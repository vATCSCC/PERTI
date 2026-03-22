-- ============================================================================
-- VATSIM_TMI Migration 038: GDP Phase 2 — FPFS + RBD Algorithm
-- Evolves slot assignment from RBS to CASA-FPFS with RBD tiebreaker
-- ============================================================================
-- Version: 1.0.0
-- Date: 2026-03-05
-- Author: HP/Claude
--
-- Changes:
--   1. Add reserve_pct to tmi_programs (percentage-based reserve slots)
--   2. Add assignment_reason to tmi_flight_control (transparency)
--   3. Rebuild FlightListType TVP with dist_to_dest_nm (for RBD tiebreaker)
--   4. sp_TMI_AssignFlightsFPFS: FPFS ordering + RBD tiebreaker
--   5. sp_TMI_DetectPopups: Recreated for new TVP
--   6. sp_TMI_ApplyGroundStop: Recreated for new TVP
--   7. sp_TMI_GenerateSlots: Percentage-based reserve distribution
--   8. sp_TMI_AdjustReserves: Adaptive reserve management (new)
--   9. sp_TMI_AssignFlightsRBS: Backward-compat wrapper → calls FPFS
--
-- Algorithm (CASA-FPFS + RBD):
--   EUROCONTROL CASA (Computer-Assisted Slot Allocation) uses First-Planned-
--   First-Served ordering by ETA. The RBD (Ration-By-Distance) tiebreaker from
--   Ball/Hoffman/Mukherjee (2010) prioritizes closer flights within the same
--   5-minute ETA window. This is provably optimal for dynamic GDP scenarios
--   with high popup rates — exactly the VATSIM environment.
--
-- References:
--   - EUROCONTROL ATFCM Operations Manual (2024): CASA/FPFS algorithm
--   - Ball/Hoffman/Mukherjee, Transportation Science (2010): RBD optimality
--   - Mukherjee/Hansen (2007-12): Dynamic stochastic GDP
-- ============================================================================

USE VATSIM_TMI;
GO

PRINT '=== Migration 038: GDP Phase 2 — FPFS + RBD Algorithm ===';
PRINT '';

-- ============================================================================
-- PART 1: Schema changes
-- ============================================================================
PRINT 'Part 1: Schema changes...';

-- Add reserve_pct to tmi_programs (0 = use legacy reserve_rate, 1-100 = percentage)
IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_programs' AND COLUMN_NAME = 'reserve_pct')
    ALTER TABLE dbo.tmi_programs ADD reserve_pct TINYINT NOT NULL DEFAULT 0;

-- Add assignment_reason to tmi_flight_control for algorithm transparency
IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_flight_control' AND COLUMN_NAME = 'assignment_reason')
    ALTER TABLE dbo.tmi_flight_control ADD assignment_reason NVARCHAR(16) NULL;

-- Add dist_to_dest_nm to tmi_flight_control for RBD audit trail
IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_flight_control' AND COLUMN_NAME = 'dist_to_dest_nm')
    ALTER TABLE dbo.tmi_flight_control ADD dist_to_dest_nm FLOAT NULL;

-- Expand exempt reason CHECK constraint to include new exemption types
-- (DEPARTING_SOON, EXEMPT_ORIGIN, EXEMPT_FLIGHT used by simulate.php)
IF EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = 'CK_tmi_flight_control_exempt')
    ALTER TABLE dbo.tmi_flight_control DROP CONSTRAINT CK_tmi_flight_control_exempt;

ALTER TABLE dbo.tmi_flight_control
ADD CONSTRAINT CK_tmi_flight_control_exempt CHECK (ctl_exempt_reason IS NULL OR ctl_exempt_reason IN
    ('AIRBORNE', 'DISTANCE', 'CENTER', 'CARRIER', 'TYPE', 'EARLY', 'LATE', 'MANUAL', 'OTHER',
     'DEPARTING_SOON', 'EXEMPT_ORIGIN', 'EXEMPT_FLIGHT'));

PRINT '  Added: reserve_pct to tmi_programs';
PRINT '  Added: assignment_reason, dist_to_dest_nm to tmi_flight_control';
PRINT '  Updated: CK_tmi_flight_control_exempt (added DEPARTING_SOON, EXEMPT_ORIGIN, EXEMPT_FLIGHT)';
GO

