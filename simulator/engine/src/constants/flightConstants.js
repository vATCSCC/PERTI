/**
 * Physical and aviation constants for flight simulation
 * Adapted from openScope with additions for ATFM training
 */

// Earth constants
const EARTH_RADIUS_NM = 3440.065;  // Nautical miles
const EARTH_RADIUS_KM = 6371;      // Kilometers

// Conversion factors
const NM_TO_FEET = 6076.12;
const FEET_TO_NM = 1 / NM_TO_FEET;
const KTS_TO_FPM = 101.269;        // Knots to feet per minute (for vertical speed calcs)
const DEG_TO_RAD = Math.PI / 180;
const RAD_TO_DEG = 180 / Math.PI;
const NM_TO_KM = 1.852;
const KM_TO_NM = 1 / NM_TO_KM;
const METERS_TO_FEET = 3.28084;
const FEET_TO_METERS = 1 / METERS_TO_FEET;

// Atmospheric constants
const ISA_SEA_LEVEL_TEMP_C = 15;
const ISA_LAPSE_RATE_C_PER_FT = 0.001981;  // ~2°C per 1000ft below tropopause
const TROPOPAUSE_FT = 36089;
const ISA_TROPOPAUSE_TEMP_C = -56.5;

// Speed of sound at sea level (knots)
const SPEED_OF_SOUND_SEA_LEVEL_KTS = 661.47;

// Standard acceleration/deceleration (knots per second)
const DEFAULT_ACCEL_KTS_PER_SEC = 2;
const DEFAULT_DECEL_KTS_PER_SEC = 3;

// Flight phase thresholds
const FLIGHT_PHASE = {
    PREFLIGHT: 'PREFLIGHT',
    TAXI_OUT: 'TAXI_OUT',
    DEPARTURE: 'DEPARTURE',
    CLIMB: 'CLIMB',
    CRUISE: 'CRUISE',
    DESCENT: 'DESCENT',
    APPROACH: 'APPROACH',
    LANDING: 'LANDING',
    TAXI_IN: 'TAXI_IN',
    ARRIVED: 'ARRIVED'
};

// Altitude thresholds (feet)
const ALTITUDE_THRESHOLDS = {
    GROUND: 100,           // Below this = on ground
    DEPARTURE_TOP: 10000,  // Transition from departure to climb
    CRUISE_FLOOR: 25000,   // Minimum typical cruise altitude
    FL180: 18000,          // Transition altitude (US)
    FL410: 41000           // Typical max cruise
};

// Speed restrictions
const SPEED_RESTRICTIONS = {
    BELOW_10000_KTS: 250,  // Below FL100
    CLASS_B_KTS: 200,      // In Class B airspace below 2500 AGL
    CLASS_C_D_KTS: 200     // In Class C/D airspace
};

// Turn performance
const STANDARD_RATE_TURN_DEG_PER_SEC = 3;  // 3°/sec = 2min for 360°
const HALF_STANDARD_RATE_DEG_PER_SEC = 1.5;

// Navigation tolerances
const WAYPOINT_CAPTURE_NM = 1.5;     // Distance to consider waypoint "reached"
const ALTITUDE_CAPTURE_FT = 100;      // Tolerance for level-off
const HEADING_CAPTURE_DEG = 2;        // Tolerance for on-heading
const SPEED_CAPTURE_KTS = 5;          // Tolerance for at-speed

// Simulation defaults
const DEFAULT_TICK_SECONDS = 1;       // Physics update interval
const MAX_SIMULATION_RATE = 100;      // Max time acceleration factor

module.exports = {
    // Earth
    EARTH_RADIUS_NM,
    EARTH_RADIUS_KM,
    
    // Conversions
    NM_TO_FEET,
    FEET_TO_NM,
    KTS_TO_FPM,
    DEG_TO_RAD,
    RAD_TO_DEG,
    NM_TO_KM,
    KM_TO_NM,
    METERS_TO_FEET,
    FEET_TO_METERS,
    
    // Atmosphere
    ISA_SEA_LEVEL_TEMP_C,
    ISA_LAPSE_RATE_C_PER_FT,
    TROPOPAUSE_FT,
    ISA_TROPOPAUSE_TEMP_C,
    SPEED_OF_SOUND_SEA_LEVEL_KTS,
    
    // Performance defaults
    DEFAULT_ACCEL_KTS_PER_SEC,
    DEFAULT_DECEL_KTS_PER_SEC,
    
    // Flight phases
    FLIGHT_PHASE,
    ALTITUDE_THRESHOLDS,
    SPEED_RESTRICTIONS,
    
    // Turn performance
    STANDARD_RATE_TURN_DEG_PER_SEC,
    HALF_STANDARD_RATE_DEG_PER_SEC,
    
    // Navigation tolerances
    WAYPOINT_CAPTURE_NM,
    ALTITUDE_CAPTURE_FT,
    HEADING_CAPTURE_DEG,
    SPEED_CAPTURE_KTS,
    
    // Simulation
    DEFAULT_TICK_SECONDS,
    MAX_SIMULATION_RATE
};
