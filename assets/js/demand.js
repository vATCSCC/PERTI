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
        return PERTII18n.t('demand.config.arrDepFormat', { arr: arrPart, dep: depPart });
    }

    if (!configName) {return '--';}

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
        return PERTII18n.t('demand.config.arrDepFormat', { arr: arrPart, dep: depPart });
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
        'unknown': '#9333ea',
    };

    // Phase labels - use shared config if available
    const PHASE_LABELS = (typeof window.PHASE_LABELS !== 'undefined') ? window.PHASE_LABELS : {
        'arrived': PERTII18n.t('phase.arrived'),
        'disconnected': PERTII18n.t('phase.disconnected'),
        'descending': PERTII18n.t('phase.descending'),
        'enroute': PERTII18n.t('phase.enroute'),
        'departed': PERTII18n.t('phase.departed'),
        'taxiing': PERTII18n.t('phase.taxiing'),
        'prefile': PERTII18n.t('phase.prefile'),
        'actual_gs': PERTII18n.t('demand.phase.actualGs'),
        'simulated_gs': PERTII18n.t('demand.phase.simulatedGs'),
        'proposed_gs': PERTII18n.t('demand.phase.proposedGs'),
        'gs': PERTII18n.t('tmi.gs'),
        'actual_gdp': PERTII18n.t('demand.phase.actualGdp'),
        'simulated_gdp': PERTII18n.t('demand.phase.simulatedGdp'),
        'proposed_gdp': PERTII18n.t('demand.phase.proposedGdp'),
        'gdp': PERTII18n.t('tmi.gdpShort'),
        'exempt': PERTII18n.t('demand.phase.exempt'),
        'uncontrolled': PERTII18n.t('demand.phase.uncontrolled'),
        'unknown': PERTII18n.t('phase.unknown'),
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

        while (current < end) {
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
                    shadowColor: 'rgba(0,0,0,0.2)',
                },
            },
            itemStyle: {
                color: color,
                borderColor: type === 'departures' ? 'rgba(255,255,255,0.5)' : 'transparent',
                borderWidth: type === 'departures' ? 1 : 0,
            },
            data: data,
        };

        // Add diagonal hatching pattern for departures (FSM/TBFM style)
        if (type === 'departures') {
            seriesConfig.itemStyle.decal = {
                symbol: 'rect',
                symbolSize: 1,
                rotation: Math.PI / 4,
                color: 'rgba(255,255,255,0.4)',
                dashArrayX: [1, 0],
                dashArrayY: [3, 5],
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
                type: 'solid',
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
                borderWidth: 1,
            },
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
                adr_custom: { type: 'dotted', width: 2 },
            },
        };

        // Determine style: custom (override), suggested, or active
        const isCustom = rateData.has_override;
        const styleKey = isCustom ? 'custom' : (rateData.is_suggested ? 'suggested' : 'active');

        const addLine = function(value, source, rateType) {
            if (!value) {return;}

            const sourceStyle = cfg[styleKey][source];
            // Use dotted line style for custom/dynamic rates
            const lineStyleKey = isCustom ? (rateType + '_custom') : rateType;
            const lineTypeStyle = cfg.lineStyle[lineStyleKey] || cfg.lineStyle[rateType];

            lines.push({
                yAxis: value,
                lineStyle: {
                    color: sourceStyle.color,
                    width: lineTypeStyle.width,
                    type: lineTypeStyle.type,
                },
                label: {
                    show: false,  // Labels moved to chart header
                },
            });
        };

        if (direction === 'both' || direction === 'arr') {
            addLine(rates.vatsim_aar, 'vatsim', 'aar');
            addLine(rates.rw_aar, 'rw', 'aar');
        }

        if (direction === 'both' || direction === 'dep') {
            addLine(rates.vatsim_adr, 'vatsim', 'adr');
            addLine(rates.rw_adr, 'rw', 'adr');
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
        return PERTII18n.t('demand.chart.xAxisLabel', { minutes: minutes });
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
            showRateLines: options.showRateLines !== false,
        };

        // Handle window resize
        const resizeHandler = function() {
            if (state.chart) {state.chart.resize();}
        };
        window.addEventListener('resize', resizeHandler);

        return {
            load: function(airport, opts) {
                opts = opts || {};
                if (!airport) {
                    this.clear();
                    return Promise.resolve({ success: false, error: PERTII18n.t('demand.error.noAirportSpecified') });
                }

                state.airport = airport;
                if (opts.direction) {state.direction = opts.direction;}
                if (opts.granularity) {state.granularity = opts.granularity;}
                if (opts.timeBasis) {state.timeBasis = opts.timeBasis;}
                if (opts.programId !== undefined) {state.programId = opts.programId;}
                if (opts.timeRangeStart !== undefined) {state.timeRangeStart = opts.timeRangeStart;}
                if (opts.timeRangeEnd !== undefined) {state.timeRangeEnd = opts.timeRangeEnd;}

                const now = new Date();
                const start = new Date(now.getTime() + state.timeRangeStart * 60 * 60 * 1000);
                const end = new Date(now.getTime() + state.timeRangeEnd * 60 * 60 * 1000);

                const params = new URLSearchParams({
                    airport: airport,
                    granularity: state.granularity,
                    direction: state.direction,
                    start: start.toISOString(),
                    end: end.toISOString(),
                    time_basis: state.timeBasis,
                });

                // Add program_id if specified (for TMI-specific filtering)
                if (state.programId) {
                    params.append('program_id', state.programId);
                }

                state.chart.showLoading({ text: PERTII18n.t('common.loading'), maskColor: 'rgba(255,255,255,0.8)', textColor: '#333' });

                const self = this;
                const demandPromise = fetch('api/demand/airport.php?' + params.toString()).then(function(r) { return r.json(); });
                const ratesPromise = fetch('api/demand/rates.php?airport=' + encodeURIComponent(airport)).then(function(r) { return r.json(); }).catch(function() { return null; });

                return Promise.all([demandPromise, ratesPromise]).then(function(results) {
                    const demandResponse = results[0];
                    const ratesResponse = results[1];
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
                if (!state.chart || !state.lastData) {return;}

                const data = state.lastData;
                const arrivals = data.data.arrivals || [];
                const departures = data.data.departures || [];
                const direction = state.direction;

                console.log('[DemandChart] render - timeBasis:', state.timeBasis, 'arrivals:', arrivals.length, 'departures:', departures.length);

                const timeBins = generateAllTimeBins(state.granularity, state.timeRangeStart, state.timeRangeEnd);

                const arrivalsByBin = {};
                arrivals.forEach(function(d) { arrivalsByBin[normalizeTimeBin(d.time_bin)] = d.breakdown; });

                const departuresByBin = {};
                departures.forEach(function(d) { departuresByBin[normalizeTimeBin(d.time_bin)] = d.breakdown; });

                // Debug: Log first breakdown to see what keys are available
                if (arrivals.length > 0 && arrivals[0].breakdown) {
                    console.log('[DemandChart] Sample breakdown keys:', Object.keys(arrivals[0].breakdown));
                }

                const series = [];

                // When time_basis=ctd, only show TMI status breakdown (not flight phases)
                // to avoid double-counting controlled flights
                let phasesToRender;
                if (state.timeBasis === 'ctd') {
                    // TMI status phases only - no regular flight phases to avoid double-counting
                    phasesToRender = [
                        'uncontrolled',  // Flights not controlled by any TMI
                        'exempt',        // Exempt from TMI
                        'actual_gs', 'simulated_gs', 'proposed_gs',     // Ground Stop statuses
                        'actual_gdp', 'simulated_gdp', 'proposed_gdp',   // GDP statuses
                    ];
                } else {
                    // Standard flight phases when using ETA
                    phasesToRender = ['arrived', 'disconnected', 'descending', 'enroute',
                        'departed', 'taxiing', 'prefile', 'unknown'];
                }
                console.log('[DemandChart] phasesToRender:', phasesToRender);

                if (direction === 'arr' || direction === 'both') {
                    phasesToRender.forEach(function(phase) {
                        const suffix = direction === 'both' ? ' (' + PERTII18n.t('demand.direction.arrShort') + ')' : '';
                        series.push(buildPhaseSeriesTimeAxis(PHASE_LABELS[phase] + suffix, timeBins, arrivalsByBin, phase, 'arrivals', direction, state.granularity));
                    });
                }

                if (direction === 'dep' || direction === 'both') {
                    phasesToRender.forEach(function(phase) {
                        const suffix = direction === 'both' ? ' (' + PERTII18n.t('demand.direction.depShort') + ')' : '';
                        series.push(buildPhaseSeriesTimeAxis(PHASE_LABELS[phase] + suffix, timeBins, departuresByBin, phase, 'departures', direction, state.granularity));
                    });
                }

                const timeMarkLineData = getCurrentTimeMarkLineForTimeAxis();
                const rateMarkLines = buildRateMarkLinesForChart(state.rateData, direction, state.showRateLines);

                if (series.length > 0) {
                    const markLineData = [];
                    if (timeMarkLineData) {markLineData.push(timeMarkLineData);}
                    if (rateMarkLines && rateMarkLines.length > 0) {markLineData.push.apply(markLineData, rateMarkLines);}

                    if (markLineData.length > 0) {
                        series[0].markLine = { silent: true, symbol: ['none', 'none'], data: markLineData };
                    }
                }

                const intervalMs = getGranularityMinutes(state.granularity) * 60 * 1000;
                const chartTitle = buildChartTitle(data.airport, data.last_adl_update);
                const gran = state.granularity;

                const option = {
                    backgroundColor: '#ffffff',
                    title: {
                        text: chartTitle,
                        left: 'center',
                        top: 10,
                        textStyle: { fontSize: 13, fontWeight: 'bold', color: '#333', fontFamily: '"Inconsolata", "SF Mono", monospace' },
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
                            if (!params || params.length === 0) {return '';}
                            const timestamp = params[0].value[0];
                            const timeStr = formatTimeLabelFromTimestamp(timestamp, gran);
                            let tooltip = '<strong style="font-size:12px;">' + timeStr + '</strong><br/>';
                            let total = 0;
                            params.forEach(function(p) {
                                const val = p.value[1] || 0;
                                if (val > 0) {
                                    tooltip += p.marker + ' ' + p.seriesName + ': <strong>' + val + '</strong><br/>';
                                    total += val;
                                }
                            });
                            tooltip += '<hr style="margin:4px 0;border-color:#ddd;"/><strong>' + PERTII18n.t('demand.chart.total') + ': ' + total + '</strong>';
                            // Add rate information if available
                            if (state.rateData && state.rateData.rates) {
                                const rates = state.rateData.rates;
                                const proRateFactor = getGranularityMinutes(state.granularity) / 60;
                                const aar = rates.vatsim_aar ? Math.round(rates.vatsim_aar * proRateFactor) : null;
                                const adr = rates.vatsim_adr ? Math.round(rates.vatsim_adr * proRateFactor) : null;
                                if (aar || adr) {
                                    tooltip += '<hr style="margin:4px 0;border-color:#ddd;"/>';
                                    if (aar) {tooltip += '<span style="color:#000;">AAR: <strong>' + aar + '</strong></span>';}
                                    if (aar && adr) {tooltip += ' / ';}
                                    if (adr) {tooltip += '<span style="color:#000;">ADR: <strong>' + adr + '</strong></span>';}
                                }
                            }
                            return tooltip;
                        },
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
                            formatter: function(name) { return name.replace(' (Arr)', ''); },
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
                            formatter: function(name) { return name.replace(' (Dep)', ''); },
                        },
                    ] : {
                        bottom: 5,
                        left: 'center',
                        width: '85%',  // Allow wrapping
                        type: 'scroll',
                        itemWidth: 12,
                        itemHeight: 8,
                        itemGap: 10,   // Space between items
                        textStyle: { fontSize: 10, fontFamily: '"Segoe UI", sans-serif' },
                    },
                    grid: { left: 50, right: 100, bottom: 135, top: 45, containLabel: false },  // Room for x-axis title + legend + dataZoom
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
                                const d = new Date(value);
                                return d.getUTCHours().toString().padStart(2, '0') + d.getUTCMinutes().toString().padStart(2, '0') + 'Z';
                            },
                        },
                        splitLine: { show: true, lineStyle: { color: '#f0f0f0', type: 'solid' } },
                        min: new Date(timeBins[0]).getTime(),
                        max: new Date(timeBins[timeBins.length - 1]).getTime() + intervalMs,
                    },
                    yAxis: {
                        type: 'value',
                        name: PERTII18n.t('demand.chart.yAxisLabel'),
                        nameLocation: 'middle',
                        nameGap: 35,
                        nameTextStyle: { fontSize: 11, color: '#333', fontWeight: 500 },
                        minInterval: 1,
                        axisLine: { show: true, lineStyle: { color: '#333', width: 1 } },
                        axisTick: { show: true, lineStyle: { color: '#666' } },
                        axisLabel: { fontSize: 10, color: '#333', fontFamily: '"Inconsolata", monospace' },
                        splitLine: { show: true, lineStyle: { color: '#e8e8e8', type: 'dashed' } },
                    },
                    series: series,
                };

                state.chart.setOption(option, true);
            },

            update: function(opts) {
                let needsReload = false;
                let needsRender = false;

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
                if (state.chart) {state.chart.clear();}
            },

            getRateData: function() { return state.rateData; },
            getLastData: function() { return state.lastData; },
            getState: function() {
                return {
                    airport: state.airport,
                    direction: state.direction,
                    granularity: state.granularity,
                    timeBasis: state.timeBasis,
                    programId: state.programId,
                    timeRangeStart: state.timeRangeStart,
                    timeRangeEnd: state.timeRangeEnd,
                };
            },
            getSnapshot: function() {
                if (!state.lastData || !state.airport) return null;
                return {
                    airport: state.airport,
                    direction: state.direction,
                    granularity: state.granularity,
                    timeBasis: state.timeBasis,
                    timeRangeStart: state.timeRangeStart,
                    timeRangeEnd: state.timeRangeEnd,
                    demandData: state.lastData,
                    rateData: state.rateData,
                };
            },
            loadFromSnapshot: function(snapshot) {
                if (!snapshot || !snapshot.demandData) return;
                state.airport = snapshot.airport;
                state.direction = snapshot.direction || state.direction;
                state.granularity = snapshot.granularity || state.granularity;
                state.timeBasis = snapshot.timeBasis || state.timeBasis;
                if (snapshot.timeRangeStart !== undefined) state.timeRangeStart = snapshot.timeRangeStart;
                if (snapshot.timeRangeEnd !== undefined) state.timeRangeEnd = snapshot.timeRangeEnd;
                state.lastData = snapshot.demandData;
                state.rateData = snapshot.rateData || null;
                this.render();
            },
            dispose: function() {
                window.removeEventListener('resize', resizeHandler);
                if (state.chart) { state.chart.dispose(); state.chart = null; }
            },
            resize: function() { if (state.chart) {state.chart.resize();} },
            setTitle: function(text, subtext) {
                if (state.chart) {
                    state.chart.setOption({ title: { text: text, subtext: subtext || '' } });
                }
            },
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
        PHASE_ORDER: PHASE_ORDER,
    };
})();

// Alias for backwards compatibility with demand-chart.js API
window.DemandChart = {
    create: window.DemandChartCore.createChart,
    PHASE_COLORS: window.DemandChartCore.PHASE_COLORS,
    PHASE_LABELS: window.DemandChartCore.PHASE_LABELS,
    PHASE_ORDER: window.DemandChartCore.PHASE_ORDER,
};

// ============================================================================
// Page-specific demand visualization code (demand.php only)
// ============================================================================

// Global state
const DEMAND_STATE = {
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
    // Phase group visibility filters (all checked by default except unknown)
    phaseGroups: {
        prefile: true,      // PREFILE - filed but not connected
        departing: true,    // DEPARTING - taxiing at origin
        active: true,       // ACTIVE - departed/enroute/descending (airborne)
        arrived: true,      // ARRIVED - landed at destination
        disconnected: true, // DISCONNECTED - disconnected mid-flight
        unknown: false,      // UNKNOWN - other/unknown (hidden by default)
    },
    atisData: null, // Store ATIS data from API
    // Cache management
    cacheTimestamp: null, // When data was last loaded from API
    cacheValidityMs: 15000, // Cache is valid for 15 seconds
    summaryLoaded: false, // Whether summary breakdown data has been loaded
    // Legend visibility (persisted in localStorage)
    legendVisible: localStorage.getItem('demand_legend_visible') !== 'false', // default true
    // ECharts legend selected state (preserved across auto-refresh)
    legendSelected: {},
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
    'unknown': '#9333ea',        // Purple - Unknown/other phase (top)
};

// Phase labels - use shared config if available
const FSM_PHASE_LABELS = (typeof PHASE_LABELS !== 'undefined') ? PHASE_LABELS : {
    'arrived': PERTII18n.t('phase.arrived'),
    'disconnected': PERTII18n.t('phase.disconnected'),
    'descending': PERTII18n.t('phase.descending'),
    'enroute': PERTII18n.t('phase.enroute'),
    'departed': PERTII18n.t('phase.departed'),
    'taxiing': PERTII18n.t('phase.taxiing'),
    'prefile': PERTII18n.t('phase.prefile'),
    'unknown': PERTII18n.t('phase.unknown'),
};

// Phase group mapping - maps logical UI groups to database phase values
const PHASE_GROUP_MAP = {
    prefile: ['prefile'],
    departing: ['taxiing'],
    active: ['departed', 'enroute', 'descending'],
    arrived: ['arrived'],
    disconnected: ['disconnected'],
    unknown: ['unknown'],
};

// Reverse lookup - maps database phase to UI group
const PHASE_TO_GROUP = {
    prefile: 'prefile',
    taxiing: 'departing',
    departed: 'active',
    enroute: 'active',
    descending: 'active',
    arrived: 'arrived',
    disconnected: 'disconnected',
    unknown: 'unknown',
};

// ARTCC colors for origin breakdown visualization - uses PERTI when available
const ARTCC_COLORS = (typeof PERTI !== 'undefined' && PERTI.UI && PERTI.UI.ARTCC_COLORS)
    ? PERTI.UI.ARTCC_COLORS
    : {
        'ZNY': '#e41a1c', 'ZDC': '#377eb8', 'ZBW': '#4daf4a', 'ZOB': '#984ea3',
        'ZAU': '#ff7f00', 'ZID': '#ffff33', 'ZTL': '#a65628', 'ZJX': '#f781bf',
        'ZMA': '#999999', 'ZHU': '#66c2a5', 'ZFW': '#fc8d62', 'ZKC': '#8da0cb',
        'ZME': '#e78ac3', 'ZDV': '#a6d854', 'ZMP': '#ffd92f', 'ZAB': '#e5c494',
        'ZLA': '#b3b3b3', 'ZOA': '#1b9e77', 'ZSE': '#d95f02', 'ZLC': '#7570b3',
        'ZAN': '#e7298a', 'ZHN': '#66a61e',
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

// Aircraft manufacturer groupings for equipment chart legend
// Use PERTIAircraft as source of truth with fallback for load order
// Note: demand.js returns display names like 'Boeing', while PERTIAircraft keys are 'BOEING'
const AIRCRAFT_MANUFACTURERS = (typeof PERTIAircraft !== 'undefined' && PERTIAircraft.MANUFACTURERS)
    ? PERTIAircraft.MANUFACTURERS
    : {
        'Boeing': {
            order: 1,
            prefixes: ['B7', 'B3'],
            types: ['B712', 'B717', 'B721', 'B722', 'B727', 'B731', 'B732', 'B733', 'B734', 'B735', 'B736', 'B737', 'B738', 'B739',
                'B37M', 'B38M', 'B39M', 'B3XM', 'B741', 'B742', 'B743', 'B744', 'B748', 'B74D', 'B74R', 'B74S',
                'B752', 'B753', 'B762', 'B763', 'B764', 'B772', 'B773', 'B77L', 'B77W', 'B788', 'B789', 'B78X'],
        },
        'Airbus': {
            order: 2,
            // Note: A124/A148/A158/A225 are Antonov, not Airbus
            prefixes: ['A30', 'A31', 'A32', 'A33', 'A34', 'A35', 'A38'],
            types: ['A306', 'A30B', 'A310', 'A318', 'A319', 'A320', 'A321',
                'A19N', 'A20N', 'A21N',
                'A332', 'A333', 'A337', 'A338', 'A339',
                'A342', 'A343', 'A345', 'A346', 'A359', 'A35K', 'A388', 'A3ST'],
        },
        'Embraer': {
            order: 3,
            prefixes: ['E1', 'E2', 'E3', 'E4', 'E5', 'E6', 'E7', 'E9', 'ERJ'],
            types: ['E110', 'E120', 'E121', 'E135', 'E145', 'E170', 'E175', 'E190', 'E195', 'E290', 'E295',
                'E35L', 'E50P', 'E55P', 'ERJ1', 'ERJ2'],
        },
        'Bombardier': {
            order: 4,
            prefixes: ['CRJ', 'CL', 'GL', 'BD', 'CH'],
            types: ['BD10', 'BD70', 'CL30', 'CL35', 'CL60', 'CRJ1', 'CRJ2', 'CRJ7', 'CRJ9', 'CRJX',
                'GL5T', 'GL7T', 'GLEX', 'GLXS', 'CH30', 'CH35', 'C25A', 'C25B', 'C25C', 'C500', 'C510',
                'C525', 'C550', 'C560', 'C56X', 'C650', 'C680', 'C68A', 'C750'],
        },
        'McDonnell Douglas': {
            order: 5,
            prefixes: ['MD', 'DC'],
            types: ['DC10', 'DC3', 'DC6', 'DC85', 'DC86', 'DC87', 'DC9', 'DC93', 'DC94', 'DC95',
                'MD10', 'MD11', 'MD80', 'MD81', 'MD82', 'MD83', 'MD87', 'MD88', 'MD90'],
        },
        'ATR/De Havilland': {
            order: 6,
            prefixes: ['AT', 'DH', 'DHC'],
            types: ['AT43', 'AT44', 'AT45', 'AT46', 'AT72', 'AT73', 'AT75', 'AT76',
                'DH8A', 'DH8B', 'DH8C', 'DH8D', 'DHC2', 'DHC3', 'DHC4', 'DHC5', 'DHC6', 'DHC7'],
        },
        'Chinese': {
            order: 7,
            prefixes: ['C9', 'ARJ'],
            types: ['ARJ2', 'ARJ21', 'C919', 'MA60', 'Y12'],
        },
        'Russian': {
            order: 8,
            // Includes Antonov (Ukrainian - A124/A148/A158/A225 are Antonov ICAO codes)
            prefixes: ['IL', 'TU', 'AN', 'SSJ', 'SU', 'YK'],
            types: ['AN12', 'AN14', 'AN22', 'AN24', 'AN26', 'AN28', 'AN30', 'AN32', 'AN72', 'AN74',
                'A124', 'A148', 'A158', 'A225',
                'IL14', 'IL18', 'IL62', 'IL76', 'IL86', 'IL96', 'SSJ1', 'SU95', 'TU14', 'TU15', 'TU16', 'TU20', 'TU22', 'TU34',
                'TU54', 'T134', 'T144', 'T154', 'T204', 'T214', 'YK40', 'YK42'],
        },
        'Concorde': {
            order: 9,
            prefixes: ['CONC'],
            types: ['CONC'],
        },
    };

// Get manufacturer for an aircraft type
function getAircraftManufacturer(acType) {
    // Use PERTIAircraft if available
    if (typeof PERTIAircraft !== 'undefined' && PERTIAircraft.getManufacturerName) {
        return PERTIAircraft.getManufacturerName(acType);
    }

    // Fallback to local lookup
    if (!acType) {return PERTII18n.t('common.other');}
    const upper = acType.toUpperCase();

    // Check exact type matches first
    for (const [mfr, data] of Object.entries(AIRCRAFT_MANUFACTURERS)) {
        if (data.types && data.types.includes(upper)) {
            return mfr;
        }
    }

    // Check prefix matches
    for (const [mfr, data] of Object.entries(AIRCRAFT_MANUFACTURERS)) {
        for (const prefix of (data.prefixes || [])) {
            if (upper.startsWith(prefix)) {
                return mfr;
            }
        }
    }

    return PERTII18n.t('common.other');
}

// Get manufacturer order for sorting
function getManufacturerOrder(mfr) {
    if (typeof PERTIAircraft !== 'undefined' && PERTIAircraft.getManufacturerOrder) {
        return PERTIAircraft.getManufacturerOrder(mfr);
    }
    return AIRCRAFT_MANUFACTURERS[mfr]?.order || 99;
}

/**
 * Get DCC region color for an ARTCC
 * Uses FacilityHierarchy regional color scheme:
 * - Northeast (ZBW, ZDC, ZNY, ZOB): Blue
 * - Southeast (ZID, ZJX, ZMA, ZMO, ZTL): Yellow
 * - South Central (ZAB, ZFW, ZHO, ZHU, ZME): Orange
 * - Midwest (ZAU, ZDV, ZKC, ZMP): Green
 * - West (ZAK, ZAN, ZAP, ZHN, ZLA, ZLC, ZOA, ZSE, ZUA): Red
 * - Canada: Purple
 * - International: Various
 * @param {string} artcc - ARTCC code
 * @returns {string} Color hex code
 */
function getDCCRegionColor(artcc) {
    // Use PERTI namespace if available
    if (typeof PERTI !== 'undefined' && PERTI.getDCCColor && PERTI.getDCCRegion) {
        return PERTI.getDCCColor(PERTI.getDCCRegion(artcc));
    }
    // Use FacilityHierarchy if available
    if (typeof FH !== 'undefined' && FH.getRegionColor) {
        const regionColor = FH.getRegionColor(artcc);
        if (regionColor) {
            return regionColor;
        }
    }

    // Fallback: inline DCC region mapping
    const DCC_REGION_COLORS = {
        // Northeast - Blue
        'ZBW': '#007bff', 'ZDC': '#007bff', 'ZNY': '#007bff', 'ZOB': '#007bff', 'ZWY': '#007bff',
        // Southeast - Yellow/Gold
        'ZID': '#ffc107', 'ZJX': '#ffc107', 'ZMA': '#ffc107', 'ZMO': '#ffc107', 'ZTL': '#ffc107',
        // South Central - Orange
        'ZAB': '#fd7e14', 'ZFW': '#fd7e14', 'ZHO': '#fd7e14', 'ZHU': '#fd7e14', 'ZME': '#fd7e14',
        // Midwest - Green
        'ZAU': '#28a745', 'ZDV': '#28a745', 'ZKC': '#28a745', 'ZMP': '#28a745',
        // West - Red
        'ZAK': '#dc3545', 'ZAN': '#dc3545', 'ZAP': '#dc3545', 'ZHN': '#dc3545',
        'ZLA': '#dc3545', 'ZLC': '#dc3545', 'ZOA': '#dc3545', 'ZSE': '#dc3545', 'ZUA': '#dc3545',
        // Canada - Purple
        'CZEG': '#6f42c1', 'CZVR': '#6f42c1', 'CZWG': '#6f42c1', 'CZYZ': '#6f42c1',
        'CZQM': '#6f42c1', 'CZQX': '#6f42c1', 'CZQO': '#6f42c1', 'CZUL': '#6f42c1',
        // Mexico - Teal-green
        'MMMX': '#20c997', 'MMTY': '#20c997', 'MMZT': '#20c997',
        'MMMD': '#20c997', 'MMUN': '#20c997', 'MMFR': '#20c997', 'MMFO': '#20c997',
        // Caribbean - Pink
        'TJZS': '#e83e8c', 'MKJK': '#e83e8c', 'MUFH': '#e83e8c', 'MYNA': '#e83e8c',
        'MDCS': '#e83e8c', 'TNCF': '#e83e8c', 'TTZP': '#e83e8c', 'MHCC': '#e83e8c', 'MPZL': '#e83e8c',
        // ECFMP - Cyan
        'EGPX': '#17a2b8', 'EGTT': '#17a2b8', 'EISN': '#17a2b8',
        // International oceanic regions
        'ASIA': '#e83e8c', 'EURO': '#17a2b8', 'INTL': '#6c757d', 'YPAC': '#17a2b8',
    };

    if (DCC_REGION_COLORS[artcc]) {
        return DCC_REGION_COLORS[artcc];
    }

    // Generate consistent color for unknown ARTCCs
    let hash = 0;
    for (let i = 0; i < artcc.length; i++) {
        hash = artcc.charCodeAt(i) + ((hash << 5) - hash);
    }
    const hue = Math.abs(hash % 360);
    return `hsl(${hue}, 70%, 50%)`;
}

// DCC Region display order for legend grouping - PERTI > FacilityHierarchy > fallback
const DCC_REGION_ORDER = (typeof PERTI !== 'undefined' && PERTI.GEOGRAPHIC && PERTI.GEOGRAPHIC.DCC_REGION_ORDER)
    ? PERTI.GEOGRAPHIC.DCC_REGION_ORDER
    : {
        'NORTHEAST': 1,
        'SOUTHEAST': 2,
        'SOUTH_CENTRAL': 3,
        'MIDWEST': 4,
        'WEST': 5,
        'CANADA': 6,
        'MEXICO': 7,
        'CARIBBEAN': 8,
        'Other': 99,
    };

// Get DCC region name for an ARTCC (uses global FacilityHierarchy if available)
function getARTCCRegion(artcc) {
    if (!artcc) {return PERTII18n.t('common.other');}

    // Use global FacilityHierarchy if available
    if (typeof FacilityHierarchy !== 'undefined' && FacilityHierarchy.getRegion) {
        const region = FacilityHierarchy.getRegion(artcc);
        if (region) {return region;}
    }

    return PERTII18n.t('common.other');
}

// Get region display name
function getRegionDisplayName(regionKey) {
    if (typeof FacilityHierarchy !== 'undefined' && FacilityHierarchy.DCC_REGIONS && FacilityHierarchy.DCC_REGIONS[regionKey]) {
        return FacilityHierarchy.DCC_REGIONS[regionKey].name;
    }
    return regionKey;
}

// Get region order for sorting
function getRegionOrder(region) {
    return DCC_REGION_ORDER[region] || 99;
}

/**
 * Normalize a procedure name (DP/STAR) by replacing version number and suffix with placeholders
 * Examples:
 *   - CINDY2D, CINDY8S → CINDY#?    (international format: NAME + digit + letter)
 *   - MARUN2D, MARUN6E → MARUN#?
 *   - KERAX4D, KERAX5D → KERAX#?
 *   - SKORR4, SKORR5 → SKORR#      (US format: NAME + digit)
 *   - RNAV procedures often have numbers in the name, so we match trailing digit+letter
 * @param {string} name - Procedure name
 * @returns {string} Normalized procedure name
 */
function normalizeProcedureName(name) {
    if (!name || name === 'UNKNOWN') return name;

    // Pattern: Base name (letters) + digit(s) + optional single letter suffix
    // Match: CINDY2D, MARUN6E, KERAX5D, SKORR4, DEBHI1C, EMPAX5C
    // The pattern looks for: letters followed by one or more digits, optionally followed by a single letter at end
    const match = name.match(/^([A-Z]+)(\d+)([A-Z])?$/);
    if (match) {
        const base = match[1];
        const suffix = match[3]; // May be undefined for US procedures
        if (suffix) {
            return `${base}#?`;  // International: CINDY#?, KERAX#?
        } else {
            return `${base}#`;   // US: SKORR#
        }
    }

    return name; // No match, return original
}

/**
 * Normalize breakdown data by grouping procedures with the same base name
 * @param {Object} breakdown - Breakdown data keyed by time bin
 * @param {string} categoryKey - Key to read/write the category (e.g., 'dp', 'star')
 * @returns {Object} Normalized breakdown with grouped procedures
 */
function normalizeBreakdownByProcedure(breakdown, categoryKey) {
    if (!breakdown) return breakdown;

    const normalized = {};

    for (const timeBin in breakdown) {
        const items = breakdown[timeBin];
        if (!Array.isArray(items)) {
            normalized[timeBin] = items;
            continue;
        }

        // Group items by normalized procedure name
        const grouped = {};
        items.forEach(item => {
            const originalName = item[categoryKey];
            const normalizedName = normalizeProcedureName(originalName);

            if (!grouped[normalizedName]) {
                grouped[normalizedName] = {
                    [categoryKey]: normalizedName,
                    count: 0,
                    phases: {},
                };
            }

            // Sum counts
            grouped[normalizedName].count += item.count || 0;

            // Merge phase breakdowns
            if (item.phases) {
                for (const phase in item.phases) {
                    grouped[normalizedName].phases[phase] =
                        (grouped[normalizedName].phases[phase] || 0) + item.phases[phase];
                }
            }
        });

        // Convert back to array
        normalized[timeBin] = Object.values(grouped);
    }

    return normalized;
}

/**
 * Check if a specific phase is enabled based on phase group selections
 * @param {string} phase - Phase name (e.g., 'enroute', 'taxiing', 'arrived')
 * @returns {boolean} True if the phase should be displayed
 */
function isPhaseEnabled(phase) {
    const group = PHASE_TO_GROUP[phase];
    return group ? DEMAND_STATE.phaseGroups[group] : false;
}

/**
 * Get array of enabled phases based on phase group selections
 * @returns {Array} Array of phase strings to include in rendering
 */
function getEnabledPhases() {
    const enabled = [];
    for (const group in DEMAND_STATE.phaseGroups) {
        if (DEMAND_STATE.phaseGroups[group] && PHASE_GROUP_MAP[group]) {
            enabled.push(...PHASE_GROUP_MAP[group]);
        }
    }
    return enabled;
}

/**
 * Sync phase filter checkbox DOM state from DEMAND_STATE.phaseGroups.
 * Ensures visual checkbox state matches JS state after refresh cycles.
 */
function syncPhaseCheckboxes() {
    for (var group in DEMAND_STATE.phaseGroups) {
        var $cb = $('#phase_' + group);
        if ($cb.length) {
            $cb.prop('checked', DEMAND_STATE.phaseGroups[group]);
        }
    }
}

/**
 * Sync rate line checkbox DOM state from DEMAND_STATE.
 * Ensures visual checkbox state matches JS state after refresh cycles.
 */
function syncRateCheckboxes() {
    $('#rate_vatsim_aar').prop('checked', DEMAND_STATE.showVatsimAar);
    $('#rate_vatsim_adr').prop('checked', DEMAND_STATE.showVatsimAdr);
    $('#rate_rw_aar').prop('checked', DEMAND_STATE.showRwAar);
    $('#rate_rw_adr').prop('checked', DEMAND_STATE.showRwAdr);
}

/**
 * Show chart loading overlay for filter changes
 */
function showChartLoading() {
    $('#chart_loading_overlay').addClass('visible');
}

/**
 * Hide chart loading overlay
 */
function hideChartLoading() {
    $('#chart_loading_overlay').removeClass('visible');
}

/**
 * Render current view with loading indicator
 * Shows a brief loading state to provide visual feedback on filter changes
 */
function renderWithLoading() {
    if (!DEMAND_STATE.lastDemandData) {return;}

    showChartLoading();

    // Use requestAnimationFrame to ensure the loading indicator is painted
    // before starting the potentially heavy render operation
    requestAnimationFrame(() => {
        // Small timeout to ensure overlay is visible before render blocks
        setTimeout(() => {
            renderCurrentView();
            syncPhaseCheckboxes();
            syncRateCheckboxes();
            hideChartLoading();
        }, 50);
    });
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
    { value: 'custom', label: PERTII18n.t('demand.timeRange.custom'), start: null, end: null },
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
        if (!merged.rates) {merged.rates = {};}
        merged.rates.vatsim_aar = tmiConfig.aar;
    }

    if (tmiConfig.adr !== null && tmiConfig.adr !== undefined) {
        if (!merged.rates) {merged.rates = {};}
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
            vatsim_adr: tmiConfig.adr,
        },
        is_suggested: false,
        has_override: false,
        rate_source: 'TMI',
        match_type: 'TMI',
        tmi_source: true,
        tmi_config: tmiConfig,
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
    select.append('<option value="">' + PERTII18n.t('demand.filter.allArtccs') + '</option>');

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
                showError(PERTII18n.t('demand.error.loadAirportList'));
            }
        })
        .fail(function(err) {
            console.error('API error loading airports:', err);
            showError(PERTII18n.t('demand.error.connectingToServer'));
        });
}

