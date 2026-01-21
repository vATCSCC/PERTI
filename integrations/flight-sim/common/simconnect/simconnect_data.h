/**
 * VATSWIM SimConnect Data Definitions
 *
 * Common data request definitions for MSFS and P3D SimConnect plugins.
 * Defines the data structures and request IDs for subscribing to simulator data.
 *
 * @package VATSWIM
 * @subpackage Flight Simulator Integrations
 * @version 1.0.0
 */

#ifndef VATSWIM_SIMCONNECT_DATA_H
#define VATSWIM_SIMCONNECT_DATA_H

#include <windows.h>
#include <SimConnect.h>

#ifdef __cplusplus
extern "C" {
#endif

/**
 * Data definition IDs for SimConnect subscriptions
 */
enum VATSWIM_DATA_DEFINE_ID {
    VATSWIM_DEFINE_POSITION = 0,
    VATSWIM_DEFINE_AIRCRAFT_INFO,
    VATSWIM_DEFINE_FLIGHT_STATE,
    VATSWIM_DEFINE_AUTOPILOT,
    VATSWIM_DEFINE_ENGINES
};

/**
 * Request IDs for SimConnect data requests
 */
enum VATSWIM_DATA_REQUEST_ID {
    VATSWIM_REQUEST_POSITION = 0,
    VATSWIM_REQUEST_AIRCRAFT_INFO,
    VATSWIM_REQUEST_FLIGHT_STATE,
    VATSWIM_REQUEST_AUTOPILOT,
    VATSWIM_REQUEST_ENGINES
};

/**
 * Event IDs for SimConnect events
 */
enum VATSWIM_EVENT_ID {
    VATSWIM_EVENT_SIM_START = 0,
    VATSWIM_EVENT_SIM_STOP,
    VATSWIM_EVENT_PAUSE,
    VATSWIM_EVENT_AIRCRAFT_LOADED,
    VATSWIM_EVENT_FLIGHT_LOADED,
    VATSWIM_EVENT_POSITION_CHANGED
};

/**
 * Position data structure
 * Updated every second for track reporting
 */
#pragma pack(push, 1)
typedef struct {
    double latitude;              // PLANE LATITUDE (degrees)
    double longitude;             // PLANE LONGITUDE (degrees)
    double altitude_msl;          // PLANE ALTITUDE (feet MSL)
    double altitude_agl;          // PLANE ALT ABOVE GROUND (feet AGL)
    double indicated_altitude;    // INDICATED ALTITUDE (feet)
    double heading_true;          // PLANE HEADING DEGREES TRUE
    double heading_mag;           // PLANE HEADING DEGREES MAGNETIC
    double groundspeed;           // GROUND VELOCITY (knots)
    double airspeed_indicated;    // AIRSPEED INDICATED (knots)
    double airspeed_true;         // AIRSPEED TRUE (knots)
    double vertical_speed;        // VERTICAL SPEED (feet/minute)
    double pitch;                 // PLANE PITCH DEGREES
    double bank;                  // PLANE BANK DEGREES
    DWORD on_ground;              // SIM ON GROUND (bool)
    double ground_altitude;       // GROUND ALTITUDE (feet)
} VATSWIM_PositionData;
#pragma pack(pop)

/**
 * Aircraft information structure
 * Queried once at flight start
 */
#pragma pack(push, 1)
typedef struct {
    char title[256];              // TITLE
    char atc_type[32];            // ATC TYPE (ICAO type code)
    char atc_model[32];           // ATC MODEL
    char atc_id[32];              // ATC ID (registration/tail number)
    char atc_airline[64];         // ATC AIRLINE
    char atc_flight_number[16];   // ATC FLIGHT NUMBER
    DWORD num_engines;            // NUMBER OF ENGINES
    DWORD engine_type;            // ENGINE TYPE
    double empty_weight;          // EMPTY WEIGHT (lbs)
    double max_gross_weight;      // MAX GROSS WEIGHT (lbs)
    double total_weight;          // TOTAL WEIGHT (lbs)
    double wing_span;             // WING SPAN (feet)
} VATSWIM_AircraftInfo;
#pragma pack(pop)

/**
 * Flight state structure
 * Used for OOOI detection
 */
#pragma pack(push, 1)
typedef struct {
    DWORD on_ground;              // SIM ON GROUND
    DWORD parking_brake;          // BRAKE PARKING POSITION (0-32767)
    double groundspeed;           // GROUND VELOCITY (knots)
    double vertical_speed;        // VERTICAL SPEED (feet/minute)
    double altitude_agl;          // PLANE ALT ABOVE GROUND (feet)
    DWORD gear_handle_position;   // GEAR HANDLE POSITION
    DWORD pushback_state;         // PUSHBACK STATE
    double fuel_total;            // FUEL TOTAL QUANTITY (gallons)
    DWORD engine_running;         // GENERAL ENG COMBUSTION:1
    DWORD is_slew_active;         // IS SLEW ACTIVE
    DWORD sim_disabled;           // SIM DISABLED
} VATSWIM_FlightState;
#pragma pack(pop)

/**
 * Autopilot data structure
 */
#pragma pack(push, 1)
typedef struct {
    DWORD master;                 // AUTOPILOT MASTER
    DWORD altitude_lock;          // AUTOPILOT ALTITUDE LOCK
    double altitude_var;          // AUTOPILOT ALTITUDE LOCK VAR (feet)
    DWORD heading_lock;           // AUTOPILOT HEADING LOCK
    double heading_var;           // AUTOPILOT HEADING LOCK DIR (degrees)
    DWORD airspeed_hold;          // AUTOPILOT AIRSPEED HOLD
    double airspeed_var;          // AUTOPILOT AIRSPEED HOLD VAR (knots)
    DWORD mach_hold;              // AUTOPILOT MACH HOLD
    double mach_var;              // AUTOPILOT MACH HOLD VAR
    DWORD vertical_hold;          // AUTOPILOT VERTICAL HOLD
    double vertical_var;          // AUTOPILOT VERTICAL HOLD VAR (feet/min)
    DWORD approach_hold;          // AUTOPILOT APPROACH HOLD
    DWORD nav1_lock;              // AUTOPILOT NAV1 LOCK
    DWORD glideslope_hold;        // AUTOPILOT GLIDESLOPE HOLD
} VATSWIM_AutopilotData;
#pragma pack(pop)

/**
 * Engine data structure
 */
#pragma pack(push, 1)
typedef struct {
    double n1_1;                  // TURB ENG N1:1
    double n1_2;                  // TURB ENG N1:2
    double n1_3;                  // TURB ENG N1:3
    double n1_4;                  // TURB ENG N1:4
    DWORD combustion_1;           // GENERAL ENG COMBUSTION:1
    DWORD combustion_2;           // GENERAL ENG COMBUSTION:2
    DWORD combustion_3;           // GENERAL ENG COMBUSTION:3
    DWORD combustion_4;           // GENERAL ENG COMBUSTION:4
    double throttle_1;            // GENERAL ENG THROTTLE LEVER POSITION:1
    double throttle_2;            // GENERAL ENG THROTTLE LEVER POSITION:2
    double fuel_flow_1;           // ENG FUEL FLOW GPH:1
    double fuel_flow_2;           // ENG FUEL FLOW GPH:2
} VATSWIM_EngineData;
#pragma pack(pop)

/**
 * Initialize SimConnect data definitions
 *
 * @param hSimConnect SimConnect handle
 * @return True if successful
 */
static inline BOOL vatswim_init_data_definitions(HANDLE hSimConnect) {
    HRESULT hr;

    // Position data definition
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_POSITION,
        "PLANE LATITUDE", "degrees", SIMCONNECT_DATATYPE_FLOAT64);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_POSITION,
        "PLANE LONGITUDE", "degrees", SIMCONNECT_DATATYPE_FLOAT64);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_POSITION,
        "PLANE ALTITUDE", "feet", SIMCONNECT_DATATYPE_FLOAT64);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_POSITION,
        "PLANE ALT ABOVE GROUND", "feet", SIMCONNECT_DATATYPE_FLOAT64);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_POSITION,
        "INDICATED ALTITUDE", "feet", SIMCONNECT_DATATYPE_FLOAT64);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_POSITION,
        "PLANE HEADING DEGREES TRUE", "degrees", SIMCONNECT_DATATYPE_FLOAT64);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_POSITION,
        "PLANE HEADING DEGREES MAGNETIC", "degrees", SIMCONNECT_DATATYPE_FLOAT64);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_POSITION,
        "GROUND VELOCITY", "knots", SIMCONNECT_DATATYPE_FLOAT64);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_POSITION,
        "AIRSPEED INDICATED", "knots", SIMCONNECT_DATATYPE_FLOAT64);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_POSITION,
        "AIRSPEED TRUE", "knots", SIMCONNECT_DATATYPE_FLOAT64);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_POSITION,
        "VERTICAL SPEED", "feet per minute", SIMCONNECT_DATATYPE_FLOAT64);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_POSITION,
        "PLANE PITCH DEGREES", "degrees", SIMCONNECT_DATATYPE_FLOAT64);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_POSITION,
        "PLANE BANK DEGREES", "degrees", SIMCONNECT_DATATYPE_FLOAT64);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_POSITION,
        "SIM ON GROUND", "bool", SIMCONNECT_DATATYPE_INT32);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_POSITION,
        "GROUND ALTITUDE", "feet", SIMCONNECT_DATATYPE_FLOAT64);

    // Aircraft info definition
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_AIRCRAFT_INFO,
        "TITLE", NULL, SIMCONNECT_DATATYPE_STRING256);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_AIRCRAFT_INFO,
        "ATC TYPE", NULL, SIMCONNECT_DATATYPE_STRING32);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_AIRCRAFT_INFO,
        "ATC MODEL", NULL, SIMCONNECT_DATATYPE_STRING32);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_AIRCRAFT_INFO,
        "ATC ID", NULL, SIMCONNECT_DATATYPE_STRING32);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_AIRCRAFT_INFO,
        "ATC AIRLINE", NULL, SIMCONNECT_DATATYPE_STRING64);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_AIRCRAFT_INFO,
        "ATC FLIGHT NUMBER", NULL, SIMCONNECT_DATATYPE_STRING16);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_AIRCRAFT_INFO,
        "NUMBER OF ENGINES", "number", SIMCONNECT_DATATYPE_INT32);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_AIRCRAFT_INFO,
        "ENGINE TYPE", "number", SIMCONNECT_DATATYPE_INT32);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_AIRCRAFT_INFO,
        "EMPTY WEIGHT", "pounds", SIMCONNECT_DATATYPE_FLOAT64);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_AIRCRAFT_INFO,
        "MAX GROSS WEIGHT", "pounds", SIMCONNECT_DATATYPE_FLOAT64);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_AIRCRAFT_INFO,
        "TOTAL WEIGHT", "pounds", SIMCONNECT_DATATYPE_FLOAT64);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_AIRCRAFT_INFO,
        "WING SPAN", "feet", SIMCONNECT_DATATYPE_FLOAT64);

    // Flight state definition (for OOOI detection)
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_FLIGHT_STATE,
        "SIM ON GROUND", "bool", SIMCONNECT_DATATYPE_INT32);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_FLIGHT_STATE,
        "BRAKE PARKING POSITION", "position 16k", SIMCONNECT_DATATYPE_INT32);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_FLIGHT_STATE,
        "GROUND VELOCITY", "knots", SIMCONNECT_DATATYPE_FLOAT64);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_FLIGHT_STATE,
        "VERTICAL SPEED", "feet per minute", SIMCONNECT_DATATYPE_FLOAT64);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_FLIGHT_STATE,
        "PLANE ALT ABOVE GROUND", "feet", SIMCONNECT_DATATYPE_FLOAT64);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_FLIGHT_STATE,
        "GEAR HANDLE POSITION", "bool", SIMCONNECT_DATATYPE_INT32);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_FLIGHT_STATE,
        "PUSHBACK STATE", "enum", SIMCONNECT_DATATYPE_INT32);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_FLIGHT_STATE,
        "FUEL TOTAL QUANTITY", "gallons", SIMCONNECT_DATATYPE_FLOAT64);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_FLIGHT_STATE,
        "GENERAL ENG COMBUSTION:1", "bool", SIMCONNECT_DATATYPE_INT32);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_FLIGHT_STATE,
        "IS SLEW ACTIVE", "bool", SIMCONNECT_DATATYPE_INT32);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_FLIGHT_STATE,
        "SIM DISABLED", "bool", SIMCONNECT_DATATYPE_INT32);

    // Autopilot definition
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_AUTOPILOT,
        "AUTOPILOT MASTER", "bool", SIMCONNECT_DATATYPE_INT32);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_AUTOPILOT,
        "AUTOPILOT ALTITUDE LOCK", "bool", SIMCONNECT_DATATYPE_INT32);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_AUTOPILOT,
        "AUTOPILOT ALTITUDE LOCK VAR", "feet", SIMCONNECT_DATATYPE_FLOAT64);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_AUTOPILOT,
        "AUTOPILOT HEADING LOCK", "bool", SIMCONNECT_DATATYPE_INT32);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_AUTOPILOT,
        "AUTOPILOT HEADING LOCK DIR", "degrees", SIMCONNECT_DATATYPE_FLOAT64);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_AUTOPILOT,
        "AUTOPILOT AIRSPEED HOLD", "bool", SIMCONNECT_DATATYPE_INT32);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_AUTOPILOT,
        "AUTOPILOT AIRSPEED HOLD VAR", "knots", SIMCONNECT_DATATYPE_FLOAT64);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_AUTOPILOT,
        "AUTOPILOT MACH HOLD", "bool", SIMCONNECT_DATATYPE_INT32);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_AUTOPILOT,
        "AUTOPILOT MACH HOLD VAR", "number", SIMCONNECT_DATATYPE_FLOAT64);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_AUTOPILOT,
        "AUTOPILOT VERTICAL HOLD", "bool", SIMCONNECT_DATATYPE_INT32);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_AUTOPILOT,
        "AUTOPILOT VERTICAL HOLD VAR", "feet per minute", SIMCONNECT_DATATYPE_FLOAT64);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_AUTOPILOT,
        "AUTOPILOT APPROACH HOLD", "bool", SIMCONNECT_DATATYPE_INT32);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_AUTOPILOT,
        "AUTOPILOT NAV1 LOCK", "bool", SIMCONNECT_DATATYPE_INT32);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_AUTOPILOT,
        "AUTOPILOT GLIDESLOPE HOLD", "bool", SIMCONNECT_DATATYPE_INT32);

    // Engine data definition
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_ENGINES,
        "TURB ENG N1:1", "percent", SIMCONNECT_DATATYPE_FLOAT64);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_ENGINES,
        "TURB ENG N1:2", "percent", SIMCONNECT_DATATYPE_FLOAT64);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_ENGINES,
        "TURB ENG N1:3", "percent", SIMCONNECT_DATATYPE_FLOAT64);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_ENGINES,
        "TURB ENG N1:4", "percent", SIMCONNECT_DATATYPE_FLOAT64);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_ENGINES,
        "GENERAL ENG COMBUSTION:1", "bool", SIMCONNECT_DATATYPE_INT32);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_ENGINES,
        "GENERAL ENG COMBUSTION:2", "bool", SIMCONNECT_DATATYPE_INT32);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_ENGINES,
        "GENERAL ENG COMBUSTION:3", "bool", SIMCONNECT_DATATYPE_INT32);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_ENGINES,
        "GENERAL ENG COMBUSTION:4", "bool", SIMCONNECT_DATATYPE_INT32);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_ENGINES,
        "GENERAL ENG THROTTLE LEVER POSITION:1", "percent", SIMCONNECT_DATATYPE_FLOAT64);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_ENGINES,
        "GENERAL ENG THROTTLE LEVER POSITION:2", "percent", SIMCONNECT_DATATYPE_FLOAT64);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_ENGINES,
        "ENG FUEL FLOW GPH:1", "gallons per hour", SIMCONNECT_DATATYPE_FLOAT64);
    hr = SimConnect_AddToDataDefinition(hSimConnect, VATSWIM_DEFINE_ENGINES,
        "ENG FUEL FLOW GPH:2", "gallons per hour", SIMCONNECT_DATATYPE_FLOAT64);

    return SUCCEEDED(hr);
}

