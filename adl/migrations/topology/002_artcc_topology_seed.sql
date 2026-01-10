-- ============================================================================
-- ADL Topology Schema - Migration 002: Seed ARTCC Tier Data
--
-- Populates the topology tables with ARTCC facilities, tier types,
-- tier groups (6West, 10West, EastCoast, etc.) and facility configurations
--
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== ADL Topology Migration 002: Seed ARTCC Tier Data ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- ============================================================================
-- 1. Seed ARTCC Facilities
-- ============================================================================

PRINT 'Seeding ARTCC facilities...';

-- Use MERGE to insert or update facilities
MERGE dbo.artcc_facilities AS target
USING (VALUES
    ('ZAB', 'Albuquerque Center', 'ARTCC', 'US'),
    ('ZAU', 'Chicago Center', 'ARTCC', 'US'),
    ('ZBW', 'Boston Center', 'ARTCC', 'US'),
    ('ZDC', 'Washington Center', 'ARTCC', 'US'),
    ('ZDV', 'Denver Center', 'ARTCC', 'US'),
    ('ZFW', 'Fort Worth Center', 'ARTCC', 'US'),
    ('ZHU', 'Houston Center', 'ARTCC', 'US'),
    ('ZID', 'Indianapolis Center', 'ARTCC', 'US'),
    ('ZJX', 'Jacksonville Center', 'ARTCC', 'US'),
    ('ZKC', 'Kansas City Center', 'ARTCC', 'US'),
    ('ZLA', 'Los Angeles Center', 'ARTCC', 'US'),
    ('ZLC', 'Salt Lake City Center', 'ARTCC', 'US'),
    ('ZMA', 'Miami Center', 'ARTCC', 'US'),
    ('ZME', 'Memphis Center', 'ARTCC', 'US'),
    ('ZMP', 'Minneapolis Center', 'ARTCC', 'US'),
    ('ZNY', 'New York Center', 'ARTCC', 'US'),
    ('ZOA', 'Oakland Center', 'ARTCC', 'US'),
    ('ZOB', 'Cleveland Center', 'ARTCC', 'US'),
    ('ZSE', 'Seattle Center', 'ARTCC', 'US'),
    ('ZTL', 'Atlanta Center', 'ARTCC', 'US'),
    -- Canadian FIRs (Domestic) - Full codes
    ('CZVR', 'Vancouver FIR', 'FIR', 'CA'),
    ('CZEG', 'Edmonton FIR', 'FIR', 'CA'),
    ('CZWG', 'Winnipeg FIR', 'FIR', 'CA'),
    ('CZYZ', 'Toronto FIR', 'FIR', 'CA'),
    ('CZUL', 'Montreal FIR', 'FIR', 'CA'),
    ('CZQM', 'Moncton FIR', 'FIR', 'CA'),
    -- Canadian FIRs (Domestic) - Short codes (aliases)
    ('CZV', 'Vancouver FIR (Short)', 'FIR', 'CA'),
    ('CZE', 'Edmonton FIR (Short)', 'FIR', 'CA'),
    ('CZW', 'Winnipeg FIR (Short)', 'FIR', 'CA'),
    ('CZY', 'Toronto FIR (Short)', 'FIR', 'CA'),
    ('CZU', 'Montreal FIR (Short)', 'FIR', 'CA'),
    ('CZM', 'Moncton FIR (Short)', 'FIR', 'CA'),
    -- Canadian FIRs (Gander)
    ('CZQX', 'Gander Domestic FIR', 'FIR', 'CA'),
    ('CZQO', 'Gander Oceanic FIR', 'OCEANIC', 'CA'),
    ('CZX', 'Gander Domestic (Short)', 'FIR', 'CA'),
    ('CZO', 'Gander Oceanic (Short)', 'OCEANIC', 'CA'),
    -- US Oceanic
    ('KZAK', 'Oakland Oceanic', 'OCEANIC', 'US'),
    ('KZWY', 'New York Oceanic', 'OCEANIC', 'US'),
    ('KZAN', 'Anchorage ARTCC', 'ARTCC', 'US'),
    -- North Atlantic (for reference)
    ('BGGL', 'Greenland (Sondrestrom)', 'OCEANIC', 'GL'),
    ('BIRD', 'Reykjavik FIR', 'OCEANIC', 'IS'),
    ('EGGX', 'Shanwick Oceanic', 'OCEANIC', 'UK'),
    ('LPPO', 'Santa Maria Oceanic', 'OCEANIC', 'PT')
) AS source (facility_code, facility_name, facility_type, country_code)
ON target.facility_code = source.facility_code
WHEN MATCHED THEN
    UPDATE SET
        facility_name = source.facility_name,
        facility_type = source.facility_type,
        country_code = source.country_code,
        updated_at = GETUTCDATE()
WHEN NOT MATCHED THEN
    INSERT (facility_code, facility_name, facility_type, country_code)
    VALUES (source.facility_code, source.facility_name, source.facility_type, source.country_code);

PRINT 'Seeded ' + CAST(@@ROWCOUNT AS VARCHAR) + ' ARTCC facilities';
GO

-- ============================================================================
-- 2. Seed Tier Types
-- ============================================================================

PRINT 'Seeding tier types...';

MERGE dbo.artcc_tier_types AS target
USING (VALUES
    ('internal', 'Internal', 'RADIAL', 1),
    ('1stTier', '1st Tier', 'RADIAL', 2),
    ('2ndTier', '2nd Tier', 'RADIAL', 3),
    ('1stTier+Canada', '1st Tier + Canada', 'RADIAL', 4),
    ('6West', '6 West', 'REGIONAL', 10),
    ('10West', '10 West', 'REGIONAL', 11),
    ('12West', '12 West', 'REGIONAL', 12),
    ('eastCoast', 'East Coast', 'COASTAL', 20),
    ('westCoast', 'West Coast', 'COASTAL', 21),
    ('gulf', 'Gulf', 'COASTAL', 22)
) AS source (tier_type_code, tier_type_label, tier_type_category, display_order)
ON target.tier_type_code = source.tier_type_code
WHEN MATCHED THEN
    UPDATE SET
        tier_type_label = source.tier_type_label,
        tier_type_category = source.tier_type_category,
        display_order = source.display_order
WHEN NOT MATCHED THEN
    INSERT (tier_type_code, tier_type_label, tier_type_category, display_order)
    VALUES (source.tier_type_code, source.tier_type_label, source.tier_type_category, source.display_order);

PRINT 'Seeded tier types';
GO

-- ============================================================================
-- 3. Seed Named Tier Groups
-- ============================================================================

PRINT 'Seeding named tier groups...';

