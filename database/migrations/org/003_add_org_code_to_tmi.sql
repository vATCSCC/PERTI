-- ============================================================================
-- Migration: 003_add_org_code_to_tmi.sql
-- Database:  VATSIM_TMI (Azure SQL)
-- Purpose:   Add org_code column to TMI tables for multi-org support.
--            Existing rows default to 'vatcscc'.
-- ============================================================================

USE VATSIM_TMI;

-- ----------------------------------------------------------------------------
-- TMI programs (GDP, GS, AFP, reroutes)
-- ----------------------------------------------------------------------------
ALTER TABLE tmi_programs
    ADD org_code NVARCHAR(16) NOT NULL DEFAULT 'vatcscc';

-- ----------------------------------------------------------------------------
-- TMI advisories
-- ----------------------------------------------------------------------------
ALTER TABLE tmi_advisories
    ADD org_code NVARCHAR(16) NOT NULL DEFAULT 'vatcscc';

-- ----------------------------------------------------------------------------
-- TMI log entries (MIT, AFP, restrictions)
-- ----------------------------------------------------------------------------
ALTER TABLE tmi_entries
    ADD org_code NVARCHAR(16) NOT NULL DEFAULT 'vatcscc';

-- ----------------------------------------------------------------------------
-- Reroute definitions
-- ----------------------------------------------------------------------------
ALTER TABLE tmi_reroutes
    ADD org_code NVARCHAR(16) NOT NULL DEFAULT 'vatcscc';

-- ----------------------------------------------------------------------------
-- Public route visualizations
-- ----------------------------------------------------------------------------
ALTER TABLE tmi_public_routes
    ADD org_code NVARCHAR(16) NOT NULL DEFAULT 'vatcscc';

-- ----------------------------------------------------------------------------
-- Airport configuration snapshots
-- ----------------------------------------------------------------------------
ALTER TABLE tmi_airport_configs
    ADD org_code NVARCHAR(16) NOT NULL DEFAULT 'vatcscc';

-- ----------------------------------------------------------------------------
-- Delay reports
-- ----------------------------------------------------------------------------
ALTER TABLE tmi_delay_entries
    ADD org_code NVARCHAR(16) NOT NULL DEFAULT 'vatcscc';

-- ----------------------------------------------------------------------------
-- Coordination proposals
-- ----------------------------------------------------------------------------
ALTER TABLE tmi_proposals
    ADD org_code NVARCHAR(16) NOT NULL DEFAULT 'vatcscc';

-- ----------------------------------------------------------------------------
-- Nonclustered indexes for org-scoped queries on high-traffic tables
-- ----------------------------------------------------------------------------
CREATE NONCLUSTERED INDEX IX_tmi_programs_org_code
    ON tmi_programs (org_code);

CREATE NONCLUSTERED INDEX IX_tmi_advisories_org_code
    ON tmi_advisories (org_code);

CREATE NONCLUSTERED INDEX IX_tmi_entries_org_code
    ON tmi_entries (org_code);

PRINT 'org_code columns added to TMI tables';
