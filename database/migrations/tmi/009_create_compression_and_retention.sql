-- ============================================================================
-- VATSIM_TMI Migration 009: Compression & Retention Procedures
-- Manual and Adaptive Compression, Data Archival
-- ============================================================================
-- Version: 1.0.0
-- Date: 2026-01-18
-- Author: HP/Claude
--
-- Compression Algorithm (per FSM spec):
--   1. Find slots that are ASSIGNED but have no-show flights (departed/airborne)
--   2. Bridge later flights forward into earlier slots
--   3. Reduce total delay while maintaining schedule order
-- ============================================================================

USE VATSIM_TMI;
GO

-- ============================================================================
-- sp_TMI_RunCompression
-- Manual compression of slot assignments
-- ============================================================================
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
    -- Slots that are assigned but the flight has already departed/arrived
    -- These slots can be reclaimed for later flights
    
    DECLARE @free_slots TABLE (
        slot_id BIGINT,
        slot_time_utc DATETIME2(0),
        slot_index INT
    );
    
    -- Get slots where assigned flight is no longer needing the slot
    -- (departed before CTD, or already airborne/arrived)
    INSERT INTO @free_slots (slot_id, slot_time_utc, slot_index)
    SELECT s.slot_id, s.slot_time_utc, s.slot_index
    FROM dbo.tmi_slots s
    INNER JOIN dbo.tmi_flight_control fc ON s.slot_id = fc.slot_id
    WHERE s.program_id = @program_id
      AND s.slot_status = 'ASSIGNED'
      AND (
          fc.actual_dep_utc IS NOT NULL  -- Flight already departed
          OR fc.compliance_status IN ('EARLY', 'NO_SHOW')  -- Didn't use slot
      )
      AND s.slot_time_utc > SYSUTCDATETIME()  -- Future slot
    ORDER BY s.slot_time_utc;
    
    -- Find flights that can be moved to earlier slots
    DECLARE @moveable_flights TABLE (
        control_id BIGINT,
        flight_uid BIGINT,
        current_slot_id BIGINT,
        current_cta DATETIME2(0),
        orig_eta DATETIME2(0),
        current_delay INT
    );
    
    INSERT INTO @moveable_flights
    SELECT 
        fc.control_id,
        fc.flight_uid,
        fc.slot_id,
        fc.cta_utc,
        fc.orig_eta_utc,
        fc.program_delay_min
    FROM dbo.tmi_flight_control fc
    INNER JOIN dbo.tmi_slots s ON fc.slot_id = s.slot_id
    WHERE fc.program_id = @program_id
      AND fc.ctl_exempt = 0
      AND fc.actual_dep_utc IS NULL  -- Not yet departed
      AND fc.compliance_status IS NULL OR fc.compliance_status = 'PENDING'
      AND s.slot_time_utc > SYSUTCDATETIME()
    ORDER BY fc.cta_utc DESC;  -- Start with latest flights
    
    -- Process compression: move flights to earlier slots
    DECLARE @free_slot_id BIGINT;
    DECLARE @free_slot_time DATETIME2(0);
    DECLARE @free_slot_index INT;
    DECLARE @move_control_id BIGINT;
    DECLARE @move_flight_uid BIGINT;
    DECLARE @move_current_slot BIGINT;
    DECLARE @move_current_cta DATETIME2(0);
    DECLARE @move_orig_eta DATETIME2(0);
    DECLARE @move_current_delay INT;
    DECLARE @new_delay INT;
    DECLARE @delay_reduction INT;
    
    DECLARE free_cursor CURSOR LOCAL FAST_FORWARD FOR
        SELECT slot_id, slot_time_utc, slot_index FROM @free_slots ORDER BY slot_time_utc;
    
    OPEN free_cursor;
    FETCH NEXT FROM free_cursor INTO @free_slot_id, @free_slot_time, @free_slot_index;
    
    WHILE @@FETCH_STATUS = 0
    BEGIN
        -- Find a flight that can use this earlier slot
        -- Must have ETA <= slot time and current CTA > slot time
        SELECT TOP 1
            @move_control_id = control_id,
            @move_flight_uid = flight_uid,
            @move_current_slot = current_slot_id,
            @move_current_cta = current_cta,
            @move_orig_eta = orig_eta,
            @move_current_delay = current_delay
        FROM @moveable_flights
        WHERE orig_eta <= @free_slot_time
          AND current_cta > @free_slot_time
        ORDER BY current_delay DESC;  -- Prioritize flights with most delay
        
        IF @move_control_id IS NOT NULL
        BEGIN
            -- Calculate new delay
            SET @new_delay = DATEDIFF(MINUTE, @move_orig_eta, @free_slot_time);
            SET @delay_reduction = @move_current_delay - @new_delay;
            
            -- Update slot assignment
            UPDATE dbo.tmi_slots
            SET slot_status = 'COMPRESSED',
                modified_utc = SYSUTCDATETIME()
            WHERE slot_id = @move_current_slot;
            
            -- Assign flight to earlier slot
            UPDATE dbo.tmi_slots
            SET assigned_flight_uid = @move_flight_uid,
                slot_status = 'ASSIGNED',
                modified_utc = SYSUTCDATETIME()
            WHERE slot_id = @free_slot_id;
            
            -- Update flight control
            UPDATE dbo.tmi_flight_control
            SET slot_id = @free_slot_id,
                cta_utc = @free_slot_time,
                ctd_utc = DATEADD(MINUTE, -orig_ete_min, @free_slot_time),
                aslot = (SELECT slot_name FROM dbo.tmi_slots WHERE slot_id = @free_slot_id),
                ctl_type = 'COMP',
                program_delay_min = @new_delay,
                modified_utc = SYSUTCDATETIME()
            WHERE control_id = @move_control_id;
            
            -- Track metrics
            SET @slots_compressed = @slots_compressed + 1;
            SET @delay_saved_min = @delay_saved_min + @delay_reduction;
            
            -- Remove from moveable list
            DELETE FROM @moveable_flights WHERE control_id = @move_control_id;
            
            -- Log individual compression
            INSERT INTO dbo.tmi_events (
                event_type, event_subtype, program_id, ctl_element,
                flight_uid, slot_id,
                description, event_source, event_user,
                old_value, new_value
            )
            VALUES (
                'SLOT_COMPRESSED', 'FLIGHT_MOVED', @program_id, @ctl_element,
                @move_flight_uid, @free_slot_id,
                'Flight compressed to earlier slot, saved ' + CAST(@delay_reduction AS VARCHAR(10)) + ' min',
                CASE WHEN @compression_by IS NULL THEN 'SYSTEM' ELSE 'USER' END,
                @compression_by,
                CAST(@move_current_delay AS VARCHAR(10)) + ' min delay',
                CAST(@new_delay AS VARCHAR(10)) + ' min delay'
            );
        END
        
        SET @move_control_id = NULL;
        FETCH NEXT FROM free_cursor INTO @free_slot_id, @free_slot_time, @free_slot_index;
    END
    
    CLOSE free_cursor;
    DEALLOCATE free_cursor;
    
    -- Update program metrics
    UPDATE dbo.tmi_programs
    SET last_compression_utc = SYSUTCDATETIME(),
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
    
    -- Log compression summary
    INSERT INTO dbo.tmi_events (
        event_type, program_id, ctl_element,
        description, event_source, event_user,
        details_json
    )
    VALUES (
        'COMPRESSION_COMPLETE', @program_id, @ctl_element,
        'Compression complete: ' + CAST(@slots_compressed AS VARCHAR(10)) + ' slots, ' +
        CAST(@delay_saved_min AS VARCHAR(10)) + ' minutes saved',
        CASE WHEN @compression_by IS NULL THEN 'SYSTEM' ELSE 'USER' END,
        @compression_by,
        '{"slots_compressed":' + CAST(@slots_compressed AS VARCHAR(10)) + 
        ',"delay_saved_min":' + CAST(@delay_saved_min AS VARCHAR(10)) + '}'
    );
    
    RETURN 0;
