/**
 * GDP Module - Ground Delay Program functionality for TMI Coordination
 *
 * Handles:
 * - GDP Setup form management
 * - Preview/Simulate/Apply workflow
 * - D3.js demand/capacity visualization
 * - Slot allocation display
 *
 * Follows the same patterns as the GS module in tmi.js
 */

const GDP = (function() {
    'use strict';

    // =========================================================================
    // Constants & State
    // =========================================================================

    const API = {
        preview: 'api/tmi/gdp_preview.php',
        simulate: 'api/tmi/gdp_simulate.php',
        apply: 'api/tmi/gdp_apply.php',
        purgeLocal: 'api/tmi/gdp_purge_local.php',
        purge: 'api/tmi/gdp_purge.php',
    };

    // State tracking
    const state = {
        status: 'DRAFT',        // DRAFT, PREVIEWED, SIMULATED, ACTIVE
        programId: null,
        previewFlights: [],
        simulatedFlights: [],
        slots: [],
        summary: null,
        rateMode: 'simple',     // simple or detailed
        currentView: 'chart',    // chart, table, slots
    };

    // D3 chart reference
    const d3Chart = null;

    // =========================================================================
    // Helpers
    // =========================================================================

    function apiPostJson(url, payload) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        }).then(r => r.json());
    }

    function formatUtcTime(isoStr) {
        if (!isoStr) {return '--';}
        try {
            const d = new Date(isoStr);
            return d.toISOString().slice(11, 16) + 'Z';
        } catch (e) {
            return isoStr;
        }
    }

    function formatUtcDateTime(isoStr) {
        if (!isoStr) {return '--';}
        try {
            const d = new Date(isoStr);
            return d.toISOString().slice(0, 16).replace('T', ' ') + 'Z';
        } catch (e) {
            return isoStr;
        }
    }

    function formatDelay(minutes) {
        if (minutes === null || minutes === undefined) {return '--';}
        const m = parseInt(minutes, 10);
        if (isNaN(m)) {return '--';}
        if (m <= 0) {return '0';}
        return '+' + m + 'm';
    }

    function getDelayClass(minutes) {
        const m = parseInt(minutes, 10);
        if (isNaN(m) || m <= 0) {return '';}
        if (m < 15) {return 'text-success';}
        if (m < 30) {return 'text-warning';}
        if (m < 60) {return 'text-orange';}
        return 'text-danger';
    }

    function updateStatusBadge(status) {
        const badge = document.getElementById('gdp_status_badge');
        if (!badge) {return;}

        const statusMap = {
            'DRAFT': { text: 'Draft (local)', class: 'badge-secondary' },
            'PREVIEWED': { text: 'Previewed', class: 'badge-info' },
            'SIMULATED': { text: 'Simulated', class: 'badge-warning' },
            'ACTIVE': { text: 'Active', class: 'badge-success' },
            'PURGED': { text: 'Purged', class: 'badge-danger' },
        };

        const info = statusMap[status] || statusMap['DRAFT'];
        badge.textContent = info.text;
        badge.className = 'badge tmi-badge-status ' + info.class;
        state.status = status;
    }

    function updateWorkflowButtons() {
        const previewBtn = document.getElementById('gdp_model_btn');
        const modelBtn = document.getElementById('gdp_model_btn');
        const submitTmiBtn = document.getElementById('gdp_submit_tmi_btn');
        const purgeLocalBtn = document.getElementById('gdp_purge_local_btn');
        const purgeBtn = document.getElementById('gdp_purge_btn');

        // Enable based on current state
        // Submit to TMI is enabled only after simulation (model) completes
        if (submitTmiBtn) {
            const canSubmit = state.status === 'SIMULATED' || state.status === 'MODELED';
            submitTmiBtn.disabled = !canSubmit;
            if (canSubmit) {
                submitTmiBtn.title = 'Submit GDP to TMI Publishing for coordination';
            } else {
                submitTmiBtn.title = 'Run "Model" first to enable';
            }
        }
        if (purgeLocalBtn) {purgeLocalBtn.disabled = (state.status === 'DRAFT');}
        if (purgeBtn) {purgeBtn.disabled = (state.status !== 'ACTIVE');}
    }

    // =========================================================================
    // Payload Collection
    // =========================================================================

    function collectGdpPayload() {
        // Determine scope mode
        const scopeMode = document.getElementById('gdp_scope_mode_distance')?.classList.contains('active')
            ? 'distance' : 'tier';

        const payload = {
            gdp_airport: (document.getElementById('gdp_ctl_element')?.value || '').toUpperCase().trim(),
            gdp_start: document.getElementById('gdp_start_ddhhmm')?.value || document.getElementById('gdp_start')?.value || '',
            gdp_end: document.getElementById('gdp_end_ddhhmm')?.value || document.getElementById('gdp_end')?.value || '',
            program_rate: parseInt(document.getElementById('gdp_program_rate')?.value || '40', 10),
            reserve_rate: parseInt(document.getElementById('gdp_reserve_rate')?.value || '0', 10),
            delay_limit: parseInt(document.getElementById('gdp_delay_limit')?.value || '180', 10),
            gdp_origin_airports: document.getElementById('gdp_origin_airports')?.value || '',
            gdp_flt_incl_carrier: document.getElementById('gdp_flt_incl_carrier')?.value || '',
            gdp_flt_incl_type: document.getElementById('gdp_flt_incl_type')?.value || 'ALL',
            adv_number: document.getElementById('gdp_adv_number')?.value || '',
            impacting_condition: document.getElementById('gdp_impacting_condition')?.value || '',
            prob_extension: document.getElementById('gdp_prob_ext')?.value || '',
            exemptions: collectExemptions(),
        };

        // Scope based on mode
        if (scopeMode === 'distance') {
            payload.distance_nm = parseInt(document.getElementById('gdp_distance_nm')?.value || '500', 10);
            payload.gdp_origin_centers = '';
            payload.gdp_dep_facilities = '';
        } else {
            // Use the computed values from scope selector
            payload.gdp_origin_centers = document.getElementById('gdp_origin_centers')?.value || '';
            payload.gdp_dep_facilities = document.getElementById('gdp_dep_facilities')?.value || '';
            payload.distance_nm = 0;
        }

        // Add hourly rates if in detailed mode
        if (state.rateMode === 'detailed') {
            payload.program_rates_hourly = collectHourlyRates();
            payload.reserve_rates_hourly = collectHourlyReserves();
        }

        // Add program ID if available
        if (state.programId) {
            payload.program_id = state.programId;
        }

        return payload;
    }

    function collectSelectedCenters() {
        // This now uses the hidden field populated by recomputeGdpScope
        return document.getElementById('gdp_origin_centers')?.value || '';
    }

    function collectExemptions() {
        return {
            orig_airports: document.getElementById('gdp_exempt_orig_airports')?.value || '',
            orig_tracons: document.getElementById('gdp_exempt_orig_tracons')?.value || '',
            orig_artccs: document.getElementById('gdp_exempt_orig_artccs')?.value || '',
            carriers: document.getElementById('gdp_exempt_carriers')?.value || '',
            callsigns: document.getElementById('gdp_exempt_callsigns')?.value || '',
            airborne: document.getElementById('gdp_exempt_airborne')?.checked || false,
        };
    }

    function collectHourlyRates() {
        // TODO: Implement when hourly mode is built out
        return null;
    }

    function collectHourlyReserves() {
        // TODO: Implement when hourly mode is built out
        return null;
    }

    // =========================================================================
    // Reload Handler - Refresh ADL data for current airport
    // =========================================================================

    async function handleReload() {
        const airport = (document.getElementById('gdp_ctl_element')?.value || '').toUpperCase().trim();

        if (!airport) {
            alert('Please enter a CTL Element (airport code) first.');
            return;
        }

        const btn = document.getElementById('gdp_reload_btn');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Loading...';
        }

        try {
            // Clear current state
            state.previewFlights = [];
            state.simulatedFlights = [];
            state.slots = [];
            state.summary = null;
            state.status = 'DRAFT';

            // Update UI
            updateStatusBadge('DRAFT');
            updateWorkflowButtons();

            // Clear tables and charts
            const tbodies = ['gdp_flight_list_tbody', 'gdp_slots_list_tbody', 'gdp_demand_by_center'];
            tbodies.forEach(id => {
                const tbody = document.getElementById(id);
                if (tbody) {tbody.innerHTML = '<tr><td colspan="10" class="text-center text-secondary">Click Model to load data</td></tr>';}
            });

            // Update airport label
            const labelEl = document.getElementById('gdp_airport_label');
            if (labelEl) {labelEl.textContent = airport;}

            console.log('GDP: Reloaded state for', airport);

        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-sync-alt mr-1"></i> Reload';
            }
        }
    }

    // =========================================================================
    // Fill Rates Handler
    // =========================================================================

    function handleFillRates() {
        const fillRow = document.getElementById('gdp_fill_row')?.value || 'PR';
        const fillValue = parseInt(document.getElementById('gdp_fill_value')?.value || '40', 10);

        // Get all rate inputs in the rate table
        if (fillRow === 'PR') {
            const rateInputs = document.querySelectorAll('#gdp_rate_pr input[type="number"]');
            rateInputs.forEach(input => input.value = fillValue);
        } else if (fillRow === 'Reserve') {
            const reserveInputs = document.querySelectorAll('#gdp_rate_reserve input[type="number"]');
            reserveInputs.forEach(input => input.value = fillValue);
        }

        console.log('GDP: Filled', fillRow, 'row with value', fillValue);
    }

    // =========================================================================
    // Show Demand Handler
    // =========================================================================

    function handleShowDemand() {
        const airport = (document.getElementById('gdp_ctl_element')?.value || '').toUpperCase().trim();
        if (!airport) {
            alert('Please enter a CTL Element (airport code) first.');
            return;
        }

        // Trigger a preview to load demand data
        handlePreview();
    }

    // =========================================================================
    // Modal Handlers
    // =========================================================================

    function openFlightListModal() {
        const modal = document.getElementById('gdp_flight_list_modal');
        if (modal) {
            // Update airport label
            const airport = document.getElementById('gdp_ctl_element')?.value || '---';
            document.getElementById('gdp_flight_list_airport').textContent = airport.toUpperCase();

            // Populate flight list
            renderFlightListModal();

            // Show modal using Bootstrap
            $(modal).modal('show');
        }
    }

    function openSlotsListModal() {
        const modal = document.getElementById('gdp_slots_list_modal');
        if (modal) {
            // Update airport label
            const airport = document.getElementById('gdp_ctl_element')?.value || '---';
            document.getElementById('gdp_slots_list_airport').textContent = airport.toUpperCase();

            // Populate slots list
            renderSlotsListModal();

            // Show modal using Bootstrap
            $(modal).modal('show');
        }
    }

    function renderFlightListModal() {
        const tbody = document.getElementById('gdp_flight_list_tbody');
        if (!tbody) {return;}

        const flights = state.simulatedFlights.length > 0 ? state.simulatedFlights : state.previewFlights;

        if (!flights || flights.length === 0) {
            tbody.innerHTML = '<tr><td colspan="10" class="text-center text-secondary">No flights loaded. Run Model first.</td></tr>';
            return;
        }

        let html = '';
        let delayed = 0, exempt = 0;

        flights.forEach(f => {
            const delay = parseInt(f.program_delay_min, 10) || 0;
            const isExempt = f.ctl_exempt === 1 || f.ctl_exempt === '1';
            const statusClass = isExempt ? 'text-success' : (delay > 0 ? 'text-warning' : '');
            const statusText = isExempt ? 'Exempt' : (delay > 0 ? 'Delayed' : 'Uncontrolled');

            if (delay > 0) {delayed++;}
            if (isExempt) {exempt++;}

            html += `<tr class="${statusClass}">
                <td>${f.callsign || '--'}</td>
                <td>${f.origin || '--'}</td>
                <td>${f.destination || '--'}</td>
                <td>${f.origin_artcc || '--'}</td>
                <td>${f.aircraft_type || '--'}</td>
                <td>${formatUtcTime(f.eta_runway_utc)}</td>
                <td>${formatUtcTime(f.cta_utc)}</td>
                <td>${formatUtcTime(f.ctd_utc)}</td>
                <td class="${getDelayClass(delay)}">${formatDelay(delay)}</td>
                <td>${statusText}</td>
            </tr>`;
        });

        tbody.innerHTML = html;

        // Update counts
        document.getElementById('gdp_fl_count_all').textContent = flights.length;
        document.getElementById('gdp_fl_count_delayed').textContent = delayed;
        document.getElementById('gdp_fl_count_exempt').textContent = exempt;
        document.getElementById('gdp_fl_status').textContent = `Showing ${flights.length} flights`;
    }

    function renderSlotsListModal() {
        const tbody = document.getElementById('gdp_slots_list_tbody');
        if (!tbody) {return;}

        const slots = state.slots || [];

        if (slots.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-center text-secondary">No slots generated. Run Model first.</td></tr>';
            return;
        }

        let html = '';
        let assigned = 0, open = 0;

        slots.forEach(s => {
            const isAssigned = s.callsign && s.callsign !== '';
            if (isAssigned) {assigned++;} else {open++;}

            const statusText = isAssigned ? 'Assigned' : 'Open';
            const statusClass = isAssigned ? 'text-primary' : 'text-secondary';

            html += `<tr>
                <td>${formatUtcTime(s.slot_time_utc)}</td>
                <td>${s.slot_id || '--'}</td>
                <td class="${statusClass}">${s.callsign || '(open)'}</td>
                <td>${s.origin || '--'}</td>
                <td>${formatUtcTime(s.ctd_utc)}</td>
                <td class="${getDelayClass(s.delay_min)}">${formatDelay(s.delay_min)}</td>
                <td>${s.slot_type || 'PRG'}</td>
                <td class="${statusClass}">${statusText}</td>
            </tr>`;
        });

        tbody.innerHTML = html;

        // Update counts
        document.getElementById('gdp_sl_count_all').textContent = slots.length;
        document.getElementById('gdp_sl_count_assigned').textContent = assigned;
        document.getElementById('gdp_sl_count_open').textContent = open;
        document.getElementById('gdp_sl_status').textContent = `Showing ${slots.length} slots`;
    }

    // =========================================================================
    // Bar Graph View Toggle
    // =========================================================================

    function switchBarGraphView(view) {
        const etaBtn = document.getElementById('gdp_bar_eta_btn');
        const ctaBtn = document.getElementById('gdp_bar_cta_btn');

        if (view === 'eta') {
            if (etaBtn) {etaBtn.classList.add('active');}
            if (ctaBtn) {ctaBtn.classList.remove('active');}
        } else {
            if (etaBtn) {etaBtn.classList.remove('active');}
            if (ctaBtn) {ctaBtn.classList.add('active');}
        }

        // Re-render bar graph with the selected view
        renderBarGraph(view);
    }

    function renderBarGraph(view) {
        const flights = state.simulatedFlights.length > 0 ? state.simulatedFlights : state.previewFlights;
        if (!flights || flights.length === 0) {return;}

        // Group flights by hour based on view (ETA or CTA)
        const hourlyData = {};

        flights.forEach(f => {
            const timeField = view === 'cta' ? (f.cta_utc || f.eta_runway_utc) : f.eta_runway_utc;
            if (!timeField) {return;}

            const hour = new Date(timeField).getUTCHours();
            const hourKey = hour.toString().padStart(2, '0') + 'Z';

            if (!hourlyData[hourKey]) {
                hourlyData[hourKey] = { original: 0, modeled: 0 };
            }

            // Original count (based on original ETA)
            if (f.gdp_original_eta_utc || f.eta_runway_utc) {
                const origHour = new Date(f.gdp_original_eta_utc || f.eta_runway_utc).getUTCHours();
                const origKey = origHour.toString().padStart(2, '0') + 'Z';
                if (origKey === hourKey) {hourlyData[hourKey].original++;}
            }

            // Modeled count (based on current time field)
            hourlyData[hourKey].modeled++;
        });

        // Update label
        const label = document.getElementById('gdp_bargraph_label');
        if (label) {label.textContent = document.getElementById('gdp_ctl_element')?.value?.toUpperCase() || '---';}

        // Render using Chart.js
        renderBarGraphChart(hourlyData);
    }

    function renderBarGraphChart(hourlyData) {
        const canvas = document.getElementById('gdp_bargraph_canvas');
        if (!canvas) {return;}

        const ctx = canvas.getContext('2d');
        if (!ctx) {return;}

        // Destroy existing chart if any
        if (window.gdpBarChart) {
            window.gdpBarChart.destroy();
        }

        const hours = Object.keys(hourlyData).sort();
        const originalData = hours.map(h => hourlyData[h].original);
        const modeledData = hours.map(h => hourlyData[h].modeled);

        window.gdpBarChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: hours,
                datasets: [
                    {
                        label: 'Original',
                        data: originalData,
                        backgroundColor: 'rgba(0, 123, 255, 0.7)',
                        borderColor: 'rgba(0, 123, 255, 1)',
                        borderWidth: 1,
                    },
                    {
                        label: 'Modeled',
                        data: modeledData,
                        backgroundColor: 'rgba(255, 193, 7, 0.5)',
                        borderColor: 'rgba(255, 193, 7, 1)',
                        borderWidth: 1,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { beginAtZero: true },
                },
                plugins: {
                    legend: { position: 'top' },
                },
            },
        });
    }

    // =========================================================================
    // Preview Handler
    // =========================================================================

    async function handlePreview() {
        const payload = collectGdpPayload();

        if (!payload.gdp_airport) {
            alert('Please enter a CTL Element (airport code).');
            return;
        }

        console.log('GDP Preview payload:', payload);

        const btn = document.getElementById('gdp_model_btn');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Loading...';
        }

        try {
            const result = await apiPostJson(API.preview, payload);

            console.log('GDP Preview result:', result);

            if (result.status !== 'ok') {
                alert('Preview failed: ' + (result.message || 'Unknown error'));
                return;
            }

            state.previewFlights = result.flights || [];
            state.summary = result.summary || {};

            updateStatusBadge('PREVIEWED');
            updateWorkflowButtons();
            updateFlightCount(result.affected || 0, result.exempt || 0);
            renderSummaryTables(result.summary);
            renderFlightTable(state.previewFlights, false);
            renderDemandChart(result.summary);
            renderBarGraph('eta');  // Render bar graph with ETA view

            // Generate program ID
            if (payload.gdp_start) {
                const dateStr = payload.gdp_start.replace(/[-:T]/g, '').slice(0, 12);
                state.programId = 'GDP-' + payload.gdp_airport + '-' + dateStr;
                document.getElementById('gdp_program_id').value = state.programId;
            }

        } catch (err) {
            console.error('Preview error:', err);
            alert('Preview failed: ' + err.message);
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-calculator mr-1"></i> Model';
            }
        }
    }

    // =========================================================================
    // Model Handler (Combined Preview + Simulate)
    // =========================================================================

    async function handleModel() {
        const payload = collectGdpPayload();

        if (!payload.gdp_airport || !payload.gdp_start || !payload.gdp_end) {
            alert('Please enter CTL Element, Start, and End times.');
            return;
        }

        console.log('GDP Model payload:', payload);

        const btn = document.getElementById('gdp_model_btn');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Modeling...';
        }

        try {
            // Run simulation which includes preview + EDCT calculation
            const result = await apiPostJson(API.simulate, payload);

            console.log('GDP Model result:', result);

            if (result.status !== 'ok') {
                alert('Model failed: ' + (result.message || 'Unknown error'));
                return;
            }

            // Store simulation results
            state.simulatedFlights = result.flights || [];
            state.slots = result.slots || [];
            state.summary = result.summary || {};
            state.programId = result.program_id;

            if (result.program_id) {
                document.getElementById('gdp_program_id').value = state.programId;
            }

            updateStatusBadge('SIMULATED');
            updateWorkflowButtons();
            updateMetrics(result.summary);
            renderFlightTable(state.simulatedFlights, true);
            renderSlotTable(state.slots);
            renderDemandChartWithSlots(state.simulatedFlights, state.slots, payload);
            renderBarGraph('eta');

        } catch (err) {
            console.error('Model error:', err);
            alert('Model failed: ' + err.message);
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-calculator mr-1"></i> Model';
            }
        }
    }

    // =========================================================================
    // Simulate Handler (Legacy - kept for backwards compatibility)
    // =========================================================================

    async function handleSimulate() {
        const payload = collectGdpPayload();

        if (!payload.gdp_airport || !payload.gdp_start || !payload.gdp_end) {
            alert('Please enter CTL Element, Start, and End times.');
            return;
        }

        const btn = document.getElementById('gdp_run_proposed_btn');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Running...';
        }

        try {
            const result = await apiPostJson(API.simulate, payload);

            if (result.status !== 'ok') {
                alert('Simulation failed: ' + (result.message || 'Unknown error'));
                return;
            }

            state.simulatedFlights = result.flights || [];
            state.slots = result.slots || [];
            state.summary = result.summary || {};
            state.programId = result.program_id;

            document.getElementById('gdp_program_id').value = state.programId;

            updateStatusBadge('SIMULATED');
            updateWorkflowButtons();
            updateMetrics(result.summary);
            renderFlightTable(state.simulatedFlights, true);
            renderSlotTable(state.slots);
            renderDemandChartWithSlots(state.simulatedFlights, state.slots, payload);
            renderBarGraph('eta');  // Render bar graph with ETA view

        } catch (err) {
            console.error('Simulate error:', err);
            alert('Simulation failed: ' + err.message);
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-paper-plane mr-1"></i> Run Proposed';
            }
        }
    }

    // =========================================================================
    // Apply Handler
    // =========================================================================

    async function handleApply() {
        if (!confirm('Apply GDP to live ADL? This will assign EDCTs to affected flights.')) {
            return;
        }

        const payload = collectGdpPayload();

        const btn = document.getElementById('gdp_run_actual_btn');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Applying...';
        }

        try {
            const result = await apiPostJson(API.apply, payload);

            if (result.status !== 'ok') {
                alert('Apply failed: ' + (result.message || 'Unknown error'));
                return;
            }

            updateStatusBadge('ACTIVE');
            updateWorkflowButtons();
            updateMetrics(result.metrics);

            alert('GDP applied successfully. ' + result.applied_count + ' flights assigned EDCTs.');

        } catch (err) {
            console.error('Apply error:', err);
            alert('Apply failed: ' + err.message);
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-check-circle mr-1"></i> Run Actual';
            }
        }
    }

    // =========================================================================
    // Submit to TMI Publishing Handler
    // =========================================================================

    function handleSubmitToTmi() {
        // Require simulation to be run first
        if (state.status !== 'SIMULATED' && state.status !== 'MODELED') {
            if (window.Swal) {
                window.Swal.fire({
                    icon: 'warning',
                    title: 'Model Required',
                    text: 'You must run "Model" before submitting to TMI Publishing. This ensures EDCTs are calculated correctly.',
                    confirmButtonText: 'OK',
                });
            } else {
                alert('You must run "Model" before submitting to TMI Publishing.');
            }
            return;
        }

        const payload = collectGdpPayload();

        // Validate required fields
        if (!payload.gdp_airport || !payload.gdp_start || !payload.gdp_end) {
            alert('Please enter CTL Element, Start, and End times.');
            return;
        }

        // Build the TMI Publishing handoff data
        const tmiHandoff = {
            type: 'GDP',
            program_type: document.getElementById('gdp_program_type')?.value || 'GDP-UDP',
            program_id: state.programId || null,
            ctl_element: payload.gdp_airport,
            start_time: payload.gdp_start,
            end_time: payload.gdp_end,
            program_rate: payload.program_rate,
            reserve_rate: payload.reserve_rate,
            delay_limit: payload.delay_limit,
            distance_nm: payload.distance_nm || 0,
            origin_centers: payload.gdp_origin_centers || '',
            dep_facilities: payload.gdp_dep_facilities || '',
            origin_airports: payload.gdp_origin_airports || '',
            flt_incl_carrier: payload.gdp_flt_incl_carrier || '',
            flt_incl_type: payload.gdp_flt_incl_type || 'ALL',
            impacting_condition: payload.impacting_condition || '',
            prob_extension: payload.prob_extension || '',
            exemptions: payload.exemptions || {},

            // Include simulation results
            summary: state.summary || {},
            flights: state.simulatedFlights || [],
            slots: state.slots || [],

            // Metadata
            source: 'GDT',
            created_at: new Date().toISOString(),
        };

        // Store in sessionStorage for TMI Publishing to pick up
        try {
            sessionStorage.setItem('tmi_gdp_handoff', JSON.stringify(tmiHandoff));

            // Navigate to TMI Publishing page with GDP tab active
            window.location.href = 'tmi-publish.php?tab=gdp&source=gdt&program_id=' + (state.programId || '');

        } catch (err) {
            console.error('Failed to store TMI handoff data:', err);
            alert('Failed to prepare handoff data: ' + err.message);
        }
    }

    // =========================================================================
    // Purge Handlers
    // =========================================================================

    async function handlePurgeLocal() {
        if (!confirm('Clear GDP simulation? This does not affect live flights.')) {
            return;
        }

        try {
            const result = await apiPostJson(API.purgeLocal, { program_id: state.programId });

            if (result.status !== 'ok') {
                alert('Purge failed: ' + (result.message || 'Unknown error'));
                return;
            }

            state.simulatedFlights = [];
            state.slots = [];
            updateStatusBadge('DRAFT');
            updateWorkflowButtons();
            clearDisplay();

        } catch (err) {
            console.error('Purge local error:', err);
            alert('Purge failed: ' + err.message);
        }
    }

    async function handlePurge() {
        if (!confirm('PURGE active GDP? This will remove EDCTs from all affected flights.')) {
            return;
        }

        try {
            const payload = {
                program_id: state.programId,
                gdp_airport: document.getElementById('gdp_ctl_element')?.value || '',
            };

            const result = await apiPostJson(API.purge, payload);

            if (result.status !== 'ok') {
                alert('Purge failed: ' + (result.message || 'Unknown error'));
                return;
            }

            updateStatusBadge('PURGED');
            updateWorkflowButtons();
            clearDisplay();

            alert('GDP purged. ' + result.flights_cleared + ' flights cleared.');

        } catch (err) {
            console.error('Purge error:', err);
            alert('Purge failed: ' + err.message);
        }
    }

    // =========================================================================
    // Display Functions
    // =========================================================================

    function updateFlightCount(affected, exempt) {
        // Update the summary stats in the Model Summary card
        const totalEl = document.getElementById('gdp_sum_total');
        const delayedEl = document.getElementById('gdp_sum_delayed');
        const exemptEl = document.getElementById('gdp_sum_exempt');

        if (totalEl) {totalEl.textContent = affected + exempt;}
        if (delayedEl) {delayedEl.textContent = affected;}
        if (exemptEl) {exemptEl.textContent = exempt;}
    }

    function updateMetrics(summary) {
        if (!summary) {return;}

        const set = (id, val) => {
            const el = document.getElementById(id);
            if (el) {el.textContent = val;}
        };

        // Update summary card
        set('gdp_sum_total', summary.total_flights || summary.assigned_flights || '--');
        set('gdp_sum_delayed', summary.delayed_flights || '--');
        set('gdp_sum_exempt', summary.exempt_flights || '--');
        set('gdp_sum_utilization', summary.slot_utilization !== undefined ? summary.slot_utilization + '%' : '--%');

        // Update data graph stats
        set('gdp_stat_avg_delay', summary.avg_delay_min !== null ? summary.avg_delay_min + 'm' : '--');
        set('gdp_stat_max_delay', summary.max_delay_min !== null ? summary.max_delay_min + 'm' : '--');
        set('gdp_stat_total_delay', summary.total_delay_min !== undefined ? Math.round(summary.total_delay_min / 60) + 'h' : '--');
        set('gdp_stat_affected', summary.delayed_flights || summary.assigned_flights || '--');
    }

    function renderSummaryTables(summary) {
        if (!summary) {return;}

        // By Origin Center - render in the "Demand by Center" card
        renderDemandByCenter(summary.by_origin_center);
    }

    function renderDemandByCenter(data) {
        const tbody = document.getElementById('gdp_demand_by_center');
        if (!tbody) {return;}

        if (!data || Object.keys(data).length === 0) {
            tbody.innerHTML = '<tr><td colspan="3" class="text-center text-secondary">No data available</td></tr>';
            return;
        }

        let html = '';
        for (const [center, counts] of Object.entries(data)) {
            const nonExempt = counts.non_exempt || counts.total || counts;
            const exempt = counts.exempt || 0;
            html += `<tr>
                <td>${center}</td>
                <td class="text-right text-danger">${typeof nonExempt === 'object' ? nonExempt.count : nonExempt}</td>
                <td class="text-right text-success">${exempt}</td>
            </tr>`;
        }
        tbody.innerHTML = html;
    }

    function renderCountTable(tbodyId, data) {
        const tbody = document.getElementById(tbodyId);
        if (!tbody || !data) {return;}

        tbody.innerHTML = '';

        for (const [key, count] of Object.entries(data)) {
            const tr = document.createElement('tr');
            tr.innerHTML = '<td>' + key + '</td><td class="text-right">' + count + '</td>';
            tbody.appendChild(tr);
        }
    }

    function renderFlightTable(flights, hasSlots) {
        const tbody = document.getElementById('gdp_flight_table_body');
        if (!tbody) {return;}

        tbody.innerHTML = '';

        if (!flights || flights.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">No flights</td></tr>';
            return;
        }

        flights.forEach(f => {
            const tr = document.createElement('tr');
            const delay = f.program_delay_min || 0;
            const delayClass = getDelayClass(delay);

            tr.innerHTML = `
                <td><strong>${f.callsign || '--'}</strong></td>
                <td>${f.fp_dept_icao || '--'}</td>
                <td>${formatUtcTime(f.gdp_original_eta_utc || f.eta_runway_utc)}</td>
                <td>${formatUtcTime(f.cta_utc)}</td>
                <td>${formatUtcTime(f.ctd_utc)}</td>
                <td class="${delayClass}">${formatDelay(delay)}</td>
                <td>${f.gdp_slot_index || '--'}</td>
                <td><span class="badge badge-${f.ctl_type === 'GDP' ? 'success' : 'secondary'}">${f.ctl_type || 'PEND'}</span></td>
            `;
            tbody.appendChild(tr);
        });
    }

    function renderSlotTable(slots) {
        const tbody = document.getElementById('gdp_slot_table_body');
        if (!tbody) {return;}

        tbody.innerHTML = '';

        if (!slots || slots.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">No slots generated</td></tr>';
            return;
        }

        slots.forEach(s => {
            const tr = document.createElement('tr');
            const statusClass = s.slot_status === 'ASSIGNED' ? 'success' :
                s.slot_type === 'RESERVED' ? 'warning' : 'light';

            tr.innerHTML = `
                <td>${s.slot_index}</td>
                <td>${formatUtcTime(s.slot_time_utc)}</td>
                <td><span class="badge badge-${s.slot_type === 'RESERVED' ? 'warning' : 'secondary'}">${s.slot_type}</span></td>
                <td>${s.assigned_callsign || '-'}</td>
                <td>${s.assigned_origin || '-'}</td>
                <td><span class="badge badge-${statusClass}">${s.slot_status}</span></td>
            `;
            tbody.appendChild(tr);
        });
    }

    function clearDisplay() {
        document.getElementById('gdp_flight_table_body').innerHTML = '';
        document.getElementById('gdp_slot_table_body').innerHTML = '';
        document.getElementById('gdp_counts_origin_center').innerHTML = '';
        document.getElementById('gdp_counts_hour').innerHTML = '';
        document.getElementById('gdp_counts_carrier').innerHTML = '';
        document.getElementById('gdp_metric_total').textContent = '--';
        document.getElementById('gdp_metric_avg_delay').textContent = '--';
        document.getElementById('gdp_metric_max_delay').textContent = '--';
        document.getElementById('gdp_metric_utilization').textContent = '--';
        document.getElementById('gdp_flight_count').textContent = '0 flights';

        // Clear chart
        const chartContainer = document.getElementById('gdp_chart_container');
        if (chartContainer) {
            chartContainer.innerHTML = `
                <div class="d-flex justify-content-center align-items-center h-100 text-muted">
                    <div class="text-center">
                        <i class="fas fa-chart-bar fa-3x mb-2"></i>
                        <p class="mb-0">Run Preview to see demand/capacity chart</p>
                    </div>
                </div>
            `;
        }
    }

    // =========================================================================
    // D3.js Chart Rendering
    // =========================================================================

    function renderDemandChart(summary) {
        if (!summary || !summary.by_hour) {
            return;
        }

        const container = document.getElementById('gdp_chart_container');
        if (!container) {return;}

        container.innerHTML = '';

        const data = Object.entries(summary.by_hour).map(([hour, count]) => ({
            hour: hour,
            demand: count,
        }));

        if (data.length === 0) {
            container.innerHTML = '<div class="text-center text-muted p-4">No data to display</div>';
            return;
        }

        // Get container dimensions
        const width = container.clientWidth || 500;
        const height = container.clientHeight || 280;
        const margin = { top: 20, right: 30, bottom: 40, left: 50 };

        const svg = d3.select(container)
            .append('svg')
            .attr('width', width)
            .attr('height', height);

        const chartWidth = width - margin.left - margin.right;
        const chartHeight = height - margin.top - margin.bottom;

        const g = svg.append('g')
            .attr('transform', `translate(${margin.left},${margin.top})`);

        // Scales
        const x = d3.scaleBand()
            .domain(data.map(d => d.hour))
            .range([0, chartWidth])
            .padding(0.2);

        const y = d3.scaleLinear()
            .domain([0, d3.max(data, d => d.demand) * 1.1])
            .range([chartHeight, 0]);

        // Axes
        g.append('g')
            .attr('transform', `translate(0,${chartHeight})`)
            .call(d3.axisBottom(x))
            .selectAll('text')
            .style('font-size', '10px');

        g.append('g')
            .call(d3.axisLeft(y).ticks(5))
            .selectAll('text')
            .style('font-size', '10px');

        // Bars
        g.selectAll('.bar')
            .data(data)
            .enter()
            .append('rect')
            .attr('class', 'bar')
            .attr('x', d => x(d.hour))
            .attr('y', d => y(d.demand))
            .attr('width', x.bandwidth())
            .attr('height', d => chartHeight - y(d.demand))
            .attr('fill', '#17a2b8');

        // Y-axis label
        svg.append('text')
            .attr('transform', 'rotate(-90)')
            .attr('y', 10)
            .attr('x', -(height / 2))
            .attr('dy', '1em')
            .style('text-anchor', 'middle')
            .style('font-size', '11px')
            .text('Flights');
    }

    function renderDemandChartWithSlots(flights, slots, params) {
        const container = document.getElementById('gdp_chart_container');
        if (!container) {return;}

        container.innerHTML = '';

        // Group flights by hour
        const hourlyDemand = {};
        const hourlyCapacity = {};

        // Count demand (flights per hour based on original ETA)
        flights.forEach(f => {
            const eta = f.gdp_original_eta_utc || f.eta_runway_utc;
            if (eta) {
                const hour = new Date(eta).getUTCHours() + '00Z';
                hourlyDemand[hour] = (hourlyDemand[hour] || 0) + 1;
            }
        });

        // Count capacity (slots per hour)
        slots.forEach(s => {
            if (s.slot_time_utc) {
                const hour = new Date(s.slot_time_utc).getUTCHours() + '00Z';
                hourlyCapacity[hour] = (hourlyCapacity[hour] || 0) + 1;
            }
        });

        // Merge into data array
        const hours = [...new Set([...Object.keys(hourlyDemand), ...Object.keys(hourlyCapacity)])].sort();
        const data = hours.map(hour => ({
            hour: hour,
            demand: hourlyDemand[hour] || 0,
            capacity: hourlyCapacity[hour] || 0,
        }));

        if (data.length === 0) {
            container.innerHTML = '<div class="text-center text-muted p-4">No data to display</div>';
            return;
        }

        // Get container dimensions
        const width = container.clientWidth || 500;
        const height = container.clientHeight || 280;
        const margin = { top: 20, right: 30, bottom: 40, left: 50 };

        const svg = d3.select(container)
            .append('svg')
            .attr('width', width)
            .attr('height', height);

        const chartWidth = width - margin.left - margin.right;
        const chartHeight = height - margin.top - margin.bottom;

        const g = svg.append('g')
            .attr('transform', `translate(${margin.left},${margin.top})`);

        // Scales
        const x = d3.scaleBand()
            .domain(data.map(d => d.hour))
            .range([0, chartWidth])
            .padding(0.2);

        const maxVal = Math.max(
            d3.max(data, d => d.demand),
            d3.max(data, d => d.capacity),
        );

        const y = d3.scaleLinear()
            .domain([0, maxVal * 1.1])
            .range([chartHeight, 0]);

        // Axes
        g.append('g')
            .attr('transform', `translate(0,${chartHeight})`)
            .call(d3.axisBottom(x))
            .selectAll('text')
            .style('font-size', '10px');

        g.append('g')
            .call(d3.axisLeft(y).ticks(5))
            .selectAll('text')
            .style('font-size', '10px');

        // Demand bars
        g.selectAll('.bar-demand')
            .data(data)
            .enter()
            .append('rect')
            .attr('class', 'bar-demand')
            .attr('x', d => x(d.hour))
            .attr('y', d => y(d.demand))
            .attr('width', x.bandwidth() / 2)
            .attr('height', d => chartHeight - y(d.demand))
            .attr('fill', '#dc3545');

        // Capacity bars
        g.selectAll('.bar-capacity')
            .data(data)
            .enter()
            .append('rect')
            .attr('class', 'bar-capacity')
            .attr('x', d => x(d.hour) + x.bandwidth() / 2)
            .attr('y', d => y(d.capacity))
            .attr('width', x.bandwidth() / 2)
            .attr('height', d => chartHeight - y(d.capacity))
            .attr('fill', '#28a745');

        // Program rate line
        const programRate = params.program_rate || 40;
        g.append('line')
            .attr('x1', 0)
            .attr('x2', chartWidth)
            .attr('y1', y(programRate))
            .attr('y2', y(programRate))
            .attr('stroke', '#007bff')
            .attr('stroke-width', 2)
            .attr('stroke-dasharray', '5,5');

        // Legend
        const legend = svg.append('g')
            .attr('transform', `translate(${margin.left + 10}, ${margin.top})`);

        legend.append('rect').attr('x', 0).attr('y', 0).attr('width', 12).attr('height', 12).attr('fill', '#dc3545');
        legend.append('text').attr('x', 16).attr('y', 10).text('Demand').style('font-size', '10px');

        legend.append('rect').attr('x', 70).attr('y', 0).attr('width', 12).attr('height', 12).attr('fill', '#28a745');
        legend.append('text').attr('x', 86).attr('y', 10).text('Capacity').style('font-size', '10px');

        legend.append('line').attr('x1', 150).attr('x2', 170).attr('y1', 6).attr('y2', 6)
            .attr('stroke', '#007bff').attr('stroke-width', 2).attr('stroke-dasharray', '5,5');
        legend.append('text').attr('x', 175).attr('y', 10).text('Rate').style('font-size', '10px');

        // Y-axis label
        svg.append('text')
            .attr('transform', 'rotate(-90)')
            .attr('y', 10)
            .attr('x', -(height / 2))
            .attr('dy', '1em')
            .style('text-anchor', 'middle')
            .style('font-size', '11px')
            .text('Flights');
    }

    // =========================================================================
    // View Switching
    // =========================================================================

    function switchView(view) {
        state.currentView = view;

        const chartContainer = document.getElementById('gdp_chart_container');
        const tableContainer = document.getElementById('gdp_table_container');
        const slotsContainer = document.getElementById('gdp_slots_container');

        const chartBtn = document.getElementById('gdp_view_chart_btn');
        const tableBtn = document.getElementById('gdp_view_table_btn');
        const slotsBtn = document.getElementById('gdp_view_slots_btn');

        // Hide all
        if (chartContainer) {chartContainer.style.display = 'none';}
        if (tableContainer) {tableContainer.style.display = 'none';}
        if (slotsContainer) {slotsContainer.style.display = 'none';}

        // Deactivate all buttons
        [chartBtn, tableBtn, slotsBtn].forEach(btn => {
            if (btn) {btn.classList.remove('active');}
        });

        // Show selected
        switch (view) {
            case 'chart':
                if (chartContainer) {chartContainer.style.display = 'block';}
                if (chartBtn) {chartBtn.classList.add('active');}
                break;
            case 'table':
                if (tableContainer) {tableContainer.style.display = 'block';}
                if (tableBtn) {tableBtn.classList.add('active');}
                break;
            case 'slots':
                if (slotsContainer) {slotsContainer.style.display = 'block';}
                if (slotsBtn) {slotsBtn.classList.add('active');}
                break;
        }
    }

    // =========================================================================
    // Rate Mode Switching
    // =========================================================================

    function switchRateMode(mode) {
        state.rateMode = mode;

        const simplePanel = document.getElementById('gdp_rate_simple_panel');
        const detailedPanel = document.getElementById('gdp_rate_detailed_panel');
        const simpleBtn = document.getElementById('gdp_rate_mode_simple');
        const detailedBtn = document.getElementById('gdp_rate_mode_detailed');

        if (mode === 'simple') {
            if (simplePanel) {simplePanel.style.display = 'block';}
            if (detailedPanel) {detailedPanel.style.display = 'none';}
            if (simpleBtn) {simpleBtn.classList.add('active');}
            if (detailedBtn) {detailedBtn.classList.remove('active');}
        } else {
            if (simplePanel) {simplePanel.style.display = 'none';}
            if (detailedPanel) {detailedPanel.style.display = 'block';}
            if (simpleBtn) {simpleBtn.classList.remove('active');}
            if (detailedBtn) {detailedBtn.classList.add('active');}
            buildHourlyRateTable();
        }
    }

    function buildHourlyRateTable() {
        const startInput = document.getElementById('gdp_start') || document.getElementById('gdp_start_ddhhmm');
        const endInput = document.getElementById('gdp_end') || document.getElementById('gdp_end_ddhhmm');

        if (!startInput?.value || !endInput?.value) {
            console.log('GDP: Cannot build rate table - start/end times not set');
            return;
        }

        // Parse ddhhmm format or ISO format
        const parseDateValue = (val) => {
            if (val.length === 6) {
                // ddhhmm format
                const day = parseInt(val.slice(0, 2), 10);
                const hour = parseInt(val.slice(2, 4), 10);
                const min = parseInt(val.slice(4, 6), 10);
                const now = new Date();
                return new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), day, hour, min));
            }
            return new Date(val);
        };

        const start = parseDateValue(startInput.value);
        const end = parseDateValue(endInput.value);

        // Use the correct element IDs from the HTML
        const headerRow = document.getElementById('gdp_rate_header');
        const prRow = document.getElementById('gdp_rate_pr');
        const reserveRow = document.getElementById('gdp_rate_reserve');

        if (!headerRow || !prRow || !reserveRow) {
            console.log('GDP: Rate table elements not found');
            return;
        }

        // Build header and rate cells
        let headerHtml = '<th>Hour</th>';
        let prHtml = '<td class="font-weight-bold small">PR</td>';
        let reserveHtml = '<td class="font-weight-bold small text-info">Reserve</td>';

        const defaultRate = parseInt(document.getElementById('gdp_fill_value')?.value || '40', 10);

        const current = new Date(start);
        let hourCount = 0;
        while (current < end && hourCount < 24) {  // Limit to 24 hours
            const hour = current.getUTCHours();
            const hourStr = hour.toString().padStart(2, '0');

            headerHtml += `<th class="text-center small">${hourStr}Z</th>`;
            prHtml += `<td><input type="number" class="form-control form-control-sm text-center gdp-hourly-rate" data-hour="${hour}" value="${defaultRate}" min="0" max="120" style="width: 45px;"></td>`;
            reserveHtml += `<td><input type="number" class="form-control form-control-sm text-center gdp-hourly-reserve" data-hour="${hour}" value="0" min="0" max="20" style="width: 45px;"></td>`;

            current.setUTCHours(current.getUTCHours() + 1);
            hourCount++;
        }

        headerRow.innerHTML = headerHtml;
        prRow.innerHTML = prHtml;
        reserveRow.innerHTML = reserveHtml;

        console.log('GDP: Built rate table with', hourCount, 'hours');
    }

    // =========================================================================
    // Initialization
    // =========================================================================

    function init() {
        console.log('GDP Module initializing...');

        // Workflow buttons - map to actual HTML element IDs
        document.getElementById('gdp_reload_btn')?.addEventListener('click', handleReload);
        document.getElementById('gdp_model_btn')?.addEventListener('click', handleModel);  // Model = Preview + Simulate
        document.getElementById('gdp_submit_tmi_btn')?.addEventListener('click', handleSubmitToTmi);  // Submit to TMI Publishing

        // Scope method radio buttons
        document.getElementById('gdp_scope_tier')?.addEventListener('change', function() {
            if (this.checked) {switchScopeMode('tier');}
        });
        document.getElementById('gdp_scope_distance')?.addEventListener('change', function() {
            if (this.checked) {switchScopeMode('distance');}
        });

        // Fill rates button
        document.getElementById('gdp_fill_btn')?.addEventListener('click', handleFillRates);

        // Load Times button - build rate table from program times
        document.getElementById('gdp_load_times_btn')?.addEventListener('click', buildHourlyRateTable);

        // Load AAR button - placeholder for loading AAR data
        document.getElementById('gdp_load_aar_btn')?.addEventListener('click', () => {
            console.log('GDP: Load AAR clicked - feature coming soon');
            alert('Load ADL AAR feature coming soon.');
        });

        // Show Demand button
        document.getElementById('gdp_show_demand_btn')?.addEventListener('click', handleShowDemand);

        // Flight list and Slots list modals
        document.getElementById('gdp_flight_list_btn')?.addEventListener('click', openFlightListModal);
        document.getElementById('gdp_slots_list_btn')?.addEventListener('click', openSlotsListModal);

        // Bar graph ETA/CTA toggle
        document.getElementById('gdp_bar_eta_btn')?.addEventListener('click', () => switchBarGraphView('eta'));
        document.getElementById('gdp_bar_cta_btn')?.addEventListener('click', () => switchBarGraphView('cta'));

        // View toggle buttons
        document.getElementById('gdp_view_chart_btn')?.addEventListener('click', () => switchView('chart'));
        document.getElementById('gdp_view_table_btn')?.addEventListener('click', () => switchView('table'));
        document.getElementById('gdp_view_slots_btn')?.addEventListener('click', () => switchView('slots'));

        // Rate mode toggle
        document.getElementById('gdp_rate_mode_simple')?.addEventListener('click', () => switchRateMode('simple'));
        document.getElementById('gdp_rate_mode_detailed')?.addEventListener('click', () => switchRateMode('detailed'));

        // Scope mode toggle
        document.getElementById('gdp_scope_mode_tier')?.addEventListener('click', () => switchScopeMode('tier'));
        document.getElementById('gdp_scope_mode_distance')?.addEventListener('click', () => switchScopeMode('distance'));

        // Scope selector change - recompute included facilities
        document.getElementById('gdp_scope_select')?.addEventListener('change', recomputeGdpScope);

        // Fill rates button
        document.getElementById('gdp_fill_rates_btn')?.addEventListener('click', buildHourlyRateTable);

        // Model section close button
        document.getElementById('gdp_model_close_btn')?.addEventListener('click', () => {
            document.getElementById('gdp_model_section').style.display = 'none';
        });

        // Model chart controls
        document.getElementById('gdp_model_chart_view')?.addEventListener('change', renderModelCharts);
        document.getElementById('gdp_model_metric')?.addEventListener('change', renderModelCharts);

        // Collapse toggle icons
        $('#gdp_setup_body').on('show.bs.collapse', () => {
            document.getElementById('gdp_setup_toggle_icon')?.classList.replace('fa-chevron-down', 'fa-chevron-up');
        });
        $('#gdp_setup_body').on('hide.bs.collapse', () => {
            document.getElementById('gdp_setup_toggle_icon')?.classList.replace('fa-chevron-up', 'fa-chevron-down');
        });
        $('#gdp_exemptions_body').on('show.bs.collapse', () => {
            document.getElementById('gdp_exemptions_toggle_icon')?.classList.replace('fa-chevron-down', 'fa-chevron-up');
        });
        $('#gdp_exemptions_body').on('hide.bs.collapse', () => {
            document.getElementById('gdp_exemptions_toggle_icon')?.classList.replace('fa-chevron-up', 'fa-chevron-down');
        });

        // Set default times (round to next hour)
        setDefaultTimes();

        // Populate scope selector (uses TMI_TIER_INFO from tmi.js)
        populateGdpScopeSelector();

        console.log('GDP Module initialized.');
    }

    // =========================================================================
    // Scope Mode Switching
    // =========================================================================

    function switchScopeMode(mode) {
        const tierPanel = document.getElementById('gdp_tier_panel');
        const distancePanel = document.getElementById('gdp_distance_panel');

        if (mode === 'tier') {
            if (tierPanel) {tierPanel.style.display = 'block';}
            if (distancePanel) {distancePanel.style.display = 'none';}
        } else {
            if (tierPanel) {tierPanel.style.display = 'none';}
            if (distancePanel) {distancePanel.style.display = 'block';}
        }
    }

    // =========================================================================
    // Model/Power Run Functions
    // =========================================================================

    function handleOpenModel() {
        const modelSection = document.getElementById('gdp_model_section');
        if (modelSection) {
            modelSection.style.display = 'block';
            modelSection.scrollIntoView({ behavior: 'smooth' });
            renderModelCharts();
        }
    }

    function renderModelCharts() {
        const flights = state.simulatedFlights.length > 0 ? state.simulatedFlights : state.previewFlights;
        if (!flights || flights.length === 0) {return;}

        // Calculate statistics
        const stats = calculateModelStats(flights);

        // Update summary stats
        document.getElementById('gdp_model_stat_total').textContent = stats.total;
        document.getElementById('gdp_model_stat_delayed').textContent = stats.delayed;
        document.getElementById('gdp_model_stat_capped').textContent = stats.capped;
        document.getElementById('gdp_model_stat_total_delay').textContent = (stats.totalDelayMin / 60).toFixed(1);
        document.getElementById('gdp_model_stat_avg_delay').textContent = stats.avgDelay.toFixed(1);
        document.getElementById('gdp_model_stat_max_delay').textContent = stats.maxDelay;
        document.getElementById('gdp_model_stat_utilization').textContent =
            (state.summary?.slot_utilization || state.summary?.slot_utilization === 0)
                ? state.summary.slot_utilization + '%' : '--';

        // Render delay buckets
        renderDelayBuckets(stats.buckets, stats.total);

        // Render breakdown tables
        renderModelTable('gdp_model_by_artcc', stats.byArtcc);
        renderModelTable('gdp_model_by_carrier', stats.byCarrier);
        renderModelTable('gdp_model_by_hour', stats.byHour);

        // Render main chart
        renderModelChart(stats);
    }

    function calculateModelStats(flights) {
        const stats = {
            total: flights.length,
            delayed: 0,
            capped: 0,
            totalDelayMin: 0,
            avgDelay: 0,
            maxDelay: 0,
            buckets: { '0': 0, '1-15': 0, '16-30': 0, '31-60': 0, '61-90': 0, '91-120': 0, '121-180': 0, '180+': 0 },
            byArtcc: {},
            byCarrier: {},
            byHour: {},
        };

        flights.forEach(f => {
            const delay = parseInt(f.program_delay_min, 10) || 0;
            const isCapped = f.ctl_type === 'GDP-CAP';

            if (delay > 0) {stats.delayed++;}
            if (isCapped) {stats.capped++;}

            stats.totalDelayMin += delay;
            stats.maxDelay = Math.max(stats.maxDelay, delay);

            // Bucket
            if (delay === 0) {stats.buckets['0']++;}
            else if (delay <= 15) {stats.buckets['1-15']++;}
            else if (delay <= 30) {stats.buckets['16-30']++;}
            else if (delay <= 60) {stats.buckets['31-60']++;}
            else if (delay <= 90) {stats.buckets['61-90']++;}
            else if (delay <= 120) {stats.buckets['91-120']++;}
            else if (delay <= 180) {stats.buckets['121-180']++;}
            else {stats.buckets['180+']++;}

            // By ARTCC
            const artcc = f.fp_dept_artcc || 'UNK';
            if (!stats.byArtcc[artcc]) {stats.byArtcc[artcc] = { count: 0, totalDelay: 0 };}
            stats.byArtcc[artcc].count++;
            stats.byArtcc[artcc].totalDelay += delay;

            // By Carrier
            const carrier = f.major_carrier || 'UNK';
            if (!stats.byCarrier[carrier]) {stats.byCarrier[carrier] = { count: 0, totalDelay: 0 };}
            stats.byCarrier[carrier].count++;
            stats.byCarrier[carrier].totalDelay += delay;

            // By Hour (ETA)
            const eta = f.gdp_original_eta_utc || f.eta_runway_utc || '';
            let hour = 'UNK';
            if (eta && eta.match(/T(\d{2}):/)) {
                hour = eta.match(/T(\d{2}):/)[1] + 'Z';
            }
            if (!stats.byHour[hour]) {stats.byHour[hour] = { count: 0, totalDelay: 0 };}
            stats.byHour[hour].count++;
            stats.byHour[hour].totalDelay += delay;
        });

        stats.avgDelay = stats.total > 0 ? stats.totalDelayMin / stats.total : 0;

        return stats;
    }

    function renderDelayBuckets(buckets, total) {
        const tbody = document.getElementById('gdp_model_delay_buckets');
        if (!tbody) {return;}
        tbody.innerHTML = '';

        const bucketOrder = ['0', '1-15', '16-30', '31-60', '61-90', '91-120', '121-180', '180+'];
        bucketOrder.forEach(bucket => {
            const count = buckets[bucket] || 0;
            const pct = total > 0 ? ((count / total) * 100).toFixed(1) : '0.0';
            const tr = document.createElement('tr');
            tr.innerHTML = `<td>${bucket} min</td><td class="text-right">${count}</td><td class="text-right">${pct}%</td>`;
            tbody.appendChild(tr);
        });
    }

    function renderModelTable(tbodyId, data) {
        const tbody = document.getElementById(tbodyId);
        if (!tbody) {return;}
        tbody.innerHTML = '';

        // Sort by count descending
        const sorted = Object.entries(data).sort((a, b) => b[1].count - a[1].count);

        sorted.forEach(([key, val]) => {
            const avgDelay = val.count > 0 ? (val.totalDelay / val.count).toFixed(1) : '0';
            const tr = document.createElement('tr');
            tr.innerHTML = `<td>${key}</td><td class="text-right">${val.count}</td><td class="text-right">${avgDelay}</td>`;
            tbody.appendChild(tr);
        });
    }

    let gdpModelChart = null;

    function renderModelChart(stats) {
        const canvas = document.getElementById('gdp_model_chart');
        if (!canvas) {return;}

        const view = document.getElementById('gdp_model_chart_view')?.value || 'hourly';
        const metric = document.getElementById('gdp_model_metric')?.value || 'delay';

        let labels = [];
        let dataPoints = [];
        let chartLabel = 'Avg Delay (min)';

        if (view === 'hourly') {
            // Sort hours
            const hours = Object.keys(stats.byHour).sort();
            labels = hours;
            if (metric === 'count') {
                dataPoints = hours.map(h => stats.byHour[h].count);
                chartLabel = 'Flight Count';
            } else {
                dataPoints = hours.map(h => {
                    const d = stats.byHour[h];
                    return d.count > 0 ? (d.totalDelay / d.count).toFixed(1) : 0;
                });
            }
        } else if (view === 'orig_artcc') {
            const sorted = Object.entries(stats.byArtcc).sort((a, b) => b[1].count - a[1].count).slice(0, 10);
            labels = sorted.map(s => s[0]);
            if (metric === 'count') {
                dataPoints = sorted.map(s => s[1].count);
                chartLabel = 'Flight Count';
            } else {
                dataPoints = sorted.map(s => s[1].count > 0 ? (s[1].totalDelay / s[1].count).toFixed(1) : 0);
            }
        } else if (view === 'carrier') {
            const sorted = Object.entries(stats.byCarrier).sort((a, b) => b[1].count - a[1].count).slice(0, 10);
            labels = sorted.map(s => s[0]);
            if (metric === 'count') {
                dataPoints = sorted.map(s => s[1].count);
                chartLabel = 'Flight Count';
            } else {
                dataPoints = sorted.map(s => s[1].count > 0 ? (s[1].totalDelay / s[1].count).toFixed(1) : 0);
            }
        }

        // Destroy existing chart
        if (gdpModelChart) {
            gdpModelChart.destroy();
        }

        gdpModelChart = new Chart(canvas, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: chartLabel,
                    data: dataPoints,
                    backgroundColor: 'rgba(23, 162, 184, 0.7)',
                    borderColor: 'rgba(23, 162, 184, 1)',
                    borderWidth: 1,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: true, position: 'top' },
                },
                scales: {
                    y: { beginAtZero: true },
                },
            },
        });
    }

    function copyGsScopeOptions() {
        // No longer needed - we use populateGdpScopeSelector instead
    }

    // =========================================================================
    // GDP Scope Selector - Copy from GS scope selector (same data)
    // =========================================================================

    let scopeRetryCount = 0;
    const MAX_SCOPE_RETRIES = 20;

    function populateGdpScopeSelector() {
        const gdpSel = document.getElementById('gdp_scope_select');
        const gsSel = document.getElementById('gs_scope_select');

        if (!gdpSel) {return;}

        // Copy options from GS scope selector if available
        if (gsSel && gsSel.options.length > 0) {
            gdpSel.innerHTML = '';

            // Clone the entire structure including optgroups
            Array.from(gsSel.children).forEach(child => {
                gdpSel.appendChild(child.cloneNode(true));
            });

            console.log('GDP: Scope selector populated from GS (' + gsSel.options.length + ' options)');
            return;
        }

        // Retry if GS scope not ready yet
        scopeRetryCount++;
        if (scopeRetryCount < MAX_SCOPE_RETRIES) {
            console.log('GDP: GS scope selector not ready, retry ' + scopeRetryCount + '/' + MAX_SCOPE_RETRIES);
            setTimeout(populateGdpScopeSelector, 500);
        } else {
            console.warn('GDP: Could not populate scope selector after ' + MAX_SCOPE_RETRIES + ' retries');
        }
    }

    function recomputeGdpScope() {
        const sel = document.getElementById('gdp_scope_select');
        const gsSel = document.getElementById('gs_scope_select');
        if (!sel) {return;}

        const selected = Array.from(sel.selectedOptions || []);
        const originCentersField = document.getElementById('gdp_origin_centers');
        const depFacilitiesField = document.getElementById('gdp_dep_facilities');

        // Check if any selection is "Manual" mode
        const manual = selected.some(o => o.dataset && o.dataset.type === 'manual');

        if (manual) {
            // Manual mode: user types directly, don't auto-populate
            return;
        }

        // Build the included facilities list by looking at what GS would compute
        // We can use a similar approach: check dataset.type and gather facilities
        const includedSet = new Set();
        const scopeTokens = [];

        selected.forEach(o => {
            const type = o.dataset ? o.dataset.type : '';
            const val = o.value;

            if (type === 'fac') {
                // Individual facility
                scopeTokens.push(val);
                includedSet.add(val);
            } else if (type === 'tier' || type === 'special') {
                // For tiers, we need the expanded list
                // Since we don't have direct access to TMI_TIER_INFO_BY_CODE,
                // we'll trigger GS's recompute by temporarily selecting the same options
                // Or just pass the tier code and let the API handle expansion
                scopeTokens.push(val);

                // Try to get the expanded list from GS if it has same selection
                // For now, just include the code - the API should handle tier expansion
            }
        });

        if (originCentersField) {
            originCentersField.value = scopeTokens.join(' ');
        }

        // For dep_facilities, if we have individual facilities, use those
        // Otherwise, pass "ALL" or empty to let server handle tier expansion
        if (depFacilitiesField) {
            if (includedSet.size > 0) {
                depFacilitiesField.value = Array.from(includedSet).sort().join(' ');
            } else if (scopeTokens.length > 0) {
                // We have tier codes but no individual facilities
                // Check if GS has computed the dep_facilities for the same selection
                const gsDepFac = document.getElementById('gs_dep_facilities');
                if (gsDepFac && gsDepFac.value) {
                    // Borrow from GS if available
                    depFacilitiesField.value = gsDepFac.value;
                } else {
                    depFacilitiesField.value = 'ALL';
                }
            } else {
                depFacilitiesField.value = '';
            }
        }
    }

    function setDefaultTimes() {
        const now = new Date();
        const roundedHour = new Date(now);
        roundedHour.setUTCMinutes(0, 0, 0);
        roundedHour.setUTCHours(roundedHour.getUTCHours() + 1);

        const endTime = new Date(roundedHour);
        endTime.setUTCHours(endTime.getUTCHours() + 4);

        const formatForInput = (d) => {
            return d.toISOString().slice(0, 16);
        };

        // Try both ID patterns for compatibility
        const startInput = document.getElementById('gdp_start') || document.getElementById('gdp_start_ddhhmm');
        const endInput = document.getElementById('gdp_end') || document.getElementById('gdp_end_ddhhmm');

        // Format for ddhhmm style inputs (e.g., 201800 for day 20, 18:00)
        const formatDdhhmm = (d) => {
            const day = d.getUTCDate().toString().padStart(2, '0');
            const hour = d.getUTCHours().toString().padStart(2, '0');
            const min = d.getUTCMinutes().toString().padStart(2, '0');
            return day + hour + min;
        };

        if (startInput && !startInput.value) {
            // Check input type/placeholder to determine format
            if (startInput.placeholder && startInput.placeholder.length === 6) {
                startInput.value = formatDdhhmm(roundedHour);
            } else {
                startInput.value = formatForInput(roundedHour);
            }
        }
        if (endInput && !endInput.value) {
            if (endInput.placeholder && endInput.placeholder.length === 6) {
                endInput.value = formatDdhhmm(endTime);
            } else {
                endInput.value = formatForInput(endTime);
            }
        }
    }

    // =========================================================================
    // Public API
    // =========================================================================

    return {
        init: init,
        preview: handlePreview,
        simulate: handleSimulate,
        apply: handleApply,
        purgeLocal: handlePurgeLocal,
        purge: handlePurge,
        openModel: handleOpenModel,
        switchScopeMode: switchScopeMode,
        getState: () => state,
    };

})();

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    // Small delay to ensure GS module has initialized first
    setTimeout(() => {
        GDP.init();
    }, 100);
});
