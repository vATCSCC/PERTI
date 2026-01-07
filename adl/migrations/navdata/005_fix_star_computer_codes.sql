-- ============================================================================
-- ADL Migration 051: Fix STAR computer_codes to include transition
--
-- STAR procedures currently have computer_code like "HOBTT.HOBTT3" for all
-- transitions, but the transition fix is only in full_route (e.g., "SHYRE ...").
-- This migration updates computer_code to include the transition fix,
-- matching the format used by DPs (e.g., "SHYRE.HOBTT3").
--
-- Run Order: 51
-- Depends on: nav_procedures table
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== ADL Migration 051: Fix STAR computer_codes ==='
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- ============================================================================
-- 1. Preview affected records
-- ============================================================================

PRINT '';
PRINT 'Previewing STAR records that need updating...';
PRINT '(where computer_code transition does not match full_route first fix)';

SELECT TOP 20
    computer_code AS old_code,
    LEFT(full_route, CHARINDEX(' ', full_route + ' ') - 1) + '.' +
        SUBSTRING(computer_code, CHARINDEX('.', computer_code) + 1, 100) AS new_code,
    LEFT(full_route, 60) AS route_preview
FROM dbo.nav_procedures
WHERE procedure_type = 'STAR'
  AND CHARINDEX('.', computer_code) > 0
  AND CHARINDEX(' ', full_route + ' ') > 1
  AND LEFT(computer_code, CHARINDEX('.', computer_code) - 1) !=
      LEFT(full_route, CHARINDEX(' ', full_route + ' ') - 1);
GO

-- ============================================================================
-- 2. Update STAR computer_codes to include transition from full_route
-- ============================================================================

PRINT '';
PRINT 'Updating STAR computer_codes...';

DECLARE @updated INT;

UPDATE dbo.nav_procedures
SET computer_code = LEFT(full_route, CHARINDEX(' ', full_route + ' ') - 1) + '.' +
                    SUBSTRING(computer_code, CHARINDEX('.', computer_code) + 1, 100)
WHERE procedure_type = 'STAR'
  AND CHARINDEX('.', computer_code) > 0
  AND CHARINDEX(' ', full_route + ' ') > 1
  AND LEFT(computer_code, CHARINDEX('.', computer_code) - 1) !=
      LEFT(full_route, CHARINDEX(' ', full_route + ' ') - 1);

SET @updated = @@ROWCOUNT;
PRINT 'Updated ' + CAST(@updated AS VARCHAR) + ' STAR records';
GO

-- ============================================================================
-- 3. Verify the fix for HOBTT3
-- ============================================================================

PRINT '';
PRINT 'Verifying HOBTT3 transitions after update:';

SELECT computer_code, LEFT(full_route, 50) AS route_start
FROM dbo.nav_procedures
WHERE computer_code LIKE '%.HOBTT3' AND procedure_type = 'STAR'
ORDER BY computer_code;
GO

PRINT '';
PRINT '=== ADL Migration 051 Complete ===';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO
