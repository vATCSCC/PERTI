-- ============================================================================
-- Migration: 004_add_route_remarks.sql
-- Database:  perti_site (MySQL 8)
-- Purpose:   Add play-level remarks column to playbook_plays for TMU notes.
-- ============================================================================

ALTER TABLE playbook_plays
ADD COLUMN remarks VARCHAR(500) NULL DEFAULT NULL
AFTER impacted_area;
