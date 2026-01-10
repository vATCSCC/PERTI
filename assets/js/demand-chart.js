/**
 * PERTI Shared Demand Chart Module
 * Reusable demand visualization using ECharts
 * Used by both demand.php and gdt.php
 */

// Namespace for shared demand chart functionality
window.DemandChart = (function() {
    'use strict';

    // Phase colors - use shared config from phase-colors.js
    const PHASE_COLORS = (typeof window.PHASE_COLORS !== 'undefined') ? window.PHASE_COLORS : {
        'arrived': '#1a1a1a',
        'disconnected': '#f97316',
        'descending': '#991b1b',
        'enroute': '#dc2626',
        'departed': '#f87171',
        'taxiing': '#22c55e',
        'prefile': '#3b82f6',
        'unknown': '#eab308'
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
        'unknown': 'Unknown'
    };

    // Phase stacking order (bottom to top)
    const PHASE_ORDER = ['arrived', 'disconnected', 'descending', 'enroute', 'departed', 'taxiing', 'prefile', 'unknown'];

    /**
     * Create a new demand chart instance
     * @param {HTMLElement|string} container - DOM element or element ID
     * @param {Object} options - Chart options
     * @returns {Object} Chart controller instance
     */
    function create(container, options = {}) {
        const el = typeof container === 'string' ? document.getElementById(container) : container;
        if (!el) {
            console.error('DemandChart: container not found');
            return null;
        }

        const chart = echarts.init(el);

        const state = {
            chart: chart,
            airport: null,
            direction: options.direction || 'both',
            granularity: options.granularity || 'hourly',
            timeRangeStart: options.timeRangeStart || -2,
            timeRangeEnd: options.timeRangeEnd || 14,
            lastData: null,
            rateData: null
        };

        // Handle window resize
        const resizeHandler = function() {
            if (state.chart) {
                state.chart.resize();
            }
        };
        window.addEventListener('resize', resizeHandler);

        // Return controller object
        return {
            /**
             * Load and render demand data for an airport
             * @param {string} airport - ICAO airport code
             * @param {Object} opts - Override options (direction, granularity, timeRangeStart, timeRangeEnd)
             */
            load: function(airport, opts = {}) {
                if (!airport) {
                    this.clear();
                    return Promise.resolve();
                }

                state.airport = airport;
                if (opts.direction) state.direction = opts.direction;
                if (opts.granularity) state.granularity = opts.granularity;
                if (opts.timeRangeStart !== undefined) state.timeRangeStart = opts.timeRangeStart;
                if (opts.timeRangeEnd !== undefined) state.timeRangeEnd = opts.timeRangeEnd;

                // Calculate time range
                const now = new Date();
                const start = new Date(now.getTime() + state.timeRangeStart * 60 * 60 * 1000);
                const end = new Date(now.getTime() + state.timeRangeEnd * 60 * 60 * 1000);

                const params = new URLSearchParams({
                    airport: airport,
                    granularity: state.granularity,
                    direction: state.direction,
                    start: start.toISOString(),
                    end: end.toISOString()
                });

                // Show loading
                state.chart.showLoading({
                    text: 'Loading...',
                    maskColor: 'rgba(255, 255, 255, 0.8)',
                    textColor: '#333'
                });

                // Fetch demand data and rates in parallel
                const demandPromise = fetch(`api/demand/airport.php?${params.toString()}`).then(r => r.json());
                const ratesPromise = fetch(`api/demand/rates.php?airport=${encodeURIComponent(airport)}`).then(r => r.json()).catch(() => null);

                return Promise.all([demandPromise, ratesPromise]).then(([demandResponse, ratesResponse]) => {
                    state.chart.hideLoading();

                    if (!demandResponse.success) {
                        console.error('Demand API error:', demandResponse.error);
                        return { success: false, error: demandResponse.error };
                    }

                    state.lastData = demandResponse;
                    state.rateData = (ratesResponse && ratesResponse.success) ? ratesResponse : null;

                    this.render();
                    return { success: true, data: demandResponse, rates: state.rateData };
                }).catch(err => {
                    state.chart.hideLoading();
                    console.error('DemandChart load error:', err);
                    return { success: false, error: err.message };
                });
            },

            /**
             * Render the chart with current data
             */
            render: function() {
                if (!state.chart || !state.lastData) return;

                const data = state.lastData;
                const arrivals = data.data.arrivals || [];
                const departures = data.data.departures || [];
                const direction = state.direction;

                // Generate complete time bins
                const timeBins = generateAllTimeBins(state.granularity, state.timeRangeStart, state.timeRangeEnd);

                // Normalize time bin helper
                const normalizeTimeBin = (bin) => {
                    const d = new Date(bin);
                    d.setUTCSeconds(0, 0);
                    return d.toISOString().replace('.000Z', 'Z');
                };

                // Build series
                const series = [];

                if (direction === 'arr' || direction === 'both') {
                    const arrivalsByBin = {};
                    arrivals.forEach(d => { arrivalsByBin[normalizeTimeBin(d.time_bin)] = d.breakdown; });

                    PHASE_ORDER.forEach(phase => {
                        const suffix = direction === 'both' ? ' (Arr)' : '';
                        series.push(buildPhaseSeries(PHASE_LABELS[phase] + suffix, timeBins, arrivalsByBin, phase, 'arrivals', direction, state.granularity));
                    });
                }

                if (direction === 'dep' || direction === 'both') {
                    const departuresByBin = {};
                    departures.forEach(d => { departuresByBin[normalizeTimeBin(d.time_bin)] = d.breakdown; });

                    PHASE_ORDER.forEach(phase => {
                        const suffix = direction === 'both' ? ' (Dep)' : '';
                        series.push(buildPhaseSeries(PHASE_LABELS[phase] + suffix, timeBins, departuresByBin, phase, 'departures', direction, state.granularity));
                    });
                }

                // Add current time marker and rate lines to first series
                const timeMarkLineData = getCurrentTimeMarkLine();
                const rateMarkLines = buildRateMarkLines(state.rateData, direction);

                if (series.length > 0) {
                    const markLineData = [];
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

                // Calculate interval for x-axis bounds
                const intervalMs = getGranularityMinutes(state.granularity) * 60 * 1000;

                // Build chart title
                const chartTitle = buildChartTitle(data.airport, data.last_adl_update);

                // Chart options
                const option = {
                    backgroundColor: '#ffffff',
                    title: {
                        text: chartTitle,
                        left: 'center',
                        top: 10,
                        textStyle: {
                            fontSize: 13,
                            fontWeight: 'bold',
                            color: '#333',
                            fontFamily: '"Inconsolata", "SF Mono", monospace'
                        }
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
                            const timestamp = params[0].value[0];
                            const timeStr = formatTimeLabelFromTimestamp(timestamp);
                            let tooltip = `<strong style="font-size:12px;">${timeStr}</strong><br/>`;
                            let total = 0;
                            params.forEach(p => {
                                const val = p.value[1] || 0;
                                if (val > 0) {
                                    tooltip += `${p.marker} ${p.seriesName}: <strong>${val}</strong><br/>`;
                                    total += val;
                                }
                            });
                            tooltip += `<hr style="margin:4px 0;border-color:#ddd;"/><strong>Total: ${total}</strong>`;
                            return tooltip;
                        }
                    },
                    legend: {
                        bottom: 5,
                        left: 'center',
                        type: 'scroll',
                        itemWidth: 12,
                        itemHeight: 8,
                        textStyle: { fontSize: 10, fontFamily: '"Segoe UI", sans-serif' }
                    },
                    grid: {
                        left: 50,
                        right: 20,
                        bottom: 70,
                        top: 45,
                        containLabel: false
                    },
                    xAxis: {
                        type: 'time',
                        name: getXAxisLabel(state.granularity, direction),
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
                                const h = d.getUTCHours().toString().padStart(2, '0');
                                const m = d.getUTCMinutes().toString().padStart(2, '0');
                                return h + m + 'z';
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

            /**
             * Update chart settings
             * @param {Object} opts - Options to update (direction, granularity, etc.)
             */
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

            /**
             * Clear the chart
             */
            clear: function() {
                state.lastData = null;
                state.rateData = null;
                state.airport = null;
                if (state.chart) {
                    state.chart.clear();
                }
            },

            /**
             * Get current rate data
             */
            getRateData: function() {
                return state.rateData;
            },

            /**
             * Get current state
             */
            getState: function() {
                return {
                    airport: state.airport,
                    direction: state.direction,
                    granularity: state.granularity,
                    timeRangeStart: state.timeRangeStart,
                    timeRangeEnd: state.timeRangeEnd
                };
            },

            /**
             * Dispose the chart
             */
            dispose: function() {
                window.removeEventListener('resize', resizeHandler);
                if (state.chart) {
                    state.chart.dispose();
                    state.chart = null;
                }
            },

            /**
             * Resize the chart
             */
            resize: function() {
                if (state.chart) {
                    state.chart.resize();
                }
            }
        };
    }

    // Helper functions

    function getGranularityMinutes(granularity) {
        switch (granularity) {
            case '15min': return 15;
            case '30min': return 30;
            case 'hourly':
            default: return 60;
        }
    }

    function generateAllTimeBins(granularity, startHours, endHours) {
        const intervalMs = getGranularityMinutes(granularity) * 60 * 1000;
        const now = new Date();
        const start = new Date(now.getTime() + startHours * 60 * 60 * 1000);
        const end = new Date(now.getTime() + endHours * 60 * 60 * 1000);

        // Align to interval boundaries
        start.setUTCMilliseconds(0);
        start.setUTCSeconds(0);
        const startMinutes = start.getUTCMinutes();
        const intervalMinutes = getGranularityMinutes(granularity);
        start.setUTCMinutes(Math.floor(startMinutes / intervalMinutes) * intervalMinutes);

        const bins = [];
        let current = new Date(start);
        while (current <= end) {
            bins.push(current.toISOString().replace('.000Z', 'Z'));
            current = new Date(current.getTime() + intervalMs);
        }
        return bins;
    }

    function buildPhaseSeries(name, timeBins, dataByBin, phase, dataType, direction, granularity) {
        const intervalMs = getGranularityMinutes(granularity) * 60 * 1000;
        const halfInterval = intervalMs / 2;

        const normalizeTimeBin = (bin) => {
            const d = new Date(bin);
            d.setUTCSeconds(0, 0);
            return d.toISOString().replace('.000Z', 'Z');
        };

        const seriesData = timeBins.map(bin => {
            const normalizedBin = normalizeTimeBin(bin);
            const breakdown = dataByBin[normalizedBin] || {};
            const value = breakdown[phase] || 0;
            return [new Date(bin).getTime() + halfInterval, value];
        });

        // Determine stack name
        let stackName = dataType;
        if (direction === 'both') {
            stackName = dataType === 'arrivals' ? 'arrivals' : 'departures';
        }

        return {
            name: name,
            type: 'bar',
            stack: stackName,
            barWidth: direction === 'both' ? '35%' : '70%',
            barGap: direction === 'both' ? '10%' : '0%',
            emphasis: {
                focus: 'series',
                itemStyle: { shadowBlur: 2, shadowColor: 'rgba(0,0,0,0.2)' }
            },
            itemStyle: {
                color: PHASE_COLORS[phase] || '#999999',
                borderColor: 'transparent',
                borderWidth: 0
            },
            data: seriesData
        };
    }

    function getCurrentTimeMarkLine() {
        const now = new Date();
        return {
            xAxis: now.getTime(),
            lineStyle: {
                color: '#ef4444',
                width: 2,
                type: 'solid'
            },
            label: {
                show: true,
                formatter: 'NOW',
                position: 'insideEndTop',
                color: '#ef4444',
                fontSize: 9,
                fontWeight: 'bold',
                backgroundColor: 'rgba(255,255,255,0.9)',
                padding: [2, 4],
                borderRadius: 2
            }
        };
    }

    function buildRateMarkLines(rateData, direction) {
        if (!rateData || !rateData.rates) return [];

        const lines = [];
        const isActive = !rateData.is_suggested;
        const vatsimColor = isActive ? '#FFFFFF' : '#888888';
        const rwColor = isActive ? '#00FFFF' : '#008080';

        // AAR line (solid)
        if ((direction === 'arr' || direction === 'both') && rateData.rates.vatsim_aar) {
            lines.push({
                yAxis: rateData.rates.vatsim_aar,
                lineStyle: { color: vatsimColor, width: 2, type: 'solid' },
                label: {
                    show: true,
                    formatter: `AAR ${rateData.rates.vatsim_aar}`,
                    position: 'end',
                    color: vatsimColor,
                    fontSize: 9,
                    fontWeight: 'bold',
                    backgroundColor: 'rgba(0,0,0,0.6)',
                    padding: [2, 4],
                    borderRadius: 2
                }
            });
        }

        // ADR line (dashed)
        if ((direction === 'dep' || direction === 'both') && rateData.rates.vatsim_adr) {
            lines.push({
                yAxis: rateData.rates.vatsim_adr,
                lineStyle: { color: vatsimColor, width: 2, type: 'dashed' },
                label: {
                    show: true,
                    formatter: `ADR ${rateData.rates.vatsim_adr}`,
                    position: 'end',
                    color: vatsimColor,
                    fontSize: 9,
                    fontWeight: 'bold',
                    backgroundColor: 'rgba(0,0,0,0.6)',
                    padding: [2, 4],
                    borderRadius: 2
                }
            });
        }

        return lines;
    }

    function buildChartTitle(airport, lastUpdate) {
        const now = new Date();
        const dateStr = now.toISOString().substring(0, 10);
        const timeStr = now.getUTCHours().toString().padStart(2, '0') +
                       now.getUTCMinutes().toString().padStart(2, '0') + 'Z';
        return `${airport || '--'}  |  ${dateStr}  |  ${timeStr}`;
    }

    function formatTimeLabelFromTimestamp(timestamp) {
        const d = new Date(timestamp);
        const h = d.getUTCHours().toString().padStart(2, '0');
        const m = d.getUTCMinutes().toString().padStart(2, '0');
        const month = (d.getUTCMonth() + 1).toString().padStart(2, '0');
        const day = d.getUTCDate().toString().padStart(2, '0');
        return `${month}/${day} ${h}${m}Z`;
    }

    function getXAxisLabel(granularity, direction) {
        const intervalMin = getGranularityMinutes(granularity);
        const dirLabel = direction === 'arr' ? 'Arrivals' :
                        direction === 'dep' ? 'Departures' : 'Arrivals & Departures';
        return `${dirLabel} (${intervalMin}-min bins, UTC)`;
    }

    // Public API
    return {
        create: create,
        PHASE_COLORS: PHASE_COLORS,
        PHASE_LABELS: PHASE_LABELS,
        PHASE_ORDER: PHASE_ORDER
    };
})();
