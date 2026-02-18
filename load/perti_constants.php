<?php
/**
 * PERTI Centralized PHP Constants
 * ================================
 *
 * PHP mirror of assets/js/lib/perti.js domain constants.
 * SYNC WITH: assets/js/lib/perti.js (v1.6.0)
 *
 * Usage: require_once __DIR__ . '/perti_constants.php';  (from load/)
 *        require_once __DIR__ . '/../load/perti_constants.php';  (from api/)
 *
 * All constants are prefixed PERTI_ to avoid collisions with existing code.
 *
 * @version 1.1.0
 * @date 2026-02-07
 */

if (defined('PERTI_CONSTANTS_LOADED')) return;
define('PERTI_CONSTANTS_LOADED', true);

// =============================================================================
// TMI Types (mirrors perti.js ATFM.TMI_TYPES)
// =============================================================================

/** TMI type codes and their human-readable names */
const PERTI_TMI_TYPES = [
    'GS'       => 'Ground Stop',
    'GDP'      => 'Ground Delay Program',
    'AFP'      => 'Airspace Flow Program',
    'MIT'      => 'Miles-in-Trail',
    'MINIT'    => 'Minutes-in-Trail',
    'STOP'     => 'Full Ground Stop',
    'SWAP'     => 'Severe Weather Avoidance Plan',
    'EDCT'     => 'Expect Departure Clearance Time',
    'APREQ'    => 'Approval Request',
    'DSP'      => 'Departure Sequencing Program',
    'TBFM'     => 'Time Based Flow Management',
    'CFR'      => 'Call For Release',
    'CTOP'     => 'Collaborative Trajectory Options Program',
    'REROUTE'  => 'Reroute Advisory',
    'PLAYBOOK' => 'Coded Departure Route',
    'GD'       => 'Ground Delay',
];

/** TMI status values */
const PERTI_TMI_STATUSES = [
    'ACTIVE'    => 'Active',
    'ENDED'     => 'Ended',
    'CANCELLED' => 'Cancelled',
    'MODIFIED'  => 'Modified',
];

/** Valid advisory types accepted by the API (synced with perti.js ADVISORY_TYPES + API extensions) */
const PERTI_ADVISORY_TYPES = [
    // TMI Program Types (from perti.js)
    'GS', 'GDP', 'AFP', 'ICR', 'CTOP',
    // Route Advisories (from perti.js)
    'ROUTE', 'PLAYBOOK', 'CDR', 'REROUTE',
    // Operational (from perti.js)
    'SPECIAL OPERATIONS', 'OPERATIONS PLAN', 'NRP SUSPENSIONS',
    // Scope (from perti.js)
    'VS', 'NAT', 'FCA', 'FEA', 'SHUTTLE ACTIVITY',
    // General (from perti.js)
    'INFORMATIONAL', 'MISCELLANEOUS',
    // API-only extensions (not in JS)
    'MIT', 'MINIT', 'SWAP', 'TOS', 'HOTLINE', 'FREEFORM', 'ATCSCC',
    // Cancellation types (from TMI Publisher)
    'GDP_CANCEL', 'GS_CANCEL',
    // Backward-compatible aliases (deprecated, use canonical names above)
    'OPS_PLAN', 'GENERAL',
];

// =============================================================================
// Impacting Conditions / Reason Codes (mirrors perti.js COORDINATION.IMPACTING_CONDITIONS)
// =============================================================================

/** Impacting condition codes per OPSNET */
const PERTI_IMPACTING_CONDITIONS = ['WEATHER', 'VOLUME', 'RUNWAY', 'EQUIPMENT', 'OTHER'];

/** Default reason codes per TMI type */
const PERTI_DEFAULT_REASONS = [
    'GS'    => 'WEATHER',
    'GDP'   => 'WEATHER',
    'DELAY' => 'VOLUME',
];

// =============================================================================
// Facility Lists (mirrors perti.js FACILITY.FACILITY_LISTS)
// =============================================================================

/** US CONUS ARTCCs (22 centers including ZAN, ZHN) */
const PERTI_ARTCC_CONUS = [
    'ZAB', 'ZAN', 'ZAU', 'ZBW', 'ZDC', 'ZDV', 'ZFW', 'ZHN', 'ZHU',
    'ZID', 'ZJX', 'ZKC', 'ZLA', 'ZLC', 'ZMA', 'ZME', 'ZMP', 'ZNY',
    'ZOA', 'ZOB', 'ZSE', 'ZTL',
];

/** All US ARTCCs (CONUS + non-CONUS domestic + oceanic/territory) */
const PERTI_ARTCC_ALL = [
    // CONUS (20)
    'ZAB', 'ZAU', 'ZBW', 'ZDC', 'ZDV', 'ZFW', 'ZHU', 'ZID', 'ZJX', 'ZKC',
    'ZLA', 'ZLC', 'ZMA', 'ZME', 'ZMP', 'ZNY', 'ZOA', 'ZOB', 'ZSE', 'ZTL',
    // Non-CONUS domestic
    'ZAN', 'ZHN', 'ZSU',
    // Oceanic/Territory
    'ZAK', 'ZAP', 'ZHO', 'ZMO', 'ZUA', 'ZWY',
];

/** International organizations for coordination (mirrors perti.js COORDINATION.INTL_ORGS) */
const PERTI_INTL_ORGS = [
    'VATCAN' => 'Canada',
    'VATMEX' => 'Mexico',
    'VATCAR' => 'Caribbean',
    'ECFMP'  => 'Europe/N. Africa',
];

// =============================================================================
// Named Tier Groups (PHP-only; used by api/tiers/query.php)
// =============================================================================

