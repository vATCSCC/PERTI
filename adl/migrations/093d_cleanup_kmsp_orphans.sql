-- =====================================================
-- Cleanup KMSP Orphan Entries
-- Migration: 093d
-- Description: Delete orphan runway entries from bad parsing
-- =====================================================

SET NOCOUNT ON;

PRINT '=== Migration 093d: Cleanup KMSP Orphans ===';
PRINT '';

-- Delete orphan entries that couldn't be updated due to unique constraint
-- These are fragments from badly parsed runway IDs

DELETE FROM dbo.airport_config_runway
WHERE runway_id IN ('A)', 'R')
  AND config_id IN (8162, 8165, 8170);

PRINT 'Deleted KMSP orphan entries: ' + CAST(@@ROWCOUNT AS VARCHAR);

-- Verify KMSP configs now look correct
PRINT '';
PRINT '=== KMSP configs after cleanup ===';

SELECT
    c.config_id,
    c.config_name,
    STRING_AGG(r.runway_id + ' (' + r.runway_use + ISNULL(', ' + r.notes, '') + ')', ', ')
        WITHIN GROUP (ORDER BY r.runway_use, r.runway_id) AS runways
FROM dbo.airport_config c
JOIN dbo.airport_config_runway r ON c.config_id = r.config_id
WHERE c.airport_icao = 'KMSP'
  AND c.config_id IN (8162, 8165, 8170)
GROUP BY c.config_id, c.config_name
ORDER BY c.config_id;

PRINT '';
PRINT 'Migration 093d completed.';
GO
