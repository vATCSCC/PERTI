-- Migration 023: Runway-aware expand_route()
--
-- Adds departure/arrival runway context extraction during pre-processing.
-- When route strings contain airport/runway tokens (e.g., KJFK/31L DEEZZ6.CANDR ... KDEN/16L),
-- the function now prefers procedure variants matching the specified runway group.
--
-- Runway context tracking:
--   - Departure airport + runway(s) = first airport/runway token in route
--   - Arrival airport + runway(s)   = last airport/runway token in route
--   - Alternate airports + runways  = any intermediate airport/runway tokens
--   - DP lookups prefer departure runway
--   - STAR lookups prefer arrival runway
--   - Dot-notation lookups check both departure and arrival
--
-- This is a SOFT preference in ORDER BY — if runway_group is NULL or doesn't match,
-- existing behavior is unchanged (no breaking changes).
--
-- Date: 2026-04-05
-- Depends on: migration 022 (body_name, runway_group columns)

CREATE OR REPLACE FUNCTION expand_route(p_route_string TEXT)
RETURNS TABLE(waypoint_seq INT, waypoint_id VARCHAR, lat NUMERIC, lon NUMERIC, waypoint_type VARCHAR)
LANGUAGE plpgsql STABLE
AS $$
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
    v_airway_expanded BOOLEAN;
    -- Procedure expansion variables
    v_dot_left TEXT;
    v_dot_right TEXT;
    v_proc_route TEXT;
    v_proc_parts TEXT[];
    v_proc_idx INT;
    v_proc_part TEXT;
    v_trunc_name TEXT;
    -- Buffered output arrays (Pass 1 collects, Pass 2 corrects, then return)
    v_r_count INT := 0;
    v_r_ids VARCHAR[];
    v_r_lats NUMERIC[];
    v_r_lons NUMERIC[];
    v_r_types VARCHAR[];
    v_k INT;
    -- Runway context: airport/runway associations extracted from route tokens
    -- Parallel arrays: v_rwy_airports[i] has runway v_rwy_runways[i]
    v_rwy_airports TEXT[] := ARRAY[]::TEXT[];
    v_rwy_runways TEXT[] := ARRAY[]::TEXT[];
    v_rwy_count INT := 0;
    -- Derived departure/arrival context
    v_dep_airport TEXT := NULL;    -- first airport with runway (departure)
    v_dep_runways TEXT := NULL;    -- pipe-delimited runways at departure (e.g., '31L|31R')
    v_arr_airport TEXT := NULL;    -- last airport with runway (arrival)
    v_arr_runways TEXT := NULL;    -- pipe-delimited runways at arrival
    -- Temp variables for runway extraction
    v_rwy_apt TEXT;
    v_rwy_part TEXT;
    v_rwy_list TEXT[];
    v_rwy_str TEXT;