/** Named ARTCC groupings for tier expansion and scoping */
const PERTI_NAMED_GROUPS = [
    '6WEST'      => ['ZLA', 'ZLC', 'ZDV', 'ZOA', 'ZAB', 'ZSE'],
    '10WEST'     => ['ZAB', 'ZDV', 'ZFW', 'ZHU', 'ZKC', 'ZLA', 'ZLC', 'ZMP', 'ZOA', 'ZSE'],
    '12WEST'     => ['ZAB', 'ZAU', 'ZDV', 'ZFW', 'ZHU', 'ZKC', 'ZLA', 'ZLC', 'ZME', 'ZMP', 'ZOA', 'ZSE'],
    'EASTCOAST'  => ['ZBW', 'ZNY', 'ZDC', 'ZJX', 'ZMA'],
    'WESTCOAST'  => ['ZSE', 'ZOA', 'ZLA'],
    'GULF'       => ['ZJX', 'ZMA', 'ZHU'],
    'CANWEST'    => ['CZVR', 'CZEG'],
    'CANEAST'    => ['CZWG', 'CZYZ', 'CZUL', 'CZQM'],
    'ALL'        => ['ZAB', 'ZAU', 'ZBW', 'ZDC', 'ZDV', 'ZFW', 'ZHU', 'ZID', 'ZJX', 'ZKC',
                     'ZLA', 'ZLC', 'ZMA', 'ZME', 'ZMP', 'ZNY', 'ZOA', 'ZOB', 'ZSE', 'ZTL'],
    'ALL+CANADA' => ['ZAB', 'ZAU', 'ZBW', 'ZDC', 'ZDV', 'ZFW', 'ZHU', 'ZID', 'ZJX', 'ZKC',
                     'ZLA', 'ZLC', 'ZMA', 'ZME', 'ZMP', 'ZNY', 'ZOA', 'ZOB', 'ZSE', 'ZTL',
                     'CZVR', 'CZEG', 'CZWG', 'CZYZ', 'CZUL', 'CZQM'],
];

/** Region detection by ICAO code prefix */
const PERTI_REGION_PREFIXES = [
    'US'      => ['KZ'],
    'CAN'     => ['CZ'],
    'MEX'     => ['MM'],
    'CAR'     => ['M', 'T'],      // Caribbean (not MM)
    'LATAM'   => ['S', 'SK'],
    'EUR'     => ['E', 'L'],
    'ASIA'    => ['R', 'Z', 'V'],
    'AFR'     => ['D', 'F', 'G', 'H'],
    'OCEANIC' => ['ZAK', 'ZWY', 'CZQO', 'CZQX'],
];

// =============================================================================
// GDT Program Types & Statuses (used by api/tmi/ and api/gdt/ endpoints)
// =============================================================================

/** Valid GDT program types (GS + GDP subtypes + AFP subtypes) */
const PERTI_PROGRAM_TYPES = ['GS', 'GDP-DAS', 'GDP-GAAP', 'GDP-UDP', 'AFP-DAS', 'AFP-GAAP', 'AFP-UDP'];

/** GDP subtypes only (used for GS→GDP transition validation) */
const PERTI_GDP_TYPES = ['GDP-DAS', 'GDP-GAAP', 'GDP-UDP'];

/** Valid NTML log entry types */
const PERTI_ENTRY_TYPES = ['MIT', 'MINIT', 'DELAY', 'CONFIG', 'APREQ', 'CONTINGENCY', 'MISC', 'REROUTE'];

/** Entry types that require inter-facility coordination */
const PERTI_COORDINATED_ENTRY_TYPES = ['MIT', 'MINIT', 'APREQ', 'CFR', 'TBM', 'TBFM', 'STOP'];

/** Valid coordination modes for proposal submission */
const PERTI_COORDINATION_MODES = ['STANDARD', 'EXPEDITED', 'IMMEDIATE'];

/** Program statuses eligible for simulation and proposal submission */
const PERTI_MODELING_STATUSES = ['PROPOSED', 'MODELING'];

// =============================================================================
// Element Type Detection (unified from publish.php + coordinate.php)
// =============================================================================

/**
 * Detect the type of an airspace element from its identifier.
 *
 * Returns 'APT' (not 'AIRPORT') per database CHECK constraint on tmi_programs.
 * This is the single source of truth — replaces duplicate functions in:
 *   - api/mgt/tmi/publish.php (was returning 'APT')
 *   - api/mgt/tmi/coordinate.php (was returning 'AIRPORT' — BUG)
 *
 * @param string|null $element Airspace element identifier
 * @return string|null Element type: APT, ARTCC, FCA, FIX, AIRWAY, MULTI, OTHER, or null
 */
function perti_detect_element_type($element) {
    if (empty($element)) return null;
    $element = strtoupper(trim($element));

    // Multi-element (comma-separated list)
    if (strpos($element, ',') !== false) {
        return 'MULTI';
    }

    // Airport patterns: K***, C***, P***, T*** (4-letter ICAO)
    if (preg_match('/^[KPCTY][A-Z]{2,3}$/', $element)) {
        return 'APT';
    }

    // ARTCC (Z** — 3-letter US center code)
    if (preg_match('/^Z[A-Z]{2}$/', $element)) {
        return 'ARTCC';
    }

    // FCA/FEA (flight corridor/area)
    if (preg_match('/^(FCA|FEA)/', $element)) {
        return 'FCA';
    }

    // Airway (J*, V*, Q*, T* followed by digits)
    if (preg_match('/^[JVQT]\d+$/', $element)) {
        return 'AIRWAY';
    }

    // Fix/waypoint (5-letter name)
    if (preg_match('/^[A-Z]{5}$/', $element)) {
        return 'FIX';
    }

    return 'OTHER';
}
