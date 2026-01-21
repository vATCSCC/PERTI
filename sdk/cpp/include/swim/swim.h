/**
 * VATSWIM C/C++ SDK - Main Header
 *
 * Header-only library for flight simulator integration with VATSWIM API.
 * Provides telemetry ingestion and OOOI detection for MSFS, X-Plane, and P3D.
 *
 * Usage:
 *   #define SWIM_USE_CURL  // Enable HTTP support (requires libcurl)
 *   #include <swim/swim.h>
 *
 * @file swim.h
 * @version 1.0.0
 * @license MIT
 *
 * @example
 *   // Initialize client
 *   SwimClientConfig config = {0};
 *   strcpy(config.api_key, "swim_par_your_key");
 *   strcpy(config.source_id, "msfs_plugin");
 *   config.timeout_ms = 5000;
 *
 *   SwimClient client;
 *   swim_client_init(&client, &config);
 *
 *   // Initialize OOOI detector
 *   SwimOOOIDetector oooi;
 *   swim_oooi_init(&oooi);
 *
 *   // In your update loop:
 *   swim_oooi_update(&oooi, gs, on_ground, agl, vs, parking_brake);
 *
 *   // Periodically send position updates
 *   SwimTrackUpdate track = {0};
 *   strcpy(track.callsign, "UAL123");
 *   track.position.latitude = 40.6413;
 *   track.position.longitude = -73.7781;
 *   // ... set other fields ...
 *
 *   swim_client_ingest_track(&client, &track, 1);
 */

#ifndef SWIM_H
#define SWIM_H

/* Include all component headers */
#include "types.h"
#include "telemetry.h"
#include "json.h"
#include "http.h"

