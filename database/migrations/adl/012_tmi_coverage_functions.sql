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

    -- Look up from FAA apts table (authoritative source)
    -- Try ICAO_ID first (includes K prefix), then ARPT_ID (FAA code without K)
    SELECT TOP 1 @artcc = RESP_ARTCC_ID
    FROM dbo.apts
    WHERE ICAO_ID = @airport_icao
       OR ARPT_ID = @airport_icao
       OR ARPT_ID = SUBSTRING(@airport_icao, 2, 3)  -- Strip K prefix
       OR ICAO_ID = 'K' + @airport_icao;            -- Add K prefix

    RETURN @artcc;
END
GO

PRINT 'Created function dbo.fn_GetAirportArtcc';
GO

PRINT '=== ADL Migration 012 Complete ===';
GO
