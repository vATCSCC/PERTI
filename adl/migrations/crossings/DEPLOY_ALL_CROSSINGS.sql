-- ============================================================================
-- PLANNED CROSSINGS SYSTEM - COMPLETE DEPLOYMENT SCRIPT
-- ============================================================================
-- Run this script with an admin account that has DDL permissions
--
-- Usage with sqlcmd:
--   sqlcmd -S vatsim.database.windows.net -d VATSIM_ADL -U <admin_user> -P <password> -i DEPLOY_ALL_CROSSINGS.sql
--
-- Or paste into Azure Portal Query Editor / SSMS
-- ============================================================================

SET NOCOUNT ON;
PRINT '============================================================================';
PRINT 'Starting Planned Crossings System Deployment';
PRINT 'Time: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '============================================================================';
GO

-- ============================================================================
-- PART 1: CORE SCHEMA
-- ============================================================================
PRINT '';
PRINT '--- Part 1: Core Schema ---';
GO

-- 1.1 Custom Airspace Elements Table
IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'adl_airspace_element')
BEGIN
    CREATE TABLE dbo.adl_airspace_element (
        element_id          INT IDENTITY PRIMARY KEY,
        element_name        NVARCHAR(64) NOT NULL,
        element_type        VARCHAR(16) NOT NULL,
        element_subtype     VARCHAR(32),
        reference_boundary_id INT NULL,
        reference_fix_name    NVARCHAR(64) NULL,
        reference_airway      VARCHAR(8) NULL,
        geometry            GEOGRAPHY NULL,
        definition_json     NVARCHAR(MAX) NULL,
        radius_nm           DECIMAL(8,2) NULL,
        floor_fl            INT NULL,
        ceiling_fl          INT NULL,
        category            NVARCHAR(64),
        description         NVARCHAR(512),
        created_by          NVARCHAR(64),
        created_at          DATETIME2(0) DEFAULT GETUTCDATE(),
        updated_at          DATETIME2(0) DEFAULT GETUTCDATE(),
        is_active           BIT DEFAULT 1,
        CONSTRAINT UQ_element_name UNIQUE (element_name)
    );
    PRINT 'Created table: adl_airspace_element';
END
ELSE
    PRINT 'Table adl_airspace_element already exists';
GO

-- 1.2 Planned Crossings Table
IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'adl_flight_planned_crossings')
BEGIN
    CREATE TABLE dbo.adl_flight_planned_crossings (
        crossing_id         BIGINT IDENTITY PRIMARY KEY,
        flight_uid          BIGINT NOT NULL,
        crossing_source     VARCHAR(16) NOT NULL,
        boundary_id         INT NULL,
        element_id          INT NULL,
        boundary_code       VARCHAR(50),
        boundary_type       VARCHAR(20),
        crossing_type       VARCHAR(8) NOT NULL,
        crossing_order      SMALLINT NOT NULL,
        entry_waypoint_seq  INT,
        exit_waypoint_seq   INT,
        entry_fix_name      NVARCHAR(64),
        exit_fix_name       NVARCHAR(64),
        planned_entry_utc   DATETIME2(0),
        planned_exit_utc    DATETIME2(0),
        entry_lat           DECIMAL(10,7),
        entry_lon           DECIMAL(11,7),
        calculated_at       DATETIME2(0) DEFAULT GETUTCDATE(),
        calculation_tier    TINYINT
    );

    CREATE INDEX IX_crossing_flight ON dbo.adl_flight_planned_crossings(flight_uid);
    CREATE INDEX IX_crossing_boundary ON dbo.adl_flight_planned_crossings(boundary_id, planned_entry_utc);
    CREATE INDEX IX_crossing_element ON dbo.adl_flight_planned_crossings(element_id, planned_entry_utc);
    CREATE INDEX IX_crossing_time ON dbo.adl_flight_planned_crossings(planned_entry_utc) INCLUDE (boundary_code, boundary_type, flight_uid);
    CREATE INDEX IX_crossing_type_time ON dbo.adl_flight_planned_crossings(boundary_type, planned_entry_utc);

    PRINT 'Created table: adl_flight_planned_crossings with indexes';
END
ELSE
    PRINT 'Table adl_flight_planned_crossings already exists';
GO