/**
 * Populate airport dropdown with loaded airports
 */
function populateAirportDropdown(airports) {
    const select = $('#demand_airport');
    const currentValue = select.val();

    select.empty();
    select.append('<option value="">-- ' + PERTII18n.t('demand.filter.selectAirport') + ' --</option>');

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
            showError(PERTII18n.t('demand.error.selectBothTimes'));
            return;
        }

        // Parse datetime-local values as UTC
        const startDate = parseDateTimeLocalAsUTC(startVal);
        const endDate = parseDateTimeLocalAsUTC(endVal);

        if (endDate <= startDate) {
            showError(PERTII18n.t('demand.error.endAfterStart'));
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
        DEMAND_STATE.legendSelected = {}; // Reset legend state when series names change
        if (DEMAND_STATE.selectedAirport && DEMAND_STATE.lastDemandData) {
            // Check if we need to load breakdown data (only if not cached or cache expired)
            const needsSummaryData = DEMAND_STATE.chartView !== 'status';
            const hasCachedSummary = DEMAND_STATE.summaryLoaded && isCacheValid();

            if (needsSummaryData && !hasCachedSummary) {
                // Load summary data and then render
                loadFlightSummary(true);
            } else {
                // Use cached data - show loading indicator during render
                renderWithLoading();
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
        updateHeaderRateDisplay(DEMAND_STATE.rateData);
        renderWithLoading();
    });

    $('#rate_vatsim_adr').on('change', function() {
        DEMAND_STATE.showVatsimAdr = $(this).is(':checked');
        updateHeaderRateDisplay(DEMAND_STATE.rateData);
        renderWithLoading();
    });

    $('#rate_rw_aar').on('change', function() {
        DEMAND_STATE.showRwAar = $(this).is(':checked');
        updateHeaderRateDisplay(DEMAND_STATE.rateData);
        renderWithLoading();
    });

    $('#rate_rw_adr').on('change', function() {
        DEMAND_STATE.showRwAdr = $(this).is(':checked');
        updateHeaderRateDisplay(DEMAND_STATE.rateData);
        renderWithLoading();
    });

    // Phase group filter toggles
    ['prefile', 'departing', 'active', 'arrived', 'disconnected', 'unknown'].forEach(group => {
        $(`#phase_${group}`).on('change', function() {
            DEMAND_STATE.phaseGroups[group] = $(this).is(':checked');
            renderWithLoading();
        });
    });

    // Legend toggle button
    $('#demand_legend_toggle_btn').on('click', function() {
        toggleLegendVisibility();
    });

    // Initialize legend toggle button state from localStorage
    const toggleText = document.getElementById('legend_toggle_text');
    const toggleBtn = document.getElementById('demand_legend_toggle_btn');
    if (toggleText && toggleBtn) {
        if (DEMAND_STATE.legendVisible) {
            toggleText.textContent = PERTII18n.t('demand.legend.hide');
            toggleBtn.querySelector('i').className = 'fas fa-eye-slash';
        } else {
            toggleText.textContent = PERTII18n.t('demand.legend.show');
            toggleBtn.querySelector('i').className = 'fas fa-eye';
        }
    }

    // Initialize floating phase filter panel
    initPhaseFilterFloatingPanel();
}

/**
 * Initialize floating phase filter panel functionality
 */
function initPhaseFilterFloatingPanel() {
    const $floatingPanel = $('#phase-filter-floating');
    const $inlineContainer = $('#phase-filter-inline-container');
    const $checkboxes = $('#phase-filter-checkboxes');
    const $floatingBody = $('#phase-filter-floating-body');
    const $popoutBtn = $('#phase-filter-popout-btn');
    const $collapseBtn = $('#phase-filter-collapse-btn');
    const $closeBtn = $('#phase-filter-close-btn');
    const $panelHeader = $floatingPanel.find('.panel-header');

    // Default position (near top-right of chart area)
    const panelPos = { x: window.innerWidth - 200, y: 200 };

    // Pop out to floating panel
    $popoutBtn.on('click', function() {
        // Move checkboxes to floating panel
        $checkboxes.appendTo($floatingBody);

        // Hide inline container content, show placeholder
        $inlineContainer.find('.demand-label').parent().hide();
        $inlineContainer.append('<div id="phase-filter-placeholder" class="text-muted small" style="font-size:0.7rem;"><i class="fas fa-external-link-alt mr-1"></i> ' + PERTII18n.t('demand.phaseFilter.floatingPanelOpen') + '</div>');

        // Position and show floating panel
        $floatingPanel.css({
            left: panelPos.x + 'px',
            top: panelPos.y + 'px',
        }).addClass('visible');
    });

    // Collapse/expand toggle
    $collapseBtn.on('click', function() {
        $floatingPanel.toggleClass('collapsed');
        const $icon = $(this).find('i');
        if ($floatingPanel.hasClass('collapsed')) {
            $icon.removeClass('fa-minus').addClass('fa-plus');
            $(this).attr('title', PERTII18n.t('demand.panel.expand'));
        } else {
            $icon.removeClass('fa-plus').addClass('fa-minus');
            $(this).attr('title', PERTII18n.t('demand.panel.collapse'));
        }
    });

    // Close and return to inline position
    $closeBtn.on('click', function() {
        // Save current position
        panelPos.x = parseInt($floatingPanel.css('left'));
        panelPos.y = parseInt($floatingPanel.css('top'));

        // Move checkboxes back to inline container
        $('#phase-filter-placeholder').remove();
        $inlineContainer.find('.demand-label').parent().show();
        $checkboxes.appendTo($inlineContainer);

        // Hide floating panel and reset collapsed state
        $floatingPanel.removeClass('visible collapsed');
        $collapseBtn.find('i').removeClass('fa-plus').addClass('fa-minus');
        $collapseBtn.attr('title', PERTII18n.t('demand.panel.collapse'));
    });

    // Dragging functionality
    let isDragging = false;
    const dragOffset = { x: 0, y: 0 };

    // Helper to restore normal state after drag ends
    function endDrag() {
        if (isDragging) {
            isDragging = false;
            $('body').css('user-select', '');
            // Re-enable pointer events on chart
            $('#demand_chart').css('pointer-events', '');
            // Save position
            panelPos.x = parseInt($floatingPanel.css('left')) || 0;
            panelPos.y = parseInt($floatingPanel.css('top')) || 0;
        }
    }

    $panelHeader.on('mousedown', function(e) {
        if ($(e.target).closest('.panel-btn').length) {return;} // Don't drag when clicking buttons
        isDragging = true;
        // For position:fixed elements, use CSS left/top values (viewport-relative)
        // to match e.clientX/clientY (also viewport-relative)
        const currentLeft = parseInt($floatingPanel.css('left')) || 0;
        const currentTop = parseInt($floatingPanel.css('top')) || 0;
        dragOffset.x = e.clientX - currentLeft;
        dragOffset.y = e.clientY - currentTop;
        $('body').css('user-select', 'none');
        // Disable pointer events on chart so drag works over it
        $('#demand_chart').css('pointer-events', 'none');
    });

    $(document).on('mousemove', function(e) {
        if (!isDragging) {return;}

        let newX = e.clientX - dragOffset.x;
        let newY = e.clientY - dragOffset.y;

        // Keep within viewport bounds
        const panelWidth = $floatingPanel.outerWidth();
        const panelHeight = $floatingPanel.outerHeight();
        newX = Math.max(0, Math.min(newX, window.innerWidth - panelWidth));
        newY = Math.max(0, Math.min(newY, window.innerHeight - panelHeight));

        $floatingPanel.css({
            left: newX + 'px',
            top: newY + 'px',
        });
    });

    // End drag on mouseup
    $(document).on('mouseup', endDrag);

    // Also end drag if mouse leaves window (prevents stuck state)
    $(document).on('mouseleave', endDrag);

    // Handle window resize - keep panel in bounds
    $(window).on('resize', function() {
        if (!$floatingPanel.hasClass('visible')) {return;}

        const panelWidth = $floatingPanel.outerWidth();
        const panelHeight = $floatingPanel.outerHeight();
        let currentX = parseInt($floatingPanel.css('left'));
        let currentY = parseInt($floatingPanel.css('top'));

        currentX = Math.max(0, Math.min(currentX, window.innerWidth - panelWidth));
        currentY = Math.max(0, Math.min(currentY, window.innerHeight - panelHeight));

        $floatingPanel.css({
            left: currentX + 'px',
            top: currentY + 'px',
        });
    });
}

/**
 * Update tier options based on selected ARTCC
 */
function updateTierOptions() {
    const artcc = DEMAND_STATE.artcc;
    const select = $('#demand_tier');
    select.empty();
    select.append('<option value="all">' + PERTII18n.t('demand.filter.allTiers') + '</option>');

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

        if (hasInternal) {select.append(`<option value="${internalCode}">${PERTII18n.t('demand.filter.internal')}</option>`);}
        if (has1stTier) {select.append(`<option value="${tier1Code}">${PERTII18n.t('demand.filter.firstTier')}</option>`);}
        if (has2ndTier) {select.append(`<option value="${tier2Code}">${PERTII18n.t('demand.filter.secondTier')}</option>`);}
    }
}

/**
 * Show prompt to select an airport
 */
function showSelectAirportPrompt() {
    // Show empty state, hide chart and legend toggle
    $('#demand_empty_state').show();
    $('#demand_chart').hide();
    $('#demand_legend_toggle_area').hide();

    // Hide header rate display
    $('#demand_header_aar_row').hide();
    $('#demand_header_adr_row').hide();

    // Reset info bar
    $('#demand_selected_airport').text('----');
    $('#demand_airport_name').text(PERTII18n.t('demand.prompt.selectAirport'));

    // Reset stats
    $('#demand_arr_total, #demand_arr_active, #demand_arr_scheduled, #demand_arr_proposed').text('0');
    $('#demand_dep_total, #demand_dep_active, #demand_dep_scheduled, #demand_dep_proposed').text('0');
    $('#demand_flight_count').text(PERTII18n.t('flight.other', { count: 0 }));

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

    // Hide empty state, show chart and legend toggle
    $('#demand_empty_state').hide();
    $('#demand_chart').show();
    $('#demand_legend_toggle_area').show();

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
        end: end.toISOString(),
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
                showError(PERTII18n.t('demand.error.connectingToServer'));
                return;
            }

            const demandResponse = demandResult.value;
            if (!demandResponse.success) {
                console.error('API error:', demandResponse.error);
                showError(PERTII18n.t('demand.error.loadDemandData') + ': ' + demandResponse.error);
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

            // Sync checkbox DOM state to ensure visual state matches JS state after refresh
            syncPhaseCheckboxes();
            syncRateCheckboxes();
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
        // Also clear header rate display
        updateHeaderRateDisplay(null);
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
        if (rateData.arr_runways) {tooltip += `ARR: ${rateData.arr_runways}\n`;}
        if (rateData.dep_runways) {tooltip += `DEP: ${rateData.dep_runways}`;}
    }
    // Add override info to tooltip
    if (rateData.has_override && rateData.override_reason) {
        tooltip += '\n\n' + PERTII18n.t('demand.rate.override', { reason: rateData.override_reason });
    }
    // Add TMI config info to tooltip
    if (rateData.tmi_source && rateData.tmi_config) {
        const tmi = rateData.tmi_config;
        tooltip += '\n\n--- ' + PERTII18n.t('demand.rate.tmiPublishedConfig') + ' ---';
        if (tmi.aar_type) {tooltip += '\n' + PERTII18n.t('demand.rate.aarType', { type: tmi.aar_type });}
        if (tmi.created_by_name) {tooltip += '\n' + PERTII18n.t('demand.rate.publishedBy', { name: tmi.created_by_name });}
        if (tmi.valid_from) {
            const validFrom = new Date(tmi.valid_from);
            tooltip += '\n' + PERTII18n.t('demand.rate.validFrom', { time: validFrom.toUTCString().replace('GMT', 'Z') });
        }
        if (tmi.valid_until) {
            const validUntil = new Date(tmi.valid_until);
            tooltip += '\n' + PERTII18n.t('demand.rate.validUntil', { time: validUntil.toUTCString().replace('GMT', 'Z') });
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
            $overrideBadge.attr('title', PERTII18n.t('demand.rate.overrideActiveUntil', { time: endStr }));
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
            'EXACT': PERTII18n.t('demand.matchType.exact'),
            'PARTIAL_ARR': PERTII18n.t('demand.matchType.partial'),
            'PARTIAL_DEP': PERTII18n.t('demand.matchType.partial'),
            'SUBSET_ARR': PERTII18n.t('demand.matchType.subset'),
            'SUBSET_DEP': PERTII18n.t('demand.matchType.subset'),
            'WIND_BASED': PERTII18n.t('demand.matchType.wind'),
            'CAPACITY_DEFAULT': PERTII18n.t('demand.matchType.default'),
            'VMC_FALLBACK': PERTII18n.t('demand.matchType.fallback'),
            'DETECTED_TRACKS': PERTII18n.t('demand.matchType.detected'),
            'MANUAL': PERTII18n.t('demand.matchType.manual'),
            'TMI': PERTII18n.t('demand.matchType.tmi'),
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
        sourceText = PERTII18n.t('demand.matchType.suggested');
    }
    $('#rate_source').text(sourceText);

    // Also update header rate display
    updateHeaderRateDisplay(rateData);
}

/**
 * Get age badge color based on minutes
 */
function getAgeBadgeColor(ageMins) {
    if (ageMins < 15) {return '#10b981';} // green - fresh
    if (ageMins < 30) {return '#f59e0b';} // amber - getting stale
    return '#ef4444'; // red - stale
}

/**
 * Format age text for display
 */
function formatAgeText(ageMins) {
    if (ageMins === null || ageMins === undefined) {return '--';}
    if (ageMins < 1) {return '<1m';}
    if (ageMins < 60) {return ageMins + 'm';}
    return Math.floor(ageMins / 60) + 'h';
}

/**
 * Build HTML for a single ATIS badge
 */
function buildAtisBadge(atis, type, labelPrefix) {
    if (!atis) {return '';}

    const code = atis.atis_code || '?';
    const ageMins = atis.age_mins || 0;
    const ageColor = getAgeBadgeColor(ageMins);
    const ageText = formatAgeText(ageMins);
    const typeLabel = labelPrefix ? `<span class="badge-atis-type">${labelPrefix}</span>` : '';

    return `<span class="atis-badge-group" data-atis-type="${type}" title="${PERTII18n.t('demand.atis.badgeTooltip', { type: type.toUpperCase(), code: code, age: ageText })}">
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
    if (!atis) {return '';}

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
                <span class="badge badge-secondary ml-1">${atis.callsign || PERTII18n.t('common.unknown')}</span>
                <span class="badge badge-atis ml-1">${atis.atis_code || '?'}</span>
                <span class="badge badge-dark ml-1">${fetchedTime}</span>
                <span class="badge ml-1" style="background-color: ${ageColor}; color: #fff;">${formatAgeText(atis.age_mins)}</span>
            </div>
            <div class="border rounded p-2" style="background: #f8f9fa; font-family: monospace; font-size: 0.8rem; white-space: pre-wrap; max-height: 150px; overflow-y: auto;">
${atis.atis_text || PERTII18n.t('demand.atis.noAtisTextAvailable')}
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
            title: PERTII18n.t('demand.atis.noAtisAvailable'),
            text: PERTII18n.t('demand.atis.noAtisText'),
            timer: 3000,
            showConfirmButton: false,
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
        runwayDetailsHtml = '<div class="mt-3"><strong>' + PERTII18n.t('demand.atis.runwayDetails') + ':</strong><ul class="mb-0 pl-3">';
        runways.details.forEach(rwy => {
            const useLabel = rwy.runway_use === 'ARR' ? PERTII18n.t('demand.direction.arrivals') : (rwy.runway_use === 'DEP' ? PERTII18n.t('demand.direction.departures') : PERTII18n.t('demand.direction.both'));
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
        atisSectionsHtml = buildAtisSection(atisComb, PERTII18n.t('demand.atis.typeCombined'), '#6366f1');
        titleSuffix = atisComb.atis_code || '';
    } else if (effectiveSource === 'ARR_DEP') {
        // Both ARR and DEP available
        if (atisArr) {
            atisSectionsHtml += buildAtisSection(atisArr, PERTII18n.t('demand.atis.typeArrival'), '#22c55e');
        }
        if (atisDep) {
            atisSectionsHtml += buildAtisSection(atisDep, PERTII18n.t('demand.atis.typeDeparture'), '#f97316');
        }
        const codes = [];
        if (atisArr?.atis_code) {codes.push('A:' + atisArr.atis_code);}
        if (atisDep?.atis_code) {codes.push('D:' + atisDep.atis_code);}
        titleSuffix = codes.join(' / ');
    } else if (effectiveSource === 'ARR_ONLY' && atisArr) {
        // Arrival only
        atisSectionsHtml = buildAtisSection(atisArr, PERTII18n.t('demand.atis.typeArrival'), '#22c55e');
        titleSuffix = atisArr.atis_code || '';
    } else if (effectiveSource === 'DEP_ONLY' && atisDep) {
        // Departure only
        atisSectionsHtml = buildAtisSection(atisDep, PERTII18n.t('demand.atis.typeDeparture'), '#f97316');
        titleSuffix = atisDep.atis_code || '';
    } else {
        // Fallback to primary atis object
        const atis = atisData.atis;
        if (atis) {
            const typeLabel = atis.atis_type === 'ARR' ? PERTII18n.t('demand.atis.typeArrival') : (atis.atis_type === 'DEP' ? PERTII18n.t('demand.atis.typeDeparture') : PERTII18n.t('demand.atis.typeCombined'));
            const typeColor = atis.atis_type === 'ARR' ? '#22c55e' : (atis.atis_type === 'DEP' ? '#f97316' : '#6366f1');
            atisSectionsHtml = buildAtisSection(atis, typeLabel, typeColor);
            titleSuffix = atis.atis_code || '';
        }
    }

    // Source indicator
    const sourceLabel = {
        'COMB': PERTII18n.t('demand.atis.sourceCombined'),
        'ARR_DEP': PERTII18n.t('demand.atis.sourceSeparate'),
        'ARR_ONLY': PERTII18n.t('demand.atis.sourceArrOnly'),
        'DEP_ONLY': PERTII18n.t('demand.atis.sourceDepOnly'),
    }[effectiveSource] || PERTII18n.t('common.unknown');

    Swal.fire({
        title: `<i class="fas fa-broadcast-tower mr-2"></i> ${atisData.airport_icao} ATIS ${titleSuffix}`,
        html: `
            <div class="text-left">
                <div class="mb-3 text-center">
                    <span class="badge badge-secondary">${sourceLabel}</span>
                </div>
                <div class="mb-3">
                    <strong>${PERTII18n.t('demand.atis.arrivalRunways')}:</strong> ${runways?.arr_runways || PERTII18n.t('common.na')}<br>
                    <strong>${PERTII18n.t('demand.atis.departureRunways')}:</strong> ${runways?.dep_runways || PERTII18n.t('common.na')}<br>
                    <strong>${PERTII18n.t('demand.atis.approaches')}:</strong> ${runways?.approach_info || PERTII18n.t('common.na')}
                </div>
                ${runwayDetailsHtml}
                <hr>
                <div class="mt-3">
                    <strong>${PERTII18n.t('demand.atis.atisInformation')}:</strong>
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
            popup: 'text-left',
        },
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

    // Capture legend selected state before replacing chart options
    DEMAND_STATE.legendSelected = captureLegendSelected();

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
    // Filter by enabled phase groups
    const series = [];
    const allPhases = ['arrived', 'disconnected', 'descending', 'enroute', 'departed', 'taxiing', 'prefile', 'unknown'];
    const phaseOrder = allPhases.filter(phase => isPhaseEnabled(phase));

    if (direction === 'arr' || direction === 'both') {
        // Build arrival series by phase (normalize time bins for lookup)
        const arrivalsByBin = {};
        arrivals.forEach(d => { arrivalsByBin[normalizeTimeBin(d.time_bin)] = d.breakdown; });

        // Add series in stacking order (bottom to top)
        phaseOrder.forEach(phase => {
            const suffix = direction === 'both' ? ' (' + PERTII18n.t('demand.direction.arrShort') + ')' : '';
            series.push(
                buildPhaseSeriesTimeAxis(FSM_PHASE_LABELS[phase] + suffix, timeBins, arrivalsByBin, phase, 'arrivals', direction),
            );
        });
    }

    if (direction === 'dep' || direction === 'both') {
        // Build departure series by phase (normalize time bins for lookup)
        const departuresByBin = {};
        departures.forEach(d => { departuresByBin[normalizeTimeBin(d.time_bin)] = d.breakdown; });

        // Add series in stacking order (bottom to top)
        phaseOrder.forEach(phase => {
            const suffix = direction === 'both' ? ' (' + PERTII18n.t('demand.direction.depShort') + ')' : '';
            series.push(
                buildPhaseSeriesTimeAxis(FSM_PHASE_LABELS[phase] + suffix, timeBins, departuresByBin, phase, 'departures', direction),
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
                data: markLineData,
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
        if (!breakdown) {return 0;}
        return Object.values(breakdown).reduce((sum, val) => sum + (typeof val === 'number' ? val : 0), 0);
    };
    arrivals.forEach(d => {
        const binTotal = countDemandInBin(d.breakdown);
        if (binTotal > maxDemand) {maxDemand = binTotal;}
    });
    departures.forEach(d => {
        const binTotal = countDemandInBin(d.breakdown);
        if (binTotal > maxDemand) {maxDemand = binTotal;}
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
        if (combinedMax > maxDemand) {maxDemand = combinedMax;}
    }

    // Calculate max rate (pro-rated for granularity)
    let maxRate = 0;
    if (DEMAND_STATE.rateData && DEMAND_STATE.rateData.rates) {
        const rates = DEMAND_STATE.rateData.rates;
        maxRate = Math.max(
            (rates.vatsim_aar || 0) * proRateFactor,
            (rates.vatsim_adr || 0) * proRateFactor,
            (rates.rw_aar || 0) * proRateFactor,
            (rates.rw_adr || 0) * proRateFactor,
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
                fontFamily: '"Inconsolata", "SF Mono", monospace',
            },
        },
        tooltip: {
            trigger: 'axis',
            confine: true,  // Keep tooltip within chart area
            axisPointer: {
                type: 'shadow',
                z: 10,  // Keep axisPointer below dataZoom sliders
            },
            z: 50,  // Keep tooltip below dataZoom sliders (z: 100)
            backgroundColor: 'rgba(255, 255, 255, 0.98)',
            borderColor: '#ccc',
            borderWidth: 1,
            padding: [8, 12],
            textStyle: {
                color: '#333',
                fontSize: 12,
            },
            formatter: function(params) {
                if (!params || params.length === 0) {return '';}
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
                tooltip += `<hr style="margin:4px 0;border-color:#ddd;"/><strong>${PERTII18n.t('demand.chart.total')}: ${total}</strong>`;
                // Add rate information if available
                const rates = getRatesForTimestamp(timestamp);
                if (rates && (rates.aar || rates.adr)) {
                    tooltip += `<hr style="margin:4px 0;border-color:#ddd;"/>`;
                    if (rates.aar) {tooltip += `<span style="color:#000;">AAR: <strong>${rates.aar}</strong></span>`;}
                    if (rates.aar && rates.adr) {tooltip += ` / `;}
                    if (rates.adr) {tooltip += `<span style="color:#000;">ADR: <strong>${rates.adr}</strong></span>`;}
                }
                return tooltip;
            },
        },
        legend: Object.assign({}, getStandardLegendConfig(DEMAND_STATE.legendVisible),
            direction === 'both' ? {} : {},
            { selected: DEMAND_STATE.legendSelected }
        ),
        // DataZoom sliders for customizable time/demand ranges
        dataZoom: getDataZoomConfig(),
        grid: getStandardGridConfig(),
        xAxis: {
            type: 'time',
            name: getXAxisLabel(),
            nameLocation: 'middle',
            nameGap: direction === 'both' ? 45 : 30, // Extra gap for 2 legend rows
            nameTextStyle: {
                fontSize: 11,
                color: '#333',
                fontWeight: 500,
            },
            maxInterval: 3600 * 1000,  // Maximum 1 hour between labels
            axisLine: {
                lineStyle: {
                    color: '#333',
                    width: 1,
                },
            },
            axisTick: {
                alignWithLabel: true,
                lineStyle: {
                    color: '#666',
                },
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
                },
            },
            splitLine: {
                show: true,
                lineStyle: {
                    color: '#f0f0f0',
                    type: 'solid',
                },
            },
            min: new Date(timeBins[0]).getTime(),
            max: new Date(timeBins[timeBins.length - 1]).getTime() + intervalMs,
        },
        yAxis: {
            type: 'value',
            name: PERTII18n.t('demand.chart.yAxisLabel'),
            nameLocation: 'middle',
            nameGap: 40,
            nameTextStyle: {
                fontSize: 12,
                color: '#333',
                fontWeight: 500,
            },
            minInterval: 1,
            min: 0,
            max: yAxisMax, // Will be null (auto) if no rates, or calculated to fit rate lines
            axisLine: {
                show: true,
                lineStyle: {
                    color: '#333',
                    width: 1,
                },
            },
            axisTick: {
                show: true,
                lineStyle: {
                    color: '#666',
                },
            },
            axisLabel: {
                fontSize: 11,
                color: '#333',
                fontFamily: '"Inconsolata", monospace',
            },
            splitLine: {
                show: true,
                lineStyle: {
                    color: '#e8e8e8',
                    type: 'dashed',
                },
            },
        },
        series: series,
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
 * Origin breakdown shows where arrivals come from, so only applies to arr/both directions
 */
function renderOriginChart() {
    const direction = DEMAND_STATE.direction;

    // Origin breakdown only makes sense for arrivals (where did they come from?)
    if (direction === 'dep') {
        showDirectionRestrictedEmptyState(PERTII18n.t('demand.breakdown.originArtcc'), 'arr', PERTII18n.t('demand.direction.departures').toLowerCase());
        return;
    }

    const dirLabel = direction === 'arr' ? PERTII18n.t('demand.direction.arrivals') : PERTII18n.t('demand.direction.flights');

    // Get all ARTCCs from breakdown data
    const breakdown = DEMAND_STATE.originBreakdown || {};
    const allARTCCs = new Set();
    for (const bin in breakdown) {
        const catData = breakdown[bin];
        if (Array.isArray(catData)) {
            catData.forEach(item => allARTCCs.add(item.artcc));
        }
    }

    // Group ARTCCs by DCC region
    const regionGroups = {};
    allARTCCs.forEach(artcc => {
        const region = getARTCCRegion(artcc);
        if (!regionGroups[region]) {
            regionGroups[region] = [];
        }
        regionGroups[region].push(artcc);
    });

    // Sort regions by order, then sort ARTCCs within each region
    const sortedRegions = Object.keys(regionGroups).sort((a, b) => getRegionOrder(a) - getRegionOrder(b));
    sortedRegions.forEach(region => {
        regionGroups[region].sort();
    });

    // Build single legend with all ARTCCs (using standard config for fixed height)
    // The scroll feature will handle overflow for many items
    const allArtccs = [];
    const activeRegions = sortedRegions.filter(r => regionGroups[r].length > 0);
    activeRegions.forEach(region => {
        allArtccs.push(...regionGroups[region]);
    });

    // Use shared renderBreakdownChart with DCC regional colors and standard legend
    renderBreakdownChart(
        DEMAND_STATE.originBreakdown,
        PERTII18n.t('demand.breakdown.byOriginArtcc', { direction: dirLabel }),
        'origin',
        'artcc',
        getDCCRegionColor,  // Use DCC regional color function
        null,
        null,
        {
            // Use standard legend config - scroll handles overflow
            legend: Object.assign({}, getStandardLegendConfig(DEMAND_STATE.legendVisible), {
                data: allArtccs,
            }),
            grid: getStandardGridConfig(),
        },
    );
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
 * @param {Object} extraOptions - Optional extra chart options (legend, grid overrides)
 */
function renderBreakdownChart(breakdownData, subtitle, stackName, categoryKey, colorFn, labelFn, order, extraOptions) {
    if (!DEMAND_STATE.chart) {
        console.error('Chart not initialized');
        return;
    }

    // Capture legend selected state before replacing chart options
    DEMAND_STATE.legendSelected = captureLegendSelected();

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

    // Round to granularity helper - for time bin lookups
    const roundToGranularity = (bin) => {
        const d = new Date(bin);
        const granMinutes = getGranularityMinutes();
        const minutes = d.getUTCMinutes();
        const roundedMinutes = Math.floor(minutes / granMinutes) * granMinutes;
        d.setUTCMinutes(roundedMinutes, 0, 0);
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
            if (!categoryList.includes(cat)) {categoryList.push(cat);}
        });
    } else {
        categoryList = Array.from(allCategories).sort();
    }

    // Calculate interval
    const intervalMs = getGranularityMinutes() * 60 * 1000;
    const halfInterval = intervalMs / 2;

    // Build series with phase filtering
    const enabledPhases = getEnabledPhases();

    // Debug: log enabled phases and verify math GLOBALLY across all entries
    console.log('[Demand] Phase filter - enabled phases:', enabledPhases);

    // Sum across ALL entries to verify filter math
    let totalWithEnabledPhases = 0;
    let totalArrivedOnly = 0;
    let totalDisconnectedOnly = 0;
    let totalArrivedPlusDisconnected = 0;
    let totalAllPhases = 0;
    let totalCount = 0;
    const mismatches = [];

    Object.keys(breakdown).forEach(binKey => {
        const binData = breakdown[binKey];
        if (!Array.isArray(binData)) {return;}

        binData.forEach(entry => {
            if (!entry.phases) {return;}

            // Calculate various sums for this entry
            const phaseValues = Object.entries(entry.phases)
                .filter(([k, v]) => k !== '_sum')
                .reduce((obj, [k, v]) => { obj[k] = v; return obj; }, {});

            const enabledSum = enabledPhases.reduce((sum, p) => sum + (phaseValues[p] || 0), 0);
            const arrivedSum = phaseValues.arrived || 0;
            const disconnectedSum = phaseValues.disconnected || 0;
            const allSum = Object.values(phaseValues).reduce((a, b) => a + b, 0);

            totalWithEnabledPhases += enabledSum;
            totalArrivedOnly += arrivedSum;
            totalDisconnectedOnly += disconnectedSum;
            totalArrivedPlusDisconnected += arrivedSum + disconnectedSum;
            totalAllPhases += allSum;
            totalCount += entry.count;

            // Check for mismatches
            if (entry.count !== allSum) {
                mismatches.push({ bin: binKey, category: entry[categoryKey], count: entry.count, phaseSum: allSum });
            }
        });
    });

    console.log('[Demand] Phase filter GLOBAL TOTALS:', {
        enabledPhases,
        totalWithEnabledPhases,
        totalArrivedOnly,
        totalDisconnectedOnly,
        'arrived+disconnected': totalArrivedPlusDisconnected,
        totalAllPhases,
        totalCount,
        'arrivedOnly + disconnectedOnly': totalArrivedOnly + totalDisconnectedOnly,
        mismatches: mismatches.length > 0 ? mismatches.slice(0, 5) : 'None',
    });

    const series = categoryList.map(category => {
        const seriesData = timeBins.map(bin => {
            // Match time bin format - use normalized bin first, then granularity-rounded fallback
            const normalizedBin = normalizeTimeBin(bin);
            const roundedBin = roundToGranularity(bin);
            const binData = breakdown[normalizedBin] || breakdown[roundedBin] || [];
            const catEntry = Array.isArray(binData) ? binData.find(item => item[categoryKey] === category) : null;

            // Calculate value based on enabled phases
            let value = 0;
            if (catEntry) {
                if (catEntry.phases) {
                    // Use phase-filtered count
                    enabledPhases.forEach(phase => {
                        value += catEntry.phases[phase] || 0;
                    });
                    // Debug: verify phase math (only for first bin/category)
                    if (bin === timeBins[0] && category === categoryList[0]) {
                        const phaseSum = catEntry.phases._sum || Object.entries(catEntry.phases)
                            .filter(([k,v]) => k !== '_sum')
                            .reduce((a, [k,v]) => a + v, 0);
                        console.log('[Demand] Phase filter debug:', {
                            category,
                            bin,
                            count: catEntry.count,
                            phases: catEntry.phases,
                            enabledPhases,
                            filteredValue: value,
                            phaseSum,
                            mismatch: catEntry.count !== phaseSum ? 'COUNT != PHASE SUM!' : 'OK',
                        });
                    }
                } else {
                    // Fallback for data without phase breakdown
                    value = catEntry.count;
                }
            }
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
                    shadowColor: 'rgba(0,0,0,0.2)',
                },
            },
            itemStyle: {
                color: colorFn(category),
                borderColor: 'transparent',
                borderWidth: 0,
            },
            data: seriesData,
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
        if (tmiProgramMarkLines && tmiProgramMarkLines.length > 0) {markLineData.push(...tmiProgramMarkLines);}
        if (timeMarkLineData) {markLineData.push(timeMarkLineData);}
        if (rateMarkLines && rateMarkLines.length > 0) {markLineData.push(...rateMarkLines);}

        if (markLineData.length > 0) {
            series[0].markLine = {
                silent: true,
                symbol: ['none', 'none'],
                data: markLineData,
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
            if (binTotal > maxDemand) {maxDemand = binTotal;}
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
            (rates.rw_adr || 0) * proRateFactor,
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
                fontFamily: '"Inconsolata", "SF Mono", monospace',
            },
            subtextStyle: {
                fontSize: 11,
                color: '#666',
            },
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
                if (!params || params.length === 0) {return '';}
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
                tooltip += `<hr style="margin:4px 0;border-color:#ddd;"/><strong>${PERTII18n.t('demand.chart.total')}: ${total}</strong>`;
                // Add rate information if available
                const rates = getRatesForTimestamp(timestamp);
                if (rates && (rates.aar || rates.adr)) {
                    tooltip += `<hr style="margin:4px 0;border-color:#ddd;"/>`;
                    if (rates.aar) {tooltip += `<span style="color:#000;">AAR: <strong>${rates.aar}</strong></span>`;}
                    if (rates.aar && rates.adr) {tooltip += ` / `;}
                    if (rates.adr) {tooltip += `<span style="color:#000;">ADR: <strong>${rates.adr}</strong></span>`;}
                }
                return tooltip;
            },
        },
        legend: Object.assign({},
            (extraOptions && extraOptions.legend)
                ? Object.assign({}, extraOptions.legend, { show: DEMAND_STATE.legendVisible })
                : getStandardLegendConfig(DEMAND_STATE.legendVisible),
            { selected: DEMAND_STATE.legendSelected }
        ),
        // DataZoom sliders for customizable time/demand ranges
        dataZoom: getDataZoomConfig(),
        grid: (extraOptions && extraOptions.grid) ? extraOptions.grid : getStandardGridConfig(),
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
                },
            },
            splitLine: { show: true, lineStyle: { color: '#f0f0f0', type: 'solid' } },
            min: new Date(timeBins[0]).getTime(),
            max: new Date(timeBins[timeBins.length - 1]).getTime() + intervalMs,
        },
        yAxis: {
            type: 'value',
            name: PERTII18n.t('demand.chart.yAxisLabel'),
            nameLocation: 'middle',
            nameGap: 40,
            nameTextStyle: { fontSize: 12, color: '#333', fontWeight: 500 },
            minInterval: 1,
            min: 0,
            max: yAxisMax,
            axisLine: { show: true, lineStyle: { color: '#333', width: 1 } },
            axisTick: { show: true, lineStyle: { color: '#666' } },
            axisLabel: { fontSize: 11, color: '#333', fontFamily: '"Inconsolata", monospace' },
            splitLine: { show: true, lineStyle: { color: '#e8e8e8', type: 'dashed' } },
        },
        series: series,
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
 * Dest breakdown shows where departures go, so only applies to dep/both directions
 */
function renderDestChart() {
    const direction = DEMAND_STATE.direction;

    // Dest breakdown only makes sense for departures (where are they going?)
    // For arrivals-only, show empty state
    if (direction === 'arr') {
        if (!DEMAND_STATE.chart) {return;}
        const data = DEMAND_STATE.lastDemandData;
        const chartTitle = buildChartTitle(data?.airport || '', data?.last_adl_update);
        DEMAND_STATE.chart.setOption({
            backgroundColor: '#ffffff',
            title: {
                text: chartTitle,
                subtext: PERTII18n.t('demand.breakdown.destNotApplicableArr'),
                left: 'center',
                top: 5,
                textStyle: { fontSize: 14, fontWeight: 'bold', color: '#333' },
                subtextStyle: { fontSize: 11, color: '#999', fontStyle: 'italic' },
            },
            xAxis: { show: false },
            yAxis: { show: false },
            series: [],
            graphic: {
                type: 'text',
                left: 'center',
                top: 'middle',
                style: {
                    text: PERTII18n.t('demand.breakdown.destSwitchDirection'),
                    fontSize: 14,
                    fill: '#999',
                    textAlign: 'center',
                },
            },
        }, true);
        return;
    }

    const dirLabel = direction === 'dep' ? PERTII18n.t('demand.direction.departures') : PERTII18n.t('demand.direction.flights');

    // Get all ARTCCs from breakdown data
    const breakdown = DEMAND_STATE.destBreakdown || {};
    const allARTCCs = new Set();
    for (const bin in breakdown) {
        const catData = breakdown[bin];
        if (Array.isArray(catData)) {
            catData.forEach(item => allARTCCs.add(item.artcc));
        }
    }

    // Group ARTCCs by DCC region
    const regionGroups = {};
    allARTCCs.forEach(artcc => {
        const region = getARTCCRegion(artcc);
        if (!regionGroups[region]) {
            regionGroups[region] = [];
        }
        regionGroups[region].push(artcc);
    });

    // Sort regions by order, then sort ARTCCs within each region
    const sortedRegions = Object.keys(regionGroups).sort((a, b) => getRegionOrder(a) - getRegionOrder(b));
    sortedRegions.forEach(region => {
        regionGroups[region].sort();
    });

    // Build single legend with all ARTCCs (using standard config for fixed height)
    const allArtccs = [];
    const activeRegions = sortedRegions.filter(r => regionGroups[r].length > 0);
    activeRegions.forEach(region => {
        allArtccs.push(...regionGroups[region]);
    });

    renderBreakdownChart(
        DEMAND_STATE.destBreakdown,
        PERTII18n.t('demand.breakdown.byDestArtcc', { direction: dirLabel }),
        'dest',
        'artcc',
        getDCCRegionColor,
        null,
        null,
        {
            // Use standard legend config - scroll handles overflow
            legend: Object.assign({}, getStandardLegendConfig(DEMAND_STATE.legendVisible), {
                data: allArtccs,
            }),
            grid: getStandardGridConfig(),
        },
    );
}

/**
 * Render chart with carrier breakdown (top carriers + OTHER)
 * Shows ICAO codes only, sorted by flight count (descending)
 */
function renderCarrierChart() {
    const direction = DEMAND_STATE.direction;
    const dirLabel = direction === 'arr' ? PERTII18n.t('demand.direction.arrivals') : (direction === 'dep' ? PERTII18n.t('demand.direction.departures') : PERTII18n.t('demand.direction.flights'));

    // Calculate total flights per carrier for sorting
    const breakdown = DEMAND_STATE.carrierBreakdown || {};
    const carrierTotals = {};
    for (const bin in breakdown) {
        const catData = breakdown[bin];
        if (Array.isArray(catData)) {
            catData.forEach(item => {
                const carrier = item.carrier;
                if (!carrierTotals[carrier]) {
                    carrierTotals[carrier] = 0;
                }
                carrierTotals[carrier] += item.count || 0;
            });
        }
    }

    // Sort carriers by count (descending)
    const sortedCarriers = Object.keys(carrierTotals).sort((a, b) => carrierTotals[b] - carrierTotals[a]);

    renderBreakdownChart(
        DEMAND_STATE.carrierBreakdown,
        PERTII18n.t('demand.breakdown.byCarrier', { direction: dirLabel }),
        'carrier',
        'carrier',
        (carrier) => {
            if (typeof FILTER_CONFIG !== 'undefined' && FILTER_CONFIG.carrier && FILTER_CONFIG.carrier.colors) {
                return FILTER_CONFIG.carrier.colors[carrier] || FILTER_CONFIG.carrier.colors['OTHER'] || '#6c757d';
            }
            return '#6c757d';
        },
        null,  // No label function - just show ICAO code
        sortedCarriers,  // Order by flight count
    );
}

/**
 * Render chart with weight class breakdown (S/L/H/J)
 */
function renderWeightChart() {
    const direction = DEMAND_STATE.direction;
    const dirLabel = direction === 'arr' ? PERTII18n.t('demand.direction.arrivals') : (direction === 'dep' ? PERTII18n.t('demand.direction.departures') : PERTII18n.t('demand.direction.flights'));

    let order = ['J', 'H', 'L', 'S', 'UNKNOWN'];
    if (typeof FILTER_CONFIG !== 'undefined' && FILTER_CONFIG.weightClass && FILTER_CONFIG.weightClass.order) {
        order = FILTER_CONFIG.weightClass.order;
    }

    renderBreakdownChart(
        DEMAND_STATE.weightBreakdown,
        PERTII18n.t('demand.breakdown.byWeightClass', { direction: dirLabel }),
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
            const labels = { 'J': PERTII18n.t('weightClass.J'), 'H': PERTII18n.t('weightClass.H'), 'L': PERTII18n.t('weightClass.L'), 'S': PERTII18n.t('weightClass.S') };
            return labels[wc] || wc;
        },
        order,
    );
}