BEGIN
    -- Split route string into raw parts
    v_raw_parts := regexp_split_to_array(TRIM(p_route_string), '\s+');

    -- ======================================================================
    -- PRE-SCAN: Extract airport/runway pairs from slash tokens
    -- Patterns recognized:
    --   KJFK/31L       -> airport=KJFK, runways=['31L']
    --   KJFK/31L|31R   -> airport=KJFK, runways=['31L','31R']
    --   SJU/08|10|26   -> airport=SJU,  runways=['08','10','26'] (3-char FAA LID)
    --   55/40           -> SKIP (NAT coordinate)
    --   FIX/FIX         -> SKIP (not airport/runway format)
    -- ======================================================================
    FOR v_i IN 1..array_length(v_raw_parts, 1) LOOP
        v_part := v_raw_parts[v_i];
        IF v_part IS NULL OR v_part = '' THEN
            CONTINUE;
        END IF;

        -- Only look at tokens containing '/' that are NOT coordinates
        IF position('/' IN v_part) > 0 AND v_part !~ '^\d{2}/\d{2,3}$' THEN
            v_rwy_apt := split_part(v_part, '/', 1);
            v_rwy_part := split_part(v_part, '/', 2);

            -- Check if first part looks like an airport (3-4 alpha chars, or 4-char ICAO)
            IF v_rwy_apt ~ '^[A-Z]{3,4}$' THEN
                -- Check if second part contains runway designators (digits + optional L/R/C/B)
                -- Handle pipe-delimited: 31L|31R|04L
                v_rwy_list := string_to_array(v_rwy_part, '|');
                IF v_rwy_list[1] ~ '^\d{2}[LRCB]?$' THEN
                    -- Valid airport/runway pair found
                    -- Store each individual runway with its airport
                    FOREACH v_rwy_str IN ARRAY v_rwy_list LOOP
                        IF v_rwy_str ~ '^\d{2}[LRCB]?$' THEN
                            v_rwy_count := v_rwy_count + 1;
                            v_rwy_airports := array_append(v_rwy_airports, UPPER(v_rwy_apt));
                            v_rwy_runways := array_append(v_rwy_runways, UPPER(v_rwy_str));
                        END IF;
                    END LOOP;

                    -- Track departure (first) and arrival (last) airports
                    IF v_dep_airport IS NULL THEN
                        v_dep_airport := UPPER(v_rwy_apt);
                        v_dep_runways := UPPER(v_rwy_part);  -- keep pipe-delimited form
                    END IF;
                    -- Always update arrival (so last one wins)
                    v_arr_airport := UPPER(v_rwy_apt);
                    v_arr_runways := UPPER(v_rwy_part);
                END IF;
            END IF;
        END IF;
    END LOOP;

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

    -- ======================================================================
    -- PASS 1: Forward resolution (identical logic to migration 020,
    --         but with runway preference in procedure ORDER BY clauses)
    -- ======================================================================
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
        -- =====================================================================
        IF v_part LIKE '%.%' AND v_part !~ '^\d' THEN
            v_dot_left := split_part(v_part, '.', 1);
            v_dot_right := split_part(v_part, '.', 2);

            v_proc_route := NULL;

            v_trunc_name := NULL;
            IF UPPER(v_dot_right) ~ '^[A-Z]{5,}\d' THEN
                v_trunc_name := LEFT(regexp_replace(UPPER(v_dot_right), '\d.*$', ''), 4)
                             || regexp_replace(UPPER(v_dot_right), '^[A-Z]+', '');
            ELSIF UPPER(v_dot_left) ~ '^[A-Z]{5,}\d' THEN
                v_trunc_name := LEFT(regexp_replace(UPPER(v_dot_left), '\d.*$', ''), 4)
                             || regexp_replace(UPPER(v_dot_left), '^[A-Z]+', '');
            END IF;

            SELECT np.full_route INTO v_proc_route
            FROM nav_procedures np
            WHERE np.source IN ('NASR', 'nasr', 'cifp_base', 'synthetic_base', 'CIFP')
              AND np.is_active = true
              AND (np.is_superseded IS NULL OR np.is_superseded = false)
              AND np.full_route IS NOT NULL
              AND np.full_route != ''
              AND (
                (np.computer_code LIKE '%.' || UPPER(v_dot_right)
                 AND np.full_route LIKE UPPER(v_dot_left) || ' %')
                OR (np.computer_code LIKE UPPER(v_dot_left) || '.%'
                    AND np.full_route LIKE '% ' || UPPER(v_dot_right) || '%')
                OR (np.computer_code LIKE '%.' || UPPER(v_dot_left)
                    AND np.full_route LIKE UPPER(v_dot_right) || ' %')
                OR (np.computer_code LIKE UPPER(v_dot_right) || '.%'
                    AND np.full_route LIKE '% ' || UPPER(v_dot_left) || '%')
                OR np.computer_code = UPPER(v_part)
                OR np.computer_code = UPPER(v_dot_right || '.' || v_dot_left)
                OR (v_trunc_name IS NOT NULL AND np.computer_code LIKE '%.' || v_trunc_name)
                OR (v_trunc_name IS NOT NULL AND np.computer_code LIKE v_trunc_name || '.%')
                OR (v_trunc_name IS NOT NULL AND np.computer_code = v_trunc_name)
              )
            ORDER BY
              CASE WHEN np.source IN ('NASR', 'nasr') THEN 0 ELSE 1 END,
              -- Runway preference: check both dep and arr runways for dot-notation
              CASE WHEN np.runway_group IS NOT NULL AND v_dep_runways IS NOT NULL
                   AND np.runway_group LIKE '%' || split_part(v_dep_runways, '|', 1) || '%'
                   THEN 0
                   WHEN np.runway_group IS NOT NULL AND v_arr_runways IS NOT NULL
                   AND np.runway_group LIKE '%' || split_part(v_arr_runways, '|', 1) || '%'
                   THEN 0
                   ELSE 1 END,
              CASE WHEN np.full_route LIKE UPPER(v_dot_left) || ' %' THEN 0 ELSE 1 END,
              length(np.full_route) DESC
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
                        v_r_count := v_r_count + 1;
                        v_r_ids[v_r_count] := v_wp.fix_id;
                        v_r_lats[v_r_count] := v_wp.lat;
                        v_r_lons[v_r_count] := v_wp.lon;
                        v_r_types[v_r_count] := 'procedure';
                        v_prev_fix := v_proc_part;
                        v_prev_lat := v_wp.lat;
                        v_prev_lon := v_wp.lon;
                    END IF;
                END LOOP;

                v_idx := v_idx + 1;
                CONTINUE;
            END IF;

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
            -- Pass context coordinates for proximity-based variant selection
            v_airway_expanded := false;
            FOR v_airway_wp IN
                SELECT ea.seq, ea.fix_id, ea.lat, ea.lon
                FROM expand_airway(v_part, v_prev_fix, v_next_fix, v_prev_lat, v_prev_lon) ea
                WHERE ea.seq > 1
            LOOP
                v_airway_expanded := true;
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
                v_r_count := v_r_count + 1;
                v_r_ids[v_r_count] := v_airway_wp.fix_id;
                v_r_lats[v_r_count] := v_resolved_lat;
                v_r_lons[v_r_count] := v_resolved_lon;
                v_r_types[v_r_count] := 'airway_' || v_part;
                v_prev_fix := v_airway_wp.fix_id;
                v_prev_lat := v_resolved_lat;
                v_prev_lon := v_resolved_lon;
            END LOOP;

            IF v_airway_expanded THEN
                -- Airway expanded successfully — skip airway + exit fix tokens
                v_idx := v_idx + 2;
            ELSE
                -- Airway expansion failed (entry/exit on different variants, or
                -- airway not found). Skip only the airway token. The exit fix
                -- will be resolved independently on the next iteration via
                -- resolve_waypoint() with context from the previous waypoint.
                v_idx := v_idx + 1;
            END IF;
        ELSE
            -- Direct waypoint/fix
            SELECT rw.fix_id, rw.lat, rw.lon, rw.source
            INTO v_wp
            FROM resolve_waypoint(v_part, v_prev_lat, v_prev_lon) rw
            LIMIT 1;

            IF v_wp.fix_id IS NOT NULL AND v_wp.lat IS NOT NULL THEN
                IF v_prev_fix IS NOT NULL AND UPPER(v_part) = UPPER(v_prev_fix) THEN
                    v_idx := v_idx + 1;
                    CONTINUE;
                END IF;

                v_seq := v_seq + 1;
                v_r_count := v_r_count + 1;
                v_r_ids[v_r_count] := v_wp.fix_id;
                v_r_lats[v_r_count] := v_wp.lat;
                v_r_lons[v_r_count] := v_wp.lon;
                v_r_types[v_r_count] := v_wp.source;
                v_prev_fix := v_part;
                v_prev_lat := v_wp.lat;
                v_prev_lon := v_wp.lon;
            ELSE
                -- Fix not found — check if this is a standalone procedure name
                v_proc_route := NULL;

                v_trunc_name := NULL;
                IF UPPER(v_part) ~ '^[A-Z]{5,}\d' THEN
                    v_trunc_name := LEFT(regexp_replace(UPPER(v_part), '\d.*$', ''), 4)
                                 || regexp_replace(UPPER(v_part), '^[A-Z]+', '');
                END IF;

                v_next_fix := NULL;
                IF v_idx < array_length(v_parts, 1) THEN
                    v_next_fix := UPPER(v_parts[v_idx + 1]);
                    -- Strip dot-notation from next fix (e.g., "FIX.STAR5" -> "FIX")
                    IF v_next_fix LIKE '%.%' THEN
                        v_next_fix := split_part(v_next_fix, '.', 1);
                    END IF;
                END IF;

                -- Try DP lookup: exact transition match first (PROC.NEXT_FIX)
                -- This prevents selecting a combined full_route that contains all
                -- transitions (e.g., DEDKI5 dumping 24 waypoints from 3 transitions
                -- when only DEDKI5.RAKAM is needed).
                IF v_next_fix IS NOT NULL THEN
                    SELECT np.full_route INTO v_proc_route
                    FROM nav_procedures np
                    WHERE (np.computer_code = UPPER(v_part) || '.' || v_next_fix
                           OR (v_trunc_name IS NOT NULL
                               AND np.computer_code = v_trunc_name || '.' || v_next_fix))
                      AND np.procedure_type IN ('DP', 'SID')
                      AND np.source IN ('NASR', 'nasr', 'cifp_base', 'synthetic_base', 'CIFP')
                      AND np.is_active = true
                      AND (np.is_superseded IS NULL OR np.is_superseded = false)
                      AND np.full_route IS NOT NULL
                      AND np.full_route != ''
                    ORDER BY
                      CASE WHEN np.source IN ('NASR', 'nasr') THEN 0 ELSE 1 END,
                      -- DP: prefer departure runway match
                      CASE WHEN np.runway_group IS NOT NULL AND v_dep_runways IS NOT NULL
                           AND np.runway_group LIKE '%' || split_part(v_dep_runways, '|', 1) || '%'
                           THEN 0 ELSE 1 END,
                      length(np.full_route) ASC
                    LIMIT 1;
                END IF;

                -- Fallback: broader DP match with transition preference in ORDER BY
                IF v_proc_route IS NULL THEN
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
                      -- Prefer fix transitions over runway-specific transitions;
                      -- runway transitions (PROC.RWxx) are airport-config-dependent
                      -- and we don't know the active runway during route expansion.
                      CASE WHEN np.computer_code ~ '\.(RW\d|RW$)' THEN 1 ELSE 0 END,
                      -- DP: prefer departure runway match
                      CASE WHEN np.runway_group IS NOT NULL AND v_dep_runways IS NOT NULL
                           AND np.runway_group LIKE '%' || split_part(v_dep_runways, '|', 1) || '%'
                           THEN 0 ELSE 1 END,
                      CASE WHEN v_next_fix IS NOT NULL
                           AND (np.full_route LIKE '% ' || v_next_fix
                                OR np.full_route LIKE '% ' || v_next_fix || ' %')
                           THEN 0 ELSE 1 END,
                      length(np.full_route) ASC
                    LIMIT 1;
                END IF;

                -- Try STAR lookup: exact transition match first (PREV_FIX.PROC)
                IF v_proc_route IS NULL AND v_prev_fix IS NOT NULL THEN
                    SELECT np.full_route INTO v_proc_route
                    FROM nav_procedures np
                    WHERE (np.computer_code = UPPER(v_prev_fix) || '.' || UPPER(v_part)
                           OR (v_trunc_name IS NOT NULL
                               AND np.computer_code = UPPER(v_prev_fix) || '.' || v_trunc_name))
                      AND np.procedure_type = 'STAR'
                      AND np.source IN ('NASR', 'nasr', 'cifp_base', 'synthetic_base', 'CIFP')
                      AND np.is_active = true
                      AND (np.is_superseded IS NULL OR np.is_superseded = false)
                      AND np.full_route IS NOT NULL
                      AND np.full_route != ''
                    ORDER BY
                      CASE WHEN np.source IN ('NASR', 'nasr') THEN 0 ELSE 1 END,
                      -- STAR: prefer arrival runway match
                      CASE WHEN np.runway_group IS NOT NULL AND v_arr_runways IS NOT NULL
                           AND np.runway_group LIKE '%' || split_part(v_arr_runways, '|', 1) || '%'
                           THEN 0 ELSE 1 END,
                      length(np.full_route) ASC
                    LIMIT 1;
                END IF;

                -- Fallback: broader STAR match with entry preference in ORDER BY
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
                      -- Prefer fix transitions over runway-specific transitions
                      CASE WHEN np.computer_code ~ '^RW\d' THEN 1 ELSE 0 END,
                      -- STAR: prefer arrival runway match
                      CASE WHEN np.runway_group IS NOT NULL AND v_arr_runways IS NOT NULL
                           AND np.runway_group LIKE '%' || split_part(v_arr_runways, '|', 1) || '%'
                           THEN 0 ELSE 1 END,
                      CASE WHEN v_prev_fix IS NOT NULL
                           AND (np.full_route LIKE v_prev_fix || ' %'
                                OR np.full_route LIKE '% ' || v_prev_fix || ' %')
                           THEN 0 ELSE 1 END,
                      length(np.full_route) ASC
                    LIMIT 1;
                END IF;

                -- Fallback: exact procedure_name match
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
                      -- Use whichever runway context matches (dep for DP, arr for STAR)
                      CASE WHEN np.runway_group IS NOT NULL AND v_dep_runways IS NOT NULL
                           AND np.procedure_type IN ('DP', 'SID')
                           AND np.runway_group LIKE '%' || split_part(v_dep_runways, '|', 1) || '%'
                           THEN 0
                           WHEN np.runway_group IS NOT NULL AND v_arr_runways IS NOT NULL
                           AND np.procedure_type = 'STAR'
                           AND np.runway_group LIKE '%' || split_part(v_arr_runways, '|', 1) || '%'
                           THEN 0
                           ELSE 1 END,
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
                        -- Skip runway designators (RW09L, 26L, 34B) and slash tokens
                        IF v_proc_part ~ '/'
                           OR v_proc_part ~ '^RW\d{2}[LRCB]?$'
                           OR v_proc_part ~ '^\d{2}[LRCB]?$' THEN
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
                            v_r_count := v_r_count + 1;
                            v_r_ids[v_r_count] := v_wp.fix_id;
                            v_r_lats[v_r_count] := v_wp.lat;
                            v_r_lons[v_r_count] := v_wp.lon;
                            v_r_types[v_r_count] := 'procedure';
                            v_prev_fix := v_proc_part;
                            v_prev_lat := v_wp.lat;
                            v_prev_lon := v_wp.lon;
                        END IF;

                        -- Stop expanding if we've reached the next fix in the route.
                        -- This prevents multi-transition procedures (e.g., DEDKI5 at CYYZ)
                        -- from dumping all transitions when only one is needed.
                        IF v_next_fix IS NOT NULL AND UPPER(v_proc_part) = v_next_fix THEN
                            EXIT;
                        END IF;
                    END LOOP;
                END IF;
            END IF;

            v_idx := v_idx + 1;
        END IF;
    END LOOP;

    -- ======================================================================
    -- PASS 2: Anchor-based correction
    -- ======================================================================
    -- Anchors are high-confidence positions: airports, coordinates, area
    -- centers, procedures, airway waypoints, and nav_fixes with only one
    -- candidate globally. Ambiguous waypoints (nav_fixes with 2+ candidates)
    -- are re-resolved using the midpoint of the nearest left and right
    -- anchors as proximity context.
    -- ======================================================================
    IF v_r_count > 1 THEN
        DECLARE
            v_is_anchor BOOLEAN[];
            v_cand_count INT;
            v_left_lat NUMERIC;
            v_left_lon NUMERIC;
            v_right_lat NUMERIC;
            v_right_lon NUMERIC;
            v_ctx_lat NUMERIC;
            v_ctx_lon NUMERIC;
            v_new_lat NUMERIC;
            v_new_lon NUMERIC;
        BEGIN
            -- Classify anchors (high-confidence positions)
            FOR v_k IN 1..v_r_count LOOP
                IF v_r_types[v_k] IN ('airport', 'airport_faa', 'airport_k',
                                       'coordinate', 'area_center', 'procedure')
                   OR v_r_types[v_k] LIKE 'airway_%' THEN
                    v_is_anchor[v_k] := true;
                ELSE
                    SELECT COUNT(*) INTO v_cand_count
                    FROM nav_fixes nf
                    WHERE nf.fix_name = v_r_ids[v_k]
                    AND (nf.is_superseded IS NULL OR nf.is_superseded = false);
                    v_is_anchor[v_k] := (v_cand_count <= 1);
                END IF;
            END LOOP;

            -- Re-resolve ambiguous waypoints with bidirectional anchor context
            FOR v_k IN 1..v_r_count LOOP
                IF v_is_anchor[v_k] THEN CONTINUE; END IF;

                -- Find nearest left anchor
                v_left_lat := NULL; v_left_lon := NULL;
                FOR v_j IN REVERSE (v_k - 1)..1 LOOP
                    IF v_is_anchor[v_j] THEN
                        v_left_lat := v_r_lats[v_j];
                        v_left_lon := v_r_lons[v_j];
                        EXIT;
                    END IF;
                END LOOP;

                -- Find nearest right anchor
                v_right_lat := NULL; v_right_lon := NULL;
                FOR v_j IN (v_k + 1)..v_r_count LOOP
                    IF v_is_anchor[v_j] THEN
                        v_right_lat := v_r_lats[v_j];
                        v_right_lon := v_r_lons[v_j];
                        EXIT;
                    END IF;
                END LOOP;

                -- Compute context from anchors
                IF v_left_lat IS NOT NULL AND v_right_lat IS NOT NULL THEN
                    v_ctx_lat := (v_left_lat + v_right_lat) / 2.0;
                    v_ctx_lon := (v_left_lon + v_right_lon) / 2.0;
                ELSIF v_right_lat IS NOT NULL THEN
                    v_ctx_lat := v_right_lat;
                    v_ctx_lon := v_right_lon;
                ELSE
                    -- No right anchor — use left only (or skip correction)
                    CONTINUE;
                END IF;

                -- Re-resolve with better context
                SELECT rw.lat, rw.lon
                INTO v_new_lat, v_new_lon
                FROM resolve_waypoint(v_r_ids[v_k], v_ctx_lat, v_ctx_lon) rw
                LIMIT 1;

                IF v_new_lat IS NOT NULL THEN
                    -- Only update if position meaningfully changed (~1km threshold)
                    IF abs(v_r_lats[v_k] - v_new_lat) > 0.01
                       OR abs(v_r_lons[v_k] - v_new_lon) > 0.01 THEN
                        v_r_lats[v_k] := v_new_lat;
                        v_r_lons[v_k] := v_new_lon;
                    END IF;
                END IF;
            END LOOP;
        END;
    END IF;

    -- ======================================================================
    -- RETURN buffered results
    -- ======================================================================
    FOR v_k IN 1..v_r_count LOOP
        waypoint_seq := v_k;
        waypoint_id := v_r_ids[v_k];
        lat := v_r_lats[v_k];
        lon := v_r_lons[v_k];
        waypoint_type := v_r_types[v_k];
        RETURN NEXT;
    END LOOP;