-- 1.3 Region Group Tables
IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'adl_region_group')
BEGIN
    CREATE TABLE dbo.adl_region_group (
        region_id           INT IDENTITY PRIMARY KEY,
        region_code         VARCHAR(32) NOT NULL,
        region_name         NVARCHAR(128),
        mega_polygon        GEOGRAPHY NULL,
        artcc_codes         NVARCHAR(MAX),
        created_at          DATETIME2(0) DEFAULT GETUTCDATE(),
        CONSTRAINT UQ_region_code UNIQUE (region_code)
    );
    PRINT 'Created table: adl_region_group';
END
ELSE
    PRINT 'Table adl_region_group already exists';
GO

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'adl_region_group_members')
BEGIN
    CREATE TABLE dbo.adl_region_group_members (
        region_id           INT NOT NULL,
        boundary_id         INT NOT NULL,
        boundary_code       VARCHAR(50),
        PRIMARY KEY (region_id, boundary_id)
    );
    PRINT 'Created table: adl_region_group_members';
END
ELSE
    PRINT 'Table adl_region_group_members already exists';
GO

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'adl_region_airports')
BEGIN
    CREATE TABLE dbo.adl_region_airports (
        region_id           INT NOT NULL,
        icao_prefix         VARCHAR(4) NOT NULL,
        country_name        NVARCHAR(64),
        PRIMARY KEY (region_id, icao_prefix)
    );
    PRINT 'Created table: adl_region_airports';
END
ELSE
    PRINT 'Table adl_region_airports already exists';
GO

-- ============================================================================
-- PART 2: FLIGHT CORE CROSSING COLUMNS
-- ============================================================================
PRINT '';
PRINT '--- Part 2: Flight Core Crossing Columns ---';
GO

IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'adl_flight_core' AND COLUMN_NAME = 'crossing_tier')
BEGIN
    ALTER TABLE dbo.adl_flight_core ADD
        crossing_tier               TINYINT NULL,
        crossing_last_calc_utc      DATETIME2(0) NULL,
        crossing_needs_recalc       BIT DEFAULT 0,
        crossing_region_flags       TINYINT NULL,
        level_flight_samples        TINYINT DEFAULT 0,
        level_flight_confirmed      BIT DEFAULT 0,
        last_vertical_phase         CHAR(1) NULL;
    PRINT 'Added crossing columns to adl_flight_core';
END
ELSE
    PRINT 'Crossing columns already exist in adl_flight_core';
GO

-- Create filtered indexes for tiered batch selection
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_flight_crossing_tier1' AND object_id = OBJECT_ID('dbo.adl_flight_core'))
BEGIN
    CREATE INDEX IX_flight_crossing_tier1 ON dbo.adl_flight_core(crossing_last_calc_utc, crossing_needs_recalc)
        WHERE is_active = 1 AND (crossing_last_calc_utc IS NULL OR crossing_needs_recalc = 1);
    PRINT 'Created index: IX_flight_crossing_tier1';
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_flight_crossing_regional' AND object_id = OBJECT_ID('dbo.adl_flight_core'))
BEGIN
    CREATE INDEX IX_flight_crossing_regional ON dbo.adl_flight_core(crossing_region_flags, lifecycle_state)
        INCLUDE (current_artcc, current_tracon, level_flight_confirmed)
        WHERE is_active = 1 AND crossing_region_flags > 0;
    PRINT 'Created index: IX_flight_crossing_regional';
END
GO

-- ============================================================================
-- PART 3: POPULATE PRIORITY REGION
-- ============================================================================
PRINT '';
PRINT '--- Part 3: Populate Priority Region ---';
GO

IF NOT EXISTS (SELECT 1 FROM dbo.adl_region_group WHERE region_code = 'US_CA_MX_LATAM_CAR')
BEGIN
    INSERT INTO dbo.adl_region_group (region_code, region_name, artcc_codes)
    VALUES (
        'US_CA_MX_LATAM_CAR',
        'United States, Canada, Mexico, Latin America, Caribbean',
        '["ZAB","ZAU","ZBW","ZDC","ZDV","ZFW","ZHU","ZID","ZJX","ZKC","ZLA","ZLC","ZMA","ZME","ZMP","ZNY","ZOA","ZOB","ZSE","ZSU","ZTL","ZAN","ZHN","ZUA","KZWY","ZMO","KZAK","ZAP","ZHO","CZEG","CZUL","CZVR","CZWG","CZYZ","CZQM","CZQX","CZQO"]'
    );
    PRINT 'Created region: US_CA_MX_LATAM_CAR';
END
GO

-- Populate airport prefixes
DECLARE @region_id INT = (SELECT region_id FROM dbo.adl_region_group WHERE region_code = 'US_CA_MX_LATAM_CAR');

IF @region_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM dbo.adl_region_airports WHERE region_id = @region_id)
BEGIN
    INSERT INTO dbo.adl_region_airports (region_id, icao_prefix, country_name) VALUES
    (@region_id, 'K', 'United States (CONUS)'),
    (@region_id, 'PA', 'United States (Alaska)'),
    (@region_id, 'PH', 'United States (Hawaii)'),
    (@region_id, 'PG', 'United States (Guam)'),
    (@region_id, 'PW', 'United States (Wake Island)'),
    (@region_id, 'PM', 'United States (Midway)'),
    (@region_id, 'TJ', 'United States (Puerto Rico)'),
    (@region_id, 'TI', 'United States (US Virgin Islands)'),
    (@region_id, 'C', 'Canada'),
    (@region_id, 'MM', 'Mexico'),
    (@region_id, 'MG', 'Guatemala'),
    (@region_id, 'MH', 'Honduras'),
    (@region_id, 'MN', 'Nicaragua'),
    (@region_id, 'MR', 'Costa Rica'),
    (@region_id, 'MP', 'Panama'),
    (@region_id, 'MS', 'El Salvador'),
    (@region_id, 'MB', 'Turks & Caicos'),
    (@region_id, 'MY', 'Bahamas'),
    (@region_id, 'MU', 'Cuba'),
    (@region_id, 'MK', 'Jamaica'),
    (@region_id, 'MD', 'Dominican Republic'),
    (@region_id, 'MT', 'Haiti'),
    (@region_id, 'TN', 'Caribbean Netherlands'),
    (@region_id, 'TT', 'Trinidad and Tobago'),
    (@region_id, 'TF', 'French Antilles'),
    (@region_id, 'TB', 'Barbados'),
    (@region_id, 'TL', 'St Lucia'),
    (@region_id, 'TA', 'Antigua'),
    (@region_id, 'TK', 'St Kitts'),
    (@region_id, 'TU', 'British Virgin Islands'),
    (@region_id, 'TV', 'British Virgin Islands'),
    (@region_id, 'SK', 'Colombia'),
    (@region_id, 'SV', 'Venezuela');
    PRINT 'Inserted airport prefixes for region';
END
GO

-- Populate region members from boundaries
DECLARE @region_id2 INT = (SELECT region_id FROM dbo.adl_region_group WHERE region_code = 'US_CA_MX_LATAM_CAR');

IF @region_id2 IS NOT NULL
BEGIN
    DELETE FROM dbo.adl_region_group_members WHERE region_id = @region_id2;

    INSERT INTO dbo.adl_region_group_members (region_id, boundary_id, boundary_code)
    SELECT @region_id2, boundary_id, boundary_code
    FROM dbo.adl_boundary
    WHERE boundary_type = 'ARTCC'
      AND is_active = 1
      AND (
          boundary_code IN ('ZAB','ZAU','ZBW','ZDC','ZDV','ZFW','ZHU','ZID','ZJX','ZKC',
                            'ZLA','ZLC','ZMA','ZME','ZMP','ZNY','ZOA','ZOB','ZSE','ZSU','ZTL',
                            'ZAN','ZHN','ZUA','KZWY','ZMO','KZAK','ZAP','ZHO')
          OR boundary_code LIKE 'CZ%'
          OR boundary_code LIKE 'MM%'
      );

    PRINT 'Populated region members: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' boundaries';
END
GO

-- ============================================================================
-- PART 4: FORECAST VIEWS
-- ============================================================================
PRINT '';
PRINT '--- Part 4: Forecast Views ---';
GO

CREATE OR ALTER VIEW dbo.vw_boundary_workload_forecast
AS
SELECT
    c.boundary_code,
    c.boundary_type,
    DATEADD(MINUTE, (DATEDIFF(MINUTE, '2000-01-01', c.planned_entry_utc) / 15) * 15, '2000-01-01') AS time_bucket,
    COUNT(*) AS expected_entries,
    COUNT(DISTINCT c.flight_uid) AS unique_flights
FROM dbo.adl_flight_planned_crossings c
WHERE c.planned_entry_utc >= GETUTCDATE()
  AND c.planned_entry_utc < DATEADD(HOUR, 12, GETUTCDATE())
  AND c.boundary_code IS NOT NULL
GROUP BY c.boundary_code, c.boundary_type,
    DATEADD(MINUTE, (DATEDIFF(MINUTE, '2000-01-01', c.planned_entry_utc) / 15) * 15, '2000-01-01');
