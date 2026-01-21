-- ============================================================================
-- VATSIM_TMI GDT Stored Procedures (v2 - Azure SQL Compatible)
-- Core operations for Ground Delay Tools
-- ============================================================================
-- Version: 1.0.1
-- Date: 2026-01-21
-- Author: HP/Claude
--
-- Fixes from v1:
--   - Replaced LEAST() with IIF() for pre-2022 SQL Server compatibility
--   - Fixed sp_LogTmiEvent calls to match existing procedure signature
--   - Added NULL handling for is_exempt from FlightListType (no DEFAULT in types)
--
-- Procedures:
--   sp_TMI_CreateProgram      - Create new GS/GDP/AFP
--   sp_TMI_GenerateSlots      - Generate slots using RBS rate
--   sp_TMI_ActivateProgram    - Activate proposed program
--   sp_TMI_PurgeProgram       - Cancel/purge program
--   sp_TMI_AssignFlightsRBS   - Assign flights to slots (RBS algorithm)
--   sp_TMI_DetectPopups       - Auto-detect pop-up flights
--   sp_TMI_AssignPopups       - Auto-assign pop-ups to reserved slots
--   sp_TMI_ApplyGroundStop    - Apply GS to flights
--   sp_TMI_TransitionGStoGDP  - GS→GDP transition
--   sp_TMI_ExtendProgram      - Extend program end time
--   sp_TMI_ArchiveData        - Retention management
-- ============================================================================

USE VATSIM_TMI;
GO

PRINT 'Creating GDT stored procedures v2...';
PRINT '';

-- ============================================================================
-- sp_TMI_CreateProgram
-- Create a new GS/GDP/AFP program
-- ============================================================================
IF OBJECT_ID('dbo.sp_TMI_CreateProgram', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_TMI_CreateProgram;
GO

CREATE PROCEDURE dbo.sp_TMI_CreateProgram
    @ctl_element        NVARCHAR(8),
    @element_type       NVARCHAR(8) = 'APT',
    @program_type       NVARCHAR(16),
    @start_utc          DATETIME2(0),
    @end_utc            DATETIME2(0),
    @program_rate       INT = NULL,
    @reserve_rate       INT = NULL,
    @delay_limit_min    INT = 180,
    @scope_json         NVARCHAR(MAX) = NULL,
    @impacting_condition NVARCHAR(64) = NULL,
    @cause_text         NVARCHAR(512) = NULL,
    @created_by         NVARCHAR(64) = NULL,
    @program_id         INT OUTPUT,
    @program_guid       UNIQUEIDENTIFIER OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @new_guid UNIQUEIDENTIFIER = NEWID();
    DECLARE @program_name NVARCHAR(32);
    DECLARE @adv_number NVARCHAR(16);
    
    -- Get next advisory number for today
    SELECT @adv_number = RIGHT('000' + CAST(ISNULL(MAX(CAST(adv_number AS INT)), 0) + 1 AS VARCHAR(3)), 3)
    FROM dbo.tmi_programs
    WHERE ctl_element = @ctl_element
      AND CAST(created_at AS DATE) = CAST(SYSUTCDATETIME() AS DATE);
    
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
        program_guid, ctl_element, element_type, program_type, program_name, adv_number,
        start_utc, end_utc, cumulative_start, cumulative_end,
        status, is_proposed,
        program_rate, reserve_rate, delay_limit_min,
        scope_json, impacting_condition, cause_text,
        created_by
    )
    VALUES (
        @new_guid, @ctl_element, @element_type, @program_type, @program_name, @adv_number,
        @start_utc, @end_utc, @start_utc, @end_utc,
        'PROPOSED', 1,
        @program_rate, @reserve_rate, @delay_limit_min,
        @scope_json, @impacting_condition, @cause_text,
        @created_by
    );
    
    SET @program_id = SCOPE_IDENTITY();
    SET @program_guid = @new_guid;
    
    -- Log event using existing sp_LogTmiEvent signature
    EXEC dbo.sp_LogTmiEvent 
        @entity_type = 'PROGRAM',
        @entity_id = @program_id,
        @entity_guid = @new_guid,
        @event_type = 'PROGRAM_CREATED',
        @event_detail = @program_name,
        @source_type = 'USER',
        @actor_id = @created_by,
        @program_id = @program_id;
    
    RETURN 0;
