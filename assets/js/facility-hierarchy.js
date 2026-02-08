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
 * - assets/data/apts.csv (airport→ARTCC mappings)
 * - jatoc.php FIR definitions
 * - FAA OPSNET/ASPM (airport tier lists - Core30, OEP35, OPSNET45, ASPM82)
 *
 * NOTE: This module extends PERTI namespace data with operational metadata.
 * Load lib/perti.js before this file for full integration.
 *
 * Usage: Include this file before any scripts that need facility data
 *
 * @package PERTI
 * @subpackage Assets/JS
 * @version 1.2.0
 * @date 2026-02-03
 */

(function(global) {
    'use strict';

    // Reference to PERTI namespace if available
    const _PERTI = (typeof PERTI !== 'undefined') ? PERTI : null;

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
        'TJZS', 'MKJK', 'MUFH', 'MYNA', 'MDCS', 'MTEG', 'TNCF', 'TTZP', 'MHCC', 'MPZL',
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
        'ZHN': ['PHZH'],            // Honolulu
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
    // Extended from PERTI.GEOGRAPHIC.DCC_REGIONS with UI metadata
    // ===========================================

    // Build DCC_REGIONS by extending PERTI data with UI-specific metadata
    const DCC_REGIONS = (function() {
        // UI metadata for each region (bgColor, textClass not in PERTI)
        const UI_METADATA = {
            'WEST': { bgColor: 'rgba(220, 53, 69, 0.15)', textClass: 'text-danger' },
            'SOUTH_CENTRAL': { bgColor: 'rgba(253, 126, 20, 0.15)', textClass: 'text-warning' },
            'MIDWEST': { bgColor: 'rgba(40, 167, 69, 0.15)', textClass: 'text-success' },
            'SOUTHEAST': { bgColor: 'rgba(255, 193, 7, 0.15)', textClass: 'text-warning' },
            'NORTHEAST': { bgColor: 'rgba(0, 123, 255, 0.15)', textClass: 'text-primary' },
            'CANADA': { bgColor: 'rgba(111, 66, 193, 0.15)', textClass: 'text-purple' },
            'OTHER': { bgColor: 'rgba(108, 117, 125, 0.15)', textClass: 'text-muted' },
        };

        // Additional regions not in PERTI (MEXICO, CARIBBEAN)
        const EXTENDED_REGIONS = {
            'MEXICO': {
                name: 'Mexico',
                artccs: ['MMFR', 'MMFO'],
                color: '#8B4513',
                bgColor: 'rgba(139, 69, 19, 0.15)',
                textClass: 'text-brown',
            },
            'CARIBBEAN': {
                name: 'Caribbean',
                artccs: ['TJZS', 'MKJK', 'MUFH', 'MYNA', 'MDCS', 'MTEG', 'TNCF', 'TTZP', 'MHCC', 'MPZL'],
                color: '#e83e8c',
                bgColor: 'rgba(232, 62, 140, 0.15)',
                textClass: 'text-pink',
            },
        };

        // If PERTI is available, build from it
        if (_PERTI && _PERTI.GEOGRAPHIC && _PERTI.GEOGRAPHIC.DCC_REGIONS) {
            const regions = {};
            Object.entries(_PERTI.GEOGRAPHIC.DCC_REGIONS).forEach(([key, data]) => {
                // WEST region in facility-hierarchy has extra ARTCCs (ZAP, ZUA)
                let artccs = [...data.artccs];
                if (key === 'WEST') {
                    artccs = ['ZAK', 'ZAN', 'ZAP', 'ZHN', 'ZLA', 'ZLC', 'ZOA', 'ZSE', 'ZUA'];
                }
                // CANADA region — no overrides needed (CZZV removed as non-FIR)
                regions[key] = {
                    name: data.name,
                    artccs: artccs,
                    color: data.color,
                    ...(UI_METADATA[key] || {}),
                };
            });
            // Add extended regions
            return { ...regions, ...EXTENDED_REGIONS };
        }

        // Fallback when PERTI not loaded
        return {
            'SOUTH_CENTRAL': {
                name: 'South Central',
                artccs: ['ZAB', 'ZFW', 'ZHO', 'ZHU', 'ZME'],
                color: '#fd7e14',
                bgColor: 'rgba(253, 126, 20, 0.15)',
                textClass: 'text-warning',
            },
            'SOUTHEAST': {
                name: 'Southeast',
                artccs: ['ZID', 'ZJX', 'ZMA', 'ZMO', 'ZTL'],
                color: '#ffc107',
                bgColor: 'rgba(255, 193, 7, 0.15)',
                textClass: 'text-warning',
            },
            'NORTHEAST': {
                name: 'Northeast',
                artccs: ['ZBW', 'ZDC', 'ZNY', 'ZOB', 'ZWY'],
                color: '#007bff',
                bgColor: 'rgba(0, 123, 255, 0.15)',
                textClass: 'text-primary',
            },
            'MIDWEST': {
                name: 'Midwest',
                artccs: ['ZAU', 'ZDV', 'ZKC', 'ZMP'],
                color: '#28a745',
                bgColor: 'rgba(40, 167, 69, 0.15)',
                textClass: 'text-success',
            },
            'WEST': {
                name: 'West',
                artccs: ['ZAK', 'ZAN', 'ZAP', 'ZHN', 'ZLA', 'ZLC', 'ZOA', 'ZSE', 'ZUA'],
                color: '#dc3545',
                bgColor: 'rgba(220, 53, 69, 0.15)',
                textClass: 'text-danger',
            },
            'CANADA': {
                name: 'Canada',
                artccs: ['CZEG', 'CZQM', 'CZQO', 'CZQX', 'CZUL', 'CZVR', 'CZWG', 'CZYZ'],
                color: '#6f42c1',
                bgColor: 'rgba(111, 66, 193, 0.15)',
                textClass: 'text-purple',
            },
            ...EXTENDED_REGIONS,
        };
    })();

    // ===========================================
    // International FIR Mapping (ICAO prefix → FIR)
    // Based on ICAO region letter assignments
    //
    // Authoritative Sources (for verification):
    // - https://github.com/vatsimnetwork/vatspy-data-project (VATSpy.dat [Airports] section)
    // - https://github.com/vatsimnetwork/simaware-tracon-project (TRACON boundaries)
    // - https://vatsim.dev/api/aip-api/get-airport (VATSIM AIP API)
    // ===========================================

    const ICAO_FIR_MAP = {
        // North America - US (K prefix handled by apts.csv)
        'K': { fir: null, region: 'US', note: 'Use apts.csv RESP_ARTCC_ID' },

        // North America - Canada (CY/CZ prefixes)
        'CY': { fir: null, region: 'Canada', note: 'Use apts.csv or FIR lookup' },
        'CZ': { fir: null, region: 'Canada', note: 'Canadian FIR codes' },

        // North America - Mexico
        'MM': { fir: 'MMFR', region: 'Mexico', name: 'Mexico FIR' },

        // Caribbean & Central America
        'MK': { fir: 'MKJK', region: 'Caribbean', name: 'Kingston FIR (Jamaica)' },
        'MU': { fir: 'MUFH', region: 'Caribbean', name: 'Havana FIR (Cuba)' },
        'MY': { fir: 'MYNA', region: 'Caribbean', name: 'Nassau FIR (Bahamas)' },
        'MD': { fir: 'MDCS', region: 'Caribbean', name: 'Santo Domingo FIR' },
        'MT': { fir: 'MTEG', region: 'Caribbean', name: 'Port-au-Prince FIR (Haiti)' },
        'TN': { fir: 'TNCF', region: 'Caribbean', name: 'Curaçao FIR' },
        'TT': { fir: 'TTZP', region: 'Caribbean', name: 'Piarco FIR (Trinidad)' },
        'TJ': { fir: 'TJZS', region: 'Caribbean', name: 'San Juan FIR (Puerto Rico)' },
        'MH': { fir: 'MHCC', region: 'Caribbean', name: 'Central America FIR (Honduras)' },
        'MP': { fir: 'MPZL', region: 'Caribbean', name: 'Panama FIR' },
        'MG': { fir: 'MGGT', region: 'Caribbean', name: 'Guatemala FIR' },
        'MN': { fir: 'MNMG', region: 'Caribbean', name: 'Managua FIR (Nicaragua)' },
        'MR': { fir: 'MRPV', region: 'Caribbean', name: 'San José FIR (Costa Rica)' },
        'MS': { fir: 'MSLP', region: 'Caribbean', name: 'San Salvador FIR' },
        'TB': { fir: 'TBPB', region: 'Caribbean', name: 'Barbados FIR' },

        // South America
        'SA': { fir: 'SACF', region: 'South America', name: 'Argentina FIR' },
        'SB': { fir: 'SBCW', region: 'South America', name: 'Brazil FIR (Curitiba)' },
        'SC': { fir: 'SCFZ', region: 'South America', name: 'Chile FIR' },
        'SE': { fir: 'SEGU', region: 'South America', name: 'Ecuador FIR (Guayaquil)' },
        'SK': { fir: 'SKED', region: 'South America', name: 'Colombia FIR (Bogotá)' },
        'SL': { fir: 'SLVR', region: 'South America', name: 'Bolivia FIR' },
        'SM': { fir: 'SMPM', region: 'South America', name: 'Suriname FIR' },
        'SP': { fir: 'SPIM', region: 'South America', name: 'Peru FIR (Lima)' },
        'SV': { fir: 'SVZM', region: 'South America', name: 'Venezuela FIR (Maiquetía)' },
        'SY': { fir: 'SYGC', region: 'South America', name: 'Guyana FIR' },
        'SU': { fir: 'SUEO', region: 'South America', name: 'Uruguay FIR (Montevideo)' },

        // North Atlantic
        'BI': { fir: 'BIRD', region: 'North Atlantic', name: 'Reykjavik FIR (Iceland)' },
        'BG': { fir: 'BGGL', region: 'North Atlantic', name: 'Sondrestrom FIR (Greenland)' },
        'EK': { fir: 'EKDK', region: 'North Atlantic', name: 'Copenhagen FIR (Denmark)' },

        // Europe - Northern
        'EG': { fir: 'EGTT', region: 'Europe', name: 'London FIR (UK)' },
        'EI': { fir: 'EISN', region: 'Europe', name: 'Shannon FIR (Ireland)' },
        'EN': { fir: 'ENOR', region: 'Europe', name: 'Norway FIR' },
        'ES': { fir: 'ESAA', region: 'Europe', name: 'Sweden FIR' },
        'EF': { fir: 'EFIN', region: 'Europe', name: 'Finland FIR' },
        'EE': { fir: 'EETT', region: 'Europe', name: 'Tallinn FIR (Estonia)' },
        'EV': { fir: 'EVRR', region: 'Europe', name: 'Riga FIR (Latvia)' },
        'EY': { fir: 'EYVL', region: 'Europe', name: 'Vilnius FIR (Lithuania)' },

        // Europe - Central
        'ED': { fir: 'EDGG', region: 'Europe', name: 'Germany FIR' },
        'EH': { fir: 'EHAA', region: 'Europe', name: 'Amsterdam FIR (Netherlands)' },
        'EB': { fir: 'EBBU', region: 'Europe', name: 'Brussels FIR (Belgium)' },
        'EL': { fir: 'ELLX', region: 'Europe', name: 'Luxembourg FIR' },
        'EP': { fir: 'EPWW', region: 'Europe', name: 'Warsaw FIR (Poland)' },
        'LK': { fir: 'LKAA', region: 'Europe', name: 'Prague FIR (Czech Republic)' },
        'LO': { fir: 'LOVV', region: 'Europe', name: 'Vienna FIR (Austria)' },
        'LS': { fir: 'LSAS', region: 'Europe', name: 'Switzerland FIR' },
        'LH': { fir: 'LHCC', region: 'Europe', name: 'Budapest FIR (Hungary)' },
        'LZ': { fir: 'LZBB', region: 'Europe', name: 'Bratislava FIR (Slovakia)' },

        // Europe - Southern
        'LF': { fir: 'LFFF', region: 'Europe', name: 'Paris FIR (France)' },
        'LE': { fir: 'LECM', region: 'Europe', name: 'Madrid FIR (Spain)' },
        'LP': { fir: 'LPPC', region: 'Europe', name: 'Lisbon FIR (Portugal)' },
        'LI': { fir: 'LIRR', region: 'Europe', name: 'Rome FIR (Italy)' },
        'LG': { fir: 'LGGG', region: 'Europe', name: 'Athens FIR (Greece)' },
        'LT': { fir: 'LTAA', region: 'Europe', name: 'Ankara FIR (Turkey)' },
        'LC': { fir: 'LCCC', region: 'Europe', name: 'Nicosia FIR (Cyprus)' },
        'LM': { fir: 'LMMM', region: 'Europe', name: 'Malta FIR' },
        'LA': { fir: 'LAAA', region: 'Europe', name: 'Tirana FIR (Albania)' },
        'LY': { fir: 'LYBA', region: 'Europe', name: 'Belgrade FIR (Serbia)' },
        'LD': { fir: 'LDZO', region: 'Europe', name: 'Zagreb FIR (Croatia)' },
        'LJ': { fir: 'LJLA', region: 'Europe', name: 'Ljubljana FIR (Slovenia)' },
        'LW': { fir: 'LWSK', region: 'Europe', name: 'Skopje FIR (N. Macedonia)' },
        'LQ': { fir: 'LQSB', region: 'Europe', name: 'Sarajevo FIR (Bosnia)' },
        'LU': { fir: 'LUUU', region: 'Europe', name: 'Chisinau FIR (Moldova)' },
        'LR': { fir: 'LRBB', region: 'Europe', name: 'Bucharest FIR (Romania)' },
        'LB': { fir: 'LBSR', region: 'Europe', name: 'Sofia FIR (Bulgaria)' },

        // Europe - Eastern / Russia
        'UK': { fir: 'UKBV', region: 'Europe', name: 'Kyiv FIR (Ukraine)' },
        'UL': { fir: 'ULMM', region: 'Russia', name: 'Murmansk FIR' },
        'UU': { fir: 'UUWV', region: 'Russia', name: 'Moscow FIR' },
        'UR': { fir: 'URRV', region: 'Russia', name: 'Rostov FIR' },
        'UW': { fir: 'UWWW', region: 'Russia', name: 'Samara FIR' },
        'US': { fir: 'USSS', region: 'Russia', name: 'Yekaterinburg FIR' },
        'UN': { fir: 'UNNT', region: 'Russia', name: 'Novosibirsk FIR' },
        'UH': { fir: 'UHHH', region: 'Russia', name: 'Khabarovsk FIR' },

        // Middle East
        'OE': { fir: 'OEJD', region: 'Middle East', name: 'Jeddah FIR (Saudi Arabia)' },
        'OO': { fir: 'OOMM', region: 'Middle East', name: 'Muscat FIR (Oman)' },
        'OM': { fir: 'OMAE', region: 'Middle East', name: 'Emirates FIR (UAE)' },
        'OB': { fir: 'OBBB', region: 'Middle East', name: 'Bahrain FIR' },
        'OK': { fir: 'OKAC', region: 'Middle East', name: 'Kuwait FIR' },
        'OT': { fir: 'OTBD', region: 'Middle East', name: 'Doha FIR (Qatar)' },
        'OI': { fir: 'OIIX', region: 'Middle East', name: 'Tehran FIR (Iran)' },
        'OJ': { fir: 'OJAC', region: 'Middle East', name: 'Amman FIR (Jordan)' },
        'OS': { fir: 'OSTT', region: 'Middle East', name: 'Damascus FIR (Syria)' },
        'OL': { fir: 'OLBB', region: 'Middle East', name: 'Beirut FIR (Lebanon)' },
        'OR': { fir: 'ORBB', region: 'Middle East', name: 'Baghdad FIR (Iraq)' },
        'LL': { fir: 'LLLL', region: 'Middle East', name: 'Tel Aviv FIR (Israel)' },
        'OY': { fir: 'OYSC', region: 'Middle East', name: 'Sana\'a FIR (Yemen)' },

        // Africa - North
        'HE': { fir: 'HECC', region: 'Africa', name: 'Cairo FIR (Egypt)' },
        'HL': { fir: 'HLLL', region: 'Africa', name: 'Tripoli FIR (Libya)' },
        'DT': { fir: 'DTTC', region: 'Africa', name: 'Tunis FIR (Tunisia)' },
        'DA': { fir: 'DAAA', region: 'Africa', name: 'Algiers FIR (Algeria)' },
        'GM': { fir: 'GMMM', region: 'Africa', name: 'Casablanca FIR (Morocco)' },
        'GC': { fir: 'GCCC', region: 'Africa', name: 'Canarias FIR (Spain)' },

        // Africa - West
        'GO': { fir: 'GOOO', region: 'Africa', name: 'Dakar FIR (Senegal)' },
        'GU': { fir: 'GUCY', region: 'Africa', name: 'Conakry FIR (Guinea)' },
        'GF': { fir: 'GFLL', region: 'Africa', name: 'Freetown FIR (Sierra Leone)' },
        'GL': { fir: 'GLRB', region: 'Africa', name: 'Roberts FIR (Liberia)' },
        'DG': { fir: 'DGAC', region: 'Africa', name: 'Accra FIR (Ghana)' },
        'DB': { fir: 'DBBB', region: 'Africa', name: 'Cotonou FIR (Benin)' },
        'DN': { fir: 'DNKK', region: 'Africa', name: 'Kano FIR (Nigeria)' },
        'DF': { fir: 'DRRR', region: 'Africa', name: 'Niamey FIR (Niger)' },
        'DX': { fir: 'DXXX', region: 'Africa', name: 'Lomé FIR (Togo)' },

        // Africa - Central
        'FT': { fir: 'FTTT', region: 'Africa', name: 'N\'Djamena FIR (Chad)' },
        'FK': { fir: 'FKKK', region: 'Africa', name: 'Douala FIR (Cameroon)' },
        'FG': { fir: 'FGSL', region: 'Africa', name: 'Malabo FIR (Equatorial Guinea)' },
        'FO': { fir: 'FOON', region: 'Africa', name: 'Brazzaville FIR (Congo)' },
        'FZ': { fir: 'FZZA', region: 'Africa', name: 'Kinshasa FIR (DR Congo)' },
        'FC': { fir: 'FCCC', region: 'Africa', name: 'Central African Republic FIR' },

        // Africa - East
        'HA': { fir: 'HAAA', region: 'Africa', name: 'Addis Ababa FIR (Ethiopia)' },
        'HK': { fir: 'HKNA', region: 'Africa', name: 'Nairobi FIR (Kenya)' },
        'HU': { fir: 'HUEN', region: 'Africa', name: 'Entebbe FIR (Uganda)' },
        'HR': { fir: 'HRYR', region: 'Africa', name: 'Kigali FIR (Rwanda)' },
        'HT': { fir: 'HTDC', region: 'Africa', name: 'Dar es Salaam FIR (Tanzania)' },
        'HS': { fir: 'HSSS', region: 'Africa', name: 'Khartoum FIR (Sudan)' },
        'HD': { fir: 'HDAL', region: 'Africa', name: 'Djibouti FIR' },
        'HC': { fir: 'HCSM', region: 'Africa', name: 'Mogadishu FIR (Somalia)' },

        // Africa - Southern
        'FA': { fir: 'FAJA', region: 'Africa', name: 'Johannesburg FIR (South Africa)' },
        'FQ': { fir: 'FQBE', region: 'Africa', name: 'Beira FIR (Mozambique)' },
        'FV': { fir: 'FVHA', region: 'Africa', name: 'Harare FIR (Zimbabwe)' },
        'FB': { fir: 'FBGR', region: 'Africa', name: 'Gaborone FIR (Botswana)' },
        'FW': { fir: 'FWLL', region: 'Africa', name: 'Lilongwe FIR (Malawi)' },
        'FL': { fir: 'FLFI', region: 'Africa', name: 'Lusaka FIR (Zambia)' },
        'FY': { fir: 'FYWH', region: 'Africa', name: 'Windhoek FIR (Namibia)' },
        'FX': { fir: 'FXMM', region: 'Africa', name: 'Lesotho FIR' },
        'FD': { fir: 'FDMS', region: 'Africa', name: 'Eswatini FIR' },
        'FM': { fir: 'FMMI', region: 'Africa', name: 'Antananarivo FIR (Madagascar)' },
        'FN': { fir: 'FNLU', region: 'Africa', name: 'Luanda FIR (Angola)' },
        'FH': { fir: 'FHAW', region: 'Africa', name: 'Ascension FIR' },

        // Indian Ocean
        'FI': { fir: 'FIMM', region: 'Indian Ocean', name: 'Mauritius FIR' },
        'FS': { fir: 'FSSS', region: 'Indian Ocean', name: 'Seychelles FIR' },
        'FR': { fir: 'FRRR', region: 'Indian Ocean', name: 'Réunion FIR' },
        'VR': { fir: 'VRMF', region: 'Indian Ocean', name: 'Maldives FIR' },

        // South Asia
        'VA': { fir: 'VABF', region: 'South Asia', name: 'Mumbai FIR (India)' },
        'VE': { fir: 'VECF', region: 'South Asia', name: 'Kolkata FIR (India)' },
        'VI': { fir: 'VIDF', region: 'South Asia', name: 'Delhi FIR (India)' },
        'VO': { fir: 'VOMF', region: 'South Asia', name: 'Chennai FIR (India)' },
        'VC': { fir: 'VCCF', region: 'South Asia', name: 'Colombo FIR (Sri Lanka)' },
        'VN': { fir: 'VNKT', region: 'South Asia', name: 'Kathmandu FIR (Nepal)' },
        'VQ': { fir: 'VQPR', region: 'South Asia', name: 'Bhutan FIR' },
        'VG': { fir: 'VGFR', region: 'South Asia', name: 'Dhaka FIR (Bangladesh)' },
        'OP': { fir: 'OPLR', region: 'South Asia', name: 'Lahore FIR (Pakistan)' },

        // Southeast Asia
        'VT': { fir: 'VTBB', region: 'Southeast Asia', name: 'Bangkok FIR (Thailand)' },
        'VL': { fir: 'VLVT', region: 'Southeast Asia', name: 'Vientiane FIR (Laos)' },
        'VV': { fir: 'VVHN', region: 'Southeast Asia', name: 'Hanoi FIR (Vietnam)' },
        'VY': { fir: 'VYYY', region: 'Southeast Asia', name: 'Yangon FIR (Myanmar)' },
        'WM': { fir: 'WMFC', region: 'Southeast Asia', name: 'Kuala Lumpur FIR (Malaysia)' },
        'WS': { fir: 'WSJC', region: 'Southeast Asia', name: 'Singapore FIR' },
        'WI': { fir: 'WIIF', region: 'Southeast Asia', name: 'Jakarta FIR (Indonesia)' },
        'WA': { fir: 'WAAF', region: 'Southeast Asia', name: 'Ujung Pandang FIR (Indonesia)' },
        'WB': { fir: 'WBFC', region: 'Southeast Asia', name: 'Kota Kinabalu FIR (Malaysia)' },
        'WR': { fir: 'WRRR', region: 'Southeast Asia', name: 'Bali FIR (Indonesia)' },
        'RP': { fir: 'RPHI', region: 'Southeast Asia', name: 'Manila FIR (Philippines)' },

        // East Asia
        'Z': { fir: null, region: 'China', note: 'Multiple FIRs - use 2-letter prefix' },
        'ZB': { fir: 'ZBPE', region: 'East Asia', name: 'Beijing FIR (China)' },
        'ZS': { fir: 'ZSHA', region: 'East Asia', name: 'Shanghai FIR (China)' },
        'ZG': { fir: 'ZGZU', region: 'East Asia', name: 'Guangzhou FIR (China)' },
        'ZH': { fir: 'ZHWH', region: 'East Asia', name: 'Wuhan FIR (China)' },
        'ZU': { fir: 'ZUUU', region: 'East Asia', name: 'Chengdu FIR (China)' },
        'ZY': { fir: 'ZYSH', region: 'East Asia', name: 'Shenyang FIR (China)' },
        'ZL': { fir: 'ZLHW', region: 'East Asia', name: 'Lanzhou FIR (China)' },
        'ZK': { fir: 'ZKPY', region: 'East Asia', name: 'Pyongyang FIR (North Korea)' },
        'RK': { fir: 'RKRR', region: 'East Asia', name: 'Incheon FIR (South Korea)' },
        'RJ': { fir: 'RJJJ', region: 'East Asia', name: 'Tokyo FIR (Japan)' },
        'RO': { fir: 'RORK', region: 'East Asia', name: 'Naha FIR (Okinawa/Japan)' },
        'RC': { fir: 'RCAA', region: 'East Asia', name: 'Taipei FIR (Taiwan)' },
        'VH': { fir: 'VHHK', region: 'East Asia', name: 'Hong Kong FIR' },
        'VM': { fir: 'VMMC', region: 'East Asia', name: 'Macau FIR' },
        'UB': { fir: 'UBRR', region: 'East Asia', name: 'Ulaanbaatar FIR (Mongolia)' },

        // Pacific
        'PH': { fir: 'ZHN', region: 'Pacific', name: 'Honolulu FIR (Hawaii)' },
        'PA': { fir: 'ZAN', region: 'Pacific', name: 'Anchorage FIR (Alaska)' },
        'PG': { fir: 'PGUM', region: 'Pacific', name: 'Guam FIR' },
        'PW': { fir: 'PWUZ', region: 'Pacific', name: 'Wake Island FIR' },
        'PK': { fir: 'PKMJ', region: 'Pacific', name: 'Marshall Islands FIR' },
        'PT': { fir: 'PTAA', region: 'Pacific', name: 'Palau FIR' },
        'NF': { fir: 'NFFF', region: 'Pacific', name: 'Nadi FIR (Fiji)' },
        'NW': { fir: 'NWWW', region: 'Pacific', name: 'Noumea FIR (New Caledonia)' },
        'NC': { fir: 'NCRG', region: 'Pacific', name: 'Rarotonga FIR (Cook Islands)' },
        'NS': { fir: 'NSFA', region: 'Pacific', name: 'Samoa FIR' },
        'NI': { fir: 'NIUE', region: 'Pacific', name: 'Niue FIR' },
        'NT': { fir: 'NTTT', region: 'Pacific', name: 'Tahiti FIR (French Polynesia)' },
        'NZ': { fir: 'NZZO', region: 'Pacific', name: 'Auckland FIR (New Zealand)' },

        // Australia (Y prefix, second letter indicates state/region)
        'Y': { fir: null, region: 'Australia', note: 'Multiple FIRs - use 2-letter prefix' },
        'YM': { fir: 'YMMM', region: 'Australia', name: 'Melbourne FIR' },
        'YB': { fir: 'YBBB', region: 'Australia', name: 'Brisbane FIR' },
        'YS': { fir: 'YMMM', region: 'Australia', name: 'Sydney FIR (Melbourne)' },
        'YP': { fir: 'YMMM', region: 'Australia', name: 'Perth FIR (Melbourne)' },
        'YC': { fir: 'YBBB', region: 'Australia', name: 'Canberra FIR (Brisbane)' },

        // Default catch-all for unknown prefixes
        'DEFAULT': { fir: null, region: 'Unknown', note: 'Unknown ICAO prefix' },
    };

    /**
     * Get FIR code for an airport based on ICAO code
     * @param {string} icao - ICAO airport code (e.g., 'EGLL', 'KJFK')
     * @returns {object} { fir: string, region: string, name: string, source: string }
     */
    function getInternationalFIR(icao) {
        if (!icao || typeof icao !== 'string') {
            return { fir: null, region: 'Unknown', name: null, source: 'invalid' };
        }

        const upper = icao.toUpperCase().trim();

        // First check if it's in our domestic data (US/Canada airports)
        if (AIRPORT_TO_ARTCC[upper]) {
            const artcc = AIRPORT_TO_ARTCC[upper];
            return {
                fir: artcc,
                region: ARTCC_TO_REGION[artcc] || 'US',
                name: null,
                source: 'apts.csv'
            };
        }

        // Try 2-letter prefix first (more specific)
        const prefix2 = upper.substring(0, 2);
        if (ICAO_FIR_MAP[prefix2]) {
            const data = ICAO_FIR_MAP[prefix2];
            return {
                fir: data.fir,
                region: data.region,
                name: data.name,
                source: 'icao_prefix'
            };
        }

        // Fall back to 1-letter prefix
        const prefix1 = upper.substring(0, 1);
        if (ICAO_FIR_MAP[prefix1]) {
            const data = ICAO_FIR_MAP[prefix1];
            return {
                fir: data.fir,
                region: data.region,
                name: data.name || data.note,
                source: 'icao_prefix'
            };
        }

        return { fir: null, region: 'Unknown', name: null, source: 'not_found' };
    }

    // ===========================================
    // Facility Emoji Mapping (for Discord reactions)
    // Alternate method for TMI coordination approvals
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
        'CZUL': '8️⃣',   // 8 - Montreal
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
            artccs: ['ZLA', 'ZLC', 'ZDV', 'ZOA', 'ZAB', 'ZSE'],
        },
        '10WEST': {
            name: '10 West',
            description: 'Ten western ARTCCs',
            artccs: ['ZAB', 'ZDV', 'ZFW', 'ZHU', 'ZKC', 'ZLA', 'ZLC', 'ZMP', 'ZOA', 'ZSE'],
        },
        '12WEST': {
            name: '12 West',
            description: 'Twelve western/central ARTCCs',
            artccs: ['ZAB', 'ZAU', 'ZDV', 'ZFW', 'ZHU', 'ZKC', 'ZLA', 'ZLC', 'ZME', 'ZMP', 'ZOA', 'ZSE'],
        },
        'GULF': {
            name: 'Gulf',
            description: 'Gulf region',
            artccs: ['ZJX', 'ZMA', 'ZHU'],
        },
        'CANWEST': {
            name: 'Canada West',
            description: 'Western Canadian FIRs',
            artccs: ['CZVR', 'CZEG'],
        },
        'CANEAST': {
            name: 'Canada East',
            description: 'Eastern Canadian FIRs',
            artccs: ['CZWG', 'CZYZ', 'CZUL', 'CZQM'],
        },
    };

    // ===========================================
    // Quick-Select Facility Groups
    // ===========================================

    const FACILITY_GROUPS = {
        'US_CONUS': {
            name: 'CONUS (Lower 48)',
            artccs: ['ZAB', 'ZAU', 'ZBW', 'ZDC', 'ZDV', 'ZFW', 'ZHU', 'ZID', 'ZJX', 'ZKC',
                'ZLA', 'ZLC', 'ZMA', 'ZME', 'ZMP', 'ZNY', 'ZOA', 'ZOB', 'ZSE', 'ZTL'],
        },
        'US_ALL': {
            name: 'All US (incl. AK/HI/Oceanic)',
            artccs: ['ZAB', 'ZAN', 'ZAU', 'ZBW', 'ZDC', 'ZDV', 'ZFW', 'ZHN', 'ZHU', 'ZID',
                'ZJX', 'ZKC', 'ZLA', 'ZLC', 'ZMA', 'ZME', 'ZMP', 'ZNY', 'ZOA', 'ZOB',
                'ZSE', 'ZTL', 'ZHO', 'ZMO', 'ZWY', 'ZAK', 'ZAP', 'ZUA'],
        },
        'US_CANADA': {
            name: 'All US + Canada',
            artccs: ['ZAB', 'ZAN', 'ZAU', 'ZBW', 'ZDC', 'ZDV', 'ZFW', 'ZHN', 'ZHU', 'ZID',
                'ZJX', 'ZKC', 'ZLA', 'ZLC', 'ZMA', 'ZME', 'ZMP', 'ZNY', 'ZOA', 'ZOB',
                'ZSE', 'ZTL', 'ZHO', 'ZMO', 'ZWY', 'ZAK', 'ZAP', 'ZUA',
                'CZEG', 'CZVR', 'CZWG', 'CZYZ', 'CZQM', 'CZQX', 'CZQO', 'CZUL'],
        },
        '6WEST': {
            name: '6 West',
            artccs: ['ZLA', 'ZLC', 'ZDV', 'ZOA', 'ZAB', 'ZSE'],
        },
        '10WEST': {
            name: '10 West',
            artccs: ['ZAB', 'ZDV', 'ZFW', 'ZHU', 'ZKC', 'ZLA', 'ZLC', 'ZMP', 'ZOA', 'ZSE'],
        },
        '12WEST': {
            name: '12 West',
            artccs: ['ZAB', 'ZAU', 'ZDV', 'ZFW', 'ZHU', 'ZKC', 'ZLA', 'ZLC', 'ZME', 'ZMP', 'ZOA', 'ZSE'],
        },
        'GULF': {
            name: 'Gulf',
            artccs: ['ZJX', 'ZMA', 'ZHU'],
        },
    };

    // ===========================================
    // Airport Groups (FAA operational performance tiers)
    // Source: FAA OPSNET/ASPM - rarely changes
    // ===========================================

    const AIRPORT_GROUPS = {
        'CORE30': {
            name: 'Core 30',
            airports: [
                'KATL', 'KBOS', 'KBWI', 'KCLT', 'KDCA', 'KDEN', 'KDFW', 'KDTW', 'KEWR', 'KFLL',
                'KIAD', 'KIAH', 'KJFK', 'KLAS', 'KLAX', 'KLGA', 'KMCO', 'KMDW', 'KMEM', 'KMIA',
                'KMSP', 'KORD', 'KPHL', 'KPHX', 'KSAN', 'KSEA', 'KSFO', 'KSLC', 'KTPA', 'PHNL',
            ],
        },
        'OEP35': {
            name: 'OEP 35',
            airports: [
                'KATL', 'KBOS', 'KBWI', 'KCLE', 'KCLT', 'KCVG', 'KDCA', 'KDEN', 'KDFW', 'KDTW',
                'KEWR', 'KFLL', 'KIAD', 'KIAH', 'KJFK', 'KLAS', 'KLAX', 'KLGA', 'KMCO', 'KMDW',
                'KMEM', 'KMIA', 'KMSP', 'KORD', 'KPDX', 'KPHL', 'KPHX', 'KPIT', 'KSAN', 'KSEA',
                'KSFO', 'KSLC', 'KSTL', 'KTPA', 'PHNL',
            ],
        },
        'OPSNET45': {
            name: 'OPSNET 45',
            airports: [
                'KABQ', 'KATL', 'KBNA', 'KBOS', 'KBWI', 'KCLE', 'KCLT', 'KCVG', 'KDCA', 'KDEN',
                'KDFW', 'KDTW', 'KEWR', 'KFLL', 'KHOU', 'KIAD', 'KIAH', 'KIND', 'KJFK', 'KLAS',
                'KLAX', 'KLGA', 'KMCI', 'KMCO', 'KMDW', 'KMEM', 'KMIA', 'KMSP', 'KMSY', 'KOAK',
                'KORD', 'KPBI', 'KPDX', 'KPHL', 'KPHX', 'KPIT', 'KRDU', 'KSAN', 'KSEA', 'KSFO',
                'KSJC', 'KSLC', 'KSTL', 'KTEB', 'KTPA',
            ],
        },
        'ASPM82': {
            name: 'ASPM 82',
            airports: [
                'KABQ', 'PANC', 'KAPA', 'KASE', 'KATL', 'KAUS', 'KBDL', 'KBHM', 'KBJC', 'KBNA',
                'KBOI', 'KBOS', 'KBUF', 'KBUR', 'KBWI', 'KCLE', 'KCLT', 'KCMH', 'KCVG', 'KDAL',
                'KDAY', 'KDCA', 'KDEN', 'KDFW', 'KDTW', 'KEWR', 'KFLL', 'KGYY', 'PHNL', 'KHOU',
                'KHPN', 'KIAD', 'KIAH', 'KIND', 'KISP', 'KJAX', 'KJFK', 'KLAS', 'KLAX', 'KLGA',
                'KLGB', 'KMCI', 'KMCO', 'KMDW', 'KMEM', 'KMHT', 'KMIA', 'KMKE', 'KMSP', 'KMSY',
                'KOAK', 'PHOG', 'KOMA', 'KONT', 'KORD', 'KOXR', 'KPBI', 'KPDX', 'KPHL', 'KPHX',
                'KPIT', 'KPSP', 'KPVD', 'KRDU', 'KRFD', 'KRSW', 'KSAN', 'KSAT', 'KSDF', 'KSEA',
                'KSFO', 'KSJC', 'TJSJ', 'KSLC', 'KSMF', 'KSNA', 'KSTL', 'KSWF', 'KTEB', 'KTPA',
                'KTUS', 'KVNY',
            ],
        },
    };

    // ===========================================
    // Airport Tier Colors
    // ===========================================

    const AIRPORT_TIER_COLORS = {
        'CORE30': '#dc3545',    // Red - highest priority
        'OEP35': '#007bff',     // Blue
        'OPSNET45': '#17a2b8',  // Teal
        'ASPM82': '#ffc107',    // Yellow
        'OTHER': '#6c757d',     // Gray
    };

    // ===========================================
    // Carrier Classifications
    // Source: FAA OPSNET, consolidated from nod.js
    // ===========================================

    const MAJOR_CARRIERS = [
        'AAL', 'UAL', 'DAL', 'SWA', 'JBU', 'ASA', 'HAL', 'NKS', 'FFT', 'AAY', 'VXP', 'SYX',
    ];

    const REGIONAL_CARRIERS = [
        'SKW', 'RPA', 'ENY', 'PDT', 'PSA', 'ASQ', 'GJS', 'CPZ', 'EDV', 'QXE', 'ASH', 'OO', 'AIP', 'MES', 'JIA', 'SCX',
    ];

    const FREIGHT_CARRIERS = [
        'FDX', 'UPS', 'ABX', 'GTI', 'ATN', 'CLX', 'PAC', 'KAL', 'MTN', 'SRR', 'WCW', 'CAO',
    ];

    // Comprehensive military callsign prefixes
    // US Military + NATO allies
    const MILITARY_PREFIXES = [
        // US Air Force
        'RCH', 'REACH', 'AIO', 'KING', 'JOLLY', 'PEDRO', 'SPAR', 'EVAC', 'BREW', 'SHELL',
        'DARK', 'HAWK', 'VIPER', 'EAGLE', 'RAPTOR', 'BOLT', 'SLAM', 'BONE', 'DEATH', 'DOOM',
        'COBRA', 'TIGER', 'WOLF', 'REAPER', 'SHADOW', 'GLOBAL',
        'USAF', 'ANG', 'AFRC',
        // US Army
        'ARMY', 'PAT',
        // US Navy
        'NAVY', 'CNV', 'VAQ', 'VFA', 'VAW', 'VRC', 'VRM',
        // US Marines
        'MARINE', 'MAR', 'USMC', 'VMM', 'VMA', 'VMFA', 'VMC', 'VMGR', 'HMH', 'HMM', 'HML', 'HMLA',
        // US Coast Guard
        'USCG', 'COAST',
        // US Special Operations
        'RRR', 'EXEC', 'SAM', 'VENUS',
        // NATO
        'NATO', 'ALLIED', 'SENTRY', 'AWACS',
        // UK RAF
        'RFR', 'ASCOT', 'TARTAN', 'TALLY',
        // Canadian Forces
        'CFC', 'CANFORCE', 'CANAF',
        // German Luftwaffe
        'GAF', 'GAM',
        // French Air Force
        'FAF', 'CTM', 'COTAM',
        // Italian Air Force
        'IAM',
        // Australian Defence Force
        'AUSSIE', 'RAAF',
    ];

    const OPERATOR_GROUP_COLORS = {
        'MAJOR': '#dc3545',      // Red - major carriers
        'REGIONAL': '#28a745',   // Green - regional carriers
        'FREIGHT': '#007bff',    // Blue - freight/cargo
        'GA': '#ffc107',         // Yellow - general aviation
        'MILITARY': '#6f42c1',   // Purple - military
        'OTHER': '#6c757d',      // Gray - unclassified
    };

    // ===========================================
    // ICAO Normalization
    // Converts 3-letter IATA codes to 4-letter ICAO
    // Handles regional prefixes (K, CY, PA, PH, TJ, etc.)
    //
    // Note: IATA→ICAO is NOT algorithmic for non-CONUS airports.
    // Must use explicit mappings for Alaska, Hawaii, Pacific, Caribbean.
    // ===========================================

    // US airports starting with Y (these get K prefix, NOT CY prefix)
    // Common mistake: YIP is Michigan, not Canadian!
    const US_Y_AIRPORTS = new Set([
        'YIP', // Willow Run, MI
        'YNG', // Youngstown, OH
        'YUM', // Yuma, AZ
        'YKM', // Yakima, WA
        'YKN', // Yankton, SD
    ]);

    // IATA to ICAO explicit mappings for non-K-prefix airports
    // Source of truth: PERTI.IATA_TO_ICAO (perti.js)
    const IATA_TO_ICAO = (typeof PERTI !== 'undefined' && PERTI.IATA_TO_ICAO)
        ? Object.assign({}, PERTI.IATA_TO_ICAO)
        : {
            // Alaska (PA prefix)
            'ANC': 'PANC', 'FAI': 'PAFA', 'JNU': 'PAJN', 'BET': 'PABE', 'OME': 'PAOM',
            'OTZ': 'PAOT', 'SCC': 'PASC', 'ADQ': 'PADQ', 'DLG': 'PADL', 'CDV': 'PACV',
            'AKN': 'PAKN', 'BRW': 'PABR', 'CDB': 'PACD', 'ENA': 'PAEN', 'GST': 'PAGS',
            'HNS': 'PAHN', 'HOM': 'PAHO', 'KTN': 'PAKT', 'SIT': 'PASI', 'VDZ': 'PAVD',
            'WRG': 'PAWG', 'YAK': 'PAYA', 'SGY': 'PAGY', 'PSG': 'PAPG',
            // Hawaii (PH prefix)
            'HNL': 'PHNL', 'OGG': 'PHOG', 'LIH': 'PHLI', 'KOA': 'PHKO', 'ITO': 'PHTO',
            'MKK': 'PHMK', 'LNY': 'PHNY', 'JHM': 'PHJH', 'HNM': 'PHHN',
            // Pacific territories (PG prefix)
            'GUM': 'PGUM', 'SPN': 'PGSN', 'ROP': 'PGRO', 'TIQ': 'PGWT',
            // Puerto Rico (TJ prefix)
            'SJU': 'TJSJ', 'BQN': 'TJBQ', 'PSE': 'TJPS', 'RVR': 'TJRV',
            'MAZ': 'TJMZ', 'VQS': 'TJVQ', 'CPX': 'TJCP',
            // US Virgin Islands (TI prefix)
            'STT': 'TIST', 'STX': 'TISX',
        };

    /**
     * Normalize airport code to ICAO format.
     * Converts 3-letter IATA codes to 4-letter ICAO with appropriate prefix.
     *
     * Regional prefix rules:
     * - Canadian: Y** → CY** (YVR → CYVR)
     * - Alaska: explicit lookup (ANC → PANC)
     * - Hawaii: explicit lookup (HNL → PHNL)
     * - Pacific: explicit lookup (GUM → PGUM)
     * - Puerto Rico: explicit lookup (SJU → TJSJ)
     * - US Virgin Islands: explicit lookup (STT → TIST)
     * - CONUS: → K*** (ATL → KATL, 1O1 → K1O1)
     *
     * Also handles FAA LIDs with numbers (1O1, F05, 1U7, 3J7, etc.)
     *
     * @param {string} code - Airport code (3 or 4 chars, may include numbers)
     * @returns {string} - ICAO format code
     */
    function normalizeIcao(code) {
        // Delegate to PERTI canonical implementation when available
        if (typeof PERTI !== 'undefined' && PERTI.normalizeIcao) {
            return PERTI.normalizeIcao(code);
        }

        // Fallback: inline implementation for standalone usage
        if (!code) {return code;}

        const upper = String(code).toUpperCase().trim();

        // Already 4+ characters, return as-is
        if (upper.length >= 4) {return upper;}

        // Too short
        if (upper.length < 3) {return upper;}

        // 3-character codes - determine region
        if (upper.length === 3) {
            // Check explicit IATA→ICAO mappings first (Alaska, Hawaii, Pacific, Caribbean)
            if (IATA_TO_ICAO[upper]) {
                return IATA_TO_ICAO[upper];
            }

            // Y-prefix airports: check if US or Canadian
            if (/^Y[A-Z]{2}$/.test(upper)) {
                // Check if it's a known US Y-airport (YIP, YNG, YUM, etc.)
                if (US_Y_AIRPORTS.has(upper)) {
                    return 'K' + upper;  // KYIP, not CYIP
                }
                // Otherwise assume Canadian
                return 'C' + upper;  // CYYZ, CYVR, etc.
            }

            // Default: CONUS airports and FAA LIDs get K prefix
            // Includes alphanumeric codes like 1O1, F05, 1U7, 3J7
            return 'K' + upper;
        }

        return upper;
    }

    // Build reverse lookup (ICAO → IATA)
    const ICAO_TO_IATA = {};
    Object.entries(IATA_TO_ICAO).forEach(([iata, icao]) => {
        ICAO_TO_IATA[icao] = iata;
    });

    /**
     * Denormalize ICAO code back to 3-letter IATA format.
     * Useful for display or matching against IATA-based data.
     *
     * @param {string} icao - 4-letter ICAO code
     * @returns {string} - 3-letter IATA code (or original if not applicable)
     */
    function denormalizeIcao(icao) {
        // Delegate to PERTI canonical implementation when available
        if (typeof PERTI !== 'undefined' && PERTI.denormalizeIcao) {
            return PERTI.denormalizeIcao(icao);
        }

        // Fallback: inline implementation for standalone usage
        if (!icao) {return icao;}

        const upper = String(icao).toUpperCase().trim();

        // Must be 4 letters
        if (upper.length !== 4) {return upper;}

        // Check explicit ICAO→IATA mappings first (Alaska, Hawaii, Pacific, Caribbean)
        if (ICAO_TO_IATA[upper]) {
            return ICAO_TO_IATA[upper];
        }

        // K-prefix CONUS: KJFK → JFK
        if (upper.startsWith('K')) {
            return upper.slice(1);
        }

        // Canadian: CYYZ → YYZ, CZYZ → ZYZ
        if (upper.startsWith('CY') || upper.startsWith('CZ')) {
            return upper.slice(1);
        }

        return upper;
    }

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

    const FACILITY_HIERARCHY = {};  // artcc/tracon -> [child facilities]
    const TRACON_TO_ARTCC = {};     // tracon -> parent artcc
    const AIRPORT_TO_TRACON = {};   // airport -> parent tracon
    const AIRPORT_TO_ARTCC = {};    // airport -> parent artcc
    const ALL_TRACONS = new Set();
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
        if (lines.length < 2) {return;}

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
        };

        // Initialize ARTCC entries
        ARTCCS.forEach(artcc => {
            FACILITY_HIERARCHY[artcc] = new Set();
        });

        // Parse each airport line
        for (let i = 1; i < lines.length; i++) {
            const line = lines[i].trim();
            if (!line) {continue;}

            const cols = parseCSVLine(line);
            const arptId = (cols[colIdx.arptId] || '').trim().toUpperCase();
            const icaoId = (cols[colIdx.icaoId] || '').trim().toUpperCase();
            const artcc = (cols[colIdx.respArtcc] || '').trim().toUpperCase();

            // Get TRACON - check multiple columns
            let tracon = (cols[colIdx.approachId] || '').trim().toUpperCase();
            if (!tracon) {tracon = (cols[colIdx.depId] || '').trim().toUpperCase();}
            if (!tracon) {tracon = (cols[colIdx.apDepId] || '').trim().toUpperCase();}

            // Skip if no ARTCC or not in our list
            if (!artcc) {continue;}

            // Resolve aliases to canonical form
            const canonicalArtcc = ALIAS_TO_CANONICAL[artcc] || artcc;
            if (!ARTCCS.includes(canonicalArtcc) && !ARTCCS.includes(artcc)) {continue;}

            // Add TRACON to ARTCC's children if valid
            if (tracon && tracon.length >= 2 && tracon.length <= 4 && !ARTCCS.includes(tracon)) {
                if (!FACILITY_HIERARCHY[canonicalArtcc]) {FACILITY_HIERARCHY[canonicalArtcc] = new Set();}
                FACILITY_HIERARCHY[canonicalArtcc].add(tracon);
                TRACON_TO_ARTCC[tracon] = canonicalArtcc;
                ALL_TRACONS.add(tracon);

                // Initialize TRACON's children set
                if (!FACILITY_HIERARCHY[tracon]) {FACILITY_HIERARCHY[tracon] = new Set();}
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
                    if (!FACILITY_HIERARCHY[canonicalArtcc]) {FACILITY_HIERARCHY[canonicalArtcc] = new Set();}
                    FACILITY_HIERARCHY[canonicalArtcc].add(airportCode);
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
        if (hierarchyLoadPromise) {return hierarchyLoadPromise;}

        hierarchyLoadPromise = fetch('assets/data/apts.csv')
            .then(response => response.text())
            .then(csvText => {
                parseFacilityHierarchy(csvText);
                console.log('[FacilityHierarchy] Loaded:', {
                    artccs: ARTCCS.length,
                    tracons: ALL_TRACONS.size,
                    airports: Object.keys(AIRPORT_TO_ARTCC).length,
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
        // Delegate to PERTI for alias resolution (includes Canadian, Mexican, European, Oceanic)
        if (typeof PERTI !== 'undefined' && PERTI.resolveArtcc) {
            const resolved = PERTI.resolveArtcc(code);
            // Also check local aliases (may have additional entries not in PERTI)
            return ALIAS_TO_CANONICAL[resolved] || resolved;
        }
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
        if (ARTCCS.includes(upper)) {return upper;}
        if (TRACON_TO_ARTCC[upper]) {return TRACON_TO_ARTCC[upper];}
        if (AIRPORT_TO_ARTCC[upper]) {return AIRPORT_TO_ARTCC[upper];}
        return null;
    }

    function getChildFacilities(code) {
        return FACILITY_HIERARCHY[resolveAlias(code)] || [];
    }

    /**
     * Get airport tier (CORE30, OEP35, OPSNET45, ASPM82, or OTHER)
     * @param {string} icao - Airport ICAO code
     * @returns {string} - Tier name
     */
    function getAirportTier(icao) {
        if (!icao) {return 'OTHER';}
        const apt = icao.toUpperCase();
        if (AIRPORT_GROUPS.CORE30.airports.includes(apt)) {return 'CORE30';}
        if (AIRPORT_GROUPS.OEP35.airports.includes(apt)) {return 'OEP35';}
        if (AIRPORT_GROUPS.OPSNET45.airports.includes(apt)) {return 'OPSNET45';}
        if (AIRPORT_GROUPS.ASPM82.airports.includes(apt)) {return 'ASPM82';}
        return 'OTHER';
    }

    /**
     * Get airport tier color
     * @param {string} icao - Airport ICAO code
     * @returns {string} - CSS color
     */
    function getAirportTierColor(icao) {
        const tier = getAirportTier(icao);
        return AIRPORT_TIER_COLORS[tier] || AIRPORT_TIER_COLORS.OTHER;
    }

    /**
     * Extract carrier code from callsign (first 3 chars or word)
     * @param {string} callsign - Flight callsign
     * @returns {string} - Carrier code
     */
    function extractCarrier(callsign) {
        if (!callsign) {return '';}
        const upper = callsign.toUpperCase().trim();
        // Most callsigns: AAL1234 -> AAL
        // Some: REACH123 -> REACH
        const match = upper.match(/^([A-Z]{2,5})/);
        return match ? match[1].substring(0, 3) : '';
    }

    /**
     * Get operator group for a callsign
     * @param {string} callsign - Flight callsign
     * @returns {string} - Operator group (MAJOR, REGIONAL, FREIGHT, MILITARY, GA, OTHER)
     */
    function getOperatorGroup(callsign) {
        if (!callsign) {return 'OTHER';}
        const upper = callsign.toUpperCase();
        const carrier = extractCarrier(callsign);

        if (MAJOR_CARRIERS.includes(carrier)) {return 'MAJOR';}
        if (REGIONAL_CARRIERS.includes(carrier)) {return 'REGIONAL';}
        if (FREIGHT_CARRIERS.includes(carrier)) {return 'FREIGHT';}

        // Check military prefixes
        for (const prefix of MILITARY_PREFIXES) {
            if (upper.startsWith(prefix)) {return 'MILITARY';}
        }

        // GA typically has N-numbers or short callsigns
        if (/^N[0-9]/.test(upper) || callsign.length <= 5) {return 'GA';}

        return 'OTHER';
    }

    /**
     * Get operator group color
     * @param {string} callsign - Flight callsign
     * @returns {string} - CSS color
     */
    function getOperatorGroupColor(callsign) {
        const group = getOperatorGroup(callsign);
        return OPERATOR_GROUP_COLORS[group] || OPERATOR_GROUP_COLORS.OTHER;
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

        // Carrier classifications
        MAJOR_CARRIERS: MAJOR_CARRIERS,
        REGIONAL_CARRIERS: REGIONAL_CARRIERS,
        FREIGHT_CARRIERS: FREIGHT_CARRIERS,
        MILITARY_PREFIXES: MILITARY_PREFIXES,
        OPERATOR_GROUP_COLORS: OPERATOR_GROUP_COLORS,
        AIRPORT_TIER_COLORS: AIRPORT_TIER_COLORS,

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
        getChildren: getChildFacilities,
        getFIR: getInternationalFIR,
        ICAO_FIR_MAP: ICAO_FIR_MAP,

        // Airport tier utilities
        getAirportTier: getAirportTier,
        getAirportTierColor: getAirportTierColor,

        // Operator group utilities
        extractCarrier: extractCarrier,
        getOperatorGroup: getOperatorGroup,
        getOperatorGroupColor: getOperatorGroupColor,

        // ICAO normalization utilities
        normalizeIcao: normalizeIcao,
        denormalizeIcao: denormalizeIcao,
    };

})(typeof window !== 'undefined' ? window : this);