GO
PRINT 'Created view: vw_boundary_workload_forecast';
GO

CREATE OR ALTER VIEW dbo.vw_artcc_workload_summary
AS
SELECT
    c.boundary_code AS artcc,
    DATEADD(HOUR, DATEDIFF(HOUR, 0, c.planned_entry_utc), 0) AS hour_bucket,
    COUNT(*) AS expected_entries,
    COUNT(DISTINCT c.flight_uid) AS unique_flights,
    MIN(c.planned_entry_utc) AS first_entry,
    MAX(c.planned_entry_utc) AS last_entry
FROM dbo.adl_flight_planned_crossings c
WHERE c.boundary_type = 'ARTCC'
  AND c.planned_entry_utc >= GETUTCDATE()
  AND c.planned_entry_utc < DATEADD(HOUR, 6, GETUTCDATE())
GROUP BY c.boundary_code, DATEADD(HOUR, DATEDIFF(HOUR, 0, c.planned_entry_utc), 0);
GO
PRINT 'Created view: vw_artcc_workload_summary';
GO

CREATE OR ALTER VIEW dbo.vw_flights_crossing_boundary
AS
SELECT
    c.boundary_code,
    c.boundary_type,
    c.flight_uid,
    f.callsign,
    f.dept,
    f.dest,
    f.aircraft_type,
    c.crossing_order,
    c.entry_fix_name,
    c.exit_fix_name,
    c.planned_entry_utc,
    c.planned_exit_utc,
    DATEDIFF(MINUTE, c.planned_entry_utc, c.planned_exit_utc) AS transit_minutes,
    DATEDIFF(MINUTE, GETUTCDATE(), c.planned_entry_utc) AS minutes_until_entry,
    c.entry_lat,
    c.entry_lon
FROM dbo.adl_flight_planned_crossings c
JOIN dbo.adl_flight_core f ON f.flight_uid = c.flight_uid
WHERE c.planned_entry_utc >= GETUTCDATE()
  AND f.is_active = 1;
GO
PRINT 'Created view: vw_flights_crossing_boundary';
GO

CREATE OR ALTER VIEW dbo.vw_flights_crossing_element
AS
SELECT
    e.element_id,
    e.element_name,
    e.element_type,
    e.category,
    c.flight_uid,
    f.callsign,
    f.dept,
    f.dest,
    c.crossing_type,
    c.planned_entry_utc,
    c.planned_exit_utc,
    DATEDIFF(MINUTE, GETUTCDATE(), c.planned_entry_utc) AS minutes_until_entry
FROM dbo.adl_flight_planned_crossings c
JOIN dbo.adl_airspace_element e ON e.element_id = c.element_id
JOIN dbo.adl_flight_core f ON f.flight_uid = c.flight_uid
WHERE c.element_id IS NOT NULL
  AND c.planned_entry_utc >= GETUTCDATE()
  AND f.is_active = 1
  AND e.is_active = 1;
GO
PRINT 'Created view: vw_flights_crossing_element';
GO

CREATE OR ALTER VIEW dbo.vw_sector_demand_forecast
AS
SELECT
    c.boundary_code AS sector,
    c.boundary_type AS sector_type,
    DATEADD(MINUTE, (DATEDIFF(MINUTE, '2000-01-01', c.planned_entry_utc) / 30) * 30, '2000-01-01') AS time_bucket_30min,
    COUNT(*) AS expected_transits,
    COUNT(DISTINCT c.flight_uid) AS unique_flights
FROM dbo.adl_flight_planned_crossings c
WHERE c.boundary_type IN ('SECTOR_HIGH', 'SECTOR_LOW', 'SECTOR_SUPERHIGH')
  AND c.planned_entry_utc >= GETUTCDATE()
  AND c.planned_entry_utc < DATEADD(HOUR, 4, GETUTCDATE())
GROUP BY c.boundary_code, c.boundary_type,
    DATEADD(MINUTE, (DATEDIFF(MINUTE, '2000-01-01', c.planned_entry_utc) / 30) * 30, '2000-01-01');
GO
PRINT 'Created view: vw_sector_demand_forecast';
GO

CREATE OR ALTER VIEW dbo.vw_flight_route_crossings
AS
SELECT
    c.flight_uid,
    f.callsign,
    f.dept,
    f.dest,
    c.crossing_order,
    c.boundary_type,
    c.boundary_code,
    c.crossing_type,
    c.entry_fix_name,
    c.exit_fix_name,
    c.planned_entry_utc,
    c.planned_exit_utc,
    DATEDIFF(MINUTE, c.planned_entry_utc, c.planned_exit_utc) AS transit_minutes,
    c.entry_lat,
    c.entry_lon
