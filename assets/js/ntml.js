/**
 * NTML Quick Entry - Streamlined Client-Side Logic
 * Enhanced NLP parsing, batch processing, validation prompts
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
    'ZSE': 'Seattle Center', 'ZTL': 'Atlanta Center'
};

const TRACONS = {
    'A80': 'Atlanta TRACON', 'A90': 'Boston TRACON', 'C90': 'Chicago TRACON',
    'D01': 'Denver TRACON', 'D10': 'Dallas TRACON', 'D21': 'Detroit TRACON',
    'I90': 'Houston TRACON', 'L30': 'Las Vegas TRACON', 'M98': 'Minneapolis TRACON',
    'N90': 'New York TRACON', 'NCT': 'NorCal TRACON', 'P50': 'Phoenix TRACON',
    'P80': 'Portland TRACON', 'PCT': 'Potomac TRACON', 'S46': 'Seattle TRACON',
    'SCT': 'SoCal TRACON', 'Y90': 'Yankee TRACON'
};

const MAJOR_AIRPORTS = [
    'ATL', 'BOS', 'BWI', 'CLT', 'DCA', 'DEN', 'DFW', 'DTW', 'EWR', 'FLL', 
    'IAD', 'IAH', 'JFK', 'LAS', 'LAX', 'LGA', 'MCO', 'MDW', 'MIA', 'MSP', 
    'ORD', 'PHL', 'PHX', 'SAN', 'SEA', 'SFO', 'SLC', 'TPA'
];

// Common fixes/waypoints for autocomplete
const COMMON_FIXES = [
    'CAMRN', 'LENDY', 'BIGGY', 'MERIT', 'DIXIE', 'COATE', 'NEION', 'GAYEL',
    'KORRY', 'LANNA', 'PARCH', 'ROBER', 'SKIPY', 'WAVEY', 'WHITE', 'ZIGGI',
    'BETTE', 'PARKE', 'GREKI', 'JUDDS', 'VALRE', 'ELIOT', 'LEEAH', 'HAAYS'
];

const REASONS = ['WEATHER', 'VOLUME', 'RUNWAY', 'EQUIPMENT', 'OTHER', 'WX', 'VOL', 'RWY', 'EQUIP'];
const QUALIFIERS = ['HEAVY', 'B757', 'LARGE', 'SMALL', 'EACH', 'AS_ONE', 'PER_FIX', 'AS ONE', 'PER FIX'];
const WEATHER_CONDITIONS = ['VMC', 'LVMC', 'IMC', 'LIMC', 'VLIMC'];

// Reason aliases
const REASON_MAP = {
    'WX': 'WEATHER', 'VOL': 'VOLUME', 'RWY': 'RUNWAY', 'EQUIP': 'EQUIPMENT',
    'WEATHER': 'WEATHER', 'VOLUME': 'VOLUME', 'RUNWAY': 'RUNWAY', 
    'EQUIPMENT': 'EQUIPMENT', 'OTHER': 'OTHER'
};

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
            // Tab to accept autocomplete
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
// ENHANCED NLP PARSING ENGINE
// ============================================

function parseEntry(input) {
    input = input.trim().toUpperCase();
    if (!input) return null;
    
    // Normalize common separators
    input = normalizeInput(input);
    
    // Try each parser in order of specificity
    let result = parseMIT_NLP(input) || parseMINIT_NLP(input) || parseDelay_NLP(input) || parseConfig_NLP(input);
    
    if (result) {
        result.raw = input;
        result.determinant = calculateDeterminant(result);
        result.validationErrors = validateEntry(result);
    }
    
    return result;
}

function normalizeInput(input) {
    // Normalize various separators and formats
    return input
        .replace(/\s*:\s*/g, ':')           // ZDC : ZJX -> ZDC:ZJX
        .replace(/\s*->\s*/g, '→')           // -> to arrow
        .replace(/\s*>\s*/g, '→')            // > to arrow
        .replace(/\s+TO\s+/gi, '→')          // TO to arrow
        .replace(/\s*→\s*/g, '→')            // Clean up arrows
        .replace(/(\d+)\s*(MIT|MINIT)/gi, '$1$2')  // 40 MIT -> 40MIT
        .replace(/(\d+)\s*MIN\b/gi, '$1min') // 45 MIN -> 45min
        .replace(/(\d+)\s*FLT\b/gi, '$1flt') // 12 FLT -> 12flt
        .trim();
}

