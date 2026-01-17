/**
 * NTML Quick Entry - Enhanced NLP Parser v2.0
 * Supports all 13 TMI types based on historical NTML analysis
 * 
 * TMI Types Supported:
 * - MIT (Miles-In-Trail)
 * - MINIT (Minutes-In-Trail)
 * - STOP (Flow Stoppage)
 * - APREQ/CFR (Approval Request/Call for Release)
 * - TBM (Time-Based Metering)
 * - CANCEL (TMI Cancellation)
 * - E/D, A/D, D/D (Expected/Arrival/Departure Delay with Holding)
 * - CONFIG (Airport Configuration)
 * - REROUTE (Route Changes)
 * - GS/GDP (Ground Stop/Ground Delay Program)
 * - OTHER (Planning, Alt restrictions, etc.)
 */

// ============================================
// CONSTANTS & DATA
// ============================================

const ARTCCS = {
    'ZAB': 'Albuquerque Center', 'ZAU': 'Chicago Center', 'ZBW': 'Boston Center',
    'ZDC': 'Washington Center', 'ZDV': 'Denver Center', 'ZFW': 'Fort Worth Center',
    'ZHU': 'Houston Center', 'ZID': 'Indianapolis Center', 'ZJX': 'Jacksonville Center',
    'ZKC': 'Kansas City Center', 'ZLA': 'Los Angeles Center', 'ZLC': 'Salt Lake Center',
    'ZMA': 'Miami Center', 'ZME': 'Memphis Center', 'ZMP': 'Minneapolis Center',
    'ZNY': 'New York Center', 'ZOA': 'Oakland Center', 'ZOB': 'Cleveland Center',
    'ZSE': 'Seattle Center', 'ZTL': 'Atlanta Center',
    // Canadian
    'CZY': 'Gander Oceanic', 'CZM': 'Montreal Center', 'CZZ': 'Toronto Center',
    'CZE': 'Edmonton Center', 'CZV': 'Vancouver Center', 'CZW': 'Winnipeg Center'
};

const TRACONS = {
    'A80': 'Atlanta TRACON', 'A90': 'Boston TRACON', 'C90': 'Chicago TRACON',
    'D01': 'Denver TRACON', 'D10': 'Dallas TRACON', 'D21': 'Detroit TRACON',
    'F11': 'Miami TRACON', 'I90': 'Houston TRACON', 'L30': 'Las Vegas TRACON', 
    'M98': 'Minneapolis TRACON', 'N90': 'New York TRACON', 'NCT': 'NorCal TRACON', 
    'P50': 'Phoenix TRACON', 'P80': 'Portland TRACON', 'PCT': 'Potomac TRACON', 
    'S46': 'Seattle TRACON', 'SCT': 'SoCal TRACON', 'Y90': 'Yankee TRACON',
    // Towers that appear in NTML
    'MIA': 'Miami Tower', 'JFK': 'JFK Tower', 'EWR': 'Newark Tower', 
    'LGA': 'LaGuardia Tower', 'ORD': 'O\'Hare Tower', 'ATL': 'Atlanta Tower',
    'DFW': 'Dallas Tower', 'LAX': 'Los Angeles Tower', 'SFO': 'San Francisco Tower',
    'DEN': 'Denver Tower', 'SEA': 'Seattle Tower', 'PHX': 'Phoenix Tower'
};

const MAJOR_AIRPORTS = [
    'ABQ', 'ATL', 'AUS', 'BDL', 'BNA', 'BOS', 'BWI', 'CLE', 'CLT', 'CMH', 
    'CVG', 'DAL', 'DCA', 'DEN', 'DFW', 'DTW', 'EWR', 'FLL', 'GEG', 'HNL',
    'HOU', 'IAD', 'IAH', 'IND', 'ISP', 'JAX', 'JFK', 'LAS', 'LAX', 
    'LGA', 'MCI', 'MCO', 'MDW', 'MEM', 'MIA', 'MKE', 'MSP', 'MSY', 'OAK',
    'OKC', 'ORD', 'PDX', 'PHL', 'PHX', 'PIT', 'RDU', 'RSW', 'SAN', 'SAT',
    'SDF', 'SEA', 'SFO', 'SJC', 'SLC', 'SMF', 'SNA', 'STL', 'TEB', 'TPA',
    'TUS', 'YYZ', 'YYJ', 'YVR'
];

// Expanded fixes from historical NTML data
const COMMON_FIXES = [
    // NY Metro
    'CAMRN', 'LENDY', 'BIGGY', 'MERIT', 'DIXIE', 'COATE', 'NEION', 'GAYEL',
    'KORRY', 'LANNA', 'PARCH', 'ROBER', 'SKIPY', 'WAVEY', 'WHITE', 'ZIGGI',
    'BETTE', 'PARKE', 'GREKI', 'JUDDS', 'VALRE', 'ELIOT', 'LEEAH', 'HAAYS',
    'HAARP', 'FLOSI', 'PHLBO', 'FQM', 'MIP', 'LVZ', 'JAIKE', 'MAZIE',
    // Florida
    'CIGAR', 'BAGGS', 'CODGR', 'JUULI', 'BALKE', 'SLOJO', 'WALET', 'JORAY',
    'HIBAC', 'CRANS', 'SSCOT', 'CURSO', 'QUBEN', 'MARQO', 'OHDEA', 'LUNNI',
    // Atlanta
    'CHPPR', 'GLAVN', 'BOBZY',
    // West Coast
    'SERFR', 'DYAMD', 'BDEGA', 'PIRAT', 'SUUTR', 'SLMMR', 'SILCN', 'RAZRR',
    'BRIXX', 'TUDOR', 'HAWKZ', 'RADDY', 'JAKSN', 'GABBL', 'ESTWD', 'BURGL', 'REBRG',
    // Central
    'BONNT', 'KEOKK', 'CASHN', 'BENKY', 'TRTLL', 'MAGOO', 'PHEEB', 'ZZIPR',
    'MYRRS', 'KOHLL', 'UFDUH', 'OBSTR', 'OHHMY', 'JALAP', 'SMUUV', 'EMMMA',
    'WATSN', 'BAGEL', 'DROSE', 'JSONN', 'MNOSO', 'KKISS', 'RKCTY', 'HAYLL', 'VCTRZ',
    // DFW
    'TURKI', 'MDANO', 'HOFFF', 'HITUG', 'AXXEE', 'FEWWW', 'RRNET', 'YUYUN',
    'PNUTS', 'STUFT', 'CRIED', 'GUTZZ', 'PIEPE', 'IBUFY', 'BEREE',
    // Airways
    'J48', 'J70', 'J146', 'J584', 'J60', 'J6', 'J75', 'J220', 'Q75', 'Q58', 
    'Q102', 'Q818', 'Q480', 'AR21', 'AR22'
];

// Navaids from NTML
const NAVAIDS = ['OMN', 'ARS', 'ENDEW', 'DEALE', 'PSB', 'FWA', 'IRK', 'FOD', 
    'GRB', 'EKR', 'LMT', 'BAE', 'DBQ', 'ALO', 'GSO', 'JAX', 'EAU', 'SQS', 'PLL'];

// Expanded reason formats (from NTML analysis)
const REASON_FORMATS = {
    // Standard colon-separated
    'VOLUME:VOLUME': 'VOLUME', 'VOLUME / VOLUME': 'VOLUME', 'VOLUME/VOLUME': 'VOLUME',
    'WEATHER:WEATHER': 'WEATHER', 'WEATHER:THUNDERSTORMS': 'WEATHER',
    'WEATHER / THUNDERSTORMS': 'WEATHER', 'WEATHER:WX': 'WEATHER',
    'RUNWAY:CONFIG CHG': 'RUNWAY', 'RUNWAY:CONFIG': 'RUNWAY', 'RUNWAY:RWY CHG': 'RUNWAY',
    'OTHER:OTHER': 'OTHER', 'OTHER:STAFFING': 'OTHER', 'OTHER:COORDINATION': 'OTHER',
    // Event-specific
    'VOLUME:FNO': 'VOLUME', 'VOLUME:EVENT': 'VOLUME', 'VOLUME:GO BLUE': 'VOLUME',
    'VOLUME:NIGHTMARE': 'VOLUME', 'VOLUME:BLUCIFER': 'VOLUME', 'VOLUME:JOURNEY': 'VOLUME',
    'VOLUME:NCX': 'VOLUME', 'VOLUME:EMNEM': 'VOLUME', 'VOLUME:COMPACTED DEMAND': 'VOLUME',
    // Simple
    'VOLUME': 'VOLUME', 'WEATHER': 'WEATHER', 'WX': 'WEATHER', 'VOL': 'VOLUME',
    'RUNWAY': 'RUNWAY', 'RWY': 'RUNWAY', 'EQUIPMENT': 'EQUIPMENT', 'EQUIP': 'EQUIPMENT',
    'OTHER': 'OTHER', 'STAFFING': 'OTHER', 'COORDINATION': 'OTHER'
};

// Expanded qualifiers from NTML analysis
const QUALIFIERS = [
    // Flow qualifiers
    'PER FIX', 'PER AIRPORT', 'PER STREAM', 'PER ROUTE', 'PER RTE',
    'AS ONE', 'SINGLE STREAM', 'EACH',
    // Stack control
    'NO STACKS',
    // Runway
    'RALT', 'RUNWAY ALTERNATING',
    // Type filters
    'TYPE:JETS', 'TYPE:ALL', 'TYPE:PROPS', 'JETS', 'PROPS',
    // Exclusions
    'EXCL:PROPS', 'EXCL:NONE', 'EXCL:DIVERSIONS', 'EXCL:ATL', 'EXCLROPS',
    // Weight class
    'HEAVY', 'B757', 'LARGE', 'SMALL',
    // Compression
    'NO COMP', '-10MIT NO COMP-',
    // Sequencing
    'AFTER'
];

const WEATHER_CONDITIONS = ['VMC', 'LVMC', 'MVMC', 'IMC', 'LIMC', 'VLIMC'];

const AAR_TYPES = ['Strat', 'Dyn'];

// Sector identifiers (from NTML)
const SECTORS = ['3_WEST', 'NORTH', 'SOUTH', 'EAST', 'WEST'];

// ============================================
// STATE
// ============================================

let entryQueue = [];
let productionMode = false;
let currentMode = 'single';

// ============================================
// INITIALIZATION
// ============================================

$(document).ready(function() {
    initializeEventHandlers();
    initializeTimeDefaults();
    loadSavedState();
});

