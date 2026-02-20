-- =============================================================================
-- VATSIM_GIS Route Expansion Functions
-- =============================================================================
-- Database: VATSIM_GIS (PostgreSQL/PostGIS)
--
-- These functions parse and expand route strings using synced NAVDATA.
--
-- Key functions:
--   resolve_waypoint(fix_name) - Get coordinates for a fix/airport
--   expand_airway(airway, from_fix, to_fix) - Get segment waypoints
--   expand_route(route_string) - Parse and expand full route
--   expand_route_with_artccs(route_string) - Expand + get traversed ARTCCs
--
-- Table dependencies:
--   - nav_fixes (fix_name, lat, lon, fix_type)
--   - airways (airway_id, airway_name, fix_sequence)
--   - airway_segments (airway_id, sequence_num, from_fix, to_fix, from_lat, from_lon, to_lat, to_lon)
--   - airports (icao_id, arpt_id, lat, lon)
--   - area_centers (center_code, center_type, lat, lon)
--   - artcc_boundaries (artcc_code, fir_name, floor_altitude, ceiling_altitude, geom)
--   - playbook_routes (play_name, full_route, origin_airports, origin_artccs, dest_airports, dest_artccs)
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. resolve_waypoint - Resolve a fix/airport identifier to coordinates
-- -----------------------------------------------------------------------------
-- Input: Fix identifier (e.g., 'BNA', 'KDFW', 'ZBW')
-- Output: fix_id, lat, lon, source (nav_fix, airport, airport_faa, airport_k, area_center)
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION resolve_waypoint(p_fix_name VARCHAR)
RETURNS TABLE (
    fix_id VARCHAR,
    lat DECIMAL(10,7),
    lon DECIMAL(11,7),
    source VARCHAR(20)
) AS $$
BEGIN
    -- Try nav_fixes first (most common - waypoints, VORs, NDBs)
    RETURN QUERY
    SELECT
        nf.fix_name::VARCHAR AS fix_id,
        nf.lat,
        nf.lon,
        'nav_fix'::VARCHAR AS source
    FROM nav_fixes nf
    WHERE nf.fix_name = p_fix_name
    LIMIT 1;

    IF FOUND THEN RETURN; END IF;

    -- Try airports by ICAO code (e.g., KJFK, KLAX)
    RETURN QUERY
    SELECT
        a.icao_id::VARCHAR AS fix_id,
        a.lat,
        a.lon,
        'airport'::VARCHAR AS source
    FROM airports a
    WHERE a.icao_id = p_fix_name
    LIMIT 1;

    IF FOUND THEN RETURN; END IF;

    -- Try airports by FAA code (e.g., DFW, JFK - 3-letter codes)
    RETURN QUERY
    SELECT
        a.icao_id::VARCHAR AS fix_id,
        a.lat,
        a.lon,
        'airport_faa'::VARCHAR AS source
    FROM airports a
    WHERE a.arpt_id = p_fix_name
    LIMIT 1;

    IF FOUND THEN RETURN; END IF;

    -- Try with K prefix for US airports (3-letter to ICAO conversion)
    IF LENGTH(p_fix_name) = 3 AND p_fix_name ~ '^[A-Z]{3}$' THEN
        RETURN QUERY
        SELECT
            a.icao_id::VARCHAR AS fix_id,
            a.lat,
            a.lon,
            'airport_k'::VARCHAR AS source
        FROM airports a
        WHERE a.icao_id = 'K' || p_fix_name
        LIMIT 1;

        IF FOUND THEN RETURN; END IF;
    END IF;

    -- Try area_centers (ARTCC/TRACON pseudo-fixes like ZNY, ZBW)
    RETURN QUERY
    SELECT
        ac.center_code::VARCHAR AS fix_id,
        ac.lat,
        ac.lon,
        'area_center'::VARCHAR AS source
    FROM area_centers ac
    WHERE ac.center_code = p_fix_name
    LIMIT 1;

END;
$$ LANGUAGE plpgsql STABLE;

COMMENT ON FUNCTION resolve_waypoint(VARCHAR) IS 'Resolves a fix/airport identifier to lat/lon coordinates';

-- -----------------------------------------------------------------------------
-- 2. expand_airway - Expand airway between two fixes
-- -----------------------------------------------------------------------------
-- Input: Airway name (e.g., 'J86'), from_fix (e.g., 'LOWGN'), to_fix (e.g., 'BNA')
-- Output: Ordered list of waypoints along the airway
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION expand_airway(
    p_airway VARCHAR,
    p_from_fix VARCHAR,
    p_to_fix VARCHAR
)
RETURNS TABLE (
    seq INT,
    fix_id VARCHAR,
    lat DECIMAL(10,7),
    lon DECIMAL(11,7)
) AS $$
DECLARE
    v_from_seq INT;
    v_to_seq INT;
    v_airway_id INT;
