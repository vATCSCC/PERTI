-- =============================================================================
-- Migration 010: Skip pseudo-fix placeholders in expand_route()
-- =============================================================================
-- Database: VATSIM_GIS (PostgreSQL/PostGIS)
--
-- Problem: UNKN/VARIOUS pseudo-fixes resolve to placeholder locations (e.g.,
-- UNKN in nav_fixes at some arbitrary location). When used as route origins for
-- routes far from the pseudo-location, the distance context poisons subsequent
-- waypoint resolution (e.g., Asia→PANC cargo routes starting with UNKN).
--
-- Fix: Skip UNKN/VARIOUS tokens entirely in expand_route() so they never set
-- position context. The next real waypoint resolves without distance constraints.
--
-- Base: migration 008's expand_route (the last deployed version) with pseudo-fix
-- skip added. Also skips UNKN/VARIOUS in lookahead context for airway distance
-- validation.
--
-- Affected routes: ANC_CARGO_ROUTES and similar plays with UNKN origin/dest.
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

        -- Skip pseudo-fix placeholders (UNKN, VARIOUS) — these have no
        -- meaningful geographic location and would poison proximity context
        -- for subsequent waypoints (e.g., UNKN resolved in Atlantic breaks
        -- Pacific routes like Asia→PANC cargo plays)
        IF UPPER(v_part) IN ('UNKN', 'VARIOUS') THEN
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
            -- Strip procedure notation (e.g., "KDFW.LOWGN5" -> "KDFW")
            IF v_part LIKE '%.%' THEN
                v_part := split_part(v_part, '.', 1);
            END IF;

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
            END IF;

            v_idx := v_idx + 1;
        END IF;
    END LOOP;
END;
$function$;

COMMENT ON FUNCTION expand_route(TEXT) IS 'Parses and expands a route string to ordered waypoints with coordinates';