END;
GO

PRINT '  Created: sp_TMI_CreateProgram';

-- ============================================================================
-- sp_TMI_GenerateSlots
-- Generate arrival slots for a GDP/AFP program
-- ============================================================================
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
    DECLARE @element_type NVARCHAR(8);
    DECLARE @program_type NVARCHAR(16);
    DECLARE @start_utc DATETIME2(0);
    DECLARE @end_utc DATETIME2(0);
    DECLARE @program_rate INT;
    DECLARE @reserve_rate INT;
    DECLARE @slot_interval_sec INT;
    DECLARE @reserve_interval INT;
    
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
    
    SET @slot_interval_sec = 3600 / @program_rate;
    
    IF @program_type IN ('GDP-GAAP', 'GDP-UDP') AND @reserve_rate > 0
        SET @reserve_interval = @program_rate / @reserve_rate;
    ELSE
        SET @reserve_interval = 0;
    
    -- Delete existing slots (for re-modeling)
    DELETE FROM dbo.tmi_slots WHERE program_id = @program_id;
    
    -- Generate slots
    DECLARE @current_time DATETIME2(0) = @start_utc;
    DECLARE @slot_index INT = 1;
    DECLARE @suffix_counter INT = 0;
    DECLARE @last_minute INT = -1;
    DECLARE @slot_name NVARCHAR(16);
    DECLARE @slot_type NVARCHAR(16);
    DECLARE @bin_date DATE;
    DECLARE @bin_hour TINYINT;
    DECLARE @bin_quarter TINYINT;
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
        
        IF @reserve_interval > 0 AND (@slot_index % @reserve_interval) = 0
            SET @slot_type = 'RESERVED';
        ELSE
            SET @slot_type = 'REGULAR';
        
        SET @bin_date = CAST(@current_time AS DATE);
        SET @bin_hour = DATEPART(HOUR, @current_time);
        SET @bin_quarter = (DATEPART(MINUTE, @current_time) / 15) * 15;
        
        INSERT INTO dbo.tmi_slots (
            program_id, slot_name, slot_index, slot_time_utc,
            slot_type, slot_status,
            bin_date, bin_hour, bin_quarter
        )
        VALUES (
            @program_id, @slot_name, @slot_index, @current_time,
            @slot_type, 'OPEN',
            @bin_date, @bin_hour, @bin_quarter
        );
        
        SET @slot_index = @slot_index + 1;
        SET @current_time = DATEADD(SECOND, @slot_interval_sec, @current_time);
    END
    
    SET @slot_count = @slot_index - 1;
    
    UPDATE dbo.tmi_programs
    SET status = 'MODELING', updated_at = SYSUTCDATETIME()
    WHERE program_id = @program_id AND status = 'PROPOSED';
    
    RETURN 0;
END;
GO

PRINT '  Created: sp_TMI_GenerateSlots';

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
    DECLARE @program_name NVARCHAR(32);
    DECLARE @program_guid UNIQUEIDENTIFIER;
    
    SELECT 
        @current_status = status,
        @ctl_element = ctl_element,
        @program_name = program_name,
        @program_guid = program_guid
    FROM dbo.tmi_programs
    WHERE program_id = @program_id;
    
    IF @current_status NOT IN ('PROPOSED', 'MODELING')
    BEGIN
        RAISERROR('Program cannot be activated from status: %s', 16, 1, @current_status);
        RETURN 2;
    END
    
    -- Deactivate any other active programs for this element
    UPDATE dbo.tmi_programs
    SET status = 'SUPERSEDED', superseded_by_id = @program_id, updated_at = SYSUTCDATETIME()
    WHERE ctl_element = @ctl_element AND is_active = 1 AND program_id != @program_id;
    
    -- Activate this program
    UPDATE dbo.tmi_programs
    SET status = 'ACTIVE', is_proposed = 0, is_active = 1,
        activated_by = @activated_by, activated_at = SYSUTCDATETIME(),
        updated_at = SYSUTCDATETIME()
    WHERE program_id = @program_id;
    
    EXEC dbo.sp_LogTmiEvent 
        @entity_type = 'PROGRAM',
        @entity_id = @program_id,
        @entity_guid = @program_guid,
        @event_type = 'PROGRAM_ACTIVATED',
        @event_detail = @program_name,
        @source_type = 'USER',
        @actor_id = @activated_by,
        @program_id = @program_id;
    
    RETURN 0;
