# VATSWIM Client Bridges Design

**Date:** 2026-03-30
**Status:** Approved
**Scope:** Five bridges to make VATSWIM bidirectional -- delivering enriched data to pilots and controllers

---

## Problem

VATSWIM is a one-way data sink. Eight connectors feed data in (vNAS, SimTraffic, vACDM, ECFMP, Hoppie, vATIS, Virtual Airlines, vIFF CDM), but there are no established pathways to push SWIM-enriched data back to the tools pilots and controllers use daily. The GDP algorithm generates EDCTs with no delivery mechanism. TMI programs, flow measures, AMAN sequences, and reroute advisories exist in SWIM but are invisible to ATC clients and pilot cockpits.

## Solution

Five bridges targeting different layers of the VATSIM client stack:

| Bridge | Language | Audience | Data Flow | Priority |
|---|---|---|---|---|
| **1: HoppieWriter** | PHP (server-side) | All pilots (universal) | SWIM -> Hoppie -> Pilot clients | High |
| **2: FSD Bridge** | Go (standalone binary) | CRC + EuroScope controllers | SWIM -> FSD :6809 -> ATC clients | Critical |
| **3: EuroScope Plugin** | C++ (DLL) | EuroScope controllers | SWIM -> ES tags + embedded FSD | High |
| **4: Pilot Portal** | Web (JS/Vue) | Pilots | Pilot <-> VA (optional) <-> SWIM | Medium |
| **5: AOC Client** | C/C++ (sim daemon) | Pilots + VAs | Simulator <-> VA/AOC <-> SWIM | Medium |

## Architecture

```
                      VATSWIM API (perti.vatcscc.org)
                     ┌──────────┬──────────────────┐
                     │ REST API │  WebSocket :8090  │
                     └────┬─────┴────────┬──────────┘
                          │              │
        ┌─────────────────┼──────────────┼─────────────────────────┐
        │                 │              │                         │
  Bridge 1          Bridge 2        Bridge 3                 Bridge 4 + 5
  HoppieWriter      FSD Bridge      EuroScope Plugin         Pilot Portal
  (PHP, server)     (Go, binary)    (C++, DLL)               + AOC Client
        │                │               │                         │
        ▼                ▼               ▼                         ▼
  Hoppie ACARS      FSD :6809      EuroScope tags          Pilot browser /
  Network           TCP server     lists, displays         Simulator FMS
        │                │               │                         │
        ▼                ▼               ▼                         │
  EasyCPDLC         CRC (VATUSA)   EuroScope               SimConnect /
  EuroscopeACARS    EuroScope      (deep integration       XPLM API
  Any Hoppie client VRC (legacy)    + embedded FSD          VA systems
                                    for redundancy)
```

---

## Bridge 1: HoppieWriter (PHP, Server-Side)

### Overview

Activate and extend the existing `EDCTDelivery.php` multi-channel delivery system. CPDLC delivery via Hoppie is already built as Priority 1 channel. Wire it into the GDP/GDT pipeline and extend to all TMI message types.

### Existing Infrastructure

- `load/services/EDCTDelivery.php` -- 4-channel delivery (CPDLC, VatswimPlugin, WebSocket, Discord DM)
- `integrations/hoppie-cpdlc/HoppieClient.php` -- Hoppie HTTP client (send/poll/peek)
- `integrations/hoppie-cpdlc/CPDLCParser.php` -- CPDLC message parser
- `integrations/hoppie-cpdlc/bridge.php` -- 30s cron orchestrator
- `cdm_messages` table -- message queue with delivery status tracking
- EDCT value source: `adl_flight_tmi.edct_utc` (synced from TMI every 60s)

### Phase A: Activate

Wire `EDCTDelivery` into `executeDeferredTMISync()` in the ADL daemon. When an EDCT changes for a flight, trigger delivery. The sync runs every 60s; changes detected by comparing previous `edct_utc` to current.

