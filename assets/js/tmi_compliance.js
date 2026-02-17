/**
 * TMI Compliance Analysis Module
 * Handles loading and displaying TMI compliance results in PERTI review
 */

const TMICompliance = {
    planId: null,
    results: null,

    // HTML escape utility for free-text fields (NTML advisory text, comments, etc.)
    escapeHtml: function(s) { return s ? String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;') : ''; },

    // Format decimal minutes into human-readable duration
    formatDuration: function(minutes) {
        if (minutes === undefined || minutes === null) return typeof PERTII18n !== 'undefined' ? PERTII18n.t('gsAnalysis.duration.na') : '-';
        const totalSec = Math.round(Math.abs(minutes) * 60);
        const h = Math.floor(totalSec / 3600);
        const m = Math.floor((totalSec % 3600) / 60);
        const s = totalSec % 60;
        if (typeof PERTII18n !== 'undefined') {
            if (h > 0) return PERTII18n.t('gsAnalysis.duration.hours', { h: h, m: String(m).padStart(2, '0') });
            if (m > 0 && s > 0) return PERTII18n.t('gsAnalysis.duration.minutes', { m: m, s: String(s).padStart(2, '0') });
            if (m > 0) return PERTII18n.t('gsAnalysis.duration.minutesOnly', { m: m });
            return PERTII18n.t('gsAnalysis.duration.seconds', { s: s });
        }
        if (h > 0) return h + 'h ' + String(m).padStart(2, '0') + 'm';
        if (m > 0 && s > 0) return m + 'm ' + String(s).padStart(2, '0') + 's';
        if (m > 0) return m + 'm';
        return s + 's';
    },

    // Format a phase value with type label
    formatPhase: function(phase, phaseType) {
        if (!phase) return '';
        let label = 'Adv ' + phase;
        if (phaseType) label += ' (' + phaseType.charAt(0) + phaseType.slice(1).toLowerCase() + ')';
        return label;
    },

    // View mode for exempt flights: 'scale' (to-scale with dashed) or 'collapsed' (discontinuity)
    exemptViewMode: 'scale',

    // Spacing diagram scale mode: 'equal' (equal-width segments) or 'proportional' (to-scale)
    spacingDiagramScale: 'equal',

    // Filters for TMI results
    filters: {
        requestor: '',      // Filter by requestor facility
        provider: '',       // Filter by provider facility
        minValue: '',       // Min MIT/MINIT value
        maxValue: '',       // Max MIT/MINIT value
        hourStart: '',      // Start hour (0-23)
        hourEnd: '',        // End hour (0-23)
        tmiType: '',         // 'MIT', 'MINIT', or '' for all
    },

    // Progressive disclosure layout state (v2 UI)
    useProgressiveLayout: true,  // Enable new master-detail layout
    selectedTmiId: null,         // Currently selected TMI in list
    listGrouping: 'type',        // 'type', 'chronological', 'volume', 'noncompliant'
    listOrdering: 'chronological', // 'chronological', 'volume', 'alpha'
    expandedSections: {},        // Track which detail sections are expanded
    holdingData: null,               // Holding detection results from Python
    _selectedHoldingFix: null,       // Currently selected holding fix index

    init: function() {
        // Get plan ID from URL
        const uri = window.location.href.split('?');
        this.planId = uri[1] || null;

        // Bind button events
        $('#load_tmi_results').on('click', () => this.loadResults());
        $('#run_tmi_analysis').on('click', () => this.runAnalysis());
        $('#save_ntml_config').on('click', () => this.saveConfig());
        $('#load_ntml_config').on('click', () => this.loadConfig());

        // Auto-fill from plan data first (as defaults)
        this.populateFromPlanData();

        // Then try to load saved config (overrides defaults if exists)
        this.loadConfig(true);
    },

    populateFromPlanData: function() {
        // Use window.planData set by PHP in review.php
        if (!window.planData) {return;}

        const pd = window.planData;

        // Destinations
        if (pd.destinations) {
            $('#tmi_destinations').val(pd.destinations);
        }

        // Event Start: combine event_date and event_start (HHMM)
        if (pd.event_date && pd.event_start) {
            const startTime = pd.event_start.padStart(4, '0');
            const startFormatted = `${pd.event_date} ${startTime.substring(0,2)}:${startTime.substring(2,4)}`;
            $('#tmi_event_start').val(startFormatted);
        }

        // Event End: combine event_end_date and event_end_time (HHMM)
        if (pd.event_end_date && pd.event_end_time) {
            const endTime = pd.event_end_time.padStart(4, '0');
            const endFormatted = `${pd.event_end_date} ${endTime.substring(0,2)}:${endTime.substring(2,4)}`;
            $('#tmi_event_end').val(endFormatted);
        }
    },

    saveConfig: function() {
        if (!this.planId) {
            $('#ntml_save_status').text('No plan ID').addClass('text-danger');
            return;
        }

        const config = {
            p_id: this.planId,
            destinations: $('#tmi_destinations').val(),
            event_start: $('#tmi_event_start').val(),
            event_end: $('#tmi_event_end').val(),
            ntml_text: $('#tmi_ntml_input').val(),
        };

        $('#ntml_save_status').text('Saving...');

        $.ajax({
            url: 'api/analysis/tmi_config.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(config),
            success: (response) => {
                if (response.success) {
                    $('#ntml_save_status').text('Saved!').removeClass('text-danger').addClass('text-success');
                    setTimeout(() => $('#ntml_save_status').text(''), 3000);
                } else {
                    $('#ntml_save_status').text('Save failed').addClass('text-danger');
                }
            },
            error: () => {
                $('#ntml_save_status').text('Save error').addClass('text-danger');
            },
        });
    },

    loadConfig: function(silent = false) {
        if (!this.planId) {
            if (!silent) {$('#ntml_save_status').text('No plan ID').addClass('text-danger');}
            return;
        }

        if (!silent) {$('#ntml_save_status').text('Loading...');}

        $.ajax({
            url: `api/analysis/tmi_config.php?p_id=${this.planId}`,
            method: 'GET',
            dataType: 'json',
            success: (response) => {
                if (response.success && response.data) {
                    $('#tmi_destinations').val(response.data.destinations || '');
                    $('#tmi_event_start').val(response.data.event_start || '');
                    $('#tmi_event_end').val(response.data.event_end || '');
                    $('#tmi_ntml_input').val(response.data.ntml_text || '');
                    if (!silent) {
                        $('#ntml_save_status').text('Loaded').removeClass('text-danger').addClass('text-success');
                        setTimeout(() => $('#ntml_save_status').text(''), 2000);
                    }
                } else if (!silent) {
                    $('#ntml_save_status').text('No saved config');
                    setTimeout(() => $('#ntml_save_status').text(''), 2000);
                }
            },
            error: () => {
                if (!silent) {$('#ntml_save_status').text('Load error').addClass('text-danger');}
            },
        });
    },

    loadResults: function() {
        if (!this.planId) {
            this.showError('No plan ID found');
            return;
        }

        $('#tmi_status').text('Loading...');
        $('#load_tmi_results').prop('disabled', true);

        $.ajax({
            url: `api/analysis/tmi_compliance.php?p_id=${this.planId}`,
            method: 'GET',
            dataType: 'json',
            success: (response) => {
                $('#load_tmi_results').prop('disabled', false);

                if (response.success && response.data) {
                    this.results = response.data;
                    // Store holding results
                    if (this.results && this.results.holding) {
                        this.holdingData = this.results.holding;
                    }
                    // Start trajectory fetch in parallel (maps will await it)
                    if (response.data.trajectories_url) {
                        this.loadTrajectories(this.planId, response.data.trajectories_url);
                    }
                    // Check for data gaps before rendering
                    this.checkDataGaps(() => {
                        this.renderResults();
                        $('#tmi_status').text(`Loaded: ${response.data.event}`);
                    });
                } else {
                    $('#tmi_status').text(response.message || 'No results found');
                    this.showNoData();
                }
            },
            error: (xhr, status, error) => {
                $('#load_tmi_results').prop('disabled', false);
                $('#tmi_status').text('Error loading results');
                this.showError(`Failed to load results: ${error}`);
            },
        });
    },

    /**
     * Fetch trajectory data from the split trajectory endpoint.
     * Called in parallel with results rendering — maps defer trajectory-dependent
     * features (flight tracks, flow cones, branch analysis) until this resolves.
     */
    loadTrajectories: function(planId, trajectoryUrl) {
        const url = trajectoryUrl || `api/analysis/tmi_compliance.php?p_id=${planId}&trajectories=true`;
        this._trajectoryPromise = fetch(url)
            .then(resp => resp.ok ? resp.json() : null)
            .then(trajData => {
                if (trajData) {
                    this._rawTrajectories = trajData;
                    console.log(`Loaded trajectory data: ${Object.keys(trajData).length} MIT entries`);
                }
                return trajData;
            })
            .catch(e => {
                console.warn('Trajectory fetch failed:', e);
                return null;
            });
        return this._trajectoryPromise;
    },

    analysisInProgress: false,

    runAnalysis: function() {
        if (!this.planId) {
            this.showError('No plan ID found');
            return;
        }

        if (this.analysisInProgress) {
            Swal.fire('Analysis In Progress', 'Please wait for the current analysis to complete.', 'info');
            return;
        }

        // Check if config is saved
        const ntmlText = $('#tmi_ntml_input').val();
        if (!ntmlText || ntmlText.trim() === '') {
            Swal.fire({
                icon: 'warning',
                title: 'No NTML Configuration',
                html: `
                    <div class="text-left">
                        <p>Please enter NTML entries in the configuration section above and click <strong>Save Config</strong> before running analysis.</p>
                        <p class="small text-muted">Example NTML format:</p>
                        <pre class="small">LAS via FLCHR 20MIT ZLA:ZOA 2359Z-0400Z
LAS GS (NCT) 0230Z-0315Z issued 0244Z</pre>
                    </div>
                `,
                confirmButtonText: 'OK',
            });
            return;
        }

        // Confirm before running
        Swal.fire({
            title: 'Run TMI Compliance Analysis?',
            html: `
                <div class="text-left small">
                    <p>This will analyze flight data against the configured TMIs for Plan ${this.planId}.</p>
                    <p><strong>Note:</strong> Analysis typically takes 1-5 minutes depending on the number of TMIs and traffic volume. Large events may take longer.</p>
                    <p class="text-warning"><i class="fas fa-exclamation-triangle"></i>
                       Make sure your TMI configuration is saved before running.</p>
                </div>
            `,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Run Analysis',
            cancelButtonText: 'Cancel',
        }).then((result) => {
            if (result.isConfirmed) {
                this.executeAnalysis();
            }
        });
    },

    executeAnalysis: function() {
        this.analysisInProgress = true;
        const startTime = Date.now();

        // Analysis steps with timing estimates (cumulative seconds)
        const analysisSteps = [
            { text: 'Connecting to analysis service...', icon: 'fa-plug', time: 0 },
            { text: 'Loading TMI configuration...', icon: 'fa-cog', time: 2 },
            { text: 'Parsing NTML entries...', icon: 'fa-file-alt', time: 4 },
            { text: 'Querying flight database...', icon: 'fa-database', time: 8 },
            { text: 'Loading flight trajectories...', icon: 'fa-route', time: 15 },
            { text: 'Computing boundary crossings...', icon: 'fa-border-all', time: 25 },
            { text: 'Analyzing MIT/MINIT compliance...', icon: 'fa-ruler-horizontal', time: 35 },
            { text: 'Analyzing ground stops...', icon: 'fa-plane-slash', time: 45 },
            { text: 'Processing APREQ restrictions...', icon: 'fa-clipboard-check', time: 50 },
            { text: 'Generating compliance report...', icon: 'fa-chart-bar', time: 55 },
        ];

        // Show progress modal with step display
        Swal.fire({
            title: 'Analyzing TMI Compliance',
            html: `
                <div class="text-center">
                    <div class="mb-3">
                        <i id="analysis_icon" class="fas fa-plug fa-2x text-primary fa-pulse"></i>
                    </div>
                    <p id="analysis_status" class="mb-2" style="font-weight: 500;">Connecting to analysis service...</p>
                    <div class="progress mt-3" style="height: 8px; background: #2d2d44;">
                        <div id="analysis_progress" class="progress-bar"
                             role="progressbar" style="width: 5%; background: linear-gradient(90deg, #4dabf7, #228be6);"></div>
                    </div>
                    <div class="d-flex justify-content-between mt-2 small" style="color: var(--dark-text-subtle);">
                        <span id="analysis_elapsed">0:00</span>
                        <span id="analysis_step">Step 1 of ${analysisSteps.length}</span>
                    </div>
                    <div id="analysis_steps_list" class="mt-3 text-left small" style="max-height: 150px; overflow-y: auto;">
                    </div>
                </div>
            `,
            allowOutsideClick: false,
            showConfirmButton: false,
            width: 450,
        });

        let currentStep = 0;
        const completedSteps = [];

        // Update elapsed time and step based on actual time
        const progressInterval = setInterval(() => {
            const elapsed = (Date.now() - startTime) / 1000;
            const mins = Math.floor(elapsed / 60);
            const secs = Math.floor(elapsed % 60);
            $('#analysis_elapsed').text(`${mins}:${secs.toString().padStart(2, '0')}`);

            // Find current step based on elapsed time
            let newStep = 0;
            for (let i = 0; i < analysisSteps.length; i++) {
                if (elapsed >= analysisSteps[i].time) {
                    newStep = i;
                }
            }

            // Update if step changed
            if (newStep !== currentStep) {
                currentStep = newStep;
                const step = analysisSteps[currentStep];

                $('#analysis_status').text(step.text);
                $('#analysis_icon').removeClass().addClass(`fas ${step.icon} fa-2x text-primary fa-pulse`);
                $('#analysis_step').text(`Step ${currentStep + 1} of ${analysisSteps.length}`);

                // Calculate progress (leave room for completion)
                const progress = Math.min(5 + (currentStep / analysisSteps.length) * 85, 90);
                $('#analysis_progress').css('width', progress + '%');

                // Add completed step to list
                if (currentStep > 0) {
                    const prevStep = analysisSteps[currentStep - 1];
                    if (!completedSteps.includes(currentStep - 1)) {
                        completedSteps.push(currentStep - 1);
                        $('#analysis_steps_list').append(`
                            <div style="color: #51cf66;"><i class="fas fa-check mr-2"></i>${prevStep.text.replace('...', '')} ✓</div>
                        `);
                    }
                }
            }
        }, 500);

        // Launch async analysis, then poll for completion
        $.ajax({
            url: `api/analysis/tmi_compliance.php?p_id=${this.planId}&run=true`,
            method: 'GET',
            dataType: 'json',
            timeout: 30000,
            success: (response) => {
                if (response.status === 'running') {
                    // Analysis launched in background - start polling
                    this._pollForResults(startTime, progressInterval, analysisSteps, currentStep, completedSteps);
                } else if (response.status === 'error') {
                    clearInterval(progressInterval);
                    this.analysisInProgress = false;
                    Swal.fire({
                        icon: 'error',
                        title: 'Analysis Failed',
                        html: `<p>${response.message || 'Failed to start analysis'}</p>`,
                    });
                } else if (response.success && response.data) {
                    // Immediate result (e.g., cached or very fast)
                    clearInterval(progressInterval);
                    this._handleAnalysisComplete(response, startTime);
                }
            },
            error: (xhr, status, error) => {
                clearInterval(progressInterval);
                this.analysisInProgress = false;
                Swal.fire({
                    icon: 'error',
                    title: 'Analysis Failed',
                    html: `<p>${xhr.responseJSON?.message || error || 'Failed to start analysis'}</p>`,
                });
            },
        });
    },

    _pollForResults: function(startTime, progressInterval, analysisSteps, currentStep, completedSteps) {
        const pollInterval = setInterval(() => {
            $.ajax({
                url: `api/analysis/tmi_compliance.php?p_id=${this.planId}&status=true`,
                method: 'GET',
                dataType: 'json',
                timeout: 15000,
                success: (response) => {
                    if (response.status === 'complete') {
                        clearInterval(pollInterval);
                        clearInterval(progressInterval);
                        this._handleAnalysisComplete(response, startTime);
                    } else if (response.status === 'error') {
                        clearInterval(pollInterval);
                        clearInterval(progressInterval);
                        this.analysisInProgress = false;
                        Swal.fire({
                            icon: 'error',
                            title: 'Analysis Failed',
                            html: `<p>${response.message || 'Analysis failed'}</p>
                                   ${response.error_log ? '<pre class="small text-left mt-2" style="max-height:200px;overflow:auto;font-size:0.75rem;">' + $('<span>').text(response.error_log.slice(-500)).html() + '</pre>' : ''}`,
                        });
                    }
                    // 'running' status - keep polling, update elapsed from server
                    if (response.elapsed_seconds) {
                        const serverElapsed = response.elapsed_seconds;
                        // Update progress based on server-reported time
                        const progress = Math.min(5 + Math.min(serverElapsed / 300, 1) * 85, 90);
                        $('#analysis_progress').css('width', progress + '%');
                    }
                },
                error: () => {
                    // Network blip during poll - keep trying
                },
            });
        }, 4000); // Poll every 4 seconds

        // Safety: stop polling after 30 minutes
        setTimeout(() => {
            clearInterval(pollInterval);
            clearInterval(progressInterval);
            if (this.analysisInProgress) {
                this.analysisInProgress = false;
                Swal.fire({
                    icon: 'error',
                    title: 'Analysis Timeout',
                    text: 'Analysis did not complete within 30 minutes. Check server logs.',
                });
            }
        }, 1800000);
    },

    _handleAnalysisComplete: function(response, startTime) {
        this.analysisInProgress = false;
        const elapsed = ((Date.now() - startTime) / 1000).toFixed(1);

        if (response.success && response.data) {
            this.results = response.data;
            // Store holding results
            if (this.results && this.results.holding) {
                this.holdingData = this.results.holding;
            }
            if (response.data.trajectories_url) {
                this.loadTrajectories(this.planId, response.data.trajectories_url);
            }

            const mitCount = response.data.mit_results?.length || 0;
            const gsCount = response.data.gs_results?.length || 0;
            const apreqCount = response.data.apreq_results?.length || 0;

            this.checkDataGaps(() => {
                const gapWarning = this.dataGaps?.has_gaps
                    ? `<div class="mt-2 small text-warning"><i class="fas fa-exclamation-triangle"></i> Data gaps detected - some flights may be missing</div>`
                    : '';

                Swal.fire({
                    icon: 'success',
                    title: 'Analysis Complete',
                    html: `
                        <div class="text-center">
                            <p>TMI compliance analysis completed in <strong>${elapsed}s</strong></p>
                            <div class="mt-3 small" style="color: var(--dark-text-subtle);">
                                <div><i class="fas fa-ruler-horizontal text-info mr-2"></i>${mitCount} MIT/MINIT restriction${mitCount !== 1 ? 's' : ''}</div>
                                <div><i class="fas fa-plane-slash text-warning mr-2"></i>${gsCount} ground stop${gsCount !== 1 ? 's' : ''}</div>
                                <div><i class="fas fa-clipboard-check text-success mr-2"></i>${apreqCount} APREQ/CFR${apreqCount !== 1 ? 's' : ''}</div>
                            </div>
                            ${gapWarning}
                        </div>
                    `,
                    timer: 4000,
                    showConfirmButton: false,
                });
                this.renderResults();
                $('#tmi_status').text(`Analysis complete: ${response.data.event}`);
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Analysis Failed',
                html: `<p>${response.message || 'Unknown error'}</p>`,
            });
        }
    },

    detailIdCounter: 0,

    renderResults: function() {
        if (!this.results) {
            this.showNoData();
            return;
        }

        // Use progressive disclosure layout (v2 UI) if enabled
        if (this.useProgressiveLayout) {
            this.renderProgressiveLayout();
            return;
        }

        // Legacy layout below
        this.detailIdCounter = 0;
        let html = '';

        // Data gap warning (if any)
        html += this.renderDataGapWarning();

        // Summary card
        const summary = this.results.summary || {};
        const mitCompliance = summary.mit?.compliance_pct || 0;
        const gsResults = this.results.gs_results || {};
        const hasGroundStops = Object.keys(gsResults).length > 0 || (Array.isArray(gsResults) && gsResults.length > 0);
        const gsCompliance = hasGroundStops ? (summary.gs?.compliance_pct ?? 100) : null;
        const rrResults = this.results.reroute_results || {};
        const rrArray = Array.isArray(rrResults) ? rrResults : Object.values(rrResults);
        const hasReroutes = rrArray.length > 0;
        const rrCompliance = hasReroutes ? (rrArray.reduce((s, rr) => s + (rr.filed_compliance_pct || 0), 0) / rrArray.length) : null;
        const overall = summary.overall_compliance_pct || 0;

        html += `
            <div class="tmi-summary-card">
                <h5 class="mb-3"><i class="fas fa-tachometer-alt"></i> ${this.results.event}</h5>
                <div class="small text-muted mb-3">
                    ${this.results.event_start || ''} to ${this.results.event_end || ''} |
                    Generated: ${this.results.generated_utc || 'N/A'}
                </div>
                <div class="summary-stats">
                    <div class="summary-stat">
                        <div class="summary-stat-value ${this.getComplianceClass(mitCompliance)}">${mitCompliance.toFixed(1)}%</div>
                        <div class="tmi-stat-label">MIT/MINIT Compliance</div>
                    </div>
                    <div class="summary-stat">
                        <div class="summary-stat-value ${gsCompliance !== null ? this.getComplianceClass(gsCompliance) : 'text-muted'}">${gsCompliance !== null ? gsCompliance.toFixed(1) + '%' : 'N/A'}</div>
                        <div class="tmi-stat-label">Ground Stop Compliance</div>
                    </div>
                    <div class="summary-stat">
                        <div class="summary-stat-value ${rrCompliance !== null ? this.getComplianceClass(rrCompliance) : 'text-muted'}">${rrCompliance !== null ? rrCompliance.toFixed(1) + '%' : 'N/A'}</div>
                        <div class="tmi-stat-label">Reroute Compliance</div>
                    </div>
                    <div class="summary-stat">
                        <div class="summary-stat-value ${this.getComplianceClass(overall)}">${overall.toFixed(1)}%</div>
                        <div class="tmi-stat-label">Overall Score</div>
                    </div>
                </div>
                ${summary.mit ? `
                <div class="mt-3 small">
                    <span class="text-muted">MIT Violations:</span> ${summary.mit.total_violations || 0}/${summary.mit.total_pairs || 0} pairs |
                    <span class="text-muted">Max Difference:</span> ${summary.mit.max_shortfall_pct || 0}%
                </div>
                ` : ''}
            </div>
        `;

        // TMI Gantt Chart (Timeline)
        html += this.renderGanttChart();

        // Event Statistics Section
        html += this.renderEventStatistics();

        // MIT Results - handle both array and object formats
        const mitResults = this.results.mit_results || {};
        const mitResultsArray = Array.isArray(mitResults) ? mitResults : Object.values(mitResults);
        if (mitResultsArray.length > 0) {
            // Collect unique values for filter dropdowns
            const uniqueRequestors = new Set();
            const uniqueProviders = new Set();
            const uniqueValues = new Set();
            mitResultsArray.forEach(r => {
                if (r.requestor) {uniqueRequestors.add(r.requestor);}
                if (r.provider) {uniqueProviders.add(r.provider);}
                if (r.required) {uniqueValues.add(r.required);}
            });

            // Filter controls
            html += `
                <div class="tmi-filters card card-body bg-light mb-3">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <span class="text-muted mr-2"><i class="fas fa-filter"></i> Filter:</span>
                        <select id="tmi_filter_requestor" class="form-control form-control-sm" style="width:auto;min-width:100px;">
                            <option value="">All Requestors</option>
                            ${[...uniqueRequestors].sort().map(r => `<option value="${r}" ${this.filters.requestor===r?'selected':''}>${r}</option>`).join('')}
                        </select>
                        <select id="tmi_filter_provider" class="form-control form-control-sm" style="width:auto;min-width:100px;">
                            <option value="">All Providers</option>
                            ${[...uniqueProviders].sort().map(p => `<option value="${p}" ${this.filters.provider===p?'selected':''}>${p}</option>`).join('')}
                        </select>
                        <select id="tmi_filter_type" class="form-control form-control-sm" style="width:auto;">
                            <option value="">MIT/MINIT</option>
                            <option value="MIT" ${this.filters.tmiType==='MIT'?'selected':''}>MIT only</option>
                            <option value="MINIT" ${this.filters.tmiType==='MINIT'?'selected':''}>MINIT only</option>
                        </select>
                        <div class="input-group input-group-sm" style="width:auto;">
                            <div class="input-group-prepend"><span class="input-group-text">Value</span></div>
                            <input type="number" id="tmi_filter_min_value" class="form-control" style="width:70px;" placeholder="Min" title="Minimum MIT/MINIT value" value="${this.filters.minValue}">
                            <input type="number" id="tmi_filter_max_value" class="form-control" style="width:70px;" placeholder="Max" title="Maximum MIT/MINIT value" value="${this.filters.maxValue}">
                        </div>
                        <div class="input-group input-group-sm" style="width:auto;">
                            <div class="input-group-prepend"><span class="input-group-text">Hour (Z)</span></div>
                            <input type="number" id="tmi_filter_hour_start" class="form-control" style="width:65px;" placeholder="00" title="Start hour (0-23 Zulu)" min="0" max="23" value="${this.filters.hourStart}">
                            <div class="input-group-prepend input-group-append"><span class="input-group-text">-</span></div>
                            <input type="number" id="tmi_filter_hour_end" class="form-control" style="width:65px;" placeholder="23" title="End hour (0-23 Zulu)" min="0" max="23" value="${this.filters.hourEnd}">
                        </div>
                        <button class="btn btn-sm btn-outline-secondary" onclick="TMICompliance.clearFilters()"><i class="fas fa-times"></i> Clear</button>
                    </div>
                </div>
            `;

            html += '<h6 class="text-primary mb-3"><i class="fas fa-ruler-horizontal"></i> Miles-In-Trail (MIT/MINIT) <button class="btn btn-sm btn-outline-secondary ml-2" onclick="TMICompliance.copyNtmlSummary()" title="Copy NTML summary for Discord"><i class="fas fa-copy"></i> Copy for Discord</button></h6>';

            // Group TMIs by pair count for collapsible sections
            const groups = {
                high: { label: '20+ pairs', min: 20, max: Infinity, items: [], expanded: true },
                medium: { label: '5-19 pairs', min: 5, max: 19, items: [], expanded: true },
                low: { label: '1-4 pairs', min: 1, max: 4, items: [], expanded: false },
                none: { label: '0 pairs', min: 0, max: 0, items: [], expanded: false }
            };

            // Apply filters and categorize
            let visibleCount = 0;
            let filteredCount = 0;
            for (const r of mitResultsArray) {
                // Skip entries with no data message
                if (r.pairs === 0 && r.message) {continue;}

                // Apply filters
                if (!this.matchesFilters(r)) {
                    filteredCount++;
                    continue;
                }

                visibleCount++;
                const pairs = r.pairs || 0;

                // Categorize by pair count
                if (pairs >= 20) {
                    groups.high.items.push(r);
                } else if (pairs >= 5) {
                    groups.medium.items.push(r);
                } else if (pairs >= 1) {
                    groups.low.items.push(r);
                } else {
                    groups.none.items.push(r);
                }
            }

            // Render each group as collapsible section
            for (const [groupKey, group] of Object.entries(groups)) {
                if (group.items.length === 0) continue;

                const groupId = `tmi-group-${groupKey}`;
                const chevron = group.expanded ? 'fa-chevron-down' : 'fa-chevron-right';
                const displayStyle = group.expanded ? '' : 'display:none;';

                html += `
                    <div class="tmi-group mb-3">
                        <div class="tmi-group-header d-flex align-items-center py-2 px-3"
                             style="background:rgba(255,255,255,0.05);border-radius:6px;cursor:pointer;border-left:3px solid var(--primary);"
                             onclick="TMICompliance.toggleGroup('${groupId}')">
                            <i class="fas ${chevron} mr-2" id="${groupId}-chevron" style="width:14px;"></i>
                            <span class="font-weight-bold">${group.label}</span>
                            <span class="badge badge-primary ml-2">${group.items.length} TMI${group.items.length !== 1 ? 's' : ''}</span>
                        </div>
                        <div class="tmi-group-content mt-2" id="${groupId}" style="${displayStyle}">
                `;

                // Sort items by pair count descending within group
                group.items.sort((a, b) => (b.pairs || 0) - (a.pairs || 0));

                // Group multi-facility TMIs together (same group_id = same original TMI)
                const facilityGroups = new Map(); // group_id -> [items]
                const ungrouped = [];
                for (const r of group.items) {
                    const gid = r.group_id || '';
                    if (gid) {
                        if (!facilityGroups.has(gid)) facilityGroups.set(gid, []);
                        facilityGroups.get(gid).push(r);
                    } else {
                        ungrouped.push(r);
                    }
                }

                // Render ungrouped TMIs normally
                for (const r of ungrouped) {
                    html += this.renderMitCard(r);
                }

                // Render multi-facility groups as wrapped cards
                for (const [gid, members] of facilityGroups) {
                    html += this.renderMultiFacilityGroup(members);
                }

                html += `
                        </div>
                    </div>
                `;
            }

            // Show filter status if some are hidden
            if (filteredCount > 0) {
                html += `<div class="text-muted small mb-3"><i class="fas fa-info-circle"></i> Showing ${visibleCount} TMIs (${filteredCount} filtered out)</div>`;
            }
        }

        // Ground Stop Results - handle both array and object formats
        const gsResultsForCards = this.results.gs_results || {};
        const gsResultsArray = Array.isArray(gsResultsForCards) ? gsResultsForCards : Object.values(gsResultsForCards);
        if (gsResultsArray.length > 0) {
            html += '<h6 class="text-danger mb-3 mt-4"><i class="fas fa-ban"></i> Ground Stops <button class="btn btn-sm btn-outline-secondary ml-2" onclick="TMICompliance.copyGsSummary()" title="Copy GS summary for Discord"><i class="fas fa-copy"></i> Copy for Discord</button></h6>';

            for (const r of gsResultsArray) {
                html += this.renderGsCard(r);
            }
        }

        // Reroute Results - handle both array and object formats
        const rerouteResults = this.results.reroute_results || {};
        const rerouteArray = Array.isArray(rerouteResults) ? rerouteResults : Object.values(rerouteResults);
        if (rerouteArray.length > 0) {
            html += '<h6 class="text-warning mb-3 mt-4"><i class="fas fa-route"></i> Reroutes</h6>';

            for (const r of rerouteArray) {
                html += this.renderRerouteCard(r);
            }
        }

        // APREQ Results
        const apreqResults = this.results.apreq_results || {};
        const apreqResultsArray = Array.isArray(apreqResults) ? apreqResults : Object.values(apreqResults);
        if (apreqResultsArray.length > 0) {
            html += '<h6 class="text-info mb-3 mt-4" style="color:#17a2b8 !important;"><i class="fas fa-phone"></i> APREQ/CFR (Tracking Only)</h6>';

            for (const r of apreqResultsArray) {
                html += this.renderApreqCard(r);
            }
        }

        // Delay Tracking Results
        const delayResults = this.results.delay_results || [];
        if (delayResults.length > 0) {
            html += this.renderDelaySection(delayResults);
        }

        if (!html) {
            this.showNoData();
            return;
        }

        $('#tmi_results_container').html(html);

        // Bind filter events after rendering
        this.bindFilterEvents();
    },

    // Check if a TMI result matches current filters
    matchesFilters: function(r) {
        const f = this.filters;

        // Requestor filter
        if (f.requestor && r.requestor !== f.requestor) {
            return false;
        }

        // Provider filter
        if (f.provider && r.provider !== f.provider) {
            return false;
        }

        // TMI type filter
        if (f.tmiType) {
            const isMINIT = r.unit === 'min';
            if (f.tmiType === 'MIT' && isMINIT) {return false;}
            if (f.tmiType === 'MINIT' && !isMINIT) {return false;}
        }

        // Value filter
        const value = r.required || 0;
        if (f.minValue !== '' && value < parseFloat(f.minValue)) {
            return false;
        }
        if (f.maxValue !== '' && value > parseFloat(f.maxValue)) {
            return false;
        }

        // Hour filter - check if TMI time range overlaps with filter range
        if (f.hourStart !== '' || f.hourEnd !== '') {
            const filterStart = f.hourStart !== '' ? parseInt(f.hourStart) : 0;
            const filterEnd = f.hourEnd !== '' ? parseInt(f.hourEnd) : 23;

            // Extract hours from tmi_start and tmi_end (format: "HH:MMZ")
            const tmiStartMatch = (r.tmi_start || '').match(/^(\d{2})/);
            const tmiEndMatch = (r.tmi_end || '').match(/^(\d{2})/);

            if (tmiStartMatch && tmiEndMatch) {
                const tmiStartHour = parseInt(tmiStartMatch[1]);
                const tmiEndHour = parseInt(tmiEndMatch[1]);

                // Check for overlap - TMI must have some overlap with filter range
                // Handle wrap-around (e.g., 23Z-04Z)
                if (filterEnd >= filterStart) {
                    // Normal range (e.g., 02Z-06Z)
                    if (tmiEndHour < filterStart || tmiStartHour > filterEnd) {
                        return false;
                    }
                } else {
                    // Wrapped range (e.g., 22Z-04Z)
                    if (tmiEndHour < filterStart && tmiStartHour > filterEnd) {
                        return false;
                    }
                }
            }
        }

        return true;
    },

    // Clear all filters
    clearFilters: function() {
        this.filters = {
            requestor: '',
            provider: '',
            minValue: '',
            maxValue: '',
            hourStart: '',
            hourEnd: '',
            tmiType: '',
        };
        this.renderResults();
    },

    // Toggle TMI group expand/collapse
    toggleGroup: function(groupId) {
        const content = document.getElementById(groupId);
        const chevron = document.getElementById(groupId + '-chevron');
        if (!content || !chevron) return;

        const isHidden = content.style.display === 'none';
        content.style.display = isHidden ? '' : 'none';
        chevron.className = isHidden ? 'fas fa-chevron-down mr-2' : 'fas fa-chevron-right mr-2';
    },

    // Bind filter change events
    bindFilterEvents: function() {
        const self = this;
        const updateFilter = () => {
            self.filters.requestor = $('#tmi_filter_requestor').val() || '';
            self.filters.provider = $('#tmi_filter_provider').val() || '';
            self.filters.tmiType = $('#tmi_filter_type').val() || '';
            self.filters.minValue = $('#tmi_filter_min_value').val() || '';
            self.filters.maxValue = $('#tmi_filter_max_value').val() || '';
            self.filters.hourStart = $('#tmi_filter_hour_start').val() || '';
            self.filters.hourEnd = $('#tmi_filter_hour_end').val() || '';
            self.renderResults();
        };

        // Use .off() to prevent duplicate bindings
        $('#tmi_filter_requestor, #tmi_filter_provider, #tmi_filter_type').off('change').on('change', updateFilter);
        $('#tmi_filter_min_value, #tmi_filter_max_value, #tmi_filter_hour_start, #tmi_filter_hour_end')
            .off('change keyup').on('change keyup', function() {
                // Debounce for typing
                clearTimeout(self._filterDebounce);
                self._filterDebounce = setTimeout(updateFilter, 300);
            });
    },

    // Helper: Convert decimal minutes to mm:ss format
    formatTimeGap: function(decimalMin) {
        const totalSeconds = Math.round(decimalMin * 60);
        const minutes = Math.floor(totalSeconds / 60);
        const seconds = totalSeconds % 60;
        return `${minutes}:${seconds.toString().padStart(2, '0')}`;
    },

    // Helper: Calculate margin amount in units
    calcMarginAmount: function(spacing, required) {
        return (spacing - required).toFixed(1);
    },

    // Helper: Create spacing bar visualization
    renderSpacingBar: function(spacing, required, unitLabel) {
        const maxDisplay = required * 2.5; // Scale bar to 250% of required
        const spacingPct = Math.min((spacing / maxDisplay) * 100, 100);
        const requiredPct = (required / maxDisplay) * 100;
        const isUnder = spacing < required;
        const barColor = isUnder ? '#dc3545' : (spacing <= required * 1.1 ? '#28a745' : '#17a2b8');

        return `
            <div class="spacing-bar-container" style="position: relative; height: 20px; background: #e9ecef; border-radius: 3px; overflow: hidden;">
                <div class="spacing-bar-fill" style="position: absolute; left: 0; top: 0; height: 100%; width: ${spacingPct}%; background: ${barColor}; transition: width 0.3s;"></div>
                <div class="spacing-bar-required" style="position: absolute; left: ${requiredPct}%; top: 0; height: 100%; width: 2px; background: #000; z-index: 1;" title="Required: ${required}${unitLabel}"></div>
                <div class="spacing-bar-label" style="position: absolute; left: 4px; top: 50%; transform: translateY(-50%); font-size: 11px; font-weight: bold; color: ${isUnder ? '#fff' : '#333'}; z-index: 2;">
                    <code style="background: transparent;">${spacing}${unitLabel}</code>
                </div>
            </div>
        `;
    },

    // Helper: Create spacing bar with required label indicator
    renderSpacingBarWithLabel: function(spacing, required, unitLabel) {
        const maxDisplay = required * 2.5; // Scale bar to 250% of required
        const spacingPct = Math.min((spacing / maxDisplay) * 100, 100);
        const requiredPct = (required / maxDisplay) * 100;
        const isUnder = spacing < required;
        const barColor = isUnder ? '#dc3545' : (spacing <= required * 1.1 ? '#28a745' : '#17a2b8');

        return `
            <div class="spacing-bar-wrapper">
                <div class="spacing-bar-container" style="position: relative; height: 24px; background: #e9ecef; border-radius: 3px; overflow: visible;">
                    <div class="spacing-bar-fill" style="position: absolute; left: 0; top: 0; height: 100%; width: ${spacingPct}%; background: ${barColor}; transition: width 0.3s; border-radius: 3px 0 0 3px;"></div>
                    <div class="spacing-bar-required" style="position: absolute; left: ${requiredPct}%; top: -4px; height: calc(100% + 8px); width: 2px; background: #000; z-index: 3;" title="Required: ${required}${unitLabel}">
                        <span class="spacing-required-label" style="position: absolute; top: -14px; left: 50%; transform: translateX(-50%); font-size: 9px; color: #666; white-space: nowrap;">${required}</span>
                    </div>
                    <div class="spacing-bar-label" style="position: absolute; left: 4px; top: 50%; transform: translateY(-50%); font-size: 11px; font-weight: bold; color: ${isUnder ? '#fff' : '#333'}; z-index: 2;">
                        <code style="background: transparent;">${spacing.toFixed ? spacing.toFixed(1) : spacing}${unitLabel}</code>
                    </div>
                </div>
            </div>
        `;
    },

    // Helper: Calculate compliance streaks
    calcComplianceStreaks: function(allPairs) {
        if (!allPairs || allPairs.length === 0) {return { longestGood: 0, currentGood: 0, goodPeriods: [] };}

        let longestGood = 0;
        let currentGood = 0;
        let currentStreak = 0;
        const goodPeriods = [];
        let streakStart = null;

        for (let i = 0; i < allPairs.length; i++) {
            const p = allPairs[i];
            const isGood = p.spacing_category !== 'UNDER';

            if (isGood) {
                if (currentStreak === 0) {
                    streakStart = p.prev_time;
                }
                currentStreak++;
            } else {
                if (currentStreak >= 3) {
                    goodPeriods.push({
                        length: currentStreak,
                        start: streakStart,
                        end: allPairs[i-1]?.curr_time || streakStart,
                    });
                }
                if (currentStreak > longestGood) {longestGood = currentStreak;}
                currentStreak = 0;
            }
        }

        // Handle final streak
        if (currentStreak > longestGood) {longestGood = currentStreak;}
        if (currentStreak >= 3) {
            goodPeriods.push({
                length: currentStreak,
                start: streakStart,
                end: allPairs[allPairs.length - 1]?.curr_time || streakStart,
            });
        }
        currentGood = currentStreak;

        return { longestGood, currentGood, goodPeriods };
    },

    // Format TMI in standardized NTML notation
    formatStandardizedTMI: function(r) {
        const fix = r.fix || '';
        const required = r.required || 0;
        const unit = r.unit === 'min' ? 'MINIT' : 'MIT';
        const destinations = r.destinations ? r.destinations.join(',') : '';
        const origins = r.origins ? r.origins.join(',') : '';
        const provider = r.provider || '';
        const requestor = r.requestor || '';
        const tmiStart = r.tmi_start || '';
        const tmiEnd = r.tmi_end || '';

        // Check if fix is a real navaid (not ALL/ANY/empty)
        const isRealFix = fix && !['ALL', 'ANY', ''].includes(fix.toUpperCase());

        // Format: DEST via FIX XXnm/min MIT REQUESTOR:PROVIDER TTTTZ-TTTTZ
        // (skip "via FIX" if fix is ALL/ANY - just means all flows)
        let formatted = '';
        if (destinations) {formatted += `${destinations} `;}
        if (isRealFix) {formatted += `via ${fix} `;}
        formatted += `${required}${r.unit === 'min' ? 'MINIT' : 'MIT'} `;
        if (requestor || provider) {formatted += `${requestor}:${provider} `;}
        formatted += `${tmiStart}-${tmiEnd}`;

        return formatted.trim();
    },

    // Render horizontal spacing diagram (visual timeline)
    renderSpacingDiagram: function(allPairs, required, unit, diagramId, tmiStart, tmiEnd) {
        if (!allPairs || allPairs.length === 0) {return '';}

        const unitLabel = unit === 'min' ? 'min' : 'nm';
        const isMinit = unit === 'min';

        // Check for data gaps in this TMI window
        const gapOverlap = this.checkTMIGapOverlap(tmiStart, tmiEnd);
        const gapHours = new Set();
        if (gapOverlap?.gaps) {
            // Extract individual hours from each gap
            gapOverlap.gaps.forEach(gap => {
                for (let h = gap.start_hour; h <= gap.end_hour; h++) {
                    gapHours.add(h);
                }
            });
        }

        // Helper to check if a time falls in a gap hour
        const isInGapHour = (timeStr) => {
            if (!timeStr || gapHours.size === 0) {return false;}
            const match = timeStr.match(/(\d{2}):?(\d{2})/);
            if (match) {
                return gapHours.has(parseInt(match[1]));
            }
            return false;
        };

        // Build crossings list (unique flights at their crossing times)
        const crossings = [];
        if (allPairs.length > 0) {
            crossings.push({
                callsign: allPairs[0].prev_callsign,
                time: allPairs[0].prev_time,
                dept: allPairs[0].prev_dept || '',
                dest: allPairs[0].prev_dest || '',
                color: '#6c757d',
                inGap: isInGapHour(allPairs[0].prev_time),
            });
            allPairs.forEach(p => {
                // Determine color based on category
                let color = '#6c757d';
                if (p.spacing_category === 'UNDER') {color = '#dc3545';}
                else if (p.spacing_category === 'WITHIN') {color = '#28a745';}
                else if (p.spacing_category === 'OVER') {color = '#17a2b8';}
                else if (p.spacing_category === 'GAP') {color = '#ffc107';}

                crossings.push({
                    callsign: p.curr_callsign,
                    time: p.curr_time,
                    dept: p.curr_dept || '',
                    dest: p.curr_dest || '',
                    spacingFromPrev: p.spacing,
                    category: p.spacing_category,
                    isExempt: p.is_exempt || false,
                    color: color,
                    inGap: isInGapHour(p.curr_time),
                });
            });
        }

        // Calculate diagram dimensions - PROPORTIONAL to actual spacing values
        const padding = 60;
        const minSegmentWidth = 50;  // Minimum pixels per segment
        const maxSegmentWidth = 200; // Cap for very large spacing values
        const pixelsPerUnit = 4;     // Base scale: 4 pixels per nm/min

        // Calculate positions for each crossing based on actual spacing
        const positions = [];
        let currentX = padding;
        crossings.forEach((c, i) => {
            if (i === 0) {
                positions.push(currentX);
            } else {
                // Width proportional to spacing value
                let segWidth = (c.spacingFromPrev || required) * pixelsPerUnit;
                segWidth = Math.max(minSegmentWidth, Math.min(maxSegmentWidth, segWidth));
                currentX += segWidth;
                positions.push(currentX);
            }
        });

        const diagramWidth = Math.max(600, currentX + padding);
        const diagramHeight = 120;
        const hasGaps = gapHours.size > 0;

        // Build SVG content in layers: defs -> background -> gap markers -> lines -> labels -> dots (on top)
        let defsContent = '';
        let bgContent = '';
        let gapContent = '';
        let lineContent = '';
        let labelContent = '';
        let dotContent = '';

        // Add hatched pattern for data gaps
        if (hasGaps) {
            defsContent = `
                <defs>
                    <pattern id="gap-hatch-${diagramId}" patternUnits="userSpaceOnUse" width="8" height="8" patternTransform="rotate(45)">
                        <line x1="0" y1="0" x2="0" y2="8" stroke="#f59f00" stroke-width="2" stroke-opacity="0.4"/>
                    </pattern>
                </defs>
            `;
        }

        // Background track
        const trackEndX = positions[positions.length - 1] || padding;
        bgContent += `<rect x="${padding}" y="45" width="${trackEndX - padding}" height="30" fill="#f8f9fa" rx="4"/>`;

        // Draw segments and collect dot info
        crossings.forEach((c, i) => {
            const x = positions[i];
            const labelRow = i % 2;
            const labelY = labelRow === 0 ? 95 : 110;

            if (i > 0) {
                const prevX = positions[i - 1];
                const lineStyle = c.isExempt ? 'stroke-dasharray: 5,5' : '';
                const prevCrossing = crossings[i - 1];

                // Draw gap marker behind segment if either endpoint is in a gap hour
                if (hasGaps && (c.inGap || prevCrossing.inGap)) {
                    gapContent += `<rect x="${prevX}" y="45" width="${x - prevX}" height="30" fill="url(#gap-hatch-${diagramId})" rx="2"/>`;
                }

                // Line segment (drawn first, under dots)
                lineContent += `<line x1="${prevX}" y1="60" x2="${x}" y2="60" stroke="${c.color}" stroke-width="4" ${lineStyle}/>`;

                // Spacing label above line (staggered to avoid overlaps)
                const spacingLabelRow = (i - 1) % 2;
                const spacingY = spacingLabelRow === 0 ? 25 : 38;
                const midX = (prevX + x) / 2;
                labelContent += `<text x="${midX}" y="${spacingY}" text-anchor="middle" font-size="10" font-weight="bold" fill="${c.color}" class="spacing-label">${c.spacingFromPrev.toFixed(1)}${unitLabel}</text>`;
            }

            // Callsign label (staggered between y=95 and y=110)
            labelContent += `<text x="${x}" y="${labelY}" text-anchor="middle" font-size="9" fill="#666" class="callsign-label">${c.callsign}</text>`;

            // Format crossing time as HHMMZ for tooltip
            const formatCrossingTime = (timeStr) => {
                if (!timeStr) {return '';}
                const match = timeStr.match(/(\d{2}):?(\d{2})/);
                if (match) {
                    return `${match[1]}${match[2]}Z`;
                }
                return timeStr;
            };
            const crossingTimeLabel = formatCrossingTime(c.time);

            // Dot (flight marker) - drawn LAST so it's on top of lines
            // Uses group with title for native browser tooltip showing crossing time
            dotContent += `<g class="flight-marker-group" style="cursor: pointer;" onclick="TMICompliance.showFlightPopup(this.querySelector('circle'))">
                <title>Crossing: ${crossingTimeLabel}</title>
                <circle cx="${x}" cy="60" r="8" fill="${c.color}" class="flight-marker" data-callsign="${c.callsign}" data-time="${c.time}" data-dept="${c.dept}" data-dest="${c.dest}"/>
            </g>`;
        });

        // Assemble SVG: defs, background, gap markers, lines, labels, then dots on top
        const svgContent = defsContent + bgContent + gapContent + lineContent + labelContent + dotContent;

        // Legend as HTML element outside SVG (sticky positioned)
        const gapLegendItem = hasGaps
            ? `<span class="legend-item"><span class="legend-box" style="background: repeating-linear-gradient(45deg, transparent, transparent 2px, rgba(245,159,0,0.4) 2px, rgba(245,159,0,0.4) 4px);"></span> Data Gap</span>`
            : '';
        const legendHtml = `
            <div class="spacing-diagram-legend">
                <span class="legend-item"><span class="legend-line" style="background:#000;"></span> Req: ${required}${unitLabel}</span>
                <span class="legend-item"><span class="legend-box" style="background:#dc3545;"></span> Under</span>
                <span class="legend-item"><span class="legend-box" style="background:#28a745;"></span> OK</span>
                <span class="legend-item"><span class="legend-box" style="background:#17a2b8;"></span> Over</span>
                <span class="legend-item"><span class="legend-box" style="background:#ffc107;"></span> Gap</span>
                <span class="legend-item"><span class="legend-line legend-dashed"></span> Exempt</span>
                ${gapLegendItem}
            </div>
        `;

        return `
            <div class="spacing-diagram-container" id="${diagramId}">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="small text-muted">Spacing Timeline (${isMinit ? 'time-based' : 'distance-based'}) - ${crossings.length} flights</span>
                    ${legendHtml}
                </div>
                <div class="spacing-diagram-svg" style="overflow-x: auto; background: #fff; border: 1px solid #dee2e6; border-radius: 4px; padding: 10px;">
                    <svg width="${diagramWidth}" height="${diagramHeight}" viewBox="0 0 ${diagramWidth} ${diagramHeight}" style="min-width: ${diagramWidth}px;">
                        ${svgContent}
                    </svg>
                </div>
            </div>
        `;
    },

    // Toggle exempt flight view mode
    setExemptViewMode: function(mode, diagramId) {
        this.exemptViewMode = mode;
        // Re-render the results to update the diagram
        this.renderResults();
    },

    // Show flight details popup
    showFlightPopup: function(element) {
        const callsign = element.getAttribute('data-callsign');
        const time = element.getAttribute('data-time');
        const dept = element.getAttribute('data-dept') || 'N/A';
        const dest = element.getAttribute('data-dest') || 'N/A';
        const acftType = element.getAttribute('data-acft') || 'N/A';
        const dp = element.getAttribute('data-dp') || 'N/A';
        const star = element.getAttribute('data-star') || 'N/A';
        const dfix = element.getAttribute('data-dfix') || 'N/A';
        const afix = element.getAttribute('data-afix') || 'N/A';

        Swal.fire({
            title: `<code>${callsign}</code>`,
            html: `
                <table class="table table-sm table-borderless text-left mb-0" style="font-size: 0.9rem;">
                    <tr><td class="text-muted" width="40%">Crossing Time</td><td><strong>${time}</strong></td></tr>
                    <tr><td class="text-muted">Origin</td><td><strong>${dept}</strong></td></tr>
                    <tr><td class="text-muted">Destination</td><td><strong>${dest}</strong></td></tr>
                    <tr><td class="text-muted">Aircraft Type</td><td>${acftType}</td></tr>
                    <tr><td class="text-muted">Departure Fix</td><td>${dfix}</td></tr>
                    <tr><td class="text-muted">Arrival Fix</td><td>${afix}</td></tr>
                    <tr><td class="text-muted">DP/SID</td><td>${dp}</td></tr>
                    <tr><td class="text-muted">STAR</td><td>${star}</td></tr>
                </table>
            `,
            showCloseButton: true,
            showConfirmButton: false,
            width: '400px',
        });
    },

    // Render event statistics section
    renderEventStatistics: function() {
        if (!this.results) {return '';}

        const summary = this.results.summary || {};
        const mitResults = this.results.mit_results || {};
        const gsResults = this.results.gs_results || {};

        // Calculate totals
        let totalCrossings = 0;
        let totalPairs = 0;
        let totalViolations = 0;
        const uniqueFlights = new Set();

        // Process MIT results (could be object or array)
        const mitResultsArray = Array.isArray(mitResults) ? mitResults : Object.values(mitResults);
        for (const r of mitResultsArray) {
            totalCrossings += r.total_crossings || r.crossings || 0;
            totalPairs += r.pairs || 0;
            if (r.violations?.total) {totalViolations += r.violations.total;}
            if (r.all_pairs) {
                r.all_pairs.forEach(p => {
                    uniqueFlights.add(p.prev_callsign);
                    uniqueFlights.add(p.curr_callsign);
                });
            }
        }

        // Process GS results
        let gsFlights = 0;
        let gsExempt = 0;
        let gsViolations = 0;
        const gsResultsArray = Array.isArray(gsResults) ? gsResults : Object.values(gsResults);
        for (const r of gsResultsArray) {
            gsFlights += r.total_flights || 0;
            gsExempt += r.exempt_count || (r.exempt_flights?.length || 0);
            gsViolations += r.non_compliant_count || (r.non_compliant_flights?.length || 0);
        }

        return `
            <div class="event-statistics-card mb-3">
                <div class="card">
                    <div class="card-header bg-dark text-white py-2">
                        <i class="fas fa-chart-bar"></i> Event Statistics
                    </div>
                    <div class="card-body py-2">
                        <div class="row text-center">
                            <div class="col-md-3 col-6 mb-2">
                                <div class="stat-value text-primary">${uniqueFlights.size}</div>
                                <div class="stat-label text-muted small">Unique Flights</div>
                            </div>
                            <div class="col-md-3 col-6 mb-2">
                                <div class="stat-value text-info">${totalCrossings}</div>
                                <div class="stat-label text-muted small">Fix Crossings</div>
                            </div>
                            <div class="col-md-3 col-6 mb-2">
                                <div class="stat-value">${totalPairs}</div>
                                <div class="stat-label text-muted small">Pairs Analyzed</div>
                            </div>
                            <div class="col-md-3 col-6 mb-2">
                                <div class="stat-value text-danger">${totalViolations}</div>
                                <div class="stat-label text-muted small">MIT Violations</div>
                            </div>
                        </div>
                        ${gsFlights > 0 ? `
                        <hr class="my-2">
                        <div class="row text-center">
                            <div class="col-md-4 col-4">
                                <div class="stat-value">${gsFlights}</div>
                                <div class="stat-label text-muted small">GS Flights</div>
                            </div>
                            <div class="col-md-4 col-4">
                                <div class="stat-value text-info">${gsExempt}</div>
                                <div class="stat-label text-muted small">GS Exempt</div>
                            </div>
                            <div class="col-md-4 col-4">
                                <div class="stat-value text-danger">${gsViolations}</div>
                                <div class="stat-label text-muted small">GS Violations</div>
                            </div>
                        </div>
                        ` : ''}
                    </div>
                </div>
            </div>
        `;
    },

    // Render TMI Gantt Chart (Timeline visualization)
    renderGanttChart: function() {
        if (!this.results) {return '';}

        // Collect all TMIs for the timeline
        const allTmis = [];

        // MIT/MINIT results
        const mitResults = this.results.mit_results || {};
        const mitArray = Array.isArray(mitResults) ? mitResults : Object.values(mitResults);
        mitArray.forEach((r, i) => {
            if (r.pairs > 0 || r.crossings > 0 || r.total_crossings > 0) {
                const crossings = r.crossings || r.total_crossings || 0;
                const hasPairs = (r.pairs || 0) > 0 || crossings >= 2;
                allTmis.push({
                    type: 'MIT',
                    label: r.fix || r.destinations?.join(',') || 'MIT',
                    sublabel: `${r.required || 0}${r.unit === 'min' ? 'min' : 'nm'}`,
                    start: r.tmi_start,
                    end: r.tmi_end,
                    compliance: hasPairs ? (r.compliance_pct || 0) : null,
                    cardId: `mit_detail_${i + 1}`,
                    cancelled: r.cancelled || false,
                });
            }
        });

        // Ground Stop results - handle both array and object formats
        const gsResultsData = this.results.gs_results || {};
        const gsEntries = Array.isArray(gsResultsData)
            ? gsResultsData.map((r, i) => [`gs_${i}`, r])
            : Object.entries(gsResultsData);
        gsEntries.forEach(([key, r], i) => {
            // Extract airport from key (format: GS_NCT_KLAS_ALL -> LAS)
            const keyParts = key.split('_');
            var airportCode = 'GS';
            if (keyParts.length >= 3) {
                airportCode = (typeof PERTI !== 'undefined' && PERTI.denormalizeIcao)
                    ? PERTI.denormalizeIcao(keyParts[2])
                    : keyParts[2].replace(/^K/, '');
            }
            allTmis.push({
                type: 'GS',
                label: r.destination || airportCode,
                sublabel: 'Ground Stop',
                start: r.gs_start,
                end: r.gs_end,
                compliance: r.compliance_pct,
                cardId: `gs_detail_${mitArray.length + i + 1}`,
                cancelled: r.cancelled || false,
            });
        });

        // APREQ results
        const apreqResults = this.results.apreq_results || {};
        const apreqArray = Array.isArray(apreqResults) ? apreqResults : Object.values(apreqResults);
        apreqArray.forEach((r, i) => {
            allTmis.push({
                type: 'APREQ',
                label: r.fix || r.destinations?.join(',') || 'APREQ',
                sublabel: 'CFR',
                start: r.tmi_start,
                end: r.tmi_end,
                compliance: null, // APREQ is tracking only
                cardId: `apreq_detail_${mitArray.length + gsEntries.length + i + 1}`,
                cancelled: r.cancelled || false,
            });
        });

        if (allTmis.length === 0) {return '';}

        // Parse event start/end times
        const eventStart = this.parseEventTime(this.results.event_start);
        const eventEnd = this.parseEventTime(this.results.event_end);

        if (!eventStart || !eventEnd) {return '';}

        const eventDurationMs = eventEnd - eventStart;
        const eventDurationHours = eventDurationMs / (1000 * 60 * 60);

        // Chart dimensions - calculate width based on event duration
        const leftMargin = 120;  // Space for labels
        const rightMargin = 60;  // Space for compliance percentages
        const pixelsPerHour = 130;  // Fixed scale
        const hoursToShow = Math.ceil(eventDurationHours);
        const timelineWidth = hoursToShow * pixelsPerHour;
        const chartWidth = leftMargin + timelineWidth + rightMargin;
        const rowHeight = 32;
        const headerHeight = 40;
        const chartHeight = headerHeight + (allTmis.length * rowHeight) + 20;

        // Build SVG
        let svg = '';

        // Background
        svg += `<rect x="0" y="0" width="${chartWidth}" height="${chartHeight}" fill="#f8f9fa"/>`;

        // Hour grid lines and labels
        for (let h = 0; h <= hoursToShow; h++) {
            const x = leftMargin + (h * pixelsPerHour);
            const hourTime = new Date(eventStart.getTime() + (h * 60 * 60 * 1000));
            const hourLabel = hourTime.getUTCHours().toString().padStart(2, '0') + ':00Z';

            // Grid line
            svg += `<line x1="${x}" y1="${headerHeight}" x2="${x}" y2="${chartHeight - 10}" stroke="#dee2e6" stroke-width="1"/>`;

            // Hour label
            svg += `<text x="${x}" y="${headerHeight - 10}" text-anchor="middle" font-size="10" fill="#666">${hourLabel}</text>`;
        }

        // Draw TMI bars
        allTmis.forEach((tmi, i) => {
            const y = headerHeight + (i * rowHeight) + 4;
            const barHeight = rowHeight - 8;

            // Parse TMI times (format: "HH:MMZ" or similar)
            const tmiStart = this.parseTmiTime(tmi.start, eventStart);
            const tmiEnd = this.parseTmiTime(tmi.end, eventStart);

            if (!tmiStart || !tmiEnd) {return;}

            // Calculate bar position
            const startOffset = Math.max(0, (tmiStart - eventStart) / eventDurationMs);
            const endOffset = Math.min(1, (tmiEnd - eventStart) / eventDurationMs);
            const barX = leftMargin + (startOffset * timelineWidth);
            const barWidth = Math.max(10, (endOffset - startOffset) * timelineWidth);

            // Determine color based on type
            let barColor = '#007bff'; // MIT default blue
            if (tmi.type === 'GS') {barColor = '#dc3545';} // Red for ground stop
            else if (tmi.type === 'APREQ') {barColor = '#17a2b8';} // Cyan for APREQ

            // Compliance-based styling
            let opacity = 1;
            let pattern = '';
            if (tmi.cancelled) {
                pattern = ' stroke-dasharray="5,5"';
                opacity = 0.5;
            } else if (tmi.compliance !== null && tmi.compliance < 75) {
                opacity = 0.7;
            }

            // TMI label (left side)
            svg += `<text x="${leftMargin - 5}" y="${y + barHeight/2 + 4}" text-anchor="end" font-size="11" fill="#333" font-weight="bold">${tmi.label}</text>`;

            // TMI bar
            svg += `<rect x="${barX}" y="${y}" width="${barWidth}" height="${barHeight}" fill="${barColor}" opacity="${opacity}" rx="3"${pattern} class="gantt-bar" data-tmi-index="${i}" style="cursor:pointer;" onclick="TMICompliance.scrollToTmiCard(${i})"/>`;

            // Sublabel inside bar (if room)
            if (barWidth > 50) {
                svg += `<text x="${barX + 5}" y="${y + barHeight/2 + 4}" font-size="9" fill="#fff">${tmi.sublabel}</text>`;
            }

            // Compliance badge (right of bar)
            if (tmi.compliance !== null) {
                const badgeX = barX + barWidth + 5;
                const compClass = tmi.compliance >= 90 ? '#28a745' : (tmi.compliance >= 75 ? '#ffc107' : '#dc3545');
                svg += `<text x="${badgeX}" y="${y + barHeight/2 + 4}" font-size="9" fill="${compClass}" font-weight="bold">${tmi.compliance.toFixed(0)}%</text>`;
            } else {
                const badgeX = barX + barWidth + 5;
                // APREQ shows "track", MIT/GS with no pairs shows "N/A"
                const nullLabel = tmi.type === 'APREQ' ? 'track' : 'N/A';
                svg += `<text x="${badgeX}" y="${y + barHeight/2 + 4}" font-size="9" fill="#6c757d" font-style="italic">${nullLabel}</text>`;
            }
        });

        // Legend
        const legendY = 8;
        const legendHtml = `
            <div class="gantt-legend d-flex align-items-center small text-muted">
                <span class="mr-3"><span class="legend-box" style="background:#007bff;"></span> MIT/MINIT</span>
                <span class="mr-3"><span class="legend-box" style="background:#dc3545;"></span> Ground Stop</span>
                <span class="mr-3"><span class="legend-box" style="background:#17a2b8;"></span> APREQ/CFR</span>
                <span class="mr-3"><span class="legend-line legend-dashed"></span> Cancelled</span>
            </div>
        `;

        return `
            <div class="tmi-gantt-chart card mb-3">
                <div class="card-header py-2 d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-stream"></i> TMI Timeline</span>
                    ${legendHtml}
                </div>
                <div class="card-body p-2">
                    <div class="gantt-container">
                        <svg width="100%" height="${chartHeight}" viewBox="0 0 ${chartWidth} ${chartHeight}" preserveAspectRatio="xMinYMin meet">
                            ${svg}
                        </svg>
                    </div>
                    <div class="small text-muted mt-2">
                        <i class="fas fa-info-circle"></i> Click on a TMI bar to scroll to its detailed analysis below.
                    </div>
                </div>
            </div>
        `;
    },

    // Parse event time string (format: "2026-01-18T00:00:00" or "2026-01-18 00:00")
    // IMPORTANT: Treat as UTC - append Z if no timezone indicator present
    parseEventTime: function(timeStr) {
        if (!timeStr) {return null;}
        // If no timezone indicator (Z, +, or -), append Z to parse as UTC
        if (!timeStr.endsWith('Z') && !timeStr.includes('+') && !/T\d{2}:\d{2}:\d{2}-/.test(timeStr)) {
            timeStr += 'Z';
        }
        const d = new Date(timeStr);
        return isNaN(d.getTime()) ? null : d;
    },

    // Parse TMI time string (format: "HH:MMZ" or "HHMM") relative to event date
    parseTmiTime: function(timeStr, eventStart) {
        if (!timeStr || !eventStart) {return null;}

        // Extract hours and minutes from various formats
        const match = timeStr.match(/(\d{2}):?(\d{2})Z?/);
        if (!match) {return null;}

        const hours = parseInt(match[1]);
        const minutes = parseInt(match[2]);

        // Create date based on event start date
        const result = new Date(eventStart);
        result.setUTCHours(hours, minutes, 0, 0);

        // Handle wrap-around (e.g., 23:59Z to 04:00Z)
        if (result < eventStart) {
            result.setUTCDate(result.getUTCDate() + 1);
        }

        return result;
    },

    // Scroll to TMI detail card when clicking gantt bar
    scrollToTmiCard: function(index) {
        // Find all TMI cards and scroll to the one at this index
        const cards = document.querySelectorAll('.tmi-card');
        if (cards[index]) {
            cards[index].scrollIntoView({ behavior: 'smooth', block: 'center' });
            // Flash highlight
            cards[index].classList.add('highlight-flash');
            setTimeout(() => cards[index].classList.remove('highlight-flash'), 1500);
        }
    },

    renderMitCard: function(r) {
        const crossings = r.crossings || r.total_crossings || 0;
        const pairs = r.pairs || 0;
        const hasPairs = pairs > 0 || crossings >= 2;
        const compPct = hasPairs ? (r.compliance_pct || 0) : null;
        const compClass = compPct !== null ? this.getComplianceClass(compPct) : 'text-muted';
        const detailId = `mit_detail_${++this.detailIdCounter}`;
        const violationId = `mit_violations_${this.detailIdCounter}`;
        const diagramId = `mit_diagram_${this.detailIdCounter}`;
        const allPairs = r.all_pairs || [];
        const violations = allPairs.filter(p => p.spacing_category === 'UNDER');
        const compliant = allPairs.filter(p => p.spacing_category !== 'UNDER');
        const unitLabel = r.unit === 'min' ? 'min' : 'nm';
        const required = r.required || 0;
        const tmiType = r.unit === 'min' ? 'MINIT' : 'MIT';

        // Calculate streak metrics
        const streaks = this.calcComplianceStreaks(allPairs);

        // Format standardized TMI notation
        const standardizedTMI = this.formatStandardizedTMI(r);

        // Measurement type badge
        const measurementType = r.measurement_type || 'FIX';
        const measurementPoint = r.measurement_point || r.fix || '';
        const isBoundary = measurementType === 'BOUNDARY';
        const measurementBadge = isBoundary
            ? `<span class="badge badge-info ml-2" title="Measured at ARTCC handoff point"><i class="fas fa-border-all"></i> Boundary</span>`
            : measurementType === 'BOUNDARY_FALLBACK_FIX'
                ? `<span class="badge badge-secondary ml-2" title="Boundary unavailable, measured at fix"><i class="fas fa-map-marker-alt"></i> Fix (fallback)</span>`
                : '';

        // Determine display name: use fix if real, otherwise use destination(s)
        const isRealFix = r.fix && !['ALL', 'ANY', ''].includes((r.fix || '').toUpperCase());
        const displayName = isRealFix ? r.fix : (r.destinations?.join(',') || 'Unknown');

        // Check for data gap overlap
        const gapBadge = this.renderTMIGapBadge(r.tmi_start, r.tmi_end);

        let html = `
            <div class="tmi-card mit-card${gapBadge ? ' has-data-gap' : ''}">
                <!-- TMI Header with standardized notation -->
                <div class="tmi-header">
                    <div>
                        <span class="tmi-fix-name">${displayName}</span>
                        <span class="tmi-type-badge ml-2">${required}${unitLabel} ${tmiType}</span>
                        <span class="text-muted ml-2">| ${r.tmi_start || ''} - ${r.tmi_end || ''}</span>
                        ${r.cancelled ? '<span class="badge badge-warning ml-2">CANCELLED</span>' : ''}
                        ${gapBadge}
                        ${measurementBadge}
                    </div>
                    <div class="compliance-badge ${compClass}">${compPct !== null ? compPct.toFixed(1) + '%' : 'N/A'}</div>
                </div>
                <!-- Standardized TMI Notation -->
                <div class="standardized-tmi mb-2">
                    <code class="small">${standardizedTMI}</code>
                    ${isBoundary ? `<span class="small text-info ml-2"><i class="fas fa-ruler"></i> Measured at: ${measurementPoint}</span>` : ''}
                </div>
                <div class="tmi-stats">
                    <div class="tmi-stat">
                        <div class="tmi-stat-value">${r.crossings || r.total_crossings || 0}</div>
                        <div class="tmi-stat-label">Crossings</div>
                    </div>
                    <div class="tmi-stat">
                        <div class="tmi-stat-value">${r.pairs || 0}</div>
                        <div class="tmi-stat-label">Pairs Analyzed</div>
                    </div>
                    <div class="tmi-stat">
                        <div class="tmi-stat-value">${(r.spacing_stats?.avg || r.avg_spacing || 0).toFixed(1)}${unitLabel}</div>
                        <div class="tmi-stat-label">Avg Spacing</div>
                    </div>
                    <div class="tmi-stat">
                        <div class="tmi-stat-value">${(r.spacing_stats?.min || r.min_spacing || 0).toFixed(1)}${unitLabel}</div>
                        <div class="tmi-stat-label">Min Spacing</div>
                    </div>
                </div>
                ${r.distribution ? this.renderDistribution(r.distribution) : ''}

                <!-- Good compliance metrics -->
                ${streaks.longestGood > 0 ? `
                <div class="mt-2 small text-success d-flex align-items-center">
                    <span class="mr-3"><i class="fas fa-trophy"></i> Longest compliant streak: <strong>${streaks.longestGood}</strong> pairs</span>
                    ${streaks.goodPeriods.length > 0 ? `<span class="text-muted">| ${streaks.goodPeriods.length} compliant periods</span>` : ''}
                </div>
                ` : ''}

                <!-- Visual Spacing Diagram -->
                ${allPairs.length > 0 ? this.renderSpacingDiagram(allPairs, required, r.unit, diagramId, r.tmi_start, r.tmi_end) : ''}
        `;

        // Violations summary with expand button
        if (violations.length > 0) {
            const maxDiff = Math.max(...violations.map(v => Math.abs(v.shortfall_pct || 0)));
            html += `
                <div class="mt-2 small text-danger d-flex justify-content-between align-items-center">
                    <span>
                        <i class="fas fa-exclamation-triangle"></i>
                        <code>${violations.length}</code> violations | Max difference: <code>-${maxDiff}%</code>
                    </span>
                    <button class="btn btn-sm btn-outline-danger" type="button" data-toggle="collapse" data-target="#${violationId}">
                        <i class="fas fa-eye"></i> Show Violations
                    </button>
                </div>
                <div class="collapse mt-2" id="${violationId}">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped tmi-detail-table">
                            <thead class="thead-dark">
                                <tr>
                                    <th>Lead Aircraft</th>
                                    <th>Trail Aircraft</th>
                                    <th>Gap (mm:ss)</th>
                                    <th style="min-width: 180px;">Spacing <span class="badge badge-light ml-1">${required}${unitLabel} req</span></th>
                                    <th>Required</th>
                                    <th>Difference</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${violations.map(v => {
        const marginAmt = this.calcMarginAmount(v.spacing, v.required);
        return `
                                    <tr class="table-danger">
                                        <td><code>${v.prev_callsign}</code><br><code class="text-muted small">${v.prev_time}</code></td>
                                        <td><code>${v.curr_callsign}</code><br><code class="text-muted small">${v.curr_time}</code></td>
                                        <td><code>${this.formatTimeGap(v.time_min)}</code></td>
                                        <td>${this.renderSpacingBarWithLabel(v.spacing, v.required, unitLabel)}</td>
                                        <td><code>${v.required}${unitLabel}</code></td>
                                        <td class="text-danger"><code><strong>${marginAmt}${unitLabel}</strong> (${v.shortfall_pct > 0 ? '-' : ''}${v.shortfall_pct}%)</code></td>
                                    </tr>
                                    `;
    }).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>
            `;
        }

        // All pairs detail button
        if (allPairs.length > 0) {
            html += `
                <div class="mt-2 d-flex justify-content-between align-items-center">
                    <span class="small text-muted">${allPairs.length} consecutive pairs analyzed (${compliant.length} compliant)</span>
                    <button class="btn btn-sm btn-outline-primary" type="button" data-toggle="collapse" data-target="#${detailId}">
                        <i class="fas fa-table"></i> All Pairs Detail
                    </button>
                </div>
                <div class="collapse mt-2" id="${detailId}">
                    <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                        <table class="table table-sm table-striped tmi-detail-table">
                            <thead class="thead-light sticky-top">
                                <tr>
                                    <th>Lead</th>
                                    <th>Trail</th>
                                    <th>Gap (mm:ss)</th>
                                    <th style="min-width: 180px;">Spacing <span class="badge badge-secondary ml-1">${required}${unitLabel} req</span></th>
                                    <th>Margin</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${allPairs.map(p => {
        const rowClass = p.spacing_category === 'UNDER' ? 'table-danger' :
            p.spacing_category === 'WITHIN' ? 'table-success' :
                p.spacing_category === 'GAP' ? 'table-warning' : '';
        const statusBadge = p.spacing_category === 'UNDER' ? '<span class="badge badge-danger">UNDER</span>' :
            p.spacing_category === 'WITHIN' ? '<span class="badge badge-success">WITHIN</span>' :
                p.spacing_category === 'OVER' ? '<span class="badge badge-info">OVER</span>' :
                    '<span class="badge badge-warning">GAP</span>';
        const marginAmt = this.calcMarginAmount(p.spacing, required);
        const marginSign = parseFloat(marginAmt) >= 0 ? '+' : '';
        return `
                                        <tr class="${rowClass}">
                                            <td><code>${p.prev_callsign}</code><br><code class="text-muted small">${p.prev_time}</code></td>
                                            <td><code>${p.curr_callsign}</code><br><code class="text-muted small">${p.curr_time}</code></td>
                                            <td><code>${this.formatTimeGap(p.time_min)}</code></td>
                                            <td>${this.renderSpacingBarWithLabel(p.spacing, required, unitLabel)}</td>
                                            <td class="${p.margin_pct < 0 ? 'text-danger' : 'text-success'}"><code>${marginSign}${marginAmt}${unitLabel}</code><br><small>(${p.margin_pct > 0 ? '+' : ''}${p.margin_pct}%)</small></td>
                                            <td>${statusBadge}</td>
                                        </tr>
                                    `;
    }).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>
            `;
        }

        // Add trajectory counts info
        // Stream flights = total crossings, Analyzed = unique callsigns in all_pairs (those with trajectory data)
        const streamFlights = r.crossings || r.total_crossings || 0;
        const analyzedCallsigns = new Set();
        allPairs.forEach(p => {
            if (p.prev_callsign) analyzedCallsigns.add(p.prev_callsign);
            if (p.curr_callsign) analyzedCallsigns.add(p.curr_callsign);
        });
        const analyzedFlights = analyzedCallsigns.size;
        html += this.renderTrajectoryCounts(r.tmi_start, r.tmi_end, streamFlights, analyzedFlights);

        // Add context map section
        const mapId = `mit_map_${this.detailIdCounter}`;
        html += this.renderMapSection(r, mapId);

        html += '</div>';
        return html;
    },

    /**
     * Render a group of multi-facility TMIs as a parent wrapper with sub-entries.
     * e.g., "DFW via PNUTS 30 MIT ZFW:ZHU,ZME 0000-0400" wraps the individual
     * ZFW:ZHU and ZFW:ZME boundary analyses.
     */
    renderMultiFacilityGroup: function(members) {
        if (!members || members.length === 0) return '';
        const first = members[0];
        const originalFac = first.original_facilities || `${first.requestor}:${first.provider}`;
        const fix = first.fix || '';
        const required = first.required || 0;
        const unitLabel = first.unit === 'min' ? 'min' : 'nm';
        const tmiType = first.unit === 'min' ? 'MINIT' : 'MIT';
        const destinations = first.destinations ? first.destinations.join(',') : '';
        const isRealFix = fix && !['ALL', 'ANY', ''].includes(fix.toUpperCase());
        const tmiStart = first.tmi_start || '';
        const tmiEnd = first.tmi_end || '';

        // Aggregate stats across all sub-TMIs
        let totalPairs = 0, totalCrossings = 0, totalViolations = 0;
        let allSpacings = [];
        for (const m of members) {
            totalPairs += m.pairs || 0;
            totalCrossings += m.crossings || m.total_crossings || 0;
            const pairs = m.all_pairs || [];
            pairs.forEach(p => { if (p.spacing != null) allSpacings.push(p.spacing); });
            totalViolations += pairs.filter(p => p.spacing_category === 'UNDER').length;
        }
        const aggCompPct = totalPairs > 0 ? ((totalPairs - totalViolations) / totalPairs * 100) : 0;
        const aggCompClass = this.getComplianceClass(aggCompPct);
        const avgSpacing = allSpacings.length > 0 ? (allSpacings.reduce((a,b) => a+b, 0) / allSpacings.length) : 0;

        // Build notation line
        let notation = '';
        if (destinations) notation += `${destinations} `;
        if (isRealFix) notation += `via ${fix} `;
        notation += `${required}${unitLabel} ${tmiType} `;
        notation += `${originalFac} `;
        notation += `${tmiStart}-${tmiEnd}`;

        const groupToggleId = `mfg_${++this.detailIdCounter}`;

        let html = `
            <div class="tmi-card mit-card multi-facility-group" style="border-left:3px solid #17a2b8;background:rgba(23,162,184,0.04);">
                <!-- Multi-Facility Group Header -->
                <div class="tmi-header">
                    <div>
                        <span class="tmi-fix-name">${isRealFix ? fix : (destinations || 'Unknown')}</span>
                        <span class="tmi-type-badge ml-2">${required}${unitLabel} ${tmiType}</span>
                        <span class="text-muted ml-2">| ${tmiStart} - ${tmiEnd}</span>
                        <span class="badge badge-info ml-2" title="Multi-facility TMI analyzed at ${members.length} boundaries"><i class="fas fa-layer-group"></i> ${members.length} boundaries</span>
                    </div>
                    <div class="compliance-badge ${aggCompClass}">${aggCompPct.toFixed(1)}%</div>
                </div>
                <div class="standardized-tmi mb-2">
                    <code class="small">${notation.trim()}</code>
                </div>
                <!-- Aggregate stats -->
                <div class="tmi-stats">
                    <div class="tmi-stat">
                        <div class="tmi-stat-value">${totalCrossings}</div>
                        <div class="tmi-stat-label">Total Crossings</div>
                    </div>
                    <div class="tmi-stat">
                        <div class="tmi-stat-value">${totalPairs}</div>
                        <div class="tmi-stat-label">Total Pairs</div>
                    </div>
                    <div class="tmi-stat">
                        <div class="tmi-stat-value">${avgSpacing.toFixed(1)}${unitLabel}</div>
                        <div class="tmi-stat-label">Avg Spacing</div>
                    </div>
                    <div class="tmi-stat">
                        <div class="tmi-stat-value">${members.length}</div>
                        <div class="tmi-stat-label">Boundaries</div>
                    </div>
                </div>
                <!-- Per-boundary summary table -->
                <div class="mt-2 mb-2">
                    <table class="table table-sm table-striped mb-0" style="font-size:0.85rem;">
                        <thead><tr>
                            <th>Boundary</th>
                            <th class="text-center">Crossings</th>
                            <th class="text-center">Pairs</th>
                            <th class="text-center">Compliance</th>
                            <th class="text-center">Avg Spacing</th>
                        </tr></thead>
                        <tbody>`;

        for (const m of members) {
            const mPairs = m.pairs || 0;
            const mComp = m.compliance_pct || 0;
            const mClass = this.getComplianceClass(mComp);
            const mAvg = (m.spacing_stats?.avg || 0).toFixed(1);
            const mPoint = m.measurement_point || `${m.requestor}:${m.provider}`;
            html += `
                            <tr>
                                <td><i class="fas fa-border-all text-info mr-1"></i> ${m.requestor}:${m.provider}
                                    <span class="text-muted small ml-1">${mPoint}</span></td>
                                <td class="text-center">${m.crossings || m.total_crossings || 0}</td>
                                <td class="text-center">${mPairs}</td>
                                <td class="text-center"><span class="${mClass} font-weight-bold">${mComp.toFixed(1)}%</span></td>
                                <td class="text-center">${mAvg}${unitLabel}</td>
                            </tr>`;
        }

        html += `
                        </tbody>
                    </table>
                </div>
                <!-- Expand per-boundary details -->
                <div class="mt-1 mb-2">
                    <button class="btn btn-sm btn-outline-info" type="button" data-toggle="collapse" data-target="#${groupToggleId}">
                        <i class="fas fa-chevron-down"></i> Per-Boundary Details
                    </button>
                </div>
                <div class="collapse" id="${groupToggleId}">`;

        // Render each sub-TMI as an individual card inside the group
        for (const m of members) {
            html += this.renderMitCard(m);
        }

        html += `
                </div>
            </div>`;

        return html;
    },

    renderGsCard: function(r) {
        const compPct = r.compliance_pct || 0;
        const compClass = this.getComplianceClass(compPct);
        const detailId = `gs_detail_${++this.detailIdCounter}`;
        const originId = `gs_origin_${this.detailIdCounter}`;

        // Handle both naming conventions (exempt_flights OR exempt)
        const exemptFlights = r.exempt_flights || r.exempt || [];
        const compliantFlights = r.compliant_flights || r.compliant || [];
        const nonCompliantFlights = r.non_compliant_flights || r.non_compliant || [];
        const notInScopeFlights = r.not_in_scope || [];
        const exemptCount = r.exempt_count || exemptFlights.length;
        const compliantCount = r.compliant_count || compliantFlights.length;
        const nonCompliantCount = r.non_compliant_count || r.violations?.total || nonCompliantFlights.length;
        const notInScopeCount = notInScopeFlights.length;

        // Check for data gap overlap
        const gapBadge = this.renderTMIGapBadge(r.gs_start, r.gs_end);

        // Ended-by badge
        let endedBadge = '';
        if (r.ended_by === 'CNX' || r.cancelled) {
            endedBadge = '<span class="gs-ended-badge cnx">CNX</span>';
        } else if (r.ended_by === 'EXPIRATION') {
            endedBadge = '<span class="gs-ended-badge expired">EXPIRED</span>';
        }

        let html = `
            <div class="tmi-card gs-card${gapBadge ? ' has-data-gap' : ''}">
                <div class="tmi-header">
                    <div>
                        <span class="tmi-fix-name">Ground Stop</span>
                        <span class="text-muted ml-2">
                            ${(r.destinations || []).join(', ')} |
                            ${r.gs_start || ''} - ${r.gs_end || ''} |
                            Issued: ${r.gs_issued || 'N/A'}
                        </span>
                        ${endedBadge}
                        ${gapBadge}
                    </div>
                    <div class="compliance-badge ${compClass}">${compPct.toFixed(1)}%</div>
                </div>
        `;

        // Impacting condition + program metadata
        if (r.impacting_condition) {
            html += `<div class="text-muted small mb-1"><i class="fas fa-cloud"></i> ${this.escapeHtml(r.impacting_condition)}</div>`;
        }
        if (r.dep_facility_tier) {
            html += `<span class="badge badge-outline-secondary mr-1" style="font-size:0.7rem;">${this.escapeHtml(r.dep_facility_tier)}</span>`;
        }
        if (r.prob_extension) {
            html += `<div class="text-muted small mb-1"><i class="fas fa-clock"></i> Prob Extension: ${this.escapeHtml(r.prob_extension)}</div>`;
        }

        // Program timeline bar (backward compat: only render if present)
        if (r.program_timeline && r.program_timeline.length > 0) {
            html += this.renderGsTimelineBar(r.program_timeline, r.gs_start, r.gs_end);

            // Advisory detail section (collapsible)
            const advDetailId = `gs_adv_detail_${this.detailIdCounter}`;
            html += `
                <div class="mt-1">
                    <button class="btn btn-sm btn-outline-secondary btn-xs" type="button" data-toggle="collapse" data-target="#${advDetailId}" style="font-size:0.72rem; padding:1px 6px;">
                        <i class="fas fa-list-alt"></i> Advisory Details (${r.program_timeline.length})
                    </button>
                </div>
                <div class="collapse mt-2" id="${advDetailId}">
                    <div class="advisory-chain">
                        ${r.program_timeline.map(adv => {
                            const typeClass = adv.type === 'INITIAL' ? 'danger' : adv.type === 'CNX' ? 'success' : 'warning';
                            return `<div class="advisory-detail-card${adv.type === 'CNX' ? ' cnx' : ''}">
                                <div class="advisory-detail-header">
                                    <span class="badge badge-${typeClass}">ADVZY ${adv.advzy || '?'}</span>
                                    <span class="advisory-type">${adv.type || ''}</span>
                                    ${adv.start ? `<span class="text-muted">${adv.start} - ${adv.end || '?'}</span>` : ''}
                                    <span class="text-muted small">Issued: ${adv.issued || 'N/A'}</span>
                                </div>
                                ${adv.impacting_condition ? `<div class="advisory-meta"><i class="fas fa-cloud"></i> ${this.escapeHtml(adv.impacting_condition)}</div>` : ''}
                                ${adv.dep_facilities && adv.dep_facilities.length ? `<div class="advisory-meta"><i class="fas fa-building"></i> DEP: ${adv.dep_facilities.join(', ')}${adv.dep_facility_tier ? ' (' + adv.dep_facility_tier + ')' : ''}</div>` : ''}
                                ${adv.prob_extension ? `<div class="advisory-meta"><i class="fas fa-clock"></i> Extension: ${this.escapeHtml(adv.prob_extension)}</div>` : ''}
                                ${adv.comments ? `<div class="advisory-meta"><i class="fas fa-comment"></i> ${this.escapeHtml(adv.comments)}</div>` : ''}
                            </div>`;
                        }).join('')}
                    </div>
                </div>
            `;
        }

        // Stats row
        html += `
                <div class="tmi-stats">
                    <div class="tmi-stat">
                        <div class="tmi-stat-value">${r.total_flights || 0}</div>
                        <div class="tmi-stat-label">Total Flights</div>
                    </div>
                    <div class="tmi-stat">
                        <div class="tmi-stat-value text-info">${exemptCount}</div>
                        <div class="tmi-stat-label">Exempt</div>
                    </div>
                    <div class="tmi-stat">
                        <div class="tmi-stat-value text-success">${compliantCount}</div>
                        <div class="tmi-stat-label">Compliant</div>
                    </div>
                    <div class="tmi-stat">
                        <div class="tmi-stat-value text-danger">${nonCompliantCount}</div>
                        <div class="tmi-stat-label">Violations</div>
                    </div>
        `;

        // Not-in-scope stat
        if (notInScopeCount > 0) {
            html += `
                    <div class="tmi-stat">
                        <div class="tmi-stat-value text-muted">${notInScopeCount}</div>
                        <div class="tmi-stat-label">Not In Scope</div>
                    </div>
            `;
        }

        // Avg hold time with min/max/median tooltip
        if (r.avg_hold_time_min && r.avg_hold_time_min > 0) {
            const stats = r.hold_time_stats || {};
            const tooltip = stats.min ? `Min: ${this.formatDuration(stats.min)} | Median: ${this.formatDuration(stats.median)} | Max: ${this.formatDuration(stats.max)}` : '';
            html += `
                    <div class="tmi-stat" ${tooltip ? `title="${tooltip}"` : ''}>
                        <div class="tmi-stat-value">${this.formatDuration(r.avg_hold_time_min)}</div>
                        <div class="tmi-stat-label">Avg Hold</div>
                    </div>
            `;
        }

        // GS delay stat (from OOOI taxi time analysis)
        const gsDelay = r.gs_delay_stats || {};
        if (gsDelay.flights_with_delay_data > 0) {
            const delayTooltip = `Median: ${this.formatDuration(gsDelay.median_delay_min)} | Max: ${this.formatDuration(gsDelay.max_delay_min)} | Total: ${this.formatDuration(gsDelay.total_delay_min)} | Based on ${gsDelay.flights_with_delay_data} flights with OOOI data`;
            html += `
                    <div class="tmi-stat" title="${delayTooltip}">
                        <div class="tmi-stat-value">${this.formatDuration(gsDelay.avg_delay_min)}</div>
                        <div class="tmi-stat-label">Avg GS Delay</div>
                    </div>
            `;
        }

        html += `</div>`;

        // Time source breakdown
        const tsb = r.time_source_breakdown || {};
        const tsTotal = (tsb['off_utc'] || 0) + (tsb['out_utc+taxi'] || 0) + (tsb['first_seen'] || 0);
        if (tsTotal > 0) {
            html += `<div class="text-muted small mt-1" style="font-size:0.72rem;">
                <i class="fas fa-stopwatch"></i> Time sources:
                ${tsb['off_utc'] ? `<span class="badge badge-success mr-1" style="font-size:0.68rem;">${tsb['off_utc']} wheels-off</span>` : ''}
                ${tsb['out_utc+taxi'] ? `<span class="badge badge-info mr-1" style="font-size:0.68rem;">${tsb['out_utc+taxi']} gate+taxi</span>` : ''}
                ${tsb['first_seen'] ? `<span class="badge badge-warning mr-1" style="font-size:0.68rem;">${tsb['first_seen']} first-seen</span>` : ''}
            </div>`;
        }

        // CNX comments
        if (r.cnx_comments) {
            html += `<div class="gs-cnx-comments"><i class="fas fa-info-circle text-info"></i> ${this.escapeHtml(r.cnx_comments)}</div>`;
        }

        // Per-origin breakdown (collapsible)
        const perOrigin = r.per_origin_breakdown || [];
        if (perOrigin.length > 0) {
            html += `
                <div class="mt-2">
                    <button class="btn btn-sm btn-outline-info" type="button" data-toggle="collapse" data-target="#${originId}">
                        <i class="fas fa-map-marker-alt"></i> Per-Origin Breakdown (${perOrigin.length})
                    </button>
                </div>
                <div class="collapse mt-2 gs-per-origin" id="${originId}">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped sortable-table" id="gs_origin_tbl_${this.detailIdCounter}">
                            <thead class="thead-dark">
                                <tr>
                                    <th onclick="TMICompliance.sortTable('gs_origin_tbl_${this.detailIdCounter}',0,false)" style="cursor:pointer;">Origin</th>
                                    <th onclick="TMICompliance.sortTable('gs_origin_tbl_${this.detailIdCounter}',1,true)" style="cursor:pointer;">Total</th>
                                    <th onclick="TMICompliance.sortTable('gs_origin_tbl_${this.detailIdCounter}',2,true)" style="cursor:pointer;">Compliant</th>
                                    <th onclick="TMICompliance.sortTable('gs_origin_tbl_${this.detailIdCounter}',3,true)" style="cursor:pointer;">Non-Comp</th>
                                    <th onclick="TMICompliance.sortTable('gs_origin_tbl_${this.detailIdCounter}',4,true)" style="cursor:pointer;">Exempt</th>
                                    <th onclick="TMICompliance.sortTable('gs_origin_tbl_${this.detailIdCounter}',5,true)" style="cursor:pointer;">Rate</th>
                                    <th onclick="TMICompliance.sortTable('gs_origin_tbl_${this.detailIdCounter}',6,true)" style="cursor:pointer;">Avg Hold</th>
                                    <th onclick="TMICompliance.sortTable('gs_origin_tbl_${this.detailIdCounter}',7,true)" style="cursor:pointer;">Avg Delay</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${perOrigin.map(o => {
                                    const oPct = o.compliance_pct || 0;
                                    const oClass = this.getComplianceClass(oPct);
                                    const compW = o.total > 0 ? Math.round(((o.compliant || 0) / o.total) * 100) : 0;
                                    const ncW = o.total > 0 ? Math.round(((o.non_compliant || 0) / o.total) * 100) : 0;
                                    const exW = 100 - compW - ncW;
                                    return `<tr>
                                        <td>${o.origin}
                                            <div class="compliance-bar"><span class="bg-success" style="width:${compW}%"></span><span class="bg-danger" style="width:${ncW}%"></span><span class="bg-info" style="width:${exW}%"></span></div>
                                        </td>
                                        <td>${o.total || 0}</td>
                                        <td class="text-success">${o.compliant || 0}</td>
                                        <td class="text-danger">${o.non_compliant || 0}</td>
                                        <td class="text-info">${o.exempt || 0}</td>
                                        <td><span class="compliance-badge ${oClass}" style="font-size:0.8rem;">${oPct.toFixed(1)}%</span></td>
                                        <td>${o.avg_hold_time_min ? TMICompliance.formatDuration(o.avg_hold_time_min) : '-'}</td>
                                        <td>${o.avg_gs_delay_min ? TMICompliance.formatDuration(o.avg_gs_delay_min) : '-'}</td>
                                    </tr>`;
                                }).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>
            `;
        }

        // Show flight details
        const hasDetails = exemptFlights.length > 0 || compliantFlights.length > 0 || nonCompliantFlights.length > 0;

        if (hasDetails) {
            html += `
                <div class="mt-2 d-flex justify-content-end">
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-toggle="collapse" data-target="#${detailId}">
                        <i class="fas fa-plane"></i> Flight Details
                    </button>
                </div>
                <div class="collapse mt-2" id="${detailId}">
                    <div class="row">
            `;

            // Non-compliant flights (violations) - with Phase, Source, and GS Delay columns
            if (nonCompliantFlights.length > 0) {
                const hasPhase = nonCompliantFlights.some(f => f.phase);
                const hasGsDelay = nonCompliantFlights.some(f => f.gs_delay_min !== undefined);
                html += `
                    <div class="col-md-4">
                        <h6 class="text-danger"><i class="fas fa-times-circle"></i> Violations (${nonCompliantFlights.length})</h6>
                        <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                            <table class="table table-sm table-striped">
                                <thead class="thead-dark sticky-top">
                                    <tr><th>Callsign</th><th>Origin</th><th>Dept Time</th><th>Into GS</th>${hasPhase ? '<th>Phase</th>' : ''}${hasGsDelay ? '<th>GS Delay</th>' : ''}<th>Source</th></tr>
                                </thead>
                                <tbody>
                                    ${nonCompliantFlights.map(f => `
                                        <tr class="table-danger">
                                            <td>${f.callsign}</td>
                                            <td>${f.dept || 'N/A'}</td>
                                            <td>${f.dept_time || 'N/A'}</td>
                                            <td>${f.pct_into_gs ? f.pct_into_gs + '%' : ''}</td>
                                            ${hasPhase ? `<td>${TMICompliance.formatPhase(f.phase, f.phase_type)}</td>` : ''}
                                            ${hasGsDelay ? `<td>${f.gs_delay_min !== undefined ? TMICompliance.formatDuration(f.gs_delay_min) : ''}</td>` : ''}
                                            <td class="text-muted">${f.time_source || ''}</td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                    </div>
                `;
            }

            // Exempt flights
            if (exemptFlights.length > 0) {
                html += `
                    <div class="col-md-4">
                        <h6 class="text-info"><i class="fas fa-check-circle"></i> Exempt (${exemptFlights.length})</h6>
                        <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                            <table class="table table-sm table-striped">
                                <thead class="thead-light sticky-top">
                                    <tr><th>Callsign</th><th>Origin</th><th>Dept Time</th><th>Reason</th></tr>
                                </thead>
                                <tbody>
                                    ${exemptFlights.map(f => `
                                        <tr>
                                            <td>${f.callsign}</td>
                                            <td>${f.dept || 'N/A'}</td>
                                            <td>${f.dept_time || 'N/A'}</td>
                                            <td class="text-muted">${f.reason || ''}</td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                    </div>
                `;
            }

            // Compliant flights - with Hold, GS Delay, and Source columns
            if (compliantFlights.length > 0) {
                const hasCompGsDelay = compliantFlights.some(f => f.gs_delay_min !== undefined);
                html += `
                    <div class="col-md-4">
                        <h6 class="text-success"><i class="fas fa-check"></i> Compliant (${compliantFlights.length})</h6>
                        <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                            <table class="table table-sm table-striped">
                                <thead class="thead-light sticky-top">
                                    <tr><th>Callsign</th><th>Origin</th><th>Dept Time</th><th>Hold</th>${hasCompGsDelay ? '<th>GS Delay</th>' : ''}<th>Source</th></tr>
                                </thead>
                                <tbody>
                                    ${compliantFlights.map(f => `
                                        <tr>
                                            <td>${f.callsign}</td>
                                            <td>${f.dept || 'N/A'}</td>
                                            <td>${f.dept_time || 'N/A'}</td>
                                            <td>${f.hold_time_min ? TMICompliance.formatDuration(f.hold_time_min) : ''}</td>
                                            ${hasCompGsDelay ? `<td>${f.gs_delay_min !== undefined ? TMICompliance.formatDuration(f.gs_delay_min) : ''}</td>` : ''}
                                            <td class="text-muted">${f.time_source || ''}</td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                    </div>
                `;
            }

            html += `
                    </div>
                </div>
            `;
        }

        // Add trajectory counts info
        // Stream flights = total, Analyzed = compliant + non-compliant + exempt (those with trajectory data)
        const streamFlights = r.total_flights || 0;
        const analyzedFlights = compliantCount + nonCompliantCount + exemptCount;
        html += this.renderTrajectoryCounts(r.gs_start, r.gs_end, streamFlights, analyzedFlights);

        html += '</div>';
        return html;
    },

    /**
     * Render GS program timeline bar
     * Shows advisory phases proportionally on a time axis
     */
    renderGsTimelineBar: function(timeline, gsStart, gsEnd) {
        if (!timeline || timeline.length === 0) return '';

        // Filter to phases with start/end (skip CNX entries which have null times)
        const phases = timeline.filter(p => p.start && p.end);
        const cnxEntry = timeline.find(p => (p.type || '').toUpperCase() === 'CNX');

        if (phases.length === 0 && !cnxEntry) return '';

        // Parse time to minutes for proportional sizing
        const parseTime = (t) => {
            if (!t) return 0;
            const match = t.match(/(\d{2}):?(\d{2})/);
            return match ? parseInt(match[1]) * 60 + parseInt(match[2]) : 0;
        };

        // Compute total span (handle overnight wrap)
        const allStarts = phases.map(p => parseTime(p.start));
        const allEnds = phases.map(p => parseTime(p.end));
        const totalStart = Math.min(...allStarts);
        let totalEnd = Math.max(...allEnds);
        let totalSpan = totalEnd - totalStart;
        if (totalSpan <= 0) totalSpan += 1440; // Overnight wrap: add 24h

        let html = '<div class="gs-timeline">';

        phases.forEach(p => {
            const pStart = parseTime(p.start);
            let pEnd = parseTime(p.end);
            if (pEnd <= pStart) pEnd += 1440; // Overnight wrap for individual phase
            const pct = ((pEnd - pStart) / totalSpan * 100).toFixed(1);
            const pType = (p.type || '').toUpperCase();
            let phaseClass = 'phase-initial';
            if (pType === 'EXTENSION') phaseClass = 'phase-extension';
            const label = p.advzy ? `ADVZY ${p.advzy}` : pType;

            html += `<div class="phase ${phaseClass}" style="flex: ${pct} 0 0;" title="${label}: ${p.start} - ${p.end}">${label}</div>`;
        });

        // Small CNX marker at the end
        if (cnxEntry) {
            html += `<div class="phase phase-cnx" title="CNX: ${cnxEntry.issued || ''}"></div>`;
        }

        html += '</div>';
        return html;
    },

    /**
     * Get Bootstrap badge class for reroute action type
     */
    getActionBadgeClass: function(action) {
        switch ((action || '').toUpperCase()) {
            case 'RQD': return 'badge-danger';
            case 'RMD': return 'badge-warning';
            case 'FYI': return 'badge-info';
            case 'PLN': return 'badge-secondary';
            default: return 'badge-light';
        }
    },

    /**
     * Toggle visibility of an expandable flight detail row
     */
    toggleFlightDetail: function(rowId) {
        var detailRow = document.getElementById(rowId);
        if (detailRow) {
            var isHidden = detailRow.style.display === 'none';
            detailRow.style.display = isHidden ? '' : 'none';
            // Toggle chevron on the trigger row (previous sibling)
            var triggerRow = detailRow.previousElementSibling;
            if (triggerRow) {
                var chevron = triggerRow.querySelector('.expand-chevron');
                if (chevron) {
                    chevron.classList.toggle('fa-chevron-down', !isHidden);
                    chevron.classList.toggle('fa-chevron-up', isHidden);
                }
            }
        }
    },

    /**
     * Sort a table by column index. Toggles asc/desc on repeated clicks.
     * @param {string} tableId - DOM id of the table
     * @param {number} colIdx - Column index to sort by
     * @param {boolean} isNumeric - Whether to sort numerically
     */
    sortTable: function(tableId, colIdx, isNumeric) {
        var table = document.getElementById(tableId);
        if (!table) return;
        var tbody = table.querySelector('tbody');
        if (!tbody) return;
        var allRows = Array.from(tbody.querySelectorAll('tr'));

        // Determine sort direction from header state
        var th = table.querySelectorAll('thead th')[colIdx];
        var asc = true;
        if (th) {
            asc = th.getAttribute('data-sort-dir') !== 'asc';
            // Clear all header sort indicators
            table.querySelectorAll('thead th').forEach(function(h) {
                h.removeAttribute('data-sort-dir');
                h.classList.remove('sort-asc', 'sort-desc');
            });
            th.setAttribute('data-sort-dir', asc ? 'asc' : 'desc');
            th.classList.add(asc ? 'sort-asc' : 'sort-desc');
        }

        // Group rows: pair each data row with its following detail row (if any).
        // Detail rows have an id containing '_detail_' and a colspan cell.
        var groups = [];
        for (var i = 0; i < allRows.length; i++) {
            var row = allRows[i];
            if (row.id && row.id.indexOf('_detail_') !== -1) continue; // skip detail rows (handled as part of group)
            var group = [row];
            // Check if next row is a detail row for this one
            if (i + 1 < allRows.length) {
                var next = allRows[i + 1];
                if (next.id && next.id.indexOf('_detail_') !== -1) {
                    group.push(next);
                    i++; // skip the detail row in the outer loop
                }
            }
            groups.push(group);
        }

        groups.sort(function(a, b) {
            var aRow = a[0], bRow = b[0];
            var aVal = (aRow.cells[colIdx] && aRow.cells[colIdx].textContent.trim()) || '';
            var bVal = (bRow.cells[colIdx] && bRow.cells[colIdx].textContent.trim()) || '';
            if (isNumeric) {
                var aNum = parseFloat(aVal.replace(/[^0-9.\-]/g, '')) || 0;
                var bNum = parseFloat(bVal.replace(/[^0-9.\-]/g, '')) || 0;
                return asc ? aNum - bNum : bNum - aNum;
            }
            return asc ? aVal.localeCompare(bVal) : bVal.localeCompare(aVal);
        });

        groups.forEach(function(group) {
            group.forEach(function(row) { tbody.appendChild(row); });
        });
    },

    /**
     * Render expandable route match detail for a reroute flight
     * Shows: fix checklist, filed route with highlights, flown crossing details
     */
    renderFlightRouteDetail: function(flight, requiredFixes) {
        var filedMatched = flight.filed_matched_fixes || [];
        var flownMatched = flight.flown_matched_fixes || [];
        var flownDetails = flight.flown_fix_details || [];
        var fixes = flight.required_fixes || requiredFixes || [];

        var html = '<div class="flight-route-detail">';

        // 1. Fix checklist
        if (fixes.length > 0) {
            html += '<div class="fix-checklist"><div class="small text-muted mb-1">Required Fixes:</div><div class="fix-list">';
            fixes.forEach(function(fix) {
                var filedOk = filedMatched.indexOf(fix) >= 0;
                var flownOk = flownMatched.indexOf(fix) >= 0;
                var cls = (filedOk && flownOk) ? 'fix-matched' : (!filedOk && !flownOk) ? 'fix-missing' : 'fix-partial';
                var filedIcon = filedOk ? '\u2713' : '\u2717';
                var flownIcon = flownOk ? '\u2713' : '\u2717';
                html += '<span class="fix-check ' + cls + '" title="Filed: ' + filedIcon + ' | Flown: ' + flownIcon + '">' +
                    fix + ' <small class="text-muted">(F:' + filedIcon + ' L:' + flownIcon + ')</small></span>';
            });
            html += '</div></div>';
        }

        // 2. Filed route with fix highlighting
        if (flight.filed_route) {
            var routeStr = this.escapeHtml(flight.filed_route);
            fixes.forEach(function(fix) {
                var matched = filedMatched.indexOf(fix) >= 0;
                var cls = matched ? 'fix-matched' : 'fix-missing';
                routeStr = routeStr.replace(new RegExp('\\b' + fix + '\\b', 'g'),
                    '<span class="' + cls + '">' + fix + '</span>');
            });
            html += '<div class="filed-route mt-1"><div class="small text-muted">Filed Route:</div><code class="route-string">' + routeStr + '</code></div>';
        }

        // 3. Flown crossing details table
        if (flownDetails.length > 0) {
            html += '<div class="mt-1"><div class="small text-muted">Flown Crossings:</div>' +
                '<table class="table table-sm table-bordered crossing-detail-table mb-0">' +
                '<thead><tr><th>Fix</th><th>Dist (nm)</th><th>Time</th><th>Altitude</th></tr></thead><tbody>';
            flownDetails.forEach(function(d) {
                html += '<tr>' +
                    '<td><code>' + (d.fix || '') + '</code></td>' +
                    '<td>' + (d.distance_nm != null ? d.distance_nm : 'N/A') + '</td>' +
                    '<td>' + (d.crossing_time || 'N/A') + '</td>' +
                    '<td>' + (d.altitude ? d.altitude.toLocaleString() + ' ft' : 'N/A') + '</td>' +
                    '</tr>';
            });
            html += '</tbody></table></div>';
        } else if (flight.has_trajectory === false) {
            html += '<div class="text-muted small mt-1"><i class="fas fa-exclamation-triangle"></i> No trajectory data available</div>';
        }

        html += '</div>';
        return html;
    },

    /**
     * Render a reroute compliance card (classic layout)
     */
    renderRerouteCard: function(r) {
        const detailId = `reroute_detail_${++this.detailIdCounter}`;
        const historyId = `reroute_history_${this.detailIdCounter}`;
        const routeTableId = `reroute_routes_${this.detailIdCounter}`;

        const action = r.action || (r.mandatory ? 'RQD' : 'FYI');
        const routeType = r.route_type || 'ROUTE';
        const actionBadgeClass = this.getActionBadgeClass(action);
        const compPct = r.compliance_pct || r.filed_compliance_pct || 0;
        const compClass = this.getComplianceClass(compPct);

        const filedCompliant = r.filed_compliant || [];
        const filedNonCompliant = r.filed_non_compliant || [];
        const flownCompliant = r.flown_compliant || [];
        const flownNonCompliant = r.flown_non_compliant || [];
        const allFlights = r.flights || [];
        const totalFlights = r.total_flights || allFlights.length;

        // Ended-by badge
        let endedBadge = '';
        if (r.ended_by === 'CNX') {
            endedBadge = '<span class="gs-ended-badge cnx">CNX</span>';
        } else if (r.ended_by === 'EXPIRATION') {
            endedBadge = '<span class="gs-ended-badge expired">EXPIRED</span>';
        }

        let html = `
            <div class="tmi-card reroute-card">
                <div class="tmi-header">
                    <div>
                        <span class="action-badge badge ${actionBadgeClass}">${routeType} ${action}</span>
                        <span class="tmi-fix-name ml-2">${r.name || 'Reroute'}</span>
                        <span class="text-muted ml-2">
                            ${r.start || ''} - ${r.end || ''}
                        </span>
                        ${endedBadge}
                    </div>
                    <div class="compliance-badge ${compClass}">${compPct.toFixed(1)}%</div>
                </div>
        `;

        // Subheader: constrained area, reason, assessment mode
        const subParts = [];
        if (r.constrained_area) subParts.push(this.escapeHtml(r.constrained_area));
        if (r.reason) subParts.push(this.escapeHtml(r.reason));
        if (r.assessment_mode) {
            const modeLabel = r.assessment_mode === 'full_compliance' ? 'Full route compliance' :
                r.assessment_mode === 'fix_only' ? 'Required fix check only' : r.assessment_mode;
            subParts.push(modeLabel);
        }
        if (subParts.length > 0) {
            html += `<div class="text-muted small mb-2">${subParts.join(' | ')}</div>`;
        }

        // Stats row with filed vs flown split
        html += `
                <div class="tmi-stats">
                    <div class="tmi-stat">
                        <div class="tmi-stat-value">${totalFlights}</div>
                        <div class="tmi-stat-label">Total Flights</div>
                    </div>
                    <div class="tmi-stat">
                        <div class="tmi-stat-value text-success">${filedCompliant.length}</div>
                        <div class="tmi-stat-label">Filed Compliant</div>
                    </div>
                    <div class="tmi-stat">
                        <div class="tmi-stat-value text-success">${flownCompliant.length}</div>
                        <div class="tmi-stat-label">Flown Compliant</div>
                    </div>
                    <div class="tmi-stat">
                        <div class="tmi-stat-value text-danger">${filedNonCompliant.length + flownNonCompliant.length}</div>
                        <div class="tmi-stat-label">Non-Compliant</div>
                    </div>
                </div>
        `;

        // Filed vs flown compliance split
        const filedPct = r.filed_compliance_pct || 0;
        const flownPct = r.flown_compliance_pct || 0;
        html += `
                <div class="compliance-split">
                    <div class="filed">
                        <div class="small text-muted">Filed</div>
                        <div class="compliance-badge ${this.getComplianceClass(filedPct)}" style="font-size:1rem;">${filedPct.toFixed(1)}%</div>
                    </div>
                    <div class="flown">
                        <div class="small text-muted">Flown</div>
                        <div class="compliance-badge ${this.getComplianceClass(flownPct)}" style="font-size:1rem;">${flownPct.toFixed(1)}%</div>
                        ${r.no_trajectory_count ? `<div class="small text-muted">(${r.no_trajectory_count} no trajectory)</div>` : ''}
                    </div>
                </div>
        `;

        // Program history (collapsible) - enriched with action, modifications, route info
        const history = r.program_history || [];
        if (history.length > 0) {
            html += `
                <div class="mt-2">
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-toggle="collapse" data-target="#${historyId}">
                        <i class="fas fa-history"></i> Program History (${history.length})
                    </button>
                </div>
                <div class="collapse mt-2 program-history" id="${historyId}">
                    ${history.map(h => `
                        <div class="history-item" style="flex-wrap:wrap;">
                            <span class="badge badge-secondary">ADVZY ${h.advzy || '?'}</span>
                            ${h.action ? `<span class="action-badge badge ${this.getActionBadgeClass(h.action)}">${h.action}</span>` : ''}
                            <span>${h.type || ''}</span>
                            ${h.start ? `<span class="text-muted">${h.start} - ${h.end || '?'}</span>` : ''}
                            <span class="text-muted small">Issued: ${h.issued || 'N/A'}</span>
                            ${h.replaces ? `<span class="text-muted small">(replaces ADVZY ${h.replaces})</span>` : ''}
                            ${h.modifications ? `<div class="w-100 advisory-meta small text-warning"><i class="fas fa-edit"></i> ${this.escapeHtml(h.modifications)}</div>` : ''}
                            ${h.routes && h.routes.length ? `<div class="w-100 advisory-meta small"><i class="fas fa-route"></i> ${h.routes.length} route(s)</div>` : ''}
                            ${h.comments ? `<div class="w-100 advisory-meta small"><i class="fas fa-comment"></i> ${this.escapeHtml(h.comments)}</div>` : ''}
                        </div>
                    `).join('')}
                </div>
            `;
        }

        // Required routes table (collapsible)
        const requiredRoutes = r.required_routes || [];
        if (requiredRoutes.length > 0) {
            html += `
                <div class="mt-2">
                    <button class="btn btn-sm btn-outline-info" type="button" data-toggle="collapse" data-target="#${routeTableId}">
                        <i class="fas fa-route"></i> Required Routes (${requiredRoutes.length})
                    </button>
                </div>
                <div class="collapse mt-2" id="${routeTableId}">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped route-table">
                            <thead class="thead-dark">
                                <tr><th>Origin</th><th>Dest</th><th>Route</th></tr>
                            </thead>
                            <tbody>
                                ${requiredRoutes.map(rt => {
                                    // Highlight required fixes in the route string
                                    let routeStr = rt.route || '';
                                    const reqFixes = r.required_fixes || [];
                                    reqFixes.forEach(fix => {
                                        routeStr = routeStr.replace(new RegExp(`\\b${fix}\\b`, 'g'),
                                            `<span class="required-segment">${fix}</span>`);
                                    });
                                    return `<tr>
                                        <td><code>${rt.orig || ''}</code></td>
                                        <td><code>${rt.dest || ''}</code></td>
                                        <td>${routeStr}</td>
                                    </tr>`;
                                }).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>
            `;
        }

        // Flight detail table (collapsible) - with expandable route match rows
        if (allFlights.length > 0) {
            const flightTblId = `reroute_flights_${this.detailIdCounter}`;
            const reqFixes = r.required_fixes || [];
            html += `
                <div class="mt-2 d-flex justify-content-end">
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-toggle="collapse" data-target="#${detailId}">
                        <i class="fas fa-plane"></i> Flight Details (${allFlights.length})
                    </button>
                </div>
                <div class="collapse mt-2" id="${detailId}">
                    <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                        <table class="table table-sm table-striped" id="${flightTblId}">
                            <thead class="thead-dark sticky-top">
                                <tr>
                                    <th style="width:20px;"></th>
                                    <th>Callsign</th>
                                    <th>Origin</th>
                                    <th>Dest</th>
                                    <th>Filed %</th>
                                    <th>Flown %</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${allFlights.map((f, idx) => {
                                    const fStatus = (f.final_status || f.filed_status || '').toUpperCase();
                                    const statusClass = fStatus === 'COMPLIANT' ? 'text-success' :
                                        fStatus === 'NON_COMPLIANT' ? 'text-danger' : 'text-muted';
                                    const detRowId = flightTblId + '_d' + idx;
                                    const hasDetail = (f.required_fixes && f.required_fixes.length > 0) ||
                                                      (f.filed_matched_fixes && f.filed_matched_fixes.length > 0) ||
                                                      reqFixes.length > 0;
                                    return `<tr class="${hasDetail ? 'reroute-flight-row expandable' : ''}"
                                                ${hasDetail ? 'onclick="TMICompliance.toggleFlightDetail(\'' + detRowId + '\')" style="cursor:pointer;"' : ''}>
                                        <td>${hasDetail ? '<i class="fas fa-chevron-down expand-chevron text-muted small"></i>' : ''}</td>
                                        <td><code>${f.callsign || ''}</code></td>
                                        <td>${f.dept || ''}</td>
                                        <td>${f.dest || ''}</td>
                                        <td>${f.filed_match_pct != null ? f.filed_match_pct + '%' : 'N/A'}</td>
                                        <td>${f.flown_match_pct != null ? f.flown_match_pct + '%' : (f.has_trajectory === false ? 'No traj' : 'N/A')}</td>
                                        <td class="${statusClass}">${(fStatus || '').replace('_', ' ')}</td>
                                    </tr>
                                    ${hasDetail ? '<tr id="' + detRowId + '" style="display:none;" class="flight-detail-row"><td colspan="7">' + this.renderFlightRouteDetail(f, reqFixes) + '</td></tr>' : ''}`;
                                }).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>
            `;
        }

        // Exemption text
        if (r.exemptions) {
            html += `<div class="exemption-box mt-2"><i class="fas fa-shield-alt text-info"></i> ${this.escapeHtml(r.exemptions)}</div>`;
        }

        // Associated restrictions
        if (r.associated_restrictions) {
            html += `<div class="text-muted small mt-1"><i class="fas fa-link"></i> ${this.escapeHtml(r.associated_restrictions)}</div>`;
        }

        html += '</div>';
        return html;
    },

    /**
     * Render reroute detail content (V2 progressive layout)
     */
    renderRerouteDetailV2: function(data) {
        const action = data.action || (data.mandatory ? 'RQD' : 'FYI');
        const routeType = data.route_type || 'ROUTE';
        const actionBadgeClass = this.getActionBadgeClass(action);
        const allFlights = data.flights || [];
        const totalFlights = data.total_flights || allFlights.length;
        const filedCompliant = data.filed_compliant || [];
        const filedNonCompliant = data.filed_non_compliant || [];
        const flownCompliant = data.flown_compliant || [];
        const flownNonCompliant = data.flown_non_compliant || [];
        const filedPct = data.filed_compliance_pct || 0;
        const flownPct = data.flown_compliance_pct || 0;

        // Ended-by badge
        let endedBadge = '';
        if (data.ended_by === 'CNX') {
            endedBadge = '<span class="gs-ended-badge cnx">CNX</span>';
        } else if (data.ended_by === 'EXPIRATION') {
            endedBadge = '<span class="gs-ended-badge expired">EXPIRED</span>';
        }

        let html = `
            <div class="mb-2">
                <span class="action-badge badge ${actionBadgeClass}">${routeType} ${action}</span>
                ${endedBadge}
            </div>
            <div class="tmi-detail-overview">
                <div class="stat">
                    <div class="stat-value">${data.start || '?'} - ${data.end || '?'}</div>
                    <div class="stat-label">Active Window</div>
                </div>
                <div class="stat">
                    <div class="stat-value">${totalFlights}</div>
                    <div class="stat-label">Flights Tracked</div>
                </div>
                <div class="stat">
                    <div class="stat-value">${filedCompliant.length}</div>
                    <div class="stat-label">Filed Compliant</div>
                </div>
                <div class="stat">
                    <div class="stat-value">${flownCompliant.length}</div>
                    <div class="stat-label">Flown Compliant</div>
                </div>
                <div class="stat">
                    <div class="stat-value">${filedNonCompliant.length + flownNonCompliant.length}</div>
                    <div class="stat-label">Non-Compliant</div>
                </div>
            </div>
        `;

        // Subheader info
        const subParts = [];
        if (data.constrained_area) subParts.push(this.escapeHtml(data.constrained_area));
        if (data.reason) subParts.push(this.escapeHtml(data.reason));
        if (subParts.length > 0) {
            html += `<div class="text-muted small mt-2">${subParts.join(' | ')}</div>`;
        }

        // Filed vs flown compliance split
        html += `
            <div class="compliance-split mt-2">
                <div class="filed">
                    <div class="small text-muted">Filed Compliance</div>
                    <div class="compliance-badge ${this.getComplianceClass(filedPct)}" style="font-size:1rem;">${filedPct.toFixed(1)}%</div>
                </div>
                <div class="flown">
                    <div class="small text-muted">Flown Compliance</div>
                    <div class="compliance-badge ${this.getComplianceClass(flownPct)}" style="font-size:1rem;">${flownPct.toFixed(1)}%</div>
                    ${data.no_trajectory_count ? `<div class="small text-muted">(${data.no_trajectory_count} no trajectory)</div>` : ''}
                </div>
            </div>
        `;

        // Program history (expandable section) - enriched with action, modifications, routes
        const history = data.program_history || [];
        if (history.length > 0) {
            html += this.renderExpandableSectionV2('reroute-history', 'Program History', `(${history.length})`, () => {
                return `<div class="program-history">${history.map(h => `
                    <div class="history-item" style="flex-wrap:wrap;">
                        <span class="badge badge-secondary">ADVZY ${h.advzy || '?'}</span>
                        ${h.action ? `<span class="action-badge badge ${this.getActionBadgeClass(h.action)}">${h.action}</span>` : ''}
                        <span>${h.type || ''}</span>
                        ${h.start ? `<span class="text-muted">${h.start} - ${h.end || '?'}</span>` : ''}
                        <span class="text-muted small">Issued: ${h.issued || 'N/A'}</span>
                        ${h.replaces ? `<span class="text-muted small">(replaces ADVZY ${h.replaces})</span>` : ''}
                        ${h.modifications ? `<div class="w-100 advisory-meta small text-warning"><i class="fas fa-edit"></i> ${this.escapeHtml(h.modifications)}</div>` : ''}
                        ${h.routes && h.routes.length ? `<div class="w-100 advisory-meta small"><i class="fas fa-route"></i> ${h.routes.length} route(s)</div>` : ''}
                        ${h.comments ? `<div class="w-100 advisory-meta small"><i class="fas fa-comment"></i> ${this.escapeHtml(h.comments)}</div>` : ''}
                    </div>
                `).join('')}</div>`;
            });
        }

        // Required routes (expandable section)
        const requiredRoutes = data.required_routes || [];
        if (requiredRoutes.length > 0) {
            html += this.renderExpandableSectionV2('reroute-routes', 'Required Routes', `(${requiredRoutes.length})`, () => {
                let tbl = `<div class="table-responsive"><table class="table table-sm table-striped route-table">
                    <thead><tr><th>Origin</th><th>Dest</th><th>Route</th></tr></thead><tbody>`;
                requiredRoutes.forEach(rt => {
                    let routeStr = rt.route || '';
                    const reqFixes = data.required_fixes || [];
                    reqFixes.forEach(fix => {
                        routeStr = routeStr.replace(new RegExp(`\\b${fix}\\b`, 'g'),
                            `<span class="required-segment">${fix}</span>`);
                    });
                    tbl += `<tr>
                        <td><code>${rt.orig || ''}</code></td>
                        <td><code>${rt.dest || ''}</code></td>
                        <td>${routeStr}</td>
                    </tr>`;
                });
                tbl += `</tbody></table></div>`;
                return tbl;
            });
        }

        // Flight details (expandable section) - with expandable route match rows
        if (allFlights.length > 0) {
            const v2FlightTblId = `reroute_v2_flights_${++this.detailIdCounter}`;
            const reqFixesV2 = data.required_fixes || [];
            html += this.renderExpandableSectionV2('reroute-flights', 'Flight Details', `(${allFlights.length})`, () => {
                let tbl = `<div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                    <table class="table table-sm table-striped" id="${v2FlightTblId}">
                        <thead class="thead-dark sticky-top">
                            <tr><th style="width:20px;"></th><th>Callsign</th><th>Origin</th><th>Dest</th><th>Filed %</th><th>Flown %</th><th>Status</th></tr>
                        </thead><tbody>`;
                allFlights.forEach((f, idx) => {
                    const fStatus = (f.final_status || f.filed_status || '').toUpperCase();
                    const statusClass = fStatus === 'COMPLIANT' ? 'text-success' :
                        fStatus === 'NON_COMPLIANT' ? 'text-danger' : 'text-muted';
                    const detRowId = v2FlightTblId + '_d' + idx;
                    const hasDetail = (f.required_fixes && f.required_fixes.length > 0) ||
                                      (f.filed_matched_fixes && f.filed_matched_fixes.length > 0) ||
                                      reqFixesV2.length > 0;
                    tbl += `<tr class="${hasDetail ? 'reroute-flight-row expandable' : ''}"
                                ${hasDetail ? 'onclick="TMICompliance.toggleFlightDetail(\'' + detRowId + '\')" style="cursor:pointer;"' : ''}>
                        <td>${hasDetail ? '<i class="fas fa-chevron-down expand-chevron text-muted small"></i>' : ''}</td>
                        <td><code>${f.callsign || ''}</code></td>
                        <td>${f.dept || ''}</td>
                        <td>${f.dest || ''}</td>
                        <td>${f.filed_match_pct != null ? f.filed_match_pct + '%' : 'N/A'}</td>
                        <td>${f.flown_match_pct != null ? f.flown_match_pct + '%' : (f.has_trajectory === false ? 'No traj' : 'N/A')}</td>
                        <td class="${statusClass}">${(fStatus || '').replace('_', ' ')}</td>
                    </tr>`;
                    if (hasDetail) {
                        tbl += '<tr id="' + detRowId + '" style="display:none;" class="flight-detail-row"><td colspan="7">' + this.renderFlightRouteDetail(f, reqFixesV2) + '</td></tr>';
                    }
                });
                tbl += `</tbody></table></div>`;
                return tbl;
            });
        }

        // Exemption text
        if (data.exemptions) {
            html += `<div class="exemption-box mt-2"><i class="fas fa-shield-alt text-info"></i> ${this.escapeHtml(data.exemptions)}</div>`;
        }

        // Associated restrictions
        if (data.associated_restrictions) {
            html += `<div class="text-muted small mt-1"><i class="fas fa-link"></i> ${this.escapeHtml(data.associated_restrictions)}</div>`;
        }

        return html;
    },

    renderApreqCard: function(r) {
        const detailId = `apreq_detail_${++this.detailIdCounter}`;
        const exemptFlights = r.exempt_flights || [];
        const affectedFlights = r.affected_flights || [];
        const postTmiFlights = r.post_tmi_flights || [];
        const exemptCount = r.exempt_count || exemptFlights.length;
        const affectedCount = r.affected_count || affectedFlights.length;
        const postTmiCount = r.post_tmi_count || postTmiFlights.length;

        // Build standardized notation
        const origins = (r.origins || []).join(',');
        const dests = (r.destinations || []).join(',');
        const isRealFix = r.fix && !['ALL', 'ANY', ''].includes((r.fix || '').toUpperCase());
        const viaPart = isRealFix ? `via ${r.fix} ` : '';
        const notation = `${dests} ${viaPart}CFR ${r.requestor || ''}:${r.provider || ''} ${r.tmi_start || ''}-${r.tmi_end || ''}`;
        const displayName = isRealFix ? r.fix : dests;

        // Check for data gap overlap
        const gapBadge = this.renderTMIGapBadge(r.tmi_start, r.tmi_end);

        let html = `
            <div class="tmi-card apreq-card${gapBadge ? ' has-data-gap' : ''}">
                <div class="tmi-header">
                    <div>
                        <span class="tmi-fix-name">APREQ/CFR: ${displayName}</span>
                        <span class="text-muted ml-2">
                            ${isRealFix ? dests + ' | ' : ''}${r.tmi_start || ''} - ${r.tmi_end || ''}
                        </span>
                        ${r.cancelled ? '<span class="badge badge-warning ml-2">CANCELLED</span>' : ''}
                        ${gapBadge}
                    </div>
                    <span class="badge badge-secondary">TRACKING ONLY</span>
                </div>
                <!-- Standardized TMI Notation -->
                <div class="standardized-tmi mb-2">
                    <code class="small">${notation}</code>
                </div>
                <div class="tmi-stats">
                    <div class="tmi-stat">
                        <div class="tmi-stat-value">${r.total_flights || 0}</div>
                        <div class="tmi-stat-label">Total Flights</div>
                    </div>
                    <div class="tmi-stat">
                        <div class="tmi-stat-value text-info">${exemptCount}</div>
                        <div class="tmi-stat-label">Exempt</div>
                    </div>
                    <div class="tmi-stat">
                        <div class="tmi-stat-value text-warning">${affectedCount}</div>
                        <div class="tmi-stat-label">Need Release</div>
                    </div>
                    <div class="tmi-stat">
                        <div class="tmi-stat-value text-muted">${postTmiCount}</div>
                        <div class="tmi-stat-label">Post TMI</div>
                    </div>
                </div>
                <div class="alert alert-info mb-0 small mt-2">
                    <i class="fas fa-info-circle"></i>
                    ${r.note || 'APREQ/CFR requires coordination verification - these flights would need release'}
                </div>
        `;

        // Show flight details
        const hasDetails = exemptFlights.length > 0 || affectedFlights.length > 0 || postTmiFlights.length > 0;

        if (hasDetails) {
            html += `
                <div class="mt-2 d-flex justify-content-end">
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-toggle="collapse" data-target="#${detailId}">
                        <i class="fas fa-plane"></i> Flight Details
                    </button>
                </div>
                <div class="collapse mt-2" id="${detailId}">
                    <div class="row">
            `;

            // Affected flights (need coordination)
            if (affectedFlights.length > 0) {
                html += `
                    <div class="col-md-4">
                        <h6 class="text-warning"><i class="fas fa-phone"></i> Need Release (${affectedFlights.length})</h6>
                        <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                            <table class="table table-sm table-striped">
                                <thead class="thead-dark sticky-top">
                                    <tr><th>Callsign</th><th>Origin</th><th>Dest</th><th>Dept Time</th></tr>
                                </thead>
                                <tbody>
                                    ${affectedFlights.map(f => `
                                        <tr class="table-warning">
                                            <td><code>${f.callsign}</code></td>
                                            <td>${f.dept || 'N/A'}</td>
                                            <td>${f.dest || 'N/A'}</td>
                                            <td>${f.first_seen || 'N/A'}</td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                    </div>
                `;
            }

            // Exempt flights
            if (exemptFlights.length > 0) {
                html += `
                    <div class="col-md-4">
                        <h6 class="text-info"><i class="fas fa-check-circle"></i> Exempt (${exemptFlights.length})</h6>
                        <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                            <table class="table table-sm table-striped">
                                <thead class="thead-light sticky-top">
                                    <tr><th>Callsign</th><th>Origin</th><th>Dept Time</th></tr>
                                </thead>
                                <tbody>
                                    ${exemptFlights.map(f => `
                                        <tr>
                                            <td><code>${f.callsign}</code></td>
                                            <td>${f.dept || 'N/A'}</td>
                                            <td>${f.first_seen || 'N/A'}</td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                    </div>
                `;
            }

            // Post-TMI flights
            if (postTmiFlights.length > 0) {
                html += `
                    <div class="col-md-4">
                        <h6 class="text-muted"><i class="fas fa-hourglass-end"></i> Post TMI (${postTmiFlights.length})</h6>
                        <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                            <table class="table table-sm table-striped">
                                <thead class="thead-light sticky-top">
                                    <tr><th>Callsign</th><th>Origin</th><th>Dept Time</th></tr>
                                </thead>
                                <tbody>
                                    ${postTmiFlights.map(f => `
                                        <tr>
                                            <td><code>${f.callsign}</code></td>
                                            <td>${f.dept || 'N/A'}</td>
                                            <td>${f.first_seen || 'N/A'}</td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                    </div>
                `;
            }

            html += `
                    </div>
                </div>
            `;
        }

        // Add trajectory counts info
        // Stream flights = total, Analyzed = exempt + affected + post_tmi (those with trajectory data)
        const streamFlights = r.total_flights || 0;
        const analyzedFlights = exemptCount + affectedCount + postTmiCount;
        html += this.renderTrajectoryCounts(r.tmi_start, r.tmi_end, streamFlights, analyzedFlights);

        html += '</div>';
        return html;
    },

    renderDelaySection: function(delays) {
        if (!delays || delays.length === 0) {return '';}

        // Group delays by airport
        const byAirport = {};
        delays.forEach(d => {
            const apt = d.airport || 'UNKNOWN';
            if (!byAirport[apt]) {byAirport[apt] = [];}
            byAirport[apt].push(d);
        });

        let html = '<h6 class="text-warning mb-3 mt-4" style="color:#856404 !important;"><i class="fas fa-clock"></i> Delay Tracking</h6>';

        // Summary stats
        const totalDelay = delays.reduce((sum, d) => sum + (d.delay_minutes || 0), 0);
        const holdingEntries = delays.filter(d => d.holding_status === 'HOLDING');
        const airports = Object.keys(byAirport);

        html += `
            <div class="tmi-card delay-card mb-3">
                <div class="tmi-header">
                    <div>
                        <span class="tmi-fix-name">Delay Overview</span>
                        <span class="text-muted ml-2">| ${delays.length} entries across ${airports.length} airport(s)</span>
                    </div>
                </div>
                <div class="tmi-stats">
                    <div class="tmi-stat">
                        <div class="tmi-stat-value">${delays.length}</div>
                        <div class="tmi-stat-label">Total Updates</div>
                    </div>
                    <div class="tmi-stat">
                        <div class="tmi-stat-value text-warning">${holdingEntries.length}</div>
                        <div class="tmi-stat-label">Holding Events</div>
                    </div>
                    <div class="tmi-stat">
                        <div class="tmi-stat-value">${airports.length}</div>
                        <div class="tmi-stat-label">Airports</div>
                    </div>
                </div>
            </div>
        `;

        // Render each airport's delay timeline
        for (const [airport, aptDelays] of Object.entries(byAirport)) {
            html += this.renderAirportDelays(airport, aptDelays);
        }

        return html;
    },

    renderAirportDelays: function(airport, delays) {
        // Separate by delay type
        const departures = delays.filter(d => d.delay_type === 'D/D' || d.delay_type === 'DEPARTURE');
        const enroute = delays.filter(d => d.delay_type === 'E/D' || d.delay_type === 'ENROUTE');
        const arrivals = delays.filter(d => d.delay_type === 'A/D' || d.delay_type === 'ARRIVAL');

        let html = `
            <div class="tmi-card delay-airport-card mb-2">
                <div class="tmi-header">
                    <div>
                        <span class="tmi-fix-name">${airport}</span>
                        <span class="text-muted ml-2">| ${delays.length} delay update(s)</span>
                    </div>
                </div>
                <div class="delay-timeline">
        `;

        // Departure delays (D/D)
        if (departures.length > 0) {
            html += `<div class="delay-type-section mb-2">
                <div class="small text-muted mb-1"><i class="fas fa-plane-departure"></i> Departure Delays (D/D)</div>
                <div class="delay-entries">`;
            for (const d of departures) {
                html += this.renderDelayEntry(d);
            }
            html += `</div></div>`;
        }

        // En-route delays (E/D)
        if (enroute.length > 0) {
            html += `<div class="delay-type-section mb-2">
                <div class="small text-muted mb-1"><i class="fas fa-plane"></i> En-Route Delays (E/D)</div>
                <div class="delay-entries">`;
            for (const d of enroute) {
                html += this.renderDelayEntry(d);
            }
            html += `</div></div>`;
        }

        // Arrival delays (A/D)
        if (arrivals.length > 0) {
            html += `<div class="delay-type-section mb-2">
                <div class="small text-muted mb-1"><i class="fas fa-plane-arrival"></i> Arrival Delays (A/D)</div>
                <div class="delay-entries">`;
            for (const d of arrivals) {
                html += this.renderDelayEntry(d);
            }
            html += `</div></div>`;
        }

        html += `</div></div>`;
        return html;
    },

    renderDelayEntry: function(d) {
        const trendIcon = d.delay_trend === 'INCREASING' ? '<i class="fas fa-arrow-up text-danger"></i>'
            : d.delay_trend === 'DECREASING' ? '<i class="fas fa-arrow-down text-success"></i>'
                : d.delay_trend === 'STEADY' ? '<i class="fas fa-minus text-muted"></i>'
                    : '';

        const holdingBadge = d.holding_status === 'HOLDING'
            ? `<span class="badge badge-warning ml-1"><i class="fas fa-sync"></i> Holding${d.holding_fix ? ' @ ' + d.holding_fix : ''}${d.aircraft_holding ? ' (' + d.aircraft_holding + ' ACFT)' : ''}</span>`
            : '';

        const delayDisplay = d.delay_minutes > 0 ? `+${d.delay_minutes}min` : (d.holding_status === 'HOLDING' ? 'Holding' : '-');

        return `
            <div class="delay-entry d-flex align-items-center py-1 border-bottom" style="font-size: 0.85rem;">
                <span class="text-monospace mr-2" style="min-width:50px;">${d.timestamp || '??:??'}</span>
                <span class="mr-2" style="min-width:60px;">${trendIcon} <strong>${delayDisplay}</strong></span>
                ${holdingBadge}
                <span class="text-muted ml-auto small">${d.reason || ''}</span>
            </div>
        `;
    },

    renderDistribution: function(dist) {
        if (!dist) {return '';}

        return `
            <div class="tmi-distribution">
                <div class="dist-item dist-under" title="Under (<95%) - Violations">
                    <i class="fas fa-exclamation-triangle"></i> ${dist.under || 0}
                </div>
                <div class="dist-item dist-within" title="Within (95-110%) - Ideal">
                    <i class="fas fa-check"></i> ${dist.within || 0}
                </div>
                <div class="dist-item dist-over" title="Over (110-200%) - Acceptable">
                    <i class="fas fa-arrow-up"></i> ${dist.over || 0}
                </div>
                <div class="dist-item dist-gap" title="Gap (>200%) - Excessive">
                    <i class="fas fa-arrows-alt-h"></i> ${dist.gap || 0}
                </div>
            </div>
        `;
    },

    getComplianceClass: function(pct) {
        if (pct >= 90) {return 'good';}
        if (pct >= 75) {return 'warn';}
        return 'bad';
    },

    // =========================================================================
    // TMI CONTEXT MAP RENDERING
    // =========================================================================

    // Cache for map data (keyed by requestor_provider_fix)
    mapDataCache: {},

    // Cache for flight trajectories (keyed by mapId)
    trajectoryCache: {},

    // Cache for TMI metadata (required spacing, unit) keyed by mapId
    tmiMetadataCache: {},

    // Track active maps for cleanup
    activeMaps: {},

    // Cache for flow stream analysis from PostGIS API (keyed by mapId)
    flowStreamCache: {},

    // Cache for TMI-filtered trajectories (only flights matching the TMI's semantic flow)
    // Full trajectories stay in trajectoryCache for track rendering; this holds the subset
    // used by flow streams, branches, and cone enhancement.
    flowTrajectoryCache: {},

    // Cache for airway geometry (keyed by airway name)
    airwayCache: {},

    // Cache for branch corridor data (keyed by mapId)
    branchCorridorCache: {},

    // Raw trajectory data keyed by MIT key (loaded from separate endpoint)
    _rawTrajectories: null,
    _trajectoryPromise: null,

    // Track active branch panel states (keyed by mapId)
    branchPanelState: {},

    /**
     * Check if a string looks like an airway identifier
     * Supports global formats: J###, V###, Q###, T###, Y###, A###,
     * UL###, L/M/N###, AR###, G###, B###, W###, R###
     *
     * @param {string} name - Fix/route identifier to check
     * @returns {boolean} - True if it matches an airway pattern
     */
    isAirway: function(name) {
        if (!name) return false;
        const upper = String(name).toUpperCase().trim();
        // Global airway patterns:
        // J### - Jet routes (US)
        // V### - Victor routes (US)
        // Q### - RNAV high altitude (US/Global)
        // T### - RNAV low altitude (US)
        // Y### - RNAV routes (Global)
        // A### - Oceanic routes
        // UL/UA/UB/UM/UN### - Upper European/International
        // L/M/N### - European
        // AR### - Area Navigation
        // G### - GNSS routes
        // B### - Control area routes
        // W### - Low level routes
        // R### - RNAV routes (regional)
        return /^(J|V|Q|T|Y|A|UL|UA|UB|UM|UN|L|M|N|AR|G|B|W|R)\d+$/.test(upper);
    },

    /**
     * Fetch airway geometry from API
     *
     * @param {string} airwayName - Airway identifier (e.g., Y290, J48)
     * @returns {Promise<Object|null>} - Airway data with geojson or null
     */
    fetchAirwayGeometry: async function(airwayName) {
        if (!airwayName || !this.isAirway(airwayName)) {
            return null;
        }

        const upper = airwayName.toUpperCase().trim();

        // Check cache
        if (this.airwayCache[upper]) {
            console.log(`Airway cache hit: ${upper}`);
            return this.airwayCache[upper];
        }

        try {
            const response = await fetch(`api/adl/airway.php?airway=${encodeURIComponent(upper)}`);
            if (!response.ok) {
                console.warn(`Airway API error: ${response.status}`);
                return null;
            }

            const data = await response.json();
            if (data.success && data.airways?.[upper]?.found) {
                const airway = data.airways[upper];
                this.airwayCache[upper] = airway;
                console.log(`Fetched airway ${upper}: ${airway.segment_count} segments, ${airway.total_distance_nm}nm`);
                return airway;
            } else {
                console.warn(`Airway ${upper} not found in database`);
                // Cache negative result to avoid repeated lookups
                this.airwayCache[upper] = { name: upper, found: false };
                return null;
            }
        } catch (err) {
            console.warn(`Airway fetch failed for ${upper}:`, err);
            return null;
        }
    },

    /**
     * Filter trajectories to only include flights matching the TMI's semantic flow.
     * For arrival TMIs: keep only flights whose dest matches any TMI destination.
     * For departure TMIs: keep only flights whose dept matches the controlled element.
     *
     * @param {Object} trajectories - Full trajectory data {callsign: {coordinates, properties}}
     * @param {Object} mitResult - TMI result with destinations, origins, direction, etc.
     * @returns {Object} {filtered, stats} where filtered is the trajectory subset
     */
    filterTrajectoriesToTMIFlow: function(trajectories, mitResult) {
        if (!trajectories) return { filtered: {}, stats: { total: 0, matched: 0, reason: 'no trajectories' } };

        const total = Object.keys(trajectories).length;
        const direction = mitResult.direction || 'arrival';
        const destinations = (mitResult.destinations || []).map(d => String(d).toUpperCase().trim());
        const origins = (mitResult.origins || []).map(o => String(o).toUpperCase().trim());
        // Also check ctl_element (the airport for GDPs/ground stops/APREQs)
        const ctlElement = (mitResult.destination || mitResult.ctl_element || '').toUpperCase().trim();

        // Build the set of airport codes to match against, including ICAO/FAA variants
        const buildMatchSet = (codes) => {
            const set = new Set();
            codes.forEach(code => {
                if (!code) return;
                set.add(code);
                // ICAO K-prefix variant: KSJC ↔ SJC
                if (code.length === 4 && code.startsWith('K')) set.add(code.substring(1));
                if (code.length === 3) set.add('K' + code);
            });
            return set;
        };

        let matchField, matchSet;
        if (direction === 'departure') {
            matchField = 'dept';
            matchSet = buildMatchSet([...origins, ctlElement].filter(Boolean));
        } else {
            // arrival or overflight — match by destination
            matchField = 'dest';
            matchSet = buildMatchSet([...destinations, ctlElement].filter(Boolean));
        }

        // If no TMI airports to match against, return everything (can't filter)
        if (matchSet.size === 0) {
            return { filtered: trajectories, stats: { total, matched: total, reason: 'no filter airports' } };
        }

        const filtered = {};
        let matched = 0;
        Object.entries(trajectories).forEach(([callsign, traj]) => {
            const aptCode = (traj.properties?.[matchField] || '').toUpperCase().trim();
            if (matchSet.has(aptCode)) {
                filtered[callsign] = traj;
                matched++;
            }
        });

        console.log(`TMI flow filter (${direction}): ${matched}/${total} flights match ${matchField} ∈ {${[...matchSet].join(', ')}}`);
        return { filtered, stats: { total, matched, matchField, matchSet: [...matchSet], reason: 'tmi_semantic' } };
    },

    /**
     * Fetch flow stream analysis from the PostGIS track density API
     * Uses DBSCAN clustering to identify distinct traffic streams and merge zones
     *
     * @param {string} mapId - Map identifier for caching
     * @param {Object} trajectories - Trajectory data from the analysis API
     * @param {Object} options - Analysis options
     * @param {Array} options.fixPoint - [lon, lat] of the measurement fix
     * @param {Array} options.knownFixes - Array of known fixes [{id, lat, lon}]
     * @param {boolean} options.isArrival - True for arrivals (converging), false for departures
     */
    fetchFlowStreams: async function(mapId, trajectories, options = {}) {
        if (!trajectories || Object.keys(trajectories).length < 3) {
            console.log(`Skipping flow stream analysis for ${mapId}: insufficient trajectories`);
            return null;
        }

        // Check cache first
        if (this.flowStreamCache[mapId]) {
            return this.flowStreamCache[mapId];
        }

        // Convert trajectory data to API format
        const trajArray = Object.entries(trajectories).map(([callsign, traj]) => ({
            callsign,
            coordinates: traj.coordinates || [],
            properties: traj.properties || {}
        })).filter(t => t.coordinates.length >= 2);

        if (trajArray.length < 3) {
            console.log(`Skipping flow stream analysis for ${mapId}: only ${trajArray.length} valid trajectories`);
            return null;
        }

        try {
            const response = await fetch('api/gis/track_density.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'flow_streams',
                    trajectories: trajArray,
                    fix_point: options.fixPoint || null,
                    known_fixes: options.knownFixes || [],
                    is_arrival: options.isArrival !== false,
                    cluster_eps_nm: 3,
                    cluster_min_points: 3,
                    distance_band_nm: 15
                })
            });

            if (!response.ok) {
                console.warn(`Flow stream API error: ${response.status}`);
                return null;
            }

            const data = await response.json();
            if (data.error) {
                console.warn(`Flow stream API error: ${data.error}`);
                return null;
            }

            // Cache the result
            this.flowStreamCache[mapId] = data;
            console.log(`Flow streams for ${mapId}: ${data.streams?.length || 0} streams, ${data.merge_zones?.length || 0} merge zones`);

            // If map already exists (user opened it before API returned), add layers now
            if (this.activeMaps[mapId] && data.streams?.length > 0) {
                console.log(`Map ${mapId} already open - adding flow stream layers dynamically`);
                this.addFlowStreamLayers(mapId, data);
            }

            // Update the flow analysis section
            this.renderFlowAnalysis(mapId);

            return data;
        } catch (err) {
            console.warn(`Flow stream fetch failed for ${mapId}:`, err);
            return null;
        }
    },

    /**
     * Fetch branch corridor analysis from GIS API (moved from PHP server-side).
     * Fires alongside fetchFlowStreams when trajectory data is available.
     * Computes per-branch compliance metrics client-side from pair data.
     */
    fetchBranchAnalysis: async function(mapId, trajectories, mitResult) {
        if (!trajectories || Object.keys(trajectories).length < 3) return null;
        if (this.branchCorridorCache[mapId]) return this.branchCorridorCache[mapId];

        // Resolve fix coordinates: try fix_info, then traffic_sector.measurement_point
        let fixLat, fixLon;
        const fixInfo = mitResult.fix_info;
        if (fixInfo?.lat && fixInfo?.lon) {
            fixLat = parseFloat(fixInfo.lat);
            fixLon = parseFloat(fixInfo.lon);
        } else {
            // Fallback: traffic_sector.measurement_point is [lon, lat]
            const mp = mitResult.traffic_sector?.measurement_point || mitResult.traffic_sector?.fix_point;
            if (mp) {
                fixLon = parseFloat(mp[0]);
                fixLat = parseFloat(mp[1]);
            }
        }
        if (!fixLat || !fixLon) return null;

        // Haversine distance in nm (reused from computeBranchCorridor)
        const distNm = (lon1, lat1, lon2, lat2) => {
            const R = 3440.065;
            const dLat = (lat2 - lat1) * Math.PI / 180;
            const dLon = (lon2 - lon1) * Math.PI / 180;
            const a = Math.sin(dLat / 2) ** 2 +
                Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) * Math.sin(dLon / 2) ** 2;
            return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        };

        // Filter to upstream-only: for arrivals, keep only points BEFORE closest
        // approach to fix (where flight is approaching, not departing the fix).
        const flightMeta = {};
        const trajArray = Object.entries(trajectories).map(([cs, traj]) => {
            const coords = traj.coordinates || [];

            // Find index of closest approach to fix
            let minDist = Infinity, minIdx = 0;
            for (let i = 0; i < coords.length; i++) {
                const d = distNm(coords[i][0], coords[i][1], fixLon, fixLat);
                if (d < minDist) { minDist = d; minIdx = i; }
            }

            // Keep only points up to and including the closest approach (upstream)
            const upstream = coords.slice(0, minIdx + 1).map(c => [c[0], c[1]]);

            // Build flight_meta for proper branch naming
            if (traj.properties) {
                flightMeta[cs] = {
                    dept: traj.properties.dept || '',
                    dest: traj.properties.dest || '',
                };
            }

            return { callsign: cs, coordinates: upstream };
        }).filter(t => t.coordinates.length >= 2);

        if (trajArray.length < 3) return null;

        try {
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 30000); // 30s timeout
            const response = await fetch('api/gis/track_density.php?action=branch_analysis', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                signal: controller.signal,
                body: JSON.stringify({
                    trajectories: trajArray,
                    fix_point: [fixLon, fixLat],
                    branch_min_distance_nm: 5,           // Inner bound for clustering (NOT MIT distance)
                    max_distance_nm: 250,
                    tmi_type: 'arrival',
                    flight_meta: flightMeta,
                    cluster_eps_nm: 6,                   // Phase 1: wider eps for corridor detection
                    cluster_min_points: 3,
                    bearing_filter_deg: 90,              // Reject opposite-direction traffic
                    sub_branch_eps_nm: 3,                // Phase 2: tighter eps for sub-branches
                })
            });
            clearTimeout(timeoutId);

            if (!response.ok) {
                console.warn(`Branch analysis API returned ${response.status} for ${mapId}`);
                this.branchCorridorCache[mapId] = { branches: [], error: true, branch_count: 0 };
                this.renderFlowAnalysis(mapId);
                return null;
            }
            const result = await response.json();
            if (!result.success || !result.data?.branches?.length) return null;

            const gisData = result.data;

            // Compute per-branch compliance metrics from pair data
            const allPairs = this.pairCache?.[mapId] || mitResult.all_pairs || [];
            const assignments = gisData.flight_assignments || {};
            const branchMetrics = this._computeBranchMetrics(allPairs, assignments);

            const corridors = {
                branches: gisData.branches,
                flight_assignments: assignments,
                branch_metrics: branchMetrics,
                total_flights: gisData.total_flights || trajArray.length,
                branch_count: gisData.branch_count || gisData.branches.length,
                ungrouped_flights: gisData.ungrouped_flights || 0,
                bearing_filter: gisData.bearing_filter || null,
                corridors_phase1: gisData.corridors_phase1 || 0,
            };

            this.branchCorridorCache[mapId] = corridors;
            const bf = gisData.bearing_filter;
            const filterInfo = bf ? ` (bearing filter: ${bf.accepted} accepted, ${bf.rejected} rejected, median ${bf.median_bearing}°)` : '';
            console.log(`Branch analysis for ${mapId}: ${gisData.corridors_phase1 || '?'} corridors → ${corridors.branch_count} branches${filterInfo}`);

            // Unhide the branch button if map controls already rendered
            const controls = document.getElementById(`${mapId}_controls`);
            if (controls) {
                const branchBtn = controls.querySelector('.branch-toggle-btn');
                if (branchBtn) branchBtn.style.display = '';
            }

            // Auto-initialize branch corridors if map is already active
            if (this.activeMaps[mapId]) {
                this.initBranchAnalysis(mapId);
            }

            // Update the flow analysis section
            this.renderFlowAnalysis(mapId);

            return corridors;
        } catch (err) {
            const isTimeout = err.name === 'AbortError';
            console.warn(`Branch analysis ${isTimeout ? 'timed out' : 'failed'} for ${mapId}:`, err);
            this.branchCorridorCache[mapId] = { branches: [], error: true, timeout: isTimeout, branch_count: 0 };
            this.renderFlowAnalysis(mapId);
            return null;
        }
    },

    /**
     * Compute per-branch compliance metrics from pair data (moved from PHP).
     * Filters pairs where both flights belong to the same branch.
     */
    _computeBranchMetrics: function(allPairs, flightAssignments) {
        if (!allPairs?.length || !flightAssignments) return {};

        const branchPairs = {}; // branch_id -> [pairs]
        for (const pair of allPairs) {
            const lead = pair.prev_callsign || pair.lead_callsign || '';
            const trail = pair.curr_callsign || pair.trail_callsign || '';
            const leadBranch = flightAssignments[lead];
            const trailBranch = flightAssignments[trail];

            if (leadBranch && leadBranch === trailBranch) {
                if (!branchPairs[leadBranch]) branchPairs[leadBranch] = [];
                branchPairs[leadBranch].push(pair);
            }
        }

        const metrics = {};
        for (const [branchId, pairs] of Object.entries(branchPairs)) {
            let compliant = 0;
            const spacings = [];
            const violations = [];

            for (const pair of pairs) {
                const spacing = parseFloat(pair.spacing || 0);
                spacings.push(spacing);
                if ((pair.compliance || '').toUpperCase() === 'COMPLIANT') {
                    compliant++;
                } else {
                    violations.push({
                        lead: pair.prev_callsign || pair.lead_callsign || '',
                        trail: pair.curr_callsign || pair.trail_callsign || '',
                        spacing,
                        shortfall_pct: parseFloat(pair.shortfall_pct || 0),
                    });
                }
            }

            metrics[branchId] = {
                pairs: pairs.length,
                compliant_pairs: compliant,
                compliance_pct: pairs.length > 0 ? Math.round((compliant / pairs.length) * 1000) / 10 : 100,
                violations,
                spacing_stats: {
                    min: spacings.length ? Math.round(Math.min(...spacings) * 10) / 10 : 0,
                    avg: spacings.length ? Math.round((spacings.reduce((a, b) => a + b, 0) / spacings.length) * 10) / 10 : 0,
                    max: spacings.length ? Math.round(Math.max(...spacings) * 10) / 10 : 0,
                },
            };
        }
        return metrics;
    },

    /**
     * Add flow stream visualization layers to an existing map
     * Extracted to allow adding layers either during initial map load or after async API returns
     *
     * @param {string} mapId - Map identifier
     * @param {Object} flowData - Flow stream data from the API
     */
    addFlowStreamLayers: function(mapId, flowData) {
        const map = this.activeMaps[mapId];
        if (!map || !flowData?.streams?.length) {
            return;
        }

        // Skip if layers already exist
        if (map.getSource('flow-streams')) {
            console.log(`Flow stream layers already exist for ${mapId}`);
            return;
        }

        // Color palette for streams - use centralized config with fallbacks
        const streamColors = FILTER_CONFIG?.map?.streamPalette || [
            '#3498db', // Blue
            '#e74c3c', // Red
            '#2ecc71', // Green
            '#9b59b6', // Purple
            '#f39c12', // Orange
            '#1abc9c', // Teal
            '#e67e22', // Dark orange
            '#34495e', // Dark gray-blue
        ];

        // Build stream hull features
        const streamFeatures = flowData.streams.map((stream, idx) => ({
            type: 'Feature',
            properties: {
                stream_id: stream.stream_id,
                display_short: stream.display?.short || stream.stream_id,
                display_long: stream.display?.long || stream.stream_id,
                track_count: stream.track_count,
                is_merge: stream.components?.is_merge || false,
                color: streamColors[idx % streamColors.length],
            },
            geometry: stream.hull
        }));

        // Build merge zone features
        const mergeFeatures = (flowData.merge_zones || []).map(mz => ({
            type: 'Feature',
            properties: {
                merge_id: mz.merge_id,
                display_short: mz.display?.short || 'Merge',
                display_long: mz.display?.long || 'Merge Zone',
                parent_streams: mz.stream_addresses?.join(' + ') || '',
            },
            geometry: mz.geometry
        }));

        // Add stream hulls source
        map.addSource('flow-streams', {
            type: 'geojson',
            data: { type: 'FeatureCollection', features: streamFeatures }
        });

        // Stream hull fills (subtle, below flight tracks)
        const beforeLayer = map.getLayer('flight-tracks-solid-glow') ? 'flight-tracks-solid-glow' : undefined;

        map.addLayer({
            id: 'flow-streams-fill',
            type: 'fill',
            source: 'flow-streams',
            paint: {
                'fill-color': ['get', 'color'],
                'fill-opacity': ['case', ['get', 'is_merge'], 0.08, 0.12],
            }
        }, beforeLayer);

        // Stream hull outlines
        map.addLayer({
            id: 'flow-streams-outline',
            type: 'line',
            source: 'flow-streams',
            paint: {
                'line-color': ['get', 'color'],
                'line-width': ['case', ['get', 'is_merge'], 1.5, 2],
                'line-opacity': 0.7,
                'line-dasharray': ['case', ['get', 'is_merge'], ['literal', [4, 2]], ['literal', [1, 0]]],
            }
        }, beforeLayer);

        // Stream labels (centroid)
        const labelFeatures = flowData.streams.map((stream, idx) => ({
            type: 'Feature',
            properties: {
                label: stream.display?.short || stream.stream_id,
                track_count: stream.track_count,
                color: streamColors[idx % streamColors.length],
            },
            geometry: stream.centroid
        }));

        map.addSource('flow-stream-labels', {
            type: 'geojson',
            data: { type: 'FeatureCollection', features: labelFeatures }
        });

        map.addLayer({
            id: 'flow-stream-labels',
            type: 'symbol',
            source: 'flow-stream-labels',
            layout: {
                'text-field': ['concat', ['get', 'label'], '\n', ['get', 'track_count'], ' ac'],
                'text-font': ['Open Sans Bold'],
                'text-size': 11,
                'text-anchor': 'center',
                'text-allow-overlap': false,
            },
            paint: {
                'text-color': ['get', 'color'],
                'text-halo-color': '#ffffff',
                'text-halo-width': 1.5,
            }
        });

        // Add merge zones if present
        if (mergeFeatures.length > 0) {
            map.addSource('flow-merge-zones', {
                type: 'geojson',
                data: { type: 'FeatureCollection', features: mergeFeatures }
            });

            map.addLayer({
                id: 'flow-merge-zones-fill',
                type: 'fill',
                source: 'flow-merge-zones',
                paint: {
                    'fill-color': '#ff6b6b',
                    'fill-opacity': 0.15,
                }
            }, beforeLayer);

            map.addLayer({
                id: 'flow-merge-zones-outline',
                type: 'line',
                source: 'flow-merge-zones',
                paint: {
                    'line-color': '#ff6b6b',
                    'line-width': 2,
                    'line-opacity': 0.8,
                    'line-dasharray': [2, 2],
                }
            }, beforeLayer);
        }

        console.log(`Added flow streams: ${streamFeatures.length} streams, ${mergeFeatures.length} merge zones`);

        // Add hover effect for streams
        map.on('mouseenter', 'flow-streams-fill', () => {
            map.getCanvas().style.cursor = 'pointer';
        });
        map.on('mouseleave', 'flow-streams-fill', () => {
            map.getCanvas().style.cursor = '';
        });

        // Add click popup for stream details
        map.on('click', 'flow-streams-fill', (e) => {
            const props = e.features[0]?.properties || {};
            new maplibregl.Popup({ closeButton: true, maxWidth: '280px' })
                .setLngLat(e.lngLat)
                .setHTML(`
                    <div style="font-size: 12px;">
                        <div style="font-weight: bold; border-bottom: 1px solid #ddd; padding-bottom: 4px; margin-bottom: 4px;">
                            ${props.display_long || props.stream_id || 'Stream'}
                        </div>
                        <div><strong>ID:</strong> ${props.stream_id || 'N/A'}</div>
                        <div><strong>Aircraft:</strong> ${props.track_count || 0}</div>
                        ${props.is_merge === 'true' || props.is_merge === true ? '<div style="color: #e74c3c;"><strong>Type:</strong> Merge Zone</div>' : ''}
                    </div>
                `)
                .addTo(map);
        });
    },

    /**
     * Render a collapsible map section for a TMI
     */
    renderMapSection: function(r, mapId) {
        const requestor = (r.requestor || '').replace(/'/g, "\\'");
        const provider = (r.provider || '').replace(/'/g, "\\'");
        // Use airway field if available, then raw fix name for GIS resolution
        // Airway is explicitly set when fix is an airway identifier (Y290, J48, etc.)
        // Note: r.measurement_point is a display label (may include suffixes like "(boundary unavailable)")
        // and should NOT be used for GIS waypoint resolution
        const fix = (r.airway || r.fix || '').replace(/'/g, "\\'");
        const destinations = r.destinations || [];
        const origins = r.origins || [];

        // Cache TMI metadata for later use in map rendering
        this.tmiMetadataCache[mapId] = {
            required: r.required || 0,
            unit: r.unit || 'nm',
        };

        // Cache trajectories if available (for flight track rendering)
        // Supports both inline (old format) and split (new format via _rawTrajectories)
        const _cacheAndFetchForMap = (trajectories) => {
            if (!trajectories || Object.keys(trajectories).length === 0) return;
            this.trajectoryCache[mapId] = trajectories;
            console.log(`Cached ${Object.keys(trajectories).length} trajectories for ${mapId}`);

            // Filter trajectories to TMI-relevant flow (e.g., only SJC arrivals for SJC MIT)
            const { filtered, stats } = this.filterTrajectoriesToTMIFlow(trajectories, r);
            this.flowTrajectoryCache[mapId] = filtered;
            this.flowFilterStats = this.flowFilterStats || {};
            this.flowFilterStats[mapId] = stats;

            this.renderFlowAnalysis(mapId);

            // Trigger async flow stream analysis with TMI-filtered trajectories
            // fix_info from Python: {lat, lon} — prefer over crossing centroid
            const fixPoint = r.fix_info ? [r.fix_info.lon, r.fix_info.lat]
                : (r.traffic_sector?.measurement_point) // [lon, lat] crossing centroid fallback
                || null;
            const knownFixes = (r.known_fixes || r.approach_fixes || []).map(f => ({
                id: f.id || f.name || f.fix,
                lat: f.lat || f.latitude || f.geometry?.coordinates?.[1],
                lon: f.lon || f.lng || f.longitude || f.geometry?.coordinates?.[0]
            })).filter(f => f.lat && f.lon);
            this.fetchFlowStreams(mapId, filtered, {
                fixPoint, knownFixes, isArrival: r.direction !== 'departure'
            });
            // Also fire branch analysis with TMI-filtered trajectories
            this.fetchBranchAnalysis(mapId, filtered, r);
        };

        // Try inline trajectories first (old format / backwards compat)
        const inlineTrajs = r.trajectories && Object.keys(r.trajectories).length > 0
            ? r.trajectories : null;
        if (inlineTrajs) {
            _cacheAndFetchForMap(inlineTrajs);
        } else if (r.has_trajectories && r.mit_key) {
            // New split format: check _rawTrajectories or wait for promise
            const splitTrajs = this._rawTrajectories?.[r.mit_key];
            if (splitTrajs && Object.keys(splitTrajs).length > 0) {
                _cacheAndFetchForMap(splitTrajs);
            } else if (this._trajectoryPromise) {
                // Trajectories still loading — defer until ready
                this._trajectoryPromise.then(() => {
                    const deferred = this._rawTrajectories?.[r.mit_key];
                    _cacheAndFetchForMap(deferred);
                });
            }
        }

        // Cache traffic sector data if available (include required spacing for arc rendering)
        if (r.traffic_sector) {
            this.trafficSectorCache = this.trafficSectorCache || {};
            this.trafficSectorCache[mapId] = {
                ...r.traffic_sector,
                // Prefer navdata fix coords (fix_info) over crossing centroid (measurement_point)
                fix_point: r.fix_info ? [r.fix_info.lon, r.fix_info.lat]
                    : (r.traffic_sector.measurement_point || r.traffic_sector.fix_point),
                required_spacing: r.required || 0,
                unit: r.unit || 'nm',
            };
            console.log(`Cached traffic sector for ${mapId}: ${r.traffic_sector.track_count} tracks, ${r.traffic_sector.sector_75.width_deg}° (75%), ${r.traffic_sector.sector_90.width_deg}° (90%), ${r.required}${r.unit} spacing`);
        }

        // Cache pair data for violations/pairs map layers
        if (r.all_pairs && r.all_pairs.length > 0) {
            this.pairCache = this.pairCache || {};
            this.pairCache[mapId] = r.all_pairs;
            console.log(`Cached ${r.all_pairs.length} pairs for ${mapId}`);
        }

        // Branch corridors are now fetched via fetchBranchAnalysis() above (fired with trajectories)
        // Legacy: cache if still present in PHP response (backwards compat)
        if (r.branch_corridors && r.branch_corridors.branches?.length > 0) {
            this.branchCorridorCache[mapId] = r.branch_corridors;
            console.log(`Cached ${r.branch_corridors.branch_count} branches for ${mapId}`);
        }

        if (!requestor && !provider) {
            return ''; // No facilities to show
        }

        // Escape JSON for HTML attribute - use encodeURIComponent for safety
        const destJson = encodeURIComponent(JSON.stringify(destinations));
        const origJson = encodeURIComponent(JSON.stringify(origins));

        const displayReq = r.requestor || '';
        const displayProv = r.provider || '';

        return `
            <div class="tmi-map-section mt-3">
                <div class="tmi-map-toggle collapsed"
                     data-map-id="${mapId}"
                     data-requestor="${requestor}"
                     data-provider="${provider}"
                     data-fix="${fix}"
                     data-destinations="${destJson}"
                     data-origins="${origJson}"
                     onclick="TMICompliance.toggleMapFromData(this)">
                    <i class="fas fa-chevron-down"></i>
                    <span><i class="fas fa-map-marked-alt mr-1"></i> View Context Map</span>
                    <span class="small ml-2">(${displayProv}${displayReq ? ' → ' + displayReq : ''})</span>
                </div>
                <div class="tmi-map-collapse" id="${mapId}" style="display: none;">
                    <div class="tmi-map-layer-controls" id="${mapId}_controls" style="display: none;">
                        <span class="layer-label">Layers:</span>
                        <button class="layer-btn active" data-layer="artcc" data-map="${mapId}" onclick="TMICompliance.toggleLayer(this)">ARTCC</button>
                        <button class="layer-btn active" data-layer="tracon" data-map="${mapId}" onclick="TMICompliance.toggleLayer(this)">TRACON</button>
                        <button class="layer-btn" data-layer="sectors-low" data-map="${mapId}" onclick="TMICompliance.toggleLayer(this)">Low</button>
                        <button class="layer-btn active" data-layer="sectors-high" data-map="${mapId}" onclick="TMICompliance.toggleLayer(this)">High</button>
                        <button class="layer-btn" data-layer="sectors-superhigh" data-map="${mapId}" onclick="TMICompliance.toggleLayer(this)">Super High</button>
                        <span class="layer-divider">|</span>
                        <button class="layer-btn active" data-layer="tracks" data-map="${mapId}" onclick="TMICompliance.toggleLayer(this)">Tracks</button>
                        <button class="layer-btn active" data-layer="flow-streams" data-map="${mapId}" onclick="TMICompliance.toggleLayer(this)">Streams</button>
                        <button class="layer-btn active" data-layer="traffic-sectors" data-map="${mapId}" onclick="TMICompliance.toggleLayer(this)">Flow Cone</button>
                        <button class="layer-btn branch-toggle-btn" data-map="${mapId}" onclick="TMICompliance.toggleBranches(this)" style="display:none"><i class="fas fa-code-branch"></i> Branches</button>
                        <button class="layer-btn" data-layer="pairs" data-map="${mapId}" onclick="TMICompliance.toggleLayer(this)">Pairs</button>
                        <button class="layer-btn" data-layer="violations" data-map="${mapId}" onclick="TMICompliance.toggleLayer(this)">Violations</button>
                        <span class="layer-divider">|</span>
                        <span class="cone-legend">
                            <span class="legend-item"><span class="legend-swatch cone-75"></span>75%</span>
                            <span class="legend-item"><span class="legend-swatch cone-90"></span>90%</span>
                        </span>
                    </div>
                    <div class="flow-analysis-section" id="${mapId}_flow_analysis" style="display: none;"></div>
                    <div class="tmi-map-container" id="${mapId}_container">
                        <div class="tmi-map-loading">
                            <i class="fas fa-spinner fa-spin"></i> Loading map...
                        </div>
                    </div>
                </div>
            </div>
        `;
    },

    /**
     * Toggle map from data attributes (safer than inline onclick params)
     */
    toggleMapFromData: function(element) {
        const el = $(element);
        // jQuery converts data-map-id to mapId automatically
        const mapId = el.data('mapId') || el.attr('data-map-id');
        const requestor = el.data('requestor') || '';
        const provider = el.data('provider') || '';
        const fix = el.data('fix') || '';

        // Parse the encoded JSON arrays
        let destinations = [];
        let origins = [];
        try {
            const destData = el.data('destinations') || el.attr('data-destinations') || '[]';
            const origData = el.data('origins') || el.attr('data-origins') || '[]';
            destinations = JSON.parse(decodeURIComponent(destData));
            origins = JSON.parse(decodeURIComponent(origData));
        } catch (e) {
            console.error('Error parsing map data attributes:', e);
        }

        console.log('toggleMapFromData:', { mapId, requestor, provider, fix, destinations, origins });
        this.toggleMap(mapId, requestor, provider, fix, destinations, origins);
    },

    /**
     * Toggle map visibility and load data
     */
    toggleMap: function(mapId, requestor, provider, fix, destinations, origins) {
        // Use attribute selector since jQuery data() caches can differ
        const toggle = $(`.tmi-map-toggle[data-map-id="${mapId}"]`);
        const collapseDiv = $(`#${mapId}`);

        console.log('toggleMap called:', { mapId, hasToggle: toggle.length, hasCollapse: collapseDiv.length });

        const isCollapsed = toggle.hasClass('collapsed');

        if (isCollapsed) {
            // Opening
            toggle.removeClass('collapsed');
            collapseDiv.slideDown(200);

            // Load map if not already loaded
            if (!this.activeMaps[mapId]) {
                this.loadMapData(mapId, requestor, provider, fix, destinations, origins);
            }
        } else {
            // Closing
            toggle.addClass('collapsed');
            collapseDiv.slideUp(200);
        }
    },

    /**
     * Load map data from API and render
     */
    loadMapData: function(mapId, requestor, provider, fix, destinations, origins) {
        console.log(`loadMapData: fix="${fix}", isAirway=${this.isAirway(fix)}`);
        const cacheKey = `${requestor}_${provider}_${fix}`;

        // Check cache first
        if (this.mapDataCache[cacheKey]) {
            console.log('Map data cache hit:', cacheKey);
            this.renderMap(mapId, this.mapDataCache[cacheKey]);
            return;
        }

        // Fetch from API
        const params = new URLSearchParams({
            action: 'tmi_map',
            requestor: requestor,
            provider: provider,
            fixes: JSON.stringify(fix ? [fix] : []),
            destinations: JSON.stringify(destinations || []),
            origins: JSON.stringify(origins || []),
        });

        const apiUrl = `api/gis/boundaries.php?${params}`;
        console.log('Fetching map data:', apiUrl);

        fetch(apiUrl)
            .then(response => {
                console.log('Map API response status:', response.status);
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                return response.json();
            })
            .then(async data => {
                console.log('Map API response:', data);
                if (data.success && data.map_data) {
                    const mapData = data.map_data;

                    // If facilities are empty, load from local GeoJSON files
                    if (!mapData.facilities?.length && (requestor || provider)) {
                        console.log('Loading facility boundaries from local GeoJSON...');
                        const localFacilities = await this.loadLocalFacilityBoundaries(requestor, provider);
                        if (localFacilities.length > 0) {
                            mapData.facilities = localFacilities;
                            // Recalculate bounds to include facilities
                            mapData.bounds = this.calculateBounds(mapData);
                            console.log(`Loaded ${localFacilities.length} facilities from local GeoJSON`);
                        }
                    }

                    // Load sector boundaries for any ARTCC facilities
                    const artccCodes = [];
                    if (requestor && /^Z[A-Z]{2}$/.test(requestor)) {artccCodes.push(requestor);}
                    if (provider && /^Z[A-Z]{2}$/.test(provider) && provider !== requestor) {artccCodes.push(provider);}
                    if (artccCodes.length > 0) {
                        console.log('Loading sector boundaries for ARTCCs:', artccCodes.join(', '));
                        const sectors = await this.loadLocalSectorBoundaries(artccCodes, provider);
                        if (sectors.length > 0) {
                            mapData.sectors = sectors;
                            console.log(`Loaded ${sectors.length} sector boundaries`);
                        }
                    }

                    // Load airway geometry if fix is an airway identifier
                    if (fix && this.isAirway(fix)) {
                        console.log(`Detected airway: ${fix}, fetching geometry...`);
                        const airwayData = await this.fetchAirwayGeometry(fix);
                        if (airwayData?.found && airwayData.geojson) {
                            mapData.airway = airwayData;
                            console.log(`Loaded airway ${fix}: ${airwayData.segment_count} segments, ${airwayData.total_distance_nm}nm`);

                            // Extend bounds to include airway
                            if (airwayData.geojson.geometry?.coordinates) {
                                const coords = airwayData.geojson.geometry.coordinates;
                                if (coords.length > 0 && mapData.bounds) {
                                    let [minLon, minLat, maxLon, maxLat] = mapData.bounds;
                                    coords.forEach(([lon, lat]) => {
                                        minLon = Math.min(minLon, lon);
                                        minLat = Math.min(minLat, lat);
                                        maxLon = Math.max(maxLon, lon);
                                        maxLat = Math.max(maxLat, lat);
                                    });
                                    mapData.bounds = [minLon, minLat, maxLon, maxLat];
                                }
                            }
                        }
                    }

                    // Cache the data
                    this.mapDataCache[cacheKey] = mapData;
                    this.renderMap(mapId, mapData);
                } else {
                    const errMsg = data.error || 'Failed to load map data';
                    console.error('Map API error:', errMsg);
                    this.showMapError(mapId, errMsg);
                }
            })
            .catch(err => {
                console.error('Map data fetch error:', err);
                this.showMapError(mapId, 'Error loading map: ' + err.message);
            });
    },

    /**
     * Render MapLibre map with TMI context
     */
    renderMap: function(mapId, mapData) {
        const container = document.getElementById(`${mapId}_container`);
        if (!container) {return;}

        // Clear loading state
        container.innerHTML = '';

        // Check if we have any data to show (facilities, fixes, airports, or trajectories)
        const hasData = mapData.facilities?.length || mapData.fixes?.length ||
                        mapData.airports?.length || this.trajectoryCache[mapId];
        if (!hasData) {
            container.innerHTML = '<div class="text-center text-muted py-4">No boundary data available</div>';
            return;
        }

        // Calculate center and bounds
        let center = mapData.center || [-95, 38];
        let zoom = 5;

        if (mapData.bounds) {
            const [minLon, minLat, maxLon, maxLat] = mapData.bounds;
            center = [(minLon + maxLon) / 2, (minLat + maxLat) / 2];
            const latDiff = maxLat - minLat;
            const lonDiff = maxLon - minLon;
            const maxDiff = Math.max(latDiff, lonDiff);
            zoom = maxDiff > 20 ? 3 : maxDiff > 10 ? 4 : maxDiff > 5 ? 5 : 6;
        }

        // Create map
        const map = new maplibregl.Map({
            container: container,
            style: {
                version: 8,
                glyphs: 'https://fonts.openmaptiles.org/{fontstack}/{range}.pbf',
                sources: {
                    'carto-dark': {
                        type: 'raster',
                        tiles: ['https://a.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}.png'],
                        tileSize: 256,
                        attribution: '&copy; OpenStreetMap contributors',
                    },
                },
                layers: [{
                    id: 'carto-dark-layer',
                    type: 'raster',
                    source: 'carto-dark',
                    minzoom: 0,
                    maxzoom: 19,
                }],
            },
            center: center,
            zoom: zoom,
            attributionControl: false,
        });

        this.activeMaps[mapId] = map;

        map.on('load', () => {
            // Catch race condition: if fetchBranchAnalysis() completed before map activation,
            // initBranchAnalysis was skipped. Trigger it now that the map is active and style is loaded.
            if (this.branchCorridorCache[mapId] && !this.branchCorridorCache[mapId].error
                && !this.branchPanelState[mapId]?.initialized) {
                this.initBranchAnalysis(mapId);
            }

            // Add facility boundaries (provider emphasized as it manages the stream)
            if (mapData.facilities?.length) {
                map.addSource('facilities', {
                    type: 'geojson',
                    data: { type: 'FeatureCollection', features: mapData.facilities },
                });

                // Use centralized colors from FILTER_CONFIG with fallbacks
                const facilityColors = FILTER_CONFIG?.map?.facility || {
                    provider: { fill: '#4dabf7', fillOpacity: 0.1, stroke: '#4dabf7', strokeWidth: 2 },
                    requestor: { fill: '#ff6b6b', fillOpacity: 0.1, stroke: '#ff6b6b', strokeWidth: 2 },
                    default: { fill: '#888888', fillOpacity: 0.1, stroke: '#888888', strokeWidth: 1 },
                };

                map.addLayer({
                    id: 'facilities-fill',
                    type: 'fill',
                    source: 'facilities',
                    paint: {
                        'fill-color': ['case',
                            ['==', ['get', 'role'], 'provider'], facilityColors.provider.fill,
                            ['==', ['get', 'role'], 'requestor'], facilityColors.requestor.fill,
                            facilityColors.default.fill],
                        'fill-opacity': facilityColors.provider.fillOpacity || 0.1,
                    },
                });

                map.addLayer({
                    id: 'facilities-outline',
                    type: 'line',
                    source: 'facilities',
                    paint: {
                        'line-color': ['case',
                            ['==', ['get', 'role'], 'provider'], facilityColors.provider.stroke,
                            ['==', ['get', 'role'], 'requestor'], facilityColors.requestor.stroke,
                            facilityColors.default.stroke],
                        // Thicker line for provider (manages the stream)
                        'line-width': ['case',
                            ['==', ['get', 'role'], 'provider'], facilityColors.provider.strokeWidth || 3,
                            facilityColors.requestor.strokeWidth || 1.5],
                        'line-opacity': 0.6,
                    },
                });

                map.addLayer({
                    id: 'facilities-labels',
                    type: 'symbol',
                    source: 'facilities',
                    layout: {
                        'text-field': ['get', 'code'],
                        'text-font': ['Open Sans Regular'],
                        'text-size': 12,
                        'text-anchor': 'center',
                    },
                    paint: {
                        'text-color': '#ffffff',
                        'text-halo-color': '#000000',
                        'text-halo-width': 1,
                    },
                });

                console.log(`Added ${mapData.facilities.length} facility boundaries to map`);
            }

            // Add sector boundaries by altitude type (low, high, superhigh)
            if (mapData.sectors?.length) {
                // Use centralized colors from FILTER_CONFIG with fallbacks
                const sectorConfig = FILTER_CONFIG?.map?.sector || {
                    low: { fill: '#868e96', fillOpacity: 0.08, stroke: '#868e96', strokeWidth: 1 },
                    high: { fill: '#4dabf7', fillOpacity: 0.08, stroke: '#4dabf7', strokeWidth: 1.5 },
                    superhigh: { fill: '#228be6', fillOpacity: 0.06, stroke: '#228be6', strokeWidth: 1 },
                };
                const altitudeColors = {
                    low: { fill: sectorConfig.low.fill, line: sectorConfig.low.stroke, opacity: sectorConfig.low.fillOpacity || 0.08, width: sectorConfig.low.strokeWidth || 1 },
                    high: { fill: sectorConfig.high.fill, line: sectorConfig.high.stroke, opacity: sectorConfig.high.fillOpacity || 0.08, width: sectorConfig.high.strokeWidth || 1.5 },
                    superhigh: { fill: sectorConfig.superhigh.fill, line: sectorConfig.superhigh.stroke, opacity: sectorConfig.superhigh.fillOpacity || 0.06, width: sectorConfig.superhigh.strokeWidth || 1 },
                };

                // Insert below facilities if they exist
                const insertBefore = mapData.facilities?.length ? 'facilities-fill' : undefined;

                // Create separate source and layers for each altitude type
                for (const altType of ['low', 'high', 'superhigh']) {
                    const altSectors = mapData.sectors.filter(s => s.properties.altitude === altType);
                    if (altSectors.length === 0) {continue;}

                    const sourceId = `sectors-${altType}`;
                    const colors = altitudeColors[altType];

                    map.addSource(sourceId, {
                        type: 'geojson',
                        data: { type: 'FeatureCollection', features: altSectors },
                    });

                    // Sector fills - very subtle
                    map.addLayer({
                        id: `${sourceId}-fill`,
                        type: 'fill',
                        source: sourceId,
                        layout: {
                            'visibility': altType === 'high' ? 'visible' : 'none',  // Only high visible by default
                        },
                        paint: {
                            'fill-color': colors.fill,
                            'fill-opacity': 0.05,
                        },
                    }, insertBefore);

                    // Sector outlines - thin dashed lines
                    map.addLayer({
                        id: `${sourceId}-outline`,
                        type: 'line',
                        source: sourceId,
                        layout: {
                            'visibility': altType === 'high' ? 'visible' : 'none',
                        },
                        paint: {
                            'line-color': colors.line,
                            'line-width': ['case', ['==', ['get', 'role'], 'provider'], 1, 0.5],
                            'line-opacity': 0.6,
                            'line-dasharray': [3, 2],
                        },
                    }, insertBefore);

                    // Sector labels - only at higher zoom levels
                    map.addLayer({
                        id: `${sourceId}-labels`,
                        type: 'symbol',
                        source: sourceId,
                        minzoom: 6,
                        layout: {
                            'visibility': altType === 'high' ? 'visible' : 'none',
                            'text-field': ['get', 'code'],
                            'text-font': ['Open Sans Regular'],
                            'text-size': 9,
                            'text-anchor': 'center',
                            'text-allow-overlap': false,
                        },
                        paint: {
                            'text-color': colors.line,
                            'text-halo-color': '#000000',
                            'text-halo-width': 1,
                            'text-opacity': 0.6,
                        },
                    });

                    console.log(`Added ${altSectors.length} ${altType}-altitude sectors`);
                }

                console.log(`Added ${mapData.sectors.length} sector boundaries to map`);
            }

            // Add shared boundary (handoff line) - emphasized
            if (mapData.shared_boundary) {
                map.addSource('shared-boundary', {
                    type: 'geojson',
                    data: mapData.shared_boundary,
                });

                map.addLayer({
                    id: 'shared-boundary-glow',
                    type: 'line',
                    source: 'shared-boundary',
                    paint: {
                        'line-color': '#ffd43b',
                        'line-width': 8,
                        'line-opacity': 0.3,
                        'line-blur': 2,
                    },
                });

                map.addLayer({
                    id: 'shared-boundary-line',
                    type: 'line',
                    source: 'shared-boundary',
                    paint: {
                        'line-color': '#ffd43b',
                        'line-width': 4,
                        'line-opacity': 1,
                        'line-dasharray': [2, 1],
                    },
                });

                // Label the handoff boundary with facility names
                map.addLayer({
                    id: 'shared-boundary-label',
                    type: 'symbol',
                    source: 'shared-boundary',
                    layout: {
                        'symbol-placement': 'line-center',
                        'text-field': ['concat', ['get', 'facility1'], ' / ', ['get', 'facility2'], ' Boundary'],
                        'text-font': ['Open Sans Bold'],
                        'text-size': 13,
                        'text-offset': [0, -1.2],
                        'text-allow-overlap': true,
                    },
                    paint: {
                        'text-color': '#ffd43b',
                        'text-halo-color': '#000000',
                        'text-halo-width': 2,
                    },
                });
            }

            // Add airway line (for route-based TMIs like Y290, J48, etc.)
            if (mapData.airway?.geojson) {
                const airway = mapData.airway;
                console.log(`Rendering airway ${airway.name}: ${airway.type}`);

                // Use centralized colors from FILTER_CONFIG with fallbacks
                const airwayColors = FILTER_CONFIG?.map?.airway || {
                    stroke: '#00ff88',
                    strokeWidth: 3,
                    glowColor: '#00ff88',
                    glowWidth: 10,
                    glowOpacity: 0.25,
                    labelColor: '#00ff88',
                    labelHalo: '#000000',
                };

                map.addSource('tmi-airway', {
                    type: 'geojson',
                    data: airway.geojson,
                });

                // Airway glow (background)
                map.addLayer({
                    id: 'tmi-airway-glow',
                    type: 'line',
                    source: 'tmi-airway',
                    paint: {
                        'line-color': airwayColors.glowColor,
                        'line-width': airwayColors.glowWidth || 10,
                        'line-opacity': airwayColors.glowOpacity || 0.25,
                        'line-blur': 3,
                    },
                });

                // Airway main line
                map.addLayer({
                    id: 'tmi-airway-line',
                    type: 'line',
                    source: 'tmi-airway',
                    paint: {
                        'line-color': airwayColors.stroke,
                        'line-width': airwayColors.strokeWidth || 3,
                        'line-opacity': 0.9,
                    },
                });

                // Airway label
                map.addLayer({
                    id: 'tmi-airway-label',
                    type: 'symbol',
                    source: 'tmi-airway',
                    layout: {
                        'symbol-placement': 'line-center',
                        'text-field': airway.name,
                        'text-font': ['Open Sans Bold'],
                        'text-size': 14,
                        'text-offset': [0, -1],
                    },
                    paint: {
                        'text-color': airwayColors.labelColor || airwayColors.stroke,
                        'text-halo-color': airwayColors.labelHalo || '#000000',
                        'text-halo-width': 2,
                    },
                });

                console.log(`Added airway ${airway.name} layer to map`);
            }

            // Add fixes
            if (mapData.fixes?.length) {
                map.addSource('fixes', {
                    type: 'geojson',
                    data: { type: 'FeatureCollection', features: mapData.fixes },
                });

                map.addLayer({
                    id: 'fixes-circles',
                    type: 'circle',
                    source: 'fixes',
                    paint: {
                        'circle-radius': 6,
                        'circle-color': '#51cf66',
                        'circle-stroke-width': 2,
                        'circle-stroke-color': '#ffffff',
                    },
                });

                map.addLayer({
                    id: 'fixes-labels',
                    type: 'symbol',
                    source: 'fixes',
                    layout: {
                        'text-field': ['get', 'name'],
                        'text-font': ['Open Sans Regular'],
                        'text-size': 11,
                        'text-offset': [0, 1.5],
                        'text-anchor': 'top',
                    },
                    paint: {
                        'text-color': '#51cf66',
                        'text-halo-color': '#000000',
                        'text-halo-width': 1,
                    },
                });
            }

            // Add airports
            if (mapData.airports?.length) {
                map.addSource('airports', {
                    type: 'geojson',
                    data: { type: 'FeatureCollection', features: mapData.airports },
                });

                map.addLayer({
                    id: 'airports-icons',
                    type: 'circle',
                    source: 'airports',
                    paint: {
                        'circle-radius': 5,
                        'circle-color': ['case',
                            ['==', ['get', 'role'], 'origin'], '#f783ac',
                            ['==', ['get', 'role'], 'destination'], '#74c0fc',
                            '#d0bfff'],
                        'circle-stroke-width': 1,
                        'circle-stroke-color': '#ffffff',
                    },
                });

                map.addLayer({
                    id: 'airports-labels',
                    type: 'symbol',
                    source: 'airports',
                    layout: {
                        'text-field': ['get', 'code'],
                        'text-font': ['Open Sans Regular'],
                        'text-size': 10,
                        'text-offset': [0, -1.2],
                        'text-anchor': 'bottom',
                    },
                    paint: {
                        'text-color': '#ffffff',
                        'text-halo-color': '#000000',
                        'text-halo-width': 1,
                    },
                });
            }

            // Add flight trajectories with gap visualization and density-based coloring
            const trajectories = TMICompliance.trajectoryCache[mapId];
            if (trajectories && Object.keys(trajectories).length > 0) {
                // Build density grid from all trajectories
                // Grid resolution: ~0.05 degrees (about 3nm at mid-latitudes)
                const GRID_RES = 0.05;
                const densityGrid = {};

                // First pass: count unique flights per grid cell
                Object.entries(trajectories).forEach(([callsign, traj]) => {
                    if (!traj.coordinates || traj.coordinates.length < 2) return;
                    const visitedCells = new Set();

                    traj.coordinates.forEach(coord => {
                        const cellX = Math.floor(coord[0] / GRID_RES);
                        const cellY = Math.floor(coord[1] / GRID_RES);
                        const cellKey = `${cellX},${cellY}`;

                        if (!visitedCells.has(cellKey)) {
                            visitedCells.add(cellKey);
                            densityGrid[cellKey] = (densityGrid[cellKey] || 0) + 1;
                        }
                    });
                });

                // Find max density for normalization
                const maxDensity = Math.max(...Object.values(densityGrid), 1);

                // Helper: get density at a point (normalized 0-1)
                const getDensity = (lon, lat) => {
                    const cellX = Math.floor(lon / GRID_RES);
                    const cellY = Math.floor(lat / GRID_RES);
                    const cellKey = `${cellX},${cellY}`;
                    return (densityGrid[cellKey] || 0) / maxDensity;
                };

                // Helper: get average density along a segment
                const getSegmentDensity = (coords) => {
                    if (coords.length < 2) return 0;
                    let totalDensity = 0;
                    let samples = 0;

                    for (let i = 0; i < coords.length; i++) {
                        totalDensity += getDensity(coords[i][0], coords[i][1]);
                        samples++;
                    }

                    // Also sample midpoints for better coverage
                    for (let i = 0; i < coords.length - 1; i++) {
                        const midLon = (coords[i][0] + coords[i + 1][0]) / 2;
                        const midLat = (coords[i][1] + coords[i + 1][1]) / 2;
                        totalDensity += getDensity(midLon, midLat);
                        samples++;
                    }

                    return samples > 0 ? totalDensity / samples : 0;
                };

                const solidFeatures = [];  // Normal segments (gaps <= 5 min)
                const dashedFeatures = []; // Sparse data segments (gaps > 5 min, <= 15 min)

                const GAP_DASHED = 5 * 60;  // 5 minutes in seconds
                const GAP_BREAK = 15 * 60;  // 15 minutes in seconds

                Object.entries(trajectories).forEach(([callsign, traj]) => {
                    if (!traj.coordinates || traj.coordinates.length < 2) {return;}

                    // Split trajectory into segments based on time gaps
                    let currentSolid = [traj.coordinates[0]];

                    for (let i = 1; i < traj.coordinates.length; i++) {
                        const prev = traj.coordinates[i - 1];
                        const curr = traj.coordinates[i];

                        // Check if coordinates include timestamp (3rd element)
                        const hasTimestamps = prev.length >= 3 && curr.length >= 3;
                        const gap = hasTimestamps ? (curr[2] - prev[2]) : 0;

                        if (gap > GAP_BREAK) {
                            // Gap > 15 min: end current segment, start new one (no connecting line)
                            if (currentSolid.length >= 2) {
                                const coords = currentSolid.map(c => [c[0], c[1]]);
                                solidFeatures.push({
                                    type: 'Feature',
                                    properties: {
                                        callsign: callsign,
                                        dept: traj.properties?.dept || '',
                                        dest: traj.properties?.dest || '',
                                        density: getSegmentDensity(coords),
                                    },
                                    geometry: { type: 'LineString', coordinates: coords },
                                });
                            }
                            currentSolid = [curr];
                        } else if (gap > GAP_DASHED) {
                            // Gap > 5 min: end solid segment, add dashed connector, start new solid
                            if (currentSolid.length >= 2) {
                                const coords = currentSolid.map(c => [c[0], c[1]]);
                                solidFeatures.push({
                                    type: 'Feature',
                                    properties: {
                                        callsign: callsign,
                                        dept: traj.properties?.dept || '',
                                        dest: traj.properties?.dest || '',
                                        density: getSegmentDensity(coords),
                                    },
                                    geometry: { type: 'LineString', coordinates: coords },
                                });
                            }
                            // Add dashed line between last solid point and current
                            const dashCoords = [[prev[0], prev[1]], [curr[0], curr[1]]];
                            dashedFeatures.push({
                                type: 'Feature',
                                properties: {
                                    callsign: callsign,
                                    dept: traj.properties?.dept || '',
                                    dest: traj.properties?.dest || '',
                                    density: getSegmentDensity(dashCoords),
                                },
                                geometry: { type: 'LineString', coordinates: dashCoords },
                            });
                            currentSolid = [curr];
                        } else {
                            // Normal gap: continue solid segment
                            currentSolid.push(curr);
                        }
                    }

                    // Add final solid segment
                    if (currentSolid.length >= 2) {
                        const coords = currentSolid.map(c => [c[0], c[1]]);
                        solidFeatures.push({
                            type: 'Feature',
                            properties: {
                                callsign: callsign,
                                dept: traj.properties?.dept || '',
                                dest: traj.properties?.dest || '',
                                density: getSegmentDensity(coords),
                            },
                            geometry: { type: 'LineString', coordinates: coords },
                        });
                    }
                });

                console.log(`Track density: max ${maxDensity} flights per cell, ${Object.keys(densityGrid).length} cells`);

                // Spectral color ramp for density: blue (cold/sparse) -> red (hot/busy)
                // Use centralized config with fallbacks
                const densityRamp = FILTER_CONFIG?.map?.densityRamp || [
                    { stop: 0.0, color: '#3b4cc0' },
                    { stop: 0.2, color: '#6788ee' },
                    { stop: 0.4, color: '#9abbff' },
                    { stop: 0.5, color: '#c9d7f0' },
                    { stop: 0.6, color: '#edd1c2' },
                    { stop: 0.7, color: '#f7a789' },
                    { stop: 0.85, color: '#e26952' },
                    { stop: 1.0, color: '#b40426' },
                ];
                const densityColorExpr = [
                    'interpolate',
                    ['linear'],
                    ['get', 'density'],
                    ...densityRamp.flatMap(r => [r.stop, r.color]),
                ];

                // Add solid flight tracks
                if (solidFeatures.length > 0) {
                    map.addSource('flight-tracks-solid', {
                        type: 'geojson',
                        data: { type: 'FeatureCollection', features: solidFeatures },
                    });

                    map.addLayer({
                        id: 'flight-tracks-solid-glow',
                        type: 'line',
                        source: 'flight-tracks-solid',
                        layout: { 'line-cap': 'round', 'line-join': 'round' },
                        paint: {
                            'line-color': densityColorExpr,
                            'line-width': 5,
                            'line-opacity': 0.25,
                            'line-blur': 3,
                        },
                    });

                    map.addLayer({
                        id: 'flight-tracks-solid',
                        type: 'line',
                        source: 'flight-tracks-solid',
                        layout: { 'line-cap': 'round', 'line-join': 'round' },
                        paint: {
                            'line-color': densityColorExpr,
                            'line-width': 2,
                            'line-opacity': 0.85,
                        },
                    });
                }

                // Add dashed flight tracks (data gaps > 5 min)
                if (dashedFeatures.length > 0) {
                    map.addSource('flight-tracks-dashed', {
                        type: 'geojson',
                        data: { type: 'FeatureCollection', features: dashedFeatures },
                    });

                    map.addLayer({
                        id: 'flight-tracks-dashed',
                        type: 'line',
                        source: 'flight-tracks-dashed',
                        layout: { 'line-cap': 'round', 'line-join': 'round' },
                        paint: {
                            'line-color': densityColorExpr,
                            'line-width': 1.5,
                            'line-opacity': 0.5,
                            'line-dasharray': [4, 4],
                        },
                    });
                }

                console.log(`Added flight tracks: ${solidFeatures.length} solid, ${dashedFeatures.length} dashed segments`);
            }

            // Add flow stream visualization (DBSCAN-clustered traffic streams)
            // Uses the extracted addFlowStreamLayers function which can also be called
            // dynamically when the API returns after the map has already loaded
            const flowData = TMICompliance.flowStreamCache?.[mapId];
            if (flowData?.streams?.length > 0) {
                TMICompliance.addFlowStreamLayers(mapId, flowData);
            }

            // Compute multi-stream flow corridors from trajectory data
            // First clusters trajectories by approach direction, then computes per-stream cones
            // Enhancement runs when: we have trajectories AND (no enhanced sector yet OR basic sector from Python)
            // Uses TMI-filtered trajectories (only flights matching the TMI's semantic flow)
            const existingSector = TMICompliance.trafficSectorCache?.[mapId];
            const needsEnhancement = !existingSector?.use_centerline; // Python provides basic sector without centerline
            const hasTrajectories = !!(TMICompliance.flowTrajectoryCache[mapId] || TMICompliance.trajectoryCache[mapId]);
            // Get fix coordinates: prefer GIS-resolved fix, fallback to Python's measurement_point
            let fixLon, fixLat;
            if (mapData.fixes?.length && mapData.fixes[0]?.geometry?.coordinates) {
                [fixLon, fixLat] = mapData.fixes[0].geometry.coordinates;
            } else if (existingSector?.fix_point) {
                [fixLon, fixLat] = existingSector.fix_point;
                console.log(`Using cached measurement_point for cone enhancement: [${fixLon}, ${fixLat}]`);
            }

            if (needsEnhancement && hasTrajectories && fixLon !== undefined) {
                // Prefer TMI-filtered trajectories; fall back to full set
                const trajectories = TMICompliance.flowTrajectoryCache[mapId] || TMICompliance.trajectoryCache[mapId];
                // Fix coordinates already set above

                    // Compute distance between two points in nm
                    const distanceNm = (lon1, lat1, lon2, lat2) => {
                        const R = 3440.065; // Earth radius in nm
                        const dLat = (lat2 - lat1) * Math.PI / 180;
                        const dLon = (lon2 - lon1) * Math.PI / 180;
                        const a = Math.sin(dLat / 2) ** 2 +
                            Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) * Math.sin(dLon / 2) ** 2;
                        return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
                    };

                    // Compute bearing from point 1 to point 2
                    const bearingTo = (lon1, lat1, lon2, lat2) => {
                        const dLon = (lon2 - lon1) * Math.PI / 180;
                        const lat1r = lat1 * Math.PI / 180;
                        const lat2r = lat2 * Math.PI / 180;
                        const y = Math.sin(dLon) * Math.cos(lat2r);
                        const x = Math.cos(lat1r) * Math.sin(lat2r) - Math.sin(lat1r) * Math.cos(lat2r) * Math.cos(dLon);
                        return ((Math.atan2(y, x) * 180 / Math.PI) + 360) % 360;
                    };

                    // Compute point at given bearing and distance from origin
                    const pointAtBearing = (lon, lat, bearingDeg, distNm) => {
                        const R = 3440.065; // Earth radius in nm
                        const bearing = bearingDeg * Math.PI / 180;
                        const lat1 = lat * Math.PI / 180;
                        const lon1 = lon * Math.PI / 180;
                        const lat2 = Math.asin(Math.sin(lat1) * Math.cos(distNm / R) +
                            Math.cos(lat1) * Math.sin(distNm / R) * Math.cos(bearing));
                        const lon2 = lon1 + Math.atan2(
                            Math.sin(bearing) * Math.sin(distNm / R) * Math.cos(lat1),
                            Math.cos(distNm / R) - Math.sin(lat1) * Math.sin(lat2)
                        );
                        return [lon2 * 180 / Math.PI, lat2 * 180 / Math.PI];
                    };

                    // Angular difference helper (handles wrap-around)
                    const angularDiff = (a, b) => {
                        let diff = a - b;
                        while (diff > 180) diff -= 360;
                        while (diff < -180) diff += 360;
                        return diff;
                    };

                    // Get required spacing from TMI metadata
                    const tmiMeta = TMICompliance.tmiMetadataCache?.[mapId] || {};
                    const requiredSpacing = tmiMeta.required || 15;
                    const maxDistance = Math.max(75, requiredSpacing * 4);
                    const BIN_SIZE = 3;

                    // ═══════════════════════════════════════════════════════════════════════
                    // STEP 1: Compute approach bearing for each trajectory
                    // Find where traffic is COMING FROM by detecting the approach phase
                    // (when aircraft is getting closer to fix) and computing direction
                    // ═══════════════════════════════════════════════════════════════════════
                    const trajectoryApproach = {}; // callsign -> { bearing, minDist }

                    Object.entries(trajectories).forEach(([callsign, traj]) => {
                        if (!traj.coordinates || traj.coordinates.length < 2) return;

                        let bestApproachPoint = null;
                        let bestApproachDist = Infinity;

                        // Find approach segments (where distance to fix is DECREASING)
                        // and use a point from the approach phase to determine direction
                        for (let i = 1; i < traj.coordinates.length; i++) {
                            const prev = traj.coordinates[i - 1];
                            const curr = traj.coordinates[i];

                            const prevDist = distanceNm(prev[0], prev[1], fixLon, fixLat);
                            const currDist = distanceNm(curr[0], curr[1], fixLon, fixLat);

                            // Is this segment approaching? (getting closer to fix)
                            if (currDist < prevDist) {
                                // Use the upstream point (prev) as the approach reference
                                // Prefer points 10-40nm out for stable bearing calculation
                                if (prevDist >= 10 && prevDist <= 40 && prevDist < bestApproachDist) {
                                    bestApproachDist = prevDist;
                                    bestApproachPoint = prev;
                                }
                            }
                        }

                        // Fallback: if no good approach point found, try any approaching point within 50nm
                        if (!bestApproachPoint) {
                            for (let i = 1; i < traj.coordinates.length; i++) {
                                const prev = traj.coordinates[i - 1];
                                const curr = traj.coordinates[i];
                                const prevDist = distanceNm(prev[0], prev[1], fixLon, fixLat);
                                const currDist = distanceNm(curr[0], curr[1], fixLon, fixLat);

                                if (currDist < prevDist && prevDist <= 50 && prevDist < bestApproachDist) {
                                    bestApproachDist = prevDist;
                                    bestApproachPoint = prev;
                                }
                            }
                        }

                        if (bestApproachPoint) {
                            // Bearing FROM fix TO the approach point = direction traffic comes FROM
                            const approachBearing = bearingTo(fixLon, fixLat, bestApproachPoint[0], bestApproachPoint[1]);
                            trajectoryApproach[callsign] = { bearing: approachBearing, minDist: bestApproachDist };
                        }
                    });

                    // ═══════════════════════════════════════════════════════════════════════
                    // STEP 2: Cluster trajectories by approach bearing
                    // Use 45° bins, then merge adjacent sparse bins
                    // ═══════════════════════════════════════════════════════════════════════
                    const CLUSTER_WIDTH = 45; // Degrees per initial bin
                    const bearingBins = {}; // bin_center -> [callsigns]

                    Object.entries(trajectoryApproach).forEach(([callsign, data]) => {
                        const binCenter = Math.round(data.bearing / CLUSTER_WIDTH) * CLUSTER_WIDTH;
                        bearingBins[binCenter] = bearingBins[binCenter] || [];
                        bearingBins[binCenter].push(callsign);
                    });

                    // Filter out bins with fewer than 2 aircraft (noise)
                    const significantBins = Object.entries(bearingBins)
                        .filter(([, callsigns]) => callsigns.length >= 2)
                        .sort((a, b) => b[1].length - a[1].length); // Sort by count descending

                    // Merge adjacent bins that are within 45° of each other
                    const streams = []; // { bearing, callsigns }
                    const usedBins = new Set();

                    significantBins.forEach(([binStr, callsigns]) => {
                        const bin = Number(binStr);
                        if (usedBins.has(bin)) return;

                        // Start a new stream
                        let streamCallsigns = [...callsigns];
                        let sumBearing = bin * callsigns.length;
                        let count = callsigns.length;
                        usedBins.add(bin);

                        // Try to merge adjacent bins
                        significantBins.forEach(([adjBinStr, adjCallsigns]) => {
                            const adjBin = Number(adjBinStr);
                            if (usedBins.has(adjBin)) return;

                            const diff = Math.abs(angularDiff(bin, adjBin));
                            if (diff <= CLUSTER_WIDTH && diff > 0) {
                                streamCallsigns = streamCallsigns.concat(adjCallsigns);
                                sumBearing += adjBin * adjCallsigns.length;
                                count += adjCallsigns.length;
                                usedBins.add(adjBin);
                            }
                        });

                        const avgBearing = sumBearing / count;
                        streams.push({
                            bearing: avgBearing,
                            callsigns: streamCallsigns,
                            trackCount: streamCallsigns.length
                        });
                    });

                    console.log(`Detected ${streams.length} distinct streams from ${Object.keys(trajectoryApproach).length} trajectories`);
                    streams.forEach((s, i) => console.log(`  Stream ${i + 1}: ${s.trackCount} tracks, bearing ${s.bearing.toFixed(0)}°`));

                    // ═══════════════════════════════════════════════════════════════════════
                    // STEP 3: Compute per-stream centerlines and cones
                    // ═══════════════════════════════════════════════════════════════════════
                    const allStreamCones = []; // Array of { polygon_75, polygon_90, bearing, trackCount }

                    streams.forEach((stream, streamIdx) => {
                        const streamCallsigns = new Set(stream.callsigns);

                        // Sample this stream's trajectories by distance from fix
                        // ONLY use points from APPROACH phase (getting closer to fix)
                        const distanceBins = {};

                        Object.entries(trajectories).forEach(([callsign, traj]) => {
                            if (!streamCallsigns.has(callsign)) return;
                            if (!traj.coordinates || traj.coordinates.length < 2) return;

                            const visitedBins = new Set();

                            // Only sample points from approach phase (distance decreasing)
                            for (let i = 1; i < traj.coordinates.length; i++) {
                                const prev = traj.coordinates[i - 1];
                                const curr = traj.coordinates[i];

                                const prevDist = distanceNm(prev[0], prev[1], fixLon, fixLat);
                                const currDist = distanceNm(curr[0], curr[1], fixLon, fixLat);

                                // Only use this point if approaching (getting closer)
                                if (currDist < prevDist) {
                                    const [lon, lat] = prev;
                                    const dist = prevDist;
                                    const bearing = bearingTo(fixLon, fixLat, lon, lat);
                                    const bin = Math.round(dist / BIN_SIZE) * BIN_SIZE;

                                    if (bin > 0 && bin <= maxDistance && !visitedBins.has(bin)) {
                                        visitedBins.add(bin);
                                        distanceBins[bin] = distanceBins[bin] || [];
                                        distanceBins[bin].push({ bearing, lon, lat, callsign });
                                    }
                                }
                            }
                        });

                        // Compute centerline for this stream
                        const centerlinePoints = [];
                        const sortedBins = Object.keys(distanceBins).map(Number).sort((a, b) => a - b);

                        sortedBins.forEach(dist => {
                            const points = distanceBins[dist];
                            if (points.length < 2) return;

                            const bearings = points.map(p => p.bearing).sort((a, b) => a - b);
                            const medianBearing = bearings[Math.floor(bearings.length / 2)];

                            const normalized = bearings.map(b => {
                                let diff = b - medianBearing;
                                if (diff > 180) diff -= 360;
                                if (diff < -180) diff += 360;
                                return diff;
                            }).sort((a, b) => a - b);

                            const p75Hi = Math.min(Math.ceil(normalized.length * 0.875) - 1, normalized.length - 1);
                            const p75Lo = Math.max(Math.floor(normalized.length * 0.125), 0);
                            const p90Hi = Math.min(Math.ceil(normalized.length * 0.95) - 1, normalized.length - 1);
                            const p90Lo = Math.max(Math.floor(normalized.length * 0.05), 0);

                            const width75 = Math.max(3, Math.max(Math.abs(normalized[p75Hi] || 0), Math.abs(normalized[p75Lo] || 0)));
                            const width90 = Math.max(5, Math.max(Math.abs(normalized[p90Hi] || 0), Math.abs(normalized[p90Lo] || 0)));

                            const centerPoint = pointAtBearing(fixLon, fixLat, medianBearing, dist);

                            centerlinePoints.push({
                                dist,
                                coords: centerPoint,
                                bearing: medianBearing,
                                width75,
                                width90,
                                trackCount: points.length,
                            });
                        });

                        // ───────────────────────────────────────────────────────────
                        // OUTLIER REJECTION: Replace bearings that deviate wildly from
                        // the stream's overall direction. Sparse bins can produce extreme
                        // outliers (e.g., 259° when stream flows at 170°) that distort
                        // the cone even after smoothing.
                        // ───────────────────────────────────────────────────────────
                        if (centerlinePoints.length >= 3) {
                            const streamBearing = stream.bearing;
                            const MAX_DEVIATION = 60; // degrees from stream bearing

                            for (let i = 0; i < centerlinePoints.length; i++) {
                                const cp = centerlinePoints[i];
                                const dev = Math.abs(angularDiff(cp.bearing, streamBearing));
                                if (dev > MAX_DEVIATION) {
                                    // Find nearest non-outlier neighbors for interpolation
                                    let prevGood = null, nextGood = null;
                                    for (let j = i - 1; j >= 0; j--) {
                                        if (Math.abs(angularDiff(centerlinePoints[j].bearing, streamBearing)) <= MAX_DEVIATION) {
                                            prevGood = centerlinePoints[j];
                                            break;
                                        }
                                    }
                                    for (let j = i + 1; j < centerlinePoints.length; j++) {
                                        if (Math.abs(angularDiff(centerlinePoints[j].bearing, streamBearing)) <= MAX_DEVIATION) {
                                            nextGood = centerlinePoints[j];
                                            break;
                                        }
                                    }

                                    let newBearing;
                                    if (prevGood && nextGood) {
                                        // Circular mean of nearest good neighbors
                                        const rad1 = prevGood.bearing * Math.PI / 180;
                                        const rad2 = nextGood.bearing * Math.PI / 180;
                                        newBearing = ((Math.atan2(
                                            Math.sin(rad1) + Math.sin(rad2),
                                            Math.cos(rad1) + Math.cos(rad2)
                                        ) * 180 / Math.PI) + 360) % 360;
                                    } else if (prevGood) {
                                        newBearing = prevGood.bearing;
                                    } else if (nextGood) {
                                        newBearing = nextGood.bearing;
                                    } else {
                                        newBearing = streamBearing;
                                    }

                                    console.log(`Outlier rejected at ${cp.dist}nm: ${cp.bearing.toFixed(1)}° → ${newBearing.toFixed(1)}° (stream: ${streamBearing.toFixed(1)}°)`);
                                    cp.bearing = newBearing;
                                    cp.coords = pointAtBearing(fixLon, fixLat, newBearing, cp.dist);
                                }
                            }
                        }

                        if (centerlinePoints.length >= 2) {
                            // ───────────────────────────────────────────────────────────
                            // SMOOTHING: Apply weighted moving average to reduce jagged edges
                            // Uses a Gaussian-like weighting with window size 3
                            // ───────────────────────────────────────────────────────────
                            const smoothCenterline = (points) => {
                                if (points.length < 3) return points;

                                const WINDOW = 3; // Number of neighbors on each side
                                const smoothed = [];

                                for (let i = 0; i < points.length; i++) {
                                    const cp = points[i];

                                    // Gather neighbors for averaging (handle edges)
                                    const neighbors = [];
                                    for (let j = Math.max(0, i - WINDOW); j <= Math.min(points.length - 1, i + WINDOW); j++) {
                                        // Weight: closer neighbors have more influence
                                        const dist = Math.abs(j - i);
                                        const weight = 1 / (1 + dist);
                                        neighbors.push({ cp: points[j], weight });
                                    }

                                    const totalWeight = neighbors.reduce((sum, n) => sum + n.weight, 0);

                                    // Smooth bearing using circular mean (handles wraparound)
                                    let sinSum = 0, cosSum = 0;
                                    neighbors.forEach(n => {
                                        const rad = n.cp.bearing * Math.PI / 180;
                                        sinSum += Math.sin(rad) * n.weight;
                                        cosSum += Math.cos(rad) * n.weight;
                                    });
                                    const smoothBearing = ((Math.atan2(sinSum, cosSum) * 180 / Math.PI) + 360) % 360;

                                    // Smooth widths (simple weighted average)
                                    const smoothWidth75 = neighbors.reduce((sum, n) => sum + n.cp.width75 * n.weight, 0) / totalWeight;
                                    const smoothWidth90 = neighbors.reduce((sum, n) => sum + n.cp.width90 * n.weight, 0) / totalWeight;

                                    // Recompute coords from smoothed bearing
                                    const smoothCoords = pointAtBearing(fixLon, fixLat, smoothBearing, cp.dist);

                                    smoothed.push({
                                        dist: cp.dist,
                                        coords: smoothCoords,
                                        bearing: smoothBearing,
                                        width75: smoothWidth75,
                                        width90: smoothWidth90,
                                        trackCount: cp.trackCount,
                                    });
                                }

                                return smoothed;
                            };

                            // Apply smoothing twice for extra smoothness
                            const smoothedPoints = smoothCenterline(smoothCenterline(centerlinePoints));

                            // ───────────────────────────────────────────────────────────
                            // MONOTONIC CONVERGENCE: Ensure cone narrows toward fix
                            // Process from farthest to nearest, cap width at previous
                            // ───────────────────────────────────────────────────────────
                            const enforceMonotonic = (points) => {
                                if (points.length < 2) return points;

                                // Sort by distance descending (farthest first)
                                const sorted = [...points].sort((a, b) => b.dist - a.dist);

                                // Walk from farthest to nearest, cap widths
                                let maxWidth75 = sorted[0].width75;
                                let maxWidth90 = sorted[0].width90;

                                sorted.forEach(cp => {
                                    // Width can never exceed the width at farther distances
                                    cp.width75 = Math.min(cp.width75, maxWidth75);
                                    cp.width90 = Math.min(cp.width90, maxWidth90);
                                    maxWidth75 = cp.width75;
                                    maxWidth90 = cp.width90;
                                });

                                // Return sorted by distance ascending (for polygon building)
                                return sorted.sort((a, b) => a.dist - b.dist);
                            };

                            const convergentPoints = enforceMonotonic(smoothedPoints);

                            // Build buffer polygon for this stream using smoothed points
                            const buildBufferPolygon = (points, widthKey) => {
                                const leftEdge = [];
                                const rightEdge = [];

                                points.forEach(cp => {
                                    const halfWidth = cp[widthKey];
                                    const leftBearing = (cp.bearing + 90) % 360;
                                    const rightBearing = (cp.bearing - 90 + 360) % 360;
                                    const linearWidth = cp.dist * Math.sin(halfWidth * Math.PI / 180);

                                    leftEdge.push(pointAtBearing(cp.coords[0], cp.coords[1], leftBearing, linearWidth));
                                    rightEdge.push(pointAtBearing(cp.coords[0], cp.coords[1], rightBearing, linearWidth));
                                });

                                const polygon = [[fixLon, fixLat]];
                                rightEdge.forEach(pt => polygon.push(pt));
                                leftEdge.reverse().forEach(pt => polygon.push(pt));
                                polygon.push([fixLon, fixLat]);

                                return polygon;
                            };

                            allStreamCones.push({
                                streamIdx,
                                polygon_75: buildBufferPolygon(convergentPoints, 'width75'),
                                polygon_90: buildBufferPolygon(convergentPoints, 'width90'),
                                bearing: stream.bearing,
                                trackCount: stream.trackCount,
                                centerlinePoints: convergentPoints
                            });
                        }
                    });

                    // ═══════════════════════════════════════════════════════════════════════
                    // STEP 4: Store results (backwards compatible + multi-stream)
                    // ═══════════════════════════════════════════════════════════════════════
                    if (allStreamCones.length > 0) {
                        // For backwards compatibility, use first/largest stream for single-cone rendering
                        const primaryStream = allStreamCones[0];
                        const avgWidth75 = primaryStream.centerlinePoints.reduce((sum, cp) => sum + cp.width75, 0) / primaryStream.centerlinePoints.length;
                        const avgWidth90 = primaryStream.centerlinePoints.reduce((sum, cp) => sum + cp.width90, 0) / primaryStream.centerlinePoints.length;

                        TMICompliance.trafficSectorCache = TMICompliance.trafficSectorCache || {};
                        TMICompliance.trafficSectorCache[mapId] = {
                            fix_point: [fixLon, fixLat],
                            // Multi-stream data
                            streams: allStreamCones,
                            stream_count: allStreamCones.length,
                            // Primary stream for backwards compatibility
                            centerline: primaryStream.centerlinePoints,
                            polygon_75: primaryStream.polygon_75,
                            polygon_90: primaryStream.polygon_90,
                            sector_75: {
                                start_bearing: ((primaryStream.bearing - avgWidth75) + 360) % 360,
                                end_bearing: ((primaryStream.bearing + avgWidth75) + 360) % 360,
                                width_deg: avgWidth75 * 2,
                            },
                            sector_90: {
                                start_bearing: ((primaryStream.bearing - avgWidth90) + 360) % 360,
                                end_bearing: ((primaryStream.bearing + avgWidth90) + 360) % 360,
                                width_deg: avgWidth90 * 2,
                            },
                            track_count: Object.keys(trajectories).length,
                            required_spacing: requiredSpacing,
                            unit: tmiMeta.unit || 'nm',
                            max_distance: maxDistance,
                            use_centerline: true,
                            multi_stream: allStreamCones.length > 1,
                        };

                        console.log(`Computed ${allStreamCones.length} stream cones from ${Object.keys(trajectories).length} tracks`);
                        allStreamCones.forEach((sc, i) => console.log(`  Cone ${i+1}: bearing=${sc.bearing.toFixed(1)}°, tracks=${sc.trackCount}, pts=${sc.centerlinePoints.length}`));
                    }
            }

            // Add traffic flow sectors (75% and 90% capture zones)
            const sectorData = TMICompliance.trafficSectorCache?.[mapId];
            console.log(`Flow cone rendering for ${mapId}:`, sectorData ? {
                has_fix_point: !!sectorData.fix_point,
                fix_point: sectorData.fix_point,
                has_sector_75: !!sectorData.sector_75,
                has_sector_90: !!sectorData.sector_90,
                use_centerline: sectorData.use_centerline,
                has_streams: !!sectorData.streams?.length,
            } : 'NO SECTOR DATA');
            if (sectorData) {
                const sectorFeatures = [];
                // Sector radius: at least 30nm, or enough to show 3 spacing arcs
                const spacing = sectorData.required_spacing || 15;
                const SECTOR_RADIUS_NM = Math.max(30, spacing * 3);

                // Helper: compute point at given bearing and distance from origin
                const pointAtBearing = (lon, lat, bearingDeg, distanceNm) => {
                    const R = 3440.065; // Earth radius in nm
                    const bearing = bearingDeg * Math.PI / 180;
                    const lat1 = lat * Math.PI / 180;
                    const lon1 = lon * Math.PI / 180;
                    const d = distanceNm / R;

                    const lat2 = Math.asin(Math.sin(lat1) * Math.cos(d) + Math.cos(lat1) * Math.sin(d) * Math.cos(bearing));
                    const lon2 = lon1 + Math.atan2(Math.sin(bearing) * Math.sin(d) * Math.cos(lat1),
                        Math.cos(d) - Math.sin(lat1) * Math.sin(lat2));

                    return [lon2 * 180 / Math.PI, lat2 * 180 / Math.PI];
                };

                // Build sector polygon: vertex at fix, arc points extend upstream
                const buildSectorPolygon = (sector, radius) => {
                    const [originLon, originLat] = sectorData.fix_point; // Cone centered at fix
                    const coords = [[originLon, originLat]]; // Start at vertex (fix)

                    // Generate arc points from start to end bearing
                    const startBearing = sector.start_bearing;
                    let endBearing = sector.end_bearing;

                    // Handle wrap-around
                    if (endBearing < startBearing) {endBearing += 360;}

                    const arcPoints = Math.max(10, Math.ceil(sector.width_deg / 3)); // ~3 degrees per point
                    for (let i = 0; i <= arcPoints; i++) {
                        const bearing = startBearing + (endBearing - startBearing) * i / arcPoints;
                        coords.push(pointAtBearing(originLon, originLat, bearing % 360, radius));
                    }

                    coords.push([originLon, originLat]); // Close polygon back to vertex
                    return coords;
                };

                // Use centerline-based polygons if available, otherwise fall back to wedge
                if (sectorData.use_centerline && sectorData.streams?.length > 0) {
                    // Multi-stream: draw separate cones for each stream
                    // Skip degenerate cones with too few centerline points (< 5) to render meaningfully
                    sectorData.streams.forEach((stream, idx) => {
                        if (stream.centerlinePoints?.length < 5) {
                            console.log(`  Skipping stream ${idx + 1}: only ${stream.centerlinePoints?.length || 0} centerline pts (need ≥5)`);
                            return;
                        }
                        if (stream.polygon_90) {
                            sectorFeatures.push({
                                type: 'Feature',
                                properties: { pct: 90, streamIdx: idx, trackCount: stream.trackCount },
                                geometry: { type: 'Polygon', coordinates: [stream.polygon_90] },
                            });
                        }
                        if (stream.polygon_75) {
                            sectorFeatures.push({
                                type: 'Feature',
                                properties: { pct: 75, streamIdx: idx, trackCount: stream.trackCount },
                                geometry: { type: 'Polygon', coordinates: [stream.polygon_75] },
                            });
                        }
                    });
                    console.log(`Rendering ${sectorData.streams.length} stream cones`);
                } else if (sectorData.use_centerline && sectorData.polygon_90 && sectorData.polygon_75) {
                    // Single-stream centerline-following buffer polygons
                    sectorFeatures.push({
                        type: 'Feature',
                        properties: { pct: 90 },
                        geometry: { type: 'Polygon', coordinates: [sectorData.polygon_90] },
                    });
                    sectorFeatures.push({
                        type: 'Feature',
                        properties: { pct: 75 },
                        geometry: { type: 'Polygon', coordinates: [sectorData.polygon_75] },
                    });
                } else {
                    // Legacy wedge-based polygons
                    // Python's traffic_sector uses track HEADINGS (direction of flight).
                    // Flow cone should show APPROACH direction (where traffic comes from),
                    // so we flip the sector bearings by 180°.
                    const flipSector = (sector) => ({
                        start_bearing: (sector.start_bearing + 180) % 360,
                        end_bearing: (sector.end_bearing + 180) % 360,
                        width_deg: sector.width_deg,
                    });
                    try {
                        if (!sectorData.fix_point) {
                            console.error(`Flow cone error: fix_point is missing for ${mapId}`);
                        } else {
                            sectorFeatures.push({
                                type: 'Feature',
                                properties: { pct: 90 },
                                geometry: { type: 'Polygon', coordinates: [buildSectorPolygon(flipSector(sectorData.sector_90), SECTOR_RADIUS_NM)] },
                            });
                            sectorFeatures.push({
                                type: 'Feature',
                                properties: { pct: 75 },
                                geometry: { type: 'Polygon', coordinates: [buildSectorPolygon(flipSector(sectorData.sector_75), SECTOR_RADIUS_NM)] },
                            });
                        }
                    } catch (err) {
                        console.error(`Flow cone rendering error for ${mapId}:`, err, sectorData);
                    }
                }

                map.addSource('traffic-sectors', {
                    type: 'geojson',
                    data: { type: 'FeatureCollection', features: sectorFeatures },
                });

                // Insert below flight tracks if they exist
                const beforeLayer = map.getLayer('flight-tracks-solid-glow') ? 'flight-tracks-solid-glow' : undefined;

                // Use centralized colors from FILTER_CONFIG with fallbacks
                const coneColors = FILTER_CONFIG?.map?.flowCone || {
                    '75': { fill: 'rgba(255,212,59,0.15)', stroke: '#ffd43b', strokeWidth: 2 },
                    '90': { fill: 'rgba(255,146,43,0.10)', stroke: '#ff922b', strokeWidth: 1 },
                };

                // Sector fills
                map.addLayer({
                    id: 'traffic-sectors-fill',
                    type: 'fill',
                    source: 'traffic-sectors',
                    paint: {
                        'fill-color': ['case', ['==', ['get', 'pct'], 75], coneColors['75'].stroke, coneColors['90'].stroke],
                        'fill-opacity': ['case', ['==', ['get', 'pct'], 75], 0.15, 0.1],
                    },
                }, beforeLayer);

                // Sector outlines
                map.addLayer({
                    id: 'traffic-sectors-outline',
                    type: 'line',
                    source: 'traffic-sectors',
                    paint: {
                        'line-color': ['case', ['==', ['get', 'pct'], 75], coneColors['75'].stroke, coneColors['90'].stroke],
                        'line-width': ['case', ['==', ['get', 'pct'], 75], coneColors['75'].strokeWidth || 2, coneColors['90'].strokeWidth || 1],
                        'line-opacity': 0.6,
                    },
                }, beforeLayer);

                console.log(`Added flow cone layers for ${mapId}: ${sectorFeatures.length} features`);

                // Add spacing markers (perpendicular lines for centerline, arcs for wedge)
                if (sectorData.required_spacing && sectorData.required_spacing > 0 && sectorData.unit === 'nm') {
                    const arcFeatures = [];
                    const labelFeatures = [];
                    const [originLon, originLat] = sectorData.fix_point;
                    // Python's sector bearings are in direction of flight; arcs should be
                    // on the approach side (where traffic comes from), so flip by 180°
                    // when using legacy wedge arcs. Centerline bearings are already correct.
                    const rawSector = sectorData.sector_90;
                    const sector = sectorData.use_centerline ? rawSector : {
                        start_bearing: (rawSector.start_bearing + 180) % 360,
                        end_bearing: (rawSector.end_bearing + 180) % 360,
                        width_deg: rawSector.width_deg,
                    };

                    // Use centerline-following perpendicular lines if available
                    if (sectorData.use_centerline && sectorData.centerline?.length > 0) {
                        // Build perpendicular crossing line at a centerline point
                        const buildCrossing = (centerlinePoint) => {
                            const { coords, bearing, width90, dist } = centerlinePoint;
                            // Convert angular width to linear distance at this range
                            const linearWidth = dist * Math.sin(width90 * Math.PI / 180);
                            // Perpendicular bearings (±90° from centerline direction)
                            const leftBearing = (bearing + 90) % 360;
                            const rightBearing = (bearing - 90 + 360) % 360;

                            const leftPt = pointAtBearing(coords[0], coords[1], leftBearing, linearWidth);
                            const rightPt = pointAtBearing(coords[0], coords[1], rightBearing, linearWidth);

                            return {
                                coords: [leftPt, coords, rightPt],
                                labelPoints: [leftPt, coords, rightPt],
                            };
                        };

                        // Generate crossings at spacing intervals
                        const minCrossings = 3;
                        const maxDist = sectorData.max_distance || Math.max(SECTOR_RADIUS_NM, spacing * minCrossings);

                        for (let targetDist = spacing; targetDist <= maxDist; targetDist += spacing) {
                            // Find closest centerline point to this distance
                            let closestCp = null;
                            let closestDiff = Infinity;
                            sectorData.centerline.forEach(cp => {
                                const diff = Math.abs(cp.dist - targetDist);
                                if (diff < closestDiff) {
                                    closestDiff = diff;
                                    closestCp = cp;
                                }
                            });

                            if (closestCp && closestDiff < spacing / 2) {
                                const { coords, labelPoints } = buildCrossing(closestCp);
                                arcFeatures.push({
                                    type: 'Feature',
                                    properties: { distance: targetDist },
                                    geometry: { type: 'LineString', coordinates: coords },
                                });

                                // Add label at center of crossing
                                labelFeatures.push({
                                    type: 'Feature',
                                    properties: { label: `${targetDist}nm` },
                                    geometry: { type: 'Point', coordinates: labelPoints[1] },
                                });
                            }
                        }
                    } else {
                        // Legacy: radial arcs from fix point
                        const buildArc = (radius) => {
                            const coords = [];
                            const startBearing = sector.start_bearing;
                            let endBearing = sector.end_bearing;
                            if (endBearing < startBearing) endBearing += 360;

                            const arcPoints = Math.max(10, Math.ceil(sector.width_deg / 3));
                            for (let i = 0; i <= arcPoints; i++) {
                                const bearing = startBearing + (endBearing - startBearing) * i / arcPoints;
                                coords.push(pointAtBearing(originLon, originLat, bearing % 360, radius));
                            }

                            const midBearing = (startBearing + endBearing) / 2;
                            const startPt = pointAtBearing(originLon, originLat, startBearing % 360, radius);
                            const midPt = pointAtBearing(originLon, originLat, midBearing % 360, radius);
                            const endPt = pointAtBearing(originLon, originLat, endBearing % 360, radius);

                            return { coords, labelPoints: [startPt, midPt, endPt] };
                        };

                        const minArcs = 3;
                        const maxDist = Math.max(SECTOR_RADIUS_NM, spacing * minArcs);
                        for (let dist = spacing; dist <= maxDist; dist += spacing) {
                            const { coords, labelPoints } = buildArc(dist);
                            arcFeatures.push({
                                type: 'Feature',
                                properties: { distance: dist },
                                geometry: { type: 'LineString', coordinates: coords },
                            });

                            labelPoints.forEach(pt => {
                                labelFeatures.push({
                                    type: 'Feature',
                                    properties: { label: `${dist}nm` },
                                    geometry: { type: 'Point', coordinates: pt },
                                });
                            });
                        }
                    }

                    if (arcFeatures.length > 0) {
                        // Use centralized spacing colors from FILTER_CONFIG
                        const spacingColors = FILTER_CONFIG?.map?.spacing || {
                            line: '#ffffff',
                            lineOpacity: 0.6,
                            label: '#ffffff',
                            labelHalo: '#000000',
                        };

                        map.addSource('spacing-arcs', {
                            type: 'geojson',
                            data: { type: 'FeatureCollection', features: arcFeatures },
                        });

                        map.addLayer({
                            id: 'spacing-arcs',
                            type: 'line',
                            source: 'spacing-arcs',
                            paint: {
                                'line-color': spacingColors.line,
                                'line-width': 1,
                                'line-opacity': spacingColors.lineOpacity || 0.6,
                                'line-dasharray': [2, 2],
                            },
                        }, beforeLayer);

                        // Add spacing arc labels
                        map.addSource('spacing-arc-labels', {
                            type: 'geojson',
                            data: { type: 'FeatureCollection', features: labelFeatures },
                        });

                        map.addLayer({
                            id: 'spacing-arc-labels',
                            type: 'symbol',
                            source: 'spacing-arc-labels',
                            layout: {
                                'text-field': ['get', 'label'],
                                'text-size': 11,
                                'text-anchor': 'center',
                                'text-allow-overlap': true,
                            },
                            paint: {
                                'text-color': spacingColors.label,
                                'text-halo-color': spacingColors.labelHalo,
                                'text-halo-width': 1.5,
                            },
                        }, beforeLayer);

                        console.log(`Added ${arcFeatures.length} spacing arcs at ${spacing}nm intervals with labels`);
                    }
                }

                console.log(`Added traffic sectors: 75% (${sectorData.sector_75.width_deg}°), 90% (${sectorData.sector_90.width_deg}°)`);
            }

            // Add measurement point emphasis (pulsing marker at fix location)
            // Try mapData.fixes first, then fallback to traffic_sector.measurement_point
            let measurementCoords = null;
            let measurementName = 'Measurement Point';

            if (mapData.fixes?.length) {
                const measurementFix = mapData.fixes[0];
                if (measurementFix?.geometry?.coordinates) {
                    measurementCoords = measurementFix.geometry.coordinates;
                    measurementName = measurementFix.properties?.name || 'Measurement Point';
                }
            }

            // Fallback: use traffic_sector.measurement_point from analysis
            if (!measurementCoords && sectorData?.measurement_point) {
                measurementCoords = sectorData.measurement_point;  // [lon, lat]
                measurementName = 'Measurement Point';
                console.log(`Using traffic_sector measurement_point: [${measurementCoords.join(', ')}]`);
            }

            if (measurementCoords) {
                const [lon, lat] = measurementCoords;

                // Create pulsing ring effect
                map.addSource('measurement-point', {
                    type: 'geojson',
                    data: {
                        type: 'Feature',
                        geometry: { type: 'Point', coordinates: [lon, lat] },
                        properties: { name: measurementName },
                    },
                });

                // Outer ring (subtle)
                map.addLayer({
                    id: 'measurement-pulse',
                    type: 'circle',
                    source: 'measurement-point',
                    paint: {
                        'circle-radius': 12,
                        'circle-color': 'transparent',
                        'circle-stroke-width': 1.5,
                        'circle-stroke-color': '#ffffff',
                        'circle-stroke-opacity': 0.5,
                    },
                });

                // Inner marker (smaller, more subtle)
                map.addLayer({
                    id: 'measurement-center',
                    type: 'circle',
                    source: 'measurement-point',
                    paint: {
                        'circle-radius': 4,
                        'circle-color': '#ffffff',
                        'circle-opacity': 0.8,
                        'circle-stroke-width': 1,
                        'circle-stroke-color': '#333333',
                    },
                });

                console.log(`Added measurement point marker at ${measurementName}`);
            }

            // Show layer controls
            const controls = document.getElementById(`${mapId}_controls`);
            if (controls) {
                controls.style.display = 'flex';

                // Update button states based on what layers actually exist
                const updateButtonState = (layer, hasData) => {
                    const btn = controls.querySelector(`.layer-btn[data-layer="${layer}"]`);
                    if (btn) {
                        btn.style.display = hasData ? '' : 'none';
                    }
                };

                // Check which layers have data
                updateButtonState('artcc', mapData.facilities?.some(f => f.properties.type === 'ARTCC'));
                updateButtonState('tracon', mapData.facilities?.some(f => f.properties.type === 'TRACON'));
                updateButtonState('sectors-low', map.getSource('sectors-low'));
                updateButtonState('sectors-high', map.getSource('sectors-high'));
                updateButtonState('sectors-superhigh', map.getSource('sectors-superhigh'));
                updateButtonState('tracks', map.getSource('flight-tracks-solid') || map.getSource('flight-tracks-dashed'));
                updateButtonState('flow-streams', map.getSource('flow-streams'));
                updateButtonState('traffic-sectors', map.getSource('traffic-sectors'));

                // Show branches button if branch corridor data is available
                const branchBtn = controls.querySelector('.branch-toggle-btn');
                if (branchBtn && TMICompliance.branchCorridorCache[mapId]) {
                    branchBtn.style.display = '';
                }
            }

            // Fit bounds
            if (mapData.bounds) {
                map.fitBounds(mapData.bounds, { padding: 30, maxZoom: 8 });
            }
        });
    },

    /**
     * Smooth trajectory using Catmull-Rom spline interpolation
     * Creates natural-looking curves through waypoints
     */
    smoothTrajectory: function(coords, segments = 5) {
        if (!coords || coords.length < 2) {return coords;}
        if (coords.length === 2) {return coords;}

        const result = [];
        const n = coords.length;

        // Add first point
        result.push(coords[0]);

        for (let i = 0; i < n - 1; i++) {
            // Get four control points (P0, P1, P2, P3)
            const p0 = coords[Math.max(0, i - 1)];
            const p1 = coords[i];
            const p2 = coords[Math.min(n - 1, i + 1)];
            const p3 = coords[Math.min(n - 1, i + 2)];

            // Interpolate between P1 and P2 using Catmull-Rom
            for (let j = 1; j <= segments; j++) {
                const t = j / segments;
                const t2 = t * t;
                const t3 = t2 * t;

                // Catmull-Rom spline formula
                const lon = 0.5 * (
                    (2 * p1[0]) +
                    (-p0[0] + p2[0]) * t +
                    (2*p0[0] - 5*p1[0] + 4*p2[0] - p3[0]) * t2 +
                    (-p0[0] + 3*p1[0] - 3*p2[0] + p3[0]) * t3
                );
                const lat = 0.5 * (
                    (2 * p1[1]) +
                    (-p0[1] + p2[1]) * t +
                    (2*p0[1] - 5*p1[1] + 4*p2[1] - p3[1]) * t2 +
                    (-p0[1] + 3*p1[1] - 3*p2[1] + p3[1]) * t3
                );

                result.push([lon, lat]);
            }
        }

        return result;
    },

    /**
     * Load facility boundaries from local GeoJSON files
     * Fallback when GIS API doesn't have boundary data
     */
    loadLocalFacilityBoundaries: async function(requestor, provider) {
        const facilities = [];

        // Detect facility type from code
        const detectType = (code) => {
            if (!code) {return null;}
            // ARTCC codes start with Z (ZNY, ZDC, ZBW, etc.)
            if (/^Z[A-Z]{2}$/.test(code)) {return 'ARTCC';}
            // TRACON codes: letter + 2 digits (N90, A80, etc.) or 3 letters (PCT, SCT)
            if (/^[A-Z]\d{2}$/.test(code) || /^[A-Z]{3}$/.test(code)) {return 'TRACON';}
            return null;
        };

        const requestorType = detectType(requestor);
        const providerType = detectType(provider);

        try {
            // Load ARTCC boundaries if needed
            if (requestorType === 'ARTCC' || providerType === 'ARTCC') {
                const artccResponse = await fetch('assets/geojson/artcc.json');
                if (artccResponse.ok) {
                    const artccData = await artccResponse.json();
                    const artccCodes = [];
                    if (requestorType === 'ARTCC') {artccCodes.push({ code: requestor, role: 'requestor' });}
                    if (providerType === 'ARTCC' && provider !== requestor) {artccCodes.push({ code: provider, role: 'provider' });}

                    for (const { code, role } of artccCodes) {
                        // ARTCC GeoJSON uses ICAO codes (KZNY instead of ZNY)
                        const icaoCode = (typeof PERTI !== 'undefined' && PERTI.normalizeArtcc)
                            ? PERTI.normalizeArtcc(code) : 'K' + code;
                        const feature = artccData.features.find(f =>
                            f.properties.ICAOCODE === icaoCode || f.properties.ICAOCODE === code,
                        );
                        if (feature) {
                            facilities.push({
                                type: 'Feature',
                                properties: {
                                    code: code,
                                    name: feature.properties.FIRname || code,
                                    type: 'ARTCC',
                                    role: role,
                                },
                                geometry: feature.geometry,
                            });
                        }
                    }
                }
            }

            // Load TRACON boundaries if needed
            if (requestorType === 'TRACON' || providerType === 'TRACON') {
                const traconResponse = await fetch('assets/geojson/tracon.json');
                if (traconResponse.ok) {
                    const traconData = await traconResponse.json();
                    const traconCodes = [];
                    if (requestorType === 'TRACON') {traconCodes.push({ code: requestor, role: 'requestor' });}
                    if (providerType === 'TRACON' && provider !== requestor) {traconCodes.push({ code: provider, role: 'provider' });}

                    for (const { code, role } of traconCodes) {
                        // TRACON GeoJSON may have multiple features per sector (altitude layers)
                        // Collect all and merge into MultiPolygon
                        const features = traconData.features.filter(f => f.properties.sector === code);
                        if (features.length > 0) {
                            // Merge all geometries into one MultiPolygon
                            const allCoords = [];
                            features.forEach(f => {
                                if (f.geometry.type === 'Polygon') {
                                    allCoords.push(f.geometry.coordinates);
                                } else if (f.geometry.type === 'MultiPolygon') {
                                    allCoords.push(...f.geometry.coordinates);
                                }
                            });
                            facilities.push({
                                type: 'Feature',
                                properties: {
                                    code: code,
                                    name: features[0].properties.label || code,
                                    type: 'TRACON',
                                    role: role,
                                    label_lat: features[0].properties.label_lat,
                                    label_lon: features[0].properties.label_lon,
                                },
                                geometry: {
                                    type: 'MultiPolygon',
                                    coordinates: allCoords,
                                },
                            });
                        }
                    }
                }
            }
        } catch (err) {
            console.error('Error loading local facility boundaries:', err);
        }

        return facilities;
    },

    /**
     * Load sector boundaries from local GeoJSON files (high.json and low.json)
     * Returns sectors for the specified ARTCC codes
     */
    loadLocalSectorBoundaries: async function(artccCodes, providerCode) {
        const sectors = [];
        if (!artccCodes || artccCodes.length === 0) {return sectors;}

        // Normalize codes to lowercase for matching
        const normalizedCodes = artccCodes.map(c => c.toLowerCase());
        const providerLower = providerCode ? providerCode.toLowerCase() : null;

        try {
            // Load both high and low altitude sector files
            const [highResponse, lowResponse, superhighResponse] = await Promise.all([
                fetch('assets/geojson/high.json').catch(() => null),
                fetch('assets/geojson/low.json').catch(() => null),
                fetch('assets/geojson/superhigh.json').catch(() => null),
            ]);

            const processFile = async (response, altitudeType) => {
                if (!response || !response.ok) {return;}
                const data = await response.json();

                for (const feature of data.features) {
                    const artcc = feature.properties.artcc;
                    if (!artcc || !normalizedCodes.includes(artcc.toLowerCase())) {continue;}

                    // Determine if this sector belongs to provider
                    const isProvider = providerLower && artcc.toLowerCase() === providerLower;

                    sectors.push({
                        type: 'Feature',
                        properties: {
                            code: feature.properties.label || `${artcc.toUpperCase()}${feature.properties.sector}`,
                            sector: feature.properties.sector,
                            artcc: artcc.toUpperCase(),
                            type: 'SECTOR',
                            altitude: altitudeType,
                            role: isProvider ? 'provider' : 'other',
                        },
                        geometry: feature.geometry,
                    });
                }
            };

            await Promise.all([
                processFile(highResponse, 'high'),
                processFile(lowResponse, 'low'),
                processFile(superhighResponse, 'superhigh'),
            ]);

            // Group by altitude for logging
            const byCat = { low: 0, high: 0, superhigh: 0 };
            sectors.forEach(s => byCat[s.properties.altitude]++);
            console.log(`Loaded ${sectors.length} sectors for ARTCCs ${artccCodes.join(', ')}: ${byCat.low} low, ${byCat.high} high, ${byCat.superhigh} superhigh`);
        } catch (err) {
            console.error('Error loading sector boundaries:', err);
        }

        return sectors;
    },

    /**
     * Calculate bounding box from map data (facilities, fixes, airports)
     */
    calculateBounds: function(mapData) {
        let minLon = 180, maxLon = -180, minLat = 90, maxLat = -90;
        let hasData = false;

        const expandBounds = (coords) => {
            if (Array.isArray(coords[0])) {
                coords.forEach(c => expandBounds(c));
            } else {
                const [lon, lat] = coords;
                if (typeof lon === 'number' && typeof lat === 'number') {
                    minLon = Math.min(minLon, lon);
                    maxLon = Math.max(maxLon, lon);
                    minLat = Math.min(minLat, lat);
                    maxLat = Math.max(maxLat, lat);
                    hasData = true;
                }
            }
        };

        // Include facilities
        (mapData.facilities || []).forEach(f => {
            if (f.geometry?.coordinates) {expandBounds(f.geometry.coordinates);}
        });

        // Include fixes
        (mapData.fixes || []).forEach(f => {
            if (f.geometry?.coordinates) {expandBounds(f.geometry.coordinates);}
        });

        // Include airports
        (mapData.airports || []).forEach(f => {
            if (f.geometry?.coordinates) {expandBounds(f.geometry.coordinates);}
        });

        return hasData ? [minLon, minLat, maxLon, maxLat] : null;
    },

    showMapError: function(mapId, message) {
        const container = document.getElementById(`${mapId}_container`);
        if (container) {
            container.innerHTML = `<div class="text-center text-danger py-4"><i class="fas fa-exclamation-triangle mr-2"></i>${message}</div>`;
        }
    },

    /**
     * Toggle map layer visibility
     */
    toggleLayer: function(btn) {
        const layer = btn.dataset.layer;
        const mapId = btn.dataset.map;
        const map = this.activeMaps[mapId];
        if (!map) {return;}

        const isActive = btn.classList.toggle('active');

        // Map layer names to actual map layer IDs
        const layerMappings = {
            'artcc': ['facilities-fill', 'facilities-outline', 'facilities-labels'].filter(id =>
                map.getLayer(id) && map.getSource('facilities')?.serialize()?.data?.features?.some(f => f.properties.type === 'ARTCC'),
            ),
            'tracon': ['facilities-fill', 'facilities-outline', 'facilities-labels'].filter(id =>
                map.getLayer(id) && map.getSource('facilities')?.serialize()?.data?.features?.some(f => f.properties.type === 'TRACON'),
            ),
            'sectors-low': ['sectors-low-fill', 'sectors-low-outline', 'sectors-low-labels'],
            'sectors-high': ['sectors-high-fill', 'sectors-high-outline', 'sectors-high-labels'],
            'sectors-superhigh': ['sectors-superhigh-fill', 'sectors-superhigh-outline', 'sectors-superhigh-labels'],
            'tracks': ['flight-tracks-solid-glow', 'flight-tracks-solid', 'flight-tracks-dashed'],
            'traffic-sectors': ['traffic-sectors-fill', 'traffic-sectors-outline', 'spacing-arcs', 'spacing-arc-labels'],
            'flow-streams': ['flow-streams-fill', 'flow-streams-outline', 'flow-stream-labels', 'flow-merge-zones-fill', 'flow-merge-zones-outline'],
            'pairs': ['pair-lines', 'pair-markers-prev', 'pair-markers-curr', 'pair-labels-prev', 'pair-labels-curr'],
            'violations': ['pair-lines', 'pair-markers-prev', 'pair-markers-curr', 'pair-labels-prev', 'pair-labels-curr'],
        };

        // Handle pairs/violations specially - they share the same source but filter differently
        if (layer === 'pairs' || layer === 'violations') {
            const pairData = this.pairCache?.[mapId];
            if (!pairData || pairData.length === 0) {
                console.log(`No pair data for ${mapId}`);
                btn.classList.remove('active');
                return;
            }

            // Determine filter: violations only shows UNDER, pairs shows all
            const filterToViolations = (layer === 'violations');
            const filteredPairs = filterToViolations
                ? pairData.filter(p => p.spacing_category === 'UNDER')
                : pairData;

            if (filteredPairs.length === 0) {
                console.log(`No ${layer} to display for ${mapId}`);
                btn.classList.remove('active');
                return;
            }

            // Toggle the other button off if turning this one on
            const otherLayer = layer === 'pairs' ? 'violations' : 'pairs';
            const otherBtn = document.querySelector(`.layer-btn[data-layer="${otherLayer}"][data-map="${mapId}"]`);
            if (isActive && otherBtn?.classList.contains('active')) {
                otherBtn.classList.remove('active');
            }

            if (!isActive) {
                // Hide the layers (including cluster layers)
                ['pair-lines', 'pair-markers-prev', 'pair-markers-curr', 'pair-labels-prev', 'pair-labels-curr', 'pair-clusters', 'pair-cluster-count'].forEach(layerId => {
                    if (map.getLayer(layerId)) {
                        map.setLayoutProperty(layerId, 'visibility', 'none');
                    }
                });
                return;
            }

            // Build GeoJSON for the pairs
            const pairGeoJSON = this.buildPairGeoJSON(filteredPairs);

            if (map.getSource('pair-data')) {
                // Update existing source
                map.getSource('pair-data').setData(pairGeoJSON.lines);
                map.getSource('pair-markers').setData(pairGeoJSON.markers);
                // Show layers (including cluster layers)
                ['pair-lines', 'pair-markers-prev', 'pair-markers-curr', 'pair-labels-prev', 'pair-labels-curr', 'pair-clusters', 'pair-cluster-count'].forEach(layerId => {
                    if (map.getLayer(layerId)) {
                        map.setLayoutProperty(layerId, 'visibility', 'visible');
                    }
                });
            } else {
                // Create sources and layers
                map.addSource('pair-data', { type: 'geojson', data: pairGeoJSON.lines });
                map.addSource('pair-markers', {
                    type: 'geojson',
                    data: pairGeoJSON.markers,
                    cluster: true,
                    clusterMaxZoom: 12,  // Disable clustering at zoom 13+
                    clusterRadius: 50,   // Cluster points within 50px
                });

                // Cluster circles - show aggregated markers at low zoom
                map.addLayer({
                    id: 'pair-clusters',
                    type: 'circle',
                    source: 'pair-markers',
                    filter: ['has', 'point_count'],
                    paint: {
                        'circle-color': [
                            'step', ['get', 'point_count'],
                            '#f28cb1', 10,   // Pink for < 10 points
                            '#f1a340', 25,   // Orange for < 25 points
                            '#d73027',       // Red for 25+ points
                        ],
                        'circle-radius': [
                            'step', ['get', 'point_count'],
                            18, 10,  // 18px for < 10 points
                            24, 25,  // 24px for < 25 points
                            30,      // 30px for 25+ points
                        ],
                        'circle-stroke-color': '#ffffff',
                        'circle-stroke-width': 2,
                    },
                });

                // Cluster count labels
                map.addLayer({
                    id: 'pair-cluster-count',
                    type: 'symbol',
                    source: 'pair-markers',
                    filter: ['has', 'point_count'],
                    layout: {
                        'text-field': '{point_count_abbreviated}',
                        'text-font': ['Open Sans Bold'],
                        'text-size': 12,
                    },
                    paint: {
                        'text-color': '#ffffff',
                    },
                });

                // Connecting lines - colored by spacing category
                map.addLayer({
                    id: 'pair-lines',
                    type: 'line',
                    source: 'pair-data',
                    paint: {
                        'line-color': ['get', 'color'],
                        'line-width': 2,
                        'line-opacity': 0.8,
                    },
                });

                // Aircraft markers - previous (leading) aircraft (only unclustered)
                map.addLayer({
                    id: 'pair-markers-prev',
                    type: 'circle',
                    source: 'pair-markers',
                    filter: ['all', ['!has', 'point_count'], ['==', 'position', 'prev']],
                    paint: {
                        'circle-radius': 6,
                        'circle-color': ['get', 'color'],
                        'circle-stroke-color': '#ffffff',
                        'circle-stroke-width': 2,
                    },
                });

                // Aircraft markers - current (trailing) aircraft (only unclustered)
                map.addLayer({
                    id: 'pair-markers-curr',
                    type: 'circle',
                    source: 'pair-markers',
                    filter: ['all', ['!has', 'point_count'], ['==', 'position', 'curr']],
                    paint: {
                        'circle-radius': 6,
                        'circle-color': ['get', 'color'],
                        'circle-stroke-color': '#ffffff',
                        'circle-stroke-width': 2,
                    },
                });

                // Labels for previous aircraft (only unclustered)
                // Use colored text with dark background halo for better readability
                map.addLayer({
                    id: 'pair-labels-prev',
                    type: 'symbol',
                    source: 'pair-markers',
                    filter: ['all', ['!has', 'point_count'], ['==', 'position', 'prev']],
                    minzoom: 8, // Only show labels when zoomed in enough
                    layout: {
                        'text-field': ['concat', ['get', 'callsign'], '\n', ['get', 'time']],
                        'text-font': ['Open Sans Bold'],
                        'text-size': 11,
                        'text-anchor': 'bottom',
                        'text-offset': [0, -0.8],
                        'text-allow-overlap': false,
                        'text-ignore-placement': false,
                    },
                    paint: {
                        'text-color': ['get', 'color'],
                        'text-halo-color': 'rgba(20, 20, 35, 0.9)',
                        'text-halo-width': 2,
                        'text-halo-blur': 0.5,
                    },
                });

                // Labels for current aircraft (only unclustered)
                map.addLayer({
                    id: 'pair-labels-curr',
                    type: 'symbol',
                    source: 'pair-markers',
                    filter: ['all', ['!has', 'point_count'], ['==', 'position', 'curr']],
                    minzoom: 8, // Only show labels when zoomed in enough
                    layout: {
                        'text-field': ['concat', ['get', 'callsign'], '\n', ['get', 'time']],
                        'text-font': ['Open Sans Bold'],
                        'text-size': 11,
                        'text-anchor': 'top',
                        'text-offset': [0, 0.8],
                        'text-allow-overlap': false,
                        'text-ignore-placement': false,
                    },
                    paint: {
                        'text-color': ['get', 'color'],
                        'text-halo-color': 'rgba(20, 20, 35, 0.9)',
                        'text-halo-width': 2,
                        'text-halo-blur': 0.5,
                    },
                });

                // Click on cluster to zoom in and expand
                map.on('click', 'pair-clusters', (e) => {
                    const features = map.queryRenderedFeatures(e.point, { layers: ['pair-clusters'] });
                    if (!features.length) return;
                    const clusterId = features[0].properties.cluster_id;
                    map.getSource('pair-markers').getClusterExpansionZoom(clusterId, (err, zoom) => {
                        if (err) return;
                        map.easeTo({
                            center: features[0].geometry.coordinates,
                            zoom: zoom,
                        });
                    });
                });

                // Pointer cursor on clusters
                map.on('mouseenter', 'pair-clusters', () => {
                    map.getCanvas().style.cursor = 'pointer';
                });
                map.on('mouseleave', 'pair-clusters', () => {
                    map.getCanvas().style.cursor = '';
                });
            }
            return;
        }

        // Handle ARTCC/TRACON specially since they share the facilities source
        if (layer === 'artcc' || layer === 'tracon') {
            // Use filter expressions to show/hide by type
            const facilityType = layer === 'artcc' ? 'ARTCC' : 'TRACON';
            const otherBtn = document.querySelector(`.layer-btn[data-layer="${layer === 'artcc' ? 'tracon' : 'artcc'}"][data-map="${mapId}"]`);
            const otherActive = otherBtn?.classList.contains('active');

            if (map.getLayer('facilities-fill')) {
                if (!isActive && !otherActive) {
                    // Hide all facilities
                    map.setLayoutProperty('facilities-fill', 'visibility', 'none');
                    map.setLayoutProperty('facilities-outline', 'visibility', 'none');
                    map.setLayoutProperty('facilities-labels', 'visibility', 'none');
                } else {
                    map.setLayoutProperty('facilities-fill', 'visibility', 'visible');
                    map.setLayoutProperty('facilities-outline', 'visibility', 'visible');
                    map.setLayoutProperty('facilities-labels', 'visibility', 'visible');

                    // Build filter based on which are active
                    const types = [];
                    if (btn.classList.contains('active') && layer === 'artcc') {types.push('ARTCC');}
                    if (btn.classList.contains('active') && layer === 'tracon') {types.push('TRACON');}
                    if (otherActive && layer !== 'artcc') {types.push('ARTCC');}
                    if (otherActive && layer !== 'tracon') {types.push('TRACON');}

                    const filter = types.length > 0 ? ['in', ['get', 'type'], ['literal', types]] : ['==', 1, 0];
                    map.setFilter('facilities-fill', filter);
                    map.setFilter('facilities-outline', filter);
                    map.setFilter('facilities-labels', filter);
                }
            }
        } else {
            // Standard layer visibility toggle
            const layerIds = layerMappings[layer] || [];
            layerIds.forEach(layerId => {
                if (map.getLayer(layerId)) {
                    map.setLayoutProperty(layerId, 'visibility', isActive ? 'visible' : 'none');
                }
            });
        }
    },

    /**
     * Build GeoJSON for pair visualization (connecting lines and markers)
     * @param {Array} pairs - Array of pair objects with crossing coordinates
     * @returns {Object} { lines: GeoJSON, markers: GeoJSON }
     */
    buildPairGeoJSON: function(pairs) {
        const lineFeatures = [];
        const markerFeatures = [];

        // Color mapping by spacing category
        const categoryColors = {
            'UNDER': '#dc3545',  // Red - violation
            'WITHIN': '#28a745', // Green - within tolerance
            'OVER': '#17a2b8',   // Cyan - over required
            'GAP': '#ffc107',    // Yellow - large gap
        };

        pairs.forEach((p, idx) => {
            // Skip if missing coordinate data
            if (!p.prev_crossing_lat || !p.prev_crossing_lon ||
                !p.curr_crossing_lat || !p.curr_crossing_lon) {
                return;
            }

            const color = categoryColors[p.spacing_category] || '#6c757d';

            // Format time as HHMMZ from "HH:MM:SSZ" format
            const formatTime = (timeStr) => {
                if (!timeStr) return '';
                const match = timeStr.match(/(\d{2}):(\d{2}):\d{2}Z/);
                if (match) {
                    return match[1] + match[2] + 'Z';
                }
                return timeStr.replace(/:/g, '').substring(0, 4) + 'Z';
            };

            // Line connecting the two aircraft positions
            lineFeatures.push({
                type: 'Feature',
                properties: {
                    pairIndex: idx,
                    spacing: p.spacing,
                    required: p.required,
                    category: p.spacing_category,
                    compliance: p.compliance,
                    color: color,
                    prev_callsign: p.prev_callsign,
                    curr_callsign: p.curr_callsign,
                },
                geometry: {
                    type: 'LineString',
                    coordinates: [
                        [p.prev_crossing_lon, p.prev_crossing_lat],
                        [p.curr_crossing_lon, p.curr_crossing_lat],
                    ],
                },
            });

            // Marker for previous (leading) aircraft
            markerFeatures.push({
                type: 'Feature',
                properties: {
                    pairIndex: idx,
                    position: 'prev',
                    callsign: p.prev_callsign,
                    time: formatTime(p.prev_time),
                    color: color,
                    category: p.spacing_category,
                },
                geometry: {
                    type: 'Point',
                    coordinates: [p.prev_crossing_lon, p.prev_crossing_lat],
                },
            });

            // Marker for current (trailing) aircraft
            markerFeatures.push({
                type: 'Feature',
                properties: {
                    pairIndex: idx,
                    position: 'curr',
                    callsign: p.curr_callsign,
                    time: formatTime(p.curr_time),
                    color: color,
                    category: p.spacing_category,
                },
                geometry: {
                    type: 'Point',
                    coordinates: [p.curr_crossing_lon, p.curr_crossing_lat],
                },
            });
        });

        return {
            lines: {
                type: 'FeatureCollection',
                features: lineFeatures,
            },
            markers: {
                type: 'FeatureCollection',
                features: markerFeatures,
            },
        };
    },

    cleanupMaps: function() {
        Object.values(this.activeMaps).forEach(map => { if (map) {map.remove();} });
        this.activeMaps = {};
    },

    // Data gap tracking
    dataGaps: null,
    dataGapsChecked: false,
    hourlyCounts: {},

    /**
     * Check for trajectory data gaps in the analysis time range
     */
    checkDataGaps: function(callback) {
        // Get event time range from results or form
        let startDate = this.results?.event_start || $('#tmi_event_start').val();
        let endDate = this.results?.event_end || $('#tmi_event_end').val();

        if (!startDate || !endDate) {
            this.dataGapsChecked = true;
            this.dataGaps = null;
            if (callback) {callback();}
            return;
        }

        // Parse dates and format for API
        try {
            // Handle various date formats
            startDate = this.normalizeDateTime(startDate);
            endDate = this.normalizeDateTime(endDate);
        } catch (e) {
            console.warn('Could not parse dates for gap check:', e);
            this.dataGapsChecked = true;
            if (callback) {callback();}
            return;
        }

        $.ajax({
            url: `api/analysis/trajectory_gaps.php?start=${encodeURIComponent(startDate)}&end=${encodeURIComponent(endDate)}&include_counts=true`,
            method: 'GET',
            dataType: 'json',
            success: (response) => {
                this.dataGapsChecked = true;
                if (response.success) {
                    this.dataGaps = response.has_gaps ? response : null;
                    this.hourlyCounts = response.hourly_counts || {};
                } else {
                    this.hourlyCounts = {};
                }
                if (callback) {callback();}
            },
            error: () => {
                this.dataGapsChecked = true;
                this.dataGaps = null;
                this.hourlyCounts = {};
                if (callback) {callback();}
            }
        });
    },

    /**
     * Normalize date/time string to ISO format
     */
    normalizeDateTime: function(dateStr) {
        if (!dateStr) {return null;}

        // Already ISO format
        if (dateStr.match(/^\d{4}-\d{2}-\d{2}/)) {
            return dateStr.split('T')[0];
        }

        // Format: "YYYY-MM-DD HH:MM" or "YYYY-MM-DD HHMM"
        const match = dateStr.match(/^(\d{4}-\d{2}-\d{2})/);
        if (match) {
            return match[1];
        }

        // Try parsing as Date
        const d = new Date(dateStr);
        if (!isNaN(d.getTime())) {
            return d.toISOString().split('T')[0];
        }

        return dateStr;
    },

    /**
     * Render the data gap warning banner
     */
    renderDataGapWarning: function() {
        if (!this.dataGaps || !this.dataGaps.has_gaps) {
            return '';
        }

        const gaps = this.dataGaps.gaps || [];
        const totalHours = this.dataGaps.total_missing_hours || 0;

        // Filter gaps that overlap with event window
        let relevantGaps = gaps;
        if (this.results?.event_start && this.results?.event_end) {
            // For now, show all gaps in range - could enhance to filter by TMI windows
            relevantGaps = gaps;
        }

        if (relevantGaps.length === 0) {
            return '';
        }

        // Group gaps by date for cleaner display
        const gapsByDate = {};
        relevantGaps.forEach(g => {
            if (!gapsByDate[g.date]) {
                gapsByDate[g.date] = [];
            }
            gapsByDate[g.date].push(g);
        });

        let gapListHtml = '';
        Object.keys(gapsByDate).sort().forEach(date => {
            const dateGaps = gapsByDate[date];
            const hoursStr = dateGaps.map(g => {
                if (g.duration_hours === 1) {
                    return `${String(g.start_hour).padStart(2,'0')}Z`;
                } else {
                    return `${String(g.start_hour).padStart(2,'0')}-${String(g.end_hour).padStart(2,'0')}Z`;
                }
            }).join(', ');
            gapListHtml += `<div><strong>${date}</strong>: ${hoursStr}</div>`;
        });

        return `
            <div class="tmi-data-gap-warning alert alert-warning mb-3" style="border-left: 4px solid #f0ad4e;">
                <div class="d-flex align-items-start">
                    <i class="fas fa-exclamation-triangle fa-lg mr-3 mt-1" style="color: #f0ad4e;"></i>
                    <div class="flex-grow-1">
                        <strong>Trajectory Data Gaps Detected</strong>
                        <p class="mb-2 mt-1" style="font-size: 0.9em;">
                            Flight position data is missing for <strong>${totalHours} hour${totalHours !== 1 ? 's' : ''}</strong>
                            during the analysis window. This may result in:
                        </p>
                        <ul class="mb-2" style="font-size: 0.85em;">
                            <li>Missing flights in TMI crossing analysis</li>
                            <li>Underreported traffic counts</li>
                            <li>Incomplete compliance calculations</li>
                        </ul>
                        <div class="small">
                            <button class="btn btn-sm btn-outline-warning" type="button"
                                    data-toggle="collapse" data-target="#dataGapDetails" aria-expanded="false">
                                <i class="fas fa-list"></i> View Gap Details
                            </button>
                        </div>
                        <div class="collapse mt-2" id="dataGapDetails">
                            <div class="card card-body bg-light small py-2" style="font-family: monospace;">
                                ${gapListHtml}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;
    },

    /**
     * Check if a TMI time window overlaps with any data gaps
     * @param {string} tmiStart - TMI start time (format: "HH:MMZ" or ISO datetime)
     * @param {string} tmiEnd - TMI end time (format: "HH:MMZ" or ISO datetime)
     * @returns {object|null} - Gap info if overlapping, null otherwise
     */
    checkTMIGapOverlap: function(tmiStart, tmiEnd) {
        if (!this.dataGaps?.has_gaps || !this.dataGaps?.gaps?.length) {
            return null;
        }

        // Parse TMI times to get hours
        const parseHour = (timeStr) => {
            if (!timeStr) {return null;}
            // Format: "HH:MMZ" or "HHMM" or ISO datetime
            const match = timeStr.match(/(\d{2}):?(\d{2})/);
            if (match) {
                return parseInt(match[1]);
            }
            return null;
        };

        const tmiStartHour = parseHour(tmiStart);
        const tmiEndHour = parseHour(tmiEnd);

        if (tmiStartHour === null || tmiEndHour === null) {
            return null;
        }

        // Get event date from results
        const eventDate = this.results?.event_start?.split('T')[0] ||
                          this.results?.event_start?.split(' ')[0];

        // Find overlapping gaps
        const overlappingGaps = [];
        for (const gap of this.dataGaps.gaps) {
            // Check if this gap is on the event date (or near it)
            // For simplicity, check hour overlap
            const gapStartHour = gap.start_hour;
            const gapEndHour = gap.end_hour;

            // Handle wrap-around (e.g., TMI 23Z-04Z)
            let tmiHours = [];
            if (tmiEndHour >= tmiStartHour) {
                for (let h = tmiStartHour; h <= tmiEndHour; h++) {
                    tmiHours.push(h);
                }
            } else {
                // Wrap around midnight
                for (let h = tmiStartHour; h <= 23; h++) {
                    tmiHours.push(h);
                }
                for (let h = 0; h <= tmiEndHour; h++) {
                    tmiHours.push(h);
                }
            }

            // Check if any gap hours overlap with TMI hours
            for (let h = gapStartHour; h <= gapEndHour; h++) {
                if (tmiHours.includes(h)) {
                    overlappingGaps.push(gap);
                    break;
                }
            }
        }

        if (overlappingGaps.length === 0) {
            return null;
        }

        // Return summary of overlapping gaps
        const totalMissingHours = overlappingGaps.reduce((sum, g) => sum + g.duration_hours, 0);
        const gapSummary = overlappingGaps.map(g => {
            if (g.duration_hours === 1) {
                return `${String(g.start_hour).padStart(2,'0')}Z`;
            }
            return `${String(g.start_hour).padStart(2,'0')}-${String(g.end_hour).padStart(2,'0')}Z`;
        }).join(', ');

        return {
            gaps: overlappingGaps,
            totalHours: totalMissingHours,
            summary: gapSummary
        };
    },

    /**
     * Render a data gap warning badge for a TMI card
     * @param {string} tmiStart - TMI start time
     * @param {string} tmiEnd - TMI end time
     * @returns {string} - HTML for warning badge, or empty string
     */
    renderTMIGapBadge: function(tmiStart, tmiEnd) {
        const overlap = this.checkTMIGapOverlap(tmiStart, tmiEnd);
        if (!overlap) {
            return '';
        }

        return `<span class="badge badge-warning ml-2"
                      title="Data gap during TMI window: ${overlap.summary} (${overlap.totalHours}h missing). Traffic counts may be incomplete."
                      style="cursor: help;">
                    Data Gap
                </span>`;
    },

    /**
     * Get trajectory counts for a TMI time window
     * @param {string} tmiStart - TMI start time (format: "HH:MMZ" or ISO datetime)
     * @param {string} tmiEnd - TMI end time (format: "HH:MMZ" or ISO datetime)
     * @returns {object} - {trajPoints: N, uniqueFlights: N, hours: N, hasData: bool}
     */
    getTMITrajectoryCounts: function(tmiStart, tmiEnd) {
        const result = {
            trajPoints: 0,
            uniqueFlights: 0,
            hours: 0,
            hasData: false
        };

        if (!this.hourlyCounts || Object.keys(this.hourlyCounts).length === 0) {
            return result;
        }

        // Parse TMI times to get hours
        const parseHour = (timeStr) => {
            if (!timeStr) {return null;}
            const match = timeStr.match(/(\d{2}):?(\d{2})/);
            if (match) {
                return parseInt(match[1]);
            }
            return null;
        };

        const tmiStartHour = parseHour(tmiStart);
        const tmiEndHour = parseHour(tmiEnd);

        if (tmiStartHour === null || tmiEndHour === null) {
            return result;
        }

        // Get event date from results
        const eventDate = this.results?.event_start?.split('T')[0] ||
                          this.results?.event_start?.split(' ')[0];

        if (!eventDate) {
            return result;
        }

        // Build list of hours in TMI window, handling wrap-around
        let tmiHours = [];
        if (tmiEndHour >= tmiStartHour) {
            for (let h = tmiStartHour; h <= tmiEndHour; h++) {
                tmiHours.push(h);
            }
        } else {
            // Wrap around midnight
            for (let h = tmiStartHour; h <= 23; h++) {
                tmiHours.push(h);
            }
            for (let h = 0; h <= tmiEndHour; h++) {
                tmiHours.push(h);
            }
        }

        result.hours = tmiHours.length;

        // Sum trajectory counts for each hour in the TMI window
        tmiHours.forEach(h => {
            const key = `${eventDate}T${String(h).padStart(2, '0')}`;
            const hourData = this.hourlyCounts[key];
            if (hourData) {
                result.trajPoints += hourData.traj_points || 0;
                result.uniqueFlights += hourData.unique_flights || 0;
                result.hasData = true;
            }
        });

        return result;
    },

    /**
     * Render trajectory counts info for a TMI card
     * @param {string} tmiStart - TMI start time
     * @param {string} tmiEnd - TMI end time
     * @param {number} streamFlightCount - Number of flights matching TMI stream definition
     * @param {number} analyzedFlightCount - Number of stream flights with trajectory data (actually analyzed)
     * @returns {string} - HTML for trajectory counts display
     */
    renderTrajectoryCounts: function(tmiStart, tmiEnd, streamFlightCount, analyzedFlightCount) {
        const counts = this.getTMITrajectoryCounts(tmiStart, tmiEnd);

        if (!counts.hasData && !analyzedFlightCount) {
            return '';
        }

        // Format numbers
        const formatNum = (n) => n.toLocaleString();
        const streamFlights = streamFlightCount || 0;
        const trajFlights = analyzedFlightCount || 0;

        // Calculate coverage percentage (stream flights with trajectories / total stream flights)
        const coveragePct = streamFlights > 0 ? Math.round((trajFlights / streamFlights) * 100) : 0;
        const coverageClass = coveragePct >= 90 ? 'text-success' : coveragePct >= 70 ? 'text-warning' : 'text-danger';

        return `
            <div class="tmi-trajectory-counts small text-muted mt-1" style="border-top: 1px dashed #dee2e6; padding-top: 0.5rem;">
                <span title="Flights matching TMI stream definition (origin/dest/fix)">
                    <strong>${formatNum(streamFlights)}</strong> stream flights
                </span>
                <span class="mx-2">|</span>
                <span title="Stream flights with trajectory data available for analysis">
                    <strong>${formatNum(trajFlights)}</strong> w/ trajectories
                </span>
                <span class="mx-2">|</span>
                <span title="Hours covered by this TMI">
                    ${counts.hours}h window
                </span>
            </div>
        `;
    },

    showNoData: function() {
        $('#tmi_results_container').html(`
            <div class="text-center py-4 text-muted">
                <i class="fas fa-database fa-2x mb-2"></i>
                <div>No TMI compliance data available for this event.</div>
                <div class="small">Run the analysis script to generate results.</div>
            </div>
        `);
    },

    showError: function(message) {
        $('#tmi_results_container').html(`
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> ${message}
            </div>
        `);
    },

    // ========================================
    // PROGRESSIVE DISCLOSURE LAYOUT (v2 UI)
    // Master-detail layout with L1/L2/L3 layers
    // ========================================

    /**
     * Render the progressive disclosure layout (new v2 UI)
     * L1: Summary Header - 5-second answer
     * L2: TMI List Panel - scannable index
     * L3: Detail Panel - selected TMI details
     */
    renderProgressiveLayout: function() {
        const html = `
            <div class="tmi-analysis-wrapper">
                ${this.renderSummaryHeaderV2()}
                <div class="tmi-analysis-container">
                    <div class="tmi-list-panel">
                        ${this.renderListPanelV2()}
                    </div>
                    <div class="tmi-detail-panel" id="tmi-detail-panel">
                        <div class="tmi-detail-empty">
                            Select a TMI from the list to view details
                        </div>
                    </div>
                </div>
            </div>
        `;
        $('#tmi_results_container').html(html);
        this.bindProgressiveLayoutEvents();

        // Auto-select first TMI if available
        const firstItem = $('.tmi-list-item').first();
        if (firstItem.length) {
            this.selectTmi(firstItem.data('tmi-id'));
        }
    },

    /**
     * Format a datetime string for human-readable display
     * Input: ISO format "2026-01-30T23:59:00" or "2026-01-30 23:59:00"
     * Output: Aviation format "2026-01-30 2359Z"
     */
    formatEventTime: function(datetime) {
        if (!datetime) return '';

        // Parse ISO or space-separated datetime; ensure UTC interpretation
        let isoStr = datetime.replace(' ', 'T');
        if (!/[Zz+\-]\d{0,4}$/.test(isoStr)) isoStr += 'Z';
        const dt = new Date(isoStr);
        if (isNaN(dt.getTime())) return datetime; // Return original if parse fails

        const year = dt.getUTCFullYear();
        const month = String(dt.getUTCMonth() + 1).padStart(2, '0');
        const day = String(dt.getUTCDate()).padStart(2, '0');
        const hours = String(dt.getUTCHours()).padStart(2, '0');
        const mins = String(dt.getUTCMinutes()).padStart(2, '0');

        return `${year}-${month}-${day} ${hours}${mins}Z`;
    },

    /**
     * Format event window for display
     * Shows full date on start, just time on end if same day
     */
    formatEventWindow: function(startDt, endDt) {
        const start = this.formatEventTime(startDt);
        const end = this.formatEventTime(endDt);

        if (!start || !end) return `${start || '?'} – ${end || '?'}`;

        // Check if same date - show just time for end
        const startDate = start.split(' ')[0];
        const endDate = end.split(' ')[0];
        const endTime = end.split(' ')[1];

        if (startDate === endDate) {
            return `${start} – ${endTime}`;
        }
        return `${start} – ${end}`;
    },

    /**
     * L1: Summary Header - The 5-second answer
     */
    renderSummaryHeaderV2: function() {
        const r = this.results;
        const summary = r.summary || {};

        // Get all TMI results
        const mitResults = r.mit_results || {};
        const gsResults = r.gs_results || {};
        const apreqResults = r.apreq_results || {};
        const rerouteResults = r.reroute_results || {};
        const delayResults = r.delay_results || [];

        const mitArray = Array.isArray(mitResults) ? mitResults : Object.values(mitResults);
        const gsArray = Array.isArray(gsResults) ? gsResults : Object.values(gsResults);
        const apreqArray = Array.isArray(apreqResults) ? apreqResults : Object.values(apreqResults);
        const rerouteArray = Array.isArray(rerouteResults) ? rerouteResults : Object.values(rerouteResults);

        // Calculate NTML entry counts
        const mitCount = mitArray.length;
        const mitPairs = mitArray.reduce((sum, m) => sum + (m.pairs || 0), 0);
        const mitNonCompliant = mitArray.reduce((sum, m) => {
            const allPairs = m.all_pairs || [];
            return sum + allPairs.filter(p => p.spacing_category === 'UNDER').length;
        }, 0);

        const gsCount = gsArray.length;
        const stopViolations = gsArray.reduce((sum, g) => {
            return sum + (g.non_compliant_count || 0);
        }, 0);

        const apreqCount = apreqArray.length;
        const rerouteCount = rerouteArray.length;
        const mandatoryReroutes = rerouteArray.filter(rr => rr.mandatory || rr.action === 'RQD').length;
        const rerouteFlights = rerouteArray.reduce((sum, rr) => sum + (rr.total_flights || 0), 0);

        // Calculate trajectory coverage
        const trajCoverage = this.calculateTrajectoryCoverageV2();

        // Format event window (human-readable)
        const eventWindow = this.formatEventWindow(r.event_start, r.event_end);

        // NTML Entries summary
        let ntmlLines = '';
        if (mitCount > 0) {
            ntmlLines += `<div class="tmi-summary-line"><strong>MIT/MINIT:</strong> ${mitPairs} pairs analyzed, ${mitNonCompliant} non-compliant</div>`;
        }
        if (apreqCount > 0) {
            ntmlLines += `<div class="tmi-summary-line"><strong>APREQ/CFR:</strong> ${apreqCount} tracked</div>`;
        }
        if (gsCount > 0) {
            ntmlLines += `<div class="tmi-summary-line"><strong>STOP:</strong> ${gsCount} stop${gsCount > 1 ? 's' : ''}, ${stopViolations} departure${stopViolations !== 1 ? 's' : ''} during restriction</div>`;
        }

        // Advisories summary (GS and reroutes as advisory context)
        let advisoryLines = '';
        if (gsCount > 0) {
            advisoryLines += `<div class="tmi-summary-line"><strong>GS:</strong> ${gsCount} program${gsCount > 1 ? 's' : ''}</div>`;
        }
        if (rerouteCount > 0) {
            const avgFiledPct = rerouteArray.reduce((sum, rr) => sum + (rr.filed_compliance_pct || 0), 0) / rerouteCount;
            advisoryLines += `<div class="tmi-summary-line"><strong>Reroutes:</strong> ${rerouteCount} program${rerouteCount > 1 ? 's' : ''} (${mandatoryReroutes} mandatory), ${rerouteFlights} flights, filed ${avgFiledPct.toFixed(0)}% compliant</div>`;
        }

        // Data gap information
        let gapInfo = '';
        if (this.dataGaps && this.dataGaps.length > 0) {
            const gapTimes = this.dataGaps.map(g => {
                const start = g.start_hour.toString().padStart(2, '0') + ':00Z';
                const end = (g.end_hour + 1).toString().padStart(2, '0') + ':00Z';
                return `${start}–${end}`;
            }).join(', ');
            gapInfo = ` | <span class="gap-warning">Gaps: ${gapTimes}</span>`;
        }

        return `
            <div class="tmi-summary-header">
                <div class="event-identity">Plan ${r.plan_id || this.planId || '?'} — TMI Analysis</div>
                <div class="event-window">${eventWindow}</div>

                <div class="tmi-summary-entries">
                    <div class="tmi-summary-group">
                        <h4>NTML Entries</h4>
                        ${ntmlLines || '<div class="tmi-summary-line text-muted">None configured</div>'}
                    </div>
                    ${advisoryLines ? `
                    <div class="tmi-summary-group">
                        <h4>Advisories</h4>
                        ${advisoryLines}
                    </div>
                    ` : ''}
                </div>

                <div class="tmi-discord-copy mt-2">
                    ${mitCount > 0 ? '<button class="btn btn-sm btn-outline-secondary mr-2" onclick="TMICompliance.copyNtmlSummary()" title="Copy NTML summary for Discord"><i class="fas fa-copy"></i> Copy NTML for Discord</button>' : ''}
                    ${gsCount > 0 ? '<button class="btn btn-sm btn-outline-secondary" onclick="TMICompliance.copyGsSummary()" title="Copy GS summary for Discord"><i class="fas fa-copy"></i> Copy GS for Discord</button>' : ''}
                </div>

                <div class="tmi-data-quality">
                    Data: ${trajCoverage}% trajectory coverage${gapInfo}
                </div>
            </div>
        `;
    },

    /**
     * Calculate trajectory coverage percentage
     */
    calculateTrajectoryCoverageV2: function() {
        const mitResults = this.results?.mit_results || {};
        const mitArray = Array.isArray(mitResults) ? mitResults : Object.values(mitResults);

        let totalCrossings = 0;
        let withTrajectories = 0;

        mitArray.forEach(m => {
            totalCrossings += (m.crossings || m.total_crossings || 0);
            // Estimate trajectory coverage from analyzed pairs
            withTrajectories += (m.pairs || 0) * 2; // Each pair involves 2 flights
        });

        if (totalCrossings === 0) return 100;
        return Math.min(100, Math.round((withTrajectories / totalCrossings) * 100));
    },

    /**
     * L2: TMI List Panel - Scannable index
     */
    renderListPanelV2: function() {
        const allTmis = this.getAllTmisForList();

        if (allTmis.length === 0) {
            return `<div class="tmi-list-empty">No TMIs to display</div>`;
        }

        // Group by type
        const ntmlEntries = allTmis.filter(t => ['MIT', 'MINIT', 'APREQ', 'STOP'].includes(t.type));
        const advisories = allTmis.filter(t => ['GS', 'GDP', 'REROUTE'].includes(t.type));

        let html = `
            <div class="tmi-list-header">
                <div class="tmi-list-controls">
                    <select id="tmi-list-ordering" title="Sort order">
                        <option value="chronological" ${this.listOrdering === 'chronological' ? 'selected' : ''}>Chronological</option>
                        <option value="volume" ${this.listOrdering === 'volume' ? 'selected' : ''}>By Volume</option>
                        <option value="noncompliant" ${this.listOrdering === 'noncompliant' ? 'selected' : ''}>Non-compliant First</option>
                        <option value="alpha" ${this.listOrdering === 'alpha' ? 'selected' : ''}>Alphabetical</option>
                    </select>
                </div>
            </div>
        `;

        // Sort TMIs based on current ordering
        const sortedNtml = this.sortTmiList(ntmlEntries);
        const sortedAdvisories = this.sortTmiList(advisories);

        // NTML Entries section
        if (sortedNtml.length > 0) {
            html += '<div class="tmi-list-section-label">NTML Entries</div>';
            sortedNtml.forEach(tmi => {
                html += this.renderListItemV2(tmi);
            });
        }

        // Advisories section
        if (sortedAdvisories.length > 0) {
            html += '<div class="tmi-list-section-label">Advisories</div>';
            sortedAdvisories.forEach(tmi => {
                html += this.renderListItemV2(tmi);
            });
        }

        // Holding patterns section
        if (this.holdingData && this.holdingData.summary && this.holdingData.summary.total_hold_events > 0) {
            const hs = this.holdingData.summary;
            html += '<div class="tmi-list-section-label">Holding Patterns</div>';
            (hs.hold_fixes || []).forEach((fix, idx) => {
                const fixLabel = fix.fix_name || 'Unknown Fix';
                const isSelected = this._selectedHoldingFix === idx;
                const durMin = Math.round(fix.avg_duration_sec / 60);
                html += `<div class="tmi-list-item${isSelected ? ' selected' : ''}" onclick="TMICompliance.selectHoldingFix(${idx})">
                    <div class="tmi-list-item-identity">
                        <span class="tmi-type-badge holding">HPT</span> ${fixLabel}
                    </div>
                    <div class="tmi-list-item-meta">
                        ${fix.flight_count} flights, ${durMin}min avg${fix.ntml_corroborated ? ' <i class="fas fa-check-circle" title="NTML corroborated"></i>' : ''}
                    </div>
                </div>`;
            });
        }

        return html;
    },

    /**
     * Get all TMIs in a normalized format for the list
     */
    getAllTmisForList: function() {
        const tmis = [];
        const r = this.results;

        // MIT/MINIT
        const mitResults = r.mit_results || {};
        const mitArray = Array.isArray(mitResults) ? mitResults : Object.values(mitResults);
        mitArray.forEach((m, i) => {
            const pairs = m.pairs || 0;
            const allPairs = m.all_pairs || [];
            const nonCompliant = allPairs.filter(p => p.spacing_category === 'UNDER').length;
            const isMinit = m.unit === 'min';
            const displayName = (m.fix && !['ALL', 'ANY', ''].includes(m.fix.toUpperCase()))
                ? m.fix
                : (m.destinations?.join(',') || 'Unknown');

            tmis.push({
                id: `mit_${i}`,
                type: isMinit ? 'MINIT' : 'MIT',
                identifier: displayName,
                typeValue: `${m.required || 0}${isMinit ? 'MINIT' : 'MIT'}`,
                metric: `${pairs}p`,
                metricValue: pairs,
                nonCompliant: nonCompliant,
                startTime: m.tmi_start,
                data: m,
            });
        });

        // Ground Stops
        const gsResults = r.gs_results || {};
        const gsArray = Array.isArray(gsResults) ? gsResults : Object.values(gsResults);
        gsArray.forEach((g, i) => {
            const nonCompliant = g.non_compliant_count || 0;
            const totalFlights = g.total_flights || 0;
            const identifier = (g.destinations && g.destinations.length > 0)
                ? g.destinations.join(',')
                : 'GS';
            tmis.push({
                id: `gs_${i}`,
                type: 'GS',
                identifier: identifier,
                typeValue: 'GS',
                metric: `${nonCompliant}/${totalFlights}`,
                metricValue: totalFlights,
                nonCompliant: nonCompliant,
                startTime: g.gs_start,
                data: g,
            });
        });

        // Reroutes
        const rerouteResults = r.reroute_results || {};
        const rerouteArray = Array.isArray(rerouteResults) ? rerouteResults : Object.values(rerouteResults);
        rerouteArray.forEach((rr, i) => {
            const action = rr.action || (rr.mandatory ? 'RQD' : 'FYI');
            const routeType = rr.route_type || 'ROUTE';
            const filedNc = (rr.filed_non_compliant || []).length;
            const flownNc = (rr.flown_non_compliant || []).length;
            tmis.push({
                id: `reroute_${i}`,
                type: 'REROUTE',
                identifier: rr.name || 'Reroute',
                typeValue: `${routeType} ${action}`,
                metric: `${rr.total_flights || 0}`,
                metricValue: rr.total_flights || 0,
                nonCompliant: filedNc + flownNc,
                startTime: rr.start,
                data: rr,
            });
        });

        // APREQ
        const apreqResults = r.apreq_results || {};
        const apreqArray = Array.isArray(apreqResults) ? apreqResults : Object.values(apreqResults);
        apreqArray.forEach((a, i) => {
            const flightCount = a.total_flights || a.affected_count || 0;
            tmis.push({
                id: `apreq_${i}`,
                type: 'APREQ',
                identifier: a.fix || a.destinations?.join(',') || 'APREQ',
                typeValue: 'APREQ',
                metric: `${flightCount}`,
                metricValue: flightCount,
                nonCompliant: 0,
                startTime: a.tmi_start,
                data: a,
            });
        });

        return tmis;
    },

    /**
     * Sort TMI list based on current ordering
     */
    sortTmiList: function(tmis) {
        const sorted = [...tmis];

        switch (this.listOrdering) {
            case 'volume':
                sorted.sort((a, b) => b.metricValue - a.metricValue);
                break;
            case 'noncompliant':
                sorted.sort((a, b) => b.nonCompliant - a.nonCompliant);
                break;
            case 'alpha':
                sorted.sort((a, b) => a.identifier.localeCompare(b.identifier));
                break;
            case 'chronological':
            default:
                // Already in chronological order from data
                break;
        }

        return sorted;
    },

    /**
     * Render a single list item
     */
    renderListItemV2: function(tmi) {
        const hasNonCompliant = tmi.nonCompliant > 0;
        const selectedClass = this.selectedTmiId === tmi.id ? 'selected' : '';

        return `
            <div class="tmi-list-item ${selectedClass}" data-tmi-id="${tmi.id}" onclick="TMICompliance.selectTmi('${tmi.id}')">
                <div class="identifier">
                    ${hasNonCompliant ? '<span class="tmi-nc-dot"></span>' : ''}
                    ${tmi.identifier}
                </div>
                <div class="type-value">${tmi.typeValue}</div>
                <div class="metric">${tmi.metric}</div>
            </div>
        `;
    },

    /**
     * Select a TMI and show its details
     */
    selectTmi: function(tmiId) {
        this.selectedTmiId = tmiId;

        // Update list selection state
        $('.tmi-list-item').removeClass('selected');
        $(`.tmi-list-item[data-tmi-id="${tmiId}"]`).addClass('selected');

        // Find TMI data
        const tmi = this.findTmiById(tmiId);
        if (!tmi) return;

        // Render detail panel
        $('#tmi-detail-panel').html(this.renderDetailPanelV2(tmi));

        // On mobile, scroll to detail
        if (window.innerWidth < 1000) {
            $('#tmi-detail-panel')[0].scrollIntoView({ behavior: 'smooth' });
        }
    },

    /**
     * Find TMI data by ID
     */
    findTmiById: function(tmiId) {
        const allTmis = this.getAllTmisForList();
        return allTmis.find(t => t.id === tmiId);
    },

    /**
     * L3: Detail Panel - Full details for selected TMI
     */
    renderDetailPanelV2: function(tmi) {
        if (!tmi) {
            return '<div class="tmi-detail-empty">Select a TMI from the list</div>';
        }

        const data = tmi.data;
        let html = '';

        // Back link for mobile
        html += '<a class="tmi-detail-back" onclick="TMICompliance.scrollToList()">← Back to list</a>';

        // Detail header - format standardized line based on type
        let standardizedLine = '';
        if (tmi.type === 'REROUTE') {
            const action = data.action || (data.mandatory ? 'RQD' : 'FYI');
            const routeType = data.route_type || 'ROUTE';
            const parts = [`${routeType} ${action}`];
            if (data.name) parts.push(data.name);
            if (data.start || data.end) parts.push(`${data.start || '?'}-${data.end || '?'}`);
            if (data.constrained_area) parts.push(data.constrained_area);
            standardizedLine = parts.join(' | ');
        } else {
            standardizedLine = this.formatStandardizedTMI(data);
        }

        html += `
            <div class="tmi-detail-header">
                <div class="tmi-identity">${tmi.identifier} ${tmi.typeValue}</div>
                <div class="tmi-standardized">${standardizedLine}</div>
            </div>
        `;

        // Render type-specific content
        if (tmi.type === 'MIT' || tmi.type === 'MINIT') {
            html += this.renderMitDetailV2(data);
        } else if (tmi.type === 'GS') {
            html += this.renderGsDetailV2(data);
        } else if (tmi.type === 'REROUTE') {
            html += this.renderRerouteDetailV2(data);
        } else if (tmi.type === 'APREQ') {
            html += this.renderApreqDetailV2(data);
        }

        return html;
    },

    /**
     * Render MIT/MINIT detail content
     */
    renderMitDetailV2: function(data) {
        const allPairs = data.all_pairs || [];
        const nonCompliant = allPairs.filter(p => p.spacing_category === 'UNDER');
        const pairs = data.pairs || 0;
        const required = data.required || 0;
        const unitLabel = data.unit === 'min' ? 'min' : 'nm';

        // Calculate spacing stats
        const minSpacing = data.spacing_stats?.min || data.min_spacing || 0;
        const maxSpacing = data.spacing_stats?.max || data.max_spacing || 0;
        const avgSpacing = data.spacing_stats?.avg || data.avg_spacing || 0;

        let html = `
            <div class="tmi-detail-overview">
                <div class="stat">
                    <div class="stat-value">${data.crossings || data.total_crossings || 0}</div>
                    <div class="stat-label">Crossings</div>
                </div>
                <div class="stat">
                    <div class="stat-value">${pairs}</div>
                    <div class="stat-label">Pairs Analyzed</div>
                </div>
                <div class="stat">
                    <div class="stat-value">${nonCompliant.length}</div>
                    <div class="stat-label">Non-compliant</div>
                </div>
                <div class="stat">
                    <div class="spacing-range">${minSpacing.toFixed(1)}–${maxSpacing.toFixed(1)}${unitLabel}</div>
                    <div class="stat-label">Spacing Range</div>
                </div>
                <div class="stat">
                    <div class="stat-value">${avgSpacing.toFixed(1)}${unitLabel}</div>
                    <div class="stat-label">Avg Spacing</div>
                </div>
            </div>
        `;

        // Expandable sections
        const diagramId = `diagram_${data.fix || 'mit'}_${Date.now()}`;

        // Spacing Diagram section
        html += this.renderExpandableSectionV2('spacing-diagram', 'Spacing Diagram', '', () => {
            return this.renderSpacingDiagramV2(allPairs, required, data.unit);
        });

        // All Pairs section
        html += this.renderExpandableSectionV2('all-pairs', 'All Pairs', `(${pairs})`, () => {
            return this.renderPairsTableV2(allPairs, required, unitLabel);
        });

        // Non-Compliant section (if any)
        if (nonCompliant.length > 0) {
            html += this.renderExpandableSectionV2('non-compliant', 'Non-Compliant', `(${nonCompliant.length})`, () => {
                return this.renderPairsTableV2(nonCompliant, required, unitLabel);
            });
        }

        // Context Map section (reuse existing map rendering)
        const mapId = `map_v2_${data.fix || 'mit'}_${Date.now()}`;
        html += this.renderMapSection(data, mapId);

        return html;
    },

    /**
     * Render GS detail content
     */
    renderGsDetailV2: function(data) {
        const nonCompliantFlights = data.non_compliant_flights || data.non_compliant || [];
        const exemptFlights = data.exempt_flights || data.exempt || [];
        const compliantFlights = data.compliant_flights || data.compliant || [];
        const nonCompliant = data.non_compliant_count || data.violations?.total || nonCompliantFlights.length;
        const totalFlights = data.total_flights || 0;
        const compliant = data.compliant_count || compliantFlights.length;
        const exempt = data.exempt_count || exemptFlights.length;
        const notInScope = (data.not_in_scope || []).length;

        // Ended-by badge
        let endedBadge = '';
        if (data.ended_by === 'CNX' || data.cancelled) {
            endedBadge = '<span class="gs-ended-badge cnx">CNX</span>';
        } else if (data.ended_by === 'EXPIRATION') {
            endedBadge = '<span class="gs-ended-badge expired">EXPIRED</span>';
        }

        let html = `
            <div class="tmi-detail-overview">
                <div class="stat">
                    <div class="stat-value">${data.gs_start || '?'} - ${data.gs_end || '?'} ${endedBadge}</div>
                    <div class="stat-label">Stop Window</div>
                </div>
                <div class="stat">
                    <div class="stat-value">${totalFlights}</div>
                    <div class="stat-label">Flights Tracked</div>
                </div>
                <div class="stat">
                    <div class="stat-value">${compliant}</div>
                    <div class="stat-label">Compliant</div>
                </div>
                <div class="stat">
                    <div class="stat-value">${nonCompliant}</div>
                    <div class="stat-label">Non-compliant</div>
                </div>
                <div class="stat">
                    <div class="stat-value">${exempt}</div>
                    <div class="stat-label">Exempt</div>
                </div>
        `;

        if (notInScope > 0) {
            html += `
                <div class="stat">
                    <div class="stat-value">${notInScope}</div>
                    <div class="stat-label">Not In Scope</div>
                </div>
            `;
        }

        if (data.avg_hold_time_min && data.avg_hold_time_min > 0) {
            const stats = data.hold_time_stats || {};
            const tooltip = stats.min ? `Min: ${this.formatDuration(stats.min)} | Median: ${this.formatDuration(stats.median)} | Max: ${this.formatDuration(stats.max)}` : '';
            html += `
                <div class="stat" ${tooltip ? `title="${tooltip}"` : ''}>
                    <div class="stat-value">${this.formatDuration(data.avg_hold_time_min)}</div>
                    <div class="stat-label">Avg Hold</div>
                </div>
            `;
        }

        // GS delay stat (from OOOI taxi time analysis)
        const v2GsDelay = data.gs_delay_stats || {};
        if (v2GsDelay.flights_with_delay_data > 0) {
            const delayTooltip = `Median: ${this.formatDuration(v2GsDelay.median_delay_min)} | Max: ${this.formatDuration(v2GsDelay.max_delay_min)} | Total: ${this.formatDuration(v2GsDelay.total_delay_min)} | Based on ${v2GsDelay.flights_with_delay_data} flights with OOOI data`;
            html += `
                <div class="stat" title="${delayTooltip}">
                    <div class="stat-value">${this.formatDuration(v2GsDelay.avg_delay_min)}</div>
                    <div class="stat-label">Avg GS Delay</div>
                </div>
            `;
        }

        html += `</div>`;

        // Time source breakdown
        const v2Tsb = data.time_source_breakdown || {};
        const v2TsTotal = (v2Tsb['off_utc'] || 0) + (v2Tsb['out_utc+taxi'] || 0) + (v2Tsb['first_seen'] || 0);
        if (v2TsTotal > 0) {
            html += `<div class="text-muted small mt-1" style="font-size:0.72rem;">
                <i class="fas fa-stopwatch"></i> Time sources:
                ${v2Tsb['off_utc'] ? `<span class="badge badge-success mr-1" style="font-size:0.68rem;">${v2Tsb['off_utc']} wheels-off</span>` : ''}
                ${v2Tsb['out_utc+taxi'] ? `<span class="badge badge-info mr-1" style="font-size:0.68rem;">${v2Tsb['out_utc+taxi']} gate+taxi</span>` : ''}
                ${v2Tsb['first_seen'] ? `<span class="badge badge-warning mr-1" style="font-size:0.68rem;">${v2Tsb['first_seen']} first-seen</span>` : ''}
            </div>`;
        }

        // Impacting condition + program metadata
        if (data.impacting_condition) {
            html += `<div class="text-muted small mt-2"><i class="fas fa-cloud"></i> ${this.escapeHtml(data.impacting_condition)}</div>`;
        }
        if (data.dep_facility_tier) {
            html += `<span class="badge badge-outline-secondary mr-1" style="font-size:0.7rem;">${this.escapeHtml(data.dep_facility_tier)}</span>`;
        }
        if (data.prob_extension) {
            html += `<div class="text-muted small mt-1"><i class="fas fa-clock"></i> Prob Extension: ${this.escapeHtml(data.prob_extension)}</div>`;
        }

        // Program timeline bar
        if (data.program_timeline && data.program_timeline.length > 0) {
            html += this.renderGsTimelineBar(data.program_timeline, data.gs_start, data.gs_end);

            // Advisory detail section (expandable V2)
            html += this.renderExpandableSectionV2('gs-advisory-chain', 'Advisory History', `(${data.program_timeline.length})`, () => {
                return `<div class="advisory-chain">
                    ${data.program_timeline.map(adv => {
                        const typeClass = adv.type === 'INITIAL' ? 'danger' : adv.type === 'CNX' ? 'success' : 'warning';
                        return `<div class="advisory-detail-card${adv.type === 'CNX' ? ' cnx' : ''}">
                            <div class="advisory-detail-header">
                                <span class="badge badge-${typeClass}">ADVZY ${adv.advzy || '?'}</span>
                                <span class="advisory-type">${adv.type || ''}</span>
                                ${adv.start ? `<span class="text-muted">${adv.start} - ${adv.end || '?'}</span>` : ''}
                                <span class="text-muted small">Issued: ${adv.issued || 'N/A'}</span>
                            </div>
                            ${adv.impacting_condition ? `<div class="advisory-meta"><i class="fas fa-cloud"></i> ${this.escapeHtml(adv.impacting_condition)}</div>` : ''}
                            ${adv.dep_facilities && adv.dep_facilities.length ? `<div class="advisory-meta"><i class="fas fa-building"></i> DEP: ${adv.dep_facilities.join(', ')}${adv.dep_facility_tier ? ' (' + adv.dep_facility_tier + ')' : ''}</div>` : ''}
                            ${adv.prob_extension ? `<div class="advisory-meta"><i class="fas fa-clock"></i> Extension: ${this.escapeHtml(adv.prob_extension)}</div>` : ''}
                            ${adv.comments ? `<div class="advisory-meta"><i class="fas fa-comment"></i> ${this.escapeHtml(adv.comments)}</div>` : ''}
                        </div>`;
                    }).join('')}
                </div>`;
            });
        }

        // CNX comments
        if (data.cnx_comments) {
            html += `<div class="gs-cnx-comments mt-2"><i class="fas fa-info-circle text-info"></i> ${this.escapeHtml(data.cnx_comments)}</div>`;
        }

        // Per-origin breakdown (expandable section)
        const perOrigin = data.per_origin_breakdown || [];
        if (perOrigin.length > 0) {
            const v2OriginTblId = `gs_origin_v2_tbl_${++this.detailIdCounter}`;
            html += this.renderExpandableSectionV2('gs-per-origin', 'Per-Origin Breakdown', `(${perOrigin.length})`, () => {
                let tbl = `<div class="gs-per-origin"><div class="table-responsive"><table class="table table-sm table-striped sortable-table" id="${v2OriginTblId}">
                    <thead><tr>
                        <th onclick="TMICompliance.sortTable('${v2OriginTblId}',0,false)" style="cursor:pointer;">Origin</th>
                        <th onclick="TMICompliance.sortTable('${v2OriginTblId}',1,true)" style="cursor:pointer;">Total</th>
                        <th onclick="TMICompliance.sortTable('${v2OriginTblId}',2,true)" style="cursor:pointer;">Compliant</th>
                        <th onclick="TMICompliance.sortTable('${v2OriginTblId}',3,true)" style="cursor:pointer;">Non-Comp</th>
                        <th onclick="TMICompliance.sortTable('${v2OriginTblId}',4,true)" style="cursor:pointer;">Exempt</th>
                        <th onclick="TMICompliance.sortTable('${v2OriginTblId}',5,true)" style="cursor:pointer;">Rate</th>
                        <th onclick="TMICompliance.sortTable('${v2OriginTblId}',6,true)" style="cursor:pointer;">Avg Hold</th>
                        <th onclick="TMICompliance.sortTable('${v2OriginTblId}',7,true)" style="cursor:pointer;">Avg Delay</th>
                    </tr></thead><tbody>`;
                perOrigin.forEach(o => {
                    const oPct = o.compliance_pct || 0;
                    const oClass = this.getComplianceClass(oPct);
                    const compW = o.total > 0 ? Math.round(((o.compliant || 0) / o.total) * 100) : 0;
                    const ncW = o.total > 0 ? Math.round(((o.non_compliant || 0) / o.total) * 100) : 0;
                    const exW = 100 - compW - ncW;
                    tbl += `<tr>
                        <td>${o.origin}
                            <div class="compliance-bar"><span class="bg-success" style="width:${compW}%"></span><span class="bg-danger" style="width:${ncW}%"></span><span class="bg-info" style="width:${exW}%"></span></div>
                        </td>
                        <td>${o.total || 0}</td>
                        <td class="text-success">${o.compliant || 0}</td>
                        <td class="text-danger">${o.non_compliant || 0}</td>
                        <td class="text-info">${o.exempt || 0}</td>
                        <td><span class="compliance-badge ${oClass}" style="font-size:0.8rem;">${oPct.toFixed(1)}%</span></td>
                        <td>${o.avg_hold_time_min ? TMICompliance.formatDuration(o.avg_hold_time_min) : '-'}</td>
                        <td>${o.avg_gs_delay_min ? TMICompliance.formatDuration(o.avg_gs_delay_min) : '-'}</td>
                    </tr>`;
                });
                tbl += `</tbody></table></div></div>`;
                return tbl;
            });
        }

        // Per-carrier breakdown (expandable section)
        const perCarrier = data.per_carrier_breakdown || [];
        if (perCarrier.length > 0) {
            const v2CarrierTblId = `gs_carrier_v2_tbl_${++this.detailIdCounter}`;
            html += this.renderExpandableSectionV2('gs-per-carrier', 'Per-Carrier Breakdown', `(${perCarrier.length})`, () => {
                let tbl = `<div class="gs-per-origin"><div class="table-responsive"><table class="table table-sm table-striped sortable-table" id="${v2CarrierTblId}">
                    <thead><tr>
                        <th onclick="TMICompliance.sortTable('${v2CarrierTblId}',0,false)" style="cursor:pointer;">Carrier</th>
                        <th onclick="TMICompliance.sortTable('${v2CarrierTblId}',1,true)" style="cursor:pointer;">Total</th>
                        <th onclick="TMICompliance.sortTable('${v2CarrierTblId}',2,true)" style="cursor:pointer;">Compliant</th>
                        <th onclick="TMICompliance.sortTable('${v2CarrierTblId}',3,true)" style="cursor:pointer;">Non-Comp</th>
                        <th onclick="TMICompliance.sortTable('${v2CarrierTblId}',4,true)" style="cursor:pointer;">Exempt</th>
                        <th onclick="TMICompliance.sortTable('${v2CarrierTblId}',5,true)" style="cursor:pointer;">Rate</th>
                        <th onclick="TMICompliance.sortTable('${v2CarrierTblId}',6,true)" style="cursor:pointer;">Avg Hold</th>
                        <th onclick="TMICompliance.sortTable('${v2CarrierTblId}',7,true)" style="cursor:pointer;">Avg Delay</th>
                    </tr></thead><tbody>`;
                perCarrier.forEach(c => {
                    const cPct = c.compliance_pct || 0;
                    const cClass = this.getComplianceClass(cPct);
                    const compW = c.total > 0 ? Math.round(((c.compliant || 0) / c.total) * 100) : 0;
                    const ncW = c.total > 0 ? Math.round(((c.non_compliant || 0) / c.total) * 100) : 0;
                    const exW = 100 - compW - ncW;
                    const nameTooltip = c.airline_name ? ` title="${this.escapeHtml(c.airline_name)}"` : '';
                    tbl += `<tr>
                        <td${nameTooltip}>${c.carrier}${c.airline_name ? ' <span class="text-muted" style="font-size:0.78rem;">(' + this.escapeHtml(c.airline_name) + ')</span>' : ''}
                            <div class="compliance-bar"><span class="bg-success" style="width:${compW}%"></span><span class="bg-danger" style="width:${ncW}%"></span><span class="bg-info" style="width:${exW}%"></span></div>
                        </td>
                        <td>${c.total || 0}</td>
                        <td class="text-success">${c.compliant || 0}</td>
                        <td class="text-danger">${c.non_compliant || 0}</td>
                        <td class="text-info">${c.exempt || 0}</td>
                        <td><span class="compliance-badge ${cClass}" style="font-size:0.8rem;">${cPct.toFixed(1)}%</span></td>
                        <td>${c.avg_hold_time_min ? TMICompliance.formatDuration(c.avg_hold_time_min) : '-'}</td>
                        <td>${c.avg_gs_delay_min ? TMICompliance.formatDuration(c.avg_gs_delay_min) : '-'}</td>
                    </tr>`;
                });
                tbl += `</tbody></table></div></div>`;
                return tbl;
            });
        }

        // Unified Flight Catalog (expandable section with drill-down)
        // Order: violations first, then compliant, then exempt
        const allFlights = [].concat(nonCompliantFlights, compliantFlights, exemptFlights);
        if (allFlights.length > 0) {
            const catalogTblId = `gs_catalog_v2_tbl_${++this.detailIdCounter}`;
            const hasAnyPhase = allFlights.some(f => f.phase);
            const hasAnyGsDelay = allFlights.some(f => f.gs_delay_min !== undefined);

            html += this.renderExpandableSectionV2('gs-flight-catalog', 'Flight Catalog', `(${allFlights.length})`, () => {
                let tbl = `<div class="table-responsive" style="max-height:500px; overflow-y:auto;">
                    <table class="table table-sm sortable-table" id="${catalogTblId}" style="font-family:'SFMono-Regular',Menlo,Monaco,Consolas,'Liberation Mono','Courier New',monospace; font-size:0.8rem;">
                    <thead class="thead-dark sticky-top"><tr>
                        <th onclick="TMICompliance.sortTable('${catalogTblId}',0,false)" style="cursor:pointer;">Status</th>
                        <th onclick="TMICompliance.sortTable('${catalogTblId}',1,false)" style="cursor:pointer;">Callsign</th>
                        <th onclick="TMICompliance.sortTable('${catalogTblId}',2,false)" style="cursor:pointer;">Carrier</th>
                        <th onclick="TMICompliance.sortTable('${catalogTblId}',3,false)" style="cursor:pointer;">Origin</th>
                        <th onclick="TMICompliance.sortTable('${catalogTblId}',4,false)" style="cursor:pointer;">Dept Time</th>
                        <th>Into GS / Hold</th>
                        ${hasAnyGsDelay ? '<th>GS Delay</th>' : ''}
                        ${hasAnyPhase ? '<th>Phase</th>' : ''}
                        <th>Source</th>
                    </tr></thead><tbody>`;

                allFlights.forEach((f, idx) => {
                    const statusBadge = f.status === 'NON-COMPLIANT'
                        ? '<span class="badge badge-danger" style="font-size:0.7rem;">VIOLATION</span>'
                        : f.status === 'COMPLIANT'
                            ? '<span class="badge badge-success" style="font-size:0.7rem;">COMPLIANT</span>'
                            : '<span class="badge badge-info" style="font-size:0.7rem;">EXEMPT</span>';
                    const rowClass = f.status === 'NON-COMPLIANT' ? 'table-danger' : '';
                    const detailRowId = `gs_catalog_detail_${catalogTblId}_${idx}`;

                    // Into GS / Hold column: violations show time + %, compliant show hold time
                    let holdCell = '';
                    if (f.into_gs_min !== undefined && f.pct_into_gs !== undefined) {
                        holdCell = TMICompliance.formatDuration(f.into_gs_min) + ' (' + f.pct_into_gs + '%)';
                    } else if (f.hold_time_min) {
                        holdCell = TMICompliance.formatDuration(f.hold_time_min);
                    }

                    tbl += `<tr class="${rowClass}" style="cursor:pointer;" onclick="document.getElementById('${detailRowId}').classList.toggle('d-none')">
                        <td>${statusBadge}</td>
                        <td>${f.callsign}</td>
                        <td>${f.carrier || ''}</td>
                        <td>${f.dept || 'N/A'}</td>
                        <td>${f.dept_time || 'N/A'}</td>
                        <td>${holdCell}</td>
                        ${hasAnyGsDelay ? `<td>${f.gs_delay_min !== undefined ? TMICompliance.formatDuration(f.gs_delay_min) : ''}</td>` : ''}
                        ${hasAnyPhase ? `<td>${TMICompliance.formatPhase(f.phase, f.phase_type)}</td>` : ''}
                        <td class="text-muted">${f.time_source || ''}</td>
                    </tr>`;

                    // Inline detail row: flight timeline (hidden by default, toggled on click)
                    const colSpan = 6 + (hasAnyGsDelay ? 1 : 0) + (hasAnyPhase ? 1 : 0);
                    tbl += `<tr id="${detailRowId}" class="d-none">
                        <td colspan="${colSpan}" style="padding:10px 16px; background:var(--light-bg-subtle, #1e2128);">`;

                    // Build visual timeline
                    tbl += `<div style="font-size:0.82rem;">`;

                    // Timeline events as a vertical list with connecting line
                    const events = [];
                    if (f.first_seen_time) events.push({ time: f.first_seen_time, label: 'First Seen (connected)', icon: 'fa-wifi', color: '#6f42c1' });
                    if (f.out_time) events.push({ time: f.out_time, label: 'Gate Push (OUT)', icon: 'fa-door-open', color: '#6c757d' });
                    if (f.off_time) events.push({ time: f.off_time, label: 'Wheels-Off (OFF)', icon: 'fa-plane-departure', color: '#17a2b8' });
                    if (f.dept_time) events.push({ time: f.dept_time, label: 'Departure Time (' + (f.time_source || '') + ')', icon: 'fa-clock', color: f.status === 'NON-COMPLIANT' ? '#dc3545' : '#28a745' });

                    if (events.length > 0) {
                        tbl += `<div style="display:flex; gap:24px; align-items:flex-start;">`;
                        // Left: vertical timeline
                        tbl += `<div style="position:relative; padding-left:20px; min-width:260px;">`;
                        events.forEach((ev, i) => {
                            const isLast = i === events.length - 1;
                            tbl += `<div style="display:flex; align-items:flex-start; margin-bottom:${isLast ? '0' : '12px'}; position:relative;">`;
                            // Dot + connector line
                            tbl += `<div style="position:absolute; left:-20px; top:2px;">
                                <div style="width:10px; height:10px; border-radius:50%; background:${ev.color}; border:2px solid ${ev.color};"></div>
                                ${!isLast ? `<div style="position:absolute; left:4px; top:12px; width:2px; height:20px; background:var(--light-border, #333);"></div>` : ''}
                            </div>`;
                            tbl += `<div>
                                <span style="color:${ev.color}; font-weight:600;">${ev.time}</span>
                                <span class="text-muted" style="margin-left:8px;">${ev.label}</span>
                            </div>`;
                            tbl += `</div>`;
                        });
                        tbl += `</div>`;

                        // Right: computed metrics
                        tbl += `<div style="display:flex; flex-wrap:wrap; gap:12px 24px; align-items:flex-start;">`;
                        if (f.gate_wait_min) tbl += `<div><span class="text-muted">Gate Wait:</span> <strong>${TMICompliance.formatDuration(f.gate_wait_min)}</strong></div>`;
                        if (f.actual_taxi_min !== undefined) tbl += `<div><span class="text-muted">Taxi:</span> <strong>${TMICompliance.formatDuration(f.actual_taxi_min)}</strong></div>`;
                        if (f.unimpeded_taxi_min !== undefined) tbl += `<div><span class="text-muted">Unimpeded:</span> <strong>${TMICompliance.formatDuration(f.unimpeded_taxi_min)}</strong></div>`;
                        if (f.gs_delay_min !== undefined) tbl += `<div><span class="text-muted">GS Delay:</span> <strong>${TMICompliance.formatDuration(f.gs_delay_min)}</strong></div>`;
                        if (f.hold_time_min) tbl += `<div><span class="text-muted">Hold Time:</span> <strong>${TMICompliance.formatDuration(f.hold_time_min)}</strong></div>`;
                        if (f.into_gs_min !== undefined) tbl += `<div><span class="text-muted">Into GS:</span> <strong>${TMICompliance.formatDuration(f.into_gs_min)} (${f.pct_into_gs}%)</strong></div>`;
                        if (f.phase) tbl += `<div><span class="text-muted">Phase:</span> <strong>${TMICompliance.formatPhase(f.phase, f.phase_type)}</strong></div>`;
                        tbl += `<div><span class="text-muted">Reason:</span> ${f.reason || ''}</div>`;
                        tbl += `</div>`;

                        tbl += `</div>`;
                    } else {
                        // No OOOI data - show simple reason
                        tbl += `<span class="text-muted">Reason:</span> ${f.reason || ''}`;
                        if (f.phase) tbl += ` &nbsp;|&nbsp; <span class="text-muted">Phase:</span> ${TMICompliance.formatPhase(f.phase, f.phase_type)}`;
                    }

                    tbl += `</div></td></tr>`;
                });

                tbl += `</tbody></table></div>`;
                return tbl;
            });
        }

        // Context Map section for GS
        const mapId = `map_v2_gs_${Date.now()}`;
        html += this.renderMapSection(data, mapId);

        return html;
    },

    /**
     * Render APREQ detail content
     */
    renderApreqDetailV2: function(data) {
        const totalFlights = data.total_flights || 0;
        const affectedCount = data.affected_count || 0;
        const exemptCount = data.exempt_count || 0;
        const postTmiCount = data.post_tmi_count || 0;

        let html = `
            <div class="tmi-detail-overview">
                <div class="stat">
                    <div class="stat-value">${data.tmi_start || '?'} - ${data.tmi_end || '?'}</div>
                    <div class="stat-label">Active Window</div>
                </div>
                <div class="stat">
                    <div class="stat-value">${totalFlights}</div>
                    <div class="stat-label">Flights Tracked</div>
                </div>
                <div class="stat">
                    <div class="stat-value">${affectedCount}</div>
                    <div class="stat-label">Affected</div>
                </div>
                <div class="stat">
                    <div class="stat-value">${exemptCount}</div>
                    <div class="stat-label">Exempt</div>
                </div>
            </div>
            <div class="text-muted small mt-3">
                <i class="fas fa-info-circle"></i> APREQ/CFR is tracked for awareness only. Compliance is determined by coordination between facilities.
            </div>
        `;

        return html;
    },

    /**
     * Render an expandable section
     */
    renderExpandableSectionV2: function(sectionId, title, count, contentFn) {
        const isExpanded = this.expandedSections[sectionId] || false;
        const expandedClass = isExpanded ? 'expanded' : '';

        return `
            <div class="tmi-section ${expandedClass}" data-section-id="${sectionId}">
                <div class="tmi-section-header" onclick="TMICompliance.toggleSectionV2('${sectionId}')">
                    <span class="chevron">▸</span>
                    <span class="section-title">${title}</span>
                    <span class="section-count">${count}</span>
                </div>
                <div class="tmi-section-content">
                    ${isExpanded ? contentFn() : ''}
                </div>
            </div>
        `;
    },

    /**
     * Toggle section expansion
     */
    toggleSectionV2: function(sectionId) {
        this.expandedSections[sectionId] = !this.expandedSections[sectionId];

        const section = $(`.tmi-section[data-section-id="${sectionId}"]`);
        const content = section.find('.tmi-section-content');

        if (this.expandedSections[sectionId]) {
            section.addClass('expanded');
            // Re-render content when expanding
            // For sections managed by renderExpandableSectionV2 with contentFn,
            // re-render the full detail panel to invoke the content function
            const tmi = this.findTmiById(this.selectedTmiId);
            if (tmi) {
                let contentHtml = '';
                if (sectionId === 'spacing-diagram') {
                    const data = tmi.data;
                    contentHtml = this.renderSpacingDiagramV2(data.all_pairs || [], data.required || 0, data.unit);
                } else if (sectionId === 'all-pairs') {
                    const data = tmi.data;
                    contentHtml = this.renderPairsTableV2(data.all_pairs || [], data.required || 0, data.unit === 'min' ? 'min' : 'nm');
                } else if (sectionId === 'non-compliant') {
                    const data = tmi.data;
                    const nonCompliant = (data.all_pairs || []).filter(p => p.spacing_category === 'UNDER');
                    contentHtml = this.renderPairsTableV2(nonCompliant, data.required || 0, data.unit === 'min' ? 'min' : 'nm');
                } else {
                    // Generic handler: re-render the entire detail panel
                    // This supports GS per-origin, violations, exempt, reroute sections
                    $('#tmi-detail-panel').html(this.renderDetailPanelV2(tmi));
                    return;
                }
                content.html(contentHtml);
            }
        } else {
            section.removeClass('expanded');
        }
    },

    /**
     * Select a holding fix in the list panel
     */
    selectHoldingFix: function(fixIdx) {
        this._selectedHoldingFix = fixIdx;
        this.selectedTmiId = null; // Deselect any TMI

        // Remove selected class from TMI list items, add to holding item
        $('.tmi-list-item').removeClass('selected');
        $(`.tmi-list-item`).eq(-1).addClass('selected'); // Will be updated by re-render

        this.renderHoldingDetail(fixIdx);
    },

    /**
     * Render holding fix detail panel
     */
    renderHoldingDetail: function(fixIdx) {
        const holding = this.holdingData;
        if (!holding || !holding.summary) return;

        const fix = holding.summary.hold_fixes[fixIdx];
        if (!fix) return;

        const events = holding.events.filter(e => {
            return (fix.fix_name && e.matched_fix === fix.fix_name) ||
                   (!fix.fix_name && Math.abs(e.center_lat - fix.center[1]) < 0.01);
        });

        let html = '';

        // Back link for mobile
        html += '<a class="tmi-detail-back" onclick="TMICompliance.scrollToList()">\u2190 Back to list</a>';

        // Header
        html += `<div class="tmi-detail-header">
            <div class="tmi-identity"><span class="tmi-type-badge holding">HPT</span> Holding at ${fix.fix_name || 'Unknown'}</div>
            <div class="tmi-standardized">${fix.flight_count} flights, ${fix.total_orbits} total orbits</div>
        </div>`;

        // Overview stats
        const durMin = Math.round(fix.avg_duration_sec / 60);
        html += `<div class="tmi-detail-overview">
            <div class="stat"><div class="stat-value">${fix.flight_count}</div><div class="stat-label">Flights</div></div>
            <div class="stat"><div class="stat-value">${fix.total_orbits}</div><div class="stat-label">Total Orbits</div></div>
            <div class="stat"><div class="stat-value">${durMin}m</div><div class="stat-label">Avg Duration</div></div>
            <div class="stat"><div class="stat-value">${fix.peak_concurrent}</div><div class="stat-label">Peak Concurrent</div></div>
        </div>`;

        // NTML badge
        if (fix.ntml_corroborated) {
            html += '<div class="holding-ntml-badge"><i class="fas fa-check-circle"></i> NTML Corroborated</div>';
        }

        // Flight list expandable section
        html += this.renderExpandableSectionV2('holding-flights-' + fixIdx, 'Flights', events.length, () => {
            let tableHtml = '<table class="tmi-pairs-table"><thead><tr>';
            tableHtml += '<th>Callsign</th><th>Dep</th><th>Dest</th><th>Start</th><th>Duration</th><th>Orbits</th><th>Fix Source</th><th>Dir</th>';
            tableHtml += '</tr></thead><tbody>';
            events.forEach(e => {
                const startTime = e.hold_start_utc ? e.hold_start_utc.substring(11, 16) : '-';
                const durMin = Math.round(e.duration_sec / 60);
                const sourceLabel = e.fix_match_source === 'route' ? 'Route'
                    : e.fix_match_source === 'star' ? 'STAR'
                    : e.fix_match_source === 'navfix' ? 'Nearby' : '-';
                tableHtml += `<tr>
                    <td><strong>${e.callsign}</strong></td>
                    <td>${e.dept || '-'}</td>
                    <td>${e.dest || '-'}</td>
                    <td>${startTime}Z</td>
                    <td>${durMin}min</td>
                    <td>${e.orbit_count}</td>
                    <td>${sourceLabel}</td>
                    <td>${e.turn_direction || '-'}</td>
                </tr>`;
            });
            tableHtml += '</tbody></table>';
            return tableHtml;
        });

        // Delay attribution expandable section
        const attr = holding.summary.delay_attribution;
        if (attr && attr.total_hold_delay_sec > 0) {
            html += this.renderExpandableSectionV2('holding-delay-' + fixIdx, 'Delay Attribution', '', () => {
                const totalMin = Math.round(attr.total_hold_delay_sec / 60);
                let dhtml = '<div class="holding-delay-summary">';
                dhtml += `<div class="stat"><div class="stat-value">${totalMin}m</div><div class="stat-label">Total Hold Delay</div></div>`;
                if (attr.attributed.gs && attr.attributed.gs.flights > 0) {
                    dhtml += `<div class="stat"><div class="stat-value">${attr.attributed.gs.flights}</div><div class="stat-label">GS-Attributed</div></div>`;
                }
                if (attr.attributed.mit && attr.attributed.mit.flights > 0) {
                    dhtml += `<div class="stat"><div class="stat-value">${attr.attributed.mit.flights}</div><div class="stat-label">MIT-Attributed</div></div>`;
                }
                if (attr.unattributed && attr.unattributed.flights > 0) {
                    dhtml += `<div class="stat"><div class="stat-value">${attr.unattributed.flights}</div><div class="stat-label">Unattributed</div></div>`;
                }
                dhtml += '</div>';
                return dhtml;
            });
        }

        $('#tmi-detail-panel').html(html);
    },

    /**
     * Toggle spacing diagram scale mode and re-render
     */
    toggleSpacingDiagramScale: function() {
        this.spacingDiagramScale = this.spacingDiagramScale === 'equal' ? 'proportional' : 'equal';
        // Re-render the spacing diagram section content
        const tmi = this.findTmiById(this.selectedTmiId);
        if (tmi) {
            const data = tmi.data;
            const section = $(`.tmi-section[data-section-id="spacing-diagram"]`);
            section.find('.tmi-section-content').html(
                this.renderSpacingDiagramV2(data.all_pairs || [], data.required || 0, data.unit)
            );
        }
    },

    /**
     * Simplified spacing diagram (v2) with optional proportional scale
     */
    renderSpacingDiagramV2: function(allPairs, required, unit) {
        if (!allPairs || allPairs.length === 0) {
            return '<div class="text-muted">No pairs to display</div>';
        }

        const unitLabel = unit === 'min' ? 'min' : 'nm';
        const isProportional = this.spacingDiagramScale === 'proportional';

        // Toggle button
        const t = typeof PERTII18n !== 'undefined' ? (k, p) => PERTII18n.t(k, p) : null;
        const descText = isProportional
            ? (t ? t('spacingDiagram.proportionalDesc') : 'Proportional spacing (segment widths reflect actual values)')
            : (t ? t('spacingDiagram.equalDesc') : 'Equal-width segments');
        const btnText = isProportional
            ? (t ? t('spacingDiagram.switchToEqual') : 'Switch to Equal Width')
            : (t ? t('spacingDiagram.switchToProportional') : 'Switch to To-Scale');
        let html = `<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
            <span style="font-size:0.8em; color:var(--light-text-muted, #6b7280);">
                ${descText}
            </span>
            <button class="btn btn-sm btn-outline-secondary" style="font-size:0.75em; padding:2px 10px;"
                onclick="TMICompliance.toggleSpacingDiagramScale()">
                ${btnText}
            </button>
        </div>`;

        // Build flight sequence
        const flights = [];
        if (allPairs.length > 0) {
            flights.push({
                callsign: allPairs[0].prev_callsign,
                time: allPairs[0].prev_time,
            });
            allPairs.forEach(p => {
                flights.push({
                    callsign: p.curr_callsign,
                    time: p.curr_time,
                    spacing: p.spacing,
                    category: p.spacing_category,
                });
            });
        }

        // For proportional mode, compute scale factor to fit container
        let pixelsPerUnit = 0;
        if (isProportional && allPairs.length > 0) {
            const totalSpacing = allPairs.reduce((sum, p) => sum + (p.spacing || 0), 0);
            // Target total width ~700px for segments, leaving room for flight dots (~50px each)
            const flightDotWidth = flights.length * 50;
            const availableForSegments = Math.max(300, 700 - flightDotWidth * 0.3);
            if (totalSpacing > 0) {
                pixelsPerUnit = availableForSegments / totalSpacing;
                // Clamp: minimum 8px per segment, maximum 120px per segment
                const maxSpacing = Math.max(...allPairs.map(p => p.spacing || 0));
                if (maxSpacing * pixelsPerUnit > 120) pixelsPerUnit = 120 / maxSpacing;
                const positiveSpacings = allPairs.filter(p => p.spacing > 0).map(p => p.spacing);
                const minSpacing = positiveSpacings.length > 0 ? Math.min(...positiveSpacings) : maxSpacing;
                if (minSpacing > 0 && minSpacing * pixelsPerUnit < 8) pixelsPerUnit = 8 / minSpacing;
            }
        }

        html += '<div class="tmi-spacing-diagram"><div class="tmi-spacing-timeline">';

        let lastTime = null;
        let lastTimeMs = null;

        for (let i = 0; i < flights.length; i++) {
            const flight = flights[i];

            // Parse time for gap detection
            let flightTimeMs = null;
            if (flight.time) {
                const match = flight.time.match(/(\d{2}):?(\d{2})/);
                if (match) {
                    flightTimeMs = parseInt(match[1]) * 60 + parseInt(match[2]);
                }
            }

            // Check for gap (>10 min between flights)
            if (lastTimeMs !== null && flightTimeMs !== null) {
                let gapMinutes = flightTimeMs - lastTimeMs;
                if (gapMinutes < 0) gapMinutes += 24 * 60; // Handle day wrap

                if (gapMinutes > 10) {
                    html += `<div class="tmi-spacing-gap">${gapMinutes} min</div>`;
                } else if (flight.spacing !== undefined) {
                    // Render segment line between consecutive flights
                    const isNonCompliant = flight.category === 'UNDER';
                    const segPx = isProportional && pixelsPerUnit > 0
                        ? Math.max(8, Math.round(flight.spacing * pixelsPerUnit)) : 0;
                    const segStyle = segPx > 0 ? `width:${segPx}px; min-width:8px; padding:0 2px;` : '';
                    const lineStyle = segPx > 0 ? `min-width:${Math.max(4, segPx - 4)}px;` : '';
                    html += `
                        <div class="tmi-spacing-segment" style="${segStyle}">
                            <div class="tmi-spacing-line ${isNonCompliant ? 'non-compliant' : ''}" style="${lineStyle}"></div>
                            <div class="tmi-spacing-value">${flight.spacing?.toFixed(1) || '?'}${unitLabel}</div>
                        </div>
                    `;
                }
            }

            // Render flight dot
            const isNonCompliant = flight.category === 'UNDER';
            html += `
                <div class="tmi-spacing-flight">
                    <div class="tmi-spacing-dot ${isNonCompliant ? 'non-compliant' : ''}"></div>
                    <div class="tmi-spacing-callsign">${flight.callsign}</div>
                    <div class="tmi-spacing-time">${flight.time || ''}</div>
                </div>
            `;

            lastTime = flight.time;
            lastTimeMs = flightTimeMs;
        }

        html += '</div>';

        // Scale bar - in proportional mode, show reference bar at required value width
        if (isProportional && pixelsPerUnit > 0) {
            const scaleBarWidth = Math.round(required * pixelsPerUnit);
            html += `
                <div class="tmi-spacing-scale" style="margin-top:16px;">
                    <div style="width:${scaleBarWidth}px; height:4px; background:var(--tmi-scale-bar, #adb5bd); border-radius:2px; position:relative;">
                        <div style="position:absolute; left:0; top:-2px; bottom:-2px; width:2px; background:var(--light-text-secondary, #4a4a6a); border-radius:1px;"></div>
                        <div style="position:absolute; right:0; top:-2px; bottom:-2px; width:2px; background:var(--light-text-secondary, #4a4a6a); border-radius:1px;"></div>
                    </div>
                    <span class="tmi-spacing-scale-label">${required}${unitLabel} required</span>
                </div>
            `;
        } else {
            html += `
                <div class="tmi-spacing-scale">
                    <div class="tmi-spacing-scale-bar"></div>
                    <span class="tmi-spacing-scale-label">${required}${unitLabel} required</span>
                </div>
            `;
        }

        html += '</div>';
        return html;
    },

    /**
     * Pairs table (v2)
     */
    renderPairsTableV2: function(pairs, required, unitLabel) {
        if (!pairs || pairs.length === 0) {
            return '<div class="text-muted">No pairs to display</div>';
        }

        // Required marker position: at 66.67% since bar represents 150% of required
        const requiredMarkerPct = (1 / 1.5) * 100; // ≈ 66.67%

        let html = `
            <table class="tmi-pairs-table">
                <thead>
                    <tr>
                        <th>Lead</th>
                        <th>Trail</th>
                        <th>Gap (mm:ss)</th>
                        <th>Spacing</th>
                        <th class="spacing-bar-cell">
                            <span class="visual-header">
                                <span class="required-label">${required}${unitLabel} req</span>
                            </span>
                        </th>
                    </tr>
                </thead>
                <tbody>
        `;

        pairs.forEach(p => {
            const isNonCompliant = p.spacing_category === 'UNDER';
            const spacing = p.spacing || 0;
            const diff = spacing - required;
            const diffStr = diff >= 0 ? `+${diff.toFixed(1)}` : diff.toFixed(1);

            // Calculate gap in mm:ss format (time_min is the gap in minutes from Python analyzer)
            const gapStr = this.formatGapMmSs(p.time_min || 0);

            // Calculate bar width (cap at 150% of required)
            const barPct = Math.min((spacing / (required * 1.5)) * 100, 100);

            html += `
                <tr>
                    <td class="flight-cell">
                        <div class="callsign">${p.prev_callsign}</div>
                        <div class="crossing-time">${p.prev_time || ''}</div>
                    </td>
                    <td class="flight-cell">
                        <div class="callsign">${p.curr_callsign}</div>
                        <div class="crossing-time">${p.curr_time || ''}</div>
                    </td>
                    <td class="gap-cell">${gapStr}</td>
                    <td class="spacing-cell">${spacing.toFixed(1)}${unitLabel}</td>
                    <td class="spacing-bar-cell">
                        <div class="tmi-spacing-bar-inline">
                            <div class="tmi-spacing-bar-track">
                                <div class="tmi-spacing-bar-fill ${isNonCompliant ? 'non-compliant' : ''}" style="width: ${barPct}%"></div>
                                <div class="tmi-required-marker" style="left: ${requiredMarkerPct}%" title="${required}${unitLabel} required"></div>
                            </div>
                            <span class="tmi-spacing-diff">(${diffStr})</span>
                        </div>
                    </td>
                </tr>
            `;
        });

        html += '</tbody></table>';
        return html;
    },

    /**
     * Format gap as mm:ss (always 2 digits each)
     */
    formatGapMmSs: function(decimalMinutes) {
        const totalSeconds = Math.round((decimalMinutes || 0) * 60);
        const minutes = Math.floor(totalSeconds / 60);
        const seconds = Math.abs(totalSeconds % 60);
        return `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
    },

    /**
     * Scroll to list (mobile)
     */
    scrollToList: function() {
        $('.tmi-list-panel')[0]?.scrollIntoView({ behavior: 'smooth' });
    },

    /**
     * Bind events for progressive layout
     * Uses event delegation to survive re-renders
     */
    bindProgressiveLayoutEvents: function() {
        const self = this;

        // List ordering change - use event delegation on parent container
        $('.tmi-analysis-wrapper').off('change', '#tmi-list-ordering').on('change', '#tmi-list-ordering', function() {
            self.listOrdering = $(this).val();
            // Re-render just the list items, not the header with dropdown
            self.refreshListItems();
        });
    },

    /**
     * Refresh only the list items without replacing the dropdown
     */
    refreshListItems: function() {
        const allTmis = this.getAllTmisForList();

        // Group by type
        const ntmlEntries = allTmis.filter(t => ['MIT', 'MINIT', 'APREQ', 'STOP'].includes(t.type));
        const advisories = allTmis.filter(t => ['GS', 'GDP', 'REROUTE'].includes(t.type));

        // Sort TMIs based on current ordering
        const sortedNtml = this.sortTmiList(ntmlEntries);
        const sortedAdvisories = this.sortTmiList(advisories);

        // Build list HTML
        let html = '';

        // NTML Entries section
        if (sortedNtml.length > 0) {
            html += '<div class="tmi-list-section-label">NTML Entries</div>';
            sortedNtml.forEach(tmi => {
                html += this.renderListItemV2(tmi);
            });
        }

        // Advisories section
        if (sortedAdvisories.length > 0) {
            html += '<div class="tmi-list-section-label">Advisories</div>';
            sortedAdvisories.forEach(tmi => {
                html += this.renderListItemV2(tmi);
            });
        }

        // Holding patterns section
        if (this.holdingData && this.holdingData.summary && this.holdingData.summary.total_hold_events > 0) {
            const hs = this.holdingData.summary;
            html += '<div class="tmi-list-section-label">Holding Patterns</div>';
            (hs.hold_fixes || []).forEach((fix, idx) => {
                const fixLabel = fix.fix_name || 'Unknown Fix';
                const isSelected = this._selectedHoldingFix === idx;
                const durMin = Math.round(fix.avg_duration_sec / 60);
                html += `<div class="tmi-list-item${isSelected ? ' selected' : ''}" onclick="TMICompliance.selectHoldingFix(${idx})">
                    <div class="tmi-list-item-identity">
                        <span class="tmi-type-badge holding">HPT</span> ${fixLabel}
                    </div>
                    <div class="tmi-list-item-meta">
                        ${fix.flight_count} flights, ${durMin}min avg${fix.ntml_corroborated ? ' <i class="fas fa-check-circle" title="NTML corroborated"></i>' : ''}
                    </div>
                </div>`;
            });
        }

        // Replace just the list content (after header)
        $('.tmi-list-panel .tmi-list-section-label, .tmi-list-panel .tmi-list-item').remove();
        $('.tmi-list-panel .tmi-list-header').after(html);

        // Re-select the currently selected item
        if (this.selectedTmiId) {
            $(`.tmi-list-item[data-tmi-id="${this.selectedTmiId}"]`).addClass('selected');
        }
    },

    // =========================================================================
    // BRANCH CORRIDOR ANALYSIS
    // Progressive disclosure: Flow Cone → Show Branches → Select/Compare
    // =========================================================================

    /**
     * Toggle branch analysis layers on the map and expand/collapse branch list.
     */
    toggleBranches: function(btn) {
        const mapId = btn.dataset.map;
        const map = this.activeMaps[mapId];
        if (!map) return;

        const isActive = btn.classList.toggle('active');
        const branchList = document.getElementById(`${mapId}_fa_branch_list`);

        if (isActive) {
            // First activation: compute and render
            if (!this.branchPanelState[mapId]?.initialized) {
                this.initBranchAnalysis(mapId);
            }

            // Expand branch list
            if (branchList) branchList.style.display = '';

            // Show branch layers
            ['branch-corridors-fill', 'branch-corridors-outline', 'branch-corridor-labels'].forEach(id => {
                if (map.getLayer(id)) map.setLayoutProperty(id, 'visibility', 'visible');
            });

            // Dim flow cone to 50%
            if (map.getLayer('traffic-sectors-fill')) {
                map.setPaintProperty('traffic-sectors-fill', 'fill-opacity',
                    ['case', ['==', ['get', 'pct'], 75], 0.07, 0.05]);
            }
            if (map.getLayer('traffic-sectors-outline')) {
                map.setPaintProperty('traffic-sectors-outline', 'line-opacity', 0.3);
            }
            if (map.getLayer('spacing-arcs')) {
                map.setPaintProperty('spacing-arcs', 'line-opacity', 0.3);
            }
        } else {
            // Collapse branch list
            if (branchList) branchList.style.display = 'none';

            // Hide branch layers
            ['branch-corridors-fill', 'branch-corridors-outline', 'branch-corridor-labels'].forEach(id => {
                if (map.getLayer(id)) map.setLayoutProperty(id, 'visibility', 'none');
            });

            // Restore flow cone opacity
            if (map.getLayer('traffic-sectors-fill')) {
                map.setPaintProperty('traffic-sectors-fill', 'fill-opacity',
                    ['case', ['==', ['get', 'pct'], 75], 0.15, 0.1]);
            }
            if (map.getLayer('traffic-sectors-outline')) {
                map.setPaintProperty('traffic-sectors-outline', 'line-opacity', 0.6);
            }
            if (map.getLayer('spacing-arcs')) {
                map.setPaintProperty('spacing-arcs', 'line-opacity', 0.6);
            }
        }
    },

    /**
     * Initialize branch analysis: compute corridors, render panel and map layers.
     */
    initBranchAnalysis: function(mapId) {
        const branchData = this.branchCorridorCache[mapId];
        const trajectories = this.trajectoryCache[mapId];
        const sectorData = this.trafficSectorCache?.[mapId];

        if (!branchData || !trajectories || !sectorData?.fix_point) {
            console.warn('Missing data for branch analysis:', {
                hasBranch: !!branchData, hasTraj: !!trajectories, hasSector: !!sectorData,
            });
            return;
        }

        const [fixLon, fixLat] = sectorData.fix_point;
        const required = sectorData.required_spacing || 15;
        const binSize = required >= 20 ? 5 : 3; // MIT-aligned bins
        const maxDistance = Math.max(75, required * 4);
        const metrics = branchData.branch_metrics || {};

        // Compute corridor polygons for each branch
        const branchCorridors = [];
        let skippedSingle = 0;

        branchData.branches.forEach((branch, idx) => {
            const callsignSet = new Set(branch.callsigns || []);
            if (callsignSet.size < 2) { skippedSingle++; return; }

            // Approach bearing: opposite of bearing_to_fix (direction traffic comes FROM)
            const approachBearing = ((branch.bearing_to_fix || 0) + 180) % 360;

            const corridor = this.computeBranchCorridor(
                trajectories, callsignSet, fixLon, fixLat,
                approachBearing, binSize, maxDistance
            );

            if (corridor) {
                const branchMetrics = metrics[branch.branch_id] || {};
                branchCorridors.push({
                    branchId: branch.branch_id,
                    branchIdx: idx,
                    shortName: branch.display?.short || `Branch ${idx + 1}`,
                    longName: branch.display?.long || branch.branch_id,
                    trackCount: branch.track_count || callsignSet.size,
                    compliancePct: branchMetrics.compliance_pct ?? 100,
                    pairs: branchMetrics.pairs || 0,
                    violations: (branchMetrics.violations || []).length,
                    spacingStats: branchMetrics.spacing_stats || null,
                    polygon75: corridor.polygon_75,
                    polygon90: corridor.polygon_90,
                    centroid: branch.centroid || null,
                    parentCorridorId: branch.parent_corridor_id || null,
                    selected: true,
                    isSubBranch: branch.is_sub_branch || false,
                    odComposition: branch.od_composition || null,
                    approachDirection: branch.approach_direction || '',
                });
            }
        });

        if (branchCorridors.length === 0) {
            console.warn('No branch corridors computed for', mapId);
            this.branchPanelState[mapId] = { initialized: true, corridors: [], skippedSingle: skippedSingle };
            this.renderFlowAnalysis(mapId);
            return;
        }

        this.branchPanelState[mapId] = {
            initialized: true,
            corridors: branchCorridors,
            skippedSingle: skippedSingle,
        };

        this.renderFlowAnalysis(mapId);
        this.renderBranchLayers(mapId);
        console.log(`Branch analysis: ${branchCorridors.length} corridors computed for ${mapId}`);
    },

    /**
     * Compute corridor polygons for a single branch's callsigns.
     * Reuses the same algorithm as flow cone: distance bins → centerline →
     * Gaussian smoothing → monotonic convergence → buffer polygons.
     */
    computeBranchCorridor: function(trajectories, callsignSet, fixLon, fixLat, streamBearing, binSize, maxDistance) {
        // Geo utility functions (same as flow cone computation)
        const distanceNm = (lon1, lat1, lon2, lat2) => {
            const R = 3440.065;
            const dLat = (lat2 - lat1) * Math.PI / 180;
            const dLon = (lon2 - lon1) * Math.PI / 180;
            const a = Math.sin(dLat / 2) ** 2 +
                Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) * Math.sin(dLon / 2) ** 2;
            return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        };

        const bearingTo = (lon1, lat1, lon2, lat2) => {
            const dLon = (lon2 - lon1) * Math.PI / 180;
            const lat1r = lat1 * Math.PI / 180;
            const lat2r = lat2 * Math.PI / 180;
            const y = Math.sin(dLon) * Math.cos(lat2r);
            const x = Math.cos(lat1r) * Math.sin(lat2r) - Math.sin(lat1r) * Math.cos(lat2r) * Math.cos(dLon);
            return ((Math.atan2(y, x) * 180 / Math.PI) + 360) % 360;
        };

        const pointAtBearing = (lon, lat, bearingDeg, distNm) => {
            const R = 3440.065;
            const bearing = bearingDeg * Math.PI / 180;
            const lat1 = lat * Math.PI / 180;
            const lon1 = lon * Math.PI / 180;
            const lat2 = Math.asin(Math.sin(lat1) * Math.cos(distNm / R) +
                Math.cos(lat1) * Math.sin(distNm / R) * Math.cos(bearing));
            const lon2 = lon1 + Math.atan2(
                Math.sin(bearing) * Math.sin(distNm / R) * Math.cos(lat1),
                Math.cos(distNm / R) - Math.sin(lat1) * Math.sin(lat2)
            );
            return [lon2 * 180 / Math.PI, lat2 * 180 / Math.PI];
        };

        const angularDiff = (a, b) => {
            let diff = a - b;
            while (diff > 180) diff -= 360;
            while (diff < -180) diff += 360;
            return diff;
        };

        // Step 1: Sample approach-phase points into distance bins
        const distanceBins = {};

        Object.entries(trajectories).forEach(([callsign, traj]) => {
            if (!callsignSet.has(callsign)) return;
            if (!traj.coordinates || traj.coordinates.length < 2) return;

            const visitedBins = new Set();
            for (let i = 1; i < traj.coordinates.length; i++) {
                const prev = traj.coordinates[i - 1];
                const curr = traj.coordinates[i];
                const prevDist = distanceNm(prev[0], prev[1], fixLon, fixLat);
                const currDist = distanceNm(curr[0], curr[1], fixLon, fixLat);

                if (currDist < prevDist) {
                    const [lon, lat] = prev;
                    const dist = prevDist;
                    const bearing = bearingTo(fixLon, fixLat, lon, lat);
                    const bin = Math.round(dist / binSize) * binSize;

                    if (bin > 0 && bin <= maxDistance && !visitedBins.has(bin)) {
                        visitedBins.add(bin);
                        distanceBins[bin] = distanceBins[bin] || [];
                        distanceBins[bin].push({ bearing, lon, lat, callsign });
                    }
                }
            }
        });

        // Step 2: Compute centerline from distance bins
        const centerlinePoints = [];
        const sortedBins = Object.keys(distanceBins).map(Number).sort((a, b) => a - b);

        sortedBins.forEach(dist => {
            const points = distanceBins[dist];
            if (points.length < 2) return;

            const bearings = points.map(p => p.bearing).sort((a, b) => a - b);
            const medianBearing = bearings[Math.floor(bearings.length / 2)];

            const normalized = bearings.map(b => {
                let diff = b - medianBearing;
                if (diff > 180) diff -= 360;
                if (diff < -180) diff += 360;
                return diff;
            }).sort((a, b) => a - b);

            const p75Hi = Math.min(Math.ceil(normalized.length * 0.875) - 1, normalized.length - 1);
            const p75Lo = Math.max(Math.floor(normalized.length * 0.125), 0);
            const p90Hi = Math.min(Math.ceil(normalized.length * 0.95) - 1, normalized.length - 1);
            const p90Lo = Math.max(Math.floor(normalized.length * 0.05), 0);

            const width75 = Math.max(3, Math.max(Math.abs(normalized[p75Hi] || 0), Math.abs(normalized[p75Lo] || 0)));
            const width90 = Math.max(5, Math.max(Math.abs(normalized[p90Hi] || 0), Math.abs(normalized[p90Lo] || 0)));

            centerlinePoints.push({
                dist,
                coords: pointAtBearing(fixLon, fixLat, medianBearing, dist),
                bearing: medianBearing,
                width75, width90,
                trackCount: points.length,
            });
        });

        if (centerlinePoints.length < 2) return null;

        // Step 3: Outlier rejection (>60° from branch stream bearing)
        const MAX_DEVIATION = 60;
        for (let i = 0; i < centerlinePoints.length; i++) {
            const cp = centerlinePoints[i];
            const dev = Math.abs(angularDiff(cp.bearing, streamBearing));
            if (dev > MAX_DEVIATION) {
                let prevGood = null, nextGood = null;
                for (let j = i - 1; j >= 0; j--) {
                    if (Math.abs(angularDiff(centerlinePoints[j].bearing, streamBearing)) <= MAX_DEVIATION) {
                        prevGood = centerlinePoints[j]; break;
                    }
                }
                for (let j = i + 1; j < centerlinePoints.length; j++) {
                    if (Math.abs(angularDiff(centerlinePoints[j].bearing, streamBearing)) <= MAX_DEVIATION) {
                        nextGood = centerlinePoints[j]; break;
                    }
                }

                let newBearing;
                if (prevGood && nextGood) {
                    const rad1 = prevGood.bearing * Math.PI / 180;
                    const rad2 = nextGood.bearing * Math.PI / 180;
                    newBearing = ((Math.atan2(
                        Math.sin(rad1) + Math.sin(rad2),
                        Math.cos(rad1) + Math.cos(rad2)
                    ) * 180 / Math.PI) + 360) % 360;
                } else if (prevGood) {
                    newBearing = prevGood.bearing;
                } else if (nextGood) {
                    newBearing = nextGood.bearing;
                } else {
                    newBearing = streamBearing;
                }

                cp.bearing = newBearing;
                cp.coords = pointAtBearing(fixLon, fixLat, newBearing, cp.dist);
            }
        }

        // Step 4: Gaussian smoothing (window=3, applied twice)
        const smoothCenterline = (points) => {
            if (points.length < 3) return points;
            const WINDOW = 3;
            const smoothed = [];

            for (let i = 0; i < points.length; i++) {
                const neighbors = [];
                for (let j = Math.max(0, i - WINDOW); j <= Math.min(points.length - 1, i + WINDOW); j++) {
                    const weight = 1 / (1 + Math.abs(j - i));
                    neighbors.push({ cp: points[j], weight });
                }

                const totalWeight = neighbors.reduce((sum, n) => sum + n.weight, 0);
                let sinSum = 0, cosSum = 0;
                neighbors.forEach(n => {
                    const rad = n.cp.bearing * Math.PI / 180;
                    sinSum += Math.sin(rad) * n.weight;
                    cosSum += Math.cos(rad) * n.weight;
                });

                const smoothBearing = ((Math.atan2(sinSum, cosSum) * 180 / Math.PI) + 360) % 360;
                const smoothWidth75 = neighbors.reduce((sum, n) => sum + n.cp.width75 * n.weight, 0) / totalWeight;
                const smoothWidth90 = neighbors.reduce((sum, n) => sum + n.cp.width90 * n.weight, 0) / totalWeight;

                smoothed.push({
                    dist: points[i].dist,
                    coords: pointAtBearing(fixLon, fixLat, smoothBearing, points[i].dist),
                    bearing: smoothBearing,
                    width75: smoothWidth75,
                    width90: smoothWidth90,
                    trackCount: points[i].trackCount,
                });
            }
            return smoothed;
        };

        const smoothedPoints = smoothCenterline(smoothCenterline(centerlinePoints));

        // Step 5: Monotonic convergence (cone narrows toward fix)
        const sorted = [...smoothedPoints].sort((a, b) => b.dist - a.dist);
        let maxW75 = sorted[0].width75, maxW90 = sorted[0].width90;
        sorted.forEach(cp => {
            cp.width75 = Math.min(cp.width75, maxW75);
            cp.width90 = Math.min(cp.width90, maxW90);
            maxW75 = cp.width75;
            maxW90 = cp.width90;
        });
        const convergentPoints = sorted.sort((a, b) => a.dist - b.dist);

        // Step 6: Build buffer polygons (perpendicular offsets from centerline)
        const buildBufferPolygon = (points, widthKey) => {
            const leftEdge = [];
            const rightEdge = [];

            points.forEach(cp => {
                const halfWidth = cp[widthKey];
                const leftBearing = (cp.bearing + 90) % 360;
                const rightBearing = (cp.bearing - 90 + 360) % 360;
                const linearWidth = cp.dist * Math.sin(halfWidth * Math.PI / 180);

                leftEdge.push(pointAtBearing(cp.coords[0], cp.coords[1], leftBearing, linearWidth));
                rightEdge.push(pointAtBearing(cp.coords[0], cp.coords[1], rightBearing, linearWidth));
            });

            const polygon = [[fixLon, fixLat]];
            rightEdge.forEach(pt => polygon.push(pt));
            leftEdge.reverse().forEach(pt => polygon.push(pt));
            polygon.push([fixLon, fixLat]);
            return polygon;
        };

        return {
            polygon_75: buildBufferPolygon(convergentPoints, 'width75'),
            polygon_90: buildBufferPolygon(convergentPoints, 'width90'),
            centerlinePoints: convergentPoints,
        };
    },

    /**
     * Get compliance color for a percentage value.
     */
    branchComplianceColor: function(pct) {
        if (pct >= 98) return '#28a745'; // Green - excellent
        if (pct >= 90) return '#5cb85c'; // Light green - good
        if (pct >= 80) return '#ffc107'; // Yellow - marginal
        if (pct >= 65) return '#fd7e14'; // Orange - poor
        return '#dc3545'; // Red - critical
    },

    /**
     * Build GeoJSON features for all branch corridors.
     */
    buildBranchFeatures: function(mapId) {
        const state = this.branchPanelState[mapId];
        if (!state?.corridors) return [];

        const features = [];
        state.corridors.forEach(bc => {
            const color = this.branchComplianceColor(bc.compliancePct);

            // Shared properties for popups and labels
            const sharedProps = {
                branchId: bc.branchId,
                color, selected: bc.selected,
                shortName: bc.shortName,
                longName: bc.longName,
                trackCount: bc.trackCount,
                compliancePct: bc.compliancePct,
                pairs: bc.pairs,
                violations: bc.violations,
                isSubBranch: bc.isSubBranch,
                approachDirection: bc.approachDirection,
                spacingStats: JSON.stringify(bc.spacingStats || {}),
                odComposition: JSON.stringify(bc.odComposition || {}),
            };

            if (bc.polygon90) {
                features.push({
                    type: 'Feature',
                    properties: { ...sharedProps, pct: 90 },
                    geometry: { type: 'Polygon', coordinates: [bc.polygon90] },
                });
            }
            if (bc.polygon75) {
                features.push({
                    type: 'Feature',
                    properties: { ...sharedProps, pct: 75 },
                    geometry: { type: 'Polygon', coordinates: [bc.polygon75] },
                });
            }
        });

        return features;
    },

    /**
     * Render branch corridor layers on the map.
     */
    renderBranchLayers: function(mapId) {
        const map = this.activeMaps[mapId];
        if (!map) return;

        const features = this.buildBranchFeatures(mapId);
        if (features.length === 0) return;

        const geojson = { type: 'FeatureCollection', features };

        if (map.getSource('branch-corridors')) {
            map.getSource('branch-corridors').setData(geojson);
            // Also update centroid labels if source exists
            if (map.getSource('branch-corridor-labels')) {
                const state = this.branchPanelState[mapId];
                const centroidFeats = [];
                if (state?.corridors) {
                    state.corridors.forEach(bc => {
                        if (!bc.centroid) return;
                        centroidFeats.push({
                            type: 'Feature',
                            properties: {
                                shortName: bc.shortName,
                                trackCount: bc.trackCount,
                                color: this.branchComplianceColor(bc.compliancePct),
                            },
                            geometry: bc.centroid,
                        });
                    });
                }
                map.getSource('branch-corridor-labels').setData({
                    type: 'FeatureCollection', features: centroidFeats,
                });
            }
        } else {
            map.addSource('branch-corridors', { type: 'geojson', data: geojson });

            const beforeLayer = map.getLayer('flight-tracks-solid-glow') ? 'flight-tracks-solid-glow' : undefined;

            // Start hidden — user clicks Branches toggle to show
            const branchBtn = document.querySelector(`.branch-toggle-btn[data-map="${mapId}"]`);
            const initVisible = branchBtn?.classList.contains('active') ? 'visible' : 'none';

            map.addLayer({
                id: 'branch-corridors-fill',
                type: 'fill',
                source: 'branch-corridors',
                layout: { visibility: initVisible },
                paint: {
                    'fill-color': ['get', 'color'],
                    'fill-opacity': ['case',
                        ['get', 'selected'],
                        ['case', ['==', ['get', 'pct'], 75], 0.20, 0.12],
                        ['case', ['==', ['get', 'pct'], 75], 0.05, 0.03],
                    ],
                },
            }, beforeLayer);

            map.addLayer({
                id: 'branch-corridors-outline',
                type: 'line',
                source: 'branch-corridors',
                layout: { visibility: initVisible },
                paint: {
                    'line-color': ['get', 'color'],
                    'line-width': ['case', ['==', ['get', 'pct'], 75], 2, 1],
                    'line-opacity': ['case', ['get', 'selected'], 0.7, 0.15],
                },
            }, beforeLayer);

            // Branch centroid labels
            const state = this.branchPanelState[mapId];
            const centroidFeatures = [];
            if (state?.corridors) {
                state.corridors.forEach(bc => {
                    if (!bc.centroid) return;
                    centroidFeatures.push({
                        type: 'Feature',
                        properties: {
                            shortName: bc.shortName,
                            trackCount: bc.trackCount,
                            color: this.branchComplianceColor(bc.compliancePct),
                        },
                        geometry: bc.centroid,
                    });
                });
            }
            if (centroidFeatures.length > 0) {
                map.addSource('branch-corridor-labels', {
                    type: 'geojson',
                    data: { type: 'FeatureCollection', features: centroidFeatures },
                });
                map.addLayer({
                    id: 'branch-corridor-labels',
                    type: 'symbol',
                    source: 'branch-corridor-labels',
                    layout: {
                        visibility: initVisible,
                        'text-field': ['concat', ['get', 'shortName'], '\n', ['to-string', ['get', 'trackCount']], ' ac'],
                        'text-font': ['Open Sans Bold', 'Arial Unicode MS Bold'],
                        'text-size': 10,
                        'text-anchor': 'center',
                        'text-allow-overlap': false,
                    },
                    paint: {
                        'text-color': ['get', 'color'],
                        'text-halo-color': '#000000',
                        'text-halo-width': 2,
                    },
                });
            }

            // Hover cursor change
            map.on('mouseenter', 'branch-corridors-fill', () => {
                map.getCanvas().style.cursor = 'pointer';
            });
            map.on('mouseleave', 'branch-corridors-fill', () => {
                map.getCanvas().style.cursor = '';
            });

            // Click popup with branch details
            map.on('click', 'branch-corridors-fill', (e) => {
                // Prefer the 90% (inner) polygon to avoid duplicate popups
                const feature = e.features.find(f => f.properties.pct === 90) || e.features[0];
                const props = feature?.properties || {};

                let spacingStats = {}, odComposition = {};
                try { spacingStats = JSON.parse(props.spacingStats || '{}'); } catch (e) { /* truncated */ }
                try { odComposition = JSON.parse(props.odComposition || '{}'); } catch (e) { /* truncated */ }

                // Build spacing stats line
                let spacingHtml = '';
                if (spacingStats.min !== undefined) {
                    spacingHtml = `<div><strong>Spacing:</strong> ${spacingStats.min} / ${spacingStats.avg} / ${spacingStats.max}</div>
                        <div style="font-size:0.85em; color:#999;">min / avg / max</div>`;
                }

                // Build O/D composition
                let odHtml = '';
                const odKeys = Object.keys(odComposition);
                if (odKeys.length > 0) {
                    const odEntries = Object.entries(odComposition)
                        .sort((a, b) => b[1] - a[1])
                        .slice(0, 5)
                        .map(([key, count]) => {
                            const parts = key.split(':');
                            let label = parts[1] || parts[0];
                            if (label.length === 4 && label[0] === 'K') label = label.substring(1);
                            return `${label}\u00d7${count}`;
                        })
                        .join(', ');
                    odHtml = `<div style="margin-top:4px;"><strong>O/D:</strong> ${odEntries}</div>`;
                }

                const subBadge = (props.isSubBranch === true || props.isSubBranch === 'true')
                    ? ' <span style="font-size:0.8em; opacity:0.7;">(sub-branch)</span>' : '';

                new maplibregl.Popup({ closeButton: true, maxWidth: '300px' })
                    .setLngLat(e.lngLat)
                    .setHTML(`
                        <div style="font-size: 12px; color: #333;">
                            <div style="font-weight: bold; border-bottom: 1px solid #ddd; padding-bottom: 4px; margin-bottom: 6px;">
                                ${props.longName || props.shortName || 'Branch'}${subBadge}
                            </div>
                            <div><strong>Aircraft:</strong> ${props.trackCount || 0}</div>
                            <div><strong>Compliance:</strong> ${props.compliancePct}% <span style="font-size:0.85em; color:#999;">(${props.pairs || 0} pairs, ${props.violations || 0} NC)</span></div>
                            ${spacingHtml}
                            ${odHtml}
                        </div>
                    `)
                    .addTo(map);
            });
        }
    },

    /**
     * Render the progressive flow analysis section.
     * Called whenever new data arrives: streams, branches, or branch corridors.
     * Progressively builds up: streams → branches → branch list.
     */
    renderFlowAnalysis: function(mapId) {
        const section = document.getElementById(`${mapId}_flow_analysis`);
        if (!section) return;

        const flowData = this.flowStreamCache[mapId];
        const branchData = this.branchCorridorCache[mapId];
        const branchState = this.branchPanelState[mapId];
        const trajCount = this.trajectoryCache[mapId]
            ? Object.keys(this.trajectoryCache[mapId]).length : 0;
        const flowFilterStats = this.flowFilterStats?.[mapId];

        // Nothing to show yet
        if (!flowData && !branchData && trajCount === 0) return;

        let html = '';

        // ── Flow filter row (when semantic filtering applied) ──
        if (flowFilterStats && flowFilterStats.reason === 'tmi_semantic' && flowFilterStats.matched < flowFilterStats.total) {
            const airports = flowFilterStats.matchSet?.join('/') || '';
            html += `
                <div class="fa-row fa-row-filter">
                    <i class="fas fa-filter fa-row-icon"></i>
                    <span class="fa-row-label">${flowFilterStats.matched} of ${flowFilterStats.total} flights</span>
                    <span class="fa-filter-badge" title="Filtered by ${flowFilterStats.matchField} matching ${airports}"><i class="fas fa-plane-arrival"></i> ${airports}</span>
                </div>`;
        }

        // ── Streams row ──
        if (flowData?.streams?.length > 0) {
            const streams = flowData.streams;
            const badges = streams.map(s => {
                const bearing = Math.round(s.spatial?.bearing_to_fix || s.bearing_to_fix || 0);
                const count = s.track_count || s.callsigns?.length || 0;
                const dir = s.spatial?.cardinal || '';
                return `<span class="fa-stream-badge">${dir ? dir + ' ' : ''}${bearing}° <span class="fa-badge-count">${count}</span></span>`;
            }).join('');
            html += `
                <div class="fa-row fa-row-streams">
                    <i class="fas fa-water fa-row-icon"></i>
                    <span class="fa-row-label">${streams.length} stream${streams.length !== 1 ? 's' : ''}</span>
                    <span class="fa-stream-badges">${badges}</span>
                </div>`;
        }

        // ── Branches row ──
        if (branchData?.error) {
            const errMsg = branchData.timeout ? 'Branch analysis timed out' : 'Branch analysis unavailable';
            html += `
                <div class="fa-row fa-row-branches">
                    <i class="fas fa-code-branch fa-row-icon"></i>
                    <span class="fa-row-label" style="color:#6b7280;">${errMsg}</span>
                </div>`;
        } else if (branchData) {
            const bf = branchData.bearing_filter;
            const phase1 = branchData.corridors_phase1 || 0;
            const branchCount = branchState?.corridors?.length || branchData.branch_count || 0;
            const totalFlights = branchData.total_flights || 0;
            const ungrouped = branchData.ungrouped_flights || 0;

            // Summary stats
            let statsText = `${branchCount} branch${branchCount !== 1 ? 'es' : ''}`;
            if (phase1 && phase1 !== branchCount) statsText += ` from ${phase1} corridor${phase1 !== 1 ? 's' : ''}`;
            statsText += `, ${totalFlights} flights`;
            if (ungrouped > 0) statsText += ` (${ungrouped} ungrouped)`;

            // Filter badge
            let filterBadge = '';
            if (bf && bf.rejected > 0) {
                filterBadge = `<span class="fa-filter-badge" title="Bearing filter: ±${bf.filter_deg}° from ${bf.median_bearing}° median"><i class="fas fa-filter"></i> ${bf.rejected} filtered</span>`;
            }

            // Determine if branch list is expanded (Branches button active)
            const branchBtn = document.querySelector(`.branch-toggle-btn[data-map="${mapId}"]`);
            const isExpanded = branchBtn?.classList.contains('active');

            html += `
                <div class="fa-row fa-row-branches">
                    <i class="fas fa-code-branch fa-row-icon"></i>
                    <span class="fa-row-label">${statsText}</span>
                    ${filterBadge}`;

            // Branch list (expanded when Branches layer toggle is active)
            if (branchState?.corridors?.length > 0) {
                html += `
                    <button class="branch-select-btn" onclick="TMICompliance.selectAllBranches('${mapId}', true)">All</button>
                    <button class="branch-select-btn" onclick="TMICompliance.selectAllBranches('${mapId}', false)">None</button>
                </div>
                <div class="fa-branch-list" id="${mapId}_fa_branch_list" style="${isExpanded ? '' : 'display:none'}">`;

                // Sort: parents first, then their sub-branches grouped after
                const ordered = [];
                const subsByParent = new Map();
                branchState.corridors.forEach(bc => {
                    if (bc.isSubBranch && bc.parentCorridorId != null) {
                        const key = bc.parentCorridorId;
                        if (!subsByParent.has(key)) subsByParent.set(key, []);
                        subsByParent.get(key).push(bc);
                    } else {
                        ordered.push(bc);
                    }
                });
                const sortedCorridors = [];
                ordered.forEach(bc => {
                    sortedCorridors.push(bc);
                    const subs = subsByParent.get(bc.parentCorridorId) || [];
                    subs.forEach(sub => sortedCorridors.push(sub));
                });
                // Append any orphaned sub-branches
                subsByParent.forEach((subs, key) => {
                    if (!ordered.some(bc => bc.parentCorridorId === key)) {
                        subs.forEach(sub => sortedCorridors.push(sub));
                    }
                });

                (sortedCorridors.length > 0 ? sortedCorridors : branchState.corridors).forEach(bc => {
                    const compClass = bc.compliancePct >= 98 ? 'excellent' : bc.compliancePct >= 90 ? 'good' : bc.compliancePct >= 80 ? 'warn' : bc.compliancePct >= 65 ? 'poor' : 'bad';
                    const color = this.branchComplianceColor(bc.compliancePct);
                    const subClass = bc.isSubBranch ? ' branch-item-sub' : '';
                    const subIcon = bc.isSubBranch ? '<i class="fas fa-level-up-alt fa-rotate-90" style="font-size:0.65em; opacity:0.6; margin-right:3px" title="Sub-branch"></i>' : '';

                    html += `
                    <label class="branch-item${subClass}" title="${bc.longName}">
                        <input type="checkbox" ${bc.selected ? 'checked' : ''}
                               data-branch-id="${bc.branchId}" data-map="${mapId}"
                               onchange="TMICompliance.toggleBranchSelection(this)">
                        <span class="branch-color-swatch" style="background: ${color}"></span>
                        <span class="branch-name">${subIcon}${bc.shortName}</span>
                        <span class="branch-count">${bc.trackCount}<i class="fas fa-plane" style="font-size:0.7em; margin-left:3px"></i></span>
                        <span class="branch-compliance ${compClass}">${bc.compliancePct}%</span>
                    </label>`;
                });

                if (branchState.skippedSingle > 0) {
                    html += `<div class="fa-branch-note">${branchState.skippedSingle} single-flight corridor${branchState.skippedSingle > 1 ? 's' : ''} omitted</div>`;
                }
                html += '</div>';
            } else if (!branchState?.initialized) {
                // Still computing
                html += `
                    <span class="fa-computing"><i class="fas fa-spinner fa-spin"></i> computing corridors...</span>
                </div>`;
            } else if (branchState?.initialized && branchState.corridors?.length === 0) {
                // Initialized but no corridors found
                const skipNote = branchState.skippedSingle > 0 ? ` (${branchState.skippedSingle} single-flight)` : '';
                html += `
                    <span style="color:#6b7280; font-size:0.85em; margin-left:4px;">No distinct corridors detected${skipNote}</span>
                </div>`;
            } else {
                html += '</div>';
            }
        } else if (trajCount > 3) {
            // Branches not yet loaded, show loading state
            html += `
                <div class="fa-row fa-row-branches fa-loading">
                    <i class="fas fa-code-branch fa-row-icon"></i>
                    <span class="fa-row-label"><i class="fas fa-spinner fa-spin"></i> Analyzing branches...</span>
                </div>`;
        }

        section.innerHTML = html;
        section.style.display = html ? '' : 'none';
    },

    /**
     * Handle branch checkbox selection change.
     */
    toggleBranchSelection: function(checkbox) {
        const branchId = checkbox.dataset.branchId;
        const mapId = checkbox.dataset.map;
        const state = this.branchPanelState?.[mapId];
        if (!state?.corridors) return;

        const corridor = state.corridors.find(c => c.branchId === branchId);
        if (corridor) corridor.selected = checkbox.checked;

        this.updateBranchFeatures(mapId);
    },

    /**
     * Select or deselect all branches.
     */
    selectAllBranches: function(mapId, selectAll) {
        const state = this.branchPanelState?.[mapId];
        if (!state?.corridors) return;

        state.corridors.forEach(c => c.selected = selectAll);

        const branchList = document.getElementById(`${mapId}_fa_branch_list`);
        if (branchList) {
            branchList.querySelectorAll('input[type="checkbox"]').forEach(cb => cb.checked = selectAll);
        }

        this.updateBranchFeatures(mapId);
    },

    /**
     * Update branch corridor features on the map after selection change.
     */
    updateBranchFeatures: function(mapId) {
        const map = this.activeMaps[mapId];
        if (!map) return;

        const source = map.getSource('branch-corridors');
        if (!source) return;

        const features = this.buildBranchFeatures(mapId);
        source.setData({ type: 'FeatureCollection', features });
    },

    // =========================================================================
    // Discord Summary Copy
    // =========================================================================

    /**
     * Build NTML entries summary formatted for Discord markdown.
     * Returns a string under 2000 characters.
     */
    buildNtmlDiscordSummary: function() {
        const r = this.results;
        if (!r) return '';

        const mitResults = r.mit_results || {};
        const mitArray = Array.isArray(mitResults) ? mitResults : Object.values(mitResults);
        if (mitArray.length === 0) return '';

        const planId = r.plan_id || this.planId || '?';
        const eventWindow = `${r.event_start || '?'} to ${r.event_end || '?'}`;
        const trajCoverage = this.calculateTrajectoryCoverageV2();

        // Sort by pair count descending (most significant first)
        const sorted = [...mitArray].sort((a, b) => (b.pairs || 0) - (a.pairs || 0));

        // Aggregate stats for header
        const totalPairs = sorted.reduce((s, m) => s + (m.pairs || 0), 0);
        const totalViolations = sorted.reduce((s, m) => s + (m.violations ? m.violations.length : 0), 0);
        const totalCrossings = sorted.reduce((s, m) => s + (m.crossings || m.total_crossings || 0), 0);

        // Build header
        let lines = [];
        lines.push(`**TMI Compliance Analysis** \u2014 Plan ${planId}`);
        lines.push(eventWindow);
        lines.push('');
        lines.push(`**NTML ENTRIES** (${sorted.length} analyzed, ${totalCrossings} total crossings, ${totalPairs} pairs, ${totalViolations} violations)`);

        // Build each entry block
        const entryBlocks = [];
        for (const m of sorted) {
            const notation = this.formatStandardizedTMI(m);
            const crossings = m.crossings || m.total_crossings || 0;
            const pairs = m.pairs || 0;
            const compPct = m.compliance_pct !== null && m.compliance_pct !== undefined
                ? m.compliance_pct.toFixed(1) + '%' : 'N/A';
            const unitLabel = m.unit === 'min' ? 'min' : 'nm';
            const stats = m.spacing_stats || {};
            const avgSpacing = (stats.avg || m.avg_spacing || 0).toFixed(1);
            const minSpacing = (stats.min || m.min_spacing || 0).toFixed(1);
            const medianSpacing = stats.median ? stats.median.toFixed(1) : null;
            const violations = m.violations || [];

            let block = '';
            block += `\n> **${notation}**`;
            if (m.cancelled) block += ' *(CANCELLED)*';
            block += `\n> ${crossings} crossings, ${pairs} pairs | Compliance: **${compPct}**`;

            // Spacing line: avg / min / median
            let spacingDetail = `Avg: ${avgSpacing}${unitLabel}, Min: ${minSpacing}${unitLabel}`;
            if (medianSpacing) spacingDetail += `, Median: ${medianSpacing}${unitLabel}`;
            block += `\n> ${spacingDetail}`;

            // Violations detail
            if (violations.length > 0) {
                const worstShortfall = Math.max(...violations.map(v => Math.abs(v.shortfall_pct || 0)));
                block += `\n> ${violations.length} violation${violations.length !== 1 ? 's' : ''}, worst shortfall: -${worstShortfall.toFixed(0)}%`;
            }
            entryBlocks.push(block);
        }

        // Footer
        const footer = `\n*${trajCoverage}% trajectory coverage*`;

        // Assemble with truncation check
        const header = lines.join('\n');
        const MAX_LEN = 1950;
        let body = '';
        let included = 0;
        for (const block of entryBlocks) {
            const candidate = body + block;
            if ((header + candidate + footer).length > MAX_LEN && included > 0) {
                const remaining = entryBlocks.length - included;
                body += `\n\n*... and ${remaining} more entr${remaining === 1 ? 'y' : 'ies'} (see full report)*`;
                break;
            }
            body += block;
            included++;
        }

        return header + body + '\n' + footer;
    },

    /**
     * Build Ground Stop summary formatted for Discord markdown.
     * Returns a string under 2000 characters.
     */
    buildGsDiscordSummary: function() {
        const r = this.results;
        if (!r) return '';

        const gsResults = r.gs_results || {};
        const gsArray = Array.isArray(gsResults) ? gsResults : Object.values(gsResults);
        if (gsArray.length === 0) return '';

        const planId = r.plan_id || this.planId || '?';
        const eventWindow = `${r.event_start || '?'} to ${r.event_end || '?'}`;
        const trajCoverage = this.calculateTrajectoryCoverageV2();

        let lines = [];
        lines.push(`**TMI Compliance Analysis** \u2014 Plan ${planId}`);
        lines.push(eventWindow);

        for (const gs of gsArray) {
            const dests = (gs.destinations || []).join(', ') || '?';
            const gsStart = gs.gs_start || '?';
            const gsEnd = gs.gs_end || '?';

            // Ended-by status
            let endedBy = '';
            if (gs.ended_by === 'CNX' || gs.cancelled) endedBy = ' (CNX)';
            else if (gs.ended_by === 'EXPIRATION') endedBy = ' (EXPIRED)';

            lines.push('');
            lines.push(`**GROUND STOP:** ${dests}`);
            lines.push(`${gsStart} - ${gsEnd}${endedBy}`);

            if (gs.impacting_condition) {
                lines.push(gs.impacting_condition);
            }

            // Advisory chain (if program_timeline exists)
            const timeline = gs.program_timeline || [];
            if (timeline.length > 0) {
                const chain = timeline.map(adv => {
                    let tag = adv.type || '?';
                    if (adv.advzy) tag = `Adv ${adv.advzy} (${tag})`;
                    return tag;
                }).join(' \u2192 ');
                lines.push(`Advisories: ${chain}`);
            }

            // Stats
            const total = gs.total_flights || 0;
            const compliant = gs.compliant_count || 0;
            const nonComp = gs.non_compliant_count || 0;
            const exempt = gs.exempt_count || 0;
            const compPct = gs.compliance_pct !== null && gs.compliance_pct !== undefined
                ? gs.compliance_pct.toFixed(1) + '%' : 'N/A';

            lines.push('');
            lines.push(`> **Flights:** ${total} total, ${compliant} compliant, ${nonComp} non-compliant, ${exempt} exempt`);
            lines.push(`> **Compliance:** ${compPct}`);

            // Hold time stats with range
            const holdStats = gs.hold_time_stats || {};
            if (gs.avg_hold_time_min && gs.avg_hold_time_min > 0) {
                let holdLine = `**Avg Hold:** ${this.formatDuration(gs.avg_hold_time_min)}`;
                if (holdStats.min !== undefined && holdStats.max !== undefined) {
                    holdLine += ` (min ${this.formatDuration(holdStats.min)}, median ${this.formatDuration(holdStats.median)}, max ${this.formatDuration(holdStats.max)})`;
                }
                lines.push(`> ${holdLine}`);
            }

            // GS delay: Total / Maximum / Average (matches advisory format)
            const gsDelay = gs.gs_delay_stats || {};
            if (gsDelay.avg_delay_min && gsDelay.avg_delay_min > 0) {
                const total = gsDelay.total_delay_min ? Math.round(gsDelay.total_delay_min) : '?';
                const max = gsDelay.max_delay_min ? Math.round(gsDelay.max_delay_min) : '?';
                const avg = Math.round(gsDelay.avg_delay_min);
                lines.push(`> **Total, Maximum, Average Delays:** ${total} / ${max} / ${avg}`);
            }

            // Time source breakdown
            const tsb = gs.time_source_breakdown || {};
            const tsTotal = (tsb['off_utc'] || 0) + (tsb['out_utc+taxi'] || 0) + (tsb['first_seen'] || 0) + (tsb['first_seen+connect'] || 0);
            if (tsTotal > 0) {
                const parts = [];
                if (tsb['off_utc']) parts.push(`${tsb['off_utc']} wheels-off`);
                if (tsb['out_utc+taxi']) parts.push(`${tsb['out_utc+taxi']} gate+taxi`);
                if (tsb['first_seen'] || tsb['first_seen+connect']) parts.push(`${(tsb['first_seen'] || 0) + (tsb['first_seen+connect'] || 0)} first-seen`);
                lines.push(`> Time sources: ${parts.join(', ')}`);
            }

            // Per-origin breakdown
            const perOrigin = gs.per_origin_breakdown || [];
            if (perOrigin.length > 0) {
                const sortedOrigins = [...perOrigin].sort((a, b) => (b.total || 0) - (a.total || 0));

                lines.push('');
                lines.push('**Per-Origin Breakdown:**');

                const maxOrigins = 10;
                const shown = sortedOrigins.slice(0, maxOrigins);
                for (const o of shown) {
                    const oPct = (o.compliance_pct || 0).toFixed(0);
                    const holdStr = o.avg_hold_time_min ? `, hold ${this.formatDuration(o.avg_hold_time_min)}` : '';
                    const delayStr = o.avg_gs_delay_min ? `, delay ${this.formatDuration(o.avg_gs_delay_min)}` : '';
                    lines.push(`> \`${o.origin}\` \u2014 ${o.total || 0} flt, ${oPct}% comp${holdStr}${delayStr}`);
                }
                if (sortedOrigins.length > maxOrigins) {
                    lines.push(`> *... and ${sortedOrigins.length - maxOrigins} more origin${sortedOrigins.length - maxOrigins === 1 ? '' : 's'}*`);
                }
            }
        }

        // Footer
        lines.push('');
        lines.push(`*${trajCoverage}% trajectory coverage*`);

        let text = lines.join('\n');

        // Truncation safety
        if (text.length > 1950) {
            while (text.length > 1950 && lines.length > 5) {
                lines.splice(lines.length - 2, 1);
                text = lines.join('\n');
            }
        }

        return text;
    },

    /**
     * Copy NTML summary to clipboard for Discord posting.
     */
    copyNtmlSummary: function() {
        const text = this.buildNtmlDiscordSummary();
        if (!text) {
            Swal.fire({ icon: 'warning', title: 'No NTML data to copy', toast: true,
                        position: 'top-end', showConfirmButton: false, timer: 2000 });
            return;
        }
        this._copyToClipboard(text);
    },

    /**
     * Copy Ground Stop summary to clipboard for Discord posting.
     */
    copyGsSummary: function() {
        const text = this.buildGsDiscordSummary();
        if (!text) {
            Swal.fire({ icon: 'warning', title: 'No Ground Stop data to copy', toast: true,
                        position: 'top-end', showConfirmButton: false, timer: 2000 });
            return;
        }
        this._copyToClipboard(text);
    },

    /**
     * Copy text to clipboard with fallback for older browsers.
     */
    _copyToClipboard: function(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function() {
                Swal.fire({ icon: 'success', title: 'Copied to clipboard', toast: true,
                            position: 'top-end', showConfirmButton: false, timer: 2000 });
            }).catch(function() {
                TMICompliance._fallbackCopy(text);
            });
        } else {
            this._fallbackCopy(text);
        }
    },

    _fallbackCopy: function(text) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        Swal.fire({ icon: 'success', title: 'Copied to clipboard', toast: true,
                    position: 'top-end', showConfirmButton: false, timer: 2000 });
    },
};

// Initialize when document is ready
$(document).ready(function() {
    TMICompliance.init();
});
