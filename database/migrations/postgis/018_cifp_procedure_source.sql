-- =============================================================================
-- Migration 018: Include CIFP source in procedure expansion
-- =============================================================================
-- Database: VATSIM_GIS (PostgreSQL/PostGIS)
--
-- Problem: expand_route() filters nav_procedures by source IN ('NASR', 'nasr',
-- 'cifp_base', 'synthetic_base'), excluding 71,795 international procedures
-- imported with source = 'CIFP' (deployed 2026-03-21). International airports
-- have ZERO non-CIFP procedures, so 100% of their STARs/DPs are invisible
-- to route expansion.
--
-- Fix: Add 'CIFP' to all source filter clauses (4 locations) and add NASR
-- preference in ORDER BY to prevent US regression when both NASR and CIFP
-- procedures exist for the same airport.
--
-- ARINC 424 truncation: Pilots file full base names (DIXAT1A) but CIFP
-- truncates 5+ char alpha prefix to 4 chars (DIXA1A). All procedure lookup
-- queries include OR alternatives with truncated names as fallback.
--
-- Data quality: 99.94% of CIFP STAR fix references resolve in nav_fixes.
--
-- Safe to re-run: uses CREATE OR REPLACE FUNCTION.
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
    v_trunc_name TEXT;   -- ARINC 424 truncated procedure name (5+ char alpha -> 4)
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
        -- NASR uses TRANSITION.PROCEDURE (ENE.PARCH4)
        -- CIFP uses PROCEDURE.TRANSITION (PARCH4.ENE)
        -- Try both orientations; prefer non-CIFP sources (cleaner full_route)
        -- =====================================================================
        IF v_part LIKE '%.%' AND v_part !~ '^\d' THEN
            v_dot_left := split_part(v_part, '.', 1);
            v_dot_right := split_part(v_part, '.', 2);

            v_proc_route := NULL;

            -- ARINC 424 truncation: CIFP truncates 5+ char alpha base to 4
            -- e.g., pilots file DIXAT1A but CIFP stores DIXA1A
            v_trunc_name := NULL;
            IF UPPER(v_dot_right) ~ '^[A-Z]{5,}\d' THEN
                v_trunc_name := LEFT(regexp_replace(UPPER(v_dot_right), '\d.*$', ''), 4)
                             || regexp_replace(UPPER(v_dot_right), '^[A-Z]+', '');
            ELSIF UPPER(v_dot_left) ~ '^[A-Z]{5,}\d' THEN
                v_trunc_name := LEFT(regexp_replace(UPPER(v_dot_left), '\d.*$', ''), 4)
                             || regexp_replace(UPPER(v_dot_left), '^[A-Z]+', '');
            END IF;

            -- Look up procedure full_route from nav_procedures.
            -- Filed routes use TRANSITION.PROCEDURE (STARs: ENE.PARCH4)
            --   or PROCEDURE.TRANSITION (DPs: DEEZZ6.CANDR)
            -- NASR computer_code: STAR=BASE.VERSION (PARCH.PARCH4),
            --   DP=VERSION.BASE (DEEZZ6.DEEZZ)
            -- Include CIFP source for international procedures (99.94% STAR fix resolvability).
            -- NASR preferred for US airports via ORDER BY to preserve existing behavior.
            -- Strategy: match by computer_code pattern + transition in full_route.
            SELECT np.full_route INTO v_proc_route
            FROM nav_procedures np
            WHERE np.source IN ('NASR', 'nasr', 'cifp_base', 'synthetic_base', 'CIFP')
              AND np.is_active = true
              AND (np.is_superseded IS NULL OR np.is_superseded = false)
              AND np.full_route IS NOT NULL
              AND np.full_route != ''
              AND (
                -- STAR: TRANSITION.PROCEDURE (ENE.PARCH4)
                -- computer_code ends with .PARCH4, route starts with ENE
                (np.computer_code LIKE '%.' || UPPER(v_dot_right)
                 AND np.full_route LIKE UPPER(v_dot_left) || ' %')
                -- DP: PROCEDURE.TRANSITION (DEEZZ6.CANDR)
                -- computer_code starts with DEEZZ6., route contains CANDR
                OR (np.computer_code LIKE UPPER(v_dot_left) || '.%'
                    AND np.full_route LIKE '% ' || UPPER(v_dot_right) || '%')
                -- Reversed orientations (less common)
                OR (np.computer_code LIKE '%.' || UPPER(v_dot_left)
                    AND np.full_route LIKE UPPER(v_dot_right) || ' %')
                OR (np.computer_code LIKE UPPER(v_dot_right) || '.%'
                    AND np.full_route LIKE '% ' || UPPER(v_dot_left) || '%')
                -- Exact computer_code match
                OR np.computer_code = UPPER(v_part)
                OR np.computer_code = UPPER(v_dot_right || '.' || v_dot_left)
                -- ARINC 424 truncation alternatives for dot-notation
                OR (v_trunc_name IS NOT NULL AND np.computer_code LIKE '%.' || v_trunc_name)
                OR (v_trunc_name IS NOT NULL AND np.computer_code LIKE v_trunc_name || '.%')
                OR (v_trunc_name IS NOT NULL AND np.computer_code = v_trunc_name)
              )
            ORDER BY
              CASE WHEN np.source IN ('NASR', 'nasr') THEN 0 ELSE 1 END,
              CASE WHEN np.full_route LIKE UPPER(v_dot_left) || ' %' THEN 0 ELSE 1 END,
              length(np.full_route) DESC
            LIMIT 1;

            -- If we found a procedure, expand its full_route waypoints
            IF v_proc_route IS NOT NULL AND v_proc_route != '' THEN
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

                    -- Skip if same as previous fix (dedup consecutive duplicates from CIFP data)
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
                -- Skip consecutive duplicate (e.g., procedure ends with BPK then standalone BPK follows)
                IF v_prev_fix IS NOT NULL AND UPPER(v_part) = UPPER(v_prev_fix) THEN
                    v_idx := v_idx + 1;
                    CONTINUE;
                END IF;

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
                -- (e.g., "SNAPR2" without dot notation, space-separated routes)
                --
                -- NASR stores transitions under BASE procedure_name:
                --   DP: computer_code = 'SNAPR2.SNAPR', procedure_name = 'SNAPR'
                --   STAR: computer_code = 'BEANO.BEANO3', procedure_name = 'BEANO'
                -- So we search by computer_code LIKE pattern, not procedure_name.
                --
                -- The adjacent token is used as transition context:
                --   DP: next token is transition fix (SNAPR2 SUMAC -> route ending at SUMAC)
                --   STAR: prev token is transition fix (BEANO BEANO3 -> route starting from BEANO)
                --
                -- Include CIFP source for international procedures.
                -- NASR preferred via ORDER BY to preserve US behavior.
                v_proc_route := NULL;

                -- ARINC 424 truncation: CIFP truncates 5+ char alpha base to 4
                -- e.g., pilots file DIXAT1A but CIFP stores DIXA1A
                v_trunc_name := NULL;
                IF UPPER(v_part) ~ '^[A-Z]{5,}\d' THEN
                    v_trunc_name := LEFT(regexp_replace(UPPER(v_part), '\d.*$', ''), 4)
                                 || regexp_replace(UPPER(v_part), '^[A-Z]+', '');
                END IF;

                -- Use adjacent token as transition hint
                v_next_fix := NULL;
                IF v_idx < array_length(v_parts, 1) THEN
                    v_next_fix := UPPER(v_parts[v_idx + 1]);
                END IF;

                -- Try DP lookup: computer_code LIKE 'SNAPR2.%'
                -- Prefer route containing the next token (transition fix)
                -- Filter by procedure_type to prevent CIFP STARs (which use
                -- PROC.TRANSITION format) from matching the DP pattern.
                SELECT np.full_route INTO v_proc_route
                FROM nav_procedures np
                WHERE (np.computer_code LIKE UPPER(v_part) || '.%'
                       OR (v_trunc_name IS NOT NULL AND np.computer_code LIKE v_trunc_name || '.%'))
                  AND np.procedure_type IN ('DP', 'SID')
                  AND np.source IN ('NASR', 'nasr', 'cifp_base', 'synthetic_base', 'CIFP')
                  AND np.is_active = true
                  AND (np.is_superseded IS NULL OR np.is_superseded = false)
                  AND np.full_route IS NOT NULL
                  AND np.full_route != ''
                ORDER BY
                  CASE WHEN np.source IN ('NASR', 'nasr') THEN 0 ELSE 1 END,
                  CASE WHEN v_next_fix IS NOT NULL
                       AND (np.full_route LIKE '% ' || v_next_fix
                            OR np.full_route LIKE '% ' || v_next_fix || ' %')
                       THEN 0 ELSE 1 END,
                  length(np.full_route) ASC
                LIMIT 1;

                -- Try STAR lookup: computer_code LIKE '%.BEANO3'
                -- Filter by procedure_type for symmetry with DP lookup above.
                IF v_proc_route IS NULL THEN
                    SELECT np.full_route INTO v_proc_route
                    FROM nav_procedures np
                    WHERE (np.computer_code LIKE '%.' || UPPER(v_part)
                           OR (v_trunc_name IS NOT NULL AND np.computer_code LIKE '%.' || v_trunc_name))
                      AND np.procedure_type = 'STAR'
                      AND np.source IN ('NASR', 'nasr', 'cifp_base', 'synthetic_base', 'CIFP')
                      AND np.is_active = true
                      AND (np.is_superseded IS NULL OR np.is_superseded = false)
                      AND np.full_route IS NOT NULL
                      AND np.full_route != ''
                    ORDER BY
                      CASE WHEN np.source IN ('NASR', 'nasr') THEN 0 ELSE 1 END,
                      CASE WHEN v_prev_fix IS NOT NULL
                           AND (np.full_route LIKE v_prev_fix || ' %'
                                OR np.full_route LIKE '% ' || v_prev_fix || ' %')
                           THEN 0 ELSE 1 END,
                      length(np.full_route) ASC
                    LIMIT 1;
                END IF;

                -- Fallback: exact procedure_name match (catches PARCH4 etc.)
                IF v_proc_route IS NULL THEN
                    SELECT np.full_route INTO v_proc_route
                    FROM nav_procedures np
                    WHERE (np.procedure_name = UPPER(v_part)
                           OR (v_trunc_name IS NOT NULL AND np.procedure_name = v_trunc_name))
                      AND np.source IN ('NASR', 'nasr', 'cifp_base', 'synthetic_base', 'CIFP')
                      AND np.is_active = true
                      AND (np.is_superseded IS NULL OR np.is_superseded = false)
                      AND np.full_route IS NOT NULL
                      AND np.full_route != ''
                    ORDER BY
                      CASE WHEN np.source IN ('NASR', 'nasr') THEN 0 ELSE 1 END,
                      length(np.full_route) ASC
                    LIMIT 1;
                END IF;

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

COMMENT ON FUNCTION expand_route(TEXT) IS 'Parses and expands a route string to ordered waypoints with coordinates. Supports airways, direct fixes, coordinate waypoints, STAR/DP procedure expansion including international CIFP procedures.';
