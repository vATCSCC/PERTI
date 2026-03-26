-- =============================================================================
-- Migration 012: Add STAR/DP procedure expansion to expand_route()
-- =============================================================================
-- Database: VATSIM_GIS (PostgreSQL/PostGIS)
--
-- Problem: expand_route() cannot resolve STAR/DP procedure tokens.
-- When a route contains "ENE.PARCH4" (dot notation) or "ENE PARCH4"
-- (space-separated), the procedure name "PARCH4" is either silently
-- dropped (dot case) or fails to resolve as a fix (space case).
--
-- Fix: Add procedure resolution logic that:
-- 1. Detects dot-notation procedure tokens (e.g., "ENE.PARCH4")
-- 2. Looks up the transition's full_route in nav_procedures
-- 3. Expands the procedure waypoints inline
-- 4. Falls back to resolving standalone procedure names as fixes
--
-- The nav_procedures table has ~100K rows with computer_code, full_route,
-- airport_icao, and procedure_type columns. STAR computer_codes use
-- format "TRANSITION.PROCEDURE" (e.g., "ENE.PARCH4").
--
-- Base: migration 010's expand_route with procedure expansion added.
-- =============================================================================

CREATE OR REPLACE FUNCTION public.expand_route(p_route_string text)
RETURNS TABLE(waypoint_seq integer, waypoint_id character varying, lat numeric, lon numeric, waypoint_type character varying)
LANGUAGE plpgsql
STABLE
AS $function$
DECLARE
    v_raw_parts TEXT[];
    v_parts TEXT[];
    v_idx INT;
    v_part TEXT;
    v_prev_fix TEXT := NULL;
    v_prev_lat NUMERIC := NULL;
    v_prev_lon NUMERIC := NULL;
    v_seq INT := 0;
    v_is_airway BOOLEAN;
    v_next_fix TEXT;
    v_wp RECORD;
    v_airway_wp RECORD;
    v_resolved_lat NUMERIC;
    v_resolved_lon NUMERIC;
    v_equirect_dist NUMERIC;
    v_slash_parts TEXT[];
    v_processed TEXT[];
    v_i INT;
    v_j INT;
    -- Procedure expansion variables
    v_dot_left TEXT;
    v_dot_right TEXT;
    v_proc_route TEXT;
    v_proc_parts TEXT[];
    v_proc_idx INT;
    v_proc_part TEXT;
