/**
 * TMI Compliance Analysis Module
 * Handles loading and displaying TMI compliance results in PERTI review
 */

const TMICompliance = {
    planId: null,
    results: null,

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

        // Show progress modal
        Swal.fire({
            title: 'Analyzing TMI Compliance',
            html: `
                <div class="text-center">
                    <div class="spinner-border text-primary mb-3" role="status">
                        <span class="sr-only">Loading...</span>
                    </div>
                    <p id="analysis_status">Connecting to analysis service...</p>
                    <div class="progress mt-3" style="height: 20px;">
                        <div id="analysis_progress" class="progress-bar progress-bar-striped progress-bar-animated"
                             role="progressbar" style="width: 10%"></div>
                    </div>
                    <p class="small text-muted mt-3">This may take 30-60 seconds</p>
                </div>
            `,
            allowOutsideClick: false,
            showConfirmButton: false
        });

        // Simulate progress updates
        let progress = 10;
        const progressInterval = setInterval(() => {
            progress = Math.min(progress + 5, 90);
            $('#analysis_progress').css('width', progress + '%');

            if (progress >= 20 && progress < 40) {
                $('#analysis_status').text('Loading configuration...');
            } else if (progress >= 40 && progress < 60) {
                $('#analysis_status').text('Querying flight data...');
            } else if (progress >= 60 && progress < 80) {
                $('#analysis_status').text('Computing MIT compliance...');
            } else if (progress >= 80) {
                $('#analysis_status').text('Generating report...');
            }
        }, 2000);

        // Call API with run=true
        $.ajax({
            url: `api/analysis/tmi_compliance.php?p_id=${this.planId}&run=true`,
            method: 'GET',
            dataType: 'json',
            timeout: 180000, // 3 minute timeout
            success: (response) => {
                clearInterval(progressInterval);
                this.analysisInProgress = false;

                if (response.success && response.data) {
                    this.results = response.data;
                    Swal.fire({
                        icon: 'success',
                        title: 'Analysis Complete',
                        text: response.message || 'TMI compliance analysis completed successfully.',
                        timer: 2000,
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

                let errorMsg = error;
                if (status === 'timeout') {
                    errorMsg = 'Analysis timed out. The event may have too many flights. Try running the Python script locally.';
                } else if (xhr.responseJSON?.error) {
                    errorMsg = xhr.responseJSON.error;
                }

                Swal.fire({
                    icon: 'error',
                    title: 'Analysis Failed',
                    html: `<p>${errorMsg}</p>
                           <p class="small text-muted">If the Azure Function is not configured, you can still run analysis locally:<br>
                           <code>python C:\\temp\\tmi_compliance_analyzer.py --plan ${this.planId}</code></p>`
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
        const gsCompliance = summary.gs?.compliance_pct || 100;
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
                        <div class="summary-stat-value ${this.getComplianceClass(gsCompliance)}">${gsCompliance.toFixed(1)}%</div>
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
                    <span class="text-muted">Max Shortfall:</span> ${summary.mit.max_shortfall_pct || 0}%
                </div>
                ` : ''}
            </div>
        `;

        // MIT Results
        if (this.results.mit_results && this.results.mit_results.length > 0) {
            html += '<h6 class="text-primary mb-3"><i class="fas fa-ruler-horizontal"></i> Miles-In-Trail (MIT)</h6>';

            for (const r of this.results.mit_results) {
                html += this.renderMitCard(r);
            }
        }

        // Ground Stop Results
        if (this.results.gs_results && this.results.gs_results.length > 0) {
            html += '<h6 class="text-danger mb-3 mt-4"><i class="fas fa-ban"></i> Ground Stops</h6>';

            for (const r of this.results.gs_results) {
                html += this.renderGsCard(r);
            }
        }

        // APREQ Results
        if (this.results.apreq_results && this.results.apreq_results.length > 0) {
            html += '<h6 class="text-secondary mb-3 mt-4"><i class="fas fa-phone"></i> APREQ/CFR (Tracking Only)</h6>';

            for (const r of this.results.apreq_results) {
                html += `
                    <div class="tmi-card apreq-card">
                        <div class="tmi-header">
                            <div>
                                <span class="tmi-fix-name">APREQ/CFR: ${r.fix || 'ALL'}</span>
                                <span class="text-muted ml-2">
                                    ${(r.destinations || []).join(', ')} | ${r.tmi_start || ''} - ${r.tmi_end || ''}
                                </span>
                            </div>
                            <span class="badge badge-secondary">TRACKING ONLY</span>
                        </div>
                        <div class="alert alert-warning mb-0 small">
                            <i class="fas fa-info-circle"></i>
                            ${r.note || 'APREQ/CFR requires human coordination - no compliance assessment'}
                        </div>
                        <div class="mt-2">
                            <strong>${r.total_flights || 0}</strong> flights subject to APREQ/CFR
                        </div>
                    </div>
                `;
            }
        }

        if (!html) {
            this.showNoData();
            return;
        }

        $('#tmi_results_container').html(html);
    },

    renderMitCard: function(r) {
        const compPct = r.compliance_pct || 0;
        const compClass = this.getComplianceClass(compPct);
        const detailId = `mit_detail_${++this.detailIdCounter}`;
        const violationId = `mit_violations_${this.detailIdCounter}`;
        const allPairs = r.all_pairs || [];
        const violations = allPairs.filter(p => p.spacing_category === 'UNDER');
        const unitLabel = r.unit === 'min' ? 'min' : 'nm';

        let html = `
            <div class="tmi-card mit-card">
                <div class="tmi-header">
                    <div>
                        <span class="tmi-fix-name">${r.fix || 'Unknown'}</span>
                        <span class="text-muted ml-2">${r.required || 0}${unitLabel} ${r.unit === 'min' ? 'MINIT' : 'MIT'} | ${r.tmi_start || ''} - ${r.tmi_end || ''}</span>
                        ${r.cancelled ? '<span class="badge badge-warning ml-2">CANCELLED</span>' : ''}
                    </div>
                    <div class="compliance-badge ${compClass}">${compPct.toFixed(1)}%</div>
                </div>
                <div class="tmi-stats">
                    <div class="tmi-stat">
                        <div class="tmi-stat-value">${r.crossings || 0}</div>
                        <div class="tmi-stat-label">Crossings</div>
                    </div>
                    <div class="tmi-stat">
                        <div class="tmi-stat-value">${r.pairs || 0}</div>
                        <div class="tmi-stat-label">Pairs Analyzed</div>
                    </div>
                    <div class="tmi-stat">
                        <div class="tmi-stat-value">${r.spacing_stats?.avg?.toFixed(1) || 0}</div>
                        <div class="tmi-stat-label">Avg Spacing</div>
                    </div>
                    <div class="tmi-stat">
                        <div class="tmi-stat-value">${r.spacing_stats?.min?.toFixed(1) || 0}</div>
                        <div class="tmi-stat-label">Min Spacing</div>
                    </div>
                </div>
                ${r.distribution ? this.renderDistribution(r.distribution) : ''}
        `;

        // Violations summary with expand button
        if (violations.length > 0) {
            html += `
                <div class="mt-2 small text-danger d-flex justify-content-between align-items-center">
                    <span>
                        <i class="fas fa-exclamation-triangle"></i>
                        ${violations.length} violations | Max shortfall: ${r.violations?.max_shortfall_pct || 0}%
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
                                    <th>Time Gap</th>
                                    <th>Spacing</th>
                                    <th>Required</th>
                                    <th>Shortfall</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${violations.map(v => `
                                    <tr class="table-danger">
                                        <td><code>${v.prev_callsign}</code> @ ${v.prev_time}</td>
                                        <td><code>${v.curr_callsign}</code> @ ${v.curr_time}</td>
                                        <td>${v.time_min} min</td>
                                        <td><strong>${v.spacing}${unitLabel}</strong></td>
                                        <td>${v.required}${unitLabel}</td>
                                        <td class="text-danger"><strong>-${v.shortfall_pct}%</strong></td>
                                    </tr>
                                `).join('')}
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
                    <span class="small text-muted">${allPairs.length} consecutive pairs analyzed</span>
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
                                    <th>Time</th>
                                    <th>Spacing</th>
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
                                    return `
                                        <tr class="${rowClass}">
                                            <td><code>${p.prev_callsign}</code><br><small class="text-muted">${p.prev_time}</small></td>
                                            <td><code>${p.curr_callsign}</code><br><small class="text-muted">${p.curr_time}</small></td>
                                            <td>${p.time_min}m</td>
                                            <td><strong>${p.spacing}${unitLabel}</strong></td>
                                            <td class="${p.margin_pct < 0 ? 'text-danger' : 'text-success'}">${p.margin_pct > 0 ? '+' : ''}${p.margin_pct}%</td>
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

        html += '</div>';
        return html;
    },

    renderGsCard: function(r) {
        const compPct = r.compliance_pct || 0;
        const compClass = this.getComplianceClass(compPct);
        const detailId = `gs_detail_${++this.detailIdCounter}`;

        const exemptFlights = r.exempt_flights || [];
        const compliantFlights = r.compliant_flights || [];
        const nonCompliantFlights = r.non_compliant_flights || [];
        const exemptCount = r.exempt_count || exemptFlights.length;
        const compliantCount = r.compliant_count || compliantFlights.length;
        const nonCompliantCount = r.non_compliant_count || nonCompliantFlights.length;

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
