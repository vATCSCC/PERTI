-- Migration: Add Canadian fixes for YVR TMI analysis
-- These fixes (EGRET, NADPI, NOVAR) are used for CYVR arrival routes
-- Run against VATSIM_ADL database
--
-- Usage: sqlcmd -S vatsim.database.windows.net -d VATSIM_ADL -U adl_api_user -P 'CAMRN@11000' -i add_canadian_fixes.sql

SET NOCOUNT ON;
PRINT 'Adding Canadian fixes for YVR TMI analysis...';

-- Check if fixes already exist
IF NOT EXISTS (SELECT 1 FROM dbo.nav_fixes WHERE fix_name = 'EGRET')
BEGIN
    INSERT INTO dbo.nav_fixes (fix_name, fix_type, lat, lon, source)
    VALUES (N'EGRET', N'WAYPOINT', 48.71162777, -122.5094472, N'points.csv');
    PRINT 'Added EGRET';
END
ELSE
    PRINT 'EGRET already exists';

IF NOT EXISTS (SELECT 1 FROM dbo.nav_fixes WHERE fix_name = 'NADPI')
BEGIN
    INSERT INTO dbo.nav_fixes (fix_name, fix_type, lat, lon, source)
    VALUES (N'NADPI', N'WAYPOINT', 51.714444, -117.34, N'points.csv');
    PRINT 'Added NADPI';
END
ELSE
    PRINT 'NADPI already exists';

IF NOT EXISTS (SELECT 1 FROM dbo.nav_fixes WHERE fix_name = 'NOVAR')
BEGIN
    INSERT INTO dbo.nav_fixes (fix_name, fix_type, lat, lon, source)
    VALUES (N'NOVAR', N'WAYPOINT', 50.6725, -116.390278, N'points.csv');
    PRINT 'Added NOVAR';
END
ELSE
    PRINT 'NOVAR already exists';

PRINT '';
PRINT 'Verification:';
SELECT fix_name, fix_type, lat, lon
FROM dbo.nav_fixes
WHERE fix_name IN ('EGRET', 'NADPI', 'NOVAR');

PRINT 'Done.';
GO
