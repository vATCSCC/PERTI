# VATSWIM C/C++ SDK

Header-only C/C++ library for flight simulator integration with the VATSWIM API.

## Features

- **Header-only** - No compilation required, just include the headers
- **OOOI Detection** - Automatic Out/Off/On/In time detection from telemetry
- **Position Throttling** - Built-in rate limiting for position updates
- **Minimal Dependencies** - Only libcurl for HTTP (optional)
- **Cross-platform** - Works on Windows, Linux, macOS
- **Simulator-agnostic** - Use with MSFS, X-Plane, P3D, or any other simulator

## Installation

Copy the `include/swim` directory to your project's include path.

```bash
# Or use CMake FetchContent
FetchContent_Declare(
  swim_sdk
  GIT_REPOSITORY https://github.com/vatcscc/swim-sdk-cpp.git
  GIT_TAG v1.0.0
)
FetchContent_MakeAvailable(swim_sdk)
```

## Quick Start

```cpp
#define SWIM_USE_CURL  // Enable HTTP support
#include <swim/swim.h>

int main() {
    // Initialize client
    SwimClientConfig config = {0};
    strcpy(config.api_key, "swim_par_your_key_here");
    strcpy(config.source_id, "my_simulator");
    config.timeout_ms = 5000;

    SwimClient client;
    if (!swim_client_init(&client, &config)) {
        printf("Failed to initialize client\n");
        return 1;
    }

    // In your simulator update loop:
    SwimPosition pos = {
        .latitude = 40.6413,
        .longitude = -73.7781,
        .altitude_ft = 35000,
        .heading_deg = 270,
        .groundspeed_kts = 450,
        .vertical_rate = -500,
        .on_ground = false
    };

    // Update OOOI detector
    bool oooi_event = swim_client_update_oooi(
        &client,
        pos.groundspeed_kts,
        pos.on_ground,
        pos.altitude_ft,  // Use AGL here
        pos.vertical_rate,
        false  // parking_brake
    );

    if (oooi_event) {
        printf("OOOI event detected!\n");
    }

    // Send position (with automatic throttling)
    SwimIngestResult result;
    if (swim_client_send_position_throttled(&client, "UAL123", &pos, &result)) {
        printf("Position sent: %d processed\n", result.processed);
    }

    // Cleanup
    swim_client_cleanup(&client);
    return 0;
}
```

## OOOI Detection

The SDK includes a state machine for detecting OOOI times from telemetry:

- **OUT** - Gate departure (pushback detected)
- **OFF** - Wheels up (takeoff detected)
- **ON** - Wheels down (landing detected)
- **IN** - Gate arrival (parking detected)

```cpp
// Initialize detector
SwimOOOIDetector oooi;
swim_oooi_init(&oooi);

// Update with telemetry (call every frame or at regular intervals)
bool event = swim_oooi_update(
    &oooi,
    groundspeed_kts,
    on_ground,
    altitude_agl_ft,
    vertical_rate_fpm,
    parking_brake_set
);

// Get current zone
SwimAirportZone zone = oooi.current_zone;
printf("Current zone: %s\n", swim_zone_to_string(zone));

// Get OOOI times
SwimOOOI times;
swim_oooi_get_times(&oooi, &times);

if (times.out_utc > 0) {
    printf("OUT: %s", ctime(&times.out_utc));
}
```

## Position Throttling

Built-in throttling prevents excessive API calls:

```cpp
SwimPositionThrottle throttle;
swim_throttle_init(&throttle,
    5,      // Minimum 5 seconds between updates
    0.5,    // Minimum 0.5nm distance change
    100     // Minimum 100ft altitude change
);

// Check if should send
if (swim_throttle_should_send(&throttle, &position)) {
    // Send position...
    swim_throttle_mark_sent(&throttle, &position);
}
```

## API Reference

### Types

| Type | Description |
|------|-------------|
| `SwimPosition` | Geographic position with altitude, speed, heading |
| `SwimOOOI` | Out/Off/On/In times |
| `SwimFlightPlan` | Flight plan data |
| `SwimTrackUpdate` | Position update for track ingest |
| `SwimFlightIngest` | Full flight data for ADL ingest |
| `SwimIngestResult` | API response with counts and status |
| `SwimClientConfig` | Client configuration |
| `SwimClient` | Main client instance |

### Enumerations

| Enum | Values |
|------|--------|
| `SwimFlightPhase` | PREFILE, PREFLIGHT, PUSHBACK, TAXI_OUT, TAKEOFF, DEPARTURE, ENROUTE, DESCENT, APPROACH, LANDING, TAXI_IN, ARRIVED |
| `SwimAirportZone` | PARKING, TAXIWAY, HOLD, RUNWAY, AIRBORNE, APPROACH, FINAL |
| `SwimStatus` | OK, ERROR_NETWORK, ERROR_AUTH, ERROR_RATE_LIMIT, ERROR_INVALID_DATA, ERROR_SERVER, ERROR_TIMEOUT |

