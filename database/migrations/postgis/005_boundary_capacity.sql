-- ============================================================================
-- PostGIS Migration 005: Boundary Capacity Columns
-- Adds airspace capacity tracking columns to boundary tables
-- ============================================================================
-- Run on: VATSIM_GIS (PostgreSQL/PostGIS)
-- Date: 2026-03-05
--
-- New columns on sector_boundaries, artcc_boundaries, tracon_boundaries:
--   capacity, capacity_type, capacity_source
-- ============================================================================

-- sector_boundaries
ALTER TABLE sector_boundaries ADD COLUMN IF NOT EXISTS capacity INT NULL;
ALTER TABLE sector_boundaries ADD COLUMN IF NOT EXISTS capacity_type VARCHAR(20) NULL;
ALTER TABLE sector_boundaries ADD COLUMN IF NOT EXISTS capacity_source VARCHAR(50) NULL;

COMMENT ON COLUMN sector_boundaries.capacity IS 'Max aircraft count or entry rate';
COMMENT ON COLUMN sector_boundaries.capacity_type IS 'MONITOR, ENTRY_RATE, or OCCUPANCY';
COMMENT ON COLUMN sector_boundaries.capacity_source IS 'Data source: CAD, MANUAL, ECFMP, VATSIM';

-- artcc_boundaries
ALTER TABLE artcc_boundaries ADD COLUMN IF NOT EXISTS capacity INT NULL;
ALTER TABLE artcc_boundaries ADD COLUMN IF NOT EXISTS capacity_type VARCHAR(20) NULL;
ALTER TABLE artcc_boundaries ADD COLUMN IF NOT EXISTS capacity_source VARCHAR(50) NULL;

COMMENT ON COLUMN artcc_boundaries.capacity IS 'Max aircraft count or entry rate';
COMMENT ON COLUMN artcc_boundaries.capacity_type IS 'MONITOR, ENTRY_RATE, or OCCUPANCY';
COMMENT ON COLUMN artcc_boundaries.capacity_source IS 'Data source: CAD, MANUAL, ECFMP, VATSIM';

-- tracon_boundaries
ALTER TABLE tracon_boundaries ADD COLUMN IF NOT EXISTS capacity INT NULL;
ALTER TABLE tracon_boundaries ADD COLUMN IF NOT EXISTS capacity_type VARCHAR(20) NULL;
ALTER TABLE tracon_boundaries ADD COLUMN IF NOT EXISTS capacity_source VARCHAR(50) NULL;

COMMENT ON COLUMN tracon_boundaries.capacity IS 'Max aircraft count or entry rate';
COMMENT ON COLUMN tracon_boundaries.capacity_type IS 'MONITOR, ENTRY_RATE, or OCCUPANCY';
COMMENT ON COLUMN tracon_boundaries.capacity_source IS 'Data source: CAD, MANUAL, ECFMP, VATSIM';
