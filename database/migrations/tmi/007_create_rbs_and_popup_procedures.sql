-- ============================================================================
-- VATSIM_TMI Migration 007: RBS Assignment & Pop-up Detection Procedures
-- Flight-to-Slot Assignment using Ration By Schedule algorithm
-- ============================================================================
-- Version: 1.0.0
-- Date: 2026-01-18
-- Author: HP/Claude
--
-- RBS Algorithm (per FSM spec):
--   1. Order flights by ETA (scheduled arrival time)
--   2. Assign each flight to the next available slot >= their ETA
--   3. Calculate delay as (slot_time - original_eta)
--   4. Cap delay at delay_limit_min
--   5. For GAAP/UDP: Reserve slots for pop-ups
-- ============================================================================

USE VATSIM_TMI;
GO

-- ============================================================================
-- sp_TMI_AssignFlightsRBS
-- Assign flights to slots using Ration By Schedule algorithm
-- This procedure should be called with a TVP of flight data from VATSIM_ADL
-- ============================================================================
IF OBJECT_ID('dbo.sp_TMI_AssignFlightsRBS', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_TMI_AssignFlightsRBS;
GO

-- Create table type for flight input
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
    is_exempt           BIT DEFAULT 0,
    exempt_reason       NVARCHAR(32) NULL,
    PRIMARY KEY (flight_uid)
);
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
    DECLARE @start_utc DATETIME2(0);
    DECLARE @end_utc DATETIME2(0);
    
    -- Get program details
    SELECT 
        @ctl_element = ctl_element,
        @program_type = program_type,
        @delay_limit_min = delay_limit_min,
        @start_utc = start_utc,
        @end_utc = end_utc
    FROM dbo.tmi_programs
    WHERE program_id = @program_id;
    
    IF @ctl_element IS NULL
    BEGIN
        RAISERROR('Program not found: %d', 16, 1, @program_id);
        RETURN 1;
    END
    
    SET @assigned_count = 0;
    SET @exempt_count = 0;
    
    -- Clear existing assignments for this program
    DELETE FROM dbo.tmi_flight_control WHERE program_id = @program_id;
    
    -- Reset slot assignments
    UPDATE dbo.tmi_slots
    SET slot_status = 'OPEN',
        assigned_flight_uid = NULL,
        assigned_callsign = NULL,
        assigned_carrier = NULL,
        assigned_origin = NULL,
        assigned_utc = NULL,
        original_eta_utc = NULL,
        slot_delay_min = NULL,
        is_popup_slot = 0
    WHERE program_id = @program_id;
    
    -- Process flights in ETA order using cursor
    -- (More complex scenarios might use set-based operations)
    DECLARE @flight_uid BIGINT;
    DECLARE @callsign NVARCHAR(12);
    DECLARE @eta_utc DATETIME2(0);
    DECLARE @etd_utc DATETIME2(0);
    DECLARE @dep_airport NVARCHAR(4);
    DECLARE @arr_airport NVARCHAR(4);
    DECLARE @dep_center NVARCHAR(4);
    DECLARE @arr_center NVARCHAR(4);
    DECLARE @carrier NVARCHAR(8);
    DECLARE @aircraft_type NVARCHAR(8);
    DECLARE @flight_status NVARCHAR(16);
    DECLARE @is_exempt BIT;
    DECLARE @exempt_reason NVARCHAR(32);
    
    DECLARE @slot_id BIGINT;
    DECLARE @slot_name NVARCHAR(20);
    DECLARE @slot_time DATETIME2(0);
    DECLARE @delay_min INT;
    DECLARE @delay_capped BIT;
    DECLARE @ctd_utc DATETIME2(0);
    DECLARE @cta_utc DATETIME2(0);
    DECLARE @ete_min INT;
    
    DECLARE flight_cursor CURSOR LOCAL FAST_FORWARD FOR
        SELECT 
            flight_uid, callsign, eta_utc, etd_utc,
            dep_airport, arr_airport, dep_center, arr_center,
            carrier, aircraft_type, flight_status,
            is_exempt, exempt_reason
        FROM @flights
        ORDER BY eta_utc, flight_uid;
    
    OPEN flight_cursor;
    FETCH NEXT FROM flight_cursor INTO 
        @flight_uid, @callsign, @eta_utc, @etd_utc,
        @dep_airport, @arr_airport, @dep_center, @arr_center,
        @carrier, @aircraft_type, @flight_status,
        @is_exempt, @exempt_reason;
    
    WHILE @@FETCH_STATUS = 0
    BEGIN
        -- Skip exempt flights
        IF @is_exempt = 1
        BEGIN
            -- Record exempt flight
            INSERT INTO dbo.tmi_flight_control (
                flight_uid, callsign, program_id,
                ctl_elem, ctl_type, ctl_exempt, ctl_exempt_reason,
                orig_eta_utc, orig_etd_utc,
                dep_airport, arr_airport, dep_center, arr_center,
                flight_status_at_ctl, control_assigned_utc
            )
            VALUES (
                @flight_uid, @callsign, @program_id,
                @ctl_element, @program_type, 1, @exempt_reason,
                @eta_utc, @etd_utc,
                @dep_airport, @arr_airport, @dep_center, @arr_center,
                @flight_status, SYSUTCDATETIME()
            );
            
            SET @exempt_count = @exempt_count + 1;
        END
        ELSE
        BEGIN
            -- Find next available slot at or after ETA
            -- Skip RESERVED slots unless we're in GAAP/UDP and no regular slots available
            SELECT TOP 1 
                @slot_id = slot_id,
                @slot_name = slot_name,
                @slot_time = slot_time_utc
            FROM dbo.tmi_slots
            WHERE program_id = @program_id
              AND slot_status = 'OPEN'
              AND slot_type = 'REGULAR'
              AND slot_time_utc >= @eta_utc
            ORDER BY slot_time_utc, slot_index;
            
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
                ORDER BY slot_time_utc, slot_index;
            END
            
            IF @slot_id IS NOT NULL
            BEGIN
                -- Calculate delay
                SET @delay_min = DATEDIFF(MINUTE, @eta_utc, @slot_time);
                SET @delay_capped = 0;
                
                -- Cap delay at limit
                IF @delay_min > @delay_limit_min
                BEGIN
                    SET @delay_min = @delay_limit_min;
                    SET @delay_capped = 1;
                END
                
                -- Calculate en route time and CTD
                SET @ete_min = DATEDIFF(MINUTE, @etd_utc, @eta_utc);
                SET @cta_utc = @slot_time;
                SET @ctd_utc = DATEADD(MINUTE, -@ete_min, @cta_utc);
                
                -- Update slot
                UPDATE dbo.tmi_slots
                SET slot_status = 'ASSIGNED',
                    assigned_flight_uid = @flight_uid,
                    assigned_callsign = @callsign,
                    assigned_carrier = @carrier,
                    assigned_origin = @dep_airport,
                    assigned_utc = SYSUTCDATETIME(),
                    original_eta_utc = @eta_utc,
                    slot_delay_min = @delay_min,
                    modified_utc = SYSUTCDATETIME()
                WHERE slot_id = @slot_id;
                
                -- Insert flight control record
                INSERT INTO dbo.tmi_flight_control (
                    flight_uid, callsign, program_id, slot_id,
                    ctd_utc, cta_utc, octd_utc, octa_utc,
                    aslot, ctl_elem, ctl_type, ctl_exempt,
                    program_delay_min, delay_capped,
                    orig_eta_utc, orig_etd_utc, orig_ete_min,
                    dep_airport, arr_airport, dep_center, arr_center,
                    flight_status_at_ctl, control_assigned_utc
                )
                VALUES (
                    @flight_uid, @callsign, @program_id, @slot_id,
                    @ctd_utc, @cta_utc, @ctd_utc, @cta_utc,
                    @slot_name, @ctl_element, 
                    CASE @program_type 
                        WHEN 'GDP-DAS' THEN 'DAS'
                        WHEN 'GDP-GAAP' THEN 'GAAP'
                        WHEN 'GDP-UDP' THEN 'UDP'
                        ELSE @program_type 
                    END,
                    0,
                    @delay_min, @delay_capped,
                    @eta_utc, @etd_utc, @ete_min,
                    @dep_airport, @arr_airport, @dep_center, @arr_center,
                    @flight_status, SYSUTCDATETIME()
                );
                
                SET @assigned_count = @assigned_count + 1;
            END
        END
        
        -- Reset for next iteration
        SET @slot_id = NULL;
        
        FETCH NEXT FROM flight_cursor INTO 
            @flight_uid, @callsign, @eta_utc, @etd_utc,
            @dep_airport, @arr_airport, @dep_center, @arr_center,
            @carrier, @aircraft_type, @flight_status,
            @is_exempt, @exempt_reason;
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
                         WHERE program_id = @program_id AND ctl_exempt = 0),
        max_delay_min = (SELECT MAX(program_delay_min) 
                         FROM dbo.tmi_flight_control 
                         WHERE program_id = @program_id AND ctl_exempt = 0),
        total_delay_min = (SELECT SUM(program_delay_min) 
                           FROM dbo.tmi_flight_control 
                           WHERE program_id = @program_id AND ctl_exempt = 0),
        modified_utc = SYSUTCDATETIME()
    WHERE program_id = @program_id;
    
    -- Log event
    INSERT INTO dbo.tmi_events (
        event_type, program_id, ctl_element,
        description, event_source,
        details_json
    )
    VALUES (
        'FLIGHTS_ASSIGNED', @program_id, @ctl_element,
        'Assigned ' + CAST(@assigned_count AS VARCHAR(10)) + ' flights, ' +
        CAST(@exempt_count AS VARCHAR(10)) + ' exempt',
        'SYSTEM',
        '{"assigned":' + CAST(@assigned_count AS VARCHAR(10)) + 
        ',"exempt":' + CAST(@exempt_count AS VARCHAR(10)) + '}'
    );
    
    RETURN 0;
