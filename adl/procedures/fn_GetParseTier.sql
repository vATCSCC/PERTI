-- ============================================================================
-- ADL Tier Assignment Function
-- 
-- Determines which parsing tier a flight should be assigned to based on:
-- - Origin/destination airports
-- - Current position
-- - Distance to CONUS
-- - Oceanic FIR location
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

IF EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.fn_GetParseTier') AND type = 'FN')
BEGIN
    DROP FUNCTION dbo.fn_GetParseTier;
    PRINT 'Dropped existing function dbo.fn_GetParseTier';
END
GO

CREATE FUNCTION dbo.fn_GetParseTier(
    @dept_icao      CHAR(4),
    @dest_icao      CHAR(4),
    @current_lat    DECIMAL(10,7),
    @current_lon    DECIMAL(11,7)
)
RETURNS TINYINT
AS
BEGIN
    DECLARE @tier TINYINT = 4;  -- Default: lowest priority (Asia/Oceania)
    DECLARE @is_us_origin BIT = 0;
    DECLARE @is_us_dest BIT = 0;
    DECLARE @is_both_us BIT = 0;
    DECLARE @dist_to_conus_nm DECIMAL(10,2) = NULL;
    DECLARE @in_zak BIT = 0;
    DECLARE @in_us_ca_latam_oceanic BIT = 0;
    DECLARE @is_alaska_hawaii_pacific BIT = 0;
    
    -- ═══════════════════════════════════════════════════════════════════════
    -- HELPER: Check if airport is US/CA/LatAm/Caribbean
    -- ═══════════════════════════════════════════════════════════════════════
    
    -- Check origin
    SET @is_us_origin = CASE WHEN (
        @dept_icao LIKE 'K%' OR @dept_icao LIKE 'P%' OR  -- US CONUS + Pacific
        @dept_icao LIKE 'C%' OR                          -- Canada
        @dept_icao LIKE 'T%' OR                          -- Caribbean
        @dept_icao LIKE 'MM%' OR                         -- Mexico
        @dept_icao LIKE 'M[GHNRPSB]%' OR                 -- Central America
        @dept_icao LIKE 'S[KVEP]%'                       -- Northern South America
    ) THEN 1 ELSE 0 END;
    
    -- Check destination
    SET @is_us_dest = CASE WHEN (
        @dest_icao LIKE 'K%' OR @dest_icao LIKE 'P%' OR
        @dest_icao LIKE 'C%' OR
        @dest_icao LIKE 'T%' OR
        @dest_icao LIKE 'MM%' OR
        @dest_icao LIKE 'M[GHNRPSB]%' OR
        @dest_icao LIKE 'S[KVEP]%'
    ) THEN 1 ELSE 0 END;
    
    -- Both endpoints in region = always Tier 0
    SET @is_both_us = CASE WHEN @is_us_origin = 1 AND @is_us_dest = 1 THEN 1 ELSE 0 END;
    
    -- ═══════════════════════════════════════════════════════════════════════
    -- HELPER: Check if destination/origin is Alaska, Hawaii, or US Pacific
    -- ═══════════════════════════════════════════════════════════════════════
    
    SET @is_alaska_hawaii_pacific = CASE WHEN (
        -- Alaska (PA prefix)
        @dept_icao LIKE 'PA%' OR @dest_icao LIKE 'PA%' OR
        -- Hawaii (PH prefix)
        @dept_icao LIKE 'PH%' OR @dest_icao LIKE 'PH%' OR
        -- Guam (PG prefix)
        @dept_icao LIKE 'PG%' OR @dest_icao LIKE 'PG%' OR
        -- Other US Pacific (Midway, Wake, etc.)
        @dept_icao LIKE 'PM%' OR @dest_icao LIKE 'PM%' OR
        @dept_icao LIKE 'PW%' OR @dest_icao LIKE 'PW%' OR
        @dept_icao IN ('NSTU', 'NSFA') OR @dest_icao IN ('NSTU', 'NSFA')
    ) THEN 1 ELSE 0 END;
    
    -- ═══════════════════════════════════════════════════════════════════════
    -- HELPER: Calculate distance to CONUS
    -- ═══════════════════════════════════════════════════════════════════════
    
    IF @current_lat IS NOT NULL AND @current_lon IS NOT NULL
    BEGIN
        SET @dist_to_conus_nm = dbo.fn_DistanceToConusNm(@current_lat, @current_lon);
    END;
    
    -- ═══════════════════════════════════════════════════════════════════════
    -- HELPER: Check if in ZAK (Oakland Oceanic)
    -- ═══════════════════════════════════════════════════════════════════════
    
    IF @current_lat IS NOT NULL AND @current_lon IS NOT NULL
    BEGIN
        SET @in_zak = CASE WHEN (
            @current_lat BETWEEN 10 AND 55 AND
            (
                @current_lon BETWEEN -180 AND -125 OR
                @current_lon BETWEEN 140 AND 180
            )
        ) THEN 1 ELSE 0 END;
    END;
    
    -- ═══════════════════════════════════════════════════════════════════════
    -- HELPER: Check if in US/CA/LatAm Oceanic FIRs
    -- ═══════════════════════════════════════════════════════════════════════
    
    IF @current_lat IS NOT NULL AND @current_lon IS NOT NULL
    BEGIN
        SET @in_us_ca_latam_oceanic = CASE WHEN (
            -- New York Oceanic
            (@current_lat BETWEEN 30 AND 45 AND @current_lon BETWEEN -75 AND -40) OR
            -- Miami Oceanic
            (@current_lat BETWEEN 18 AND 30 AND @current_lon BETWEEN -85 AND -55) OR
            -- Houston Oceanic
            (@current_lat BETWEEN 18 AND 30 AND @current_lon BETWEEN -98 AND -85) OR
            -- Gander Oceanic
            (@current_lat BETWEEN 40 AND 65 AND @current_lon BETWEEN -60 AND -30) OR
            -- Moncton FIR
            (@current_lat BETWEEN 42 AND 52 AND @current_lon BETWEEN -70 AND -55) OR
            -- Vancouver Oceanic
            (@current_lat BETWEEN 45 AND 60 AND @current_lon BETWEEN -140 AND -125) OR
            -- Anchorage Oceanic
            (@current_lat BETWEEN 50 AND 75 AND @current_lon BETWEEN -180 AND -130) OR
            -- Caribbean FIRs
            (@current_lat BETWEEN 10 AND 22 AND @current_lon BETWEEN -72 AND -60) OR
            -- Central America
            (@current_lat BETWEEN 5 AND 18 AND @current_lon BETWEEN -92 AND -77) OR
            -- SA Atlantic approaches
            (@current_lat BETWEEN -5 AND 15 AND @current_lon BETWEEN -60 AND -35)
        ) THEN 1 ELSE 0 END;
    END;
    
    -- ═══════════════════════════════════════════════════════════════════════
    -- TIER 0: Both endpoints in US/CA/LatAm/Caribbean - always real-time
    -- ═══════════════════════════════════════════════════════════════════════
    
    IF @is_both_us = 1
    BEGIN
        RETURN 0;
    END;
    
    -- ═══════════════════════════════════════════════════════════════════════
    -- TIER 0 WITH DISTANCE DEMOTION RULES
    -- ═══════════════════════════════════════════════════════════════════════
    
    IF @is_us_origin = 1 OR @is_us_dest = 1
    BEGIN
        -- Default: Tier 0
        SET @tier = 0;
        
        -- RULE 1: If >500nm from CONUS → Demote to Tier 1
        IF @dist_to_conus_nm IS NOT NULL AND @dist_to_conus_nm > 500
        BEGIN
            SET @tier = 1;
            
            -- RULE 2: If in ZAK AND NOT Alaska/Hawaii/Pacific → Tier 4
            IF @in_zak = 1 AND @is_alaska_hawaii_pacific = 0
            BEGIN
                SET @tier = 4;
            END
            -- RULE 3: If NOT in any US/CA/LatAm oceanic FIR → Tier 4
            ELSE IF @in_us_ca_latam_oceanic = 0 AND @in_zak = 0
            BEGIN
                SET @tier = 4;
            END;
        END;
        
        RETURN @tier;
    END;
    
    -- ═══════════════════════════════════════════════════════════════════════
    -- NON-US FLIGHTS: Standard tier assignment by region
    -- ═══════════════════════════════════════════════════════════════════════
    
    -- TIER 1: North America non-US/CA
    IF (
        (@current_lat BETWEEN 15 AND 72 AND @current_lon BETWEEN -170 AND -50)
        OR
        (@dept_icao LIKE 'M%' AND @dest_icao LIKE 'M%')
    )
    BEGIN
        RETURN 1;
    END;
    
    -- TIER 2: Europe, South America
    IF (
        @dept_icao LIKE 'E%' OR @dept_icao LIKE 'L%' OR 
        @dept_icao LIKE 'B%' OR @dept_icao LIKE 'U%' OR
        @dest_icao LIKE 'E%' OR @dest_icao LIKE 'L%' OR 
        @dest_icao LIKE 'B%' OR @dest_icao LIKE 'U%' OR
        @dept_icao LIKE 'S%' OR @dest_icao LIKE 'S%'
    )
    BEGIN
        RETURN 2;
    END;
    
    -- TIER 3: Middle East, Africa
    IF (
        @dept_icao LIKE 'O%' OR @dest_icao LIKE 'O%' OR
        @dept_icao LIKE 'D%' OR @dest_icao LIKE 'D%' OR
        @dept_icao LIKE 'F%' OR @dest_icao LIKE 'F%' OR
        @dept_icao LIKE 'G%' OR @dest_icao LIKE 'G%' OR
        @dept_icao LIKE 'H%' OR @dest_icao LIKE 'H%'
    )
    BEGIN
        RETURN 3;
    END;
    
    -- TIER 4: Default (Asia, Oceania)
    RETURN 4;
