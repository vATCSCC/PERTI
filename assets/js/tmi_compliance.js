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

    runAnalysis: function() {
        // Placeholder for triggering analysis
        Swal.fire({
            icon: 'info',
            title: 'Analysis',
            text: 'Run the Python analyzer script and then click Load Results.',
            confirmButtonText: 'OK'
        });
    },

    renderResults: function() {
        if (!this.results) {
            this.showNoData();
            return;
        }

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
                    <span class="text-muted">Avg Shortfall:</span> ${summary.mit.avg_shortfall_pct || 0}% |
                    <span class="text-muted">Max Shortfall:</span> ${summary.mit.max_shortfall_pct || 0}%
                </div>
                ` : ''}
            </div>
        `;

        // MIT Results
        if (this.results.mit_results && this.results.mit_results.length > 0) {
            html += '<h6 class="text-primary mb-3"><i class="fas fa-ruler-horizontal"></i> Miles-In-Trail (MIT)</h6>';

            for (const r of this.results.mit_results) {
                const compPct = r.compliance_pct || 0;
                const compClass = this.getComplianceClass(compPct);

                html += `
                    <div class="tmi-card mit-card">
                        <div class="tmi-header">
                            <div>
                                <span class="tmi-fix-name">${r.fix || 'Unknown'}</span>
                                <span class="text-muted ml-2">${r.required || 0}nm MIT | ${r.tmi_start || ''} - ${r.tmi_end || ''}</span>
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
                                <div class="tmi-stat-label">Avg Spacing (nm)</div>
                            </div>
                            <div class="tmi-stat">
                                <div class="tmi-stat-value">${r.spacing_stats?.min?.toFixed(1) || 0}</div>
                                <div class="tmi-stat-label">Min Spacing (nm)</div>
                            </div>
                        </div>
                        ${r.distribution ? this.renderDistribution(r.distribution) : ''}
                        ${r.violations?.total > 0 ? `
                        <div class="mt-2 small text-danger">
                            <i class="fas fa-exclamation-triangle"></i>
                            ${r.violations.total} violations | Avg shortfall: ${r.violations.avg_shortfall_pct || 0}% | Max: ${r.violations.max_shortfall_pct || 0}%
                        </div>
                        ` : ''}
                    </div>
                `;
            }
        }

        // Ground Stop Results
        if (this.results.gs_results && this.results.gs_results.length > 0) {
            html += '<h6 class="text-danger mb-3 mt-4"><i class="fas fa-ban"></i> Ground Stops</h6>';

            for (const r of this.results.gs_results) {
                const compPct = r.compliance_pct || 0;
                const compClass = this.getComplianceClass(compPct);

                html += `
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
                                <div class="tmi-stat-value">${r.exempt || 0}</div>
                                <div class="tmi-stat-label">Exempt</div>
                            </div>
                            <div class="tmi-stat">
                                <div class="tmi-stat-value text-success">${r.compliant || 0}</div>
                                <div class="tmi-stat-label">Compliant</div>
                            </div>
                            <div class="tmi-stat">
                                <div class="tmi-stat-value text-danger">${r.non_compliant || 0}</div>
                                <div class="tmi-stat-label">Violations</div>
                            </div>
                        </div>
                    </div>
                `;
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