/**
 * Enhanced MIT Parser - handles many formats:
 * - 20MIT ZBW→ZNY JFK LENDY VOLUME
 * - ZMA TO JFK VIA CAMRN 40MIT ZDC:ZJX
 * - 40MIT ZDC:ZJX JFK CAMRN WEATHER
 * - JFK ARR 20MIT FROM ZBW VIA LENDY
 */
function parseMIT_NLP(input) {
    // Extract MIT value first (required)
    const mitMatch = input.match(/(\d+)\s*MIT/i);
    if (!mitMatch) return null;
    
    const distance = parseInt(mitMatch[1]);
    let remaining = input.replace(/(\d+)\s*MIT/i, ' ').trim();
    
    // Extract facilities - look for various patterns
    let fromFacility = null, toFacility = null;
    
    // Pattern: ZDC:ZJX or ZDC-ZJX (from:to notation)
    const colonPattern = remaining.match(/([A-Z]{3}):([A-Z0-9]{3})/);
    if (colonPattern) {
        fromFacility = colonPattern[1];
        toFacility = colonPattern[2];
        remaining = remaining.replace(colonPattern[0], ' ');
    }
    
    // Pattern: ZBW→ZNY
    const arrowPattern = remaining.match(/([A-Z0-9]{2,4})→([A-Z0-9]{2,4})/);
    if (arrowPattern && !fromFacility) {
        fromFacility = arrowPattern[1];
        toFacility = arrowPattern[2];
        remaining = remaining.replace(arrowPattern[0], ' ');
    }
    
    // Pattern: FROM ZBW or ZBW FROM
    const fromPattern = remaining.match(/(?:FROM\s+)?([A-Z]{3})(?:\s+FROM)?/i);
    if (fromPattern && !fromFacility) {
        // Check if this is likely the from facility (ARTCC code)
        if (ARTCCS[fromPattern[1]] || TRACONS[fromPattern[1]]) {
            fromFacility = fromPattern[1];
            remaining = remaining.replace(fromPattern[0], ' ');
        }
    }
    
    // Extract condition (airport, fix, VIA waypoint)
    let condition = '';
    
    // Pattern: VIA CAMRN or VIA LENDY
    const viaPattern = remaining.match(/VIA\s+([A-Z0-9]{3,5})/i);
    if (viaPattern) {
        condition = viaPattern[1];
        remaining = remaining.replace(viaPattern[0], ' ');
    }
    
    // Look for airport codes
    const airports = findAirports(remaining);
    if (airports.length > 0) {
        // First airport is likely the destination/condition
        if (!toFacility && MAJOR_AIRPORTS.includes(airports[0])) {
            // If it looks like an airport and we don't have a to facility
            if (!condition) condition = airports[0];
        }
        if (!condition && airports[0]) {
            condition = airports[0];
        }
        remaining = remaining.replace(new RegExp('\\b' + airports[0] + '\\b'), ' ');
    }
    
    // Look for fixes in remaining text
    const fixes = findFixes(remaining);
    if (fixes.length > 0 && !condition) {
        condition = fixes[0];
        remaining = remaining.replace(new RegExp('\\b' + fixes[0] + '\\b'), ' ');
    }
    
    // Look for any 5-letter words that might be fixes
    const fiveLetterMatch = remaining.match(/\b([A-Z]{5})\b/);
    if (fiveLetterMatch && !condition) {
        condition = fiveLetterMatch[1];
        remaining = remaining.replace(fiveLetterMatch[0], ' ');
    }
    
    // Extract reason
    let reason = 'VOLUME';
    for (const r of Object.keys(REASON_MAP)) {
        if (remaining.includes(r)) {
            reason = REASON_MAP[r];
            remaining = remaining.replace(new RegExp('\\b' + r + '\\b', 'i'), ' ');
            break;
        }
    }
    
    // Extract qualifiers
    const { qualifiers } = extractQualifiers(remaining);
    
    // Extract time range if present (1400-1800 or 1400Z-1800Z)
    let validFrom = $('#validFrom').val();
    let validUntil = $('#validUntil').val();
    const timePattern = remaining.match(/(\d{4})Z?\s*[-–]\s*(\d{4})Z?/);
    if (timePattern) {
        validFrom = timePattern[1];
        validUntil = timePattern[2];
    }
    
    // Try to identify any remaining facility codes
    if (!fromFacility || !toFacility) {
        const facilityMatches = remaining.match(/\b([A-Z][A-Z0-9]{2})\b/g) || [];
        for (const fac of facilityMatches) {
            if (ARTCCS[fac] || TRACONS[fac]) {
                if (!fromFacility) fromFacility = fac;
                else if (!toFacility) toFacility = fac;
            }
        }
    }
    
    return {
        type: 'MIT',
        protocol: '05',
        distance: distance,
        fromFacility: fromFacility,
        toFacility: toFacility,
        condition: condition,
        qualifiers: qualifiers,
        reason: reason,
        validFrom: validFrom,
        validUntil: validUntil,
        isInternal: isSameARTCC(fromFacility, toFacility)
    };
}

