-- ============================================================================
-- perti_site (MySQL) Migration 010: CDM Support Tables
-- CID-to-Discord link for DM delivery + Operations Plan sections
-- ============================================================================
-- Version: 1.0.0
-- Date: 2026-03-05
-- Author: HP/Claude
--
-- Tables:
--   1. user_discord_link   - CID ↔ Discord user mapping for CDM notifications
--   2. p_ops_plan          - Operations Plan sections (outlook, triggers, etc.)
-- ============================================================================

-- ============================================================================
-- TABLE 1: user_discord_link
-- ============================================================================
-- Maps VATSIM CIDs to Discord user IDs for CDM notification delivery.
-- Discord DM is the lowest-priority fallback channel for EDCT delivery.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `user_discord_link` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `cid` INT NOT NULL,
    `discord_user_id` VARCHAR(20) NOT NULL,
    `discord_username` VARCHAR(100) NULL,
    `cdm_notifications` TINYINT(1) NOT NULL DEFAULT 1,
    `notification_types` VARCHAR(200) NULL COMMENT 'Comma-separated: edct,gate_hold,slot_update,cancel',
    `linked_utc` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_cid` (`cid`),
    UNIQUE KEY `uk_discord` (`discord_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- TABLE 2: p_ops_plan
-- ============================================================================
-- Operations Plan sections linked to PERTI event plans.
-- Replaces FAA Planning Telcon structured documents with digital plan.
-- Sections: outlook, config, initiative, trigger, coordination, contingency
-- ============================================================================

CREATE TABLE IF NOT EXISTS `p_ops_plan` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `p_id` INT NOT NULL COMMENT 'FK → p_plans.id',
    `section_type` ENUM('outlook', 'config', 'initiative', 'trigger', 'coordination', 'contingency') NOT NULL,
    `section_order` INT NOT NULL DEFAULT 0,
    `title` VARCHAR(200) NULL,
    `content` TEXT NULL,

    -- Trigger-specific fields (only for section_type = trigger)
    `trigger_condition` TEXT NULL COMMENT 'Human-readable condition description',
    `trigger_action` TEXT NULL COMMENT 'Human-readable action description',
    `trigger_threshold` VARCHAR(100) NULL COMMENT 'Threshold value (e.g., "demand > AAR * 1.2")',
    `trigger_id` INT NULL COMMENT 'FK → VATSIM_TMI.cdm_triggers.trigger_id (when wired)',

    -- State
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `activated_at` TIMESTAMP NULL,
    `deactivated_at` TIMESTAMP NULL,

    -- Metadata
    `created_by` INT NULL COMMENT 'CID of creator',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX `idx_plan_section` (`p_id`, `section_type`, `section_order`),
    INDEX `idx_active_triggers` (`section_type`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- TABLE 3: cdm_notification_log (MySQL)
-- ============================================================================
-- Lightweight log of CDM notifications sent via Discord DM.
-- Separate from Azure SQL cdm_messages to avoid cross-database writes.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `cdm_notification_log` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `cid` INT NOT NULL,
    `callsign` VARCHAR(12) NOT NULL,
    `notification_type` VARCHAR(20) NOT NULL COMMENT 'edct, gate_hold, slot_update, cancel',
    `channel` VARCHAR(20) NOT NULL DEFAULT 'discord' COMMENT 'discord, email (future)',
    `discord_message_id` VARCHAR(20) NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'sent' COMMENT 'sent, failed, rate_limited',
    `error_detail` VARCHAR(500) NULL,
    `sent_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_cid_type` (`cid`, `notification_type`, `sent_at`),
    INDEX `idx_callsign` (`callsign`, `sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
