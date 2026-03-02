-- ============================================================================
-- Migration: 006_add_route_remarks.sql
-- Database:  perti_site (MySQL 8)
-- Purpose:   1. Add remarks column to playbook_routes for per-route variant,
--               direction, and coordination notes (e.g., "NB WEST; JCAA UPDATE").
--            2. Add 'CADENA' to playbook_plays.source ENUM for CADENA PASA routes.
-- ============================================================================

-- 1. Route-level remarks
ALTER TABLE playbook_routes
ADD COLUMN remarks VARCHAR(500) NULL DEFAULT NULL
AFTER traversed_sectors_superhigh;

-- 2. Extend source ENUM to include CADENA
ALTER TABLE playbook_plays
MODIFY COLUMN source ENUM('FAA','DCC','ECFMP','CANOC','CADENA') NOT NULL DEFAULT 'DCC';