FROM dbo.adl_flight_planned_crossings c
JOIN dbo.adl_flight_core f ON f.flight_uid = c.flight_uid
WHERE f.is_active = 1;
GO
PRINT 'Created view: vw_flight_route_crossings';
GO

CREATE OR ALTER VIEW dbo.vw_hot_boundaries
AS
SELECT TOP 50
    c.boundary_code,
    c.boundary_type,
    COUNT(*) AS expected_entries_1hr,
    COUNT(DISTINCT c.flight_uid) AS unique_flights_1hr,
    MIN(c.planned_entry_utc) AS next_entry,
    MAX(c.planned_entry_utc) AS last_entry_in_window
FROM dbo.adl_flight_planned_crossings c
WHERE c.planned_entry_utc >= GETUTCDATE()
  AND c.planned_entry_utc < DATEADD(HOUR, 1, GETUTCDATE())
  AND c.boundary_code IS NOT NULL
GROUP BY c.boundary_code, c.boundary_type
ORDER BY COUNT(*) DESC;
GO
PRINT 'Created view: vw_hot_boundaries';
GO

CREATE OR ALTER VIEW dbo.vw_crossing_statistics
AS
SELECT
    COUNT(DISTINCT flight_uid) AS flights_with_crossings,
    COUNT(*) AS total_crossings,
    SUM(CASE WHEN boundary_type = 'ARTCC' THEN 1 ELSE 0 END) AS artcc_crossings,
    SUM(CASE WHEN boundary_type = 'SECTOR_HIGH' THEN 1 ELSE 0 END) AS sector_high_crossings,
    SUM(CASE WHEN boundary_type = 'SECTOR_LOW' THEN 1 ELSE 0 END) AS sector_low_crossings,
    SUM(CASE WHEN boundary_type = 'TRACON' THEN 1 ELSE 0 END) AS tracon_crossings,
    SUM(CASE WHEN element_id IS NOT NULL THEN 1 ELSE 0 END) AS element_crossings,
    MIN(calculated_at) AS oldest_calculation,
    MAX(calculated_at) AS newest_calculation
FROM dbo.adl_flight_planned_crossings;
GO
PRINT 'Created view: vw_crossing_statistics';
GO

-- ============================================================================
-- PART 5: STORED PROCEDURES
-- ============================================================================
PRINT '';
PRINT '--- Part 5: Stored Procedures ---';
GO

-- 5.1 sp_DetectRegionalFlight
CREATE OR ALTER PROCEDURE dbo.sp_DetectRegionalFlight
    @flight_uid BIGINT = NULL,
    @batch_mode BIT = 0
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @region_id INT = (SELECT region_id FROM dbo.adl_region_group WHERE region_code = 'US_CA_MX_LATAM_CAR');
    IF @region_id IS NULL RETURN;

    IF @batch_mode = 1
    BEGIN
        UPDATE c
        SET c.crossing_region_flags =
            CASE WHEN EXISTS (SELECT 1 FROM dbo.adl_region_airports a WHERE a.region_id = @region_id
                              AND (c.dept LIKE a.icao_prefix + '%' OR (a.icao_prefix = 'K' AND c.dept LIKE 'K%' AND LEN(c.dept) = 4)))
                 THEN 1 ELSE 0 END
            |
            CASE WHEN EXISTS (SELECT 1 FROM dbo.adl_region_airports a WHERE a.region_id = @region_id
                              AND (c.dest LIKE a.icao_prefix + '%' OR (a.icao_prefix = 'K' AND c.dest LIKE 'K%' AND LEN(c.dest) = 4)))
                 THEN 2 ELSE 0 END
        FROM dbo.adl_flight_core c
        WHERE c.is_active = 1 AND c.crossing_region_flags IS NULL;
    END
    ELSE IF @flight_uid IS NOT NULL
    BEGIN
        UPDATE c
        SET c.crossing_region_flags =
            CASE WHEN EXISTS (SELECT 1 FROM dbo.adl_region_airports a WHERE a.region_id = @region_id
                              AND (c.dept LIKE a.icao_prefix + '%' OR (a.icao_prefix = 'K' AND c.dept LIKE 'K%' AND LEN(c.dept) = 4)))
                 THEN 1 ELSE 0 END
            |
            CASE WHEN EXISTS (SELECT 1 FROM dbo.adl_region_airports a WHERE a.region_id = @region_id
                              AND (c.dest LIKE a.icao_prefix + '%' OR (a.icao_prefix = 'K' AND c.dest LIKE 'K%' AND LEN(c.dest) = 4)))
                 THEN 2 ELSE 0 END
        FROM dbo.adl_flight_core c
        WHERE c.flight_uid = @flight_uid;
    END
