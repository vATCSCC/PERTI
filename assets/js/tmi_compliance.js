/**
 * TMI Compliance Analysis Module
 * Handles loading and displaying TMI compliance results in PERTI review
 */

const TMICompliance = {
    planId: null,
    results: null,

    // View mode for exempt flights: 'scale' (to-scale with dashed) or 'collapsed' (discontinuity)
    exemptViewMode: 'scale',

    // Filters for TMI results
    filters: {
        requestor: '',      // Filter by requestor facility
        provider: '',       // Filter by provider facility
        minValue: '',       // Min MIT/MINIT value
        maxValue: '',       // Max MIT/MINIT value
        hourStart: '',      // Start hour (0-23)
        hourEnd: '',        // End hour (0-23)
        tmiType: ''         // 'MIT', 'MINIT', or '' for all
    },

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
        if (!window.planData) return;

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
            ntml_text: $('#tmi_ntml_input').val()
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
            }
        });
    },

    loadConfig: function(silent = false) {
        if (!this.planId) {
            if (!silent) $('#ntml_save_status').text('No plan ID').addClass('text-danger');
            return;
        }

        if (!silent) $('#ntml_save_status').text('Loading...');

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
                if (!silent) $('#ntml_save_status').text('Load error').addClass('text-danger');
            }
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
                    this.renderResults();
                    $('#tmi_status').text(`Loaded: ${response.data.event}`);
                } else {
                    $('#tmi_status').text(response.message || 'No results found');
                    this.showNoData();
                }
            },
            error: (xhr, status, error) => {
                $('#load_tmi_results').prop('disabled', false);
                $('#tmi_status').text('Error loading results');
                this.showError(`Failed to load results: ${error}`);
            }
        });
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
                confirmButtonText: 'OK'
            });
            return;
        }

        // Confirm before running
        Swal.fire({
            title: 'Run TMI Compliance Analysis?',
            html: `
                <div class="text-left small">
                    <p>This will analyze flight data against the configured TMIs for Plan ${this.planId}.</p>
                    <p><strong>Note:</strong> Analysis typically takes 30-60 seconds depending on traffic volume.</p>
                    <p class="text-warning"><i class="fas fa-exclamation-triangle"></i>
                       Make sure your TMI configuration is saved before running.</p>
                </div>
            `,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Run Analysis',
            cancelButtonText: 'Cancel'
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
            { text: 'Generating compliance report...', icon: 'fa-chart-bar', time: 55 }
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
                    <div class="d-flex justify-content-between mt-2 small" style="color: #888;">
                        <span id="analysis_elapsed">0:00</span>
                        <span id="analysis_step">Step 1 of ${analysisSteps.length}</span>
                    </div>
                    <div id="analysis_steps_list" class="mt-3 text-left small" style="max-height: 150px; overflow-y: auto;">
                    </div>
                </div>
            `,
            allowOutsideClick: false,
            showConfirmButton: false,
            width: 450
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

        // Call API with run=true
        $.ajax({
            url: `api/analysis/tmi_compliance.php?p_id=${this.planId}&run=true`,
            method: 'GET',
            dataType: 'json',
            timeout: 180000, // 3 minute timeout
            success: (response) => {
                clearInterval(progressInterval);
                this.analysisInProgress = false;

                const elapsed = ((Date.now() - startTime) / 1000).toFixed(1);

                if (response.success && response.data) {
                    this.results = response.data;

                    // Count results
                    const mitCount = response.data.mit_results?.length || 0;
                    const gsCount = response.data.gs_results?.length || 0;
                    const apreqCount = response.data.apreq_results?.length || 0;

                    Swal.fire({
                        icon: 'success',
                        title: 'Analysis Complete',
                        html: `
                            <div class="text-center">
                                <p>TMI compliance analysis completed in <strong>${elapsed}s</strong></p>
                                <div class="mt-3 small" style="color: #888;">
                                    <div><i class="fas fa-ruler-horizontal text-info mr-2"></i>${mitCount} MIT/MINIT restriction${mitCount !== 1 ? 's' : ''}</div>
                                    <div><i class="fas fa-plane-slash text-warning mr-2"></i>${gsCount} ground stop${gsCount !== 1 ? 's' : ''}</div>
                                    <div><i class="fas fa-clipboard-check text-success mr-2"></i>${apreqCount} APREQ/CFR${apreqCount !== 1 ? 's' : ''}</div>
                                </div>
                            </div>
                        `,
                        timer: 3000,
                        showConfirmButton: false
                    });
                    this.renderResults();
                    $('#tmi_status').text(`Analysis complete: ${response.data.event}`);
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Analysis Failed',
                        html: `<p>${response.message || response.error || 'Unknown error occurred'}</p>
                               <p class="small text-muted">Check that the Azure Function is configured and TMI config is saved.</p>`
                    });
                }
            },
            error: (xhr, status, error) => {
                clearInterval(progressInterval);
                this.analysisInProgress = false;

                const elapsed = ((Date.now() - startTime) / 1000).toFixed(1);
                let errorMsg = error;
                if (status === 'timeout') {
                    errorMsg = `Analysis timed out after ${elapsed}s. The event may have too many flights.`;
                } else if (xhr.responseJSON?.error) {
                    errorMsg = xhr.responseJSON.error;
                }

                Swal.fire({
                    icon: 'error',
                    title: 'Analysis Failed',
                    html: `<p>${errorMsg}</p>
                           <p class="small text-muted">If the Azure Function is not configured, you can still run analysis locally:<br>
                           <code>python scripts/tmi_compliance/run.py --plan_id ${this.planId}</code></p>`
                });
            }
        });
    },

    detailIdCounter: 0,

    renderResults: function() {
        if (!this.results) {
            this.showNoData();
            return;
        }

        this.detailIdCounter = 0;
        let html = '';

        // Summary card
        const summary = this.results.summary || {};
        const mitCompliance = summary.mit?.compliance_pct || 0;
        const gsResults = this.results.gs_results || {};
        const hasGroundStops = Object.keys(gsResults).length > 0 || (Array.isArray(gsResults) && gsResults.length > 0);
        const gsCompliance = hasGroundStops ? (summary.gs?.compliance_pct ?? 100) : null;
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
                if (r.requestor) uniqueRequestors.add(r.requestor);
                if (r.provider) uniqueProviders.add(r.provider);
                if (r.required) uniqueValues.add(r.required);
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

            html += '<h6 class="text-primary mb-3"><i class="fas fa-ruler-horizontal"></i> Miles-In-Trail (MIT/MINIT)</h6>';

            // Apply filters and render
            let visibleCount = 0;
            let filteredCount = 0;
            for (const r of mitResultsArray) {
                // Skip entries with no data
                if (r.pairs === 0 && r.message) continue;

                // Apply filters
                if (!this.matchesFilters(r)) {
                    filteredCount++;
                    continue;
                }

                visibleCount++;
                html += this.renderMitCard(r);
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
            html += '<h6 class="text-danger mb-3 mt-4"><i class="fas fa-ban"></i> Ground Stops</h6>';

            for (const r of gsResultsArray) {
                html += this.renderGsCard(r);
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
            if (f.tmiType === 'MIT' && isMINIT) return false;
            if (f.tmiType === 'MINIT' && !isMINIT) return false;
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
            tmiType: ''
        };
        this.renderResults();
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
        if (!allPairs || allPairs.length === 0) return { longestGood: 0, currentGood: 0, goodPeriods: [] };

        let longestGood = 0;
        let currentGood = 0;
        let currentStreak = 0;
        let goodPeriods = [];
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
                        end: allPairs[i-1]?.curr_time || streakStart
                    });
                }
                if (currentStreak > longestGood) longestGood = currentStreak;
                currentStreak = 0;
            }
        }

        // Handle final streak
        if (currentStreak > longestGood) longestGood = currentStreak;
        if (currentStreak >= 3) {
            goodPeriods.push({
                length: currentStreak,
                start: streakStart,
                end: allPairs[allPairs.length - 1]?.curr_time || streakStart
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
        if (destinations) formatted += `${destinations} `;
        if (isRealFix) formatted += `via ${fix} `;
        formatted += `${required}${r.unit === 'min' ? 'MINIT' : 'MIT'} `;
        if (requestor || provider) formatted += `${requestor}:${provider} `;
        formatted += `${tmiStart}-${tmiEnd}`;

        return formatted.trim();
    },

    // Render horizontal spacing diagram (visual timeline)
    renderSpacingDiagram: function(allPairs, required, unit, diagramId) {
        if (!allPairs || allPairs.length === 0) return '';

        const unitLabel = unit === 'min' ? 'min' : 'nm';
        const isMinit = unit === 'min';

        // Build crossings list (unique flights at their crossing times)
        const crossings = [];
        if (allPairs.length > 0) {
            crossings.push({
                callsign: allPairs[0].prev_callsign,
                time: allPairs[0].prev_time,
                dept: allPairs[0].prev_dept || '',
                dest: allPairs[0].prev_dest || '',
                color: '#6c757d'
            });
            allPairs.forEach(p => {
                // Determine color based on category
                let color = '#6c757d';
                if (p.spacing_category === 'UNDER') color = '#dc3545';
                else if (p.spacing_category === 'WITHIN') color = '#28a745';
                else if (p.spacing_category === 'OVER') color = '#17a2b8';
                else if (p.spacing_category === 'GAP') color = '#ffc107';

                crossings.push({
                    callsign: p.curr_callsign,
                    time: p.curr_time,
                    dept: p.curr_dept || '',
                    dest: p.curr_dest || '',
                    spacingFromPrev: p.spacing,
                    category: p.spacing_category,
                    isExempt: p.is_exempt || false,
                    color: color
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

        // Build SVG content in layers: background -> lines -> labels -> dots (on top)
        let bgContent = '';
        let lineContent = '';
        let labelContent = '';
        let dotContent = '';

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

            // Dot (flight marker) - drawn LAST so it's on top of lines
            dotContent += `<circle cx="${x}" cy="60" r="8" fill="${c.color}" class="flight-marker" data-callsign="${c.callsign}" data-time="${c.time}" data-dept="${c.dept}" data-dest="${c.dest}" style="cursor: pointer;" onclick="TMICompliance.showFlightPopup(this)"/>`;
        });

        // Assemble SVG: background, lines, labels, then dots on top (no legend inside SVG)
        const svgContent = bgContent + lineContent + labelContent + dotContent;

        // Legend as HTML element outside SVG (sticky positioned)
        const legendHtml = `
            <div class="spacing-diagram-legend">
                <span class="legend-item"><span class="legend-line" style="background:#000;"></span> Req: ${required}${unitLabel}</span>
                <span class="legend-item"><span class="legend-box" style="background:#dc3545;"></span> Under</span>
                <span class="legend-item"><span class="legend-box" style="background:#28a745;"></span> OK</span>
                <span class="legend-item"><span class="legend-box" style="background:#17a2b8;"></span> Over</span>
                <span class="legend-item"><span class="legend-box" style="background:#ffc107;"></span> Gap</span>
                <span class="legend-item"><span class="legend-line legend-dashed"></span> Exempt</span>
            </div>
        `;

        return `
            <div class="spacing-diagram-container" id="${diagramId}">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="small text-muted"><i class="fas fa-chart-line"></i> Spacing Timeline (${isMinit ? 'time-based' : 'distance-based'}) - ${crossings.length} flights</span>
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
            width: '400px'
        });
    },

    // Render event statistics section
    renderEventStatistics: function() {
        if (!this.results) return '';

        const summary = this.results.summary || {};
        const mitResults = this.results.mit_results || {};
        const gsResults = this.results.gs_results || {};

        // Calculate totals
        let totalCrossings = 0;
        let totalPairs = 0;
        let totalViolations = 0;
        let uniqueFlights = new Set();

        // Process MIT results (could be object or array)
        const mitResultsArray = Array.isArray(mitResults) ? mitResults : Object.values(mitResults);
        for (const r of mitResultsArray) {
            totalCrossings += r.total_crossings || r.crossings || 0;
            totalPairs += r.pairs || 0;
            if (r.violations?.total) totalViolations += r.violations.total;
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
        if (!this.results) return '';

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
                    cancelled: r.cancelled || false
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
            const airportCode = keyParts.length >= 3 ? keyParts[2].replace(/^K/, '') : 'GS';
            allTmis.push({
                type: 'GS',
                label: r.destination || airportCode,
                sublabel: 'Ground Stop',
                start: r.gs_start,
                end: r.gs_end,
                compliance: r.compliance_pct,
                cardId: `gs_detail_${mitArray.length + i + 1}`,
                cancelled: r.cancelled || false
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
                cancelled: r.cancelled || false
            });
        });

        if (allTmis.length === 0) return '';

        // Parse event start/end times
        const eventStart = this.parseEventTime(this.results.event_start);
        const eventEnd = this.parseEventTime(this.results.event_end);

        if (!eventStart || !eventEnd) return '';

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

            if (!tmiStart || !tmiEnd) return;

            // Calculate bar position
            const startOffset = Math.max(0, (tmiStart - eventStart) / eventDurationMs);
            const endOffset = Math.min(1, (tmiEnd - eventStart) / eventDurationMs);
            const barX = leftMargin + (startOffset * timelineWidth);
            const barWidth = Math.max(10, (endOffset - startOffset) * timelineWidth);

            // Determine color based on type
            let barColor = '#007bff'; // MIT default blue
            if (tmi.type === 'GS') barColor = '#dc3545'; // Red for ground stop
            else if (tmi.type === 'APREQ') barColor = '#17a2b8'; // Cyan for APREQ

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
        if (!timeStr) return null;
        // If no timezone indicator (Z, +, or -), append Z to parse as UTC
        if (!timeStr.endsWith('Z') && !timeStr.includes('+') && !/T\d{2}:\d{2}:\d{2}-/.test(timeStr)) {
            timeStr += 'Z';
        }
        const d = new Date(timeStr);
        return isNaN(d.getTime()) ? null : d;
    },

    // Parse TMI time string (format: "HH:MMZ" or "HHMM") relative to event date
    parseTmiTime: function(timeStr, eventStart) {
        if (!timeStr || !eventStart) return null;

        // Extract hours and minutes from various formats
        let match = timeStr.match(/(\d{2}):?(\d{2})Z?/);
        if (!match) return null;

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

        let html = `
            <div class="tmi-card mit-card">
                <!-- TMI Header with standardized notation -->
                <div class="tmi-header">
                    <div>
                        <span class="tmi-fix-name">${displayName}</span>
                        <span class="tmi-type-badge ml-2">${required}${unitLabel} ${tmiType}</span>
                        <span class="text-muted ml-2">| ${r.tmi_start || ''} - ${r.tmi_end || ''}</span>
                        ${r.cancelled ? '<span class="badge badge-warning ml-2">CANCELLED</span>' : ''}
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
                ${allPairs.length > 0 ? this.renderSpacingDiagram(allPairs, required, r.unit, diagramId) : ''}
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

        // Add context map section
        const mapId = `mit_map_${this.detailIdCounter}`;
        html += this.renderMapSection(r, mapId);

        html += '</div>';
        return html;
    },

    renderGsCard: function(r) {
        const compPct = r.compliance_pct || 0;
        const compClass = this.getComplianceClass(compPct);
        const detailId = `gs_detail_${++this.detailIdCounter}`;

        // Handle both naming conventions (exempt_flights OR exempt)
        const exemptFlights = r.exempt_flights || r.exempt || [];
        const compliantFlights = r.compliant_flights || r.compliant || [];
        const nonCompliantFlights = r.non_compliant_flights || r.non_compliant || [];
        const exemptCount = r.exempt_count || exemptFlights.length;
        const compliantCount = r.compliant_count || compliantFlights.length;
        const nonCompliantCount = r.non_compliant_count || r.violations?.total || nonCompliantFlights.length;

        let html = `
            <div class="tmi-card gs-card">
                <div class="tmi-header">
                    <div>
                        <span class="tmi-fix-name">Ground Stop</span>
                        <span class="text-muted ml-2">
                            ${(r.destinations || []).join(', ')} |
                            ${r.gs_start || ''} - ${r.gs_end || ''} |
                            Issued: ${r.gs_issued || 'N/A'}
                        </span>
                    </div>
                    <div class="compliance-badge ${compClass}">${compPct.toFixed(1)}%</div>
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
                        <div class="tmi-stat-value text-success">${compliantCount}</div>
                        <div class="tmi-stat-label">Compliant</div>
                    </div>
                    <div class="tmi-stat">
                        <div class="tmi-stat-value text-danger">${nonCompliantCount}</div>
                        <div class="tmi-stat-label">Violations</div>
                    </div>
                </div>
        `;

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

            // Non-compliant flights (violations)
            if (nonCompliantFlights.length > 0) {
                html += `
                    <div class="col-md-4">
                        <h6 class="text-danger"><i class="fas fa-times-circle"></i> Violations (${nonCompliantFlights.length})</h6>
                        <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                            <table class="table table-sm table-striped">
                                <thead class="thead-dark sticky-top">
                                    <tr><th>Callsign</th><th>Origin</th><th>Dept Time</th><th>Into GS</th></tr>
                                </thead>
                                <tbody>
                                    ${nonCompliantFlights.map(f => `
                                        <tr class="table-danger">
                                            <td><code>${f.callsign}</code></td>
                                            <td>${f.dept || 'N/A'}</td>
                                            <td>${f.dept_time || 'N/A'}</td>
                                            <td>${f.pct_into_gs ? f.pct_into_gs + '%' : ''}</td>
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
                                            <td>${f.dept_time || 'N/A'}</td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                    </div>
                `;
            }

            // Compliant flights
            if (compliantFlights.length > 0) {
                html += `
                    <div class="col-md-4">
                        <h6 class="text-success"><i class="fas fa-check"></i> Compliant (${compliantFlights.length})</h6>
                        <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                            <table class="table table-sm table-striped">
                                <thead class="thead-light sticky-top">
                                    <tr><th>Callsign</th><th>Origin</th><th>Dept Time</th></tr>
                                </thead>
                                <tbody>
                                    ${compliantFlights.map(f => `
                                        <tr>
                                            <td><code>${f.callsign}</code></td>
                                            <td>${f.dept || 'N/A'}</td>
                                            <td>${f.dept_time || 'N/A'}</td>
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

        html += '</div>';
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

        let html = `
            <div class="tmi-card apreq-card">
                <div class="tmi-header">
                    <div>
                        <span class="tmi-fix-name">APREQ/CFR: ${displayName}</span>
                        <span class="text-muted ml-2">
                            ${isRealFix ? dests + ' | ' : ''}${r.tmi_start || ''} - ${r.tmi_end || ''}
                        </span>
                        ${r.cancelled ? '<span class="badge badge-warning ml-2">CANCELLED</span>' : ''}
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

        html += '</div>';
        return html;
    },

    renderDelaySection: function(delays) {
        if (!delays || delays.length === 0) return '';

        // Group delays by airport
        const byAirport = {};
        delays.forEach(d => {
            const apt = d.airport || 'UNKNOWN';
            if (!byAirport[apt]) byAirport[apt] = [];
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
        if (!dist) return '';

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
        if (pct >= 90) return 'good';
        if (pct >= 75) return 'warn';
        return 'bad';
    },

    // =========================================================================
    // TMI CONTEXT MAP RENDERING
    // =========================================================================

    // Cache for map data (keyed by requestor_provider_fix)
    mapDataCache: {},

    // Cache for flight trajectories (keyed by mapId)
    trajectoryCache: {},

    // Track active maps for cleanup
    activeMaps: {},

    /**
     * Render a collapsible map section for a TMI
     */
    renderMapSection: function(r, mapId) {
        const requestor = (r.requestor || '').replace(/'/g, "\\'");
        const provider = (r.provider || '').replace(/'/g, "\\'");
        const fix = (r.fix || '').replace(/'/g, "\\'");
        const destinations = r.destinations || [];
        const origins = r.origins || [];

        // Cache trajectories if available (for flight track rendering)
        if (r.trajectories && Object.keys(r.trajectories).length > 0) {
            this.trajectoryCache[mapId] = r.trajectories;
            console.log(`Cached ${Object.keys(r.trajectories).length} trajectories for ${mapId}`);
        }

        // Cache traffic sector data if available (include required spacing for arc rendering)
        if (r.traffic_sector) {
            this.trafficSectorCache = this.trafficSectorCache || {};
            this.trafficSectorCache[mapId] = {
                ...r.traffic_sector,
                required_spacing: r.required || 0,
                unit: r.unit || 'nm'
            };
            console.log(`Cached traffic sector for ${mapId}: ${r.traffic_sector.track_count} tracks, ${r.traffic_sector.sector_75.width_deg}° (75%), ${r.traffic_sector.sector_90.width_deg}° (90%), ${r.required}${r.unit} spacing`);
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
                    <span class="small ml-2">(${displayReq}${displayProv ? ' → ' + displayProv : ''})</span>
                </div>
                <div class="tmi-map-collapse" id="${mapId}" style="display: none;">
                    <div class="tmi-map-container mt-2" id="${mapId}_container">
                        <div class="d-flex align-items-center justify-content-center h-100" style="color: #9090a0;">
                            <i class="fas fa-spinner fa-spin mr-2"></i> Loading map...
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
            origins: JSON.stringify(origins || [])
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
                    let mapData = data.map_data;

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
                    if (requestor && /^Z[A-Z]{2}$/.test(requestor)) artccCodes.push(requestor);
                    if (provider && /^Z[A-Z]{2}$/.test(provider) && provider !== requestor) artccCodes.push(provider);
                    if (artccCodes.length > 0) {
                        console.log('Loading sector boundaries for ARTCCs:', artccCodes.join(', '));
                        const sectors = await this.loadLocalSectorBoundaries(artccCodes, provider);
                        if (sectors.length > 0) {
                            mapData.sectors = sectors;
                            console.log(`Loaded ${sectors.length} sector boundaries`);
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
        if (!container) return;

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
                glyphs: 'https://cdn.protomaps.com/fonts/pbf/{fontstack}/{range}.pbf',
                sources: {
                    'carto-dark': {
                        type: 'raster',
                        tiles: ['https://a.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}.png'],
                        tileSize: 256,
                        attribution: '&copy; OpenStreetMap contributors'
                    }
                },
                layers: [{
                    id: 'carto-dark-layer',
                    type: 'raster',
                    source: 'carto-dark',
                    minzoom: 0,
                    maxzoom: 19
                }]
            },
            center: center,
            zoom: zoom,
            attributionControl: false
        });

        this.activeMaps[mapId] = map;

        map.on('load', () => {
            // Add facility boundaries (provider emphasized as it manages the stream)
            if (mapData.facilities?.length) {
                map.addSource('facilities', {
                    type: 'geojson',
                    data: { type: 'FeatureCollection', features: mapData.facilities }
                });

                map.addLayer({
                    id: 'facilities-fill',
                    type: 'fill',
                    source: 'facilities',
                    paint: {
                        'fill-color': ['case',
                            ['==', ['get', 'role'], 'provider'], '#4dabf7',
                            ['==', ['get', 'role'], 'requestor'], '#ff6b6b',
                            '#888888'],
                        // Provider is emphasized (manages the traffic stream/MIT)
                        'fill-opacity': ['case',
                            ['==', ['get', 'role'], 'provider'], 0.25,
                            0.1]
                    }
                });

                map.addLayer({
                    id: 'facilities-outline',
                    type: 'line',
                    source: 'facilities',
                    paint: {
                        'line-color': ['case',
                            ['==', ['get', 'role'], 'provider'], '#4dabf7',
                            ['==', ['get', 'role'], 'requestor'], '#ff6b6b',
                            '#888888'],
                        // Thicker line for provider (manages the stream)
                        'line-width': ['case',
                            ['==', ['get', 'role'], 'provider'], 3,
                            1.5],
                        'line-opacity': ['case',
                            ['==', ['get', 'role'], 'provider'], 1,
                            0.6]
                    }
                });

                map.addLayer({
                    id: 'facilities-labels',
                    type: 'symbol',
                    source: 'facilities',
                    layout: {
                        'text-field': ['get', 'code'],
                        'text-font': ['Noto Sans Regular'],
                        'text-size': 12,
                        'text-anchor': 'center'
                    },
                    paint: {
                        'text-color': '#ffffff',
                        'text-halo-color': '#000000',
                        'text-halo-width': 1
                    }
                });

                console.log(`Added ${mapData.facilities.length} facility boundaries to map`);
            }

            // Add sector boundaries (less prominent than facility boundaries)
            if (mapData.sectors?.length) {
                map.addSource('sectors', {
                    type: 'geojson',
                    data: { type: 'FeatureCollection', features: mapData.sectors }
                });

                // Insert below facilities if they exist
                const insertBefore = mapData.facilities?.length ? 'facilities-fill' : undefined;

                // Sector fills - very subtle
                map.addLayer({
                    id: 'sectors-fill',
                    type: 'fill',
                    source: 'sectors',
                    paint: {
                        'fill-color': ['case',
                            ['==', ['get', 'role'], 'provider'], '#4dabf7',
                            '#888888'],
                        'fill-opacity': 0.05
                    }
                }, insertBefore);

                // Sector outlines - thin dashed lines
                map.addLayer({
                    id: 'sectors-outline',
                    type: 'line',
                    source: 'sectors',
                    paint: {
                        'line-color': ['case',
                            ['==', ['get', 'role'], 'provider'], '#4dabf7',
                            '#888888'],
                        'line-width': 0.75,
                        'line-opacity': 0.5,
                        'line-dasharray': [3, 2]
                    }
                }, insertBefore);

                // Sector labels - only show at higher zoom levels
                map.addLayer({
                    id: 'sectors-labels',
                    type: 'symbol',
                    source: 'sectors',
                    minzoom: 6,  // Only show labels when zoomed in
                    layout: {
                        'text-field': ['get', 'code'],
                        'text-font': ['Noto Sans Regular'],
                        'text-size': 10,
                        'text-anchor': 'center',
                        'text-allow-overlap': false,
                        'symbol-placement': 'point'
                    },
                    paint: {
                        'text-color': '#adb5bd',
                        'text-halo-color': '#000000',
                        'text-halo-width': 1,
                        'text-opacity': 0.7
                    }
                });

                console.log(`Added ${mapData.sectors.length} sector boundaries to map`);
            }

            // Add shared boundary (handoff line) - emphasized
            if (mapData.shared_boundary) {
                map.addSource('shared-boundary', {
                    type: 'geojson',
                    data: mapData.shared_boundary
                });

                map.addLayer({
                    id: 'shared-boundary-glow',
                    type: 'line',
                    source: 'shared-boundary',
                    paint: {
                        'line-color': '#ffd43b',
                        'line-width': 8,
                        'line-opacity': 0.3,
                        'line-blur': 2
                    }
                });

                map.addLayer({
                    id: 'shared-boundary-line',
                    type: 'line',
                    source: 'shared-boundary',
                    paint: {
                        'line-color': '#ffd43b',
                        'line-width': 4,
                        'line-opacity': 1,
                        'line-dasharray': [2, 1]
                    }
                });
            }

            // Add fixes
            if (mapData.fixes?.length) {
                map.addSource('fixes', {
                    type: 'geojson',
                    data: { type: 'FeatureCollection', features: mapData.fixes }
                });

                map.addLayer({
                    id: 'fixes-circles',
                    type: 'circle',
                    source: 'fixes',
                    paint: {
                        'circle-radius': 6,
                        'circle-color': '#51cf66',
                        'circle-stroke-width': 2,
                        'circle-stroke-color': '#ffffff'
                    }
                });

                map.addLayer({
                    id: 'fixes-labels',
                    type: 'symbol',
                    source: 'fixes',
                    layout: {
                        'text-field': ['get', 'name'],
                        'text-font': ['Noto Sans Regular'],
                        'text-size': 11,
                        'text-offset': [0, 1.5],
                        'text-anchor': 'top'
                    },
                    paint: {
                        'text-color': '#51cf66',
                        'text-halo-color': '#000000',
                        'text-halo-width': 1
                    }
                });
            }

            // Add airports
            if (mapData.airports?.length) {
                map.addSource('airports', {
                    type: 'geojson',
                    data: { type: 'FeatureCollection', features: mapData.airports }
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
                        'circle-stroke-color': '#ffffff'
                    }
                });

                map.addLayer({
                    id: 'airports-labels',
                    type: 'symbol',
                    source: 'airports',
                    layout: {
                        'text-field': ['get', 'code'],
                        'text-font': ['Noto Sans Regular'],
                        'text-size': 10,
                        'text-offset': [0, -1.2],
                        'text-anchor': 'bottom'
                    },
                    paint: {
                        'text-color': '#ffffff',
                        'text-halo-color': '#000000',
                        'text-halo-width': 1
                    }
                });
            }

            // Add flight trajectories with gap visualization
            const trajectories = TMICompliance.trajectoryCache[mapId];
            if (trajectories && Object.keys(trajectories).length > 0) {
                const solidFeatures = [];  // Normal segments (gaps <= 5 min)
                const dashedFeatures = []; // Sparse data segments (gaps > 5 min, <= 15 min)

                const GAP_DASHED = 5 * 60;  // 5 minutes in seconds
                const GAP_BREAK = 15 * 60;  // 15 minutes in seconds

                Object.entries(trajectories).forEach(([callsign, traj]) => {
                    if (!traj.coordinates || traj.coordinates.length < 2) return;

                    const props = {
                        callsign: callsign,
                        dept: traj.properties?.dept || '',
                        dest: traj.properties?.dest || ''
                    };

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
                                solidFeatures.push({
                                    type: 'Feature',
                                    properties: props,
                                    geometry: { type: 'LineString', coordinates: currentSolid.map(c => [c[0], c[1]]) }
                                });
                            }
                            currentSolid = [curr];
                        } else if (gap > GAP_DASHED) {
                            // Gap > 5 min: end solid segment, add dashed connector, start new solid
                            if (currentSolid.length >= 2) {
                                solidFeatures.push({
                                    type: 'Feature',
                                    properties: props,
                                    geometry: { type: 'LineString', coordinates: currentSolid.map(c => [c[0], c[1]]) }
                                });
                            }
                            // Add dashed line between last solid point and current
                            dashedFeatures.push({
                                type: 'Feature',
                                properties: props,
                                geometry: { type: 'LineString', coordinates: [[prev[0], prev[1]], [curr[0], curr[1]]] }
                            });
                            currentSolid = [curr];
                        } else {
                            // Normal gap: continue solid segment
                            currentSolid.push(curr);
                        }
                    }

                    // Add final solid segment
                    if (currentSolid.length >= 2) {
                        solidFeatures.push({
                            type: 'Feature',
                            properties: props,
                            geometry: { type: 'LineString', coordinates: currentSolid.map(c => [c[0], c[1]]) }
                        });
                    }
                });

                // Add solid flight tracks
                if (solidFeatures.length > 0) {
                    map.addSource('flight-tracks-solid', {
                        type: 'geojson',
                        data: { type: 'FeatureCollection', features: solidFeatures }
                    });

                    map.addLayer({
                        id: 'flight-tracks-solid-glow',
                        type: 'line',
                        source: 'flight-tracks-solid',
                        layout: { 'line-cap': 'round', 'line-join': 'round' },
                        paint: {
                            'line-color': '#4dabf7',
                            'line-width': 4,
                            'line-opacity': 0.3,
                            'line-blur': 3
                        }
                    });

                    map.addLayer({
                        id: 'flight-tracks-solid',
                        type: 'line',
                        source: 'flight-tracks-solid',
                        layout: { 'line-cap': 'round', 'line-join': 'round' },
                        paint: {
                            'line-color': '#74c0fc',
                            'line-width': 1.5,
                            'line-opacity': 0.8
                        }
                    });
                }

                // Add dashed flight tracks (data gaps > 5 min)
                if (dashedFeatures.length > 0) {
                    map.addSource('flight-tracks-dashed', {
                        type: 'geojson',
                        data: { type: 'FeatureCollection', features: dashedFeatures }
                    });

                    map.addLayer({
                        id: 'flight-tracks-dashed',
                        type: 'line',
                        source: 'flight-tracks-dashed',
                        layout: { 'line-cap': 'round', 'line-join': 'round' },
                        paint: {
                            'line-color': '#74c0fc',
                            'line-width': 1.5,
                            'line-opacity': 0.5,
                            'line-dasharray': [4, 4]
                        }
                    });
                }

                console.log(`Added flight tracks: ${solidFeatures.length} solid, ${dashedFeatures.length} dashed segments`);
            }

            // Add traffic flow sectors (75% and 90% capture zones)
            const sectorData = TMICompliance.trafficSectorCache?.[mapId];
            if (sectorData) {
                const sectorFeatures = [];
                // Sector radius: at least 30nm, or enough to show 2-3 spacing arcs
                const spacing = sectorData.required_spacing || 15;
                const SECTOR_RADIUS_NM = Math.max(30, spacing * 2.5);

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

                // Build sector polygon: vertex + arc points
                const buildSectorPolygon = (sector, radius) => {
                    const [originLon, originLat] = sectorData.measurement_point;
                    const coords = [[originLon, originLat]]; // Start at vertex

                    // Generate arc points from start to end bearing
                    let startBearing = sector.start_bearing;
                    let endBearing = sector.end_bearing;

                    // Handle wrap-around
                    if (endBearing < startBearing) endBearing += 360;

                    const arcPoints = Math.max(10, Math.ceil(sector.width_deg / 3)); // ~3 degrees per point
                    for (let i = 0; i <= arcPoints; i++) {
                        const bearing = startBearing + (endBearing - startBearing) * i / arcPoints;
                        coords.push(pointAtBearing(originLon, originLat, bearing % 360, radius));
                    }

                    coords.push([originLon, originLat]); // Close polygon back to vertex
                    return coords;
                };

                // 90% sector (larger, more transparent)
                sectorFeatures.push({
                    type: 'Feature',
                    properties: { pct: 90 },
                    geometry: { type: 'Polygon', coordinates: [buildSectorPolygon(sectorData.sector_90, SECTOR_RADIUS_NM)] }
                });

                // 75% sector (smaller, more visible)
                sectorFeatures.push({
                    type: 'Feature',
                    properties: { pct: 75 },
                    geometry: { type: 'Polygon', coordinates: [buildSectorPolygon(sectorData.sector_75, SECTOR_RADIUS_NM * 0.8)] }
                });

                map.addSource('traffic-sectors', {
                    type: 'geojson',
                    data: { type: 'FeatureCollection', features: sectorFeatures }
                });

                // Insert below flight tracks if they exist
                const beforeLayer = map.getLayer('flight-tracks-solid-glow') ? 'flight-tracks-solid-glow' : undefined;

                // Sector fills
                map.addLayer({
                    id: 'traffic-sectors-fill',
                    type: 'fill',
                    source: 'traffic-sectors',
                    paint: {
                        'fill-color': ['case', ['==', ['get', 'pct'], 75], '#ffd43b', '#ff922b'],
                        'fill-opacity': ['case', ['==', ['get', 'pct'], 75], 0.25, 0.15]
                    }
                }, beforeLayer);

                // Sector outlines
                map.addLayer({
                    id: 'traffic-sectors-outline',
                    type: 'line',
                    source: 'traffic-sectors',
                    paint: {
                        'line-color': ['case', ['==', ['get', 'pct'], 75], '#ffd43b', '#ff922b'],
                        'line-width': ['case', ['==', ['get', 'pct'], 75], 2, 1],
                        'line-opacity': 0.8
                    }
                }, beforeLayer);

                // Add spacing arcs within the 90% sector
                if (sectorData.required_spacing && sectorData.required_spacing > 0 && sectorData.unit === 'nm') {
                    const arcFeatures = [];
                    const [originLon, originLat] = sectorData.measurement_point;
                    const sector = sectorData.sector_90;

                    // Build arc at given radius
                    const buildArc = (radius) => {
                        const coords = [];
                        let startBearing = sector.start_bearing;
                        let endBearing = sector.end_bearing;
                        if (endBearing < startBearing) endBearing += 360;

                        const arcPoints = Math.max(10, Math.ceil(sector.width_deg / 3));
                        for (let i = 0; i <= arcPoints; i++) {
                            const bearing = startBearing + (endBearing - startBearing) * i / arcPoints;
                            coords.push(pointAtBearing(originLon, originLat, bearing % 360, radius));
                        }
                        return coords;
                    };

                    // Generate arcs at spacing intervals (up to sector radius)
                    for (let dist = spacing; dist <= SECTOR_RADIUS_NM; dist += spacing) {
                        arcFeatures.push({
                            type: 'Feature',
                            properties: { distance: dist },
                            geometry: { type: 'LineString', coordinates: buildArc(dist) }
                        });
                    }

                    if (arcFeatures.length > 0) {
                        map.addSource('spacing-arcs', {
                            type: 'geojson',
                            data: { type: 'FeatureCollection', features: arcFeatures }
                        });

                        map.addLayer({
                            id: 'spacing-arcs',
                            type: 'line',
                            source: 'spacing-arcs',
                            paint: {
                                'line-color': '#ffffff',
                                'line-width': 1,
                                'line-opacity': 0.5,
                                'line-dasharray': [2, 2]
                            }
                        }, beforeLayer);

                        console.log(`Added ${arcFeatures.length} spacing arcs at ${spacing}nm intervals`);
                    }
                }

                console.log(`Added traffic sectors: 75% (${sectorData.sector_75.width_deg}°), 90% (${sectorData.sector_90.width_deg}°)`);
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
        if (!coords || coords.length < 2) return coords;
        if (coords.length === 2) return coords;

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
            if (!code) return null;
            // ARTCC codes start with Z (ZNY, ZDC, ZBW, etc.)
            if (/^Z[A-Z]{2}$/.test(code)) return 'ARTCC';
            // TRACON codes: letter + 2 digits (N90, A80, etc.) or 3 letters (PCT, SCT)
            if (/^[A-Z]\d{2}$/.test(code) || /^[A-Z]{3}$/.test(code)) return 'TRACON';
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
                    if (requestorType === 'ARTCC') artccCodes.push({ code: requestor, role: 'requestor' });
                    if (providerType === 'ARTCC' && provider !== requestor) artccCodes.push({ code: provider, role: 'provider' });

                    for (const { code, role } of artccCodes) {
                        // ARTCC GeoJSON uses ICAO codes (KZNY instead of ZNY)
                        const icaoCode = 'K' + code;
                        const feature = artccData.features.find(f =>
                            f.properties.ICAOCODE === icaoCode || f.properties.ICAOCODE === code
                        );
                        if (feature) {
                            facilities.push({
                                type: 'Feature',
                                properties: {
                                    code: code,
                                    name: feature.properties.FIRname || code,
                                    type: 'ARTCC',
                                    role: role
                                },
                                geometry: feature.geometry
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
                    if (requestorType === 'TRACON') traconCodes.push({ code: requestor, role: 'requestor' });
                    if (providerType === 'TRACON' && provider !== requestor) traconCodes.push({ code: provider, role: 'provider' });

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
                                    label_lon: features[0].properties.label_lon
                                },
                                geometry: {
                                    type: 'MultiPolygon',
                                    coordinates: allCoords
                                }
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
        if (!artccCodes || artccCodes.length === 0) return sectors;

        // Normalize codes to lowercase for matching
        const normalizedCodes = artccCodes.map(c => c.toLowerCase());
        const providerLower = providerCode ? providerCode.toLowerCase() : null;

        try {
            // Load both high and low altitude sector files
            const [highResponse, lowResponse] = await Promise.all([
                fetch('assets/geojson/high.json').catch(() => null),
                fetch('assets/geojson/low.json').catch(() => null)
            ]);

            const processFile = async (response, altitudeType) => {
                if (!response || !response.ok) return;
                const data = await response.json();

                for (const feature of data.features) {
                    const artcc = feature.properties.artcc;
                    if (!artcc || !normalizedCodes.includes(artcc.toLowerCase())) continue;

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
                            role: isProvider ? 'provider' : 'other'
                        },
                        geometry: feature.geometry
                    });
                }
            };

            await Promise.all([
                processFile(highResponse, 'high'),
                processFile(lowResponse, 'low')
            ]);

            console.log(`Loaded ${sectors.length} sectors for ARTCCs: ${artccCodes.join(', ')}`);
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
            if (f.geometry?.coordinates) expandBounds(f.geometry.coordinates);
        });

        // Include fixes
        (mapData.fixes || []).forEach(f => {
            if (f.geometry?.coordinates) expandBounds(f.geometry.coordinates);
        });

        // Include airports
        (mapData.airports || []).forEach(f => {
            if (f.geometry?.coordinates) expandBounds(f.geometry.coordinates);
        });

        return hasData ? [minLon, minLat, maxLon, maxLat] : null;
    },

    showMapError: function(mapId, message) {
        const container = document.getElementById(`${mapId}_container`);
        if (container) {
            container.innerHTML = `<div class="text-center text-danger py-4"><i class="fas fa-exclamation-triangle mr-2"></i>${message}</div>`;
        }
    },

    cleanupMaps: function() {
        Object.values(this.activeMaps).forEach(map => { if (map) map.remove(); });
        this.activeMaps = {};
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
    }
};

// Initialize when document is ready
$(document).ready(function() {
    TMICompliance.init();
});
