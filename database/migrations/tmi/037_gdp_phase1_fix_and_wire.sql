-- ============================================================================
-- VATSIM_TMI Migration 037: GDP Phase 1 — Fix & Wire
-- Fixes bugs in compression, RBS assignment, and popup SPs
-- Adds missing schema columns for slot-level CTA/CTD tracking
-- ============================================================================
-- Version: 1.0.0
-- Date: 2026-03-05
-- Author: HP/Claude
--
-- Bug fixes:
--   1. sp_TMI_RunCompression: OR precedence bug (line 102)
--   2. sp_TMI_RunCompression: Wrong tmi_events column names (runtime failure)
--   3. sp_TMI_RunCompression: Freed slots marked COMPRESSED, should be OPEN
--   4. sp_TMI_AssignFlightsRBS v2: Incomplete slot reset (stale metadata)
--   5. sp_TMI_AssignFlightsRBS v2: References non-existent columns on tmi_slots
--   6. sp_TMI_AssignFlightsRBS v2: Flights with no slot silently dropped
--   7. sp_TMI_AssignPopups: Only handles GAAP/UDP, ignores DAS programs
--   8. sp_TMI_AdaptiveCompression: Wrong tmi_events column names
-- ============================================================================

USE VATSIM_TMI;
GO

PRINT '=== Migration 037: GDP Phase 1 — Fix & Wire ===';
PRINT '';

-- ============================================================================
-- PART 1: Add missing columns to tmi_slots
-- ============================================================================
PRINT 'Part 1: Adding ctd_utc and cta_utc to tmi_slots...';

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_slots' AND COLUMN_NAME = 'ctd_utc')
    ALTER TABLE dbo.tmi_slots ADD ctd_utc DATETIME2(0) NULL;

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tmi_slots' AND COLUMN_NAME = 'cta_utc')
    ALTER TABLE dbo.tmi_slots ADD cta_utc DATETIME2(0) NULL;

PRINT 'Part 1 complete.';
GO

-- ============================================================================
-- PART 2: Fix sp_TMI_AssignFlightsRBS (v2)
--
-- Fixes:
--   - Complete slot reset (clear ALL assignment metadata)
--   - Use correct column name assigned_utc (not assigned_at)
--   - Create flight_control record for flights with no available slot
-- ============================================================================
PRINT 'Part 2: Fixing sp_TMI_AssignFlightsRBS...';