BEGIN
    -- Get airway ID
    SELECT a.airway_id INTO v_airway_id
    FROM airways a
    WHERE a.airway_name = p_airway
    LIMIT 1;

    IF v_airway_id IS NULL THEN
        -- Airway not found, return empty
        RETURN;
    END IF;

    -- Find sequence numbers for from/to fixes
    -- Check both from_fix and to_fix columns since direction can vary
    SELECT MIN(s.sequence_num) INTO v_from_seq
    FROM airway_segments s
    WHERE s.airway_id = v_airway_id
      AND (s.from_fix = p_from_fix OR s.to_fix = p_from_fix);

    SELECT MAX(s.sequence_num) INTO v_to_seq
    FROM airway_segments s
    WHERE s.airway_id = v_airway_id
      AND (s.from_fix = p_to_fix OR s.to_fix = p_to_fix);

    IF v_from_seq IS NULL OR v_to_seq IS NULL THEN
        -- Fixes not found on airway, return empty
        RETURN;
    END IF;

    -- Handle forward or reverse traversal
    IF v_from_seq <= v_to_seq THEN
        -- Forward direction
        RETURN QUERY
        WITH ordered_fixes AS (
            -- Get starting fix
            SELECT 1 AS seq, s.from_fix AS fix_id, s.from_lat AS lat, s.from_lon AS lon
            FROM airway_segments s
            WHERE s.airway_id = v_airway_id AND s.sequence_num = v_from_seq
            UNION ALL
            -- Get all to_fixes in order
            SELECT (s.sequence_num - v_from_seq + 2)::INT AS seq, s.to_fix, s.to_lat, s.to_lon
            FROM airway_segments s
            WHERE s.airway_id = v_airway_id
              AND s.sequence_num >= v_from_seq
              AND s.sequence_num <= v_to_seq
            ORDER BY seq
        )
        SELECT of.seq::INT, of.fix_id::VARCHAR, of.lat, of.lon
        FROM ordered_fixes of;
    ELSE
        -- Reverse direction
        RETURN QUERY
        WITH ordered_fixes AS (
            -- Get starting fix (which is to_fix of highest seq)
            SELECT 1 AS seq, s.to_fix AS fix_id, s.to_lat AS lat, s.to_lon AS lon
            FROM airway_segments s
            WHERE s.airway_id = v_airway_id AND s.sequence_num = v_from_seq
            UNION ALL
            -- Get all from_fixes in reverse order
            SELECT (v_from_seq - s.sequence_num + 2)::INT AS seq, s.from_fix, s.from_lat, s.from_lon
            FROM airway_segments s
            WHERE s.airway_id = v_airway_id
              AND s.sequence_num <= v_from_seq
              AND s.sequence_num >= v_to_seq
            ORDER BY seq
        )
        SELECT of.seq::INT, of.fix_id::VARCHAR, of.lat, of.lon
        FROM ordered_fixes of;
    END IF;
END;
$$ LANGUAGE plpgsql STABLE;

COMMENT ON FUNCTION expand_airway(VARCHAR, VARCHAR, VARCHAR) IS 'Expands an airway between two fixes to ordered waypoints';

-- -----------------------------------------------------------------------------
-- 2b. resolve_fbd_waypoint - Resolve a Fix/Bearing/Distance token
-- -----------------------------------------------------------------------------
-- Input: FBD token like 'BDR228018' (BDR VOR, 228° bearing, 18nm distance)
--        Optional prev/next coordinates for base fix disambiguation
-- Output: fix_id (original token), lat, lon, source ('fbd')
--
-- Format: {FIX_NAME}{BBB}{DDD} — 2-5 uppercase letters + 3-digit bearing + 3-digit distance
-- Uses route context (prev + next waypoints) to disambiguate duplicate base fixes
-- globally — NOT US-centric.
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION resolve_fbd_waypoint(
    p_token VARCHAR,
    p_prev_lat DECIMAL(10,7) DEFAULT NULL,
    p_prev_lon DECIMAL(11,7) DEFAULT NULL,
    p_next_lat DECIMAL(10,7) DEFAULT NULL,
    p_next_lon DECIMAL(11,7) DEFAULT NULL
)
RETURNS TABLE (
    fix_id VARCHAR,
    lat DECIMAL(10,7),
    lon DECIMAL(11,7),
    source VARCHAR(20)
) AS $$
DECLARE
    v_base_fix VARCHAR;
    v_bearing_deg INT;
    v_distance_nm INT;
    v_alpha_len INT;
    v_base_lat DECIMAL(10,7);
    v_base_lon DECIMAL(11,7);
    v_projected GEOGRAPHY;
    v_ref_lat DECIMAL(10,7);
    v_ref_lon DECIMAL(11,7);
    v_mag_var DECIMAL(5,2);
    v_true_bearing FLOAT;
