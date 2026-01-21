/**
 * VATSWIM P3D Plugin
 *
 * Prepar3D v4/v5 integration for VATSWIM (VATSIM System Wide Information Management).
 * Provides real-time track reporting and OOOI detection.
 *
 * Build as a DLL addon for P3D.
 *
 * @package VATSWIM
 * @subpackage P3D Integration
 * @version 1.0.0
 */

#include <windows.h>
#include <stdio.h>
#include <stdarg.h>
#include <time.h>

// P3D SimConnect SDK
#include <SimConnect.h>

// VATSWIM common SimConnect code (shared with MSFS)
#include "../../common/simconnect/simconnect_handler.h"

// Plugin constants
#define VATSWIM_P3D_VERSION "1.0.0"
#define VATSWIM_P3D_NAME "VATSWIM-P3D"

// Configuration file path
#define CONFIG_FILE "vatswim_p3d.ini"

// Log file
static FILE* g_log_file = NULL;

/**
 * Implementation of vatswim_log for P3D
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

    char full_message[1200];
    snprintf(full_message, sizeof(full_message), "[%s] [%s] %s\n", timestamp, level, message);

    // Write to log file
    if (g_log_file) {
        fputs(full_message, g_log_file);
        fflush(g_log_file);
    }

    // Write to debug output
    OutputDebugStringA(full_message);
}

/**
 * Load configuration from INI file
 */
static bool load_config(void) {
    // Set defaults
    memset(&g_config, 0, sizeof(g_config));
    strncpy(g_config.api_base_url, "https://perti.vatcscc.org/api/swim/v1",
            sizeof(g_config.api_base_url) - 1);
    g_config.track_interval_ms = 1000;
    g_config.enable_oooi = true;
    g_config.enable_tracks = true;
    g_config.verbose_logging = false;

    // Build config path (same directory as DLL)
    char config_path[MAX_PATH];
    HMODULE hModule = NULL;
    GetModuleHandleExA(GET_MODULE_HANDLE_EX_FLAG_FROM_ADDRESS | GET_MODULE_HANDLE_EX_FLAG_UNCHANGED_REFCOUNT,
                       (LPCSTR)&load_config, &hModule);
    GetModuleFileNameA(hModule, config_path, sizeof(config_path));

    char* last_slash = strrchr(config_path, '\\');
    if (last_slash) {
        *last_slash = '\0';
        strcat_s(config_path, sizeof(config_path), "\\");
        strcat_s(config_path, sizeof(config_path), CONFIG_FILE);
    }

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

    vatswim_log("INFO", "Configuration loaded from %s", config_path);
    vatswim_log("INFO", "  Track Interval: %d ms", g_config.track_interval_ms);
    vatswim_log("INFO", "  OOOI Detection: %s", g_config.enable_oooi ? "enabled" : "disabled");
    vatswim_log("INFO", "  Track Reporting: %s", g_config.enable_tracks ? "enabled" : "disabled");

    if (strlen(g_config.api_key) == 0) {
        vatswim_log("WARN", "No API key configured - track/OOOI reporting disabled");
        g_config.enable_tracks = false;
        g_config.enable_oooi = false;
    }

    return true;
}

/**
 * P3D-specific initialization
 * Called from add-on.xml auto-load or DLLStart
 */
static bool vatswim_p3d_init(void) {
    // Open log file
    char log_path[MAX_PATH];
    GetTempPathA(sizeof(log_path), log_path);
    strcat_s(log_path, sizeof(log_path), "vatswim_p3d.log");
    g_log_file = fopen(log_path, "a");

    vatswim_log("INFO", "VATSWIM P3D Plugin v%s initializing", VATSWIM_P3D_VERSION);

    // Load configuration
    load_config();

    // Initialize plugin state
    vatswim_init_state();

    // Connect to SimConnect
    if (!vatswim_connect(VATSWIM_P3D_NAME)) {
        vatswim_log("ERROR", "Failed to connect to SimConnect");
        return false;
    }

    vatswim_log("INFO", "VATSWIM P3D Plugin initialized successfully");
    return true;
}

/**
 * P3D-specific shutdown
 */
static void vatswim_p3d_shutdown(void) {
    vatswim_log("INFO", "VATSWIM P3D Plugin shutting down");

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

// ============================================================================
// P3D DLL Addon Interface
// ============================================================================

/**
 * DLLStart - Called when P3D loads the addon
 */
extern "C" __declspec(dllexport)
void __stdcall DLLStart(void) {
    vatswim_p3d_init();
}

/**
 * DLLStop - Called when P3D unloads the addon
 */
extern "C" __declspec(dllexport)
void __stdcall DLLStop(void) {
    vatswim_p3d_shutdown();
}

// ============================================================================
// External API (for vPilot integration)
// ============================================================================

/**
 * Set callsign and flight plan
 */
extern "C" __declspec(dllexport)
void VATSWIM_SetFlightInfo(const char* callsign, const char* departure, const char* destination) {
    vatswim_set_flight_info(callsign, departure, destination);
}

/**
 * Set API key at runtime
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
    return VATSWIM_P3D_VERSION;
}

/**
 * Get current statistics
 */
extern "C" __declspec(dllexport)
void VATSWIM_GetStats(unsigned int* tracks_sent, unsigned int* oooi_events, unsigned int* errors) {
    vatswim_get_stats(tracks_sent, oooi_events, errors);
}

/**
 * Check if connected
 */
extern "C" __declspec(dllexport)
bool VATSWIM_IsConnected(void) {
    return g_state.connected;
}

/**
 * Process messages (call from external timer if needed)
 */
extern "C" __declspec(dllexport)
void VATSWIM_ProcessMessages(void) {
    vatswim_process_messages();
}

// ============================================================================
// DllMain
// ============================================================================

BOOL APIENTRY DllMain(HMODULE hModule, DWORD reason, LPVOID lpReserved) {
    switch (reason) {
        case DLL_PROCESS_ATTACH:
            DisableThreadLibraryCalls(hModule);
            break;

        case DLL_PROCESS_DETACH:
            break;
    }
    return TRUE;
}
