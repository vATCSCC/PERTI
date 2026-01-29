-- =============================================================================
-- TMI Test Data Cleanup Script
-- Database: VATSIM_TMI
-- Date: 2026-01-29
-- Purpose: Clear all test data from TMI tables for fresh start
-- =============================================================================
--
-- WARNING: This script permanently deletes all data from TMI tables.
-- Only run this in development/testing environments.
--
-- =============================================================================

SET NOCOUNT ON;
GO

PRINT '=== TMI Test Data Cleanup ==='
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120)
PRINT ''

-- =============================================================================
-- 1. COORDINATION TABLES (delete in order due to foreign keys)
-- =============================================================================

PRINT 'Clearing coordination tables...'

-- Reactions (child of proposals)
DELETE FROM dbo.tmi_proposal_reactions;
PRINT '  - tmi_proposal_reactions: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows deleted'

-- Facilities (child of proposals)
DELETE FROM dbo.tmi_proposal_facilities;
PRINT '  - tmi_proposal_facilities: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows deleted'

-- Proposals (parent)
DELETE FROM dbo.tmi_proposals;
PRINT '  - tmi_proposals: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows deleted'

-- =============================================================================
-- 2. REROUTE TABLES (delete in order due to foreign keys)
-- =============================================================================

PRINT ''
PRINT 'Clearing reroute tables...'

-- Compliance log (child of reroute_flights)
IF OBJECT_ID('dbo.tmi_reroute_compliance_log', 'U') IS NOT NULL
BEGIN
    DELETE FROM dbo.tmi_reroute_compliance_log;
    PRINT '  - tmi_reroute_compliance_log: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows deleted'
END

-- Flight assignments (child of reroutes)
IF OBJECT_ID('dbo.tmi_reroute_flights', 'U') IS NOT NULL
BEGIN
    DELETE FROM dbo.tmi_reroute_flights;
    PRINT '  - tmi_reroute_flights: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows deleted'
END

-- Reroutes (parent)
IF OBJECT_ID('dbo.tmi_reroutes', 'U') IS NOT NULL
BEGIN
    DELETE FROM dbo.tmi_reroutes;
    PRINT '  - tmi_reroutes: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows deleted'
END

-- =============================================================================
-- 3. PROGRAM TABLES (delete children first due to foreign keys)
-- =============================================================================

PRINT ''
PRINT 'Clearing program tables...'

-- Flight control (child of programs - FK_tmi_flight_control_program)
IF OBJECT_ID('dbo.tmi_flight_control', 'U') IS NOT NULL
BEGIN
    DELETE FROM dbo.tmi_flight_control;
    PRINT '  - tmi_flight_control: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows deleted'
END

-- Slots (child of programs)
IF OBJECT_ID('dbo.tmi_slots', 'U') IS NOT NULL
BEGIN
    DELETE FROM dbo.tmi_slots;
    PRINT '  - tmi_slots: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows deleted'
END

-- Programs (parent)
IF OBJECT_ID('dbo.tmi_programs', 'U') IS NOT NULL
BEGIN
    DELETE FROM dbo.tmi_programs;
    PRINT '  - tmi_programs: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows deleted'
END

-- =============================================================================
-- 4. MAIN TMI TABLES
-- =============================================================================

PRINT ''
PRINT 'Clearing main TMI tables...'

-- Entries
IF OBJECT_ID('dbo.tmi_entries', 'U') IS NOT NULL
BEGIN
    DELETE FROM dbo.tmi_entries;
    PRINT '  - tmi_entries: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows deleted'
END

-- Advisories
IF OBJECT_ID('dbo.tmi_advisories', 'U') IS NOT NULL
BEGIN
    DELETE FROM dbo.tmi_advisories;
    PRINT '  - tmi_advisories: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows deleted'
END

-- Public routes
IF OBJECT_ID('dbo.tmi_public_routes', 'U') IS NOT NULL
BEGIN
    DELETE FROM dbo.tmi_public_routes;
    PRINT '  - tmi_public_routes: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows deleted'
END

-- =============================================================================
-- 5. AUDIT AND SEQUENCE TABLES
-- =============================================================================

PRINT ''
PRINT 'Clearing audit and sequence tables...'

-- Events log
IF OBJECT_ID('dbo.tmi_events', 'U') IS NOT NULL
BEGIN
    DELETE FROM dbo.tmi_events;
    PRINT '  - tmi_events: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows deleted'
END

-- Advisory sequences (reset numbering)
IF OBJECT_ID('dbo.tmi_advisory_sequences', 'U') IS NOT NULL
BEGIN
    DELETE FROM dbo.tmi_advisory_sequences;
    PRINT '  - tmi_advisory_sequences: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows deleted'
END

-- =============================================================================
-- 6. DISCORD QUEUE TABLE
-- =============================================================================

PRINT ''
PRINT 'Clearing Discord queue...'

IF OBJECT_ID('dbo.tmi_discord_posts', 'U') IS NOT NULL
BEGIN
    DELETE FROM dbo.tmi_discord_posts;
    PRINT '  - tmi_discord_posts: ' + CAST(@@ROWCOUNT AS VARCHAR) + ' rows deleted'
END

-- =============================================================================
-- 7. RESET IDENTITY COLUMNS (optional - uncomment if needed)
-- =============================================================================

/*
PRINT ''
PRINT 'Resetting identity columns...'

DBCC CHECKIDENT ('tmi_proposals', RESEED, 0);
DBCC CHECKIDENT ('tmi_proposal_facilities', RESEED, 0);
DBCC CHECKIDENT ('tmi_proposal_reactions', RESEED, 0);
DBCC CHECKIDENT ('tmi_entries', RESEED, 0);
DBCC CHECKIDENT ('tmi_advisories', RESEED, 0);
DBCC CHECKIDENT ('tmi_programs', RESEED, 0);
DBCC CHECKIDENT ('tmi_reroutes', RESEED, 0);
DBCC CHECKIDENT ('tmi_events', RESEED, 0);

PRINT 'Identity columns reset'
*/

-- =============================================================================
-- SUMMARY
-- =============================================================================

PRINT ''
PRINT '=== TMI Test Data Cleanup Complete ==='
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120)
PRINT ''
PRINT 'REMINDER: Also clear the Discord #coordination channel manually.'
PRINT '          Delete all threads in the channel to remove test coordination messages.'
GO
