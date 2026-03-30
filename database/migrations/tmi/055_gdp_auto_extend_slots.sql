-- ============================================================================
-- Migration 055: GDP Auto-Extend Slots
-- ============================================================================
-- When sp_TMI_AssignFlightsFPFS can't find enough slots for all flights,
-- some get assignment_reason='NO_SLOT'. This SP extends the slot window
-- in 15-minute increments (at program_rate) and assigns overflow flights
-- until all flights are accommodated or a safety limit is reached.
--
-- Called after sp_TMI_AssignFlightsFPFS in simulate.php and power_run.php.
-- ============================================================================

SET NOCOUNT ON;
GO

PRINT '=== Migration 055: GDP Auto-Extend Slots ===';
PRINT '';

-- ============================================================================
-- PART 1: Create sp_TMI_AutoExtendSlots
-- ============================================================================

PRINT 'Creating sp_TMI_AutoExtendSlots...';

IF OBJECT_ID('dbo.sp_TMI_AutoExtendSlots', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_TMI_AutoExtendSlots;
GO

CREATE PROCEDURE dbo.sp_TMI_AutoExtendSlots
    @program_id             INT,
    @max_extension_quarters INT = 48,       -- safety: max 48 * 15min = 12 hours
    @extensions_applied     INT OUTPUT,
    @slots_added            INT OUTPUT,
    @flights_reassigned     INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    SET @extensions_applied = 0;
    SET @slots_added = 0;
    SET @flights_reassigned = 0;

    -- ================================================================
    -- Check for NO_SLOT flights
    -- ================================================================
    DECLARE @no_slot_count INT;
    SELECT @no_slot_count = COUNT(*)
    FROM dbo.tmi_flight_control
    WHERE program_id = @program_id AND assignment_reason = 'NO_SLOT';

    IF @no_slot_count = 0
        RETURN 0;

    -- ================================================================
    -- Load program parameters
    -- ================================================================
    DECLARE @ctl_element NVARCHAR(8);
    DECLARE @program_type NVARCHAR(16);
    DECLARE @program_rate INT;
    DECLARE @delay_limit_min INT;
    DECLARE @start_utc DATETIME2(0);

    SELECT
        @ctl_element     = ctl_element,
        @program_type    = program_type,
        @program_rate    = ISNULL(program_rate, 30),
        @delay_limit_min = ISNULL(delay_limit_min, 180),
        @start_utc       = start_utc
    FROM dbo.tmi_programs
    WHERE program_id = @program_id;

    IF @ctl_element IS NULL
        RETURN 1;   -- program not found

    DECLARE @ctl_type NVARCHAR(8) = CASE @program_type
        WHEN 'GDP-DAS'  THEN 'DAS'
        WHEN 'GDP-GAAP' THEN 'GAAP'
        WHEN 'GDP-UDP'  THEN 'UDP'
        ELSE @program_type
    END;

    -- Slot spacing for extension quarters (use program_rate, always per-hour)
    DECLARE @slot_interval_sec INT = 3600 / @program_rate;

    -- ================================================================
    -- Slot-generation variables (declared once, reused each iteration)
    -- ================================================================
    DECLARE @last_slot_time DATETIME2(0);
    DECLARE @max_slot_index INT;
    DECLARE @current_time DATETIME2(0);
    DECLARE @quarter_end DATETIME2(0);
    DECLARE @slot_index INT;
    DECLARE @slot_minute INT;
    DECLARE @last_minute INT;
    DECLARE @suffix_counter INT;
    DECLARE @suffix_char CHAR(1);
    DECLARE @slot_name NVARCHAR(16);

    -- ================================================================
    -- Assignment cursor variables (declared once, reused each iteration)
    -- ================================================================
    DECLARE @control_id BIGINT;
    DECLARE @flight_uid BIGINT;
    DECLARE @callsign NVARCHAR(12);
    DECLARE @eta_utc DATETIME2(0);
    DECLARE @etd_utc DATETIME2(0);
    DECLARE @dist_nm FLOAT;
    DECLARE @dep_airport NVARCHAR(4);
    DECLARE @arr_airport NVARCHAR(4);
    DECLARE @dep_center NVARCHAR(4);
    DECLARE @arr_center NVARCHAR(4);
    DECLARE @new_slot_id BIGINT;
    DECLARE @new_slot_name NVARCHAR(16);
    DECLARE @new_slot_time DATETIME2(0);
    DECLARE @delay_min INT;
    DECLARE @delay_capped BIT;
    DECLARE @ete_min INT;
    DECLARE @new_ctd DATETIME2(0);

    -- ================================================================
    -- Extension loop: add 15-min of slots + assign NO_SLOT flights
    -- ================================================================
    WHILE @no_slot_count > 0 AND @extensions_applied < @max_extension_quarters
    BEGIN
        -- Get current last slot
        SELECT @last_slot_time = MAX(slot_time_utc),
               @max_slot_index = MAX(slot_index)
        FROM dbo.tmi_slots
        WHERE program_id = @program_id;

        -- Next 15-minute window
        SET @current_time = DATEADD(SECOND, @slot_interval_sec, @last_slot_time);
        SET @quarter_end  = DATEADD(MINUTE, 15, @last_slot_time);
        SET @slot_index   = @max_slot_index + 1;
        SET @last_minute  = -1;
        SET @suffix_counter = 0;

        -- ============================================================
        -- Generate new slots for this 15-minute extension
        -- ============================================================
        WHILE @current_time < @quarter_end
        BEGIN
            SET @slot_minute = DATEPART(HOUR, @current_time) * 100
                             + DATEPART(MINUTE, @current_time);

            IF @slot_minute = @last_minute
                SET @suffix_counter = @suffix_counter + 1;
            ELSE
            BEGIN
                SET @suffix_counter = 0;
                SET @last_minute = @slot_minute;
            END

            SET @suffix_char = CHAR(65 + (@suffix_counter % 26));
            SET @slot_name = @ctl_element + '.'
                           + FORMAT(@current_time, 'ddHHmm')
                           + @suffix_char;

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

            SET @slot_index   = @slot_index + 1;
            SET @slots_added  = @slots_added + 1;
            SET @current_time = DATEADD(SECOND, @slot_interval_sec, @current_time);
        END

        -- ============================================================
        -- Assign NO_SLOT flights to newly created open slots (FPFS order)
        -- ============================================================
        DECLARE noslot_cursor CURSOR LOCAL FAST_FORWARD FOR
            SELECT control_id, flight_uid, callsign,
                   orig_eta_utc, orig_etd_utc, dist_to_dest_nm,
                   dep_airport, arr_airport, dep_center, arr_center
            FROM dbo.tmi_flight_control
            WHERE program_id = @program_id
              AND assignment_reason = 'NO_SLOT'
            ORDER BY
                DATEDIFF(MINUTE, @start_utc, orig_eta_utc) / 5,
                ISNULL(dist_to_dest_nm, 99999) ASC,
                flight_uid;

        OPEN noslot_cursor;
        FETCH NEXT FROM noslot_cursor
            INTO @control_id, @flight_uid, @callsign, @eta_utc, @etd_utc,
                 @dist_nm, @dep_airport, @arr_airport, @dep_center, @arr_center;

        WHILE @@FETCH_STATUS = 0
        BEGIN
            SET @new_slot_id = NULL;

            -- Find first OPEN slot at or after this flight's ETA
            SELECT TOP 1
                @new_slot_id   = slot_id,
                @new_slot_name = slot_name,
                @new_slot_time = slot_time_utc
            FROM dbo.tmi_slots
            WHERE program_id = @program_id
              AND slot_status = 'OPEN'
              AND slot_time_utc >= @eta_utc
            ORDER BY slot_time_utc;

            IF @new_slot_id IS NOT NULL
            BEGIN
                -- Compute delay and CTD
                SET @delay_min   = DATEDIFF(MINUTE, @eta_utc, @new_slot_time);
                SET @delay_capped = CASE WHEN @delay_min > @delay_limit_min THEN 1 ELSE 0 END;
                IF @delay_capped = 1
                    SET @delay_min = @delay_limit_min;

                SET @ete_min = CASE
                    WHEN @etd_utc IS NOT NULL
                    THEN DATEDIFF(MINUTE, @etd_utc, @eta_utc)
                    ELSE 0
                END;
                SET @new_ctd = CASE
                    WHEN @ete_min > 0
                    THEN DATEADD(MINUTE, -@ete_min, @new_slot_time)
                    ELSE NULL
                END;

                -- Assign the slot
                UPDATE dbo.tmi_slots
                SET slot_status          = 'ASSIGNED',
                    assigned_flight_uid  = @flight_uid,
                    assigned_callsign    = @callsign,
                    assigned_carrier     = LEFT(@callsign, 3),
                    assigned_origin      = @dep_airport,
                    assigned_at          = SYSUTCDATETIME(),
                    original_eta_utc     = @eta_utc,
                    slot_delay_min       = @delay_min,
                    ctd_utc              = @new_ctd,
                    cta_utc              = @new_slot_time,
                    updated_at           = SYSUTCDATETIME()
                WHERE slot_id = @new_slot_id;

                -- Convert NO_SLOT record to assigned
                UPDATE dbo.tmi_flight_control
                SET slot_id              = @new_slot_id,
                    aslot                = @new_slot_name,
                    ctd_utc              = @new_ctd,
                    cta_utc              = @new_slot_time,
                    octd_utc             = @new_ctd,
                    octa_utc             = @new_slot_time,
                    program_delay_min    = @delay_min,
                    delay_capped         = @delay_capped,
                    orig_ete_min         = @ete_min,
                    assignment_reason    = 'FPFS_RBD',
                    compliance_status    = NULL,
                    control_assigned_utc = SYSUTCDATETIME()
                WHERE control_id = @control_id;

                SET @flights_reassigned = @flights_reassigned + 1;
            END

            FETCH NEXT FROM noslot_cursor
                INTO @control_id, @flight_uid, @callsign, @eta_utc, @etd_utc,
                     @dist_nm, @dep_airport, @arr_airport, @dep_center, @arr_center;
        END

        CLOSE noslot_cursor;
        DEALLOCATE noslot_cursor;

        SET @extensions_applied = @extensions_applied + 1;

        -- Recount NO_SLOT flights
        SELECT @no_slot_count = COUNT(*)
        FROM dbo.tmi_flight_control
        WHERE program_id = @program_id AND assignment_reason = 'NO_SLOT';
    END

    -- ================================================================
    -- Update program end_utc and metrics to reflect extensions
    -- ================================================================
    DECLARE @new_end_utc DATETIME2(0);
    SELECT @new_end_utc = DATEADD(SECOND, @slot_interval_sec, MAX(slot_time_utc))
    FROM dbo.tmi_slots
    WHERE program_id = @program_id;

    UPDATE dbo.tmi_programs
    SET end_utc          = @new_end_utc,
        total_flights    = (SELECT COUNT(*) FROM dbo.tmi_flight_control WHERE program_id = @program_id),
        controlled_flights = (SELECT COUNT(*) FROM dbo.tmi_flight_control WHERE program_id = @program_id AND ctl_exempt = 0),
        exempt_flights   = (SELECT COUNT(*) FROM dbo.tmi_flight_control WHERE program_id = @program_id AND ctl_exempt = 1),
        avg_delay_min    = (SELECT AVG(CAST(program_delay_min AS DECIMAL(8,2)))
                            FROM dbo.tmi_flight_control
                            WHERE program_id = @program_id AND ctl_exempt = 0 AND slot_id IS NOT NULL),
        max_delay_min    = (SELECT MAX(program_delay_min)
                            FROM dbo.tmi_flight_control
                            WHERE program_id = @program_id AND ctl_exempt = 0 AND slot_id IS NOT NULL),
        total_delay_min  = (SELECT SUM(program_delay_min)
                            FROM dbo.tmi_flight_control
                            WHERE program_id = @program_id AND ctl_exempt = 0 AND slot_id IS NOT NULL),
        updated_at       = SYSUTCDATETIME()
    WHERE program_id = @program_id;

    -- Recompute reversal metrics with extended assignments
    DECLARE @rev_count INT, @rev_pct DECIMAL(5,2), @rev_pairs BIGINT;
    IF OBJECT_ID('dbo.sp_TMI_ComputeReversals', 'P') IS NOT NULL
    BEGIN
        EXEC dbo.sp_TMI_ComputeReversals
            @program_id     = @program_id,
            @reversal_count = @rev_count OUTPUT,
            @reversal_pct   = @rev_pct OUTPUT,
            @eligible_pairs = @rev_pairs OUTPUT;
    END

    RETURN 0;
END;
GO

PRINT '  Created: sp_TMI_AutoExtendSlots';
PRINT '';
PRINT '=== Migration 055 complete ===';
GO