END;
GO

-- ============================================================================
-- sp_TMI_AdaptiveCompression
-- Automatic adaptive compression (runs periodically via daemon)
-- ============================================================================
IF OBJECT_ID('dbo.sp_TMI_AdaptiveCompression', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_TMI_AdaptiveCompression;
GO

CREATE PROCEDURE dbo.sp_TMI_AdaptiveCompression
    @program_id         INT = NULL,     -- NULL = process all active programs
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
            @compression_by = NULL,  -- System-initiated
            @slots_compressed = @slots_compressed OUTPUT,
            @delay_saved_min = @delay_saved OUTPUT;
        
        SET @total_compressed = @total_compressed + @slots_compressed;
        SET @total_delay_saved = @total_delay_saved + @delay_saved;
        
        FETCH NEXT FROM program_cursor INTO @pid;
    END
    
    CLOSE program_cursor;
    DEALLOCATE program_cursor;
    
    RETURN 0;
END;
GO

-- ============================================================================
-- sp_TMI_ArchiveData
-- Archive old data based on retention policy
-- Retention: Hot 90 days → Cool 1 year → Cold indefinite
-- ============================================================================
IF OBJECT_ID('dbo.sp_TMI_ArchiveData', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_TMI_ArchiveData;
GO

CREATE PROCEDURE dbo.sp_TMI_ArchiveData
    @archive_mode       NVARCHAR(16) = 'HOT_TO_COOL',  -- HOT_TO_COOL, COOL_TO_COLD
    @archived_count     INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    
    SET @archived_count = 0;
    DECLARE @cutoff_date DATETIME2(0);
    DECLARE @target_tier TINYINT;
    
    IF @archive_mode = 'HOT_TO_COOL'
    BEGIN
        -- Move data older than 90 days to Cool tier
        SET @cutoff_date = DATEADD(DAY, -90, SYSUTCDATETIME());
        SET @target_tier = 2;  -- Cool
        
        -- Archive flight control records
        UPDATE dbo.tmi_flight_control
        SET is_archived = 1,
            archive_tier = @target_tier,
            archived_utc = SYSUTCDATETIME()
        WHERE is_archived = 0
          AND created_utc < @cutoff_date;
        
        SET @archived_count = @archived_count + @@ROWCOUNT;
        
        -- Archive slots
        UPDATE dbo.tmi_slots
        SET is_archived = 1,
            archive_tier = @target_tier,
            archived_utc = SYSUTCDATETIME()
        WHERE is_archived = 0
          AND created_utc < @cutoff_date;
        
        SET @archived_count = @archived_count + @@ROWCOUNT;
    END
    ELSE IF @archive_mode = 'COOL_TO_COLD'
    BEGIN
        -- Move Cool data older than 1 year to Cold tier
        SET @cutoff_date = DATEADD(YEAR, -1, SYSUTCDATETIME());
        SET @target_tier = 3;  -- Cold
        
        UPDATE dbo.tmi_flight_control
        SET archive_tier = @target_tier,
            archived_utc = SYSUTCDATETIME()
        WHERE archive_tier = 2  -- Cool
          AND created_utc < @cutoff_date;
        
        SET @archived_count = @archived_count + @@ROWCOUNT;
        
        UPDATE dbo.tmi_slots
        SET archive_tier = @target_tier,
            archived_utc = SYSUTCDATETIME()
        WHERE archive_tier = 2
          AND created_utc < @cutoff_date;
        
        SET @archived_count = @archived_count + @@ROWCOUNT;
    END
    
    -- Archive programs (5 years for purged)
    IF @archive_mode = 'HOT_TO_COOL'
    BEGIN
        SET @cutoff_date = DATEADD(YEAR, -5, SYSUTCDATETIME());
        
        UPDATE dbo.tmi_programs
        SET is_archived = 1
        WHERE is_archived = 0
          AND status = 'PURGED'
          AND purged_utc < @cutoff_date;
        
        SET @archived_count = @archived_count + @@ROWCOUNT;
    END
    
    -- Log archival
    INSERT INTO dbo.tmi_events (
        event_type, description, event_source,
        details_json
    )
    VALUES (
        'DATA_ARCHIVED',
        'Archived ' + CAST(@archived_count AS VARCHAR(10)) + ' records (' + @archive_mode + ')',
        'SYSTEM',
        '{"mode":"' + @archive_mode + '","count":' + CAST(@archived_count AS VARCHAR(10)) + '}'
    );
    
    RETURN 0;
END;
GO

PRINT 'Migration 009: Compression and retention procedures created successfully';
GO
