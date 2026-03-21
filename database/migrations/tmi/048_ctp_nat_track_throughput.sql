-- Migration 048: CTP NAT Track Throughput + Planning Tables
-- Database: VATSIM_TMI
-- Run as: jpeterson (DDL admin)
-- Spec: docs/superpowers/specs/2026-03-21-ctp-swim-nat-track-throughput-design.md

-- ============================================================================
-- 4.1: New columns on ctp_flight_control
-- ============================================================================
ALTER TABLE dbo.ctp_flight_control ADD
    resolved_nat_track      NVARCHAR(8) NULL,
    nat_track_resolved_at   DATETIME2(0) NULL,
    nat_track_source        NVARCHAR(8) NULL;
GO

CREATE NONCLUSTERED INDEX IX_ctp_fc_nat_track
    ON dbo.ctp_flight_control(session_id, resolved_nat_track)
    WHERE resolved_nat_track IS NOT NULL;
GO

-- ============================================================================
-- 4.3: ctp_track_throughput_config
-- ============================================================================
CREATE TABLE dbo.ctp_track_throughput_config (
    config_id               INT IDENTITY(1,1) PRIMARY KEY,
    session_id              INT NOT NULL,
    config_label            NVARCHAR(64) NOT NULL,
    tracks_json             NVARCHAR(MAX) NULL,
    origins_json            NVARCHAR(MAX) NULL,
    destinations_json       NVARCHAR(MAX) NULL,
    max_acph                INT NOT NULL,
    priority                INT NOT NULL DEFAULT 50,
    is_active               BIT NOT NULL DEFAULT 1,
    notes                   NVARCHAR(256) NULL,
    created_by              NVARCHAR(16) NULL,
    created_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    updated_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),

    CONSTRAINT FK_ctp_ttc_session FOREIGN KEY (session_id)
        REFERENCES dbo.ctp_sessions(session_id)
);
GO

CREATE NONCLUSTERED INDEX IX_ctp_ttc_session
    ON dbo.ctp_track_throughput_config(session_id, is_active)
    WHERE is_active = 1;
GO

CREATE NONCLUSTERED INDEX IX_ctp_ttc_session_priority
    ON dbo.ctp_track_throughput_config(session_id, priority)
    WHERE is_active = 1
    INCLUDE (config_label, max_acph, tracks_json, origins_json, destinations_json);
GO

-- ============================================================================
-- 4.6: Planning simulator tables
-- ============================================================================
CREATE TABLE dbo.ctp_planning_scenarios (
    scenario_id             INT IDENTITY(1,1) PRIMARY KEY,
    session_id              INT NULL,
    scenario_name           NVARCHAR(64) NOT NULL,
    departure_window_start  DATETIME2(0) NOT NULL,
    departure_window_end    DATETIME2(0) NOT NULL,
    status                  NVARCHAR(16) NOT NULL DEFAULT 'DRAFT',
    notes                   NVARCHAR(MAX) NULL,
    created_by              NVARCHAR(16) NULL,
    created_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    updated_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),

    CONSTRAINT CK_ctp_ps_status CHECK (status IN ('DRAFT', 'ACTIVE', 'ARCHIVED')),
    CONSTRAINT FK_ctp_ps_session FOREIGN KEY (session_id)
        REFERENCES dbo.ctp_sessions(session_id)
);
GO

CREATE TABLE dbo.ctp_planning_traffic_blocks (
    block_id                INT IDENTITY(1,1) PRIMARY KEY,
    scenario_id             INT NOT NULL,
    block_label             NVARCHAR(64) NULL,
    origins_json            NVARCHAR(MAX) NOT NULL,
    destinations_json       NVARCHAR(MAX) NOT NULL,
    flight_count            INT NOT NULL,
    dep_distribution        NVARCHAR(16) NOT NULL DEFAULT 'UNIFORM',
    dep_distribution_json   NVARCHAR(MAX) NULL,
    aircraft_mix_json       NVARCHAR(MAX) NULL,
    created_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    updated_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),

    CONSTRAINT CK_ctp_ptb_dist CHECK (dep_distribution IN ('UNIFORM','FRONT_LOADED','BACK_LOADED','CUSTOM')),
    CONSTRAINT FK_ctp_ptb_scenario FOREIGN KEY (scenario_id)
        REFERENCES dbo.ctp_planning_scenarios(scenario_id) ON DELETE CASCADE
);
GO

CREATE TABLE dbo.ctp_planning_track_assignments (
    assignment_id           INT IDENTITY(1,1) PRIMARY KEY,
    block_id                INT NOT NULL,
    track_name              NVARCHAR(8) NULL,
    route_string            NVARCHAR(MAX) NULL,
    flight_count            INT NOT NULL,
    altitude_range          NVARCHAR(32) NULL,
    created_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    updated_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),

    CONSTRAINT FK_ctp_pta_block FOREIGN KEY (block_id)
        REFERENCES dbo.ctp_planning_traffic_blocks(block_id) ON DELETE CASCADE
);
GO
