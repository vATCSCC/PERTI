# vNAS Reference Data Sync — Design Document

**Date**: 2026-04-07
**Status**: Approved
**Scope**: Opportunities 7A (Facility/Position Sync), 2B (Enhanced Controller Feed), 6B (Restrictions Import)

---

## 1. Overview

Import the complete vNAS configuration dataset from CRC local data into PERTI's databases, then use it to enrich the live controller feed with sector staffing, consolidation detection, and operational intelligence.

### Data Source

24 ARTCC JSON files at `%LOCALAPPDATA%/CRC/ARTCCs/*.json`, containing:

| Data | Count | Use |
|------|-------|-----|
| Facilities (hierarchical) | 782 | 7A: facility reference |
| Positions | 3,990 | 7A: position reference, 2B: ULID-based mapping |
| STARS TCPs | 1,949 | 2B: consolidation detection |
| STARS Areas | 647 | 7A: visibility centers, display config |
| Restrictions | 1,836 | 6B: inter-facility agreements |
| Auto ATC Rules | 1,188 | 6B: descent/crossing restrictions |
| Beacon Code Banks | 781 | Conflict detection |
| Transceivers | ~1,526 | Spatial reference |
| Video Map Index | 15,007 | Map metadata |
| Airport Groups | 69 | Named groupings |
| Common URLs | 88 | Reference links |
| TDLS Configs | 87 facilities / 660 SIDs | Clearance workflow reference |
| ERAM/STARS/Tower/ASDEX/Strips Configs | Various | JSON columns on facilities |

Position ULIDs in the live controller feed (`primaryPositionId`, `positions[].positionId`) match exactly to ULIDs in the CRC JSON files. This enables direct lookup without fuzzy matching.

### Approach

**Per-domain pipelines (Approach B)**:
1. Python local agent watches CRC files, POSTs to two API endpoints
2. `POST /ingest/vnas/facilities` — facility hierarchy, positions, TCPs, areas, transceivers, beacon banks, video maps, airport groups, URLs
3. `POST /ingest/vnas/restrictions` — restrictions, Auto ATC rules
4. Enhanced `vnas_controller_poll.php` — uses imported position/TCP data for staffing and consolidation

---

## 2. Database Schema

All tables in **VATSIM_ADL** (Azure SQL).

### 2.1 Core Tables (7A)

#### vnas_facilities (782 rows)

```sql
CREATE TABLE vnas_facilities (
    facility_id               NVARCHAR(8)   NOT NULL PRIMARY KEY,
    facility_name             NVARCHAR(100) NOT NULL,
    facility_type             NVARCHAR(16)  NOT NULL,      -- Artcc|Tracon|Atct|AtctTracon|AtctRapcon
    parent_artcc              NVARCHAR(4)   NOT NULL,
    parent_facility_id        NVARCHAR(8)   NULL,          -- direct parent (NULL for ARTCCs)
    hierarchy_depth           SMALLINT      NOT NULL,      -- 0=ARTCC, 1=TRACON, 2=ATCT
    neighboring_facility_ids  NVARCHAR(MAX) NULL,          -- JSON: ["ZNY","ZOB",...]
    non_nas_facility_ids      NVARCHAR(MAX) NULL,          -- JSON
    -- Config presence flags
    has_eram                  BIT NOT NULL DEFAULT 0,
    has_stars                 BIT NOT NULL DEFAULT 0,
    has_flight_strips         BIT NOT NULL DEFAULT 0,
    has_tower_cab             BIT NOT NULL DEFAULT 0,
    has_asdex                 BIT NOT NULL DEFAULT 0,
    has_tdls                  BIT NOT NULL DEFAULT 0,
    -- Full configs as JSON
    eram_config_json          NVARCHAR(MAX) NULL,          -- 24 ARTCCs: nasId, geoMaps[162], beaconCodeBanks[376], neighboringStarsConfigurations[360], neighboringCaatsConfigurations[13], coordinationFixes[14], referenceFixes[1187], asrSites[224], internalAirports[667], conflictAlertFloor, airportSingleChars, emergencyChecklist, positionReliefChecklist
    stars_config_json         NVARCHAR(MAX) NULL,          -- 214 facilities: areas[647], tcps[1949], beaconCodeBanks[405], rpcs[139], primaryScratchpadRules[2596], secondaryScratchpadRules[87], mapGroups[531], configurationPlans[274], rnavPatterns[884], routeBasedCoordinations[1229], starsHandoffIds[506], videoMapIds, atpaVolumes[673], terminalSectors[133], automaticConsolidation, recatEnabled, allow4CharacterScratchpad, impliedCompoundCommands, lists, internalAirports[3731]
    flight_strips_json        NVARCHAR(MAX) NULL,          -- 691 facilities: stripBays, externalBays, displayBarcodes, enableArrivalStrips, enableSeparateArrDepPrinters, lockSeparators
    tower_cab_json            NVARCHAR(MAX) NULL,          -- 728 facilities: towerLocation{lat,lon}, defaultRotation, defaultZoomRange, aircraftVisibilityCeiling, videoMapId
    asdex_config_json         NVARCHAR(MAX) NULL,          -- 85 facilities: towerLocation, positions[{id,name,runwayIds}], runwayConfigurations[{id,name,arrivalRunwayIds,departureRunwayIds,holdShortRunwayPairs}], fixRules[{id,fixId,searchPattern}], defaultPositionId, defaultRotation, defaultZoomRange, targetVisibilityCeiling, targetVisibilityRange, videoMapId, useDestinationIdAsFix
    tdls_config_json          NVARCHAR(MAX) NULL,          -- 87 facilities: sids[{id,name,transitions[{id,name,defaultExpect,defaultInitialAlt}]}], climbouts[{id,value}], climbvias[], contactInfos[{id,value}], depFreqs[{id,value}], expects[{id,value}], initialAlts[{id,value}], localInfos[{id,value}], mandatoryClimbout, mandatoryClimbvia, mandatoryContactInfo, mandatoryDepFreq, mandatoryExpect, mandatoryInitialAlt, mandatoryLocalInfo, mandatorySid, defaultSidId, defaultTransitionId
    -- Top-level ARTCC data (NULL for non-ARTCCs)
    visibility_centers_json   NVARCHAR(MAX) NULL,          -- [{lat,lon},...] top-level, NOT part of ERAM config
    aliases_updated_at        DATETIME2     NULL,          -- aliasesLastUpdatedAt from source JSON
    -- Metadata
    source_artcc              NVARCHAR(4)   NOT NULL,
    source_updated_at         DATETIME2     NULL,          -- lastUpdatedAt from JSON
    imported_utc              DATETIME2     NOT NULL DEFAULT SYSUTCDATETIME()
);

CREATE INDEX IX_vnas_facilities_parent ON vnas_facilities (parent_artcc, facility_type);
CREATE INDEX IX_vnas_facilities_type ON vnas_facilities (facility_type);
```