/**
 * Render chart with equipment/aircraft type breakdown
 * Legend is grouped by manufacturer (Boeing, Airbus, etc.)
 */
function renderEquipmentChart() {
    const direction = DEMAND_STATE.direction;
    const dirLabel = direction === 'arr' ? PERTII18n.t('demand.direction.arrivals') : (direction === 'dep' ? PERTII18n.t('demand.direction.departures') : PERTII18n.t('demand.direction.flights'));

    // Get all aircraft types from breakdown data
    const breakdown = DEMAND_STATE.equipmentBreakdown || {};
    const allTypes = new Set();
    for (const bin in breakdown) {
        const catData = breakdown[bin];
        if (Array.isArray(catData)) {
            catData.forEach(item => allTypes.add(item.equipment));
        }
    }

    // Group aircraft types by manufacturer for sorting
    const mfrGroups = {};
    allTypes.forEach(acType => {
        const mfr = getAircraftManufacturer(acType);
        if (!mfrGroups[mfr]) {
            mfrGroups[mfr] = [];
        }
        mfrGroups[mfr].push(acType);
    });

    // Sort manufacturers by order, then sort types within each manufacturer
    const sortedMfrs = Object.keys(mfrGroups).sort((a, b) => getManufacturerOrder(a) - getManufacturerOrder(b));
    sortedMfrs.forEach(mfr => {
        mfrGroups[mfr].sort();
    });

    // Build flat sorted list of all aircraft types (manufacturer-grouped order)
    const sortedTypes = [];
    sortedMfrs.forEach(mfr => {
        sortedTypes.push(...mfrGroups[mfr]);
    });

    // Use standard scrolling legend like other charts
    renderBreakdownChart(
        DEMAND_STATE.equipmentBreakdown,
        PERTII18n.t('demand.breakdown.byAircraftType', { direction: dirLabel }),
        'equipment',
        'equipment',
        (acType) => {
            if (typeof FILTER_CONFIG !== 'undefined' && FILTER_CONFIG.equipment && FILTER_CONFIG.equipment.colors) {
                return FILTER_CONFIG.equipment.colors[acType] || FILTER_CONFIG.equipment.colors['OTHER'] || '#6c757d';
            }
            return '#6c757d';
        },
        null,
        null,
        {
            legend: Object.assign({}, getStandardLegendConfig(DEMAND_STATE.legendVisible), {
                data: sortedTypes,
            }),
            grid: getStandardGridConfig(),
        },
    );
}

/**
 * Render chart with flight rule breakdown (IFR/VFR)
 */
function renderRuleChart() {
    const direction = DEMAND_STATE.direction;
    const dirLabel = direction === 'arr' ? PERTII18n.t('demand.direction.arrivals') : (direction === 'dep' ? PERTII18n.t('demand.direction.departures') : PERTII18n.t('demand.direction.flights'));

    let order = ['I', 'V'];
    if (typeof FILTER_CONFIG !== 'undefined' && FILTER_CONFIG.flightRule && FILTER_CONFIG.flightRule.order) {
        order = FILTER_CONFIG.flightRule.order;
    }

    renderBreakdownChart(
        DEMAND_STATE.ruleBreakdown,
        PERTII18n.t('demand.breakdown.byFlightRule', { direction: dirLabel }),
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
            const labels = { 'I': PERTII18n.t('flightRule.I'), 'V': PERTII18n.t('flightRule.V') };
            return labels[rule] || rule;
        },
        order,
    );
}

/**
 * Show empty state for direction-restricted breakdown views
 */
function showDirectionRestrictedEmptyState(viewName, requiredDirection, requiredLabel) {
    if (!DEMAND_STATE.chart) {return;}
    const data = DEMAND_STATE.lastDemandData;
    const chartTitle = buildChartTitle(data?.airport || '', data?.last_adl_update);
    const dirText = requiredDirection === 'arr' ? PERTII18n.t('demand.emptyState.arrOrBoth') : PERTII18n.t('demand.emptyState.depOrBoth');
    DEMAND_STATE.chart.setOption({
        backgroundColor: '#ffffff',
        title: {
            text: chartTitle,
            subtext: PERTII18n.t('demand.emptyState.notApplicableFor', { view: viewName, direction: requiredLabel }),
            left: 'center',
            top: 5,
            textStyle: { fontSize: 14, fontWeight: 'bold', color: '#333' },
            subtextStyle: { fontSize: 11, color: '#999', fontStyle: 'italic' },
        },
        xAxis: { show: false },
        yAxis: { show: false },
        series: [],
        graphic: {
            type: 'text',
            left: 'center',
            top: 'middle',
            style: {
                text: PERTII18n.t('demand.emptyState.switchDirection', { view: viewName, appliesTo: requiredDirection === 'arr' ? PERTII18n.t('demand.direction.arrivals').toLowerCase() : PERTII18n.t('demand.direction.departures').toLowerCase(), dirText: dirText }),
                fontSize: 14,
                fill: '#999',
                textAlign: 'center',
            },
        },
    }, true);
}

/**
 * Render chart with departure fix breakdown
 * Dep fix only applies to departures
 */
function renderDepFixChart() {
    const direction = DEMAND_STATE.direction;

    // Dep fix only makes sense for departures
    if (direction === 'arr') {
        showDirectionRestrictedEmptyState(PERTII18n.t('demand.breakdown.depFix'), 'dep', PERTII18n.t('demand.direction.arrivals').toLowerCase());
        return;
    }

    const dirLabel = direction === 'dep' ? PERTII18n.t('demand.direction.departures') : PERTII18n.t('demand.direction.flights');
    renderBreakdownChart(
        DEMAND_STATE.depFixBreakdown,
        PERTII18n.t('demand.breakdown.byDepFix', { direction: dirLabel }),
        'dep_fix',
        'fix',
        (fix) => {
            if (typeof FILTER_CONFIG !== 'undefined' && FILTER_CONFIG.fix && typeof FILTER_CONFIG.fix.getColor === 'function') {
                return FILTER_CONFIG.fix.getColor(fix);
            }
            return getCategoricalColor(fix);
        },
        null,
        null,
    );
}

