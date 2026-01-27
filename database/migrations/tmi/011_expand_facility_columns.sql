-- =============================================================================
-- TMI Schema Update: Expand facility columns
-- Server: vatsim.database.windows.net
-- Database: VATSIM_TMI
-- Version: 2.1
-- Date: 2026-01-28
-- =============================================================================
--
-- Issue: The providing_facility and requesting_facility columns are NVARCHAR(8)
-- but users may enter multiple comma-separated facility codes (e.g., "CZY,CZU,ZOB,ZID,ZNY")
--
-- Fix: Expand both columns to NVARCHAR(64) to accommodate multiple facilities
--
-- Note: Must drop and recreate indexes that depend on these columns
--
-- =============================================================================

-- Step 1: Drop the dependent indexes
DROP INDEX IF EXISTS IX_entries_facility_req ON dbo.tmi_entries;
GO

DROP INDEX IF EXISTS IX_entries_facility_prov ON dbo.tmi_entries;
GO

PRINT 'Dropped facility indexes';
GO

-- Step 2: Expand requesting_facility column
ALTER TABLE dbo.tmi_entries ALTER COLUMN requesting_facility NVARCHAR(64) NULL;
GO

-- Step 3: Expand providing_facility column
ALTER TABLE dbo.tmi_entries ALTER COLUMN providing_facility NVARCHAR(64) NULL;
GO

PRINT 'Expanded facility columns to NVARCHAR(64)';
GO

-- Step 4: Recreate the indexes
CREATE NONCLUSTERED INDEX IX_entries_facility_req ON dbo.tmi_entries (requesting_facility) WHERE requesting_facility IS NOT NULL;
GO

CREATE NONCLUSTERED INDEX IX_entries_facility_prov ON dbo.tmi_entries (providing_facility) WHERE providing_facility IS NOT NULL;
GO

PRINT 'Recreated facility indexes';
PRINT 'Migration complete!';
GO
