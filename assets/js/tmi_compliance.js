/**
 * TMI Compliance Analysis Module
 * Handles loading and displaying TMI compliance results in PERTI review
 */

const TMICompliance = {
    planId: null,
    results: null,

    // View mode for exempt flights: 'scale' (to-scale with dashed) or 'collapsed' (discontinuity)
    exemptViewMode: 'scale',

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
                    <span class="text-muted">Max Difference:</span> ${summary.mit.max_shortfall_pct || 0}%
                </div>
                ` : ''}
            </div>
        `;

        // Event Statistics Section
        html += this.renderEventStatistics();

        // MIT Results - handle both array and object formats
        const mitResults = this.results.mit_results || {};
        const mitResultsArray = Array.isArray(mitResults) ? mitResults : Object.values(mitResults);
        if (mitResultsArray.length > 0) {
            html += '<h6 class="text-primary mb-3"><i class="fas fa-ruler-horizontal"></i> Miles-In-Trail (MIT/MINIT)</h6>';

            for (const r of mitResultsArray) {
                // Skip entries with no data
                if (r.pairs === 0 && r.message) continue;
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
        const apreqResults = this.results.apreq_results || {};
        const apreqResultsArray = Array.isArray(apreqResults) ? apreqResults : Object.values(apreqResults);
        if (apreqResultsArray.length > 0) {
            html += '<h6 class="text-secondary mb-3 mt-4"><i class="fas fa-phone"></i> APREQ/CFR (Tracking Only)</h6>';

            for (const r of apreqResultsArray) {
                html += this.renderApreqCard(r);
            }
        }

        if (!html) {
            this.showNoData();
            return;
        }

        $('#tmi_results_container').html(html);
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

    renderMitCard: function(r) {
        const compPct = r.compliance_pct || 0;
        const compClass = this.getComplianceClass(compPct);
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
                    <div class="compliance-badge ${compClass}">${compPct.toFixed(1)}%</div>
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
