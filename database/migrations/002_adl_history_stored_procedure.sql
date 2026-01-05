-- ============================================================================
-- 002_adl_history_stored_procedure.sql
-- 
-- Stored procedure to insert ADL flight data into history table
-- Run this AFTER 001_create_reroute_tables_sqlserver.sql
-- ============================================================================

-- Create history table if it doesn't exist
IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.adl_flights_history') AND type = 'U')
BEGIN
    CREATE TABLE dbo.adl_flights_history (
        id BIGINT IDENTITY(1,1) PRIMARY KEY,
        flight_key NVARCHAR(64) NOT NULL,
        callsign NVARCHAR(12),
        cid INT,
        
        -- Position data
        lat DECIMAL(9,6),
        lon DECIMAL(10,6),
        altitude_ft INT,
        groundspeed_kts INT,
        heading INT,
        
        -- Flight plan data
        fp_dept_icao NVARCHAR(4),
        fp_dest_icao NVARCHAR(4),
        fp_route NVARCHAR(MAX),
        fp_altitude_ft INT,
        aircraft_type NVARCHAR(8),
        
        -- New fields from design doc
        dfix NVARCHAR(10),           -- Departure fix
        afix NVARCHAR(10),           -- Arrival fix
        dp_name NVARCHAR(32),        -- Departure procedure name
        star_name NVARCHAR(32),      -- STAR name
        
        -- Timing
        etd_runway_utc DATETIME2,
        eta_runway_utc DATETIME2,
        ctd_utc DATETIME2,
        cta_utc DATETIME2,
        
        -- Control fields
        ctl_type NVARCHAR(8),
        ctl_element NVARCHAR(8),
        delay_status NVARCHAR(16),
        
        -- Phase tracking
        phase NVARCHAR(16),
        
        -- Snapshot metadata
        snapshot_utc DATETIME2 NOT NULL DEFAULT GETUTCDATE(),
        snapshot_source NVARCHAR(16) DEFAULT 'ADL',  -- 'ADL', 'VATSIM', 'SIMTRAFFIC'
        
        -- Indexes
        INDEX ix_history_flight_key (flight_key),
        INDEX ix_history_callsign (callsign),
        INDEX ix_history_snapshot (snapshot_utc),
        INDEX ix_history_composite (flight_key, snapshot_utc)
    );
    
    PRINT 'Created table dbo.adl_flights_history';
END
GO

-- ============================================================================
-- Stored Procedure: sp_snapshot_adl_to_history
-- 
-- Takes a snapshot of current ADL flights and inserts into history table.
-- Can be called on a schedule (e.g., every 5 minutes) or on demand.
-- ============================================================================

IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.sp_snapshot_adl_to_history') AND type = 'P')
    DROP PROCEDURE dbo.sp_snapshot_adl_to_history;
GO

CREATE PROCEDURE dbo.sp_snapshot_adl_to_history
    @source NVARCHAR(16) = 'ADL',
    @flight_keys NVARCHAR(MAX) = NULL,  -- Optional: comma-separated list to snapshot specific flights
    @active_only BIT = 1
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @snapshot_time DATETIME2 = GETUTCDATE();
    DECLARE @rows_inserted INT = 0;
    
    -- If specific flight keys provided, use them; otherwise snapshot all active
    IF @flight_keys IS NOT NULL AND LEN(@flight_keys) > 0
    BEGIN
        -- Parse comma-separated flight keys
        INSERT INTO dbo.adl_flights_history (
            flight_key, callsign, cid,
            lat, lon, altitude_ft, groundspeed_kts, heading,
            fp_dept_icao, fp_dest_icao, fp_route, fp_altitude_ft, aircraft_type,
            etd_runway_utc, eta_runway_utc, ctd_utc, cta_utc,
            ctl_type, ctl_element, delay_status, phase,
            snapshot_utc, snapshot_source
        )
        SELECT 
            a.flight_key, a.callsign, a.cid,
            a.lat, a.lon, a.altitude_ft, a.groundspeed_kts, a.heading,
            a.fp_dept_icao, a.fp_dest_icao, a.fp_route, a.fp_altitude_ft, a.aircraft_type,
            a.etd_runway_utc, a.eta_runway_utc, a.ctd_utc, a.cta_utc,
            a.ctl_type, a.ctl_element, a.delay_status, a.phase,
            @snapshot_time, @source
        FROM dbo.adl_flights a
        WHERE a.flight_key IN (SELECT TRIM(value) FROM STRING_SPLIT(@flight_keys, ','));
    END
    ELSE
    BEGIN
        -- Snapshot all active flights
        INSERT INTO dbo.adl_flights_history (
            flight_key, callsign, cid,
            lat, lon, altitude_ft, groundspeed_kts, heading,
            fp_dept_icao, fp_dest_icao, fp_route, fp_altitude_ft, aircraft_type,
            etd_runway_utc, eta_runway_utc, ctd_utc, cta_utc,
            ctl_type, ctl_element, delay_status, phase,
            snapshot_utc, snapshot_source
        )
        SELECT 
            a.flight_key, a.callsign, a.cid,
            a.lat, a.lon, a.altitude_ft, a.groundspeed_kts, a.heading,
            a.fp_dept_icao, a.fp_dest_icao, a.fp_route, a.fp_altitude_ft, a.aircraft_type,
            a.etd_runway_utc, a.eta_runway_utc, a.ctd_utc, a.cta_utc,
            a.ctl_type, a.ctl_element, a.delay_status, a.phase,
            @snapshot_time, @source
        FROM dbo.adl_flights a
        WHERE (@active_only = 0 OR a.is_active = 1);
    END
    
    SET @rows_inserted = @@ROWCOUNT;
    
    SELECT @rows_inserted AS rows_inserted, @snapshot_time AS snapshot_utc;
