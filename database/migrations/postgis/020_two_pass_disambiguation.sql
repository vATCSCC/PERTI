-- ============================================================================
-- Migration 020: Two-Pass Route Disambiguation
-- ============================================================================
-- Problem: When the first waypoint in a route has no prior context,
-- resolve_waypoint() picks an arbitrary row from nav_fixes (LIMIT 1
-- without ORDER BY). This causes wrong-continent resolutions:
--   PIKIL -> Australia (-32S, 117E) instead of North Atlantic (56N, 15W)
--   SPP   -> Spain (36N, 5W) instead of Caribbean (10N, 66W)
--   JSY   -> Oregon airport instead of Jersey VOR (English Channel)
--
-- Additionally, the forward-only (left-to-right) resolution in expand_route()
-- means early ambiguous waypoints never benefit from downstream context.
-- A route like "PIKIL 56N015W ..." resolves PIKIL wrongly because 56N015W
-- hasn't been seen yet.
--
-- Fixes:
-- 1. resolve_waypoint(): Add deterministic ORDER BY (lat DESC, lon ASC) to
--    the no-context nav_fixes fallback. Prefers northern/western hemisphere
--    where most VATSIM traffic operates.
--
-- 2. expand_route(): Buffer all waypoints during the forward pass instead
--    of streaming via RETURN NEXT. After the forward pass, run a correction
--    pass that classifies each waypoint as an "anchor" (high confidence) or
--    "ambiguous" (multiple nav_fix candidates). Ambiguous waypoints are
--    re-resolved using bidirectional anchor context (midpoint of nearest
--    left and right anchors).
-- ============================================================================


-- ============================================================================
-- 1. resolve_waypoint() — deterministic no-context fallback
-- ============================================================================
-- Base: migration 009 (area_centers-first when no context).
-- Change: no-context nav_fixes branch gets ORDER BY nf.lat DESC, nf.lon ASC.
-- ============================================================================
CREATE OR REPLACE FUNCTION public.resolve_waypoint(
    p_fix_name character varying,
    p_context_lat numeric DEFAULT NULL,
    p_context_lon numeric DEFAULT NULL
)
RETURNS TABLE(fix_id character varying, lat numeric, lon numeric, source character varying)
LANGUAGE plpgsql
STABLE
AS $function$
DECLARE
    v_coord RECORD;
