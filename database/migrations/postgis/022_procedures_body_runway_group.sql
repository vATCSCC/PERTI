-- Migration 022: Add body_name and runway_group columns to nav_procedures
-- Supports the unified procedures JSON format with structured runway associations.
--
-- body_name:    The NASR body name (e.g., "FANZI-ACCRA", "AALLE-KIPPR")
-- runway_group: JSON array of {airport, runways} objects derived from ORIG_GROUP/DEST_GROUP
--               e.g., [{"airport":"TJSJ","runways":["08","10","26","28"]}]
--               Uses proper ICAO codes (not naive K-prefix): SJU->TJSJ, HNL->PHNL, etc.
--
-- Date: 2026-04-05

-- Add columns
ALTER TABLE nav_procedures ADD COLUMN IF NOT EXISTS body_name VARCHAR(64);
ALTER TABLE nav_procedures ADD COLUMN IF NOT EXISTS runway_group TEXT;

-- Index for runway-aware lookups (used by expand_route() runway preference)
CREATE INDEX IF NOT EXISTS idx_np_code_runway_group
    ON nav_procedures (computer_code, runway_group)
    WHERE is_active = true AND (is_superseded IS NULL OR is_superseded = false);
