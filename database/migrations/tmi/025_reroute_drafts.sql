-- ============================================================================
-- Migration: Reroute Drafts and Proposal Integration
-- Purpose: Create drafts table for hybrid storage and link proposals to reroutes
-- Date: 2026-01-30
-- ============================================================================

USE VATSIM_TMI;
GO

-- ============================================================================
-- PART 1: Create tmi_reroute_drafts table for persistent draft storage
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID('dbo.tmi_reroute_drafts') AND type = 'U')
BEGIN
    CREATE TABLE dbo.tmi_reroute_drafts (
        draft_id INT IDENTITY(1,1) PRIMARY KEY,
        draft_guid UNIQUEIDENTIFIER NOT NULL DEFAULT NEWID(),

        -- User who created the draft
        user_cid NVARCHAR(16) NOT NULL,
        user_name NVARCHAR(64) NULL,

        -- Draft metadata
        draft_name NVARCHAR(128) NULL,
        draft_data NVARCHAR(MAX) NOT NULL,  -- JSON blob containing full draft state

        -- Timestamps
        created_at DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        updated_at DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        expires_at DATETIME2(0) NULL,  -- Auto-cleanup after expiration

        -- Status tracking
        is_submitted BIT NOT NULL DEFAULT 0,  -- Set to 1 when submitted for coordination
        submitted_proposal_id INT NULL  -- Link to resulting proposal if submitted
    );

    PRINT 'Created table tmi_reroute_drafts';
END
ELSE
BEGIN
    PRINT 'Table tmi_reroute_drafts already exists';
END
GO

-- Create indexes for efficient lookups
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE object_id = OBJECT_ID('dbo.tmi_reroute_drafts') AND name = 'IX_reroute_drafts_user_cid')
BEGIN
    CREATE NONCLUSTERED INDEX IX_reroute_drafts_user_cid
    ON dbo.tmi_reroute_drafts (user_cid)
    INCLUDE (draft_name, created_at, updated_at, is_submitted);

    PRINT 'Created index IX_reroute_drafts_user_cid';
END
GO

IF NOT EXISTS (SELECT * FROM sys.indexes WHERE object_id = OBJECT_ID('dbo.tmi_reroute_drafts') AND name = 'IX_reroute_drafts_expires_at')
BEGIN
    CREATE NONCLUSTERED INDEX IX_reroute_drafts_expires_at
    ON dbo.tmi_reroute_drafts (expires_at)
    WHERE expires_at IS NOT NULL AND is_submitted = 0;

    PRINT 'Created index IX_reroute_drafts_expires_at';
END
GO

IF NOT EXISTS (SELECT * FROM sys.indexes WHERE object_id = OBJECT_ID('dbo.tmi_reroute_drafts') AND name = 'UQ_reroute_drafts_guid')
BEGIN
    CREATE UNIQUE NONCLUSTERED INDEX UQ_reroute_drafts_guid
    ON dbo.tmi_reroute_drafts (draft_guid);

    PRINT 'Created unique index UQ_reroute_drafts_guid';
END
GO

-- ============================================================================
-- PART 2: Add reroute_id column to tmi_proposals for REROUTE type proposals
-- ============================================================================

IF NOT EXISTS (
    SELECT * FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.tmi_proposals')
    AND name = 'reroute_id'
)
BEGIN
    ALTER TABLE dbo.tmi_proposals
    ADD reroute_id INT NULL;

    PRINT 'Added reroute_id column to tmi_proposals';
END
ELSE
BEGIN
    PRINT 'reroute_id column already exists in tmi_proposals';
END
GO

-- Add index for reroute lookups
IF NOT EXISTS (
    SELECT * FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.tmi_proposals')
    AND name = 'IX_tmi_proposals_reroute_id'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_tmi_proposals_reroute_id
    ON dbo.tmi_proposals (reroute_id)
    WHERE reroute_id IS NOT NULL;

    PRINT 'Created index IX_tmi_proposals_reroute_id';
END
GO

-- ============================================================================
-- PART 3: Create stored procedure for draft cleanup
-- ============================================================================

IF OBJECT_ID('dbo.sp_CleanupExpiredRerouteDrafts', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_CleanupExpiredRerouteDrafts;
GO

CREATE PROCEDURE dbo.sp_CleanupExpiredRerouteDrafts
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @deleted INT;

    -- Delete drafts that have expired and were never submitted
    DELETE FROM dbo.tmi_reroute_drafts
    WHERE expires_at < SYSUTCDATETIME()
      AND is_submitted = 0;

    SET @deleted = @@ROWCOUNT;

    -- Also delete very old submitted drafts (older than 30 days)
    DELETE FROM dbo.tmi_reroute_drafts
    WHERE is_submitted = 1
      AND updated_at < DATEADD(DAY, -30, SYSUTCDATETIME());

    SET @deleted = @deleted + @@ROWCOUNT;

    SELECT @deleted AS deleted_count;
END
GO

PRINT 'Created stored procedure sp_CleanupExpiredRerouteDrafts';
GO

-- ============================================================================
-- PART 4: Create view for active (non-expired) drafts
-- ============================================================================

IF OBJECT_ID('dbo.vw_reroute_drafts_active', 'V') IS NOT NULL
    DROP VIEW dbo.vw_reroute_drafts_active;
GO

CREATE VIEW dbo.vw_reroute_drafts_active AS
SELECT
    draft_id,
    draft_guid,
    user_cid,
    user_name,
    draft_name,
    draft_data,
    created_at,
    updated_at,
    expires_at,
    is_submitted,
    submitted_proposal_id
FROM dbo.tmi_reroute_drafts
WHERE is_submitted = 0
  AND (expires_at IS NULL OR expires_at > SYSUTCDATETIME());
GO

PRINT 'Created view vw_reroute_drafts_active';
GO

PRINT 'Migration 025_reroute_drafts completed successfully';