BEGIN
    -- Validate token: 2-5 uppercase letters followed by exactly 6 digits
    IF p_token !~ '^[A-Z]{2,5}\d{6}$' THEN
        RETURN;
    END IF;

    -- Extract components: base fix name (alpha prefix) + bearing (3 digits) + distance (3 digits)
    v_alpha_len := LENGTH(regexp_replace(p_token, '\d.*$', ''));
    v_base_fix := SUBSTRING(p_token FROM 1 FOR v_alpha_len);
    v_bearing_deg := CAST(SUBSTRING(p_token FROM v_alpha_len + 1 FOR 3) AS INT);
    v_distance_nm := CAST(SUBSTRING(p_token FROM v_alpha_len + 4 FOR 3) AS INT);

    -- Validate ranges
    IF v_bearing_deg > 360 OR v_distance_nm < 1 OR v_distance_nm > 999 THEN
        RETURN;
    END IF;

    -- Resolve base fix with route-context proximity disambiguation
    -- Strategy: both prev+next → midpoint, one side → that side, neither → first result
    IF p_prev_lat IS NOT NULL AND p_next_lat IS NOT NULL THEN
        v_ref_lat := (p_prev_lat + p_next_lat) / 2.0;
        v_ref_lon := (p_prev_lon + p_next_lon) / 2.0;
    ELSIF p_prev_lat IS NOT NULL THEN
        v_ref_lat := p_prev_lat;
        v_ref_lon := p_prev_lon;
    ELSIF p_next_lat IS NOT NULL THEN
        v_ref_lat := p_next_lat;
        v_ref_lon := p_next_lon;
    END IF;

    IF v_ref_lat IS NOT NULL THEN
        SELECT nf.lat, nf.lon, nf.mag_var INTO v_base_lat, v_base_lon, v_mag_var
        FROM nav_fixes nf
        WHERE nf.fix_name = v_base_fix
          AND nf.lat IS NOT NULL AND nf.lon IS NOT NULL
        ORDER BY ST_Distance(
            ST_SetSRID(ST_MakePoint(nf.lon, nf.lat), 4326)::geography,
            ST_SetSRID(ST_MakePoint(v_ref_lon, v_ref_lat), 4326)::geography
        )
        LIMIT 1;
    ELSE
        SELECT nf.lat, nf.lon, nf.mag_var INTO v_base_lat, v_base_lon, v_mag_var
        FROM nav_fixes nf
        WHERE nf.fix_name = v_base_fix
          AND nf.lat IS NOT NULL AND nf.lon IS NOT NULL
        LIMIT 1;
    END IF;

    IF v_base_lat IS NULL THEN
        RETURN;
    END IF;

    -- If mag_var wasn't set from the resolved fix, look for it from a co-located VOR record
    IF v_mag_var IS NULL OR v_mag_var = 0 THEN
        SELECT nf.mag_var INTO v_mag_var
        FROM nav_fixes nf
        WHERE nf.fix_name = v_base_fix
          AND nf.mag_var IS NOT NULL AND nf.mag_var != 0
          AND ABS(nf.lat - v_base_lat) < 0.1 AND ABS(nf.lon - v_base_lon) < 0.1
        LIMIT 1;
    END IF;

    -- Convert magnetic bearing to true bearing
    -- mag_var: positive = East, negative = West; True = Magnetic + mag_var
    v_true_bearing := v_bearing_deg::FLOAT + COALESCE(v_mag_var, 0)::FLOAT;
    -- Normalize to 0-360
    IF v_true_bearing < 0 THEN v_true_bearing := v_true_bearing + 360; END IF;
    IF v_true_bearing >= 360 THEN v_true_bearing := v_true_bearing - 360; END IF;

    -- Project from base fix along true bearing for distance
    -- ST_Project(geography, distance_meters, azimuth_radians)
    v_projected := ST_Project(
        ST_SetSRID(ST_MakePoint(v_base_lon, v_base_lat), 4326)::geography,
        v_distance_nm * 1852.0,
        RADIANS(v_true_bearing)
    );

    RETURN QUERY SELECT
        p_token::VARCHAR AS fix_id,
        ROUND(ST_Y(v_projected::geometry)::DECIMAL(10,7), 7) AS lat,
        ROUND(ST_X(v_projected::geometry)::DECIMAL(11,7), 7) AS lon,
        'fbd'::VARCHAR(20) AS source;