/**
 * Render chart with arrival fix breakdown
 * Arr fix only applies to arrivals
 */
function renderArrFixChart() {
    const direction = DEMAND_STATE.direction;

    // Arr fix only makes sense for arrivals
    if (direction === 'dep') {
        showDirectionRestrictedEmptyState(PERTII18n.t('demand.breakdown.arrFix'), 'arr', PERTII18n.t('demand.direction.departures').toLowerCase());
        return;
    }

    const dirLabel = direction === 'arr' ? PERTII18n.t('demand.direction.arrivals') : PERTII18n.t('demand.direction.flights');
    renderBreakdownChart(
        DEMAND_STATE.arrFixBreakdown,
        PERTII18n.t('demand.breakdown.byArrFix', { direction: dirLabel }),
        'arr_fix',
        'fix',
        (fix) => {
            if (typeof FILTER_CONFIG !== 'undefined' && FILTER_CONFIG.fix && typeof FILTER_CONFIG.fix.getColor === 'function') {
                return FILTER_CONFIG.fix.getColor(fix);
            }
            return getCategoricalColor(fix);
        },
        null,
        null,
    );
}

/**
 * Render chart with DP/SID breakdown
 * DP (SID) only applies to departures
 */
function renderDPChart() {
    const direction = DEMAND_STATE.direction;

    // DP/SID only makes sense for departures
    if (direction === 'arr') {
        showDirectionRestrictedEmptyState(PERTII18n.t('demand.breakdown.dpSid'), 'dep', PERTII18n.t('demand.direction.arrivals').toLowerCase());
        return;
    }

    const dirLabel = direction === 'dep' ? PERTII18n.t('demand.direction.departures') : PERTII18n.t('demand.direction.flights');
    renderBreakdownChart(
        DEMAND_STATE.dpBreakdown,
        PERTII18n.t('demand.breakdown.byDpSid', { direction: dirLabel }),
        'dp',
        'dp',
        (dp) => {
            if (typeof FILTER_CONFIG !== 'undefined' && FILTER_CONFIG.procedure && typeof FILTER_CONFIG.procedure.getColor === 'function') {
                return FILTER_CONFIG.procedure.getColor(dp);
            }
            return getCategoricalColor(dp);
        },
        null,
        null,
    );
}

/**
 * Render chart with STAR breakdown
 * STAR only applies to arrivals
 */
function renderSTARChart() {
    const direction = DEMAND_STATE.direction;

    // STAR only makes sense for arrivals
    if (direction === 'dep') {
        showDirectionRestrictedEmptyState(PERTII18n.t('demand.breakdown.star'), 'arr', PERTII18n.t('demand.direction.departures').toLowerCase());
        return;
    }

    const dirLabel = direction === 'arr' ? PERTII18n.t('demand.direction.arrivals') : PERTII18n.t('demand.direction.flights');
    renderBreakdownChart(
        DEMAND_STATE.starBreakdown,
        PERTII18n.t('demand.breakdown.byStar', { direction: dirLabel }),
        'star',
        'star',
        (star) => {
            if (typeof FILTER_CONFIG !== 'undefined' && FILTER_CONFIG.procedure && typeof FILTER_CONFIG.procedure.getColor === 'function') {
                return FILTER_CONFIG.procedure.getColor(star);
            }
            return getCategoricalColor(star);
        },
        null,
        null,
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
    $('#demand_flight_count').text(PERTII18n.tp('flight', totalFlights));
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
            focus: 'series',
        },
        itemStyle: {
            color: color,
            borderColor: '#fff',
            borderWidth: 0.5,
        },
        data: data,
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
                shadowColor: 'rgba(0,0,0,0.2)',
            },
        },
        itemStyle: {
            color: color,
            borderColor: type === 'departures' ? 'rgba(255,255,255,0.5)' : 'transparent',
            borderWidth: type === 'departures' ? 1 : 0,
        },
        data: data,
    };

    // Add diagonal hatching pattern for departures (FSM/TBFM style)
    if (type === 'departures') {
        seriesConfig.itemStyle.decal = {
            symbol: 'rect',
            symbolSize: 1,
            rotation: Math.PI / 4,  // 45-degree diagonal lines
            color: 'rgba(255,255,255,0.4)',  // White lines for contrast
            dashArrayX: [1, 0],
            dashArrayY: [3, 5],  // Line thickness and spacing
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
                shadowColor: 'rgba(0,0,0,0.2)',
            },
        },
        itemStyle: {
            color: color,
            borderColor: 'transparent', // No borders - AADC style
            borderWidth: 0,
        },
        data: data,
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
            if (program.start_utc) {tmiMarkerTimes.push(new Date(program.start_utc).getTime());}
            if (program.was_updated && program.updated_at) {tmiMarkerTimes.push(new Date(program.updated_at).getTime());}
            if (program.status === 'PURGED' && program.purged_at) {tmiMarkerTimes.push(new Date(program.purged_at).getTime());}
            else if (program.end_utc) {tmiMarkerTimes.push(new Date(program.end_utc).getTime());}
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
            type: 'solid',
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
            borderWidth: 1,
        },
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
                    source: 'TMI',
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
            source: 'VATSIM',
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
    if (!rates) {return [];}

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
            rw: { color: '#00FFFF' },
        },
        suggested: {
            vatsim: { color: '#6b7280' },
            rw: { color: '#0d9488' },
        },
        custom: {
            vatsim: { color: '#000000' },
            rw: { color: '#00FFFF' },
        },
        lineStyle: {
            aar: { type: 'solid', width: 2 },
            adr: { type: 'dashed', width: 2 },
            aar_custom: { type: 'dotted', width: 2 },
            adr_custom: { type: 'dotted', width: 2 },
        },
        label: {
            position: 'end',
            fontSize: 10,
            fontWeight: 'bold',
        },
    };

    // Always use 'active' style - consistent symbology regardless of override/suggested status
    // VATSIM = black, RW = cyan, AAR = solid, ADR = dashed
    const styleKey = 'active';

    // Track label index for vertical stacking
    let labelIndex = 0;

    // Helper to create a rate line
    const addLine = (value, source, rateType, label) => {
        if (!value) {return;}

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
                type: lineTypeStyle.type,
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
                borderWidth: 1,
            },
        });
    };

    // Add lines based on direction filter AND individual visibility toggles
    if (direction === 'both' || direction === 'arr') {
        if (DEMAND_STATE.showVatsimAar) {addLine(rates.vatsim_aar, 'vatsim', 'aar', 'AAR');}
        if (DEMAND_STATE.showRwAar) {addLine(rates.rw_aar, 'rw', 'aar', 'RW AAR');}
    }

    if (direction === 'both' || direction === 'dep') {
        if (DEMAND_STATE.showVatsimAdr) {addLine(rates.vatsim_adr, 'vatsim', 'adr', 'ADR');}
        if (DEMAND_STATE.showRwAdr) {addLine(rates.rw_adr, 'rw', 'adr', 'RW ADR');}
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
    if (!configs || configs.length === 0) {return [];}

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
            rw: { color: '#00FFFF' },
        },
        lineStyle: {
            aar: { type: 'solid', width: 2 },
            adr: { type: 'dashed', width: 2 },
        },
        label: {
            fontSize: 10,
            fontWeight: 'bold',
        },
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
        rateValues.vatsim_aar, rateValues.vatsim_adr, rateValues.rw_aar, rateValues.rw_adr,
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
        if (segmentStart >= segmentEnd) {return;}

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
                    yAxis: proRatedValue,
                },
                {
                    xAxis: segmentEnd,
                    yAxis: proRatedValue,
                    lineStyle: {
                        color: sourceStyle.color,
                        width: lineTypeStyle.width,
                        type: lineTypeStyle.type,
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
                        borderWidth: 1,
                    },
                },
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
                    yAxis: proRatedValue,
                },
                {
                    xAxis: segmentEnd,
                    yAxis: proRatedValue,
                    lineStyle: {
                        color: sourceStyle.color,
                        width: lineTypeStyle.width,
                        type: lineTypeStyle.type,
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
                        borderWidth: 1,
                    },
                },
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
                            type: 'solid',  // Solid for AAR
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
                            borderWidth: 1,
                        },
                    },
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
                            type: 'dashed',  // Dashed for ADR
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
                            borderWidth: 1,
                        },
                    },
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
                        type: 'solid',  // Solid for AAR
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
                        borderWidth: 1,
                    },
                },
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
                        type: 'dashed',  // Dashed for ADR
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
                        borderWidth: 1,
                    },
                },
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
        if (!isoString) {return '';}
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
                label: `${prefix} ${PERTII18n.t('demand.tmiMarker.start')}: ${formatTimeZ(program.start_utc)}`,
                color: color,
                isDashed: false,
            });
        }

        // Update marker (dashed line) - only if was_updated is true
        if (program.was_updated && program.updated_at) {
            markersToAdd.push({
                timestamp: program.updated_at,
                label: `${prefix} ${PERTII18n.t('demand.tmiMarker.update')}: ${formatTimeZ(program.updated_at)}`,
                color: color,
                isDashed: true,
            });
        }

        // End marker - check status
        if (program.status === 'PURGED' && program.purged_at) {
            markersToAdd.push({
                timestamp: program.purged_at,
                label: `${prefix} ${PERTII18n.t('demand.tmiMarker.cnx')}: ${formatTimeZ(program.purged_at)}`,
                color: color,
                isDashed: false,
            });
        } else if (program.end_utc) {
            markersToAdd.push({
                timestamp: program.end_utc,
                label: `${prefix} ${PERTII18n.t('demand.tmiMarker.end')}: ${formatTimeZ(program.end_utc)}`,
                color: color,
                isDashed: false,
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
        if (timeMs < chartStart || timeMs > chartEnd) {return;}

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
                type: marker.isDashed ? 'dashed' : 'solid',
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
                borderWidth: 1,
            },
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

    while (current < end) {
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
    if (!timeBins || timeBins.length === 0) {return -1;}

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
    if (currentIndex < 0) {return null;}

    const now = new Date();
    const hours = now.getUTCHours().toString().padStart(2, '0');
    const minutes = now.getUTCMinutes().toString().padStart(2, '0');

    return {
        silent: true,
        symbol: 'none',
        lineStyle: {
            color: '#000000',
            width: 2,
            type: 'solid',
        },
        label: {
            show: true,
            formatter: `NOW\n${hours}:${minutes}Z`,
            position: 'start',
            color: '#000000',
            fontWeight: 'bold',
            fontSize: 10,
        },
        data: [{ xAxis: currentIndex }],
    };
}

/**
 * Get direction label for chart subtitle
 */
function getDirectionLabel() {
    switch (DEMAND_STATE.direction) {
        case 'arr': return PERTII18n.t('demand.direction.arrivalsOnly');
        case 'dep': return PERTII18n.t('demand.direction.departuresOnly');
        default: return PERTII18n.t('demand.direction.arrivalsAndDepartures');
    }
}

/**
 * Get X-axis label based on granularity
 * Format: "Time in {#}-Minute Increments"
 */
function getXAxisLabel() {
    const minutes = getGranularityMinutes();
    return PERTII18n.t('demand.chart.xAxisLabel', { minutes: minutes });
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
    if (!DEMAND_STATE.cacheTimestamp) {return false;}
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
    if (!DEMAND_STATE.lastDemandData) {return;}

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
                borderWidth: 1,
            },
            moveHandleSize: 10,           // Size of the move handle (middle bar)
            emphasis: {
                handleStyle: {
                    color: '#0056b3',
                    borderColor: '#003d82',
                },
            },
            textStyle: {
                color: '#333',
                fontSize: 10,
                fontFamily: '"Inconsolata", monospace',
            },
            labelFormatter: function(value) {
                const d = new Date(value);
                return d.getUTCHours().toString().padStart(2, '0') +
                       d.getUTCMinutes().toString().padStart(2, '0') + 'Z';
            },
            brushSelect: false,
            z: 100,                       // Ensure slider is above tooltip elements
            zLevel: 100,                   // Ensure slider is above other elements
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
                borderWidth: 1,
            },
            emphasis: {
                handleStyle: {
                    color: '#1e7e34',
                    borderColor: '#155d27',
                },
            },
            textStyle: {
                color: '#333',
                fontSize: 10,
            },
            brushSelect: false,
            z: 100,                       // Ensure slider is above tooltip elements
            zLevel: 100,
        },
        {
            // Inside zoom for time axis (mouse scroll/drag)
            type: 'inside',
            xAxisIndex: 0,
            zoomOnMouseWheel: 'shift', // Shift+scroll to zoom
            moveOnMouseMove: false,
            moveOnMouseWheel: false,
        },
    ];
}

/**
 * Get the standard legend configuration for demand charts
 * Uses fixed positioning and scroll for overflow, with visibility toggle support
 * @param {boolean} visible - Whether the legend should be visible
 * @returns {Object} ECharts legend configuration
 */
function getStandardLegendConfig(visible) {
    return {
        show: visible,
        bottom: 55,  // Fixed position above dataZoom slider
        left: 'center',
        width: '90%',
        type: 'scroll',
        itemWidth: 14,
        itemHeight: 10,
        itemGap: 12,
        pageButtonItemGap: 5,
        pageButtonGap: 10,
        pageIconSize: 12,
        textStyle: {
            fontSize: 11,
            fontFamily: '"Segoe UI", sans-serif',
        },
    };
}

/**
 * Capture the current ECharts legend selected state before re-render.
 * Preserves which legend items the user has toggled on/off across auto-refreshes.
 */
function captureLegendSelected() {
    if (!DEMAND_STATE.chart) return {};
    var option = DEMAND_STATE.chart.getOption();
    if (option && option.legend && option.legend[0] && option.legend[0].selected) {
        return Object.assign({}, option.legend[0].selected);
    }
    return {};
}

/**
 * Get the standard grid configuration for demand charts
 * Uses fixed bottom padding (no longer varies based on legend content)
 * @returns {Object} ECharts grid configuration
 */
function getStandardGridConfig() {
    return {
        left: 55,
        right: 100,  // Room for vertical dataZoom slider (30px) + rate labels (70px)
        bottom: 135, // Room for x-axis title + legend + dataZoom slider
        top: 55,
        containLabel: false,
    };
}

/**
 * Curated categorical color palette for fixes, DPs, STARs
 * Designed for maximum visual distinction between adjacent items
 * Based on ColorBrewer qualitative palettes with aviation-friendly tones
 */
const CATEGORICAL_COLORS = [
    '#1f77b4', // blue
    '#ff7f0e', // orange
    '#2ca02c', // green
    '#d62728', // red
    '#9467bd', // purple
    '#8c564b', // brown
    '#e377c2', // pink
    '#17becf', // cyan
    '#bcbd22', // olive
    '#7f7f7f', // gray
    '#aec7e8', // light blue
    '#ffbb78', // light orange
    '#98df8a', // light green
    '#ff9896', // light red
    '#c5b0d5', // light purple
    '#c49c94', // light brown
    '#f7b6d2', // light pink
    '#9edae5', // light cyan
    '#dbdb8d', // light olive
    '#393b79', // dark blue
    '#637939', // dark olive
    '#8c6d31', // dark tan
    '#843c39', // dark red-brown
    '#7b4173', // dark magenta
];

/**
 * Get a consistent color for a category name (fix, DP, STAR)
 * Uses curated palette with hash-based selection for consistency
 * @param {string} name - Category name
 * @param {number} index - Optional index for sequential coloring
 * @returns {string} - Hex color code
 */
function getCategoricalColor(name, index) {
    if (!name) return '#6c757d';

    // If UNKNOWN, use a distinct gray
    if (name === 'UNKNOWN' || name === 'UNK') {
        return '#6c757d';
    }

    // Use index if provided, otherwise hash the name for consistent colors
    let idx;
    if (typeof index === 'number') {
        idx = index;
    } else {
        // Hash the name to get a consistent index
        let hash = 0;
        for (let i = 0; i < name.length; i++) {
            hash = name.charCodeAt(i) + ((hash << 5) - hash);
        }
        idx = Math.abs(hash);
    }

    return CATEGORICAL_COLORS[idx % CATEGORICAL_COLORS.length];
}

/**
 * Toggle legend visibility and update chart
 */
function toggleLegendVisibility() {
    DEMAND_STATE.legendVisible = !DEMAND_STATE.legendVisible;
    localStorage.setItem('demand_legend_visible', DEMAND_STATE.legendVisible);

    // Update toggle button text
    const toggleText = document.getElementById('legend_toggle_text');
    const toggleBtn = document.getElementById('demand_legend_toggle_btn');
    if (toggleText && toggleBtn) {
        if (DEMAND_STATE.legendVisible) {
            toggleText.textContent = PERTII18n.t('demand.legend.hide');
            toggleBtn.querySelector('i').className = 'fas fa-eye-slash';
        } else {
            toggleText.textContent = PERTII18n.t('demand.legend.show');
            toggleBtn.querySelector('i').className = 'fas fa-eye';
        }
    }

    // Re-render current chart view to apply legend visibility
    if (DEMAND_STATE.chart && DEMAND_STATE.lastDemandData) {
        renderCurrentView();
    }
}

