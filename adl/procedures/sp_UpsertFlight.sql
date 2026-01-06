-- ============================================================================
-- sp_UpsertFlight.sql
-- Data Ingestion for ADL Normalized Schema
-- 
-- Handles upserts from VATSIM Data API and SimTraffic into normalized tables.
-- Designed to be called by PHP daemons for each flight update.
-- ============================================================================

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

-- ============================================================================
-- sp_UpsertFlight - Single flight upsert
-- ============================================================================
IF OBJECT_ID('dbo.sp_UpsertFlight', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_UpsertFlight;
GO

CREATE PROCEDURE dbo.sp_UpsertFlight
    -- Core identifiers
    @cid INT,
    @callsign NVARCHAR(16),
    @source NVARCHAR(16) = 'vatsim',           -- vatsim, simtraffic, prefile
    
    -- Position data
    @lat DECIMAL(10,7) = NULL,
    @lon DECIMAL(11,7) = NULL,
    @altitude_ft INT = NULL,
    @groundspeed_kts INT = NULL,
    @heading_deg SMALLINT = NULL,
    @vertical_rate_fpm INT = NULL,
    
    -- Flight plan
    @fp_rule NCHAR(1) = NULL,
    @dept_icao CHAR(4) = NULL,
    @dest_icao CHAR(4) = NULL,
    @alt_icao CHAR(4) = NULL,
    @route NVARCHAR(MAX) = NULL,
    @remarks NVARCHAR(MAX) = NULL,
    @altitude_filed INT = NULL,
    @tas_filed INT = NULL,
    @dep_time_z CHAR(4) = NULL,
    @enroute_minutes INT = NULL,
    @fuel_minutes INT = NULL,
    
    -- Aircraft
    @aircraft_type NVARCHAR(8) = NULL,
    @aircraft_equip NVARCHAR(32) = NULL,
    
    -- VATSIM specific
    @flight_id NVARCHAR(32) = NULL,
    @logon_time DATETIME2(0) = NULL,
    @qnh_in_hg DECIMAL(5,2) = NULL,
    @qnh_mb INT = NULL,
    
    -- Output
    @flight_uid BIGINT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @now DATETIME2(0) = SYSUTCDATETIME();
    DECLARE @flight_key NVARCHAR(64);
    DECLARE @is_new BIT = 0;
    DECLARE @route_changed BIT = 0;
    DECLARE @old_route NVARCHAR(MAX);
    DECLARE @old_route_hash BINARY(32);
    DECLARE @new_route_hash BINARY(32);
    
    -- Build flight key: cid|callsign|dept|dest|deptime
    SET @flight_key = CAST(@cid AS NVARCHAR) + '|' + @callsign + '|' + 
                      ISNULL(@dept_icao, '') + '|' + ISNULL(@dest_icao, '') + '|' + 
                      ISNULL(@dep_time_z, '');
    
    -- ========================================================================
    -- 1. UPSERT adl_flight_core
    -- ========================================================================
    
    -- Try to find existing flight
    SELECT @flight_uid = flight_uid
    FROM dbo.adl_flight_core
    WHERE flight_key = @flight_key;
    
    IF @flight_uid IS NULL
    BEGIN
        -- New flight - INSERT
        INSERT INTO dbo.adl_flight_core (
            flight_key, cid, callsign, flight_id,
            phase, last_source, is_active,
            first_seen_utc, last_seen_utc, logon_time_utc,
            adl_date, adl_time, snapshot_utc
        )
        VALUES (
            @flight_key, @cid, @callsign, @flight_id,
            CASE 
                WHEN @lat IS NULL THEN 'prefile'
                WHEN @groundspeed_kts < 50 THEN 'taxiing'
                WHEN @altitude_ft < 10000 AND @vertical_rate_fpm > 500 THEN 'departed'
                WHEN @altitude_ft < 10000 AND @vertical_rate_fpm < -500 THEN 'descending'
                ELSE 'enroute'
            END,
            @source, 1,
            @now, @now, @logon_time,
            CAST(@now AS DATE), CAST(@now AS TIME), @now
        );
        
        SET @flight_uid = SCOPE_IDENTITY();
        SET @is_new = 1;
    END
    ELSE
    BEGIN
        -- Existing flight - UPDATE
        UPDATE dbo.adl_flight_core
        SET last_seen_utc = @now,
            last_source = @source,
            snapshot_utc = @now,
            phase = CASE 
                WHEN @lat IS NULL THEN phase  -- Keep existing if no position
                WHEN @groundspeed_kts < 50 THEN 'taxiing'
                WHEN @altitude_ft < 10000 AND @vertical_rate_fpm > 500 THEN 'departed'
                WHEN @altitude_ft < 10000 AND @vertical_rate_fpm < -500 THEN 'descending'
                ELSE 'enroute'
            END,
            flight_id = COALESCE(@flight_id, flight_id),
            logon_time_utc = COALESCE(@logon_time, logon_time_utc)
        WHERE flight_uid = @flight_uid;
    END
    
    -- ========================================================================
    -- 2. UPSERT adl_flight_position
    -- ========================================================================
    
    IF @lat IS NOT NULL AND @lon IS NOT NULL
    BEGIN
        MERGE dbo.adl_flight_position AS target
        USING (SELECT @flight_uid AS flight_uid) AS source
        ON target.flight_uid = source.flight_uid
        WHEN MATCHED THEN
            UPDATE SET
                lat = @lat,
                lon = @lon,
                position_geo = geography::Point(@lat, @lon, 4326),
                altitude_ft = @altitude_ft,
                groundspeed_kts = @groundspeed_kts,
                heading_deg = @heading_deg,
                vertical_rate_fpm = @vertical_rate_fpm,
                qnh_in_hg = @qnh_in_hg,
                qnh_mb = @qnh_mb,
                position_updated_utc = @now
        WHEN NOT MATCHED THEN
            INSERT (flight_uid, lat, lon, position_geo, altitude_ft, groundspeed_kts, 
                    heading_deg, vertical_rate_fpm, qnh_in_hg, qnh_mb, position_updated_utc)
            VALUES (@flight_uid, @lat, @lon, geography::Point(@lat, @lon, 4326), 
                    @altitude_ft, @groundspeed_kts, @heading_deg, @vertical_rate_fpm,
                    @qnh_in_hg, @qnh_mb, @now);
    END
    
    -- ========================================================================
    -- 3. UPSERT adl_flight_plan (check for route changes)
    -- ========================================================================
    
    -- Check if route changed
    IF @route IS NOT NULL
    BEGIN
        SET @new_route_hash = HASHBYTES('SHA2_256', @route + ISNULL(@remarks, ''));
        
        SELECT @old_route = fp_route, @old_route_hash = fp_hash
        FROM dbo.adl_flight_plan
        WHERE flight_uid = @flight_uid;
        
        IF @old_route_hash IS NULL OR @old_route_hash != @new_route_hash
            SET @route_changed = 1;
    END
    
    MERGE dbo.adl_flight_plan AS target
    USING (SELECT @flight_uid AS flight_uid) AS source
    ON target.flight_uid = source.flight_uid
    WHEN MATCHED THEN
        UPDATE SET
            fp_rule = COALESCE(@fp_rule, fp_rule),
            fp_dept_icao = COALESCE(@dept_icao, fp_dept_icao),
            fp_dest_icao = COALESCE(@dest_icao, fp_dest_icao),
            fp_alt_icao = COALESCE(@alt_icao, fp_alt_icao),
            fp_route = COALESCE(@route, fp_route),
            fp_remarks = COALESCE(@remarks, fp_remarks),
            fp_altitude_ft = COALESCE(@altitude_filed, fp_altitude_ft),
            fp_tas_kts = COALESCE(@tas_filed, fp_tas_kts),
            fp_dept_time_z = COALESCE(@dep_time_z, fp_dept_time_z),
            fp_enroute_minutes = COALESCE(@enroute_minutes, fp_enroute_minutes),
            fp_fuel_minutes = COALESCE(@fuel_minutes, fp_fuel_minutes),
            aircraft_type = COALESCE(@aircraft_type, aircraft_type),
            aircraft_equip = COALESCE(@aircraft_equip, aircraft_equip),
            fp_hash = CASE WHEN @route_changed = 1 THEN @new_route_hash ELSE fp_hash END,
            fp_updated_utc = CASE WHEN @route_changed = 1 THEN @now ELSE fp_updated_utc END,
            -- Reset parse status if route changed
            parse_status = CASE WHEN @route_changed = 1 THEN 'PENDING' ELSE parse_status END,
            route_geometry = CASE WHEN @route_changed = 1 THEN NULL ELSE route_geometry END
    WHEN NOT MATCHED THEN
        INSERT (flight_uid, fp_rule, fp_dept_icao, fp_dest_icao, fp_alt_icao,
                fp_route, fp_remarks, fp_altitude_ft, fp_tas_kts, fp_dept_time_z,
                fp_enroute_minutes, fp_fuel_minutes, aircraft_type, aircraft_equip,
                fp_hash, fp_updated_utc, parse_status)
        VALUES (@flight_uid, @fp_rule, @dept_icao, @dest_icao, @alt_icao,
                @route, @remarks, @altitude_filed, @tas_filed, @dep_time_z,
                @enroute_minutes, @fuel_minutes, @aircraft_type, @aircraft_equip,
                @new_route_hash, @now, 'PENDING');
    
    -- ========================================================================
    -- 4. Queue for parsing if new or route changed
    -- ========================================================================
    
    IF @is_new = 1 OR @route_changed = 1
    BEGIN
        IF @route IS NOT NULL AND LEN(LTRIM(RTRIM(@route))) > 0
        BEGIN
            DECLARE @tier TINYINT;
            SET @tier = dbo.fn_GetParseTier(@dept_icao, @dest_icao, @lat, @lon);
            
            -- Upsert parse queue
            MERGE dbo.adl_parse_queue AS target
            USING (SELECT @flight_uid AS flight_uid) AS source
            ON target.flight_uid = source.flight_uid
            WHEN MATCHED THEN
                UPDATE SET
                    parse_tier = @tier,
                    status = 'PENDING',
                    queued_utc = @now,
                    attempts = 0,
                    error_message = NULL
            WHEN NOT MATCHED THEN
                INSERT (flight_uid, parse_tier, status, queued_utc, next_eligible_utc)
                VALUES (@flight_uid, @tier, 'PENDING', @now, @now);
        END
    END
    
    -- ========================================================================
    -- 5. UPSERT adl_flight_aircraft (if we have aircraft info)
    -- ========================================================================
    
    IF @aircraft_type IS NOT NULL
    BEGIN
        DECLARE @weight_class NCHAR(1) = NULL;
        DECLARE @wake_category NVARCHAR(8) = NULL;
        
        -- Derive weight class from common types (simplified)
        SET @weight_class = CASE 
            WHEN @aircraft_type IN ('A388', 'A380', 'B748', 'B744', 'AN25') THEN 'J'  -- Super
            WHEN @aircraft_type IN ('B77W', 'B77L', 'B772', 'B773', 'A359', 'A35K', 'A346', 'A345', 'A343', 'A342', 'A333', 'A332', 'B788', 'B789', 'B78X', 'B764', 'B763', 'B762', 'MD11', 'DC10', 'IL96', 'B752', 'B753') THEN 'H'  -- Heavy
            WHEN @aircraft_type LIKE 'C1%' OR @aircraft_type LIKE 'C2%' OR @aircraft_type LIKE 'PA%' OR @aircraft_type LIKE 'BE%' OR @aircraft_type LIKE 'SR%' OR @aircraft_type LIKE 'DA%' THEN 'S'  -- Small
            ELSE 'L'  -- Large (default for jets)
        END;
        
        SET @wake_category = CASE @weight_class
            WHEN 'J' THEN 'SUPER'
            WHEN 'H' THEN 'HEAVY'
            WHEN 'S' THEN 'LIGHT'
            ELSE 'MEDIUM'
        END;
        
        MERGE dbo.adl_flight_aircraft AS target
        USING (SELECT @flight_uid AS flight_uid) AS source
        ON target.flight_uid = source.flight_uid
        WHEN MATCHED THEN
            UPDATE SET
                aircraft_icao = @aircraft_type,
                weight_class = @weight_class,
                wake_category = @wake_category,
                aircraft_updated_utc = @now
        WHEN NOT MATCHED THEN
            INSERT (flight_uid, aircraft_icao, weight_class, wake_category, aircraft_updated_utc)
            VALUES (@flight_uid, @aircraft_type, @weight_class, @wake_category, @now);
    END
END;
GO

-- ============================================================================
-- sp_MarkFlightInactive - Mark flights not seen recently as inactive
-- ============================================================================
IF OBJECT_ID('dbo.sp_MarkFlightInactive', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_MarkFlightInactive;
GO

CREATE PROCEDURE dbo.sp_MarkFlightInactive
    @stale_minutes INT = 5
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @cutoff DATETIME2(0) = DATEADD(MINUTE, -@stale_minutes, SYSUTCDATETIME());
    DECLARE @count INT;
    
    -- Mark stale flights as inactive
    UPDATE dbo.adl_flight_core
    SET is_active = 0,
        phase = 'arrived'
    WHERE is_active = 1 
      AND last_seen_utc < @cutoff;
    
    SET @count = @@ROWCOUNT;
    
    SELECT @count AS flights_marked_inactive;
END;
GO

-- ============================================================================
-- sp_ProcessParseQueue - Wrapper to process parse queue continuously
-- ============================================================================
IF OBJECT_ID('dbo.sp_ProcessParseQueue', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_ProcessParseQueue;
GO

CREATE PROCEDURE dbo.sp_ProcessParseQueue
    @max_iterations INT = 10,
    @batch_size INT = 50
AS
BEGIN
    SET NOCOUNT ON;
    
    DECLARE @iteration INT = 0;
    DECLARE @processed INT;
    DECLARE @errors INT;
    DECLARE @total_processed INT = 0;
    DECLARE @total_errors INT = 0;
    
    WHILE @iteration < @max_iterations
    BEGIN
        EXEC dbo.sp_ParseRouteBatch 
            @batch_size = @batch_size,
            @tier = NULL;
        
        -- Get results from last batch (simplified - actual would need OUTPUT params)
        SET @iteration = @iteration + 1;
        
        -- If queue is empty, stop early
        IF NOT EXISTS (
            SELECT 1 FROM dbo.adl_parse_queue 
            WHERE status = 'PENDING' 
              AND next_eligible_utc <= SYSUTCDATETIME()
        )
            BREAK;
    END
    
    SELECT @iteration AS iterations_run;
END;
GO

-- ============================================================================
-- Helper: sp_GetActiveFlightCount - Quick stats
-- ============================================================================
IF OBJECT_ID('dbo.sp_GetActiveFlightStats', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_GetActiveFlightStats;
GO

CREATE PROCEDURE dbo.sp_GetActiveFlightStats
AS
BEGIN
    SET NOCOUNT ON;
    
    SELECT 
        (SELECT COUNT(*) FROM dbo.adl_flight_core WHERE is_active = 1) AS active_flights,
        (SELECT COUNT(*) FROM dbo.adl_flight_core WHERE is_active = 1 AND last_source = 'vatsim') AS vatsim_flights,
        (SELECT COUNT(*) FROM dbo.adl_flight_core WHERE is_active = 1 AND last_source = 'simtraffic') AS simtraffic_flights,
        (SELECT COUNT(*) FROM dbo.adl_parse_queue WHERE status = 'PENDING') AS pending_parse,
        (SELECT COUNT(*) FROM dbo.adl_parse_queue WHERE status = 'PROCESSING') AS parsing,
        (SELECT COUNT(*) FROM dbo.adl_parse_queue WHERE status = 'FAILED') AS parse_failed,
        (SELECT COUNT(*) FROM dbo.adl_flight_plan WHERE parse_status = 'COMPLETE') AS routes_parsed;
END;
GO

PRINT 'Data ingestion procedures created successfully.';
PRINT '';
PRINT 'Usage:';
PRINT '  -- Upsert a single flight from VATSIM/SimTraffic:';
PRINT '  DECLARE @uid BIGINT;';
PRINT '  EXEC sp_UpsertFlight @cid=1234567, @callsign=''AAL123'', @source=''vatsim'',';
PRINT '       @lat=40.123, @lon=-73.456, @altitude_ft=35000, ...';
PRINT '       @flight_uid=@uid OUTPUT;';
PRINT '';
PRINT '  -- Mark stale flights as inactive:';
PRINT '  EXEC sp_MarkFlightInactive @stale_minutes=5;';
PRINT '';
PRINT '  -- Process parse queue:';
PRINT '  EXEC sp_ProcessParseQueue @max_iterations=10, @batch_size=50;';
PRINT '';
PRINT '  -- Get stats:';
PRINT '  EXEC sp_GetActiveFlightStats;';
GO