END
GO
PRINT 'Created procedure: sp_DetectRegionalFlight';
GO

-- 5.2 sp_CalculatePlannedCrossings
CREATE OR ALTER PROCEDURE dbo.sp_CalculatePlannedCrossings
    @flight_uid BIGINT,
    @tier TINYINT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @now DATETIME2(0) = GETUTCDATE();
    DECLARE @grid_size FLOAT = 0.5;
    DECLARE @crossing_count INT = 0;

    CREATE TABLE #waypoints (
        seq INT PRIMARY KEY, fix_name NVARCHAR(64), lat DECIMAL(10,7), lon DECIMAL(11,7),
        eta_utc DATETIME2(0), grid_lat SMALLINT, grid_lon SMALLINT
    );

    INSERT INTO #waypoints (seq, fix_name, lat, lon, eta_utc, grid_lat, grid_lon)
    SELECT w.sequence_num, w.fix_name, w.lat, w.lon, w.eta_utc,
           CAST(FLOOR(w.lat / @grid_size) AS SMALLINT), CAST(FLOOR(w.lon / @grid_size) AS SMALLINT)
    FROM dbo.adl_flight_waypoints w
    WHERE w.flight_uid = @flight_uid AND w.lat IS NOT NULL AND w.lon IS NOT NULL
    ORDER BY w.sequence_num;

    IF @@ROWCOUNT = 0
    BEGIN
        UPDATE dbo.adl_flight_core SET crossing_last_calc_utc = @now, crossing_needs_recalc = 0, crossing_tier = @tier
        WHERE flight_uid = @flight_uid;
        DROP TABLE #waypoints;
        RETURN;
    END

    DELETE FROM dbo.adl_flight_planned_crossings WHERE flight_uid = @flight_uid;

    CREATE TABLE #waypoint_artcc (
        seq INT PRIMARY KEY, fix_name NVARCHAR(64), eta_utc DATETIME2(0),
        lat DECIMAL(10,7), lon DECIMAL(11,7), artcc_id INT, artcc_code VARCHAR(50)
    );

    INSERT INTO #waypoint_artcc (seq, fix_name, eta_utc, lat, lon, artcc_id, artcc_code)
    SELECT w.seq, w.fix_name, w.eta_utc, w.lat, w.lon, a.boundary_id, a.boundary_code
    FROM #waypoints w
    OUTER APPLY (
        SELECT TOP 1 g.boundary_id, g.boundary_code
        FROM dbo.adl_boundary_grid g
        JOIN dbo.adl_boundary b ON b.boundary_id = g.boundary_id
        WHERE g.boundary_type = 'ARTCC' AND g.grid_lat = w.grid_lat AND g.grid_lon = w.grid_lon
          AND b.boundary_geography.STContains(geography::Point(w.lat, w.lon, 4326)) = 1
        ORDER BY g.is_oceanic ASC, g.boundary_area ASC
    ) a;

    CREATE TABLE #crossings (
        crossing_order SMALLINT IDENTITY(1,1), boundary_type VARCHAR(20), boundary_id INT, boundary_code VARCHAR(50),
        crossing_type VARCHAR(8), entry_seq INT, exit_seq INT, entry_fix NVARCHAR(64), exit_fix NVARCHAR(64),
        entry_utc DATETIME2(0), exit_utc DATETIME2(0), entry_lat DECIMAL(10,7), entry_lon DECIMAL(11,7)
    );

    INSERT INTO #crossings (boundary_type, boundary_id, boundary_code, crossing_type, entry_seq, entry_fix, entry_utc, entry_lat, entry_lon)
    SELECT 'ARTCC', curr.artcc_id, curr.artcc_code, 'ENTRY', curr.seq, curr.fix_name, curr.eta_utc, curr.lat, curr.lon
    FROM #waypoint_artcc curr
    LEFT JOIN #waypoint_artcc prev ON prev.seq = curr.seq - 1
    WHERE curr.artcc_id IS NOT NULL AND (prev.artcc_id IS NULL OR prev.artcc_id != curr.artcc_id);

    UPDATE c SET c.exit_seq = exit_info.exit_seq, c.exit_fix = exit_info.exit_fix, c.exit_utc = exit_info.exit_utc
    FROM #crossings c
    CROSS APPLY (
        SELECT TOP 1 w.seq AS exit_seq, w.fix_name AS exit_fix, w.eta_utc AS exit_utc
        FROM #waypoint_artcc w WHERE w.seq > c.entry_seq AND (w.artcc_id IS NULL OR w.artcc_id != c.boundary_id)
        ORDER BY w.seq
    ) exit_info
    WHERE c.boundary_type = 'ARTCC';

    INSERT INTO dbo.adl_flight_planned_crossings (
        flight_uid, crossing_source, boundary_id, boundary_code, boundary_type, crossing_type, crossing_order,
        entry_waypoint_seq, exit_waypoint_seq, entry_fix_name, exit_fix_name, planned_entry_utc, planned_exit_utc,
        entry_lat, entry_lon, calculated_at, calculation_tier
    )
    SELECT @flight_uid, 'BOUNDARY', c.boundary_id, c.boundary_code, c.boundary_type, c.crossing_type, c.crossing_order,
           c.entry_seq, c.exit_seq, c.entry_fix, c.exit_fix, c.entry_utc, c.exit_utc, c.entry_lat, c.entry_lon, @now, @tier
    FROM #crossings c WHERE c.boundary_id IS NOT NULL ORDER BY c.crossing_order;

    SET @crossing_count = @@ROWCOUNT;

    UPDATE dbo.adl_flight_core SET crossing_last_calc_utc = @now, crossing_needs_recalc = 0, crossing_tier = @tier
    WHERE flight_uid = @flight_uid;

    DROP TABLE #waypoints;
    DROP TABLE #waypoint_artcc;
    DROP TABLE #crossings;

    SELECT @crossing_count AS crossings_calculated;
