-- ============================================================================
-- sp_TriggerCrossingRecalc
-- Version: 1.0
-- Date: 2026-01-07
-- Description: Trigger crossing recalculation for specific events
--              Called by zone transition (OUT/OFF), route changes, level flight
-- ============================================================================

CREATE OR ALTER PROCEDURE dbo.sp_TriggerCrossingRecalc
    @flight_uid BIGINT,
    @trigger_source VARCHAR(32) = NULL  -- 'OUT', 'OFF', 'ROUTE_CHANGE', 'LEVEL_FLIGHT'
AS
BEGIN
    SET NOCOUNT ON;

    UPDATE dbo.adl_flight_core
    SET crossing_needs_recalc = 1
    WHERE flight_uid = @flight_uid
      AND is_active = 1;

    -- Return success
    SELECT @@ROWCOUNT AS flights_flagged, @trigger_source AS trigger_source;
END
GO

-- ============================================================================
-- sp_UpdateLevelFlightStatus
-- Version: 1.0
-- Date: 2026-01-07
-- Description: Update level flight detection with smoothing
--              Triggers recalc after 3 consecutive level samples
--              Resets when flight starts climbing or descending
--              Triggers after BOTH climb and descent phases
-- ============================================================================

CREATE OR ALTER PROCEDURE dbo.sp_UpdateLevelFlightStatus
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @level_threshold INT = 200;     -- fpm threshold for level flight
    DECLARE @required_samples INT = 3;       -- Consecutive samples needed

    -- ========================================================================
    -- 1. Detect current vertical phase for all active flights
    -- ========================================================================
    ;WITH FlightPhases AS (
        SELECT
            c.flight_uid,
            c.level_flight_samples,
            c.level_flight_confirmed,
            c.last_vertical_phase,
            CASE
                WHEN p.vertical_rate_fpm > @level_threshold THEN 'C'      -- Climbing
                WHEN p.vertical_rate_fpm < -@level_threshold THEN 'D'     -- Descending
                ELSE 'L'                                                   -- Level
            END AS current_phase,
            ABS(ISNULL(p.vertical_rate_fpm, 0)) AS abs_vrate
        FROM dbo.adl_flight_core c
        JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
        WHERE c.is_active = 1
          AND c.lifecycle_state IN ('departed', 'enroute', 'descending')
    )
    UPDATE c
    SET
        -- Increment or reset level flight samples
        c.level_flight_samples = CASE
            WHEN fp.current_phase = 'L' THEN
                CASE WHEN c.level_flight_samples < 255
                     THEN c.level_flight_samples + 1
                     ELSE 255 END
            ELSE 0  -- Reset when not level
        END,

        -- Update phase tracking
        c.last_vertical_phase = fp.current_phase,

        -- Confirm level flight after threshold AND trigger recalc
        -- Trigger when transitioning TO level from climb OR descent
        c.level_flight_confirmed = CASE
            WHEN fp.current_phase = 'L'
                 AND c.level_flight_samples >= @required_samples - 1  -- Will hit threshold this update
                 AND c.level_flight_confirmed = 0                      -- Not already confirmed
            THEN 1
            WHEN fp.current_phase != 'L'
            THEN 0  -- Reset when leaving level flight
            ELSE c.level_flight_confirmed
        END,

        -- Trigger recalc when level flight is newly confirmed
        c.crossing_needs_recalc = CASE
            WHEN fp.current_phase = 'L'
                 AND c.level_flight_samples >= @required_samples - 1
                 AND c.level_flight_confirmed = 0
                 AND c.last_vertical_phase IN ('C', 'D')  -- Transitioning FROM climb or descent
            THEN 1
            ELSE c.crossing_needs_recalc
        END
    FROM dbo.adl_flight_core c
    JOIN FlightPhases fp ON fp.flight_uid = c.flight_uid;

    SELECT @@ROWCOUNT AS flights_updated;
END
GO

-- ============================================================================
-- sp_TriggerCrossingRecalcBatch
-- Version: 1.0
-- Date: 2026-01-07
-- Description: Batch trigger for multiple flights (e.g., after route parsing)
-- ============================================================================

CREATE OR ALTER PROCEDURE dbo.sp_TriggerCrossingRecalcBatch
    @flight_uids NVARCHAR(MAX) = NULL,      -- Comma-separated list
    @trigger_source VARCHAR(32) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    IF @flight_uids IS NULL OR @flight_uids = ''
        RETURN;

    -- Parse comma-separated UIDs
    ;WITH ParsedUIDs AS (
        SELECT CAST(value AS BIGINT) AS flight_uid
        FROM STRING_SPLIT(@flight_uids, ',')
        WHERE ISNUMERIC(value) = 1
    )
    UPDATE c
    SET c.crossing_needs_recalc = 1
    FROM dbo.adl_flight_core c
    JOIN ParsedUIDs p ON p.flight_uid = c.flight_uid
    WHERE c.is_active = 1;

    SELECT @@ROWCOUNT AS flights_flagged, @trigger_source AS trigger_source;
END
GO

PRINT 'Created procedure: sp_TriggerCrossingRecalc';
PRINT 'Created procedure: sp_UpdateLevelFlightStatus';
PRINT 'Created procedure: sp_TriggerCrossingRecalcBatch';
GO
