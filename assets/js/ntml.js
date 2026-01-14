/**
 * NTML Quick Entry - Streamlined Client-Side Logic
 * Parses natural language entries, batch processing, auto-detection
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
    'ATL', 'BOS', 'CLT', 'DCA', 'DEN', 'DFW', 'DTW', 'EWR', 'FLL', 'IAD', 'IAH',
    'JFK', 'LAS', 'LAX', 'LGA', 'MCO', 'MDW', 'MIA', 'MSP', 'ORD', 'PHL', 'PHX',
    'SAN', 'SEA', 'SFO', 'SLC', 'TPA'
];

const REASONS = ['WEATHER', 'VOLUME', 'RUNWAY', 'EQUIPMENT', 'OTHER'];
const QUALIFIERS = ['HEAVY', 'B757', 'LARGE', 'SMALL', 'EACH', 'AS_ONE', 'PER_FIX'];
const WEATHER_CONDITIONS = ['VMC', 'LVMC', 'IMC', 'LIMC', 'VLIMC'];

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
        }
    });
    
    // Batch input - Ctrl+Enter to parse and add
    $('#batchInput').on('keydown', function(e) {
        if (e.ctrlKey && e.key === 'Enter') {
            e.preventDefault();
            parseBatchInput();
        }
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
    
    // Auto-uppercase facility inputs
    $('#quickInput').on('input', function() {
        // Don't auto-uppercase everything, just help with parsing
        showAutocomplete($(this).val());
    });
    
    // Valid time auto-advance
    $('#validFrom').on('input', function() {
        if ($(this).val().length === 4) {
            $('#validUntil').focus();
        }
    });
}

function initializeTimeDefaults() {
    // Set current Zulu time as default
    const now = new Date();
    const zuluHours = String(now.getUTCHours()).padStart(2, '0');
    const zuluMins = String(now.getUTCMinutes()).padStart(2, '0');
    $('#validFrom').val(zuluHours + zuluMins);
    
    // Default end time +2 hours
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
// PARSING ENGINE
// ============================================

function parseEntry(input) {
    input = input.trim().toUpperCase();
    if (!input) return null;
    
    // Try each parser
    let result = parseMIT(input) || parseMINIT(input) || parseDelay(input) || parseConfig(input);
    
    if (result) {
        result.raw = input;
        result.determinant = calculateDeterminant(result);
    }
    
    return result;
}

function parseMIT(input) {
    // Pattern: [distance]MIT [from]→[to] [condition] [reason] [time-range]
    // Also supports: [distance]MIT [from]->[to], [from]>[to], [from] [to]
    const mitPattern = /^(\d+)\s*MIT\s+([A-Z0-9]{2,4})\s*(?:→|->|>|TO)\s*([A-Z0-9]{2,4})\s+(.+?)(?:\s+(WEATHER|VOLUME|RUNWAY|EQUIPMENT|OTHER))?(?:\s+(\d{4})-(\d{4}))?$/i;
    
    // Simpler pattern without arrow
    const mitSimplePattern = /^(\d+)\s*MIT\s+([A-Z0-9]{2,4})\s+([A-Z0-9]{2,4})\s+(.+?)(?:\s+(WEATHER|VOLUME|RUNWAY|EQUIPMENT|OTHER))?(?:\s+(\d{4})-(\d{4}))?$/i;
    
    let match = input.match(mitPattern) || input.match(mitSimplePattern);
    if (!match) return null;
    
    const [, distance, fromFac, toFac, conditionPart, reason, validFrom, validUntil] = match;
    
    // Parse condition for qualifiers
    const { condition, qualifiers } = extractQualifiers(conditionPart);
    
    return {
        type: 'MIT',
        protocol: '05',
        distance: parseInt(distance),
        fromFacility: fromFac,
        toFacility: toFac,
        condition: condition.trim(),
        qualifiers: qualifiers,
        reason: reason || 'VOLUME',
        validFrom: validFrom || $('#validFrom').val(),
        validUntil: validUntil || $('#validUntil').val(),
        isInternal: isSameARTCC(fromFac, toFac)
    };
}

function parseMINIT(input) {
    // Pattern: [minutes]MINIT [from]→[to] [condition] [reason]
    const minitPattern = /^(\d+)\s*MINIT\s+([A-Z0-9]{2,4})\s*(?:→|->|>|TO)\s*([A-Z0-9]{2,4})\s+(.+?)(?:\s+(WEATHER|VOLUME|RUNWAY|EQUIPMENT|OTHER))?(?:\s+(\d{4})-(\d{4}))?$/i;
    const minitSimplePattern = /^(\d+)\s*MINIT\s+([A-Z0-9]{2,4})\s+([A-Z0-9]{2,4})\s+(.+?)(?:\s+(WEATHER|VOLUME|RUNWAY|EQUIPMENT|OTHER))?(?:\s+(\d{4})-(\d{4}))?$/i;
    
    let match = input.match(minitPattern) || input.match(minitSimplePattern);
    if (!match) return null;
    
    const [, minutes, fromFac, toFac, conditionPart, reason, validFrom, validUntil] = match;
    const { condition, qualifiers } = extractQualifiers(conditionPart);
    
    return {
        type: 'MINIT',
        protocol: '06',
        minutes: parseInt(minutes),
        fromFacility: fromFac,
        toFacility: toFac,
        condition: condition.trim(),
        qualifiers: qualifiers,
        reason: reason || 'VOLUME',
        validFrom: validFrom || $('#validFrom').val(),
        validUntil: validUntil || $('#validUntil').val(),
        isInternal: isSameARTCC(fromFac, toFac)
    };
}

function parseDelay(input) {
    // Pattern: DELAY [facility] [minutes]min [trend] [flights]flt [reason]
    const delayPattern = /^DELAY\s+([A-Z0-9]{2,4})\s+(\d+)\s*(?:MIN|M)?\s*(INC(?:REASING)?|DEC(?:REASING)?|STEADY|STD)?\s*(\d+)?\s*(?:FLT|FLIGHTS?)?\s*(WEATHER|VOLUME|RUNWAY|EQUIPMENT|OTHER)?/i;
    
    let match = input.match(delayPattern);
    if (!match) return null;
    
    const [, facility, minutes, trendRaw, flights, reason] = match;
    
    // Normalize trend
    let trend = 'steady';
    if (trendRaw) {
        const t = trendRaw.toUpperCase();
        if (t.startsWith('INC')) trend = 'increasing';
        else if (t.startsWith('DEC')) trend = 'decreasing';
    }
    
    return {
        type: 'DELAY',
        protocol: '04',
        facility: facility,
        chargeFacility: facility, // Default same
        minutes: parseInt(minutes),
        trend: trend,
        flightsDelayed: parseInt(flights) || 1,
        reason: reason || 'VOLUME',
        holding: 'no'
    };
}

function parseConfig(input) {
    // Pattern: CONFIG [airport] [wx] ARR:[rwys] DEP:[rwys] AAR:[n] ADR:[n]
    const configPattern = /^CONFIG\s+([A-Z0-9]{3,4})\s+(VMC|LVMC|IMC|LIMC|VLIMC)?\s*(?:ARR[:\s]*([A-Z0-9\/]+))?\s*(?:DEP[:\s]*([A-Z0-9\/]+))?\s*(?:AAR[:\s]*(\d+))?\s*(?:ADR[:\s]*(\d+))?/i;
    
    let match = input.match(configPattern);
    if (!match) return null;
    
    const [, airport, weather, arrRwys, depRwys, aar, adr] = match;
    
    // Detect single runway
    const arrCount = arrRwys ? arrRwys.split('/').length : 0;
    const depCount = depRwys ? depRwys.split('/').length : 0;
    const singleRunway = (arrCount <= 1 && depCount <= 1);
    
    return {
        type: 'CONFIG',
        protocol: '01',
        airport: airport,
        weather: weather || 'VMC',
        arrRunways: arrRwys || '',
        depRunways: depRwys || '',
        aar: parseInt(aar) || 60,
        adr: parseInt(adr) || 60,
        singleRunway: singleRunway
    };
}

function extractQualifiers(text) {
    const found = [];
    let remaining = text;
    
    for (const q of QUALIFIERS) {
        const regex = new RegExp('\\b' + q.replace('_', '[_\\s]?') + '\\b', 'gi');
        if (regex.test(remaining)) {
            found.push(q);
            remaining = remaining.replace(regex, '').trim();
        }
    }
    
    return { condition: remaining, qualifiers: found };
}

function isSameARTCC(fac1, fac2) {
    // Get ARTCC for each facility
    const artcc1 = getARTCCForFacility(fac1);
    const artcc2 = getARTCCForFacility(fac2);
    
    return artcc1 && artcc2 && artcc1 === artcc2;
}

function getARTCCForFacility(fac) {
    // If it's already an ARTCC
    if (ARTCCS[fac]) return fac;
    
    // TRACON to ARTCC mapping (simplified)
    const traconToArtcc = {
        'N90': 'ZNY', 'A90': 'ZBW', 'C90': 'ZAU', 'D10': 'ZFW',
        'PCT': 'ZDC', 'A80': 'ZTL', 'SCT': 'ZLA', 'NCT': 'ZOA',
        'I90': 'ZHU', 'D01': 'ZDV', 'S46': 'ZSE', 'L30': 'ZLA'
    };
    
    if (traconToArtcc[fac]) return traconToArtcc[fac];
    
    // Airport to ARTCC (simplified - major airports)
    const airportToArtcc = {
        'JFK': 'ZNY', 'LGA': 'ZNY', 'EWR': 'ZNY', 'BOS': 'ZBW',
        'ORD': 'ZAU', 'MDW': 'ZAU', 'ATL': 'ZTL', 'CLT': 'ZTL',
        'DFW': 'ZFW', 'IAH': 'ZHU', 'LAX': 'ZLA', 'SFO': 'ZOA',
        'SEA': 'ZSE', 'DEN': 'ZDV', 'MIA': 'ZMA', 'DCA': 'ZDC',
        'IAD': 'ZDC', 'PHL': 'ZNY', 'DTW': 'ZOB', 'MSP': 'ZMP'
    };
    
    return airportToArtcc[fac] || null;
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
    const d = entry.distance;
    const internal = entry.isInternal;
    
    let level, subcode;
    
    if (d >= 60) {
        level = 'D';
        subcode = internal ? '04' : '01';
    } else if (d >= 40) {
        level = 'C';
        subcode = internal ? '04' : '01';
    } else if (d >= 25) {
        level = 'B';
        subcode = internal ? '04' : '01';
    } else if (d >= 15) {
        level = 'A';
        subcode = internal ? '04' : '01';
    } else {
        level = 'O';
        subcode = '01';
    }
    
    return `05${level}${subcode}`;
}

function calculateMINITDeterminant(entry) {
    const m = entry.minutes;
    const internal = entry.isInternal;
    
    let level, subcode;
    
    if (m >= 30) {
        level = 'D';
        subcode = internal ? '04' : '01';
    } else if (m >= 20) {
        level = 'C';
        subcode = internal ? '04' : '01';
    } else if (m >= 13) {
        level = 'B';
        subcode = internal ? '04' : '01';
    } else if (m >= 7) {
        level = 'A';
        subcode = internal ? '04' : '01';
    } else {
        level = internal ? 'A' : 'O';
        subcode = internal ? '04' : '01';
    }
    
    return `06${level}${subcode}`;
}

function calculateDelayDeterminant(entry) {
    const d = entry.minutes;
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
    const aar = entry.aar;
    const adr = entry.adr;
    
    let level = 'O', subcode = '01';
    
    if (single) {
        if (wx === 'VLIMC' || wx === 'LIMC') {
            level = 'E'; subcode = '01';
        } else if (wx === 'IMC' && (aar <= 30 || adr <= 30)) {
            level = 'E'; subcode = '02';
        } else if (aar <= 45 || adr <= 45) {
            level = 'E'; subcode = '03';
        } else {
            level = 'D'; subcode = '01';
        }
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
        entryQueue.push(entry);
        $('#quickInput').val('').focus();
        renderQueue();
        hideAutocomplete();
    } else if (input.trim()) {
        // Show error for unparseable input
        Swal.fire({
            icon: 'error',
            title: 'Could not parse entry',
            text: 'Check the syntax help for valid formats.',
            toast: true,
            position: 'top-end',
            timer: 3000,
            showConfirmButton: false
        });
    }
}

function parseBatchInput() {
    const lines = $('#batchInput').val().split('\n').filter(l => l.trim());
    let added = 0, failed = 0;
    const errors = [];
    
    for (const line of lines) {
        const entry = parseEntry(line);
        if (entry) {
            entryQueue.push(entry);
            added++;
        } else {
            failed++;
            errors.push(line);
        }
    }
    
    if (added > 0) {
        $('#batchInput').val('');
        renderQueue();
    }
    
    if (failed > 0) {
        Swal.fire({
            icon: 'warning',
            title: `${added} added, ${failed} failed`,
            html: `Could not parse:<br><code>${errors.slice(0, 3).join('<br>')}</code>${errors.length > 3 ? '<br>...' : ''}`
        });
    } else if (added > 0) {
        Swal.fire({
            icon: 'success',
            title: `${added} entries added`,
            toast: true,
            position: 'top-end',
            timer: 2000,
            showConfirmButton: false
        });
    }
}

function removeFromQueue(index) {
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
        container.html($('#emptyQueueMsg').prop('outerHTML'));
        return;
    }
    
    let html = '';
    entryQueue.forEach((entry, index) => {
        const pillClass = entry.type.toLowerCase();
        const message = buildDiscordMessage(entry);
        
        html += `
            <div class="preview-card valid">
                <button class="remove-btn" onclick="removeFromQueue(${index})">
                    <i class="fas fa-times"></i>
                </button>
                <span class="protocol-pill ${pillClass}">${entry.type}</span>
                <span class="determinant ml-2">[${entry.determinant}]</span>
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

function showAutocomplete(input) {
    // Extract the last word being typed
    const words = input.split(/\s+/);
    const lastWord = words[words.length - 1].toUpperCase();
    
    if (lastWord.length < 2) {
        hideAutocomplete();
        return;
    }
    
    const suggestions = [];
    
    // Search ARTCCs
    for (const [id, name] of Object.entries(ARTCCS)) {
        if (id.includes(lastWord)) {
            suggestions.push({ id, name, type: 'ARTCC' });
        }
    }
    
    // Search TRACONs
    for (const [id, name] of Object.entries(TRACONS)) {
        if (id.includes(lastWord)) {
            suggestions.push({ id, name, type: 'TRACON' });
        }
    }
    
    // Search airports
    for (const apt of MAJOR_AIRPORTS) {
        if (apt.includes(lastWord)) {
            suggestions.push({ id: apt, name: apt + ' Airport', type: 'Airport' });
        }
    }
    
    if (suggestions.length === 0) {
        hideAutocomplete();
        return;
    }
    
    let html = '';
    suggestions.slice(0, 8).forEach(s => {
        html += `
            <div class="autocomplete-item" data-value="${s.id}">
                <span class="facility-id">${s.id}</span>
                <span class="facility-name ml-2">${s.name} (${s.type})</span>
            </div>
        `;
    });
    
    $('#autocompleteDropdown').html(html).addClass('show');
    
    // Click handler
    $('.autocomplete-item').click(function() {
        const value = $(this).data('value');
        const words = $('#quickInput').val().split(/\s+/);
        words[words.length - 1] = value;
        $('#quickInput').val(words.join(' ') + ' ').focus();
        hideAutocomplete();
    });
}

function hideAutocomplete() {
    $('#autocompleteDropdown').removeClass('show');
}

// ============================================
// MESSAGE BUILDING
// ============================================

function buildDiscordMessage(entry) {
    switch (entry.type) {
        case 'MIT':
            return buildMITMessage(entry);
        case 'MINIT':
            return buildMINITMessage(entry);
        case 'DELAY':
            return buildDelayMessage(entry);
        case 'CONFIG':
            return buildConfigMessage(entry);
        default:
            return `[${entry.determinant}] ${entry.type}`;
    }
}

function buildMITMessage(e) {
    let msg = `**[${e.determinant}] ${e.distance}MIT** ${e.fromFacility}→${e.toFacility} ${e.condition}`;
    if (e.qualifiers.length) msg += ' ' + e.qualifiers.join(' ');
    msg += `\nValid: ${e.validFrom}Z-${e.validUntil}Z`;
    msg += `\nReason: ${e.reason}`;
    return msg;
}

function buildMINITMessage(e) {
    let msg = `**[${e.determinant}] ${e.minutes}MINIT** ${e.fromFacility}→${e.toFacility} ${e.condition}`;
    if (e.qualifiers.length) msg += ' ' + e.qualifiers.join(' ');
    msg += `\nValid: ${e.validFrom}Z-${e.validUntil}Z`;
    msg += `\nReason: ${e.reason}`;
    return msg;
}

function buildDelayMessage(e) {
    const trendDisplay = e.trend.charAt(0).toUpperCase() + e.trend.slice(1);
    let msg = `**[${e.determinant}] DELAY** ${e.facility}`;
    msg += `\nLongest: ${e.minutes}min | Trend: ${trendDisplay} | Flights: ${e.flightsDelayed}`;
    msg += `\nReason: ${e.reason}`;
    return msg;
}

function buildConfigMessage(e) {
    let msg = `**[${e.determinant}] AIRPORT CONFIG** ${e.airport}`;
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
        
        // Small delay between submissions to avoid rate limiting
        if (i < entryQueue.length - 1) {
            await new Promise(r => setTimeout(r, 500));
        }
    }
    
    // Clear successful entries
    entryQueue = [];
    renderQueue();
    
    // Show result
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
        // Build POST data based on entry type
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
                qualifiers: entry.qualifiers.join(','),
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
                qualifiers: entry.qualifiers.join(','),
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

// Make removeFromQueue available globally
window.removeFromQueue = removeFromQueue;
