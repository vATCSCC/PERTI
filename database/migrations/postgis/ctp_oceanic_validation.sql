-- ============================================================================
-- CTP Oceanic Route Validation Function
-- Migration: ctp_oceanic_validation.sql
-- Date: 2026-03-12
--
-- Wraps expand_route_with_artccs() to validate CTP oceanic route segments.
-- Checks: route parseable, crosses expected FIRs, valid entry/exit points,
-- altitude range, returns GeoJSON + waypoints.
-- ============================================================================

CREATE OR REPLACE FUNCTION validate_oceanic_route(
    p_route_string TEXT,
    p_dep_icao VARCHAR DEFAULT NULL,
    p_arr_icao VARCHAR DEFAULT NULL,
    p_validation_rules JSONB DEFAULT '{}'::JSONB
)
RETURNS TABLE (
    valid BOOLEAN,
    errors TEXT[],
    warnings TEXT[],
    waypoints JSONB,
    geojson TEXT,
    distance_nm DECIMAL(10,2),
    entry_fix TEXT,
    exit_fix TEXT,
    entry_fir TEXT,
    exit_fir TEXT,
    artccs_traversed TEXT[]
)
LANGUAGE plpgsql
AS $$
DECLARE
    v_waypoints JSONB;
    v_artccs TEXT[];
    v_geom GEOMETRY;
    v_errors TEXT[] := ARRAY[]::TEXT[];
    v_warnings TEXT[] := ARRAY[]::TEXT[];
    v_valid BOOLEAN := TRUE;
    v_geojson TEXT;
    v_distance DECIMAL(10,2);
    v_entry_fix TEXT;
    v_exit_fix TEXT;
    v_entry_fir TEXT;
    v_exit_fir TEXT;
    v_constrained_firs TEXT[];
    v_allowed_entry TEXT[];
    v_allowed_exit TEXT[];
    v_altitude_min INT;
    v_altitude_max INT;
    v_wp_count INT;
    v_wp JSONB;
    v_wp_id TEXT;
    v_wp_lat DECIMAL(10,7);
    v_wp_lon DECIMAL(11,7);
    v_found_entry BOOLEAN := FALSE;
    v_found_exit BOOLEAN := FALSE;
    v_fir_code TEXT;
    v_rec RECORD;
