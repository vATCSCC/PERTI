# Prong 5: TFMS Adaptation & Configuration Analysis

**Date**: 2026-03-31
**Sources**: TFMS Adaptation Manual (123pp), TFMS Reference Manual v8.9 (998pp), TSD Reference Manual v9.5 (1202pp)
**Purpose**: Extract all TFMS system configuration, adaptation parameters, facility settings, and customization details for implementation in PERTI.

---

## 1. System Adaptation Parameters

### 1.1 Adaptation Cycle Timing

| Cycle | Interval | Trigger | Description |
|-------|----------|---------|-------------|
| **Chart Change Update (CCU)** | 56 days | AIRAC cycle | Full NAS-wide adaptation rebuild from all data sources |
| **Weekly Update** | 7 days | Every Tuesday | Incremental updates to Monitor/Alert parameters picked up by hub script |

**Weekly update schedule**: Files updated at field sites (Centers, TRACONs, Canada) are picked up by a hub script every **Tuesday night** and available for use **Wednesday morning**.

### 1.2 BPEL Adaptation Build Process

The adaptation build is orchestrated via a BPEL (Business Process Execution Language) workflow with these sequential steps:

1. **Get Data** -- Retrieve source files from all data groups (ACES, ERAM, NFDC, NACO, International, Static, Manual)
2. **Ingest Files** -- Parse and validate input files into staging tables
3. **Process Data** -- Merge, deduplicate, resolve conflicts between overlapping data sources
4. **Move Data** -- Transfer processed data to distribution staging
5. **Compare and Evaluate** -- Diff against previous adaptation set, flag anomalies
6. **Create Legacy Files** -- Generate MOLDB-format files for backward compatibility

### 1.3 Data Source Priority

| Priority | Source | Description |
|----------|--------|-------------|
| 1 (highest) | **ERAM** (via ERAM2ACES/E2A tool) | ARTCC automation data; XML converted to BPEL-compatible |
| 2 | **ACES** | ARTCC Adaptation Control Environment System; primary domestic |
| 3 | **NFDC** | National Flight Data Center; authoritative FAA facility/procedural data |
| 4 | **NACO** | National Aeronautical Charting Office; route and airway data |
| 5 | **International** | Canadian (56-day), Mexican, Chilean, Colombian, European, UK data |
| 6 | **Manual/Static** | Hand-maintained files that override or supplement automated sources |
| 7 | **OAG** | Official Airline Guide; schedule data for SDB |

### 1.4 Adaptation File Locations on TFMS Nodes

| Path | Contents |
|------|----------|
| `/etms/tsd/adapt/` | TSD adaptation files (display settings, saved configs) |
| `/etms/tsd/adapt/adapt_resources` | Color and font customization files |
| `/etms/tsd/data/scripts` | TSD request scripts |
| `/etms/tmsh/adapt/scripts` | TM Shell scripts |
| `/etms/email/data/sent_msgs` | Sent email/advisory archive |
| `/etms/shared/data/rcvd_msgs` | Received messages |
| `TFMS/tdb/static_data/` | Static adaptation data (sector thresholds, profiles) |

---

## 2. Airport Configuration

### 2.1 Airport Data Fields

| Field | Format | Description |
|-------|--------|-------------|
| **FAA ID** | 3-4 char | FAA location identifier (e.g., `ORD`, `JFK`) |
| **ICAO ID** | 4 char | ICAO identifier (e.g., `KORD`, `KJFK`) |
| **Name** | String | Official airport name |
| **City** | String | City location |
| **State** | String | State (US airports) |
| **Country** | String | Country code |
| **ARTCC** | 3 char | Controlling ARTCC code (e.g., `ZAU`) |
| **Latitude** | Decimal degrees | Airport reference point latitude |
| **Longitude** | Decimal degrees | Airport reference point longitude |

### 2.2 Airport Adaptation Files

| File | Source | Contents | Count |
|------|--------|----------|-------|
| `aces_ap.dat` | Manual | Supplemental airports | -- |
| `airport_enroute.dat` | Manual | Enroute airports | -- |
| `foreign_airport.dat` | Manual | Foreign airports | 159 unique |
| `international_ap.dat` | Manual | International airports | 1,561 unique |
| `mexican_airport.dat` | Manual | Mexican airports | 321 unique |
| `chilean_airport.dat` | Manual | Chilean airports | -- |
| `colombian_airports.dat` | Manual | Colombian airports | 110 unique |
| `honduras_airports.dat` | Manual | Honduran airports | 41 unique |
| `pacing_airport.dat` | Manual | Pacing airport designator list only | ~30 |

### 2.3 Airport Acceptance Rate / Departure Rate (AAR/ADR)