**Facility type breakdown**: 544 Atct, 131 AtctTracon, 53 AtctRapcon, 30 Tracon, 24 Artcc

**Config type breakdown**: 24 ERAM, 214 STARS, 691 FlightStrips, 728 TowerCab, 85 ASDEX, 87 TDLS

**Facility keys by type**:
- `Artcc`: id, name, type, childFacilities, eramConfiguration, positions, neighboringFacilityIds, nonNasFacilityIds
- `Tracon`: id, name, type, childFacilities, starsConfiguration, flightStripsConfiguration, positions, neighboringFacilityIds, nonNasFacilityIds
- `AtctTracon`: id, name, type, childFacilities, starsConfiguration, flightStripsConfiguration, towerCabConfiguration, asdexConfiguration, tdlsConfiguration, positions, neighboringFacilityIds, nonNasFacilityIds
- `AtctRapcon`: id, name, type, childFacilities, starsConfiguration, flightStripsConfiguration, towerCabConfiguration, positions, neighboringFacilityIds, nonNasFacilityIds
- `Atct`: id, name, type, childFacilities, towerCabConfiguration, flightStripsConfiguration, asdexConfiguration, tdlsConfiguration, positions, neighboringFacilityIds, nonNasFacilityIds

#### vnas_positions (3,990 rows)

```sql
CREATE TABLE vnas_positions (
    position_ulid             NVARCHAR(32)  NOT NULL PRIMARY KEY,
    facility_id               NVARCHAR(8)   NOT NULL,
    parent_artcc              NVARCHAR(4)   NOT NULL,
    position_name             NVARCHAR(50)  NOT NULL,      -- 'Gordonsville 32'
    callsign                  NVARCHAR(20)  NOT NULL,      -- 'DC_32_CTR'
    radio_name                NVARCHAR(50)  NULL,          -- 'Washington Center'
    frequency_hz              INT           NOT NULL,      -- 133725000
    starred                   BIT           NOT NULL DEFAULT 0,
    -- ERAM (ARTCC positions only)
    eram_sector_id            NVARCHAR(8)   NULL,          -- '32'
    -- STARS (TRACON/ATCT positions)
    stars_area_id             NVARCHAR(32)  NULL,          -- ULID → vnas_stars_areas
    stars_tcp_id              NVARCHAR(32)  NULL,          -- ULID → vnas_stars_tcps
    stars_color_set           NVARCHAR(8)   NULL,          -- 'Tcw', 'Tdw'
    -- Transceiver linkage
    transceiver_ids_json      NVARCHAR(MAX) NULL,          -- JSON: ["uuid1","uuid2",...]
    -- Metadata
    imported_utc              DATETIME2     NOT NULL DEFAULT SYSUTCDATETIME()
);

CREATE INDEX IX_vnas_positions_facility ON vnas_positions (facility_id);
CREATE INDEX IX_vnas_positions_artcc ON vnas_positions (parent_artcc);
CREATE INDEX IX_vnas_positions_callsign ON vnas_positions (callsign);
```

