/**
 * PERTI Filter Configuration
 * Unified color scheme for all filter/breakdown dimensions across pages.
 * Single source of truth for: demand.php, route.php, nod.php, gdt.php
 *
 * i18n Support:
 *   - FILTER_I18N_KEYS maps filter values to i18n keys
 *   - getFilterLabel() uses PERTII18n.t() when available
 *   - Carrier/ARTCC names are proper nouns and not translated
 */

const FILTER_CONFIG = {
    // Weight Class (S/L/H/J)
    weightClass: {
        colors: {
            'J': '#ffc107',  // Super - Amber
            'H': '#dc3545',  // Heavy - Red
            'L': '#28a745',  // Large - Green
            'S': '#17a2b8',  // Small - Cyan
            'UNKNOWN': '#6c757d',
        },
        labels: {
            'J': 'Super',
            'H': 'Heavy',
            'L': 'Large',
            'S': 'Small',
            'UNKNOWN': 'Unknown',
        },
        order: ['J', 'H', 'L', 'S', 'UNKNOWN'],
    },

    // Flight Rules (IFR/VFR)
    flightRule: {
        colors: {
            'I': '#007bff',  // IFR - Blue
            'V': '#28a745',   // VFR - Green
        },
        labels: {
            'I': 'IFR',
            'V': 'VFR',
        },
        order: ['I', 'V'],
    },

    // Major Carriers
    carrier: {
        colors: {
            // US Majors
            'AAL': '#0078d2',  // American - Royal Blue
            'UAL': '#0033a0',  // United - Dark Blue
            'DAL': '#e01933',  // Delta - Red
            'SWA': '#f9b612',  // Southwest - Yellow
            'JBU': '#003876',  // JetBlue - Navy
            'ASA': '#00a8e0',  // Alaska - Teal
            'FFT': '#2b8542',  // Frontier - Green
            'NKS': '#ffd200',  // Spirit - Yellow
            'HAL': '#5b2e91',  // Hawaiian - Purple
            // Canadian Majors
            'ACA': '#f01428',  // Air Canada - Red
            'WJA': '#00a4e4',  // WestJet - Blue
            'TSC': '#e31837',  // Air Transat - Red
            'ROU': '#e4002b',  // Rouge (AC) - Red
            'WEN': '#00a4e4',  // WestJet Encore
            'POE': '#0033a0',  // Porter - Dark Blue
            'SWG': '#00a4e4',  // Sunwing
            'FLE': '#00a651',  // Flair - Green
            // Cargo
            'FDX': '#ff6600',  // FedEx - Orange
            'UPS': '#351c15',  // UPS - Brown
            'GTI': '#002d72',  // Atlas - Dark Blue
            'ABX': '#cc0000',  // ABX Air - Red
            // Regionals
            'SKW': '#1e90ff',  // SkyWest - Dodger Blue
            'RPA': '#4169e1',  // Republic - Royal Blue
            'ENY': '#87ceeb',  // Envoy - Light Blue
            'PDT': '#0078d2',  // Piedmont
            'JIA': '#0033a0',  // PSA Airlines
            // European
            'BAW': '#075aaa',  // British Airways
            'DLH': '#00205b',  // Lufthansa
            'AFR': '#002157',  // Air France
            'KLM': '#00a1e4',  // KLM - Light Blue
            'VIR': '#e01933',  // Virgin Atlantic - Red
            'IBE': '#d4a900',  // Iberia - Gold
            'AZA': '#006643',  // ITA Airways - Green
            'SAS': '#000066',  // SAS - Navy
            'TAP': '#00a651',  // TAP Portugal - Green
            'THY': '#c8102e',  // Turkish - Red
            'SWR': '#c8102e',  // Swiss - Red
            'AUA': '#c8102e',  // Austrian - Red
            'BEL': '#002157',  // Brussels - Blue
            'EIN': '#00a651',  // Aer Lingus - Green
            'FIN': '#0033a0',  // Finnair - Blue
            'NAX': '#d81939',  // Norwegian - Red
            'RYR': '#0033a0',  // Ryanair - Navy
            'EZY': '#ff6600',  // EasyJet - Orange
            'VLG': '#f0006f',  // Vueling - Pink
            // Middle East / Africa
            'UAE': '#d71921',  // Emirates - Red
            'QTR': '#5c0632',  // Qatar - Maroon
            'ETD': '#bd8b13',  // Etihad - Gold
            'SAA': '#006847',  // South African - Green
            'ETH': '#006341',  // Ethiopian - Green
            'MSR': '#00205b',  // EgyptAir - Navy
            'RJA': '#c8102e',  // Royal Jordanian - Red
            'GFA': '#c4a000',  // Gulf Air - Gold
            'SVA': '#006747',  // Saudia - Green
            // Asian
            'SIA': '#005f6a',  // Singapore - Teal
            'CPA': '#006747',  // Cathay Pacific - Green
            'JAL': '#c8102e',  // Japan Airlines - Red
            'ANA': '#002a6b',  // All Nippon - Navy
            'KAL': '#005a9c',  // Korean Air - Blue
            'AAR': '#e30613',  // Asiana - Red
            'EVA': '#00a651',  // EVA Air - Green
            'CAL': '#e4007f',  // China Airlines - Pink
            'CES': '#e30613',  // China Eastern - Red
            'CSN': '#e30613',  // China Southern - Red
            'CCA': '#c8102e',  // Air China - Red
            'HDA': '#f57c00',  // Hainan - Orange
            'THA': '#5c2e91',  // Thai - Purple
            'MAS': '#e30613',  // Malaysia - Red
            'GIA': '#00599d',  // Garuda - Blue
            'VNA': '#e30613',  // Vietnam Airlines - Red
            'AIC': '#ff6600',  // Air India - Orange
            // Oceania
            'QFA': '#e31937',  // Qantas - Red
            'ANZ': '#00838f',  // Air New Zealand - Teal
            // Latin America
            'AVA': '#e30613',  // Avianca - Red
            'LAN': '#002a5c',  // LATAM - Navy
            'GLO': '#ff6600',  // Gol - Orange
            'AZU': '#005eb8',  // Azul - Blue
            'AMX': '#00205b',  // Aeromexico - Navy
            'CMP': '#0033a0',  // Copa - Blue
            // Business/Charter
            'EJA': '#8b4513',  // NetJets - Brown
            'XOJ': '#4a4a4a',  // XOJet - Dark Gray
            'LEJ': '#1a1a1a',  // LJ Aviation - Black
            // Fallbacks
            'OTHER': '#6c757d',
            'UNKNOWN': '#adb5bd',
        },
        labels: {
            // US
            'AAL': 'American', 'UAL': 'United', 'DAL': 'Delta',
            'SWA': 'Southwest', 'JBU': 'JetBlue', 'ASA': 'Alaska',
            'FFT': 'Frontier', 'NKS': 'Spirit', 'HAL': 'Hawaiian',
            // Canada
            'ACA': 'Air Canada', 'WJA': 'WestJet', 'TSC': 'Air Transat',
            'ROU': 'Rouge', 'WEN': 'WestJet Encore', 'POE': 'Porter',
            'SWG': 'Sunwing', 'FLE': 'Flair',
            // Cargo
            'FDX': 'FedEx', 'UPS': 'UPS', 'GTI': 'Atlas', 'ABX': 'ABX Air',
            // Regionals
            'SKW': 'SkyWest', 'RPA': 'Republic', 'ENY': 'Envoy',
            'PDT': 'Piedmont', 'JIA': 'PSA Airlines',
            // European
            'BAW': 'British Airways', 'DLH': 'Lufthansa', 'AFR': 'Air France',
            'KLM': 'KLM', 'VIR': 'Virgin Atlantic', 'IBE': 'Iberia',
            'AZA': 'ITA Airways', 'SAS': 'SAS', 'TAP': 'TAP Portugal',
            'THY': 'Turkish', 'SWR': 'Swiss', 'AUA': 'Austrian',
            'BEL': 'Brussels', 'EIN': 'Aer Lingus', 'FIN': 'Finnair',
            'NAX': 'Norwegian', 'RYR': 'Ryanair', 'EZY': 'EasyJet', 'VLG': 'Vueling',
            // Middle East / Africa
            'UAE': 'Emirates', 'QTR': 'Qatar', 'ETD': 'Etihad',
            'SAA': 'South African', 'ETH': 'Ethiopian', 'MSR': 'EgyptAir',
            'RJA': 'Royal Jordanian', 'GFA': 'Gulf Air', 'SVA': 'Saudia',
            // Asian
            'SIA': 'Singapore', 'CPA': 'Cathay Pacific', 'JAL': 'Japan Airlines',
            'ANA': 'All Nippon', 'KAL': 'Korean Air', 'AAR': 'Asiana',
            'EVA': 'EVA Air', 'CAL': 'China Airlines', 'CES': 'China Eastern',
            'CSN': 'China Southern', 'CCA': 'Air China', 'HDA': 'Hainan',
            'THA': 'Thai', 'MAS': 'Malaysia', 'GIA': 'Garuda',
            'VNA': 'Vietnam Airlines', 'AIC': 'Air India',
            // Oceania
            'QFA': 'Qantas', 'ANZ': 'Air New Zealand',
            // Latin America
            'AVA': 'Avianca', 'LAN': 'LATAM', 'GLO': 'Gol', 'AZU': 'Azul',
            'AMX': 'Aeromexico', 'CMP': 'Copa',
            // Business/Charter
            'EJA': 'NetJets', 'XOJ': 'XOJet', 'LEJ': 'LJ Aviation',
            'OTHER': 'Other', 'UNKNOWN': 'Unknown',
        },
    },

    // Aircraft Type by Manufacturer
    equipment: {
        colors: {
            // Boeing Classic/Legacy
            'B703': '#4682b4', 'B707': '#4682b4', 'B720': '#4682b4',  // 707 family - Steel Blue
            'B712': '#5f9ea0', 'B717': '#5f9ea0',  // 717 - Cadet Blue
            'B721': '#6495ed', 'B722': '#6495ed', 'B727': '#6495ed', 'R722': '#6495ed',  // 727 family - Cornflower
            'B752': '#4169e1', 'B753': '#4169e1', 'B757': '#4169e1',  // 757 family - Royal Blue
            // Boeing Narrowbody (737 family)
            'B731': '#0078d2', 'B732': '#0078d2', 'B733': '#0078d2',
            'B734': '#0078d2', 'B735': '#0078d2', 'B736': '#0078d2',
            'B737': '#0078d2', 'B738': '#0078d2', 'B739': '#0078d2',
            'B38M': '#0078d2', 'B39M': '#0078d2', 'B3XM': '#0078d2',
            // Boeing Widebody (767)
            'B762': '#4169e1', 'B763': '#4169e1', 'B764': '#4169e1',
            // Boeing Widebody (777)
            'B772': '#1e90ff', 'B773': '#1e90ff', 'B77W': '#1e90ff', 'B77L': '#1e90ff',
            'B778': '#1e90ff', 'B779': '#1e90ff',
            // Boeing Widebody (787)
            'B788': '#00bfff', 'B789': '#00bfff', 'B78X': '#00bfff',
            // Boeing Widebody (747)
            'B741': '#6495ed', 'B742': '#6495ed', 'B743': '#6495ed',
            'B744': '#6495ed', 'B748': '#6495ed', 'B74S': '#6495ed',
            // Airbus Classic
            'A306': '#b22222', 'A30B': '#b22222', 'A300': '#b22222',  // A300 - Fire Brick
            'A310': '#cd5c5c', 'A313': '#cd5c5c',  // A310 - Indian Red
            // Airbus Narrowbody (A320 family)
            'A318': '#e01933', 'A319': '#e01933', 'A320': '#e01933', 'A321': '#e01933',
            'A19N': '#e01933', 'A20N': '#e01933', 'A21N': '#e01933',
            // Airbus Widebody (A330)
            'A332': '#dc143c', 'A333': '#dc143c', 'A338': '#dc143c', 'A339': '#dc143c',
            // Airbus Widebody (A340)
            'A342': '#b22222', 'A343': '#b22222', 'A345': '#b22222', 'A346': '#b22222',
            // Airbus Widebody (A350)
            'A359': '#ff4500', 'A35K': '#ff4500',
            // Airbus Super
            'A380': '#ff6347', 'A388': '#ff6347',
            // Embraer Jets
            'E135': '#228b22', 'E145': '#228b22',
            'E170': '#228b22', 'E175': '#228b22',
            'E190': '#32cd32', 'E195': '#32cd32',
            'E290': '#32cd32', 'E295': '#32cd32',
            // CRJ Family
            'CRJ1': '#8b4513', 'CRJ2': '#8b4513',
            'CR5': '#a0522d', 'CRJ5': '#a0522d', 'C550': '#a0522d',  // CRJ550
            'CRJ7': '#a0522d', 'CR7': '#a0522d',
            'CRJ9': '#cd853f', 'CR9': '#cd853f',
            'CRJX': '#deb887',
            // SAAB
            'SB20': '#2e8b57', 'SF34': '#2e8b57', 'SB34': '#2e8b57',  // SAAB 340/2000 - Sea Green
            // Turboprops - ATR/Dash
            'AT43': '#9acd32', 'AT45': '#9acd32', 'AT72': '#9acd32', 'AT76': '#9acd32',
            'DH8A': '#808000', 'DH8B': '#808000', 'DH8C': '#6b8e23', 'DH8D': '#6b8e23',
            // Chinese Aircraft
            'A19C': '#ff1493', 'A29C': '#ff1493',  // Comac ARJ21 - Deep Pink
            'C919': '#ff69b4',  // Comac C919 - Hot Pink
            // Russian Aircraft
            'SU95': '#8b008b', 'SU9S': '#8b008b',  // Sukhoi Superjet - Dark Magenta
            'IL96': '#9400d3', 'IL86': '#9400d3',  // Ilyushin - Dark Violet
            'TU54': '#800080', 'T154': '#800080', 'T204': '#800080',  // Tupolev - Purple
            'AN24': '#ba55d3', 'AN26': '#ba55d3', 'AN12': '#ba55d3',  // Antonov - Medium Orchid
            // Private Jets - Light
            'C25A': '#708090', 'C25B': '#708090', 'C25C': '#708090',  // Citation - Slate Gray
            'C510': '#778899', 'C525': '#778899', 'C56X': '#778899',
            'C560': '#778899', 'C680': '#778899', 'C750': '#778899',
            // Private Jets - Medium
            'CL30': '#696969', 'CL35': '#696969', 'CL60': '#696969',  // Challenger - Dim Gray
            'GL5T': '#2f4f4f', 'GL7T': '#2f4f4f', 'GLEX': '#2f4f4f',  // Global - Dark Slate
            'G150': '#556b2f', 'G200': '#556b2f', 'G280': '#556b2f',  // Gulfstream Small - Dark Olive
            'G450': '#4a4a4a', 'G500': '#4a4a4a', 'G550': '#4a4a4a',  // Gulfstream Large
            'G650': '#3d3d3d', 'G700': '#3d3d3d', 'G800': '#3d3d3d',
            'FA50': '#5c5c5c', 'F900': '#5c5c5c', 'FA7X': '#5c5c5c',  // Falcon - Gray
            'F2TH': '#5c5c5c', 'FA8X': '#5c5c5c',
            'E35L': '#6a5acd', 'E50P': '#6a5acd', 'E55P': '#6a5acd',  // Embraer Phenom - Slate Blue
            'HDJT': '#483d8b', 'HA4T': '#483d8b',  // HondaJet - Dark Slate Blue
            'PC12': '#8fbc8f', 'PC24': '#8fbc8f',  // Pilatus - Dark Sea Green
            'LJ35': '#7b68ee', 'LJ45': '#7b68ee', 'LJ60': '#7b68ee',  // Learjet - Medium Slate
            'LJ70': '#7b68ee', 'LJ75': '#7b68ee',
            // Fallbacks
            'OTHER': '#6c757d',
            'UNKNOWN': '#adb5bd',
        },
    },

    // ARTCC/FIR Centers
    artcc: {
        colors: {
            // US ARTCCs
            'ZBW': '#e6194b', 'ZNY': '#3cb44b', 'ZDC': '#ffe119',
            'ZJX': '#4363d8', 'ZMA': '#f58231', 'ZTL': '#911eb4',
            'ZID': '#46f0f0', 'ZOB': '#f032e6', 'ZAU': '#bcf60c',
            'ZMP': '#fabebe', 'ZKC': '#008080', 'ZME': '#e6beff',
            'ZFW': '#9a6324', 'ZHU': '#fffac8', 'ZAB': '#800000',
            'ZDV': '#aaffc3', 'ZLC': '#808000', 'ZLA': '#ffd8b1',
            'ZOA': '#000075', 'ZSE': '#808080', 'ZAN': '#000000',
            'ZHN': '#ffffff',
            // Canadian FIRs - East (Purple)
            'CZYZ': '#9b59b6',  // Toronto
            'CZUL': '#8e44ad',  // Montreal
            'CZZV': '#7d3c98',  // Sept-Iles
            'CZQM': '#6c3483',  // Moncton
            'CZQX': '#5b2c6f',  // Gander Domestic
            'CZQO': '#4a235a',  // Gander Oceanic
            // Canadian FIRs - West (Pink)
            'CZWG': '#ff69b4',  // Winnipeg
            'CZEG': '#ff1493',  // Edmonton
            'CZVR': '#db7093',  // Vancouver
            // Fallbacks
            'OTHER': '#6c757d', 'UNKNOWN': '#adb5bd',
        },
        labels: {
            // US ARTCCs
            'ZBW': 'Boston', 'ZNY': 'New York', 'ZDC': 'Washington',
            'ZJX': 'Jacksonville', 'ZMA': 'Miami', 'ZTL': 'Atlanta',
            'ZID': 'Indianapolis', 'ZOB': 'Cleveland', 'ZAU': 'Chicago',
            'ZMP': 'Minneapolis', 'ZKC': 'Kansas City', 'ZME': 'Memphis',
            'ZFW': 'Fort Worth', 'ZHU': 'Houston', 'ZAB': 'Albuquerque',
            'ZDV': 'Denver', 'ZLC': 'Salt Lake', 'ZLA': 'Los Angeles',
            'ZOA': 'Oakland', 'ZSE': 'Seattle', 'ZAN': 'Anchorage',
            'ZHN': 'Honolulu',
            // Canadian FIRs
            'CZYZ': 'Toronto', 'CZUL': 'Montreal', 'CZZV': 'Sept-Iles',
            'CZQM': 'Moncton', 'CZQX': 'Gander Domestic', 'CZQO': 'Gander Oceanic',
            'CZWG': 'Winnipeg', 'CZEG': 'Edmonton', 'CZVR': 'Vancouver',
        },
    },

    // DCC Regions (matches nod.js DCC_REGIONS)
    dccRegion: {
        colors: {
            'WEST': '#dc3545',           // Red - ZAK, ZAN, ZHN, ZLA, ZLC, ZOA, ZSE
            'SOUTH_CENTRAL': '#fd7e14',  // Orange - ZAB, ZFW, ZHO, ZHU, ZME
            'MIDWEST': '#28a745',        // Green - ZAU, ZDV, ZKC, ZMP
            'SOUTHEAST': '#ffc107',      // Yellow - ZID, ZJX, ZMA, ZMO, ZTL
            'NORTHEAST': '#007bff',      // Blue - ZBW, ZDC, ZNY, ZOB, ZWY
            'CANADA_EAST': '#9b59b6',    // Purple - CZYZ, CZUL, CZZV, CZQM, CZQX, CZQO
            'CANADA_WEST': '#ff69b4',    // Pink - CZWG, CZEG, CZVR
            'OTHER': '#6c757d',
            'UNKNOWN': '#adb5bd',
        },
        labels: {
            'WEST': 'West', 'SOUTH_CENTRAL': 'South Central', 'MIDWEST': 'Midwest',
            'SOUTHEAST': 'Southeast', 'NORTHEAST': 'Northeast',
            'CANADA_EAST': 'Canada East', 'CANADA_WEST': 'Canada West',
            'OTHER': 'Other', 'UNKNOWN': 'Unknown',
        },
        // Map ARTCCs/FIRs to DCC regions
        mapping: {
            // DCC West (Red)
            'ZAK': 'WEST', 'ZAN': 'WEST', 'ZHN': 'WEST', 'ZLA': 'WEST',
            'ZLC': 'WEST', 'ZOA': 'WEST', 'ZSE': 'WEST',
            // DCC South Central (Orange)
            'ZAB': 'SOUTH_CENTRAL', 'ZFW': 'SOUTH_CENTRAL', 'ZHO': 'SOUTH_CENTRAL',
            'ZHU': 'SOUTH_CENTRAL', 'ZME': 'SOUTH_CENTRAL',
            // DCC Midwest (Green)
            'ZAU': 'MIDWEST', 'ZDV': 'MIDWEST', 'ZKC': 'MIDWEST', 'ZMP': 'MIDWEST',
            // DCC Southeast (Yellow)
            'ZID': 'SOUTHEAST', 'ZJX': 'SOUTHEAST', 'ZMA': 'SOUTHEAST',
            'ZMO': 'SOUTHEAST', 'ZTL': 'SOUTHEAST',
            // DCC Northeast (Blue)
            'ZBW': 'NORTHEAST', 'ZDC': 'NORTHEAST', 'ZNY': 'NORTHEAST',
            'ZOB': 'NORTHEAST', 'ZWY': 'NORTHEAST',
            // Canada East (Purple)
            'CZYZ': 'CANADA_EAST', 'CZUL': 'CANADA_EAST', 'CZZV': 'CANADA_EAST',
            'CZQM': 'CANADA_EAST', 'CZQX': 'CANADA_EAST', 'CZQO': 'CANADA_EAST',
            // Canada West (Pink)
            'CZWG': 'CANADA_WEST', 'CZEG': 'CANADA_WEST', 'CZVR': 'CANADA_WEST',
        },
    },

    // TRACON coloring - inherit from parent ARTCC's DCC region
    // Use getDCCRegionColor(artcc) to get color for TRACONs
    tracon: {
        // Map TRACONs to their parent ARTCC, then use dccRegion.mapping
        getColor: function(traconId, traconToArtccMap) {
            const artcc = traconToArtccMap[traconId];
            if (!artcc) {return FILTER_CONFIG.dccRegion.colors['OTHER'];}
            const region = FILTER_CONFIG.dccRegion.mapping[artcc];
            return FILTER_CONFIG.dccRegion.colors[region] || FILTER_CONFIG.dccRegion.colors['OTHER'];
        },
    },

    // Airport coloring - inherit from parent ARTCC's DCC region
    // Use getDCCRegionColor(artcc) to get color for airports
    airport: {
        // Map airports to their ARTCC, then use dccRegion.mapping
        getColor: function(airportId, airportToArtccMap) {
            const artcc = airportToArtccMap[airportId];
            if (!artcc) {return FILTER_CONFIG.dccRegion.colors['OTHER'];}
            const region = FILTER_CONFIG.dccRegion.mapping[artcc];
            return FILTER_CONFIG.dccRegion.colors[region] || FILTER_CONFIG.dccRegion.colors['OTHER'];
        },
    },

    // Procedures (DP/STAR) - dynamic colors, use generator
    procedure: {
        // Generate colors dynamically based on procedure name hash
        getColor: function(name) {
            if (!name) {return '#6c757d';}
            let hash = 0;
            for (let i = 0; i < name.length; i++) {
                hash = name.charCodeAt(i) + ((hash << 5) - hash);
            }
            const hue = Math.abs(hash % 360);
            return `hsl(${hue}, 70%, 50%)`;
        },
    },

    // Fixes (departure/arrival) - dynamic colors
    fix: {
        getColor: function(name) {
            if (!name) {return '#6c757d';}
            let hash = 0;
            for (let i = 0; i < name.length; i++) {
                hash = name.charCodeAt(i) + ((hash << 5) - hash);
            }
            const hue = Math.abs(hash % 360);
            return `hsl(${hue}, 65%, 45%)`;
        },
    },

    // Map Visualization Colors
    map: {
        // Facility boundary colors (ARTCC/TRACON)
        facility: {
            provider: {
                fill: '#4dabf7',      // Blue
                fillOpacity: 0.1,
                stroke: '#4dabf7',
                strokeWidth: 2,
            },
            requestor: {
                fill: '#ff6b6b',      // Red
                fillOpacity: 0.1,
                stroke: '#ff6b6b',
                strokeWidth: 2,
            },
            default: {
                fill: '#888888',      // Gray
                fillOpacity: 0.1,
                stroke: '#888888',
                strokeWidth: 1,
            },
        },
        // Sector boundary colors by altitude
        sector: {
            low: {
                fill: '#868e96',      // Gray
                fillOpacity: 0.08,
                stroke: '#868e96',
                strokeWidth: 1,
                strokeDash: [4, 2],
            },
            high: {
                fill: '#4dabf7',      // Blue
                fillOpacity: 0.08,
                stroke: '#4dabf7',
                strokeWidth: 1.5,
            },
            superhigh: {
                fill: '#228be6',      // Dark blue
                fillOpacity: 0.06,
                stroke: '#228be6',
                strokeWidth: 1,
                strokeDash: [2, 2],
            },
        },
        // Flow Cone (Traffic Sector) colors
        flowCone: {
            '75': {
                fill: 'rgba(255, 212, 59, 0.15)',  // Yellow with transparency
                stroke: '#ffd43b',                  // Solid yellow border
                strokeWidth: 2,
            },
            '90': {
                fill: 'rgba(255, 146, 43, 0.10)',  // Orange with transparency
                stroke: '#ff922b',                  // Solid orange border
                strokeWidth: 1,
            },
        },
        // Spacing markers (arcs/crossings)
        spacing: {
            line: '#ffffff',
            lineOpacity: 0.6,
            label: '#ffffff',
            labelHalo: '#000000',
        },
        // Flow stream colors (DBSCAN clustered streams)
        streamPalette: [
            '#3498db', // Blue
            '#e74c3c', // Red
            '#2ecc71', // Green
            '#9b59b6', // Purple
            '#f39c12', // Orange
            '#1abc9c', // Teal
            '#e67e22', // Dark orange
            '#34495e', // Dark gray-blue
        ],
        // Track density color ramp (blue-cold to red-hot)
        densityRamp: [
            { stop: 0.0, color: '#3b4cc0' },   // Blue (sparse)
            { stop: 0.2, color: '#6788ee' },   // Light blue
            { stop: 0.4, color: '#9abbff' },   // Cyan-ish
            { stop: 0.5, color: '#c9d7f0' },   // Light gray-blue (neutral)
            { stop: 0.6, color: '#edd1c2' },   // Light peach
            { stop: 0.7, color: '#f7a789' },   // Orange
            { stop: 0.85, color: '#e26952' },  // Red-orange
            { stop: 1.0, color: '#b40426' },   // Dark red (busy)
        ],
        // UI text colors
        ui: {
            legendText: 'var(--dark-text-muted, #adb5bd)',
            mutedText: 'var(--dark-text-subtle, #6c757d)',
        },
    },
};

// i18n keys for translatable filter labels
const FILTER_I18N_KEYS = {
    weightClass: {
        'J': 'weightClass.J',
        'H': 'weightClass.H',
        'L': 'weightClass.L',
        'S': 'weightClass.S',
        'UNKNOWN': 'common.unknown',
    },
    flightRule: {
        'I': 'flightRule.I',
        'V': 'flightRule.V',
    },
    dccRegion: {
        'WEST': 'dccRegion.west',
        'SOUTH_CENTRAL': 'dccRegion.southCentral',
        'MIDWEST': 'dccRegion.midwest',
        'SOUTHEAST': 'dccRegion.southeast',
        'NORTHEAST': 'dccRegion.northeast',
        'CANADA_EAST': 'dccRegion.canadaEast',
        'CANADA_WEST': 'dccRegion.canadaWest',
        'OTHER': 'common.other',
        'UNKNOWN': 'common.unknown',
    },
};

// Helper functions
function getFilterColor(category, value) {
    const cfg = FILTER_CONFIG[category];
    if (!cfg) {return '#6c757d';}
    if (cfg.colors) {return cfg.colors[value] || cfg.colors['OTHER'] || '#6c757d';}
    if (cfg.getColor) {return cfg.getColor(value);}
    return '#6c757d';
}

function getFilterLabel(category, value) {
    const cfg = FILTER_CONFIG[category];
    if (!cfg || !cfg.labels) {return value || 'Unknown';}

    // Try i18n first if available
    if (typeof PERTII18n !== 'undefined' && FILTER_I18N_KEYS[category]) {
        const i18nKey = FILTER_I18N_KEYS[category][value];
        if (i18nKey) {
            return PERTII18n.t(i18nKey);
        }
    }

    // Fallback to hardcoded labels
    return cfg.labels[value] || value;
}

/**
 * Get DCC region color for any ARTCC/FIR code
 * Use this for TRACONs and airports by passing their parent ARTCC
 * @param {string} artcc - ARTCC or FIR code (e.g., 'ZNY', 'CZYZ')
 * @returns {string} Hex color code based on DCC region
 */
function getDCCRegionColor(artcc) {
    const region = FILTER_CONFIG.dccRegion.mapping[artcc];
    return FILTER_CONFIG.dccRegion.colors[region] || FILTER_CONFIG.dccRegion.colors['OTHER'];
}

/**
 * Get DCC region name for any ARTCC/FIR code
 * @param {string} artcc - ARTCC or FIR code
 * @returns {string} Region name (e.g., 'Northeast', 'Canada East')
 */
function getDCCRegion(artcc) {
    const region = FILTER_CONFIG.dccRegion.mapping[artcc];
    return FILTER_CONFIG.dccRegion.labels[region] || 'Other';
}

// Export for use in other scripts (if using modules)
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        FILTER_CONFIG,
        FILTER_I18N_KEYS,
        getFilterColor,
        getFilterLabel,
        getDCCRegionColor,
        getDCCRegion,
    };
}
