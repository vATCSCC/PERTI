-- =====================================================
-- Analyze Runway Data from MySQL Source
-- Run this to see what patterns exist in the runway columns
-- =====================================================

SET NOCOUNT ON;

PRINT '=== Analyzing MySQL config_data runway patterns ===';
PRINT '';

-- First, let's look at the raw data from the source
-- Query the original MySQL data patterns by looking at what failed to import

PRINT '=== 1. Current runway_id values in ADL (what imported successfully) ===';
PRINT '';

SELECT
    runway_id,
    runway_use,
    COUNT(*) as cnt
FROM dbo.airport_config_runway
GROUP BY runway_id, runway_use
ORDER BY runway_id;

PRINT '';
PRINT '=== 2. Runway IDs with unusual characters ===';
PRINT '';

SELECT DISTINCT
    runway_id,
    LEN(runway_id) as len,
    CASE
        WHEN runway_id LIKE '[0-9][0-9][LRC]' THEN 'Standard (09L)'
        WHEN runway_id LIKE '[0-9][0-9]' THEN 'Standard (09)'
        WHEN runway_id LIKE '[0-9][LRC]' THEN 'Short (9L)'
        WHEN runway_id LIKE '[0-9]' THEN 'Short (9)'
        WHEN runway_id LIKE '%[_]%' THEN 'Contains underscore'
        WHEN runway_id LIKE '%/%' THEN 'Contains slash'
        WHEN runway_id LIKE '%-%' THEN 'Contains dash'
        WHEN runway_id LIKE '%[A-Z][A-Z]%' AND runway_id NOT LIKE '%[0-9]%' THEN 'Text only (approach type?)'
        ELSE 'Other pattern'
    END as pattern
FROM dbo.airport_config_runway
WHERE runway_id NOT LIKE '[0-9][0-9]'
  AND runway_id NOT LIKE '[0-9][0-9][LRC]'
  AND runway_id NOT LIKE '[0-9]'
  AND runway_id NOT LIKE '[0-9][LRC]'
ORDER BY pattern, runway_id;

PRINT '';
PRINT '=== 3. Airports with unusual runway entries ===';
PRINT '';

SELECT
    c.airport_icao,
    c.config_name,
    r.runway_id,
    r.runway_use
FROM dbo.airport_config c
JOIN dbo.airport_config_runway r ON c.config_id = r.config_id
WHERE r.runway_id NOT LIKE '[0-9][0-9]'
  AND r.runway_id NOT LIKE '[0-9][0-9][LRC]'
  AND r.runway_id NOT LIKE '[0-9]'
  AND r.runway_id NOT LIKE '[0-9][LRC]'
ORDER BY c.airport_icao, r.runway_id;

PRINT '';
PRINT '=== 4. Pattern counts ===';
PRINT '';

SELECT
    CASE
        WHEN runway_id LIKE '[0-9][0-9][LRC]' THEN 'Standard 3-char (09L)'
        WHEN runway_id LIKE '[0-9][0-9]' THEN 'Standard 2-char (09)'
        WHEN runway_id LIKE '[0-9][LRC]' THEN 'Short 2-char (9L)'
        WHEN runway_id LIKE '[0-9]' THEN 'Short 1-char (9)'
        WHEN runway_id LIKE '%[_]%' THEN 'Contains underscore'
        ELSE 'Other'
    END as pattern,
    COUNT(*) as cnt
FROM dbo.airport_config_runway
GROUP BY
    CASE
        WHEN runway_id LIKE '[0-9][0-9][LRC]' THEN 'Standard 3-char (09L)'
        WHEN runway_id LIKE '[0-9][0-9]' THEN 'Standard 2-char (09)'
        WHEN runway_id LIKE '[0-9][LRC]' THEN 'Short 2-char (9L)'
        WHEN runway_id LIKE '[0-9]' THEN 'Short 1-char (9)'
        WHEN runway_id LIKE '%[_]%' THEN 'Contains underscore'
        ELSE 'Other'
    END
ORDER BY cnt DESC;

PRINT '';
PRINT '=== Analysis complete ===';
GO
