/**
 * Statsim Traffic Data & Hourly Rates Module with FSM-style Demand Bar Graphs
 * For PERTI Review page
 * 
 * FSM Chapter 5 Styling:
 * - Title: "{ICAO} MM/DD/YYYY HH:MMZ"
 * - Arrivals bar LEFT, Departures bar RIGHT (between gridlines)
 * - Vertical HHMM labels at period start
 * - Stepped rate lines (3px thick)
 * - Legend on canvas
 * - H+0 determined from PERTI Plan event start
 */

(function() {
    'use strict';
    
    let planId = null;
    let planInfo = null;  // Store plan info including H+0
    
    function getPlanId() {
        const planIdInput = document.getElementById('plan_id');
        if (planIdInput && planIdInput.value) {
            return planIdInput.value;
        }
        const urlParams = new URLSearchParams(window.location.search);
        return urlParams.keys().next().value;
    }
    
    // =========================================================================
    // Custom Chart.js Plugin: Draw Legend on Canvas
    // =========================================================================
    const legendPlugin = {
        id: 'canvasLegend',
        afterDraw: function(chart) {
            const ctx = chart.ctx;
            const chartArea = chart.chartArea;
            const legendY = chart.height - 22;
            
            ctx.save();
            
            // Legend background
            ctx.fillStyle = '#d0d0d0';
            ctx.fillRect(chartArea.left, legendY - 2, chartArea.right - chartArea.left, 20);
            ctx.strokeStyle = '#808080';
            ctx.lineWidth = 1;
            ctx.strokeRect(chartArea.left, legendY - 2, chartArea.right - chartArea.left, 20);
            
            // Legend items - Arrivals first (left bar), then Departures (right bar)
            const items = [
                { color: '#ff0000', label: 'Arrivals', type: 'box' },
                { color: '#00ff00', label: 'Departures', type: 'box' },
                { color: '#ffffff', label: 'VATSIM AAR', type: 'line', dash: false },
                { color: '#ffffff', label: 'VATSIM ADR', type: 'line', dash: true },
                { color: '#00ffff', label: 'RW AAR', type: 'line', dash: false },
                { color: '#00ffff', label: 'RW ADR', type: 'line', dash: true }
            ];
            
            ctx.font = 'bold 9px "Segoe UI", Tahoma, Arial, sans-serif';
            ctx.textBaseline = 'middle';
            
            let x = chartArea.left + 8;
            const y = legendY + 8;
            
            items.forEach(item => {
                if (item.type === 'box') {
                    ctx.fillStyle = item.color;
                    ctx.fillRect(x, y - 5, 10, 10);
                    ctx.strokeStyle = '#000';
                    ctx.lineWidth = 1;
                    ctx.strokeRect(x, y - 5, 10, 10);
                    x += 14;
                } else {
                    ctx.strokeStyle = item.color;
                    ctx.lineWidth = 3;
                    if (item.dash) {
                        ctx.setLineDash([4, 2]);
                    } else {
                        ctx.setLineDash([]);
                    }
                    ctx.beginPath();
                    ctx.moveTo(x, y);
                    ctx.lineTo(x + 16, y);
                    ctx.stroke();
                    ctx.setLineDash([]);
                    x += 20;
                }
                
                ctx.fillStyle = '#000';
                ctx.fillText(item.label, x, y);
                x += ctx.measureText(item.label).width + 12;
            });
            
            ctx.restore();
        }
    };
    
    // Register the plugin
    Chart.register(legendPlugin);
    
    // =========================================================================
    // Statsim Module
    // =========================================================================
    const Statsim = {
        defaults: null,
        airportNames: {},  // Standardized names from VATSIM_ADL.dbo.apts
        
        init: function() {
            this.loadPlanDefaults();
            this.bindEvents();
        },
        
        loadPlanDefaults: function() {
            if (!planId) return;
            
            fetch(`api/statsim/plan_info.php?id=${planId}`)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        planInfo = data;  // Store full plan info for H+0 reference
                        this.defaults = data.defaults;
                        
                        // Store standardized airport names from VATSIM_ADL.dbo.apts
                        if (data.airports && Array.isArray(data.airports)) {
                            data.airports.forEach(apt => {
                                if (apt.icao && apt.name) {
                                    this.airportNames[apt.icao] = apt.name;
                                }
                            });
                            // Pass to HourlyRates for use in headers
                            HourlyRates.setAirportNames(this.airportNames);
                        }
                        
                        this.applyDefaults();
                        this.updateUrlDisplay();
                        
                        // Set H+0 for HourlyRates from PERTI Plan
                        if (data.defaults && data.defaults.h0_datetime) {
                            HourlyRates.setEventStartHour(data.defaults.h0_datetime);
                        }
                    }
                })
                .catch(err => console.error('Failed to load plan defaults:', err));
        },
        
        applyDefaults: function() {
            if (!this.defaults) return;
            document.getElementById('statsim_airports').value = this.defaults.airports || '';
            document.getElementById('statsim_from').value = this.defaults.from || '';
            document.getElementById('statsim_to').value = this.defaults.to || '';
        },
        
        bindEvents: function() {
            document.getElementById('statsim_fetch').addEventListener('click', () => this.fetchData());
            document.getElementById('statsim_open_url').addEventListener('click', () => this.openUrl());
            document.getElementById('statsim_reset_defaults').addEventListener('click', () => this.applyDefaults());
            
            ['statsim_airports', 'statsim_from', 'statsim_to'].forEach(id => {
                document.getElementById(id).addEventListener('input', () => this.updateUrlDisplay());
            });
        },
        
        buildUrl: function() {
            const airports = document.getElementById('statsim_airports').value.replace(/\s/g, '');
            const from = document.getElementById('statsim_from').value;
            const to = document.getElementById('statsim_to').value;
            
            if (!airports || !from || !to) return null;
            
            return `https://statsim.net/events/custom/?airports=${airports}&period=custom&from=${from}&to=${to}`;
        },
        
        updateUrlDisplay: function() {
            const url = this.buildUrl();
            const display = document.getElementById('statsim_url_display');
            const link = document.getElementById('statsim_url_link');
            
            if (url) {
                link.href = url;
                link.textContent = url;
                display.style.display = 'block';
            } else {
                display.style.display = 'none';
            }
        },
        
        openUrl: function() {
            const url = this.buildUrl();
            if (url) window.open(url, '_blank');
            else alert('Please fill in all fields first.');
        },
        
        fetchData: function() {
            const airports = document.getElementById('statsim_airports').value;
            const from = document.getElementById('statsim_from').value;
            const to = document.getElementById('statsim_to').value;
            
            if (!airports || !from || !to) {
                alert('Please fill in all fields.');
                return;
            }
            
            const resultsDiv = document.getElementById('statsim_results');
            resultsDiv.innerHTML = '<div class="statsim-loading"><i class="fas fa-spinner fa-spin"></i> Fetching data from Statsim...</div>';
            
            fetch(`api/statsim/fetch.php?airports=${airports}&from=${from}&to=${to}`)
                .then(r => r.json())
                .then(data => this.displayResults(data))
                .catch(err => {
                    resultsDiv.innerHTML = `<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> Error: ${err.message}</div>`;
                });
        },
        
        displayResults: function(data) {
            const resultsDiv = document.getElementById('statsim_results');
            
            if (data.error === true || data.success === false) {
                resultsDiv.innerHTML = `
                    <div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> ${data.message || 'An error occurred'}</div>
                    ${data.statsim_url ? `<p><a href="${data.statsim_url}" target="_blank" class="btn btn-sm btn-primary">Open Statsim</a></p>` : ''}
                `;
                return;
            }
            
            if (data.scraping_available === false) {
                resultsDiv.innerHTML = `
                    <div class="alert alert-info"><i class="fas fa-info-circle"></i> ${data.message}</div>
                    <p><a href="${data.statsim_url}" target="_blank" class="btn btn-sm btn-primary"><i class="fas fa-external-link-alt"></i> Open Statsim</a></p>
                `;
                return;
            }
            
            resultsDiv.innerHTML = `
                <div class="statsim-totals mb-3">
                    <div class="total-item">
                        <div class="total-label">Total Movements</div>
                        <div class="total-value">${data.totalMovements || (data.totals.arrivals + data.totals.departures)}</div>
                    </div>
                    <div class="total-item">
                        <div class="total-label">Arrivals</div>
                        <div class="total-value arrivals">${data.totals?.arrivals || 0}</div>
                    </div>
                    <div class="total-item">
                        <div class="total-label">Departures</div>
                        <div class="total-value departures">${data.totals?.departures || 0}</div>
                    </div>
                </div>
                <p class="small text-muted mb-0"><i class="fas fa-link"></i> Source: <a href="${data.statsim_url}" target="_blank">${data.statsim_url}</a></p>
            `;
            
            if (data.airports && data.airports.length > 0) {
                HourlyRates.populateFromStatsim(data);
            }
        }
    };
    
    // =========================================================================
    // HourlyRates Module with FSM-style Bar Graphs
    // =========================================================================
    const HourlyRates = {
        airports: {},
        statsimData: null,
        charts: {},
        eventStartHour: null,  // H+0 reference point from PERTI Plan
        airportNames: {},  // Standardized names from VATSIM_ADL.dbo.apts
        
        /**
         * Set standardized airport names from VATSIM_ADL.dbo.apts
         */
        setAirportNames: function(names) {
            this.airportNames = names || {};
        },
        
        /**
         * Get standardized airport name
         * Priority: 1) VATSIM_ADL.dbo.apts, 2) Statsim data, 3) ICAO
         */
        getAirportName: function(icao) {
            // First check DB names from plan_info
            if (this.airportNames[icao] && this.airportNames[icao] !== icao) {
                return this.airportNames[icao];
            }
            // Fallback to statsim-provided name
            if (this.airports[icao] && this.airports[icao].name) {
                // Clean up statsim name (often includes ICAO in parentheses)
                let name = this.airports[icao].name;
                name = name.replace(/\s*\([^)]*\)\s*$/, '').trim();  // Remove trailing (ICAO)
                if (name && name !== icao) {
                    return name;
                }
            }
            return icao;
        },
        
        /**
         * Set H+0 from PERTI Plan (called by Statsim.loadPlanDefaults)
         */
        setEventStartHour: function(h0_datetime) {
            if (h0_datetime) {
                this.eventStartHour = new Date(h0_datetime);
                console.log('H+0 set from PERTI Plan:', this.eventStartHour.toISOString());
            }
        },
        
        /**
         * Calculate relative hour label (H-2, H-1, H+0, H+1, etc.)
         * Based on PERTI Plan event start, not statsim data
         */
        getRelativeHourLabel: function(hourTimestamp) {
            if (!this.eventStartHour) return '';
            
            const hourDate = new Date(hourTimestamp);
            hourDate.setUTCMinutes(0);
            hourDate.setUTCSeconds(0);
            hourDate.setUTCMilliseconds(0);
            
            const diffMs = hourDate.getTime() - this.eventStartHour.getTime();
            const diffHours = Math.round(diffMs / (1000 * 60 * 60));
            
            if (diffHours === 0) return 'H+0';
            if (diffHours > 0) return `H+${diffHours}`;
            return `H${diffHours}`;
        },
        
        /**
         * Format date as DD/HHMM for table display
         */
        formatTableTime: function(date, time) {
            // date format: "YYYY-MM-DD", time format: "HH:MM"
            const day = date.substring(8, 10);
            const hhmm = time.replace(':', '');
            return `${day}/${hhmm}`;
        },
        
        /**
         * Format date as MM/DD/YYYY for chart title
         */
        formatChartDate: function(date) {
            // date format: "YYYY-MM-DD" -> MM/DD/YYYY
            const parts = date.split('-');
            return `${parts[1]}/${parts[2]}/${parts[0]}`;
        },
        
        /**
         * Format time as HH:MMZ for chart title
         */
        formatChartTime: function(time) {
            return time + 'Z';
        },
        
        populateFromStatsim: function(data) {
            this.statsimData = data;
            this.airports = {};
            
            Object.values(this.charts).forEach(chart => chart.destroy());
            this.charts = {};
            
            // Note: eventStartHour is already set from PERTI Plan via setEventStartHour()
            
            data.airports.forEach(airport => {
                this.airports[airport.icao] = {
                    name: airport.name,
                    totalArr: airport.arrivals,
                    totalDep: airport.departures,
                    hours: []
                };
                
                if (airport.hourly && airport.hourly.length > 0) {
                    airport.hourly.forEach(h => {
                        this.airports[airport.icao].hours.push({
                            timestamp: h.timestamp,
                            date: h.date,
                            time: h.time,
                            tableTime: this.formatTableTime(h.date, h.time),
                            relativeHour: this.getRelativeHourLabel(h.timestamp),
                            statsim_arr: h.arrivals,
                            statsim_dep: h.departures,
                            vatsim_aar: '',
                            vatsim_adr: '',
                            rw_aar: '',
                            rw_adr: ''
                        });
                    });
                }
            });
            
            this.renderAll();
        },
        
        renderAll: function() {
            const container = document.getElementById('hourly_rates_container');
            
            if (Object.keys(this.airports).length === 0) {
                container.innerHTML = `<div class="text-muted text-center py-3"><i class="fas fa-info-circle"></i> Fetch Statsim data to populate hourly rate inputs.</div>`;
                document.getElementById('rates_actions').style.display = 'none';
                return;
            }
            
            let html = '';
            
            Object.keys(this.airports).forEach(icao => {
                const airport = this.airports[icao];
                // Use standardized name from VATSIM_ADL.dbo.apts
                const displayName = this.getAirportName(icao);
                
                html += `
                    <div class="airport-rates-card" id="airport_card_${icao}">
                        <div class="airport-rates-header">
                            <strong>${icao}</strong> - ${displayName}
                            <span class="float-right">
                                <span class="badge badge-arr">ARR: ${airport.totalArr}</span>
                                <span class="badge badge-dep">DEP: ${airport.totalDep}</span>
                            </span>
                        </div>
                        
                        <!-- Export buttons bar -->
                        <div class="chart-export-bar">
                            <button class="btn btn-sm btn-secondary" onclick="HourlyRates.exportPNG('${icao}')" title="Download PNG"><i class="fas fa-download"></i> PNG</button>
                            <button class="btn btn-sm btn-secondary" onclick="HourlyRates.exportSVG('${icao}')" title="Download SVG"><i class="fas fa-download"></i> SVG</button>
                            <button class="btn btn-sm btn-secondary" onclick="HourlyRates.exportChartCSV('${icao}')" title="Download CSV"><i class="fas fa-download"></i> CSV</button>
                            <button class="btn btn-sm btn-info" onclick="HourlyRates.copyToClipboard('${icao}', 'png')" title="Copy PNG to clipboard"><i class="fas fa-copy"></i> Copy</button>
                        </div>
                        
                        <!-- FSM-style Demand Bar Graph (title & legend on canvas) -->
                        <div class="demand-chart-container">
                            <canvas id="chart_${icao}"></canvas>
                        </div>
                        
                        <!-- Quick Fill Controls -->
                        <div class="quick-fill-row">
                            <div class="quick-fill-group">
                                <label>VATSIM AAR:</label>
                                <input type="number" class="form-control form-control-sm" id="qf_${icao}_vatsim_aar" min="0">
                                <button class="btn btn-sm btn-light" onclick="HourlyRates.quickFill('${icao}', 'vatsim_aar')" title="Fill all"><i class="fas fa-fill"></i></button>
                            </div>
                            <div class="quick-fill-group">
                                <label>VATSIM ADR:</label>
                                <input type="number" class="form-control form-control-sm" id="qf_${icao}_vatsim_adr" min="0">
                                <button class="btn btn-sm btn-light" onclick="HourlyRates.quickFill('${icao}', 'vatsim_adr')" title="Fill all"><i class="fas fa-fill"></i></button>
                            </div>
                            <div class="quick-fill-group">
                                <label>RW AAR:</label>
                                <input type="number" class="form-control form-control-sm" id="qf_${icao}_rw_aar" min="0">
                                <button class="btn btn-sm btn-cyan" onclick="HourlyRates.quickFill('${icao}', 'rw_aar')" title="Fill all"><i class="fas fa-fill"></i></button>
                            </div>
                            <div class="quick-fill-group">
                                <label>RW ADR:</label>
                                <input type="number" class="form-control form-control-sm" id="qf_${icao}_rw_adr" min="0">
                                <button class="btn btn-sm btn-cyan" onclick="HourlyRates.quickFill('${icao}', 'rw_adr')" title="Fill all"><i class="fas fa-fill"></i></button>
                            </div>
                        </div>
                        
                        <!-- Hourly Data Table -->
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered hourly-rates-table" id="table_${icao}">
                                <thead>
                                    <tr>
                                        <th rowspan="2" class="align-middle time-header">REL</th>
                                        <th rowspan="2" class="align-middle time-header">DD/HHMM</th>
                                        <th colspan="2" class="statsim-header">STATSIM</th>
                                        <th colspan="2" class="vatsim-header">VATSIM</th>
                                        <th colspan="2" class="rw-header">RW</th>
                                    </tr>
                                    <tr>
                                        <th class="statsim-col col-arr">ARR</th>
                                        <th class="statsim-col col-dep">DEP</th>
                                        <th class="vatsim-col">AAR</th>
                                        <th class="vatsim-col">ADR</th>
                                        <th class="rw-col">AAR</th>
                                        <th class="rw-col">ADR</th>
                                    </tr>
                                </thead>
                                <tbody>
                `;
                
                airport.hours.forEach((hour, idx) => {
                    const relClass = hour.relativeHour === 'H+0' ? 'rel-zero' : '';
                    html += `
                        <tr class="${relClass}">
                            <td class="rel-cell">${hour.relativeHour}</td>
                            <td class="time-cell">${hour.tableTime}</td>
                            <td class="statsim-col col-arr text-center">${hour.statsim_arr}</td>
                            <td class="statsim-col col-dep text-center">${hour.statsim_dep}</td>
                            <td class="vatsim-col text-center">
                                <input type="number" class="form-control form-control-sm rate-input" 
                                       data-icao="${icao}" data-idx="${idx}" data-field="vatsim_aar"
                                       value="${hour.vatsim_aar}" min="0" placeholder="-">
                            </td>
                            <td class="vatsim-col text-center">
                                <input type="number" class="form-control form-control-sm rate-input" 
                                       data-icao="${icao}" data-idx="${idx}" data-field="vatsim_adr"
                                       value="${hour.vatsim_adr}" min="0" placeholder="-">
                            </td>
                            <td class="rw-col text-center">
                                <input type="number" class="form-control form-control-sm rate-input" 
                                       data-icao="${icao}" data-idx="${idx}" data-field="rw_aar"
                                       value="${hour.rw_aar}" min="0" placeholder="-">
                            </td>
                            <td class="rw-col text-center">
                                <input type="number" class="form-control form-control-sm rate-input" 
                                       data-icao="${icao}" data-idx="${idx}" data-field="rw_adr"
                                       value="${hour.rw_adr}" min="0" placeholder="-">
                            </td>
                        </tr>
                    `;
                });
                
                html += `
                                </tbody>
                                <tfoot>
                                    <tr class="totals-row">
                                        <td colspan="2" class="text-right"><strong>TOTALS:</strong></td>
                                        <td class="statsim-col col-arr text-center">${airport.totalArr}</td>
                                        <td class="statsim-col col-dep text-center">${airport.totalDep}</td>
                                        <td class="vatsim-col text-center" id="total_${icao}_vatsim_aar">-</td>
                                        <td class="vatsim-col text-center" id="total_${icao}_vatsim_adr">-</td>
                                        <td class="rw-col text-center" id="total_${icao}_rw_aar">-</td>
                                        <td class="rw-col text-center" id="total_${icao}_rw_adr">-</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                `;
            });
            
            container.innerHTML = html;
            document.getElementById('rates_actions').style.display = 'flex';
            
            container.querySelectorAll('.rate-input').forEach(input => {
                input.addEventListener('change', (e) => this.onInputChange(e));
                input.addEventListener('input', (e) => this.onInputChange(e));
            });
            
            Object.keys(this.airports).forEach(icao => {
                this.createChart(icao);
            });
        },
        
        /**
         * Create FSM-style demand bar graph
         * - Arrivals on LEFT, Departures on RIGHT (between gridlines)
         * - Title: MM/DD/YYYY HH:MMZ format
         * - Vertical HHMM labels at gridlines
         * - Bars positioned between gridlines (after label)
         */
        createChart: function(icao) {
            const canvas = document.getElementById(`chart_${icao}`);
            if (!canvas) return;
            
            const airport = this.airports[icao];
            const firstHour = airport.hours.length > 0 ? airport.hours[0] : null;
            
            // Format: MM/DD/YYYY HH:MMZ
            const dateStr = firstHour ? this.formatChartDate(firstHour.date) : '';
            const timeStr = firstHour ? this.formatChartTime(firstHour.time) : '';
            
            // HHMM format labels (e.g., "0100", "0200")
            const labels = airport.hours.map(h => h.time.replace(':', ''));
            
            // Data - Arrivals first (left bar), Departures second (right bar)
            const arrData = airport.hours.map(h => h.statsim_arr);
            const depData = airport.hours.map(h => h.statsim_dep);
            const vatsimAarData = airport.hours.map(h => h.vatsim_aar !== '' ? parseInt(h.vatsim_aar) : null);
            const vatsimAdrData = airport.hours.map(h => h.vatsim_adr !== '' ? parseInt(h.vatsim_adr) : null);
            const rwAarData = airport.hours.map(h => h.rw_aar !== '' ? parseInt(h.rw_aar) : null);
            const rwAdrData = airport.hours.map(h => h.rw_adr !== '' ? parseInt(h.rw_adr) : null);
            
            // FSM-style font configuration
            const fsmFont = {
                family: "'Segoe UI', Tahoma, Arial, sans-serif",
                size: 10,
                weight: 'bold'
            };
            
            this.charts[icao] = new Chart(canvas, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            // Arrivals = Bright Red (LEFT bar)
                            label: 'Arrivals',
                            data: arrData,
                            backgroundColor: '#ff0000',
                            borderColor: '#cc0000',
                            borderWidth: 1,
                            barPercentage: 0.85,
                            categoryPercentage: 0.9,
                            order: 2
                        },
                        {
                            // Departures = Bright Green (RIGHT bar)
                            label: 'Departures',
                            data: depData,
                            backgroundColor: '#00ff00',
                            borderColor: '#00cc00',
                            borderWidth: 1,
                            barPercentage: 0.85,
                            categoryPercentage: 0.9,
                            order: 2
                        },
                        {
                            // VATSIM AAR = White STEPPED line (THICK)
                            label: 'VATSIM AAR',
                            data: vatsimAarData,
                            type: 'line',
                            borderColor: '#ffffff',
                            backgroundColor: 'transparent',
                            borderWidth: 3,
                            stepped: 'before',
                            pointRadius: 0,
                            pointHoverRadius: 4,
                            pointBackgroundColor: '#ffffff',
                            pointBorderColor: '#000000',
                            fill: false,
                            spanGaps: false,
                            order: 1
                        },
                        {
                            // VATSIM ADR = White STEPPED dashed line (THICK)
                            label: 'VATSIM ADR',
                            data: vatsimAdrData,
                            type: 'line',
                            borderColor: '#ffffff',
                            backgroundColor: 'transparent',
                            borderWidth: 3,
                            borderDash: [6, 3],
                            stepped: 'before',
                            pointRadius: 0,
                            pointHoverRadius: 4,
                            pointBackgroundColor: '#ffffff',
                            pointBorderColor: '#000000',
                            fill: false,
                            spanGaps: false,
                            order: 1
                        },
                        {
                            // RW AAR = Cyan STEPPED line (THICK)
                            label: 'RW AAR',
                            data: rwAarData,
                            type: 'line',
                            borderColor: '#00ffff',
                            backgroundColor: 'transparent',
                            borderWidth: 3,
                            stepped: 'before',
                            pointRadius: 0,
                            pointHoverRadius: 4,
                            pointBackgroundColor: '#00ffff',
                            pointBorderColor: '#000000',
                            fill: false,
                            spanGaps: false,
                            order: 1
                        },
                        {
                            // RW ADR = Cyan STEPPED dashed line (THICK)
                            label: 'RW ADR',
                            data: rwAdrData,
                            type: 'line',
                            borderColor: '#00ffff',
                            backgroundColor: 'transparent',
                            borderWidth: 3,
                            borderDash: [6, 3],
                            stepped: 'before',
                            pointRadius: 0,
                            pointHoverRadius: 4,
                            pointBackgroundColor: '#00ffff',
                            pointBorderColor: '#000000',
                            fill: false,
                            spanGaps: false,
                            order: 1
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    layout: {
                        padding: {
                            left: 5,
                            right: 10,
                            top: 5,
                            bottom: 25  // Extra space for legend
                        }
                    },
                    interaction: {
                        mode: 'index',
                        intersect: false
                    },
                    plugins: {
                        // Title rendered ON canvas: MM/DD/YYYY HH:MMZ
                        title: {
                            display: true,
                            text: `${icao}    ${dateStr}    ${timeStr}`,
                            color: '#000000',
                            font: {
                                family: "'Segoe UI', Tahoma, Arial, sans-serif",
                                size: 14,
                                weight: 'bold'
                            },
                            padding: { top: 4, bottom: 8 }
                        },
                        legend: {
                            display: false  // We use custom canvas legend plugin
                        },
                        tooltip: {
                            backgroundColor: 'rgba(0,0,0,0.9)',
                            titleColor: '#fff',
                            bodyColor: '#fff',
                            titleFont: { ...fsmFont, size: 12 },
                            bodyFont: { ...fsmFont, size: 11, weight: 'normal' },
                            padding: 10,
                            callbacks: {
                                label: function(context) {
                                    if (context.raw === null) return null;
                                    return `${context.dataset.label}: ${context.raw}`;
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            // Bars fill space between gridlines
                            offset: true,
                            grid: {
                                color: '#666666',
                                lineWidth: 1,
                                offset: true,  // Gridlines at category BOUNDARIES (edges)
                                drawOnChartArea: true
                            },
                            border: {
                                color: '#000000',
                                width: 2
                            },
                            ticks: {
                                color: '#000000',
                                font: { ...fsmFont, size: 9 },
                                maxRotation: 90,
                                minRotation: 90,
                                padding: 2
                            },
                            title: {
                                display: true,
                                text: 'Time in 60-Minute Increments',
                                color: '#000000',
                                font: { ...fsmFont, size: 10 },
                                padding: { top: 2 }
                            }
                        },
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: '#666666',
                                lineWidth: 1
                            },
                            border: {
                                color: '#000000',
                                width: 2
                            },
                            ticks: {
                                color: '#000000',
                                font: { ...fsmFont, size: 9 },
                                stepSize: 10,
                                padding: 4
                            },
                            title: {
                                display: true,
                                text: 'Demand',
                                color: '#000000',
                                font: { ...fsmFont, size: 10 },
                                padding: { bottom: 2 }
                            }
                        }
                    }
                }
            });
        },
        
        updateChart: function(icao) {
            const chart = this.charts[icao];
            if (!chart) return;
            
            const airport = this.airports[icao];
            
            chart.data.datasets[2].data = airport.hours.map(h => h.vatsim_aar !== '' ? parseInt(h.vatsim_aar) : null);
            chart.data.datasets[3].data = airport.hours.map(h => h.vatsim_adr !== '' ? parseInt(h.vatsim_adr) : null);
            chart.data.datasets[4].data = airport.hours.map(h => h.rw_aar !== '' ? parseInt(h.rw_aar) : null);
            chart.data.datasets[5].data = airport.hours.map(h => h.rw_adr !== '' ? parseInt(h.rw_adr) : null);
            
            chart.update('none');
        },
        
        // =====================================================================
        // Export Functions with Clipboard Support
        // =====================================================================
        
        copyToClipboard: async function(icao, format) {
            try {
                if (format === 'png') {
                    const canvas = document.getElementById(`chart_${icao}`);
                    if (!canvas) return;
                    
                    const tempCanvas = document.createElement('canvas');
                    const ctx = tempCanvas.getContext('2d');
                    tempCanvas.width = canvas.width;
                    tempCanvas.height = canvas.height;
                    ctx.fillStyle = '#c0c0c0';
                    ctx.fillRect(0, 0, tempCanvas.width, tempCanvas.height);
                    ctx.drawImage(canvas, 0, 0);
                    
                    tempCanvas.toBlob(async (blob) => {
                        try {
                            await navigator.clipboard.write([
                                new ClipboardItem({ 'image/png': blob })
                            ]);
                            this.showCopySuccess('PNG copied to clipboard!');
                        } catch (err) {
                            console.error('Clipboard write failed:', err);
                            alert('Could not copy to clipboard. Try the download button instead.');
                        }
                    }, 'image/png');
                    
                } else if (format === 'csv') {
                    const csv = this.generateChartCSV(icao);
                    await navigator.clipboard.writeText(csv);
                    this.showCopySuccess('CSV copied to clipboard!');
                    
                } else if (format === 'svg') {
                    const svg = this.generateSVG(icao);
                    await navigator.clipboard.writeText(svg);
                    this.showCopySuccess('SVG copied to clipboard!');
                }
            } catch (err) {
                console.error('Copy failed:', err);
                alert('Could not copy to clipboard: ' + err.message);
            }
        },
        
        showCopySuccess: function(message) {
            const toast = document.createElement('div');
            toast.innerHTML = `<i class="fas fa-check"></i> ${message}`;
            toast.style.cssText = 'position:fixed;bottom:20px;right:20px;background:#28a745;color:#fff;padding:10px 20px;border-radius:4px;z-index:9999;font-size:14px;';
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), 2000);
        },
        
        exportPNG: function(icao) {
            const canvas = document.getElementById(`chart_${icao}`);
            if (!canvas) return;
            
            const tempCanvas = document.createElement('canvas');
            const ctx = tempCanvas.getContext('2d');
            tempCanvas.width = canvas.width;
            tempCanvas.height = canvas.height;
            ctx.fillStyle = '#c0c0c0';
            ctx.fillRect(0, 0, tempCanvas.width, tempCanvas.height);
            ctx.drawImage(canvas, 0, 0);
            
            const link = document.createElement('a');
            link.download = `${icao}_demand_chart.png`;
            link.href = tempCanvas.toDataURL('image/png');
            link.click();
        },
        
        generateSVG: function(icao) {
            const chart = this.charts[icao];
            if (!chart) return '';
            
            const airport = this.airports[icao];
            const canvas = document.getElementById(`chart_${icao}`);
            const width = canvas.width || 800;
            const height = canvas.height || 400;
            
            const firstHour = airport.hours.length > 0 ? airport.hours[0] : null;
            const dateStr = firstHour ? this.formatChartDate(firstHour.date) : '';
            const timeStr = firstHour ? this.formatChartTime(firstHour.time) : '';
            
            const labels = airport.hours.map(h => h.time.replace(':', ''));
            const arrData = airport.hours.map(h => h.statsim_arr);
            const depData = airport.hours.map(h => h.statsim_dep);
            const maxVal = Math.max(...arrData, ...depData, 10) * 1.15;
            
            const margin = { top: 40, right: 20, bottom: 80, left: 50 };
            const chartWidth = width - margin.left - margin.right;
            const chartHeight = height - margin.top - margin.bottom;
            const gap = chartWidth / labels.length;
            const barWidth = gap * 0.38;
            
            let svg = `<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}" viewBox="0 0 ${width} ${height}">
  <defs>
    <style>
      .axis-label { font: bold 10px 'Segoe UI', Tahoma, Arial, sans-serif; fill: #000; }
      .tick-label { font: bold 9px 'Segoe UI', Tahoma, Arial, sans-serif; fill: #000; }
      .title { font: bold 14px 'Segoe UI', Tahoma, Arial, sans-serif; fill: #000; }
      .legend-text { font: bold 9px 'Segoe UI', Tahoma, Arial, sans-serif; fill: #000; }
    </style>
  </defs>
  <rect width="100%" height="100%" fill="#c0c0c0"/>
  
  <!-- Title: MM/DD/YYYY HH:MMZ -->
  <text x="${width/2}" y="22" text-anchor="middle" class="title">${icao}    ${dateStr}    ${timeStr}</text>
  
  <g transform="translate(${margin.left}, ${margin.top})">
`;
            
            // Grid lines at labels (period start)
            labels.forEach((label, i) => {
                const x = i * gap;
                svg += `    <line x1="${x}" y1="0" x2="${x}" y2="${chartHeight}" stroke="#666" stroke-width="1"/>
`;
            });
            // Final gridline
            svg += `    <line x1="${chartWidth}" y1="0" x2="${chartWidth}" y2="${chartHeight}" stroke="#666" stroke-width="1"/>
`;
            
            // Horizontal grid lines
            for (let i = 0; i <= 5; i++) {
                const y = (chartHeight / 5) * i;
                svg += `    <line x1="0" y1="${y}" x2="${chartWidth}" y2="${y}" stroke="#666" stroke-width="1"/>
`;
            }
            
            // Bars - positioned between gridlines (Arrivals LEFT, Departures RIGHT)
            labels.forEach((label, i) => {
                const x = i * gap;
                const arrH = (arrData[i] / maxVal) * chartHeight;
                const depH = (depData[i] / maxVal) * chartHeight;
                
                // Arrivals bar (LEFT, after gridline)
                svg += `    <rect x="${x + gap * 0.05}" y="${chartHeight - arrH}" width="${barWidth}" height="${arrH}" fill="#ff0000" stroke="#cc0000"/>
`;
                // Departures bar (RIGHT)
                svg += `    <rect x="${x + gap * 0.05 + barWidth + 2}" y="${chartHeight - depH}" width="${barWidth}" height="${depH}" fill="#00ff00" stroke="#00cc00"/>
`;
                // Vertical time labels at gridline
                svg += `    <text x="${x}" y="${chartHeight + 8}" transform="rotate(90, ${x}, ${chartHeight + 8})" class="tick-label">${label}</text>
`;
            });
            
            // Axes
            svg += `    <line x1="0" y1="${chartHeight}" x2="${chartWidth}" y2="${chartHeight}" stroke="#000" stroke-width="2"/>
    <line x1="0" y1="0" x2="0" y2="${chartHeight}" stroke="#000" stroke-width="2"/>
`;
            
            // Y-axis ticks
            for (let i = 0; i <= 5; i++) {
                const y = chartHeight - (chartHeight / 5) * i;
                const val = Math.round((maxVal / 5) * i);
                svg += `    <text x="-8" y="${y + 3}" text-anchor="end" class="tick-label">${val}</text>
`;
            }
            
            // Axis labels
            svg += `    <text x="${chartWidth/2}" y="${chartHeight + 55}" text-anchor="middle" class="axis-label">Time in 60-Minute Increments</text>
    <text x="-30" y="${chartHeight/2}" text-anchor="middle" transform="rotate(-90, -30, ${chartHeight/2})" class="axis-label">Demand</text>
`;
            
            svg += `  </g>
  
  <!-- Legend -->
  <rect x="${margin.left}" y="${height - 20}" width="${chartWidth}" height="18" fill="#d0d0d0" stroke="#808080"/>
  <rect x="${margin.left + 5}" y="${height - 16}" width="10" height="10" fill="#ff0000" stroke="#000"/>
  <text x="${margin.left + 18}" y="${height - 8}" class="legend-text">Arrivals</text>
  <rect x="${margin.left + 65}" y="${height - 16}" width="10" height="10" fill="#00ff00" stroke="#000"/>
  <text x="${margin.left + 78}" y="${height - 8}" class="legend-text">Departures</text>
  <line x1="${margin.left + 145}" y1="${height - 11}" x2="${margin.left + 160}" y2="${height - 11}" stroke="#fff" stroke-width="3"/>
  <text x="${margin.left + 163}" y="${height - 8}" class="legend-text">VATSIM AAR</text>
  <line x1="${margin.left + 230}" y1="${height - 11}" x2="${margin.left + 245}" y2="${height - 11}" stroke="#fff" stroke-width="3" stroke-dasharray="4,2"/>
  <text x="${margin.left + 248}" y="${height - 8}" class="legend-text">VATSIM ADR</text>
  <line x1="${margin.left + 315}" y1="${height - 11}" x2="${margin.left + 330}" y2="${height - 11}" stroke="#0ff" stroke-width="3"/>
  <text x="${margin.left + 333}" y="${height - 8}" class="legend-text">RW AAR</text>
  <line x1="${margin.left + 380}" y1="${height - 11}" x2="${margin.left + 395}" y2="${height - 11}" stroke="#0ff" stroke-width="3" stroke-dasharray="4,2"/>
  <text x="${margin.left + 398}" y="${height - 8}" class="legend-text">RW ADR</text>
</svg>`;
            
            return svg;
        },
        
        exportSVG: function(icao) {
            const svg = this.generateSVG(icao);
            if (!svg) return;
            
            const blob = new Blob([svg], { type: 'image/svg+xml' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.download = `${icao}_demand_chart.svg`;
            link.href = url;
            link.click();
            URL.revokeObjectURL(url);
        },
        
        generateChartCSV: function(icao) {
            const airport = this.airports[icao];
            if (!airport) return '';
            
            let csv = `Relative,DD/HHMM,Statsim Arrivals,Statsim Departures,VATSIM AAR,VATSIM ADR,RW AAR,RW ADR\n`;
            
            airport.hours.forEach(h => {
                csv += `${h.relativeHour},${h.tableTime},${h.statsim_arr},${h.statsim_dep},${h.vatsim_aar || ''},${h.vatsim_adr || ''},${h.rw_aar || ''},${h.rw_adr || ''}\n`;
            });
            
            return csv;
        },
        
        exportChartCSV: function(icao) {
            const csv = this.generateChartCSV(icao);
            if (!csv) return;
            
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.download = `${icao}_demand_data.csv`;
            link.href = url;
            link.click();
            URL.revokeObjectURL(url);
        },
        
        // =====================================================================
        // Input Handling
        // =====================================================================
        
        onInputChange: function(e) {
            const icao = e.target.dataset.icao;
            const idx = parseInt(e.target.dataset.idx);
            const field = e.target.dataset.field;
            const value = e.target.value;
            
            if (this.airports[icao] && this.airports[icao].hours[idx]) {
                this.airports[icao].hours[idx][field] = value;
            }
            
            this.updateTotals(icao);
            this.updateChart(icao);
        },
        
        updateTotals: function(icao) {
            const airport = this.airports[icao];
            if (!airport) return;
            
            const totals = { vatsim_aar: 0, vatsim_adr: 0, rw_aar: 0, rw_adr: 0 };
            const hasTotals = { vatsim_aar: false, vatsim_adr: false, rw_aar: false, rw_adr: false };
            
            airport.hours.forEach(h => {
                ['vatsim_aar', 'vatsim_adr', 'rw_aar', 'rw_adr'].forEach(field => {
                    if (h[field] !== '' && !isNaN(parseInt(h[field]))) {
                        totals[field] += parseInt(h[field]);
                        hasTotals[field] = true;
                    }
                });
            });
            
            ['vatsim_aar', 'vatsim_adr', 'rw_aar', 'rw_adr'].forEach(field => {
                const el = document.getElementById(`total_${icao}_${field}`);
                if (el) el.textContent = hasTotals[field] ? totals[field] : '-';
            });
        },
        
        quickFill: function(icao, field) {
            const qfInput = document.getElementById(`qf_${icao}_${field}`);
            const value = qfInput.value;
            
            if (value === '') {
                alert('Enter a value to fill first.');
                return;
            }
            
            const airport = this.airports[icao];
            if (!airport) return;
            
            airport.hours.forEach((hour, idx) => {
                hour[field] = value;
                const input = document.querySelector(`input[data-icao="${icao}"][data-idx="${idx}"][data-field="${field}"]`);
                if (input) input.value = value;
            });
            
            this.updateTotals(icao);
            this.updateChart(icao);
        },
        
        clearAll: function() {
            if (!confirm('Clear all rate values?')) return;
            
            Object.keys(this.airports).forEach(icao => {
                this.airports[icao].hours.forEach(hour => {
                    hour.vatsim_aar = '';
                    hour.vatsim_adr = '';
                    hour.rw_aar = '';
                    hour.rw_adr = '';
                });
            });
            
            this.renderAll();
        },
        
        saveRates: function() {
            const saveBtn = document.querySelector('#rates_actions .btn-success');
            const originalText = saveBtn.innerHTML;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            saveBtn.disabled = true;
            
            const airports = [];
            Object.keys(this.airports).forEach(icao => {
                const airport = this.airports[icao];
                const totals = { 
                    statsim_arr: airport.totalArr, 
                    statsim_dep: airport.totalDep,
                    vatsim_aar: 0, vatsim_adr: 0, rw_aar: 0, rw_adr: 0
                };
                
                const hours = airport.hours.map(h => {
                    ['vatsim_aar', 'vatsim_adr', 'rw_aar', 'rw_adr'].forEach(f => {
                        if (h[f] !== '' && !isNaN(parseInt(h[f]))) {
                            totals[f] += parseInt(h[f]);
                        }
                    });
                    
                    return {
                        timestamp: h.timestamp,
                        date: h.date,
                        time: h.time,
                        statsim_arr: h.statsim_arr,
                        statsim_dep: h.statsim_dep,
                        vatsim_aar: h.vatsim_aar !== '' ? parseInt(h.vatsim_aar) : null,
                        vatsim_adr: h.vatsim_adr !== '' ? parseInt(h.vatsim_adr) : null,
                        rw_aar: h.rw_aar !== '' ? parseInt(h.rw_aar) : null,
                        rw_adr: h.rw_adr !== '' ? parseInt(h.rw_adr) : null
                    };
                });
                
                airports.push({ icao, name: airport.name, totals, hours });
            });
            
            fetch('api/statsim/save_rates.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    plan_id: planId,
                    statsim_url: this.statsimData?.statsim_url || '',
                    airports
                })
            })
            .then(r => r.json())
            .then(data => {
                saveBtn.innerHTML = originalText;
                saveBtn.disabled = false;
                alert(data.success ? 'Rates saved successfully to VATSIM_ADL!' : 'Error: ' + (data.message || 'Unknown error'));
            })
            .catch(err => {
                saveBtn.innerHTML = originalText;
                saveBtn.disabled = false;
                alert('Error saving rates: ' + err.message);
            });
        },
        
        exportCSV: function() {
            if (Object.keys(this.airports).length === 0) {
                alert('No data to export.');
                return;
            }
            
            let csv = 'Airport,Relative,DD/HHMM,Date,Time,Statsim Arr,Statsim Dep,VATSIM AAR,VATSIM ADR,RW AAR,RW ADR\n';
            
            Object.keys(this.airports).forEach(icao => {
                const airport = this.airports[icao];
                airport.hours.forEach(h => {
                    csv += `${icao},${h.relativeHour},${h.tableTime},${h.date},${h.time},${h.statsim_arr},${h.statsim_dep},${h.vatsim_aar || ''},${h.vatsim_adr || ''},${h.rw_aar || ''},${h.rw_adr || ''}\n`;
                });
            });
            
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.download = `hourly_rates_${planId || 'export'}.csv`;
            link.href = url;
            link.click();
            URL.revokeObjectURL(url);
        }
    };
    
    window.Statsim = Statsim;
    window.HourlyRates = HourlyRates;
    
    document.addEventListener('DOMContentLoaded', () => {
        planId = getPlanId();
        Statsim.init();
    });
})();