END;
$$ LANGUAGE plpgsql STABLE;

COMMENT ON FUNCTION resolve_fbd_waypoint(VARCHAR, DECIMAL, DECIMAL, DECIMAL, DECIMAL) IS 'Resolves Fix/Bearing/Distance token (e.g. BDR228018) to projected coordinates';

-- -----------------------------------------------------------------------------
-- 3. expand_route - Parse and expand a full route string
-- -----------------------------------------------------------------------------
-- Input: Route string like "KDFW LOWGN J86 BNA J42 BROKK KMCO"
-- Output: All waypoints with coordinates in flight order
--
-- Handles:
--   - Direct waypoints (fixes, airports)
--   - Jet routes (J routes)
--   - Q routes, V routes, T routes
--   - SID/STAR notation (FIX.PROC5 - strips procedure suffix)
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION expand_route(p_route_string TEXT)
RETURNS TABLE (
    waypoint_seq INT,
    waypoint_id VARCHAR,
    lat DECIMAL(10,7),
    lon DECIMAL(11,7),
    waypoint_type VARCHAR(20)
) AS $$
DECLARE
    v_parts TEXT[];
    v_idx INT;
    v_part TEXT;
    v_prev_fix TEXT := NULL;
    v_seq INT := 0;
    v_is_airway BOOLEAN;
    v_next_fix TEXT;
    v_wp RECORD;
    v_airway_wp RECORD;
    v_fbd_wp RECORD;
    v_fbd_prev_lat DECIMAL(10,7);
    v_fbd_prev_lon DECIMAL(11,7);
    v_fbd_next_lat DECIMAL(10,7);
    v_fbd_next_lon DECIMAL(11,7);
    v_fbd_next_wp RECORD;
BEGIN
    -- Split route string into parts
    v_parts := regexp_split_to_array(TRIM(p_route_string), '\s+');

    v_idx := 1;
    WHILE v_idx <= array_length(v_parts, 1) LOOP
        v_part := v_parts[v_idx];

        -- Skip empty parts
        IF v_part IS NULL OR v_part = '' THEN
            v_idx := v_idx + 1;
            CONTINUE;
        END IF;

        -- Check for FBD (Fix/Bearing/Distance) tokens like BDR228018
        -- Must come BEFORE airway check to avoid misclassification
        IF v_part ~ '^[A-Z]{2,5}\d{6}$' THEN
            -- Get previous fix coords for disambiguation
            v_fbd_prev_lat := NULL;
            v_fbd_prev_lon := NULL;
            v_fbd_next_lat := NULL;
            v_fbd_next_lon := NULL;

            IF v_seq > 0 THEN
                SELECT rw.lat, rw.lon INTO v_fbd_prev_lat, v_fbd_prev_lon
                FROM resolve_waypoint(v_prev_fix) rw LIMIT 1;
            END IF;

            -- Look ahead to next token for disambiguation context
            IF v_idx < array_length(v_parts, 1) THEN
                v_next_fix := v_parts[v_idx + 1];
                IF v_next_fix IS NOT NULL AND v_next_fix != '' AND v_next_fix !~ '^[A-Z]{2,5}\d{6}$' THEN
                    -- Strip procedure notation
                    IF v_next_fix LIKE '%.%' THEN
                        v_next_fix := split_part(v_next_fix, '.', 1);
                    END IF;
                    SELECT rw.lat, rw.lon INTO v_fbd_next_lat, v_fbd_next_lon
                    FROM resolve_waypoint(v_next_fix) rw LIMIT 1;
                END IF;
            END IF;

            SELECT rfbd.fix_id, rfbd.lat, rfbd.lon, rfbd.source
            INTO v_fbd_wp
            FROM resolve_fbd_waypoint(v_part, v_fbd_prev_lat, v_fbd_prev_lon, v_fbd_next_lat, v_fbd_next_lon) rfbd
            LIMIT 1;

            IF v_fbd_wp.fix_id IS NOT NULL THEN
                v_seq := v_seq + 1;
                waypoint_seq := v_seq;
                waypoint_id := v_fbd_wp.fix_id;
                lat := v_fbd_wp.lat;
                lon := v_fbd_wp.lon;
                waypoint_type := 'fbd';
                RETURN NEXT;
                v_prev_fix := v_part;
            END IF;

            v_idx := v_idx + 1;
            CONTINUE;
        END IF;

        -- Check if this is an airway (J/Q/V/T followed by number, or named airways like A1, B5)
        v_is_airway := v_part ~ '^[JQVT]\d+$' OR v_part ~ '^[A-Z]{1,2}\d{1,3}$';

        IF v_is_airway AND v_prev_fix IS NOT NULL AND v_idx < array_length(v_parts, 1) THEN
            -- This is an airway - expand it
            v_next_fix := v_parts[v_idx + 1];

            -- Strip any procedure notation (e.g., "FIX.STAR5" -> "FIX")
            IF v_next_fix LIKE '%.%' THEN
                v_next_fix := split_part(v_next_fix, '.', 1);
            END IF;

            -- Expand airway (skip first fix as it was already added)
            FOR v_airway_wp IN
                SELECT ea.seq, ea.fix_id, ea.lat, ea.lon
                FROM expand_airway(v_part, v_prev_fix, v_next_fix) ea
                WHERE ea.seq > 1
            LOOP
                v_seq := v_seq + 1;
                waypoint_seq := v_seq;
                waypoint_id := v_airway_wp.fix_id;
                lat := v_airway_wp.lat;
                lon := v_airway_wp.lon;
                waypoint_type := 'airway_' || v_part;
                RETURN NEXT;
                v_prev_fix := v_airway_wp.fix_id;
            END LOOP;

            -- Skip the airway and next fix (already processed via expansion)
            v_idx := v_idx + 2;
        ELSE
            -- Direct waypoint/fix
            -- Strip procedure notation (e.g., "KDFW.LOWGN5" -> "KDFW")
            IF v_part LIKE '%.%' THEN
                v_part := split_part(v_part, '.', 1);
            END IF;

            -- Resolve waypoint
            SELECT rw.fix_id, rw.lat, rw.lon, rw.source
            INTO v_wp
            FROM resolve_waypoint(v_part) rw
            LIMIT 1;

            IF v_wp.fix_id IS NOT NULL AND v_wp.lat IS NOT NULL THEN
                v_seq := v_seq + 1;
                waypoint_seq := v_seq;
                waypoint_id := v_wp.fix_id;
                lat := v_wp.lat;
                lon := v_wp.lon;
                waypoint_type := v_wp.source;
                RETURN NEXT;
                v_prev_fix := v_part;
            END IF;

            v_idx := v_idx + 1;
        END IF;
    END LOOP;
