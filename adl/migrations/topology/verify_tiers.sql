-- ============================================================================
-- Verify Tier Configuration Data
-- Run this to compare database tier data against TierInfo.csv
-- ============================================================================

SET NOCOUNT ON;

PRINT '=== Sample Facility Tier Configurations ===';
PRINT '';

-- Show ZAB configs (has 6West, 10West, 12West variations)
PRINT '--- ZAB Configurations ---';
SELECT
    fc.config_code,
    fc.config_label,
    COALESCE(
        -- If config references a tier group, get members from there
        (SELECT STRING_AGG(gf.facility_code, ' ') WITHIN GROUP (ORDER BY tgm.display_order)
         FROM dbo.artcc_tier_group_members tgm
         INNER JOIN dbo.artcc_facilities gf ON tgm.facility_id = gf.facility_id
         WHERE tgm.tier_group_id = fc.tier_group_id),
        -- Otherwise get from config members
        STRING_AGG(mf.facility_code, ' ') WITHIN GROUP (ORDER BY fcm.display_order)
    ) AS facilities
FROM dbo.facility_tier_configs fc
INNER JOIN dbo.artcc_facilities ff ON fc.facility_id = ff.facility_id
LEFT JOIN dbo.facility_tier_config_members fcm ON fc.config_id = fcm.config_id
LEFT JOIN dbo.artcc_facilities mf ON fcm.facility_id = mf.facility_id
WHERE ff.facility_code = 'ZAB'
GROUP BY fc.config_id, fc.config_code, fc.config_label, fc.tier_group_id, fc.display_order
ORDER BY fc.display_order;

PRINT '';
PRINT '--- ZBW Configurations (has +Canada and EastCoast) ---';
SELECT
    fc.config_code,
    fc.config_label,
    COALESCE(
        (SELECT STRING_AGG(gf.facility_code, ' ') WITHIN GROUP (ORDER BY tgm.display_order)
         FROM dbo.artcc_tier_group_members tgm
         INNER JOIN dbo.artcc_facilities gf ON tgm.facility_id = gf.facility_id
         WHERE tgm.tier_group_id = fc.tier_group_id),
        STRING_AGG(mf.facility_code, ' ') WITHIN GROUP (ORDER BY fcm.display_order)
    ) AS facilities
FROM dbo.facility_tier_configs fc
INNER JOIN dbo.artcc_facilities ff ON fc.facility_id = ff.facility_id
LEFT JOIN dbo.facility_tier_config_members fcm ON fc.config_id = fcm.config_id
LEFT JOIN dbo.artcc_facilities mf ON fcm.facility_id = mf.facility_id
WHERE ff.facility_code = 'ZBW'
GROUP BY fc.config_id, fc.config_code, fc.config_label, fc.tier_group_id, fc.display_order
ORDER BY fc.display_order;

PRINT '';
PRINT '--- ZSE Configurations (has +Canada, 6West, WestCoast) ---';
SELECT
    fc.config_code,
    fc.config_label,
    COALESCE(
        (SELECT STRING_AGG(gf.facility_code, ' ') WITHIN GROUP (ORDER BY tgm.display_order)
         FROM dbo.artcc_tier_group_members tgm
         INNER JOIN dbo.artcc_facilities gf ON tgm.facility_id = gf.facility_id
         WHERE tgm.tier_group_id = fc.tier_group_id),
        STRING_AGG(mf.facility_code, ' ') WITHIN GROUP (ORDER BY fcm.display_order)
    ) AS facilities
FROM dbo.facility_tier_configs fc
INNER JOIN dbo.artcc_facilities ff ON fc.facility_id = ff.facility_id
LEFT JOIN dbo.facility_tier_config_members fcm ON fc.config_id = fcm.config_id
LEFT JOIN dbo.artcc_facilities mf ON fcm.facility_id = mf.facility_id
WHERE ff.facility_code = 'ZSE'
GROUP BY fc.config_id, fc.config_code, fc.config_label, fc.tier_group_id, fc.display_order
ORDER BY fc.display_order;

PRINT '';
PRINT '--- ZJX Configurations (has EastCoast, Gulf) ---';
SELECT
    fc.config_code,
    fc.config_label,
    COALESCE(
        (SELECT STRING_AGG(gf.facility_code, ' ') WITHIN GROUP (ORDER BY tgm.display_order)
         FROM dbo.artcc_tier_group_members tgm
         INNER JOIN dbo.artcc_facilities gf ON tgm.facility_id = gf.facility_id
         WHERE tgm.tier_group_id = fc.tier_group_id),
        STRING_AGG(mf.facility_code, ' ') WITHIN GROUP (ORDER BY fcm.display_order)
    ) AS facilities