END
GO

PRINT 'Created function dbo.fn_GetParseTier';
GO

-- ============================================================================
-- Test the function
-- ============================================================================

PRINT '';
PRINT 'Testing fn_GetParseTier:';
PRINT '  KJFK→KORD (domestic): Tier ' + CAST(dbo.fn_GetParseTier('KJFK', 'KORD', 41.0, -85.0) AS VARCHAR);
PRINT '  KJFK→EGLL (over NYC): Tier ' + CAST(dbo.fn_GetParseTier('KJFK', 'EGLL', 40.7, -74.0) AS VARCHAR);
PRINT '  KJFK→EGLL (mid-Atlantic): Tier ' + CAST(dbo.fn_GetParseTier('KJFK', 'EGLL', 50.0, -40.0) AS VARCHAR);
PRINT '  EGLL→KJFK (over Ireland): Tier ' + CAST(dbo.fn_GetParseTier('EGLL', 'KJFK', 52.0, -8.0) AS VARCHAR);
PRINT '  RJTT→KLAX (over Pacific): Tier ' + CAST(dbo.fn_GetParseTier('RJTT', 'KLAX', 40.0, -170.0) AS VARCHAR);
PRINT '  RJTT→PHNL (to Hawaii): Tier ' + CAST(dbo.fn_GetParseTier('RJTT', 'PHNL', 30.0, -160.0) AS VARCHAR);
PRINT '  EGLL→LFPG (Europe domestic): Tier ' + CAST(dbo.fn_GetParseTier('EGLL', 'LFPG', 49.0, 2.0) AS VARCHAR);
GO
