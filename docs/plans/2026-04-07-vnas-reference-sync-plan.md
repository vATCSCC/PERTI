# vNAS Reference Data Sync — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Import vNAS facility hierarchy, positions, restrictions, and Auto ATC rules from CRC local data into PERTI, then use them to enrich the live controller feed with sector staffing and consolidation detection.

**Architecture:** Python local agent watches CRC ARTCC JSON files for changes, POSTs to two PERTI API endpoints (`/ingest/vnas/facilities` and `/ingest/vnas/restrictions`). Server-side PHP imports into 13 new Azure SQL tables. Enhanced `vnas_controller_poll.php` uses imported position/TCP data to detect staffing, consolidation, and top-down coverage.

**Tech Stack:** Python 3.12 (watchdog + requests), PHP 8.2, Azure SQL (sqlsrv), existing SWIM auth/response framework.

**Design doc:** `docs/plans/2026-04-07-vnas-reference-sync-design.md`

---

## Task 1: Database Migration — Core Tables (7A)

Creates the 9 core tables for facility/position reference data.

**Files:**
- Create: `database/migrations/vnas/001_vnas_reference_schema.sql`

**Step 1: Write the migration SQL**

```sql
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
        terminal_sector           BIT           NULL,
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
        source_system             NVARCHAR(4)   NOT NULL,
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
        map_name                  NVARCHAR(100) NOT NULL,
        short_name                NVARCHAR(50)  NULL,
        stars_id                  NVARCHAR(16)  NULL,
        tags_json                 NVARCHAR(MAX) NULL,
        source_file_name          NVARCHAR(100) NULL,
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
```

**Step 2: Run migration against VATSIM_ADL**

Run with `jpeterson` admin credentials (adl_api_user lacks CREATE TABLE):
```bash
sqlcmd -S vatsim.database.windows.net -d VATSIM_ADL -U jpeterson -P Jhp21012 -i database/migrations/vnas/001_vnas_reference_schema.sql
```

Expected: 10 "Created table" messages.

**Step 3: Verify tables exist**

```sql
SELECT name FROM sys.tables WHERE name LIKE 'vnas_%' ORDER BY name;
```

Expected: 10 rows.

**Step 4: Commit**

```bash
git add database/migrations/vnas/001_vnas_reference_schema.sql
git commit -m "feat(vnas): add 10 core reference data tables (7A)"
```

---

## Task 2: Database Migration — Restrictions Tables (6B)

**Files:**
- Create: `database/migrations/vnas/002_vnas_restrictions_schema.sql`

**Step 1: Write the migration SQL**

```sql
-- ============================================================================
-- Migration 002: vNAS Restrictions & Auto ATC Rules
-- Source: restrictions[] and autoAtcRules[] from CRC ARTCC JSON files
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
```

**Step 2: Run migration**

```bash
sqlcmd -S vatsim.database.windows.net -d VATSIM_ADL -U jpeterson -P Jhp21012 -i database/migrations/vnas/002_vnas_restrictions_schema.sql
```

**Step 3: Commit**

```bash
git add database/migrations/vnas/002_vnas_restrictions_schema.sql
git commit -m "feat(vnas): add restrictions and auto ATC rules tables (6B)"
```

---

## Task 3: Database Migration — 2B Staffing & Mapping Tables

**Files:**
- Create: `database/migrations/vnas/003_vnas_staffing_mapping.sql`

**Step 1: Write the migration SQL**