/**
 * Update the header rate display with current rate values
 * @param {Object} rateData - Rate data object with rates property
 */
function updateHeaderRateDisplay(rateData) {
    const aarRow = document.getElementById('demand_header_aar_row');
    const adrRow = document.getElementById('demand_header_adr_row');

    if (!aarRow || !adrRow) return;

    // Get current rate values
    const rates = rateData && rateData.rates ? rateData.rates : null;
    const direction = DEMAND_STATE.direction;

    // Check which rates are enabled
    const showVatsimAar = DEMAND_STATE.showVatsimAar;
    const showVatsimAdr = DEMAND_STATE.showVatsimAdr;
    const showRwAar = DEMAND_STATE.showRwAar;
    const showRwAdr = DEMAND_STATE.showRwAdr;

    // Show/hide rows based on direction
    const showAar = direction === 'both' || direction === 'arr';
    const showAdr = direction === 'both' || direction === 'dep';

    aarRow.style.display = showAar && rates ? '' : 'none';
    adrRow.style.display = showAdr && rates ? '' : 'none';

    if (!rates) return;

    // Update AAR row values
    const vatsimAarEl = document.getElementById('header_vatsim_aar');
    const rwAarEl = document.getElementById('header_rw_aar');
    if (vatsimAarEl) {
        vatsimAarEl.textContent = (showVatsimAar && rates.vatsim_aar) ? rates.vatsim_aar : '--';
        vatsimAarEl.style.opacity = (showVatsimAar && rates.vatsim_aar) ? '1' : '0.4';
    }
    if (rwAarEl) {
        rwAarEl.textContent = (showRwAar && rates.rw_aar) ? rates.rw_aar : '--';
        rwAarEl.style.opacity = (showRwAar && rates.rw_aar) ? '1' : '0.4';
    }

    // Update ADR row values
    const vatsimAdrEl = document.getElementById('header_vatsim_adr');
    const rwAdrEl = document.getElementById('header_rw_adr');
    if (vatsimAdrEl) {
        vatsimAdrEl.textContent = (showVatsimAdr && rates.vatsim_adr) ? rates.vatsim_adr : '--';
        vatsimAdrEl.style.opacity = (showVatsimAdr && rates.vatsim_adr) ? '1' : '0.4';
    }
    if (rwAdrEl) {
        rwAdrEl.textContent = (showRwAdr && rates.rw_adr) ? rates.rw_adr : '--';
        rwAdrEl.style.opacity = (showRwAdr && rates.rw_adr) ? '1' : '0.4';
    }
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
    let display = PERTII18n.t('demand.status.refreshed', { time: localTime });
    if (adlUpdate) {
        display += ' | ' + PERTII18n.t('demand.status.adlTime', { time: formatTimeLabel(adlUpdate) });
    }
    $('#demand_last_update').text(display);
}

/**
 * Show loading indicator on chart
 */
