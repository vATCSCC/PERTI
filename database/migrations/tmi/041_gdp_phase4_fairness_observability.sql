-- ============================================================================
-- VATSIM_TMI Migration 041: GDP Phase 4 — Fairness & Observability
-- Adds filing-order reversal metrics (Bertsimas/Gupta 2016), anti-gaming
-- flags (Schummer/Vohra 2013), and wires fairness computation into the
-- assignment and re-optimization pipelines.
-- ============================================================================
-- Version: 1.0.0
-- Date: 2026-03-05
-- Author: HP/Claude
--
-- Changes:
--   1. Add fairness metrics columns to tmi_programs
--   2. Add filing_time_utc and gaming_flag to tmi_flight_control
--   3. Filtered indexes for fairness/gaming queries
--   4. sp_TMI_ComputeReversals: filing-order reversal computation
--   5. Redefine sp_TMI_AssignFlightsFPFS: add reversal computation
--   6. Redefine sp_TMI_ReoptimizeProgram: add fairness + gaming steps
--
-- Filing-order reversal (Bertsimas/Gupta 2016):
--   Flight A filed before B (filing_time_utc_A < B), but B gets an earlier
--   arrival slot (cta_utc_B < cta_utc_A). Target: <15% reversal rate.
--   On VATSIM, filing_time_utc = adl_flight_core.first_seen_utc (closest
--   proxy — no formal FPL filing event).
--
-- Anti-gaming flags (informational only, not blocking):
--   MULTI_FILING  - Same CID has >1 active flight to GDP destination
--   DEST_SWITCH   - Destination changed to GDP airport after program start
--   LATE_STRATEGIC - Popup filed after program start, CTA in last 30 min
--   Detection runs in PHP (cross-database ADL+TMI), flags stored here.
--
-- References:
--   - Bertsimas/Gupta (2016): Network fairness with reversal metrics
--   - Schummer/Vohra (2013): Anti-gaming mechanism design
-- ============================================================================

USE VATSIM_TMI;
GO

PRINT '=== Migration 041: GDP Phase 4 — Fairness & Observability ===';
PRINT '';

-- ============================================================================
-- PART 1: Schema changes — tmi_programs
-- ============================================================================
PRINT 'Part 1: Schema changes (tmi_programs)...';

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_programs' AND COLUMN_NAME = 'reversal_count')
    ALTER TABLE dbo.tmi_programs ADD reversal_count INT NOT NULL DEFAULT 0;

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_programs' AND COLUMN_NAME = 'reversal_pct')
    ALTER TABLE dbo.tmi_programs ADD reversal_pct DECIMAL(5,2) NOT NULL DEFAULT 0.0;

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_programs' AND COLUMN_NAME = 'fairness_computed_utc')
    ALTER TABLE dbo.tmi_programs ADD fairness_computed_utc DATETIME2(0) NULL;

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_programs' AND COLUMN_NAME = 'gaming_flags_count')
    ALTER TABLE dbo.tmi_programs ADD gaming_flags_count INT NOT NULL DEFAULT 0;

PRINT '  Added: reversal_count, reversal_pct, fairness_computed_utc, gaming_flags_count';
GO

-- ============================================================================
-- PART 2: Schema changes — tmi_flight_control
-- ============================================================================
PRINT 'Part 2: Schema changes (tmi_flight_control)...';

-- Filing time proxy: copied from adl_flight_core.first_seen_utc by PHP at assignment time
IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_flight_control' AND COLUMN_NAME = 'filing_time_utc')
    ALTER TABLE dbo.tmi_flight_control ADD filing_time_utc DATETIME2(0) NULL;

-- Anti-gaming flag: MULTI_FILING, DEST_SWITCH, LATE_STRATEGIC (or NULL = clean)
IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_flight_control' AND COLUMN_NAME = 'gaming_flag')
    ALTER TABLE dbo.tmi_flight_control ADD gaming_flag NVARCHAR(32) NULL;

PRINT '  Added: filing_time_utc, gaming_flag';
GO

-- ============================================================================
-- PART 3: Filtered indexes
-- ============================================================================
PRINT 'Part 3: Creating filtered indexes...';

-- Reversal computation: self-join on non-exempt, slotted flights with filing times
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_tmi_flight_control_fairness')
    CREATE NONCLUSTERED INDEX IX_tmi_flight_control_fairness
        ON dbo.tmi_flight_control(program_id, filing_time_utc)
        WHERE filing_time_utc IS NOT NULL AND ctl_exempt = 0 AND slot_id IS NOT NULL
        INCLUDE (flight_uid, cta_utc);

