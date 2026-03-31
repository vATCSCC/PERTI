-- Migration 034: swim_route_stats — pre-aggregated historical route statistics
-- Source: MySQL perti_site.route_history_facts + dim_route + dim_aircraft_type + dim_operator
-- Sync: swim_tmi_sync_daemon.php Tier 2, daily full replace

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'swim_route_stats')
BEGIN
    CREATE TABLE dbo.swim_route_stats (
        stat_id          INT IDENTITY(1,1) PRIMARY KEY,
        origin_icao      NVARCHAR(4)   NOT NULL,
        dest_icao        NVARCHAR(4)   NOT NULL,
        route_hash       BINARY(16)    NOT NULL,
        normalized_route NVARCHAR(MAX) NOT NULL,
        flight_count     INT           NOT NULL,
        usage_pct        DECIMAL(5,2)  NOT NULL,
        avg_altitude_ft  INT           NULL,
        common_aircraft  NVARCHAR(200) NULL,
        common_operators NVARCHAR(200) NULL,
        first_seen       DATE          NOT NULL,
        last_seen        DATE          NOT NULL,
        last_sync_utc    DATETIME2(0)  NOT NULL DEFAULT SYSUTCDATETIME()
    );

    CREATE UNIQUE INDEX IX_route_stats_pair_hash
        ON dbo.swim_route_stats(origin_icao, dest_icao, route_hash);

    CREATE INDEX IX_route_stats_pair_count
        ON dbo.swim_route_stats(origin_icao, dest_icao, flight_count DESC)
        INCLUDE (normalized_route, usage_pct, last_seen);

    PRINT 'Created swim_route_stats with indexes';
END
ELSE
    PRINT 'swim_route_stats already exists — skipping';
GO
