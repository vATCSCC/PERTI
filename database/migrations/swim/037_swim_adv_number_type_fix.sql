-- Migration 037: Fix adv_number type mismatch in swim_tmi_reroutes
-- Source (VATSIM_TMI.tmi_reroutes.adv_number) is NVARCHAR(16)
-- Mirror was incorrectly typed as INT, losing leading zeros (e.g., '006' -> 6)
-- Applied: 2026-04-04

ALTER TABLE dbo.swim_tmi_reroutes ALTER COLUMN adv_number NVARCHAR(16) NULL;
