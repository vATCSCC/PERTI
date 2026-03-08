-- ============================================================================
-- Migration: 009_route_groups.sql
-- Database:  perti_site (MySQL 8)
-- Purpose:   Add route grouping & coloring for playbook plays
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. playbook_route_groups - Per-play manual group assignments
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS playbook_route_groups (
    group_id         INT AUTO_INCREMENT PRIMARY KEY,
    play_id          INT           NOT NULL,
    group_name       VARCHAR(100)  NOT NULL,
    group_color      CHAR(7)       NOT NULL DEFAULT '#e74c3c',
    route_ids        JSON          NOT NULL,
    sort_order       INT           NOT NULL DEFAULT 0,
    source_config_id INT           DEFAULT NULL,
    created_by       VARCHAR(20)   DEFAULT NULL,
    created_at       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_play_id (play_id),
    FOREIGN KEY (play_id) REFERENCES playbook_plays(play_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 2. playbook_group_configs - Global reusable group configs (templates)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS playbook_group_configs (
    config_id        INT AUTO_INCREMENT PRIMARY KEY,
    config_name      VARCHAR(100)  NOT NULL,
    description      VARCHAR(500)  DEFAULT NULL,
    created_by       VARCHAR(20)   DEFAULT NULL,
    created_at       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 3. playbook_group_config_rules - Rules within a global config
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS playbook_group_config_rules (
    rule_id          INT AUTO_INCREMENT PRIMARY KEY,
    config_id        INT           NOT NULL,
    group_name       VARCHAR(100)  NOT NULL,
    group_color      CHAR(7)       NOT NULL DEFAULT '#e74c3c',
    sort_order       INT           NOT NULL DEFAULT 0,
    match_field      ENUM('origin_tracons','origin_artccs','origin_firs',
                          'dest_tracons','dest_artccs','dest_firs',
                          'origin_airports','dest_airports','route_contains') NOT NULL,
    match_value      VARCHAR(200)  NOT NULL,
    FOREIGN KEY (config_id) REFERENCES playbook_group_configs(config_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
