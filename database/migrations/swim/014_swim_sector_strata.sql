-- =====================================================
-- Migration 014: Add current_sector_strata column
-- Description: Stores sector classification (low/high/superhigh)
-- for filtering flights by sector type rather than altitude
-- =====================================================

-- Add current_sector_strata column
IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = 'dbo'
    AND TABLE_NAME = 'swim_flights'
    AND COLUMN_NAME = 'current_sector_strata'
)
BEGIN
    ALTER TABLE dbo.swim_flights
    ADD current_sector_strata NVARCHAR(10) NULL;

    PRINT 'Added column: current_sector_strata';
END
ELSE
BEGIN
    PRINT 'Column current_sector_strata already exists';
END
GO

-- Create filtered index for strata queries
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = 'IX_swim_flights_strata'
    AND object_id = OBJECT_ID('dbo.swim_flights')
)
BEGIN
    CREATE INDEX IX_swim_flights_strata
    ON dbo.swim_flights (current_sector_strata)
    WHERE current_sector_strata IS NOT NULL AND is_active = 1;

    PRINT 'Created index: IX_swim_flights_strata';
END
ELSE
BEGIN
    PRINT 'Index IX_swim_flights_strata already exists';
END
GO

PRINT 'Migration 014 complete: current_sector_strata column added';
GO
