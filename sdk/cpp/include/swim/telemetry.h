/**
 * VATSWIM C/C++ SDK - Telemetry & OOOI Detection
 *
 * State machine for detecting OOOI (Out, Off, On, In) times
 * from flight simulator telemetry data.
 *
 * @file telemetry.h
 * @version 1.0.0
 * @license MIT
 */

#ifndef SWIM_TELEMETRY_H
#define SWIM_TELEMETRY_H

#include "types.h"
#include <string.h>
#include <math.h>

#ifdef __cplusplus
extern "C" {
#endif

/* ============================================================================
 * OOOI Detection Thresholds
 * ============================================================================ */

/* Ground speed thresholds (knots) */
#define SWIM_OOOI_PARKED_MAX_GS         5.0     /* Max GS when parked */
#define SWIM_OOOI_TAXI_MIN_GS           3.0     /* Min GS for taxi */
#define SWIM_OOOI_TAXI_MAX_GS           30.0    /* Max GS for taxi */
#define SWIM_OOOI_TAKEOFF_MIN_GS        60.0    /* Min GS for takeoff roll */

/* Altitude thresholds (feet AGL) */
#define SWIM_OOOI_AIRBORNE_MIN_AGL      50.0    /* Min AGL to be airborne */
#define SWIM_OOOI_APPROACH_MAX_AGL      3000.0  /* Max AGL for approach */
#define SWIM_OOOI_FINAL_MAX_AGL         1000.0  /* Max AGL for final */

/* Vertical rate thresholds (fpm) */
#define SWIM_OOOI_CLIMB_MIN_VS          300.0   /* Min VS for climb detection */
#define SWIM_OOOI_DESCENT_MAX_VS        -300.0  /* Max VS for descent detection */

/* Time thresholds (seconds) */
#define SWIM_OOOI_PARKED_MIN_TIME       30      /* Min time parked to confirm IN */
#define SWIM_OOOI_DEBOUNCE_TIME         5       /* Debounce for state changes */

/* ============================================================================
 * OOOI Detector Functions
 * ============================================================================ */

/**
 * Initialize OOOI detector state
 *
 * @param detector Pointer to detector state
 */
static inline void swim_oooi_init(SwimOOOIDetector* detector) {
    if (!detector) return;
    memset(detector, 0, sizeof(SwimOOOIDetector));
    detector->current_zone = SWIM_ZONE_UNKNOWN;
    detector->previous_zone = SWIM_ZONE_UNKNOWN;
}

/**
 * Detect current airport zone from telemetry
 *
 * @param gs_kts Ground speed in knots
 * @param on_ground True if aircraft is on ground
 * @param agl_ft Altitude above ground level in feet
 * @param vs_fpm Vertical speed in feet per minute
 * @param parking_brake True if parking brake is set
 * @return Current airport zone
 */
static inline SwimAirportZone swim_detect_zone(
    float gs_kts,
    bool on_ground,
    float agl_ft,
    float vs_fpm,
    bool parking_brake
) {
    /* Airborne detection */
    if (!on_ground && agl_ft > SWIM_OOOI_AIRBORNE_MIN_AGL) {
        if (agl_ft <= SWIM_OOOI_FINAL_MAX_AGL && vs_fpm < SWIM_OOOI_DESCENT_MAX_VS) {
            return SWIM_ZONE_FINAL;
        }
        if (agl_ft <= SWIM_OOOI_APPROACH_MAX_AGL && vs_fpm < 0) {
            return SWIM_ZONE_APPROACH;
        }
        return SWIM_ZONE_AIRBORNE;
    }

    /* Ground detection */
    if (on_ground) {
        /* Parked */
        if (gs_kts < SWIM_OOOI_PARKED_MAX_GS && parking_brake) {
            return SWIM_ZONE_PARKING;
        }

        /* Takeoff roll or landing rollout */
        if (gs_kts >= SWIM_OOOI_TAKEOFF_MIN_GS) {
            return SWIM_ZONE_RUNWAY;
        }

        /* Taxiing */
        if (gs_kts >= SWIM_OOOI_TAXI_MIN_GS) {
            return SWIM_ZONE_TAXIWAY;
        }

        /* Stopped but not at parking (hold short) */
        if (!parking_brake && gs_kts < SWIM_OOOI_PARKED_MAX_GS) {
            return SWIM_ZONE_HOLD;
        }

        return SWIM_ZONE_PARKING;
    }

    return SWIM_ZONE_UNKNOWN;
}

/**
 * Update OOOI detector with new telemetry data
 *
 * @param detector Pointer to detector state
 * @param gs_kts Ground speed in knots
 * @param on_ground True if aircraft is on ground
 * @param agl_ft Altitude above ground level in feet
 * @param vs_fpm Vertical speed in feet per minute
 * @param parking_brake True if parking brake is set
 * @return True if any OOOI event was detected
 */
static inline bool swim_oooi_update(
    SwimOOOIDetector* detector,
    float gs_kts,
    bool on_ground,
    float agl_ft,
    float vs_fpm,
    bool parking_brake
) {
    if (!detector) return false;

    bool event_detected = false;
    time_t now = time(NULL);

    /* Detect current zone */
    SwimAirportZone new_zone = swim_detect_zone(gs_kts, on_ground, agl_ft, vs_fpm, parking_brake);

    /* Check for zone transition */
    if (new_zone != detector->current_zone) {
        detector->previous_zone = detector->current_zone;
        detector->current_zone = new_zone;

        /* OUT detection: Parking -> Taxiway (pushback complete) */
        if (!detector->out_detected &&
            detector->previous_zone == SWIM_ZONE_PARKING &&
            (new_zone == SWIM_ZONE_TAXIWAY || new_zone == SWIM_ZONE_HOLD)) {
            detector->times.out_utc = now;
            detector->out_detected = true;
            event_detected = true;
        }

        /* OFF detection: Ground -> Airborne (wheels up) */
        if (!detector->off_detected &&
            detector->out_detected &&
            (detector->previous_zone == SWIM_ZONE_RUNWAY ||
             detector->previous_zone == SWIM_ZONE_TAXIWAY) &&
            new_zone == SWIM_ZONE_AIRBORNE) {
            detector->times.off_utc = now;
            detector->off_detected = true;
            event_detected = true;
        }

        /* ON detection: Airborne/Final -> Runway (wheels down) */
        if (!detector->on_detected &&
            detector->off_detected &&
            (detector->previous_zone == SWIM_ZONE_AIRBORNE ||
             detector->previous_zone == SWIM_ZONE_APPROACH ||
             detector->previous_zone == SWIM_ZONE_FINAL) &&
            (new_zone == SWIM_ZONE_RUNWAY || new_zone == SWIM_ZONE_TAXIWAY)) {
            detector->times.on_utc = now;
            detector->on_detected = true;
            event_detected = true;
        }

        /* IN detection: Taxiway -> Parking (arrived at gate) */
        if (!detector->in_detected &&
            detector->on_detected &&
            (detector->previous_zone == SWIM_ZONE_TAXIWAY ||
             detector->previous_zone == SWIM_ZONE_HOLD) &&
            new_zone == SWIM_ZONE_PARKING) {
            detector->times.in_utc = now;
            detector->in_detected = true;
            event_detected = true;
        }
    }

    detector->last_update = now;
    return event_detected;
}

/**
 * Reset OOOI detector for a new flight
 *
 * @param detector Pointer to detector state
 */
static inline void swim_oooi_reset(SwimOOOIDetector* detector) {
    swim_oooi_init(detector);
}

/**
 * Check if flight is complete (all OOOI times captured)
 *
 * @param detector Pointer to detector state
 * @return True if all OOOI times are captured
 */
static inline bool swim_oooi_is_complete(const SwimOOOIDetector* detector) {
    if (!detector) return false;
    return detector->out_detected &&
           detector->off_detected &&
           detector->on_detected &&
           detector->in_detected;
}

/**
 * Get OOOI times from detector
 *
 * @param detector Pointer to detector state
 * @param oooi Pointer to OOOI structure to fill
 */
static inline void swim_oooi_get_times(const SwimOOOIDetector* detector, SwimOOOI* oooi) {
    if (!detector || !oooi) return;
    memcpy(oooi, &detector->times, sizeof(SwimOOOI));
}

/* ============================================================================
 * Flight Phase Detection
 * ============================================================================ */

/**
 * Detect flight phase from telemetry and OOOI state
 *
 * @param detector OOOI detector state (can be NULL)
 * @param gs_kts Ground speed in knots
 * @param on_ground True if on ground
 * @param agl_ft Altitude AGL in feet
 * @param vs_fpm Vertical speed in feet per minute
 * @param dist_to_dest_nm Distance to destination in nautical miles
 * @return Detected flight phase
 */
static inline SwimFlightPhase swim_detect_phase(
    const SwimOOOIDetector* detector,
    float gs_kts,
    bool on_ground,
    float agl_ft,
    float vs_fpm,
    float dist_to_dest_nm
) {
    /* Check OOOI state if available */
    if (detector) {
        if (detector->in_detected) return SWIM_PHASE_ARRIVED;
        if (detector->on_detected) return SWIM_PHASE_TAXI_IN;
    }

    /* Ground phases */
    if (on_ground) {
        if (gs_kts < 5.0) {
            if (detector && detector->on_detected) return SWIM_PHASE_TAXI_IN;
            if (detector && detector->out_detected) return SWIM_PHASE_TAXI_OUT;
            return SWIM_PHASE_PREFLIGHT;
        }

        if (gs_kts >= 60.0) {
            /* High speed on ground - takeoff or landing roll */
            if (detector && detector->off_detected && !detector->on_detected) {
                return SWIM_PHASE_LANDING;
            }
            return SWIM_PHASE_TAKEOFF;
        }

        /* Taxiing */
        if (detector && detector->on_detected) return SWIM_PHASE_TAXI_IN;
        if (detector && detector->out_detected) return SWIM_PHASE_TAXI_OUT;
        return SWIM_PHASE_PUSHBACK;
    }

    /* Airborne phases */
    if (agl_ft < 3000 && vs_fpm > SWIM_OOOI_CLIMB_MIN_VS) {
        return SWIM_PHASE_DEPARTURE;
    }

    if (agl_ft < 3000 && vs_fpm < SWIM_OOOI_DESCENT_MAX_VS) {
        return SWIM_PHASE_APPROACH;
    }

    if (vs_fpm < SWIM_OOOI_DESCENT_MAX_VS || dist_to_dest_nm < 100) {
        return SWIM_PHASE_DESCENT;
    }

    return SWIM_PHASE_ENROUTE;
}

/* ============================================================================
 * Position Rate Limiting
 * ============================================================================ */

typedef struct {
    time_t last_send_time;
    double last_lat;
    double last_lon;
    int32_t last_alt;
    int min_interval_sec;       /* Minimum seconds between updates */
    double min_distance_nm;     /* Minimum distance change to trigger update */
    int32_t min_alt_change_ft;  /* Minimum altitude change to trigger update */
} SwimPositionThrottle;

/**
 * Initialize position throttle
 *
 * @param throttle Pointer to throttle state
 * @param interval_sec Minimum seconds between updates (default: 5)
 * @param distance_nm Minimum distance change in nm (default: 0.5)
 * @param alt_change_ft Minimum altitude change in feet (default: 100)
 */
static inline void swim_throttle_init(
    SwimPositionThrottle* throttle,
    int interval_sec,
    double distance_nm,
    int32_t alt_change_ft
) {
    if (!throttle) return;
    memset(throttle, 0, sizeof(SwimPositionThrottle));
    throttle->min_interval_sec = interval_sec > 0 ? interval_sec : 5;
    throttle->min_distance_nm = distance_nm > 0 ? distance_nm : 0.5;
    throttle->min_alt_change_ft = alt_change_ft > 0 ? alt_change_ft : 100;
}

/**
 * Check if position should be sent based on throttling rules
 *
 * @param throttle Pointer to throttle state
 * @param pos Current position
 * @return True if position should be sent
 */
static inline bool swim_throttle_should_send(
    SwimPositionThrottle* throttle,
    const SwimPosition* pos
) {
    if (!throttle || !pos) return false;

    time_t now = time(NULL);

    /* Always send if never sent before */
    if (throttle->last_send_time == 0) {
        return true;
    }

    /* Check time interval */
    if (now - throttle->last_send_time < throttle->min_interval_sec) {
        return false;
    }

    /* Check altitude change */
    int32_t alt_diff = abs(pos->altitude_ft - throttle->last_alt);
    if (alt_diff >= throttle->min_alt_change_ft) {
        return true;
    }

    /* Check distance change (simple approximation) */
    double lat_diff = pos->latitude - throttle->last_lat;
    double lon_diff = pos->longitude - throttle->last_lon;
    double dist_deg = sqrt(lat_diff * lat_diff + lon_diff * lon_diff);
    double dist_nm = dist_deg * 60.0; /* Rough conversion */

    if (dist_nm >= throttle->min_distance_nm) {
        return true;
    }

    /* Time exceeded, send anyway */
    if (now - throttle->last_send_time >= throttle->min_interval_sec * 3) {
        return true;
    }

    return false;
}

/**
 * Mark position as sent
 *
 * @param throttle Pointer to throttle state
 * @param pos Position that was sent
 */
static inline void swim_throttle_mark_sent(
    SwimPositionThrottle* throttle,
    const SwimPosition* pos
) {
    if (!throttle || !pos) return;
    throttle->last_send_time = time(NULL);
    throttle->last_lat = pos->latitude;
    throttle->last_lon = pos->longitude;
    throttle->last_alt = pos->altitude_ft;
}

#ifdef __cplusplus
}
#endif

#endif /* SWIM_TELEMETRY_H */