**File**: `aar_adr` (Manual data group -- weekly updates via CR/ticket process)
**Format**: `Identifier, Arrival_Rate, Departure_Rate`
**Change process**: Facility submits CR/ticket -> memo to NOM at ATCSCC for area confirmation -> Volpe updates file.

### 2.4 Departure and Arrival Fixes

**File**: `new_depfix.dat` -- Departure fix definitions for 56 airports

**Format**:
```
DEPARTAPT=BWI DEPARTFIX=AML JETS
DEPARTAPT=BWI DEPARTFIX=EMI PROPS
DEPARTAPT=BWI DEPARTFIX=SIE PROPS_JETS
```

Aircraft categories: `JETS`, `PROPS`, `PROPS_JETS`

**File**: `new_arrfix.dat` -- Arrival fix definitions
**Format**: `Airport_Name, Waypoint (Fix or Navaid)`

---

## 3. Airspace Configuration

### 3.1 ARTCC Boundaries

**Primary file**: `artcc_v5.dat`
**Format**: `ARTCC_name, lower_alt, upper_alt, Lat/Lon_chain`
**Coverage**: CONUS ARTCCs, ZSU, ZHN, ZAN, London, ZSC, ZSW, ZSN

**Supplemental files**: `canadian_artcc.dat`, `mexican_artcc.dat`, `honduras_artcc.dat`

### 3.2 Sector Definitions

**File pattern**: `Z**_56_sector_definitions.dat` (one per ARTCC)
**23 US sites**: ZAB, ZAN, ZAU, ZBW, ZDC, ZDV, ZFW, ZHN, ZHU, ZID, ZJX, ZKC, ZLA, ZLC, ZMA, ZME, ZMP, ZNY, ZOA, ZOB, ZSE, ZSU, ZTL

**Format**: `Sector_Name, Sector_Type, Sector_Overlay, Alt`

**Sector types**:

| Code | Type |
|------|------|
| `L` | Low (below FL240) |
| `H` | High (FL240-FL600) |
| `S` | Superhigh (above FL600) |
| `T` | TRACON (terminal) |
| `O` | Oceanic |

**Threshold files**: `TFMS/tdb/static_data/Zxx_sector_thresholds.dat` (per ARTCC, all 21 CONUS + Canadian)

### 3.3 Special Use Airspace

SUA types with quick keys: Alert Areas (`[A`), MOAs (`[M`), Prohibited (`[P`), Restricted (`[R`), Warning (`[W`), All On (`[+`), All Off (`['`)

Canadian restricted areas: 95 unique in `restrict.dat`, 15 with multiple altitude stratifications.

### 3.4 Baseline vs. Current Sectors

TSD supports two modes via Map Overlays dialog:
- **Current**: Dynamic sectorization (combined/split state)
- **Baseline**: Original sector configuration

Affects both overlays and flight count/alert computations.

---

## 4. Route/Airway Configuration

### 4.1 Common Route File Format

```
Sequence_Number, Mag_Var, Latitude, Longitude, Fix, Element_Type, Name
```

### 4.2 Airway Files

| File | Source | Notes |
|------|--------|-------|
| `jet.dat` | International (Canadian) | Canadian jet routes |
| `lfmf.dat` | International (Canadian) | LF/MF routes |
| `rnav.dat` | International (Canadian) | RNAV routes (some unique) |
| `vic.dat` | International (Canadian) | 9 unique Canadian victor routes |
| `track.dat` | International (Canadian) | All unique track routes |
| `Caribbean_Jroutes.dat` | Manual | Caribbean jet routes |
| `Caribbean_Vroutes.dat` | Manual | Caribbean victor routes (all unique) |
| `mexican_jet_rte.dat` / `mexican_vic_rte.dat` | Manual | Mexican jet/victor routes |
| `chilean_jet.dat` / `chilean_victor.dat` | Manual | Chilean routes |
| `honduras_jet.dat` | Manual | 49 unique Honduran jet routes |
| `colombian_routes.dat` | Manual | Colombian routes |
| `Y_routes.dat` | Manual | Caribbean Y-airways: Y585, Y586, Y587, Y589 |

**Canadian airway processing**: Special script removes Canadian definitions of V6, V7, V9, V450 to prevent conflicts with NFDC.

### 4.3 Route Manager (RMGR) Commands

| Command | Function |
|---------|----------|
| `P` | Preferred routes between two points |
| `X` | Expanded preferred routes with fix details |
| `M` | Major traffic flows over a fix |
| `R` | Route parsing with ARTCC traverse data |
| `D` | Decode identifier (airport/fix/navaid lookup) |
| `E` | Encode identifier name |

---

## 5. Trajectory Model Configuration

### 5.1 SA/DPD (Standard Ascent/Descent Profile Database)

Three database tables:

