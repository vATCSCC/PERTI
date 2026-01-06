-- ============================================================================
-- fn_IsFlightRelevant.sql
-- Determines if a flight is relevant for trajectory logging (Tier 0-6)
-- Returns 0 for Tier 7 (no logging)
-- 
-- Relevance Criteria:
-- 1. Origin in covered region (US/CA/LatAm/Caribbean)
-- 2. Destination in covered region
-- 3. Current position in covered airspace
-- 
-- Part of the ETA & Trajectory Calculation System
-- ============================================================================

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

IF OBJECT_ID('dbo.fn_IsFlightRelevant', 'FN') IS NOT NULL
    DROP FUNCTION dbo.fn_IsFlightRelevant;
GO

CREATE FUNCTION dbo.fn_IsFlightRelevant(
    @dept_icao      CHAR(4),
    @dest_icao      CHAR(4),
    @current_lat    DECIMAL(10,7),
    @current_lon    DECIMAL(11,7)
)
RETURNS BIT
AS
BEGIN
    -- ========================================================================
    -- CHECK 1: Origin in covered region
    -- ========================================================================
    -- US: K*, P* (CONUS, Pacific territories)
    -- Canada: C*
    -- Mexico: MM*
    -- Central America: MG* (Guatemala), MH* (Honduras), MN* (Nicaragua), 
    --                  MR* (Costa Rica), MP* (Panama), MS* (El Salvador), MB* (Belize)
    -- Caribbean: T* (includes all Caribbean islands)
    -- South America: S* (all of South America)
    
    IF @dept_icao LIKE 'K%' OR @dept_icao LIKE 'P%'
        RETURN 1;
    IF @dept_icao LIKE 'C%'
        RETURN 1;
    IF @dept_icao LIKE 'MM%'
        RETURN 1;
    IF @dept_icao LIKE 'M[GHNRPSB]%'
        RETURN 1;
    IF @dept_icao LIKE 'T%'
        RETURN 1;
    IF @dept_icao LIKE 'S%'
        RETURN 1;
    
    -- ========================================================================
    -- CHECK 2: Destination in covered region
    -- ========================================================================
    
    IF @dest_icao LIKE 'K%' OR @dest_icao LIKE 'P%'
        RETURN 1;
    IF @dest_icao LIKE 'C%'
        RETURN 1;
    IF @dest_icao LIKE 'MM%'
        RETURN 1;
    IF @dest_icao LIKE 'M[GHNRPSB]%'
        RETURN 1;
    IF @dest_icao LIKE 'T%'
        RETURN 1;
    IF @dest_icao LIKE 'S%'
        RETURN 1;
    
    -- ========================================================================
    -- CHECK 3: Bounding box pre-filter for transit
    -- ========================================================================
    -- Covered region approximate bounds:
    -- Lat: -56 (southern Chile/Argentina) to +72 (northern Canada/Alaska)
    -- Lon: -180 to -20 (plus Brazil Atlantic -30 to -20)
    
    IF @current_lat IS NULL OR @current_lon IS NULL
        RETURN 0;
    
    -- Quick reject if completely outside covered region
    IF @current_lat < -60 OR @current_lat > 75
        RETURN 0;
    
    IF @current_lon < -180 OR @current_lon > -10
        RETURN 0;
    
    -- ========================================================================
    -- CHECK 4: Position in covered airspace (simplified check)
    -- ========================================================================
    -- For transit flights, check if currently over Americas
    
    -- North America (including oceanic approaches)
    IF @current_lat BETWEEN 15 AND 72 AND @current_lon BETWEEN -180 AND -50
        RETURN 1;
    
    -- Central America / Caribbean
    IF @current_lat BETWEEN 5 AND 35 AND @current_lon BETWEEN -100 AND -55
        RETURN 1;
    
    -- South America
    IF @current_lat BETWEEN -56 AND 15 AND @current_lon BETWEEN -85 AND -30
        RETURN 1;
    
    -- US Atlantic oceanic (ZNY/ZMA oceanic)
    IF @current_lat BETWEEN 20 AND 45 AND @current_lon BETWEEN -80 AND -40
        RETURN 1;
    
    -- US Pacific oceanic (ZOA/ZAK)
    IF @current_lat BETWEEN 15 AND 60 AND @current_lon BETWEEN -180 AND -130
        RETURN 1;
    
    -- Canadian oceanic (Gander/Moncton)
    IF @current_lat BETWEEN 40 AND 60 AND @current_lon BETWEEN -60 AND -30
        RETURN 1;
    
    -- Hawaiian region
    IF @current_lat BETWEEN 15 AND 30 AND @current_lon BETWEEN -180 AND -150
        RETURN 1;
    
    -- If none of the above, not relevant
    RETURN 0;
END
GO

PRINT 'Created function dbo.fn_IsFlightRelevant';
GO
