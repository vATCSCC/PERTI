-- ============================================================================
-- Migration 012: Transition Type Preference for sp_ParseRoute
-- ============================================================================
--
-- Fixes DP/STAR zig-zagging in the legacy parse daemon (sp_ParseRoute v4.3)
-- by porting the transition_type preference logic from PostGIS migration 025.
--
-- Problem: sp_ParseRoute used ORDER BY LEN(full_route) which preferentially
-- selected short runway transitions whose approach-specific waypoints cause
-- zig-zagging. The GIS daemon (PostGIS expand_route) was fixed in migration
-- 025 but the legacy daemon remained broken.
--
-- Changes:
--   1. Defensive: ensure transition_type column exists on nav_procedures
--   2. Backfill: populate NULL transition_type values from computer_code
--   3. New function: dbo.fn_ProcedureCommonBody — T-SQL port of PostGIS
--      procedure_common_body() (extracts shared waypoint body from runway
--      transitions; STAR=prefix, DP=suffix)
--   4. Updated sp_ParseRoute v4.3 → v4.4:
--      - All 6 procedure lookup strategies now prefer fix > base > runway
--        transitions via transition_type ORDER BY
--      - Common body fallback when runway transition selected without
--        runway context (prevents zig-zagging)
--
-- Deploy steps:
--   1. Run this migration on VATSIM_ADL (schema + function)
--   2. Run adl/procedures/sp_ParseRoute.sql on VATSIM_ADL (updated SP v4.4)
--
-- Database: VATSIM_ADL
-- Date: 2026-04-23
-- Depends on: AIRAC 2604 (transition_type populated by airac_update.py)
-- ============================================================================

SET NOCOUNT ON;
PRINT '=== Migration 012: Transition Type Preference for sp_ParseRoute ===';
PRINT '';

-- ============================================================================
-- Step 1: Defensive — ensure transition_type column exists
-- ============================================================================
PRINT 'Step 1: Checking transition_type column...';

IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = 'nav_procedures'
      AND COLUMN_NAME = 'transition_type'
)
BEGIN
    ALTER TABLE dbo.nav_procedures ADD transition_type NVARCHAR(10) NULL;
    PRINT '  Added transition_type column to nav_procedures';
END
ELSE
    PRINT '  transition_type column already exists';
GO

-- ============================================================================
-- Step 2: Backfill NULL transition_type values
-- ============================================================================
-- Classification logic (same as PostGIS migration 021):
--   'runway' — computer_code starts with 'RW' (e.g., RW02.ULPO1A, RW29B.MEZC1B)
--   'fix'    — computer_code does NOT start with 'RW' and has a dot
--              (e.g., JJEDI.JJEDI4, CAMRN4.BOWLL)
--   NULL     — no dot in computer_code (base/default transition)
-- ============================================================================
PRINT '';
PRINT 'Step 2: Backfilling NULL transition_type values...';

DECLARE @backfill_count INT;

-- Runway transitions: computer_code starts with 'RW' before the dot
UPDATE dbo.nav_procedures
SET transition_type = 'runway'
WHERE transition_type IS NULL
  AND computer_code LIKE 'RW[0-9]%.%';

SET @backfill_count = @@ROWCOUNT;
PRINT '  Classified ' + CAST(@backfill_count AS VARCHAR) + ' runway transitions (RW prefix)';

-- Also handle STAR.RW format (e.g., MEZC1B.RW29B)
UPDATE dbo.nav_procedures
SET transition_type = 'runway'
WHERE transition_type IS NULL
  AND computer_code LIKE '%.RW[0-9]%';

SET @backfill_count = @@ROWCOUNT;
PRINT '  Classified ' + CAST(@backfill_count AS VARCHAR) + ' runway transitions (.RW suffix)';

-- Fix transitions: have a dot but don't start/end with RW
UPDATE dbo.nav_procedures
SET transition_type = 'fix'
WHERE transition_type IS NULL
  AND CHARINDEX('.', computer_code) > 0
  AND computer_code NOT LIKE 'RW[0-9]%.%'
  AND computer_code NOT LIKE '%.RW[0-9]%';

SET @backfill_count = @@ROWCOUNT;
PRINT '  Classified ' + CAST(@backfill_count AS VARCHAR) + ' fix transitions';

-- Summary
PRINT '';
PRINT 'Transition type distribution:';
SELECT transition_type, COUNT(*) AS cnt
FROM dbo.nav_procedures
GROUP BY transition_type
ORDER BY cnt DESC;
GO

-- ============================================================================
-- Step 3: Create fn_ProcedureCommonBody
-- ============================================================================
PRINT '';
PRINT 'Step 3: Creating fn_ProcedureCommonBody...';
GO

CREATE OR ALTER FUNCTION dbo.fn_ProcedureCommonBody(
    @proc_name NVARCHAR(32),
    @proc_type VARCHAR(10),
    @trunc_name NVARCHAR(32) = NULL
)
RETURNS NVARCHAR(MAX)
AS
BEGIN
    DECLARE @result NVARCHAR(MAX);
    DECLARE @route_count INT;

    -- Collect all runway transition full_routes for this procedure.
    -- Use procedure_name column (canonical name) instead of parsing computer_code,
    -- because 93.7% of runway transitions have version suffixes in computer_code
    -- (e.g., RW02.ULPO1A) that don't match the base procedure_name (ULPO).
    DECLARE @routes TABLE (route_id INT IDENTITY(1,1), full_route NVARCHAR(MAX));

    IF @proc_type = 'STAR'
    BEGIN
        INSERT INTO @routes (full_route)
        SELECT np.full_route
        FROM dbo.nav_procedures np
        WHERE (np.procedure_name = UPPER(@proc_name)
               OR (@trunc_name IS NOT NULL AND np.procedure_name = UPPER(@trunc_name)))
          AND np.procedure_type = 'STAR'
          AND np.transition_type = 'runway'
          AND np.source IN ('NASR', 'cifp_base', 'synthetic_base', 'CIFP')
          AND np.is_active = 1
          AND (np.is_superseded IS NULL OR np.is_superseded = 0)
          AND np.full_route IS NOT NULL
          AND LEN(np.full_route) > 0
        ORDER BY np.computer_code;
    END
    ELSE  -- DP or SID
    BEGIN
        INSERT INTO @routes (full_route)
        SELECT np.full_route
        FROM dbo.nav_procedures np
        WHERE (np.procedure_name = UPPER(@proc_name)
               OR (@trunc_name IS NOT NULL AND np.procedure_name = UPPER(@trunc_name)))
          AND np.procedure_type IN ('DP', 'SID')
          AND np.transition_type = 'runway'
          AND np.source IN ('NASR', 'cifp_base', 'synthetic_base', 'CIFP')
          AND np.is_active = 1
          AND (np.is_superseded IS NULL OR np.is_superseded = 0)
          AND np.full_route IS NOT NULL
          AND LEN(np.full_route) > 0
        ORDER BY np.computer_code;
    END

    SET @route_count = (SELECT COUNT(*) FROM @routes);

    -- Single transition: return as-is (no alternative exists)
    IF @route_count = 1
    BEGIN
        SELECT @result = full_route FROM @routes WHERE route_id = 1;
        RETURN @result;
    END

    -- Zero transitions: nothing to compute
    IF @route_count < 2
        RETURN NULL;

    -- Split all routes into words with sequential position.
    -- STRING_SPLIT ordinal may have gaps when consecutive spaces produce empty values,
    -- so re-number with ROW_NUMBER to get contiguous 1-based positions.
    DECLARE @words TABLE (route_id INT, seq INT, word NVARCHAR(100));

    INSERT INTO @words (route_id, seq, word)
    SELECT r.route_id,
           ROW_NUMBER() OVER (PARTITION BY r.route_id ORDER BY s.ordinal),
           LTRIM(RTRIM(s.value))
    FROM @routes r
    CROSS APPLY STRING_SPLIT(LTRIM(RTRIM(r.full_route)), ' ', 1) s
    WHERE LEN(LTRIM(RTRIM(s.value))) > 0;

    -- Get word counts per route and find minimum length
    DECLARE @route_lengths TABLE (route_id INT, word_count INT);
    INSERT INTO @route_lengths (route_id, word_count)
    SELECT route_id, MAX(seq) FROM @words GROUP BY route_id;

    DECLARE @min_len INT;
    SELECT @min_len = MIN(word_count) FROM @route_lengths;

    IF @min_len IS NULL OR @min_len = 0
        RETURN NULL;

    DECLARE @common_len INT = 0;
    DECLARE @i INT = 1;
    DECLARE @ref_word NVARCHAR(100);

    IF @proc_type = 'STAR'
    BEGIN
        -- STAR: Longest common PREFIX
        WHILE @i <= @min_len
        BEGIN
            SELECT @ref_word = word FROM @words WHERE route_id = 1 AND seq = @i;

            IF EXISTS (
                SELECT 1 FROM @words
                WHERE seq = @i AND route_id > 1 AND word != @ref_word
            )
                BREAK;

            SET @common_len = @i;
            SET @i = @i + 1;
        END

        IF @common_len = 0
            RETURN NULL;

        SELECT @result = STRING_AGG(word, ' ') WITHIN GROUP (ORDER BY seq)
        FROM @words
        WHERE route_id = 1 AND seq <= @common_len;
    END
    ELSE
    BEGIN
        -- DP/SID: Longest common SUFFIX
        DECLARE @first_len INT;
        SELECT @first_len = word_count FROM @route_lengths WHERE route_id = 1;

        WHILE @i <= @min_len
        BEGIN
            SELECT @ref_word = word
            FROM @words
            WHERE route_id = 1 AND seq = @first_len - @i + 1;

            IF EXISTS (
                SELECT 1
                FROM @words w
                JOIN @route_lengths rl ON rl.route_id = w.route_id
                WHERE w.route_id > 1
                  AND w.seq = rl.word_count - @i + 1
                  AND w.word != @ref_word
            )
                BREAK;

            SET @common_len = @i;
            SET @i = @i + 1;
        END

        IF @common_len = 0
            RETURN NULL;

        SELECT @result = STRING_AGG(word, ' ') WITHIN GROUP (ORDER BY seq)
        FROM @words
        WHERE route_id = 1 AND seq >= (@first_len - @common_len + 1);
    END

    RETURN @result;