-- First insert the tier groups
MERGE dbo.artcc_tier_groups AS target
USING (
    SELECT v.code, v.name, tt.tier_type_id, v.description, v.display_order
    FROM (VALUES
        ('6WEST', '6 West', '6West', 'Six southwestern ARTCCs: ZLA, ZLC, ZDV, ZOA, ZAB, ZSE', 1),
        ('10WEST', '10 West', '10West', 'Ten western ARTCCs', 2),
        ('12WEST', '12 West', '12West', 'Twelve western/central ARTCCs', 3),
        ('EASTCOAST', 'East Coast', 'eastCoast', 'East coast corridor: ZBW, ZNY, ZDC, ZJX, ZMA', 4),
        ('CANWEST', 'Canada West', NULL, 'Western Canadian FIRs: CZVR, CZEG', 7),
        ('CANEAST', 'Canada East', NULL, 'Eastern Canadian FIRs: CZWG, CZYZ, CZUL, CZQM', 8),
        ('WESTCOAST', 'West Coast', 'westCoast', 'West coast corridor: ZSE, ZOA, ZLA', 5),
        ('GULF', 'Gulf', 'gulf', 'Gulf region: ZJX, ZMA, ZHU', 6),
        ('ALL', 'All US ARTCCs', NULL, 'All 20 CONUS ARTCCs', 99),
        ('ALL+CANADA', 'All US + Canada', NULL, 'All 20 CONUS ARTCCs plus 6 Canadian FIRs', 100)
    ) AS v (code, name, tier_type_code, description, display_order)
    LEFT JOIN dbo.artcc_tier_types tt ON v.tier_type_code = tt.tier_type_code
) AS source
ON target.tier_group_code = source.code
WHEN MATCHED THEN
    UPDATE SET
        tier_group_name = source.name,
        tier_type_id = source.tier_type_id,
        description = source.description,
        display_order = source.display_order,
        updated_at = GETUTCDATE()
WHEN NOT MATCHED THEN
    INSERT (tier_group_code, tier_group_name, tier_type_id, description, display_order)
    VALUES (source.code, source.name, source.tier_type_id, source.description, source.display_order);

PRINT 'Seeded tier groups';
GO

-- ============================================================================
-- 4. Seed Tier Group Members
-- ============================================================================

PRINT 'Seeding tier group members...';

-- Clear existing members to avoid duplicates
DELETE FROM dbo.artcc_tier_group_members;

-- Insert 6 West members
INSERT INTO dbo.artcc_tier_group_members (tier_group_id, facility_id, display_order)
SELECT tg.tier_group_id, f.facility_id, v.display_order
FROM (VALUES
    ('6WEST', 'ZLA', 1),
    ('6WEST', 'ZLC', 2),
    ('6WEST', 'ZDV', 3),
    ('6WEST', 'ZOA', 4),
    ('6WEST', 'ZAB', 5),
    ('6WEST', 'ZSE', 6)
) AS v (group_code, facility_code, display_order)
INNER JOIN dbo.artcc_tier_groups tg ON tg.tier_group_code = v.group_code
INNER JOIN dbo.artcc_facilities f ON f.facility_code = v.facility_code;

-- Insert 10 West members
INSERT INTO dbo.artcc_tier_group_members (tier_group_id, facility_id, display_order)
SELECT tg.tier_group_id, f.facility_id, v.display_order
FROM (VALUES
    ('10WEST', 'ZAB', 1),
    ('10WEST', 'ZDV', 2),
    ('10WEST', 'ZFW', 3),
    ('10WEST', 'ZHU', 4),
    ('10WEST', 'ZKC', 5),
    ('10WEST', 'ZLA', 6),
    ('10WEST', 'ZLC', 7),
    ('10WEST', 'ZMP', 8),
    ('10WEST', 'ZOA', 9),
    ('10WEST', 'ZSE', 10)
) AS v (group_code, facility_code, display_order)
INNER JOIN dbo.artcc_tier_groups tg ON tg.tier_group_code = v.group_code
INNER JOIN dbo.artcc_facilities f ON f.facility_code = v.facility_code;

-- Insert 12 West members
INSERT INTO dbo.artcc_tier_group_members (tier_group_id, facility_id, display_order)
SELECT tg.tier_group_id, f.facility_id, v.display_order
FROM (VALUES
    ('12WEST', 'ZAB', 1),
    ('12WEST', 'ZAU', 2),
    ('12WEST', 'ZDV', 3),
    ('12WEST', 'ZFW', 4),
    ('12WEST', 'ZHU', 5),
    ('12WEST', 'ZKC', 6),
    ('12WEST', 'ZLA', 7),
    ('12WEST', 'ZLC', 8),
    ('12WEST', 'ZME', 9),
    ('12WEST', 'ZMP', 10),
    ('12WEST', 'ZOA', 11),
    ('12WEST', 'ZSE', 12)
) AS v (group_code, facility_code, display_order)
INNER JOIN dbo.artcc_tier_groups tg ON tg.tier_group_code = v.group_code
INNER JOIN dbo.artcc_facilities f ON f.facility_code = v.facility_code;

-- Insert East Coast members
INSERT INTO dbo.artcc_tier_group_members (tier_group_id, facility_id, display_order)
SELECT tg.tier_group_id, f.facility_id, v.display_order
FROM (VALUES
    ('EASTCOAST', 'ZBW', 1),
    ('EASTCOAST', 'ZNY', 2),
    ('EASTCOAST', 'ZDC', 3),
    ('EASTCOAST', 'ZJX', 4),
    ('EASTCOAST', 'ZMA', 5)
) AS v (group_code, facility_code, display_order)
INNER JOIN dbo.artcc_tier_groups tg ON tg.tier_group_code = v.group_code
INNER JOIN dbo.artcc_facilities f ON f.facility_code = v.facility_code;

-- Insert West Coast members
INSERT INTO dbo.artcc_tier_group_members (tier_group_id, facility_id, display_order)
SELECT tg.tier_group_id, f.facility_id, v.display_order
FROM (VALUES
    ('WESTCOAST', 'ZSE', 1),
    ('WESTCOAST', 'ZOA', 2),
    ('WESTCOAST', 'ZLA', 3)
) AS v (group_code, facility_code, display_order)
INNER JOIN dbo.artcc_tier_groups tg ON tg.tier_group_code = v.group_code
INNER JOIN dbo.artcc_facilities f ON f.facility_code = v.facility_code;

-- Insert Gulf members
INSERT INTO dbo.artcc_tier_group_members (tier_group_id, facility_id, display_order)
SELECT tg.tier_group_id, f.facility_id, v.display_order
FROM (VALUES
    ('GULF', 'ZJX', 1),
    ('GULF', 'ZMA', 2),
    ('GULF', 'ZHU', 3)
) AS v (group_code, facility_code, display_order)
INNER JOIN dbo.artcc_tier_groups tg ON tg.tier_group_code = v.group_code
INNER JOIN dbo.artcc_facilities f ON f.facility_code = v.facility_code;

-- Insert Canada West members
INSERT INTO dbo.artcc_tier_group_members (tier_group_id, facility_id, display_order)
SELECT tg.tier_group_id, f.facility_id, v.display_order
FROM (VALUES
    ('CANWEST', 'CZVR', 1),
    ('CANWEST', 'CZEG', 2)
) AS v (group_code, facility_code, display_order)
INNER JOIN dbo.artcc_tier_groups tg ON tg.tier_group_code = v.group_code
INNER JOIN dbo.artcc_facilities f ON f.facility_code = v.facility_code;

-- Insert Canada East members
INSERT INTO dbo.artcc_tier_group_members (tier_group_id, facility_id, display_order)
SELECT tg.tier_group_id, f.facility_id, v.display_order
FROM (VALUES
    ('CANEAST', 'CZWG', 1),
    ('CANEAST', 'CZYZ', 2),
    ('CANEAST', 'CZUL', 3),
    ('CANEAST', 'CZQM', 4)
) AS v (group_code, facility_code, display_order)
INNER JOIN dbo.artcc_tier_groups tg ON tg.tier_group_code = v.group_code
INNER JOIN dbo.artcc_facilities f ON f.facility_code = v.facility_code;

