-- Migration 004: Rename JATOC incident columns for clarity
-- status -> incident_type (describes WHAT happened: ATC_ZERO, ATC_ALERT, etc.)
-- incident_status -> lifecycle_status (describes WHERE in lifecycle: PENDING, ACTIVE, CLOSED)
--
-- This migration uses an additive approach for backward compatibility:
-- 1. Add new columns (incident_type, lifecycle_status)
-- 2. Copy data from old columns to new columns
-- 3. Old columns are retained during transition period
-- 4. A future migration will drop old columns once all code is updated

-- ============================================================================
-- Step 1: Add incident_type column to jatoc_incidents
-- ============================================================================
IF NOT EXISTS (
    SELECT * FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.jatoc_incidents') AND name = 'incident_type'
)
BEGIN
    ALTER TABLE dbo.jatoc_incidents ADD incident_type NVARCHAR(32) NULL;
    PRINT 'Added incident_type column to jatoc_incidents';
END
GO

-- ============================================================================
-- Step 2: Add lifecycle_status column to jatoc_incidents
-- ============================================================================
IF NOT EXISTS (
    SELECT * FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.jatoc_incidents') AND name = 'lifecycle_status'
)
BEGIN
    ALTER TABLE dbo.jatoc_incidents ADD lifecycle_status NVARCHAR(16) NULL;
    PRINT 'Added lifecycle_status column to jatoc_incidents';
END
GO

-- ============================================================================
-- Step 3: Copy data from old columns to new columns
-- ============================================================================
UPDATE dbo.jatoc_incidents
SET incident_type = status
WHERE incident_type IS NULL AND status IS NOT NULL;

UPDATE dbo.jatoc_incidents
SET lifecycle_status = incident_status
WHERE lifecycle_status IS NULL AND incident_status IS NOT NULL;

PRINT 'Copied data from old columns to new columns';
GO

-- ============================================================================
-- Step 4: Set defaults for any remaining NULL values
-- ============================================================================
UPDATE dbo.jatoc_incidents
SET incident_type = 'OTHER'
WHERE incident_type IS NULL;

UPDATE dbo.jatoc_incidents
SET lifecycle_status = 'ACTIVE'
WHERE lifecycle_status IS NULL;
GO

-- ============================================================================
-- Step 5: Add NOT NULL constraint to incident_type
-- ============================================================================
IF EXISTS (
    SELECT * FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.jatoc_incidents')
    AND name = 'incident_type'
    AND is_nullable = 1
)
BEGIN
    ALTER TABLE dbo.jatoc_incidents ALTER COLUMN incident_type NVARCHAR(32) NOT NULL;
    PRINT 'Applied NOT NULL constraint to incident_type';
END
GO

-- ============================================================================
-- Step 6: Add default constraint to lifecycle_status
-- ============================================================================
IF NOT EXISTS (
    SELECT * FROM sys.default_constraints
    WHERE parent_object_id = OBJECT_ID('dbo.jatoc_incidents')
    AND name = 'DF_jatoc_incidents_lifecycle_status'
)
BEGIN
    -- First ensure no NULLs
    UPDATE dbo.jatoc_incidents SET lifecycle_status = 'ACTIVE' WHERE lifecycle_status IS NULL;

    -- Then add default
    ALTER TABLE dbo.jatoc_incidents
    ADD CONSTRAINT DF_jatoc_incidents_lifecycle_status DEFAULT 'ACTIVE' FOR lifecycle_status;
    PRINT 'Added default constraint to lifecycle_status';
END
GO

-- ============================================================================
-- Step 7: Update jatoc_reports table similarly
-- ============================================================================
IF EXISTS (SELECT * FROM sys.tables WHERE name = 'jatoc_reports')
BEGIN
    -- Add incident_type column
    IF NOT EXISTS (
        SELECT * FROM sys.columns
        WHERE object_id = OBJECT_ID('dbo.jatoc_reports') AND name = 'incident_type'
    )
    BEGIN
        ALTER TABLE dbo.jatoc_reports ADD incident_type NVARCHAR(32) NULL;
        PRINT 'Added incident_type column to jatoc_reports';
    END

    -- Copy data
    UPDATE dbo.jatoc_reports
    SET incident_type = status
    WHERE incident_type IS NULL AND status IS NOT NULL;
END
GO

-- ============================================================================
-- Step 8: Create index on new columns
-- ============================================================================
IF NOT EXISTS (
    SELECT * FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.jatoc_incidents')
    AND name = 'IX_jatoc_incidents_incident_type'
)
BEGIN
    CREATE INDEX IX_jatoc_incidents_incident_type
    ON dbo.jatoc_incidents(incident_type);
    PRINT 'Created index on incident_type';
END
GO

IF NOT EXISTS (
    SELECT * FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.jatoc_incidents')
    AND name = 'IX_jatoc_incidents_lifecycle_status'
)
BEGIN
    CREATE INDEX IX_jatoc_incidents_lifecycle_status
    ON dbo.jatoc_incidents(lifecycle_status);
    PRINT 'Created index on lifecycle_status';
END
GO

PRINT 'Migration 004 complete: Added incident_type and lifecycle_status columns';
PRINT 'NOTE: Old columns (status, incident_status) are retained for backward compatibility';
GO
