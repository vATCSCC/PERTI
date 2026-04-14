-- ============================================================================
-- Migration: 011_visibility_acl.sql
-- Database:  perti_site (MySQL 8)
-- Purpose:   Add visibility scoping and ACL (access control list) to playbook
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. Add visibility column to playbook_plays
--    Existing plays default to 'public' (backward compatible).
-- ----------------------------------------------------------------------------
ALTER TABLE playbook_plays
    ADD COLUMN visibility ENUM('public','local','private_users','private_org')
        NOT NULL DEFAULT 'public'
        AFTER status;

CREATE INDEX idx_pb_visibility ON playbook_plays (visibility);

-- ----------------------------------------------------------------------------
-- 2. Extend playbook_changelog action ENUM for ACL audit events
-- ----------------------------------------------------------------------------
ALTER TABLE playbook_changelog
MODIFY COLUMN action ENUM(
    'play_created','play_updated','play_archived','play_restored','play_deleted',
    'route_added','route_updated','route_deleted',
    'faa_import','faa_reimport',
    'historical_import','historical_reimport',
    'visibility_changed','acl_added','acl_removed','acl_updated'
) NOT NULL;

-- ----------------------------------------------------------------------------
-- 3. ACL table — per-play shared user list with granular permissions
--
--    Owner (playbook_plays.created_by) has implicit full access and is
--    never stored in this table. Only shared users appear here.
--
--    can_view:        User can see the play in list/detail views
--    can_manage:      User can edit play metadata and routes
--    can_manage_acl:  User can add/remove other users from the ACL
--                     (default FALSE — only owners get manage-access by default)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS playbook_play_acl (
    acl_id          INT           AUTO_INCREMENT PRIMARY KEY,
    play_id         INT           NOT NULL,
    cid             INT           NOT NULL,
    can_view        TINYINT       NOT NULL DEFAULT 1,
    can_manage      TINYINT       NOT NULL DEFAULT 0,
    can_manage_acl  TINYINT       NOT NULL DEFAULT 0,
    added_by        VARCHAR(20)   NULL,
    created_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pb_acl_play_cid (play_id, cid),
    INDEX idx_pb_acl_cid (cid),
    FOREIGN KEY (play_id) REFERENCES playbook_plays(play_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