#ifdef __cplusplus
extern "C" {
#endif

/* ============================================================================
 * SWIM Client
 * ============================================================================ */

typedef struct {
    SwimHttpClient http;
    SwimClientConfig config;
    SwimOOOIDetector oooi;
    SwimPositionThrottle throttle;
    bool initialized;
} SwimClient;

/**
 * Initialize SWIM client
 *
 * @param client Pointer to client instance
 * @param config Client configuration
 * @return true on success
 */
static inline bool swim_client_init(SwimClient* client, const SwimClientConfig* config) {
    if (!client || !config) return false;

    memset(client, 0, sizeof(SwimClient));
    memcpy(&client->config, config, sizeof(SwimClientConfig));

    /* Set defaults if not provided */
    if (client->config.base_url[0] == '\0') {
        strcpy(client->config.base_url, SWIM_DEFAULT_BASE_URL);
    }
    if (client->config.timeout_ms == 0) {
        client->config.timeout_ms = 30000;
    }

    /* Initialize HTTP client */
    if (!swim_http_init(&client->http, &client->config)) {
        return false;
    }

    /* Initialize OOOI detector */
    swim_oooi_init(&client->oooi);

    /* Initialize throttle (5 second interval, 0.5nm distance, 100ft altitude) */
    swim_throttle_init(&client->throttle, 5, 0.5, 100);

    client->initialized = true;
    return true;
}

/**
 * Cleanup SWIM client
 *
 * @param client Pointer to client instance
 */
static inline void swim_client_cleanup(SwimClient* client) {
    if (!client) return;

    swim_http_cleanup(&client->http);
    client->initialized = false;
}

/**
 * Ingest track updates (position data)
 *
 * @param client Pointer to client instance
 * @param tracks Array of track updates
 * @param count Number of tracks in array (max 1000)
 * @param result Result structure to fill
 * @return Status code
 */
static inline SwimStatus swim_client_ingest_track(
    SwimClient* client,
    const SwimTrackUpdate* tracks,
    int count,
    SwimIngestResult* result
) {
    if (!client || !client->initialized || !tracks || count <= 0 || !result) {
        if (result) {
            result->status = SWIM_ERROR_INVALID_DATA;
            strcpy(result->error_message, "Invalid parameters");
        }
        return SWIM_ERROR_INVALID_DATA;
    }

    if (count > SWIM_MAX_BATCH_TRACKS) {
        count = SWIM_MAX_BATCH_TRACKS;
    }

    /* Build JSON */
    SwimJsonBuilder json;
    if (!swim_json_init(&json, count * 256)) {
        result->status = SWIM_ERROR_BUFFER;
        strcpy(result->error_message, "Failed to allocate JSON buffer");
        return SWIM_ERROR_BUFFER;
    }

    swim_json_object_start(&json);
    swim_json_array_start(&json, "tracks");

    for (int i = 0; i < count; i++) {
        swim_json_track(&json, &tracks[i]);
    }

    swim_json_array_end(&json);
    swim_json_object_end(&json);

    /* Send request */
    SwimStatus status = swim_http_post(&client->http, "/ingest/track", swim_json_get(&json), result);

    swim_json_free(&json);
    return status;
}

/**
 * Ingest ADL flight data
 *
 * @param client Pointer to client instance
 * @param flights Array of flight data
 * @param count Number of flights in array (max 500)
 * @param result Result structure to fill
 * @return Status code
 */
static inline SwimStatus swim_client_ingest_adl(
    SwimClient* client,
    const SwimFlightIngest* flights,
    int count,
    SwimIngestResult* result
) {
    if (!client || !client->initialized || !flights || count <= 0 || !result) {
        if (result) {
            result->status = SWIM_ERROR_INVALID_DATA;
            strcpy(result->error_message, "Invalid parameters");
        }
        return SWIM_ERROR_INVALID_DATA;
    }

    if (count > SWIM_MAX_BATCH_ADL) {
        count = SWIM_MAX_BATCH_ADL;
    }

    /* Build JSON */
    SwimJsonBuilder json;
    if (!swim_json_init(&json, count * 512)) {
        result->status = SWIM_ERROR_BUFFER;
        strcpy(result->error_message, "Failed to allocate JSON buffer");
        return SWIM_ERROR_BUFFER;
    }

    swim_json_object_start(&json);
    swim_json_array_start(&json, "flights");

    for (int i = 0; i < count; i++) {
        swim_json_flight_ingest(&json, &flights[i]);
    }

    swim_json_array_end(&json);
    swim_json_object_end(&json);

    /* Send request */
    SwimStatus status = swim_http_post(&client->http, "/ingest/adl", swim_json_get(&json), result);

    swim_json_free(&json);
    return status;
}

/**
 * Update OOOI detector and return current state
 *
 * @param client Pointer to client instance
 * @param gs_kts Ground speed in knots
 * @param on_ground True if on ground
 * @param agl_ft Altitude AGL in feet
 * @param vs_fpm Vertical speed in feet per minute
 * @param parking_brake True if parking brake set
 * @return true if an OOOI event was detected
 */
static inline bool swim_client_update_oooi(
    SwimClient* client,
    float gs_kts,
    bool on_ground,
    float agl_ft,
    float vs_fpm,
    bool parking_brake
) {
    if (!client || !client->initialized) return false;
    return swim_oooi_update(&client->oooi, gs_kts, on_ground, agl_ft, vs_fpm, parking_brake);
}

/**
 * Get OOOI times from client
 *
 * @param client Pointer to client instance
 * @param oooi Structure to fill with OOOI times
 */
static inline void swim_client_get_oooi(const SwimClient* client, SwimOOOI* oooi) {
    if (!client || !oooi) return;
    swim_oooi_get_times(&client->oooi, oooi);
}

/**
 * Reset OOOI detector for new flight
 *
 * @param client Pointer to client instance
 */
static inline void swim_client_reset_oooi(SwimClient* client) {
    if (!client) return;
    swim_oooi_reset(&client->oooi);
}

/**
 * Check if position should be sent based on throttling
 *
 * @param client Pointer to client instance
 * @param pos Current position
 * @return true if position should be sent
 */
static inline bool swim_client_should_send_position(SwimClient* client, const SwimPosition* pos) {
    if (!client || !pos) return false;
    return swim_throttle_should_send(&client->throttle, pos);
}

/**
 * Mark position as sent
 *
 * @param client Pointer to client instance
 * @param pos Position that was sent
 */
static inline void swim_client_mark_position_sent(SwimClient* client, const SwimPosition* pos) {
    if (!client || !pos) return;
    swim_throttle_mark_sent(&client->throttle, pos);
}

/**
 * Convenience function: Send single track if throttle allows
 *
 * @param client Pointer to client instance
 * @param callsign Flight callsign
 * @param pos Current position
 * @param result Result structure to fill (can be NULL to ignore)
 * @return true if position was sent, false if throttled or error
 */
static inline bool swim_client_send_position_throttled(
    SwimClient* client,
    const char* callsign,
    const SwimPosition* pos,
    SwimIngestResult* result
) {
    if (!client || !client->initialized || !callsign || !pos) {
        return false;
    }

    if (!swim_client_should_send_position(client, pos)) {
        return false; /* Throttled */
    }

    /* Build track update */
    SwimTrackUpdate track = {0};
    strncpy(track.callsign, callsign, SWIM_MAX_CALLSIGN - 1);
    memcpy(&track.position, pos, sizeof(SwimPosition));
    track.timestamp = time(NULL);

    /* Send */
    SwimIngestResult local_result;
    if (!result) result = &local_result;

    SwimStatus status = swim_client_ingest_track(client, &track, 1, result);

    if (status == SWIM_OK) {
        swim_client_mark_position_sent(client, pos);
        return true;
    }

    return false;
}

#ifdef __cplusplus
}
#endif

#endif /* SWIM_H */
