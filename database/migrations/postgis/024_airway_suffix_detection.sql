-- Migration 024: Fix airway detection for letter-suffix airways
--
-- Problem: The airway detection regex in expand_route() does not match airways
-- with letter suffixes (e.g., N693A, J804R, BR10L, N622E, A576S). These are
-- common in XP12/Navigraph data and some NASR airways (BR-series, J804R).
-- 818 airways in awys.csv have letter suffixes and are silently dropped during
-- route expansion, falling through to waypoint resolution where they fail.
--
-- Fix: Two-tier detection (matching scripts/postgis/004 pattern):
--   1. Fast-path regex: ^[JQVT]\d+[A-Z]?$ — unambiguous, no DB lookup needed
--   2. Broad regex: ^[A-Z]{1,2}\d{1,4}[A-Z]?$ — verified against airways table
--      to prevent false positives (e.g., AR22 could be airway or fix)
--
-- Impact: 818 airways now correctly detected and expanded.
-- No false positives: broad pattern always confirmed via DB lookup.
--
-- Date: 2026-04-21
-- Depends on: migration 023 (runway-aware expand_route)

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
    -- ======================================================================
    FOR v_i IN 1..array_length(v_raw_parts, 1) LOOP
        v_part := v_raw_parts[v_i];
        IF v_part IS NULL OR v_part = '' THEN
            CONTINUE;
        END IF;

        IF position('/' IN v_part) > 0 AND v_part !~ '^\d{2}/\d{2,3}$' THEN
            v_rwy_apt := split_part(v_part, '/', 1);
            v_rwy_part := split_part(v_part, '/', 2);

            IF v_rwy_apt ~ '^[A-Z]{3,4}$' THEN
                v_rwy_list := string_to_array(v_rwy_part, '|');
                IF v_rwy_list[1] ~ '^\d{2}[LRCB]?$' THEN
                    FOREACH v_rwy_str IN ARRAY v_rwy_list LOOP
                        IF v_rwy_str ~ '^\d{2}[LRCB]?$' THEN
                            v_rwy_count := v_rwy_count + 1;
                            v_rwy_airports := array_append(v_rwy_airports, UPPER(v_rwy_apt));
                            v_rwy_runways := array_append(v_rwy_runways, UPPER(v_rwy_str));
                        END IF;
                    END LOOP;

                    IF v_dep_airport IS NULL THEN
                        v_dep_airport := UPPER(v_rwy_apt);
                        v_dep_runways := UPPER(v_rwy_part);
                    ELSIF UPPER(v_rwy_apt) != v_dep_airport THEN
                        v_arr_airport := UPPER(v_rwy_apt);
                        v_arr_runways := UPPER(v_rwy_part);
                    END IF;
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

        IF v_part ~ '^\d{2}/\d{2,3}$' THEN
            v_processed := array_append(v_processed, v_part);
        ELSIF position('/' IN v_part) > 0 THEN
            v_slash_parts := string_to_array(v_part, '/');
            FOR v_j IN 1..array_length(v_slash_parts, 1) LOOP
                IF v_slash_parts[v_j] IS NOT NULL AND v_slash_parts[v_j] != '' THEN
                    IF v_j > 1 AND v_slash_parts[v_j] ~ '^\d{2}[LRCB]?(\|\d{2}[LRCB]?)*$' THEN
                        NULL;
                    ELSE
                        v_processed := array_append(v_processed, v_slash_parts[v_j]);
                    END IF;
                END IF;
            END LOOP;
        ELSE
            v_processed := array_append(v_processed, v_part);
        END IF;
    END LOOP;

    v_parts := v_processed;

    -- ======================================================================
    -- PASS 1: Forward resolution
    -- ======================================================================
    v_idx := 1;
    WHILE v_idx <= array_length(v_parts, 1) LOOP
        v_part := v_parts[v_idx];

        IF v_part IS NULL OR v_part = '' THEN
            v_idx := v_idx + 1;
            CONTINUE;
        END IF;

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
              CASE WHEN np.transition_name IS NOT NULL
                   AND (np.transition_name LIKE UPPER(v_dot_right) || '%'
                        OR np.transition_name LIKE UPPER(v_dot_left) || '%')
                   THEN 0 ELSE 1 END,
              CASE WHEN np.full_route LIKE UPPER(v_dot_left) || ' %' THEN 0 ELSE 1 END,
              CASE WHEN np.full_route LIKE '% ' || UPPER(v_dot_right) THEN 0 ELSE 1 END,
              CASE WHEN np.runway_group IS NOT NULL
                   AND v_dep_airport IS NOT NULL AND v_dep_runways IS NOT NULL
                   AND np.runway_group LIKE '%' || v_dep_airport || '%'
                   AND np.runway_group LIKE '%' || split_part(v_dep_runways, '|', 1) || '%'
                   THEN 0
                   WHEN np.runway_group IS NOT NULL
                   AND v_arr_airport IS NOT NULL AND v_arr_runways IS NOT NULL
                   AND np.runway_group LIKE '%' || v_arr_airport || '%'
                   AND np.runway_group LIKE '%' || split_part(v_arr_runways, '|', 1) || '%'
                   THEN 0
                   ELSE 1 END,
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

        -- =================================================================
        -- Airway detection: two-tier approach (migration 024)
        --
        -- Tier 1 (fast-path): Known unambiguous prefixes + optional suffix
        --   J/Q/V/T routes are never waypoint names, safe without DB check
        --
        -- Tier 2 (broad + DB verify): 1-2 letter prefix + 1-4 digits + optional suffix
        --   Matches N693A, BR10L, A576S, etc. Verified against airways table
        --   to avoid false positives (e.g., a fix named like an airway)
        -- =================================================================
        v_is_airway := v_part ~ '^[JQVT]\d+[A-Z]?$';

        IF NOT v_is_airway AND v_part ~ '^[A-Z]{1,2}\d{1,4}[A-Z]?$' THEN
            SELECT EXISTS(
                SELECT 1 FROM airways
                WHERE airway_name = v_part
                  AND (is_superseded IS NULL OR is_superseded = false)
            ) INTO v_is_airway;
        END IF;

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
                v_idx := v_idx + 2;
            ELSE
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
                    IF v_next_fix LIKE '%.%' THEN
                        v_next_fix := split_part(v_next_fix, '.', 1);
                    END IF;
                END IF;

                -- Try DP lookup: exact transition match first
                IF v_next_fix IS NOT NULL THEN
                    SELECT np.full_route INTO v_proc_route
                    FROM nav_procedures np
                    WHERE (np.computer_code = UPPER(v_part) || '.' || v_next_fix
                           OR (v_trunc_name IS NOT NULL
                               AND np.computer_code = v_trunc_name || '.' || v_next_fix)
                           OR (np.computer_code LIKE UPPER(v_part) || '.%'
                               AND np.transition_name LIKE v_next_fix || '%')
                           OR (v_trunc_name IS NOT NULL
                               AND np.computer_code LIKE v_trunc_name || '.%'
                               AND np.transition_name LIKE v_next_fix || '%'))
                      AND np.procedure_type IN ('DP', 'SID')
                      AND np.source IN ('NASR', 'nasr', 'cifp_base', 'synthetic_base', 'CIFP')
                      AND np.is_active = true
                      AND (np.is_superseded IS NULL OR np.is_superseded = false)
                      AND np.full_route IS NOT NULL
                      AND np.full_route != ''
                    ORDER BY
                      CASE WHEN np.source IN ('NASR', 'nasr') THEN 0 ELSE 1 END,
                      CASE WHEN v_next_fix IS NOT NULL
                           AND np.transition_name IS NOT NULL
                           AND np.transition_name LIKE v_next_fix || '%'
                           THEN 0 ELSE 1 END,
                      CASE WHEN np.runway_group IS NOT NULL
                           AND v_dep_airport IS NOT NULL AND v_dep_runways IS NOT NULL
                           AND np.runway_group LIKE '%' || v_dep_airport || '%'
                           AND np.runway_group LIKE '%' || split_part(v_dep_runways, '|', 1) || '%'
                           THEN 0 ELSE 1 END,
                      length(np.full_route) ASC
                    LIMIT 1;
                END IF;

                -- Fallback: broader DP match
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
                      CASE WHEN np.computer_code ~ '\.(RW\d|RW$)' THEN 1 ELSE 0 END,
                      CASE WHEN v_next_fix IS NOT NULL
                           AND np.transition_name IS NOT NULL
                           AND np.transition_name LIKE v_next_fix || '%'
                           THEN 0 ELSE 1 END,
                      CASE WHEN np.runway_group IS NOT NULL
                           AND v_dep_airport IS NOT NULL AND v_dep_runways IS NOT NULL
                           AND np.runway_group LIKE '%' || v_dep_airport || '%'
                           AND np.runway_group LIKE '%' || split_part(v_dep_runways, '|', 1) || '%'
                           THEN 0 ELSE 1 END,
                      CASE WHEN v_next_fix IS NOT NULL
                           AND (np.full_route LIKE '% ' || v_next_fix
                                OR np.full_route LIKE '% ' || v_next_fix || ' %')
                           THEN 0 ELSE 1 END,
                      length(np.full_route) ASC
                    LIMIT 1;
                END IF;

                -- Try STAR lookup: exact transition match first
                IF v_proc_route IS NULL AND v_prev_fix IS NOT NULL THEN
                    SELECT np.full_route INTO v_proc_route
                    FROM nav_procedures np
                    WHERE (np.computer_code = UPPER(v_prev_fix) || '.' || UPPER(v_part)
                           OR (v_trunc_name IS NOT NULL
                               AND np.computer_code = UPPER(v_prev_fix) || '.' || v_trunc_name)
                           OR (np.computer_code LIKE '%.' || UPPER(v_part)
                               AND np.transition_name LIKE UPPER(v_prev_fix) || '%')
                           OR (v_trunc_name IS NOT NULL
                               AND np.computer_code LIKE '%.' || v_trunc_name
                               AND np.transition_name LIKE UPPER(v_prev_fix) || '%'))
                      AND np.procedure_type = 'STAR'
                      AND np.source IN ('NASR', 'nasr', 'cifp_base', 'synthetic_base', 'CIFP')
                      AND np.is_active = true
                      AND (np.is_superseded IS NULL OR np.is_superseded = false)
                      AND np.full_route IS NOT NULL
                      AND np.full_route != ''
                    ORDER BY
                      CASE WHEN np.source IN ('NASR', 'nasr') THEN 0 ELSE 1 END,
                      CASE WHEN v_prev_fix IS NOT NULL
                           AND np.transition_name IS NOT NULL
                           AND np.transition_name LIKE UPPER(v_prev_fix) || '%'
                           THEN 0 ELSE 1 END,
                      CASE WHEN np.runway_group IS NOT NULL
                           AND v_arr_airport IS NOT NULL AND v_arr_runways IS NOT NULL
                           AND np.runway_group LIKE '%' || v_arr_airport || '%'
                           AND np.runway_group LIKE '%' || split_part(v_arr_runways, '|', 1) || '%'
                           THEN 0 ELSE 1 END,
                      length(np.full_route) ASC
                    LIMIT 1;
                END IF;

                -- Fallback: broader STAR match
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
                      CASE WHEN np.computer_code ~ '^RW\d' THEN 1 ELSE 0 END,
                      CASE WHEN v_prev_fix IS NOT NULL
                           AND np.transition_name IS NOT NULL
                           AND np.transition_name LIKE UPPER(v_prev_fix) || '%'
                           THEN 0 ELSE 1 END,
                      CASE WHEN np.runway_group IS NOT NULL
                           AND v_arr_airport IS NOT NULL AND v_arr_runways IS NOT NULL
                           AND np.runway_group LIKE '%' || v_arr_airport || '%'
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
                      CASE WHEN np.transition_name IS NOT NULL
                           AND ((v_next_fix IS NOT NULL AND np.transition_name LIKE v_next_fix || '%')
                                OR (v_prev_fix IS NOT NULL AND np.transition_name LIKE UPPER(v_prev_fix) || '%'))
                           THEN 0 ELSE 1 END,
                      CASE WHEN np.runway_group IS NOT NULL
                           AND v_dep_airport IS NOT NULL AND v_dep_runways IS NOT NULL
                           AND np.procedure_type IN ('DP', 'SID')
                           AND np.runway_group LIKE '%' || v_dep_airport || '%'
                           AND np.runway_group LIKE '%' || split_part(v_dep_runways, '|', 1) || '%'
                           THEN 0
                           WHEN np.runway_group IS NOT NULL
                           AND v_arr_airport IS NOT NULL AND v_arr_runways IS NOT NULL
                           AND np.procedure_type = 'STAR'
                           AND np.runway_group LIKE '%' || v_arr_airport || '%'
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

            FOR v_k IN 1..v_r_count LOOP
                IF v_is_anchor[v_k] THEN CONTINUE; END IF;

                v_left_lat := NULL; v_left_lon := NULL;
                FOR v_j IN REVERSE (v_k - 1)..1 LOOP
                    IF v_is_anchor[v_j] THEN
                        v_left_lat := v_r_lats[v_j];
                        v_left_lon := v_r_lons[v_j];
                        EXIT;
                    END IF;
                END LOOP;

                v_right_lat := NULL; v_right_lon := NULL;
                FOR v_j IN (v_k + 1)..v_r_count LOOP
                    IF v_is_anchor[v_j] THEN
                        v_right_lat := v_r_lats[v_j];
                        v_right_lon := v_r_lons[v_j];
                        EXIT;
                    END IF;
                END LOOP;

                IF v_left_lat IS NOT NULL AND v_right_lat IS NOT NULL THEN
                    v_ctx_lat := (v_left_lat + v_right_lat) / 2.0;
                    v_ctx_lon := (v_left_lon + v_right_lon) / 2.0;
                ELSIF v_right_lat IS NOT NULL THEN
                    v_ctx_lat := v_right_lat;
                    v_ctx_lon := v_right_lon;
                ELSE
                    CONTINUE;
                END IF;

                SELECT rw.lat, rw.lon
                INTO v_new_lat, v_new_lon
                FROM resolve_waypoint(v_r_ids[v_k], v_ctx_lat, v_ctx_lon) rw
                LIMIT 1;

                IF v_new_lat IS NOT NULL THEN
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
    'Migration 024: Fix airway detection for letter-suffix airways (N693A, J804R, '
    'BR10L, etc.). Two-tier detection: fast-path regex for J/Q/V/T routes, then '
    'broad regex verified against airways table for other patterns. Preserves all '
    'runway-aware procedure expansion from migration 023 and two-pass '
    'disambiguation from migration 020.';


-- ============================================================================
-- Verification queries (run manually after applying)
-- ============================================================================
-- Letter-suffix airway (the bug this fixes):
-- SELECT * FROM expand_route('TAFFY N693A NEEKO');
-- Expected: TAFFY + NEEKO (N693A recognized as airway, 2-fix airway has no intermediates)
--
-- Another suffix airway:
-- SELECT * FROM expand_route('NEEKO N622E TAFFY');
-- Expected: NEEKO + TAFFY (N622E recognized as airway)
--
-- Standard airways (regression check):
-- SELECT * FROM expand_route('KJFK DEEZZ6.CANDR J60 KBOS');
-- Expected: Same as migration 023 (J60 still works)
--
-- Verify airway detection does NOT match speed restrictions:
-- SELECT 'N0450' ~ '^[JQVT]\d+[A-Z]?$' AS fast_match,
--        'N0450' ~ '^[A-Z]{1,2}\d{1,4}[A-Z]?$' AS broad_match;
-- Expected: fast=false, broad=true (but DB lookup will reject — no airway named N0450)