-- Insert ALL US ARTCCs members
INSERT INTO dbo.artcc_tier_group_members (tier_group_id, facility_id, display_order)
SELECT tg.tier_group_id, f.facility_id, ROW_NUMBER() OVER (ORDER BY f.facility_code)
FROM dbo.artcc_tier_groups tg
CROSS JOIN dbo.artcc_facilities f
WHERE tg.tier_group_code = 'ALL'
  AND f.facility_type = 'ARTCC'
  AND f.country_code = 'US';

-- Insert ALL+CANADA members (US ARTCCs + Canadian domestic FIRs)
INSERT INTO dbo.artcc_tier_group_members (tier_group_id, facility_id, display_order)
SELECT tg.tier_group_id, f.facility_id, ROW_NUMBER() OVER (ORDER BY f.facility_code)
FROM dbo.artcc_tier_groups tg
CROSS JOIN dbo.artcc_facilities f
WHERE tg.tier_group_code = 'ALL+CANADA'
  AND ((f.facility_type = 'ARTCC' AND f.country_code = 'US')
       OR (f.facility_type = 'FIR' AND f.country_code = 'CA'));

PRINT 'Seeded tier group members';
GO

-- ============================================================================
-- 5. Seed ARTCC/FIR Adjacencies (Border Relationships)
-- ============================================================================

PRINT 'Seeding facility adjacencies...';

-- Clear existing adjacencies
DELETE FROM dbo.artcc_adjacencies;

-- Helper temp table for adjacency data
CREATE TABLE #Adjacencies (
    facility_code NVARCHAR(8),
    adjacent_code NVARCHAR(8),
    border_type NVARCHAR(16),
    notes NVARCHAR(256)
);

-- Canadian FIR adjacencies (based on provided data)
INSERT INTO #Adjacencies VALUES
-- CZVR (Vancouver) borders
('CZVR', 'KZAN', 'LATERAL', NULL),
('CZVR', 'KZAK', 'OCEANIC', NULL),
('CZVR', 'CZEG', 'LATERAL', NULL),
('CZVR', 'ZSE', 'LATERAL', NULL),

-- CZEG (Edmonton) borders
('CZEG', 'KZAN', 'LATERAL', NULL),
('CZEG', 'CZVR', 'LATERAL', NULL),
('CZEG', 'CZWG', 'LATERAL', NULL),
('CZEG', 'ZSE', 'LATERAL', NULL),
('CZEG', 'ZLC', 'LATERAL', NULL),
('CZEG', 'BGGL', 'OCEANIC', NULL),
('CZEG', 'CZQO', 'OCEANIC', NULL),
('CZEG', 'CZUL', 'LATERAL', NULL),
('CZEG', 'CZYZ', 'LATERAL', 'When CZEG owns CZWG north high sector'),

-- CZWG (Winnipeg) borders
('CZWG', 'CZEG', 'LATERAL', NULL),
('CZWG', 'CZYZ', 'LATERAL', NULL),
('CZWG', 'CZUL', 'LATERAL', NULL),
('CZWG', 'ZMP', 'LATERAL', NULL),
('CZWG', 'ZLC', 'LATERAL', NULL),

-- CZYZ (Toronto) borders
('CZYZ', 'CZWG', 'LATERAL', NULL),
('CZYZ', 'CZUL', 'LATERAL', NULL),
('CZYZ', 'ZMP', 'LATERAL', NULL),
('CZYZ', 'ZOB', 'LATERAL', NULL),
('CZYZ', 'ZBW', 'LATERAL', NULL),
('CZYZ', 'CZEG', 'LATERAL', 'When CZEG owns CZWG north high sector'),

-- CZUL (Montreal) borders
('CZUL', 'CZEG', 'LATERAL', NULL),
('CZUL', 'CZWG', 'LATERAL', NULL),
('CZUL', 'CZYZ', 'LATERAL', NULL),
('CZUL', 'CZQM', 'LATERAL', NULL),
('CZUL', 'CZQX', 'OCEANIC', NULL),
('CZUL', 'CZQO', 'OCEANIC', NULL),
('CZUL', 'ZBW', 'LATERAL', NULL),

-- CZQM (Moncton) borders
('CZQM', 'ZBW', 'LATERAL', NULL),
('CZQM', 'KZWY', 'OCEANIC', NULL),
('CZQM', 'CZUL', 'LATERAL', NULL),
('CZQM', 'CZQX', 'OCEANIC', NULL),

-- CZQX (Gander Oceanic) borders
('CZQX', 'CZQO', 'OCEANIC', NULL),
('CZQX', 'CZUL', 'OCEANIC', NULL),
('CZQX', 'CZQM', 'OCEANIC', NULL),
('CZQX', 'KZWY', 'OCEANIC', NULL),

-- CZQO (Arctic/Polar) borders
('CZQO', 'CZQX', 'OCEANIC', NULL),
('CZQO', 'CZUL', 'OCEANIC', NULL),
('CZQO', 'CZEG', 'OCEANIC', NULL),
('CZQO', 'BGGL', 'OCEANIC', NULL),
('CZQO', 'KZWY', 'OCEANIC', NULL),
('CZQO', 'LPPO', 'OCEANIC', NULL),
('CZQO', 'EGGX', 'OCEANIC', NULL),
('CZQO', 'BIRD', 'OCEANIC', NULL),

-- US ARTCC to Canadian FIR adjacencies (derived from above)
('ZSE', 'CZVR', 'LATERAL', NULL),
('ZSE', 'CZEG', 'LATERAL', NULL),
('ZLC', 'CZEG', 'LATERAL', NULL),
('ZLC', 'CZWG', 'LATERAL', NULL),
('ZMP', 'CZWG', 'LATERAL', NULL),
('ZMP', 'CZYZ', 'LATERAL', NULL),
('ZOB', 'CZYZ', 'LATERAL', NULL),
('ZBW', 'CZYZ', 'LATERAL', NULL),
('ZBW', 'CZUL', 'LATERAL', NULL),
('ZBW', 'CZQM', 'LATERAL', NULL);

-- Insert adjacencies
INSERT INTO dbo.artcc_adjacencies (facility_id, adjacent_facility_id, border_type, notes)
SELECT
    f1.facility_id,
    f2.facility_id,
    a.border_type,
    a.notes
FROM #Adjacencies a
INNER JOIN dbo.artcc_facilities f1 ON f1.facility_code = a.facility_code
INNER JOIN dbo.artcc_facilities f2 ON f2.facility_code = a.adjacent_code;

DROP TABLE #Adjacencies;

PRINT 'Seeded ' + CAST(@@ROWCOUNT AS VARCHAR) + ' facility adjacencies';
GO

-- ============================================================================
-- 6. Verify the data
-- ============================================================================

PRINT '';
PRINT '=== Verification ===';

SELECT 'artcc_facilities' AS table_name, COUNT(*) AS row_count FROM dbo.artcc_facilities
UNION ALL
SELECT 'artcc_tier_types', COUNT(*) FROM dbo.artcc_tier_types
UNION ALL
SELECT 'artcc_tier_groups', COUNT(*) FROM dbo.artcc_tier_groups
UNION ALL
SELECT 'artcc_tier_group_members', COUNT(*) FROM dbo.artcc_tier_group_members
UNION ALL
SELECT 'artcc_adjacencies', COUNT(*) FROM dbo.artcc_adjacencies;

PRINT '';
PRINT '=== Named Tier Groups Summary ===';

