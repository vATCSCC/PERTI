/**
 * VATSWIM C/C++ SDK - Type Definitions
 *
 * Header-only library for flight simulator integration with VATSWIM API.
 *
 * @file types.h
 * @version 1.0.0
 * @license MIT
 */

#ifndef SWIM_TYPES_H
#define SWIM_TYPES_H

#include <stdint.h>
#include <stdbool.h>
#include <time.h>

#ifdef __cplusplus
extern "C" {
#endif

/* ============================================================================
 * Constants
 * ============================================================================ */

#define SWIM_MAX_CALLSIGN       16
#define SWIM_MAX_ICAO           4
#define SWIM_MAX_ROUTE          2048
#define SWIM_MAX_GUFI           64
#define SWIM_MAX_API_KEY        128
#define SWIM_MAX_ERROR_MSG      256
#define SWIM_MAX_BATCH_TRACKS   1000
#define SWIM_MAX_BATCH_ADL      500

/* Default API endpoint */
#define SWIM_DEFAULT_BASE_URL   "https://perti.vatcscc.org/api/swim/v1"

/* ============================================================================
 * Enumerations
 * ============================================================================ */

/**
 * Flight phase enumeration
 */
typedef enum {
    SWIM_PHASE_UNKNOWN = 0,
    SWIM_PHASE_PREFILE,
    SWIM_PHASE_PREFLIGHT,
    SWIM_PHASE_PUSHBACK,
    SWIM_PHASE_TAXI_OUT,
    SWIM_PHASE_TAKEOFF,
    SWIM_PHASE_DEPARTURE,
    SWIM_PHASE_ENROUTE,
    SWIM_PHASE_DESCENT,
    SWIM_PHASE_APPROACH,
    SWIM_PHASE_LANDING,
    SWIM_PHASE_TAXI_IN,
    SWIM_PHASE_ARRIVED
} SwimFlightPhase;

/**
 * Airport zone enumeration (for OOOI detection)
 */
typedef enum {
    SWIM_ZONE_UNKNOWN = 0,
    SWIM_ZONE_PARKING,
    SWIM_ZONE_TAXIWAY,
    SWIM_ZONE_HOLD,
    SWIM_ZONE_RUNWAY,
    SWIM_ZONE_AIRBORNE,
    SWIM_ZONE_APPROACH,
    SWIM_ZONE_FINAL
} SwimAirportZone;

/**
 * API response status codes
 */
typedef enum {
    SWIM_OK = 0,
    SWIM_ERROR_NETWORK = -1,
    SWIM_ERROR_AUTH = -2,
    SWIM_ERROR_RATE_LIMIT = -3,
    SWIM_ERROR_INVALID_DATA = -4,
    SWIM_ERROR_SERVER = -5,
    SWIM_ERROR_TIMEOUT = -6,
    SWIM_ERROR_BUFFER = -7
} SwimStatus;

/**
 * API key tier
 */
typedef enum {
    SWIM_TIER_PUBLIC = 0,
    SWIM_TIER_DEVELOPER,
    SWIM_TIER_PARTNER,
    SWIM_TIER_SYSTEM
} SwimApiTier;

/* ============================================================================
 * Data Structures
 * ============================================================================ */

/**
 * Geographic position
 */
typedef struct {
    double latitude;        /* Degrees (-90 to 90) */
    double longitude;       /* Degrees (-180 to 180) */
    int32_t altitude_ft;    /* Feet MSL */
    int16_t heading_deg;    /* Degrees (0-359) */
    int16_t groundspeed_kts;/* Knots */
    int16_t vertical_rate;  /* Feet per minute (positive = climb) */
    int16_t true_airspeed;  /* Knots */
    float mach_number;      /* Mach (e.g., 0.82) */
    bool on_ground;         /* True if on ground */
} SwimPosition;

/**
 * OOOI times (Out, Off, On, In)
 */
typedef struct {
    time_t out_utc;         /* Gate departure (pushback) */
    time_t off_utc;         /* Wheels up */
    time_t on_utc;          /* Wheels down */
    time_t in_utc;          /* Gate arrival */
} SwimOOOI;

/**
 * Flight plan data
 */
typedef struct {
    char callsign[SWIM_MAX_CALLSIGN];
    char dept_icao[SWIM_MAX_ICAO + 1];
    char dest_icao[SWIM_MAX_ICAO + 1];
    char alt_icao[SWIM_MAX_ICAO + 1];
    char aircraft_type[8];
    char route[SWIM_MAX_ROUTE];
    int32_t cruise_altitude_ft;
    int16_t cruise_speed_kts;
    int32_t cid;            /* VATSIM CID */
} SwimFlightPlan;

/**
 * Track update for position ingest
 */
typedef struct {
    char callsign[SWIM_MAX_CALLSIGN];
    SwimPosition position;
    time_t timestamp;       /* UTC timestamp */
    char squawk[5];         /* Transponder code */
} SwimTrackUpdate;

/**
 * ADL flight ingest data
 */
typedef struct {
    /* Identity */
    char callsign[SWIM_MAX_CALLSIGN];
    char dept_icao[SWIM_MAX_ICAO + 1];
    char dest_icao[SWIM_MAX_ICAO + 1];
    int32_t cid;

    /* Aircraft */
    char aircraft_type[8];

    /* Route */
    char route[SWIM_MAX_ROUTE];
    int32_t cruise_altitude_ft;
    int16_t cruise_speed_kts;

    /* Position (optional) */
    SwimPosition position;
    bool has_position;

    /* Times (optional) */
    SwimOOOI oooi;
    time_t eta_utc;
    time_t etd_utc;

    /* Phase */
    SwimFlightPhase phase;
    bool is_active;
} SwimFlightIngest;

/**
 * API response wrapper
 */
typedef struct {
    SwimStatus status;
    int http_code;
    int processed;
    int created;
    int updated;
    int errors;
    char error_message[SWIM_MAX_ERROR_MSG];
} SwimIngestResult;

/**
 * Client configuration
 */
typedef struct {
    char api_key[SWIM_MAX_API_KEY];
    char base_url[256];
    char source_id[32];
    SwimApiTier tier;
    int timeout_ms;
    bool verify_ssl;
} SwimClientConfig;

/**
 * OOOI detector state
 */
typedef struct {
    SwimAirportZone current_zone;
    SwimAirportZone previous_zone;
    SwimOOOI times;
    bool out_detected;
    bool off_detected;
    bool on_detected;
    bool in_detected;
    time_t last_update;
} SwimOOOIDetector;

/* ============================================================================
 * Helper Macros
 * ============================================================================ */

#define SWIM_IS_VALID_LAT(lat) ((lat) >= -90.0 && (lat) <= 90.0)
#define SWIM_IS_VALID_LON(lon) ((lon) >= -180.0 && (lon) <= 180.0)
#define SWIM_IS_VALID_POSITION(pos) (SWIM_IS_VALID_LAT((pos).latitude) && SWIM_IS_VALID_LON((pos).longitude))

/* Convert phase enum to string */
static inline const char* swim_phase_to_string(SwimFlightPhase phase) {
    switch (phase) {
        case SWIM_PHASE_PREFILE:    return "PREFILE";
        case SWIM_PHASE_PREFLIGHT:  return "PREFLIGHT";
        case SWIM_PHASE_PUSHBACK:   return "PUSHBACK";
        case SWIM_PHASE_TAXI_OUT:   return "TAXI_OUT";
        case SWIM_PHASE_TAKEOFF:    return "TAKEOFF";
        case SWIM_PHASE_DEPARTURE:  return "DEPARTURE";
        case SWIM_PHASE_ENROUTE:    return "ENROUTE";
        case SWIM_PHASE_DESCENT:    return "DESCENT";
        case SWIM_PHASE_APPROACH:   return "APPROACH";
        case SWIM_PHASE_LANDING:    return "LANDING";
        case SWIM_PHASE_TAXI_IN:    return "TAXI_IN";
        case SWIM_PHASE_ARRIVED:    return "ARRIVED";
        default:                     return "UNKNOWN";
    }
}

/* Convert zone enum to string */
static inline const char* swim_zone_to_string(SwimAirportZone zone) {
    switch (zone) {
        case SWIM_ZONE_PARKING:  return "PARKING";
        case SWIM_ZONE_TAXIWAY:  return "TAXIWAY";
        case SWIM_ZONE_HOLD:     return "HOLD";
        case SWIM_ZONE_RUNWAY:   return "RUNWAY";
        case SWIM_ZONE_AIRBORNE: return "AIRBORNE";
        case SWIM_ZONE_APPROACH: return "APPROACH";
        case SWIM_ZONE_FINAL:    return "FINAL";
        default:                  return "UNKNOWN";
    }
}

#ifdef __cplusplus
}
#endif

#endif /* SWIM_TYPES_H */