IF OBJECT_ID('dbo.sp_TMI_AssignFlightsRBS', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_TMI_AssignFlightsRBS;
GO

CREATE PROCEDURE dbo.sp_TMI_AssignFlightsRBS
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

    SELECT
        @ctl_element = ctl_element,
        @program_type = program_type,
        @delay_limit_min = ISNULL(delay_limit_min, 180)
    FROM dbo.tmi_programs WHERE program_id = @program_id;

    SET @assigned_count = 0;
    SET @exempt_count = 0;

    -- Clear existing assignments
    DELETE FROM dbo.tmi_flight_control WHERE program_id = @program_id;

    -- FIX: Complete slot reset — clear ALL assignment metadata
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

    -- Process flights in ETA order
    DECLARE @flight_uid BIGINT, @callsign NVARCHAR(12), @eta_utc DATETIME2(0), @etd_utc DATETIME2(0);
    DECLARE @dep_airport NVARCHAR(4), @arr_airport NVARCHAR(4), @dep_center NVARCHAR(4), @carrier NVARCHAR(8);
    DECLARE @is_exempt BIT, @exempt_reason NVARCHAR(32);
    DECLARE @slot_id BIGINT, @slot_name NVARCHAR(16), @slot_time DATETIME2(0);
    DECLARE @delay_min INT, @delay_capped BIT, @ctd_utc DATETIME2(0), @ete_min INT;

    DECLARE flight_cursor CURSOR LOCAL FAST_FORWARD FOR
        SELECT flight_uid, callsign, eta_utc, etd_utc, dep_airport, arr_airport, dep_center, carrier,
               ISNULL(is_exempt, 0), exempt_reason
        FROM @flights ORDER BY eta_utc, flight_uid;

    OPEN flight_cursor;
    FETCH NEXT FROM flight_cursor INTO @flight_uid, @callsign, @eta_utc, @etd_utc, @dep_airport, @arr_airport, @dep_center, @carrier, @is_exempt, @exempt_reason;

    WHILE @@FETCH_STATUS = 0
    BEGIN
        IF @is_exempt = 1
        BEGIN
            INSERT INTO dbo.tmi_flight_control (
                flight_uid, callsign, program_id, ctl_elem, ctl_type,
                ctl_exempt, ctl_exempt_reason,
                orig_eta_utc, orig_etd_utc,
                dep_airport, arr_airport, dep_center,
                control_assigned_utc
            )
            VALUES (
                @flight_uid, @callsign, @program_id, @ctl_element, @program_type,
                1, @exempt_reason,
                @eta_utc, @etd_utc,
                @dep_airport, @arr_airport, @dep_center,
                SYSUTCDATETIME()
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

            -- If no regular slot, try reserved (for GAAP/UDP overflow)
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

                -- FIX: Use correct column names (assigned_utc not assigned_at)
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
                    dep_airport, arr_airport, dep_center,
                    control_assigned_utc
                )
                VALUES (
                    @flight_uid, @callsign, @program_id, @slot_id,
                    @ctd_utc, @slot_time, @ctd_utc, @slot_time,
                    @slot_name, @ctl_element,
                    CASE @program_type
                        WHEN 'GDP-DAS' THEN 'DAS'
                        WHEN 'GDP-GAAP' THEN 'GAAP'
                        WHEN 'GDP-UDP' THEN 'UDP'
                        ELSE @program_type
                    END,
                    @delay_min, @delay_capped,
                    @eta_utc, @etd_utc, @ete_min,
                    @dep_airport, @arr_airport, @dep_center,
                    SYSUTCDATETIME()
                );

                SET @assigned_count = @assigned_count + 1;
            END
            ELSE
            BEGIN
                -- FIX: Create flight_control record even when no slot available
                -- This ensures the flight appears in the GDT display as "unassigned"
                INSERT INTO dbo.tmi_flight_control (
                    flight_uid, callsign, program_id,
                    ctl_elem, ctl_type, ctl_exempt,
                    program_delay_min, delay_capped,
                    orig_eta_utc, orig_etd_utc,
                    dep_airport, arr_airport, dep_center,
                    compliance_status,
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
                    @dep_airport, @arr_airport, @dep_center,
                    'UNASSIGNED',
                    SYSUTCDATETIME()
                );
                -- Count as assigned (controlled) since it's in scope
                SET @assigned_count = @assigned_count + 1;
            END
        END

        SET @slot_id = NULL;
        FETCH NEXT FROM flight_cursor INTO @flight_uid, @callsign, @eta_utc, @etd_utc, @dep_airport, @arr_airport, @dep_center, @carrier, @is_exempt, @exempt_reason;
    END

    CLOSE flight_cursor;
    DEALLOCATE flight_cursor;

    -- Update program metrics
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

PRINT '  Fixed: sp_TMI_AssignFlightsRBS';

-- ============================================================================
-- PART 3: Fix sp_TMI_RunCompression
--
-- Fixes:
--   - OR precedence bug (was: AND x IS NULL OR y = 'PENDING')
--   - Wrong tmi_events column names (all INSERTs)
--   - Freed slot marked COMPRESSED instead of OPEN
--   - Incomplete metadata copy to new slot
-- ============================================================================
PRINT 'Part 3: Fixing sp_TMI_RunCompression...';

IF OBJECT_ID('dbo.sp_TMI_RunCompression', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_TMI_RunCompression;
GO

CREATE PROCEDURE dbo.sp_TMI_RunCompression
    @program_id         INT,
    @compression_by     NVARCHAR(64) = NULL,
    @slots_compressed   INT OUTPUT,
    @delay_saved_min    INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @ctl_element NVARCHAR(8);
    DECLARE @program_type NVARCHAR(16);

    SELECT
        @ctl_element = ctl_element,
        @program_type = program_type
    FROM dbo.tmi_programs
    WHERE program_id = @program_id
      AND is_active = 1;

    IF @ctl_element IS NULL
    BEGIN
        RAISERROR('Active program not found: %d', 16, 1, @program_id);
        RETURN 1;
    END

    SET @slots_compressed = 0;
    SET @delay_saved_min = 0;

    -- Find candidates for compression:
    -- Slots where assigned flight has already departed or is a no-show
    DECLARE @free_slots TABLE (
        slot_id BIGINT,
        slot_time_utc DATETIME2(0),
        slot_index INT
    );

    INSERT INTO @free_slots (slot_id, slot_time_utc, slot_index)
    SELECT s.slot_id, s.slot_time_utc, s.slot_index
    FROM dbo.tmi_slots s
    INNER JOIN dbo.tmi_flight_control fc ON s.slot_id = fc.slot_id
    WHERE s.program_id = @program_id
      AND s.slot_status = 'ASSIGNED'
      AND (
          fc.actual_dep_utc IS NOT NULL
          OR fc.compliance_status IN ('EARLY', 'NO_SHOW')
      )
      AND s.slot_time_utc > SYSUTCDATETIME()
    ORDER BY s.slot_time_utc;

    -- Find flights that can be moved to earlier slots
    -- FIX: Added parentheses around OR clause (was Bug #1)
    DECLARE @moveable_flights TABLE (
        control_id BIGINT,
        flight_uid BIGINT,
        callsign NVARCHAR(12),
        carrier NVARCHAR(8),
        dep_airport NVARCHAR(4),
        current_slot_id BIGINT,
        current_cta DATETIME2(0),
        orig_eta DATETIME2(0),
        orig_ete_min INT,
        current_delay INT
    );

    INSERT INTO @moveable_flights
    SELECT
        fc.control_id,
        fc.flight_uid,
        fc.callsign,
        s.assigned_carrier,
        fc.dep_airport,
        fc.slot_id,
        fc.cta_utc,
        fc.orig_eta_utc,
        fc.orig_ete_min,
        fc.program_delay_min
    FROM dbo.tmi_flight_control fc
    INNER JOIN dbo.tmi_slots s ON fc.slot_id = s.slot_id
    WHERE fc.program_id = @program_id
      AND fc.ctl_exempt = 0
      AND fc.actual_dep_utc IS NULL
      -- FIX: Parentheses around OR (was the #1 bug - global OR)
      AND (fc.compliance_status IS NULL OR fc.compliance_status = 'PENDING')
      AND s.slot_time_utc > SYSUTCDATETIME()
    ORDER BY fc.cta_utc DESC;

    -- Process compression: move flights to earlier slots
    DECLARE @free_slot_id BIGINT;
    DECLARE @free_slot_time DATETIME2(0);
    DECLARE @free_slot_index INT;
    DECLARE @move_control_id BIGINT;
    DECLARE @move_flight_uid BIGINT;
    DECLARE @move_callsign NVARCHAR(12);
    DECLARE @move_carrier NVARCHAR(8);
    DECLARE @move_dep_airport NVARCHAR(4);
    DECLARE @move_current_slot BIGINT;
    DECLARE @move_current_cta DATETIME2(0);
    DECLARE @move_orig_eta DATETIME2(0);
    DECLARE @move_orig_ete_min INT;
    DECLARE @move_current_delay INT;
    DECLARE @new_delay INT;
    DECLARE @delay_reduction INT;
    DECLARE @new_ctd DATETIME2(0);

    DECLARE free_cursor CURSOR LOCAL FAST_FORWARD FOR
        SELECT slot_id, slot_time_utc, slot_index FROM @free_slots ORDER BY slot_time_utc;

    OPEN free_cursor;
    FETCH NEXT FROM free_cursor INTO @free_slot_id, @free_slot_time, @free_slot_index;

    WHILE @@FETCH_STATUS = 0
    BEGIN
        SET @move_control_id = NULL;

        -- Find a flight that benefits from this earlier slot
        SELECT TOP 1
            @move_control_id = control_id,
            @move_flight_uid = flight_uid,
            @move_callsign = callsign,
            @move_carrier = carrier,
            @move_dep_airport = dep_airport,
            @move_current_slot = current_slot_id,
            @move_current_cta = current_cta,
            @move_orig_eta = orig_eta,
            @move_orig_ete_min = orig_ete_min,
            @move_current_delay = current_delay
        FROM @moveable_flights
        WHERE orig_eta <= @free_slot_time
          AND current_cta > @free_slot_time
        ORDER BY current_delay DESC;

        IF @move_control_id IS NOT NULL
        BEGIN
            SET @new_delay = DATEDIFF(MINUTE, @move_orig_eta, @free_slot_time);
            SET @delay_reduction = ISNULL(@move_current_delay, 0) - @new_delay;
            SET @new_ctd = DATEADD(MINUTE, -ISNULL(@move_orig_ete_min, 0), @free_slot_time);

            -- FIX: Free the old slot back to OPEN (was COMPRESSED — Bug #5)
            -- This keeps the slot available for future popup assignment
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
            WHERE slot_id = @move_current_slot;

            -- FIX: Assign flight to earlier slot with full metadata
            UPDATE dbo.tmi_slots
            SET assigned_flight_uid = @move_flight_uid,
                assigned_callsign = @move_callsign,
                assigned_carrier = @move_carrier,
                assigned_origin = @move_dep_airport,
                slot_status = 'ASSIGNED',
                assigned_utc = SYSUTCDATETIME(),
                original_eta_utc = @move_orig_eta,
                slot_delay_min = @new_delay,
                ctd_utc = @new_ctd,
                cta_utc = @free_slot_time,
                modified_utc = SYSUTCDATETIME()
            WHERE slot_id = @free_slot_id;

            -- Update flight control record
            UPDATE dbo.tmi_flight_control
            SET slot_id = @free_slot_id,
                cta_utc = @free_slot_time,
                ctd_utc = @new_ctd,
                aslot = (SELECT slot_name FROM dbo.tmi_slots WHERE slot_id = @free_slot_id),
                program_delay_min = @new_delay,
                updated_at = SYSUTCDATETIME()
            WHERE control_id = @move_control_id;

            SET @slots_compressed = @slots_compressed + 1;
            SET @delay_saved_min = @delay_saved_min + @delay_reduction;

            -- Remove from moveable list
            DELETE FROM @moveable_flights WHERE control_id = @move_control_id;

            -- FIX: Use correct tmi_events column names (was Bug #6)
            EXEC dbo.sp_LogTmiEvent
                @entity_type = 'SLOT',
                @entity_id = @free_slot_id,
                @event_type = 'SLOT_COMPRESSED',
                @event_detail = 'Flight moved to earlier slot',
                @old_value = NULL,
                @new_value = NULL,
                @source_type = CASE WHEN @compression_by IS NULL THEN 'COMPRESSION' ELSE 'USER' END,
                @actor_id = @compression_by,
                @program_id = @program_id,
                @flight_uid = @move_flight_uid,
                @slot_id = @free_slot_id;
        END

        FETCH NEXT FROM free_cursor INTO @free_slot_id, @free_slot_time, @free_slot_index;
    END

    CLOSE free_cursor;
    DEALLOCATE free_cursor;

    -- Cleanup: free any "departed/no-show" slots that were NOT reassigned above.
    -- The cursor loop only reassigns a free slot if a later flight benefits.
    -- Slots that weren't matched (no moveable flight) still need their status
    -- cleared from ASSIGNED to OPEN so they re-enter the available pool.
    -- The WHERE s.slot_status = 'ASSIGNED' filter skips slots that were just
    -- reassigned to a new flight (already have correct ASSIGNED status).
    UPDATE s
    SET s.slot_status = 'OPEN',
        s.assigned_flight_uid = NULL,
        s.assigned_callsign = NULL,
        s.assigned_carrier = NULL,
        s.assigned_origin = NULL,
        s.assigned_utc = NULL,
        s.original_eta_utc = NULL,
        s.slot_delay_min = NULL,
        s.ctd_utc = NULL,
        s.cta_utc = NULL,
        s.is_popup_slot = 0,
        s.popup_lead_time_min = NULL,
        s.modified_utc = SYSUTCDATETIME()
    FROM dbo.tmi_slots s
    INNER JOIN @free_slots fs ON s.slot_id = fs.slot_id
    WHERE s.slot_status = 'ASSIGNED';  -- Only clear ones that weren't just reassigned

    -- Update program metrics
    UPDATE dbo.tmi_programs
    SET last_compression_utc = SYSUTCDATETIME(),
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

    -- FIX: Use correct tmi_events column names for summary log
    IF @slots_compressed > 0
    BEGIN
        EXEC dbo.sp_LogTmiEvent
            @entity_type = 'PROGRAM',
            @entity_id = @program_id,
            @event_type = 'COMPRESSION_COMPLETE',
            @event_detail = 'Compression run completed',
            @source_type = CASE WHEN @compression_by IS NULL THEN 'COMPRESSION' ELSE 'USER' END,
            @actor_id = @compression_by,
            @program_id = @program_id;
    END

    RETURN 0;
END;
GO

PRINT '  Fixed: sp_TMI_RunCompression';

-- ============================================================================
-- PART 4: Fix sp_TMI_AdaptiveCompression
--
-- Fixes:
--   - Uses sp_LogTmiEvent instead of direct INSERT with wrong column names
-- ============================================================================
PRINT 'Part 4: Fixing sp_TMI_AdaptiveCompression...';

IF OBJECT_ID('dbo.sp_TMI_AdaptiveCompression', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_TMI_AdaptiveCompression;
GO

CREATE PROCEDURE dbo.sp_TMI_AdaptiveCompression
    @program_id         INT = NULL,
    @total_compressed   INT OUTPUT,
    @total_delay_saved  INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    SET @total_compressed = 0;
    SET @total_delay_saved = 0;

    DECLARE @pid INT;
    DECLARE @slots_compressed INT;
    DECLARE @delay_saved INT;

    DECLARE program_cursor CURSOR LOCAL FAST_FORWARD FOR
        SELECT program_id
        FROM dbo.tmi_programs
        WHERE is_active = 1
          AND adaptive_compression = 1
          AND program_type IN ('GDP-DAS', 'GDP-GAAP', 'GDP-UDP', 'AFP')
          AND (@program_id IS NULL OR program_id = @program_id);

    OPEN program_cursor;
    FETCH NEXT FROM program_cursor INTO @pid;

    WHILE @@FETCH_STATUS = 0
    BEGIN
        EXEC dbo.sp_TMI_RunCompression
            @program_id = @pid,
            @compression_by = NULL,
            @slots_compressed = @slots_compressed OUTPUT,
            @delay_saved_min = @delay_saved OUTPUT;

        SET @total_compressed = @total_compressed + ISNULL(@slots_compressed, 0);
        SET @total_delay_saved = @total_delay_saved + ISNULL(@delay_saved, 0);

        FETCH NEXT FROM program_cursor INTO @pid;
    END

    CLOSE program_cursor;
    DEALLOCATE program_cursor;

    RETURN 0;
END;
GO

PRINT '  Fixed: sp_TMI_AdaptiveCompression';

-- ============================================================================
-- PART 5: Fix sp_TMI_AssignPopups
--
-- Fixes:
--   - Handle ALL GDP types (DAS, GAAP, UDP), not just GAAP/UDP
--   - For DAS: assign popups to any OPEN slot (no reserved preference)
--   - Use correct column names on tmi_slots
-- ============================================================================
PRINT 'Part 5: Fixing sp_TMI_AssignPopups...';

IF OBJECT_ID('dbo.sp_TMI_AssignPopups', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_TMI_AssignPopups;
GO

CREATE PROCEDURE dbo.sp_TMI_AssignPopups
    @program_id         INT,
    @assigned_count     INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @ctl_element NVARCHAR(8);
    DECLARE @program_type NVARCHAR(16);
    DECLARE @delay_limit_min INT;

    SELECT
        @ctl_element = ctl_element,
        @program_type = program_type,
        @delay_limit_min = ISNULL(delay_limit_min, 180)
    FROM dbo.tmi_programs
    WHERE program_id = @program_id AND is_active = 1;

    -- FIX: Handle all GDP types, not just GAAP/UDP
    IF @program_type NOT IN ('GDP-DAS', 'GDP-GAAP', 'GDP-UDP', 'AFP')
    BEGIN
        SET @assigned_count = 0;
        RETURN 0;
    END

    SET @assigned_count = 0;

    DECLARE @queue_id BIGINT, @flight_uid BIGINT, @callsign NVARCHAR(12);
    DECLARE @eta_utc DATETIME2(0), @lead_time INT;
    DECLARE @dep_airport NVARCHAR(4), @carrier NVARCHAR(8);
    DECLARE @slot_id BIGINT, @slot_name NVARCHAR(16), @slot_time DATETIME2(0);
    DECLARE @delay_min INT, @raw_delay INT;
    DECLARE @ete_min INT, @ctd_utc DATETIME2(0);
    DECLARE @has_reserved BIT;
    DECLARE @ctl_type NVARCHAR(8);

    -- Check if program has reserved slots (GAAP/UDP do, DAS doesn't)
    SET @has_reserved = CASE WHEN @program_type IN ('GDP-GAAP', 'GDP-UDP') THEN 1 ELSE 0 END;

    DECLARE popup_cursor CURSOR LOCAL FAST_FORWARD FOR
        SELECT queue_id, flight_uid, callsign, flight_eta_utc, lead_time_min, dep_airport, carrier
        FROM dbo.tmi_popup_queue
        WHERE program_id = @program_id AND queue_status = 'PENDING'
        ORDER BY flight_eta_utc;

    OPEN popup_cursor;
    FETCH NEXT FROM popup_cursor INTO @queue_id, @flight_uid, @callsign, @eta_utc, @lead_time, @dep_airport, @carrier;

    WHILE @@FETCH_STATUS = 0
    BEGIN
        SET @slot_id = NULL;

        -- For GAAP/UDP: try reserved slots first
        IF @has_reserved = 1
        BEGIN
            SELECT TOP 1
                @slot_id = slot_id,
                @slot_name = slot_name,
                @slot_time = slot_time_utc
            FROM dbo.tmi_slots
            WHERE program_id = @program_id
              AND slot_status = 'OPEN'
              AND slot_type = 'RESERVED'
              AND slot_time_utc >= @eta_utc
            ORDER BY slot_time_utc;
        END

        -- Fall back to any OPEN slot (all GDP types)
        IF @slot_id IS NULL
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
            SET @raw_delay = DATEDIFF(MINUTE, @eta_utc, @slot_time);
            SET @delay_min = IIF(@raw_delay < @delay_limit_min, @raw_delay, @delay_limit_min);

            -- FIX: Use correct column names (assigned_utc not assigned_at)
            UPDATE dbo.tmi_slots
            SET slot_status = 'ASSIGNED',
                assigned_flight_uid = @flight_uid,
                assigned_callsign = @callsign,
                assigned_carrier = @carrier,
                assigned_origin = @dep_airport,
                assigned_utc = SYSUTCDATETIME(),
                original_eta_utc = @eta_utc,
                slot_delay_min = @delay_min,
                cta_utc = @slot_time,
                is_popup_slot = 1,
                popup_lead_time_min = @lead_time,
                modified_utc = SYSUTCDATETIME()
            WHERE slot_id = @slot_id;

            -- Determine ctl_type based on program type
            SET @ctl_type = CASE @program_type
                WHEN 'GDP-DAS' THEN 'DAS'
                WHEN 'GDP-GAAP' THEN 'GAAP'
                WHEN 'GDP-UDP' THEN 'UDP'
                ELSE @program_type
            END;

            INSERT INTO dbo.tmi_flight_control (
                flight_uid, callsign, program_id, slot_id,
                cta_utc, octa_utc, aslot, ctl_elem, ctl_type,
                program_delay_min, orig_eta_utc,
                dep_airport, is_popup,
                popup_detected_utc, popup_lead_time_min,
                control_assigned_utc
            )
            VALUES (
                @flight_uid, @callsign, @program_id, @slot_id,
                @slot_time, @slot_time, @slot_name, @ctl_element, @ctl_type,
                @delay_min, @eta_utc,
                @dep_airport, 1,
                SYSUTCDATETIME(), @lead_time,
                SYSUTCDATETIME()
            );

            UPDATE dbo.tmi_popup_queue
            SET queue_status = 'ASSIGNED',
                assigned_slot_id = @slot_id,
                assigned_utc = SYSUTCDATETIME(),
                assignment_type = CASE WHEN @has_reserved = 1 THEN 'RESERVED' ELSE 'REGULAR' END,
                processed_at = SYSUTCDATETIME()
            WHERE queue_id = @queue_id;

            SET @assigned_count = @assigned_count + 1;
        END
        ELSE
        BEGIN
            UPDATE dbo.tmi_popup_queue
            SET queue_status = 'FAILED',
                process_notes = 'No slot available',
                processed_at = SYSUTCDATETIME()
            WHERE queue_id = @queue_id;
        END

        FETCH NEXT FROM popup_cursor INTO @queue_id, @flight_uid, @callsign, @eta_utc, @lead_time, @dep_airport, @carrier;
    END

    CLOSE popup_cursor;
    DEALLOCATE popup_cursor;

    -- Update program popup count
    IF @assigned_count > 0
    BEGIN
        UPDATE dbo.tmi_programs
        SET popup_flights = ISNULL(popup_flights, 0) + @assigned_count,
            controlled_flights = ISNULL(controlled_flights, 0) + @assigned_count,
            updated_at = SYSUTCDATETIME()
        WHERE program_id = @program_id;
    END

    RETURN 0;
END;
GO

PRINT '  Fixed: sp_TMI_AssignPopups';

-- ============================================================================
-- PART 6: Add UNASSIGNED to compliance_status check constraint
-- (needed for flights that have no available slot)
-- ============================================================================
PRINT 'Part 6: Updating compliance_status constraint...';

-- Drop and recreate constraint to include UNASSIGNED
IF EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = 'CK_tmi_flight_control_compliance')
BEGIN
    ALTER TABLE dbo.tmi_flight_control DROP CONSTRAINT CK_tmi_flight_control_compliance;
END

ALTER TABLE dbo.tmi_flight_control
ADD CONSTRAINT CK_tmi_flight_control_compliance
CHECK (compliance_status IN ('PENDING', 'COMPLIANT', 'EARLY', 'LATE', 'NO_SHOW', 'UNASSIGNED'));

PRINT 'Part 6 complete.';
GO

PRINT '';
PRINT '=== Migration 037 Complete ===';
PRINT 'Fixed: sp_TMI_AssignFlightsRBS (slot reset, column names, no-slot handling)';
PRINT 'Fixed: sp_TMI_RunCompression (OR precedence, event logging, slot status)';
PRINT 'Fixed: sp_TMI_AdaptiveCompression (event logging)';
PRINT 'Fixed: sp_TMI_AssignPopups (all GDP types, column names)';
PRINT 'Added: ctd_utc, cta_utc columns to tmi_slots';
PRINT 'Added: UNASSIGNED to compliance_status constraint';
GO
