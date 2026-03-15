-- =============================================================================
-- Migration 007: Add missing columns to tracon_boundaries
-- =============================================================================
-- The import script expects hierarchy_level, hierarchy_type, and parent_fir
-- columns from the enriched canonical tracon.json. Also widen parent_artcc
-- from VARCHAR(4) to VARCHAR(20) for international ICAO codes.
-- =============================================================================

-- Add enrichment columns
ALTER TABLE tracon_boundaries ADD COLUMN IF NOT EXISTS hierarchy_level INTEGER;
ALTER TABLE tracon_boundaries ADD COLUMN IF NOT EXISTS hierarchy_type VARCHAR(30);
ALTER TABLE tracon_boundaries ADD COLUMN IF NOT EXISTS parent_fir VARCHAR(20);

-- Widen parent_artcc for international codes (e.g., CZEG, EDGG)
ALTER TABLE tracon_boundaries ALTER COLUMN parent_artcc TYPE VARCHAR(20);