END;
GO

PRINT '  fn_ProcedureCommonBody created';
PRINT '';

-- ============================================================================
-- Step 4: Verify
-- ============================================================================
PRINT 'Step 4: Verification queries...';
PRINT '';

-- Test common body extraction
PRINT 'Testing fn_ProcedureCommonBody:';

-- Find a STAR with multiple runway transitions for testing
DECLARE @test_star NVARCHAR(32);
SELECT TOP 1 @test_star = procedure_name
FROM dbo.nav_procedures
WHERE transition_type = 'runway' AND procedure_type = 'STAR'
  AND is_active = 1 AND (is_superseded IS NULL OR is_superseded = 0)
GROUP BY procedure_name
HAVING COUNT(*) >= 2;

IF @test_star IS NOT NULL
BEGIN
    DECLARE @star_result NVARCHAR(MAX) = dbo.fn_ProcedureCommonBody(@test_star, 'STAR', NULL);
    PRINT '  STAR ' + @test_star + ' common body: ' + ISNULL(@star_result, 'NULL');
END
ELSE
    PRINT '  No multi-runway STAR found for testing';

-- Find a DP with multiple runway transitions for testing
DECLARE @test_dp NVARCHAR(32);
SELECT TOP 1 @test_dp = procedure_name
FROM dbo.nav_procedures
WHERE transition_type = 'runway' AND procedure_type = 'DP'
  AND is_active = 1 AND (is_superseded IS NULL OR is_superseded = 0)
GROUP BY procedure_name
HAVING COUNT(*) >= 2;

IF @test_dp IS NOT NULL
BEGIN
    DECLARE @dp_result NVARCHAR(MAX) = dbo.fn_ProcedureCommonBody(@test_dp, 'DP', NULL);
    PRINT '  DP ' + @test_dp + ' common body: ' + ISNULL(@dp_result, 'NULL');
END
ELSE
    PRINT '  No multi-runway DP found for testing';
GO

-- ============================================================================
-- Done
-- ============================================================================
PRINT '';
PRINT '=== Migration 012 complete ===';
PRINT '';
PRINT 'NEXT STEP: Run adl/procedures/sp_ParseRoute.sql on VATSIM_ADL';
PRINT '           to deploy sp_ParseRoute v4.4 with transition_type';
PRINT '           ORDER BY + common body fallback.';
GO
