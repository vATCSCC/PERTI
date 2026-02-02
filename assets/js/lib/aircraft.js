/**
 * PERTI Aircraft Data
 * ====================
 *
 * Centralized aircraft type classification by manufacturer.
 * Single source of truth for manufacturer patterns, colors, and metadata.
 *
 * Data sources:
 * - FAA aircraft type designators
 * - ICAO Doc 8643 aircraft type designators
 * - Consolidated from demand.js, nod.js, route-maplibre.js
 *
 * @package PERTI
 * @subpackage Assets/JS/Lib
 * @version 1.0.0
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
        return AIRCRAFT_MANUFACTURERS[mfr]?.name || 'Other';
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
                label: 'Other',
            }]);
    }

    // ===========================================
    // Export to Global Namespace
    // ===========================================

    global.PERTIAircraft = {
        // Data
        MANUFACTURERS: AIRCRAFT_MANUFACTURERS,
        COLORS: MANUFACTURER_COLORS,
        PATTERNS: MANUFACTURER_PATTERNS,

        // Functions
        stripSuffixes: stripSuffixes,
        getManufacturer: getManufacturer,
        getManufacturerName: getManufacturerName,
        getManufacturerColor: getManufacturerColor,
        getManufacturerOrder: getManufacturerOrder,
        getManufacturerLegend: getManufacturerLegend,
    };

})(typeof window !== 'undefined' ? window : this);
