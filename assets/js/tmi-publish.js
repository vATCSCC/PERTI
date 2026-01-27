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
 * @version 1.6.0
 * @date 2026-01-27
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
    const ARTCCS = {
        'ZBW': 'Boston Center', 'ZNY': 'New York Center', 'ZDC': 'Washington Center',
        'ZTL': 'Atlanta Center', 'ZJX': 'Jacksonville Center', 'ZMA': 'Miami Center',
        'ZOB': 'Cleveland Center', 'ZID': 'Indianapolis Center', 'ZAU': 'Chicago Center',
        'ZMP': 'Minneapolis Center', 'ZKC': 'Kansas City Center', 'ZME': 'Memphis Center',
        'ZFW': 'Fort Worth Center', 'ZHU': 'Houston Center', 'ZAB': 'Albuquerque Center',
        'ZDV': 'Denver Center', 'ZLC': 'Salt Lake Center', 'ZLA': 'Los Angeles Center',
        'ZOA': 'Oakland Center', 'ZSE': 'Seattle Center', 'ZAN': 'Anchorage Center',
        'ZHN': 'Honolulu Center',
        // Canadian
        'CZYZ': 'Toronto FIR', 'CZWG': 'Winnipeg FIR', 'CZVR': 'Vancouver FIR',
        'CZEG': 'Edmonton FIR', 'CZQM': 'Moncton FIR', 'CZQX': 'Gander FIR'
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
            { code: 'RVSM', label: 'RVSM', desc: 'RVSM-compliant only' }
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
            { code: 'VOLUME', label: 'Volume (General)' },
            { code: 'COMPACTED DEMAND', label: 'Compacted Demand' },
            { code: 'MULTI-TAXI', label: 'Multi-Taxi' },
            { code: 'AIRSPACE', label: 'Airspace' }
        ],
        WEATHER: [
            { code: 'WEATHER', label: 'Weather (General)' },
            { code: 'THUNDERSTORMS', label: 'Thunderstorms' },
            { code: 'LOW CEILINGS', label: 'Low Ceilings' },
            { code: 'LOW VISIBILITY', label: 'Low Visibility' },
            { code: 'FOG', label: 'Fog' },
            { code: 'WIND', label: 'Wind' },
            { code: 'SNOW/ICE', label: 'Snow/Ice' }
        ],
        RUNWAY: [
            { code: 'RUNWAY', label: 'Runway (General)' },
            { code: 'RUNWAY CONFIGURATION', label: 'Runway Configuration' },
            { code: 'RUNWAY CONSTRUCTION', label: 'Runway Construction' },
            { code: 'RUNWAY CLOSURE', label: 'Runway Closure' }
        ],
        EQUIPMENT: [
            { code: 'EQUIPMENT', label: 'Equipment (General)' },
            { code: 'FAA EQUIPMENT', label: 'FAA Equipment' },
            { code: 'NON-FAA EQUIPMENT', label: 'Non-FAA Equipment' }
        ],
        OTHER: [
            { code: 'OTHER', label: 'Other (General)' },
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
        updateUI();
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
        // Load advisory counters from localStorage or fetch from server
        const today = getUtcDateString();
        const saved = localStorage.getItem('tmi_advisory_counters');
        
        if (saved) {
            try {
                const data = JSON.parse(saved);
                // Reset if different day (midnight UTC reset)
                if (data.date !== today) {
                    state.advisoryCounters = { date: today, opsplan: 1, freeform: 1, hotline: 1, swap: 1 };
                    saveAdvisoryCounters();
                } else {
                    state.advisoryCounters = data;
                }
            } catch (e) {
                state.advisoryCounters = { date: today, opsplan: 1, freeform: 1, hotline: 1, swap: 1 };
            }
        } else {
            state.advisoryCounters = { date: today, opsplan: 1, freeform: 1, hotline: 1, swap: 1 };
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
            // Reset counters at midnight UTC
            const newDate = getUtcDateString();
            state.advisoryCounters = { date: newDate, opsplan: 1, freeform: 1, hotline: 1, swap: 1 };
            saveAdvisoryCounters();
            console.log('Advisory counters reset at midnight UTC');
            
            // Schedule next reset
            scheduleAdvisoryCounterReset();
        }, msUntilMidnight);
    }
    
    function saveAdvisoryCounters() {
        localStorage.setItem('tmi_advisory_counters', JSON.stringify(state.advisoryCounters));
    }
    
    function getNextAdvisoryNumber(type) {
        // Check if we need to reset (new day)
        const today = getUtcDateString();
        if (state.advisoryCounters.date !== today) {
            state.advisoryCounters = { date: today, opsplan: 1, freeform: 1, hotline: 1, swap: 1 };
        }
        
        const key = type.toLowerCase();
        const num = state.advisoryCounters[key] || 1;
        state.advisoryCounters[key] = num + 1;
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
        
        $('#ntml_form_container').html(formHtml);
        initNtmlFormHandlers(type);
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
            
            // Altitude
            html += '<div class="qualifier-group mb-2">';
            html += '<span class="small text-muted mr-2">Altitude:</span>';
            NTML_QUALIFIERS.altitude.forEach(q => {
                html += `<button type="button" class="btn btn-outline-secondary btn-sm qualifier-btn mr-1 mb-1" data-qualifier="${q.code}" data-group="altitude" title="${q.desc}">${q.label}</button>`;
            });
            html += '</div>';
        }
        
        html += '</div></div>';
        return html;
    }
    
    function buildReasonSelect() {
        let html = `
            <div class="row">
                <div class="col-6">
                    <label class="form-label small text-muted">Category</label>
                    <select class="form-control" id="ntml_reason_category" onchange="TMIPublisher.updateCauseOptions()">
        `;
        REASON_CATEGORIES.forEach(r => {
            html += `<option value="${r.code}">${r.label}</option>`;
        });
        html += `
                    </select>
                </div>
                <div class="col-6">
                    <label class="form-label small text-muted">Cause</label>
                    <select class="form-control" id="ntml_reason_cause">
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
                    <!-- Row 1: Value, Airport/Fix, Via -->
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <label class="form-label small text-muted">${unit}</label>
                            <input type="number" class="form-control" id="ntml_value" min="5" max="100" step="5" value="20">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Airport/Fix</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_ctl_element" placeholder="JFK" maxlength="10">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small text-muted">Via Route/Fix</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_via_fix" placeholder="CLIPR/SKILS or ALL" maxlength="30">
                        </div>
                    </div>
                    
                    <!-- Row 2: Reason (Category:Cause) -->
                    <div class="row mb-3">
                        <div class="col-12">
                            <label class="form-label small text-muted">Reason (Category:Cause)</label>
                            ${buildReasonSelect()}
                        </div>
                    </div>
                    
                    <!-- Row 3: Facilities + Exclusions -->
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Requesting Facility</label>
                            <input type="text" class="form-control text-uppercase facility-autocomplete" id="ntml_req_facility" placeholder="ZNY" maxlength="4" list="facilityList">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Providing Facility</label>
                            <input type="text" class="form-control text-uppercase facility-autocomplete" id="ntml_prov_facility" placeholder="ZOB" maxlength="4" list="facilityList">
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
                            <input type="time" class="form-control" id="ntml_valid_from" value="${times.start}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Valid Until (UTC)</label>
                            <input type="time" class="form-control" id="ntml_valid_until" value="${times.end}">
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
                    <!-- Row 1: Airport/Fix, Via -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Airport/Fix</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_ctl_element" placeholder="KJFK" maxlength="10">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Via Route/Fix</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_via_fix" placeholder="LENDY or ALL" maxlength="30">
                        </div>
                    </div>
                    
                    <!-- Row 2: Reason (Category:Cause) -->
                    <div class="row mb-3">
                        <div class="col-12">
                            <label class="form-label small text-muted">Reason (Category:Cause)</label>
                            ${buildReasonSelect()}
                        </div>
                    </div>
                    
                    <!-- Row 3: Facilities + Exclusions -->
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Requesting Facility</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_req_facility" placeholder="ZNY" maxlength="4" list="facilityList">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Providing Facility</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_prov_facility" placeholder="ZBW" maxlength="4" list="facilityList">
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
                            <input type="time" class="form-control" id="ntml_valid_from" value="${times.start}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Valid Until (UTC)</label>
                            <input type="time" class="form-control" id="ntml_valid_until" value="${times.end}">
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
                        <div class="col-md-3">
                            <label class="form-label small text-muted">Type</label>
                            <select class="form-control" id="ntml_apreq_type">
                                <option value="APREQ">APREQ</option>
                                <option value="CFR">CFR (Call for Release)</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-muted">Airport</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_ctl_element" placeholder="KJFK" maxlength="4">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-muted">Via Route/Fix</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_via_fix" maxlength="10">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-muted">Reason</label>
                            ${buildReasonSelect()}
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Requesting Facility</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_req_facility" maxlength="4" list="facilityList">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Providing Facility</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_prov_facility" maxlength="4" list="facilityList">
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Valid From (UTC)</label>
                            <input type="time" class="form-control" id="ntml_valid_from" value="${times.start}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Valid Until (UTC)</label>
                            <input type="time" class="form-control" id="ntml_valid_until" value="${times.end}">
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Departure Scope</label>
                            <select class="form-control" id="ntml_dep_scope">
                                <option value="ALL">All Departures</option>
                                <option value="TIER1">Tier 1 Airports</option>
                                <option value="SPECIFIED">Specified Facilities</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Additional Dep Facilities (if specified)</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_add_dep_facilities" placeholder="ZDC ZOB ZID" maxlength="50">
                        </div>
                    </div>
                    
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
                    <span class="tmi-section-title"><i class="fas fa-tachometer-alt mr-1"></i> Time-Based Metering Details</span>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <label class="form-label small text-muted">Airport</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_ctl_element" placeholder="KATL" maxlength="4">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-muted">Meter Point/Arc</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_meter_point" placeholder="ZTL33 or ERLIN" maxlength="10">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-muted">Freeze Horizon (min)</label>
                            <input type="number" class="form-control" id="ntml_freeze_horizon" min="10" max="120" value="20">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-muted">Reason</label>
                            ${buildReasonSelect()}
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Requesting Facility</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_req_facility" maxlength="4" list="facilityList">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Providing Facility</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_prov_facility" maxlength="4" list="facilityList">
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Valid From (UTC)</label>
                            <input type="time" class="form-control" id="ntml_valid_from" value="${times.start}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Valid Until (UTC)</label>
                            <input type="time" class="form-control" id="ntml_valid_until" value="${times.end}">
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-12">
                            <label class="form-label small text-muted">Participating Facilities</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_participating" placeholder="ZTL ZJX ZDC ZID" maxlength="50">
                            <small class="text-muted">Space-separated list of ARTCCs participating in metering</small>
                        </div>
                    </div>
                    
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
                            <input type="text" class="form-control text-uppercase" id="ntml_ctl_element" placeholder="KJFK" maxlength="4">
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
                            <input type="text" class="form-control text-uppercase" id="ntml_ctl_element" placeholder="KJFK" maxlength="4">
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
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Config Name</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_config_name" placeholder="EAST, WEST, etc.">
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
                                <option value="STRAT">Strategic</option>
                                <option value="OPS">Operational</option>
                                <option value="CALLED">Called</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted">ADR</label>
                            <input type="number" class="form-control" id="ntml_adr" min="0" max="120" value="60">
                        </div>
                    </div>
                    
                    <div class="alert alert-info small mb-3">
                        <i class="fas fa-info-circle"></i> 
                        For standard configurations, see <a href="airport_config.php" target="_blank">Airport Configurations</a>
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
                            <input type="text" class="form-control text-uppercase" id="ntml_req_facility" maxlength="4" list="facilityList">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Providing Facility</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_prov_facility" maxlength="4" list="facilityList">
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
    }
    
    // ===========================================
    // Advisory Form Loading
    // ===========================================
    
    function loadAdvisoryForm(type) {
        let formHtml = '';
        
        switch(type) {
            case 'OPSPLAN':
                formHtml = buildOpsPlanForm();
                break;
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
        
        $('#adv_form_container').html(formHtml);
        initAdvisoryFormHandlers(type);
        updateAdvisoryPreview();
        $('#adv_add_to_queue').prop('disabled', false);
    }
    
    function buildOpsPlanForm() {
        const advNum = getNextAdvisoryNumber('OPSPLAN');
        
        return `
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <span class="tmi-section-title"><i class="fas fa-clipboard-check mr-1"></i> Operations Plan</span>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Advisory #</label>
                            <input type="text" class="form-control" id="adv_number" value="${advNum}" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Facility</label>
                            <input type="text" class="form-control text-uppercase" id="adv_facility" value="DCC">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Date (Zulu)</label>
                            <input type="text" class="form-control" id="adv_date" value="${getUtcDateFormatted()}" readonly>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label small text-muted">Key Initiatives</label>
                        <textarea class="form-control" id="adv_initiatives" rows="4" placeholder="List key TMIs and initiatives for the operational period..."></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label small text-muted">Weather Impact</label>
                        <textarea class="form-control" id="adv_weather" rows="2" placeholder="Summarize weather impacts..."></textarea>
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
        
        return `
            <div class="card shadow-sm">
                <div class="card-header bg-secondary text-white">
                    <span class="tmi-section-title"><i class="fas fa-file-alt mr-1"></i> Free-Form Advisory</span>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Advisory #</label>
                            <input type="text" class="form-control" id="adv_number" value="${advNum}" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Facility</label>
                            <input type="text" class="form-control text-uppercase" id="adv_facility" value="DCC">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Subject</label>
                            <input type="text" class="form-control" id="adv_subject" placeholder="Advisory Subject">
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
        const currentTime = getCurrentTimeHHMM();
        
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
                                <option value="EAST COAST HOTLINE">EAST COAST HOTLINE</option>
                                <option value="WEST COAST HOTLINE">WEST COAST HOTLINE</option>
                                <option value="MIDWEST HOTLINE">MIDWEST HOTLINE</option>
                                <option value="NORTHEAST HOTLINE">NORTHEAST HOTLINE</option>
                                <option value="SOUTHEAST HOTLINE">SOUTHEAST HOTLINE</option>
                                <option value="SOUTHWEST HOTLINE">SOUTHWEST HOTLINE</option>
                                <option value="NORTHWEST HOTLINE">NORTHWEST HOTLINE</option>
                                <option value="CUSTOM">CUSTOM</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-muted">Custom Name</label>
                            <input type="text" class="form-control text-uppercase" id="adv_hotline_custom" placeholder="If CUSTOM selected" maxlength="40">
                        </div>
                    </div>
                    
                    <!-- Row 2: Event Times -->
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <label class="form-label small text-muted">Start Time (UTC)</label>
                            <input type="time" class="form-control" id="adv_effective_time" value="${currentTime}">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-muted">End Time (UTC)</label>
                            <input type="time" class="form-control" id="adv_end_time">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-muted">Participation</label>
                            <select class="form-control" id="adv_participation">
                                <option value="MANDATORY">MANDATORY</option>
                                <option value="OPTIONAL">OPTIONAL</option>
                                <option value="ENCOURAGED">ENCOURAGED</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-muted">Prob. of Extension</label>
                            <select class="form-control" id="adv_extension_prob">
                                <option value="NONE">None</option>
                                <option value="LOW">Low</option>
                                <option value="MEDIUM">Medium</option>
                                <option value="HIGH">High</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Row 3: Facilities -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Constrained Facilities</label>
                            <input type="text" class="form-control text-uppercase" id="adv_constrained_facilities" placeholder="ZNY, ZBW, ZDC (facilities with constraints)">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Facilities to Attend</label>
                            <input type="text" class="form-control text-uppercase" id="adv_facilities" placeholder="ZNY, ZBW, ZDC, ZOB, ZID">
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
                    
                    <!-- Row 5: Hotline Location Details -->
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Hotline Location</label>
                            <input type="text" class="form-control" id="adv_hotline_location" placeholder="e.g., vATCSCC Discord">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small text-muted">Hotline Address (URL/Link)</label>
                            <input type="text" class="form-control" id="adv_hotline_address" placeholder="https://discord.gg/... or phone">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-muted">Hotline PIN</label>
                            <input type="text" class="form-control" id="adv_hotline_pin" placeholder="PIN code if any">
                        </div>
                    </div>
                    
                    <!-- Row 6: Restrictions -->
                    <div class="mb-3">
                        <label class="form-label small text-muted">Associated Restrictions</label>
                        <input type="text" class="form-control" id="adv_restrictions" placeholder="e.g., 20MIT, APREQ, Ground Stop">
                    </div>
                    
                    <!-- Row 7: Notes -->
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
    
    function buildSwapForm() {
        const advNum = getNextAdvisoryNumber('SWAP');
        const currentTime = getCurrentTimeHHMM();
        
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
                            <label class="form-label small text-muted">Effective Time (UTC)</label>
                            <input type="time" class="form-control" id="adv_effective_time" value="${currentTime}">
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
        $('#adv_form_container input, #adv_form_container textarea, #adv_form_container select').on('input change', function() {
            updateAdvisoryPreview();
        });
        
        // Auto-uppercase inputs
        $('#adv_form_container .text-uppercase').on('input', function() {
            this.value = this.value.toUpperCase();
        });
        
        // Handle custom hotline name toggle
        if (type === 'HOTLINE') {
            $('#adv_hotline_name').on('change', function() {
                if ($(this).val() === 'CUSTOM') {
                    $('#adv_hotline_custom').prop('disabled', false).focus();
                } else {
                    $('#adv_hotline_custom').prop('disabled', true).val('');
                }
                updateAdvisoryPreview();
            });
            $('#adv_hotline_custom').prop('disabled', true);
            
            // Handle action type change - show/hide fields based on action
            $('#adv_hotline_action').on('change', function() {
                const action = $(this).val();
                
                // Show full fields for ACTIVATION, simplified for UPDATE/TERMINATION
                if (action === 'ACTIVATION') {
                    $('#adv_hotline_location, #adv_hotline_address, #adv_hotline_pin').closest('.col-md-4, .col-md-5, .col-md-3').show();
                    $('#adv_participation').closest('.col-md-3').show();
                } else {
                    // Hide location fields for UPDATE/TERMINATION (optional - keep them visible)
                    // The preview builder handles the format difference
                }
                
                updateAdvisoryPreview();
            });
        }
    }
    
    function updateAdvisoryPreview() {
        const type = state.selectedAdvisoryType;
        if (!type) {
            $('#adv_preview').text('Select an advisory type to begin...');
            return;
        }
        
        let preview = '';
        
        switch(type) {
            case 'OPSPLAN':
                preview = buildOpsPlanPreview();
                break;
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
        const validTimes = getValidTimeRange();
        
        let lines = [
            buildAdvisoryHeader(num, facility, 'OPERATIONS PLAN'),
            `VALID FOR ${validTimes.start}Z THRU ${validTimes.end}Z`,
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
        
        let lines = [
            buildAdvisoryHeader(num, facility, subject.toUpperCase()),
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
        let hotlineName = $('#adv_hotline_name').val() || 'HOTLINE';
        if (hotlineName === 'CUSTOM') {
            hotlineName = $('#adv_hotline_custom').val() || 'CUSTOM HOTLINE';
        }
        const effTime = $('#adv_effective_time').val()?.replace(':', '') || getCurrentTimeHHMM().replace(':', '');
        const endTime = $('#adv_end_time').val()?.replace(':', '') || '';
        const extensionProb = $('#adv_extension_prob').val() || 'NONE';
        const participation = $('#adv_participation').val() || 'MANDATORY';
        const constrainedFacilities = $('#adv_constrained_facilities').val() || '';
        const facilities = $('#adv_facilities').val() || 'TBD';
        const reason = $('#adv_reason').val() || 'WEATHER';
        const impactedArea = $('#adv_impacted_area').val() || '';
        const hotlineLocation = $('#adv_hotline_location').val() || 'vATCSCC Discord';
        const hotlineAddress = $('#adv_hotline_address').val() || '';
        const hotlinePin = $('#adv_hotline_pin').val() || '';
        const restrictions = $('#adv_restrictions').val() || '';
        const notes = $('#adv_notes').val() || '';
        
        const now = new Date();
        const day = String(now.getUTCDate()).padStart(2, '0');
        
        let lines = [
            buildAdvisoryHeader(num, 'DCC', `HOTLINE ${action}`),
            ``
        ];
        
        // Build different format based on action type
        if (action === 'ACTIVATION') {
            // Full boilerplate for Activation
            lines.push(`EVENT TIME: ${day}/${effTime}Z - ${endTime ? day + '/' + endTime + 'Z' : 'TBD'}`);
            
            if (constrainedFacilities) {
                lines.push(`CONSTRAINED FACILITIES: ${constrainedFacilities}`);
            }
            
            lines.push(``);
            
            // Main boilerplate paragraph - wrap at 68 chars
            let boilerplate = `THE ${hotlineName} IS BEING ACTIVATED TO ADDRESS ${reason} IN ${impactedArea || 'THE AFFECTED AREA'}.`;
            
            // Location info
            let locationInfo = `THE LOCATION IS THE ${hotlineLocation}, ${hotlineName}`;
            if (hotlineAddress) {
                locationInfo += `, (${hotlineAddress})`;
            }
            if (hotlinePin) {
                locationInfo += `, PIN: ${hotlinePin}`;
            }
            locationInfo += '.';
            
            boilerplate += ' ' + locationInfo;
            
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
            
            if (endTime) {
                lines.push(`VALID TIMES: ${day}/${effTime}Z - ${day}/${endTime}Z`);
            } else {
                lines.push(`EFFECTIVE: ${day}/${effTime}Z`);
            }
            
            if (extensionProb !== 'NONE') {
                lines.push(`PROBABILITY OF EXTENSION: ${extensionProb}`);
            }
            
        } else if (action === 'TERMINATION') {
            // Termination format - simple
            lines.push(`THE ${hotlineName} IS BEING TERMINATED EFFECTIVE ${day}/${effTime}Z.`);
            
            if (constrainedFacilities) {
                lines.push(``);
                lines.push(`FACILITIES AFFECTED: ${constrainedFacilities}`);
            }
        }
        
        // Common elements for all types
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
    
    function buildSwapPreview() {
        const num = $('#adv_number').val() || '001';
        const swapType = $('#adv_swap_type').val() || 'IMPLEMENTATION';
        const effTime = $('#adv_effective_time').val()?.replace(':', '') || getCurrentTimeHHMM().replace(':', '');
        const duration = parseInt($('#adv_duration').val()) || 4;
        const impactedArea = $('#adv_impacted_area').val() || '';
        const areas = $('#adv_areas').val() || 'TBD';
        const includeTraffic = $('#adv_include_traffic').val() || 'ALL';
        const extensionProb = $('#adv_extension_prob').val() || 'NONE';
        const weather = $('#adv_weather').val() || '';
        const routes = $('#adv_routes').val() || '';
        const restrictions = $('#adv_restrictions').val() || '';
        const notes = $('#adv_notes').val() || '';
        
        const now = new Date();
        const day = String(now.getUTCDate()).padStart(2, '0');
        const startHour = parseInt(effTime.substr(0, 2));
        const startMin = effTime.substr(2, 2);
        const endHour = (startHour + duration) % 24;
        const endDay = (startHour + duration >= 24) ? String(now.getUTCDate() + 1).padStart(2, '0') : day;
        const endTime = String(endHour).padStart(2, '0') + startMin;
        
        let lines = [
            buildAdvisoryHeader(num, 'DCC', `SWAP ${swapType}`),
            ``
        ];
        
        if (impactedArea) {
            lines.push(`IMPACTED AREA: ${impactedArea}`);
        }
        
        lines.push(`REASON: SEVERE WEATHER`);
        lines.push(`INCLUDE TRAFFIC: ${includeTraffic}`);
        lines.push(`VALID TIMES: ${day}/${effTime}Z - ${endDay}/${endTime}Z`);
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
    
    function addNtmlToQueue(type) {
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
                data.apreq_type = $('#ntml_apreq_type').val();
                data.via_fix = ($('#ntml_via_fix').val() || '').trim().toUpperCase();
                data.reason = $('#ntml_reason').val();
                data.dep_scope = $('#ntml_dep_scope').val();
                data.add_dep_facilities = ($('#ntml_add_dep_facilities').val() || '').trim().toUpperCase();
                break;
            case 'TBM':
                data.meter_point = ($('#ntml_meter_point').val() || '').trim().toUpperCase();
                data.freeze_horizon = $('#ntml_freeze_horizon').val();
                data.participating = ($('#ntml_participating').val() || '').trim().toUpperCase();
                data.reason = $('#ntml_reason').val();
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
        // Format: {DD/HHMM}    {element} via {fix} {value}{type} {spacing} EXCL:{excl} {category}:{cause} {valid} {req}:{prov}
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
        
        // Format: 4 spaces between major sections
        let line = `${logTime}    ${element} via ${viaFix} ${value}${type} ${spacing} EXCL:${exclusions} ${category}:${cause} ${validTime} ${reqFac}:${provFac}`;
        
        return line;
    }
    
    function formatStopMessage(data, logTime, validTime) {
        // Format: {DD/HHMM}    {element} via {fix} STOP {qualifier} EXCL:{excl} {category}:{cause} {valid} {req}:{prov}
        const element = (data.ctl_element || 'N/A').toUpperCase();
        const viaFix = data.via_fix ? (data.via_fix).toUpperCase() : 'ALL';
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
        
        let line = `${logTime}    ${element} via ${viaFix} STOP${qualStr} EXCL:${exclusions} ${category}:${cause} ${validTime} ${reqFac}:${provFac}`;
        
        return line;
    }
    
    function formatApreqMessage(data, logTime, validTime) {
        const apreqType = data.apreq_type || 'APREQ';
        let line = `${logTime}    ${apreqType} ${data.ctl_element || 'N/A'}`;
        
        if (data.via_fix) {
            line += ` via ${data.via_fix}`;
        }
        
        line += ` ${data.reason || 'VOLUME'}`;
        
        if (data.dep_scope && data.dep_scope !== 'ALL') {
            line += ` DEP SCOPE: ${data.dep_scope}`;
        }
        
        line += ` ${validTime}`;
        line += ` ${data.req_facility || 'N/A'}:${data.prov_facility || 'N/A'}`;
        
        return line;
    }
    
    function formatTbmMessage(data, logTime, validTime) {
        let line = `${logTime}    ${data.ctl_element || 'N/A'} TBM`;
        
        if (data.meter_point) {
            line += ` ${data.meter_point}`;
        }
        
        if (data.freeze_horizon) {
            line += ` FRZ:${data.freeze_horizon}`;
        }
        
        line += ` ${data.reason || 'VOLUME'}`;
        line += ` ${validTime}`;
        line += ` ${data.req_facility || 'N/A'}:${data.prov_facility || 'N/A'}`;
        
        if (data.participating) {
            line += ` PARTICIPATING: ${data.participating}`;
        }
        
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
                            <button class="btn btn-sm btn-outline-primary mb-1" onclick="TMIPublisher.previewEntry(${index})">
                                <i class="fas fa-eye"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-danger" onclick="TMIPublisher.removeFromQueue(${index})">
                                <i class="fas fa-times"></i>
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
        const startHour = minutes > snapMinutes ? (now.getUTCHours() + 1) % 24 : now.getUTCHours();
        const startMinutes = snapMinutes;
        
        // End time is 4 hours later
        const endHour = (startHour + 4) % 24;
        
        return {
            start: `${String(startHour).padStart(2, '0')}:${String(startMinutes).padStart(2, '0')}`,
            end: `${String(endHour).padStart(2, '0')}:${String(startMinutes).padStart(2, '0')}`
        };
    }
    
    function formatValidTime(from, until) {
        const fromStr = (from || '').replace(':', '') || '0000';
        const untilStr = (until || '').replace(':', '') || '0000';
        return `${fromStr}-${untilStr}`;
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
        updateCauseOptions: updateCauseOptions
    };
    
})();