END
GO

PRINT 'Created stored procedure dbo.sp_snapshot_adl_to_history';
GO

-- ============================================================================
-- Stored Procedure: sp_get_flight_track
-- 
-- Retrieves historical track for a specific flight
-- ============================================================================

IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.sp_get_flight_track') AND type = 'P')
    DROP PROCEDURE dbo.sp_get_flight_track;
GO

CREATE PROCEDURE dbo.sp_get_flight_track
    @flight_key NVARCHAR(64),
    @since_utc DATETIME2 = NULL,
    @limit INT = 500
AS
BEGIN
    SET NOCOUNT ON;
    
    IF @since_utc IS NULL
        SET @since_utc = DATEADD(HOUR, -24, GETUTCDATE());
    
    SELECT TOP (@limit)
        flight_key, callsign,
        lat, lon, altitude_ft, groundspeed_kts, heading,
        phase, snapshot_utc
    FROM dbo.adl_flights_history
    WHERE flight_key = @flight_key
      AND snapshot_utc >= @since_utc
      AND lat IS NOT NULL
      AND lon IS NOT NULL
    ORDER BY snapshot_utc ASC;
END
GO

PRINT 'Created stored procedure dbo.sp_get_flight_track';
GO

-- ============================================================================
-- Stored Procedure: sp_cleanup_old_history
-- 
-- Removes history records older than retention period (default 7 days)
-- ============================================================================

IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.sp_cleanup_old_history') AND type = 'P')
    DROP PROCEDURE dbo.sp_cleanup_old_history;
GO

CREATE PROCEDURE dbo.sp_cleanup_old_history
    @retention_days INT = 7
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @cutoff DATETIME2 = DATEADD(DAY, -@retention_days, GETUTCDATE());
    DECLARE @rows_deleted INT;
    
    DELETE FROM dbo.adl_flights_history
    WHERE snapshot_utc < @cutoff;
    
    SET @rows_deleted = @@ROWCOUNT;
    
    SELECT @rows_deleted AS rows_deleted, @cutoff AS cutoff_utc;
END
GO

PRINT 'Created stored procedure dbo.sp_cleanup_old_history';
GO

-- ============================================================================
-- Usage Examples:
-- ============================================================================
-- 
-- Snapshot all active flights:
--   EXEC dbo.sp_snapshot_adl_to_history;
-- 
-- Snapshot specific flights:
--   EXEC dbo.sp_snapshot_adl_to_history @flight_keys = 'AAL123_1234567890,UAL456_0987654321';
-- 
-- Snapshot from a different source:
--   EXEC dbo.sp_snapshot_adl_to_history @source = 'VATSIM';
-- 
-- Get flight track:
--   EXEC dbo.sp_get_flight_track @flight_key = 'AAL123_1234567890';
-- 
-- Cleanup old records:
--   EXEC dbo.sp_cleanup_old_history @retention_days = 7;
-- 
-- ============================================================================