-- ============================================================================
-- PART 2: Rebuild FlightListType TVP with dist_to_dest_nm
--
-- Must DROP all SPs that reference it, then DROP TYPE, then CREATE TYPE,
-- then recreate all SPs.
-- ============================================================================
PRINT 'Part 2: Rebuilding FlightListType TVP...';

-- Drop SPs that reference FlightListType
IF OBJECT_ID('dbo.sp_TMI_AssignFlightsRBS', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_TMI_AssignFlightsRBS;
IF OBJECT_ID('dbo.sp_TMI_AssignFlightsFPFS', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_TMI_AssignFlightsFPFS;
IF OBJECT_ID('dbo.sp_TMI_DetectPopups', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_TMI_DetectPopups;
IF OBJECT_ID('dbo.sp_TMI_ApplyGroundStop', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_TMI_ApplyGroundStop;

-- Drop and recreate type with new column
IF TYPE_ID('dbo.FlightListType') IS NOT NULL
    DROP TYPE dbo.FlightListType;
GO

CREATE TYPE dbo.FlightListType AS TABLE (
    flight_uid          BIGINT NOT NULL,
    callsign            NVARCHAR(12) NOT NULL,
    eta_utc             DATETIME2(0) NOT NULL,
    etd_utc             DATETIME2(0) NULL,
    dep_airport         NVARCHAR(4) NULL,
    arr_airport         NVARCHAR(4) NULL,
    dep_center          NVARCHAR(4) NULL,
    arr_center          NVARCHAR(4) NULL,
    carrier             NVARCHAR(8) NULL,
    aircraft_type       NVARCHAR(8) NULL,
    flight_status       NVARCHAR(16) NULL,
    is_exempt           BIT NULL,
    exempt_reason       NVARCHAR(32) NULL,
    dist_to_dest_nm     FLOAT NULL,             -- NEW: distance to destination for RBD tiebreaker
    PRIMARY KEY (flight_uid)
);
GO

PRINT '  Rebuilt: FlightListType with dist_to_dest_nm';

-- ============================================================================
-- PART 3: sp_TMI_AssignFlightsFPFS
--
-- First-Planned-First-Served with Ration-By-Distance tiebreaker.
--
-- Ordering:
--   1. 5-minute ETA window (groups flights with similar ETAs)
--   2. dist_to_dest_nm ASC (closer flights get priority within window)
--   3. flight_uid (deterministic tiebreaker for identical distance)
--
-- This replaces the old sp_TMI_AssignFlightsRBS.
-- ============================================================================
PRINT 'Part 3: Creating sp_TMI_AssignFlightsFPFS...';
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

    -- Complete slot reset — clear ALL assignment metadata
    UPDATE dbo.tmi_slots
    SET slot_status = 'OPEN',
        assigned_flight_uid = NULL,
        assigned_callsign = NULL,
        assigned_carrier = NULL,
        assigned_origin = NULL,
        assigned_at = NULL,
        original_eta_utc = NULL,
        slot_delay_min = NULL,
        ctd_utc = NULL,
        cta_utc = NULL,
        is_popup_slot = 0,
        popup_lead_time_min = NULL,
        updated_at = SYSUTCDATETIME()
    WHERE program_id = @program_id;

    -- Cursor variables
    DECLARE @flight_uid BIGINT, @callsign NVARCHAR(12), @eta_utc DATETIME2(0), @etd_utc DATETIME2(0);
    DECLARE @dep_airport NVARCHAR(4), @arr_airport NVARCHAR(4), @dep_center NVARCHAR(4), @arr_center NVARCHAR(4), @carrier NVARCHAR(8);
    DECLARE @is_exempt BIT, @exempt_reason NVARCHAR(32), @dist_nm FLOAT;
    DECLARE @slot_id BIGINT, @slot_name NVARCHAR(16), @slot_time DATETIME2(0);
    DECLARE @delay_min INT, @delay_capped BIT, @ctd_utc DATETIME2(0), @ete_min INT;
    DECLARE @ctl_type NVARCHAR(8);

    -- FPFS + RBD ordering:
    -- 1. 5-minute ETA window: DATEDIFF(MINUTE, @start_utc, eta_utc) / 5
    --    Groups flights whose ETAs are within the same 5-min bucket.
    -- 2. dist_to_dest_nm ASC: closer flights get priority (RBD tiebreaker)
    --    NULL distance = max distance (least priority within bucket).
    -- 3. flight_uid: deterministic final tiebreaker.
    DECLARE flight_cursor CURSOR LOCAL FAST_FORWARD FOR
        SELECT flight_uid, callsign, eta_utc, etd_utc, dep_airport, arr_airport, dep_center, arr_center, carrier,
               ISNULL(is_exempt, 0), exempt_reason, dist_to_dest_nm
        FROM @flights
        ORDER BY
            DATEDIFF(MINUTE, @start_utc, eta_utc) / 5,    -- 5-min ETA bucket
            ISNULL(dist_to_dest_nm, 99999) ASC,            -- RBD: closer = higher priority
            flight_uid;                                     -- deterministic

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

                -- Determine ctl_type
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
                    assigned_at = SYSUTCDATETIME(),
                    original_eta_utc = @eta_utc,
                    slot_delay_min = @delay_min,
                    ctd_utc = @ctd_utc,
                    cta_utc = @slot_time,
                    updated_at = SYSUTCDATETIME()
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
                -- No slot available — record as UNASSIGNED
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

    RETURN 0;
END;
GO

PRINT '  Created: sp_TMI_AssignFlightsFPFS';

-- ============================================================================
-- PART 4: Recreate sp_TMI_DetectPopups (new TVP signature)
-- Same logic as 037, just references updated FlightListType.
-- ============================================================================
PRINT 'Part 4: Recreating sp_TMI_DetectPopups...';
GO

CREATE PROCEDURE dbo.sp_TMI_DetectPopups
    @program_id         INT,
    @flights            dbo.FlightListType READONLY,
    @popup_count        INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @ctl_element NVARCHAR(8);
    DECLARE @start_utc DATETIME2(0);
    DECLARE @end_utc DATETIME2(0);

    SELECT @ctl_element = ctl_element, @start_utc = start_utc, @end_utc = end_utc
    FROM dbo.tmi_programs WHERE program_id = @program_id AND is_active = 1;

    IF @ctl_element IS NULL
    BEGIN
        SET @popup_count = 0;
        RETURN 0;
    END

    INSERT INTO dbo.tmi_popup_queue (
        flight_uid, callsign, program_id,
        detected_utc, flight_eta_utc, lead_time_min,
        dep_airport, arr_airport, dep_center,
        aircraft_type, carrier, queue_status
    )
    SELECT
        f.flight_uid, f.callsign, @program_id,
        SYSUTCDATETIME(), f.eta_utc,
        DATEDIFF(MINUTE, SYSUTCDATETIME(), f.eta_utc),
        f.dep_airport, f.arr_airport, f.dep_center,
        f.aircraft_type, f.carrier, 'PENDING'
    FROM @flights f
    WHERE f.eta_utc BETWEEN @start_utc AND @end_utc
      AND ISNULL(f.is_exempt, 0) = 0
      AND NOT EXISTS (
          SELECT 1 FROM dbo.tmi_flight_control fc
          WHERE fc.flight_uid = f.flight_uid AND fc.program_id = @program_id
      )
      AND NOT EXISTS (
          SELECT 1 FROM dbo.tmi_popup_queue q
          WHERE q.flight_uid = f.flight_uid AND q.program_id = @program_id
      );

    SET @popup_count = @@ROWCOUNT;
    RETURN 0;
END;
GO

PRINT '  Created: sp_TMI_DetectPopups';

-- ============================================================================
-- PART 5: Recreate sp_TMI_ApplyGroundStop (new TVP signature)
-- Same logic as migration 012, just references updated FlightListType.
-- ============================================================================
PRINT 'Part 5: Recreating sp_TMI_ApplyGroundStop...';
GO

CREATE PROCEDURE dbo.sp_TMI_ApplyGroundStop
    @program_id         INT,
    @flights            dbo.FlightListType READONLY,
    @held_count         INT OUTPUT,
    @exempt_count       INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @ctl_element NVARCHAR(8);
    DECLARE @end_utc DATETIME2(0);

    SELECT @ctl_element = ctl_element, @end_utc = end_utc
    FROM dbo.tmi_programs WHERE program_id = @program_id AND program_type = 'GS';

    DELETE FROM dbo.tmi_flight_control WHERE program_id = @program_id;

    INSERT INTO dbo.tmi_flight_control (
        flight_uid, callsign, program_id,
        ctl_elem, ctl_type,
        ctl_exempt, ctl_exempt_reason,
        gs_held, gs_release_utc,
        orig_eta_utc, orig_etd_utc,
        dep_airport, arr_airport, dep_center, arr_center,
        control_assigned_utc
    )
    SELECT
        f.flight_uid, f.callsign, @program_id,
        @ctl_element, 'GS',
        ISNULL(f.is_exempt, 0), f.exempt_reason,
        CASE WHEN ISNULL(f.is_exempt, 0) = 1 THEN 0 ELSE 1 END,
        @end_utc,
        f.eta_utc, f.etd_utc,
        f.dep_airport, f.arr_airport, f.dep_center, f.arr_center,
        SYSUTCDATETIME()
    FROM @flights f;

    SELECT
        @held_count = SUM(CASE WHEN gs_held = 1 THEN 1 ELSE 0 END),
        @exempt_count = SUM(CASE WHEN ctl_exempt = 1 THEN 1 ELSE 0 END)
    FROM dbo.tmi_flight_control WHERE program_id = @program_id;

    UPDATE dbo.tmi_programs
    SET total_flights = @held_count + @exempt_count,
        controlled_flights = @held_count,
        exempt_flights = @exempt_count
    WHERE program_id = @program_id;

    RETURN 0;
END;
GO

PRINT '  Created: sp_TMI_ApplyGroundStop';

-- ============================================================================
-- PART 6: Update sp_TMI_GenerateSlots
--
-- Add support for percentage-based reserve distribution.
-- If tmi_programs.reserve_pct > 0, uses that instead of legacy reserve_rate.
-- Generates all slots first, then distributes RESERVED evenly.
-- ============================================================================
PRINT 'Part 6: Updating sp_TMI_GenerateSlots...';

IF OBJECT_ID('dbo.sp_TMI_GenerateSlots', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_TMI_GenerateSlots;
GO

CREATE PROCEDURE dbo.sp_TMI_GenerateSlots
    @program_id         INT,
    @slot_count         INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @ctl_element NVARCHAR(8);
    DECLARE @program_type NVARCHAR(16);
    DECLARE @start_utc DATETIME2(0);
    DECLARE @end_utc DATETIME2(0);
    DECLARE @program_rate INT;
    DECLARE @reserve_rate INT;
    DECLARE @reserve_pct TINYINT;
    DECLARE @slot_interval_sec INT;

    SELECT
        @ctl_element = ctl_element,
        @program_type = program_type,
        @start_utc = start_utc,
        @end_utc = end_utc,
        @program_rate = ISNULL(program_rate, 30),
        @reserve_rate = ISNULL(reserve_rate, 0),
        @reserve_pct = ISNULL(reserve_pct, 0)
    FROM dbo.tmi_programs
    WHERE program_id = @program_id;

    IF @ctl_element IS NULL
    BEGIN
        RAISERROR('Program not found: %d', 16, 1, @program_id);
        RETURN 1;
    END

    SET @slot_interval_sec = 3600 / @program_rate;

    -- Delete existing slots (for re-modeling)
    DELETE FROM dbo.tmi_slots WHERE program_id = @program_id;

    -- ====================================================================
    -- Step 1: Generate all slots as REGULAR
    -- ====================================================================
    DECLARE @current_time DATETIME2(0) = @start_utc;
    DECLARE @slot_index INT = 1;
    DECLARE @suffix_counter INT = 0;
    DECLARE @last_minute INT = -1;
    DECLARE @slot_name NVARCHAR(16);
    DECLARE @slot_minute INT;
    DECLARE @suffix_char CHAR(1);

    WHILE @current_time < @end_utc
    BEGIN
        SET @slot_minute = DATEPART(HOUR, @current_time) * 100 + DATEPART(MINUTE, @current_time);
        IF @slot_minute = @last_minute
            SET @suffix_counter = @suffix_counter + 1;
        ELSE
        BEGIN
            SET @suffix_counter = 0;
            SET @last_minute = @slot_minute;
        END

        SET @suffix_char = CHAR(65 + (@suffix_counter % 26));
        SET @slot_name = @ctl_element + '.' + FORMAT(@current_time, 'ddHHmm') + @suffix_char;

        INSERT INTO dbo.tmi_slots (
            program_id, slot_name, slot_index, slot_time_utc,
            slot_type, slot_status,
            bin_date, bin_hour, bin_quarter
        )
        VALUES (
            @program_id, @slot_name, @slot_index, @current_time,
            'REGULAR', 'OPEN',
            CAST(@current_time AS DATE),
            DATEPART(HOUR, @current_time),
            (DATEPART(MINUTE, @current_time) / 15) * 15
        );

        SET @slot_index = @slot_index + 1;
        SET @current_time = DATEADD(SECOND, @slot_interval_sec, @current_time);
    END

    SET @slot_count = @slot_index - 1;

    -- ====================================================================
    -- Step 2: Distribute RESERVED slots
    --
    -- If reserve_pct > 0: use percentage-based distribution (new)
    -- Else if reserve_rate > 0: use legacy interval-based distribution
    -- Else: all slots remain REGULAR
    -- ====================================================================
    IF @reserve_pct > 0 AND @reserve_pct < 100 AND @slot_count > 0
    BEGIN
        -- Percentage-based: mark reserve_pct% of slots as RESERVED, evenly spaced.
        -- Calculate how many REGULAR slots we need.
        DECLARE @regular_count INT = CEILING(@slot_count * (100 - @reserve_pct) / 100.0);
        IF @regular_count < 1 SET @regular_count = 1;
        IF @regular_count > @slot_count SET @regular_count = @slot_count;

        -- Step 2a: Mark ALL slots as RESERVED
        UPDATE dbo.tmi_slots SET slot_type = 'RESERVED'
        WHERE program_id = @program_id;

        -- Step 2b: Un-reserve evenly-spaced slots back to REGULAR.
        -- Uses a fractional step to pick @regular_count slots from @slot_count.
        DECLARE @step FLOAT = CAST(@slot_count AS FLOAT) / @regular_count;
        DECLARE @i INT = 0;
        DECLARE @target_index INT;

        WHILE @i < @regular_count
        BEGIN
            SET @target_index = FLOOR(@i * @step) + 1;  -- 1-based slot_index
            UPDATE dbo.tmi_slots
            SET slot_type = 'REGULAR'
            WHERE program_id = @program_id AND slot_index = @target_index;
            SET @i = @i + 1;
        END
    END
    ELSE IF @reserve_rate > 0 AND @program_type IN ('GDP-GAAP', 'GDP-UDP')
    BEGIN
        -- Legacy interval-based: every Nth slot is RESERVED
        DECLARE @reserve_interval INT = @program_rate / @reserve_rate;
        IF @reserve_interval > 0
        BEGIN
            UPDATE dbo.tmi_slots
            SET slot_type = 'RESERVED'
            WHERE program_id = @program_id
              AND (slot_index % @reserve_interval) = 0;
        END
    END

    UPDATE dbo.tmi_programs
    SET status = 'MODELING', updated_at = SYSUTCDATETIME()
    WHERE program_id = @program_id AND status = 'PROPOSED';

    RETURN 0;
END;
GO

PRINT '  Updated: sp_TMI_GenerateSlots (percentage-based reserves)';

-- ============================================================================
-- PART 7: sp_TMI_AdjustReserves (new)
--
-- Adaptive reserve management: convert RESERVED→REGULAR as demand fills.
--
-- Formula: target_reserve = floor + (initial - floor) * (1 - demand/100)
--   - As demand increases (0→100%), reserve decreases from initial to floor
--   - floor = 20% ensures always some reserve for late popups
--
-- Called periodically (daemon integration in Phase 3) or manually.
-- ============================================================================
PRINT 'Part 7: Creating sp_TMI_AdjustReserves...';
GO

CREATE PROCEDURE dbo.sp_TMI_AdjustReserves
    @program_id         INT,
    @converted_count    INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    SET @converted_count = 0;

    DECLARE @reserve_pct TINYINT;
    DECLARE @is_active BIT;
    DECLARE @program_type NVARCHAR(16);

    SELECT
        @reserve_pct = ISNULL(reserve_pct, 0),
        @is_active = is_active,
        @program_type = program_type
    FROM dbo.tmi_programs
    WHERE program_id = @program_id;

    -- Only applies to active programs with percentage-based reserves
    IF @is_active != 1 OR @reserve_pct = 0
        RETURN 0;

    -- GS programs don't have slots
    IF @program_type = 'GS'
        RETURN 0;

    -- Calculate current demand across future slots
    DECLARE @total_future_slots INT;
    DECLARE @assigned_slots INT;
    DECLARE @reserved_open INT;

    SELECT
        @total_future_slots = COUNT(*),
        @assigned_slots = SUM(CASE WHEN slot_status = 'ASSIGNED' THEN 1 ELSE 0 END),
        @reserved_open = SUM(CASE WHEN slot_status = 'OPEN' AND slot_type = 'RESERVED' THEN 1 ELSE 0 END)
    FROM dbo.tmi_slots
    WHERE program_id = @program_id
      AND slot_time_utc > SYSUTCDATETIME();

    IF @total_future_slots = 0 OR @reserved_open = 0
        RETURN 0;

    -- Demand percentage: how full is the program?
    DECLARE @demand_pct DECIMAL(5,2) = @assigned_slots * 100.0 / @total_future_slots;

    -- Adaptive target: as demand fills, reduce reserves toward floor
    -- floor = 20%, initial = reserve_pct
    DECLARE @floor_pct INT = 20;
    DECLARE @target_reserve_pct DECIMAL(5,2);

    -- Formula: target = floor + (initial - floor) * (1 - demand/100)
    -- At 0% demand: target = reserve_pct (full reserves)
    -- At 100% demand: target = floor_pct (minimum reserves)
    SET @target_reserve_pct = @floor_pct + (@reserve_pct - @floor_pct) * (1.0 - @demand_pct / 100.0);
    IF @target_reserve_pct < @floor_pct SET @target_reserve_pct = @floor_pct;

    -- How many reserved slots should remain?
    DECLARE @target_reserved INT = CEILING(@total_future_slots * @target_reserve_pct / 100.0);
    DECLARE @to_release INT = @reserved_open - @target_reserved;

    IF @to_release > 0
    BEGIN
        -- Release earliest reserved slots first (most likely needed for imminent demand)
        ;WITH to_convert AS (
            SELECT TOP (@to_release) slot_id
            FROM dbo.tmi_slots
            WHERE program_id = @program_id
              AND slot_status = 'OPEN'
              AND slot_type = 'RESERVED'
              AND slot_time_utc > SYSUTCDATETIME()
            ORDER BY slot_time_utc
        )
        UPDATE dbo.tmi_slots
        SET slot_type = 'REGULAR',
            updated_at = SYSUTCDATETIME()
        WHERE slot_id IN (SELECT slot_id FROM to_convert);

        SET @converted_count = @to_release;

        -- Log the adjustment
        EXEC dbo.sp_LogTmiEvent
            @entity_type = 'PROGRAM',
            @entity_id = @program_id,
            @event_type = 'RESERVES_ADJUSTED',
            @event_detail = 'Adaptive reserve adjustment',
            @source_type = 'SYSTEM',
            @program_id = @program_id;
    END

    RETURN 0;
END;
GO

PRINT '  Created: sp_TMI_AdjustReserves';

-- ============================================================================
-- PART 8: Backward-compatible wrapper sp_TMI_AssignFlightsRBS
--
-- simulate.php and other callers still reference the old name.
-- This wrapper simply delegates to sp_TMI_AssignFlightsFPFS.
-- ============================================================================
PRINT 'Part 8: Creating backward-compat sp_TMI_AssignFlightsRBS...';
GO

CREATE PROCEDURE dbo.sp_TMI_AssignFlightsRBS
    @program_id         INT,
    @flights            dbo.FlightListType READONLY,
    @assigned_count     INT OUTPUT,
    @exempt_count       INT OUTPUT
AS
BEGIN
    -- Delegate to FPFS algorithm (backward compatibility wrapper)
    EXEC dbo.sp_TMI_AssignFlightsFPFS
        @program_id = @program_id,
        @flights = @flights,
        @assigned_count = @assigned_count OUTPUT,
        @exempt_count = @exempt_count OUTPUT;
END;
GO

PRINT '  Created: sp_TMI_AssignFlightsRBS (wrapper → FPFS)';

-- ============================================================================
-- Summary
-- ============================================================================
PRINT '';
PRINT '=== Migration 038 Complete ===';
PRINT 'Schema: reserve_pct (tmi_programs), assignment_reason + dist_to_dest_nm (tmi_flight_control)';
PRINT 'TVP: FlightListType rebuilt with dist_to_dest_nm column';
PRINT 'New: sp_TMI_AssignFlightsFPFS (FPFS + RBD tiebreaker algorithm)';
PRINT 'New: sp_TMI_AdjustReserves (adaptive reserve management)';
PRINT 'Updated: sp_TMI_GenerateSlots (percentage-based reserve distribution)';
PRINT 'Recreated: sp_TMI_DetectPopups, sp_TMI_ApplyGroundStop (new TVP)';
PRINT 'Compat: sp_TMI_AssignFlightsRBS (wrapper → calls FPFS)';
GO
