-- =============================================================================
-- TMI Proposals Schema - Coordination Approval Workflow
-- Database: VATSIM_TMI
-- Version: 1.0
-- Date: 2026-01-28
-- =============================================================================
--
-- TABLES INCLUDED:
--   1. tmi_proposals          - Pending TMI coordination proposals
--   2. tmi_proposal_facilities - Facilities required to approve
--   3. tmi_proposal_reactions  - Approval/denial reactions from facilities
--
-- WORKFLOW:
--   1. User submits TMI for coordination (status = PENDING)
--   2. Proposal posted to Discord #coordination with deadline
--   3. Facilities react with emoji to approve/deny
--   4. If unanimous approval -> TMI activated/scheduled
--   5. If any denial -> DCC can override with :DCC: or :x:
--   6. Past deadline -> requires DCC action
--
-- =============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

PRINT '=== TMI Proposals Schema Migration ===';
PRINT 'Started at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO

-- =============================================================================
-- TABLE 1: tmi_proposals (Pending Coordination Proposals)
-- =============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.tmi_proposals') AND type = 'U')
BEGIN
    CREATE TABLE dbo.tmi_proposals (
        proposal_id             INT IDENTITY(1,1) PRIMARY KEY,
        proposal_guid           UNIQUEIDENTIFIER NOT NULL DEFAULT NEWID(),

        -- ═══════════════════════════════════════════════════════════════════
        -- PROPOSAL IDENTIFICATION
        -- ═══════════════════════════════════════════════════════════════════

        -- Link to pending entry (stored in tmi_entries with status='PROPOSED')
        entry_id                INT NULL,
        entry_type              NVARCHAR(16) NOT NULL,    -- MIT, MINIT, STOP, APREQ, TBM, DELAY, CONFIG

        -- Facilities involved
        requesting_facility     NVARCHAR(64) NOT NULL,    -- Facility requesting the TMI
        providing_facility      NVARCHAR(64) NULL,        -- Facility providing the TMI (if different)
        ctl_element             NVARCHAR(8) NULL,         -- Airport/FCA affected

        -- ═══════════════════════════════════════════════════════════════════
        -- PROPOSAL CONTENT
        -- ═══════════════════════════════════════════════════════════════════

        -- Full entry data (JSON)
        entry_data_json         NVARCHAR(MAX) NOT NULL,   -- Complete entry data for activation
        raw_text                NVARCHAR(MAX) NULL,       -- NTML-formatted text

        -- ═══════════════════════════════════════════════════════════════════
        -- COORDINATION SETTINGS
        -- ═══════════════════════════════════════════════════════════════════

        -- Deadline for facility approval (user-specified UTC)
        approval_deadline_utc   DATETIME2(0) NOT NULL,

        -- Auto-activate or schedule based on valid period
        valid_from              DATETIME2(0) NULL,
        valid_until             DATETIME2(0) NULL,

        -- ═══════════════════════════════════════════════════════════════════
        -- STATUS
        -- ═══════════════════════════════════════════════════════════════════

        status                  NVARCHAR(16) NOT NULL DEFAULT 'PENDING',
                                -- PENDING   = Awaiting facility reactions
                                -- APPROVED  = Unanimous approval (or DCC override)
                                -- DENIED    = Denied (by facility + not overridden)
                                -- ACTIVATED = TMI activated
                                -- SCHEDULED = TMI scheduled for future
                                -- EXPIRED   = Deadline passed without action
                                -- CANCELLED = Proposer cancelled

        -- Approval tracking
        requires_unanimous      BIT DEFAULT 1,            -- Require all facilities to approve
        facilities_approved     INT DEFAULT 0,            -- Count of approved
        facilities_denied       INT DEFAULT 0,            -- Count of denied
        facilities_required     INT DEFAULT 0,            -- Total facilities required

        -- DCC Override
        dcc_override            BIT DEFAULT 0,
        dcc_override_action     NVARCHAR(8) NULL,         -- APPROVE, DENY
        dcc_override_by         NVARCHAR(64) NULL,
        dcc_override_at         DATETIME2(0) NULL,

        -- ═══════════════════════════════════════════════════════════════════
        -- DISCORD TRACKING
        -- ═══════════════════════════════════════════════════════════════════

        discord_channel_id      NVARCHAR(64) NULL,
        discord_message_id      NVARCHAR(64) NULL,
        discord_posted_at       DATETIME2(0) NULL,

        -- ═══════════════════════════════════════════════════════════════════
        -- RESULT
        -- ═══════════════════════════════════════════════════════════════════

        -- Created entry (after activation)
        activated_entry_id      INT NULL,
        activated_at            DATETIME2(0) NULL,

        -- ═══════════════════════════════════════════════════════════════════
        -- AUDIT
        -- ═══════════════════════════════════════════════════════════════════

        created_by              NVARCHAR(64) NULL,
        created_by_name         NVARCHAR(128) NULL,
        created_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        updated_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),

        CONSTRAINT UQ_proposals_guid UNIQUE (proposal_guid)
    );

    -- Indexes
    CREATE NONCLUSTERED INDEX IX_proposals_status ON dbo.tmi_proposals (status, approval_deadline_utc);
    CREATE NONCLUSTERED INDEX IX_proposals_pending ON dbo.tmi_proposals (status, discord_message_id) WHERE status = 'PENDING';
    CREATE NONCLUSTERED INDEX IX_proposals_discord ON dbo.tmi_proposals (discord_message_id) WHERE discord_message_id IS NOT NULL;
    CREATE NONCLUSTERED INDEX IX_proposals_created ON dbo.tmi_proposals (created_at DESC);

    PRINT 'Created table: tmi_proposals';
