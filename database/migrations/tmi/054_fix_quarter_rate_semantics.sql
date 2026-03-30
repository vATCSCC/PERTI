-- ============================================================================
-- VATSIM_TMI Migration 054: Fix Quarter Rate Semantics
-- ============================================================================
-- Version: 1.0.0
-- Date: 2026-03-30
-- Author: HP/Claude
--
-- Changes quarter rate semantics from "arrivals per hour" to "arrivals per
-- 15-minute period". This aligns with the user's expectation that:
--   hourly_rate = quarter_1 + quarter_2 + quarter_3 + quarter_4
--
-- Example: If hourly rate = 30/hr, quarter values should be ~7-8 each
--   (30/4 = 7.5, distributed as 8/7/8/7 = 30 total)
--
-- SP changes:
--   - Variable rate path: slot_interval = 900/@rate (was 3600/@rate)
--   - Hourly JSON fallback: divides hourly value by 4 for each quarter
-- ============================================================================

USE VATSIM_TMI;
GO

-- ============================================================================
-- Update sp_TMI_GenerateSlots for per-15-min quarter rate semantics
-- ============================================================================

PRINT 'Updating sp_TMI_GenerateSlots for per-15-min quarter rate semantics...';
GO

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
    DECLARE @rates_quarter_json NVARCHAR(MAX);
    DECLARE @rates_hourly_json NVARCHAR(MAX);

    SELECT
        @ctl_element = ctl_element,
        @program_type = program_type,
        @start_utc = start_utc,
        @end_utc = end_utc,
        @program_rate = ISNULL(program_rate, 30),
        @reserve_rate = ISNULL(reserve_rate, 0),
        @reserve_pct = ISNULL(reserve_pct, 0),
        @rates_quarter_json = rates_quarter_json,
        @rates_hourly_json = rates_hourly_json
    FROM dbo.tmi_programs
    WHERE program_id = @program_id;

    IF @ctl_element IS NULL
    BEGIN
        RAISERROR('Program not found: %d', 16, 1, @program_id);
        RETURN 1;
    END

    -- Delete existing flight control + slots (for re-modeling)
    -- Must delete flight_control first: FK_tmi_flight_control_slot references tmi_slots
    DELETE FROM dbo.tmi_flight_control WHERE program_id = @program_id;
    DELETE FROM dbo.tmi_slots WHERE program_id = @program_id;

    -- ====================================================================
    -- Parse rate schedules into temp table for fast lookup
    -- Priority: rates_quarter_json > rates_hourly_json > program_rate
    --
    -- Quarter values are "arrivals per 15-minute period":
    --   slot_interval = 900 / rate_per_quarter
    -- ====================================================================
    DECLARE @use_variable_rates BIT = 0;

    CREATE TABLE #rate_schedule (
        quarter_key VARCHAR(5) PRIMARY KEY,  -- "HH:MM"
        rate_per_quarter INT NOT NULL         -- arrivals per 15-minute period
    );

    -- Try quarter-hour rates first (values are per-15-min)
    IF @rates_quarter_json IS NOT NULL AND LEN(@rates_quarter_json) > 2
    BEGIN
        INSERT INTO #rate_schedule (quarter_key, rate_per_quarter)
        SELECT [key], CAST([value] AS INT)
        FROM OPENJSON(@rates_quarter_json);

        IF @@ROWCOUNT > 0
            SET @use_variable_rates = 1;
    END

    -- Fall back to hourly rates (divide each hour by 4 for quarter values)
    IF @use_variable_rates = 0 AND @rates_hourly_json IS NOT NULL AND LEN(@rates_hourly_json) > 2
    BEGIN
        -- Each quarter gets floor(hourly/4); remainder distributed to early quarters
        -- For simplicity, use CEILING for :00 and :15, FLOOR for :30 and :45
        INSERT INTO #rate_schedule (quarter_key, rate_per_quarter)
        SELECT
            RIGHT('0' + [key], 2) + ':00',
            CEILING(CAST([value] AS FLOAT) / 4)
        FROM OPENJSON(@rates_hourly_json)
        UNION ALL
        SELECT
            RIGHT('0' + [key], 2) + ':15',
            CEILING(CAST([value] AS FLOAT) / 4)
        FROM OPENJSON(@rates_hourly_json)
        UNION ALL
        SELECT
            RIGHT('0' + [key], 2) + ':30',
            FLOOR(CAST([value] AS FLOAT) / 4)
        FROM OPENJSON(@rates_hourly_json)
        UNION ALL
        SELECT
            RIGHT('0' + [key], 2) + ':45',
            FLOOR(CAST([value] AS FLOAT) / 4)
        FROM OPENJSON(@rates_hourly_json);

        IF @@ROWCOUNT > 0
            SET @use_variable_rates = 1;
    END

    -- ====================================================================
    -- Step 1: Generate slots
    -- ====================================================================
    DECLARE @current_time DATETIME2(0) = @start_utc;
    DECLARE @slot_index INT = 1;
    DECLARE @suffix_counter INT = 0;
    DECLARE @last_minute INT = -1;
    DECLARE @slot_name NVARCHAR(16);
    DECLARE @slot_minute INT;
    DECLARE @suffix_char CHAR(1);
    DECLARE @current_rate INT;
    DECLARE @slot_interval_sec INT;
    DECLARE @current_quarter_key VARCHAR(5);
    DECLARE @quarter_end DATETIME2(0);

    IF @use_variable_rates = 0
    BEGIN
        -- Simple uniform rate: same logic as before (rate is per hour)
        SET @slot_interval_sec = 3600 / @program_rate;

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
    END
    ELSE
    BEGIN
        -- Variable rate: look up per-15-min rate for each quarter period
        WHILE @current_time < @end_utc
        BEGIN
            -- Determine current quarter key: "HH:MM" where MM is 00/15/30/45
            SET @current_quarter_key = RIGHT('0' + CAST(DATEPART(HOUR, @current_time) AS VARCHAR), 2)
                + ':' + RIGHT('0' + CAST((DATEPART(MINUTE, @current_time) / 15) * 15 AS VARCHAR), 2);

            -- Look up rate for this quarter (per-15-min); fall back to program_rate/4
            SELECT @current_rate = rate_per_quarter FROM #rate_schedule WHERE quarter_key = @current_quarter_key;
            IF @current_rate IS NULL OR @current_rate <= 0
                SET @current_rate = CEILING(@program_rate / 4.0);

            -- Slot interval: 900 seconds (15 min) / rate_per_quarter
            SET @slot_interval_sec = 900 / @current_rate;

            -- Calculate end of this 15-min period
            SET @quarter_end = DATEADD(MINUTE,
                15 - (DATEPART(MINUTE, @current_time) % 15),
                DATEADD(SECOND, -DATEPART(SECOND, @current_time), @current_time));
            IF @quarter_end > @end_utc
                SET @quarter_end = @end_utc;

            -- Generate slots within this quarter period
            WHILE @current_time < @quarter_end AND @current_time < @end_utc
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

            -- Reset @current_rate for next iteration
            SET @current_rate = NULL;
        END
    END

    DROP TABLE #rate_schedule;

    SET @slot_count = @slot_index - 1;

    -- ====================================================================
    -- Step 2: Distribute RESERVED slots
    --
    -- If reserve_pct > 0: use percentage-based distribution
    -- Else if reserve_rate > 0: use legacy interval-based distribution
    -- Else: all slots remain REGULAR
    -- ====================================================================
    IF @reserve_pct > 0 AND @reserve_pct < 100 AND @slot_count > 0
    BEGIN
        DECLARE @regular_count INT = CEILING(@slot_count * (100 - @reserve_pct) / 100.0);
        IF @regular_count < 1 SET @regular_count = 1;
        IF @regular_count > @slot_count SET @regular_count = @slot_count;

        UPDATE dbo.tmi_slots SET slot_type = 'RESERVED'
        WHERE program_id = @program_id;

        DECLARE @step FLOAT = CAST(@slot_count AS FLOAT) / @regular_count;
        DECLARE @i INT = 0;
        DECLARE @target_index INT;

        WHILE @i < @regular_count
        BEGIN
            SET @target_index = FLOOR(@i * @step) + 1;
            UPDATE dbo.tmi_slots
            SET slot_type = 'REGULAR'
            WHERE program_id = @program_id AND slot_index = @target_index;
            SET @i = @i + 1;
        END
    END
    ELSE IF @reserve_rate > 0 AND @program_type IN ('GDP-GAAP', 'GDP-UDP')
    BEGIN
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

PRINT '  Updated: sp_TMI_GenerateSlots (per-15-min quarter rate semantics)';
GO

PRINT 'Migration 054: Fix quarter rate semantics complete';
GO
