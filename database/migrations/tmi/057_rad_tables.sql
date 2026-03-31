-- Migration 057: Route Amendment Dialogue tables
-- rad_amendments: amendment lifecycle tracking
-- rad_amendment_log: audit trail of status transitions

CREATE TABLE dbo.rad_amendments (
    id              INT IDENTITY(1,1) PRIMARY KEY,
    gufi            UNIQUEIDENTIFIER NOT NULL,
    callsign        VARCHAR(10) NOT NULL,
    origin          CHAR(4) NOT NULL,
    destination     CHAR(4) NOT NULL,
    original_route  VARCHAR(MAX),
    assigned_route  VARCHAR(MAX) NOT NULL,
    assigned_route_geojson VARCHAR(MAX),
    status          VARCHAR(10) NOT NULL DEFAULT 'DRAFT',
    rrstat          VARCHAR(10),
    tmi_reroute_id  INT NULL,
    tmi_id_label    VARCHAR(20),
    delivery_channels VARCHAR(50),
    cpdlc_message_id VARCHAR(50),
    route_color     VARCHAR(10),
    created_by      INT,
    created_utc     DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
    sent_utc        DATETIME2,
    delivered_utc   DATETIME2,
    resolved_utc    DATETIME2,
    expires_utc     DATETIME2,
    notes           VARCHAR(500),
    CONSTRAINT CK_rad_status CHECK (status IN ('DRAFT','SENT','DLVD','ACPT','RJCT','EXPR'))
);

CREATE INDEX IX_rad_amendments_gufi ON dbo.rad_amendments (gufi);
CREATE INDEX IX_rad_amendments_status ON dbo.rad_amendments (status)
    INCLUDE (gufi, callsign, origin, destination, assigned_route, rrstat, sent_utc)
    WHERE status NOT IN ('ACPT','RJCT','EXPR');
CREATE INDEX IX_rad_amendments_tmi ON dbo.rad_amendments (tmi_reroute_id)
    WHERE tmi_reroute_id IS NOT NULL;

CREATE TABLE dbo.rad_amendment_log (
    id              INT IDENTITY(1,1) PRIMARY KEY,
    amendment_id    INT NOT NULL,
    status_from     VARCHAR(10),
    status_to       VARCHAR(10) NOT NULL,
    detail          VARCHAR(500),
    changed_by      INT,
    changed_utc     DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
    CONSTRAINT FK_rad_log_amendment FOREIGN KEY (amendment_id)
        REFERENCES dbo.rad_amendments(id) ON DELETE CASCADE
);

CREATE INDEX IX_rad_log_amendment ON dbo.rad_amendment_log (amendment_id);

-- New columns on adl_flight_tmi (cross-DB logical reference to rad_amendments.id)
-- Run this against VATSIM_ADL database
-- ALTER TABLE dbo.adl_flight_tmi ADD rad_amendment_id INT NULL;
-- ALTER TABLE dbo.adl_flight_tmi ADD rad_assigned_route VARCHAR(MAX) NULL;
