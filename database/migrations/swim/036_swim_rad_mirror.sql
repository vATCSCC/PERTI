-- Migration 036: SWIM RAD mirror + API key feature gating
-- swim_rad_amendments: mirror of rad_amendments, synced by swim_tmi_sync_daemon.php
-- swim_api_keys.allowed_features: JSON array for feature-level gating

CREATE TABLE dbo.swim_rad_amendments (
    id              INT PRIMARY KEY,
    gufi            UNIQUEIDENTIFIER NOT NULL,
    gufi_legacy     NVARCHAR(64) NULL,
    callsign        VARCHAR(10) NOT NULL,
    origin          CHAR(4) NOT NULL,
    destination     CHAR(4) NOT NULL,
    original_route  VARCHAR(MAX),
    assigned_route  VARCHAR(MAX) NOT NULL,
    assigned_route_geojson VARCHAR(MAX),
    status          VARCHAR(10) NOT NULL,
    rrstat          VARCHAR(10),
    tmi_reroute_id  INT NULL,
    tmi_id_label    VARCHAR(20),
    delivery_channels VARCHAR(50),
    route_color     VARCHAR(10),
    created_by      INT,
    created_utc     DATETIME2,
    sent_utc        DATETIME2,
    delivered_utc   DATETIME2,
    resolved_utc    DATETIME2,
    expires_utc     DATETIME2,
    notes           VARCHAR(500),
    synced_utc      DATETIME2 NULL DEFAULT SYSUTCDATETIME()
);

CREATE INDEX IX_swim_rad_gufi ON dbo.swim_rad_amendments (gufi);
CREATE INDEX IX_swim_rad_status ON dbo.swim_rad_amendments (status);

-- Feature gating column on swim_api_keys
-- NULL = all features allowed. JSON array = restricted to listed features.
ALTER TABLE dbo.swim_api_keys ADD allowed_features NVARCHAR(MAX) NULL;