**Position fields** (all types): id (ULID), name, starred, radioName, callsign, frequency (Hz), transceiverIds[], eramConfiguration{sectorId} OR starsConfiguration{areaId, colorSet, tcpId}

### 2.2 Extracted Sub-Tables (queried by 2B)

#### vnas_stars_tcps (1,949 rows)

Critical for consolidation detection. The live feed's `assumedTcps[]` contains sectorId strings (e.g., "1W", "3H") that map to these rows.

```sql
CREATE TABLE vnas_stars_tcps (
    tcp_id                    NVARCHAR(32)  NOT NULL PRIMARY KEY,  -- ULID
    facility_id               NVARCHAR(8)   NOT NULL,
    parent_artcc              NVARCHAR(4)   NOT NULL,
    subset                    SMALLINT      NOT NULL,      -- 1, 2
    sector_id                 NVARCHAR(4)   NOT NULL,      -- '1W', 'A', '3H'
    parent_tcp_id             NVARCHAR(32)  NULL,          -- 741 TCPs have parent linkage
    terminal_sector           BIT           NULL,          -- 946 flagged as terminal sector
    imported_utc              DATETIME2     NOT NULL DEFAULT SYSUTCDATETIME()
);

CREATE INDEX IX_vnas_stars_tcps_facility ON vnas_stars_tcps (facility_id, sector_id);
CREATE INDEX IX_vnas_stars_tcps_parent ON vnas_stars_tcps (parent_tcp_id);
```

**TCP fields**: id (ULID), subset (SMALLINT), sectorId (VARCHAR 4), parentTcpId (ULID, 741 have this), terminalSector (BIT, 946 have this)

#### vnas_stars_areas (647 rows)

```sql
CREATE TABLE vnas_stars_areas (
    area_id                   NVARCHAR(32)  NOT NULL PRIMARY KEY,  -- ULID
    facility_id               NVARCHAR(8)   NOT NULL,
    parent_artcc              NVARCHAR(4)   NOT NULL,
    area_name                 NVARCHAR(50)  NOT NULL,      -- 'Chesapeake'
    visibility_lat            FLOAT         NULL,
    visibility_lon            FLOAT         NULL,
    surveillance_range        INT           NULL,          -- nm
    ldb_beacon_codes_inhibited    BIT NULL,
    pdb_ground_speed_inhibited    BIT NULL,
    display_requested_alt_in_fdb  BIT NULL,
    use_vfr_position_symbol       BIT NULL,
    show_dest_departures          BIT NULL,
    show_dest_satellite_arrivals  BIT NULL,
    show_dest_primary_arrivals    BIT NULL,
    underlying_airports_json      NVARCHAR(MAX) NULL,      -- string array
    ssa_airports_json             NVARCHAR(MAX) NULL,      -- string array
    tower_list_configs_json       NVARCHAR(MAX) NULL,      -- [{id, airportId, range}]
    imported_utc              DATETIME2     NOT NULL DEFAULT SYSUTCDATETIME()
);

CREATE INDEX IX_vnas_stars_areas_facility ON vnas_stars_areas (facility_id);
```

**STARS Area fields**: id (ULID), name, visibilityCenter{lat,lon}, surveillanceRange, underlyingAirports[], ssaAirports[], towerListConfigurations[{id,airportId,range}], ldbBeaconCodesInhibited, pdbGroundSpeedInhibited, displayRequestedAltInFdb, useVfrPositionSymbol, showDestinationDepartures, showDestinationSatelliteArrivals, showDestinationPrimaryArrivals

#### vnas_beacon_banks (781 rows)

```sql
CREATE TABLE vnas_beacon_banks (
    bank_id                   NVARCHAR(32)  NOT NULL PRIMARY KEY,
    facility_id               NVARCHAR(8)   NOT NULL,
    parent_artcc              NVARCHAR(4)   NOT NULL,
    source_system             NVARCHAR(4)   NOT NULL,      -- 'ERAM' or 'STARS'
    category                  NVARCHAR(16)  NULL,          -- 'External', 'Internal'
    priority                  NVARCHAR(16)  NULL,          -- 'Primary', 'Secondary'
    subset                    INT           NULL,
    start_code                INT           NOT NULL,
    end_code                  INT           NOT NULL,
    imported_utc              DATETIME2     NOT NULL DEFAULT SYSUTCDATETIME()
);

CREATE INDEX IX_vnas_beacon_banks_facility ON vnas_beacon_banks (facility_id, source_system);
```

**Beacon bank fields**: id (ULID), category, priority, subset, start, end. Source: eramConfiguration.beaconCodeBanks (376) + starsConfiguration.beaconCodeBanks (405).

#### vnas_transceivers (~1,526 rows)