END;
GO

-- ============================================================================
-- sp_TMI_DetectPopups
-- Detect new flights that appeared after program start (pop-ups)
-- Called by ADL refresh daemon
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
    DECLARE @program_type NVARCHAR(16);
    DECLARE @start_utc DATETIME2(0);
    DECLARE @end_utc DATETIME2(0);
    
    SELECT 
        @ctl_element = ctl_element,
        @program_type = program_type,
        @start_utc = start_utc,
        @end_utc = end_utc
    FROM dbo.tmi_programs
    WHERE program_id = @program_id
      AND is_active = 1;
    
    IF @ctl_element IS NULL
    BEGIN
        SET @popup_count = 0;
        RETURN 0;  -- No active program
    END
    
    -- Find flights in input that are NOT already controlled
    INSERT INTO dbo.tmi_popup_queue (
        flight_uid,
        callsign,
        program_id,
        detected_utc,
        flight_eta_utc,
        lead_time_min,
        dep_airport,
        arr_airport,
        dep_center,
        aircraft_type,
        carrier,
        queue_status
    )
    SELECT 
        f.flight_uid,
        f.callsign,
        @program_id,
        SYSUTCDATETIME(),
        f.eta_utc,
        DATEDIFF(MINUTE, SYSUTCDATETIME(), f.eta_utc),
        f.dep_airport,
        f.arr_airport,
        f.dep_center,
        f.aircraft_type,
        f.carrier,
        'PENDING'
    FROM @flights f
    WHERE f.eta_utc BETWEEN @start_utc AND @end_utc
      AND f.is_exempt = 0
      AND NOT EXISTS (
          SELECT 1 FROM dbo.tmi_flight_control fc
          WHERE fc.flight_uid = f.flight_uid
            AND fc.program_id = @program_id
      )
      AND NOT EXISTS (
          SELECT 1 FROM dbo.tmi_popup_queue q
          WHERE q.flight_uid = f.flight_uid
            AND q.program_id = @program_id
      );
    
    SET @popup_count = @@ROWCOUNT;
    
    -- Log if any popups detected
    IF @popup_count > 0
    BEGIN
        INSERT INTO dbo.tmi_events (
            event_type, program_id, ctl_element,
            description, event_source,
            details_json
        )
        VALUES (
            'POPUPS_DETECTED', @program_id, @ctl_element,
            'Detected ' + CAST(@popup_count AS VARCHAR(10)) + ' pop-up flights',
            'DAEMON',
            '{"count":' + CAST(@popup_count AS VARCHAR(10)) + '}'
        );
    END
    
    RETURN 0;
