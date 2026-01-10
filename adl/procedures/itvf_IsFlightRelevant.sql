-- ============================================================================
-- itvf_IsFlightRelevant.sql
-- INLINE TABLE-VALUED FUNCTION (Performance Optimized)
--
-- Replaces scalar fn_IsFlightRelevant for better parallelism and set-based
-- execution. SQL Server inlines this into the query plan.
--
-- Determines if a flight is relevant for trajectory logging (Tier 0-6)
-- Returns 0 for Tier 7 (no logging)
--
-- Usage:
--   CROSS APPLY dbo.itvf_IsFlightRelevant(dept, dest, lat, lon) r
--   WHERE r.is_relevant = 1
--
-- Part of the ETA & Trajectory Calculation System
-- ============================================================================

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

IF OBJECT_ID('dbo.itvf_IsFlightRelevant', 'IF') IS NOT NULL
    DROP FUNCTION dbo.itvf_IsFlightRelevant;
GO

CREATE FUNCTION dbo.itvf_IsFlightRelevant(
    @dept_icao      CHAR(4),
    @dest_icao      CHAR(4),
    @current_lat    DECIMAL(10,7),
    @current_lon    DECIMAL(11,7)
)
RETURNS TABLE
AS
RETURN (
    SELECT CAST(
        CASE
            -- ================================================================
            -- CHECK 1: Origin in covered region
            -- ================================================================
            -- US: K*, P* (CONUS, Pacific territories)
            WHEN @dept_icao LIKE 'K%' OR @dept_icao LIKE 'P%' THEN 1
            -- Canada: C*
            WHEN @dept_icao LIKE 'C%' THEN 1
            -- Mexico: MM*
            WHEN @dept_icao LIKE 'MM%' THEN 1
            -- Central America: MG, MH, MN, MR, MP, MS, MB
            WHEN @dept_icao LIKE 'M[GHNRPSB]%' THEN 1
            -- Caribbean: T* (all Caribbean islands)
            WHEN @dept_icao LIKE 'T%' THEN 1
            -- South America: S*
            WHEN @dept_icao LIKE 'S%' THEN 1

            -- ================================================================
            -- CHECK 2: Destination in covered region
            -- ================================================================
            WHEN @dest_icao LIKE 'K%' OR @dest_icao LIKE 'P%' THEN 1
            WHEN @dest_icao LIKE 'C%' THEN 1
            WHEN @dest_icao LIKE 'MM%' THEN 1
            WHEN @dest_icao LIKE 'M[GHNRPSB]%' THEN 1
            WHEN @dest_icao LIKE 'T%' THEN 1
            WHEN @dest_icao LIKE 'S%' THEN 1

            -- ================================================================
            -- CHECK 3: Position null check
            -- ================================================================
            WHEN @current_lat IS NULL OR @current_lon IS NULL THEN 0

            -- ================================================================
            -- CHECK 4: Quick reject if completely outside covered region
            -- ================================================================
            WHEN @current_lat < -60 OR @current_lat > 75 THEN 0
            WHEN @current_lon < -180 OR @current_lon > -10 THEN 0

            -- ================================================================
            -- CHECK 5: Position in covered airspace
            -- ================================================================
            -- North America (including oceanic approaches)
            WHEN @current_lat BETWEEN 15 AND 72 AND @current_lon BETWEEN -180 AND -50 THEN 1
            -- Central America / Caribbean
            WHEN @current_lat BETWEEN 5 AND 35 AND @current_lon BETWEEN -100 AND -55 THEN 1
            -- South America
            WHEN @current_lat BETWEEN -56 AND 15 AND @current_lon BETWEEN -85 AND -30 THEN 1
            -- US Atlantic oceanic (ZNY/ZMA oceanic)
            WHEN @current_lat BETWEEN 20 AND 45 AND @current_lon BETWEEN -80 AND -40 THEN 1
            -- US Pacific oceanic (ZOA/ZAK)
            WHEN @current_lat BETWEEN 15 AND 60 AND @current_lon BETWEEN -180 AND -130 THEN 1
            -- Canadian oceanic (Gander/Moncton)
            WHEN @current_lat BETWEEN 40 AND 60 AND @current_lon BETWEEN -60 AND -30 THEN 1
            -- Hawaiian region
            WHEN @current_lat BETWEEN 15 AND 30 AND @current_lon BETWEEN -180 AND -150 THEN 1

            -- If none of the above, not relevant
            ELSE 0
        END AS BIT
    ) AS is_relevant
);
GO

PRINT 'Created inline table-valued function dbo.itvf_IsFlightRelevant';
GO
