-- ============================================================================
-- Migration: 009_route_groups.sql
-- Database:  perti_site (MySQL 8)
-- Purpose:   Add route grouping & coloring for playbook plays
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. playbook_route_groups - Per-play group assignments
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS playbook_route_groups (
    group_id         INT AUTO_INCREMENT PRIMARY KEY,
    play_id          INT           NOT NULL,
    group_name       VARCHAR(100)  NOT NULL,
    group_color      CHAR(7)       NOT NULL DEFAULT '#e74c3c',
    route_ids        JSON          NOT NULL,
    sort_order       INT           NOT NULL DEFAULT 0,
    created_by       VARCHAR(20)   DEFAULT NULL,
    created_at       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_play_id (play_id),
    FOREIGN KEY (play_id) REFERENCES playbook_plays(play_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
