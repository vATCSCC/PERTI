-- ============================================================================
-- sp_CalculatePlannedCrossings
-- Version: 1.0
-- Date: 2026-01-07
-- Description: Calculate planned boundary crossings for a single flight
--              Uses waypoint ETAs to predict when boundaries will be crossed
-- Performance Target: <30ms per flight using set-based operations
-- ============================================================================

CREATE OR ALTER PROCEDURE dbo.sp_CalculatePlannedCrossings
    @flight_uid BIGINT,
    @tier TINYINT = NULL
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @now DATETIME2(0) = GETUTCDATE();
    DECLARE @grid_size FLOAT = 0.5;  -- Match boundary grid size
    DECLARE @crossing_count INT = 0;

    -- ========================================================================
    -- 1. Get waypoints with ETAs for this flight
    -- ========================================================================
    CREATE TABLE #waypoints (
        seq             INT PRIMARY KEY,
        fix_name        NVARCHAR(64),
        lat             DECIMAL(10,7),
        lon             DECIMAL(11,7),
        eta_utc         DATETIME2(0),
        grid_lat        SMALLINT,
        grid_lon        SMALLINT
    );

    INSERT INTO #waypoints (seq, fix_name, lat, lon, eta_utc, grid_lat, grid_lon)
    SELECT
        w.sequence_num,
        w.fix_name,
        w.lat,
        w.lon,
        w.eta_utc,
        CAST(FLOOR(w.lat / @grid_size) AS SMALLINT),
        CAST(FLOOR(w.lon / @grid_size) AS SMALLINT)
    FROM dbo.adl_flight_waypoints w
    WHERE w.flight_uid = @flight_uid
      AND w.lat IS NOT NULL
      AND w.lon IS NOT NULL
    ORDER BY w.sequence_num;

    IF @@ROWCOUNT = 0
    BEGIN
        -- No waypoints, just mark as calculated
        UPDATE dbo.adl_flight_core
        SET crossing_last_calc_utc = @now,
            crossing_needs_recalc = 0,
            crossing_tier = @tier
        WHERE flight_uid = @flight_uid;

        DROP TABLE #waypoints;
        RETURN;
    END

    -- ========================================================================
    -- 2. Delete existing crossings for this flight
    -- ========================================================================
    DELETE FROM dbo.adl_flight_planned_crossings
    WHERE flight_uid = @flight_uid;

    -- ========================================================================
    -- 3. Find containing ARTCC for each waypoint (using grid pre-filter)
    -- ========================================================================
    CREATE TABLE #waypoint_artcc (
        seq             INT PRIMARY KEY,
        fix_name        NVARCHAR(64),
        eta_utc         DATETIME2(0),
        lat             DECIMAL(10,7),
        lon             DECIMAL(11,7),
        artcc_id        INT,
        artcc_code      VARCHAR(50)
    );

    INSERT INTO #waypoint_artcc (seq, fix_name, eta_utc, lat, lon, artcc_id, artcc_code)
    SELECT
        w.seq,
        w.fix_name,
        w.eta_utc,
        w.lat,
        w.lon,
        a.boundary_id,
        a.boundary_code
    FROM #waypoints w
    OUTER APPLY (
        SELECT TOP 1 g.boundary_id, g.boundary_code
        FROM dbo.adl_boundary_grid g
        JOIN dbo.adl_boundary b ON b.boundary_id = g.boundary_id
        WHERE g.boundary_type = 'ARTCC'
          AND g.grid_lat = w.grid_lat
          AND g.grid_lon = w.grid_lon
          AND b.boundary_geography.STContains(geography::Point(w.lat, w.lon, 4326)) = 1
        ORDER BY g.is_oceanic ASC, g.boundary_area ASC
    ) a;

    -- ========================================================================
    -- 4. Find containing sectors (HIGH, LOW, SUPERHIGH) for each waypoint
    -- We'll aggregate multiple overlapping sectors
    -- ========================================================================
    CREATE TABLE #waypoint_sectors (
        seq             INT,
        sector_type     VARCHAR(20),
        boundary_id     INT,
        boundary_code   VARCHAR(50),
        PRIMARY KEY (seq, sector_type, boundary_id)
    );

    -- High sectors
    INSERT INTO #waypoint_sectors (seq, sector_type, boundary_id, boundary_code)
    SELECT DISTINCT
        w.seq,
        'SECTOR_HIGH',
        g.boundary_id,
        g.boundary_code
    FROM #waypoints w
    JOIN dbo.adl_boundary_grid g ON g.grid_lat = w.grid_lat AND g.grid_lon = w.grid_lon
    JOIN dbo.adl_boundary b ON b.boundary_id = g.boundary_id
    WHERE g.boundary_type = 'SECTOR_HIGH'
      AND b.boundary_geography.STContains(geography::Point(w.lat, w.lon, 4326)) = 1;

    -- Low sectors
    INSERT INTO #waypoint_sectors (seq, sector_type, boundary_id, boundary_code)
    SELECT DISTINCT
        w.seq,
        'SECTOR_LOW',
        g.boundary_id,
        g.boundary_code
    FROM #waypoints w
    JOIN dbo.adl_boundary_grid g ON g.grid_lat = w.grid_lat AND g.grid_lon = w.grid_lon
    JOIN dbo.adl_boundary b ON b.boundary_id = g.boundary_id
    WHERE g.boundary_type = 'SECTOR_LOW'
      AND b.boundary_geography.STContains(geography::Point(w.lat, w.lon, 4326)) = 1;

    -- Superhigh sectors
    INSERT INTO #waypoint_sectors (seq, sector_type, boundary_id, boundary_code)
    SELECT DISTINCT
        w.seq,
        'SECTOR_SUPERHIGH',
        g.boundary_id,
        g.boundary_code
    FROM #waypoints w
    JOIN dbo.adl_boundary_grid g ON g.grid_lat = w.grid_lat AND g.grid_lon = w.grid_lon
    JOIN dbo.adl_boundary b ON b.boundary_id = g.boundary_id
    WHERE g.boundary_type = 'SECTOR_SUPERHIGH'
      AND b.boundary_geography.STContains(geography::Point(w.lat, w.lon, 4326)) = 1;

    -- ========================================================================
    -- 5. Detect ARTCC crossings (boundary changes between waypoints)
    -- ========================================================================
    CREATE TABLE #crossings (
        crossing_order      SMALLINT IDENTITY(1,1),
        boundary_type       VARCHAR(20),
        boundary_id         INT,
        boundary_code       VARCHAR(50),
        crossing_type       VARCHAR(8),
        entry_seq           INT,
        exit_seq            INT,
        entry_fix           NVARCHAR(64),
        exit_fix            NVARCHAR(64),
        entry_utc           DATETIME2(0),
        exit_utc            DATETIME2(0),
        entry_lat           DECIMAL(10,7),
        entry_lon           DECIMAL(11,7)
    );

    -- ARTCC entries (where ARTCC changes from previous waypoint)
    INSERT INTO #crossings (boundary_type, boundary_id, boundary_code, crossing_type,
                            entry_seq, entry_fix, entry_utc, entry_lat, entry_lon)
    SELECT
        'ARTCC',
        curr.artcc_id,
        curr.artcc_code,
        'ENTRY',
        curr.seq,
        curr.fix_name,
        curr.eta_utc,
        curr.lat,
        curr.lon
    FROM #waypoint_artcc curr
    LEFT JOIN #waypoint_artcc prev ON prev.seq = curr.seq - 1
    WHERE curr.artcc_id IS NOT NULL
      AND (prev.artcc_id IS NULL OR prev.artcc_id != curr.artcc_id);

    -- Update with exit information
    UPDATE c
    SET c.exit_seq = exit_info.exit_seq,
        c.exit_fix = exit_info.exit_fix,
        c.exit_utc = exit_info.exit_utc
    FROM #crossings c
    CROSS APPLY (
        SELECT TOP 1 w.seq AS exit_seq, w.fix_name AS exit_fix, w.eta_utc AS exit_utc
        FROM #waypoint_artcc w
        WHERE w.seq > c.entry_seq
          AND (w.artcc_id IS NULL OR w.artcc_id != c.boundary_id)
        ORDER BY w.seq
    ) exit_info
    WHERE c.boundary_type = 'ARTCC';

    -- ========================================================================
    -- 6. Detect Sector crossings (simplified - first entry per sector)
    -- ========================================================================
    -- Get first occurrence of each sector along route
    INSERT INTO #crossings (boundary_type, boundary_id, boundary_code, crossing_type,
                            entry_seq, entry_fix, entry_utc, entry_lat, entry_lon)
    SELECT
        s.sector_type,
        s.boundary_id,
        s.boundary_code,
        'ENTRY',
        MIN(s.seq),
        (SELECT TOP 1 w.fix_name FROM #waypoints w WHERE w.seq = MIN(s.seq)),
        (SELECT TOP 1 w.eta_utc FROM #waypoints w WHERE w.seq = MIN(s.seq)),
        (SELECT TOP 1 w.lat FROM #waypoints w WHERE w.seq = MIN(s.seq)),
        (SELECT TOP 1 w.lon FROM #waypoints w WHERE w.seq = MIN(s.seq))
    FROM #waypoint_sectors s
    GROUP BY s.sector_type, s.boundary_id, s.boundary_code;

    -- ========================================================================
    -- 7. Insert crossings into permanent table
    -- ========================================================================
    INSERT INTO dbo.adl_flight_planned_crossings (
        flight_uid, crossing_source, boundary_id, boundary_code, boundary_type,
        crossing_type, crossing_order, entry_waypoint_seq, exit_waypoint_seq,
        entry_fix_name, exit_fix_name, planned_entry_utc, planned_exit_utc,
        entry_lat, entry_lon, calculated_at, calculation_tier
    )
    SELECT
        @flight_uid,
        'BOUNDARY',
        c.boundary_id,
        c.boundary_code,
        c.boundary_type,
        c.crossing_type,
        c.crossing_order,
        c.entry_seq,
        c.exit_seq,
        c.entry_fix,
        c.exit_fix,
        c.entry_utc,
        c.exit_utc,
        c.entry_lat,
        c.entry_lon,
        @now,
        @tier
    FROM #crossings c
    WHERE c.boundary_id IS NOT NULL
    ORDER BY c.crossing_order;

    SET @crossing_count = @@ROWCOUNT;

    -- ========================================================================
    -- 8. Update flight core with calculation metadata
    -- ========================================================================
    UPDATE dbo.adl_flight_core
    SET crossing_last_calc_utc = @now,
        crossing_needs_recalc = 0,
        crossing_tier = @tier
    WHERE flight_uid = @flight_uid;

    -- Cleanup
    DROP TABLE #waypoints;
    DROP TABLE #waypoint_artcc;
    DROP TABLE #waypoint_sectors;
    DROP TABLE #crossings;

    -- Return count for logging
    SELECT @crossing_count AS crossings_calculated;
END
GO

PRINT 'Created procedure: sp_CalculatePlannedCrossings';
GO