BEGIN
    -- Split route string into raw parts
    v_raw_parts := regexp_split_to_array(TRIM(p_route_string), '\s+');

    -- Pre-process: split slash-delimited tokens that are NOT coordinate formats
    v_processed := ARRAY[]::TEXT[];
    FOR v_i IN 1..array_length(v_raw_parts, 1) LOOP
        v_part := v_raw_parts[v_i];
        IF v_part IS NULL OR v_part = '' THEN
            CONTINUE;
        END IF;

        -- Check if this is a NAT slash coordinate (dd/dd or dd/ddd)
        IF v_part ~ '^\d{2}/\d{2,3}$' THEN
            v_processed := array_append(v_processed, v_part);
        ELSIF position('/' IN v_part) > 0 THEN
            -- Split on slash and take each non-empty piece
            v_slash_parts := string_to_array(v_part, '/');
            FOR v_j IN 1..array_length(v_slash_parts, 1) LOOP
                IF v_slash_parts[v_j] IS NOT NULL AND v_slash_parts[v_j] != '' THEN
                    v_processed := array_append(v_processed, v_slash_parts[v_j]);
                END IF;
            END LOOP;
        ELSE
            v_processed := array_append(v_processed, v_part);
        END IF;
    END LOOP;

    v_parts := v_processed;

    v_idx := 1;
    WHILE v_idx <= array_length(v_parts, 1) LOOP
        v_part := v_parts[v_idx];

        -- Skip empty parts
        IF v_part IS NULL OR v_part = '' THEN
            v_idx := v_idx + 1;
            CONTINUE;
        END IF;

        -- Skip pseudo-fix placeholders (UNKN, VARIOUS)
        IF UPPER(v_part) IN ('UNKN', 'VARIOUS') THEN
            v_idx := v_idx + 1;
            CONTINUE;
        END IF;

        -- =====================================================================
        -- Procedure detection: dot-notation tokens like "ENE.PARCH4"
        -- Format: TRANSITION.PROCEDURE (STAR) or PROCEDURE.TRANSITION (DP)
        -- =====================================================================
        IF v_part LIKE '%.%' AND v_part !~ '^\d' THEN
            v_dot_left := split_part(v_part, '.', 1);
            v_dot_right := split_part(v_part, '.', 2);

            -- Strip runway suffixes from right side (e.g., "PARCH4" stays, but airport codes with runways get cleaned)
            -- Try STAR lookup first: computer_code = 'LEFT.RIGHT' (e.g., ENE.PARCH4)
            v_proc_route := NULL;
            SELECT np.full_route INTO v_proc_route
            FROM nav_procedures np
            WHERE np.computer_code = UPPER(v_part)
              AND np.procedure_type = 'STAR'
              AND np.is_active = true
              AND (np.is_superseded IS NULL OR np.is_superseded = false)
            LIMIT 1;

            -- If not found as STAR, try DP: computer_code = 'LEFT.RIGHT' (e.g., RNGRR5.JFK)
            IF v_proc_route IS NULL THEN
                SELECT np.full_route INTO v_proc_route
                FROM nav_procedures np
                WHERE np.computer_code = UPPER(v_part)
                  AND np.procedure_type = 'DP'
                  AND np.is_active = true
                  AND (np.is_superseded IS NULL OR np.is_superseded = false)
                LIMIT 1;
            END IF;

            -- If we found a procedure, expand its full_route waypoints
            IF v_proc_route IS NOT NULL AND v_proc_route != '' THEN
                -- Strip runway suffixes from full_route (e.g., "ENE ASPEN PVD ... JFK/22L|22R" -> remove runway part)
                v_proc_parts := regexp_split_to_array(TRIM(v_proc_route), '\s+');
                FOR v_proc_idx IN 1..array_length(v_proc_parts, 1) LOOP
                    v_proc_part := v_proc_parts[v_proc_idx];
                    IF v_proc_part IS NULL OR v_proc_part = '' THEN
                        CONTINUE;
                    END IF;
                    -- Skip runway tokens (e.g., "JFK/22L|22R", "22L", "04L|04R|13L|13R|31L|31R")
                    IF v_proc_part ~ '/' OR v_proc_part ~ '^\d{2}[LRC]' THEN
                        CONTINUE;
                    END IF;

                    -- Skip if same as previous fix (avoid duplicates at procedure boundaries)
                    IF v_prev_fix IS NOT NULL AND UPPER(v_proc_part) = UPPER(v_prev_fix) THEN
                        CONTINUE;
                    END IF;

                    -- Resolve each waypoint in the procedure
                    SELECT rw.fix_id, rw.lat, rw.lon, rw.source
                    INTO v_wp
                    FROM resolve_waypoint(v_proc_part, v_prev_lat, v_prev_lon) rw
                    LIMIT 1;

                    IF v_wp.fix_id IS NOT NULL AND v_wp.lat IS NOT NULL THEN
                        v_seq := v_seq + 1;
                        waypoint_seq := v_seq;
                        waypoint_id := v_wp.fix_id;
                        lat := v_wp.lat;
                        lon := v_wp.lon;
                        waypoint_type := 'procedure';
                        RETURN NEXT;
                        v_prev_fix := v_proc_part;
                        v_prev_lat := v_wp.lat;
                        v_prev_lon := v_wp.lon;
                    END IF;
                END LOOP;

                v_idx := v_idx + 1;
                CONTINUE;
            END IF;

            -- Not a procedure — fall through to normal fix resolution with left side only
            v_part := v_dot_left;
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
                v_resolved_lat := v_airway_wp.lat;
                v_resolved_lon := v_airway_wp.lon;

                -- Validate: if airway segment coordinates are unreasonably far
                -- from previous point, re-resolve using context-aware disambiguation.
                IF v_prev_lat IS NOT NULL THEN
                    v_equirect_dist := sqrt(
                        (v_resolved_lat - v_prev_lat)^2 +
                        ((v_resolved_lon - v_prev_lon) * cos(radians(v_prev_lat)))^2
                    );
                    IF v_equirect_dist > 25 THEN
                        SELECT rw.lat, rw.lon
                        INTO v_resolved_lat, v_resolved_lon
                        FROM resolve_waypoint(v_airway_wp.fix_id, v_prev_lat, v_prev_lon) rw
                        LIMIT 1;
                    END IF;
                END IF;

                v_seq := v_seq + 1;
                waypoint_seq := v_seq;
                waypoint_id := v_airway_wp.fix_id;
                lat := v_resolved_lat;
                lon := v_resolved_lon;
                waypoint_type := 'airway_' || v_part;
                RETURN NEXT;
                v_prev_fix := v_airway_wp.fix_id;
                v_prev_lat := v_resolved_lat;
                v_prev_lon := v_resolved_lon;
            END LOOP;

            -- Skip the airway and next fix (already processed via expansion)
            v_idx := v_idx + 2;
        ELSE
            -- Direct waypoint/fix
            -- Resolve waypoint with context for disambiguation
            SELECT rw.fix_id, rw.lat, rw.lon, rw.source
            INTO v_wp
            FROM resolve_waypoint(v_part, v_prev_lat, v_prev_lon) rw
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
                v_prev_lat := v_wp.lat;
                v_prev_lon := v_wp.lon;
            ELSE
                -- Fix not found — check if this is a standalone procedure name
                -- (e.g., "PARCH4" without dot notation, common in space-separated routes)
                -- Look for any STAR/DP matching this procedure_name
                v_proc_route := NULL;
                SELECT np.full_route INTO v_proc_route
                FROM nav_procedures np
                WHERE np.procedure_name = UPPER(v_part)
                  AND np.is_active = true
                  AND (np.is_superseded IS NULL OR np.is_superseded = false)
                  -- Prefer the base transition (no transition prefix, shortest route)
                ORDER BY length(np.full_route) ASC
                LIMIT 1;

                IF v_proc_route IS NOT NULL AND v_proc_route != '' THEN
                    v_proc_parts := regexp_split_to_array(TRIM(v_proc_route), '\s+');
                    FOR v_proc_idx IN 1..array_length(v_proc_parts, 1) LOOP
                        v_proc_part := v_proc_parts[v_proc_idx];
                        IF v_proc_part IS NULL OR v_proc_part = '' THEN
                            CONTINUE;
                        END IF;
                        IF v_proc_part ~ '/' OR v_proc_part ~ '^\d{2}[LRC]' THEN
                            CONTINUE;
                        END IF;
                        IF v_prev_fix IS NOT NULL AND UPPER(v_proc_part) = UPPER(v_prev_fix) THEN
                            CONTINUE;
                        END IF;

                        SELECT rw.fix_id, rw.lat, rw.lon, rw.source
                        INTO v_wp
                        FROM resolve_waypoint(v_proc_part, v_prev_lat, v_prev_lon) rw
                        LIMIT 1;

                        IF v_wp.fix_id IS NOT NULL AND v_wp.lat IS NOT NULL THEN
                            v_seq := v_seq + 1;
                            waypoint_seq := v_seq;
                            waypoint_id := v_wp.fix_id;
                            lat := v_wp.lat;
                            lon := v_wp.lon;
                            waypoint_type := 'procedure';
                            RETURN NEXT;
                            v_prev_fix := v_proc_part;
                            v_prev_lat := v_wp.lat;
                            v_prev_lon := v_wp.lon;
                        END IF;
                    END LOOP;
                END IF;
                -- If not a procedure either, silently skip (unknown token)
            END IF;

            v_idx := v_idx + 1;
        END IF;
    END LOOP;
END;
$function$;

COMMENT ON FUNCTION expand_route(TEXT) IS 'Parses and expands a route string to ordered waypoints with coordinates. Supports airways, direct fixes, coordinate waypoints, and STAR/DP procedure expansion.';