END;
GO

PRINT '  Created: sp_TMI_ActivateProgram';

-- ============================================================================
-- sp_TMI_PurgeProgram
-- Cancel/purge an active program
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
    DECLARE @program_name NVARCHAR(32);
    DECLARE @program_guid UNIQUEIDENTIFIER;
    
    SELECT @ctl_element = ctl_element, @program_name = program_name, @program_guid = program_guid
    FROM dbo.tmi_programs WHERE program_id = @program_id;
    
    UPDATE dbo.tmi_programs
    SET status = 'PURGED', is_active = 0,
        purged_by = @purged_by, purged_at = SYSUTCDATETIME(),
        comments = ISNULL(comments + CHAR(13) + CHAR(10), '') + 'Purged: ' + ISNULL(@purge_reason, 'No reason'),
        updated_at = SYSUTCDATETIME()
    WHERE program_id = @program_id;
    
    -- Release GS-held flights
    UPDATE dbo.tmi_flight_control
    SET gs_held = 0, control_released_utc = SYSUTCDATETIME(), updated_at = SYSUTCDATETIME()
    WHERE program_id = @program_id AND gs_held = 1;
    
    EXEC dbo.sp_LogTmiEvent 
        @entity_type = 'PROGRAM',
        @entity_id = @program_id,
        @entity_guid = @program_guid,
        @event_type = 'PROGRAM_PURGED',
        @event_detail = @program_name,
        @source_type = 'USER',
        @actor_id = @purged_by,
        @program_id = @program_id;
    
    RETURN 0;
END;
GO

PRINT '  Created: sp_TMI_PurgeProgram';

