# TFMS Architecture & System Design Analysis

## Prong 1 Report — Synthesized from FAA TFMS Reference Manuals

**Sources analyzed:**
- TFMS 8.9 Reference Manual (pages 1-400)
- TFMS TSD Reference Manual v9.5 (pages 1-200)
- TFMS Adaptation Manual (all 123 pages)

---

## 1. System Architecture Overview

### Network Topology

TFMS operates on a hub-and-spoke architecture running on **Red Hat Linux workstations**:

| Component | Role | Location |
|-----------|------|----------|
| **TPC** (TFM-M Production Center) | Central processing hub, all database servers | ATCSCC (Warrenton, VA) |
| **TRS** (TFM-M Remote Sites) | ARTCC-level workstations, local TSD clients | Each of 20 ARTCCs |
| **TSD Clients** | Traffic Situation Display workstations | ATCSCC + ARTCCs |

### Major Subsystems

**5 Core Database Servers** (all resident at TPC):

1. **List Server** — Generates real-time flight reports (lists) based on user-defined filters. Supplies flight data to TSD displays, Reroute Monitor, and demand sets. Filters by: airport, fix, sector, ARTCC, altitude, aircraft type, airline, route string, time window.

2. **EDCT Server** — Monitors Ground Delay Program compliance. Tracks EDCT assignments, departure times, and slot utilization. Feeds the GDT (Ground Delay Table) display.

3. **SDB Server** (Schedule Database) — Stores OAG (Official Airline Guide) scheduled flight data. Provides baseline demand predictions before flight plans are filed. Updated weekly from OAG feed.

4. **TDB** (Traffic Database) — Real-time demand monitoring engine. Calculates current and predicted demand counts for every NAS element (sectors, airports, fixes, FEAs/FCAs). Compares demand against capacity thresholds to trigger NAS Monitor alerts.

5. **GTP** (Ground Time Predictor) — Computes historical departure time predictions. Uses rolling statistical models of actual departure behavior by airport/carrier/time-of-day to predict when scheduled flights will actually depart.

**Application Subsystems:**

| Subsystem | Function |
|-----------|----------|
| **TSD** (Traffic Situation Display) | Primary graphical client — map display, flight icons, weather overlays, demand graphs |
| **FSM** (Flight Schedule Monitor) | CDM-enhanced GDP display — airline substitutions, slot credit/trade |
| **TM Shell** | TMI command interface — GDP/GS/AFP/CTOP issuance and management |
| **Route Manager (RMGR)** | Reroute definition, playbook management, amendment submission to ERAM |
| **FTM** (Flight Tracking Module) | Processes radar position data into flight track updates |
| **Email/Autosend** | Advisory distribution to NADIN/ARINC networks |
| **NAS Monitor** | Threshold-based alerting for sectors, airports, fixes |

### Client-Server Model

- TSD clients connect to the **flight server node** at TPC
- Flight data updates transmit to the flight server at least every **5 minutes**
- Between updates, flight positions are **estimated (extrapolated)** based on last known trajectory
- TSD display refreshes every **1 minute** with interpolated positions
- FTM declares a flight "ghost" (hollow icon) after **7 minutes** without position data
- Loss of ARTCC host computer feed results in **"No Radar" symbol** on affected flights

### Server Infrastructure

The adaptation server is identified as **tfsvr720** with adaptation data stored at:
```
/opt/tfms/adapt_data/
```

Subdirectories per data source:
```
/opt/tfms/adapt_data/aces/       — ACES airspace configuration
/opt/tfms/adapt_data/nfdc/       — National Flight Data Center
/opt/tfms/adapt_data/naco/       — Charting Office routes
/opt/tfms/adapt_data/eram/       — ERAM automation data
/opt/tfms/adapt_data/intl/       — International data
/opt/tfms/adapt_data/weekly/     — Weekly operational updates
```

---

## 2. Data Sources & Feeds

### External Data Sources (56-Day CCU Cycle)

| Source | Files | Category | Content |
|--------|-------|----------|---------|
| **NFDC** | `awy.txt` | Route | Federal airways |
| **NFDC** | `fix.txt` | Fix | Named fixes/waypoints |
| **NFDC** | `apt.txt` | Airport | Airport definitions |
| **NFDC** | `nav.txt` | Navaid | Navigation aids |
| **NFDC** | `sua.txt` | Boundary/SUA | Special Use Airspace |
| **NFDC** | `stardp.txt` | Route | STARs and DPs |
| **NACO** | `alhigh.dat` | Route | Alaska high-altitude routes |
| **NACO** | `allow.dat` | Route | Alaska low-altitude routes |
| **NACO** | `bahama.dat` | Route | Bahamas routes |
| **NACO** | `enhigh.dat` | Route | CONUS high-altitude routes |
| **NACO** | `enlow.dat` | Route | CONUS low-altitude routes |
| **NACO** | `hawaii.dat` | Route | Hawaii routes |
| **NACO** | `oceanic.dat` | Route | Oceanic routes |
| **NACO** | `pr.dat` | Route | Puerto Rico routes |

### ACES Data (Per-ARTCC, 56-Day Cycle)

| File | Category | Content |
|------|----------|---------|
| `ABER` | Equipment | Equipment/configuration data |
| `ARPT` | Airport | Airport characteristics per ARTCC |
| `FPA` | Boundary/FPA | Flow Pattern Areas |
| `NODE` | Boundary/FPA | FPA node definitions |
| `SECR` | Boundary/Sector | Sector boundary definitions |

### ERAM Data (Per-ARTCC, XML Format)

| File | Content |
|------|---------|
| `AircraftCharacteristics.xml` | Aircraft type performance data |
| `Airport.xml` | Airport definitions |
| `Alias.xml` | Alias/shortcut definitions |
| `ATCPoints.xml` | ATC fix/waypoint points |
| `EQData.xml` | Equipment qualifications |
| `Facility.xml` | Facility configuration |
| `FAV.xml` | Favorite/saved configurations |
| `Sector.xml` | Sector boundary geometry |

**ERAM2ACES Conversion**: The `E2A` Java tool converts ERAM XML into ACES format:
```
java e2a -outdir OUTPUT -rawdir XML_DIR ZDC
```
Options: `-nosect` (skip sectors), `-filter` (apply filtering), `-onesite` (single facility)

### Weekly Operational Data (7-Day Cycle)

| File | Content |
|------|---------|
| `aar_adr.doc` | Airport Acceptance/Departure Rates |
| `airportcapacities.dat` | Airport capacity configurations |
| `airline_definitions.dat` | Airline code mappings |
| `sectorcapacities2.dat` | Sector capacity thresholds |
| `fixcapacities.dat` | Fix capacity thresholds |
| `flight_ids.*` | Flight identification data |
| `IncludeInASDI.Dat` | ASDI inclusion filter |
| `FilterCallSign.Dat` | Callsign filtering rules |
| `new_monitor.dat` | NAS Monitor alert definitions |
| `radiotelephony.dat` | Radio telephony designators |
| `military_sensitive_filtering_table` | Military flight filtering |

### OAG (Official Airline Guide)

- Updated **weekly**
- Provides scheduled flight data for the SDB Server
- Flights from OAG carry status code **"S" (Scheduled)**
- Base demand prediction source before flight plans are filed
- Includes: airline, origin, destination, scheduled departure/arrival, aircraft type, frequency

### Sector Threshold Data

Per-ARTCC files at `TFMS/tdb/static_data/Zxx_sector_thresholds.dat` concatenated into `sectorcapacities2.dat`.

Default capacity format (5 values per sector):
```
20 15 12 20 15
```
Representing: **low altitude**, **high altitude**, **superhigh altitude**, **TRACON**, **oceanic**

### Codes File Types

| Type Code | Content |
|-----------|---------|
| Type 1 | Airport codes |
| Type 5 | Airline codes |
| Type 8 | Aircraft type codes |
| Type 86 | Sector identifiers |
| Type 91 | Fix identifiers |

### International Data Sources

Per-country and per-FIR files covering:
- Airport definitions (Canadian, UK, European, Caribbean airports)
- Fix/navaid data per FIR
- Sector/boundary definitions
- Oceanic track structures (NAT, PACOTS)
- FIR boundary polygons

### Adaptation File-to-Table Parser Mappings

Example: NFDC `stardp.txt` processing:
```
Parser: NfdcStarDp
Input: stardp.txt
Output Tables: Route, Waypoint_reference, Fix, Navigational_aid
```

Each parser maps raw file formats to normalized database tables, with the BPEL workflow orchestrating the pipeline.

---

## 3. Core Data Models

### Flight Record Data Model

**Flight Status Codes** (FSTAT field):

| Code | Status | Description |
|------|--------|-------------|
| `S` | Scheduled | OAG schedule data, no flight plan filed |
| `N` | Early Intent | Pre-departure intent message received |
| `P` | Proposed | Flight plan filed with ATC |
| `T` | Taxi | Aircraft taxiing (out event received) |
| `A` | Active | Airborne (off event or radar contact) |
| `E` | Estimated | No flight plan, position estimated |
| `R` | Replaced | Flight plan replaced/amended |

**Core Flight Fields** (from List Server / TSD flight data blocks):

| Field | Description | Format |
|-------|-------------|--------|
| `ACID` | Aircraft Identification (callsign) | String, e.g., "UAL123" |
| `GUFI` | Global Unique Flight Identifier | Numeric |
| `ORIG` | Origin airport | ICAO/FAA code |
| `DEST` | Destination airport | ICAO/FAA code |
| `ETD` | Estimated Time of Departure | HHmm UTC |
| `ETA` | Estimated Time of Arrival | HHmm UTC |
| `ATD` | Actual Time of Departure | HHmm UTC |
| `P-TIME` | Proposed departure time | HHmm UTC |
| `FSTAT` | Flight status | S/N/P/T/A/E/R |
| `ALT` | Filed altitude | Flight level or feet |
| `CALT` | Current/assigned altitude | Flight level |
| `SPD` | Filed speed | Knots or Mach |
| `ACTYPE` | Aircraft type | ICAO designator |
| `WTCLASS` | Weight class | H/L/S/G (Heavy/Large/Small/General) |
| `EQUIP` | Equipment suffix | /A, /G, /L, /W, etc. |
| `ROUTE` | Filed route string | Fix-airway-fix format |
| `ARTCC` | Current controlling ARTCC | 3-char code (ZDC, ZNY, etc.) |
| `SECTOR` | Current sector | Sector ID |
| `BCN` | Beacon/transponder code | 4-digit octal |
| `CTD` | Controlled Time of Departure (EDCT) | HHmm UTC |
| `CTA` | Controlled Time of Arrival | HHmm UTC |
| `EDCT` | Expect Departure Clearance Time | HHmm UTC |
| `DELAY` | Assigned delay | Minutes |
| `RVSM` | RVSM compliance indicator | Y/N/X |

**TMI Control Fields:**

| Field | Description | Values |
|-------|-------------|--------|
| `TMI_ID` | Traffic Management Initiative ID | Format: "RR" + 3-char facility + advisory# (e.g., `RRDCC345`) |
| `AMDTSTATUS` | Route amendment status | `SENT`, `ACPT`, `RJCT` |
| `RRSTAT` | Reroute conformance status | `C` (Conforming), `NC` (Non-Conforming), `NC/OK`, `UNKN`, `OK`, `EXC` (Excluded) |
| `PROGRAM_ID` | GDP/GS/AFP program identifier | Numeric |

### SA/DPD (Standard Ascent/Descent Profile Database)

Three core tables for trajectory computation:

**`src_asc_dsc_profile`**:
| Column | Type | Description |
|--------|------|-------------|
| `profile_name` | String | Profile identifier |
| `type` | Char(1) | `A` (Ascent) or `D` (Descent) |
| `IDFR` | String | Identifier reference |
| `lock_ts` | Timestamp | Last modification |

**`src_profile_mapping`**:
| Column | Type | Description |
|--------|------|-------------|
| `profile_name` | String | Links to profile |
| `acft_type` | Char(1) | `J` (Jet), `P` (Prop), `T` (Turbo), `A` (All) |
| `IDFR` | String | Identifier reference |
| `lock_ts` | Timestamp | Last modification |

**`src_profile_pt`** (profile points for trajectory computation):
| Column | Type | Description |
|--------|------|-------------|
| `profile_name` | String | Links to profile |
| `alt` | Numeric | Altitude (feet) |
| `horizontal_distance` | Numeric | Horizontal distance (nm) |
| `vertical_segment_pair` | Numeric | Vertical segment reference |
| `true_air_speed` | Numeric | TAS (knots) |
| `indicated_airspeed` | Numeric | IAS (knots) |
| `mach` | Numeric | Mach number |

### Demand Model

The TDB computes demand counts for every NAS element at configurable time intervals:

- **Sector demand**: Count of flights predicted to occupy a sector at each time slice
- **Airport demand**: Arrival/departure counts per time period (typically 15-min or 1-hour buckets)
- **Fix demand**: Count of flights predicted to cross a fix per time period
- **FEA/FCA demand**: Count of flights predicted to enter a Flow Evaluation/Constrained Area

Threshold comparison format:
```
Capacity: MAP (Monitor Alert Parameter) value per sector
Colors: Green (under), Yellow (at), Red (over capacity)
Default thresholds: 20/15/12/20/15 (low/high/superhigh/tracon/oceanic)
```

### Reroute Monitor Flight List Columns

The Reroute Monitor displays per-flight data in these columns:

| Column | Content |
|--------|---------|
| ACID | Aircraft callsign |
| TYPE | Aircraft type designator |
| ORIG | Origin airport |
| DEST | Destination airport |
| ETD | Estimated departure time |
| ETA | Estimated arrival time |
| ALT | Filed altitude |
| ROUTE | Filed route of flight |
| RRSTAT | Reroute conformance status |
| AMDTSTATUS | Amendment status (SENT/ACPT/RJCT) |
| TMI_ID | Associated TMI identifier |
| DELAY | Assigned delay in minutes |
| ARTCC | Current/last ARTCC |

---

## 4. Processing Pipeline

### Data Ingestion Pipeline (Adaptation)

The adaptation pipeline uses **Oracle BPEL** (Business Process Execution Language) as its workflow engine, managed through the **Oracle BPEL Console** web interface.

**5-Step BPEL Workflow:**

```
Step 1: INGEST FILES
  +-- Receive raw files from NFDC, NACO, ACES, ERAM, OAG, international sources
  +-- Validate file checksums and format
  +-- Stage in /opt/tfms/adapt_data/ directory structure

Step 2: PROCESS FILES
  +-- Run format-specific parsers (NfdcStarDp, NacoRoute, AcesSecr, etc.)
  +-- ERAM2ACES conversion for ERAM XML files
  +-- Normalize to internal table schemas
  +-- Populate staging database tables

Step 3: MOVE DATA
  +-- Transfer processed data to production tables
  +-- Apply per-ARTCC overlays
  +-- Update cross-reference indexes

Step 4: CREATE LEGACY FILES
  +-- Generate backward-compatible format files
  +-- Distribute to systems requiring legacy formats

Step 5: COMPARE AND EVALUATE
  +-- Diff new data against previous cycle
  +-- Flag changes for review (added/modified/deleted records)
  +-- Generate adaptation change reports
```

**Cycle Types:**

| Cycle | Interval | Content | Trigger |
|-------|----------|---------|---------|
| CCU (Chart Change Update) | 56 days | NFDC, NACO, ACES, ERAM | NAS charting cycle |
| Weekly | 7 days | OAG, capacities, airline defs, sector thresholds | Operational updates |
| Ad-hoc | As needed | Emergency fixes, SUA activations | Operational necessity |

### Flight Data Processing Pipeline

```
1. INGESTION
   +-- OAG schedule data -> SDB Server (status "S")
   +-- Early Intent messages -> status "N"
   +-- Flight plan filing (FP message) -> status "P"
   +-- OOOI events (Out/Off/On/In) -> status transitions P->T->A
   +-- Radar position data -> FTM processing

2. TRAJECTORY MODELING
   +-- FTM receives radar track data from ARTCC host computers
   +-- SA/DPD profiles applied for climb/descent modeling
   +-- Route parsed against adaptation (airways, fixes, STARs/DPs)
   +-- 4D trajectory computed (lat/lon/alt/time at each waypoint)
   +-- Wind data applied for ground speed estimation

3. DEMAND PREDICTION
   +-- TDB aggregates trajectories into time-sliced demand counts
   +-- Demand computed per: sector, airport, fix, FEA/FCA
   +-- Compared against capacity thresholds from adaptation
   +-- NAS Monitor alerts generated when demand > capacity
   +-- Demand data fed to TSD displays and graphs

4. TMI EXECUTION
   +-- NAS Monitor alerts inform TMI decisions
   +-- GDP: EDCT assignments computed via RBS algorithm
   +-- GS: All departures halted to affected airport
   +-- AFP: Reroute + metering combined program
   +-- CTOP: Multi-option trajectory selection (v9.5+)
   +-- Reroute: Amendment submitted to ERAM via RMGR

5. MONITORING & FEEDBACK
   +-- EDCT Server tracks GDP compliance
   +-- Reroute Monitor tracks amendment acceptance
   +-- FTM updates actual positions against predicted
   +-- TDB updates demand predictions with actuals
   +-- GTP refines historical departure predictions
```

### FTM (Flight Tracking Module) Processing

- Receives position updates from ARTCC host computer radar feeds
- Normal update interval: variable, but display interpolates between updates
- **Ghost timeout**: 7 minutes without position data -> flight icon becomes hollow (ghost)
- **No Radar condition**: Loss of entire ARTCC host computer feed -> "No Radar" symbol displayed
- Position extrapolation: Between actual updates, FTM estimates position based on:
  - Last known position, heading, speed
  - Filed route of flight
  - SA/DPD climb/descent profiles (for non-cruise phases)

---

## 5. System Interfaces

### ERAM Interface (Route Amendments)

TFMS communicates with ERAM for route amendments through the Route Manager (RMGR):

**Amendment Workflow:**
```
1. TMI specialist selects flights for reroute in Reroute Monitor
2. New route defined (from playbook or manual entry)
3. Amendment submitted to ERAM -> AMDTSTATUS = "SENT"
4. ERAM processes amendment:
   a. ACCEPTED -> AMDTSTATUS = "ACPT", flight plan updated
   b. REJECTED -> AMDTSTATUS = "RJCT", reason provided
5. Reroute conformance updated: RRSTAT = C/NC/NC-OK/UNKN/OK/EXC
```

### FSM (Flight Schedule Monitor) / CDM Interface

FSM extends GDP management with CDM (Collaborative Decision Making) capabilities:

- Airline operators view their flights' EDCT assignments
- **Slot credit/trade**: Airlines can swap EDCT slots between their flights
- **Substitution**: Airlines can substitute one flight for another in a GDP slot
- **Compression**: Automatic reallocation of unused slots
- FSM data feeds from EDCT Server and interfaces with airline CDM systems

### SWIM Interface

SWIM (System Wide Information Management) provides external data distribution:

- TFMS publishes flight data, TMI status, and demand information via SWIM
- Consumers include airlines, airports, and third-party systems
- Data formatted in **FIXM** (Flight Information Exchange Model) standard
- SWIM subscriptions allow filtered data delivery

### Weather Data Interfaces

TSD integrates multiple weather products via overlay system:

| Product | Source | Content |
|---------|--------|---------|
| **CIWS** (Corridor Integrated Weather System) | MIT Lincoln Labs | Convective weather display, storm motion vectors |
| **NOWRAD** | NWS | National radar mosaic |
| **CCFP** (Collaborative Convective Forecast Product) | AWC | 2/4/6-hour convective forecasts |
| **NCWF** (National Convective Weather Forecast) | CIWS | Short-term convective forecast |
| **ITWS** (Integrated Terminal Weather System) | Terminal areas | Terminal weather products |

### NADIN/ARINC Communication Interface

Advisory and general messages distributed via:

| Network | Coverage | Message Types |
|---------|----------|---------------|
| **NADIN** (National Airspace Data Interchange Network) | Domestic (FAA facilities) | Advisories, TMI notifications, amendments |
| **ARINC** | Airlines, international | Flight plans, position reports, CDM data |

---

## 6. Communication Architecture

### Message Distribution Hierarchy

```
ATCSCC (National Level)
+-- TPC (Production Center) -- all database servers
|   +-- TSD Clients (ATCSCC floor)
|   +-- TM Shell (TMI issuance)
|   +-- Email/Autosend (advisory distribution)
|   +-- SWIM (external distribution)
|
+-- TRS (Remote Sites) -- one per ARTCC
|   +-- ZDC (Washington Center)
|   +-- ZNY (New York Center)
|   +-- ZBW (Boston Center)
|   +-- ZOB (Cleveland Center)
|   +-- ... (20 total ARTCCs)
|   +-- Each with:
|       +-- TSD Client(s)
|       +-- Local TMI coordination
|       +-- ERAM interface
|
+-- External Recipients
    +-- Airlines (via ARINC/CDM/SWIM)
    +-- TRACONs (via ARTCC relay)
    +-- Towers (via ARTCC/TRACON relay)
    +-- International (via ICAO/bilateral)
```

### Advisory Message Types

| Type | Distribution | Content |
|------|-------------|---------|
| **Advisory** | NADIN + ARINC broadcast | TMI announcements (GDP, GS, AFP, reroutes) |
| **General Message** | Point-to-point or broadcast | Coordination, information sharing |
| **EDCT Message** | Per-flight via NADIN | Individual EDCT assignments |
| **Amendment** | TFMS->ERAM | Route change submissions |
| **CDM Message** | SWIM/airline networks | GDP slot swaps, compression, substitutions |

### Data Update Cadence

| Data Type | Update Interval | Distribution |
|-----------|----------------|--------------|
| Flight positions | <=5 min to flight server, 1 min display refresh | TPC -> all TSD clients |
| Demand counts | Real-time (TDB continuous recalculation) | TPC -> TSD NAS Monitor |
| Weather overlays | Product-dependent (CIWS ~5 min, CCFP hourly) | External -> TSD overlay |
| OAG schedules | Weekly | OAG -> SDB Server |
| Adaptation data | 56-day cycle + weekly | BPEL pipeline -> all servers |
| TMI status | Real-time | TPC -> all TRS + SWIM |

---

## 7. Version Differences (TSD 8.9 vs 9.5/9.7)

### New Features in TSD 9.5

| Feature | Description | PERTI Relevance |
|---------|-------------|-----------------|
| **RAPT Menu** | Route Availability Planning Tool — assesses route availability based on weather | New menu category; weather-route intersection analysis |
| **CTOP Menu** | Collaborative Trajectory Options Program — multi-option trajectory selection for TMIs | New TMI type beyond GDP/GS/AFP |
| **Role-Based Access** | TSD-C (Controller) and TSD-U (User) role distinctions | Access control model for features |
| **ESIS Display** | Enhanced integration with ESIS (Enhanced Status Information System) | Additional status data layer |

### Menu Structure Comparison

**TSD 8.9 Menus:**
- Display, Maps, Flights, Alerts, Weather, Reroute, FEA/FCA, Tools

**TSD 9.5 Menus (additions):**
- Display, Maps, Flights, Alerts, Weather, Reroute, FEA/FCA, **RAPT**, **CTOP**, Tools

### CTOP (New in 9.5)

CTOP represents a significant architectural addition:
- Combines elements of GDP (delay) and AFP (reroute) into a single program
- Flights submit **Trajectory Options Sets (TOS)** — multiple route/time options
- System optimizes across all options to minimize total NAS delay
- Requires new data model: TOS definitions, option scoring, multi-route evaluation

### RAPT (New in 9.5)

Route Availability Planning Tool:
- Evaluates route segments for weather impact
- Color-coded route availability: Green (open), Yellow (marginal), Red (blocked)
- Integrates CIWS weather data with route geometry
- Supports departure route planning at congested airports

### Architecture Consistency

Both versions share the same fundamental 5-server architecture (List Server, EDCT Server, SDB, TDB, GTP). The core data model, adaptation pipeline, and communication architecture remain unchanged between 8.9 and 9.5. The differences are additive — new menu items, new TMI types (CTOP), new analysis tools (RAPT), and access control refinements.

---

## PERTI Mapping Recommendations

Based on this analysis, key areas where PERTI can improve fidelity to the real TFMS:

1. **Flight Status Model**: PERTI's ADL should fully implement the S->N->P->T->A lifecycle with the E and R edge cases. The `adl_flight_core` table's `phase` field maps to FSTAT.

2. **Demand Computation**: TDB's real-time demand calculation against sector/airport/fix thresholds maps directly to PERTI's demand monitoring system. The default threshold format `20/15/12/20/15` should be the baseline.

3. **Adaptation Pipeline**: PERTI's AIRAC update pipeline (`airac_full_update.py`) mirrors the BPEL 56-day CCU cycle. PERTI already ingests NFDC-equivalent data (fixes, airways, procedures). The weekly capacity/threshold update cycle could be formalized.

4. **SA/DPD Profiles**: The 3-table profile structure (`src_asc_dsc_profile`, `src_profile_mapping`, `src_profile_pt`) could inform PERTI's trajectory modeling, complementing the existing BADA/OpenAP performance data.

5. **Reroute Amendment Workflow**: The SENT->ACPT/RJCT state machine with RRSTAT conformance tracking maps to PERTI's existing `tmi_reroute_flights` compliance model.

6. **CTOP/RAPT**: These v9.5 features represent future PERTI capabilities — CTOP as a new TMI type with TOS optimization, RAPT as weather-route intersection analysis.

7. **NAS Monitor Alerting**: TDB's threshold-based alerting (Green/Yellow/Red) maps directly to PERTI's demand monitor system with its OpLevel severity model.
