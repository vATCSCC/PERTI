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
    lastUpdate: null
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

    let url = `api/demand/airports.php?category=${category}`;
    if (artcc) {
        url += `&artcc=${artcc}`;
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
        const tiers = ARTCC_TIERS.byFacility[artcc];
        if (tiers.internal) select.append('<option value="internal">Internal</option>');
        if (tiers['1stTier']) select.append('<option value="1stTier">1st Tier</option>');
        if (tiers['2ndTier']) select.append('<option value="2ndTier">2nd Tier</option>');
    }
}

/**
 * Show prompt to select an airport
 */
function showSelectAirportPrompt() {
    if (DEMAND_STATE.chart) {
        DEMAND_STATE.chart.clear();
        DEMAND_STATE.chart.setOption({
            title: {
                text: 'Select an airport to view demand',
                left: 'center',
                top: 'middle',
                textStyle: {
                    color: '#999',
                    fontSize: 18
                }
            }
        });
    }
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

    $.getJSON(`api/demand/airport.php?${params.toString()}`)
        .done(function(response) {
            if (response.success) {
                DEMAND_STATE.lastUpdate = new Date();
                renderChart(response);
                updateLastUpdateDisplay(response.last_adl_update);
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
    if (DEMAND_STATE.chart) {
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

// Initialize when document is ready
$(document).ready(function() {
    initDemand();
});