```sql
-- ============================================================================
-- Migration 003: Staffing columns on adl_boundary + position/TCP sector maps
-- Enables 2B: enhanced controller feed parsing
-- ============================================================================

-- 1. Staffing columns on adl_boundary
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('dbo.adl_boundary') AND name = 'is_staffed')
BEGIN
    ALTER TABLE dbo.adl_boundary ADD is_staffed BIT NOT NULL DEFAULT 0;
    ALTER TABLE dbo.adl_boundary ADD staffed_by_cid INT NULL;
    ALTER TABLE dbo.adl_boundary ADD staffed_updated_utc DATETIME2 NULL;
    PRINT 'Added staffing columns to dbo.adl_boundary';
END
GO

-- 2. vnas_position_sector_map (maps position ULIDs to adl_boundary rows)
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'vnas_position_sector_map')
BEGIN
    CREATE TABLE dbo.vnas_position_sector_map (
        position_ulid             NVARCHAR(32)  NOT NULL,
        boundary_id               INT           NOT NULL,
        boundary_code             NVARCHAR(20)  NOT NULL,
        parent_artcc              NVARCHAR(4)   NOT NULL,
        sector_type               NVARCHAR(16)  NOT NULL,
        mapped_utc                DATETIME2     NOT NULL DEFAULT SYSUTCDATETIME(),
        CONSTRAINT PK_vnas_pos_sector PRIMARY KEY (position_ulid, boundary_id)
    );
    CREATE INDEX IX_vnas_pos_sector_boundary ON dbo.vnas_position_sector_map (boundary_id);
    PRINT 'Created table: dbo.vnas_position_sector_map';
END
GO

-- 3. vnas_tcp_sector_map (maps STARS TCP sectorIds to adl_boundary rows)
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'vnas_tcp_sector_map')
BEGIN
    CREATE TABLE dbo.vnas_tcp_sector_map (
        tcp_id                    NVARCHAR(32)  NOT NULL PRIMARY KEY,
        facility_id               NVARCHAR(8)   NOT NULL,
        sector_id                 NVARCHAR(4)   NOT NULL,
        boundary_id               INT           NULL,
        boundary_code             NVARCHAR(20)  NULL,
        parent_artcc              NVARCHAR(4)   NOT NULL,
        mapped_utc                DATETIME2     NOT NULL DEFAULT SYSUTCDATETIME()
    );
    CREATE INDEX IX_vnas_tcp_sector_facility ON dbo.vnas_tcp_sector_map (facility_id, sector_id);
    PRINT 'Created table: dbo.vnas_tcp_sector_map';
END
GO
```

**Step 2: Run migration**

```bash
sqlcmd -S vatsim.database.windows.net -d VATSIM_ADL -U jpeterson -P Jhp21012 -i database/migrations/vnas/003_vnas_staffing_mapping.sql
```

**Step 3: Commit**

```bash
git add database/migrations/vnas/003_vnas_staffing_mapping.sql
git commit -m "feat(vnas): add staffing columns and position-sector mapping tables (2B)"
```

---

## Task 4: Python Local Agent — CRC Parser

The core parser that reads CRC ARTCC JSON files and flattens the hierarchy into API-ready payloads.

**Files:**
- Create: `scripts/vnas_sync/crc_parser.py`
- Create: `scripts/vnas_sync/__init__.py`

**Step 1: Write the parser module**

Implement `parse_artcc_json(filepath)` that returns two dicts: `facilities_payload` and `restrictions_payload`. The parser must:
- Recursively walk `facility.childFacilities` to extract all 782 facilities with parent linkage and hierarchy depth
- Extract all positions from each facility, preserving ULID, callsign, freq, ERAM/STARS config
- Extract STARS TCPs from each facility's `starsConfiguration.tcps[]`
- Extract STARS areas from each facility's `starsConfiguration.areas[]`
- Collect beacon banks from both ERAM (`eramConfiguration.beaconCodeBanks`) and STARS (`starsConfiguration.beaconCodeBanks`) with `source_system` discriminator
- Collect transceivers from top-level `transceivers[]`
- Collect video map metadata from top-level `videoMaps[]`
- Collect airport groups from top-level `airportGroups[]`
- Collect common URLs from top-level `commonUrls[]`
- Store full configs as JSON: eram_config_json, stars_config_json (with TCPs/areas/banks stripped to avoid duplication), flight_strips_json, tower_cab_json, asdex_config_json, tdls_config_json
- Extract restrictions from top-level `restrictions[]`, flattening altitude/speed/heading/location sub-objects
- Extract Auto ATC rules from top-level `autoAtcRules[]`, flattening the three descent types (descentRestriction, descentCrossingRestriction, descendVia)

**Key data paths** (verified against ZDC.json):
- Position ERAM sector: `position['eramConfiguration']['sectorId']`
- Position STARS TCP: `position['starsConfiguration']['tcpId']`
- Position STARS area: `position['starsConfiguration']['areaId']`
- Position STARS color: `position['starsConfiguration']['colorSet']`
- TCP parent: `tcp['parentTcpId']` (741 of 1,949)
- TCP terminal: `tcp['terminalSector']` (946 of 1,949)
- TCP has fields: `id`, `subset`, `sectorId`, `parentTcpId`, `terminalSector`

**Step 2: Write a local smoke test**

Run the parser on ZDC.json and verify counts:
```bash
python -c "
from scripts.vnas_sync.crc_parser import parse_artcc_json
fac, rest = parse_artcc_json('C:/Users/jerem.DESKTOP-T926IG8/AppData/Local/CRC/ARTCCs/ZDC.json')
print(f'Facilities: {len(fac[\"facilities\"])}')  # expect 47
print(f'Positions: {len(fac[\"positions\"])}')  # expect 254
print(f'TCPs: {len(fac[\"stars_tcps\"])}')
print(f'Areas: {len(fac[\"stars_areas\"])}')
print(f'Restrictions: {len(rest[\"restrictions\"])}')  # expect 0 for ZDC
print(f'Auto ATC: {len(rest[\"auto_atc_rules\"])}')  # expect 118
"
```

