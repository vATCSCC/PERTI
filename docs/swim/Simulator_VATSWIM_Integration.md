# Flight Simulator → VATSWIM Integration

This document describes how flight simulators (MSFS, X-Plane, P3D, FSX) can push flight data to PERTI's VATSWIM API.

## Overview

PERTI receives real-time flight data from simulator plugins to track position, detect OOOI events, and update flight state. The integration supports multiple simulator platforms through a unified SDK architecture.

## Supported Simulators

| Simulator | Platform | API | Plugin Location |
|-----------|----------|-----|-----------------|
| Microsoft Flight Simulator 2020/2024 | Windows | SimConnect | `/integrations/flight-sim/msfs/` |
| X-Plane 11/12 | Windows/macOS/Linux | XPLM DataRefs | `/integrations/flight-sim/xplane/` |
| Prepar3D v4/v5 | Windows | SimConnect | `/integrations/flight-sim/p3d/` |
| FSX/FSX:SE | Windows | SimConnect (legacy) | `/integrations/flight-sim/fsx/` |

## API Endpoints

Simulator plugins use the existing SWIM ingest endpoints:

| Endpoint | Purpose |
|----------|---------|
| `POST /api/swim/v1/ingest/track` | Position updates |
| `POST /api/swim/v1/ingest/acars` | OOOI events (via ACARS endpoint) |

---

## Track Position Updates

### Endpoint

```
POST /api/swim/v1/ingest/track
```

### Request Format

```json
{
  "tracks": [
    {
      "callsign": "UAL123",
      "latitude": 40.6413,
      "longitude": -73.7781,
      "altitude_ft": 35000,
      "ground_speed_kts": 450,
      "heading_deg": 270,
      "vertical_rate_fpm": -500,
      "squawk": "1200",
      "track_source": "simulator",
      "timestamp": "2026-01-27T15:30:00Z"
    }
  ]
}
```

### Update Frequency

**Recommended throttling:**
- **Cruise:** Every 5 seconds
- **Taxi/Approach:** Every 2 seconds
- **Minimum change thresholds:**
  - Distance: 0.5 nm
  - Altitude: 100 ft

---

## OOOI Detection

Simulator plugins can detect OOOI events using the hybrid detection strategy:

### Client-Side Detection Thresholds

| Event | Detection Criteria |
|-------|-------------------|
| **OUT** | GS > 5 kts, leaving parking zone |
| **OFF** | GS > 60 kts, AGL > 50 ft |
| **ON** | GS < 200 kts, AGL < 100 ft, touchdown detected |
| **IN** | GS < 5 kts, entering parking zone |

### OOOI via ACARS Endpoint

```json
{
  "source": "generic",
  "messages": [{
    "type": "oooi",
    "callsign": "UAL123",
    "timestamp": "2026-01-27T14:30:00Z",
    "payload": {
      "event": "OUT",
      "departure_icao": "KJFK",
      "gate": "B32"
    }
  }]
}
```

---

## Data Authority

Simulator plugins have the following priority levels:

| Data Type | Priority | Notes |
|-----------|----------|-------|
| Track Position | 4 | After vNAS, CRC, EuroScope |
| OOOI Times | 3 | After ACARS, VA platforms |
| Telemetry | 1 | Primary for direct sim data |

---

## SDK Architecture

### C++ SDK Headers

Location: `/sdk/cpp/include/swim/`

| Header | Purpose |
|--------|---------|
| `swim.h` | Main SDK entry point |
| `types.h` | Core data structures |
| `telemetry.h` | OOOI detection helpers |
| `http.h` | HTTP client wrapper |
| `json.h` | JSON serialization |

### Position Throttling

```c
typedef struct {
    float interval_sec;      // Min time between sends (default: 5s)
    float distance_nm;       // Min distance change (default: 0.5nm)
    float altitude_ft;       // Min altitude change (default: 100ft)
    SwimPosition last_sent;
    uint64_t last_send_time;
} SwimPositionThrottle;
```

### OOOI Detection

```c
typedef struct {
    // Thresholds
    float out_gs_threshold;      // Default: 5 kts
    float off_gs_threshold;      // Default: 60 kts
    float on_gs_threshold;       // Default: 200 kts
    float in_gs_threshold;       // Default: 5 kts
    float airborne_agl_min;      // Default: 50 ft

    // OOOI times (UTC epoch)
    uint64_t out_time;
    uint64_t off_time;
    uint64_t on_time;
    uint64_t in_time;
} SwimOOOIDetector;
```

---

## Plugin Configuration

### Configuration File

**Windows:** `vatswim_config.ini`
**X-Plane:** `vatswim_config.txt`

```ini
[VATSWIM]
# API Authentication
ApiKey=swim_par_your_key_here
ApiBaseUrl=https://perti.vatcscc.org/api/swim/v1

# Feature Toggles
EnableTracks=1
EnableOOOI=1
EnableFuel=1

# Position Reporting
TrackIntervalMs=5000
MinDistanceNm=0.5
MinAltitudeChangeFt=100

# Batching
BatchTimeoutSec=10
MaxBatchSize=100
ImmediateOnOOOI=1

# OOOI Thresholds
OutSpeedKts=5
OffSpeedKts=60
OnSpeedKts=200
InSpeedKts=5

# Logging
VerboseLogging=0
LogFilePath=vatswim.log
```