-- ============================================================================
-- sp_TMI_AssignFlightsRBS
-- Assign flights to slots using Ration By Schedule algorithm
-- ============================================================================
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
    
    -- Clear existing
    DELETE FROM dbo.tmi_flight_control WHERE program_id = @program_id;
    UPDATE dbo.tmi_slots SET slot_status = 'OPEN', assigned_flight_uid = NULL, assigned_callsign = NULL
    WHERE program_id = @program_id;
    
    -- Process flights in ETA order
    DECLARE @flight_uid BIGINT, @callsign NVARCHAR(12), @eta_utc DATETIME2(0), @etd_utc DATETIME2(0);
    DECLARE @dep_airport NVARCHAR(4), @arr_airport NVARCHAR(4), @dep_center NVARCHAR(4), @carrier NVARCHAR(8);
    DECLARE @is_exempt BIT, @exempt_reason NVARCHAR(32);
    DECLARE @slot_id BIGINT, @slot_name NVARCHAR(16), @slot_time DATETIME2(0);
    DECLARE @delay_min INT, @delay_capped BIT, @ctd_utc DATETIME2(0), @ete_min INT;
    
    DECLARE flight_cursor CURSOR LOCAL FAST_FORWARD FOR
        SELECT flight_uid, callsign, eta_utc, etd_utc, dep_airport, arr_airport, dep_center, carrier, 
               ISNULL(is_exempt, 0), exempt_reason  -- Handle NULL from table type
        FROM @flights ORDER BY eta_utc, flight_uid;
    
    OPEN flight_cursor;
    FETCH NEXT FROM flight_cursor INTO @flight_uid, @callsign, @eta_utc, @etd_utc, @dep_airport, @arr_airport, @dep_center, @carrier, @is_exempt, @exempt_reason;
    
    WHILE @@FETCH_STATUS = 0
    BEGIN
        IF @is_exempt = 1
        BEGIN
            INSERT INTO dbo.tmi_flight_control (flight_uid, callsign, program_id, ctl_elem, ctl_type, ctl_exempt, ctl_exempt_reason, orig_eta_utc, orig_etd_utc, dep_airport, arr_airport, dep_center, control_assigned_utc)
            VALUES (@flight_uid, @callsign, @program_id, @ctl_element, @program_type, 1, @exempt_reason, @eta_utc, @etd_utc, @dep_airport, @arr_airport, @dep_center, SYSUTCDATETIME());
            SET @exempt_count = @exempt_count + 1;
        END
        ELSE
        BEGIN
            SELECT TOP 1 @slot_id = slot_id, @slot_name = slot_name, @slot_time = slot_time_utc
            FROM dbo.tmi_slots WHERE program_id = @program_id AND slot_status = 'OPEN' AND slot_type = 'REGULAR' AND slot_time_utc >= @eta_utc
            ORDER BY slot_time_utc;
            
            IF @slot_id IS NOT NULL
            BEGIN
                SET @delay_min = DATEDIFF(MINUTE, @eta_utc, @slot_time);
                SET @delay_capped = CASE WHEN @delay_min > @delay_limit_min THEN 1 ELSE 0 END;
                IF @delay_capped = 1 SET @delay_min = @delay_limit_min;
                SET @ete_min = DATEDIFF(MINUTE, @etd_utc, @eta_utc);
                SET @ctd_utc = DATEADD(MINUTE, -@ete_min, @slot_time);
                
                UPDATE dbo.tmi_slots
                SET slot_status = 'ASSIGNED', assigned_flight_uid = @flight_uid, assigned_callsign = @callsign, assigned_carrier = @carrier, assigned_origin = @dep_airport, assigned_at = SYSUTCDATETIME(), original_eta_utc = @eta_utc, slot_delay_min = @delay_min, ctd_utc = @ctd_utc, cta_utc = @slot_time
                WHERE slot_id = @slot_id;
                
                INSERT INTO dbo.tmi_flight_control (flight_uid, callsign, program_id, slot_id, ctd_utc, cta_utc, octd_utc, octa_utc, aslot, ctl_elem, ctl_type, program_delay_min, delay_capped, orig_eta_utc, orig_etd_utc, orig_ete_min, dep_airport, arr_airport, dep_center, control_assigned_utc)
                VALUES (@flight_uid, @callsign, @program_id, @slot_id, @ctd_utc, @slot_time, @ctd_utc, @slot_time, @slot_name, @ctl_element, CASE @program_type WHEN 'GDP-DAS' THEN 'DAS' WHEN 'GDP-GAAP' THEN 'GAAP' WHEN 'GDP-UDP' THEN 'UDP' ELSE @program_type END, @delay_min, @delay_capped, @eta_utc, @etd_utc, @ete_min, @dep_airport, @arr_airport, @dep_center, SYSUTCDATETIME());
                
                SET @assigned_count = @assigned_count + 1;
            END
        END
        
        SET @slot_id = NULL;
        FETCH NEXT FROM flight_cursor INTO @flight_uid, @callsign, @eta_utc, @etd_utc, @dep_airport, @arr_airport, @dep_center, @carrier, @is_exempt, @exempt_reason;
    END
    
    CLOSE flight_cursor;
    DEALLOCATE flight_cursor;
    
    -- Update metrics
    UPDATE dbo.tmi_programs
    SET total_flights = @assigned_count + @exempt_count, controlled_flights = @assigned_count, exempt_flights = @exempt_count,
        avg_delay_min = (SELECT AVG(CAST(program_delay_min AS DECIMAL(8,2))) FROM dbo.tmi_flight_control WHERE program_id = @program_id AND ctl_exempt = 0),
        max_delay_min = (SELECT MAX(program_delay_min) FROM dbo.tmi_flight_control WHERE program_id = @program_id AND ctl_exempt = 0),
        total_delay_min = (SELECT SUM(program_delay_min) FROM dbo.tmi_flight_control WHERE program_id = @program_id AND ctl_exempt = 0),
        updated_at = SYSUTCDATETIME()
    WHERE program_id = @program_id;
    
    RETURN 0;
END;
GO

PRINT '  Created: sp_TMI_AssignFlightsRBS';