function initializeEventHandlers() {
    // Mode toggle
    $('.mode-btn').click(function() {
        currentMode = $(this).data('mode');
        $('.mode-btn').removeClass('active');
        $(this).addClass('active');
        
        if (currentMode === 'single') {
            $('#singleMode').show();
            $('#batchMode').hide();
            $('#quickInput').focus();
        } else {
            $('#singleMode').hide();
            $('#batchMode').show();
            $('#batchInput').focus();
        }
    });
    
    // Production mode toggle
    $('#productionMode').change(function() {
        productionMode = $(this).is(':checked');
        localStorage.setItem('ntml_production_mode', productionMode);
        updateModeIndicator();
        
        if (productionMode) {
            Swal.fire({
                icon: 'warning',
                title: 'Production Mode',
                text: 'Entries will post to LIVE Discord channels.',
                confirmButtonColor: '#dc3545'
            });
        }
    });
    
    // Quick input - Enter to add, Ctrl+Enter to submit
    $('#quickInput').on('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            if (e.ctrlKey) {
                submitAll();
            } else {
                addFromQuickInput();
            }
        } else if (e.key === 'Tab') {
            const selected = $('.autocomplete-item.selected');
            if (selected.length) {
                e.preventDefault();
                selectAutocompleteItem(selected.data('value'));
            }
        } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            navigateAutocomplete(e.key === 'ArrowDown' ? 1 : -1);
        } else if (e.key === 'Escape') {
            hideAutocomplete();
        }
    });
    
    // Batch input - Ctrl+Enter to parse and add
    $('#batchInput').on('keydown', function(e) {
        if (e.ctrlKey && e.key === 'Enter') {
            e.preventDefault();
            parseBatchInput();
        }
    });
    
    // Input change for autocomplete
    $('#quickInput').on('input', function() {
        showAutocomplete($(this).val());
    });
    
    // Templates
    $('.template-btn').click(function() {
        const template = $(this).data('template');
        applyTemplate(template);
    });
    
    // Clear queue
    $('#clearQueue').click(function() {
        Swal.fire({
            title: 'Clear All Entries?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            confirmButtonText: 'Clear All'
        }).then((result) => {
            if (result.isConfirmed) {
                entryQueue = [];
                renderQueue();
            }
        });
    });
    
    // Preview button
    $('#previewBtn').click(showPreview);
    
    // Submit buttons
    $('#submitAllBtn, #submitFromPreview').click(submitAll);
    
    // Valid time auto-advance
    $('#validFrom').on('input', function() {
        if ($(this).val().length === 4) {
            $('#validUntil').focus();
        }
    });
    
    // Click outside to close autocomplete
    $(document).click(function(e) {
        if (!$(e.target).closest('#quickInput, #autocompleteDropdown').length) {
            hideAutocomplete();
        }
    });
}

function initializeTimeDefaults() {
    const now = new Date();
    const zuluHours = String(now.getUTCHours()).padStart(2, '0');
    const zuluMins = String(now.getUTCMinutes()).padStart(2, '0');
    $('#validFrom').val(zuluHours + zuluMins);
    
    const endTime = new Date(now.getTime() + 2 * 60 * 60 * 1000);
    const endHours = String(endTime.getUTCHours()).padStart(2, '0');
    const endMins = String(endTime.getUTCMinutes()).padStart(2, '0');
    $('#validUntil').val(endHours + endMins);
}

function loadSavedState() {
    const savedMode = localStorage.getItem('ntml_production_mode');
    if (savedMode === 'true') {
        $('#productionMode').prop('checked', true);
        productionMode = true;
        updateModeIndicator();
    }
}

function updateModeIndicator() {
    const indicator = $('#modeIndicator');
    const warning = $('#prodWarning');
    
    if (productionMode) {
        indicator.removeClass('badge-warning').addClass('badge-danger').text('LIVE');
        warning.addClass('show');
    } else {
        indicator.removeClass('badge-danger').addClass('badge-warning').text('TEST');
        warning.removeClass('show');
    }
}

// ============================================
// ENHANCED NLP PARSING ENGINE v2.0
// ============================================

/**
 * Main parser entry point - tries parsers in order of specificity
 */
function parseEntry(input) {
    input = input.trim().toUpperCase();
    if (!input) return null;
    
    // Normalize input
    input = normalizeInput(input);
    
    // Try each parser in order of specificity
    let result = 
        parseCancel_NLP(input) ||      // Check CANCEL first (can contain other keywords)
        parseHolding_NLP(input) ||     // E/D, A/D, D/D patterns
        parseStop_NLP(input) ||        // STOP patterns
        parseAPREQ_NLP(input) ||       // APREQ/CFR patterns
        parseTBM_NLP(input) ||         // TBM/Metering patterns
        parseReroute_NLP(input) ||     // Reroute patterns
        parseMIT_NLP(input) ||         // MIT patterns
        parseMINIT_NLP(input) ||       // MINIT patterns
        parseDelay_NLP(input) ||       // DELAY patterns
        parseConfig_NLP(input) ||      // CONFIG patterns
        parseOther_NLP(input);         // Catch-all for other types
    
    if (result) {
        result.raw = input;
        result.determinant = calculateDeterminant(result);
        result.validationErrors = validateEntry(result);
    }
    
    return result;
}

/**
 * Normalize input - standardize separators and formats
 */
function normalizeInput(input) {
    return input
        .replace(/\s*:\s*/g, ':')              // ZDC : ZJX -> ZDC:ZJX
        .replace(/\s*->\s*/g, '→')             // -> to arrow
        .replace(/\s*>\s*/g, '→')              // > to arrow  
        .replace(/\s+TO\s+(?!MIA|JFK|ATL|ORD|LAX|SFO|DFW|DEN|SEA)/gi, '→')  // TO to arrow (not before airports)
        .replace(/\s*→\s*/g, '→')              // Clean up arrows
        .replace(/(\d+)\s+(MIT|MINIT)/gi, '$1$2')   // 40 MIT -> 40MIT
        .replace(/(\d+)\s*MIN\b/gi, '$1min')   // 45 MIN -> 45min
        .replace(/(\d+)\s*FLT\b/gi, '$1flt')   // 12 FLT -> 12flt
        .replace(/\bE\/D\b/g, 'ED')            // E/D -> ED
        .replace(/\bA\/D\b/g, 'AD')            // A/D -> AD
        .replace(/\bD\/D\b/g, 'DD')            // D/D -> DD
        .trim();
}

/**
 * Extract time range from input (HHMM-HHMM or HHMMZ-HHMMZ)
 */
function extractTimeRange(input) {
    const timePattern = input.match(/(\d{4})Z?\s*[-–]\s*(\d{4})Z?/);
    if (timePattern) {
        return {
            validFrom: timePattern[1],
            validUntil: timePattern[2],
            remaining: input.replace(timePattern[0], ' ')
        };
    }
    return {
        validFrom: $('#validFrom').val() || '',
        validUntil: $('#validUntil').val() || '',
        remaining: input
    };
}

/**
 * Extract facility pair from input (ZDC:ZJX or ZBW→ZNY or ZBW ZNY)
 */
function extractFacilityPair(input) {
    let fromFacility = null, toFacility = null;
    let remaining = input;
    
    // Pattern: ZDC:ZJX,ZTL,ZHU (colon with multiple destinations)
    const colonMulti = remaining.match(/([A-Z]{3}):([A-Z0-9,]+)/);
    if (colonMulti) {
        fromFacility = colonMulti[1];
        toFacility = colonMulti[2]; // Keep the full list
        remaining = remaining.replace(colonMulti[0], ' ');
    }
    
    // Pattern: ZBW→ZNY
    if (!fromFacility) {
        const arrowPattern = remaining.match(/([A-Z0-9]{2,4})→([A-Z0-9]{2,4})/);
        if (arrowPattern) {
            fromFacility = arrowPattern[1];
            toFacility = arrowPattern[2];
            remaining = remaining.replace(arrowPattern[0], ' ');
        }
    }
    
    return { fromFacility, toFacility, remaining };
}

/**
 * Extract reason from input
 */
function extractReason(input) {
    // Try compound reasons first (VOLUME:VOLUME, etc.)
    for (const [pattern, normalized] of Object.entries(REASON_FORMATS)) {
        const regex = new RegExp('\\b' + pattern.replace(/[/:]/g, '[/:]?') + '\\b', 'i');
        if (regex.test(input)) {
            return {
                reason: normalized,
                rawReason: pattern,
                remaining: input.replace(regex, ' ')
            };
        }
    }
    return { reason: 'VOLUME', rawReason: 'VOLUME:VOLUME', remaining: input };
}

/**
 * Extract exclusions (EXCL:NONE, EXCL:PROPS, etc.)
 */
function extractExclusions(input) {
    const exclMatch = input.match(/EXCL:([A-Z,]+)/i);
    if (exclMatch) {
        return {
            exclusions: exclMatch[1],
            remaining: input.replace(exclMatch[0], ' ')
        };
    }
    return { exclusions: 'NONE', remaining: input };
}

/**
 * Extract qualifiers from input
 */
function extractQualifiers(input) {
    const found = [];
    let remaining = input;
    
    // Check for AFTER [callsign] pattern
    const afterMatch = remaining.match(/AFTER\s+([A-Z]{3}\d+)/i);
    if (afterMatch) {
        found.push(`AFTER ${afterMatch[1]}`);
        remaining = remaining.replace(afterMatch[0], ' ');
    }
    
    // Check for TYPE: patterns
    const typeMatch = remaining.match(/TYPE:([A-Z]+)/i);
    if (typeMatch) {
        found.push(`TYPE:${typeMatch[1]}`);
        remaining = remaining.replace(typeMatch[0], ' ');
    }
    
    // Check for ALT: patterns (altitude restrictions)
    const altMatch = remaining.match(/ALT:([A-Z0-9]+)/i);
    if (altMatch) {
        found.push(`ALT:${altMatch[1]}`);
        remaining = remaining.replace(altMatch[0], ' ');
    }
    
    // Check standard qualifiers
    for (const q of QUALIFIERS) {
        if (q === 'AFTER') continue; // Already handled
        const normalized = q.replace(/[_\s]+/g, '[_\\s]?');
        const regex = new RegExp('\\b' + normalized + '\\b', 'gi');
        if (regex.test(remaining)) {
            found.push(q.replace(/\s+/g, '_'));
            remaining = remaining.replace(regex, ' ').trim();
        }
    }
    
    return { qualifiers: found, remaining };
}

// ============================================
// TMI TYPE PARSERS
// ============================================

/**
 * Parse CANCEL entries
 */
function parseCancel_NLP(input) {
    if (!input.includes('CANCEL')) return null;
    
    let remaining = input;
    
    // ALL TMI CANCELLED
    if (/ALL\s+TMI\s*(CANCELLED|CANCEL)/i.test(input)) {
        const { fromFacility, toFacility, remaining: r1 } = extractFacilityPair(remaining);
        return {
            type: 'CANCEL',
            protocol: '00',
            cancelType: 'ALL',
            condition: 'ALL TMI',
            fromFacility,
            toFacility
        };
    }
    
    remaining = remaining.replace(/CANCEL\s*/gi, ' ').trim();
    
    const { fromFacility, toFacility, remaining: r2 } = extractFacilityPair(remaining);
    remaining = r2;
    
    const { validFrom, validUntil, remaining: r3 } = extractTimeRange(remaining);
    remaining = r3;
    
    let viaRoute = null;
    const viaMatch = remaining.match(/VIA\s+([A-Z0-9,\/]+)/i);
    if (viaMatch) {
        viaRoute = viaMatch[1];
        remaining = remaining.replace(viaMatch[0], ' ');
    }
    
    const airports = findAirports(remaining);
    const condition = airports.length > 0 ? airports.join(',') : (viaRoute || '');
    
    const mitMatch = remaining.match(/(\d+)\s*MIT/i);
    const mitValue = mitMatch ? parseInt(mitMatch[1]) : null;
    
    return {
        type: 'CANCEL',
        protocol: '00',
        cancelType: mitValue ? 'MIT' : (viaRoute ? 'ROUTE' : 'TMI'),
        condition: condition,
        viaRoute: viaRoute,
        mitValue: mitValue,
        fromFacility,
        toFacility,
        validFrom,
        validUntil
    };
}

