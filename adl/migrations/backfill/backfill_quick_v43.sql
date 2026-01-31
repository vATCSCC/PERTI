-- ============================================================================
-- Quick Backfill: Extract dfix/afix/runways WITHOUT GIS resolution
--
-- This is 50-100x faster than sp_ParseRoute because it:
--   - Uses set-based operations (no cursor)
--   - Skips coordinate lookups entirely
--   - Only extracts the text fields needed for demand filtering
--
-- Run this to quickly populate dfix/afix/runways, then optionally run
-- the full sp_ParseRouteBatch later for waypoints_json if needed.
-- ============================================================================

SET NOCOUNT ON;
PRINT '=== Quick Backfill v4.3 Fields (No GIS) ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);

-- ============================================================================
-- Step 1: Extract runway from route (first /##L pattern = dep, last = arr)
-- ============================================================================

PRINT '';
PRINT 'Step 1: Extracting runways from routes...';

-- Departure runway (first occurrence of /##[LRC] pattern)
UPDATE fp SET
    dep_runway = SUBSTRING(fp_route, PATINDEX('%/[0-9][0-9][LRC]%', fp_route) + 1, 3),
    dep_runway_source = 'ROUTE'
FROM dbo.adl_flight_plan fp
WHERE fp_route LIKE '%/[0-9][0-9][LRC]%'
  AND dep_runway IS NULL;

PRINT '  Departure runways extracted: ' + CAST(@@ROWCOUNT AS VARCHAR);

-- Arrival runway - harder to get "last" in set-based, use REVERSE trick
UPDATE fp SET
    arr_runway = REVERSE(SUBSTRING(REVERSE(fp_route),
        PATINDEX('%[CLR][0-9][0-9]/%', REVERSE(fp_route)), 3)),
    arr_runway_source = 'ROUTE'
FROM dbo.adl_flight_plan fp
WHERE fp_route LIKE '%/[0-9][0-9][LRC]%'
  AND arr_runway IS NULL
  AND CHARINDEX('/', fp_route, PATINDEX('%/[0-9][0-9][LRC]%', fp_route) + 1) > 0;  -- Has second runway

PRINT '  Arrival runways extracted: ' + CAST(@@ROWCOUNT AS VARCHAR);

-- ============================================================================
-- Step 2: Extract SID name (first token matching pattern)
-- ============================================================================

PRINT '';
PRINT 'Step 2: Extracting SID names...';

;WITH TokenizedRoutes AS (
    SELECT
        fp.flight_uid,
        fp.fp_route,
        -- Get first space-delimited token
        CASE
            WHEN CHARINDEX(' ', fp.fp_route) > 0
            THEN LEFT(fp.fp_route, CHARINDEX(' ', fp.fp_route) - 1)
            ELSE fp.fp_route
        END AS first_token
    FROM dbo.adl_flight_plan fp
    WHERE fp.dp_name IS NULL
      AND fp.fp_route IS NOT NULL
      AND LEN(fp.fp_route) > 5
)
UPDATE fp SET
    dp_name = t.first_token
FROM dbo.adl_flight_plan fp
INNER JOIN TokenizedRoutes t ON fp.flight_uid = t.flight_uid
WHERE LEN(t.first_token) BETWEEN 4 AND 8
  AND (
      -- SID with dot: RNLDI1.RNLDI
      t.first_token LIKE '%[0-9].%'
      -- SID without dot ending in digit: KRSTA4
      OR (t.first_token LIKE '[A-Z][A-Z][A-Z]%[0-9]' AND t.first_token NOT LIKE '%[0-9][0-9]%')
      -- SID without dot ending in digit+letter: ABTAN2W
      OR (t.first_token LIKE '[A-Z][A-Z][A-Z]%[0-9][A-Z]' AND t.first_token NOT LIKE '%[0-9][0-9]%')
  );

PRINT '  SID names extracted: ' + CAST(@@ROWCOUNT AS VARCHAR);

-- ============================================================================
-- Step 3: Extract STAR name (last token before destination matching pattern)
-- ============================================================================

PRINT '';
PRINT 'Step 3: Extracting STAR names...';

;WITH TokenizedRoutes AS (
    SELECT
        fp.flight_uid,
        fp.fp_route,
        -- Get last space-delimited token (reverse, find first space, reverse back)
        REVERSE(
            CASE
                WHEN CHARINDEX(' ', REVERSE(LTRIM(RTRIM(fp.fp_route)))) > 0
                THEN LEFT(REVERSE(LTRIM(RTRIM(fp.fp_route))), CHARINDEX(' ', REVERSE(LTRIM(RTRIM(fp.fp_route)))) - 1)
                ELSE REVERSE(LTRIM(RTRIM(fp.fp_route)))
            END
        ) AS last_token
    FROM dbo.adl_flight_plan fp
    WHERE fp.star_name IS NULL
      AND fp.fp_route IS NOT NULL
      AND LEN(fp.fp_route) > 5
)
UPDATE fp SET
    star_name = t.last_token
FROM dbo.adl_flight_plan fp
INNER JOIN TokenizedRoutes t ON fp.flight_uid = t.flight_uid
WHERE LEN(t.last_token) BETWEEN 4 AND 8
  AND (
      -- STAR with dot: LENDY6.LENDY
      t.last_token LIKE '%.%[0-9]'
      -- STAR without dot ending in digit: PARCH4
      OR (t.last_token LIKE '[A-Z][A-Z][A-Z]%[0-9]' AND t.last_token NOT LIKE '%[0-9][0-9]%')
      -- STAR without dot ending in digit+letter: DUMEP1T
      OR (t.last_token LIKE '[A-Z][A-Z][A-Z]%[0-9][A-Z]' AND t.last_token NOT LIKE '%[0-9][0-9]%')
  );

PRINT '  STAR names extracted: ' + CAST(@@ROWCOUNT AS VARCHAR);

-- ============================================================================
-- Step 4: Extract dfix (first 2-5 char fix after SID or start)
-- ============================================================================

PRINT '';
PRINT 'Step 4: Extracting departure fixes (dfix)...';

;WITH RouteTokens AS (
    SELECT
        fp.flight_uid,
        -- Remove SID from start if present, then get first fix-like token
        CASE
            WHEN fp.dp_name IS NOT NULL AND fp.fp_route LIKE fp.dp_name + ' %'
            THEN LTRIM(SUBSTRING(fp.fp_route, LEN(fp.dp_name) + 1, LEN(fp.fp_route)))
            ELSE fp.fp_route
        END AS route_after_sid
    FROM dbo.adl_flight_plan fp
    WHERE fp.dfix IS NULL
      AND fp.fp_route IS NOT NULL
),
FirstFix AS (
    SELECT
        flight_uid,
        CASE
            WHEN CHARINDEX(' ', route_after_sid) > 0
            THEN LEFT(route_after_sid, CHARINDEX(' ', route_after_sid) - 1)
            ELSE route_after_sid
        END AS candidate_fix
    FROM RouteTokens
)
UPDATE fp SET
    dfix = ff.candidate_fix
FROM dbo.adl_flight_plan fp
INNER JOIN FirstFix ff ON fp.flight_uid = ff.flight_uid
WHERE LEN(ff.candidate_fix) BETWEEN 2 AND 5
  AND ff.candidate_fix LIKE '[A-Z][A-Z]%'
  AND ff.candidate_fix NOT LIKE '%[0-9][0-9]%'  -- Not a SID/STAR
  AND ff.candidate_fix NOT LIKE 'DCT'
  AND ff.candidate_fix NOT LIKE 'K[A-Z][A-Z][A-Z]';  -- Not an airport

PRINT '  Departure fixes extracted: ' + CAST(@@ROWCOUNT AS VARCHAR);

-- ============================================================================
-- Step 5: Extract afix (last 2-5 char fix before STAR or end)
-- ============================================================================

PRINT '';
PRINT 'Step 5: Extracting arrival fixes (afix)...';

;WITH RouteTokens AS (
    SELECT
        fp.flight_uid,
        -- Remove STAR from end if present
        CASE
            WHEN fp.star_name IS NOT NULL AND fp.fp_route LIKE '% ' + fp.star_name
            THEN RTRIM(LEFT(fp.fp_route, LEN(fp.fp_route) - LEN(fp.star_name) - 1))
            ELSE fp.fp_route
        END AS route_before_star
    FROM dbo.adl_flight_plan fp
    WHERE fp.afix IS NULL
      AND fp.fp_route IS NOT NULL
),
LastFix AS (
    SELECT
        flight_uid,
        REVERSE(
            CASE
                WHEN CHARINDEX(' ', REVERSE(LTRIM(RTRIM(route_before_star)))) > 0
                THEN LEFT(REVERSE(LTRIM(RTRIM(route_before_star))), CHARINDEX(' ', REVERSE(LTRIM(RTRIM(route_before_star)))) - 1)
                ELSE REVERSE(LTRIM(RTRIM(route_before_star)))
            END
        ) AS candidate_fix
    FROM RouteTokens
)
UPDATE fp SET
    afix = lf.candidate_fix
FROM dbo.adl_flight_plan fp
INNER JOIN LastFix lf ON fp.flight_uid = lf.flight_uid
WHERE LEN(lf.candidate_fix) BETWEEN 2 AND 5
  AND lf.candidate_fix LIKE '[A-Z][A-Z]%'
  AND lf.candidate_fix NOT LIKE '%[0-9][0-9]%'  -- Not a SID/STAR
  AND lf.candidate_fix NOT LIKE 'DCT'
  AND lf.candidate_fix NOT LIKE 'K[A-Z][A-Z][A-Z]';  -- Not an airport

PRINT '  Arrival fixes extracted: ' + CAST(@@ROWCOUNT AS VARCHAR);

-- ============================================================================
-- Step 6: Clear the parse queue (these flights are now handled)
-- ============================================================================

PRINT '';
PRINT 'Step 6: Clearing parse queue...';

DELETE FROM dbo.adl_parse_queue
WHERE status = 'PENDING';

PRINT '  Cleared pending queue entries: ' + CAST(@@ROWCOUNT AS VARCHAR);

-- ============================================================================
-- Summary
-- ============================================================================

PRINT '';
PRINT '=== Summary ===';

SELECT
    'Flights with dfix' AS metric,
    COUNT(*) AS count
FROM dbo.adl_flight_plan WHERE dfix IS NOT NULL
UNION ALL
SELECT 'Flights with afix', COUNT(*) FROM dbo.adl_flight_plan WHERE afix IS NOT NULL
UNION ALL
SELECT 'Flights with dep_runway', COUNT(*) FROM dbo.adl_flight_plan WHERE dep_runway IS NOT NULL
UNION ALL
SELECT 'Flights with arr_runway', COUNT(*) FROM dbo.adl_flight_plan WHERE arr_runway IS NOT NULL
UNION ALL
SELECT 'Flights with dp_name (SID)', COUNT(*) FROM dbo.adl_flight_plan WHERE dp_name IS NOT NULL
UNION ALL
SELECT 'Flights with star_name', COUNT(*) FROM dbo.adl_flight_plan WHERE star_name IS NOT NULL;

PRINT '';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '';
PRINT 'NOTE: This quick backfill does NOT populate waypoints_json with coordinates.';
PRINT 'If you need full GIS resolution, run sp_ParseRouteBatch separately.';
GO
