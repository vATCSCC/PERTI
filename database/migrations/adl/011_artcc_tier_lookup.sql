-- ============================================================================
-- ADL Migration 011: ARTCC Adjacency Lookup for TMI Tier Calculation
--
-- Purpose: Cache ARTCC neighbor relationships for fast tier determination
-- Source: Derived from PostGIS artcc_boundaries spatial relationships
--
-- Target Database: VATSIM_ADL
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== ADL Migration 011: ARTCC Tier Lookup ===';
GO

-- ============================================================================
-- 1. ARTCC Adjacency Table
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.ref_artcc_adjacency') AND type = 'U')
BEGIN
    CREATE TABLE dbo.ref_artcc_adjacency (
        artcc_code          CHAR(3) NOT NULL,
        neighbor_code       CHAR(3) NOT NULL,
        hop_distance        TINYINT NOT NULL,  -- 1 = adjacent, 2 = 2 hops

        CONSTRAINT PK_artcc_adjacency PRIMARY KEY (artcc_code, neighbor_code),
        CONSTRAINT CK_hop_distance CHECK (hop_distance IN (1, 2))
    );

    PRINT 'Created table dbo.ref_artcc_adjacency';
END
GO

-- ============================================================================
-- 2. Seed ARTCC Adjacency Data (CONUS + adjacent)
-- ============================================================================

-- Clear and reseed
TRUNCATE TABLE dbo.ref_artcc_adjacency;

-- Tier 1 neighbors (directly adjacent ARTCCs)
-- This data derived from PostGIS ST_Touches analysis
INSERT INTO dbo.ref_artcc_adjacency (artcc_code, neighbor_code, hop_distance) VALUES
-- ZNY (New York) neighbors
('ZNY', 'ZBW', 1), ('ZNY', 'ZOB', 1), ('ZNY', 'ZDC', 1),
-- ZDC (Washington) neighbors
('ZDC', 'ZNY', 1), ('ZDC', 'ZOB', 1), ('ZDC', 'ZID', 1), ('ZDC', 'ZTL', 1), ('ZDC', 'ZJX', 1),
-- ZTL (Atlanta) neighbors
('ZTL', 'ZDC', 1), ('ZTL', 'ZJX', 1), ('ZTL', 'ZME', 1), ('ZTL', 'ZID', 1), ('ZTL', 'ZHU', 1),
-- ZJX (Jacksonville) neighbors
('ZJX', 'ZDC', 1), ('ZJX', 'ZTL', 1), ('ZJX', 'ZMA', 1), ('ZJX', 'ZHU', 1),
-- ZMA (Miami) neighbors
('ZMA', 'ZJX', 1), ('ZMA', 'ZHU', 1),
-- ZHU (Houston) neighbors
('ZHU', 'ZTL', 1), ('ZHU', 'ZJX', 1), ('ZHU', 'ZMA', 1), ('ZHU', 'ZME', 1), ('ZHU', 'ZFW', 1), ('ZHU', 'ZAB', 1),
-- ZME (Memphis) neighbors
('ZME', 'ZTL', 1), ('ZME', 'ZID', 1), ('ZME', 'ZKC', 1), ('ZME', 'ZFW', 1), ('ZME', 'ZHU', 1),
-- ZID (Indianapolis) neighbors
('ZID', 'ZOB', 1), ('ZID', 'ZDC', 1), ('ZID', 'ZTL', 1), ('ZID', 'ZME', 1), ('ZID', 'ZKC', 1), ('ZID', 'ZAU', 1),
-- ZOB (Cleveland) neighbors
('ZOB', 'ZNY', 1), ('ZOB', 'ZDC', 1), ('ZOB', 'ZID', 1), ('ZOB', 'ZAU', 1), ('ZOB', 'ZBW', 1),
-- ZBW (Boston) neighbors
('ZBW', 'ZNY', 1), ('ZBW', 'ZOB', 1),
-- ZAU (Chicago) neighbors
('ZAU', 'ZOB', 1), ('ZAU', 'ZID', 1), ('ZAU', 'ZKC', 1), ('ZAU', 'ZMP', 1),
-- ZKC (Kansas City) neighbors
('ZKC', 'ZAU', 1), ('ZKC', 'ZID', 1), ('ZKC', 'ZME', 1), ('ZKC', 'ZFW', 1), ('ZKC', 'ZAB', 1), ('ZKC', 'ZDV', 1), ('ZKC', 'ZMP', 1),
-- ZFW (Fort Worth) neighbors
('ZFW', 'ZKC', 1), ('ZFW', 'ZME', 1), ('ZFW', 'ZHU', 1), ('ZFW', 'ZAB', 1),
-- ZAB (Albuquerque) neighbors
('ZAB', 'ZKC', 1), ('ZAB', 'ZFW', 1), ('ZAB', 'ZHU', 1), ('ZAB', 'ZDV', 1), ('ZAB', 'ZLA', 1),
-- ZDV (Denver) neighbors
('ZDV', 'ZKC', 1), ('ZDV', 'ZAB', 1), ('ZDV', 'ZLA', 1), ('ZDV', 'ZLC', 1), ('ZDV', 'ZMP', 1),
-- ZMP (Minneapolis) neighbors
('ZMP', 'ZAU', 1), ('ZMP', 'ZKC', 1), ('ZMP', 'ZDV', 1), ('ZMP', 'ZLC', 1), ('ZMP', 'ZSE', 1),
-- ZLC (Salt Lake) neighbors
('ZLC', 'ZDV', 1), ('ZLC', 'ZMP', 1), ('ZLC', 'ZLA', 1), ('ZLC', 'ZOA', 1), ('ZLC', 'ZSE', 1),
-- ZLA (Los Angeles) neighbors
('ZLA', 'ZAB', 1), ('ZLA', 'ZDV', 1), ('ZLA', 'ZLC', 1), ('ZLA', 'ZOA', 1),
-- ZOA (Oakland) neighbors
('ZOA', 'ZLA', 1), ('ZOA', 'ZLC', 1), ('ZOA', 'ZSE', 1),
-- ZSE (Seattle) neighbors
('ZSE', 'ZOA', 1), ('ZSE', 'ZLC', 1), ('ZSE', 'ZMP', 1);

PRINT 'Inserted Tier 1 (adjacent) ARTCC relationships';

-- Tier 2 neighbors (2 hops away) - computed from Tier 1
INSERT INTO dbo.ref_artcc_adjacency (artcc_code, neighbor_code, hop_distance)
SELECT DISTINCT a1.artcc_code, a2.neighbor_code, 2
FROM dbo.ref_artcc_adjacency a1
JOIN dbo.ref_artcc_adjacency a2 ON a1.neighbor_code = a2.artcc_code
WHERE a1.hop_distance = 1
  AND a2.hop_distance = 1
  AND a1.artcc_code <> a2.neighbor_code
  AND NOT EXISTS (
      SELECT 1 FROM dbo.ref_artcc_adjacency x
      WHERE x.artcc_code = a1.artcc_code
        AND x.neighbor_code = a2.neighbor_code
  );

PRINT 'Computed Tier 2 (2-hop) ARTCC relationships';

-- Report counts
SELECT hop_distance, COUNT(*) AS relationship_count
FROM dbo.ref_artcc_adjacency
GROUP BY hop_distance;

GO

PRINT '=== ADL Migration 011 Complete ===';
GO