END
ELSE
BEGIN
    PRINT 'Table dbo.tmi_proposals already exists - skipping';
END
GO

-- =============================================================================
-- TABLE 2: tmi_proposal_facilities (Facilities Required to Approve)
-- =============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.tmi_proposal_facilities') AND type = 'U')
BEGIN
    CREATE TABLE dbo.tmi_proposal_facilities (
        id                      INT IDENTITY(1,1) PRIMARY KEY,
        proposal_id             INT NOT NULL,

        -- Facility info
        facility_code           NVARCHAR(8) NOT NULL,     -- ARTCC code (ZNY, ZDC, etc.)
        facility_name           NVARCHAR(64) NULL,        -- Display name
        facility_type           NVARCHAR(16) NULL,        -- ARTCC, TRACON, TOWER, DCC

        -- Approval emoji (custom Discord emoji)
        approval_emoji          NVARCHAR(64) NULL,        -- e.g., <:ZNY:123456789>

        -- Status
        approval_status         NVARCHAR(16) NOT NULL DEFAULT 'PENDING',
                                -- PENDING, APPROVED, DENIED

        -- Reaction tracking
        reacted_at              DATETIME2(0) NULL,
        reacted_by_user_id      NVARCHAR(64) NULL,
        reacted_by_username     NVARCHAR(128) NULL,

        CONSTRAINT FK_proposal_facilities_proposal FOREIGN KEY (proposal_id)
            REFERENCES dbo.tmi_proposals(proposal_id) ON DELETE CASCADE
    );

    CREATE NONCLUSTERED INDEX IX_proposal_facilities_proposal ON dbo.tmi_proposal_facilities (proposal_id, approval_status);
    CREATE UNIQUE NONCLUSTERED INDEX IX_proposal_facilities_unique ON dbo.tmi_proposal_facilities (proposal_id, facility_code);

    PRINT 'Created table: tmi_proposal_facilities';
END
ELSE
BEGIN
    PRINT 'Table dbo.tmi_proposal_facilities already exists - skipping';
END
GO

-- =============================================================================
-- TABLE 3: tmi_proposal_reactions (All Reactions Log)
-- =============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.tmi_proposal_reactions') AND type = 'U')
BEGIN
    CREATE TABLE dbo.tmi_proposal_reactions (
        reaction_id             INT IDENTITY(1,1) PRIMARY KEY,
        proposal_id             INT NOT NULL,

        -- Reaction details
        emoji                   NVARCHAR(64) NOT NULL,    -- Emoji name or unicode
        emoji_id                NVARCHAR(64) NULL,        -- Discord custom emoji ID
        reaction_type           NVARCHAR(16) NOT NULL,    -- FACILITY_APPROVE, FACILITY_DENY, DCC_APPROVE, DCC_DENY, OTHER

        -- User who reacted
        discord_user_id         NVARCHAR(64) NOT NULL,
        discord_username        NVARCHAR(128) NULL,
        discord_roles           NVARCHAR(MAX) NULL,       -- JSON array of role IDs

        -- Mapping
        facility_code           NVARCHAR(8) NULL,         -- Matched facility (if approval)

        -- Timestamp
        reacted_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),

        CONSTRAINT FK_proposal_reactions_proposal FOREIGN KEY (proposal_id)
            REFERENCES dbo.tmi_proposals(proposal_id) ON DELETE CASCADE
    );

    CREATE NONCLUSTERED INDEX IX_proposal_reactions_proposal ON dbo.tmi_proposal_reactions (proposal_id, reacted_at);
    CREATE NONCLUSTERED INDEX IX_proposal_reactions_user ON dbo.tmi_proposal_reactions (discord_user_id);

    PRINT 'Created table: tmi_proposal_reactions';
END
ELSE
BEGIN
    PRINT 'Table dbo.tmi_proposal_reactions already exists - skipping';
END
GO

-- =============================================================================
-- STORED PROCEDURE: sp_CheckProposalApproval
-- =============================================================================

