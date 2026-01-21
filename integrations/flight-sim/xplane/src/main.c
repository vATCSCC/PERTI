/**
 * VATSWIM X-Plane Plugin
 *
 * X-Plane 11/12 integration for VATSWIM (VATSIM System Wide Information Management).
 * Provides real-time track reporting and OOOI detection via XPLM SDK.
 *
 * @package VATSWIM
 * @subpackage X-Plane Integration
 * @version 1.0.0
 */

#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <stdarg.h>
#include <time.h>

// X-Plane SDK
#include <XPLMPlugin.h>
#include <XPLMDataAccess.h>
#include <XPLMProcessing.h>
#include <XPLMMenus.h>
#include <XPLMUtilities.h>

// VATSWIM DataRefs
#include "datarefs.h"

// VATSWIM SDK (header-only)
#include "../../../../sdk/cpp/include/swim/swim.h"

// Plugin info
#define VATSWIM_XPLANE_VERSION "1.0.0"
#define VATSWIM_XPLANE_NAME "VATSWIM X-Plane Plugin"
#define VATSWIM_XPLANE_SIG "org.vatcscc.vatswim.xplane"
#define VATSWIM_XPLANE_DESC "VATSWIM track reporting and OOOI detection"

// Configuration
typedef struct {
    char api_key[128];
    char api_base_url[256];
    char callsign[16];
    char departure[8];
    char destination[8];
    float track_interval_sec;
    int enable_oooi;
    int enable_tracks;
    int verbose_logging;
} VATSWIMConfig;

// Plugin state
typedef struct {
    VATSWIMDataRefs datarefs;
    VATSWIMConfig config;
    SwimOOOI oooi;

    int flight_active;
    time_t last_track_time;
    time_t flight_start_time;

    unsigned int tracks_sent;
    unsigned int oooi_events_sent;
    unsigned int errors;

    XPLMMenuID menu_id;
    int menu_item_enable;
    int menu_item_verbose;
} VATSWIMState;

static VATSWIMState g_state = {0};

// Forward declarations
static float vatswim_flight_loop(float elapsed_since_last_call,
                                  float elapsed_since_last_flight_loop,
                                  int counter, void* refcon);
static void vatswim_menu_handler(void* menu_ref, void* item_ref);

/**
 * Log a message to X-Plane log
 */
static void vatswim_log(const char* level, const char* format, ...) {
    char message[1024];
    va_list args;
    va_start(args, format);
    vsnprintf(message, sizeof(message), format, args);
    va_end(args);

    char full_message[1100];
    snprintf(full_message, sizeof(full_message), "[VATSWIM] [%s] %s\n", level, message);

    XPLMDebugString(full_message);

    // Also log to file if verbose
    if (g_state.config.verbose_logging) {
        char log_path[512];
        XPLMGetSystemPath(log_path);
        strcat(log_path, "vatswim_xplane.log");

        FILE* f = fopen(log_path, "a");
        if (f) {
            time_t now = time(NULL);
            char timestamp[32];
            strftime(timestamp, sizeof(timestamp), "%Y-%m-%d %H:%M:%S", localtime(&now));
            fprintf(f, "[%s] [%s] %s\n", timestamp, level, message);
            fclose(f);
        }
    }
}

/**
 * Load configuration from preferences
 */
static void vatswim_load_config(void) {
    char prefs_path[512];
    XPLMGetPrefsPath(prefs_path);

    // Find the preferences directory and build config path
    char* last_sep = strrchr(prefs_path, '/');
    if (!last_sep) last_sep = strrchr(prefs_path, '\\');
    if (last_sep) *last_sep = '\0';
    strcat(prefs_path, "/vatswim_config.txt");

    // Set defaults
    memset(&g_state.config, 0, sizeof(VATSWIMConfig));
    strncpy(g_state.config.api_base_url, "https://perti.vatcscc.org/api/swim/v1",
            sizeof(g_state.config.api_base_url) - 1);
    g_state.config.track_interval_sec = 1.0f;
    g_state.config.enable_oooi = 1;
    g_state.config.enable_tracks = 1;
    g_state.config.verbose_logging = 0;

    // Try to load from config file
    FILE* f = fopen(prefs_path, "r");
    if (f) {
        char line[512];
        while (fgets(line, sizeof(line), f)) {
            char key[64], value[256];
            if (sscanf(line, "%63[^=]=%255[^\n]", key, value) == 2) {
                if (strcmp(key, "api_key") == 0) {
                    strncpy(g_state.config.api_key, value, sizeof(g_state.config.api_key) - 1);
                } else if (strcmp(key, "api_base_url") == 0) {
                    strncpy(g_state.config.api_base_url, value, sizeof(g_state.config.api_base_url) - 1);
                } else if (strcmp(key, "track_interval") == 0) {
                    g_state.config.track_interval_sec = (float)atof(value);
                } else if (strcmp(key, "enable_oooi") == 0) {
                    g_state.config.enable_oooi = atoi(value);
                } else if (strcmp(key, "enable_tracks") == 0) {
                    g_state.config.enable_tracks = atoi(value);
                } else if (strcmp(key, "verbose_logging") == 0) {
                    g_state.config.verbose_logging = atoi(value);
                }
            }
        }
        fclose(f);
        vatswim_log("INFO", "Configuration loaded from %s", prefs_path);
    } else {
        vatswim_log("WARN", "No config file found at %s, using defaults", prefs_path);
    }

    // Disable reporting if no API key
    if (strlen(g_state.config.api_key) == 0) {
        vatswim_log("WARN", "No API key configured - track/OOOI reporting disabled");
        g_state.config.enable_tracks = 0;
        g_state.config.enable_oooi = 0;
    }
}

/**
 * Create plugin menu
 */
static void vatswim_create_menu(void) {
    int menu_container = XPLMAppendMenuItem(XPLMFindPluginsMenu(), "VATSWIM", NULL, 0);
    g_state.menu_id = XPLMCreateMenu("VATSWIM", XPLMFindPluginsMenu(), menu_container,
                                      vatswim_menu_handler, NULL);

    g_state.menu_item_enable = XPLMAppendMenuItem(g_state.menu_id,
        g_state.config.enable_tracks ? "Disable Track Reporting" : "Enable Track Reporting",
        (void*)1, 0);

    g_state.menu_item_verbose = XPLMAppendMenuItem(g_state.menu_id,
        g_state.config.verbose_logging ? "Disable Verbose Logging" : "Enable Verbose Logging",
        (void*)2, 0);

    XPLMAppendMenuSeparator(g_state.menu_id);
    XPLMAppendMenuItem(g_state.menu_id, "Show Statistics", (void*)3, 0);
}

/**
 * Menu handler
 */
static void vatswim_menu_handler(void* menu_ref, void* item_ref) {
    intptr_t item = (intptr_t)item_ref;

    switch (item) {
        case 1: // Toggle tracks
            g_state.config.enable_tracks = !g_state.config.enable_tracks;
            XPLMSetMenuItemName(g_state.menu_id, g_state.menu_item_enable,
                g_state.config.enable_tracks ? "Disable Track Reporting" : "Enable Track Reporting", 0);
            vatswim_log("INFO", "Track reporting %s",
                        g_state.config.enable_tracks ? "enabled" : "disabled");
            break;

        case 2: // Toggle verbose logging
            g_state.config.verbose_logging = !g_state.config.verbose_logging;
            XPLMSetMenuItemName(g_state.menu_id, g_state.menu_item_verbose,
                g_state.config.verbose_logging ? "Disable Verbose Logging" : "Enable Verbose Logging", 0);
            vatswim_log("INFO", "Verbose logging %s",
                        g_state.config.verbose_logging ? "enabled" : "disabled");
            break;

        case 3: // Show statistics
            vatswim_log("INFO", "Statistics: %u tracks sent, %u OOOI events, %u errors",
                        g_state.tracks_sent, g_state.oooi_events_sent, g_state.errors);
            break;
    }
}

/**
 * Submit track position to SWIM API
 */
static void vatswim_submit_track(VATSWIMPositionData* pos) {
    if (!g_state.config.enable_tracks || !g_state.flight_active) {
        return;
    }

    // Check if enough time has passed
    time_t now = time(NULL);
    if (now - g_state.last_track_time < (time_t)g_state.config.track_interval_sec) {
        return;
    }

    // Build track update
    SwimTrackUpdate track = {0};
    strncpy(track.callsign, g_state.config.callsign, sizeof(track.callsign) - 1);
    track.position.latitude = pos->latitude;
    track.position.longitude = pos->longitude;
    track.position.altitude_ft = pos->altitude_ft;
    track.position.groundspeed_kts = pos->groundspeed_kts;
    track.position.heading_deg = pos->heading_mag;
    track.position.vertical_rate_fpm = pos->vertical_speed_fpm;
    track.position.on_ground = pos->on_ground != 0;
    track.position.timestamp = (uint64_t)now;
    strncpy(track.source, "xplane_plugin", sizeof(track.source) - 1);

    // Build JSON
    char json[1024];
    swim_json_track(&track, json, sizeof(json));

    // Send to API
    char url[512];
    snprintf(url, sizeof(url), "%s/ingest/track", g_state.config.api_base_url);

    char response[2048];
    int http_code = swim_http_post(url, g_state.config.api_key, json, response, sizeof(response));

    if (http_code >= 200 && http_code < 300) {
        g_state.tracks_sent++;
        g_state.last_track_time = now;
        if (g_state.config.verbose_logging) {
            vatswim_log("DEBUG", "Track submitted: %.4f, %.4f, %.0f ft",
                        pos->latitude, pos->longitude, pos->altitude_ft);
        }
    } else {
        g_state.errors++;
        vatswim_log("ERROR", "Track submission failed: HTTP %d", http_code);
    }
}

/**
 * Submit OOOI event
 */
static void vatswim_submit_oooi_event(SwimFlightPhase phase) {
    if (!g_state.config.enable_oooi || !g_state.flight_active) {
        return;
    }

    const char* time_field = NULL;
    const char* phase_name = NULL;

    switch (phase) {
        case SWIM_PHASE_OUT:
            time_field = "out_utc";
            phase_name = "OUT";
            break;
        case SWIM_PHASE_TAKEOFF:
            time_field = "off_utc";
            phase_name = "OFF";
            break;
        case SWIM_PHASE_LANDING:
            time_field = "on_utc";
            phase_name = "ON";
            break;
        case SWIM_PHASE_IN:
            time_field = "in_utc";
            phase_name = "IN";
            break;
        default:
            return; // Not an OOOI event
    }

    // Build timestamp
    time_t now = time(NULL);
    struct tm* utc = gmtime(&now);
    char timestamp[32];
    strftime(timestamp, sizeof(timestamp), "%Y-%m-%dT%H:%M:%SZ", utc);

    // Build JSON
    char json[512];
    snprintf(json, sizeof(json),
        "{"
        "\"callsign\":\"%s\","
        "\"dept_icao\":\"%s\","
        "\"dest_icao\":\"%s\","
        "\"%s\":\"%s\","
        "\"source\":\"xplane_plugin\""
        "}",
        g_state.config.callsign,
        g_state.config.departure,
        g_state.config.destination,
        time_field,
        timestamp);

    // Send to API
    char url[512];
    snprintf(url, sizeof(url), "%s/ingest/adl", g_state.config.api_base_url);

    char response[2048];
    int http_code = swim_http_post(url, g_state.config.api_key, json, response, sizeof(response));

    if (http_code >= 200 && http_code < 300) {
        g_state.oooi_events_sent++;
        vatswim_log("INFO", "OOOI event submitted: %s = %s", phase_name, timestamp);
    } else {
        g_state.errors++;
        vatswim_log("ERROR", "OOOI submission failed: HTTP %d", http_code);
    }
}

/**
 * Flight loop callback
 */
static float vatswim_flight_loop(float elapsed_since_last_call,
                                  float elapsed_since_last_flight_loop,
                                  int counter, void* refcon) {
    // Skip if paused or in replay
    VATSWIMPositionData pos = {0};
    vatswim_read_position(&g_state.datarefs, &pos);

    if (pos.paused || pos.replay) {
        return 1.0f; // Check again in 1 second
    }

    // Submit track position
    vatswim_submit_track(&pos);

    // Process OOOI detection
    if (g_state.config.enable_oooi && g_state.flight_active) {
        SwimPosition swim_pos = {0};
        swim_pos.latitude = pos.latitude;
        swim_pos.longitude = pos.longitude;
        swim_pos.altitude_ft = pos.altitude_ft;
        swim_pos.groundspeed_kts = pos.groundspeed_kts;
        swim_pos.vertical_rate_fpm = pos.vertical_speed_fpm;
        swim_pos.on_ground = pos.on_ground != 0;
        swim_pos.timestamp = (uint64_t)time(NULL);

        SwimFlightPhase prev_phase = g_state.oooi.current_phase;
        swim_oooi_update(&g_state.oooi, &swim_pos);

        if (g_state.oooi.current_phase != prev_phase) {
            vatswim_submit_oooi_event(g_state.oooi.current_phase);
        }
    }

    return 1.0f; // Call every second
}

// ============================================================================
// X-Plane Plugin Interface
// ============================================================================

PLUGIN_API int XPluginStart(char* outName, char* outSig, char* outDesc) {
    strcpy(outName, VATSWIM_XPLANE_NAME);
    strcpy(outSig, VATSWIM_XPLANE_SIG);
    strcpy(outDesc, VATSWIM_XPLANE_DESC);

    vatswim_log("INFO", "VATSWIM X-Plane Plugin v%s starting", VATSWIM_XPLANE_VERSION);

    // Load configuration
    vatswim_load_config();

    // Initialize DataRefs
    if (!vatswim_init_datarefs(&g_state.datarefs)) {
        vatswim_log("ERROR", "Failed to initialize DataRefs");
        return 0;
    }

    // Initialize OOOI detector
    swim_oooi_reset(&g_state.oooi);

    // Create menu
    vatswim_create_menu();

    // Register flight loop callback
    XPLMRegisterFlightLoopCallback(vatswim_flight_loop, 1.0f, NULL);

    vatswim_log("INFO", "VATSWIM X-Plane Plugin initialized successfully");
    return 1;
}

PLUGIN_API void XPluginStop(void) {
    vatswim_log("INFO", "VATSWIM X-Plane Plugin stopping");

    // Log statistics
    vatswim_log("INFO", "Session stats: %u tracks sent, %u OOOI events, %u errors",
                g_state.tracks_sent, g_state.oooi_events_sent, g_state.errors);

    // Unregister flight loop
    XPLMUnregisterFlightLoopCallback(vatswim_flight_loop, NULL);

    // Destroy menu
    if (g_state.menu_id) {
        XPLMDestroyMenu(g_state.menu_id);
    }
}

PLUGIN_API int XPluginEnable(void) {
    vatswim_log("INFO", "Plugin enabled");
    return 1;
}

PLUGIN_API void XPluginDisable(void) {
    vatswim_log("INFO", "Plugin disabled");
}

PLUGIN_API void XPluginReceiveMessage(XPLMPluginID from, int msg, void* param) {
    switch (msg) {
        case XPLM_MSG_PLANE_LOADED:
            if (param == 0) { // User aircraft
                VATSWIMAircraftData aircraft = {0};
                vatswim_read_aircraft(&g_state.datarefs, &aircraft);
                vatswim_log("INFO", "Aircraft loaded: %s (%s)",
                            aircraft.description, aircraft.icao_type);
            }
            break;

        case XPLM_MSG_AIRPORT_LOADED:
            vatswim_log("INFO", "Airport/scenery loaded");
            break;

        case XPLM_MSG_PLANE_CRASHED:
            vatswim_log("INFO", "Aircraft crashed - resetting OOOI");
            swim_oooi_reset(&g_state.oooi);
            g_state.flight_active = 0;
            break;
    }
}

// ============================================================================
// External API (for xPilot integration)
// ============================================================================

/**
 * Set flight info (called by xPilot when connecting to VATSIM)
 */
PLUGIN_API void VATSWIM_SetFlightInfo(const char* callsign, const char* departure,
                                       const char* destination) {
    strncpy(g_state.config.callsign, callsign, sizeof(g_state.config.callsign) - 1);
    strncpy(g_state.config.departure, departure, sizeof(g_state.config.departure) - 1);
    strncpy(g_state.config.destination, destination, sizeof(g_state.config.destination) - 1);

    swim_oooi_reset(&g_state.oooi);
    g_state.flight_active = 1;
    g_state.flight_start_time = time(NULL);

    vatswim_log("INFO", "Flight info set: %s %s->%s", callsign, departure, destination);
}

/**
 * Set API key at runtime
 */
PLUGIN_API void VATSWIM_SetApiKey(const char* api_key) {
    strncpy(g_state.config.api_key, api_key, sizeof(g_state.config.api_key) - 1);
    g_state.config.enable_tracks = strlen(api_key) > 0;
    g_state.config.enable_oooi = strlen(api_key) > 0;
    vatswim_log("INFO", "API key updated, reporting %s",
                g_state.config.enable_tracks ? "enabled" : "disabled");
}

/**
 * Enable/disable track reporting
 */
PLUGIN_API void VATSWIM_EnableTracks(int enable) {
    g_state.config.enable_tracks = enable && strlen(g_state.config.api_key) > 0;
    vatswim_log("INFO", "Track reporting %s", g_state.config.enable_tracks ? "enabled" : "disabled");
}

/**
 * Enable/disable OOOI detection
 */
PLUGIN_API void VATSWIM_EnableOOOI(int enable) {
    g_state.config.enable_oooi = enable && strlen(g_state.config.api_key) > 0;
    vatswim_log("INFO", "OOOI detection %s", g_state.config.enable_oooi ? "enabled" : "disabled");
}

/**
 * Get plugin version
 */
PLUGIN_API const char* VATSWIM_GetVersion(void) {
    return VATSWIM_XPLANE_VERSION;
}

/**
 * Check connection status
 */
PLUGIN_API int VATSWIM_IsActive(void) {
    return g_state.flight_active;
}
