-- ============================================================================
-- Migration: 007_widen_facilities_columns.sql
-- Database:  perti_site (MySQL 8)
-- Purpose:   Widen facilities_involved and impacted_area columns on
--            playbook_plays to support large multi-region playbooks (e.g.,
--            transatlantic plays with 90+ routes spanning dozens of FIRs).
--            500 chars is insufficient when auto-computed from route ARTCCs.
-- ============================================================================

ALTER TABLE playbook_plays
MODIFY COLUMN facilities_involved VARCHAR(2000) NULL DEFAULT NULL;

ALTER TABLE playbook_plays
MODIFY COLUMN impacted_area VARCHAR(2000) NULL DEFAULT NULL;