/**
 * Parse Holding/Delay entries (E/D, A/D, D/D)
 */
function parseHolding_NLP(input) {
    const holdingPattern = input.match(/(?:([A-Z]{3}(?:\d{2})?)\s+)?(ED|AD|DD)\s+(?:FOR|FROM|TO)\s+([A-Z]{3})/i);
    if (!holdingPattern) return null;
    
    const reportingFacility = holdingPattern[1] || null;
    const delayType = holdingPattern[2].toUpperCase();
    const airport = holdingPattern[3];
    
    let remaining = input.replace(holdingPattern[0], ' ');
    
    let delayChange = 'steady';
    let delayMinutes = null;
    let delayTime = null;
    let acftCount = null;
    let isHolding = false;
    
    const holdingMatch = remaining.match(/([+-])Holding\/(\d{4})(?:\/(\d+)\s*ACFT)?/i);
    if (holdingMatch) {
        delayChange = holdingMatch[1] === '+' ? 'initiating' : 'terminating';
        delayTime = holdingMatch[2];
        acftCount = holdingMatch[3] ? parseInt(holdingMatch[3]) : null;
        isHolding = true;
        remaining = remaining.replace(holdingMatch[0], ' ');
    }
    
    const delayMatch = remaining.match(/([+-])(\d+)\/(\d{4})(?:[-\/](\d{4}))?(?:\/(\d+)\s*ACFT)?/i);
    if (delayMatch) {
        delayChange = delayMatch[1] === '+' ? 'increasing' : 'decreasing';
        delayMinutes = parseInt(delayMatch[2]);
        delayTime = delayMatch[3];
        acftCount = delayMatch[5] ? parseInt(delayMatch[5]) : null;
        remaining = remaining.replace(delayMatch[0], ' ');
    }
    
    let navaid = null;
    const navaidMatch = remaining.match(/NAVAID:([A-Z]+)/i);
    if (navaidMatch) {
        navaid = navaidMatch[1];
        remaining = remaining.replace(navaidMatch[0], ' ');
    }
    
    const { reason, rawReason } = extractReason(remaining);
    
    const isStream = remaining.includes('STREAM');
    const isLateNote = remaining.includes('LATE NOTE');
    
    return {
        type: 'HOLDING',
        protocol: '04',
        delayType: delayType,
        airport: airport,
        reportingFacility: reportingFacility,
        delayChange: delayChange,
        delayMinutes: delayMinutes,
        delayTime: delayTime,
        acftCount: acftCount,
        isHolding: isHolding,
        navaid: navaid,
        isStream: isStream,
        isLateNote: isLateNote,
        reason: reason,
        rawReason: rawReason
    };
}

/**
 * Parse STOP entries (flow stoppage)
 */
function parseStop_NLP(input) {
    if (!input.includes('STOP') || input.includes('GROUND STOP')) return null;
    if (input.includes('CANCEL')) return null;
    
    let remaining = input;
    
    const { validFrom, validUntil, remaining: r1 } = extractTimeRange(remaining);
    remaining = r1;
    
    const { fromFacility, toFacility, remaining: r2 } = extractFacilityPair(remaining);
    remaining = r2;
    
    const { exclusions, remaining: r3 } = extractExclusions(remaining);
    remaining = r3;
    
    const { reason, rawReason, remaining: r4 } = extractReason(remaining);
    remaining = r4;
    
    remaining = remaining.replace(/\bSTOP\b/gi, ' ');
    
    let viaRoute = null;
    const viaMatch = remaining.match(/VIA\s+([A-Z0-9,\/\s]+?)(?=\s+(?:VOLUME|WEATHER|RUNWAY|OTHER|EXCL|\d{4})|\s*$)/i);
    if (viaMatch) {
        viaRoute = viaMatch[1].trim();
        remaining = remaining.replace(viaMatch[0], ' ');
    }
    
    const isDepartures = remaining.includes('DEPARTURES') || remaining.includes('DEP');
    const isArrivals = remaining.includes('ARRIVALS') || remaining.includes('ARR');
    remaining = remaining.replace(/\b(DEPARTURES?|ARRIVALS?|DEP|ARR)\b/gi, ' ');
    
    const airports = findAirports(remaining);
    const condition = airports.join(',') || '';
    
    return {
        type: 'STOP',
        protocol: '03',
        condition: condition,
        viaRoute: viaRoute,
        isDepartures: isDepartures,
        isArrivals: isArrivals,
        fromFacility,
        toFacility,
        exclusions,
        reason,
        rawReason,
        validFrom,
        validUntil
    };
}

/**
 * Parse APREQ/CFR entries
 */
function parseAPREQ_NLP(input) {
    const isAPREQ = input.includes('APREQ');
    const isCFR = input.includes('CFR');
    if (!isAPREQ && !isCFR) return null;
    
    let remaining = input.replace(/\b(APREQ|CFR)\b/gi, ' ').trim();
    
    const { validFrom, validUntil, remaining: r1 } = extractTimeRange(remaining);
    remaining = r1;
    
    const { fromFacility, toFacility, remaining: r2 } = extractFacilityPair(remaining);
    remaining = r2;
    
    const { exclusions, remaining: r3 } = extractExclusions(remaining);
    remaining = r3;
    
    const { reason, rawReason, remaining: r4 } = extractReason(remaining);
    remaining = r4;
    
    const { qualifiers, remaining: r5 } = extractQualifiers(remaining);
    remaining = r5;
    
    let viaRoute = null;
    const viaMatch = remaining.match(/VIA\s+([A-Z0-9,\/]+)/i);
    if (viaMatch) {
        viaRoute = viaMatch[1];
        remaining = remaining.replace(viaMatch[0], ' ');
    }
    
    const isDepartures = remaining.includes('DEPARTURES') || remaining.includes('DEP');
    const isArrivals = remaining.includes('ARRIVALS') || remaining.includes('ARR');
    remaining = remaining.replace(/\b(DEPARTURES?|ARRIVALS?|DEP|ARR)\b/gi, ' ');
    
    const airports = findAirports(remaining);
    const condition = airports.join(',') || '';
    
    return {
        type: isAPREQ ? 'APREQ' : 'CFR',
        protocol: '07',
        condition: condition,
        viaRoute: viaRoute,
        isDepartures: isDepartures,
        isArrivals: isArrivals,
        qualifiers,
        fromFacility,
        toFacility,
        exclusions,
        reason,
        rawReason,
        validFrom,
        validUntil
    };
}

/**
 * Parse TBM (Time-Based Metering) entries
 */
function parseTBM_NLP(input) {
    const hasTBM = input.includes('TBM');
    const hasMetering = input.includes('METERING') || input.includes('CALFLOW');
    if (!hasTBM && !hasMetering) return null;
    
    let remaining = input.replace(/\bTBM\b/gi, ' ').replace(/\bMETERING\b/gi, ' ').replace(/\bCALFLOW\b/gi, ' ').trim();
    
    const { validFrom, validUntil, remaining: r1 } = extractTimeRange(remaining);
    remaining = r1;
    
    const { fromFacility, toFacility, remaining: r2 } = extractFacilityPair(remaining);
    remaining = r2;
    
    const { exclusions, remaining: r3 } = extractExclusions(remaining);
    remaining = r3;
    
    const { reason, rawReason, remaining: r4 } = extractReason(remaining);
    remaining = r4;
    
    let sector = null;
    for (const s of SECTORS) {
        if (remaining.includes(s)) {
            sector = s;
            remaining = remaining.replace(s, ' ');
            break;
        }
    }
    
    const isBegin = remaining.includes('BEGIN');
    const isEnd = remaining.includes('END');
    remaining = remaining.replace(/\b(BEGIN|END)\b/gi, ' ');
    
    const viaSimtraffic = remaining.includes('SIMTRAFFIC');
    remaining = remaining.replace(/SIMTRAFFIC/gi, ' ');
    
    const isDepartures = remaining.includes('DEPARTURES') || remaining.includes('DEP');
    remaining = remaining.replace(/\b(DEPARTURES?|DEP)\b/gi, ' ');
    
    const airports = findAirports(remaining);
    const condition = airports.join(',') || '';
    
    return {
        type: 'TBM',
        protocol: '08',
        condition: condition,
        sector: sector,
        isBegin: isBegin,
        isEnd: isEnd,
        isDepartures: isDepartures,
        viaSimtraffic: viaSimtraffic,
        fromFacility,
        toFacility,
        exclusions,
        reason,
        rawReason,
        validFrom,
        validUntil
    };
}

/**
 * Parse Reroute entries
 */
function parseReroute_NLP(input) {
    if (!input.includes('REROUTE') && !input.includes('RERTES')) return null;
    
    let remaining = input.replace(/\b(REROUTE|RERTES)\b/gi, ' ').trim();
    
    const { validFrom, validUntil, remaining: r1 } = extractTimeRange(remaining);
    remaining = r1;
    
    const { fromFacility, toFacility, remaining: r2 } = extractFacilityPair(remaining);
    remaining = r2;
    
    const { exclusions, remaining: r3 } = extractExclusions(remaining);
    remaining = r3;
    
    const { reason, rawReason, remaining: r4 } = extractReason(remaining);
    remaining = r4;
    
    let viaRoute = null;
    const viaMatch = remaining.match(/VIA\s+(.+?)(?=\s+(?:VOLUME|WEATHER|EXCL|\d{4})|$)/i);
    if (viaMatch) {
        viaRoute = viaMatch[1].trim();
        remaining = remaining.replace(viaMatch[0], ' ');
    } else {
        const routeTokens = remaining.match(/\b([A-Z]{4,5}\d?)\b/g);
        if (routeTokens && routeTokens.length > 1) {
            viaRoute = routeTokens.join(' ');
        }
    }
    
    const airports = findAirports(remaining);
    const condition = airports.join(',') || '';
    
    return {
        type: 'REROUTE',
        protocol: '09',
        condition: condition,
        route: viaRoute,
        fromFacility,
        toFacility,
        exclusions,
        reason,
        rawReason,
        validFrom,
        validUntil
    };
}

/**
 * Enhanced MIT Parser
 * Format: APT [arrivals/departures] via FIX ##MIT [QUALIFIERS] REASON EXCL:xxx HHMM-HHMM REQ:PROV
 */