### Functions

```cpp
// Client lifecycle
bool swim_client_init(SwimClient* client, const SwimClientConfig* config);
void swim_client_cleanup(SwimClient* client);

// Ingest
SwimStatus swim_client_ingest_track(SwimClient* client, const SwimTrackUpdate* tracks, int count, SwimIngestResult* result);
SwimStatus swim_client_ingest_adl(SwimClient* client, const SwimFlightIngest* flights, int count, SwimIngestResult* result);

// OOOI
bool swim_client_update_oooi(SwimClient* client, float gs, bool on_ground, float agl, float vs, bool parking_brake);
void swim_client_get_oooi(const SwimClient* client, SwimOOOI* oooi);
void swim_client_reset_oooi(SwimClient* client);

// Throttling
bool swim_client_should_send_position(SwimClient* client, const SwimPosition* pos);
void swim_client_mark_position_sent(SwimClient* client, const SwimPosition* pos);
bool swim_client_send_position_throttled(SwimClient* client, const char* callsign, const SwimPosition* pos, SwimIngestResult* result);
```

## Building with CMake

```cmake
cmake_minimum_required(VERSION 3.14)
project(my_simulator_plugin)

# Find curl
find_package(CURL REQUIRED)

add_executable(my_plugin main.cpp)
target_include_directories(my_plugin PRIVATE ${CMAKE_SOURCE_DIR}/sdk/cpp/include)
target_compile_definitions(my_plugin PRIVATE SWIM_USE_CURL)
target_link_libraries(my_plugin CURL::libcurl)
```

## Simulator Integration

### MSFS (SimConnect)

```cpp
#include <windows.h>
#include <SimConnect.h>
#define SWIM_USE_CURL
#include <swim/swim.h>

// In your SimConnect callback:
void CALLBACK SimConnectCallback(SIMCONNECT_RECV* pData, DWORD cbData, void* pContext) {
    SwimClient* client = (SwimClient*)pContext;

    if (pData->dwID == SIMCONNECT_RECV_ID_SIMOBJECT_DATA) {
        SIMCONNECT_RECV_SIMOBJECT_DATA* pObjData = (SIMCONNECT_RECV_SIMOBJECT_DATA*)pData;
        SimData* data = (SimData*)&pObjData->dwData;

        SwimPosition pos = {
            .latitude = data->latitude,
            .longitude = data->longitude,
            .altitude_ft = (int)data->altitude,
            .groundspeed_kts = (int)data->groundspeed,
            .heading_deg = (int)data->heading,
            .vertical_rate = (int)data->vertical_speed,
            .on_ground = data->on_ground != 0
        };

        swim_client_update_oooi(client, pos.groundspeed_kts, pos.on_ground,
                                data->altitude_agl, pos.vertical_rate,
                                data->parking_brake != 0);

        swim_client_send_position_throttled(client, data->callsign, &pos, NULL);
    }
}
```

### X-Plane (XPLM)

```c
#include "XPLMDataAccess.h"
#include "XPLMProcessing.h"
#define SWIM_USE_CURL
#include <swim/swim.h>

static XPLMDataRef lat_ref, lon_ref, alt_ref, gs_ref, hdg_ref, vs_ref, on_ground_ref;

float FlightLoopCallback(float inElapsedSinceLastCall, float inElapsedTimeSinceLastFlightLoop,
                         int inCounter, void* inRefcon) {
    SwimClient* client = (SwimClient*)inRefcon;

    SwimPosition pos = {
        .latitude = XPLMGetDataf(lat_ref),
        .longitude = XPLMGetDataf(lon_ref),
        .altitude_ft = (int)XPLMGetDataf(alt_ref),
        .groundspeed_kts = (int)(XPLMGetDataf(gs_ref) * 1.94384),  // m/s to kts
        .heading_deg = (int)XPLMGetDataf(hdg_ref),
        .vertical_rate = (int)(XPLMGetDataf(vs_ref) * 196.85),    // m/s to fpm
        .on_ground = XPLMGetDatai(on_ground_ref) != 0
    };

    swim_client_send_position_throttled(client, "N12345", &pos, NULL);

    return 1.0f;  // Call again in 1 second
}
```

## License

MIT License - see LICENSE file for details.

## Support

- GitHub Issues: https://github.com/vatcscc/swim-sdk-cpp/issues
- Documentation: https://perti.vatcscc.org/swim/docs