**`src_asc_dsc_profile`**: `profile_name`, `type` (A=Ascent/D=Descent), `IDFR`

**`src_profile_mapping`**: `profile_name`, `acft_type` (A=All, J=Jet, P=Prop, T=Turboprop)

**`src_profile_pt`**: `profile_name`, `alt` (feet), `horizontal_distance` (nm), `vertical_segment_pair`, `true_air_speed` (knots), `indicated_airspeed` (knots), `mach`

### 5.2 Flight Position Estimation

- Radar updates: at least every **5 minutes**
- Display interpolation: every **1 minute** between updates
- Ghost threshold: **7 minutes** with no position update -> hollow icon
- Landing estimation: ghost disappears when FTM estimates landing

### 5.3 Ground Time Predictor (GTP)

Predicts taxi times and gate-to-runway transitions.

---

## 6. Display Configuration

### 6.1 Saveable TSD Settings

All of the following can be saved to named adaptation files under `/ETMS/tsd/adapt/`:
- Map overlays (all types), CIWS/legacy weather overlays
- Flight sets and properties
- Show Map Items, Range rings
- Center point and zoom scale (**20 to 12,800 nm**)
- Data block contents, Alert settings
- Colors/fonts for overlays
- NAS Monitor and FEA/FCA Timeline settings
- Default settings for flight sets and reroutes

**Protected file**: `defaults` cannot be overwritten or deleted.

### 6.2 Preference Set System

- Up to **32 workspaces** saved/restored per Gnome desktop
- Per-facility storage, folder hierarchy

### 6.3 Map Projections

| Projection | Description |
|------------|-------------|
| **Dynamic** (default) | Center follows display |
| **CONUS** | Fixed US center |
| **London** | UK/European |
| **Canada** | Canadian airspace |
| **Atlantic** | Atlantic oceanic |
| **Alaskan** | Alaskan airspace |
| **Chile** | Chilean airspace |

### 6.4 Range Rings

| Parameter | Default | Range |
|-----------|---------|-------|
| Number | 5 | 1-200 |
| Distance | 20 nm | 1-3,000 nm |
| Max sets | 64 simultaneous | -- |

### 6.5 Times Box

Shows current time + last update for flights, alerts, each weather type.
- Stale weather (>30 min): red
- Expired: flash red/black for 1 minute
- CCFP: shows Issued + Valid times, expires at 2.5 hours
- Obsolete (>12 hours): unavailable for display

---

## 7. Data Tables & Reference Data

### 7.1 Codes File System

| Type Code | Entity | Fields |
|-----------|--------|--------|
| **1** | Airports | Designator, coordinates, facility data |
| **5** | Airlines | Airline designator, ICAO code, name |
| **8** | Aircraft | Code, characteristics, name, manufacturer |
| **86** | Sectors | Designator, type, ARTCC, boundaries |
| **91** | Fixes | Fix name, coordinates, ARTCC, type |

### 7.2 Fix Data

Key files: `aces_fix.dat` (supplemental), `dot_concat.dat` (1,067 fixes), `foreign_fix.dat`, `mexican_fix.dat` (646+ unique), `chilean_fix.dat`, `colombian_fixes.dat`, `dominican_fixes.dat`, `honduras_fixes.dat` (139 unique), `fixes_enroute.dat` / `fixes_terminal.dat` (Canadian)

### 7.3 Navaid Data

`foreign_navaid.dat` (1,786 entries, 1,575 unique; 748 share names with ACES at different lat/lon), `canadian_nav.dat`, `terminal_navaids.dat` (56 Canadian, all in ERAM), `mexican_nav.dat` (115 unique), `chilean_navaid.dat`, `honduras_navaids.dat` (48 unique)

### 7.4 Airline Data

| File | Contents |
|------|----------|
| `airline.dat` | Master designator list |
| `airline_definitions.dat` | CDM/GDP participants with subcarrier visibility definitions |
| `radiotelephony.dat` | Telephony contractions |
| `flight_ids.XXX` | Per-company subscriber lists (NBAA, NetJets, etc.) |

### 7.5 ASDI Filtering

`FilterCallSign.Dat`, `IncludeInASDI.Dat`, `military_sensitive_filtering_table` (site/keyword/groups-allowed)

---

## 8. Facility Hierarchy

### 8.1 Site Types and Capabilities

| Site | Description | Key Capabilities |
|------|-------------|-----------------|
| **ATCSCC** | Command Center | Full: advisories, public FCAs, GDPs, all networks (NADIN/ARINC/TMS), site switching |
| **ARTCC** | En route center (22 CONUS + ZAN/ZHN/ZSU) | TSD, TM Shell, EMail (TMS only), Monitor/Alert, local sector/fix capacity updates |
| **TRACON** | Terminal facility | TSD, limited TM Shell, EMail (TMS only) |
| **Hubsite** | W.J. Hughes Technical Center | Data aggregation/distribution, adaptation build orchestration |
| **Canadian** | Nav Canada facilities | TSD, EMail, Monitor/Alert with Canadian data |