### Phase B: Extend Message Catalog

**TMI Control Messages (auto-triggered by daemon):**

| Message Type | CPDLC Format | Trigger |
|---|---|---|
| EDCT assigned | `EXPECT DEPARTURE CLEARANCE TIME {HHMM}Z DUE {REASON}. REPORT READY.` | New EDCT in tmi_flight_control |
| EDCT amended | `REVISED EDCT {HHMM}Z. PREVIOUS {HHMM}Z` | EDCT value change detected |
| EDCT cancelled | `DISREGARD EDCT {HHMM}Z. DEPART WHEN READY` | TMI control removed |
| CTOT (vIFF) | `CALCULATED TAKEOFF TIME {HHMM}Z. CTOT REGULATION {ID}` | vIFF daemon detects new CTOT |
| Ground stop | `GROUND STOP IN EFFECT FOR {DEST}. HOLD FOR RELEASE. EXPECT UPDATE BY {HHMM}Z` | GS program activated |
| GS released (GDP follows) | `GROUND STOP RLSD FOR {DEST}. FLIGHTS MAY RECEIVE NEW EDCTS DUE TO AN ACTIVE FLOW PROGRAM` | GS released, GDP active (controller config) |
| GS released (clean) | `GROUND STOP RLSD FOR {DEST}. DISREGARD EDCT & DEPART WHEN READY` | GS released, no follow-on (controller config) |
| Reroute (voice) | `REROUTE ADVISORY {ADVISORY_NUM}. AMEND ROUTE TO {ROUTE} OR STANDBY FOR VOICE CLEARANCE` | Reroute assigned, delivery mode = VOICE (controller config) |
| Reroute (delivery) | `REROUTE ADVISORY {ADVISORY_NUM}. AMEND ROUTE TO {ROUTE} OR CONTACT DELIVERY AT {FREQ} FOR AMENDED CLEARANCE` | Reroute assigned, delivery mode = DELIVERY (controller config) |
| Flow measure (ECFMP) | `FLOW RESTRICTION: {MEASURE_TYPE} {VALUE} FOR {FIR}` | ECFMP measure affects flight |
| MIT/MINIT | `MILES IN TRAIL {N}NM IN EFFECT AT {FIX}. EXPECT DELAY.` | tmi_entries MIT restriction |
| AFP restriction | `AIRSPACE FLOW PROGRAM IN EFFECT FOR {AIRSPACE}. {RATE} FLIGHTS PER HOUR. EXPECT DELAY {N} MIN.` | AFP TMI program activation |
| Metering fix time | `CROSS {FIX} AT {HHMM}Z. SCHEDULED TIME OF ARRIVAL {HHMM}Z.` | SimTraffic TBFM / AMAN Maestro |
| Hold advisory | `EXPECT HOLDING AT {FIX}. EXPECT FURTHER CLEARANCE {HHMM}Z.` | Controller-initiated via PERTI |
| CTP slot | `CTP SLOT ASSIGNED: {ENTRY_FIX} AT {HHMM}Z. ROUTE: {ROUTE}. CONFIRM ACCEPTANCE.` | CTP session slot assignment |
| Weather reroute | `CONVECTIVE ACTIVITY NEAR {FIX/AIRSPACE}. SUGGESTED DEVIATION: {ROUTE}. PILOT DISCRETION.` | Weather impact + playbook route suggestion |

**Traffic Advisory Messages (controller-initiated via PERTI UI):**

| Message Type | CPDLC Format |
|---|---|
| Arrival volume | `HIGH ARRIVAL VOLUME FOR {APT}. SUGGEST REDIRECTING TO {APT_OPTIONS} TO AVOID EXCESSIVE DELAYS.` |
| Departure volume | `HIGH DEPARTURE VOLUME OVER {AIRSPACE_ELEMENT}. SUGGEST REROUTING OVER {AIRSPACE_ELEMENT_OPTIONS} TO AVOID EXCESSIVE DELAYS.` |
| Reroute fuel advisory | `REROUTE/S IN EFFECT {TO/FROM/THRU} {FACILITY}. USERS SHOULD FUEL ACCORDINGLY.` |
| Delay fuel advisory | `{DEPARTURE/EN ROUTE/ARRIVAL} DELAYS {FROM/THRU/TO} {FACILITY}. USERS SHOULD FUEL ACCORDINGLY.` |

