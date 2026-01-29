-- =============================================================================
-- VATSIM_GIS Batch Route Processing Functions
-- Server: PostgreSQL 15+ with PostGIS 3.4+
-- Database: VATSIM_GIS
-- Version: 1.0
-- Date: 2026-01-29
-- =============================================================================
--
-- PURPOSE:
--   Batch processing functions for multiple routes at once.
--   Returns GeoJSON geometries for mapping/visualization.
--
-- FUNCTIONS:
--   expand_routes_batch(routes TEXT[]) - Expand multiple routes, return geometries
--   expand_routes_with_geojson(routes TEXT[]) - Expand with GeoJSON output
--
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. expand_routes_batch - Expand multiple routes at once
-- -----------------------------------------------------------------------------
-- Input: Array of route strings ['KDFW BNA KMCO', 'KJFK KMIA', ...]
-- Output: Table with route_index, route_input, waypoints, artccs, geometry, distance
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION expand_routes_batch(p_routes TEXT[])
RETURNS TABLE (
    route_index INT,
    route_input TEXT,
    waypoints JSONB,
    artccs_traversed TEXT[],
    route_geometry GEOMETRY,
    distance_nm DECIMAL(10,2),
    error_message TEXT
) AS $$
DECLARE
    v_route TEXT;
    v_idx INT := 0;
    v_result RECORD;
BEGIN
    FOREACH v_route IN ARRAY p_routes
    LOOP
        v_idx := v_idx + 1;

        BEGIN
            -- Call expand_route_with_artccs for each route
            SELECT
                era.waypoints,
                era.artccs_traversed,
                era.route_geometry,
                CASE
                    WHEN era.route_geometry IS NOT NULL
                    THEN ST_Length(era.route_geometry::geography) / 1852.0
                    ELSE 0
                END as dist
            INTO v_result
            FROM expand_route_with_artccs(v_route) era;

            route_index := v_idx;
            route_input := v_route;
            waypoints := v_result.waypoints;
            artccs_traversed := v_result.artccs_traversed;
            route_geometry := v_result.route_geometry;
            distance_nm := v_result.dist;
            error_message := NULL;
            RETURN NEXT;

        EXCEPTION WHEN OTHERS THEN
            route_index := v_idx;
            route_input := v_route;
            waypoints := '[]'::JSONB;
            artccs_traversed := ARRAY[]::TEXT[];
            route_geometry := NULL;
            distance_nm := 0;
            error_message := SQLERRM;
            RETURN NEXT;
        END;
    END LOOP;
END;
$$ LANGUAGE plpgsql STABLE;

COMMENT ON FUNCTION expand_routes_batch(TEXT[]) IS 'Batch expand multiple route strings, returning geometries and ARTCCs';

-- -----------------------------------------------------------------------------
-- 2. expand_routes_with_geojson - Batch expand with GeoJSON output
-- -----------------------------------------------------------------------------
-- Input: Array of route strings
-- Output: Table with GeoJSON LineString for each route (ready for mapping)
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION expand_routes_with_geojson(p_routes TEXT[])
RETURNS TABLE (
    route_index INT,
    route_input TEXT,
    waypoint_count INT,
    artccs TEXT[],
    artccs_display TEXT,
    distance_nm DECIMAL(10,2),
    geojson TEXT,
    error_message TEXT
) AS $$
BEGIN
    RETURN QUERY
    SELECT
        erb.route_index,
        erb.route_input,
        jsonb_array_length(erb.waypoints)::INT as waypoint_count,
        erb.artccs_traversed as artccs,
        array_to_string(
            ARRAY(SELECT CASE WHEN a LIKE 'K%' AND LENGTH(a) = 4 THEN SUBSTRING(a, 2) ELSE a END FROM unnest(erb.artccs_traversed) a),
            ' -> '
        ) as artccs_display,
        erb.distance_nm,
        ST_AsGeoJSON(erb.route_geometry) as geojson,
        erb.error_message
    FROM expand_routes_batch(p_routes) erb;
END;
$$ LANGUAGE plpgsql STABLE;

COMMENT ON FUNCTION expand_routes_with_geojson(TEXT[]) IS 'Batch expand routes with GeoJSON output for mapping';

