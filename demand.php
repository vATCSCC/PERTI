<?php

include("sessions/handler.php");
if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

include("load/config.php");
include("load/connect.php");

?>
<!DOCTYPE html>
<html lang="en">
<head>

    <?php include("load/header.php"); ?>

    <title>Demand Visualization | PERTI</title>

    <!-- Info Bar Shared Styles -->
    <link rel="stylesheet" href="assets/css/info-bar.css">

    <!-- ECharts CDN -->
    <script src="https://cdn.jsdelivr.net/npm/echarts@5.4.3/dist/echarts.min.js"></script>

    <style>
        /* Label styling consistent with other PERTI pages */
        .demand-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 600;
            color: #333;
        }

        .demand-section-title {
            font-weight: 600;
            font-size: 0.9rem;
            text-transform: uppercase;
        }

        /* Chart container */
        .demand-chart-container {
            width: 100%;
            height: 450px;
            min-height: 350px;
        }

        /* Status indicators */
        .demand-status-indicator {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .demand-status-active {
            background-color: #28a745;
            color: #fff;
        }

        .demand-status-paused {
            background-color: #ffc107;
            color: #333;
        }

        /* Granularity and direction toggles - Bootstrap 4 */
        .demand-toggle-group .btn {
            font-size: 0.8rem;
            padding: 5px 15px;
        }

        .demand-toggle-group .btn.active {
            background-color: #6c757d !important;
            border-color: #6c757d !important;
            color: #fff !important;
        }

        /* Hide radio buttons inside labels */
        .demand-toggle-group input[type="radio"] {
            position: absolute;
            clip: rect(0,0,0,0);
            pointer-events: none;
        }

        /* Legend items */
        .demand-legend-item {
            display: inline-flex;
            align-items: center;
            margin-right: 15px;
            font-size: 0.75rem;
        }

        .demand-legend-color {
            display: inline-block;
            width: 14px;
            height: 14px;
            border-radius: 2px;
            margin-right: 5px;
        }

        /* Card header fixes */
        .card-header .demand-section-title {
            color: #333;
        }

        .card-header.bg-primary .demand-section-title,
        .card-header.bg-secondary .demand-section-title,
        .card-header.bg-info .demand-section-title {
            color: #fff;
        }

        /* Filter card */
        .demand-filter-card .card-body {
            padding: 15px;
        }

        /* Empty state */
        .demand-empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }

        .demand-empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
        }

        .demand-empty-state h5 {
            font-weight: 600;
            margin-bottom: 10px;
        }
    </style>

</head>
<body>

<?php include("load/nav.php"); ?>

<!-- Hero Section -->
<section class="d-flex align-items-center position-relative min-vh-25 py-4" data-jarallax data-speed="0.3" style="pointer-events: all;">
    <div class="container-fluid pt-2 pb-4 py-lg-5">
        <img class="jarallax-img" src="assets/img/jumbotron/main.png" alt="" style="opacity: 50%;">

        <center>
            <h1>Demand Visualization</h1>
            <h4 class="text-white hvr-bob pl-1">
                <a href="#demand_section" style="text-decoration: none; color: #fff;">
                    <i class="fas fa-chevron-down text-danger"></i>
                    Airport Arrival &amp; Departure Analysis
                </a>
            </h4>
        </center>
    </div>
</section>