-- ============================================================================
-- sp_TMI_DetectPopups
-- Detect new flights that appeared after program start
-- ============================================================================
IF OBJECT_ID('dbo.sp_TMI_DetectPopups', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_TMI_DetectPopups;
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
    
    INSERT INTO dbo.tmi_popup_queue (flight_uid, callsign, program_id, flight_eta_utc, lead_time_min, dep_airport, arr_airport, dep_center, aircraft_type, carrier)
    SELECT f.flight_uid, f.callsign, @program_id, f.eta_utc, DATEDIFF(MINUTE, SYSUTCDATETIME(), f.eta_utc), f.dep_airport, f.arr_airport, f.dep_center, f.aircraft_type, f.carrier
    FROM @flights f
    WHERE f.eta_utc BETWEEN @start_utc AND @end_utc AND ISNULL(f.is_exempt, 0) = 0
      AND NOT EXISTS (SELECT 1 FROM dbo.tmi_flight_control fc WHERE fc.flight_uid = f.flight_uid AND fc.program_id = @program_id)
      AND NOT EXISTS (SELECT 1 FROM dbo.tmi_popup_queue q WHERE q.flight_uid = f.flight_uid AND q.program_id = @program_id);
    
    SET @popup_count = @@ROWCOUNT;
    RETURN 0;
END;
GO

PRINT '  Created: sp_TMI_DetectPopups';

-- ============================================================================
-- sp_TMI_AssignPopups
-- Auto-assign pending pop-ups to available slots (GAAP/UDP mode)
-- Uses IIF() instead of LEAST() for pre-2022 SQL Server compatibility
-- ============================================================================
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
    
    SELECT @ctl_element = ctl_element, @program_type = program_type, @delay_limit_min = ISNULL(delay_limit_min, 180)
    FROM dbo.tmi_programs WHERE program_id = @program_id AND is_active = 1;
    
    IF @program_type NOT IN ('GDP-GAAP', 'GDP-UDP')
    BEGIN
        SET @assigned_count = 0;
        RETURN 0;
    END
    
    SET @assigned_count = 0;
    
    DECLARE @queue_id BIGINT, @flight_uid BIGINT, @callsign NVARCHAR(12), @eta_utc DATETIME2(0), @lead_time INT;
    DECLARE @dep_airport NVARCHAR(4), @carrier NVARCHAR(8);
    DECLARE @slot_id BIGINT, @slot_name NVARCHAR(16), @slot_time DATETIME2(0), @delay_min INT;
    DECLARE @raw_delay INT;
    
    DECLARE popup_cursor CURSOR LOCAL FAST_FORWARD FOR
        SELECT queue_id, flight_uid, callsign, flight_eta_utc, lead_time_min, dep_airport, carrier
        FROM dbo.tmi_popup_queue WHERE program_id = @program_id AND queue_status = 'PENDING'
        ORDER BY flight_eta_utc;
    
    OPEN popup_cursor;
    FETCH NEXT FROM popup_cursor INTO @queue_id, @flight_uid, @callsign, @eta_utc, @lead_time, @dep_airport, @carrier;
    
    WHILE @@FETCH_STATUS = 0
    BEGIN
        SELECT TOP 1 @slot_id = slot_id, @slot_name = slot_name, @slot_time = slot_time_utc
        FROM dbo.tmi_slots WHERE program_id = @program_id AND slot_status = 'OPEN' AND slot_type = 'RESERVED' AND slot_time_utc >= @eta_utc
        ORDER BY slot_time_utc;
        
        IF @slot_id IS NULL
            SELECT TOP 1 @slot_id = slot_id, @slot_name = slot_name, @slot_time = slot_time_utc
            FROM dbo.tmi_slots WHERE program_id = @program_id AND slot_status = 'OPEN' AND slot_time_utc >= @eta_utc
            ORDER BY slot_time_utc;
        
        IF @slot_id IS NOT NULL
        BEGIN
            -- Use IIF instead of LEAST for SQL Server < 2022 compatibility
            SET @raw_delay = DATEDIFF(MINUTE, @eta_utc, @slot_time);
            SET @delay_min = IIF(@raw_delay < @delay_limit_min, @raw_delay, @delay_limit_min);
            
            UPDATE dbo.tmi_slots
            SET slot_status = 'ASSIGNED', assigned_flight_uid = @flight_uid, assigned_callsign = @callsign, assigned_carrier = @carrier, assigned_origin = @dep_airport, assigned_at = SYSUTCDATETIME(), original_eta_utc = @eta_utc, slot_delay_min = @delay_min, is_popup_slot = 1, popup_lead_time_min = @lead_time, cta_utc = @slot_time
            WHERE slot_id = @slot_id;
            
            INSERT INTO dbo.tmi_flight_control (flight_uid, callsign, program_id, slot_id, cta_utc, octa_utc, aslot, ctl_elem, ctl_type, program_delay_min, orig_eta_utc, dep_airport, is_popup, popup_detected_utc, popup_lead_time_min, control_assigned_utc)
            VALUES (@flight_uid, @callsign, @program_id, @slot_id, @slot_time, @slot_time, @slot_name, @ctl_element, 'GAAP', @delay_min, @eta_utc, @dep_airport, 1, SYSUTCDATETIME(), @lead_time, SYSUTCDATETIME());
            
            UPDATE dbo.tmi_popup_queue SET queue_status = 'ASSIGNED', assigned_slot_id = @slot_id, assigned_utc = SYSUTCDATETIME(), assignment_type = 'RESERVED', processed_at = SYSUTCDATETIME() WHERE queue_id = @queue_id;
            SET @assigned_count = @assigned_count + 1;
        END
        ELSE
        BEGIN
            UPDATE dbo.tmi_popup_queue SET queue_status = 'FAILED', process_notes = 'No slot available', processed_at = SYSUTCDATETIME() WHERE queue_id = @queue_id;
        END
        
        SET @slot_id = NULL;
        FETCH NEXT FROM popup_cursor INTO @queue_id, @flight_uid, @callsign, @eta_utc, @lead_time, @dep_airport, @carrier;
    END
    
    CLOSE popup_cursor;
    DEALLOCATE popup_cursor;
    
    IF @assigned_count > 0
        UPDATE dbo.tmi_programs SET popup_flights = ISNULL(popup_flights, 0) + @assigned_count, controlled_flights = ISNULL(controlled_flights, 0) + @assigned_count WHERE program_id = @program_id;
    
    RETURN 0;
END;
GO

PRINT '  Created: sp_TMI_AssignPopups';

-- ============================================================================
-- sp_TMI_ApplyGroundStop
-- Apply ground stop to flights
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
    DECLARE @end_utc DATETIME2(0);
    
    SELECT @ctl_element = ctl_element, @end_utc = end_utc
    FROM dbo.tmi_programs WHERE program_id = @program_id AND program_type = 'GS';
    
    DELETE FROM dbo.tmi_flight_control WHERE program_id = @program_id;
    
    INSERT INTO dbo.tmi_flight_control (flight_uid, callsign, program_id, ctl_elem, ctl_type, ctl_exempt, ctl_exempt_reason, gs_held, gs_release_utc, orig_eta_utc, orig_etd_utc, dep_airport, arr_airport, dep_center, arr_center, control_assigned_utc)
    SELECT f.flight_uid, f.callsign, @program_id, @ctl_element, 'GS', ISNULL(f.is_exempt, 0), f.exempt_reason, CASE WHEN ISNULL(f.is_exempt, 0) = 1 THEN 0 ELSE 1 END, @end_utc, f.eta_utc, f.etd_utc, f.dep_airport, f.arr_airport, f.dep_center, f.arr_center, SYSUTCDATETIME()
    FROM @flights f;
    
    SELECT @held_count = SUM(CASE WHEN gs_held = 1 THEN 1 ELSE 0 END), @exempt_count = SUM(CASE WHEN ctl_exempt = 1 THEN 1 ELSE 0 END)
    FROM dbo.tmi_flight_control WHERE program_id = @program_id;
    
    UPDATE dbo.tmi_programs SET total_flights = @held_count + @exempt_count, controlled_flights = @held_count, exempt_flights = @exempt_count WHERE program_id = @program_id;
    
    RETURN 0;
END;
GO

PRINT '  Created: sp_TMI_ApplyGroundStop';

-- ============================================================================
-- sp_TMI_TransitionGStoGDP
-- Transition from Ground Stop to GDP
-- ============================================================================
IF OBJECT_ID('dbo.sp_TMI_TransitionGStoGDP', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_TMI_TransitionGStoGDP;
GO

CREATE PROCEDURE dbo.sp_TMI_TransitionGStoGDP
    @gs_program_id      INT,
    @gdp_type           NVARCHAR(16) = 'GDP-DAS',
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
    DECLARE @impacting_condition NVARCHAR(64);
    DECLARE @cause_text NVARCHAR(512);
    DECLARE @scope_json NVARCHAR(MAX);
    DECLARE @gdp_guid UNIQUEIDENTIFIER;
    DECLARE @slot_count INT;
    DECLARE @gs_program_guid UNIQUEIDENTIFIER;
    
    SELECT @ctl_element = ctl_element, @gs_end_utc = end_utc, @impacting_condition = impacting_condition, @cause_text = cause_text, @scope_json = scope_json, @gs_program_guid = program_guid
    FROM dbo.tmi_programs WHERE program_id = @gs_program_id AND program_type = 'GS';
    
    EXEC dbo.sp_TMI_CreateProgram @ctl_element = @ctl_element, @element_type = 'APT', @program_type = @gdp_type,
        @start_utc = @gs_end_utc, @end_utc = @gdp_end_utc, @program_rate = @program_rate, @reserve_rate = @reserve_rate,
        @delay_limit_min = @delay_limit_min, @scope_json = @scope_json, @impacting_condition = @impacting_condition,
        @cause_text = @cause_text, @created_by = @transitioned_by,
        @program_id = @gdp_program_id OUTPUT, @program_guid = @gdp_guid OUTPUT;
    
    UPDATE dbo.tmi_programs SET parent_program_id = @gs_program_id, transition_type = 'GS_TO_GDP',
        cumulative_start = (SELECT start_utc FROM dbo.tmi_programs WHERE program_id = @gs_program_id)
    WHERE program_id = @gdp_program_id;
    
    EXEC dbo.sp_TMI_GenerateSlots @program_id = @gdp_program_id, @slot_count = @slot_count OUTPUT;
    
    UPDATE dbo.tmi_programs SET status = 'COMPLETED', is_active = 0, completed_at = SYSUTCDATETIME(), superseded_by_id = @gdp_program_id WHERE program_id = @gs_program_id;
    UPDATE dbo.tmi_flight_control SET gs_held = 0, control_released_utc = SYSUTCDATETIME() WHERE program_id = @gs_program_id AND gs_held = 1;
    
    EXEC dbo.sp_LogTmiEvent 
        @entity_type = 'PROGRAM',
        @entity_id = @gs_program_id,
        @entity_guid = @gs_program_guid,
        @event_type = 'GS_TO_GDP_TRANSITION',
        @event_detail = 'Transitioned to GDP',
        @source_type = 'USER',
        @actor_id = @transitioned_by,
        @program_id = @gs_program_id;
    
    RETURN 0;
END;
GO

PRINT '  Created: sp_TMI_TransitionGStoGDP';

-- ============================================================================
-- sp_TMI_ExtendProgram
-- Extend program end time
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
    DECLARE @program_guid UNIQUEIDENTIFIER;
    
    SELECT @ctl_element = ctl_element, @program_type = program_type, @old_end_utc = end_utc, @program_rate = program_rate, @reserve_rate = reserve_rate, @program_guid = program_guid
    FROM dbo.tmi_programs WHERE program_id = @program_id;
    
    UPDATE dbo.tmi_programs SET end_utc = @new_end_utc, cumulative_end = @new_end_utc, revision_number = revision_number + 1, updated_at = SYSUTCDATETIME()
    WHERE program_id = @program_id;
    
    SET @new_slots_count = 0;
    
    IF @program_type != 'GS' AND @program_rate > 0
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
        
        WHILE @current_time < @new_end_utc
        BEGIN
            DECLARE @slot_minute INT = DATEPART(HOUR, @current_time) * 100 + DATEPART(MINUTE, @current_time);
            IF @slot_minute = @last_minute SET @suffix_counter = @suffix_counter + 1;
            ELSE BEGIN SET @suffix_counter = 0; SET @last_minute = @slot_minute; END
            
            DECLARE @suffix_char CHAR(1) = CHAR(65 + (@suffix_counter % 26));
            DECLARE @slot_name NVARCHAR(16) = @ctl_element + '.' + FORMAT(@current_time, 'ddHHmm') + @suffix_char;
            DECLARE @slot_type NVARCHAR(16) = CASE WHEN @reserve_interval > 0 AND (@slot_index % @reserve_interval) = 0 THEN 'RESERVED' ELSE 'REGULAR' END;
            
            INSERT INTO dbo.tmi_slots (program_id, slot_name, slot_index, slot_time_utc, slot_type, slot_status, bin_date, bin_hour, bin_quarter)
            VALUES (@program_id, @slot_name, @slot_index, @current_time, @slot_type, 'OPEN', CAST(@current_time AS DATE), DATEPART(HOUR, @current_time), (DATEPART(MINUTE, @current_time) / 15) * 15);
            
            SET @slot_index = @slot_index + 1;
            SET @new_slots_count = @new_slots_count + 1;
            SET @current_time = DATEADD(SECOND, @slot_interval_sec, @current_time);
        END
    END
    
    EXEC dbo.sp_LogTmiEvent 
        @entity_type = 'PROGRAM',
        @entity_id = @program_id,
        @entity_guid = @program_guid,
        @event_type = 'PROGRAM_EXTENDED',
        @event_detail = 'Extended',
        @source_type = 'USER',
        @actor_id = @extended_by,
        @program_id = @program_id;
    
    RETURN 0;
END;
GO

PRINT '  Created: sp_TMI_ExtendProgram';

-- ============================================================================
-- sp_TMI_ArchiveData
-- Archive old data based on retention policy
-- ============================================================================
IF OBJECT_ID('dbo.sp_TMI_ArchiveData', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_TMI_ArchiveData;
GO

CREATE PROCEDURE dbo.sp_TMI_ArchiveData
    @archive_mode       NVARCHAR(16) = 'HOT_TO_COOL',
    @archived_count     INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    
    SET @archived_count = 0;
    DECLARE @cutoff_date DATETIME2(0);
    DECLARE @target_tier TINYINT;
    
    IF @archive_mode = 'HOT_TO_COOL'
    BEGIN
        SET @cutoff_date = DATEADD(DAY, -90, SYSUTCDATETIME());
        SET @target_tier = 2;
        
        UPDATE dbo.tmi_flight_control SET is_archived = 1, archive_tier = @target_tier, archived_at = SYSUTCDATETIME() WHERE is_archived = 0 AND created_at < @cutoff_date;
        SET @archived_count = @archived_count + @@ROWCOUNT;
        
        UPDATE dbo.tmi_slots SET is_archived = 1, archive_tier = @target_tier, archived_at = SYSUTCDATETIME() WHERE is_archived = 0 AND created_at < @cutoff_date;
        SET @archived_count = @archived_count + @@ROWCOUNT;
        
        SET @cutoff_date = DATEADD(YEAR, -5, SYSUTCDATETIME());
        UPDATE dbo.tmi_programs SET is_archived = 1 WHERE is_archived = 0 AND status = 'PURGED' AND purged_at < @cutoff_date;
        SET @archived_count = @archived_count + @@ROWCOUNT;
    END
    ELSE IF @archive_mode = 'COOL_TO_COLD'
    BEGIN
        SET @cutoff_date = DATEADD(YEAR, -1, SYSUTCDATETIME());
        SET @target_tier = 3;
        
        UPDATE dbo.tmi_flight_control SET archive_tier = @target_tier, archived_at = SYSUTCDATETIME() WHERE archive_tier = 2 AND created_at < @cutoff_date;
        SET @archived_count = @archived_count + @@ROWCOUNT;
        
        UPDATE dbo.tmi_slots SET archive_tier = @target_tier, archived_at = SYSUTCDATETIME() WHERE archive_tier = 2 AND created_at < @cutoff_date;
        SET @archived_count = @archived_count + @@ROWCOUNT;
    END
    
    RETURN 0;
END;
GO

PRINT '  Created: sp_TMI_ArchiveData';

PRINT '';
PRINT 'GDT stored procedures v2 created successfully.';
GO
