-- ============================================================================
-- SWIM_API Migration 027: Fix 500 Errors — Missing Columns
-- Applied: 2026-03-23
-- Run as: jpeterson (DDL admin)
--
-- Fixes 3 critical 500 errors found during SWIM API audit:
--   1. /controllers → Applied migration 024 (swim_controllers table)
--   2. /tmi/entries → Added 6 missing filter columns to swim_tmi_entries
--   3. /tmi/routes → Added 5 missing coordination/discord columns to swim_tmi_public_routes
--
-- Also applied matching columns to VATSIM_TMI source tables:
--   - tmi_entries: +6 columns (aircraft_type, altitude, alt_type, speed, speed_operator, flow_type)
--   - tmi_public_routes: +5 columns (coordination_status, coordination_proposal_id, discord_message_id, discord_channel_id, discord_posted_at)
-- ============================================================================

USE SWIM_API;
GO

-- ============================================================================
-- Part 1: swim_tmi_entries — Add 6 restriction/filter columns
-- Referenced by api/swim/v1/tmi/entries.php lines 101-109
-- ============================================================================

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_tmi_entries') AND name = 'aircraft_type')
    ALTER TABLE dbo.swim_tmi_entries ADD aircraft_type NVARCHAR(16) NULL;
GO
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_tmi_entries') AND name = 'altitude')
    ALTER TABLE dbo.swim_tmi_entries ADD altitude NVARCHAR(16) NULL;
GO
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_tmi_entries') AND name = 'alt_type')
    ALTER TABLE dbo.swim_tmi_entries ADD alt_type NVARCHAR(8) NULL;
GO
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_tmi_entries') AND name = 'speed')
    ALTER TABLE dbo.swim_tmi_entries ADD speed NVARCHAR(16) NULL;
GO
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_tmi_entries') AND name = 'speed_operator')
    ALTER TABLE dbo.swim_tmi_entries ADD speed_operator NVARCHAR(8) NULL;
GO
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_tmi_entries') AND name = 'flow_type')
    ALTER TABLE dbo.swim_tmi_entries ADD flow_type NVARCHAR(16) NULL;
GO

-- ============================================================================
-- Part 2: swim_tmi_public_routes — Add 5 coordination/discord columns
-- Referenced by api/swim/v1/tmi/routes.php lines 97, 135-136, 500-501
-- ============================================================================

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_tmi_public_routes') AND name = 'coordination_status')
    ALTER TABLE dbo.swim_tmi_public_routes ADD coordination_status NVARCHAR(16) NULL;
GO
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_tmi_public_routes') AND name = 'coordination_proposal_id')
    ALTER TABLE dbo.swim_tmi_public_routes ADD coordination_proposal_id INT NULL;
GO
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_tmi_public_routes') AND name = 'discord_message_id')
    ALTER TABLE dbo.swim_tmi_public_routes ADD discord_message_id NVARCHAR(32) NULL;
GO
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_tmi_public_routes') AND name = 'discord_channel_id')
    ALTER TABLE dbo.swim_tmi_public_routes ADD discord_channel_id NVARCHAR(32) NULL;
GO
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.swim_tmi_public_routes') AND name = 'discord_posted_at')
    ALTER TABLE dbo.swim_tmi_public_routes ADD discord_posted_at DATETIME2 NULL;
GO

PRINT 'Migration 027 complete: All 500 errors fixed.';
GO