```sql
CREATE TABLE vnas_transceivers (
    transceiver_id            NVARCHAR(40)  NOT NULL PRIMARY KEY,  -- UUID
    parent_artcc              NVARCHAR(4)   NOT NULL,
    transceiver_name          NVARCHAR(80)  NOT NULL,      -- 'KZDC_FAA_GRANTSVILLE'
    lat                       FLOAT         NOT NULL,
    lon                       FLOAT         NOT NULL,
    height_msl_meters         INT           NULL,
    height_agl_meters         INT           NULL,
    imported_utc              DATETIME2     NOT NULL DEFAULT SYSUTCDATETIME()
);

CREATE INDEX IX_vnas_transceivers_artcc ON vnas_transceivers (parent_artcc);
```

**Transceiver fields**: id (UUID), name, location{lat,lon}, heightMslMeters, heightAglMeters

#### vnas_video_map_index (15,007 rows)

```sql
CREATE TABLE vnas_video_map_index (
    map_id                    NVARCHAR(32)  NOT NULL PRIMARY KEY,
    parent_artcc              NVARCHAR(4)   NOT NULL,
    map_name                  NVARCHAR(100) NOT NULL,
    short_name                NVARCHAR(50)  NULL,
    stars_id                  NVARCHAR(16)  NULL,
    tags_json                 NVARCHAR(MAX) NULL,          -- ["SECTOR","HIGH"]
    source_file_name          NVARCHAR(100) NULL,
    stars_brightness_category NVARCHAR(20)  NULL,
    stars_always_visible      BIT           NULL,
    tdm_only                  BIT           NULL,
    last_updated_at           DATETIME2     NULL,
    imported_utc              DATETIME2     NOT NULL DEFAULT SYSUTCDATETIME()
);

CREATE INDEX IX_vnas_video_map_artcc ON vnas_video_map_index (parent_artcc);
```

**Video map fields**: id, name, shortName, starsId, tags[], sourceFileName, starsBrightnessCategory, starsAlwaysVisible, tdmOnly, lastUpdatedAt

#### vnas_airport_groups (69 rows)

```sql
CREATE TABLE vnas_airport_groups (
    group_id                  NVARCHAR(32)  NOT NULL PRIMARY KEY,
    parent_artcc              NVARCHAR(4)   NOT NULL,
    group_name                NVARCHAR(50)  NOT NULL,
    airport_ids_json          NVARCHAR(MAX) NOT NULL,
    imported_utc              DATETIME2     NOT NULL DEFAULT SYSUTCDATETIME()
);
```

**Airport group fields**: id, name, airportIds[]

#### vnas_common_urls (88 rows)

```sql
CREATE TABLE vnas_common_urls (
    url_id                    NVARCHAR(32)  NOT NULL PRIMARY KEY,
    parent_artcc              NVARCHAR(4)   NOT NULL,
    url_name                  NVARCHAR(100) NOT NULL,
    url                       NVARCHAR(500) NOT NULL,
    imported_utc              DATETIME2     NOT NULL DEFAULT SYSUTCDATETIME()
);
```

**Common URL fields**: id, name, url

### 2.3 Restrictions & Auto ATC Rules (6B)

#### vnas_restrictions (1,836 rows)

```sql
CREATE TABLE vnas_restrictions (
    restriction_id            NVARCHAR(40)  NOT NULL PRIMARY KEY,
    parent_artcc              NVARCHAR(4)   NOT NULL,
    owning_facility_id        NVARCHAR(8)   NOT NULL,
    owning_sector_ids         NVARCHAR(MAX) NULL,          -- JSON: ["1L"]
    requesting_facility_id    NVARCHAR(8)   NULL,
    requesting_sector_ids     NVARCHAR(MAX) NULL,          -- JSON: ["1K"]
    route                     NVARCHAR(200) NULL,
    applicable_airports       NVARCHAR(MAX) NULL,          -- JSON: ["SFO"]
    applicable_aircraft_types NVARCHAR(MAX) NULL,          -- JSON: ["Props","Turboprops","Jets","Rnav"]
    flight_type               NVARCHAR(20)  NULL,          -- 'Arrivals' | 'Departures' | NULL
    flow                      NVARCHAR(50)  NULL,          -- 'ABQ EAST'
    group_name                NVARCHAR(100) NULL,          -- 'DLAMP Gate', 'East Flow (8, 3)'
    -- Altitude
    altitude_type             NVARCHAR(30)  NULL,          -- At|AtOrAbove|AtOrAboveClimbingTo|AtOrBelow|AtOrBelowDescendingTo|Between|ClimbingTo|ClimbingVia|DescendingTo|DescendingVia|Eastbound|Westbound|AnyOf
    altitude_values           NVARCHAR(MAX) NULL,          -- JSON: ["6000"] or ["FL340"] or ["14000","17000"]
    -- Speed
    speed_type                NVARCHAR(20)  NULL,          -- At|AtOrAbove|AtOrBelow
    speed_values              NVARCHAR(MAX) NULL,          -- JSON: [280]
    speed_units               NVARCHAR(10)  NULL,          -- 'Knots' | 'Mach'
    -- Heading
    heading_type              NVARCHAR(20)  NULL,          -- 'Heading'
    heading_values            NVARCHAR(MAX) NULL,          -- JSON: [310]
    -- Location
    location_type             NVARCHAR(10)  NULL,          -- 'Fix' | 'Boundary'
    location_value            NVARCHAR(20)  NULL,          -- 'ANCHR' | NULL (for Boundary)
    -- Notes
    notes_json                NVARCHAR(MAX) NULL,          -- [{id, classification, message}] classification: General|Ait
    display_order             INT           NOT NULL DEFAULT 0,
    imported_utc              DATETIME2     NOT NULL DEFAULT SYSUTCDATETIME()
);

CREATE INDEX IX_vnas_restrictions_artcc ON vnas_restrictions (parent_artcc);
CREATE INDEX IX_vnas_restrictions_owning ON vnas_restrictions (owning_facility_id);
CREATE INDEX IX_vnas_restrictions_airports ON vnas_restrictions (parent_artcc) INCLUDE (applicable_airports, flight_type);
```