END;
$$ LANGUAGE plpgsql STABLE;

COMMENT ON FUNCTION expand_route(TEXT) IS 'Parses and expands a route string to ordered waypoints with coordinates';

-- -----------------------------------------------------------------------------
-- 4. expand_route_with_artccs - Expand route and get traversed ARTCCs
-- -----------------------------------------------------------------------------
-- Input: Route string
-- Output: waypoints (JSONB), artccs_traversed (TEXT[]), route_geometry (GEOMETRY)
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION expand_route_with_artccs(p_route_string TEXT)
RETURNS TABLE (
    waypoints JSONB,
    artccs_traversed TEXT[],
    route_geometry GEOMETRY
) AS $$
DECLARE
    v_waypoints JSONB;
    v_route_geom GEOMETRY;
    v_artccs TEXT[];
BEGIN
    -- Expand route to waypoints as JSONB
    SELECT jsonb_agg(
        jsonb_build_object(
            'seq', er.waypoint_seq,
            'id', er.waypoint_id,
            'lat', er.lat,
            'lon', er.lon,
            'type', er.waypoint_type
        ) ORDER BY er.waypoint_seq
    )
    INTO v_waypoints
    FROM expand_route(p_route_string) er;

    -- Build route geometry LineString from waypoints
    IF v_waypoints IS NOT NULL AND jsonb_array_length(v_waypoints) >= 2 THEN
        SELECT ST_MakeLine(
            ARRAY(
                SELECT ST_SetSRID(ST_MakePoint(
                    (wp->>'lon')::FLOAT,
                    (wp->>'lat')::FLOAT
                ), 4326)
                FROM jsonb_array_elements(v_waypoints) AS wp
                ORDER BY (wp->>'seq')::INT
            )
        ) INTO v_route_geom;
    END IF;

    -- Find ARTCCs traversed (in traversal order by route centroid position)
    IF v_route_geom IS NOT NULL THEN
        SELECT ARRAY(
            SELECT artcc_code FROM (
                SELECT DISTINCT ON (ab.artcc_code)
                    ab.artcc_code,
                    ST_LineLocatePoint(v_route_geom, ST_Centroid(ST_Intersection(ab.geom, v_route_geom))) AS traversal_order
                FROM artcc_boundaries ab
                WHERE ST_Intersects(ab.geom, v_route_geom)
                ORDER BY ab.artcc_code, traversal_order
            ) sub
            ORDER BY traversal_order
        ) INTO v_artccs;
    END IF;

    -- Return results
    waypoints := COALESCE(v_waypoints, '[]'::JSONB);
    artccs_traversed := COALESCE(v_artccs, ARRAY[]::TEXT[]);
    route_geometry := v_route_geom;
    RETURN NEXT;
