-- ============================================================================
-- ADL Migration 013: Backfill Flight Phases
--
-- Fixes flights with incorrect or missing phase values by recalculating
-- based on position data.
--
-- Phase values: prefile, taxiing, departed, enroute, descending, arrived, disconnected, unknown
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== ADL Migration 013: Backfill Flight Phases ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- ============================================================================
-- Step 1: Diagnostic - Show current phase distribution
-- ============================================================================
PRINT '';
PRINT '--- Current Phase Distribution ---';

SELECT
    phase,
    is_active,
    COUNT(*) AS flight_count
FROM dbo.adl_flight_core
GROUP BY phase, is_active
ORDER BY is_active DESC, flight_count DESC;
GO

-- ============================================================================
-- Step 2: Mark stale active flights as disconnected or arrived
-- Flights not seen in 5+ minutes should be marked inactive
-- If they were close to destination (pct_complete > 85, groundspeed < 50) -> arrived
-- Otherwise -> disconnected (they disconnected mid-flight)
-- ============================================================================
PRINT '';
PRINT '--- Step 2: Marking stale flights as disconnected/arrived ---';

DECLARE @stale_arrived INT = 0;
DECLARE @stale_disconnected INT = 0;

-- Mark as arrived if they were close to destination
UPDATE c
SET
    c.is_active = 0,
    c.phase = 'arrived'
FROM dbo.adl_flight_core c
LEFT JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
WHERE c.is_active = 1
  AND c.last_seen_utc < DATEADD(MINUTE, -5, SYSUTCDATETIME())
  AND p.groundspeed_kts < 50
  AND ISNULL(p.pct_complete, 0) > 85;

SET @stale_arrived = @@ROWCOUNT;

-- Mark remaining stale flights as disconnected
UPDATE c
SET
    c.is_active = 0,
    c.phase = 'disconnected'
FROM dbo.adl_flight_core c
WHERE c.is_active = 1
  AND c.last_seen_utc < DATEADD(MINUTE, -5, SYSUTCDATETIME());

SET @stale_disconnected = @@ROWCOUNT;

PRINT 'Marked ' + CAST(@stale_arrived AS VARCHAR) + ' stale flights as arrived (completed journey)';
PRINT 'Marked ' + CAST(@stale_disconnected AS VARCHAR) + ' stale flights as disconnected (mid-flight)';
GO

-- ============================================================================
-- Step 3: Fix flights with unknown/NULL phase based on position data
-- ============================================================================
PRINT '';
PRINT '--- Step 3: Fixing unknown/NULL phases based on position data ---';

DECLARE @fixed_count INT;

UPDATE c
SET c.phase = CASE
    -- No position data = prefile
    WHEN p.lat IS NULL THEN 'prefile'
    -- On ground at destination (slow + high completion)
    WHEN p.groundspeed_kts < 50 AND ISNULL(p.pct_complete, 0) > 85 THEN 'arrived'
    -- On ground at origin (slow + low completion)
    WHEN p.groundspeed_kts < 50 THEN 'taxiing'
    -- Low altitude, early in flight = departed/climbing
    WHEN p.altitude_ft < 10000 AND ISNULL(p.pct_complete, 0) < 15 THEN 'departed'
    -- Low altitude, late in flight = descending
    WHEN p.altitude_ft < 10000 AND ISNULL(p.pct_complete, 0) > 85 THEN 'descending'
    -- Otherwise enroute
    ELSE 'enroute'
END
FROM dbo.adl_flight_core c
LEFT JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
WHERE c.phase IS NULL OR c.phase = 'unknown';

SET @fixed_count = @@ROWCOUNT;
PRINT 'Fixed ' + CAST(@fixed_count AS VARCHAR) + ' flights with unknown/NULL phase';
GO

-- ============================================================================
-- Step 4: Fix inactive flights that still have non-terminal phase
-- Inactive flights should be:
--   - 'prefile' if they never connected (no position data)
--   - 'arrived' if they completed their journey (close to destination)
--   - 'disconnected' if they disconnected mid-flight
-- ============================================================================
PRINT '';
PRINT '--- Step 4: Fixing inactive flights with non-terminal phases ---';

DECLARE @inactive_prefile INT = 0;
DECLARE @inactive_arrived INT = 0;
DECLARE @inactive_disconnected INT = 0;

-- Prefiles that never connected stay as prefile
UPDATE c
SET c.phase = 'prefile'
FROM dbo.adl_flight_core c
LEFT JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
WHERE c.is_active = 0
  AND c.phase NOT IN ('arrived', 'prefile', 'disconnected')
  AND p.lat IS NULL;

SET @inactive_prefile = @@ROWCOUNT;

-- Flights that completed (close to destination) -> arrived
UPDATE c
SET c.phase = 'arrived'
FROM dbo.adl_flight_core c
LEFT JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
WHERE c.is_active = 0
  AND c.phase NOT IN ('arrived', 'prefile', 'disconnected')
  AND p.groundspeed_kts < 50
  AND ISNULL(p.pct_complete, 0) > 85;

SET @inactive_arrived = @@ROWCOUNT;

-- Remaining inactive flights with mid-flight phases -> disconnected
UPDATE c
SET c.phase = 'disconnected'
FROM dbo.adl_flight_core c
WHERE c.is_active = 0
  AND c.phase NOT IN ('arrived', 'prefile', 'disconnected');

SET @inactive_disconnected = @@ROWCOUNT;

PRINT 'Fixed ' + CAST(@inactive_prefile AS VARCHAR) + ' as prefile (never connected)';
PRINT 'Fixed ' + CAST(@inactive_arrived AS VARCHAR) + ' as arrived (completed journey)';
PRINT 'Fixed ' + CAST(@inactive_disconnected AS VARCHAR) + ' as disconnected (mid-flight disconnect)';
GO

-- ============================================================================
-- Step 5: Final diagnostic - Show updated phase distribution
-- ============================================================================
PRINT '';
PRINT '--- Updated Phase Distribution ---';

SELECT
    phase,
    is_active,
    COUNT(*) AS flight_count
FROM dbo.adl_flight_core
GROUP BY phase, is_active
ORDER BY is_active DESC, flight_count DESC;
GO

PRINT '';
PRINT '=== ADL Migration 013 Complete ===';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO
