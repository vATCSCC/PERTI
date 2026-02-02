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
 * @version 1.9.2
 * @date 2026-01-29
 *
 * v1.9.2 Changes:
 *   - Added individual submit button to each queue item (Coord/Pub)
 *   - Per-entry facility verification when submitting for coordination
 *   - Auto-detect facilities based on entry's ctl_element and prov_facility
 *   - Stepped wizard dialog for batch coordination (verify each entry)
 *   - Entries not requiring coordination can be published without blocking
 *
 * v1.9.1 Changes:
 *   - Qualifier buttons now support multi-select (multiple in same group)
 *   - Qualifier buttons can now be unselected by clicking again
 *   - Removed single-select-per-group restriction
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
        crossBorderAutoDetect: true,
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
        'MMUN': 'Cancun ACC', 'MMMD': 'Merida ACC',
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
            { code: 'EACH', label: 'EACH', desc: 'Each aircraft separately' },
        ],
        // Aircraft Type
        aircraft: [
            { code: 'JET', label: 'JET', desc: 'Jet aircraft only' },
            { code: 'PROP', label: 'PROP', desc: 'Propeller aircraft only' },
            { code: 'TURBOJET', label: 'TURBOJET', desc: 'Turbojet aircraft only' },
            { code: 'B757', label: 'B757', desc: 'B757 aircraft only' },
        ],
        // Weight Class
        weight: [
            { code: 'HEAVY', label: 'HEAVY', desc: 'Heavy aircraft (>255,000 lbs)' },
            { code: 'LARGE', label: 'LARGE', desc: 'Large aircraft (41,000-255,000 lbs)' },
            { code: 'SMALL', label: 'SMALL', desc: 'Small aircraft (<41,000 lbs)' },
            { code: 'SUPER', label: 'SUPER', desc: 'Superheavy aircraft (A380, AN-225)' },
        ],
        // Equipment/Capability
        equipment: [
            { code: 'RNAV', label: 'RNAV', desc: 'RNAV-equipped aircraft only' },
            { code: 'NON-RNAV', label: 'NON-RNAV', desc: 'Non-RNAV aircraft only' },
            { code: 'RNP', label: 'RNP', desc: 'RNP-capable aircraft only' },
            { code: 'RVSM', label: 'RVSM', desc: 'RVSM-compliant only' },
            { code: 'NON-RVSM', label: 'NON-RVSM', desc: 'Non-RVSM aircraft only' },
        ],
        // Flow Type
        flow: [
            { code: 'ARR', label: 'ARR', desc: 'Arrival traffic only' },
            { code: 'DEP', label: 'DEP', desc: 'Departure traffic only' },
            { code: 'OVFLT', label: 'OVFLT', desc: 'Overflight traffic only' },
        ],
        // Operator Category
        operator: [
            { code: 'AIR CARRIER', label: 'AIR CARRIER', desc: 'Air carrier operations' },
            { code: 'AIR TAXI', label: 'AIR TAXI', desc: 'Air taxi operations' },
            { code: 'GA', label: 'GA', desc: 'General aviation' },
            { code: 'CARGO', label: 'CARGO', desc: 'Cargo operations' },
            { code: 'MIL', label: 'MIL', desc: 'Military operations' },
        ],
        // Altitude
        altitude: [
            { code: 'HIGH ALT', label: 'HIGH ALT', desc: 'FL240 and above' },
            { code: 'LOW ALT', label: 'LOW ALT', desc: 'Below FL240' },
        ],
    };

    // Reason codes - Category (broad) per OPSNET
    const REASON_CATEGORIES = [
        { code: 'VOLUME', label: 'Volume' },
        { code: 'WEATHER', label: 'Weather' },
        { code: 'RUNWAY', label: 'Runway' },
        { code: 'EQUIPMENT', label: 'Equipment' },
        { code: 'OTHER', label: 'Other' },
    ];

    // Cause codes - Specific causes per OPSNET/ASPM
    const REASON_CAUSES = {
        VOLUME: [
            { code: 'VOLUME', label: 'Volume' },
            { code: 'COMPACTED DEMAND', label: 'Compacted Demand' },
            { code: 'MULTI-TAXI', label: 'Multi-Taxi' },
            { code: 'AIRSPACE', label: 'Airspace' },
        ],
        WEATHER: [
            { code: 'WEATHER', label: 'Weather' },
            { code: 'THUNDERSTORMS', label: 'Thunderstorms' },
            { code: 'LOW CEILINGS', label: 'Low Ceilings' },
            { code: 'LOW VISIBILITY', label: 'Low Visibility' },
            { code: 'FOG', label: 'Fog' },
            { code: 'WIND', label: 'Wind' },
            { code: 'SNOW/ICE', label: 'Snow/Ice' },
        ],
        RUNWAY: [
            { code: 'RUNWAY', label: 'Runway' },
            { code: 'RUNWAY CONFIGURATION', label: 'Runway Configuration' },
            { code: 'RUNWAY CONSTRUCTION', label: 'Runway Construction' },
            { code: 'RUNWAY CLOSURE', label: 'Runway Closure' },
        ],
        EQUIPMENT: [
            { code: 'EQUIPMENT', label: 'Equipment' },
            { code: 'FAA EQUIPMENT', label: 'FAA Equipment' },
            { code: 'NON-FAA EQUIPMENT', label: 'Non-FAA Equipment' },
        ],
        OTHER: [
            { code: 'OTHER', label: 'Other' },
            { code: 'STAFFING', label: 'Staffing' },
            { code: 'AIR SHOW', label: 'Air Show' },
            { code: 'VIP MOVEMENT', label: 'VIP Movement' },
            { code: 'SPECIAL EVENT', label: 'Special Event' },
            { code: 'SECURITY', label: 'Security' },
        ],
    };

    // ===========================================
    // State
    // ===========================================

    const state = {
        queue: [],
        productionMode: false,
        selectedNtmlType: null,
        selectedAdvisoryType: null,
        lastCrossBorderOrgs: [],
        advisoryCounters: {}, // Track advisory numbers by date
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
                PERTIDialog.show({
                    titleKey: 'tmiPublish.enableProdMode.title',
                    htmlKey: 'tmiPublish.enableProdMode.html',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#dc3545',
                    confirmKey: 'tmiPublish.enableProdMode.confirm',
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
                            data.swap || 1,
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
            0, 0, 0,
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
        `<button type="button" class="btn btn-outline-secondary btn-sm qualifier-btn mr-1 mb-1" data-qualifier="${q.code}" title="${q.desc}">${q.label}</button>`,
    ).join('')}
                            ${NTML_QUALIFIERS.weight.map(q =>
        `<button type="button" class="btn btn-outline-secondary btn-sm qualifier-btn mr-1 mb-1" data-qualifier="${q.code}" title="${q.desc}">${q.label}</button>`,
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
                        <div class="col-md-3">
                            <label class="form-label small text-muted">Impacting Condition</label>
                            <select class="form-control" id="ntml_reason_category" onchange="TMIPublisher.updateCauseOptions()">
                                ${REASON_CATEGORIES.map(r => `<option value="${r.code}">${r.label}</option>`).join('')}
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-muted">Specific Impact</label>
                            <select class="form-control" id="ntml_reason_cause">
                                ${REASON_CAUSES.VOLUME.map(c => `<option value="${c.code}">${c.label}</option>`).join('')}
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-muted">Report Time (UTC)</label>
                            <input type="time" class="form-control" id="ntml_report_time" value="${currentTime}">
                        </div>
                        <div class="col-md-3">
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
        const times = getSmartDefaultTimes();

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

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Valid From (UTC)</label>
                            <input type="datetime-local" class="form-control" id="ntml_valid_from" value="${times.start}">
                            <small class="text-muted">When this config becomes active</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Valid Until (UTC)</label>
                            <input type="datetime-local" class="form-control" id="ntml_valid_until" value="${times.end}">
                            <small class="text-muted">When this config expires</small>
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

        // Qualifier toggle buttons - multi-select allowed within groups, click to toggle
        $('.qualifier-btn').on('click', function() {
            // Toggle this button's selected state (no group exclusivity)
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
            },
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

        if (aar) {$('#ntml_aar').val(aar);}
        if (adr) {$('#ntml_adr').val(adr);}
    }

    // ===========================================
    // Airport Code Lookup (FAA ↔ ICAO)
    // ===========================================

    const icaoLookupCache = {}; // Cache lookups to reduce API calls

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
            },
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
            'East Coast', 'West Coast', 'Canada East', 'Canada West', 'Mexico', 'Caribbean',
        ];

        // Participation options
        const participationOptions = [
            'MANDATORY', 'EXPECTED', 'STRONGLY ENCOURAGED', 'STRONGLY RECOMMENDED',
            'ENCOURAGED', 'RECOMMENDED', 'OPTIONAL',
        ];

        // Hotline address options
        const hotlineAddresses = [
            { value: 'ts.vatusa.net', label: 'VATUSA TeamSpeak (ts.vatusa.net)' },
            { value: 'ts.vatcan.ca', label: 'VATCAN TeamSpeak (ts.vatcan.ca)' },
            { value: 'discord', label: 'vATCSCC Discord, Hotline Backup voice channel' },
        ];

        // Build hotline name options
        const hotlineNameOptions = hotlineNames.map(name =>
            `<option value="${name}">${name}</option>`,
        ).join('');

        // Build participation options
        const participationOpts = participationOptions.map(opt =>
            `<option value="${opt}">${opt}</option>`,
        ).join('');

        // Build address options
        const addressOptions = hotlineAddresses.map(addr =>
            `<option value="${addr.value}">${addr.label}</option>`,
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
            { code: 'CARIBBEAN', name: 'Caribbean' },
        ];

        return facilities.map(f =>
            `<option value="${f.code}">${f.code} - ${f.name}</option>`,
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
            // Handle action change (ACTIVATION, UPDATE, TERMINATION)
            $('#adv_hotline_action').on('change', function() {
                const action = $(this).val();
                if (action === 'UPDATE' || action === 'TERMINATION') {
                    // Show picker for existing hotline advisories
                    showHotlineAdvisoryPicker(action);
                }
                updateAdvisoryPreview();
            });

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
            },
        });
    }

    function showImportDialog(planOptions) {
        const hintText = typeof PERTII18n !== 'undefined'
            ? PERTII18n.t('tmiPublish.importPlan.selectPlanHint')
            : 'Select a PERTI Plan to import TMI data into the Operations Plan advisory.';
        const labelText = typeof PERTII18n !== 'undefined'
            ? PERTII18n.t('tmiPublish.importPlan.selectPlanLabel')
            : 'Select Plan';
        const validationMsg = typeof PERTII18n !== 'undefined'
            ? PERTII18n.t('tmiPublish.importPlan.pleaseSelectPlan')
            : 'Please select a plan';

        PERTIDialog.show({
            titleKey: 'tmiPublish.importPlan.title',
            html: `
                <div class="text-left">
                    <p class="small text-muted mb-3">${hintText}</p>
                    <div class="form-group">
                        <label class="small font-weight-bold">${labelText}</label>
                        <select id="importPlanId" class="form-control">${planOptions}</select>
                    </div>
                </div>
            `,
            width: 450,
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-download"></i> Import',
            confirmButtonColor: '#007bff',
            cancelKey: 'common.cancel',
            preConfirm: () => {
                const planId = document.getElementById('importPlanId').value;
                if (!planId) {
                    Swal.showValidationMessage(validationMsg);
                    return false;
                }
                return { type: 'id', value: planId };
            },
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
        PERTIDialog.loading('dialog.loading');

        // Build request params based on type
        const params = {};
        if (type === 'id') {params.id = value;}
        else if (type === 'date') {params.date = value;}
        else if (type === 'event') {params.event = value;}

        $.ajax({
            url: 'api/mgt/plan/get.php',
            method: 'GET',
            data: params,
            dataType: 'json',
            success: function(response) {
                PERTIDialog.close();

                // Handle multiple results from event search
                if (response.success && response.plans && response.plans.length > 1) {
                    // Show plan selection dialog
                    let options = '';
                    response.plans.forEach(function(plan) {
                        options += `<option value="${plan.id}">${plan.eventName} (${plan.eventDate})</option>`;
                    });
                    PERTIDialog.show({
                        titleKey: 'tmiPublish.importPlan.multiplePlans',
                        html: `<select id="selectPlan" class="form-control">${options}</select>`,
                        confirmKey: 'common.select',
                        showCancelButton: true,
                        preConfirm: () => document.getElementById('selectPlan').value,
                    }).then((result) => {
                        if (result.isConfirmed) {
                            fetchPertiPlanData('id', result.value);
                        }
                    });
                    return;
                }

                if (response.success && response.plan) {
                    populateOpsPlanFromPerti(response.plan);
                    PERTIDialog.success('tmiPublish.importPlan.planImported', 'tmiPublish.importPlan.planImportedText');
                } else if (response.success && !response.plan) {
                    PERTIDialog.info('tmiPublish.importPlan.noPlanFound', 'tmiPublish.importPlan.noPlanFoundText');
                } else {
                    PERTIDialog.error('tmiPublish.importPlan.importFailed', null, {}, {
                        text: response.error || PERTII18n.t('tmiPublish.importPlan.importFailedText'),
                    });
                }
            },
            error: function(xhr, status, error) {
                PERTIDialog.close();
                PERTIDialog.error('tmiPublish.importPlan.importFailed', 'error.networkError', { message: error });
            },
        });
    }

    /**
     * Populate Ops Plan form fields from PERTI Plan data
     */
    function populateOpsPlanFromPerti(plan) {
        console.log('[TMI-Publish] Populating from plan:', plan);
        console.log('[TMI-Publish] Debug info from API:', plan._debug);
        console.log('[TMI-Publish] Available fields:', {
            initiativesSummary: plan.initiativesSummary,
            constraintsSummary: plan.constraintsSummary,
            eventsSummary: plan.eventsSummary,
            tmisCount: plan.tmis ? plan.tmis.length : 0,
            eventsCount: plan.events ? plan.events.length : 0,
        });

        // Check if form fields exist
        const fieldsExist = {
            initiatives: $('#adv_initiatives').length > 0,
            weather: $('#adv_weather').length > 0,
            events: $('#adv_events').length > 0,
        };
        console.log('[TMI-Publish] Form fields exist:', fieldsExist);

        // Use pre-built initiative summary from API, or build from TMIs
        if (plan.initiativesSummary) {
            console.log('[TMI-Publish] Setting initiatives from initiativesSummary');
            $('#adv_initiatives').val(plan.initiativesSummary);
        } else if (plan.tmis && plan.tmis.length > 0) {
            // Fallback: build from TMIs array
            console.log('[TMI-Publish] Building initiatives from TMIs array');
            const initiatives = plan.tmis.map(tmi => {
                if (tmi.type === 'terminal') {
                    return `${tmi.airport || ''}${tmi.context ? ' - ' + tmi.context : ''}`;
                } else {
                    return `${tmi.element || 'Enroute'}${tmi.context ? ' - ' + tmi.context : ''}`;
                }
            }).filter(i => i.trim());
            $('#adv_initiatives').val(initiatives.join('\n'));
        } else {
            console.log('[TMI-Publish] No initiatives data available');
        }

        // Use pre-built constraints summary from API
        if (plan.constraintsSummary) {
            console.log('[TMI-Publish] Setting constraints from constraintsSummary');
            $('#adv_weather').val(plan.constraintsSummary);
        } else if (plan.weather) {
            // Fallback: weather might be array or string
            console.log('[TMI-Publish] Building constraints from weather data');
            const weatherStr = Array.isArray(plan.weather) ? plan.weather.join('\n') : plan.weather;
            $('#adv_weather').val(weatherStr);
        } else {
            console.log('[TMI-Publish] No constraints data available');
        }

        // Use pre-built events summary from API, or build from events array
        if (plan.eventsSummary) {
            console.log('[TMI-Publish] Setting events from eventsSummary');
            $('#adv_events').val(plan.eventsSummary);
        } else if (plan.events && plan.events.length > 0) {
            // Fallback: build from events array
            console.log('[TMI-Publish] Building events from events array');
            const events = plan.events.map(ev => {
                let line = ev.title || '';
                if (ev.description) {line += ': ' + ev.description;}
                if (ev.time) {line += ' (' + ev.time + ')';}
                return line;
            }).filter(e => e.trim());
            $('#adv_events').val(events.join('\n'));
        } else {
            console.log('[TMI-Publish] No events data available');
        }

        // Update validity times if plan has them
        if (plan.validFrom) {
            try {
                // Handle various date formats
                let fromStr = plan.validFrom;
                if (!fromStr.includes('T')) {fromStr = fromStr.replace(' ', 'T');}
                $('#adv_valid_from').val(fromStr.slice(0, 16));
            } catch (e) {
                console.warn('[TMI-Publish] Could not parse validFrom:', plan.validFrom);
            }
        }
        if (plan.validUntil) {
            try {
                let untilStr = plan.validUntil;
                if (!untilStr.includes('T')) {untilStr = untilStr.replace(' ', 'T');}
                $('#adv_valid_until').val(untilStr.slice(0, 16));
            } catch (e) {
                console.warn('[TMI-Publish] Could not parse validUntil:', plan.validUntil);
            }
        }

        // Trigger preview update
        updateAdvisoryPreview();

        // Log final field values
        console.log('[TMI-Publish] Final field values:', {
            initiatives: $('#adv_initiatives').val(),
            weather: $('#adv_weather').val(),
            events: $('#adv_events').val(),
        });
    }

    /**
     * Show picker for active hotline advisories (for UPDATE/TERMINATION)
     */
    function showHotlineAdvisoryPicker(action) {
        // Fetch active hotline advisories from the API
        $.ajax({
            url: 'api/mgt/tmi/active.php',
            method: 'GET',
            data: { type: 'advisories', source: 'ALL' },
            dataType: 'json',
            success: function(response) {
                if (!response.success) {
                    console.error('[TMI-Publish] Failed to fetch active advisories');
                    return;
                }

                // Filter for active HOTLINE advisories
                // Exclude TERMINATION advisories (they can't be terminated again)
                // Only show ACTIVATION advisories that can be updated/terminated
                const activeHotlines = (response.data?.active || []).filter(item =>
                    item.entityType === 'ADVISORY' &&
                    item.entryType === 'HOTLINE' &&
                    item.status === 'ACTIVE' &&
                    // Exclude termination advisories (subject contains "TERMINATION")
                    !(item.subject && item.subject.toUpperCase().includes('TERMINATION')),
                );

                if (activeHotlines.length === 0) {
                    PERTIDialog.info(
                        'tmiPublish.noActiveHotlines',
                        'tmiPublish.hotline.noActiveText',
                        { action: action.toLowerCase() },
                    ).then(() => {
                        // Reset to ACTIVATION
                        $('#adv_hotline_action').val('ACTIVATION');
                    });
                    return;
                }

                // Build options for picker
                const options = activeHotlines.map(h => {
                    const hotlineName = h.subject || h.ctlElement || 'Unknown';
                    const advNum = h.advisoryNumber || '???';
                    return `<option value="${h.entityId}"
                        data-name="${escapeAttr(h.subject || '')}"
                        data-facilities="${escapeAttr(h.scopeFacilities || '')}"
                        data-reason="${escapeAttr(h.reasonCode || '')}"
                        data-impacted="${escapeAttr(h.reasonDetail || '')}"
                        data-valid-from="${h.validFrom || ''}"
                        data-valid-until="${h.validUntil || ''}"
                        data-body="${escapeAttr(h.bodyText || h.rawText || '')}"
                    >ADVZY ${advNum} - ${hotlineName}</option>`;
                }).join('');

                const selectTitle = typeof PERTII18n !== 'undefined'
                    ? PERTII18n.t('tmiPublish.hotline.selectToAction', { action })
                    : `Select Hotline to ${action}`;
                const selectHint = typeof PERTII18n !== 'undefined'
                    ? PERTII18n.t('tmiPublish.hotline.selectHint', { action: action.toLowerCase() })
                    : `Select the active hotline advisory you want to ${action.toLowerCase()}:`;
                const selectPlaceholder = typeof PERTII18n !== 'undefined'
                    ? PERTII18n.t('tmiPublish.hotline.selectPlaceholder')
                    : '-- Select a hotline --';
                const validationMsg = typeof PERTII18n !== 'undefined'
                    ? PERTII18n.t('tmiPublish.hotline.pleaseSelect')
                    : 'Please select a hotline';

                PERTIDialog.show({
                    title: `<i class="fas fa-phone-alt text-danger"></i> ${selectTitle}`,
                    html: `
                        <div class="text-left">
                            <p class="small text-muted mb-3">${selectHint}</p>
                            <select id="hotlinePickerSelect" class="form-control">
                                <option value="">${selectPlaceholder}</option>
                                ${options}
                            </select>
                        </div>
                    `,
                    width: 500,
                    showCancelButton: true,
                    confirmButtonText: '<i class="fas fa-check"></i> Select',
                    confirmButtonColor: '#dc3545',
                    cancelKey: 'common.cancel',
                    preConfirm: () => {
                        const select = document.getElementById('hotlinePickerSelect');
                        const selectedOption = select.options[select.selectedIndex];
                        if (!select.value) {
                            Swal.showValidationMessage(validationMsg);
                            return false;
                        }
                        return {
                            id: select.value,
                            name: selectedOption.dataset.name,
                            facilities: selectedOption.dataset.facilities,
                            reason: selectedOption.dataset.reason,
                            impacted: selectedOption.dataset.impacted,
                            validFrom: selectedOption.dataset.validFrom,
                            validUntil: selectedOption.dataset.validUntil,
                            body: selectedOption.dataset.body,
                        };
                    },
                }).then((result) => {
                    if (result.isConfirmed) {
                        populateHotlineFromExisting(result.value, action);
                    } else {
                        // Reset to ACTIVATION if cancelled
                        $('#adv_hotline_action').val('ACTIVATION');
                    }
                });
            },
            error: function() {
                PERTIDialog.error('common.error', 'error.fetchFailed', { resource: 'advisories' });
                $('#adv_hotline_action').val('ACTIVATION');
            },
        });
    }

    /**
     * Populate hotline form from existing advisory data
     */
    function populateHotlineFromExisting(data, action) {
        console.log('[TMI-Publish] Populating hotline form from:', data);

        // Store reference to original advisory
        $('#advisoryFormContainer').data('reference-id', data.id);

        // Set hotline name if it matches one of our options
        const hotlineNameSelect = $('#adv_hotline_name');
        const nameMatch = hotlineNameSelect.find('option').filter(function() {
            return data.name && data.name.toLowerCase().includes($(this).val().toLowerCase());
        }).first();
        if (nameMatch.length) {
            hotlineNameSelect.val(nameMatch.val());
        }

        // Parse facilities from comma-separated string
        if (data.facilities) {
            const facilities = data.facilities.split(',').map(f => f.trim());
            $('#adv_facilities').val(facilities.join(', '));
            // Also try to select in the multi-select
            $('#adv_attend_select option').each(function() {
                $(this).prop('selected', facilities.includes($(this).val()));
            });
        }

        // Set reason
        if (data.reason) {
            $('#adv_reason').val(data.reason);
        }

        // Set impacted area from reason detail
        if (data.impacted) {
            $('#adv_impacted_area').val(data.impacted);
        }

        // For TERMINATION, set end time to now
        if (action === 'TERMINATION') {
            const now = new Date();
            $('#adv_end_datetime').val(now.toISOString().slice(0, 16));
            // Keep original start time
            if (data.validFrom) {
                $('#adv_start_datetime').val(data.validFrom.slice(0, 16));
            }
        } else if (action === 'UPDATE') {
            // For UPDATE, keep original times but allow modification
            if (data.validFrom) {
                $('#adv_start_datetime').val(data.validFrom.slice(0, 16));
            }
            if (data.validUntil) {
                $('#adv_end_datetime').val(data.validUntil.slice(0, 16));
            }
        }

        // Add note referencing original advisory
        const note = action === 'TERMINATION'
            ? 'This advisory terminates the referenced hotline activation.'
            : 'This advisory updates the referenced hotline activation.';
        $('#adv_notes').val(note);

        // Trigger address mapping and preview update
        $('#adv_hotline_name').trigger('change');
        updateAdvisoryPreview();

        const instruction = action === 'TERMINATION' ? 'confirm the termination time' : 'make your updates';
        PERTIDialog.success(
            'tmiPublish.fieldsAutoFilled',
            'tmiPublish.hotline.autoFilledText',
            { instruction },
            { timer: 3000 },
        );
    }

    function escapeAttr(str) {
        if (!str) {return '';}
        return str.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
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
            if (!dt) {return 'TBD';}
            const d = new Date(dt + 'Z'); // Treat as UTC
            const day = String(d.getUTCDate()).padStart(2, '0');
            const hour = String(d.getUTCHours()).padStart(2, '0');
            const min = String(d.getUTCMinutes()).padStart(2, '0');
            return `${day}${hour}${min}`;
        };

        const startFormatted = formatDateTime(validFrom);
        const endFormatted = formatDateTime(validUntil);

        const lines = [
            buildAdvisoryHeader(num, facility, 'OPERATIONS PLAN'),
            `VALID FOR ${startFormatted}Z THRU ${endFormatted}Z`,
            ``,
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
            if (!dt) {return 'TBD';}
            const d = new Date(dt + 'Z'); // Treat as UTC
            const day = String(d.getUTCDate()).padStart(2, '0');
            const hour = String(d.getUTCHours()).padStart(2, '0');
            const min = String(d.getUTCMinutes()).padStart(2, '0');
            return `${day}/${hour}${min}`;
        };

        const startFormatted = formatDateTime(validFrom);
        const endFormatted = formatDateTime(validUntil);

        const lines = [
            buildAdvisoryHeader(num, facility, subject.toUpperCase()),
            `VALID: ${startFormatted}Z - ${endFormatted}Z`,
            ``,
            text ? wrapText(text) : '(No text entered)',
            ``,
            buildAdvisoryFooter(num, facility),
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
            if (!dt) {return '';}
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
            'discord': 'vATCSCC Discord, Hotline Backup voice channel',
        };
        const hotlineAddress = addressMap[hotlineAddressCode] || hotlineAddressCode;

        const lines = [
            buildAdvisoryHeader(num, 'DCC', `HOTLINE ${action}`),
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

            // Participation info - handle long facility lists specially
            boilerplate += ` PARTICIPATION IS ${participation} FOR`;

            // Wrap the main boilerplate text at 68 characters
            lines.push(wrapText(boilerplate));

            // Add wrapped facility list on separate lines
            lines.push(wrapFacilityList(facilities));

            // Standard closing - wrap separately
            const closing = 'AFFECTED MAJOR UNDERLYING FACILITIES ARE STRONGLY ENCOURAGED TO ATTEND. ALL OTHER PARTICIPANTS ARE WELCOME TO JOIN. PLEASE MESSAGE THE NOM IF YOU HAVE ISSUES OR QUESTIONS.';
            lines.push(wrapText(closing));

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
            // Termination format - matches activation structure
            lines.push(`EVENT TIME: ${startFormatted}Z - ${endFormatted}Z`);

            if (constrainedFacilities) {
                lines.push(`CONSTRAINED FACILITIES: ${constrainedFacilities}`);
            }

            // Termination message uses END time (when hotline is being terminated)
            lines.push(`THE ${hotlineName} IS BEING TERMINATED EFFECTIVE ${endFormatted}Z.`);
        }

        lines.push(``);

        // For termination, use end time for both footer times
        if (action === 'TERMINATION') {
            lines.push(buildTerminationFooter(endDateTime));
        } else {
            lines.push(buildAdvisoryFooter(num, 'DCC'));
        }

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

        const lines = [
            buildAdvisoryHeader(num, 'DCC', `SWAP ${swapType}`),
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

    // Standard advisory footer with optional TMI ID and signature
    // Format (with TMI ID - only for Reroutes):
    //   TMI ID: {OI}.RR{SOURCE}{ADVZY #}
    //   {DDHHMM}-{DDHHMM}
    //   YY/MM/DD HH:MM {OI}
    // Format (without TMI ID - for all other advisories):
    //   {DDHHMM}-{DDHHMM}
    //   YY/MM/DD HH:MM {OI}
    function buildAdvisoryFooter(advNum, facility, includeTmiId = false) {
        const oi = getUserOI();
        const now = new Date();

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

        // Only include TMI ID for Reroutes
        const lines = [];
        if (includeTmiId) {
            const tmiId = buildTmiId(advNum || '001', facility || 'DCC');
            lines.push(`TMI ID: ${tmiId}`);
        }
        lines.push(`${timestamp}-${endTimestamp}`);
        lines.push(signature);

        return lines.join('\n');
    }

    // Build footer for termination advisories (both times = termination time)
    function buildTerminationFooter(terminationDateTime) {
        const oi = getUserOI();
        const now = new Date();

        // If terminationDateTime provided, use it; otherwise use current time
        let termTime;
        if (terminationDateTime) {
            termTime = new Date(terminationDateTime);
        } else {
            termTime = now;
        }

        // Termination timestamp (DDHHMM format)
        const day = String(termTime.getUTCDate()).padStart(2, '0');
        const hour = String(termTime.getUTCHours()).padStart(2, '0');
        const min = String(termTime.getUTCMinutes()).padStart(2, '0');
        const timestamp = `${day}${hour}${min}`;

        // Signature uses current time (when advisory was issued)
        const sigDay = String(now.getUTCDate()).padStart(2, '0');
        const sigHour = String(now.getUTCHours()).padStart(2, '0');
        const sigMin = String(now.getUTCMinutes()).padStart(2, '0');
        const year = String(now.getUTCFullYear()).substr(2, 2);
        const month = String(now.getUTCMonth() + 1).padStart(2, '0');
        const signature = `${year}/${month}/${sigDay} ${sigHour}:${sigMin} ${oi}`;

        // Both times are the termination time
        return `${timestamp}-${timestamp}\n${signature}`;
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
            end: `${endDay}${String(endHour).padStart(2, '0')}00`,
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
            PERTIDialog.warning('validation.missingField', 'validation.enterAirportOrFix');
            return;
        }

        if (type !== 'DELAY' && type !== 'CONFIG' && (!reqFacility || !provFacility)) {
            PERTIDialog.warning('validation.missingFacilities', 'validation.enterFacilities');
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
            valid_until: validUntil,
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
            if (altFilter) {data.altitude_filter = altFilter;}

            const speedFilter = $('#ntml_speed_filter').val();
            if (speedFilter) {data.speed_filter = speedFilter;}
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
                data.valid_from = ($('#ntml_valid_from').val() || '').trim();
                data.valid_until = ($('#ntml_valid_until').val() || '').trim();
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
            timestamp: new Date().toISOString(),
        };

        state.queue.push(entry);
        saveState();
        updateUI();

        // Switch to queue tab
        $('#queue-tab').tab('show');

        PERTIDialog.success('tmiPublish.queue.addedToQueue', null, null, {
            text: `${type} entry added`,
            timer: 1500,
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

        // Get spacing qualifier (first one found) - only if explicitly selected
        let spacing = '';
        if (data.qualifiers && data.qualifiers.length > 0) {
            const spacingQual = data.qualifiers.find(q =>
                ['AS ONE', 'PER STREAM', 'PER AIRPORT', 'PER FIX', 'EACH'].includes(q),
            );
            if (spacingQual) {spacing = spacingQual;}
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
                !['AS ONE', 'PER STREAM', 'PER AIRPORT', 'PER FIX', 'EACH'].includes(q),
            );
            if (nonSpacing.length > 0) {
                otherQuals = ' ' + nonSpacing.join(' ');
            }
        }

        // Format: valid period and req:prov ALWAYS at the end
        // Only include spacing if explicitly selected
        const spacingPart = spacing ? ` ${spacing}` : '';
        const line = `${logTime}    ${element} via ${viaFix} ${value}${type}${spacingPart}${otherQuals} EXCL:${exclusions} ${category}:${cause}${filters} ${validTime} ${reqFac}:${provFac}`;

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

        const line = `${logTime}    STOP ${element}${trafficFlow} via ${viaFix}${qualStr} EXCL:${exclusions} ${category}:${cause}${filters} ${validTime} ${reqFac}:${provFac}`;

        return line;
    }

    function formatApreqMessage(data, logTime, validTime) {
        // Use CFR directly, no expansion needed
        const apreqType = data.apreq_type || 'CFR';
        const category = (data.reason_category || 'VOLUME').toUpperCase();
        const cause = (data.reason_cause || category).toUpperCase();
        const trafficFlow = data.traffic_flow ? ` ${data.traffic_flow.toLowerCase()}` : '';
        let line = `${logTime}    ${apreqType} ${(data.ctl_element || 'N/A').toUpperCase()}${trafficFlow}`;

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
        if (data.delay_trend === 'INC') {sign = '+';}
        if (data.delay_trend === 'DEC') {sign = '-';}

        let line = `${logTime}    ${delayType} ${data.ctl_element || 'N/A'}, ${sign}${data.delay_minutes || '0'}/${reportTime}`;

        if (data.acft_count && parseInt(data.acft_count) > 1) {
            line += `/${data.acft_count} ACFT`;
        }

        line += ` ${data.reason || 'VOLUME'}`;

        return line;
    }

    function formatConfigMessage(data, logTime) {
        // Format: {DD/HHMM}    {airport}    {weather}    ARR:{arr} DEP:{dep}    AAR(Strat):{aar} ADR:{adr} {valid}
        const airport = (data.ctl_element || 'N/A').toUpperCase();
        const weather = (data.weather || 'VMC').toUpperCase();
        const arrRwys = (data.arr_runways || 'N/A').toUpperCase();
        const depRwys = (data.dep_runways || 'N/A').toUpperCase();
        const aar = data.aar || '60';
        const adr = data.adr || '60';
        const aarType = data.aar_type || 'Strat';
        const validTime = formatValidTime(data.valid_from, data.valid_until);

        let line = `${logTime}    ${airport} ${weather} ARR:${arrRwys} DEP:${depRwys} AAR(${aarType}):${aar} ADR:${adr}`;

        if (validTime && validTime !== 'TFN') {
            line += ` ${validTime}`;
        }

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
        if (!type) {return;}

        const preview = $('#adv_preview').text();
        const orgs = getSelectedOrgsAdvisory();

        const entry = {
            id: generateId(),
            type: 'advisory',
            entryType: type,
            data: collectAdvisoryFormData(type),
            preview: preview,
            orgs: orgs,
            timestamp: new Date().toISOString(),
        };

        state.queue.push(entry);
        saveState();
        updateUI();

        $('#queue-tab').tab('show');

        PERTIDialog.success('tmiPublish.queue.addedToQueue', null, null, {
            text: `${type} advisory added`,
            timer: 1500,
        });
    }

    function collectAdvisoryFormData(type) {
        const data = {
            advisory_type: type,
            number: $('#adv_number').val() || '001',
            facility: $('#adv_facility').val() || 'DCC',
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
            if (!entry || typeof entry !== 'object') {return;}

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

            // Check if this entry requires coordination
            const needsCoord = requiresCoordination(entrySubType);
            const submitBtnClass = needsCoord ? 'btn-outline-warning' : 'btn-outline-success';
            const submitBtnTitle = needsCoord ? 'Submit for Coordination' : 'Publish Directly';
            const submitBtnIcon = needsCoord ? 'fa-paper-plane' : 'fa-check';
            const submitBtnText = needsCoord ? 'Coord' : 'Pub';

            html += `
                <div class="queue-item p-3 border-left-4 ${typeClass} mb-2 bg-light rounded">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="flex-grow-1">
                            <span class="badge ${typeBadge} mr-1">${escapeHtml(entryType.toUpperCase())}</span>
                            <span class="badge badge-secondary">${escapeHtml(entrySubType)}</span>
                            ${needsCoord ? '<span class="badge badge-warning ml-1" title="Requires coordination">⚡</span>' : ''}
                            <div class="mt-2 font-monospace small text-dark" style="white-space: pre-wrap; max-height: 100px; overflow-y: auto;">${escapeHtml(displayText)}</div>
                            <div class="mt-1 small text-muted">
                                <i class="fab fa-discord"></i> ${orgs.join(', ')}
                            </div>
                        </div>
                        <div class="ml-2 d-flex flex-column">
                            <button class="btn btn-sm ${submitBtnClass} mb-1" onclick="TMIPublisher.submitSingleEntry(${index})" title="${submitBtnTitle}">
                                <i class="fas ${submitBtnIcon}"></i><span class="ml-1 d-none d-sm-inline">${submitBtnText}</span>
                            </button>
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
        if (!state.queue || state.queue.length === 0) {return;}

        PERTIDialog.confirm(
            'tmiPublish.queue.clearQueue',
            'tmiPublish.queue.clearQueueText',
            { count: state.queue.length },
            { confirmButtonColor: '#dc3545', confirmButtonText: 'Clear All' },
        ).then((result) => {
            if (result.isConfirmed) {
                state.queue = [];
                saveState();
                updateUI();
            }
        });
    }

    function previewEntry(index) {
        const entry = state.queue[index];
        if (!entry) {return;}

        const previewText = entry.preview || 'No preview available';
        $('#previewModalContent').text(previewText);
        $('#previewModal').modal('show');
    }

    /**
     * Submit a single entry from the queue
     * If it requires coordination, show coordination dialog
     * Otherwise, publish directly
     */
    function submitSingleEntry(index) {
        const entry = state.queue[index];
        if (!entry) {return;}

        const entryType = entry.entryType || entry.data?.entry_type || 'TMI';
        const needsCoord = requiresCoordination(entryType);

        if (needsCoord) {
            // Show single-entry coordination dialog
            showSingleEntryCoordinationDialog(entry, []);
        } else {
            // Publish directly without coordination
            const entryDetailHtml = buildEntryDetailHtml(entry);
            PERTIDialog.show({
                titleKey: 'tmiPublish.publish.title',
                html: `
                    <div class="text-left">
                        ${entryDetailHtml}
                        <p class="small text-muted mt-2">${PERTII18n.t('tmiPublish.publish.noCoordRequired')}</p>
                    </div>
                `,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#28a745',
                confirmKey: 'tmiPublish.publish.publishBtn',
                width: '500px',
            }).then((result) => {
                if (result.isConfirmed) {
                    publishSingleEntryDirect(entry, index);
                }
            });
        }
    }

    /**
     * Publish a single entry directly (no coordination)
     */
    async function publishSingleEntryDirect(entry, queueIndex) {
        PERTIDialog.loading('dialog.publishing');

        try {
            // Build the publish payload
            const payload = {
                production: true,
                entries: [entry],
                userCid: CONFIG.userCid,
                userName: CONFIG.userName || 'Unknown',
            };

            const response = await $.ajax({
                url: 'api/mgt/tmi/publish.php',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(payload),
            });

            console.log('[Direct Publish] Response:', response);

            if (response.success || (response.results && response.results.some(r => r.success))) {
                // Remove from queue on success
                state.queue.splice(queueIndex, 1);
                saveState();
                updateUI();

                PERTIDialog.success('tmiPublish.publish.published', 'tmiPublish.publish.publishedToDiscord');
            } else {
                const errorMsg = response.error || response.results?.[0]?.error || PERTII18n.t('common.unknown');
                PERTIDialog.error('tmiPublish.publish.publishFailed', null, {}, { html: `<p>${errorMsg}</p>` });
            }
        } catch (error) {
            console.error('[Direct Publish] Error:', error);
            PERTIDialog.error('tmiPublish.publish.publishFailed', null, {}, {
                html: `<p>${error.responseText || error.message || PERTII18n.t('error.connectionFailed')}</p>`,
            });
        }
    }

    /**
     * Async version of performSubmit for use in coordination flow
     */
    async function performSubmitAsync() {
        return new Promise((resolve, reject) => {
            performSubmit();
            // performSubmit handles its own UI, just resolve after a delay
            setTimeout(resolve, 1000);
        });
    }

    /**
     * Show coordination submission results
     */
    function showCoordinationResults(results, totalCoord, totalDirect) {
        const successCount = results.success.length;
        const discordFailedCount = results.discordFailed.length;
        const failedCount = results.failed.length;
        const directCount = results.directPublished.length;

        let icon = 'success';
        let title = 'Submitted!';

        if (failedCount > 0 || discordFailedCount > 0) {
            icon = 'warning';
            title = 'Partially Submitted';
        }
        if (successCount === 0 && discordFailedCount === 0) {
            icon = 'error';
            title = 'Submission Failed';
        }

        let html = '';
        if (successCount > 0) {
            html += `<p class="text-success"><i class="fas fa-check"></i> ${successCount} proposal(s) posted to #coordination</p>`;
        }
        if (discordFailedCount > 0) {
            html += `<p class="text-warning"><i class="fas fa-exclamation-triangle"></i> ${discordFailedCount} proposal(s) saved but Discord failed</p>`;
        }
        if (failedCount > 0) {
            html += `<p class="text-danger"><i class="fas fa-times"></i> ${failedCount} proposal(s) failed</p>`;
            results.failed.forEach(f => {
                html += `<small class="text-muted d-block">${f.entry.data?.ctl_element || 'Entry'}: ${f.error}</small>`;
            });
        }
        if (directCount > 0) {
            html += `<p class="text-info"><i class="fas fa-paper-plane"></i> ${directCount} entry(ies) published directly</p>`;
        }

        // Clear successfully submitted entries from queue
        if (successCount > 0 || discordFailedCount > 0) {
            // Remove coordinated entries from queue
            const coordEntries = [...results.success, ...results.discordFailed].map(r => r.entry);
            state.queue = state.queue.filter(q => !coordEntries.includes(q));
        }
        if (directCount > 0) {
            state.queue = state.queue.filter(q => !results.directPublished.includes(q));
        }
        saveState();
        updateUI();

        PERTIDialog.show({
            title: title,
            html: html,
            icon: icon,
        });
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
                        item.status !== 'CANCELLED',
                    );
                    callback(existing || null);
                } else {
                    callback(null);
                }
            },
            error: function() {
                callback(null);
            },
        });
    }

    function showDuplicateConfigPrompt(airport, existingConfig, type) {
        const existingTime = existingConfig.validFrom ?
            new Date(existingConfig.validFrom).toLocaleString('en-US', { timeZone: 'UTC', hour: '2-digit', minute: '2-digit', hour12: false }) + 'Z' :
            'Unknown time';
        const existingStatus = existingConfig.status || 'ACTIVE';
        const titleText = typeof PERTII18n !== 'undefined'
            ? PERTII18n.t('tmiPublish.duplicateConfig.title')
            : 'Duplicate CONFIG';
        const existingText = typeof PERTII18n !== 'undefined'
            ? PERTII18n.t('tmiPublish.duplicateConfig.existingText', { airport })
            : `An active CONFIG already exists for ${airport}:`;
        const whatToDo = typeof PERTII18n !== 'undefined'
            ? PERTII18n.t('tmiPublish.duplicateConfig.whatToDo')
            : 'What would you like to do?';

        PERTIDialog.show({
            title: `<i class="fas fa-exclamation-triangle text-warning"></i> ${titleText}`,
            html: `
                <div class="text-left">
                    <p>${existingText}</p>
                    <div class="alert alert-secondary">
                        <strong>Status:</strong> ${existingStatus}<br>
                        <strong>Posted:</strong> ${existingTime}<br>
                        <strong>ID:</strong> #${existingConfig.entityId}
                    </div>
                    <p>${whatToDo}</p>
                </div>
            `,
            icon: 'warning',
            showCancelButton: true,
            showDenyButton: true,
            confirmButtonText: '<i class="fas fa-edit"></i> Update Existing',
            confirmButtonColor: '#007bff',
            denyButtonText: '<i class="fas fa-plus"></i> Create New Anyway',
            denyButtonColor: '#6c757d',
            cancelKey: 'common.cancel',
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

        PERTIDialog.loading('tmiPublish.cancelTmi.cancelling');

        $.ajax({
            url: 'api/mgt/tmi/cancel.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                entityType: 'ENTRY',
                entityId: existingId,
                reason: 'Replaced with updated CONFIG',
                userCid: userCid,
                userName: userName,
            }),
            success: function(response) {
                PERTIDialog.close();
                if (response.success) {
                    // Now add the new CONFIG
                    addNtmlToQueue(type, true);
                } else {
                    PERTIDialog.error('common.error', null, {}, { text: response.error || PERTII18n.t('tmiPublish.cancelTmi.failed') });
                }
            },
            error: function(xhr) {
                PERTIDialog.close();
                PERTIDialog.error('common.error', null, {}, { text: PERTII18n.t('tmiPublish.cancelTmi.failed') + ': ' + (xhr.responseJSON?.error || PERTII18n.t('common.unknown')) });
            },
        });
    }

    // ===========================================
    // Submit
    // ===========================================

    function submitAll() {
        if (!state.queue || state.queue.length === 0) {return;}

        // Check if profile is complete
        if (!isProfileComplete()) {
            PERTIDialog.show({
                icon: 'warning',
                titleKey: 'tmiPublish.profile.required',
                htmlKey: 'tmiPublish.profile.requiredHtml',
                confirmKey: 'tmiPublish.profile.setup',
                showCancelButton: true,
            }).then((result) => {
                if (result.isConfirmed) {
                    showProfileModal();
                }
            });
            return;
        }

        const mode = state.productionMode ? 'PRODUCTION' : 'STAGING';
        const modeClass = state.productionMode ? 'text-danger' : 'text-warning';

        // For production mode, show coordination options
        if (state.productionMode) {
            showCoordinationDialog();
        } else {
            // Staging - direct submit
            PERTIDialog.confirm(
                null, null, {},
                {
                    title: `Submit to ${mode}?`,
                    html: `<p>Post <strong>${state.queue.length}</strong> entries to Discord.</p>
                           <p class="${modeClass}">Mode: <strong>${mode}</strong></p>`,
                    confirmButtonColor: '#28a745',
                    confirmButtonText: `Submit to ${mode}`,
                },
            ).then((result) => {
                if (result.isConfirmed) {
                    performSubmit();
                }
            });
        }
    }

    // TMI types that require coordination (external approval process)
    // All other types can be published directly without coordination
    const COORDINATION_REQUIRED_TYPES = ['MIT', 'MINIT', 'APREQ', 'CFR', 'TBM', 'TBFM', 'STOP'];

    /**
     * Check if an entry type requires external coordination
     */
    function requiresCoordination(entryType) {
        return COORDINATION_REQUIRED_TYPES.includes((entryType || '').toUpperCase());
    }

    function showCoordinationDialog() {
        // Check if any entries require coordination
        const entriesRequiringCoord = state.queue.filter(e =>
            requiresCoordination(e.entryType || e.data?.entry_type),
        );
        const entriesNotRequiringCoord = state.queue.filter(e =>
            !requiresCoordination(e.entryType || e.data?.entry_type),
        );

        // If no entries require coordination, skip the dialog and publish directly
        if (entriesRequiringCoord.length === 0) {
            console.log('[Coordination] No entries require coordination, publishing directly');
            PERTIDialog.confirm(
                'tmiPublish.publish.publishNow', null, {},
                {
                    html: `<p>Post <strong>${state.queue.length}</strong> entry(ies) directly to Discord.</p>
                           <p class="small text-muted">${typeof PERTII18n !== 'undefined' ? PERTII18n.t('tmiPublish.publish.noCoordRequired') : 'These entry types do not require facility coordination.'}</p>`,
                    confirmButtonColor: '#28a745',
                    confirmKey: 'tmiPublish.publish.publishBtn',
                },
            ).then((result) => {
                if (result.isConfirmed) {
                    performSubmit();
                }
            });
            return;
        }

        // If only one entry requiring coordination, use simplified flow
        if (entriesRequiringCoord.length === 1) {
            showSingleEntryCoordinationDialog(entriesRequiringCoord[0], entriesNotRequiringCoord);
            return;
        }

        // Multiple entries - ask user how they want to proceed
        let message = `<p><strong>${entriesRequiringCoord.length}</strong> entries require coordination.</p>`;
        if (entriesNotRequiringCoord.length > 0) {
            message += `<p class="small text-muted">(${entriesNotRequiringCoord.length} other entries will publish directly)</p>`;
        }
        message += `<p>How would you like to proceed?</p>`;

        PERTIDialog.show({
            titleKey: 'tmiPublish.submit.title',
            html: message,
            icon: 'question',
            showCancelButton: true,
            showDenyButton: true,
            confirmButtonColor: '#007bff',
            denyButtonColor: '#28a745',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Verify Each Entry',
            denyButtonText: typeof PERTII18n !== 'undefined' ? PERTII18n.t('tmiPublish.quickSubmit') : 'Quick Submit (Same Facilities)',
            cancelKey: 'common.cancel',
            width: '500px',
        }).then((result) => {
            if (result.isConfirmed) {
                // Step through each entry individually
                showSteppedCoordinationDialog(entriesRequiringCoord, entriesNotRequiringCoord);
            } else if (result.isDenied) {
                // Quick submit - use same facilities for all (legacy behavior)
                showBulkCoordinationDialog(entriesRequiringCoord, entriesNotRequiringCoord);
            }
        });
    }

    /**
     * Show coordination dialog for a single entry
     */
    function showSingleEntryCoordinationDialog(entry, entriesToPublishDirect) {
        // Calculate default deadline for this entry
        const deadline = calculateEntryDeadline(entry);
        const deadlineStr = deadline.toISOString().slice(0, 16);

        // Detect facilities for this entry
        const suggestedFacilities = detectFacilitiesForEntry(entry);

        // Build detailed entry display
        const entryDetailHtml = buildEntryDetailHtml(entry);

        let directMsg = '';
        if (entriesToPublishDirect.length > 0) {
            directMsg = `<p class="small text-muted mb-2">(${entriesToPublishDirect.length} other entries will publish directly)</p>`;
        }

        Swal.fire({
            title: 'Submit for Coordination',
            html: `
                <div class="text-left">
                    ${entryDetailHtml}
                    ${directMsg}

                    <div class="form-group">
                        <div class="custom-control custom-radio mb-2">
                            <input type="radio" id="coordOption_coordinate" name="coordOption" class="custom-control-input" value="coordinate" checked>
                            <label class="custom-control-label" for="coordOption_coordinate">
                                <strong>Submit for Coordination</strong>
                            </label>
                        </div>
                        <div class="custom-control custom-radio mb-2">
                            <input type="radio" id="coordOption_precoordinated" name="coordOption" class="custom-control-input" value="precoordinated">
                            <label class="custom-control-label" for="coordOption_precoordinated">
                                <strong>Already Coordinated</strong>
                            </label>
                        </div>
                    </div>

                    <div id="coordinationOptions" class="mt-3 p-3 bg-light rounded">
                        <div class="form-group mb-3">
                            <label for="coordDeadline"><strong>Approval Deadline (UTC):</strong></label>
                            <input type="datetime-local" class="form-control" id="coordDeadline" value="${deadlineStr}">
                        </div>

                        <div class="form-group mb-0">
                            <label><strong>Facilities Required to Approve:</strong></label>
                            <div id="facilityCheckboxes" class="row">
                                ${buildFacilityCheckboxesForEntry(entry, suggestedFacilities)}
                            </div>
                        </div>
                    </div>
                </div>
            `,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            confirmButtonText: 'Submit',
            width: '550px',
            didOpen: () => {
                document.querySelectorAll('input[name="coordOption"]').forEach(radio => {
                    radio.addEventListener('change', function() {
                        document.getElementById('coordinationOptions').style.display =
                            this.value === 'coordinate' ? 'block' : 'none';
                    });
                });
            },
            preConfirm: () => {
                const mode = document.querySelector('input[name="coordOption"]:checked').value;
                if (mode === 'coordinate') {
                    const deadline = document.getElementById('coordDeadline').value;
                    if (!deadline) {
                        Swal.showValidationMessage('Please set an approval deadline');
                        return false;
                    }
                    const facilities = [];
                    document.querySelectorAll('.facility-checkbox:checked').forEach(cb => {
                        facilities.push({ code: cb.value, emoji: cb.dataset.emoji || null });
                    });
                    if (facilities.length === 0) {
                        Swal.showValidationMessage('Please select at least one facility');
                        return false;
                    }
                    return { mode: 'coordinate', deadline, facilities };
                }
                return { mode: 'direct' };
            },
        }).then((result) => {
            if (result.isConfirmed) {
                if (result.value.mode === 'coordinate') {
                    // Submit this entry with its specific facilities
                    submitEntriesForCoordination([{
                        entry: entry,
                        deadline: result.value.deadline,
                        facilities: result.value.facilities,
                    }], entriesToPublishDirect);
                } else {
                    performSubmit();
                }
            }
        });
    }

    /**
     * Step through each entry for individual facility verification
     */
    async function showSteppedCoordinationDialog(entries, entriesToPublishDirect) {
        const confirmedEntries = [];
        const totalEntries = entries.length;

        for (let i = 0; i < totalEntries; i++) {
            const entry = entries[i];
            const data = entry.data || {};
            const entryType = (entry.entryType || data.entry_type || 'TMI').toUpperCase();
            const ctlElement = (data.ctl_element || '').toUpperCase();
            const deadline = calculateEntryDeadline(entry);
            const deadlineStr = deadline.toISOString().slice(0, 16);
            const suggestedFacilities = detectFacilitiesForEntry(entry);

            // Build detailed entry display
            const entryDetailHtml = buildEntryDetailHtml(entry);

            const result = await Swal.fire({
                title: `Entry ${i + 1} of ${totalEntries}`,
                html: `
                    <div class="text-left">
                        ${entryDetailHtml}

                        <div class="form-group mb-3">
                            <label for="coordDeadline_${i}"><strong>Approval Deadline (UTC):</strong></label>
                            <input type="datetime-local" class="form-control" id="coordDeadline_${i}" value="${deadlineStr}">
                        </div>

                        <div class="form-group mb-0">
                            <label><strong>Facilities Required to Approve:</strong></label>
                            <div id="facilityCheckboxes_${i}" class="row">
                                ${buildFacilityCheckboxesForEntry(entry, suggestedFacilities)}
                            </div>
                            <small class="text-muted">Auto-detected from entry. Modify as needed.</small>
                        </div>
                    </div>
                `,
                icon: 'question',
                showCancelButton: true,
                showDenyButton: i > 0,
                confirmButtonColor: i === totalEntries - 1 ? '#dc3545' : '#007bff',
                denyButtonColor: '#6c757d',
                confirmButtonText: i === totalEntries - 1 ? 'Submit All' : 'Next Entry →',
                denyButtonText: '← Back',
                cancelButtonText: 'Cancel All',
                width: '550px',
                allowOutsideClick: false,
                preConfirm: () => {
                    const deadline = document.getElementById(`coordDeadline_${i}`).value;
                    if (!deadline) {
                        Swal.showValidationMessage('Please set an approval deadline');
                        return false;
                    }
                    const facilities = [];
                    document.querySelectorAll('.facility-checkbox:checked').forEach(cb => {
                        facilities.push({ code: cb.value, emoji: cb.dataset.emoji || null });
                    });
                    if (facilities.length === 0) {
                        Swal.showValidationMessage('Please select at least one facility');
                        return false;
                    }
                    return { deadline, facilities };
                },
            });

            if (result.isDenied && i > 0) {
                // Go back - remove last confirmed entry and decrement counter
                confirmedEntries.pop();
                i -= 2; // Will increment to i-1 on next iteration
                continue;
            }

            if (result.isDismissed) {
                // User cancelled
                return;
            }

            // Store confirmed entry with its facilities
            confirmedEntries.push({
                entry: entry,
                deadline: result.value.deadline,
                facilities: result.value.facilities,
            });
        }

        // All entries confirmed - submit them
        if (confirmedEntries.length === totalEntries) {
            submitEntriesForCoordination(confirmedEntries, entriesToPublishDirect);
        }
    }

    /**
     * Quick bulk submit with same facilities for all entries (legacy behavior)
     */
    function showBulkCoordinationDialog(entriesRequiringCoord, entriesNotRequiringCoord) {
        // Use first entry's deadline as default for all
        const deadline = calculateEntryDeadline(entriesRequiringCoord[0]);
        const deadlineStr = deadline.toISOString().slice(0, 16);

        let coordMessage = `Post <strong>${entriesRequiringCoord.length}</strong> entry(ies) to <span class="text-danger font-weight-bold">PRODUCTION</span>`;
        if (entriesNotRequiringCoord.length > 0) {
            coordMessage += `<br><small class="text-muted">(${entriesNotRequiringCoord.length} other entries will publish directly)</small>`;
        }

        Swal.fire({
            title: 'Quick Submit - Same Facilities',
            html: `
                <div class="text-left">
                    <p class="mb-3">${coordMessage}</p>
                    <p class="small text-warning"><strong>Note:</strong> All entries will use the same facilities and deadline.</p>

                    <div class="p-3 bg-light rounded">
                        <div class="form-group mb-3">
                            <label for="coordDeadline"><strong>Approval Deadline (UTC):</strong></label>
                            <input type="datetime-local" class="form-control" id="coordDeadline" value="${deadlineStr}">
                        </div>

                        <div class="form-group mb-0">
                            <label><strong>Facilities Required to Approve:</strong></label>
                            <div id="facilityCheckboxes" class="row">
                                ${buildFacilityCheckboxes()}
                            </div>
                        </div>
                    </div>
                </div>
            `,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            confirmButtonText: 'Submit All',
            width: '550px',
            preConfirm: () => {
                const deadline = document.getElementById('coordDeadline').value;
                if (!deadline) {
                    Swal.showValidationMessage('Please set an approval deadline');
                    return false;
                }
                const facilities = [];
                document.querySelectorAll('.facility-checkbox:checked').forEach(cb => {
                    facilities.push({ code: cb.value, emoji: cb.dataset.emoji || null });
                });
                if (facilities.length === 0) {
                    Swal.showValidationMessage('Please select at least one facility');
                    return false;
                }
                return { deadline, facilities };
            },
        }).then((result) => {
            if (result.isConfirmed) {
                // Apply same facilities to all entries
                const confirmedEntries = entriesRequiringCoord.map(entry => ({
                    entry: entry,
                    deadline: result.value.deadline,
                    facilities: result.value.facilities,
                }));
                submitEntriesForCoordination(confirmedEntries, entriesNotRequiringCoord);
            }
        });
    }

    /**
     * Calculate deadline for an entry: T(start) - 1 minute, or now + 2 hours
     */
    function calculateEntryDeadline(entry) {
        const data = entry?.data || {};
        const validFrom = data.valid_from || data.validFrom;

        if (validFrom) {
            const startTime = new Date(validFrom.includes('Z') ? validFrom : validFrom + 'Z');
            if (!isNaN(startTime.getTime()) && startTime > new Date()) {
                return new Date(startTime.getTime() - 60 * 1000); // T-1 minute
            }
        }
        return new Date(Date.now() + 2 * 60 * 60 * 1000); // Now + 2 hours
    }

    /**
     * Detect facilities that should approve this entry based on its data
     * NOTE: Requesting facility is excluded - they implicitly approve by submitting
     */
    function detectFacilitiesForEntry(entry) {
        const facilities = new Set();
        const data = entry.data || {};

        // Get the requesting facility - they don't need to approve (implicit approval by submitting)
        const reqFacility = (data.requesting_facility || data.req_facility_id || data.req_facility || '').toUpperCase().trim();

        // Check explicitly specified providing facilities
        if (data.prov_facility) {
            data.prov_facility.toUpperCase().split(',').forEach(fac => {
                const trimmed = fac.trim();
                if (trimmed && trimmed !== reqFacility) {facilities.add(trimmed);}
            });
        }

        // Also check providing_facility field
        if (data.providing_facility) {
            data.providing_facility.toUpperCase().split(',').forEach(fac => {
                const trimmed = fac.trim();
                if (trimmed && trimmed !== reqFacility) {facilities.add(trimmed);}
            });
        }

        // Check prov_facility_id field
        if (data.prov_facility_id) {
            data.prov_facility_id.toUpperCase().split(',').forEach(fac => {
                const trimmed = fac.trim();
                if (trimmed && trimmed !== reqFacility) {facilities.add(trimmed);}
            });
        }

        // NOTE: Control element (airport) does NOT determine coordination facilities.
        // Coordination is purely between requesting and providing facilities.
        // The TMI could be for an airport in a different ARTCC, but that ARTCC
        // doesn't need to approve - only the specified req:prov facilities do.

        return facilities;
    }

    /**
     * Build detailed HTML display for a TMI entry in the coordination dialog
     */
    function buildEntryDetailHtml(entry) {
        const data = entry.data || {};
        const entryType = (entry.entryType || data.entry_type || 'TMI').toUpperCase();
        const ctlElement = (data.ctl_element || '').toUpperCase();
        const restrictionValue = data.restriction_value || '';
        const restrictionUnit = data.restriction_unit || (entryType === 'MIT' ? 'NM' : 'MIN');
        const via = data.via || data.condition_text || '';
        const flowType = data.flow_type || 'arrivals';
        const qualifiers = data.qualifiers || '';
        const reasonCode = data.reason_code || '';
        const reasonDetail = data.reason_detail || '';
        const exclusions = data.exclusions || '';
        const reqFacility = (data.requesting_facility || data.req_facility_id || '').toUpperCase();
        const provFacility = (data.providing_facility || data.prov_facility_id || '').toUpperCase();

        // Format valid times
        const validFrom = data.valid_from || data.validFrom || '';
        const validUntil = data.valid_until || data.validUntil || '';

        const formatTime = (timeStr) => {
            if (!timeStr) {return '--';}
            try {
                // Handle HHMM format (4 digits like "0959" or "1359")
                if (/^\d{4}$/.test(timeStr)) {
                    const hours = timeStr.slice(0, 2);
                    const mins = timeStr.slice(2, 4);
                    return `${hours}:${mins}Z`;
                }
                // Handle DDHHMM format (6 digits like "290959")
                if (/^\d{6}$/.test(timeStr)) {
                    const hours = timeStr.slice(2, 4);
                    const mins = timeStr.slice(4, 6);
                    return `${hours}:${mins}Z`;
                }
                // Handle datetime-local format (2026-01-29T10:05)
                if (timeStr.includes('T') || timeStr.includes('Z')) {
                    const dt = new Date(timeStr.includes('Z') ? timeStr : timeStr + 'Z');
                    if (!isNaN(dt.getTime())) {
                        return dt.toISOString().slice(11, 16) + 'Z';
                    }
                }
                // Fallback: return first 5 chars or the input
                return timeStr.slice(0, 5) || timeStr || '--';
            } catch {
                return timeStr.slice(0, 5) || '--';
            }
        };

        const timeRange = `${formatTime(validFrom)} - ${formatTime(validUntil)}`;

        // Build restriction string
        let restrictionStr = '';
        if (entryType === 'STOP') {
            restrictionStr = 'STOP';
        } else if (restrictionValue) {
            restrictionStr = `${restrictionValue}${restrictionUnit}`;
        }

        // Build the header line (NTML-style)
        let headerLine = `<strong>${ctlElement}</strong>`;
        if (via) {
            headerLine += ` ${flowType} via <strong>${via}</strong>`;
        }
        if (restrictionStr) {
            headerLine += ` <span class="badge badge-warning">${restrictionStr}</span>`;
        }
        if (qualifiers) {
            const qualifierArray = typeof qualifiers === 'string' ? qualifiers.split(',') : qualifiers;
            qualifierArray.forEach(q => {
                if (q.trim()) {
                    headerLine += ` <span class="badge badge-secondary">${q.trim().toUpperCase()}</span>`;
                }
            });
        }

        // Build detail rows in NTML order: reason, exclusions, valid times, facilities
        let detailRows = '';

        // Reason row
        if (reasonCode) {
            const reasonStr = reasonDetail ? `${reasonCode}:${reasonDetail}` : reasonCode;
            detailRows += `
                <tr>
                    <td class="text-muted" style="width: 100px;">Reason:</td>
                    <td>${reasonStr.toUpperCase()}</td>
                </tr>
            `;
        }

        // Exclusions row
        if (exclusions && exclusions.toUpperCase() !== 'NONE') {
            detailRows += `
                <tr>
                    <td class="text-muted">Exclusions:</td>
                    <td>${exclusions.toUpperCase()}</td>
                </tr>
            `;
        }

        // Valid times row
        detailRows += `
            <tr>
                <td class="text-muted">Valid:</td>
                <td><strong>${timeRange}</strong></td>
            </tr>
        `;

        // Facilities row (at the end per NTML format)
        if (reqFacility || provFacility) {
            detailRows += `
                <tr>
                    <td class="text-muted">Req:Prov:</td>
                    <td>${reqFacility}${provFacility ? ':' + provFacility : ''}</td>
                </tr>
            `;
        }

        return `
            <div class="card mb-3">
                <div class="card-header py-2 bg-info text-white">
                    <strong>${entryType}</strong>
                </div>
                <div class="card-body py-2">
                    <p class="mb-2">${headerLine}</p>
                    <table class="table table-sm table-borderless mb-0" style="font-size: 0.9em;">
                        <tbody>
                            ${detailRows}
                        </tbody>
                    </table>
                </div>
            </div>
        `;
    }

    /**
     * Build facility checkboxes for a specific entry with suggested facilities pre-checked
     */
    function buildFacilityCheckboxesForEntry(entry, suggestedFacilities) {
        const commonFacilities = ['ZNY', 'ZDC', 'ZBW', 'ZOB', 'ZAU', 'ZID', 'ZTL', 'ZJX', 'ZMA', 'ZHU', 'ZFW', 'ZKC', 'ZMP', 'ZLA', 'ZOA', 'ZSE', 'ZLC', 'ZDV', 'ZAB', 'ZME'];
        const allFacilities = new Set(commonFacilities);
        suggestedFacilities.forEach(f => allFacilities.add(f));

        const sortedFacilities = Array.from(allFacilities).sort();

        let html = '';
        sortedFacilities.forEach(fac => {
            const checked = suggestedFacilities.has(fac);
            html += `
                <div class="col-4 mb-1">
                    <div class="custom-control custom-checkbox">
                        <input type="checkbox" class="custom-control-input facility-checkbox"
                               id="fac_${fac}" value="${fac}" data-emoji=":${fac}:" ${checked ? 'checked' : ''}>
                        <label class="custom-control-label ${checked ? 'font-weight-bold text-primary' : ''}" for="fac_${fac}">${fac}</label>
                    </div>
                </div>
            `;
        });
        return html;
    }

    /**
     * Submit confirmed entries for coordination (each with its own facilities)
     */
    async function submitEntriesForCoordination(confirmedEntries, entriesToPublishDirect) {
        const totalCoord = confirmedEntries.length;
        const totalDirect = entriesToPublishDirect.length;
        const results = { success: [], failed: [], discordFailed: [], directPublished: [] };

        PERTIDialog.show({
            titleKey: 'tmiPublish.submit.submitting',
            html: `<p>Processing <strong>0 / ${totalCoord}</strong> proposals</p>`,
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading(),
        });

        // Submit each entry with its specific facilities
        for (let i = 0; i < totalCoord; i++) {
            const { entry, deadline, facilities } = confirmedEntries[i];
            const deadlineUtc = deadline + ':00.000Z';

            Swal.update({
                html: `<p>Posting <strong>${i + 1} / ${totalCoord}</strong> proposals to #coordination</p>
                       <p class="small text-muted">${entry.data?.ctl_element || 'Entry'} - ${entry.data?.entry_type || 'TMI'}</p>`,
            });

            const payload = {
                entry: entry,
                deadlineUtc: deadlineUtc,
                facilities: facilities,
                userCid: CONFIG.userCid,
                userName: CONFIG.userName || 'Unknown',
            };

            try {
                const response = await $.ajax({
                    url: 'api/mgt/tmi/coordinate.php',
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify(payload),
                });

                console.log(`[Coordination] Entry ${i + 1} Response:`, response);

                if (response.success) {
                    const discordOk = response.discord && response.discord.success;
                    if (discordOk) {
                        results.success.push({ entry, proposalId: response.proposal_id });
                    } else {
                        results.discordFailed.push({
                            entry, proposalId: response.proposal_id,
                            error: response.discord?.error || 'Discord posting failed',
                        });
                    }
                } else {
                    results.failed.push({ entry, error: response.error || 'Unknown error' });
                }
            } catch (error) {
                console.error(`Coordination submit error for entry ${i + 1}:`, error);
                results.failed.push({
                    entry, error: error.responseText || error.message || 'Connection error',
                });
            }

            if (i < totalCoord - 1) {
                await new Promise(resolve => setTimeout(resolve, 500));
            }
        }

        // Publish direct entries
        if (totalDirect > 0) {
            Swal.update({
                html: `<p>Publishing <strong>${totalDirect}</strong> entries directly...</p>`,
            });

            try {
                // Temporarily set queue to just direct entries
                const originalQueue = state.queue;
                state.queue = entriesToPublishDirect;
                await performSubmitAsync();
                state.queue = originalQueue;
                results.directPublished = entriesToPublishDirect;
            } catch (error) {
                console.error('Direct publish error:', error);
            }
        }

        // Show results
        showCoordinationResults(results, totalCoord, totalDirect);
    }

    // Airport to ARTCC mapping - uses FacilityHierarchy loaded from apts.csv
    // See: assets/js/facility-hierarchy.js AIRPORT_TO_ARTCC

    /**
     * Get ARTCC for an airport code
     * @param {string} airport - Airport code (FAA or ICAO)
     * @returns {string|null} ARTCC code or null
     */
    function getAirportARTCC(airport) {
        const FH = window.FacilityHierarchy;
        if (FH && FH.AIRPORT_TO_ARTCC) {
            return FH.AIRPORT_TO_ARTCC[airport.toUpperCase()] || null;
        }
        return null;
    }

    function buildFacilityCheckboxes() {
        // Get PROVIDING facilities from queue entries (facilities that need to approve)
        // Note: We do NOT auto-select requesting facilities - they proposed the TMI, they don't approve it
        const detectedFacilities = new Set();
        state.queue.forEach(entry => {
            const data = entry.data || {};
            // Only add PROVIDING facilities (the ones who need to approve)
            // Field name is 'prov_facility' from the form - may be comma-separated (e.g., "ZOB,ZNY,CZYZ")
            if (data.prov_facility) {
                const provFacs = data.prov_facility.toUpperCase();
                provFacs.split(',').forEach(fac => {
                    const trimmed = fac.trim();
                    if (trimmed) {detectedFacilities.add(trimmed);}
                });
            }
            // Only fall back to airport ARTCC mapping if no providing facility was explicitly specified
            else if (data.ctl_element) {
                const airport = data.ctl_element.toUpperCase();
                const artcc = getAirportARTCC(airport);
                if (artcc) {
                    detectedFacilities.add(artcc);
                }
            }
        });

        // Common ARTCCs (always shown)
        const commonFacilities = ['ZNY', 'ZDC', 'ZBW', 'ZOB', 'ZAU', 'ZID', 'ZTL', 'ZJX', 'ZMA', 'ZHU', 'ZFW', 'ZKC', 'ZMP', 'ZLA', 'ZOA', 'ZSE', 'ZLC', 'ZDV', 'ZAB', 'ZME'];

        // Merge detected and common
        const allFacilities = new Set(commonFacilities);
        detectedFacilities.forEach(f => allFacilities.add(f));

        const sortedFacilities = Array.from(allFacilities).sort();

        let html = '';
        sortedFacilities.forEach(fac => {
            // Check if this facility was detected from the queue entries
            const checked = detectedFacilities.has(fac);
            html += `
                <div class="col-4 mb-1">
                    <div class="custom-control custom-checkbox">
                        <input type="checkbox" class="custom-control-input facility-checkbox"
                               id="fac_${fac}" value="${fac}" data-emoji=":${fac}:" ${checked ? 'checked' : ''}>
                        <label class="custom-control-label" for="fac_${fac}">${fac}</label>
                    </div>
                </div>
            `;
        });
        return html;
    }

    async function submitForCoordination(deadline, facilities) {
        // Submit EACH entry separately for coordination - each gets its own proposal
        // NOTE: Form is labeled "UTC" so user enters UTC time directly
        // datetime-local returns value without timezone, append Z to mark as UTC
        const deadlineUtc = deadline + ':00.000Z';

        // Separate entries that require coordination from those that don't
        const entriesToCoordinate = state.queue.filter(e =>
            requiresCoordination(e.entryType || e.data?.entry_type),
        );
        const entriesToPublishDirect = state.queue.filter(e =>
            !requiresCoordination(e.entryType || e.data?.entry_type),
        );

        const totalCoord = entriesToCoordinate.length;
        const totalDirect = entriesToPublishDirect.length;
        const results = { success: [], failed: [], discordFailed: [], directPublished: [] };

        PERTIDialog.show({
            titleKey: 'tmiPublish.submit.submitting',
            html: `<p>Processing <strong>0 / ${totalCoord + totalDirect}</strong> entries</p>`,
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading(),
        });

        // First, submit entries requiring coordination
        for (let i = 0; i < totalCoord; i++) {
            const entry = entriesToCoordinate[i];

            // Update progress
            Swal.update({
                html: `<p>Posting <strong>${i + 1} / ${totalCoord}</strong> proposals to #coordination</p>
                       <p class="small text-muted">${entry.data?.ctl_element || 'Entry'} - ${entry.data?.entry_type || 'TMI'}</p>`,
            });

            const payload = {
                entry: entry,
                deadlineUtc: deadlineUtc,
                facilities: facilities,
                userCid: CONFIG.userCid,
                userName: CONFIG.userName || 'Unknown',
            };

            try {
                const response = await $.ajax({
                    url: 'api/mgt/tmi/coordinate.php',
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify(payload),
                });

                console.log(`[Coordination] Entry ${i + 1} Response:`, response);

                if (response.success) {
                    const discordOk = response.discord && response.discord.success;
                    if (discordOk) {
                        results.success.push({
                            entry: entry,
                            proposalId: response.proposal_id,
                        });
                    } else {
                        results.discordFailed.push({
                            entry: entry,
                            proposalId: response.proposal_id,
                            error: response.discord?.error || 'Discord posting failed',
                        });
                    }
                } else {
                    results.failed.push({
                        entry: entry,
                        error: response.error || 'Unknown error',
                    });
                }
            } catch (error) {
                console.error(`Coordination submit error for entry ${i + 1}:`, error);
                results.failed.push({
                    entry: entry,
                    error: error.responseText || error.message || 'Connection error',
                });
            }

            // Small delay between submissions to avoid rate limiting
            if (i < totalCoord - 1) {
                await new Promise(resolve => setTimeout(resolve, 500));
            }
        }

        // Now publish entries that don't require coordination directly
        if (totalDirect > 0) {
            Swal.update({
                html: `<p>Publishing <strong>${totalDirect}</strong> entries directly...</p>
                       <p class="small text-muted">These types don't require coordination</p>`,
            });

            try {
                const directPayload = {
                    entries: entriesToPublishDirect,
                    production: state.productionMode,
                    userCid: CONFIG.userCid,
                };

                const directResponse = await $.ajax({
                    url: 'api/mgt/tmi/publish.php',
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify(directPayload),
                });

                if (directResponse.success) {
                    results.directPublished = entriesToPublishDirect;
                    console.log('[Coordination] Direct publish succeeded:', directResponse);
                } else {
                    console.error('[Coordination] Direct publish failed:', directResponse.error);
                }
            } catch (error) {
                console.error('[Coordination] Direct publish error:', error);
            }
        }

        PERTIDialog.close();

        // Clear queue and show results
        state.queue = [];
        saveState();
        updateUI();

        // Build result summary
        const successCount = results.success.length;
        const discordFailedCount = results.discordFailed.length;
        const failedCount = results.failed.length;
        const directCount = results.directPublished.length;

        if (failedCount === 0 && discordFailedCount === 0) {
            // All succeeded
            let html = '';
            if (successCount > 0) {
                const proposalIds = results.success.map(r => `#${r.proposalId}`).join(', ');
                html += `<p><strong>${successCount}</strong> proposal(s) posted to #coordination.</p>
                         <p class="small">Proposal IDs: ${proposalIds}</p>
                         <p class="small text-muted">Awaiting facility approval.</p>`;
            }
            if (directCount > 0) {
                html += `<p><strong>${directCount}</strong> entry(ies) published directly.</p>`;
            }
            PERTIDialog.success('tmiPublish.submit.complete', null, {}, {
                html: html,
                timer: 5000,
                showConfirmButton: true,
            });
        } else if (successCount === 0 && discordFailedCount === 0 && directCount === 0) {
            // All failed
            PERTIDialog.error('tmiPublish.submit.allFailed', null, {}, {
                html: `<p>Failed to submit <strong>${failedCount}</strong> proposal(s).</p>
                       <p class="small text-danger">${results.failed[0]?.error || 'Unknown error'}</p>`,
            });
        } else {
            // Mixed results
            let html = '';
            if (successCount > 0) {
                const proposalIds = results.success.map(r => `#${r.proposalId}`).join(', ');
                html += `<p class="text-success"><strong>${successCount}</strong> submitted for coordination (${proposalIds})</p>`;
            }
            if (directCount > 0) {
                html += `<p class="text-success"><strong>${directCount}</strong> published directly</p>`;
            }
            if (discordFailedCount > 0) {
                const proposalIds = results.discordFailed.map(r => `#${r.proposalId}`).join(', ');
                html += `<p class="text-warning"><strong>${discordFailedCount}</strong> saved but Discord failed (${proposalIds})</p>`;
            }
            if (failedCount > 0) {
                html += `<p class="text-danger"><strong>${failedCount}</strong> failed to submit</p>`;
            }

            PERTIDialog.show({
                icon: discordFailedCount > 0 || failedCount > 0 ? 'warning' : 'success',
                titleKey: 'tmiPublish.submit.results',
                html: html,
            });
        }
    }

    function performSubmit() {
        const payload = {
            entries: state.queue,
            production: state.productionMode,
            userCid: CONFIG.userCid,
        };

        // Show loading
        PERTIDialog.loading('tmiPublish.submit.submitting', 'Posting entries to Discord');

        $.ajax({
            url: 'api/mgt/tmi/publish.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(payload),
            success: function(response) {
                PERTIDialog.close();

                if (response.success) {
                    // Clear queue on success
                    state.queue = [];
                    saveState();
                    updateUI();

                    showSubmitResults(response);
                } else {
                    PERTIDialog.error('tmiPublish.submit.failed', null, {}, {
                        text: response.error || 'Unknown error occurred',
                    });
                }
            },
            error: function(xhr, status, error) {
                PERTIDialog.close();
                console.error('Submit error:', xhr.responseText);
                PERTIDialog.error('tmiPublish.submit.connectionError', null, {}, {
                    html: `<p>Failed to connect to server.</p><p class="small text-muted">${error}</p>`,
                });
            },
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

        PERTIDialog.show({
            icon: (response.summary?.failed || 0) === 0 ? 'success' : 'warning',
            titleKey: 'tmiPublish.submitComplete',
            html: html,
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
            },
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
        PERTIDialog.info(
            'tmiPublish.tmiDetails', null, {},
            { text: `${entityType || 'Entry'} #${id} - Details view coming soon` },
        );
    }

    function cancelTmi(id, entityType) {
        PERTIDialog.confirm(
            'tmiPublish.cancelTmi.title', null, {},
            {
                text: `This will cancel ${(entityType || 'entry').toLowerCase()} #${id}`,
                confirmButtonColor: '#dc3545',
                confirmButtonText: 'Cancel TMI',
            },
        ).then((result) => {
            if (result.isConfirmed) {
                PERTIDialog.info('tmiPublish.comingSoon', null, {}, {
                    text: 'Cancel functionality will be implemented shortly',
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
            },
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
            if (!entry || typeof entry !== 'object') {return;}

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
        PERTIDialog.confirm(
            'tmiPublish.promote.title', null, {},
            {
                html: `<p>Publish this ${(entityType || 'entry').toLowerCase()} to production channels?</p>
                       <p class="text-danger">This will post to LIVE channels.</p>`,
                confirmButtonColor: '#28a745',
                confirmButtonText: 'Promote',
            },
        ).then((result) => {
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
                userCid: CONFIG.userCid,
            }),
            success: function(response) {
                if (response.success) {
                    PERTIDialog.success('tmiPublish.promote.promoted', null, {}, {
                        text: 'Entry published to production channels.',
                        timer: 2000,
                    });
                    loadStagedEntries();
                } else {
                    PERTIDialog.error('tmiPublish.promote.failed', null, {}, {
                        text: response.results?.[0]?.error || response.error || 'Unknown error',
                    });
                }
            },
            error: function() {
                PERTIDialog.error('common.error', 'error.connectionFailed');
            },
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

        // Start time = current UTC time (no snapping)
        const startDate = new Date(now);
        startDate.setUTCSeconds(0);
        startDate.setUTCMilliseconds(0);

        // End time = 4 hours later, snapped to next quarter hour boundary (:14, :29, :44, :59)
        const endDate = new Date(startDate);
        endDate.setUTCHours(endDate.getUTCHours() + 4);

        // Snap end time to next quarter hour boundary
        const endMinutes = endDate.getUTCMinutes();
        let snapMinutes;
        if (endMinutes < 15) {snapMinutes = 14;}
        else if (endMinutes < 30) {snapMinutes = 29;}
        else if (endMinutes < 45) {snapMinutes = 44;}
        else {snapMinutes = 59;}

        if (endMinutes > snapMinutes) {
            endDate.setUTCHours(endDate.getUTCHours() + 1);
        }
        endDate.setUTCMinutes(snapMinutes);
        endDate.setUTCSeconds(0);

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
            endTime: `${String(endDate.getUTCHours()).padStart(2, '0')}:${String(endDate.getUTCMinutes()).padStart(2, '0')}`,
        };
    }

    function formatValidTime(from, until) {
        // Handle datetime-local format (YYYY-MM-DDTHH:MM) or time format (HH:MM)
        // Returns hhmm-hhmm format (time only, no date)
        const extractTime = (val) => {
            if (!val) {return '0000';}
            // If datetime-local format, extract time only
            if (val.includes('T')) {
                const timePart = val.split('T')[1] || '00:00';
                return timePart.replace(':', '');
            }
            // If time format only
            return val.replace(':', '') || '0000';
        };

        const fromStr = extractTime(from);
        const untilStr = extractTime(until);
        return `${fromStr}-${untilStr}`;
    }

    function formatValidDateTime(from, until) {
        // Returns formatted date/time for display: "01/28 1400-1800Z"
        const extractDateTime = (val) => {
            if (!val) {return { date: '', time: '0000' };}
            if (val.includes('T')) {
                const [datePart, timePart] = val.split('T');
                const [year, month, day] = datePart.split('-');
                return {
                    date: `${month}/${day}`,
                    time: (timePart || '00:00').replace(':', ''),
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
        if (text === null || text === undefined) {return '';}
        const str = String(text);
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    /**
     * Check if user is logged in (has valid VATSIM CID)
     * Required for DCC override actions
     */
    function isUserLoggedIn() {
        const cid = CONFIG.userCid;
        return cid && !isNaN(cid) && parseInt(cid, 10) > 0;
    }

    // ===========================================
    // Text Formatting Utilities (FAA 68-char standard)
    // ===========================================

    const TEXT_FORMAT = {
        LINE_WIDTH: 68,
        SEPARATOR: '____________________________________________________________________', // 68 underscores
        INDENT: '    ', // 4 spaces for continuation
    };

    /**
     * Wrap text to 68 characters with word boundaries
     * @param {string} text - Text to wrap
     * @param {number} width - Max line width (default 68)
     * @param {string} indent - Indent for continuation lines (default none)
     * @returns {string} Wrapped text
     */
    function wrapText(text, width = TEXT_FORMAT.LINE_WIDTH, indent = '') {
        if (!text) {return '';}

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
        if (!text) {return '';}

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
        if (!rows || rows.length === 0) {return '';}

        return rows.map(row => {
            return row.map((cell, i) => {
                const width = colWidths[i] || 10;
                return String(cell || '').padEnd(width);
            }).join('');
        }).join('\n');
    }

    /**
     * Wrap facility list (slash-separated) to fit within max width
     * Breaks at / characters to create readable multi-line lists
     * @param {string} facilityList - Slash-separated facility list (e.g., "ZAB/ZAN/ZAU/...")
     * @param {number} maxWidth - Maximum line width (default 68)
     * @returns {string} Wrapped facility list
     */
    function wrapFacilityList(facilityList, maxWidth = TEXT_FORMAT.LINE_WIDTH) {
        if (!facilityList) {return '';}

        const facilities = facilityList.split('/').filter(f => f.trim());
        if (facilities.length === 0) {return '';}

        const lines = [];
        let currentLine = '';

        facilities.forEach((facility, index) => {
            const separator = index < facilities.length - 1 ? '/' : '';
            const testAdd = facility + separator;
            const testLine = currentLine ? currentLine + testAdd : testAdd;

            if (testLine.length <= maxWidth) {
                currentLine = testLine;
            } else {
                if (currentLine) {
                    lines.push(currentLine);
                }
                currentLine = testAdd;
            }
        });

        if (currentLine) {
            lines.push(currentLine);
        }

        return lines.join('\n');
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
            PERTIDialog.success('common.copied', null, {}, { timer: 1000 });
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
                if (profile.oi) {CONFIG.userOI = profile.oi;}
                if (profile.facility) {CONFIG.userFacility = profile.facility;}
                // Load name/cid from localStorage if not set from server session
                if (profile.name && !CONFIG.userName) {CONFIG.userName = profile.name;}
                if (profile.cid && !CONFIG.userCid) {CONFIG.userCid = profile.cid;}
            } catch (e) {
                console.warn('Failed to load user profile:', e);
            }
        }

        // Update user info display with localStorage data
        updateUserInfoDisplay();

        // Check if profile is complete
        const profileComplete = isProfileComplete();

        // Show profile modal if incomplete
        if (!profileComplete && !localStorage.getItem('tmi_profile_dismissed')) {
            setTimeout(() => {
                showProfileModal();
            }, 1000);
        }
    }

    function isProfileComplete() {
        // Profile is complete if we have name, CID, OI, and facility
        const hasName = CONFIG.userName || false;
        const hasCid = CONFIG.userCid || false;
        const hasOI = CONFIG.userOI && CONFIG.userOI.length >= 2;
        const hasFacility = CONFIG.userFacility || false;
        return hasName && hasCid && hasOI && hasFacility;
    }

    function updateUserInfoDisplay() {
        const $label = $('#userInfoLabel');
        const $display = $('#userInfoDisplay');

        if (!$display.length) {return;}

        // Build display name
        let displayName = CONFIG.userName;
        if (!displayName) {
            // Try localStorage
            const savedProfile = localStorage.getItem('tmi_user_profile');
            if (savedProfile) {
                try {
                    const profile = JSON.parse(savedProfile);
                    displayName = profile.name;
                } catch (e) {}
            }
        }

        if (displayName) {
            $label.text('User Profile');
            $display.html(`<i class="fas fa-user-edit mr-1 small text-muted"></i>${displayName}`);
        } else {
            $label.text('User Profile');
            $display.html('<i class="fas fa-user-edit mr-1 small text-muted"></i><span class="text-warning">Set Up Profile</span>');
        }
    }

    function showProfileModal() {
        // Pre-populate fields from CONFIG or localStorage
        const savedProfile = localStorage.getItem('tmi_user_profile');
        let profile = {};
        if (savedProfile) {
            try { profile = JSON.parse(savedProfile); } catch(e) {}
        }

        // Pre-populate name/cid if editable (not readonly from server)
        const $nameField = $('#profileName');
        const $cidField = $('#profileCid');
        if (!$nameField.attr('readonly')) {
            $nameField.val(CONFIG.userName || profile.name || '');
        }
        if (!$cidField.attr('readonly')) {
            $cidField.val(CONFIG.userCid || profile.cid || '');
        }

        if (CONFIG.userOI || profile.oi) {
            $('#profileOI').val(CONFIG.userOI || profile.oi);
        }
        if (CONFIG.userFacility || profile.facility) {
            $('#profileFacility').val(CONFIG.userFacility || profile.facility);
        }
        $('#userProfileModal').modal('show');
    }

    function saveProfile() {
        const oi = ($('#profileOI').val() || '').trim().toUpperCase();
        const facility = $('#profileFacility').val() || '';

        // Get name/cid from fields (only if not readonly)
        const $nameField = $('#profileName');
        const $cidField = $('#profileCid');
        const name = ($nameField.val() || '').trim();
        const cid = ($cidField.val() || '').trim();

        // Validate all required fields
        const nameEditable = !$nameField.attr('readonly');
        const cidEditable = !$cidField.attr('readonly');

        // If editable, name and cid are required
        if (nameEditable && !name) {
            PERTIDialog.warning('validation.nameRequired', 'validation.enterName');
            return;
        }
        if (cidEditable && !cid) {
            PERTIDialog.warning('validation.cidRequired', 'validation.enterCid');
            return;
        }
        if (!oi || oi.length < 2 || oi.length > 3) {
            PERTIDialog.warning('validation.invalidOI', 'validation.oiLength');
            return;
        }
        if (!facility) {
            PERTIDialog.warning('validation.facilityRequired', 'validation.selectFacility');
            return;
        }

        // Build profile object
        const profile = { oi, facility };
        if (nameEditable) {profile.name = name;}
        if (cidEditable) {profile.cid = cid;}

        // Preserve existing name/cid if server-set
        const existingProfile = localStorage.getItem('tmi_user_profile');
        if (existingProfile) {
            try {
                const existing = JSON.parse(existingProfile);
                if (!nameEditable && existing.name) {profile.name = existing.name;}
                if (!cidEditable && existing.cid) {profile.cid = existing.cid;}
            } catch(e) {}
        }

        localStorage.setItem('tmi_user_profile', JSON.stringify(profile));

        // Update CONFIG
        CONFIG.userOI = oi;
        CONFIG.userFacility = facility;
        if (name) {CONFIG.userName = name;}
        if (cid) {CONFIG.userCid = cid;}

        // Update user info display
        updateUserInfoDisplay();

        // Close modal
        $('#userProfileModal').modal('hide');

        // Update any open forms with new facility as default
        if (facility && $('#ntml_req_facility').length && !$('#ntml_req_facility').val()) {
            $('#ntml_req_facility').val(facility);
        }

        PERTIDialog.success('tmiPublish.profile.saved', 'tmiPublish.profile.savedText');
    }

    function getUserFacility() {
        return CONFIG.userFacility || '';
    }

    // ===========================================
    // Coordination Proposals
    // ===========================================

    let coordinationLoaded = false;

    function initCoordinationTab() {
        // Load proposals when tab is shown
        $('a[data-toggle="tab"][href="#coordinationPanel"]').on('shown.bs.tab', function() {
            if (!coordinationLoaded) {
                loadProposals();
                coordinationLoaded = true;
            }
        });

        // Refresh button
        $('#refreshProposals').on('click', function() {
            loadProposals();
        });

        // Extend deadline button (delegated event)
        $(document).on('click', '.extend-deadline-btn', function() {
            const proposalId = $(this).data('proposal-id');
            const currentDeadline = $(this).data('current-deadline');
            showExtendDeadlineDialog(proposalId, currentDeadline);
        });

        // Approve/Deny buttons (delegated events)
        $(document).on('click', '.approve-proposal-btn', function() {
            const proposalId = $(this).data('proposal-id');
            handleProposalAction(proposalId, 'APPROVE');
        });

        $(document).on('click', '.deny-proposal-btn', function() {
            const proposalId = $(this).data('proposal-id');
            handleProposalAction(proposalId, 'DENY');
        });

        // Reopen button (for resolved proposals)
        $(document).on('click', '.reopen-proposal-btn', function() {
            const proposalId = $(this).data('proposal-id');
            handleReopenProposal(proposalId);
        });

        // Edit proposal button
        $(document).on('click', '.edit-proposal-btn', function() {
            const proposalId = $(this).data('proposal-id');
            handleEditProposal(proposalId);
        });

        // Publish approved proposal button
        $(document).on('click', '.publish-proposal-btn', function() {
            const proposalId = $(this).data('proposal-id');
            const entryType = $(this).data('entry-type') || 'TMI';
            const ctlElement = $(this).data('ctl-element') || '';
            handlePublishProposal(proposalId, entryType, ctlElement);
        });

        $(document).on('click', '.cancel-proposal-btn', function() {
            const proposalId = $(this).data('proposal-id');
            const entryType = $(this).data('entry-type') || 'TMI';
            const ctlElement = $(this).data('ctl-element') || '';
            handleCancelProposal(proposalId, entryType, ctlElement);
        });

        // Publish Now button for SCHEDULED proposals
        $(document).on('click', '.publish-now-btn', function() {
            const proposalId = $(this).data('proposal-id');
            const entryType = $(this).data('entry-type') || 'TMI';
            const ctlElement = $(this).data('ctl-element') || '';
            handlePublishNow(proposalId, entryType, ctlElement);
        });

        // Batch publish all approved proposals
        $('#batchPublishApproved').on('click', function() {
            if (!isUserLoggedIn()) {
                PERTIDialog.warning('validation.loginRequired', 'validation.mustBeLoggedIn');
                return;
            }
            handleBatchPublish();
        });
    }

    function handleReopenProposal(proposalId) {
        PERTIDialog.show({
            titleKey: 'tmiPublish.reopen.title',
            html: `<p>Reopen Proposal #${proposalId} for coordination?</p>
                   <p class="small text-muted">This will reset all facility approvals and set status back to PENDING.</p>
                   <div class="form-group text-left mt-3">
                       <label class="small">Reason (optional):</label>
                       <input type="text" id="reopen_reason" class="form-control form-control-sm" placeholder="e.g., Conditions changed">
                   </div>`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#f0ad4e',
            confirmButtonText: '<i class="fas fa-undo mr-1"></i> Reopen',
            cancelKey: 'common.cancel',
        }).then((result) => {
            if (result.isConfirmed) {
                const reason = $('#reopen_reason').val();
                submitReopenProposal(proposalId, reason);
            }
        });
    }

    function submitReopenProposal(proposalId, reason) {
        PERTIDialog.loading('tmiPublish.reopen.reopening');

        $.ajax({
            url: 'api/mgt/tmi/coordinate.php',
            method: 'DELETE',
            contentType: 'application/json',
            data: JSON.stringify({
                proposal_id: proposalId,
                action: 'REOPEN',
                user_cid: CONFIG.userCid,
                user_name: CONFIG.userName || 'DCC',
                reason: reason,
            }),
            success: function(response) {
                PERTIDialog.close();
                if (response.success) {
                    PERTIDialog.success('tmiPublish.reopen.reopened', null, {}, {
                        text: `Proposal #${proposalId} is now pending coordination.`,
                        timer: 2000,
                    });
                    loadProposals();
                } else {
                    PERTIDialog.error('common.error', null, {}, { text: response.error || PERTII18n.t('error.updateFailed', { resource: 'proposal' }) });
                }
            },
            error: function(xhr) {
                PERTIDialog.close();
                PERTIDialog.error('common.error', null, {}, { text: xhr.responseJSON?.error || PERTII18n.t('error.connectionFailed') });
            },
        });
    }

    // =========================================
    // Publish Approved Proposal
    // =========================================

    function handlePublishProposal(proposalId, entryType, ctlElement) {
        const publishTitle = typeof PERTII18n !== 'undefined'
            ? PERTII18n.t('tmiPublish.publish.publishToDiscord')
            : 'Publish to Discord?';

        PERTIDialog.show({
            title: `<i class="fas fa-broadcast-tower text-success"></i> ${publishTitle}`,
            html: `
                <div class="text-left">
                    <p>Ready to publish <strong>${escapeHtml(entryType)} ${escapeHtml(ctlElement)}</strong> to production Discord channels?</p>
                    <div class="alert alert-info small py-2">
                        <i class="fas fa-info-circle mr-1"></i>
                        This proposal has been fully approved by all required facilities.
                    </div>
                </div>
            `,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#28a745',
            confirmButtonText: '<i class="fas fa-broadcast-tower mr-1"></i> Publish Now',
            cancelKey: 'common.cancel',
        }).then((result) => {
            if (result.isConfirmed) {
                submitPublishProposal(proposalId);
            }
        });
    }

    function submitPublishProposal(proposalId) {
        PERTIDialog.loading('tmiPublish.publish.publishing');

        $.ajax({
            url: 'api/mgt/tmi/coordinate.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                action: 'PUBLISH',
                proposal_id: proposalId,
                user_cid: CONFIG.userCid,
                user_name: CONFIG.userName || 'DCC',
            }),
            success: function(response) {
                PERTIDialog.close();
                if (response.success) {
                    const activation = response.activation || {};
                    PERTIDialog.success('tmiPublish.publish.published', null, {}, {
                        html: `
                            <p>TMI published successfully.</p>
                            ${activation.tmi_entry_id ? `<p class="small text-muted">Entry ID: #${activation.tmi_entry_id}</p>` : ''}
                        `,
                        timer: 3000,
                        showConfirmButton: true,
                    });
                    loadProposals(); // Refresh the list
                } else {
                    PERTIDialog.error('common.error', null, {}, { text: response.error || PERTII18n.t('tmiPublish.publish.publishFailed') });
                }
            },
            error: function(xhr) {
                PERTIDialog.close();
                PERTIDialog.error('common.error', null, {}, { text: xhr.responseJSON?.error || PERTII18n.t('error.connectionFailed') });
            },
        });
    }

    // =========================================
    // Publish Now (for SCHEDULED proposals)
    // =========================================

    function handlePublishNow(proposalId, entryType, ctlElement) {
        PERTIDialog.confirm(
            'tmiPublish.publish.publishNow', null, {},
            {
                html: `<p>Immediately publish this scheduled ${entryType} to Discord?</p>
                       <p class="small text-muted">The proposal will be activated and posted to the advisories channel.</p>`,
                confirmButtonColor: '#28a745',
                confirmButtonText: '<i class="fas fa-broadcast-tower mr-1"></i> Publish Now',
            },
        ).then((result) => {
            if (result.isConfirmed) {
                PERTIDialog.loading('tmiPublish.publish.publishing');

                $.ajax({
                    url: 'api/mgt/tmi/coordinate.php',
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({
                        action: 'DCC_ACTION',
                        dcc_action: 'PUBLISH_NOW',
                        proposal_id: proposalId,
                        user_cid: CONFIG.userCid,
                        user_name: CONFIG.userName || 'DCC',
                    }),
                    success: function(response) {
                        PERTIDialog.close();
                        if (response.success) {
                            PERTIDialog.success('tmiPublish.publish.published', null, {}, {
                                html: `<p>${entryType} published to Discord.</p>`,
                                timer: 3000,
                                showConfirmButton: true,
                            });
                            loadProposals(); // Refresh the list
                        } else {
                            PERTIDialog.error('common.error', null, {}, { text: response.error || PERTII18n.t('tmiPublish.publish.publishFailed') });
                        }
                    },
                    error: function(xhr) {
                        PERTIDialog.close();
                        PERTIDialog.error('common.error', null, {}, { text: xhr.responseJSON?.error || PERTII18n.t('error.connectionFailed') });
                    },
                });
            }
        });
    }

    // =========================================
    // Batch Publish All Approved Proposals
    // =========================================

    function handleBatchPublish() {
        // Get all approved proposal IDs from the table
        const approvedIds = [];
        $('#proposalsTableBody tr.table-success').each(function() {
            const $publishBtn = $(this).find('.publish-proposal-btn');
            if ($publishBtn.length) {
                const id = $publishBtn.data('proposal-id');
                if (id) {approvedIds.push(id);}
            }
        });

        if (approvedIds.length === 0) {
            PERTIDialog.info('validation.noApprovedProposals', 'validation.noApprovedProposalsText');
            return;
        }

        PERTIDialog.show({
            title: '<i class="fas fa-broadcast-tower text-success"></i> Batch Publish?',
            html: `
                <div class="text-left">
                    <p>Publish <strong>${approvedIds.length}</strong> approved proposal(s) to Discord?</p>
                    <div class="alert alert-info small py-2">
                        <i class="fas fa-info-circle mr-1"></i>
                        All TMIs will be created and posted to production channels.
                    </div>
                    <div class="small text-muted">
                        Proposal IDs: ${approvedIds.join(', ')}
                    </div>
                </div>
            `,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#28a745',
            confirmButtonText: `<i class="fas fa-broadcast-tower mr-1"></i> Publish All (${approvedIds.length})`,
            cancelKey: 'common.cancel',
        }).then((result) => {
            if (result.isConfirmed) {
                submitBatchPublish(approvedIds);
            }
        });
    }

    function submitBatchPublish(proposalIds) {
        PERTIDialog.show({
            titleKey: 'tmiPublish.publish.publishing',
            html: `<p>Publishing ${proposalIds.length} proposal(s)...</p><p class="small text-muted" id="batchPublishProgress">Starting...</p>`,
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading(),
        });

        $.ajax({
            url: 'api/mgt/tmi/coordinate.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                action: 'BATCH_PUBLISH',
                proposal_ids: proposalIds,
                user_cid: CONFIG.userCid,
                user_name: CONFIG.userName || 'DCC',
            }),
            success: function(response) {
                PERTIDialog.close();
                if (response.success) {
                    PERTIDialog.success('tmiPublish.publish.batchComplete', null, {}, {
                        html: `
                            <p><strong>${response.published || 0}</strong> of <strong>${response.total || 0}</strong> proposals published.</p>
                            ${response.failed > 0 ? `<p class="text-warning small">${response.failed} failed</p>` : ''}
                        `,
                        timer: 4000,
                        showConfirmButton: true,
                    });
                    loadProposals(); // Refresh the list
                } else {
                    // Partial success
                    const successCount = response.published || 0;
                    const failCount = response.failed || 0;
                    const title = typeof PERTII18n !== 'undefined'
                        ? (successCount > 0 ? PERTII18n.t('tmiPublish.publish.partialSuccess') : PERTII18n.t('tmiPublish.publish.batchFailed'))
                        : (successCount > 0 ? 'Partial Success' : 'Batch Publish Failed');
                    PERTIDialog.show({
                        icon: successCount > 0 ? 'warning' : 'error',
                        title: title,
                        html: `
                            <p><strong>${successCount}</strong> published, <strong>${failCount}</strong> failed</p>
                            ${Object.entries(response.results || {}).map(([id, r]) =>
        `<div class="small ${r.success ? 'text-success' : 'text-danger'}">
                                    #${id}: ${r.success ? 'OK' : r.error}
                                </div>`,
    ).join('')}
                        `,
                    });
                    loadProposals();
                }
            },
            error: function(xhr) {
                PERTIDialog.close();
                PERTIDialog.error('common.error', null, {}, { text: xhr.responseJSON?.error || PERTII18n.t('error.submitFailed', { resource: 'batch publish' }) });
            },
        });
    }

    function handleCancelProposal(proposalId, entryType, ctlElement) {
        PERTIDialog.show({
            title: '<i class="fas fa-trash text-danger"></i> Cancel Proposal?',
            html: `
                <div class="text-left">
                    <p>Cancel <strong>${escapeHtml(entryType)} ${escapeHtml(ctlElement)}</strong> proposal?</p>
                    <div class="alert alert-warning small py-2">
                        <i class="fas fa-exclamation-triangle mr-1"></i>
                        This will permanently cancel the proposal. It cannot be undone.
                    </div>
                    <div class="form-group mt-3">
                        <label class="small">Reason (optional):</label>
                        <input type="text" id="cancel_reason" class="form-control form-control-sm" placeholder="e.g., No longer needed">
                    </div>
                </div>
            `,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            confirmButtonText: '<i class="fas fa-trash mr-1"></i> Cancel Proposal',
            cancelButtonText: 'Keep',
        }).then((result) => {
            if (result.isConfirmed) {
                const reason = $('#cancel_reason').val();
                submitCancelProposal(proposalId, reason);
            }
        });
    }

    function submitCancelProposal(proposalId, reason) {
        PERTIDialog.loading('tmiPublish.cancelTmi.cancelling');

        $.ajax({
            url: 'api/mgt/tmi/coordinate.php',
            method: 'DELETE',
            contentType: 'application/json',
            data: JSON.stringify({
                proposal_id: proposalId,
                action: 'CANCEL',
                user_cid: CONFIG.userCid,
                user_name: CONFIG.userName || 'DCC',
                reason: reason,
            }),
            success: function(response) {
                PERTIDialog.close();
                if (response.success) {
                    PERTIDialog.success('dialog.success.deleted', null, {}, {
                        text: `Proposal #${proposalId} has been cancelled.`,
                        timer: 2000,
                    });
                    loadProposals();
                } else {
                    PERTIDialog.error('common.error', null, {}, { text: response.error || PERTII18n.t('tmiPublish.cancelTmi.failed') });
                }
            },
            error: function(xhr) {
                PERTIDialog.close();
                PERTIDialog.error('common.error', null, {}, { text: xhr.responseJSON?.error || PERTII18n.t('error.connectionFailed') });
            },
        });
    }

    // =========================================
    // Edit Proposal
    // =========================================

    function handleEditProposal(proposalId) {
        // First, fetch the full proposal data
        PERTIDialog.loading('dialog.loading');

        $.ajax({
            url: `api/mgt/tmi/coordinate.php?proposal_id=${proposalId}`,
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                PERTIDialog.close();
                if (response.success && response.proposal) {
                    showEditProposalDialog(response.proposal, response.facilities || []);
                } else {
                    PERTIDialog.error('common.error', null, {}, { text: response.error || PERTII18n.t('error.loadFailed', { resource: 'proposal' }) });
                }
            },
            error: function(xhr) {
                PERTIDialog.close();
                PERTIDialog.error('common.error', 'error.loadFailed', { resource: 'proposal data' });
            },
        });
    }

    function showEditProposalDialog(proposal, facilities) {
        const entryData = proposal.entry_data || {};
        // Format UTC datetime string for datetime-local input without timezone conversion
        // Database stores UTC times, so we keep them as-is for the datetime-local input
        const formatForInput = (dateStr) => {
            if (!dateStr) {return '';}
            // Remove any trailing Z or timezone info, replace space with T
            // Handles: "2026-01-28 03:45:00", "2026-01-28T03:45:00Z", "2026-01-28T03:45"
            const s = dateStr.toString().replace(' ', 'T').replace('Z', '');
            // Ensure we have YYYY-MM-DDTHH:MM format for datetime-local
            if (s.length >= 16) {return s.slice(0, 16);}
            // Try parsing if format is unexpected
            const d = new Date(dateStr + 'Z'); // Append Z to parse as UTC
            if (isNaN(d.getTime())) {return '';}
            // Manual format to avoid local timezone offset
            const pad = n => n.toString().padStart(2, '0');
            return `${d.getUTCFullYear()}-${pad(d.getUTCMonth()+1)}-${pad(d.getUTCDate())}T${pad(d.getUTCHours())}:${pad(d.getUTCMinutes())}`;
        };

        Swal.fire({
            title: `<i class="fas fa-edit text-info"></i> Edit Proposal #${proposal.proposal_id}`,
            html: `
                <div class="text-left" style="max-height: 60vh; overflow-y: auto;">
                    <div class="alert alert-warning small py-2">
                        <i class="fas fa-exclamation-triangle mr-1"></i>
                        <strong>Warning:</strong> Editing will clear all facility approvals and restart coordination.
                    </div>
                    <div class="row mb-2">
                        <div class="col-6">
                            <label class="small font-weight-bold">Type</label>
                            <input type="text" class="form-control form-control-sm bg-light" value="${escapeHtml(proposal.entry_type || 'TMI')}" readonly>
                        </div>
                        <div class="col-6">
                            <label class="small font-weight-bold">Control Element</label>
                            <input type="text" id="editPropCtlElement" class="form-control form-control-sm text-uppercase"
                                   value="${escapeHtml(proposal.ctl_element || entryData.ctl_element || '')}">
                        </div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-6">
                            <label class="small font-weight-bold">Requesting Facility</label>
                            <input type="text" id="editPropReqFac" class="form-control form-control-sm text-uppercase"
                                   value="${escapeHtml(proposal.requesting_facility || entryData.requesting_facility || '')}">
                        </div>
                        <div class="col-6">
                            <label class="small font-weight-bold">Providing Facility</label>
                            <input type="text" id="editPropProvFac" class="form-control form-control-sm text-uppercase"
                                   value="${escapeHtml(proposal.providing_facility || entryData.providing_facility || '')}">
                        </div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-6">
                            <label class="small font-weight-bold">Valid From (UTC)</label>
                            <input type="datetime-local" id="editPropValidFrom" class="form-control form-control-sm"
                                   value="${formatForInput(proposal.valid_from || entryData.valid_from)}">
                        </div>
                        <div class="col-6">
                            <label class="small font-weight-bold">Valid Until (UTC)</label>
                            <input type="datetime-local" id="editPropValidUntil" class="form-control form-control-sm"
                                   value="${formatForInput(proposal.valid_until || entryData.valid_until)}">
                        </div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-6">
                            <label class="small font-weight-bold">Restriction Value</label>
                            <input type="number" id="editPropValue" class="form-control form-control-sm"
                                   value="${entryData.restriction_value || entryData.value || ''}" placeholder="e.g., 20">
                        </div>
                        <div class="col-6">
                            <label class="small font-weight-bold">Unit</label>
                            <select id="editPropUnit" class="form-control form-control-sm">
                                <option value="MIT" ${(entryData.restriction_unit || entryData.unit) === 'MIT' ? 'selected' : ''}>MIT (miles)</option>
                                <option value="MINIT" ${(entryData.restriction_unit || entryData.unit) === 'MINIT' ? 'selected' : ''}>MINIT (minutes)</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group mb-2">
                        <label class="small font-weight-bold">Restriction Text</label>
                        <textarea id="editPropRawText" class="form-control form-control-sm" rows="4"
                                  style="font-family: monospace; font-size: 11px;">${escapeHtml(proposal.raw_text || '')}</textarea>
                        <small class="text-muted">This is the NTML text that facilities will see</small>
                    </div>
                    <div class="form-group mb-2">
                        <label class="small font-weight-bold">Edit Reason</label>
                        <input type="text" id="editPropReason" class="form-control form-control-sm"
                               placeholder="Why is this proposal being edited?" required>
                    </div>
                </div>
            `,
            width: 650,
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-save"></i> Save & Restart Coordination',
            confirmButtonColor: '#17a2b8',
            cancelButtonText: 'Cancel',
            didOpen: () => {
                // Auto-update raw_text when restriction value or unit changes
                const valueInput = document.getElementById('editPropValue');
                const unitSelect = document.getElementById('editPropUnit');
                const rawTextArea = document.getElementById('editPropRawText');

                // Store original values
                const origValue = valueInput.value;
                const origUnit = unitSelect.value;

                const updateRawText = () => {
                    const newValue = valueInput.value;
                    const newUnit = unitSelect.value;
                    const rawText = rawTextArea.value;

                    // Find pattern like "4MINIT" or "20MIT" and replace with new value/unit
                    // Match: number followed by MIT or MINIT (case insensitive)
                    const pattern = new RegExp('(\\d+)(MIT|MINIT)', 'gi');
                    const newRawText = rawText.replace(pattern, (match, num, unit) => {
                        return newValue + newUnit;
                    });

                    if (newRawText !== rawText) {
                        rawTextArea.value = newRawText;
                    }
                };

                valueInput.addEventListener('change', updateRawText);
                valueInput.addEventListener('input', updateRawText);
                unitSelect.addEventListener('change', updateRawText);
            },
            preConfirm: () => {
                const reason = document.getElementById('editPropReason').value.trim();
                if (!reason) {
                    Swal.showValidationMessage('Please provide a reason for the edit');
                    return false;
                }
                return {
                    ctl_element: document.getElementById('editPropCtlElement').value.trim().toUpperCase(),
                    requesting_facility: document.getElementById('editPropReqFac').value.trim().toUpperCase(),
                    providing_facility: document.getElementById('editPropProvFac').value.trim().toUpperCase(),
                    valid_from: document.getElementById('editPropValidFrom').value,
                    valid_until: document.getElementById('editPropValidUntil').value,
                    restriction_value: document.getElementById('editPropValue').value,
                    restriction_unit: document.getElementById('editPropUnit').value,
                    raw_text: document.getElementById('editPropRawText').value,
                    edit_reason: reason,
                };
            },
        }).then((result) => {
            if (result.isConfirmed) {
                submitProposalEdit(proposal.proposal_id, result.value);
            }
        });
    }

    function submitProposalEdit(proposalId, updates) {
        PERTIDialog.loading('tmiPublish.saving', 'Updating proposal and restarting coordination');

        $.ajax({
            url: 'api/mgt/tmi/coordinate.php',
            method: 'PATCH',
            contentType: 'application/json',
            data: JSON.stringify({
                action: 'EDIT_PROPOSAL',
                proposal_id: proposalId,
                updates: updates,
                user_cid: CONFIG.userCid,
                user_name: CONFIG.userName || 'Unknown',
            }),
            success: function(response) {
                PERTIDialog.close();
                if (response.success) {
                    const updatedText = typeof PERTII18n !== 'undefined'
                        ? PERTII18n.t('tmiPublish.proposal.updatedText', { id: proposalId })
                        : `Proposal #${proposalId} has been updated.`;
                    const approvalsCleared = typeof PERTII18n !== 'undefined'
                        ? PERTII18n.t('tmiPublish.proposal.approvalsCleared')
                        : 'All facility approvals have been cleared and coordination has restarted.';
                    PERTIDialog.success('tmiPublish.proposal.updated', null, {}, {
                        html: `<p>${updatedText}</p><p class="small text-muted">${approvalsCleared}</p>`,
                        timer: 3000,
                    });
                    loadProposals();
                } else {
                    PERTIDialog.error('common.error', null, {}, { text: response.error || PERTII18n.t('error.updateFailed', { resource: 'proposal' }) });
                }
            },
            error: function(xhr) {
                PERTIDialog.close();
                PERTIDialog.error('common.error', null, {}, { text: xhr.responseJSON?.error || PERTII18n.t('error.updateFailed', { resource: 'proposal' }) });
            },
        });
    }

    function loadProposals() {
        // Show loading
        $('#proposalsTableBody').html(`
            <tr>
                <td colspan="8" class="text-center text-muted py-4">
                    <i class="fas fa-spinner fa-spin fa-2x mb-2"></i><br>
                    Loading proposals...
                </td>
            </tr>
        `);

        // Fetch pending proposals
        $.ajax({
            url: 'api/mgt/tmi/coordinate.php?list=pending',
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    displayProposals(response.proposals || [], 'proposalsTableBody', true);
                    const pendingCount = (response.proposals || []).length;
                    $('#pendingCount').text(pendingCount);
                    if (pendingCount > 0) {
                        $('#pendingProposalsBadge').text(pendingCount).show();
                    } else {
                        $('#pendingProposalsBadge').hide();
                    }
                } else {
                    showProposalsError('proposalsTableBody', response.error || 'Failed to load proposals');
                }
            },
            error: function(xhr, status, error) {
                showProposalsError('proposalsTableBody', 'Failed to connect to server');
            },
        });

        // Fetch recent proposals (all)
        $.ajax({
            url: 'api/mgt/tmi/coordinate.php?list=all',
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    // Filter to show only resolved (not pending)
                    const resolved = (response.proposals || []).filter(p => p.status !== 'PENDING');
                    displayProposals(resolved, 'recentProposalsTableBody', false);
                    $('#recentCount').text(resolved.length);
                }
            },
            error: function() {
                // Silent fail for recent
            },
        });
    }

    function displayProposals(proposals, containerId, isPending) {
        const $tbody = $('#' + containerId);

        if (!proposals || proposals.length === 0) {
            const colSpan = isPending ? 9 : 7;
            $tbody.html(`
                <tr>
                    <td colspan="${colSpan}" class="text-center text-muted py-3">
                        <em>No ${isPending ? 'pending' : 'recent'} proposals</em>
                    </td>
                </tr>
            `);
            // Hide batch publish button when no proposals
            if (isPending) {
                $('#batchPublishApproved').hide();
            }
            return;
        }

        let html = '';
        proposals.forEach(p => {
            const statusBadge = getStatusBadge(p.status, p.dcc_override, p.dcc_override_action);
            const approvalProgress = isPending ? `${p.approved_count || 0}/${p.facility_count || 0}` : '';
            const deadline = p.approval_deadline_utc ? formatDeadline(p.approval_deadline_utc) : '--';
            const resolvedAt = p.updated_at ? formatDateTime(p.updated_at) : '--';

            if (isPending) {
                // Determine if this proposal is APPROVED (ready for publication) or still PENDING
                const isApproved = p.status === 'APPROVED';

                html += `
                    <tr class="${isApproved ? 'table-success' : ''}">
                        <td class="small font-weight-bold">#${p.proposal_id}</td>
                        <td><span class="badge badge-primary">${escapeHtml(p.entry_type || 'TMI')}</span></td>
                        <td class="small">${escapeHtml(p.ctl_element || p.requesting_facility || '--')}</td>
                        <td class="small text-truncate" style="max-width: 250px;" title="${escapeHtml(p.raw_text || '')}">${escapeHtml(p.raw_text || '--')}</td>
                        <td class="small">${escapeHtml(p.created_by_name || 'Unknown')}</td>
                        <td class="small">${isApproved ? '<span class="text-success">--</span>' : deadline}</td>
                        <td><span class="badge ${isApproved ? 'badge-success' : 'badge-info'}">${isApproved ? 'Ready' : approvalProgress}</span></td>
                        <td>${statusBadge}</td>
                        <td class="text-nowrap" style="white-space: nowrap;">
                            ${isApproved ? `
                                <div class="btn-group btn-group-sm" role="group">
                                ${isUserLoggedIn() ? `
                                    <button class="btn btn-outline-info edit-proposal-btn"
                                            data-proposal-id="${p.proposal_id}"
                                            title="Edit proposal (restarts coordination)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn btn-success publish-proposal-btn"
                                            data-proposal-id="${p.proposal_id}"
                                            data-entry-type="${escapeHtml(p.entry_type || 'TMI')}"
                                            data-ctl-element="${escapeHtml(p.ctl_element || '')}"
                                            title="Publish to Discord">
                                        <i class="fas fa-broadcast-tower"></i>
                                    </button>
                                    <button class="btn btn-outline-danger cancel-proposal-btn"
                                            data-proposal-id="${p.proposal_id}"
                                            data-entry-type="${escapeHtml(p.entry_type || 'TMI')}"
                                            data-ctl-element="${escapeHtml(p.ctl_element || '')}"
                                            title="Cancel proposal">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                ` : `
                                    <button class="btn btn-outline-secondary" disabled title="Login required">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn btn-secondary" disabled title="Login required to publish">
                                        <i class="fas fa-broadcast-tower"></i>
                                    </button>
                                `}
                                </div>
                            ` : `
                                <div class="btn-group btn-group-sm" role="group">
                                ${isUserLoggedIn() ? `
                                    <button class="btn btn-outline-info edit-proposal-btn"
                                            data-proposal-id="${p.proposal_id}"
                                            title="Edit proposal (clears approvals)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn btn-outline-success approve-proposal-btn"
                                            data-proposal-id="${p.proposal_id}"
                                            title="Approve (DCC Override)">
                                        <i class="fas fa-check"></i>
                                    </button>
                                    <button class="btn btn-outline-danger deny-proposal-btn"
                                            data-proposal-id="${p.proposal_id}"
                                            title="Deny (DCC Override)">
                                        <i class="fas fa-times"></i>
                                    </button>
                                    <button class="btn btn-outline-primary extend-deadline-btn"
                                            data-proposal-id="${p.proposal_id}"
                                            data-current-deadline="${escapeHtml(p.approval_deadline_utc || '')}"
                                            title="Extend deadline">
                                        <i class="fas fa-clock"></i>
                                    </button>
                                    <button class="btn btn-outline-secondary cancel-proposal-btn"
                                            data-proposal-id="${p.proposal_id}"
                                            data-entry-type="${escapeHtml(p.entry_type || 'TMI')}"
                                            data-ctl-element="${escapeHtml(p.ctl_element || '')}"
                                            title="Cancel proposal">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                ` : `
                                    <button class="btn btn-outline-secondary" disabled title="Login required">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn btn-outline-secondary" disabled title="Login required">
                                        <i class="fas fa-check"></i>
                                    </button>
                                    <button class="btn btn-outline-secondary" disabled title="Login required">
                                        <i class="fas fa-times"></i>
                                    </button>
                                    <button class="btn btn-outline-secondary extend-deadline-btn"
                                            data-proposal-id="${p.proposal_id}"
                                            data-current-deadline="${escapeHtml(p.approval_deadline_utc || '')}"
                                            title="Extend deadline">
                                        <i class="fas fa-clock"></i>
                                    </button>
                                `}
                                </div>
                            `}
                        </td>
                    </tr>
                `;
            } else {
                // For resolved proposals, show reopen button if not cancelled
                const canReopen = p.status !== 'CANCELLED';
                const isScheduled = p.status === 'SCHEDULED';
                html += `
                    <tr>
                        <td class="small font-weight-bold">#${p.proposal_id}</td>
                        <td><span class="badge badge-primary">${escapeHtml(p.entry_type || 'TMI')}</span></td>
                        <td class="small">${escapeHtml(p.ctl_element || p.requesting_facility || '--')}</td>
                        <td class="small text-truncate" style="max-width: 250px;" title="${escapeHtml(p.raw_text || '')}">${escapeHtml(p.raw_text || '--')}</td>
                        <td class="small">${escapeHtml(p.created_by_name || 'Unknown')}</td>
                        <td>${statusBadge}</td>
                        <td class="small">${resolvedAt}</td>
                        <td class="text-nowrap">
                            ${isScheduled && isUserLoggedIn() ? `
                                <button class="btn btn-sm btn-success publish-now-btn"
                                        data-proposal-id="${p.proposal_id}"
                                        data-entry-type="${escapeHtml(p.entry_type || 'TMI')}"
                                        data-ctl-element="${escapeHtml(p.ctl_element || '')}"
                                        title="Publish to Discord Now">
                                    <i class="fas fa-broadcast-tower mr-1"></i>Publish Now
                                </button>
                            ` : ''}
                            ${canReopen ? `
                                <button class="btn btn-sm btn-outline-warning reopen-proposal-btn"
                                        data-proposal-id="${p.proposal_id}"
                                        title="Reopen for coordination">
                                    <i class="fas fa-undo"></i>
                                </button>
                            ` : ''}
                        </td>
                    </tr>
                `;
            }
        });

        $tbody.html(html);

        // Show/hide batch publish button based on approved count (only for pending table)
        if (isPending) {
            const approvedCount = proposals.filter(p => p.status === 'APPROVED').length;
            const $batchBtn = $('#batchPublishApproved');
            if (approvedCount > 0 && isUserLoggedIn()) {
                $batchBtn.text(`Publish All Approved (${approvedCount})`).prepend('<i class="fas fa-broadcast-tower mr-1"></i>').show();
            } else {
                $batchBtn.hide();
            }
        }
    }

    function getStatusBadge(status, dccOverride, dccAction) {
        if (dccOverride) {
            if (dccAction === 'APPROVE') {
                return '<span class="badge badge-success"><i class="fas fa-gavel mr-1"></i>DCC Approved</span>';
            } else if (dccAction === 'DENY') {
                return '<span class="badge badge-danger"><i class="fas fa-gavel mr-1"></i>DCC Denied</span>';
            }
        }

        switch (status) {
            case 'PENDING':
                return '<span class="badge badge-warning"><i class="fas fa-clock mr-1"></i>Pending</span>';
            case 'APPROVED':
                return '<span class="badge badge-success"><i class="fas fa-check mr-1"></i>Approved</span>';
            case 'ACTIVATED':
                return '<span class="badge badge-success"><i class="fas fa-broadcast-tower mr-1"></i>Activated</span>';
            case 'SCHEDULED':
                return '<span class="badge badge-info"><i class="fas fa-calendar mr-1"></i>Scheduled</span>';
            case 'DENIED':
                return '<span class="badge badge-danger"><i class="fas fa-times mr-1"></i>Denied</span>';
            case 'EXPIRED':
                return '<span class="badge badge-secondary"><i class="fas fa-hourglass-end mr-1"></i>Expired</span>';
            default:
                return `<span class="badge badge-secondary">${escapeHtml(status || 'Unknown')}</span>`;
        }
    }

    function formatDeadline(dateStr) {
        try {
            // Database returns UTC datetime without Z suffix, so append it
            // to ensure JavaScript interprets it as UTC, not local time
            let dateStrUtc = dateStr;
            if (dateStr && !dateStr.includes('Z') && !dateStr.includes('+')) {
                dateStrUtc = dateStr.replace(' ', 'T') + 'Z';
            }
            const d = new Date(dateStrUtc);
            const now = new Date();
            const diff = d - now;

            // Format: "14:30Z (in 2h)"
            const timeStr = String(d.getUTCHours()).padStart(2, '0') + ':' +
                           String(d.getUTCMinutes()).padStart(2, '0') + 'Z';

            if (diff < 0) {
                return `<span class="text-danger">${timeStr} (expired)</span>`;
            } else if (diff < 3600000) {
                const mins = Math.round(diff / 60000);
                return `<span class="text-warning">${timeStr} (${mins}m)</span>`;
            } else {
                const hours = Math.round(diff / 3600000);
                return `${timeStr} (${hours}h)`;
            }
        } catch (e) {
            return dateStr || '--';
        }
    }

    function formatDateTime(dateStr) {
        try {
            // Database returns UTC datetime without Z suffix
            let dateStrUtc = dateStr;
            if (dateStr && !dateStr.includes('Z') && !dateStr.includes('+')) {
                dateStrUtc = dateStr.replace(' ', 'T') + 'Z';
            }
            const d = new Date(dateStrUtc);
            return d.toISOString().substr(0, 16).replace('T', ' ') + 'Z';
        } catch (e) {
            return dateStr || '--';
        }
    }

    function showExtendDeadlineDialog(proposalId, currentDeadline) {
        // Calculate default new deadline (current + 1 hour, or now + 1 hour if expired)
        let defaultDeadline;
        if (currentDeadline) {
            const currentDate = new Date(currentDeadline.includes('Z') ? currentDeadline : currentDeadline.replace(' ', 'T') + 'Z');
            const now = new Date();
            // If expired, start from now; otherwise extend from current
            const baseTime = currentDate > now ? currentDate : now;
            defaultDeadline = new Date(baseTime.getTime() + 60 * 60 * 1000);
        } else {
            defaultDeadline = new Date(Date.now() + 60 * 60 * 1000);
        }
        const deadlineStr = defaultDeadline.toISOString().slice(0, 16);

        Swal.fire({
            title: 'Extend Deadline',
            html: `
                <div class="text-left">
                    <p>Extend approval deadline for Proposal #${proposalId}</p>
                    <div class="form-group">
                        <label for="newDeadline"><strong>New Deadline (UTC):</strong></label>
                        <input type="datetime-local" class="form-control" id="newDeadline" value="${deadlineStr}">
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Extend',
            confirmButtonColor: '#007bff',
            preConfirm: () => {
                const newDeadline = document.getElementById('newDeadline').value;
                if (!newDeadline) {
                    Swal.showValidationMessage('Please enter a new deadline');
                    return false;
                }
                return newDeadline;
            },
        }).then((result) => {
            if (result.isConfirmed) {
                extendDeadline(proposalId, result.value);
            }
        });
    }

    function extendDeadline(proposalId, newDeadline) {
        PERTIDialog.loading('tmiPublish.deadline.extending');

        $.ajax({
            url: 'api/mgt/tmi/coordinate.php',
            method: 'PATCH',
            contentType: 'application/json',
            data: JSON.stringify({
                proposal_id: proposalId,
                new_deadline_utc: newDeadline + ':00.000Z',
                user_cid: CONFIG.userCid,
                user_name: CONFIG.userName || 'Unknown',
            }),
            success: function(response) {
                PERTIDialog.close();
                if (response.success) {
                    PERTIDialog.success('tmiPublish.deadline.extended', null, {}, {
                        text: `New deadline: ${formatDateTime(response.new_deadline)}`,
                        timer: 3000,
                    });
                    loadProposals(); // Refresh the list
                } else {
                    PERTIDialog.error('tmiPublish.deadline.extendFailed', null, {}, {
                        text: response.error || 'Unknown error',
                    });
                }
            },
            error: function(xhr, status, error) {
                PERTIDialog.close();
                PERTIDialog.error('tmiPublish.submit.connectionError', null, {}, {
                    text: error || 'Failed to extend deadline',
                });
            },
        });
    }

    function handleProposalAction(proposalId, action) {
        const actionText = action === 'APPROVE' ? 'approve' : 'deny';
        const actionColor = action === 'APPROVE' ? '#28a745' : '#dc3545';

        Swal.fire({
            title: `${action === 'APPROVE' ? 'Approve' : 'Deny'} Proposal?`,
            html: `<p>Are you sure you want to <strong>${actionText}</strong> Proposal #${proposalId}?</p>
                   <p class="small text-muted">This is a DCC override action.</p>`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: action === 'APPROVE' ? 'Approve' : 'Deny',
            confirmButtonColor: actionColor,
        }).then((result) => {
            if (result.isConfirmed) {
                submitProposalAction(proposalId, action);
            }
        });
    }

    function submitProposalAction(proposalId, action) {
        PERTIDialog.show({
            title: `${action === 'APPROVE' ? 'Approving' : 'Denying'}...`,
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading(),
        });

        $.ajax({
            url: 'api/mgt/tmi/coordinate.php',
            method: 'PUT',
            contentType: 'application/json',
            data: JSON.stringify({
                proposal_id: proposalId,
                reaction_type: 'DCC_OVERRIDE',
                dcc_action: action,
                discord_user_id: CONFIG.userCid,
                discord_username: CONFIG.userName || 'DCC',
            }),
            success: function(response) {
                PERTIDialog.close();
                if (response.success) {
                    const successKey = action === 'APPROVE' ? 'tmiPublish.proposal.approved' : 'tmiPublish.proposal.denied';
                    PERTIDialog.success(successKey, null, {}, { timer: 2000 });
                    loadProposals();
                } else {
                    PERTIDialog.error('tmiPublish.proposal.actionFailed', null, {}, {
                        text: response.error || 'Unknown error',
                    });
                }
            },
            error: function(xhr, status, error) {
                PERTIDialog.close();
                PERTIDialog.error('tmiPublish.submit.connectionError', null, {}, {
                    text: error || 'Failed to process action',
                });
            },
        });
    }

    function showProposalsError(containerId, message) {
        $('#' + containerId).html(`
            <tr>
                <td colspan="8" class="text-center text-danger py-3">
                    <i class="fas fa-exclamation-triangle mr-1"></i> ${escapeHtml(message)}
                </td>
            </tr>
        `);
    }

    // Initialize coordination tab events
    $(document).ready(function() {
        initCoordinationTab();
        RerouteHandler.init();
    });

    // ===========================================
    // REROUTE HANDLER
    // Integration with Route Plotter for TMI reroute coordination
    // ===========================================

    const RerouteHandler = {
        rerouteData: null,
        userCid: null,
        userName: null,

        /**
         * Initialize reroute mode on page load
         */
        init: function() {
            const self = this;

            // Get user info from page config
            this.userCid = window.TMI_PUBLISHER_CONFIG?.userCid || null;
            this.userName = window.TMI_PUBLISHER_CONFIG?.userName || 'Unknown';

            // Check for reroute mode from URL
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('mode') === 'reroute') {
                this.loadFromSessionStorage();
            }

            // Bind events
            this.bindEvents();

            // Fetch next advisory number
            this.fetchNextAdvisoryNumber();

            // Load saved drafts
            this.loadDraftsList();

            console.log('[REROUTE] Handler initialized');
        },

        /**
         * Load reroute data from sessionStorage
         */
        loadFromSessionStorage: function() {
            try {
                const dataStr = sessionStorage.getItem('tmi_reroute_draft');
                const timestamp = sessionStorage.getItem('tmi_reroute_draft_timestamp');

                if (!dataStr) {
                    console.log('[REROUTE] No draft data found in sessionStorage');
                    return;
                }

                // Check if data is stale (older than 1 hour)
                if (timestamp && (Date.now() - parseInt(timestamp)) > 3600000) {
                    console.log('[REROUTE] Draft data is stale, ignoring');
                    sessionStorage.removeItem('tmi_reroute_draft');
                    sessionStorage.removeItem('tmi_reroute_draft_timestamp');
                    return;
                }

                this.rerouteData = JSON.parse(dataStr);
                console.log('[REROUTE] Loaded draft data:', this.rerouteData);

                // Switch to reroute tab
                $('#reroute-tab').tab('show');

                // Populate form
                this.populateForm();

                // Show source info
                $('#rerouteSourceInfo').show();
                $('#rerouteRouteCount').text(
                    ` - ${this.rerouteData.routes?.length || 0} route(s)`,
                );

                // Clean up sessionStorage
                sessionStorage.removeItem('tmi_reroute_draft');
                sessionStorage.removeItem('tmi_reroute_draft_timestamp');

            } catch (e) {
                console.error('[REROUTE] Failed to load from sessionStorage:', e);
            }
        },

        /**
         * Populate form from loaded data
         */
        populateForm: function() {
            if (!this.rerouteData) {return;}

            const adv = this.rerouteData.advisory || {};

            // Basic info
            $('#rr_facility').val(adv.facility || 'DCC');
            $('#rr_name').val(adv.name || '');
            $('#rr_route_type').val(adv.routeType || 'ROUTE');
            $('#rr_compliance').val(adv.compliance || 'RQD');
            $('#rr_constrained_area').val(adv.constrainedArea || '');
            $('#rr_reason').val(adv.reason || 'WEATHER');
            $('#rr_include_traffic').val(adv.includeTraffic || '');
            $('#rr_time_basis').val(adv.timeBasis || 'ETD');
            $('#rr_prob_extension').val(adv.probExtension || 'MEDIUM');
            $('#rr_remarks').val(adv.remarks || '');
            $('#rr_restrictions').val(adv.restrictions || '');
            $('#rr_modifications').val(adv.modifications || '');

            // Parse and set valid times
            if (adv.validStart) {
                const start = this.parseValidTime(adv.validStart);
                if (start) {$('#rr_valid_from').val(start);}
            }
            if (adv.validEnd) {
                const end = this.parseValidTime(adv.validEnd);
                if (end) {$('#rr_valid_until').val(end);}
            }

            // Populate routes table
            this.populateRoutesTable(this.rerouteData.routes || []);

            // Populate facilities grid
            this.populateFacilitiesGrid(this.rerouteData.facilities || []);

            // Auto-generate include traffic if not provided
            if (!adv.includeTraffic) {
                this.autoGenerateIncludeTraffic();
            }

            // Auto-fill route name if not already set
            if (!adv.name) {
                this.autoFillRouteName();
            }

            // Auto-populate remarks with route sources (playbooks/CDRs)
            if (!adv.remarks) {
                this.autoPopulateRemarksWithSources();
            }
        },

        /**
         * Auto-fill route name based on playbook/CDR usage
         * Logic:
         *   - 1 Playbook route: {Play name}
         *   - Part of playbook route: {Play name}_PARTIAL
         *   - 1 Playbook + additional routes: {Play name}_MOD
         *   - Otherwise: let user define
         */
        autoFillRouteName: function() {
            if (!this.rerouteData) {return;}

            const procedures = this.rerouteData.procedures || [];
            const routes = this.rerouteData.routes || [];

            // Extract playbook names from procedures
            const playbookNames = procedures
                .filter(p => p.startsWith('PB: '))
                .map(p => p.slice(4).trim());

            // Extract CDR codes from procedures
            const cdrCodes = procedures
                .filter(p => p.startsWith('CDR: '))
                .map(p => p.slice(5).trim());

            let autoName = '';

            if (playbookNames.length === 1 && cdrCodes.length === 0) {
                // Single playbook route
                const pbName = playbookNames[0];

                // Check if routes match full playbook or are partial
                // For simplicity, if route count > 0, assume it's complete
                // "Partial" would need more complex analysis of actual vs expected routes
                autoName = pbName;

                // If there are additional non-playbook routes, mark as _MOD
                // This is a heuristic - if rawInput contains lines not starting with PB.
                const rawInput = (this.rerouteData.rawInput || '').trim();
                const lines = rawInput.split(/\r?\n/).filter(l => l.trim() && !l.trim().startsWith('['));
                const pbLines = lines.filter(l => l.trim().toUpperCase().startsWith('PB.'));
                const nonPbLines = lines.filter(l => !l.trim().toUpperCase().startsWith('PB.') && !l.trim().startsWith(';'));

                if (nonPbLines.length > 0 && pbLines.length > 0) {
                    autoName = pbName + '_MOD';
                }
            } else if (playbookNames.length > 1) {
                // Multiple playbook routes - use first one + _MULTI
                autoName = playbookNames[0] + '_MULTI';
            } else if (cdrCodes.length === 1 && playbookNames.length === 0) {
                // Single CDR code
                autoName = cdrCodes[0];
            } else if (cdrCodes.length > 1 && playbookNames.length === 0) {
                // Multiple CDR codes
                autoName = cdrCodes[0] + '_MULTI';
            }

            if (autoName) {
                $('#rr_name').val(autoName);
            }
        },

        /**
         * Auto-populate remarks with route sources (playbooks/CDRs)
         * Each source is on its own line - the formatter handles column alignment
         */
        autoPopulateRemarksWithSources: function() {
            if (!this.rerouteData) {return;}

            const procedures = this.rerouteData.procedures || [];
            const routes = this.rerouteData.routes || [];

            // Extract playbook names from procedures
            const playbookNames = [...new Set(
                procedures
                    .filter(p => p.startsWith('PB: '))
                    .map(p => p.slice(4).trim()),
            )];

            // Extract CDR codes from procedures
            const cdrCodes = [...new Set(
                procedures
                    .filter(p => p.startsWith('CDR: '))
                    .map(p => p.slice(5).trim()),
            )];

            // Also check individual routes for playbook/CDR info
            routes.forEach(r => {
                if (r.playbookName && !playbookNames.includes(r.playbookName)) {
                    playbookNames.push(r.playbookName);
                }
                if (r.cdrCode && !cdrCodes.includes(r.cdrCode)) {
                    cdrCodes.push(r.cdrCode);
                }
            });

            if (playbookNames.length === 0 && cdrCodes.length === 0) {
                return;
            }

            // Build remarks text with each source on its own line
            // The formatter will handle column alignment with hanging indent
            const lines = [];

            // Add playbook sources
            playbookNames.forEach(pb => {
                lines.push(`BASED ON PLAYBOOK: ${pb}`);
            });

            // Add CDR sources
            cdrCodes.forEach(cdr => {
                lines.push(`BASED ON CDR: ${cdr}`);
            });

            // Join with newlines - formatter will add proper column alignment
            const remarksText = lines.join('\n');

            if (remarksText) {
                $('#rr_remarks').val(remarksText);
            }
        },

        /**
         * Populate routes table
         */
        populateRoutesTable: function(routes) {
            const $tbody = $('#rr_routes_body');
            $tbody.empty();

            if (!routes.length) {
                $tbody.html(`
                    <tr class="rr-empty-row">
                        <td colspan="7" class="text-center text-muted py-3">
                            No routes available.
                        </td>
                    </tr>
                `);
                return;
            }

            const self = this;
            routes.forEach((route, idx) => {
                // Normalize route string: strip dots between proc and trans
                const normalizedRoute = self.normalizeRouteString(route.route || '');

                $tbody.append(`
                    <tr data-idx="${idx}">
                        <td class="text-center align-middle" style="padding: 0.15rem;">
                            <input type="checkbox" class="rr-route-select" data-idx="${idx}">
                        </td>
                        <td style="padding: 0.15rem;">
                            <input type="text" class="form-control form-control-sm rr-route-origin"
                                   value="${self.escapeHtml(route.origin)}"
                                   style="font-family: monospace; font-size: 0.7rem; padding: 0.15rem 0.25rem;">
                        </td>
                        <td style="padding: 0.15rem;">
                            <input type="text" class="form-control form-control-sm rr-route-orig-filter"
                                   value="${self.escapeHtml(route.originFilter || '')}"
                                   placeholder="-APT"
                                   style="font-family: monospace; font-size: 0.65rem; padding: 0.15rem 0.25rem;">
                        </td>
                        <td style="padding: 0.15rem;">
                            <input type="text" class="form-control form-control-sm rr-route-dest"
                                   value="${self.escapeHtml(route.destination)}"
                                   style="font-family: monospace; font-size: 0.7rem; padding: 0.15rem 0.25rem;">
                        </td>
                        <td style="padding: 0.15rem;">
                            <input type="text" class="form-control form-control-sm rr-route-dest-filter"
                                   value="${self.escapeHtml(route.destFilter || '')}"
                                   placeholder="-APT"
                                   style="font-family: monospace; font-size: 0.65rem; padding: 0.15rem 0.25rem;">
                        </td>
                        <td style="padding: 0.15rem;">
                            <textarea class="form-control form-control-sm rr-route-string" rows="1"
                                   style="font-family: monospace; font-size: 0.7rem; padding: 0.15rem 0.25rem; resize: vertical; min-height: 26px;">${self.escapeHtml(normalizedRoute)}</textarea>
                        </td>
                        <td style="padding: 0.15rem;">
                            <button class="btn btn-sm btn-outline-danger rr-remove-route py-0 px-1"
                                    data-idx="${idx}" title="Remove">
                                <i class="fas fa-times"></i>
                            </button>
                        </td>
                    </tr>
                `);
            });
        },

        /**
         * Normalize route string: strip dots between procedure and transition
         */
        normalizeRouteString: function(routeText) {
            if (!routeText) {return '';}
            // Replace PROC.TRANS patterns with PROC TRANS (strip the dot)
            return routeText.replace(/(\w+)\.(\w+)/g, '$1 $2');
        },

        /**
         * Check if a token looks like a DP or STAR procedure (ends with digit)
         */
        looksLikeProcedure: function(token) {
            if (!token) {return false;}
            const clean = token.replace(/[<>]/g, '').toUpperCase();
            // Typical DP/STAR: 4-7 alphanumeric chars ending in a digit
            if (clean.length < 4 || clean.length > 7) {return false;}
            if (!/^[A-Z]+\d$/.test(clean)) {return false;}
            // Exclude airport codes
            if (/^K[A-Z]{3}$/.test(clean)) {return false;}
            return true;
        },

        /**
         * Make selected routes mandatory by adding >< markers around the route portion
         * (excluding any DP/STAR procedures at the start/end)
         */
        makeRoutesMandatory: function() {
            const self = this;
            const $selectedRows = $('#rr_routes_body tr:not(.rr-empty-row)').filter(function() {
                return $(this).find('.rr-route-select').is(':checked');
            });

            if ($selectedRows.length === 0) {
                PERTIDialog.info('reroute.noRoutesSelected', 'reroute.selectRoutesMandatory', {}, {
                    timer: 2000,
                    showConfirmButton: false,
                });
                return;
            }

            $selectedRows.each(function() {
                const $input = $(this).find('.rr-route-string');
                const routeText = $input.val() || '';
                const mandatoryRoute = self.applyMandatoryMarkers(routeText);
                $input.val(mandatoryRoute);
            });

            // Uncheck all after operation
            $('.rr-route-select').prop('checked', false);
            $('#rr_select_all_routes').prop('checked', false);

            const successText = typeof PERTII18n !== 'undefined'
                ? PERTII18n.t('reroute.appliedMandatory', { count: $selectedRows.length })
                : `Applied mandatory markers to ${$selectedRows.length} route(s).`;
            PERTIDialog.success('reroute.routesMadeMandatory', null, {}, {
                text: successText,
                timer: 2000,
                showConfirmButton: false,
            });
        },

        /**
         * Apply mandatory markers to a route string, excluding DP/STAR at start/end
         */
        applyMandatoryMarkers: function(routeText) {
            if (!routeText) {return routeText;}

            // Already has markers
            if (routeText.indexOf('>') !== -1 || routeText.indexOf('<') !== -1) {
                return routeText;
            }

            const tokens = routeText.trim().split(/\s+/).filter(Boolean);
            if (tokens.length === 0) {return routeText;}
            if (tokens.length === 1) {
                // Single token - wrap it if not a procedure
                if (this.looksLikeProcedure(tokens[0])) {
                    return routeText; // Don't wrap standalone procedure
                }
                return '>' + tokens[0] + '<';
            }

            // Find first non-procedure token (where mandatory starts)
            let startIdx = 0;
            while (startIdx < tokens.length && this.looksLikeProcedure(tokens[startIdx])) {
                startIdx++;
            }

            // Find last non-procedure token (where mandatory ends)
            let endIdx = tokens.length - 1;
            while (endIdx >= startIdx && this.looksLikeProcedure(tokens[endIdx])) {
                endIdx--;
            }

            // If all tokens are procedures, don't add markers
            if (startIdx > endIdx) {
                return routeText;
            }

            // Apply markers
            tokens[startIdx] = '>' + tokens[startIdx];
            tokens[endIdx] = tokens[endIdx] + '<';

            return tokens.join(' ');
        },

        /**
         * Group routes that share the same route string, consolidating origins/destinations
         */
        groupRoutes: function() {
            const routes = this.collectRoutes();
            if (routes.length < 2) {
                PERTIDialog.info('reroute.nothingToGroup', 'reroute.needTwoRoutes', {}, {
                    timer: 2000,
                    showConfirmButton: false,
                });
                return;
            }

            // Group by normalized route string
            const routeGroups = {};
            routes.forEach(r => {
                const routeKey = (r.route || '').trim().toUpperCase();
                if (!routeGroups[routeKey]) {
                    routeGroups[routeKey] = {
                        origins: new Set(),
                        destinations: new Set(),
                        originFilters: new Set(),
                        destFilters: new Set(),
                        route: r.route,
                    };
                }
                if (r.origin) {
                    // Handle slash-separated origins
                    r.origin.split('/').filter(Boolean).forEach(o => routeGroups[routeKey].origins.add(o.trim().toUpperCase()));
                }
                if (r.destination) {
                    // Handle slash-separated destinations
                    r.destination.split('/').filter(Boolean).forEach(d => routeGroups[routeKey].destinations.add(d.trim().toUpperCase()));
                }
                // Merge filters (space-separated tokens)
                if (r.originFilter) {
                    r.originFilter.split(/\s+/).filter(Boolean).forEach(f => routeGroups[routeKey].originFilters.add(f.trim()));
                }
                if (r.destFilter) {
                    r.destFilter.split(/\s+/).filter(Boolean).forEach(f => routeGroups[routeKey].destFilters.add(f.trim()));
                }
            });

            // Convert back to array
            const grouped = Object.values(routeGroups).map(g => ({
                origin: Array.from(g.origins).sort().join('/'),
                destination: Array.from(g.destinations).sort().join('/'),
                route: g.route,
                originFilter: Array.from(g.originFilters).sort().join(' '),
                destFilter: Array.from(g.destFilters).sort().join(' '),
            })).filter(r => r.route); // Remove empty routes

            if (grouped.length === routes.length) {
                PERTIDialog.info('reroute.noGroupingPossible', 'reroute.allRoutesUnique', {}, {
                    timer: 2000,
                    showConfirmButton: false,
                });
                return;
            }

            // Update the table
            this.populateRoutesTable(grouped);

            const groupedText = typeof PERTII18n !== 'undefined'
                ? PERTII18n.t('reroute.consolidated', { from: routes.length, to: grouped.length })
                : `Consolidated ${routes.length} routes into ${grouped.length} groups.`;
            PERTIDialog.success('reroute.routesGrouped', null, {}, {
                text: groupedText,
                timer: 2000,
                showConfirmButton: false,
            });
        },

        /**
         * Auto-detect and apply filters for overlapping facilities.
         * When routes exist for both specific facilities (airports) AND
         * overlying facilities (ARTCCs), automatically add exclusion filters.
         *
         * Example: If KBWI→KMIA and KDCA→KMIA and ZDC→KMIA exist,
         * ZDC→KMIA gets origin_filter "-KBWI -KDCA"
         */
        autoDetectFilters: async function() {
            const routes = this.collectRoutes();
            if (routes.length < 2) {
                PERTIDialog.info('reroute.notEnoughRoutes', 'reroute.needTwoRoutesFilters', {}, {
                    timer: 2000,
                    showConfirmButton: false,
                });
                return;
            }

            // Ensure facility hierarchy is loaded
            if (typeof FacilityHierarchy === 'undefined' || !FacilityHierarchy.isLoaded) {
                if (typeof FacilityHierarchy !== 'undefined') {
                    await FacilityHierarchy.load();
                } else {
                    PERTIDialog.warning('reroute.cannotAutoDetect', 'reroute.hierarchyNotAvailable', {}, {
                        timer: 2000,
                        showConfirmButton: false,
                    });
                    return;
                }
            }

            const ARTCCS = FacilityHierarchy.ARTCCS || [];
            const AIRPORT_TO_ARTCC = FacilityHierarchy.AIRPORT_TO_ARTCC || {};

            // Helper: check if facility is an ARTCC
            const isARTCC = (fac) => {
                if (!fac) {return false;}
                const upper = fac.toUpperCase().split('/')[0]; // Handle slash-separated
                return ARTCCS.includes(upper);
            };

            // Helper: get ARTCC for an airport
            const getARTCC = (airport) => {
                if (!airport) {return null;}
                const upper = airport.toUpperCase();
                return AIRPORT_TO_ARTCC[upper] || null;
            };

            // Build lookup: destination -> [origins that share same route to that dest]
            // Also build reverse: origin -> [destinations]
            const destToOrigins = {};
            const origToDests = {};
            routes.forEach((r, idx) => {
                // Handle slash-separated origins/destinations
                const origins = (r.origin || '').toUpperCase().split('/').filter(Boolean);
                const dests = (r.destination || '').toUpperCase().split('/').filter(Boolean);
                const routeNorm = (r.route || '').toUpperCase().trim();

                origins.forEach(orig => {
                    dests.forEach(dest => {
                        if (!destToOrigins[dest]) {destToOrigins[dest] = [];}
                        destToOrigins[dest].push({ orig, routeIdx: idx, routeStr: routeNorm });

                        if (!origToDests[orig]) {origToDests[orig] = [];}
                        origToDests[orig].push({ dest, routeIdx: idx, routeStr: routeNorm });
                    });
                });
            });

            // For each route, detect if it's an ARTCC and there are airports within it
            // that have their own specific routes
            let filterCount = 0;
            routes.forEach((r, idx) => {
                const origins = (r.origin || '').toUpperCase().split('/').filter(Boolean);
                const dests = (r.destination || '').toUpperCase().split('/').filter(Boolean);

                // Check origin side: if origin is an ARTCC
                origins.forEach(orig => {
                    if (isARTCC(orig)) {
                        // Find airports within this ARTCC that have different route strings
                        const childAirports = FacilityHierarchy.getChildFacilities ?
                            FacilityHierarchy.getChildFacilities(orig) : [];

                        dests.forEach(dest => {
                            const artccEntries = (destToOrigins[dest] || [])
                                .filter(e => e.orig === orig && e.routeIdx === idx);

                            if (artccEntries.length === 0) {return;}

                            // Find other origins to same dest that are children of this ARTCC
                            const toExclude = [];
                            (destToOrigins[dest] || []).forEach(entry => {
                                if (entry.routeIdx === idx) {return;} // Same route
                                const entryOrig = entry.orig;
                                // Check if this origin is a child of our ARTCC
                                const parentArtcc = getARTCC(entryOrig);
                                if (parentArtcc === orig || childAirports.includes(entryOrig)) {
                                    // Different route string = needs exclusion
                                    if (entry.routeStr !== artccEntries[0].routeStr) {
                                        toExclude.push('-' + entryOrig);
                                    }
                                }
                            });

                            if (toExclude.length > 0) {
                                // Merge with existing filter
                                const existing = (r.originFilter || '').toUpperCase().split(/\s+/).filter(Boolean);
                                const merged = [...new Set([...existing, ...toExclude])].sort();
                                r.originFilter = merged.join(' ');
                                filterCount++;
                            }
                        });
                    }
                });

                // Check dest side: if dest is an ARTCC
                dests.forEach(dest => {
                    if (isARTCC(dest)) {
                        const childAirports = FacilityHierarchy.getChildFacilities ?
                            FacilityHierarchy.getChildFacilities(dest) : [];

                        origins.forEach(orig => {
                            const artccEntries = (origToDests[orig] || [])
                                .filter(e => e.dest === dest && e.routeIdx === idx);

                            if (artccEntries.length === 0) {return;}

                            const toExclude = [];
                            (origToDests[orig] || []).forEach(entry => {
                                if (entry.routeIdx === idx) {return;}
                                const entryDest = entry.dest;
                                const parentArtcc = getARTCC(entryDest);
                                if (parentArtcc === dest || childAirports.includes(entryDest)) {
                                    if (entry.routeStr !== artccEntries[0].routeStr) {
                                        toExclude.push('-' + entryDest);
                                    }
                                }
                            });

                            if (toExclude.length > 0) {
                                const existing = (r.destFilter || '').toUpperCase().split(/\s+/).filter(Boolean);
                                const merged = [...new Set([...existing, ...toExclude])].sort();
                                r.destFilter = merged.join(' ');
                                filterCount++;
                            }
                        });
                    }
                });
            });

            // Repopulate table with updated filters
            this.populateRoutesTable(routes);

            if (filterCount > 0) {
                Swal.fire({
                    icon: 'success',
                    title: 'Filters Detected',
                    text: `Applied ${filterCount} automatic filter(s) to overlapping routes.`,
                    timer: 2500,
                    showConfirmButton: false,
                });
            } else {
                Swal.fire({
                    icon: 'info',
                    title: 'No Filters Needed',
                    text: 'No overlapping ARTCC/airport routes detected.',
                    timer: 2000,
                    showConfirmButton: false,
                });
            }
        },

        /**
         * Populate facilities grid with checkboxes
         * Also auto-detect international organization requirements based on route airports
         */
        populateFacilitiesGrid: function(facilities) {
            // Uncheck all first
            $('.rr-facility-cb').prop('checked', false);

            // Check the ones in the facilities array
            facilities.forEach(artcc => {
                $('#rr_fac_' + artcc).prop('checked', true);
            });

            // Auto-detect international organizations based on route airports
            this.autoDetectInternationalOrgs();
        },

        /**
         * Auto-detect and check international organizations based on route airports/FIRs
         * Uses FacilityHierarchy global when available for accurate mappings
         *
         * VATCAN: Canadian FIRs (CZEG, CZVR, CZWG, CZYZ, CZQM, CZQX, CZQO, CZUL) or CY/CZ airports
         * VATMEX: Mexican FIRs (MMFR, MMFO) or MM airports
         * VATCAR: Caribbean FIRs (TJZS, MKJK, MUFH, MYNA, MDCS, MTEG, TNCF, TTZP, MHCC, MPZL) or Caribbean airports
         * ECFMP: European/North African airports (Exx/Lxx/Gxx prefixes)
         */
        autoDetectInternationalOrgs: function() {
            const routes = this.rerouteData?.routes || [];
            const facilities = this.rerouteData?.facilities || [];
            if (!routes.length && !facilities.length) {return;}

            // Use FacilityHierarchy if available for region definitions
            const FH = window.FacilityHierarchy || {};
            const DCC_REGIONS = FH.DCC_REGIONS || {};

            // Canadian FIRs from hierarchy
            const canadianFIRs = (DCC_REGIONS.CANADA?.artccs || [
                'CZEG', 'CZVR', 'CZWG', 'CZYZ', 'CZQM', 'CZQX', 'CZQO', 'CZUL',
            ]).map(f => f.toUpperCase());

            // Mexican FIRs from hierarchy
            const mexicanFIRs = (DCC_REGIONS.MEXICO?.artccs || ['MMFR', 'MMFO']).map(f => f.toUpperCase());

            // Caribbean FIRs from hierarchy
            const caribbeanFIRs = (DCC_REGIONS.CARIBBEAN?.artccs || [
                'TJZS', 'MKJK', 'MUFH', 'MYNA', 'MDCS', 'MTEG', 'TNCF', 'TTZP', 'MHCC', 'MPZL',
            ]).map(f => f.toUpperCase());

            // Check if any facilities in the route match international FIRs
            const upperFacilities = facilities.map(f => f.toUpperCase());
            const hasCanadianFIR = upperFacilities.some(f => canadianFIRs.includes(f));
            const hasMexicanFIR = upperFacilities.some(f => mexicanFIRs.includes(f));
            const hasCaribbeanFIR = upperFacilities.some(f => caribbeanFIRs.includes(f));

            // Collect all airports from routes
            const allAirports = new Set();
            const allFIRs = new Set();
            routes.forEach(r => {
                // Add origin airports and ARTCCs
                (r.originAirports || []).forEach(apt => allAirports.add(apt.toUpperCase()));
                (r.originArtccs || []).forEach(fir => allFIRs.add(fir.toUpperCase()));
                // Add dest airports and ARTCCs
                (r.destAirports || []).forEach(apt => allAirports.add(apt.toUpperCase()));
                (r.destArtccs || []).forEach(fir => allFIRs.add(fir.toUpperCase()));
                // Also check origin/destination strings for codes
                const origTokens = (r.origin || '').toUpperCase().split(/[\s/]+/).filter(Boolean);
                const destTokens = (r.destination || '').toUpperCase().split(/[\s/]+/).filter(Boolean);
                [...origTokens, ...destTokens].forEach(t => {
                    if (/^[A-Z]{4}$/.test(t)) {allAirports.add(t);}
                    if (/^[A-Z]{2,4}$/.test(t) && (t.startsWith('Z') || t.startsWith('CZ') || t.startsWith('MM'))) {
                        allFIRs.add(t);
                    }
                });
            });

            const airports = Array.from(allAirports);
            const firs = Array.from(allFIRs);

            // Check FIRs from routes
            const hasCanadianFIRRoute = firs.some(f => canadianFIRs.includes(f));
            const hasMexicanFIRRoute = firs.some(f => mexicanFIRs.includes(f));
            const hasCaribbeanFIRRoute = firs.some(f => caribbeanFIRs.includes(f));

            // Canadian airports (CYXX, CZXX)
            const hasCanadianAirport = airports.some(apt => /^C[YZ][A-Z]{2}$/.test(apt));

            // Mexican airports (MMXX)
            const hasMexicanAirport = airports.some(apt => /^MM[A-Z]{2}$/.test(apt));

            // Caribbean airport prefixes (based on ICAO region assignments)
            // T = Caribbean and North Atlantic, M = Central America/Mexico
            // TJ = Puerto Rico, TI = Virgin Islands, TN = Netherlands Antilles, TK = St. Kitts
            // MK = Jamaica, TT = Trinidad, TB = Barbados, TF = French Antilles
            // MU = Cuba, MY = Bahamas, MD = Dominican Republic, MH = Honduras
            // MG = Guatemala, MS = El Salvador, MW = Cayman, MP = Panama
            const caribbeanPrefixes = ['TJ', 'TI', 'TN', 'TK', 'MK', 'TT', 'TB', 'TF', 'MU', 'MY', 'MD', 'MH', 'MG', 'MS', 'MW', 'MP', 'MT'];
            const hasCaribbeanAirport = airports.some(apt => caribbeanPrefixes.some(pfx => apt.startsWith(pfx)));

            // European/North African prefixes (ICAO regions)
            // E = Northern Europe (EG=UK, EI=Ireland, EH=NL, EB=Belgium, ED=Germany, EK=Denmark, EN=Norway, ES=Sweden, EF=Finland, EP=Poland)
            // L = Southern Europe (LF=France, LE=Spain, LP=Portugal, LI=Italy, LG=Greece, LZ=Slovakia, LO=Austria, LK=Czech)
            // G = West Africa (GM=Morocco, GC=Canary Islands, etc.)
            const euNaPrefixes = [
                'EG', 'EI', 'EH', 'EB', 'ED', 'EK', 'EN', 'ES', 'EF', 'EP', 'EE', 'EV', 'EY',
                'LF', 'LE', 'LP', 'LI', 'LG', 'LZ', 'LO', 'LK', 'LH', 'LJ', 'LD', 'LQ', 'LY',
                'GM', 'GC', 'DA', 'DT',
            ];
            const hasEuNaAirport = airports.some(apt => euNaPrefixes.some(pfx => apt.startsWith(pfx)));

            // Check the appropriate international orgs
            if (hasCanadianFIR || hasCanadianFIRRoute || hasCanadianAirport) {
                $('#rr_fac_VATCAN').prop('checked', true);
            }
            if (hasMexicanFIR || hasMexicanFIRRoute || hasMexicanAirport) {
                $('#rr_fac_VATMEX').prop('checked', true);
            }
            if (hasCaribbeanFIR || hasCaribbeanFIRRoute || hasCaribbeanAirport) {
                $('#rr_fac_VATCAR').prop('checked', true);
            }
            if (hasEuNaAirport) {
                $('#rr_fac_ECFMP').prop('checked', true);
            }
        },

        /**
         * Fetch next advisory number from API
         */
        fetchNextAdvisoryNumber: async function() {
            try {
                const response = await fetch('/api/mgt/tmi/advisory-number.php?peek=1');
                if (response.ok) {
                    const data = await response.json();
                    $('#rr_adv_number').val(data.advisory_number || '001');
                }
            } catch (e) {
                console.warn('[REROUTE] Could not fetch advisory number:', e);
                $('#rr_adv_number').val('001');
            }
        },

        /**
         * Generate advisory preview text
         */
        generatePreview: function() {
            const routes = this.collectRoutes();
            const facilities = this.getSelectedFacilities();

            const params = {
                advisory_number: $('#rr_adv_number').val() || '001',
                facility: $('#rr_facility').val() || 'DCC',
                name: $('#rr_name').val() || '',
                route_type: $('#rr_route_type').val() || 'ROUTE',
                compliance: $('#rr_compliance').val() || 'RQD',
                constrained_area: $('#rr_constrained_area').val() || '',
                reason: $('#rr_reason').val() || 'WEATHER',
                include_traffic: $('#rr_include_traffic').val() || '',
                facilities_included: facilities.join('/'),
                valid_from: $('#rr_valid_from').val() || '',
                valid_until: $('#rr_valid_until').val() || '',
                time_basis: $('#rr_time_basis').val() || 'ETD',
                prob_extension: $('#rr_prob_extension').val() || 'MEDIUM',
                remarks: $('#rr_remarks').val() || '',
                restrictions: $('#rr_restrictions').val() || '',
                modifications: $('#rr_modifications').val() || '',
                routes: routes,
            };

            const preview = this.formatRerouteAdvisory(params);
            $('#rr_preview_text').text(preview);
        },

        /**
         * Format reroute advisory text with proper 68-char line wrapping
         */
        formatRerouteAdvisory: function(params) {
            const MAX_LINE = 68;
            const now = new Date();
            const headerDate = (now.getUTCMonth() + 1).toString().padStart(2, '0') + '/' +
                              now.getUTCDate().toString().padStart(2, '0') + '/' +
                              now.getUTCFullYear();

            const validFromDt = params.valid_from ? new Date(params.valid_from) : now;
            const validUntilDt = params.valid_until ? new Date(params.valid_until) :
                new Date(now.getTime() + 4 * 3600000);

            const startStr = validFromDt.getUTCDate().toString().padStart(2, '0') +
                            validFromDt.getUTCHours().toString().padStart(2, '0') +
                            validFromDt.getUTCMinutes().toString().padStart(2, '0');
            const endStr = validUntilDt.getUTCDate().toString().padStart(2, '0') +
                          validUntilDt.getUTCHours().toString().padStart(2, '0') +
                          validUntilDt.getUTCMinutes().toString().padStart(2, '0');

            const tmiId = 'RR' + params.facility + params.advisory_number;

            // Helper: add labeled field with proper wrapping at 68 chars
            // Preserves explicit newlines in the value, treating each line separately
            const addLabeledField = (lines, label, value) => {
                const raw = (value == null ? '' : String(value)).trim().toUpperCase();
                if (!raw.length) {return;}

                const labelStr = label + ': ';
                const hangIndent = ' '.repeat(labelStr.length);

                // Split by explicit newlines first, then wrap each segment
                const segments = raw.split(/\r?\n/).map(s => s.trim()).filter(s => s);

                segments.forEach((segment, segIdx) => {
                    // Tokenize segment, breaking slash-lists for proper wrapping
                    const tokens = [];
                    segment.split(/\s+/).forEach(part => {
                        if (part.indexOf('/') !== -1 && part.length > 8) {
                            // Break up long slash-separated lists
                            part.split('/').forEach((piece, idx, arr) => {
                                if (piece) {tokens.push(idx < arr.length - 1 ? piece + '/' : piece);}
                            });
                        } else {
                            tokens.push(part);
                        }
                    });

                    if (!tokens.length) {return;}

                    // First segment gets the label, subsequent segments get hanging indent
                    let prefix = segIdx === 0 ? labelStr : hangIndent;
                    let content = '';

                    tokens.forEach(word => {
                        if (!word) {return;}
                        if (!content) {
                            if (prefix.length + word.length <= MAX_LINE) {
                                content = word;
                            } else {
                                lines.push(prefix + word);
                                prefix = hangIndent;
                                content = '';
                            }
                        } else {
                            const lastChar = content[content.length - 1];
                            const joiner = (lastChar === '/' ? '' : ' ');
                            if (prefix.length + content.length + joiner.length + word.length <= MAX_LINE) {
                                content += joiner + word;
                            } else {
                                lines.push((prefix + content).trimEnd());
                                prefix = hangIndent;
                                content = word;
                            }
                        }
                    });
                    if (content) {
                        lines.push((prefix + content).trimEnd());
                    }
                });
            };

            const lines = [];
            const routeType = params.route_type || 'ROUTE';
            const compliance = params.compliance || 'RQD';
            lines.push(`vATCSCC ADVZY ${params.advisory_number} ${params.facility} ${headerDate} ${routeType} ${compliance}`);
            addLabeledField(lines, 'NAME', params.name);
            addLabeledField(lines, 'CONSTRAINED AREA', params.constrained_area);
            addLabeledField(lines, 'REASON', params.reason);
            addLabeledField(lines, 'INCLUDE TRAFFIC', params.include_traffic);
            addLabeledField(lines, 'FACILITIES INCLUDED', params.facilities_included);
            lines.push('FLIGHT STATUS: ALL FLIGHTS');
            lines.push(`VALID: ${params.time_basis} ${startStr} TO ${endStr}`);
            addLabeledField(lines, 'PROBABILITY OF EXTENSION', params.prob_extension);
            addLabeledField(lines, 'REMARKS', params.remarks);
            addLabeledField(lines, 'ASSOCIATED RESTRICTIONS', params.restrictions);
            addLabeledField(lines, 'MODIFICATIONS', params.modifications);
            lines.push('ROUTES:');
            lines.push('');

            // Route table with column alignment and line wrapping
            // Check format option - split vs full
            const useSplitFormat = $('input[name="rr_format"]:checked').val() === 'split';
            if (useSplitFormat) {
                lines.push(this.formatSplitRouteTable(params.routes));
            } else {
                lines.push(this.formatRouteTable(params.routes));
            }

            lines.push('');
            lines.push(`TMI ID: ${tmiId}`);
            lines.push(`${startStr} - ${endStr}`);

            const timestampStr = now.getUTCFullYear().toString().slice(2) + '/' +
                                (now.getUTCMonth() + 1).toString().padStart(2, '0') + '/' +
                                now.getUTCDate().toString().padStart(2, '0') + ' ' +
                                now.getUTCHours().toString().padStart(2, '0') + ':' +
                                now.getUTCMinutes().toString().padStart(2, '0');

            // Add author signature: FACILITY.OI (e.g., DCC.HP)
            const userOI = window.TMI_PUBLISHER_CONFIG?.userOI || '';
            const authorSig = userOI ? `${params.facility}.${userOI}` : params.facility;
            lines.push(`${timestampStr} ${authorSig}`);

            return lines.join('\n');
        },

        /**
         * Format route table for advisory with proper column alignment and 68-char wrapping
         */
        formatRouteTable: function(routes) {
            const MAX_LINE = 68;

            if (!routes || !routes.length) {
                return 'ORIG       DEST       ROUTE\n----       ----       -----\n(No routes specified)';
            }

            // Calculate column widths based on content (including filters)
            let maxOrigLen = 4, maxDestLen = 4;
            routes.forEach(r => {
                // Include filter in origin/dest length calculation (filters in parentheses)
                let origWithFilter = (r.origin || '');
                if (r.originFilter) {origWithFilter += ' (' + r.originFilter + ')';}
                let destWithFilter = (r.destination || '');
                if (r.destFilter) {destWithFilter += ' (' + r.destFilter + ')';}
                maxOrigLen = Math.max(maxOrigLen, origWithFilter.length);
                maxDestLen = Math.max(maxDestLen, destWithFilter.length);
            });

            // Cap column widths to leave room for route text
            const origColWidth = Math.min(maxOrigLen + 2, 24);
            const destColWidth = Math.min(maxDestLen + 2, 24);
            const routeStartCol = origColWidth + destColWidth;

            // Format route row with proper wrapping
            const formatRouteRow = (orig, dest, routeText, origFilter, destFilter) => {
                orig = (orig || '').toUpperCase();
                dest = (dest || '').toUpperCase();
                origFilter = (origFilter || '').toUpperCase().trim();
                destFilter = (destFilter || '').toUpperCase().trim();
                routeText = (routeText || '').toUpperCase().trim();

                // Append filters to origin/dest in parentheses
                if (origFilter) {orig = orig + ' (' + origFilter + ')';}
                if (destFilter) {dest = dest + ' (' + destFilter + ')';}

                const rowLines = [];
                const routeTokens = routeText.length ? routeText.split(/\s+/).filter(Boolean) : [];

                // Handle multi-item origins/dests (space or "/" separated)
                // Split on "/" or whitespace to handle both "KJFK KLGA" and "KJFK/KLGA" formats
                const origItems = orig.trim() ? orig.trim().split(/[\s/]+/).filter(Boolean) : [];
                const destItems = dest.trim() ? dest.trim().split(/[\s/]+/).filter(Boolean) : [];

                // Chunk items to fit in column (using "/" separator for compact display)
                const chunkItemsToFit = (items, maxLen, sep = '/') => {
                    if (!items.length) {return [''];}
                    const chunks = [];
                    let current = [];
                    let currentLen = 0;

                    items.forEach(item => {
                        const sepLen = current.length > 0 ? sep.length : 0;
                        if (currentLen + sepLen + item.length > maxLen && current.length > 0) {
                            chunks.push(current.join(sep));
                            current = [item];
                            currentLen = item.length;
                        } else {
                            current.push(item);
                            currentLen += sepLen + item.length;
                        }
                    });
                    if (current.length) {chunks.push(current.join(sep));}
                    return chunks.length ? chunks : [''];
                };

                const origChunks = chunkItemsToFit(origItems, origColWidth - 2);
                const destChunks = chunkItemsToFit(destItems, destColWidth - 2);
                const maxColumnLines = Math.max(origChunks.length, destChunks.length, 1);
                const words = routeTokens.slice();
                let lineIndex = 0;

                while (lineIndex < maxColumnLines || words.length) {
                    const oStr = (lineIndex < origChunks.length) ? origChunks[lineIndex] : '';
                    const dStr = (lineIndex < destChunks.length) ? destChunks[lineIndex] : '';
                    const origPad = oStr.padEnd(origColWidth).slice(0, origColWidth);
                    const destPad = dStr.padEnd(destColWidth).slice(0, destColWidth);
                    const basePrefix = origPad + destPad;
                    let current = basePrefix;
                    const baseLen = current.length;

                    if (words.length) {
                        while (words.length) {
                            const word = words[0];
                            const atStart = (current.length === baseLen);
                            const addition = atStart ? word : ' ' + word;
                            if (current.length + addition.length <= MAX_LINE) {
                                current += addition;
                                words.shift();
                            } else {
                                // Force at least one word on the line if at start
                                if (atStart && current.length + word.length > MAX_LINE) {
                                    current += word;
                                    words.shift();
                                }
                                break;
                            }
                        }
                    }
                    rowLines.push(current.trimEnd());
                    lineIndex++;
                }

                return rowLines.length ? rowLines : [''];
            };

            let output = 'ORIG'.padEnd(origColWidth) + 'DEST'.padEnd(destColWidth) + 'ROUTE\n';
            output += '----'.padEnd(origColWidth) + '----'.padEnd(destColWidth) + '-----\n';

            routes.forEach(r => {
                const rowLines = formatRouteRow(r.origin, r.destination, r.route, r.originFilter, r.destFilter);
                rowLines.forEach(line => {
                    output += line + '\n';
                });
            });

            return output.trim();
        },

        /**
         * Format routes in SPLIT format (ORIGIN SEGMENTS / DESTINATION SEGMENTS)
         * This format groups routes by common segments and shows:
         * - FROM section: Origins with their route segments to the common point
         * - TO section: Common point to destinations
         *
         * Only waypoints are valid split points (not airways or procedures)
         */
        formatSplitRouteTable: function(routes) {
            const MAX_LINE = 68;
            const LABEL_WIDTH = 20;

            if (!routes || !routes.length) {
                return 'ORIG                 ROUTE - ORIGIN SEGMENTS\n----                 -----------------------\n(No routes specified)';
            }

            // Token classification helpers
            // Airways: letter(s) + numbers (J60, V16, Q99, T200, AR10, A216)
            const isAirway = (token) => {
                if (!token) {return false;}
                // Check against known airways if available (awys array from awys.js)
                if (typeof awys !== 'undefined' && Array.isArray(awys)) {
                    const found = awys.some(a => a[0] === token);
                    if (found) {return true;}
                }
                // Pattern match: 1-2 letters followed by digits (J60, V16, Q99, T200, AR10)
                return /^[A-Z]{1,2}\d+$/.test(token);
            };

            // Procedures (SID/STAR): Various patterns including international
            // Examples: RNAV6, CAMRN4, WYNDE3, JFK5, LGA4, EWR1
            const isProcedure = (token) => {
                if (!token) {return false;}

                // Check against loaded procs array if available (procs.js)
                if (typeof procs !== 'undefined' && Array.isArray(procs)) {
                    const found = procs.some(p => p[0] === token);
                    if (found) {return true;}
                }

                // Check against dpAllRootNames and starAllRootNames (from procs_enhanced.js)
                // Extract root name (letters only) from token
                const rootName = token.replace(/\d+$/, '');
                if (rootName) {
                    if (typeof dpAllRootNames !== 'undefined' && dpAllRootNames instanceof Set) {
                        if (dpAllRootNames.has(rootName)) {return true;}
                    }
                    if (typeof starAllRootNames !== 'undefined' && starAllRootNames instanceof Set) {
                        if (starAllRootNames.has(rootName)) {return true;}
                    }
                }

                // Pattern match for procedures:
                // 1. Standard: 2+ letters followed by 1-2 digits (JFK5, WYNDE3)
                // 2. Alphanumeric: digit(s) + letters + digit (1U71, 77S2) - small airport DPs
                // Must avoid matching airways like J6, V16
                if (/^[A-Z]{2,6}\d{1,2}$/.test(token) && token.length >= 3 && token.length <= 8) {
                    // Exclude if it matches airway pattern (1-2 letters + digits)
                    if (/^[A-Z]{1,2}\d+$/.test(token)) {return false;}
                    return true;
                }
                // Alphanumeric small airport DP codes (1U71, 77S2, 0S91, etc.)
                if (/^\d{1,2}[A-Z]{1,2}\d{1,2}$/.test(token) && token.length >= 3 && token.length <= 5) {
                    return true;
                }

                return false;
            };

            // Waypoints: everything that's not an airway or procedure
            // Typically 3-5 letter codes (VORs, fixes) or alphanumeric fix names
            const isWaypoint = (token) => {
                if (!token) {return false;}
                if (isAirway(token)) {return false;}
                if (isProcedure(token)) {return false;}

                // Check against nav_fixes if available
                if (typeof navFixes !== 'undefined' && navFixes instanceof Set) {
                    if (navFixes.has(token)) {return true;}
                }

                // Valid waypoints: 2-5 alphanumeric, predominantly letters
                // Includes VORs (3 letters), 5-letter fixes, and some special codes
                return /^[A-Z]{2,5}$/.test(token) || /^[A-Z]{3,4}[0-9]?$/.test(token);
            };

            // Find first waypoint in tokens starting from index
            const findFirstWaypoint = (tokens, startIdx) => {
                for (let i = startIdx; i < tokens.length; i++) {
                    if (isWaypoint(tokens[i])) {return { idx: i, token: tokens[i] };}
                }
                return null;
            };

            // Find last waypoint in tokens up to (but not including) endIdx
            const findLastWaypoint = (tokens, endIdx) => {
                for (let i = endIdx - 1; i >= 0; i--) {
                    if (isWaypoint(tokens[i])) {return { idx: i, token: tokens[i] };}
                }
                return null;
            };

            // Tokenize all routes for comparison
            const tokenizedRoutes = routes.map(r => ({
                ...r,
                tokens: (r.route || '').toUpperCase().split(/\s+/).filter(Boolean),
                origDisplay: (r.origin || '').toUpperCase(),
                destDisplay: (r.destination || '').toUpperCase(),
                origFilter: (r.originFilter || '').toUpperCase(),
                destFilter: (r.destFilter || '').toUpperCase(),
            }));

            // Find MULTIPLE pivot waypoints - different route groups may have different pivots
            // E.g., JFK routes converge at MCI, PHL routes converge at STL
            const findPivotWaypoints = (routes) => {
                if (routes.length < 2) {return { pivotGroups: [], unmatchedRoutes: routes };}

                const allTokens = routes.map(r => r.tokens);
                const nonEmptyTokens = allTokens.filter(t => t.length > 0);
                if (nonEmptyTokens.length < 2) {return { pivotGroups: [], unmatchedRoutes: routes };}

                // Minimum routes for a waypoint to be considered a pivot (at least 3 routes or 30%)
                const minMatchCount = Math.max(3, Math.ceil(nonEmptyTokens.length * 0.3));

                // Count waypoint occurrences across all routes
                const waypointCounts = {};
                const waypointPositions = {};

                nonEmptyTokens.forEach(tokens => {
                    const routeLength = tokens.length;
                    const seenInThisRoute = new Set();

                    tokens.forEach((token, idx) => {
                        if (isWaypoint(token) && !seenInThisRoute.has(token)) {
                            seenInThisRoute.add(token);
                            waypointCounts[token] = (waypointCounts[token] || 0) + 1;

                            const position = routeLength > 1 ? idx / (routeLength - 1) : 0.5;
                            if (!waypointPositions[token]) {waypointPositions[token] = [];}
                            waypointPositions[token].push(position);
                        }
                    });
                });

                // Find candidate pivot waypoints (appear in enough routes)
                const candidatePivots = Object.entries(waypointCounts)
                    .filter(([wpt, count]) => count >= minMatchCount)
                    .map(([wpt, count]) => {
                        const positions = waypointPositions[wpt];
                        const avgPosition = positions.reduce((a, b) => a + b, 0) / positions.length;
                        const centralityScore = 1 - Math.abs(avgPosition - 0.5) * 2;
                        const matchScore = count / nonEmptyTokens.length;
                        const score = centralityScore * 0.7 + matchScore * 0.3;
                        return { wpt, count, avgPosition, centralityScore, matchScore, score };
                    })
                    .sort((a, b) => b.score - a.score);

                if (candidatePivots.length === 0) {
                    return { pivotGroups: [], unmatchedRoutes: routes };
                }

                // Assign each route to its best pivot (highest scoring pivot it contains)
                const routeAssignments = new Map(); // route index -> pivot wpt
                const unmatchedRoutes = [];

                routes.forEach((r, idx) => {
                    // Find the best pivot this route contains
                    let bestPivot = null;
                    let bestScore = -1;
                    for (const pivot of candidatePivots) {
                        if (r.tokens.includes(pivot.wpt) && pivot.score > bestScore) {
                            bestPivot = pivot;
                            bestScore = pivot.score;
                        }
                    }
                    if (bestPivot) {
                        routeAssignments.set(idx, bestPivot.wpt);
                    } else {
                        unmatchedRoutes.push(r);
                    }
                });

                // Group routes by their assigned pivot
                const pivotGroups = [];
                const usedPivots = new Set(routeAssignments.values());

                for (const pivotWpt of usedPivots) {
                    const pivotInfo = candidatePivots.find(p => p.wpt === pivotWpt);
                    const originSegs = [];
                    const destSegs = [];

                    routes.forEach((r, idx) => {
                        if (routeAssignments.get(idx) !== pivotWpt) {return;}

                        const pivotIdx = r.tokens.indexOf(pivotWpt);
                        if (pivotIdx < 0) {return;}

                        // Origin segment: everything up to and including pivot (excluding origin airport)
                        let origTokens = r.tokens.slice(0, pivotIdx + 1);
                        if (origTokens.length > 0 && origTokens[0] === r.origDisplay) {
                            origTokens = origTokens.slice(1);
                        }

                        // Dest segment: everything from pivot onwards (excluding dest airport)
                        let destTokens = r.tokens.slice(pivotIdx);
                        if (destTokens.length > 0 && destTokens[destTokens.length - 1] === r.destDisplay) {
                            destTokens = destTokens.slice(0, -1);
                        }

                        const origSeg = origTokens.join(' ');
                        const destSeg = destTokens.join(' ');

                        originSegs.push({
                            origin: r.origDisplay,
                            originFilter: r.origFilter,
                            segment: origSeg,
                        });

                        destSegs.push({
                            destination: r.destDisplay,
                            destFilter: r.destFilter,
                            segment: destSeg,
                        });
                    });

                    pivotGroups.push({
                        pivotWpt,
                        pivotInfo,
                        originSegs,
                        destSegs,
                        routeCount: originSegs.length,
                    });
                }

                // Sort groups by route count (most routes first)
                pivotGroups.sort((a, b) => b.routeCount - a.routeCount);

                return { pivotGroups, unmatchedRoutes, candidatePivots };
            };

            const result = findPivotWaypoints(tokenizedRoutes);
            const { pivotGroups, unmatchedRoutes } = result;

            // If no pivot groups found, fall back to showing routes by origin
            if (!pivotGroups || pivotGroups.length === 0) {
                return this.formatSplitByOriginDest(tokenizedRoutes, MAX_LINE, LABEL_WIDTH);
            }

            // Format helper for split rows - handles label AND route wrapping
            // Labels (origin/dest + filters) wrap within their column if too long
            // Routes wrap in the route column
            const formatSplitRow = (label, routeText, labelCol = LABEL_WIDTH) => {
                label = (label || '').toUpperCase().trim();
                routeText = (routeText || '').toUpperCase().trim();

                // Split label on "/" or whitespace to handle "KJFK/KLGA" and "KJFK KLGA" formats
                const labelWords = label.split(/[\s/]+/).filter(Boolean);
                const routeWords = routeText.split(/\s+/).filter(Boolean);

                const lines = [];
                let labelIdx = 0;
                let routeIdx = 0;

                // Continue until both label and route are exhausted
                while (labelIdx < labelWords.length || routeIdx < routeWords.length) {
                    // Build label portion for this line
                    let labelPart = '';
                    while (labelIdx < labelWords.length) {
                        const word = labelWords[labelIdx];
                        // Use "/" as separator for airport codes (compact format)
                        const sep = labelPart ? '/' : '';
                        const addition = sep + word;
                        if (labelPart.length + addition.length <= labelCol - 2) { // Leave 2 chars padding
                            labelPart += addition;
                            labelIdx++;
                        } else if (!labelPart) {
                            // Single word too long - take it anyway
                            labelPart = word.substring(0, labelCol - 2);
                            labelIdx++;
                            break;
                        } else {
                            break;
                        }
                    }

                    // Build route portion for this line
                    let routePart = '';
                    const routeColWidth = MAX_LINE - labelCol;
                    while (routeIdx < routeWords.length) {
                        const word = routeWords[routeIdx];
                        const addition = routePart ? ' ' + word : word;
                        if (routePart.length + addition.length <= routeColWidth) {
                            routePart += addition;
                            routeIdx++;
                        } else if (!routePart) {
                            // Single word too long - take it anyway
                            routePart = word;
                            routeIdx++;
                            break;
                        } else {
                            break;
                        }
                    }

                    lines.push(labelPart.padEnd(labelCol) + routePart);
                }

                return lines.length ? lines.map(l => l.trimEnd()) : [''];
            };

            let output = '';

            // Combine all origin/dest segments from all pivot groups into single sections
            const allOriginSegs = [];
            const allDestSegs = [];
            pivotGroups.forEach(group => {
                allOriginSegs.push(...group.originSegs);
                allDestSegs.push(...group.destSegs);
            });

            // FROM section - all origins combined
            output += 'FROM:\n';
            output += 'ORIG'.padEnd(LABEL_WIDTH) + 'ROUTE - ORIGIN SEGMENTS\n';
            output += '----'.padEnd(LABEL_WIDTH) + '-----------------------\n';

            const byOrigSeg = {};
            allOriginSegs.forEach(s => {
                const origKey = s.origin + (s.originFilter ? ' (' + s.originFilter + ')' : '');
                const fullKey = origKey + '|||' + s.segment;
                if (!byOrigSeg[fullKey]) {
                    byOrigSeg[fullKey] = { origin: origKey, segment: s.segment };
                }
            });

            const byOrig = {};
            Object.values(byOrigSeg).forEach(item => {
                if (!byOrig[item.origin]) {byOrig[item.origin] = new Set();}
                byOrig[item.origin].add(item.segment);
            });

            Object.keys(byOrig).sort().forEach(orig => {
                const segments = Array.from(byOrig[orig]).sort();
                segments.forEach(seg => {
                    formatSplitRow(orig, seg).forEach(line => {
                        output += line + '\n';
                    });
                });
            });

            output += '\n';

            // TO section - all destinations combined
            output += 'TO:\n';
            output += 'DEST'.padEnd(LABEL_WIDTH) + 'ROUTE - DESTINATION SEGMENTS\n';
            output += '----'.padEnd(LABEL_WIDTH) + '----------------------------\n';

            const byDestSeg = {};
            allDestSegs.forEach(s => {
                const destKey = s.destination + (s.destFilter ? ' (' + s.destFilter + ')' : '');
                const fullKey = destKey + '|||' + s.segment;
                if (!byDestSeg[fullKey]) {
                    byDestSeg[fullKey] = { destination: destKey, segment: s.segment };
                }
            });

            const byDest = {};
            Object.values(byDestSeg).forEach(item => {
                if (!byDest[item.destination]) {byDest[item.destination] = new Set();}
                byDest[item.destination].add(item.segment);
            });

            Object.keys(byDest).sort().forEach(dest => {
                const segments = Array.from(byDest[dest]).sort();
                segments.forEach(seg => {
                    formatSplitRow(dest, seg).forEach(line => {
                        output += line + '\n';
                    });
                });
            });

            // Show unmatched routes if any
            if (unmatchedRoutes && unmatchedRoutes.length > 0) {
                output += '\n';
                output += 'OTHER ROUTES:\n';
                output += 'ORIG'.padEnd(10) + 'DEST'.padEnd(10) + 'ROUTE\n';
                output += '----'.padEnd(10) + '----'.padEnd(10) + '-----\n';

                const ORIG_COL = 10;
                const DEST_COL = 10;
                const ROUTE_START = ORIG_COL + DEST_COL;

                unmatchedRoutes.forEach(r => {
                    const orig = (r.origDisplay || '').substring(0, 8).padEnd(ORIG_COL);
                    const dest = (r.destDisplay || '').substring(0, 8).padEnd(DEST_COL);
                    const routeTokens = r.tokens || [];

                    if (routeTokens.length === 0) {
                        output += orig + dest + '\n';
                        return;
                    }

                    // Build route lines with proper wrapping at 68 chars
                    let isFirstLine = true;
                    let currentLine = orig + dest;
                    const routeColWidth = MAX_LINE - ROUTE_START;

                    routeTokens.forEach((token, idx) => {
                        const spaceNeeded = currentLine.length > ROUTE_START ? 1 : 0;
                        const tokenWithSpace = (spaceNeeded ? ' ' : '') + token;

                        if (currentLine.length + tokenWithSpace.length <= MAX_LINE) {
                            currentLine += tokenWithSpace;
                        } else {
                            // Line would exceed limit - output current and start new line
                            output += currentLine.trimEnd() + '\n';
                            // Continuation lines start at ROUTE column position
                            currentLine = ' '.repeat(ROUTE_START) + token;
                            isFirstLine = false;
                        }
                    });

                    // Output final line
                    if (currentLine.trim()) {
                        output += currentLine.trimEnd() + '\n';
                    }
                });
            }

            return output.trim();
        },

        /**
         * Format split by origin/dest when no common segment found
         * Falls back to grouping by origin for FROM and by dest for TO
         */
        formatSplitByOriginDest: function(routes, maxLine, labelWidth) {
            // Format helper - handles label AND route wrapping
            const formatSplitRow = (label, routeText) => {
                label = (label || '').toUpperCase().trim();
                routeText = (routeText || '').toUpperCase().trim();

                // Split label on "/" or whitespace to handle "KJFK/KLGA" and "KJFK KLGA" formats
                const labelWords = label.split(/[\s/]+/).filter(Boolean);
                const routeWords = routeText.split(/\s+/).filter(Boolean);

                const lines = [];
                let labelIdx = 0;
                let routeIdx = 0;

                while (labelIdx < labelWords.length || routeIdx < routeWords.length) {
                    let labelPart = '';
                    while (labelIdx < labelWords.length) {
                        const word = labelWords[labelIdx];
                        // Use "/" as separator for airport codes (compact format)
                        const sep = labelPart ? '/' : '';
                        const addition = sep + word;
                        if (labelPart.length + addition.length <= labelWidth - 2) {
                            labelPart += addition;
                            labelIdx++;
                        } else if (!labelPart) {
                            labelPart = word.substring(0, labelWidth - 2);
                            labelIdx++;
                            break;
                        } else {
                            break;
                        }
                    }

                    let routePart = '';
                    const routeColWidth = maxLine - labelWidth;
                    while (routeIdx < routeWords.length) {
                        const word = routeWords[routeIdx];
                        const addition = routePart ? ' ' + word : word;
                        if (routePart.length + addition.length <= routeColWidth) {
                            routePart += addition;
                            routeIdx++;
                        } else if (!routePart) {
                            routePart = word;
                            routeIdx++;
                            break;
                        } else {
                            break;
                        }
                    }

                    lines.push(labelPart.padEnd(labelWidth) + routePart);
                }

                return lines.length ? lines.map(l => l.trimEnd()) : [''];
            };

            let output = '';

            // Group by origin
            const byOrig = {};
            routes.forEach(r => {
                const origKey = r.origDisplay + (r.origFilter ? ' (' + r.origFilter + ')' : '');
                if (!byOrig[origKey]) {byOrig[origKey] = [];}
                byOrig[origKey].push(r);
            });

            // FROM section
            output += 'FROM:\n';
            output += 'ORIG'.padEnd(labelWidth) + 'ROUTE - ORIGIN SEGMENTS\n';
            output += '----'.padEnd(labelWidth) + '-----------------------\n';

            Object.keys(byOrig).sort().forEach(orig => {
                const origRoutes = byOrig[orig];
                const seenRoutes = new Set();
                origRoutes.forEach(r => {
                    const routeStr = r.tokens.join(' ');
                    if (routeStr && !seenRoutes.has(routeStr)) {
                        seenRoutes.add(routeStr);
                        formatSplitRow(orig, routeStr).forEach(line => {
                            output += line + '\n';
                        });
                    }
                });
            });

            output += '\n';

            // Group by destination
            const byDest = {};
            routes.forEach(r => {
                const destKey = r.destDisplay + (r.destFilter ? ' (' + r.destFilter + ')' : '');
                if (!byDest[destKey]) {byDest[destKey] = [];}
                byDest[destKey].push(r);
            });

            // TO section
            output += 'TO:\n';
            output += 'DEST'.padEnd(labelWidth) + 'ROUTE - DESTINATION SEGMENTS\n';
            output += '----'.padEnd(labelWidth) + '----------------------------\n';

            Object.keys(byDest).sort().forEach(dest => {
                const destRoutes = byDest[dest];
                const seenRoutes = new Set();
                destRoutes.forEach(r => {
                    const routeStr = r.tokens.join(' ');
                    if (routeStr && !seenRoutes.has(routeStr)) {
                        seenRoutes.add(routeStr);
                        formatSplitRow(dest, routeStr).forEach(line => {
                            output += line + '\n';
                        });
                    }
                });
            });

            return output.trim();
        },

        /**
         * Detect common segments in routes and show analysis
         * Only considers waypoints as valid split points (not airways or procedures)
         */
        detectCommonSegments: function() {
            const routes = this.collectRoutes();
            if (routes.length < 2) {
                Swal.fire({
                    icon: 'info',
                    title: 'Need More Routes',
                    text: 'Need at least 2 routes to detect common segments.',
                    timer: 2000,
                    showConfirmButton: false,
                });
                return;
            }

            // Token classification helpers (same as formatSplitRouteTable)
            const isAirway = (token) => {
                if (!token) {return false;}
                if (typeof awys !== 'undefined' && Array.isArray(awys)) {
                    const found = awys.some(a => a[0] === token);
                    if (found) {return true;}
                }
                return /^[A-Z]{1,2}\d+$/.test(token);
            };

            const isProcedure = (token) => {
                if (!token) {return false;}
                // Check against loaded procs array
                if (typeof procs !== 'undefined' && Array.isArray(procs)) {
                    const found = procs.some(p => p[0] === token);
                    if (found) {return true;}
                }
                // Check against dpAllRootNames and starAllRootNames
                const rootName = token.replace(/\d+$/, '');
                if (rootName) {
                    if (typeof dpAllRootNames !== 'undefined' && dpAllRootNames instanceof Set) {
                        if (dpAllRootNames.has(rootName)) {return true;}
                    }
                    if (typeof starAllRootNames !== 'undefined' && starAllRootNames instanceof Set) {
                        if (starAllRootNames.has(rootName)) {return true;}
                    }
                }
                // Pattern: 2+ letters followed by 1-2 digits (JFK5, WYNDE3)
                if (/^[A-Z]{2,6}\d{1,2}$/.test(token) && token.length >= 3 && token.length <= 8) {
                    if (/^[A-Z]{1,2}\d+$/.test(token)) {return false;}
                    return true;
                }
                // Alphanumeric small airport DP codes (1U71, 77S2, 0S91, etc.)
                if (/^\d{1,2}[A-Z]{1,2}\d{1,2}$/.test(token) && token.length >= 3 && token.length <= 5) {
                    return true;
                }
                return false;
            };

            const isWaypoint = (token) => {
                if (!token) {return false;}
                if (isAirway(token)) {return false;}
                if (isProcedure(token)) {return false;}
                if (typeof navFixes !== 'undefined' && navFixes instanceof Set) {
                    if (navFixes.has(token)) {return true;}
                }
                return /^[A-Z]{2,5}$/.test(token) || /^[A-Z]{3,4}[0-9]?$/.test(token);
            };

            // Tokenize routes
            const tokenizedRoutes = routes.map(r => ({
                ...r,
                tokens: (r.route || '').toUpperCase().split(/\s+/).filter(Boolean),
            }));

            // Find pivot waypoints - common waypoints that routes converge through
            const allTokens = tokenizedRoutes.map(r => r.tokens);
            const nonEmptyTokens = allTokens.filter(t => t.length > 0);
            // Lower threshold: 3+ routes or 30% (whichever is higher)
            const minMatchCount = Math.max(3, Math.ceil(nonEmptyTokens.length * 0.3));

            // Count waypoint occurrences across all routes
            const waypointCounts = {};
            const waypointPositions = {};

            nonEmptyTokens.forEach(tokens => {
                const routeLength = tokens.length;
                const seenInThisRoute = new Set();

                tokens.forEach((token, idx) => {
                    if (isWaypoint(token) && !seenInThisRoute.has(token)) {
                        seenInThisRoute.add(token);
                        waypointCounts[token] = (waypointCounts[token] || 0) + 1;

                        const position = routeLength > 1 ? idx / (routeLength - 1) : 0.5;
                        if (!waypointPositions[token]) {waypointPositions[token] = [];}
                        waypointPositions[token].push(position);
                    }
                });
            });

            // Find candidate pivot waypoints
            const candidatePivots = Object.entries(waypointCounts)
                .filter(([wpt, count]) => count >= minMatchCount)
                .map(([wpt, count]) => {
                    const positions = waypointPositions[wpt];
                    const avgPosition = positions.reduce((a, b) => a + b, 0) / positions.length;
                    const centralityScore = 1 - Math.abs(avgPosition - 0.5) * 2;
                    const matchScore = count / nonEmptyTokens.length;
                    const score = centralityScore * 0.7 + matchScore * 0.3;
                    return { wpt, count, avgPosition, centralityScore, matchScore, score };
                })
                .sort((a, b) => b.score - a.score);

            if (candidatePivots.length > 0) {
                // Assign each route to its best pivot
                const pivotRouteCounts = {};
                let unmatchedCount = 0;

                tokenizedRoutes.forEach(r => {
                    let assigned = false;
                    for (const pivot of candidatePivots) {
                        if (r.tokens.includes(pivot.wpt)) {
                            pivotRouteCounts[pivot.wpt] = (pivotRouteCounts[pivot.wpt] || 0) + 1;
                            assigned = true;
                            break;
                        }
                    }
                    if (!assigned) {unmatchedCount++;}
                });

                // Build table showing pivots that will be used
                const usedPivots = candidatePivots.filter(p => pivotRouteCounts[p.wpt] > 0);
                const candidatesHtml = usedPivots.slice(0, 8).map((c, i) => {
                    const pos = Math.round(c.avgPosition * 100);
                    const routesAssigned = pivotRouteCounts[c.wpt] || 0;
                    return `<tr><td><b>${c.wpt}</b></td><td>${routesAssigned} routes</td><td>${pos}%</td></tr>`;
                }).join('');

                Swal.fire({
                    icon: 'success',
                    title: `${usedPivots.length} Pivot Waypoint${usedPivots.length > 1 ? 's' : ''} Detected`,
                    html: `
                        <p>Found ${usedPivots.length} pivot waypoint${usedPivots.length > 1 ? 's' : ''} across ${routes.length} routes.</p>
                        ${unmatchedCount > 0 ? `<p class="small text-warning">${unmatchedCount} routes don't match any pivot.</p>` : ''}
                        <table class="table table-sm mt-3" style="font-size: 0.85rem;">
                            <thead><tr><th>Pivot</th><th>Routes</th><th>Avg Position</th></tr></thead>
                            <tbody>${candidatesHtml}</tbody>
                        </table>
                        <p class="small text-muted mt-2">Each route is assigned to its best matching pivot based on centrality scoring.</p>
                    `,
                    confirmButtonText: 'Use Split Format',
                    showCancelButton: true,
                    cancelButtonText: 'Close',
                    width: 500,
                }).then(result => {
                    if (result.isConfirmed) {
                        $('#rr_format_split').prop('checked', true);
                        this.generatePreview();
                    }
                });
            } else {
                Swal.fire({
                    icon: 'info',
                    title: 'No Pivot Waypoints Found',
                    html: `
                        <p>No waypoint found in at least 3 routes (or 30% of ${routes.length} routes).</p>
                        <p class="small text-muted">Routes will be displayed grouped by origin and destination.</p>
                    `,
                    timer: 3000,
                    showConfirmButton: false,
                });
            }
        },

        /**
         * Collect routes from form
         */
        collectRoutes: function() {
            const routes = [];
            $('#rr_routes_body tr:not(.rr-empty-row)').each(function() {
                const $row = $(this);
                const origin = $row.find('.rr-route-origin').val() || '';
                const dest = $row.find('.rr-route-dest').val() || '';
                const route = $row.find('.rr-route-string').val() || '';
                const originFilter = $row.find('.rr-route-orig-filter').val() || '';
                const destFilter = $row.find('.rr-route-dest-filter').val() || '';

                if (origin || dest || route) {
                    routes.push({
                        origin,
                        destination: dest,
                        route,
                        originFilter: originFilter.trim(),
                        destFilter: destFilter.trim(),
                    });
                }
            });
            return routes;
        },

        /**
         * Get selected facilities
         */
        getSelectedFacilities: function() {
            const facilities = [];
            $('.rr-facility-cb:checked').each(function() {
                facilities.push($(this).val());
            });
            return facilities;
        },

        /**
         * Save draft to database
         */
        saveDraft: async function() {
            const routes = this.collectRoutes();
            const facilities = this.getSelectedFacilities();

            const draftData = {
                version: '1.0',
                timestamp: new Date().toISOString(),
                source: 'tmi-publisher',
                advisory: {
                    facility: $('#rr_facility').val() || 'DCC',
                    name: $('#rr_name').val() || '',
                    routeType: $('#rr_route_type').val() || 'ROUTE',
                    compliance: $('#rr_compliance').val() || 'RQD',
                    constrainedArea: $('#rr_constrained_area').val() || '',
                    reason: $('#rr_reason').val() || 'WEATHER',
                    includeTraffic: $('#rr_include_traffic').val() || '',
                    validStart: $('#rr_valid_from').val() || '',
                    validEnd: $('#rr_valid_until').val() || '',
                    timeBasis: $('#rr_time_basis').val() || 'ETD',
                    probExtension: $('#rr_prob_extension').val() || 'MEDIUM',
                    remarks: $('#rr_remarks').val() || '',
                    restrictions: $('#rr_restrictions').val() || '',
                    modifications: $('#rr_modifications').val() || '',
                },
                facilities: facilities,
                routes: routes,
                geojson: this.rerouteData?.geojson || null,
            };

            try {
                const response = await fetch('/api/mgt/tmi/reroute-drafts.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        user_cid: this.userCid,
                        user_name: this.userName,
                        draft_name: draftData.advisory.name || `${routes[0]?.origin || 'Unknown'}-${routes[0]?.destination || 'Unknown'} Reroute`,
                        draft_data: draftData,
                    }),
                });

                const result = await response.json();

                if (result.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Draft Saved',
                        text: `Draft saved successfully (ID: ${result.draft_id})`,
                        timer: 2000,
                        showConfirmButton: false,
                    });
                    this.loadDraftsList();
                } else {
                    throw new Error(result.error || 'Unknown error');
                }

            } catch (e) {
                Swal.fire('Error', 'Failed to save draft: ' + e.message, 'error');
            }
        },

        /**
         * Load drafts list for current user
         */
        loadDraftsList: async function() {
            const $list = $('#rr_drafts_list');

            if (!this.userCid) {
                $list.html('<div class="list-group-item text-center text-muted small">Sign in to save drafts</div>');
                return;
            }

            try {
                const response = await fetch(`/api/mgt/tmi/reroute-drafts.php?user_cid=${this.userCid}&limit=10`);
                const result = await response.json();

                if (!result.success || !result.drafts.length) {
                    $list.html('<div class="list-group-item text-center text-muted small">No saved drafts</div>');
                    return;
                }

                let html = '';
                const self = this;
                result.drafts.forEach(draft => {
                    const updatedAt = new Date(draft.updated_at).toLocaleString();
                    html += `
                        <a href="#" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center rr-draft-item"
                           data-draft-id="${draft.draft_id}">
                            <div>
                                <div class="font-weight-bold small">${self.escapeHtml(draft.draft_name || 'Untitled')}</div>
                                <small class="text-muted">${updatedAt}</small>
                            </div>
                            <button class="btn btn-sm btn-outline-danger rr-delete-draft" data-draft-id="${draft.draft_id}"
                                    onclick="event.stopPropagation(); RerouteHandler.deleteDraft(${draft.draft_id});">
                                <i class="fas fa-trash"></i>
                            </button>
                        </a>
                    `;
                });

                $list.html(html);

            } catch (e) {
                console.error('[REROUTE] Failed to load drafts:', e);
                $list.html('<div class="list-group-item text-center text-danger small">Failed to load drafts</div>');
            }
        },

        /**
         * Load a specific draft
         */
        loadDraft: async function(draftId) {
            try {
                const response = await fetch(`/api/mgt/tmi/reroute-drafts.php?draft_id=${draftId}`);
                const result = await response.json();

                if (!result.success || !result.draft) {
                    throw new Error('Draft not found');
                }

                this.rerouteData = result.draft.draft_data;
                this.populateForm();

                Swal.fire({
                    icon: 'success',
                    title: 'Draft Loaded',
                    timer: 1500,
                    showConfirmButton: false,
                });

            } catch (e) {
                Swal.fire('Error', 'Failed to load draft: ' + e.message, 'error');
            }
        },

        /**
         * Delete a draft
         */
        deleteDraft: async function(draftId) {
            const confirm = await Swal.fire({
                title: 'Delete Draft?',
                text: 'This cannot be undone.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                confirmButtonText: 'Delete',
            });

            if (!confirm.isConfirmed) {return;}

            try {
                const response = await fetch(`/api/mgt/tmi/reroute-drafts.php?draft_id=${draftId}`, {
                    method: 'DELETE',
                });
                const result = await response.json();

                if (result.success) {
                    this.loadDraftsList();
                } else {
                    throw new Error(result.error || 'Unknown error');
                }

            } catch (e) {
                Swal.fire('Error', 'Failed to delete draft: ' + e.message, 'error');
            }
        },

        /**
         * Submit for coordination
         */
        submitForCoordination: async function() {
            const routes = this.collectRoutes();
            const facilities = this.getSelectedFacilities();

            if (!routes.length) {
                PERTIDialog.error('common.error', 'validation.routeRequired');
                return;
            }

            if (!facilities.length) {
                PERTIDialog.error('common.error', 'validation.facilityCoordRequired');
                return;
            }

            // Ensure preview is generated before submitting
            this.generatePreview();

            // Build entry data
            const entryData = {
                entryType: 'REROUTE',
                data: {
                    entry_type: 'REROUTE',
                    req_facility: $('#rr_facility').val() || 'DCC',
                    name: $('#rr_name').val() || '',
                    route_type: $('#rr_route_type').val() || 'ROUTE',
                    compliance: $('#rr_compliance').val() || 'RQD',
                    constrained_area: $('#rr_constrained_area').val() || '',
                    reason: $('#rr_reason').val() || 'WEATHER',
                    include_traffic: $('#rr_include_traffic').val() || '',
                    valid_from: $('#rr_valid_from').val() || '',
                    valid_until: $('#rr_valid_until').val() || '',
                    time_basis: $('#rr_time_basis').val() || 'ETD',
                    airborne_filter: $('#rr_airborne_filter').val() || 'NOT_AIRBORNE',
                    prob_extension: $('#rr_prob_extension').val() || 'MEDIUM',
                    remarks: $('#rr_remarks').val() || '',
                    routes: routes,
                    geojson: this.rerouteData?.geojson || null,
                },
                rawText: $('#rr_preview_text').text(),
            };

            // Get selected Discord orgs
            const orgs = [];
            $('.discord-org-checkbox-rr:checked').each(function() {
                orgs.push($(this).val());
            });

            // Show deadline dialog
            const { value: deadline } = await Swal.fire({
                title: 'Coordination Deadline',
                html: `
                    <p class="mb-2">Set deadline for facility approvals:</p>
                    <input type="datetime-local" id="swal-deadline" class="swal2-input"
                           style="width: 100%;">
                    <p class="small text-muted mt-2">
                        Facilities: ${facilities.join(', ')}
                    </p>
                `,
                didOpen: () => {
                    // Default: 30 minutes from now
                    const defaultDeadline = new Date(Date.now() + 30 * 60000);
                    const isoStr = defaultDeadline.toISOString().slice(0, 16);
                    document.getElementById('swal-deadline').value = isoStr;
                },
                preConfirm: () => {
                    return document.getElementById('swal-deadline').value;
                },
                showCancelButton: true,
                confirmButtonText: 'Submit',
                cancelButtonText: 'Cancel',
            });

            if (!deadline) {return;}

            // Submit to coordination API
            Swal.fire({
                title: 'Submitting...',
                text: 'Creating coordination proposal',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading(),
            });

            try {
                const response = await fetch('/api/mgt/tmi/coordinate.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        entry: entryData,
                        deadlineUtc: deadline + ':00.000Z',
                        facilities: facilities.map(f => ({ code: f })),
                        orgs: orgs,
                        userCid: this.userCid,
                        userName: this.userName,
                    }),
                });

                const result = await response.json();

                if (result.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Submitted for Coordination',
                        html: `
                            <p>Proposal ID: <strong>${result.proposal_id}</strong></p>
                            ${result.auto_approved ?
        '<p class="text-success">Auto-approved (internal TMI)</p>' :
        '<p>Awaiting facility approvals on Discord.</p>'
    }
                        `,
                        confirmButtonText: 'View in Coordination Tab',
                    }).then(() => {
                        // Switch to coordination tab
                        $('#coordination-tab').tab('show');
                        // Refresh proposals list
                        if (typeof loadProposals === 'function') {
                            loadProposals();
                        }
                    });
                } else {
                    throw new Error(result.error || 'Unknown error');
                }

            } catch (e) {
                Swal.fire('Error', 'Failed to submit: ' + e.message, 'error');
            }
        },

        /**
         * Reset form
         */
        resetForm: function() {
            this.rerouteData = null;
            $('#reroutePanel input:not([type="checkbox"]), #reroutePanel textarea').val('');
            $('#reroutePanel select').each(function() {
                $(this).val($(this).find('option:first').val());
            });
            $('#rr_routes_body').html(`
                <tr class="rr-empty-row">
                    <td colspan="4" class="text-center text-muted py-3">
                        No routes loaded. Use Route Plotter to create routes, or add manually.
                    </td>
                </tr>
            `);
            $('.rr-facility-cb').prop('checked', false);
            $('#rerouteSourceInfo').hide();
            $('#rr_preview_text').text('Generate preview to see advisory text...');
            this.fetchNextAdvisoryNumber();
        },

        /**
         * Add empty route row
         */
        addRouteRow: function() {
            const $tbody = $('#rr_routes_body');
            // Remove empty row placeholder if present
            $tbody.find('.rr-empty-row').remove();

            const idx = $tbody.find('tr').length;
            $tbody.append(`
                <tr data-idx="${idx}">
                    <td class="text-center align-middle" style="padding: 0.15rem;">
                        <input type="checkbox" class="rr-route-select" data-idx="${idx}">
                    </td>
                    <td style="padding: 0.15rem;">
                        <input type="text" class="form-control form-control-sm rr-route-origin"
                               style="font-family: monospace; font-size: 0.7rem; padding: 0.15rem 0.25rem;" placeholder="KABC">
                    </td>
                    <td style="padding: 0.15rem;">
                        <input type="text" class="form-control form-control-sm rr-route-orig-filter"
                               style="font-family: monospace; font-size: 0.65rem; padding: 0.15rem 0.25rem;" placeholder="-APT">
                    </td>
                    <td style="padding: 0.15rem;">
                        <input type="text" class="form-control form-control-sm rr-route-dest"
                               style="font-family: monospace; font-size: 0.7rem; padding: 0.15rem 0.25rem;" placeholder="KXYZ">
                    </td>
                    <td style="padding: 0.15rem;">
                        <input type="text" class="form-control form-control-sm rr-route-dest-filter"
                               style="font-family: monospace; font-size: 0.65rem; padding: 0.15rem 0.25rem;" placeholder="-APT">
                    </td>
                    <td style="padding: 0.15rem;">
                        <textarea class="form-control form-control-sm rr-route-string" rows="1"
                               style="font-family: monospace; font-size: 0.7rem; padding: 0.15rem 0.25rem; resize: vertical; min-height: 26px;" placeholder="DCT FIX1 J123 FIX2 DCT"></textarea>
                    </td>
                    <td style="padding: 0.15rem;">
                        <button class="btn btn-sm btn-outline-danger rr-remove-route py-0 px-1"
                                data-idx="${idx}"><i class="fas fa-times"></i></button>
                    </td>
                </tr>
            `);
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            const self = this;

            $('#rr_preview').on('click', () => self.generatePreview());
            $('#rr_submit_coordination').on('click', () => self.submitForCoordination());
            $('#rr_save_draft').on('click', () => self.saveDraft());
            $('#rr_refresh_drafts').on('click', () => self.loadDraftsList());

            // Copy preview to clipboard
            $('#rr_copy_preview').on('click', function() {
                const previewText = $('#rr_preview_text').text();
                if (!previewText || previewText === 'Generate preview to see advisory text...') {
                    Swal.fire({
                        icon: 'warning',
                        title: 'No Preview',
                        text: 'Generate a preview first before copying.',
                        timer: 2000,
                        showConfirmButton: false,
                    });
                    return;
                }

                navigator.clipboard.writeText(previewText).then(() => {
                    // Temporarily change button to show success
                    const $btn = $(this);
                    const originalHtml = $btn.html();
                    $btn.html('<i class="fas fa-check"></i>').addClass('btn-success').removeClass('btn-outline-secondary');
                    setTimeout(() => {
                        $btn.html(originalHtml).removeClass('btn-success').addClass('btn-outline-secondary');
                    }, 1500);
                }).catch(err => {
                    console.error('Failed to copy:', err);
                    Swal.fire({
                        icon: 'error',
                        title: 'Copy Failed',
                        text: 'Could not copy to clipboard. Please select and copy manually.',
                        timer: 3000,
                        showConfirmButton: false,
                    });
                });
            });

            $('#rr_add_route').on('click', () => self.addRouteRow());
            $('#rr_make_mandatory').on('click', () => self.makeRoutesMandatory());
            $('#rr_group_routes').on('click', () => self.groupRoutes());
            $('#rr_auto_filters').on('click', () => self.autoDetectFilters());
            $('#rr_detect_common').on('click', () => self.detectCommonSegments());

            // Select all routes checkbox
            $('#rr_select_all_routes').on('change', function() {
                const isChecked = $(this).is(':checked');
                $('.rr-route-select').prop('checked', isChecked);
            });

            $(document).on('click', '.rr-remove-route', function() {
                $(this).closest('tr').remove();
                // Show empty row if no routes left
                if ($('#rr_routes_body tr').length === 0) {
                    $('#rr_routes_body').html(`
                        <tr class="rr-empty-row">
                            <td colspan="7" class="text-center text-muted py-3">
                                No routes loaded.
                            </td>
                        </tr>
                    `);
                }
            });

            $(document).on('click', '.rr-draft-item', function(e) {
                e.preventDefault();
                const draftId = $(this).data('draft-id');
                self.loadDraft(draftId);
            });

            $('#rr_reset').on('click', () => {
                Swal.fire({
                    title: 'Reset Form?',
                    text: 'This will clear all fields.',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Reset',
                }).then((result) => {
                    if (result.isConfirmed) {
                        self.resetForm();
                    }
                });
            });
        },

        // Helper methods
        escapeHtml: function(str) {
            return String(str || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        },

        parseValidTime: function(timeStr) {
            // Convert DDHHMM to datetime-local format
            if (!timeStr || timeStr.length < 6) {return null;}
            const now = new Date();
            const dd = timeStr.slice(0, 2);
            const hh = timeStr.slice(2, 4);
            const mm = timeStr.slice(4, 6);
            const year = now.getUTCFullYear();
            const month = (now.getUTCMonth() + 1).toString().padStart(2, '0');
            return `${year}-${month}-${dd}T${hh}:${mm}`;
        },

        autoGenerateIncludeTraffic: function() {
            if (!this.rerouteData?.routes?.length) {return;}

            const origins = new Set();
            const dests = new Set();

            this.rerouteData.routes.forEach(r => {
                if (r.origin) {origins.add(r.origin.toUpperCase());}
                if (r.destination) {dests.add(r.destination.toUpperCase());}
                (r.originAirports || []).forEach(a => origins.add(a.toUpperCase()));
                (r.destAirports || []).forEach(a => dests.add(a.toUpperCase()));
            });

            if (origins.size && dests.size) {
                const originStr = Array.from(origins).map(a =>
                    a.startsWith('K') ? a : 'K' + a,
                ).join('/');
                const destStr = Array.from(dests).map(a =>
                    a.startsWith('K') ? a : 'K' + a,
                ).join('/');

                $('#rr_include_traffic').val(`${originStr} DEPARTURES TO ${destStr}`);
            }
        },
    };

    // Expose RerouteHandler globally for event handlers
    window.RerouteHandler = RerouteHandler;

    // ===========================================
    // Public API
    // ===========================================

    window.TMIPublisher = {
        removeFromQueue: removeFromQueue,
        previewEntry: previewEntry,
        submitSingleEntry: submitSingleEntry,
        clearQueue: clearQueue,
        addNtmlToQueue: addNtmlToQueue,
        resetNtmlForm: resetNtmlForm,
        promoteEntry: promoteEntry,
        loadStagedEntries: loadStagedEntries,
        viewTmiDetails: viewTmiDetails,
        cancelTmi: cancelTmi,
        updateCauseOptions: updateCauseOptions,
        saveProfile: saveProfile,
        showProfileModal: showProfileModal,
        loadProposals: loadProposals,
    };

})();