**Trajectory Options Set (TOS) Messages:**

| Message Type | CPDLC Format | Direction |
|---|---|---|
| TOS query | `TRAJECTORY OPTIONS REQUESTED FOR {DEPT}-{DEST}. FILE VIA PILOT CLIENT OR VATSWIM.` | SWIM -> Pilot |
| TOS ack | `{N} TRAJECTORY OPTIONS ON FILE. STANDBY FOR ASSIGNMENT.` | SWIM -> Pilot |
| TOS assignment (short route) | `TRAJECTORY OPTION {N} ASSIGNED: {ROUTE}. REASON: {REASON}.` | SWIM -> Pilot |
| TOS assignment (long route) | `TRAJECTORY OPTION {N} ASSIGNED PER ADVISORY {ADVISORY_NUM}. CHECK PILOT CLIENT FOR ROUTE DETAIL.` | SWIM -> Pilot |

TOS submission (pilot filing ranked route preferences) happens via SWIM REST API through Bridge 4 (Pilot Portal) or Bridge 5 (AOC Client), not via ACARS -- Hoppie's 256-char limit makes freeform route filing impractical. CPDLC serves as notification; rich clients provide the detail.

### Controller Configuration Options

| Config | Options | Stored In |
|---|---|---|
| Reroute delivery mode | `VOICE` / `DELIVERY` | Per-reroute advisory in tmi_reroutes |
| GS release follow-on | `GDP_ACTIVE` / `RELEASED` | Per-GS program in tmi_programs |

### Rate Limiting

Hoppie spec: 45-75s between polls, no explicit send rate limit but must be conservative. EDCTDelivery already spaces messages 2s apart. Batch window: accumulate changes over 60s TMI sync cycle, deliver all with 2s spacing.

### Acknowledgment Tracking

CPDLC responses (WILCO/UNABLE/STANDBY) flow back through existing Hoppie poller -> CPDLCParser -> `cdm_messages.ack_type`. Already built.

---

## Bridge 2: FSD Protocol Bridge (Go, Standalone Binary)

### Overview

Standalone Go binary that subscribes to SWIM WebSocket and serves enriched data as a **supplementary FSD server**. Controllers connect their ATC client to it as a secondary server alongside VATSIM FSD. This is the **only path** to inject SWIM data into CRC (no plugin system).

### Target ATC Clients

| Client | Status | Region |
|---|---|---|
| **CRC** | Primary. Consolidated Radar Client -- replaces VRC/vSTARS/vERAM (Oct 2023). STARS, ERAM, ASDE-X, Tower Cab modes. Part of vNAS suite. | VATUSA (all ARTCCs) |
| **EuroScope** | Primary for non-NA. Plugin ecosystem. | Europe, VATCAN, others |
| **VRC** | Deprecated, legacy | Legacy holdouts |

### Architecture

