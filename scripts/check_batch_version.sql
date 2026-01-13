-- Check actual deployed sp_CalculateETABatch for prefile support
-- The WHERE clause should include "OR c.phase = 'prefile'"

PRINT '=== CHECK BATCH PROCEDURE VERSION ===';
PRINT '';

-- Show procedure metadata
SELECT
    name AS proc_name,
    create_date,
    modify_date
FROM sys.procedures
WHERE name = 'sp_CalculateETABatch';

PRINT '';
PRINT '--- Searching for prefile clause in procedure definition ---';

-- Check if the WHERE clause includes prefile support
SELECT
    CASE
        WHEN CHARINDEX('c.phase = ''prefile''', definition) > 0
        THEN 'FOUND - Prefile support EXISTS'
        ELSE 'NOT FOUND - Prefile support MISSING!'
    END AS prefile_clause_status,
    CASE
        WHEN CHARINDEX('OR c.phase = ''prefile''', definition) > 0
        THEN 'Correct OR clause found'
        ELSE 'OR clause not found'
    END AS or_clause_status,
    LEN(definition) AS proc_length
FROM sys.sql_modules
WHERE object_id = OBJECT_ID('dbo.sp_CalculateETABatch');

PRINT '';
PRINT '--- Extracting WHERE clause from procedure ---';

-- Extract the WHERE clause section
DECLARE @def NVARCHAR(MAX);
SELECT @def = definition FROM sys.sql_modules WHERE object_id = OBJECT_ID('dbo.sp_CalculateETABatch');

-- Find the WHERE clause in Step 1
DECLARE @whereStart INT = CHARINDEX('WHERE c.is_active = 1', @def);
DECLARE @whereEnd INT;

IF @whereStart > 0
BEGIN
    SET @whereEnd = CHARINDEX(';', @def, @whereStart);
    IF @whereEnd = 0 SET @whereEnd = @whereStart + 200;

    PRINT 'WHERE clause found at position ' + CAST(@whereStart AS VARCHAR);
    PRINT SUBSTRING(@def, @whereStart, CASE WHEN @whereEnd - @whereStart > 200 THEN 200 ELSE @whereEnd - @whereStart END);
END
ELSE
BEGIN
    PRINT 'WHERE clause not found!';
END

PRINT '';
PRINT '--- Full procedure header (first 1500 chars) ---';
PRINT SUBSTRING(@def, 1, 1500);

PRINT '';
PRINT '=== END CHECK ===';
