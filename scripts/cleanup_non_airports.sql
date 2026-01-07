-- =====================================================
-- Cleanup Non-Airport Entries
-- Removes placeholder/test entries like _A08, _BW1, etc.
-- =====================================================

SET NOCOUNT ON;

PRINT '=== Removing non-airport entries ===';
PRINT '';

-- Show what will be deleted
SELECT 'Entries to remove:' AS info;
SELECT c.config_id, c.airport_faa, c.airport_icao, c.config_name
FROM dbo.airport_config c
WHERE c.airport_faa LIKE '[_]%'
   OR c.airport_icao LIKE '[_]%'
   OR c.airport_faa LIKE 'FCA%'
   OR c.airport_icao LIKE 'FCA%';

-- Delete runway entries (child table)
DELETE r
FROM dbo.airport_config_runway r
JOIN dbo.airport_config c ON r.config_id = c.config_id
WHERE c.airport_faa LIKE '[_]%'
   OR c.airport_icao LIKE '[_]%'
   OR c.airport_faa LIKE 'FCA%'
   OR c.airport_icao LIKE 'FCA%';

PRINT 'Deleted runway entries: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

-- Delete rate entries (child table)
DELETE rt
FROM dbo.airport_config_rate rt
JOIN dbo.airport_config c ON rt.config_id = c.config_id
WHERE c.airport_faa LIKE '[_]%'
   OR c.airport_icao LIKE '[_]%'
   OR c.airport_faa LIKE 'FCA%'
   OR c.airport_icao LIKE 'FCA%';

PRINT 'Deleted rate entries: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

-- Delete config entries (parent table)
DELETE FROM dbo.airport_config
WHERE airport_faa LIKE '[_]%'
   OR airport_icao LIKE '[_]%'
   OR airport_faa LIKE 'FCA%'
   OR airport_icao LIKE 'FCA%';

PRINT 'Deleted config entries: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows';

PRINT '';
PRINT '=== Summary ===';
SELECT 'Remaining configs: ' + CAST(COUNT(*) AS VARCHAR) FROM dbo.airport_config;
SELECT 'Remaining runways: ' + CAST(COUNT(*) AS VARCHAR) FROM dbo.airport_config_runway;

PRINT '';
PRINT 'Cleanup complete.';
GO
