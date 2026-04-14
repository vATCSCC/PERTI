-- ============================================================================
-- Migration: Widen narrow columns across splits tables
--
-- Root cause: splits_presets.artcc was CHAR(3), too narrow for 4-char
-- international FIR codes (CZUL, EGTT, etc.), causing 500 errors on save.
-- Also fixes splits_preset_positions.color (CHAR(7) -> NVARCHAR(20))
-- and splits_positions.frequency/controller_oi for consistency.
--
-- Run on: VATSIM_ADL (requires DDL permissions - use jpeterson)
-- Applied to production: 2026-04-14
-- ============================================================================

-- 1. Widen splits_presets.artcc from CHAR(3) to NVARCHAR(4)
IF EXISTS (
    SELECT 1 FROM sys.columns c
    JOIN sys.types t ON c.system_type_id = t.system_type_id AND c.user_type_id = t.user_type_id
    WHERE c.object_id = OBJECT_ID('dbo.splits_presets')
    AND c.name = 'artcc' AND t.name = 'char' AND c.max_length = 3
)
BEGIN
    DROP INDEX IF EXISTS IX_splits_presets_artcc ON dbo.splits_presets;
    ALTER TABLE dbo.splits_presets ALTER COLUMN artcc NVARCHAR(4) NOT NULL;
    CREATE INDEX IX_splits_presets_artcc ON dbo.splits_presets(artcc);
    PRINT 'Widened splits_presets.artcc from CHAR(3) to NVARCHAR(4)';
END
ELSE
    PRINT 'splits_presets.artcc already widened';
GO

-- 2. Widen splits_preset_positions.color from CHAR(7) to NVARCHAR(20)
IF EXISTS (
    SELECT 1 FROM sys.columns c
    JOIN sys.types t ON c.system_type_id = t.system_type_id AND c.user_type_id = t.user_type_id
    WHERE c.object_id = OBJECT_ID('dbo.splits_preset_positions')
    AND c.name = 'color' AND t.name = 'char' AND c.max_length = 7
)
BEGIN
    -- Drop DEFAULT constraint first
    DECLARE @df_name NVARCHAR(200);
    SELECT @df_name = d.name
    FROM sys.default_constraints d
    JOIN sys.columns c ON d.parent_object_id = c.object_id AND d.parent_column_id = c.column_id
    WHERE d.parent_object_id = OBJECT_ID('dbo.splits_preset_positions') AND c.name = 'color';

    IF @df_name IS NOT NULL
        EXEC('ALTER TABLE dbo.splits_preset_positions DROP CONSTRAINT [' + @df_name + ']');

    ALTER TABLE dbo.splits_preset_positions ALTER COLUMN color NVARCHAR(20) NOT NULL;
    ALTER TABLE dbo.splits_preset_positions ADD CONSTRAINT DF_preset_positions_color DEFAULT '#4dabf7' FOR color;
    PRINT 'Widened splits_preset_positions.color from CHAR(7) to NVARCHAR(20)';
END
ELSE
    PRINT 'splits_preset_positions.color already widened';
GO

-- 3. Widen splits_positions.frequency from VARCHAR(10) to NVARCHAR(20)
IF EXISTS (
    SELECT 1 FROM sys.columns c
    JOIN sys.types t ON c.system_type_id = t.system_type_id AND c.user_type_id = t.user_type_id
    WHERE c.object_id = OBJECT_ID('dbo.splits_positions')
    AND c.name = 'frequency' AND t.name = 'varchar' AND c.max_length = 10
)
BEGIN
    ALTER TABLE dbo.splits_positions ALTER COLUMN frequency NVARCHAR(20) NULL;
    PRINT 'Widened splits_positions.frequency from VARCHAR(10) to NVARCHAR(20)';
END
ELSE
    PRINT 'splits_positions.frequency already widened';
GO

-- 4. Widen splits_positions.controller_oi from VARCHAR(2) to VARCHAR(4)
IF EXISTS (
    SELECT 1 FROM sys.columns c
    JOIN sys.types t ON c.system_type_id = t.system_type_id AND c.user_type_id = t.user_type_id
    WHERE c.object_id = OBJECT_ID('dbo.splits_positions')
    AND c.name = 'controller_oi' AND t.name = 'varchar' AND c.max_length = 2
)
BEGIN
    ALTER TABLE dbo.splits_positions ALTER COLUMN controller_oi VARCHAR(4) NULL;
    PRINT 'Widened splits_positions.controller_oi from VARCHAR(2) to VARCHAR(4)';
END
ELSE
    PRINT 'splits_positions.controller_oi already widened';
GO