END;
$$;


-- ============================================================================
-- Function comment
-- ============================================================================
COMMENT ON FUNCTION expand_route(TEXT) IS
    'Migration 023: Runway-aware procedure expansion. Pre-scans route for '
    'airport/runway tokens (e.g., KJFK/31L, KDEN/16L|16R) to extract departure '
    '(first) and arrival (last) runway context. DP lookups prefer departure '
    'runway_group match; STAR lookups prefer arrival runway_group match; '
    'dot-notation checks both. Soft ORDER BY preference — no breaking changes '
    'when runway_group is NULL. Preserves all two-pass disambiguation from '
    'migration 020 (anchor-based correction, deterministic no-context fallback).';


-- ============================================================================
-- Verification queries (run manually after applying)
-- ============================================================================
-- Runway-aware DP expansion:
-- SELECT * FROM expand_route('KJFK/31L DEEZZ6.CANDR J60 KBOS');
-- Expected: Selects DEEZZ6.CANDR variant for runway 31L (SKORR-HEERO body)
--
-- Runway-aware STAR expansion:
-- SELECT * FROM expand_route('KJFK MERIT HFD BBOTL.AALLE4 KDEN/16L');
-- Expected: Selects AALLE4 variant for runway 16L (AALLE-KIPPR body)
--
-- No runway context (fallback to existing behavior):
-- SELECT * FROM expand_route('KJFK DEEZZ6.CANDR J60 KBOS');
-- Expected: Same as before migration 023 — picks default variant
--
-- Multi-runway departure:
-- SELECT * FROM expand_route('KJFK/31L|31R DEEZZ6.CANDR J60 KBOS');
-- Expected: Prefers 31L match (first runway in pipe-delimited list)