function showLoading() {
    // Make sure chart and legend toggle are visible
    $('#demand_empty_state').hide();
    $('#demand_chart').show();
    $('#demand_legend_toggle_area').show();

    // Resize chart in case it was hidden
    if (DEMAND_STATE.chart) {
        DEMAND_STATE.chart.resize();
        DEMAND_STATE.chart.showLoading({
            text: PERTII18n.t('common.loading'),
            color: '#007bff',
            textColor: '#000',
            maskColor: 'rgba(255, 255, 255, 0.8)',
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
                    fontSize: 16,
                },
            },
        });
    }

    // Also show toast notification
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            toast: true,
            position: 'bottom-right',
            icon: 'error',
            title: PERTII18n.t('common.error'),
            text: message,
            timer: 5000,
            showConfirmButton: false,
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
    if (!airport) {return;}

    const params = new URLSearchParams({
        airport: airport,
        start: DEMAND_STATE.currentStart,
        end: DEMAND_STATE.currentEnd,
        direction: DEMAND_STATE.direction,
        granularity: getGranularityMinutes(),  // Pass granularity for time bin breakdown
    });

    console.log('[Demand] Summary API call - granularity:', getGranularityMinutes(), 'URL:', params.toString());
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
                DEMAND_STATE.dpBreakdown = normalizeBreakdownByProcedure(response.dp_breakdown || {}, 'dp');
                DEMAND_STATE.starBreakdown = normalizeBreakdownByProcedure(response.star_breakdown || {}, 'star');

                // Mark summary data as loaded (for caching)
                DEMAND_STATE.summaryLoaded = true;
                DEMAND_STATE.cacheTimestamp = Date.now(); // Refresh cache timestamp

                // Debug: Log breakdown data sizes and sample keys
                const carrierKeys = Object.keys(DEMAND_STATE.carrierBreakdown);
                console.log('[Demand] Summary API response (granularity=' + getGranularityMinutes() + '):',
                    'carrier bins:', carrierKeys.length,
                    'sample keys:', carrierKeys.slice(0, 5),
                    'expected interval:', getGranularityMinutes() + 'min',
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
        $tbody.append('<tr><td class="text-muted text-center" colspan="2">' + PERTII18n.t('demand.table.noData') + '</td></tr>');
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
        $tbody.append('<tr><td class="text-muted text-center" colspan="2">' + PERTII18n.t('demand.table.noData') + '</td></tr>');
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
    if (!airport) {return;}

    // Adjust timestamp back to bin start (subtract half interval)
    const intervalMs = getGranularityMinutes() * 60 * 1000;
    const halfInterval = intervalMs / 2;
    const binStartMs = new Date(timeBin).getTime() - halfInterval;
    const actualTimeBin = new Date(binStartMs).toISOString();

    const params = new URLSearchParams({
        airport: airport,
        time_bin: actualTimeBin,
        direction: DEMAND_STATE.direction,
        granularity: getGranularityMinutes(),
    });

    // Show loading in modal
    const timeLabel = formatTimeLabelZ(actualTimeBin);
    const endTime = new Date(binStartMs + intervalMs);
    const endLabel = formatTimeLabelZ(endTime.toISOString());

    Swal.fire({
        title: PERTII18n.t('demand.flightDetail.title', { start: timeLabel, end: endLabel }),
        html: '<div class="text-center"><i class="fas fa-spinner fa-spin fa-2x"></i><br>' + PERTII18n.t('demand.flightDetail.loading') + '</div>',
        showConfirmButton: false,
        showCloseButton: true,
        width: '900px',
        didOpen: function() {
            $.getJSON(`api/demand/summary.php?${params.toString()}`)
                .done(function(response) {
                    if (response.success && response.flights) {
                        const html = buildFlightListHtml(response.flights, clickedSeries);
                        Swal.update({
                            html: html,
                        });
                    } else {
                        Swal.update({
                            html: '<p class="text-muted">' + PERTII18n.t('demand.flightDetail.noFlights') + '</p>',
                        });
                    }
                })
                .fail(function() {
                    Swal.update({
                        html: '<p class="text-danger">' + PERTII18n.t('demand.flightDetail.loadFailed') + '</p>',
                    });
                });
        },
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
        return '<p class="text-muted">' + PERTII18n.t('demand.flightDetail.noFlights') + '</p>';
    }

    const chartView = DEMAND_STATE.chartView || 'status';
    const direction = DEMAND_STATE.direction || 'both';

    // Determine if we need an extra column based on chart view
    const showExtraColumn = chartView !== 'status';
    let extraColumnHeader = '';
    let extraColumnField = '';

    switch (chartView) {
        case 'origin':
            extraColumnHeader = PERTII18n.t('demand.flightDetail.col.originArtcc');
            extraColumnField = 'origin_artcc';
            break;
        case 'dest':
            extraColumnHeader = PERTII18n.t('demand.flightDetail.col.destArtcc');
            extraColumnField = 'dest_artcc';
            break;
        case 'carrier':
            extraColumnHeader = PERTII18n.t('demand.flightDetail.col.carrier');
            extraColumnField = 'carrier';
            break;
        case 'weight':
            extraColumnHeader = PERTII18n.t('demand.flightDetail.col.weight');
            extraColumnField = 'weight_class';
            break;
        case 'equipment':
            extraColumnHeader = PERTII18n.t('demand.flightDetail.col.equipment');
            extraColumnField = 'aircraft';
            break;
        case 'rule':
            extraColumnHeader = PERTII18n.t('demand.flightDetail.col.rule');
            extraColumnField = 'flight_rules';
            break;
        case 'dep_fix':
            extraColumnHeader = PERTII18n.t('demand.flightDetail.col.depFix');
            extraColumnField = 'dfix';
            break;
        case 'arr_fix':
            extraColumnHeader = PERTII18n.t('demand.flightDetail.col.arrFix');
            extraColumnField = 'afix';
            break;
        case 'dp':
            extraColumnHeader = PERTII18n.t('demand.flightDetail.col.sid');
            extraColumnField = 'dp_name';
            break;
        case 'star':
            extraColumnHeader = PERTII18n.t('demand.flightDetail.col.star');
            extraColumnField = 'star_name';
            break;
    }

    // Time column header based on direction
    let timeHeader = PERTII18n.t('demand.flightDetail.col.time');
    if (direction === 'arr') {timeHeader = PERTII18n.t('demand.flightDetail.col.eta');}
    else if (direction === 'dep') {timeHeader = PERTII18n.t('demand.flightDetail.col.etd');}

    let html = `
        <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
            <table class="table table-sm table-hover mb-0" style="font-size: 0.85rem;">
                <thead style="position: sticky; top: 0; background: #f8f9fa; z-index: 1;">
                    <tr>
                        <th style="border-bottom: 2px solid #dee2e6;">${PERTII18n.t('demand.flightDetail.col.callsign')}</th>
                        <th style="border-bottom: 2px solid #dee2e6;">${PERTII18n.t('demand.flightDetail.col.type')}</th>
                        <th style="border-bottom: 2px solid #dee2e6;">${PERTII18n.t('demand.flightDetail.col.origin')}</th>
                        <th style="border-bottom: 2px solid #dee2e6;">${PERTII18n.t('demand.flightDetail.col.dest')}</th>
                        <th style="border-bottom: 2px solid #dee2e6;">${timeHeader}</th>
                        ${showExtraColumn ? `<th style="border-bottom: 2px solid #dee2e6;">${extraColumnHeader}</th>` : ''}
                        <th style="border-bottom: 2px solid #dee2e6;">${PERTII18n.t('demand.flightDetail.col.status')}</th>
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
                <i class="fas fa-plane-arrival text-success"></i> ${PERTII18n.t('demand.flightDetail.arrival')} &nbsp;
                <i class="fas fa-plane-departure text-warning"></i> ${PERTII18n.t('demand.flightDetail.departure')}
            </div>
            <div>${PERTII18n.t('demand.chart.total')}: <strong>${flights.length}</strong> ${PERTII18n.tp('flight', flights.length)}</div>
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
        return 'color: var(--dark-text-subtle);';
    }

    let bgColor = null;

    switch (chartView) {
        case 'origin':
        case 'dest':
            // Use DCC region colors
            bgColor = getDCCRegionColor(value);
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
    // Use PERTI namespace if available
    if (typeof PERTI !== 'undefined' && PERTI.getCarrierColor) {
        var c = PERTI.getCarrierColor(carrier);
        var otherColor = (PERTI.UI && PERTI.UI.CARRIER_COLORS) ? PERTI.UI.CARRIER_COLORS.OTHER : null;
        if (c && c !== otherColor) return c;
    }
    // Fallback: common carriers with specific colors
    const CARRIER_COLORS = {
        'AAL': '#c41230', 'DAL': '#003366', 'UAL': '#002244', 'SWA': '#f9a825',
        'JBU': '#003876', 'ASA': '#00205b', 'FFT': '#00467f', 'SKW': '#1a1a1a',
        'RPA': '#00467f', 'ENY': '#c41230', 'PDT': '#c41230', 'PSA': '#c41230',
        'NKS': '#ffd700', 'AAY': '#ff6600', 'FDX': '#4d148c', 'UPS': '#351c15',
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
        'SUPER': '#9333ea',
    };
    return WEIGHT_COLORS[weightClass] || '#6b7280';
}

/**
 * Generate consistent color from any string value
 */
function getHashColor(str) {
    if (!str) {return '#6b7280';}
    let hash = 0;
    for (let i = 0; i < str.length; i++) {
        hash = str.charCodeAt(i) + ((hash << 5) - hash);
    }
    const hue = Math.abs(hash % 360);
    return `hsl(${hue}, 65%, 45%)`;
}

// ============================================================================
// Set Airport Config from Demand Page
// Allows users to publish a CONFIG NTML entry directly from the demand page
// ============================================================================

(function() {
    'use strict';

    // State for the config modal
    let configPresets = [];
    let defaultAar = null; // Preset default AAR for Strat/Dyn determination

    // ---- Helper functions ----

    function getCurrentDateDDHHMM() {
        const now = new Date();
        return String(now.getUTCDate()).padStart(2, '0') + '/' +
               String(now.getUTCHours()).padStart(2, '0') +
               String(now.getUTCMinutes()).padStart(2, '0');
    }

    function formatDateTimeLocalUTC(d) {
        const year = d.getUTCFullYear();
        const month = String(d.getUTCMonth() + 1).padStart(2, '0');
        const day = String(d.getUTCDate()).padStart(2, '0');
        const hours = String(d.getUTCHours()).padStart(2, '0');
        const mins = String(d.getUTCMinutes()).padStart(2, '0');
        return `${year}-${month}-${day}T${hours}:${mins}`;
    }

    function snapEndTimeToQuarter(date) {
        const d = new Date(date);
        const mins = d.getUTCMinutes();
        let snap;
        if (mins <= 14) {snap = 14;}
        else if (mins <= 29) {snap = 29;}
        else if (mins <= 44) {snap = 44;}
        else {snap = 59;}
        d.setUTCMinutes(snap);
        d.setUTCSeconds(0);
        d.setUTCMilliseconds(0);
        return d;
    }

    function getSmartDefaults() {
        const now = new Date();
        const start = new Date(now);
        start.setUTCSeconds(0);
        start.setUTCMilliseconds(0);

        const end = new Date(start);
        end.setUTCHours(end.getUTCHours() + 4);
        const snappedEnd = snapEndTimeToQuarter(end);

        return {
            start: formatDateTimeLocalUTC(start),
            end: formatDateTimeLocalUTC(snappedEnd)
        };
    }

    function formatValidTimeSuffix(from, until) {
        if (!from && !until) {return '';}
        const extractHHMM = (val) => {
            if (!val || !val.includes('T')) {return '';}
            const timePart = val.split('T')[1] || '00:00';
            return timePart.replace(':', '').substring(0, 4);
        };
        const fromStr = extractHHMM(from);
        const untilStr = extractHHMM(until);
        if (fromStr && untilStr) {return fromStr + '-' + untilStr;}
        if (untilStr) {return untilStr;}
        return '';
    }

    function formatConfigMessage(data) {
        const logTime = getCurrentDateDDHHMM();
        const airport = (data.ctl_element || 'N/A').toUpperCase();
        const weather = (data.weather || 'VMC').toUpperCase();
        const arrRwys = (data.arr_runways || 'N/A').toUpperCase();
        const depRwys = (data.dep_runways || 'N/A').toUpperCase();
        const aar = data.aar || '60';
        const adr = data.adr || '60';
        const aarType = data.aar_type || 'Strat';
        const validSuffix = formatValidTimeSuffix(data.valid_from, data.valid_until);

        let line = `${logTime}    ${airport} ${weather} ARR:${arrRwys} DEP:${depRwys} AAR(${aarType}):${aar}`;

        // Add AAR Adjustment reason if dynamic
        if (aarType === 'Dyn' && data.aar_adjustment) {
            line += ` AAR Adjustment:${data.aar_adjustment}`;
        }

        line += ` ADR:${adr}`;

        if (validSuffix && validSuffix !== 'TFN') {
            line += ` ${validSuffix}`;
        }

        return line;
    }

    // ---- Show/hide button ----

    function updateSetConfigButtonVisibility() {
        const btn = document.getElementById('set_config_btn');
        if (!btn) {return;}
        btn.style.display = DEMAND_STATE.selectedAirport ? '' : 'none';
    }

    // Hook into airport selection changes
    $(document).on('change', '#demand_airport', function() {
        // Small delay to let DEMAND_STATE update
        setTimeout(updateSetConfigButtonVisibility, 50);
    });

    // ---- Modal ----

    function showSetConfigModal() {
        const airport = DEMAND_STATE.selectedAirport;
        if (!airport) {return;}

        // Collect pre-population data
        const rd = DEMAND_STATE.rateData;
        const tmi = DEMAND_STATE.tmiConfig;
        const atis = DEMAND_STATE.atisData;

        // Prefer TMI config values, then rate data, then defaults
        let preAar = tmi?.aar ?? rd?.rates?.vatsim_aar ?? '';
        let preAdr = tmi?.adr ?? rd?.rates?.vatsim_adr ?? '';
        let preWeather = tmi?.weather_category ?? rd?.weather_category ?? 'VMC';
        let preArrRwys = tmi?.arr_runways ?? rd?.arr_runways ?? '';
        let preDepRwys = tmi?.dep_runways ?? rd?.dep_runways ?? '';
        let preAarType = tmi?.aar_type ?? 'Strat';

        // If ATIS has runway data and no TMI/rate runways, use ATIS
        if (!preArrRwys && atis?.runways?.arr_runways) {
            preArrRwys = atis.runways.arr_runways;
        }
        if (!preDepRwys && atis?.runways?.dep_runways) {
            preDepRwys = atis.runways.dep_runways;
        }

        const defaults = getSmartDefaults();
        const user = window.DEMAND_USER || {};

        // Store default AAR for Strat/Dyn comparison
        defaultAar = preAar || null;

        // Load config presets
        loadConfigPresets(airport, function() {
            buildAndShowModal(airport, {
                aar: preAar, adr: preAdr, weather: preWeather,
                arrRwys: preArrRwys, depRwys: preDepRwys, aarType: preAarType,
                validFrom: defaults.start, validUntil: defaults.end,
                user: user
            });
        });
    }

    function loadConfigPresets(airport, callback) {
        configPresets = [];
        $.getJSON('api/mgt/tmi/airport_configs.php', {airport: airport, active_only: 1})
            .done(function(resp) {
                if (resp.success && resp.configs) {
                    configPresets = resp.configs;
                }
                callback();
            })
            .fail(function() { callback(); });
    }

    function buildAndShowModal(airport, pre) {
        // Build preset options
        let presetOptions = '<option value="">-- ' + PERTII18n.t('demand.config.selectPreset') + ' --</option>';
        configPresets.forEach(function(c) {
            presetOptions += `<option value="${c.configId}">${c.configName || c.configCode || PERTII18n.t('demand.setConfig.configNum', { id: c.configId })}</option>`;
        });

        // Determine if AAR adjustment row should show
        const showAdjustment = pre.aarType === 'Dyn';

        const formHtml = `
            <div class="text-left" style="font-size: 0.85rem;">
                <div class="form-group mb-2">
                    <label class="font-weight-bold mb-1" style="font-size: 0.75rem;">${PERTII18n.t('demand.setConfig.configPreset')}</label>
                    <select class="form-control form-control-sm" id="sc_preset">${presetOptions}</select>
                </div>
                <div class="row mb-2">
                    <div class="col-6">
                        <label class="font-weight-bold mb-1" style="font-size: 0.75rem;">${PERTII18n.t('demand.setConfig.weather')}</label>
                        <select class="form-control form-control-sm" id="sc_weather">
                            <option value="VMC"${pre.weather === 'VMC' ? ' selected' : ''}>VMC</option>
                            <option value="MVFR"${pre.weather === 'MVFR' ? ' selected' : ''}>MVFR</option>
                            <option value="IMC"${pre.weather === 'IMC' ? ' selected' : ''}>IMC</option>
                            <option value="LIMC"${pre.weather === 'LIMC' ? ' selected' : ''}>LIMC</option>
                        </select>
                    </div>
                    <div class="col-6">&nbsp;</div>
                </div>
                <div class="row mb-2">
                    <div class="col-6">
                        <label class="font-weight-bold mb-1" style="font-size: 0.75rem;">${PERTII18n.t('demand.setConfig.arrivalRunways')}</label>
                        <input type="text" class="form-control form-control-sm" id="sc_arr_rwys" value="${pre.arrRwys}" placeholder="e.g. 27R">
                    </div>
                    <div class="col-6">
                        <label class="font-weight-bold mb-1" style="font-size: 0.75rem;">${PERTII18n.t('demand.setConfig.departureRunways')}</label>
                        <input type="text" class="form-control form-control-sm" id="sc_dep_rwys" value="${pre.depRwys}" placeholder="e.g. 27L/35">
                    </div>
                </div>
                <div class="row mb-2">
                    <div class="col-4">
                        <label class="font-weight-bold mb-1" style="font-size: 0.75rem;">${PERTII18n.t('demand.setConfig.aar')}</label>
                        <input type="number" class="form-control form-control-sm" id="sc_aar" value="${pre.aar}" min="0" max="200">
                    </div>
                    <div class="col-4">
                        <label class="font-weight-bold mb-1" style="font-size: 0.75rem;">${PERTII18n.t('demand.setConfig.adr')}</label>
                        <input type="number" class="form-control form-control-sm" id="sc_adr" value="${pre.adr}" min="0" max="200">
                    </div>
                    <div class="col-4">
                        <label class="font-weight-bold mb-1" style="font-size: 0.75rem;">${PERTII18n.t('demand.setConfig.aarType')}</label>
                        <input type="text" class="form-control form-control-sm bg-light" id="sc_aar_type" value="${pre.aarType}" readonly>
                    </div>
                </div>
                <div class="form-group mb-2" id="sc_adjustment_row" style="display: ${showAdjustment ? '' : 'none'};">
                    <label class="font-weight-bold mb-1" style="font-size: 0.75rem;">${PERTII18n.t('demand.setConfig.aarAdjustmentReason')}</label>
                    <input type="text" class="form-control form-control-sm" id="sc_aar_adjustment" placeholder="e.g. XW-TLWD">
                </div>
                <hr class="my-2">
                <div class="row mb-2">
                    <div class="col-6">
                        <label class="font-weight-bold mb-1" style="font-size: 0.75rem;">${PERTII18n.t('demand.setConfig.validFromUtc')}</label>
                        <input type="datetime-local" class="form-control form-control-sm" id="sc_valid_from" value="${pre.validFrom}">
                    </div>
                    <div class="col-6">
                        <label class="font-weight-bold mb-1" style="font-size: 0.75rem;">${PERTII18n.t('demand.setConfig.validUntilUtc')}</label>
                        <input type="datetime-local" class="form-control form-control-sm" id="sc_valid_until" value="${pre.validUntil}">
                    </div>
                </div>
                ${!pre.user.loggedIn ? `
                <hr class="my-2">
                <div class="row mb-2">
                    <div class="col-6">
                        <label class="font-weight-bold mb-1" style="font-size: 0.75rem;">${PERTII18n.t('demand.setConfig.yourName')}</label>
                        <input type="text" class="form-control form-control-sm" id="sc_user_name" placeholder="${PERTII18n.t('demand.setConfig.placeholder.name')}">
                    </div>
                    <div class="col-6">
                        <label class="font-weight-bold mb-1" style="font-size: 0.75rem;">${PERTII18n.t('demand.setConfig.cidOptional')}</label>
                        <input type="text" class="form-control form-control-sm" id="sc_user_cid" placeholder="${PERTII18n.t('demand.setConfig.placeholder.cid')}">
                    </div>
                </div>` : ''}
            </div>
        `;

        Swal.fire({
            title: '<i class="fas fa-tachometer-alt"></i> ' + PERTII18n.t('demand.setConfig.title', { airport: airport }),
            html: formHtml,
            width: 520,
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-paper-plane"></i> ' + PERTII18n.t('demand.setConfig.publish'),
            confirmButtonColor: '#2c3e50',
            cancelButtonText: PERTII18n.t('common.cancel'),
            didOpen: function() {
                // Preset change handler
                $('#sc_preset').on('change', function() {
                    const id = parseInt($(this).val());
                    const cfg = configPresets.find(c => c.configId === id);
                    if (!cfg) {return;}

                    $('#sc_arr_rwys').val(cfg.arrRunways || '');
                    $('#sc_dep_rwys').val(cfg.depRunways || '');

                    const weather = $('#sc_weather').val();
                    let aar = cfg.rates.vmcAar;
                    let adr = cfg.rates.vmcAdr;
                    if (weather === 'IMC' || weather === 'LIMC') {
                        aar = cfg.rates.imcAar || cfg.rates.vmcAar;
                        adr = cfg.rates.imcAdr || cfg.rates.vmcAdr;
                    }
                    if (aar) {$('#sc_aar').val(aar);}
                    if (adr) {$('#sc_adr').val(adr);}

                    // Store preset default AAR
                    defaultAar = aar || null;
                    updateAarType();
                });

                // Weather change handler
                $('#sc_weather').on('change', function() {
                    const id = parseInt($('#sc_preset').val());
                    const cfg = configPresets.find(c => c.configId === id);
                    if (!cfg) {return;}

                    const weather = $(this).val();
                    let aar = cfg.rates.vmcAar;
                    let adr = cfg.rates.vmcAdr;
                    if (weather === 'IMC' || weather === 'LIMC') {
                        aar = cfg.rates.imcAar || cfg.rates.vmcAar;
                        adr = cfg.rates.imcAdr || cfg.rates.vmcAdr;
                    }
                    if (aar) {$('#sc_aar').val(aar);}
                    if (adr) {$('#sc_adr').val(adr);}

                    defaultAar = aar || null;
                    updateAarType();
                });

                // AAR change handler - auto-determine Strat/Dyn
                $('#sc_aar').on('input change', function() {
                    updateAarType();
                });
            },
            preConfirm: function() {
                return validateAndCollect(airport, pre.user);
            }
        }).then(function(result) {
            if (result.isConfirmed && result.value) {
                publishConfig(result.value);
            }
        });
    }

    function updateAarType() {
        const currentAar = parseInt($('#sc_aar').val());
        const isDyn = defaultAar !== null && !isNaN(currentAar) && currentAar !== parseInt(defaultAar);

        $('#sc_aar_type').val(isDyn ? 'Dyn' : 'Strat');
        $('#sc_adjustment_row').toggle(isDyn);

        if (!isDyn) {
            $('#sc_aar_adjustment').val('');
        }
    }

    function validateAndCollect(airport, user) {
        const aar = $('#sc_aar').val();
        const adr = $('#sc_adr').val();
        const weather = $('#sc_weather').val();
        const validFrom = $('#sc_valid_from').val();
        const validUntil = $('#sc_valid_until').val();
        const aarType = $('#sc_aar_type').val();
        const aarAdjustment = $('#sc_aar_adjustment').val()?.trim() || '';

        if (!aar || !adr) {
            Swal.showValidationMessage(PERTII18n.t('demand.setConfig.validation.aarAdrRequired'));
            return false;
        }
        if (!validFrom || !validUntil) {
            Swal.showValidationMessage(PERTII18n.t('demand.setConfig.validation.validTimesRequired'));
            return false;
        }
        if (aarType === 'Dyn' && !aarAdjustment) {
            Swal.showValidationMessage(PERTII18n.t('demand.setConfig.validation.adjustmentRequired'));
            return false;
        }

        // Determine user identity
        let userName, userCid;
        if (user.loggedIn) {
            userName = user.name;
            userCid = user.cid;
        } else {
            userName = ($('#sc_user_name').val() || '').trim();
            userCid = ($('#sc_user_cid').val() || '').trim() || null;
            if (!userName) {
                Swal.showValidationMessage(PERTII18n.t('demand.setConfig.validation.nameRequired'));
                return false;
            }
        }

        return {
            data: {
                type: 'CONFIG',
                ctl_element: airport.toUpperCase(),
                req_facility: '',
                prov_facility: '',
                valid_from: validFrom,
                valid_until: validUntil,
                qualifiers: [],
                weather: weather,
                config_name: undefined,
                arr_runways: ($('#sc_arr_rwys').val() || '').trim().toUpperCase(),
                dep_runways: ($('#sc_dep_rwys').val() || '').trim().toUpperCase(),
                aar: aar,
                aar_type: aarType,
                adr: adr,
                aar_adjustment: aarAdjustment.toUpperCase()
            },
            userName: userName,
            userCid: userCid
        };
    }

    // ---- Publish ----

    function publishConfig(collected) {
        // Check for duplicate CONFIG first
        checkDuplicateConfig(collected.data.ctl_element, function(existing) {
            if (existing) {
                showDuplicatePrompt(collected, existing);
            } else {
                doPublish(collected);
            }
        });
    }

    function checkDuplicateConfig(airport, callback) {
        $.ajax({
            url: 'api/mgt/tmi/active.php',
            method: 'GET',
            data: {type: 'ntml', source: 'ALL'},
            success: function(response) {
                if (response.success && response.data) {
                    const all = [...(response.data.active || []), ...(response.data.scheduled || [])];
                    const existing = all.find(function(item) {
                        return item.entryType === 'CONFIG' &&
                            item.ctlElement &&
                            item.ctlElement.toUpperCase() === airport.toUpperCase() &&
                            item.status !== 'CANCELLED';
                    });
                    callback(existing || null);
                } else {
                    callback(null);
                }
            },
            error: function() { callback(null); }
        });
    }

    function showDuplicatePrompt(collected, existing) {
        const existingTime = existing.validFrom
            ? new Date(existing.validFrom).toLocaleString('en-US', {timeZone: 'UTC', hour: '2-digit', minute: '2-digit', hour12: false}) + 'Z'
            : PERTII18n.t('common.unknown');

        Swal.fire({
            title: '<i class="fas fa-exclamation-triangle text-warning"></i> ' + PERTII18n.t('demand.setConfig.existingConfig'),
            html: `
                <div class="text-left">
                    <p>${PERTII18n.t('demand.setConfig.existingConfigMsg', { airport: collected.data.ctl_element })}</p>
                    <div class="alert alert-secondary">
                        <strong>${PERTII18n.t('demand.setConfig.statusLabel')}:</strong> ${existing.status || 'ACTIVE'}<br>
                        <strong>${PERTII18n.t('demand.setConfig.postedLabel')}:</strong> ${existingTime}<br>
                        <strong>${PERTII18n.t('demand.setConfig.idLabel')}:</strong> #${existing.entityId}
                    </div>
                    <p>${PERTII18n.t('demand.setConfig.willUpdate')}</p>
                </div>
            `,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-edit"></i> ' + PERTII18n.t('demand.setConfig.updateAndPublish'),
            confirmButtonColor: '#2c3e50',
            cancelButtonText: PERTII18n.t('common.cancel')
        }).then(function(result) {
            if (result.isConfirmed) {
                doPublish(collected);
            }
        });
    }

    function doPublish(collected) {
        Swal.fire({
            title: PERTII18n.t('demand.setConfig.publishing'),
            allowOutsideClick: false,
            didOpen: function() { Swal.showLoading(); }
        });

        const message = formatConfigMessage(collected.data);

        const entry = {
            id: 'demand_config_' + Date.now(),
            type: 'ntml',
            entryType: 'CONFIG',
            data: collected.data,
            preview: message,
            orgs: window.PERTI_ORG && window.PERTI_ORG.global
                ? window.PERTI_ORG.allOrgs.filter(o => o !== 'global')
                : [window.PERTI_ORG ? window.PERTI_ORG.code : 'vatcscc'],
            timestamp: new Date().toISOString()
        };

        const payload = {
            production: true,
            entries: [entry],
            userCid: collected.userCid,
            userName: collected.userName
        };

        $.ajax({
            url: 'api/mgt/tmi/publish.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(payload)
        }).done(function(response) {
            if (response.success || (response.results && response.results.some(function(r) { return r.success; }))) {
                Swal.fire({
                    icon: 'success',
                    title: PERTII18n.t('demand.setConfig.publishedTitle'),
                    text: PERTII18n.t('demand.setConfig.publishedMsg', { airport: collected.data.ctl_element }),
                    timer: 2500,
                    showConfirmButton: false
                });

                // Refresh demand data to pick up new active config
                if (typeof loadDemandData === 'function') {
                    setTimeout(function() { loadDemandData(); }, 1000);
                }
            } else {
                const errMsg = response.error || (response.results && response.results[0] && response.results[0].error) || PERTII18n.t('common.unknownError');
                Swal.fire({
                    icon: 'error',
                    title: PERTII18n.t('demand.setConfig.publishFailed'),
                    html: '<p>' + errMsg + '</p>'
                });
            }
        }).fail(function(xhr) {
            Swal.fire({
                icon: 'error',
                title: PERTII18n.t('demand.setConfig.publishFailed'),
                html: '<p>' + (xhr.responseText || PERTII18n.t('demand.error.connectingToServer')) + '</p>'
            });
        });
    }

    // ---- Button click handler ----
    $(document).on('click', '#set_config_btn', function() {
        showSetConfigModal();
    });

})();

// Initialize when document is ready (only on demand.php page)
$(document).ready(function() {
    // Only initialize demand.php-specific code if we're on that page
    // Check for the demand chart container which only exists on demand.php
    if (document.getElementById('demand_chart')) {
        initDemand();
    }
});