function parseMIT_NLP(input) {
    const mitMatch = input.match(/(\d+)\s*MIT/i);
    if (!mitMatch) return null;
    
    const distance = parseInt(mitMatch[1]);
    let remaining = input.replace(/(\d+)\s*MIT/i, ' ').trim();
    
    const { validFrom, validUntil, remaining: r1 } = extractTimeRange(remaining);
    remaining = r1;
    
    const { fromFacility, toFacility, remaining: r2 } = extractFacilityPair(remaining);
    remaining = r2;
    
    const { exclusions, remaining: r3 } = extractExclusions(remaining);
    remaining = r3;
    
    const { reason, rawReason, remaining: r4 } = extractReason(remaining);
    remaining = r4;
    
    const { qualifiers, remaining: r5 } = extractQualifiers(remaining);
    remaining = r5;
    
    // Extract via route (fix) - this is separate from the airport condition
    let viaRoute = null;
    const viaPattern = remaining.match(/VIA\s+([A-Z0-9,\/]+)/i);
    if (viaPattern) {
        viaRoute = viaPattern[1];
        remaining = remaining.replace(viaPattern[0], ' ');
    }
    
    const isDepartures = remaining.includes('DEPARTURES') || remaining.includes('DEP');
    const isArrivals = remaining.includes('ARRIVALS') || remaining.includes('ARR');
    remaining = remaining.replace(/\b(DEPARTURES?|ARRIVALS?|DEP|ARR)\b/gi, ' ');
    
    // Find airports - these go in condition
    let condition = '';
    const airports = findAirports(remaining);
    if (airports.length > 0) {
        condition = airports.join(',');
        for (const apt of airports) {
            remaining = remaining.replace(new RegExp('\\b' + apt + '\\b'), ' ');
        }
    }
    
    // Look for 5-letter fixes (even if we have an airport)
    // This handles "JFK LENDY 20MIT" where LENDY is the fix without "via" keyword
    if (!viaRoute) {
        const fixes = findFixes(remaining);
        if (fixes.length > 0) {
            viaRoute = fixes.join(',');
            for (const fix of fixes) {
                remaining = remaining.replace(new RegExp('\\b' + fix + '\\b'), ' ');
            }
        }
    }
    
    // Try to find airport from remaining 3-letter codes that aren't facilities
    if (!condition) {
        const threeLetterMatch = remaining.match(/\b([A-Z]{3})\b/g) || [];
        for (const code of threeLetterMatch) {
            if (!ARTCCS[code] && !TRACONS[code] && MAJOR_AIRPORTS.includes(code)) {
                condition = code;
                break;
            }
        }
    }
    
    let parsedFrom = fromFacility, parsedTo = toFacility;
    if (!parsedFrom || !parsedTo) {
        const facilityMatches = remaining.match(/\b([A-Z][A-Z0-9]{2,3})\b/g) || [];
        for (const fac of facilityMatches) {
            if (ARTCCS[fac] || TRACONS[fac]) {
                if (!parsedFrom) parsedFrom = fac;
                else if (!parsedTo) parsedTo = fac;
            }
        }
    }
    
    return {
        type: 'MIT',
        protocol: '05',
        distance: distance,
        fromFacility: parsedFrom,
        toFacility: parsedTo,
        condition: condition,
        viaRoute: viaRoute,
        isDepartures: isDepartures,
        isArrivals: isArrivals,
        qualifiers: qualifiers,
        exclusions: exclusions,
        reason: reason,
        rawReason: rawReason,
        validFrom: validFrom,
        validUntil: validUntil,
        isInternal: isSameARTCC(parsedFrom, parsedTo)
    };
}

/**
 * Enhanced MINIT Parser
 * Format: APT #MINIT [QUALIFIERS] REASON EXCL:xxx HHMM-HHMM REQ:PROV
 */
function parseMINIT_NLP(input) {
    const minitMatch = input.match(/(\d+)\s*MINIT/i);
    if (!minitMatch) return null;
    
    const minutes = parseInt(minitMatch[1]);
    let remaining = input.replace(/(\d+)\s*MINIT/i, ' ').trim();
    
    const { validFrom, validUntil, remaining: r1 } = extractTimeRange(remaining);
    remaining = r1;
    
    const { fromFacility, toFacility, remaining: r2 } = extractFacilityPair(remaining);
    remaining = r2;
    
    const { exclusions, remaining: r3 } = extractExclusions(remaining);
    remaining = r3;
    
    const { reason, rawReason, remaining: r4 } = extractReason(remaining);
    remaining = r4;
    
    const { qualifiers, remaining: r5 } = extractQualifiers(remaining);
    remaining = r5;
    
    // Extract via route (fix) - this is separate from the airport condition
    let viaRoute = null;
    const viaPattern = remaining.match(/VIA\s+([A-Z0-9,\/]+)/i);
    if (viaPattern) {
        viaRoute = viaPattern[1];
        remaining = remaining.replace(viaPattern[0], ' ');
    }
    
    // Find airports - these go in condition
    let condition = '';
    const airports = findAirports(remaining);
    if (airports.length > 0) {
        condition = airports.join(',');
    }
    
    // If no airport found, check for 5-letter fixes
    if (!condition && !viaRoute) {
        const fixes = findFixes(remaining);
        if (fixes.length > 0) {
            viaRoute = fixes[0];
        }
    }
    
    let parsedFrom = fromFacility, parsedTo = toFacility;
    if (!parsedFrom || !parsedTo) {
        const facilityMatches = remaining.match(/\b([A-Z][A-Z0-9]{2,3})\b/g) || [];
        for (const fac of facilityMatches) {
            if (ARTCCS[fac] || TRACONS[fac]) {
                if (!parsedFrom) parsedFrom = fac;
                else if (!parsedTo) parsedTo = fac;
            }
        }
    }
    
    return {
        type: 'MINIT',
        protocol: '06',
        minutes: minutes,
        fromFacility: parsedFrom,
        toFacility: parsedTo,
        condition: condition,
        viaRoute: viaRoute,
        qualifiers: qualifiers,
        exclusions: exclusions,
        reason: reason,
        rawReason: rawReason,
        validFrom: validFrom,
        validUntil: validUntil,
        isInternal: isSameARTCC(parsedFrom, parsedTo)
    };
}

/**
 * Enhanced Delay Parser
 */
function parseDelay_NLP(input) {
    if (!input.includes('DELAY')) return null;
    if (/\b(ED|AD|DD)\s+(?:FOR|FROM|TO)/i.test(input)) return null;
    
    let remaining = input.replace(/DELAY/i, ' ').trim();
    
    let minutes = null;
    const minMatch = remaining.match(/(\d+)\s*(?:MIN|M)?(?:UTES?)?/i);
    if (minMatch) {
        minutes = parseInt(minMatch[1]);
        remaining = remaining.replace(minMatch[0], ' ');
    }
    
    let trend = 'steady';
    if (/\b(?:INC(?:REASING)?|INCR)\b/i.test(remaining)) {
        trend = 'increasing';
        remaining = remaining.replace(/\b(?:INC(?:REASING)?|INCR)\b/i, ' ');
    } else if (/\b(?:DEC(?:REASING)?|DECR)\b/i.test(remaining)) {
        trend = 'decreasing';
        remaining = remaining.replace(/\b(?:DEC(?:REASING)?|DECR)\b/i, ' ');
    } else if (/\b(?:STD|STEADY)\b/i.test(remaining)) {
        trend = 'steady';
        remaining = remaining.replace(/\b(?:STD|STEADY)\b/i, ' ');
    }
    
    let flightsDelayed = 1;
    const fltMatch = remaining.match(/(\d+)\s*(?:FLT|FLIGHTS?)/i);
    if (fltMatch) {
        flightsDelayed = parseInt(fltMatch[1]);
        remaining = remaining.replace(fltMatch[0], ' ');
    }
    
    let facility = null;
    const airports = findAirports(remaining);
    if (airports.length > 0) {
        facility = airports[0];
    } else {
        const facMatch = remaining.match(/\b([A-Z]{3})\b/);
        if (facMatch) facility = facMatch[1];
    }
    
    const { reason } = extractReason(remaining);
    
    let holding = 'no';
    if (/\bHOLD(?:ING)?\b/i.test(remaining)) {
        holding = 'yes_initiating';
    }
    
    return {
        type: 'DELAY',
        protocol: '04',
        facility: facility,
        chargeFacility: facility,
        minutes: minutes,
        trend: trend,
        flightsDelayed: flightsDelayed,
        reason: reason,
        holding: holding
    };
}

/**
 * Enhanced Config Parser (Airport Configuration)
 */
function parseConfig_NLP(input) {
    const hasConfig = input.includes('CONFIG');
    const hasAARPattern = /AAR\s*\([^)]+\)\s*:\s*\d+/i.test(input) || /AAR:\d+/i.test(input);
    const hasRunwayPattern = /ARR:\s*[A-Z0-9\/]+/i.test(input) || /DEP:\s*[A-Z0-9\/]+/i.test(input);
    const hasWeatherCode = WEATHER_CONDITIONS.some(wx => input.includes(wx));
    
    if (!hasConfig && !hasAARPattern && !(hasRunwayPattern && hasWeatherCode)) return null;
    
    let remaining = input.replace(/CONFIG/i, ' ').trim();
    
    let airport = null;
    const airports = findAirports(remaining);
    if (airports.length > 0) {
        airport = airports[0];
        remaining = remaining.replace(new RegExp('\\b' + airport + '\\b'), ' ');
    }
    
    let weather = 'VMC';
    for (const wx of WEATHER_CONDITIONS) {
        if (remaining.includes(wx)) {
            weather = wx;
            remaining = remaining.replace(wx, ' ');
            break;
        }
    }
    
    let arrRunways = '', depRunways = '';
    const arrMatch = remaining.match(/ARR[:\s]*([A-Z0-9_\/]+)/i);
    if (arrMatch) {
        arrRunways = arrMatch[1].replace(/_/g, '/');
        remaining = remaining.replace(arrMatch[0], ' ');
    }
    
    const depMatch = remaining.match(/DEP[:\s]*([A-Z0-9_\/]+)/i);
    if (depMatch) {
        depRunways = depMatch[1].replace(/_/g, '/');
        remaining = remaining.replace(depMatch[0], ' ');
    }
    
    let aar = 60, aarType = 'Strat', aarAdjustment = null;
    const aarMatch = remaining.match(/AAR\s*\((\w+)\)\s*:\s*(\d+)/i);
    if (aarMatch) {
        aarType = aarMatch[1];
        aar = parseInt(aarMatch[2]);
        remaining = remaining.replace(aarMatch[0], ' ');
    } else {
        const simpleAar = remaining.match(/AAR[:\s]*(\d+)/i);
        if (simpleAar) {
            aar = parseInt(simpleAar[1]);
            remaining = remaining.replace(simpleAar[0], ' ');
        }
    }
    
    const adjMatch = remaining.match(/AAR\s*Adjustment[:\s]*([A-Z_\/]+)/i);
    if (adjMatch) {
        aarAdjustment = adjMatch[1];
        remaining = remaining.replace(adjMatch[0], ' ');
    }
    
    let adr = 60;
    const adrMatch = remaining.match(/ADR[:\s]*(\d+)/i);
    if (adrMatch) {
        adr = parseInt(adrMatch[1]);
    }
    
    const arrCount = arrRunways ? arrRunways.split('/').filter(r => r.match(/\d/)).length : 0;
    const depCount = depRunways ? depRunways.split('/').filter(r => r.match(/\d/)).length : 0;
    const singleRunway = (arrCount <= 1 && depCount <= 1) || /\bSINGLE\b/i.test(input);
    
    return {
        type: 'CONFIG',
        protocol: '01',
        airport: airport,
        weather: weather,
        arrRunways: arrRunways,
        depRunways: depRunways,
        aar: aar,
        aarType: aarType,
        aarAdjustment: aarAdjustment,
        adr: adr,
        singleRunway: singleRunway
    };
}

