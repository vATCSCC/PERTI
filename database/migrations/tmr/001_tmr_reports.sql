-- TMR Reports table for Traffic Management Review workflow
-- Database: perti_site (MySQL 8)
-- One report per PERTI plan (UNIQUE on p_id)
-- Note: Planned for VATSIM_ADL (Azure SQL) but adl_api_user lacks DDL permissions.
-- Deployed to perti_site instead. Uses PDO ($conn_pdo) connection.

CREATE TABLE IF NOT EXISTS r_tmr_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    p_id INT NOT NULL,

    -- Header
    host_artcc VARCHAR(10) NULL,

    -- TMR Triggers (JSON array of trigger keys)
    tmr_triggers JSON NULL,

    -- Overview
    overview TEXT NULL,

    -- Airport Conditions
    airport_conditions TEXT NULL,
    airport_config_correct TINYINT(1) NULL,

    -- Weather
    weather_category VARCHAR(20) NULL,
    weather_summary TEXT NULL,

    -- Special Events
    special_events TEXT NULL,

    -- TMIs
    tmi_list JSON NULL,
    tmi_source VARCHAR(20) DEFAULT 'manual',
    tmi_complied TINYINT(1) NULL,
    tmi_complied_details TEXT NULL,
    tmi_effective TINYINT(1) NULL,
    tmi_effective_details TEXT NULL,
    tmi_timely TINYINT(1) NULL,
    tmi_timely_details TEXT NULL,

    -- Equipment
    equipment TEXT NULL,

    -- Personnel
    personnel_adequate TINYINT(1) NULL,
    personnel_details TEXT NULL,

    -- Operational Plan
    operational_plan_link VARCHAR(500) NULL,

    -- Findings & Recommendations
    findings TEXT NULL,
    recommendations TEXT NULL,

    -- Metadata
    status VARCHAR(20) DEFAULT 'draft',
    created_by VARCHAR(20) NULL,
    updated_by VARCHAR(20) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_p_id (p_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
