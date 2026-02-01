/**
 * TMI Constants - Traffic Management Initiative definitions
 *
 * Based on FAA FSM/TFMS specifications
 */

// TMI Types
const TMI_TYPE = {
    GROUND_STOP: 'GS',
    GROUND_DELAY_PROGRAM: 'GDP',
    AIRSPACE_FLOW_PROGRAM: 'AFP',
    COMPRESSION: 'COMP',
    BLANKET: 'BLKT',
    REROUTE: 'REROUTE',
};

// TMI Status
const TMI_STATUS = {
    PROPOSED: 'PROPOSED',
    ACTIVE: 'ACTIVE',
    EXPIRED: 'EXPIRED',
    PURGED: 'PURGED',
};

// GDP Program Types (delay assignment modes)
const GDP_TYPE = {
    DAS: 'DAS',      // Delay Assignment - first come first served by original ETA
    GAAP: 'GAAP',    // General Aviation Airport Program
    UDP: 'UDP',       // Unified Delay Program
};

// Scope Types
const SCOPE_TYPE = {
    TIER: 'TIER',           // Center-based tiers
    DISTANCE: 'DISTANCE',    // Distance in NM from destination
};

// Tier Keywords
const TIER_KEYWORD = {
    INTERNAL: 'INTERNAL',   // Tier 0 - controlling center only
    TIER1: 'TIER1',         // Tier 1 - adjacent centers
    TIER2: 'TIER2',         // Tier 2 - one ring further
    ALL: 'ALL',             // All CONUS centers
    CONUS: 'CONUS',          // Same as ALL for CONUS
};

// Flight Control Types
const CONTROL_TYPE = {
    GROUND_STOP: 'GS',
    GDP_CONTROL: 'GDP',
    AFP_CONTROL: 'AFP',
    EXEMPT: 'EXEMPT',
    NONE: 'NONE',
};

// Exemption Categories
const EXEMPTION_CATEGORY = {
    CARRIER: 'CARRIER',           // Specific carriers exempt
    AIRCRAFT_TYPE: 'AIRCRAFT_TYPE', // Specific aircraft types
    ARRIVAL_FIX: 'ARRIVAL_FIX',   // Specific arrival fixes
    ORIGIN_AIRPORT: 'ORIGIN_AIRPORT',
    ORIGIN_CENTER: 'ORIGIN_CENTER',
    FLIGHT_TYPE: 'FLIGHT_TYPE',   // e.g., military, cargo
    EQUIPMENT: 'EQUIPMENT',        // e.g., props only
};

// Flight Types for exemption
const FLIGHT_TYPE = {
    SCHEDULED: 'S',
    GENERAL_AVIATION: 'G',
    MILITARY: 'M',
    CARGO: 'C',
    CHARTER: 'H',
    AIR_TAXI: 'T',
};

// Default GS duration (1 hour minimum per FSM)
const GS_MIN_DURATION_MINUTES = 60;

// Default time increments
const TIME_INCREMENT_MINUTES = 15;

module.exports = {
    TMI_TYPE,
    TMI_STATUS,
    GDP_TYPE,
    SCOPE_TYPE,
    TIER_KEYWORD,
    CONTROL_TYPE,
    EXEMPTION_CATEGORY,
    FLIGHT_TYPE,
    GS_MIN_DURATION_MINUTES,
    TIME_INCREMENT_MINUTES,
};