/**
 * Parse Other/Planning entries
 */
function parseOther_NLP(input) {
    if (input.includes('TYPE:PLANNING') || input.includes('PLANNING')) {
        const { validFrom, validUntil, remaining: r1 } = extractTimeRange(input);
        const { fromFacility, toFacility, remaining: r2 } = extractFacilityPair(r1);
        const { reason, remaining: r3 } = extractReason(r2);
        
        return {
            type: 'OTHER',
            protocol: '00',
            subType: 'PLANNING',
            condition: r3.trim(),
            fromFacility,
            toFacility,
            reason,
            validFrom,
            validUntil
        };
    }
    
    if (input.includes('AOB') || input.includes('AOA') || input.includes('NON-RVSM')) {
        const { validFrom, validUntil, remaining: r1 } = extractTimeRange(input);
        const { fromFacility, toFacility, remaining: r2 } = extractFacilityPair(r1);
        
        let altRestriction = null;
        const altMatch = input.match(/(?:AOB|AOA|ABV|BLW)\s*(?:FL)?(\d+)/i);
        if (altMatch) {
            altRestriction = altMatch[0];
        }
        
        return {
            type: 'OTHER',
            protocol: '00',
            subType: 'ALTITUDE',
            condition: altRestriction || input,
            fromFacility,
            toFacility,
            validFrom,
            validUntil
        };
    }
    
    const dirMatch = input.match(/\b(NORTH|SOUTH|EAST|WEST)\b/i);
    if (dirMatch && !input.match(/\d+\s*MIT/i)) {
        const { validFrom, validUntil, remaining: r1 } = extractTimeRange(input);
        const { fromFacility, toFacility, remaining: r2 } = extractFacilityPair(r1);
        const airports = findAirports(r2);
        
        return {
            type: 'OTHER',
            protocol: '00',
            subType: 'FLOW_DIRECTION',
            condition: airports.join(',') + ' ' + dirMatch[1],
            fromFacility,
            toFacility,
            validFrom,
            validUntil
        };
    }
    
    const { validFrom, validUntil } = extractTimeRange(input);
    const { fromFacility, toFacility } = extractFacilityPair(input);
    
    return {
        type: 'OTHER',
        protocol: '00',
        subType: 'GENERIC',
        condition: input.substring(0, 100),
        fromFacility,
        toFacility,
        validFrom,
        validUntil
    };
}

// ============================================
// HELPER FUNCTIONS
// ============================================

function findAirports(text) {
    const found = [];
    for (const apt of MAJOR_AIRPORTS) {
        if (new RegExp('\\b' + apt + '\\b').test(text)) {
            found.push(apt);
        }
    }
    return found;
}

function findFixes(text) {
    const found = [];
    for (const fix of COMMON_FIXES) {
        if (new RegExp('\\b' + fix + '\\b').test(text)) {
            found.push(fix);
        }
    }
    for (const nav of NAVAIDS) {
        if (new RegExp('\\b' + nav + '\\b').test(text)) {
            found.push(nav);
        }
    }
    return found;
}

function isSameARTCC(fac1, fac2) {
    if (!fac1 || !fac2) return false;
    const artcc1 = getARTCCForFacility(fac1);
    const artcc2 = getARTCCForFacility(fac2);
    return artcc1 && artcc2 && artcc1 === artcc2;
}

function getARTCCForFacility(fac) {
    if (!fac) return null;
    if (ARTCCS[fac]) return fac;
    
    const traconToArtcc = {
        'N90': 'ZNY', 'A90': 'ZBW', 'C90': 'ZAU', 'D10': 'ZFW',
        'PCT': 'ZDC', 'A80': 'ZTL', 'SCT': 'ZLA', 'NCT': 'ZOA',
        'I90': 'ZHU', 'D01': 'ZDV', 'S46': 'ZSE', 'L30': 'ZLA',
        'F11': 'ZMA', 'D21': 'ZOB', 'M98': 'ZMP', 'P50': 'ZAB',
        'P80': 'ZSE', 'Y90': 'ZBW'
    };
    
    if (traconToArtcc[fac]) return traconToArtcc[fac];
    
    const airportToArtcc = {
        'JFK': 'ZNY', 'LGA': 'ZNY', 'EWR': 'ZNY', 'TEB': 'ZNY', 'HPN': 'ZNY', 'ISP': 'ZNY',
        'BOS': 'ZBW', 'PVD': 'ZBW', 'BDL': 'ZBW',
        'ORD': 'ZAU', 'MDW': 'ZAU',
        'ATL': 'ZTL', 'CLT': 'ZTL',
        'DFW': 'ZFW', 'DAL': 'ZFW',
        'IAH': 'ZHU', 'HOU': 'ZHU',
        'LAX': 'ZLA', 'SAN': 'ZLA', 'LAS': 'ZLA', 'SNA': 'ZLA',
        'SFO': 'ZOA', 'OAK': 'ZOA', 'SJC': 'ZOA', 'SMF': 'ZOA',
        'SEA': 'ZSE', 'PDX': 'ZSE', 'GEG': 'ZSE',
        'DEN': 'ZDV', 'SLC': 'ZLC',
        'MIA': 'ZMA', 'FLL': 'ZMA', 'TPA': 'ZMA', 'RSW': 'ZMA', 'MCO': 'ZMA', 'JAX': 'ZJX',
        'DCA': 'ZDC', 'IAD': 'ZDC', 'BWI': 'ZDC',
        'PHL': 'ZNY',
        'DTW': 'ZOB', 'CLE': 'ZOB', 'PIT': 'ZOB', 'CVG': 'ZID',
        'MSP': 'ZMP', 'MCI': 'ZKC', 'STL': 'ZKC',
        'PHX': 'ZAB', 'ABQ': 'ZAB', 'TUS': 'ZAB',
        'MEM': 'ZME', 'BNA': 'ZME',
        'IND': 'ZID', 'SDF': 'ZID'
    };
    
    return airportToArtcc[fac] || null;
}

// ============================================
// VALIDATION
// ============================================

function validateEntry(entry) {
    const errors = [];
    
    switch (entry.type) {
        case 'MIT':
            if (!entry.distance || entry.distance < 1) errors.push({ field: 'distance', message: 'Distance is required' });
            if (!entry.fromFacility) errors.push({ field: 'fromFacility', message: 'Providing facility is required' });
            if (!entry.toFacility) errors.push({ field: 'toFacility', message: 'Requesting facility is required' });
            if (!entry.condition) errors.push({ field: 'condition', message: 'Condition (airport/fix) is required' });
            if (!entry.validFrom) errors.push({ field: 'validFrom', message: 'Valid from time is required' });
            if (!entry.validUntil) errors.push({ field: 'validUntil', message: 'Valid until time is required' });
            break;
            
        case 'MINIT':
            if (!entry.minutes || entry.minutes < 1) errors.push({ field: 'minutes', message: 'Minutes value is required' });
            if (!entry.fromFacility) errors.push({ field: 'fromFacility', message: 'Providing facility is required' });
            if (!entry.toFacility) errors.push({ field: 'toFacility', message: 'Requesting facility is required' });
            if (!entry.condition) errors.push({ field: 'condition', message: 'Condition (airport/fix) is required' });
            if (!entry.validFrom) errors.push({ field: 'validFrom', message: 'Valid from time is required' });
            if (!entry.validUntil) errors.push({ field: 'validUntil', message: 'Valid until time is required' });
            break;
            
        case 'STOP':
            if (!entry.fromFacility) errors.push({ field: 'fromFacility', message: 'Providing facility is required' });
            if (!entry.toFacility) errors.push({ field: 'toFacility', message: 'Requesting facility is required' });
            if (!entry.validFrom) errors.push({ field: 'validFrom', message: 'Valid from time is required' });
            if (!entry.validUntil) errors.push({ field: 'validUntil', message: 'Valid until time is required' });
            break;
            
        case 'APREQ':
        case 'CFR':
            if (!entry.fromFacility) errors.push({ field: 'fromFacility', message: 'Providing facility is required' });
            if (!entry.toFacility) errors.push({ field: 'toFacility', message: 'Requesting facility is required' });
            if (!entry.validFrom) errors.push({ field: 'validFrom', message: 'Valid from time is required' });
            if (!entry.validUntil) errors.push({ field: 'validUntil', message: 'Valid until time is required' });
            break;
            
        case 'TBM':
            if (!entry.condition) errors.push({ field: 'condition', message: 'Airport(s) required' });
            if (!entry.fromFacility) errors.push({ field: 'fromFacility', message: 'Providing facility is required' });
            if (!entry.toFacility) errors.push({ field: 'toFacility', message: 'Requesting facility is required' });
            break;
            
        case 'HOLDING':
            if (!entry.airport) errors.push({ field: 'airport', message: 'Airport is required' });
            break;
            
        case 'DELAY':
            if (!entry.facility) errors.push({ field: 'facility', message: 'Facility is required' });
            if (!entry.minutes || entry.minutes < 1) errors.push({ field: 'minutes', message: 'Delay duration is required' });
            break;
            
        case 'CONFIG':
            if (!entry.airport) errors.push({ field: 'airport', message: 'Airport is required' });
            if (!entry.arrRunways && !entry.depRunways) errors.push({ field: 'runways', message: 'At least one runway configuration is required' });
            break;
            
        case 'CANCEL':
            if (!entry.fromFacility && !entry.cancelType) errors.push({ field: 'fromFacility', message: 'Facility info required' });
            break;
            
        case 'REROUTE':
            if (!entry.route) errors.push({ field: 'route', message: 'Route is required' });
            break;
    }
    
    return errors;
}

function isEntryValid(entry) {
    return !entry.validationErrors || entry.validationErrors.length === 0;
}

// ============================================
// DETERMINANT CODE CALCULATION
// ============================================

function calculateDeterminant(entry) {
    switch (entry.type) {
        case 'MIT': return calculateMITDeterminant(entry);
        case 'MINIT': return calculateMINITDeterminant(entry);
        case 'DELAY': 
        case 'HOLDING': return calculateDelayDeterminant(entry);
        case 'CONFIG': return calculateConfigDeterminant(entry);
        case 'STOP': return '03O01';
        case 'APREQ':
        case 'CFR': return '07O01';
        case 'TBM': return '08O01';
        case 'CANCEL': return '00X00';
        case 'REROUTE': return '09O01';
        default: return '00O00';
    }
}

