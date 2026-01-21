/**
 * VATSWIM SimConnect Event Handler
 *
 * Common SimConnect dispatch handling for MSFS and P3D plugins.
 * Processes incoming SimConnect messages and updates SWIM state.
 *
 * @package VATSWIM
 * @subpackage Flight Simulator Integrations
 * @version 1.0.0
 */

#ifndef VATSWIM_SIMCONNECT_HANDLER_H
#define VATSWIM_SIMCONNECT_HANDLER_H

#include <windows.h>
#include <SimConnect.h>
#include <stdbool.h>
#include <time.h>
#include "simconnect_data.h"

// Include SWIM SDK for telemetry and HTTP
#include "../../../../sdk/cpp/include/swim/swim.h"

#ifdef __cplusplus
extern "C" {
#endif

/**
 * Plugin configuration
 */
typedef struct {
    char api_key[128];           // SWIM API key
    char api_base_url[256];      // API base URL
    char callsign[16];           // Current callsign (from VATSIM)
    char departure[8];           // Departure ICAO
    char destination[8];         // Destination ICAO
    int track_interval_ms;       // Position report interval (default 1000)
    bool enable_oooi;            // Enable OOOI detection
    bool enable_tracks;          // Enable track position reporting
    bool verbose_logging;        // Enable verbose logging
} VATSWIM_Config;

/**
 * Plugin state
 */
typedef struct {
    HANDLE hSimConnect;          // SimConnect handle
    bool connected;              // SimConnect connected
    bool sim_running;            // Simulation running (not paused)
    bool flight_active;          // Active flight in progress

    // Current data
    VATSWIM_PositionData position;
    VATSWIM_AircraftInfo aircraft;
    VATSWIM_FlightState flight_state;

    // OOOI detector
    SwimOOOI oooi;

    // Timing
    time_t last_track_report;    // Last track submission time
    time_t last_oooi_check;      // Last OOOI check time
    time_t flight_start_time;    // When current flight started

    // Statistics
    unsigned int tracks_sent;
    unsigned int oooi_events_sent;
    unsigned int errors;
} VATSWIM_State;

// Global state (singleton pattern for SimConnect callback)
static VATSWIM_Config g_config = {0};
static VATSWIM_State g_state = {0};

/**
 * Initialize the plugin state
 */
static inline void vatswim_init_state(void) {
    memset(&g_state, 0, sizeof(g_state));
    swim_oooi_reset(&g_state.oooi);
}

/**
 * Log a message (implement in platform-specific code)
 */
extern void vatswim_log(const char* level, const char* format, ...);

/**
 * Submit track position to SWIM API
 */
static inline void vatswim_submit_track(void) {
    if (!g_config.enable_tracks || !g_state.flight_active) {
        return;
    }

    // Check if enough time has passed since last report
    time_t now = time(NULL);
    int interval_sec = g_config.track_interval_ms / 1000;
    if (interval_sec < 1) interval_sec = 1;

    if (now - g_state.last_track_report < interval_sec) {
        return;
    }

    // Build position for SWIM SDK
    SwimPosition pos = {0};
    pos.latitude = g_state.position.latitude;
    pos.longitude = g_state.position.longitude;
    pos.altitude_ft = (float)g_state.position.altitude_msl;
    pos.groundspeed_kts = (float)g_state.position.groundspeed;
    pos.heading_deg = (float)g_state.position.heading_mag;
    pos.vertical_rate_fpm = (float)g_state.position.vertical_speed;
    pos.on_ground = g_state.position.on_ground != 0;
    pos.timestamp = (uint64_t)now;

    // Build track update
    SwimTrackUpdate track = {0};
    strncpy(track.callsign, g_config.callsign, sizeof(track.callsign) - 1);
    track.position = pos;
    strncpy(track.source, "msfs_plugin", sizeof(track.source) - 1);

    // Build JSON and send
    char json[1024];
    swim_json_track(&track, json, sizeof(json));

    char url[512];
    snprintf(url, sizeof(url), "%s/ingest/track", g_config.api_base_url);

    char response[2048];
    int http_code = swim_http_post(url, g_config.api_key, json, response, sizeof(response));

    if (http_code >= 200 && http_code < 300) {
        g_state.tracks_sent++;
        g_state.last_track_report = now;
        if (g_config.verbose_logging) {
            vatswim_log("DEBUG", "Track submitted: %.4f, %.4f, %.0f ft",
                pos.latitude, pos.longitude, pos.altitude_ft);
        }
    } else {
        g_state.errors++;
        vatswim_log("ERROR", "Track submission failed: HTTP %d", http_code);
    }
}

/**
 * Submit OOOI event to SWIM API
 */
static inline void vatswim_submit_oooi_event(SwimFlightPhase phase) {
    if (!g_config.enable_oooi || !g_state.flight_active) {
        return;
    }

    const char* phase_name = "";
    const char* time_field = "";

    switch (phase) {
        case SWIM_PHASE_OUT:
            phase_name = "OUT";
            time_field = "out_utc";
            break;
        case SWIM_PHASE_TAXI_OUT:
            phase_name = "TAXI_OUT";
            break;
        case SWIM_PHASE_TAKEOFF:
            phase_name = "OFF";
            time_field = "off_utc";
            break;
        case SWIM_PHASE_CLIMB:
        case SWIM_PHASE_CRUISE:
        case SWIM_PHASE_DESCENT:
        case SWIM_PHASE_ENROUTE:
            return; // Not OOOI events
        case SWIM_PHASE_APPROACH:
            break;
        case SWIM_PHASE_LANDING:
            phase_name = "ON";
            time_field = "on_utc";
            break;
        case SWIM_PHASE_TAXI_IN:
            phase_name = "TAXI_IN";
            break;
        case SWIM_PHASE_IN:
            phase_name = "IN";
            time_field = "in_utc";
            break;
        default:
            return;
    }

    // Only submit actual OOOI times (OUT, OFF, ON, IN)
    if (strlen(time_field) == 0) {
        vatswim_log("INFO", "Flight phase changed: %s", phase_name);
        return;
    }

    // Build OOOI update JSON
    char json[512];
    time_t now = time(NULL);
    struct tm* utc = gmtime(&now);
    char timestamp[32];
    strftime(timestamp, sizeof(timestamp), "%Y-%m-%dT%H:%M:%SZ", utc);

    snprintf(json, sizeof(json),
        "{"
        "\"callsign\":\"%s\","
        "\"dept_icao\":\"%s\","
        "\"dest_icao\":\"%s\","
        "\"%s\":\"%s\","
        "\"source\":\"msfs_plugin\""
        "}",
        g_config.callsign,
        g_config.departure,
        g_config.destination,
        time_field,
        timestamp);

    char url[512];
    snprintf(url, sizeof(url), "%s/ingest/adl", g_config.api_base_url);

    char response[2048];
    int http_code = swim_http_post(url, g_config.api_key, json, response, sizeof(response));

    if (http_code >= 200 && http_code < 300) {
        g_state.oooi_events_sent++;
        vatswim_log("INFO", "OOOI event submitted: %s = %s", phase_name, timestamp);
    } else {
        g_state.errors++;
        vatswim_log("ERROR", "OOOI submission failed: HTTP %d", http_code);
    }
}

/**
 * Process OOOI detection
 */
static inline void vatswim_process_oooi(void) {
    if (!g_config.enable_oooi || !g_state.flight_active) {
        return;
    }

    // Build position for OOOI detector
    SwimPosition pos = {0};
    pos.latitude = g_state.position.latitude;
    pos.longitude = g_state.position.longitude;
    pos.altitude_ft = (float)g_state.position.altitude_msl;
    pos.groundspeed_kts = (float)g_state.position.groundspeed;
    pos.vertical_rate_fpm = (float)g_state.position.vertical_speed;
    pos.on_ground = g_state.flight_state.on_ground != 0;
    pos.timestamp = (uint64_t)time(NULL);

    // Additional state info for detection
    bool parking_brake = g_state.flight_state.parking_brake > 8000; // ~50% threshold
    bool engines_running = g_state.flight_state.engine_running != 0;

    // Get previous phase
    SwimFlightPhase prev_phase = g_state.oooi.current_phase;

    // Update OOOI detector
    swim_oooi_update(&g_state.oooi, &pos);

    // Check for phase transition
    if (g_state.oooi.current_phase != prev_phase) {
        vatswim_submit_oooi_event(g_state.oooi.current_phase);
    }
}

/**
 * Handle position data update
 */
static inline void vatswim_handle_position(VATSWIM_PositionData* data) {
    memcpy(&g_state.position, data, sizeof(VATSWIM_PositionData));

    // Submit track if conditions met
    vatswim_submit_track();

    // Process OOOI detection
    vatswim_process_oooi();
}

/**
 * Handle aircraft info update
 */
static inline void vatswim_handle_aircraft_info(VATSWIM_AircraftInfo* data) {
    memcpy(&g_state.aircraft, data, sizeof(VATSWIM_AircraftInfo));

    vatswim_log("INFO", "Aircraft loaded: %s (%s)",
        data->title, data->atc_type[0] ? data->atc_type : "Unknown");
}

/**
 * Handle flight state update
 */
static inline void vatswim_handle_flight_state(VATSWIM_FlightState* data) {
    memcpy(&g_state.flight_state, data, sizeof(VATSWIM_FlightState));

    // Skip processing if simulation is disabled or in slew
    if (data->sim_disabled || data->is_slew_active) {
        return;
    }
}

/**
 * Handle simulation start
 */
static inline void vatswim_handle_sim_start(void) {
    g_state.sim_running = true;
    vatswim_log("INFO", "Simulation started");
}

/**
 * Handle simulation stop
 */
static inline void vatswim_handle_sim_stop(void) {
    g_state.sim_running = false;
    vatswim_log("INFO", "Simulation stopped");
}

/**
 * Handle pause event
 */
static inline void vatswim_handle_pause(bool paused) {
    g_state.sim_running = !paused;
    if (paused) {
        vatswim_log("DEBUG", "Simulation paused");
    } else {
        vatswim_log("DEBUG", "Simulation unpaused");
    }
}

/**
 * Handle aircraft loaded event
 */
static inline void vatswim_handle_aircraft_loaded(void) {
    // Request aircraft info
    vatswim_request_aircraft_info(g_state.hSimConnect);
    vatswim_log("INFO", "Aircraft loaded, requesting info");
}

/**
 * Handle flight loaded event
 */
static inline void vatswim_handle_flight_loaded(void) {
    // Reset OOOI state for new flight
    swim_oooi_reset(&g_state.oooi);
    g_state.flight_start_time = time(NULL);
    g_state.flight_active = true;

    // Request aircraft info
    vatswim_request_aircraft_info(g_state.hSimConnect);

    vatswim_log("INFO", "Flight loaded, OOOI reset");
}

/**
 * SimConnect dispatch callback
 * Routes SimConnect messages to appropriate handlers
 */
static inline void CALLBACK vatswim_dispatch_proc(SIMCONNECT_RECV* pData, DWORD cbData, void* pContext) {
    switch (pData->dwID) {
        case SIMCONNECT_RECV_ID_OPEN:
            g_state.connected = true;
            vatswim_log("INFO", "SimConnect connection opened");
            break;

        case SIMCONNECT_RECV_ID_QUIT:
            g_state.connected = false;
            vatswim_log("INFO", "SimConnect connection closed");
            break;

        case SIMCONNECT_RECV_ID_SIMOBJECT_DATA: {
            SIMCONNECT_RECV_SIMOBJECT_DATA* pObjData = (SIMCONNECT_RECV_SIMOBJECT_DATA*)pData;

            switch (pObjData->dwRequestID) {
                case VATSWIM_REQUEST_POSITION:
                    vatswim_handle_position((VATSWIM_PositionData*)&pObjData->dwData);
                    break;

                case VATSWIM_REQUEST_AIRCRAFT_INFO:
                    vatswim_handle_aircraft_info((VATSWIM_AircraftInfo*)&pObjData->dwData);
                    break;

                case VATSWIM_REQUEST_FLIGHT_STATE:
                    vatswim_handle_flight_state((VATSWIM_FlightState*)&pObjData->dwData);
                    break;
            }
            break;
        }

        case SIMCONNECT_RECV_ID_EVENT: {
            SIMCONNECT_RECV_EVENT* pEvent = (SIMCONNECT_RECV_EVENT*)pData;

            switch (pEvent->uEventID) {
                case VATSWIM_EVENT_SIM_START:
                    vatswim_handle_sim_start();
                    break;

                case VATSWIM_EVENT_SIM_STOP:
                    vatswim_handle_sim_stop();
                    break;

                case VATSWIM_EVENT_PAUSE:
                    vatswim_handle_pause(pEvent->dwData != 0);
                    break;

                case VATSWIM_EVENT_AIRCRAFT_LOADED:
                    vatswim_handle_aircraft_loaded();
                    break;

                case VATSWIM_EVENT_FLIGHT_LOADED:
                    vatswim_handle_flight_loaded();
                    break;
            }
            break;
        }

        case SIMCONNECT_RECV_ID_EXCEPTION: {
            SIMCONNECT_RECV_EXCEPTION* pException = (SIMCONNECT_RECV_EXCEPTION*)pData;
            vatswim_log("ERROR", "SimConnect exception: %d", pException->dwException);
            g_state.errors++;
            break;
        }

        default:
            break;
    }
}

/**
 * Connect to SimConnect
 *
 * @param app_name Application name to register
 * @return True if connected successfully
 */
static inline bool vatswim_connect(const char* app_name) {
    HRESULT hr = SimConnect_Open(&g_state.hSimConnect, app_name, NULL, 0, 0, 0);

    if (FAILED(hr)) {
        vatswim_log("ERROR", "Failed to connect to SimConnect: 0x%08X", hr);
        return false;
    }

    // Initialize data definitions
    if (!vatswim_init_data_definitions(g_state.hSimConnect)) {
        vatswim_log("ERROR", "Failed to initialize SimConnect data definitions");
        SimConnect_Close(g_state.hSimConnect);
        return false;
    }

    // Subscribe to events
    vatswim_subscribe_events(g_state.hSimConnect);

    // Subscribe to position updates
    vatswim_subscribe_position(g_state.hSimConnect, g_config.track_interval_ms);

    // Subscribe to flight state for OOOI
    vatswim_subscribe_flight_state(g_state.hSimConnect);

    g_state.connected = true;
    vatswim_log("INFO", "Connected to SimConnect");

    return true;
}

/**
 * Disconnect from SimConnect
 */
static inline void vatswim_disconnect(void) {
    if (g_state.hSimConnect) {
        SimConnect_Close(g_state.hSimConnect);
        g_state.hSimConnect = NULL;
    }
    g_state.connected = false;
    vatswim_log("INFO", "Disconnected from SimConnect");
}

/**
 * Process pending SimConnect messages
 * Call this in your main loop
 */
static inline void vatswim_process_messages(void) {
    if (!g_state.hSimConnect || !g_state.connected) {
        return;
    }

    SimConnect_CallDispatch(g_state.hSimConnect, vatswim_dispatch_proc, NULL);
}

/**
 * Set flight plan info (call when connecting to VATSIM)
 */
static inline void vatswim_set_flight_info(const char* callsign, const char* departure,
                                           const char* destination) {
    strncpy(g_config.callsign, callsign, sizeof(g_config.callsign) - 1);
    strncpy(g_config.departure, departure, sizeof(g_config.departure) - 1);
    strncpy(g_config.destination, destination, sizeof(g_config.destination) - 1);

    // Reset OOOI for new flight plan
    swim_oooi_reset(&g_state.oooi);
    g_state.flight_active = true;
    g_state.flight_start_time = time(NULL);

    vatswim_log("INFO", "Flight info set: %s %s->%s", callsign, departure, destination);
}

/**
 * Get plugin statistics
 */
static inline void vatswim_get_stats(unsigned int* tracks, unsigned int* oooi_events,
                                     unsigned int* errors) {
    if (tracks) *tracks = g_state.tracks_sent;
    if (oooi_events) *oooi_events = g_state.oooi_events_sent;
    if (errors) *errors = g_state.errors;
}

#ifdef __cplusplus
}
#endif

#endif /* VATSWIM_SIMCONNECT_HANDLER_H */