---

## Event-Driven Updates

### State Changes That Trigger Immediate Updates

- OOOI state changes (OUT, OFF, ON, IN)
- Flight phase transitions (taxi, takeoff, climb, cruise, descent, approach, landing)
- Gear up/down
- Parking brake set/released

### Batching Strategy

```
1. Collect position updates for 5-10 seconds
2. Flush batch on:
   - OOOI event detected (immediate)
   - 100 tracks buffered (max batch)
   - 10 seconds elapsed
   - Disconnect event
```

---

## SimConnect Integration (MSFS/P3D/FSX)

### Key SimVars

| SimVar | Purpose |
|--------|---------|
| `PLANE LATITUDE` | Aircraft latitude |
| `PLANE LONGITUDE` | Aircraft longitude |
| `PLANE ALTITUDE` | Altitude MSL |
| `PLANE ALT ABOVE GROUND` | AGL for OOOI detection |
| `GROUND VELOCITY` | Ground speed |
| `PLANE HEADING DEGREES MAGNETIC` | Heading |
| `VERTICAL SPEED` | Vertical rate |
| `GEAR HANDLE POSITION` | Gear state |
| `BRAKE PARKING POSITION` | Parking brake |
| `SIM ON GROUND` | Ground contact |

### Plugin Entry Point

```cpp
// MSFS: main.cpp
void CALLBACK SimConnectCallback(SIMCONNECT_RECV* pData, DWORD cbData, void* pContext) {
    // Process position data
    // Detect OOOI events
    // Batch and send to VATSWIM
}
```

---

## X-Plane DataRef Integration

### Key DataRefs

| DataRef | Purpose |
|---------|---------|
| `sim/flightmodel/position/latitude` | Aircraft latitude |
| `sim/flightmodel/position/longitude` | Aircraft longitude |
| `sim/flightmodel/position/elevation` | Altitude MSL |
| `sim/flightmodel/position/y_agl` | AGL |
| `sim/flightmodel/position/groundspeed` | Ground speed |
| `sim/flightmodel/position/mag_psi` | Magnetic heading |
| `sim/flightmodel/position/vh_ind` | Vertical speed |
| `sim/cockpit2/controls/gear_handle_down` | Gear state |
| `sim/cockpit2/controls/parking_brake_ratio` | Parking brake |
| `sim/flightmodel/failures/onground_any` | Ground contact |

### Plugin Entry Point

```c
// X-Plane: main.c
float FlightLoopCallback(float inElapsedSinceLastCall, float inElapsedTimeSinceLastFlightLoop, int inCounter, void* inRefcon) {
    // Read DataRefs
    // Detect OOOI events
    // Batch and send to VATSWIM
    return 5.0f;  // Call again in 5 seconds
}
```

---

## Zone-Based OOOI Detection

The server uses airport geometry polygons for authoritative OOOI detection:

### Zone Types

| Zone | OOOI Relevance |
|------|----------------|
| `PARKING` | OUT/IN detection |
| `GATE` | OUT/IN detection |
| `APRON` | Transition zone |
| `TAXIWAY` | Taxi detection |
| `HOLD` | Departure sequencing |
| `RUNWAY` | OFF/ON detection |

### Server-Side Detection

The stored procedure `sp_ProcessZoneDetectionBatch` at `/adl/migrations/oooi/007_oooi_batch_v3.sql` provides authoritative OOOI detection based on position and zone geometry.

---

## Example Integration

### Minimal Track Update

```bash
curl -X POST https://perti.vatcscc.org/api/swim/v1/ingest/track \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer swim_par_your_key" \
  -d '{
    "tracks": [{
      "callsign": "UAL123",
      "latitude": 40.6413,
      "longitude": -73.7781,
      "altitude_ft": 35000,
      "ground_speed_kts": 450
    }]
  }'
```

### OOOI Event

```bash
curl -X POST https://perti.vatcscc.org/api/swim/v1/ingest/acars \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer swim_par_your_key" \
  -d '{
    "source": "generic",
    "messages": [{
      "type": "oooi",
      "callsign": "UAL123",
      "timestamp": "2026-01-27T14:45:00Z",
      "payload": {
        "event": "OFF",
        "departure_icao": "KJFK",
        "runway": "31L"
      }
    }]
  }'
```

---

## Plugin Development

### Building MSFS Plugin

```bash
cd integrations/flight-sim/msfs
mkdir build && cd build
cmake ..
cmake --build . --config Release
```

### Building X-Plane Plugin

```bash
cd integrations/flight-sim/xplane
mkdir build && cd build
cmake ..
cmake --build . --config Release
```

---

*Document version: 1.0.0 | Last updated: 2026-01-27*