**All restriction fields verified**: id, route, applicableAirports[], applicableAircraftTypes[], owningFacilityId, owningSectorIds[], requestingFacilityId, requestingSectorIds[], altitudeRestriction{type,altitudes[]}, speedRestriction{type,speeds[],units}, headingRestriction{type,headings[]}, locationRestriction{type,location}, flightType, flow, groupName, notes[{id,classification,message}], displayOrder

**Altitude types** (13 confirmed): At, AtOrAbove, AtOrAboveClimbingTo, AtOrBelow, AtOrBelowDescendingTo, Between, ClimbingTo, ClimbingVia, DescendingTo, DescendingVia, Eastbound, Westbound, AnyOf

#### vnas_auto_atc_rules (1,188 rows)

```sql
CREATE TABLE vnas_auto_atc_rules (
    rule_id                       NVARCHAR(32)  NOT NULL PRIMARY KEY,
    parent_artcc                  NVARCHAR(4)   NOT NULL,
    rule_name                     NVARCHAR(100) NOT NULL,
    status                        NVARCHAR(16)  NOT NULL,  -- Enabled|Testing|Disabled
    position_ulid                 NVARCHAR(32)  NULL,
    -- Criteria
    route_substrings              NVARCHAR(MAX) NULL,      -- JSON
    exclude_route_substrings      NVARCHAR(MAX) NULL,      -- JSON
    departure_airports            NVARCHAR(MAX) NULL,      -- JSON
    destination_airports          NVARCHAR(MAX) NULL,      -- JSON
    min_altitude                  INT           NULL,      -- 152 rules
    max_altitude                  INT           NULL,      -- 14 rules
    applicable_jets               BIT NOT NULL DEFAULT 0,
    applicable_turboprops         BIT NOT NULL DEFAULT 0,
    applicable_props              BIT NOT NULL DEFAULT 0,
    -- Descent restriction (crossing-line based)
    descent_crossing_line_json    NVARCHAR(MAX) NULL,      -- [{lat,lon},...] polyline
    descent_altitude_value        INT           NULL,
    descent_altitude_type         NVARCHAR(10)  NULL,      -- At|AtOrAbove|AtOrBelow
    descent_transition_level      INT           NULL,      -- e.g. 180
    descent_is_lufl               BIT           NULL,
    descent_lufl_station_id       NVARCHAR(4)   NULL,
    descent_altimeter_station     NVARCHAR(8)   NULL,      -- 'KACY'
    descent_altimeter_name        NVARCHAR(50)  NULL,      -- 'Atlantic City'
    descent_speed_value           INT           NULL,      -- 1 rule
    descent_speed_is_mach         BIT           NULL,
    descent_speed_type            NVARCHAR(16)  NULL,      -- 'Maintain'
    -- Descent crossing restriction (fix-based, 606 rules)
    crossing_fix                  NVARCHAR(10)  NULL,      -- 'FIYER'
    crossing_fix_name             NVARCHAR(20)  NULL,
    crossing_altitude_value       INT           NULL,
    crossing_altitude_type        NVARCHAR(10)  NULL,
    crossing_transition_level     INT           NULL,
    crossing_is_lufl              BIT           NULL,
    crossing_altimeter_station    NVARCHAR(8)   NULL,
    crossing_altimeter_name       NVARCHAR(50)  NULL,
    -- Descend via (203 rules)
    descend_via_star_name         NVARCHAR(20)  NULL,      -- 'WITTI4'
    descend_via_crossing_line_json NVARCHAR(MAX) NULL,     -- [{lat,lon},...] polyline
    descend_via_altimeter_station NVARCHAR(8)   NULL,
    descend_via_altimeter_name    NVARCHAR(50)  NULL,
    -- Rule linkage
    precursor_rule_ids            NVARCHAR(MAX) NULL,      -- JSON
    exclusionary_rule_ids         NVARCHAR(MAX) NULL,      -- JSON
    imported_utc                  DATETIME2     NOT NULL DEFAULT SYSUTCDATETIME()
);

CREATE INDEX IX_vnas_auto_atc_parent ON vnas_auto_atc_rules (parent_artcc, status);
CREATE INDEX IX_vnas_auto_atc_position ON vnas_auto_atc_rules (position_ulid);
```

