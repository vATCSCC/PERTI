/**
 * PERTI Demand Visualization
 * Core client-side logic for demand charts and filtering
 */

// ============================================================================
// Utility Functions
// ============================================================================

/**
 * Format config name for display
 * Uses explicit runway parameters when provided, otherwise parses config name
 */
window.formatConfigName = function(configName, arrRunways, depRunways) {
    // If explicit runway parameters are provided, use them directly
    if (arrRunways || depRunways) {
        const arrPart = (arrRunways || '--').replace(/\//g, ' ');
        const depPart = (depRunways || '--').replace(/\//g, ' ');
        return `ARR: ${arrPart} | DEP: ${depPart}`;
    }

    if (!configName) return '--';

    // Check if it's a simple descriptive name
    const flowKeywords = ['Flow', 'Config', 'Standard', 'Primary', 'Secondary', 'Alternate'];
    const isSimpleName = !configName.includes('/') ||
        /^[A-Za-z]+ Flow$/i.test(configName) ||
        /^(North|South|East|West|Mixed|Balanced)/i.test(configName) ||
        flowKeywords.some(kw => configName.toLowerCase().includes(kw.toLowerCase()));

    if (isSimpleName) {
        return configName;
    }

    // Parse "ARR / DEP" pattern as fallback
    const match = configName.match(/^(.+?)\s*\/\s*(.+)$/);
    if (match) {
        const arrPart = match[1].trim().replace(/\//g, ' ');
        const depPart = match[2].trim().replace(/\//g, ' ');
        return `ARR: ${arrPart} | DEP: ${depPart}`;
    }

    return configName;
};

/**
 * Calculate contrasting text color for a given background color
 * Returns white for dark backgrounds, black for light backgrounds
 * @param {string} hexColor - Background color in hex format (e.g., '#FF0000' or 'FF0000')
 * @returns {string} '#000000' or '#ffffff'
 */
window.getContrastTextColor = function(hexColor) {
    const hex = hexColor.replace('#', '');
    const r = parseInt(hex.substr(0, 2), 16) / 255;
    const g = parseInt(hex.substr(2, 2), 16) / 255;
    const b = parseInt(hex.substr(4, 2), 16) / 255;
    // Relative luminance formula (ITU-R BT.601)
    const luminance = 0.299 * r + 0.587 * g + 0.114 * b;
    return luminance > 0.5 ? '#000000' : '#ffffff';
};

// ============================================================================
// DemandChartCore - Reusable chart rendering functions
// Used by both demand.php and gdt.php
// ============================================================================
window.DemandChartCore = (function() {
    'use strict';

    // Phase colors - use shared config from phase-colors.js if available
    const PHASE_COLORS = (typeof window.PHASE_COLORS !== 'undefined') ? window.PHASE_COLORS : {
        // Standard flight phases
        'arrived': '#1a1a1a',
        'disconnected': '#f97316',
        'descending': '#991b1b',
        'enroute': '#dc2626',
        'departed': '#f87171',
        'taxiing': '#22c55e',
        'prefile': '#3b82f6',
        // Ground Stop statuses (yellow spectrum)
        'actual_gs': '#eab308',
        'simulated_gs': '#fef08a',
        'proposed_gs': '#ca8a04',
        'gs': '#eab308',
        // Ground Delay Program statuses (brown spectrum)
        'actual_gdp': '#92400e',
        'simulated_gdp': '#d4a574',
        'proposed_gdp': '#78350f',
        'gdp': '#92400e',
        // Exempt and uncontrolled
        'exempt': '#6b7280',
        'uncontrolled': '#94a3b8',
        // Unknown/other
        'unknown': '#9333ea'
    };

    // Phase labels - use shared config if available
    const PHASE_LABELS = (typeof window.PHASE_LABELS !== 'undefined') ? window.PHASE_LABELS : {
        'arrived': 'Arrived',
        'disconnected': 'Disconnected',
        'descending': 'Descending',
        'enroute': 'Enroute',
        'departed': 'Departed',
        'taxiing': 'Taxiing',
        'prefile': 'Prefile',
        'actual_gs': 'GS (EDCT)',
        'simulated_gs': 'GS (Simulated)',
        'proposed_gs': 'GS (Proposed)',
        'gs': 'Ground Stop',
        'actual_gdp': 'GDP (EDCT)',
        'simulated_gdp': 'GDP (Simulated)',
        'proposed_gdp': 'GDP (Proposed)',
        'gdp': 'GDP',
        'exempt': 'Exempt',
        'uncontrolled': 'Uncontrolled',
        'unknown': 'Unknown'
    };

    // Phase stacking order (bottom to top) - use shared config if available
    const PHASE_ORDER = (typeof window.PHASE_STACK_ORDER !== 'undefined') ? window.PHASE_STACK_ORDER : 
        ['arrived', 'disconnected', 'descending', 'enroute', 'departed', 'taxiing', 'prefile', 
         'uncontrolled', 'exempt', 'actual_gs', 'simulated_gs', 'proposed_gs', 
         'actual_gdp', 'simulated_gdp', 'proposed_gdp', 'unknown'];

    /**
     * Get granularity in minutes
     * @param {string} granularity - 'hourly', '30min', or '15min'
     * @returns {number} Minutes per bin
     */
    function getGranularityMinutes(granularity) {
        switch (granularity) {
            case '15min': return 15;
            case '30min': return 30;
            case 'hourly':
            default: return 60;
        }
    }

    /**
     * Generate all time bins for a time range
     * @param {string} granularity - 'hourly', '30min', or '15min'
     * @param {number} startHours - Hours before now (negative)
     * @param {number} endHours - Hours after now (positive)
     * @returns {Array} Array of ISO time strings
     */
    function generateAllTimeBins(granularity, startHours, endHours) {
        const now = new Date();
        const start = new Date(now.getTime() + startHours * 60 * 60 * 1000);
        const end = new Date(now.getTime() + endHours * 60 * 60 * 1000);
        const intervalMinutes = getGranularityMinutes(granularity);

        // Round start down to nearest interval
        const startMinutes = start.getUTCMinutes();
        const roundedStartMinutes = Math.floor(startMinutes / intervalMinutes) * intervalMinutes;
        start.setUTCMinutes(roundedStartMinutes, 0, 0);

        // Round end up to nearest interval
        const endMinutes = end.getUTCMinutes();
        const roundedEndMinutes = Math.ceil(endMinutes / intervalMinutes) * intervalMinutes;
        if (roundedEndMinutes >= 60) {
            end.setUTCHours(end.getUTCHours() + 1);
            end.setUTCMinutes(0, 0, 0);
        } else {
            end.setUTCMinutes(roundedEndMinutes, 0, 0);
        }

        const timeBins = [];
        const current = new Date(start);

        while (current <= end) {
            // Format without milliseconds to match PHP's format
            timeBins.push(current.toISOString().replace('.000Z', 'Z'));
            current.setUTCMinutes(current.getUTCMinutes() + intervalMinutes);
        }

        return timeBins;
    }

    /**
     * Normalize a time bin string (remove milliseconds)
     * @param {string} bin - ISO time string
     * @returns {string} Normalized time string
     */
    function normalizeTimeBin(bin) {
        const d = new Date(bin);
        d.setUTCSeconds(0, 0);
        return d.toISOString().replace('.000Z', 'Z');
    }

    /**
     * Build a series for a specific phase - TBFM/FSM style with TRUE TIME AXIS
     * @param {string} name - Series name for legend
     * @param {Array} timeBins - Array of ISO time bin strings
     * @param {Object} dataByBin - Lookup map of breakdown data by time bin
     * @param {string} phase - Phase name (arrived, enroute, etc.)
     * @param {string} type - 'arrivals' or 'departures'
     * @param {string} viewDirection - 'both', 'arr', or 'dep' - controls bar width
     * @param {string} granularity - 'hourly', '30min', or '15min'
     * @returns {Object} ECharts series configuration
     */
    function buildPhaseSeriesTimeAxis(name, timeBins, dataByBin, phase, type, viewDirection, granularity) {
        const intervalMs = getGranularityMinutes(granularity) * 60 * 1000;
        const halfInterval = intervalMs / 2;

        // Build data as [timestamp, value] pairs for time axis
        const data = timeBins.map(bin => {
            const normalizedBin = normalizeTimeBin(bin);
            const breakdown = dataByBin[normalizedBin] || dataByBin[bin];
            const value = breakdown ? (breakdown[phase] || 0) : 0;
            return [new Date(bin).getTime() + halfInterval, value];
        });

        const color = PHASE_COLORS[phase] || '#999';
        const isSingleDirection = viewDirection === 'arr' || viewDirection === 'dep';
        const barWidth = isSingleDirection ? '70%' : '35%';

        const seriesConfig = {
            name: name,
            type: 'bar',
            stack: type,
            barWidth: barWidth,
            barGap: '10%',
            emphasis: {
                focus: 'series',
                itemStyle: {
                    shadowBlur: 2,
                    shadowColor: 'rgba(0,0,0,0.2)'
                }
            },
            itemStyle: {
                color: color,
                borderColor: type === 'departures' ? 'rgba(255,255,255,0.5)' : 'transparent',
                borderWidth: type === 'departures' ? 1 : 0
            },
            data: data
        };

        // Add diagonal hatching pattern for departures (FSM/TBFM style)
        if (type === 'departures') {
            seriesConfig.itemStyle.decal = {
                symbol: 'rect',
                symbolSize: 1,
                rotation: Math.PI / 4,
                color: 'rgba(255,255,255,0.4)',
                dashArrayX: [1, 0],
                dashArrayY: [3, 5]
            };
        }

        return seriesConfig;
    }

    /**
     * Get current time markLine data item - FAA AADC style
     * @returns {Object} ECharts markLine data item
     */
    function getCurrentTimeMarkLineForTimeAxis() {
        const now = new Date();
        const hours = now.getUTCHours().toString().padStart(2, '0');
        const minutes = now.getUTCMinutes().toString().padStart(2, '0');
        const markerColor = '#f59e0b';

        return {
            xAxis: now.getTime(),
            lineStyle: {
                color: markerColor,
                width: 2,
                type: 'solid'
            },
            label: {
                show: true,
                formatter: `${hours}${minutes}Z`,
                position: 'end',
                color: markerColor,
                fontWeight: 'bold',
                fontSize: 10,
                fontFamily: '"Inconsolata", monospace',
                backgroundColor: 'rgba(255,255,255,0.95)',
                padding: [2, 6],
                borderRadius: 2,
                borderColor: markerColor,
                borderWidth: 1
            }
        };
    }

    /**
     * Build rate mark lines for the demand chart
     * @param {Object} rateData - Rate data from API
     * @param {string} direction - 'both', 'arr', or 'dep'
     * @param {boolean} showRateLines - Whether to show rate lines
     * @returns {Array} Array of ECharts markLine data items
     */
    function buildRateMarkLinesForChart(rateData, direction, showRateLines) {
        if (!showRateLines || !rateData || !rateData.rates) {
            return [];
        }

        const rates = rateData.rates;
        const lines = [];

        // Use config if available, otherwise use defaults
        const cfg = (typeof window.RATE_LINE_CONFIG !== 'undefined') ? window.RATE_LINE_CONFIG : {
            active: { vatsim: { color: '#000000' }, rw: { color: '#00FFFF' } },
            suggested: { vatsim: { color: '#6b7280' }, rw: { color: '#0d9488' } },
            custom: { vatsim: { color: '#000000' }, rw: { color: '#00FFFF' } },
            lineStyle: {
                aar: { type: 'solid', width: 2 },
                adr: { type: 'dashed', width: 2 },
                aar_custom: { type: 'dotted', width: 2 },
                adr_custom: { type: 'dotted', width: 2 }
            },
            label: { position: 'end', fontSize: 10, fontWeight: 'bold' }
        };

        // Determine style: custom (override), suggested, or active
        const isCustom = rateData.has_override;
        const styleKey = isCustom ? 'custom' : (rateData.is_suggested ? 'suggested' : 'active');

        // Track label index for vertical stacking
        let labelIndex = 0;

        const addLine = function(value, source, rateType, label) {
            if (!value) return;

            const sourceStyle = cfg[styleKey][source];
            // Use dotted line style for custom/dynamic rates
            const lineStyleKey = isCustom ? (rateType + '_custom') : rateType;
            const lineTypeStyle = cfg.lineStyle[lineStyleKey] || cfg.lineStyle[rateType];

            // Use line color as background, contrasting text for readability
            const bgColor = sourceStyle.color;
            const textColor = getContrastTextColor(bgColor);

            // Stack labels vertically at the right edge
            const verticalOffset = labelIndex * 20;
            labelIndex++;

            lines.push({
                yAxis: value,
                lineStyle: {
                    color: sourceStyle.color,
                    width: lineTypeStyle.width,
                    type: lineTypeStyle.type
                },
                label: {
                    show: true,
                    formatter: `${label} ${value}`,
                    position: 'end',
                    distance: 5,
                    offset: [0, verticalOffset],
                    color: textColor,
                    fontSize: cfg.label.fontSize || 10,
                    fontWeight: cfg.label.fontWeight || 'bold',
                    fontFamily: '"Roboto Mono", monospace',
                    backgroundColor: bgColor,
                    padding: [2, 6],
                    borderRadius: 3,
                    borderColor: textColor === '#ffffff' ? 'rgba(255,255,255,0.3)' : 'rgba(0,0,0,0.2)',
                    borderWidth: 1
                }
            });
        };

        if (direction === 'both' || direction === 'arr') {
            addLine(rates.vatsim_aar, 'vatsim', 'aar', 'AAR');
            addLine(rates.rw_aar, 'rw', 'aar', 'RW AAR');
        }

        if (direction === 'both' || direction === 'dep') {
            addLine(rates.vatsim_adr, 'vatsim', 'adr', 'ADR');
            addLine(rates.rw_adr, 'rw', 'adr', 'RW ADR');
        }

        return lines;
    }

    /**
     * Format timestamp for tooltip display - FAA AADC style
     * @param {number} timestamp - Unix timestamp in milliseconds
     * @param {string} granularity - 'hourly', '30min', or '15min'
     * @returns {string} Formatted time range string
     */
    function formatTimeLabelFromTimestamp(timestamp, granularity) {
        const intervalMs = getGranularityMinutes(granularity) * 60 * 1000;
        const halfInterval = intervalMs / 2;
        const binStart = timestamp - halfInterval;

        const d = new Date(binStart);
        const hours = d.getUTCHours().toString().padStart(2, '0');
        const minutes = d.getUTCMinutes().toString().padStart(2, '0');

        const endTime = new Date(binStart + intervalMs);
        const endHours = endTime.getUTCHours().toString().padStart(2, '0');
        const endMinutes = endTime.getUTCMinutes().toString().padStart(2, '0');

        return `${hours}${minutes} - ${endHours}${endMinutes}`;
    }

    /**
     * Build chart title in FSM/TBFM style
     * @param {string} airport - Airport ICAO code
     * @param {string|Date} lastAdlUpdate - Last ADL update timestamp
     * @returns {string} Formatted chart title
     */
    function buildChartTitle(airport, lastAdlUpdate) {
        let dateStr = '--/--/----';
        let timeStr = '--:--Z';

        if (lastAdlUpdate) {
            const adlDate = new Date(lastAdlUpdate);
            const month = (adlDate.getUTCMonth() + 1).toString().padStart(2, '0');
            const day = adlDate.getUTCDate().toString().padStart(2, '0');
            const year = adlDate.getUTCFullYear();
            dateStr = `${month}/${day}/${year}`;

            const hours = adlDate.getUTCHours().toString().padStart(2, '0');
            const mins = adlDate.getUTCMinutes().toString().padStart(2, '0');
            timeStr = `${hours}:${mins}Z`;
        }

        return `${airport}          ${dateStr}          ${timeStr}`;
    }

    /**
     * Get X-axis label based on granularity
     * @param {string} granularity - 'hourly', '30min', or '15min'
     * @returns {string} X-axis label
     */
    function getXAxisLabel(granularity) {
        const minutes = getGranularityMinutes(granularity);
        return `Time in ${minutes}-Minute Increments`;
    }

    /**
     * Create a demand chart instance with a simple API
     * @param {HTMLElement|string} container - DOM element or element ID
     * @param {Object} options - Chart options
     * @returns {Object} Chart controller instance
     */
    function createChart(container, options) {
        options = options || {};
        const el = typeof container === 'string' ? document.getElementById(container) : container;
        if (!el) {
            console.error('DemandChartCore: container not found');
            return null;
        }

        const chart = echarts.init(el);
        const state = {
            chart: chart,
            airport: null,
            direction: options.direction || 'both',
            granularity: options.granularity || 'hourly',
            timeBasis: options.timeBasis || 'eta',  // 'eta' or 'ctd' (controlled time)
            programId: options.programId || null,    // Optional TMI program filter
            timeRangeStart: options.timeRangeStart !== undefined ? options.timeRangeStart : -2,
            timeRangeEnd: options.timeRangeEnd !== undefined ? options.timeRangeEnd : 14,
            lastData: null,
            rateData: null,
            showRateLines: options.showRateLines !== false
        };

        // Handle window resize
        var resizeHandler = function() {
            if (state.chart) state.chart.resize();
        };
        window.addEventListener('resize', resizeHandler);

        return {
        load: function(airport, opts) {
        opts = opts || {};
        if (!airport) {
        this.clear();
        return Promise.resolve({ success: false, error: 'No airport specified' });
        }

        state.airport = airport;
        if (opts.direction) state.direction = opts.direction;
        if (opts.granularity) state.granularity = opts.granularity;
        if (opts.timeBasis) state.timeBasis = opts.timeBasis;
        if (opts.programId !== undefined) state.programId = opts.programId;
                if (opts.timeRangeStart !== undefined) state.timeRangeStart = opts.timeRangeStart;
        if (opts.timeRangeEnd !== undefined) state.timeRangeEnd = opts.timeRangeEnd;

        var now = new Date();
                var start = new Date(now.getTime() + state.timeRangeStart * 60 * 60 * 1000);
        var end = new Date(now.getTime() + state.timeRangeEnd * 60 * 60 * 1000);

        var params = new URLSearchParams({
        airport: airport,
        granularity: state.granularity,
        direction: state.direction,
            start: start.toISOString(),
                    end: end.toISOString(),
                    time_basis: state.timeBasis
                });
                
                // Add program_id if specified (for TMI-specific filtering)
                if (state.programId) {
                    params.append('program_id', state.programId);
                }

                state.chart.showLoading({ text: 'Loading...', maskColor: 'rgba(255,255,255,0.8)', textColor: '#333' });

                var self = this;
                var demandPromise = fetch('api/demand/airport.php?' + params.toString()).then(function(r) { return r.json(); });
                var ratesPromise = fetch('api/demand/rates.php?airport=' + encodeURIComponent(airport)).then(function(r) { return r.json(); }).catch(function() { return null; });

                return Promise.all([demandPromise, ratesPromise]).then(function(results) {
                    var demandResponse = results[0];
                    var ratesResponse = results[1];
                    state.chart.hideLoading();

                    if (!demandResponse.success) {
                        console.error('Demand API error:', demandResponse.error);
                        return { success: false, error: demandResponse.error };
                    }

                    state.lastData = demandResponse;
                    state.rateData = (ratesResponse && ratesResponse.success) ? ratesResponse : null;

                    self.render();
                    return { success: true, data: demandResponse, rates: state.rateData };
                }).catch(function(err) {
                    state.chart.hideLoading();
                    console.error('DemandChartCore load error:', err);
                    return { success: false, error: err.message };
                });
            },

            render: function() {
                if (!state.chart || !state.lastData) return;

                var data = state.lastData;
                var arrivals = data.data.arrivals || [];
                var departures = data.data.departures || [];
                var direction = state.direction;

                console.log('[DemandChart] render - timeBasis:', state.timeBasis, 'arrivals:', arrivals.length, 'departures:', departures.length);

                var timeBins = generateAllTimeBins(state.granularity, state.timeRangeStart, state.timeRangeEnd);

                var arrivalsByBin = {};
                arrivals.forEach(function(d) { arrivalsByBin[normalizeTimeBin(d.time_bin)] = d.breakdown; });

                var departuresByBin = {};
                departures.forEach(function(d) { departuresByBin[normalizeTimeBin(d.time_bin)] = d.breakdown; });
                
                // Debug: Log first breakdown to see what keys are available
                if (arrivals.length > 0 && arrivals[0].breakdown) {
                    console.log('[DemandChart] Sample breakdown keys:', Object.keys(arrivals[0].breakdown));
                }

                var series = [];
                
                // When time_basis=ctd, only show TMI status breakdown (not flight phases)
                // to avoid double-counting controlled flights
                var phasesToRender;
                if (state.timeBasis === 'ctd') {
                    // TMI status phases only - no regular flight phases to avoid double-counting
                    phasesToRender = [
                        'uncontrolled',  // Flights not controlled by any TMI
                        'exempt',        // Exempt from TMI
                        'actual_gs', 'simulated_gs', 'proposed_gs',     // Ground Stop statuses
                        'actual_gdp', 'simulated_gdp', 'proposed_gdp'   // GDP statuses
                    ];
                } else {
                    // Standard flight phases when using ETA
                    phasesToRender = ['arrived', 'disconnected', 'descending', 'enroute', 
                                      'departed', 'taxiing', 'prefile', 'unknown'];
                }
                console.log('[DemandChart] phasesToRender:', phasesToRender);

                if (direction === 'arr' || direction === 'both') {
                    phasesToRender.forEach(function(phase) {
                        var suffix = direction === 'both' ? ' (Arr)' : '';
                        series.push(buildPhaseSeriesTimeAxis(PHASE_LABELS[phase] + suffix, timeBins, arrivalsByBin, phase, 'arrivals', direction, state.granularity));
                    });
                }

                if (direction === 'dep' || direction === 'both') {
                    phasesToRender.forEach(function(phase) {
                        var suffix = direction === 'both' ? ' (Dep)' : '';
                        series.push(buildPhaseSeriesTimeAxis(PHASE_LABELS[phase] + suffix, timeBins, departuresByBin, phase, 'departures', direction, state.granularity));
                    });
                }

                var timeMarkLineData = getCurrentTimeMarkLineForTimeAxis();
                var rateMarkLines = buildRateMarkLinesForChart(state.rateData, direction, state.showRateLines);

                if (series.length > 0) {
                    var markLineData = [];
                    if (timeMarkLineData) markLineData.push(timeMarkLineData);
                    if (rateMarkLines && rateMarkLines.length > 0) markLineData.push.apply(markLineData, rateMarkLines);

                    if (markLineData.length > 0) {
                        series[0].markLine = { silent: true, symbol: ['none', 'none'], data: markLineData };
                    }
                }

                var intervalMs = getGranularityMinutes(state.granularity) * 60 * 1000;
                var chartTitle = buildChartTitle(data.airport, data.last_adl_update);
                var gran = state.granularity;

                var option = {
                    backgroundColor: '#ffffff',
                    title: {
                        text: chartTitle,
                        left: 'center',
                        top: 10,
                        textStyle: { fontSize: 13, fontWeight: 'bold', color: '#333', fontFamily: '"Inconsolata", "SF Mono", monospace' }
                    },
                    tooltip: {
                        trigger: 'axis',
                        axisPointer: { type: 'shadow' },
                        backgroundColor: 'rgba(255, 255, 255, 0.98)',
                        borderColor: '#ccc',
                        borderWidth: 1,
                        padding: [8, 12],
                        textStyle: { color: '#333', fontSize: 11 },
                        formatter: function(params) {
                            if (!params || params.length === 0) return '';
                            var timestamp = params[0].value[0];
                            var timeStr = formatTimeLabelFromTimestamp(timestamp, gran);
                            var tooltip = '<strong style="font-size:12px;">' + timeStr + '</strong><br/>';
                            var total = 0;
                            params.forEach(function(p) {
                                var val = p.value[1] || 0;
                                if (val > 0) {
                                    tooltip += p.marker + ' ' + p.seriesName + ': <strong>' + val + '</strong><br/>';
                                    total += val;
                                }
                            });
                            tooltip += '<hr style="margin:4px 0;border-color:#ddd;"/><strong>Total: ' + total + '</strong>';
                            // Add rate information if available
                            if (state.rateData && state.rateData.rates) {
                                var rates = state.rateData.rates;
                                var proRateFactor = getGranularityMinutes(state.granularity) / 60;
                                var aar = rates.vatsim_aar ? Math.round(rates.vatsim_aar * proRateFactor) : null;
                                var adr = rates.vatsim_adr ? Math.round(rates.vatsim_adr * proRateFactor) : null;
                                if (aar || adr) {
                                    tooltip += '<hr style="margin:4px 0;border-color:#ddd;"/>';
                                    if (aar) tooltip += '<span style="color:#000;">AAR: <strong>' + aar + '</strong></span>';
                                    if (aar && adr) tooltip += ' / ';
                                    if (adr) tooltip += '<span style="color:#000;">ADR: <strong>' + adr + '</strong></span>';
                                }
                            }
                            return tooltip;
                        }
                    },
                    legend: direction === 'both' ? [
                        {
                            // Arrivals row
                            bottom: 30,
                            left: 'center',
                            width: '85%',  // Allow wrapping
                            type: 'scroll',
                            itemWidth: 12,
                            itemHeight: 8,
                            itemGap: 10,   // Space between items
                            textStyle: { fontSize: 10, fontFamily: '"Segoe UI", sans-serif' },
                            data: series.filter(s => s.name.includes('(Arr)')).map(s => s.name),
                            formatter: function(name) { return name.replace(' (Arr)', ''); }
                        },
                        {
                            // Departures row
                            bottom: 5,
                            left: 'center',
                            width: '85%',  // Allow wrapping
                            type: 'scroll',
                            itemWidth: 12,
                            itemHeight: 8,
                            itemGap: 10,   // Space between items
                            textStyle: { fontSize: 10, fontFamily: '"Segoe UI", sans-serif' },
                            icon: 'rect',
                            data: series.filter(s => s.name.includes('(Dep)')).map(s => s.name),
                            formatter: function(name) { return name.replace(' (Dep)', ''); }
                        }
                    ] : {
                        bottom: 5,
                        left: 'center',
                        width: '85%',  // Allow wrapping
                        type: 'scroll',
                        itemWidth: 12,
                        itemHeight: 8,
                        itemGap: 10,   // Space between items
                        textStyle: { fontSize: 10, fontFamily: '"Segoe UI", sans-serif' }
                    },
                    grid: { left: 50, right: 70, bottom: 100, top: 45, containLabel: false },  // Extra room for wrapped legend
                    xAxis: {
                        type: 'time',
                        name: getXAxisLabel(state.granularity),
                        nameLocation: 'middle',
                        nameGap: 25,
                        nameTextStyle: { fontSize: 10, color: '#333', fontWeight: 500 },
                        maxInterval: 3600 * 1000,
                        axisLine: { lineStyle: { color: '#333', width: 1 } },
                        axisTick: { alignWithLabel: true, lineStyle: { color: '#666' } },
                        axisLabel: {
                            fontSize: 10,
                            color: '#333',
                            fontFamily: '"Inconsolata", "SF Mono", monospace',
                            formatter: function(value) {
                                var d = new Date(value);
                                return d.getUTCHours().toString().padStart(2, '0') + d.getUTCMinutes().toString().padStart(2, '0') + 'Z';
                            }
                        },
                        splitLine: { show: true, lineStyle: { color: '#f0f0f0', type: 'solid' } },
                        min: new Date(timeBins[0]).getTime(),
                        max: new Date(timeBins[timeBins.length - 1]).getTime() + intervalMs
                    },
                    yAxis: {
                        type: 'value',
                        name: 'Demand',
                        nameLocation: 'middle',
                        nameGap: 35,
                        nameTextStyle: { fontSize: 11, color: '#333', fontWeight: 500 },
                        minInterval: 1,
                        axisLine: { show: true, lineStyle: { color: '#333', width: 1 } },
                        axisTick: { show: true, lineStyle: { color: '#666' } },
                        axisLabel: { fontSize: 10, color: '#333', fontFamily: '"Inconsolata", monospace' },
                        splitLine: { show: true, lineStyle: { color: '#e8e8e8', type: 'dashed' } }
                    },
                    series: series
                };

                state.chart.setOption(option, true);
            },

            update: function(opts) {
                var needsReload = false;
                var needsRender = false;

                if (opts.direction && opts.direction !== state.direction) {
                    state.direction = opts.direction;
                    needsRender = true;
                }
                if (opts.granularity && opts.granularity !== state.granularity) {
                    state.granularity = opts.granularity;
                    needsReload = true;
                }
                if (opts.timeBasis && opts.timeBasis !== state.timeBasis) {
                    state.timeBasis = opts.timeBasis;
                    needsReload = true;  // Time basis change requires fresh data
                }
                if (opts.programId !== undefined && opts.programId !== state.programId) {
                    state.programId = opts.programId;
                    needsReload = true;
                }
                if (opts.timeRangeStart !== undefined && opts.timeRangeStart !== state.timeRangeStart) {
                    state.timeRangeStart = opts.timeRangeStart;
                    needsReload = true;
                }
                if (opts.timeRangeEnd !== undefined && opts.timeRangeEnd !== state.timeRangeEnd) {
                    state.timeRangeEnd = opts.timeRangeEnd;
                    needsReload = true;
                }

                if (needsReload && state.airport) {
                    return this.load(state.airport);
                } else if (needsRender && state.lastData) {
                    this.render();
                }
                return Promise.resolve();
            },

            clear: function() {
                state.lastData = null;
                state.rateData = null;
                state.airport = null;
                if (state.chart) state.chart.clear();
            },

            getRateData: function() { return state.rateData; },
            getState: function() {
                return {
                    airport: state.airport,
                    direction: state.direction,
                    granularity: state.granularity,
                    timeBasis: state.timeBasis,
                    programId: state.programId,
                    timeRangeStart: state.timeRangeStart,
                    timeRangeEnd: state.timeRangeEnd
                };
            },
            dispose: function() {
                window.removeEventListener('resize', resizeHandler);
                if (state.chart) { state.chart.dispose(); state.chart = null; }
            },
            resize: function() { if (state.chart) state.chart.resize(); }
        };
    }

    // Public API
    return {
        // Core utility functions
        getGranularityMinutes: getGranularityMinutes,
        generateAllTimeBins: generateAllTimeBins,
        normalizeTimeBin: normalizeTimeBin,
        buildPhaseSeriesTimeAxis: buildPhaseSeriesTimeAxis,
        getCurrentTimeMarkLineForTimeAxis: getCurrentTimeMarkLineForTimeAxis,
        buildRateMarkLinesForChart: buildRateMarkLinesForChart,
        formatTimeLabelFromTimestamp: formatTimeLabelFromTimestamp,
        buildChartTitle: buildChartTitle,
        getXAxisLabel: getXAxisLabel,

        // Chart factory
        createChart: createChart,

        // Constants
        PHASE_COLORS: PHASE_COLORS,
        PHASE_LABELS: PHASE_LABELS,
        PHASE_ORDER: PHASE_ORDER
    };
})();

// Alias for backwards compatibility with demand-chart.js API
window.DemandChart = {
    create: window.DemandChartCore.createChart,
    PHASE_COLORS: window.DemandChartCore.PHASE_COLORS,
    PHASE_LABELS: window.DemandChartCore.PHASE_LABELS,
    PHASE_ORDER: window.DemandChartCore.PHASE_ORDER
};

// ============================================================================
// Page-specific demand visualization code (demand.php only)
// ============================================================================

// Global state
let DEMAND_STATE = {
    selectedAirport: null,
    granularity: 'hourly',
    timeRangeStart: -2, // hours before now (null if custom)
    timeRangeEnd: 14,   // hours after now (null if custom)
    timeRangeMode: 'preset', // 'preset' or 'custom'
    customStart: null,  // ISO datetime string for custom start
    customEnd: null,    // ISO datetime string for custom end
    direction: 'both',
    category: 'all',
    artcc: '',
    tier: 'all',
    autoRefresh: true,
    refreshInterval: 15000, // 15 seconds
    refreshTimer: null,
    chart: null,
    lastUpdate: null,
    timeBins: [], // Store time bins for drill-down
    currentStart: null,
    currentEnd: null,
    chartView: 'status', // 'status', 'origin', 'dest', 'carrier', 'weight', 'equipment', 'rule', 'dep_fix', 'arr_fix', 'dp', 'star'
    originBreakdown: null, // Store origin ARTCC breakdown data
    destBreakdown: null, // Store dest ARTCC breakdown data
    weightBreakdown: null, // Store weight class breakdown data
    carrierBreakdown: null, // Store carrier breakdown data
    equipmentBreakdown: null, // Store equipment breakdown data
    ruleBreakdown: null, // Store flight rule breakdown data
    depFixBreakdown: null, // Store departure fix breakdown data
    arrFixBreakdown: null, // Store arrival fix breakdown data
    dpBreakdown: null, // Store DP/SID breakdown data
    starBreakdown: null, // Store STAR breakdown data
    lastDemandData: null, // Store last demand response for view switching
    rateData: null, // Store rate suggestion data from API
    tmiConfig: null, // Store active TMI CONFIG entry if any
    scheduledConfigs: null, // Store all scheduled TMI CONFIG entries for time-bounded rate lines
    tmiPrograms: null, // Store GS/GDP programs for vertical markers
    showRateLines: true, // Master toggle for rate line visibility
    // Individual rate line visibility toggles
    showVatsimAar: true,
    showVatsimAdr: true,
    showRwAar: true,
    showRwAdr: true,
    atisData: null, // Store ATIS data from API
    // Cache management
    cacheTimestamp: null, // When data was last loaded from API
    cacheValidityMs: 15000, // Cache is valid for 15 seconds
    summaryLoaded: false // Whether summary breakdown data has been loaded
};

// Phase colors - use shared config from phase-colors.js
// Fallback definitions if config not loaded (for backwards compatibility)
const FSM_PHASE_COLORS = (typeof PHASE_COLORS !== 'undefined') ? PHASE_COLORS : {
    'arrived': '#1a1a1a',       // Black - Landed at destination (bottom)
    'disconnected': '#f97316',  // Bright Orange - Disconnected mid-flight
    'descending': '#991b1b',    // Dark Red - On approach to destination
    'enroute': '#dc2626',       // Red - Cruising
    'departed': '#f87171',      // Light Red - Just took off from origin
    'taxiing': '#22c55e',       // Green - Taxiing at origin airport
    'prefile': '#3b82f6',       // Blue - Filed flight plan
    'unknown': '#9333ea'        // Purple - Unknown/other phase (top)
};

// Phase labels - use shared config if available
const FSM_PHASE_LABELS = (typeof PHASE_LABELS !== 'undefined') ? PHASE_LABELS : {
    'arrived': 'Arrived',
    'disconnected': 'Disconnected',
    'descending': 'Descending',
    'enroute': 'Enroute',
    'departed': 'Departed',
    'taxiing': 'Taxiing',
    'prefile': 'Prefile',
    'unknown': 'Unknown'
};

// ARTCC colors for origin breakdown visualization
const ARTCC_COLORS = {
    'ZNY': '#e41a1c', 'ZDC': '#377eb8', 'ZBW': '#4daf4a', 'ZOB': '#984ea3',
    'ZAU': '#ff7f00', 'ZID': '#ffff33', 'ZTL': '#a65628', 'ZJX': '#f781bf',
    'ZMA': '#999999', 'ZHU': '#66c2a5', 'ZFW': '#fc8d62', 'ZKC': '#8da0cb',
    'ZME': '#e78ac3', 'ZDV': '#a6d854', 'ZMP': '#ffd92f', 'ZAB': '#e5c494',
    'ZLA': '#b3b3b3', 'ZOA': '#1b9e77', 'ZSE': '#d95f02', 'ZLC': '#7570b3',
    'ZAN': '#e7298a', 'ZHN': '#66a61e'
};

// Generate a color for unknown ARTCCs
function getARTCCColor(artcc) {
    if (ARTCC_COLORS[artcc]) {
        return ARTCC_COLORS[artcc];
    }
    // Generate consistent color from ARTCC name
    let hash = 0;
    for (let i = 0; i < artcc.length; i++) {
        hash = artcc.charCodeAt(i) + ((hash << 5) - hash);
    }
    const hue = Math.abs(hash % 360);
    return `hsl(${hue}, 70%, 50%)`;
}

// Format date for datetime-local input (YYYY-MM-DDTHH:MM in local time)
/**
 * Format a Date object for datetime-local input in UTC
 * @param {Date} date - Date object to format
 * @returns {string} - Format: YYYY-MM-DDTHH:MM (UTC time)
 */
function formatDateTimeLocalUTC(date) {
    const year = date.getUTCFullYear();
    const month = String(date.getUTCMonth() + 1).padStart(2, '0');
    const day = String(date.getUTCDate()).padStart(2, '0');
    const hours = String(date.getUTCHours()).padStart(2, '0');
    const minutes = String(date.getUTCMinutes()).padStart(2, '0');
    return `${year}-${month}-${day}T${hours}:${minutes}`;
}

/**
 * Parse a datetime-local input value as UTC
 * @param {string} value - datetime-local value (YYYY-MM-DDTHH:MM)
 * @returns {Date} - Date object representing the UTC time
 */
function parseDateTimeLocalAsUTC(value) {
    // Append 'Z' to interpret as UTC
    return new Date(value + ':00Z');
}

// Time range options (from design document)
const TIME_RANGE_OPTIONS = [
    { value: 'T-2/+14', label: 'T-2H/T+14H', start: -2, end: 14 },
    { value: 'T-1/+6', label: 'T-1H/T+6H', start: -1, end: 6 },
    { value: 'T-3/+6', label: 'T-3H/T+6H', start: -3, end: 6 },
    { value: 'T-6/+6', label: '+/- 6H', start: -6, end: 6 },
    { value: 'T-12/+12', label: '+/- 12H', start: -12, end: 12 },
    { value: 'T-24/+24', label: '+/- 24H', start: -24, end: 24 },
    { value: 'custom', label: 'Custom...', start: null, end: null }
];

// ARTCC tier data (loaded from JSON)
let ARTCC_TIERS = null;

// ============================================================================
// TMI Config Integration Functions
// ============================================================================

/**
 * Merge TMI CONFIG data into existing rate data
 * TMI CONFIG takes precedence for AAR/ADR and runways
 * @param {Object} rateData - Existing rate data from rates API
 * @param {Object} tmiConfig - Active TMI CONFIG entry
 * @returns {Object} Merged rate data
 */
function mergeWithTmiConfig(rateData, tmiConfig) {
    // Clone the rate data to avoid mutation
    const merged = JSON.parse(JSON.stringify(rateData));

    // Override with TMI CONFIG values
    if (tmiConfig.aar !== null && tmiConfig.aar !== undefined) {
        if (!merged.rates) merged.rates = {};
        merged.rates.vatsim_aar = tmiConfig.aar;
    }

    if (tmiConfig.adr !== null && tmiConfig.adr !== undefined) {
        if (!merged.rates) merged.rates = {};
        merged.rates.vatsim_adr = tmiConfig.adr;
    }

    // Override runways if provided
    if (tmiConfig.arr_runways) {
        merged.arr_runways = tmiConfig.arr_runways;
    }

    if (tmiConfig.dep_runways) {
        merged.dep_runways = tmiConfig.dep_runways;
    }

    // Override weather category if provided
    if (tmiConfig.weather_category) {
        merged.weather_category = tmiConfig.weather_category;
    }

    // Override config name if provided
    if (tmiConfig.config_name) {
        merged.config_name = tmiConfig.config_name;
    }

    // Mark as TMI source for display
    merged.tmi_source = true;
    merged.tmi_config = tmiConfig;
    merged.rate_source = 'TMI';
    merged.match_type = 'TMI';

    // Note: Don't set has_override=true since this is a TMI publication, not a manual override
    // The override badge should only show for manual rate overrides

    return merged;
}

/**
 * Build rate data object from TMI CONFIG when rates API is unavailable
 * @param {Object} tmiConfig - Active TMI CONFIG entry
 * @returns {Object} Rate data object compatible with updateRateInfoDisplay
 */
function buildRateDataFromTmiConfig(tmiConfig) {
    return {
        success: true,
        airport_icao: tmiConfig.airport,
        config_name: tmiConfig.config_name || `TMI Config`,
        config_matched: true,
        arr_runways: tmiConfig.arr_runways || null,
        dep_runways: tmiConfig.dep_runways || null,
        weather_category: tmiConfig.weather_category || 'VMC',
        rates: {
            vatsim_aar: tmiConfig.aar,
            vatsim_adr: tmiConfig.adr
        },
        is_suggested: false,
        has_override: false,
        rate_source: 'TMI',
        match_type: 'TMI',
        tmi_source: true,
        tmi_config: tmiConfig
    };
}

/**
 * Initialize the demand visualization page
 */
function initDemand() {
    console.log('Initializing Demand Visualization...');

    // Load tier data
    loadTierData();

    // Populate filter dropdowns
    populateTimeRanges();
    loadAirportList();

    // Initialize chart
    initChart();

    // Set up event handlers
    setupEventHandlers();

    // Start with no airport selected - show prompt
    showSelectAirportPrompt();

    console.log('Demand Visualization initialized.');
}

/**
 * Load ARTCC tier data from API (database-backed)
 */
function loadTierData() {
    $.getJSON('api/tiers.php?format=legacy')
        .done(function(data) {
            ARTCC_TIERS = data;
            populateARTCCDropdown();
            console.log('ARTCC tier data loaded from database.');
        })
        .fail(function(err) {
            console.error('Failed to load ARTCC tier data:', err);
        });
}

/**
 * Populate time range dropdown
 */
function populateTimeRanges() {
    const select = $('#demand_time_range');
    select.empty();
    TIME_RANGE_OPTIONS.forEach(function(opt) {
        // Default selection is T-2H/T+14H
        const isDefault = opt.start === DEMAND_STATE.timeRangeStart && opt.end === DEMAND_STATE.timeRangeEnd;
        const selected = isDefault ? 'selected' : '';
        select.append(`<option value="${opt.value}" data-start="${opt.start}" data-end="${opt.end}" ${selected}>${opt.label}</option>`);
    });
}

/**
 * Populate ARTCC dropdown
 */
function populateARTCCDropdown() {
    const select = $('#demand_artcc');
    select.empty();
    select.append('<option value="">All ARTCCs</option>');

    if (ARTCC_TIERS && ARTCC_TIERS.facilityList) {
        ARTCC_TIERS.facilityList.forEach(function(artcc) {
            select.append(`<option value="${artcc}">${artcc}</option>`);
        });
    }
}

/**
 * Load airport list from API
 */
function loadAirportList() {
    const category = DEMAND_STATE.category;
    const artcc = DEMAND_STATE.artcc;
    const tier = DEMAND_STATE.tier;

    let url = `api/demand/airports.php?category=${category}`;
    if (artcc) {
        url += `&artcc=${artcc}`;
    }
    if (tier && tier !== 'all') {
        url += `&tier=${tier}`;
    }

    $.getJSON(url)
        .done(function(response) {
            if (response.success) {
                populateAirportDropdown(response.airports);
            } else {
                console.error('Failed to load airports:', response.error);
                showError('Failed to load airport list');
            }
        })
        .fail(function(err) {
            console.error('API error loading airports:', err);
            showError('Error connecting to server');
        });
}

/**
 * Populate airport dropdown with loaded airports
 */
function populateAirportDropdown(airports) {
    const select = $('#demand_airport');
    const currentValue = select.val();

    select.empty();
    select.append('<option value="">-- Select Airport --</option>');

    airports.forEach(function(apt) {
        // Show only FAA/ICAO code without category labels
        select.append(`<option value="${apt.icao}" data-name="${apt.name}" data-artcc="${apt.artcc}">${apt.icao}</option>`);
    });

    // Restore selection if still valid
    if (currentValue && select.find(`option[value="${currentValue}"]`).length) {
        select.val(currentValue);
    }

    // Refresh bootstrap-select if used
    if ($.fn.selectpicker) {
        select.selectpicker('refresh');
    }
}

/**
 * Initialize ECharts instance
 */
function initChart() {
    const chartContainer = document.getElementById('demand_chart');
    if (!chartContainer) {
        console.error('Chart container not found');
        return;
    }

    DEMAND_STATE.chart = echarts.init(chartContainer);

    // Handle window resize
    window.addEventListener('resize', function() {
        if (DEMAND_STATE.chart) {
            DEMAND_STATE.chart.resize();
        }
    });
}

/**
 * Set up event handlers for filters and controls
 */
function setupEventHandlers() {
    // Airport selection
    $('#demand_airport').on('change', function() {
        const airport = $(this).val();
        DEMAND_STATE.selectedAirport = airport;
        if (airport) {
            loadDemandData();
            startAutoRefresh();
        } else {
            showSelectAirportPrompt();
            stopAutoRefresh();
        }
    });

    // Granularity toggle - invalidates cache as data structure changes
    $('input[name="demand_granularity"]').on('change', function() {
        DEMAND_STATE.granularity = $(this).val();
        invalidateCache(); // Granularity changes require fresh data
        if (DEMAND_STATE.selectedAirport) {
            loadDemandData();
        }
    });

    // Time range - invalidates cache as time window changes
    $('#demand_time_range').on('change', function() {
        const $selected = $(this).find(':selected');
        const value = $selected.val();

        if (value === 'custom') {
            // Show custom time range inputs
            $('#custom_time_range_container').show();
            DEMAND_STATE.timeRangeMode = 'custom';

            // Initialize with current calculated range as default
            const now = new Date();
            const defaultStart = new Date(now.getTime() - 2 * 60 * 60 * 1000); // T-2H
            const defaultEnd = new Date(now.getTime() + 14 * 60 * 60 * 1000);  // T+14H

            // Format for datetime-local input in UTC (YYYY-MM-DDTHH:MM)
            $('#demand_custom_start').val(formatDateTimeLocalUTC(defaultStart));
            $('#demand_custom_end').val(formatDateTimeLocalUTC(defaultEnd));
        } else {
            // Hide custom inputs, use preset
            $('#custom_time_range_container').hide();
            DEMAND_STATE.timeRangeMode = 'preset';
            DEMAND_STATE.timeRangeStart = parseInt($selected.data('start'));
            DEMAND_STATE.timeRangeEnd = parseInt($selected.data('end'));
            DEMAND_STATE.customStart = null;
            DEMAND_STATE.customEnd = null;
            invalidateCache(); // Time range changes require fresh data
            if (DEMAND_STATE.selectedAirport) {
                loadDemandData();
            }
        }
    });

    // Apply custom time range button
    $('#apply_custom_range').on('click', function() {
        const startVal = $('#demand_custom_start').val();
        const endVal = $('#demand_custom_end').val();

        if (!startVal || !endVal) {
            showError('Please select both start and end times');
            return;
        }

        // Parse datetime-local values as UTC
        const startDate = parseDateTimeLocalAsUTC(startVal);
        const endDate = parseDateTimeLocalAsUTC(endVal);

        if (endDate <= startDate) {
            showError('End time must be after start time');
            return;
        }

        // Store as ISO strings
        DEMAND_STATE.customStart = startDate.toISOString();
        DEMAND_STATE.customEnd = endDate.toISOString();
        DEMAND_STATE.timeRangeMode = 'custom';

        invalidateCache();
        if (DEMAND_STATE.selectedAirport) {
            loadDemandData();
        }
    });

    // Direction toggle - can use cached data, just re-render
    $('input[name="demand_direction"]').on('change', function() {
        DEMAND_STATE.direction = $(this).val();
        if (DEMAND_STATE.selectedAirport) {
            // Direction change still requires fresh data from API (data is directional)
            invalidateCache();
            loadDemandData();
        }
    });

    // Category filter
    $('#demand_category').on('change', function() {
        DEMAND_STATE.category = $(this).val();
        loadAirportList();
    });

    // ARTCC filter
    $('#demand_artcc').on('change', function() {
        DEMAND_STATE.artcc = $(this).val();
        updateTierOptions();
        loadAirportList();
    });

    // Tier filter
    $('#demand_tier').on('change', function() {
        DEMAND_STATE.tier = $(this).val();
        loadAirportList();
    });

    // Auto-refresh toggle
    $('#demand_auto_refresh').on('change', function() {
        DEMAND_STATE.autoRefresh = $(this).is(':checked');
        if (DEMAND_STATE.autoRefresh && DEMAND_STATE.selectedAirport) {
            startAutoRefresh();
        } else {
            stopAutoRefresh();
        }
    });

    // Manual refresh button
    $('#demand_refresh_btn').on('click', function() {
        if (DEMAND_STATE.selectedAirport) {
            loadDemandData();
        }
    });

    // Chart view toggle (Status, Origin, Dest, Carrier, Weight, Equipment, Rule, etc.)
    // Uses cached breakdown data to avoid re-querying on view changes
    $('input[name="demand_chart_view"]').on('change', function() {
        DEMAND_STATE.chartView = $(this).val();
        if (DEMAND_STATE.selectedAirport && DEMAND_STATE.lastDemandData) {
            // Check if we need to load breakdown data (only if not cached or cache expired)
            const needsSummaryData = DEMAND_STATE.chartView !== 'status';
            const hasCachedSummary = DEMAND_STATE.summaryLoaded && isCacheValid();

            if (needsSummaryData && !hasCachedSummary) {
                // Load summary data and then render
                loadFlightSummary(true);
            } else {
                // Use cached data - render immediately without API call
                renderCurrentView();
            }
        }
    });

    // ATIS details button click handler
    $('#atis_details_btn').on('click', function() {
        showAtisModal();
    });

    // Rate line visibility toggles
    $('#rate_vatsim_aar').on('change', function() {
        DEMAND_STATE.showVatsimAar = $(this).is(':checked');
        renderCurrentView();
    });

    $('#rate_vatsim_adr').on('change', function() {
        DEMAND_STATE.showVatsimAdr = $(this).is(':checked');
        renderCurrentView();
    });

    $('#rate_rw_aar').on('change', function() {
        DEMAND_STATE.showRwAar = $(this).is(':checked');
        renderCurrentView();
    });

    $('#rate_rw_adr').on('change', function() {
        DEMAND_STATE.showRwAdr = $(this).is(':checked');
        renderCurrentView();
    });
}

/**
 * Update tier options based on selected ARTCC
 */
function updateTierOptions() {
    const artcc = DEMAND_STATE.artcc;
    const select = $('#demand_tier');
    select.empty();
    select.append('<option value="all">All Tiers</option>');

    if (artcc && ARTCC_TIERS && ARTCC_TIERS.byFacility && ARTCC_TIERS.byFacility[artcc]) {
        const facilityTiers = ARTCC_TIERS.byFacility[artcc];
        // Check for tier types based on label patterns
        let hasInternal = false, has1stTier = false, has2ndTier = false;
        let internalCode = null, tier1Code = null, tier2Code = null;

        for (const code in facilityTiers) {
            const tier = facilityTiers[code];
            if (tier.label && tier.label.includes('Internal')) {
                hasInternal = true;
                internalCode = code;
            } else if (tier.label && tier.label.includes('1stTier')) {
                has1stTier = true;
                tier1Code = code;
            } else if (tier.label && tier.label.includes('2ndTier')) {
                has2ndTier = true;
                tier2Code = code;
            }
        }

        if (hasInternal) select.append(`<option value="${internalCode}">Internal</option>`);
        if (has1stTier) select.append(`<option value="${tier1Code}">1st Tier</option>`);
        if (has2ndTier) select.append(`<option value="${tier2Code}">2nd Tier</option>`);
    }
}

/**
 * Show prompt to select an airport
 */
function showSelectAirportPrompt() {
    // Show empty state, hide chart
    $('#demand_empty_state').show();
    $('#demand_chart').hide();

    // Reset info bar
    $('#demand_selected_airport').text('----');
    $('#demand_airport_name').text('Select an airport');

    // Reset stats
    $('#demand_arr_total, #demand_arr_active, #demand_arr_scheduled, #demand_arr_proposed').text('0');
    $('#demand_dep_total, #demand_dep_active, #demand_dep_scheduled, #demand_dep_proposed').text('0');
    $('#demand_flight_count').text('0 flights');

    $('#demand_last_update').text('--');

    // Hide ATIS card
    $('#atis_card_container').hide();
    DEMAND_STATE.atisData = null;
}

/**
 * Load demand data from API
 */
function loadDemandData() {
    const airport = DEMAND_STATE.selectedAirport;
    if (!airport) {
        showSelectAirportPrompt();
        return;
    }

    // Update info bar with selected airport
    const $selectedOption = $('#demand_airport option:selected');
    const airportName = $selectedOption.data('name') || '';
    $('#demand_selected_airport').text(airport);
    $('#demand_airport_name').text(airportName);

    // Hide empty state, show chart
    $('#demand_empty_state').hide();
    $('#demand_chart').show();

    // Calculate time range - use custom values or preset offsets
    let start, end;
    if (DEMAND_STATE.timeRangeMode === 'custom' && DEMAND_STATE.customStart && DEMAND_STATE.customEnd) {
        // Use custom time range
        start = new Date(DEMAND_STATE.customStart);
        end = new Date(DEMAND_STATE.customEnd);
    } else {
        // Use preset offset from current time
        const now = new Date();
        start = new Date(now.getTime() + DEMAND_STATE.timeRangeStart * 60 * 60 * 1000);
        end = new Date(now.getTime() + DEMAND_STATE.timeRangeEnd * 60 * 60 * 1000);
    }

    const params = new URLSearchParams({
        airport: airport,
        granularity: DEMAND_STATE.granularity,
        direction: DEMAND_STATE.direction,
        start: start.toISOString(),
        end: end.toISOString()
    });

    // Show loading state
    showLoading();

    // Store time range for summary API
    DEMAND_STATE.currentStart = start.toISOString();
    DEMAND_STATE.currentEnd = end.toISOString();

    // Fetch demand data, rate suggestions, ATIS, active TMI config, and scheduled configs in parallel
    // Use Promise.allSettled so optional API failures don't block demand data
    const demandPromise = $.getJSON(`api/demand/airport.php?${params.toString()}`);
    const ratesPromise = $.getJSON(`api/demand/rates.php?airport=${encodeURIComponent(airport)}`);
    const atisPromise = $.getJSON(`api/demand/atis.php?airport=${encodeURIComponent(airport)}`);
    const tmiConfigPromise = $.getJSON(`api/demand/active_config.php?airport=${encodeURIComponent(airport)}`);
    const scheduledConfigsPromise = $.getJSON(`api/demand/scheduled_configs.php?airport=${encodeURIComponent(airport)}&start=${encodeURIComponent(start.toISOString())}&end=${encodeURIComponent(end.toISOString())}`);
    const tmiProgramsPromise = $.getJSON(`api/demand/tmi_programs.php?airport=${encodeURIComponent(airport)}&start=${encodeURIComponent(start.toISOString())}&end=${encodeURIComponent(end.toISOString())}`);

    Promise.allSettled([demandPromise, ratesPromise, atisPromise, tmiConfigPromise, scheduledConfigsPromise, tmiProgramsPromise])
        .then(function(results) {
            const [demandResult, ratesResult, atisResult, tmiConfigResult, scheduledConfigsResult, tmiProgramsResult] = results;

            // Handle demand data (required)
            if (demandResult.status === 'rejected') {
                console.error('Demand API failed:', demandResult.reason);
                showError('Error connecting to server');
                return;
            }

            const demandResponse = demandResult.value;
            if (!demandResponse.success) {
                console.error('API error:', demandResponse.error);
                showError('Failed to load demand data: ' + demandResponse.error);
                return;
            }

            DEMAND_STATE.lastUpdate = new Date();
            DEMAND_STATE.lastDemandData = demandResponse; // Store for view switching
            DEMAND_STATE.cacheTimestamp = Date.now(); // Mark cache as fresh
            DEMAND_STATE.summaryLoaded = false; // Summary needs to be reloaded

            // Handle rate data (optional - don't fail if unavailable)
            // Check for active TMI CONFIG first - it takes precedence
            let tmiConfig = null;
            if (tmiConfigResult.status === 'fulfilled' && tmiConfigResult.value &&
                tmiConfigResult.value.success && tmiConfigResult.value.has_active_config) {
                tmiConfig = tmiConfigResult.value.config;
                DEMAND_STATE.tmiConfig = tmiConfig;
            } else {
                DEMAND_STATE.tmiConfig = null;
            }

            if (ratesResult.status === 'fulfilled' && ratesResult.value && ratesResult.value.success) {
                let rateData = ratesResult.value;

                // If there's an active TMI CONFIG, merge its rates into rateData
                if (tmiConfig) {
                    rateData = mergeWithTmiConfig(rateData, tmiConfig);
                }

                DEMAND_STATE.rateData = rateData;
                updateRateInfoDisplay(rateData);
            } else {
                // Rates API failed - try to use TMI config alone if available
                if (tmiConfig) {
                    const tmiOnlyData = buildRateDataFromTmiConfig(tmiConfig);
                    DEMAND_STATE.rateData = tmiOnlyData;
                    updateRateInfoDisplay(tmiOnlyData);
                } else {
                    // No rate data available
                    DEMAND_STATE.rateData = null;
                    updateRateInfoDisplay(null);
                }
                if (ratesResult.status === 'rejected') {
                    console.warn('Rates API unavailable:', ratesResult.reason);
                }
            }

            // Handle ATIS data (optional - don't fail if unavailable)
            if (atisResult.status === 'fulfilled' && atisResult.value && atisResult.value.success) {
                DEMAND_STATE.atisData = atisResult.value;
                updateAtisDisplay(atisResult.value);
            } else {
                // ATIS API failed or returned error - just hide ATIS card
                DEMAND_STATE.atisData = null;
                updateAtisDisplay(null);
                if (atisResult.status === 'rejected') {
                    console.warn('ATIS API unavailable:', atisResult.reason);
                }
            }

            // Handle scheduled TMI configs (optional - for time-bounded rate lines)
            if (scheduledConfigsResult.status === 'fulfilled' && scheduledConfigsResult.value &&
                scheduledConfigsResult.value.success && scheduledConfigsResult.value.configs) {
                DEMAND_STATE.scheduledConfigs = scheduledConfigsResult.value.configs;
                console.log('[Demand] Loaded', scheduledConfigsResult.value.configs.length, 'scheduled TMI configs for time-bounded rate lines');
            } else {
                DEMAND_STATE.scheduledConfigs = null;
                if (scheduledConfigsResult.status === 'rejected') {
                    console.warn('Scheduled configs API unavailable:', scheduledConfigsResult.reason);
                }
            }

            // Handle TMI programs (GS/GDP) - optional, for vertical markers
            if (tmiProgramsResult.status === 'fulfilled' && tmiProgramsResult.value &&
                tmiProgramsResult.value.success && tmiProgramsResult.value.programs) {
                DEMAND_STATE.tmiPrograms = tmiProgramsResult.value.programs;
                console.log('[Demand] Loaded', tmiProgramsResult.value.programs.length, 'GS/GDP programs for chart markers');
            } else {
                DEMAND_STATE.tmiPrograms = null;
                if (tmiProgramsResult.status === 'rejected') {
                    console.warn('TMI Programs API unavailable:', tmiProgramsResult.reason);
                }
            }

            // Render based on current view mode
            if (DEMAND_STATE.chartView === 'status') {
                // Status view - render immediately with demand data
                renderChart(demandResponse);
                // Load flight summary data (for tables, not chart)
                loadFlightSummary(false);
            } else {
                // Any breakdown view - load breakdown data first, then render
                loadFlightSummary(true);
            }

            updateInfoBarStats(demandResponse);
            updateLastUpdateDisplay(demandResponse.last_adl_update);
        });
}

/**
 * Update rate info display in the info bar
 */
function updateRateInfoDisplay(rateData) {
    if (!rateData) {
        $('#rate_config_name').text('--').attr('title', '');
        $('#rate_weather_category').text('--').removeClass().addClass('badge');
        $('#rate_display').text('--/--');
        $('#rate_source').text('--');
        $('#rate_override_badge').hide();
        $('#rate_arr_runways').text('--');
        $('#rate_dep_runways').text('--');
        return;
    }

    // Config name with tooltip showing full details
    const configName = rateData.config_name || '--';
    const displayName = window.formatConfigName(configName, rateData.arr_runways, rateData.dep_runways);
    const $configEl = $('#rate_config_name');
    $configEl.text(displayName);

    // Populate runway display fields (dep on top, arr below)
    const arrRunways = rateData.arr_runways || '--';
    const depRunways = rateData.dep_runways || '--';
    $('#rate_arr_runways').text(arrRunways.replace(/\//g, ' / '));
    $('#rate_dep_runways').text(depRunways.replace(/\//g, ' / '));

    // Build tooltip with runway info if available
    let tooltip = configName;
    if (rateData.arr_runways || rateData.dep_runways) {
        tooltip += '\n';
        if (rateData.arr_runways) tooltip += `ARR: ${rateData.arr_runways}\n`;
        if (rateData.dep_runways) tooltip += `DEP: ${rateData.dep_runways}`;
    }
    // Add override info to tooltip
    if (rateData.has_override && rateData.override_reason) {
        tooltip += `\n\nOverride: ${rateData.override_reason}`;
    }
    // Add TMI config info to tooltip
    if (rateData.tmi_source && rateData.tmi_config) {
        const tmi = rateData.tmi_config;
        tooltip += '\n\n--- TMI Published Config ---';
        if (tmi.aar_type) tooltip += `\nAAR Type: ${tmi.aar_type}`;
        if (tmi.created_by_name) tooltip += `\nPublished by: ${tmi.created_by_name}`;
        if (tmi.valid_from) {
            const validFrom = new Date(tmi.valid_from);
            tooltip += `\nValid from: ${validFrom.toUTCString().replace('GMT', 'Z')}`;
        }
        if (tmi.valid_until) {
            const validUntil = new Date(tmi.valid_until);
            tooltip += `\nValid until: ${validUntil.toUTCString().replace('GMT', 'Z')}`;
        }
    }
    $configEl.attr('title', tooltip.trim());

    // Weather category with color
    const weatherCat = rateData.weather_category || 'VMC';
    const $weatherBadge = $('#rate_weather_category');
    $weatherBadge.text(weatherCat);

    // Apply weather color from config if available
    if (typeof RATE_LINE_CONFIG !== 'undefined' && RATE_LINE_CONFIG.weatherColors) {
        $weatherBadge.css('background-color', RATE_LINE_CONFIG.weatherColors[weatherCat] || '#6b7280');
        $weatherBadge.css('color', '#fff');
    }

    // Show/hide override badge
    const $overrideBadge = $('#rate_override_badge');
    if (rateData.has_override) {
        $overrideBadge.show();
        // Add expiry time to badge tooltip
        if (rateData.override_end_utc) {
            const endTime = new Date(rateData.override_end_utc);
            const endStr = endTime.getUTCHours().toString().padStart(2, '0') + ':' +
                           endTime.getUTCMinutes().toString().padStart(2, '0') + 'Z';
            $overrideBadge.attr('title', `Override active until ${endStr}`);
        }
    } else {
        $overrideBadge.hide();
    }

    // Rates display (AAR/ADR)
    const aar = rateData.rates?.vatsim_aar;
    const adr = rateData.rates?.vatsim_adr;
    const rateStr = (aar || '--') + '/' + (adr || '--');
    $('#rate_display').text(rateStr);

    // Source info (match_type or rate_source)
    let sourceText = '--';
    if (rateData.match_type) {
        // Format match type for display
        const matchTypeMap = {
            'EXACT': 'Exact',
            'PARTIAL_ARR': 'Partial',
            'PARTIAL_DEP': 'Partial',
            'SUBSET_ARR': 'Subset',
            'SUBSET_DEP': 'Subset',
            'WIND_BASED': 'Wind',
            'CAPACITY_DEFAULT': 'Default',
            'VMC_FALLBACK': 'Fallback',
            'DETECTED_TRACKS': 'Detected',
            'MANUAL': 'Manual',
            'TMI': 'TMI' // Active TMI CONFIG entry
        };
        sourceText = matchTypeMap[rateData.match_type] || rateData.match_type;

        // Add match score if available and not 100%
        if (rateData.match_score && rateData.match_score < 100 && rateData.match_type !== 'MANUAL' && rateData.match_type !== 'TMI') {
            sourceText += ` ${rateData.match_score}%`;
        }

        // Add publisher info for TMI configs
        if (rateData.match_type === 'TMI' && rateData.tmi_config) {
            const tmi = rateData.tmi_config;
            if (tmi.created_by_name) {
                sourceText = `TMI (${tmi.created_by_name})`;
            }
        }
    } else if (rateData.rate_source) {
        sourceText = rateData.rate_source;
    } else if (rateData.is_suggested) {
        sourceText = 'Suggested';
    }
    $('#rate_source').text(sourceText);
}

/**
 * Get age badge color based on minutes
 */
function getAgeBadgeColor(ageMins) {
    if (ageMins < 15) return '#10b981'; // green - fresh
    if (ageMins < 30) return '#f59e0b'; // amber - getting stale
    return '#ef4444'; // red - stale
}

/**
 * Format age text for display
 */
function formatAgeText(ageMins) {
    if (ageMins === null || ageMins === undefined) return '--';
    if (ageMins < 1) return '<1m';
    if (ageMins < 60) return ageMins + 'm';
    return Math.floor(ageMins / 60) + 'h';
}

/**
 * Build HTML for a single ATIS badge
 */
function buildAtisBadge(atis, type, labelPrefix) {
    if (!atis) return '';

    const code = atis.atis_code || '?';
    const ageMins = atis.age_mins || 0;
    const ageColor = getAgeBadgeColor(ageMins);
    const ageText = formatAgeText(ageMins);
    const typeLabel = labelPrefix ? `<span class="badge-atis-type">${labelPrefix}</span>` : '';

    return `<span class="atis-badge-group" data-atis-type="${type}" title="${type.toUpperCase()} ATIS - Info ${code} (${ageText} ago)">
        ${typeLabel}<span class="badge badge-atis">${code}</span><span class="badge badge-age" style="background-color: ${ageColor};">${ageText}</span>
    </span>`;
}

/**
 * Update ATIS display in the info bar
 * Handles combined, arrival-only, departure-only, and ARR+DEP ATIS combinations
 */
function updateAtisDisplay(atisData) {
    const $container = $('#atis_card_container');
    const $badgesContainer = $('#atis_badges_container');

    if (!atisData || !atisData.has_atis) {
        // No ATIS available - hide the card
        $container.hide();
        return;
    }

    // Show the card
    $container.show();

    const effectiveSource = atisData.effective_source;
    const atisArr = atisData.atis_arr;
    const atisDep = atisData.atis_dep;
    const atisComb = atisData.atis_comb;
    const runways = atisData.runways;

    // Build badges based on what ATIS types are available
    let badgesHtml = '';

    if (effectiveSource === 'COMB' && atisComb) {
        // Combined ATIS - show single badge
        badgesHtml = buildAtisBadge(atisComb, 'comb', '');
    } else if (effectiveSource === 'ARR_DEP') {
        // Both ARR and DEP ATIS available - show both
        if (atisArr) {
            badgesHtml += buildAtisBadge(atisArr, 'arr', 'A');
        }
        if (atisDep) {
            badgesHtml += buildAtisBadge(atisDep, 'dep', 'D');
        }
    } else if (effectiveSource === 'ARR_ONLY' && atisArr) {
        // Only arrival ATIS
        badgesHtml = buildAtisBadge(atisArr, 'arr', 'A');
    } else if (effectiveSource === 'DEP_ONLY' && atisDep) {
        // Only departure ATIS
        badgesHtml = buildAtisBadge(atisDep, 'dep', 'D');
    } else {
        // Fallback to primary atis object for backwards compatibility
        const atis = atisData.atis;
        if (atis) {
            const typePrefix = atis.atis_type === 'ARR' ? 'A' : (atis.atis_type === 'DEP' ? 'D' : '');
            badgesHtml = buildAtisBadge(atis, atis.atis_type?.toLowerCase() || 'comb', typePrefix);
        }
    }

    $badgesContainer.html(badgesHtml);

    // Runway configuration (from the effective/combined runway view)
    const arrRunways = runways?.arr_runways || '--';
    const depRunways = runways?.dep_runways || '--';
    const approachInfo = runways?.approach_info || '--';

    $('#atis_arr_runways').text(arrRunways);
    $('#atis_dep_runways').text(depRunways);

    // Approach info with tooltip for full text
    const $approachEl = $('#atis_approach');
    $approachEl.text(approachInfo !== '--' ? approachInfo.split(',')[0].trim() : '--');
    $approachEl.attr('title', approachInfo);
}

/**
 * Build HTML for a single ATIS section in the modal
 */
function buildAtisSection(atis, typeLabel, typeBadgeColor) {
    if (!atis) return '';

    let fetchedTime = '--';
    if (atis.fetched_utc) {
        const d = new Date(atis.fetched_utc);
        fetchedTime = d.getUTCHours().toString().padStart(2, '0') + ':' +
                      d.getUTCMinutes().toString().padStart(2, '0') + 'Z';
    }

    const ageColor = getAgeBadgeColor(atis.age_mins || 0);

    return `
        <div class="atis-section mb-3 pb-3 border-bottom">
            <div class="mb-2">
                <span class="badge" style="background-color: ${typeBadgeColor}; color: #fff;">${typeLabel}</span>
                <span class="badge badge-secondary ml-1">${atis.callsign || 'Unknown'}</span>
                <span class="badge badge-atis ml-1">${atis.atis_code || '?'}</span>
                <span class="badge badge-dark ml-1">${fetchedTime}</span>
                <span class="badge ml-1" style="background-color: ${ageColor}; color: #fff;">${formatAgeText(atis.age_mins)}</span>
            </div>
            <div class="border rounded p-2" style="background: #f8f9fa; font-family: monospace; font-size: 0.8rem; white-space: pre-wrap; max-height: 150px; overflow-y: auto;">
${atis.atis_text || 'No ATIS text available'}
            </div>
        </div>
    `;
}

/**
 * Show ATIS details modal with full ATIS text
 * Displays all available ATIS types (ARR, DEP, COMB)
 */
function showAtisModal() {
    const atisData = DEMAND_STATE.atisData;

    if (!atisData || !atisData.has_atis) {
        Swal.fire({
            icon: 'info',
            title: 'No ATIS Available',
            text: 'No ATIS information is currently available for this airport.',
            timer: 3000,
            showConfirmButton: false
        });
        return;
    }

    const effectiveSource = atisData.effective_source;
    const atisArr = atisData.atis_arr;
    const atisDep = atisData.atis_dep;
    const atisComb = atisData.atis_comb;
    const runways = atisData.runways;

    // Build runway details HTML
    let runwayDetailsHtml = '';
    if (runways && runways.details && runways.details.length > 0) {
        runwayDetailsHtml = '<div class="mt-3"><strong>Runway Details:</strong><ul class="mb-0 pl-3">';
        runways.details.forEach(rwy => {
            const useLabel = rwy.runway_use === 'ARR' ? 'Arrivals' : (rwy.runway_use === 'DEP' ? 'Departures' : 'Both');
            const approach = rwy.approach_type ? ` (${rwy.approach_type})` : '';
            runwayDetailsHtml += `<li><strong>${rwy.runway_id}</strong>: ${useLabel}${approach}</li>`;
        });
        runwayDetailsHtml += '</ul></div>';
    }

    // Build ATIS sections based on what's available
    let atisSectionsHtml = '';
    let titleSuffix = '';

    if (effectiveSource === 'COMB' && atisComb) {
        // Combined ATIS only
        atisSectionsHtml = buildAtisSection(atisComb, 'COMBINED', '#6366f1');
        titleSuffix = atisComb.atis_code || '';
    } else if (effectiveSource === 'ARR_DEP') {
        // Both ARR and DEP available
        if (atisArr) {
            atisSectionsHtml += buildAtisSection(atisArr, 'ARRIVAL', '#22c55e');
        }
        if (atisDep) {
            atisSectionsHtml += buildAtisSection(atisDep, 'DEPARTURE', '#f97316');
        }
        const codes = [];
        if (atisArr?.atis_code) codes.push('A:' + atisArr.atis_code);
        if (atisDep?.atis_code) codes.push('D:' + atisDep.atis_code);
        titleSuffix = codes.join(' / ');
    } else if (effectiveSource === 'ARR_ONLY' && atisArr) {
        // Arrival only
        atisSectionsHtml = buildAtisSection(atisArr, 'ARRIVAL', '#22c55e');
        titleSuffix = atisArr.atis_code || '';
    } else if (effectiveSource === 'DEP_ONLY' && atisDep) {
        // Departure only
        atisSectionsHtml = buildAtisSection(atisDep, 'DEPARTURE', '#f97316');
        titleSuffix = atisDep.atis_code || '';
    } else {
        // Fallback to primary atis object
        const atis = atisData.atis;
        if (atis) {
            const typeLabel = atis.atis_type === 'ARR' ? 'ARRIVAL' : (atis.atis_type === 'DEP' ? 'DEPARTURE' : 'COMBINED');
            const typeColor = atis.atis_type === 'ARR' ? '#22c55e' : (atis.atis_type === 'DEP' ? '#f97316' : '#6366f1');
            atisSectionsHtml = buildAtisSection(atis, typeLabel, typeColor);
            titleSuffix = atis.atis_code || '';
        }
    }

    // Source indicator
    const sourceLabel = {
        'COMB': 'Combined ATIS',
        'ARR_DEP': 'Separate ARR/DEP ATIS',
        'ARR_ONLY': 'Arrival ATIS Only',
        'DEP_ONLY': 'Departure ATIS Only'
    }[effectiveSource] || 'Unknown';

    Swal.fire({
        title: `<i class="fas fa-broadcast-tower mr-2"></i> ${atisData.airport_icao} ATIS ${titleSuffix}`,
        html: `
            <div class="text-left">
                <div class="mb-3 text-center">
                    <span class="badge badge-secondary">${sourceLabel}</span>
                </div>
                <div class="mb-3">
                    <strong>Arrival Runways:</strong> ${runways?.arr_runways || 'N/A'}<br>
                    <strong>Departure Runways:</strong> ${runways?.dep_runways || 'N/A'}<br>
                    <strong>Approaches:</strong> ${runways?.approach_info || 'N/A'}
                </div>
                ${runwayDetailsHtml}
                <hr>
                <div class="mt-3">
                    <strong>ATIS Information:</strong>
                </div>
                <div class="mt-2">
                    ${atisSectionsHtml}
                </div>
            </div>
        `,
        width: 650,
        showCloseButton: true,
        showConfirmButton: false,
        customClass: {
            popup: 'text-left'
        }
    });
}

/**
 * Render the demand chart with data - TBFM/FSM style with true time axis
 */
function renderChart(data) {
    if (!DEMAND_STATE.chart) {
        console.error('Chart not initialized');
        return;
    }

    // Hide loading indicator
    DEMAND_STATE.chart.hideLoading();

    const arrivals = data.data.arrivals || [];
    const departures = data.data.departures || [];
    const direction = DEMAND_STATE.direction;

    // Generate complete time bins for the entire range (no gaps)
    const timeBins = generateAllTimeBins();

    // Store for drill-down
    DEMAND_STATE.timeBins = timeBins;

    // Create lookup maps for data by time bin (normalized to match generated bins)
    // Note: PHP formats as "Y-m-d\TH:i:s\Z" (no milliseconds), JS toISOString includes .000Z
    // So we need to normalize both to the same format
    const normalizeTimeBin = (bin) => {
        const d = new Date(bin);
        d.setUTCSeconds(0, 0);
        // Return format matching PHP: "2026-01-10T14:00:00Z" (no milliseconds)
        return d.toISOString().replace('.000Z', 'Z');
    };

    // Build series based on direction
    // Phase stacking order (bottom to top): arrived, descending, enroute, departed, taxiing, prefile, unknown
    const series = [];
    const phaseOrder = ['arrived', 'disconnected', 'descending', 'enroute', 'departed', 'taxiing', 'prefile', 'unknown'];

    if (direction === 'arr' || direction === 'both') {
        // Build arrival series by phase (normalize time bins for lookup)
        const arrivalsByBin = {};
        arrivals.forEach(d => { arrivalsByBin[normalizeTimeBin(d.time_bin)] = d.breakdown; });

        // Add series in stacking order (bottom to top)
        phaseOrder.forEach(phase => {
            const suffix = direction === 'both' ? ' (Arr)' : '';
            series.push(
                buildPhaseSeriesTimeAxis(FSM_PHASE_LABELS[phase] + suffix, timeBins, arrivalsByBin, phase, 'arrivals', direction)
            );
        });
    }

    if (direction === 'dep' || direction === 'both') {
        // Build departure series by phase (normalize time bins for lookup)
        const departuresByBin = {};
        departures.forEach(d => { departuresByBin[normalizeTimeBin(d.time_bin)] = d.breakdown; });

        // Add series in stacking order (bottom to top)
        phaseOrder.forEach(phase => {
            const suffix = direction === 'both' ? ' (Dep)' : '';
            series.push(
                buildPhaseSeriesTimeAxis(FSM_PHASE_LABELS[phase] + suffix, timeBins, departuresByBin, phase, 'departures', direction)
            );
        });
    }

    // Add current time marker, rate lines, and TMI program markers to first series
    const timeMarkLineData = getCurrentTimeMarkLineForTimeAxis();
    const rateMarkLines = (DEMAND_STATE.scheduledConfigs && DEMAND_STATE.scheduledConfigs.length > 0)
        ? buildTimeBoundedRateMarkLines()
        : buildRateMarkLinesForChart();
    const tmiProgramMarkLines = buildTmiProgramMarkLines();

    if (series.length > 0) {
        const markLineData = [];

        // Add TMI program markers first (GS/GDP vertical lines - behind other markers)
        if (tmiProgramMarkLines && tmiProgramMarkLines.length > 0) {
            markLineData.push(...tmiProgramMarkLines);
        }

        // Add time marker (now returns a single data item with embedded label)
        if (timeMarkLineData) {
            markLineData.push(timeMarkLineData);
        }

        // Add rate lines
        if (rateMarkLines && rateMarkLines.length > 0) {
            markLineData.push(...rateMarkLines);
        }

        if (markLineData.length > 0) {
            series[0].markLine = {
                silent: true,
                symbol: ['none', 'none'],
                data: markLineData
            };
        }
    }

    // Calculate interval for x-axis bounds
    const intervalMs = getGranularityMinutes() * 60 * 1000;
    const proRateFactor = getGranularityMinutes() / 60;

    // Build chart title - FSM/TBFM style: Airport (left) | Date (center) | Time (right)
    const chartTitle = buildChartTitle(data.airport, data.last_adl_update);

    // Calculate y-axis max to ensure rate lines are visible AND all data is captured

    // Calculate max demand from data series
    let maxDemand = 0;
    const countDemandInBin = (breakdown) => {
        if (!breakdown) return 0;
        return Object.values(breakdown).reduce((sum, val) => sum + (typeof val === 'number' ? val : 0), 0);
    };
    arrivals.forEach(d => {
        const binTotal = countDemandInBin(d.breakdown);
        if (binTotal > maxDemand) maxDemand = binTotal;
    });
    departures.forEach(d => {
        const binTotal = countDemandInBin(d.breakdown);
        if (binTotal > maxDemand) maxDemand = binTotal;
    });
    // If showing both directions stacked, consider combined total
    if (direction === 'both') {
        const combinedMax = Math.max(...timeBins.map(bin => {
            const normalizedBin = new Date(bin);
            normalizedBin.setUTCSeconds(0, 0);
            const binKey = normalizedBin.toISOString().replace('.000Z', 'Z');
            const arrData = arrivals.find(d => {
                const dBin = new Date(d.time_bin);
                dBin.setUTCSeconds(0, 0);
                return dBin.toISOString().replace('.000Z', 'Z') === binKey;
            });
            const depData = departures.find(d => {
                const dBin = new Date(d.time_bin);
                dBin.setUTCSeconds(0, 0);
                return dBin.toISOString().replace('.000Z', 'Z') === binKey;
            });
            return countDemandInBin(arrData?.breakdown) + countDemandInBin(depData?.breakdown);
        }));
        if (combinedMax > maxDemand) maxDemand = combinedMax;
    }

    // Calculate max rate (pro-rated for granularity)
    let maxRate = 0;
    if (DEMAND_STATE.rateData && DEMAND_STATE.rateData.rates) {
        const rates = DEMAND_STATE.rateData.rates;
        maxRate = Math.max(
            (rates.vatsim_aar || 0) * proRateFactor,
            (rates.vatsim_adr || 0) * proRateFactor,
            (rates.rw_aar || 0) * proRateFactor,
            (rates.rw_adr || 0) * proRateFactor
        );
    }

    // Use the higher of max demand or max rate, with padding
    // Round to logical intervals (multiples of 5 for small values, 10 for larger)
    let yAxisMax = null;
    const effectiveMax = Math.max(maxDemand, maxRate);
    if (effectiveMax > 0) {
        const padded = effectiveMax * 1.15; // 15% padding
        // Round up to nearest logical interval
        if (padded <= 10) {
            yAxisMax = Math.ceil(padded);
        } else if (padded <= 50) {
            yAxisMax = Math.ceil(padded / 5) * 5; // Round to nearest 5
        } else {
            yAxisMax = Math.ceil(padded / 10) * 10; // Round to nearest 10
        }
    }

    // Chart options - TBFM/FSM/AADC style with TRUE TIME AXIS
    const option = {
        backgroundColor: '#ffffff',
        title: {
            text: chartTitle,
            left: 'center',
            top: 10,
            textStyle: {
                fontSize: 14,
                fontWeight: 'bold',
                color: '#333',
                fontFamily: '"Inconsolata", "SF Mono", monospace'
            }
        },
        tooltip: {
            trigger: 'axis',
            axisPointer: {
                type: 'shadow'
            },
            backgroundColor: 'rgba(255, 255, 255, 0.98)',
            borderColor: '#ccc',
            borderWidth: 1,
            padding: [8, 12],
            textStyle: {
                color: '#333',
                fontSize: 12
            },
            formatter: function(params) {
                if (!params || params.length === 0) return '';
                // Extract timestamp from first param
                const timestamp = params[0].value[0];
                const timeStr = formatTimeLabelFromTimestamp(timestamp);
                let tooltip = `<strong style="font-size:13px;">${timeStr}</strong><br/>`;
                let total = 0;
                params.forEach(p => {
                    const val = p.value[1] || 0;
                    if (val > 0) {
                        tooltip += `${p.marker} ${p.seriesName}: <strong>${val}</strong><br/>`;
                        total += val;
                    }
                });
                tooltip += `<hr style="margin:4px 0;border-color:#ddd;"/><strong>Total: ${total}</strong>`;
                // Add rate information if available
                const rates = getRatesForTimestamp(timestamp);
                if (rates && (rates.aar || rates.adr)) {
                    tooltip += `<hr style="margin:4px 0;border-color:#ddd;"/>`;
                    if (rates.aar) tooltip += `<span style="color:#000;">AAR: <strong>${rates.aar}</strong></span>`;
                    if (rates.aar && rates.adr) tooltip += ` / `;
                    if (rates.adr) tooltip += `<span style="color:#000;">ADR: <strong>${rates.adr}</strong></span>`;
                }
                return tooltip;
            }
        },
        legend: direction === 'both' ? [
            {
                // Arrivals row (circles)
                bottom: 115,
                left: 'center',
                width: '85%',  // Allow wrapping
                type: 'scroll',
                itemWidth: 14,
                itemHeight: 10,
                itemGap: 12,   // Space between items
                textStyle: { fontSize: 11, fontFamily: '"Segoe UI", sans-serif' },
                data: series.filter(s => s.name.includes('(Arr)')).map(s => s.name),
                formatter: function(name) { return name.replace(' (Arr)', ''); }
            },
            {
                // Departures row (rectangles)
                bottom: 90,
                left: 'center',
                width: '85%',  // Allow wrapping
                type: 'scroll',
                itemWidth: 14,
                itemHeight: 10,
                itemGap: 12,   // Space between items
                textStyle: { fontSize: 11, fontFamily: '"Segoe UI", sans-serif' },
                icon: 'rect',
                data: series.filter(s => s.name.includes('(Dep)')).map(s => s.name),
                formatter: function(name) { return name.replace(' (Dep)', ''); }
            }
        ] : {
            bottom: 70,  // Single row - no overlap issue
            left: 'center',
            width: '85%',  // Allow wrapping
            type: 'scroll',
            itemWidth: 14,
            itemHeight: 10,
            itemGap: 12,   // Space between items
            textStyle: { fontSize: 11, fontFamily: '"Segoe UI", sans-serif' }
        },
        // DataZoom sliders for customizable time/demand ranges
        dataZoom: getDataZoomConfig(),
        grid: {
            left: 55,
            right: 70,   // Room for AAR/ADR labels
            bottom: direction === 'both' ? 165 : 140, // Extra room for 2 legend rows
            top: 55,
            containLabel: false
        },
        xAxis: {
            type: 'time',
            name: getXAxisLabel(),
            nameLocation: 'middle',
            nameGap: direction === 'both' ? 45 : 30, // Extra gap for 2 legend rows
            nameTextStyle: {
                fontSize: 11,
                color: '#333',
                fontWeight: 500
            },
            maxInterval: 3600 * 1000,  // Maximum 1 hour between labels
            axisLine: {
                lineStyle: {
                    color: '#333',
                    width: 1
                }
            },
            axisTick: {
                alignWithLabel: true,
                lineStyle: {
                    color: '#666'
                }
            },
            axisLabel: {
                fontSize: 11,
                color: '#333',
                fontFamily: '"Inconsolata", "SF Mono", monospace',
                fontWeight: function(value) {
                    // Emphasize 00Z and 12Z with bold
                    const d = new Date(value);
                    const h = d.getUTCHours();
                    return (h === 0 || h === 12) ? 'bold' : 500;
                },
                formatter: function(value) {
                    const d = new Date(value);
                    const h = d.getUTCHours().toString().padStart(2, '0');
                    const m = d.getUTCMinutes().toString().padStart(2, '0');
                    // AADC style: "1200Z", "1300Z", etc.
                    return h + m + 'Z';
                }
            },
            splitLine: {
                show: true,
                lineStyle: {
                    color: '#f0f0f0',
                    type: 'solid'
                }
            },
            min: new Date(timeBins[0]).getTime(),
            max: new Date(timeBins[timeBins.length - 1]).getTime() + intervalMs
        },
        yAxis: {
            type: 'value',
            name: 'Demand',
            nameLocation: 'middle',
            nameGap: 40,
            nameTextStyle: {
                fontSize: 12,
                color: '#333',
                fontWeight: 500
            },
            minInterval: 1,
            min: 0,
            max: yAxisMax, // Will be null (auto) if no rates, or calculated to fit rate lines
            axisLine: {
                show: true,
                lineStyle: {
                    color: '#333',
                    width: 1
                }
            },
            axisTick: {
                show: true,
                lineStyle: {
                    color: '#666'
                }
            },
            axisLabel: {
                fontSize: 11,
                color: '#333',
                fontFamily: '"Inconsolata", monospace'
            },
            splitLine: {
                show: true,
                lineStyle: {
                    color: '#e8e8e8',
                    type: 'dashed'
                }
            }
        },
        series: series
    };

    DEMAND_STATE.chart.setOption(option, true);

    // Add click handler for drill-down
    DEMAND_STATE.chart.off('click'); // Remove previous handler
    DEMAND_STATE.chart.on('click', function(params) {
        if (params.componentType === 'series' && params.value) {
            // Extract timestamp from [timestamp, value] pair
            const timestamp = params.value[0];
            const timeBin = new Date(timestamp).toISOString();
            if (timeBin) {
                // Pass the clicked series name for emphasis in flight list
                showFlightDetails(timeBin, params.seriesName);
            }
        }
    });
}

/**
 * Render chart with origin ARTCC breakdown - TBFM/FSM style with TRUE TIME AXIS
 */
function renderOriginChart() {
    if (!DEMAND_STATE.chart) {
        console.error('Chart not initialized');
        return;
    }

    // Hide loading indicator
    DEMAND_STATE.chart.hideLoading();

    const originBreakdown = DEMAND_STATE.originBreakdown || {};
    const data = DEMAND_STATE.lastDemandData;

    if (!data) {
        console.error('No demand data available');
        return;
    }

    // Generate complete time bins for the entire range (no gaps)
    const timeBins = generateAllTimeBins();

    // Store for drill-down
    DEMAND_STATE.timeBins = timeBins;

    // Normalize time bin helper for lookup (match PHP format without milliseconds)
    const normalizeTimeBin = (bin) => {
        const d = new Date(bin);
        d.setUTCSeconds(0, 0);
        return d.toISOString().replace('.000Z', 'Z');
    };

    // Round to hour helper - breakdown data from API is always hourly
    const roundToHour = (bin) => {
        const d = new Date(bin);
        d.setUTCMinutes(0, 0, 0);
        return d.toISOString().replace('.000Z', 'Z');
    };

    // Collect all unique ARTCCs
    const allARTCCs = new Set();
    for (const bin in originBreakdown) {
        const artccData = originBreakdown[bin];
        if (Array.isArray(artccData)) {
            artccData.forEach(item => allARTCCs.add(item.artcc));
        }
    }
    const artccList = Array.from(allARTCCs).sort();

    // Calculate interval in milliseconds
    const intervalMs = getGranularityMinutes() * 60 * 1000;
    const halfInterval = intervalMs / 2;

    // Build series for each ARTCC with TRUE TIME AXIS data format
    // Shift by half interval so bars are centered on the time period
    const series = artccList.map(artcc => {
        const seriesData = timeBins.map(bin => {
            // Breakdown data is always hourly, so try hourly lookup first
            const hourlyBin = roundToHour(bin);
            const binData = originBreakdown[hourlyBin] || originBreakdown[normalizeTimeBin(bin)] || [];
            const artccEntry = Array.isArray(binData) ? binData.find(item => item.artcc === artcc) : null;
            const value = artccEntry ? artccEntry.count : 0;
            // Center the bar on the time period (start + half interval)
            return [new Date(bin).getTime() + halfInterval, value];
        });

        return {
            name: artcc,
            type: 'bar',
            stack: 'origin',
            barWidth: '70%', // Percentage of available space per bin
            barGap: '0%',
            emphasis: {
                focus: 'series',
                itemStyle: {
                    shadowBlur: 2,
                    shadowColor: 'rgba(0,0,0,0.2)'
                }
            },
            itemStyle: {
                color: getARTCCColor(artcc),
                borderColor: 'transparent', // No borders - AADC style
                borderWidth: 0
            },
            data: seriesData
        };
    });

    // Add current time marker, rate lines, and TMI program markers to first series
    const timeMarkLineData = getCurrentTimeMarkLineForTimeAxis();
    const rateMarkLines = (DEMAND_STATE.scheduledConfigs && DEMAND_STATE.scheduledConfigs.length > 0)
        ? buildTimeBoundedRateMarkLines()
        : buildRateMarkLinesForChart();
    const tmiProgramMarkLines = buildTmiProgramMarkLines();

    if (series.length > 0) {
        const markLineData = [];
        // Add TMI program markers first (GS/GDP vertical lines - behind other markers)
        if (tmiProgramMarkLines && tmiProgramMarkLines.length > 0) markLineData.push(...tmiProgramMarkLines);
        if (timeMarkLineData) markLineData.push(timeMarkLineData);
        if (rateMarkLines && rateMarkLines.length > 0) markLineData.push(...rateMarkLines);

        if (markLineData.length > 0) {
            series[0].markLine = {
                silent: true,
                symbol: ['none', 'none'],
                data: markLineData
            };
        }
    }

    // Build chart title - FSM/TBFM style: Airport (left) | Date (center) | Time (right)
    const chartTitle = buildChartTitle(data.airport, data.last_adl_update);

    // Calculate y-axis max to ensure rate lines are visible
    let yAxisMax = null; // null = auto-scale
    if (DEMAND_STATE.rateData && DEMAND_STATE.rateData.rates) {
        const rates = DEMAND_STATE.rateData.rates;
        const maxRate = Math.max(
            rates.vatsim_aar || 0,
            rates.rw_aar || 0
        );
        if (maxRate > 0) {
            yAxisMax = Math.ceil(maxRate * 1.1);
        }
    }

    // Chart options - TBFM/FSM/AADC style with TRUE TIME AXIS
    const option = {
        backgroundColor: '#ffffff',
        title: {
            text: chartTitle,
            subtext: 'Arrivals by Origin ARTCC',
            left: 'center',
            top: 5,
            textStyle: {
                fontSize: 14,
                fontWeight: 'bold',
                color: '#333',
                fontFamily: '"Inconsolata", "SF Mono", monospace'
            },
            subtextStyle: {
                fontSize: 11,
                color: '#666'
            }
        },
        tooltip: {
            trigger: 'axis',
            axisPointer: {
                type: 'shadow'
            },
            backgroundColor: 'rgba(255, 255, 255, 0.98)',
            borderColor: '#ccc',
            borderWidth: 1,
            padding: [8, 12],
            textStyle: {
                color: '#333',
                fontSize: 12
            },
            formatter: function(params) {
                if (!params || params.length === 0) return '';
                const timestamp = params[0].value[0];
                const timeStr = formatTimeLabelFromTimestamp(timestamp);
                let tooltip = `<strong style="font-size:13px;">${timeStr}</strong><br/>`;
                let total = 0;
                // Sort by value descending
                const sorted = [...params].sort((a, b) => (b.value[1] || 0) - (a.value[1] || 0));
                sorted.forEach(p => {
                    const val = p.value[1] || 0;
                    if (val > 0) {
                        tooltip += `${p.marker} ${p.seriesName}: <strong>${val}</strong><br/>`;
                        total += val;
                    }
                });
                tooltip += `<hr style="margin:4px 0;border-color:#ddd;"/><strong>Total: ${total}</strong>`;
                // Add rate information if available
                const rates = getRatesForTimestamp(timestamp);
                if (rates && (rates.aar || rates.adr)) {
                    tooltip += `<hr style="margin:4px 0;border-color:#ddd;"/>`;
                    if (rates.aar) tooltip += `<span style="color:#000;">AAR: <strong>${rates.aar}</strong></span>`;
                    if (rates.aar && rates.adr) tooltip += ` / `;
                    if (rates.adr) tooltip += `<span style="color:#000;">ADR: <strong>${rates.adr}</strong></span>`;
                }
                return tooltip;
            }
        },
        legend: {
            bottom: 75,  // Above sliders
            left: 'center',
            width: '85%',  // Allow wrapping
            type: 'scroll',
            itemWidth: 14,
            itemHeight: 10,
            itemGap: 12,   // Space between items
            textStyle: {
                fontSize: 11,
                fontFamily: '"Segoe UI", sans-serif'
            }
        },
        // DataZoom sliders for customizable time/demand ranges
        dataZoom: getDataZoomConfig(),
        grid: {
            left: 55,
            right: 70,   // Room for AAR/ADR labels
            bottom: 145, // Room for slider + legend (with wrapping)
            top: 55,
            containLabel: false
        },
        xAxis: {
            type: 'time',
            name: getXAxisLabel(),
            nameLocation: 'middle',
            nameGap: 35,  // More space to avoid legend overlap
            nameTextStyle: {
                fontSize: 11,
                color: '#333',
                fontWeight: 500
            },
            maxInterval: 3600 * 1000,  // Maximum 1 hour between labels
            axisLine: {
                lineStyle: {
                    color: '#333',
                    width: 1
                }
            },
            axisTick: {
                alignWithLabel: true,
                lineStyle: {
                    color: '#666'
                }
            },
            axisLabel: {
                fontSize: 11,
                color: '#333',
                fontFamily: '"Inconsolata", "SF Mono", monospace',
                fontWeight: function(value) {
                    // Emphasize 00Z and 12Z with bold
                    const d = new Date(value);
                    const h = d.getUTCHours();
                    return (h === 0 || h === 12) ? 'bold' : 500;
                },
                formatter: function(value) {
                    const d = new Date(value);
                    const h = d.getUTCHours().toString().padStart(2, '0');
                    const m = d.getUTCMinutes().toString().padStart(2, '0');
                    // AADC style: "1200Z", "1300Z", etc.
                    return h + m + 'Z';
                }
            },
            splitLine: {
                show: true,
                lineStyle: {
                    color: '#f0f0f0',
                    type: 'solid'
                }
            },
            min: new Date(timeBins[0]).getTime(),
            max: new Date(timeBins[timeBins.length - 1]).getTime() + intervalMs
        },
        yAxis: {
            type: 'value',
            name: 'Demand',
            nameLocation: 'middle',
            nameGap: 40,
            nameTextStyle: {
                fontSize: 12,
                color: '#333',
                fontWeight: 500
            },
            minInterval: 1,
            min: 0,
            max: yAxisMax, // Will be null (auto) if no rates, or calculated to fit rate lines
            axisLine: {
                show: true,
                lineStyle: {
                    color: '#333',
                    width: 1
                }
            },
            axisTick: {
                show: true,
                lineStyle: {
                    color: '#666'
                }
            },
            axisLabel: {
                fontSize: 11,
                color: '#333',
                fontFamily: '"Inconsolata", monospace'
            },
            splitLine: {
                show: true,
                lineStyle: {
                    color: '#e8e8e8',
                    type: 'dashed'
                }
            }
        },
        series: series
    };

    DEMAND_STATE.chart.setOption(option, true);

    // Add click handler for drill-down
    DEMAND_STATE.chart.off('click');
    DEMAND_STATE.chart.on('click', function(params) {
        if (params.componentType === 'series' && params.value) {
            const timestamp = params.value[0];
            const timeBin = new Date(timestamp).toISOString();
            if (timeBin) {
                showFlightDetails(timeBin, params.seriesName);
            }
        }
    });
}

/**
 * Generic render function for breakdown charts
 * Used by renderDestChart, renderCarrierChart, renderWeightChart, etc.
 * @param {Object} breakdownData - Breakdown data by time bin
 * @param {string} subtitle - Chart subtitle (e.g., "Arrivals by Carrier")
 * @param {string} stackName - Stack name for series
 * @param {string} categoryKey - Key in breakdown items for category name
 * @param {Function} colorFn - Function to get color for a category
 * @param {Function} labelFn - Optional function to get display label
 * @param {Array} order - Optional array specifying category order
 */
function renderBreakdownChart(breakdownData, subtitle, stackName, categoryKey, colorFn, labelFn, order) {
    if (!DEMAND_STATE.chart) {
        console.error('Chart not initialized');
        return;
    }

    DEMAND_STATE.chart.hideLoading();

    const breakdown = breakdownData || {};
    const data = DEMAND_STATE.lastDemandData;

    // Debug: Log breakdown chart rendering info
    console.log('[Demand] renderBreakdownChart:', stackName, 'categoryKey:', categoryKey,
        'breakdownBins:', Object.keys(breakdown).length,
        'sampleKeys:', Object.keys(breakdown).slice(0, 3));

    if (!data) {
        console.error('No demand data available');
        return;
    }

    // Generate complete time bins
    const timeBins = generateAllTimeBins();
    DEMAND_STATE.timeBins = timeBins;

    // Normalize time bin helper (preserves minutes)
    const normalizeTimeBin = (bin) => {
        const d = new Date(bin);
        d.setUTCSeconds(0, 0);
        return d.toISOString().replace('.000Z', 'Z');
    };

    // Round to hour helper - breakdown data from API is always hourly
    const roundToHour = (bin) => {
        const d = new Date(bin);
        d.setUTCMinutes(0, 0, 0);
        return d.toISOString().replace('.000Z', 'Z');
    };

    // Collect all unique categories
    const allCategories = new Set();
    for (const bin in breakdown) {
        const catData = breakdown[bin];
        if (Array.isArray(catData)) {
            catData.forEach(item => allCategories.add(item[categoryKey]));
        }
    }

    // Debug: Log categories found
    console.log('[Demand] renderBreakdownChart categories found:', Array.from(allCategories));

    // Sort categories - use order if provided, otherwise alphabetical
    let categoryList;
    if (order && order.length > 0) {
        categoryList = order.filter(cat => allCategories.has(cat));
        // Add any categories not in order
        allCategories.forEach(cat => {
            if (!categoryList.includes(cat)) categoryList.push(cat);
        });
    } else {
        categoryList = Array.from(allCategories).sort();
    }

    // Calculate interval
    const intervalMs = getGranularityMinutes() * 60 * 1000;
    const halfInterval = intervalMs / 2;

    // Build series
    const series = categoryList.map(category => {
        const seriesData = timeBins.map(bin => {
            // Try hourly lookup first (breakdown data is always hourly), then exact match
            const hourlyBin = roundToHour(bin);
            const binData = breakdown[hourlyBin] || breakdown[normalizeTimeBin(bin)] || [];
            const catEntry = Array.isArray(binData) ? binData.find(item => item[categoryKey] === category) : null;
            const value = catEntry ? catEntry.count : 0;
            return [new Date(bin).getTime() + halfInterval, value];
        });

        const displayLabel = labelFn ? labelFn(category) : category;

        return {
            name: displayLabel,
            type: 'bar',
            stack: stackName,
            barWidth: '70%',
            barGap: '0%',
            emphasis: {
                focus: 'series',
                itemStyle: {
                    shadowBlur: 2,
                    shadowColor: 'rgba(0,0,0,0.2)'
                }
            },
            itemStyle: {
                color: colorFn(category),
                borderColor: 'transparent',
                borderWidth: 0
            },
            data: seriesData
        };
    });

    // Add time marker, rate lines, and TMI program markers
    const timeMarkLineData = getCurrentTimeMarkLineForTimeAxis();
    const rateMarkLines = (DEMAND_STATE.scheduledConfigs && DEMAND_STATE.scheduledConfigs.length > 0)
        ? buildTimeBoundedRateMarkLines()
        : buildRateMarkLinesForChart();
    const tmiProgramMarkLines = buildTmiProgramMarkLines();

    if (series.length > 0) {
        const markLineData = [];
        // Add TMI program markers first (GS/GDP vertical lines - behind other markers)
        if (tmiProgramMarkLines && tmiProgramMarkLines.length > 0) markLineData.push(...tmiProgramMarkLines);
        if (timeMarkLineData) markLineData.push(timeMarkLineData);
        if (rateMarkLines && rateMarkLines.length > 0) markLineData.push(...rateMarkLines);

        if (markLineData.length > 0) {
            series[0].markLine = {
                silent: true,
                symbol: ['none', 'none'],
                data: markLineData
            };
        }
    }

    // Build chart title
    const chartTitle = buildChartTitle(data.airport, data.last_adl_update);

    // Calculate y-axis max from actual data and rates
    // Sum stacked values per bin to get total per time bin
    let maxDemand = 0;
    if (series.length > 0) {
        const binCount = timeBins.length;
        for (let i = 0; i < binCount; i++) {
            let binTotal = 0;
            series.forEach(s => {
                if (s.data && s.data[i]) {
                    binTotal += s.data[i][1] || 0;
                }
            });
            if (binTotal > maxDemand) maxDemand = binTotal;
        }
    }

    // Get max rate (if available) - pro-rate for granularity
    const proRateFactor = getGranularityMinutes() / 60;
    let maxRate = 0;
    if (DEMAND_STATE.rateData && DEMAND_STATE.rateData.rates) {
        const rates = DEMAND_STATE.rateData.rates;
        maxRate = Math.max(
            (rates.vatsim_aar || 0) * proRateFactor,
            (rates.vatsim_adr || 0) * proRateFactor,
            (rates.rw_aar || 0) * proRateFactor,
            (rates.rw_adr || 0) * proRateFactor
        );
    }

    // Use the higher of max demand or max rate, with padding
    let yAxisMax = null;
    const effectiveMax = Math.max(maxDemand, maxRate);
    if (effectiveMax > 0) {
        const padded = effectiveMax * 1.15; // 15% padding
        // Round up to nearest logical interval
        if (padded <= 10) {
            yAxisMax = Math.ceil(padded);
        } else if (padded <= 50) {
            yAxisMax = Math.ceil(padded / 5) * 5; // Round to nearest 5
        } else {
            yAxisMax = Math.ceil(padded / 10) * 10; // Round to nearest 10
        }
    }

    const option = {
        backgroundColor: '#ffffff',
        title: {
            text: chartTitle,
            subtext: subtitle,
            left: 'center',
            top: 5,
            textStyle: {
                fontSize: 14,
                fontWeight: 'bold',
                color: '#333',
                fontFamily: '"Inconsolata", "SF Mono", monospace'
            },
            subtextStyle: {
                fontSize: 11,
                color: '#666'
            }
        },
        tooltip: {
            trigger: 'axis',
            axisPointer: { type: 'shadow' },
            backgroundColor: 'rgba(255, 255, 255, 0.98)',
            borderColor: '#ccc',
            borderWidth: 1,
            padding: [8, 12],
            textStyle: { color: '#333', fontSize: 12 },
            formatter: function(params) {
                if (!params || params.length === 0) return '';
                const timestamp = params[0].value[0];
                const timeStr = formatTimeLabelFromTimestamp(timestamp);
                let tooltip = `<strong style="font-size:13px;">${timeStr}</strong><br/>`;
                let total = 0;
                const sorted = [...params].sort((a, b) => (b.value[1] || 0) - (a.value[1] || 0));
                sorted.forEach(p => {
                    const val = p.value[1] || 0;
                    if (val > 0) {
                        tooltip += `${p.marker} ${p.seriesName}: <strong>${val}</strong><br/>`;
                        total += val;
                    }
                });
                tooltip += `<hr style="margin:4px 0;border-color:#ddd;"/><strong>Total: ${total}</strong>`;
                // Add rate information if available
                const rates = getRatesForTimestamp(timestamp);
                if (rates && (rates.aar || rates.adr)) {
                    tooltip += `<hr style="margin:4px 0;border-color:#ddd;"/>`;
                    if (rates.aar) tooltip += `<span style="color:#000;">AAR: <strong>${rates.aar}</strong></span>`;
                    if (rates.aar && rates.adr) tooltip += ` / `;
                    if (rates.adr) tooltip += `<span style="color:#000;">ADR: <strong>${rates.adr}</strong></span>`;
                }
                return tooltip;
            }
        },
        legend: {
            bottom: 75,  // Above sliders
            left: 'center',
            width: '85%',  // Allow wrapping
            type: 'scroll',
            itemWidth: 14,
            itemHeight: 10,
            itemGap: 12,   // Space between items
            textStyle: {
                fontSize: 11,
                fontFamily: '"Segoe UI", sans-serif'
            }
        },
        // DataZoom sliders for customizable time/demand ranges
        dataZoom: getDataZoomConfig(),
        grid: {
            left: 55,
            right: 70,   // Room for AAR/ADR labels
            bottom: 145, // Room for slider + legend (with wrapping)
            top: 55,
            containLabel: false
        },
        xAxis: {
            type: 'time',
            name: getXAxisLabel(),
            nameLocation: 'middle',
            nameGap: 35,  // More space to avoid legend overlap
            nameTextStyle: { fontSize: 11, color: '#333', fontWeight: 500 },
            maxInterval: 3600 * 1000,
            axisLine: { lineStyle: { color: '#333', width: 1 } },
            axisTick: { lineStyle: { color: '#666' } },
            axisLabel: {
                fontSize: 11,
                color: '#333',
                fontFamily: '"Inconsolata", "SF Mono", monospace',
                fontWeight: function(value) {
                    const d = new Date(value);
                    const h = d.getUTCHours();
                    return (h === 0 || h === 12) ? 'bold' : 500;
                },
                formatter: function(value) {
                    const d = new Date(value);
                    const h = d.getUTCHours().toString().padStart(2, '0');
                    const m = d.getUTCMinutes().toString().padStart(2, '0');
                    return h + m + 'Z';
                }
            },
            splitLine: { show: true, lineStyle: { color: '#f0f0f0', type: 'solid' } },
            min: new Date(timeBins[0]).getTime(),
            max: new Date(timeBins[timeBins.length - 1]).getTime() + intervalMs
        },
        yAxis: {
            type: 'value',
            name: 'Demand',
            nameLocation: 'middle',
            nameGap: 40,
            nameTextStyle: { fontSize: 12, color: '#333', fontWeight: 500 },
            minInterval: 1,
            min: 0,
            max: yAxisMax,
            axisLine: { show: true, lineStyle: { color: '#333', width: 1 } },
            axisTick: { show: true, lineStyle: { color: '#666' } },
            axisLabel: { fontSize: 11, color: '#333', fontFamily: '"Inconsolata", monospace' },
            splitLine: { show: true, lineStyle: { color: '#e8e8e8', type: 'dashed' } }
        },
        series: series
    };

    DEMAND_STATE.chart.setOption(option, true);

    // Add click handler
    DEMAND_STATE.chart.off('click');
    DEMAND_STATE.chart.on('click', function(params) {
        if (params.componentType === 'series' && params.value) {
            const timestamp = params.value[0];
            const timeBin = new Date(timestamp).toISOString();
            if (timeBin) {
                showFlightDetails(timeBin, params.seriesName);
            }
        }
    });
}

/**
 * Render chart with destination ARTCC breakdown
 */
function renderDestChart() {
    const direction = DEMAND_STATE.direction;
    const dirLabel = direction === 'arr' ? 'Arrivals' : (direction === 'dep' ? 'Departures' : 'Flights');
    renderBreakdownChart(
        DEMAND_STATE.destBreakdown,
        `${dirLabel} by Destination ARTCC`,
        'dest',
        'artcc',
        (artcc) => typeof getDCCRegionColor === 'function' ? getDCCRegionColor(artcc) : getARTCCColor(artcc),
        null,
        null
    );
}

/**
 * Render chart with carrier breakdown (top carriers + OTHER)
 */
function renderCarrierChart() {
    const direction = DEMAND_STATE.direction;
    const dirLabel = direction === 'arr' ? 'Arrivals' : (direction === 'dep' ? 'Departures' : 'Flights');
    renderBreakdownChart(
        DEMAND_STATE.carrierBreakdown,
        `${dirLabel} by Carrier`,
        'carrier',
        'carrier',
        (carrier) => {
            if (typeof FILTER_CONFIG !== 'undefined' && FILTER_CONFIG.carrier && FILTER_CONFIG.carrier.colors) {
                return FILTER_CONFIG.carrier.colors[carrier] || FILTER_CONFIG.carrier.colors['OTHER'] || '#6c757d';
            }
            return '#6c757d';
        },
        (carrier) => {
            if (typeof FILTER_CONFIG !== 'undefined' && FILTER_CONFIG.carrier && FILTER_CONFIG.carrier.labels) {
                return FILTER_CONFIG.carrier.labels[carrier] || carrier;
            }
            return carrier;
        },
        null
    );
}

/**
 * Render chart with weight class breakdown (S/L/H/J)
 */
function renderWeightChart() {
    const direction = DEMAND_STATE.direction;
    const dirLabel = direction === 'arr' ? 'Arrivals' : (direction === 'dep' ? 'Departures' : 'Flights');

    let order = ['J', 'H', 'L', 'S', 'UNKNOWN'];
    if (typeof FILTER_CONFIG !== 'undefined' && FILTER_CONFIG.weightClass && FILTER_CONFIG.weightClass.order) {
        order = FILTER_CONFIG.weightClass.order;
    }

    renderBreakdownChart(
        DEMAND_STATE.weightBreakdown,
        `${dirLabel} by Weight Class`,
        'weight',
        'weight_class',
        (wc) => {
            if (typeof FILTER_CONFIG !== 'undefined' && FILTER_CONFIG.weightClass && FILTER_CONFIG.weightClass.colors) {
                return FILTER_CONFIG.weightClass.colors[wc] || FILTER_CONFIG.weightClass.colors['UNKNOWN'] || '#6c757d';
            }
            // Fallback colors
            const fallback = { 'J': '#ffc107', 'H': '#dc3545', 'L': '#28a745', 'S': '#17a2b8' };
            return fallback[wc] || '#6c757d';
        },
        (wc) => {
            if (typeof FILTER_CONFIG !== 'undefined' && FILTER_CONFIG.weightClass && FILTER_CONFIG.weightClass.labels) {
                return FILTER_CONFIG.weightClass.labels[wc] || wc;
            }
            const labels = { 'J': 'Super', 'H': 'Heavy', 'L': 'Large', 'S': 'Small' };
            return labels[wc] || wc;
        },
        order
    );
}

/**
 * Render chart with equipment/aircraft type breakdown
 */
function renderEquipmentChart() {
    const direction = DEMAND_STATE.direction;
    const dirLabel = direction === 'arr' ? 'Arrivals' : (direction === 'dep' ? 'Departures' : 'Flights');
    renderBreakdownChart(
        DEMAND_STATE.equipmentBreakdown,
        `${dirLabel} by Aircraft Type`,
        'equipment',
        'equipment',
        (acType) => {
            if (typeof FILTER_CONFIG !== 'undefined' && FILTER_CONFIG.equipment && FILTER_CONFIG.equipment.colors) {
                return FILTER_CONFIG.equipment.colors[acType] || FILTER_CONFIG.equipment.colors['OTHER'] || '#6c757d';
            }
            return '#6c757d';
        },
        null,
        null
    );
}

/**
 * Render chart with flight rule breakdown (IFR/VFR)
 */
function renderRuleChart() {
    const direction = DEMAND_STATE.direction;
    const dirLabel = direction === 'arr' ? 'Arrivals' : (direction === 'dep' ? 'Departures' : 'Flights');

    let order = ['I', 'V'];
    if (typeof FILTER_CONFIG !== 'undefined' && FILTER_CONFIG.flightRule && FILTER_CONFIG.flightRule.order) {
        order = FILTER_CONFIG.flightRule.order;
    }

    renderBreakdownChart(
        DEMAND_STATE.ruleBreakdown,
        `${dirLabel} by Flight Rule`,
        'rule',
        'rule',
        (rule) => {
            if (typeof FILTER_CONFIG !== 'undefined' && FILTER_CONFIG.flightRule && FILTER_CONFIG.flightRule.colors) {
                return FILTER_CONFIG.flightRule.colors[rule] || '#6c757d';
            }
            // Fallback colors
            const fallback = { 'I': '#007bff', 'V': '#28a745' };
            return fallback[rule] || '#6c757d';
        },
        (rule) => {
            if (typeof FILTER_CONFIG !== 'undefined' && FILTER_CONFIG.flightRule && FILTER_CONFIG.flightRule.labels) {
                return FILTER_CONFIG.flightRule.labels[rule] || rule;
            }
            const labels = { 'I': 'IFR', 'V': 'VFR' };
            return labels[rule] || rule;
        },
        order
    );
}

/**
 * Render chart with departure fix breakdown
 */
function renderDepFixChart() {
    const direction = DEMAND_STATE.direction;
    const dirLabel = direction === 'arr' ? 'Arrivals' : (direction === 'dep' ? 'Departures' : 'Flights');
    renderBreakdownChart(
        DEMAND_STATE.depFixBreakdown,
        `${dirLabel} by Departure Fix`,
        'dep_fix',
        'fix',
        (fix) => {
            if (typeof FILTER_CONFIG !== 'undefined' && FILTER_CONFIG.fix && typeof FILTER_CONFIG.fix.getColor === 'function') {
                return FILTER_CONFIG.fix.getColor(fix);
            }
            // Fallback: generate color from hash
            if (!fix) return '#6c757d';
            let hash = 0;
            for (let i = 0; i < fix.length; i++) {
                hash = fix.charCodeAt(i) + ((hash << 5) - hash);
            }
            const hue = Math.abs(hash % 360);
            return `hsl(${hue}, 65%, 45%)`;
        },
        null,
        null
    );
}

/**
 * Render chart with arrival fix breakdown
 */
function renderArrFixChart() {
    const direction = DEMAND_STATE.direction;
    const dirLabel = direction === 'arr' ? 'Arrivals' : (direction === 'dep' ? 'Departures' : 'Flights');
    renderBreakdownChart(
        DEMAND_STATE.arrFixBreakdown,
        `${dirLabel} by Arrival Fix`,
        'arr_fix',
        'fix',
        (fix) => {
            if (typeof FILTER_CONFIG !== 'undefined' && FILTER_CONFIG.fix && typeof FILTER_CONFIG.fix.getColor === 'function') {
                return FILTER_CONFIG.fix.getColor(fix);
            }
            // Fallback: generate color from hash
            if (!fix) return '#6c757d';
            let hash = 0;
            for (let i = 0; i < fix.length; i++) {
                hash = fix.charCodeAt(i) + ((hash << 5) - hash);
            }
            const hue = Math.abs(hash % 360);
            return `hsl(${hue}, 65%, 45%)`;
        },
        null,
        null
    );
}

/**
 * Render chart with DP/SID breakdown
 */
function renderDPChart() {
    const direction = DEMAND_STATE.direction;
    const dirLabel = direction === 'arr' ? 'Arrivals' : (direction === 'dep' ? 'Departures' : 'Flights');
    renderBreakdownChart(
        DEMAND_STATE.dpBreakdown,
        `${dirLabel} by Departure Procedure (SID)`,
        'dp',
        'dp',
        (dp) => {
            if (typeof FILTER_CONFIG !== 'undefined' && FILTER_CONFIG.procedure && typeof FILTER_CONFIG.procedure.getColor === 'function') {
                return FILTER_CONFIG.procedure.getColor(dp);
            }
            // Fallback: generate color from hash
            if (!dp) return '#6c757d';
            let hash = 0;
            for (let i = 0; i < dp.length; i++) {
                hash = dp.charCodeAt(i) + ((hash << 5) - hash);
            }
            const hue = Math.abs(hash % 360);
            return `hsl(${hue}, 70%, 50%)`;
        },
        null,
        null
    );
}

/**
 * Render chart with STAR breakdown
 */
function renderSTARChart() {
    const direction = DEMAND_STATE.direction;
    const dirLabel = direction === 'arr' ? 'Arrivals' : (direction === 'dep' ? 'Departures' : 'Flights');
    renderBreakdownChart(
        DEMAND_STATE.starBreakdown,
        `${dirLabel} by STAR`,
        'star',
        'star',
        (star) => {
            if (typeof FILTER_CONFIG !== 'undefined' && FILTER_CONFIG.procedure && typeof FILTER_CONFIG.procedure.getColor === 'function') {
                return FILTER_CONFIG.procedure.getColor(star);
            }
            // Fallback: generate color from hash
            if (!star) return '#6c757d';
            let hash = 0;
            for (let i = 0; i < star.length; i++) {
                hash = star.charCodeAt(i) + ((hash << 5) - hash);
            }
            const hue = Math.abs(hash % 360);
            return `hsl(${hue}, 70%, 50%)`;
        },
        null,
        null
    );
}

/**
 * Update info bar stats with response data
 * Uses individual phase breakdown from API
 */
function updateInfoBarStats(data) {
    const arrivals = data.data.arrivals || [];
    const departures = data.data.departures || [];

    // Calculate arrival totals by phase
    // Active = departed + enroute + descending (airborne flights)
    // Scheduled = taxiing (at origin, ready to depart)
    // Proposed = prefile (filed but not yet taxiing)
    let arrTotal = 0, arrActive = 0, arrScheduled = 0, arrProposed = 0;
    arrivals.forEach(d => {
        const b = d.breakdown || {};
        arrActive += (b.departed || 0) + (b.enroute || 0) + (b.descending || 0);
        arrScheduled += b.taxiing || 0;
        arrProposed += b.prefile || 0;
        arrTotal += d.total || 0;
    });

    // Calculate departure totals by phase
    let depTotal = 0, depActive = 0, depScheduled = 0, depProposed = 0;
    departures.forEach(d => {
        const b = d.breakdown || {};
        depActive += (b.departed || 0) + (b.enroute || 0) + (b.descending || 0);
        depScheduled += b.taxiing || 0;
        depProposed += b.prefile || 0;
        depTotal += d.total || 0;
    });

    // Update arrival stats
    $('#demand_arr_total').text(arrTotal);
    $('#demand_arr_active').text(arrActive);
    $('#demand_arr_scheduled').text(arrScheduled);
    $('#demand_arr_proposed').text(arrProposed);

    // Update departure stats
    $('#demand_dep_total').text(depTotal);
    $('#demand_dep_active').text(depActive);
    $('#demand_dep_scheduled').text(depScheduled);
    $('#demand_dep_proposed').text(depProposed);

    // Update flight count
    const totalFlights = arrTotal + depTotal;
    $('#demand_flight_count').text(totalFlights + ' flights');
}

/**
 * Build a series for a specific status - TFMS style (category axis - legacy)
 */
function buildStatusSeries(name, timeBins, dataByBin, status, type) {
    const data = timeBins.map(bin => {
        const breakdown = dataByBin[bin];
        return breakdown ? (breakdown[status] || 0) : 0;
    });

    // Adjust color based on type for visual distinction
    let color = FSM_STATUS_COLORS[status] || '#999';
    if (type === 'departures') {
        // Slightly adjust departure colors for distinction
        color = adjustColor(color, 0.15);
    }

    return {
        name: name,
        type: 'bar',
        stack: type,
        barWidth: '60%',
        barGap: '10%',
        emphasis: {
            focus: 'series'
        },
        itemStyle: {
            color: color,
            borderColor: '#fff',
            borderWidth: 0.5
        },
        data: data
    };
}

/**
 * Build a series for a specific phase - TBFM/FSM style with TRUE TIME AXIS
 * Data format: [[timestamp, value], [timestamp, value], ...]
 * Uses individual phase colors from FSM_PHASE_COLORS
 *
 * FSM/TBFM style:
 * - Arrivals: solid bars on the left side of each time bin
 * - Departures: hatched/diagonal pattern bars on the right side
 * - Bars are centered on the time period (shifted by half interval)
 *
 * @param {string} name - Series name for legend
 * @param {Array} timeBins - Array of ISO time bin strings
 * @param {Object} dataByBin - Lookup map of breakdown data by time bin
 * @param {string} phase - Phase name (arrived, enroute, etc.)
 * @param {string} type - 'arrivals' or 'departures'
 * @param {string} viewDirection - 'both', 'arr', or 'dep' - controls bar width
 */
function buildPhaseSeriesTimeAxis(name, timeBins, dataByBin, phase, type, viewDirection) {
    // Calculate interval for centering bars on time period
    const intervalMs = getGranularityMinutes() * 60 * 1000;
    const halfInterval = intervalMs / 2;

    // Build data as [timestamp, value] pairs for time axis
    // Shift by half interval so bar is centered on the time period
    const data = timeBins.map(bin => {
        const breakdown = dataByBin[bin];
        const value = breakdown ? (breakdown[phase] || 0) : 0;
        // Center the bar on the time period (start + half interval)
        return [new Date(bin).getTime() + halfInterval, value];
    });

    // Get phase color from individual phase palette
    const color = FSM_PHASE_COLORS[phase] || '#999';

    // Determine bar width based on whether showing both directions or just one
    // When showing both: narrower bars side-by-side (35%)
    // When showing single direction: wider bars (70%)
    const isSingleDirection = viewDirection === 'arr' || viewDirection === 'dep';
    const barWidth = isSingleDirection ? '70%' : '35%';

    // Base series config
    const seriesConfig = {
        name: name,
        type: 'bar',
        stack: type,
        barWidth: barWidth,
        barGap: '10%',    // Small gap between arrival and departure bars (when both shown)
        emphasis: {
            focus: 'series',
            itemStyle: {
                shadowBlur: 2,
                shadowColor: 'rgba(0,0,0,0.2)'
            }
        },
        itemStyle: {
            color: color,
            borderColor: type === 'departures' ? 'rgba(255,255,255,0.5)' : 'transparent',
            borderWidth: type === 'departures' ? 1 : 0
        },
        data: data
    };

    // Add diagonal hatching pattern for departures (FSM/TBFM style)
    if (type === 'departures') {
        seriesConfig.itemStyle.decal = {
            symbol: 'rect',
            symbolSize: 1,
            rotation: Math.PI / 4,  // 45-degree diagonal lines
            color: 'rgba(255,255,255,0.4)',  // White lines for contrast
            dashArrayX: [1, 0],
            dashArrayY: [3, 5]  // Line thickness and spacing
        };
    }

    return seriesConfig;
}

/**
 * Build a series for a specific status - TBFM/FSM style with TRUE TIME AXIS (legacy)
 * Data format: [[timestamp, value], [timestamp, value], ...]
 */
function buildStatusSeriesTimeAxis(name, timeBins, dataByBin, status, type) {
    // Build data as [timestamp, value] pairs for time axis
    const data = timeBins.map(bin => {
        const breakdown = dataByBin[bin];
        const value = breakdown ? (breakdown[status] || 0) : 0;
        return [new Date(bin).getTime(), value];
    });

    // Use phase colors
    let color = FSM_PHASE_COLORS[status] || '#999';
    if (type === 'departures') {
        // Use distinct hatch pattern effect for departures via lighter shade
        color = adjustColor(color, 0.12);
    }

    return {
        name: name,
        type: 'bar',
        stack: type,
        barWidth: '70%', // Percentage of available space per bin
        barGap: '0%',
        emphasis: {
            focus: 'series',
            itemStyle: {
                shadowBlur: 2,
                shadowColor: 'rgba(0,0,0,0.2)'
            }
        },
        itemStyle: {
            color: color,
            borderColor: 'transparent', // No borders - AADC style
            borderWidth: 0
        },
        data: data
    };
}

/**
 * Format timestamp for tooltip display - FAA AADC style
 * Note: Timestamps are centered on the bin (shifted by half interval),
 * so we subtract half to get the actual bin start time.
 */
function formatTimeLabelFromTimestamp(timestamp) {
    // Calculate interval and adjust timestamp back to bin start
    const intervalMs = getGranularityMinutes() * 60 * 1000;
    const halfInterval = intervalMs / 2;
    const binStart = timestamp - halfInterval;

    const d = new Date(binStart);
    const hours = d.getUTCHours().toString().padStart(2, '0');
    const minutes = d.getUTCMinutes().toString().padStart(2, '0');

    // Calculate end time (bin start + interval)
    const endTime = new Date(binStart + intervalMs);
    const endHours = endTime.getUTCHours().toString().padStart(2, '0');
    const endMinutes = endTime.getUTCMinutes().toString().padStart(2, '0');

    // AADC style: "1400 - 1500"
    return `${hours}${minutes} - ${endHours}${endMinutes}`;
}

/**
 * Get current time markLine data item for TRUE TIME AXIS - FAA AADC style
 * Returns a data item with embedded label config (for merging with rate lines)
 * Includes overlap detection with TMI program markers
 */
function getCurrentTimeMarkLineForTimeAxis() {
    const now = new Date();
    const nowMs = now.getTime();
    const hours = now.getUTCHours().toString().padStart(2, '0');
    const minutes = now.getUTCMinutes().toString().padStart(2, '0');

    // FSM/TBFM style: yellow/orange current time marker
    const markerColor = '#f59e0b';  // Amber/yellow like FSM reference

    // Check for overlap with TMI program markers
    const LABEL_PROXIMITY_THRESHOLD = 30 * 60 * 1000;  // 30 minutes in ms
    const LABEL_HEIGHT = 22;
    let labelOffset = 0;

    if (DEMAND_STATE.tmiPrograms && DEMAND_STATE.tmiPrograms.length > 0) {
        // Collect all TMI marker timestamps
        const tmiMarkerTimes = [];
        DEMAND_STATE.tmiPrograms.forEach(program => {
            if (program.start_utc) tmiMarkerTimes.push(new Date(program.start_utc).getTime());
            if (program.was_updated && program.updated_at) tmiMarkerTimes.push(new Date(program.updated_at).getTime());
            if (program.status === 'PURGED' && program.purged_at) tmiMarkerTimes.push(new Date(program.purged_at).getTime());
            else if (program.end_utc) tmiMarkerTimes.push(new Date(program.end_utc).getTime());
        });

        // Count how many TMI markers are within proximity of current time
        let markersInProximity = 0;
        tmiMarkerTimes.forEach(markerTime => {
            if (Math.abs(markerTime - nowMs) < LABEL_PROXIMITY_THRESHOLD) {
                markersInProximity++;
            }
        });

        // Offset time marker label based on number of nearby TMI markers
        labelOffset = markersInProximity * LABEL_HEIGHT;
    }

    // Return data item with label embedded (not at markLine level)
    // This allows proper merging with rate lines
    return {
        xAxis: nowMs,
        lineStyle: {
            color: markerColor,
            width: 2,
            type: 'solid'
        },
        label: {
            show: true,
            formatter: `${hours}${minutes}Z`,
            position: 'end',
            offset: [0, labelOffset],
            color: markerColor,
            fontWeight: 'bold',
            fontSize: 10,
            fontFamily: '"Inconsolata", monospace',
            backgroundColor: 'rgba(255,255,255,0.95)',
            padding: [2, 6],
            borderRadius: 2,
            borderColor: markerColor,
            borderWidth: 1
        }
    };
}

/**
 * Get applicable AAR/ADR rates for a specific timestamp
 * Checks scheduled configs first, falls back to current rateData
 * @param {number} timestamp - Milliseconds since epoch
 * @returns {Object|null} { aar, adr, source } with pro-rated values, or null if no rates
 */
function getRatesForTimestamp(timestamp) {
    const granularityMinutes = getGranularityMinutes();
    const proRateFactor = granularityMinutes / 60;

    // Try to find matching scheduled config first
    if (DEMAND_STATE.scheduledConfigs && DEMAND_STATE.scheduledConfigs.length > 0) {
        for (const config of DEMAND_STATE.scheduledConfigs) {
            const configStart = config.valid_from ? new Date(config.valid_from).getTime() : 0;
            const configEnd = config.valid_until ? new Date(config.valid_until).getTime() : Infinity;

            if (timestamp >= configStart && timestamp < configEnd) {
                return {
                    aar: config.aar ? Math.round(config.aar * proRateFactor) : null,
                    adr: config.adr ? Math.round(config.adr * proRateFactor) : null,
                    weather: config.weather || null,
                    source: 'TMI'
                };
            }
        }
    }

    // Fall back to current rate data (VATSIM rates)
    if (DEMAND_STATE.rateData && DEMAND_STATE.rateData.rates) {
        const rates = DEMAND_STATE.rateData.rates;
        return {
            aar: rates.vatsim_aar ? Math.round(rates.vatsim_aar * proRateFactor) : null,
            adr: rates.vatsim_adr ? Math.round(rates.vatsim_adr * proRateFactor) : null,
            weather: DEMAND_STATE.rateData.weather || null,
            source: 'VATSIM'
        };
    }

    return null;
}

/**
 * Build rate mark lines for the demand chart
 * Uses RATE_LINE_CONFIG from rate-colors.js for styling
 * Pro-rates hourly rates for sub-hourly granularities (30-min, 15-min)
 */
function buildRateMarkLinesForChart() {
    // Check if rate lines are enabled and we have rate data
    if (!DEMAND_STATE.showRateLines || !DEMAND_STATE.rateData) {
        return [];
    }

    const rateData = DEMAND_STATE.rateData;
    const rates = rateData.rates;
    if (!rates) return [];

    const lines = [];
    const direction = DEMAND_STATE.direction;

    // Pro-rate factor: AAR/ADR are hourly rates, adjust for granularity
    // Hourly = 1.0, 30-min = 0.5, 15-min = 0.25
    const granularityMinutes = getGranularityMinutes();
    const proRateFactor = granularityMinutes / 60;

    // Use config if available, otherwise use defaults
    const cfg = (typeof RATE_LINE_CONFIG !== 'undefined') ? RATE_LINE_CONFIG : {
        active: {
            vatsim: { color: '#000000' },
            rw: { color: '#00FFFF' }
        },
        suggested: {
            vatsim: { color: '#6b7280' },
            rw: { color: '#0d9488' }
        },
        custom: {
            vatsim: { color: '#000000' },
            rw: { color: '#00FFFF' }
        },
        lineStyle: {
            aar: { type: 'solid', width: 2 },
            adr: { type: 'dashed', width: 2 },
            aar_custom: { type: 'dotted', width: 2 },
            adr_custom: { type: 'dotted', width: 2 }
        },
        label: {
            position: 'end',
            fontSize: 10,
            fontWeight: 'bold'
        }
    };

    // Always use 'active' style - consistent symbology regardless of override/suggested status
    // VATSIM = black, RW = cyan, AAR = solid, ADR = dashed
    const styleKey = 'active';

    // Track label index for vertical stacking
    let labelIndex = 0;

    // Helper to create a rate line
    const addLine = (value, source, rateType, label) => {
        if (!value) return;

        // Apply pro-rate factor for sub-hourly granularity
        const proRatedValue = Math.round(value * proRateFactor * 10) / 10; // Round to 1 decimal
        const displayValue = proRatedValue % 1 === 0 ? proRatedValue.toFixed(0) : proRatedValue.toFixed(1);

        const sourceStyle = cfg[styleKey][source];
        // Always use standard line style (solid for AAR, dashed for ADR)
        const lineTypeStyle = cfg.lineStyle[rateType];

        // Show pro-rated label (e.g., "AAR 15" for 15-min at 60/hr, or "AAR 60/hr" for hourly)
        const labelText = proRateFactor < 1
            ? `${label} ${displayValue}`
            : `${label} ${value}`;

        // Use line color as background, contrasting text for readability
        const bgColor = sourceStyle.color;
        const textColor = getContrastTextColor(bgColor);

        // Stack labels vertically at the right edge
        // Each label is ~18px tall, offset by index
        const verticalOffset = labelIndex * 20;
        labelIndex++;

        lines.push({
            yAxis: proRatedValue,
            lineStyle: {
                color: sourceStyle.color,
                width: lineTypeStyle.width,
                type: lineTypeStyle.type
            },
            label: {
                show: true,
                formatter: labelText,
                position: 'end',
                distance: 5,
                offset: [0, verticalOffset],
                color: textColor,
                fontSize: cfg.label.fontSize || 10,
                fontWeight: cfg.label.fontWeight || 'bold',
                fontFamily: '"Roboto Mono", monospace',
                backgroundColor: bgColor,
                padding: [2, 6],
                borderRadius: 3,
                borderColor: textColor === '#ffffff' ? 'rgba(255,255,255,0.3)' : 'rgba(0,0,0,0.2)',
                borderWidth: 1
            }
        });
    };

    // Add lines based on direction filter AND individual visibility toggles
    if (direction === 'both' || direction === 'arr') {
        if (DEMAND_STATE.showVatsimAar) addLine(rates.vatsim_aar, 'vatsim', 'aar', 'AAR');
        if (DEMAND_STATE.showRwAar) addLine(rates.rw_aar, 'rw', 'aar', 'RW AAR');
    }

    if (direction === 'both' || direction === 'dep') {
        if (DEMAND_STATE.showVatsimAdr) addLine(rates.vatsim_adr, 'vatsim', 'adr', 'ADR');
        if (DEMAND_STATE.showRwAdr) addLine(rates.rw_adr, 'rw', 'adr', 'RW ADR');
    }

    return lines;
}

/**
 * Build time-bounded rate mark lines from scheduled TMI CONFIG entries
 * Creates horizontal line segments for each config period (stair-step style)
 * Lines are discontinuous at config transitions - no interpolation
 */
function buildTimeBoundedRateMarkLines() {
    // Check if rate lines are enabled and we have scheduled configs
    if (!DEMAND_STATE.showRateLines || !DEMAND_STATE.scheduledConfigs) {
        return [];
    }

    const configs = DEMAND_STATE.scheduledConfigs;
    if (!configs || configs.length === 0) return [];

    const lines = [];
    const direction = DEMAND_STATE.direction;

    // Pro-rate factor: AAR/ADR are hourly rates, adjust for granularity
    const granularityMinutes = getGranularityMinutes();
    const proRateFactor = granularityMinutes / 60;

    // Chart time bounds (in milliseconds)
    const chartStart = new Date(DEMAND_STATE.currentStart).getTime();
    const chartEnd = new Date(DEMAND_STATE.currentEnd).getTime();

    // Use config if available, otherwise use defaults
    const cfg = (typeof RATE_LINE_CONFIG !== 'undefined') ? RATE_LINE_CONFIG : {
        active: {
            vatsim: { color: '#000000' },
            rw: { color: '#00FFFF' }
        },
        lineStyle: {
            aar: { type: 'solid', width: 2 },
            adr: { type: 'dashed', width: 2 }
        },
        label: {
            fontSize: 10,
            fontWeight: 'bold'
        }
    };

    // === Smart label positioning to avoid collisions ===
    // Track ALL labels by (X endpoint, Y rate value) and ensure adequate vertical separation
    // Labels with similar Y values need to be offset to avoid overlap
    const LABEL_HEIGHT = 24;  // Approximate label height in pixels
    const X_PROXIMITY_MS = 30 * 60 * 1000;  // 30 minutes in ms - labels closer than this are grouped

    // Calculate dynamic Y proximity threshold based on max rate value (~12% of max)
    // This adapts to different Y-axis scales
    const rateValues = DEMAND_STATE.rateData?.rates || {};
    const allRateValues = [
        ...configs.map(c => c.aar).filter(v => v),
        ...configs.map(c => c.adr).filter(v => v),
        rateValues.vatsim_aar, rateValues.vatsim_adr, rateValues.rw_aar, rateValues.rw_adr
    ].filter(v => v && !isNaN(v));
    const maxRateValue = allRateValues.length > 0 ? Math.max(...allRateValues) : 50;
    const Y_PROXIMITY_THRESHOLD = Math.max(3, Math.round(maxRateValue * 0.12));  // 12% of max, min 3

    // Track labels at each X position: Map<xEndRounded, Array<{yValue, offset, isAar}>>
    const labelsByX = new Map();

    // Helper to get label offset for a position, accounting for Y-value proximity
    const getLabelOffset = (xEnd, yValue, isAar) => {
        const xKey = Math.round(xEnd / X_PROXIMITY_MS) * X_PROXIMITY_MS;
        if (!labelsByX.has(xKey)) {
            labelsByX.set(xKey, []);
        }
        const labels = labelsByX.get(xKey);

        // Find labels with similar Y values (potential collisions)
        const nearbyLabels = labels.filter(l => Math.abs(l.yValue - yValue) < Y_PROXIMITY_THRESHOLD);

        // Calculate offset based on how many nearby labels exist
        // AAR labels go above (negative), ADR labels go below (positive)
        let offset;
        if (isAar) {
            // Count nearby AAR labels to stack upward
            const nearbyAar = nearbyLabels.filter(l => l.isAar).length;
            offset = -LABEL_HEIGHT * nearbyAar;
        } else {
            // Count nearby ADR labels to stack downward, plus base offset
            const nearbyAdr = nearbyLabels.filter(l => !l.isAar).length;
            offset = LABEL_HEIGHT + (LABEL_HEIGHT * nearbyAdr);
        }

        // Register this label
        labels.push({ yValue, offset, isAar });

        return offset;
    };

    // Process each config
    configs.forEach((config, configIndex) => {
        // Parse config times (use chart bounds if null)
        const configStart = config.valid_from ? new Date(config.valid_from).getTime() : chartStart;
        const configEnd = config.valid_until ? new Date(config.valid_until).getTime() : chartEnd;

        // Clamp to chart bounds
        const segmentStart = Math.max(configStart, chartStart);
        const segmentEnd = Math.min(configEnd, chartEnd);

        // Skip if segment is outside visible range
        if (segmentStart >= segmentEnd) return;

        // Add AAR line segment (arrivals) - uses VATSIM visibility toggle
        if ((direction === 'both' || direction === 'arr') && config.aar && DEMAND_STATE.showVatsimAar) {
            const proRatedValue = Math.round(config.aar * proRateFactor * 10) / 10;
            const displayValue = proRatedValue % 1 === 0 ? proRatedValue.toFixed(0) : proRatedValue.toFixed(1);

            const labelText = proRateFactor < 1
                ? `AAR ${displayValue}`
                : `AAR ${config.aar}`;

            const sourceStyle = cfg.active.vatsim;
            const lineTypeStyle = cfg.lineStyle.aar;
            const bgColor = sourceStyle.color;
            const textColor = getContrastTextColor(bgColor);

            // Smart vertical offset: AAR labels above, grouped by X position and Y value
            const verticalOffset = getLabelOffset(segmentEnd, proRatedValue, true);

            // Two-point line segment format for ECharts
            lines.push([
                {
                    xAxis: segmentStart,
                    yAxis: proRatedValue
                },
                {
                    xAxis: segmentEnd,
                    yAxis: proRatedValue,
                    lineStyle: {
                        color: sourceStyle.color,
                        width: lineTypeStyle.width,
                        type: lineTypeStyle.type
                    },
                    label: {
                        show: true,
                        formatter: labelText,
                        position: 'end',
                        distance: 5,
                        offset: [0, verticalOffset],
                        color: textColor,
                        fontSize: cfg.label.fontSize || 10,
                        fontWeight: cfg.label.fontWeight || 'bold',
                        fontFamily: '"Roboto Mono", monospace',
                        backgroundColor: bgColor,
                        padding: [2, 6],
                        borderRadius: 3,
                        borderColor: textColor === '#ffffff' ? 'rgba(255,255,255,0.3)' : 'rgba(0,0,0,0.2)',
                        borderWidth: 1
                    }
                }
            ]);
        }

        // Add ADR line segment (departures) - uses VATSIM visibility toggle
        if ((direction === 'both' || direction === 'dep') && config.adr && DEMAND_STATE.showVatsimAdr) {
            const proRatedValue = Math.round(config.adr * proRateFactor * 10) / 10;
            const displayValue = proRatedValue % 1 === 0 ? proRatedValue.toFixed(0) : proRatedValue.toFixed(1);

            const labelText = proRateFactor < 1
                ? `ADR ${displayValue}`
                : `ADR ${config.adr}`;

            const sourceStyle = cfg.active.vatsim;
            const lineTypeStyle = cfg.lineStyle.adr;
            const bgColor = sourceStyle.color;
            const textColor = getContrastTextColor(bgColor);

            // Smart vertical offset: ADR labels below, grouped by X position and Y value
            const verticalOffset = getLabelOffset(segmentEnd, proRatedValue, false);

            lines.push([
                {
                    xAxis: segmentStart,
                    yAxis: proRatedValue
                },
                {
                    xAxis: segmentEnd,
                    yAxis: proRatedValue,
                    lineStyle: {
                        color: sourceStyle.color,
                        width: lineTypeStyle.width,
                        type: lineTypeStyle.type
                    },
                    label: {
                        show: true,
                        formatter: labelText,
                        position: 'end',
                        distance: 5,
                        offset: [0, verticalOffset],
                        color: textColor,
                        fontSize: cfg.label.fontSize || 10,
                        fontWeight: cfg.label.fontWeight || 'bold',
                        fontFamily: '"Roboto Mono", monospace',
                        backgroundColor: bgColor,
                        padding: [2, 6],
                        borderRadius: 3,
                        borderColor: textColor === '#ffffff' ? 'rgba(255,255,255,0.3)' : 'rgba(0,0,0,0.2)',
                        borderWidth: 1
                    }
                }
            ]);
        }
    });

    // === FALLBACK: Fill gaps with VATSIM rate data from DEMAND_STATE.rateData ===
    // When CONFIG entries don't cover the full chart range, use fallback VATSIM rates
    // Use same symbology as scheduled configs: solid black AAR, dashed black ADR
    if (DEMAND_STATE.rateData && DEMAND_STATE.rateData.rates) {
        const rates = DEMAND_STATE.rateData.rates;
        const vatsimStyle = cfg.active?.vatsim || { color: '#000000' };
        const vatsimTextColor = getContrastTextColor(vatsimStyle.color);

        // Build list of covered periods from configs
        const coveredPeriods = [];
        configs.forEach(config => {
            const configStart = config.valid_from ? new Date(config.valid_from).getTime() : chartStart;
            const configEnd = config.valid_until ? new Date(config.valid_until).getTime() : chartEnd;
            const start = Math.max(configStart, chartStart);
            const end = Math.min(configEnd, chartEnd);
            if (start < end) {
                coveredPeriods.push({ start, end });
            }
        });

        // Sort periods by start time
        coveredPeriods.sort((a, b) => a.start - b.start);

        // Find gaps (uncovered periods)
        const gaps = [];
        let cursor = chartStart;

        coveredPeriods.forEach(period => {
            if (period.start > cursor) {
                gaps.push({ start: cursor, end: period.start });
            }
            cursor = Math.max(cursor, period.end);
        });

        // Final gap after last config
        if (cursor < chartEnd) {
            gaps.push({ start: cursor, end: chartEnd });
        }

        // Track which fallback labels have been added (only label once, on last gap)
        let vatsimAarLabeled = false;
        let vatsimAdrLabeled = false;

        // Add fallback VATSIM rate lines for each gap (same symbology as active)
        gaps.forEach((gap, gapIndex) => {
            const isLastGap = gapIndex === gaps.length - 1;

            // VATSIM AAR fallback - solid black
            if ((direction === 'both' || direction === 'arr') && rates.vatsim_aar && DEMAND_STATE.showVatsimAar) {
                const proRatedValue = Math.round(rates.vatsim_aar * proRateFactor * 10) / 10;
                const displayValue = proRatedValue % 1 === 0 ? proRatedValue.toFixed(0) : proRatedValue.toFixed(1);
                const labelText = proRateFactor < 1
                    ? `AAR ${displayValue}`
                    : `AAR ${rates.vatsim_aar}`;

                const showLabel = isLastGap && !vatsimAarLabeled;
                const verticalOffset = showLabel ? getLabelOffset(gap.end, proRatedValue, true) : 0;
                if (showLabel) {
                    vatsimAarLabeled = true;
                }

                lines.push([
                    { xAxis: gap.start, yAxis: proRatedValue },
                    {
                        xAxis: gap.end,
                        yAxis: proRatedValue,
                        lineStyle: {
                            color: vatsimStyle.color,
                            width: 2,
                            type: 'solid'  // Solid for AAR
                        },
                        label: {
                            show: showLabel,
                            formatter: labelText,
                            position: 'end',
                            distance: 5,
                            offset: [0, verticalOffset],
                            color: vatsimTextColor,
                            fontSize: cfg.label?.fontSize || 10,
                            fontWeight: cfg.label?.fontWeight || 'bold',
                            fontFamily: '"Roboto Mono", monospace',
                            backgroundColor: vatsimStyle.color,
                            padding: [2, 6],
                            borderRadius: 3,
                            borderColor: 'rgba(255,255,255,0.3)',
                            borderWidth: 1
                        }
                    }
                ]);
            }

            // VATSIM ADR fallback - dashed black
            if ((direction === 'both' || direction === 'dep') && rates.vatsim_adr && DEMAND_STATE.showVatsimAdr) {
                const proRatedValue = Math.round(rates.vatsim_adr * proRateFactor * 10) / 10;
                const displayValue = proRatedValue % 1 === 0 ? proRatedValue.toFixed(0) : proRatedValue.toFixed(1);
                const labelText = proRateFactor < 1
                    ? `ADR ${displayValue}`
                    : `ADR ${rates.vatsim_adr}`;

                const showLabel = isLastGap && !vatsimAdrLabeled;
                const verticalOffset = showLabel ? getLabelOffset(gap.end, proRatedValue, false) : 0;
                if (showLabel) {
                    vatsimAdrLabeled = true;
                }

                lines.push([
                    { xAxis: gap.start, yAxis: proRatedValue },
                    {
                        xAxis: gap.end,
                        yAxis: proRatedValue,
                        lineStyle: {
                            color: vatsimStyle.color,
                            width: 2,
                            type: 'dashed'  // Dashed for ADR
                        },
                        label: {
                            show: showLabel,
                            formatter: labelText,
                            position: 'end',
                            distance: 5,
                            offset: [0, verticalOffset],
                            color: vatsimTextColor,
                            fontSize: cfg.label?.fontSize || 10,
                            fontWeight: cfg.label?.fontWeight || 'bold',
                            fontFamily: '"Roboto Mono", monospace',
                            backgroundColor: vatsimStyle.color,
                            padding: [2, 6],
                            borderRadius: 3,
                            borderColor: 'rgba(255,255,255,0.3)',
                            borderWidth: 1
                        }
                    }
                ]);
            }
        });

        // === Add Real World (RW) rates as full-width lines ===
        // RW rates are not time-bounded by TMI CONFIGs, so show them across full chart
        const rwStyle = cfg.active?.rw || { color: '#00FFFF' };
        const rwTextColor = getContrastTextColor(rwStyle.color);

        // RW AAR - solid cyan across full chart
        if ((direction === 'both' || direction === 'arr') && rates.rw_aar && DEMAND_STATE.showRwAar) {
            const proRatedValue = Math.round(rates.rw_aar * proRateFactor * 10) / 10;
            const displayValue = proRatedValue % 1 === 0 ? proRatedValue.toFixed(0) : proRatedValue.toFixed(1);
            const labelText = proRateFactor < 1
                ? `RW AAR ${displayValue}`
                : `RW AAR ${rates.rw_aar}`;

            // Smart vertical offset: AAR labels above, with Y-value collision detection
            const verticalOffset = getLabelOffset(chartEnd, proRatedValue, true);

            lines.push([
                { xAxis: chartStart, yAxis: proRatedValue },
                {
                    xAxis: chartEnd,
                    yAxis: proRatedValue,
                    lineStyle: {
                        color: rwStyle.color,
                        width: 2,
                        type: 'solid'  // Solid for AAR
                    },
                    label: {
                        show: true,
                        formatter: labelText,
                        position: 'end',
                        distance: 5,
                        offset: [0, verticalOffset],
                        color: rwTextColor,
                        fontSize: cfg.label?.fontSize || 10,
                        fontWeight: cfg.label?.fontWeight || 'bold',
                        fontFamily: '"Roboto Mono", monospace',
                        backgroundColor: rwStyle.color,
                        padding: [2, 6],
                        borderRadius: 3,
                        borderColor: 'rgba(0,0,0,0.2)',
                        borderWidth: 1
                    }
                }
            ]);
        }

        // RW ADR - dashed cyan across full chart
        if ((direction === 'both' || direction === 'dep') && rates.rw_adr && DEMAND_STATE.showRwAdr) {
            const proRatedValue = Math.round(rates.rw_adr * proRateFactor * 10) / 10;
            const displayValue = proRatedValue % 1 === 0 ? proRatedValue.toFixed(0) : proRatedValue.toFixed(1);
            const labelText = proRateFactor < 1
                ? `RW ADR ${displayValue}`
                : `RW ADR ${rates.rw_adr}`;

            // Smart vertical offset: ADR labels below, with Y-value collision detection
            const verticalOffset = getLabelOffset(chartEnd, proRatedValue, false);

            lines.push([
                { xAxis: chartStart, yAxis: proRatedValue },
                {
                    xAxis: chartEnd,
                    yAxis: proRatedValue,
                    lineStyle: {
                        color: rwStyle.color,
                        width: 2,
                        type: 'dashed'  // Dashed for ADR
                    },
                    label: {
                        show: true,
                        formatter: labelText,
                        position: 'end',
                        distance: 5,
                        offset: [0, verticalOffset],
                        color: rwTextColor,
                        fontSize: cfg.label?.fontSize || 10,
                        fontWeight: cfg.label?.fontWeight || 'bold',
                        fontFamily: '"Roboto Mono", monospace',
                        backgroundColor: rwStyle.color,
                        padding: [2, 6],
                        borderRadius: 3,
                        borderColor: 'rgba(0,0,0,0.2)',
                        borderWidth: 1
                    }
                }
            ]);
        }
    }

    return lines;
}

/**
 * Build vertical marker lines for GS (Ground Stop) and GDP programs
 * GS = Yellow vertical lines, GDP = Brown vertical lines
 * Markers: Start (solid), Update (dashed), CNX/End (solid)
 * Includes label overlap detection to stack labels when markers are close together
 *
 * @returns {Array} Array of markLine data items for ECharts
 */
function buildTmiProgramMarkLines() {
    if (!DEMAND_STATE.tmiPrograms || DEMAND_STATE.tmiPrograms.length === 0) {
        return [];
    }

    const lines = [];

    // Color definitions
    const GS_COLOR = '#fbbf24';   // Yellow/Amber for Ground Stops
    const GDP_COLOR = '#92400e';  // Brown for Ground Delay Programs

    // Label overlap detection - track marker positions
    const markerPositions = [];  // Array of {xAxis, labelOffset}
    const LABEL_PROXIMITY_THRESHOLD = 30 * 60 * 1000;  // 30 minutes in ms - labels closer than this get stacked
    const LABEL_HEIGHT = 22;  // Approximate height of label in pixels

    // Helper to format time as HHMMZ
    const formatTimeZ = (isoString) => {
        if (!isoString) return '';
        const d = new Date(isoString);
        return d.getUTCHours().toString().padStart(2, '0') +
               d.getUTCMinutes().toString().padStart(2, '0') + 'Z';
    };

    // Calculate label offset based on proximity to other markers
    const calculateLabelOffset = (xAxis) => {
        // Find markers within proximity threshold
        let maxOffsetInProximity = -1;
        markerPositions.forEach(pos => {
            if (Math.abs(pos.xAxis - xAxis) < LABEL_PROXIMITY_THRESHOLD) {
                maxOffsetInProximity = Math.max(maxOffsetInProximity, pos.labelOffset);
            }
        });
        // Next offset is one level higher than the highest in proximity
        return maxOffsetInProximity + 1;
    };

    // Collect all markers first with their timestamps
    const markersToAdd = [];

    // Process each program
    DEMAND_STATE.tmiPrograms.forEach(program => {
        const isGS = program.program_type === 'GS';
        const color = isGS ? GS_COLOR : GDP_COLOR;
        const prefix = isGS ? 'GS' : 'GDP';

        // Start marker (solid line)
        if (program.start_utc) {
            markersToAdd.push({
                timestamp: program.start_utc,
                label: `${prefix} Start: ${formatTimeZ(program.start_utc)}`,
                color: color,
                isDashed: false
            });
        }

        // Update marker (dashed line) - only if was_updated is true
        if (program.was_updated && program.updated_at) {
            markersToAdd.push({
                timestamp: program.updated_at,
                label: `${prefix} Update: ${formatTimeZ(program.updated_at)}`,
                color: color,
                isDashed: true
            });
        }

        // End marker - check status
        if (program.status === 'PURGED' && program.purged_at) {
            markersToAdd.push({
                timestamp: program.purged_at,
                label: `${prefix} CNX: ${formatTimeZ(program.purged_at)}`,
                color: color,
                isDashed: false
            });
        } else if (program.end_utc) {
            markersToAdd.push({
                timestamp: program.end_utc,
                label: `${prefix} End: ${formatTimeZ(program.end_utc)}`,
                color: color,
                isDashed: false
            });
        }
    });

    // Sort markers by timestamp for consistent offset calculation
    markersToAdd.sort((a, b) => new Date(a.timestamp).getTime() - new Date(b.timestamp).getTime());

    const chartStart = new Date(DEMAND_STATE.currentStart).getTime();
    const chartEnd = new Date(DEMAND_STATE.currentEnd).getTime();

    // Add markers with overlap detection
    markersToAdd.forEach(marker => {
        const timeMs = new Date(marker.timestamp).getTime();

        // Skip if outside visible range
        if (timeMs < chartStart || timeMs > chartEnd) return;

        // Calculate offset for this marker
        const labelOffset = calculateLabelOffset(timeMs);
        const verticalOffset = labelOffset * LABEL_HEIGHT;

        // Record this marker's position for future overlap detection
        markerPositions.push({ xAxis: timeMs, labelOffset: labelOffset });

        lines.push({
            xAxis: timeMs,
            lineStyle: {
                color: marker.color,
                width: 2,
                type: marker.isDashed ? 'dashed' : 'solid'
            },
            label: {
                show: true,
                formatter: marker.label,
                position: 'end',
                offset: [0, verticalOffset],
                color: '#000000',  // Black text for readability
                fontWeight: 'bold',
                fontSize: 10,
                fontFamily: '"Inconsolata", monospace',
                backgroundColor: marker.color,
                padding: [2, 6],
                borderRadius: 2,
                borderColor: 'rgba(0,0,0,0.3)',
                borderWidth: 1
            }
        });
    });

    return lines;
}

/**
 * Adjust color brightness
 */
function adjustColor(hex, percent) {
    const num = parseInt(hex.replace('#', ''), 16);
    const amt = Math.round(2.55 * percent * 100);
    const R = Math.min(255, Math.max(0, (num >> 16) + amt));
    const G = Math.min(255, Math.max(0, ((num >> 8) & 0x00FF) + amt));
    const B = Math.min(255, Math.max(0, (num & 0x0000FF) + amt));
    return '#' + (0x1000000 + R * 0x10000 + G * 0x100 + B).toString(16).slice(1);
}

/**
 * Format time bin for display - TFMS style
 */
function formatTimeLabel(isoString) {
    const date = new Date(isoString);
    const hours = date.getUTCHours().toString().padStart(2, '0');
    const minutes = date.getUTCMinutes();

    // For hourly granularity, show just "14Z", for 15-min show "14:15"
    if (minutes === 0) {
        return `${hours}Z`;
    }
    return `${hours}:${minutes.toString().padStart(2, '0')}`;
}

/**
 * Generate all time bins for the current time range and granularity
 * This ensures the x-axis shows all time slots, not just those with data
 */
function generateAllTimeBins() {
    // Calculate time range - use custom values or preset offsets
    let start, end;
    if (DEMAND_STATE.timeRangeMode === 'custom' && DEMAND_STATE.customStart && DEMAND_STATE.customEnd) {
        start = new Date(DEMAND_STATE.customStart);
        end = new Date(DEMAND_STATE.customEnd);
    } else {
        const now = new Date();
        start = new Date(now.getTime() + DEMAND_STATE.timeRangeStart * 60 * 60 * 1000);
        end = new Date(now.getTime() + DEMAND_STATE.timeRangeEnd * 60 * 60 * 1000);
    }

    // Round start down and end up to nearest interval
    const intervalMinutes = getGranularityMinutes();

    // Round start down to nearest interval
    const startMinutes = start.getUTCMinutes();
    const roundedStartMinutes = Math.floor(startMinutes / intervalMinutes) * intervalMinutes;
    start.setUTCMinutes(roundedStartMinutes, 0, 0);

    // Round end up to nearest interval
    const endMinutes = end.getUTCMinutes();
    const roundedEndMinutes = Math.ceil(endMinutes / intervalMinutes) * intervalMinutes;
    if (roundedEndMinutes >= 60) {
        end.setUTCHours(end.getUTCHours() + 1);
        end.setUTCMinutes(0, 0, 0);
    } else {
        end.setUTCMinutes(roundedEndMinutes, 0, 0);
    }

    const timeBins = [];
    const current = new Date(start);

    while (current <= end) {
        // Format without milliseconds to match PHP's format: "2026-01-10T14:00:00Z"
        timeBins.push(current.toISOString().replace('.000Z', 'Z'));
        current.setUTCMinutes(current.getUTCMinutes() + intervalMinutes);
    }

    return timeBins;
}

/**
 * Find the index of the time bin closest to current time
 */
function findCurrentTimeIndex(timeBins) {
    if (!timeBins || timeBins.length === 0) return -1;

    const now = new Date().getTime();
    let closestIndex = 0;
    let closestDiff = Math.abs(new Date(timeBins[0]).getTime() - now);

    for (let i = 1; i < timeBins.length; i++) {
        const diff = Math.abs(new Date(timeBins[i]).getTime() - now);
        if (diff < closestDiff) {
            closestDiff = diff;
            closestIndex = i;
        }
    }

    return closestIndex;
}

/**
 * Get current time markLine configuration for chart
 */
function getCurrentTimeMarkLine(timeBins) {
    const currentIndex = findCurrentTimeIndex(timeBins);
    if (currentIndex < 0) return null;

    const now = new Date();
    const hours = now.getUTCHours().toString().padStart(2, '0');
    const minutes = now.getUTCMinutes().toString().padStart(2, '0');

    return {
        silent: true,
        symbol: 'none',
        lineStyle: {
            color: '#000000',
            width: 2,
            type: 'solid'
        },
        label: {
            show: true,
            formatter: `NOW\n${hours}:${minutes}Z`,
            position: 'start',
            color: '#000000',
            fontWeight: 'bold',
            fontSize: 10
        },
        data: [{ xAxis: currentIndex }]
    };
}

/**
 * Get direction label for chart subtitle
 */
function getDirectionLabel() {
    switch (DEMAND_STATE.direction) {
        case 'arr': return 'Arrivals Only';
        case 'dep': return 'Departures Only';
        default: return 'Arrivals & Departures';
    }
}

/**
 * Get X-axis label based on granularity
 * Format: "Time in {#}-Minute Increments"
 */
function getXAxisLabel() {
    const minutes = getGranularityMinutes();
    return `Time in ${minutes}-Minute Increments`;
}

/**
 * Get granularity in minutes
 */
function getGranularityMinutes() {
    switch (DEMAND_STATE.granularity) {
        case '15min': return 15;
        case '30min': return 30;
        default: return 60;
    }
}

/**
 * Check if cached data is still valid (within 15 seconds of last load)
 */
function isCacheValid() {
    if (!DEMAND_STATE.cacheTimestamp) return false;
    const now = Date.now();
    return (now - DEMAND_STATE.cacheTimestamp) < DEMAND_STATE.cacheValidityMs;
}

/**
 * Invalidate the cache (call when filters change that require fresh data)
 */
function invalidateCache() {
    DEMAND_STATE.cacheTimestamp = null;
    DEMAND_STATE.summaryLoaded = false;
}

/**
 * Render the current chart view using cached data
 * This avoids API calls when switching between views
 */
function renderCurrentView() {
    if (!DEMAND_STATE.lastDemandData) return;

    switch (DEMAND_STATE.chartView) {
        case 'origin':
            renderOriginChart();
            break;
        case 'dest':
            renderDestChart();
            break;
        case 'carrier':
            renderCarrierChart();
            break;
        case 'weight':
            renderWeightChart();
            break;
        case 'equipment':
            renderEquipmentChart();
            break;
        case 'rule':
            renderRuleChart();
            break;
        case 'dep_fix':
            renderDepFixChart();
            break;
        case 'arr_fix':
            renderArrFixChart();
            break;
        case 'dp':
            renderDPChart();
            break;
        case 'star':
            renderSTARChart();
            break;
        default:
            renderChart(DEMAND_STATE.lastDemandData);
    }
}

/**
 * Get standard dataZoom configuration for demand charts
 * Provides horizontal (time) and vertical (demand) sliders
 */
function getDataZoomConfig() {
    return [
        {
            // Horizontal slider (time axis)
            type: 'slider',
            xAxisIndex: 0,
            bottom: 10,
            height: 30,
            start: 0,
            end: 100,
            borderColor: '#adb5bd',
            backgroundColor: '#f8f9fa',
            fillerColor: 'rgba(0, 123, 255, 0.2)',
            handleSize: '110%',           // Larger handles for easier interaction
            handleStyle: {
                color: '#007bff',
                borderColor: '#0056b3',
                borderWidth: 1
            },
            moveHandleSize: 10,           // Size of the move handle (middle bar)
            emphasis: {
                handleStyle: {
                    color: '#0056b3',
                    borderColor: '#003d82'
                }
            },
            textStyle: {
                color: '#333',
                fontSize: 10,
                fontFamily: '"Inconsolata", monospace'
            },
            labelFormatter: function(value) {
                const d = new Date(value);
                return d.getUTCHours().toString().padStart(2, '0') +
                       d.getUTCMinutes().toString().padStart(2, '0') + 'Z';
            },
            brushSelect: false,
            zLevel: 10                    // Ensure slider is above other elements
        },
        {
            // Vertical slider (demand axis) - on the right side
            type: 'slider',
            yAxisIndex: 0,
            right: 5,
            width: 25,
            start: 0,
            end: 100,
            borderColor: '#adb5bd',
            backgroundColor: '#f8f9fa',
            fillerColor: 'rgba(40, 167, 69, 0.2)',
            handleSize: '110%',
            handleStyle: {
                color: '#28a745',
                borderColor: '#1e7e34',
                borderWidth: 1
            },
            emphasis: {
                handleStyle: {
                    color: '#1e7e34',
                    borderColor: '#155d27'
                }
            },
            textStyle: {
                color: '#333',
                fontSize: 10
            },
            brushSelect: false,
            zLevel: 10
        },
        {
            // Inside zoom for time axis (mouse scroll/drag)
            type: 'inside',
            xAxisIndex: 0,
            zoomOnMouseWheel: 'shift', // Shift+scroll to zoom
            moveOnMouseMove: false,
            moveOnMouseWheel: false
        }
    ];
}

/**
 * Build chart title in FSM/TBFM style
 * Format: "KATL          01/10/2026          16:30Z"
 * Airport code (left), ADL date (center), ADL time (right)
 */
function buildChartTitle(airport, lastAdlUpdate) {
    // Format ADL date and time
    let dateStr = '--/--/----';
    let timeStr = '--:--Z';

    if (lastAdlUpdate) {
        const adlDate = new Date(lastAdlUpdate);
        // Format: mm/dd/yyyy
        const month = (adlDate.getUTCMonth() + 1).toString().padStart(2, '0');
        const day = adlDate.getUTCDate().toString().padStart(2, '0');
        const year = adlDate.getUTCFullYear();
        dateStr = `${month}/${day}/${year}`;

        // Format: HH:MMZ (with colon, uppercase Z)
        const hours = adlDate.getUTCHours().toString().padStart(2, '0');
        const mins = adlDate.getUTCMinutes().toString().padStart(2, '0');
        timeStr = `${hours}:${mins}Z`;
    }

    // Use fixed-width spacing to create the left/center/right layout
    // Pad with spaces to create even distribution
    return `${airport}          ${dateStr}          ${timeStr}`;
}

/**
 * Update last update timestamp display
 */
function updateLastUpdateDisplay(adlUpdate) {
    const now = new Date();
    const localTime = now.toLocaleTimeString();
    let display = `Refreshed: ${localTime}`;
    if (adlUpdate) {
        display += ` | ADL: ${formatTimeLabel(adlUpdate)}`;
    }
    $('#demand_last_update').text(display);
}

/**
 * Show loading indicator on chart
 */
function showLoading() {
    // Make sure chart is visible
    $('#demand_empty_state').hide();
    $('#demand_chart').show();

    // Resize chart in case it was hidden
    if (DEMAND_STATE.chart) {
        DEMAND_STATE.chart.resize();
        DEMAND_STATE.chart.showLoading({
            text: 'Loading...',
            color: '#007bff',
            textColor: '#000',
            maskColor: 'rgba(255, 255, 255, 0.8)'
        });
    }
}

/**
 * Show error message
 */
function showError(message) {
    if (DEMAND_STATE.chart) {
        DEMAND_STATE.chart.hideLoading();
        DEMAND_STATE.chart.clear();
        DEMAND_STATE.chart.setOption({
            title: {
                text: message,
                left: 'center',
                top: 'middle',
                textStyle: {
                    color: '#dc3545',
                    fontSize: 16
                }
            }
        });
    }

    // Also show toast notification
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            toast: true,
            position: 'bottom-right',
            icon: 'error',
            title: 'Error',
            text: message,
            timer: 5000,
            showConfirmButton: false
        });
    }
}

/**
 * Start auto-refresh timer
 */
function startAutoRefresh() {
    stopAutoRefresh(); // Clear any existing timer
    if (DEMAND_STATE.autoRefresh && DEMAND_STATE.selectedAirport) {
        DEMAND_STATE.refreshTimer = setInterval(function() {
            loadDemandData();
        }, DEMAND_STATE.refreshInterval);
        console.log('Auto-refresh started (every ' + (DEMAND_STATE.refreshInterval / 1000) + 's)');
    }
}

/**
 * Stop auto-refresh timer
 */
function stopAutoRefresh() {
    if (DEMAND_STATE.refreshTimer) {
        clearInterval(DEMAND_STATE.refreshTimer);
        DEMAND_STATE.refreshTimer = null;
        console.log('Auto-refresh stopped');
    }
}

/**
 * Load flight summary data (top origins, top carriers, origin breakdown)
 * @param {boolean} renderOriginChartAfter - If true, render origin chart after loading
 */
function loadFlightSummary(renderOriginChartAfter) {
    const airport = DEMAND_STATE.selectedAirport;
    if (!airport) return;

    const params = new URLSearchParams({
        airport: airport,
        start: DEMAND_STATE.currentStart,
        end: DEMAND_STATE.currentEnd,
        direction: DEMAND_STATE.direction
    });

    $.getJSON(`api/demand/summary.php?${params.toString()}`)
        .done(function(response) {
            if (response.success) {
                updateTopOrigins(response.top_origins || []);
                updateTopCarriers(response.top_carriers || []);

                // Store breakdown data for chart views
                DEMAND_STATE.originBreakdown = response.origin_artcc_breakdown || {};
                DEMAND_STATE.destBreakdown = response.dest_artcc_breakdown || {};
                DEMAND_STATE.weightBreakdown = response.weight_breakdown || {};
                DEMAND_STATE.carrierBreakdown = response.carrier_breakdown || {};
                DEMAND_STATE.equipmentBreakdown = response.equipment_breakdown || {};
                DEMAND_STATE.ruleBreakdown = response.rule_breakdown || {};
                DEMAND_STATE.depFixBreakdown = response.dep_fix_breakdown || {};
                DEMAND_STATE.arrFixBreakdown = response.arr_fix_breakdown || {};
                DEMAND_STATE.dpBreakdown = response.dp_breakdown || {};
                DEMAND_STATE.starBreakdown = response.star_breakdown || {};

                // Mark summary data as loaded (for caching)
                DEMAND_STATE.summaryLoaded = true;
                DEMAND_STATE.cacheTimestamp = Date.now(); // Refresh cache timestamp

                // Debug: Log breakdown data sizes
                console.log('[Demand] Summary API breakdown data (cached):',
                    'origin:', Object.keys(DEMAND_STATE.originBreakdown).length,
                    'dest:', Object.keys(DEMAND_STATE.destBreakdown).length,
                    'weight:', Object.keys(DEMAND_STATE.weightBreakdown).length,
                    'carrier:', Object.keys(DEMAND_STATE.carrierBreakdown).length,
                    'equipment:', Object.keys(DEMAND_STATE.equipmentBreakdown).length,
                    'rule:', Object.keys(DEMAND_STATE.ruleBreakdown).length,
                    'depFix:', Object.keys(DEMAND_STATE.depFixBreakdown).length,
                    'arrFix:', Object.keys(DEMAND_STATE.arrFixBreakdown).length,
                    'dp:', Object.keys(DEMAND_STATE.dpBreakdown).length,
                    'star:', Object.keys(DEMAND_STATE.starBreakdown).length
                );

                // Auto-expand the summary section if it has data
                const hasData = (response.top_origins && response.top_origins.length > 0) ||
                               (response.top_carriers && response.top_carriers.length > 0);
                if (hasData) {
                    const $summary = $('#demand_flight_summary');
                    const $icon = $('#demand_toggle_flights i');
                    if (!$summary.is(':visible')) {
                        $summary.slideDown(200);
                        $icon.removeClass('fa-chevron-down').addClass('fa-chevron-up');
                    }
                }

                // Render breakdown chart if requested (based on current view)
                if (renderOriginChartAfter && DEMAND_STATE.chartView !== 'status') {
                    // Re-render the appropriate breakdown chart
                    switch (DEMAND_STATE.chartView) {
                        case 'origin': renderOriginChart(); break;
                        case 'dest': renderDestChart(); break;
                        case 'carrier': renderCarrierChart(); break;
                        case 'weight': renderWeightChart(); break;
                        case 'equipment': renderEquipmentChart(); break;
                        case 'rule': renderRuleChart(); break;
                        case 'dep_fix': renderDepFixChart(); break;
                        case 'arr_fix': renderArrFixChart(); break;
                        case 'dp': renderDPChart(); break;
                        case 'star': renderSTARChart(); break;
                    }
                }
            }
        })
        .fail(function(err) {
            console.error('Failed to load flight summary:', err);
        });
}

/**
 * Update top origins table
 */
function updateTopOrigins(origins) {
    const $tbody = $('#demand_top_origins');
    $tbody.empty();

    if (origins.length === 0) {
        $tbody.append('<tr><td class="text-muted text-center" colspan="2">No data</td></tr>');
        return;
    }

    origins.forEach(function(item, index) {
        const bgClass = index === 0 ? 'table-primary' : '';
        $tbody.append(`
            <tr class="${bgClass}">
                <td><strong>${item.artcc}</strong></td>
                <td class="text-right">${item.count}</td>
            </tr>
        `);
    });
}

/**
 * Update top carriers table
 */
function updateTopCarriers(carriers) {
    const $tbody = $('#demand_top_carriers');
    $tbody.empty();

    if (carriers.length === 0) {
        $tbody.append('<tr><td class="text-muted text-center" colspan="2">No data</td></tr>');
        return;
    }

    carriers.forEach(function(item, index) {
        const bgClass = index === 0 ? 'table-primary' : '';
        $tbody.append(`
            <tr class="${bgClass}">
                <td><strong>${item.carrier}</strong></td>
                <td class="text-right">${item.count}</td>
            </tr>
        `);
    });
}

/**
 * Show flight details for a specific time bin (drill-down)
 * Note: timeBin is centered on the period (shifted by half interval),
 * so we adjust it back to get the actual bin start time.
 * @param {string} timeBin - ISO timestamp of the clicked time bin
 * @param {string} clickedSeries - Optional: the series name that was clicked (status, carrier, ARTCC, etc.)
 */
function showFlightDetails(timeBin, clickedSeries) {
    const airport = DEMAND_STATE.selectedAirport;
    if (!airport) return;

    // Adjust timestamp back to bin start (subtract half interval)
    const intervalMs = getGranularityMinutes() * 60 * 1000;
    const halfInterval = intervalMs / 2;
    const binStartMs = new Date(timeBin).getTime() - halfInterval;
    const actualTimeBin = new Date(binStartMs).toISOString();

    const params = new URLSearchParams({
        airport: airport,
        time_bin: actualTimeBin,
        direction: DEMAND_STATE.direction,
        granularity: getGranularityMinutes()
    });

    // Show loading in modal
    const timeLabel = formatTimeLabelZ(actualTimeBin);
    const endTime = new Date(binStartMs + intervalMs);
    const endLabel = formatTimeLabelZ(endTime.toISOString());

    Swal.fire({
        title: `Flights: ${timeLabel} - ${endLabel}`,
        html: '<div class="text-center"><i class="fas fa-spinner fa-spin fa-2x"></i><br>Loading flights...</div>',
        showConfirmButton: false,
        showCloseButton: true,
        width: '900px',
        didOpen: function() {
            $.getJSON(`api/demand/summary.php?${params.toString()}`)
                .done(function(response) {
                    if (response.success && response.flights) {
                        const html = buildFlightListHtml(response.flights, clickedSeries);
                        Swal.update({
                            html: html
                        });
                    } else {
                        Swal.update({
                            html: '<p class="text-muted">No flights found for this time period.</p>'
                        });
                    }
                })
                .fail(function() {
                    Swal.update({
                        html: '<p class="text-danger">Failed to load flight details.</p>'
                    });
                });
        }
    });
}

/**
 * Format time as HH:MMZ (consistent Zulu time format)
 */
function formatTimeLabelZ(isoString) {
    const date = new Date(isoString);
    const hours = date.getUTCHours().toString().padStart(2, '0');
    const minutes = date.getUTCMinutes().toString().padStart(2, '0');
    return `${hours}:${minutes}Z`;
}

/**
 * Build HTML for flight list with color-coded status and filter-aware columns
 * @param {Array} flights - Array of flight objects
 * @param {string} clickedSeries - Optional: the series name that was clicked for emphasis
 */
function buildFlightListHtml(flights, clickedSeries) {
    if (!flights || flights.length === 0) {
        return '<p class="text-muted">No flights found for this time period.</p>';
    }

    const chartView = DEMAND_STATE.chartView || 'status';
    const direction = DEMAND_STATE.direction || 'both';

    // Determine if we need an extra column based on chart view
    const showExtraColumn = chartView !== 'status';
    let extraColumnHeader = '';
    let extraColumnField = '';

    switch (chartView) {
        case 'origin':
            extraColumnHeader = 'Origin ARTCC';
            extraColumnField = 'origin_artcc';
            break;
        case 'dest':
            extraColumnHeader = 'Dest ARTCC';
            extraColumnField = 'dest_artcc';
            break;
        case 'carrier':
            extraColumnHeader = 'Carrier';
            extraColumnField = 'carrier';
            break;
        case 'weight':
            extraColumnHeader = 'Weight';
            extraColumnField = 'weight_class';
            break;
        case 'equipment':
            extraColumnHeader = 'Equipment';
            extraColumnField = 'aircraft';
            break;
        case 'rule':
            extraColumnHeader = 'Rule';
            extraColumnField = 'flight_rules';
            break;
        case 'dep_fix':
            extraColumnHeader = 'Dep Fix';
            extraColumnField = 'dfix';
            break;
        case 'arr_fix':
            extraColumnHeader = 'Arr Fix';
            extraColumnField = 'afix';
            break;
        case 'dp':
            extraColumnHeader = 'SID';
            extraColumnField = 'dp_name';
            break;
        case 'star':
            extraColumnHeader = 'STAR';
            extraColumnField = 'star_name';
            break;
    }

    // Time column header based on direction
    let timeHeader = 'Time';
    if (direction === 'arr') timeHeader = 'ETA';
    else if (direction === 'dep') timeHeader = 'ETD';

    let html = `
        <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
            <table class="table table-sm table-hover mb-0" style="font-size: 0.85rem;">
                <thead style="position: sticky; top: 0; background: #f8f9fa; z-index: 1;">
                    <tr>
                        <th style="border-bottom: 2px solid #dee2e6;">Callsign</th>
                        <th style="border-bottom: 2px solid #dee2e6;">Type</th>
                        <th style="border-bottom: 2px solid #dee2e6;">Origin</th>
                        <th style="border-bottom: 2px solid #dee2e6;">Dest</th>
                        <th style="border-bottom: 2px solid #dee2e6;">${timeHeader}</th>
                        ${showExtraColumn ? `<th style="border-bottom: 2px solid #dee2e6;">${extraColumnHeader}</th>` : ''}
                        <th style="border-bottom: 2px solid #dee2e6;">Status</th>
                    </tr>
                </thead>
                <tbody>
    `;

    flights.forEach(function(flight) {
        const status = flight.status || 'unknown';
        const statusStyle = getStatusBadgeStyle(status);
        const dirIcon = flight.direction === 'arrival'
            ? '<i class="fas fa-plane-arrival text-success"></i>'
            : '<i class="fas fa-plane-departure text-warning"></i>';
        const time = flight.time ? formatTimeLabelZ(flight.time) : '--';

        // Check if this row should be emphasized (matches clicked series)
        let rowStyle = '';
        let isEmphasized = false;
        if (clickedSeries) {
            // Normalize for comparison
            const normalizedClicked = clickedSeries.toLowerCase();
            const normalizedStatus = status.toLowerCase();

            // Check if this flight matches the clicked series
            if (chartView === 'status' && normalizedStatus === normalizedClicked) {
                isEmphasized = true;
            } else if (showExtraColumn) {
                const extraValue = (flight[extraColumnField] || '').toString();
                if (extraValue.toUpperCase() === clickedSeries.toUpperCase()) {
                    isEmphasized = true;
                }
            }
        }

        if (isEmphasized) {
            rowStyle = 'background-color: rgba(59, 130, 246, 0.15); font-weight: 600;';
        }

        // Build extra column cell with color coding
        let extraColumnCell = '';
        if (showExtraColumn) {
            const extraValue = flight[extraColumnField] || '--';
            const extraStyle = getExtraColumnStyle(chartView, extraValue);
            extraColumnCell = `<td><span style="${extraStyle}">${extraValue}</span></td>`;
        }

        html += `
            <tr style="${rowStyle}">
                <td><strong>${flight.callsign || '--'}</strong></td>
                <td>${flight.aircraft || '--'}</td>
                <td>${flight.origin || '--'}</td>
                <td>${flight.destination || '--'}</td>
                <td style="white-space: nowrap;">${time} ${dirIcon}</td>
                ${extraColumnCell}
                <td><span style="${statusStyle}">${FSM_PHASE_LABELS[status] || status}</span></td>
            </tr>
        `;
    });

    html += `
                </tbody>
            </table>
        </div>
        <div class="mt-2 text-muted small d-flex justify-content-between align-items-center">
            <div>
                <i class="fas fa-plane-arrival text-success"></i> Arrival &nbsp;
                <i class="fas fa-plane-departure text-warning"></i> Departure
            </div>
            <div>Total: <strong>${flights.length}</strong> flights</div>
        </div>
    `;

    return html;
}

/**
 * Get inline style for status badge using phase colors
 */
function getStatusBadgeStyle(status) {
    const bgColor = FSM_PHASE_COLORS[status] || '#6b7280';
    const textColor = getContrastTextColor(bgColor);
    return `background-color: ${bgColor}; color: ${textColor}; padding: 3px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 600; display: inline-block;`;
}

/**
 * Get inline style for extra column values based on chart view
 */
function getExtraColumnStyle(chartView, value) {
    if (!value || value === '--') {
        return 'color: #999;';
    }

    let bgColor = null;

    switch (chartView) {
        case 'origin':
        case 'dest':
            // Use ARTCC colors
            bgColor = getARTCCColor(value);
            break;
        case 'carrier':
            // Generate color from carrier code
            bgColor = getCarrierColor(value);
            break;
        case 'weight':
            // Weight class colors
            bgColor = getWeightClassColor(value);
            break;
        case 'rule':
            // IFR/VFR colors
            bgColor = value === 'IFR' ? '#3b82f6' : '#22c55e';
            break;
        case 'equipment':
        case 'dep_fix':
        case 'arr_fix':
        case 'dp':
        case 'star':
            // Generate color from value
            bgColor = getHashColor(value);
            break;
    }

    if (bgColor) {
        const textColor = getContrastTextColor(bgColor);
        return `background-color: ${bgColor}; color: ${textColor}; padding: 2px 6px; border-radius: 3px; font-size: 0.75rem; font-weight: 500; display: inline-block;`;
    }

    return '';
}

/**
 * Generate consistent color from carrier code
 */
function getCarrierColor(carrier) {
    // Common carriers with specific colors
    const CARRIER_COLORS = {
        'AAL': '#c41230', 'DAL': '#003366', 'UAL': '#002244', 'SWA': '#f9a825',
        'JBU': '#003876', 'ASA': '#00205b', 'FFT': '#00467f', 'SKW': '#1a1a1a',
        'RPA': '#00467f', 'ENY': '#c41230', 'PDT': '#c41230', 'PSA': '#c41230',
        'NKS': '#ffd700', 'AAY': '#ff6600', 'FDX': '#4d148c', 'UPS': '#351c15'
    };

    if (CARRIER_COLORS[carrier]) {
        return CARRIER_COLORS[carrier];
    }
    return getHashColor(carrier);
}

/**
 * Get color for weight class
 */
function getWeightClassColor(weightClass) {
    const WEIGHT_COLORS = {
        'SMALL': '#22c55e',
        'LARGE': '#3b82f6',
        'B757': '#f59e0b',
        'HEAVY': '#ef4444',
        'SUPER': '#9333ea'
    };
    return WEIGHT_COLORS[weightClass] || '#6b7280';
}

/**
 * Generate consistent color from any string value
 */
function getHashColor(str) {
    if (!str) return '#6b7280';
    let hash = 0;
    for (let i = 0; i < str.length; i++) {
        hash = str.charCodeAt(i) + ((hash << 5) - hash);
    }
    const hue = Math.abs(hash % 360);
    return `hsl(${hue}, 65%, 45%)`;
}

// Initialize when document is ready (only on demand.php page)
$(document).ready(function() {
    // Only initialize demand.php-specific code if we're on that page
    // Check for the demand chart container which only exists on demand.php
    if (document.getElementById('demand_chart')) {
        initDemand();
    }
});