**Step 3: Test on all 24 ARTCCs**

```bash
python -c "
from scripts.vnas_sync.crc_parser import parse_artcc_json
import os, json
artcc_dir = 'C:/Users/jerem.DESKTOP-T926IG8/AppData/Local/CRC/ARTCCs'
totals = {'f':0,'p':0,'t':0,'a':0,'r':0,'aar':0,'bb':0,'tx':0,'vm':0}
for fn in sorted(os.listdir(artcc_dir)):
    if not fn.endswith('.json'): continue
    fac, rest = parse_artcc_json(os.path.join(artcc_dir, fn))
    totals['f'] += len(fac['facilities'])
    totals['p'] += len(fac['positions'])
    totals['t'] += len(fac['stars_tcps'])
    totals['a'] += len(fac['stars_areas'])
    totals['r'] += len(rest['restrictions'])
    totals['aar'] += len(rest['auto_atc_rules'])
    totals['bb'] += len(fac['beacon_banks'])
    totals['tx'] += len(fac['transceivers'])
    totals['vm'] += len(fac['video_maps'])
print(json.dumps(totals, indent=2))
"
```

Expected: `f:782, p:3990, t:~1949, a:~647, r:~1836, aar:1188, bb:~781, tx:~1526, vm:~15007`

**Step 4: Commit**

```bash
git add scripts/vnas_sync/__init__.py scripts/vnas_sync/crc_parser.py
git commit -m "feat(vnas): CRC ARTCC JSON parser with full field extraction"
```

---

## Task 5: Python Local Agent — File Watcher & API Client

**Files:**
- Create: `scripts/vnas_sync/vnas_crc_watcher.py`
- Create: `scripts/vnas_sync/requirements.txt`

**Step 1: Write requirements.txt**

```
requests>=2.31.0
watchdog>=4.0.0
```

**Step 2: Write the watcher script**

Implements:
- `watchdog` filesystem observer on `%LOCALAPPDATA%/CRC/ARTCCs/`
- On file change: read JSON, compare `lastUpdatedAt` to saved state
- If changed: call `parse_artcc_json()`, POST to `/api/swim/v1/ingest/vnas/facilities`, then POST to `/api/swim/v1/ingest/vnas/restrictions`
- State file: `~/.perti/vnas_sync_state.json` — saves `lastUpdatedAt` per ARTCC
- Config: API base URL and key via env vars `PERTI_API_URL` and `PERTI_API_KEY`
- CLI: `python vnas_crc_watcher.py [--once] [--artcc ZDC] [--debug]`
  - `--once`: process all ARTCCs once and exit (no file watching)
  - `--artcc ZDC`: process only one ARTCC
  - `--debug`: verbose logging

**Step 3: Test in --once mode locally (dry-run)**

```bash
cd scripts/vnas_sync
pip install -r requirements.txt
python vnas_crc_watcher.py --once --artcc ZDC --debug --dry-run
```

Expected: Parses ZDC.json, prints payload sizes, skips actual POST.

**Step 4: Commit**

```bash
git add scripts/vnas_sync/vnas_crc_watcher.py scripts/vnas_sync/requirements.txt
git commit -m "feat(vnas): Python CRC file watcher with API client"
```

---

## Task 6: Server-Side — Facilities Ingest Endpoint

**Files:**
- Create: `api/swim/v1/ingest/vnas/facilities.php`

**Step 1: Write the endpoint**

