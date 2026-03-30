-- Migration 019: Fix cross-variant airway expansion
--
-- Problem: When an airway name (e.g., UP533) has multiple airway_id variants
-- split at FIR boundaries, and a flight plan references fixes on DIFFERENT
-- variants (e.g., KIKAS on variant 13507, CITRS on variant 6697),
-- expand_airway() returns empty and expand_route() silently skips BOTH
-- the airway token AND the exit fix.
--
-- Fixes:
-- 1. expand_airway(): Use context coordinates for proximity-based variant
--    selection when no single variant has both entry and exit fixes.
--    Also filter by is_superseded = false (was missing).
--
-- 2. expand_route(): Pass context lat/lon to expand_airway(). When airway
--    expansion returns no rows, skip only the airway token — let the exit
--    fix resolve independently via resolve_waypoint() on the next iteration.

-- ============================================================================
-- 1. Updated expand_airway()
-- ============================================================================
CREATE OR REPLACE FUNCTION expand_airway(
    p_airway VARCHAR,
    p_from_fix VARCHAR,
    p_to_fix VARCHAR,
    p_context_lat NUMERIC DEFAULT NULL,
    p_context_lon NUMERIC DEFAULT NULL
)
RETURNS TABLE(seq INT, fix_id VARCHAR, lat NUMERIC, lon NUMERIC)
LANGUAGE plpgsql STABLE
AS $$
DECLARE
    v_from_seq INT;
    v_to_seq INT;
    v_airway_id INT;
    v_candidate RECORD;
    v_both_found BOOLEAN := false;
