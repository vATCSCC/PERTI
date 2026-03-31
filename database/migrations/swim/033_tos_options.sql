-- Migration 033: TOS (Trajectory Option Set) options table
-- Stores pilot-filed route preferences for TMI automation.
-- Pilots submit ranked route options; the system can later assign one.

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'tos_options')
CREATE TABLE dbo.tos_options (
    tos_id          INT IDENTITY(1,1) PRIMARY KEY,
    flight_uid      BIGINT NOT NULL,
    callsign        VARCHAR(10) NOT NULL,
    departure       VARCHAR(4) NOT NULL,
    destination     VARCHAR(4) NOT NULL,
    option_rank     TINYINT NOT NULL,           -- 1 = most preferred
    route_string    VARCHAR(1024) NOT NULL,
    flight_time_min SMALLINT NULL,              -- estimated flight time in minutes
    fuel_penalty_pct DECIMAL(5,2) NULL,         -- fuel cost % vs preferred
    status          VARCHAR(16) NOT NULL DEFAULT 'FILED',  -- FILED, ASSIGNED, REJECTED, EXPIRED
    assigned_by     VARCHAR(32) NULL,           -- facility or system that assigned
    assigned_at     DATETIME2 NULL,
    filed_at        DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
    expires_at      DATETIME2 NULL,
    CONSTRAINT FK_tos_flight FOREIGN KEY (flight_uid) REFERENCES dbo.swim_flights(flight_uid)
);

-- Indexes
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_tos_flight' AND object_id = OBJECT_ID('dbo.tos_options'))
    CREATE INDEX IX_tos_flight ON dbo.tos_options (flight_uid, option_rank);

IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_tos_callsign' AND object_id = OBJECT_ID('dbo.tos_options'))
    CREATE INDEX IX_tos_callsign ON dbo.tos_options (callsign, status);

IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_tos_expires' AND object_id = OBJECT_ID('dbo.tos_options'))
    CREATE INDEX IX_tos_expires ON dbo.tos_options (expires_at) WHERE expires_at IS NOT NULL;
