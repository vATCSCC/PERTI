-- ============================================================================
-- Migration: 010_ctp_organization.sql
-- Database:  perti_site (MySQL 8)
-- Purpose:   Add CTP (Cross the Pond) as a new organization
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. Allow NULL vatsim_division (CTP has no VATSIM division)
-- ----------------------------------------------------------------------------
ALTER TABLE organizations MODIFY COLUMN vatsim_division VARCHAR(8) NULL;

-- ----------------------------------------------------------------------------
-- 2. Insert CTP organization
--    - org_code:       ctp
--    - display_name:   CTP
--    - region:         EU (primary region, operates globally)
--    - vatsim_division: NULL (cross-org, no single division)
--    - default_locale: en-EU
--    - No facility ownership (CTP operates across org boundaries)
--    - No Discord config
-- ----------------------------------------------------------------------------
INSERT INTO organizations (org_code, org_name, display_name, region, vatsim_division, default_locale, is_active)
VALUES ('ctp', 'CTP', 'CTP', 'EU', NULL, 'en-EU', 1);

-- No org_facilities rows — CTP is cross-org and does not own specific FIRs.
-- CTP membership is manual (no auto-assignment from VATSIM division).
