# VATSWIM x vNAS Ecosystem Integration Opportunities

**Date**: 2026-04-06
**Status**: Analysis Complete
**Scope**: Every integration opportunity between PERTI/VATSWIM and the 7 vNAS ecosystem components

---

## Executive Summary

PERTI/VATSWIM currently has **3 active touchpoints** with the vNAS ecosystem:
1. **Controller Feed Polling** (`VNASService.php` + `vnas_controller_poll.php`) -- consumes `controllers.json`
2. **vNAS Push Ingest** (4 endpoints under `api/swim/v1/ingest/vnas/`) -- receives track, tag, handoff, controller data
3. **FSD Bridge** (`integrations/fsd-bridge/`) -- translates SWIM events to FSD protocol for CRC/EuroScope

This analysis identifies **18 new integration opportunities** across all 7 vNAS components, organized by component. Each opportunity includes: what exists today, what the opportunity is, what data flows, what we'd need to build, and dependencies/blockers.

---

## Table of Contents

1. [Current State: What Exists Today](#1-current-state)
2. [vNAS Server Integrations](#2-vnas-server)
3. [CRC Integrations](#3-crc)
4. [vStrips Integrations](#4-vstrips)
5. [vTDLS Integrations](#5-vtdls)
6. [Restrictions Viewer Integrations](#6-restrictions-viewer)
7. [Data Admin Integrations](#7-data-admin)
8. [ATCTrainer Integrations](#8-atctrainer)
9. [Cross-Cutting Opportunities](#9-cross-cutting)
10. [Priority Matrix](#10-priority-matrix)
11. [Dependency Map](#11-dependency-map)

---

## 1. Current State

### 1A. Controller Feed Polling (LIVE)

**Files**:
- `load/services/VNASService.php` -- polls `https://live.env.vnas.vatsim.net/data-feed/controllers.json`
- `scripts/vnas_controller_poll.php` -- daemon (60s cycle, circuit breaker pattern, WebSocket publish)

**Data consumed**: Controller objects with `artccId`, `primaryFacilityId`, `primaryPositionId`, `positions[]` (each with `facilityId`, `positionName`, `frequency`, `eramData`/`starsData`), `vatsimData` (CID, callsign, rating).

**Used for**: Enriching SWIM controller data, detecting active controllers for EDCT delivery routing.

### 1B. vNAS Push Ingest (BUILT, not wired to vNAS Server)

4 endpoints under `api/swim/v1/ingest/vnas/`:

| Endpoint | Batch Max | Auth Field | Key Data Fields |
|---|---|---|---|
| `track.php` | 1000 | `track` | callsign, lat/lon, altitude_ft, ground_speed_kts, track_deg, vertical_rate_fpm, beacon_code, track_quality (source, mode_c, mode_s, ads_b, position_quality 0-9) |
| `tags.php` | 500 | `track` | assigned_altitude, interim_altitude, assigned_speed/mach/heading, scratchpad (1/2/3), point_out_sector, coordination_status (UNTRACKED/TRACKED/ASSOCIATED/SUSPENDED), conflict_alert, msaw_alert |
| `handoff.php` | 200 | `track` | handoff_type (AUTOMATED/MANUAL/POINT_OUT), from_sector, to_sector, status (INITIATED/ACCEPTED/REJECTED/RECALLED/COMPLETED), boundary_fix |
| `controllers.php` | -- | `track` | Controller enrichment data |

**Status**: Endpoints deployed and functional. Missing: **a connector running inside or alongside the vNAS Server** that pushes data to these endpoints. The C# connector stub exists at `integrations/connectors/vnas/VATSWIMConnector.cs` but requires vNAS Server integration (which Ross Carlson controls).

### 1C. FSD Bridge (BUILT)

**Files**: `integrations/fsd-bridge/` (Go, 15 files)

**Architecture**: Go TCP server listens on port 6809. Consumes SWIM WebSocket events (`flight.*`, `tmi.*`, `cdm.*`, `aman.*`). Translates to FSD packets (`#TM` text messages, `$CR` client query replies). CRC/EuroScope connect as FSD clients.

**Event types handled**: `FlightEvent`, `TMIEvent`, `CDMEvent`, `AMANEvent` (defined in `internal/swim/events.go`).

### 1D. EuroScope Plugin (BUILT)

**Files**: `integrations/euroscope-plugin/` (C++ DLL, 15 files)

**Tag items exposed** (from `TagItems.h`):
- `GetEDCT()`, `GetCTOT()` -- departure clearance times
- `GetTMIStatus()`, `GetTMIDelay()` -- GDP/GS/AFP status
- `GetAMANSequence()`, `GetAMANDelay()` -- arrival manager data
- `GetCDMStatus()`, `GetFlowStatus()` -- CDM and flow status

**Polling**: 15-second REST API polling via `SWIMClient.cpp`.

### 1E. EDCT Delivery (BUILT)

**File**: `load/services/EDCTDelivery.php`

**5-channel priority fallback**:
1. Hoppie CPDLC (via `integrations/hoppie-cpdlc/HoppieClient.php`, 2s spacing)
2. Pilot client plugin polling (VatswimPlugin)
3. CDM WebSocket push
4. Discord DM
5. SimTraffic webhook

### 1F. vFDS TDLS Sync (BUILT)

**File**: `integrations/vfds/src/TDLSSync.php`

Syncs departure list data between vFDS (virtual Flight Data System) and VATSWIM. Posts to `/ingest/adl`.

---

## 2. vNAS Server

### Opportunity 2A: Direct Server-Side Push Connector

**What**: The vNAS Server (.NET 8, SignalR) processes every flight's track data, handoffs, and controller actions. If Ross Carlson were to embed a SWIM push client in the server, all 4 ingest endpoints would light up with real-time data from every ERAM/STARS/Tower Cab session.

**Why it matters**: Today VATSWIM gets flight data from the VATSIM data feed (15s polls via `scripts/vatsim_adl_daemon.php`). vNAS Server has **1-second** track updates via UDP and real-time handoff/tag data that never reaches the VATSIM data feed.

**Data flow**:
```
vNAS Server (.NET 8)
    ├── SignalR Hub events (track update, handoff, tag change)
    │   └── VATSWIMPushClient (embedded .NET HttpClient)
    │       ├── POST /api/swim/v1/ingest/vnas/track   (batch 1000, every 5-15s)
    │       ├── POST /api/swim/v1/ingest/vnas/tags     (batch 500, on change)
    │       ├── POST /api/swim/v1/ingest/vnas/handoff  (batch 200, on event)
    │       └── POST /api/swim/v1/ingest/vnas/controllers (on login/logout)
```

**What we'd need to build**:
1. A .NET HttpClient wrapper that batches and posts to VATSWIM (the C# stub at `integrations/connectors/vnas/VATSWIMConnector.cs` is the starting point)
2. Coordination with Ross Carlson to embed this as an optional plugin/module in vNAS Server
3. A `swim_sys_` API key provisioned for the vNAS Server instance
4. Rate limiting consideration: ~3,000 active flights on busy VATSIM nights = ~200 track batches/min

**Dependencies**: Ross Carlson's approval and integration into vNAS Server codebase. **This is the single highest-value integration** -- everything else is a workaround for not having this.

**Blockers**: vNAS Server is closed-source. We cannot modify it directly. Ross would need to adopt or embed our connector.

### Opportunity 2B: Controller Feed Enhanced Consumption

**What**: The existing `vnas_controller_poll.php` polls `controllers.json` every 60s. We could extract significantly more operational intelligence from this data.

**Why it matters**: The controller feed includes `starsData.assumedTcps[]`, `eramData.sectorId`, and `positions[]` with multiple assumed positions -- this tells us exactly which sectors are open, consolidated, and who's working what.

**Data flow**:
```
controllers.json (already polled)
    └── Enhanced parsing in vnas_controller_poll.php
        ├── Sector consolidation detection (multiple TCPs → one controller)
        ├── Position type mapping (Artcc/Tracon/Atct → PERTI facility model)
        ├── Staffing level inference (count positions per facility)
        └── Write to: adl_boundary.is_staffed, splits active config
```

**What we'd need to build**:
1. Extend `vnas_controller_poll.php` to parse `starsData.assumedTcps[]` and detect consolidation
2. Map vNAS position IDs (ULIDs) to PERTI sector boundaries (by callsign/frequency matching)
3. New table or columns: `vnas_position_map` linking vNAS position ULIDs to PERTI sector IDs
4. Auto-populate splits configurations from live controller data
5. Feed staffing data into the demand monitoring system (`demand_monitors` table)

**Dependencies**: No external dependencies. We already poll the feed. This is purely internal enhancement.

### Opportunity 2C: WebSocket Event Subscription from vNAS

**What**: If vNAS Server ever exposes a WebSocket or SignalR endpoint for external consumers (beyond the controller feed), VATSWIM could subscribe to real-time events instead of polling.

**Status**: Not currently available. The vNAS Server uses SignalR internally between CRC clients and server, but does not expose a public subscription API. The controller feed JSON endpoint is the only external data surface.

**What we'd need**: Ross to expose a read-only SignalR hub or webhook callback. We'd build a .NET or Go client to connect and forward events to our SWIM ingest endpoints.

**Dependencies**: vNAS Server feature request. Low likelihood near-term.

---

## 3. CRC (Consolidated Radar Client)

### Opportunity 3A: EDCT/TMI Display via FSD Bridge

**What**: CRC receives FSD protocol messages. The FSD Bridge (`integrations/fsd-bridge/`) already translates SWIM CDM/TMI events into `#TM` text messages and `$CR` client query replies. Wiring the GDP algorithm output into EDCT delivery → FSD Bridge would make EDCTs visible in CRC's Messages window.

**Why it matters**: Controllers issuing GDPs via PERTI currently have no way to see EDCT assignments in CRC without switching to the PERTI web UI.

**Data flow**:
```
GDP Algorithm (sp_TMI_ApplyGDP in VATSIM_TMI)
    → executeDeferredTMISync() in ADL daemon
    → EDCTDelivery.php (Priority 2: VatswimPlugin channel)
    → SWIM WebSocket (cdm.edct_assigned event)
    → FSD Bridge (Go) subscriber
    → translator.go handleCDM()
    → #TM text message → CRC Messages window
```

**What we'd need to build**:
1. **Wire GDP output to EDCTDelivery** -- the `executeDeferredTMISync()` function in the ADL daemon needs to call `EDCTDelivery->deliver()` when EDCT changes are detected (currently the GDP applies EDCTs to `tmi_flight_control` but doesn't trigger delivery)
2. **WebSocket event emission** -- ensure `cdm.edct_assigned` events are published to the SWIM WebSocket when EDCTs change
3. **FSD Bridge deployment** -- the Go binary needs to be compiled, configured with SWIM WS URL and API key, and run alongside CRC
4. **CRC connection** -- CRC must be configured to connect to the FSD Bridge's local TCP port as a secondary server (or the bridge needs to inject into an existing FSD connection)

**Dependencies**:
- FSD Bridge needs a deployment/packaging strategy (standalone binary + config)
- CRC's FSD client handling -- CRC connects to vNAS Server, not to arbitrary FSD servers. The FSD Bridge may need to operate as a **proxy** (CRC → Bridge → vNAS Server) or deliver via Hoppie CPDLC instead.
- **Key blocker**: CRC has NO plugin system (confirmed in vNAS ecosystem reference). The only external data path into CRC is FSD protocol or Hoppie ACARS.

### Opportunity 3B: CRC Scratchpad/Data Block Enrichment

**What**: CRC's ERAM and STARS display scratchpad fields (up to 8 chars ERAM, 3-4 chars STARS). If VATSWIM could push TMI status codes into controller scratchpads via the vNAS ingest path, controllers would see GDP/GS/reroute status directly on their radar scope.

**Example**: Flight AAL123 under a GDP with +15 min delay → scratchpad shows `GDP+15`

**Data flow**:
```
VATSWIM TMI status for flight
    → POST /api/swim/v1/ingest/vnas/tags (scratchpad field)
    → vNAS Server applies to flight's data block
    → CRC displays in ERAM Field E / STARS scratchpad
```

**What we'd need to build**:
1. A scratchpad formatting function that encodes TMI status into 3-8 character codes
2. Integration point in the TMI sync daemon that pushes scratchpad updates when TMI status changes
3. **Requires Opportunity 2A** (direct server push) or a reverse path where VATSWIM writes to vNAS Server

**Dependencies**: Requires vNAS Server to accept inbound scratchpad writes from VATSWIM. Currently the `tags.php` ingest endpoint accepts scratchpad data, but there's no mechanism to push it **back** to vNAS Server for display in CRC. This is a **bidirectional** requirement.

**Blocker**: vNAS Server would need to expose a write API for external scratchpad updates. Not currently available.

### Opportunity 3C: Auto ATC Rule Synchronization

**What**: vNAS Data Admin defines 1,188 Auto ATC rules across 24 ARTCCs. These contain descent restrictions (crossing lines, altitudes, speeds) that are functionally identical to PERTI's TMI restriction definitions (`tmi_entries`, `tmi_reroutes`). Synchronizing these would prevent controllers from defining the same restrictions in two places.

**Data flow**:
```
Data Admin Auto ATC Rules (per ARTCC JSON config)
    → Parse from CRC local data (%LOCALAPPDATA%/CRC/ARTCCs/*.json)
       OR from Data Admin API (if exposed)
    → Map to PERTI restriction format
    → Insert/update tmi_entries or config_data tables
```

**What we'd need to build**:
1. Parser for CRC ARTCC JSON format (the `autoAtcRules` array within each ARTCC config)
2. Mapping layer: Auto ATC rule fields → PERTI restriction fields
   - `crossingLine` (lat/lon array) → PERTI restriction geometry
   - `altitude`, `constraint` → PERTI altitude restriction
   - `departureAirports`, `destinationAirports`, `routeSubstrings` → PERTI criteria
3. Sync daemon or import script (run on AIRAC cycle change)
4. Conflict resolution logic when both PERTI and vNAS define overlapping restrictions

**Dependencies**:
- CRC local data access (only available on machines with CRC installed)
- OR Data Admin API (not publicly documented; would need Ross's cooperation)
- The Auto ATC rules are in CRC's local JSON configs which we analyzed (`%LOCALAPPDATA%/CRC/ARTCCs/*.json`)

### Opportunity 3D: Video Map / GeoMap Reference Data

**What**: CRC's 15,003 GeoJSON video maps contain authoritative runway, taxiway, NAVAID, airway, and sector boundary geometry. PERTI's PostGIS database has its own boundary and airport geometry. Cross-referencing or importing vNAS video map data could improve PERTI's spatial accuracy.

**Data available**: Each GeoJSON file contains LineStrings, Polygons, Points with properties: `color`, `bcg`, `filters[]`, `zIndex`, `style`, `thickness`, `text[]`.

**What we'd need to build**:
1. GeoJSON parser that extracts airport diagram geometry (runways, taxiways) from Tower Cab/ASDE-X maps
2. Sector boundary extraction from ERAM GeoMaps
3. Import pipeline to PostGIS (`artcc_boundaries`, `tracon_boundaries`, `sector_boundaries` tables)
4. Reconciliation logic against existing PostGIS data

**Dependencies**: CRC local data access. Video maps are 15K+ files totaling several hundred MB.

---

## 4. vStrips (Virtual Flight Strips)

### Opportunity 4A: Strip Bay TMI Annotations

**What**: vStrips displays flight progress strips with editable fields (8A, 8B). If VATSWIM could push TMI status into these fields, tower controllers using vStrips would see GDP delay, EDCT times, or reroute status on their strips.

**Data flow**:
```
VATSWIM TMI status
    → vNAS Server (if it accepts strip field updates from external sources)
    → vStrips shared strip bays
    → Controllers see TMI annotations on flight strips
```

**What we'd need to build**:
1. TMI status formatting for strip fields (compact: `E1430Z` for EDCT, `GDP+12` for delay)
2. Write pathway to vStrips (via vNAS Server API, if one exists for strip field updates)
3. Fallback: Display TMI info in a separate PERTI web panel that tower controllers can position alongside vStrips

**Dependencies**: vNAS Server would need to support external writes to strip fields. **Not currently available.** vStrips updates flow through CRC/vNAS Server only.

**Alternative approach**: Build a companion web widget at `perti.vatcscc.org/strips-overlay` that shows TMI status for flights in the controller's facility. Controllers position it alongside vStrips. No vNAS integration required -- just consumes SWIM API.

### Opportunity 4B: Departure Sequence from vStrips Strip Order

**What**: The order of strips in vStrips bays represents the controller's intended departure sequence. If VATSWIM could read this order, it could feed the CDM system's TOBT (Target Off-Block Time) predictions.

**Data flow**:
```
vStrips strip bay order
    → vNAS Server (internal state)
    → Controller feed or new API endpoint
    → VATSWIM CDM system (adl_flight_times.tobt_utc)
```

**What we'd need to build**:
1. vNAS Server would need to expose strip bay order via API (not currently available)
2. Polling daemon to consume strip order data
3. CDM algorithm to infer TOBT from strip position relative to current time

**Dependencies**: Requires new vNAS Server API. **Not feasible without Ross's cooperation.**

---

## 5. vTDLS (Virtual Tower Data Link Services)

### Opportunity 5A: EDCT Injection into PDC Messages

**What**: vTDLS sends Pre-Departure Clearances (PDCs) to pilots. PDCs contain SID, transition, initial altitude, departure frequency, and "Local Info" (free text, 32 char max). If VATSWIM could inject EDCT information into the Local Info field, pilots would receive their EDCT as part of their PDC.

**Example**: Local Info: `EDCT 1430Z GDP KJFK VOLUME`

**Data flow**:
```
VATSWIM GDP algorithm assigns EDCT to flight
    → vTDLS PDC preparation (controller opens clearance editor)
    → Local Info field pre-populated with EDCT text
    → Controller sends PDC → pilot receives EDCT in-band
```

**What we'd need to build**:
1. vTDLS would need to query VATSWIM for EDCT status when preparing a PDC
   - OR: vNAS Server queries VATSWIM and pre-populates Local Info
2. VATSWIM API endpoint: `GET /api/swim/v1/cdm/edct?callsign=AAL123` (returns EDCT if active)
3. vTDLS integration to call this endpoint (requires vNAS Server modification)

**Dependencies**: Requires vNAS Server/vTDLS modification by Ross. The TDLS configuration is managed in Data Admin under "TDLS Configuration" per facility. The `LOCAL INFO` field supports predefined values set by Facility Engineers. VATSWIM could potentially become a "data source" for Local Info values if integrated.

**Existing touchpoint**: `integrations/vfds/src/TDLSSync.php` already syncs departure list data -- this pattern could be extended.

### Opportunity 5B: PDC Status Feedback to VATSWIM

**What**: When vTDLS sends a PDC, the flight moves from DCL to PDC list. This state change indicates the pilot has been cleared. VATSWIM's CDM system could use this as a milestone: "clearance delivered" → update `cdm_messages.status`.

**Data flow**:
```
vTDLS sends PDC
    → vNAS Server records clearance delivery event
    → Controller feed or webhook
    → VATSWIM CDM: flight.clearance_delivered_utc
    → CDM compliance: time from clearance to pushback
```

**What we'd need to build**:
1. vNAS Server event or controller feed extension for PDC delivery events
2. VATSWIM listener (extend `vnas_controller_poll.php` or new webhook)
3. CDM table column: `clearance_delivered_utc` in `adl_flight_times`

**Dependencies**: Requires vNAS Server to expose PDC events. Not currently in the controller feed.

---

## 6. Restrictions Viewer

### Opportunity 6A: PERTI TMI Restrictions → Restrictions Viewer

**What**: The vNAS Restrictions Viewer (`restrictions.virtualnas.net`) displays altitude, route, heading, and speed restrictions defined in Data Admin. PERTI defines its own TMI restrictions (`tmi_entries`, `tmi_reroutes`). Publishing PERTI's active TMI restrictions to the Restrictions Viewer would give controllers a single place to see both permanent and flow-control restrictions.

**Data flow**:
```
PERTI TMI restrictions (tmi_entries, tmi_reroutes)
    → Format as vNAS restriction objects
    → Push to Data Admin API (if writable)
    → Appears in Restrictions Viewer with "ATCSCC" or "TMI" label
```

**What we'd need to build**:
1. Restriction format translator: PERTI TMI → vNAS restriction schema
   - vNAS restrictions have: owning facility/sectors, requesting facility/sectors, criteria (airports, route, flow, flight type), location (fix/boundary), altitude/heading/speed restrictions, notes
2. Data Admin write API (not currently documented as publicly accessible)
3. Lifecycle management: create on TMI issuance, delete on TMI cancellation

**Dependencies**: Requires Data Admin write API access. Ross would need to expose this or agree to automated restriction creation.

**Alternative**: Publish PERTI's active TMI restrictions as a **standalone Restrictions Viewer clone** at `perti.vatcscc.org/restrictions` that mirrors the vNAS Restrictions Viewer UI pattern but sources data from PERTI's TMI database. Controllers bookmark both.

### Opportunity 6B: Restrictions Viewer Data → PERTI Reference

**What**: Import vNAS restriction definitions into PERTI's reference data so the demand analysis system can account for standing restrictions when computing flow rates.

**Data available** (from Data Admin docs):
- Location restrictions: Fix or Boundary
- Altitude restrictions: at/above/below/between/climbing/descending via/any of/eastbound/westbound
- Speed restrictions: at/above/below
- Heading restrictions
- Criteria: applicable airports, route patterns, flow, flight type, aircraft types

**What we'd need to build**:
1. Scraper or API client for Data Admin restriction data (HTML scrape if no API, or parse from CRC ARTCC JSON configs)
2. Mapping: vNAS restriction → PERTI `config_data` or new `vnas_restrictions` reference table
3. Integration into demand analysis: restrictions that reduce AAR/ADR → adjust rate calculations

**Dependencies**: Data Admin API or CRC local data parsing.

---

## 7. Data Admin

### Opportunity 7A: Facility/Position Reference Sync

**What**: Data Admin is the authoritative source for facility hierarchy (24 ARTCCs → 782 facilities → 3,990 positions), position frequencies, callsigns, and sector assignments. PERTI maintains its own facility reference (`artcc_facilities`, `sector_boundaries`). Synchronizing would ensure PERTI's facility model matches vNAS exactly.

**Data available** (from CRC ARTCC JSON configs):
- Facility IDs, names, types (ARTCC/TRACON/ATCT/ATCT-TRACON/ATCT-RAPCON)
- Position names, callsigns, frequencies (Hz), radio names
- ERAM sector IDs, STARS TCPs/areas/subsets
- Transceiver locations (lat/lon/height)
- Neighboring facility references

**What we'd need to build**:
1. Parser for CRC ARTCC JSON format (24 files, ~100-500KB each)
2. Mapping: vNAS facility model → PERTI `artcc_facilities` + `sector_boundaries`
3. Position-to-sector mapping: vNAS position ULID → PERTI sector polygon
4. Periodic sync (on AIRAC cycle or monthly)
5. Reconciliation report: differences between vNAS and PERTI facility data

**Dependencies**: CRC local data access, or Data Admin API.

**Value**: Eliminates manual maintenance of PERTI's facility reference. Currently 782 facilities × position data is manually curated.

### Opportunity 7B: TDLS Configuration Import for CDM

**What**: Data Admin's TDLS configuration defines which airports have TDLS, what SIDs are available, what clearance fields are mandatory, and default values. Importing this into PERTI's CDM system would let VATSWIM know which airports can receive PDCs and what their clearance workflow looks like.

**Data available**:
- TDLS-enabled airports per facility
- SID/transition definitions with default clearance field values
- Mandatory field configuration
- Contact info and departure frequency templates

**What we'd need to build**:
1. Parse TDLS config from CRC ARTCC JSON or Data Admin
2. New reference table: `vnas_tdls_airports` with SID/transition/clearance field data
3. CDM enhancement: use TDLS availability to predict clearance delivery time
4. EDCT delivery enhancement: if airport has TDLS, prefer PDC-path EDCT delivery

**Dependencies**: CRC local data or Data Admin API.

### Opportunity 7C: Beacon Code Bank Synchronization

**What**: Data Admin defines beacon code banks per ARTCC and TRACON (start/end ranges, utilization type, priority, VFR codes). PERTI's ADL system tracks beacon codes but doesn't know which ranges are allocated to which facilities. Importing this would enable beacon code conflict detection.

**What we'd need to build**:
1. Parse beacon code allocations from CRC ARTCC JSON
2. New reference table: `vnas_beacon_banks` (facility, start_code, end_code, type, priority)
3. ADL enhancement: flag flights squawking codes outside their facility's allocation

**Dependencies**: CRC local data.

---

## 8. ATCTrainer

### Opportunity 8A: PERTI Scenario Replay for Training

**What**: ATCTrainer supports scenarios with predefined aircraft, weather, and ATC positions. PERTI has historical flight data (1.6M+ flights in `adl_flight_core`) and TMI records. Exporting real VATSIM traffic snapshots as ATCTrainer scenarios would let controllers practice with realistic traffic patterns.

**Data flow**:
```
PERTI historical flight data
    → Filter by date/time/airport
    → Format as ATCTrainer scenario JSON
    → Export: aircraft (callsign, type, position, route, altitude)
    → Export: weather (wind layers, METARs)
    → Import into ATCTrainer
```

**What we'd need to build**:
1. ATCTrainer scenario format parser/writer (from Data Admin training docs):
   - Aircraft: callsign (8 max), ICAO type, flight plan, starting conditions (lat/lon/alt/speed/heading)
   - Weather: wind layers (alt/dir/speed/gusts), METARs
   - ATC positions: ARTCC, facility, position
2. PERTI export endpoint: `GET /api/data/training/scenario?airport=KJFK&date=2026-03-15&start=1200&end=1400`
3. ATCTrainer import mechanism (file-based, via Data Admin training page)

**Dependencies**: ATCTrainer scenario format documentation (captured in Data Admin training docs -- 34KB). No API integration needed -- file-based export/import.

### Opportunity 8B: TMI Training Scenarios

**What**: Create ATCTrainer scenarios that specifically exercise TMI operations (GDP issuance, EDCT compliance monitoring, ground stop management). Currently ATCTrainer has no TMI simulation capability.

**What we'd need to build**:
1. Scenario templates with pre-configured GDP/GS conditions
2. Aircraft with preset EDCT times (via ATCTrainer's "Preset Commands" feature)
3. Documentation for training staff on how to use TMI scenarios
4. Integration: ATCTrainer scenario could trigger a PERTI TMI simulation mode

**Dependencies**: ATCTrainer scenario format knowledge. No API integration -- file-based.

---

## 9. Cross-Cutting Opportunities

### Opportunity 9A: Unified Authentication via VATSIM Connect

**What**: Both PERTI and all vNAS components authenticate via VATSIM Connect OAuth 2.0. Today PERTI has its own session system (`sessions/handler.php`). Sharing controller identity would enable seamless transitions between PERTI and vNAS tools.

**What we'd need to build**:
1. PERTI already uses VATSIM Connect for login -- the overlap exists
2. Map VATSIM CID → vNAS controller state via controller feed
3. Auto-populate PERTI plan authorship with vNAS position context
4. Deep-link from PERTI to vNAS tools with pre-authenticated URLs (if VATSIM Connect supports cross-origin SSO)

**Dependencies**: VATSIM Connect SSO capabilities (standard OAuth -- likely works via shared cookies on `*.vatsim.net` domain).

### Opportunity 9B: SWIM API as Universal Data Layer

**What**: Make VATSWIM the canonical data API that vNAS tools could optionally consume. Today the SWIM API serves 80+ endpoints with multi-format output (JSON, FIXM, XML, GeoJSON, CSV, KML, NDJSON). vNAS tools (vStrips, vTDLS, Restrictions Viewer) could query SWIM for enriched flight data instead of relying solely on vNAS Server data.

**Example**: vStrips could display SWIM-sourced ETAs, CDM milestones, or TMI status alongside strip data.

**What we'd need to build**:
1. SWIM API is already built and running
2. Documentation: publish SWIM API docs to `perti.vatcscc.org/swim-docs`
3. JavaScript SDK (`sdk/javascript/`) could be embedded in vNAS web apps
4. CORS configuration to allow requests from `*.virtualnas.net` origins
5. Public-tier API keys for vNAS web apps

**Dependencies**: Ross's willingness to integrate SWIM API calls into vNAS web apps. Or: community adoption where controllers use PERTI's web tools alongside vNAS tools.

### Opportunity 9C: Bidirectional TMI Coordination

**What**: PERTI manages TMIs (GDP, GS, reroutes, advisories). vNAS manages restrictions and Auto ATC rules. A bidirectional sync would mean:
- When PERTI issues a GDP at KJFK, vNAS Auto ATC rules could automatically enforce speed/altitude restrictions for KJFK arrivals
- When a Data Admin FE defines a new restriction, PERTI's TMI system would incorporate it

**What we'd need to build**:
1. PERTI TMI → vNAS Auto ATC rule format translator
2. Push mechanism to Data Admin (API or file-based config update)
3. vNAS restriction → PERTI TMI format translator
4. Sync daemon running on PERTI with bidirectional conflict resolution

**Dependencies**: Data Admin write API. Significant coordination with Ross and VATUSA FE community.

---

## 10. Priority Matrix

| # | Opportunity | Impact | Effort | Dependencies | Priority |
|---|---|---|---|---|---|
| 2B | Enhanced Controller Feed Parsing | High | Low | None (internal) | **P0 -- Do Now** |
| 3A | EDCT/TMI via FSD Bridge | High | Medium | FSD Bridge deployment | **P1** |
| 7A | Facility/Position Reference Sync | High | Medium | CRC local data parsing | **P1** |
| 3C | Auto ATC Rule Sync | Medium | Medium | CRC local data parsing | **P1** |
| 8A | Training Scenario Export | Medium | Low | ATCTrainer format docs | **P1** |
| 5A | EDCT in PDC Local Info | Critical | High | Ross / vNAS Server changes | **P2** |
| 6A | TMI Restrictions → Restrictions Viewer | High | High | Data Admin write API | **P2** |
| 9B | SWIM as Universal Data Layer | Critical | Low | CORS + docs + community | **P2** |
| 2A | Direct Server Push Connector | Critical | Medium | Ross / vNAS Server embed | **P2** |
| 6B | Restrictions → PERTI Reference | Medium | Medium | Data scraping or API | **P2** |
| 7B | TDLS Config Import | Medium | Low | CRC local data parsing | **P2** |
| 7C | Beacon Code Bank Sync | Low | Low | CRC local data parsing | **P3** |
| 3B | Scratchpad TMI Enrichment | High | High | Bidirectional vNAS API | **P3** |
| 4A | Strip Bay TMI Annotations | Medium | High | vNAS Server write API | **P3** |
| 4B | Departure Sequence from vStrips | Medium | High | vNAS Server API | **P3** |
| 5B | PDC Status Feedback | Medium | High | vNAS Server event API | **P3** |
| 8B | TMI Training Scenarios | Low | Low | Scenario format knowledge | **P3** |
| 2C | WebSocket from vNAS Server | Critical | High | vNAS Server feature | **P4** |
| 9A | Unified Auth | Low | Low | VATSIM Connect SSO | **P4** |
| 9C | Bidirectional TMI Coordination | Critical | Very High | Data Admin API + Ross | **P4** |

**Legend**: P0 = no blockers, do immediately. P1 = achievable independently. P2 = requires external coordination. P3 = requires vNAS Server changes. P4 = long-term vision.

---

## 11. Dependency Map

```
Ross Carlson / vNAS Server (EXTERNAL)
    Blocks: 2A, 2C, 3B, 4A, 4B, 5A, 5B, 6A, 9C
    Partial: 3A (FSD proxy approach avoids this)

CRC Local Data (%LOCALAPPDATA%/CRC/)
    Enables: 3C, 3D, 6B, 7A, 7B, 7C
    Available: Yes (on any machine with CRC installed)
    Size: 24 ARTCC JSONs + 15K GeoJSON maps

Controller Feed (already consumed)
    Enables: 2B (enhanced parsing)
    Available: Yes, live at https://live.env.vnas.vatsim.net/data-feed/controllers.json

FSD Bridge (already built)
    Enables: 3A
    Status: Go binary built, needs deployment packaging

SWIM WebSocket (already built)
    Enables: 3A, 9B
    Status: Running on port 8090

SWIM REST API (already built)
    Enables: 5A, 9B
    Status: 80+ endpoints, production

ATCTrainer Scenario Format
    Enables: 8A, 8B
    Status: Documented in Data Admin training docs (34KB captured in C:\Temp\da-training.md)
```

---

## Appendix A: Validated File Inventory

All file paths verified via Glob/Read on 2026-04-06:

**Existing vNAS Integration Code:**
- `load/services/VNASService.php` -- Controller feed client (60s cache, CURL)
- `scripts/vnas_controller_poll.php` -- Controller feed daemon (circuit breaker, PID file)
- `api/swim/v1/ingest/vnas/track.php` -- Track ingest (1000 batch, lat/lon/alt/speed/heading/beacon)
- `api/swim/v1/ingest/vnas/tags.php` -- Tag ingest (500 batch, altitude/speed/mach/heading/scratchpad/coordination)
- `api/swim/v1/ingest/vnas/handoff.php` -- Handoff ingest (200 batch, type/sectors/status/boundary_fix)
- `api/swim/v1/ingest/vnas/controllers.php` -- Controller enrichment
- `integrations/connectors/vnas/VATSWIMConnector.cs` -- C# connector stub
- `integrations/connectors/vnas/example_payload.json` -- Example track payload
- `integrations/fsd-bridge/` -- Go FSD bridge (15 files)
- `integrations/euroscope-plugin/` -- C++ EuroScope plugin (15 files)
- `integrations/hoppie-cpdlc/` -- PHP Hoppie ACARS client
- `integrations/vfds/src/TDLSSync.php` -- vFDS TDLS sync
- `load/services/EDCTDelivery.php` -- 5-channel EDCT delivery
- `api/swim/v1/ws/WebSocketServer.php` -- SWIM WebSocket server
- `api/swim/v1/ws/SubscriptionManager.php` -- WS subscription management
- `database/migrations/swim/021_vnas_integration_schema.sql` -- vNAS columns on swim_flights

**Design Docs:**
- `docs/plans/2026-03-30-vatswim-client-bridges-design.md` -- Bridge architecture
- `docs/plans/2026-03-30-vatswim-client-bridges-plan.md` -- Bridge implementation plan
- `docs/reference/vnas-ecosystem-reference.md` -- 848-line ecosystem reference

**vNAS Feed Schema** (from `VNASService.php` + controller feed docs):
- Feed URL: `https://live.env.vnas.vatsim.net/data-feed/controllers.json`
- Controller fields: `artccId`, `primaryFacilityId`, `primaryPositionId`, `positions[]`, `isActive`, `isObserver`, `loginTime`, `vatsimData` (cid, realName, callsign, userRating, primaryFrequency)
- Position fields: `facilityId`, `facilityName`, `positionId` (GUID), `positionName`, `positionType` (Artcc/Tracon/Atct), `radioName`, `frequency` (Hz), `isPrimary`, `isActive`
- ERAM data: `sectorId`
- STARS data: `subset`, `sectorId`, `areaId` (GUID), `assumedTcps[]`