/**
 * Enhanced MINIT Parser
 */
function parseMINIT_NLP(input) {
    const minitMatch = input.match(/(\d+)\s*MINIT/i);
    if (!minitMatch) return null;
    
    const minutes = parseInt(minitMatch[1]);
    let remaining = input.replace(/(\d+)\s*MINIT/i, ' ').trim();
    
    // Reuse MIT parsing logic for facilities and conditions
    let fromFacility = null, toFacility = null, condition = '';
    
    // Pattern: ZDC:ZJX
    const colonPattern = remaining.match(/([A-Z]{3}):([A-Z0-9]{3})/);
    if (colonPattern) {
        fromFacility = colonPattern[1];
        toFacility = colonPattern[2];
        remaining = remaining.replace(colonPattern[0], ' ');
    }
    
    // Pattern: ZBW→ZNY
    const arrowPattern = remaining.match(/([A-Z0-9]{2,4})→([A-Z0-9]{2,4})/);
    if (arrowPattern && !fromFacility) {
        fromFacility = arrowPattern[1];
        toFacility = arrowPattern[2];
        remaining = remaining.replace(arrowPattern[0], ' ');
    }
    
    // VIA pattern
    const viaPattern = remaining.match(/VIA\s+([A-Z0-9]{3,5})/i);
    if (viaPattern) {
        condition = viaPattern[1];
        remaining = remaining.replace(viaPattern[0], ' ');
    }
    
    // Look for airports and fixes
    const airports = findAirports(remaining);
    if (airports.length > 0 && !condition) {
        condition = airports[0];
        remaining = remaining.replace(new RegExp('\\b' + airports[0] + '\\b'), ' ');
    }
    
    const fixes = findFixes(remaining);
    if (fixes.length > 0 && !condition) {
        condition = fixes[0];
    }
    
    // Extract reason
    let reason = 'VOLUME';
    for (const r of Object.keys(REASON_MAP)) {
        if (remaining.includes(r)) {
            reason = REASON_MAP[r];
            break;
        }
    }
    
    const { qualifiers } = extractQualifiers(remaining);
    
    // Time
    let validFrom = $('#validFrom').val();
    let validUntil = $('#validUntil').val();
    const timePattern = remaining.match(/(\d{4})Z?\s*[-–]\s*(\d{4})Z?/);
    if (timePattern) {
        validFrom = timePattern[1];
        validUntil = timePattern[2];
    }
    
    // Remaining facility codes
    if (!fromFacility || !toFacility) {
        const facilityMatches = remaining.match(/\b([A-Z][A-Z0-9]{2})\b/g) || [];
        for (const fac of facilityMatches) {
            if (ARTCCS[fac] || TRACONS[fac]) {
                if (!fromFacility) fromFacility = fac;
                else if (!toFacility) toFacility = fac;
            }
        }
    }
    
    return {
        type: 'MINIT',
        protocol: '06',
        minutes: minutes,
        fromFacility: fromFacility,
        toFacility: toFacility,
        condition: condition,
        qualifiers: qualifiers,
        reason: reason,
        validFrom: validFrom,
        validUntil: validUntil,
        isInternal: isSameARTCC(fromFacility, toFacility)
    };
}