BEGIN
    -- Find the correct airway variant by preferring the one where BOTH entry
    -- and exit fixes exist. This prevents cross-hemisphere mismatches when
    -- airways share names across regions (e.g., J107 in US vs J107 in Europe).
    v_airway_id := NULL;
    FOR v_candidate IN
        SELECT DISTINCT a.airway_id
        FROM airways a
        WHERE a.airway_name = p_airway
          AND (a.is_superseded IS NULL OR a.is_superseded = false)
    LOOP
        PERFORM 1 FROM airway_segments s
        WHERE s.airway_id = v_candidate.airway_id
          AND (s.from_fix = p_from_fix OR s.to_fix = p_from_fix);
        IF FOUND THEN
            PERFORM 1 FROM airway_segments s
            WHERE s.airway_id = v_candidate.airway_id
              AND (s.from_fix = p_to_fix OR s.to_fix = p_to_fix);
            IF FOUND THEN
                v_airway_id := v_candidate.airway_id;
                v_both_found := true;
                EXIT;
            END IF;
        END IF;

        -- Fallback: remember first variant that has the entry fix
        IF v_airway_id IS NULL THEN
            PERFORM 1 FROM airway_segments s
            WHERE s.airway_id = v_candidate.airway_id
              AND (s.from_fix = p_from_fix OR s.to_fix = p_from_fix);
            IF FOUND THEN
                v_airway_id := v_candidate.airway_id;
            END IF;
        END IF;
    END LOOP;

    -- When only a fallback match was found (entry fix only, not exit) and
    -- context coordinates are available, override with proximity-based
    -- variant selection. This picks the variant where the entry fix is
    -- geographically closest to the previous waypoint in the route.
    IF v_airway_id IS NOT NULL AND NOT v_both_found AND p_context_lat IS NOT NULL THEN
        SELECT s.airway_id INTO v_airway_id
        FROM airway_segments s
        JOIN airways a ON s.airway_id = a.airway_id
        WHERE a.airway_name = p_airway
          AND (a.is_superseded IS NULL OR a.is_superseded = false)
          AND (s.from_fix = p_from_fix OR s.to_fix = p_from_fix)
        ORDER BY ST_Distance(
            ST_SetSRID(ST_MakePoint(
                CASE WHEN s.from_fix = p_from_fix THEN s.from_lon ELSE s.to_lon END,
                CASE WHEN s.from_fix = p_from_fix THEN s.from_lat ELSE s.to_lat END
            ), 4326)::geography,
            ST_SetSRID(ST_MakePoint(p_context_lon, p_context_lat), 4326)::geography
        )
        LIMIT 1;
    END IF;

    IF v_airway_id IS NULL THEN
        RETURN;
    END IF;

    -- Find sequence numbers for from/to fixes.
    -- When context coordinates are available, use proximity to pick the correct
    -- segment when a fix name appears multiple times on the same airway.
    IF p_context_lat IS NOT NULL THEN
        SELECT s.sequence_num INTO v_from_seq
        FROM airway_segments s
        WHERE s.airway_id = v_airway_id
          AND (s.from_fix = p_from_fix OR s.to_fix = p_from_fix)
        ORDER BY ST_Distance(
            ST_SetSRID(ST_MakePoint(
                CASE WHEN s.from_fix = p_from_fix THEN s.from_lon ELSE s.to_lon END,
                CASE WHEN s.from_fix = p_from_fix THEN s.from_lat ELSE s.to_lat END
            ), 4326)::geography,
            ST_SetSRID(ST_MakePoint(p_context_lon, p_context_lat), 4326)::geography
        )
        LIMIT 1;
    ELSE
        SELECT MIN(s.sequence_num) INTO v_from_seq
        FROM airway_segments s
        WHERE s.airway_id = v_airway_id
          AND (s.from_fix = p_from_fix OR s.to_fix = p_from_fix);
    END IF;

    SELECT MAX(s.sequence_num) INTO v_to_seq
    FROM airway_segments s
    WHERE s.airway_id = v_airway_id
      AND (s.from_fix = p_to_fix OR s.to_fix = p_to_fix);

    IF v_from_seq IS NULL OR v_to_seq IS NULL THEN
        RETURN;
    END IF;

    -- Distance sanity check: if context is available, verify the matched from_fix
    -- is within 2500km (~1350nm) of the previous waypoint.
    IF p_context_lat IS NOT NULL THEN
        PERFORM 1
        FROM airway_segments s
        WHERE s.airway_id = v_airway_id AND s.sequence_num = v_from_seq
          AND ST_Distance(
            ST_SetSRID(ST_MakePoint(
                CASE WHEN s.from_fix = p_from_fix THEN s.from_lon ELSE s.to_lon END,
                CASE WHEN s.from_fix = p_from_fix THEN s.from_lat ELSE s.to_lat END
            ), 4326)::geography,
            ST_SetSRID(ST_MakePoint(p_context_lon, p_context_lat), 4326)::geography
          ) < 2500000;
        IF NOT FOUND THEN
            RETURN;
        END IF;
    END IF;

    -- Spatial coherence check: reject airway range if ANY segment has an
    -- unreasonable intra-segment jump (>2000km).
    PERFORM 1
    FROM airway_segments s
    WHERE s.airway_id = v_airway_id
      AND s.sequence_num >= LEAST(v_from_seq, v_to_seq)
      AND s.sequence_num <= GREATEST(v_from_seq, v_to_seq)
      AND ST_Distance(
        ST_SetSRID(ST_MakePoint(s.from_lon, s.from_lat), 4326)::geography,
        ST_SetSRID(ST_MakePoint(s.to_lon, s.to_lat), 4326)::geography
      ) > 2000000;

    IF FOUND THEN
        RETURN;
    END IF;

    -- Inter-segment gap check: adjacent segments must connect (to_point ~ next from_point).
    PERFORM 1
    FROM airway_segments a1
    JOIN airway_segments a2
      ON a2.airway_id = a1.airway_id AND a2.sequence_num = a1.sequence_num + 1
    WHERE a1.airway_id = v_airway_id
      AND a1.sequence_num >= LEAST(v_from_seq, v_to_seq)
      AND a1.sequence_num < GREATEST(v_from_seq, v_to_seq)
      AND ST_Distance(
        ST_SetSRID(ST_MakePoint(a1.to_lon, a1.to_lat), 4326)::geography,
        ST_SetSRID(ST_MakePoint(a2.from_lon, a2.from_lat), 4326)::geography
      ) > 500000;

    IF FOUND THEN
        RETURN;
    END IF;

    -- Handle forward or reverse traversal
    IF v_from_seq <= v_to_seq THEN
        RETURN QUERY
        WITH ordered_fixes AS (
            SELECT 1 AS seq, s.from_fix AS fix_id, s.from_lat AS lat, s.from_lon AS lon
            FROM airway_segments s
            WHERE s.airway_id = v_airway_id AND s.sequence_num = v_from_seq
            UNION ALL
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
        RETURN QUERY
        WITH ordered_fixes AS (
            SELECT 1 AS seq, s.to_fix AS fix_id, s.to_lat AS lat, s.to_lon AS lon
            FROM airway_segments s
            WHERE s.airway_id = v_airway_id AND s.sequence_num = v_from_seq
            UNION ALL
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
$$;

-- ============================================================================
-- 2. Updated expand_route() — pass context coords + graceful airway fallback
-- ============================================================================
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
    v_airway_expanded BOOLEAN;  -- NEW: track if airway expansion produced rows
    -- Procedure expansion variables
    v_dot_left TEXT;
    v_dot_right TEXT;
    v_proc_route TEXT;
    v_proc_parts TEXT[];
    v_proc_idx INT;
    v_proc_part TEXT;
    v_trunc_name TEXT;
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
                v_proc_route := NULL;

                v_trunc_name := NULL;
                IF UPPER(v_part) ~ '^[A-Z]{5,}\d' THEN
                    v_trunc_name := LEFT(regexp_replace(UPPER(v_part), '\d.*$', ''), 4)
                                 || regexp_replace(UPPER(v_part), '^[A-Z]+', '');
                END IF;

                v_next_fix := NULL;
                IF v_idx < array_length(v_parts, 1) THEN
                    v_next_fix := UPPER(v_parts[v_idx + 1]);
                END IF;

                -- Try DP lookup
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

                -- Try STAR lookup
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
            END IF;

            v_idx := v_idx + 1;
        END IF;
    END LOOP;
END;
$$;