/**
 * Subscribe to position updates
 *
 * @param hSimConnect SimConnect handle
 * @param interval_ms Update interval in milliseconds
 */
static inline void vatswim_subscribe_position(HANDLE hSimConnect, DWORD interval_ms) {
    // SIMCONNECT_PERIOD_SECOND gives us updates every sim second
    // For more frequent updates, use SIMCONNECT_PERIOD_VISUAL_FRAME with origin/interval
    if (interval_ms <= 100) {
        SimConnect_RequestDataOnSimObject(hSimConnect, VATSWIM_REQUEST_POSITION,
            VATSWIM_DEFINE_POSITION, SIMCONNECT_OBJECT_ID_USER,
            SIMCONNECT_PERIOD_VISUAL_FRAME, 0, 0, 0, 0);
    } else {
        SimConnect_RequestDataOnSimObject(hSimConnect, VATSWIM_REQUEST_POSITION,
            VATSWIM_DEFINE_POSITION, SIMCONNECT_OBJECT_ID_USER,
            SIMCONNECT_PERIOD_SECOND, 0, 0, 0, 0);
    }
}

/**
 * Subscribe to flight state updates (for OOOI detection)
 */
static inline void vatswim_subscribe_flight_state(HANDLE hSimConnect) {
    SimConnect_RequestDataOnSimObject(hSimConnect, VATSWIM_REQUEST_FLIGHT_STATE,
        VATSWIM_DEFINE_FLIGHT_STATE, SIMCONNECT_OBJECT_ID_USER,
        SIMCONNECT_PERIOD_SECOND, 0, 0, 0, 0);
}