### 8.2 Network Architecture

| Network | Users | Purpose |
|---------|-------|---------|
| **NADIN** | ATCSCC only | Advisories to ARTCCs, FSS, Canadian Centers, military |
| **ARINC** | ATCSCC only | Advisories to airlines |
| **TMS** | All TFMS sites | Inter-site TFMS messaging |

### 8.3 Advisory System

- Numbered advisories: ATCSCC-only, sequence resets to 1 at midnight daily, assigned at send time
- General messages: available at all sites, no sequence number

---

## 9. Monitor/Alert Configuration

### 9.1 Default Sector Capacities

| Sector Type | Default (flights/15-min) |
|-------------|------------------------|
| Low | **20** |
| High | **15** |
| Superhigh | **12** |
| TRACON | **20** |
| Oceanic | **15** |

### 9.2 Capacity Files

**`sectorcapacities.dat`**: `Designator(s), Cap` -- per-sector overrides, updated at field sites, weekly Tuesday pickup
**`fixcapacities.dat`**: `Designator, Low_cap, High_cap, Superhigh_cap` -- fix crossing capacity per 15-minute interval
**`new_monitor.dat`**: Monitored airport designator list

### 9.3 Time Parameters

| Parameter | Value |
|-----------|-------|
| Max alert time limit | **2:25** (2 hours 25 minutes) |
| Default report period | **5 hours** |
| On-time window | -5 min to +15 min from predicted |
| Early threshold | >5 min before predicted |
| Late threshold | >15 min after predicted |

### 9.4 Time Verification Types

| Code | Type |
|------|------|
| `S` | Scheduled (OAG) |
| `P` | Proposed (latest flight plan) |
| `O` | Original (filed) |
| `CT` | Controlled (EDCT) |

---

## 10. PERTI Implementation Mapping

### 10.1 Direct Equivalences

| TFMS Component | PERTI Equivalent | Status |
|----------------|------------------|--------|
| SA/DPD profiles | BADA/OpenAP tables in VATSIM_ADL | Implemented |
| Airport AAR/ADR | `airport_config`/`airport_config_rate` | Implemented |
| Sector capacities | `adl_boundary` + capacity columns | Implemented |
| Sector definitions | `sector_boundaries` in VATSIM_GIS | Implemented |
| ARTCC boundaries | `artcc_boundaries` in VATSIM_GIS | Implemented |
| Fix data | `nav_fixes` in VATSIM_REF/GIS (269K) | Implemented |
| Airway data | `airways`/`airway_segments` (1,515) | Implemented |
| Route parsing | `expand_route()` in PostGIS | Implemented |
| Preferred routes | `playbook_routes`/`coded_departure_routes` (56K+41K) | Implemented |
| Airlines | `airlines` table (228) | Implemented |
| GTP (taxi) | `airport_taxi_reference` (3,628 airports) | Implemented |
| Connect-to-push | `airport_connect_reference` (5,552 airports) | Implemented |
| EDCT system | `tmi_slots`/`EDCTDelivery.php` | Implemented |
| NAS Monitor | `demand_monitors` table | Implemented |
| TSD display | MapLibre-based `route.php`/`nod.php` | Partial |
| Advisories | `tmi_advisories`/Discord | Implemented |
| Adaptation cycle | AIRAC pipeline + `refdata_sync_daemon.php` | Implemented |

### 10.2 Key Gaps

| TFMS Feature | PERTI Gap | Priority |
|--------------|-----------|----------|
| Default sector capacity by type (20/15/12/20/15) | No typed defaults | Medium |
| Fix crossing capacities | No fix_capacity table | Low |
| Sector type classification (L/H/S/T/O) | No explicit type field in `sector_boundaries` | Medium |
| On-time window (-5/+15 min) | Not formalized in compliance calc | Medium |

### 10.3 Recommended Enhancements

1. **Add sector type field** to `sector_boundaries` (L/H/S/T/O) to enable typed capacity defaults matching TFMS's 20/15/12/20/15 pattern.

2. **Add fix crossing capacity** support -- a `fix_capacity` table or extend demand monitoring for fix-level thresholds.

3. **Formalize the on-time window** (early: >5min before, on-time: -5 to +15min, late: >15min after) in PERTI's TMI compliance calculations.

4. **Document the adaptation equivalent** -- PERTI's AIRAC pipeline + daily refdata sync + manual config serves the same purpose as TFMS's 56-day CCU + weekly cycle, but with higher frequency.
