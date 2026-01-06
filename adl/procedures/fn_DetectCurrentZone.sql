-- ============================================================================
-- fn_DetectCurrentZone.sql
-- Detects which airport zone an aircraft is currently in
-- 
-- Returns: zone_type (PARKING/APRON/TAXILANE/TAXIWAY/HOLD/RUNWAY/AIRBORNE/UNKNOWN)
-- ============================================================================

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

IF OBJECT_ID('dbo.fn_DetectCurrentZone', 'FN') IS NOT NULL
    DROP FUNCTION dbo.fn_DetectCurrentZone;
GO

CREATE FUNCTION dbo.fn_DetectCurrentZone(
    @airport_icao   NVARCHAR(4),
    @lat            DECIMAL(10,7),
    @lon            DECIMAL(11,7),
    @altitude_ft    INT,
    @groundspeed_kts INT
)
RETURNS NVARCHAR(16)
AS
BEGIN
    DECLARE @zone NVARCHAR(16) = 'UNKNOWN';
    DECLARE @airport_elev INT;
    DECLARE @agl INT;
    
    -- Null checks
    IF @lat IS NULL OR @lon IS NULL OR @airport_icao IS NULL
        RETURN 'UNKNOWN';
    
    -- Get airport elevation
    SELECT @airport_elev = ISNULL(CAST(ELEV AS INT), 0)
    FROM dbo.apts
    WHERE ICAO_ID = @airport_icao;
    
    SET @airport_elev = ISNULL(@airport_elev, 0);
    SET @agl = @altitude_ft - @airport_elev;
    
    -- ========================================================================
    -- Rule 1: If high AGL, definitely airborne
    -- ========================================================================
    IF @agl > 500
        RETURN 'AIRBORNE';
    
    -- ========================================================================
    -- Rule 2: Try OSM geometry match (prioritized by specificity)
    -- ========================================================================
    SELECT TOP 1 @zone = ag.zone_type
    FROM dbo.airport_geometry ag
    WHERE ag.airport_icao = @airport_icao
      AND ag.is_active = 1
      AND ag.geometry.STDistance(geography::Point(@lat, @lon, 4326)) < 100  -- Within 100m
    ORDER BY 
        -- Prioritize most specific zones
        CASE ag.zone_type
            WHEN 'PARKING' THEN 1
            WHEN 'GATE' THEN 2
            WHEN 'HOLD' THEN 3
            WHEN 'RUNWAY' THEN 4
            WHEN 'TAXILANE' THEN 5
            WHEN 'TAXIWAY' THEN 6
            WHEN 'APRON' THEN 7
            ELSE 99
        END,
        ag.geometry.STDistance(geography::Point(@lat, @lon, 4326));
    
    -- If OSM found a zone, return it
    IF @zone != 'UNKNOWN'
        RETURN @zone;
    
    -- ========================================================================
    -- Rule 3: Fallback - Speed-based detection
    -- ========================================================================
    
    -- Stationary or very slow = likely at parking
    IF @groundspeed_kts < 5
        RETURN 'PARKING';
    
    -- Taxi speed = taxiway or apron
    IF @groundspeed_kts BETWEEN 5 AND 35
        RETURN 'TAXIWAY';
    
    -- Higher speed on ground = likely runway
    IF @groundspeed_kts > 35 AND @agl < 100
        RETURN 'RUNWAY';
    
    -- Low altitude, higher speed = just departed or about to land
    IF @agl BETWEEN 100 AND 500
        RETURN 'AIRBORNE';
    
    RETURN 'UNKNOWN';
END
GO

PRINT 'Created function dbo.fn_DetectCurrentZone';
GO


-- ============================================================================
-- fn_DetectCurrentZoneWithDetails - Returns zone + name + distance
-- Table-valued function for more detailed zone detection
-- ============================================================================

IF OBJECT_ID('dbo.fn_DetectCurrentZoneWithDetails', 'IF') IS NOT NULL
    DROP FUNCTION dbo.fn_DetectCurrentZoneWithDetails;
GO

CREATE FUNCTION dbo.fn_DetectCurrentZoneWithDetails(
    @airport_icao   NVARCHAR(4),
    @lat            DECIMAL(10,7),
    @lon            DECIMAL(11,7),
    @altitude_ft    INT,
    @groundspeed_kts INT
)
RETURNS TABLE
AS
RETURN
(
    WITH zone_matches AS (
        SELECT 
            ag.zone_type,
            ag.zone_name,
            ag.geometry.STDistance(geography::Point(@lat, @lon, 4326)) AS distance_m,
            CASE ag.zone_type
                WHEN 'PARKING' THEN 1
                WHEN 'GATE' THEN 2
                WHEN 'HOLD' THEN 3
                WHEN 'RUNWAY' THEN 4
                WHEN 'TAXILANE' THEN 5
                WHEN 'TAXIWAY' THEN 6
                WHEN 'APRON' THEN 7
                ELSE 99
            END AS priority,
            'OSM_GEOMETRY' AS detection_method,
            -- Confidence based on distance
            CASE 
                WHEN ag.geometry.STDistance(geography::Point(@lat, @lon, 4326)) < 10 THEN 0.99
                WHEN ag.geometry.STDistance(geography::Point(@lat, @lon, 4326)) < 30 THEN 0.90
                WHEN ag.geometry.STDistance(geography::Point(@lat, @lon, 4326)) < 60 THEN 0.75
                ELSE 0.50
            END AS confidence
        FROM dbo.airport_geometry ag
        WHERE ag.airport_icao = @airport_icao
          AND ag.is_active = 1
          AND ag.geometry.STDistance(geography::Point(@lat, @lon, 4326)) < 100
    )
    SELECT TOP 1
        zone_type,
        zone_name,
        distance_m,
        detection_method,
        confidence
    FROM zone_matches
    ORDER BY priority, distance_m
);
GO

PRINT 'Created function dbo.fn_DetectCurrentZoneWithDetails';
GO