**All Auto ATC rule fields verified**:
- Top-level: id, name, status, positionId, precursorRules[], exclusionaryRules[]
- Criteria: routeSubstrings[], excludeRouteSubstrings[], departures[], destinations[], minAltitude, maxAltitude, applicableToJets, applicableToTurboprops, applicableToProps
- descentRestriction: crossingLine[{lat,lon}], altitudeConstraint{value,transitionLevel,constraintType,isLufl,luflStationId}, altimeterStation{stationId,stationName}, speedConstraint{value,isMach,constraintType}
- descentCrossingRestriction (606 rules): crossingFix, crossingFixName, altitudeConstraint{...}, altimeterStation{...}
- descendVia (203 rules): crossingLine[{lat,lon}], starName, altimeterStation{...}

### 2.4 Controller Feed Enrichment (2B)

#### Position-to-sector mapping

```sql
-- Maps positions → adl_boundary sectors (for ERAM positions)
CREATE TABLE vnas_position_sector_map (
    position_ulid             NVARCHAR(32)  NOT NULL,
    boundary_id               INT           NOT NULL,
    boundary_code             NVARCHAR(20)  NOT NULL,
    parent_artcc              NVARCHAR(4)   NOT NULL,
    sector_type               NVARCHAR(16)  NOT NULL,
    mapped_utc                DATETIME2     NOT NULL DEFAULT SYSUTCDATETIME(),
    CONSTRAINT PK_vnas_pos_sector PRIMARY KEY (position_ulid, boundary_id)
);

CREATE INDEX IX_vnas_pos_sector_boundary ON vnas_position_sector_map (boundary_id);

-- Maps STARS TCPs → adl_boundary sectors
-- Feed says "assumedTcps: ['1W','1E']" → look up boundary_ids
CREATE TABLE vnas_tcp_sector_map (
    tcp_id                    NVARCHAR(32)  NOT NULL PRIMARY KEY,
    facility_id               NVARCHAR(8)   NOT NULL,
    sector_id                 NVARCHAR(4)   NOT NULL,
    boundary_id               INT           NULL,          -- NULL if no matching boundary
    boundary_code             NVARCHAR(20)  NULL,
    parent_artcc              NVARCHAR(4)   NOT NULL,
    mapped_utc                DATETIME2     NOT NULL DEFAULT SYSUTCDATETIME()
);

CREATE INDEX IX_vnas_tcp_sector_facility ON vnas_tcp_sector_map (facility_id, sector_id);
```

#### Staffing state on existing table

```sql
ALTER TABLE adl_boundary ADD is_staffed BIT NOT NULL DEFAULT 0;
ALTER TABLE adl_boundary ADD staffed_by_cid INT NULL;
ALTER TABLE adl_boundary ADD staffed_updated_utc DATETIME2 NULL;
```

### 2.5 Sync Metadata

```sql
CREATE TABLE vnas_sync_metadata (
    artcc_code                NVARCHAR(4)   NOT NULL PRIMARY KEY,
    source_updated_at         DATETIME2     NULL,
    last_import_utc           DATETIME2     NULL,
    facilities_count          INT           NULL,
    positions_count           INT           NULL,
    restrictions_count        INT           NULL,
    auto_atc_rules_count      INT           NULL,
    import_duration_ms        INT           NULL,
    import_status             NVARCHAR(20)  NULL           -- success|error|partial
);
```

---

## 3. Architecture

### 3.1 Python Local Agent

**Location**: `scripts/vnas_sync/`
**Runtime**: Python 3.12 (already installed locally)
**Trigger**: File watcher on `%LOCALAPPDATA%/CRC/ARTCCs/*.json`

```
vnas_crc_watcher.py
├── Watches %LOCALAPPDATA%/CRC/ARTCCs/*.json
├── Detects changes via lastUpdatedAt field comparison
├── State file: ~/.perti/vnas_sync_state.json
│   { "ZDC": "2026-03-19T23:51:50.848Z", ... }
├── On change per ARTCC:
│   ├── Parse facility hierarchy (recursive walk)
│   ├── Flatten to: facilities[], positions[], tcps[], areas[],
│   │   transceivers[], beacon_banks[], video_maps[],
│   │   airport_groups[], common_urls[]
│   ├── POST /api/swim/v1/ingest/vnas/facilities
│   │   { artcc_code, source_updated_at, facilities, positions,
│   │     stars_tcps, stars_areas, beacon_banks, transceivers,
│   │     video_maps, airport_groups, common_urls }
│   ├── Extract restrictions[], auto_atc_rules[]
│   └── POST /api/swim/v1/ingest/vnas/restrictions
│       { artcc_code, restrictions, auto_atc_rules }
└── Logging: stdout + optional file
```

