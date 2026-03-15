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

/** Embed colors for Discord coordination messages (left-bar color) */
const COORD_EMBED_COLORS = [
    // TMI type colors
    'MIT'       => 0xFF922B,  // Orange
    'MINIT'     => 0xFF922B,  // Orange
    'STOP'      => 0xDC3545,  // Red
    'GS'        => 0xDC3545,  // Red
    'GDP'       => 0xFD7E14,  // Amber
    'DELAY'     => 0xE67E22,  // Dark orange
    'CONFIG'    => 0x3498DB,  // Blue
    'ROUTE'     => 0x28A745,  // Green
    'REROUTE'   => 0x28A745,  // Green
    'FCA'       => 0x9B59B6,  // Purple
    'AFP'       => 0x9B59B6,  // Purple
    'ADVISORY'  => 0x95A5A6,  // Gray
    'OTHER'     => 0x95A5A6,  // Gray
    // Status colors (for starter edits + log)
    'APPROVED'  => 0x2ECC71,  // Green
    'DENIED'    => 0xE74C3C,  // Red
    'CANCELLED' => 0x7F8C8D,  // Dark gray
    'EXPIRED'   => 0x7F8C8D,  // Dark gray
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
    'ZAN', 'ZHN',
    // Oceanic/Territory
    'ZAK', 'ZAP', 'ZHO', 'ZMO', 'ZUA', 'ZWY',
];

/** Canadian FIR codes for coordination grid (individual facility checkboxes) */
const PERTI_CANADIAN_FIRS = ['CZEG', 'CZVR', 'CZWG', 'CZYZ', 'CZQM', 'CZQX', 'CZQO', 'CZUL'];

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
    'CANWEST'    => ['CZVR', 'CZEG', 'CZWG'],
    'CANEAST'    => ['CZYZ', 'CZUL', 'CZQM', 'CZQX', 'CZQO'],
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

/** All known ARTCC/FIR codes (synced with facility-hierarchy.js ARTCCS array) */
const PERTI_FIR_CODES = [
    // Continental US (20 CONUS)
    'ZAB', 'ZAU', 'ZBW', 'ZDC', 'ZDV', 'ZFW', 'ZHU', 'ZID', 'ZJX', 'ZKC',
    'ZLA', 'ZLC', 'ZMA', 'ZME', 'ZMP', 'ZNY', 'ZOA', 'ZOB', 'ZSE', 'ZTL',
    // Alaska & Hawaii
    'ZAN', 'ZHN',
    // US Oceanic
    'ZAK', 'ZAP', 'ZWY', 'ZHO', 'ZMO', 'ZUA',
    // Canada (ICAO codes)
    'CZEG', 'CZVR', 'CZWG', 'CZYZ', 'CZQM', 'CZQX', 'CZQO', 'CZUL',
    // Mexico ACCs/FIRs
    'MMMX', 'MMTY', 'MMZT', 'MMMD', 'MMUN', 'MMFR', 'MMFO',
    // Caribbean & Central American FIRs
    'TJZS', 'MKJK', 'MUFH', 'MYNA', 'MDCS', 'MTEG', 'TNCF', 'TTZP', 'MHCC', 'MPZL',
    // European FIRs (ECFMP coverage)
    'EGPX', 'EGTT', 'EISN',
    'LFFF', 'LFBB', 'LFEE', 'LFMM', 'LFRR',
    'EDGG', 'EDMM', 'EDUU', 'EDWW', 'EHAA', 'EBBU', 'ELLX', 'LSAS',
    'EFIN', 'ENOR', 'ESAA', 'EKDK', 'BIRD', 'BICC',
    'EETT', 'EVRR', 'EYVL', 'EPWW',
    'LOVV', 'LKAA', 'LZBB', 'LHCC', 'LDZO', 'LJLA', 'LQSB',
    'LECM', 'LECB', 'LECS', 'LPPC',
    'LIBB', 'LIMM', 'LIPP', 'LIRR', 'LGGG', 'LCCC', 'LMMM',
    'LRBB', 'LBSR',
    'LTAA', 'LYBA', 'LAAA', 'LWSK', 'LUUU',
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
const PERTI_COORDINATED_ENTRY_TYPES = ['MIT', 'MINIT', 'APREQ', 'CFR', 'TBM', 'TBFM', 'STOP', 'ROUTE', 'REROUTE'];

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

    // Known ARTCC/FIR (US Z** and international 4-letter codes like EGTT, EDGG)
    if (in_array($element, PERTI_FIR_CODES)) {
        return 'ARTCC';
    }

    // Airport patterns: K***, C***, P***, T*** (4-letter ICAO)
    if (preg_match('/^[KPCTY][A-Z]{2,3}$/', $element)) {
        return 'APT';
    }

    // ARTCC fallback (Z** — 3-letter US center code not in FIR list)
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

// =============================================================================
// FIR Pattern Expansion (FIR:ED.. -> ['EDGG', 'EDMM', 'EDUU', 'EDWW'])
// =============================================================================

/**
 * Expand a FIR pattern to matching FIR codes from PERTI_FIR_CODES.
 *
 * Strips 'FIR:' prefix and trailing dots (single-char wildcards),
 * then matches the remaining prefix against all known FIR codes.
 *
 * Examples:
 *   'FIR:ED..' -> ['EDGG', 'EDMM', 'EDUU', 'EDWW']
 *   'FIR:EG..' -> ['EGPX', 'EGTT']
 *   'FIR:C...' -> ['CZEG', 'CZVR', 'CZWG', 'CZYZ', 'CZQM', 'CZQX', 'CZQO', 'CZUL']
 *   'ED'       -> ['EDGG', 'EDMM', 'EDUU', 'EDWW']
 *
 * @param string $pattern Pattern like 'FIR:ED..', 'ED', 'C', 'LF', or '*'
 * @return array Matching FIR codes from PERTI_FIR_CODES
 */
function perti_expand_fir_pattern($pattern) {
    if (empty($pattern)) return [];
    $prefix = strtoupper(trim($pattern));

    // Strip FIR: prefix if present
    if (strpos($prefix, 'FIR:') === 0) {
        $prefix = substr($prefix, 4);
    }

    // Strip trailing dots (wildcards)
    $prefix = rtrim($prefix, '.');

    // Empty or wildcard = all FIR codes
    if ($prefix === '' || $prefix === '*') {
        return PERTI_FIR_CODES;
    }

    // Filter by prefix match
    return array_values(array_filter(PERTI_FIR_CODES, function($code) use ($prefix) {
        return strpos($code, $prefix) === 0;
    }));
}

/**
 * Process scope tokens, expanding any FIR: patterns to individual FIR codes.
 *
 * Input:  ['ZNY', 'FIR:ED..', 'ZDC']
 * Output: ['ZNY', 'EDGG', 'EDMM', 'EDUU', 'EDWW', 'ZDC']
 *
 * @param array $tokens Array of scope code tokens (from split_codes())
 * @return array Flat, deduplicated array with FIR patterns expanded
 */
function perti_expand_scope_codes($tokens) {
    if (empty($tokens) || !is_array($tokens)) return [];

    $result = [];
    foreach ($tokens as $token) {
        $token = trim($token);
        if ($token === '') continue;

        if (stripos($token, 'FIR:') === 0) {
            // Explicit FIR: prefix (e.g., "FIR:ED..", "FIR:C...")
            $expanded = perti_expand_fir_pattern($token);
            foreach ($expanded as $code) {
                $result[] = $code;
            }
        } elseif (preg_match('/^[A-Z]{1,3}\.+$/i', $token)) {
            // Implicit FIR pattern — letters + trailing dots (e.g., "M...", "EH..", "L...")
            // Handles comma-separated patterns after FIR: prefix:
            //   "FIR:C...,M..." → split_codes produces ["FIR:C...", "M..."] → both expand
            $expanded = perti_expand_fir_pattern($token);
            foreach ($expanded as $code) {
                $result[] = $code;
            }
        } else {
            $result[] = $token;
        }
    }

    return array_values(array_unique($result));
}