IF OBJECT_ID('dbo.sp_CheckProposalApproval', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_CheckProposalApproval;
GO

CREATE PROCEDURE dbo.sp_CheckProposalApproval
    @proposal_id INT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @status NVARCHAR(16);
    DECLARE @facilities_required INT;
    DECLARE @facilities_approved INT;
    DECLARE @facilities_denied INT;
    DECLARE @deadline DATETIME2(0);
    DECLARE @dcc_override BIT;
    DECLARE @dcc_action NVARCHAR(8);

    -- Get current proposal state
    SELECT
        @status = status,
        @deadline = approval_deadline_utc,
        @dcc_override = dcc_override,
        @dcc_action = dcc_override_action
    FROM dbo.tmi_proposals
    WHERE proposal_id = @proposal_id;

    -- Only process pending proposals
    IF @status != 'PENDING'
    BEGIN
        SELECT
            @proposal_id AS proposal_id,
            @status AS status,
            'NOT_PENDING' AS action_taken;
        RETURN;
    END

    -- Count facility responses
    SELECT
        @facilities_required = COUNT(*),
        @facilities_approved = SUM(CASE WHEN approval_status = 'APPROVED' THEN 1 ELSE 0 END),
        @facilities_denied = SUM(CASE WHEN approval_status = 'DENIED' THEN 1 ELSE 0 END)
    FROM dbo.tmi_proposal_facilities
    WHERE proposal_id = @proposal_id;

    -- Update counts
    UPDATE dbo.tmi_proposals
    SET
        facilities_required = @facilities_required,
        facilities_approved = @facilities_approved,
        facilities_denied = @facilities_denied,
        updated_at = SYSUTCDATETIME()
    WHERE proposal_id = @proposal_id;

    -- Check for DCC override
    IF @dcc_override = 1
    BEGIN
        IF @dcc_action = 'APPROVE'
        BEGIN
            UPDATE dbo.tmi_proposals
            SET status = 'APPROVED', updated_at = SYSUTCDATETIME()
            WHERE proposal_id = @proposal_id;

            SELECT @proposal_id AS proposal_id, 'APPROVED' AS status, 'DCC_OVERRIDE_APPROVE' AS action_taken;
            RETURN;
        END
        ELSE IF @dcc_action = 'DENY'
        BEGIN
            UPDATE dbo.tmi_proposals
            SET status = 'DENIED', updated_at = SYSUTCDATETIME()
            WHERE proposal_id = @proposal_id;

            SELECT @proposal_id AS proposal_id, 'DENIED' AS status, 'DCC_OVERRIDE_DENY' AS action_taken;
            RETURN;
        END
    END

    -- Check for unanimous approval
    IF @facilities_approved = @facilities_required AND @facilities_required > 0
    BEGIN
        UPDATE dbo.tmi_proposals
        SET status = 'APPROVED', updated_at = SYSUTCDATETIME()
        WHERE proposal_id = @proposal_id;

        SELECT @proposal_id AS proposal_id, 'APPROVED' AS status, 'UNANIMOUS_APPROVAL' AS action_taken;
        RETURN;
    END

    -- Check if deadline passed
    IF @deadline < SYSUTCDATETIME()
    BEGIN
        -- Deadline passed without unanimous approval - needs DCC action
        IF @facilities_denied > 0 OR @facilities_approved < @facilities_required
        BEGIN
            -- Mark as expired - requires DCC intervention
            UPDATE dbo.tmi_proposals
            SET status = 'EXPIRED', updated_at = SYSUTCDATETIME()
            WHERE proposal_id = @proposal_id;

            SELECT @proposal_id AS proposal_id, 'EXPIRED' AS status, 'DEADLINE_PASSED' AS action_taken;
            RETURN;
        END
    END

    -- Still pending
    SELECT
        @proposal_id AS proposal_id,
        'PENDING' AS status,
        'AWAITING_APPROVAL' AS action_taken,
        @facilities_approved AS approved,
        @facilities_required AS required;
END
GO

PRINT 'Created procedure: sp_CheckProposalApproval';
GO

-- =============================================================================
-- VIEW: vw_tmi_pending_proposals
-- =============================================================================

IF OBJECT_ID('dbo.vw_tmi_pending_proposals', 'V') IS NOT NULL
    DROP VIEW dbo.vw_tmi_pending_proposals;
GO

CREATE VIEW dbo.vw_tmi_pending_proposals AS
SELECT
    p.proposal_id,
    p.proposal_guid,
    p.entry_type,
    p.requesting_facility,
    p.providing_facility,
    p.ctl_element,
    p.approval_deadline_utc,
    p.valid_from,
    p.valid_until,
    p.status,
    p.facilities_approved,
    p.facilities_denied,
    p.facilities_required,
    p.dcc_override,
    p.discord_message_id,
    p.discord_channel_id,
    p.created_by_name,
    p.created_at,
    DATEDIFF(MINUTE, SYSUTCDATETIME(), p.approval_deadline_utc) AS minutes_until_deadline,
    CASE
        WHEN p.approval_deadline_utc < SYSUTCDATETIME() THEN 1
        ELSE 0
    END AS is_past_deadline
FROM dbo.tmi_proposals p
WHERE p.status = 'PENDING';
GO

PRINT 'Created view: vw_tmi_pending_proposals';
GO

PRINT '';
PRINT '=== TMI Proposals Schema Migration Complete ===';
PRINT 'Finished at: ' + CONVERT(VARCHAR, GETUTCDATE(), 120);
GO
