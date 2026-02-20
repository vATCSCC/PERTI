-- ============================================================================
-- Migration 021: Web-based TMI Coordination Support
-- Purpose: Add OI tracking columns, widen reaction_type, enhance audit log
-- Date: 2026-02-20
-- ============================================================================
--
-- CHANGES:
--   1. tmi_proposal_facilities: Add reacted_by_oi for OI tracking
--   2. tmi_proposal_reactions: Add operating_initials, widen reaction_type
--   3. tmi_coordination_log: Add structured audit columns (CID, OI, facility, via)
--
-- ============================================================================

USE VATSIM_TMI;
GO

PRINT '=== Migration 021: Web-based TMI Coordination Support ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- 1. tmi_proposal_facilities: track OI of the person who reacted
IF NOT EXISTS (
    SELECT * FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.tmi_proposal_facilities')
    AND name = 'reacted_by_oi'
)
BEGIN
    ALTER TABLE dbo.tmi_proposal_facilities ADD reacted_by_oi NVARCHAR(4) NULL;
    PRINT 'Added column: tmi_proposal_facilities.reacted_by_oi';
END
ELSE
BEGIN
    PRINT 'Column tmi_proposal_facilities.reacted_by_oi already exists - skipping';
END
GO

-- 2. tmi_proposal_reactions: track OI in the audit log
IF NOT EXISTS (
    SELECT * FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.tmi_proposal_reactions')
    AND name = 'operating_initials'
)
BEGIN
    ALTER TABLE dbo.tmi_proposal_reactions ADD operating_initials NVARCHAR(4) NULL;
    PRINT 'Added column: tmi_proposal_reactions.operating_initials';
END
ELSE
BEGIN
    PRINT 'Column tmi_proposal_reactions.operating_initials already exists - skipping';
END
GO

-- 3. tmi_proposal_reactions: widen reaction_type from NVARCHAR(16) to NVARCHAR(24)
--    FACILITY_APPROVE is exactly 16 chars (at the limit)
--    WEB_FAC_APPROVE = 15 chars, WEB_FAC_DENY = 12 chars
DECLARE @current_len INT;
SELECT @current_len = max_length / 2  -- nvarchar stores 2 bytes per char
FROM sys.columns
WHERE object_id = OBJECT_ID('dbo.tmi_proposal_reactions')
AND name = 'reaction_type';

IF @current_len IS NOT NULL AND @current_len < 24
BEGIN
    ALTER TABLE dbo.tmi_proposal_reactions ALTER COLUMN reaction_type NVARCHAR(24) NOT NULL;
    PRINT 'Widened column: tmi_proposal_reactions.reaction_type to NVARCHAR(24)';
END
ELSE
BEGIN
    PRINT 'Column tmi_proposal_reactions.reaction_type already >= 24 chars - skipping';
END
GO

-- 4. tmi_coordination_log: add structured audit columns
--    Currently only has: log_id, proposal_id, action, details(JSON), created_at
IF EXISTS (SELECT * FROM sys.tables WHERE name = 'tmi_coordination_log')
BEGIN
    IF NOT EXISTS (
        SELECT * FROM sys.columns
        WHERE object_id = OBJECT_ID('dbo.tmi_coordination_log')
        AND name = 'user_cid'
    )
    BEGIN
        ALTER TABLE dbo.tmi_coordination_log ADD user_cid NVARCHAR(32) NULL;
        PRINT 'Added column: tmi_coordination_log.user_cid';
    END

    IF NOT EXISTS (
        SELECT * FROM sys.columns
        WHERE object_id = OBJECT_ID('dbo.tmi_coordination_log')
        AND name = 'user_name'
    )
    BEGIN
        ALTER TABLE dbo.tmi_coordination_log ADD user_name NVARCHAR(128) NULL;
        PRINT 'Added column: tmi_coordination_log.user_name';
    END

    IF NOT EXISTS (
        SELECT * FROM sys.columns
        WHERE object_id = OBJECT_ID('dbo.tmi_coordination_log')
        AND name = 'operating_initials'
    )
    BEGIN
        ALTER TABLE dbo.tmi_coordination_log ADD operating_initials NVARCHAR(4) NULL;
        PRINT 'Added column: tmi_coordination_log.operating_initials';
    END

    IF NOT EXISTS (
        SELECT * FROM sys.columns
        WHERE object_id = OBJECT_ID('dbo.tmi_coordination_log')
        AND name = 'facility_code'
    )
    BEGIN
        ALTER TABLE dbo.tmi_coordination_log ADD facility_code NVARCHAR(8) NULL;
        PRINT 'Added column: tmi_coordination_log.facility_code';
    END

    IF NOT EXISTS (
        SELECT * FROM sys.columns
        WHERE object_id = OBJECT_ID('dbo.tmi_coordination_log')
        AND name = 'via'
    )
    BEGIN
        ALTER TABLE dbo.tmi_coordination_log ADD via NVARCHAR(16) NULL;
        PRINT 'Added column: tmi_coordination_log.via';
    END
END
ELSE
BEGIN
    PRINT 'Table tmi_coordination_log does not exist - skipping structured column additions';
END
GO

PRINT '';
PRINT '=== Migration 021 completed successfully ===';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO
