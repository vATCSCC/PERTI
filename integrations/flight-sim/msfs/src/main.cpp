/**
 * VATSWIM MSFS Plugin
 *
 * Microsoft Flight Simulator 2020/2024 integration for VATSWIM.
 * Provides real-time track reporting and OOOI detection.
 *
 * Build as a WASM module for Community folder installation.
 *
 * @package VATSWIM
 * @subpackage MSFS Integration
 * @version 1.0.0
 */

#include <windows.h>
#include <stdio.h>
#include <stdarg.h>
#include <time.h>

// SimConnect SDK
#include <SimConnect.h>

// VATSWIM common SimConnect code
#include "../../common/simconnect/simconnect_handler.h"

// Plugin constants
#define VATSWIM_MSFS_VERSION "1.0.0"
#define VATSWIM_MSFS_NAME "VATSWIM-MSFS"

// Configuration file path (relative to package folder)
#define CONFIG_FILE "vatswim_config.ini"

// Log file
static FILE* g_log_file = NULL;
static bool g_log_to_console = true;

/**
 * Implementation of vatswim_log for MSFS
 */
void vatswim_log(const char* level, const char* format, ...) {
    char timestamp[32];
    time_t now = time(NULL);
    struct tm* local = localtime(&now);
    strftime(timestamp, sizeof(timestamp), "%Y-%m-%d %H:%M:%S", local);

    char message[1024];
    va_list args;
    va_start(args, format);
    vsnprintf(message, sizeof(message), format, args);
    va_end(args);

    // Format: [TIMESTAMP] [LEVEL] message
    char full_message[1200];
    snprintf(full_message, sizeof(full_message), "[%s] [%s] %s\n", timestamp, level, message);

    // Write to log file
    if (g_log_file) {
        fputs(full_message, g_log_file);
        fflush(g_log_file);
    }

    // Write to debug output (visible in debug builds)
    OutputDebugStringA(full_message);

    // Console output for gauge development
    if (g_log_to_console) {
        printf("%s", full_message);
    }
}

/**
 * Load configuration from INI file
 */
static bool load_config(const char* config_path) {
    // Set defaults
    memset(&g_config, 0, sizeof(g_config));
    strncpy(g_config.api_base_url, "https://perti.vatcscc.org/api/swim/v1",
            sizeof(g_config.api_base_url) - 1);
    g_config.track_interval_ms = 1000;  // 1 second
    g_config.enable_oooi = true;
    g_config.enable_tracks = true;
    g_config.verbose_logging = false;

    // Try to load from INI file
    char api_key[128] = {0};
    GetPrivateProfileStringA("VATSWIM", "ApiKey", "", api_key, sizeof(api_key), config_path);
    if (strlen(api_key) > 0) {
        strncpy(g_config.api_key, api_key, sizeof(g_config.api_key) - 1);
    }

    char base_url[256] = {0};
    GetPrivateProfileStringA("VATSWIM", "ApiBaseUrl", "", base_url, sizeof(base_url), config_path);
    if (strlen(base_url) > 0) {
        strncpy(g_config.api_base_url, base_url, sizeof(g_config.api_base_url) - 1);
    }

    g_config.track_interval_ms = GetPrivateProfileIntA("VATSWIM", "TrackIntervalMs", 1000, config_path);
    g_config.enable_oooi = GetPrivateProfileIntA("VATSWIM", "EnableOOOI", 1, config_path) != 0;
    g_config.enable_tracks = GetPrivateProfileIntA("VATSWIM", "EnableTracks", 1, config_path) != 0;
    g_config.verbose_logging = GetPrivateProfileIntA("VATSWIM", "VerboseLogging", 0, config_path) != 0;

    vatswim_log("INFO", "Configuration loaded:");
    vatswim_log("INFO", "  API Base URL: %s", g_config.api_base_url);
    vatswim_log("INFO", "  Track Interval: %d ms", g_config.track_interval_ms);
    vatswim_log("INFO", "  OOOI Detection: %s", g_config.enable_oooi ? "enabled" : "disabled");
    vatswim_log("INFO", "  Track Reporting: %s", g_config.enable_tracks ? "enabled" : "disabled");

    if (strlen(g_config.api_key) == 0) {
        vatswim_log("WARN", "No API key configured - track/OOOI reporting disabled until key provided");
        g_config.enable_tracks = false;
        g_config.enable_oooi = false;
    }

    return true;
}

/**
 * Initialize the MSFS plugin
 */
static bool vatswim_msfs_init(void) {
    // Open log file
    char log_path[MAX_PATH];
    GetTempPathA(sizeof(log_path), log_path);
    strcat_s(log_path, sizeof(log_path), "vatswim_msfs.log");
    g_log_file = fopen(log_path, "a");

    vatswim_log("INFO", "VATSWIM MSFS Plugin v%s initializing", VATSWIM_MSFS_VERSION);

    // Load configuration
    char config_path[MAX_PATH];
    GetModuleFileNameA(NULL, config_path, sizeof(config_path));
    // Navigate to package folder (strip executable path)
    char* last_slash = strrchr(config_path, '\\');
    if (last_slash) {
        *last_slash = '\0';
        strcat_s(config_path, sizeof(config_path), "\\");
        strcat_s(config_path, sizeof(config_path), CONFIG_FILE);
    }
    load_config(config_path);

    // Initialize plugin state
    vatswim_init_state();

    // Connect to SimConnect
    if (!vatswim_connect(VATSWIM_MSFS_NAME)) {
        vatswim_log("ERROR", "Failed to connect to SimConnect");
        return false;
    }

    vatswim_log("INFO", "VATSWIM MSFS Plugin initialized successfully");
    return true;
}