BEGIN
    -- When no context is available (typically first waypoint in route), try
    -- area_centers BEFORE nav_fixes. Area center codes (ARTCC: ZLA, ZNY, ZBW;
    -- TRACON: A80, PCT) are unique facility identifiers that should not be
    -- misresolved to same-named nav_fixes on other continents.
    -- With context available, nav_fixes proximity check handles disambiguation.
    IF p_context_lat IS NULL AND p_context_lon IS NULL THEN
        RETURN QUERY
        SELECT ac.center_code::VARCHAR, ac.lat, ac.lon, 'area_center'::VARCHAR
        FROM area_centers ac
        WHERE ac.center_code = p_fix_name
        LIMIT 1;
        IF FOUND THEN RETURN; END IF;
    END IF;

    -- Try nav_fixes (most common - waypoints, VORs, NDBs)
    IF p_context_lat IS NOT NULL AND p_context_lon IS NOT NULL THEN
        -- Context-aware: pick closest match using equirectangular approximation
        RETURN QUERY
        SELECT nf.fix_name::VARCHAR, nf.lat, nf.lon, 'nav_fix'::VARCHAR
        FROM nav_fixes nf
        WHERE nf.fix_name = p_fix_name
        ORDER BY (nf.lat - p_context_lat)^2 +
                 ((nf.lon - p_context_lon) * cos(radians(p_context_lat)))^2
        LIMIT 1;
    ELSE
        -- No context: deterministic fallback preferring northern/western hemisphere
        -- (where most VATSIM traffic operates). Previously LIMIT 1 with no ORDER BY
        -- returned arbitrary results depending on physical storage order.
        RETURN QUERY
        SELECT nf.fix_name::VARCHAR, nf.lat, nf.lon, 'nav_fix'::VARCHAR
        FROM nav_fixes nf
        WHERE nf.fix_name = p_fix_name
        ORDER BY nf.lat DESC, nf.lon ASC
        LIMIT 1;
    END IF;
    IF FOUND THEN RETURN; END IF;

    -- Try airports by ICAO code (e.g., KJFK, KLAX)
    RETURN QUERY
    SELECT a.icao_id::VARCHAR, a.lat, a.lon, 'airport'::VARCHAR
    FROM airports a
    WHERE a.icao_id = p_fix_name
    LIMIT 1;
    IF FOUND THEN RETURN; END IF;

    -- Try airports by FAA code (e.g., DFW, JFK - 3-letter codes)
    RETURN QUERY
    SELECT a.icao_id::VARCHAR, a.lat, a.lon, 'airport_faa'::VARCHAR
    FROM airports a
    WHERE a.arpt_id = p_fix_name
    LIMIT 1;
    IF FOUND THEN RETURN; END IF;

    -- Try with K prefix for US airports (3-letter to ICAO conversion)
    IF LENGTH(p_fix_name) = 3 AND p_fix_name ~ '^[A-Z]{3}$' THEN
        RETURN QUERY
        SELECT a.icao_id::VARCHAR, a.lat, a.lon, 'airport_k'::VARCHAR
        FROM airports a
        WHERE a.icao_id = 'K' || p_fix_name
        LIMIT 1;
        IF FOUND THEN RETURN; END IF;
    END IF;

    -- Try area_centers (for with-context case where nav_fixes didn't match)
    RETURN QUERY
    SELECT ac.center_code::VARCHAR, ac.lat, ac.lon, 'area_center'::VARCHAR
    FROM area_centers ac
    WHERE ac.center_code = p_fix_name
    LIMIT 1;
    IF FOUND THEN RETURN; END IF;

    -- Fallback: try parsing as a coordinate token
    SELECT ct.lat, ct.lon INTO v_coord
    FROM parse_coordinate_token(p_fix_name) ct
    LIMIT 1;

    IF v_coord.lat IS NOT NULL THEN
        RETURN QUERY SELECT p_fix_name::VARCHAR, v_coord.lat, v_coord.lon, 'coordinate'::VARCHAR;
        RETURN;
    END IF;
END;
$function$;


-- ============================================================================
-- 2. expand_route() — buffered output + anchor-based correction pass
-- ============================================================================
-- Base: migration 019 (cross-variant airway fix).
-- Changes:
--   A) Buffer waypoints into arrays instead of RETURN NEXT during forward pass
--   B) After forward pass, classify anchors and re-resolve ambiguous waypoints
--      using bidirectional anchor context
--   C) Return buffered results at the end
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

    -- ======================================================================
    -- PASS 1: Forward resolution (identical logic to migration 019,
    --         but buffering results instead of RETURN NEXT)
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
                    FROM nav_fixes nf WHERE nf.fix_name = v_r_ids[v_k];
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
                    CONTINUE;  -- left-only = forward pass already handled
                END IF;

                -- Re-resolve
                SELECT rw.lat, rw.lon INTO v_new_lat, v_new_lon
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
    -- Return buffered results
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
-- Function comments
-- ============================================================================
COMMENT ON FUNCTION resolve_waypoint(VARCHAR, NUMERIC, NUMERIC) IS
    'Migration 020: Deterministic no-context fallback (ORDER BY lat DESC, lon ASC). '
    'Preserves area_centers-first priority from migration 009.';

COMMENT ON FUNCTION expand_route(TEXT) IS
    'Migration 020: Two-pass route disambiguation. Pass 1 resolves left-to-right '
    '(identical to migration 019). Pass 2 classifies anchors (airports, coordinates, '
    'area_centers, procedures, airway waypoints, unique nav_fixes) and re-resolves '
    'ambiguous waypoints using bidirectional anchor context midpoint.';


-- ============================================================================
-- Verification queries (run manually)
-- ============================================================================
-- Deterministic no-context resolution:
-- SELECT * FROM resolve_waypoint('PIKIL');
-- Expected: lat > 0 (northern hemisphere), NOT -32.x (Australia)
--
-- SELECT * FROM resolve_waypoint('SPP');
-- Expected: prefers northern/western hemisphere candidate
--
-- Two-pass correction on NAT route:
-- SELECT * FROM expand_route('PIKIL 56N015W 57N020W 58N030W CYQX');
-- Expected: PIKIL resolves near 56N/15W (North Atlantic), not Australia
--
-- US domestic route (should be unchanged):
-- SELECT * FROM expand_route('KJFK MERIT HFD PUT BOS');
-- Expected: all waypoints in northeastern US, same as before