function calculateMITDeterminant(entry) {
    const d = entry.distance || 0;
    const internal = entry.isInternal;
    
    let level, subcode;
    
    if (d >= 60) { level = 'D'; subcode = internal ? '04' : '01'; }
    else if (d >= 40) { level = 'C'; subcode = internal ? '04' : '01'; }
    else if (d >= 25) { level = 'B'; subcode = internal ? '04' : '01'; }
    else if (d >= 15) { level = 'A'; subcode = internal ? '04' : '01'; }
    else { level = 'O'; subcode = '01'; }
    
    return `05${level}${subcode}`;
}

function calculateMINITDeterminant(entry) {
    const m = entry.minutes || 0;
    const internal = entry.isInternal;
    
    let level, subcode;
    
    if (m >= 30) { level = 'D'; subcode = internal ? '04' : '01'; }
    else if (m >= 20) { level = 'C'; subcode = internal ? '04' : '01'; }
    else if (m >= 13) { level = 'B'; subcode = internal ? '04' : '01'; }
    else if (m >= 7) { level = 'A'; subcode = internal ? '04' : '01'; }
    else { level = internal ? 'A' : 'O'; subcode = internal ? '04' : '01'; }
    
    return `06${level}${subcode}`;
}

function calculateDelayDeterminant(entry) {
    const d = entry.minutes || entry.delayMinutes || 0;
    const trend = entry.trend || entry.delayChange || 'steady';
    
    let level, baseCode;
    
    if (d >= 600) { level = 'D'; baseCode = 1; }
    else if (d >= 360) { level = 'D'; baseCode = 4; }
    else if (d >= 180) { level = 'C'; baseCode = 1; }
    else if (d >= 120) { level = 'C'; baseCode = 4; }
    else if (d >= 90) { level = 'B'; baseCode = 1; }
    else if (d >= 60) { level = 'B'; baseCode = 4; }
    else if (d >= 30) { level = 'A'; baseCode = 1; }
    else if (d >= 15) { level = 'A'; baseCode = 4; }
    else { level = 'O'; baseCode = 1; }
    
    const trendOffset = trend === 'increasing' || trend === 'initiating' ? 0 : 
                       (trend === 'steady' ? 1 : 2);
    const subcode = String(baseCode + trendOffset).padStart(2, '0');
    
    return `04${level}${subcode}`;
}

function calculateConfigDeterminant(entry) {
    const wx = entry.weather;
    const single = entry.singleRunway;
    const aar = entry.aar || 60;
    const adr = entry.adr || 60;
    
    let level = 'O', subcode = '01';
    
    if (single) {
        if (wx === 'VLIMC' || wx === 'LIMC') { level = 'E'; subcode = '01'; }
        else if (wx === 'IMC' && (aar <= 30 || adr <= 30)) { level = 'E'; subcode = '02'; }
        else if (aar <= 45 || adr <= 45) { level = 'E'; subcode = '03'; }
        else { level = 'D'; subcode = '01'; }
    } else {
        if (wx === 'VLIMC' || wx === 'LIMC') level = 'C';
        else if (wx === 'IMC') level = 'B';
        else if (wx === 'LVMC' || wx === 'MVMC') level = 'A';
        else level = 'O';
    }
    
    return `01${level}${subcode}`;
}

// ============================================
// QUEUE MANAGEMENT
// ============================================

function addFromQuickInput() {
    const input = $('#quickInput').val();
    const entry = parseEntry(input);
    
    if (entry) {
        if (!isEntryValid(entry)) {
            promptForMissingFields(entry, function(completedEntry) {
                entryQueue.push(completedEntry);
                $('#quickInput').val('').focus();
                renderQueue();
            });
        } else {
            entryQueue.push(entry);
            $('#quickInput').val('').focus();
            renderQueue();
        }
        hideAutocomplete();
    } else if (input.trim()) {
        Swal.fire({
            icon: 'error',
            title: 'Could not parse entry',
            html: `Check the syntax help for valid formats.<br><br>
                   <strong>Supported types:</strong><br>
                   MIT, MINIT, STOP, APREQ, CFR, TBM, CONFIG, CANCEL, E/D, A/D, D/D, REROUTE<br><br>
                   <strong>Examples:</strong><br>
                   <code>20MIT ZBW:ZNY JFK LENDY VOL</code><br>
                   <code>BOS STOP VOLUME:VOLUME 2345-0015 ZNY:PHL</code><br>
                   <code>CFR MIA departures 2100-0400 ZMA:F11</code>`,
            toast: false
        });
    }
}

function promptForMissingFields(entry, callback) {
    const errors = entry.validationErrors;
    
    let formHtml = '<div class="text-left">';
    formHtml += `<p class="mb-3">Parsed as <strong>${entry.type}</strong>: <code>${entry.raw}</code></p>`;
    formHtml += '<p class="text-warning mb-3"><i class="fas fa-exclamation-triangle"></i> Missing required fields:</p>';
    
    const fieldConfigs = {
        'fromFacility': { label: 'Providing Facility', placeholder: 'e.g., ZBW', type: 'text', maxlength: 4 },
        'toFacility': { label: 'Requesting Facility', placeholder: 'e.g., ZNY', type: 'text', maxlength: 4 },
        'condition': { label: 'Condition (Airport/Fix)', placeholder: 'e.g., JFK, LENDY', type: 'text', maxlength: 20 },
        'facility': { label: 'Facility', placeholder: 'e.g., JFK', type: 'text', maxlength: 4 },
        'airport': { label: 'Airport', placeholder: 'e.g., JFK', type: 'text', maxlength: 4 },
        'distance': { label: 'Distance (nm)', placeholder: 'e.g., 20', type: 'number', min: 1 },
        'minutes': { label: 'Minutes', placeholder: 'e.g., 15', type: 'number', min: 1 },
        'runways': { label: 'Arrival Runways', placeholder: 'e.g., 22L/22R', type: 'text' },
        'route': { label: 'Route', placeholder: 'e.g., PIEPE5 IBUFY SQS', type: 'text' },
        'validFrom': { label: 'Valid From (Zulu)', placeholder: '1400', type: 'text', maxlength: 4, value: $('#validFrom').val() },
        'validUntil': { label: 'Valid Until (Zulu)', placeholder: '1800', type: 'text', maxlength: 4, value: $('#validUntil').val() }
    };
    
    for (const error of errors) {
        const config = fieldConfigs[error.field] || { label: error.field, type: 'text' };
        const currentValue = entry[error.field] || config.value || '';
        
        formHtml += `
            <div class="form-group mb-2">
                <label class="small text-muted">${config.label}</label>
                <input type="${config.type}" 
                       class="form-control form-control-sm bg-dark text-light border-secondary" 
                       id="fix_${error.field}" 
                       placeholder="${config.placeholder || ''}"
                       value="${currentValue}"
                       ${config.maxlength ? `maxlength="${config.maxlength}"` : ''}
                       ${config.min ? `min="${config.min}"` : ''}>
            </div>
        `;
    }
    
    formHtml += '</div>';
    
    Swal.fire({
        title: 'Complete Entry',
        html: formHtml,
        showCancelButton: true,
        confirmButtonText: 'Add to Queue',
        cancelButtonText: 'Cancel',
        focusConfirm: false,
        customClass: {
            popup: 'bg-dark text-light'
        },
        preConfirm: () => {
            for (const error of errors) {
                const value = $(`#fix_${error.field}`).val();
                if (!value) {
                    Swal.showValidationMessage(`${error.message}`);
                    return false;
                }
                
                if (error.field === 'runways') {
                    entry.arrRunways = value;
                } else {
                    entry[error.field] = error.field === 'distance' || error.field === 'minutes' 
                        ? parseInt(value) 
                        : value.toUpperCase();
                }
            }
            
            entry.isInternal = isSameARTCC(entry.fromFacility, entry.toFacility);
            entry.determinant = calculateDeterminant(entry);
            entry.validationErrors = validateEntry(entry);
            
            return entry;
        }
    }).then((result) => {
        if (result.isConfirmed && result.value) {
            callback(result.value);
        }
    });
}

function parseBatchInput() {
    const lines = $('#batchInput').val().split('\n').filter(l => l.trim());
    let added = 0, needsReview = 0;
    const errors = [];
    const pendingEntries = [];
    
    for (const line of lines) {
        const entry = parseEntry(line);
        if (entry) {
            if (isEntryValid(entry)) {
                entryQueue.push(entry);
                added++;
            } else {
                pendingEntries.push(entry);
                needsReview++;
            }
        } else {
            errors.push(line);
        }
    }
    
    if (added > 0 || needsReview > 0) {
        $('#batchInput').val(errors.join('\n'));
        renderQueue();
    }
    
    let message = '';
    if (added > 0) message += `${added} entries added. `;
    if (needsReview > 0) message += `${needsReview} need review. `;
    if (errors.length > 0) message += `${errors.length} could not be parsed.`;
    
    if (needsReview > 0) {
        processIncompleteEntries(pendingEntries, 0);
    } else if (message) {
        Swal.fire({
            icon: errors.length > 0 ? 'warning' : 'success',
            title: 'Batch Processing Complete',
            text: message,
            toast: true,
            position: 'top-end',
            timer: 3000,
            showConfirmButton: false
        });
    }
}

function processIncompleteEntries(entries, index) {
    if (index >= entries.length) {
        renderQueue();
        Swal.fire({
            icon: 'success',
            title: 'Batch review complete',
            toast: true,
            position: 'top-end',
            timer: 2000,
            showConfirmButton: false
        });
        return;
    }
    
    promptForMissingFields(entries[index], function(completedEntry) {
        entryQueue.push(completedEntry);
        processIncompleteEntries(entries, index + 1);
    });
}

function removeFromQueue(index) {
    entryQueue.splice(index, 1);
    renderQueue();
}

function editQueueEntry(index) {
    const entry = entryQueue[index];
    $('#quickInput').val(entry.raw).focus();
    entryQueue.splice(index, 1);
    renderQueue();
}

function renderQueue() {
    const container = $('#entryQueue');
    const count = entryQueue.length;
    
    $('#queueCount').text(count);
    $('#clearQueue').toggle(count > 0);
    $('#submitArea').toggle(count > 0);
    $('#submitCount').text(count);
    $('#emptyQueueMsg').toggle(count === 0);
    
    if (count === 0) {
        container.html(`
            <div class="text-center text-muted py-4" id="emptyQueueMsg">
                <i class="fas fa-inbox fa-2x mb-2"></i><br>
                No entries queued. Type above to add entries.
            </div>
        `);
        return;
    }
    
    let html = '';
    entryQueue.forEach((entry, index) => {
        const pillClass = entry.type.toLowerCase().replace('/', '-');
        const message = buildDiscordMessage(entry);
        const isValid = isEntryValid(entry);
        
        html += `
            <div class="preview-card ${isValid ? 'valid' : 'invalid'}">
                <button class="remove-btn" onclick="removeFromQueue(${index})" title="Remove">
                    <i class="fas fa-times"></i>
                </button>
                <button class="remove-btn" style="right: 35px;" onclick="editQueueEntry(${index})" title="Edit">
                    <i class="fas fa-edit"></i>
                </button>
                <span class="protocol-pill ${pillClass}">${entry.type}</span>
                <span class="determinant ml-2">[${entry.determinant}]</span>
                ${!isValid ? '<span class="badge badge-danger ml-2">Incomplete</span>' : ''}
                <div class="details">${escapeHtml(message.split('\n')[0])}</div>
            </div>
        `;
    });
    
    container.html(html);
}