END;
$$ LANGUAGE plpgsql STABLE;

COMMENT ON FUNCTION expand_route_with_artccs(TEXT) IS 'Expands route and returns traversed ARTCCs in order';

-- -----------------------------------------------------------------------------
-- 5. get_route_artccs - Lightweight function for just ARTCCs
-- -----------------------------------------------------------------------------
-- Input: Route string
-- Output: Array of ARTCC codes in traversal order (e.g., ARRAY['ZFW', 'ZHU', 'ZJX'])
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION get_route_artccs(p_route_string TEXT)
RETURNS TEXT[] AS $$
DECLARE
    v_artccs TEXT[];
BEGIN
    SELECT era.artccs_traversed INTO v_artccs
    FROM expand_route_with_artccs(p_route_string) era;

    RETURN COALESCE(v_artccs, ARRAY[]::TEXT[]);
END;
$$ LANGUAGE plpgsql STABLE;

COMMENT ON FUNCTION get_route_artccs(TEXT) IS 'Returns ARTCCs traversed by route in order';

-- -----------------------------------------------------------------------------
-- 6. expand_route_with_boundaries - Full boundary analysis
-- -----------------------------------------------------------------------------
-- Input: Route string, cruise altitude (default 35000)
-- Output: waypoints, artccs, boundaries with altitude filtering, geometry, distance
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION expand_route_with_boundaries(
    p_route_string TEXT,
    p_altitude INT DEFAULT 35000
)
RETURNS TABLE (
    waypoints JSONB,
    artccs_traversed TEXT[],
    boundaries_traversed JSONB,
    route_geometry GEOMETRY,
    total_distance_nm DECIMAL(10,2)
) AS $$
DECLARE
    v_waypoints JSONB;
    v_route_geom GEOMETRY;
    v_artccs TEXT[];
    v_boundaries JSONB;
    v_distance DECIMAL(10,2);
BEGIN
    -- Expand route to waypoints
    SELECT jsonb_agg(
        jsonb_build_object(
            'seq', er.waypoint_seq,
            'id', er.waypoint_id,
            'lat', er.lat,
            'lon', er.lon,
            'type', er.waypoint_type
        ) ORDER BY er.waypoint_seq
    )
    INTO v_waypoints
    FROM expand_route(p_route_string) er;

    -- Build route geometry
    IF v_waypoints IS NOT NULL AND jsonb_array_length(v_waypoints) >= 2 THEN
        SELECT ST_MakeLine(
            ARRAY(
                SELECT ST_SetSRID(ST_MakePoint(
                    (wp->>'lon')::FLOAT,
                    (wp->>'lat')::FLOAT
                ), 4326)
                FROM jsonb_array_elements(v_waypoints) AS wp
                ORDER BY (wp->>'seq')::INT
            )
        ) INTO v_route_geom;
    END IF;

    -- Calculate distance in nautical miles
    IF v_route_geom IS NOT NULL THEN
        SELECT ST_Length(v_route_geom::geography) / 1852.0 INTO v_distance;
    END IF;

    -- Find ARTCCs traversed (in traversal order)
    IF v_route_geom IS NOT NULL THEN
        SELECT ARRAY(
            SELECT artcc_code FROM (
                SELECT DISTINCT ON (ab.artcc_code)
                    ab.artcc_code,
                    ST_LineLocatePoint(v_route_geom, ST_Centroid(ST_Intersection(ab.geom, v_route_geom))) AS traversal_order
                FROM artcc_boundaries ab
                WHERE ST_Intersects(ab.geom, v_route_geom)
                ORDER BY ab.artcc_code, traversal_order
            ) sub
            ORDER BY traversal_order
        ) INTO v_artccs;

        -- Find all boundaries with altitude filtering
        SELECT jsonb_agg(boundary_data ORDER BY (boundary_data->>'traversal_order')::FLOAT)
        INTO v_boundaries
        FROM (
            SELECT jsonb_build_object(
                'code', ab.artcc_code,
                'name', ab.fir_name,
                'floor', ab.floor_altitude,
                'ceiling', ab.ceiling_altitude,
                'is_oceanic', ab.is_oceanic,
                'traversal_order', ST_LineLocatePoint(v_route_geom, ST_Centroid(ST_Intersection(ab.geom, v_route_geom)))
            ) AS boundary_data
            FROM artcc_boundaries ab
            WHERE ST_Intersects(ab.geom, v_route_geom)
              AND (ab.floor_altitude IS NULL OR ab.floor_altitude <= p_altitude)
              AND (ab.ceiling_altitude IS NULL OR ab.ceiling_altitude >= p_altitude)
        ) sub;
    END IF;

    -- Return results
    waypoints := COALESCE(v_waypoints, '[]'::JSONB);
    artccs_traversed := COALESCE(v_artccs, ARRAY[]::TEXT[]);
    boundaries_traversed := COALESCE(v_boundaries, '[]'::JSONB);
    route_geometry := v_route_geom;
    total_distance_nm := COALESCE(v_distance, 0);
    RETURN NEXT;
