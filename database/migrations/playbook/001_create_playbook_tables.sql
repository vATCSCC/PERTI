-- ============================================================================
-- Migration: 001_create_playbook_tables.sql
-- Database:  perti_site (MySQL 8)
-- Purpose:   Create vATCSCC Playbook tables for play/route management
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. playbook_plays - Catalog of pre-coordinated route plays
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS playbook_plays (
    play_id          INT AUTO_INCREMENT PRIMARY KEY,
    play_name        VARCHAR(100)  NOT NULL,
    play_name_norm   VARCHAR(100)  NOT NULL,
    display_name     VARCHAR(200)  NULL,
    description      TEXT          NULL,
    category         VARCHAR(50)   NULL,
    impacted_area    VARCHAR(200)  NULL,
    facilities_involved VARCHAR(500) NULL,
    scenario_type    VARCHAR(50)   NULL,
    route_format     ENUM('standard','split') NOT NULL DEFAULT 'standard',
    source           ENUM('FAA','DCC') NOT NULL DEFAULT 'DCC',
    status           ENUM('active','draft','archived') NOT NULL DEFAULT 'active',
    airac_cycle      VARCHAR(10)   NULL,
    route_count      INT           NOT NULL DEFAULT 0,
    org_code         VARCHAR(20)   DEFAULT NULL,
    created_by       VARCHAR(20)   NULL,
    updated_by       VARCHAR(20)   NULL,
    updated_at       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_name_source (play_name_norm, source),
    INDEX idx_category (category),
    INDEX idx_status (status),
    INDEX idx_source (source),
    INDEX idx_org_code (org_code)
);

-- ----------------------------------------------------------------------------
-- 2. playbook_routes - Individual routes within a play
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS playbook_routes (
    route_id         INT AUTO_INCREMENT PRIMARY KEY,
    play_id          INT           NOT NULL,
    route_string     TEXT          NOT NULL,
    origin           VARCHAR(200)  NOT NULL,
    origin_filter    VARCHAR(200)  NULL,
    dest             VARCHAR(200)  NOT NULL,
    dest_filter      VARCHAR(200)  NULL,
    origin_airports  VARCHAR(500)  NULL,
    origin_tracons   VARCHAR(200)  NULL,
    origin_artccs    VARCHAR(200)  NULL,
    dest_airports    VARCHAR(500)  NULL,
    dest_tracons     VARCHAR(200)  NULL,
    dest_artccs      VARCHAR(200)  NULL,
    sort_order       INT           DEFAULT 0,
    created_at       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (play_id) REFERENCES playbook_plays(play_id) ON DELETE CASCADE,
    INDEX idx_play_id (play_id)
);

-- ----------------------------------------------------------------------------
-- 3. playbook_changelog - Audit trail for all play/route changes
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS playbook_changelog (
    changelog_id     INT AUTO_INCREMENT PRIMARY KEY,
    play_id          INT           NOT NULL,
    route_id         INT           NULL,
    action           ENUM('play_created','play_updated','play_archived','play_restored','play_deleted',
                          'route_added','route_updated','route_deleted',
                          'faa_import','faa_reimport') NOT NULL,
    field_name       VARCHAR(100)  NULL,
    old_value        TEXT          NULL,
    new_value        TEXT          NULL,
    airac_cycle      VARCHAR(10)   NULL,
    changed_by       VARCHAR(20)   NULL,
    changed_at       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_play_id (play_id),
    INDEX idx_airac (airac_cycle),
    INDEX idx_action (action),
    INDEX idx_changed_at (changed_at)
);
