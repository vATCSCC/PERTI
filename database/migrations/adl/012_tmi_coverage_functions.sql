-- ============================================================================
-- ADL Migration 012: TMI Coverage Area Functions
--
-- Purpose: Functions to determine if a flight is within TMI coverage area
-- Coverage: US, Canada, Mexico, Latin America, Caribbean (incl. oceanic)
--
-- Target Database: VATSIM_ADL
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== ADL Migration 012: TMI Coverage Functions ===';
GO

-- ============================================================================
-- 1. Coverage Area Check (Bounding Box Approximation)
-- ============================================================================

CREATE OR ALTER FUNCTION dbo.fn_IsInTmiCoverage(
    @lat DECIMAL(10,7),
    @lon DECIMAL(11,7)
)
RETURNS BIT
AS
BEGIN
    -- Coverage area: US/CA/MX/LATAM/CAR including oceanic approaches
    -- Approximate bounding box (generous to include oceanic)
    -- North: 72°N (Arctic Canada)
    -- South: -60°S (South America tip)
    -- West: -180° (includes Pacific oceanic)
    -- East: -25° (includes Atlantic oceanic approaches)

    DECLARE @inCoverage BIT = 0;

    IF @lat BETWEEN -60.0 AND 72.0
       AND @lon BETWEEN -180.0 AND -25.0
    BEGIN
        SET @inCoverage = 1;
    END

    RETURN @inCoverage;
END
GO

PRINT 'Created function dbo.fn_IsInTmiCoverage';
GO

-- ============================================================================
-- 2. Airport to Parent ARTCC Lookup
-- ============================================================================

CREATE OR ALTER FUNCTION dbo.fn_GetAirportArtcc(
    @airport_icao CHAR(4)
)
RETURNS CHAR(3)
AS
BEGIN
    DECLARE @artcc CHAR(3);

    -- Look up from reference data
    SELECT @artcc = artcc_code
    FROM dbo.ref_airports
    WHERE icao_code = @airport_icao;

    -- Fallback: derive from common patterns
    IF @artcc IS NULL
    BEGIN
        SET @artcc = CASE
            -- Major hubs with known ARTCCs
            WHEN @airport_icao IN ('KJFK', 'KLGA', 'KEWR', 'KTEB') THEN 'ZNY'
            WHEN @airport_icao IN ('KBOS', 'KPVD', 'KBDL') THEN 'ZBW'
            WHEN @airport_icao IN ('KDCA', 'KIAD', 'KBWI', 'KPHL') THEN 'ZDC'
            WHEN @airport_icao IN ('KATL', 'KCLT', 'KBNA') THEN 'ZTL'
            WHEN @airport_icao IN ('KMIA', 'KFLL', 'KPBI', 'KMCO') THEN 'ZMA'
            WHEN @airport_icao IN ('KJAX', 'KTPA', 'KRSW') THEN 'ZJX'
            WHEN @airport_icao IN ('KORD', 'KMDW', 'KMKE') THEN 'ZAU'
            WHEN @airport_icao IN ('KDTW', 'KCLE', 'KPIT', 'KCMH') THEN 'ZOB'
            WHEN @airport_icao IN ('KIND', 'KCVG', 'KSDF') THEN 'ZID'
            WHEN @airport_icao IN ('KMEM', 'KSTL', 'KLIT') THEN 'ZME'
            WHEN @airport_icao IN ('KMSP', 'KFAR') THEN 'ZMP'
            WHEN @airport_icao IN ('KMCI', 'KOMA', 'KDSM') THEN 'ZKC'
            WHEN @airport_icao IN ('KDFW', 'KDAL', 'KAUS', 'KSAT', 'KHOU') THEN 'ZFW'
            WHEN @airport_icao IN ('KIAH', 'KMSY', 'KBTR') THEN 'ZHU'
            WHEN @airport_icao IN ('KDEN', 'KCOS', 'KABQ') THEN 'ZDV'
            WHEN @airport_icao IN ('KPHX', 'KTUS', 'KELP') THEN 'ZAB'
            WHEN @airport_icao IN ('KSLC', 'KBOI') THEN 'ZLC'
            WHEN @airport_icao IN ('KLAX', 'KSAN', 'KLAS', 'KONT', 'KBURBANK') THEN 'ZLA'
            WHEN @airport_icao IN ('KSFO', 'KOAK', 'KSJC', 'KSMF') THEN 'ZOA'
            WHEN @airport_icao IN ('KSEA', 'KPDX', 'KGEG') THEN 'ZSE'
            ELSE NULL
        END;
    END

    RETURN @artcc;
END
GO

PRINT 'Created function dbo.fn_GetAirportArtcc';
GO

PRINT '=== ADL Migration 012 Complete ===';
GO