END;
$$ LANGUAGE plpgsql STABLE;

COMMENT ON FUNCTION expand_route_with_boundaries(TEXT, INT) IS 'Full route analysis with altitude-filtered boundary traversal';

-- -----------------------------------------------------------------------------
-- 7. expand_playbook_route - Parse and expand a playbook route code
-- -----------------------------------------------------------------------------
-- Input: Playbook code like "PB.ROD.KSAN.KJFK" or "PB.ONL.ZNY.KSFO"
-- Output: waypoints, artccs, route_string, geometry
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION expand_playbook_route(p_pb_code VARCHAR)
RETURNS TABLE (
    waypoints JSONB,
    artccs_traversed TEXT[],
    route_string TEXT,
    route_geometry GEOMETRY
) AS $$
DECLARE
    v_parts TEXT[];
    v_play VARCHAR;
    v_origin VARCHAR;
    v_dest VARCHAR;
    v_route TEXT;
    v_result RECORD;
BEGIN
    -- Parse PB.PLAY.ORIGIN.DEST format
    v_parts := string_to_array(p_pb_code, '.');

    IF array_length(v_parts, 1) < 4 OR UPPER(v_parts[1]) != 'PB' THEN
        RAISE EXCEPTION 'Invalid playbook code format. Expected: PB.PLAY.ORIGIN.DEST, got: %', p_pb_code;
    END IF;

    v_play := v_parts[2];
    v_origin := v_parts[3];
    v_dest := v_parts[4];

    -- Look up playbook route (try multiple match patterns)
    -- Pattern 1: Exact play name, origin in origin_airports or origin_artccs, dest in dest_airports or dest_artccs
    SELECT pr.full_route INTO v_route
    FROM playbook_routes pr
    WHERE pr.play_name = v_play
      AND (pr.origin_airports LIKE '%' || v_origin || '%'
           OR pr.origin_artccs LIKE '%' || v_origin || '%')
      AND (pr.dest_airports LIKE '%' || v_dest || '%'
           OR pr.dest_artccs LIKE '%' || v_dest || '%')
    LIMIT 1;

    -- Pattern 2: Case-insensitive play name match
    IF v_route IS NULL THEN
        SELECT pr.full_route INTO v_route
        FROM playbook_routes pr
        WHERE UPPER(pr.play_name) = UPPER(v_play)
          AND (pr.origin_airports LIKE '%' || v_origin || '%'
               OR pr.origin_artccs LIKE '%' || v_origin || '%')
          AND (pr.dest_airports LIKE '%' || v_dest || '%'
               OR pr.dest_artccs LIKE '%' || v_dest || '%')
        LIMIT 1;
    END IF;

    -- Pattern 3: Just play name match (broadest)
    IF v_route IS NULL THEN
        SELECT pr.full_route INTO v_route
        FROM playbook_routes pr
        WHERE UPPER(pr.play_name) = UPPER(v_play)
        LIMIT 1;
    END IF;

    IF v_route IS NULL THEN
        RAISE EXCEPTION 'Playbook route not found: %', p_pb_code;
    END IF;

    -- Expand the route
    SELECT era.waypoints, era.artccs_traversed, era.route_geometry
    INTO v_result
    FROM expand_route_with_artccs(v_route) era;

    waypoints := v_result.waypoints;
    artccs_traversed := v_result.artccs_traversed;
    route_string := v_route;
    route_geometry := v_result.route_geometry;
    RETURN NEXT;
END;
$$ LANGUAGE plpgsql STABLE;

COMMENT ON FUNCTION expand_playbook_route(VARCHAR) IS 'Expands a playbook route code (PB.PLAY.ORIGIN.DEST) to waypoints and ARTCCs';

