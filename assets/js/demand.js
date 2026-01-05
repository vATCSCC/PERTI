/**
 * PERTI Demand Visualization
 * Core client-side logic for demand charts and filtering
 */

// Global state
let DEMAND_STATE = {
    selectedAirport: null,
    granularity: 'hourly',
    timeRange: 6, // hours (+/-)
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

// FSM Status colors from design document Table 7-1
const FSM_STATUS_COLORS = {
    'active': '#FF0000',      // Red - Flight Active (airborne)
    'arrived': '#000000',     // Black - Arrived
    'departed': '#006400',    // Dark Green - Departed
    'scheduled': '#90EE90',   // Light Green - Scheduled (Dep No CTD)
    'proposed': '#0066FF',    // Blue - Proposed
    'dep_past_etd': '#8B4513' // Brown - Dep Past ETD
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
    { value: 1, label: '+/- 1H' },
    { value: 2, label: '+/- 2H' },
    { value: 3, label: '+/- 3H' },
    { value: 4, label: '+/- 4H' },
    { value: 5, label: '+/- 5H' },
    { value: 6, label: '+/- 6H' },
    { value: 8, label: '+/- 8H' },
    { value: 12, label: '+/- 12H' },
    { value: 24, label: '+/- 24H' },
    { value: 36, label: '+/- 36H' },
    { value: 48, label: '+/- 48H' },
    { value: 72, label: '+/- 72H' },
    { value: 96, label: '+/- 96H' }
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
        const selected = opt.value === DEMAND_STATE.timeRange ? 'selected' : '';
        select.append(`<option value="${opt.value}" ${selected}>${opt.label}</option>`);
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
        let label = apt.icao;
        if (apt.is_core30) {
            label += ' (Core30)';
        } else if (apt.is_oep35) {
            label += ' (OEP35)';
        } else if (apt.is_aspm77) {
            label += ' (ASPM77)';
        }
        select.append(`<option value="${apt.icao}" data-name="${apt.name}" data-artcc="${apt.artcc}">${label}</option>`);
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
        DEMAND_STATE.timeRange = parseInt($(this).val());
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

    // Calculate time range
    const now = new Date();
    const start = new Date(now.getTime() - DEMAND_STATE.timeRange * 60 * 60 * 1000);
    const end = new Date(now.getTime() + DEMAND_STATE.timeRange * 60 * 60 * 1000);

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
 * Render the demand chart with data
 */
function renderChart(data) {
    if (!DEMAND_STATE.chart) {
        console.error('Chart not initialized');
        return;
    }

    const arrivals = data.data.arrivals || [];
    const departures = data.data.departures || [];
    const direction = DEMAND_STATE.direction;

    // Get all unique time bins
    const timeBinsSet = new Set();
    arrivals.forEach(d => timeBinsSet.add(d.time_bin));
    departures.forEach(d => timeBinsSet.add(d.time_bin));
    const timeBins = Array.from(timeBinsSet).sort();

    // Store for drill-down
    DEMAND_STATE.timeBins = timeBins;

    // Format time labels
    const labels = timeBins.map(formatTimeLabel);

    // Build series based on direction
    const series = [];

    if (direction === 'arr' || direction === 'both') {
        // Build arrival series by status
        const arrivalsByBin = {};
        arrivals.forEach(d => { arrivalsByBin[d.time_bin] = d.breakdown; });

        series.push(
            buildStatusSeries('Active (Arr)', timeBins, arrivalsByBin, 'active', 'arrivals'),
            buildStatusSeries('Scheduled (Arr)', timeBins, arrivalsByBin, 'scheduled', 'arrivals'),
            buildStatusSeries('Proposed (Arr)', timeBins, arrivalsByBin, 'proposed', 'arrivals'),
            buildStatusSeries('Arrived', timeBins, arrivalsByBin, 'arrived', 'arrivals')
        );
    }

    if (direction === 'dep' || direction === 'both') {
        // Build departure series by status
        const departuresByBin = {};
        departures.forEach(d => { departuresByBin[d.time_bin] = d.breakdown; });

        series.push(
            buildStatusSeries('Active (Dep)', timeBins, departuresByBin, 'active', 'departures'),
            buildStatusSeries('Scheduled (Dep)', timeBins, departuresByBin, 'scheduled', 'departures'),
            buildStatusSeries('Proposed (Dep)', timeBins, departuresByBin, 'proposed', 'departures'),
            buildStatusSeries('Departed', timeBins, departuresByBin, 'departed', 'departures')
        );
    }

    // Chart options
    const option = {
        title: {
            text: `${data.airport} Demand`,
            subtext: `${DEMAND_STATE.granularity === '15min' ? '15-Minute' : 'Hourly'} | ${getDirectionLabel()}`,
            left: 'center'
        },
        tooltip: {
            trigger: 'axis',
            axisPointer: {
                type: 'shadow'
            },
            formatter: function(params) {
                let tooltip = `<strong>${params[0].axisValueLabel}</strong><br/>`;
                let total = 0;
                params.forEach(p => {
                    if (p.value > 0) {
                        tooltip += `${p.marker} ${p.seriesName}: ${p.value}<br/>`;
                        total += p.value;
                    }
                });
                tooltip += `<strong>Total: ${total}</strong>`;
                return tooltip;
            }
        },
        legend: {
            bottom: 10,
            left: 'center',
            type: 'scroll'
        },
        grid: {
            left: '3%',
            right: '4%',
            bottom: 80,
            top: 80,
            containLabel: true
        },
        xAxis: {
            type: 'category',
            data: labels,
            axisLabel: {
                rotate: 45,
                interval: 0
            }
        },
        yAxis: {
            type: 'value',
            name: 'Flights',
            minInterval: 1
        },
        series: series
    };

    DEMAND_STATE.chart.setOption(option, true);

    // Add click handler for drill-down
    DEMAND_STATE.chart.off('click'); // Remove previous handler
    DEMAND_STATE.chart.on('click', function(params) {
        if (params.componentType === 'series') {
            const timeBin = DEMAND_STATE.timeBins[params.dataIndex];
            if (timeBin) {
                showFlightDetails(timeBin);
            }
        }
    });
}

/**
 * Render chart with origin ARTCC breakdown
 */
function renderOriginChart() {
    if (!DEMAND_STATE.chart) {
        console.error('Chart not initialized');
        return;
    }

    const originBreakdown = DEMAND_STATE.originBreakdown || {};
    const data = DEMAND_STATE.lastDemandData;

    if (!data) {
        console.error('No demand data available');
        return;
    }

    // Get time bins from arrivals data
    const arrivals = data.data.arrivals || [];
    const timeBinsSet = new Set();
    arrivals.forEach(d => timeBinsSet.add(d.time_bin));
    const timeBins = Array.from(timeBinsSet).sort();

    // Store for drill-down
    DEMAND_STATE.timeBins = timeBins;

    // Collect all unique ARTCCs
    const allARTCCs = new Set();
    for (const bin in originBreakdown) {
        const artccData = originBreakdown[bin];
        if (Array.isArray(artccData)) {
            artccData.forEach(item => allARTCCs.add(item.artcc));
        }
    }
    const artccList = Array.from(allARTCCs).sort();

    // Format time labels
    const labels = timeBins.map(formatTimeLabel);

    // Build series for each ARTCC
    const series = artccList.map(artcc => {
        const seriesData = timeBins.map(bin => {
            const binData = originBreakdown[bin] || [];
            const artccEntry = binData.find(item => item.artcc === artcc);
            return artccEntry ? artccEntry.count : 0;
        });

        return {
            name: artcc,
            type: 'bar',
            stack: 'origin',
            emphasis: {
                focus: 'series'
            },
            itemStyle: {
                color: getARTCCColor(artcc)
            },
            data: seriesData
        };
    });

    // Chart options
    const option = {
        title: {
            text: `${data.airport} Arrivals by Origin ARTCC`,
            subtext: `${DEMAND_STATE.granularity === '15min' ? '15-Minute' : 'Hourly'} | Origin Breakdown`,
            left: 'center'
        },
        tooltip: {
            trigger: 'axis',
            axisPointer: {
                type: 'shadow'
            },
            formatter: function(params) {
                let tooltip = `<strong>${params[0].axisValueLabel}</strong><br/>`;
                let total = 0;
                // Sort by value descending
                const sorted = [...params].sort((a, b) => b.value - a.value);
                sorted.forEach(p => {
                    if (p.value > 0) {
                        tooltip += `${p.marker} ${p.seriesName}: ${p.value}<br/>`;
                        total += p.value;
                    }
                });
                tooltip += `<strong>Total: ${total}</strong>`;
                return tooltip;
            }
        },
        legend: {
            bottom: 10,
            left: 'center',
            type: 'scroll'
        },
        grid: {
            left: '3%',
            right: '4%',
            bottom: 80,
            top: 80,
            containLabel: true
        },
        xAxis: {
            type: 'category',
            data: labels,
            axisLabel: {
                rotate: 45,
                interval: 0
            }
        },
        yAxis: {
            type: 'value',
            name: 'Arrivals',
            minInterval: 1
        },
        series: series
    };

    DEMAND_STATE.chart.setOption(option, true);

    // Add click handler for drill-down
    DEMAND_STATE.chart.off('click');
    DEMAND_STATE.chart.on('click', function(params) {
        if (params.componentType === 'series') {
            const timeBin = DEMAND_STATE.timeBins[params.dataIndex];
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
 * Build a series for a specific status
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
        color = adjustColor(color, 0.2);
    }

    return {
        name: name,
        type: 'bar',
        stack: type,
        emphasis: {
            focus: 'series'
        },
        itemStyle: {
            color: color
        },
        data: data
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
 * Format time bin for display
 */
function formatTimeLabel(isoString) {
    const date = new Date(isoString);
    const hours = date.getUTCHours().toString().padStart(2, '0');
    const minutes = date.getUTCMinutes().toString().padStart(2, '0');
    return `${hours}:${minutes}Z`;
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
