-- ============================================================================
-- Migration 002: vNAS Restrictions & Auto ATC Rules
-- Source: restrictions[] and autoAtcRules[] from CRC ARTCC JSON files
-- Design: docs/plans/2026-04-07-vnas-reference-sync-design.md
-- ============================================================================

-- 1. vnas_restrictions (1,836 rows)
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'vnas_restrictions')
BEGIN
    CREATE TABLE dbo.vnas_restrictions (
        restriction_id            NVARCHAR(40)  NOT NULL PRIMARY KEY,
        parent_artcc              NVARCHAR(4)   NOT NULL,
        owning_facility_id        NVARCHAR(8)   NOT NULL,
        owning_sector_ids         NVARCHAR(MAX) NULL,
        requesting_facility_id    NVARCHAR(8)   NULL,
        requesting_sector_ids     NVARCHAR(MAX) NULL,
        route                     NVARCHAR(200) NULL,
        applicable_airports       NVARCHAR(MAX) NULL,
        applicable_aircraft_types NVARCHAR(MAX) NULL,
        flight_type               NVARCHAR(20)  NULL,
        flow                      NVARCHAR(50)  NULL,
        group_name                NVARCHAR(100) NULL,
        altitude_type             NVARCHAR(30)  NULL,
        altitude_values           NVARCHAR(MAX) NULL,
        speed_type                NVARCHAR(20)  NULL,
        speed_values              NVARCHAR(MAX) NULL,
        speed_units               NVARCHAR(10)  NULL,
        heading_type              NVARCHAR(20)  NULL,
        heading_values            NVARCHAR(MAX) NULL,
        location_type             NVARCHAR(10)  NULL,
        location_value            NVARCHAR(20)  NULL,
        notes_json                NVARCHAR(MAX) NULL,
        display_order             INT           NOT NULL DEFAULT 0,
        imported_utc              DATETIME2     NOT NULL DEFAULT SYSUTCDATETIME()
    );
    CREATE INDEX IX_vnas_restrictions_artcc ON dbo.vnas_restrictions (parent_artcc);
    CREATE INDEX IX_vnas_restrictions_owning ON dbo.vnas_restrictions (owning_facility_id);
    CREATE INDEX IX_vnas_restrictions_airports ON dbo.vnas_restrictions (parent_artcc) INCLUDE (applicable_airports, flight_type);
    PRINT 'Created table: dbo.vnas_restrictions';
END
GO

-- 2. vnas_auto_atc_rules (1,188 rows)
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'vnas_auto_atc_rules')
BEGIN
    CREATE TABLE dbo.vnas_auto_atc_rules (
        rule_id                       NVARCHAR(32)  NOT NULL PRIMARY KEY,
        parent_artcc                  NVARCHAR(4)   NOT NULL,
        rule_name                     NVARCHAR(100) NOT NULL,
        status                        NVARCHAR(16)  NOT NULL,
        position_ulid                 NVARCHAR(32)  NULL,
        route_substrings              NVARCHAR(MAX) NULL,
        exclude_route_substrings      NVARCHAR(MAX) NULL,
        departure_airports            NVARCHAR(MAX) NULL,
        destination_airports          NVARCHAR(MAX) NULL,
        min_altitude                  INT           NULL,
        max_altitude                  INT           NULL,
        applicable_jets               BIT NOT NULL DEFAULT 0,
        applicable_turboprops         BIT NOT NULL DEFAULT 0,
        applicable_props              BIT NOT NULL DEFAULT 0,
        descent_crossing_line_json    NVARCHAR(MAX) NULL,
        descent_altitude_value        INT           NULL,
        descent_altitude_type         NVARCHAR(10)  NULL,
        descent_transition_level      INT           NULL,
        descent_is_lufl               BIT           NULL,
        descent_lufl_station_id       NVARCHAR(4)   NULL,
        descent_altimeter_station     NVARCHAR(8)   NULL,
        descent_altimeter_name        NVARCHAR(50)  NULL,
        descent_speed_value           INT           NULL,
        descent_speed_is_mach         BIT           NULL,
        descent_speed_type            NVARCHAR(16)  NULL,
        crossing_fix                  NVARCHAR(10)  NULL,
        crossing_fix_name             NVARCHAR(20)  NULL,
        crossing_altitude_value       INT           NULL,
        crossing_altitude_type        NVARCHAR(10)  NULL,
        crossing_transition_level     INT           NULL,
        crossing_is_lufl              BIT           NULL,
        crossing_altimeter_station    NVARCHAR(8)   NULL,
        crossing_altimeter_name       NVARCHAR(50)  NULL,
        descend_via_star_name         NVARCHAR(20)  NULL,
        descend_via_crossing_line_json NVARCHAR(MAX) NULL,
        descend_via_altimeter_station NVARCHAR(8)   NULL,
        descend_via_altimeter_name    NVARCHAR(50)  NULL,
        precursor_rule_ids            NVARCHAR(MAX) NULL,
        exclusionary_rule_ids         NVARCHAR(MAX) NULL,
        imported_utc                  DATETIME2     NOT NULL DEFAULT SYSUTCDATETIME()
    );
    CREATE INDEX IX_vnas_auto_atc_parent ON dbo.vnas_auto_atc_rules (parent_artcc, status);
    CREATE INDEX IX_vnas_auto_atc_position ON dbo.vnas_auto_atc_rules (position_ulid);
    PRINT 'Created table: dbo.vnas_auto_atc_rules';
END
GO
