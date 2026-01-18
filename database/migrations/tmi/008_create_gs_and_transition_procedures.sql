-- ============================================================================
-- VATSIM_TMI Migration 008: Ground Stop & Transition Procedures
-- GS Operations and GS→GDP Transition
-- ============================================================================
-- Version: 1.0.0
-- Date: 2026-01-18
-- Author: HP/Claude
-- ============================================================================

USE VATSIM_TMI;
GO

-- ============================================================================
-- sp_TMI_ApplyGroundStop
-- Apply ground stop to flights destined for control element
-- ============================================================================
IF OBJECT_ID('dbo.sp_TMI_ApplyGroundStop', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_TMI_ApplyGroundStop;
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
    DECLARE @program_type NVARCHAR(16);
    DECLARE @start_utc DATETIME2(0);
    DECLARE @end_utc DATETIME2(0);
    
    SELECT 
        @ctl_element = ctl_element,
        @program_type = program_type,
        @start_utc = start_utc,
        @end_utc = end_utc
    FROM dbo.tmi_programs
    WHERE program_id = @program_id;
    
    IF @program_type != 'GS'
    BEGIN
        RAISERROR('Program is not a Ground Stop: %d', 16, 1, @program_id);
        RETURN 1;
    END
    
    SET @held_count = 0;
    SET @exempt_count = 0;
    
    -- Clear existing assignments
    DELETE FROM dbo.tmi_flight_control WHERE program_id = @program_id;
    
    -- Insert ground-stopped flights
    INSERT INTO dbo.tmi_flight_control (
        flight_uid, callsign, program_id,
        ctl_elem, ctl_type, 
        ctl_exempt, ctl_exempt_reason,
        gs_held, gs_release_utc,
        orig_eta_utc, orig_etd_utc,
        dep_airport, arr_airport, dep_center, arr_center,
        flight_status_at_ctl, control_assigned_utc
    )
    SELECT 
        f.flight_uid, f.callsign, @program_id,
        @ctl_element, 'GS',
        f.is_exempt, f.exempt_reason,
        CASE WHEN f.is_exempt = 1 THEN 0 ELSE 1 END,
        @end_utc,  -- Release at GS end
        f.eta_utc, f.etd_utc,
        f.dep_airport, f.arr_airport, f.dep_center, f.arr_center,
        f.flight_status, SYSUTCDATETIME()
    FROM @flights f
    WHERE f.eta_utc BETWEEN @start_utc AND DATEADD(HOUR, 6, @end_utc);  -- Include flights ETA within 6hrs after GS end
    
    -- Count results
    SELECT 
        @held_count = SUM(CASE WHEN gs_held = 1 THEN 1 ELSE 0 END),
        @exempt_count = SUM(CASE WHEN ctl_exempt = 1 THEN 1 ELSE 0 END)
    FROM dbo.tmi_flight_control
    WHERE program_id = @program_id;
    
    -- Update program metrics
    UPDATE dbo.tmi_programs
    SET total_flights = @held_count + @exempt_count,
        controlled_flights = @held_count,
        exempt_flights = @exempt_count,
        modified_utc = SYSUTCDATETIME()
    WHERE program_id = @program_id;
    
    -- Log event
    INSERT INTO dbo.tmi_events (
        event_type, program_id, ctl_element,
        description, event_source,
        details_json
    )
    VALUES (
        'GS_APPLIED', @program_id, @ctl_element,
        'Ground Stop applied: ' + CAST(@held_count AS VARCHAR(10)) + ' flights held, ' +
        CAST(@exempt_count AS VARCHAR(10)) + ' exempt',
        'SYSTEM',
        '{"held":' + CAST(@held_count AS VARCHAR(10)) + 
        ',"exempt":' + CAST(@exempt_count AS VARCHAR(10)) + '}'
    );
    
    RETURN 0;
END;
GO

-- ============================================================================
-- sp_TMI_ReleaseGroundStop
-- Release flights from ground stop (manually or at scheduled end)
-- ============================================================================
IF OBJECT_ID('dbo.sp_TMI_ReleaseGroundStop', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_TMI_ReleaseGroundStop;
GO

CREATE PROCEDURE dbo.sp_TMI_ReleaseGroundStop
    @program_id         INT,
    @release_rate       INT = NULL,         -- Flights per hour (NULL = release all)
    @released_by        NVARCHAR(64) = NULL,
    @released_count     INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @ctl_element NVARCHAR(8);
    
    SELECT @ctl_element = ctl_element
    FROM dbo.tmi_programs
    WHERE program_id = @program_id;
    
    IF @release_rate IS NULL
    BEGIN
        -- Release all
        UPDATE dbo.tmi_flight_control
        SET gs_held = 0,
            control_released_utc = SYSUTCDATETIME(),
            modified_utc = SYSUTCDATETIME()
        WHERE program_id = @program_id
          AND gs_held = 1;
        
        SET @released_count = @@ROWCOUNT;
    END
    ELSE
    BEGIN
        -- Metered release - release in ETA order
        DECLARE @release_interval_sec INT = 3600 / @release_rate;
        DECLARE @current_release DATETIME2(0) = SYSUTCDATETIME();
        DECLARE @seq INT = 0;
        
        UPDATE fc
        SET gs_held = 0,
            gs_release_utc = DATEADD(SECOND, @seq * @release_interval_sec, @current_release),
            gs_release_sequence = @seq,
            control_released_utc = SYSUTCDATETIME(),
            modified_utc = SYSUTCDATETIME(),
            @seq = @seq + 1
        FROM dbo.tmi_flight_control fc
        WHERE fc.program_id = @program_id
          AND fc.gs_held = 1
        ORDER BY fc.orig_eta_utc;
        
        SET @released_count = @seq;
    END
    
    -- Log event
    INSERT INTO dbo.tmi_events (
        event_type, program_id, ctl_element,
        description, event_source, event_user,
        details_json
    )
    VALUES (
        'GS_RELEASED', @program_id, @ctl_element,
        'Ground Stop released: ' + CAST(@released_count AS VARCHAR(10)) + ' flights' +
        CASE WHEN @release_rate IS NOT NULL 
             THEN ' at ' + CAST(@release_rate AS VARCHAR(10)) + '/hr'
             ELSE '' END,
        'USER', @released_by,
        '{"count":' + CAST(@released_count AS VARCHAR(10)) + 
        CASE WHEN @release_rate IS NOT NULL 
             THEN ',"rate":' + CAST(@release_rate AS VARCHAR(10))
             ELSE '' END + '}'
    );
    
    RETURN 0;
END;
GO

-- ============================================================================
-- sp_TMI_TransitionGStoGDP
-- Transition from Ground Stop to GDP
-- Creates new GDP program and transfers flights
-- ============================================================================
IF OBJECT_ID('dbo.sp_TMI_TransitionGStoGDP', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_TMI_TransitionGStoGDP;
GO

CREATE PROCEDURE dbo.sp_TMI_TransitionGStoGDP
    @gs_program_id      INT,
    @gdp_type           NVARCHAR(16) = 'GDP-DAS',   -- GDP-DAS, GDP-GAAP, GDP-UDP
    @gdp_end_utc        DATETIME2(0),
    @program_rate       INT,
    @reserve_rate       INT = NULL,
    @delay_limit_min    INT = 180,
    @transitioned_by    NVARCHAR(64) = NULL,
    @gdp_program_id     INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @ctl_element NVARCHAR(8);
    DECLARE @gs_end_utc DATETIME2(0);
    DECLARE @impacting_condition NVARCHAR(32);
    DECLARE @cause_text NVARCHAR(512);
    DECLARE @scope_type NVARCHAR(16);
    DECLARE @scope_distance_nm INT;
    DECLARE @scope_centers_json NVARCHAR(MAX);
    DECLARE @gdp_guid UNIQUEIDENTIFIER;
    
    -- Get GS program details
    SELECT 
        @ctl_element = ctl_element,
        @gs_end_utc = end_utc,
        @impacting_condition = impacting_condition,
        @cause_text = cause_text,
        @scope_type = scope_type,
        @scope_distance_nm = scope_distance_nm,
        @scope_centers_json = scope_centers_json
    FROM dbo.tmi_programs
    WHERE program_id = @gs_program_id
      AND program_type = 'GS';
    
    IF @ctl_element IS NULL
    BEGIN
        RAISERROR('GS program not found: %d', 16, 1, @gs_program_id);
        RETURN 1;
    END
    
    -- Create new GDP program starting at GS end
    EXEC dbo.sp_TMI_CreateProgram
        @ctl_element = @ctl_element,
        @element_type = 'APT',
        @program_type = @gdp_type,
        @start_utc = @gs_end_utc,
        @end_utc = @gdp_end_utc,
        @program_rate = @program_rate,
        @reserve_rate = @reserve_rate,
        @delay_limit_min = @delay_limit_min,
        @scope_type = @scope_type,
        @scope_distance_nm = @scope_distance_nm,
        @scope_centers_json = @scope_centers_json,
        @impacting_condition = @impacting_condition,
        @cause_text = @cause_text,
        @created_by = @transitioned_by,
        @program_id = @gdp_program_id OUTPUT,
        @program_guid = @gdp_guid OUTPUT;
    
    -- Link GDP to GS
    UPDATE dbo.tmi_programs
    SET parent_program_id = @gs_program_id,
        transition_type = 'GS_TO_GDP',
        cumulative_start_utc = (SELECT start_utc FROM dbo.tmi_programs WHERE program_id = @gs_program_id)
    WHERE program_id = @gdp_program_id;
    
    -- Generate slots for GDP
    DECLARE @slot_count INT;
    EXEC dbo.sp_TMI_GenerateSlots 
        @program_id = @gdp_program_id,
        @slot_count = @slot_count OUTPUT;
    
    -- Mark GS as completed/transitioned
    UPDATE dbo.tmi_programs
    SET status = 'COMPLETED',
        is_active = 0,
        completed_utc = SYSUTCDATETIME(),
        superseded_by_id = @gdp_program_id,
        comments = ISNULL(comments + CHAR(13) + CHAR(10), '') + 
                   'Transitioned to ' + @gdp_type + ' (Program ID: ' + CAST(@gdp_program_id AS VARCHAR(10)) + ')',
        modified_utc = SYSUTCDATETIME()
    WHERE program_id = @gs_program_id;
    
    -- Release GS-held flights
    UPDATE dbo.tmi_flight_control
    SET gs_held = 0,
        control_released_utc = SYSUTCDATETIME()
    WHERE program_id = @gs_program_id
      AND gs_held = 1;
    
    -- Log event
    INSERT INTO dbo.tmi_events (
        event_type, program_id, ctl_element,
        description, event_source, event_user,
        details_json
    )
    VALUES (
        'GS_TO_GDP_TRANSITION', @gs_program_id, @ctl_element,
        'Transitioned GS to ' + @gdp_type + ' (new program ID: ' + CAST(@gdp_program_id AS VARCHAR(10)) + ')',
        'USER', @transitioned_by,
        '{"gs_program_id":' + CAST(@gs_program_id AS VARCHAR(10)) + 
        ',"gdp_program_id":' + CAST(@gdp_program_id AS VARCHAR(10)) + 
        ',"gdp_type":"' + @gdp_type + '"' +
        ',"slot_count":' + CAST(@slot_count AS VARCHAR(10)) + '}'
    );
    
    RETURN 0;
END;
GO

-- ============================================================================
-- sp_TMI_ExtendProgram
-- Extend the end time of an active program
-- ============================================================================
IF OBJECT_ID('dbo.sp_TMI_ExtendProgram', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_TMI_ExtendProgram;
GO

CREATE PROCEDURE dbo.sp_TMI_ExtendProgram
    @program_id         INT,
    @new_end_utc        DATETIME2(0),
    @extended_by        NVARCHAR(64) = NULL,
    @new_slots_count    INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @ctl_element NVARCHAR(8);
    DECLARE @program_type NVARCHAR(16);
    DECLARE @old_end_utc DATETIME2(0);
    DECLARE @program_rate INT;
    DECLARE @reserve_rate INT;
    
    SELECT 
        @ctl_element = ctl_element,
        @program_type = program_type,
        @old_end_utc = end_utc,
        @program_rate = program_rate,
        @reserve_rate = reserve_rate
    FROM dbo.tmi_programs
    WHERE program_id = @program_id;
    
    IF @new_end_utc <= @old_end_utc
    BEGIN
        RAISERROR('New end time must be after current end time', 16, 1);
        RETURN 1;
    END
    
    -- Update program times
    UPDATE dbo.tmi_programs
    SET end_utc = @new_end_utc,
        cumulative_end_utc = @new_end_utc,
        revision_number = revision_number + 1,
        modified_by = @extended_by,
        modified_utc = SYSUTCDATETIME()
    WHERE program_id = @program_id;
    
    -- Generate additional slots for the extension period
    IF @program_type != 'GS'
    BEGIN
        DECLARE @slot_interval_sec INT = 3600 / @program_rate;
        DECLARE @reserve_interval INT = CASE WHEN @reserve_rate > 0 THEN @program_rate / @reserve_rate ELSE 0 END;
        DECLARE @current_time DATETIME2(0) = @old_end_utc;
        DECLARE @max_index INT;
        DECLARE @slot_index INT;
        DECLARE @suffix_counter INT = 0;
        DECLARE @last_minute INT = -1;
        
        SELECT @max_index = MAX(slot_index) FROM dbo.tmi_slots WHERE program_id = @program_id;
        SET @slot_index = ISNULL(@max_index, 0) + 1;
        SET @new_slots_count = 0;
        
        WHILE @current_time < @new_end_utc
        BEGIN
            DECLARE @slot_minute INT = DATEPART(HOUR, @current_time) * 100 + DATEPART(MINUTE, @current_time);
            IF @slot_minute = @last_minute
                SET @suffix_counter = @suffix_counter + 1;
            ELSE
            BEGIN
                SET @suffix_counter = 0;
                SET @last_minute = @slot_minute;
            END
            
            DECLARE @suffix_char CHAR(1) = CHAR(65 + (@suffix_counter % 26));
            DECLARE @slot_name NVARCHAR(20) = @ctl_element + '.' + FORMAT(@current_time, 'ddHHmm') + @suffix_char;
            DECLARE @slot_type NVARCHAR(16) = CASE 
                WHEN @reserve_interval > 0 AND (@slot_index % @reserve_interval) = 0 THEN 'RESERVED'
                ELSE 'REGULAR'
            END;
            
            INSERT INTO dbo.tmi_slots (
                program_id, slot_name, slot_index, slot_time_utc,
                slot_type, slot_status,
                bin_date, bin_hour, bin_quarter
            )
            VALUES (
                @program_id, @slot_name, @slot_index, @current_time,
                @slot_type, 'OPEN',
                CAST(@current_time AS DATE),
                DATEPART(HOUR, @current_time),
                (DATEPART(MINUTE, @current_time) / 15) * 15
            );
            
            SET @slot_index = @slot_index + 1;
            SET @new_slots_count = @new_slots_count + 1;
            SET @current_time = DATEADD(SECOND, @slot_interval_sec, @current_time);
        END
    END
    ELSE
    BEGIN
        SET @new_slots_count = 0;
    END
    
    -- Log event
    INSERT INTO dbo.tmi_events (
        event_type, program_id, ctl_element,
        description, event_source, event_user,
        old_value, new_value,
        details_json
    )
    VALUES (
        'PROGRAM_EXTENDED', @program_id, @ctl_element,
        'Extended program end time by ' + 
        CAST(DATEDIFF(MINUTE, @old_end_utc, @new_end_utc) AS VARCHAR(10)) + ' minutes',
        'USER', @extended_by,
        FORMAT(@old_end_utc, 'yyyy-MM-dd HH:mm'),
        FORMAT(@new_end_utc, 'yyyy-MM-dd HH:mm'),
        '{"old_end":"' + FORMAT(@old_end_utc, 'yyyy-MM-ddTHH:mm:ssZ') + '"' +
        ',"new_end":"' + FORMAT(@new_end_utc, 'yyyy-MM-ddTHH:mm:ssZ') + '"' +
        ',"new_slots":' + CAST(@new_slots_count AS VARCHAR(10)) + '}'
    );
    
    RETURN 0;
END;
GO

PRINT 'Migration 008: Ground Stop and transition procedures created successfully';
GO