/**
 * Shutdown the MSFS plugin
 */
static void vatswim_msfs_shutdown(void) {
    vatswim_log("INFO", "VATSWIM MSFS Plugin shutting down");

    // Log statistics
    unsigned int tracks, oooi_events, errors;
    vatswim_get_stats(&tracks, &oooi_events, &errors);
    vatswim_log("INFO", "Session stats: %u tracks sent, %u OOOI events, %u errors",
                tracks, oooi_events, errors);

    // Disconnect from SimConnect
    vatswim_disconnect();

    // Close log file
    if (g_log_file) {
        fclose(g_log_file);
        g_log_file = NULL;
    }
}

/**
 * Main update loop (called periodically)
 */
static void vatswim_msfs_update(void) {
    // Process SimConnect messages
    vatswim_process_messages();
}

/**
 * Set callsign and flight plan (called from external integration, e.g., vPilot)
 *
 * @param callsign VATSIM callsign
 * @param departure Departure ICAO
 * @param destination Destination ICAO
 */
extern "C" __declspec(dllexport)
void VATSWIM_SetFlightInfo(const char* callsign, const char* departure, const char* destination) {
    vatswim_set_flight_info(callsign, departure, destination);
}

/**
 * Set API key at runtime (called from external integration)
 *
 * @param api_key VATSWIM API key
 */
extern "C" __declspec(dllexport)
void VATSWIM_SetApiKey(const char* api_key) {
    strncpy(g_config.api_key, api_key, sizeof(g_config.api_key) - 1);
    g_config.enable_tracks = strlen(api_key) > 0;
    g_config.enable_oooi = strlen(api_key) > 0;
    vatswim_log("INFO", "API key updated, reporting %s",
                g_config.enable_tracks ? "enabled" : "disabled");
}

/**
 * Enable/disable track reporting
 */
extern "C" __declspec(dllexport)
void VATSWIM_EnableTracks(bool enable) {
    g_config.enable_tracks = enable && strlen(g_config.api_key) > 0;
    vatswim_log("INFO", "Track reporting %s", g_config.enable_tracks ? "enabled" : "disabled");
}

/**
 * Enable/disable OOOI detection
 */
extern "C" __declspec(dllexport)
void VATSWIM_EnableOOOI(bool enable) {
    g_config.enable_oooi = enable && strlen(g_config.api_key) > 0;
    vatswim_log("INFO", "OOOI detection %s", g_config.enable_oooi ? "enabled" : "disabled");
}

/**
 * Get plugin version
 */
extern "C" __declspec(dllexport)
const char* VATSWIM_GetVersion(void) {
    return VATSWIM_MSFS_VERSION;
}

/**
 * Get current statistics
 */
extern "C" __declspec(dllexport)
void VATSWIM_GetStats(unsigned int* tracks_sent, unsigned int* oooi_events,
                      unsigned int* errors) {
    vatswim_get_stats(tracks_sent, oooi_events, errors);
}

/**
 * Check if connected to simulator
 */
extern "C" __declspec(dllexport)
bool VATSWIM_IsConnected(void) {
    return g_state.connected;
}

// ============================================================================
// MSFS Gauge Module Interface (for WASM build)
// ============================================================================

#ifdef MSFS_WASM

#include <MSFS/MSFS.h>
#include <MSFS/MSFS_Render.h>

extern "C" {

    MSFS_CALLBACK bool module_init(void) {
        return vatswim_msfs_init();
    }

    MSFS_CALLBACK bool module_deinit(void) {
        vatswim_msfs_shutdown();
        return true;
    }

    // Gauge callback for periodic updates
    MSFS_CALLBACK void gauge_callback(FsContext ctx, int service_id, void* pData) {
        switch (service_id) {
            case PANEL_SERVICE_PRE_UPDATE:
                vatswim_msfs_update();
                break;

            case PANEL_SERVICE_PRE_KILL:
                vatswim_msfs_shutdown();
                break;
        }
    }
}

#else

// ============================================================================
// Standalone DLL Interface (for non-WASM builds, testing)
// ============================================================================

BOOL APIENTRY DllMain(HMODULE hModule, DWORD reason, LPVOID lpReserved) {
    switch (reason) {
        case DLL_PROCESS_ATTACH:
            DisableThreadLibraryCalls(hModule);
            vatswim_msfs_init();
            break;

        case DLL_PROCESS_DETACH:
            vatswim_msfs_shutdown();
            break;
    }
    return TRUE;
}

#endif // MSFS_WASM
