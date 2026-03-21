-- Migration 030: SWIM NAT Track Metrics + Throughput
-- Database: SWIM_API
-- Run as: jpeterson (DDL admin)

-- ============================================================================
-- 4.2: New columns on swim_flights
-- ============================================================================
ALTER TABLE dbo.swim_flights ADD
    resolved_nat_track      NVARCHAR(8) NULL,
    nat_track_resolved_at   DATETIME2(0) NULL,
    nat_track_source        NVARCHAR(8) NULL;
GO

CREATE NONCLUSTERED INDEX IX_swim_flights_nat_track
    ON dbo.swim_flights(resolved_nat_track)
    WHERE resolved_nat_track IS NOT NULL AND is_active = 1
    INCLUDE (flight_uid, callsign, dep_airport, arr_airport);
GO

-- ============================================================================
-- 4.4: swim_nat_track_metrics
-- ============================================================================
CREATE TABLE dbo.swim_nat_track_metrics (
    metric_id               BIGINT IDENTITY(1,1) PRIMARY KEY,
    session_id              INT NOT NULL,
    track_name              NVARCHAR(8) NOT NULL,
    bin_start_utc           DATETIME2(0) NOT NULL,
    bin_end_utc             DATETIME2(0) NOT NULL,
    flight_count            INT NOT NULL DEFAULT 0,
    slotted_count           INT NOT NULL DEFAULT 0,
    compliant_count         INT NOT NULL DEFAULT 0,
    avg_delay_min           FLOAT NULL,
    peak_rate_hr            INT NULL,
    direction               NVARCHAR(8) NULL,
    flight_levels_json      NVARCHAR(256) NULL,
    origins_json            NVARCHAR(MAX) NULL,
    destinations_json       NVARCHAR(MAX) NULL,
    source                  NVARCHAR(16) NOT NULL DEFAULT 'CTP',
    computed_at             DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),

    CONSTRAINT UQ_swim_ntm_session_track_bin
        UNIQUE (session_id, track_name, bin_start_utc),
    CONSTRAINT CK_swim_ntm_direction
        CHECK (direction IS NULL OR direction IN ('WESTBOUND', 'EASTBOUND'))
);
GO

CREATE NONCLUSTERED INDEX IX_swim_ntm_session_time
    ON dbo.swim_nat_track_metrics(session_id, bin_start_utc);
GO

-- ============================================================================
-- 4.5: swim_nat_track_throughput
-- ============================================================================
CREATE TABLE dbo.swim_nat_track_throughput (
    throughput_id           BIGINT IDENTITY(1,1) PRIMARY KEY,
    session_id              INT NOT NULL,
    config_id               INT NOT NULL,
    config_label            NVARCHAR(64) NULL,
    tracks_json             NVARCHAR(MAX) NULL,
    origins_json            NVARCHAR(MAX) NULL,
    destinations_json       NVARCHAR(MAX) NULL,
    bin_start_utc           DATETIME2(0) NOT NULL,
    bin_end_utc             DATETIME2(0) NOT NULL,
    max_acph                INT NOT NULL,
    actual_count            INT NOT NULL DEFAULT 0,
    actual_rate_hr          INT NULL,
    utilization_pct         FLOAT NULL,
    computed_at             DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),

    CONSTRAINT UQ_swim_ntt_session_config_bin
        UNIQUE (session_id, config_id, bin_start_utc)
);
GO

-- ============================================================================
-- 4.7: CTP flow provider registration
-- ============================================================================
INSERT INTO dbo.swim_tmi_flow_providers (
    provider_id, provider_code, provider_name, api_base_url,
    auth_type, sync_enabled, is_active, priority, created_at, updated_at
)
VALUES (
    (SELECT ISNULL(MAX(provider_id), 0) + 1 FROM dbo.swim_tmi_flow_providers),
    'CTP', 'Cross the Pond', 'https://perti.vatcscc.org/api/ctp',
    'session', 0, 1, 10, SYSUTCDATETIME(), SYSUTCDATETIME()
);
GO

-- ============================================================================
-- Sync state watermarks for CTP tables
-- ============================================================================
INSERT INTO dbo.swim_sync_state (table_name, last_sync_utc, last_row_count, sync_mode)
VALUES ('ctp_nat_track_metrics', '2000-01-01', 0, 'delta');
GO

INSERT INTO dbo.swim_sync_state (table_name, last_sync_utc, last_row_count, sync_mode)
VALUES ('ctp_nat_track_throughput', '2000-01-01', 0, 'delta');
GO