// ============================================
// TEMPLATES
// ============================================

function applyTemplate(template) {
    const templates = {
        'mit-arr': '20MIT ZBW→ZNY JFK LENDY VOLUME:VOLUME EXCL:NONE 2300-0300',
        'mit-dep': '15MIT ZNY→ZDC EWR DEPARTURES VOLUME:VOLUME EXCL:NONE 2300-0300',
        'minit': '10MINIT ZOB→ZNY CLE ARRIVALS VOLUME:VOLUME EXCL:NONE 2300-0300',
        'delay': 'DELAY JFK 45min INC 12flt WEATHER',
        'config': 'JFK VMC ARR:ILS_31R DEP:31L AAR(Strat):58 ADR:24',
        'gs': 'JFK STOP VOLUME:VOLUME EXCL:NONE 2300-0100 ZNY:N90',
        'stop': 'BOS STOP VOLUME:VOLUME EXCL:NONE 2345-0015 ZBW:ZNY',
        'apreq': 'APREQ JFK departures via J220 TYPE:ALL VOLUME:VOLUME 2330-0400 ZNY:ZDC',
        'cfr': 'CFR MIA,FLL departures TYPE:ALL VOLUME:VOLUME 2100-0400 ZMA:F11',
        'tbm': 'ATL TBM 3_WEST VOLUME:VOLUME EXCL:NONE 2230-0400 ZTL:ZJX,ZME',
        'holding': 'ZDC E/D for BOS, +30/0019/13 ACFT VOLUME:VOLUME',
        'cancel': 'CANCEL JFK via CAMRN 20MIT ZNY:ZDC'
    };
    
    const text = templates[template];
    if (text) {
        if (currentMode === 'single') {
            $('#quickInput').val(text).focus();
        } else {
            const current = $('#batchInput').val();
            $('#batchInput').val(current + (current ? '\n' : '') + text).focus();
        }
    }
}

// ============================================
// AUTOCOMPLETE
// ============================================

let autocompleteIndex = -1;

function showAutocomplete(input) {
    const words = input.split(/\s+/);
    const lastWord = (words[words.length - 1] || '').toUpperCase();
    
    if (lastWord.length < 2) {
        hideAutocomplete();
        return;
    }
    
    const suggestions = [];
    
    for (const [id, name] of Object.entries(ARTCCS)) {
        if (id.startsWith(lastWord) || id.includes(lastWord)) {
            suggestions.push({ id, name, type: 'ARTCC' });
        }
    }
    
    for (const [id, name] of Object.entries(TRACONS)) {
        if (id.startsWith(lastWord) || id.includes(lastWord)) {
            suggestions.push({ id, name, type: 'TRACON' });
        }
    }
    
    for (const apt of MAJOR_AIRPORTS) {
        if (apt.startsWith(lastWord) || apt.includes(lastWord)) {
            suggestions.push({ id: apt, name: apt + ' Airport', type: 'Airport' });
        }
    }
    
    for (const fix of COMMON_FIXES) {
        if (fix.startsWith(lastWord) || fix.includes(lastWord)) {
            suggestions.push({ id: fix, name: fix, type: 'Fix' });
        }
    }
    
    if (suggestions.length === 0) {
        hideAutocomplete();
        return;
    }
    
    autocompleteIndex = -1;
    
    let html = '';
    suggestions.slice(0, 10).forEach((s, i) => {
        html += `
            <div class="autocomplete-item" data-value="${s.id}" data-index="${i}">
                <span class="facility-id">${s.id}</span>
                <span class="facility-name ml-2">${s.name} (${s.type})</span>
            </div>
        `;
    });
    
    $('#autocompleteDropdown').html(html).addClass('show');
    
    $('.autocomplete-item').click(function() {
        selectAutocompleteItem($(this).data('value'));
    });
}

function navigateAutocomplete(direction) {
    const items = $('.autocomplete-item');
    if (items.length === 0) return;
    
    items.removeClass('selected');
    autocompleteIndex += direction;
    
    if (autocompleteIndex < 0) autocompleteIndex = items.length - 1;
    if (autocompleteIndex >= items.length) autocompleteIndex = 0;
    
    $(items[autocompleteIndex]).addClass('selected');
}

function selectAutocompleteItem(value) {
    const words = $('#quickInput').val().split(/\s+/);
    words[words.length - 1] = value;
    $('#quickInput').val(words.join(' ') + ' ').focus();
    hideAutocomplete();
}

function hideAutocomplete() {
    $('#autocompleteDropdown').removeClass('show');
    autocompleteIndex = -1;
}

// ============================================
// MESSAGE BUILDING
// Formats match historical NTML_2020.txt format:
// DD/HHMM APT [direction] via FIX ##MIT [QUALIFIERS] REASON EXCL:xxx HHMM-HHMM REQ:PROV
// ============================================

/**
 * Get current log time in DD/HHMM format
 */
function getLogTime() {
    const now = new Date();
    const dd = String(now.getUTCDate()).padStart(2, '0');
    const hh = String(now.getUTCHours()).padStart(2, '0');
    const mm = String(now.getUTCMinutes()).padStart(2, '0');
    return `${dd}/${hh}${mm}`;
}

function buildDiscordMessage(entry) {
    switch (entry.type) {
        case 'MIT': return buildMITMessage(entry);
        case 'MINIT': return buildMINITMessage(entry);
        case 'STOP': return buildStopMessage(entry);
        case 'APREQ':
        case 'CFR': return buildAPREQMessage(entry);
        case 'TBM': return buildTBMMessage(entry);
        case 'HOLDING': return buildHoldingMessage(entry);
        case 'DELAY': return buildDelayMessage(entry);
        case 'CONFIG': return buildConfigMessage(entry);
        case 'CANCEL': return buildCancelMessage(entry);
        case 'REROUTE': return buildRerouteMessage(entry);
        case 'OTHER': return buildOtherMessage(entry);
        default: return `[${entry.determinant}] ${entry.type}`;
    }
}

/**
 * Build MIT NTML entry
 * Format: DD/HHMM APT [arrivals] via FIX ##MIT [QUALIFIERS] REASON EXCL:xxx HHMM-HHMM REQ:PROV
 */
function buildMITMessage(e) {
    const logTime = getLogTime();
    const apt = e.condition || '???';
    const fix = e.viaRoute || '';
    const dist = e.distance || '??';
    const quals = (e.qualifiers && e.qualifiers.length) ? ' ' + e.qualifiers.join(' ').replace(/_/g, ' ') : '';
    const reason = e.rawReason || 'VOLUME:VOLUME';
    const excl = e.exclusions || 'NONE';
    const validTime = `${e.validFrom || '????'}-${e.validUntil || '????'}`;
    const facPair = `${e.toFacility || '???'}:${e.fromFacility || '???'}`;
    
    let msg = `${logTime}    ${apt}`;
    if (e.isDepartures) {
        msg += ' departures';
    }
    if (fix) {
        msg += ` via ${fix}`;
    }
    msg += ` ${dist}MIT${quals} ${reason} EXCL:${excl} ${validTime} ${facPair}`;
    return msg;
}

/**
 * Build MINIT NTML entry
 * Format: DD/HHMM APT #MINIT REASON EXCL:xxx HHMM-HHMM REQ:PROV
 */
function buildMINITMessage(e) {
    const logTime = getLogTime();
    const apt = e.condition || '???';
    const mins = e.minutes || '??';
    const quals = (e.qualifiers && e.qualifiers.length) ? ' ' + e.qualifiers.join(' ').replace(/_/g, ' ') : '';
    const reason = e.rawReason || 'VOLUME:VOLUME';
    const excl = e.exclusions || 'NONE';
    const validTime = `${e.validFrom || '????'}-${e.validUntil || '????'}`;
    const facPair = `${e.toFacility || '???'}:${e.fromFacility || '???'}`;
    
    let msg = `${logTime}    ${apt} ${mins}MINIT${quals} ${reason} EXCL:${excl} ${validTime} ${facPair}`;
    return msg;
}

/**
 * Build STOP NTML entry
 * Format: DD/HHMM APT[,APT] [direction] STOP REASON EXCL:xxx HHMM-HHMM REQ:PROV
 */
function buildStopMessage(e) {
    const logTime = getLogTime();
    const apt = e.condition || '???';
    const reason = e.rawReason || 'VOLUME:VOLUME';
    const excl = e.exclusions || 'NONE';
    const validTime = `${e.validFrom || '????'}-${e.validUntil || '????'}`;
    const facPair = `${e.toFacility || '???'}:${e.fromFacility || '???'}`;
    
    let msg = `${logTime}    ${apt}`;
    if (e.isDepartures) msg += ' departures';
    if (e.isArrivals) msg += ' arrivals';
    if (e.viaRoute) msg += ` via ${e.viaRoute}`;
    msg += ` STOP ${reason} EXCL:${excl} ${validTime} ${facPair}`;
    return msg;
}

/**
 * Build APREQ/CFR NTML entry
 * Format: DD/HHMM TYPE APT departures [via FIX] [TYPE:xx] REASON EXCL:xxx HHMM-HHMM REQ:PROV
 */
function buildAPREQMessage(e) {
    const logTime = getLogTime();
    const type = e.type;
    const apt = e.condition || '???';
    const reason = e.rawReason || 'VOLUME:VOLUME';
    const excl = e.exclusions || 'NONE';
    const validTime = `${e.validFrom || '????'}-${e.validUntil || '????'}`;
    const facPair = `${e.toFacility || '???'}:${e.fromFacility || '???'}`;
    const quals = (e.qualifiers && e.qualifiers.length) ? ' ' + e.qualifiers.join(' ').replace(/_/g, ' ') : '';
    
    let msg = `${logTime}    ${type} ${apt}`;
    if (e.isDepartures) msg += ' departures';
    if (e.viaRoute) msg += ` via ${e.viaRoute}`;
    msg += `${quals} ${reason} EXCL:${excl} ${validTime} ${facPair}`;
    return msg;
}

/**
 * Build TBM NTML entry
 * Format: DD/HHMM APT TBM SECTOR REASON EXCL:xxx HHMM-HHMM REQ:PROV
 */
function buildTBMMessage(e) {
    const logTime = getLogTime();
    const apt = e.condition || '???';
    const sector = e.sector || '';
    const reason = e.rawReason || 'VOLUME:VOLUME';
    const excl = e.exclusions || 'NONE';
    const validTime = `${e.validFrom || '????'}-${e.validUntil || '????'}`;
    const facPair = `${e.toFacility || '???'}:${e.fromFacility || '???'}`;
    
    let msg = `${logTime}    ${apt} TBM`;
    if (sector) msg += ` ${sector}`;
    msg += ` ${reason} EXCL:${excl} ${validTime} ${facPair}`;
    return msg;
}

/**
 * Build Holding/Delay NTML entry (E/D, A/D, D/D)
 * Format: DD/HHMM [FAC] TYPE prep APT, +/-value/HHMM[/# ACFT] [NAVAID:xx] REASON
 */
