-- ============================================================================
-- Migration: 009_add_global_organization.sql
-- Database:  perti_site (MySQL 8)
-- Date:      2026-02-24
-- Purpose:   Add "Global" as a first-class organization scope.
--            Members of this org see ALL plans, bypass facility scope checks,
--            and can publish to ALL Discord org channels.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. Insert Global organization
-- ----------------------------------------------------------------------------
INSERT INTO organizations (org_code, org_name, org_name_long, display_name, region, vatsim_division, default_locale, is_active)
VALUES ('global', 'Global', 'Global Operations', 'Global', 'WW', 'VATSIM', 'en-US', 1);

-- ----------------------------------------------------------------------------
-- 2. Add Global membership for existing global-role users
--    is_privileged = 1 (full edit rights)
--    is_primary = 0 (keeps their existing primary org)
--    is_global = 1 (cross-org access flag)
-- ----------------------------------------------------------------------------

-- CID 1234727 (Jeremy Peterson)
INSERT IGNORE INTO user_orgs (cid, org_code, is_privileged, is_primary, is_global)
VALUES (1234727, 'global', 1, 0, 1);

-- CID 1233493 (Nathan Power)
INSERT IGNORE INTO user_orgs (cid, org_code, is_privileged, is_primary, is_global)
VALUES (1233493, 'global', 1, 0, 1);

-- ----------------------------------------------------------------------------
-- No org_facilities rows needed.
-- The 'global' org bypasses facility scope checks entirely via
-- is_org_global() in load/org_context.php. When load_org_facilities()
-- is called for 'global', it returns ALL facilities from ALL orgs.
-- ----------------------------------------------------------------------------
