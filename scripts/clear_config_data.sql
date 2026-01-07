-- =====================================================
-- Clear Config Data for Re-Import
-- Run this before re-importing from MySQL
-- =====================================================

SET NOCOUNT ON;

PRINT '=== Clearing airport config data for re-import ===';
PRINT '';

-- Delete in correct order (children first due to foreign keys)
DELETE FROM dbo.airport_config_rate;
PRINT 'Deleted rate entries: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

DELETE FROM dbo.airport_config_runway;
PRINT 'Deleted runway entries: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

DELETE FROM dbo.airport_config;
PRINT 'Deleted config entries: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

-- Reset identity seeds
DBCC CHECKIDENT ('dbo.airport_config', RESEED, 0);
DBCC CHECKIDENT ('dbo.airport_config_runway', RESEED, 0);
DBCC CHECKIDENT ('dbo.airport_config_rate', RESEED, 0);

PRINT '';
PRINT 'Tables cleared and identity seeds reset.';
PRINT 'Ready for re-import from MySQL.';
GO