Follow the pattern from `track.php`:
- `require_once __DIR__ . '/../../auth.php'` for SWIM auth
- Require `vnas_config` write authority on the API key
- Accept JSON body with `artcc_code` + arrays for each entity type
- Use `get_conn_adl()` (VATSIM_ADL database, sqlsrv)
- Per-ARTCC atomic: DELETE WHERE parent_artcc = ?, then batch INSERT
- Use `sqlsrv_query()` with parameterized VALUES for batch inserts (chunk into 100-row batches to avoid SQL Server's 2,100 parameter limit)
- After insert: rebuild `vnas_position_sector_map` by matching `eram_sector_id` to `adl_boundary.boundary_code` WHERE `parent_artcc` matches
- Rebuild `vnas_tcp_sector_map` similarly
- Update `vnas_sync_metadata`
- Return JSON with counts per entity type

**Key implementation notes**:
- `$conn_adl = get_conn_adl()` — this is sqlsrv, NOT PDO
- Use `sqlsrv_begin_transaction($conn_adl)` / `sqlsrv_commit($conn_adl)` / `sqlsrv_rollback($conn_adl)`
- JSON columns: pass PHP arrays through `json_encode()` before INSERT
- Beacon banks need `source_system` discriminator: 'ERAM' for `eramConfiguration.beaconCodeBanks`, 'STARS' for `starsConfiguration.beaconCodeBanks`

**Step 2: Test with curl (single ARTCC)**

```bash
python scripts/vnas_sync/vnas_crc_watcher.py --once --artcc ZDC --debug
```

Expected: HTTP 200 with counts matching ZDC totals (47 facilities, 254 positions, etc.).

**Step 3: Verify data in database**

```sql
SELECT * FROM vnas_sync_metadata WHERE artcc_code = 'ZDC';
SELECT COUNT(*) FROM vnas_facilities WHERE parent_artcc = 'ZDC';
SELECT COUNT(*) FROM vnas_positions WHERE parent_artcc = 'ZDC';
SELECT COUNT(*) FROM vnas_stars_tcps WHERE parent_artcc = 'ZDC';
```

**Step 4: Commit**

```bash
git add api/swim/v1/ingest/vnas/facilities.php
git commit -m "feat(vnas): facilities ingest endpoint with batch import"
```

---

## Task 7: Server-Side — Restrictions Ingest Endpoint

**Files:**
- Create: `api/swim/v1/ingest/vnas/restrictions.php`

**Step 1: Write the endpoint**

Same pattern as facilities.php but simpler — only 2 tables:
- DELETE + batch INSERT `vnas_restrictions`
- DELETE + batch INSERT `vnas_auto_atc_rules`
- Update `vnas_sync_metadata` (restrictions_count, auto_atc_rules_count)

**Step 2: Test with curl**

```bash
python scripts/vnas_sync/vnas_crc_watcher.py --once --artcc ZDC --debug
```

Expected: 0 restrictions (ZDC has none), 118 Auto ATC rules.

Then test with ZOA (most restrictions):
```bash
python scripts/vnas_sync/vnas_crc_watcher.py --once --artcc ZOA --debug
```

Expected: 488 restrictions.

**Step 3: Commit**

```bash
git add api/swim/v1/ingest/vnas/restrictions.php
git commit -m "feat(vnas): restrictions ingest endpoint with batch import"
```

---

## Task 8: Full Import — All 24 ARTCCs

**Step 1: Run full import**

```bash
python scripts/vnas_sync/vnas_crc_watcher.py --once --debug
```

**Step 2: Verify totals**

```sql
SELECT
    (SELECT COUNT(*) FROM vnas_facilities) AS facilities,
    (SELECT COUNT(*) FROM vnas_positions) AS positions,
    (SELECT COUNT(*) FROM vnas_stars_tcps) AS tcps,
    (SELECT COUNT(*) FROM vnas_stars_areas) AS areas,
    (SELECT COUNT(*) FROM vnas_restrictions) AS restrictions,
    (SELECT COUNT(*) FROM vnas_auto_atc_rules) AS auto_atc,
    (SELECT COUNT(*) FROM vnas_beacon_banks) AS beacon_banks,
    (SELECT COUNT(*) FROM vnas_transceivers) AS transceivers,
    (SELECT COUNT(*) FROM vnas_video_map_index) AS video_maps,
    (SELECT COUNT(*) FROM vnas_airport_groups) AS airport_groups,
    (SELECT COUNT(*) FROM vnas_common_urls) AS common_urls;
```

Expected: 782, 3990, ~1949, ~647, ~1836, 1188, ~781, ~1526, ~15007, 69, 88

**Step 3: Verify sync metadata**

```sql
SELECT * FROM vnas_sync_metadata ORDER BY artcc_code;
```

Expected: 24 rows, all `import_status = 'success'`.

**Step 4: Verify position-sector mapping was built**

```sql
SELECT COUNT(*) FROM vnas_position_sector_map;
SELECT COUNT(*) FROM vnas_tcp_sector_map;
SELECT TOP 5 p.callsign, p.eram_sector_id, m.boundary_code, m.sector_type
FROM vnas_position_sector_map m
JOIN vnas_positions p ON p.position_ulid = m.position_ulid;
```

**Step 5: Commit state**

No code change — this is a verification step.

---

## Task 9: Enhanced Controller Feed — Staffing & Consolidation (2B)

**Files:**
- Modify: `scripts/vnas_controller_poll.php`

**Step 1: Add staffing update function**

After the existing `vnas_ctrl_poll()` completes (Step 2: enrichment), add a new Step 3 that:
1. Queries `swim_controllers` for active controllers with vNAS enrichment
2. For each ERAM position: look up `vnas_position_sector_map` → SET `adl_boundary.is_staffed = 1`
3. For each STARS position: parse `vnas_secondary_json` to get `starsData.assumedTcps[]`, look up `vnas_tcp_sector_map` → SET `adl_boundary.is_staffed = 1`
4. Unstaffing sweep: SET `is_staffed = 0` WHERE `staffed_updated_utc < DATEADD(second, -90, SYSUTCDATETIME())`

**Implementation note**: This queries VATSIM_ADL (`get_conn_adl()`) while the existing enrichment uses SWIM_API (`get_conn_swim()`). The daemon already has both connections available.

**Step 2: Add consolidation detection**

Parse `starsData.assumedTcps[]` from the live feed (already available in the transform step). If count > 1, emit a `controller.consolidation` WebSocket event.

**Step 3: Add top-down detection**

If a controller's `positions[]` spans multiple `facilityId` values, the non-primary facilities are covered top-down. Emit `controller.topdown` WebSocket event.

**Step 4: Add staffing metrics**

After staffing update, compute per-facility staffing counts:
```sql
SELECT vnas_facility_id, COUNT(*) as staffed
FROM swim_controllers
WHERE is_active = 1 AND vnas_facility_id IS NOT NULL
GROUP BY vnas_facility_id
```

Join with `vnas_positions` count per facility for `positions_total`. Emit `facility.staffing` WebSocket events.

**Step 5: Test**

Run the daemon once manually:
```bash
php scripts/vnas_controller_poll.php --debug
```

Then check:
```sql
SELECT boundary_code, is_staffed, staffed_by_cid, staffed_updated_utc
FROM adl_boundary WHERE is_staffed = 1;
```

**Step 6: Commit**

```bash
git add scripts/vnas_controller_poll.php
git commit -m "feat(vnas): enhanced controller feed with staffing, consolidation, top-down detection (2B)"
```

---

## Task 10: Provision API Key & Final Integration Test

**Step 1: Create swim_sys_ API key for vNAS config sync**

```sql
INSERT INTO dbo.swim_api_keys (key_prefix, key_hash, source_id, tier, is_active, authoritative_fields, description, created_utc)
VALUES ('swim_sys_vnas_config', '<hash>', 'vnas_config_sync', 'system', 1, 'vnas_config', 'vNAS CRC config sync agent', SYSUTCDATETIME());
```

**Step 2: Configure Python agent**

Set environment variables:
```bash
export PERTI_API_URL="https://perti.vatcscc.org"
export PERTI_API_KEY="swim_sys_vnas_config_<key>"
```

**Step 3: Full end-to-end test**

1. Start watcher: `python scripts/vnas_sync/vnas_crc_watcher.py --debug`
2. Open CRC (triggers JSON update)
3. Verify watcher detects change and POSTs
4. Verify database tables populated
5. Run controller poll: `php scripts/vnas_controller_poll.php --debug`
6. Verify `adl_boundary.is_staffed` reflects live controllers

**Step 4: Commit any fixes from integration testing**

```bash
git add -A
git commit -m "fix(vnas): integration test fixes"
```

---

## Summary

| Task | Component | Files | Estimated Rows |
|------|-----------|-------|---------------|
| 1 | DB: Core tables (7A) | 1 migration | 10 tables |
| 2 | DB: Restrictions (6B) | 1 migration | 2 tables |
| 3 | DB: Staffing/mapping (2B) | 1 migration | 2 tables + ALTER |
| 4 | Python: CRC parser | 2 files | ~300 lines |
| 5 | Python: File watcher | 2 files | ~200 lines |
| 6 | PHP: Facilities endpoint | 1 file | ~400 lines |
| 7 | PHP: Restrictions endpoint | 1 file | ~200 lines |
| 8 | Verification: Full import | 0 files | SQL queries |
| 9 | PHP: Enhanced controller feed | 1 modified | ~150 lines added |
| 10 | Config: API key + E2E test | 0 files | Manual |

**Dependencies**: Tasks 1-3 must run first (schema). Task 4 before 5 (parser before watcher). Tasks 6-7 before 8 (endpoints before full import). Task 8 before 9 (data before enrichment). Task 10 last.

```
[1] ──┐
[2] ──┼── [4] ── [5] ──┐
[3] ──┘                 ├── [8] ── [9] ── [10]
       [6] ── [7] ─────┘
```
