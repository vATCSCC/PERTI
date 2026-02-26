# ADL Database Redesign Implementation

**Version:** 3.0
**Status:** Deployed & Live
**Last Updated:** February 2026

## Overview

This directory contains the ADL (Aggregate Demand List) subsystem — a normalized, GIS-enabled flight data architecture that powers PERTI's traffic flow management. The system ingests VATSIM flight data every 15 seconds through `sp_Adl_RefreshFromVatsim_Staged` (V9.4.0) and processes it through 8+ background daemons for route parsing, boundary detection, crossing prediction, and ETA calculation.

## Directory Structure

```
adl/
├── README.md                    # This file
├── ARCHITECTURE.md              # Full architecture document
├── migrations/                  # SQL migration scripts (organized by feature)
│   ├── core/                        # Core 8-table flight schema
│   ├── boundaries/                  # Boundary detection tables
│   ├── crossings/                   # Boundary crossing predictions
│   ├── demand/                      # Fix/segment demand functions
│   ├── eta/                         # ETA trajectory calculation
│   ├── navdata/                     # Waypoint/procedure imports
│   ├── changelog/                   # Flight change tracking triggers
│   └── cifp/                        # CIFP procedure legs
├── procedures/                  # Stored procedures & functions
│   ├── fn_GetParseTier.sql          # Tier assignment function
│   ├── fn_GetTokenType.sql          # Route token classification
│   ├── sp_ParseQueue.sql            # Queue management procedures
│   ├── sp_ParseRoute.sql            # Full GIS route parsing (V4)
│   └── sp_UpsertFlight.sql          # Data ingestion from VATSIM/SimTraffic
├── php/                         # PHP daemons and helpers
│   ├── AdlFlightUpsert.php          # PHP wrapper for sp_UpsertFlight
│   ├── parse_queue_gis_daemon.php   # Route parsing via PostGIS (10s batch)
│   ├── boundary_gis_daemon.php      # ARTCC/TRACON boundary detection (15s)
│   ├── crossing_gis_daemon.php      # Boundary crossing predictions (tiered)
│   └── waypoint_eta_daemon.php      # Waypoint ETA calculation (tiered)
└── reference_data/              # Scripts to import reference data
    ├── import_all.php               # Master script - runs all imports
    ├── import_nav_fixes.php         # Import points.csv + navaids.csv
    ├── import_airways.php           # Import awys.csv
    ├── import_cdrs.php              # Import cdrs.csv
    ├── import_playbook.php          # Import playbook_routes.csv
    └── import_procedures.php        # Import dp/star_full_routes.csv
```

## Database Architecture

PERTI uses **7 databases across 3 engines**:

| Database | Type | Connection | Purpose |
|----------|------|------------|----------|
| `perti_site` | Azure MySQL | `$conn_pdo` / `$conn_sqli` | Plans, users, configs, staffing |
| `VATSIM_ADL` | Azure SQL | `get_conn_adl()` | Flight data, routes, positions, stats |
| `VATSIM_TMI` | Azure SQL | `get_conn_tmi()` | Traffic management initiatives |
| `VATSIM_REF` | Azure SQL | `get_conn_ref()` | Reference data (navdata, airways) |
| `SWIM_API` | Azure SQL | `get_conn_swim()` | Public API (FIXM-aligned schema) |
| `VATSIM_GIS` | PostgreSQL/PostGIS | `get_conn_gis()` | Spatial queries, boundary polygons |
| `VATSIM_STATS` | Azure SQL | — | Statistics & analytics |

The ADL tables and procedures in this directory target the **VATSIM_ADL** database. Route parsing, boundary detection, and crossing prediction use **VATSIM_GIS** (PostGIS) for spatial operations.

Connection credentials are defined in `load/config.php` and connections are established in `load/connect.php` with lazy-loading getters.

## Quick Start

### 1. Run Migrations

Execute the migration scripts in order on the **VATSIM_ADL** Azure SQL database:

```sql
-- Run in Azure Data Studio or SSMS
:r 001_adl_core_tables.sql
:r 002_adl_times_trajectory.sql
:r 003_adl_waypoints_stepclimbs.sql
:r 004_adl_reference_tables.sql
:r 005_adl_views_seed_data.sql
```

### 2. Deploy Procedures

```sql
:r procedures/fn_GetParseTier.sql
:r procedures/fn_GetTokenType.sql
:r procedures/sp_ParseQueue.sql
:r procedures/sp_ParseRoute.sql
:r procedures/sp_UpsertFlight.sql
```

### 3. Import Reference Data

