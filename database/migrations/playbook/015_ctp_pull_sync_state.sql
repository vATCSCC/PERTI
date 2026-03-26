-- ============================================================================
-- Migration: 015_ctp_pull_sync_state.sql
-- Database:  perti_site (MySQL 8)
-- Purpose:   State tracking for CTP pull-based sync (content hash, revision)
-- Depends:   014_ctp_external_fields.sql
-- ============================================================================

CREATE TABLE IF NOT EXISTS ctp_pull_sync_state (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    session_id      INT NOT NULL,
    event_code      VARCHAR(20) NOT NULL DEFAULT '',
    content_hash    VARCHAR(32) NULL DEFAULT NULL,
    synthetic_rev   INT NOT NULL DEFAULT 0,
    route_count     INT NOT NULL DEFAULT 0,
    last_sync_at    DATETIME NULL DEFAULT NULL,
    last_check_at   DATETIME NULL DEFAULT NULL,
    last_error      TEXT NULL DEFAULT NULL,
    status          ENUM('idle','syncing','error') NOT NULL DEFAULT 'idle',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_session (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