**Dependencies**: `requests`, `watchdog` (file system monitoring)

### 3.2 Server-Side Ingest Endpoints

#### POST /api/swim/v1/ingest/vnas/facilities

- Auth: `swim_sys_` API key with `vnas_config` write authority
- Payload: JSON with artcc_code + all sub-arrays
- Processing:
  1. Validate artcc_code against known ARTCCs
  2. Begin transaction
  3. DELETE FROM all `vnas_*` tables WHERE parent_artcc = :artcc_code
  4. Batch INSERT facilities, positions, TCPs, areas, transceivers, beacon banks, video maps, airport groups, URLs
  5. Rebuild `vnas_position_sector_map`: match positions' `eram_sector_id` to `adl_boundary.boundary_code` WHERE `parent_artcc` matches
  6. Rebuild `vnas_tcp_sector_map`: match TCPs' `sector_id` to adl_boundary by convention (facility_id + sector_id → boundary_code pattern)
  7. UPDATE `vnas_sync_metadata`
  8. Commit

#### POST /api/swim/v1/ingest/vnas/restrictions

- Auth: same key
- Payload: JSON with artcc_code + restrictions[] + auto_atc_rules[]
- Processing:
  1. Begin transaction
  2. DELETE FROM `vnas_restrictions` WHERE parent_artcc = :artcc_code
  3. DELETE FROM `vnas_auto_atc_rules` WHERE parent_artcc = :artcc_code
  4. Batch INSERT restrictions, auto_atc_rules
  5. UPDATE `vnas_sync_metadata`
  6. Commit

### 3.3 Enhanced Controller Feed Daemon (2B)

Enhancement to existing `scripts/vnas_controller_poll.php` (60s cycle).

**New processing after existing enrichment step**:

1. **Sector staffing**: For each active controller:
   - ERAM positions: look up `vnas_position_sector_map` by `position_ulid` → get `boundary_id` → SET `adl_boundary.is_staffed = 1, staffed_by_cid = :cid`
   - STARS positions: for each `assumedTcps[]` entry, look up `vnas_tcp_sector_map` by `(facility_id, sector_id)` → get `boundary_id` → SET staffed

2. **Consolidation detection**: Count `assumedTcps` per position. If > 1, log consolidation event.

3. **Top-down detection**: If a controller has positions spanning multiple `facilityId` values, the secondary facilities are covered top-down by the primary controller.

4. **Staffing metrics**: Count active positions per facility from `swim_controllers` WHERE `is_active = 1`, grouped by `vnas_facility_id`. Compare to total positions in `vnas_positions`.

5. **Unstaffing sweep**: Any `adl_boundary` with `is_staffed = 1` and `staffed_updated_utc` older than 90 seconds → SET `is_staffed = 0, staffed_by_cid = NULL`.

6. **Auto-populate splits**: Create/update `splits_configs` with `source = 'vnas_live'` containing positions derived from live controller data.

7. **WebSocket events**:
   - `controller.consolidation` — `{ cid, callsign, facility_id, sectors: ["1W","1E","1H",...] }`
   - `controller.topdown` — `{ cid, callsign, primary_facility, covered_facilities: ["PCT","IAD",...] }`
   - `facility.staffing` — `{ facility_id, facility_name, positions_staffed, positions_total }`

---

## 4. Sync Lifecycle

```
1. User opens CRC → CRC downloads latest data from vNAS Server
2. Python watcher detects lastUpdatedAt change on ARTCC JSON
3. Watcher parses changed ARTCC, POSTs to /ingest/vnas/facilities
4. Watcher POSTs to /ingest/vnas/restrictions
5. Server writes to vnas_* tables, rebuilds position→sector maps
6. Next controller feed poll (within 60s) uses new mappings
7. Staffing / consolidation / top-down detection produces live data
8. WebSocket events broadcast to connected clients
```

---

## 5. File Inventory

### New Files

| File | Purpose |
|------|---------|
| `scripts/vnas_sync/vnas_crc_watcher.py` | Python local agent: file watcher + API poster |
| `scripts/vnas_sync/requirements.txt` | requests, watchdog |
| `scripts/vnas_sync/README.md` | Setup and usage instructions |
| `api/swim/v1/ingest/vnas/facilities.php` | Facility hierarchy ingest endpoint |
| `api/swim/v1/ingest/vnas/restrictions.php` | Restrictions ingest endpoint |
| `database/migrations/vnas/001_vnas_reference_schema.sql` | All CREATE TABLE statements |
| `database/migrations/vnas/002_adl_boundary_staffing.sql` | ALTER TABLE adl_boundary |

### Modified Files

| File | Change |
|------|--------|
| `scripts/vnas_controller_poll.php` | Add staffing, consolidation, top-down, splits auto-population logic |
| `scripts/swim_ws_server.php` | Handle new WebSocket event types |

### Existing Files (no changes)

