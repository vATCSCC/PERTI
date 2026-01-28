/**
 * Facility Hierarchy Data
 *
 * Global definitions for FAA facility hierarchy including:
 * - ARTCC (Air Route Traffic Control Center) definitions
 * - DCC (Direct Command Center) Region groupings with colors
 * - Facility groups for quick-select
 * - Hierarchy mapping (ARTCC -> TRACON -> Airport)
 * - Facility code aliases (ICAO/FAA/Short forms)
 *
 * Data sources:
 * - adl/migrations/topology/002_artcc_topology_seed.sql
 * - assets/data/apts.csv (Core30, OEP35, ASPM77 columns)
 * - jatoc.php FIR definitions
 *
 * Usage: Include this file before any scripts that need facility data
 *
 * @package PERTI
 * @subpackage Assets/JS
 * @version 1.1.0
 * @date 2026-01-28
 */

(function(global) {
    'use strict';

    // ===========================================
    // ARTCC/FIR Definitions
    // ===========================================

    const ARTCCS = [
        // Continental US (20 CONUS)
        'ZAB', 'ZAU', 'ZBW', 'ZDC', 'ZDV', 'ZFW', 'ZHU', 'ZID', 'ZJX', 'ZKC',
        'ZLA', 'ZLC', 'ZMA', 'ZME', 'ZMP', 'ZNY', 'ZOA', 'ZOB', 'ZSE', 'ZTL',
        // Alaska
        'ZAN',
        // Hawaii
        'ZHN',
        // US Oceanic
        'ZAK', 'ZAP', 'ZWY', 'ZHO', 'ZMO', 'ZUA',
        // Canada (ICAO codes)
        'CZEG', 'CZVR', 'CZWG', 'CZYZ', 'CZQM', 'CZQX', 'CZQO', 'CZUL',
        // Mexico FIRs
        'MMFR', 'MMFO',
        // Caribbean & Central American FIRs
        'TJZS', 'MKJK', 'MUFH', 'MYNA', 'MDCS', 'MTEG', 'TNCF', 'TTZP', 'MHCC', 'MPZL'
    ];

    // ===========================================
    // Facility Code Aliases
    // Maps various code formats to canonical codes
    // ===========================================

    const FACILITY_ALIASES = {
        // Canadian FIRs: ICAO -> FAA -> Short
        'CZEG': ['ZEG', 'CZE'],    // Edmonton
        'CZVR': ['ZVR', 'CZV'],    // Vancouver
        'CZWG': ['ZWG', 'CZW'],    // Winnipeg
        'CZYZ': ['ZYZ', 'CZY'],    // Toronto
        'CZQM': ['ZQM', 'CZM'],    // Moncton
        'CZQX': ['ZQX', 'CZX'],    // Gander Domestic
        'CZQO': ['ZQO', 'CZO'],    // Gander Oceanic
        'CZUL': ['ZUL', 'CZU'],    // Montreal
        // US Oceanic with ICAO prefixes
        'ZAK': ['KZAK'],           // Oakland Oceanic
        'ZWY': ['KZWY'],           // New York Oceanic
        'ZUA': ['PGZU'],           // Guam CERAP
        'ZAN': ['PAZA'],           // Anchorage ARTCC
        'ZAP': ['PAZN'],           // Anchorage Oceanic
        'ZHN': ['PHZH']            // Honolulu
    };

    // Build reverse alias lookup (alias -> canonical)
    const ALIAS_TO_CANONICAL = {};
    Object.entries(FACILITY_ALIASES).forEach(([canonical, aliases]) => {
        ALIAS_TO_CANONICAL[canonical] = canonical;
        aliases.forEach(alias => {
            ALIAS_TO_CANONICAL[alias] = canonical;
        });
    });

    // ===========================================
    // DCC Regions with Colors
    // ===========================================

    const DCC_REGIONS = {
        'SOUTH_CENTRAL': {
            name: 'South Central',
            artccs: ['ZAB', 'ZFW', 'ZHO', 'ZHU', 'ZME'],
            color: '#ec791b',  // Orange
            bgColor: 'rgba(253, 126, 20, 0.15)',
            textClass: 'text-warning'
        },
        'SOUTHEAST': {
            name: 'Southeast',
            artccs: ['ZID', 'ZJX', 'ZMA', 'ZMO', 'ZTL'],
            color: '#ffc107',  // Yellow
            bgColor: 'rgba(255, 193, 7, 0.15)',
            textClass: 'text-warning'
        },
        'NORTHEAST': {
            name: 'Northeast',
            artccs: ['ZBW', 'ZDC', 'ZNY', 'ZOB', 'ZWY'],
            color: '#007bff',  // Blue
            bgColor: 'rgba(0, 123, 255, 0.15)',
            textClass: 'text-primary'
        },
        'MIDWEST': {
            name: 'Midwest',
            artccs: ['ZAU', 'ZDV', 'ZKC', 'ZMP'],
            color: '#28a745',  // Green
            bgColor: 'rgba(40, 167, 69, 0.15)',
            textClass: 'text-success'
        },
        'WEST': {
            name: 'West',
            artccs: ['ZAK', 'ZAN', 'ZAP', 'ZHN', 'ZLA', 'ZLC', 'ZOA', 'ZSE', 'ZUA'],
            color: '#dc3545',  // Red
            bgColor: 'rgba(220, 53, 69, 0.15)',
            textClass: 'text-danger'
        },
        'CANADA': {
            name: 'Canada',
            artccs: ['CZEG', 'CZVR', 'CZWG', 'CZYZ', 'CZQM', 'CZQX', 'CZQO', 'CZUL'],
            color: '#6f42c1',  // Purple
            bgColor: 'rgba(111, 66, 193, 0.15)',
            textClass: 'text-purple'
        },
        'MEXICO': {
            name: 'Mexico',
            artccs: ['MMFR', 'MMFO'],  // Mexico FIR, Mazatlán Oceanic
            color: '#8B4513',  // Brown
            bgColor: 'rgba(139, 69, 19, 0.15)',
            textClass: 'text-brown'
        },
        'CARIBBEAN': {
            name: 'Caribbean',
            artccs: ['TJZS', 'MKJK', 'MUFH', 'MYNA', 'MDCS', 'MTEG', 'TNCF', 'TTZP', 'MHCC', 'MPZL'],
            color: '#e83e8c',  // Pink
            bgColor: 'rgba(232, 62, 140, 0.15)',
            textClass: 'text-pink'
        }
    };

    // ===========================================
    // Facility Emoji Mapping (for Discord reactions)
    // Used by non-Nitro users for TMI coordination
    // Regional indicators for US, number emojis for Canada
    // ===========================================

    const FACILITY_EMOJI_MAP = {
        // US ARTCCs - Regional indicator letters
        'ZAB': '🇦',  // A - Albuquerque
        'ZAN': '🇬',  // G - anchoraGe (A taken, N reserved for NY)
        'ZAU': '🇺',  // U - chicaGo (zaU)
        'ZBW': '🇧',  // B - Boston
        'ZDC': '🇩',  // D - Washington DC
        'ZDV': '🇻',  // V - DenVer (D taken)
        'ZFW': '🇫',  // F - Fort Worth
        'ZHN': '🇭',  // H - Honolulu
        'ZHU': '🇼',  // W - Houston (H taken)
        'ZID': '🇮',  // I - Indianapolis
        'ZJX': '🇯',  // J - Jacksonville
        'ZKC': '🇰',  // K - Kansas City
        'ZLA': '🇱',  // L - Los Angeles
        'ZLC': '🇨',  // C - Salt Lake City (L taken)
        'ZMA': '🇲',  // M - Miami
        'ZME': '🇪',  // E - mEmphis (M taken)
        'ZMP': '🇵',  // P - minneaPolis (M taken)
        'ZNY': '🇳',  // N - New York
        'ZOA': '🇴',  // O - Oakland
        'ZOB': '🇷',  // R - cleveland (O taken)
        'ZSE': '🇸',  // S - Seattle
        'ZTL': '🇹',  // T - aTlanta
        // Canadian FIRs - Number emojis
        'CZEG': '1️⃣',  // 1 - Edmonton
        'CZVR': '2️⃣',  // 2 - Vancouver
        'CZWG': '3️⃣',  // 3 - Winnipeg
        'CZYZ': '4️⃣',  // 4 - Toronto
        'CZQM': '5️⃣',  // 5 - Moncton
        'CZQX': '6️⃣',  // 6 - Gander Domestic
        'CZQO': '7️⃣',  // 7 - Gander Oceanic
        'CZUL': '8️⃣'   // 8 - Montreal
    };

    // Reverse mapping: emoji to facility code
    const EMOJI_TO_FACILITY = {};
    Object.entries(FACILITY_EMOJI_MAP).forEach(([facility, emoji]) => {
        EMOJI_TO_FACILITY[emoji] = facility;
    });

    // ===========================================
    // Named Tier Groups (from topology seed)
    // These are fixed regional groupings
    // ===========================================

    const NAMED_TIER_GROUPS = {
        '6WEST': {
            name: '6 West',
            description: 'Six southwestern ARTCCs',
            artccs: ['ZLA', 'ZLC', 'ZDV', 'ZOA', 'ZAB', 'ZSE']
        },
        '10WEST': {
            name: '10 West',
            description: 'Ten western ARTCCs',
            artccs: ['ZAB', 'ZDV', 'ZFW', 'ZHU', 'ZKC', 'ZLA', 'ZLC', 'ZMP', 'ZOA', 'ZSE']
        },
        '12WEST': {
            name: '12 West',
            description: 'Twelve western/central ARTCCs',
            artccs: ['ZAB', 'ZAU', 'ZDV', 'ZFW', 'ZHU', 'ZKC', 'ZLA', 'ZLC', 'ZME', 'ZMP', 'ZOA', 'ZSE']
        },
        'GULF': {
            name: 'Gulf',
            description: 'Gulf region',
            artccs: ['ZJX', 'ZMA', 'ZHU']
        },
        'CANWEST': {
            name: 'Canada West',
            description: 'Western Canadian FIRs',
            artccs: ['CZVR', 'CZEG']
        },
        'CANEAST': {
            name: 'Canada East',
            description: 'Eastern Canadian FIRs',
            artccs: ['CZWG', 'CZYZ', 'CZUL', 'CZQM']
        }
    };

    // ===========================================
    // Quick-Select Facility Groups
    // ===========================================

    const FACILITY_GROUPS = {
        'US_CONUS': {
            name: 'CONUS (Lower 48)',
            artccs: ['ZAB', 'ZAU', 'ZBW', 'ZDC', 'ZDV', 'ZFW', 'ZHU', 'ZID', 'ZJX', 'ZKC',
                     'ZLA', 'ZLC', 'ZMA', 'ZME', 'ZMP', 'ZNY', 'ZOA', 'ZOB', 'ZSE', 'ZTL']
        },
        'US_ALL': {
            name: 'All US (incl. AK/HI/Oceanic)',
            artccs: ['ZAB', 'ZAN', 'ZAU', 'ZBW', 'ZDC', 'ZDV', 'ZFW', 'ZHN', 'ZHU', 'ZID',
                     'ZJX', 'ZKC', 'ZLA', 'ZLC', 'ZMA', 'ZME', 'ZMP', 'ZNY', 'ZOA', 'ZOB',
                     'ZSE', 'ZTL', 'ZHO', 'ZMO', 'ZWY', 'ZAK', 'ZAP', 'ZUA']
        },
        'US_CANADA': {
            name: 'All US + Canada',
            artccs: ['ZAB', 'ZAN', 'ZAU', 'ZBW', 'ZDC', 'ZDV', 'ZFW', 'ZHN', 'ZHU', 'ZID',
                     'ZJX', 'ZKC', 'ZLA', 'ZLC', 'ZMA', 'ZME', 'ZMP', 'ZNY', 'ZOA', 'ZOB',
                     'ZSE', 'ZTL', 'ZHO', 'ZMO', 'ZWY', 'ZAK', 'ZAP', 'ZUA',
                     'CZEG', 'CZVR', 'CZWG', 'CZYZ', 'CZQM', 'CZQX', 'CZQO', 'CZUL']
        },
        '6WEST': {
            name: '6 West',
            artccs: ['ZLA', 'ZLC', 'ZDV', 'ZOA', 'ZAB', 'ZSE']
        },
        '10WEST': {
            name: '10 West',
            artccs: ['ZAB', 'ZDV', 'ZFW', 'ZHU', 'ZKC', 'ZLA', 'ZLC', 'ZMP', 'ZOA', 'ZSE']
        },
        '12WEST': {
            name: '12 West',
            artccs: ['ZAB', 'ZAU', 'ZDV', 'ZFW', 'ZHU', 'ZKC', 'ZLA', 'ZLC', 'ZME', 'ZMP', 'ZOA', 'ZSE']
        },
        'GULF': {
            name: 'Gulf',
            artccs: ['ZJX', 'ZMA', 'ZHU']
        }
    };

    // ===========================================
    // Airport Groups (loaded from apts.csv)
    // Populated dynamically based on CSV columns
    // ===========================================

    let AIRPORT_GROUPS = {
        'CORE30': { name: 'Core 30', airports: [] },
        'OEP35': { name: 'OEP 35', airports: [] },
        'ASPM77': { name: 'ASPM 77', airports: [] }
    };

    // ===========================================
    // Build ARTCC -> Region mapping
    // ===========================================

    const ARTCC_TO_REGION = {};
    Object.entries(DCC_REGIONS).forEach(([regionKey, region]) => {
        region.artccs.forEach(artcc => {
            ARTCC_TO_REGION[artcc] = regionKey;
        });
    });

    // ===========================================
    // Hierarchy Storage (populated from apts.csv)
    // ===========================================

    let FACILITY_HIERARCHY = {};  // artcc/tracon -> [child facilities]
    let TRACON_TO_ARTCC = {};     // tracon -> parent artcc
    let AIRPORT_TO_TRACON = {};   // airport -> parent tracon
    let AIRPORT_TO_ARTCC = {};    // airport -> parent artcc
    let ALL_TRACONS = new Set();
    let hierarchyLoaded = false;
    let hierarchyLoadPromise = null;

    // ===========================================
    // CSV Parsing & Hierarchy Building
    // ===========================================

    function parseCSVLine(line) {
        const result = [];
        let current = '';
        let inQuotes = false;

        for (let i = 0; i < line.length; i++) {
            const char = line[i];
            if (char === '"') {
                inQuotes = !inQuotes;
            } else if (char === ',' && !inQuotes) {
                result.push(current);
                current = '';
            } else {
                current += char;
            }
        }
        result.push(current);
        return result;
    }

    function parseFacilityHierarchy(csvText) {
        const lines = csvText.split('\n');
        if (lines.length < 2) return;

        // Parse header to find column indices
        const header = lines[0].split(',');
        const colIdx = {
            arptId: header.indexOf('ARPT_ID'),
            icaoId: header.indexOf('ICAO_ID'),
            arptName: header.indexOf('ARPT_NAME'),
            respArtcc: header.indexOf('RESP_ARTCC_ID'),
            approachId: header.indexOf('Approach ID'),
            depId: header.indexOf('Departure ID'),
            apDepId: header.indexOf('Approach/Departure ID'),
            dccRegion: header.indexOf('DCC REGION'),
            core30: header.indexOf('Core30'),
            oep35: header.indexOf('OEP35'),
            aspm77: header.indexOf('ASPM77')
        };

        // Initialize ARTCC entries
        ARTCCS.forEach(artcc => {
            FACILITY_HIERARCHY[artcc] = new Set();
        });

        // Parse each airport line
        for (let i = 1; i < lines.length; i++) {
            const line = lines[i].trim();
            if (!line) continue;

            const cols = parseCSVLine(line);
            const arptId = (cols[colIdx.arptId] || '').trim().toUpperCase();
            const icaoId = (cols[colIdx.icaoId] || '').trim().toUpperCase();
            const artcc = (cols[colIdx.respArtcc] || '').trim().toUpperCase();

            // Get TRACON - check multiple columns
            let tracon = (cols[colIdx.approachId] || '').trim().toUpperCase();
            if (!tracon) tracon = (cols[colIdx.depId] || '').trim().toUpperCase();
            if (!tracon) tracon = (cols[colIdx.apDepId] || '').trim().toUpperCase();

            // Skip if no ARTCC or not in our list
            if (!artcc) continue;

            // Resolve aliases to canonical form
            const canonicalArtcc = ALIAS_TO_CANONICAL[artcc] || artcc;
            if (!ARTCCS.includes(canonicalArtcc) && !ARTCCS.includes(artcc)) continue;

            // Add TRACON to ARTCC's children if valid
            if (tracon && tracon.length >= 2 && tracon.length <= 4 && !ARTCCS.includes(tracon)) {
                if (!FACILITY_HIERARCHY[canonicalArtcc]) FACILITY_HIERARCHY[canonicalArtcc] = new Set();
                FACILITY_HIERARCHY[canonicalArtcc].add(tracon);
                TRACON_TO_ARTCC[tracon] = canonicalArtcc;
                ALL_TRACONS.add(tracon);

                // Initialize TRACON's children set
                if (!FACILITY_HIERARCHY[tracon]) FACILITY_HIERARCHY[tracon] = new Set();
            }

            // Add airport to appropriate parent
            const airportCode = icaoId || arptId;
            if (airportCode) {
                AIRPORT_TO_ARTCC[airportCode] = canonicalArtcc;
                if (tracon && ALL_TRACONS.has(tracon)) {
                    AIRPORT_TO_TRACON[airportCode] = tracon;
                    FACILITY_HIERARCHY[tracon].add(airportCode);
                } else {
                    // Add directly to ARTCC if no TRACON
                    if (!FACILITY_HIERARCHY[canonicalArtcc]) FACILITY_HIERARCHY[canonicalArtcc] = new Set();
                    FACILITY_HIERARCHY[canonicalArtcc].add(airportCode);
                }

                // Populate airport groups
                if (colIdx.core30 >= 0 && cols[colIdx.core30]?.toUpperCase() === 'TRUE') {
                    AIRPORT_GROUPS.CORE30.airports.push(airportCode);
                }
                if (colIdx.oep35 >= 0 && cols[colIdx.oep35]?.toUpperCase() === 'TRUE') {
                    AIRPORT_GROUPS.OEP35.airports.push(airportCode);
                }
                if (colIdx.aspm77 >= 0 && cols[colIdx.aspm77]?.toUpperCase() === 'TRUE') {
                    AIRPORT_GROUPS.ASPM77.airports.push(airportCode);
                }
            }
        }

        // Convert Sets to Arrays for easier use
        Object.keys(FACILITY_HIERARCHY).forEach(key => {
            if (FACILITY_HIERARCHY[key] instanceof Set) {
                FACILITY_HIERARCHY[key] = Array.from(FACILITY_HIERARCHY[key]);
            }
        });

        hierarchyLoaded = true;
    }

    // ===========================================
    // Load Hierarchy from CSV
    // ===========================================

    function loadHierarchy() {
        if (hierarchyLoadPromise) return hierarchyLoadPromise;

        hierarchyLoadPromise = fetch('assets/data/apts.csv')
            .then(response => response.text())
            .then(csvText => {
                parseFacilityHierarchy(csvText);
                console.log('[FacilityHierarchy] Loaded:', {
                    artccs: ARTCCS.length,
                    tracons: ALL_TRACONS.size,
                    airports: Object.keys(AIRPORT_TO_ARTCC).length,
                    core30: AIRPORT_GROUPS.CORE30.airports.length,
                    oep35: AIRPORT_GROUPS.OEP35.airports.length,
                    aspm77: AIRPORT_GROUPS.ASPM77.airports.length
                });
                return true;
            })
            .catch(e => {
                console.warn('[FacilityHierarchy] Failed to load:', e);
                // Build basic hierarchy from ARTCC list
                ARTCCS.forEach(artcc => {
                    FACILITY_HIERARCHY[artcc] = [];
                });
                return false;
            });

        return hierarchyLoadPromise;
    }

    // ===========================================
    // Helper Functions
    // ===========================================

    /**
     * Resolve a facility code to its canonical form
     * @param {string} code - Facility code (may be an alias)
     * @returns {string} - Canonical facility code
     */
    function resolveAlias(code) {
        const upper = (code || '').toUpperCase();
        return ALIAS_TO_CANONICAL[upper] || upper;
    }

    /**
     * Get all aliases for a facility code
     * @param {string} code - Facility code
     * @returns {string[]} - Array of aliases (including canonical)
     */
    function getAliases(code) {
        const canonical = resolveAlias(code);
        const aliases = FACILITY_ALIASES[canonical] || [];
        return [canonical, ...aliases];
    }

    function expandFacilitySelection(facilities) {
        // Expand selected facilities to include all children
        const expanded = new Set();

        facilities.forEach(fac => {
            const facUpper = resolveAlias(fac);
            expanded.add(facUpper);

            // Also add all known aliases
            getAliases(facUpper).forEach(alias => expanded.add(alias));

            // If it's an ARTCC, add all TRACONs and airports under it
            if (ARTCCS.includes(facUpper) && FACILITY_HIERARCHY[facUpper]) {
                FACILITY_HIERARCHY[facUpper].forEach(child => {
                    expanded.add(child);
                    // If child is a TRACON, also add its airports
                    if (FACILITY_HIERARCHY[child]) {
                        FACILITY_HIERARCHY[child].forEach(apt => expanded.add(apt));
                    }
                });
            }
            // If it's a TRACON, add all airports under it
            else if (ALL_TRACONS.has(facUpper) && FACILITY_HIERARCHY[facUpper]) {
                FACILITY_HIERARCHY[facUpper].forEach(apt => expanded.add(apt));
            }
        });

        return expanded;
    }

    function getRegionForFacility(facility) {
        const fac = resolveAlias(facility);

        // Direct ARTCC lookup
        if (ARTCC_TO_REGION[fac]) {
            return ARTCC_TO_REGION[fac];
        }
        // TRACON - look up parent ARTCC
        const parentArtcc = TRACON_TO_ARTCC[fac];
        if (parentArtcc && ARTCC_TO_REGION[parentArtcc]) {
            return ARTCC_TO_REGION[parentArtcc];
        }
        // Airport - look up ARTCC
        const artcc = AIRPORT_TO_ARTCC[fac];
        if (artcc && ARTCC_TO_REGION[artcc]) {
            return ARTCC_TO_REGION[artcc];
        }
        return null;
    }

    function getRegionColor(facility) {
        const region = getRegionForFacility(facility);
        return region ? DCC_REGIONS[region]?.color : null;
    }

    function getRegionBgColor(facility) {
        const region = getRegionForFacility(facility);
        return region ? DCC_REGIONS[region]?.bgColor : null;
    }

    function isArtcc(code) {
        return ARTCCS.includes(resolveAlias(code));
    }

    function isTracon(code) {
        return ALL_TRACONS.has(resolveAlias(code));
    }

    function getParentArtcc(code) {
        const upper = resolveAlias(code);
        if (ARTCCS.includes(upper)) return upper;
        if (TRACON_TO_ARTCC[upper]) return TRACON_TO_ARTCC[upper];
        if (AIRPORT_TO_ARTCC[upper]) return AIRPORT_TO_ARTCC[upper];
        return null;
    }

    function getChildFacilities(code) {
        return FACILITY_HIERARCHY[resolveAlias(code)] || [];
    }

    // ===========================================
    // Export to Global Namespace
    // ===========================================

    global.FacilityHierarchy = {
        // Constants
        ARTCCS: ARTCCS,
        DCC_REGIONS: DCC_REGIONS,
        FACILITY_GROUPS: FACILITY_GROUPS,
        NAMED_TIER_GROUPS: NAMED_TIER_GROUPS,
        FACILITY_ALIASES: FACILITY_ALIASES,
        ARTCC_TO_REGION: ARTCC_TO_REGION,
        FACILITY_EMOJI_MAP: FACILITY_EMOJI_MAP,
        EMOJI_TO_FACILITY: EMOJI_TO_FACILITY,

        // Dynamic data (getters)
        get FACILITY_HIERARCHY() { return FACILITY_HIERARCHY; },
        get TRACON_TO_ARTCC() { return TRACON_TO_ARTCC; },
        get AIRPORT_TO_TRACON() { return AIRPORT_TO_TRACON; },
        get AIRPORT_TO_ARTCC() { return AIRPORT_TO_ARTCC; },
        get ALL_TRACONS() { return ALL_TRACONS; },
        get AIRPORT_GROUPS() { return AIRPORT_GROUPS; },
        get isLoaded() { return hierarchyLoaded; },

        // Methods
        load: loadHierarchy,
        resolveAlias: resolveAlias,
        getAliases: getAliases,
        expandSelection: expandFacilitySelection,
        getRegion: getRegionForFacility,
        getRegionColor: getRegionColor,
        getRegionBgColor: getRegionBgColor,
        isArtcc: isArtcc,
        isTracon: isTracon,
        getParentArtcc: getParentArtcc,
        getChildren: getChildFacilities
    };

})(typeof window !== 'undefined' ? window : this);
