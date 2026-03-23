-- ============================================================================
-- Migration: 014_ctp_external_fields.sql
-- Database:  perti_site (MySQL 8)
-- Purpose:   Add CTP external metadata columns for route sync, extend source
--            ENUM, relax origin/dest constraints, add UNIQUE sync index.
-- Depends:   012_analysis_throughput.sql, 013_add_route_geometry.sql
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. Extend source ENUM to include CTP
-- ----------------------------------------------------------------------------
ALTER TABLE playbook_plays
  MODIFY COLUMN source ENUM('FAA','DCC','ECFMP','CANOC','CADENA','FAA_HISTORICAL','CTP')
  NOT NULL DEFAULT 'DCC';

-- ----------------------------------------------------------------------------
-- 2. Add external revision tracking to plays (for idempotency)
-- ----------------------------------------------------------------------------
ALTER TABLE playbook_plays ADD COLUMN external_revision BIGINT NULL DEFAULT NULL;

-- ----------------------------------------------------------------------------
-- 3. Add external metadata columns to routes
-- ----------------------------------------------------------------------------
ALTER TABLE playbook_routes ADD COLUMN external_id VARCHAR(100) NULL DEFAULT NULL;
ALTER TABLE playbook_routes ADD COLUMN external_source VARCHAR(50) NULL DEFAULT NULL;
ALTER TABLE playbook_routes ADD COLUMN external_group VARCHAR(100) NULL DEFAULT NULL;
ALTER TABLE playbook_routes ADD COLUMN external_facilities TEXT NULL DEFAULT NULL;
ALTER TABLE playbook_routes ADD COLUMN external_tags TEXT NULL DEFAULT NULL;

-- ----------------------------------------------------------------------------
-- 4. Relax origin/dest NOT NULL constraint
--    CTP oceanic routes (e.g., VESMI 6050N ... BALIX) have waypoint-only
--    endpoints, not airports. NULL DEFAULT '' is backward-compatible.
-- ----------------------------------------------------------------------------
ALTER TABLE playbook_routes MODIFY COLUMN origin VARCHAR(200) NULL DEFAULT '';
ALTER TABLE playbook_routes MODIFY COLUMN dest VARCHAR(200) NULL DEFAULT '';

-- ----------------------------------------------------------------------------
-- 5. Unique index for sync lookups
--    Prevents duplicate external_ids within a play from malformed payloads.
--    The sync algorithm matches routes by external_id, so uniqueness is
--    a correctness requirement.
-- ----------------------------------------------------------------------------
CREATE UNIQUE INDEX IX_playbook_routes_external
  ON playbook_routes (play_id, external_source, external_id);

-- ----------------------------------------------------------------------------
-- 6. Index for external_revision lookup (idempotency check)
-- ----------------------------------------------------------------------------
CREATE INDEX IX_playbook_plays_ext_rev
  ON playbook_plays (ctp_session_id, external_revision);
