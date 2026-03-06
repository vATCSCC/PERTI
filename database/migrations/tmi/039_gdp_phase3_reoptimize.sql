-- ============================================================================
-- VATSIM_TMI Migration 039: GDP Phase 3 — Rolling Re-optimization
-- Adds orchestrator SP that chains popup assignment, compression, reserve
-- adjustment, and metrics update into a single re-optimization cycle.
-- ============================================================================
-- Version: 1.0.0
-- Date: 2026-03-05
-- Author: HP/Claude
--
-- Changes:
--   1. Add reopt tracking columns to tmi_programs
--   2. sp_TMI_ReoptimizeProgram: orchestrator for rolling re-optimization
--
-- Re-optimization cycle (called every 2-5 min by daemon or manually):
--   Step 1: Assign pending popups from tmi_popup_queue → slots
--   Step 2: Run compression (departed/no-show slots → delayed flights)
--   Step 3: Adjust reserves (RESERVED → REGULAR as demand fills)
--   Step 4: Update program metrics
--   Step 5: Log re-optimization event
--
-- Note: Popup DETECTION (ADL query → tmi_popup_queue) happens in PHP
-- before calling this SP. This SP only handles TMI-side operations.
--
-- References:
--   - Mukherjee/Hansen (2007-12): Dynamic stochastic GDP, rolling horizon
--   - EUROCONTROL ATFCM: Continuous compression + adaptive reserve
-- ============================================================================

USE VATSIM_TMI;
GO

PRINT '=== Migration 039: GDP Phase 3 — Rolling Re-optimization ===';
PRINT '';

-- ============================================================================
-- PART 1: Schema changes
-- ============================================================================
PRINT 'Part 1: Schema changes...';

-- Track re-optimization cycles on the program
IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_programs' AND COLUMN_NAME = 'last_reopt_utc')
    ALTER TABLE dbo.tmi_programs ADD last_reopt_utc DATETIME2(0) NULL;

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_programs' AND COLUMN_NAME = 'reopt_cycle')
    ALTER TABLE dbo.tmi_programs ADD reopt_cycle INT NOT NULL DEFAULT 0;

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_programs' AND COLUMN_NAME = 'reopt_interval_sec')
    ALTER TABLE dbo.tmi_programs ADD reopt_interval_sec INT NOT NULL DEFAULT 120;

PRINT '  Added: last_reopt_utc, reopt_cycle, reopt_interval_sec to tmi_programs';
GO

-- ============================================================================
-- PART 2: sp_TMI_ReoptimizeProgram
--
-- Orchestrates a single re-optimization cycle for an active GDP/AFP program.
-- Called after popup detection (PHP) has populated tmi_popup_queue.
--
-- Steps:
--   1. Assign popups (sp_TMI_AssignPopups)
--   2. Compress (sp_TMI_RunCompression)
--   3. Adjust reserves (sp_TMI_AdjustReserves)
--   4. Update program metrics
--   5. Increment cycle counter and log event
--
-- Non-anticipativity: This SP never un-assigns flights. It only:
--   - Assigns new popups to open slots
--   - Moves delayed flights to earlier vacant slots (compression)
--   - Converts reserved slots to regular (adaptive reserve)
-- Flights already assigned and not departed remain in their slots.
-- ============================================================================
PRINT 'Part 2: Creating sp_TMI_ReoptimizeProgram...';