<div class="container-fluid mt-3 mb-5" id="demand_section">
    <!-- Info Bar: UTC Clock, Airport Stats -->
    <div class="perti-info-bar mb-3">
        <div class="row d-flex flex-wrap align-items-stretch" style="gap: 8px; margin: 0 -4px;">
            <!-- Current Time (UTC) -->
            <div class="col-auto px-1">
                <div class="card shadow-sm perti-info-card perti-card-utc h-100">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <div class="perti-info-label">Current UTC</div>
                            <div id="demand_utc_clock" class="perti-clock-display perti-clock-display-lg">--:--:--</div>
                        </div>
                        <div class="ml-3">
                            <i class="far fa-clock fa-lg text-primary"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Selected Airport -->
            <div class="col-auto px-1">
                <div class="card shadow-sm perti-info-card perti-card-global h-100">
                    <div class="card-body">
                        <div class="perti-info-label mb-1">Selected Airport</div>
                        <div class="d-flex align-items-center">
                            <span id="demand_selected_airport" class="perti-clock-display perti-clock-display-lg text-info">----</span>
                            <span id="demand_airport_name" class="ml-2 text-muted small" style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">Select an airport</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Arrival Stats -->
            <div class="col-auto px-1">
                <div class="card shadow-sm perti-info-card perti-card-domestic h-100">
                    <div class="card-body">
                        <div class="perti-info-label mb-1">
                            <i class="fas fa-plane-arrival mr-1"></i> Arrivals
                            <span id="demand_arr_total" class="badge badge-success badge-total ml-1">0</span>
                        </div>
                        <div class="perti-stat-grid">
                            <div class="perti-stat-item">
                                <div class="perti-stat-category">Active</div>
                                <div id="demand_arr_active" class="perti-stat-value text-danger">0</div>
                            </div>
                            <div class="perti-stat-item">
                                <div class="perti-stat-category">Sched</div>
                                <div id="demand_arr_scheduled" class="perti-stat-value text-success">0</div>
                            </div>
                            <div class="perti-stat-item">
                                <div class="perti-stat-category">Prop</div>
                                <div id="demand_arr_proposed" class="perti-stat-value text-primary">0</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Departure Stats -->
            <div class="col-auto px-1">
                <div class="card shadow-sm perti-info-card h-100" style="border-color: #fd7e14; background: linear-gradient(135deg, #ffffff 0%, #fff8f0 100%);">
                    <div class="card-body">
                        <div class="perti-info-label mb-1" style="color: #d35400;">
                            <i class="fas fa-plane-departure mr-1"></i> Departures
                            <span id="demand_dep_total" class="badge badge-warning text-dark badge-total ml-1">0</span>
                        </div>
                        <div class="perti-stat-grid">
                            <div class="perti-stat-item">
                                <div class="perti-stat-category">Active</div>
                                <div id="demand_dep_active" class="perti-stat-value text-danger">0</div>
                            </div>
                            <div class="perti-stat-item">
                                <div class="perti-stat-category">Sched</div>
                                <div id="demand_dep_scheduled" class="perti-stat-value text-success">0</div>
                            </div>
                            <div class="perti-stat-item">
                                <div class="perti-stat-category">Prop</div>
                                <div id="demand_dep_proposed" class="perti-stat-value text-primary">0</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Auto-Refresh Status -->
            <div class="col-auto px-1">
                <div class="card shadow-sm perti-info-card h-100" style="border-color: #6c757d;">
                    <div class="card-body d-flex align-items-center">
                        <div>
                            <div class="perti-info-label mb-1">Auto-Refresh</div>
                            <div class="d-flex align-items-center">
                                <div class="custom-control custom-switch">
                                    <input type="checkbox" class="custom-control-input" id="demand_auto_refresh" checked>
                                    <label class="custom-control-label" for="demand_auto_refresh"></label>
                                </div>
                                <span class="demand-status-indicator demand-status-active ml-2" id="refresh_status">15s</span>
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-primary ml-3" id="demand_refresh_btn" title="Manual Refresh">
                            <i class="fas fa-sync-alt"></i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Spacer -->
            <div class="col"></div>
        </div>
    </div>

    <div class="row">
        <!-- Left: Filters -->
        <div class="col-lg-3 mb-4">
            <div class="card shadow-sm demand-filter-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span class="demand-section-title">
                        <i class="fas fa-filter mr-1 text-primary"></i> Filters
                    </span>
                </div>

                <div class="card-body">
                    <!-- Airport Selection -->
                    <div class="form-group">
                        <label class="demand-label mb-1" for="demand_airport">Airport</label>
                        <select class="form-control form-control-sm" id="demand_airport">
                            <option value="">-- Select Airport --</option>
                        </select>
                    </div>

                    <!-- Category Filter -->
                    <div class="form-group">
                        <label class="demand-label mb-1" for="demand_category">Category</label>
                        <select class="form-control form-control-sm" id="demand_category">
                            <option value="all">All Airports</option>
                            <option value="core30">Core30</option>
                            <option value="oep35">OEP35</option>
                            <option value="aspm77">ASPM77</option>
                        </select>
                    </div>

                    <!-- ARTCC Filter -->
                    <div class="form-group">
                        <label class="demand-label mb-1" for="demand_artcc">ARTCC</label>
                        <select class="form-control form-control-sm" id="demand_artcc">
                            <option value="">All ARTCCs</option>
                        </select>
                    </div>

                    <!-- Tier Filter -->
                    <div class="form-group">
                        <label class="demand-label mb-1" for="demand_tier">Tier</label>
                        <select class="form-control form-control-sm" id="demand_tier">
                            <option value="all">All Tiers</option>
                        </select>
                    </div>

                    <hr>

                    <!-- Time Range -->
                    <div class="form-group">
                        <label class="demand-label mb-1" for="demand_time_range">Time Range</label>
                        <select class="form-control form-control-sm" id="demand_time_range">
                            <!-- Populated by JavaScript -->
                        </select>
                    </div>

                    <!-- Granularity Toggle -->
                    <div class="form-group">
                        <label class="demand-label mb-1">Granularity</label>
                        <div class="btn-group btn-group-toggle btn-group-sm demand-toggle-group w-100" data-toggle="buttons" role="group">
                            <label class="btn btn-outline-secondary">
                                <input type="radio" name="demand_granularity" id="granularity_15min" value="15min" autocomplete="off"> 15-min
                            </label>
                            <label class="btn btn-outline-secondary active">
                                <input type="radio" name="demand_granularity" id="granularity_hourly" value="hourly" autocomplete="off" checked> Hourly
                            </label>
                        </div>
                    </div>

                    <!-- Direction Toggle -->
                    <div class="form-group mb-0">
                        <label class="demand-label mb-1">Direction</label>
                        <div class="btn-group btn-group-toggle btn-group-sm demand-toggle-group w-100" data-toggle="buttons" role="group">
                            <label class="btn btn-outline-secondary active">
                                <input type="radio" name="demand_direction" id="direction_both" value="both" autocomplete="off" checked> Both
                            </label>
                            <label class="btn btn-outline-secondary">
                                <input type="radio" name="demand_direction" id="direction_arr" value="arr" autocomplete="off"> Arr
                            </label>
                            <label class="btn btn-outline-secondary">
                                <input type="radio" name="demand_direction" id="direction_dep" value="dep" autocomplete="off"> Dep
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Legend Card -->
            <div class="card shadow-sm mt-3">
                <div class="card-header">
                    <span class="demand-section-title">
                        <i class="fas fa-palette mr-1 text-info"></i> Legend
                    </span>
                </div>
                <div class="card-body py-2">
                    <div class="demand-legend-item">
                        <span class="demand-legend-color" style="background-color: #CC0000;"></span>
                        Active (Airborne)
                    </div>
                    <div class="demand-legend-item">
                        <span class="demand-legend-color" style="background-color: #32CD32;"></span>
                        Scheduled
                    </div>
                    <div class="demand-legend-item">
                        <span class="demand-legend-color" style="background-color: #4169E1;"></span>
                        Proposed
                    </div>
                    <div class="demand-legend-item">
                        <span class="demand-legend-color" style="background-color: #333333;"></span>
                        Arrived/Departed
                    </div>
                </div>
            </div>
        </div>

        <!-- Right: Chart -->
        <div class="col-lg-9 mb-4">
            <div class="card shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span class="demand-section-title">
                        <i class="fas fa-chart-bar mr-1 text-primary"></i> Demand Chart
                    </span>
                    <div class="d-flex align-items-center">
                        <!-- Chart View Toggle -->
                        <div class="btn-group btn-group-toggle btn-group-sm demand-toggle-group mr-3" data-toggle="buttons" role="group">
                            <label class="btn btn-outline-secondary active" title="Show by flight status">
                                <input type="radio" name="demand_chart_view" id="view_status" value="status" autocomplete="off" checked> Status
                            </label>
                            <label class="btn btn-outline-secondary" title="Show arrivals by origin ARTCC">
                                <input type="radio" name="demand_chart_view" id="view_origin" value="origin" autocomplete="off"> Origin
                            </label>
                        </div>
                        <span class="text-muted small" id="demand_last_update">--</span>
                    </div>
                </div>
                <div class="card-body p-2">
                    <!-- Empty State (shown when no airport selected) -->
                    <div id="demand_empty_state" class="demand-empty-state">
                        <i class="fas fa-chart-bar"></i>
                        <h5>No Airport Selected</h5>
                        <p class="text-muted">Select an airport from the filter panel to view demand data.</p>
                    </div>

                    <!-- Chart Container (hidden initially) -->
                    <div id="demand_chart" class="demand-chart-container" style="display: none;"></div>
                </div>
            </div>

            <!-- Flight List Card (placeholder for future) -->
            <div class="card shadow-sm mt-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span class="demand-section-title">
                        <i class="fas fa-list mr-1 text-info"></i> Flight Summary
                        <span class="badge badge-secondary ml-2" id="demand_flight_count">0 flights</span>
                    </span>
                    <button class="btn btn-sm btn-outline-secondary" id="demand_toggle_flights" type="button" title="Toggle flight details">
                        <i class="fas fa-chevron-down"></i>
                    </button>
                </div>
                <div class="card-body p-2" id="demand_flight_summary" style="display: none;">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card border-light mb-2">
                                <div class="card-header py-1 px-2 bg-light">
                                    <span class="demand-label">Top Origin ARTCCs</span>
                                </div>
                                <div class="card-body p-1">
                                    <table class="table table-sm table-hover mb-0" style="font-size: 0.8rem;">
                                        <tbody id="demand_top_origins"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card border-light mb-2">
                                <div class="card-header py-1 px-2 bg-light">
                                    <span class="demand-label">Top Carriers</span>
                                </div>
                                <div class="card-body p-1">
                                    <table class="table table-sm table-hover mb-0" style="font-size: 0.8rem;">
                                        <tbody id="demand_top_carriers"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include("load/footer.php"); ?>

<!-- Demand JavaScript -->
<script src="assets/js/demand.js"></script>

<script>
    // Update refresh status indicator when toggle changes
    $('#demand_auto_refresh').on('change', function() {
        const statusEl = $('#refresh_status');
        if ($(this).is(':checked')) {
            statusEl.text('15s').removeClass('demand-status-paused').addClass('demand-status-active');
        } else {
            statusEl.text('Paused').removeClass('demand-status-active').addClass('demand-status-paused');
        }
    });

    // Toggle flight summary visibility
    $('#demand_toggle_flights').on('click', function() {
        const $summary = $('#demand_flight_summary');
        const $icon = $(this).find('i');
        $summary.slideToggle(200);
        $icon.toggleClass('fa-chevron-down fa-chevron-up');
    });

    // UTC Clock update
    function updateDemandClock() {
        const now = new Date();
        const utc = now.toISOString().substring(11, 19);
        $('#demand_utc_clock').text(utc);
    }
    setInterval(updateDemandClock, 1000);
    updateDemandClock();
</script>

</body>
</html>
