-- ============================================================================
-- Migration 008: Coordinate Waypoint Parsing
-- ============================================================================
-- Problem: resolve_waypoint() cannot parse coordinate tokens commonly found
-- in playbook/CDR routes. Tokens like "4520N07350W" (ICAO compact),
-- "45/73" (NAT slash), "H4573" (NAT half-degree), and "4573N"/"45N73"
-- (ARINC 5-char) all fail to resolve, producing gaps in route geometry.
--
-- Fix: Add coordinate parsing as a fallback in resolve_waypoint(), and
-- add a pre-processing step in expand_route() to split slash-delimited
-- tokens before waypoint resolution.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. Helper: parse_coordinate_token
-- ----------------------------------------------------------------------------
-- Parses aviation coordinate formats into lat/lon. Returns NULL if not a
-- valid coordinate token.
--
-- Supported formats:
--   ICAO compact:  4520N07350W → 45.333°N, 73.833°W  (ddmmN/dddmmW)
--   NAT slash:     45/73       → 45°N, 73°W           (dd/ddd or dd/dd)
--   NAT half-deg:  H4573       → 45.5°N, 73.5°W       (Hdddd)
--   ARINC 5-char:  4573N       → 45°N, 73°W           (ddddH)
--                  45N73       → 45°N, 73°W           (ddHdd)

CREATE OR REPLACE FUNCTION public.parse_coordinate_token(p_token TEXT)
RETURNS TABLE(lat NUMERIC, lon NUMERIC)
LANGUAGE plpgsql
IMMUTABLE
AS $function$
DECLARE
    v_match TEXT[];
    v_lat NUMERIC;
    v_lon NUMERIC;
    v_lat_deg INT;
    v_lat_min INT;
    v_lon_deg INT;
    v_lon_min INT;
    v_ns CHAR(1);
    v_ew CHAR(1);
BEGIN
    -- Skip NULL/empty
    IF p_token IS NULL OR p_token = '' THEN
        RETURN;
    END IF;

    -- ── Format 1: ICAO compact (ddmmNdddmmW or ddNdddW) ──
    -- Full form: 4520N07350W (4 + N + 5 + W = 11 chars)
    -- Short form: 45N073W (2 + N + 3 + W = 7 chars)
    v_match := regexp_match(p_token, '^(\d{2})(\d{2})?([NS])(\d{3})(\d{2})?([EW])$');
    IF v_match IS NOT NULL THEN
        v_lat_deg := v_match[1]::INT;
        v_lat_min := COALESCE(v_match[2]::INT, 0);
        v_ns := v_match[3];
        v_lon_deg := v_match[4]::INT;
        v_lon_min := COALESCE(v_match[5]::INT, 0);
        v_ew := v_match[6];

        v_lat := v_lat_deg + v_lat_min / 60.0;
        v_lon := v_lon_deg + v_lon_min / 60.0;
        IF v_ns = 'S' THEN v_lat := -v_lat; END IF;
        IF v_ew = 'W' THEN v_lon := -v_lon; END IF;

        -- Sanity check
        IF v_lat BETWEEN -90 AND 90 AND v_lon BETWEEN -180 AND 180 THEN
            RETURN QUERY SELECT v_lat, v_lon;
            RETURN;
        END IF;
    END IF;

    -- ── Format 2: NAT slash (dd/ddd or dd/dd) ──
    -- Examples: 45/73, 52/020, 45/073
    v_match := regexp_match(p_token, '^(\d{2})/(\d{2,3})$');
    IF v_match IS NOT NULL THEN
        v_lat := v_match[1]::NUMERIC;
        v_lon := v_match[2]::NUMERIC;
        -- NAT convention: slash coords are always N latitude, W longitude
        v_lon := -v_lon;

        IF v_lat BETWEEN 0 AND 90 AND v_lon BETWEEN -180 AND 0 THEN
            RETURN QUERY SELECT v_lat, v_lon;
            RETURN;
        END IF;
    END IF;

    -- ── Format 3: NAT half-degree (Hdddd) ──
    -- Example: H4573 → 45.5°N, 73.5°W
    v_match := regexp_match(p_token, '^H(\d{2})(\d{2})$');
    IF v_match IS NOT NULL THEN
        v_lat := v_match[1]::NUMERIC + 0.5;
        v_lon := -(v_match[2]::NUMERIC + 0.5);

        IF v_lat BETWEEN 0 AND 90 AND v_lon BETWEEN -180 AND 0 THEN
            RETURN QUERY SELECT v_lat, v_lon;
            RETURN;
        END IF;
    END IF;

    -- ── Format 4a: ARINC 5-char trailing hemisphere (ddddH) ──
    -- Example: 4573N → 45°N, 73°W; 4573S → 45°S, 73°E
    v_match := regexp_match(p_token, '^(\d{2})(\d{2})([NSEW])$');
    IF v_match IS NOT NULL THEN
        v_lat_deg := v_match[1]::INT;
        v_lon_deg := v_match[2]::INT;
        v_ns := v_match[3];

        -- ARINC convention: hemisphere letter determines lat sign and lon sign
        IF v_ns = 'N' THEN
            v_lat := v_lat_deg; v_lon := -v_lon_deg;
        ELSIF v_ns = 'S' THEN
            v_lat := -v_lat_deg; v_lon := -v_lon_deg;
        ELSIF v_ns = 'E' THEN
            v_lat := v_lat_deg; v_lon := v_lon_deg;
        ELSIF v_ns = 'W' THEN
            v_lat := -v_lat_deg; v_lon := v_lon_deg;
        END IF;

        IF v_lat BETWEEN -90 AND 90 AND v_lon BETWEEN -180 AND 180 THEN
            RETURN QUERY SELECT v_lat, v_lon;
            RETURN;
        END IF;
    END IF;

    -- ── Format 4b: ARINC 5-char middle hemisphere (ddHdd) ──
    -- Example: 45N73 → 45°N, 73°W; 52N020 → 52°N, 20°W
    v_match := regexp_match(p_token, '^(\d{2})([NS])(\d{2,3})$');
    IF v_match IS NOT NULL THEN
        v_lat := v_match[1]::NUMERIC;
        v_ns := v_match[2];
        v_lon := v_match[3]::NUMERIC;

        IF v_ns = 'S' THEN v_lat := -v_lat; END IF;
        -- Convention: W longitude for North Atlantic route coordinates
        v_lon := -v_lon;

        IF v_lat BETWEEN -90 AND 90 AND v_lon BETWEEN -180 AND 180 THEN
            RETURN QUERY SELECT v_lat, v_lon;
            RETURN;
        END IF;
    END IF;

    -- No match — not a coordinate token
    RETURN;
