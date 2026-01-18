-- ============================================================================
-- VATSIM_TMI Migration 006: Core Stored Procedures
-- GDT Operations: Program Creation, Slot Generation, RBS Assignment
-- ============================================================================
-- Version: 1.0.0
-- Date: 2026-01-18
-- Author: HP/Claude
-- ============================================================================

USE VATSIM_TMI;
GO

-- ============================================================================
-- sp_TMI_CreateProgram
-- Create a new GS/GDP/AFP program
-- ============================================================================
IF OBJECT_ID('dbo.sp_TMI_CreateProgram', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_TMI_CreateProgram;
GO

CREATE PROCEDURE dbo.sp_TMI_CreateProgram
    @ctl_element        NVARCHAR(8),
    @element_type       NVARCHAR(8) = 'APT',        -- APT, FCA, FEA
    @program_type       NVARCHAR(16),                -- GS, GDP-DAS, GDP-GAAP, GDP-UDP, AFP
    @start_utc          DATETIME2(0),
    @end_utc            DATETIME2(0),
    @program_rate       INT = NULL,
    @reserve_rate       INT = NULL,
    @delay_limit_min    INT = 180,
    @scope_type         NVARCHAR(16) = 'ALL',
    @scope_distance_nm  INT = NULL,
    @scope_centers_json NVARCHAR(MAX) = NULL,
    @impacting_condition NVARCHAR(32) = NULL,
    @cause_text         NVARCHAR(512) = NULL,
    @created_by         NVARCHAR(64) = NULL,
    @program_id         INT OUTPUT,
    @program_guid       UNIQUEIDENTIFIER OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @new_guid UNIQUEIDENTIFIER = NEWID();
    
    -- Generate program name if not provided
    DECLARE @program_name NVARCHAR(64);
    DECLARE @adv_number NVARCHAR(16);
    
    -- Get next advisory number for today
    SELECT @adv_number = RIGHT('000' + CAST(ISNULL(MAX(CAST(adv_number AS INT)), 0) + 1 AS VARCHAR(3)), 3)
    FROM dbo.tmi_programs
    WHERE ctl_element = @ctl_element
      AND CAST(created_utc AS DATE) = CAST(SYSUTCDATETIME() AS DATE);
    
    SET @program_name = @ctl_element + ' ' + 
        CASE @program_type 
            WHEN 'GS' THEN 'GS'
            WHEN 'GDP-DAS' THEN 'GDP'
            WHEN 'GDP-GAAP' THEN 'GAAP'
            WHEN 'GDP-UDP' THEN 'UDP'
            WHEN 'AFP' THEN 'AFP'
            ELSE @program_type
        END + ' #' + @adv_number;
    
    INSERT INTO dbo.tmi_programs (
        program_guid,
        ctl_element,
        element_type,
        program_type,
        program_name,
        adv_number,
        start_utc,
        end_utc,
        cumulative_start_utc,
        cumulative_end_utc,
        status,
        is_proposed,
        program_rate,
        reserve_rate,
        delay_limit_min,
        scope_type,
        scope_distance_nm,
        scope_centers_json,
        impacting_condition,
        cause_text,
        created_by
    )
    VALUES (
        @new_guid,
        @ctl_element,
        @element_type,
        @program_type,
        @program_name,
        @adv_number,
        @start_utc,
        @end_utc,
        @start_utc,
        @end_utc,
        'PROPOSED',
        1,
        @program_rate,
        @reserve_rate,
        @delay_limit_min,
        @scope_type,
        @scope_distance_nm,
        @scope_centers_json,
        @impacting_condition,
        @cause_text,
        @created_by
    );
    
    SET @program_id = SCOPE_IDENTITY();
    SET @program_guid = @new_guid;
    
    -- Log event
    INSERT INTO dbo.tmi_events (
        event_type, program_id, ctl_element, 
        description, event_source, event_user
    )
    VALUES (
        'PROGRAM_CREATED', @program_id, @ctl_element,
        'Created ' + @program_type + ' program: ' + @program_name,
        'USER', @created_by
    );
    
    RETURN 0;
END;
GO

-- ============================================================================
-- sp_TMI_GenerateSlots
-- Generate arrival slots for a GDP/AFP program using RBS
-- ============================================================================
IF OBJECT_ID('dbo.sp_TMI_GenerateSlots', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_TMI_GenerateSlots;
GO

CREATE PROCEDURE dbo.sp_TMI_GenerateSlots
    @program_id         INT,
    @rates_hourly_json  NVARCHAR(MAX) = NULL,       -- Override rates by hour
    @reserve_hourly_json NVARCHAR(MAX) = NULL,      -- Override reserve by hour
    @slot_count         INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @ctl_element NVARCHAR(8);
    DECLARE @element_type NVARCHAR(8);
    DECLARE @program_type NVARCHAR(16);
    DECLARE @start_utc DATETIME2(0);
    DECLARE @end_utc DATETIME2(0);
    DECLARE @program_rate INT;
    DECLARE @reserve_rate INT;
    DECLARE @slot_interval_sec INT;
    DECLARE @reserve_interval INT;
    
    -- Get program details
    SELECT 
        @ctl_element = ctl_element,
        @element_type = element_type,
        @program_type = program_type,
        @start_utc = start_utc,
        @end_utc = end_utc,
        @program_rate = ISNULL(program_rate, 30),
        @reserve_rate = ISNULL(reserve_rate, 0)
    FROM dbo.tmi_programs
    WHERE program_id = @program_id;
    
    IF @ctl_element IS NULL
    BEGIN
        RAISERROR('Program not found: %d', 16, 1, @program_id);
        RETURN 1;
    END
    
    -- Calculate slot interval in seconds (3600 / rate)
    SET @slot_interval_sec = 3600 / @program_rate;
    
    -- For GAAP/UDP, calculate reserve slot interval
    IF @program_type IN ('GDP-GAAP', 'GDP-UDP') AND @reserve_rate > 0
    BEGIN
        SET @reserve_interval = @program_rate / @reserve_rate;  -- Every Nth slot is reserved
    END
    ELSE
    BEGIN
        SET @reserve_interval = 0;
    END
    
    -- Delete existing slots (for re-modeling)
    DELETE FROM dbo.tmi_slots WHERE program_id = @program_id;
    
    -- Generate slots
    DECLARE @current_time DATETIME2(0) = @start_utc;
    DECLARE @slot_index INT = 1;
    DECLARE @suffix_counter INT = 0;
    DECLARE @last_minute INT = -1;
    DECLARE @slot_name NVARCHAR(20);
    DECLARE @slot_type NVARCHAR(16);
    DECLARE @bin_date DATE;
    DECLARE @bin_hour TINYINT;
    DECLARE @bin_quarter TINYINT;
    DECLARE @slot_minute INT;
    DECLARE @suffix_char CHAR(1);
    
    WHILE @current_time < @end_utc
    BEGIN
        -- Calculate slot name suffix (A, B, C... for same minute)
        SET @slot_minute = DATEPART(HOUR, @current_time) * 100 + DATEPART(MINUTE, @current_time);
        IF @slot_minute = @last_minute
        BEGIN
            SET @suffix_counter = @suffix_counter + 1;
        END
        ELSE
        BEGIN
            SET @suffix_counter = 0;
            SET @last_minute = @slot_minute;
        END
        
        SET @suffix_char = CHAR(65 + (@suffix_counter % 26));  -- A=65
        
        -- Build slot name: KJFK.091530A or FCA027.091530A
        IF @element_type = 'FCA'
            SET @slot_name = @ctl_element + '.' + 
                FORMAT(@current_time, 'ddHHmm') + @suffix_char;
        ELSE
            SET @slot_name = @ctl_element + '.' + 
                FORMAT(@current_time, 'ddHHmm') + @suffix_char;
        
        -- Determine slot type
        IF @reserve_interval > 0 AND (@slot_index % @reserve_interval) = 0
            SET @slot_type = 'RESERVED';
        ELSE
            SET @slot_type = 'REGULAR';
        
        -- Calculate bin values
        SET @bin_date = CAST(@current_time AS DATE);
        SET @bin_hour = DATEPART(HOUR, @current_time);
        SET @bin_quarter = (DATEPART(MINUTE, @current_time) / 15) * 15;
        
        -- Insert slot
        INSERT INTO dbo.tmi_slots (
            program_id,
            slot_name,
            slot_index,
            slot_time_utc,
            slot_type,
            slot_status,
            bin_date,
            bin_hour,
            bin_quarter
        )
        VALUES (
            @program_id,
            @slot_name,
            @slot_index,
            @current_time,
            @slot_type,
            'OPEN',
            @bin_date,
            @bin_hour,
            @bin_quarter
        );
        
        SET @slot_index = @slot_index + 1;
        SET @current_time = DATEADD(SECOND, @slot_interval_sec, @current_time);
    END
    
    SET @slot_count = @slot_index - 1;
    
    -- Update program status
    UPDATE dbo.tmi_programs
    SET status = 'MODELING',
        modified_utc = SYSUTCDATETIME()
    WHERE program_id = @program_id
      AND status = 'PROPOSED';
    
    -- Log event
    INSERT INTO dbo.tmi_events (
        event_type, program_id, ctl_element,
        description, event_source,
        details_json
    )
    SELECT 
        'SLOTS_GENERATED', @program_id, @ctl_element,
        'Generated ' + CAST(@slot_count AS VARCHAR(10)) + ' slots at rate ' + CAST(@program_rate AS VARCHAR(10)) + '/hr',
        'SYSTEM',
        '{"slot_count":' + CAST(@slot_count AS VARCHAR(10)) + 
        ',"program_rate":' + CAST(@program_rate AS VARCHAR(10)) + 
        ',"reserve_rate":' + CAST(ISNULL(@reserve_rate, 0) AS VARCHAR(10)) + '}'
    FROM dbo.tmi_programs
    WHERE program_id = @program_id;
    
    RETURN 0;
END;
GO

-- ============================================================================
-- sp_TMI_GenerateSlotName
-- Helper function to generate FSM-format slot name
-- ============================================================================
IF OBJECT_ID('dbo.fn_TMI_GenerateSlotName', 'FN') IS NOT NULL
    DROP FUNCTION dbo.fn_TMI_GenerateSlotName;
GO

CREATE FUNCTION dbo.fn_TMI_GenerateSlotName(
    @ctl_element    NVARCHAR(8),
    @slot_time      DATETIME2(0),
    @suffix         CHAR(1)
)
RETURNS NVARCHAR(20)
AS
BEGIN
    RETURN @ctl_element + '.' + FORMAT(@slot_time, 'ddHHmm') + @suffix;
END;
GO

-- ============================================================================
-- sp_TMI_ActivateProgram
-- Activate a proposed/modeled program
-- ============================================================================
IF OBJECT_ID('dbo.sp_TMI_ActivateProgram', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_TMI_ActivateProgram;
GO

CREATE PROCEDURE dbo.sp_TMI_ActivateProgram
    @program_id     INT,
    @activated_by   NVARCHAR(64) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @current_status NVARCHAR(16);
    DECLARE @ctl_element NVARCHAR(8);
    DECLARE @program_name NVARCHAR(64);
    
    SELECT 
        @current_status = status,
        @ctl_element = ctl_element,
        @program_name = program_name
    FROM dbo.tmi_programs
    WHERE program_id = @program_id;
    
    IF @current_status IS NULL
    BEGIN
        RAISERROR('Program not found: %d', 16, 1, @program_id);
        RETURN 1;
    END
    
    IF @current_status NOT IN ('PROPOSED', 'MODELING', 'PAUSED')
    BEGIN
        RAISERROR('Program cannot be activated from status: %s', 16, 1, @current_status);
        RETURN 2;
    END
    
    -- Deactivate any other active programs for this element
    UPDATE dbo.tmi_programs
    SET status = 'SUPERSEDED',
        superseded_by_id = @program_id,
        modified_utc = SYSUTCDATETIME()
    WHERE ctl_element = @ctl_element
      AND is_active = 1
      AND program_id != @program_id;
    
    -- Activate this program
    UPDATE dbo.tmi_programs
    SET status = 'ACTIVE',
        is_proposed = 0,
        is_active = 1,
        activated_utc = SYSUTCDATETIME(),
        modified_by = @activated_by,
        modified_utc = SYSUTCDATETIME()
    WHERE program_id = @program_id;
    
    -- Log event
    INSERT INTO dbo.tmi_events (
        event_type, program_id, ctl_element,
        description, event_source, event_user
    )
    VALUES (
        'PROGRAM_ACTIVATED', @program_id, @ctl_element,
        'Activated program: ' + @program_name,
        'USER', @activated_by
    );
    
    RETURN 0;
END;
GO

-- ============================================================================
-- sp_TMI_PurgeProgram
-- Purge/cancel an active program
-- ============================================================================
IF OBJECT_ID('dbo.sp_TMI_PurgeProgram', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_TMI_PurgeProgram;
GO

CREATE PROCEDURE dbo.sp_TMI_PurgeProgram
    @program_id     INT,
    @purge_reason   NVARCHAR(256) = NULL,
    @purged_by      NVARCHAR(64) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @ctl_element NVARCHAR(8);
    DECLARE @program_name NVARCHAR(64);
    
    SELECT 
        @ctl_element = ctl_element,
        @program_name = program_name
    FROM dbo.tmi_programs
    WHERE program_id = @program_id;
    
    IF @ctl_element IS NULL
    BEGIN
        RAISERROR('Program not found: %d', 16, 1, @program_id);
        RETURN 1;
    END
    
    -- Update program status
    UPDATE dbo.tmi_programs
    SET status = 'PURGED',
        is_active = 0,
        purged_utc = SYSUTCDATETIME(),
        purged_by = @purged_by,
        comments = ISNULL(comments + CHAR(13) + CHAR(10), '') + 
                   'Purged: ' + ISNULL(@purge_reason, 'No reason provided'),
        modified_by = @purged_by,
        modified_utc = SYSUTCDATETIME()
    WHERE program_id = @program_id;
    
    -- Release all ground-stopped flights
    UPDATE dbo.tmi_flight_control
    SET gs_held = 0,
        control_released_utc = SYSUTCDATETIME(),
        modified_utc = SYSUTCDATETIME()
    WHERE program_id = @program_id
      AND gs_held = 1;
    
    -- Log event
    INSERT INTO dbo.tmi_events (
        event_type, program_id, ctl_element,
        description, event_source, event_user,
        details_json
    )
    VALUES (
        'PROGRAM_PURGED', @program_id, @ctl_element,
        'Purged program: ' + @program_name + ISNULL(' - ' + @purge_reason, ''),
        'USER', @purged_by,
        '{"reason":"' + ISNULL(REPLACE(@purge_reason, '"', '\"'), '') + '"}'
    );
    
    RETURN 0;
END;
GO

PRINT 'Migration 006: Core stored procedures created successfully';
GO
