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
        purge: 'api/tmi/gdp_purge.php'
    };

    // State tracking
    let state = {
        status: 'DRAFT',        // DRAFT, PREVIEWED, SIMULATED, ACTIVE
        programId: null,
        previewFlights: [],
        simulatedFlights: [],
        slots: [],
        summary: null,
        rateMode: 'simple',     // simple or detailed
        currentView: 'chart'    // chart, table, slots
    };

    // D3 chart reference
    let d3Chart = null;

    // =========================================================================
    // Helpers
    // =========================================================================

    function apiPostJson(url, payload) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(r => r.json());
    }

    function formatUtcTime(isoStr) {
        if (!isoStr) return '--';
        try {
            const d = new Date(isoStr);
            return d.toISOString().slice(11, 16) + 'Z';
        } catch (e) {
            return isoStr;
        }
    }

    function formatUtcDateTime(isoStr) {
        if (!isoStr) return '--';
        try {
            const d = new Date(isoStr);
            return d.toISOString().slice(0, 16).replace('T', ' ') + 'Z';
        } catch (e) {
            return isoStr;
        }
    }

    function formatDelay(minutes) {
        if (minutes === null || minutes === undefined) return '--';
        const m = parseInt(minutes, 10);
        if (isNaN(m)) return '--';
        if (m <= 0) return '0';
        return '+' + m + 'm';
    }

    function getDelayClass(minutes) {
        const m = parseInt(minutes, 10);
        if (isNaN(m) || m <= 0) return '';
        if (m < 15) return 'text-success';
        if (m < 30) return 'text-warning';
        if (m < 60) return 'text-orange';
        return 'text-danger';
    }

    function updateStatusBadge(status) {
        const badge = document.getElementById('gdp_status_badge');
        if (!badge) return;
        
        const statusMap = {
            'DRAFT': { text: 'Draft (local)', class: 'badge-secondary' },
            'PREVIEWED': { text: 'Previewed', class: 'badge-info' },
            'SIMULATED': { text: 'Simulated', class: 'badge-warning' },
            'ACTIVE': { text: 'Active', class: 'badge-success' },
            'PURGED': { text: 'Purged', class: 'badge-danger' }
        };
        
        const info = statusMap[status] || statusMap['DRAFT'];
        badge.textContent = info.text;
        badge.className = 'badge tmi-badge-status ' + info.class;
        state.status = status;
    }

    function updateWorkflowButtons() {
        const previewBtn = document.getElementById('gdp_preview_btn');
        const simulateBtn = document.getElementById('gdp_simulate_btn');
        const modelBtn = document.getElementById('gdp_model_btn');
        const applyBtn = document.getElementById('gdp_apply_btn');
        const purgeLocalBtn = document.getElementById('gdp_purge_local_btn');
        const purgeBtn = document.getElementById('gdp_purge_btn');

        // Enable based on current state
        if (simulateBtn) simulateBtn.disabled = (state.status === 'DRAFT');
        if (modelBtn) modelBtn.disabled = (state.status !== 'SIMULATED' && state.status !== 'ACTIVE');
        if (applyBtn) applyBtn.disabled = (state.status !== 'SIMULATED');
        if (purgeLocalBtn) purgeLocalBtn.disabled = (state.status === 'DRAFT');
        if (purgeBtn) purgeBtn.disabled = (state.status !== 'ACTIVE');
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
            exemptions: collectExemptions()
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
            airborne: document.getElementById('gdp_exempt_airborne')?.checked || false
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
    // Preview Handler
    // =========================================================================

    async function handlePreview() {
        const payload = collectGdpPayload();
        
        if (!payload.gdp_airport) {
            alert('Please enter a CTL Element (airport code).');
            return;
        }

        console.log('GDP Preview payload:', payload);

        const btn = document.getElementById('gdp_preview_btn');
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
                btn.innerHTML = '<i class="fas fa-search mr-1"></i> Preview';
            }
        }
    }

    // =========================================================================
    // Simulate Handler
    // =========================================================================

    async function handleSimulate() {
        const payload = collectGdpPayload();
        
        if (!payload.gdp_airport || !payload.gdp_start || !payload.gdp_end) {
            alert('Please enter CTL Element, Start, and End times.');
            return;
        }

        const btn = document.getElementById('gdp_simulate_btn');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Simulating...';
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
            
        } catch (err) {
            console.error('Simulate error:', err);
            alert('Simulation failed: ' + err.message);
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-play mr-1"></i> Simulate';
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
        
        const btn = document.getElementById('gdp_apply_btn');
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
                btn.innerHTML = '<i class="fas fa-check-circle mr-1"></i> Send Actual';
            }
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
                gdp_airport: document.getElementById('gdp_ctl_element')?.value || ''
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
        const el = document.getElementById('gdp_flight_count');
        if (el) {
            el.textContent = affected + ' flights' + (exempt > 0 ? ' (' + exempt + ' exempt)' : '');
        }
    }

    function updateMetrics(summary) {
        if (!summary) return;
        
        const set = (id, val) => {
            const el = document.getElementById(id);
            if (el) el.textContent = val;
        };
        
        set('gdp_metric_total', summary.total_flights || summary.assigned_flights || '--');
        set('gdp_metric_avg_delay', summary.avg_delay_min !== null ? summary.avg_delay_min + 'm' : '--');
        set('gdp_metric_max_delay', summary.max_delay_min !== null ? summary.max_delay_min + 'm' : '--');
        set('gdp_metric_utilization', summary.slot_utilization !== undefined ? summary.slot_utilization + '%' : '--');
    }

    function renderSummaryTables(summary) {
        if (!summary) return;

        // By Origin Center
        renderCountTable('gdp_counts_origin_center', summary.by_origin_center);
        
        // By Hour
        renderCountTable('gdp_counts_hour', summary.by_hour);
        
        // By Carrier
        renderCountTable('gdp_counts_carrier', summary.by_carrier);
    }

    function renderCountTable(tbodyId, data) {
        const tbody = document.getElementById(tbodyId);
        if (!tbody || !data) return;
        
        tbody.innerHTML = '';
        
        for (const [key, count] of Object.entries(data)) {
            const tr = document.createElement('tr');
            tr.innerHTML = '<td>' + key + '</td><td class="text-right">' + count + '</td>';
            tbody.appendChild(tr);
        }
    }

    function renderFlightTable(flights, hasSlots) {
        const tbody = document.getElementById('gdp_flight_table_body');
        if (!tbody) return;
        
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
        if (!tbody) return;
        
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
        if (!container) return;
        
        container.innerHTML = '';
        
        const data = Object.entries(summary.by_hour).map(([hour, count]) => ({
            hour: hour,
            demand: count
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
        if (!container) return;
        
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
            capacity: hourlyCapacity[hour] || 0
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
            d3.max(data, d => d.capacity)
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
        if (chartContainer) chartContainer.style.display = 'none';
        if (tableContainer) tableContainer.style.display = 'none';
        if (slotsContainer) slotsContainer.style.display = 'none';

        // Deactivate all buttons
        [chartBtn, tableBtn, slotsBtn].forEach(btn => {
            if (btn) btn.classList.remove('active');
        });

        // Show selected
        switch (view) {
            case 'chart':
                if (chartContainer) chartContainer.style.display = 'block';
                if (chartBtn) chartBtn.classList.add('active');
                break;
            case 'table':
                if (tableContainer) tableContainer.style.display = 'block';
                if (tableBtn) tableBtn.classList.add('active');
                break;
            case 'slots':
                if (slotsContainer) slotsContainer.style.display = 'block';
                if (slotsBtn) slotsBtn.classList.add('active');
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
            if (simplePanel) simplePanel.style.display = 'block';
            if (detailedPanel) detailedPanel.style.display = 'none';
            if (simpleBtn) simpleBtn.classList.add('active');
            if (detailedBtn) detailedBtn.classList.remove('active');
        } else {
            if (simplePanel) simplePanel.style.display = 'none';
            if (detailedPanel) detailedPanel.style.display = 'block';
            if (simpleBtn) simpleBtn.classList.remove('active');
            if (detailedBtn) detailedBtn.classList.add('active');
            buildHourlyRateTable();
        }
    }

    function buildHourlyRateTable() {
        const startInput = document.getElementById('gdp_start') || document.getElementById('gdp_start_ddhhmm');
        const endInput = document.getElementById('gdp_end') || document.getElementById('gdp_end_ddhhmm');

        if (!startInput?.value || !endInput?.value) return;

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
        
        const headers = document.getElementById('gdp_hourly_headers');
        const rates = document.getElementById('gdp_hourly_rates');
        const reserves = document.getElementById('gdp_hourly_reserves');
        
        if (!headers || !rates || !reserves) return;

        let headerHtml = '';
        let ratesHtml = '';
        let reservesHtml = '';
        
        const defaultRate = document.getElementById('gdp_program_rate')?.value || 40;
        const defaultReserve = document.getElementById('gdp_reserve_rate')?.value || 0;

        const current = new Date(start);
        while (current < end) {
            const hour = current.getUTCHours();
            const hourStr = hour.toString().padStart(2, '0');
            
            headerHtml += `<th>${hourStr}Z</th>`;
            ratesHtml += `<td><input type="number" class="form-control form-control-sm gdp-hourly-rate" data-hour="${hour}" value="${defaultRate}" min="0" max="120" style="width: 50px;"></td>`;
            reservesHtml += `<td><input type="number" class="form-control form-control-sm gdp-hourly-reserve" data-hour="${hour}" value="${defaultReserve}" min="0" max="20" style="width: 50px;"></td>`;
            
            current.setUTCHours(current.getUTCHours() + 1);
        }

        headers.innerHTML = headerHtml;
        rates.innerHTML = ratesHtml;
        reserves.innerHTML = reservesHtml;
    }

    // =========================================================================
    // Initialization
    // =========================================================================

    function init() {
        console.log('GDP Module initializing...');

        // Workflow buttons
        document.getElementById('gdp_preview_btn')?.addEventListener('click', handlePreview);
        document.getElementById('gdp_simulate_btn')?.addEventListener('click', handleSimulate);
        document.getElementById('gdp_model_btn')?.addEventListener('click', handleOpenModel);
        document.getElementById('gdp_apply_btn')?.addEventListener('click', handleApply);
        document.getElementById('gdp_purge_local_btn')?.addEventListener('click', handlePurgeLocal);
        document.getElementById('gdp_purge_btn')?.addEventListener('click', handlePurge);

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
        const tierPanel = document.getElementById('gdp_scope_tier_panel');
        const distancePanel = document.getElementById('gdp_scope_distance_panel');
        const tierBtn = document.getElementById('gdp_scope_mode_tier');
        const distanceBtn = document.getElementById('gdp_scope_mode_distance');

        if (mode === 'tier') {
            if (tierPanel) tierPanel.style.display = 'block';
            if (distancePanel) distancePanel.style.display = 'none';
            if (tierBtn) tierBtn.classList.add('active');
            if (distanceBtn) distanceBtn.classList.remove('active');
        } else {
            if (tierPanel) tierPanel.style.display = 'none';
            if (distancePanel) distancePanel.style.display = 'block';
            if (tierBtn) tierBtn.classList.remove('active');
            if (distanceBtn) distanceBtn.classList.add('active');
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
        if (!flights || flights.length === 0) return;

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
            byHour: {}
        };

        flights.forEach(f => {
            const delay = parseInt(f.program_delay_min, 10) || 0;
            const isCapped = f.ctl_type === 'GDP-CAP';
            
            if (delay > 0) stats.delayed++;
            if (isCapped) stats.capped++;
            
            stats.totalDelayMin += delay;
            stats.maxDelay = Math.max(stats.maxDelay, delay);

            // Bucket
            if (delay === 0) stats.buckets['0']++;
            else if (delay <= 15) stats.buckets['1-15']++;
            else if (delay <= 30) stats.buckets['16-30']++;
            else if (delay <= 60) stats.buckets['31-60']++;
            else if (delay <= 90) stats.buckets['61-90']++;
            else if (delay <= 120) stats.buckets['91-120']++;
            else if (delay <= 180) stats.buckets['121-180']++;
            else stats.buckets['180+']++;

            // By ARTCC
            const artcc = f.fp_dept_artcc || 'UNK';
            if (!stats.byArtcc[artcc]) stats.byArtcc[artcc] = { count: 0, totalDelay: 0 };
            stats.byArtcc[artcc].count++;
            stats.byArtcc[artcc].totalDelay += delay;

            // By Carrier
            const carrier = f.major_carrier || 'UNK';
            if (!stats.byCarrier[carrier]) stats.byCarrier[carrier] = { count: 0, totalDelay: 0 };
            stats.byCarrier[carrier].count++;
            stats.byCarrier[carrier].totalDelay += delay;

            // By Hour (ETA)
            const eta = f.gdp_original_eta_utc || f.eta_runway_utc || '';
            let hour = 'UNK';
            if (eta && eta.match(/T(\d{2}):/)) {
                hour = eta.match(/T(\d{2}):/)[1] + 'Z';
            }
            if (!stats.byHour[hour]) stats.byHour[hour] = { count: 0, totalDelay: 0 };
            stats.byHour[hour].count++;
            stats.byHour[hour].totalDelay += delay;
        });

        stats.avgDelay = stats.total > 0 ? stats.totalDelayMin / stats.total : 0;

        return stats;
    }

    function renderDelayBuckets(buckets, total) {
        const tbody = document.getElementById('gdp_model_delay_buckets');
        if (!tbody) return;
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
        if (!tbody) return;
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
        if (!canvas) return;

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
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: true, position: 'top' }
                },
                scales: {
                    y: { beginAtZero: true }
                }
            }
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
        
        if (!gdpSel) return;

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
        if (!sel) return;

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
        getState: () => state
    };

})();

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    // Small delay to ensure GS module has initialized first
    setTimeout(() => {
        GDP.init();
    }, 100);
});
