-- ============================================================================
-- Migration: 012_analysis_throughput.sql
-- Database:  perti_site (MySQL 8)
-- Purpose:   Add route throughput table, CTP scope columns, and changelog
--            enhancements for CTP E26 integration.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. playbook_route_throughput - CTP throughput data per route
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS playbook_route_throughput (
    throughput_id   INT           AUTO_INCREMENT PRIMARY KEY,
    route_id        INT           NOT NULL,
    play_id         INT           NOT NULL,
    source          VARCHAR(50)   NOT NULL DEFAULT 'CTP',
    planned_count   INT           NULL,
    slot_count      INT           NULL,
    peak_rate_hr    INT           NULL,
    avg_rate_hr     DECIMAL(6,1)  NULL,
    period_start    DATETIME      NULL,
    period_end      DATETIME      NULL,
    metadata_json   JSON          NULL,
    updated_by      VARCHAR(20)   NULL,
    updated_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (route_id) REFERENCES playbook_routes(route_id) ON DELETE CASCADE,
    FOREIGN KEY (play_id)  REFERENCES playbook_plays(play_id)   ON DELETE CASCADE,
    UNIQUE KEY uq_route_source (route_id, source),
    INDEX idx_play (play_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 2. Add CTP scope columns to playbook_plays
-- ----------------------------------------------------------------------------
ALTER TABLE playbook_plays
    ADD COLUMN ctp_scope ENUM('NA','OCEANIC','EU') NULL DEFAULT NULL AFTER org_code,
    ADD COLUMN ctp_session_id INT NULL DEFAULT NULL AFTER ctp_scope;

-- MySQL doesn't support filtered indexes; use regular indexes
CREATE INDEX idx_pb_ctp_scope ON playbook_plays (ctp_scope);
CREATE INDEX idx_pb_ctp_session ON playbook_plays (ctp_session_id);

-- ----------------------------------------------------------------------------
-- 3. Enhance playbook_changelog
-- ----------------------------------------------------------------------------
ALTER TABLE playbook_changelog
    ADD COLUMN changed_by_name VARCHAR(100) NULL AFTER changed_by,
    ADD COLUMN ip_address      VARCHAR(45)  NULL AFTER changed_by_name,
    ADD COLUMN session_context JSON         NULL AFTER ip_address;

-- Extend action ENUM to include throughput and NAT events
ALTER TABLE playbook_changelog
MODIFY COLUMN action ENUM(
    'play_created','play_updated','play_archived','play_restored','play_deleted',
    'route_added','route_updated','route_deleted',
    'faa_import','faa_reimport',
    'historical_import','historical_reimport',
    'visibility_changed','acl_added','acl_removed','acl_updated',
    'throughput_updated','throughput_deleted',
    'ctp_scope_changed','nat_track_created','nat_track_updated','nat_track_deleted'
) NOT NULL;

-- Add index for efficient history queries
CREATE INDEX idx_pb_changelog_play_time ON playbook_changelog (play_id, changed_at DESC);