END
GO
PRINT 'Created procedure: sp_CalculatePlannedCrossings';
GO

-- 5.3 sp_CalculatePlannedCrossingsBatch
CREATE OR ALTER PROCEDURE dbo.sp_CalculatePlannedCrossingsBatch
    @max_flights_per_batch INT = 500,
    @debug BIT = 0
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @now DATETIME2(0) = GETUTCDATE();
    DECLARE @cycle INT = DATEPART(SECOND, @now) / 15;
    DECLARE @minute INT = DATEPART(MINUTE, @now);
    DECLARE @start_time DATETIME2(3) = SYSDATETIME();
    DECLARE @tier1_count INT = 0, @tier2_count INT = 0, @tier3_count INT = 0;
    DECLARE @tier4_count INT = 0, @tier5_count INT = 0, @tier6_count INT = 0, @tier7_count INT = 0;
    DECLARE @total_crossings INT = 0;

    CREATE TABLE #batch_flights (flight_uid BIGINT PRIMARY KEY, tier TINYINT NOT NULL, crossing_order INT IDENTITY(1,1));

    -- Tier 1: New + recalc
    INSERT INTO #batch_flights (flight_uid, tier)
    SELECT TOP (@max_flights_per_batch) c.flight_uid, 1
    FROM dbo.adl_flight_core c
    WHERE c.is_active = 1 AND (c.crossing_last_calc_utc IS NULL OR c.crossing_needs_recalc = 1)
      AND c.crossing_region_flags IS NOT NULL
    ORDER BY c.crossing_needs_recalc DESC, c.crossing_region_flags DESC, c.first_seen_utc DESC;
    SET @tier1_count = @@ROWCOUNT;

    -- Tier 2: TRACON (every 1 min)
    IF @cycle = 0
    BEGIN
        INSERT INTO #batch_flights (flight_uid, tier)
        SELECT TOP (@max_flights_per_batch - (SELECT COUNT(*) FROM #batch_flights)) c.flight_uid, 2
        FROM dbo.adl_flight_core c
        LEFT JOIN #batch_flights b ON b.flight_uid = c.flight_uid
        WHERE c.is_active = 1 AND b.flight_uid IS NULL AND c.crossing_region_flags > 0
          AND c.lifecycle_state IN ('departed', 'enroute', 'descending') AND c.current_tracon IS NOT NULL
          AND (c.crossing_last_calc_utc IS NULL OR DATEDIFF(SECOND, c.crossing_last_calc_utc, @now) >= 60);
        SET @tier2_count = @@ROWCOUNT;
    END

    -- Process batch
    DECLARE @batch_count INT = (SELECT COUNT(*) FROM #batch_flights);
    IF @batch_count > 0
    BEGIN
        DECLARE @flight_uid BIGINT, @tier TINYINT;
        DECLARE batch_cursor CURSOR LOCAL FAST_FORWARD FOR SELECT flight_uid, tier FROM #batch_flights ORDER BY crossing_order;
        OPEN batch_cursor;
        FETCH NEXT FROM batch_cursor INTO @flight_uid, @tier;
        WHILE @@FETCH_STATUS = 0
        BEGIN
            EXEC dbo.sp_CalculatePlannedCrossings @flight_uid = @flight_uid, @tier = @tier;
            FETCH NEXT FROM batch_cursor INTO @flight_uid, @tier;
        END
        CLOSE batch_cursor;
        DEALLOCATE batch_cursor;
        SELECT @total_crossings = COUNT(*) FROM dbo.adl_flight_planned_crossings WHERE calculated_at >= @start_time;
    END

    IF @debug = 1
    BEGIN
        DECLARE @elapsed_ms INT = DATEDIFF(MILLISECOND, @start_time, SYSDATETIME());
        SELECT @now AS processed_at, @cycle AS cycle, @minute AS minute, @tier1_count AS tier1_new_recalc,
               @tier2_count AS tier2_tracon, @batch_count AS total_flights, @total_crossings AS crossings_calculated, @elapsed_ms AS elapsed_ms;
    END

    DROP TABLE #batch_flights;
END
GO
PRINT 'Created procedure: sp_CalculatePlannedCrossingsBatch';
GO

-- 5.4 sp_TriggerCrossingRecalc
CREATE OR ALTER PROCEDURE dbo.sp_TriggerCrossingRecalc
    @flight_uid BIGINT,
    @trigger_source VARCHAR(32) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    UPDATE dbo.adl_flight_core SET crossing_needs_recalc = 1
    WHERE flight_uid = @flight_uid AND is_active = 1;
    SELECT @@ROWCOUNT AS flights_flagged, @trigger_source AS trigger_source;
END
GO
PRINT 'Created procedure: sp_TriggerCrossingRecalc';
GO

-- 5.5 sp_UpdateLevelFlightStatus
CREATE OR ALTER PROCEDURE dbo.sp_UpdateLevelFlightStatus
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @level_threshold INT = 200;
    DECLARE @required_samples INT = 3;

    ;WITH FlightPhases AS (
        SELECT c.flight_uid, c.level_flight_samples, c.level_flight_confirmed, c.last_vertical_phase,
            CASE WHEN p.vertical_rate_fpm > @level_threshold THEN 'C'
                 WHEN p.vertical_rate_fpm < -@level_threshold THEN 'D' ELSE 'L' END AS current_phase
        FROM dbo.adl_flight_core c
        JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
        WHERE c.is_active = 1 AND c.lifecycle_state IN ('departed', 'enroute', 'descending')
    )
    UPDATE c
    SET c.level_flight_samples = CASE WHEN fp.current_phase = 'L' THEN CASE WHEN c.level_flight_samples < 255 THEN c.level_flight_samples + 1 ELSE 255 END ELSE 0 END,
        c.last_vertical_phase = fp.current_phase,
        c.level_flight_confirmed = CASE WHEN fp.current_phase = 'L' AND c.level_flight_samples >= @required_samples - 1 AND c.level_flight_confirmed = 0 THEN 1
                                        WHEN fp.current_phase != 'L' THEN 0 ELSE c.level_flight_confirmed END,
        c.crossing_needs_recalc = CASE WHEN fp.current_phase = 'L' AND c.level_flight_samples >= @required_samples - 1
                                            AND c.level_flight_confirmed = 0 AND c.last_vertical_phase IN ('C', 'D') THEN 1
                                       ELSE c.crossing_needs_recalc END
    FROM dbo.adl_flight_core c
    JOIN FlightPhases fp ON fp.flight_uid = c.flight_uid;

    SELECT @@ROWCOUNT AS flights_updated;
END
GO
PRINT 'Created procedure: sp_UpdateLevelFlightStatus';
GO

-- ============================================================================
-- DEPLOYMENT COMPLETE
-- ============================================================================
PRINT '';
PRINT '============================================================================';
PRINT 'Planned Crossings System Deployment Complete';
PRINT 'Time: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
PRINT '============================================================================';
GO