-- Gaming flag lookup
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_tmi_flight_control_gaming')
    CREATE NONCLUSTERED INDEX IX_tmi_flight_control_gaming
        ON dbo.tmi_flight_control(program_id, gaming_flag)
        WHERE gaming_flag IS NOT NULL
        INCLUDE (flight_uid, callsign);

PRINT '  Created: IX_tmi_flight_control_fairness, IX_tmi_flight_control_gaming';
GO

-- ============================================================================
-- PART 4: sp_TMI_ComputeReversals
--
-- Computes the filing-order reversal metric for a GDP/AFP program.
-- A "reversal" = flight A filed before B but B gets an earlier CTA.
--
-- Uses a self-join on tmi_flight_control. O(n^2) pair comparisons,
-- but n is typically 30-200 for VATSIM GDPs — well under 50ms.
--
-- Updates tmi_programs.reversal_count, reversal_pct, fairness_computed_utc.
-- ============================================================================
PRINT 'Part 4: Creating sp_TMI_ComputeReversals...';

IF OBJECT_ID('dbo.sp_TMI_ComputeReversals', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_TMI_ComputeReversals;
GO

CREATE PROCEDURE dbo.sp_TMI_ComputeReversals
    @program_id         INT,
    @reversal_count     INT OUTPUT,
    @reversal_pct       DECIMAL(5,2) OUTPUT,
    @eligible_pairs     BIGINT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    SET @reversal_count = 0;
    SET @reversal_pct = 0.0;
    SET @eligible_pairs = 0;

    -- Count eligible flights: non-exempt, slotted, with filing times
    DECLARE @eligible INT;
    SELECT @eligible = COUNT(*)
    FROM dbo.tmi_flight_control
    WHERE program_id = @program_id
      AND ctl_exempt = 0
      AND slot_id IS NOT NULL
      AND filing_time_utc IS NOT NULL
      AND cta_utc IS NOT NULL;

    IF @eligible < 2
    BEGIN
        UPDATE dbo.tmi_programs
        SET reversal_count = 0,
            reversal_pct = 0.0,
            fairness_computed_utc = SYSUTCDATETIME(),
            updated_at = SYSUTCDATETIME()
        WHERE program_id = @program_id;
        RETURN 0;
    END

    -- Count reversal pairs:
    -- A filed before B (filing_time_utc_A < B) but B arrives earlier (cta_utc_B < A)
    -- filing_time_utc strict inequality provides directionality and de-duplication:
    -- each ordered pair (earlier_filer, later_filer) appears exactly once.
    -- Ties in filing_time_utc are excluded (correct: filing order undefined).
    SELECT @reversal_count = COUNT(*)
    FROM dbo.tmi_flight_control A
    INNER JOIN dbo.tmi_flight_control B
        ON A.program_id = B.program_id
        AND A.filing_time_utc < B.filing_time_utc
    WHERE A.program_id = @program_id
      AND A.ctl_exempt = 0 AND B.ctl_exempt = 0
      AND A.slot_id IS NOT NULL AND B.slot_id IS NOT NULL
      AND A.filing_time_utc IS NOT NULL AND B.filing_time_utc IS NOT NULL
      AND A.cta_utc IS NOT NULL AND B.cta_utc IS NOT NULL
      AND B.cta_utc < A.cta_utc;

    -- Reversal percentage = reversals / total ordered pairs * 100
    -- Total ordered pairs = n*(n-1)/2
    SET @eligible_pairs = CAST(@eligible AS BIGINT) * (@eligible - 1) / 2;

    IF @eligible_pairs > 0
        SET @reversal_pct = CAST(@reversal_count AS DECIMAL(10,2)) * 100.0 / @eligible_pairs;

    UPDATE dbo.tmi_programs
    SET reversal_count = @reversal_count,
        reversal_pct = @reversal_pct,
        fairness_computed_utc = SYSUTCDATETIME(),
        updated_at = SYSUTCDATETIME()
    WHERE program_id = @program_id;

    RETURN 0;
END;
GO

PRINT '  Created: sp_TMI_ComputeReversals';

-- ============================================================================
-- PART 5: Redefine sp_TMI_AssignFlightsFPFS
--
-- Same logic as migration 038, with one addition:
-- After the final metrics UPDATE, call sp_TMI_ComputeReversals so that
-- fairness metrics are computed on initial assignment.
-- ============================================================================
PRINT 'Part 5: Redefining sp_TMI_AssignFlightsFPFS (add reversal computation)...';

IF OBJECT_ID('dbo.sp_TMI_AssignFlightsFPFS', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_TMI_AssignFlightsFPFS;
GO

CREATE PROCEDURE dbo.sp_TMI_AssignFlightsFPFS
    @program_id         INT,
    @flights            dbo.FlightListType READONLY,
    @assigned_count     INT OUTPUT,
    @exempt_count       INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @ctl_element NVARCHAR(8);
    DECLARE @program_type NVARCHAR(16);
    DECLARE @delay_limit_min INT;
    DECLARE @start_utc DATETIME2(0);

    SELECT
        @ctl_element = ctl_element,
        @program_type = program_type,
        @delay_limit_min = ISNULL(delay_limit_min, 180),
        @start_utc = start_utc
    FROM dbo.tmi_programs WHERE program_id = @program_id;

    SET @assigned_count = 0;
    SET @exempt_count = 0;

    -- Clear existing assignments
    DELETE FROM dbo.tmi_flight_control WHERE program_id = @program_id;

    -- Complete slot reset
    UPDATE dbo.tmi_slots
    SET slot_status = 'OPEN',
        assigned_flight_uid = NULL,
        assigned_callsign = NULL,
        assigned_carrier = NULL,
        assigned_origin = NULL,
        assigned_utc = NULL,
        original_eta_utc = NULL,
        slot_delay_min = NULL,
        ctd_utc = NULL,
        cta_utc = NULL,
        is_popup_slot = 0,
        popup_lead_time_min = NULL,
        modified_utc = SYSUTCDATETIME()
    WHERE program_id = @program_id;

    -- Cursor variables
    DECLARE @flight_uid BIGINT, @callsign NVARCHAR(12), @eta_utc DATETIME2(0), @etd_utc DATETIME2(0);
    DECLARE @dep_airport NVARCHAR(4), @arr_airport NVARCHAR(4), @dep_center NVARCHAR(4), @arr_center NVARCHAR(4), @carrier NVARCHAR(8);
    DECLARE @is_exempt BIT, @exempt_reason NVARCHAR(32), @dist_nm FLOAT;
    DECLARE @slot_id BIGINT, @slot_name NVARCHAR(16), @slot_time DATETIME2(0);
    DECLARE @delay_min INT, @delay_capped BIT, @ctd_utc DATETIME2(0), @ete_min INT;
    DECLARE @ctl_type NVARCHAR(8);

    -- FPFS + RBD ordering
    DECLARE flight_cursor CURSOR LOCAL FAST_FORWARD FOR
        SELECT flight_uid, callsign, eta_utc, etd_utc, dep_airport, arr_airport, dep_center, arr_center, carrier,
               ISNULL(is_exempt, 0), exempt_reason, dist_to_dest_nm
        FROM @flights
        ORDER BY
            DATEDIFF(MINUTE, @start_utc, eta_utc) / 5,
            ISNULL(dist_to_dest_nm, 99999) ASC,
            flight_uid;

    OPEN flight_cursor;
    FETCH NEXT FROM flight_cursor INTO @flight_uid, @callsign, @eta_utc, @etd_utc, @dep_airport, @arr_airport, @dep_center, @arr_center, @carrier, @is_exempt, @exempt_reason, @dist_nm;

    WHILE @@FETCH_STATUS = 0
    BEGIN
        IF @is_exempt = 1
        BEGIN
            INSERT INTO dbo.tmi_flight_control (
                flight_uid, callsign, program_id, ctl_elem, ctl_type,
                ctl_exempt, ctl_exempt_reason,
                orig_eta_utc, orig_etd_utc, dist_to_dest_nm,
                dep_airport, arr_airport, dep_center, arr_center,
                assignment_reason, control_assigned_utc
            )
            VALUES (
                @flight_uid, @callsign, @program_id, @ctl_element, @program_type,
                1, @exempt_reason,
                @eta_utc, @etd_utc, @dist_nm,
                @dep_airport, @arr_airport, @dep_center, @arr_center,
                'EXEMPT', SYSUTCDATETIME()
            );
            SET @exempt_count = @exempt_count + 1;
        END
        ELSE
        BEGIN
            -- Find next available REGULAR slot at or after ETA
            SELECT TOP 1
                @slot_id = slot_id,
                @slot_name = slot_name,
                @slot_time = slot_time_utc
            FROM dbo.tmi_slots
            WHERE program_id = @program_id
              AND slot_status = 'OPEN'
              AND slot_type = 'REGULAR'
              AND slot_time_utc >= @eta_utc
            ORDER BY slot_time_utc;

            -- If no regular slot, try reserved (overflow for GAAP/UDP)
            IF @slot_id IS NULL AND @program_type IN ('GDP-GAAP', 'GDP-UDP')
            BEGIN
                SELECT TOP 1
                    @slot_id = slot_id,
                    @slot_name = slot_name,
                    @slot_time = slot_time_utc
                FROM dbo.tmi_slots
                WHERE program_id = @program_id
                  AND slot_status = 'OPEN'
                  AND slot_time_utc >= @eta_utc
                ORDER BY slot_time_utc;
            END

            IF @slot_id IS NOT NULL
            BEGIN
                SET @delay_min = DATEDIFF(MINUTE, @eta_utc, @slot_time);
                SET @delay_capped = CASE WHEN @delay_min > @delay_limit_min THEN 1 ELSE 0 END;
                IF @delay_capped = 1 SET @delay_min = @delay_limit_min;
                SET @ete_min = DATEDIFF(MINUTE, @etd_utc, @eta_utc);
                SET @ctd_utc = DATEADD(MINUTE, -@ete_min, @slot_time);

                SET @ctl_type = CASE @program_type
                    WHEN 'GDP-DAS' THEN 'DAS'
                    WHEN 'GDP-GAAP' THEN 'GAAP'
                    WHEN 'GDP-UDP' THEN 'UDP'
                    ELSE @program_type
                END;

                UPDATE dbo.tmi_slots
                SET slot_status = 'ASSIGNED',
                    assigned_flight_uid = @flight_uid,
                    assigned_callsign = @callsign,
                    assigned_carrier = @carrier,
                    assigned_origin = @dep_airport,
                    assigned_utc = SYSUTCDATETIME(),
                    original_eta_utc = @eta_utc,
                    slot_delay_min = @delay_min,
                    ctd_utc = @ctd_utc,
                    cta_utc = @slot_time,
                    modified_utc = SYSUTCDATETIME()
                WHERE slot_id = @slot_id;

                INSERT INTO dbo.tmi_flight_control (
                    flight_uid, callsign, program_id, slot_id,
                    ctd_utc, cta_utc, octd_utc, octa_utc,
                    aslot, ctl_elem, ctl_type,
                    program_delay_min, delay_capped,
                    orig_eta_utc, orig_etd_utc, orig_ete_min,
                    dist_to_dest_nm,
                    dep_airport, arr_airport, dep_center, arr_center,
                    assignment_reason, control_assigned_utc
                )
                VALUES (
                    @flight_uid, @callsign, @program_id, @slot_id,
                    @ctd_utc, @slot_time, @ctd_utc, @slot_time,
                    @slot_name, @ctl_element, @ctl_type,
                    @delay_min, @delay_capped,
                    @eta_utc, @etd_utc, @ete_min,
                    @dist_nm,
                    @dep_airport, @arr_airport, @dep_center, @arr_center,
                    'FPFS_RBD', SYSUTCDATETIME()
                );

                SET @assigned_count = @assigned_count + 1;
            END
            ELSE
            BEGIN
                -- No slot available
                INSERT INTO dbo.tmi_flight_control (
                    flight_uid, callsign, program_id,
                    ctl_elem, ctl_type, ctl_exempt,
                    program_delay_min, delay_capped,
                    orig_eta_utc, orig_etd_utc,
                    dist_to_dest_nm,
                    dep_airport, arr_airport, dep_center, arr_center,
                    compliance_status, assignment_reason,
                    control_assigned_utc
                )
                VALUES (
                    @flight_uid, @callsign, @program_id,
                    @ctl_element,
                    CASE @program_type
                        WHEN 'GDP-DAS' THEN 'DAS'
                        WHEN 'GDP-GAAP' THEN 'GAAP'
                        WHEN 'GDP-UDP' THEN 'UDP'
                        ELSE @program_type
                    END,
                    0,
                    @delay_limit_min, 1,
                    @eta_utc, @etd_utc,
                    @dist_nm,
                    @dep_airport, @arr_airport, @dep_center, @arr_center,
                    'UNASSIGNED', 'NO_SLOT',
                    SYSUTCDATETIME()
                );
                SET @assigned_count = @assigned_count + 1;
            END
        END

        SET @slot_id = NULL;
        FETCH NEXT FROM flight_cursor INTO @flight_uid, @callsign, @eta_utc, @etd_utc, @dep_airport, @arr_airport, @dep_center, @arr_center, @carrier, @is_exempt, @exempt_reason, @dist_nm;
    END

    CLOSE flight_cursor;
    DEALLOCATE flight_cursor;

    -- Update program metrics (exclude UNASSIGNED from delay averages)
    UPDATE dbo.tmi_programs
    SET total_flights = @assigned_count + @exempt_count,
        controlled_flights = @assigned_count,
        exempt_flights = @exempt_count,
        avg_delay_min = (SELECT AVG(CAST(program_delay_min AS DECIMAL(8,2)))
                         FROM dbo.tmi_flight_control
                         WHERE program_id = @program_id AND ctl_exempt = 0 AND slot_id IS NOT NULL),
        max_delay_min = (SELECT MAX(program_delay_min)
                         FROM dbo.tmi_flight_control
                         WHERE program_id = @program_id AND ctl_exempt = 0 AND slot_id IS NOT NULL),
        total_delay_min = (SELECT SUM(program_delay_min)
                           FROM dbo.tmi_flight_control
                           WHERE program_id = @program_id AND ctl_exempt = 0 AND slot_id IS NOT NULL),
        updated_at = SYSUTCDATETIME()
    WHERE program_id = @program_id;

    -- [Phase 4] Compute filing-order reversal metrics
    -- Note: filing_time_utc is populated by PHP after this SP returns
    -- (cross-database bridge from adl_flight_core.first_seen_utc).
    -- On initial assignment, reversals will be 0 until filing times are populated.
    -- The reoptimize cycle will compute accurate reversals on subsequent runs.
    DECLARE @rev_count INT, @rev_pct DECIMAL(5,2), @rev_pairs BIGINT;
    EXEC dbo.sp_TMI_ComputeReversals
        @program_id = @program_id,
        @reversal_count = @rev_count OUTPUT,
        @reversal_pct = @rev_pct OUTPUT,
        @eligible_pairs = @rev_pairs OUTPUT;

    RETURN 0;
END;
GO

PRINT '  Redefined: sp_TMI_AssignFlightsFPFS (added reversal computation)';

-- ============================================================================
-- PART 6: Redefine sp_TMI_ReoptimizeProgram
--
-- Same logic as migration 039, with additions:
-- Step 4.5: Compute filing-order reversal metrics
-- Step 4.6: Update gaming_flags_count
-- ============================================================================
PRINT 'Part 6: Redefining sp_TMI_ReoptimizeProgram (add fairness steps)...';

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

    -- GS programs don't use slots
    IF @program_type = 'GS'
        RETURN 0;

    -- Rate limiting: skip if last reopt was too recent
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
    -- Step 4.5: Compute filing-order reversal metrics [Phase 4]
    -- ================================================================
    DECLARE @rev_count INT, @rev_pct DECIMAL(5,2), @rev_pairs BIGINT;
    EXEC dbo.sp_TMI_ComputeReversals
        @program_id = @program_id,
        @reversal_count = @rev_count OUTPUT,
        @reversal_pct = @rev_pct OUTPUT,
        @eligible_pairs = @rev_pairs OUTPUT;

    -- ================================================================
    -- Step 4.6: Update gaming flags count [Phase 4]
    -- Gaming flags are set by PHP (cross-database detection).
    -- This just refreshes the aggregate count on the program.
    -- ================================================================
    UPDATE dbo.tmi_programs
    SET gaming_flags_count = (
        SELECT COUNT(*) FROM dbo.tmi_flight_control
        WHERE program_id = @program_id AND gaming_flag IS NOT NULL
    ),
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

PRINT '  Redefined: sp_TMI_ReoptimizeProgram (added Steps 4.5, 4.6)';

-- ============================================================================
-- Summary
-- ============================================================================
PRINT '';
PRINT '=== Migration 041 Complete ===';
PRINT 'Schema: reversal_count, reversal_pct, fairness_computed_utc, gaming_flags_count (tmi_programs)';
PRINT 'Schema: filing_time_utc, gaming_flag (tmi_flight_control)';
PRINT 'Indexes: IX_tmi_flight_control_fairness, IX_tmi_flight_control_gaming';
PRINT 'New: sp_TMI_ComputeReversals';
PRINT 'Redefined: sp_TMI_AssignFlightsFPFS (reversal computation)';
PRINT 'Redefined: sp_TMI_ReoptimizeProgram (fairness + gaming steps)';
GO
