/**
 * VATSWIM X-Plane DataRef Definitions
 *
 * DataRef paths and structures for X-Plane plugin.
 *
 * @package VATSWIM
 * @subpackage X-Plane Integration
 * @version 1.0.0
 */

#ifndef VATSWIM_XPLANE_DATAREFS_H
#define VATSWIM_XPLANE_DATAREFS_H

#include <XPLMDataAccess.h>

#ifdef __cplusplus
extern "C" {
#endif

/**
 * Position DataRefs
 */
typedef struct {
    XPLMDataRef latitude;           // sim/flightmodel/position/latitude
    XPLMDataRef longitude;          // sim/flightmodel/position/longitude
    XPLMDataRef elevation;          // sim/flightmodel/position/elevation (meters MSL)
    XPLMDataRef y_agl;              // sim/flightmodel/position/y_agl (meters AGL)
    XPLMDataRef indicated_alt;      // sim/cockpit2/gauges/indicators/altitude_ft_pilot
    XPLMDataRef groundspeed;        // sim/flightmodel/position/groundspeed (m/s)
    XPLMDataRef indicated_airspeed; // sim/flightmodel/position/indicated_airspeed (kts)
    XPLMDataRef true_airspeed;      // sim/flightmodel/position/true_airspeed (m/s)
    XPLMDataRef vh_ind;             // sim/flightmodel/position/vh_ind (vertical speed m/s)
    XPLMDataRef mag_psi;            // sim/flightmodel/position/mag_psi (magnetic heading)
    XPLMDataRef true_psi;           // sim/flightmodel/position/true_psi (true heading)
    XPLMDataRef pitch;              // sim/flightmodel/position/theta (pitch degrees)
    XPLMDataRef roll;               // sim/flightmodel/position/phi (roll/bank degrees)
} VATSWIMPositionRefs;

/**
 * Flight State DataRefs
 */
typedef struct {
    XPLMDataRef on_ground;          // sim/flightmodel/failures/onground_any
    XPLMDataRef parking_brake;      // sim/cockpit2/controls/parking_brake_ratio
    XPLMDataRef gear_deploy;        // sim/aircraft/parts/acf_gear_deploy
    XPLMDataRef paused;             // sim/time/paused
    XPLMDataRef sim_speed;          // sim/time/sim_speed
    XPLMDataRef replay_mode;        // sim/operation/prefs/replay_mode
} VATSWIMStateRefs;

/**
 * Aircraft Info DataRefs
 */
typedef struct {
    XPLMDataRef icao_type;          // sim/aircraft/view/acf_ICAO
    XPLMDataRef tailnum;            // sim/aircraft/view/acf_tailnum
    XPLMDataRef description;        // sim/aircraft/view/acf_descrip
    XPLMDataRef num_engines;        // sim/aircraft/engine/acf_num_engines
    XPLMDataRef engine_type;        // sim/aircraft/prop/acf_en_type
    XPLMDataRef empty_weight;       // sim/aircraft/weight/acf_m_empty (kg)
    XPLMDataRef max_weight;         // sim/aircraft/weight/acf_m_max (kg)
    XPLMDataRef total_weight;       // sim/flightmodel/weight/m_total (kg)
    XPLMDataRef fuel_total;         // sim/flightmodel/weight/m_fuel_total (kg)
} VATSWIMAircraftRefs;

/**
 * Engine DataRefs
 */
typedef struct {
    XPLMDataRef engine_running;     // sim/flightmodel/engine/ENGN_running (array)
    XPLMDataRef n1;                 // sim/cockpit2/engine/indicators/N1_percent (array)
    XPLMDataRef throttle;           // sim/cockpit2/engine/actuators/throttle_ratio (array)
    XPLMDataRef fuel_flow;          // sim/cockpit2/engine/indicators/fuel_flow_kg_sec (array)
} VATSWIMEngineRefs;

/**
 * Autopilot DataRefs
 */
typedef struct {
    XPLMDataRef ap_master;          // sim/cockpit/autopilot/autopilot_mode
    XPLMDataRef ap_altitude;        // sim/cockpit/autopilot/altitude
    XPLMDataRef ap_heading;         // sim/cockpit/autopilot/heading
    XPLMDataRef ap_airspeed;        // sim/cockpit/autopilot/airspeed
    XPLMDataRef ap_vs;              // sim/cockpit/autopilot/vertical_velocity
} VATSWIMAutopilotRefs;

/**
 * All DataRefs container
 */
typedef struct {
    VATSWIMPositionRefs position;
    VATSWIMStateRefs state;
    VATSWIMAircraftRefs aircraft;
    VATSWIMEngineRefs engine;
    VATSWIMAutopilotRefs autopilot;
    int initialized;
} VATSWIMDataRefs;

/**
 * Initialize all DataRefs
 */
static inline int vatswim_init_datarefs(VATSWIMDataRefs* refs) {
    if (!refs) return 0;

    // Position DataRefs
    refs->position.latitude = XPLMFindDataRef("sim/flightmodel/position/latitude");
    refs->position.longitude = XPLMFindDataRef("sim/flightmodel/position/longitude");
    refs->position.elevation = XPLMFindDataRef("sim/flightmodel/position/elevation");
    refs->position.y_agl = XPLMFindDataRef("sim/flightmodel/position/y_agl");
    refs->position.indicated_alt = XPLMFindDataRef("sim/cockpit2/gauges/indicators/altitude_ft_pilot");
    refs->position.groundspeed = XPLMFindDataRef("sim/flightmodel/position/groundspeed");
    refs->position.indicated_airspeed = XPLMFindDataRef("sim/flightmodel/position/indicated_airspeed");
    refs->position.true_airspeed = XPLMFindDataRef("sim/flightmodel/position/true_airspeed");
    refs->position.vh_ind = XPLMFindDataRef("sim/flightmodel/position/vh_ind");
    refs->position.mag_psi = XPLMFindDataRef("sim/flightmodel/position/mag_psi");
    refs->position.true_psi = XPLMFindDataRef("sim/flightmodel/position/true_psi");
    refs->position.pitch = XPLMFindDataRef("sim/flightmodel/position/theta");
    refs->position.roll = XPLMFindDataRef("sim/flightmodel/position/phi");

    // State DataRefs
    refs->state.on_ground = XPLMFindDataRef("sim/flightmodel/failures/onground_any");
    refs->state.parking_brake = XPLMFindDataRef("sim/cockpit2/controls/parking_brake_ratio");
    refs->state.gear_deploy = XPLMFindDataRef("sim/aircraft/parts/acf_gear_deploy");
    refs->state.paused = XPLMFindDataRef("sim/time/paused");
    refs->state.sim_speed = XPLMFindDataRef("sim/time/sim_speed");
    refs->state.replay_mode = XPLMFindDataRef("sim/operation/prefs/replay_mode");

    // Aircraft DataRefs
    refs->aircraft.icao_type = XPLMFindDataRef("sim/aircraft/view/acf_ICAO");
    refs->aircraft.tailnum = XPLMFindDataRef("sim/aircraft/view/acf_tailnum");
    refs->aircraft.description = XPLMFindDataRef("sim/aircraft/view/acf_descrip");
    refs->aircraft.num_engines = XPLMFindDataRef("sim/aircraft/engine/acf_num_engines");
    refs->aircraft.engine_type = XPLMFindDataRef("sim/aircraft/prop/acf_en_type");
    refs->aircraft.empty_weight = XPLMFindDataRef("sim/aircraft/weight/acf_m_empty");
    refs->aircraft.max_weight = XPLMFindDataRef("sim/aircraft/weight/acf_m_max");
    refs->aircraft.total_weight = XPLMFindDataRef("sim/flightmodel/weight/m_total");
    refs->aircraft.fuel_total = XPLMFindDataRef("sim/flightmodel/weight/m_fuel_total");

    // Engine DataRefs
    refs->engine.engine_running = XPLMFindDataRef("sim/flightmodel/engine/ENGN_running");
    refs->engine.n1 = XPLMFindDataRef("sim/cockpit2/engine/indicators/N1_percent");
    refs->engine.throttle = XPLMFindDataRef("sim/cockpit2/engine/actuators/throttle_ratio");
    refs->engine.fuel_flow = XPLMFindDataRef("sim/cockpit2/engine/indicators/fuel_flow_kg_sec");

    // Autopilot DataRefs
    refs->autopilot.ap_master = XPLMFindDataRef("sim/cockpit/autopilot/autopilot_mode");
    refs->autopilot.ap_altitude = XPLMFindDataRef("sim/cockpit/autopilot/altitude");
    refs->autopilot.ap_heading = XPLMFindDataRef("sim/cockpit/autopilot/heading");
    refs->autopilot.ap_airspeed = XPLMFindDataRef("sim/cockpit/autopilot/airspeed");
    refs->autopilot.ap_vs = XPLMFindDataRef("sim/cockpit/autopilot/vertical_velocity");

    // Verify critical DataRefs were found
    if (!refs->position.latitude || !refs->position.longitude || !refs->position.elevation) {
        return 0;
    }

    refs->initialized = 1;
    return 1;
}

/**
 * Read current position data
 */
typedef struct {
    double latitude;
    double longitude;
    float altitude_ft;
    float altitude_agl_ft;
    float indicated_alt_ft;
    float groundspeed_kts;
    float indicated_airspeed_kts;
    float true_airspeed_kts;
    float vertical_speed_fpm;
    float heading_mag;
    float heading_true;
    float pitch;
    float roll;
    int on_ground;
    float parking_brake;
    int paused;
    int replay;
    int any_engine_running;
} VATSWIMPositionData;

static inline void vatswim_read_position(VATSWIMDataRefs* refs, VATSWIMPositionData* data) {
    if (!refs || !refs->initialized || !data) return;

    // Position (convert meters to feet where needed)
    data->latitude = XPLMGetDatad(refs->position.latitude);
    data->longitude = XPLMGetDatad(refs->position.longitude);
    data->altitude_ft = (float)(XPLMGetDatad(refs->position.elevation) * 3.28084);  // m to ft
    data->altitude_agl_ft = XPLMGetDataf(refs->position.y_agl) * 3.28084f;
    data->indicated_alt_ft = XPLMGetDataf(refs->position.indicated_alt);

    // Speed (convert m/s to knots where needed)
    data->groundspeed_kts = XPLMGetDataf(refs->position.groundspeed) * 1.94384f;  // m/s to kts
    data->indicated_airspeed_kts = XPLMGetDataf(refs->position.indicated_airspeed);
    data->true_airspeed_kts = XPLMGetDataf(refs->position.true_airspeed) * 1.94384f;

    // Vertical speed (convert m/s to fpm)
    data->vertical_speed_fpm = XPLMGetDataf(refs->position.vh_ind) * 196.85f;

    // Attitude
    data->heading_mag = XPLMGetDataf(refs->position.mag_psi);
    data->heading_true = XPLMGetDataf(refs->position.true_psi);
    data->pitch = XPLMGetDataf(refs->position.pitch);
    data->roll = XPLMGetDataf(refs->position.roll);

    // State
    data->on_ground = XPLMGetDatai(refs->state.on_ground);
    data->parking_brake = XPLMGetDataf(refs->state.parking_brake);
    data->paused = XPLMGetDatai(refs->state.paused);
    data->replay = XPLMGetDatai(refs->state.replay_mode);

    // Check if any engine is running
    int engines[8] = {0};
    int num_engines = XPLMGetDatavi(refs->engine.engine_running, engines, 0, 8);
    data->any_engine_running = 0;
    for (int i = 0; i < num_engines; i++) {
        if (engines[i]) {
            data->any_engine_running = 1;
            break;
        }
    }
}

/**
 * Read aircraft info
 */
typedef struct {
    char icao_type[8];
    char tailnum[16];
    char description[64];
    int num_engines;
    int engine_type;
    float empty_weight_lbs;
    float max_weight_lbs;
    float total_weight_lbs;
    float fuel_lbs;
} VATSWIMAircraftData;

static inline void vatswim_read_aircraft(VATSWIMDataRefs* refs, VATSWIMAircraftData* data) {
    if (!refs || !refs->initialized || !data) return;

    // Read strings
    XPLMGetDatab(refs->aircraft.icao_type, data->icao_type, 0, sizeof(data->icao_type) - 1);
    XPLMGetDatab(refs->aircraft.tailnum, data->tailnum, 0, sizeof(data->tailnum) - 1);
    XPLMGetDatab(refs->aircraft.description, data->description, 0, sizeof(data->description) - 1);

    // Read numeric values (convert kg to lbs)
    data->num_engines = XPLMGetDatai(refs->aircraft.num_engines);
    data->engine_type = XPLMGetDatai(refs->aircraft.engine_type);
    data->empty_weight_lbs = XPLMGetDataf(refs->aircraft.empty_weight) * 2.20462f;
    data->max_weight_lbs = XPLMGetDataf(refs->aircraft.max_weight) * 2.20462f;
    data->total_weight_lbs = XPLMGetDataf(refs->aircraft.total_weight) * 2.20462f;
    data->fuel_lbs = XPLMGetDataf(refs->aircraft.fuel_total) * 2.20462f;
}

#ifdef __cplusplus
}
#endif

#endif /* VATSWIM_XPLANE_DATAREFS_H */