/**
 * Request aircraft info (one-time)
 */
static inline void vatswim_request_aircraft_info(HANDLE hSimConnect) {
    SimConnect_RequestDataOnSimObjectType(hSimConnect, VATSWIM_REQUEST_AIRCRAFT_INFO,
        VATSWIM_DEFINE_AIRCRAFT_INFO, 0, SIMCONNECT_SIMOBJECT_TYPE_USER);
}

/**
 * Subscribe to system events
 */
static inline void vatswim_subscribe_events(HANDLE hSimConnect) {
    SimConnect_SubscribeToSystemEvent(hSimConnect, VATSWIM_EVENT_SIM_START, "SimStart");
    SimConnect_SubscribeToSystemEvent(hSimConnect, VATSWIM_EVENT_SIM_STOP, "SimStop");
    SimConnect_SubscribeToSystemEvent(hSimConnect, VATSWIM_EVENT_PAUSE, "Pause");
    SimConnect_SubscribeToSystemEvent(hSimConnect, VATSWIM_EVENT_AIRCRAFT_LOADED, "AircraftLoaded");
    SimConnect_SubscribeToSystemEvent(hSimConnect, VATSWIM_EVENT_FLIGHT_LOADED, "FlightLoaded");
    SimConnect_SubscribeToSystemEvent(hSimConnect, VATSWIM_EVENT_POSITION_CHANGED, "PositionChanged");
}

#ifdef __cplusplus
}
#endif

#endif /* VATSWIM_SIMCONNECT_DATA_H */