/**
 * Enhanced Delay Parser
 * Handles: DELAY JFK 45min INC 12flt WEATHER
 *          JFK DELAY 45 INCREASING 12 FLIGHTS WX
 */
function parseDelay_NLP(input) {
    if (!input.includes('DELAY')) return null;
    
    let remaining = input.replace(/DELAY/i, ' ').trim();
    
    // Extract delay duration
    let minutes = null;
    const minMatch = remaining.match(/(\d+)\s*(?:MIN|M)?(?:UTES?)?/i);
    if (minMatch) {
        minutes = parseInt(minMatch[1]);
        remaining = remaining.replace(minMatch[0], ' ');
    }
    
    // Extract trend
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
    
    // Extract flights count
    let flightsDelayed = 1;
    const fltMatch = remaining.match(/(\d+)\s*(?:FLT|FLIGHTS?)/i);
    if (fltMatch) {
        flightsDelayed = parseInt(fltMatch[1]);
        remaining = remaining.replace(fltMatch[0], ' ');
    }
    
    // Extract facility
    let facility = null;
    const airports = findAirports(remaining);
    if (airports.length > 0) {
        facility = airports[0];
    } else {
        // Look for any 3-letter code
        const facMatch = remaining.match(/\b([A-Z]{3})\b/);
        if (facMatch) facility = facMatch[1];
    }
    
    // Extract reason
    let reason = 'VOLUME';
    for (const r of Object.keys(REASON_MAP)) {
        if (remaining.includes(r)) {
            reason = REASON_MAP[r];
            break;
        }
    }
    
    // Holding detection
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
 * Enhanced Config Parser
 */
function parseConfig_NLP(input) {
    if (!input.includes('CONFIG')) return null;
    
    let remaining = input.replace(/CONFIG/i, ' ').trim();
    
    // Extract airport
    let airport = null;
    const airports = findAirports(remaining);
    if (airports.length > 0) {
        airport = airports[0];
        remaining = remaining.replace(new RegExp('\\b' + airport + '\\b'), ' ');
    }
    
    // Extract weather
    let weather = 'VMC';
    for (const wx of WEATHER_CONDITIONS) {
        if (remaining.includes(wx)) {
            weather = wx;
            remaining = remaining.replace(wx, ' ');
            break;
        }
    }
    
    // Extract runways
    let arrRunways = '', depRunways = '';
    const arrMatch = remaining.match(/ARR[:\s]*([A-Z0-9\/]+)/i);
    if (arrMatch) {
        arrRunways = arrMatch[1];
        remaining = remaining.replace(arrMatch[0], ' ');
    }
    
    const depMatch = remaining.match(/DEP[:\s]*([A-Z0-9\/]+)/i);
    if (depMatch) {
        depRunways = depMatch[1];
        remaining = remaining.replace(depMatch[0], ' ');
    }
    
    // Extract rates
    let aar = 60, adr = 60;
    const aarMatch = remaining.match(/AAR[:\s]*(\d+)/i);
    if (aarMatch) aar = parseInt(aarMatch[1]);
    
    const adrMatch = remaining.match(/ADR[:\s]*(\d+)/i);
    if (adrMatch) adr = parseInt(adrMatch[1]);
    
    // Detect single runway
    const arrCount = arrRunways ? arrRunways.split('/').length : 0;
    const depCount = depRunways ? depRunways.split('/').length : 0;
    const singleRunway = (arrCount <= 1 && depCount <= 1) || /\bSINGLE\b/i.test(remaining);
    
    return {
        type: 'CONFIG',
        protocol: '01',
        airport: airport,
        weather: weather,
        arrRunways: arrRunways,
        depRunways: depRunways,
        aar: aar,
        adr: adr,
        singleRunway: singleRunway
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
    return found;
}

function extractQualifiers(text) {
    const found = [];
    let remaining = text;
    
    for (const q of QUALIFIERS) {
        const normalized = q.replace(/[_\s]+/g, '[_\\s]?');
        const regex = new RegExp('\\b' + normalized + '\\b', 'gi');
        if (regex.test(remaining)) {
            found.push(q.replace(/\s+/g, '_'));
            remaining = remaining.replace(regex, '').trim();
        }
    }
    
    return { condition: remaining, qualifiers: found };
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
        'I90': 'ZHU', 'D01': 'ZDV', 'S46': 'ZSE', 'L30': 'ZLA'
    };
    
    if (traconToArtcc[fac]) return traconToArtcc[fac];
    
    const airportToArtcc = {
        'JFK': 'ZNY', 'LGA': 'ZNY', 'EWR': 'ZNY', 'TEB': 'ZNY',
        'BOS': 'ZBW', 'PVD': 'ZBW', 'BDL': 'ZBW',
        'ORD': 'ZAU', 'MDW': 'ZAU',
        'ATL': 'ZTL', 'CLT': 'ZTL',
        'DFW': 'ZFW', 'DAL': 'ZFW',
        'IAH': 'ZHU', 'HOU': 'ZHU',
        'LAX': 'ZLA', 'SAN': 'ZLA', 'LAS': 'ZLA',
        'SFO': 'ZOA', 'OAK': 'ZOA', 'SJC': 'ZOA',
        'SEA': 'ZSE', 'PDX': 'ZSE',
        'DEN': 'ZDV',
        'MIA': 'ZMA', 'FLL': 'ZMA', 'TPA': 'ZMA',
        'DCA': 'ZDC', 'IAD': 'ZDC', 'BWI': 'ZDC',
        'PHL': 'ZNY',
        'DTW': 'ZOB', 'CLE': 'ZOB',
        'MSP': 'ZMP',
        'PHX': 'ZAB'
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
            
        case 'DELAY':
            if (!entry.facility) errors.push({ field: 'facility', message: 'Facility is required' });
            if (!entry.minutes || entry.minutes < 1) errors.push({ field: 'minutes', message: 'Delay duration is required' });
            break;
            
        case 'CONFIG':
            if (!entry.airport) errors.push({ field: 'airport', message: 'Airport is required' });
            if (!entry.arrRunways && !entry.depRunways) errors.push({ field: 'runways', message: 'At least one runway configuration is required' });
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
    switch (entry.protocol) {
        case '05': return calculateMITDeterminant(entry);
        case '06': return calculateMINITDeterminant(entry);
        case '04': return calculateDelayDeterminant(entry);
        case '01': return calculateConfigDeterminant(entry);
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
    const d = entry.minutes || 0;
    const trend = entry.trend;
    
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
    
    const trendOffset = trend === 'increasing' ? 0 : (trend === 'steady' ? 1 : 2);
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
        else if (wx === 'LVMC') level = 'A';
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
            // Prompt for missing fields
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
            html: 'Check the syntax help for valid formats.<br><br>Try formats like:<br><code>20MIT ZBW→ZNY JFK LENDY VOL</code>',
            toast: false
        });
    }
}

