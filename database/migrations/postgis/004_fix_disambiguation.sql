-- ============================================================================
-- Migration 004: Context-Aware Fix Disambiguation
-- ============================================================================
-- Problem: resolve_waypoint() uses LIMIT 1 with no ordering, so fixes with
-- the same name in different regions (e.g. BLV in Illinois vs Spain, GCM in
-- Oklahoma vs Grand Cayman, MAMBI in Caribbean vs Indonesia) resolve to an
-- arbitrary row — often the wrong continent.
--
-- Fix: Add context (previous waypoint lat/lon) to resolve_waypoint so it
-- picks the geographically closest match. Update expand_route to pass
-- context, and fix expand_airway to select the correct airway variant.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. resolve_waypoint: Add context-aware disambiguation
-- ----------------------------------------------------------------------------
-- Drop old single-arg version and create new version with optional context.
-- The new signature is backwards-compatible: callers passing just a name
-- get the same behavior as before (LIMIT 1), while callers passing context
-- get nearest-match disambiguation.

DROP FUNCTION IF EXISTS resolve_waypoint(character varying);

CREATE OR REPLACE FUNCTION public.resolve_waypoint(
    p_fix_name character varying,
    p_context_lat numeric DEFAULT NULL,
    p_context_lon numeric DEFAULT NULL
)
RETURNS TABLE(fix_id character varying, lat numeric, lon numeric, source character varying)
LANGUAGE plpgsql
STABLE
AS $function$
BEGIN
    -- Try nav_fixes first (most common - waypoints, VORs, NDBs)
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
        RETURN QUERY
        SELECT nf.fix_name::VARCHAR, nf.lat, nf.lon, 'nav_fix'::VARCHAR
        FROM nav_fixes nf
        WHERE nf.fix_name = p_fix_name
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

    -- Try area_centers (ARTCC/TRACON pseudo-fixes like ZNY, ZBW)
    RETURN QUERY
    SELECT ac.center_code::VARCHAR, ac.lat, ac.lon, 'area_center'::VARCHAR
    FROM area_centers ac
    WHERE ac.center_code = p_fix_name
    LIMIT 1;
END;
$function$;


-- ----------------------------------------------------------------------------
-- 2. expand_airway: Fix variant selection
-- ----------------------------------------------------------------------------
-- The old version used LIMIT 1 on airway lookup, which could pick the wrong
-- regional variant. New version first tries to find the variant that contains
-- BOTH the from and to fixes, falling back to LIMIT 1 if none found.

CREATE OR REPLACE FUNCTION public.expand_airway(
    p_airway character varying,
    p_from_fix character varying,
    p_to_fix character varying
)
RETURNS TABLE(seq integer, fix_id character varying, lat numeric, lon numeric)
LANGUAGE plpgsql
STABLE
AS $function$
DECLARE
    v_from_seq INT;
    v_to_seq INT;
    v_airway_id INT;
BEGIN
    -- Try to find the airway variant that contains BOTH from and to fixes
    SELECT DISTINCT a.airway_id INTO v_airway_id
    FROM airways a
    JOIN airway_segments s1 ON s1.airway_id = a.airway_id
    JOIN airway_segments s2 ON s2.airway_id = a.airway_id
    WHERE a.airway_name = p_airway
      AND (s1.from_fix = p_from_fix OR s1.to_fix = p_from_fix)
      AND (s2.from_fix = p_to_fix OR s2.to_fix = p_to_fix)
    LIMIT 1;

    -- Fallback: if no variant has both fixes, use original behavior
    IF v_airway_id IS NULL THEN
        SELECT a.airway_id INTO v_airway_id
        FROM airways a
        WHERE a.airway_name = p_airway
        LIMIT 1;
    END IF;

    IF v_airway_id IS NULL THEN
        RETURN;
    END IF;

    -- Find sequence numbers for from/to fixes
    SELECT MIN(s.sequence_num) INTO v_from_seq
    FROM airway_segments s
    WHERE s.airway_id = v_airway_id
      AND (s.from_fix = p_from_fix OR s.to_fix = p_from_fix);

    SELECT MAX(s.sequence_num) INTO v_to_seq
    FROM airway_segments s
    WHERE s.airway_id = v_airway_id
      AND (s.from_fix = p_to_fix OR s.to_fix = p_to_fix);

    IF v_from_seq IS NULL OR v_to_seq IS NULL THEN
        RETURN;
    END IF;

    -- Handle forward or reverse traversal
    IF v_from_seq <= v_to_seq THEN
        -- Forward direction
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
        -- Reverse direction
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
$function$;


-- ----------------------------------------------------------------------------
-- 3. expand_route: Pass context to resolve_waypoint + validate airway coords
-- ----------------------------------------------------------------------------
-- Track previous waypoint lat/lon and pass to resolve_waypoint for
-- context-aware fix disambiguation. Also validate coordinates returned by
-- expand_airway — if a fix from airway_segments is unreasonably far from
-- the previous waypoint (>25° equirectangular ≈ 2800km), re-resolve via
-- resolve_waypoint with context. This catches wrong-region coordinates
-- stored in airway_segments (e.g. MAMBI stored as Indonesia instead of
-- Caribbean).

CREATE OR REPLACE FUNCTION public.expand_route(p_route_string text)
RETURNS TABLE(waypoint_seq integer, waypoint_id character varying, lat numeric, lon numeric, waypoint_type character varying)
LANGUAGE plpgsql
STABLE
AS $function$
DECLARE
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
                -- Catches wrong-region coords in airway_segments (e.g. MAMBI stored
                -- as Indonesia instead of Caribbean).
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


-- ----------------------------------------------------------------------------
-- 4. Verification queries (run manually to confirm fix works)
-- ----------------------------------------------------------------------------
-- Caribbean route: should stay in Caribbean, not jump to Oklahoma
-- SELECT * FROM expand_route('MKJP BOSOM GCM UR640 MAMBI UL577 ILUBA UL333 RAKAR UM219 MYDIA Y240 SHAQQ KMIA');
--
-- European route: BLV should resolve to Spain (43.30N), not Illinois (38.55N)
-- SELECT * FROM expand_route('ORTAC UL14 DIN UNB63 TERPO UNB72 ERIGA EPIXO TEPRA YURZI POPUL BLV');
--
-- Check specific fixes:
-- SELECT * FROM resolve_waypoint('BLV', 43.95, -2.84);  -- context near POPUL → should return Spain
-- SELECT * FROM resolve_waypoint('GCM', 17.86, -78.04);  -- context near BOSOM → should return Grand Cayman
-- SELECT * FROM resolve_waypoint('MAMBI', 19.29, -81.37); -- context near GCM → should return Caribbean
