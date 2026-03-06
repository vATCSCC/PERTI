-- JATOC Incident Name Length Expansion
-- Migration 009: facility column from NVARCHAR(128) -> NVARCHAR(255)
-- "facility" is used as the free-text incident name field.

IF EXISTS (
    SELECT 1
    FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.jatoc_incidents')
      AND name = 'facility'
      AND system_type_id = 231 -- NVARCHAR
      AND max_length < 510      -- NVARCHAR(255) == 510 bytes
)
BEGIN
    ALTER TABLE dbo.jatoc_incidents ALTER COLUMN facility NVARCHAR(255) NULL;
END
GO

IF EXISTS (
    SELECT 1
    FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.jatoc_reports')
      AND name = 'facility'
      AND system_type_id = 231
      AND max_length < 510
)
BEGIN
    ALTER TABLE dbo.jatoc_reports ALTER COLUMN facility NVARCHAR(255) NULL;
END
GO