FROM dbo.facility_tier_configs fc
INNER JOIN dbo.artcc_facilities ff ON fc.facility_id = ff.facility_id
LEFT JOIN dbo.facility_tier_config_members fcm ON fc.config_id = fcm.config_id
LEFT JOIN dbo.artcc_facilities mf ON fcm.facility_id = mf.facility_id
WHERE ff.facility_code = 'ZJX'
GROUP BY fc.config_id, fc.config_code, fc.config_label, fc.tier_group_id, fc.display_order
ORDER BY fc.display_order;

PRINT '';
PRINT '--- ZLC Configurations (has +Canada, 6West) ---';
SELECT
    fc.config_code,
    fc.config_label,
    COALESCE(
        (SELECT STRING_AGG(gf.facility_code, ' ') WITHIN GROUP (ORDER BY tgm.display_order)
         FROM dbo.artcc_tier_group_members tgm
         INNER JOIN dbo.artcc_facilities gf ON tgm.facility_id = gf.facility_id
         WHERE tgm.tier_group_id = fc.tier_group_id),
        STRING_AGG(mf.facility_code, ' ') WITHIN GROUP (ORDER BY fcm.display_order)
    ) AS facilities
FROM dbo.facility_tier_configs fc
INNER JOIN dbo.artcc_facilities ff ON fc.facility_id = ff.facility_id
LEFT JOIN dbo.facility_tier_config_members fcm ON fc.config_id = fcm.config_id
LEFT JOIN dbo.artcc_facilities mf ON fcm.facility_id = mf.facility_id
WHERE ff.facility_code = 'ZLC'
GROUP BY fc.config_id, fc.config_code, fc.config_label, fc.tier_group_id, fc.display_order
ORDER BY fc.display_order;

PRINT '';
PRINT '=== Named Tier Groups ===';
SELECT
    tg.tier_group_code,
    tg.tier_group_name,
    COUNT(tgm.member_id) AS member_count,
    STRING_AGG(f.facility_code, ' ') WITHIN GROUP (ORDER BY tgm.display_order) AS facilities
FROM dbo.artcc_tier_groups tg
LEFT JOIN dbo.artcc_tier_group_members tgm ON tg.tier_group_id = tgm.tier_group_id
LEFT JOIN dbo.artcc_facilities f ON tgm.facility_id = f.facility_id
GROUP BY tg.tier_group_id, tg.tier_group_code, tg.tier_group_name, tg.display_order
ORDER BY tg.display_order;

PRINT '';
PRINT '=== Summary Statistics ===';
SELECT 'Total Facilities' AS metric, COUNT(*) AS value FROM dbo.artcc_facilities
UNION ALL
SELECT 'Total Tier Configs', COUNT(*) FROM dbo.facility_tier_configs
UNION ALL
SELECT 'Total Config Members', COUNT(*) FROM dbo.facility_tier_config_members
UNION ALL
SELECT 'Total Tier Groups', COUNT(*) FROM dbo.artcc_tier_groups
UNION ALL
SELECT 'Total Group Members', COUNT(*) FROM dbo.artcc_tier_group_members;

PRINT '';
PRINT '=== Expected Values (FIR codes) ===';
PRINT 'ZAB1 should be: ZAB ZLA ZDV ZKC ZFW ZHU';
PRINT 'ZAB6W should be: ZLA ZLC ZDV ZOA ZAB ZSE (via 6WEST tier group)';
PRINT 'ZBW1C should be: ZBW ZDC ZNY ZOB CZYZ CZUL CZQM';
PRINT 'ZBWEC should be: ZBW ZNY ZDC ZJX ZMA (via EASTCOAST tier group)';
PRINT 'ZSE1C should be: ZSE ZOA ZLC CZVR CZEG';
PRINT 'ZJXGULF should be: ZJX ZMA ZHU (via GULF tier group)';
PRINT 'ZLC1C should be: ZLC ZDV ZLA ZMP ZOA ZSE CZEG CZWG';
PRINT 'ALL should be: 20 CONUS ARTCCs (no KZAN)';
PRINT 'ALL+CANADA should be: 20 CONUS ARTCCs + 6 Canadian FIRs (CZVR, CZEG, CZWG, CZYZ, CZUL, CZQM)';