END;
$function$;


-- ----------------------------------------------------------------------------
-- 2. resolve_waypoint: Add coordinate token fallback
-- ----------------------------------------------------------------------------
-- After all database lookups fail, try parsing the token as a coordinate.
-- This is the last resort before returning empty.

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


-- ----------------------------------------------------------------------------
-- 3. expand_route: Pre-process slash tokens before resolution
-- ----------------------------------------------------------------------------
-- Tokens containing "/" that aren't NAT slash coords (e.g., "KDFW/0305")
-- need to be split so the fix portion can be resolved. NAT slash coords
-- like "45/73" are left intact for resolve_waypoint's coordinate parser.

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


-- ----------------------------------------------------------------------------
-- 4. Verification queries (run manually to confirm coordinate parsing)
-- ----------------------------------------------------------------------------
-- ICAO compact format:
-- SELECT * FROM parse_coordinate_token('4520N07350W');
-- Expected: lat=45.333, lon=-73.833
--
-- NAT slash format:
-- SELECT * FROM parse_coordinate_token('45/73');
-- Expected: lat=45, lon=-73
--
-- NAT half-degree format:
-- SELECT * FROM parse_coordinate_token('H4573');
-- Expected: lat=45.5, lon=-73.5
--
-- ARINC trailing hemisphere:
-- SELECT * FROM parse_coordinate_token('4573N');
-- Expected: lat=45, lon=-73
--
-- ARINC middle hemisphere:
-- SELECT * FROM parse_coordinate_token('45N73');
-- Expected: lat=45, lon=-73
--
-- Full route with coordinate tokens:
-- SELECT * FROM expand_route('KJFK 4520N07350W 45/73 CYUL');
--
-- Route with slash-delimited tokens:
-- SELECT * FROM expand_route('KJFK MERIT/J584 COATE CYYZ');
--
-- Resolve coordinate as waypoint:
-- SELECT * FROM resolve_waypoint('4520N07350W');
-- Expected: fix_id='4520N07350W', source='coordinate'
