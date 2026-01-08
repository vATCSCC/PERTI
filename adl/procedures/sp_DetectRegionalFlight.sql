-- ============================================================================
-- sp_DetectRegionalFlight
-- Version: 1.0
-- Date: 2026-01-07
-- Description: Determines if a flight is to/from/thru the priority region
--              Sets crossing_region_flags bitmask:
--                Bit 1 (value 1): Departs from region
--                Bit 2 (value 2): Arrives in region
--                Bit 3 (value 4): Transits through region
-- Performance: <1ms per flight, set-based for batch processing
-- ============================================================================

CREATE OR ALTER PROCEDURE dbo.sp_DetectRegionalFlight
    @flight_uid BIGINT = NULL,          -- Single flight mode
    @batch_mode BIT = 0,                 -- Process all flights needing detection
    @region_id INT = 1                   -- Default to primary region
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @now DATETIME2(0) = GETUTCDATE();
    DECLARE @processed INT = 0;

    -- ========================================================================
    -- BATCH MODE: Process all flights with NULL crossing_region_flags
    -- ========================================================================
    IF @batch_mode = 1
    BEGIN
        -- Create temp table for batch processing
        CREATE TABLE #flights_to_detect (
            flight_uid      BIGINT PRIMARY KEY,
            dept            VARCHAR(4),
            dest            VARCHAR(4),
            has_route_geo   BIT
        );

        -- Get flights needing region detection
        INSERT INTO #flights_to_detect (flight_uid, dept, dest, has_route_geo)
        SELECT
            c.flight_uid,
            c.dept,
            c.dest,
            CASE WHEN p.route_geometry IS NOT NULL THEN 1 ELSE 0 END
        FROM dbo.adl_flight_core c
        LEFT JOIN dbo.adl_flight_plan p ON p.flight_uid = c.flight_uid
        WHERE c.is_active = 1
          AND c.crossing_region_flags IS NULL;

        SET @processed = @@ROWCOUNT;

        IF @processed = 0
        BEGIN
            DROP TABLE #flights_to_detect;
            RETURN;
        END

        -- Calculate region flags using set-based approach
        ;WITH RegionFlags AS (
            SELECT
                f.flight_uid,
                -- Bit 1: Departs from region (check dept ICAO prefix)
                CASE WHEN EXISTS (
                    SELECT 1 FROM dbo.adl_region_airports ra
                    WHERE ra.region_id = @region_id
                      AND (
                          (ra.match_type = 'PREFIX' AND f.dept LIKE ra.icao_prefix + '%')
                          OR (ra.match_type = 'EXACT' AND f.dept = ra.icao_prefix)
                      )
                ) THEN 1 ELSE 0 END AS departs_region,

                -- Bit 2: Arrives in region (check dest ICAO prefix)
                CASE WHEN EXISTS (
                    SELECT 1 FROM dbo.adl_region_airports ra
                    WHERE ra.region_id = @region_id
                      AND (
                          (ra.match_type = 'PREFIX' AND f.dest LIKE ra.icao_prefix + '%')
                          OR (ra.match_type = 'EXACT' AND f.dest = ra.icao_prefix)
                      )
                ) THEN 2 ELSE 0 END AS arrives_region,

                f.has_route_geo
            FROM #flights_to_detect f
        )
        UPDATE c
        SET c.crossing_region_flags = rf.departs_region | rf.arrives_region,
            c.crossing_needs_recalc = 1
        FROM dbo.adl_flight_core c
        JOIN RegionFlags rf ON rf.flight_uid = c.flight_uid;

        -- For flights with route geometry that don't depart/arrive in region,
        -- check if they transit through (expensive, do last)
        -- Only check flights with region_flags = 0 and has_route_geo = 1
        UPDATE c
        SET c.crossing_region_flags = c.crossing_region_flags | 4  -- Add transit bit
        FROM dbo.adl_flight_core c
        JOIN #flights_to_detect f ON f.flight_uid = c.flight_uid
        JOIN dbo.adl_flight_plan p ON p.flight_uid = c.flight_uid
        JOIN dbo.adl_region_group rg ON rg.region_id = @region_id
        WHERE c.crossing_region_flags = 0  -- Doesn't depart or arrive in region
          AND f.has_route_geo = 1
          AND rg.mega_polygon IS NOT NULL
          AND p.route_geometry.STIntersects(rg.mega_polygon) = 1;

        DROP TABLE #flights_to_detect;
    END
    -- ========================================================================
    -- SINGLE FLIGHT MODE
    -- ========================================================================
    ELSE IF @flight_uid IS NOT NULL
    BEGIN
        DECLARE @dept VARCHAR(4), @dest VARCHAR(4);
        DECLARE @region_flags TINYINT = 0;

        -- Get flight details
        SELECT @dept = dept, @dest = dest
        FROM dbo.adl_flight_core
        WHERE flight_uid = @flight_uid;

        IF @dept IS NULL
            RETURN;

        -- Check departure
        IF EXISTS (
            SELECT 1 FROM dbo.adl_region_airports ra
            WHERE ra.region_id = @region_id
              AND (
                  (ra.match_type = 'PREFIX' AND @dept LIKE ra.icao_prefix + '%')
                  OR (ra.match_type = 'EXACT' AND @dept = ra.icao_prefix)
              )
        )
            SET @region_flags = @region_flags | 1;

        -- Check arrival
        IF EXISTS (
            SELECT 1 FROM dbo.adl_region_airports ra
            WHERE ra.region_id = @region_id
              AND (
                  (ra.match_type = 'PREFIX' AND @dest LIKE ra.icao_prefix + '%')
                  OR (ra.match_type = 'EXACT' AND @dest = ra.icao_prefix)
              )
        )
            SET @region_flags = @region_flags | 2;

        -- Check transit only if not departing/arriving in region
        IF @region_flags = 0
        BEGIN
            IF EXISTS (
                SELECT 1
                FROM dbo.adl_flight_plan p
                JOIN dbo.adl_region_group rg ON rg.region_id = @region_id
                WHERE p.flight_uid = @flight_uid
                  AND rg.mega_polygon IS NOT NULL
                  AND p.route_geometry IS NOT NULL
                  AND p.route_geometry.STIntersects(rg.mega_polygon) = 1
            )
                SET @region_flags = @region_flags | 4;
        END

        -- Update flight
        UPDATE dbo.adl_flight_core
        SET crossing_region_flags = @region_flags,
            crossing_needs_recalc = 1
        WHERE flight_uid = @flight_uid;

        SET @processed = 1;
    END

    -- Return count for logging
    SELECT @processed AS flights_processed;
END
GO

-- ============================================================================
-- sp_DetectRegionalFlightBatch
-- Lightweight wrapper for batch detection during refresh cycle
-- ============================================================================
CREATE OR ALTER PROCEDURE dbo.sp_DetectRegionalFlightBatch
AS
BEGIN
    SET NOCOUNT ON;
    EXEC dbo.sp_DetectRegionalFlight @batch_mode = 1;
END
GO

PRINT 'Created procedure: sp_DetectRegionalFlight';
PRINT 'Created procedure: sp_DetectRegionalFlightBatch';
GO
