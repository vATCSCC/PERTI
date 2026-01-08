-- ============================================================================
-- Populate Priority Region (US/CA/MX/LATAM/CAR)
-- Version: 1.0
-- Date: 2026-01-07
-- Description: Populates region group with all ARTCCs/FIRs for priority region
-- ============================================================================

-- ============================================================================
-- 1. Create the priority region
-- ============================================================================
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

-- ============================================================================
-- 2. Populate region members from existing boundaries
-- ============================================================================
DECLARE @region_id INT = (SELECT region_id FROM dbo.adl_region_group WHERE region_code = 'US_CA_MX_LATAM_CAR');

-- Clear existing members
DELETE FROM dbo.adl_region_group_members WHERE region_id = @region_id;

-- Insert US ARTCCs (CONUS + Territories)
INSERT INTO dbo.adl_region_group_members (region_id, boundary_id, boundary_code)
SELECT @region_id, boundary_id, boundary_code
FROM dbo.adl_boundary
WHERE boundary_type = 'ARTCC'
  AND is_active = 1
  AND (
      -- US CONUS ARTCCs
      boundary_code IN ('ZAB','ZAU','ZBW','ZDC','ZDV','ZFW','ZHU','ZID','ZJX','ZKC',
                        'ZLA','ZLC','ZMA','ZME','ZMP','ZNY','ZOA','ZOB','ZSE','ZSU','ZTL')
      -- US Territories
      OR boundary_code IN ('ZAN','ZHN','ZUA')
      -- US Oceanic
      OR boundary_code IN ('KZWY','ZMO','KZAK','ZAP','ZHO')
      -- Canada
      OR boundary_code LIKE 'CZ%'
      -- Mexico
      OR boundary_code LIKE 'MM%'
  );

PRINT 'Inserted US/CA/MX ARTCCs into region';

-- Insert Central America FIRs
INSERT INTO dbo.adl_region_group_members (region_id, boundary_id, boundary_code)
SELECT @region_id, boundary_id, boundary_code
FROM dbo.adl_boundary
WHERE boundary_type IN ('ARTCC', 'FIR')
  AND is_active = 1
  AND boundary_code IN ('MGGT','MHTG','MNMG','MRPV','MPZL','MSLP','MHCC')
  AND NOT EXISTS (SELECT 1 FROM dbo.adl_region_group_members m
                  WHERE m.region_id = @region_id AND m.boundary_id = dbo.adl_boundary.boundary_id);

PRINT 'Inserted Central America FIRs into region';

-- Insert Caribbean FIRs
INSERT INTO dbo.adl_region_group_members (region_id, boundary_id, boundary_code)
SELECT @region_id, boundary_id, boundary_code
FROM dbo.adl_boundary
WHERE boundary_type IN ('ARTCC', 'FIR')
  AND is_active = 1
  AND boundary_code IN ('TJZS','MKJK','MUFH','MDCS','TNCF','TTPP','TFFR','TBPB',
                        'TFFF','TLPL','TAPA','TKPK','TUPJ','MYNN','MBPV')
  AND NOT EXISTS (SELECT 1 FROM dbo.adl_region_group_members m
                  WHERE m.region_id = @region_id AND m.boundary_id = dbo.adl_boundary.boundary_id);

PRINT 'Inserted Caribbean FIRs into region';

-- Insert LATAM (Caribbean/Gulf bordering)
INSERT INTO dbo.adl_region_group_members (region_id, boundary_id, boundary_code)
SELECT @region_id, boundary_id, boundary_code
FROM dbo.adl_boundary
WHERE boundary_type IN ('ARTCC', 'FIR')
  AND is_active = 1
  AND boundary_code IN ('SKED','SVZM')
  AND NOT EXISTS (SELECT 1 FROM dbo.adl_region_group_members m
                  WHERE m.region_id = @region_id AND m.boundary_id = dbo.adl_boundary.boundary_id);

PRINT 'Inserted LATAM FIRs into region';

-- ============================================================================
-- 3. Build mega-polygon from all member boundaries
-- ============================================================================
DECLARE @mega_polygon GEOGRAPHY;

;WITH MemberBoundaries AS (
    SELECT b.boundary_geography
    FROM dbo.adl_region_group_members m
    JOIN dbo.adl_boundary b ON b.boundary_id = m.boundary_id
    WHERE m.region_id = @region_id
      AND b.boundary_geography IS NOT NULL
)
SELECT @mega_polygon = geography::UnionAggregate(boundary_geography)
FROM MemberBoundaries;

UPDATE dbo.adl_region_group
SET mega_polygon = @mega_polygon
WHERE region_id = @region_id;

PRINT 'Built mega-polygon for region';

-- ============================================================================
-- 4. Report results
-- ============================================================================
SELECT
    rg.region_code,
    rg.region_name,
    COUNT(m.boundary_id) AS member_count,
    CASE WHEN rg.mega_polygon IS NOT NULL THEN 'Yes' ELSE 'No' END AS has_mega_polygon
FROM dbo.adl_region_group rg
LEFT JOIN dbo.adl_region_group_members m ON m.region_id = rg.region_id
WHERE rg.region_code = 'US_CA_MX_LATAM_CAR'
GROUP BY rg.region_code, rg.region_name, rg.mega_polygon;

PRINT '============================================================================';
PRINT 'Priority Region Population - Complete';
PRINT '============================================================================';
GO
