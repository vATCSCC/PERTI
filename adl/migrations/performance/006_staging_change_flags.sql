-- Migration: Add change_flags to adl_staging_pilots for delta detection
-- Version: V9.3.0
-- Date: 2026-02-16
--
-- change_flags bitmask:
--   Bit 0 (1): POSITION_CHANGED
--   Bit 1 (2): PLAN_CHANGED
--   Bit 2 (4): NEW_FLIGHT
--   Value 0:   Heartbeat only (everything identical)
--   Default 15: Full processing (backward-compatible fallback)
--
-- DEFAULT 15 ensures backward compatibility: old daemon code without
-- delta detection will trigger full processing for all flights.

IF NOT EXISTS (
    SELECT 1 FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.adl_staging_pilots')
    AND name = 'change_flags'
)
BEGIN
    ALTER TABLE dbo.adl_staging_pilots
    ADD change_flags TINYINT NOT NULL DEFAULT 15;
END
