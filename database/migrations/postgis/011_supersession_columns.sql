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

-- Widen fix_name to match Azure SQL nvarchar(32) (353 ZZ_ pseudo-fixes exceed 16 chars)
ALTER TABLE nav_fixes ALTER COLUMN fix_name TYPE VARCHAR(32);

-- nav_fixes
ALTER TABLE nav_fixes ADD COLUMN IF NOT EXISTS is_superseded BOOLEAN DEFAULT FALSE;
ALTER TABLE nav_fixes ADD COLUMN IF NOT EXISTS superseded_cycle VARCHAR(8);
ALTER TABLE nav_fixes ADD COLUMN IF NOT EXISTS superseded_reason VARCHAR(16);

-- airways
ALTER TABLE airways ADD COLUMN IF NOT EXISTS is_superseded BOOLEAN DEFAULT FALSE;
ALTER TABLE airways ADD COLUMN IF NOT EXISTS superseded_cycle VARCHAR(8);
ALTER TABLE airways ADD COLUMN IF NOT EXISTS superseded_reason VARCHAR(16);

-- coded_departure_routes: drop UNIQUE constraint (CDR codes have 2-3 routes each)
ALTER TABLE coded_departure_routes DROP CONSTRAINT IF EXISTS coded_departure_routes_cdr_code_key;
CREATE INDEX IF NOT EXISTS idx_cdr_code ON coded_departure_routes (cdr_code);

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