-- -----------------------------------------------------------------------------
-- 3. expand_routes_full - Full analysis with sectors and TRACONs
-- -----------------------------------------------------------------------------
-- Input: Array of route strings + cruise altitude
-- Output: Complete boundary analysis for each route
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION expand_routes_full(
    p_routes TEXT[],
    p_altitude INT DEFAULT 35000
)
RETURNS TABLE (
    route_index INT,
    route_input TEXT,
    waypoints JSONB,
    artccs TEXT[],
    sectors_low TEXT[],
    sectors_high TEXT[],
    sectors_superhi TEXT[],
    tracons TEXT[],
    distance_nm DECIMAL(10,2),
    geojson TEXT,
    error_message TEXT
) AS $$
DECLARE
    v_route TEXT;
    v_idx INT := 0;
    v_expand RECORD;
    v_geom GEOMETRY;
    v_lo TEXT[];
    v_hi TEXT[];
    v_shi TEXT[];
    v_trc TEXT[];
BEGIN
    FOREACH v_route IN ARRAY p_routes
    LOOP
        v_idx := v_idx + 1;

        BEGIN
            -- Expand route
            SELECT
                era.waypoints,
                era.artccs_traversed,
                era.route_geometry
            INTO v_expand
            FROM expand_route_with_artccs(v_route) era;

            v_geom := v_expand.route_geometry;

            -- Get sectors if geometry exists
            IF v_geom IS NOT NULL THEN
                -- LOW sectors
                SELECT ARRAY(
                    SELECT sb.sector_code
                    FROM sector_boundaries sb
                    WHERE ST_Intersects(v_geom, sb.geom)
                      AND sb.sector_type = 'LOW'
                      AND (sb.floor_altitude IS NULL OR sb.floor_altitude <= p_altitude)
                      AND (sb.ceiling_altitude IS NULL OR sb.ceiling_altitude >= p_altitude)
                    ORDER BY ST_LineLocatePoint(v_geom, ST_Centroid(ST_Intersection(v_geom, sb.geom)))
                ) INTO v_lo;

                -- HIGH sectors
                SELECT ARRAY(
                    SELECT sb.sector_code
                    FROM sector_boundaries sb
                    WHERE ST_Intersects(v_geom, sb.geom)
                      AND sb.sector_type = 'HIGH'
                      AND (sb.floor_altitude IS NULL OR sb.floor_altitude <= p_altitude)
                      AND (sb.ceiling_altitude IS NULL OR sb.ceiling_altitude >= p_altitude)
                    ORDER BY ST_LineLocatePoint(v_geom, ST_Centroid(ST_Intersection(v_geom, sb.geom)))
                ) INTO v_hi;

                -- SUPERHIGH sectors
                SELECT ARRAY(
                    SELECT sb.sector_code
                    FROM sector_boundaries sb
                    WHERE ST_Intersects(v_geom, sb.geom)
                      AND sb.sector_type = 'SUPERHIGH'
                      AND (sb.floor_altitude IS NULL OR sb.floor_altitude <= p_altitude)
                      AND (sb.ceiling_altitude IS NULL OR sb.ceiling_altitude >= p_altitude)
                    ORDER BY ST_LineLocatePoint(v_geom, ST_Centroid(ST_Intersection(v_geom, sb.geom)))
                ) INTO v_shi;

                -- TRACONs
                SELECT ARRAY(
                    SELECT tb.tracon_code
                    FROM tracon_boundaries tb
                    WHERE ST_Intersects(v_geom, tb.geom)
                    ORDER BY ST_LineLocatePoint(v_geom, ST_Centroid(ST_Intersection(v_geom, tb.geom)))
                ) INTO v_trc;
            ELSE
                v_lo := ARRAY[]::TEXT[];
                v_hi := ARRAY[]::TEXT[];
                v_shi := ARRAY[]::TEXT[];
                v_trc := ARRAY[]::TEXT[];
            END IF;

            -- Return row
            route_index := v_idx;
            route_input := v_route;
            waypoints := v_expand.waypoints;
            artccs := v_expand.artccs_traversed;
            sectors_low := v_lo;
            sectors_high := v_hi;
            sectors_superhi := v_shi;
            tracons := v_trc;
            distance_nm := CASE WHEN v_geom IS NOT NULL THEN ST_Length(v_geom::geography) / 1852.0 ELSE 0 END;
            geojson := ST_AsGeoJSON(v_geom);
            error_message := NULL;
            RETURN NEXT;

        EXCEPTION WHEN OTHERS THEN
            route_index := v_idx;
            route_input := v_route;
            waypoints := '[]'::JSONB;
            artccs := ARRAY[]::TEXT[];
            sectors_low := ARRAY[]::TEXT[];
            sectors_high := ARRAY[]::TEXT[];
            sectors_superhi := ARRAY[]::TEXT[];
            tracons := ARRAY[]::TEXT[];
            distance_nm := 0;
            geojson := NULL;
            error_message := SQLERRM;
            RETURN NEXT;
        END;
    END LOOP;