-- -----------------------------------------------------------------------------
-- 8. analyze_route_from_waypoints - Analyze pre-expanded waypoints
-- -----------------------------------------------------------------------------
-- Input: JSONB array of waypoints [{lon, lat}, ...]
-- Output: ARTCCs traversed, route geometry, distance
-- Useful when waypoints are already expanded (e.g., from client)
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION analyze_route_from_waypoints(p_waypoints JSONB)
RETURNS TABLE (
    artccs_traversed TEXT[],
    route_geometry GEOMETRY,
    total_distance_nm DECIMAL(10,2)
) AS $$
DECLARE
    v_route_geom GEOMETRY;
    v_artccs TEXT[];
    v_distance DECIMAL(10,2);
BEGIN
    -- Build route geometry from waypoints
    IF p_waypoints IS NOT NULL AND jsonb_array_length(p_waypoints) >= 2 THEN
        SELECT ST_MakeLine(
            ARRAY(
                SELECT ST_SetSRID(ST_MakePoint(
                    (wp->>'lon')::FLOAT,
                    (wp->>'lat')::FLOAT
                ), 4326)
                FROM jsonb_array_elements(p_waypoints) AS wp
            )
        ) INTO v_route_geom;
    END IF;

    IF v_route_geom IS NOT NULL THEN
        -- Calculate distance
        SELECT ST_Length(v_route_geom::geography) / 1852.0 INTO v_distance;

        -- Find ARTCCs traversed
        SELECT ARRAY(
            SELECT artcc_code FROM (
                SELECT DISTINCT ON (ab.artcc_code)
                    ab.artcc_code,
                    ST_LineLocatePoint(v_route_geom, ST_Centroid(ST_Intersection(ab.geom, v_route_geom))) AS traversal_order
                FROM artcc_boundaries ab
                WHERE ST_Intersects(ab.geom, v_route_geom)
                ORDER BY ab.artcc_code, traversal_order
            ) sub
            ORDER BY traversal_order
        ) INTO v_artccs;
    END IF;

    artccs_traversed := COALESCE(v_artccs, ARRAY[]::TEXT[]);
    route_geometry := v_route_geom;
    total_distance_nm := COALESCE(v_distance, 0);
    RETURN NEXT;
END;
$$ LANGUAGE plpgsql STABLE;

COMMENT ON FUNCTION analyze_route_from_waypoints(JSONB) IS 'Analyzes pre-expanded waypoints for ARTCC crossings';

-- =============================================================================
-- ADDITIONAL INDEXES for performance (if not already exist)
-- =============================================================================
CREATE INDEX IF NOT EXISTS idx_nav_fixes_fix_name ON nav_fixes(fix_name);
CREATE INDEX IF NOT EXISTS idx_airways_name ON airways(airway_name);
CREATE INDEX IF NOT EXISTS idx_airway_segments_lookup ON airway_segments(airway_id, from_fix, to_fix);
CREATE INDEX IF NOT EXISTS idx_airway_segments_seq ON airway_segments(airway_id, sequence_num);
CREATE INDEX IF NOT EXISTS idx_airports_icao_lookup ON airports(icao_id);
CREATE INDEX IF NOT EXISTS idx_airports_faa_lookup ON airports(arpt_id);
CREATE INDEX IF NOT EXISTS idx_area_centers_code ON area_centers(center_code);
CREATE INDEX IF NOT EXISTS idx_playbook_play_name ON playbook_routes(play_name);

-- =============================================================================
-- GRANT permissions (uncomment as needed for your environment)
-- =============================================================================
-- GRANT EXECUTE ON FUNCTION resolve_waypoint(VARCHAR) TO gis_readonly;
-- GRANT EXECUTE ON FUNCTION resolve_fbd_waypoint(VARCHAR, DECIMAL, DECIMAL, DECIMAL, DECIMAL) TO gis_readonly;
-- GRANT EXECUTE ON FUNCTION expand_airway(VARCHAR, VARCHAR, VARCHAR) TO gis_readonly;
-- GRANT EXECUTE ON FUNCTION expand_route(TEXT) TO gis_readonly;
-- GRANT EXECUTE ON FUNCTION expand_route_with_artccs(TEXT) TO gis_readonly;
-- GRANT EXECUTE ON FUNCTION get_route_artccs(TEXT) TO gis_readonly;
-- GRANT EXECUTE ON FUNCTION expand_route_with_boundaries(TEXT, INT) TO gis_readonly;
-- GRANT EXECUTE ON FUNCTION expand_playbook_route(VARCHAR) TO gis_readonly;
-- GRANT EXECUTE ON FUNCTION analyze_route_from_waypoints(JSONB) TO gis_readonly;