function buildHoldingMessage(e) {
    const logTime = getLogTime();
    const typeMap = { 'ED': 'E/D', 'AD': 'A/D', 'DD': 'D/D' };
    const typeDisplay = typeMap[e.delayType] || e.delayType;
    
    // Preposition based on type
    let prep = 'from'; // D/D = from [departure airport]
    if (typeDisplay === 'E/D') prep = 'for';  // E/D = for [destination]
    if (typeDisplay === 'A/D') prep = 'to';   // A/D = to [arrival airport]
    
    const apt = e.airport || '???';
    const reason = e.rawReason || 'VOLUME:VOLUME';
    
    // Build delay value
    let delayValue = '';
    if (e.isHolding) {
        const sign = (e.delayChange === 'initiating' || e.delayChange === 'increasing') ? '+' : '-';
        delayValue = `${sign}Holding`;
    } else if (e.delayMinutes) {
        const sign = (e.delayChange === 'increasing') ? '+' : '-';
        delayValue = `${sign}${e.delayMinutes}`;
    }
    
    const reportTime = e.delayTime || '????';
    
    let msg = `${logTime}`;
    if (e.reportingFacility) msg += `    ${e.reportingFacility}`;
    msg += ` ${typeDisplay} ${prep} ${apt}, ${delayValue}/${reportTime}`;
    if (e.acftCount) msg += `/${e.acftCount} ACFT`;
    if (e.navaid) msg += ` NAVAID:${e.navaid}`;
    if (e.isStream) msg += ' STREAM';
    if (e.isLateNote) msg += ' LATE NOTE';
    msg += ` ${reason}`;
    return msg;
}

/**
 * Build simple Delay NTML entry (D/D style)
 * Format: DD/HHMM D/D from APT, +/-##/HHMM REASON
 */
function buildDelayMessage(e) {
    const logTime = getLogTime();
    const apt = e.facility || e.chargeFacility || '???';
    const mins = e.minutes || '??';
    const trend = e.trend || 'steady';
    const sign = (trend === 'increasing') ? '+' : (trend === 'decreasing') ? '-' : '';
    const reason = e.reason || 'VOLUME';
    const reportTime = logTime.split('/')[1]; // Use current time as report time
    
    let msg = `${logTime}     D/D from ${apt}, ${sign}${mins}/${reportTime} ${reason}:${reason}`;
    return msg;
}

/**
 * Build Config NTML entry
 * Format: DD/HHMM APT    WX    ARR:rwys DEP:rwys    AAR(type):##    [AAR Adjustment:xx]    ADR:##
 */
function buildConfigMessage(e) {
    const logTime = getLogTime();
    const apt = e.airport || '???';
    const wx = e.weather || 'VMC';
    const arrRwys = e.arrRunways || '-';
    const depRwys = e.depRunways || '-';
    const aar = e.aar || '60';
    const aarType = e.aarType || 'Strat';
    const adr = e.adr || '60';
    
    let msg = `${logTime}    ${apt}    ${wx}    ARR:${arrRwys} DEP:${depRwys}    AAR(${aarType}):${aar}`;
    if (e.aarAdjustment) msg += ` AAR Adjustment:${e.aarAdjustment}`;
    msg += `    ADR:${adr}`;
    return msg;
}

/**
 * Build Cancel NTML entry
 */
function buildCancelMessage(e) {
    const logTime = getLogTime();
    
    if (e.cancelType === 'ALL') {
        return `${logTime}    ALL TMI CANCELLED`;
    }
    
    let msg = `${logTime}    CANCEL`;
    if (e.condition) msg += ` ${e.condition}`;
    if (e.viaRoute) msg += ` via ${e.viaRoute}`;
    if (e.mitValue) msg += ` ${e.mitValue}MIT`;
    if (e.fromFacility || e.toFacility) msg += ` ${e.toFacility || ''}:${e.fromFacility || ''}`;
    return msg;
}

/**
 * Build Reroute NTML entry
 */
function buildRerouteMessage(e) {
    const logTime = getLogTime();
    const apt = e.condition || '???';
    const reason = e.rawReason || 'VOLUME:VOLUME';
    const excl = e.exclusions || 'NONE';
    const validTime = `${e.validFrom || '????'}-${e.validUntil || '????'}`;
    const facPair = `${e.toFacility || '???'}:${e.fromFacility || '???'}`;
    
    let msg = `${logTime}    REROUTE ${apt}`;
    if (e.route) msg += ` via ${e.route}`;
    msg += ` ${reason} EXCL:${excl} ${validTime} ${facPair}`;
    return msg;
}

/**
 * Build Other/Generic NTML entry
 */
function buildOtherMessage(e) {
    const logTime = getLogTime();
    let msg = `${logTime}    ${e.subType || 'OTHER'}`;
    if (e.condition) msg += ` ${e.condition}`;
    if (e.fromFacility || e.toFacility) msg += ` ${e.toFacility || ''}:${e.fromFacility || ''}`;
    if (e.validFrom) msg += ` ${e.validFrom}-${e.validUntil || '????'}`;
    return msg;
}

// ============================================
// PREVIEW & SUBMIT
// ============================================

function showPreview() {
    let content = '';
    entryQueue.forEach((entry, i) => {
        content += `--- Entry ${i + 1} ---\n`;
        content += buildDiscordMessage(entry);
        content += '\n\n';
    });
    
    $('#previewContent').text(content);
    $('#previewModal').modal('show');
}

function submitAll() {
    if (entryQueue.length === 0) return;
    
    const incomplete = entryQueue.filter(e => !isEntryValid(e));
    if (incomplete.length > 0) {
        Swal.fire({
            icon: 'error',
            title: 'Incomplete Entries',
            text: `${incomplete.length} entries have missing required fields. Please complete them before submitting.`
        });
        return;
    }
    
    const confirmMsg = productionMode 
        ? `Submit ${entryQueue.length} entries to LIVE Discord?`
        : `Submit ${entryQueue.length} entries to staging?`;
    
    Swal.fire({
        title: 'Confirm Submission',
        text: confirmMsg,
        icon: productionMode ? 'warning' : 'question',
        showCancelButton: true,
        confirmButtonColor: productionMode ? '#dc3545' : '#28a745',
        confirmButtonText: 'Submit All'
    }).then((result) => {
        if (result.isConfirmed) {
            processSubmissions();
        }
    });
}

async function processSubmissions() {
    $('#previewModal').modal('hide');
    
    Swal.fire({
        title: 'Submitting...',
        html: `<div id="submitProgress">0 / ${entryQueue.length}</div>`,
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    let success = 0, failed = 0;
    const errors = [];
    
    for (let i = 0; i < entryQueue.length; i++) {
        const entry = entryQueue[i];
        $('#submitProgress').text(`${i + 1} / ${entryQueue.length}`);
        
        try {
            const result = await submitEntry(entry);
            if (result.success) {
                success++;
            } else {
                failed++;
                errors.push(result.error || 'Unknown error');
            }
        } catch (e) {
            failed++;
            errors.push(e.message || 'Network error');
        }
        
        if (i < entryQueue.length - 1) {
            await new Promise(r => setTimeout(r, 500));
        }
    }
    
    entryQueue = [];
    renderQueue();
    
    if (failed === 0) {
        Swal.fire({
            icon: 'success',
            title: 'All Submitted!',
            text: `${success} entries posted to Discord.`
        });
    } else {
        Swal.fire({
            icon: 'warning',
            title: 'Partially Completed',
            html: `${success} succeeded, ${failed} failed.<br><small>${errors[0]}</small>`
        });
    }
}

function submitEntry(entry) {
    return new Promise((resolve, reject) => {
        const postData = buildPostData(entry);
        postData.determinant = entry.determinant;
        postData.production = productionMode ? '1' : '0';
        
        $.ajax({
            type: 'POST',
            url: 'api/mgt/ntml/post.php',
            data: postData,
            success: function(response) {
                try {
                    const data = typeof response === 'string' ? JSON.parse(response) : response;
                    resolve(data);
                } catch (e) {
                    reject(new Error('Invalid response'));
                }
            },
            error: function(xhr, status, error) {
                reject(new Error(error || 'Request failed'));
            }
        });
    });
}

function buildPostData(entry) {
    const base = {
        type: entry.type,
        protocol: entry.protocol,
        determinant: entry.determinant,
        raw: entry.raw,
        reason: entry.reason,
        raw_reason: entry.rawReason,
        valid_from: entry.validFrom,
        valid_until: entry.validUntil,
        from_facility: entry.fromFacility,
        to_facility: entry.toFacility,
        exclusions: entry.exclusions,
        qualifiers: (entry.qualifiers || []).join(',')
    };
    
    switch (entry.type) {
        case 'MIT':
            return { ...base, distance: entry.distance, condition: entry.condition, 
                     via_route: entry.viaRoute,
                     is_departures: entry.isDepartures, is_arrivals: entry.isArrivals,
                     is_internal: entry.isInternal };
        case 'MINIT':
            return { ...base, minutes: entry.minutes, condition: entry.condition,
                     via_route: entry.viaRoute,
                     is_internal: entry.isInternal };
        case 'STOP':
            return { ...base, condition: entry.condition, via_route: entry.viaRoute,
                     is_departures: entry.isDepartures, is_arrivals: entry.isArrivals };
        case 'APREQ':
        case 'CFR':
            return { ...base, condition: entry.condition, via_route: entry.viaRoute,
                     is_departures: entry.isDepartures };
        case 'TBM':
            return { ...base, condition: entry.condition, sector: entry.sector,
                     is_begin: entry.isBegin, is_end: entry.isEnd, via_simtraffic: entry.viaSimtraffic };
        case 'HOLDING':
            return { ...base, delay_type: entry.delayType, airport: entry.airport,
                     reporting_facility: entry.reportingFacility, delay_change: entry.delayChange,
                     delay_minutes: entry.delayMinutes, delay_time: entry.delayTime,
                     acft_count: entry.acftCount, is_holding: entry.isHolding, navaid: entry.navaid };
        case 'DELAY':
            return { ...base, facility: entry.facility, minutes: entry.minutes,
                     trend: entry.trend, flights_delayed: entry.flightsDelayed, holding: entry.holding };
        case 'CONFIG':
            return { ...base, airport: entry.airport, weather: entry.weather,
                     arr_runways: entry.arrRunways, dep_runways: entry.depRunways,
                     aar: entry.aar, aar_type: entry.aarType, aar_adjustment: entry.aarAdjustment,
                     adr: entry.adr, single_runway: entry.singleRunway };
        case 'CANCEL':
            return { ...base, cancel_type: entry.cancelType, condition: entry.condition,
                     via_route: entry.viaRoute, mit_value: entry.mitValue };
        case 'REROUTE':
            return { ...base, condition: entry.condition, route: entry.route };
        case 'OTHER':
            return { ...base, sub_type: entry.subType, condition: entry.condition };
        default:
            return base;
    }
}

// ============================================
// UTILITIES
// ============================================

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Global functions for onclick handlers
window.removeFromQueue = removeFromQueue;
window.editQueueEntry = editQueueEntry;