Run from command line (from the `adl/reference_data` directory):

```bash
# Import all reference data (recommended)
php import_all.php

# Or run individually in order:
php import_nav_fixes.php     # ~270K waypoints from points.csv + navaids.csv
php import_airways.php       # ~1.2K airways from awys.csv
php import_cdrs.php          # ~2.5K CDRs from cdrs.csv  
php import_playbook.php      # ~3K playbook routes from playbook_routes.csv
php import_procedures.php    # ~10K+ DPs/STARs from dp/star_full_routes.csv
```

**Note:** Run `import_nav_fixes.php` first as other imports depend on fix coordinates.

### 4. Start Data Ingestion Daemons

All daemons are started automatically by `scripts/startup.sh` at App Service boot. Key ADL-related daemons:

| Daemon | Purpose | Interval |
|--------|---------|----------|
| `scripts/vatsim_adl_daemon.php` | VATSIM feed + ATIS + deferred ETA | 15s |
| `adl/php/parse_queue_gis_daemon.php` | Route parsing via PostGIS | 10s batch |
| `adl/php/boundary_gis_daemon.php` | ARTCC/TRACON boundary detection | 15s |
| `adl/php/crossing_gis_daemon.php` | Boundary crossing ETA prediction | Tiered |
| `adl/php/waypoint_eta_daemon.php` | Waypoint-level ETA calculation | Tiered |
| `scripts/swim_sync_daemon.php` | Sync ADL to SWIM_API | 2min |
| `scripts/scheduler_daemon.php` | Splits/routes auto-activation | 60s |
| `scripts/archival_daemon.php` | Trajectory tiering, changelog purge | 1-4h |

See the full 15-daemon list in `scripts/startup.sh`.

Or integrate into existing code:

```php
// Include connection setup (provides $conn_adl for VATSIM_ADL database)
require_once 'load/connect.php';
require_once 'adl/php/AdlFlightUpsert.php';

// $conn_adl is the Azure SQL connection to VATSIM_ADL (not PERTI MySQL)
$adl = new AdlFlightUpsert($conn_adl);

// Process VATSIM API response
$vatsimData = json_decode(file_get_contents('https://data.vatsim.net/v3/vatsim-data.json'), true);
$count = $adl->processVatsimData($vatsimData);

// Or upsert individual flights
$flightUid = $adl->upsert([
    'cid' => 1234567,
    'callsign' => 'AAL123',
    'source' => 'vatsim',
    'lat' => 40.6399,
    'lon' => -73.7787,
    'dept_icao' => 'KJFK',
    'dest_icao' => 'KLAX',
    'route' => 'SKORR5 RNGRR RBV Q430 SAAME...',
    // ... other fields
]);

// Mark stale flights inactive
$adl->markInactive(5);  // 5 minute threshold

// Get stats
$stats = $adl->getStats();
```

## Architecture

### Table Structure

| Table | Purpose | Update Frequency |
|-------|---------|------------------|
| `adl_flight_core` | Master registry, surrogate keys | Every refresh |
| `adl_flight_position` | Real-time position + spatial | Every refresh |
| `adl_flight_plan` | Route + GIS geometry | On FP change |
| `adl_flight_waypoints` | Parsed route waypoints | On FP change |
| `adl_flight_stepclimbs` | Step climb records | On FP change |
| `adl_flight_times` | 50+ TFMS time fields | Every refresh |
| `adl_flight_trajectory` | Position history | Every refresh |
| `adl_flight_tmi` | TMI controls | Every refresh |
| `adl_flight_aircraft` | Aircraft info | On change |
| `adl_flight_changelog` | Audit trail | Every refresh |
| `adl_parse_queue` | Async parsing queue | Continuous |

### Route Parsing Features

Route parsing uses PostGIS (`parse_queue_gis_daemon.php`) for spatial fix matching and airway expansion. The V4 algorithm provides:

- **Token Classification:** Airports, fixes, airways, SIDs, STARs, radials, coordinates
- **SID/STAR Expansion:** Automatic lookup and waypoint insertion from `nav_procedures`
- **Airway Expansion:** Full route extraction from `nav_airways` 
- **CDR Support:** Coded Departure Routes from `nav_cdrs`
- **Geometry Generation:** LineString geometry for spatial queries
- **Metadata Population:** DP name, DFIX, STAR name, AFIX, transitions

### Tiered Parsing Strategy

Routes are parsed asynchronously based on operational relevance:

| Tier | Region | Interval | Condition |
|------|--------|----------|-----------|
| 0 | US/CA/LatAm/Caribbean | 15 sec | <500nm from CONUS |
| 1 | US/CA oceanic approaches | 30 sec | >500nm, in NA oceanic |
| 2 | Europe, South America | 1 min | Non-US domestic |
| 3 | Africa, Middle East | 2 min | Low priority |
| 4 | Asia, Oceania, distant | 5 min | Lowest priority |

## Backward Compatibility

The `vw_adl_flights` view presents normalized data as a single flat structure matching the original `adl_flights` table. Existing queries should work without modification.

## Performance (Current: V9.4.0 + Route Distance V2.2)

| Metric | Typical | Peak |
|--------|---------|------|
| Main refresh | ~3.5 sec | ~5.5 sec |
| Parse queue throughput | 50 routes/batch | 500 when backlogged |
| Spatial query response | <500ms | — |

Key optimizations in production:
- **Delta detection bitmask** filters Steps 1b/2/3/4/6 — ~30-40% SP reduction
- **Geography pre-computation** eliminates ~8,500 Point() CLR calls/cycle — ~12% faster
- **Route Distance V2.2**: Two-pass LINESTRING approach — 25% total SP reduction
- **Covering index** `IX_waypoints_route_calc` eliminates 315K key lookups

## Azure Configuration

**Current: Hyperscale Serverless (HS_S_Gen5_16)**

| Resource | Specification |
|----------|---------------|
| Compute | Hyperscale Serverless, 3 min / 16 max vCores |
| Storage | Auto-scaling |
| Backup | Geo-redundant |
| Estimated Cost | ~$2,100/month (right-sized from $3,300) |

## Development Status

- [x] Migration scripts created
- [x] Core tables defined
- [x] Tier assignment function
- [x] Parse queue procedures
- [x] Reference data import scripts
- [x] Full GIS route parsing (sp_ParseRoute)
- [x] SID/STAR detection and expansion
- [x] Airway expansion
- [x] Route metadata population (DP, STAR, fixes, transitions)
- [x] Expanded route string (fp_route_expanded)
- [x] Data ingestion procedures (sp_UpsertFlight)
- [x] PHP helper class (AdlFlightUpsert)
- [x] VATSIM ingestion daemon
- [x] Parse queue daemon
- [x] Boundary detection daemon (ARTCC/TRACON/Crossings)
- [x] Position geo-coding (PostGIS boundary detection)
- [x] Trajectory tiered archival (live → archive → compressed)
- [x] Archive maintenance procedures (archival_daemon.php)
- [x] API endpoint updates (api/adl/current.php, flight.php, stats.php, snapshot_history.php, demand/*)
- [x] Production cutover (fully live since early 2025)
- [x] GIS-based route parsing (parse_queue_gis_daemon.php)
- [x] Boundary crossing predictions (crossing_gis_daemon.php)
- [x] Waypoint-level ETA calculation (waypoint_eta_daemon.php)
- [x] SWIM sync (swim_sync_daemon.php)
- [x] Delta detection bitmask optimization (V9.3.0+)

## Key Procedures Reference

### sp_UpsertFlight
Upserts flight data from VATSIM/SimTraffic into normalized tables.

```sql
DECLARE @uid BIGINT;
EXEC sp_UpsertFlight 
    @cid = 1234567, 
    @callsign = 'AAL123',
    @source = 'vatsim',
    @lat = 40.123, @lon = -73.456,
    @dept_icao = 'KJFK', @dest_icao = 'KLAX',
    @route = 'SKORR5 RNGRR RBV Q430...',
    @flight_uid = @uid OUTPUT;
```

### sp_ParseRoute
Parses a flight's route into waypoints and geometry.

```sql
EXEC sp_ParseRoute @flight_uid = 12345, @debug = 1;
```

### sp_ParseRouteBatch
Processes multiple routes from the parse queue.

```sql
EXEC sp_ParseRouteBatch @batch_size = 50, @tier = 0;
```

### sp_GetActiveFlightStats
Quick stats on current system state.

```sql
EXEC sp_GetActiveFlightStats;
-- Returns: active_flights, pending_parse, routes_parsed, etc.
```

## Related Documentation

- [ARCHITECTURE.md](ARCHITECTURE.md) - Full architecture document
- [[Data-Flow]] - End-to-end data pipeline
- [[Algorithm-Data-Refresh]] - SP refresh pipeline details
- [[Algorithm-Route-Parsing]] - V4 route parsing algorithm
- [[Algorithm-ETA-Calculation]] - ETA calculation algorithm
- [[Daemons-and-Scripts]] - All background daemons