function promptForMissingFields(entry, callback) {
    const errors = entry.validationErrors;
    
    // Build form HTML for missing fields
    let formHtml = '<div class="text-left">';
    formHtml += `<p class="mb-3">Parsed: <code>${entry.raw}</code></p>`;
    formHtml += '<p class="text-warning mb-3"><i class="fas fa-exclamation-triangle"></i> Missing required fields:</p>';
    
    const fieldConfigs = {
        'fromFacility': { label: 'Providing Facility', placeholder: 'e.g., ZBW', type: 'text', maxlength: 4 },
        'toFacility': { label: 'Requesting Facility', placeholder: 'e.g., ZNY', type: 'text', maxlength: 4 },
        'condition': { label: 'Condition (Airport/Fix)', placeholder: 'e.g., JFK, LENDY', type: 'text', maxlength: 10 },
        'facility': { label: 'Facility', placeholder: 'e.g., JFK', type: 'text', maxlength: 4 },
        'airport': { label: 'Airport', placeholder: 'e.g., JFK', type: 'text', maxlength: 4 },
        'distance': { label: 'Distance (nm)', placeholder: 'e.g., 20', type: 'number', min: 1 },
        'minutes': { label: 'Minutes', placeholder: 'e.g., 15', type: 'number', min: 1 },
        'runways': { label: 'Arrival Runways', placeholder: 'e.g., 22L/22R', type: 'text' },
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
            // Gather values
            for (const error of errors) {
                const value = $(`#fix_${error.field}`).val();
                if (!value) {
                    Swal.showValidationMessage(`${error.message}`);
                    return false;
                }
                
                // Update entry with new value
                if (error.field === 'runways') {
                    entry.arrRunways = value;
                } else {
                    entry[error.field] = error.field === 'distance' || error.field === 'minutes' 
                        ? parseInt(value) 
                        : value.toUpperCase();
                }
            }
            
            // Recalculate determinant and validation
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
    
    // Report results
    let message = '';
    if (added > 0) message += `${added} entries added. `;
    if (needsReview > 0) message += `${needsReview} need review. `;
    if (errors.length > 0) message += `${errors.length} could not be parsed.`;
    
    if (needsReview > 0) {
        // Process entries needing review one by one
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
    
    // Put raw text back in input for editing
    $('#quickInput').val(entry.raw).focus();
    
    // Remove from queue
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
        const pillClass = entry.type.toLowerCase();
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
        'mit-arr': '20MIT ZBW→ZNY JFK LENDY VOLUME',
        'mit-dep': '15MIT ZNY→ZDC EWR DEPARTURES VOLUME',
        'minit': '10MINIT ZOB→ZNY CLE ARRIVALS WEATHER',
        'delay': 'DELAY JFK 45min INC 12flt WEATHER',
        'config': 'CONFIG JFK IMC ARR:22L/22R DEP:31L AAR:40 ADR:45',
        'gs': 'DELAY JFK 120min STEADY 50flt WEATHER'
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
    
    // Search ARTCCs
    for (const [id, name] of Object.entries(ARTCCS)) {
        if (id.startsWith(lastWord) || id.includes(lastWord)) {
            suggestions.push({ id, name, type: 'ARTCC' });
        }
    }
    
    // Search TRACONs
    for (const [id, name] of Object.entries(TRACONS)) {
        if (id.startsWith(lastWord) || id.includes(lastWord)) {
            suggestions.push({ id, name, type: 'TRACON' });
        }
    }
    
    // Search airports
    for (const apt of MAJOR_AIRPORTS) {
        if (apt.startsWith(lastWord) || apt.includes(lastWord)) {
            suggestions.push({ id: apt, name: apt + ' Airport', type: 'Airport' });
        }
    }
    
    // Search fixes
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
// ============================================

function buildDiscordMessage(entry) {
    switch (entry.type) {
        case 'MIT': return buildMITMessage(entry);
        case 'MINIT': return buildMINITMessage(entry);
        case 'DELAY': return buildDelayMessage(entry);
        case 'CONFIG': return buildConfigMessage(entry);
        default: return `[${entry.determinant}] ${entry.type}`;
    }
}

function buildMITMessage(e) {
    let msg = `**[${e.determinant}] ${e.distance}MIT** ${e.fromFacility || '???'}→${e.toFacility || '???'} ${e.condition || ''}`;
    if (e.qualifiers && e.qualifiers.length) msg += ' ' + e.qualifiers.join(' ');
    msg += `\nValid: ${e.validFrom || '????'}Z-${e.validUntil || '????'}Z`;
    msg += `\nReason: ${e.reason}`;
    return msg;
}

function buildMINITMessage(e) {
    let msg = `**[${e.determinant}] ${e.minutes}MINIT** ${e.fromFacility || '???'}→${e.toFacility || '???'} ${e.condition || ''}`;
    if (e.qualifiers && e.qualifiers.length) msg += ' ' + e.qualifiers.join(' ');
    msg += `\nValid: ${e.validFrom || '????'}Z-${e.validUntil || '????'}Z`;
    msg += `\nReason: ${e.reason}`;
    return msg;
}

function buildDelayMessage(e) {
    const trendDisplay = e.trend ? e.trend.charAt(0).toUpperCase() + e.trend.slice(1) : 'Steady';
    let msg = `**[${e.determinant}] DELAY** ${e.facility || '???'}`;
    msg += `\nLongest: ${e.minutes || '?'}min | Trend: ${trendDisplay} | Flights: ${e.flightsDelayed || 1}`;
    msg += `\nReason: ${e.reason}`;
    return msg;
}

function buildConfigMessage(e) {
    let msg = `**[${e.determinant}] AIRPORT CONFIG** ${e.airport || '???'}`;
    msg += `\nWeather: ${e.weather} | Single RWY: ${e.singleRunway ? 'Yes' : 'No'}`;
    if (e.arrRunways) msg += `\nARR: ${e.arrRunways}`;
    if (e.depRunways) msg += ` | DEP: ${e.depRunways}`;
    msg += `\nAAR: ${e.aar} | ADR: ${e.adr}`;
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
    
    // Check for incomplete entries
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
        
        // Delay between submissions
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
    switch (entry.type) {
        case 'MIT':
            return {
                protocol: '05',
                condition: entry.condition,
                restriction_type: 'arrival',
                req_facility_type: 'ARTCC',
                req_facility_id: entry.toFacility,
                prov_facility_type: 'ARTCC',
                prov_facility_id: entry.fromFacility,
                same_artcc: entry.isInternal ? 'yes' : 'no',
                distance: entry.distance,
                qualifiers: (entry.qualifiers || []).join(','),
                reason: entry.reason,
                valid_from: entry.validFrom,
                valid_until: entry.validUntil
            };
        case 'MINIT':
            return {
                protocol: '06',
                condition: entry.condition,
                restriction_type: 'arrival',
                req_facility_type: 'ARTCC',
                req_facility_id: entry.toFacility,
                prov_facility_type: 'ARTCC',
                prov_facility_id: entry.fromFacility,
                same_artcc: entry.isInternal ? 'yes' : 'no',
                minutes: entry.minutes,
                qualifiers: (entry.qualifiers || []).join(','),
                reason: entry.reason,
                valid_from: entry.validFrom,
                valid_until: entry.validUntil
            };
        case 'DELAY':
            return {
                protocol: '04',
                delay_type: 'arrival',
                timeframe: 'now',
                delay_facility: entry.facility,
                charge_facility: entry.chargeFacility,
                flights_delayed: entry.flightsDelayed,
                longest_delay: entry.minutes,
                delay_trend: entry.trend,
                holding: entry.holding,
                reason: entry.reason
            };
        case 'CONFIG':
            return {
                protocol: '01',
                airport: entry.airport,
                weather: entry.weather,
                arr_runways: entry.arrRunways,
                dep_runways: entry.depRunways,
                single_runway: entry.singleRunway ? 'yes' : 'no',
                aar: entry.aar,
                adr: entry.adr,
                fleet_mix: 'light'
            };
        default:
            return { protocol: '00' };
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
