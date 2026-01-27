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
 * @version 1.1.0
 * @date 2026-01-27
 */

(function() {
    'use strict';
    
    // ===========================================
    // Configuration
    // ===========================================
    
    const CONFIG = window.TMI_PUBLISHER_CONFIG || {
        userCid: null,
        userName: 'Unknown',
        userPrivileged: false,
        userHomeOrg: 'vatcscc',
        discordOrgs: { vatcscc: { name: 'vATCSCC', region: 'US', default: true } },
        stagingRequired: true,
        crossBorderAutoDetect: true,
        defaultStartTime: '0000',
        defaultEndTime: '0400'
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
    
    // ===========================================
    // State
    // ===========================================
    
    let state = {
        queue: [],
        productionMode: false,
        selectedNtmlType: null,
        selectedAdvisoryType: null,
        lastCrossBorderOrgs: []
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
    
    function buildMitMinitForm(type) {
        const unit = type === 'MIT' ? 'Miles' : 'Minutes';
        const unitShort = type === 'MIT' ? 'nm' : 'min';
        
        return `
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <span class="tmi-section-title"><i class="fas fa-ruler-horizontal mr-1"></i> ${type} Entry</span>
                </div>
                <div class="card-body">
                    <!-- Restriction Value -->
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label small text-muted">${unit}-in-Trail Value</label>
                            <div class="input-group">
                                <input type="number" class="form-control" id="ntml_value" min="5" max="100" step="5" value="20">
                                <div class="input-group-append">
                                    <span class="input-group-text">${unitShort}</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Control Element (Airport/Fix)</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_ctl_element" placeholder="KJFK or LENDY" maxlength="10">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Via Fix (optional)</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_via_fix" placeholder="MERIT" maxlength="10">
                        </div>
                    </div>
                    
                    <!-- Facilities -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Requesting Facility</label>
                            <input type="text" class="form-control text-uppercase facility-autocomplete" id="ntml_req_facility" placeholder="ZNY" maxlength="4" list="facilityList">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Providing Facility</label>
                            <input type="text" class="form-control text-uppercase facility-autocomplete" id="ntml_prov_facility" placeholder="ZBW" maxlength="4" list="facilityList">
                        </div>
                    </div>
                    
                    <!-- Valid Times -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Valid From (UTC)</label>
                            <input type="text" class="form-control time-input" id="ntml_valid_from" placeholder="1400" maxlength="4" value="${CONFIG.defaultStartTime}">
                            <small class="text-muted">Format: HHMM</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Valid Until (UTC)</label>
                            <input type="text" class="form-control time-input" id="ntml_valid_until" placeholder="1800" maxlength="4" value="${CONFIG.defaultEndTime}">
                            <small class="text-muted">Format: HHMM</small>
                        </div>
                    </div>
                    
                    <!-- Reason & Options -->
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Reason</label>
                            <select class="form-control" id="ntml_reason">
                                <option value="VOLUME">Volume</option>
                                <option value="WEATHER">Weather</option>
                                <option value="RUNWAY">Runway</option>
                                <option value="STAFFING">Staffing</option>
                                <option value="EQUIPMENT">Equipment</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Exclusions</label>
                            <select class="form-control" id="ntml_exclusions">
                                <option value="NONE">None</option>
                                <option value="LIFEGUARD">Lifeguard</option>
                                <option value="MEDEVAC">Medevac</option>
                                <option value="AIRDROP">Airdrop</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Flow Direction</label>
                            <select class="form-control" id="ntml_flow">
                                <option value="arrivals">Arrivals</option>
                                <option value="departures">Departures</option>
                            </select>
                        </div>
                    </div>
                    
                    <hr>
                    <div class="d-flex justify-content-between">
                        <button class="btn btn-secondary" type="button" onclick="TMIPublisher.resetNtmlForm()">
                            <i class="fas fa-undo mr-1"></i> Reset
                        </button>
                        <button class="btn btn-primary" type="button" onclick="TMIPublisher.addNtmlToQueue('${type}')">
                            <i class="fas fa-plus mr-1"></i> Add to Queue
                        </button>
                    </div>
                </div>
            </div>
            ${buildFacilityDatalist()}
        `;
    }
    
    function buildStopForm() {
        return `
            <div class="card shadow-sm">
                <div class="card-header bg-danger text-white">
                    <span class="tmi-section-title"><i class="fas fa-hand-paper mr-1"></i> Flow Stoppage Entry</span>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Control Element (Airport/Fix)</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_ctl_element" placeholder="KJFK" maxlength="10">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Via Fix (optional)</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_via_fix" placeholder="LENDY" maxlength="10">
                        </div>
                    </div>
                    
                    <!-- Facilities -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Requesting Facility</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_req_facility" placeholder="ZNY" maxlength="4" list="facilityList">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Providing Facility</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_prov_facility" placeholder="ZBW" maxlength="4" list="facilityList">
                        </div>
                    </div>
                    
                    <!-- Valid Times -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Valid From (UTC)</label>
                            <input type="text" class="form-control time-input" id="ntml_valid_from" placeholder="1400" maxlength="4" value="${CONFIG.defaultStartTime}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Valid Until (UTC)</label>
                            <input type="text" class="form-control time-input" id="ntml_valid_until" placeholder="1800" maxlength="4" value="${CONFIG.defaultEndTime}">
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Reason</label>
                            <select class="form-control" id="ntml_reason">
                                <option value="WEATHER">Weather</option>
                                <option value="VOLUME">Volume</option>
                                <option value="RUNWAY">Runway</option>
                                <option value="STAFFING">Staffing</option>
                            </select>
                        </div>
                    </div>
                    
                    <hr>
                    <div class="d-flex justify-content-between">
                        <button class="btn btn-secondary" type="button" onclick="TMIPublisher.resetNtmlForm()">
                            <i class="fas fa-undo mr-1"></i> Reset
                        </button>
                        <button class="btn btn-danger" type="button" onclick="TMIPublisher.addNtmlToQueue('STOP')">
                            <i class="fas fa-plus mr-1"></i> Add to Queue
                        </button>
                    </div>
                </div>
            </div>
            ${buildFacilityDatalist()}
        `;
    }
    
    function buildApreqForm() {
        return `
            <div class="card shadow-sm">
                <div class="card-header bg-warning text-dark">
                    <span class="tmi-section-title"><i class="fas fa-phone mr-1"></i> APREQ/CFR Entry</span>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Type</label>
                            <select class="form-control" id="ntml_apreq_type">
                                <option value="APREQ">APREQ</option>
                                <option value="CFR">CFR (Call for Release)</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Airport</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_ctl_element" placeholder="KJFK" maxlength="4">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Via Fix (optional)</label>
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
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Valid From (UTC)</label>
                            <input type="text" class="form-control time-input" id="ntml_valid_from" value="${CONFIG.defaultStartTime}" maxlength="4">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Valid Until (UTC)</label>
                            <input type="text" class="form-control time-input" id="ntml_valid_until" value="${CONFIG.defaultEndTime}" maxlength="4">
                        </div>
                    </div>
                    
                    <hr>
                    <div class="d-flex justify-content-between">
                        <button class="btn btn-secondary" type="button" onclick="TMIPublisher.resetNtmlForm()">Reset</button>
                        <button class="btn btn-warning" type="button" onclick="TMIPublisher.addNtmlToQueue('APREQ')">
                            <i class="fas fa-plus mr-1"></i> Add to Queue
                        </button>
                    </div>
                </div>
            </div>
            ${buildFacilityDatalist()}
        `;
    }
    
    function buildTbmForm() {
        return `
            <div class="card shadow-sm">
                <div class="card-header bg-success text-white">
                    <span class="tmi-section-title"><i class="fas fa-tachometer-alt mr-1"></i> TBM Entry</span>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Airport</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_ctl_element" placeholder="KATL" maxlength="4">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Sector</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_sector" placeholder="ZTL33" maxlength="10">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Reason</label>
                            <select class="form-control" id="ntml_reason">
                                <option value="VOLUME">Volume</option>
                                <option value="WEATHER">Weather</option>
                            </select>
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
                            <input type="text" class="form-control time-input" id="ntml_valid_from" value="${CONFIG.defaultStartTime}" maxlength="4">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Valid Until (UTC)</label>
                            <input type="text" class="form-control time-input" id="ntml_valid_until" value="${CONFIG.defaultEndTime}" maxlength="4">
                        </div>
                    </div>
                    
                    <hr>
                    <div class="d-flex justify-content-between">
                        <button class="btn btn-secondary" type="button" onclick="TMIPublisher.resetNtmlForm()">Reset</button>
                        <button class="btn btn-success" type="button" onclick="TMIPublisher.addNtmlToQueue('TBM')">
                            <i class="fas fa-plus mr-1"></i> Add to Queue
                        </button>
                    </div>
                </div>
            </div>
            ${buildFacilityDatalist()}
        `;
    }
    
    function buildDelayForm() {
        return `
            <div class="card shadow-sm">
                <div class="card-header" style="background-color: #fd7e14; color: white;">
                    <span class="tmi-section-title"><i class="fas fa-hourglass-half mr-1"></i> Delay Entry</span>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <label class="form-label small text-muted">Delay Type</label>
                            <select class="form-control" id="ntml_delay_type">
                                <option value="D/D">D/D (Departure Delay)</option>
                                <option value="E/D">E/D (En Route Delay)</option>
                                <option value="A/D">A/D (Arrival Delay)</option>
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
                            <select class="form-control" id="ntml_reason">
                                <option value="VOLUME">Volume</option>
                                <option value="WEATHER">Weather</option>
                                <option value="RUNWAY">Runway</option>
                                <option value="STAFFING">Staffing</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Report Time (UTC)</label>
                            <input type="text" class="form-control time-input" id="ntml_report_time" value="${getCurrentTimeHHMM()}" maxlength="4">
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
                            <i class="fas fa-plus mr-1"></i> Add to Queue
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
                    <span class="tmi-section-title"><i class="fas fa-plane-departure mr-1"></i> Airport Configuration Entry</span>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Airport</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_ctl_element" placeholder="KJFK" maxlength="4">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Weather</label>
                            <select class="form-control" id="ntml_weather">
                                <option value="VMC">VMC</option>
                                <option value="IMC">IMC</option>
                                <option value="MVFR">MVFR</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Config Name (optional)</label>
                            <input type="text" class="form-control" id="ntml_config_name" placeholder="e.g., EAST">
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Arrival Runways</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_arr_runways" placeholder="22L/22R">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Departure Runways</label>
                            <input type="text" class="form-control text-uppercase" id="ntml_dep_runways" placeholder="31L/31R">
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
                                <option value="Strat">Strategic</option>
                                <option value="Ops">Operational</option>
                                <option value="Called">Called</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted">ADR</label>
                            <input type="number" class="form-control" id="ntml_adr" min="0" max="120" value="60">
                        </div>
                    </div>
                    
                    <div class="alert alert-info small mb-3">
                        <i class="fas fa-info-circle"></i> 
                        For standard configurations, see <a href="configs.php" target="_blank">Airport Configurations</a>
                    </div>
                    
                    <hr>
                    <div class="d-flex justify-content-between">
                        <button class="btn btn-secondary" type="button" onclick="TMIPublisher.resetNtmlForm()">Reset</button>
                        <button class="btn btn-primary" type="button" style="background-color: #6f42c1; border-color: #6f42c1;" onclick="TMIPublisher.addNtmlToQueue('CONFIG')">
                            <i class="fas fa-plus mr-1"></i> Add to Queue
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
                    <span class="tmi-section-title"><i class="fas fa-times-circle mr-1"></i> Cancel TMI</span>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Cancel Type</label>
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
                            <i class="fas fa-times mr-1"></i> Add Cancel to Queue
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
        return `
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <span class="tmi-section-title"><i class="fas fa-clipboard-check mr-1"></i> Operations Plan</span>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Advisory #</label>
                            <input type="text" class="form-control" id="adv_number" value="001">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Facility</label>
                            <input type="text" class="form-control text-uppercase" id="adv_facility" value="DCC">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Date (Zulu)</label>
                            <input type="text" class="form-control" id="adv_date" value="${getCurrentDateMMDD()}">
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
        return `
            <div class="card shadow-sm">
                <div class="card-header bg-secondary text-white">
                    <span class="tmi-section-title"><i class="fas fa-file-alt mr-1"></i> Free-Form Advisory</span>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Advisory #</label>
                            <input type="text" class="form-control" id="adv_number" value="001">
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
        return `
            <div class="card shadow-sm">
                <div class="card-header bg-danger text-white">
                    <span class="tmi-section-title"><i class="fas fa-phone-volume mr-1"></i> Hotline Advisory</span>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Action</label>
                            <select class="form-control" id="adv_hotline_action">
                                <option value="ACTIVATION">Activation</option>
                                <option value="TERMINATION">Termination</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Hotline Name</label>
                            <input type="text" class="form-control text-uppercase" id="adv_hotline_name" placeholder="e.g., EAST COAST">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Effective Time (UTC)</label>
                            <input type="text" class="form-control time-input" id="adv_effective_time" value="${getCurrentTimeHHMM()}" maxlength="4">
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Facilities Involved</label>
                            <input type="text" class="form-control text-uppercase" id="adv_facilities" placeholder="ZNY, ZBW, ZDC">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Reason</label>
                            <input type="text" class="form-control" id="adv_reason" placeholder="Weather, Volume, etc.">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label small text-muted">Additional Notes</label>
                        <textarea class="form-control" id="adv_notes" rows="2"></textarea>
                    </div>
                </div>
            </div>
        `;
    }
    
    function buildSwapForm() {
        return `
            <div class="card shadow-sm">
                <div class="card-header bg-warning text-dark">
                    <span class="tmi-section-title"><i class="fas fa-cloud-sun-rain mr-1"></i> SWAP Advisory</span>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Advisory #</label>
                            <input type="text" class="form-control" id="adv_number" value="001">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted">SWAP Type</label>
                            <select class="form-control" id="adv_swap_type">
                                <option value="IMPLEMENTATION">Implementation</option>
                                <option value="UPDATE">Update</option>
                                <option value="TERMINATION">Termination</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Effective Time (UTC)</label>
                            <input type="text" class="form-control time-input" id="adv_effective_time" value="${getCurrentTimeHHMM()}" maxlength="4">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label small text-muted">Affected Areas</label>
                        <input type="text" class="form-control text-uppercase" id="adv_areas" placeholder="e.g., ZNY, ZBW, ZDC">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label small text-muted">Weather Summary</label>
                        <textarea class="form-control" id="adv_weather" rows="2" placeholder="Describe weather conditions..."></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label small text-muted">Playbook Routes / Initiatives</label>
                        <textarea class="form-control" id="adv_routes" rows="3" placeholder="List active playbook routes and initiatives..."></textarea>
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
    }
    
    function updateAdvisoryPreview() {
        const type = state.selectedAdvisoryType;
        if (!type) {
            $('#adv_preview').text('Select an advisory type to begin...');
            return;
        }
        
        let preview = '';
        const now = new Date();
        const dateStr = now.toISOString().substr(0, 10).replace(/-/g, '/');
        const timeStr = now.toISOString().substr(11, 5).replace(':', '');
        
        switch(type) {
            case 'OPSPLAN':
                preview = buildOpsPlanPreview(dateStr, timeStr);
                break;
            case 'FREEFORM':
                preview = buildFreeformPreview(dateStr, timeStr);
                break;
            case 'HOTLINE':
                preview = buildHotlinePreview(dateStr, timeStr);
                break;
            case 'SWAP':
                preview = buildSwapPreview(dateStr, timeStr);
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
    
    function buildOpsPlanPreview(dateStr, timeStr) {
        const num = $('#adv_number').val() || '001';
        const facility = $('#adv_facility').val() || 'DCC';
        const date = $('#adv_date').val() || dateStr;
        const initiatives = $('#adv_initiatives').val() || '';
        const weather = $('#adv_weather').val() || '';
        const events = $('#adv_events').val() || '';
        
        let lines = [
            `=== OPERATIONS PLAN ===`,
            `ADVISORY ${num}  ${facility}  ${date}Z`,
            ``,
            `KEY INITIATIVES:`,
            initiatives || '(None specified)',
            ``
        ];
        
        if (weather) {
            lines.push(`WEATHER IMPACT:`);
            lines.push(weather);
            lines.push(``);
        }
        
        if (events) {
            lines.push(`SPECIAL EVENTS:`);
            lines.push(events);
        }
        
        return lines.join('\n');
    }
    
    function buildFreeformPreview(dateStr, timeStr) {
        const num = $('#adv_number').val() || '001';
        const facility = $('#adv_facility').val() || 'DCC';
        const subject = $('#adv_subject').val() || 'GENERAL ADVISORY';
        const text = $('#adv_text').val() || '';
        
        return [
            `=== ${subject.toUpperCase()} ===`,
            `ADVISORY ${num}  ${facility}  ${dateStr}/${timeStr}Z`,
            ``,
            text || '(No text entered)'
        ].join('\n');
    }
    
    function buildHotlinePreview(dateStr, timeStr) {
        const action = $('#adv_hotline_action').val() || 'ACTIVATION';
        const name = $('#adv_hotline_name').val() || 'HOTLINE';
        const effTime = $('#adv_effective_time').val() || timeStr;
        const facilities = $('#adv_facilities').val() || '';
        const reason = $('#adv_reason').val() || '';
        const notes = $('#adv_notes').val() || '';
        
        let lines = [
            `=== HOTLINE ${action} ===`,
            ``,
            `HOTLINE: ${name}`,
            `EFFECTIVE: ${effTime}Z`,
            `FACILITIES: ${facilities || 'TBD'}`,
            `REASON: ${reason || 'N/A'}`
        ];
        
        if (notes) {
            lines.push(``);
            lines.push(`NOTES: ${notes}`);
        }
        
        return lines.join('\n');
    }
    
    function buildSwapPreview(dateStr, timeStr) {
        const num = $('#adv_number').val() || '001';
        const swapType = $('#adv_swap_type').val() || 'IMPLEMENTATION';
        const effTime = $('#adv_effective_time').val() || timeStr;
        const areas = $('#adv_areas').val() || '';
        const weather = $('#adv_weather').val() || '';
        const routes = $('#adv_routes').val() || '';
        
        let lines = [
            `=== SWAP ${swapType} ===`,
            `ADVISORY ${num}  EFFECTIVE ${effTime}Z`,
            ``,
            `AFFECTED AREAS: ${areas || 'TBD'}`,
            ``
        ];
        
        if (weather) {
            lines.push(`WEATHER: ${weather}`);
            lines.push(``);
        }
        
        if (routes) {
            lines.push(`PLAYBOOK ROUTES / INITIATIVES:`);
            lines.push(routes);
        }
        
        return lines.join('\n');
    }
    
    // ===========================================
    // Queue Management
    // ===========================================
    
    function addNtmlToQueue(type) {
        // Validate required fields
        const ctlElement = $('#ntml_ctl_element').val()?.trim().toUpperCase();
        const reqFacility = $('#ntml_req_facility').val()?.trim().toUpperCase();
        const provFacility = $('#ntml_prov_facility').val()?.trim().toUpperCase();
        const validFrom = $('#ntml_valid_from').val()?.trim();
        const validUntil = $('#ntml_valid_until').val()?.trim();
        
        // Basic validation
        if (!ctlElement && type !== 'DELAY') {
            Swal.fire('Missing Field', 'Please enter a control element (airport or fix)', 'warning');
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
        
        // Type-specific data
        switch(type) {
            case 'MIT':
            case 'MINIT':
                data.value = $('#ntml_value').val();
                data.via_fix = $('#ntml_via_fix').val()?.trim().toUpperCase();
                data.reason = $('#ntml_reason').val();
                data.exclusions = $('#ntml_exclusions').val();
                data.flow = $('#ntml_flow').val();
                break;
            case 'STOP':
                data.via_fix = $('#ntml_via_fix').val()?.trim().toUpperCase();
                data.reason = $('#ntml_reason').val();
                break;
            case 'APREQ':
                data.apreq_type = $('#ntml_apreq_type').val();
                data.via_fix = $('#ntml_via_fix').val()?.trim().toUpperCase();
                break;
            case 'TBM':
                data.sector = $('#ntml_sector').val()?.trim().toUpperCase();
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
                data.arr_runways = $('#ntml_arr_runways').val()?.trim().toUpperCase();
                data.dep_runways = $('#ntml_dep_runways').val()?.trim().toUpperCase();
                data.aar = $('#ntml_aar').val();
                data.aar_type = $('#ntml_aar_type').val();
                data.adr = $('#ntml_adr').val();
                break;
            case 'CANCEL':
                data.cancel_type = $('#ntml_cancel_type').val();
                data.via_fix = $('#ntml_via_fix').val()?.trim().toUpperCase();
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
        
        switch(type) {
            case 'MIT':
            case 'MINIT':
                return formatMitMinitMessage(type, data, logTime);
            case 'STOP':
                return formatStopMessage(data, logTime);
            case 'APREQ':
                return formatApreqMessage(data, logTime);
            case 'TBM':
                return formatTbmMessage(data, logTime);
            case 'DELAY':
                return formatDelayMessage(data, logTime);
            case 'CONFIG':
                return formatConfigMessage(data, logTime);
            case 'CANCEL':
                return formatCancelMessage(data, logTime);
            default:
                return `${logTime} ${type} ${data.ctl_element}`;
        }
    }
    
    function formatMitMinitMessage(type, data, logTime) {
        let line = `${logTime}    ${data.ctl_element}`;
        
        if (data.flow === 'departures') {
            line += ' departures';
        }
        
        if (data.via_fix) {
            line += ` via ${data.via_fix}`;
        }
        
        line += ` ${data.value}${type}`;
        line += ` ${data.reason}:${data.reason}`;
        line += ` EXCL:${data.exclusions}`;
        line += ` ${data.valid_from}-${data.valid_until}`;
        line += ` ${data.req_facility}:${data.prov_facility}`;
        
        return line;
    }
    
    function formatStopMessage(data, logTime) {
        let line = `${logTime}    ${data.ctl_element}`;
        
        if (data.via_fix) {
            line += ` via ${data.via_fix}`;
        }
        
        line += ` STOP`;
        line += ` ${data.reason}:${data.reason}`;
        line += ` ${data.valid_from}-${data.valid_until}`;
        line += ` ${data.req_facility}:${data.prov_facility}`;
        
        return line;
    }
    
    function formatApreqMessage(data, logTime) {
        let line = `${logTime}    ${data.apreq_type || 'APREQ'} ${data.ctl_element} departures`;
        
        if (data.via_fix) {
            line += ` via ${data.via_fix}`;
        }
        
        line += ` ${data.valid_from}-${data.valid_until}`;
        line += ` ${data.req_facility}:${data.prov_facility}`;
        
        return line;
    }
    
    function formatTbmMessage(data, logTime) {
        let line = `${logTime}    ${data.ctl_element} TBM`;
        
        if (data.sector) {
            line += ` ${data.sector}`;
        }
        
        line += ` ${data.reason}:${data.reason}`;
        line += ` ${data.valid_from}-${data.valid_until}`;
        line += ` ${data.req_facility}:${data.prov_facility}`;
        
        return line;
    }
    
    function formatDelayMessage(data, logTime) {
        const delayType = data.delay_type || 'D/D';
        let prep = 'from';
        if (delayType === 'E/D') prep = 'for';
        if (delayType === 'A/D') prep = 'to';
        
        let sign = '';
        if (data.delay_trend === 'INC') sign = '+';
        if (data.delay_trend === 'DEC') sign = '-';
        
        let line = `${logTime}    ${delayType} ${prep} ${data.ctl_element}, ${sign}${data.delay_minutes}/${data.report_time}`;
        
        if (data.acft_count && data.acft_count > 1) {
            line += `/${data.acft_count} ACFT`;
        }
        
        line += ` ${data.reason}:${data.reason}`;
        
        return line;
    }
    
    function formatConfigMessage(data, logTime) {
        let line = `${logTime}    ${data.ctl_element}    ${data.weather}`;
        line += `    ARR:${data.arr_runways} DEP:${data.dep_runways}`;
        line += `    AAR(${data.aar_type}):${data.aar}`;
        line += `    ADR:${data.adr}`;
        
        return line;
    }
    
    function formatCancelMessage(data, logTime) {
        let line = `${logTime}    `;
        
        if (data.cancel_type === 'ALL') {
            line += `ALL TMI CANCELLED ${data.ctl_element}`;
        } else {
            line += `CANCEL ${data.ctl_element}`;
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
            number: $('#adv_number').val(),
            facility: $('#adv_facility').val()
        };
        
        // Collect all form fields
        $('#adv_form_container input, #adv_form_container textarea, #adv_form_container select').each(function() {
            const id = $(this).attr('id');
            if (id) {
                data[id.replace('adv_', '')] = $(this).val();
            }
        });
        
        return data;
    }
    
    function updateQueueDisplay() {
        const container = $('#entryQueueList');
        const emptyMsg = $('#emptyQueueMsg');
        
        if (state.queue.length === 0) {
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
            const typeClass = entry.type === 'advisory' ? 'border-purple' : 'border-success';
            const typeBadge = entry.type === 'advisory' ? 'badge-purple' : 'badge-success';
            
            html += `
                <div class="queue-item p-3 border-left-4 ${typeClass} mb-2 bg-light rounded">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="flex-grow-1">
                            <span class="badge ${typeBadge} mr-1">${entry.type.toUpperCase()}</span>
                            <span class="badge badge-secondary">${entry.entryType}</span>
                            <div class="mt-2 font-monospace small text-dark" style="white-space: pre-wrap; max-height: 100px; overflow-y: auto;">${escapeHtml(entry.preview.substring(0, 200))}${entry.preview.length > 200 ? '...' : ''}</div>
                            <div class="mt-1 small text-muted">
                                <i class="fab fa-discord"></i> ${entry.orgs.join(', ')}
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
        if (state.queue.length === 0) return;
        
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
        
        $('#previewModalContent').text(entry.preview);
        $('#previewModal').modal('show');
    }
    
    // ===========================================
    // Submit
    // ===========================================
    
    function submitAll() {
        if (state.queue.length === 0) return;
        
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
        let html = `<p><strong>Summary:</strong> ${response.summary.success}/${response.summary.total} succeeded</p>`;
        
        if (response.results && response.results.length > 0) {
            html += '<ul class="list-unstyled small">';
            response.results.forEach(r => {
                const icon = r.success ? '✅' : '❌';
                html += `<li>${icon} ${r.entryType || r.type}</li>`;
            });
            html += '</ul>';
        }
        
        Swal.fire({
            icon: response.summary.failed === 0 ? 'success' : 'warning',
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
        
        // TODO: Implement API call to fetch active TMIs
        // For now, show placeholder
        setTimeout(() => {
            $('#activeTmiBody').html(`
                <tr>
                    <td colspan="6" class="text-center text-muted py-4">
                        <i class="fas fa-info-circle"></i> No active TMIs found
                    </td>
                </tr>
            `);
        }, 500);
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
                console.log('No staged entries API available');
            }
        });
    }
    
    function displayStagedEntries(entries) {
        const container = $('#recentPostsList');
        
        if (!entries || entries.length === 0) {
            container.html(`
                <div class="list-group-item text-center text-muted py-3">
                    <i class="fas fa-clock"></i> No staged posts
                </div>
            `);
            return;
        }
        
        let html = '';
        entries.forEach(entry => {
            html += `
                <div class="list-group-item p-2">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <span class="badge badge-warning badge-sm mr-1">STAGED</span>
                            <span class="small">${escapeHtml(entry.summary || entry.entryType)}</span>
                        </div>
                        <button class="btn btn-sm btn-success" onclick="TMIPublisher.promoteEntry('${entry.entityType}', ${entry.entityId}, ${JSON.stringify(entry.stagedOrgs || [])})">
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
            html: `<p>Publish this ${entityType.toLowerCase()} to production channels?</p>
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
                        text: response.results?.[0]?.error || 'Unknown error'
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
        const count = state.queue.length;
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
        const hasEntries = state.queue.length > 0;
        $('#submitAllBtn').prop('disabled', !hasEntries);
        
        // Update target orgs display
        const orgs = new Set();
        state.queue.forEach(e => e.orgs.forEach(o => orgs.add(o)));
        const orgNames = Array.from(orgs).map(code => {
            return CONFIG.discordOrgs[code]?.name || code;
        });
        $('#targetOrgsDisplay').text(orgNames.length > 0 ? orgNames.join(', ') : 'None selected');
    }
    
    function resetNtmlForm() {
        $('#ntml_form_container input').val('');
        $('#ntml_form_container select').each(function() {
            this.selectedIndex = 0;
        });
        $('#ntml_value').val('20');
        $('#ntml_valid_from').val(CONFIG.defaultStartTime);
        $('#ntml_valid_until').val(CONFIG.defaultEndTime);
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
        const reqFac = $('#ntml_req_facility').val()?.trim().toUpperCase();
        const provFac = $('#ntml_prov_facility').val()?.trim().toUpperCase();
        
        let crossBorder = false;
        [reqFac, provFac].forEach(fac => {
            if (CROSS_BORDER_FACILITIES.includes(fac)) {
                crossBorder = true;
            }
        });
        
        // Auto-check partner org if cross-border detected
        if (crossBorder && CONFIG.crossBorderAutoDetect) {
            // Check if Canadian facility involved
            if (reqFac?.startsWith('CZ') || provFac?.startsWith('CZ')) {
                $('#org_vatcan').prop('checked', true);
            }
            // Check if US facility involved
            if (reqFac?.startsWith('Z') || provFac?.startsWith('Z')) {
                $('#org_vatcscc').prop('checked', true);
            }
        }
    }
    
    function getCurrentTimeHHMM() {
        const now = new Date();
        return String(now.getUTCHours()).padStart(2, '0') + String(now.getUTCMinutes()).padStart(2, '0');
    }
    
    function getCurrentDateMMDD() {
        const now = new Date();
        return String(now.getUTCMonth() + 1).padStart(2, '0') + '/' + String(now.getUTCDate()).padStart(2, '0');
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
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    function saveState() {
        try {
            localStorage.setItem('tmi_publisher_queue', JSON.stringify(state.queue));
            localStorage.setItem('tmi_publisher_mode', state.productionMode ? '1' : '0');
        } catch (e) {
            console.warn('Failed to save state:', e);
        }
    }
    
    function loadSavedState() {
        try {
            const savedQueue = localStorage.getItem('tmi_publisher_queue');
            if (savedQueue) {
                state.queue = JSON.parse(savedQueue);
            }
            
            const savedMode = localStorage.getItem('tmi_publisher_mode');
            if (savedMode === '1') {
                state.productionMode = true;
                $('#productionMode').prop('checked', true);
            }
        } catch (e) {
            console.warn('Failed to load state:', e);
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
        loadStagedEntries: loadStagedEntries
    };
    
})();
