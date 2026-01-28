/**
 * TMI Publisher Controller
 * 
 * Unified NTML Entry + Advisory publishing with multi-Discord support.
 * 
 * NTML Types: MIT, MINIT, STOP, APREQ/CFR, TBM, Delay, Config, Cancel
 * Advisory Types: Operations Plan, Free Form, Hotline, SWAP
 * 
 * @package PERTI
 * @subpackage Assets/JS
 * @version 1.9.0
 * @date 2026-01-28
 * 
 * v1.8.4 Changes:
 *   - Qualifier button CSS improvements: white-space: nowrap, flex-shrink
 *   - Qualifier group layout: flexbox with wrap
 *   - Responsive sizing for mobile (smaller font/padding)
 *   - CSS version bump to 1.5
 *
 * v1.8.3 Changes:
 *   - NTML date/time fields: Changed from time-only to datetime-local
 *   - getSmartDefaultTimes() returns full datetime format (YYYY-MM-DDTHH:MM)
 *   - formatValidTime() handles both datetime-local and time formats
 *   - Added formatValidDateTime() for display with dates
 *   - MIT, MINIT, STOP, APREQ, TBM forms all updated
 *
 * v1.8.2 Changes:
 *   - Airport FAA/ICAO code lookup: Auto-lookup and display both codes
 *   - API integration with api/util/icao_lookup.php
 *   - Results cached to reduce API calls
 *   - Status display under airport input fields
 *
 * v1.8.1 Changes:
 *   - Airport CONFIG presets: Database integration via api/mgt/tmi/airport_configs.php
 *   - Auto-load presets when airport code entered
 *   - Auto-populate runways and rates from preset
 *   - Weather category change updates rates from preset
 *
 * v1.8.0 Changes:
 *   - Hotline form completely redesigned:
 *     - Hotline names match PERTI Plan options (NY Metro, DC Metro, Chicago, etc.)
 *     - Participation options expanded (MANDATORY, EXPECTED, STRONGLY ENCOURAGED, etc.)
 *     - Hotline address selector with auto-mapping (ts.vatusa.net for US, ts.vatcan.ca for Canada)
 *     - Facility selectors with dropdown + type-to-parse pattern
 *     - Start/End datetime fields (with dates, not just times)
 *     - Removed: Associated Restrictions, Prob. of Extension, custom hotline name
 *   - User Profile modal added:
 *     - Set operating initials and home facility
 *     - Shows on first visit
 *     - Saves to localStorage
 *     - Requesting facility defaults to user's home facility
 *   - Queue item buttons now show text fallback (View/Del) when icons fail to load
 *   - Fixed container ID mismatches for form loading
 *   - Added NON-RVSM to equipment qualifiers
 *   - Renamed "Reason category" → "Impacting Condition", "Cause" → "Specific Impact"
 *   - TBM Freeze Horizon formatted as TIME+{value}MIN
 *   - Added altitude and speed filter inputs to all NTML forms
 *
 * v1.6.0 Changes:
 *   - Added Hotline Activation boilerplate with full field support
 *   - Added 68-character line wrapping utility for FAA-standard formatting
 *   - Enhanced Hotline form with location, PIN, participation fields
 *   - Updated NTML format to match Zapier/TypeForm output exactly:
 *     - MIT/MINIT: {time}    {element} via {fix} {value}{type} {spacing} EXCL:{excl} {category}:{cause} {valid} {req}:{prov}
 *     - CONFIG:   {time}    {airport}    {weather}    ARR:{arr} DEP:{dep}    AAR(type):{aar} ADR:{adr}
 *   - Added EXCL: (exclusions) field to MIT/MINIT/STOP forms
 *   - Added Category/Cause reason selector (per OPSNET/ASPM):
 *     - Categories: Volume, Weather, Runway, Equipment, Other
 *     - Causes: Compacted Demand, Thunderstorms, Low Ceilings, Fog, etc.
 *   - Updated spacing qualifiers: AS ONE, PER STREAM, PER AIRPORT, PER FIX, EACH
 *   - Removed determinant codes (to be implemented later with lookup table)
 *   - Fixed uppercase enforcement for all facility/runway/fix inputs
 */