IF OBJECT_ID('dbo.sp_TMI_ReoptimizeProgram', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_TMI_ReoptimizeProgram;
GO

CREATE PROCEDURE dbo.sp_TMI_ReoptimizeProgram
    @program_id             INT,
    @triggered_by           NVARCHAR(64) = 'SYSTEM',
    @popups_assigned        INT OUTPUT,
    @slots_compressed       INT OUTPUT,
    @delay_saved_min        INT OUTPUT,
    @reserves_converted     INT OUTPUT,
    @actions_taken          BIT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    SET @popups_assigned = 0;
    SET @slots_compressed = 0;
    SET @delay_saved_min = 0;
    SET @reserves_converted = 0;
    SET @actions_taken = 0;

    -- Validate: must be an active program
    DECLARE @is_active BIT;
    DECLARE @program_type NVARCHAR(16);
    DECLARE @last_reopt DATETIME2(0);
    DECLARE @reopt_interval INT;

    SELECT
        @is_active = is_active,
        @program_type = program_type,
        @last_reopt = last_reopt_utc,
        @reopt_interval = ISNULL(reopt_interval_sec, 120)
    FROM dbo.tmi_programs
    WHERE program_id = @program_id;

    IF @is_active IS NULL
    BEGIN
        RAISERROR('Program not found: %d', 16, 1, @program_id);
        RETURN 1;
    END

    IF @is_active != 1
    BEGIN
        RAISERROR('Program %d is not active', 16, 1, @program_id);
        RETURN 1;
    END

    -- GS programs don't use slots — no re-optimization
    IF @program_type = 'GS'
        RETURN 0;

    -- Rate limiting: skip if last reopt was too recent
    -- (unless triggered manually — triggered_by != 'SYSTEM')
    IF @triggered_by = 'SYSTEM' AND @last_reopt IS NOT NULL
       AND DATEDIFF(SECOND, @last_reopt, SYSUTCDATETIME()) < @reopt_interval
        RETURN 0;

    BEGIN TRY

    -- ================================================================
    -- Step 1: Assign pending popup flights
    -- ================================================================
    DECLARE @popup_result INT;
    EXEC @popup_result = dbo.sp_TMI_AssignPopups
        @program_id = @program_id,
        @assigned_count = @popups_assigned OUTPUT;

    IF @popups_assigned > 0
        SET @actions_taken = 1;

    -- ================================================================
    -- Step 2: Run compression
    -- ================================================================
    DECLARE @comp_result INT;
    EXEC @comp_result = dbo.sp_TMI_RunCompression
        @program_id = @program_id,
        @compression_by = @triggered_by,
        @slots_compressed = @slots_compressed OUTPUT,
        @delay_saved_min = @delay_saved_min OUTPUT;

    IF @slots_compressed > 0
        SET @actions_taken = 1;

    -- ================================================================
    -- Step 3: Adjust reserves (adaptive)
    -- ================================================================
    DECLARE @reserve_result INT;
    EXEC @reserve_result = dbo.sp_TMI_AdjustReserves
        @program_id = @program_id,
        @converted_count = @reserves_converted OUTPUT;

    IF @reserves_converted > 0
        SET @actions_taken = 1;

    -- ================================================================
    -- Step 4: Update program metrics
    -- ================================================================
    UPDATE dbo.tmi_programs
    SET total_flights = (
            SELECT COUNT(*) FROM dbo.tmi_flight_control
            WHERE program_id = @program_id
        ),
        controlled_flights = (
            SELECT COUNT(*) FROM dbo.tmi_flight_control
            WHERE program_id = @program_id AND ctl_exempt = 0
        ),
        exempt_flights = (
            SELECT COUNT(*) FROM dbo.tmi_flight_control
            WHERE program_id = @program_id AND ctl_exempt = 1
        ),
        avg_delay_min = (
            SELECT AVG(CAST(program_delay_min AS DECIMAL(8,2)))
            FROM dbo.tmi_flight_control
            WHERE program_id = @program_id AND ctl_exempt = 0 AND slot_id IS NOT NULL
        ),
        max_delay_min = (
            SELECT MAX(program_delay_min)
            FROM dbo.tmi_flight_control
            WHERE program_id = @program_id AND ctl_exempt = 0 AND slot_id IS NOT NULL
        ),
        total_delay_min = (
            SELECT SUM(program_delay_min)
            FROM dbo.tmi_flight_control
            WHERE program_id = @program_id AND ctl_exempt = 0 AND slot_id IS NOT NULL
        ),
        last_reopt_utc = SYSUTCDATETIME(),
        reopt_cycle = ISNULL(reopt_cycle, 0) + 1,
        updated_at = SYSUTCDATETIME()
    WHERE program_id = @program_id;

    -- ================================================================
    -- Step 5: Log re-optimization event
    -- ================================================================
    IF @actions_taken = 1
    BEGIN
        -- Pre-compute source_type (CASE not allowed directly in EXEC params)
        DECLARE @log_source NVARCHAR(16) = CASE WHEN @triggered_by = 'SYSTEM' THEN 'DAEMON' ELSE 'USER' END;
        -- Keep detail under 64 chars (sp_LogTmiEvent @event_detail limit)
        DECLARE @detail NVARCHAR(64) = CONCAT(
            '+', @popups_assigned, 'P ', @slots_compressed, 'C(-', @delay_saved_min, 'm) ', @reserves_converted, 'R'
        );

        EXEC dbo.sp_LogTmiEvent
            @entity_type = 'PROGRAM',
            @entity_id = @program_id,
            @event_type = 'REOPTIMIZATION',
            @event_detail = @detail,
            @source_type = @log_source,
            @actor_id = @triggered_by,
            @program_id = @program_id;
    END

    END TRY
    BEGIN CATCH
        -- Log the error but don't re-raise — partial work (popups, compression)
        -- may have already committed in the sub-SPs. The metrics update in Step 4
        -- will be retried on the next cycle.
        DECLARE @err_msg NVARCHAR(4000) = ERROR_MESSAGE();
        DECLARE @err_source NVARCHAR(16) = CASE WHEN @triggered_by = 'SYSTEM' THEN 'DAEMON' ELSE 'USER' END;
        EXEC dbo.sp_LogTmiEvent
            @entity_type = 'PROGRAM',
            @entity_id = @program_id,
            @event_type = 'REOPT_ERROR',
            @event_detail = @err_msg,
            @source_type = @err_source,
            @actor_id = @triggered_by,
            @program_id = @program_id;
    END CATCH

    RETURN 0;
END;
GO

PRINT '  Created: sp_TMI_ReoptimizeProgram';

-- ============================================================================
-- Summary
-- ============================================================================
PRINT '';
PRINT '=== Migration 039 Complete ===';
PRINT 'Schema: last_reopt_utc, reopt_cycle, reopt_interval_sec (tmi_programs)';
PRINT 'New: sp_TMI_ReoptimizeProgram (rolling re-optimization orchestrator)';
GO
