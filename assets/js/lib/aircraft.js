/**
 * PERTI Aircraft Data
 * ====================
 *
 * Centralized aircraft classification data:
 * - Manufacturer classification (Airbus, Boeing, Embraer, etc.)
 * - Aircraft Configuration (by engine count/layout)
 * - FAA RECAT Wake Turbulence Categories (A-F)
 * - Weight Class (J/H/L/S)
 *
 * Data sources:
 * - FAA aircraft type designators
 * - ICAO Doc 8643 aircraft type designators
 * - FAA Order 7110.659 / JO 7110.65BB (RECAT)
 * - Consolidated from demand.js, nod.js, route-maplibre.js
 *
 * @package PERTI
 * @subpackage Assets/JS/Lib
 * @version 1.1.0
 * @date 2026-02-02
 */

(function(global) {
    'use strict';

    // ===========================================
    // Aircraft Manufacturers
    // Primary classification for map color coding
    //
    // Note: Pattern matching order matters - first match wins
    // Categories match nod.js/route-maplibre.js for consistency
    // ===========================================

    const AIRCRAFT_MANUFACTURERS = {
        'AIRBUS': {
            name: 'Airbus',
            order: 1,
            color: '#e15759',      // Red
            // Note: Antonov A124/A148/A158/A225 are NOT Airbus (use explicit type list)
            pattern: /^A3[0-9]{2}|^A3[0-9][A-Z]|^A[0-9]{2}[NK]/i,
            prefixes: ['A30', 'A31', 'A32', 'A33', 'A34', 'A35', 'A38'],
            types: [
                // A300 series
                'A306', 'A30B', 'A310',
                // A320 family (narrowbody)
                'A318', 'A319', 'A320', 'A321',
                'A19N', 'A20N', 'A21N',  // Neo variants
                // A330 family
                'A332', 'A333', 'A337', 'A338', 'A339',
                // A340 family
                'A342', 'A343', 'A345', 'A346',
                // A350 family
                'A359', 'A35K',
                // A380
                'A388', 'A380',
                // Beluga
                'A3ST',
            ],
        },
        'BOEING': {
            name: 'Boeing',
            order: 2,
            color: '#4e79a7',      // Blue
            pattern: /^B7[0-9]{2}|^B3[0-9]M|^B3XM|^B77[A-Z]|^B74[A-Z]|^B74[0-9][A-Z]|^B78X/i,
            prefixes: ['B7', 'B3'],
            types: [
                'B712', 'B717', 'B721', 'B722', 'B727',
                'B731', 'B732', 'B733', 'B734', 'B735', 'B736', 'B737', 'B738', 'B739',
                'B37M', 'B38M', 'B39M', 'B3XM',
                'B741', 'B742', 'B743', 'B744', 'B748', 'B74D', 'B74R', 'B74S',
                'B752', 'B753', 'B762', 'B763', 'B764',
                'B772', 'B773', 'B77L', 'B77W',
                'B788', 'B789', 'B78X',
            ],
        },
        'EMBRAER': {
            name: 'Embraer',
            order: 3,
            color: '#59a14f',      // Green
            pattern: /^E[0-9]{3}|^ERJ|^EMB|^E[0-9][0-9][A-Z]/i,
            prefixes: ['E1', 'E2', 'E3', 'E4', 'E5', 'E6', 'E7', 'E9', 'ERJ', 'EMB'],
            types: [
                'E110', 'E120', 'E121', 'E135', 'E145',
                'E170', 'E175', 'E190', 'E195',
                'E290', 'E295',
                'E35L', 'E50P', 'E55P',
                'ERJ1', 'ERJ2',
            ],
        },
        'BOMBARDIER': {
            name: 'Bombardier',
            order: 4,
            color: '#f28e2b',      // Orange
            pattern: /^CRJ|^CL[0-9]{2}|^BD[0-9]{3}|^GL[0-9]{2}|^DHC|^BCS[0-9]|^Q[0-9]{3}/i,
            prefixes: ['CRJ', 'CL', 'GL', 'BD', 'CH', 'BCS', 'Q', 'DHC'],
            types: [
                'BD10', 'BD70', 'BCS1', 'BCS3',
                'CL30', 'CL35', 'CL60',
                'CRJ1', 'CRJ2', 'CRJ7', 'CRJ9', 'CRJX',
                'GL5T', 'GL7T', 'GLEX', 'GLXS',
                'CH30', 'CH35',
                'DHC2', 'DHC3', 'DHC4', 'DHC5', 'DHC6', 'DHC7', 'DHC8',
                'DH8A', 'DH8B', 'DH8C', 'DH8D',
                'Q100', 'Q200', 'Q300', 'Q400',
            ],
        },
        'MD_DC': {
            name: 'MD/DC',
            order: 5,
            color: '#b07aa1',      // Purple
            // McDonnell Douglas / Douglas Aircraft Company (merged with Boeing 1997)
            pattern: /^MD[0-9]{2}|^DC[0-9]{1,2}/i,
            prefixes: ['MD', 'DC'],
            types: [
                'DC10', 'DC3', 'DC6', 'DC85', 'DC86', 'DC87', 'DC9', 'DC93', 'DC94', 'DC95',
                'MD10', 'MD11', 'MD80', 'MD81', 'MD82', 'MD83', 'MD87', 'MD88', 'MD90',
            ],
        },
        'REGIONAL': {
            name: 'Regional',
            order: 6,
            color: '#76b7b2',      // Teal
            // European regional manufacturers: Saab, Fokker, Dornier, BAe, ATR, Pilatus
            // Also includes Lockheed legacy types for grouping
            pattern: /^SF34|^SB20|^F[0-9]{2,3}|^D[0-9]{3}|^BAE|^B?146|^RJ[0-9]{2}|^AT[0-9]{2}|^PC[0-9]{2}|^L10|^C13[0-9]|^C17/i,
            prefixes: ['SF', 'SB', 'F', 'D3', 'BAE', 'RJ', 'AT', 'PC', 'L1', 'C13', 'C17'],
            types: [
                // Saab
                'SF34', 'SB20',
                // Fokker
                'F50', 'F70', 'F100', 'F27', 'F28',
                // Dornier
                'D228', 'D328',
                // BAe
                'BA46', 'B146', 'RJ70', 'RJ85', 'RJ1H', 'BAEL', 'BAE1', 'BAE4',
                // ATR
                'AT43', 'AT44', 'AT45', 'AT46', 'AT72', 'AT73', 'AT75', 'AT76',
                // Pilatus
                'PC12', 'PC21', 'PC24',
                // Lockheed (legacy widebodies and military)
                'L101', 'L10', 'C130', 'C17',
            ],
        },
        'RUSSIAN': {
            name: 'Russian',
            order: 7,
            color: '#9c755f',      // Brown
            // Includes Antonov (Ukrainian - often grouped with Russian for simplicity)
            // A124/A148/A158/A225 are Antonov ICAO codes
            pattern: /^AN[0-9]{2,3}|^A12[0-9]|^A14[0-9]|^A15[0-9]|^A22[0-9]|^IL[0-9]{2,3}|^TU[0-9]{3}|^SU[0-9]{2}|^YAK|^SSJ/i,
            prefixes: ['AN', 'IL', 'TU', 'SU', 'YAK', 'SSJ', 'T1', 'T2'],
            types: [
                // Antonov (AN prefix)
                'AN12', 'AN14', 'AN22', 'AN24', 'AN26', 'AN28', 'AN30', 'AN32', 'AN72', 'AN74', 'AN14', 'AN22',
                // Antonov (A prefix - ICAO codes)
                'A124', 'A148', 'A158', 'A225',
                'IL14', 'IL18', 'IL62', 'IL76', 'IL86', 'IL96',
                'SSJ1', 'SU95',
                'TU14', 'TU15', 'TU16', 'TU20', 'TU22', 'TU34', 'TU54',
                'T134', 'T144', 'T154', 'T204', 'T214',
                'YAK4', 'YK40', 'YK42',
            ],
        },
        'CHINESE': {
            name: 'Chinese',
            order: 8,
            color: '#edc948',      // Yellow
            pattern: /^ARJ|^C9[0-9]{2}|^MA[0-9]{2}|^Y[0-9]{1,2}/i,
            prefixes: ['C9', 'ARJ', 'MA', 'Y1'],
            types: ['ARJ2', 'ARJ21', 'C919', 'MA60', 'Y12'],
        },
        'OTHER': {
            name: 'Other',
            order: 99,
            color: '#6c757d',      // Gray
            pattern: null,
            prefixes: [],
            types: [],
        },
    };

    // Build quick lookup maps
    const MANUFACTURER_COLORS = {};
    const MANUFACTURER_PATTERNS = {};
    Object.entries(AIRCRAFT_MANUFACTURERS).forEach(([key, data]) => {
        MANUFACTURER_COLORS[key] = data.color;
        if (data.pattern) {
            MANUFACTURER_PATTERNS[key] = data.pattern;
        }
    });

    // ===========================================
    // Weight Class (FAA Categories)
    // J = Super (A380-class)
    // H = Heavy (>255,000 lbs MTOW)
    // L = Large (>41,000 lbs MTOW)
    // S = Small (<41,000 lbs MTOW)
    // ===========================================

    const WEIGHT_CLASS_COLORS = {
        'SUPER': '#ffc107', 'J': '#ffc107',  // Amber/Gold for Jumbo
        'HEAVY': '#dc3545', 'H': '#dc3545',  // Red for Heavy
        'LARGE': '#28a745', 'L': '#28a745',  // Green for Large/Jet
        'SMALL': '#17a2b8', 'S': '#17a2b8',  // Cyan for Small/Prop
        '': '#6c757d',
    };

    // Weight class labels resolved via i18n (existing keys: weightClass.J/H/L/S)
    function _wcLabel(code) {
        if (typeof PERTII18n !== 'undefined') {
            return PERTII18n.t('weightClass.' + code);
        }
        var fallback = { J: 'Super', H: 'Heavy', L: 'Large', S: 'Small' };
        return fallback[code] || code;
    }
    const WEIGHT_CLASS_LABELS = {
        get SUPER() { return _wcLabel('J'); }, get J() { return _wcLabel('J'); },
        get HEAVY() { return _wcLabel('H'); }, get H() { return _wcLabel('H'); },
        get LARGE() { return _wcLabel('L'); }, get L() { return _wcLabel('L'); },
        get SMALL() { return _wcLabel('S'); }, get S() { return _wcLabel('S'); },
    };

    // ===========================================
    // Aircraft Configuration
    // Classification by engine count and layout
    // Order matters - first match wins
    // ===========================================

    const AIRCRAFT_CONFIG = {
        'CONC': {
            name: 'Concorde',
            order: 1,
            color: '#ff1493',      // Deep Pink - Supersonic
            pattern: /^CONC|^T144|^TU144/i,
        },
        'A380': {
            name: 'A380',
            order: 2,
            color: '#9c27b0',      // Deep Purple - Super Heavy
            // Includes AN-225, AN-124 (super-heavy lifters)
            pattern: /^A38[0-9]|^A225|^AN225|^A124|^AN124/i,
        },
        'QUAD_JET': {
            name: 'Quad Jet',
            order: 3,
            color: '#e15759',      // Red
            // 747, A340, IL-96, DC-8, VC10
            pattern: /^B74[0-9]|^B74[A-Z]|^B74[0-9][A-Z]|^A34[0-6]|^A340|^IL96|^DC8|^VC10/i,
        },
        'HEAVY_TWIN': {
            name: 'Heavy Twin',
            order: 4,
            color: '#f28e2b',      // Orange
            // 777, 787, A330, A350, 767, A300, A310, IL-86, IL-62
            pattern: /^B77[0-9]|^B77[A-Z]|^B78[0-9]|^B78X|^A33[0-9]|^A35[0-9]|^A35K|^B76[0-9]|^A30[0-9]|^A310|^IL86|^IL62/i,
        },
        'TRI_JET': {
            name: 'Tri Jet',
            order: 5,
            color: '#edc948',      // Yellow
            // MD-11, DC-10, L-1011, TU-154, 727, Yak-42, TU-134, Falcon 900/7X/8X
            pattern: /^MD11|^DC10|^L101|^L10|^TU15|^B72[0-9]|^R72[0-9]|^YK42|^YAK42|^TU13|^F900|^FA7X|^FA8X/i,
        },
        'TWIN_JET': {
            name: 'Twin Jet',
            order: 6,
            color: '#59a14f',      // Green
            // A320 family, 737 family, 757, MD-80/90, 717, Fokker, BAe, TU-204, C919, SSJ, ARJ, CRJX
            pattern: /^A32[0-9]|^A31[0-9]|^A2[0-9][NK]|^A22[0-9]|^B73[0-9]|^B3[0-9]M|^B3XM|^B75[0-9]|^MD[89][0-9]|^BCS[0-9]|^B712|^B717|^F100|^F70|^F28|^B146|^RJ[0-9]{2}|^BA46|^AVRO|^TU20|^TU21|^C919|^SSJ|^SU95|^ARJ|^CRJX/i,
        },
        'REGIONAL_JET': {
            name: 'Regional',
            order: 7,
            color: '#4e79a7',      // Blue
            // CRJ, ERJ, E-Jets
            pattern: /^CRJ[0-9]|^ERJ|^E[0-9]{3}|^E[0-9][0-9][A-Z]|^E1[0-9]{2}|^E75|^E90|^E95/i,
        },
        'TURBOPROP': {
            name: 'Turboprop',
            order: 8,
            color: '#76b7b2',      // Teal
            // ATR, DHC-8/Q, Saab, Beech 1900, Jetstream, PC-12/24, Caravan, L-410, MA60, Y-12, Dornier
            pattern: /^AT[0-9]{2}|^ATR|^DH8|^DHC[0-9]|^Q[0-9]{3}|^SF34|^SB20|^SAAB|^B190|^BE19|^JS[0-9]{2}|^J31|^J32|^J41|^PC12|^PC24|^C208|^C212|^L410|^MA60|^Y12|^AN[23][0-9]|^DO[0-9]{2}|^D328/i,
        },
        'PROP': {
            name: 'Prop',
            order: 9,
            color: '#17a2b8',      // Cyan
            // GA: Cessna, Piper, Cirrus, Diamond, Mooney, Beech Bonanza, Robin, Socata, etc.
            pattern: /^C1[0-9]{2}|^C2[0-9]{2}|^C3[0-9]{2}|^C4[0-9]{2}|^P28|^PA[0-9]{2}|^PA[0-9][0-9]T|^SR2[0-9]|^SR22|^DA[0-9]{2}|^DA4[0-9]|^M20|^M20[A-Z]|^BE[0-9]{2}[^0-9]|^BE3[0-9]|^BE36|^A36|^G36|^DR[0-9]{2}|^TB[0-9]{2}|^TBM|^RV[0-9]|^AAA|^AA5|^GLST|^ULAC|^TRIN|^COL[0-9]|^EVOT/i,
        },
        'OTHER': {
            name: 'Other',
            order: 99,
            color: '#6c757d',      // Gray
            pattern: null,
        },
    };

    // Build quick lookup maps for configuration
    const CONFIG_COLORS = {};
    const CONFIG_PATTERNS = {};
    Object.entries(AIRCRAFT_CONFIG).forEach(([key, data]) => {
        CONFIG_COLORS[key] = data.color;
        if (data.pattern) {
            CONFIG_PATTERNS[key] = data.pattern;
        }
    });

    // ===========================================
    // FAA RECAT Wake Turbulence Categories
    // Based on FAA Order 7110.659 and JO 7110.65BB
    // Categories A-F based on wingspan and MTOW
    // ===========================================

    const RECAT_CATEGORIES = {
        'A': {
            name: 'Super',
            description: 'A380 only (wingspan > 245ft)',
            color: '#9c27b0',      // Purple
            // Cat A: Super - A380 only
            pattern: /^A38[0-9]/i,
        },
        'B': {
            name: 'Upper Heavy',
            description: 'wingspan 175-245ft (747, 777, A340, A350-1000, MD-11)',
            color: '#dc3545',      // Red
            // 747 variants, 777 variants, A340, A350-1000, MD-11, DC-10-30/40, C-5, AN-124, AN-225
            pattern: /^B74[0-9]|^B77[0-9]|^B77[A-Z]|^A34[0-9]|^A35K|^MD11|^DC10|^C5|^C5M|^AN12|^A124|^AN225|^IL96|^A300B4|^KC10|^KC135|^E3|^E4|^E6|^VC25/i,
        },
        'C': {
            name: 'Lower Heavy',
            description: 'wingspan 125-175ft (787, 767, A330, A350-900)',
            color: '#f28e2b',      // Orange
            // 787, 767, A350-900, A330, A300, L-1011, DC-8, IL-62, IL-86, TU-154M
            pattern: /^B78[0-9]|^B78X|^B76[0-9]|^A35[0-9]|^A33[0-9]|^A30[0-9]|^A310|^L101|^DC8|^IL62|^IL86|^TU15|^C17|^KC46|^P8/i,
        },
        'D': {
            name: 'Upper Large',
            description: 'wingspan 90-125ft (757, 737, A320 fam, MD-80/90)',
            color: '#edc948',      // Yellow
            // 757, 737-700/800/900/MAX, A321, A320, A319, A318, MD-80/90, 717, C-130, P-3, Gulfstream
            pattern: /^B75[0-9]|^B73[789]|^B38M|^B39M|^B3XM|^A32[0-1]|^A31[89]|^MD[89][0-9]|^B712|^B717|^C130|^C160|^P3|^G[56][0-9]{2}|^GLF[456]|^F900|^FA[78]X|^CL60|^GL[57]T|^GLEX|^BCS[13]/i,
        },
        'E': {
            name: 'Lower Large',
            description: 'wingspan 65-90ft (CRJ, ERJ, E-Jets, ATR, DHC-8)',
            color: '#28a745',      // Green
            // CRJ-200/700, ERJ-145/170/175, E-Jets, ATR 42/72, DHC-8, Saab 340/2000, Beech 1900
            pattern: /^CRJ[12789]|^ERJ|^E[0-9]{3}|^E1[0-9]{2}|^E75|^E90|^E95|^AT[47][0-9]|^ATR|^DH8|^DHC8|^Q[0-9]{3}|^SF34|^SB20|^B190|^JS[0-9]{2}|^PC12|^PC24|^BE20|^BE30|^BE35|^C208|^DHC[67]|^F[27]0|^F100|^BA46|^B146|^RJ[0-9]{2}/i,
        },
        'F': {
            name: 'Small',
            description: 'wingspan < 65ft, MTOW < 15,500 lbs',
            color: '#17a2b8',      // Cyan
            // Light GA, Cessna singles/twins, Piper, Cirrus, Diamond, Beech Bonanza, etc.
            pattern: /^C1[0-9]{2}|^C2[0-9]{2}|^C3[0-9]{2}|^C4[0-9]{2}|^P28|^PA[0-9]{2}|^SR2[0-9]|^DA[0-9]{2}|^M20|^BE[0-9]{2}[^0-9]|^BE3[56]|^A36|^G36|^TB[0-9]{2}|^TBM|^PC6|^ULAC/i,
        },
    };

    // Build quick lookup maps for RECAT
    const RECAT_COLORS = {};
    const RECAT_PATTERNS = {};
    Object.entries(RECAT_CATEGORIES).forEach(([key, data]) => {
        RECAT_COLORS[key] = data.color;
        if (data.pattern) {
            RECAT_PATTERNS[key] = data.pattern;
        }
    });
    RECAT_COLORS[''] = '#6c757d';  // Unknown

    // ===========================================
    // Helper Functions
    // ===========================================

    /**
     * Strip common suffixes from aircraft type codes
     * Examples: B738/W -> B738, A320/N -> A320
     *
     * @param {string} acType - Aircraft type code
     * @returns {string} - Cleaned type code
     */
    function stripSuffixes(acType) {
        if (!acType) {return '';}
        return String(acType).toUpperCase().trim()
            .replace(/\/[A-Z]$/, '')      // /W, /N, /L suffixes
            .replace(/\s+.*$/, '')        // Everything after space
            .replace(/-[A-Z0-9]+$/, '');  // -200, -8, etc.
    }

    /**
     * Get manufacturer for an aircraft type code
     *
     * @param {string} acType - Aircraft type code (e.g., 'B738', 'A320', 'CRJ9')
     * @returns {string} - Manufacturer key (e.g., 'BOEING', 'AIRBUS', 'OTHER')
     */
    function getManufacturer(acType) {
        if (!acType) {return 'OTHER';}

        const type = stripSuffixes(acType);

        // Check explicit type matches first (most accurate)
        for (const [mfr, data] of Object.entries(AIRCRAFT_MANUFACTURERS)) {
            if (mfr === 'OTHER') {continue;}
            if (data.types && data.types.includes(type)) {
                return mfr;
            }
        }

        // Check regex patterns (catches variants)
        for (const [mfr, data] of Object.entries(AIRCRAFT_MANUFACTURERS)) {
            if (mfr === 'OTHER') {continue;}
            if (data.pattern && data.pattern.test(type)) {
                return mfr;
            }
        }

        return 'OTHER';
    }

    /**
     * Get manufacturer display name
     *
     * @param {string} acType - Aircraft type code
     * @returns {string} - Display name (e.g., 'Boeing', 'Airbus')
     */
    function getManufacturerName(acType) {
        const mfr = getManufacturer(acType);
        return AIRCRAFT_MANUFACTURERS[mfr]?.name || ((typeof PERTII18n !== 'undefined') ? PERTII18n.t('common.other') : 'Other');
    }

    /**
     * Get aircraft configuration (by engine layout)
     *
     * @param {string} acType - Aircraft type code
     * @returns {string} - Configuration key (e.g., 'TWIN_JET', 'TURBOPROP')
     */
    function getConfig(acType) {
        if (!acType) {return 'OTHER';}
        const type = stripSuffixes(acType);
        for (const [cfg, data] of Object.entries(AIRCRAFT_CONFIG)) {
            if (cfg === 'OTHER') {continue;}
            if (data.pattern && data.pattern.test(type)) {
                return cfg;
            }
        }
        return 'OTHER';
    }

    /**
     * Get aircraft configuration display name
     *
     * @param {string} acType - Aircraft type code
     * @returns {string} - Display name (e.g., 'Twin Jet', 'Turboprop')
     */
    function getConfigName(acType) {
        const cfg = getConfig(acType);
        return AIRCRAFT_CONFIG[cfg]?.name || ((typeof PERTII18n !== 'undefined') ? PERTII18n.t('common.other') : 'Other');
    }

    /**
     * Get color for aircraft configuration
     *
     * @param {string} acType - Aircraft type code
     * @returns {string} - CSS color hex value
     */
    function getConfigColor(acType) {
        const cfg = getConfig(acType);
        return CONFIG_COLORS[cfg] || CONFIG_COLORS['OTHER'];
    }

    /**
     * Get FAA RECAT wake turbulence category (A-F) from aircraft type
     *
     * @param {string} acType - Aircraft type code
     * @param {string} [weightClass] - Optional weight class fallback (J/H/L/S)
     * @returns {string} - RECAT category (A-F) or empty string
     */
    function getRecatCategory(acType, weightClass) {
        const type = stripSuffixes(acType);

        if (type) {
            // Check patterns in order (A is most restrictive)
            for (const [cat, data] of Object.entries(RECAT_CATEGORIES)) {
                if (data.pattern && data.pattern.test(type)) {
                    return cat;
                }
            }
        }

        // Fallback to weight class if no pattern match
        if (weightClass) {
            const wc = weightClass.toUpperCase();
            if (wc === 'SUPER' || wc === 'J') {return 'A';}
            if (wc === 'HEAVY' || wc === 'H') {return 'C';}  // Default heavy to Cat C
            if (wc === 'LARGE' || wc === 'L') {return 'D';}
            if (wc === 'SMALL' || wc === 'S') {return 'F';}
        }

        return 'D';  // Default to Cat D (most common)
    }

    /**
     * Get RECAT category display name
     *
     * @param {string} category - RECAT category (A-F)
     * @returns {string} - Display name (e.g., 'Super', 'Upper Heavy')
     */
    function getRecatName(category) {
        return RECAT_CATEGORIES[category]?.name || ((typeof PERTII18n !== 'undefined') ? PERTII18n.t('common.unknown') : 'Unknown');
    }

    /**
     * Get color for RECAT category
     *
     * @param {string} category - RECAT category (A-F)
     * @returns {string} - CSS color hex value
     */
    function getRecatColor(category) {
        return RECAT_COLORS[category] || RECAT_COLORS[''];
    }

    /**
     * Normalize weight class to standard format
     * Accepts: J/H/L/S or SUPER/HEAVY/LARGE/SMALL
     *
     * @param {string} wc - Weight class code
     * @returns {string} - Normalized to SUPER/HEAVY/LARGE/SMALL or ''
     */
    function normalizeWeightClass(wc) {
        if (!wc) {return '';}
        const upper = wc.toUpperCase().trim();
        if (upper === 'J' || upper === 'SUPER') {return 'SUPER';}
        if (upper === 'H' || upper === 'HEAVY') {return 'HEAVY';}
        if (upper === 'L' || upper === 'LARGE') {return 'LARGE';}
        if (upper === 'S' || upper === 'SMALL') {return 'SMALL';}
        return '';
    }

    /**
     * Get color for weight class
     *
     * @param {string} wc - Weight class (J/H/L/S or SUPER/HEAVY/LARGE/SMALL)
     * @returns {string} - CSS color hex value
     */
    function getWeightClassColor(wc) {
        return WEIGHT_CLASS_COLORS[normalizeWeightClass(wc)] || WEIGHT_CLASS_COLORS[''];
    }

    /**
     * Get label for weight class
     *
     * @param {string} wc - Weight class (J/H/L/S or SUPER/HEAVY/LARGE/SMALL)
     * @returns {string} - Display label
     */
    function getWeightClassLabel(wc) {
        return WEIGHT_CLASS_LABELS[normalizeWeightClass(wc)] || ((typeof PERTII18n !== 'undefined') ? PERTII18n.t('common.unknown') : 'Unknown');
    }

    /**
     * Get color for an aircraft type
     *
     * @param {string} acType - Aircraft type code
     * @returns {string} - CSS color hex value
     */
    function getManufacturerColor(acType) {
        const mfr = getManufacturer(acType);
        return MANUFACTURER_COLORS[mfr] || MANUFACTURER_COLORS['OTHER'];
    }

    /**
     * Get sort order for a manufacturer
     *
     * @param {string} mfr - Manufacturer key (e.g., 'BOEING')
     * @returns {number} - Sort order (1-99)
     */
    function getManufacturerOrder(mfr) {
        if (!mfr) {return 99;}
        const upper = mfr.toUpperCase();
        return AIRCRAFT_MANUFACTURERS[upper]?.order || 99;
    }

    /**
     * Get legend items for manufacturer color schemes
     * Useful for building map legends
     *
     * @returns {Array} - Array of { key, color, label } objects
     */
    function getManufacturerLegend() {
        return Object.entries(AIRCRAFT_MANUFACTURERS)
            .filter(([key]) => key !== 'OTHER')
            .sort((a, b) => a[1].order - b[1].order)
            .map(([key, data]) => ({
                key: key,
                color: data.color,
                label: data.name,
            }))
            .concat([{
                key: 'OTHER',
                color: AIRCRAFT_MANUFACTURERS.OTHER.color,
                label: (typeof PERTII18n !== 'undefined') ? PERTII18n.t('common.other') : 'Other',
            }]);
    }

    // ===========================================
    // Legend Builders
    // ===========================================

    /**
     * Get legend items for aircraft configuration
     * @returns {Array} - Array of { key, color, label } objects
     */
    function getConfigLegend() {
        return Object.entries(AIRCRAFT_CONFIG)
            .filter(([key]) => key !== 'OTHER')
            .sort((a, b) => a[1].order - b[1].order)
            .map(([key, data]) => ({
                key: key,
                color: data.color,
                label: data.name,
            }));
    }

    /**
     * Get legend items for RECAT categories
     * @returns {Array} - Array of { key, color, label, description } objects
     */
    function getRecatLegend() {
        return Object.entries(RECAT_CATEGORIES)
            .map(([key, data]) => ({
                key: key,
                color: data.color,
                label: `${key} (${data.name})`,
                description: data.description,
            }));
    }

    /**
     * Get legend items for weight class
     * @returns {Array} - Array of { key, color, label } objects
     */
    function getWeightClassLegend() {
        var t = (typeof PERTII18n !== 'undefined') ? PERTII18n.t.bind(PERTII18n) : null;
        return [
            { key: 'J', color: WEIGHT_CLASS_COLORS['J'], label: t ? t('aircraft.legendWeightJ') : 'J (Super)' },
            { key: 'H', color: WEIGHT_CLASS_COLORS['H'], label: t ? t('aircraft.legendWeightH') : 'H (Heavy)' },
            { key: 'L', color: WEIGHT_CLASS_COLORS['L'], label: t ? t('aircraft.legendWeightL') : 'L (Large)' },
            { key: 'S', color: WEIGHT_CLASS_COLORS['S'], label: t ? t('aircraft.legendWeightS') : 'S (Small)' },
        ];
    }

    // ===========================================
    // Export to Global Namespace
    // ===========================================

    global.PERTIAircraft = {
        // Manufacturer Data
        MANUFACTURERS: AIRCRAFT_MANUFACTURERS,
        MANUFACTURER_COLORS: MANUFACTURER_COLORS,
        MANUFACTURER_PATTERNS: MANUFACTURER_PATTERNS,
        // Legacy aliases
        COLORS: MANUFACTURER_COLORS,
        PATTERNS: MANUFACTURER_PATTERNS,

        // Aircraft Configuration Data
        CONFIG: AIRCRAFT_CONFIG,
        CONFIG_COLORS: CONFIG_COLORS,
        CONFIG_PATTERNS: CONFIG_PATTERNS,

        // RECAT Wake Turbulence Data
        RECAT: RECAT_CATEGORIES,
        RECAT_COLORS: RECAT_COLORS,
        RECAT_PATTERNS: RECAT_PATTERNS,

        // Weight Class Data
        WEIGHT_CLASS_COLORS: WEIGHT_CLASS_COLORS,
        WEIGHT_CLASS_LABELS: WEIGHT_CLASS_LABELS,

        // Utility Functions
        stripSuffixes: stripSuffixes,

        // Manufacturer Functions
        getManufacturer: getManufacturer,
        getManufacturerName: getManufacturerName,
        getManufacturerColor: getManufacturerColor,
        getManufacturerOrder: getManufacturerOrder,
        getManufacturerLegend: getManufacturerLegend,

        // Configuration Functions
        getConfig: getConfig,
        getConfigName: getConfigName,
        getConfigColor: getConfigColor,
        getConfigLegend: getConfigLegend,

        // RECAT Functions
        getRecatCategory: getRecatCategory,
        getRecatName: getRecatName,
        getRecatColor: getRecatColor,
        getRecatLegend: getRecatLegend,

        // Weight Class Functions
        normalizeWeightClass: normalizeWeightClass,
        getWeightClassColor: getWeightClassColor,
        getWeightClassLabel: getWeightClassLabel,
        getWeightClassLegend: getWeightClassLegend,
    };

})(typeof window !== 'undefined' ? window : this);