END;
$$ LANGUAGE plpgsql STABLE;

COMMENT ON FUNCTION expand_routes_full(TEXT[], INT) IS 'Full batch route analysis with sectors and TRACONs';

-- -----------------------------------------------------------------------------
-- 4. route_to_geojson_feature - Convert single route to GeoJSON Feature
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION route_to_geojson_feature(
    p_route TEXT,
    p_properties JSONB DEFAULT '{}'::JSONB
)
RETURNS JSONB AS $$
DECLARE
    v_result RECORD;
    v_props JSONB;
BEGIN
    SELECT
        era.waypoints,
        era.artccs_traversed,
        era.route_geometry,
        ST_Length(era.route_geometry::geography) / 1852.0 as distance
    INTO v_result
    FROM expand_route_with_artccs(p_route) era;

    -- Build properties
    v_props := p_properties || jsonb_build_object(
        'route', p_route,
        'waypoint_count', jsonb_array_length(v_result.waypoints),
        'artccs', v_result.artccs_traversed,
        'distance_nm', ROUND(v_result.distance::NUMERIC, 1)
    );

    -- Return GeoJSON Feature
    RETURN jsonb_build_object(
        'type', 'Feature',
        'geometry', ST_AsGeoJSON(v_result.route_geometry)::JSONB,
        'properties', v_props
    );
END;
$$ LANGUAGE plpgsql STABLE;

COMMENT ON FUNCTION route_to_geojson_feature(TEXT, JSONB) IS 'Convert route to GeoJSON Feature with properties';

-- -----------------------------------------------------------------------------
-- 5. routes_to_geojson_collection - Convert multiple routes to FeatureCollection
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION routes_to_geojson_collection(p_routes TEXT[])
RETURNS JSONB AS $$
DECLARE
    v_features JSONB[];
    v_route TEXT;
    v_idx INT := 0;
BEGIN
    FOREACH v_route IN ARRAY p_routes
    LOOP
        v_idx := v_idx + 1;
        v_features := array_append(
            v_features,
            route_to_geojson_feature(v_route, jsonb_build_object('index', v_idx))
        );
    END LOOP;

    RETURN jsonb_build_object(
        'type', 'FeatureCollection',
        'features', to_jsonb(v_features)
    );
END;
$$ LANGUAGE plpgsql STABLE;

COMMENT ON FUNCTION routes_to_geojson_collection(TEXT[]) IS 'Convert multiple routes to GeoJSON FeatureCollection';

-- =============================================================================
-- GRANTS (uncomment as needed)
-- =============================================================================
-- GRANT EXECUTE ON FUNCTION expand_routes_batch(TEXT[]) TO gis_readonly;
-- GRANT EXECUTE ON FUNCTION expand_routes_with_geojson(TEXT[]) TO gis_readonly;
-- GRANT EXECUTE ON FUNCTION expand_routes_full(TEXT[], INT) TO gis_readonly;
-- GRANT EXECUTE ON FUNCTION route_to_geojson_feature(TEXT, JSONB) TO gis_readonly;
-- GRANT EXECUTE ON FUNCTION routes_to_geojson_collection(TEXT[]) TO gis_readonly;
