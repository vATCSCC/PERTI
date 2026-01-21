/**
 * VATSWIM C/C++ SDK - Minimal JSON Serialization
 *
 * Header-only minimal JSON builder for API requests.
 * No parsing support - use a full JSON library if you need parsing.
 *
 * @file json.h
 * @version 1.0.0
 * @license MIT
 */

#ifndef SWIM_JSON_H
#define SWIM_JSON_H

#include "types.h"
#include <stdio.h>
#include <string.h>
#include <stdlib.h>

#ifdef __cplusplus
extern "C" {
#endif

/* ============================================================================
 * JSON Builder
 * ============================================================================ */

#define SWIM_JSON_MAX_DEPTH     16
#define SWIM_JSON_DEFAULT_SIZE  4096

typedef struct {
    char* buffer;
    size_t capacity;
    size_t length;
    int depth;
    bool first_item[SWIM_JSON_MAX_DEPTH];
    bool in_array[SWIM_JSON_MAX_DEPTH];
} SwimJsonBuilder;

/**
 * Initialize JSON builder
 */
static inline bool swim_json_init(SwimJsonBuilder* json, size_t initial_capacity) {
    if (!json) return false;

    json->capacity = initial_capacity > 0 ? initial_capacity : SWIM_JSON_DEFAULT_SIZE;
    json->buffer = (char*)malloc(json->capacity);
    if (!json->buffer) return false;

    json->buffer[0] = '\0';
    json->length = 0;
    json->depth = 0;
    memset(json->first_item, true, sizeof(json->first_item));
    memset(json->in_array, false, sizeof(json->in_array));

    return true;
}

/**
 * Free JSON builder
 */
static inline void swim_json_free(SwimJsonBuilder* json) {
    if (json && json->buffer) {
        free(json->buffer);
        json->buffer = NULL;
        json->capacity = 0;
        json->length = 0;
    }
}

/**
 * Ensure buffer has enough capacity
 */
static inline bool swim_json_ensure(SwimJsonBuilder* json, size_t needed) {
    if (!json) return false;

    size_t required = json->length + needed + 1;
    if (required <= json->capacity) return true;

    size_t new_capacity = json->capacity * 2;
    while (new_capacity < required) new_capacity *= 2;

    char* new_buffer = (char*)realloc(json->buffer, new_capacity);
    if (!new_buffer) return false;

    json->buffer = new_buffer;
    json->capacity = new_capacity;
    return true;
}

/**
 * Append raw string to buffer
 */
static inline bool swim_json_append(SwimJsonBuilder* json, const char* str) {
    if (!json || !str) return false;

    size_t len = strlen(str);
    if (!swim_json_ensure(json, len)) return false;

    memcpy(json->buffer + json->length, str, len);
    json->length += len;
    json->buffer[json->length] = '\0';

    return true;
}

/**
 * Add comma if not first item
 */
static inline void swim_json_comma(SwimJsonBuilder* json) {
    if (!json || json->depth < 0 || json->depth >= SWIM_JSON_MAX_DEPTH) return;

    if (!json->first_item[json->depth]) {
        swim_json_append(json, ",");
    }
    json->first_item[json->depth] = false;
}

/**
 * Start object
 */
static inline bool swim_json_object_start(SwimJsonBuilder* json) {
    if (!json || json->depth >= SWIM_JSON_MAX_DEPTH - 1) return false;

    swim_json_comma(json);
    swim_json_append(json, "{");
    json->depth++;
    json->first_item[json->depth] = true;
    json->in_array[json->depth] = false;

    return true;
}

/**
 * End object
 */
static inline bool swim_json_object_end(SwimJsonBuilder* json) {
    if (!json || json->depth <= 0) return false;

    json->depth--;
    swim_json_append(json, "}");

    return true;
}

/**
 * Start array
 */
static inline bool swim_json_array_start(SwimJsonBuilder* json, const char* key) {
    if (!json || json->depth >= SWIM_JSON_MAX_DEPTH - 1) return false;

    swim_json_comma(json);

    if (key) {
        swim_json_append(json, "\"");
        swim_json_append(json, key);
        swim_json_append(json, "\":[");
    } else {
        swim_json_append(json, "[");
    }

    json->depth++;
    json->first_item[json->depth] = true;
    json->in_array[json->depth] = true;

    return true;
}

/**
 * End array
 */
static inline bool swim_json_array_end(SwimJsonBuilder* json) {
    if (!json || json->depth <= 0) return false;

    json->depth--;
    swim_json_append(json, "]");

    return true;
}

/**
 * Add string value
 */
static inline bool swim_json_string(SwimJsonBuilder* json, const char* key, const char* value) {
    if (!json) return false;

    swim_json_comma(json);

    if (key) {
        swim_json_append(json, "\"");
        swim_json_append(json, key);
        swim_json_append(json, "\":");
    }

    if (value) {
        swim_json_append(json, "\"");
        /* Basic escape handling */
        for (const char* p = value; *p; p++) {
            char c = *p;
            if (c == '"' || c == '\\') {
                char buf[3] = {'\\', c, '\0'};
                swim_json_append(json, buf);
            } else if (c == '\n') {
                swim_json_append(json, "\\n");
            } else if (c == '\r') {
                swim_json_append(json, "\\r");
            } else if (c == '\t') {
                swim_json_append(json, "\\t");
            } else {
                char buf[2] = {c, '\0'};
                swim_json_append(json, buf);
            }
        }
        swim_json_append(json, "\"");
    } else {
        swim_json_append(json, "null");
    }

    return true;
}

/**
 * Add integer value
 */
static inline bool swim_json_int(SwimJsonBuilder* json, const char* key, int64_t value) {
    if (!json) return false;

    swim_json_comma(json);

    char buf[64];
    if (key) {
        snprintf(buf, sizeof(buf), "\"%s\":%lld", key, (long long)value);
    } else {
        snprintf(buf, sizeof(buf), "%lld", (long long)value);
    }
    swim_json_append(json, buf);

    return true;
}

/**
 * Add double value
 */
static inline bool swim_json_double(SwimJsonBuilder* json, const char* key, double value, int precision) {
    if (!json) return false;

    swim_json_comma(json);

    char fmt[16];
    char buf[64];
    snprintf(fmt, sizeof(fmt), "%%.%df", precision > 0 ? precision : 6);

    if (key) {
        swim_json_append(json, "\"");
        swim_json_append(json, key);
        swim_json_append(json, "\":");
        snprintf(buf, sizeof(buf), fmt, value);
    } else {
        snprintf(buf, sizeof(buf), fmt, value);
    }
    swim_json_append(json, buf);

    return true;
}

/**
 * Add boolean value
 */
static inline bool swim_json_bool(SwimJsonBuilder* json, const char* key, bool value) {
    if (!json) return false;

    swim_json_comma(json);

    char buf[64];
    if (key) {
        snprintf(buf, sizeof(buf), "\"%s\":%s", key, value ? "true" : "false");
    } else {
        snprintf(buf, sizeof(buf), "%s", value ? "true" : "false");
    }
    swim_json_append(json, buf);

    return true;
}

/**
 * Add null value
 */
static inline bool swim_json_null(SwimJsonBuilder* json, const char* key) {
    if (!json) return false;

    swim_json_comma(json);

    char buf[64];
    if (key) {
        snprintf(buf, sizeof(buf), "\"%s\":null", key);
    } else {
        swim_json_append(json, "null");
        return true;
    }
    swim_json_append(json, buf);

    return true;
}

/**
 * Add ISO 8601 timestamp value
 */
static inline bool swim_json_timestamp(SwimJsonBuilder* json, const char* key, time_t timestamp) {
    if (!json) return false;

    if (timestamp == 0) {
        return swim_json_null(json, key);
    }

    struct tm* tm_info = gmtime(&timestamp);
    if (!tm_info) return swim_json_null(json, key);

    char buf[32];
    strftime(buf, sizeof(buf), "%Y-%m-%dT%H:%M:%SZ", tm_info);

    return swim_json_string(json, key, buf);
}

/**
 * Get final JSON string
 */
static inline const char* swim_json_get(const SwimJsonBuilder* json) {
    return json ? json->buffer : NULL;
}

/**
 * Get JSON string length
 */
static inline size_t swim_json_length(const SwimJsonBuilder* json) {
    return json ? json->length : 0;
}

/* ============================================================================
 * Convenience Functions for SWIM Types
 * ============================================================================ */

/**
 * Serialize position to JSON object (without braces)
 */
static inline void swim_json_position(SwimJsonBuilder* json, const SwimPosition* pos) {
    if (!json || !pos) return;

    swim_json_double(json, "latitude", pos->latitude, 6);
    swim_json_double(json, "longitude", pos->longitude, 6);
    swim_json_int(json, "altitude_ft", pos->altitude_ft);
    swim_json_int(json, "heading_deg", pos->heading_deg);
    swim_json_int(json, "groundspeed_kts", pos->groundspeed_kts);
    swim_json_int(json, "vertical_rate_fpm", pos->vertical_rate);

    if (pos->true_airspeed > 0) {
        swim_json_int(json, "true_airspeed_kts", pos->true_airspeed);
    }
    if (pos->mach_number > 0) {
        swim_json_double(json, "mach_number", pos->mach_number, 3);
    }
}

/**
 * Serialize track update to JSON object
 */
static inline void swim_json_track(SwimJsonBuilder* json, const SwimTrackUpdate* track) {
    if (!json || !track) return;

    swim_json_object_start(json);
    swim_json_string(json, "callsign", track->callsign);
    swim_json_double(json, "latitude", track->position.latitude, 6);
    swim_json_double(json, "longitude", track->position.longitude, 6);

    if (track->position.altitude_ft != 0) {
        swim_json_int(json, "altitude_ft", track->position.altitude_ft);
    }
    if (track->position.groundspeed_kts != 0) {
        swim_json_int(json, "ground_speed_kts", track->position.groundspeed_kts);
    }
    if (track->position.heading_deg != 0) {
        swim_json_int(json, "heading_deg", track->position.heading_deg);
    }
    if (track->position.vertical_rate != 0) {
        swim_json_int(json, "vertical_rate_fpm", track->position.vertical_rate);
    }
    if (track->squawk[0] != '\0') {
        swim_json_string(json, "squawk", track->squawk);
    }
    if (track->timestamp != 0) {
        swim_json_timestamp(json, "timestamp", track->timestamp);
    }

    swim_json_object_end(json);
}

/**
 * Serialize flight ingest to JSON object
 */
static inline void swim_json_flight_ingest(SwimJsonBuilder* json, const SwimFlightIngest* flight) {
    if (!json || !flight) return;

    swim_json_object_start(json);

    /* Required fields */
    swim_json_string(json, "callsign", flight->callsign);
    swim_json_string(json, "dept_icao", flight->dept_icao);
    swim_json_string(json, "dest_icao", flight->dest_icao);

    /* Optional identity */
    if (flight->cid > 0) {
        swim_json_int(json, "cid", flight->cid);
    }

    /* Aircraft */
    if (flight->aircraft_type[0] != '\0') {
        swim_json_string(json, "aircraft_type", flight->aircraft_type);
    }

    /* Route */
    if (flight->route[0] != '\0') {
        swim_json_string(json, "route", flight->route);
    }
    if (flight->cruise_altitude_ft > 0) {
        swim_json_int(json, "cruise_altitude", flight->cruise_altitude_ft);
    }
    if (flight->cruise_speed_kts > 0) {
        swim_json_int(json, "cruise_speed", flight->cruise_speed_kts);
    }

    /* Position */
    if (flight->has_position && SWIM_IS_VALID_POSITION(flight->position)) {
        swim_json_double(json, "latitude", flight->position.latitude, 6);
        swim_json_double(json, "longitude", flight->position.longitude, 6);
        swim_json_int(json, "altitude", flight->position.altitude_ft);
        swim_json_int(json, "groundspeed", flight->position.groundspeed_kts);
        swim_json_int(json, "heading", flight->position.heading_deg);
        swim_json_int(json, "vertical_rate_fpm", flight->position.vertical_rate);
    }

    /* OOOI times */
    if (flight->oooi.out_utc > 0) {
        swim_json_timestamp(json, "out_utc", flight->oooi.out_utc);
    }
    if (flight->oooi.off_utc > 0) {
        swim_json_timestamp(json, "off_utc", flight->oooi.off_utc);
    }
    if (flight->oooi.on_utc > 0) {
        swim_json_timestamp(json, "on_utc", flight->oooi.on_utc);
    }
    if (flight->oooi.in_utc > 0) {
        swim_json_timestamp(json, "in_utc", flight->oooi.in_utc);
    }

    /* ETA/ETD */
    if (flight->eta_utc > 0) {
        swim_json_timestamp(json, "eta_utc", flight->eta_utc);
    }
    if (flight->etd_utc > 0) {
        swim_json_timestamp(json, "etd_utc", flight->etd_utc);
    }

    /* Phase */
    if (flight->phase != SWIM_PHASE_UNKNOWN) {
        swim_json_string(json, "phase", swim_phase_to_string(flight->phase));
    }
    swim_json_bool(json, "is_active", flight->is_active);

    swim_json_object_end(json);
}

#ifdef __cplusplus
}
#endif

#endif /* SWIM_JSON_H */