END;
GO

-- ============================================================================
-- sp_TMI_AssignPopups
-- Auto-assign pending pop-ups to available slots (GAAP/UDP mode)
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
    
    SELECT 
        @ctl_element = ctl_element,
        @program_type = program_type,
        @delay_limit_min = delay_limit_min
    FROM dbo.tmi_programs
    WHERE program_id = @program_id
      AND is_active = 1;
    
    IF @program_type NOT IN ('GDP-GAAP', 'GDP-UDP')
    BEGIN
        -- DAS mode: pop-ups get average delay, not slot assignment
        -- This is handled differently
        SET @assigned_count = 0;
        RETURN 0;
    END
    
    SET @assigned_count = 0;
    
    -- Process pending pop-ups in ETA order
    DECLARE @queue_id BIGINT;
    DECLARE @flight_uid BIGINT;
    DECLARE @callsign NVARCHAR(12);
    DECLARE @eta_utc DATETIME2(0);
    DECLARE @lead_time INT;
    DECLARE @dep_airport NVARCHAR(4);
    DECLARE @arr_airport NVARCHAR(4);
    DECLARE @dep_center NVARCHAR(4);
    DECLARE @carrier NVARCHAR(8);
    
    DECLARE @slot_id BIGINT;
    DECLARE @slot_name NVARCHAR(20);
    DECLARE @slot_time DATETIME2(0);
    DECLARE @delay_min INT;
    
    DECLARE popup_cursor CURSOR LOCAL FAST_FORWARD FOR
        SELECT queue_id, flight_uid, callsign, flight_eta_utc, lead_time_min,
               dep_airport, arr_airport, dep_center, carrier
        FROM dbo.tmi_popup_queue
        WHERE program_id = @program_id
          AND queue_status = 'PENDING'
        ORDER BY flight_eta_utc;
    
    OPEN popup_cursor;
    FETCH NEXT FROM popup_cursor INTO 
        @queue_id, @flight_uid, @callsign, @eta_utc, @lead_time,
        @dep_airport, @arr_airport, @dep_center, @carrier;
    
    WHILE @@FETCH_STATUS = 0
    BEGIN
        -- Find available RESERVED slot first, then any open slot
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
        
        -- If no reserved slot, try unassigned
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
            SET @delay_min = DATEDIFF(MINUTE, @eta_utc, @slot_time);
            IF @delay_min > @delay_limit_min
                SET @delay_min = @delay_limit_min;
            
            -- Assign slot
            UPDATE dbo.tmi_slots
            SET slot_status = 'ASSIGNED',
                assigned_flight_uid = @flight_uid,
                assigned_callsign = @callsign,
                assigned_carrier = @carrier,
                assigned_origin = @dep_airport,
                assigned_utc = SYSUTCDATETIME(),
                original_eta_utc = @eta_utc,
                slot_delay_min = @delay_min,
                is_popup_slot = 1,
                popup_lead_time_min = @lead_time,
                modified_utc = SYSUTCDATETIME()
            WHERE slot_id = @slot_id;
            
            -- Create flight control record
            INSERT INTO dbo.tmi_flight_control (
                flight_uid, callsign, program_id, slot_id,
                cta_utc, octa_utc, aslot, ctl_elem, ctl_type,
                program_delay_min, orig_eta_utc,
                dep_airport, arr_airport, dep_center,
                is_popup, popup_detected_utc, popup_lead_time_min,
                control_assigned_utc
            )
            VALUES (
                @flight_uid, @callsign, @program_id, @slot_id,
                @slot_time, @slot_time, @slot_name, @ctl_element, 'GAAP',
                @delay_min, @eta_utc,
                @dep_airport, @arr_airport, @dep_center,
                1, SYSUTCDATETIME(), @lead_time,
                SYSUTCDATETIME()
            );
            
            -- Update queue
            UPDATE dbo.tmi_popup_queue
            SET queue_status = 'ASSIGNED',
                assigned_slot_id = @slot_id,
                assigned_utc = SYSUTCDATETIME(),
                assignment_type = 'RESERVED',
                processed_utc = SYSUTCDATETIME()
            WHERE queue_id = @queue_id;
            
            SET @assigned_count = @assigned_count + 1;
        END
        ELSE
        BEGIN
            -- No slot available - mark for DAS delay
            UPDATE dbo.tmi_popup_queue
            SET queue_status = 'FAILED',
                process_notes = 'No available slot',
                processed_utc = SYSUTCDATETIME()
            WHERE queue_id = @queue_id;
        END
        
        SET @slot_id = NULL;
        
        FETCH NEXT FROM popup_cursor INTO 
            @queue_id, @flight_uid, @callsign, @eta_utc, @lead_time,
            @dep_airport, @arr_airport, @dep_center, @carrier;
    END
    
    CLOSE popup_cursor;
    DEALLOCATE popup_cursor;
    
    -- Update program popup count
    IF @assigned_count > 0
    BEGIN
        UPDATE dbo.tmi_programs
        SET popup_flights = ISNULL(popup_flights, 0) + @assigned_count,
            controlled_flights = ISNULL(controlled_flights, 0) + @assigned_count,
            modified_utc = SYSUTCDATETIME()
        WHERE program_id = @program_id;
        
        INSERT INTO dbo.tmi_events (
            event_type, program_id, ctl_element,
            description, event_source,
            details_json
        )
        VALUES (
            'POPUPS_ASSIGNED', @program_id, @ctl_element,
            'Auto-assigned ' + CAST(@assigned_count AS VARCHAR(10)) + ' pop-up flights',
            'DAEMON',
            '{"count":' + CAST(@assigned_count AS VARCHAR(10)) + '}'
        );
    END
    
    RETURN 0;
END;
GO

PRINT 'Migration 007: RBS assignment and pop-up detection procedures created successfully';
GO
