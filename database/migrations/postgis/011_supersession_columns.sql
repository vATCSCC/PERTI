-- =============================================================================
-- Migration: 011_supersession_columns.sql
-- Database:  VATSIM_GIS (PostGIS)
-- Purpose:   Add supersession tracking columns to reference tables
-- Date:      2026-03-19
-- =============================================================================
--
-- Adds is_superseded, superseded_cycle, superseded_reason to all 5 reference
-- tables synced from VATSIM_REF. These track historical _old_ entries from
-- AIRAC cycle transitions.
--
-- Safe to re-run (ADD COLUMN IF NOT EXISTS).
-- =============================================================================

-- nav_fixes
ALTER TABLE nav_fixes ADD COLUMN IF NOT EXISTS is_superseded BOOLEAN DEFAULT FALSE;
ALTER TABLE nav_fixes ADD COLUMN IF NOT EXISTS superseded_cycle VARCHAR(8);
ALTER TABLE nav_fixes ADD COLUMN IF NOT EXISTS superseded_reason VARCHAR(16);

-- airways
ALTER TABLE airways ADD COLUMN IF NOT EXISTS is_superseded BOOLEAN DEFAULT FALSE;
ALTER TABLE airways ADD COLUMN IF NOT EXISTS superseded_cycle VARCHAR(8);
ALTER TABLE airways ADD COLUMN IF NOT EXISTS superseded_reason VARCHAR(16);

-- coded_departure_routes
ALTER TABLE coded_departure_routes ADD COLUMN IF NOT EXISTS is_superseded BOOLEAN DEFAULT FALSE;
ALTER TABLE coded_departure_routes ADD COLUMN IF NOT EXISTS superseded_cycle VARCHAR(8);
ALTER TABLE coded_departure_routes ADD COLUMN IF NOT EXISTS superseded_reason VARCHAR(16);

-- nav_procedures
ALTER TABLE nav_procedures ADD COLUMN IF NOT EXISTS is_superseded BOOLEAN DEFAULT FALSE;
ALTER TABLE nav_procedures ADD COLUMN IF NOT EXISTS superseded_cycle VARCHAR(8);
ALTER TABLE nav_procedures ADD COLUMN IF NOT EXISTS superseded_reason VARCHAR(16);

-- playbook_routes
ALTER TABLE playbook_routes ADD COLUMN IF NOT EXISTS is_superseded BOOLEAN DEFAULT FALSE;
ALTER TABLE playbook_routes ADD COLUMN IF NOT EXISTS superseded_cycle VARCHAR(8);
ALTER TABLE playbook_routes ADD COLUMN IF NOT EXISTS superseded_reason VARCHAR(16);
