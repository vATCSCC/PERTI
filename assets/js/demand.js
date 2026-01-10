/**
 * PERTI Demand Visualization
 * Core client-side logic for demand charts and filtering
 */

// Global state
let DEMAND_STATE = {
    selectedAirport: null,
    granularity: 'hourly',
    timeRangeStart: -2, // hours before now
    timeRangeEnd: 14,   // hours after now
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
    chartView: 'status', // 'status' or 'origin'
    originBreakdown: null, // Store origin ARTCC breakdown data
    lastDemandData: null // Store last demand response for view switching
};

// Phase-based status colors - matches status.php color scheme
const FSM_STATUS_COLORS = {
    'active': '#dc2626',      // Red - Flight Active/Airborne (enroute, departed, descending phases)
    'arrived': '#1a1a1a',     // Black - Arrived at destination
    'departed': '#22c55e',    // Green - Taxiing (ground movement)
    'scheduled': '#06b6d4',   // Cyan - Prefile with is_active=1
    'proposed': '#67e8f9',    // Light Cyan - Prefile with is_active=0
    'unknown': '#eab308'      // Yellow - Unknown phase
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

// Time range options (from design document)
const TIME_RANGE_OPTIONS = [
    { value: 'T-2/+14', label: 'T-2H/T+14H', start: -2, end: 14 },
    { value: 'T-1/+6', label: 'T-1H/T+6H', start: -1, end: 6 },
    { value: 'T-3/+6', label: 'T-3H/T+6H', start: -3, end: 6 },
    { value: 'T-6/+6', label: '+/- 6H', start: -6, end: 6 },
    { value: 'T-12/+12', label: '+/- 12H', start: -12, end: 12 },
    { value: 'T-24/+24', label: '+/- 24H', start: -24, end: 24 }
];

// ARTCC tier data (loaded from JSON)
let ARTCC_TIERS = null;

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
 * Load ARTCC tier data from JSON file
 */
function loadTierData() {
    $.getJSON('assets/data/artcc_tiers.json')
        .done(function(data) {
            ARTCC_TIERS = data;
            populateARTCCDropdown();
            console.log('ARTCC tier data loaded.');
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

    // Granularity toggle
    $('input[name="demand_granularity"]').on('change', function() {
        DEMAND_STATE.granularity = $(this).val();
        if (DEMAND_STATE.selectedAirport) {
            loadDemandData();
        }
    });

    // Time range
    $('#demand_time_range').on('change', function() {
        const $selected = $(this).find(':selected');
        DEMAND_STATE.timeRangeStart = parseInt($selected.data('start'));
        DEMAND_STATE.timeRangeEnd = parseInt($selected.data('end'));
        if (DEMAND_STATE.selectedAirport) {
            loadDemandData();
        }
    });

    // Direction toggle
    $('input[name="demand_direction"]').on('change', function() {
        DEMAND_STATE.direction = $(this).val();
        if (DEMAND_STATE.selectedAirport) {
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

    // Chart view toggle (Status vs Origin)
    $('input[name="demand_chart_view"]').on('change', function() {
        DEMAND_STATE.chartView = $(this).val();
        if (DEMAND_STATE.selectedAirport && DEMAND_STATE.lastDemandData) {
            // Re-render with stored data
            if (DEMAND_STATE.chartView === 'origin') {
                renderOriginChart();
            } else {
                renderChart(DEMAND_STATE.lastDemandData);
            }
        }
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

    // Calculate time range using asymmetric start/end offsets
    const now = new Date();
    const start = new Date(now.getTime() + DEMAND_STATE.timeRangeStart * 60 * 60 * 1000);
    const end = new Date(now.getTime() + DEMAND_STATE.timeRangeEnd * 60 * 60 * 1000);

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

    $.getJSON(`api/demand/airport.php?${params.toString()}`)
        .done(function(response) {
            if (response.success) {
                DEMAND_STATE.lastUpdate = new Date();
                DEMAND_STATE.lastDemandData = response; // Store for view switching

                // Render based on current view mode
                if (DEMAND_STATE.chartView === 'origin') {
                    // Load origin data first, then render
                    loadFlightSummary(true);
                } else {
                    renderChart(response);
                }

                updateInfoBarStats(response);
                updateLastUpdateDisplay(response.last_adl_update);

                // Load flight summary data (for tables)
                if (DEMAND_STATE.chartView !== 'origin') {
                    loadFlightSummary(false);
                }
            } else {
                console.error('API error:', response.error);
                showError('Failed to load demand data: ' + response.error);
            }
        })
        .fail(function(err) {
            console.error('Request failed:', err);
            showError('Error connecting to server');
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
    const series = [];

    if (direction === 'arr' || direction === 'both') {
        // Build arrival series by status (normalize time bins for lookup)
        const arrivalsByBin = {};
        arrivals.forEach(d => { arrivalsByBin[normalizeTimeBin(d.time_bin)] = d.breakdown; });

        series.push(
            buildStatusSeriesTimeAxis('Active (Arr)', timeBins, arrivalsByBin, 'active', 'arrivals'),
            buildStatusSeriesTimeAxis('Scheduled (Arr)', timeBins, arrivalsByBin, 'scheduled', 'arrivals'),
            buildStatusSeriesTimeAxis('Proposed (Arr)', timeBins, arrivalsByBin, 'proposed', 'arrivals'),
            buildStatusSeriesTimeAxis('Arrived', timeBins, arrivalsByBin, 'arrived', 'arrivals')
        );
    }

    if (direction === 'dep' || direction === 'both') {
        // Build departure series by status (normalize time bins for lookup)
        const departuresByBin = {};
        departures.forEach(d => { departuresByBin[normalizeTimeBin(d.time_bin)] = d.breakdown; });

        series.push(
            buildStatusSeriesTimeAxis('Active (Dep)', timeBins, departuresByBin, 'active', 'departures'),
            buildStatusSeriesTimeAxis('Scheduled (Dep)', timeBins, departuresByBin, 'scheduled', 'departures'),
            buildStatusSeriesTimeAxis('Proposed (Dep)', timeBins, departuresByBin, 'proposed', 'departures'),
            buildStatusSeriesTimeAxis('Departed', timeBins, departuresByBin, 'departed', 'departures')
        );
    }

    // Add current time marker to first series
    const timeMarkLine = getCurrentTimeMarkLineForTimeAxis();
    if (series.length > 0 && timeMarkLine) {
        series[0].markLine = timeMarkLine;
    }

    // Calculate interval for x-axis bounds
    const intervalMs = DEMAND_STATE.granularity === '15min' ? 15 * 60 * 1000 : 60 * 60 * 1000;

    // Chart options - TBFM/FSM/AADC style with TRUE TIME AXIS
    const option = {
        backgroundColor: '#ffffff',
        title: {
            text: `${data.airport} ${getDirectionLabel()}`,
            left: 'center',
            top: 10,
            textStyle: {
                fontSize: 16,
                fontWeight: 'bold',
                color: '#333',
                fontFamily: '"Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif'
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
                return tooltip;
            }
        },
        legend: {
            bottom: 5,
            left: 'center',
            type: 'scroll',
            itemWidth: 14,
            itemHeight: 10,
            textStyle: {
                fontSize: 11,
                fontFamily: '"Segoe UI", sans-serif'
            }
        },
        grid: {
            left: 55,
            right: 25,
            bottom: 70,
            top: 55,
            containLabel: false
        },
        xAxis: {
            type: 'time',
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
                fontWeight: 500,
                formatter: function(value) {
                    const d = new Date(value);
                    const h = d.getUTCHours().toString().padStart(2, '0');
                    const m = d.getUTCMinutes().toString().padStart(2, '0');
                    // AADC style: "1200", "1300", "1330" etc. (no colon, no Z)
                    return h + m;
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
            name: 'Flights',
            nameLocation: 'middle',
            nameGap: 40,
            nameTextStyle: {
                fontSize: 12,
                color: '#333',
                fontWeight: 500
            },
            minInterval: 1,
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
                showFlightDetails(timeBin);
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
    const intervalMs = DEMAND_STATE.granularity === '15min' ? 15 * 60 * 1000 : 60 * 60 * 1000;

    // Build series for each ARTCC with TRUE TIME AXIS data format
    const series = artccList.map(artcc => {
        const seriesData = timeBins.map(bin => {
            const binData = originBreakdown[normalizeTimeBin(bin)] || originBreakdown[bin] || [];
            const artccEntry = Array.isArray(binData) ? binData.find(item => item.artcc === artcc) : null;
            const value = artccEntry ? artccEntry.count : 0;
            return [new Date(bin).getTime(), value];
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

    // Add current time marker to first series
    const timeMarkLine = getCurrentTimeMarkLineForTimeAxis();
    if (series.length > 0 && timeMarkLine) {
        series[0].markLine = timeMarkLine;
    }

    // Chart options - TBFM/FSM/AADC style with TRUE TIME AXIS
    const option = {
        backgroundColor: '#ffffff',
        title: {
            text: `${data.airport} Arrivals by Origin ARTCC`,
            left: 'center',
            top: 10,
            textStyle: {
                fontSize: 16,
                fontWeight: 'bold',
                color: '#333',
                fontFamily: '"Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif'
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
                return tooltip;
            }
        },
        legend: {
            bottom: 5,
            left: 'center',
            type: 'scroll',
            itemWidth: 14,
            itemHeight: 10,
            textStyle: {
                fontSize: 11,
                fontFamily: '"Segoe UI", sans-serif'
            }
        },
        grid: {
            left: 55,
            right: 25,
            bottom: 70,
            top: 55,
            containLabel: false
        },
        xAxis: {
            type: 'time',
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
                fontWeight: 500,
                formatter: function(value) {
                    const d = new Date(value);
                    const h = d.getUTCHours().toString().padStart(2, '0');
                    const m = d.getUTCMinutes().toString().padStart(2, '0');
                    // AADC style: "1200", "1300", "1330" etc.
                    return h + m;
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
            name: 'Arrivals',
            nameLocation: 'middle',
            nameGap: 40,
            nameTextStyle: {
                fontSize: 12,
                color: '#333',
                fontWeight: 500
            },
            minInterval: 1,
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
                showFlightDetails(timeBin);
            }
        }
    });
}

/**
 * Update info bar stats with response data
 */
function updateInfoBarStats(data) {
    const arrivals = data.data.arrivals || [];
    const departures = data.data.departures || [];

    // Calculate arrival totals
    let arrTotal = 0, arrActive = 0, arrScheduled = 0, arrProposed = 0;
    arrivals.forEach(d => {
        const b = d.breakdown || {};
        arrActive += b.active || 0;
        arrScheduled += b.scheduled || 0;
        arrProposed += b.proposed || 0;
        arrTotal += d.total || 0;
    });

    // Calculate departure totals
    let depTotal = 0, depActive = 0, depScheduled = 0, depProposed = 0;
    departures.forEach(d => {
        const b = d.breakdown || {};
        depActive += b.active || 0;
        depScheduled += b.scheduled || 0;
        depProposed += b.proposed || 0;
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
 * Build a series for a specific status - TBFM/FSM style with TRUE TIME AXIS
 * Data format: [[timestamp, value], [timestamp, value], ...]
 */
function buildStatusSeriesTimeAxis(name, timeBins, dataByBin, status, type) {
    // Build data as [timestamp, value] pairs for time axis
    const data = timeBins.map(bin => {
        const breakdown = dataByBin[bin];
        const value = breakdown ? (breakdown[status] || 0) : 0;
        return [new Date(bin).getTime(), value];
    });

    // TBFM/FSM color palette - high contrast for ATC displays
    let color = FSM_STATUS_COLORS[status] || '#999';
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
 */
function formatTimeLabelFromTimestamp(timestamp) {
    const d = new Date(timestamp);
    const hours = d.getUTCHours().toString().padStart(2, '0');
    const minutes = d.getUTCMinutes().toString().padStart(2, '0');

    // Calculate end time based on granularity
    const intervalMinutes = DEMAND_STATE.granularity === '15min' ? 15 : 60;
    const endTime = new Date(timestamp + intervalMinutes * 60 * 1000);
    const endHours = endTime.getUTCHours().toString().padStart(2, '0');
    const endMinutes = endTime.getUTCMinutes().toString().padStart(2, '0');

    // AADC style: "1400" or "1400 - 1500"
    return `${hours}${minutes} - ${endHours}${endMinutes}`;
}

/**
 * Get current time markLine for TRUE TIME AXIS - FAA AADC style
 */
function getCurrentTimeMarkLineForTimeAxis() {
    const now = new Date();
    const hours = now.getUTCHours().toString().padStart(2, '0');
    const minutes = now.getUTCMinutes().toString().padStart(2, '0');

    return {
        silent: true,
        symbol: ['none', 'none'],
        lineStyle: {
            color: '#0066CC',
            width: 2,
            type: 'solid'
        },
        label: {
            show: true,
            formatter: `${hours}${minutes}z`,
            position: 'start',
            color: '#0066CC',
            fontWeight: 'bold',
            fontSize: 10,
            fontFamily: '"Inconsolata", monospace',
            backgroundColor: 'rgba(255,255,255,0.95)',
            padding: [2, 6],
            borderRadius: 2,
            borderColor: '#0066CC',
            borderWidth: 1
        },
        data: [{
            xAxis: now.getTime()
        }]
    };
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
    const now = new Date();
    const start = new Date(now.getTime() + DEMAND_STATE.timeRangeStart * 60 * 60 * 1000);
    const end = new Date(now.getTime() + DEMAND_STATE.timeRangeEnd * 60 * 60 * 1000);

    // Round start down and end up to nearest interval
    const intervalMinutes = DEMAND_STATE.granularity === '15min' ? 15 : 60;

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

                // Store origin breakdown for chart
                DEMAND_STATE.originBreakdown = response.origin_artcc_breakdown || {};

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

                // Render origin chart if requested
                if (renderOriginChartAfter) {
                    renderOriginChart();
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
 */
function showFlightDetails(timeBin) {
    const airport = DEMAND_STATE.selectedAirport;
    if (!airport) return;

    const params = new URLSearchParams({
        airport: airport,
        time_bin: timeBin,
        direction: DEMAND_STATE.direction
    });

    // Show loading in modal
    const timeLabel = formatTimeLabel(timeBin);
    const endTime = new Date(new Date(timeBin).getTime() + 60 * 60 * 1000);
    const endLabel = formatTimeLabel(endTime.toISOString());

    Swal.fire({
        title: `Flights: ${timeLabel} - ${endLabel}`,
        html: '<div class="text-center"><i class="fas fa-spinner fa-spin fa-2x"></i><br>Loading flights...</div>',
        showConfirmButton: false,
        showCloseButton: true,
        width: '800px',
        didOpen: function() {
            $.getJSON(`api/demand/summary.php?${params.toString()}`)
                .done(function(response) {
                    if (response.success && response.flights) {
                        const html = buildFlightListHtml(response.flights);
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
 * Build HTML for flight list
 */
function buildFlightListHtml(flights) {
    if (!flights || flights.length === 0) {
        return '<p class="text-muted">No flights found for this time period.</p>';
    }

    let html = `
        <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
            <table class="table table-sm table-striped table-hover mb-0">
                <thead class="thead-light" style="position: sticky; top: 0;">
                    <tr>
                        <th>Callsign</th>
                        <th>Type</th>
                        <th>Origin</th>
                        <th>Dest</th>
                        <th>Time</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
    `;

    flights.forEach(function(flight) {
        const statusClass = getStatusBadgeClass(flight.status);
        const dirIcon = flight.direction === 'arrival'
            ? '<i class="fas fa-plane-arrival text-success"></i>'
            : '<i class="fas fa-plane-departure text-warning"></i>';
        const time = flight.time ? formatTimeLabel(flight.time) : '--';

        html += `
            <tr>
                <td><strong>${flight.callsign || '--'}</strong></td>
                <td>${flight.aircraft || '--'}</td>
                <td>${flight.origin || '--'}</td>
                <td>${flight.destination || '--'}</td>
                <td>${time} ${dirIcon}</td>
                <td><span class="badge ${statusClass}">${flight.status || 'unknown'}</span></td>
            </tr>
        `;
    });

    html += `
                </tbody>
            </table>
        </div>
        <div class="mt-2 text-muted small">
            <i class="fas fa-plane-arrival text-success"></i> Arrival &nbsp;
            <i class="fas fa-plane-departure text-warning"></i> Departure &nbsp;
            Total: ${flights.length} flights
        </div>
    `;

    return html;
}

/**
 * Get Bootstrap badge class for status
 */
function getStatusBadgeClass(status) {
    switch (status) {
        case 'active': return 'badge-danger';
        case 'arrived': return 'badge-dark';
        case 'departed': return 'badge-success';
        case 'scheduled': return 'badge-info';
        case 'proposed': return 'badge-primary';
        default: return 'badge-secondary';
    }
}

// Initialize when document is ready
$(document).ready(function() {
    initDemand();
});
