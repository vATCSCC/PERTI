-- ============================================================================
-- Migration 009: Fix ARTCC Code Resolution Priority
-- ============================================================================
-- Problem: resolve_waypoint() checks nav_fixes BEFORE area_centers. When no
-- geographic context is available (first waypoint in route), ARTCC codes like
-- "ZLA" resolve to same-named nav_fixes on other continents (e.g. ZLA →
-- Žilina, Slovakia at 49.2°N/18.5°E) instead of the intended area center
-- (Los Angeles Center at 34.1°N/118.1°W). This causes route geometry spikes
-- to wrong continents.
--
-- Fix: When no context is provided (p_context_lat/lon are NULL — typically
-- the first waypoint), check area_centers BEFORE nav_fixes. Area center codes
-- (ARTCC: ZLA, ZNY, ZBW; TRACON: A80, PCT, NCT) are unique facility
-- identifiers that should take priority over potentially ambiguous nav_fixes.
-- When context IS available (all subsequent waypoints), the existing proximity
-- check in nav_fixes handles disambiguation correctly.
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


-- ----------------------------------------------------------------------------
-- Verification queries (run manually)
-- ----------------------------------------------------------------------------
-- ARTCC codes without context should resolve to area_centers:
-- SELECT * FROM resolve_waypoint('ZLA');
-- Expected: source='area_center', lat≈34.1, lon≈-118.1 (Los Angeles Center)
-- NOT: lat≈49.2, lon≈18.5 (Žilina, Slovakia)
--
-- SELECT * FROM resolve_waypoint('ZNY');
-- Expected: source='area_center' (New York Center)
--
-- With context, nav_fixes proximity still works:
-- SELECT * FROM resolve_waypoint('ZLA', 34.0, -118.0);
-- Expected: should return closest ZLA match (could be nav_fix or area_center)
--
-- Route starting with ARTCC code:
-- SELECT * FROM expand_route('ZLA TRM J100 PSP BIKKR');
-- Expected: first waypoint should be near LA, not Slovakia
