-- Migration 013: Add body_name and runway_group columns to nav_procedures
-- Supports the unified procedures JSON format with structured runway associations.
--
-- body_name:    The NASR body name (e.g., "FANZI-ACCRA", "AALLE-KIPPR")
-- runway_group: JSON array of {airport, runways} objects derived from ORIG_GROUP/DEST_GROUP
--               e.g., [{"airport":"TJSJ","runways":["08","10","26","28"]}]
--               Uses proper ICAO codes (not naive K-prefix): SJU->TJSJ, HNL->PHNL, etc.
--
-- Apply to: VATSIM_REF, VATSIM_ADL
-- Date: 2026-04-05

-- Add body_name column
IF NOT EXISTS (SELECT 1 FROM sys.columns
               WHERE object_id = OBJECT_ID('dbo.nav_procedures')
                 AND name = 'body_name')
    ALTER TABLE dbo.nav_procedures ADD body_name NVARCHAR(64) NULL;
GO

-- Add runway_group column (JSON array stored as NVARCHAR(MAX))
IF NOT EXISTS (SELECT 1 FROM sys.columns
               WHERE object_id = OBJECT_ID('dbo.nav_procedures')
                 AND name = 'runway_group')
    ALTER TABLE dbo.nav_procedures ADD runway_group NVARCHAR(MAX) NULL;
GO

-- Backfill NULL is_superseded to 0 so filtered index covers all active records.
-- (Aligns with PostGIS filter which uses IS NULL OR = false.)
UPDATE dbo.nav_procedures SET is_superseded = 0 WHERE is_superseded IS NULL;
GO

-- Filtered index for runway-aware procedure lookups (expand_route, ADL daemon).
-- INCLUDE must come before WHERE per SQL Server syntax.
-- body_name included to support per-variant changelog tracking.
IF NOT EXISTS (SELECT 1 FROM sys.indexes
               WHERE name = 'IX_proc_code_runway_group'
                 AND object_id = OBJECT_ID('dbo.nav_procedures'))
    CREATE NONCLUSTERED INDEX IX_proc_code_runway_group
        ON dbo.nav_procedures (computer_code)
        INCLUDE (runway_group, body_name, full_route, procedure_type, airport_icao)
        WHERE is_active = 1 AND is_superseded = 0;
GO
