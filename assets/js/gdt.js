// assets/js/gdt.js
// Ground Delay Tool - Ground Stop builder and VATSIM previewer with Tier-based scope and airport coloring
// Tier/group data is loaded at runtime from the database via api/tiers.php
//
// API Migration (2026-01-26): GDT now uses unified /api/gdt/ endpoints targeting VATSIM_TMI database.
// Data migrated from VATSIM_ADL.dbo.ntml to VATSIM_TMI.dbo.tmi_programs via Migration 014.

(function() {
    'use strict';

    // Runtime Tier structures
    let TMI_TIER_INFO = [];
    let TMI_UNIQUE_FACILITIES = [];
    let TMI_TIER_INFO_BY_CODE = {};

    let AIRPORT_CENTER_MAP = {};
    let CENTER_AIRPORTS_MAP = {};
    let AIRPORT_TRACON_MAP = {};
    let TRACON_AIRPORTS_MAP = {};
    const AIRPORT_IATA_MAP = {};

    let GS_ADL = null;
    let GS_ADL_LOADING = false;
    let GS_ADL_PROMISE = null;


    let GS_VATSIM = null;
    let GS_VATSIM_LOADING = false;
    let GS_VATSIM_PROMISE = null;
    let GS_FLIGHT_ROW_INDEX = {};

    // GS Flight List display mode: 'eligible' (default) or 'all'
    let GS_SHOW_ALL_FLIGHTS = false;

    const GS_ADL_API_URL = 'api/adl/current.php';

    // GDT API endpoints (unified GDT structure in VATSIM_TMI)
    const GS_API = {
        list: 'api/gdt/programs/list.php',
        active: 'api/gdt/programs/active.php',
        create: 'api/gdt/programs/create.php',
        model: 'api/gdt/programs/model.php',
        simulate: 'api/gdt/programs/simulate.php',
        activate: 'api/gdt/programs/activate.php',
        extend: 'api/gdt/programs/extend.php',
        revise: 'api/gdt/programs/revise.php',
        cancel: 'api/gdt/programs/cancel.php',
        transition: 'api/gdt/programs/transition.php',
        purge: 'api/gdt/programs/purge.php',
        get: 'api/gdt/programs/get.php',
        flights: 'api/gdt/flights/list.php',
        demand: 'api/gdt/demand/hourly.php',
    };

    // Legacy GS workflow API endpoints (deprecated - use GS_API instead)
    // These endpoints remain for backward compatibility but should not be used for new code
    const GS_WORKFLOW_API = {
        preview: 'api/tmi/gs_preview.php',
        simulate: 'api/tmi/gs_simulate.php',
        apply: 'api/tmi/gs_apply.php',
        purgeLocal: 'api/tmi/gs_purge_local.php',
        purgeAll: 'api/tmi/gs_purge_all.php',
    };

    // Current GS program state
    let GS_CURRENT_PROGRAM_ID = null;
    let GS_CURRENT_PROGRAM_STATUS = null;

    // Which dataset the flight table is currently showing
    // LIVE = dbo.adl_flights, GS = dbo.adl_flights_gs (local sandbox)
    let GS_TABLE_MODE = 'LIVE';

    // Track whether a simulation has been run (required before Submit to TMI)
    let GS_SIMULATION_READY = false;

    // ========================================================================
    // Workflow State Machine
    // ========================================================================

    // Valid states: 'configure', 'preview', 'model', 'active'
    let GS_WORKFLOW_STATE = 'configure';

    // Dashboard state
    let GDT_DASHBOARD_PROGRAMS = [];
    let GDT_DASHBOARD_COLLAPSED = false;
    let GDT_DASHBOARD_REFRESH_TIMER = null;
    const GDT_DASHBOARD_REFRESH_INTERVAL = 60000; // 60 seconds

    // What-If re-model state
    let GS_WHAT_IF_MODE = false;
    let GS_WHAT_IF_CACHED_PROGRAM = null; // Snapshot of active program before what-if

    const GS_SIMTRAFFIC_CACHE = {};
    let GS_SIMTRAFFIC_QUEUE = [];
    let GS_SIMTRAFFIC_BUSY = false;
    let GS_SIMTRAFFIC_ENABLED = true;
    let GS_SIMTRAFFIC_ERROR_COUNT = 0;
    const GS_SIMTRAFFIC_ERROR_CUTOFF = 8;
    let GS_BTS_AVG_TIMES = null;

    // ICAO/IATA airline code mappings - uses PERTI when available
    const AIRLINE_CODE_MAP = (typeof PERTI !== 'undefined' && PERTI.FACILITY && PERTI.FACILITY.AIRLINE_CODES)
        ? PERTI.FACILITY.AIRLINE_CODES
        : {
            'AAL': 'AA', 'AA': 'AA',
            'DAL': 'DL', 'DL': 'DL',
            'UAL': 'UA', 'UA': 'UA',
            'SWA': 'WN', 'WN': 'WN',
            'JBU': 'B6', 'B6': 'B6',
            'ASA': 'AS', 'AS': 'AS',
            'AAY': 'G4', 'G4': 'G4',
            'FFT': 'F9', 'F9': 'F9',
            'NKS': 'NK', 'NK': 'NK',
            'ENY': 'MQ', 'MQ': 'MQ',
            'ASQ': 'EV', 'EV': 'EV',
            'EDV': '9E', '9E': '9E',
            'SKW': 'OO', 'OO': 'OO',
            'ASH': 'YV', 'YV': 'YV',
            'RPA': 'YX', 'YX': 'YX',
            'HAL': 'HA', 'HA': 'HA',
            'FDX': 'FX', 'FX': 'FX',
            'UPS': '5X', '5X': '5X',
        };

    // ========================================================================
    // Active Programs Dashboard
    // ========================================================================

    function loadActiveProgramsDashboard() {
        $.ajax({
            url: GS_API.active + '?include_recent=1',
            method: 'GET',
            dataType: 'json',
            success: function(resp) {
                if (resp.status === 'ok' && resp.data && resp.data.programs) {
                    GDT_DASHBOARD_PROGRAMS = resp.data.programs;
                    renderDashboard(resp.data.programs, resp.data.server_utc);
                    if (GDT_TIMELINE_VISIBLE) renderTimeline();
                }
            },
            error: function() {
                // Silently fail on dashboard load - not critical
                console.warn('GDT Dashboard: Failed to load active programs');
            }
        });
    }

    function renderDashboard(programs, serverUtc) {
        var container = document.getElementById('gdt_dashboard');
        var cardsEl = document.getElementById('gdt_dashboard_cards');
        var emptyEl = document.getElementById('gdt_dashboard_empty');
        var summaryEl = document.getElementById('gdt_dashboard_summary');
        var countBadge = document.getElementById('gdt_dashboard_count');
        var refreshTimeEl = document.getElementById('gdt_dashboard_refresh_time');

        if (!container || !cardsEl) return;

        // Show dashboard
        container.style.display = '';

        // Count active programs (not completed/cancelled/transitioned)
        var activePrograms = programs.filter(function(p) {
            return ['ACTIVE', 'PROPOSED', 'MODELING', 'PENDING_COORD'].indexOf(p.status) !== -1;
        });

        if (programs.length === 0) {
            emptyEl.style.display = '';
            cardsEl.innerHTML = '';
            summaryEl.style.display = 'none';
            countBadge.style.display = 'none';
            return;
        }

        emptyEl.style.display = 'none';
        countBadge.style.display = '';
        countBadge.textContent = activePrograms.length;

        // Build cards
        var html = '';
        var totalControlled = 0;

        for (var i = 0; i < programs.length; i++) {
            var p = programs[i];
            totalControlled += (p.controlled_flights || 0);
            html += buildProgramCard(p);
        }

        cardsEl.innerHTML = html;

        // Summary
        if (activePrograms.length > 0) {
            summaryEl.style.display = '';
            document.getElementById('gdt_dashboard_total_controlled').textContent = totalControlled;
        } else {
            summaryEl.style.display = 'none';
        }

        // Refresh time
        if (refreshTimeEl) {
            var now = new Date();
            var hh = String(now.getUTCHours()).padStart(2, '0');
            var mm = String(now.getUTCMinutes()).padStart(2, '0');
            refreshTimeEl.textContent = hh + ':' + mm + 'Z';
        }

        // Highlight selected program
        if (GS_CURRENT_PROGRAM_ID) {
            var sel = document.querySelector('.gdt-program-card[data-program-id="' + GS_CURRENT_PROGRAM_ID + '"]');
            if (sel) sel.classList.add('selected');
        }
    }

    function buildProgramCard(p) {
        var typeClass = 'gdt-card-type-gs';
        var typeLabel = p.program_type || 'GS';
        if (typeLabel.indexOf('GDP') !== -1) typeClass = 'gdt-card-type-gdp';
        else if (typeLabel === 'AFP') typeClass = 'gdt-card-type-afp';

        var statusClass = 'gdt-card-status-' + (p.status || 'proposed').toLowerCase();

        // Format time window
        var startStr = formatZuluShort(p.start_utc);
        var endStr = formatZuluShort(p.end_utc);

        // Elapsed percentage
        var elapsed = parseFloat(p.elapsed_pct) || 0;
        var minsLeft = parseInt(p.minutes_remaining) || 0;

        // Progress bar color
        var progressColor = '#28a745';
        if (elapsed > 80) progressColor = '#dc3545';
        else if (elapsed > 60) progressColor = '#e67e22';

        // Metrics
        var totalFlights = p.total_flights || 0;
        var controlled = p.controlled_flights || 0;
        var exempt = p.exempt_flights || 0;
        var avgDelay = p.avg_delay_min ? parseFloat(p.avg_delay_min).toFixed(0) : '-';
        var maxDelay = p.max_delay_min ? parseInt(p.max_delay_min) : 0;

        // Delay color coding
        var avgDelayClass = '';
        if (avgDelay !== '-') {
            var ad = parseInt(avgDelay);
            if (ad > 60) avgDelayClass = ' text-danger';
            else if (ad > 30) avgDelayClass = ' text-warning';
        }
        var maxDelayClass = '';
        if (maxDelay > 60) maxDelayClass = ' text-danger';
        else if (maxDelay > 30) maxDelayClass = ' text-warning';

        // Program duration
        var durationStr = '';
        if (p.start_utc && p.end_utc) {
            try {
                var durMin = Math.round((new Date(p.end_utc) - new Date(p.start_utc)) / 60000);
                if (durMin >= 60) durationStr = Math.floor(durMin / 60) + 'h' + (durMin % 60 > 0 ? String(durMin % 60).padStart(2, '0') + 'm' : '');
                else durationStr = durMin + 'm';
            } catch(e) {}
        }

        // Rate info for GDP programs
        var rateStr = '';
        if (typeLabel.indexOf('GDP') !== -1 && p.program_rate) {
            rateStr = p.program_rate + '/hr';
            if (p.delay_limit_min) rateStr += ', cap ' + p.delay_limit_min + 'm';
        }

        // Scope info (extract ARTCC from scope_json if available)
        var artcc = '';
        if (p.scope_json) {
            try {
                var scope = typeof p.scope_json === 'string' ? JSON.parse(p.scope_json) : p.scope_json;
                if (scope.arr_center) artcc = scope.arr_center;
                else if (scope.artcc) artcc = scope.artcc;
            } catch(e) {}
        }

        // Determine which quick actions to show
        var actions = '';
        if (p.status === 'ACTIVE') {
            actions += '<button class="btn btn-sm btn-outline-primary" onclick="event.stopPropagation(); dashboardExtend(' + p.program_id + ');" title="' + PERTII18n.t('gdt.dashboard.action.extendTitle') + '">' + PERTII18n.t('gdt.dashboard.action.extend') + '</button>';
            actions += '<button class="btn btn-sm btn-outline-warning" onclick="event.stopPropagation(); dashboardRevise(' + p.program_id + ');" title="' + PERTII18n.t('gdt.dashboard.action.reviseTitle') + '">' + PERTII18n.t('gdt.dashboard.action.revise') + '</button>';
            if (typeLabel === 'GS') {
                actions += '<button class="btn btn-sm btn-outline-info" onclick="event.stopPropagation(); dashboardTransition(' + p.program_id + ');" title="' + PERTII18n.t('gdt.dashboard.action.transitionTitle') + '">GS&rarr;GDP</button>';
            }
            actions += '<button class="btn btn-sm btn-outline-secondary" onclick="event.stopPropagation(); dashboardRemodel(' + p.program_id + ');" title="' + PERTII18n.t('gdt.dashboard.action.remodelTitle') + '">' + PERTII18n.t('gdt.dashboard.action.remodel') + '</button>';
            actions += '<button class="btn btn-sm btn-outline-danger" onclick="event.stopPropagation(); dashboardCancel(' + p.program_id + ');" title="' + PERTII18n.t('gdt.dashboard.action.cancelTitle') + '">' + PERTII18n.t('gdt.dashboard.action.cancel') + '</button>';
        } else if (p.status === 'PROPOSED' || p.status === 'MODELING' || p.status === 'PENDING_COORD') {
            actions += '<button class="btn btn-sm btn-outline-danger" onclick="event.stopPropagation(); dashboardDelete(' + p.program_id + ');" title="' + PERTII18n.t('gdt.dashboard.action.deleteTitle') + '"><i class="fas fa-trash-alt mr-1"></i>' + PERTII18n.t('gdt.dashboard.action.delete') + '</button>';
        }

        var chainIndicator = '';
        if (p.parent_program_id) {
            chainIndicator = '<span class="badge badge-info ml-1" style="font-size:0.55rem;" title="Transitioned from program #' + p.parent_program_id + '">CHAIN</span>';
        }

        return '<div class="gdt-program-card" data-program-id="' + p.program_id + '" onclick="loadProgramFromDashboard(' + p.program_id + ');">' +
            '<div class="d-flex justify-content-between align-items-start">' +
                '<div>' +
                    '<span class="gdt-card-type ' + typeClass + '">' + escapeHtml(typeLabel) + '</span>' +
                    ' <span class="gdt-card-status ' + statusClass + '">' + escapeHtml(p.status || '') + '</span>' +
                    chainIndicator +
                '</div>' +
                (p.adv_number ? '<span class="text-muted" style="font-size:0.65rem;">' + escapeHtml(p.adv_number) + '</span>' : '') +
            '</div>' +
            '<div class="mt-1">' +
                '<span class="gdt-card-element">' + escapeHtml(p.ctl_element || '') + '</span>' +
                (artcc ? ' <span class="gdt-card-artcc">/ ' + escapeHtml(artcc) + '</span>' : '') +
            '</div>' +
            '<div class="gdt-card-time">' + startStr + ' - ' + endStr +
                (durationStr ? ' <span class="text-muted" style="font-size:0.6rem;">(' + durationStr + ')</span>' : '') +
                (minsLeft > 0 ? ' <span class="text-muted">(' + minsLeft + 'm left)</span>' : '') +
                (p.status === 'ACTIVE' && minsLeft <= 0 ? ' <span class="badge badge-danger" style="font-size:0.5rem;">EXPIRED</span>' : '') +
            '</div>' +
            (rateStr ? '<div class="text-muted" style="font-size:0.65rem;margin-top:1px;"><i class="fas fa-tachometer-alt mr-1"></i>' + rateStr + '</div>' : '') +
            '<div class="gdt-card-progress">' +
                '<div class="gdt-card-progress-bar" style="width: ' + Math.min(100, elapsed) + '%; background: ' + progressColor + ';"></div>' +
            '</div>' +
            '<div class="gdt-card-metrics">' +
                '<div><span class="gdt-card-metric-value">' + totalFlights + '</span> ' + PERTII18n.t('gdt.dashboard.metric.total', {fallback: 'total'}) + '</div>' +
                '<div><span class="gdt-card-metric-value">' + controlled + '</span> ' + PERTII18n.t('gdt.dashboard.metric.controlled') + '</div>' +
                '<div><span class="gdt-card-metric-value">' + exempt + '</span> ' + PERTII18n.t('gdt.dashboard.metric.exempt') + '</div>' +
                '<div><span class="gdt-card-metric-value' + avgDelayClass + '">' + avgDelay + '</span> ' + PERTII18n.t('gdt.dashboard.metric.avgDelay') + '</div>' +
                (maxDelay > 0 ? '<div><span class="gdt-card-metric-value' + maxDelayClass + '">' + maxDelay + '</span> max</div>' : '') +
            '</div>' +
            (actions ? '<div class="gdt-card-actions">' + actions + '</div>' : '') +
        '</div>';
    }

    function formatZuluShort(isoStr) {
        if (!isoStr) return '--:--Z';
        try {
            var d = new Date(isoStr);
            if (isNaN(d.getTime())) return '--:--Z';
            var dd = String(d.getUTCDate()).padStart(2, '0');
            var hh = String(d.getUTCHours()).padStart(2, '0');
            var mm = String(d.getUTCMinutes()).padStart(2, '0');
            return dd + '/' + hh + mm + 'Z';
        } catch(e) { return '--:--Z'; }
    }

    function toggleDashboard() {
        var body = document.getElementById('gdt_dashboard_body');
        var chevron = document.getElementById('gdt_dashboard_chevron');
        if (!body) return;

        GDT_DASHBOARD_COLLAPSED = !GDT_DASHBOARD_COLLAPSED;
        body.style.display = GDT_DASHBOARD_COLLAPSED ? 'none' : '';
        if (chevron) {
            chevron.className = GDT_DASHBOARD_COLLAPSED ? 'fas fa-chevron-right' : 'fas fa-chevron-down';
        }
    }

    function loadProgramFromDashboard(programId) {
        // Remove selected from all cards
        document.querySelectorAll('.gdt-program-card.selected').forEach(function(el) {
            el.classList.remove('selected');
        });
        // Highlight this card
        var card = document.querySelector('.gdt-program-card[data-program-id="' + programId + '"]');
        if (card) card.classList.add('selected');

        // Load program into the form
        GS_CURRENT_PROGRAM_ID = programId;

        // Find program in cached data (handles string/number type coercion)
        var program = findDashboardProgram(programId);

        if (program) {
            populateFormFromProgram(program);

            // Set stepper based on program status
            if (program.status === 'ACTIVE' || program.status === 'TRANSITIONED') {
                setWorkflowState('active');
            } else if (program.status === 'MODELING') {
                setWorkflowState('model');
            } else if (program.status === 'PROPOSED' || program.status === 'PENDING_COORD') {
                setWorkflowState('preview');
            } else if (program.status === 'COMPLETED' || program.status === 'CANCELLED') {
                // Completed/cancelled: show active state (read-only view)
                setWorkflowState('active');
            } else {
                setWorkflowState('configure');
            }
        }

        // Scroll to the form
        var section = document.getElementById('gs_section');
        if (section) {
            section.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    function populateFormFromProgram(p) {
        // Populate basic fields
        var el = document.getElementById('gs_ctl_element');
        if (el) el.value = p.ctl_element || '';

        el = document.getElementById('gs_element_type');
        if (el) el.value = p.element_type || 'APT';

        el = document.getElementById('gs_adv_number');
        if (el) el.value = p.adv_number || '';

        // Populate program type selector and show/hide GDP fields
        var pType = p.program_type || 'GS';
        el = document.getElementById('gs_program_type');
        if (el) {
            el.value = pType;
            // Trigger the change handler to update header and field visibility
            $(el).trigger('change');
        }

        // GDP-specific fields
        if (pType.indexOf('GDP') !== -1) {
            el = document.getElementById('gs_program_rate');
            if (el) el.value = p.program_rate || '';
            el = document.getElementById('gs_delay_limit');
            if (el) el.value = p.delay_limit_min || '';
            el = document.getElementById('gs_reserve_rate');
            if (el) el.value = p.reserve_rate || '';
        }

        // Populate time fields (convert ISO to datetime-local format)
        if (p.start_utc) {
            el = document.getElementById('gs_start');
            if (el) el.value = isoToDatetimeLocal(p.start_utc);
        }
        if (p.end_utc) {
            el = document.getElementById('gs_end');
            if (el) el.value = isoToDatetimeLocal(p.end_utc);
        }

        // Impacting condition
        el = document.getElementById('gs_impacting_condition');
        if (el) el.value = p.impacting_condition || '';

        // Probability
        el = document.getElementById('gs_prob_ext');
        if (el) el.value = p.gs_probability || '';

        // Comments
        el = document.getElementById('gs_comments');
        if (el) el.value = p.comments || '';

        // Scope
        if (p.scope_json) {
            try {
                var scope = typeof p.scope_json === 'string' ? JSON.parse(p.scope_json) : p.scope_json;
                // Populate origin centers
                if (scope.origin_centers) {
                    el = document.getElementById('gs_origin_centers');
                    if (el) el.value = scope.origin_centers;
                }
                // Populate origin airports
                if (scope.origin_airports) {
                    el = document.getElementById('gs_origin_airports');
                    if (el) el.value = scope.origin_airports;
                }
                // Populate arrival airports
                if (scope.arrival_airports) {
                    el = document.getElementById('gs_airports');
                    if (el) el.value = scope.arrival_airports;
                }
            } catch(e) {}
        }

        // Update status badge
        var badge = document.getElementById('gs_status_badge');
        if (badge) {
            badge.textContent = p.status || 'UNKNOWN';
            badge.className = 'badge tmi-badge-status badge-' + getStatusBadgeClass(p.status);
        }

        GS_CURRENT_PROGRAM_STATUS = p.status;
    }

    function isoToDatetimeLocal(iso) {
        if (!iso) return '';
        try {
            // Handle "2026-02-11T14:00:00Z" format
            var d = new Date(iso);
            if (isNaN(d.getTime())) return '';
            var yyyy = d.getUTCFullYear();
            var mm = String(d.getUTCMonth() + 1).padStart(2, '0');
            var dd = String(d.getUTCDate()).padStart(2, '0');
            var hh = String(d.getUTCHours()).padStart(2, '0');
            var mi = String(d.getUTCMinutes()).padStart(2, '0');
            return yyyy + '-' + mm + '-' + dd + 'T' + hh + ':' + mi;
        } catch(e) { return ''; }
    }

    function getStatusBadgeClass(status) {
        switch ((status || '').toUpperCase()) {
            case 'ACTIVE': return 'success';
            case 'MODELING': return 'warning';
            case 'PROPOSED': return 'info';
            case 'PENDING_COORD': return 'info';
            case 'TRANSITIONED': return 'secondary';
            case 'CANCELLED': return 'danger';
            case 'COMPLETED': return 'secondary';
            case 'PURGED': return 'dark';
            default: return 'secondary';
        }
    }

    function resetAndNewProgram() {
        GS_CURRENT_PROGRAM_ID = null;
        GS_CURRENT_PROGRAM_STATUS = null;
        GS_SIMULATION_READY = false;

        // Clear selected card
        document.querySelectorAll('.gdt-program-card.selected').forEach(function(el) {
            el.classList.remove('selected');
        });

        // Reset form (calls existing resetGsForm if available)
        if (typeof resetGsForm === 'function') {
            resetGsForm();
        }

        setWorkflowState('configure');

        // Update status badge
        var badge = document.getElementById('gs_status_badge');
        if (badge) {
            badge.textContent = PERTII18n.t('gdt.dashboard.draftLocal');
            badge.className = 'badge tmi-badge-status badge-secondary';
        }
    }

    // ========================================================================
    // Dashboard Quick Actions
    // ========================================================================

    function dashboardExtend(programId) {
        // Find program in cached data
        var program = findDashboardProgram(programId);
        if (!program) {
            Swal.fire(PERTII18n.t('common.error'), PERTII18n.t('gdt.dashboard.programNotFound'), 'error');
            return;
        }

        // Populate extend modal
        var info = escapeHtml(program.program_type || 'GS') + ' #' + programId +
            ' - ' + escapeHtml(program.ctl_element || '') +
            ' (' + escapeHtml(program.status || '') + ')';
        document.getElementById('gdt_extend_program_info').innerHTML = info;

        var currentEnd = formatZuluShort(program.end_utc);
        document.getElementById('gdt_extend_current_end').value = currentEnd;

        // Pre-fill new end time 1 hour after current end
        if (program.end_utc) {
            var endDt = new Date(program.end_utc);
            endDt.setUTCHours(endDt.getUTCHours() + 1);
            document.getElementById('gdt_extend_new_end').value = isoToDatetimeLocal(endDt.toISOString());
        }

        // Pre-fill probability from current program
        var probEl = document.getElementById('gdt_extend_prob_ext');
        if (probEl) probEl.value = program.gs_probability || '';

        // Clear previous state
        document.getElementById('gdt_extend_comments').value = '';
        document.getElementById('gdt_extend_advisory_preview').textContent = '';
        var errEl = document.getElementById('gdt_extend_error');
        errEl.classList.add('d-none');
        errEl.textContent = '';

        // Store program ID for submit
        document.getElementById('gdt_extend_modal').dataset.programId = programId;

        // Build extend advisory preview
        buildExtendAdvisoryPreview(program);

        // Show modal
        $('#gdt_extend_modal').modal('show');
    }

    function buildExtendAdvisoryPreview(program) {
        var now = new Date();
        var advNum = program.adv_number || 'XXX';
        var ctlElement = program.ctl_element || 'XXX';
        var newEndEl = document.getElementById('gdt_extend_new_end');
        var newEnd = newEndEl ? newEndEl.value : '';
        var probExt = document.getElementById('gdt_extend_prob_ext').value || '';
        var comments = document.getElementById('gdt_extend_comments').value || '';

        var startStr = formatZuluFromIso(program.start_utc);
        var endStr = newEnd ? formatZuluFromLocal(newEnd) : formatZuluFromIso(program.end_utc);
        var headerDate = String(now.getUTCMonth() + 1).padStart(2, '0') + '/' +
            String(now.getUTCDate()).padStart(2, '0') + '/' + now.getUTCFullYear();

        var typeLabel = (program.program_type || 'GS') === 'GS' ? PERTII18n.t('gdt.page.groundStopLabel') : PERTII18n.t('gdt.page.groundDelayProgramLabel');

        var lines = [];
        lines.push(AdvisoryConfig.getPrefix() + ' ADVZY ' + advNum + ' ' + ctlElement + ' ' + headerDate + ' CDM ' + typeLabel + ' EXTENSION');
        lines.push('CTL ELEMENT: ' + ctlElement);
        lines.push(typeLabel + ' PERIOD: ' + startStr + ' - ' + endStr);
        if (probExt) lines.push('PROBABILITY OF EXTENSION: ' + probExt);
        if (comments) lines.push('COMMENTS: ' + comments);

        var pre = document.getElementById('gdt_extend_advisory_preview');
        if (pre) pre.textContent = lines.join('\n');
    }

    function submitExtend() {
        var modal = document.getElementById('gdt_extend_modal');
        var programId = parseInt(modal.dataset.programId);
        var newEndVal = document.getElementById('gdt_extend_new_end').value;
        var errEl = document.getElementById('gdt_extend_error');
        var btn = document.getElementById('gdt_extend_submit_btn');

        if (!newEndVal) {
            errEl.textContent = PERTII18n.t('gdt.dashboard.newEndTimeRequired');
            errEl.classList.remove('d-none');
            return;
        }

        // Convert local datetime to ISO UTC
        var newEndDt = new Date(newEndVal);
        var newEndUtc = newEndDt.toISOString();

        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> ' + PERTII18n.t('gdt.dashboard.extending');

        $.ajax({
            url: GS_API.extend,
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                program_id: programId,
                new_end_utc: newEndUtc
            }),
            success: function(resp) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-clock mr-1"></i> ' + PERTII18n.t('gdt.page.extendProgram');

                if (resp.status === 'ok') {
                    $('#gdt_extend_modal').modal('hide');
                    Swal.fire({
                        icon: 'success',
                        title: PERTII18n.t('gdt.dashboard.programExtended'),
                        text: PERTII18n.t('gdt.dashboard.programExtendedText', { id: programId }) +
                            (resp.data && resp.data.new_slots_count ? ' ' + PERTII18n.t('gdt.dashboard.newSlotsCreated', { count: resp.data.new_slots_count }) : ''),
                        timer: 3000,
                        showConfirmButton: false
                    });
                    loadActiveProgramsDashboard();

                    // If this is the currently loaded program, refresh form
                    if (GS_CURRENT_PROGRAM_ID === programId && resp.data && resp.data.program) {
                        populateFormFromProgram(resp.data.program);
                    }
                } else {
                    errEl.textContent = resp.message || PERTII18n.t('gdt.dashboard.extendFailed');
                    errEl.classList.remove('d-none');
                }
            },
            error: function(xhr) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-clock mr-1"></i> ' + PERTII18n.t('gdt.page.extendProgram');
                var msg = PERTII18n.t('gdt.dashboard.extendFailed');
                try { msg = JSON.parse(xhr.responseText).message || msg; } catch(e) {}
                errEl.textContent = msg;
                errEl.classList.remove('d-none');
            }
        });
    }

    function dashboardRevise(programId) {
        var program = findDashboardProgram(programId);
        if (!program) {
            Swal.fire(PERTII18n.t('common.error'), PERTII18n.t('gdt.dashboard.programNotFound'), 'error');
            return;
        }

        // Populate revise modal
        var info = escapeHtml(program.program_type || 'GS') + ' #' + programId +
            ' - ' + escapeHtml(program.ctl_element || '') +
            ' (Rev ' + ((program.revision_number || 0) + 1) + ')';
        document.getElementById('gdt_revise_program_info').innerHTML = info;

        // Pre-fill current values
        document.getElementById('gdt_revise_rate').value = program.program_rate || '';
        document.getElementById('gdt_revise_delay_cap').value = program.delay_limit_min || '';
        if (program.end_utc) {
            document.getElementById('gdt_revise_end_utc').value = isoToDatetimeLocal(program.end_utc);
        }
        document.getElementById('gdt_revise_impacting').value = program.impacting_condition || 'WEATHER';
        document.getElementById('gdt_revise_prob_ext').value = program.gs_probability || '';
        document.getElementById('gdt_revise_comments').value = '';

        // Clear previous state
        document.getElementById('gdt_revise_advisory_preview').textContent = '';
        var errEl = document.getElementById('gdt_revise_error');
        errEl.classList.add('d-none');
        errEl.textContent = '';

        // Store program data for submit
        document.getElementById('gdt_revise_modal').dataset.programId = programId;
        document.getElementById('gdt_revise_modal').dataset.originalRate = program.program_rate || '';
        document.getElementById('gdt_revise_modal').dataset.originalDelayCap = program.delay_limit_min || '';

        // Build revision advisory preview
        buildReviseAdvisoryPreview(program);

        $('#gdt_revise_modal').modal('show');
    }

    function buildReviseAdvisoryPreview(program) {
        var now = new Date();
        var advNum = program.adv_number || 'XXX';
        var ctlElement = program.ctl_element || 'XXX';
        var newRate = document.getElementById('gdt_revise_rate').value || program.program_rate || '';
        var comments = document.getElementById('gdt_revise_comments').value || '';
        var headerDate = String(now.getUTCMonth() + 1).padStart(2, '0') + '/' +
            String(now.getUTCDate()).padStart(2, '0') + '/' + now.getUTCFullYear();

        var typeLabel = (program.program_type || 'GS') === 'GS' ? PERTII18n.t('gdt.page.groundStopLabel') : PERTII18n.t('gdt.page.groundDelayProgramLabel');

        var lines = [];
        lines.push(AdvisoryConfig.getPrefix() + ' ADVZY ' + advNum + ' ' + ctlElement + ' ' + headerDate + ' CDM ' + typeLabel + ' REVISION');
        lines.push('CTL ELEMENT: ' + ctlElement);
        if (newRate) lines.push('PROGRAM RATE: ' + newRate);

        // Show what changed
        var origRate = document.getElementById('gdt_revise_modal').dataset.originalRate;
        if (origRate && newRate && origRate !== newRate) {
            lines.push('RATE REVISED FROM ' + origRate + ' TO ' + newRate);
        }

        if (comments) lines.push('COMMENTS: ' + comments);

        var pre = document.getElementById('gdt_revise_advisory_preview');
        if (pre) pre.textContent = lines.join('\n');
    }

    function submitRevise() {
        var modal = document.getElementById('gdt_revise_modal');
        var programId = parseInt(modal.dataset.programId);
        var errEl = document.getElementById('gdt_revise_error');
        var btn = document.getElementById('gdt_revise_submit_btn');

        // Collect changed values
        var body = { program_id: programId };

        var rate = document.getElementById('gdt_revise_rate').value;
        if (rate) body.program_rate = parseInt(rate);

        var delayCap = document.getElementById('gdt_revise_delay_cap').value;
        if (delayCap) body.delay_limit_min = parseInt(delayCap);

        var endUtc = document.getElementById('gdt_revise_end_utc').value;
        if (endUtc) body.end_utc = new Date(endUtc).toISOString();

        var impacting = document.getElementById('gdt_revise_impacting').value;
        if (impacting) body.impacting_condition = impacting;

        var probExt = document.getElementById('gdt_revise_prob_ext').value;
        if (probExt) body.gs_probability = probExt;

        var comments = document.getElementById('gdt_revise_comments').value;
        if (comments) body.comments = comments;

        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> ' + PERTII18n.t('gdt.dashboard.revising');

        $.ajax({
            url: GS_API.revise,
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(body),
            success: function(resp) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-edit mr-1"></i> ' + PERTII18n.t('gdt.page.reviseProgram');

                if (resp.status === 'ok') {
                    $('#gdt_revise_modal').modal('hide');

                    var changesText = (resp.data && resp.data.changes)
                        ? resp.data.changes.join(', ')
                        : PERTII18n.t('gdt.dashboard.parametersUpdated');

                    Swal.fire({
                        icon: 'success',
                        title: PERTII18n.t('gdt.dashboard.programRevised'),
                        html: 'Program #' + programId + ' revision #' +
                            (resp.data ? resp.data.revision_number : '?') +
                            '<br><small class="text-muted">' + escapeHtml(changesText) + '</small>',
                        timer: 4000,
                        showConfirmButton: false
                    });
                    loadActiveProgramsDashboard();

                    // If currently loaded program, refresh form
                    if (GS_CURRENT_PROGRAM_ID === programId && resp.data && resp.data.program) {
                        populateFormFromProgram(resp.data.program);
                    }
                } else {
                    errEl.textContent = resp.message || PERTII18n.t('gdt.dashboard.reviseFailed');
                    errEl.classList.remove('d-none');
                }
            },
            error: function(xhr) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-edit mr-1"></i> ' + PERTII18n.t('gdt.page.reviseProgram');
                var msg = PERTII18n.t('gdt.dashboard.reviseFailed');
                try { msg = JSON.parse(xhr.responseText).message || msg; } catch(e) {}
                errEl.textContent = msg;
                errEl.classList.remove('d-none');
            }
        });
    }

    function dashboardTransition(programId) {
        var program = findDashboardProgram(programId);
        if (!program) {
            Swal.fire(PERTII18n.t('common.error'), PERTII18n.t('gdt.dashboard.programNotFound'), 'error');
            return;
        }

        if ((program.program_type || '') !== 'GS') {
            Swal.fire(PERTII18n.t('common.error'), PERTII18n.t('gdt.dashboard.onlyGsCanTransition'), 'error');
            return;
        }

        // Populate GS info
        var gsInfo = 'GS #' + programId + ' - ' + escapeHtml(program.ctl_element || '') +
            ' (' + formatZuluFromIso(program.start_utc) + ' - ' + formatZuluFromIso(program.end_utc) + ')' +
            ' | ' + escapeHtml(program.impacting_condition || 'WEATHER');
        document.getElementById('gdt_transition_gs_info').innerHTML = gsInfo;

        // Reset to phase 1 (propose)
        setTransitionPhase('propose');

        // Pre-fill GDP end time: 3 hours after GS end
        if (program.end_utc) {
            var endDt = new Date(program.end_utc);
            endDt.setUTCHours(endDt.getUTCHours() + 3);
            document.getElementById('gdt_transition_end_utc').value = isoToDatetimeLocal(endDt.toISOString());
        }

        // Pre-fill impacting condition from GS
        document.getElementById('gdt_transition_impacting').value = program.impacting_condition || 'WEATHER';
        document.getElementById('gdt_transition_gdp_type').value = 'GDP-DAS';
        document.getElementById('gdt_transition_rate').value = '';
        document.getElementById('gdt_transition_reserve').value = '';
        document.getElementById('gdt_transition_delay_cap').value = '180';
        document.getElementById('gdt_transition_comments').value = '';

        // Clear state
        var errEl = document.getElementById('gdt_transition_error');
        errEl.classList.add('d-none');
        errEl.textContent = '';

        // Store GS program ID and chain info
        var modal = document.getElementById('gdt_transition_modal');
        modal.dataset.gsProgramId = programId;
        modal.dataset.gdpProgramId = '';

        // Build advisory preview
        buildTransitionAdvisoryPreview(program, 'propose');

        $('#gdt_transition_modal').modal('show');
    }

    function setTransitionPhase(phase) {
        var phaseBadge = document.getElementById('gdt_transition_phase_badge');
        var phaseHelp = document.getElementById('gdt_transition_phase_help');
        var paramsDiv = document.getElementById('gdt_transition_params');
        var proposedBanner = document.getElementById('gdt_transition_proposed_banner');
        var cumulativeRow = document.getElementById('gdt_transition_cumulative_row');
        var proposeBtn = document.getElementById('gdt_transition_propose_btn');
        var activateBtn = document.getElementById('gdt_transition_activate_btn');

        if (phase === 'propose') {
            phaseBadge.className = 'badge badge-secondary mr-2';
            phaseBadge.textContent = PERTII18n.t('gdt.dashboard.phase1Propose');
            phaseHelp.textContent = PERTII18n.t('gdt.dashboard.phase1Help');
            paramsDiv.classList.remove('d-none');
            proposedBanner.classList.add('d-none');
            cumulativeRow.classList.add('d-none');
            proposeBtn.classList.remove('d-none');
            activateBtn.classList.add('d-none');
        } else if (phase === 'activate') {
            phaseBadge.className = 'badge badge-success mr-2';
            phaseBadge.textContent = PERTII18n.t('gdt.dashboard.phase2Activate');
            phaseHelp.textContent = PERTII18n.t('gdt.dashboard.phase2Help');
            paramsDiv.classList.add('d-none');
            proposedBanner.classList.remove('d-none');
            cumulativeRow.classList.remove('d-none');
            proposeBtn.classList.add('d-none');
            activateBtn.classList.remove('d-none');
        }
    }

    function buildTransitionAdvisoryPreview(gsProgram, phase) {
        var now = new Date();
        var ctlElement = gsProgram.ctl_element || 'XXX';
        var headerDate = String(now.getUTCMonth() + 1).padStart(2, '0') + '/' +
            String(now.getUTCDate()).padStart(2, '0') + '/' + now.getUTCFullYear();
        var lines = [];

        if (phase === 'propose') {
            var gdpType = document.getElementById('gdt_transition_gdp_type').value || 'GDP-DAS';
            var rate = document.getElementById('gdt_transition_rate').value || '';
            var delayCap = document.getElementById('gdt_transition_delay_cap').value || '';
            var endUtcVal = document.getElementById('gdt_transition_end_utc').value || '';
            var comments = document.getElementById('gdt_transition_comments').value || '';
            var impacting = document.getElementById('gdt_transition_impacting').value || 'WEATHER';

            var gsStart = formatZuluFromIso(gsProgram.start_utc);
            var gdpEnd = endUtcVal ? formatZuluFromLocal(endUtcVal) : '--:--Z';

            lines.push(AdvisoryConfig.getPrefix() + ' ADVZY XXX ' + ctlElement + ' ' + headerDate + ' CDM PROPOSED GROUND DELAY PROGRAM');
            lines.push('CTL ELEMENT: ' + ctlElement);
            lines.push('IMPACTING CONDITION: ' + impacting);
            lines.push('GDP TYPE: ' + gdpType);
            if (rate) lines.push('PROGRAM RATE: ' + rate);
            if (delayCap) lines.push('DELAY ASSIGNMENT LIMIT: ' + delayCap + ' MINUTES');
            lines.push('GROUND STOP CURRENTLY IN EFFECT: ' + gsStart + ' - ' + formatZuluFromIso(gsProgram.end_utc));
            lines.push('PROPOSED GDP PERIOD: ' + formatZuluFromIso(gsProgram.end_utc) + ' - ' + gdpEnd);
            lines.push('CUMULATIVE PROGRAM PERIOD: ' + gsStart + ' - ' + gdpEnd);
            if (comments) lines.push('COMMENTS: ' + comments);
        } else {
            // Activate phase advisory
            var modal = document.getElementById('gdt_transition_modal');
            var gdpId = modal.dataset.gdpProgramId || '?';
            var cumText = document.getElementById('gdt_transition_cumulative').textContent || '';

            lines.push(AdvisoryConfig.getPrefix() + ' ADVZY XXX ' + ctlElement + ' ' + headerDate + ' CDM GROUND DELAY PROGRAM');
            lines.push('CTL ELEMENT: ' + ctlElement);
            lines.push('GROUND STOP CANCELLED.');
            lines.push('GROUND DELAY PROGRAM #' + gdpId + ' IS NOW IN EFFECT.');
            if (cumText) lines.push('CUMULATIVE PROGRAM PERIOD: ' + cumText);
        }

        var pre = document.getElementById('gdt_transition_advisory_preview');
        if (pre) pre.textContent = lines.join('\n');
    }

    function submitTransitionPropose() {
        var modal = document.getElementById('gdt_transition_modal');
        var gsProgramId = parseInt(modal.dataset.gsProgramId);
        var errEl = document.getElementById('gdt_transition_error');
        var btn = document.getElementById('gdt_transition_propose_btn');

        // Validate required fields
        var rate = document.getElementById('gdt_transition_rate').value;
        var endUtcVal = document.getElementById('gdt_transition_end_utc').value;

        if (!rate || parseInt(rate) <= 0) {
            errEl.textContent = PERTII18n.t('gdt.dashboard.rateRequired');
            errEl.classList.remove('d-none');
            return;
        }
        if (!endUtcVal) {
            errEl.textContent = PERTII18n.t('gdt.dashboard.gdpEndTimeRequired');
            errEl.classList.remove('d-none');
            return;
        }

        var gdpEndUtc = new Date(endUtcVal).toISOString();
        var gdpType = document.getElementById('gdt_transition_gdp_type').value || 'GDP-DAS';
        var reserve = document.getElementById('gdt_transition_reserve').value;
        var delayCap = document.getElementById('gdt_transition_delay_cap').value;
        var impacting = document.getElementById('gdt_transition_impacting').value;
        var comments = document.getElementById('gdt_transition_comments').value;

        var body = {
            gs_program_id: gsProgramId,
            phase: 'propose',
            gdp_type: gdpType,
            gdp_end_utc: gdpEndUtc,
            program_rate: parseInt(rate)
        };
        if (reserve) body.reserve_rate = parseInt(reserve);
        if (delayCap) body.delay_limit_min = parseInt(delayCap);
        if (impacting) body.impacting_condition = impacting;
        if (comments) body.comments = comments;

        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> ' + PERTII18n.t('gdt.dashboard.proposing');
        errEl.classList.add('d-none');

        $.ajax({
            url: GS_API.transition,
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(body),
            success: function(resp) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-file-alt mr-1"></i> ' + PERTII18n.t('gdt.page.proposeGdp');

                if (resp.status === 'ok' && resp.data) {
                    var gdpId = resp.data.gdp_program_id;
                    modal.dataset.gdpProgramId = gdpId;

                    // Show cumulative period
                    var cumStart = formatZuluFromIso(resp.data.cumulative_start);
                    var cumEnd = formatZuluFromIso(resp.data.cumulative_end);
                    document.getElementById('gdt_transition_cumulative').textContent = cumStart + ' - ' + cumEnd;
                    document.getElementById('gdt_transition_proposed_id').textContent = PERTII18n.t('gdt.dashboard.gdpNumber', { id: gdpId });

                    // Advance to phase 2
                    setTransitionPhase('activate');

                    var gsProgram = findDashboardProgram(gsProgramId);
                    if (gsProgram) buildTransitionAdvisoryPreview(gsProgram, 'activate');

                    Swal.fire({
                        icon: 'info',
                        title: PERTII18n.t('gdt.dashboard.gdpProposed'),
                        html: PERTII18n.t('gdt.dashboard.gdpProposedText', { id: gdpId }),
                        timer: 5000,
                        showConfirmButton: true
                    });

                    loadActiveProgramsDashboard();
                } else {
                    errEl.textContent = resp.message || PERTII18n.t('gdt.dashboard.proposeFailed');
                    errEl.classList.remove('d-none');
                }
            },
            error: function(xhr) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-file-alt mr-1"></i> ' + PERTII18n.t('gdt.page.proposeGdp');
                var msg = PERTII18n.t('gdt.dashboard.proposeFailed');
                try { msg = JSON.parse(xhr.responseText).message || msg; } catch(e) {}
                errEl.textContent = msg;
                errEl.classList.remove('d-none');
            }
        });
    }

    function submitTransitionActivate() {
        var modal = document.getElementById('gdt_transition_modal');
        var gsProgramId = parseInt(modal.dataset.gsProgramId);
        var gdpProgramId = parseInt(modal.dataset.gdpProgramId);
        var errEl = document.getElementById('gdt_transition_error');
        var btn = document.getElementById('gdt_transition_activate_btn');

        if (!gdpProgramId || gdpProgramId <= 0) {
            errEl.textContent = PERTII18n.t('gdt.dashboard.noProposedGdp');
            errEl.classList.remove('d-none');
            return;
        }

        Swal.fire({
            title: PERTII18n.t('gdt.dashboard.activateGdpTitle', { id: gdpProgramId }),
            html: PERTII18n.t('gdt.dashboard.activateGdpText', { gsId: gsProgramId, gdpId: gdpProgramId }),
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#28a745',
            confirmButtonText: PERTII18n.t('gdt.dashboard.yesActivate')
        }).then(function(result) {
            if (!result.isConfirmed) return;

            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> ' + PERTII18n.t('gdt.dashboard.activating');
            errEl.classList.add('d-none');

            $.ajax({
                url: GS_API.transition,
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    gs_program_id: gsProgramId,
                    phase: 'activate',
                    gdp_program_id: gdpProgramId
                }),
                success: function(resp) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-check-circle mr-1"></i> ' + PERTII18n.t('gdt.page.activateGdpCancelGs');

                    if (resp.status === 'ok') {
                        $('#gdt_transition_modal').modal('hide');
                        Swal.fire({
                            icon: 'success',
                            title: PERTII18n.t('gdt.dashboard.transitionComplete'),
                            html: 'GS #' + gsProgramId + ' &rarr; TRANSITIONED<br>GDP #' + gdpProgramId + ' &rarr; ACTIVE',
                            timer: 4000,
                            showConfirmButton: false
                        });
                        loadActiveProgramsDashboard();

                        // If the GS was loaded in the form, reset
                        if (GS_CURRENT_PROGRAM_ID === gsProgramId) {
                            resetAndNewProgram();
                        }
                    } else {
                        errEl.textContent = resp.message || PERTII18n.t('gdt.dashboard.activationFailed');
                        errEl.classList.remove('d-none');
                    }
                },
                error: function(xhr) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-check-circle mr-1"></i> ' + PERTII18n.t('gdt.page.activateGdpCancelGs');
                    var msg = PERTII18n.t('gdt.dashboard.activateGdpFailed');
                    try { msg = JSON.parse(xhr.responseText).message || msg; } catch(e) {}
                    errEl.textContent = msg;
                    errEl.classList.remove('d-none');
                }
            });
        });
    }

    function dashboardRemodel(programId) {
        var program = findDashboardProgram(programId);
        if (!program) {
            Swal.fire(PERTII18n.t('common.error'), PERTII18n.t('gdt.dashboard.programNotFound'), 'error');
            return;
        }

        if (program.status !== 'ACTIVE') {
            Swal.fire(PERTII18n.t('common.error'), PERTII18n.t('gdt.dashboard.onlyActiveCanRemodel'), 'error');
            return;
        }

        // Cache the active program state so we can restore it later
        GS_WHAT_IF_CACHED_PROGRAM = JSON.parse(JSON.stringify(program));
        GS_WHAT_IF_MODE = true;

        // Load the program into the form
        loadProgramFromDashboard(programId);

        // Set stepper to model state with what-if badge
        setWorkflowState('model');

        // Show what-if badge
        var whatIfBadge = document.getElementById('gdt_whatif_badge');
        if (whatIfBadge) whatIfBadge.classList.remove('d-none');

        // Show discard button
        var discardBtn = document.getElementById('gdt_whatif_discard_btn');
        if (discardBtn) discardBtn.classList.remove('d-none');

        Swal.fire({
            icon: 'info',
            title: PERTII18n.t('gdt.dashboard.whatIfMode'),
            html: PERTII18n.t('gdt.dashboard.whatIfModeText'),
            timer: 5000,
            showConfirmButton: true
        });
    }

    function exitWhatIfMode() {
        GS_WHAT_IF_MODE = false;

        // Hide what-if badge
        var whatIfBadge = document.getElementById('gdt_whatif_badge');
        if (whatIfBadge) whatIfBadge.classList.add('d-none');

        // Hide discard button
        var discardBtn = document.getElementById('gdt_whatif_discard_btn');
        if (discardBtn) discardBtn.classList.add('d-none');

        // Restore from cached program
        if (GS_WHAT_IF_CACHED_PROGRAM) {
            populateFormFromProgram(GS_WHAT_IF_CACHED_PROGRAM);
            setWorkflowState('active');
            GS_WHAT_IF_CACHED_PROGRAM = null;
        } else {
            resetAndNewProgram();
        }

        Swal.fire({
            icon: 'info',
            title: PERTII18n.t('gdt.dashboard.whatIfDiscarded'),
            text: PERTII18n.t('gdt.dashboard.returnedToActive'),
            timer: 2000,
            showConfirmButton: false
        });
    }

    function dashboardCancel(programId) {
        Swal.fire({
            title: PERTII18n.t('gdt.dashboard.cancelProgramTitle', { id: programId }),
            text: PERTII18n.t('gdt.dashboard.cancelProgramText'),
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            confirmButtonText: PERTII18n.t('gdt.dashboard.yesCancelIt')
        }).then(function(result) {
            if (result.isConfirmed) {
                $.ajax({
                    url: GS_API.cancel,
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({
                        program_id: programId,
                        cancel_reason: 'USER_CANCELLED'
                    }),
                    success: function(resp) {
                        if (resp.status === 'ok') {
                            Swal.fire(PERTII18n.t('gdt.dashboard.cancelled'), PERTII18n.t('gdt.dashboard.programCancelled'), 'success');
                            loadActiveProgramsDashboard();
                            if (GS_CURRENT_PROGRAM_ID === programId) {
                                resetAndNewProgram();
                            }
                        } else {
                            Swal.fire(PERTII18n.t('common.error'), resp.message || PERTII18n.t('gdt.dashboard.cancelFailed'), 'error');
                        }
                    },
                    error: function() {
                        Swal.fire(PERTII18n.t('common.error'), PERTII18n.t('gdt.dashboard.cancelFailed'), 'error');
                    }
                });
            }
        });
    }

    function dashboardDelete(programId) {
        var prog = findDashboardProgram(programId);
        var label = prog ? escapeHtml(prog.program_type + ' ' + (prog.ctl_element || '')) : 'Program #' + programId;

        Swal.fire({
            title: PERTII18n.t('gdt.dashboard.deleteTitle', { label: label }),
            text: PERTII18n.t('gdt.dashboard.deleteText'),
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            confirmButtonText: PERTII18n.t('common.yesDelete')
        }).then(function(result) {
            if (result.isConfirmed) {
                $.ajax({
                    url: GS_API.purge,
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({
                        program_id: programId,
                        purge_reason: 'PROPOSAL_DELETED'
                    }),
                    success: function(resp) {
                        if (resp.status === 'ok') {
                            Swal.fire(PERTII18n.t('gdt.dashboard.deleted'), PERTII18n.t('gdt.dashboard.proposalRemoved'), 'success');
                            loadActiveProgramsDashboard();
                            if (GS_CURRENT_PROGRAM_ID === programId) {
                                resetAndNewProgram();
                            }
                        } else {
                            Swal.fire(PERTII18n.t('common.error'), resp.message || PERTII18n.t('gdt.dashboard.deleteFailed'), 'error');
                        }
                    },
                    error: function(xhr) {
                        var msg = PERTII18n.t('gdt.dashboard.deleteFailed');
                        try {
                            var r = JSON.parse(xhr.responseText);
                            if (r.message) msg = r.message;
                        } catch(e) {}
                        Swal.fire(PERTII18n.t('common.error'), msg, 'error');
                    }
                });
            }
        });
    }

    function findDashboardProgram(programId) {
        for (var i = 0; i < GDT_DASHBOARD_PROGRAMS.length; i++) {
            if (GDT_DASHBOARD_PROGRAMS[i].program_id === programId ||
                GDT_DASHBOARD_PROGRAMS[i].program_id === String(programId)) {
                return GDT_DASHBOARD_PROGRAMS[i];
            }
        }
        return null;
    }

    function formatZuluFromIso(isoStr) {
        if (!isoStr) return '--:--Z';
        try {
            var d = new Date(isoStr);
            if (isNaN(d.getTime())) return '--:--Z';
            var dd = String(d.getUTCDate()).padStart(2, '0');
            var hh = String(d.getUTCHours()).padStart(2, '0');
            var mm = String(d.getUTCMinutes()).padStart(2, '0');
            return dd + '/' + hh + mm + 'Z';
        } catch(e) { return '--:--Z'; }
    }

    // ========================================================================
    // Multi-Program Timeline (Phase 6)
    // ========================================================================

    var GDT_TIMELINE_CHART = null;
    var GDT_TIMELINE_VISIBLE = false;

    function toggleTimeline() {
        var container = document.getElementById('gdt_timeline_container');
        var toggleText = document.getElementById('gdt_timeline_toggle_text');
        if (!container) return;

        GDT_TIMELINE_VISIBLE = !GDT_TIMELINE_VISIBLE;
        container.style.display = GDT_TIMELINE_VISIBLE ? 'block' : 'none';
        if (toggleText) toggleText.textContent = GDT_TIMELINE_VISIBLE ? PERTII18n.t('gdt.page.hideTimeline') : PERTII18n.t('gdt.page.showTimeline');

        if (GDT_TIMELINE_VISIBLE) {
            renderTimeline();
        }
    }

    function renderTimeline() {
        var canvas = document.getElementById('gdt_timeline_canvas');
        if (!canvas || typeof Chart === 'undefined') return;

        var programs = GDT_DASHBOARD_PROGRAMS.filter(function(p) {
            return p.start_utc && p.end_utc;
        });

        if (programs.length === 0) {
            if (GDT_TIMELINE_CHART) { GDT_TIMELINE_CHART.destroy(); GDT_TIMELINE_CHART = null; }
            return;
        }

        // Compute time range: earliest start - 1h to latest end + 1h
        var now = new Date();
        var minTime = new Date(now.getTime() - 3600000);
        var maxTime = new Date(now.getTime() + 3600000);
        programs.forEach(function(p) {
            var s = new Date(p.start_utc);
            var e = new Date(p.end_utc);
            if (s < minTime) minTime = new Date(s.getTime() - 3600000);
            if (e > maxTime) maxTime = new Date(e.getTime() + 3600000);
        });

        // Build datasets - one bar per program
        var labels = [];
        var data = [];
        var bgColors = [];
        var borderColors = [];
        var programIds = [];

        var typeColors = {
            'GS': { bg: 'rgba(220, 53, 69, 0.7)', border: '#dc3545' },
            'GDP': { bg: 'rgba(255, 193, 7, 0.7)', border: '#ffc107' },
            'GDP-DAS': { bg: 'rgba(255, 193, 7, 0.7)', border: '#ffc107' },
            'GDP-GAAP': { bg: 'rgba(255, 152, 0, 0.7)', border: '#ff9800' },
            'GDP-UDP': { bg: 'rgba(255, 87, 34, 0.7)', border: '#ff5722' },
            'AFP': { bg: 'rgba(23, 162, 184, 0.7)', border: '#17a2b8' }
        };
        var defaultColor = { bg: 'rgba(108, 117, 125, 0.7)', border: '#6c757d' };

        // Sort: chains grouped together, then by start time
        programs.sort(function(a, b) {
            var chainA = a.advisory_chain_id || a.program_id;
            var chainB = b.advisory_chain_id || b.program_id;
            if (chainA !== chainB) return chainA - chainB;
            return new Date(a.start_utc) - new Date(b.start_utc);
        });

        programs.forEach(function(p) {
            var pType = (p.program_type || 'GS').replace(/-.*/, '');
            var fullType = p.program_type || 'GS';
            var colors = typeColors[fullType] || typeColors[pType] || defaultColor;

            // Dim completed/cancelled/transitioned
            if (['COMPLETED', 'CANCELLED', 'TRANSITIONED'].indexOf(p.status) !== -1) {
                colors = { bg: 'rgba(108, 117, 125, 0.3)', border: '#adb5bd' };
            }

            var label = (p.program_type || 'GS') + ' #' + p.program_id + ' ' + (p.ctl_element || '');
            labels.push(label);
            programIds.push(p.program_id);

            data.push([new Date(p.start_utc).getTime(), new Date(p.end_utc).getTime()]);
            bgColors.push(colors.bg);
            borderColors.push(colors.border);
        });

        if (GDT_TIMELINE_CHART) { GDT_TIMELINE_CHART.destroy(); }

        GDT_TIMELINE_CHART = new Chart(canvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    data: data,
                    backgroundColor: bgColors,
                    borderColor: borderColors,
                    borderWidth: 1,
                    barPercentage: 0.7,
                    categoryPercentage: 0.8
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                var p = programs[ctx.dataIndex];
                                if (!p) return '';
                                var s = formatZuluFromIso(p.start_utc);
                                var e = formatZuluFromIso(p.end_utc);
                                var delay = p.avg_delay_min ? parseFloat(p.avg_delay_min).toFixed(0) + ' min avg' : '';
                                return s + ' - ' + e + (delay ? ' | ' + delay : '') + ' | ' + (p.status || '');
                            }
                        }
                    },
                    // Now-line annotation (if annotation plugin loaded)
                    annotation: typeof Chart !== 'undefined' && Chart.registry && Chart.registry.plugins.get('annotation') ? {
                        annotations: {
                            nowLine: {
                                type: 'line',
                                xMin: now.getTime(),
                                xMax: now.getTime(),
                                borderColor: '#dc3545',
                                borderWidth: 2,
                                borderDash: [4, 4],
                                label: { display: true, content: 'NOW', position: 'start', font: { size: 9 } }
                            }
                        }
                    } : undefined
                },
                scales: {
                    x: {
                        type: 'linear',
                        min: minTime.getTime(),
                        max: maxTime.getTime(),
                        ticks: {
                            callback: function(val) {
                                var d = new Date(val);
                                return String(d.getUTCHours()).padStart(2, '0') + ':' + String(d.getUTCMinutes()).padStart(2, '0') + 'Z';
                            },
                            maxTicksLimit: 12,
                            font: { size: 10 }
                        },
                        title: { display: true, text: PERTII18n.t('gdt.chart.utcTime'), font: { size: 10 } }
                    },
                    y: {
                        ticks: { font: { size: 10 } }
                    }
                },
                onClick: function(evt, elements) {
                    if (elements && elements.length > 0) {
                        var idx = elements[0].index;
                        var pid = programIds[idx];
                        if (pid) loadProgramFromDashboard(pid);
                    }
                }
            }
        });

        // Detect scope conflicts
        detectScopeConflicts(programs);
    }

    function detectScopeConflicts(programs) {
        var conflictsEl = document.getElementById('gdt_timeline_conflicts');
        if (!conflictsEl) return;
        conflictsEl.innerHTML = '';

        var activePrograms = programs.filter(function(p) {
            return p.status === 'ACTIVE' && p.scope_json;
        });

        if (activePrograms.length < 2) return;

        // Extract scope facilities for each program
        var scopeMap = {};
        activePrograms.forEach(function(p) {
            var facilities = [];
            try {
                var scope = typeof p.scope_json === 'string' ? JSON.parse(p.scope_json) : p.scope_json;
                if (scope.origin_centers) {
                    var centers = Array.isArray(scope.origin_centers) ? scope.origin_centers : String(scope.origin_centers).split(/[,\s]+/);
                    facilities = facilities.concat(centers);
                }
                if (scope.arr_center) facilities.push(scope.arr_center);
            } catch(e) {}
            scopeMap[p.program_id] = facilities.map(function(f) { return f.toUpperCase(); });
        });

        // Compare pairs for overlapping facilities
        var conflicts = [];
        for (var i = 0; i < activePrograms.length; i++) {
            for (var j = i + 1; j < activePrograms.length; j++) {
                var pA = activePrograms[i];
                var pB = activePrograms[j];
                var facA = scopeMap[pA.program_id] || [];
                var facB = scopeMap[pB.program_id] || [];
                var shared = facA.filter(function(f) { return facB.indexOf(f) !== -1; });
                if (shared.length > 0) {
                    conflicts.push({
                        a: pA.program_type + ' #' + pA.program_id + ' ' + (pA.ctl_element || ''),
                        b: pB.program_type + ' #' + pB.program_id + ' ' + (pB.ctl_element || ''),
                        facilities: shared
                    });
                }
            }
        }

        if (conflicts.length > 0) {
            var html = '<span class="text-warning"><i class="fas fa-exclamation-triangle mr-1"></i> Shared scope:</span> ';
            conflicts.forEach(function(c) {
                html += '<span class="badge badge-warning mr-1">' + escapeHtml(c.a) + ' &harr; ' + escapeHtml(c.b) +
                    ' (' + c.facilities.join(', ') + ')</span>';
            });
            conflictsEl.innerHTML = html;
        }
    }

    // Expose dashboard functions to global scope (needed by onclick handlers in HTML)
    window.toggleDashboard = toggleDashboard;
    window.toggleTimeline = toggleTimeline;
    window.loadProgramFromDashboard = loadProgramFromDashboard;
    window.resetAndNewProgram = resetAndNewProgram;
    window.dashboardExtend = dashboardExtend;
    window.dashboardRevise = dashboardRevise;
    window.dashboardTransition = dashboardTransition;
    window.dashboardCancel = dashboardCancel;
    window.dashboardDelete = dashboardDelete;
    window.submitExtend = submitExtend;
    window.submitRevise = submitRevise;
    window.submitTransitionPropose = submitTransitionPropose;
    window.submitTransitionActivate = submitTransitionActivate;
    window.dashboardRemodel = dashboardRemodel;
    window.exitWhatIfMode = exitWhatIfMode;

    // ========================================================================
    // Workflow Stepper
    // ========================================================================

    function setWorkflowState(newState) {
        var validStates = ['configure', 'preview', 'model', 'active'];
        if (validStates.indexOf(newState) === -1) return;

        GS_WORKFLOW_STATE = newState;
        updateStepperUI();
        updateWorkflowButtons();
    }

    function updateStepperUI() {
        var steps = ['configure', 'preview', 'model', 'active'];
        var currentIndex = steps.indexOf(GS_WORKFLOW_STATE);

        for (var i = 0; i < steps.length; i++) {
            var stepEl = document.getElementById('gdt_step_' + (i + 1));
            var lineEl = document.getElementById('gdt_step_line_' + (i + 1));

            if (!stepEl) continue;

            stepEl.classList.remove('active', 'completed');

            if (i < currentIndex) {
                stepEl.classList.add('completed');
            } else if (i === currentIndex) {
                stepEl.classList.add('active');
            }

            if (lineEl) {
                lineEl.classList.remove('completed');
                if (i < currentIndex) {
                    lineEl.classList.add('completed');
                }
            }
        }
    }

    function updateWorkflowButtons() {
        // Button IDs that exist in the current UI
        var previewBtn = document.getElementById('gs_preview_btn');
        var simulateBtn = document.getElementById('gs_simulate_btn');
        var submitBtn = document.getElementById('gs_submit_tmi_btn');
        var sendActualBtn = document.getElementById('gs_send_actual_btn');
        var purgeLocalBtn = document.getElementById('gs_purge_local_btn');
        var purgeAllBtn = document.getElementById('gs_purge_all_btn');
        var flightListBtn = document.getElementById('gs_view_flight_list_btn');
        var modelBtn = document.getElementById('gs_open_model_btn');

        // Default: hide everything
        var allBtns = [previewBtn, simulateBtn, submitBtn, sendActualBtn, purgeLocalBtn, purgeAllBtn, flightListBtn, modelBtn];

        switch (GS_WORKFLOW_STATE) {
            case 'configure':
                show(previewBtn);
                hide(simulateBtn); hide(submitBtn); hide(sendActualBtn);
                hide(purgeLocalBtn); hide(purgeAllBtn);
                hide(flightListBtn); hide(modelBtn);
                break;

            case 'preview':
                show(previewBtn); // Allow re-preview
                show(simulateBtn);
                show(sendActualBtn);
                hide(submitBtn);
                hide(purgeLocalBtn); hide(purgeAllBtn);
                show(flightListBtn); show(modelBtn);
                break;

            case 'model':
                hide(previewBtn);
                show(simulateBtn); // Re-model
                show(submitBtn);
                show(sendActualBtn);
                show(purgeLocalBtn);
                show(flightListBtn); show(modelBtn);
                hide(purgeAllBtn);
                break;

            case 'active':
                hide(previewBtn); hide(simulateBtn);
                hide(submitBtn); hide(sendActualBtn);
                show(purgeLocalBtn); show(purgeAllBtn);
                show(flightListBtn); show(modelBtn);
                break;
        }
    }

    function show(el) { if (el) el.style.display = ''; }
    function hide(el) { if (el) el.style.display = 'none'; }

    // Expose stepper to global scope
    window.setWorkflowState = setWorkflowState;

    function deriveBtsCarrierFromCallsign(callsign) {
        if (!callsign) {return null;}
        const cs = String(callsign).toUpperCase();
        const m = cs.match(/^[A-Z]+/);
        if (!m || !m[0]) {return null;}
        const letters = m[0];

        if (letters.length >= 3) {
            const p3 = letters.slice(0, 3);
            if (Object.prototype.hasOwnProperty.call(AIRLINE_CODE_MAP, p3)) {
                return AIRLINE_CODE_MAP[p3];
            }
        }
        if (letters.length >= 2) {
            const p2 = letters.slice(0, 2);
            if (Object.prototype.hasOwnProperty.call(AIRLINE_CODE_MAP, p2)) {
                return AIRLINE_CODE_MAP[p2];
            }
        }
        return null;
    }

    function loadBtsStats() {
        return fetch('assets/data/T_T100D_SEGMENT_US_CARRIER_ONLY.csv', { cache: 'no-cache' })
            .then(function(res) { return res.text(); })
            .then(function(text) {
                GS_BTS_AVG_TIMES = {};
                if (!text) {return;}

                const lines = text.replace(/\r/g, '').split('\n').filter(function(l) { return l.trim().length > 0; });
                if (!lines.length) {return;}

                const header = lines[0].split(',');
                function idx(name) {
                    for (let i = 0; i < header.length; i++) {
                        const col = header[i] ? header[i].replace(/^\uFEFF/, '') : '';
                        if (col === name) {return i;}
                    }
                    return -1;
                }

                const idxCarrier = idx('CARRIER');
                const idxOrigin = idx('ORIGIN');
                const idxDest = idx('DEST');
                const idxAir = idx('AIR_TIME');

                if (idxCarrier === -1 || idxOrigin === -1 || idxDest === -1 || idxAir === -1) {
                    console.warn('BTS T100 header missing expected columns');
                    return;
                }

                const accum = {};

                for (let i = 1; i < lines.length; i++) {
                    const line = lines[i];
                    if (!line.trim()) {continue;}
                    const parts = line.split(',');

                    const get = (idx) => (idx >= 0 && idx < parts.length ? parts[idx].trim().toUpperCase() : '');

                    const carrier = get(idxCarrier);
                    const origin = get(idxOrigin);
                    const dest = get(idxDest);
                    if (!carrier || !origin || !dest) {continue;}

                    const airStr = (idxAir >= 0 && idxAir < parts.length ? parts[idxAir].trim() : '');
                    const air = parseFloat(airStr);
                    if (!isFinite(air) || air <= 0) {continue;}

                    const key = carrier + '_' + origin + '_' + dest;
                    let agg = accum[key];
                    if (!agg) {
                        agg = { sum: 0, count: 0 };
                        accum[key] = agg;
                    }
                    agg.sum += air;
                    agg.count += 1;
                }

                const map = {};
                Object.keys(accum).forEach(function(key) {
                    const agg = accum[key];
                    if (agg.count > 0) {
                        map[key] = agg.sum / agg.count;
                    }
                });
                GS_BTS_AVG_TIMES = map;
                console.log('Loaded BTS T100 segment averages:', Object.keys(GS_BTS_AVG_TIMES).length, 'ODC entries');
            })
            .catch(function(err) {
                console.error('Error loading BTS T100 segment data', err);
            });
    }

    const AIRPORT_COLOR_PALETTE = [
        '#e63946',  // Vibrant red
        '#2563eb',  // Vibrant blue
        '#16a34a',  // Vibrant green
        '#ca8a04',  // Golden yellow - darker for readability
        '#ea580c',  // Vibrant orange
        '#7c3aed',  // Vibrant purple
        '#0891b2',  // Cyan/teal
        '#db2777',  // Vibrant pink
        '#059669',  // Emerald green
        '#be123c',   // Rose red
    ];

    function getValue(id) {
        const el = document.getElementById(id);
        return el ? el.value.trim() : '';
    }

    function parseUtcLocalInput(dtStr) {
        // Treat datetime-local value as UTC without timezone conversion
        // Expect: YYYY-MM-DDTHH:MM or YYYY-MM-DDTHH:MM:SS
        if (!dtStr) {return null;}
        const parts = dtStr.split('T');
        if (parts.length !== 2) {return null;}
        const d = parts[0].split('-');
        const t = parts[1].split(':');
        if (d.length !== 3 || t.length < 2) {return null;}
        return {
            year: d[0],
            month: d[1],
            day: d[2],
            hour: t[0],
            minute: t[1],
        };
    }

    function parseUtcLocalToEpoch(dtStr) {
        const p = parseUtcLocalInput(dtStr);
        if (!p) {return null;}
        const year = parseInt(p.year, 10);
        const month = parseInt(p.month, 10);
        const day = parseInt(p.day, 10);
        const hour = parseInt(p.hour, 10);
        const minute = parseInt(p.minute, 10);
        if (isNaN(year) || isNaN(month) || isNaN(day) ||
            isNaN(hour) || isNaN(minute)) {
            return null;
        }
        // Treat as UTC calendar time
        return Date.UTC(year, month - 1, day, hour, minute, 0);
    }

    function formatZuluFromLocal(dtStr) {
        const p = parseUtcLocalInput(dtStr);
        if (!p) {return 'DD/HHMMZ';}
        const dd = p.day;
        const hh = p.hour;
        const mm = p.minute;
        return dd + '/' + hh + mm + 'Z';
    }

    function formatDdHhMmFromLocal(dtStr) {
        const p = parseUtcLocalInput(dtStr);
        if (!p) {return 'DDHHMM';}
        const dd = p.day;
        const hh = p.hour;
        const mm = p.minute;
        return dd + hh + mm;
    }

    function formatZuluFromEpoch(epochMs) {
        if (epochMs == null || isNaN(epochMs)) {return '';}
        const d = new Date(epochMs);
        const dd = String(d.getUTCDate()).padStart(2, '0');
        const hh = String(d.getUTCHours()).padStart(2, '0');
        const mm = String(d.getUTCMinutes()).padStart(2, '0');
        return dd + '/' + hh + mm + 'Z';
    }
    function formatSqlUtcFromEpoch(epochMs) {
        if (epochMs == null || isNaN(epochMs)) {return '';}
        const d = new Date(epochMs);
        const yyyy = d.getUTCFullYear();
        const mm = String(d.getUTCMonth() + 1).padStart(2, '0');
        const dd = String(d.getUTCDate()).padStart(2, '0');
        const hh = String(d.getUTCHours()).padStart(2, '0');
        const mi = String(d.getUTCMinutes()).padStart(2, '0');
        const ss = String(d.getUTCSeconds()).padStart(2, '0');
        return yyyy + '-' + mm + '-' + dd + ' ' + hh + ':' + mi + ':' + ss;
    }

    function getAdlSnapshotDisplayText() {
        if (!GS_ADL || !(GS_ADL.snapshotUtc instanceof Date) || isNaN(GS_ADL.snapshotUtc.getTime())) {
            return '';
        }
        const d = GS_ADL.snapshotUtc;
        const yyyy = d.getUTCFullYear();
        const mm = String(d.getUTCMonth() + 1).padStart(2, '0');
        const dd = String(d.getUTCDate()).padStart(2, '0');
        const hh = String(d.getUTCHours()).padStart(2, '0');
        const mi = String(d.getUTCMinutes()).padStart(2, '0');
        return mm + '/' + dd + '/' + yyyy + ' ' + hh + mi + 'Z';
    }


    function parseSimtrafficTimeToEpoch(ts) {
        if (!ts) {return null;}
        ts = String(ts).trim();
        if (!ts) {return null;}

        // Normalise ISO "YYYY-MM-DDTHH:MM:SSZ" -> "YYYY-MM-DD HH:MM:SS"
        ts = ts.replace('T', ' ').replace('Z', '').replace('z', '');

        // Now expect "YYYY-MM-DD HH:MM:SS" or "YYYY-MM-DD HH:MM"
        const parts = ts.split(' ');
        if (parts.length < 2) {return null;}
        const d = parts[0].split('-');
        const t = parts[1].split(':');
        if (d.length !== 3 || t.length < 2) {return null;}

        const year   = parseInt(d[0], 10);
        const month  = parseInt(d[1], 10);
        const day    = parseInt(d[2], 10);
        const hour   = parseInt(t[0], 10);
        const minute = parseInt(t[1], 10);
        const second = t.length >= 3 ? parseInt(t[2], 10) : 0;

        if (isNaN(year) || isNaN(month) || isNaN(day) ||
            isNaN(hour) || isNaN(minute) || isNaN(second)) {
            return null;
        }

        // Times are in UTC
        return Date.UTC(year, month - 1, day, hour, minute, second);
    }


    function makeRowIdForCallsign(cs) {
        if (!cs) {return '';}
        return 'gs_row_' + String(cs).toUpperCase().replace(/[^A-Z0-9]/g, '_');
    }


    function parseVatsimDepartureTimeToEpoch(depTimeField) {
        if (!depTimeField) {return null;}
        const s = String(depTimeField).trim();
        if (!s) {return null;}

        let hh, mm;

        function pickBaseDate() {
            let base = null;
            try {
                if (typeof GS_ADL === 'object' && GS_ADL) {
                    // Prefer ADL snapshot time if provided
                    if (GS_ADL.snapshotUtc instanceof Date && !isNaN(GS_ADL.snapshotUtc.getTime())) {
                        base = GS_ADL.snapshotUtc;
                    } else if (GS_ADL.snapshot_utc) {
                        const tmpAdl = new Date(GS_ADL.snapshot_utc);
                        if (!isNaN(tmpAdl.getTime())) {base = tmpAdl;}
                    }
                }
                // If no ADL snapshot, fall back to VATSIM general update timestamp if available
                if ((!base || isNaN(base.getTime())) && typeof GS_VATSIM === 'object' && GS_VATSIM && GS_VATSIM.general && GS_VATSIM.general.update_timestamp) {
                    const tmpVs = new Date(GS_VATSIM.general.update_timestamp);
                    if (!isNaN(tmpVs.getTime())) {base = tmpVs;}
                }
            } catch (e) {
                base = null;
            }
            if (!base || isNaN(base.getTime())) {
                base = new Date(); // fallback: now (UTC)
            }
            return base;
        }


        // Case 1: numeric HHMM or HMM (e.g. "2345", "945")
        if (/^\d{3,4}$/.test(s)) {
            const len = s.length;
            hh = parseInt(s.slice(0, len - 2), 10);
            mm = parseInt(s.slice(len - 2), 10);
            if (isNaN(hh) || isNaN(mm)) {return null;}

            const base = pickBaseDate();
            return Date.UTC(
                base.getUTCFullYear(),
                base.getUTCMonth(),
                base.getUTCDate(),
                hh,
                mm,
                0,
            );
        }

        // Case 2: "HH:MM"
        const parts = s.split(':');
        if (parts.length === 2) {
            hh = parseInt(parts[0], 10);
            mm = parseInt(parts[1], 10);
            if (isNaN(hh) || isNaN(mm)) {return null;}

            const base2 = pickBaseDate();
            return Date.UTC(
                base2.getUTCFullYear(),
                base2.getUTCMonth(),
                base2.getUTCDate(),
                hh,
                mm,
                0,
            );
        }

        // Case 3: already a date-time string
        const d = new Date(s);
        if (isNaN(d.getTime())) {return null;}
        return d.getTime();
    }

    function parseVatsimEnrouteToMinutes(enroute) {
        if (!enroute) {return null;}
        const s = String(enroute).trim();
        if (!s) {return null;}

        let hh, mm;
        // Case 1: numeric HHMM or HMM (e.g. "0120", "945")
        if (/^\d{3,4}$/.test(s)) {
            const len = s.length;
            hh = parseInt(s.slice(0, len - 2), 10);
            mm = parseInt(s.slice(len - 2), 10);
        } else {
            const parts = s.split(':');
            if (parts.length < 2) {return null;}
            hh = parseInt(parts[0], 10);
            mm = parseInt(parts[1], 10);
        }

        if (isNaN(hh) || isNaN(mm)) {return null;}
        return hh * 60 + mm;
    }


    function lookupBtsAvgFlightMinutes(depIcao, arrIcao, callsign) {
        if (!GS_BTS_AVG_TIMES) {return null;}
        const dep = (depIcao || '').toUpperCase();
        const arr = (arrIcao || '').toUpperCase();
        if (!dep || !arr) {return null;}

        const carrier = deriveBtsCarrierFromCallsign(callsign);
        if (!carrier) {return null;}

        const oIata = AIRPORT_IATA_MAP[dep] || '';
        const dIata = AIRPORT_IATA_MAP[arr] || '';
        if (!oIata || !dIata) {return null;}

        const key = carrier + '_' + oIata + '_' + dIata;
        const v = GS_BTS_AVG_TIMES[key];
        if (typeof v === 'number' && !isNaN(v) && v > 0) {
            return v;
        }
        return null;
    }

    function escapeHtml(str) {
        if (!str) {return '';}
        return str
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function wrapAdvisoryLabelValue(label, value) {
        const maxWidth = 68;
        label = (label || '').toString().trim();
        const lines = [];

        if (value == null) {
            value = '';
        }
        value = value.toString().trim();

        // If no value, just return the label as a single line (if present)
        if (!value) {
            if (label) {
                lines.push(label);
            }
            return lines;
        }

        const words = value.split(/\s+/);
        const indent = label ? (label.length + 1) : 0; // +1 for the space after the colon-style label
        let current = label ? (label + ' ') : '';

        words.forEach(function(word) {
            if (!word) {return;}
            if (!current) {
            // New line starting with hanging indent
                current = (indent > 0 ? ' '.repeat(indent) : '') + word;
                return;
            }
            const tentative = current + (current.endsWith(' ') ? '' : ' ') + word;
            if (tentative.length > maxWidth && current.length > 0) {
                lines.push(current);
                current = (indent > 0 ? ' '.repeat(indent) : '') + word;
            } else {
                current = tentative;
            }
        });

        if (current) {
            lines.push(current);
        }
        return lines;
    }


    function buildAdvisory() {
        const advNum = getValue('gs_adv_number') || 'XXX';
        const elemTypeEl = document.getElementById('gs_element_type');
        const elemType = elemTypeEl ? elemTypeEl.value : 'APT';
        const airportsRaw = getValue('gs_airports') || '';
        const depFacilities = getValue('gs_dep_facilities') || 'ALL';
        const carriers = getValue('gs_flt_incl_carrier');
        const acTypeEl = document.getElementById('gs_flt_incl_type');
        const acType = acTypeEl ? acTypeEl.value : 'ALL';
        const probExt = getValue('gs_prob_ext') || '';
        const impacting = getValue('gs_impacting_condition') || '';
        const comments = getValue('gs_comments') || '';

        const startEl = document.getElementById('gs_start');
        const endEl = document.getElementById('gs_end');
        const start = startEl ? startEl.value : '';
        const end = endEl ? endEl.value : '';

        // Keep the time-filter window aligned with the GS period by default
        syncTimeFiltersWithGsPeriod();

        const now = new Date();
        const nowZ = String(now.getUTCHours()).padStart(2, '0') +
               String(now.getUTCMinutes()).padStart(2, '0') + 'Z';

        const gsPeriod = formatZuluFromLocal(start) + ' – ' + formatZuluFromLocal(end);

        // Parse airports and convert ICAO to FAA codes
        const airportTokens = (airportsRaw || '').toUpperCase().split(/[\s,]+/).filter(function(t) { return t.length > 0; });
        const faaAirports = airportTokens.map(function(icao) {
            // Convert ICAO to FAA/IATA (region-aware: handles AK, HI, Canada, etc.)
            if (typeof PERTI !== 'undefined' && PERTI.denormalizeIcao) {
                return AIRPORT_IATA_MAP[icao] || PERTI.denormalizeIcao(icao);
            }
            if (AIRPORT_IATA_MAP && AIRPORT_IATA_MAP[icao]) {
                return AIRPORT_IATA_MAP[icao];
            }
            if (icao.length === 4 && icao.charAt(0) === 'K') {
                return icao.substring(1);
            }
            return icao;
        });

        // Get responsible ARTCCs for the airports
        const responsibleCenters = [];
        const centerSet = {};
        airportTokens.forEach(function(icao) {
            const center = AIRPORT_CENTER_MAP ? AIRPORT_CENTER_MAP[icao] : null;
            if (center && !centerSet[center]) {
                centerSet[center] = true;
                responsibleCenters.push(center);
            }
        });

        // Build CTL Element: just {FAA airports} (no responsible ARTCCs)
        // If user has manually entered a value, use that; otherwise compute from airports
        let ctlElement = getValue('gs_ctl_element');
        if (!ctlElement && faaAirports.length > 0) {
            ctlElement = faaAirports.join('/');
        }
        ctlElement = ctlElement || 'XXX';

        // Note: We don't auto-update the CTL Element textbox to avoid issues with
        // partial input on keyup. The computed value is used for the advisory preview.
        // User can manually fill in the CTL Element field if they want to override.

        // Determine scope tier name from selected options
        let scopeTierName = '';
        const scopeSel = document.getElementById('gs_scope_select');
        if (scopeSel && scopeSel.selectedOptions && scopeSel.selectedOptions.length) {
            const selectedNames = [];
            Array.prototype.forEach.call(scopeSel.selectedOptions, function(opt) {
                if (opt && opt.dataset) {
                    const type = opt.dataset.type;
                    const val = opt.value;
                    if (type === 'tier' || type === 'special') {
                        selectedNames.push(opt.dataset.tierLabel || val);
                    } else if (type === 'fac') {
                        selectedNames.push(opt.dataset.tierLabel || val);
                    } else if (type === 'manual') {
                        selectedNames.push(PERTII18n.t('gdt.scope.manual'));
                    }
                }
            });
            if (selectedNames.length > 0) {
                scopeTierName = selectedNames.join('+');
            }
        }

        // Build DEP FACILITIES line with scope tier prefix
        let depFacilitiesValue = depFacilities;
        if (scopeTierName) {
            depFacilitiesValue = '(' + scopeTierName + ') ' + depFacilities;
        }

        // Flight-inclusion lines
        const fltInclValues = [];
        if (carriers) {
            fltInclValues.push('CARRIERS ' + carriers.toUpperCase());
        }
        if (acType && acType !== 'ALL') {
            fltInclValues.push(acType + ' DEP ONLY');
        }

        // Delay statistics from the current table / time filter
        function readDelaySpan(id) {
            const el = document.getElementById(id);
            return el ? (el.textContent || '').trim() : '';
        }
        const delayTotal = readDelaySpan('gs_delay_total') || '0';
        const delayMax = readDelaySpan('gs_delay_max') || '0';
        const delayAvg = readDelaySpan('gs_delay_avg') || '0';

        // Valid period line (ddhhmm-ddhhmm format, no Z)
        const validStart = formatDdHhMm(start);
        const validEnd = formatDdHhMm(end);
        const validPeriod = validStart + '-' + validEnd;

        // Header date (MM/DD/YYYY based on publish time = now)
        const headerMonth = String(now.getUTCMonth() + 1).padStart(2, '0');
        const headerDay = String(now.getUTCDate()).padStart(2, '0');
        const headerYear = String(now.getUTCFullYear());
        const headerDate = headerMonth + '/' + headerDay + '/' + headerYear;

        // Signature timestamp
        const yy = String(now.getUTCFullYear()).slice(-2);
        const mm = String(now.getUTCMonth() + 1).padStart(2, '0');
        const dd = String(now.getUTCDate()).padStart(2, '0');
        const hh = String(now.getUTCHours()).padStart(2, '0');
        const min = String(now.getUTCMinutes()).padStart(2, '0');
        const signatureLine = yy + '/' + mm + '/' + dd + ' ' + hh + ':' + min;

        let lines = [];

        // Header: {ORG_PREFIX} ADVZY {ADVZY_NUM} {CTL_ELEMENT} MM/DD/YYYY CDM GROUND STOP
        lines.push(AdvisoryConfig.getPrefix() + ' ADVZY ' + advNum + ' ' + ctlElement + ' ' + headerDate + ' CDM GROUND STOP');

        // Standard lines with hanging-indent wrapping at 68 characters
        lines = lines.concat(wrapAdvisoryLabelValue('CTL ELEMENT:', ctlElement));
        lines = lines.concat(wrapAdvisoryLabelValue('ELEMENT TYPE:', elemType));
        lines = lines.concat(wrapAdvisoryLabelValue('ADL TIME:', nowZ));
        lines = lines.concat(wrapAdvisoryLabelValue('GROUND STOP PERIOD:', gsPeriod));

        fltInclValues.forEach(function(v) {
            lines = lines.concat(wrapAdvisoryLabelValue('FLT INCL:', v));
        });

        lines = lines.concat(
            wrapAdvisoryLabelValue('DEP FACILITIES INCLUDED:', depFacilitiesValue),
        );

        // Delay line
        lines = lines.concat(
            wrapAdvisoryLabelValue(
                'NEW TOTAL, MAXIMUM, AVERAGE DELAYS:',
                delayTotal + ' / ' + delayMax + ' / ' + delayAvg,
            ),
        );

        // Always show these lines (blank if empty)
        lines = lines.concat(
            wrapAdvisoryLabelValue('PROBABILITY OF EXTENSION:', probExt),
        );
        lines = lines.concat(
            wrapAdvisoryLabelValue('IMPACTING CONDITION:', impacting),
        );
        lines = lines.concat(
            wrapAdvisoryLabelValue('COMMENTS:', comments),
        );

        // Signature block
        lines.push('');
        lines.push(validPeriod);
        lines.push(signatureLine);

        const pre = document.getElementById('gs_advisory_preview');
        if (pre) {
            pre.textContent = lines.join('\n');
        }
    }

    // Format datetime to ddhhmm (no Z suffix) for valid period
    function formatDdHhMm(localVal) {
        if (!localVal) {return '------';}
        try {
            const d = new Date(localVal);
            if (isNaN(d.getTime())) {return '------';}
            const dd = String(d.getUTCDate()).padStart(2, '0');
            const hh = String(d.getUTCHours()).padStart(2, '0');
            const mm = String(d.getUTCMinutes()).padStart(2, '0');
            return dd + hh + mm;
        } catch (e) {
            return '------';
        }
    }

    // Copy advisory preview to clipboard for Discord
    function copyAdvisoryToClipboard() {
        const pre = document.getElementById('gs_advisory_preview');
        if (!pre) {
            alert(PERTII18n.t('gdt.advisoryPreview.notFound'));
            return;
        }

        const text = pre.textContent || pre.innerText || '';
        if (!text.trim()) {
            alert(PERTII18n.t('gdt.advisoryPreview.empty'));
            return;
        }

        // Wrap in code block for Discord formatting
        const discordText = '```\n' + text + '\n```';

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(discordText).then(function() {
                showCopySuccess(PERTII18n.t('gdt.copy.advisoryCopied'));
            }).catch(function(err) {
                console.error('Clipboard copy failed', err);
                fallbackCopyAdvisory(discordText);
            });
        } else {
            fallbackCopyAdvisory(discordText);
        }
    }

    function fallbackCopyAdvisory(text) {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.left = '-9999px';
        document.body.appendChild(textarea);
        textarea.select();
        try {
            document.execCommand('copy');
            showCopySuccess(PERTII18n.t('gdt.copy.advisoryCopied'));
        } catch (err) {
            alert(PERTII18n.t('gdt.copy.failed'));
        }
        document.body.removeChild(textarea);
    }

    function initGsUtcDefaults() {
        const startEl = document.getElementById('gs_start');
        const endEl = document.getElementById('gs_end');
        if (!startEl || !endEl) {return;}
        if (startEl.value || endEl.value) {return;}

        const now = new Date();
        const start = new Date(now.getTime());
        const end = new Date(now.getTime() + 2 * 60 * 60 * 1000);

        function toUtcLocalValue(d) {
            const y = d.getUTCFullYear();
            const m = String(d.getUTCMonth() + 1).padStart(2, '0');
            const day = String(d.getUTCDate()).padStart(2, '0');
            const hh = String(d.getUTCHours()).padStart(2, '0');
            const mm = String(d.getUTCMinutes()).padStart(2, '0');
            return y + '-' + m + '-' + day + 'T' + hh + ':' + mm;
        }

        startEl.value = toUtcLocalValue(start);
        endEl.value = toUtcLocalValue(end);
    }

    function matchesCarrier(callsign, carrierFilter) {
        if (!carrierFilter) {return true;}
        if (!callsign) {return false;}
        const parts = carrierFilter.toUpperCase().split(/[\s,]+/).filter(function(p) { return p.length > 0; });
        if (!parts.length) {return true;}
        const cs = callsign.toUpperCase();
        for (let i = 0; i < parts.length; i++) {
            if (cs.indexOf(parts[i]) === 0) {
                return true;
            }
        }
        return false;
    }

    function isJetIcao(icao) {
        if (!icao) {return false;}
        icao = icao.toUpperCase();
        const jetPrefixes = ['A', 'B', 'C', 'E', 'F', 'G', 'H', 'I'];
        return jetPrefixes.indexOf(icao.charAt(0)) !== -1;
    }


    function filterFlight(f, cfg, sourceTag) {
        if (!f || !f.flight_plan) {return null;}

        const dep = (f.flight_plan.departure || '').toUpperCase();
        const arr = (f.flight_plan.arrival || '').toUpperCase();
        const alt = f.flight_plan.altitude || '';
        const icao = (f.flight_plan.aircraft || '').toUpperCase();
        const callsign = (f.callsign || '').toUpperCase();
        const route = (f.flight_plan.route || '');

        // Timing fields for ETA estimation
        const depTimeField = f.flight_plan.deptime || null;
        const depEpoch = parseVatsimDepartureTimeToEpoch(depTimeField);
        const eteMinutes = parseVatsimEnrouteToMinutes(f.flight_plan.enroute_time);
        let roughEtaEpoch = null;
        let etaSource = null;

        if (depEpoch != null && eteMinutes != null) {
            // Primary: VATSIM-planned ETE
            roughEtaEpoch = depEpoch + eteMinutes * 60 * 1000;
            etaSource = 'VATSIM';
        } else if (depEpoch != null) {
            // Fallback: BTS average airtime by origin/destination, if available
            const btsMinutes = lookupBtsAvgFlightMinutes(dep, arr, callsign);
            if (btsMinutes != null) {
                roughEtaEpoch = depEpoch + btsMinutes * 60 * 1000;
                etaSource = 'BTS';
            }
        }

        const arrivals = (cfg && Array.isArray(cfg.arrivals)) ? cfg.arrivals : [];
        if (arrivals.length && arrivals.indexOf(arr) === -1) {
            return null;
        }

        // Origin filter: check ARTCC-expanded airports AND/OR FIR ICAO-prefix patterns
        if (!(cfg && cfg.firWildcard)) {
            var originAirports = (cfg && Array.isArray(cfg.originAirports)) ? cfg.originAirports : [];
            var firPatterns = (cfg && Array.isArray(cfg.firPatterns)) ? cfg.firPatterns : [];
            if (originAirports.length || firPatterns.length) {
                var originMatch = originAirports.length > 0 && originAirports.indexOf(dep) !== -1;
                if (!originMatch && firPatterns.length > 0) {
                    for (var pi = 0; pi < firPatterns.length; pi++) {
                        if (dep.indexOf(firPatterns[pi]) === 0) { originMatch = true; break; }
                    }
                }
                if (!originMatch) { return null; }
            }
        }

        const fltInclCarrier = cfg ? cfg.carriers : null;
        if (!matchesCarrier(callsign, fltInclCarrier)) {
            return null;
        }

        const acType = cfg && cfg.acType ? cfg.acType : 'ALL';
        if (acType === 'JET' && !isJetIcao(icao)) {
            return null;
        }
        if (acType === 'PROP' && isJetIcao(icao)) {
            return null;
        }


        // GS eligibility for VATSIM data:
        // - PREFILE: Always eligible (not yet connected, pre-departure)
        // - PILOT: Unknown without ADL phase data; default to NOT eligible
        //   (ADL augmentation will set correct gsFlag based on actual phase)
        const vatsimGsFlag = (sourceTag === 'PREFILE') ? 1 : 0;

        return {
            callsign: callsign,
            dep: dep,
            arr: arr,
            alt: alt,
            aircraft: icao,
            status: (sourceTag || '').toUpperCase(),
            source: 'VATSIM',
            route: route,
            depEpoch: depEpoch,
            eteMinutes: eteMinutes,
            roughEtaEpoch: roughEtaEpoch,
            etaSource: etaSource,
            edctEpoch: null,
            tkofEpoch: null,
            mftEpoch: null,
            vtEpoch: null,
            etaPrefix: etaSource,
            // ETD-related fields (for display)
            etdEpoch: depEpoch,
            etdPrefix: etaSource || 'VATSIM',
            // Flight status / FSM (from data)
            flightStatus: (sourceTag || '').toUpperCase(),
            // GS eligibility (will be overridden by ADL if matched)
            gsFlag: vatsimGsFlag,
        };

    }

    function filterAdlFlight(f, cfg) {
        if (!f) {return null;}

        // Try multiple common field-name patterns to be flexible with the ADL API
        // and tmi_flight_control table (which uses dep_airport, arr_airport)
        const callsign = (f.callsign || f.CALLSIGN || '').toUpperCase();
        const dep = (f.fp_dept_icao || f.dep_airport || f.orig || f.dep_icao || f.dep || f.departure || f.origin || '').toUpperCase();
        const arr = (f.fp_dest_icao || f.arr_airport || f.dest || f.arr_icao || f.arr || f.arrival || f.destination || '').toUpperCase();
        const alt = f.fp_altitude_ft || f.filed_altitude || f.altitude || f.alt || '';
        const icao = (f.aircraft_icao || f.aircraft_type || f.aircraft || f.acft || '').toUpperCase();
        const route = f.fp_route || f.route || f.route_string || f.filed_route || '';

        // Status/source from ADL, with sane defaults
        let status = (f.status || f.adl_status || '').toUpperCase();
        if (!status) {
            status = f.is_active ? 'ACTIVE' : 'PREFILE';
        }
        const source = (f.source || 'ADL').toUpperCase();

        // Flight phase from ADL if available
        let flightStatus = (f.phase || f.PHASE || '').toUpperCase();
        if (!flightStatus) {
            flightStatus = status;
        }

        // GS eligibility flag from ADL (or computed from phase)
        // Pre-departure phases eligible for TMI control: prefile, taxiing, scheduled
        // Airborne/completed phases NOT eligible: departed, enroute, descending, arrived, disconnected
        const rawGsFlag = (typeof f.gs_flag !== 'undefined'
            ? f.gs_flag
            : (typeof f.GS_FLAG !== 'undefined' ? f.GS_FLAG : null));
        let gsFlag = 0;
        if (rawGsFlag === true ||
    rawGsFlag === 1 ||
    rawGsFlag === '1' ||
    rawGsFlag === 'true' ||
    rawGsFlag === 'TRUE') {
            gsFlag = 1;
        } else if (rawGsFlag === null || typeof rawGsFlag === 'undefined') {
            // Fallback: compute GS eligibility from phase if API didn't provide gs_flag
            // Only pre-departure flights can receive EDCTs
            const eligiblePhases = ['PREFILE', 'TAXIING', 'SCHEDULED', 'P', 'T', 'S'];
            if (eligiblePhases.indexOf(flightStatus) !== -1) {
                gsFlag = 1;
            }
        }

        // Filed departure epoch
        // NOTE: Database stores epochs as Unix seconds, JavaScript uses milliseconds
        let depEpoch = null;
        if (typeof f.filed_dep_epoch === 'number') {
            // Convert seconds to milliseconds if epoch is in seconds (before year 2100)
            depEpoch = f.filed_dep_epoch < 4102444800 ? f.filed_dep_epoch * 1000 : f.filed_dep_epoch;
        } else if (f.filed_dep_utc || f.dep_utc || f.planned_dep_utc ||
                   f.estimated_dep_utc || f.etd_runway_utc || f.etd_utc) {
            depEpoch = parseSimtrafficTimeToEpoch(
                f.filed_dep_utc || f.dep_utc || f.planned_dep_utc ||
                f.estimated_dep_utc || f.etd_runway_utc || f.etd_utc,
            );
        } else if (f.deptime) {
            depEpoch = parseVatsimDepartureTimeToEpoch(f.deptime);
        }


        // ETD epoch and prefix (if ADL provides explicit fields)
        // NOTE: Check both suffixed (_utc) and non-suffixed field names for API compatibility
        // NOTE: Database stores epochs as Unix seconds, JavaScript uses milliseconds
        let etdEpoch = null;
        if (typeof f.etd_epoch === 'number') {
            // Convert seconds to milliseconds if epoch is in seconds (before year 2100)
            etdEpoch = f.etd_epoch < 4102444800 ? f.etd_epoch * 1000 : f.etd_epoch;
        } else if (f.etd_utc || f.etd || f.etd_runway_utc || f.estimated_dep_utc) {
            etdEpoch = parseSimtrafficTimeToEpoch(
                f.etd_utc || f.etd || f.etd_runway_utc || f.estimated_dep_utc,
            );
        } else {
            etdEpoch = depEpoch;
        }

        let etdPrefix = f.etd_prefix || f.dep_prefix || f.etd_src || null;
        if (!etdPrefix && source) {
            etdPrefix = source;
        }

        // Enroute time in minutes
        let eteMinutes = null;
        if (typeof f.enroute_minutes === 'number') {
            eteMinutes = f.enroute_minutes;
        } else if (typeof f.ete_minutes === 'number') {
            eteMinutes = f.ete_minutes;
        } else if (f.enroute_time) {
            eteMinutes = parseVatsimEnrouteToMinutes(f.enroute_time);
        }

        // Best-guess ETA from ADL (trajectory, SimTraffic, etc.)
        // NOTE: Check both suffixed (_utc) and non-suffixed field names for API compatibility
        // NOTE: Database stores epochs as Unix seconds, JavaScript uses milliseconds
        const etaPrefix = f.eta_prefix || f.eta_src || null;
        let etaEpoch = null;
        if (typeof f.eta_epoch === 'number') {
            // Convert seconds to milliseconds if epoch is in seconds (before year 2100)
            etaEpoch = f.eta_epoch < 4102444800 ? f.eta_epoch * 1000 : f.eta_epoch;
        } else if (f.eta_best_utc || f.eta_utc || f.eta ||
                   f.eta_runway_utc || f.cta_utc || f.estimated_arr_utc) {
            etaEpoch = parseSimtrafficTimeToEpoch(
                f.eta_best_utc || f.eta_utc || f.eta ||
                f.eta_runway_utc || f.cta_utc || f.estimated_arr_utc,
            );
        } else if (depEpoch != null && eteMinutes != null) {
            etaEpoch = depEpoch + eteMinutes * 60 * 1000;
        }

        // Additional timing fields if ADL already carries them
        // NOTE: Database stores epochs as Unix seconds, JavaScript uses milliseconds
        let edctEpoch = null;
        if (typeof f.edct_epoch === 'number') {
            // Convert seconds to milliseconds if epoch is in seconds (before year 2100)
            edctEpoch = f.edct_epoch < 4102444800 ? f.edct_epoch * 1000 : f.edct_epoch;
        } else if (typeof f.ctd_epoch === 'number') {
            // CTD epoch from tmi_flight_control (GS simulation result)
            edctEpoch = f.ctd_epoch < 4102444800 ? f.ctd_epoch * 1000 : f.ctd_epoch;
        } else if (f.edct_utc || f.ctd_utc) {
            // Use edct_utc if present, otherwise fall back to CTD (ctd_utc)
            edctEpoch = parseSimtrafficTimeToEpoch(f.edct_utc || f.ctd_utc);
        }

        let tkofEpoch = null;
        if (typeof f.takeoff_epoch === 'number') {
            // Convert seconds to milliseconds if epoch is in seconds (before year 2100)
            tkofEpoch = f.takeoff_epoch < 4102444800 ? f.takeoff_epoch * 1000 : f.takeoff_epoch;
        } else if (f.takeoff_utc || f.offblock_utc || f.wheels_off_utc) {
            tkofEpoch = parseSimtrafficTimeToEpoch(
                f.takeoff_utc || f.offblock_utc || f.wheels_off_utc,
            );
        }

        let mftEpoch = null;
        if (typeof f.mft_epoch === 'number') {
            // Convert seconds to milliseconds if epoch is in seconds (before year 2100)
            mftEpoch = f.mft_epoch < 4102444800 ? f.mft_epoch * 1000 : f.mft_epoch;
        } else if (f.mft_utc || f.eta_mf_utc) {
            mftEpoch = parseSimtrafficTimeToEpoch(f.mft_utc || f.eta_mf_utc);
        }

        let vtEpoch = null;
        if (typeof f.vt_epoch === 'number') {
            // Convert seconds to milliseconds if epoch is in seconds (before year 2100)
            vtEpoch = f.vt_epoch < 4102444800 ? f.vt_epoch * 1000 : f.vt_epoch;
        } else if (f.vt_utc || f.vertex_utc) {
            vtEpoch = parseSimtrafficTimeToEpoch(f.vt_utc || f.vertex_utc);
        }

        // Apply the same filters used for the VATSIM feed
        const arrivals = (cfg && Array.isArray(cfg.arrivals)) ? cfg.arrivals : [];
        if (arrivals.length && arrivals.indexOf(arr) === -1) {
            return null;
        }

        // Origin filter: check ARTCC-expanded airports AND/OR FIR ICAO-prefix patterns
        if (!(cfg && cfg.firWildcard)) {
            var originAirports = (cfg && Array.isArray(cfg.originAirports)) ? cfg.originAirports : [];
            var firPatterns = (cfg && Array.isArray(cfg.firPatterns)) ? cfg.firPatterns : [];
            if (originAirports.length || firPatterns.length) {
                var originMatch = originAirports.length > 0 && originAirports.indexOf(dep) !== -1;
                if (!originMatch && firPatterns.length > 0) {
                    for (var pi = 0; pi < firPatterns.length; pi++) {
                        if (dep.indexOf(firPatterns[pi]) === 0) { originMatch = true; break; }
                    }
                }
                if (!originMatch) { return null; }
            }
        }

        const fltInclCarrier = cfg ? cfg.carriers : null;
        if (!matchesCarrier(callsign, fltInclCarrier)) {
            return null;
        }

        const acType = cfg && cfg.acType ? cfg.acType : 'ALL';
        if (acType === 'JET' && !isJetIcao(icao)) {
            return null;
        }
        if (acType === 'PROP' && isJetIcao(icao)) {
            return null;
        }

        return {
            callsign: callsign,
            dep: dep,
            arr: arr,
            alt: alt,
            aircraft: icao,
            status: status,
            source: source,
            route: route,
            depEpoch: depEpoch,
            eteMinutes: eteMinutes,
            roughEtaEpoch: etaEpoch,
            etaSource: etaPrefix || 'ADL',
            edctEpoch: edctEpoch,
            tkofEpoch: tkofEpoch,
            mftEpoch: mftEpoch,
            vtEpoch: vtEpoch,
            etaPrefix: etaPrefix || null,
            etdEpoch: etdEpoch,
            etdPrefix: etdPrefix || null,
            flightStatus: flightStatus,
            gsFlag: gsFlag,
            dep_artcc: (f.dep_artcc || f.fp_dept_artcc || f.dep_center || '').toUpperCase(),
            arr_artcc: (f.arr_artcc || f.fp_dest_artcc || f.arr_center || '').toUpperCase(),
        };
    }
    function augmentRowsWithAdl(rows) {
        if (!rows || !rows.length) {return;}
        if (!GS_ADL || !Array.isArray(GS_ADL.flights) || !GS_ADL.flights.length) {return;}

        // Build an index of ADL flights by callsign the first time
        if (!GS_ADL._indexByCallsign) {
            const index = {};
            GS_ADL.flights.forEach(function(f) {
                if (!f) {return;}
                const cs = (f.callsign || f.CALLSIGN || '').toUpperCase();
                if (!cs) {return;}
                if (!index[cs]) {index[cs] = [];}
                index[cs].push(f);
            });
            GS_ADL._indexByCallsign = index;
        }

        const indexByCs = GS_ADL._indexByCallsign;

        rows.forEach(function(r) {
            if (!r || !r.callsign) {return;}
            const cs = String(r.callsign).toUpperCase();
            const bucket = indexByCs[cs];
            if (!bucket || !bucket.length) {return;}

            const dep = (r.dep || '').toUpperCase();
            const arr = (r.arr || '').toUpperCase();
            let best = null;

            // Prefer exact dep/arr match
            for (let i = 0; i < bucket.length; i++) {
                const f = bucket[i];
                const fDep = (f.dep_icao || f.dep || f.departure || f.origin || '').toUpperCase();
                const fArr = (f.arr_icao || f.arr || f.arrival || f.destination || '').toUpperCase();
                if (fDep === dep && fArr === arr) {
                    best = f;
                    break;
                }
            }
            if (!best) {
                best = bucket[0];
            }
            if (!best) {return;}

            const adlRow = filterAdlFlight(best, {
                arrivals: [],
                originAirports: [],
                carriers: null,
                acType: 'ALL',
            });
            if (!adlRow) {return;}

            // Keep a reference to the underlying ADL row so we can show
            // TFMS-style Flight Info / Flight Detail popups later.
            r._adl = { raw: best, filtered: adlRow };

            // Overlay timing and status fields from ADL when available
            if (adlRow.depEpoch != null && !isNaN(adlRow.depEpoch)) {
                r.depEpoch = adlRow.depEpoch;
            }
            if (adlRow.etdEpoch != null && !isNaN(adlRow.etdEpoch)) {
                r.etdEpoch = adlRow.etdEpoch;
            }
            if (adlRow.roughEtaEpoch != null && !isNaN(adlRow.roughEtaEpoch)) {
                r.roughEtaEpoch = adlRow.roughEtaEpoch;
                r.etaSource = adlRow.etaSource;
                r.etaPrefix = adlRow.etaPrefix || adlRow.etaSource || r.etaPrefix;
            }
            if (adlRow.etdPrefix) {
                r.etdPrefix = adlRow.etdPrefix;
            }
            if (adlRow.flightStatus) {
                r.flightStatus = adlRow.flightStatus;
            }
            if (typeof adlRow.gsFlag !== 'undefined') {
                r.gsFlag = adlRow.gsFlag;
            }
            if (adlRow.edctEpoch != null && !isNaN(adlRow.edctEpoch)) {
                r.edctEpoch = adlRow.edctEpoch;
            }
            if (adlRow.tkofEpoch != null && !isNaN(adlRow.tkofEpoch)) {
                r.tkofEpoch = adlRow.tkofEpoch;
            }
            if (adlRow.mftEpoch != null && !isNaN(adlRow.mftEpoch)) {
                r.mftEpoch = adlRow.mftEpoch;
            }
            if (adlRow.vtEpoch != null && !isNaN(adlRow.vtEpoch)) {
                r.vtEpoch = adlRow.vtEpoch;
            }
        });
    }


    function buildAirportColorMap(airports) {
        const map = {};
        airports.forEach(function(a, idx) {
            const color = AIRPORT_COLOR_PALETTE[idx % AIRPORT_COLOR_PALETTE.length];
            map[a] = color;
        });
        return map;
    }

    function updateAirportsLegendAndInput(airports, airportColors) {
        const legend = document.getElementById('gs_airports_legend');
        const input = document.getElementById('gs_airports');
        if (!legend || !input) {return;}

        if (!airports.length) {
            legend.innerHTML = '';
            input.style.color = '';
            input.style.borderColor = '';
            input.style.boxShadow = '';
            return;
        }

        let html = '';
        airports.forEach(function(a) {
            const color = airportColors[a] || '#dee2e6';
            html += '<span class="tmi-airport-badge" style="background-color:' + color + ';">' +
                escapeHtml(a) + '</span>';
        });
        legend.innerHTML = html;

        if (airports.length === 1) {
            const c = airportColors[airports[0]] || '#4dabf7';
            input.style.color = c;
            input.style.borderColor = c;
            input.style.boxShadow = '0 0 0 0.1rem ' + c + '40';
        } else {
            input.style.color = '';
            input.style.borderColor = '';
            input.style.boxShadow = '';
        }
    }


    function renderFlightsFromAdl(cfg, airportColors, updatedLbl, tbody) {
        let rows = [];

        // Base: VATSIM feed (pilots + prefiles)
        const data = GS_VATSIM || (GS_ADL && GS_ADL.vatsim) || {};
        (data.pilots || []).forEach(function(p) {
            const r = filterFlight(p, cfg, 'PILOT');
            if (r) {rows.push(r);}
        });
        (data.prefiles || []).forEach(function(p) {
            const r = filterFlight(p, cfg, 'PREFILE');
            if (r) {rows.push(r);}
        });

        // Augment timing information with ADL when available
        const hasAdlData = GS_ADL && Array.isArray(GS_ADL.flights) && GS_ADL.flights.length;
        if (hasAdlData) {
            augmentRowsWithAdl(rows);
        }

        // Filter to keep only GS-eligible flights (gs_flag = 1) unless showing all
        // Eligibility is determined by flight phase:
        //   - Eligible: prefile, taxiing, scheduled (pre-departure)
        //   - NOT Eligible: departed, enroute, descending, arrived, disconnected
        //
        // When ADL data is available: gsFlag comes from ADL (computed from phase)
        // When ADL data is NOT available: gsFlag defaults from VATSIM sourceTag
        //   - PREFILE = eligible (gsFlag=1)
        //   - PILOT = NOT eligible without ADL phase confirmation (gsFlag=0)

        // Add exemption reason for each flight
        rows.forEach(function(r) {
            if (r.gsFlag === 1) {
                r.exemptReason = null; // Eligible, no exemption
            } else {
            // Determine exemption reason based on flight phase
                const phase = (r.flightStatus || '').toUpperCase();
                switch (phase) {
                    case 'DEPARTED':
                        r.exemptReason = 'DEP'; // Already departed
                        break;
                    case 'ENROUTE':
                        r.exemptReason = 'ENR'; // Enroute/airborne
                        break;
                    case 'DESCENDING':
                        r.exemptReason = 'DESC'; // Descending to destination
                        break;
                    case 'ARRIVED':
                        r.exemptReason = 'ARR'; // Already arrived
                        break;
                    case 'DISCONNECTED':
                        r.exemptReason = 'DISC'; // Pilot disconnected
                        break;
                    default:
                        r.exemptReason = 'AIR'; // Generic airborne/ineligible
                }
            }
        });

        // Filter based on display mode
        if (!GS_SHOW_ALL_FLIGHTS) {
            rows = rows.filter(function(r) {
                return r.gsFlag === 1;
            });
        }

        rows.sort(function(a, b) {
            const aEta = (a.roughEtaEpoch != null && !isNaN(a.roughEtaEpoch)) ? a.roughEtaEpoch : Number.MAX_SAFE_INTEGER;
            const bEta = (b.roughEtaEpoch != null && !isNaN(b.roughEtaEpoch)) ? b.roughEtaEpoch : Number.MAX_SAFE_INTEGER;
            if (aEta !== bEta) {
                return aEta - bEta;
            }
            if (a.status === b.status) {
                return a.callsign.localeCompare(b.callsign);
            }
            return a.status === 'ACTIVE' ? -1 : 1;
        });

        // Update table header to show/hide exemption column
        const thead = tbody.closest('table').querySelector('thead');
        if (thead) {
            const headerRow = thead.querySelector('tr');
            const exemptHeader = headerRow.querySelector('.gs-exempt-header');
            if (GS_SHOW_ALL_FLIGHTS) {
                if (!exemptHeader) {
                    const th = document.createElement('th');
                    th.className = 'gs-exempt-header';
                    th.textContent = PERTII18n.t('gdt.table.exempt');
                    th.title = PERTII18n.t('gdt.table.exemptTooltip');
                    headerRow.appendChild(th);
                }
            } else {
                if (exemptHeader) {
                    exemptHeader.remove();
                }
            }
        }

        // Count eligible vs exempt for display
        const eligibleCount = rows.filter(function(r) { return r.gsFlag === 1; }).length;
        const exemptCount = rows.filter(function(r) { return r.gsFlag !== 1; }).length;

        // Update flight count display
        const countLabel = document.getElementById('gs_flight_count_label');
        if (countLabel) {
            if (GS_SHOW_ALL_FLIGHTS) {
                countLabel.innerHTML = '<span class="text-success">' + PERTII18n.t('gdt.table.eligibleCount', { count: eligibleCount }) + '</span> + <span class="text-muted">' + PERTII18n.t('gdt.table.exemptCount', { count: exemptCount }) + '</span>';
            } else {
                countLabel.textContent = PERTII18n.t('gdt.table.eligibleFlights', { count: eligibleCount });
            }
        }

        if (!rows.length) {
            const colSpan = GS_SHOW_ALL_FLIGHTS ? 9 : 8;
            tbody.innerHTML = '<tr><td colspan="' + colSpan + '" class="text-muted text-center py-3">' + PERTII18n.t('gdt.table.noFlightsMatching') + '</td></tr>';
        } else {
            GS_FLIGHT_ROW_INDEX = {};
            let html = '';
            rows.forEach(function(r) {
                const color = airportColors[r.arr] || '';
                const arrStyle = color ? ' style="color:' + color + '; font-weight:600;"' : '';
                const rowId = makeRowIdForCallsign(r.callsign || '');
                GS_FLIGHT_ROW_INDEX[rowId] = r;

                const depEpoch = (r.depEpoch != null && !isNaN(r.depEpoch)) ? r.depEpoch : '';
                const etaEpoch = (r.roughEtaEpoch != null && !isNaN(r.roughEtaEpoch)) ? r.roughEtaEpoch : '';
                const edctEpoch = (r.edctEpoch != null && !isNaN(r.edctEpoch)) ? r.edctEpoch : '';
                const tkofEpoch = (r.tkofEpoch != null && !isNaN(r.tkofEpoch)) ? r.tkofEpoch : '';
                const mftEpoch = (r.mftEpoch != null && !isNaN(r.mftEpoch)) ? r.mftEpoch : '';
                const vtEpoch = (r.vtEpoch != null && !isNaN(r.vtEpoch)) ? r.vtEpoch : '';

                // ETD epoch (use explicit ETD if present, otherwise filed dep)
                const etdEpoch = (r.etdEpoch != null && !isNaN(r.etdEpoch)) ? r.etdEpoch : depEpoch;

                const filedAttr = depEpoch !== '' ? String(depEpoch) : '';
                const etaAttr = etaEpoch !== '' ? String(etaEpoch) : '';
                const edctAttr = edctEpoch !== '' ? String(edctEpoch) : '';
                const tkAttr = tkofEpoch !== '' ? String(tkofEpoch) : '';
                const mftAttr = mftEpoch !== '' ? String(mftEpoch) : '';
                const vtAttr = vtEpoch !== '' ? String(vtEpoch) : '';
                const eteAttr = (typeof r.eteMinutes === 'number' && !isNaN(r.eteMinutes)) ? String(r.eteMinutes) : '';

                const trData =
                ' id="' + rowId + '"' +
                ' data-route="' + escapeHtml(r.route || '') + '"' +
                ' data-callsign="' + escapeHtml(r.callsign || '') + '"' +
                ' data-edct-epoch="' + edctAttr + '"' +
                ' data-eta-epoch="' + etaAttr + '"' +
                ' data-etd-epoch="' + (etdEpoch !== '' ? String(etdEpoch) : '') + '"' +
                ' data-takeoff-epoch="' + tkAttr + '"' +
                ' data-filed-dep-epoch="' + filedAttr + '"' +
                ' data-mft-epoch="' + mftAttr + '"' +
                ' data-vt-epoch="' + vtAttr + '"' + ' data-ete-minutes="' + eteAttr + '"';

                let etdText = '';
                if (etdEpoch !== '') {
                    const baseEtd = formatZuluFromEpoch(etdEpoch);
                    etdText = r.etdPrefix ? (r.etdPrefix + ' ' + baseEtd) : baseEtd;
                }

                let etaText = '';
                if (etaEpoch !== '') {
                    const baseEta = formatZuluFromEpoch(etaEpoch);
                    etaText = r.etaPrefix ? (r.etaPrefix + ' ' + baseEta) : baseEta;
                }

                const edctText = edctEpoch !== '' ? formatZuluFromEpoch(edctEpoch) : '';

                // Departing center (ARTCC of origin airport)
                const depUpper = (r.dep || '').toUpperCase();
                let depCenter = r.dep_artcc || (depUpper ? (AIRPORT_CENTER_MAP[depUpper] || '') : '');

                // Build status text from control type, delay status, or flight status
                let statusText = '';
                if (r._adl && r._adl.raw) {
                    statusText = r._adl.raw.ctl_type || r._adl.raw.delay_status || '';
                }
                if (!statusText) {
                    statusText = r.flightStatus || r.status || '';
                }

                // Row styling for exempt flights
                let rowClass = '';
                let exemptCell = '';
                if (GS_SHOW_ALL_FLIGHTS) {
                    if (r.gsFlag !== 1) {
                        rowClass = ' class="table-secondary text-muted"';
                        const reasonTitle = {
                            'DEP': PERTII18n.t('gdt.exempt.departed'),
                            'ENR': PERTII18n.t('gdt.exempt.enroute'),
                            'DESC': PERTII18n.t('gdt.exempt.descending'),
                            'ARR': PERTII18n.t('gdt.exempt.arrived'),
                            'DISC': PERTII18n.t('gdt.exempt.disconnected'),
                            'AIR': PERTII18n.t('gdt.exempt.airborne'),
                        };
                        exemptCell = '<td><span class="badge badge-secondary" title="' + (reasonTitle[r.exemptReason] || PERTII18n.t('tmi.exempt')) + '">' + (r.exemptReason || 'EX') + '</span></td>';
                    } else {
                        exemptCell = '<td></td>'; // Empty cell for eligible flights
                    }
                }

                html += '<tr' + rowClass + trData + '>' +
    '<td><strong>' + (r.callsign || '') + '</strong></td>' +  // ACID
    '<td class="gs_etd_cell">' + etdText + '</td>' +          // ETD
    '<td class="gs_edct_cell">' + edctText + '</td>' +        // CTD
    '<td class="gs_eta_cell"' + arrStyle + '>' + etaText + '</td>' + // ETA
    '<td>' + depCenter + '</td>' +                            // DCTR
    '<td>' + (r.dep || '') + '</td>' +                        // ORIG
    '<td' + arrStyle + '>' + (r.arr || '') + '</td>' +        // DEST
    '<td>' + statusText + '</td>' +                           // STATUS
    exemptCell +                                              // EXEMPT (only when showing all)
    '</tr>';
            });
            tbody.innerHTML = html;
        }

        // Allow SimTraffic to refine EDCT/ETA/MFT/VT if available
        enrichFlightsWithSimTraffic(rows);
        applyTimeFilterToTable();

        if (updatedLbl) {
            let labelTime = null;
            if (GS_ADL && GS_ADL.snapshotUtc instanceof Date && !isNaN(GS_ADL.snapshotUtc.getTime())) {
                labelTime = GS_ADL.snapshotUtc;
            } else if (GS_VATSIM && GS_VATSIM.general && GS_VATSIM.general.update_timestamp) {
                const tmpLbl = new Date(GS_VATSIM.general.update_timestamp);
                if (!isNaN(tmpLbl.getTime())) {labelTime = tmpLbl;}
            }
            if (!labelTime) {
                labelTime = new Date();
            }
            updatedLbl.textContent = PERTII18n.t('gdt.status.updated', { time: labelTime.toUTCString() });
        }
    }


    function loadVatsimFlightsForCurrentGs() {
        const arrivalTokens = getValue('gs_airports').toUpperCase().split(/\s+/).filter(function(x) { return x.length > 0; });
        const originAirportTokens = getValue('gs_origin_airports').toUpperCase().split(/\s+/).filter(function(x) { return x.length > 0; });
        const depFacilityTokens = getValue('gs_dep_facilities').toUpperCase().split(/\s+/).filter(function(x) { return x.length > 0 && x !== 'ALL'; });
        const carriers = getValue('gs_flt_incl_carrier');
        const acTypeEl = document.getElementById('gs_flt_incl_type');
        const acType = acTypeEl ? acTypeEl.value : 'ALL';

        const tbody = document.getElementById('gs_flight_table_body');
        const updatedLbl = document.getElementById('gs_flights_updated');

        if (!tbody) {
            return;
        }

        // Separate FIR: ICAO-prefix patterns from ARTCC codes
        // FIR:ED means departures from airports starting with "ED" (Germany)
        // FIR: alone (empty prefix) means all origins (wildcard)
        var firPatterns = [];
        var firWildcard = false;
        var artccTokens = [];
        depFacilityTokens.forEach(function(tok) {
            if (tok.indexOf('FIR:') === 0) {
                var prefix = tok.substring(4);
                if (prefix === '' || prefix === '*') {
                    firWildcard = true;
                } else {
                    firPatterns.push(prefix);
                }
            } else {
                artccTokens.push(tok);
            }
        });

        const arrivalsExpanded = expandAirportTokensWithFacilities(arrivalTokens);
        const originExpanded = expandAirportTokensWithFacilities(originAirportTokens);

        // Only expand ARTCC tokens (not FIR patterns) into airport lists
        if (!firWildcard) {
            var depFacExpanded = expandAirportTokensWithFacilities(artccTokens);
            depFacExpanded.forEach(function(a) {
                if (originExpanded.indexOf(a) === -1) {
                    originExpanded.push(a);
                }
            });
        }
        // When firWildcard is true, leave originExpanded from explicit origin_airports only
        // (empty = no origin filter = show all departures)

        const airportColors = buildAirportColorMap(arrivalsExpanded);
        updateAirportsLegendAndInput(arrivalsExpanded, airportColors);

        if (!arrivalsExpanded.length) {
            tbody.innerHTML = '<tr><td colspan="8">' + PERTII18n.t('gdt.validation.enterArrivalAirports') + '</td></tr>';
            if (updatedLbl) {updatedLbl.textContent = '';}
            renderSummaryTable('gs_counts_origin_center', {});
            renderSummaryTable('gs_counts_dest_center', {});
            renderSummaryTable('gs_counts_origin_ap', {});
            renderSummaryTable('gs_counts_dest_ap', {});
            renderSummaryTable('gs_counts_carrier', {});
            return;
        }

        tbody.innerHTML = '<tr><td colspan="8">' + PERTII18n.t('gdt.status.loadingFlights') + '</td></tr>';
        if (updatedLbl) {updatedLbl.textContent = '';}

        const cfg = {
            arrivals: arrivalsExpanded,
            originAirports: originExpanded,
            firPatterns: firPatterns,    // ICAO prefix patterns (e.g., ["ED", "EG"])
            firWildcard: firWildcard,    // true = all origins (no origin filter)
            carriers: carriers,
            acType: acType,
        };

        // Load VATSIM as the primary flight list; ADL is used to augment timing
        Promise.all([
            ensureVatsimData(),
            refreshAdl().catch(function(err) {
                console.error('ADL load failed (will proceed with VATSIM only)', err);
                return null;
            }),
        ]).then(function() {
            renderFlightsFromAdl(cfg, airportColors, updatedLbl, tbody);
        }).catch(function(err) {
            console.error('Error loading VATSIM data', err);
            tbody.innerHTML = '<tr><td colspan="8" class="text-danger">' + PERTII18n.t('gdt.status.errorLoadingFlights') + ' ' +
            (err && err.message ? err.message : '') + '</td></tr>';
            if (updatedLbl) {updatedLbl.textContent = PERTII18n.t('common.error');}
            summarizeFlights([]);
        });
    }


    function resetGsForm() {
        // Reset program state
        GS_CURRENT_PROGRAM_ID = null;
        GS_CURRENT_PROGRAM_STATUS = null;
        GS_SIMULATION_READY = false;
        setGsTableMode('LIVE');
        setSendActualEnabled(false, PERTII18n.t('gdt.status.createNewProgram'));

        const ids = [
            'gs_name', 'gs_ctl_element', 'gs_airports', 'gs_origin_centers',
            'gs_origin_airports', 'gs_flt_incl_carrier', 'gs_dep_facilities',
            'gs_comments', 'gs_prob_ext', 'gs_impacting_condition',
            'gs_adv_number', 'gs_start', 'gs_end',
            // Exemption text fields
            'gs_exempt_orig_airports', 'gs_exempt_orig_tracons', 'gs_exempt_orig_artccs',
            'gs_exempt_dest_airports', 'gs_exempt_dest_tracons', 'gs_exempt_dest_artccs',
            'gs_exempt_flights', 'gs_exempt_depart_within', 'gs_exempt_alt_below', 'gs_exempt_alt_above',
        ];
        ids.forEach(function(id) {
            const el = document.getElementById(id);
            if (el) {el.value = '';}
        });

        // Reset exemption checkboxes
        const exemptCheckboxes = [
            'gs_exempt_type_jet', 'gs_exempt_type_turboprop', 'gs_exempt_type_prop',
            'gs_exempt_has_edct', 'gs_exempt_active_only',
        ];
        exemptCheckboxes.forEach(function(id) {
            const el = document.getElementById(id);
            if (el) {el.checked = false;}
        });

        const t = document.getElementById('gs_flt_incl_type');
        if (t) {t.value = 'ALL';}

        const scopeSelect = document.getElementById('gs_scope_select');
        if (scopeSelect) {
            Array.prototype.forEach.call(scopeSelect.options, function(opt) { opt.selected = false; });
        }

        const pre = document.getElementById('gs_advisory_preview');
        if (pre) {pre.textContent = '';}

        const tbody = document.getElementById('gs_flight_table_body');
        if (tbody) {tbody.innerHTML = '';}

        const updatedLbl = document.getElementById('gs_flights_updated');
        if (updatedLbl) {updatedLbl.textContent = '';}

        // Reset exemption summary
        if (typeof updateExemptionSummary === 'function') {
            updateExemptionSummary();
        }

        // Reset default times
        if (typeof initializeDefaultGsTimes === 'function') {
            initializeDefaultGsTimes();
        }

        updateAirportsLegendAndInput([], {});
        summarizeFlights([]);
        buildAdvisory();
    }

    function populateScopeSelector() {
        const sel = document.getElementById('gs_scope_select');
        if (!sel) {return;}

        sel.innerHTML = '';

        if (!TMI_TIER_INFO.length && !TMI_UNIQUE_FACILITIES.length) {
            // No data loaded; keep selector empty but usable later if data appears.
            return;
        }

        // Special presets (if present)
        const optgroupPresets = document.createElement('optgroup');
        optgroupPresets.label = PERTII18n.t('gdt.scope.presets');
        ['ALL', 'ALL+Canada', 'Manual'].forEach(function(code) {
            const entry = TMI_TIER_INFO_BY_CODE[code];
            const opt = document.createElement('option');
            opt.value = code;
            opt.dataset.type = (code === 'Manual') ? 'manual' : 'special';
            opt.dataset.tierLabel = code;
            if (entry && entry.label) {
                opt.textContent = code + ' ' + entry.label;
            } else {
                opt.textContent = code;
            }
            optgroupPresets.appendChild(opt);
        });
        sel.appendChild(optgroupPresets);

        // Group TierInfo by facility
        const facilities = {};
        TMI_TIER_INFO.forEach(function(e) {
            if (!e.facility) {return;}
            if (!facilities[e.facility]) {facilities[e.facility] = [];}
            facilities[e.facility].push(e);
        });

        Object.keys(facilities).sort().forEach(function(fac) {
            const group = document.createElement('optgroup');
            group.label = fac + ' tiers/groups';
            facilities[fac].forEach(function(e) {
                const opt = document.createElement('option');
                opt.value = e.code;
                opt.dataset.type = 'tier';
                const label = e.label || e.code;
                opt.dataset.tierLabel = label.replace(/[()]/g, '');
                group.appendChild(opt);
                opt.textContent = fac + ' ' + opt.dataset.tierLabel;
            });
            sel.appendChild(group);
        });

        // Individual facilities
        if (TMI_UNIQUE_FACILITIES.length) {
            const groupInd = document.createElement('optgroup');
            groupInd.label = PERTII18n.t('gdt.scope.individualFacilities');
            TMI_UNIQUE_FACILITIES.forEach(function(f) {
                const opt = document.createElement('option');
                opt.value = f;
                opt.dataset.type = 'fac';
                opt.dataset.tierLabel = f;
                opt.textContent = f;
                groupInd.appendChild(opt);
            });
            sel.appendChild(groupInd);
        }
    }

    function recomputeScopeFromSelector() {
        const sel = document.getElementById('gs_scope_select');
        if (!sel) {return;}

        const selected = Array.prototype.slice.call(sel.selectedOptions || []);
        const originCentersField = document.getElementById('gs_origin_centers');
        const depFacilitiesField = document.getElementById('gs_dep_facilities');

        const includedSet = new Set();
        const scopeTokens = [];
        const manual = selected.some(function(o) { return o.dataset.type === 'manual'; });

        if (!manual) {
            selected.forEach(function(o) {
                const type = o.dataset.type;
                const val = o.value;
                if (type === 'tier' || type === 'special') {
                    const entry = TMI_TIER_INFO_BY_CODE[val];
                    if (entry) {
                        scopeTokens.push(val);
                        (entry.included || []).forEach(function(f) { includedSet.add(f); });
                    }
                } else if (type === 'fac') {
                    scopeTokens.push(val);
                    includedSet.add(val);
                }
            });

            if (originCentersField) {
                originCentersField.value = scopeTokens.join(' ');
            }
            if (depFacilitiesField) {
                depFacilitiesField.value = Array.from(includedSet).sort().join(' ');
            }
        }

        buildAdvisory();
    }


    function expandAirportTokensWithFacilities(tokens) {
        if (!tokens || !tokens.length) {return [];}
        const hasFacilityData = Object.keys(CENTER_AIRPORTS_MAP).length > 0 || Object.keys(TRACON_AIRPORTS_MAP).length > 0;
        const airportsSet = new Set();
        tokens.forEach(function(tok) {
            const t = (tok || '').toUpperCase();
            if (!t) {return;}
            if (hasFacilityData && CENTER_AIRPORTS_MAP[t]) {
                CENTER_AIRPORTS_MAP[t].forEach(function(a) { airportsSet.add(a); });
            } else if (hasFacilityData && TRACON_AIRPORTS_MAP[t]) {
                TRACON_AIRPORTS_MAP[t].forEach(function(a) { airportsSet.add(a); });
            } else {
                airportsSet.add(t);
            }
        });
        return Array.from(airportsSet);
    }

    function renderSummaryTable(tbodyId, counts, options) {
        const tbody = document.getElementById(tbodyId);
        if (!tbody) {return;}
        const opts = options || {};
        const maxRows = typeof opts.maxRows === 'number' ? opts.maxRows : 10;
        const labelFor = opts.labelFor || function(k) { return k; };

        const keys = Object.keys(counts || {});
        if (!keys.length) {
            tbody.innerHTML = '<tr><td colspan="2" class="text-muted">' + PERTII18n.t('common.none') + '</td></tr>';
            return;
        }

        const entries = keys.map(function(k) { return { key: k, count: counts[k] || 0 }; });
        entries.sort(function(a, b) {
            if (b.count !== a.count) {return b.count - a.count;}
            return a.key.localeCompare(b.key);
        });

        let html = '';
        entries.slice(0, maxRows).forEach(function(e) {
            const label = labelFor(e.key);
            html += '<tr><td>' + escapeHtml(label) + '</td><td class="text-right">' + e.count + '</td></tr>';
        });
        tbody.innerHTML = html;
    }


    function renderDelaySummaryTable(tbodyId, stats, options) {
        const tbody = document.getElementById(tbodyId);
        if (!tbody) {return;}
        const opts = options || {};
        const maxRows = typeof opts.maxRows === 'number' ? opts.maxRows : 10;
        const labelFor = opts.labelFor || function(k) { return k; };

        const keys = Object.keys(stats || {});
        if (!keys.length) {
            tbody.innerHTML = '<tr><td colspan="4" class="text-muted">' + PERTII18n.t('common.none') + '</td></tr>';
            return;
        }

        let entries = keys.map(function(k) {
            const s = stats[k] || { total: 0, max: 0, count: 0 };
            const avg = s.count ? Math.round(s.total / s.count) : 0;
            return {
                key: k,
                label: labelFor(k),
                total: s.total,
                max: s.max,
                avg: avg,
            };
        });

        entries.sort(function(a, b) {
            if (b.total !== a.total) {return b.total - a.total;}
            return a.key.localeCompare(b.key);
        });

        if (entries.length > maxRows) {
            entries = entries.slice(0, maxRows);
        }

        let html = '';
        entries.forEach(function(e) {
            html += '<tr>' +
            '<td>' + escapeHtml(e.label) + '</td>' +
            '<td class="text-right">' + e.total + '</td>' +
            '<td class="text-right">' + e.max + '</td>' +
            '<td class="text-right">' + e.avg + '</td>' +
            '</tr>';
        });
        tbody.innerHTML = html;
    }

    function updateDelayBreakdowns(visibleRows) {
        visibleRows = visibleRows || [];

        const perAp = {};
        const perCenter = {};
        const perCarrier = {};
        const perHour = {};

        function accum(map, key, delayMin) {
            if (!key) {key = 'UNK';}
            if (!Object.prototype.hasOwnProperty.call(map, key)) {
                map[key] = { total: 0, max: 0, count: 0 };
            }
            const s = map[key];
            s.total += delayMin;
            if (delayMin > s.max) {s.max = delayMin;}
            s.count += 1;
        }

        visibleRows.forEach(function(tr) {
            if (!tr) {return;}

            const filedStr = tr.getAttribute('data-filed-dep-epoch') || '';
            const edctStr = tr.getAttribute('data-edct-epoch') || '';
            if (!filedStr || !edctStr) {return;}

            const filed = parseInt(filedStr, 10);
            const edct = parseInt(edctStr, 10);
            if (isNaN(filed) || isNaN(edct) || !filed || !edct) {return;}

            let delayMin = Math.round((edct - filed) / 60000);
            if (delayMin < 0) {delayMin = 0;}

            const tds = tr.querySelectorAll('td');
            let callsign = '';
            let depAp = '';
            let depCenter = '';
            if (tds && tds.length >= 1) {
                callsign = (tds[0].textContent || '').trim().toUpperCase();
            }

            // Table columns: 0=ACID, 1=ETD, 2=EDCT, 3=ETA, 4=DCENTR, 5=ORIG, 6=DEST, ...
            if (tds && tds.length >= 6) {
                depCenter = (tds[4].textContent || '').trim().toUpperCase();
                depAp = (tds[5].textContent || '').trim().toUpperCase();
            } else if (tds && tds.length >= 5) {
            // Fallback for older layouts (no DCENTR column)
                depAp = (tds[4].textContent || '').trim().toUpperCase();
            }

            const center = depCenter || (depAp ? (AIRPORT_CENTER_MAP[depAp] || 'UNK') : 'UNK');
            const carrier = callsign ? (deriveBtsCarrierFromCallsign(callsign) || 'UNK') : 'UNK';

            let hourKey = '';
            if (!isNaN(edct)) {
                const d = new Date(edct);
                const y = d.getUTCFullYear();
                const m = String(d.getUTCMonth() + 1).padStart(2, '0');
                const day = String(d.getUTCDate()).padStart(2, '0');
                const hh = String(d.getUTCHours()).padStart(2, '0');
                hourKey = y + '-' + m + '-' + day + ' ' + hh + 'Z';
            }

            accum(perAp, depAp || 'UNK', delayMin);
            accum(perCenter, center, delayMin);
            accum(perCarrier, carrier, delayMin);
            if (hourKey) {accum(perHour, hourKey, delayMin);}
        });

        renderDelaySummaryTable('gs_delay_origin_ap', perAp, { maxRows: 10 });
        renderDelaySummaryTable('gs_delay_origin_center', perCenter, { maxRows: 10 });
        renderDelaySummaryTable('gs_delay_carrier', perCarrier, { maxRows: 10 });
        renderDelaySummaryTable('gs_delay_hour_bin', perHour, { maxRows: 10 });
    }

    function summarizeFlights(rows) {
        rows = rows || [];
        const originCenterCounts = {};
        const destCenterCounts = {};
        const originApCounts = {};
        const destApCounts = {};
        const carrierCounts = {};

        rows.forEach(function(r) {
            const dep = (r.dep || '').toUpperCase();
            const arr = (r.arr || '').toUpperCase();
            const cs = (r.callsign || '').toUpperCase();

            const oCenter = AIRPORT_CENTER_MAP[dep] || 'UNK';
            const dCenter = AIRPORT_CENTER_MAP[arr] || 'UNK';

            originCenterCounts[oCenter] = (originCenterCounts[oCenter] || 0) + 1;
            destCenterCounts[dCenter] = (destCenterCounts[dCenter] || 0) + 1;

            if (dep) {originApCounts[dep] = (originApCounts[dep] || 0) + 1;}
            if (arr) {destApCounts[arr] = (destApCounts[arr] || 0) + 1;}

            let carrier = 'UNK';
            if (cs) {
                const m = cs.match(/^[A-Z]+/);
                carrier = m ? m[0] : cs;
            }
            carrierCounts[carrier] = (carrierCounts[carrier] || 0) + 1;
        });

        renderSummaryTable('gs_counts_origin_center', originCenterCounts, {
            maxRows: 10,
            labelFor: function(k) { return k === 'UNK' ? PERTII18n.t('common.unknown') : k; },
        });
        renderSummaryTable('gs_counts_dest_center', destCenterCounts, {
            maxRows: 10,
            labelFor: function(k) { return k === 'UNK' ? PERTII18n.t('common.unknown') : k; },
        });
        renderSummaryTable('gs_counts_origin_ap', originApCounts, { maxRows: 10 });
        renderSummaryTable('gs_counts_dest_ap', destApCounts, { maxRows: 10 });
        renderSummaryTable('gs_counts_carrier', carrierCounts, { maxRows: 10 });
    }


    function extractRowSummary(tr) {
        if (!tr) {return null;}
        const tds = tr.querySelectorAll('td');
        if (!tds || tds.length < 7) {return null;}
        return {
            callsign: (tds[0].textContent || '').trim(), // ACID
            dep: (tds[5].textContent || '').trim(),      // ORIG
            arr: (tds[6].textContent || '').trim(),       // DEST
        };
    }

    function getFlightModelForRow(tr) {
        if (!tr || !GS_FLIGHT_ROW_INDEX) {return null;}
        const rowId = tr.id || '';
        if (rowId && GS_FLIGHT_ROW_INDEX[rowId]) {
            return GS_FLIGHT_ROW_INDEX[rowId];
        }
        const cs = tr.getAttribute('data-callsign') || '';
        if (cs) {
            const altId = makeRowIdForCallsign(cs);
            if (GS_FLIGHT_ROW_INDEX[altId]) {
                return GS_FLIGHT_ROW_INDEX[altId];
            }
        }
        return null;
    }

    function showGsFlightRoute(tr) {
        if (!tr) {return;}
        let route = tr.getAttribute('data-route') || '';
        const cs = tr.getAttribute('data-callsign') || '';
        if (!route) {
            route = PERTII18n.t('gdt.flight.noRoute');
        }
        if (window.Swal) {
            window.Swal.fire({
                title: cs ? PERTII18n.t('gdt.flight.routeTitle', { callsign: cs }) : PERTII18n.t('gdt.flight.flightPlanRoute'),
                html: "<pre style='text-align:left;white-space:pre-wrap;font-family:Inconsolata,monospace;font-size:0.8rem;'>" +
              escapeHtml(route) + '</pre>',
                width: '60%',
            });
        } else {
            alert(cs + '\n\n' + route);
        }
    }

    function showGsFlightInfo(tr) {
        if (!tr) {return;}
        const model = getFlightModelForRow(tr) || {};
        const summary = extractRowSummary(tr) || {};
        const callsign = summary.callsign || model.callsign || '';
        const dep = summary.dep || model.dep || '';
        const arr = summary.arr || model.arr || '';

        const acft = model.aircraft || '';
        const status = (model.flightStatus || model.status || PERTII18n.t('gdt.page.normalStatus'));
        const src = model.source || '';

        const etdCell = tr.querySelector('.gs_etd_cell');
        const edctCell = tr.querySelector('.gs_edct_cell');
        const etaCell = tr.querySelector('.gs_eta_cell');
        const etdText = etdCell ? (etdCell.textContent || '').trim() : '';
        const edctText = edctCell ? (edctCell.textContent || '').trim() : '';
        const etaText = etaCell ? (etaCell.textContent || '').trim() : '';

        let eteStr = '';
        if (typeof model.eteMinutes === 'number' && !isNaN(model.eteMinutes)) {
            eteStr = String(Math.round(model.eteMinutes));
        }

        const adlTime = getAdlSnapshotDisplayText();

        const adlRaw = model._adl && model._adl.raw ? model._adl.raw : null;
        let ctlElement = '';
        let tmaRt = '';
        if (adlRaw) {
            ctlElement = adlRaw.ctl_element || adlRaw.CTL_ELEMENT || ctlElement;
            tmaRt = adlRaw.tma_rt || adlRaw.TMA_RT || tmaRt;
        }
        if (!ctlElement) {
            ctlElement = getValue('gs_ctl_element') || '-';
        }

        const html = ''
      + '<div class="tfms-flight-info-popup">'
      +   '<div class="mb-2"><strong>' + PERTII18n.t('gdt.flight.adlDateTime') + ':</strong> ' + escapeHtml(adlTime || '-')
      +   '&nbsp;&nbsp;&nbsp;<strong>' + PERTII18n.t('gdt.flight.status') + ':</strong> ' + escapeHtml(status || '-') + '</div>'
      +   '<table class="table table-sm table-borderless mb-2"><tbody>'
      +     '<tr>'
      +       '<td><strong>' + PERTII18n.t('gdt.flight.flightId') + ':</strong></td><td>' + escapeHtml(callsign || '-') + '</td>'
      +       '<td><strong>' + PERTII18n.t('gdt.flight.aircraftType') + ':</strong></td><td>' + escapeHtml(acft || '-') + '</td>'
      +     '</tr>'
      +     '<tr>'
      +       '<td><strong>' + PERTII18n.t('gdt.flight.orig') + ':</strong></td><td>' + escapeHtml(dep || '-') + '</td>'
      +       '<td><strong>' + PERTII18n.t('gdt.flight.dest') + ':</strong></td><td>' + escapeHtml(arr || '-') + '</td>'
      +     '</tr>'
      +     '<tr>'
      +       '<td><strong>' + PERTII18n.t('gdt.flight.etd') + ':</strong></td><td>' + escapeHtml(etdText || '-') + '</td>'
      +       '<td><strong>' + PERTII18n.t('gdt.flight.eta') + ':</strong></td><td>' + escapeHtml(etaText || '-') + '</td>'
      +     '</tr>'
      +     '<tr>'
      +       '<td><strong>' + PERTII18n.t('gdt.flight.edctLabel') + ':</strong></td><td>' + escapeHtml(edctText || '-') + '</td>'
      +       '<td><strong>' + PERTII18n.t('gdt.flight.ete') + ':</strong></td><td>' + escapeHtml(eteStr || '-') + '</td>'
      +     '</tr>'
      +     '<tr>'
      +       '<td><strong>' + PERTII18n.t('gdt.flight.ctlElement') + ':</strong></td><td>' + escapeHtml(ctlElement || '-') + '</td>'
      +       '<td><strong>' + PERTII18n.t('gdt.flight.tmaRt') + ':</strong></td><td>' + escapeHtml(tmaRt || '-') + '</td>'
      +     '</tr>'
      +   '</tbody></table>'
      +   '<div><small>' + PERTII18n.t('gdt.flight.source') + ': ' + escapeHtml(src || '') + '</small></div>'
      + '</div>';

        if (window.Swal) {
            window.Swal.fire({
                title: callsign ? PERTII18n.t('gdt.flight.infoTitle', { callsign: callsign }) : PERTII18n.t('gdt.flight.infoTitleGeneric'),
                html: html,
                width: '60%',
                showConfirmButton: true,
            });
        } else {
            const txt = PERTII18n.t('gdt.flight.flightLabel') + ': ' + (callsign || '') + '\n'
              + PERTII18n.t('gdt.flight.orig') + ': ' + (dep || '') + '  ' + PERTII18n.t('gdt.flight.dest') + ': ' + (arr || '') + '\n'
              + PERTII18n.t('gdt.flight.etd') + ': ' + (etdText || '') + '  ' + PERTII18n.t('gdt.flight.edctLabel') + ': ' + (edctText || '') + '  ' + PERTII18n.t('gdt.flight.eta') + ': ' + (etaText || '') + '\n'
              + PERTII18n.t('gdt.flight.ete') + ': ' + (eteStr || '');
            alert(txt);
        }
    }

    function showGsFlightDetail(tr) {
        if (!tr) {return;}
        const model = getFlightModelForRow(tr) || {};
        const summary = extractRowSummary(tr) || {};
        const callsign = summary.callsign || model.callsign || '';
        const dep = summary.dep || model.dep || '';
        const arr = summary.arr || model.arr || '';
        const acft = model.aircraft || '';
        const status = (model.flightStatus || model.status || PERTII18n.t('gdt.page.normalStatus'));

        function readEpochAttr(attr) {
            const v = tr.getAttribute(attr) || '';
            if (!v) {return null;}
            const n = parseInt(v, 10);
            return isNaN(n) ? null : n;
        }

        const filedEpoch = readEpochAttr('data-filed-dep-epoch');
        const etdEpoch = readEpochAttr('data-etd-epoch') || filedEpoch;
        const edctEpoch = readEpochAttr('data-edct-epoch');
        const tkofEpoch = readEpochAttr('data-takeoff-epoch');
        const etaEpoch = readEpochAttr('data-eta-epoch');
        const mftEpoch = readEpochAttr('data-mft-epoch');
        const vtEpoch = readEpochAttr('data-vt-epoch');

        const filedText = filedEpoch != null ? formatZuluFromEpoch(filedEpoch) : '';
        const etdText = etdEpoch != null ? formatZuluFromEpoch(etdEpoch) : '';
        const edctText = edctEpoch != null ? formatZuluFromEpoch(edctEpoch) : '';
        const tkofText = tkofEpoch != null ? formatZuluFromEpoch(tkofEpoch) : '';
        const etaText = etaEpoch != null ? formatZuluFromEpoch(etaEpoch) : '';
        const mftText = mftEpoch != null ? formatZuluFromEpoch(mftEpoch) : '';
        const vtText = vtEpoch != null ? formatZuluFromEpoch(vtEpoch) : '';

        let delayMin = null;
        if (filedEpoch != null && edctEpoch != null) {
            delayMin = Math.max(0, Math.round((edctEpoch - filedEpoch) / 60000));
        }
        const delayStr = delayMin != null ? String(delayMin) : '';

        let eteStr = '';
        if (typeof model.eteMinutes === 'number' && !isNaN(model.eteMinutes)) {
            eteStr = String(Math.round(model.eteMinutes));
        } else if (etdEpoch != null && etaEpoch != null) {
            eteStr = String(Math.max(0, Math.round((etaEpoch - etdEpoch) / 60000)));
        }

        const route = tr.getAttribute('data-route') || '';
        const adlTime = getAdlSnapshotDisplayText();

        const html = ''
      + '<div class="tfms-flight-detail-popup">'
      +   '<div class="mb-2"><strong>' + PERTII18n.t('gdt.flight.flightLabel') + ':</strong> ' + escapeHtml(callsign || '-')
      +   '&nbsp;&nbsp;&nbsp;<strong>' + PERTII18n.t('gdt.flight.type') + ':</strong> ' + escapeHtml(acft || '-')
      +   '&nbsp;&nbsp;&nbsp;<strong>' + PERTII18n.t('gdt.flight.status') + ':</strong> ' + escapeHtml(status || '-') + '</div>'
      +   '<div class="mb-2"><strong>' + PERTII18n.t('gdt.flight.orig') + ':</strong> ' + escapeHtml(dep || '-')
      +   '&nbsp;&nbsp;&nbsp;<strong>' + PERTII18n.t('gdt.flight.dest') + ':</strong> ' + escapeHtml(arr || '-') + '</div>'
      +   '<div class="mb-2"><strong>' + PERTII18n.t('gdt.flight.adlDateTime') + ':</strong> ' + escapeHtml(adlTime || '-') + '</div>'
      +   '<table class="table table-sm table-bordered mb-2"><tbody>'
      +     '<tr><th colspan="2">' + PERTII18n.t('gdt.flight.departureTimeline') + '</th></tr>'
      +     '<tr><td>' + PERTII18n.t('gdt.flight.filedDeparture') + '</td><td>' + escapeHtml(filedText || '-') + '</td></tr>'
      +     '<tr><td>' + PERTII18n.t('gdt.flight.etd') + '</td><td>' + escapeHtml(etdText || '-') + '</td></tr>'
      +     '<tr><td>' + PERTII18n.t('gdt.flight.edctCtd') + '</td><td>' + escapeHtml(edctText || '-') + '</td></tr>'
      +     '<tr><td>' + PERTII18n.t('gdt.flight.actualTakeoff') + '</td><td>' + escapeHtml(tkofText || '-') + '</td></tr>'
      +   '</tbody></table>'
      +   '<table class="table table-sm table-bordered mb-2"><tbody>'
      +     '<tr><th colspan="2">' + PERTII18n.t('gdt.flight.arrivalTimeline') + '</th></tr>'
      +     '<tr><td>' + PERTII18n.t('gdt.flight.eta') + '</td><td>' + escapeHtml(etaText || '-') + '</td></tr>'
      +     '<tr><td>' + PERTII18n.t('gdt.flight.mft') + '</td><td>' + escapeHtml(mftText || '-') + '</td></tr>'
      +     '<tr><td>' + PERTII18n.t('gdt.flight.vertexTime') + '</td><td>' + escapeHtml(vtText || '-') + '</td></tr>'
      +   '</tbody></table>'
      +   '<table class="table table-sm table-borderless mb-2"><tbody>'
      +     '<tr><td><strong>' + PERTII18n.t('gdt.flight.eteMin') + ':</strong></td><td>' + escapeHtml(eteStr || '-') + '</td>'
      +         '<td><strong>' + PERTII18n.t('gdt.flight.delayMin') + ':</strong></td><td>' + escapeHtml(delayStr || '-') + '</td></tr>'
      +   '</tbody></table>'
      +   '<div><strong>' + PERTII18n.t('gdt.flight.route') + ':</strong></div>'
      +   '<pre style="max-height:10rem;overflow:auto;white-space:pre-wrap;font-family:Inconsolata,monospace;font-size:0.8rem;">'
      +     escapeHtml(route || PERTII18n.t('gdt.flight.noRoute'))
      +   '</pre>'
      + '</div>';

        if (window.Swal) {
            window.Swal.fire({
                title: callsign ? PERTII18n.t('gdt.flight.detailTitle', { callsign: callsign }) : PERTII18n.t('gdt.flight.detail'),
                html: html,
                width: '70%',
                showConfirmButton: true,
            });
        } else {
            const txt = PERTII18n.t('gdt.flight.detailFor', { callsign: callsign || '' }) + '\n'
              + PERTII18n.t('gdt.flight.orig') + ': ' + (dep || '') + '  ' + PERTII18n.t('gdt.flight.dest') + ': ' + (arr || '') + '\n'
              + PERTII18n.t('gdt.flight.filed') + ': ' + (filedText || '') + '  ' + PERTII18n.t('gdt.flight.etd') + ': ' + (etdText || '') + '  ' + PERTII18n.t('gdt.flight.edctLabel') + ': ' + (edctText || '') + '\n'
              + PERTII18n.t('gdt.flight.eta') + ': ' + (etaText || '') + '  ' + PERTII18n.t('gdt.flight.eteMin') + ': ' + (eteStr || '') + '  ' + PERTII18n.t('gdt.flight.delayMin') + ': ' + (delayStr || '');
            alert(txt);
        }
    }

    function initGsFlightContextMenu() {
        const tbody = document.getElementById('gs_flight_table_body');
        if (!tbody) {return;}

        const menuId = 'gs_flight_context_menu';
        let menu = document.getElementById(menuId);
        if (!menu) {
            menu = document.createElement('div');
            menu.id = menuId;
            menu.className = 'dropdown-menu shadow';
            menu.style.position = 'fixed';
            menu.style.zIndex = '9999';
            menu.style.display = 'none';
            // TFMS/FSM compliant context menu options (Ch 6 & 13)
            menu.innerHTML = ''
        + '<button type="button" class="dropdown-item" data-action="info"><i class="fas fa-info-circle mr-2"></i>' + PERTII18n.t('gdt.contextMenu.flightInfo') + '</button>'
        + '<button type="button" class="dropdown-item" data-action="detail"><i class="fas fa-clipboard-list mr-2"></i>' + PERTII18n.t('gdt.contextMenu.flightDetail') + '</button>'
        + '<div class="dropdown-divider"></div>'
        + '<button type="button" class="dropdown-item" data-action="edct_check"><i class="fas fa-search mr-2"></i>' + PERTII18n.t('gdt.contextMenu.edctCheck') + '</button>'
        + '<button type="button" class="dropdown-item" data-action="edct_update"><i class="fas fa-edit mr-2"></i>' + PERTII18n.t('gdt.contextMenu.edctUpdate') + '</button>'
        + '<div class="dropdown-divider"></div>'
        + '<button type="button" class="dropdown-item" data-action="ecr"><i class="fas fa-clock mr-2"></i>' + PERTII18n.t('gdt.contextMenu.ecr') + '</button>'
        + '<div class="dropdown-divider"></div>'
        + '<button type="button" class="dropdown-item" data-action="route"><i class="fas fa-route mr-2"></i>' + PERTII18n.t('gdt.contextMenu.flightPlanRoute') + '</button>';
            document.body.appendChild(menu);
        }

        function hideMenu() {
            if (!menu) {return;}
            menu.style.display = 'none';
            menu._currentRow = null;
        }

        tbody.addEventListener('contextmenu', function(ev) {
            const tr = ev.target.closest('tr');
            if (!tr) {return;}
            ev.preventDefault();
            ev.stopPropagation();
            menu._currentRow = tr;

            let x = ev.clientX || 0;
            let y = ev.clientY || 0;

            // Ensure menu stays within viewport
            const menuWidth = 200;
            const menuHeight = 280;
            if (x + menuWidth > window.innerWidth) {x = window.innerWidth - menuWidth - 10;}
            if (y + menuHeight > window.innerHeight) {y = window.innerHeight - menuHeight - 10;}

            menu.style.left = x + 'px';
            menu.style.top = y + 'px';
            menu.style.display = 'block';
        });

        menu.addEventListener('click', function(ev) {
            const btn = ev.target.closest('.dropdown-item');
            if (!btn) {return;}
            ev.preventDefault();
            const action = btn.getAttribute('data-action');
            const tr = menu._currentRow;
            hideMenu();
            if (!tr) {return;}

            switch (action) {
                case 'info':
                    showGsFlightInfo(tr);
                    break;
                case 'detail':
                    showGsFlightDetail(tr);
                    break;
                case 'route':
                    showGsFlightRoute(tr);
                    break;
                case 'edct_check':
                    showMatchingTableEdctCheck(tr);
                    break;
                case 'edct_update':
                    showMatchingTableEdctUpdate(tr);
                    break;
                case 'ecr':
                    openMatchingTableEcr(tr);
                    break;
            }
        });

        document.addEventListener('click', function(ev) {
            if (!menu || menu.style.display === 'none') {return;}
            if (ev.target === menu || menu.contains(ev.target)) {return;}
            hideMenu();
        });

        document.addEventListener('keydown', function(ev) {
            if (ev.key === 'Escape') {
                hideMenu();
            }
        });
    }

    // EDCT Check for Flights Matching table (TFMS/FSM Ch 13 - Figure 13-6)
    function showMatchingTableEdctCheck(tr) {
        if (!tr) {return;}

        const callsign = tr.getAttribute('data-callsign') || '';
        const orig = tr.getAttribute('data-orig') || '';
        const dest = tr.getAttribute('data-dest') || '';

        // Get EDCT/CTD from cells or data attributes
        const edctCell = tr.querySelector('.gs_edct_cell');
        const etaCell = tr.querySelector('.gs_eta_cell');
        const edctText = edctCell ? edctCell.textContent.trim() : '-';
        const etaText = etaCell ? etaCell.textContent.trim() : '-';

        // Get delay from model if available
        const model = getFlightModelForRow(tr) || {};
        const delay = model.programDelayMin || model.delay || 0;

        const html = ''
      + '<div class="text-left">'
      +   '<p class="mb-3">' + PERTII18n.t('gdt.edct.queryStatus', { callsign: '<strong>' + escapeHtml(callsign) + '</strong>' }) + '</p>'
      +   '<table class="table table-sm table-bordered"><tbody>'
      +     '<tr><th width="30%">' + PERTII18n.t('gdt.edct.acid') + '</th><td>' + escapeHtml(callsign) + '</td></tr>'
      +     '<tr><th>' + PERTII18n.t('gdt.flight.orig') + '</th><td>' + escapeHtml(orig) + '</td></tr>'
      +     '<tr><th>' + PERTII18n.t('gdt.flight.dest') + '</th><td>' + escapeHtml(dest) + '</td></tr>'
      +   '</tbody></table>'
      +   '<hr>'
      +   '<h6>' + PERTII18n.t('gdt.edct.currentStatus') + ':</h6>'
      +   '<table class="table table-sm table-bordered"><tbody>'
      +     '<tr><th width="30%">' + PERTII18n.t('gdt.edct.ctdEdct') + '</th><td class="font-weight-bold">' + escapeHtml(edctText) + '</td></tr>'
      +     '<tr><th>' + PERTII18n.t('gdt.flight.eta') + '</th><td>' + escapeHtml(etaText) + '</td></tr>'
      +     '<tr><th>' + PERTII18n.t('gdt.edct.delay') + '</th><td class="' + (delay > 60 ? 'text-danger' : (delay > 30 ? 'text-warning' : '')) + '">' + delay + ' ' + PERTII18n.t('units.min') + '</td></tr>'
      +   '</tbody></table>'
      +   '<div class="alert alert-info mt-3 mb-0"><small><i class="fas fa-info-circle mr-1"></i>' + PERTII18n.t('gdt.edct.checkNote') + '</small></div>'
      + '</div>';

        if (window.Swal) {
            window.Swal.fire({
                title: '<i class="fas fa-search text-info mr-2"></i>' + PERTII18n.t('gdt.contextMenu.edctCheck'),
                html: html,
                width: '50%',
                showConfirmButton: true,
                confirmButtonText: PERTII18n.t('common.close'),
            });
        } else {
            alert(PERTII18n.t('gdt.edct.check', { callsign: callsign }) + '\n' + PERTII18n.t('gdt.edct.ctd') + ': ' + edctText + '\n' + PERTII18n.t('gdt.flight.eta') + ': ' + etaText);
        }
    }

    // EDCT Update for Flights Matching table (TFMS/FSM Ch 13 - Figure 13-7)
    function showMatchingTableEdctUpdate(tr) {
        if (!tr) {return;}

        const callsign = tr.getAttribute('data-callsign') || '';
        const orig = tr.getAttribute('data-orig') || '';
        const dest = tr.getAttribute('data-dest') || '';

        const edctCell = tr.querySelector('.gs_edct_cell');
        const edctText = edctCell ? edctCell.textContent.trim() : '-';

        // Get EDCT epoch for default new time calculation
        const edctEpoch = parseInt(tr.getAttribute('data-edct-epoch') || '0', 10);
        let defaultNewEdct = '';
        if (edctEpoch > 0) {
            const newDate = new Date(edctEpoch + 15 * 60000); // +15 minutes
            defaultNewEdct = newDate.toISOString().slice(0, 16);
        }

        const html = ''
      + '<div class="text-left">'
      +   '<p class="mb-3">' + PERTII18n.t('gdt.edct.updateFor', { callsign: '<strong>' + escapeHtml(callsign) + '</strong>' }) + '</p>'
      +   '<table class="table table-sm table-bordered mb-3"><tbody>'
      +     '<tr><th width="30%">' + PERTII18n.t('gdt.edct.acid') + '</th><td>' + escapeHtml(callsign) + '</td></tr>'
      +     '<tr><th>' + PERTII18n.t('gdt.flight.orig') + '</th><td>' + escapeHtml(orig) + '</td></tr>'
      +     '<tr><th>' + PERTII18n.t('gdt.flight.dest') + '</th><td>' + escapeHtml(dest) + '</td></tr>'
      +     '<tr><th>' + PERTII18n.t('gdt.edct.currentCtd') + '</th><td class="font-weight-bold">' + escapeHtml(edctText) + '</td></tr>'
      +   '</tbody></table>'
      +   '<div class="form-group">'
      +     '<label for="matching_edct_update_time"><strong>' + PERTII18n.t('gdt.edct.newEdctUtc') + ':</strong></label>'
      +     '<input type="datetime-local" class="form-control" id="matching_edct_update_time" value="' + defaultNewEdct + '">'
      +     '<small class="form-text text-muted">' + PERTII18n.t('gdt.edct.enterNewEdct') + '</small>'
      +   '</div>'
      +   '<div class="alert alert-warning mt-3 mb-0"><small><i class="fas fa-exclamation-triangle mr-1"></i>' + PERTII18n.t('gdt.edct.updateNote') + '</small></div>'
      + '</div>';

        if (window.Swal) {
            window.Swal.fire({
                title: '<i class="fas fa-edit text-warning mr-2"></i>' + PERTII18n.t('gdt.contextMenu.edctUpdate'),
                html: html,
                width: '50%',
                showConfirmButton: true,
                confirmButtonText: PERTII18n.t('gdt.edct.sendUpdate'),
                showCancelButton: true,
                cancelButtonText: PERTII18n.t('common.cancel'),
                preConfirm: function() {
                    const newEdctEl = document.getElementById('matching_edct_update_time');
                    const newEdct = newEdctEl ? newEdctEl.value : '';
                    if (!newEdct) {
                        window.Swal.showValidationMessage(PERTII18n.t('gdt.edct.enterNewEdctValidation'));
                        return false;
                    }
                    return { callsign: callsign, orig: orig, dest: dest, newEdct: newEdct };
                },
            }).then(function(result) {
                if (result.isConfirmed && result.value) {
                    const newEdctFormatted = formatZuluFromEpoch(new Date(result.value.newEdct).getTime());
                    window.Swal.fire({
                        icon: 'info',
                        title: PERTII18n.t('gdt.edct.updateSimulated'),
                        html: '<p>' + PERTII18n.t('gdt.edct.simulatedMessage') + '</p>' +
                  '<p><strong>' + PERTII18n.t('gdt.flight.flightLabel') + ':</strong> ' + escapeHtml(result.value.callsign) + '<br>' +
                  '<strong>' + PERTII18n.t('gdt.edct.newEdctLabel') + ':</strong> ' + newEdctFormatted + '</p>' +
                  '<p class="text-muted small">' + PERTII18n.t('gdt.edct.simulationDisclaimer') + '</p>',
                    });
                }
            });
        } else {
            alert(PERTII18n.t('gdt.edct.update', { callsign: callsign }) + '\n' + PERTII18n.t('gdt.edct.currentCtd') + ': ' + edctText);
        }
    }

    // Open ECR for Flights Matching table row
    function openMatchingTableEcr(tr) {
        if (!tr) {return;}

        const callsign = tr.getAttribute('data-callsign') || '';
        const orig = tr.getAttribute('data-orig') || '';
        const dest = tr.getAttribute('data-dest') || '';

        // Use the existing openEcrForFlight function
        openEcrForFlight(callsign, orig, dest);
    }

    // ========================================================================
    // GS FLIGHT LIST CONTEXT MENU (TFMS/FSM Spec: Chapter 6 & 13)
    // Right-click options: Flight Info, Flight Detail, EDCT Check, EDCT Update, ECR
    // ========================================================================

    let GS_FLT_LIST_CONTEXT_MENU = null;
    let GS_FLT_LIST_CONTEXT_ROW = null;

    function initGsFlightListContextMenu() {
        const tbody = document.getElementById('gs_flight_list_body');
        if (!tbody) {return;}

        const menuId = 'gs_flt_list_context_menu';
        let menu = document.getElementById(menuId);
        if (!menu) {
            menu = document.createElement('div');
            menu.id = menuId;
            menu.className = 'dropdown-menu shadow';
            menu.style.position = 'fixed';
            menu.style.zIndex = '9999';
            menu.style.display = 'none';
            menu.innerHTML = ''
        + '<button type="button" class="dropdown-item" data-action="flight_info"><i class="fas fa-info-circle mr-2"></i>' + PERTII18n.t('gdt.contextMenu.flightInfo') + '</button>'
        + '<button type="button" class="dropdown-item" data-action="flight_detail"><i class="fas fa-clipboard-list mr-2"></i>' + PERTII18n.t('gdt.contextMenu.flightDetail') + '</button>'
        + '<div class="dropdown-divider"></div>'
        + '<button type="button" class="dropdown-item" data-action="edct_check"><i class="fas fa-search mr-2"></i>' + PERTII18n.t('gdt.contextMenu.edctCheck') + '</button>'
        + '<button type="button" class="dropdown-item" data-action="edct_update"><i class="fas fa-edit mr-2"></i>' + PERTII18n.t('gdt.contextMenu.edctUpdate') + '</button>'
        + '<div class="dropdown-divider"></div>'
        + '<button type="button" class="dropdown-item" data-action="ecr"><i class="fas fa-clock mr-2"></i>' + PERTII18n.t('gdt.contextMenu.ecr') + '</button>';
            document.body.appendChild(menu);
            GS_FLT_LIST_CONTEXT_MENU = menu;
        }

        function hideFlightListMenu() {
            if (!GS_FLT_LIST_CONTEXT_MENU) {return;}
            GS_FLT_LIST_CONTEXT_MENU.style.display = 'none';
            GS_FLT_LIST_CONTEXT_ROW = null;
        }

        tbody.addEventListener('contextmenu', function(ev) {
            const tr = ev.target.closest('tr.gs-flt-list-row');
            if (!tr) {return;}
            ev.preventDefault();
            ev.stopPropagation();
            GS_FLT_LIST_CONTEXT_ROW = tr;

            let x = ev.clientX || 0;
            let y = ev.clientY || 0;

            // Ensure menu stays within viewport
            const menuWidth = 200;
            const menuHeight = 220;
            if (x + menuWidth > window.innerWidth) {x = window.innerWidth - menuWidth - 10;}
            if (y + menuHeight > window.innerHeight) {y = window.innerHeight - menuHeight - 10;}

            GS_FLT_LIST_CONTEXT_MENU.style.left = x + 'px';
            GS_FLT_LIST_CONTEXT_MENU.style.top = y + 'px';
            GS_FLT_LIST_CONTEXT_MENU.style.display = 'block';
        });

        menu.addEventListener('click', function(ev) {
            const btn = ev.target.closest('.dropdown-item');
            if (!btn) {return;}
            ev.preventDefault();
            const action = btn.getAttribute('data-action');
            const tr = GS_FLT_LIST_CONTEXT_ROW;
            hideFlightListMenu();
            if (!tr) {return;}

            switch (action) {
                case 'flight_info':
                    showFlightListFlightInfo(tr);
                    break;
                case 'flight_detail':
                    showFlightListFlightDetail(tr);
                    break;
                case 'edct_check':
                    showEdctCheckDialog(tr);
                    break;
                case 'edct_update':
                    showEdctUpdateDialog(tr);
                    break;
                case 'ecr':
                    openEcrFromFlightList(tr);
                    break;
            }
        });

        // Hide menu on click outside
        document.addEventListener('click', function(ev) {
            if (!GS_FLT_LIST_CONTEXT_MENU || GS_FLT_LIST_CONTEXT_MENU.style.display === 'none') {return;}
            if (ev.target === GS_FLT_LIST_CONTEXT_MENU || GS_FLT_LIST_CONTEXT_MENU.contains(ev.target)) {return;}
            hideFlightListMenu();
        });

        // Hide menu on scroll within modal
        const modalBody = tbody.closest('.modal-body');
        if (modalBody) {
            modalBody.addEventListener('scroll', hideFlightListMenu);
        }

        // Hide menu on Escape key
        document.addEventListener('keydown', function(ev) {
            if (ev.key === 'Escape') {
                hideFlightListMenu();
            }
        });
    }

    // Flight Info dialog for Flight List (TFMS/FSM Ch 6 - Figure 6-5)
    function showFlightListFlightInfo(tr) {
        if (!tr) {return;}

        const acid = tr.getAttribute('data-acid') || '';
        const orig = tr.getAttribute('data-orig') || '';
        const dest = tr.getAttribute('data-dest') || '';
        const dcenter = tr.getAttribute('data-dcenter') || '';
        const acenter = tr.getAttribute('data-acenter') || '';
        const oetd = tr.getAttribute('data-oetd') || '';
        const oeta = tr.getAttribute('data-oeta') || '';
        const ctd = tr.getAttribute('data-ctd') || '';
        const cta = tr.getAttribute('data-cta') || '';
        const delay = tr.getAttribute('data-delay') || '0';
        const status = tr.getAttribute('data-status') || '';

        const ctlElement = (GS_FLIGHT_LIST_PAYLOAD && GS_FLIGHT_LIST_PAYLOAD.gs_ctl_element) || '-';
        const adlTime = getAdlSnapshotDisplayText();

        const html = ''
      + '<div class="tfms-flight-info-popup text-left">'
      +   '<div class="mb-2"><strong>' + PERTII18n.t('gdt.flight.adlDateTime') + ':</strong> ' + escapeHtml(adlTime || '-')
      +   '&nbsp;&nbsp;&nbsp;<strong>' + PERTII18n.t('gdt.flight.status') + ':</strong> <span class="badge badge-warning">' + escapeHtml(status || 'GS') + '</span></div>'
      +   '<table class="table table-sm table-borderless mb-2"><tbody>'
      +     '<tr>'
      +       '<td><strong>' + PERTII18n.t('gdt.flight.flightId') + ':</strong></td><td>' + escapeHtml(acid || '-') + '</td>'
      +       '<td><strong>' + PERTII18n.t('gdt.flight.ctlElement') + ':</strong></td><td>' + escapeHtml(ctlElement || '-') + '</td>'
      +     '</tr>'
      +     '<tr>'
      +       '<td><strong>' + PERTII18n.t('gdt.flight.orig') + ':</strong></td><td>' + escapeHtml(orig || '-') + ' / ' + escapeHtml(dcenter || '-') + '</td>'
      +       '<td><strong>' + PERTII18n.t('gdt.flight.dest') + ':</strong></td><td>' + escapeHtml(dest || '-') + ' / ' + escapeHtml(acenter || '-') + '</td>'
      +     '</tr>'
      +     '<tr>'
      +       '<td><strong>' + PERTII18n.t('gdt.flight.oetd') + ':</strong></td><td>' + (oetd ? formatZuluFromIso(oetd) : '-') + '</td>'
      +       '<td><strong>' + PERTII18n.t('gdt.flight.oeta') + ':</strong></td><td>' + (oeta ? formatZuluFromIso(oeta) : '-') + '</td>'
      +     '</tr>'
      +     '<tr>'
      +       '<td><strong>' + PERTII18n.t('gdt.edct.ctd') + ':</strong></td><td class="font-weight-bold">' + (ctd ? formatZuluFromIso(ctd) : '-') + '</td>'
      +       '<td><strong>' + PERTII18n.t('gdt.edct.cta') + ':</strong></td><td class="font-weight-bold">' + (cta ? formatZuluFromIso(cta) : '-') + '</td>'
      +     '</tr>'
      +     '<tr>'
      +       '<td><strong>' + PERTII18n.t('gdt.edct.delay') + ':</strong></td><td class="' + (parseInt(delay) > 60 ? 'text-danger font-weight-bold' : (parseInt(delay) > 30 ? 'text-warning' : '')) + '">' + delay + ' ' + PERTII18n.t('units.min') + '</td>'
      +       '<td><strong>' + PERTII18n.t('gdt.flight.ctlProgram') + ':</strong></td><td>GS</td>'
      +     '</tr>'
      +   '</tbody></table>'
      + '</div>';

        if (window.Swal) {
            window.Swal.fire({
                title: '<i class="fas fa-info-circle text-info mr-2"></i>' + PERTII18n.t('gdt.flight.infoTitle', { callsign: escapeHtml(acid) }),
                html: html,
                width: '60%',
                showConfirmButton: true,
                confirmButtonText: PERTII18n.t('common.close'),
            });
        } else {
            alert(PERTII18n.t('gdt.flight.flightLabel') + ': ' + acid + '\n' + PERTII18n.t('gdt.flight.orig') + ': ' + orig + ' ' + PERTII18n.t('gdt.flight.dest') + ': ' + dest + '\n' + PERTII18n.t('gdt.edct.ctd') + ': ' + ctd + ' ' + PERTII18n.t('gdt.edct.cta') + ': ' + cta + '\n' + PERTII18n.t('gdt.edct.delay') + ': ' + delay + ' ' + PERTII18n.t('units.min'));
        }
    }

    // Flight Detail dialog for Flight List (TFMS/FSM Ch 6 - Figure 6-6)
    function showFlightListFlightDetail(tr) {
        if (!tr) {return;}

        const acid = tr.getAttribute('data-acid') || '';
        const orig = tr.getAttribute('data-orig') || '';
        const dest = tr.getAttribute('data-dest') || '';
        const dcenter = tr.getAttribute('data-dcenter') || '';
        const acenter = tr.getAttribute('data-acenter') || '';
        const oetd = tr.getAttribute('data-oetd') || '';
        const oeta = tr.getAttribute('data-oeta') || '';
        const etd = tr.getAttribute('data-etd') || '';
        const ctd = tr.getAttribute('data-ctd') || '';
        const eta = tr.getAttribute('data-eta') || '';
        const cta = tr.getAttribute('data-cta') || '';
        const delay = tr.getAttribute('data-delay') || '0';
        const status = tr.getAttribute('data-status') || '';
        const carrier = tr.getAttribute('data-carrier') || '';

        const ctlElement = (GS_FLIGHT_LIST_PAYLOAD && GS_FLIGHT_LIST_PAYLOAD.gs_ctl_element) || '-';
        const gsStart = (GS_FLIGHT_LIST_PAYLOAD && GS_FLIGHT_LIST_PAYLOAD.gs_start) ? formatZuluFromIso(GS_FLIGHT_LIST_PAYLOAD.gs_start) : '-';
        const gsEnd = (GS_FLIGHT_LIST_PAYLOAD && GS_FLIGHT_LIST_PAYLOAD.gs_end) ? formatZuluFromIso(GS_FLIGHT_LIST_PAYLOAD.gs_end) : '-';
        const adlTime = getAdlSnapshotDisplayText();

        // Calculate ETE if possible
        let eteStr = '-';
        if (oetd && oeta) {
            try {
                const etdDate = new Date(oetd);
                const etaDate = new Date(oeta);
                if (!isNaN(etdDate.getTime()) && !isNaN(etaDate.getTime())) {
                    const eteMin = Math.round((etaDate - etdDate) / 60000);
                    if (eteMin > 0) {eteStr = eteMin + ' ' + PERTII18n.t('units.min');}
                }
            } catch (e) {}
        }

        const html = ''
      + '<div class="tfms-flight-detail-popup text-left">'
      +   '<div class="row mb-3">'
      +     '<div class="col-md-6">'
      +       '<p class="mb-1"><strong>' + PERTII18n.t('gdt.flight.flightId') + ':</strong> ' + escapeHtml(acid) + '</p>'
      +       '<p class="mb-1"><strong>' + PERTII18n.t('gdt.flight.carrier') + ':</strong> ' + escapeHtml(carrier || '-') + '</p>'
      +       '<p class="mb-1"><strong>' + PERTII18n.t('gdt.flight.status') + ':</strong> <span class="badge badge-warning">' + escapeHtml(status || 'GS') + '</span></p>'
      +     '</div>'
      +     '<div class="col-md-6">'
      +       '<p class="mb-1"><strong>' + PERTII18n.t('gdt.flight.adlTime') + ':</strong> ' + escapeHtml(adlTime || '-') + '</p>'
      +       '<p class="mb-1"><strong>' + PERTII18n.t('gdt.flight.ctlElement') + ':</strong> ' + escapeHtml(ctlElement) + '</p>'
      +       '<p class="mb-1"><strong>' + PERTII18n.t('gdt.flight.gsPeriod') + ':</strong> ' + gsStart + ' - ' + gsEnd + '</p>'
      +     '</div>'
      +   '</div>'
      +   '<div class="row">'
      +     '<div class="col-md-6">'
      +       '<h6 class="border-bottom pb-1"><i class="fas fa-plane-departure mr-1"></i>' + PERTII18n.t('gdt.flight.departure') + '</h6>'
      +       '<table class="table table-sm table-borderless mb-0"><tbody>'
      +         '<tr><td width="40%">' + PERTII18n.t('gdt.flight.airport') + ':</td><td><strong>' + escapeHtml(orig || '-') + '</strong></td></tr>'
      +         '<tr><td>' + PERTII18n.t('gdt.flight.center') + ':</td><td>' + escapeHtml(dcenter || '-') + '</td></tr>'
      +         '<tr><td>' + PERTII18n.t('gdt.flight.oetd') + ':</td><td class="text-muted">' + (oetd ? formatZuluFromIso(oetd) : '-') + '</td></tr>'
      +         '<tr><td>' + PERTII18n.t('gdt.flight.etd') + ':</td><td>' + (etd ? formatZuluFromIso(etd) : '-') + '</td></tr>'
      +         '<tr><td>' + PERTII18n.t('gdt.edct.ctd') + ':</td><td class="font-weight-bold text-primary">' + (ctd ? formatZuluFromIso(ctd) : '-') + '</td></tr>'
      +       '</tbody></table>'
      +     '</div>'
      +     '<div class="col-md-6">'
      +       '<h6 class="border-bottom pb-1"><i class="fas fa-plane-arrival mr-1"></i>' + PERTII18n.t('gdt.flight.arrival') + '</h6>'
      +       '<table class="table table-sm table-borderless mb-0"><tbody>'
      +         '<tr><td width="40%">' + PERTII18n.t('gdt.flight.airport') + ':</td><td><strong>' + escapeHtml(dest || '-') + '</strong></td></tr>'
      +         '<tr><td>' + PERTII18n.t('gdt.flight.center') + ':</td><td>' + escapeHtml(acenter || '-') + '</td></tr>'
      +         '<tr><td>' + PERTII18n.t('gdt.flight.oeta') + ':</td><td class="text-muted">' + (oeta ? formatZuluFromIso(oeta) : '-') + '</td></tr>'
      +         '<tr><td>' + PERTII18n.t('gdt.flight.eta') + ':</td><td>' + (eta ? formatZuluFromIso(eta) : '-') + '</td></tr>'
      +         '<tr><td>' + PERTII18n.t('gdt.edct.cta') + ':</td><td class="font-weight-bold text-primary">' + (cta ? formatZuluFromIso(cta) : '-') + '</td></tr>'
      +       '</tbody></table>'
      +     '</div>'
      +   '</div>'
      +   '<div class="row mt-3">'
      +     '<div class="col-12">'
      +       '<table class="table table-sm table-bordered"><tbody>'
      +         '<tr class="bg-light">'
      +           '<th>' + PERTII18n.t('gdt.flight.ete') + '</th><th>' + PERTII18n.t('gdt.flight.programDelay') + '</th><th>' + PERTII18n.t('gdt.flight.controlType') + '</th><th>' + PERTII18n.t('gdt.flight.delayStatus') + '</th>'
      +         '</tr>'
      +         '<tr>'
      +           '<td>' + eteStr + '</td>'
      +           '<td class="' + (parseInt(delay) > 60 ? 'text-danger font-weight-bold' : (parseInt(delay) > 30 ? 'text-warning' : '')) + '">' + delay + ' ' + PERTII18n.t('units.min') + '</td>'
      +           '<td>GS</td>'
      +           '<td>' + escapeHtml(status || '-') + '</td>'
      +         '</tr>'
      +       '</tbody></table>'
      +     '</div>'
      +   '</div>'
      + '</div>';

        if (window.Swal) {
            window.Swal.fire({
                title: '<i class="fas fa-clipboard-list text-primary mr-2"></i>' + PERTII18n.t('gdt.flight.detailTitle', { callsign: escapeHtml(acid) }),
                html: html,
                width: '70%',
                showConfirmButton: true,
                confirmButtonText: PERTII18n.t('common.close'),
            });
        } else {
            alert(PERTII18n.t('gdt.flight.detail') + ': ' + acid + '\n' + PERTII18n.t('gdt.flight.orig') + ': ' + orig + '/' + dcenter + ' ' + PERTII18n.t('gdt.flight.dest') + ': ' + dest + '/' + acenter);
        }
    }

    // EDCT Check dialog (TFMS/FSM Ch 13 - Figure 13-6)
    function showEdctCheckDialog(tr) {
        if (!tr) {return;}

        const acid = tr.getAttribute('data-acid') || '';
        const orig = tr.getAttribute('data-orig') || '';
        const dest = tr.getAttribute('data-dest') || '';
        const ctd = tr.getAttribute('data-ctd') || '';
        const cta = tr.getAttribute('data-cta') || '';
        const delay = tr.getAttribute('data-delay') || '0';

        const ctdFormatted = ctd ? formatZuluFromIso(ctd) : '-';
        const ctaFormatted = cta ? formatZuluFromIso(cta) : '-';

        const html = ''
      + '<div class="text-left">'
      +   '<p class="mb-3">' + PERTII18n.t('gdt.edct.queryStatus', { callsign: '<strong>' + escapeHtml(acid) + '</strong>' }) + '</p>'
      +   '<table class="table table-sm table-bordered"><tbody>'
      +     '<tr><th width="30%">' + PERTII18n.t('gdt.edct.acid') + '</th><td>' + escapeHtml(acid) + '</td></tr>'
      +     '<tr><th>' + PERTII18n.t('gdt.flight.orig') + '</th><td>' + escapeHtml(orig) + '</td></tr>'
      +     '<tr><th>' + PERTII18n.t('gdt.flight.dest') + '</th><td>' + escapeHtml(dest) + '</td></tr>'
      +   '</tbody></table>'
      +   '<hr>'
      +   '<h6>' + PERTII18n.t('gdt.edct.currentStatus') + ':</h6>'
      +   '<table class="table table-sm table-bordered"><tbody>'
      +     '<tr><th width="30%">' + PERTII18n.t('gdt.edct.ctdEdct') + '</th><td class="font-weight-bold">' + ctdFormatted + '</td></tr>'
      +     '<tr><th>' + PERTII18n.t('gdt.edct.cta') + '</th><td>' + ctaFormatted + '</td></tr>'
      +     '<tr><th>' + PERTII18n.t('gdt.edct.delay') + '</th><td class="' + (parseInt(delay) > 60 ? 'text-danger' : (parseInt(delay) > 30 ? 'text-warning' : '')) + '">' + delay + ' ' + PERTII18n.t('units.min') + '</td></tr>'
      +   '</tbody></table>'
      +   '<div class="alert alert-info mt-3 mb-0"><small><i class="fas fa-info-circle mr-1"></i>' + PERTII18n.t('gdt.edct.checkNote') + '</small></div>'
      + '</div>';

        if (window.Swal) {
            window.Swal.fire({
                title: '<i class="fas fa-search text-info mr-2"></i>' + PERTII18n.t('gdt.contextMenu.edctCheck'),
                html: html,
                width: '50%',
                showConfirmButton: true,
                confirmButtonText: PERTII18n.t('common.close'),
                showCancelButton: false,
            });
        } else {
            alert(PERTII18n.t('gdt.edct.check', { callsign: acid }) + '\n' + PERTII18n.t('gdt.edct.ctd') + ': ' + ctdFormatted + '\n' + PERTII18n.t('gdt.edct.cta') + ': ' + ctaFormatted + '\n' + PERTII18n.t('gdt.edct.delay') + ': ' + delay + ' ' + PERTII18n.t('units.min'));
        }
    }

    // EDCT Update dialog (TFMS/FSM Ch 13 - Figure 13-7)
    function showEdctUpdateDialog(tr) {
        if (!tr) {return;}

        const acid = tr.getAttribute('data-acid') || '';
        const orig = tr.getAttribute('data-orig') || '';
        const dest = tr.getAttribute('data-dest') || '';
        const ctd = tr.getAttribute('data-ctd') || '';

        const ctdFormatted = ctd ? formatZuluFromIso(ctd) : '-';

        // Calculate default new EDCT (current CTD + 15 min)
        let defaultNewEdct = '';
        if (ctd) {
            try {
                const ctdDate = new Date(ctd);
                if (!isNaN(ctdDate.getTime())) {
                    ctdDate.setMinutes(ctdDate.getMinutes() + 15);
                    defaultNewEdct = ctdDate.toISOString().slice(0, 16);
                }
            } catch (e) {}
        }

        const html = ''
      + '<div class="text-left">'
      +   '<p class="mb-3">' + PERTII18n.t('gdt.edct.updateFor', { callsign: '<strong>' + escapeHtml(acid) + '</strong>' }) + '</p>'
      +   '<table class="table table-sm table-bordered mb-3"><tbody>'
      +     '<tr><th width="30%">' + PERTII18n.t('gdt.edct.acid') + '</th><td>' + escapeHtml(acid) + '</td></tr>'
      +     '<tr><th>' + PERTII18n.t('gdt.flight.orig') + '</th><td>' + escapeHtml(orig) + '</td></tr>'
      +     '<tr><th>' + PERTII18n.t('gdt.flight.dest') + '</th><td>' + escapeHtml(dest) + '</td></tr>'
      +     '<tr><th>' + PERTII18n.t('gdt.edct.currentCtd') + '</th><td class="font-weight-bold">' + ctdFormatted + '</td></tr>'
      +   '</tbody></table>'
      +   '<div class="form-group">'
      +     '<label for="edct_update_new_time"><strong>' + PERTII18n.t('gdt.edct.newEdctUtc') + ':</strong></label>'
      +     '<input type="datetime-local" class="form-control" id="edct_update_new_time" value="' + defaultNewEdct + '">'
      +     '<small class="form-text text-muted">' + PERTII18n.t('gdt.edct.enterNewEdct') + '</small>'
      +   '</div>'
      +   '<div class="alert alert-warning mt-3 mb-0"><small><i class="fas fa-exclamation-triangle mr-1"></i>' + PERTII18n.t('gdt.edct.updateNote') + '</small></div>'
      + '</div>';

        if (window.Swal) {
            window.Swal.fire({
                title: '<i class="fas fa-edit text-warning mr-2"></i>' + PERTII18n.t('gdt.contextMenu.edctUpdate'),
                html: html,
                width: '50%',
                showConfirmButton: true,
                confirmButtonText: PERTII18n.t('gdt.edct.sendUpdate'),
                showCancelButton: true,
                cancelButtonText: PERTII18n.t('common.cancel'),
                preConfirm: function() {
                    const newEdctEl = document.getElementById('edct_update_new_time');
                    const newEdct = newEdctEl ? newEdctEl.value : '';
                    if (!newEdct) {
                        window.Swal.showValidationMessage(PERTII18n.t('gdt.edct.enterNewEdctValidation'));
                        return false;
                    }
                    return { acid: acid, orig: orig, dest: dest, newEdct: newEdct };
                },
            }).then(function(result) {
                if (result.isConfirmed && result.value) {
                    const newEdctFormatted = formatZuluFromIso(result.value.newEdct);
                    window.Swal.fire({
                        icon: 'info',
                        title: PERTII18n.t('gdt.edct.updateSimulated'),
                        html: '<p>' + PERTII18n.t('gdt.edct.simulatedMessage') + '</p>' +
                  '<p><strong>' + PERTII18n.t('gdt.flight.flightLabel') + ':</strong> ' + escapeHtml(result.value.acid) + '<br>' +
                  '<strong>' + PERTII18n.t('gdt.edct.newEdctLabel') + ':</strong> ' + newEdctFormatted + '</p>' +
                  '<p class="text-muted small">' + PERTII18n.t('gdt.edct.simulationDisclaimer') + '</p>',
                    });
                }
            });
        } else {
            alert(PERTII18n.t('gdt.edct.update', { callsign: acid }) + '\n' + PERTII18n.t('gdt.edct.currentCtd') + ': ' + ctdFormatted + '\n\n' + PERTII18n.t('gdt.edct.simulationDisclaimer'));
        }
    }

    // Open ECR modal for a flight from Flight List context menu (TFMS/FSM Ch 14)
    function openEcrFromFlightList(tr) {
        if (!tr) {return;}

        const acid = tr.getAttribute('data-acid') || '';
        const orig = tr.getAttribute('data-orig') || '';
        const dest = tr.getAttribute('data-dest') || '';

        // Close the flight list modal first
        if (window.jQuery) {
            window.jQuery('#gs_flight_list_modal').modal('hide');
        }

        // Use the existing openEcrForFlight function (defined later in the file)
        setTimeout(function() {
            const acidEl = document.getElementById('ecr_acid');
            const origEl = document.getElementById('ecr_orig');
            const destEl = document.getElementById('ecr_dest');

            if (acidEl) {acidEl.value = acid;}
            if (origEl) {origEl.value = orig;}
            if (destEl) {destEl.value = dest;}

            // Open ECR modal
            if (window.jQuery) {
                window.jQuery('#ecr_modal').modal('show');
            }

            // Trigger Get Flight Data
            setTimeout(function() {
                const getFlightBtn = document.getElementById('ecr_get_flight_btn');
                if (getFlightBtn) {
                    getFlightBtn.click();
                }
            }, 300);
        }, 200);
    }

    // Initialize Flight List context menu when modal is shown
    document.addEventListener('DOMContentLoaded', function() {
    // Initialize when modal opens
        const flightListModal = document.getElementById('gs_flight_list_modal');
        if (flightListModal && window.jQuery) {
            window.jQuery(flightListModal).on('shown.bs.modal', function() {
                initGsFlightListContextMenu();
            });
        }
    });


    function syncTimeFiltersWithGsPeriod() {
        const gsStartEl = document.getElementById('gs_start');
        const gsEndEl = document.getElementById('gs_end');
        const tBasis = document.getElementById('gs_time_basis');
        const tStart = document.getElementById('gs_time_start');
        const tEnd = document.getElementById('gs_time_end');

        if (!gsStartEl || !gsEndEl || !tStart || !tEnd) {return;}

        const gsStartVal = gsStartEl.value || '';
        const gsEndVal = gsEndEl.value || '';

        // Only update filter window if GS period is populated
        if (gsStartVal) {
            tStart.value = gsStartVal;
        }
        if (gsEndVal) {
            tEnd.value = gsEndVal;
        }

        // If no basis chosen yet, default to ETA so the window is actually applied
        if (tBasis && (!tBasis.value || tBasis.value === 'NONE')) {
            tBasis.value = 'ETA';
        }
    }


    function applyGroundStopEdctToTable() {
        const gsEndEl = document.getElementById('gs_end');
        const tbody = document.getElementById('gs_flight_table_body');
        if (!gsEndEl || !tbody) {return;}

        const gsEndStr = gsEndEl.value || '';
        if (!gsEndStr) {return;}
        const gsEndEpoch = parseUtcLocalToEpoch(gsEndStr);
        if (!gsEndEpoch) {return;}

        const apStr = getValue('gs_airports') || '';
        const apTokens = apStr.toUpperCase().split(/\s+/).filter(function(x) { return x.length > 0; });

        const rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
        if (!rows.length) {return;}

        rows.forEach(function(tr) {
            if (!tr) {return;}

            // Use filed departure if available, otherwise fall back to ETD
            const filedStr = tr.getAttribute('data-filed-dep-epoch') || '';
            const etdStr   = tr.getAttribute('data-etd-epoch') || '';
            const baselineStr = filedStr || etdStr;
            if (!baselineStr) {return;}

            const baseline = parseInt(baselineStr, 10);
            if (!baseline || isNaN(baseline)) {return;}

            // Only apply GS to flights whose baseline departure is before the GS end time
            if (baseline >= gsEndEpoch) {return;}

            // If AFFECTED AIRPORTS are specified, require destination to match
            if (apTokens.length) {
                const cells = tr.querySelectorAll('td');
                if (!cells || cells.length < 7) {return;}
                const dest = (cells[6].textContent || '').trim().toUpperCase();
                if (apTokens.indexOf(dest) === -1) {return;}
            }

            const existingEdctStr = tr.getAttribute('data-edct-epoch') || '';
            const existingEdct = existingEdctStr ? parseInt(existingEdctStr, 10) : NaN;
            // If an existing EDCT is already later than the GS end, keep it
            if (!isNaN(existingEdct) && existingEdct > gsEndEpoch) {
                return;
            }

            tr.setAttribute('data-edct-epoch', String(gsEndEpoch));

            const etdCell = tr.querySelector('.gs_etd_cell');
            if (etdCell) {
                const baseEtd = formatZuluFromEpoch(gsEndEpoch);
                etdCell.textContent = PERTII18n.t('gdt.time.estimatedPrefix') + ' ' + baseEtd;
            }

            const edctCell = tr.querySelector('.gs_edct_cell');
            if (edctCell) {
                edctCell.textContent = formatZuluFromEpoch(gsEndEpoch);
            }

            // If we know an ETE for this flight, compute a GS-controlled ETA
            // as EDCT + ETE and update the ETA cell + data-eta-epoch so that
            // the table and flight info remain consistent.
            const eteStr = tr.getAttribute('data-ete-minutes') || '';
            const eteMinutes = eteStr ? parseFloat(eteStr) : NaN;
            if (!isNaN(eteMinutes) && eteMinutes > 0) {
                const newEtaEpoch = gsEndEpoch + eteMinutes * 60 * 1000;
                tr.setAttribute('data-eta-epoch', String(newEtaEpoch));
                const etaCell = tr.querySelector('.gs_eta_cell');
                if (etaCell) {
                    const baseEta = formatZuluFromEpoch(newEtaEpoch);
                    etaCell.textContent = PERTII18n.t('gdt.time.controlledPrefix') + ' ' + baseEta;
                }
            }
        });
    }
    function applyTimeFilterToTable() {
    // NOTE: Local, client-side EDCT simulation is intentionally disabled for the GS workflow.
    // The authoritative simulation/apply path is via the GS API endpoints (gs_simulate.php / gs_apply.php).
        const basisEl = document.getElementById('gs_time_basis');
        const basis = basisEl ? basisEl.value : 'NONE';
        const startStr = document.getElementById('gs_time_start') ? document.getElementById('gs_time_start').value : '';
        const endStr = document.getElementById('gs_time_end') ? document.getElementById('gs_time_end').value : '';

        const tbody = document.getElementById('gs_flight_table_body');
        if (!tbody) {
            return;
        }

        const rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
        if (!rows.length) {
            summarizeFlights([]);
            return;
        }

        const startEpoch = startStr ? parseUtcLocalToEpoch(startStr) : null;
        const endEpoch = endStr ? parseUtcLocalToEpoch(endStr) : null;

        // If no basis or no window, show all rows and summarize them all
        if (!basis || basis === 'NONE' || (!startEpoch && !endEpoch)) {
            rows.forEach(function(tr) {
                tr.style.display = '';
            });
            const allSummaries = rows.map(extractRowSummary).filter(function(r) { return !!r; });
            summarizeFlights(allSummaries);
            updateHorizonCounts(rows, 'data-eta-epoch');
            updateDelayStats(rows);
            updateDelayBreakdowns(rows);
            return;
        }

        let attrName = null;
        if (basis === 'EDCT') {attrName = 'data-edct-epoch';}
        else if (basis === 'ETA') {attrName = 'data-eta-epoch';}
        else if (basis === 'TAKEOFF') {attrName = 'data-takeoff-epoch';}
        else if (basis === 'MFT') {attrName = 'data-mft-epoch';}
        else if (basis === 'VT') {attrName = 'data-vt-epoch';}

        const visibleRows = [];

        rows.forEach(function(tr) {
            if (!attrName) {
                tr.style.display = '';
                visibleRows.push(tr);
                return;
            }
            const v = tr.getAttribute(attrName);
            if (!v) {
                // No timing data yet: keep visible so the user can still see it
                tr.style.display = '';
                visibleRows.push(tr);
                return;
            }
            const t = parseInt(v, 10);
            let ok = true;
            if (startEpoch !== null && !isNaN(startEpoch) && t < startEpoch) {ok = false;}
            if (endEpoch !== null && !isNaN(endEpoch) && t > endEpoch) {ok = false;}
            tr.style.display = ok ? '' : 'none';
            if (ok) {visibleRows.push(tr);}
        });

        const summaries = visibleRows.map(extractRowSummary).filter(function(r) { return !!r; });
        summarizeFlights(summaries);
        updateHorizonCounts(visibleRows, attrName);
        updateDelayStats(visibleRows);
    }

    function collectGsCtdPayload() {
        const tbody = document.getElementById('gs_flight_table_body');
        if (!tbody) {
            return { gs_end_utc: null, updates: [] };
        }

        const gsEndEl = document.getElementById('gs_end');
        const gsEndStr = gsEndEl ? (gsEndEl.value || '') : '';
        const gsEndEpoch = gsEndStr ? parseUtcLocalToEpoch(gsEndStr) : null;

        const rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
        const updates = [];

        rows.forEach(function(tr) {
            if (!tr) {return;}

            const edctStr = tr.getAttribute('data-edct-epoch') || '';
            if (!edctStr) {return;}

            // Use ETD if present, otherwise fall back to filed departure
            const etdStr = tr.getAttribute('data-etd-epoch') || '';
            const filedStr = tr.getAttribute('data-filed-dep-epoch') || '';
            const baselineStr = etdStr || filedStr;
            if (!baselineStr) {return;}

            const edct = parseInt(edctStr, 10);
            const baseline = parseInt(baselineStr, 10);
            if (!edct || isNaN(edct) || !baseline || isNaN(baseline)) {return;}

            // Only send updates for flights with a positive GS delay
            if (edct <= baseline) {return;}

            const tds = tr.querySelectorAll('td');
            if (!tds || tds.length < 7) {return;}

            const callsign = (tds[0].textContent || '').trim().toUpperCase();
            const dep = (tds[5].textContent || '').trim().toUpperCase();
            const dest = (tds[6].textContent || '').trim().toUpperCase();
            if (!callsign) {return;}

            updates.push({
                callsign: callsign,
                dep_icao: dep || null,
                dest_icao: dest || null,
                ctd_utc: formatSqlUtcFromEpoch(edct),
            });
        });

        return {
            gs_end_utc: gsEndEpoch ? formatSqlUtcFromEpoch(gsEndEpoch) : null,
            updates: updates,
        };
    }

    // ---------------------------------------------------------------------
    // GS workflow (ADL / GS sandbox) helpers
    // ---------------------------------------------------------------------

    function toUtcIsoNoMillis(epochMs) {
        if (epochMs == null || isNaN(epochMs)) {return null;}
        // 2025-12-17T12:34:56.789Z -> 2025-12-17T12:34:56Z
        return new Date(epochMs).toISOString().replace(/\.\d{3}Z$/, 'Z');
    }

    function utcIsoFromDatetimeLocal(dtLocalStr) {
        if (!dtLocalStr) {return null;}
        const epoch = parseUtcLocalToEpoch(dtLocalStr);
        if (epoch == null || isNaN(epoch)) {return null;}
        return toUtcIsoNoMillis(epoch);
    }

    function normalizeSqlsrvDateValue(v) {
        if (!v) {return v;}
        if (typeof v === 'string' || typeof v === 'number' || typeof v === 'boolean') {return v;}

        // SQLSRV DateTime objects are often encoded by PHP as:
        // { "date": "YYYY-MM-DD HH:MM:SS.000000", "timezone_type": 3, "timezone": "UTC" }
        if (typeof v === 'object' && typeof v.date === 'string') {
            let s = String(v.date).trim();
            if (!s) {return s;}
            s = s.split('.')[0]; // strip fractional seconds
            if (s.indexOf('T') === -1) {s = s.replace(' ', 'T');}
            if (!/[zZ]$/.test(s)) {s += 'Z';}
            return s;
        }
        return v;
    }

    function normalizeSqlsrvRow(row) {
        if (!row || typeof row !== 'object') {return row;}
        const out = {};
        Object.keys(row).forEach(function(k) {
            out[k] = normalizeSqlsrvDateValue(row[k]);
        });
        return out;
    }

    function normalizeSqlsrvRows(rows) {
        if (!Array.isArray(rows)) {return [];}
        return rows.map(normalizeSqlsrvRow);
    }

    function setGsTableMode(mode) {
        mode = (mode || '').toUpperCase();
        if (mode !== 'GS') {mode = 'LIVE';}
        GS_TABLE_MODE = mode;

        const badge = document.getElementById('gs_adl_mode_badge');
        if (badge) {
            if (mode === 'GS') {
                badge.textContent = PERTII18n.t('gdt.badge.adlGs');
                badge.classList.remove('badge-secondary', 'badge-success');
                badge.classList.add('badge-warning');
            } else {
                badge.textContent = PERTII18n.t('gdt.badge.adlLive');
                badge.classList.remove('badge-secondary', 'badge-warning');
                badge.classList.add('badge-success');
            }
        }

        updateDataSourceLabel();
    }

    // Enable or disable the "Submit to TMI" button based on simulation state
    function setSubmitTmiEnabled(enabled, reason) {
        GS_SIMULATION_READY = !!enabled;
        const btn = document.getElementById('gs_submit_tmi_btn');
        if (!btn) {return;}

        if (enabled) {
            btn.disabled = false;
            btn.classList.remove('btn-outline-secondary');
            btn.classList.add('btn-outline-success');
            btn.title = PERTII18n.t('gdt.gs.submitToTmiTooltip');
        } else {
            btn.disabled = true;
            btn.classList.remove('btn-outline-success');
            btn.classList.add('btn-outline-secondary');
            btn.title = reason || PERTII18n.t('gdt.gs.simulateFirstTooltip');
        }
    }

    function setSendActualEnabled(enabled, reason) {
        setSubmitTmiEnabled(enabled, reason);
        const sendBtn = document.getElementById('gs_send_actual_btn');
        if (sendBtn) {
            sendBtn.disabled = !enabled;
            sendBtn.title = enabled ? PERTII18n.t('gdt.page.activateGsTooltip') : (reason || '');
        }
    }

    function getMultiSelectValues(selectEl) {
        if (!selectEl || !selectEl.selectedOptions) {return [];}
        const out = [];
        Array.prototype.forEach.call(selectEl.selectedOptions, function(opt) {
            if (opt && opt.value) {out.push(String(opt.value).trim());}
        });
        return out.filter(function(x) { return !!x; });
    }

    function collectGsWorkflowPayload() {
        // Collect the shared payload for preview/simulate/apply/purge flows (GS and GDP).
        var programTypeEl = document.getElementById('gs_program_type');
        var payload = {
            gs_program_type: (programTypeEl && programTypeEl.value) ? programTypeEl.value : 'GS',
            gs_name: getValue('gs_name'),
            gs_ctl_element: getValue('gs_ctl_element'),
            gs_element_type: getValue('gs_element_type'),
            gs_adv_number: getValue('gs_adv_number'),

            gs_start: utcIsoFromDatetimeLocal(getValue('gs_start')),
            gs_end: utcIsoFromDatetimeLocal(getValue('gs_end')),

            // GDP-specific fields (included in payload even if empty)
            gs_program_rate: getValue('gs_program_rate'),
            gs_delay_limit: getValue('gs_delay_limit'),
            gs_reserve_rate: getValue('gs_reserve_rate'),

            // Airport tokens (expand centers/TRACONs into airport lists for server-side filters)
            gs_airports: (function() {
                const raw = (getValue('gs_airports') || '').toUpperCase();
                const toks = raw.split(/\s+/).filter(function(x) { return x.length > 0; });
                const expanded = expandAirportTokensWithFacilities(toks);
                return expanded.join(' ');
            })(),
            gs_origin_airports: (function() {
                const raw = (getValue('gs_origin_airports') || '').toUpperCase();
                const toks = raw.split(/\s+/).filter(function(x) { return x.length > 0; });
                const expanded = expandAirportTokensWithFacilities(toks);
                return expanded.join(' ');
            })(),

            // Scope / inclusion
            gs_scope_select: (function() {
                const sel = document.getElementById('gs_scope_select');
                return getMultiSelectValues(sel);
            })(),
            gs_dep_facilities: getValue('gs_dep_facilities'),
            gs_flt_incl_carrier: getValue('gs_flt_incl_carrier'),
            gs_flt_incl_type: getValue('gs_flt_incl_type'),

            // Advisory narrative fields (not required by the API, but useful for logging)
            gs_prob_ext: getValue('gs_prob_ext'),
            gs_impacting_condition: getValue('gs_impacting_condition'),
            gs_comments: getValue('gs_comments'),

            // Flight Exemptions (FSM User Guide exemption criteria)
            exemptions: collectExemptionRules(),
        };

        // Include legacy origin-centers hidden field if present
        const originCenters = getValue('gs_origin_centers');
        if (originCenters) {payload.gs_origin_centers = originCenters;}

        return payload;
    }

    // Collect exemption rules from the UI
    function collectExemptionRules() {
        const rules = {
            // Origin exemptions
            orig_airports: parseSpaceSeparated(getValue('gs_exempt_orig_airports')),
            orig_tracons: parseSpaceSeparated(getValue('gs_exempt_orig_tracons')),
            orig_artccs: parseSpaceSeparated(getValue('gs_exempt_orig_artccs')),

            // Destination exemptions
            dest_airports: parseSpaceSeparated(getValue('gs_exempt_dest_airports')),
            dest_tracons: parseSpaceSeparated(getValue('gs_exempt_dest_tracons')),
            dest_artccs: parseSpaceSeparated(getValue('gs_exempt_dest_artccs')),

            // Aircraft type exemptions
            exempt_jet: isChecked('gs_exempt_type_jet'),
            exempt_turboprop: isChecked('gs_exempt_type_turboprop'),
            exempt_prop: isChecked('gs_exempt_type_prop'),

            // Status exemptions
            exempt_has_edct: isChecked('gs_exempt_has_edct'),
            exempt_active_only: isChecked('gs_exempt_active_only'),
            exempt_depart_within: parseInt(getValue('gs_exempt_depart_within'), 10) || 0,

            // Altitude exemptions
            exempt_alt_below: parseInt(getValue('gs_exempt_alt_below'), 10) || 0,
            exempt_alt_above: parseInt(getValue('gs_exempt_alt_above'), 10) || 0,

            // Individual flight exemptions
            exempt_flights: parseSpaceSeparated(getValue('gs_exempt_flights')),
        };

        return rules;
    }

    function parseSpaceSeparated(str) {
        if (!str) {return [];}
        return str.toUpperCase().split(/[\s,;]+/).filter(function(x) { return x.length > 0; });
    }

    function isChecked(id) {
        const el = document.getElementById(id);
        return el ? el.checked : false;
    }

    // Check if a flight should be exempted based on current rules
    function isFlightExempted(flight, exemptions) {
        if (!exemptions) {return false;}

        const dep = (flight.dep || flight.fp_dept_icao || '').toUpperCase();
        const arr = (flight.arr || flight.fp_dest_icao || '').toUpperCase();
        const depCenter = (flight.dep_center || flight.fp_dept_artcc || AIRPORT_CENTER_MAP[dep] || '').toUpperCase();
        const arrCenter = (flight.arr_center || flight.fp_dest_artcc || AIRPORT_CENTER_MAP[arr] || '').toUpperCase();
        const depTracon = (flight.dep_tracon || AIRPORT_TRACON_MAP[dep] || '').toUpperCase();
        const arrTracon = (flight.arr_tracon || AIRPORT_TRACON_MAP[arr] || '').toUpperCase();
        const callsign = (flight.callsign || '').toUpperCase();
        const hasEdct = !!(flight.edctEpoch || flight.ctd_utc || flight.CTD_UTC);
        const altitude = parseInt(flight.altitude || flight.filed_altitude || 0, 10);

        // Derive aircraft type from equipment code if available
        const acftType = (flight.acft_type || flight.aircraft_type || '').toUpperCase();

        let reason = null;

        // Check origin airport exemption
        if (exemptions.orig_airports && exemptions.orig_airports.length > 0) {
            if (exemptions.orig_airports.indexOf(dep) >= 0) {
                reason = PERTII18n.t('gdt.exemptReason.departingAirport');
            }
        }

        // Check origin TRACON exemption
        if (!reason && exemptions.orig_tracons && exemptions.orig_tracons.length > 0) {
            if (depTracon && exemptions.orig_tracons.indexOf(depTracon) >= 0) {
                reason = PERTII18n.t('gdt.exemptReason.departingTracon');
            }
        }

        // Check origin ARTCC exemption
        if (!reason && exemptions.orig_artccs && exemptions.orig_artccs.length > 0) {
            if (depCenter && exemptions.orig_artccs.indexOf(depCenter) >= 0) {
                reason = PERTII18n.t('gdt.exemptReason.departingCenter');
            }
        }

        // Check destination airport exemption
        if (!reason && exemptions.dest_airports && exemptions.dest_airports.length > 0) {
            if (exemptions.dest_airports.indexOf(arr) >= 0) {
                reason = PERTII18n.t('gdt.exemptReason.destAirport');
            }
        }

        // Check destination TRACON exemption
        if (!reason && exemptions.dest_tracons && exemptions.dest_tracons.length > 0) {
            if (arrTracon && exemptions.dest_tracons.indexOf(arrTracon) >= 0) {
                reason = PERTII18n.t('gdt.exemptReason.destTracon');
            }
        }

        // Check destination ARTCC exemption
        if (!reason && exemptions.dest_artccs && exemptions.dest_artccs.length > 0) {
            if (arrCenter && exemptions.dest_artccs.indexOf(arrCenter) >= 0) {
                reason = PERTII18n.t('gdt.exemptReason.destCenter');
            }
        }

        // Check aircraft type exemptions
        if (!reason) {
            if (exemptions.exempt_jet && isJetAircraft(acftType)) {
                reason = PERTII18n.t('gdt.exemptReason.aircraftJet');
            } else if (exemptions.exempt_turboprop && isTurbopropAircraft(acftType)) {
                reason = PERTII18n.t('gdt.exemptReason.aircraftTurboprop');
            } else if (exemptions.exempt_prop && isPropAircraft(acftType)) {
                reason = PERTII18n.t('gdt.exemptReason.aircraftProp');
            }
        }

        // Check existing EDCT exemption
        if (!reason && exemptions.exempt_has_edct && hasEdct) {
            reason = PERTII18n.t('gdt.exemptReason.existingEdct');
        }

        // Check altitude exemptions
        if (!reason && exemptions.exempt_alt_below > 0) {
            if (altitude > 0 && altitude < exemptions.exempt_alt_below * 100) {
                reason = PERTII18n.t('gdt.exemptReason.altitudeBelow', { fl: exemptions.exempt_alt_below });
            }
        }
        if (!reason && exemptions.exempt_alt_above > 0) {
            if (altitude > 0 && altitude > exemptions.exempt_alt_above * 100) {
                reason = PERTII18n.t('gdt.exemptReason.altitudeAbove', { fl: exemptions.exempt_alt_above });
            }
        }

        // Check individual flight exemption
        if (!reason && exemptions.exempt_flights && exemptions.exempt_flights.length > 0) {
            if (exemptions.exempt_flights.indexOf(callsign) >= 0) {
                reason = PERTII18n.t('gdt.exemptReason.specificFlight');
            }
        }

        // Check departing within minutes exemption
        if (!reason && exemptions.exempt_depart_within > 0) {
            const nowEpoch = Date.now() / 1000;
            const etdEpoch = flight.etdEpoch || 0;
            if (etdEpoch > 0) {
                const minutesUntilDep = (etdEpoch - nowEpoch) / 60;
                if (minutesUntilDep >= 0 && minutesUntilDep <= exemptions.exempt_depart_within) {
                    reason = PERTII18n.t('gdt.exemptReason.departureTime', { minutes: exemptions.exempt_depart_within });
                }
            }
        }

        return reason; // null if not exempted, otherwise the reason string
    }

    // Aircraft type detection helpers
    function isJetAircraft(type) {
        if (!type) {return false;}
        // Common jet prefixes/patterns
        const jetPatterns = /^(B7|A3|A2|B73|B74|B75|B76|B77|B78|A31|A32|A33|A34|A35|A38|CRJ|E1|E2|E7|E9|MD|DC|GLF|C5|C17|CL|LJ|H25|F9|FA|GALX|G[1-6])/i;
        return jetPatterns.test(type);
    }

    function isTurbopropAircraft(type) {
        if (!type) {return false;}
        const tpPatterns = /^(AT[4-7]|DH8|B19|SF3|E12|PC12|C208|PAY|SW[234]|J31|J41|BE[19]|DHC|D328)/i;
        return tpPatterns.test(type);
    }

    function isPropAircraft(type) {
        if (!type) {return false;}
        // If not jet or turboprop, and has common prop patterns
        if (isJetAircraft(type) || isTurbopropAircraft(type)) {return false;}
        const propPatterns = /^(C1[2-8]|C20|C21|PA|BE[2-6]|M20|SR2|DA[24]|P28|AA[15]|C17[02]|C18[02]|C206|C210)/i;
        return propPatterns.test(type);
    }

    // Update exemption count badge and summary
    function updateExemptionSummary() {
        const rules = collectExemptionRules();
        let count = 0;
        const summary = [];

        if (rules.orig_airports.length > 0) { count++; summary.push(PERTII18n.t('gdt.exemption.origApts') + ': ' + rules.orig_airports.join(', ')); }
        if (rules.orig_tracons.length > 0) { count++; summary.push(PERTII18n.t('gdt.exemption.origTracons') + ': ' + rules.orig_tracons.join(', ')); }
        if (rules.orig_artccs.length > 0) { count++; summary.push(PERTII18n.t('gdt.exemption.origArtccs') + ': ' + rules.orig_artccs.join(', ')); }
        if (rules.dest_airports.length > 0) { count++; summary.push(PERTII18n.t('gdt.exemption.destApts') + ': ' + rules.dest_airports.join(', ')); }
        if (rules.dest_tracons.length > 0) { count++; summary.push(PERTII18n.t('gdt.exemption.destTracons') + ': ' + rules.dest_tracons.join(', ')); }
        if (rules.dest_artccs.length > 0) { count++; summary.push(PERTII18n.t('gdt.exemption.destArtccs') + ': ' + rules.dest_artccs.join(', ')); }
        if (rules.exempt_jet) { count++; summary.push(PERTII18n.t('gdt.exemption.aircraftJet')); }
        if (rules.exempt_turboprop) { count++; summary.push(PERTII18n.t('gdt.exemption.aircraftTurboprop')); }
        if (rules.exempt_prop) { count++; summary.push(PERTII18n.t('gdt.exemption.aircraftProp')); }
        if (rules.exempt_has_edct) { count++; summary.push(PERTII18n.t('gdt.exemption.hasEdct')); }
        if (rules.exempt_active_only) { count++; summary.push(PERTII18n.t('gdt.exemption.activeOnly')); }
        if (rules.exempt_depart_within > 0) { count++; summary.push(PERTII18n.t('gdt.exemption.departWithin', { minutes: rules.exempt_depart_within })); }
        if (rules.exempt_alt_below > 0) { count++; summary.push(PERTII18n.t('gdt.exemption.belowFl', { fl: rules.exempt_alt_below })); }
        if (rules.exempt_alt_above > 0) { count++; summary.push(PERTII18n.t('gdt.exemption.aboveFl', { fl: rules.exempt_alt_above })); }
        if (rules.exempt_flights.length > 0) { count++; summary.push(PERTII18n.t('gdt.exemption.flights') + ': ' + rules.exempt_flights.join(', ')); }

        const badge = document.getElementById('gs_exemption_count_badge');
        if (badge) {
            badge.textContent = PERTII18n.t('gdt.exemption.ruleCount', { count: count });
        }

        const summaryEl = document.getElementById('gs_exemption_summary');
        if (summaryEl) {
            if (summary.length > 0) {
                summaryEl.innerHTML = '<span class="text-success"><i class="fas fa-check-circle mr-1"></i>' + summary.join(' | ') + '</span>';
            } else {
                summaryEl.innerHTML = '<span class="text-muted">' + PERTII18n.t('gdt.exemption.noRules') + '</span>';
            }
        }
    }

    function apiPostJson(url, payload) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload || {}),
        }).then(function(res) {
            if (!res.ok) {
                return res.text().then(function(t) {
                    throw new Error('HTTP ' + res.status + ' ' + res.statusText + (t ? (': ' + t) : ''));
                });
            }
            return res.json();
        });
    }

    function clearGsFlightTable(message) {
        const tbody = document.getElementById('gs_flight_table_body');
        if (!tbody) {return;}
        const msg = message || PERTII18n.t('gdt.table.noFlightsLoaded');
        tbody.innerHTML = '<tr><td colspan="8" class="text-muted text-center py-3">' + escapeHtml(msg) + '</td></tr>';
        summarizeFlights([]);
        updateDelayStats([]);
        updateHorizonCounts([], 'data-eta-epoch');
        updateDelayBreakdowns([]);

        // Reset flight count badge
        const countBadge = document.getElementById('gs_flight_count_badge');
        if (countBadge) {countBadge.textContent = '0';}
    }

    function renderFlightsFromAdlRowsForWorkflow(adlRows, sourceLabel) {
        const tbody = document.getElementById('gs_flight_table_body');
        const countBadge = document.getElementById('gs_flight_count_badge');
        if (!tbody) {return;}

        // Store raw ADL rows for sorting functionality
        GS_MATCHING_FLIGHTS = adlRows || [];

        // Determine airport coloring based on the user's AFFECTED AIRPORTS input
        const apStr = getValue('gs_airports') || '';
        const apTokens = apStr.toUpperCase().split(/\s+/).filter(function(x) { return x.length > 0; });
        const airports = expandAirportTokensWithFacilities(apTokens);
        const airportColors = buildAirportColorMap(airports);
        updateAirportsLegendAndInput(airports, airportColors);

        // Display-side filters (API already filters, but this keeps the UI consistent)
        const originStr = getValue('gs_origin_airports') || '';
        const originTokens = originStr.toUpperCase().split(/\s+/).filter(function(x) { return x.length > 0; });
        const originAirports = expandAirportTokensWithFacilities(originTokens);

        const carriers = getValue('gs_flt_incl_carrier');
        const acTypeEl = document.getElementById('gs_flt_incl_type');
        const acType = acTypeEl ? acTypeEl.value : 'ALL';

        const cfg = {
            arrivals: airports,
            originAirports: originAirports,
            carriers: carriers,
            acType: acType,
        };

        let rows = [];
        (adlRows || []).forEach(function(raw) {
            if (!raw) {return;}
            const r = filterAdlFlight(raw, cfg);
            if (!r) {return;}

            r.source = sourceLabel || r.source || '';
            r._adl = { raw: raw, filtered: r };

            // Add exemption reason for non-eligible flights
            if (r.gsFlag === 1) {
                r.exemptReason = null;
            } else {
                const phase = (r.flightStatus || '').toUpperCase();
                switch (phase) {
                    case 'DEPARTED': r.exemptReason = 'DEP'; break;
                    case 'ENROUTE': r.exemptReason = 'ENR'; break;
                    case 'DESCENDING': r.exemptReason = 'DESC'; break;
                    case 'ARRIVED': r.exemptReason = 'ARR'; break;
                    case 'DISCONNECTED': r.exemptReason = 'DISC'; break;
                    default: r.exemptReason = 'AIR';
                }
            }
            rows.push(r);
        });

        // Filter based on display mode (GS_SHOW_ALL_FLIGHTS)
        const allRows = rows.slice(); // Keep copy for counts
        if (!GS_SHOW_ALL_FLIGHTS) {
            rows = rows.filter(function(r) {
                return r.gsFlag === 1;
            });
        }

        // Update table header to show/hide exemption column
        const thead = tbody.closest('table').querySelector('thead');
        if (thead) {
            const headerRow = thead.querySelector('tr');
            const exemptHeader = headerRow.querySelector('.gs-exempt-header');
            if (GS_SHOW_ALL_FLIGHTS) {
                if (!exemptHeader) {
                    const th = document.createElement('th');
                    th.className = 'gs-exempt-header';
                    th.textContent = PERTII18n.t('gdt.table.exempt');
                    th.title = PERTII18n.t('gdt.table.exemptTooltip');
                    headerRow.appendChild(th);
                }
            } else {
                if (exemptHeader) {
                    exemptHeader.remove();
                }
            }
        }

        // Count eligible vs exempt for display
        const eligibleCount = allRows.filter(function(r) { return r.gsFlag === 1; }).length;
        const exemptCount = allRows.filter(function(r) { return r.gsFlag !== 1; }).length;

        // Update flight count display
        const countLabel = document.getElementById('gs_flight_count_label');
        if (countLabel) {
            if (GS_SHOW_ALL_FLIGHTS) {
                countLabel.innerHTML = '<span class="text-success">' + PERTII18n.t('gdt.table.eligibleCount', { count: eligibleCount }) + '</span> + <span class="text-muted">' + PERTII18n.t('gdt.table.exemptCount', { count: exemptCount }) + '</span>';
            } else {
                countLabel.textContent = PERTII18n.t('gdt.table.eligibleFlights', { count: eligibleCount });
            }
        }

        // Apply current sort order
        const field = GS_MATCHING_SORT.field;
        const order = GS_MATCHING_SORT.order;
        rows.sort(function(a, b) {
            let valA, valB;
            switch (field) {
                case 'acid':
                    valA = (a.callsign || '').toLowerCase();
                    valB = (b.callsign || '').toLowerCase();
                    break;
                case 'etd':
                    valA = a.etdEpoch || 0;
                    valB = b.etdEpoch || 0;
                    break;
                case 'edct':
                    valA = a.edctEpoch || 0;
                    valB = b.edctEpoch || 0;
                    break;
                case 'eta':
                    valA = a.roughEtaEpoch || 0;
                    valB = b.roughEtaEpoch || 0;
                    break;
                case 'dcenter':
                    valA = (a.dep_artcc || AIRPORT_CENTER_MAP[a.dep] || '').toLowerCase();
                    valB = (b.dep_artcc || AIRPORT_CENTER_MAP[b.dep] || '').toLowerCase();
                    break;
                case 'orig':
                    valA = (a.dep || '').toLowerCase();
                    valB = (b.dep || '').toLowerCase();
                    break;
                case 'dest':
                    valA = (a.arr || '').toLowerCase();
                    valB = (b.arr || '').toLowerCase();
                    break;
                default:
                    valA = a.roughEtaEpoch || Number.MAX_SAFE_INTEGER;
                    valB = b.roughEtaEpoch || Number.MAX_SAFE_INTEGER;
            }
            if (valA < valB) {return order === 'asc' ? -1 : 1;}
            if (valA > valB) {return order === 'asc' ? 1 : -1;}
            return (a.callsign || '').localeCompare(b.callsign || '');
        });

        // Store processed rows for re-sorting
        GS_MATCHING_ROWS = rows;

        // Update flight count badge
        if (countBadge) {
            countBadge.textContent = rows.length;
        }

        GS_FLIGHT_ROW_INDEX = {};
        if (!rows.length) {
            const colSpan = GS_SHOW_ALL_FLIGHTS ? 9 : 8;
            tbody.innerHTML = '<tr><td colspan="' + colSpan + '" class="text-muted text-center py-3">' + PERTII18n.t('gdt.table.noFlightsMatching') + '</td></tr>';
            applyTimeFilterToTable();
            return;
        }

        let html = '';
        rows.forEach(function(r) {
            const cs = r.callsign || '';
            const rowId = makeRowIdForCallsign(cs);
            GS_FLIGHT_ROW_INDEX[rowId] = r;

            const dep = (r.dep || '').toUpperCase();
            const arr = (r.arr || '').toUpperCase();
            const center = r.dep_artcc || AIRPORT_CENTER_MAP[dep] || '';
            const destColor = airportColors[arr] || '';

            // Build status column from control type and delay status
            let statusText = '';
            if (r._adl && r._adl.raw) {
                const ctlType = r._adl.raw.ctl_type || r._adl.raw.CTL_TYPE || '';
                const delayStatus = r._adl.raw.delay_status || r._adl.raw.DELAY_STATUS || '';
                const flightStatus = r.flightStatus || '';
                statusText = ctlType || delayStatus || flightStatus || '';
            }

            // Row styling for exempt flights
            let rowClass = 'gs-flight-row';
            let exemptCell = '';
            if (GS_SHOW_ALL_FLIGHTS) {
                if (r.gsFlag !== 1) {
                    rowClass += ' table-secondary text-muted';
                    const reasonTitle = {
                        'DEP': PERTII18n.t('gdt.exempt.departed'),
                        'ENR': PERTII18n.t('gdt.exempt.enroute'),
                        'DESC': PERTII18n.t('gdt.exempt.descending'),
                        'ARR': PERTII18n.t('gdt.exempt.arrived'),
                        'DISC': PERTII18n.t('gdt.exempt.disconnected'),
                        'AIR': PERTII18n.t('gdt.exempt.airborne'),
                    };
                    exemptCell = '<td><span class="badge badge-secondary" title="' + (reasonTitle[r.exemptReason] || PERTII18n.t('tmi.exempt')) + '">' + (r.exemptReason || 'EX') + '</span></td>';
                } else {
                    exemptCell = '<td><span class="badge badge-success">OK</span></td>';
                }
            }

            html += '<tr id="' + escapeHtml(rowId) + '" ' +
                'data-callsign="' + escapeHtml(cs) + '" ' +
                'data-orig="' + escapeHtml(dep) + '" ' +
                'data-dest="' + escapeHtml(arr) + '" ' +
                'data-route="' + escapeHtml(r.route || '') + '" ' +
                'data-filed-dep-epoch="' + (r.filedDepEpoch != null ? String(r.filedDepEpoch) : '') + '" ' +
                'data-etd-epoch="' + (r.etdEpoch != null ? String(r.etdEpoch) : '') + '" ' +
                'data-edct-epoch="' + (r.edctEpoch != null ? String(r.edctEpoch) : '') + '" ' +
                'data-eta-epoch="' + (r.roughEtaEpoch != null ? String(r.roughEtaEpoch) : '') + '" ' +
                'data-takeoff-epoch="' + (r.tkofEpoch != null ? String(r.tkofEpoch) : '') + '" ' +
                'data-mft-epoch="' + (r.mftEpoch != null ? String(r.mftEpoch) : '') + '" ' +
                'data-vt-epoch="' + (r.vtEpoch != null ? String(r.vtEpoch) : '') + '" ' +
                'data-ete-minutes="' + (r.eteMinutes != null ? String(r.eteMinutes) : '') + '" ' +
                'class="' + rowClass + '"' +
                '>' +
                '<td><strong>' + escapeHtml(cs) + '</strong></td>' +
                '<td class="gs_etd_cell">' + (r.etdEpoch ? (escapeHtml(r.etdPrefix || '') + ' ' + escapeHtml(formatZuluFromEpoch(r.etdEpoch))) : '') + '</td>' +
                '<td class="gs_edct_cell">' + (r.edctEpoch ? escapeHtml(formatZuluFromEpoch(r.edctEpoch)) : '') + '</td>' +
                '<td class="gs_eta_cell" style="color:' + escapeHtml(destColor) + ';">' +
                    (r.roughEtaEpoch ? (escapeHtml(r.etaPrefix || '') + ' ' + escapeHtml(formatZuluFromEpoch(r.roughEtaEpoch))) : '') + '</td>' +
                '<td>' + escapeHtml(center) + '</td>' +
                '<td>' + escapeHtml(dep) + '</td>' +
                '<td style="color:' + escapeHtml(destColor) + ';">' + escapeHtml(arr) + '</td>' +
                '<td>' + escapeHtml(statusText) + ' <a href="#" class="ecr-link text-info ml-1" title="' + PERTII18n.t('gdt.ecr.title') + '" style="font-size:0.85em;"><i class="fas fa-clock"></i></a></td>' +
                exemptCell +
                '</tr>';
        });

        tbody.innerHTML = html;

        // Apply SimTraffic enrichment opportunistically
        enrichFlightsWithSimTraffic(rows);

        applyTimeFilterToTable();
    }

    function showConfirmDialog(title, text, confirmText, icon) {
        if (window.Swal) {
            return window.Swal.fire({
                title: title,
                text: text || '',
                icon: icon || 'warning',
                showCancelButton: true,
                confirmButtonText: confirmText || PERTII18n.t('common.confirm'),
            }).then(function(result) {
                return !!(result && result.isConfirmed);
            });
        }
        return Promise.resolve(window.confirm((title ? title + '\\n\\n' : '') + (text || '')));
    }

    /**
     * Show detailed GS activation confirmation with flight list and advisory
     */
    function showGsActivationConfirmation(workflowPayload) {
        if (!window.Swal) {
            return showConfirmDialog(
                'Activate GS Program ' + GS_CURRENT_PROGRAM_ID + '?',
                'This will activate the GS program and apply EDCTs to affected flights.',
                'Activate & Publish',
                'warning',
            );
        }

        // Get simulation data
        const simData = GS_LAST_SIMULATION_DATA || {};
        const flights = simData.flights || [];
        const totalFlights = simData.total || flights.length;
        const affectedFlights = simData.affected || 0;
        const maxDelay = simData.max_delay || 0;
        const avgDelay = simData.avg_delay || 0;
        const totalDelay = simData.total_delay || 0;
        const exemptFlights = simData.exempt || 0;

        // Get advisory text from preview
        const advPreviewEl = document.getElementById('gs_advisory_preview');
        const advisoryText = advPreviewEl ? (advPreviewEl.textContent || advPreviewEl.innerText || '') : '';

        // Build flight list table (show first 15 flights)
        let flightTableHtml = '';
        if (flights.length > 0) {
            flightTableHtml = '<div class="table-responsive" style="max-height: 200px; overflow-y: auto;">';
            flightTableHtml += '<table class="table table-sm table-striped mb-0" style="font-size: 0.85em;">';
            flightTableHtml += '<thead class="thead-light"><tr>';
            flightTableHtml += '<th>' + PERTII18n.t('gdt.flight.carrier') + '</th><th>' + PERTII18n.t('gdt.flight.departure') + '</th><th>' + PERTII18n.t('gdt.flight.arrival') + '</th><th>' + PERTII18n.t('gdt.flight.type') + '</th><th>' + PERTII18n.t('gdt.flight.edctLabel') + '</th><th>' + PERTII18n.t('gdt.flight.delayMin') + '</th>';
            flightTableHtml += '</tr></thead><tbody>';

            const displayFlights = flights.slice(0, 15);
            displayFlights.forEach(function(f) {
                let edct = f.edct || f.ctd_utc || f.ctd || '';
                if (edct && typeof edct === 'string') {
                    // Format as HHMM
                    const edctDate = new Date(edct);
                    if (!isNaN(edctDate.getTime())) {
                        edct = String(edctDate.getUTCHours()).padStart(2, '0') +
                               String(edctDate.getUTCMinutes()).padStart(2, '0') + 'Z';
                    }
                }
                const delay = f.delay || f.program_delay_min || f.edct_delay || 0;
                const delayClass = delay > 30 ? 'text-danger font-weight-bold' : (delay > 15 ? 'text-warning' : '');
                flightTableHtml += '<tr>';
                flightTableHtml += '<td><strong>' + escapeHtml(f.callsign || '') + '</strong></td>';
                flightTableHtml += '<td>' + escapeHtml(f.dep || f.dep_icao || f.dep_airport || '') + '</td>';
                flightTableHtml += '<td>' + escapeHtml(f.arr || f.dest_icao || f.arr_airport || '') + '</td>';
                flightTableHtml += '<td>' + escapeHtml(f.ac_type || f.aircraft_type || '') + '</td>';
                flightTableHtml += '<td>' + escapeHtml(edct) + '</td>';
                flightTableHtml += '<td class="' + delayClass + '">' + PERTII18n.t('gdt.dashboard.delayMinutes', { delay: delay }) + '</td>';
                flightTableHtml += '</tr>';
            });
            flightTableHtml += '</tbody></table>';
            if (flights.length > 15) {
                flightTableHtml += '<div class="text-muted small text-center py-1">' + PERTII18n.t('gdt.gs.andMoreFlights', { count: flights.length - 15 }) + '</div>';
            }
            flightTableHtml += '</div>';
        } else {
            flightTableHtml = '<div class="alert alert-warning mb-2"><i class="fas fa-exclamation-triangle"></i> ' + PERTII18n.t('gdt.gs.noFlightsInSimulation') + '</div>';
        }

        // Build the confirmation modal HTML
        let html = '<div class="text-left">';

        // Summary cards
        html += '<div class="row mb-3">';
        html += '<div class="col-4 text-center">';
        html += '<div class="card bg-primary text-white"><div class="card-body py-2">';
        html += '<div class="h4 mb-0">' + affectedFlights + '</div><small>' + PERTII18n.t('gdt.gs.controlled') + '</small></div></div>';
        html += '</div>';
        html += '<div class="col-4 text-center">';
        html += '<div class="card bg-danger text-white"><div class="card-body py-2">';
        html += '<div class="h4 mb-0">' + maxDelay + '</div><small>' + PERTII18n.t('gdt.gs.maxDelayMin') + '</small></div></div>';
        html += '</div>';
        html += '<div class="col-4 text-center">';
        html += '<div class="card bg-info text-white"><div class="card-body py-2">';
        html += '<div class="h4 mb-0">' + avgDelay + '</div><small>' + PERTII18n.t('gdt.gs.avgDelayMin') + '</small></div></div>';
        html += '</div>';
        html += '</div>';

        // Flight list section
        html += '<div class="card mb-3">';
        html += '<div class="card-header py-2"><strong><i class="fas fa-plane"></i> ' + PERTII18n.t('gdt.gs.affectedFlights', { count: totalFlights }) + '</strong></div>';
        html += '<div class="card-body p-2">' + flightTableHtml + '</div>';
        html += '</div>';

        // Advisory preview section
        html += '<div class="card mb-2">';
        html += '<div class="card-header py-2"><strong><i class="fas fa-bullhorn"></i> ' + PERTII18n.t('gdt.gs.advisoryToPublish') + '</strong></div>';
        html += '<div class="card-body p-2">';
        html += '<pre class="mb-0 small" style="max-height: 150px; overflow-y: auto; background: #f8f9fa; padding: 8px; border-radius: 4px; font-size: 0.8em;">' + escapeHtml(advisoryText) + '</pre>';
        html += '</div></div>';

        html += '<div class="alert alert-warning mb-0"><i class="fas fa-exclamation-circle"></i> <strong>' + PERTII18n.t('common.confirm') + ':</strong> ' + PERTII18n.t('gdt.gs.activateConfirmation') + '</div>';

        html += '</div>';

        return window.Swal.fire({
            title: '<i class="fas fa-plane-departure text-warning"></i> ' + PERTII18n.t('gdt.gs.activateTitle'),
            html: html,
            icon: null,
            width: 700,
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-check"></i> ' + PERTII18n.t('gdt.gs.activateAndPublish'),
            cancelButtonText: PERTII18n.t('common.cancel'),
            confirmButtonColor: '#28a745',
            cancelButtonColor: '#6c757d',
            customClass: {
                popup: 'gs-confirm-popup',
            },
        }).then(function(result) {
            return !!(result && result.isConfirmed);
        });
    }

    function handleGsPreview() {
        const statusEl = document.getElementById('gs_adl_status');
        if (statusEl) {statusEl.textContent = PERTII18n.t('gdt.gs.creatingProgram');}

        setGsTableMode('LIVE');
        const workflowPayload = collectGsWorkflowPayload();

        // Validate required fields
        if (!workflowPayload.gs_airports) {
            if (statusEl) {statusEl.textContent = PERTII18n.t('gdt.validation.enterAirportsFirst');}
            return Promise.resolve();
        }
        if (!workflowPayload.gs_start || !workflowPayload.gs_end) {
            if (statusEl) {statusEl.textContent = PERTII18n.t('gdt.validation.enterStartEndTimes');}
            return Promise.resolve();
        }
        // Determine program type from form selector (defaults to GS)
        var programTypeEl = document.getElementById('gs_program_type');
        var selectedProgramType = (programTypeEl && programTypeEl.value) ? programTypeEl.value : 'GS';

        // Build scope_json from departure facilities (optional — omitted for non-US airports)
        var depFacilitiesRaw = (workflowPayload.gs_dep_facilities || '').trim();
        var depFacilitiesArr = depFacilitiesRaw.split(/\s+/).filter(function(x) { return x.length > 0 && x !== 'ALL'; });
        var scopeJson = depFacilitiesArr.length > 0 ? { origin_centers: depFacilitiesArr } : null;

        // Build create payload for GDT API
        var createPayload = {
            ctl_element: workflowPayload.gs_ctl_element || workflowPayload.gs_airports.split(' ')[0],
            program_type: selectedProgramType,
            start_utc: workflowPayload.gs_start,
            end_utc: workflowPayload.gs_end,
            scope_type: 'TIER',
            scope_tier: 2,
            scope_json: scopeJson,
            exempt_airborne: true,
            impacting_condition: workflowPayload.gs_impacting_condition || 'WEATHER',
            cause_text: workflowPayload.gs_comments || (selectedProgramType === 'GS' ? 'Ground Stop' : 'Ground Delay Program'),
            created_by: 'TMU',
        };

        // Add GDP-specific fields if applicable
        if (selectedProgramType !== 'GS') {
            var rateEl = document.getElementById('gs_program_rate');
            var delayCapEl = document.getElementById('gs_delay_limit');
            if (rateEl && rateEl.value) createPayload.program_rate = parseInt(rateEl.value, 10);
            if (delayCapEl && delayCapEl.value) createPayload.delay_limit_min = parseInt(delayCapEl.value, 10);
        }

        // Orphan prevention: reuse existing MODELING program instead of creating a new one
        var createStep;
        if (GS_CURRENT_PROGRAM_ID && GS_CURRENT_PROGRAM_STATUS === 'MODELING') {
            if (statusEl) {statusEl.textContent = PERTII18n.t('gdt.gs.reusingProgram', { id: GS_CURRENT_PROGRAM_ID });}
            createStep = Promise.resolve({ status: 'ok', data: { program_id: GS_CURRENT_PROGRAM_ID } });
        } else {
            createStep = apiPostJson(GS_API.create, createPayload);
        }

        // Step 1: Create or reuse MODELING program (advisory assigned on activation)
        return createStep
            .then(function(createResp) {
                if (createResp.status !== 'ok' || !createResp.data || !createResp.data.program_id) {
                    throw new Error(createResp.message || 'Failed to create program');
                }

                var programId = createResp.data.program_id;
                GS_CURRENT_PROGRAM_ID = programId;
                GS_CURRENT_PROGRAM_STATUS = 'MODELING';

                if (statusEl) {statusEl.textContent = PERTII18n.t('gdt.gs.programCreated', { id: programId });}

                // Step 2: Model the program to get affected flights (lightweight preview)
                // dep_facilities is optional — when empty/ALL, model.php returns all flights to destination
                return apiPostJson(GS_API.model, {
                    program_id: programId,
                    dep_facilities: workflowPayload.gs_dep_facilities || 'ALL',
                });
            })
            .then(function(modelResp) {
                if (modelResp.status !== 'ok') {
                    throw new Error(modelResp.message || 'Failed to model GS program');
                }

                let flights = (modelResp.data && modelResp.data.flights) || [];
                flights = normalizeSqlsrvRows(flights);

                // Store simulation data for flight list
                storeSimulationData(modelResp.data);

                renderFlightsFromAdlRowsForWorkflow(flights, 'GS-PREVIEW');

                // Apply GS EDCT to table rows so delay stats are computed
                applyGroundStopEdctToTable();

                if (statusEl) {
                    const summary = modelResp.data.summary || {};
                    statusEl.textContent = PERTII18n.t('gdt.gs.previewStatus', {
                        flights: flights.length,
                        controlled: summary.controlled_flights || 0,
                        exempt: summary.exempt_flights || 0,
                        programId: GS_CURRENT_PROGRAM_ID
                    });
                }
                buildAdvisory();

                // Refresh demand chart with program data
                var apStr = getValue('gs_airports') || getValue('gs_ctl_element') || '';
                var demandApt = apStr.toUpperCase().split(/\s+/).filter(function(x) { return x.length >= 3; })[0];
                if (demandApt) { loadGsDemandData(demandApt); }

                // Enable simulate since we have a PROPOSED program
                setSendActualEnabled(false, PERTII18n.t('gdt.gs.simulateToFinalize'));

                // Update workflow stepper
                setWorkflowState('preview');
                loadActiveProgramsDashboard();
            })
            .catch(function(err) {
                console.error('GS preview failed', err);
                if (statusEl) {statusEl.textContent = PERTII18n.t('gdt.gs.previewFailed') + ': ' + (err && err.message ? err.message : err);}
                clearGsFlightTable(PERTII18n.t('gdt.gs.previewFailedShort'));
                GS_CURRENT_PROGRAM_ID = null;
                GS_CURRENT_PROGRAM_STATUS = null;
            });
    }

    function handleGsSimulate() {
        // If in what-if mode, run a dry_run simulation instead
        if (GS_WHAT_IF_MODE && GS_CURRENT_PROGRAM_ID) {
            return handleWhatIfSimulate();
        }

        const statusEl = document.getElementById('gs_adl_status');

        // If no program exists yet, run Preview first to create one
        if (!GS_CURRENT_PROGRAM_ID) {
            if (statusEl) {statusEl.textContent = PERTII18n.t('gdt.gs.noProgramCreatingPreview');}
            return handleGsPreview().then(function() {
                if (GS_CURRENT_PROGRAM_ID) {
                    // Now run simulate with the new program
                    return handleGsSimulate();
                }
            });
        }

        if (statusEl) {statusEl.textContent = PERTII18n.t('gdt.gs.modelingProgram', { id: GS_CURRENT_PROGRAM_ID });}

        // Run simulate.php which creates tmi_flight_control records (required before activation).
        // simulate.php reads scope from the program's scope_json stored at creation time.
        return apiPostJson(GS_API.simulate, {
            program_id: GS_CURRENT_PROGRAM_ID,
        })
            .then(function(simResp) {
                if (simResp.status !== 'ok') {
                    throw new Error(simResp.message || 'Failed to simulate program');
                }

                let flights = (simResp.data && simResp.data.flights) || [];
                flights = normalizeSqlsrvRows(flights);

                // Store simulation data for flight list viewing
                storeSimulationData(simResp.data);

                var progType = simResp.data.program_type || 'GS';
                setGsTableMode(progType);
                renderFlightsFromAdlRowsForWorkflow(flights, progType + '-SIM');

                // Apply GS EDCT to table rows so delay stats are computed
                if (progType === 'GS') applyGroundStopEdctToTable();

                if (statusEl) {
                    const summary = simResp.data.summary || {};
                    statusEl.textContent = PERTII18n.t('gdt.gs.simulatedStatus', {
                        flights: flights.length,
                        maxDelay: summary.max_delay_min || 0,
                        programId: GS_CURRENT_PROGRAM_ID
                    });
                }
                buildAdvisory();

                // Refresh demand chart with program data
                var apStr = getValue('gs_airports') || getValue('gs_ctl_element') || '';
                var demandApt = apStr.toUpperCase().split(/\s+/).filter(function(x) { return x.length >= 3; })[0];
                if (demandApt) { loadGsDemandData(demandApt); }

                // Enable "Send Actual" button now that simulation is ready
                GS_SIMULATION_READY = true;
                setSendActualEnabled(true);

                // Update workflow stepper
                setWorkflowState('model');
                loadActiveProgramsDashboard();
            })
            .catch(function(err) {
                console.error('GS simulate failed', err);
                if (statusEl) {statusEl.textContent = PERTII18n.t('gdt.gs.simulateFailed') + ': ' + (err && err.message ? err.message : err);}
                clearGsFlightTable(PERTII18n.t('gdt.gs.simulateFailedShort'));
                // Keep Send Actual disabled on simulation failure
                setSendActualEnabled(false, PERTII18n.t('gdt.gs.simulationFailedRetry'));
            });
    }

    function handleWhatIfSimulate() {
        var statusEl = document.getElementById('gs_adl_status');
        if (statusEl) statusEl.textContent = PERTII18n.t('gdt.dashboard.whatIfRunning');

        // Collect what-if overrides from the form
        var body = {
            program_id: GS_CURRENT_PROGRAM_ID,
            dry_run: true
        };

        // Check if user changed end time vs. cached original
        var formEnd = getValue('gs_end') || '';

        if (formEnd) {
            body.what_if_end_utc = new Date(formEnd).toISOString();
        }

        // GDP-specific fields (rate, delay cap) — only present for GDP forms
        if (GS_WHAT_IF_CACHED_PROGRAM) {
            var rateEl = document.getElementById('gs_program_rate');
            var dcEl = document.getElementById('gs_delay_limit');

            if (rateEl && rateEl.value) {
                var origRate = parseInt(GS_WHAT_IF_CACHED_PROGRAM.program_rate) || 0;
                if (parseInt(rateEl.value) !== origRate) {
                    body.what_if_rate = parseInt(rateEl.value);
                }
            }
            if (dcEl && dcEl.value) {
                var origDc = parseInt(GS_WHAT_IF_CACHED_PROGRAM.delay_limit_min) || 0;
                if (parseInt(dcEl.value) !== origDc) {
                    body.what_if_delay_cap = parseInt(dcEl.value);
                }
            }
        }

        return apiPostJson(GS_API.simulate, body)
            .then(function(resp) {
                if (resp.status !== 'ok') {
                    throw new Error(resp.message || 'What-if simulation failed');
                }

                var flights = (resp.data && resp.data.flights) || [];
                flights = normalizeSqlsrvRows(flights);

                storeSimulationData(resp.data);
                var progType = resp.data.program_type || 'GS';
                setGsTableMode(progType);
                renderFlightsFromAdlRowsForWorkflow(flights, progType + '-SIM');
                if (progType === 'GS') applyGroundStopEdctToTable();

                if (statusEl) {
                    var summary = resp.data.summary || {};
                    var overrides = resp.data.what_if_overrides;
                    var overrideText = overrides ? ' | Overrides: ' + JSON.stringify(overrides) : '';
                    statusEl.textContent = PERTII18n.t('gdt.dashboard.whatIfStatus', { flights: flights.length, maxDelay: summary.max_delay_min || 0 }) + overrideText;
                }

                buildAdvisory();
            })
            .catch(function(err) {
                console.error('What-if simulate failed', err);
                if (statusEl) statusEl.textContent = PERTII18n.t('gdt.dashboard.whatIfFailed') + ': ' + (err && err.message ? err.message : err);
            });
    }

    function handleGsSendActual() {
        const statusEl = document.getElementById('gs_adl_status');

        // Require simulation to be run first
        if (!GS_SIMULATION_READY) {
            if (window.Swal) {
                window.Swal.fire({
                    icon: 'warning',
                    title: PERTII18n.t('gdt.gs.simulationRequired'),
                    text: PERTII18n.t('gdt.gs.simulationRequiredText'),
                    confirmButtonText: PERTII18n.t('common.ok'),
                });
            } else {
                alert(PERTII18n.t('gdt.gs.simulationRequiredText'));
            }
            if (statusEl) {statusEl.textContent = PERTII18n.t('gdt.gs.runSimulateFirst');}
            return Promise.resolve();
        }

        // Require a program to activate
        if (!GS_CURRENT_PROGRAM_ID) {
            if (statusEl) {statusEl.textContent = PERTII18n.t('gdt.gs.noProgramToActivate');}
            return Promise.resolve();
        }

        const workflowPayload = collectGsWorkflowPayload();

        // Build detailed confirmation modal with flight list and advisory
        return showGsActivationConfirmation(workflowPayload).then(function(confirmed) {
            if (!confirmed) {return;}

            if (statusEl) {statusEl.textContent = PERTII18n.t('gdt.gs.activatingProgram', { id: GS_CURRENT_PROGRAM_ID });}

            return apiPostJson(GS_API.activate, {
                program_id: GS_CURRENT_PROGRAM_ID,
                activated_by: 'TMU',
                publish_swim: true,
                publish_discord: true,
            })
                .then(function(activateResp) {
                    if (activateResp.status !== 'ok') {
                        throw new Error(activateResp.message || 'Failed to activate GS program');
                    }

                    GS_CURRENT_PROGRAM_STATUS = 'ACTIVE';

                    const program = activateResp.data.program || {};
                    const flightsData = activateResp.data.flights || {};
                    const powerRun = activateResp.data.power_run || {};
                    const advisory = activateResp.data.advisory || {};

                    const flightCount = flightsData.controlled || program.controlled_flights || 0;

                    // Build comprehensive status message
                    let statusMsg = PERTII18n.t('gdt.gs.activeStatus', {
                        programId: GS_CURRENT_PROGRAM_ID,
                        controlled: flightCount
                    });
                    if (powerRun.max_delay) {
                        statusMsg += ' | ' + PERTII18n.t('gdt.gs.maxDelayLabel', { delay: powerRun.max_delay });
                    }
                    if (activateResp.data.swim_published) {
                        statusMsg += ' | ' + PERTII18n.t('gdt.gs.publishedToSwim');
                    }
                    if (statusEl) {
                        statusEl.textContent = statusMsg;
                    }

                    setGsTableMode('LIVE');

                    // Disable Send Actual - program is now active
                    GS_SIMULATION_READY = false;
                    setSendActualEnabled(false, PERTII18n.t('gdt.gs.gsActiveDisabled'));

                    // Update the advisory preview with the finalized advisory text
                    if (advisory.text) {
                        const advPreview = document.getElementById('gs_advisory_preview');
                        if (advPreview) {
                            advPreview.textContent = advisory.text;
                        }
                    }

                    // Show success notification with full results
                    showGsActivationSuccess(activateResp.data, workflowPayload);

                    // Update workflow stepper
                    setWorkflowState('active');
                    loadActiveProgramsDashboard();

                    // Show the GS Flight List modal with affected flights
                    const flightListData = {
                        flights: flightsData.flights || [],
                        total: flightsData.total || 0,
                        affected: flightsData.controlled || 0,
                        max_delay: flightsData.max_delay || powerRun.max_delay || 0,
                        avg_delay: flightsData.avg_delay || powerRun.avg_delay || 0,
                        total_delay: flightsData.total_delay || powerRun.total_delay || 0,
                    };
                    showGsFlightListModal(flightListData, workflowPayload);
                })
                .catch(function(err) {
                    console.error('GS activate failed', err);
                    if (statusEl) {statusEl.textContent = PERTII18n.t('gdt.gs.activateFailed') + ': ' + (err && err.message ? err.message : err);}
                    if (window.Swal) {
                        window.Swal.fire({ icon: 'error', title: PERTII18n.t('gdt.gs.activateFailed'), text: (err && err.message) ? err.message : String(err) });
                    } else {
                        alert(PERTII18n.t('gdt.gs.activateFailed') + ': ' + (err && err.message ? err.message : err));
                    }
                });
        });
    }

    /**
     * Submit GS to TMI Publishing for coordination
     * Collects the GS data and redirects to TMI Publishing page
     */
    function handleGsSubmitToTmi() {
        const statusEl = document.getElementById('gs_adl_status');

        // Require simulation to be run first
        if (!GS_SIMULATION_READY) {
            if (window.Swal) {
                window.Swal.fire({
                    icon: 'warning',
                    title: PERTII18n.t('gdt.gs.simulationRequired'),
                    text: PERTII18n.t('gdt.gs.simulationRequiredTmiText'),
                    confirmButtonText: PERTII18n.t('common.ok'),
                });
            } else {
                alert(PERTII18n.t('gdt.gs.simulationRequiredTmiText'));
            }
            if (statusEl) {statusEl.textContent = PERTII18n.t('gdt.gs.runSimulateFirstTmi');}
            return;
        }

        // Require a program to submit
        if (!GS_CURRENT_PROGRAM_ID) {
            if (statusEl) {statusEl.textContent = PERTII18n.t('gdt.gs.noProgramToSubmit');}
            return;
        }

        const workflowPayload = collectGsWorkflowPayload();

        // Build the TMI Publishing handoff data
        const tmiHandoff = {
            type: 'GS',
            program_type: 'GS',
            program_id: GS_CURRENT_PROGRAM_ID,
            ctl_element: workflowPayload.gs_ctl_element || '',
            element_type: workflowPayload.gs_element_type || 'AIRPORT',
            start_time: workflowPayload.gs_start,
            end_time: workflowPayload.gs_end,
            airports: workflowPayload.gs_airports || '',
            origin_airports: workflowPayload.gs_origin_airports || '',
            dep_facilities: workflowPayload.gs_dep_facilities || '',
            scope_select: workflowPayload.gs_scope_select || [],
            flt_incl_carrier: workflowPayload.gs_flt_incl_carrier || '',
            flt_incl_type: workflowPayload.gs_flt_incl_type || 'ALL',
            impacting_condition: workflowPayload.gs_impacting_condition || '',
            prob_extension: workflowPayload.gs_prob_ext || '',
            comments: workflowPayload.gs_comments || '',
            exemptions: workflowPayload.exemptions || {},

            // Include simulation results from GS_LAST_SIMULATION_DATA
            simulation_data: GS_LAST_SIMULATION_DATA || null,

            // Include flight data (from GS_LAST_SIMULATION_DATA)
            flights: (GS_LAST_SIMULATION_DATA && GS_LAST_SIMULATION_DATA.flights) || [],

            // Advisory text if available
            advisory_preview: document.getElementById('gs_advisory_preview')
                ? document.getElementById('gs_advisory_preview').textContent
                : '',

            // Metadata
            source: 'GDT',
            created_at: new Date().toISOString(),
        };

        // Store in sessionStorage for TMI Publishing to pick up
        try {
            sessionStorage.setItem('tmi_gs_handoff', JSON.stringify(tmiHandoff));

            if (statusEl) {statusEl.textContent = PERTII18n.t('gdt.gs.redirectingToTmi');}

            // Navigate to TMI Publishing page with GS/GDP tab active
            window.location.href = 'tmi-publish.php?tab=gdp&source=gdt&type=gs&program_id=' + GS_CURRENT_PROGRAM_ID + '#gsgdpPanel';

        } catch (err) {
            console.error('Failed to store TMI handoff data:', err);
            if (statusEl) {statusEl.textContent = PERTII18n.t('gdt.gs.handoffFailed') + ': ' + err.message;}
            if (window.Swal) {
                window.Swal.fire({
                    icon: 'error',
                    title: PERTII18n.t('gdt.gs.handoffFailedTitle'),
                    text: PERTII18n.t('gdt.gs.handoffFailedText', { message: err.message }),
                });
            } else {
                alert(PERTII18n.t('gdt.gs.handoffFailed') + ': ' + err.message);
            }
        }
    }

    /**
     * Show success notification after GS activation with full results
     */
    function showGsActivationSuccess(data, workflowPayload) {
        const program = data.program || {};
        const flightsData = data.flights || {};
        const powerRun = data.power_run || {};
        const advisory = data.advisory || {};

        // Build HTML for the success modal
        let html = '<div class="text-left">';
        html += '<h5 class="text-success"><i class="fas fa-check-circle"></i> ' + PERTII18n.t('gdt.gs.gsActivated') + '</h5>';

        // Program Info
        html += '<div class="mb-3">';
        html += '<strong>' + PERTII18n.t('gdt.gs.program') + ':</strong> ' + escapeHtml(program.program_name || 'GS-' + program.ctl_element) + '<br>';
        html += '<strong>' + PERTII18n.t('gdt.gs.airport') + ':</strong> ' + escapeHtml(program.ctl_element) + '<br>';
        html += '<strong>' + PERTII18n.t('gdt.gs.period') + ':</strong> ' + formatZuluFromIso(program.start_utc) + ' - ' + formatZuluFromIso(program.end_utc);
        html += '</div>';

        // Power Run Results
        html += '<div class="card bg-light mb-3">';
        html += '<div class="card-header py-1"><strong><i class="fas fa-chart-bar"></i> ' + PERTII18n.t('gdt.gs.powerRunResults') + '</strong></div>';
        html += '<div class="card-body py-2">';
        html += '<table class="table table-sm mb-0">';
        html += '<tr><td>' + PERTII18n.t('gdt.gs.controlledFlights') + '</td><td class="text-right font-weight-bold">' + (flightsData.controlled || 0) + '</td></tr>';
        html += '<tr><td>' + PERTII18n.t('gdt.gs.exemptFlights') + '</td><td class="text-right">' + (flightsData.exempt || 0) + '</td></tr>';
        html += '<tr><td>' + PERTII18n.t('gdt.gs.airborneFlights') + '</td><td class="text-right">' + (flightsData.airborne || 0) + '</td></tr>';
        html += '<tr><td>' + PERTII18n.t('gdt.gs.totalDelay') + '</td><td class="text-right">' + (powerRun.total_delay || 0) + ' ' + PERTII18n.t('units.min') + '</td></tr>';
        html += '<tr><td>' + PERTII18n.t('gdt.gs.maxDelay') + '</td><td class="text-right text-danger font-weight-bold">' + (powerRun.max_delay || 0) + ' ' + PERTII18n.t('units.min') + '</td></tr>';
        html += '<tr><td>' + PERTII18n.t('gdt.gs.avgDelay') + '</td><td class="text-right">' + (powerRun.avg_delay || 0) + ' ' + PERTII18n.t('units.min') + '</td></tr>';
        html += '</table>';
        html += '</div></div>';

        // Publishing Status
        html += '<div class="mb-3">';
        if (data.swim_published) {
            html += '<span class="badge badge-success mr-2"><i class="fas fa-cloud-upload-alt"></i> Published to VATSWIM</span>';
        }
        if (data.discord_posted) {
            html += '<span class="badge badge-primary"><i class="fab fa-discord"></i> Posted to Discord</span>';
        }
        html += '</div>';

        // Advisory Preview (collapsible)
        if (advisory.text) {
            html += '<details>';
            html += '<summary class="mb-2" style="cursor:pointer;"><strong>' + PERTII18n.t('gdt.gs.advisoryText') + '</strong></summary>';
            html += '<pre class="bg-dark text-light p-2 rounded" style="font-size:0.75rem; white-space:pre-wrap;">' + escapeHtml(advisory.text) + '</pre>';
            html += '</details>';
        }

        html += '</div>';

        if (window.Swal) {
            window.Swal.fire({
                icon: 'success',
                title: PERTII18n.t('gdt.gs.gsIssued'),
                html: html,
                width: 600,
                confirmButtonText: PERTII18n.t('gdt.gs.viewFlightList'),
                showCancelButton: true,
                cancelButtonText: PERTII18n.t('common.close'),
            });
        }
    }

    function handleGsPurgeAll() {
        const statusEl = document.getElementById('gs_adl_status');

        return showConfirmDialog(
            PERTII18n.t('gdt.purge.purgeAllTitle'),
            PERTII18n.t('gdt.purge.purgeAllText'),
            PERTII18n.t('gdt.purge.purgeAllBtn'),
            'warning',
        ).then(function(confirmed) {
            if (!confirmed) {return;}

            if (statusEl) {statusEl.textContent = PERTII18n.t('gdt.purge.fetchingPrograms');}

            // Step 1: Get list of ACTIVE and PROPOSED programs
            return fetch(GS_API.list + '?status=ACTIVE,PROPOSED')
                .then(function(res) { return res.json(); })
                .then(function(listResp) {
                    if (listResp.status !== 'ok') {
                        throw new Error(listResp.message || PERTII18n.t('gdt.purge.fetchProgramListFailed'));
                    }

                    const programs = (listResp.data && listResp.data.programs) || [];
                    if (!programs.length) {
                        if (statusEl) {statusEl.textContent = PERTII18n.t('gdt.purge.noProgramsToPurge');}
                        GS_CURRENT_PROGRAM_ID = null;
                        GS_CURRENT_PROGRAM_STATUS = null;
                        return;
                    }

                    if (statusEl) {statusEl.textContent = PERTII18n.t('gdt.purge.purgingPrograms', { count: programs.length });}

                    // Step 2: Purge each program sequentially
                    const purgePromises = programs.map(function(prog) {
                        return apiPostJson(GS_API.purge, {
                            program_id: prog.program_id,
                            purged_by: 'TMU',
                        });
                    });

                    return Promise.all(purgePromises);
                })
                .then(function(results) {
                    if (!results) {return;} // No programs to purge

                    const purged = results.filter(function(r) { return r && r.status === 'ok'; }).length;

                    if (statusEl) {
                        statusEl.textContent = PERTII18n.t('gdt.purge.purgedCount', { count: purged });
                    }

                    // Clear current program state
                    GS_CURRENT_PROGRAM_ID = null;
                    GS_CURRENT_PROGRAM_STATUS = null;
                    GS_SIMULATION_READY = false;

                    setGsTableMode('LIVE');
                    setSendActualEnabled(false, PERTII18n.t('gdt.purge.allPurgedCreateNew'));
                    clearGsFlightTable(PERTII18n.t('gdt.purge.allPurged'));
                })
                .catch(function(err) {
                    console.error('GS purge all failed', err);
                    if (statusEl) {statusEl.textContent = PERTII18n.t('gdt.purge.purgeAllFailed') + ': ' + (err && err.message ? err.message : err);}
                });
        });
    }

    function handleGsPurgeLocal() {
        const statusEl = document.getElementById('gs_adl_status');

        // Require a program to purge
        if (!GS_CURRENT_PROGRAM_ID) {
            if (statusEl) {statusEl.textContent = PERTII18n.t('gdt.purge.noCurrentProgram');}
            return Promise.resolve();
        }

        const programId = GS_CURRENT_PROGRAM_ID;

        return showConfirmDialog(
            PERTII18n.t('gdt.purge.purgeLocalTitle', { id: programId }),
            PERTII18n.t('gdt.purge.purgeLocalText'),
            PERTII18n.t('gdt.purge.purgeLocalBtn'),
            'warning',
        ).then(function(confirmed) {
            if (!confirmed) {return;}

            if (statusEl) {statusEl.textContent = PERTII18n.t('gdt.purge.purgingProgram', { id: programId });}

            return apiPostJson(GS_API.purge, {
                program_id: programId,
                purged_by: 'TMU',
            })
                .then(function(purgeResp) {
                    if (purgeResp.status !== 'ok') {
                        throw new Error(purgeResp.message || PERTII18n.t('gdt.purge.purgeGsProgramFailed'));
                    }

                    const purgedProgram = purgeResp.data && purgeResp.data.program;

                    if (statusEl) {
                        statusEl.textContent = PERTII18n.t('gdt.purge.programPurgedStatus', { id: programId }) +
                            (purgedProgram ? ' (' + purgedProgram.adv_number + ')' : '');
                    }

                    // Clear current program state
                    GS_CURRENT_PROGRAM_ID = null;
                    GS_CURRENT_PROGRAM_STATUS = null;
                    GS_SIMULATION_READY = false;

                    setGsTableMode('LIVE');
                    setSendActualEnabled(false, PERTII18n.t('gdt.purge.programPurgedCreateNew'));
                    clearGsFlightTable(PERTII18n.t('gdt.purge.programPurged'));
                })
                .catch(function(err) {
                    console.error('GS purge failed', err);
                    if (statusEl) {statusEl.textContent = PERTII18n.t('gdt.purge.purgeFailed') + ': ' + (err && err.message ? err.message : err);}
                });
        });
    }


    function applyGsToAdl() {
        const statusEl = document.getElementById('gs_adl_status');
        const updatedLbl = document.getElementById('gs_flights_updated');

        // Make sure GS-derived EDCT values are reflected in the table first
        applyGroundStopEdctToTable();

        const payload = collectGsCtdPayload();
        if (!payload.updates || !payload.updates.length) {
            if (statusEl) {statusEl.textContent = PERTII18n.t('gdt.adl.noFlightsToApply');}
            return;
        }

        if (statusEl) {
            statusEl.textContent = PERTII18n.t('gdt.adl.applyingGsToAdl');
        }

        fetch('api/tmi/gs_apply_ctd.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(payload),
        })
            .then(function(res) {
                if (!res.ok) {
                    throw new Error('HTTP ' + res.status + ' from gs_apply_ctd.php');
                }
                return res.json();
            })
            .then(function(data) {
                const updated = data && typeof data.updated === 'number' ? data.updated : 0;
                if (statusEl) {
                    statusEl.textContent = PERTII18n.t('gdt.adl.gsApplied', { count: updated });
                }
                if (updatedLbl) {
                    updatedLbl.textContent = PERTII18n.t('gdt.adl.ctdUpdated', { count: updated });
                }

                return refreshAdl().then(function() {
                    if (getValue('gs_airports').trim()) {
                        loadVatsimFlightsForCurrentGs();
                    }
                });
            })
            .catch(function(err) {
                console.error('Error applying GS to ADL', err);
                if (statusEl) {
                    statusEl.textContent = PERTII18n.t('gdt.adl.errorApplyingGs');
                }
            });
    }


    function loadAirportInfo() {
        return fetch('assets/data/apts.csv', { cache: 'no-cache' })
            .then(function(res) { return res.text(); })
            .then(function(text) {
                AIRPORT_CENTER_MAP = {};
                CENTER_AIRPORTS_MAP = {};
                AIRPORT_TRACON_MAP = {};
                TRACON_AIRPORTS_MAP = {};

                if (!text) {return;}

                const lines = text.replace(/\r/g, '').split('\n').filter(function(l) { return l.trim().length > 0; });
                if (!lines.length) {return;}

                const header = lines[0].split(',');
                function idx(name) {
                    for (let i = 0; i < header.length; i++) {
                        const col = header[i] ? header[i].replace(/^\uFEFF/, '') : '';
                        if (col === name) {return i;}
                    }
                    return -1;
                }
                const idxArpt = idx('ARPT_ID');
                const idxIcao = idx('ICAO_ID');
                const idxCenter = idx('RESP_ARTCC_ID');
                const idxTracon = idx('Consolidated Approach ID');

                for (let i = 1; i < lines.length; i++) {
                    const line = lines[i];
                    if (!line.trim()) {continue;}
                    const parts = line.split(',');
                    const get = (idx) => (idx >= 0 && idx < parts.length ? parts[idx].trim().toUpperCase() : '');
                    const icao = get(idxIcao);
                    if (!icao) {continue;}

                    const arpt = get(idxArpt);
                    if (icao && arpt) {
                        AIRPORT_IATA_MAP[icao] = arpt;
                    }

                    const center = get(idxCenter);
                    const tracon = get(idxTracon);

                    if (center) {
                        AIRPORT_CENTER_MAP[icao] = center;
                        if (!CENTER_AIRPORTS_MAP[center]) {CENTER_AIRPORTS_MAP[center] = [];}
                        CENTER_AIRPORTS_MAP[center].push(icao);
                    }
                    if (tracon) {
                        AIRPORT_TRACON_MAP[icao] = tracon;
                        if (!TRACON_AIRPORTS_MAP[tracon]) {TRACON_AIRPORTS_MAP[tracon] = [];}
                        TRACON_AIRPORTS_MAP[tracon].push(icao);
                    }
                }
            })
            .catch(function(err) {
                console.error('Error loading apts.csv', err);
            });
    }


    function updateDataSourceLabel() {
        const el = document.getElementById('gs_data_source');
        if (!el) {return;}

        const flightListLabel = (GS_TABLE_MODE === 'GS')
            ? PERTII18n.t('gdt.dataSource.gsProgramMode')
            : PERTII18n.t('gdt.dataSource.liveAdl');

        let adlCache = PERTII18n.t('gdt.dataSource.notLoaded');
        if (GS_ADL && (GS_ADL.snapshotUtc || (GS_ADL.raw && (GS_ADL.raw.snapshot_utc || GS_ADL.raw.snapshotUtc)))) {
            adlCache = GS_ADL.snapshotUtc || (GS_ADL.raw.snapshot_utc || GS_ADL.raw.snapshotUtc);
        } else if (GS_ADL_LOADING) {
            adlCache = PERTII18n.t('common.loading');
        }

        let programInfo = '';
        if (GS_CURRENT_PROGRAM_ID) {
            programInfo = ' | Program ID: ' + GS_CURRENT_PROGRAM_ID + ' (' + (GS_CURRENT_PROGRAM_STATUS || '?') + ')';
        }

        el.textContent = PERTII18n.t('gdt.dataSource.label', { mode: flightListLabel, programInfo: programInfo, cache: adlCache });
    }

    function refreshAdl() {
        const statusEl = document.getElementById('gs_adl_status');
        if (statusEl) {
            statusEl.textContent = PERTII18n.t('gdt.adl.loading');
            statusEl.classList.add('adl-refreshing');
        }

        GS_ADL_LOADING = true;

        // Store previous data for buffered update
        const previousAdl = GS_ADL;

        const p = fetch(GS_ADL_API_URL, { cache: 'no-cache' })
            .then(function(res) {
                if (!res.ok) {
                    throw new Error('HTTP ' + res.status + ' from ADL API');
                }
                return res.json();
            })
            .then(function(data) {
                let flights = [];
                if (data) {
                    if (Array.isArray(data.flights)) {
                        flights = data.flights;
                    } else if (Array.isArray(data.rows)) {
                        flights = data.rows;
                    }
                }

                const snapshotStr = data && (data.snapshot_utc || data.snapshotUtc || data.snapshot || null);
                let snapshotDate = null;
                if (snapshotStr) {
                    const tmp = new Date(snapshotStr);
                    if (!isNaN(tmp.getTime())) {
                        snapshotDate = tmp;
                    }
                }

                // Buffered update: only update if we got data, or had no prior data
                const hadPriorData = previousAdl && Array.isArray(previousAdl.flights) && previousAdl.flights.length > 0;

                if (flights.length > 0 || !hadPriorData) {
                    GS_ADL = {
                        raw: data || {},
                        flights: flights,
                        snapshotUtc: snapshotDate || new Date(),
                    };
                } else {
                    // Keep previous data but update timestamp
                    console.log('[TMI] Empty ADL response, keeping previous data (' + previousAdl.flights.length + ' flights)');
                    if (previousAdl) {
                        previousAdl.snapshotUtc = new Date();
                    }
                }

                if (statusEl) {
                    statusEl.textContent = PERTII18n.t('gdt.adl.updated', { time: GS_ADL ? GS_ADL.snapshotUtc.toUTCString() : 'N/A' });
                    statusEl.classList.remove('adl-refreshing');
                }
                return GS_ADL;
            })
            .catch(function(err) {
                console.error('Error loading vATCSCC ADL', err);
                if (statusEl) {
                    statusEl.textContent = PERTII18n.t('gdt.adl.refreshError');
                    statusEl.classList.remove('adl-refreshing');
                }
                // BUFFERED: Don't set GS_ADL = null - keep previous data
                // GS_ADL = null;  // REMOVED - causes data flash
                console.log('[TMI] Keeping previous ADL data due to error');
                // Don't throw - return previous data instead
                return GS_ADL || previousAdl;
            })
            .finally(function() {
                GS_ADL_LOADING = false;
                try {
                    updateDataSourceLabel();
                } catch (e) {
                    console.error('Error updating data source label', e);
                }
            });

        GS_ADL_PROMISE = p;
        return p;
    }


    function ensureVatsimData() {
        if (GS_VATSIM && GS_VATSIM.pilots && GS_VATSIM.prefiles) {
            return Promise.resolve(GS_VATSIM);
        }
        if (GS_VATSIM_LOADING && GS_VATSIM_PROMISE) {
            return GS_VATSIM_PROMISE;
        }

        GS_VATSIM_LOADING = true;

        const p = fetch('https://data.vatsim.net/v3/vatsim-data.json', { cache: 'no-cache' })
            .then(function(res) {
                if (!res.ok) {
                    throw new Error('HTTP ' + res.status + ' from VATSIM data API');
                }
                return res.json();
            })
            .then(function(data) {
                GS_VATSIM = data || {};
                return GS_VATSIM;
            })
            .catch(function(err) {
                console.error('Error loading VATSIM data', err);
                GS_VATSIM = null;
                throw err;
            })
            .finally(function() {
                GS_VATSIM_LOADING = false;
            });

        GS_VATSIM_PROMISE = p;
        return p;
    }

    function ensureAdlThen(callback) {
        if (GS_ADL && Array.isArray(GS_ADL.flights)) {
            callback();
            return;
        }
        if (GS_ADL_LOADING && GS_ADL_PROMISE) {
            GS_ADL_PROMISE.then(function() {
                if (GS_ADL && Array.isArray(GS_ADL.flights)) {
                    callback();
                }
            }).catch(function(err) {
                console.error('ADL load failed', err);
            });
            return;
        }
        refreshAdl().then(function() {
            if (GS_ADL && Array.isArray(GS_ADL.flights)) {
                callback();
            }
        }).catch(function(err) {
            console.error('ADL load failed', err);
        });
    }

    function updateRowWithSimTraffic(callsign, data) {
        if (!callsign || !data) {return;}
        const cs = String(callsign).toUpperCase();
        const rowId = makeRowIdForCallsign(cs);
        const rowEl = document.getElementById(rowId);
        if (!rowEl) {return;}

        const dep = data.departure || {};
        const arr = data.arrival || {};

        const edctEpoch = parseSimtrafficTimeToEpoch(dep.edct);
        const tkofEpoch = parseSimtrafficTimeToEpoch(dep.takeoff_time);
        const etaEpoch = parseSimtrafficTimeToEpoch(arr.eta);
        const mftEpoch = parseSimtrafficTimeToEpoch(arr.mft || arr.eta_mf);
        const vtEpoch = parseSimtrafficTimeToEpoch(arr.vt || arr.eta_vt || arr.eta_vertex || arr.eta_vertex_time || arr.vertex_time);

        if (edctEpoch) {
            const edctCell = rowEl.querySelector('.gs_edct_cell');
            if (edctCell) {
                edctCell.textContent = formatZuluFromEpoch(edctEpoch);
            }
            rowEl.setAttribute('data-edct-epoch', String(edctEpoch));
        }
        if (etaEpoch) {
            const etaCell = rowEl.querySelector('.gs_eta_cell');
            if (etaCell) {
                etaCell.textContent = formatZuluFromEpoch(etaEpoch);
            }
            rowEl.setAttribute('data-eta-epoch', String(etaEpoch));
        }
        if (tkofEpoch) {
            rowEl.setAttribute('data-takeoff-epoch', String(tkofEpoch));
        }
        if (mftEpoch) {
            rowEl.setAttribute('data-mft-epoch', String(mftEpoch));
        }
        if (vtEpoch) {
            rowEl.setAttribute('data-vt-epoch', String(vtEpoch));
        }
    }

    /* --------------------------------------------------------------------
   SimTraffic: throttled + cached + circuit-breaker
   - Avoids pulling every render/refresh
   - Caches per-callsign results (memory + localStorage) with TTL
   - Backs off / cools down quickly on 429/5xx bursts
-------------------------------------------------------------------- */

    const GS_SIMTRAFFIC_CFG = {
        localStorageKey: 'vATCSCC_gs_simtraffic_cache_v1',
        minEnrichIntervalMs: 90 * 1000,        // don't enqueue every render/refresh
        perCallsignMinIntervalMs: 8 * 60 * 1000,
        cacheTtlMs: 10 * 60 * 1000,            // success TTL
        negativeCacheTtlMs: 2 * 60 * 1000,     // error TTL (per-callsign)
        maxRequestsPerEnrich: 60,              // cap per render/refresh
        maxQueueSize: 250,
        baseDelayMs: 650,                      // ~1.5 req/sec per client
        maxDelayMs: 10000,
        errorBackoffMs: 2500,
        errorWindowMs: 60 * 1000,
        maxErrorsPerWindow: 10,
        maxConsecutiveErrors: 5,
        cooldownMsOn429: 90 * 1000,
        cooldownMsOn5xxBurst: 3 * 60 * 1000,
    };

    let GS_SIMTRAFFIC_LAST_ENRICH_MS = 0;
    let GS_SIMTRAFFIC_COOLDOWN_UNTIL_MS = 0;
    let GS_SIMTRAFFIC_CONSEC_ERRORS = 0;
    let GS_SIMTRAFFIC_ERROR_WINDOW = []; // ms timestamps

    const GS_SIMTRAFFIC_CACHE_TS = {};     // callsign -> ms timestamp (success)
    const GS_SIMTRAFFIC_NEG_CACHE_TS = {}; // callsign -> ms timestamp (error/negative cache)
    let GS_SIMTRAFFIC_QUEUE_SET = {};    // callsign -> true (dedupe)
    let GS_SIMTRAFFIC_NEXT_DELAY_MS = GS_SIMTRAFFIC_CFG.baseDelayMs;

    let GS_SIMTRAFFIC_PERSIST_TIMER = null;

    function _nowMs() { return (new Date()).getTime(); }

    function loadSimTrafficLocalCache() {
        try {
            if (typeof localStorage === 'undefined') {return;}
            const raw = localStorage.getItem(GS_SIMTRAFFIC_CFG.localStorageKey);
            if (!raw) {return;}

            const obj = JSON.parse(raw);
            if (!obj || typeof obj !== 'object') {return;}

            const now = _nowMs();
            Object.keys(obj).forEach(function(cs) {
                const entry = obj[cs];
                if (!entry || typeof entry !== 'object') {return;}
                const ts = Number(entry.t);
                if (!ts || isNaN(ts)) {return;}
                if ((now - ts) > GS_SIMTRAFFIC_CFG.cacheTtlMs) {return;}

                const data = entry.d;
                if (!data || typeof data !== 'object') {return;}

                GS_SIMTRAFFIC_CACHE[cs] = data;
                GS_SIMTRAFFIC_CACHE_TS[cs] = ts;
            });
        } catch (e) {
        // ignore storage failures
        }
    }

    function persistSimTrafficLocalCache() {
        GS_SIMTRAFFIC_PERSIST_TIMER = null;
        try {
            if (typeof localStorage === 'undefined') {return;}

            const now = _nowMs();
            const out = {};
            let keys = Object.keys(GS_SIMTRAFFIC_CACHE_TS);

            // Keep newest 500 entries max to avoid bloat
            keys.sort(function(a, b) { return (GS_SIMTRAFFIC_CACHE_TS[b] || 0) - (GS_SIMTRAFFIC_CACHE_TS[a] || 0); });
            keys = keys.slice(0, 500);

            keys.forEach(function(cs) {
                const ts = GS_SIMTRAFFIC_CACHE_TS[cs];
                if (!ts || (now - ts) > GS_SIMTRAFFIC_CFG.cacheTtlMs) {return;}
                const data = GS_SIMTRAFFIC_CACHE[cs];
                if (!data) {return;}
                out[cs] = { t: ts, d: data };
            });

            localStorage.setItem(GS_SIMTRAFFIC_CFG.localStorageKey, JSON.stringify(out));
        } catch (e) {
        // ignore storage failures
        }
    }

    function schedulePersistSimTrafficLocalCache() {
        if (GS_SIMTRAFFIC_PERSIST_TIMER) {return;}
        GS_SIMTRAFFIC_PERSIST_TIMER = setTimeout(persistSimTrafficLocalCache, 1200);
    }

    function isFreshSuccessCache(cs, now) {
        if (!Object.prototype.hasOwnProperty.call(GS_SIMTRAFFIC_CACHE, cs)) {return false;}
        const ts = GS_SIMTRAFFIC_CACHE_TS[cs];
        if (!ts) {return false;}
        return (now - ts) <= GS_SIMTRAFFIC_CFG.cacheTtlMs;
    }

    function isRecentNegativeCache(cs, now) {
        const ts = GS_SIMTRAFFIC_NEG_CACHE_TS[cs];
        if (!ts) {return false;}
        return (now - ts) <= GS_SIMTRAFFIC_CFG.negativeCacheTtlMs;
    }

    function recordNegativeCache(cs) {
        GS_SIMTRAFFIC_NEG_CACHE_TS[cs] = _nowMs();
    }

    function pruneErrorWindow(now) {
        const cutoff = now - GS_SIMTRAFFIC_CFG.errorWindowMs;
        GS_SIMTRAFFIC_ERROR_WINDOW = GS_SIMTRAFFIC_ERROR_WINDOW.filter(function(t) { return t >= cutoff; });
    }

    function computeRetryAfterSeconds(retryAfterHeader) {
        if (!retryAfterHeader) {return null;}
        const s = String(retryAfterHeader).trim();
        if (!s) {return null;}

        // seconds
        if (/^\d+$/.test(s)) {
            const v = parseInt(s, 10);
            return isNaN(v) ? null : v;
        }

        // HTTP-date
        const d = new Date(s);
        if (isNaN(d.getTime())) {return null;}
        const now = new Date();
        let sec = Math.ceil((d.getTime() - now.getTime()) / 1000);
        if (sec < 0) {sec = 0;}
        return sec;
    }

    function noteSimTrafficError(statusOrErr, retryAfterSeconds) {
        const now = _nowMs();

        GS_SIMTRAFFIC_ERROR_COUNT++;
        GS_SIMTRAFFIC_CONSEC_ERRORS++;

        GS_SIMTRAFFIC_ERROR_WINDOW.push(now);
        pruneErrorWindow(now);

        let status = null;
        if (typeof statusOrErr === 'number') {status = statusOrErr;}
        else if (statusOrErr && typeof statusOrErr.status === 'number') {status = statusOrErr.status;}

        // Cooldown logic
        let cooldownMs = GS_SIMTRAFFIC_CFG.errorBackoffMs;

        if (status === 429 || status === 503) {
            cooldownMs = GS_SIMTRAFFIC_CFG.cooldownMsOn429;
            if (retryAfterSeconds != null && !isNaN(retryAfterSeconds)) {
                cooldownMs = Math.max(cooldownMs, retryAfterSeconds * 1000);
            }
        } else if (status === 0 || (status != null && status >= 500)) {
        // 5xx burst
            if (GS_SIMTRAFFIC_CONSEC_ERRORS >= 3) {
                cooldownMs = GS_SIMTRAFFIC_CFG.cooldownMsOn5xxBurst;
            }
        }

        GS_SIMTRAFFIC_COOLDOWN_UNTIL_MS = Math.max(GS_SIMTRAFFIC_COOLDOWN_UNTIL_MS, now + cooldownMs);

        // Increase spacing between requests after errors (exponential-ish)
        GS_SIMTRAFFIC_NEXT_DELAY_MS = Math.min(
            GS_SIMTRAFFIC_CFG.maxDelayMs,
            Math.max(GS_SIMTRAFFIC_CFG.errorBackoffMs, Math.floor(GS_SIMTRAFFIC_NEXT_DELAY_MS * 1.7)),
        );

        // Hard stop for this browser session
        if (
            (GS_SIMTRAFFIC_ERROR_COUNT >= GS_SIMTRAFFIC_ERROR_CUTOFF) ||
        (GS_SIMTRAFFIC_CONSEC_ERRORS >= GS_SIMTRAFFIC_CFG.maxConsecutiveErrors) ||
        (GS_SIMTRAFFIC_ERROR_WINDOW.length >= GS_SIMTRAFFIC_CFG.maxErrorsPerWindow)
        ) {
            if (GS_SIMTRAFFIC_ENABLED) {
                GS_SIMTRAFFIC_ENABLED = false;
                GS_SIMTRAFFIC_QUEUE = [];
                GS_SIMTRAFFIC_QUEUE_SET = {};
                console.warn('SimTraffic disabled after repeated errors; no further SimTraffic calls this session.');
            }
        }
    }

    function noteSimTrafficSuccess() {
        GS_SIMTRAFFIC_CONSEC_ERRORS = 0;
        GS_SIMTRAFFIC_NEXT_DELAY_MS = GS_SIMTRAFFIC_CFG.baseDelayMs;
    }

    function enqueueSimTrafficFetch(callsign) {
        if (!GS_SIMTRAFFIC_ENABLED) {return;}
        if (!callsign) {return;}

        const cs = String(callsign).toUpperCase();
        if (!cs) {return;}

        const now = _nowMs();

        // Fresh success cache -> update row immediately (no new request)
        if (isFreshSuccessCache(cs, now)) {
            try {
                updateRowWithSimTraffic(cs, GS_SIMTRAFFIC_CACHE[cs]);
            } catch (e) {}
            return;
        }

        // Recently failed -> don't hammer
        if (isRecentNegativeCache(cs, now)) {return;}

        // Per-callsign min interval even if TTL expired
        const lastOk = GS_SIMTRAFFIC_CACHE_TS[cs];
        if (lastOk && (now - lastOk) < GS_SIMTRAFFIC_CFG.perCallsignMinIntervalMs) {return;}

        // Dedupe queue
        if (GS_SIMTRAFFIC_QUEUE_SET[cs]) {return;}

        if (GS_SIMTRAFFIC_QUEUE.length >= GS_SIMTRAFFIC_CFG.maxQueueSize) {return;}

        GS_SIMTRAFFIC_QUEUE_SET[cs] = true;
        GS_SIMTRAFFIC_QUEUE.push(cs);
        processSimTrafficQueue();
    }

    function processSimTrafficQueue() {
        if (!GS_SIMTRAFFIC_ENABLED) {return;}
        if (GS_SIMTRAFFIC_BUSY) {return;}
        if (!GS_SIMTRAFFIC_QUEUE.length) {return;}

        const now = _nowMs();
        if (now < GS_SIMTRAFFIC_COOLDOWN_UNTIL_MS) {
            setTimeout(processSimTrafficQueue, Math.max(250, GS_SIMTRAFFIC_COOLDOWN_UNTIL_MS - now));
            return;
        }

        const callsign = GS_SIMTRAFFIC_QUEUE.shift();
        if (callsign && GS_SIMTRAFFIC_QUEUE_SET[callsign]) {
            delete GS_SIMTRAFFIC_QUEUE_SET[callsign];
        }
        if (!callsign) {return;}

        GS_SIMTRAFFIC_BUSY = true;

        fetch('api/tmi/simtraffic_flight.php?cs=' + encodeURIComponent(callsign), { cache: 'no-cache' })
            .then(function(res) {
                if (!res.ok) {
                    const ra = computeRetryAfterSeconds(res.headers ? res.headers.get('Retry-After') : null);
                    noteSimTrafficError(res.status, ra);
                    recordNegativeCache(callsign);
                    throw new Error('HTTP ' + res.status + ' from SimTraffic proxy');
                }
                return res.json();
            })
            .then(function(data) {
                if (data) {
                    GS_SIMTRAFFIC_CACHE[callsign] = data;
                    GS_SIMTRAFFIC_CACHE_TS[callsign] = _nowMs();
                    schedulePersistSimTrafficLocalCache();
                }
                noteSimTrafficSuccess();

                if (data && !data.__error) {
                    updateRowWithSimTraffic(callsign, data);
                    applyTimeFilterToTable();
                }
            })
            .catch(function(err) {
                console.error('Error loading SimTraffic data for', callsign, err);

                // If we didn't already record a status-based error above, record a generic one here
                if (String(err && err.message || '').indexOf('HTTP ') !== 0) {
                    noteSimTrafficError(0, null);
                }

                // Cache a sentinel so we do not retry this callsign repeatedly
                GS_SIMTRAFFIC_CACHE[callsign] = { __error: true, message: (err && err.message) ? err.message : '' };
                recordNegativeCache(callsign);

                // Re-apply any active time filter using whatever data we have
                applyTimeFilterToTable();
            })
            .finally(function() {
                GS_SIMTRAFFIC_BUSY = false;
                if (GS_SIMTRAFFIC_ENABLED && GS_SIMTRAFFIC_QUEUE.length) {
                    setTimeout(processSimTrafficQueue, GS_SIMTRAFFIC_NEXT_DELAY_MS);
                }
            });
    }

    function enrichFlightsWithSimTraffic(rows) {
        if (!GS_SIMTRAFFIC_ENABLED) {return;}
        if (!rows || !rows.length) {return;}

        const now = _nowMs();
        if (now < GS_SIMTRAFFIC_COOLDOWN_UNTIL_MS) {return;}

        // Don't attempt on every render/refresh
        if ((now - GS_SIMTRAFFIC_LAST_ENRICH_MS) < GS_SIMTRAFFIC_CFG.minEnrichIntervalMs) {
        // Still apply any cached entries to the DOM
            rows.forEach(function(r) {
                if (!r || !r.callsign) {return;}
                const cs = String(r.callsign).toUpperCase();
                if (isFreshSuccessCache(cs, now)) {
                    updateRowWithSimTraffic(cs, GS_SIMTRAFFIC_CACHE[cs]);
                }
            });
            return;
        }

        GS_SIMTRAFFIC_LAST_ENRICH_MS = now;

        // Candidates: prioritize flights with missing times, then earliest ETA
        const candidates = [];
        rows.forEach(function(r) {
            if (!r || !r.callsign) {return;}

            const cs = String(r.callsign).toUpperCase();
            if (!cs) {return;}

            // Apply cached data immediately (no new request)
            if (isFreshSuccessCache(cs, now)) {
                updateRowWithSimTraffic(cs, GS_SIMTRAFFIC_CACHE[cs]);
                return;
            }

            // Skip if we recently failed for this callsign
            if (isRecentNegativeCache(cs, now)) {return;}

            // Only fetch when at least one key time is missing
            const needs =
            !(r.edctEpoch != null && !isNaN(r.edctEpoch)) ||
            !(r.tkofEpoch != null && !isNaN(r.tkofEpoch)) ||
            !(r.etaEpoch != null && !isNaN(r.etaEpoch)) ||
            !(r.mftEpoch != null && !isNaN(r.mftEpoch)) ||
            !(r.vtEpoch != null && !isNaN(r.vtEpoch));

            if (!needs) {return;}

            candidates.push(r);
        });

        candidates.sort(function(a, b) {
            const aEta = (a.roughEtaEpoch != null && !isNaN(a.roughEtaEpoch)) ? a.roughEtaEpoch : Number.MAX_SAFE_INTEGER;
            const bEta = (b.roughEtaEpoch != null && !isNaN(b.roughEtaEpoch)) ? b.roughEtaEpoch : Number.MAX_SAFE_INTEGER;
            return aEta - bEta;
        });

        const n = Math.min(GS_SIMTRAFFIC_CFG.maxRequestsPerEnrich, candidates.length);
        for (let i = 0; i < n; i++) {
            enqueueSimTrafficFetch(candidates[i].callsign);
        }
    }

    // Load any saved cache once per page load
    loadSimTrafficLocalCache();

    function loadTierInfo() {
        // Load tier data from GIS-based API (PostGIS proximity tiers)
        // Falls back to ADL-based API if GIS unavailable
        // Expected header columns: code, facility, select, departureFacilitiesIncluded
        return fetch('api/tiers/query.php?format=gdt', { cache: 'no-cache' })
            .then(function(res) { return res.text(); })
            .then(function(text) {
                TMI_TIER_INFO = [];
                TMI_UNIQUE_FACILITIES = [];
                TMI_TIER_INFO_BY_CODE = {};

                if (!text) {return;}

                const lines = text.replace(/\r/g, '').split('\n').filter(function(l) { return l.trim().length > 0; });
                if (!lines.length) {return;}

                const header = lines[0];
                const delim = header.indexOf(',') !== -1 ? ',' : '\t';
                const cols = header.split(delim).map(function(s) { return s.trim(); });

                function idx(name) {
                    const i = cols.indexOf(name);
                    return i === -1 ? -1 : i;
                }

                const idxCode = idx('code');
                const idxFacility = idx('facility');
                const idxLabel = idx('select');
                const idxDeps = idx('departureFacilitiesIncluded');

                const facSet = new Set();

                for (let i = 1; i < lines.length; i++) {
                    const line = lines[i];
                    if (!line.trim()) {continue;}
                    const parts = line.split(delim);
                    const get = (idx) => (idx >= 0 && idx < parts.length) ? parts[idx].trim() : '';
                    const code = get(idxCode);
                    if (!code) {continue;}
                    const facility = get(idxFacility) || null;
                    const label = get(idxLabel);
                    const depsRaw = get(idxDeps);
                    const included = depsRaw ? depsRaw.split(/\s+/).filter(function(x) { return x.length > 0; }) : [];

                    included.forEach(function(f) { facSet.add(f); });

                    const entry = {
                        code: code,
                        facility: facility,
                        label: label,
                        included: included,
                    };
                    TMI_TIER_INFO.push(entry);
                    TMI_TIER_INFO_BY_CODE[code] = entry;
                }

                TMI_UNIQUE_FACILITIES = Array.from(facSet).sort();
            })
            .catch(function(err) {
                console.warn('GIS tier API failed, falling back to ADL API:', err);
                // Fallback to ADL-based tier API
                return fetch('api/tiers.php?format=csv', { cache: 'no-cache' })
                    .then(function(res) { return res.text(); })
                    .then(function(text) {
                        TMI_TIER_INFO = [];
                        TMI_UNIQUE_FACILITIES = [];
                        TMI_TIER_INFO_BY_CODE = {};
                        if (!text) {return;}
                        const lines = text.replace(/\r/g, '').split('\n').filter(function(l) { return l.trim().length > 0; });
                        if (!lines.length) {return;}
                        const header = lines[0];
                        const delim = header.indexOf(',') !== -1 ? ',' : '\t';
                        const cols = header.split(delim).map(function(s) { return s.trim(); });
                        function idx(name) { return cols.indexOf(name); }
                        const idxCode = idx('code'), idxFacility = idx('facility'), idxLabel = idx('select'), idxDeps = idx('departureFacilitiesIncluded');
                        const facSet = new Set();
                        for (let i = 1; i < lines.length; i++) {
                            const parts = lines[i].split(delim);
                            const get = (idx) => (idx >= 0 && idx < parts.length) ? parts[idx].trim() : '';
                            const code = get(idxCode);
                            if (!code) {continue;}
                            const facility = get(idxFacility) || null;
                            const label = get(idxLabel);
                            const depsRaw = get(idxDeps);
                            const included = depsRaw ? depsRaw.split(/\s+/).filter(function(x) { return x.length > 0; }) : [];
                            included.forEach(function(f) { facSet.add(f); });
                            const entry = { code: code, facility: facility, label: label, included: included };
                            TMI_TIER_INFO.push(entry);
                            TMI_TIER_INFO_BY_CODE[code] = entry;
                        }
                        TMI_UNIQUE_FACILITIES = Array.from(facSet).sort();
                    })
                    .catch(function(fallbackErr) {
                        console.error('Fallback tier API also failed:', fallbackErr);
                    });
            });
    }

    document.addEventListener('DOMContentLoaded', function() {

        // Initialize UTC clock display if placeholder exists
        const utcClockEl = document.getElementById('tmi_utc_clock');
        if (utcClockEl) {
            const updateUtcClock = function() {
                const now = new Date();
                const dd = String(now.getUTCDate()).padStart(2, '0');
                const hh = String(now.getUTCHours()).padStart(2, '0');
                const mi = String(now.getUTCMinutes()).padStart(2, '0');
                const ss = String(now.getUTCSeconds()).padStart(2, '0');
                utcClockEl.textContent = dd + ' / ' + hh + ':' + mi + ':' + ss + 'Z';
            };
            updateUtcClock();
            setInterval(updateUtcClock, 1000);
        }

        // Initialize US timezone clocks
        const clockGuam = document.getElementById('tmi_clock_guam');
        const clockHi = document.getElementById('tmi_clock_hi');
        const clockAk = document.getElementById('tmi_clock_ak');
        const clockPac = document.getElementById('tmi_clock_pac');
        const clockMtn = document.getElementById('tmi_clock_mtn');
        const clockCent = document.getElementById('tmi_clock_cent');
        const clockEast = document.getElementById('tmi_clock_east');

        if (clockPac && clockMtn && clockCent && clockEast) {
            const updateLocalClocks = function() {
                const now = new Date();

                // Format time for a given timezone offset (hours from UTC)
                function formatLocalTime(date, tzName) {
                    try {
                        const opts = {
                            timeZone: tzName,
                            hour: '2-digit',
                            minute: '2-digit',
                            hour12: false,
                        };
                        return date.toLocaleTimeString('en-US', opts);
                    } catch (e) {
                        // Fallback if timezone not supported
                        return '--:--:--';
                    }
                }

                clockGuam.textContent = formatLocalTime(now, 'Pacific/Guam');
                clockHi.textContent = formatLocalTime(now, 'Pacific/Honolulu');
                clockAk.textContent = formatLocalTime(now, 'America/Anchorage');
                clockPac.textContent = formatLocalTime(now, 'America/Los_Angeles');
                clockMtn.textContent = formatLocalTime(now, 'America/Denver');
                clockCent.textContent = formatLocalTime(now, 'America/Chicago');
                clockEast.textContent = formatLocalTime(now, 'America/New_York');
            };
            updateLocalClocks();
            setInterval(updateLocalClocks, 1000);
        }

        // Initialize flight statistics display
        const statsElements = {
            globalTotal: document.getElementById('tmi_stats_global_total'),
            dd: document.getElementById('tmi_stats_dd'),
            di: document.getElementById('tmi_stats_di'),
            id: document.getElementById('tmi_stats_id'),
            ii: document.getElementById('tmi_stats_ii'),
            domesticTotal: document.getElementById('tmi_stats_domestic_total'),
            dccNe: document.getElementById('tmi_stats_dcc_ne'),
            dccSe: document.getElementById('tmi_stats_dcc_se'),
            dccMw: document.getElementById('tmi_stats_dcc_mw'),
            dccSc: document.getElementById('tmi_stats_dcc_sc'),
            dccW: document.getElementById('tmi_stats_dcc_w'),
            aspm82: document.getElementById('tmi_stats_aspm82'),
            oep35: document.getElementById('tmi_stats_oep35'),
            core30: document.getElementById('tmi_stats_core30'),
        };

        const hasStatsElements = Object.values(statsElements).some(function(el) { return el !== null; });

        if (hasStatsElements) {
            const updateFlightStats = function() {
                fetch('api/adl/stats.php', { cache: 'no-cache' })
                    .then(function(res) { return res.json(); })
                    .then(function(data) {
                        if (!data) {return;}

                        // Update global counts
                        if (data.global) {
                            if (statsElements.globalTotal) {
                                statsElements.globalTotal.textContent = data.global.total || 0;
                            }
                            if (statsElements.dd) {
                                statsElements.dd.textContent = data.global.domestic_to_domestic || 0;
                            }
                            if (statsElements.di) {
                                statsElements.di.textContent = data.global.domestic_to_intl || 0;
                            }
                            if (statsElements.id) {
                                statsElements.id.textContent = data.global.intl_to_domestic || 0;
                            }
                            if (statsElements.ii) {
                                statsElements.ii.textContent = data.global.intl_to_intl || 0;
                            }
                        }

                        // Update domestic counts
                        if (data.domestic) {
                            // Calculate domestic arrivals total (sum of DCC regions)
                            let domesticArrTotal = 0;
                            if (data.domestic.arr_dcc) {
                                const dcc = data.domestic.arr_dcc;
                                domesticArrTotal = (dcc.NE || 0) + (dcc.SE || 0) + (dcc.MW || 0) +
                                                   (dcc.SC || 0) + (dcc.W || 0) + (dcc.Other || 0);

                                if (statsElements.dccNe) {statsElements.dccNe.textContent = dcc.NE || 0;}
                                if (statsElements.dccSe) {statsElements.dccSe.textContent = dcc.SE || 0;}
                                if (statsElements.dccMw) {statsElements.dccMw.textContent = dcc.MW || 0;}
                                if (statsElements.dccSc) {statsElements.dccSc.textContent = dcc.SC || 0;}
                                if (statsElements.dccW) {statsElements.dccW.textContent = dcc.W || 0;}
                            }

                            if (statsElements.domesticTotal) {
                                statsElements.domesticTotal.textContent = domesticArrTotal;
                            }

                            // Airport tiers
                            if (data.domestic.arr_aspm82 && statsElements.aspm82) {
                                statsElements.aspm82.textContent = data.domestic.arr_aspm82.yes || 0;
                            }
                            if (data.domestic.arr_oep35 && statsElements.oep35) {
                                statsElements.oep35.textContent = data.domestic.arr_oep35.yes || 0;
                            }
                            if (data.domestic.arr_core30 && statsElements.core30) {
                                statsElements.core30.textContent = data.domestic.arr_core30.yes || 0;
                            }
                        }
                    })
                    .catch(function(err) {
                        console.error('Error fetching flight stats:', err);
                    });
            };

            // Initial load and refresh every 15 seconds
            updateFlightStats();
            setInterval(updateFlightStats, 15000);
        }

        // Load Tier, airport, and BTS info first, then populate scope selector
        Promise.all([loadTierInfo(), loadAirportInfo(), loadBtsStats()]).then(function() {
            populateScopeSelector();
            refreshAdl();
            // Check for ?edit=ID parameter to load program for editing
            checkUrlForEditMode();
        }).catch(function(err) {
            console.error('Error initializing TMI page', err);
        });

        // Initialize Active Programs Dashboard
        loadActiveProgramsDashboard();
        GDT_DASHBOARD_REFRESH_TIMER = setInterval(loadActiveProgramsDashboard, GDT_DASHBOARD_REFRESH_INTERVAL);

        // Initialize workflow stepper to configure state
        setWorkflowState('configure');

        // Wire up extend modal advisory preview auto-update
        ['gdt_extend_new_end', 'gdt_extend_prob_ext', 'gdt_extend_comments'].forEach(function(id) {
            var el = document.getElementById(id);
            if (el) {
                el.addEventListener('change', function() {
                    var pid = parseInt(document.getElementById('gdt_extend_modal').dataset.programId);
                    var prog = findDashboardProgram(pid);
                    if (prog) buildExtendAdvisoryPreview(prog);
                });
                el.addEventListener('keyup', function() {
                    var pid = parseInt(document.getElementById('gdt_extend_modal').dataset.programId);
                    var prog = findDashboardProgram(pid);
                    if (prog) buildExtendAdvisoryPreview(prog);
                });
            }
        });

        // Wire up revise modal advisory preview auto-update
        ['gdt_revise_rate', 'gdt_revise_delay_cap', 'gdt_revise_comments'].forEach(function(id) {
            var el = document.getElementById(id);
            if (el) {
                el.addEventListener('change', function() {
                    var pid = parseInt(document.getElementById('gdt_revise_modal').dataset.programId);
                    var prog = findDashboardProgram(pid);
                    if (prog) buildReviseAdvisoryPreview(prog);
                });
                el.addEventListener('keyup', function() {
                    var pid = parseInt(document.getElementById('gdt_revise_modal').dataset.programId);
                    var prog = findDashboardProgram(pid);
                    if (prog) buildReviseAdvisoryPreview(prog);
                });
            }
        });

        // Wire up transition modal advisory preview auto-update
        ['gdt_transition_gdp_type', 'gdt_transition_rate', 'gdt_transition_reserve',
         'gdt_transition_end_utc', 'gdt_transition_delay_cap', 'gdt_transition_impacting',
         'gdt_transition_comments'].forEach(function(id) {
            var el = document.getElementById(id);
            if (el) {
                el.addEventListener('change', function() {
                    var gsId = parseInt(document.getElementById('gdt_transition_modal').dataset.gsProgramId);
                    var prog = findDashboardProgram(gsId);
                    if (prog) buildTransitionAdvisoryPreview(prog, 'propose');
                });
                el.addEventListener('keyup', function() {
                    var gsId = parseInt(document.getElementById('gdt_transition_modal').dataset.gsProgramId);
                    var prog = findDashboardProgram(gsId);
                    if (prog) buildTransitionAdvisoryPreview(prog, 'propose');
                });
            }
        });

        // Wire up advisory auto-update
        const ids = [
            'gs_ctl_element', 'gs_element_type', 'gs_adv_number',
            'gs_start', 'gs_end', 'gs_airports', 'gs_origin_centers',
            'gs_origin_airports', 'gs_flt_incl_carrier', 'gs_flt_incl_type',
            'gs_dep_facilities', 'gs_prob_ext', 'gs_impacting_condition',
            'gs_comments',
        ];
        ids.forEach(function(id) {
            const el = document.getElementById(id);
            if (!el) {return;}
            el.addEventListener('change', buildAdvisory);
            el.addEventListener('keyup', buildAdvisory);
        });

        const scopeSel = document.getElementById('gs_scope_select');
        if (scopeSel) {
            scopeSel.addEventListener('change', recomputeScopeFromSelector);
        }

        // Copy Advisory button handler
        const copyAdvBtn = document.getElementById('gs_copy_advisory_btn');
        if (copyAdvBtn) {
            copyAdvBtn.addEventListener('click', function(ev) {
                ev.preventDefault();
                copyAdvisoryToClipboard();
            });
        }

        // Reset button handler
        const resetBtn = document.getElementById('gs_reset_btn');
        if (resetBtn) {
            resetBtn.addEventListener('click', function(ev) {
                ev.preventDefault();
                resetGsForm();
            });
        }

        const previewBtn = document.getElementById('gs_preview_flights_btn');
        if (previewBtn) {
            previewBtn.addEventListener('click', function(ev) {
                ev.preventDefault();
                buildAdvisory();
                handleGsPreview();
            });
        }

        // Show All Flights toggle handler
        const showAllToggle = document.getElementById('gs_show_all_flights');
        if (showAllToggle) {
            showAllToggle.addEventListener('change', function() {
                GS_SHOW_ALL_FLIGHTS = this.checked;
                // If we have workflow data (from Preview/Simulate), re-render from that
                // Otherwise fall back to VATSIM/ADL loading
                if (GS_MATCHING_FLIGHTS && GS_MATCHING_FLIGHTS.length > 0) {
                    const sourceLabel = GS_CURRENT_PROGRAM_STATUS === 'SIMULATED' ? 'GS-SIM' : 'GS-PREVIEW';
                    renderFlightsFromAdlRowsForWorkflow(GS_MATCHING_FLIGHTS, sourceLabel);
                } else {
                    loadVatsimFlightsForCurrentGs();
                }
            });
        }

        // Program type selector: show/hide GDP rate fields and update labels
        var programTypeSelect = document.getElementById('gs_program_type');
        if (programTypeSelect) {
            programTypeSelect.addEventListener('change', function() {
                var isGDP = this.value !== 'GS';
                var rateRow = document.getElementById('gs_gdp_rate_row');
                if (rateRow) rateRow.style.display = isGDP ? '' : 'none';

                // Update the setup card header label and icon
                var headerEl = document.getElementById('gs_setup_header_label');
                if (headerEl) {
                    var typeLabel = this.options[this.selectedIndex].text;
                    var icon = isGDP ? '<i class="fas fa-clock mr-1 text-warning"></i>' : '<i class="fas fa-ban mr-1 text-danger"></i>';
                    headerEl.innerHTML = icon + ' ' + typeLabel + ' Setup';
                }

                // Reset program state when type changes (new type = new program)
                if (GS_CURRENT_PROGRAM_ID && GS_CURRENT_PROGRAM_STATUS === 'MODELING') {
                    GS_CURRENT_PROGRAM_ID = null;
                    GS_CURRENT_PROGRAM_STATUS = null;
                    setWorkflowState('configure');
                }
            });
        }

        // Workflow toolbar buttons (ADL/GS sandbox)
        var previewBtn2 = document.getElementById('gs_preview_btn');
        if (previewBtn2) {
            previewBtn2.addEventListener('click', function(ev) {
                ev.preventDefault();
                buildAdvisory();
                handleGsPreview();
            });
        }

        const simulateBtn = document.getElementById('gs_simulate_btn');
        if (simulateBtn) {
            simulateBtn.addEventListener('click', function(ev) {
                ev.preventDefault();
                buildAdvisory();
                handleGsSimulate();
            });
        }

        const submitTmiBtn = document.getElementById('gs_submit_tmi_btn');
        if (submitTmiBtn) {
            submitTmiBtn.addEventListener('click', function(ev) {
                ev.preventDefault();
                buildAdvisory();
                handleGsSubmitToTmi();
            });
            // Initialize as disabled - must run Simulate first
            setSubmitTmiEnabled(false, PERTII18n.t('gdt.gsWorkflow.simulateFirstTooltip'));
        }

        const sendActualBtn = document.getElementById('gs_send_actual_btn');
        if (sendActualBtn) {
            sendActualBtn.addEventListener('click', function(ev) {
                ev.preventDefault();
                handleGsSendActual();
            });
            sendActualBtn.disabled = true;
        }

        const purgeLocalBtn = document.getElementById('gs_purge_local_btn');
        if (purgeLocalBtn) {
            purgeLocalBtn.addEventListener('click', function(ev) {
                ev.preventDefault();
                handleGsPurgeLocal();
            });
        }

        const purgeAllBtn = document.getElementById('gs_purge_all_btn');
        if (purgeAllBtn) {
            purgeAllBtn.addEventListener('click', function(ev) {
                ev.preventDefault();
                handleGsPurgeAll();
            });
        }

        // View Flight List button - shows current GS flight list from simulation/preview
        const viewFlightListBtn = document.getElementById('gs_view_flight_list_btn');
        if (viewFlightListBtn) {
            viewFlightListBtn.addEventListener('click', function(ev) {
                ev.preventDefault();
                handleViewFlightList();
            });
        }

        // Model GS button - opens the Model GS section with Data Graph
        const openModelBtn = document.getElementById('gs_open_model_btn');
        if (openModelBtn) {
            openModelBtn.addEventListener('click', function(ev) {
                ev.preventDefault();
                openModelGsSection();
            });
        }

        // Model GS close button
        const modelCloseBtn = document.getElementById('gs_model_close_btn');
        if (modelCloseBtn) {
            modelCloseBtn.addEventListener('click', function(ev) {
                ev.preventDefault();
                closeModelGsSection();
            });
        }

        // Initialize Model GS Power Run event handlers
        initModelGsHandlers();

        // Initialize GS Demand Chart handlers
        initGsDemandChartHandlers();

        // Flight List modal link to Model GS
        const fltListOpenModel = document.getElementById('gs_flt_list_open_model');
        if (fltListOpenModel) {
            fltListOpenModel.addEventListener('click', function(ev) {
                ev.preventDefault();
                // Close the modal first
                if (window.jQuery && window.jQuery.fn.modal) {
                    window.jQuery('#gs_flight_list_modal').modal('hide');
                }
                openModelGsSection();
            });
        }

        // Flights Matching table sortable column handler
        document.addEventListener('click', function(ev) {
            const th = ev.target.closest('.gs-matching-sortable');
            if (!th) {return;}

            const sortField = th.getAttribute('data-sort');
            if (!sortField) {return;}

            // Toggle order if same field, else default to asc
            if (GS_MATCHING_SORT.field === sortField) {
                GS_MATCHING_SORT.order = GS_MATCHING_SORT.order === 'asc' ? 'desc' : 'asc';
            } else {
                GS_MATCHING_SORT = { field: sortField, order: 'asc' };
            }

            // Re-render the flights matching table with new sort
            sortAndRenderMatchingTable();
        });

        // === EXEMPTION EVENT HANDLERS ===
        // Wire up exemption fields to update summary when changed
        const exemptionFields = [
            'gs_exempt_orig_airports', 'gs_exempt_orig_tracons', 'gs_exempt_orig_artccs',
            'gs_exempt_dest_airports', 'gs_exempt_dest_tracons', 'gs_exempt_dest_artccs',
            'gs_exempt_type_jet', 'gs_exempt_type_turboprop', 'gs_exempt_type_prop',
            'gs_exempt_has_edct', 'gs_exempt_active_only', 'gs_exempt_depart_within',
            'gs_exempt_alt_below', 'gs_exempt_alt_above', 'gs_exempt_flights',
        ];
        exemptionFields.forEach(function(id) {
            const el = document.getElementById(id);
            if (!el) {return;}
            el.addEventListener('change', updateExemptionSummary);
            if (el.type === 'text' || el.type === 'number') {
                el.addEventListener('keyup', updateExemptionSummary);
            }
        });

        // Toggle icon for exemptions collapse
        const exemptionsBody = document.getElementById('gs_exemptions_body');
        const exemptionsIcon = document.getElementById('gs_exemptions_toggle_icon');
        if (exemptionsBody && exemptionsIcon) {
            exemptionsBody.addEventListener('shown.bs.collapse', function() {
                exemptionsIcon.className = 'fas fa-chevron-up';
            });
            exemptionsBody.addEventListener('hidden.bs.collapse', function() {
                exemptionsIcon.className = 'fas fa-chevron-down';
            });
            // jQuery fallback for Bootstrap 4
            if (window.jQuery) {
                window.jQuery(exemptionsBody).on('shown.bs.collapse', function() {
                    exemptionsIcon.className = 'fas fa-chevron-up';
                });
                window.jQuery(exemptionsBody).on('hidden.bs.collapse', function() {
                    exemptionsIcon.className = 'fas fa-chevron-down';
                });
            }
        }

        // === ECR (EDCT CHANGE REQUEST) EVENT HANDLERS ===
        initializeEcrHandlers();

        // Initialize default GS Start and End times
        initializeDefaultGsTimes();
    });

    // === ECR FUNCTIONALITY ===
    let ECR_CURRENT_FLIGHT = null;

    function initializeEcrHandlers() {
        // Get Flight Data button
        const getFlightBtn = document.getElementById('ecr_get_flight_btn');
        if (getFlightBtn) {
            getFlightBtn.addEventListener('click', function(ev) {
                ev.preventDefault();
                ecrGetFlightData();
            });
        }

        // Apply Model button
        const applyModelBtn = document.getElementById('ecr_apply_model_btn');
        if (applyModelBtn) {
            applyModelBtn.addEventListener('click', function(ev) {
                ev.preventDefault();
                ecrApplyModel();
            });
        }

        // Send Request button
        const sendRequestBtn = document.getElementById('ecr_send_request_btn');
        if (sendRequestBtn) {
            sendRequestBtn.addEventListener('click', function(ev) {
                ev.preventDefault();
                ecrSendRequest();
            });
        }

        // Clear All button
        const clearAllBtn = document.getElementById('ecr_clear_btn');
        if (clearAllBtn) {
            clearAllBtn.addEventListener('click', function(ev) {
                ev.preventDefault();
                ecrClearAll();
            });
        }

        // Default Range button
        const defaultRangeBtn = document.getElementById('ecr_default_range_btn');
        if (defaultRangeBtn) {
            defaultRangeBtn.addEventListener('click', function(ev) {
                ev.preventDefault();
                document.getElementById('ecr_cta_range').value = 60;
                document.getElementById('ecr_max_add_delay').value = 60;
            });
        }

        // Manual method toggle
        const methodRadios = document.querySelectorAll('input[name="ecr_method"]');
        methodRadios.forEach(function(radio) {
            radio.addEventListener('change', function() {
                const manualSection = document.getElementById('ecr_manual_section');
                if (manualSection) {
                    manualSection.style.display = this.value === 'MANUAL' ? 'flex' : 'none';
                }
            });
        });

        // CTA Range change updates Max Additional Delay
        const ctaRangeEl = document.getElementById('ecr_cta_range');
        if (ctaRangeEl) {
            ctaRangeEl.addEventListener('change', function() {
                document.getElementById('ecr_max_add_delay').value = this.value;
            });
        }

        // Manual EDCT change calculates CTA
        const manualEdctEl = document.getElementById('ecr_manual_edct');
        if (manualEdctEl) {
            manualEdctEl.addEventListener('change', function() {
                ecrCalculateManualCta();
            });
        }
    }

    function ecrGetFlightData() {
        const acid = getValue('ecr_acid').toUpperCase();
        const orig = getValue('ecr_orig').toUpperCase();
        const dest = getValue('ecr_dest').toUpperCase();

        if (!acid) {
            if (window.Swal) {
                window.Swal.fire({ icon: 'warning', title: PERTII18n.t('gdt.ecr.acidRequired'), text: PERTII18n.t('gdt.ecr.enterAcid') });
            } else {
                alert(PERTII18n.t('gdt.ecr.enterAcid'));
            }
            return;
        }

        // Search in the current ADL data
        let flight = null;
        if (GS_ADL && Array.isArray(GS_ADL)) {
            flight = GS_ADL.find(function(f) {
                const cs = (f.callsign || f.CALLSIGN || '').toUpperCase();
                const fOrig = (f.fp_dept_icao || f.FP_DEPT_ICAO || f.dep_icao || '').toUpperCase();
                const fDest = (f.fp_dest_icao || f.FP_DEST_ICAO || f.arr_icao || '').toUpperCase();

                if (cs !== acid) {return false;}
                if (orig && fOrig !== orig) {return false;}
                if (dest && fDest !== dest) {return false;}
                return true;
            });
        }

        if (!flight) {
            if (window.Swal) {
                window.Swal.fire({ icon: 'error', title: PERTII18n.t('gdt.ecr.flightNotFound'), text: PERTII18n.t('gdt.ecr.couldNotFindFlight', { acid: acid }) });
            } else {
                alert(PERTII18n.t('gdt.ecr.couldNotFindFlight', { acid: acid }));
            }
            return;
        }

        ECR_CURRENT_FLIGHT = flight;
        ecrPopulateFlightData(flight);
    }

    function ecrPopulateFlightData(flight) {
        // Show flight data section
        const flightDataSection = document.getElementById('ecr_flight_data_section');
        const updateSection = document.getElementById('ecr_update_section');
        if (flightDataSection) {flightDataSection.style.display = 'block';}
        if (updateSection) {updateSection.style.display = 'block';}

        // Parse times
        const igtd = flight.igtd_utc || flight.IGTD_UTC || flight.orig_etd_utc || '-';
        const ctd = flight.ctd_utc || flight.CTD_UTC || '-';
        const etd = flight.etd_runway_utc || flight.ETD_RUNWAY_UTC || '-';
        const ertd = flight.ertd_utc || flight.ERTD_UTC || '-';
        const ete = flight.ete_minutes || flight.ETE_MINUTES || '-';

        const igta = flight.igta_utc || flight.IGTA_UTC || flight.orig_eta_utc || '-';
        const cta = flight.cta_utc || flight.CTA_UTC || '-';
        const eta = flight.eta_runway_utc || flight.ETA_RUNWAY_UTC || '-';
        const erta = flight.erta_utc || flight.ERTA_UTC || '-';
        const delay = flight.program_delay_min || flight.PROGRAM_DELAY_MIN || 0;

        const ctlType = flight.ctl_type || flight.CTL_TYPE || '-';
        const delayStatus = flight.delay_status || flight.DELAY_STATUS || '-';

        // Format times for display
        function formatTime(isoStr) {
            if (!isoStr || isoStr === '-') {return '-';}
            try {
                const d = new Date(isoStr);
                if (isNaN(d.getTime())) {return isoStr;}
                const dd = String(d.getUTCDate()).padStart(2, '0');
                const hh = String(d.getUTCHours()).padStart(2, '0');
                const mm = String(d.getUTCMinutes()).padStart(2, '0');
                return dd + '/' + hh + mm + 'Z';
            } catch (e) {
                return isoStr;
            }
        }

        // Populate fields
        setText('ecr_igtd', formatTime(igtd));
        setText('ecr_ctd', formatTime(ctd));
        setText('ecr_etd', formatTime(etd));
        setText('ecr_ertd', formatTime(ertd));
        setText('ecr_ete', ete !== '-' ? ete + ' ' + PERTII18n.t('units.min') : '-');

        setText('ecr_igta', formatTime(igta));
        setText('ecr_cta', formatTime(cta));
        setText('ecr_eta', formatTime(eta));
        setText('ecr_erta', formatTime(erta));
        setText('ecr_delay', delay > 0 ? delay + ' ' + PERTII18n.t('units.min') : '0 ' + PERTII18n.t('units.min'));

        setText('ecr_ctl_type', ctlType);
        setText('ecr_delay_status', delayStatus);

        // Set the delay color
        const delayEl = document.getElementById('ecr_delay');
        if (delayEl) {
            delayEl.className = 'font-weight-bold';
            if (delay > 60) {delayEl.classList.add('text-danger');}
            else if (delay > 30) {delayEl.classList.add('text-warning');}
            else {delayEl.classList.add('text-success');}
        }

        // Pre-populate origin/dest if not already set
        if (!getValue('ecr_orig')) {
            document.getElementById('ecr_orig').value = flight.fp_dept_icao || flight.FP_DEPT_ICAO || '';
        }
        if (!getValue('ecr_dest')) {
            document.getElementById('ecr_dest').value = flight.fp_dest_icao || flight.FP_DEST_ICAO || '';
        }
    }

    function setText(id, text) {
        const el = document.getElementById(id);
        if (el) {el.textContent = text;}
    }

    function ecrApplyModel() {
        if (!ECR_CURRENT_FLIGHT) {
            if (window.Swal) {
                window.Swal.fire({ icon: 'warning', title: PERTII18n.t('gdt.ecr.noFlight'), text: PERTII18n.t('gdt.ecr.getFlightFirst') });
            }
            return;
        }

        const earliestEdct = getValue('ecr_earliest_edct');
        if (!earliestEdct) {
            if (window.Swal) {
                window.Swal.fire({ icon: 'warning', title: PERTII18n.t('gdt.ecr.earliestEdctRequired'), text: PERTII18n.t('gdt.ecr.enterEarliestEdct') });
            }
            return;
        }

        // Calculate new CTD and CTA based on method
        const method = document.querySelector('input[name="ecr_method"]:checked').value;
        const ctaRange = parseInt(document.getElementById('ecr_cta_range').value, 10) || 60;
        const ete = parseInt(ECR_CURRENT_FLIGHT.ete_minutes || ECR_CURRENT_FLIGHT.ETE_MINUTES || 120, 10);

        // Parse earliest EDCT as UTC
        const earliestEdctDate = new Date(earliestEdct + 'Z');
        const newCtdDate = new Date(earliestEdctDate);
        const newCtaDate = new Date(earliestEdctDate.getTime() + ete * 60 * 1000);

        // Current delay
        const currentDelay = parseInt(ECR_CURRENT_FLIGHT.program_delay_min || ECR_CURRENT_FLIGHT.PROGRAM_DELAY_MIN || 0, 10);

        // Calculate delay change
        const currentCtdStr = ECR_CURRENT_FLIGHT.ctd_utc || ECR_CURRENT_FLIGHT.CTD_UTC;
        let delayChange = 0;
        if (currentCtdStr) {
            const currentCtdDate = new Date(currentCtdStr);
            delayChange = Math.round((newCtdDate - currentCtdDate) / 60000);
        }

        // Show modeled results
        const modelResults = document.getElementById('ecr_model_results');
        if (modelResults) {modelResults.style.display = 'block';}

        function formatDateTime(d) {
            const dd = String(d.getUTCDate()).padStart(2, '0');
            const hh = String(d.getUTCHours()).padStart(2, '0');
            const mm = String(d.getUTCMinutes()).padStart(2, '0');
            return dd + '/' + hh + mm + 'Z';
        }

        setText('ecr_new_ctd', formatDateTime(newCtdDate));
        setText('ecr_new_cta', formatDateTime(newCtaDate));

        const delayChangeEl = document.getElementById('ecr_delay_change');
        if (delayChangeEl) {
            if (delayChange > 0) {
                delayChangeEl.textContent = '+' + PERTII18n.t('gdt.dashboard.delayMinutes', { delay: delayChange });
                delayChangeEl.className = 'text-danger';
            } else if (delayChange < 0) {
                delayChangeEl.textContent = PERTII18n.t('gdt.dashboard.delayMinutes', { delay: delayChange });
                delayChangeEl.className = 'text-success';
            } else {
                delayChangeEl.textContent = PERTII18n.t('gdt.dashboard.delayMinutes', { delay: 0 });
                delayChangeEl.className = 'text-muted';
            }
        }

        // Enable Send Request button
        const sendBtn = document.getElementById('ecr_send_request_btn');
        if (sendBtn) {sendBtn.disabled = false;}
    }

    function ecrCalculateManualCta() {
        if (!ECR_CURRENT_FLIGHT) {return;}

        const manualEdct = getValue('ecr_manual_edct');
        if (!manualEdct) {return;}

        const ete = parseInt(ECR_CURRENT_FLIGHT.ete_minutes || ECR_CURRENT_FLIGHT.ETE_MINUTES || 120, 10);
        const edctDate = new Date(manualEdct + 'Z');
        const ctaDate = new Date(edctDate.getTime() + ete * 60 * 1000);

        const dd = String(ctaDate.getUTCDate()).padStart(2, '0');
        const hh = String(ctaDate.getUTCHours()).padStart(2, '0');
        const mm = String(ctaDate.getUTCMinutes()).padStart(2, '0');

        const ctaEl = document.getElementById('ecr_manual_cta');
        if (ctaEl) {ctaEl.value = dd + '/' + hh + mm + 'Z';}
    }

    function ecrSendRequest() {
        if (!ECR_CURRENT_FLIGHT) {
            if (window.Swal) {
                window.Swal.fire({ icon: 'warning', title: PERTII18n.t('gdt.ecr.noFlight'), text: PERTII18n.t('gdt.ecr.getFlightAndApplyFirst') });
            }
            return;
        }

        const method = document.querySelector('input[name="ecr_method"]:checked').value;
        const earliestEdct = getValue('ecr_earliest_edct');
        const ctaRange = parseInt(document.getElementById('ecr_cta_range').value, 10) || 60;
        const updateErta = document.getElementById('ecr_update_erta').checked;

        // For manual method, use the manual EDCT
        let newEdct = earliestEdct;
        if (method === 'MANUAL') {
            newEdct = getValue('ecr_manual_edct') || earliestEdct;
        }

        const payload = {
            acid: getValue('ecr_acid').toUpperCase(),
            orig: getValue('ecr_orig').toUpperCase(),
            dest: getValue('ecr_dest').toUpperCase(),
            method: method,
            earliest_edct: utcIsoFromDatetimeLocal(earliestEdct),
            new_edct: utcIsoFromDatetimeLocal(newEdct),
            cta_range: ctaRange,
            update_erta: updateErta,
        };

        // In a real implementation, this would call the ECR API
        // For now, we'll simulate an update to the local ADL data
        const responseSection = document.getElementById('ecr_response_section');
        const responseText = document.getElementById('ecr_response_text');

        if (responseSection && responseText) {
            responseSection.style.display = 'block';

            const ctlTypeCode = method === 'SCS' ? 'SCS' : (method === 'MANUAL' || method === 'LIMITED' || method === 'UNLIMITED' ? 'UPD' : 'ECR');

            responseText.textContent =
                PERTII18n.t('gdt.ecr.requestProcessed') + '\n' +
                '=====================\n' +
                PERTII18n.t('gdt.flight.flightLabel') + ': ' + payload.acid + '\n' +
                PERTII18n.t('gdt.ecr.method') + ': ' + method + '\n' +
                PERTII18n.t('gdt.edct.newEdctLabel') + ': ' + payload.new_edct + '\n' +
                PERTII18n.t('gdt.flight.controlType') + ': ' + ctlTypeCode + '\n' +
                PERTII18n.t('gdt.flight.status') + ': ' + PERTII18n.t('gdt.ecr.accepted');
        }

        if (window.Swal) {
            window.Swal.fire({
                icon: 'success',
                title: PERTII18n.t('gdt.ecr.requestSent'),
                text: PERTII18n.t('gdt.ecr.requestProcessedFor', { acid: payload.acid }),
                timer: 3000,
            });
        }
    }

    function ecrClearAll() {
        ECR_CURRENT_FLIGHT = null;

        // Clear input fields
        const fields = ['ecr_acid', 'ecr_orig', 'ecr_dest', 'ecr_earliest_edct', 'ecr_manual_edct', 'ecr_manual_cta'];
        fields.forEach(function(id) {
            const el = document.getElementById(id);
            if (el) {el.value = '';}
        });

        // Reset radio to SCS
        const scsRadio = document.getElementById('ecr_method_scs');
        if (scsRadio) {scsRadio.checked = true;}

        // Hide sections
        const sections = ['ecr_flight_data_section', 'ecr_update_section', 'ecr_model_results', 'ecr_response_section', 'ecr_manual_section'];
        sections.forEach(function(id) {
            const el = document.getElementById(id);
            if (el) {el.style.display = 'none';}
        });

        // Reset CTA range
        const ctaRange = document.getElementById('ecr_cta_range');
        const maxDelay = document.getElementById('ecr_max_add_delay');
        if (ctaRange) {ctaRange.value = 60;}
        if (maxDelay) {maxDelay.value = 60;}

        // Disable send button
        const sendBtn = document.getElementById('ecr_send_request_btn');
        if (sendBtn) {sendBtn.disabled = true;}
    }

    // Open ECR modal for a specific flight (called from flight table row click)
    function openEcrForFlight(callsign, orig, dest) {
        // Populate the ECR modal fields
        const acidEl = document.getElementById('ecr_acid');
        const origEl = document.getElementById('ecr_orig');
        const destEl = document.getElementById('ecr_dest');

        if (acidEl) {acidEl.value = callsign || '';}
        if (origEl) {origEl.value = orig || '';}
        if (destEl) {destEl.value = dest || '';}

        // Open the modal
        if (window.jQuery && window.jQuery.fn.modal) {
            window.jQuery('#ecr_modal').modal('show');
        }

        // Auto-fetch flight data
        if (callsign) {
            setTimeout(function() {
                ecrGetFlightData();
            }, 300);
        }
    }

    // Delegated click handler for ECR links in flight tables
    document.addEventListener('click', function(ev) {
        const link = ev.target.closest('.ecr-link');
        if (!link) {return;}

        ev.preventDefault();
        ev.stopPropagation();

        const row = link.closest('tr');
        if (!row) {return;}

        const callsign = row.getAttribute('data-callsign') || '';
        const orig = row.getAttribute('data-orig') || '';
        const dest = row.getAttribute('data-dest') || '';

        openEcrForFlight(callsign, orig, dest);
    });

    // Global variable for Flights Matching table sort state
    let GS_MATCHING_SORT = { field: 'acid', order: 'asc' };
    let GS_MATCHING_FLIGHTS = [];
    let GS_MATCHING_ROWS = [];

    // Initialize default GS Start (current UTC) and End (+1 hour, ending on :14/:29/:44/:59)
    function initializeDefaultGsTimes() {
        const gsStartEl = document.getElementById('gs_start');
        const gsEndEl = document.getElementById('gs_end');

        if (!gsStartEl || !gsEndEl) {return;}

        const now = new Date();

        // GS Start: current UTC time
        const startYear = now.getUTCFullYear();
        const startMonth = String(now.getUTCMonth() + 1).padStart(2, '0');
        const startDay = String(now.getUTCDate()).padStart(2, '0');
        const startHour = String(now.getUTCHours()).padStart(2, '0');
        const startMin = String(now.getUTCMinutes()).padStart(2, '0');
        gsStartEl.value = startYear + '-' + startMonth + '-' + startDay + 'T' + startHour + ':' + startMin;

        // GS End: current UTC + 1 hour, but end on :14, :29, :44, or :59
        let endTime = new Date(now.getTime() + 60 * 60 * 1000); // +1 hour
        const endMinutes = endTime.getUTCMinutes();

        // Find nearest :14, :29, :44, or :59
        const endPoints = [14, 29, 44, 59];
        let targetMin = 59;
        for (let i = 0; i < endPoints.length; i++) {
            if (endMinutes <= endPoints[i]) {
                targetMin = endPoints[i];
                break;
            }
        }
        // If minutes > 59, roll to next hour at :14
        if (endMinutes > 59) {
            endTime = new Date(endTime.getTime() + (60 - endMinutes + 14) * 60 * 1000);
            targetMin = 14;
        }

        endTime.setUTCMinutes(targetMin);
        endTime.setUTCSeconds(0);

        const endYear = endTime.getUTCFullYear();
        const endMonth = String(endTime.getUTCMonth() + 1).padStart(2, '0');
        const endDay = String(endTime.getUTCDate()).padStart(2, '0');
        const endHour = String(endTime.getUTCHours()).padStart(2, '0');
        const endMinFormatted = String(endTime.getUTCMinutes()).padStart(2, '0');
        gsEndEl.value = endYear + '-' + endMonth + '-' + endDay + 'T' + endHour + ':' + endMinFormatted;
    }

    /**
     * Load a program for editing from URL parameter ?edit=ID
     * Fetches program data and populates the form
     */
    function loadProgramForEdit(programId) {
        if (!programId) {return Promise.resolve(false);}

        const statusEl = document.getElementById('gs_status_message');
        if (statusEl) {statusEl.textContent = PERTII18n.t('gdt.edit.loadingProgram', { id: programId });}

        return fetch('api/tmi/programs.php?id=' + encodeURIComponent(programId), {
            cache: 'no-cache',
        })
            .then(function(res) { return res.json(); })
            .then(function(resp) {
                if (resp.status !== 'ok' || !resp.data) {
                    throw new Error(resp.message || PERTII18n.t('gdt.edit.programNotFound'));
                }
                const prog = resp.data;

                // Set the current program ID
                GS_CURRENT_PROGRAM_ID = prog.program_id;

                // Populate basic fields
                const setValue = function(id, val) {
                    const el = document.getElementById(id);
                    if (el) {el.value = val || '';}
                };

                setValue('gs_ctl_element', prog.ctl_element);
                setValue('gs_element_type', prog.element_type || 'APT');
                setValue('gs_name', prog.program_name);
                setValue('gs_adv_number', prog.adv_number);
                setValue('gs_impacting_condition', prog.impacting_condition);
                setValue('gs_comments', prog.comments);

                // Program type - determine if GS or GDP/AFP
                const progType = (prog.program_type || 'GS').toUpperCase();
                const gsType = document.getElementById('gs_type');
                if (gsType) {
                    if (progType === 'GS') {
                        gsType.value = 'GS';
                    } else if (progType.indexOf('GDP') !== -1) {
                        gsType.value = 'GDP';
                    } else if (progType.indexOf('AFP') !== -1) {
                        gsType.value = 'AFP';
                    }
                }

                // Parse and set times
                function isoToDatetimeLocal(isoStr) {
                    if (!isoStr) {return '';}
                    const d = new Date(isoStr);
                    if (isNaN(d.getTime())) {return '';}
                    const y = d.getUTCFullYear();
                    const m = String(d.getUTCMonth() + 1).padStart(2, '0');
                    const dy = String(d.getUTCDate()).padStart(2, '0');
                    const h = String(d.getUTCHours()).padStart(2, '0');
                    const mi = String(d.getUTCMinutes()).padStart(2, '0');
                    return y + '-' + m + '-' + dy + 'T' + h + ':' + mi;
                }

                setValue('gs_start', isoToDatetimeLocal(prog.start_utc));
                setValue('gs_end', isoToDatetimeLocal(prog.end_utc));

                // Airports (ctl_element for single airport programs)
                if (prog.ctl_element) {
                    setValue('gs_airports', prog.ctl_element);
                }

                // Parse scope_json if available
                let scope = null;
                if (prog.scope_json) {
                    try {
                        scope = typeof prog.scope_json === 'string' ? JSON.parse(prog.scope_json) : prog.scope_json;
                    } catch (e) {
                        console.warn('Failed to parse scope_json', e);
                    }
                }

                // Populate scope selector if scope data exists
                if (scope) {
                    const scopeSel = document.getElementById('gs_scope_select');
                    if (scopeSel) {
                    // Clear current selection
                        Array.prototype.forEach.call(scopeSel.options, function(opt) {
                            opt.selected = false;
                        });

                        // Select matching options based on scope tokens
                        let scopeTokens = scope.tokens || scope.origin_centers || [];
                        if (typeof scopeTokens === 'string') {
                            scopeTokens = scopeTokens.split(/\s+/).filter(Boolean);
                        }

                        scopeTokens.forEach(function(token) {
                            const tok = token.toUpperCase();
                            Array.prototype.forEach.call(scopeSel.options, function(opt) {
                                if (opt.value.toUpperCase() === tok) {
                                    opt.selected = true;
                                }
                            });
                        });

                        // Also populate hidden origin_centers field
                        setValue('gs_origin_centers', scopeTokens.join(' '));

                        // Populate dep_facilities from scope if available
                        if (scope.dep_facilities) {
                            const depFac = Array.isArray(scope.dep_facilities)
                                ? scope.dep_facilities.join(' ')
                                : scope.dep_facilities;
                            setValue('gs_dep_facilities', depFac);
                        }
                    }
                }

                // Parse exemptions_json if available
                let exemptions = null;
                if (prog.exemptions_json) {
                    try {
                        exemptions = typeof prog.exemptions_json === 'string'
                            ? JSON.parse(prog.exemptions_json)
                            : prog.exemptions_json;
                    } catch (e) {
                        console.warn('Failed to parse exemptions_json', e);
                    }
                }

                if (exemptions) {
                    if (exemptions.orig_airports) {setValue('gs_exempt_orig_airports', exemptions.orig_airports.join(' '));}
                    if (exemptions.orig_tracons) {setValue('gs_exempt_orig_tracons', exemptions.orig_tracons.join(' '));}
                    if (exemptions.orig_artccs) {setValue('gs_exempt_orig_artccs', exemptions.orig_artccs.join(' '));}
                    if (exemptions.dest_airports) {setValue('gs_exempt_dest_airports', exemptions.dest_airports.join(' '));}
                    if (exemptions.dest_tracons) {setValue('gs_exempt_dest_tracons', exemptions.dest_tracons.join(' '));}
                    if (exemptions.dest_artccs) {setValue('gs_exempt_dest_artccs', exemptions.dest_artccs.join(' '));}
                    if (exemptions.exempt_flights) {setValue('gs_exempt_flights', exemptions.exempt_flights.join(' '));}
                }

                // Update status
                const statusStr = prog.status || 'PROPOSED';
                GS_CURRENT_PROGRAM_STATUS = statusStr;
                if (statusEl) {
                    statusEl.textContent = PERTII18n.t('gdt.edit.editingProgram', { type: progType, id: programId, status: statusStr });
                }

                // Update status badge
                const badge = document.getElementById('gs_status_badge');
                if (badge) {
                    badge.textContent = statusStr;
                    badge.className = 'badge tmi-badge-status badge-' + getStatusBadgeClass(statusStr);
                }

                // Set workflow stepper based on program status
                if (statusStr === 'ACTIVE' || statusStr === 'TRANSITIONED') {
                    setWorkflowState('active');
                } else if (statusStr === 'MODELING') {
                    setWorkflowState('model');
                } else if (statusStr === 'PROPOSED' || statusStr === 'PENDING_COORD') {
                    setWorkflowState('preview');
                } else {
                    setWorkflowState('configure');
                }

                // Rebuild the advisory preview
                buildAdvisory();

                console.log('[GDT] Loaded program for edit:', prog);
                return true;
            })
            .catch(function(err) {
                console.error('Failed to load program for edit:', err);
                if (statusEl) {statusEl.textContent = PERTII18n.t('gdt.edit.loadFailed') + ': ' + (err.message || err);}
                return false;
            });
    }

    /**
     * Check URL for edit parameter and load program if present
     */
    function checkUrlForEditMode() {
        const urlParams = new URLSearchParams(window.location.search);
        const editId = urlParams.get('edit');
        if (editId) {
            loadProgramForEdit(editId);
        }
    }

    // Sort and re-render the Flights Matching table
    function sortAndRenderMatchingTable() {
        if (!GS_MATCHING_ROWS || !GS_MATCHING_ROWS.length) {return;}

        const field = GS_MATCHING_SORT.field;
        const order = GS_MATCHING_SORT.order;

        // Sort the processed rows
        GS_MATCHING_ROWS.sort(function(a, b) {
            let valA, valB;
            switch (field) {
                case 'acid':
                    valA = (a.callsign || '').toLowerCase();
                    valB = (b.callsign || '').toLowerCase();
                    break;
                case 'etd':
                    valA = a.etdEpoch || 0;
                    valB = b.etdEpoch || 0;
                    break;
                case 'edct':
                    valA = a.edctEpoch || 0;
                    valB = b.edctEpoch || 0;
                    break;
                case 'eta':
                    valA = a.roughEtaEpoch || 0;
                    valB = b.roughEtaEpoch || 0;
                    break;
                case 'dcenter':
                    valA = (a.dep_artcc || AIRPORT_CENTER_MAP[(a.dep || '').toUpperCase()] || '').toLowerCase();
                    valB = (b.dep_artcc || AIRPORT_CENTER_MAP[(b.dep || '').toUpperCase()] || '').toLowerCase();
                    break;
                case 'orig':
                    valA = (a.dep || '').toLowerCase();
                    valB = (b.dep || '').toLowerCase();
                    break;
                case 'dest':
                    valA = (a.arr || '').toLowerCase();
                    valB = (b.arr || '').toLowerCase();
                    break;
                default:
                    valA = (a.callsign || '').toLowerCase();
                    valB = (b.callsign || '').toLowerCase();
            }

            if (valA < valB) {return order === 'asc' ? -1 : 1;}
            if (valA > valB) {return order === 'asc' ? 1 : -1;}
            return 0;
        });

        // Re-render the table with sorted rows
        renderSortedMatchingTable(GS_MATCHING_ROWS);

        // Update sort indicators in header
        updateSortIndicators();
    }

    // Update sort direction indicators in table headers
    function updateSortIndicators() {
        const headers = document.querySelectorAll('.gs-matching-sortable');
        headers.forEach(function(th) {
            const icon = th.querySelector('i.fas');
            if (!icon) {return;}

            const sortField = th.getAttribute('data-sort');
            if (sortField === GS_MATCHING_SORT.field) {
                icon.className = GS_MATCHING_SORT.order === 'asc'
                    ? 'fas fa-sort-up fa-xs'
                    : 'fas fa-sort-down fa-xs';
            } else {
                icon.className = 'fas fa-sort fa-xs text-muted';
            }
        });
    }

    // Render the sorted matching flights table
    function renderSortedMatchingTable(rows) {
        const tbody = document.getElementById('gs_flight_table_body');
        if (!tbody) {return;}

        // Get airport colors for destination coloring
        const apStr = getValue('gs_airports') || '';
        const apTokens = apStr.toUpperCase().split(/\s+/).filter(function(x) { return x.length > 0; });
        const airports = expandAirportTokensWithFacilities(apTokens);
        const airportColors = buildAirportColorMap(airports);

        GS_FLIGHT_ROW_INDEX = {};
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-muted text-center py-3">' + PERTII18n.t('gdt.table.noFlightsMatching') + '</td></tr>';
            return;
        }

        let html = '';
        rows.forEach(function(r) {
            const cs = r.callsign || '';
            const rowId = makeRowIdForCallsign(cs);
            GS_FLIGHT_ROW_INDEX[rowId] = r;

            const dep = (r.dep || '').toUpperCase();
            const arr = (r.arr || '').toUpperCase();
            const center = r.dep_artcc || AIRPORT_CENTER_MAP[dep] || '';
            const destColor = airportColors[arr] || '';

            // Build status column from control type and delay status
            let statusText = '';
            if (r._adl && r._adl.raw) {
                const ctlType = r._adl.raw.ctl_type || r._adl.raw.CTL_TYPE || '';
                const delayStatus = r._adl.raw.delay_status || r._adl.raw.DELAY_STATUS || '';
                const flightStatus = r.flightStatus || '';
                statusText = ctlType || delayStatus || flightStatus || '';
            }

            html += '<tr id="' + escapeHtml(rowId) + '" ' +
                'data-callsign="' + escapeHtml(cs) + '" ' +
                'data-route="' + escapeHtml(r.route || '') + '" ' +
                'data-filed-dep-epoch="' + (r.filedDepEpoch != null ? String(r.filedDepEpoch) : '') + '" ' +
                'data-etd-epoch="' + (r.etdEpoch != null ? String(r.etdEpoch) : '') + '" ' +
                'data-edct-epoch="' + (r.edctEpoch != null ? String(r.edctEpoch) : '') + '" ' +
                'data-eta-epoch="' + (r.roughEtaEpoch != null ? String(r.roughEtaEpoch) : '') + '" ' +
                'data-takeoff-epoch="' + (r.tkofEpoch != null ? String(r.tkofEpoch) : '') + '" ' +
                'data-mft-epoch="' + (r.mftEpoch != null ? String(r.mftEpoch) : '') + '" ' +
                'data-vt-epoch="' + (r.vtEpoch != null ? String(r.vtEpoch) : '') + '" ' +
                'data-ete-minutes="' + (r.eteMinutes != null ? String(r.eteMinutes) : '') + '"' +
                '>' +
                '<td><strong>' + escapeHtml(cs) + '</strong></td>' +
                '<td class="gs_etd_cell">' + (r.etdEpoch ? (escapeHtml(r.etdPrefix || '') + ' ' + escapeHtml(formatZuluFromEpoch(r.etdEpoch))) : '') + '</td>' +
                '<td class="gs_edct_cell">' + (r.edctEpoch ? escapeHtml(formatZuluFromEpoch(r.edctEpoch)) : '') + '</td>' +
                '<td class="gs_eta_cell" style="color:' + escapeHtml(destColor) + ';">' +
                    (r.roughEtaEpoch ? (escapeHtml(r.etaPrefix || '') + ' ' + escapeHtml(formatZuluFromEpoch(r.roughEtaEpoch))) : '') + '</td>' +
                '<td>' + escapeHtml(center) + '</td>' +
                '<td>' + escapeHtml(dep) + '</td>' +
                '<td style="color:' + escapeHtml(destColor) + ';">' + escapeHtml(arr) + '</td>' +
                '<td>' + escapeHtml(statusText) + '</td>' +
                '</tr>';
        });

        tbody.innerHTML = html;
        applyTimeFilterToTable();
    }

    // Open Model GS Section
    function openModelGsSection() {
        const section = document.getElementById('gs_model_section');
        if (section) {
            section.style.display = '';
            section.scrollIntoView({ behavior: 'smooth', block: 'start' });

            // Render the data graph with current flight list data
            if (GS_FLIGHT_LIST_DATA || GS_LAST_SIMULATION_DATA) {
                const data = GS_FLIGHT_LIST_DATA || GS_LAST_SIMULATION_DATA;
                const payload = GS_FLIGHT_LIST_PAYLOAD || collectGsWorkflowPayload();
                renderModelGsDataGraph(data, payload);
                renderModelGsSummaryTables(data);
            }

            // Initialize and load demand chart
            initGsDemandChart();
        }
    }

    // Initialize GS Demand Chart (ECharts-based FSM/TBFM style)
    function initGsDemandChart() {
        if (!window.DemandChart) {
            console.warn('DemandChart module not loaded');
            return;
        }

        // Get first arrival airport from GS airports field
        const apStr = getValue('gs_airports') || getValue('gs_ctl_element') || '';
        const airports = apStr.toUpperCase().split(/\s+/).filter(function(x) { return x.length >= 3; });
        const airport = airports[0] || null;

        // Create chart instance if not already created
        if (!GS_DEMAND_CHART) {
            const chartContainer = document.getElementById('gs_demand_chart');
            if (chartContainer) {
                GS_DEMAND_CHART = window.DemandChart.create(chartContainer, {
                    direction: GS_DEMAND_DIRECTION,
                    granularity: GS_DEMAND_GRANULARITY,
                    timeRangeStart: -2,
                    timeRangeEnd: 14,
                });
            }
        }

        // Load data for the airport
        if (airport && GS_DEMAND_CHART) {
            loadGsDemandData(airport);
        }
    }

    // Load demand data for an airport
    function loadGsDemandData(airport) {
        if (!GS_DEMAND_CHART || !airport) {return;}

        // Normalize airport code to ICAO (region-aware: US, Canada, Alaska, etc.)
        airport = (typeof PERTI !== 'undefined' && PERTI.normalizeIcao)
            ? PERTI.normalizeIcao(airport)
            : (airport.length === 3 && !/^[PK]/.test(airport)) ? 'K' + airport : airport;

        // Update UI
        const badgeEl = document.getElementById('gs_demand_airport_badge');
        if (badgeEl) {badgeEl.textContent = airport;}

        // Get time basis from selector (default to 'eta', use 'ctd' to show TMI status colors)
        const timeBasisEl = document.getElementById('gs_model_time_basis');
        let timeBasis = timeBasisEl ? timeBasisEl.value : 'eta';
        console.log('[GDT] loadGsDemandData - raw timeBasis:', timeBasis);

        // Map CTD/CTA values to 'ctd' for the API (TMI status view)
        // Map ETD/ETA values to 'eta' for the API (flight phase view)
        if (timeBasis === 'ctd' || timeBasis === 'cta') {
            timeBasis = 'ctd';
        } else {
            timeBasis = 'eta';
        }
        console.log('[GDT] loadGsDemandData - mapped timeBasis:', timeBasis, 'programId:', GS_CURRENT_PROGRAM_ID);

        // Load demand data with time basis for TMI status coloring
        GS_DEMAND_CHART.load(airport, {
            direction: GS_DEMAND_DIRECTION,
            granularity: GS_DEMAND_GRANULARITY,
            timeBasis: timeBasis,
            programId: GS_CURRENT_PROGRAM_ID || null,
        }).then(function(result) {
            if (result.success && result.rates) {
                updateGsDemandRateInfo(result.rates);
            }
            // Update last update time
            const updateEl = document.getElementById('gs_demand_last_update');
            if (updateEl) {
                const now = new Date();
                updateEl.textContent = now.getUTCHours().toString().padStart(2, '0') + ':' +
                                      now.getUTCMinutes().toString().padStart(2, '0') + ':' +
                                      now.getUTCSeconds().toString().padStart(2, '0') + 'Z';
            }
        }).catch(function(err) {
            console.error('Failed to load demand data:', err);
        });
    }

    // Update demand rate info display in the Model section
    function updateGsDemandRateInfo(rateData) {
        if (!rateData) {return;}

        // Config name
        const configEl = document.getElementById('gs_demand_config_name');
        if (configEl) {configEl.textContent = rateData.config_name || '--';}

        // Weather badge
        const weatherEl = document.getElementById('gs_demand_weather_badge');
        if (weatherEl) {
            const weatherCat = rateData.weather_category || 'VMC';
            weatherEl.textContent = weatherCat;
            // Apply weather color from rate-colors.js if available
            if (typeof RATE_LINE_CONFIG !== 'undefined' && RATE_LINE_CONFIG.weatherColors) {
                weatherEl.style.backgroundColor = RATE_LINE_CONFIG.weatherColors[weatherCat] || '#22c55e';
            }
        }

        // AAR/ADR values
        const aarEl = document.getElementById('gs_demand_aar');
        const adrEl = document.getElementById('gs_demand_adr');
        if (aarEl) {aarEl.textContent = rateData.rates && rateData.rates.vatsim_aar ? rateData.rates.vatsim_aar : '--';}
        if (adrEl) {adrEl.textContent = rateData.rates && rateData.rates.vatsim_adr ? rateData.rates.vatsim_adr : '--';}

        // Rate source
        const sourceEl = document.getElementById('gs_demand_rate_source');
        if (sourceEl) {
            let sourceText = '--';
            if (rateData.match_type) {
                const matchTypeMap = {
                    'EXACT': PERTII18n.t('nod.matchType.exact'), 'PARTIAL_ARR': PERTII18n.t('nod.matchType.partial'), 'PARTIAL_DEP': PERTII18n.t('nod.matchType.partial'),
                    'SUBSET_ARR': PERTII18n.t('nod.matchType.subset'), 'SUBSET_DEP': PERTII18n.t('nod.matchType.subset'), 'WIND_BASED': PERTII18n.t('nod.matchType.wind'),
                    'CAPACITY_DEFAULT': PERTII18n.t('nod.matchType.default'), 'VMC_FALLBACK': PERTII18n.t('nod.matchType.fallback'),
                    'DETECTED_TRACKS': PERTII18n.t('gdt.page.detectedLabel'), 'MANUAL': PERTII18n.t('gdt.page.manualLabel'),
                };
                sourceText = matchTypeMap[rateData.match_type] || rateData.match_type;
            } else if (rateData.is_suggested) {
                sourceText = PERTII18n.t('nod.matchType.suggested');
            }
            sourceEl.textContent = sourceText;
        }
    }

    // Initialize demand chart control handlers
    function initGsDemandChartHandlers() {
        // Direction toggle buttons
        const dirBothBtn = document.getElementById('gs_demand_dir_both');
        const dirArrBtn = document.getElementById('gs_demand_dir_arr');
        const dirDepBtn = document.getElementById('gs_demand_dir_dep');

        function setDirectionActive(activeBtn) {
            [dirBothBtn, dirArrBtn, dirDepBtn].forEach(function(btn) {
                if (btn) {
                    btn.classList.remove('btn-light', 'active');
                    btn.classList.add('btn-outline-light');
                }
            });
            if (activeBtn) {
                activeBtn.classList.remove('btn-outline-light');
                activeBtn.classList.add('btn-light', 'active');
            }
        }

        if (dirBothBtn) {
            dirBothBtn.addEventListener('click', function() {
                GS_DEMAND_DIRECTION = 'both';
                setDirectionActive(dirBothBtn);
                if (GS_DEMAND_CHART) {GS_DEMAND_CHART.update({ direction: 'both' });}
            });
        }
        if (dirArrBtn) {
            dirArrBtn.addEventListener('click', function() {
                GS_DEMAND_DIRECTION = 'arr';
                setDirectionActive(dirArrBtn);
                if (GS_DEMAND_CHART) {GS_DEMAND_CHART.update({ direction: 'arr' });}
            });
        }
        if (dirDepBtn) {
            dirDepBtn.addEventListener('click', function() {
                GS_DEMAND_DIRECTION = 'dep';
                setDirectionActive(dirDepBtn);
                if (GS_DEMAND_CHART) {GS_DEMAND_CHART.update({ direction: 'dep' });}
            });
        }

        // Granularity toggle buttons
        const gran15Btn = document.getElementById('gs_demand_gran_15');
        const gran30Btn = document.getElementById('gs_demand_gran_30');
        const gran60Btn = document.getElementById('gs_demand_gran_60');

        function setGranularityActive(activeBtn) {
            [gran15Btn, gran30Btn, gran60Btn].forEach(function(btn) {
                if (btn) {
                    btn.classList.remove('btn-light', 'active');
                    btn.classList.add('btn-outline-light');
                }
            });
            if (activeBtn) {
                activeBtn.classList.remove('btn-outline-light');
                activeBtn.classList.add('btn-light', 'active');
            }
        }

        if (gran15Btn) {
            gran15Btn.addEventListener('click', function() {
                GS_DEMAND_GRANULARITY = '15min';
                setGranularityActive(gran15Btn);
                if (GS_DEMAND_CHART) {GS_DEMAND_CHART.update({ granularity: '15min' });}
            });
        }
        if (gran30Btn) {
            gran30Btn.addEventListener('click', function() {
                GS_DEMAND_GRANULARITY = '30min';
                setGranularityActive(gran30Btn);
                if (GS_DEMAND_CHART) {GS_DEMAND_CHART.update({ granularity: '30min' });}
            });
        }
        if (gran60Btn) {
            gran60Btn.addEventListener('click', function() {
                GS_DEMAND_GRANULARITY = 'hourly';
                setGranularityActive(gran60Btn);
                if (GS_DEMAND_CHART) {GS_DEMAND_CHART.update({ granularity: 'hourly' });}
            });
        }

        // Refresh button
        const refreshBtn = document.getElementById('gs_demand_refresh_btn');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', function() {
                const apStr = getValue('gs_airports') || getValue('gs_ctl_element') || '';
                const airports = apStr.toUpperCase().split(/\s+/).filter(function(x) { return x.length >= 3; });
                const airport = airports[0] || null;
                if (airport) {
                    loadGsDemandData(airport);
                }
            });
        }
    }

    // Close Model GS Section
    function closeModelGsSection() {
        const section = document.getElementById('gs_model_section');
        if (section) {
            section.style.display = 'none';
        }
    }

    // Model GS Data Graph - Enhanced Power Run Analysis
    let GS_MODEL_GRAPH_CHART = null;
    let GS_MODEL_COMPARISON_CHART = null;
    let GS_MODEL_CHART_TYPE = 'bar';
    let GS_MODEL_CURRENT_DATA = null;
    let GS_MODEL_CURRENT_PAYLOAD = null;

    // Demand Chart Integration (using shared DemandChart module)
    let GS_DEMAND_CHART = null;
    let GS_DEMAND_DIRECTION = 'both';
    let GS_DEMAND_GRANULARITY = 'hourly';

    // Initialize Model GS event handlers
    function initModelGsHandlers() {
        // Chart view selector
        const chartViewEl = document.getElementById('gs_model_chart_view');
        if (chartViewEl) {
            chartViewEl.addEventListener('change', function() {
                if (GS_MODEL_CURRENT_DATA) {
                    renderModelGsDataGraph(GS_MODEL_CURRENT_DATA, GS_MODEL_CURRENT_PAYLOAD);
                }
            });
        }

        // Time window selector
        const timeWindowEl = document.getElementById('gs_model_time_window');
        if (timeWindowEl) {
            timeWindowEl.addEventListener('change', function() {
                if (GS_MODEL_CURRENT_DATA) {
                    renderModelGsDataGraph(GS_MODEL_CURRENT_DATA, GS_MODEL_CURRENT_PAYLOAD);
                    renderModelGsSummaryTables(GS_MODEL_CURRENT_DATA);
                }
            });
        }

        // Time basis selector
        const timeBasisEl = document.getElementById('gs_model_time_basis');
        if (timeBasisEl) {
            timeBasisEl.addEventListener('change', function() {
                if (GS_MODEL_CURRENT_DATA) {
                    renderModelGsDataGraph(GS_MODEL_CURRENT_DATA, GS_MODEL_CURRENT_PAYLOAD);
                    renderComparisonChart(GS_MODEL_CURRENT_DATA);
                }
                // Also reload the demand chart with new time basis (for TMI status colors)
                const apStr = getValue('gs_airports') || getValue('gs_ctl_element') || '';
                const airports = apStr.toUpperCase().split(/\s+/).filter(function(x) { return x.length >= 3; });
                const airport = airports[0] || null;
                if (airport && GS_DEMAND_CHART) {
                    loadGsDemandData(airport);
                }
            });
        }

        // Filter inputs
        const filterArtccEl = document.getElementById('gs_model_filter_artcc');
        const filterCarrierEl = document.getElementById('gs_model_filter_carrier');
        let filterDebounce = null;
        const onFilterChange = function() {
            clearTimeout(filterDebounce);
            filterDebounce = setTimeout(function() {
                if (GS_MODEL_CURRENT_DATA) {
                    renderModelGsDataGraph(GS_MODEL_CURRENT_DATA, GS_MODEL_CURRENT_PAYLOAD);
                    renderModelGsSummaryTables(GS_MODEL_CURRENT_DATA);
                }
            }, 300);
        };
        if (filterArtccEl) {filterArtccEl.addEventListener('input', onFilterChange);}
        if (filterCarrierEl) {filterCarrierEl.addEventListener('input', onFilterChange);}

        // Chart type toggle buttons
        const barBtn = document.getElementById('gs_model_chart_type_bar');
        const lineBtn = document.getElementById('gs_model_chart_type_line');
        if (barBtn) {
            barBtn.addEventListener('click', function() {
                GS_MODEL_CHART_TYPE = 'bar';
                barBtn.classList.remove('btn-outline-light');
                barBtn.classList.add('btn-light');
                if (lineBtn) { lineBtn.classList.remove('btn-light'); lineBtn.classList.add('btn-outline-light'); }
                if (GS_MODEL_CURRENT_DATA) {renderModelGsDataGraph(GS_MODEL_CURRENT_DATA, GS_MODEL_CURRENT_PAYLOAD);}
            });
        }
        if (lineBtn) {
            lineBtn.addEventListener('click', function() {
                GS_MODEL_CHART_TYPE = 'line';
                lineBtn.classList.remove('btn-outline-light');
                lineBtn.classList.add('btn-light');
                if (barBtn) { barBtn.classList.remove('btn-light'); barBtn.classList.add('btn-outline-light'); }
                if (GS_MODEL_CURRENT_DATA) {renderModelGsDataGraph(GS_MODEL_CURRENT_DATA, GS_MODEL_CURRENT_PAYLOAD);}
            });
        }
    }

    // Get filtered flights based on current filter settings
    function getFilteredModelFlights(flightListData) {
        const flights = flightListData.flights || [];

        // Time window filter
        const timeWindowEl = document.getElementById('gs_model_time_window');
        const timeWindow = timeWindowEl ? timeWindowEl.value : 'all';

        // ARTCC filter
        const artccFilterEl = document.getElementById('gs_model_filter_artcc');
        const artccFilter = artccFilterEl ? artccFilterEl.value.toUpperCase().split(/\s+/).filter(function(x) { return x.length > 0; }) : [];

        // Carrier filter
        const carrierFilterEl = document.getElementById('gs_model_filter_carrier');
        const carrierFilter = carrierFilterEl ? carrierFilterEl.value.toUpperCase().split(/\s+/).filter(function(x) { return x.length > 0; }) : [];

        const nowMs = Date.now();

        return flights.filter(function(f) {
            // Time window filter
            if (timeWindow !== 'all') {
                const windowMin = parseInt(timeWindow, 10);
                const timeStr = f.ctd_utc || f.etd_utc;
                if (timeStr) {
                    try {
                        const d = new Date(timeStr);
                        const diffMin = (d.getTime() - nowMs) / 60000;
                        if (diffMin < 0 || diffMin > windowMin) {return false;}
                    } catch (e) { return false; }
                }
            }

            // ARTCC filter
            if (artccFilter.length > 0) {
                const origArtcc = (f.dcenter || f.dep_center || f.fp_dept_artcc || '').toUpperCase();
                if (artccFilter.indexOf(origArtcc) === -1) {return false;}
            }

            // Carrier filter
            if (carrierFilter.length > 0) {
                const carrier = extractCarrier(f.acid || f.callsign || '').toUpperCase();
                if (carrierFilter.indexOf(carrier) === -1) {return false;}
            }

            return true;
        });
    }

    // Get time value based on selected time basis
    function getFlightTimeForBasis(f) {
        const timeBasisEl = document.getElementById('gs_model_time_basis');
        const basis = timeBasisEl ? timeBasisEl.value : 'ctd';

        switch (basis) {
            case 'ctd': return f.ctd_utc || f.etd_utc;
            case 'etd': return f.oetd_utc || f.etd_utc || f.betd_utc;
            case 'cta': return f.cta_utc || f.eta_utc;
            case 'eta': return f.oeta_utc || f.eta_utc || f.beta_utc;
            default: return f.ctd_utc || f.etd_utc;
        }
    }

    function renderModelGsDataGraph(flightListData, gsPayload) {
        GS_MODEL_CURRENT_DATA = flightListData;
        GS_MODEL_CURRENT_PAYLOAD = gsPayload;

        const canvas = document.getElementById('gs_model_data_graph_canvas');
        if (!canvas) {return;}

        // Destroy existing chart
        if (GS_MODEL_GRAPH_CHART) {
            GS_MODEL_GRAPH_CHART.destroy();
            GS_MODEL_GRAPH_CHART = null;
        }

        const filteredFlights = getFilteredModelFlights(flightListData);

        // Calculate summary stats
        const totalFlts = filteredFlights.length;
        let affectedFlts = 0;
        let totalDelay = 0;
        let maxDelay = 0;
        let count60 = 0, count30 = 0, count15 = 0;
        const nowMs = Date.now();

        filteredFlights.forEach(function(f) {
            const delay = f.program_delay_min || 0;
            if (delay > 0) {
                affectedFlts++;
                totalDelay += delay;
                if (delay > maxDelay) {maxDelay = delay;}
            }

            // Horizon counts
            const timeStr = f.ctd_utc || f.etd_utc;
            if (timeStr) {
                try {
                    const d = new Date(timeStr);
                    const diffMin = (d.getTime() - nowMs) / 60000;
                    if (diffMin >= 0 && diffMin <= 60) { count60++; if (diffMin <= 30) { count30++; if (diffMin <= 15) {count15++;} } }
                } catch (e) { }
            }
        });

        const avgDelay = affectedFlts > 0 ? Math.round(totalDelay / affectedFlts) : 0;

        // Update summary stats
        let el;
        el = document.getElementById('gs_model_total_flts'); if (el) {el.textContent = totalFlts;}
        el = document.getElementById('gs_model_affected_flts'); if (el) {el.textContent = affectedFlts;}
        el = document.getElementById('gs_model_total_delay'); if (el) {el.textContent = PERTII18n.t('gdt.dashboard.delayMinutes', { delay: totalDelay });}
        el = document.getElementById('gs_model_max_delay'); if (el) {el.textContent = PERTII18n.t('gdt.dashboard.delayMinutes', { delay: maxDelay });}
        el = document.getElementById('gs_model_avg_delay'); if (el) {el.textContent = PERTII18n.t('gdt.dashboard.delayMinutes', { delay: avgDelay });}
        el = document.getElementById('gs_model_horizon_60'); if (el) {el.textContent = PERTII18n.t('gdt.dashboard.flightsCount', { count: count60 });}
        el = document.getElementById('gs_model_horizon_30'); if (el) {el.textContent = PERTII18n.t('gdt.dashboard.flightsCount', { count: count30 });}
        el = document.getElementById('gs_model_horizon_15'); if (el) {el.textContent = PERTII18n.t('gdt.dashboard.flightsCount', { count: count15 });}

        el = document.getElementById('gs_model_ctl_element'); if (el) {el.textContent = gsPayload.gs_ctl_element || '-';}
        el = document.getElementById('gs_model_gs_start'); if (el) {el.textContent = gsPayload.gs_start ? formatZuluFromIso(gsPayload.gs_start) : '-';}
        el = document.getElementById('gs_model_gs_end'); if (el) {el.textContent = gsPayload.gs_end ? formatZuluFromIso(gsPayload.gs_end) : '-';}

        if (!filteredFlights.length) {
            canvas.parentElement.innerHTML = '<canvas id="gs_model_data_graph_canvas"></canvas><div class="text-center text-muted py-5">' + PERTII18n.t('gdt.chart.noFlightData') + '</div>';
            return;
        }

        // Get chart view type
        const chartViewEl = document.getElementById('gs_model_chart_view');
        const chartView = chartViewEl ? chartViewEl.value : 'hourly';

        // Update chart title
        const chartTitleEl = document.getElementById('gs_model_chart_title');
        const chartTitles = {
            'hourly': PERTII18n.t('gdt.chart.titleHourly'),
            'orig_artcc': PERTII18n.t('gdt.chart.titleOrigArtcc'),
            'dest_artcc': PERTII18n.t('gdt.chart.titleDestArtcc'),
            'orig_ap': PERTII18n.t('gdt.chart.titleOrigAp'),
            'dest_ap': PERTII18n.t('gdt.chart.titleDestAp'),
            'orig_tracon': PERTII18n.t('gdt.chart.titleOrigTracon'),
            'dest_tracon': PERTII18n.t('gdt.chart.titleDestTracon'),
            'carrier': PERTII18n.t('gdt.chart.titleCarrier'),
            'tier': PERTII18n.t('gdt.chart.titleTier'),
        };
        if (chartTitleEl) {chartTitleEl.textContent = chartTitles[chartView] || PERTII18n.t('gdt.chart.dataGraph');}

        // Group data based on chart view
        const groupedData = groupFlightsForChart(filteredFlights, chartView);

        let labels = Object.keys(groupedData).sort();
        if (chartView === 'hourly') {
            labels.sort(function(a, b) { return a.localeCompare(b); });
        } else {
            labels.sort(function(a, b) { return (groupedData[b].count || 0) - (groupedData[a].count || 0); });
            labels = labels.slice(0, 15); // Top 15
        }

        if (!labels.length) {
            canvas.parentElement.innerHTML = '<canvas id="gs_model_data_graph_canvas"></canvas><div class="text-center text-muted py-5">' + PERTII18n.t('gdt.chart.noData') + '</div>';
            return;
        }

        const totalFltsData = labels.map(function(k) { return groupedData[k].count || 0; });
        const affectedFltsData = labels.map(function(k) { return groupedData[k].affected || 0; });
        const maxDelayData = labels.map(function(k) { return groupedData[k].maxDelay || 0; });
        const avgDelayData = labels.map(function(k) {
            const g = groupedData[k];
            return g.affected > 0 ? Math.round(g.totalDelay / g.affected) : 0;
        });

        const ctx = canvas.getContext('2d');
        const chartType = GS_MODEL_CHART_TYPE === 'line' ? 'line' : 'bar';

        GS_MODEL_GRAPH_CHART = new Chart(ctx, {
            type: chartType,
            data: {
                labels: labels,
                datasets: [
                    { label: PERTII18n.t('gdt.chart.totalFlts'), data: totalFltsData, backgroundColor: 'rgba(220, 53, 69, 0.7)', borderColor: '#dc3545', borderWidth: 1, yAxisID: 'y' },
                    { label: PERTII18n.t('gdt.chart.affectedFlts'), data: affectedFltsData, backgroundColor: 'rgba(23, 162, 184, 0.7)', borderColor: '#17a2b8', borderWidth: 1, yAxisID: 'y' },
                    { label: PERTII18n.t('gdt.chart.maxDelay'), type: 'line', data: maxDelayData, borderColor: '#343a40', backgroundColor: 'rgba(255,255,255,0.8)', borderWidth: 2, pointRadius: 3, fill: false, yAxisID: 'y1' },
                    { label: PERTII18n.t('gdt.chart.avgDelay'), type: 'line', data: avgDelayData, borderColor: '#6f42c1', borderWidth: 2, pointRadius: 3, fill: false, yAxisID: 'y1' },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: true, position: 'bottom', labels: { font: { size: 10 }, boxWidth: 12 } },
                    title: { display: false },
                },
                scales: {
                    x: { title: { display: true, text: chartView === 'hourly' ? PERTII18n.t('gdt.chart.axisHourUtc') : chartTitles[chartView].replace(PERTII18n.t('gdt.chart.prefixDataGraph') + ' ', ''), font: { size: 10 } } },
                    y: { type: 'linear', position: 'left', title: { display: true, text: PERTII18n.t('gdt.chart.axisFlights'), font: { size: 10 } }, beginAtZero: true },
                    y1: { type: 'linear', position: 'right', title: { display: true, text: PERTII18n.t('gdt.chart.axisDelayMin'), font: { size: 10 } }, beginAtZero: true, grid: { drawOnChartArea: false } },
                },
            },
        });

        // Also render the comparison chart
        renderComparisonChart(flightListData);
    }

    // Group flights based on selected chart view
    function groupFlightsForChart(flights, chartView) {
        const grouped = {};

        flights.forEach(function(f) {
            let key = '';

            switch (chartView) {
                case 'hourly': {
                    const timeStr = getFlightTimeForBasis(f);
                    if (timeStr) {
                        try {
                            const d = new Date(timeStr);
                            if (!isNaN(d.getTime())) {key = String(d.getUTCHours()).padStart(2, '0') + '00Z';}
                        } catch (e) { }
                    }
                    break;
                }
                case 'orig_artcc':
                    key = f.dcenter || f.dep_center || f.fp_dept_artcc || '';
                    break;
                case 'dest_artcc':
                    key = f.acenter || f.arr_center || f.fp_dest_artcc || AIRPORT_CENTER_MAP[(f.dest || f.fp_dest_icao || '').toUpperCase()] || '';
                    break;
                case 'orig_ap':
                    key = f.orig || f.fp_dept_icao || '';
                    break;
                case 'dest_ap':
                    key = f.dest || f.fp_dest_icao || '';
                    break;
                case 'orig_tracon': {
                    const origAp = (f.orig || f.fp_dept_icao || '').toUpperCase();
                    key = AIRPORT_TRACON_MAP[origAp] || '';
                    break;
                }
                case 'dest_tracon': {
                    const destAp = (f.dest || f.fp_dest_icao || '').toUpperCase();
                    key = AIRPORT_TRACON_MAP[destAp] || '';
                    break;
                }
                case 'carrier':
                    key = extractCarrier(f.acid || f.callsign || '');
                    break;
                case 'tier': {
                    const tierOrig = (f.orig || f.fp_dept_icao || '').toUpperCase();
                    key = getTierForAirport(tierOrig) || PERTII18n.t('gdt.chart.other');
                    break;
                }
            }

            if (!key) {return;}

            if (!grouped[key]) {
                grouped[key] = { count: 0, affected: 0, totalDelay: 0, maxDelay: 0 };
            }

            const g = grouped[key];
            g.count++;
            const delay = f.program_delay_min || 0;
            if (delay > 0) {
                g.affected++;
                g.totalDelay += delay;
                if (delay > g.maxDelay) {g.maxDelay = delay;}
            }
        });

        return grouped;
    }

    // Get tier for an airport
    function getTierForAirport(icao) {
        if (!icao) {return null;}
        for (const code in TMI_TIER_INFO_BY_CODE) {
            const entry = TMI_TIER_INFO_BY_CODE[code];
            if (entry.included && entry.included.indexOf(icao) !== -1) {
                return entry.label || entry.code || code;
            }
        }
        return null;
    }

    /**
     * Fetch dynamic proximity tier data from GIS API
     * @param {string} facility - ARTCC code (e.g., "ZFW")
     * @param {number} tier - Tier level (1, 2, etc.)
     * @param {object} options - Optional settings: { include: ["CAN","MEX"], usOnly: true }
     * @returns {Promise<string[]>} - Array of facility codes
     */
    function fetchGisProximityTier(facility, tier, options) {
        if (!facility) {return Promise.resolve([]);}
        options = options || {};

        let url = 'api/tiers/query.php?facility=' + encodeURIComponent(facility) + '&tier=' + tier + '&format=codes';
        if (options.include && options.include.length) {
            url += '&include=' + options.include.join(',');
        }
        if (options.usOnly === false) {
            url += '&us_only=0';
        }

        return fetch(url, { cache: 'no-cache' })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.success && data.facilities) {
                    return data.facilities;
                }
                return [];
            })
            .catch(function(err) {
                console.error('Error fetching GIS proximity tier:', err);
                return [];
            });
    }

    /**
     * Get cumulative facilities for tier range (tier 0 through tierMax)
     * @param {string} facility - ARTCC code (e.g., "ZFW")
     * @param {number} tierMax - Maximum tier (1 = 1stTier, 2 = 2ndTier)
     * @param {object} options - Optional settings
     * @returns {Promise<string[]>} - Array of facility codes
     */
    function fetchGisCumulativeTier(facility, tierMax, options) {
        if (!facility) {return Promise.resolve([]);}
        options = options || {};

        let url = 'api/tiers/query.php?facility=' + encodeURIComponent(facility) +
                  '&tier_min=0&tier_max=' + tierMax + '&format=codes';
        if (options.include && options.include.length) {
            url += '&include=' + options.include.join(',');
        }
        if (options.usOnly === false) {
            url += '&us_only=0';
        }

        return fetch(url, { cache: 'no-cache' })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.success && data.facilities) {
                    return data.facilities;
                }
                return [];
            })
            .catch(function(err) {
                console.error('Error fetching GIS cumulative tier:', err);
                return [];
            });
    }

    // Render Original vs Controlled comparison chart
    function renderComparisonChart(flightListData) {
        const canvas = document.getElementById('gs_model_comparison_canvas');
        if (!canvas) {return;}

        if (GS_MODEL_COMPARISON_CHART) {
            GS_MODEL_COMPARISON_CHART.destroy();
            GS_MODEL_COMPARISON_CHART = null;
        }

        const flights = getFilteredModelFlights(flightListData);
        if (!flights.length) {return;}

        // Group by hour - Original (ETD) vs Controlled (CTD)
        const hourlyOrig = {};
        const hourlyCtrl = {};

        flights.forEach(function(f) {
            const delay = f.program_delay_min || 0;

            // Original time (ETD)
            const origTime = f.oetd_utc || f.etd_utc || f.betd_utc;
            if (origTime) {
                try {
                    const dOrig = new Date(origTime);
                    if (!isNaN(dOrig.getTime())) {
                        const keyOrig = String(dOrig.getUTCHours()).padStart(2, '0') + '00Z';
                        if (!hourlyOrig[keyOrig]) {hourlyOrig[keyOrig] = 0;}
                        hourlyOrig[keyOrig]++;
                    }
                } catch (e) { }
            }

            // Controlled time (CTD)
            const ctrlTime = f.ctd_utc;
            if (ctrlTime) {
                try {
                    const dCtrl = new Date(ctrlTime);
                    if (!isNaN(dCtrl.getTime())) {
                        const keyCtrl = String(dCtrl.getUTCHours()).padStart(2, '0') + '00Z';
                        if (!hourlyCtrl[keyCtrl]) {hourlyCtrl[keyCtrl] = 0;}
                        hourlyCtrl[keyCtrl]++;
                    }
                } catch (e) { }
            }
        });

        // Merge labels
        const allKeys = {};
        Object.keys(hourlyOrig).forEach(function(k) { allKeys[k] = true; });
        Object.keys(hourlyCtrl).forEach(function(k) { allKeys[k] = true; });
        const labels = Object.keys(allKeys).sort();

        if (!labels.length) {return;}

        const origData = labels.map(function(k) { return hourlyOrig[k] || 0; });
        const ctrlData = labels.map(function(k) { return hourlyCtrl[k] || 0; });

        const ctx = canvas.getContext('2d');
        GS_MODEL_COMPARISON_CHART = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    { label: PERTII18n.t('gdt.chart.originalEtd'), data: origData, backgroundColor: 'rgba(40, 167, 69, 0.6)', borderColor: '#28a745', borderWidth: 1 },
                    { label: PERTII18n.t('gdt.chart.controlledCtd'), data: ctrlData, backgroundColor: 'rgba(255, 193, 7, 0.6)', borderColor: '#ffc107', borderWidth: 1 },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: true, position: 'bottom', labels: { font: { size: 10 }, boxWidth: 12 } },
                    title: { display: true, text: PERTII18n.t('gdt.chart.comparisonTitle'), font: { size: 11 } },
                },
                scales: {
                    x: { title: { display: true, text: PERTII18n.t('gdt.chart.axisHourUtc'), font: { size: 10 } } },
                    y: { title: { display: true, text: PERTII18n.t('gdt.chart.axisFlights'), font: { size: 10 } }, beginAtZero: true },
                },
            },
        });
    }

    function renderModelGsSummaryTables(flightListData) {
        const flights = getFilteredModelFlights(flightListData);

        // Initialize all data collectors
        const origArtccData = {};
        const destArtccData = {};
        const origApData = {};
        const destApData = {};
        const origTraconData = {};
        const destTraconData = {};
        const origTierData = {};
        const destTierData = {};
        const carrierData = {};
        const hourData = {};
        const delayRanges = {};
        delayRanges[PERTII18n.t('gdt.delayRange.zero')] = 0;
        delayRanges[PERTII18n.t('gdt.delayRange.1to15')] = 0;
        delayRanges[PERTII18n.t('gdt.delayRange.16to30')] = 0;
        delayRanges[PERTII18n.t('gdt.delayRange.31to60')] = 0;
        delayRanges[PERTII18n.t('gdt.delayRange.61to90')] = 0;
        delayRanges[PERTII18n.t('gdt.delayRange.over90')] = 0;

        flights.forEach(function(f) {
            const delay = f.program_delay_min || 0;
            const origArtcc = f.dcenter || f.dep_center || f.fp_dept_artcc || '';
            let destArtcc = f.acenter || f.arr_center || f.fp_dest_artcc || '';
            const origAp = (f.orig || f.fp_dept_icao || '').toUpperCase();
            const destAp = (f.dest || f.fp_dest_icao || '').toUpperCase();
            const origTracon = AIRPORT_TRACON_MAP[origAp] || '';
            const destTracon = AIRPORT_TRACON_MAP[destAp] || '';
            const origTier = getTierForAirport(origAp) || '';
            const destTier = getTierForAirport(destAp) || '';
            const carrier = extractCarrier(f.acid || f.callsign || '');

            // Dest ARTCC fallback
            if (!destArtcc && destAp) {destArtcc = AIRPORT_CENTER_MAP[destAp] || '';}

            // Hour
            let hourKey = '';
            const timeStr = f.ctd_utc || f.etd_utc;
            if (timeStr) {
                try {
                    const d = new Date(timeStr);
                    if (!isNaN(d.getTime())) {hourKey = String(d.getUTCHours()).padStart(2, '0') + '00Z';}
                } catch (e) { }
            }

            // Accumulate data
            function addTo(obj, key) {
                if (!key) {return;}
                if (!obj[key]) {obj[key] = { count: 0, delay: 0 };}
                obj[key].count++;
                obj[key].delay += delay;
            }

            addTo(origArtccData, origArtcc);
            addTo(destArtccData, destArtcc);
            addTo(origApData, origAp);
            addTo(destApData, destAp);
            addTo(origTraconData, origTracon);
            addTo(destTraconData, destTracon);
            addTo(origTierData, origTier);
            addTo(destTierData, destTier);
            addTo(carrierData, carrier);
            addTo(hourData, hourKey);

            // Delay range
            if (delay === 0) {delayRanges[PERTII18n.t('gdt.delayRange.zero')]++;}
            else if (delay <= 15) {delayRanges[PERTII18n.t('gdt.delayRange.1to15')]++;}
            else if (delay <= 30) {delayRanges[PERTII18n.t('gdt.delayRange.16to30')]++;}
            else if (delay <= 60) {delayRanges[PERTII18n.t('gdt.delayRange.31to60')]++;}
            else if (delay <= 90) {delayRanges[PERTII18n.t('gdt.delayRange.61to90')]++;}
            else {delayRanges[PERTII18n.t('gdt.delayRange.over90')]++;}
        });

        // Render all tables
        renderModelTable4Col('gs_model_by_orig_artcc', origArtccData);
        renderModelTable4Col('gs_model_by_dest_artcc', destArtccData);
        renderModelTable4Col('gs_model_by_orig_ap', origApData);
        renderModelTable4Col('gs_model_by_dest_ap', destApData);
        renderModelTable4Col('gs_model_by_orig_tracon', origTraconData);
        renderModelTable4Col('gs_model_by_dest_tracon', destTraconData);
        renderModelTable4Col('gs_model_by_orig_tier', origTierData);
        renderModelTable4Col('gs_model_by_dest_tier', destTierData);
        renderModelTable4Col('gs_model_by_carrier', carrierData);
        renderModelTable4Col('gs_model_by_hour', hourData);

        // Delay range table
        const rangeBody = document.getElementById('gs_model_by_delay_range');
        if (rangeBody) {
            const total = flights.length || 1;
            let html = '';
            Object.keys(delayRanges).forEach(function(range) {
                const count = delayRanges[range];
                const pct = Math.round((count / total) * 100);
                html += '<tr><td>' + range + '</td><td class="text-right">' + count + '</td><td class="text-right">' + pct + '%</td></tr>';
            });
            rangeBody.innerHTML = html || '<tr><td colspan="3" class="text-muted">-</td></tr>';
        }
    }

    // Render a 4-column summary table (key, count, total delay, avg delay)
    function renderModelTable4Col(tbodyId, data) {
        const tbody = document.getElementById(tbodyId);
        if (!tbody) {return;}

        const entries = Object.keys(data).map(function(k) {
            const d = data[k];
            return { key: k, count: d.count, delay: d.delay, avg: d.count > 0 ? Math.round(d.delay / d.count) : 0 };
        });
        entries.sort(function(a, b) { return b.count - a.count; });

        if (!entries.length) {
            tbody.innerHTML = '<tr><td colspan="4" class="text-muted">-</td></tr>';
            return;
        }

        let html = '';
        entries.slice(0, 15).forEach(function(e) {
            html += '<tr><td>' + escapeHtml(e.key) + '</td><td class="text-right">' + e.count + '</td><td class="text-right">' + e.delay + '</td><td class="text-right">' + e.avg + '</td></tr>';
        });
        tbody.innerHTML = html;
    }

    function renderModelSummaryTable(tbodyId, data, showDelay) {
        const tbody = document.getElementById(tbodyId);
        if (!tbody) {return;}

        const entries = Object.keys(data).map(function(k) {
            return { key: k, count: data[k].count, delay: data[k].delay };
        });
        entries.sort(function(a, b) { return b.count - a.count; });

        if (!entries.length) {
            tbody.innerHTML = '<tr><td colspan="3" class="text-muted">-</td></tr>';
            return;
        }

        let html = '';
        entries.slice(0, 10).forEach(function(e) {
            html += '<tr><td>' + escapeHtml(e.key) + '</td><td class="text-right">' + e.count + '</td><td class="text-right">' + e.delay + '</td></tr>';
        });
        tbody.innerHTML = html;
    }


    function updateHorizonCounts(visibleRows, attrName) {
        const span60 = document.getElementById('gs_eta_60m');
        const span30 = document.getElementById('gs_eta_30m');
        const span15 = document.getElementById('gs_eta_15m');
        if (!span60 || !span30 || !span15) {return;}

        const nowMs = Date.now();
        let count60 = 0;
        let count30 = 0;
        let count15 = 0;

        (visibleRows || []).forEach(function(tr) {
            if (!tr) {return;}
            const attr = attrName || 'data-eta-epoch';
            const valStr = tr.getAttribute(attr);
            if (!valStr) {return;}
            const epoch = parseInt(valStr, 10);
            if (isNaN(epoch)) {return;}

            const diffMin = (epoch - nowMs) / 60000;
            // Only count future arrivals (within the horizon)
            if (diffMin >= 0 && diffMin <= 60) {
                count60++;
                if (diffMin <= 30) {
                    count30++;
                    if (diffMin <= 15) {
                        count15++;
                    }
                }
            }
        });

        span60.textContent = String(count60);
        span30.textContent = String(count30);
        span15.textContent = String(count15);
    }

    function updateDelayStats(visibleRows) {
        const spanTotal = document.getElementById('gs_delay_total');
        const spanMax = document.getElementById('gs_delay_max');
        const spanAvg = document.getElementById('gs_delay_avg');
        if (!spanTotal || !spanMax || !spanAvg) {return;}

        let total = 0;
        let maxDelay = 0;
        let count = 0;

        (visibleRows || []).forEach(function(tr) {
            if (!tr) {return;}

            const edctStr = tr.getAttribute('data-edct-epoch') || '';
            if (!edctStr) {return;}

            // Use ETD if present, otherwise fall back to filed departure
            const etdStr = tr.getAttribute('data-etd-epoch') || '';
            const filedStr = tr.getAttribute('data-filed-dep-epoch') || '';
            const baselineStr = etdStr || filedStr;
            if (!baselineStr) {return;}

            const edct = parseInt(edctStr, 10);
            const baseline = parseInt(baselineStr, 10);
            if (isNaN(edct) || isNaN(baseline)) {return;}

            let delayMin = Math.round((edct - baseline) / 60000);
            if (delayMin < 0) {
                delayMin = 0;
            }
            total += delayMin;
            if (delayMin > maxDelay) {
                maxDelay = delayMin;
            }
            count++;
        });

        if (!count) {
            spanTotal.textContent = '0';
            spanMax.textContent = '0';
            spanAvg.textContent = '0';
        } else {
            spanTotal.textContent = String(total);
            spanMax.textContent = String(maxDelay);
            spanAvg.textContent = String(Math.round(total / count));
        }

        // After delay spans are updated, rebuild the advisory text so the
        // "NEW TOTAL, MAXIMUM, AVERAGE DELAYS" line reflects current values.
        try {
            if (typeof buildAdvisory === 'function') {
                buildAdvisory();
            }
        } catch (e) {
            console.error('Error updating advisory with delay statistics', e);
        }
    }

    // =========================================================================
    // GS Flight List Modal Functions (Enhanced)
    // For TMU coordination with ATC facilities (per FSM User Guide Ch 6 & 19)
    // Features: Sorting, Grouping, OETD/OETA, Data Graph (Figure 19-4)
    // =========================================================================

    let GS_FLIGHT_LIST_DATA = null;
    let GS_FLIGHT_LIST_PAYLOAD = null;
    let GS_FLIGHT_LIST_SORT = { field: 'acid', order: 'asc' };
    const GS_DATA_GRAPH_CHART = null;

    function showGsFlightListModal(flightListData, gsPayload) {
        GS_FLIGHT_LIST_DATA = flightListData;
        GS_FLIGHT_LIST_PAYLOAD = gsPayload;

        const modal = document.getElementById('gs_flight_list_modal');
        if (!modal) {
            console.warn('GS Flight List modal not found');
            return;
        }

        // Populate header info
        const ctlEl = document.getElementById('gs_flt_list_ctl_element');
        const startEl = document.getElementById('gs_flt_list_start');
        const endEl = document.getElementById('gs_flt_list_end');
        const totalEl = document.getElementById('gs_flt_list_total');
        const affectedEl = document.getElementById('gs_flt_list_affected');
        const maxDelayEl = document.getElementById('gs_flt_list_max_delay');
        const avgDelayEl = document.getElementById('gs_flt_list_avg_delay');
        const totalDelayEl = document.getElementById('gs_flt_list_total_delay');
        const timestampEl = document.getElementById('gs_flt_list_timestamp');
        const countBadge = document.getElementById('gs_flt_list_count_badge');

        if (ctlEl) {ctlEl.textContent = gsPayload.gs_ctl_element || '-';}
        if (startEl) {startEl.textContent = gsPayload.gs_start ? formatZuluFromIso(gsPayload.gs_start) : '-';}
        if (endEl) {endEl.textContent = gsPayload.gs_end ? formatZuluFromIso(gsPayload.gs_end) : '-';}

        const totalFlights = flightListData.total || 0;
        const affectedFlights = flightListData.affected || totalFlights;
        const maxDelay = flightListData.max_delay || 0;
        const avgDelay = Math.round(flightListData.avg_delay || 0); // 0 decimal places
        const totalDelay = flightListData.total_delay || 0;

        if (totalEl) {totalEl.textContent = String(totalFlights);}
        if (affectedEl) {affectedEl.textContent = String(affectedFlights);}
        if (maxDelayEl) {maxDelayEl.textContent = String(maxDelay);}
        if (avgDelayEl) {avgDelayEl.textContent = String(avgDelay);}
        if (totalDelayEl) {totalDelayEl.textContent = String(totalDelay);}
        if (countBadge) {countBadge.textContent = PERTII18n.t('gdt.flightList.countBadge', { count: totalFlights });}

        // Generated time in UTC format: dd/hhmmZ
        if (timestampEl) {
            timestampEl.textContent = formatZuluFromIso(new Date().toISOString());
        }

        // Reset sort/group controls
        const groupSelect = document.getElementById('gs_flt_list_group_by');
        const sortSelect = document.getElementById('gs_flt_list_sort_by');
        if (groupSelect) {groupSelect.value = 'none';}
        if (sortSelect) {sortSelect.value = 'acid_asc';}
        GS_FLIGHT_LIST_SORT = { field: 'acid', order: 'asc' };

        // Render the flight list table
        renderFlightListTable();

        // Show the modal using Bootstrap
        if (window.jQuery && window.jQuery.fn.modal) {
            window.jQuery('#gs_flight_list_modal').modal('show');
        } else if (modal.classList) {
            modal.classList.add('show');
            modal.style.display = 'block';
            document.body.classList.add('modal-open');
        }
    }

    function renderFlightListTable() {
        if (!GS_FLIGHT_LIST_DATA) {return;}

        const tbody = document.getElementById('gs_flight_list_body');
        if (!tbody) {return;}

        const flights = GS_FLIGHT_LIST_DATA.flights || [];
        const groupBy = document.getElementById('gs_flt_list_group_by');
        const groupValue = groupBy ? groupBy.value : 'none';

        if (!flights.length) {
            tbody.innerHTML = '<tr><td colspan="14" class="text-center text-muted">' + PERTII18n.t('gdt.flightList.noGsFlights') + '</td></tr>';
            clearSummaryTables();
            return;
        }

        // Sort flights
        const sortedFlights = sortFlights(flights);

        // Count statistics
        const dcenterCounts = {};
        const origCounts = {};
        const destCounts = {};
        const carrierCounts = {};

        // Group data if needed
        const groupedData = {};
        if (groupValue !== 'none') {
            sortedFlights.forEach(function(f) {
                const groupKey = getGroupKey(f, groupValue);
                if (!groupedData[groupKey]) {groupedData[groupKey] = [];}
                groupedData[groupKey].push(f);
            });
        }

        let html = '';

        // Render grouped or flat
        if (groupValue !== 'none' && Object.keys(groupedData).length > 0) {
            const groupKeys = Object.keys(groupedData).sort();
            groupKeys.forEach(function(groupKey) {
                const groupFlights = groupedData[groupKey];
                html += '<tr class="bg-light"><td colspan="14" class="font-weight-bold text-primary">' +
                    '<i class="fas fa-folder-open mr-1"></i>' + escapeHtml(groupKey) +
                    ' <span class="badge badge-secondary">' + groupFlights.length + '</span></td></tr>';

                groupFlights.forEach(function(f) {
                    html += renderFlightRow(f, dcenterCounts, origCounts, destCounts, carrierCounts);
                });
            });
        } else {
            sortedFlights.forEach(function(f) {
                html += renderFlightRow(f, dcenterCounts, origCounts, destCounts, carrierCounts);
            });
        }

        tbody.innerHTML = html;

        // Populate summary tables
        renderFlightListSummary('gs_flt_list_by_dcenter', dcenterCounts);
        renderFlightListSummary('gs_flt_list_by_orig', origCounts);
        renderFlightListSummary('gs_flt_list_by_dest', destCounts);
        renderFlightListSummary('gs_flt_list_by_carrier', carrierCounts);
    }

    function renderFlightRow(f, dcenterCounts, origCounts, destCounts, carrierCounts) {
        const acid = f.acid || f.callsign || '';
        const carrier = extractCarrier(acid);
        const orig = f.orig || f.fp_dept_icao || f.dep || f.dep_airport || '';
        const dest = f.dest || f.fp_dest_icao || f.arr || f.arr_airport || '';
        const dcenter = f.dcenter || f.dep_center || f.fp_dept_artcc || f.dep_artcc || '';
        const acenter = f.acenter || f.arr_center || f.fp_dest_artcc || f.arr_artcc || '';

        // Original times (OETD/OETA) - check tmi_flight_control and vw_adl_flights field names
        const oetdVal = f.oetd_utc || f.orig_etd_utc || f.octd_utc || f.oetd || f.etd_utc || f.etd || '';
        const oetaVal = f.oeta_utc || f.orig_eta_utc || f.octa_utc || f.oeta || f.eta_utc || f.eta || '';
        const oetdText = oetdVal ? formatZuluFromIso(oetdVal) : '';
        const oetaText = oetaVal ? formatZuluFromIso(oetaVal) : '';

        // Current times - check tmi_flight_control and vw_adl_flights field names
        const etdVal = f.etd_utc || f.orig_etd_utc || f.etd || f.etd_runway_utc || '';
        const ctdVal = f.ctd_utc || f.edct_utc || f.gs_release_utc || '';
        const etaVal = f.eta_utc || f.orig_eta_utc || f.eta || f.eta_runway_utc || '';
        const ctaVal = f.cta_utc || f.cta || '';
        const etdText = etdVal ? formatZuluFromIso(etdVal) : '';
        const ctdText = ctdVal ? formatZuluFromIso(ctdVal) : '';
        const etaText = etaVal ? formatZuluFromIso(etaVal) : '';
        const ctaText = ctaVal ? formatZuluFromIso(ctaVal) : '';

        const delay = f.program_delay_min || f.absolute_delay_min || 0;
        const delayText = delay > 0 ? String(delay) : '0';
        const delayClass = delay > 60 ? 'text-danger font-weight-bold' : (delay > 30 ? 'text-warning' : '');

        const status = f.delay_status || f.ctl_type || 'GS';

        // Count statistics
        if (dcenter) {dcenterCounts[dcenter] = (dcenterCounts[dcenter] || 0) + 1;}
        if (orig) {origCounts[orig] = (origCounts[orig] || 0) + 1;}
        if (dest) {destCounts[dest] = (destCounts[dest] || 0) + 1;}
        if (carrier) {carrierCounts[carrier] = (carrierCounts[carrier] || 0) + 1;}

        // Build data attributes for context menu functionality
        const dataAttrs =
            ' data-acid="' + escapeHtml(acid) + '"' +
            ' data-orig="' + escapeHtml(orig) + '"' +
            ' data-dest="' + escapeHtml(dest) + '"' +
            ' data-dcenter="' + escapeHtml(dcenter) + '"' +
            ' data-acenter="' + escapeHtml(acenter) + '"' +
            ' data-oetd="' + escapeHtml(oetdVal || '') + '"' +
            ' data-oeta="' + escapeHtml(oetaVal || '') + '"' +
            ' data-etd="' + escapeHtml(etdVal || '') + '"' +
            ' data-ctd="' + escapeHtml(ctdVal || '') + '"' +
            ' data-eta="' + escapeHtml(etaVal || '') + '"' +
            ' data-cta="' + escapeHtml(ctaVal || '') + '"' +
            ' data-delay="' + delay + '"' +
            ' data-status="' + escapeHtml(status) + '"' +
            ' data-carrier="' + escapeHtml(carrier) + '"';

        return '<tr class="gs-flt-list-row"' + dataAttrs + '>' +
            '<td><strong>' + escapeHtml(acid) + '</strong></td>' +
            '<td>' + escapeHtml(carrier) + '</td>' +
            '<td>' + escapeHtml(orig) + '</td>' +
            '<td>' + escapeHtml(dest) + '</td>' +
            '<td>' + escapeHtml(dcenter) + '</td>' +
            '<td>' + escapeHtml(acenter) + '</td>' +
            '<td class="text-muted">' + oetdText + '</td>' +
            '<td>' + etdText + '</td>' +
            '<td class="font-weight-bold">' + ctdText + '</td>' +
            '<td class="text-muted">' + oetaText + '</td>' +
            '<td>' + etaText + '</td>' +
            '<td>' + ctaText + '</td>' +
            '<td class="' + delayClass + '">' + delayText + '</td>' +
            '<td><span class="badge badge-warning">' + escapeHtml(status) + '</span></td>' +
            '</tr>';
    }

    function extractCarrier(acid) {
        if (!acid) {return '';}
        const match = String(acid).match(/^([A-Z]{2,3})/i);
        return match ? match[1].toUpperCase() : '';
    }

    function getGroupKey(flight, groupBy) {
        switch (groupBy) {
            case 'carrier':
                return extractCarrier(flight.acid || flight.callsign || '') || PERTII18n.t('common.unknown');
            case 'orig_airport':
                return flight.orig || flight.fp_dept_icao || flight.dep || flight.dep_airport || PERTII18n.t('common.unknown');
            case 'orig_center':
                return flight.dcenter || flight.dep_center || flight.dep_artcc || PERTII18n.t('common.unknown');
            case 'dest_airport':
                return flight.dest || flight.fp_dest_icao || flight.arr || flight.arr_airport || PERTII18n.t('common.unknown');
            case 'dest_center':
                return flight.acenter || flight.arr_center || flight.arr_artcc || PERTII18n.t('common.unknown');
            case 'delay_bucket': {
                const delay = flight.program_delay_min || 0;
                if (delay === 0) {return PERTII18n.t('gdt.delayRange.noDelay');}
                if (delay <= 15) {return PERTII18n.t('gdt.delayRange.1to15');}
                if (delay <= 30) {return PERTII18n.t('gdt.delayRange.16to30');}
                if (delay <= 60) {return PERTII18n.t('gdt.delayRange.31to60');}
                if (delay <= 90) {return PERTII18n.t('gdt.delayRange.61to90');}
                return PERTII18n.t('gdt.delayRange.over90');
            }
            default:
                return PERTII18n.t('gdt.flightList.all');
        }
    }

    function sortFlights(flights) {
        const sorted = flights.slice();
        const field = GS_FLIGHT_LIST_SORT.field;
        const order = GS_FLIGHT_LIST_SORT.order;

        sorted.sort(function(a, b) {
            let valA, valB;

            switch (field) {
                case 'acid':
                    valA = (a.acid || a.callsign || '').toLowerCase();
                    valB = (b.acid || b.callsign || '').toLowerCase();
                    break;
                case 'carrier':
                    valA = extractCarrier(a.acid || a.callsign || '');
                    valB = extractCarrier(b.acid || b.callsign || '');
                    break;
                case 'orig':
                    valA = (a.orig || a.fp_dept_icao || a.dep || a.dep_airport || '').toLowerCase();
                    valB = (b.orig || b.fp_dept_icao || b.dep || b.dep_airport || '').toLowerCase();
                    break;
                case 'dest':
                    valA = (a.dest || a.fp_dest_icao || a.arr || a.arr_airport || '').toLowerCase();
                    valB = (b.dest || b.fp_dest_icao || b.arr || b.arr_airport || '').toLowerCase();
                    break;
                case 'dcenter':
                    valA = (a.dcenter || a.dep_center || a.dep_artcc || '').toLowerCase();
                    valB = (b.dcenter || b.dep_center || b.dep_artcc || '').toLowerCase();
                    break;
                case 'acenter':
                    valA = (a.acenter || a.arr_center || a.arr_artcc || '').toLowerCase();
                    valB = (b.acenter || b.arr_center || b.arr_artcc || '').toLowerCase();
                    break;
                case 'delay':
                    valA = a.program_delay_min || 0;
                    valB = b.program_delay_min || 0;
                    break;
                case 'etd':
                    valA = a.etd_utc || a.orig_etd_utc || '';
                    valB = b.etd_utc || b.orig_etd_utc || '';
                    break;
                case 'oetd':
                    valA = a.oetd_utc || a.orig_etd_utc || a.octd_utc || a.etd_utc || '';
                    valB = b.oetd_utc || b.orig_etd_utc || b.octd_utc || b.etd_utc || '';
                    break;
                case 'eta':
                    valA = a.eta_utc || a.orig_eta_utc || '';
                    valB = b.eta_utc || b.orig_eta_utc || '';
                    break;
                case 'oeta':
                    valA = a.oeta_utc || a.orig_eta_utc || a.octa_utc || a.eta_utc || '';
                    valB = b.oeta_utc || b.orig_eta_utc || b.octa_utc || b.eta_utc || '';
                    break;
                default:
                    valA = (a.acid || '').toLowerCase();
                    valB = (b.acid || '').toLowerCase();
            }

            if (valA < valB) {return order === 'asc' ? -1 : 1;}
            if (valA > valB) {return order === 'asc' ? 1 : -1;}
            return 0;
        });

        return sorted;
    }

    function clearSummaryTables() {
        ['gs_flt_list_by_dcenter', 'gs_flt_list_by_orig', 'gs_flt_list_by_dest', 'gs_flt_list_by_carrier'].forEach(function(id) {
            const el = document.getElementById(id);
            if (el) {el.innerHTML = '<tr><td colspan="2" class="text-muted">-</td></tr>';}
        });
    }

    // Data Graph functions moved to Model GS section - see renderModelGsDataGraph()

    function renderFlightListSummary(tbodyId, counts) {
        const tbody = document.getElementById(tbodyId);
        if (!tbody) {return;}

        const entries = Object.keys(counts).map(function(k) {
            return { key: k, count: counts[k] };
        });
        entries.sort(function(a, b) { return b.count - a.count; });

        if (!entries.length) {
            tbody.innerHTML = '<tr><td colspan="2" class="text-muted">-</td></tr>';
            return;
        }

        let html = '';
        entries.slice(0, 10).forEach(function(e) {
            html += '<tr><td>' + escapeHtml(e.key) + '</td><td class="text-right">' + e.count + '</td></tr>';
        });
        if (entries.length > 10) {
            const othersCount = entries.slice(10).reduce(function(sum, e) { return sum + e.count; }, 0);
            html += '<tr class="text-muted"><td>' + PERTII18n.t('gdt.flightList.others') + '</td><td class="text-right">' + othersCount + '</td></tr>';
        }
        tbody.innerHTML = html;
    }

    function formatZuluFromIso(isoStr) {
        if (!isoStr) {return '';}
        try {
            const d = new Date(isoStr);
            if (isNaN(d.getTime())) {return isoStr;}
            const dd = String(d.getUTCDate()).padStart(2, '0');
            const hh = String(d.getUTCHours()).padStart(2, '0');
            const mm = String(d.getUTCMinutes()).padStart(2, '0');
            return dd + '/' + hh + mm + 'Z';
        } catch (e) {
            return isoStr;
        }
    }

    // Convert ICAO to FAA code (region-aware: handles AK, HI, Canada, etc.)
    function icaoToFaa(icao) {
        if (!icao) {return '';}
        icao = String(icao).toUpperCase();
        // PERTI denormalization (region-aware), with server IATA map as supplement
        if (typeof PERTI !== 'undefined' && PERTI.denormalizeIcao) {
            return AIRPORT_IATA_MAP[icao] || PERTI.denormalizeIcao(icao);
        }
        if (AIRPORT_IATA_MAP && AIRPORT_IATA_MAP[icao]) {
            return AIRPORT_IATA_MAP[icao];
        }
        if (icao.length === 4 && icao.charAt(0) === 'K') {
            return icao.substring(1);
        }
        return icao;
    }

    // Format snapshot time as yyyy-mm-dd hh:mm:ss.sssZ
    function formatSnapshotTime(date) {
        if (!date) {return '';}
        try {
            const d = date instanceof Date ? date : new Date(date);
            if (isNaN(d.getTime())) {return '';}
            const yyyy = d.getUTCFullYear();
            const mm = String(d.getUTCMonth() + 1).padStart(2, '0');
            const dd = String(d.getUTCDate()).padStart(2, '0');
            const hh = String(d.getUTCHours()).padStart(2, '0');
            const min = String(d.getUTCMinutes()).padStart(2, '0');
            const ss = String(d.getUTCSeconds()).padStart(2, '0');
            const ms = String(d.getUTCMilliseconds()).padStart(3, '0');
            return yyyy + '-' + mm + '-' + dd + ' ' + hh + ':' + min + ':' + ss + '.' + ms + 'Z';
        } catch (e) {
            return '';
        }
    }

    function copyGsFlightListToClipboard() {
        if (!GS_FLIGHT_LIST_DATA || !GS_FLIGHT_LIST_DATA.flights) {
            alert(PERTII18n.t('gdt.flightList.noDataAvailable'));
            return;
        }

        const flights = GS_FLIGHT_LIST_DATA.flights;
        const lines = [];

        // Get GS parameters from payload
        const ctlElement = (GS_FLIGHT_LIST_PAYLOAD && GS_FLIGHT_LIST_PAYLOAD.gs_ctl_element) || 'XXX';
        const gsStartFormatted = (GS_FLIGHT_LIST_PAYLOAD && GS_FLIGHT_LIST_PAYLOAD.gs_start)
            ? formatZuluFromIso(GS_FLIGHT_LIST_PAYLOAD.gs_start) : '-';
        const gsEndFormatted = (GS_FLIGHT_LIST_PAYLOAD && GS_FLIGHT_LIST_PAYLOAD.gs_end)
            ? formatZuluFromIso(GS_FLIGHT_LIST_PAYLOAD.gs_end) : '-';

        // Line 1: {ARRIVAL_AIRPORT(S)} GS FLIGHT LIST - {GS_START}-{GS_END}
        lines.push(ctlElement + ' GS FLIGHT LIST - ' + gsStartFormatted + '-' + gsEndFormatted);

        // Line 2: ADL Time from GS_ADL.snapshotUtc
        let adlTimeFormatted = '';
        if (GS_ADL && GS_ADL.snapshotUtc instanceof Date && !isNaN(GS_ADL.snapshotUtc.getTime())) {
            adlTimeFormatted = formatZuluFromIso(GS_ADL.snapshotUtc.toISOString());
        }
        lines.push(PERTII18n.t('gdt.flightList.adlTime') + ' ' + (adlTimeFormatted || '-'));

        lines.push(PERTII18n.t('gdt.flightList.totalFlightsLabel') + ' ' + flights.length);
        lines.push(PERTII18n.t('gdt.flightList.totalDelayLabel') + ' ' + (GS_FLIGHT_LIST_DATA.total_delay || 0) + ' ' + PERTII18n.t('units.min'));
        lines.push(PERTII18n.t('gdt.flightList.maxDelayLabel') + ' ' + (GS_FLIGHT_LIST_DATA.max_delay || 0) + ' ' + PERTII18n.t('units.min'));
        lines.push(PERTII18n.t('gdt.flightList.avgDelayLabel') + ' ' + Math.round(GS_FLIGHT_LIST_DATA.avg_delay || 0) + ' ' + PERTII18n.t('units.min'));
        lines.push('');

        // Fixed-width column header (consolidated ORIG/DEST columns, removed CARRIER)
        lines.push(
            padRight('ACID', 10) +
            padRight('ORIG', 10) +
            padRight('DEST', 10) +
            padRight('OETD', 10) +
            padRight('CTD', 10) +
            padRight('OETA', 10) +
            padRight('CTA', 10) +
            padRight('DELAY', 6),
        );
        lines.push('-'.repeat(76));

        flights.forEach(function(f) {
            // Consolidate origin: {ORIG_FAA}/{ARTCC} e.g., PHL/ZNY
            const origFaa = icaoToFaa(f.orig || '');
            const dcenter = f.dcenter || '';
            const origConsolidated = origFaa + (dcenter ? '/' + dcenter : '');

            // Consolidate destination: {DEST_FAA}/{ARTCC} e.g., ORD/ZAU
            const destFaa = icaoToFaa(f.dest || '');
            const acenter = f.acenter || '';
            const destConsolidated = destFaa + (acenter ? '/' + acenter : '');

            const row =
                padRight(f.acid || '', 10) +
                padRight(origConsolidated, 10) +
                padRight(destConsolidated, 10) +
                padRight(f.oetd_utc ? formatZuluFromIso(f.oetd_utc) : (f.etd_utc ? formatZuluFromIso(f.etd_utc) : ''), 10) +
                padRight(f.ctd_utc ? formatZuluFromIso(f.ctd_utc) : '', 10) +
                padRight(f.oeta_utc ? formatZuluFromIso(f.oeta_utc) : (f.eta_utc ? formatZuluFromIso(f.eta_utc) : ''), 10) +
                padRight(f.cta_utc ? formatZuluFromIso(f.cta_utc) : '', 10) +
                padRight(String(f.program_delay_min || 0), 6);
            lines.push(row);
        });

        // Add snapshot time at the end
        lines.push('');
        lines.push(PERTII18n.t('gdt.flightList.snapshotTime') + ' ' + formatSnapshotTime(new Date()));

        const text = lines.join('\n');

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function() {
                showCopySuccess(PERTII18n.t('gdt.flightList.copiedToClipboard'));
            }).catch(function(err) {
                console.error('Clipboard copy failed', err);
                fallbackCopyToClipboard(text);
            });
        } else {
            fallbackCopyToClipboard(text);
        }
    }

    function padRight(str, len) {
        str = String(str || '');
        while (str.length < len) {str += ' ';}
        return str;
    }

    function fallbackCopyToClipboard(text) {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        try {
            document.execCommand('copy');
            showCopySuccess(PERTII18n.t('gdt.flightList.copiedToClipboard'));
        } catch (e) {
            alert(PERTII18n.t('gdt.flightList.unableToCopy'));
        }
        document.body.removeChild(ta);
    }

    function showCopySuccess(msg) {
        if (window.Swal) {
            window.Swal.fire({
                icon: 'success',
                title: PERTII18n.t('gdt.flightList.copied'),
                text: msg,
                timer: 2000,
                showConfirmButton: false,
            });
        } else {
            alert(msg);
        }
    }

    function exportGsFlightListCsv() {
        if (!GS_FLIGHT_LIST_DATA || !GS_FLIGHT_LIST_DATA.flights) {
            alert(PERTII18n.t('gdt.flightList.noDataAvailable'));
            return;
        }

        const flights = GS_FLIGHT_LIST_DATA.flights;
        const lines = [];

        // CSV Header with OETD/OETA
        lines.push('ACID,CARRIER,ORIG,DEST,DCENTER,ACENTER,OETD_UTC,ETD_UTC,CTD_UTC,OETA_UTC,ETA_UTC,CTA_UTC,DELAY_MIN,STATUS');

        flights.forEach(function(f) {
            const row = [
                '"' + (f.acid || '').replace(/"/g, '""') + '"',
                '"' + extractCarrier(f.acid || '') + '"',
                '"' + (f.orig || '').replace(/"/g, '""') + '"',
                '"' + (f.dest || '').replace(/"/g, '""') + '"',
                '"' + (f.dcenter || '').replace(/"/g, '""') + '"',
                '"' + (f.acenter || '').replace(/"/g, '""') + '"',
                '"' + (f.oetd_utc || f.etd_utc || '').replace(/"/g, '""') + '"',
                '"' + (f.etd_utc || '').replace(/"/g, '""') + '"',
                '"' + (f.ctd_utc || '').replace(/"/g, '""') + '"',
                '"' + (f.oeta_utc || f.eta_utc || '').replace(/"/g, '""') + '"',
                '"' + (f.eta_utc || '').replace(/"/g, '""') + '"',
                '"' + (f.cta_utc || '').replace(/"/g, '""') + '"',
                String(f.program_delay_min || 0),
                '"' + (f.delay_status || f.ctl_type || 'GS').replace(/"/g, '""') + '"',
            ];
            lines.push(row.join(','));
        });

        const csv = lines.join('\n');
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);

        const a = document.createElement('a');
        a.href = url;
        a.download = 'gs_flight_list_' + new Date().toISOString().slice(0,10) + '.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    function printGsFlightList() {
        const table = document.getElementById('gs_flight_list_table');
        if (!table) {
            alert(PERTII18n.t('gdt.flightList.tableNotFound'));
            return;
        }

        const ctlElement = document.getElementById('gs_flt_list_ctl_element');
        const total = document.getElementById('gs_flt_list_total');
        const maxDelay = document.getElementById('gs_flt_list_max_delay');
        const avgDelay = document.getElementById('gs_flt_list_avg_delay');

        const printWindow = window.open('', '_blank', 'width=1100,height=700');
        printWindow.document.write('<html><head><title>' + PERTII18n.t('gdt.print.title') + '</title>');
        printWindow.document.write('<style>');
        printWindow.document.write('body { font-family: Arial, sans-serif; font-size: 9pt; margin: 15px; }');
        printWindow.document.write('h1 { font-size: 14pt; margin-bottom: 5px; }');
        printWindow.document.write('h2 { font-size: 10pt; margin-top: 0; color: #666; }');
        printWindow.document.write('.info { margin-bottom: 10px; }');
        printWindow.document.write('.info span { margin-right: 15px; }');
        printWindow.document.write('table { width: 100%; border-collapse: collapse; font-size: 8pt; }');
        printWindow.document.write('th, td { border: 1px solid #333; padding: 2px 4px; text-align: left; }');
        printWindow.document.write('th { background: #333; color: #fff; }');
        printWindow.document.write('tr:nth-child(even) { background: #f5f5f5; }');
        printWindow.document.write('.footer { margin-top: 10px; font-size: 7pt; color: #666; }');
        printWindow.document.write('</style></head><body>');
        printWindow.document.write('<h1>' + PERTII18n.t('gdt.print.heading') + '</h1>');
        printWindow.document.write('<h2>' + PERTII18n.t('gdt.print.subheading') + '</h2>');
        printWindow.document.write('<div class="info">');
        printWindow.document.write('<span><strong>' + PERTII18n.t('gdt.print.ctlElement') + '</strong> ' + (ctlElement ? ctlElement.textContent : '-') + '</span>');
        printWindow.document.write('<span><strong>' + PERTII18n.t('gdt.print.total') + '</strong> ' + (total ? total.textContent : '0') + '</span>');
        printWindow.document.write('<span><strong>' + PERTII18n.t('gdt.print.maxDelay') + '</strong> ' + (maxDelay ? maxDelay.textContent : '0') + ' ' + PERTII18n.t('units.min') + '</span>');
        printWindow.document.write('<span><strong>' + PERTII18n.t('gdt.print.avgDelay') + '</strong> ' + (avgDelay ? avgDelay.textContent : '0') + ' ' + PERTII18n.t('units.min') + '</span>');
        printWindow.document.write('</div>');
        printWindow.document.write(table.outerHTML);
        printWindow.document.write('<div class="footer">' + PERTII18n.t('gdt.print.generated') + ' ' + formatZuluFromIso(new Date().toISOString()) + ' | ' + PERTII18n.t('gdt.print.reference') + '</div>');
        printWindow.document.write('</body></html>');
        printWindow.document.close();
        printWindow.focus();
        printWindow.print();
    }

    // Wire up flight list modal buttons on DOMContentLoaded
    document.addEventListener('DOMContentLoaded', function() {
        const copyBtn = document.getElementById('gs_flt_list_copy_btn');
        if (copyBtn) {
            copyBtn.addEventListener('click', function(ev) {
                ev.preventDefault();
                copyGsFlightListToClipboard();
            });
        }

        const csvBtn = document.getElementById('gs_flt_list_export_csv_btn');
        if (csvBtn) {
            csvBtn.addEventListener('click', function(ev) {
                ev.preventDefault();
                exportGsFlightListCsv();
            });
        }

        const printBtn = document.getElementById('gs_flt_list_print_btn');
        if (printBtn) {
            printBtn.addEventListener('click', function(ev) {
                ev.preventDefault();
                printGsFlightList();
            });
        }

        // Sort dropdown handler
        const sortSelect = document.getElementById('gs_flt_list_sort_by');
        if (sortSelect) {
            sortSelect.addEventListener('change', function() {
                const val = this.value;
                const parts = val.split('_');
                if (parts.length >= 2) {
                    const field = parts.slice(0, -1).join('_');
                    const order = parts[parts.length - 1];
                    GS_FLIGHT_LIST_SORT = { field: field, order: order };
                    renderFlightListTable();
                }
            });
        }

        // Group dropdown handler
        const groupSelect = document.getElementById('gs_flt_list_group_by');
        if (groupSelect) {
            groupSelect.addEventListener('change', function() {
                renderFlightListTable();
            });
        }

        // Sortable column headers handler
        document.addEventListener('click', function(ev) {
            const th = ev.target.closest('.gs-sortable');
            if (!th) {return;}

            const sortField = th.getAttribute('data-sort');
            if (!sortField) {return;}

            // Toggle order if same field, else default to asc
            if (GS_FLIGHT_LIST_SORT.field === sortField) {
                GS_FLIGHT_LIST_SORT.order = GS_FLIGHT_LIST_SORT.order === 'asc' ? 'desc' : 'asc';
            } else {
                GS_FLIGHT_LIST_SORT = { field: sortField, order: 'asc' };
            }

            // Update sort dropdown to match
            const sortSelect = document.getElementById('gs_flt_list_sort_by');
            if (sortSelect) {
                const sortVal = sortField + '_' + GS_FLIGHT_LIST_SORT.order;
                const opt = sortSelect.querySelector('option[value="' + sortVal + '"]');
                if (opt) {sortSelect.value = sortVal;}
            }

            renderFlightListTable();
        });
    });

    // Storage for last simulation results to allow viewing flight list on demand
    let GS_LAST_SIMULATION_DATA = null;

    function handleViewFlightList() {
        // Check if we have simulation data to display
        if (!GS_LAST_SIMULATION_DATA && !GS_FLIGHT_LIST_DATA) {
            // Try to fetch current GS flights from the GS sandbox table
            fetchCurrentGsFlightList();
            return;
        }

        // Use either last simulation data or last flight list data
        const dataToShow = GS_LAST_SIMULATION_DATA || GS_FLIGHT_LIST_DATA;

        if (dataToShow) {
            const payload = collectGsWorkflowPayload();
            showGsFlightListModal(dataToShow, payload);
        } else {
            if (window.Swal) {
                window.Swal.fire({
                    icon: 'info',
                    title: PERTII18n.t('gdt.flightList.noFlightListTitle'),
                    text: PERTII18n.t('gdt.flightList.noFlightListText'),
                });
            } else {
                alert(PERTII18n.t('gdt.flightList.noFlightListText'));
            }
        }
    }

    function fetchCurrentGsFlightList() {
        const statusEl = document.getElementById('gs_adl_status');
        if (statusEl) {statusEl.textContent = PERTII18n.t('gdt.flightList.fetching');}

        // Fetch current GS flights from the preview endpoint
        const payload = collectGsWorkflowPayload();

        apiPostJson(GS_WORKFLOW_API.preview, payload)
            .then(function(data) {
                let flights = Array.isArray(data) ? data : (data && data.flights ? data.flights : []);
                flights = normalizeSqlsrvRows(flights);

                // Filter to only GS controlled flights
                const gsFlights = flights.filter(function(f) {
                    return f.ctl_type === 'GS' || f.CTL_TYPE === 'GS';
                });

                if (!gsFlights.length) {
                    if (statusEl) {statusEl.textContent = PERTII18n.t('gdt.flightList.noGsFlights');}
                    if (window.Swal) {
                        window.Swal.fire({
                            icon: 'info',
                            title: PERTII18n.t('gdt.flightList.noGsFlightsTitle'),
                            text: PERTII18n.t('gdt.flightList.noGsFlightsText'),
                        });
                    }
                    return;
                }

                // Format data for the flight list modal
                const flightListData = formatFlightsForModal(gsFlights);
                showGsFlightListModal(flightListData, payload);
                if (statusEl) {statusEl.textContent = PERTII18n.t('gdt.flightList.loaded', { count: gsFlights.length });}
            })
            .catch(function(err) {
                console.error('Failed to fetch GS flight list', err);
                if (statusEl) {statusEl.textContent = PERTII18n.t('gdt.flightList.fetchFailed');}
                if (window.Swal) {
                    window.Swal.fire({ icon: 'error', title: PERTII18n.t('common.error'), text: PERTII18n.t('gdt.flightList.fetchFailedDetail') });
                }
            });
    }

    function formatFlightsForModal(flights) {
        let totalDelay = 0;
        let maxDelay = 0;
        let delayCount = 0;

        const formattedFlights = flights.map(function(f) {
            const delay = parseInt(f.program_delay_min || f.PROGRAM_DELAY_MIN || 0, 10);
            if (delay > 0) {
                totalDelay += delay;
                if (delay > maxDelay) {maxDelay = delay;}
                delayCount++;
            }

            return {
                acid: f.callsign || f.CALLSIGN || '',
                orig: f.orig || f.fp_dept_icao || f.FP_DEPT_ICAO || f.dep_icao || f.dep || f.dep_airport || '',
                dest: f.dest || f.fp_dest_icao || f.FP_DEST_ICAO || f.arr_icao || f.arr || f.arr_airport || '',
                dcenter: f.dcenter || f.dep_center || f.fp_dept_artcc || f.FP_DEPT_ARTCC || '',
                acenter: f.acenter || f.arr_center || f.fp_dest_artcc || f.FP_DEST_ARTCC || '',
                // Original times (before GS control)
                oetd_utc: f.oetd_utc || f.OETD_UTC || f.orig_etd_utc || f.ORIG_ETD_UTC || f.etd_runway_utc || '',
                oeta_utc: f.oeta_utc || f.OETA_UTC || f.orig_eta_utc || f.ORIG_ETA_UTC || f.eta_runway_utc || '',
                // Current times
                etd_utc: f.etd_runway_utc || f.ETD_RUNWAY_UTC || f.etd_utc || f.ETD_UTC || '',
                ctd_utc: f.ctd_utc || f.CTD_UTC || '',
                eta_utc: f.eta_runway_utc || f.ETA_RUNWAY_UTC || f.eta_utc || f.ETA_UTC || '',
                cta_utc: f.cta_utc || f.CTA_UTC || '',
                program_delay_min: delay,
                delay_status: f.delay_status || f.DELAY_STATUS || f.ctl_type || 'GS',
                ctl_type: f.ctl_type || f.CTL_TYPE || 'GS',
            };
        });

        return {
            flights: formattedFlights,
            total: formattedFlights.length,
            affected: delayCount,
            total_delay: totalDelay,
            max_delay: maxDelay,
            avg_delay: delayCount > 0 ? Math.round(totalDelay / delayCount) : 0,
            generated_utc: new Date().toISOString(),
        };
    }

    // Store simulation results when simulate is called
    function storeSimulationData(data) {
        if (!data) {return;}

        const flights = data.flights || [];
        GS_LAST_SIMULATION_DATA = formatFlightsForModal(flights);

        // Update with summary data from the response if available
        if (data.summary) {
            GS_LAST_SIMULATION_DATA.total = data.summary.total_flights || flights.length;
            GS_LAST_SIMULATION_DATA.affected = data.summary.affected_flights || GS_LAST_SIMULATION_DATA.affected;
            GS_LAST_SIMULATION_DATA.max_delay = data.summary.max_program_delay_min || GS_LAST_SIMULATION_DATA.max_delay;
            GS_LAST_SIMULATION_DATA.avg_delay = Math.round(data.summary.avg_program_delay_min || GS_LAST_SIMULATION_DATA.avg_delay);
            GS_LAST_SIMULATION_DATA.total_delay = data.summary.sum_program_delay_min || GS_LAST_SIMULATION_DATA.total_delay;
        }
    }

})();