SELECT
    tg.tier_group_code,
    tg.tier_group_name,
    COUNT(tgm.member_id) AS member_count,
    STRING_AGG(f.facility_code, ', ') WITHIN GROUP (ORDER BY tgm.display_order) AS members
FROM dbo.artcc_tier_groups tg
LEFT JOIN dbo.artcc_tier_group_members tgm ON tg.tier_group_id = tgm.tier_group_id
LEFT JOIN dbo.artcc_facilities f ON tgm.facility_id = f.facility_id
GROUP BY tg.tier_group_id, tg.tier_group_code, tg.tier_group_name, tg.display_order
ORDER BY tg.display_order;

-- ============================================================================
-- 6. Seed Facility-Specific Tier Configurations
-- ============================================================================

PRINT 'Seeding facility tier configurations...';

-- Clear existing configs to avoid duplicates
DELETE FROM dbo.facility_tier_config_members;
DELETE FROM dbo.facility_tier_configs;

-- Helper temp table for bulk insert
CREATE TABLE #FacilityConfigs (
    owner_facility NVARCHAR(8),
    config_code NVARCHAR(16),
    config_label NVARCHAR(32),
    tier_type_code NVARCHAR(32),
    tier_group_code NVARCHAR(16),
    display_order INT,
    is_default BIT
);

-- Insert all facility configurations
INSERT INTO #FacilityConfigs VALUES
-- ZAB
('ZAB', 'ZABI', '(Internal)', 'internal', NULL, 1, 0),
('ZAB', 'ZAB1', '(1stTier)', '1stTier', NULL, 2, 1),
('ZAB', 'ZAB2', '(2ndTier)', '2ndTier', NULL, 3, 0),
('ZAB', 'ZAB6W', '(6West)', '6West', '6WEST', 4, 0),
('ZAB', 'ZAB10W', '(10West)', '10West', '10WEST', 5, 0),
('ZAB', 'ZAB12W', '(12West)', '12West', '12WEST', 6, 0),
-- ZAU
('ZAU', 'ZAUI', '(Internal)', 'internal', NULL, 1, 0),
('ZAU', 'ZAU1', '(1stTier)', '1stTier', NULL, 2, 1),
('ZAU', 'ZAU2', '(2ndTier)', '2ndTier', NULL, 3, 0),
('ZAU', 'ZAU12W', '(12West)', '12West', '12WEST', 4, 0),
-- ZBW
('ZBW', 'ZBWI', '(Internal)', 'internal', NULL, 1, 0),
('ZBW', 'ZBW1', '(1stTier)', '1stTier', NULL, 2, 1),
('ZBW', 'ZBW1C', '(1stTier+Canada)', '1stTier+Canada', NULL, 3, 0),
('ZBW', 'ZBW2', '(2ndTier)', '2ndTier', NULL, 4, 0),
('ZBW', 'ZBWEC', '(EastCoast)', 'eastCoast', 'EASTCOAST', 5, 0),
-- ZDC
('ZDC', 'ZDCI', '(Internal)', 'internal', NULL, 1, 0),
('ZDC', 'ZDC1', '(1stTier)', '1stTier', NULL, 2, 1),
('ZDC', 'ZDC2', '(2ndTier)', '2ndTier', NULL, 3, 0),
('ZDC', 'ZDCEC', '(EastCoast)', 'eastCoast', 'EASTCOAST', 4, 0),
-- ZDV
('ZDV', 'ZDVI', '(Internal)', 'internal', NULL, 1, 0),
('ZDV', 'ZDV1', '(1stTier)', '1stTier', NULL, 2, 1),
('ZDV', 'ZDV2', '(2ndTier)', '2ndTier', NULL, 3, 0),
('ZDV', 'ZDV6W', '(6West)', '6West', '6WEST', 4, 0),
('ZDV', 'ZDV10W', '(10West)', '10West', '10WEST', 5, 0),
('ZDV', 'ZDV12W', '(12West)', '12West', '12WEST', 6, 0),
-- ZFW
('ZFW', 'ZFWI', '(Internal)', 'internal', NULL, 1, 0),
('ZFW', 'ZFW1', '(1stTier)', '1stTier', NULL, 2, 1),
('ZFW', 'ZFW2', '(2ndTier)', '2ndTier', NULL, 3, 0),
('ZFW', 'ZFW10W', '(10West)', '10West', '10WEST', 4, 0),
('ZFW', 'ZFW12W', '(12West)', '12West', '12WEST', 5, 0),
-- ZHU
('ZHU', 'ZHUI', '(Internal)', 'internal', NULL, 1, 0),
('ZHU', 'ZHU1', '(1stTier)', '1stTier', NULL, 2, 1),
('ZHU', 'ZHU2', '(2ndTier)', '2ndTier', NULL, 3, 0),
('ZHU', 'ZHUGULF', '(Gulf)', 'gulf', 'GULF', 4, 0),
('ZHU', 'ZHU10W', '(10West)', '10West', '10WEST', 5, 0),
('ZHU', 'ZHU12W', '(12West)', '12West', '12WEST', 6, 0),
-- ZID
('ZID', 'ZIDI', '(Internal)', 'internal', NULL, 1, 0),
('ZID', 'ZID1', '(1stTier)', '1stTier', NULL, 2, 1),
('ZID', 'ZID2', '(2ndTier)', '2ndTier', NULL, 3, 0),
-- ZJX
('ZJX', 'ZJXI', '(Internal)', 'internal', NULL, 1, 0),
('ZJX', 'ZJX1', '(1stTier)', '1stTier', NULL, 2, 1),
('ZJX', 'ZJX2', '(2ndTier)', '2ndTier', NULL, 3, 0),
('ZJX', 'ZJXEC', '(EastCoast)', 'eastCoast', 'EASTCOAST', 4, 0),
('ZJX', 'ZJXGULF', '(Gulf)', 'gulf', 'GULF', 5, 0),
-- ZKC
('ZKC', 'ZKCI', '(Internal)', 'internal', NULL, 1, 0),
('ZKC', 'ZKC1', '(1stTier)', '1stTier', NULL, 2, 1),
('ZKC', 'ZKC2', '(2ndTier)', '2ndTier', NULL, 3, 0),
('ZKC', 'ZKC10W', '(10West)', '10West', '10WEST', 4, 0),
('ZKC', 'ZKC12W', '(12West)', '12West', '12WEST', 5, 0),
-- ZLA
('ZLA', 'ZLAI', '(Internal)', 'internal', NULL, 1, 0),
('ZLA', 'ZLA1', '(1stTier)', '1stTier', NULL, 2, 1),
('ZLA', 'ZLA2', '(2ndTier)', '2ndTier', NULL, 3, 0),
('ZLA', 'ZLA6W', '(6West)', '6West', '6WEST', 4, 0),
('ZLA', 'ZLAWC', '(WestCoast)', 'westCoast', 'WESTCOAST', 5, 0),
('ZLA', 'ZLA10W', '(10West)', '10West', '10WEST', 6, 0),
('ZLA', 'ZLA12W', '(12West)', '12West', '12WEST', 7, 0),
-- ZLC
('ZLC', 'ZLCI', '(Internal)', 'internal', NULL, 1, 0),
('ZLC', 'ZLC1', '(1stTier)', '1stTier', NULL, 2, 1),
('ZLC', 'ZLC1C', '(1stTier+Canada)', '1stTier+Canada', NULL, 3, 0),
('ZLC', 'ZLC2', '(2ndTier)', '2ndTier', NULL, 4, 0),
('ZLC', 'ZLC6W', '(6West)', '6West', '6WEST', 5, 0),
('ZLC', 'ZLC10W', '(10West)', '10West', '10WEST', 6, 0),
('ZLC', 'ZLC12W', '(12West)', '12West', '12WEST', 7, 0),
-- ZMA
('ZMA', 'ZMAI', '(Internal)', 'internal', NULL, 1, 0),
('ZMA', 'ZMA1', '(1stTier)', '1stTier', NULL, 2, 1),
('ZMA', 'ZMA2', '(2ndTier)', '2ndTier', NULL, 3, 0),
('ZMA', 'ZMAEC', '(EastCoast)', 'eastCoast', 'EASTCOAST', 4, 0),
('ZMA', 'ZMAGULF', '(Gulf)', 'gulf', 'GULF', 5, 0),
-- ZME
('ZME', 'ZMEI', '(Internal)', 'internal', NULL, 1, 0),
('ZME', 'ZME1', '(1stTier)', '1stTier', NULL, 2, 1),
('ZME', 'ZME2', '(2ndTier)', '2ndTier', NULL, 3, 0),
('ZME', 'ZME12W', '(12West)', '12West', '12WEST', 4, 0),
-- ZMP
('ZMP', 'ZMPI', '(Internal)', 'internal', NULL, 1, 0),
('ZMP', 'ZMP1', '(1stTier)', '1stTier', NULL, 2, 1),
('ZMP', 'ZMP1C', '(1stTier+Canada)', '1stTier+Canada', NULL, 3, 0),
('ZMP', 'ZMP2', '(2ndTier)', '2ndTier', NULL, 4, 0),
('ZMP', 'ZMP10W', '(10West)', '10West', '10WEST', 5, 0),
('ZMP', 'ZMP12W', '(12West)', '12West', '12WEST', 6, 0),
-- ZNY
('ZNY', 'ZNYI', '(Internal)', 'internal', NULL, 1, 0),
('ZNY', 'ZNY1', '(1stTier)', '1stTier', NULL, 2, 1),
('ZNY', 'ZNY2', '(2ndTier)', '2ndTier', NULL, 3, 0),
('ZNY', 'ZNYEC', '(EastCoast)', 'eastCoast', 'EASTCOAST', 4, 0),
-- ZOA
('ZOA', 'ZOAI', '(Internal)', 'internal', NULL, 1, 0),
('ZOA', 'ZOA1', '(1stTier)', '1stTier', NULL, 2, 1),
('ZOA', 'ZOA2', '(2ndTier)', '2ndTier', NULL, 3, 0),
('ZOA', 'ZOA6W', '(6West)', '6West', '6WEST', 4, 0),
('ZOA', 'ZOAWC', '(WestCoast)', 'westCoast', 'WESTCOAST', 5, 0),
('ZOA', 'ZOA10W', '(10West)', '10West', '10WEST', 6, 0),
('ZOA', 'ZOA12W', '(12West)', '12West', '12WEST', 7, 0),
-- ZOB
('ZOB', 'ZOBI', '(Internal)', 'internal', NULL, 1, 0),
('ZOB', 'ZOB1', '(1stTier)', '1stTier', NULL, 2, 1),
('ZOB', 'ZOB1C', '(1stTier+Canada)', '1stTier+Canada', NULL, 3, 0),
('ZOB', 'ZOB2', '(2ndTier)', '2ndTier', NULL, 4, 0),
-- ZSE
('ZSE', 'ZSEI', '(Internal)', 'internal', NULL, 1, 0),
('ZSE', 'ZSE1', '(1stTier)', '1stTier', NULL, 2, 1),
('ZSE', 'ZSE1C', '(1stTier+Canada)', '1stTier+Canada', NULL, 3, 0),
('ZSE', 'ZSE2', '(2ndTier)', '2ndTier', NULL, 4, 0),
('ZSE', 'ZSE6W', '(6West)', '6West', '6WEST', 5, 0),
('ZSE', 'ZSEWC', '(WestCoast)', 'westCoast', 'WESTCOAST', 6, 0),
('ZSE', 'ZSE10W', '(10West)', '10West', '10WEST', 7, 0),
('ZSE', 'ZSE12W', '(12West)', '12West', '12WEST', 8, 0),
-- ZTL
('ZTL', 'ZTLI', '(Internal)', 'internal', NULL, 1, 0),
('ZTL', 'ZTL1', '(1stTier)', '1stTier', NULL, 2, 1),
('ZTL', 'ZTL2', '(2ndTier)', '2ndTier', NULL, 3, 0);