```
SWIM WebSocket (wss://perti.vatcscc.org/api/swim/v1/ws)
    │
    │  Subscriptions:
    │  - flight.positions (15s batches)
    │  - flight.created / departed / arrived / deleted
    │  - tmi.issued / modified / released
    │  - cdm.updated
    │  - system.heartbeat
    │
    ▼
┌─────────────────────────────────┐
│  swim-bridge (Go binary)        │
│                                 │
│  ┌───────────┐  ┌────────────┐ │
│  │ SWIM      │  │ Flight     │ │
│  │ WebSocket │─>│ State      │ │
│  │ Client    │  │ Cache      │ │
│  └───────────┘  └─────┬──────┘ │
│                       │        │
│  ┌────────────────────▼──────┐ │
│  │ FSD Packet Formatter      │ │
│  │ - Text messages (#TM)     │ │
│  │ - Flight plans ($FP)      │ │
│  │ - Info responses ($CR)    │ │
│  └────────────────────┬──────┘ │
│                       │        │
│  ┌────────────────────▼──────┐ │
│  │ TCP Server (:6809)        │ │
│  │ Per-client subscriptions  │ │
│  │ Geographic filtering      │ │
│  └───────────────────────────┘ │
└─────────────────────────────────┘
    │
    ▼
CRC / EuroScope / VRC
```

### FSD Data Injection

| FSD Packet | SWIM Source | Controller sees |
|---|---|---|
| `#TM` text | TMI program changes | Chat: `[SWIM] GDP KJFK VOL ISSUED. AAR 30. DELAY 45 MIN.` |
| `#TM` text | EDCT assignments | Chat: `[SWIM] DAL123 EDCT 1430Z (+45)` |
| `#TM` text | Ground stop | Chat: `[SWIM] GROUND STOP KATL. EXP UPDATE 1600Z` |
| `#TM` text | AMAN sequence | Chat: `[SWIM] KJFK SEQ: 1.UAL456 2.DAL789 3.AAL123` |
| `$FP` flight plan | Route amendments | Updated flight strip with SWIM-enriched route |
| `$CR` info response | TMI query | Controller queries callsign, gets TMI status |

### Scaling

| Metric | 5-year target | 10-year target |
|---|---|---|
| Flights tracked | 5,000 | 10,000 |
| Concurrent controllers | 400 | 1,000 |
| Position update cycle | 15s | 15s |
| Memory (total) | ~70MB | ~150MB |
| Go binary size | ~15MB | ~15MB |

### Deployment

| Mode | Target |
|---|---|
| **Local** | Controller runs `swim-bridge.exe`, EuroScope/CRC connects to `localhost:6809` |
| **Centralized** | Azure B2s VM ($40/mo), controllers connect to `fsd.vatcscc.org:6809` |

### Configuration

```yaml
swim:
  api_key: "swim_dev_xxxxx"
  websocket_url: "wss://perti.vatcscc.org/api/swim/v1/ws"

fsd:
  listen: ":6809"
  server_name: "VATSWIM"

filters:
  airports: []
  artccs: []
  callsign_prefix: []

messages:
  tmi_alerts: true
  edct_notifications: true
  aman_sequence: true
  flow_measures: true
```

---

## Bridge 3: EuroScope Plugin (C++, DLL)

### Overview

Pure C++ EuroScope plugin using the existing SWIM C++ SDK (`sdk/cpp/`). Connects directly to SWIM REST API and WebSocket. Provides deep tag enrichment AND embedded FSD injection for redundancy (if Bridge 2 is unavailable).

### Tag Enrichment Categories

| Category | Tag Item | Example Display |
|---|---|---|
| EDCT/CTOT countdown | `SWIM_EDCT` | `EDCT 1430Z -12min` |
| TMI program membership | `SWIM_TMI` | `GDP:KJFK` or `GS:KATL` |
| Delay value | `SWIM_DELAY` | `D+45` |
| CDM readiness state | `SWIM_CDM` | `TOBT 1415Z` or `TSAT 1425Z` |
| AMAN sequence position | `SWIM_AMAN` | `SEQ#3 TTL -2:30` |
| Flow measure restrictions | `SWIM_FLOW` | `MDI 5min LFFF` |
| Reroute compliance | `SWIM_RR` | `RR:COMPLY` or `RR:NONCMPL` |
| OOOI flight phase | `SWIM_PHASE` | `TAXI-OUT` or `CLIMB` |

### Custom Lists

| List | Contents |
|---|---|
| TMI Controlled Flights | All flights with active EDCT/CTOT/GS hold |
| AMAN Sequence | Arrival sequence for selected airport |
| CDM Status Board | CDM milestone status per flight |
| Flow Measure Impact | Flights affected by active ECFMP/vIFF measures |

### Embedded FSD Injection

The plugin also implements a lightweight FSD server on `localhost:6809` (same as Bridge 2) using the EuroScope Plugin SDK's internal data model. If Bridge 2 (Go binary) is not running, the plugin serves the same supplementary data directly. This provides redundancy for EuroScope users.

### SWIM Communication

- REST API polling for initial state (flight list, TMI programs, CDM status)
- WebSocket subscription for real-time updates (position, TMI events, CDM changes)
- C++ SDK (`sdk/cpp/swim.h`) provides `swim_client_init()`, REST calls, OOOI state machine
- WebSocket support to be added to C++ SDK (currently REST-only)

### Data Flow

```
SWIM REST API ──(initial load)──> Plugin State Cache
SWIM WebSocket ─(real-time)────> Plugin State Cache
                                      │
                    ┌─────────────────┼──────────────────┐
                    │                 │                   │
              Tag Items          Custom Lists      Embedded FSD
              (per-flight)       (sortable)        (localhost:6809)
                    │                 │                   │
                    └─────────────────┼──────────────────┘
                                      │
                                 EuroScope
```

---

## Bridge 4: Pilot Portal (Web, JS/Vue)

### Overview

Web application for pilots to interact with SWIM -- view TMI status, file TOS preferences, see route assignments on a map, and load routes to simulator. Virtual Airlines act as optional intermediary for dispatch functions.

### Relationship Model

```
Pilot <-> Virtual Airline (optional) <-> VATSWIM
```

| Path | TOS filing | EDCT display | Route amendments |
|---|---|---|---|
| Pilot -> SWIM direct | Pilot files via Portal | Portal shows EDCT | Portal shows route |
| Pilot -> VA -> SWIM | VA dispatch files on pilot's behalf | VA dispatch relays EDCT | VA dispatch manages route |

### Features

| Feature | Data Source |
|---|---|
| EDCT/CTOT status + countdown | SWIM WebSocket (`cdm.*` channel) |
| TMI program impact | SWIM REST (`/flights?tmi_controlled=true`) |
| TOS preference filing (up to N ranked routes) | SWIM REST POST (structured JSON, no char limit) |
| TOS assignment display | SWIM WebSocket (`tmi.*` channel) |
| Full route visualization (MapLibre map) | SWIM REST + PostGIS route expansion |
| Route-to-FMS injection | SimConnect (MSFS/P3D), XPLM (X-Plane) via local bridge |
| SimBrief-compatible export | SWIM REST -> OFP format (fallback) |
| CPDLC message feed | Hoppie bridge relay (read-only view) |
| Flow measure advisories | SWIM REST (`/tmi/measures`) |
| Weather/NOTAM briefing | SWIM REST |

### Simulator Route Injection

The Portal includes a local helper component that communicates with the flight simulator:

```
Portal (browser)
    │ localhost WebSocket
    ▼
Local Helper (small binary, same as AOC Client or standalone)
    │ SimConnect / XPLM
    ▼
Flight Simulator FMS
```

The "Load to Simulator" button sends the route string to the local helper, which calls `SimConnect::SetFlightPlan()` (MSFS/P3D) or `XPLMSetFMSEntryInfo()` (X-Plane) to inject it into the FMS.

### Technology

- Frontend: Vue 3 + MapLibre GL (matches existing PERTI frontend stack)
- SWIM communication: swim-ws-client.js (already built) + REST API
- Authentication: VATSIM Connect OAuth (already in PERTI)
- VA integration: REST API callbacks to phpVMS/smartCARS/VAM

---

## Bridge 5: AOC Client (C/C++, Simulator Daemon)

### Overview

Local daemon that runs alongside the flight simulator, providing bidirectional data exchange between the simulator, virtual airline operations, and VATSWIM. This is the formalized version of the existing flight sim plugins, upgraded from upload-only to full bidirectional.

### Relationship Model

```
Simulator <-> VA/AOC <-> VATSWIM
```

| Mode | Who uses it | How it works |
|---|---|---|
| VA-managed | Pilots flying for a VA | phpVMS/smartCARS module handles AOC <-> SWIM. VA dispatch sees telemetry, manages routes. |
| Standalone AOC | Pilots without a VA | AOC client talks directly to SWIM. No VA intermediary. |
| VA passthrough | VAs that want SWIM data but don't build their own AOC | VA consumes SWIM API for telemetry, pilot runs standalone AOC client. |

### Architecture

```
┌──────────────────────────────────────────────┐
│  Flight Simulator (MSFS / X-Plane / P3D)     │
│  SimConnect / XPLM API                       │
└──────────────┬───────────────────────────────┘
               │ telemetry (1-5s)
               ▼
┌──────────────────────────────────────────────┐
│  VATSWIM AOC Client (local daemon)           │
│                                              │
│  ┌─────────────┐  ┌──────────────────────┐   │
│  │ Telemetry   │  │ Dispatch Receiver    │   │
│  │ Collector   │  │ (SWIM WebSocket)     │   │
│  │ (SimConnect)│  │                      │   │
│  └──────┬──────┘  └──────────┬───────────┘   │
│         │                    │               │
│  ┌──────▼────────────────────▼────────────┐  │
│  │ ACARS Message Formatter                │  │
│  │ - OOOI detection (state machine)       │  │
│  │ - Progress reports (ARINC 633 style)   │  │
│  │ - Position reports (ARINC 620 style)   │  │
│  │ - Fuel/weight telemetry                │  │
│  └──────┬─────────────────────────────────┘  │
│         │                                    │
│  ┌──────▼──────────────────────────────────┐ │
│  │ SWIM API Client (REST + WebSocket)      │ │
│  │ POST /ingest/acars   (telemetry up)     │ │
│  │ POST /ingest/track   (position up)      │ │
│  │ WS flight.*/tmi.*/cdm.* (dispatch down) │ │
│  └──────┬──────────────────────────────────┘ │
│         │                                    │
│  ┌──────▼──────────────────────────────────┐ │
│  │ FMS Writer (route/clearance injection)  │ │
│  │ SimConnect SetFlightPlan() / XPLM FMS   │ │
│  └─────────────────────────────────────────┘ │
└──────────────────────────────────────────────┘
               │                    │
               ▼                    ▼
        VATSWIM API          Virtual Airline
        (telemetry in,       (phpVMS/smartCARS/VAM
         dispatch out)        gets live feed)
```

### Telemetry Message Types

| Report | Content | Frequency | SWIM Endpoint |
|---|---|---|---|
| Position | lat/lon/alt/heading/GS/TAS/mach/wind/temp | 15s (throttled) | `/ingest/track` |
| OOOI | Out/Off/On/In gate events | Event-driven | `/ingest/acars` (type: oooi) |
| Progress | Next waypoint, ETA dest, fuel remaining, burn rate | Per waypoint | `/ingest/acars` (type: progress) |
| Fuel | FOB, burn rate, endurance, diversion fuel | 5min intervals | `/ingest/acars` (type: telemetry) |
| Weight | ZFW, TOW, landing weight | Phase change | `/ingest/acars` (type: telemetry) |
| Systems | Engine params, electrical, hydraulic (if exposed) | On request | `/ingest/acars` (type: telemetry) |
| Deviation | Off-route, altitude deviation, speed deviation | Event-driven | `/ingest/acars` (type: alert) |

### Dispatch Messages Received

| Message | Source | Action |
|---|---|---|
| EDCT/CTOT assigned | SWIM WebSocket | Display to pilot, optionally delay pushback |
| Route amendment | SWIM WebSocket | Inject into FMS via SimConnect/XPLM |
| TOS assignment | SWIM WebSocket | Load assigned route into FMS |
| Ground stop | SWIM WebSocket | Display hold notification |
| Weather deviation | SWIM WebSocket | Display advisory |

### Technology

- C/C++ with existing SWIM C++ SDK (`sdk/cpp/swim.h`)
- SimConnect (MSFS/P3D) and XPLM (X-Plane) for simulator communication
- OOOI state machine already exists in C++ SDK
- Position throttling already exists in C++ SDK
- WebSocket support to be added to C++ SDK

---

## Shared Infrastructure

### SWIM WebSocket Enhancements

All bridges consume the existing SWIM WebSocket. New channel subscriptions needed:

| Channel | Publisher | Consumers |
|---|---|---|
| `flight.positions` | Existing (swim_sync) | Bridge 2, 3 |
| `flight.created/departed/arrived/deleted` | Existing | Bridge 2, 3, 4, 5 |
| `tmi.issued/modified/released` | Existing | Bridge 1, 2, 3, 4, 5 |
| `cdm.updated` | **New** (EDCTDelivery) | Bridge 2, 3, 4, 5 |
| `cdm.{callsign}` | Existing (EDCTDelivery) | Bridge 4, 5 |
| `aman.sequence` | **New** (AMAN ingest) | Bridge 2, 3 |

### C++ SDK Enhancements

The C++ SDK (`sdk/cpp/`) currently supports REST only. Needs:
- WebSocket client support (for Bridge 3 and Bridge 5)
- FMS write functions (SimConnect/XPLM wrappers for Bridge 4 helper and Bridge 5)

### New SWIM API Endpoints

| Endpoint | Method | Purpose | Consumer |
|---|---|---|---|
| `/api/swim/v1/ingest/aman.php` | POST | AMAN sequence data | Maestro, future AMAN tools |
| `/api/swim/v1/tos/file.php` | POST | TOS preference submission | Bridge 4, Bridge 5 |
| `/api/swim/v1/tos/status.php` | GET | TOS assignment status | Bridge 4, Bridge 5 |
| `/api/swim/v1/metering/{apt}/aman-feed` | GET | AMAN-compatible ETA feed | Maestro integration |

---

## Cost

| Component | Infrastructure | Monthly Cost |
|---|---|---|
| Bridge 1 (HoppieWriter) | Existing App Service | $0 (runs in ADL daemon) |
| Bridge 2 (FSD Bridge - centralized) | Azure B2s VM | $40/mo + egress |
| Bridge 2 (FSD Bridge - local) | Controller's machine | $0 |
| Bridge 3 (EuroScope Plugin) | Controller's machine | $0 |
| Bridge 4 (Pilot Portal) | Existing App Service | $0 (static + API) |
| Bridge 5 (AOC Client) | Controller's machine | $0 |
| Egress (10 controllers) | Azure | ~$7/mo |
| Egress (400 controllers) | Azure | ~$175/mo |
| Egress (1000 controllers) | Azure | ~$670/mo |

---

## Implementation Order

1. **Bridge 1 (HoppieWriter)** -- Highest leverage, lowest effort. Activate existing code, extend message catalog. Server-side only, zero client changes needed.
2. **Bridge 2 (FSD Bridge)** -- Critical path. Only way to reach CRC controllers (entire VATUSA). Go binary, WebSocket consumer, FSD server.
3. **Bridge 3 (EuroScope Plugin)** -- Deep integration for EuroScope users. C++ DLL, tag enrichment, embedded FSD redundancy.
4. **Bridge 4 (Pilot Portal)** -- Rich pilot experience. TOS filing, route display, FMS injection. Vue 3 web app.
5. **Bridge 5 (AOC Client)** -- Full telemetry loop. Bidirectional sim integration, VA dispatch. C/C++ daemon.