(function() {
    'use strict';
    
    // ===========================================
    // Configuration
    // ===========================================
    
    const CONFIG = window.TMI_PUBLISHER_CONFIG || {
        userCid: null,
        userName: 'Unknown',
        userOI: null,  // Operating initials (2-3 chars)
        userPrivileged: false,
        userHomeOrg: 'vatcscc',
        discordOrgs: { vatcscc: { name: 'vATCSCC', region: 'US', default: true } },
        stagingRequired: true,
        crossBorderAutoDetect: true
    };
    
    const DISCORD_MAX_LENGTH = 2000;
    
    // ARTCC mappings for facility detection
    // All US ARTCCs, TRACONs, Canadian FIRs, Caribbean, Mexico
    const ARTCCS = {
        // US ARTCCs (22)
        'ZAB': 'Albuquerque Center', 'ZAN': 'Anchorage Center', 'ZAU': 'Chicago Center',
        'ZBW': 'Boston Center', 'ZDC': 'Washington Center', 'ZDV': 'Denver Center',
        'ZFW': 'Fort Worth Center', 'ZHN': 'Honolulu Center', 'ZHU': 'Houston Center',
        'ZID': 'Indianapolis Center', 'ZJX': 'Jacksonville Center', 'ZKC': 'Kansas City Center',
        'ZLA': 'Los Angeles Center', 'ZLC': 'Salt Lake Center', 'ZMA': 'Miami Center',
        'ZME': 'Memphis Center', 'ZMP': 'Minneapolis Center', 'ZNY': 'New York Center',
        'ZOA': 'Oakland Center', 'ZOB': 'Cleveland Center', 'ZSE': 'Seattle Center',
        'ZTL': 'Atlanta Center',
        // US TRACONs (Major)
        'A80': 'Atlanta TRACON', 'A90': 'Boston TRACON', 'C90': 'Chicago TRACON',
        'D01': 'Denver TRACON', 'D10': 'Dallas/Fort Worth TRACON', 'D21': 'Detroit TRACON',
        'F11': 'Central Florida TRACON', 'I90': 'Houston TRACON', 'L30': 'Las Vegas TRACON',
        'M03': 'Memphis TRACON', 'M98': 'Minneapolis TRACON', 'N90': 'New York TRACON',
        'NCT': 'NorCal TRACON', 'P31': 'Pensacola TRACON', 'P50': 'Phoenix TRACON',
        'P80': 'Portland TRACON', 'PCT': 'Potomac TRACON', 'R90': 'Omaha TRACON',
        'S46': 'Seattle TRACON', 'S56': 'Salt Lake TRACON', 'SCT': 'SoCal TRACON',
        'T75': 'St. Louis TRACON', 'U90': 'Tucson TRACON', 'Y90': 'Yankee TRACON',
        // Pacific TRACONs
        'HCF': 'Honolulu CF', 'GUM': 'Guam CERAP',
        // Canadian FIRs
        'CZEG': 'Edmonton FIR', 'CZQM': 'Moncton FIR', 'CZQX': 'Gander FIR',
        'CZVR': 'Vancouver FIR', 'CZWG': 'Winnipeg FIR', 'CZYZ': 'Toronto FIR',
        'CYUL': 'Montreal FIR',
        // Caribbean
        'TJSJ': 'San Juan CERAP', 'MUFH': 'Havana FIR', 'MKJK': 'Kingston FIR',
        'TNCF': 'Curacao FIR', 'TTPP': 'Piarco FIR',
        // Mexico
        'MMEX': 'Mexico City ACC', 'MMTY': 'Monterrey ACC', 'MMZT': 'Mazatlan ACC',
        'MMUN': 'Cancun ACC', 'MMMD': 'Merida ACC'
    };
    
    // Cross-border facilities
    const CROSS_BORDER_FACILITIES = ['ZBW', 'ZMP', 'ZSE', 'ZLC', 'ZOB', 'CZYZ', 'CZWG', 'CZVR', 'CZEG'];
    
    // Extended NTML Qualifiers - matching Zapier/TypeForm output
    const NTML_QUALIFIERS = {
        // Spacing Method (appears after MIT value)
        spacing: [
            { code: 'AS ONE', label: 'AS ONE', desc: 'Combined traffic as one stream' },
            { code: 'PER STREAM', label: 'PER STREAM', desc: 'Spacing per traffic stream' },
            { code: 'PER AIRPORT', label: 'PER AIRPORT', desc: 'Spacing per departure airport' },
            { code: 'PER FIX', label: 'PER FIX', desc: 'Spacing per arrival fix' },
            { code: 'EACH', label: 'EACH', desc: 'Each aircraft separately' }
        ],
        // Aircraft Type
        aircraft: [
            { code: 'JET', label: 'JET', desc: 'Jet aircraft only' },
            { code: 'PROP', label: 'PROP', desc: 'Propeller aircraft only' },
            { code: 'TURBOJET', label: 'TURBOJET', desc: 'Turbojet aircraft only' },
            { code: 'B757', label: 'B757', desc: 'B757 aircraft only' }
        ],
        // Weight Class
        weight: [
            { code: 'HEAVY', label: 'HEAVY', desc: 'Heavy aircraft (>255,000 lbs)' },
            { code: 'LARGE', label: 'LARGE', desc: 'Large aircraft (41,000-255,000 lbs)' },
            { code: 'SMALL', label: 'SMALL', desc: 'Small aircraft (<41,000 lbs)' },
            { code: 'SUPER', label: 'SUPER', desc: 'Superheavy aircraft (A380, AN-225)' }
        ],
        // Equipment/Capability
        equipment: [
            { code: 'RNAV', label: 'RNAV', desc: 'RNAV-equipped aircraft only' },
            { code: 'NON-RNAV', label: 'NON-RNAV', desc: 'Non-RNAV aircraft only' },
            { code: 'RNP', label: 'RNP', desc: 'RNP-capable aircraft only' },
            { code: 'RVSM', label: 'RVSM', desc: 'RVSM-compliant only' },
            { code: 'NON-RVSM', label: 'NON-RVSM', desc: 'Non-RVSM aircraft only' }
        ],
        // Flow Type
        flow: [
            { code: 'ARR', label: 'ARR', desc: 'Arrival traffic only' },
            { code: 'DEP', label: 'DEP', desc: 'Departure traffic only' },
            { code: 'OVFLT', label: 'OVFLT', desc: 'Overflight traffic only' }
        ],
        // Operator Category
        operator: [
            { code: 'AIR CARRIER', label: 'AIR CARRIER', desc: 'Air carrier operations' },
            { code: 'AIR TAXI', label: 'AIR TAXI', desc: 'Air taxi operations' },
            { code: 'GA', label: 'GA', desc: 'General aviation' },
            { code: 'CARGO', label: 'CARGO', desc: 'Cargo operations' },
            { code: 'MIL', label: 'MIL', desc: 'Military operations' }
        ],
        // Altitude
        altitude: [
            { code: 'HIGH ALT', label: 'HIGH ALT', desc: 'FL240 and above' },
            { code: 'LOW ALT', label: 'LOW ALT', desc: 'Below FL240' }
        ]
    };
    
    // Reason codes - Category (broad) per OPSNET
    const REASON_CATEGORIES = [
        { code: 'VOLUME', label: 'Volume' },
        { code: 'WEATHER', label: 'Weather' },
        { code: 'RUNWAY', label: 'Runway' },
        { code: 'EQUIPMENT', label: 'Equipment' },
        { code: 'OTHER', label: 'Other' }
    ];
    
    // Cause codes - Specific causes per OPSNET/ASPM
    const REASON_CAUSES = {
        VOLUME: [
            { code: 'VOLUME', label: 'Volume' },
            { code: 'COMPACTED DEMAND', label: 'Compacted Demand' },
            { code: 'MULTI-TAXI', label: 'Multi-Taxi' },
            { code: 'AIRSPACE', label: 'Airspace' }
        ],
        WEATHER: [
            { code: 'WEATHER', label: 'Weather' },
            { code: 'THUNDERSTORMS', label: 'Thunderstorms' },
            { code: 'LOW CEILINGS', label: 'Low Ceilings' },
            { code: 'LOW VISIBILITY', label: 'Low Visibility' },
            { code: 'FOG', label: 'Fog' },
            { code: 'WIND', label: 'Wind' },
            { code: 'SNOW/ICE', label: 'Snow/Ice' }
        ],
        RUNWAY: [
            { code: 'RUNWAY', label: 'Runway' },
            { code: 'RUNWAY CONFIGURATION', label: 'Runway Configuration' },
            { code: 'RUNWAY CONSTRUCTION', label: 'Runway Construction' },
            { code: 'RUNWAY CLOSURE', label: 'Runway Closure' }
        ],
        EQUIPMENT: [
            { code: 'EQUIPMENT', label: 'Equipment' },
            { code: 'FAA EQUIPMENT', label: 'FAA Equipment' },
            { code: 'NON-FAA EQUIPMENT', label: 'Non-FAA Equipment' }
        ],
        OTHER: [
            { code: 'OTHER', label: 'Other' },
            { code: 'STAFFING', label: 'Staffing' },
            { code: 'AIR SHOW', label: 'Air Show' },
            { code: 'VIP MOVEMENT', label: 'VIP Movement' },
            { code: 'SPECIAL EVENT', label: 'Special Event' },
            { code: 'SECURITY', label: 'Security' }
        ]
    };
    
    // ===========================================
    // State
    // ===========================================
    
    let state = {
        queue: [],
        productionMode: false,
        selectedNtmlType: null,
        selectedAdvisoryType: null,
        lastCrossBorderOrgs: [],
        advisoryCounters: {} // Track advisory numbers by date
    };
    
    // ===========================================
    // Initialization
    // ===========================================
    
    $(document).ready(function() {
        init();
    });
    
    function init() {
        initClock();
        initEventHandlers();
        initNtmlTypeSelector();
        initAdvisoryTypeSelector();
        loadSavedState();
        initAdvisoryCounters();
        initUserProfile();
        updateUI();
        
        // Load default forms
        state.selectedNtmlType = 'MIT';
        loadNtmlForm('MIT');
        state.selectedAdvisoryType = 'OPS_PLAN';
        loadAdvisoryForm('OPS_PLAN');
        
        loadActiveTmis();
        loadStagedEntries();
        
        // Refresh active TMIs every 60 seconds
        setInterval(loadActiveTmis, 60000);
        // Refresh staged entries every 30 seconds
        setInterval(loadStagedEntries, 30000);
    }
    
    function initClock() {
        function updateClock() {
            const now = new Date();
            const utc = now.toISOString().substr(11, 8);
            $('#utc_clock').text(utc);
        }
        updateClock();
        setInterval(updateClock, 1000);
    }
    
    function initEventHandlers() {
        // Production mode toggle
        $('#productionMode').on('change', function() {
            if ($(this).is(':checked')) {
                Swal.fire({
                    title: 'Enable Production Mode?',
                    html: '<p class="text-danger"><strong>Warning:</strong> Entries will post directly to LIVE Discord channels!</p>',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#dc3545',
                    confirmButtonText: 'Enable Production'
                }).then((result) => {
                    if (result.isConfirmed) {
                        state.productionMode = true;
                        updateModeIndicator();
                        saveState();
                    } else {
                        $(this).prop('checked', false);
                    }
                });
            } else {
                state.productionMode = false;
                updateModeIndicator();
                saveState();
            }
        });
        
        // Queue controls
        $('#clearQueue').on('click', clearQueue);
        $('#submitAllBtn').on('click', submitAll);
        
        // Advisory controls
        $('#adv_copy').on('click', copyAdvisoryToClipboard);
        $('#adv_add_to_queue').on('click', addAdvisoryToQueue);
        
        // Tab change
        $('a[data-toggle="tab"]').on('shown.bs.tab', function(e) {
            if (e.target.id === 'queue-tab') {
                updateQueueDisplay();
            } else if (e.target.id === 'active-tab') {
                loadActiveTmis();
            }
        });
        
        // Refresh active TMIs button
        $('#refreshActiveTmis').on('click', loadActiveTmis);
    }
    
    function initNtmlTypeSelector() {
        $('.ntml-type-card').on('click', function() {
            const type = $(this).data('type');
            $('.ntml-type-card').removeClass('selected');
            $(this).addClass('selected');
            state.selectedNtmlType = type;
            loadNtmlForm(type);
        });
    }
    
    function initAdvisoryTypeSelector() {
        $('.advisory-type-card').on('click', function() {
            const type = $(this).data('type');
            $('.advisory-type-card').removeClass('selected');
            $(this).addClass('selected');
            state.selectedAdvisoryType = type;
            loadAdvisoryForm(type);
        });
    }
    
    function initAdvisoryCounters() {
        // Load advisory counters from localStorage
        // Using a UNIFIED counter for all advisory types (per user request)
        const today = getUtcDateString();
        const saved = localStorage.getItem('tmi_advisory_counters');

        if (saved) {
            try {
                const data = JSON.parse(saved);
                // Reset if different day (midnight UTC reset)
                if (data.date !== today) {
                    state.advisoryCounters = { date: today, counter: 1 };
                    saveAdvisoryCounters();
                } else {
                    // Migrate from old per-type format to unified counter if needed
                    if (typeof data.counter === 'undefined') {
                        // Old format - find highest counter across all types
                        const maxOld = Math.max(
                            data.opsplan || 1,
                            data.freeform || 1,
                            data.hotline || 1,
                            data.swap || 1
                        );
                        state.advisoryCounters = { date: today, counter: maxOld };
                        saveAdvisoryCounters();
                    } else {
                        state.advisoryCounters = data;
                    }
                }
            } catch (e) {
                state.advisoryCounters = { date: today, counter: 1 };
            }
        } else {
            state.advisoryCounters = { date: today, counter: 1 };
        }

        // Set up midnight UTC reset timer
        scheduleAdvisoryCounterReset();
    }

    function scheduleAdvisoryCounterReset() {
        const now = new Date();
        const midnight = new Date(Date.UTC(
            now.getUTCFullYear(),
            now.getUTCMonth(),
            now.getUTCDate() + 1,
            0, 0, 0
        ));
        const msUntilMidnight = midnight - now;

        setTimeout(function() {
            // Reset unified counter at midnight UTC
            const newDate = getUtcDateString();
            state.advisoryCounters = { date: newDate, counter: 1 };
            saveAdvisoryCounters();
            console.log('Advisory counter reset at midnight UTC');

            // Schedule next reset
            scheduleAdvisoryCounterReset();
        }, msUntilMidnight);
    }

    function saveAdvisoryCounters() {
        localStorage.setItem('tmi_advisory_counters', JSON.stringify(state.advisoryCounters));
    }

    function getNextAdvisoryNumber(type) {
        // Unified counter for all advisory types
        // Check if we need to reset (new day)
        const today = getUtcDateString();
        if (state.advisoryCounters.date !== today) {
            state.advisoryCounters = { date: today, counter: 1 };
        }

        const num = state.advisoryCounters.counter || 1;
        state.advisoryCounters.counter = num + 1;
        saveAdvisoryCounters();
        return String(num).padStart(3, '0');
    }
    
    // ===========================================
    // NTML Form Loading
    // ===========================================
    
    function loadNtmlForm(type) {
        let formHtml = '';
        
        switch(type) {
            case 'MIT':
            case 'MINIT':
                formHtml = buildMitMinitForm(type);
                break;
            case 'STOP':
                formHtml = buildStopForm();
                break;
            case 'APREQ':
                formHtml = buildApreqForm();
                break;
            case 'TBM':
                formHtml = buildTbmForm();
                break;
            case 'DELAY':
                formHtml = buildDelayForm();
                break;
            case 'CONFIG':
                formHtml = buildConfigForm();
                break;
            case 'CANCEL':
                formHtml = buildCancelForm();
                break;
            default:
                formHtml = '<div class="alert alert-warning">Unknown entry type</div>';
        }
        
        $('#ntmlFormContainer').html(formHtml);
        initNtmlFormHandlers(type);
        
        // Default requesting facility to user's saved facility
        const userFacility = getUserFacility();
        if (userFacility && $('#ntml_req_facility').length && !$('#ntml_req_facility').val()) {
            $('#ntml_req_facility').val(userFacility);
        }
    }
    
    function buildQualifiersHtml(showAll = true) {
        let html = '<div class="mb-3">';
        html += '<label class="form-label small text-muted">Qualifiers (optional)</label>';
        html += '<div class="qualifier-sections">';
        
        if (showAll) {
            // Aircraft Type
            html += '<div class="qualifier-group mb-2">';
            html += '<span class="small text-muted mr-2">Type:</span>';
            NTML_QUALIFIERS.aircraft.forEach(q => {
                html += `<button type="button" class="btn btn-outline-secondary btn-sm qualifier-btn mr-1 mb-1" data-qualifier="${q.code}" data-group="aircraft" title="${q.desc}">${q.label}</button>`;
            });
            html += '</div>';
            
            // Weight Class
            html += '<div class="qualifier-group mb-2">';
            html += '<span class="small text-muted mr-2">Weight:</span>';
            NTML_QUALIFIERS.weight.forEach(q => {
                html += `<button type="button" class="btn btn-outline-secondary btn-sm qualifier-btn mr-1 mb-1" data-qualifier="${q.code}" data-group="weight" title="${q.desc}">${q.label}</button>`;
            });
            html += '</div>';
            
            // Spacing Method
            html += '<div class="qualifier-group mb-2">';
            html += '<span class="small text-muted mr-2">Spacing:</span>';
            NTML_QUALIFIERS.spacing.forEach(q => {
                html += `<button type="button" class="btn btn-outline-secondary btn-sm qualifier-btn mr-1 mb-1" data-qualifier="${q.code}" data-group="spacing" title="${q.desc}">${q.label}</button>`;
            });
            html += '</div>';
            
            // Equipment/Capability
            html += '<div class="qualifier-group mb-2">';
            html += '<span class="small text-muted mr-2">Equipment:</span>';
            NTML_QUALIFIERS.equipment.forEach(q => {
                html += `<button type="button" class="btn btn-outline-secondary btn-sm qualifier-btn mr-1 mb-1" data-qualifier="${q.code}" data-group="equipment" title="${q.desc}">${q.label}</button>`;
            });
            html += '</div>';
            
            // Flow Type
            html += '<div class="qualifier-group mb-2">';
            html += '<span class="small text-muted mr-2">Flow:</span>';
            NTML_QUALIFIERS.flow.forEach(q => {
                html += `<button type="button" class="btn btn-outline-secondary btn-sm qualifier-btn mr-1 mb-1" data-qualifier="${q.code}" data-group="flow" title="${q.desc}">${q.label}</button>`;
            });
            html += '</div>';
            
            // Operator Category
            html += '<div class="qualifier-group mb-2">';
            html += '<span class="small text-muted mr-2">Operator:</span>';
            NTML_QUALIFIERS.operator.forEach(q => {
                html += `<button type="button" class="btn btn-outline-secondary btn-sm qualifier-btn mr-1 mb-1" data-qualifier="${q.code}" data-group="operator" title="${q.desc}">${q.label}</button>`;
            });
            html += '</div>';
            
            // Altitude Filter (text input)
            html += '<div class="qualifier-group mb-2 d-flex align-items-center">';
            html += '<span class="small text-muted mr-2">Altitude:</span>';
            html += '<input type="text" class="form-control form-control-sm text-uppercase" id="ntml_altitude_filter" placeholder="e.g., AOB120, AOA320, 140B180" style="max-width: 200px;" maxlength="20">';
            html += '<small class="text-muted ml-2">AOB=At/Below, AOA=At/Above, ###B###=Block</small>';
            html += '</div>';
            
            // Speed Filter (text input)
            html += '<div class="qualifier-group mb-2 d-flex align-items-center">';
            html += '<span class="small text-muted mr-2">Speed:</span>';
            html += '<input type="number" class="form-control form-control-sm" id="ntml_speed_filter" placeholder="e.g., 250" style="max-width: 100px;" min="0" max="999">';
            html += '<span class="small text-muted ml-1">KTS</span>';
            html += '</div>';
        }
        
        html += '</div></div>';
        return html;
    }
    
    function buildReasonSelect() {
        let html = `
            <div class="row">
                <div class="col-6">
                    <label class="form-label small text-muted">Impacting Condition</label>
                    <select class="form-control" id="ntml_reason_category" onchange="TMIPublisher.updateCauseOptions()" style="min-width: 100%;">
        `;
        REASON_CATEGORIES.forEach(r => {
            html += `<option value="${r.code}">${r.label}</option>`;
        });
        html += `
                    </select>
                </div>
                <div class="col-6">
                    <label class="form-label small text-muted">Specific Impact</label>
                    <select class="form-control" id="ntml_reason_cause" style="min-width: 100%;">
        `;
        // Default to VOLUME causes
        REASON_CAUSES.VOLUME.forEach(c => {
            html += `<option value="${c.code}">${c.label}</option>`;
        });
        html += `
                    </select>
                </div>
            </div>
        `;
        return html;
    }
    
    function updateCauseOptions() {
        const category = $('#ntml_reason_category').val() || 'VOLUME';
        const causes = REASON_CAUSES[category] || REASON_CAUSES.VOLUME;
        
        let html = '';
        causes.forEach(c => {
            html += `<option value="${c.code}">${c.label}</option>`;
        });
        $('#ntml_reason_cause').html(html);
    }
    
    function buildMitMinitForm(type) {
        const unit = type === 'MIT' ? 'Miles' : 'Minutes';
        const times = getSmartDefaultTimes();
        
        return `
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <span class="tmi-section-title"><i class="fas fa-ruler-horizontal mr-1"></i> ${type === 'MIT' ? 'Miles-In-Trail' : 'Minutes-In-Trail'} Details</span>
                </div>
                <div class="card-body">
                    <!-- Row 1: Value, Airport/Fix, Traffic Flow, Via -->
                    <div class="row mb-3">
                        <div class="col-md-2">
                            <label class="form-label small text-muted">${unit}</label>
                            <input type="number" class="form-control" id="ntml_value" min="5" max="100" step="5" value="20">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-muted">Airport/Fix</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_ctl_element" placeholder="JFK" maxlength="10">
                            <small id="airport_lookup_status"></small>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-muted">Traffic Flow</label>
                            <select class="form-control" id="ntml_traffic_flow">
                                <option value="">All Traffic</option>
                                <option value="ARRIVALS">Arrivals</option>
                                <option value="DEPARTURES">Departures</option>
                                <option value="OVERFLIGHTS">Overflights</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Via Route/Fix</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_via_fix" placeholder="CLIPR/SKILS or ALL" maxlength="30">
                        </div>
                    </div>
                    
                    <!-- Row 2: Reason -->
                    <div class="row mb-3">
                        <div class="col-12">
                            <label class="form-label small text-muted">Reason</label>
                            ${buildReasonSelect()}
                        </div>
                    </div>
                    
                    <!-- Row 3: Facilities + Exclusions -->
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Requesting Facility</label>
                            <input type="text" class="form-control text-uppercase facility-autocomplete" id="ntml_req_facility" placeholder="ZNY" maxlength="30" list="facilityList" value="${getUserFacility()}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Providing Facility</label>
                            <input type="text" class="form-control text-uppercase facility-autocomplete" id="ntml_prov_facility" placeholder="ZOB" maxlength="30" list="facilityList">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Exclusions</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_exclusions" placeholder="NONE" value="NONE" maxlength="30">
                            <small class="text-muted">e.g., NONE, AAL, UAL</small>
                        </div>
                    </div>
                    
                    <!-- Row 4: Valid Times -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Valid From (UTC)</label>
                            <input type="datetime-local" class="form-control" id="ntml_valid_from" value="${times.start}">
                            <small class="text-muted">Date and time in Zulu</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Valid Until (UTC)</label>
                            <input type="datetime-local" class="form-control" id="ntml_valid_until" value="${times.end}">
                            <small class="text-muted">Date and time in Zulu</small>
                        </div>
                    </div>
                    
                    <!-- Qualifiers -->
                    ${buildQualifiersHtml(true)}
                    
                    <hr>
                    <div class="d-flex justify-content-between">
                        <button class="btn btn-secondary" type="button" onclick="TMIPublisher.resetNtmlForm()">
                            Reset
                        </button>
                        <button class="btn btn-primary" type="button" onclick="TMIPublisher.addNtmlToQueue('${type}')">
                            Add to Queue
                        </button>
                    </div>
                </div>
            </div>
            ${buildFacilityDatalist()}
        `;
    }
    
    function buildStopForm() {
        const times = getSmartDefaultTimes();
        
        return `
            <div class="card shadow-sm">
                <div class="card-header bg-danger text-white">
                    <span class="tmi-section-title"><i class="fas fa-hand-paper mr-1"></i> Flow Stoppage Details</span>
                </div>
                <div class="card-body">
                    <!-- Row 1: Airport/Fix, Traffic Flow, Via -->
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Airport/Fix</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_ctl_element" placeholder="JFK" maxlength="10">
                            <small id="airport_lookup_status"></small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Traffic Flow</label>
                            <select class="form-control" id="ntml_traffic_flow">
                                <option value="">All Traffic</option>
                                <option value="ARRIVALS">Arrivals</option>
                                <option value="DEPARTURES">Departures</option>
                                <option value="OVERFLIGHTS">Overflights</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Via Route/Fix</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_via_fix" placeholder="LENDY or ALL" maxlength="30">
                        </div>
                    </div>
                    
                    <!-- Row 2: Reason -->
                    <div class="row mb-3">
                        <div class="col-12">
                            <label class="form-label small text-muted">Reason</label>
                            ${buildReasonSelect()}
                        </div>
                    </div>
                    
                    <!-- Row 3: Facilities + Exclusions -->
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Requesting Facility</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_req_facility" placeholder="ZNY" maxlength="30" list="facilityList" value="${getUserFacility()}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Providing Facility</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_prov_facility" placeholder="ZBW" maxlength="30" list="facilityList">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Exclusions</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_exclusions" placeholder="NONE" value="NONE" maxlength="30">
                        </div>
                    </div>
                    
                    <!-- Row 4: Valid Times -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Valid From (UTC)</label>
                            <input type="datetime-local" class="form-control" id="ntml_valid_from" value="${times.start}">
                            <small class="text-muted">Date and time in Zulu</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Valid Until (UTC)</label>
                            <input type="datetime-local" class="form-control" id="ntml_valid_until" value="${times.end}">
                            <small class="text-muted">Date and time in Zulu</small>
                        </div>
                    </div>
                    
                    <!-- Limited qualifiers for STOP -->
                    <div class="mb-3">
                        <label class="form-label small text-muted">Qualifiers (optional)</label>
                        <div class="d-flex flex-wrap">
                            ${NTML_QUALIFIERS.aircraft.map(q => 
                                `<button type="button" class="btn btn-outline-secondary btn-sm qualifier-btn mr-1 mb-1" data-qualifier="${q.code}" title="${q.desc}">${q.label}</button>`
                            ).join('')}
                            ${NTML_QUALIFIERS.weight.map(q => 
                                `<button type="button" class="btn btn-outline-secondary btn-sm qualifier-btn mr-1 mb-1" data-qualifier="${q.code}" title="${q.desc}">${q.label}</button>`
                            ).join('')}
                        </div>
                    </div>
                    
                    <hr>
                    <div class="d-flex justify-content-between">
                        <button class="btn btn-secondary" type="button" onclick="TMIPublisher.resetNtmlForm()">Reset</button>
                        <button class="btn btn-danger" type="button" onclick="TMIPublisher.addNtmlToQueue('STOP')">
                            Add to Queue
                        </button>
                    </div>
                </div>
            </div>
            ${buildFacilityDatalist()}
        `;
    }
    
    function buildApreqForm() {
        const times = getSmartDefaultTimes();
        
        return `
            <div class="card shadow-sm">
                <div class="card-header bg-warning text-dark">
                    <span class="tmi-section-title"><i class="fas fa-phone mr-1"></i> APREQ/CFR Details</span>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-2">
                            <label class="form-label small text-muted">Type</label>
                            <select class="form-control" id="ntml_apreq_type">
                                <option value="APREQ">APREQ</option>
                                <option value="CFR">CFR</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-muted">Airport</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_ctl_element" placeholder="KJFK" maxlength="10">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-muted">Traffic Flow</label>
                            <select class="form-control" id="ntml_traffic_flow">
                                <option value="">All Traffic</option>
                                <option value="ARRIVALS">Arrivals</option>
                                <option value="DEPARTURES">Departures</option>
                                <option value="OVERFLIGHTS">Overflights</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Via Route/Fix</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_via_fix" maxlength="30">
                        </div>
                    </div>

                    <!-- Reason -->
                    <div class="row mb-3">
                        <div class="col-12">
                            <label class="form-label small text-muted">Reason</label>
                            ${buildReasonSelect()}
                        </div>
                    </div>
                    
                    <!-- Facilities -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Requesting Facility</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_req_facility" maxlength="30" list="facilityList" value="${getUserFacility()}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Providing Facility</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_prov_facility" maxlength="30" list="facilityList">
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Valid From (UTC)</label>
                            <input type="datetime-local" class="form-control" id="ntml_valid_from" value="${times.start}">
                            <small class="text-muted">Date and time in Zulu</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Valid Until (UTC)</label>
                            <input type="datetime-local" class="form-control" id="ntml_valid_until" value="${times.end}">
                            <small class="text-muted">Date and time in Zulu</small>
                        </div>
                    </div>
                    
                    <!-- Qualifiers -->
                    ${buildQualifiersHtml(true)}
                    
                    <hr>
                    <div class="d-flex justify-content-between">
                        <button class="btn btn-secondary" type="button" onclick="TMIPublisher.resetNtmlForm()">Reset</button>
                        <button class="btn btn-warning" type="button" onclick="TMIPublisher.addNtmlToQueue('APREQ')">
                            Add to Queue
                        </button>
                    </div>
                </div>
            </div>
            ${buildFacilityDatalist()}
        `;
    }
    
    function buildTbmForm() {
        const times = getSmartDefaultTimes();
        
        return `
            <div class="card shadow-sm">
                <div class="card-header bg-success text-white">
                    <span class="tmi-section-title"><i class="fas fa-tachometer-alt mr-1"></i> TBM/TBFM (Time-Based Flow Management) Details</span>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <label class="form-label small text-muted">Airport</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_ctl_element" placeholder="ATL" maxlength="10">
                            <small id="airport_lookup_status"></small>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-muted">Traffic Flow</label>
                            <select class="form-control" id="ntml_traffic_flow">
                                <option value="">All Traffic</option>
                                <option value="ARRIVALS" selected>Arrivals</option>
                                <option value="DEPARTURES">Departures</option>
                                <option value="OVERFLIGHTS">Overflights</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-muted">Meter Point/Arc</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_meter_point" placeholder="ZTL33 or ERLIN" maxlength="10">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-muted">Freeze Horizon (min)</label>
                            <input type="number" class="form-control" id="ntml_freeze_horizon" min="10" max="120" value="20">
                            <small class="text-muted">Output: TIME+{value}MIN</small>
                        </div>
                    </div>

                    <!-- Reason -->
                    <div class="row mb-3">
                        <div class="col-12">
                            <label class="form-label small text-muted">Reason</label>
                            ${buildReasonSelect()}
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Requesting Facility</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_req_facility" maxlength="30" list="facilityList" value="${getUserFacility()}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Providing Facility</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_prov_facility" maxlength="30" list="facilityList">
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Valid From (UTC)</label>
                            <input type="datetime-local" class="form-control" id="ntml_valid_from" value="${times.start}">
                            <small class="text-muted">Date and time in Zulu</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Valid Until (UTC)</label>
                            <input type="datetime-local" class="form-control" id="ntml_valid_until" value="${times.end}">
                            <small class="text-muted">Date and time in Zulu</small>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-12">
                            <label class="form-label small text-muted">Participating Facilities</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_participating" placeholder="ZTL ZJX ZDC ZID" maxlength="50">
                            <small class="text-muted">Space-separated list of ARTCCs participating in metering</small>
                        </div>
                    </div>
                    
                    <!-- Qualifiers -->
                    ${buildQualifiersHtml(true)}
                    
                    <hr>
                    <div class="d-flex justify-content-between">
                        <button class="btn btn-secondary" type="button" onclick="TMIPublisher.resetNtmlForm()">Reset</button>
                        <button class="btn btn-success" type="button" onclick="TMIPublisher.addNtmlToQueue('TBM')">
                            Add to Queue
                        </button>
                    </div>
                </div>
            </div>
            ${buildFacilityDatalist()}
        `;
    }
    
    function buildDelayForm() {
        const currentTime = getCurrentTimeHHMM();
        
        return `
            <div class="card shadow-sm">
                <div class="card-header" style="background-color: #fd7e14; color: white;">
                    <span class="tmi-section-title"><i class="fas fa-hourglass-half mr-1"></i> Delay Advisory Details</span>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <label class="form-label small text-muted">Delay Type</label>
                            <select class="form-control" id="ntml_delay_type">
                                <option value="E/D">E/D (En Route Delay)</option>
                                <option value="A/D">A/D (Arrival Delay)</option>
                                <option value="D/D">D/D (Departure Delay)</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-muted">Facility</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_ctl_element" placeholder="JFK" maxlength="4">
                            <small id="airport_lookup_status"></small>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-muted">Delay (minutes)</label>
                            <input type="number" class="form-control" id="ntml_delay_minutes" min="0" max="180" value="30">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-muted">Trend</label>
                            <select class="form-control" id="ntml_delay_trend">
                                <option value="STABLE">Stable</option>
                                <option value="INC">Increasing</option>
                                <option value="DEC">Decreasing</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Reason</label>
                            ${buildReasonSelect()}
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Report Time (UTC)</label>
                            <input type="time" class="form-control" id="ntml_report_time" value="${currentTime}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Aircraft Count</label>
                            <input type="number" class="form-control" id="ntml_acft_count" min="1" value="1">
                        </div>
                    </div>
                    
                    <hr>
                    <div class="d-flex justify-content-between">
                        <button class="btn btn-secondary" type="button" onclick="TMIPublisher.resetNtmlForm()">Reset</button>
                        <button class="btn btn-warning" type="button" onclick="TMIPublisher.addNtmlToQueue('DELAY')">
                            Add to Queue
                        </button>
                    </div>
                </div>
            </div>
        `;
    }
    
    function buildConfigForm() {
        return `
            <div class="card shadow-sm">
                <div class="card-header" style="background-color: #6f42c1; color: white;">
                    <span class="tmi-section-title"><i class="fas fa-plane-departure mr-1"></i> Airport Configuration Details</span>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Airport</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_ctl_element" placeholder="JFK" maxlength="4">
                            <small class="text-muted">Enter FAA or ICAO code</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Config Preset</label>
                            <select class="form-control" id="ntml_config_preset">
                                <option value="">-- Enter airport first --</option>
                            </select>
                            <small class="text-muted" id="config_preset_status"></small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Weather Category</label>
                            <select class="form-control" id="ntml_weather">
                                <option value="VMC">VMC</option>
                                <option value="MVFR">MVFR</option>
                                <option value="IMC">IMC</option>
                                <option value="LIMC">LIMC</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Arrival Runways</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_arr_runways" placeholder="22L, 22R">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Departure Runways</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_dep_runways" placeholder="31L, 31R">
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label small text-muted">AAR</label>
                            <input type="number" class="form-control" id="ntml_aar" min="0" max="120" value="60">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted">AAR Type</label>
                            <select class="form-control" id="ntml_aar_type">
                                <option value="Strat">Strategic (config rate)</option>
                                <option value="Dyn">Dynamic (user-defined)</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted">ADR</label>
                            <input type="number" class="form-control" id="ntml_adr" min="0" max="120" value="60">
                        </div>
                    </div>
                    
                    <div class="alert alert-info small mb-3">
                        <i class="fas fa-info-circle"></i> 
                        Enter an airport code to load saved presets. See <a href="airport_config.php" target="_blank">Airport Configurations</a> for full list.
                    </div>
                    
                    <hr>
                    <div class="d-flex justify-content-between">
                        <button class="btn btn-secondary" type="button" onclick="TMIPublisher.resetNtmlForm()">Reset</button>
                        <button class="btn btn-primary" type="button" style="background-color: #6f42c1; border-color: #6f42c1;" onclick="TMIPublisher.addNtmlToQueue('CONFIG')">
                            Add to Queue
                        </button>
                    </div>
                </div>
            </div>
        `;
    }
    
    function buildCancelForm() {
        return `
            <div class="card shadow-sm">
                <div class="card-header bg-secondary text-white">
                    <span class="tmi-section-title"><i class="fas fa-times-circle mr-1"></i> Cancel TMI Details</span>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Cancel Scope</label>
                            <select class="form-control" id="ntml_cancel_type">
                                <option value="SPECIFIC">Specific TMI</option>
                                <option value="ALL">All TMIs (Airport)</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Airport/Element</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_ctl_element" maxlength="10">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Via Fix (if specific)</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_via_fix" maxlength="10">
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Requesting Facility</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_req_facility" maxlength="30" list="facilityList">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Providing Facility</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_prov_facility" maxlength="30" list="facilityList">
                        </div>
                    </div>
                    
                    <hr>
                    <div class="d-flex justify-content-between">
                        <button class="btn btn-secondary" type="button" onclick="TMIPublisher.resetNtmlForm()">Reset</button>
                        <button class="btn btn-dark" type="button" onclick="TMIPublisher.addNtmlToQueue('CANCEL')">
                            Add Cancel to Queue
                        </button>
                    </div>
                </div>
            </div>
            ${buildFacilityDatalist()}
        `;
    }
    
    function buildFacilityDatalist() {
        let options = '';
        for (const [code, name] of Object.entries(ARTCCS)) {
            options += `<option value="${code}">${name}</option>`;
        }
        return `<datalist id="facilityList">${options}</datalist>`;
    }
    
    function initNtmlFormHandlers(type) {
        // Auto-uppercase inputs
        $('.text-uppercase').on('input', function() {
            this.value = this.value.toUpperCase();
        });
        
        // Qualifier toggle buttons - only one per group can be selected
        $('.qualifier-btn').on('click', function() {
            const group = $(this).data('group');
            if (group) {
                // Deselect others in same group
                $(`.qualifier-btn[data-group="${group}"]`).removeClass('btn-primary active').addClass('btn-outline-secondary');
            }
            $(this).toggleClass('btn-outline-secondary btn-primary active');
        });
        
        // Facility auto-suggest cross-border detection
        $('.facility-autocomplete').on('change', function() {
            detectCrossBorderFromFacilities();
        });
        
        // Airport code lookup for applicable forms
        if (['MIT', 'MINIT', 'STOP', 'TBM', 'DELAY', 'CANCEL'].includes(type)) {
            const $airportInput = $('#ntml_ctl_element');
            const $statusEl = $('#airport_lookup_status');
            if ($airportInput.length && $statusEl.length) {
                initAirportLookupHandler($airportInput, $statusEl);
            }
        }
        
        // CONFIG form handlers
        if (type === 'CONFIG') {
            initConfigFormHandlers();
        }
    }
    
    // ===========================================
    // CONFIG Form Handlers (Airport Presets)
    // ===========================================
    
    let configPresets = []; // Store loaded config presets
    
    function initConfigFormHandlers() {
        // Airport code change - fetch presets
        $('#ntml_ctl_element').on('blur', function() {
            const airport = $(this).val().trim().toUpperCase();
            if (airport && airport.length >= 3) {
                loadAirportConfigs(airport);
            }
        });
        
        // Also trigger on Enter key
        $('#ntml_ctl_element').on('keypress', function(e) {
            if (e.which === 13) {
                e.preventDefault();
                $(this).blur();
            }
        });
        
        // Preset selection - populate fields
        $('#ntml_config_preset').on('change', function() {
            const configId = $(this).val();
            if (configId) {
                applyConfigPreset(configId);
            }
        });
        
        // Weather category change - update rates from preset
        $('#ntml_weather').on('change', function() {
            const configId = $('#ntml_config_preset').val();
            if (configId && configPresets.length > 0) {
                applyConfigPreset(configId);
            }
        });
    }
    
    function loadAirportConfigs(airport) {
        const $preset = $('#ntml_config_preset');
        const $status = $('#config_preset_status');
        
        $status.html('<i class="fas fa-spinner fa-spin"></i> Loading...');
        $preset.html('<option value="">Loading...</option>').prop('disabled', true);
        
        $.ajax({
            url: 'api/mgt/tmi/airport_configs.php',
            method: 'GET',
            data: { airport: airport, active_only: '1' },
            success: function(response) {
                if (response.success && response.configs && response.configs.length > 0) {
                    configPresets = response.configs;
                    
                    let options = '<option value="">-- Select config --</option>';
                    response.configs.forEach(function(cfg) {
                        const displayName = cfg.configCode 
                            ? `${cfg.configName} (${cfg.configCode})`
                            : cfg.configName;
                        const rateInfo = cfg.rates.vmcAar ? ` [AAR: ${cfg.rates.vmcAar}]` : '';
                        options += `<option value="${cfg.configId}">${displayName}${rateInfo}</option>`;
                    });
                    
                    $preset.html(options).prop('disabled', false);
                    $status.html(`<span class="text-success">${response.count} config(s) found</span>`);
                    
                    console.log('[TMI] Loaded', response.count, 'configs for', airport);
                } else {
                    $preset.html('<option value="">-- No configs found --</option>').prop('disabled', true);
                    $status.html('<span class="text-warning">No presets available</span>');
                    configPresets = [];
                }
            },
            error: function(xhr, status, error) {
                console.error('[TMI] Config load error:', error);
                $preset.html('<option value="">-- Error loading --</option>').prop('disabled', true);
                $status.html('<span class="text-danger">Error loading configs</span>');
                configPresets = [];
            }
        });
    }
    
    function applyConfigPreset(configId) {
        const config = configPresets.find(c => c.configId === parseInt(configId));
        if (!config) {
            console.warn('[TMI] Config not found:', configId);
            return;
        }
        
        console.log('[TMI] Applying config preset:', config);
        
        // Populate runways
        $('#ntml_arr_runways').val(config.arrRunways || '');
        $('#ntml_dep_runways').val(config.depRunways || '');
        
        // Populate rates based on current weather category
        const weather = $('#ntml_weather').val();
        let aar = config.rates.vmcAar;
        let adr = config.rates.vmcAdr;
        
        if (weather === 'IMC' || weather === 'LIMC') {
            aar = config.rates.imcAar || config.rates.vmcAar;
            adr = config.rates.imcAdr || config.rates.vmcAdr;
        }
        
        if (aar) $('#ntml_aar').val(aar);
        if (adr) $('#ntml_adr').val(adr);
    }
    
    // ===========================================
    // Airport Code Lookup (FAA ↔ ICAO)
    // ===========================================
    
    let icaoLookupCache = {}; // Cache lookups to reduce API calls
    
    function lookupAirportCode(code, callback) {
        if (!code || code.length < 3) {
            callback(null);
            return;
        }
        
        code = code.toUpperCase().trim();
        
        // Check cache first
        if (icaoLookupCache[code]) {
            callback(icaoLookupCache[code]);
            return;
        }
        
        $.ajax({
            url: 'api/util/icao_lookup.php',
            method: 'GET',
            data: { faa: code },
            success: function(response) {
                if (response.success) {
                    icaoLookupCache[code] = response;
                    // Also cache the reverse lookup
                    if (response.icao && response.icao !== code) {
                        icaoLookupCache[response.icao] = response;
                    }
                    if (response.faa && response.faa !== code) {
                        icaoLookupCache[response.faa] = response;
                    }
                    callback(response);
                } else {
                    callback(null);
                }
            },
            error: function() {
                callback(null);
            }
        });
    }
    
    function initAirportLookupHandler($input, $statusEl) {
        // Debounce to avoid rapid API calls
        let lookupTimeout = null;
        
        $input.on('blur', function() {
            const code = $(this).val().trim().toUpperCase();
            if (code.length >= 3) {
                performAirportLookup(code, $statusEl);
            } else {
                $statusEl.html('');
            }
        });
        
        $input.on('input', function() {
            clearTimeout(lookupTimeout);
            const code = $(this).val().trim().toUpperCase();
            if (code.length >= 3) {
                lookupTimeout = setTimeout(function() {
                    performAirportLookup(code, $statusEl);
                }, 500);
            } else {
                $statusEl.html('');
            }
        });
    }
    
    function performAirportLookup(code, $statusEl) {
        $statusEl.html('<i class="fas fa-spinner fa-spin text-muted"></i>');
        
        lookupAirportCode(code, function(result) {
            if (result) {
                let html = '';
                if (result.faa && result.icao && result.faa !== result.icao) {
                    html = `<span class="text-success">${result.faa} / ${result.icao}</span>`;
                    if (result.name) {
                        html += ` <small class="text-muted">(${result.name})</small>`;
                    }
                } else if (result.name) {
                    html = `<span class="text-success">${result.faa || result.icao}</span> <small class="text-muted">(${result.name})</small>`;
                } else {
                    html = `<span class="text-muted">${code}</span>`;
                }
                $statusEl.html(html);
            } else {
                $statusEl.html('<span class="text-warning">Not found</span>');
            }
        });
    }
    
    // ===========================================
    // Advisory Form Loading
    // ===========================================
    
    function loadAdvisoryForm(type) {
        let formHtml = '';
        
        switch(type) {
            case 'OPS_PLAN':
            case 'OPSPLAN':
                formHtml = buildOpsPlanForm();
                break;
            case 'FREE_FORM':
            case 'FREEFORM':
                formHtml = buildFreeformForm();
                break;
            case 'HOTLINE':
                formHtml = buildHotlineForm();
                break;
            case 'SWAP':
                formHtml = buildSwapForm();
                break;
            default:
                formHtml = '<div class="alert alert-warning">Unknown advisory type</div>';
        }
        
        $('#advisoryFormContainer').html(formHtml);
        initAdvisoryFormHandlers(type);
        updateAdvisoryPreview();
        $('#adv_add_to_queue').prop('disabled', false);
    }
    
    function buildOpsPlanForm() {
        const advNum = getNextAdvisoryNumber('OPSPLAN');
        const currentDateTime = getCurrentDateTimeForInput();
        // Default end time: 4 hours from now
        const endDateTime = new Date(Date.now() + (4 * 60 * 60 * 1000)).toISOString().slice(0, 16);

        return `
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <span class="tmi-section-title"><i class="fas fa-clipboard-check mr-1"></i> Operations Plan</span>
                    <button type="button" class="btn btn-sm btn-light" id="btnImportPertiPlan" title="Import from PERTI Plan">
                        <i class="fas fa-file-import mr-1"></i> Import PERTI Plan
                    </button>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <label class="form-label small text-muted">Advisory #</label>
                            <input type="text" class="form-control" id="adv_number" value="${advNum}" readonly>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-muted">Facility</label>
                            <input type="text" class="form-control text-uppercase" id="adv_facility" value="DCC">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-muted">Valid From (UTC)</label>
                            <input type="datetime-local" class="form-control" id="adv_valid_from" value="${currentDateTime}">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-muted">Valid Until (UTC)</label>
                            <input type="datetime-local" class="form-control" id="adv_valid_until" value="${endDateTime}">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small text-muted">Key Initiatives</label>
                        <textarea class="form-control" id="adv_initiatives" rows="4" placeholder="List key TMIs and initiatives for the operational period..."></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small text-muted">Terminal/Enroute Constraints</label>
                        <textarea class="form-control" id="adv_weather" rows="2" placeholder="Summarize weather impacts and constraints..."></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small text-muted">Special Events</label>
                        <textarea class="form-control" id="adv_events" rows="2" placeholder="List any special events affecting traffic..."></textarea>
                    </div>
                </div>
            </div>
        `;
    }
    
    function buildFreeformForm() {
        const advNum = getNextAdvisoryNumber('FREEFORM');
        const currentDateTime = getCurrentDateTimeForInput();
        // Default end time: 4 hours from now
        const endDateTime = new Date(Date.now() + (4 * 60 * 60 * 1000)).toISOString().slice(0, 16);

        return `
            <div class="card shadow-sm">
                <div class="card-header bg-secondary text-white">
                    <span class="tmi-section-title"><i class="fas fa-file-alt mr-1"></i> Free-Form Advisory</span>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <label class="form-label small text-muted">Advisory #</label>
                            <input type="text" class="form-control" id="adv_number" value="${advNum}" readonly>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-muted">Facility</label>
                            <input type="text" class="form-control text-uppercase" id="adv_facility" value="DCC">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Subject</label>
                            <input type="text" class="form-control text-uppercase" id="adv_subject" placeholder="Advisory Subject">
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Valid From (UTC)</label>
                            <input type="datetime-local" class="form-control" id="adv_valid_from" value="${currentDateTime}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Valid Until (UTC)</label>
                            <input type="datetime-local" class="form-control" id="adv_valid_until" value="${endDateTime}">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small text-muted">Advisory Text</label>
                        <textarea class="form-control" id="adv_text" rows="8" placeholder="Enter advisory text..."></textarea>
                        <small class="text-muted">Max 2000 characters for Discord</small>
                    </div>
                </div>
            </div>
        `;
    }
    
    function buildHotlineForm() {
        const advNum = getNextAdvisoryNumber('HOTLINE');
        const currentDateTime = getCurrentDateTimeForInput();
        
        // Hotline names matching PERTI Plan options
        const hotlineNames = [
            'NY Metro', 'DC Metro', 'Chicago', 'Atlanta', 'Florida', 'Texas',
            'East Coast', 'West Coast', 'Canada East', 'Canada West', 'Mexico', 'Caribbean'
        ];
        
        // Participation options
        const participationOptions = [
            'MANDATORY', 'EXPECTED', 'STRONGLY ENCOURAGED', 'STRONGLY RECOMMENDED',
            'ENCOURAGED', 'RECOMMENDED', 'OPTIONAL'
        ];
        
        // Hotline address options
        const hotlineAddresses = [
            { value: 'ts.vatusa.net', label: 'VATUSA TeamSpeak (ts.vatusa.net)' },
            { value: 'ts.vatcan.ca', label: 'VATCAN TeamSpeak (ts.vatcan.ca)' },
            { value: 'discord', label: 'vATCSCC Discord, Hotline Backup voice channel' }
        ];
        
        // Build hotline name options
        let hotlineNameOptions = hotlineNames.map(name => 
            `<option value="${name}">${name}</option>`
        ).join('');
        
        // Build participation options
        let participationOpts = participationOptions.map(opt => 
            `<option value="${opt}">${opt}</option>`
        ).join('');
        
        // Build address options
        let addressOptions = hotlineAddresses.map(addr => 
            `<option value="${addr.value}">${addr.label}</option>`
        ).join('');
        
        return `
            <div class="card shadow-sm">
                <div class="card-header bg-danger text-white">
                    <span class="tmi-section-title"><i class="fas fa-phone-volume mr-1"></i> Hotline Advisory</span>
                </div>
                <div class="card-body">
                    <!-- Row 1: Advisory #, Action, Hotline Name -->
                    <div class="row mb-3">
                        <div class="col-md-2">
                            <label class="form-label small text-muted">Advisory #</label>
                            <input type="text" class="form-control" id="adv_number" value="${advNum}" readonly>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-muted">Action</label>
                            <select class="form-control" id="adv_hotline_action">
                                <option value="ACTIVATION">Activation</option>
                                <option value="UPDATE">Update</option>
                                <option value="TERMINATION">Termination</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Hotline Name</label>
                            <select class="form-control" id="adv_hotline_name">
                                ${hotlineNameOptions}
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-muted">Participation</label>
                            <select class="form-control" id="adv_participation">
                                ${participationOpts}
                            </select>
                        </div>
                    </div>
                    
                    <!-- Row 2: Event Times (with dates) -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Start Date/Time (UTC)</label>
                            <input type="datetime-local" class="form-control" id="adv_start_datetime" value="${currentDateTime}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted">End Date/Time (UTC)</label>
                            <input type="datetime-local" class="form-control" id="adv_end_datetime">
                        </div>
                    </div>
                    
                    <!-- Row 3: Facilities -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Constrained Facilities</label>
                            <div class="facility-selector-wrapper">
                                <select class="form-control mb-1" id="adv_constrained_select" multiple size="4">
                                    ${buildFacilityOptions()}
                                </select>
                                <input type="text" class="form-control text-uppercase" id="adv_constrained_facilities" 
                                       placeholder="Or type: ZNY, ZBW, ZDC" 
                                       data-linked-select="adv_constrained_select">
                                <small class="text-muted">Select from list or type comma-separated codes</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Facilities to Attend</label>
                            <div class="facility-selector-wrapper">
                                <select class="form-control mb-1" id="adv_attend_select" multiple size="4">
                                    ${buildFacilityOptions()}
                                </select>
                                <input type="text" class="form-control text-uppercase" id="adv_facilities" 
                                       placeholder="Or type: ZNY, ZBW, ZDC, ZOB" 
                                       data-linked-select="adv_attend_select">
                                <small class="text-muted">Select from list or type comma-separated codes</small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Row 4: Impact Details -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Impacting Condition</label>
                            <select class="form-control" id="adv_reason">
                                <option value="WEATHER">Weather</option>
                                <option value="VOLUME">Volume</option>
                                <option value="EQUIPMENT">Equipment</option>
                                <option value="STAFFING">Staffing</option>
                                <option value="RUNWAY CONSTRUCTION">Runway Construction</option>
                                <option value="SPECIAL EVENT">Special Event</option>
                                <option value="OTHER">Other</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Location of Impact</label>
                            <input type="text" class="form-control" id="adv_impacted_area" placeholder="e.g., NY Metro, EWR/JFK/LGA arrivals">
                        </div>
                    </div>
                    
                    <!-- Row 5: Hotline Address (auto-mapped) -->
                    <div class="row mb-3">
                        <div class="col-md-12">
                            <label class="form-label small text-muted">Hotline Address</label>
                            <select class="form-control" id="adv_hotline_address">
                                ${addressOptions}
                            </select>
                            <small class="text-muted">Auto-selected based on hotline region. Canada hotlines use VATCAN TeamSpeak.</small>
                        </div>
                    </div>
                    
                    <!-- Row 6: Notes -->
                    <div class="mb-3">
                        <label class="form-label small text-muted">Additional Remarks</label>
                        <textarea class="form-control" id="adv_notes" rows="2" placeholder="Additional coordination notes..."></textarea>
                    </div>
                    
                    <div class="alert alert-info small mb-0">
                        <i class="fas fa-info-circle"></i> 
                        <strong>Note:</strong> For Activation, the boilerplate text will be auto-generated. 
                        Update/Termination advisories use a simpler format.
                    </div>
                </div>
            </div>
        `;
    }
    
    // Build facility options for dropdowns
    function buildFacilityOptions() {
        const facilities = [
            // US ARTCCs
            { code: 'ZAB', name: 'Albuquerque Center' },
            { code: 'ZAN', name: 'Anchorage Center' },
            { code: 'ZAU', name: 'Chicago Center' },
            { code: 'ZBW', name: 'Boston Center' },
            { code: 'ZDC', name: 'Washington Center' },
            { code: 'ZDV', name: 'Denver Center' },
            { code: 'ZFW', name: 'Fort Worth Center' },
            { code: 'ZHN', name: 'Honolulu Center' },
            { code: 'ZHU', name: 'Houston Center' },
            { code: 'ZID', name: 'Indianapolis Center' },
            { code: 'ZJX', name: 'Jacksonville Center' },
            { code: 'ZKC', name: 'Kansas City Center' },
            { code: 'ZLA', name: 'Los Angeles Center' },
            { code: 'ZLC', name: 'Salt Lake Center' },
            { code: 'ZMA', name: 'Miami Center' },
            { code: 'ZME', name: 'Memphis Center' },
            { code: 'ZMP', name: 'Minneapolis Center' },
            { code: 'ZNY', name: 'New York Center' },
            { code: 'ZOA', name: 'Oakland Center' },
            { code: 'ZOB', name: 'Cleveland Center' },
            { code: 'ZSE', name: 'Seattle Center' },
            { code: 'ZTL', name: 'Atlanta Center' },
            // TRACONs
            { code: 'A80', name: 'Atlanta TRACON' },
            { code: 'A90', name: 'Boston TRACON' },
            { code: 'C90', name: 'Chicago TRACON' },
            { code: 'D01', name: 'Denver TRACON' },
            { code: 'D10', name: 'Dallas/Fort Worth TRACON' },
            { code: 'I90', name: 'Houston TRACON' },
            { code: 'N90', name: 'New York TRACON' },
            { code: 'NCT', name: 'NorCal TRACON' },
            { code: 'PCT', name: 'Potomac TRACON' },
            { code: 'S46', name: 'Seattle TRACON' },
            { code: 'SCT', name: 'SoCal TRACON' },
            // Canadian FIRs
            { code: 'CZEG', name: 'Edmonton FIR' },
            { code: 'CZQM', name: 'Moncton FIR' },
            { code: 'CZQX', name: 'Gander FIR' },
            { code: 'CZVR', name: 'Vancouver FIR' },
            { code: 'CZWG', name: 'Winnipeg FIR' },
            { code: 'CZYZ', name: 'Toronto FIR' },
            // International
            { code: 'MMEX', name: 'Mexico' },
            { code: 'CARIBBEAN', name: 'Caribbean' }
        ];

        return facilities.map(f =>
            `<option value="${f.code}">${f.code} - ${f.name}</option>`
        ).join('');
    }
    
    // Get current datetime for input fields
    function getCurrentDateTimeForInput() {
        const now = new Date();
        // Format: YYYY-MM-DDTHH:MM
        return now.toISOString().slice(0, 16);
    }
    
    function buildSwapForm() {
        const advNum = getNextAdvisoryNumber('SWAP');
        const currentDateTime = getCurrentDateTimeForInput();

        return `
            <div class="card shadow-sm">
                <div class="card-header bg-warning text-dark">
                    <span class="tmi-section-title"><i class="fas fa-cloud-sun-rain mr-1"></i> SWAP (Severe Weather Avoidance Plan) Advisory</span>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <label class="form-label small text-muted">Advisory #</label>
                            <input type="text" class="form-control" id="adv_number" value="${advNum}" readonly>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-muted">SWAP Action</label>
                            <select class="form-control" id="adv_swap_type">
                                <option value="IMPLEMENTATION">Implementation</option>
                                <option value="UPDATE">Update</option>
                                <option value="TERMINATION">Termination</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-muted">Effective Date/Time (UTC)</label>
                            <input type="datetime-local" class="form-control" id="adv_effective_datetime" value="${currentDateTime}">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-muted">Duration (hrs)</label>
                            <input type="number" class="form-control" id="adv_duration" min="1" max="24" value="4">
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Impacted Area</label>
                            <input type="text" class="form-control" id="adv_impacted_area" placeholder="e.g., ZNY/ZBW/ZOB airspace">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Affected Facilities</label>
                            <input type="text" class="form-control text-uppercase" id="adv_areas" placeholder="ZNY, ZBW, ZDC, ZOB">
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Include Traffic</label>
                            <select class="form-control" id="adv_include_traffic">
                                <option value="ALL">All Traffic</option>
                                <option value="EASTBOUND">Eastbound Only</option>
                                <option value="WESTBOUND">Westbound Only</option>
                                <option value="NORTHBOUND">Northbound Only</option>
                                <option value="SOUTHBOUND">Southbound Only</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Probability of Extension</label>
                            <select class="form-control" id="adv_extension_prob">
                                <option value="NONE">None</option>
                                <option value="LOW">Low</option>
                                <option value="MEDIUM">Medium</option>
                                <option value="HIGH">High</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label small text-muted">Weather Synopsis</label>
                        <textarea class="form-control" id="adv_weather" rows="3" placeholder="Describe weather conditions, movement, and forecast..."></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label small text-muted">Active Playbook Routes</label>
                        <textarea class="form-control" id="adv_routes" rows="3" placeholder="List active playbook routes (one per line)...&#10;e.g., ZNY-WEST GATE: J584 HARWL J60&#10;ZBW-SOUTH: Q436 MERIT J174"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label small text-muted">Associated Restrictions</label>
                        <input type="text" class="form-control" id="adv_restrictions" placeholder="e.g., 20MIT via affected fixes, EDCT +2hrs">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label small text-muted">Additional Remarks</label>
                        <textarea class="form-control" id="adv_notes" rows="2" placeholder="Additional coordination information..."></textarea>
                    </div>
                </div>
            </div>
        `;
    }
    
    function initAdvisoryFormHandlers(type) {
        // Auto-update preview on input
        $('#advisoryFormContainer input, #advisoryFormContainer textarea, #advisoryFormContainer select').on('input change', function() {
            updateAdvisoryPreview();
        });
        
        // Auto-uppercase inputs
        $('#advisoryFormContainer .text-uppercase').on('input', function() {
            this.value = this.value.toUpperCase();
        });
        
        // Handle Hotline-specific logic
        if (type === 'HOTLINE') {
            // Auto-map hotline address based on hotline name
            $('#adv_hotline_name').on('change', function() {
                const name = $(this).val() || '';
                // Canada hotlines use VATCAN TeamSpeak
                if (name.includes('Canada')) {
                    $('#adv_hotline_address').val('ts.vatcan.ca');
                } else {
                    $('#adv_hotline_address').val('ts.vatusa.net');
                }
                updateAdvisoryPreview();
            });
            
            // Trigger initial address mapping
            $('#adv_hotline_name').trigger('change');
            
            // Facility selector sync (select → input)
            $('#adv_constrained_select, #adv_attend_select').on('change', function() {
                const selectId = $(this).attr('id');
                const inputId = selectId === 'adv_constrained_select' ? 'adv_constrained_facilities' : 'adv_facilities';
                const selected = $(this).val() || [];
                $('#' + inputId).val(selected.join(', '));
                updateAdvisoryPreview();
            });
            
            // Facility input → select sync (parse typed input)
            $('#adv_constrained_facilities, #adv_facilities').on('blur', function() {
                const inputId = $(this).attr('id');
                const selectId = inputId === 'adv_constrained_facilities' ? 'adv_constrained_select' : 'adv_attend_select';
                const value = $(this).val() || '';
                const codes = value.split(/[,\s]+/).map(c => c.trim().toUpperCase()).filter(c => c);

                // Select matching options
                $('#' + selectId + ' option').each(function() {
                    $(this).prop('selected', codes.includes($(this).val()));
                });
            });
        }

        // Handle Ops Plan PERTI Plan import
        if (type === 'OPS_PLAN' || type === 'OPSPLAN') {
            $('#btnImportPertiPlan').on('click', function() {
                importPertiPlan();
            });
        }
    }

    /**
     * Import PERTI Plan data into Ops Plan advisory
     */
    function importPertiPlan() {
        // First fetch available plans for the dropdown
        $.ajax({
            url: 'api/mgt/plan/get.php',
            method: 'GET',
            data: { list: '1' },
            dataType: 'json',
            success: function(response) {
                let planOptions = '<option value="">-- Select a plan --</option>';
                if (response.success && response.plans) {
                    response.plans.forEach(function(plan) {
                        planOptions += `<option value="${plan.id}">${plan.eventName} (${plan.eventDate})</option>`;
                    });
                }
                showImportDialog(planOptions);
            },
            error: function() {
                // Show dialog without plan list
                showImportDialog('<option value="">-- No plans loaded --</option>');
            }
        });
    }

    function showImportDialog(planOptions) {
        Swal.fire({
            title: '<i class="fas fa-file-import text-primary"></i> Import PERTI Plan',
            html: `
                <div class="text-left">
                    <p class="small text-muted mb-3">Select a PERTI Plan to import TMI data into the Operations Plan advisory.</p>
                    <div class="form-group">
                        <label class="small font-weight-bold">Select Plan</label>
                        <select id="importPlanId" class="form-control">${planOptions}</select>
                    </div>
                </div>
            `,
            width: 450,
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-download"></i> Import',
            confirmButtonColor: '#007bff',
            cancelButtonText: 'Cancel',
            preConfirm: () => {
                const planId = document.getElementById('importPlanId').value;
                if (!planId) {
                    Swal.showValidationMessage('Please select a plan');
                    return false;
                }
                return { type: 'id', value: planId };
            }
        }).then((result) => {
            if (result.isConfirmed) {
                fetchPertiPlanData(result.value.type, result.value.value);
            }
        });
    }

    /**
     * Fetch PERTI Plan data from API
     * @param {string} type - 'date', 'id', or 'event'
     * @param {string} value - The value for the lookup
     */
    function fetchPertiPlanData(type, value) {
        Swal.fire({
            title: 'Loading...',
            text: 'Fetching PERTI Plan data',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading()
        });

        // Build request params based on type
        const params = {};
        if (type === 'id') params.id = value;
        else if (type === 'date') params.date = value;
        else if (type === 'event') params.event = value;

        $.ajax({
            url: 'api/mgt/plan/get.php',
            method: 'GET',
            data: params,
            dataType: 'json',
            success: function(response) {
                Swal.close();

                // Handle multiple results from event search
                if (response.success && response.plans && response.plans.length > 1) {
                    // Show plan selection dialog
                    let options = '';
                    response.plans.forEach(function(plan) {
                        options += `<option value="${plan.id}">${plan.eventName} (${plan.eventDate})</option>`;
                    });
                    Swal.fire({
                        title: 'Multiple Plans Found',
                        html: `<select id="selectPlan" class="form-control">${options}</select>`,
                        confirmButtonText: 'Select',
                        showCancelButton: true,
                        preConfirm: () => document.getElementById('selectPlan').value
                    }).then((result) => {
                        if (result.isConfirmed) {
                            fetchPertiPlanData('id', result.value);
                        }
                    });
                    return;
                }

                if (response.success && response.plan) {
                    populateOpsPlanFromPerti(response.plan);
                    Swal.fire({
                        icon: 'success',
                        title: 'Plan Imported',
                        text: 'PERTI Plan data has been imported into the Ops Plan.',
                        timer: 2000,
                        showConfirmButton: false
                    });
                } else if (response.success && !response.plan) {
                    Swal.fire({
                        icon: 'info',
                        title: 'No Plan Found',
                        text: 'No PERTI Plan was found for the specified criteria.'
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Import Failed',
                        text: response.error || 'Failed to fetch PERTI Plan data.'
                    });
                }
            },
            error: function(xhr, status, error) {
                Swal.close();
                Swal.fire({
                    icon: 'error',
                    title: 'Import Failed',
                    text: 'Failed to connect to server: ' + error
                });
            }
        });
    }

    /**
     * Populate Ops Plan form fields from PERTI Plan data
     */
    function populateOpsPlanFromPerti(plan) {
        // Build initiatives text from planned TMIs
        let initiatives = [];

        if (plan.tmis && plan.tmis.length > 0) {
            plan.tmis.forEach(tmi => {
                const entry = `${tmi.type || 'TMI'}: ${tmi.ctlElement || ''} - ${tmi.description || ''}`;
                initiatives.push(entry.trim());
            });
        }

        if (plan.initiatives && plan.initiatives.length > 0) {
            plan.initiatives.forEach(init => {
                initiatives.push(init);
            });
        }

        if (initiatives.length > 0) {
            $('#adv_initiatives').val(initiatives.join('\n'));
        }

        // Weather/constraints
        if (plan.weather || plan.constraints) {
            const constraints = [plan.weather, plan.constraints].filter(c => c).join('\n');
            $('#adv_weather').val(constraints);
        }

        // Events
        if (plan.events && plan.events.length > 0) {
            $('#adv_events').val(plan.events.join('\n'));
        }

        // Update validity times if plan has them
        if (plan.validFrom) {
            const fromDate = new Date(plan.validFrom);
            $('#adv_valid_from').val(fromDate.toISOString().slice(0, 16));
        }
        if (plan.validUntil) {
            const untilDate = new Date(plan.validUntil);
            $('#adv_valid_until').val(untilDate.toISOString().slice(0, 16));
        }

        // Trigger preview update
        updateAdvisoryPreview();
    }
    
    function updateAdvisoryPreview() {
        const type = state.selectedAdvisoryType;
        if (!type) {
            $('#adv_preview').text('Select an advisory type to begin...');
            return;
        }
        
        let preview = '';
        
        switch(type) {
            case 'OPS_PLAN':
            case 'OPSPLAN':
                preview = buildOpsPlanPreview();
                break;
            case 'FREE_FORM':
            case 'FREEFORM':
                preview = buildFreeformPreview();
                break;
            case 'HOTLINE':
                preview = buildHotlinePreview();
                break;
            case 'SWAP':
                preview = buildSwapPreview();
                break;
        }
        
        $('#adv_preview').text(preview);
        $('#preview_char_count').text(`${preview.length} / ${DISCORD_MAX_LENGTH}`);
        
        if (preview.length > DISCORD_MAX_LENGTH) {
            $('#preview_char_count').addClass('text-danger');
        } else {
            $('#preview_char_count').removeClass('text-danger');
        }
    }
    
    function buildOpsPlanPreview() {
        const num = $('#adv_number').val() || '001';
        const facility = $('#adv_facility').val() || 'DCC';
        const initiatives = $('#adv_initiatives').val() || '';
        const weather = $('#adv_weather').val() || '';
        const events = $('#adv_events').val() || '';

        // Parse datetime-local values
        const validFrom = $('#adv_valid_from').val() || '';
        const validUntil = $('#adv_valid_until').val() || '';

        // Format datetime as DD/HHMM
        const formatDateTime = (dt) => {
            if (!dt) return 'TBD';
            const d = new Date(dt + 'Z'); // Treat as UTC
            const day = String(d.getUTCDate()).padStart(2, '0');
            const hour = String(d.getUTCHours()).padStart(2, '0');
            const min = String(d.getUTCMinutes()).padStart(2, '0');
            return `${day}${hour}${min}`;
        };

        const startFormatted = formatDateTime(validFrom);
        const endFormatted = formatDateTime(validUntil);

        let lines = [
            buildAdvisoryHeader(num, facility, 'OPERATIONS PLAN'),
            `VALID FOR ${startFormatted}Z THRU ${endFormatted}Z`,
            ``
        ];
        
        if (weather) {
            lines.push(`TERMINAL/ENROUTE CONSTRAINTS:`);
            lines.push(wrapText(weather));
            lines.push(``);
        }
        
        if (initiatives) {
            lines.push(`KEY INITIATIVES:`);
            lines.push(wrapText(initiatives));
            lines.push(``);
        }
        
        if (events) {
            lines.push(`SPECIAL EVENTS:`);
            lines.push(wrapText(events));
            lines.push(``);
        }
        
        lines.push(`***SUBMIT OPERATIONS PLAN ITEMS VIA PERTI***`);
        lines.push(``);
        lines.push(buildAdvisoryFooter(num, facility));
        
        return lines.join('\n');
    }
    
    function buildFreeformPreview() {
        const num = $('#adv_number').val() || '001';
        const facility = $('#adv_facility').val() || 'DCC';
        const subject = $('#adv_subject').val() || 'GENERAL ADVISORY';
        const text = $('#adv_text').val() || '';

        // Parse datetime-local values
        const validFrom = $('#adv_valid_from').val() || '';
        const validUntil = $('#adv_valid_until').val() || '';

        // Format datetime as DD/HHMM
        const formatDateTime = (dt) => {
            if (!dt) return 'TBD';
            const d = new Date(dt + 'Z'); // Treat as UTC
            const day = String(d.getUTCDate()).padStart(2, '0');
            const hour = String(d.getUTCHours()).padStart(2, '0');
            const min = String(d.getUTCMinutes()).padStart(2, '0');
            return `${day}/${hour}${min}`;
        };

        const startFormatted = formatDateTime(validFrom);
        const endFormatted = formatDateTime(validUntil);

        let lines = [
            buildAdvisoryHeader(num, facility, subject.toUpperCase()),
            `VALID: ${startFormatted}Z - ${endFormatted}Z`,
            ``,
            text ? wrapText(text) : '(No text entered)',
            ``,
            buildAdvisoryFooter(num, facility)
        ];

        return lines.join('\n');
    }
    
    function buildHotlinePreview() {
        const num = $('#adv_number').val() || '001';
        const action = $('#adv_hotline_action').val() || 'ACTIVATION';
        const hotlineName = (($('#adv_hotline_name').val() || 'NY Metro') + ' HOTLINE').toUpperCase();
        
        // Parse datetime fields
        const startDateTime = $('#adv_start_datetime').val() || '';
        const endDateTime = $('#adv_end_datetime').val() || '';
        
        // Format datetime as DD/HHMM
        const formatDateTime = (dt) => {
            if (!dt) return '';
            const d = new Date(dt);
            const day = String(d.getUTCDate()).padStart(2, '0');
            const hour = String(d.getUTCHours()).padStart(2, '0');
            const min = String(d.getUTCMinutes()).padStart(2, '0');
            return `${day}/${hour}${min}`;
        };
        
        const startFormatted = formatDateTime(startDateTime);
        const endFormatted = formatDateTime(endDateTime);
        
        const participation = $('#adv_participation').val() || 'MANDATORY';
        // Convert comma-delimited facilities to / delimited for FAA format
        const constrainedFacilitiesRaw = $('#adv_constrained_facilities').val() || '';
        const constrainedFacilities = constrainedFacilitiesRaw.split(/[,\s]+/).filter(f => f.trim()).join('/');
        const facilitiesRaw = $('#adv_facilities').val() || 'TBD';
        const facilities = facilitiesRaw === 'TBD' ? 'TBD' : facilitiesRaw.split(/[,\s]+/).filter(f => f.trim()).join('/');
        const reason = $('#adv_reason').val() || 'WEATHER';
        const impactedArea = $('#adv_impacted_area').val() || '';
        const hotlineAddressCode = $('#adv_hotline_address').val() || 'ts.vatusa.net';
        const notes = $('#adv_notes').val() || '';
        
        // Map address code to display text
        const addressMap = {
            'ts.vatusa.net': 'VATUSA TeamSpeak (ts.vatusa.net)',
            'ts.vatcan.ca': 'VATCAN TeamSpeak (ts.vatcan.ca)',
            'discord': 'vATCSCC Discord, Hotline Backup voice channel'
        };
        const hotlineAddress = addressMap[hotlineAddressCode] || hotlineAddressCode;
        
        let lines = [
            buildAdvisoryHeader(num, 'DCC', `HOTLINE ${action}`)
        ];

        // Build different format based on action type
        if (action === 'ACTIVATION') {
            // Full boilerplate for Activation
            lines.push(`EVENT TIME: ${startFormatted}Z - ${endFormatted ? endFormatted + 'Z' : 'TBD'}`);
            
            if (constrainedFacilities) {
                lines.push(`CONSTRAINED FACILITIES: ${constrainedFacilities}`);
            }
            
            lines.push(``);
            
            // Main boilerplate paragraph - wrap at 68 chars
            let boilerplate = `THE ${hotlineName} IS BEING ACTIVATED TO ADDRESS ${reason} IN ${impactedArea || 'THE AFFECTED AREA'}.`;
            
            // Location info
            boilerplate += ` THE LOCATION IS ${hotlineAddress}.`;
            
            // Participation info
            boilerplate += ` PARTICIPATION IS ${participation} FOR ${facilities}.`;
            
            // Standard closing
            boilerplate += ' AFFECTED MAJOR UNDERLYING FACILITIES ARE STRONGLY ENCOURAGED TO ATTEND. ALL OTHER PARTICIPANTS ARE WELCOME TO JOIN. PLEASE MESSAGE THE NOM IF YOU HAVE ISSUES OR QUESTIONS.';
            
            // Wrap the boilerplate text at 68 characters
            lines.push(wrapText(boilerplate));
            
        } else if (action === 'UPDATE') {
            // Update format
            lines.push(`HOTLINE: ${hotlineName}`);
            lines.push(`ACTION: ${action}`);
            
            if (impactedArea) {
                lines.push(`IMPACTED AREA: ${impactedArea}`);
            }
            
            lines.push(`REASON: ${reason}`);
            lines.push(`FACILITIES INCLUDED: ${facilities}`);
            
            if (startFormatted && endFormatted) {
                lines.push(`VALID TIMES: ${startFormatted}Z - ${endFormatted}Z`);
            } else if (startFormatted) {
                lines.push(`EFFECTIVE: ${startFormatted}Z`);
            }
            
        } else if (action === 'TERMINATION') {
            // Termination format - simple
            lines.push(`THE ${hotlineName} IS BEING TERMINATED EFFECTIVE ${startFormatted}Z.`);
            
            if (constrainedFacilities) {
                lines.push(``);
                lines.push(`FACILITIES AFFECTED: ${constrainedFacilities}`);
            }
        }
        
        // Notes only (removed restrictions)
        if (notes) {
            lines.push(``);
            lines.push(wrapWithLabel('REMARKS: ', notes));
        }
        
        lines.push(``);
        lines.push(buildAdvisoryFooter(num, 'DCC'));
        
        return lines.join('\n');
    }
    
    function buildSwapPreview() {
        const num = $('#adv_number').val() || '001';
        const swapType = $('#adv_swap_type').val() || 'IMPLEMENTATION';
        const duration = parseInt($('#adv_duration').val()) || 4;
        const impactedArea = $('#adv_impacted_area').val() || '';
        // Convert comma-delimited areas to / delimited for FAA format
        const areasRaw = $('#adv_areas').val() || 'TBD';
        const areas = areasRaw === 'TBD' ? 'TBD' : areasRaw.split(/[,\s]+/).filter(f => f.trim()).join('/');
        const includeTraffic = $('#adv_include_traffic').val() || 'ALL';
        const extensionProb = $('#adv_extension_prob').val() || 'NONE';
        const weather = $('#adv_weather').val() || '';
        const routes = $('#adv_routes').val() || '';
        const restrictions = $('#adv_restrictions').val() || '';
        const notes = $('#adv_notes').val() || '';

        // Parse datetime-local value (YYYY-MM-DDTHH:MM format)
        const effDateTime = $('#adv_effective_datetime').val() || '';
        let startDate, startDay, startHour, startMin;

        if (effDateTime) {
            startDate = new Date(effDateTime + 'Z'); // Treat as UTC
            startDay = String(startDate.getUTCDate()).padStart(2, '0');
            startHour = startDate.getUTCHours();
            startMin = String(startDate.getUTCMinutes()).padStart(2, '0');
        } else {
            startDate = new Date();
            startDay = String(startDate.getUTCDate()).padStart(2, '0');
            startHour = startDate.getUTCHours();
            startMin = String(startDate.getUTCMinutes()).padStart(2, '0');
        }

        // Calculate end time
        const endDate = new Date(startDate.getTime() + (duration * 60 * 60 * 1000));
        const endDay = String(endDate.getUTCDate()).padStart(2, '0');
        const endHour = endDate.getUTCHours();
        const endMin = String(endDate.getUTCMinutes()).padStart(2, '0');

        const startTime = String(startHour).padStart(2, '0') + startMin;
        const endTime = String(endHour).padStart(2, '0') + endMin;
        
        let lines = [
            buildAdvisoryHeader(num, 'DCC', `SWAP ${swapType}`)
        ];

        if (impactedArea) {
            lines.push(`IMPACTED AREA: ${impactedArea}`);
        }
        
        lines.push(`REASON: SEVERE WEATHER`);
        lines.push(`INCLUDE TRAFFIC: ${includeTraffic}`);
        lines.push(`VALID TIMES: ${startDay}/${startTime}Z - ${endDay}/${endTime}Z`);
        lines.push(`FACILITIES INCLUDED: ${areas}`);
        
        if (extensionProb !== 'NONE') {
            lines.push(`PROBABILITY OF EXTENSION: ${extensionProb}`);
        }
        
        if (weather) {
            lines.push(``);
            lines.push(`WEATHER SYNOPSIS:`);
            lines.push(wrapText(weather));
        }
        
        if (routes) {
            lines.push(``);
            lines.push(`PLAYBOOK ROUTES / ACTIVE INITIATIVES:`);
            lines.push(wrapText(routes));
        }
        
        if (restrictions) {
            lines.push(``);
            lines.push(wrapWithLabel('ASSOCIATED RESTRICTIONS: ', restrictions));
        }
        
        if (notes) {
            lines.push(``);
            lines.push(wrapWithLabel('REMARKS: ', notes));
        }
        
        lines.push(``);
        lines.push(buildAdvisoryFooter(num, 'DCC'));
        
        return lines.join('\n');
    }
    
    // Standard advisory header per FAA format
    function buildAdvisoryHeader(advNum, facility, type) {
        return `vATCSCC ADVZY ${advNum} ${facility} ${getUtcDateMmDdYyyy()} ${type}`;
    }
    
    // Get user operating initials - from config or extract from name
    function getUserOI() {
        // Use configured OI if available
        if (CONFIG.userOI && CONFIG.userOI.length >= 2) {
            return CONFIG.userOI.toUpperCase();
        }
        
        // Extract initials from userName (first letter of each word)
        const userName = CONFIG.userName || 'XX';
        const parts = userName.trim().split(/\s+/);
        if (parts.length >= 2) {
            // First letter of first and last word
            return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
        }
        // Single word - use first 2 characters
        return userName.substr(0, 2).toUpperCase();
    }
    
    // Build TMI ID in format: {OI}.RR{SOURCE}{ADVZY #}
    function buildTmiId(advNum, facility) {
        const oi = getUserOI();
        const source = (facility || 'DCC').toUpperCase();
        return `${oi}.RR${source}${advNum}`;
    }
    
    // Standard advisory footer with TMI ID and signature
    // Format:
    //   TMI ID: {OI}.RR{SOURCE}{ADVZY #}
    //   {DDHHMM}-{DDHHMM}
    //   YY/MM/DD HH:MM {OI}
    function buildAdvisoryFooter(advNum, facility) {
        const oi = getUserOI();
        const now = new Date();
        
        // TMI ID
        const tmiId = buildTmiId(advNum || '001', facility || 'DCC');
        
        // Valid time range (DDHHMM-DDHHMM format)
        const day = String(now.getUTCDate()).padStart(2, '0');
        const hour = String(now.getUTCHours()).padStart(2, '0');
        const min = String(now.getUTCMinutes()).padStart(2, '0');
        const timestamp = `${day}${hour}${min}`;
        
        const endHour = (now.getUTCHours() + 4) % 24;
        const endDay = (now.getUTCHours() + 4 >= 24) ? String(now.getUTCDate() + 1).padStart(2, '0') : day;
        const endTimestamp = `${endDay}${String(endHour).padStart(2, '0')}${min}`;
        
        // Signature line: YY/MM/DD HH:MM {OI}
        const year = String(now.getUTCFullYear()).substr(2, 2);
        const month = String(now.getUTCMonth() + 1).padStart(2, '0');
        const signature = `${year}/${month}/${day} ${hour}:${min} ${oi}`;
        
        return [
            `TMI ID: ${tmiId}`,
            `${timestamp}-${endTimestamp}`,
            signature
        ].join('\n');
    }
    
    function getUtcDateMmDdYyyy() {
        const now = new Date();
        return `${String(now.getUTCMonth() + 1).padStart(2, '0')}/${String(now.getUTCDate()).padStart(2, '0')}/${now.getUTCFullYear()}`;
    }
    
    function getValidTimeRange() {
        const now = new Date();
        const day = String(now.getUTCDate()).padStart(2, '0');
        const startHour = now.getUTCHours();
        const endHour = (startHour + 4) % 24;
        const endDay = (startHour + 4 >= 24) ? String(now.getUTCDate() + 1).padStart(2, '0') : day;
        
        return {
            start: `${day}${String(startHour).padStart(2, '0')}00`,
            end: `${endDay}${String(endHour).padStart(2, '0')}00`
        };
    }
    
    // ===========================================
    // Queue Management
    // ===========================================
    
    function addNtmlToQueue(type, skipDuplicateCheck = false) {
        // Validate required fields
        const ctlElement = ($('#ntml_ctl_element').val() || '').trim().toUpperCase();
        const reqFacility = ($('#ntml_req_facility').val() || '').trim().toUpperCase();
        const provFacility = ($('#ntml_prov_facility').val() || '').trim().toUpperCase();
        const validFrom = ($('#ntml_valid_from').val() || '').trim();
        const validUntil = ($('#ntml_valid_until').val() || '').trim();

        // Basic validation
        if (!ctlElement && type !== 'DELAY') {
            Swal.fire('Missing Field', 'Please enter an airport or fix', 'warning');
            return;
        }

        if (type !== 'DELAY' && type !== 'CONFIG' && (!reqFacility || !provFacility)) {
            Swal.fire('Missing Facilities', 'Please enter requesting and providing facilities', 'warning');
            return;
        }

        // Check for duplicate CONFIG entries
        if (type === 'CONFIG' && ctlElement && !skipDuplicateCheck) {
            checkDuplicateConfig(ctlElement, function(existingConfig) {
                if (existingConfig) {
                    showDuplicateConfigPrompt(ctlElement, existingConfig, type);
                } else {
                    addNtmlToQueue(type, true);
                }
            });
            return;
        }
        
        // Build entry data
        const data = {
            type: type,
            ctl_element: ctlElement,
            req_facility: reqFacility,
            prov_facility: provFacility,
            valid_from: validFrom,
            valid_until: validUntil
        };
        
        // Collect active qualifiers
        const qualifiers = [];
        $('.qualifier-btn.active').each(function() {
            qualifiers.push($(this).data('qualifier'));
        });
        data.qualifiers = qualifiers;
        
        // Collect altitude and speed filters (for all types except CONFIG)
        if (type !== 'CONFIG') {
            const altFilter = ($('#ntml_altitude_filter').val() || '').trim().toUpperCase();
            if (altFilter) data.altitude_filter = altFilter;
            
            const speedFilter = $('#ntml_speed_filter').val();
            if (speedFilter) data.speed_filter = speedFilter;
        }
        
        // Type-specific data
        switch(type) {
            case 'MIT':
            case 'MINIT':
                data.value = $('#ntml_value').val();
                data.via_fix = ($('#ntml_via_fix').val() || '').trim().toUpperCase();
                data.reason_category = $('#ntml_reason_category').val() || 'VOLUME';
                data.reason_cause = $('#ntml_reason_cause').val() || data.reason_category;
                data.exclusions = ($('#ntml_exclusions').val() || 'NONE').trim().toUpperCase();
                break;
            case 'STOP':
                data.via_fix = ($('#ntml_via_fix').val() || '').trim().toUpperCase();
                data.reason_category = $('#ntml_reason_category').val() || 'VOLUME';
                data.reason_cause = $('#ntml_reason_cause').val() || data.reason_category;
                data.exclusions = ($('#ntml_exclusions').val() || 'NONE').trim().toUpperCase();
                break;
            case 'APREQ':
                data.apreq_type = $('#ntml_apreq_type').val() || 'CFR';
                data.via_fix = ($('#ntml_via_fix').val() || '').trim().toUpperCase();
                data.reason_category = $('#ntml_reason_category').val() || 'VOLUME';
                data.reason_cause = $('#ntml_reason_cause').val() || data.reason_category;
                break;
            case 'TBM':
                data.meter_point = ($('#ntml_meter_point').val() || '').trim().toUpperCase();
                data.freeze_horizon = $('#ntml_freeze_horizon').val();
                data.participating = ($('#ntml_participating').val() || '').trim().toUpperCase();
                data.reason_category = $('#ntml_reason_category').val() || 'VOLUME';
                data.reason_cause = $('#ntml_reason_cause').val() || data.reason_category;
                break;
            case 'DELAY':
                data.delay_type = $('#ntml_delay_type').val();
                data.delay_minutes = $('#ntml_delay_minutes').val();
                data.delay_trend = $('#ntml_delay_trend').val();
                data.reason = $('#ntml_reason').val();
                data.report_time = $('#ntml_report_time').val();
                data.acft_count = $('#ntml_acft_count').val();
                break;
            case 'CONFIG':
                data.weather = $('#ntml_weather').val();
                data.config_name = $('#ntml_config_name').val();
                data.arr_runways = ($('#ntml_arr_runways').val() || '').trim().toUpperCase();
                data.dep_runways = ($('#ntml_dep_runways').val() || '').trim().toUpperCase();
                data.aar = $('#ntml_aar').val();
                data.aar_type = $('#ntml_aar_type').val();
                data.adr = $('#ntml_adr').val();
                break;
            case 'CANCEL':
                data.cancel_type = $('#ntml_cancel_type').val();
                data.via_fix = ($('#ntml_via_fix').val() || '').trim().toUpperCase();
                break;
        }
        
        // Build formatted message
        const message = formatNtmlMessage(type, data);
        
        // Get selected orgs
        const orgs = getSelectedOrgs();
        
        const entry = {
            id: generateId(),
            type: 'ntml',
            entryType: type,
            data: data,
            preview: message,
            orgs: orgs,
            timestamp: new Date().toISOString()
        };
        
        state.queue.push(entry);
        saveState();
        updateUI();
        
        // Switch to queue tab
        $('#queue-tab').tab('show');
        
        Swal.fire({
            icon: 'success',
            title: 'Added to Queue',
            text: `${type} entry added`,
            timer: 1500,
            showConfirmButton: false
        });
    }
    
    function formatNtmlMessage(type, data) {
        const logTime = getCurrentDateDDHHMM();
        const validTime = formatValidTime(data.valid_from, data.valid_until);
        
        switch(type) {
            case 'MIT':
            case 'MINIT':
                return formatMitMinitMessage(type, data, logTime, validTime);
            case 'STOP':
                return formatStopMessage(data, logTime, validTime);
            case 'APREQ':
                return formatApreqMessage(data, logTime, validTime);
            case 'TBM':
                return formatTbmMessage(data, logTime, validTime);
            case 'DELAY':
                return formatDelayMessage(data, logTime);
            case 'CONFIG':
                return formatConfigMessage(data, logTime);
            case 'CANCEL':
                return formatCancelMessage(data, logTime);
            default:
                return `${logTime} ${type} ${data.ctl_element || 'N/A'}`;
        }
    }
    
    function formatMitMinitMessage(type, data, logTime, validTime) {
        // Format: {DD/HHMM}    {element} via {fix} {value}{type} {spacing} EXCL:{excl} {category}:{cause} {filters} {valid} {req}:{prov}
        const element = (data.ctl_element || 'N/A').toUpperCase();
        const viaFix = data.via_fix ? (data.via_fix).toUpperCase() : 'ALL';
        const value = data.value || '20';
        const category = (data.reason_category || 'VOLUME').toUpperCase();
        const cause = (data.reason_cause || category).toUpperCase();
        const exclusions = data.exclusions ? data.exclusions.toUpperCase() : 'NONE';
        const reqFac = (data.req_facility || 'N/A').toUpperCase();
        const provFac = (data.prov_facility || 'N/A').toUpperCase();
        
        // Get spacing qualifier (first one found)
        let spacing = 'AS ONE'; // Default
        if (data.qualifiers && data.qualifiers.length > 0) {
            const spacingQual = data.qualifiers.find(q => 
                ['AS ONE', 'PER STREAM', 'PER AIRPORT', 'PER FIX', 'EACH'].includes(q)
            );
            if (spacingQual) spacing = spacingQual;
        }
        
        // Build filter string (altitude, speed)
        let filters = '';
        if (data.altitude_filter) {
            filters += ` ALT:${data.altitude_filter.toUpperCase()}`;
        }
        if (data.speed_filter) {
            filters += ` SPD:${data.speed_filter}KTS`;
        }
        
        // Other qualifiers (aircraft type, weight, equipment, flow, operator)
        let otherQuals = '';
        if (data.qualifiers && data.qualifiers.length > 0) {
            const nonSpacing = data.qualifiers.filter(q => 
                !['AS ONE', 'PER STREAM', 'PER AIRPORT', 'PER FIX', 'EACH'].includes(q)
            );
            if (nonSpacing.length > 0) {
                otherQuals = ' ' + nonSpacing.join(' ');
            }
        }
        
        // Format: valid period and req:prov ALWAYS at the end
        let line = `${logTime}    ${element} via ${viaFix} ${value}${type} ${spacing}${otherQuals} EXCL:${exclusions} ${category}:${cause}${filters} ${validTime} ${reqFac}:${provFac}`;
        
        return line;
    }
    
    function formatStopMessage(data, logTime, validTime) {
        // Format: {DD/HHMM}    STOP {element} {traffic_flow} via {fix} {qualifiers} EXCL:{excl} {category}:{cause} {filters} {valid} {req}:{prov}
        const element = (data.ctl_element || 'N/A').toUpperCase();
        const viaFix = data.via_fix ? (data.via_fix).toUpperCase() : 'ALL';
        const trafficFlow = data.traffic_flow ? ` ${data.traffic_flow}` : '';
        const category = (data.reason_category || 'VOLUME').toUpperCase();
        const cause = (data.reason_cause || category).toUpperCase();
        const exclusions = data.exclusions ? data.exclusions.toUpperCase() : 'NONE';
        const reqFac = (data.req_facility || 'N/A').toUpperCase();
        const provFac = (data.prov_facility || 'N/A').toUpperCase();

        // Get qualifiers
        let qualStr = '';
        if (data.qualifiers && data.qualifiers.length > 0) {
            qualStr = ' ' + data.qualifiers.join(' ');
        }

        // Build filter string (altitude, speed)
        let filters = '';
        if (data.altitude_filter) {
            filters += ` ALT:${data.altitude_filter.toUpperCase()}`;
        }
        if (data.speed_filter) {
            filters += ` SPD:${data.speed_filter}KTS`;
        }

        let line = `${logTime}    STOP ${element}${trafficFlow} via ${viaFix}${qualStr} EXCL:${exclusions} ${category}:${cause}${filters} ${validTime} ${reqFac}:${provFac}`;

        return line;
    }
    
    function formatApreqMessage(data, logTime, validTime) {
        // Use CFR directly, no expansion needed
        const apreqType = data.apreq_type || 'CFR';
        const category = (data.reason_category || 'VOLUME').toUpperCase();
        const cause = (data.reason_cause || category).toUpperCase();
        let line = `${logTime}    ${apreqType} ${(data.ctl_element || 'N/A').toUpperCase()}`;
        
        if (data.via_fix) {
            line += ` via ${data.via_fix.toUpperCase()}`;
        }
        
        line += ` ${category}:${cause}`;
        
        // Get qualifiers
        if (data.qualifiers && data.qualifiers.length > 0) {
            line += ' ' + data.qualifiers.join(' ');
        }
        
        // Filters
        if (data.altitude_filter) {
            line += ` ALT:${data.altitude_filter.toUpperCase()}`;
        }
        if (data.speed_filter) {
            line += ` SPD:${data.speed_filter}KTS`;
        }
        
        // Valid period and facilities ALWAYS at the end
        line += ` ${validTime}`;
        line += ` ${(data.req_facility || 'N/A').toUpperCase()}:${(data.prov_facility || 'N/A').toUpperCase()}`;
        
        return line;
    }
    
    function formatTbmMessage(data, logTime, validTime) {
        const category = (data.reason_category || 'VOLUME').toUpperCase();
        const cause = (data.reason_cause || category).toUpperCase();
        let line = `${logTime}    ${(data.ctl_element || 'N/A').toUpperCase()} TBM`;
        
        if (data.meter_point) {
            line += ` ${data.meter_point.toUpperCase()}`;
        }
        
        // Freeze horizon formatted as TIME+{VALUE}MIN
        if (data.freeze_horizon) {
            line += ` TIME+${data.freeze_horizon}MIN`;
        }
        
        line += ` ${category}:${cause}`;
        
        // Get qualifiers
        if (data.qualifiers && data.qualifiers.length > 0) {
            line += ' ' + data.qualifiers.join(' ');
        }
        
        // Filters
        if (data.altitude_filter) {
            line += ` ALT:${data.altitude_filter.toUpperCase()}`;
        }
        if (data.speed_filter) {
            line += ` SPD:${data.speed_filter}KTS`;
        }
        
        // Participating facilities
        if (data.participating) {
            line += ` PTCP:${data.participating}`;
        }
        
        // Valid period and facilities ALWAYS at the end
        line += ` ${validTime}`;
        line += ` ${(data.req_facility || 'N/A').toUpperCase()}:${(data.prov_facility || 'N/A').toUpperCase()}`;
        
        return line;
    }
    
    function formatDelayMessage(data, logTime) {
        const delayType = data.delay_type || 'E/D';
        const reportTime = (data.report_time || '').replace(':', '') || '';
        
        let sign = '';
        if (data.delay_trend === 'INC') sign = '+';
        if (data.delay_trend === 'DEC') sign = '-';
        
        let line = `${logTime}    ${delayType} ${data.ctl_element || 'N/A'}, ${sign}${data.delay_minutes || '0'}/${reportTime}`;
        
        if (data.acft_count && parseInt(data.acft_count) > 1) {
            line += `/${data.acft_count} ACFT`;
        }
        
        line += ` ${data.reason || 'VOLUME'}`;
        
        return line;
    }
    
    function formatConfigMessage(data, logTime) {
        // Format: {DD/HHMM}    {airport}    {weather}    ARR:{arr} DEP:{dep}    AAR(Strat):{aar} ADR:{adr}
        const airport = (data.ctl_element || 'N/A').toUpperCase();
        const weather = (data.weather || 'VMC').toUpperCase();
        const arrRwys = (data.arr_runways || 'N/A').toUpperCase();
        const depRwys = (data.dep_runways || 'N/A').toUpperCase();
        const aar = data.aar || '60';
        const adr = data.adr || '60';
        const aarType = data.aar_type || 'Strat';
        
        let line = `${logTime}    ${airport}    ${weather}    ARR:${arrRwys} DEP:${depRwys}    AAR(${aarType}):${aar} ADR:${adr}`;
        
        return line;
    }
    
    function formatCancelMessage(data, logTime) {
        let line = `${logTime}    `;
        
        if (data.cancel_type === 'ALL') {
            line += `ALL TMI CANCELLED ${data.ctl_element || 'N/A'}`;
        } else {
            line += `CANCEL ${data.ctl_element || 'N/A'}`;
            if (data.via_fix) {
                line += ` via ${data.via_fix}`;
            }
        }
        
        if (data.req_facility && data.prov_facility) {
            line += ` ${data.req_facility}:${data.prov_facility}`;
        }
        
        return line;
    }
    
    function addAdvisoryToQueue() {
        const type = state.selectedAdvisoryType;
        if (!type) return;
        
        const preview = $('#adv_preview').text();
        const orgs = getSelectedOrgsAdvisory();
        
        const entry = {
            id: generateId(),
            type: 'advisory',
            entryType: type,
            data: collectAdvisoryFormData(type),
            preview: preview,
            orgs: orgs,
            timestamp: new Date().toISOString()
        };
        
        state.queue.push(entry);
        saveState();
        updateUI();
        
        $('#queue-tab').tab('show');
        
        Swal.fire({
            icon: 'success',
            title: 'Added to Queue',
            text: `${type} advisory added`,
            timer: 1500,
            showConfirmButton: false
        });
    }
    
    function collectAdvisoryFormData(type) {
        const data = {
            advisory_type: type,
            number: $('#adv_number').val() || '001',
            facility: $('#adv_facility').val() || 'DCC'
        };
        
        // Collect all form fields
        $('#adv_form_container input, #adv_form_container textarea, #adv_form_container select').each(function() {
            const id = $(this).attr('id');
            if (id) {
                data[id.replace('adv_', '')] = $(this).val() || '';
            }
        });
        
        return data;
    }
    
    function updateQueueDisplay() {
        const container = $('#entryQueueList');
        
        if (!state.queue || state.queue.length === 0) {
            container.html(`
                <div class="text-center text-muted py-4" id="emptyQueueMsg">
                    <i class="fas fa-inbox fa-2x mb-2"></i><br>
                    No entries queued. Add entries from NTML or Advisory tabs.
                </div>
            `);
            return;
        }
        
        let html = '';
        state.queue.forEach((entry, index) => {
            // Safe null checks for all properties
            if (!entry || typeof entry !== 'object') return;
            
            const entryType = entry.type || 'unknown';
            const entrySubType = entry.entryType || 'N/A';
            const typeClass = entryType === 'advisory' ? 'border-purple' : 'border-success';
            const typeBadge = entryType === 'advisory' ? 'badge-purple' : 'badge-success';
            
            // Safe preview text extraction with multiple fallbacks
            let previewText = 'Entry';
            if (entry.preview && typeof entry.preview === 'string') {
                previewText = entry.preview;
            } else if (entry.entryType && typeof entry.entryType === 'string') {
                previewText = entry.entryType;
            }
            
            // Safe substring with length check
            const displayText = previewText.length > 200 ? previewText.substring(0, 200) + '...' : previewText;
            
            // Safe orgs extraction
            const orgs = Array.isArray(entry.orgs) ? entry.orgs : ['vatcscc'];
            
            html += `
                <div class="queue-item p-3 border-left-4 ${typeClass} mb-2 bg-light rounded">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="flex-grow-1">
                            <span class="badge ${typeBadge} mr-1">${escapeHtml(entryType.toUpperCase())}</span>
                            <span class="badge badge-secondary">${escapeHtml(entrySubType)}</span>
                            <div class="mt-2 font-monospace small text-dark" style="white-space: pre-wrap; max-height: 100px; overflow-y: auto;">${escapeHtml(displayText)}</div>
                            <div class="mt-1 small text-muted">
                                <i class="fab fa-discord"></i> ${orgs.join(', ')}
                            </div>
                        </div>
                        <div class="ml-2">
                            <button class="btn btn-sm btn-outline-primary mb-1" onclick="TMIPublisher.previewEntry(${index})" title="Preview">
                                <i class="fas fa-eye"></i><span class="ml-1 d-none d-sm-inline">View</span>
                            </button>
                            <button class="btn btn-sm btn-outline-danger" onclick="TMIPublisher.removeFromQueue(${index})" title="Remove">
                                <i class="fas fa-times"></i><span class="ml-1 d-none d-sm-inline">Del</span>
                            </button>
                        </div>
                    </div>
                </div>
            `;
        });
        
        container.html(html);
    }
    
    function removeFromQueue(index) {
        state.queue.splice(index, 1);
        saveState();
        updateUI();
    }
    
    function clearQueue() {
        if (!state.queue || state.queue.length === 0) return;
        
        Swal.fire({
            title: 'Clear Queue?',
            text: `Remove all ${state.queue.length} entries from queue?`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            confirmButtonText: 'Clear All'
        }).then((result) => {
            if (result.isConfirmed) {
                state.queue = [];
                saveState();
                updateUI();
            }
        });
    }
    
    function previewEntry(index) {
        const entry = state.queue[index];
        if (!entry) return;
        
        const previewText = entry.preview || 'No preview available';
        $('#previewModalContent').text(previewText);
        $('#previewModal').modal('show');
    }

    // ===========================================
    // Duplicate CONFIG Detection
    // ===========================================

    function checkDuplicateConfig(airport, callback) {
        // Check active TMIs for existing CONFIG for this airport
        $.ajax({
            url: 'api/mgt/tmi/active.php',
            method: 'GET',
            data: { type: 'ntml', source: 'ALL' },
            success: function(response) {
                if (response.success && response.data) {
                    const allItems = [...(response.data.active || []), ...(response.data.scheduled || [])];
                    const existing = allItems.find(item =>
                        item.entryType === 'CONFIG' &&
                        item.ctlElement &&
                        item.ctlElement.toUpperCase() === airport.toUpperCase() &&
                        item.status !== 'CANCELLED'
                    );
                    callback(existing || null);
                } else {
                    callback(null);
                }
            },
            error: function() {
                callback(null);
            }
        });
    }

    function showDuplicateConfigPrompt(airport, existingConfig, type) {
        const existingTime = existingConfig.validFrom ?
            new Date(existingConfig.validFrom).toLocaleString('en-US', { timeZone: 'UTC', hour: '2-digit', minute: '2-digit', hour12: false }) + 'Z' :
            'Unknown time';
        const existingStatus = existingConfig.status || 'ACTIVE';

        Swal.fire({
            title: `<i class="fas fa-exclamation-triangle text-warning"></i> Duplicate CONFIG`,
            html: `
                <div class="text-left">
                    <p>An active CONFIG already exists for <strong>${airport}</strong>:</p>
                    <div class="alert alert-secondary">
                        <strong>Status:</strong> ${existingStatus}<br>
                        <strong>Posted:</strong> ${existingTime}<br>
                        <strong>ID:</strong> #${existingConfig.entityId}
                    </div>
                    <p>What would you like to do?</p>
                </div>
            `,
            icon: 'warning',
            showCancelButton: true,
            showDenyButton: true,
            confirmButtonText: '<i class="fas fa-edit"></i> Update Existing',
            confirmButtonColor: '#007bff',
            denyButtonText: '<i class="fas fa-plus"></i> Create New Anyway',
            denyButtonColor: '#6c757d',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                // Cancel the old one and create new
                cancelAndReplaceConfig(existingConfig.entityId, type);
            } else if (result.isDenied) {
                // Just add the new one without canceling old
                addNtmlToQueue(type, true);
            }
            // If cancelled, do nothing
        });
    }

    function cancelAndReplaceConfig(existingId, type) {
        const userCid = CONFIG.userCid || null;
        const userName = CONFIG.userName || 'Unknown';

        Swal.fire({
            title: 'Cancelling old CONFIG...',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading()
        });

        $.ajax({
            url: 'api/mgt/tmi/cancel.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                entityType: 'ENTRY',
                entityId: existingId,
                reason: 'Replaced with updated CONFIG',
                userCid: userCid,
                userName: userName
            }),
            success: function(response) {
                Swal.close();
                if (response.success) {
                    // Now add the new CONFIG
                    addNtmlToQueue(type, true);
                } else {
                    Swal.fire('Error', response.error || 'Failed to cancel old CONFIG', 'error');
                }
            },
            error: function(xhr) {
                Swal.close();
                Swal.fire('Error', 'Failed to cancel old CONFIG: ' + (xhr.responseJSON?.error || 'Unknown error'), 'error');
            }
        });
    }

    // ===========================================
    // Submit
    // ===========================================

    function submitAll() {
        if (!state.queue || state.queue.length === 0) return;
        
        const mode = state.productionMode ? 'PRODUCTION' : 'STAGING';
        const modeClass = state.productionMode ? 'text-danger' : 'text-warning';
        
        Swal.fire({
            title: `Submit to ${mode}?`,
            html: `<p>Post <strong>${state.queue.length}</strong> entries to Discord.</p>
                   <p class="${modeClass}">Mode: <strong>${mode}</strong></p>`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: state.productionMode ? '#dc3545' : '#28a745',
            confirmButtonText: `Submit to ${mode}`
        }).then((result) => {
            if (result.isConfirmed) {
                performSubmit();
            }
        });
    }
    
    function performSubmit() {
        const payload = {
            entries: state.queue,
            production: state.productionMode,
            userCid: CONFIG.userCid
        };
        
        // Show loading
        Swal.fire({
            title: 'Submitting...',
            text: 'Posting entries to Discord',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading()
        });
        
        $.ajax({
            url: 'api/mgt/tmi/publish.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(payload),
            success: function(response) {
                Swal.close();
                
                if (response.success) {
                    // Clear queue on success
                    state.queue = [];
                    saveState();
                    updateUI();
                    
                    showSubmitResults(response);
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Submit Failed',
                        text: response.error || 'Unknown error occurred'
                    });
                }
            },
            error: function(xhr, status, error) {
                Swal.close();
                console.error('Submit error:', xhr.responseText);
                Swal.fire({
                    icon: 'error',
                    title: 'Connection Error',
                    html: `<p>Failed to connect to server.</p><p class="small text-muted">${error}</p>`
                });
            }
        });
    }
    
    function showSubmitResults(response) {
        let html = `<p><strong>Summary:</strong> ${response.summary?.success || 0}/${response.summary?.total || 0} succeeded</p>`;
        
        if (response.results && response.results.length > 0) {
            html += '<ul class="list-unstyled small">';
            response.results.forEach(r => {
                const icon = r.success ? '✅' : '❌';
                html += `<li>${icon} ${r.entryType || r.type || 'Entry'}</li>`;
            });
            html += '</ul>';
        }
        
        Swal.fire({
            icon: (response.summary?.failed || 0) === 0 ? 'success' : 'warning',
            title: 'Submit Complete',
            html: html
        });
        
        // Refresh staged entries
        loadStagedEntries();
    }
    
    // ===========================================
    // Active TMIs
    // ===========================================
    
    function loadActiveTmis() {
        $('#activeTmiBody').html(`
            <tr>
                <td colspan="6" class="text-center text-muted py-4">
                    <i class="fas fa-spinner fa-spin"></i> Loading active TMIs...
                </td>
            </tr>
        `);
        
        $.ajax({
            url: 'api/mgt/tmi/active.php',
            method: 'GET',
            data: { type: 'all', include_scheduled: '1', include_cancelled: '1', cancelled_hours: 4 },
            success: function(response) {
                if (response.success) {
                    displayActiveTmis(response);
                } else {
                    showActiveTmiError('Failed to load TMIs: ' + (response.error || 'Unknown error'));
                }
            },
            error: function(xhr, status, error) {
                console.log('Active TMIs API error:', error);
                showActiveTmiError('No active TMIs (database may not be configured)');
            }
        });
    }
    
    function displayActiveTmis(response) {
        const data = response.data || { active: [], scheduled: [], cancelled: [] };
        const activeArr = Array.isArray(data.active) ? data.active : [];
        const scheduledArr = Array.isArray(data.scheduled) ? data.scheduled : [];
        const cancelledArr = Array.isArray(data.cancelled) ? data.cancelled : [];
        const total = activeArr.length + scheduledArr.length + cancelledArr.length;
        
        if (total === 0) {
            $('#activeTmiBody').html(`
                <tr>
                    <td colspan="6" class="text-center text-muted py-4">
                        <i class="fas fa-info-circle"></i> No active TMIs found
                    </td>
                </tr>
            `);
            return;
        }
        
        let html = '';
        
        // Active entries first
        activeArr.forEach(entry => {
            html += buildTmiTableRow(entry, 'success', 'ACTIVE');
        });
        
        // Scheduled entries
        scheduledArr.forEach(entry => {
            html += buildTmiTableRow(entry, 'info', 'SCHED');
        });
        
        // Cancelled entries
        cancelledArr.forEach(entry => {
            html += buildTmiTableRow(entry, 'secondary', 'CXLD');
        });
        
        $('#activeTmiBody').html(html);
    }
    
    function buildTmiTableRow(entry, badgeClass, statusText) {
        if (!entry || typeof entry !== 'object') {
            return '';
        }
        
        const entryType = entry.type || 'unknown';
        const typeIcon = entryType === 'advisory' ? 'fa-bullhorn' : 'fa-clipboard-list';
        const typeBadge = entryType === 'advisory' ? 'badge-purple' : 'badge-primary';
        const entrySubType = entry.entryType || 'N/A';
        const summary = entry.summary || '';
        const entityId = entry.entityId || 0;
        const entityType = entry.entityType || 'ntml';
        const status = entry.status || '';
        
        const facilities = entry.requestingFacility && entry.providingFacility 
            ? `${entry.requestingFacility}:${entry.providingFacility}` 
            : (entry.facilityCode || '');
        
        let validTime = '';
        if (entry.validFrom) {
            try {
                validTime = new Date(entry.validFrom).toISOString().substr(11, 5).replace(':', '') + 'Z';
            } catch (e) {
                validTime = '';
            }
        }
        
        return `
            <tr>
                <td>
                    <span class="badge ${typeBadge}">
                        <i class="fas ${typeIcon} mr-1"></i>${escapeHtml(entrySubType)}
                    </span>
                </td>
                <td class="small">${escapeHtml(summary)}</td>
                <td class="small">${escapeHtml(facilities)}</td>
                <td class="small font-monospace">${escapeHtml(validTime)}</td>
                <td><span class="badge badge-${badgeClass}">${statusText}</span></td>
                <td>
                    <button class="btn btn-xs btn-outline-secondary" onclick="TMIPublisher.viewTmiDetails(${entityId}, '${escapeHtml(entityType)}')" title="View Details">
                        <i class="fas fa-eye"></i>
                    </button>
                    ${status !== 'CANCELLED' ? `
                    <button class="btn btn-xs btn-outline-danger ml-1" onclick="TMIPublisher.cancelTmi(${entityId}, '${escapeHtml(entityType)}')" title="Cancel">
                        <i class="fas fa-times"></i>
                    </button>` : ''}
                </td>
            </tr>
        `;
    }
    
    function showActiveTmiError(message) {
        $('#activeTmiBody').html(`
            <tr>
                <td colspan="6" class="text-center text-muted py-4">
                    <i class="fas fa-info-circle"></i> ${escapeHtml(message)}
                </td>
            </tr>
        `);
    }
    
    function viewTmiDetails(id, entityType) {
        Swal.fire({
            title: 'TMI Details',
            text: `${entityType || 'Entry'} #${id} - Details view coming soon`,
            icon: 'info'
        });
    }
    
    function cancelTmi(id, entityType) {
        Swal.fire({
            title: 'Cancel TMI?',
            text: `This will cancel ${(entityType || 'entry').toLowerCase()} #${id}`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            confirmButtonText: 'Cancel TMI'
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({
                    icon: 'info',
                    title: 'Coming Soon',
                    text: 'Cancel functionality will be implemented shortly'
                });
            }
        });
    }
    
    // ===========================================
    // Staged Entries
    // ===========================================
    
    function loadStagedEntries() {
        $.ajax({
            url: 'api/mgt/tmi/staged.php',
            method: 'GET',
            success: function(response) {
                if (response.success) {
                    displayStagedEntries(response.entries);
                }
            },
            error: function() {
                // Silent fail - staged entries API might not exist yet
            }
        });
    }
    
    function displayStagedEntries(entries) {
        const container = $('#recentPostsList');
        
        if (!entries || !Array.isArray(entries) || entries.length === 0) {
            container.html(`
                <div class="list-group-item text-center text-muted py-3">
                    <i class="fas fa-clock"></i> No staged posts
                </div>
            `);
            return;
        }
        
        let html = '';
        entries.forEach(entry => {
            if (!entry || typeof entry !== 'object') return;
            
            const summary = entry.summary || entry.entryType || 'Entry';
            const entityType = entry.entityType || 'ntml';
            const entityId = entry.entityId || 0;
            const stagedOrgs = Array.isArray(entry.stagedOrgs) ? entry.stagedOrgs : [];
            
            html += `
                <div class="list-group-item p-2">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <span class="badge badge-warning badge-sm mr-1">STAGED</span>
                            <span class="small">${escapeHtml(summary)}</span>
                        </div>
                        <button class="btn btn-sm btn-success" onclick="TMIPublisher.promoteEntry('${escapeHtml(entityType)}', ${entityId}, ${JSON.stringify(stagedOrgs)})">
                            <i class="fas fa-arrow-up"></i>
                        </button>
                    </div>
                </div>
            `;
        });
        
        container.html(html);
    }
    
    function promoteEntry(entityType, entityId, orgs) {
        Swal.fire({
            title: 'Promote to Production?',
            html: `<p>Publish this ${(entityType || 'entry').toLowerCase()} to production channels?</p>
                   <p class="text-danger">This will post to LIVE channels.</p>`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#28a745',
            confirmButtonText: 'Promote'
        }).then((result) => {
            if (result.isConfirmed) {
                performPromotion(entityType, entityId, orgs);
            }
        });
    }
    
    function performPromotion(entityType, entityId, orgs) {
        $.ajax({
            url: 'api/mgt/tmi/promote.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                entityType: entityType,
                entityId: entityId,
                orgs: orgs,
                deleteStaging: true,
                userCid: CONFIG.userCid
            }),
            success: function(response) {
                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Promoted!',
                        text: 'Entry published to production channels.',
                        timer: 2000,
                        showConfirmButton: false
                    });
                    loadStagedEntries();
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Promotion Failed',
                        text: response.results?.[0]?.error || response.error || 'Unknown error'
                    });
                }
            },
            error: function() {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Failed to connect to server'
                });
            }
        });
    }
    
    // ===========================================
    // UI Updates
    // ===========================================
    
    function updateUI() {
        updateQueueBadge();
        updateQueueDisplay();
        updateModeIndicator();
        updateSubmitControls();
    }
    
    function updateQueueBadge() {
        const count = state.queue ? state.queue.length : 0;
        $('#queueBadge').text(count);
        $('#submitCount').text(count);
    }
    
    function updateModeIndicator() {
        const badge = $('#modeIndicator');
        const targetMode = $('#targetModeDisplay');
        const btnText = $('#submitBtnText');
        const hint = $('#submitHint');
        const warning = $('#prodWarning');
        
        if (state.productionMode) {
            badge.removeClass('badge-warning').addClass('badge-danger').text('PRODUCTION');
            targetMode.removeClass('badge-warning').addClass('badge-danger').text('PRODUCTION');
            btnText.text('Submit to Production');
            hint.text('Entries will post directly to LIVE Discord channels');
            warning.slideDown();
            $('#submitAllBtn').removeClass('btn-success').addClass('btn-danger');
        } else {
            badge.removeClass('badge-danger').addClass('badge-warning').text('STAGING');
            targetMode.removeClass('badge-danger').addClass('badge-warning').text('STAGING');
            btnText.text('Submit to Staging');
            hint.text('Entries will post to staging channels for review');
            warning.slideUp();
            $('#submitAllBtn').removeClass('btn-danger').addClass('btn-success');
        }
    }
    
    function updateSubmitControls() {
        const hasEntries = state.queue && state.queue.length > 0;
        $('#submitAllBtn').prop('disabled', !hasEntries);
        
        // Update target orgs display
        const orgs = new Set();
        if (state.queue && Array.isArray(state.queue)) {
            state.queue.forEach(e => {
                if (e && Array.isArray(e.orgs)) {
                    e.orgs.forEach(o => orgs.add(o));
                }
            });
        }
        const orgNames = Array.from(orgs).map(code => {
            return CONFIG.discordOrgs?.[code]?.name || code;
        });
        $('#targetOrgsDisplay').text(orgNames.length > 0 ? orgNames.join(', ') : 'None selected');
    }
    
    function resetNtmlForm() {
        $('#ntml_form_container input:not([readonly])').val('');
        $('#ntml_form_container select').each(function() {
            this.selectedIndex = 0;
        });
        $('#ntml_value').val('20');
        $('.qualifier-btn').removeClass('btn-primary active').addClass('btn-outline-secondary');
        
        // Reset time fields to smart defaults
        const times = getSmartDefaultTimes();
        $('#ntml_valid_from').val(times.start);
        $('#ntml_valid_until').val(times.end);
    }
    
    // ===========================================
    // Utilities
    // ===========================================
    
    function getSelectedOrgs() {
        const orgs = [];
        $('.discord-org-checkbox:checked').each(function() {
            orgs.push($(this).val());
        });
        return orgs.length > 0 ? orgs : ['vatcscc'];
    }
    
    function getSelectedOrgsAdvisory() {
        const orgs = [];
        $('.discord-org-checkbox-adv:checked').each(function() {
            orgs.push($(this).val());
        });
        return orgs.length > 0 ? orgs : ['vatcscc'];
    }
    
    function detectCrossBorderFromFacilities() {
        const reqFac = ($('#ntml_req_facility').val() || '').trim().toUpperCase();
        const provFac = ($('#ntml_prov_facility').val() || '').trim().toUpperCase();
        
        let crossBorder = false;
        [reqFac, provFac].forEach(fac => {
            if (CROSS_BORDER_FACILITIES.includes(fac)) {
                crossBorder = true;
            }
        });
        
        // Auto-check partner org if cross-border detected
        if (crossBorder && CONFIG.crossBorderAutoDetect) {
            if (reqFac?.startsWith('CZ') || provFac?.startsWith('CZ')) {
                $('#org_vatcan').prop('checked', true);
            }
            if (reqFac?.startsWith('Z') || provFac?.startsWith('Z')) {
                $('#org_vatcscc').prop('checked', true);
            }
        }
    }
    
    function getSmartDefaultTimes() {
        const now = new Date();
        const minutes = now.getUTCMinutes();
        
        // Snap to next quarter hour boundary (:14, :29, :44, :59)
        let snapMinutes;
        if (minutes < 15) snapMinutes = 14;
        else if (minutes < 30) snapMinutes = 29;
        else if (minutes < 45) snapMinutes = 44;
        else snapMinutes = 59;
        
        // Calculate start time (snapped)
        let startDate = new Date(now);
        if (minutes > snapMinutes) {
            // Move to next hour
            startDate.setUTCHours(startDate.getUTCHours() + 1);
        }
        startDate.setUTCMinutes(snapMinutes);
        startDate.setUTCSeconds(0);
        startDate.setUTCMilliseconds(0);
        
        // End time is 4 hours later
        let endDate = new Date(startDate);
        endDate.setUTCHours(endDate.getUTCHours() + 4);
        
        // Format as datetime-local (YYYY-MM-DDTHH:MM)
        const formatDateTimeLocal = (d) => {
            const year = d.getUTCFullYear();
            const month = String(d.getUTCMonth() + 1).padStart(2, '0');
            const day = String(d.getUTCDate()).padStart(2, '0');
            const hours = String(d.getUTCHours()).padStart(2, '0');
            const mins = String(d.getUTCMinutes()).padStart(2, '0');
            return `${year}-${month}-${day}T${hours}:${mins}`;
        };
        
        return {
            start: formatDateTimeLocal(startDate),
            end: formatDateTimeLocal(endDate),
            // Also provide time-only for backwards compatibility
            startTime: `${String(startDate.getUTCHours()).padStart(2, '0')}:${String(startDate.getUTCMinutes()).padStart(2, '0')}`,
            endTime: `${String(endDate.getUTCHours()).padStart(2, '0')}:${String(endDate.getUTCMinutes()).padStart(2, '0')}`
        };
    }
    
    function formatValidTime(from, until) {
        // Handle datetime-local format (YYYY-MM-DDTHH:MM) or time format (HH:MM)
        const extractTime = (val) => {
            if (!val) return '0000';
            // If datetime-local format, extract time part
            if (val.includes('T')) {
                const timePart = val.split('T')[1] || '00:00';
                return timePart.replace(':', '');
            }
            // If time format, just remove colon
            return val.replace(':', '') || '0000';
        };
        
        const fromStr = extractTime(from);
        const untilStr = extractTime(until);
        return `${fromStr}-${untilStr}`;
    }
    
    function formatValidDateTime(from, until) {
        // Returns formatted date/time for display: "01/28 1400-1800Z"
        const extractDateTime = (val) => {
            if (!val) return { date: '', time: '0000' };
            if (val.includes('T')) {
                const [datePart, timePart] = val.split('T');
                const [year, month, day] = datePart.split('-');
                return {
                    date: `${month}/${day}`,
                    time: (timePart || '00:00').replace(':', '')
                };
            }
            return { date: '', time: val.replace(':', '') || '0000' };
        };
        
        const fromDt = extractDateTime(from);
        const untilDt = extractDateTime(until);
        
        // If dates are same or no dates, just show time range
        if (!fromDt.date || fromDt.date === untilDt.date) {
            return `${fromDt.time}-${untilDt.time}Z`;
        }
        
        // If dates differ, show full range
        return `${fromDt.date} ${fromDt.time}-${untilDt.date} ${untilDt.time}Z`;
    }
    
    function getCurrentTimeHHMM() {
        const now = new Date();
        return String(now.getUTCHours()).padStart(2, '0') + ':' + String(now.getUTCMinutes()).padStart(2, '0');
    }
    
    function getUtcDateString() {
        const now = new Date();
        return now.toISOString().substr(0, 10);
    }
    
    function getUtcDateFormatted() {
        const now = new Date();
        return `${String(now.getUTCMonth() + 1).padStart(2, '0')}/${String(now.getUTCDate()).padStart(2, '0')}/${now.getUTCFullYear()}`;
    }
    
    function getUtcDateTimeFormatted() {
        const now = new Date();
        const date = `${String(now.getUTCMonth() + 1).padStart(2, '0')}/${String(now.getUTCDate()).padStart(2, '0')}/${now.getUTCFullYear()}`;
        const time = `${String(now.getUTCHours()).padStart(2, '0')}${String(now.getUTCMinutes()).padStart(2, '0')}Z`;
        return `${date} ${time}`;
    }
    
    function getCurrentDateDDHHMM() {
        const now = new Date();
        return String(now.getUTCDate()).padStart(2, '0') + '/' + 
               String(now.getUTCHours()).padStart(2, '0') + 
               String(now.getUTCMinutes()).padStart(2, '0');
    }
    
    function generateId() {
        return 'entry_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
    }
    
    function escapeHtml(text) {
        if (text === null || text === undefined) return '';
        const str = String(text);
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
    
    // ===========================================
    // Text Formatting Utilities (FAA 68-char standard)
    // ===========================================
    
    const TEXT_FORMAT = {
        LINE_WIDTH: 68,
        SEPARATOR: '____________________________________________________________________', // 68 underscores
        INDENT: '    ' // 4 spaces for continuation
    };
    
    /**
     * Wrap text to 68 characters with word boundaries
     * @param {string} text - Text to wrap
     * @param {number} width - Max line width (default 68)
     * @param {string} indent - Indent for continuation lines (default none)
     * @returns {string} Wrapped text
     */
    function wrapText(text, width = TEXT_FORMAT.LINE_WIDTH, indent = '') {
        if (!text) return '';
        
        const lines = [];
        const paragraphs = text.split('\n');
        
        paragraphs.forEach((paragraph, pIndex) => {
            if (paragraph.trim() === '') {
                lines.push('');
                return;
            }
            
            const words = paragraph.split(/\s+/);
            let currentLine = '';
            const lineIndent = pIndex > 0 || lines.length > 0 ? indent : '';
            
            words.forEach(word => {
                const testLine = currentLine ? `${currentLine} ${word}` : `${lineIndent}${word}`;
                
                if (testLine.length <= width) {
                    currentLine = testLine;
                } else {
                    if (currentLine) {
                        lines.push(currentLine);
                    }
                    // Start new line with indent
                    currentLine = `${indent}${word}`;
                }
            });
            
            if (currentLine) {
                lines.push(currentLine);
            }
        });
        
        return lines.join('\n');
    }
    
    /**
     * Wrap text with hanging indent (first line flush, continuation indented)
     * @param {string} label - Label prefix (e.g., "REMARKS: ")
     * @param {string} text - Text content
     * @param {number} width - Max line width
     * @returns {string} Formatted text with hanging indent
     */
    function wrapWithLabel(label, text, width = TEXT_FORMAT.LINE_WIDTH) {
        if (!text) return '';
        
        const labelLen = label.length;
        const indent = ' '.repeat(labelLen);
        const firstLineWidth = width;
        const contLineWidth = width;
        
        const words = text.split(/\s+/);
        const lines = [];
        let currentLine = label;
        let isFirstLine = true;
        
        words.forEach(word => {
            const maxWidth = isFirstLine ? firstLineWidth : contLineWidth;
            const testLine = currentLine + (currentLine.endsWith(label) ? '' : ' ') + word;
            
            if (testLine.length <= maxWidth) {
                currentLine = testLine;
            } else {
                lines.push(currentLine);
                currentLine = indent + word;
                isFirstLine = false;
            }
        });
        
        if (currentLine.trim()) {
            lines.push(currentLine);
        }
        
        return lines.join('\n');
    }
    
    /**
     * Format a section with separator lines
     * @param {string} content - Section content
     * @returns {string} Content wrapped with separator lines
     */
    function formatSection(content) {
        return `${TEXT_FORMAT.SEPARATOR}\n${content}\n${TEXT_FORMAT.SEPARATOR}`;
    }
    
    /**
     * Format column-aligned data (for route tables, etc.)
     * @param {Array<Array<string>>} rows - Array of row arrays
     * @param {Array<number>} colWidths - Width for each column
     * @returns {string} Column-aligned text
     */
    function formatColumns(rows, colWidths) {
        if (!rows || rows.length === 0) return '';
        
        return rows.map(row => {
            return row.map((cell, i) => {
                const width = colWidths[i] || 10;
                return String(cell || '').padEnd(width);
            }).join('');
        }).join('\n');
    }
    
    function saveState() {
        try {
            localStorage.setItem('tmi_publisher_queue', JSON.stringify(state.queue || []));
            localStorage.setItem('tmi_publisher_mode', state.productionMode ? '1' : '0');
        } catch (e) {
            console.warn('Failed to save state:', e);
        }
    }
    
    function loadSavedState() {
        try {
            const savedQueue = localStorage.getItem('tmi_publisher_queue');
            if (savedQueue) {
                const parsed = JSON.parse(savedQueue);
                // Validate entries have required fields
                state.queue = (parsed || []).filter(e => e && typeof e === 'object' && (e.type || e.entryType));
            } else {
                state.queue = [];
            }
            
            const savedMode = localStorage.getItem('tmi_publisher_mode');
            if (savedMode === '1') {
                state.productionMode = true;
                $('#productionMode').prop('checked', true);
            }
        } catch (e) {
            console.warn('Failed to load state:', e);
            state.queue = [];
        }
    }
    
    function copyAdvisoryToClipboard() {
        const text = $('#adv_preview').text();
        navigator.clipboard.writeText(text).then(() => {
            Swal.fire({
                icon: 'success',
                title: 'Copied!',
                timer: 1000,
                showConfirmButton: false
            });
        });
    }
    
    // ===========================================
    // User Profile Management
    // ===========================================
    
    function initUserProfile() {
        // Load saved profile from localStorage
        const savedProfile = localStorage.getItem('tmi_user_profile');
        if (savedProfile) {
            try {
                const profile = JSON.parse(savedProfile);
                if (profile.oi) CONFIG.userOI = profile.oi;
                if (profile.facility) CONFIG.userFacility = profile.facility;
            } catch (e) {
                console.warn('Failed to load user profile:', e);
            }
        }
        
        // Check if profile needs to be set (first visit)
        if (!CONFIG.userFacility && !localStorage.getItem('tmi_profile_dismissed')) {
            // Show profile modal after a short delay
            setTimeout(() => {
                showProfileModal();
            }, 1000);
        }
    }
    
    function showProfileModal() {
        // Pre-populate fields
        if (CONFIG.userOI) {
            $('#profileOI').val(CONFIG.userOI);
        }
        if (CONFIG.userFacility) {
            $('#profileFacility').val(CONFIG.userFacility);
        }
        $('#userProfileModal').modal('show');
    }
    
    function saveProfile() {
        const oi = ($('#profileOI').val() || '').trim().toUpperCase();
        const facility = $('#profileFacility').val() || '';
        
        // Validate
        if (oi && (oi.length < 2 || oi.length > 3)) {
            Swal.fire('Invalid OI', 'Operating initials must be 2-3 characters', 'warning');
            return;
        }
        
        // Save to localStorage
        const profile = { oi, facility };
        localStorage.setItem('tmi_user_profile', JSON.stringify(profile));
        
        // Update CONFIG
        if (oi) CONFIG.userOI = oi;
        if (facility) CONFIG.userFacility = facility;
        
        // Close modal
        $('#userProfileModal').modal('hide');
        
        // Update any open forms with new facility as default
        if (facility && $('#ntml_req_facility').length && !$('#ntml_req_facility').val()) {
            $('#ntml_req_facility').val(facility);
        }
        
        Swal.fire({
            icon: 'success',
            title: 'Profile Saved',
            text: 'Your settings have been saved.',
            timer: 1500,
            showConfirmButton: false
        });
    }
    
    function getUserFacility() {
        return CONFIG.userFacility || '';
    }
    
    function getUserOI() {
        // Use configured OI if available
        if (CONFIG.userOI && CONFIG.userOI.length >= 2) {
            return CONFIG.userOI.toUpperCase();
        }
        
        // Extract initials from userName (first letter of each word)
        const userName = CONFIG.userName || 'XX';
        const parts = userName.trim().split(/\s+/);
        if (parts.length >= 2) {
            // First letter of first and last word
            return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
        }
        // Single word - use first 2 characters
        return userName.substr(0, 2).toUpperCase();
    }
    
    // ===========================================
    // Public API
    // ===========================================
    
    window.TMIPublisher = {
        removeFromQueue: removeFromQueue,
        previewEntry: previewEntry,
        clearQueue: clearQueue,
        addNtmlToQueue: addNtmlToQueue,
        resetNtmlForm: resetNtmlForm,
        promoteEntry: promoteEntry,
        loadStagedEntries: loadStagedEntries,
        viewTmiDetails: viewTmiDetails,
        cancelTmi: cancelTmi,
        updateCauseOptions: updateCauseOptions,
        saveProfile: saveProfile,
        showProfileModal: showProfileModal
    };
    
})();
