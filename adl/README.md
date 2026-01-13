# ADL Database Redesign Implementation

**Version:** 2.0  
**Status:** Data Ingestion Ready  
**Last Updated:** January 2025

## Overview

This directory contains the implementation of the ADL (Aggregate Demand List) database redesign. The redesign transforms the monolithic `adl_flights` table into a normalized, GIS-enabled architecture optimized for PERTI's traffic flow management operations.

## Directory Structure

```
adl/
├── README.md                    # This file
├── migrations/                  # SQL migration scripts (run in order)
│   ├── 001_adl_core_tables.sql      # Core normalized tables
│   ├── 002_adl_times_trajectory.sql # Time fields & position history
│   ├── 003_adl_waypoints_stepclimbs.sql # GIS parsing support
│   ├── 004_adl_reference_tables.sql # Navigation data tables
│   └── 005_adl_views_seed_data.sql  # Compatibility view & seed data
├── procedures/                  # Stored procedures
│   ├── fn_GetParseTier.sql          # Tier assignment function
│   ├── fn_GetTokenType.sql          # Route token classification
│   ├── sp_ParseQueue.sql            # Queue management procedures
│   ├── sp_ParseRoute.sql            # Full GIS route parsing
│   └── sp_UpsertFlight.sql          # Data ingestion from VATSIM/SimTraffic
├── php/                         # PHP helper classes and daemons
│   ├── AdlFlightUpsert.php          # PHP wrapper for sp_UpsertFlight
│   ├── parse_queue_daemon.php       # Route parsing queue processor
│   └── boundary_daemon.php          # ARTCC/TRACON boundary detection
└── reference_data/              # Scripts to import reference data
    ├── import_all.php               # Master script - runs all imports
    ├── import_nav_fixes.php         # Import points.csv + navaids.csv
    ├── import_airways.php           # Import awys.csv
    ├── import_cdrs.php              # Import cdrs.csv
    ├── import_playbook.php          # Import playbook_routes.csv
    └── import_procedures.php        # Import dp/star_full_routes.csv
```

## Database Architecture

PERTI uses **two separate databases**:

| Database | Type | Connection | Purpose |
|----------|------|------------|----------|
| `perti_site` | Azure MySQL | `$conn_pdo` / `$conn_sqli` | Website, users, events, TMI |
| `VATSIM_ADL` | Azure SQL Server | `$conn_adl` | Flight data, routes, positions |

The ADL tables and procedures in this directory target the **VATSIM_ADL** database.

Connection credentials are defined in `load/config.php` and connections are established in `load/connect.php`.

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

Three separate daemons handle data processing:

```bash
# 1. Main VATSIM data ingestion (every 15s)
nohup php scripts/vatsim_adl_daemon.php > scripts/vatsim_adl.log 2>&1 &

# 2. Route parsing daemon (every 5s, auto-scales to 500 batch when backlogged)
nohup php adl/php/parse_queue_daemon.php --loop > scripts/parse_queue.log 2>&1 &

# 3. Boundary detection daemon (every 30s, ARTCC/TRACON/Crossings)
nohup php adl/php/boundary_daemon.php --loop > scripts/boundary.log 2>&1 &
```

| Daemon | Purpose | Interval | Batch Size |
|--------|---------|----------|------------|
| `vatsim_adl_daemon.php` | VATSIM feed + ATIS parsing | 15s | N/A |
| `parse_queue_daemon.php` | Route expansion to waypoints | 5s | 50 (500 if backlogged) |
| `boundary_daemon.php` | ARTCC/TRACON detection + crossings | 30s | 500 (1000 if backlogged) |

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
| `adl_flight_times` | 40+ TFMS time fields | Every refresh |
| `adl_flight_trajectory` | Position history | Every refresh |
| `adl_flight_tmi` | TMI controls | Every refresh |
| `adl_flight_aircraft` | Aircraft info | On change |
| `adl_flight_changelog` | Audit trail | Every refresh |
| `adl_parse_queue` | Async parsing queue | Continuous |

### Route Parsing Features

The `sp_ParseRoute` procedure provides full GIS route expansion:

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

## Performance Targets

| Metric | Target |
|--------|--------|
| Main refresh | <8 seconds |
| Peak refresh | <12 seconds |
| Tier 0 parse latency | <5 seconds |
| Spatial query response | <500ms |

## Azure Configuration

Recommended: **General Purpose Serverless, 2 vCore**

| Resource | Specification |
|----------|---------------|
| Compute | Serverless GP, 2-4 vCore auto-scale |
| Storage | 100 GB |
| Backup | Geo-redundant, 7-day retention |
| Estimated Cost | $250-300/month |

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
- [ ] Position geo-coding trigger
- [ ] Trajectory geo-coding trigger
- [ ] Archive maintenance procedures
- [x] API endpoint updates (api/adl/current.php, flight.php, stats.php, snapshot_history.php)
- [ ] Production cutover

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

See the project knowledge base for:
- ADL_Database_Redesign_Complete_v2.md - Full architecture document
- ADL_Tiered_Parsing_Strategy.md - Detailed tier logic
- ADL_Performance_Cost_Analysis.md - Cost analysis

## Contact

For questions about this implementation, see the PERTI project documentation or contact the development team.
