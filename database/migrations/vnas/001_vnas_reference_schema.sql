-- ============================================================================
-- Migration 001: vNAS Reference Data Schema
-- Creates tables for facility hierarchy, positions, STARS TCPs/areas,
-- beacon banks, transceivers, video map index, airport groups, common URLs,
-- and sync metadata imported from CRC local ARTCC JSON files.
--
-- Source: 24 ARTCC JSON files at %LOCALAPPDATA%/CRC/ARTCCs/*.json
-- Design: docs/plans/2026-04-07-vnas-reference-sync-design.md
-- ============================================================================

-- 1. vnas_facilities (782 rows)
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'vnas_facilities')
BEGIN
    CREATE TABLE dbo.vnas_facilities (
        facility_id               NVARCHAR(8)   NOT NULL PRIMARY KEY,
        facility_name             NVARCHAR(100) NOT NULL,
        facility_type             NVARCHAR(16)  NOT NULL,
        parent_artcc              NVARCHAR(4)   NOT NULL,
        parent_facility_id        NVARCHAR(8)   NULL,
        hierarchy_depth           SMALLINT      NOT NULL DEFAULT 0,
        neighboring_facility_ids  NVARCHAR(MAX) NULL,
        non_nas_facility_ids      NVARCHAR(MAX) NULL,
        has_eram                  BIT NOT NULL DEFAULT 0,
        has_stars                 BIT NOT NULL DEFAULT 0,
        has_flight_strips         BIT NOT NULL DEFAULT 0,
        has_tower_cab             BIT NOT NULL DEFAULT 0,
        has_asdex                 BIT NOT NULL DEFAULT 0,
        has_tdls                  BIT NOT NULL DEFAULT 0,
        eram_config_json          NVARCHAR(MAX) NULL,
        stars_config_json         NVARCHAR(MAX) NULL,
        flight_strips_json        NVARCHAR(MAX) NULL,
        tower_cab_json            NVARCHAR(MAX) NULL,
        asdex_config_json         NVARCHAR(MAX) NULL,
        tdls_config_json          NVARCHAR(MAX) NULL,
        visibility_centers_json   NVARCHAR(MAX) NULL,
        aliases_updated_at        DATETIME2     NULL,
        source_artcc              NVARCHAR(4)   NOT NULL,
        source_updated_at         DATETIME2     NULL,
        imported_utc              DATETIME2     NOT NULL DEFAULT SYSUTCDATETIME()
    );
    CREATE INDEX IX_vnas_facilities_parent ON dbo.vnas_facilities (parent_artcc, facility_type);
    CREATE INDEX IX_vnas_facilities_type ON dbo.vnas_facilities (facility_type);
    PRINT 'Created table: dbo.vnas_facilities';
END
GO

-- 2. vnas_positions (3,990 rows)
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'vnas_positions')
BEGIN
    CREATE TABLE dbo.vnas_positions (
        position_ulid             NVARCHAR(32)  NOT NULL PRIMARY KEY,
        facility_id               NVARCHAR(8)   NOT NULL,
        parent_artcc              NVARCHAR(4)   NOT NULL,
        position_name             NVARCHAR(50)  NOT NULL,
        callsign                  NVARCHAR(20)  NOT NULL,
        radio_name                NVARCHAR(50)  NULL,
        frequency_hz              INT           NOT NULL,
        starred                   BIT           NOT NULL DEFAULT 0,
        eram_sector_id            NVARCHAR(8)   NULL,
        stars_area_id             NVARCHAR(32)  NULL,
        stars_tcp_id              NVARCHAR(32)  NULL,
        stars_color_set           NVARCHAR(8)   NULL,
        transceiver_ids_json      NVARCHAR(MAX) NULL,
        imported_utc              DATETIME2     NOT NULL DEFAULT SYSUTCDATETIME()
    );
    CREATE INDEX IX_vnas_positions_facility ON dbo.vnas_positions (facility_id);
    CREATE INDEX IX_vnas_positions_artcc ON dbo.vnas_positions (parent_artcc);
    CREATE INDEX IX_vnas_positions_callsign ON dbo.vnas_positions (callsign);
    PRINT 'Created table: dbo.vnas_positions';
END
GO

-- 3. vnas_stars_tcps (1,949 rows)
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'vnas_stars_tcps')
BEGIN
    CREATE TABLE dbo.vnas_stars_tcps (
        tcp_id                    NVARCHAR(32)  NOT NULL PRIMARY KEY,
        facility_id               NVARCHAR(8)   NOT NULL,
        parent_artcc              NVARCHAR(4)   NOT NULL,
        subset                    SMALLINT      NOT NULL,
        sector_id                 NVARCHAR(4)   NOT NULL,
        parent_tcp_id             NVARCHAR(32)  NULL,
        terminal_sector           NVARCHAR(2)   NULL,
        imported_utc              DATETIME2     NOT NULL DEFAULT SYSUTCDATETIME()
    );
    CREATE INDEX IX_vnas_stars_tcps_facility ON dbo.vnas_stars_tcps (facility_id, sector_id);
    CREATE INDEX IX_vnas_stars_tcps_parent ON dbo.vnas_stars_tcps (parent_tcp_id);
    PRINT 'Created table: dbo.vnas_stars_tcps';
END
GO

-- 4. vnas_stars_areas (647 rows)
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'vnas_stars_areas')
BEGIN
    CREATE TABLE dbo.vnas_stars_areas (
        area_id                   NVARCHAR(32)  NOT NULL PRIMARY KEY,
        facility_id               NVARCHAR(8)   NOT NULL,
        parent_artcc              NVARCHAR(4)   NOT NULL,
        area_name                 NVARCHAR(50)  NOT NULL,
        visibility_lat            FLOAT         NULL,
        visibility_lon            FLOAT         NULL,
        surveillance_range        INT           NULL,
        ldb_beacon_codes_inhibited    BIT NULL,
        pdb_ground_speed_inhibited    BIT NULL,
        display_requested_alt_in_fdb  BIT NULL,
        use_vfr_position_symbol       BIT NULL,
        show_dest_departures          BIT NULL,
        show_dest_satellite_arrivals  BIT NULL,
        show_dest_primary_arrivals    BIT NULL,
        underlying_airports_json      NVARCHAR(MAX) NULL,
        ssa_airports_json             NVARCHAR(MAX) NULL,
        tower_list_configs_json       NVARCHAR(MAX) NULL,
        imported_utc              DATETIME2     NOT NULL DEFAULT SYSUTCDATETIME()
    );
    CREATE INDEX IX_vnas_stars_areas_facility ON dbo.vnas_stars_areas (facility_id);
    PRINT 'Created table: dbo.vnas_stars_areas';
END
GO

-- 5. vnas_beacon_banks (781 rows)
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'vnas_beacon_banks')
BEGIN
    CREATE TABLE dbo.vnas_beacon_banks (
        bank_id                   NVARCHAR(32)  NOT NULL PRIMARY KEY,
        facility_id               NVARCHAR(8)   NOT NULL,
        parent_artcc              NVARCHAR(4)   NOT NULL,
        source_system             NVARCHAR(8)   NOT NULL,
        category                  NVARCHAR(16)  NULL,
        priority                  NVARCHAR(16)  NULL,
        subset                    INT           NULL,
        start_code                INT           NOT NULL,
        end_code                  INT           NOT NULL,
        imported_utc              DATETIME2     NOT NULL DEFAULT SYSUTCDATETIME()
    );
    CREATE INDEX IX_vnas_beacon_banks_facility ON dbo.vnas_beacon_banks (facility_id, source_system);
    PRINT 'Created table: dbo.vnas_beacon_banks';
END
GO

-- 6. vnas_transceivers (~1,526 rows)
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'vnas_transceivers')
BEGIN
    CREATE TABLE dbo.vnas_transceivers (
        transceiver_id            NVARCHAR(40)  NOT NULL PRIMARY KEY,
        parent_artcc              NVARCHAR(4)   NOT NULL,
        transceiver_name          NVARCHAR(80)  NOT NULL,
        lat                       FLOAT         NOT NULL,
        lon                       FLOAT         NOT NULL,
        height_msl_meters         INT           NULL,
        height_agl_meters         INT           NULL,
        imported_utc              DATETIME2     NOT NULL DEFAULT SYSUTCDATETIME()
    );
    CREATE INDEX IX_vnas_transceivers_artcc ON dbo.vnas_transceivers (parent_artcc);
    PRINT 'Created table: dbo.vnas_transceivers';
END
GO

-- 7. vnas_video_map_index (15,007 rows)
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'vnas_video_map_index')
BEGIN
    CREATE TABLE dbo.vnas_video_map_index (
        map_id                    NVARCHAR(32)  NOT NULL PRIMARY KEY,
        parent_artcc              NVARCHAR(4)   NOT NULL,
        map_name                  NVARCHAR(500) NOT NULL,
        short_name                NVARCHAR(50)  NULL,
        stars_id                  NVARCHAR(16)  NULL,
        tags_json                 NVARCHAR(MAX) NULL,
        source_file_name          NVARCHAR(500) NULL,
        stars_brightness_category NVARCHAR(20)  NULL,
        stars_always_visible      BIT           NULL,
        tdm_only                  BIT           NULL,
        last_updated_at           DATETIME2     NULL,
        imported_utc              DATETIME2     NOT NULL DEFAULT SYSUTCDATETIME()
    );
    CREATE INDEX IX_vnas_video_map_artcc ON dbo.vnas_video_map_index (parent_artcc);
    PRINT 'Created table: dbo.vnas_video_map_index';
END
GO

-- 8. vnas_airport_groups (69 rows)
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'vnas_airport_groups')
BEGIN
    CREATE TABLE dbo.vnas_airport_groups (
        group_id                  NVARCHAR(32)  NOT NULL PRIMARY KEY,
        parent_artcc              NVARCHAR(4)   NOT NULL,
        group_name                NVARCHAR(50)  NOT NULL,
        airport_ids_json          NVARCHAR(MAX) NOT NULL,
        imported_utc              DATETIME2     NOT NULL DEFAULT SYSUTCDATETIME()
    );
    PRINT 'Created table: dbo.vnas_airport_groups';
END
GO

-- 9. vnas_common_urls (88 rows)
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'vnas_common_urls')
BEGIN
    CREATE TABLE dbo.vnas_common_urls (
        url_id                    NVARCHAR(32)  NOT NULL PRIMARY KEY,
        parent_artcc              NVARCHAR(4)   NOT NULL,
        url_name                  NVARCHAR(100) NOT NULL,
        url                       NVARCHAR(500) NOT NULL,
        imported_utc              DATETIME2     NOT NULL DEFAULT SYSUTCDATETIME()
    );
    PRINT 'Created table: dbo.vnas_common_urls';
END
GO

-- 10. vnas_sync_metadata (24 rows)
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'vnas_sync_metadata')
BEGIN
    CREATE TABLE dbo.vnas_sync_metadata (
        artcc_code                NVARCHAR(4)   NOT NULL PRIMARY KEY,
        source_updated_at         DATETIME2     NULL,
        last_import_utc           DATETIME2     NULL,
        facilities_count          INT           NULL,
        positions_count           INT           NULL,
        restrictions_count        INT           NULL,
        auto_atc_rules_count      INT           NULL,
        import_duration_ms        INT           NULL,
        import_status             NVARCHAR(20)  NULL
    );
    PRINT 'Created table: dbo.vnas_sync_metadata';
END
GO