| File | Reason |
|------|--------|
| `load/services/VNASService.php` | Already handles controller feed; no changes needed |
| `api/swim/v1/ingest/vnas/track.php` | Existing, unrelated to this work |
| `api/swim/v1/ingest/vnas/tags.php` | Existing, unrelated to this work |
| `api/swim/v1/ingest/vnas/handoff.php` | Existing, unrelated to this work |
| `api/swim/v1/ingest/vnas/controllers.php` | Existing, unrelated to this work |

---

## 6. Complete Data Coverage Audit

Every field from the CRC ARTCC JSON files accounted for:

### Top-level JSON keys
| Key | Stored In | Format |
|-----|-----------|--------|
| `id` | `vnas_facilities.facility_id` (ARTCC) | Relational |
| `lastUpdatedAt` | `vnas_facilities.source_updated_at`, `vnas_sync_metadata` | Relational |
| `facility` | Recursively flattened → `vnas_facilities`, `vnas_positions` | Relational |
| `visibilityCenters` | `vnas_facilities.visibility_centers_json` | JSON |
| `aliasesLastUpdatedAt` | `vnas_facilities.aliases_updated_at` | Relational |
| `videoMaps` | `vnas_video_map_index` | Relational |
| `transceivers` | `vnas_transceivers` | Relational |
| `restrictions` | `vnas_restrictions` | Relational |
| `airportGroups` | `vnas_airport_groups` | Relational |
| `autoAtcRules` | `vnas_auto_atc_rules` | Relational |
| `commonUrls` | `vnas_common_urls` | Relational |

**FIXED**: Added `aliases_updated_at DATETIME2 NULL` and `visibility_centers_json NVARCHAR(MAX) NULL` to `vnas_facilities`.

### Facility fields
| Key | Stored In |
|-----|-----------|
| `id` | `facility_id` |
| `name` | `facility_name` |
| `type` | `facility_type` |
| `childFacilities` | Recursive → child rows with `parent_facility_id` |
| `positions` | → `vnas_positions` rows |
| `neighboringFacilityIds` | `neighboring_facility_ids` JSON |
| `nonNasFacilityIds` | `non_nas_facility_ids` JSON |
| `eramConfiguration` | `eram_config_json` |
| `starsConfiguration` | `stars_config_json` + extracted to `vnas_stars_tcps`, `vnas_stars_areas` |
| `flightStripsConfiguration` | `flight_strips_json` |
| `towerCabConfiguration` | `tower_cab_json` |
| `asdexConfiguration` | `asdex_config_json` |
| `tdlsConfiguration` | `tdls_config_json` |

### Position fields
| Key | Stored In |
|-----|-----------|
| `id` | `position_ulid` |
| `name` | `position_name` |
| `starred` | `starred` |
| `radioName` | `radio_name` |
| `callsign` | `callsign` |
| `frequency` | `frequency_hz` |
| `eramConfiguration.sectorId` | `eram_sector_id` |
| `starsConfiguration.areaId` | `stars_area_id` |
| `starsConfiguration.tcpId` | `stars_tcp_id` |
| `starsConfiguration.colorSet` | `stars_color_set` |
| `transceiverIds` | `transceiver_ids_json` |

### STARS TCP fields
| Key | Stored In |
|-----|-----------|
| `id` | `tcp_id` |
| `subset` | `subset` |
| `sectorId` | `sector_id` |
| `parentTcpId` | `parent_tcp_id` (741 rows) |
| `terminalSector` | `terminal_sector` (946 rows) |

### Restriction fields
All 17 fields covered (see section 2.3).

### Auto ATC Rule fields
All fields from 4 rule types covered:
- `descentRestriction` (crossing-line + altitude + speed + altimeter)
- `descentCrossingRestriction` (fix-based, 606 rules)
- `descendVia` (STAR-based, 203 rules)
- `criteria` (routes, airports, aircraft types, altitude range)

---

## 7. Corrections Applied During Audit

| Issue | Resolution |
|-------|-----------|
| `aliases_updated_at` missing from schema | Add column to `vnas_facilities` |
| `visibility_centers_json` buried in eram_config_json | Add as separate JSON column on `vnas_facilities` (top-level field, not ERAM) |
| STARS TCPs `terminalSector` was BIT in early draft but 946 rows have it as a truthy value | Confirmed: stored as BIT NULL (it's a boolean in source JSON — some have it, some don't) |
| STARS beacon banks counted separately from ERAM banks | Both stored in `vnas_beacon_banks` with `source_system` discriminator |
| Auto ATC `descentCrossingRestriction` (606 rules) was initially missed | Added `crossing_*` columns to `vnas_auto_atc_rules` |
| Auto ATC `descendVia` (203 rules) was initially missed | Added `descend_via_*` columns |
| Auto ATC `criteria.minAltitude/maxAltitude` (152/14 rules) initially missed | Added columns |
| Auto ATC `descentRestriction.speedConstraint` (1 rule) initially missed | Added `descent_speed_*` columns |
| Auto ATC `altitudeConstraint.luflStationId` (1 rule) initially missed | Added `descent_lufl_station_id` column |
