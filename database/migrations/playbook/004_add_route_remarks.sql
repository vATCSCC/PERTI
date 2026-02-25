-- ============================================================================
-- Migration: 004_add_route_remarks.sql
-- Database:  perti_site (MySQL 8)
-- Purpose:   Add remarks column to playbook_routes for TMU annotations.
-- ============================================================================

ALTER TABLE playbook_routes
ADD COLUMN remarks VARCHAR(500) NULL DEFAULT NULL
AFTER dest_artccs;