-- Insert into facility_tier_configs
INSERT INTO dbo.facility_tier_configs (facility_id, config_code, config_label, tier_type_id, tier_group_id, display_order, is_default)
SELECT
    f.facility_id,
    fc.config_code,
    fc.config_label,
    tt.tier_type_id,
    tg.tier_group_id,
    fc.display_order,
    fc.is_default
FROM #FacilityConfigs fc
INNER JOIN dbo.artcc_facilities f ON f.facility_code = fc.owner_facility
LEFT JOIN dbo.artcc_tier_types tt ON tt.tier_type_code = fc.tier_type_code
LEFT JOIN dbo.artcc_tier_groups tg ON tg.tier_group_code = fc.tier_group_code;

DROP TABLE #FacilityConfigs;

PRINT 'Inserted facility tier configurations';
GO

-- ============================================================================
-- 7. Seed Facility-Specific Config Members (for configs without tier_group)
-- ============================================================================

PRINT 'Seeding facility tier config members...';

-- Helper temp table for member data
CREATE TABLE #ConfigMembers (
    config_code NVARCHAR(16),
    member_facility NVARCHAR(8),
    display_order INT
);

-- Insert all config member mappings (only for configs that don't use a tier_group)
INSERT INTO #ConfigMembers VALUES
-- ZAB Internal
('ZABI', 'ZAB', 1),
-- ZAB 1st Tier
('ZAB1', 'ZAB', 1), ('ZAB1', 'ZLA', 2), ('ZAB1', 'ZDV', 3), ('ZAB1', 'ZKC', 4), ('ZAB1', 'ZFW', 5), ('ZAB1', 'ZHU', 6),
-- ZAB 2nd Tier
('ZAB2', 'ZAB', 1), ('ZAB2', 'ZLA', 2), ('ZAB2', 'ZDV', 3), ('ZAB2', 'ZKC', 4), ('ZAB2', 'ZFW', 5), ('ZAB2', 'ZHU', 6),
('ZAB2', 'ZOA', 7), ('ZAB2', 'ZLC', 8), ('ZAB2', 'ZMP', 9), ('ZAB2', 'ZAU', 10), ('ZAB2', 'ZID', 11), ('ZAB2', 'ZME', 12), ('ZAB2', 'ZTL', 13), ('ZAB2', 'ZJX', 14),

-- ZAU Internal
('ZAUI', 'ZAU', 1),
-- ZAU 1st Tier
('ZAU1', 'ZAU', 1), ('ZAU1', 'ZMP', 2), ('ZAU1', 'ZKC', 3), ('ZAU1', 'ZID', 4), ('ZAU1', 'ZOB', 5),
-- ZAU 2nd Tier
('ZAU2', 'ZAU', 1), ('ZAU2', 'ZMP', 2), ('ZAU2', 'ZKC', 3), ('ZAU2', 'ZID', 4), ('ZAU2', 'ZOB', 5),
('ZAU2', 'ZLC', 6), ('ZAU2', 'ZDV', 7), ('ZAU2', 'ZAB', 8), ('ZAU2', 'ZFW', 9), ('ZAU2', 'ZME', 10), ('ZAU2', 'ZTL', 11), ('ZAU2', 'ZDC', 12), ('ZAU2', 'ZNY', 13), ('ZAU2', 'ZBW', 14),

-- ZBW Internal
('ZBWI', 'ZBW', 1),
-- ZBW 1st Tier
('ZBW1', 'ZBW', 1), ('ZBW1', 'ZDC', 2), ('ZBW1', 'ZNY', 3), ('ZBW1', 'ZOB', 4),
-- ZBW 1st Tier + Canada
('ZBW1C', 'ZBW', 1), ('ZBW1C', 'ZDC', 2), ('ZBW1C', 'ZNY', 3), ('ZBW1C', 'ZOB', 4),
('ZBW1C', 'CZYZ', 5), ('ZBW1C', 'CZUL', 6), ('ZBW1C', 'CZQM', 7),
-- ZBW 2nd Tier
('ZBW2', 'ZBW', 1), ('ZBW2', 'ZDC', 2), ('ZBW2', 'ZNY', 3), ('ZBW2', 'ZOB', 4),
('ZBW2', 'ZMP', 5), ('ZBW2', 'ZAU', 6), ('ZBW2', 'ZID', 7), ('ZBW2', 'ZTL', 8), ('ZBW2', 'ZJX', 9),

-- ZDC Internal
('ZDCI', 'ZDC', 1),
-- ZDC 1st Tier
('ZDC1', 'ZDC', 1), ('ZDC1', 'ZBW', 2), ('ZDC1', 'ZNY', 3), ('ZDC1', 'ZOB', 4), ('ZDC1', 'ZID', 5), ('ZDC1', 'ZTL', 6), ('ZDC1', 'ZJX', 7),
-- ZDC 2nd Tier
('ZDC2', 'ZDC', 1), ('ZDC2', 'ZBW', 2), ('ZDC2', 'ZNY', 3), ('ZDC2', 'ZOB', 4), ('ZDC2', 'ZID', 5), ('ZDC2', 'ZTL', 6), ('ZDC2', 'ZJX', 7),
('ZDC2', 'ZMA', 8), ('ZDC2', 'ZHU', 9), ('ZDC2', 'ZME', 10), ('ZDC2', 'ZKC', 11), ('ZDC2', 'ZAU', 12), ('ZDC2', 'ZMP', 13),

-- ZDV Internal
('ZDVI', 'ZDV', 1),
-- ZDV 1st Tier
('ZDV1', 'ZDV', 1), ('ZDV1', 'ZLC', 2), ('ZDV1', 'ZLA', 3), ('ZDV1', 'ZAB', 4), ('ZDV1', 'ZMP', 5), ('ZDV1', 'ZKC', 6),
-- ZDV 2nd Tier
('ZDV2', 'ZDV', 1), ('ZDV2', 'ZLC', 2), ('ZDV2', 'ZLA', 3), ('ZDV2', 'ZAB', 4), ('ZDV2', 'ZMP', 5), ('ZDV2', 'ZKC', 6),
('ZDV2', 'ZSE', 7), ('ZDV2', 'ZOA', 8), ('ZDV2', 'ZFW', 9), ('ZDV2', 'ZME', 10), ('ZDV2', 'ZHU', 11), ('ZDV2', 'ZID', 12), ('ZDV2', 'ZOB', 13), ('ZDV2', 'ZAU', 14),

-- ZFW Internal
('ZFWI', 'ZFW', 1),
-- ZFW 1st Tier
('ZFW1', 'ZFW', 1), ('ZFW1', 'ZME', 2), ('ZFW1', 'ZKC', 3), ('ZFW1', 'ZAB', 4), ('ZFW1', 'ZHU', 5),
-- ZFW 2nd Tier
('ZFW2', 'ZFW', 1), ('ZFW2', 'ZME', 2), ('ZFW2', 'ZKC', 3), ('ZFW2', 'ZAB', 4), ('ZFW2', 'ZHU', 5),
('ZFW2', 'ZLA', 6), ('ZFW2', 'ZDV', 7), ('ZFW2', 'ZMP', 8), ('ZFW2', 'ZAU', 9), ('ZFW2', 'ZID', 10), ('ZFW2', 'ZTL', 11), ('ZFW2', 'ZJX', 12), ('ZFW2', 'ZMA', 13),

-- ZHU Internal
('ZHUI', 'ZHU', 1),
-- ZHU 1st Tier
('ZHU1', 'ZHU', 1), ('ZHU1', 'ZAB', 2), ('ZHU1', 'ZFW', 3), ('ZHU1', 'ZME', 4), ('ZHU1', 'ZTL', 5), ('ZHU1', 'ZJX', 6), ('ZHU1', 'ZMA', 7),
-- ZHU 2nd Tier
('ZHU2', 'ZHU', 1), ('ZHU2', 'ZAB', 2), ('ZHU2', 'ZFW', 3), ('ZHU2', 'ZME', 4), ('ZHU2', 'ZTL', 5), ('ZHU2', 'ZJX', 6), ('ZHU2', 'ZMA', 7),
('ZHU2', 'ZLA', 8), ('ZHU2', 'ZDV', 9), ('ZHU2', 'ZKC', 10), ('ZHU2', 'ZID', 11), ('ZHU2', 'ZDC', 12),

-- ZID Internal
('ZIDI', 'ZID', 1),
-- ZID 1st Tier
('ZID1', 'ZID', 1), ('ZID1', 'ZAU', 2), ('ZID1', 'ZOB', 3), ('ZID1', 'ZDC', 4), ('ZID1', 'ZME', 5), ('ZID1', 'ZTL', 6), ('ZID1', 'ZKC', 7),
-- ZID 2nd Tier
('ZID2', 'ZID', 1), ('ZID2', 'ZAU', 2), ('ZID2', 'ZOB', 3), ('ZID2', 'ZDC', 4), ('ZID2', 'ZME', 5), ('ZID2', 'ZTL', 6), ('ZID2', 'ZKC', 7),
('ZID2', 'ZAB', 8), ('ZID2', 'ZDV', 9), ('ZID2', 'ZFW', 10), ('ZID2', 'ZMP', 11), ('ZID2', 'ZHU', 12), ('ZID2', 'ZJX', 13), ('ZID2', 'ZNY', 14), ('ZID2', 'ZBW', 15),

-- ZJX Internal
('ZJXI', 'ZJX', 1),
-- ZJX 1st Tier
('ZJX1', 'ZJX', 1), ('ZJX1', 'ZMA', 2), ('ZJX1', 'ZHU', 3), ('ZJX1', 'ZTL', 4), ('ZJX1', 'ZDC', 5),
-- ZJX 2nd Tier
('ZJX2', 'ZJX', 1), ('ZJX2', 'ZMA', 2), ('ZJX2', 'ZHU', 3), ('ZJX2', 'ZTL', 4), ('ZJX2', 'ZDC', 5),
('ZJX2', 'ZAB', 6), ('ZJX2', 'ZFW', 7), ('ZJX2', 'ZME', 8), ('ZJX2', 'ZID', 9), ('ZJX2', 'ZOB', 10), ('ZJX2', 'ZNY', 11), ('ZJX2', 'ZBW', 12),

-- ZKC Internal
('ZKCI', 'ZKC', 1),
-- ZKC 1st Tier
('ZKC1', 'ZKC', 1), ('ZKC1', 'ZMP', 2), ('ZKC1', 'ZAU', 3), ('ZKC1', 'ZID', 4), ('ZKC1', 'ZME', 5), ('ZKC1', 'ZFW', 6), ('ZKC1', 'ZAB', 7), ('ZKC1', 'ZDV', 8),
-- ZKC 2nd Tier
('ZKC2', 'ZKC', 1), ('ZKC2', 'ZMP', 2), ('ZKC2', 'ZAU', 3), ('ZKC2', 'ZID', 4), ('ZKC2', 'ZME', 5), ('ZKC2', 'ZFW', 6), ('ZKC2', 'ZAB', 7), ('ZKC2', 'ZDV', 8),
('ZKC2', 'ZLC', 9), ('ZKC2', 'ZLA', 10), ('ZKC2', 'ZHU', 11), ('ZKC2', 'ZTL', 12), ('ZKC2', 'ZDC', 13), ('ZKC2', 'ZOB', 14),

-- ZLA Internal
('ZLAI', 'ZLA', 1),
-- ZLA 1st Tier
('ZLA1', 'ZLA', 1), ('ZLA1', 'ZLC', 2), ('ZLA1', 'ZOA', 3), ('ZLA1', 'ZDV', 4), ('ZLA1', 'ZAB', 5),
-- ZLA 2nd Tier
('ZLA2', 'ZLA', 1), ('ZLA2', 'ZLC', 2), ('ZLA2', 'ZOA', 3), ('ZLA2', 'ZDV', 4), ('ZLA2', 'ZAB', 5),
('ZLA2', 'ZSE', 6), ('ZLA2', 'ZMP', 7), ('ZLA2', 'ZKC', 8), ('ZLA2', 'ZFW', 9), ('ZLA2', 'ZHU', 10),

-- ZLC Internal
('ZLCI', 'ZLC', 1),
-- ZLC 1st Tier
('ZLC1', 'ZLC', 1), ('ZLC1', 'ZDV', 2), ('ZLC1', 'ZLA', 3), ('ZLC1', 'ZMP', 4), ('ZLC1', 'ZOA', 5), ('ZLC1', 'ZSE', 6),
-- ZLC 1st Tier + Canada
('ZLC1C', 'ZLC', 1), ('ZLC1C', 'ZDV', 2), ('ZLC1C', 'ZLA', 3), ('ZLC1C', 'ZMP', 4), ('ZLC1C', 'ZOA', 5), ('ZLC1C', 'ZSE', 6),
('ZLC1C', 'CZEG', 7), ('ZLC1C', 'CZWG', 8),
-- ZLC 2nd Tier
('ZLC2', 'ZLC', 1), ('ZLC2', 'ZDV', 2), ('ZLC2', 'ZLA', 3), ('ZLC2', 'ZMP', 4), ('ZLC2', 'ZOA', 5), ('ZLC2', 'ZSE', 6),
('ZLC2', 'ZAB', 7), ('ZLC2', 'ZKC', 8), ('ZLC2', 'ZAU', 9), ('ZLC2', 'ZOB', 10),

-- ZMA Internal
('ZMAI', 'ZMA', 1),
-- ZMA 1st Tier
('ZMA1', 'ZMA', 1), ('ZMA1', 'ZJX', 2), ('ZMA1', 'ZHU', 3),
-- ZMA 2nd Tier
('ZMA2', 'ZMA', 1), ('ZMA2', 'ZJX', 2), ('ZMA2', 'ZHU', 3),
('ZMA2', 'ZFW', 4), ('ZMA2', 'ZME', 5), ('ZMA2', 'ZTL', 6), ('ZMA2', 'ZDC', 7),

-- ZME Internal
('ZMEI', 'ZME', 1),
-- ZME 1st Tier
('ZME1', 'ZME', 1), ('ZME1', 'ZTL', 2), ('ZME1', 'ZID', 3), ('ZME1', 'ZKC', 4), ('ZME1', 'ZFW', 5), ('ZME1', 'ZHU', 6),
-- ZME 2nd Tier
('ZME2', 'ZME', 1), ('ZME2', 'ZTL', 2), ('ZME2', 'ZID', 3), ('ZME2', 'ZKC', 4), ('ZME2', 'ZFW', 5), ('ZME2', 'ZHU', 6),
('ZME2', 'ZAB', 7), ('ZME2', 'ZDV', 8), ('ZME2', 'ZMP', 9), ('ZME2', 'ZAU', 10), ('ZME2', 'ZOB', 11), ('ZME2', 'ZDC', 12), ('ZME2', 'ZJX', 13), ('ZME2', 'ZMA', 14),

-- ZMP Internal
('ZMPI', 'ZMP', 1),
-- ZMP 1st Tier
('ZMP1', 'ZMP', 1), ('ZMP1', 'ZAU', 2), ('ZMP1', 'ZOB', 3), ('ZMP1', 'ZKC', 4), ('ZMP1', 'ZDV', 5), ('ZMP1', 'ZLC', 6),
-- ZMP 1st Tier + Canada
('ZMP1C', 'ZMP', 1), ('ZMP1C', 'ZAU', 2), ('ZMP1C', 'ZOB', 3), ('ZMP1C', 'ZKC', 4), ('ZMP1C', 'ZDV', 5), ('ZMP1C', 'ZLC', 6),
('ZMP1C', 'CZWG', 7), ('ZMP1C', 'CZYZ', 8),
-- ZMP 2nd Tier
('ZMP2', 'ZMP', 1), ('ZMP2', 'ZAU', 2), ('ZMP2', 'ZOB', 3), ('ZMP2', 'ZKC', 4), ('ZMP2', 'ZDV', 5), ('ZMP2', 'ZLC', 6),
('ZMP2', 'ZSE', 7), ('ZMP2', 'ZOA', 8), ('ZMP2', 'ZLA', 9), ('ZMP2', 'ZAB', 10), ('ZMP2', 'ZFW', 11), ('ZMP2', 'ZME', 12), ('ZMP2', 'ZID', 13), ('ZMP2', 'ZDC', 14), ('ZMP2', 'ZNY', 15), ('ZMP2', 'ZBW', 16),

-- ZNY Internal
('ZNYI', 'ZNY', 1),
-- ZNY 1st Tier
('ZNY1', 'ZNY', 1), ('ZNY1', 'ZBW', 2), ('ZNY1', 'ZDC', 3), ('ZNY1', 'ZOB', 4),
-- ZNY 2nd Tier
('ZNY2', 'ZNY', 1), ('ZNY2', 'ZBW', 2), ('ZNY2', 'ZDC', 3), ('ZNY2', 'ZOB', 4),
('ZNY2', 'ZMP', 5), ('ZNY2', 'ZAU', 6), ('ZNY2', 'ZID', 7), ('ZNY2', 'ZTL', 8), ('ZNY2', 'ZJX', 9),

-- ZOA Internal
('ZOAI', 'ZOA', 1),
-- ZOA 1st Tier
('ZOA1', 'ZOA', 1), ('ZOA1', 'ZLA', 2), ('ZOA1', 'ZSE', 3), ('ZOA1', 'ZLC', 4),
-- ZOA 2nd Tier
('ZOA2', 'ZOA', 1), ('ZOA2', 'ZLA', 2), ('ZOA2', 'ZSE', 3), ('ZOA2', 'ZLC', 4),
('ZOA2', 'ZDV', 5), ('ZOA2', 'ZAB', 6), ('ZOA2', 'ZMP', 7),

-- ZOB Internal
('ZOBI', 'ZOB', 1),
-- ZOB 1st Tier
('ZOB1', 'ZOB', 1), ('ZOB1', 'ZAU', 2), ('ZOB1', 'ZMP', 3), ('ZOB1', 'ZID', 4), ('ZOB1', 'ZDC', 5), ('ZOB1', 'ZNY', 6), ('ZOB1', 'ZBW', 7),
-- ZOB 1st Tier + Canada
('ZOB1C', 'ZOB', 1), ('ZOB1C', 'ZAU', 2), ('ZOB1C', 'ZMP', 3), ('ZOB1C', 'ZID', 4), ('ZOB1C', 'ZDC', 5), ('ZOB1C', 'ZNY', 6), ('ZOB1C', 'ZBW', 7),
('ZOB1C', 'CZYZ', 8),
-- ZOB 2nd Tier
('ZOB2', 'ZOB', 1), ('ZOB2', 'ZAU', 2), ('ZOB2', 'ZMP', 3), ('ZOB2', 'ZID', 4), ('ZOB2', 'ZDC', 5), ('ZOB2', 'ZNY', 6), ('ZOB2', 'ZBW', 7),
('ZOB2', 'ZLC', 8), ('ZOB2', 'ZDV', 9), ('ZOB2', 'ZKC', 10), ('ZOB2', 'ZME', 11), ('ZOB2', 'ZTL', 12), ('ZOB2', 'ZJX', 13),

-- ZSE Internal
('ZSEI', 'ZSE', 1),
-- ZSE 1st Tier
('ZSE1', 'ZSE', 1), ('ZSE1', 'ZOA', 2), ('ZSE1', 'ZLC', 3),
-- ZSE 1st Tier + Canada
('ZSE1C', 'ZSE', 1), ('ZSE1C', 'ZOA', 2), ('ZSE1C', 'ZLC', 3),
('ZSE1C', 'CZVR', 4), ('ZSE1C', 'CZEG', 5),
-- ZSE 2nd Tier
('ZSE2', 'ZSE', 1), ('ZSE2', 'ZOA', 2), ('ZSE2', 'ZLC', 3),
('ZSE2', 'ZMP', 4), ('ZSE2', 'ZDV', 5), ('ZSE2', 'ZLA', 6),

-- ZTL Internal
('ZTLI', 'ZTL', 1),
-- ZTL 1st Tier
('ZTL1', 'ZTL', 1), ('ZTL1', 'ZID', 2), ('ZTL1', 'ZDC', 3), ('ZTL1', 'ZJX', 4), ('ZTL1', 'ZME', 5), ('ZTL1', 'ZHU', 6),
-- ZTL 2nd Tier
('ZTL2', 'ZTL', 1), ('ZTL2', 'ZID', 2), ('ZTL2', 'ZDC', 3), ('ZTL2', 'ZJX', 4), ('ZTL2', 'ZME', 5), ('ZTL2', 'ZHU', 6),
('ZTL2', 'ZAB', 7), ('ZTL2', 'ZFW', 8), ('ZTL2', 'ZKC', 9), ('ZTL2', 'ZAU', 10), ('ZTL2', 'ZOB', 11), ('ZTL2', 'ZNY', 12), ('ZTL2', 'ZBW', 13), ('ZTL2', 'ZMA', 14);

-- Insert into facility_tier_config_members (only for configs without tier_group_id)
INSERT INTO dbo.facility_tier_config_members (config_id, facility_id, display_order)
SELECT
    fc.config_id,
    f.facility_id,
    cm.display_order
FROM #ConfigMembers cm
INNER JOIN dbo.facility_tier_configs fc ON fc.config_code = cm.config_code
INNER JOIN dbo.artcc_facilities f ON f.facility_code = cm.member_facility
WHERE fc.tier_group_id IS NULL;  -- Only for configs that don't reference a tier group

DROP TABLE #ConfigMembers;

PRINT 'Inserted facility tier config members';
GO

-- ============================================================================
-- 8. Final Verification
-- ============================================================================

PRINT '';
PRINT '=== Final Verification ===';

SELECT 'artcc_facilities' AS table_name, COUNT(*) AS row_count FROM dbo.artcc_facilities
UNION ALL
SELECT 'artcc_tier_types', COUNT(*) FROM dbo.artcc_tier_types
UNION ALL
SELECT 'artcc_tier_groups', COUNT(*) FROM dbo.artcc_tier_groups
UNION ALL
SELECT 'artcc_tier_group_members', COUNT(*) FROM dbo.artcc_tier_group_members
UNION ALL
SELECT 'facility_tier_configs', COUNT(*) FROM dbo.facility_tier_configs
UNION ALL
SELECT 'facility_tier_config_members', COUNT(*) FROM dbo.facility_tier_config_members;

PRINT '';
PRINT '=== Named Tier Groups Summary ===';

SELECT
    tg.tier_group_code,
    tg.tier_group_name,
    COUNT(tgm.member_id) AS member_count,
    STRING_AGG(f.facility_code, ', ') WITHIN GROUP (ORDER BY tgm.display_order) AS members
FROM dbo.artcc_tier_groups tg
LEFT JOIN dbo.artcc_tier_group_members tgm ON tg.tier_group_id = tgm.tier_group_id
LEFT JOIN dbo.artcc_facilities f ON tgm.facility_id = f.facility_id
GROUP BY tg.tier_group_id, tg.tier_group_code, tg.tier_group_name, tg.display_order
ORDER BY tg.display_order;

PRINT '';
PRINT '=== Facility Configurations Per ARTCC ===';

SELECT
    ff.facility_code,
    COUNT(*) AS config_count,
    STRING_AGG(fc.config_code, ', ') WITHIN GROUP (ORDER BY fc.display_order) AS configs
FROM dbo.facility_tier_configs fc
INNER JOIN dbo.artcc_facilities ff ON fc.facility_id = ff.facility_id
GROUP BY ff.facility_id, ff.facility_code
ORDER BY ff.facility_code;

PRINT '';
PRINT '=== Sample: ZAB Configurations with Members ===';

SELECT
    fc.config_code,
    fc.config_label,
    COALESCE(
        dbo.fn_GetTierGroupARTCCs(tg.tier_group_code),
        STRING_AGG(mf.facility_code, ', ') WITHIN GROUP (ORDER BY fcm.display_order)
    ) AS members
FROM dbo.facility_tier_configs fc
INNER JOIN dbo.artcc_facilities ff ON fc.facility_id = ff.facility_id
LEFT JOIN dbo.artcc_tier_groups tg ON fc.tier_group_id = tg.tier_group_id
LEFT JOIN dbo.facility_tier_config_members fcm ON fc.config_id = fcm.config_id
LEFT JOIN dbo.artcc_facilities mf ON fcm.facility_id = mf.facility_id
WHERE ff.facility_code = 'ZAB'
GROUP BY fc.config_id, fc.config_code, fc.config_label, tg.tier_group_code, fc.display_order
ORDER BY fc.display_order;

PRINT '';
PRINT '=== ADL Topology Migration 002 Complete ===';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO
