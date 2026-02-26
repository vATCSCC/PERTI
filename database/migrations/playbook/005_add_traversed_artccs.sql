-- ============================================================================
-- Migration: 005_add_traversed_artccs.sql
-- Database:  perti_site (MySQL 8)
-- Purpose:   Add traversed_artccs column to playbook_routes for en-route
--            ARTCC filtering. Populated at save time by looking up route
--            fix names in VATSIM_REF.nav_fixes.
-- ============================================================================

ALTER TABLE playbook_routes
ADD COLUMN traversed_artccs VARCHAR(500) NULL DEFAULT NULL
AFTER dest_artccs;