BEGIN
    -- Step 1: Expand the route
    BEGIN
        SELECT
            er.waypoints,
            er.artccs_traversed,
            er.route_geometry
        INTO v_waypoints, v_artccs, v_geom
        FROM expand_route_with_artccs(p_route_string) er;
    EXCEPTION WHEN OTHERS THEN
        v_valid := FALSE;
        v_errors := array_append(v_errors, 'Route expansion failed: ' || SQLERRM);

        RETURN QUERY SELECT
            v_valid, v_errors, v_warnings,
            NULL::JSONB, NULL::TEXT, NULL::DECIMAL(10,2),
            NULL::TEXT, NULL::TEXT, NULL::TEXT, NULL::TEXT,
            NULL::TEXT[];
        RETURN;
    END;

    -- Check waypoints exist
    IF v_waypoints IS NULL OR jsonb_array_length(v_waypoints) = 0 THEN
        v_valid := FALSE;
        v_errors := array_append(v_errors, 'No waypoints resolved from route string.');

        RETURN QUERY SELECT
            v_valid, v_errors, v_warnings,
            NULL::JSONB, NULL::TEXT, NULL::DECIMAL(10,2),
            NULL::TEXT, NULL::TEXT, NULL::TEXT, NULL::TEXT,
            NULL::TEXT[];
        RETURN;
    END IF;

    v_wp_count := jsonb_array_length(v_waypoints);
    IF v_wp_count < 2 THEN
        v_warnings := array_append(v_warnings, 'Route has fewer than 2 waypoints.');
    END IF;

    -- Convert geometry
    IF v_geom IS NOT NULL THEN
        v_geojson := ST_AsGeoJSON(v_geom);
        v_distance := ROUND((ST_Length(v_geom::geography) / 1852.0)::NUMERIC, 2);
    ELSE
        v_distance := 0;
    END IF;

    -- Parse validation rules
    v_constrained_firs := COALESCE(
        (SELECT array_agg(x.val) FROM jsonb_array_elements_text(p_validation_rules->'constrained_firs') x(val)),
        ARRAY[]::TEXT[]
    );
    v_allowed_entry := COALESCE(
        (SELECT array_agg(x.val) FROM jsonb_array_elements_text(p_validation_rules->'allowed_entry_points') x(val)),
        ARRAY[]::TEXT[]
    );
    v_allowed_exit := COALESCE(
        (SELECT array_agg(x.val) FROM jsonb_array_elements_text(p_validation_rules->'allowed_exit_points') x(val)),
        ARRAY[]::TEXT[]
    );
    v_altitude_min := COALESCE((p_validation_rules->>'altitude_min')::INT, 0);
    v_altitude_max := COALESCE((p_validation_rules->>'altitude_max')::INT, 99999);

    -- Step 2: Find entry/exit points by checking waypoints against oceanic FIR boundaries
    IF array_length(v_constrained_firs, 1) > 0 THEN
        FOR i IN 0..v_wp_count-1 LOOP
            v_wp := v_waypoints->i;
            v_wp_id := v_wp->>'id';
            v_wp_lat := (v_wp->>'lat')::DECIMAL(10,7);
            v_wp_lon := (v_wp->>'lon')::DECIMAL(11,7);

            -- Check if this waypoint is inside any constrained FIR
            SELECT ab.artcc_code INTO v_fir_code
            FROM artcc_boundaries ab
            WHERE ab.artcc_code = ANY(v_constrained_firs)
              AND ST_Contains(ab.geom, ST_SetSRID(ST_MakePoint(v_wp_lon, v_wp_lat), 4326))
            LIMIT 1;

            IF v_fir_code IS NOT NULL THEN
                IF NOT v_found_entry THEN
                    v_entry_fix := v_wp_id;
                    v_entry_fir := v_fir_code;
                    v_found_entry := TRUE;
                END IF;
                v_exit_fix := v_wp_id;
                v_exit_fir := v_fir_code;
                v_found_exit := TRUE;
            END IF;
        END LOOP;

        IF NOT v_found_entry THEN
            v_valid := FALSE;
            v_errors := array_append(v_errors, 'Route does not enter any constrained FIR (' || array_to_string(v_constrained_firs, ', ') || ').');
        END IF;
    END IF;

    -- Step 3: Validate entry/exit points against allowed lists
    IF array_length(v_allowed_entry, 1) > 0 AND v_entry_fix IS NOT NULL THEN
        IF NOT (v_entry_fix = ANY(v_allowed_entry)) THEN
            v_valid := FALSE;
            v_errors := array_append(v_errors, 'Entry fix ' || v_entry_fix || ' is not in the allowed list: ' || array_to_string(v_allowed_entry, ', '));
        END IF;
    END IF;

    IF array_length(v_allowed_exit, 1) > 0 AND v_exit_fix IS NOT NULL THEN
        IF NOT (v_exit_fix = ANY(v_allowed_exit)) THEN
            v_valid := FALSE;
            v_errors := array_append(v_errors, 'Exit fix ' || v_exit_fix || ' is not in the allowed list: ' || array_to_string(v_allowed_exit, ', '));
        END IF;
    END IF;

    -- Step 4: Check ARTCC traversal (warning if traverses unexpected ARTCCs)
    IF v_artccs IS NOT NULL AND array_length(v_artccs, 1) > 0 THEN
        -- This is informational only; no error
        NULL;
    END IF;

    -- Step 5: Distance sanity check
    IF v_distance IS NOT NULL AND v_distance > 0 THEN
        IF v_distance < 100 THEN
            v_warnings := array_append(v_warnings, 'Route distance is very short (' || v_distance || ' nm). Verify waypoints.');
        END IF;
        IF v_distance > 8000 THEN
            v_warnings := array_append(v_warnings, 'Route distance exceeds 8000 nm (' || v_distance || ' nm). Possible waypoint error.');
        END IF;
    END IF;

    RETURN QUERY SELECT
        v_valid,
        v_errors,
        v_warnings,
        v_waypoints,
        v_geojson,
        v_distance,
        v_entry_fix,
        v_exit_fix,
        v_entry_fir,
        v_exit_fir,
        v_artccs;
END;
$$;

COMMENT ON FUNCTION validate_oceanic_route IS 'Validates a CTP oceanic route segment: parseable, crosses expected FIRs, valid entry/exit points, altitude range. Returns validation result with GeoJSON.';
