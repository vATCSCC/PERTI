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

    <?php $page_title = "vATCSCC Demand"; include("load/header.php"); ?>

    <!-- Info Bar Shared Styles -->
    <link rel="stylesheet" href="assets/css/info-bar.css">

    <!-- ECharts CDN -->
    <script src="https://cdn.jsdelivr.net/npm/echarts@5.4.3/dist/echarts.min.js"></script>

    <!-- Shared Phase Color Configuration -->
    <script src="assets/js/config/phase-colors.js"></script>
    <!-- Rate Line Color Configuration -->
    <script src="assets/js/config/rate-colors.js"></script>

    <style>
        /* ═══════════════════════════════════════════════════════════════════════════
           TBFM/FSM STYLE - Airport Demand Visualization
           Based on FAA Time Based Flow Management & Flight Schedule Monitor
           ═══════════════════════════════════════════════════════════════════════════ */

        /* TBFM/FSM Label styling */
        .demand-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 600;
            color: #333;
            font-family: "Segoe UI", -apple-system, sans-serif;
        }

        .demand-section-title {
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            font-family: "Segoe UI", -apple-system, sans-serif;
        }

        /* TBFM/FSM Chart container */
        .demand-chart-container {
            width: 100%;
            height: 480px;
            min-height: 380px;
            background: #ffffff;
            border: 1px solid #d0d0d0;
        }

        /* TBFM/FSM Status indicators - high contrast */
        .demand-status-indicator {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 3px;
            font-size: 0.75rem;
            font-weight: 600;
            font-family: "Inconsolata", "SF Mono", monospace;
        }

        .demand-status-active {
            background-color: #28a745;
            color: #fff;
        }

        .demand-status-paused {
            background-color: #ffc107;
            color: #333;
        }

        /* TBFM/FSM Toggle buttons */
        .demand-toggle-group .btn {
            font-size: 0.78rem;
            padding: 5px 14px;
            font-weight: 500;
            border-radius: 3px;
        }

        .demand-toggle-group .btn.active {
            background-color: #2c3e50 !important;
            border-color: #2c3e50 !important;
            color: #fff !important;
        }

        .demand-toggle-group .btn:not(.active) {
            background-color: #f8f9fa;
            border-color: #ced4da;
            color: #495057;
        }

        .demand-toggle-group .btn:hover:not(.active) {
            background-color: #e9ecef;
            border-color: #adb5bd;
        }

        /* Hide radio buttons inside labels */
        .demand-toggle-group input[type="radio"] {
            position: absolute;
            clip: rect(0,0,0,0);
            pointer-events: none;
        }

        /* TBFM/FSM Legend items */
        .demand-legend-item {
            display: inline-flex;
            align-items: center;
            margin-right: 15px;
            margin-bottom: 6px;
            font-size: 0.78rem;
            font-family: "Segoe UI", sans-serif;
        }

        .demand-legend-color {
            display: inline-block;
            width: 16px;
            height: 12px;
            border-radius: 2px;
            margin-right: 6px;
            border: 1px solid rgba(0,0,0,0.15);
        }

        /* TBFM/FSM Card headers - dark theme */
        .card-header .demand-section-title {
            color: #333;
        }

        .tbfm-card-header {
            background: linear-gradient(180deg, #3a4a5c 0%, #2c3e50 100%);
            border-bottom: 2px solid #1a252f;
            padding: 10px 15px;
        }

        .tbfm-card-header .demand-section-title {
            color: #ffffff;
            text-shadow: 0 1px 2px rgba(0,0,0,0.2);
        }

        .tbfm-card-header .demand-section-title i {
            color: #5dade2;
        }

        /* TBFM/FSM Filter card */
        .demand-filter-card {
            border: 1px solid #bdc3c7;
            border-radius: 4px;
        }

        .demand-filter-card .card-body {
            padding: 15px;
            background: #fafbfc;
        }

        .demand-filter-card .form-control {
            font-size: 0.85rem;
            border-color: #ced4da;
        }

        .demand-filter-card .form-control:focus {
            border-color: #3498db;
            box-shadow: 0 0 0 0.15rem rgba(52, 152, 219, 0.25);
        }

        /* TBFM/FSM Empty state */
        .demand-empty-state {
            text-align: center;
            padding: 80px 20px;
            color: #6c757d;
            background: linear-gradient(180deg, #f8f9fa 0%, #ffffff 100%);
        }

        .demand-empty-state i {
            font-size: 56px;
            margin-bottom: 20px;
            opacity: 0.4;
            color: #2c3e50;
        }

        .demand-empty-state h5 {
            font-weight: 600;
            margin-bottom: 10px;
            color: #2c3e50;
        }

        /* TBFM/FSM Chart card */
        .tbfm-chart-card {
            border: 1px solid #bdc3c7;
            border-radius: 4px;
        }

        .tbfm-chart-card .card-body {
            padding: 8px;
            background: #ffffff;
        }

        /* TBFM/FSM Info bar enhancements */
        .tbfm-stat-value {
            font-family: "Inconsolata", "SF Mono", Consolas, monospace;
            font-weight: 600;
            font-size: 1rem;
        }

        .tbfm-clock {
            font-family: "Inconsolata", "SF Mono", Consolas, monospace;
            font-weight: 500;
            font-size: 1.15rem;
            letter-spacing: 0.08em;
        }

        /* TBFM/FSM Flight summary table */
        .tbfm-summary-table {
            font-size: 0.82rem;
        }

        .tbfm-summary-table th {
            background: #ecf0f1;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.7rem;
            letter-spacing: 0.04em;
            color: #2c3e50;
            padding: 6px 8px;
        }

        .tbfm-summary-table td {
            padding: 5px 8px;
            vertical-align: middle;
        }

        .tbfm-summary-table tr:hover {
            background-color: #f5f6f7;
        }

        /* TBFM/FSM Badge styles */
        .tbfm-badge-arrivals {
            background-color: #27ae60;
            color: #fff;
        }

        .tbfm-badge-departures {
            background-color: #e67e22;
            color: #fff;
        }

        .tbfm-badge-active {
            background-color: #c0392b;
            color: #fff;
        }

        /* TBFM/FSM Toggle buttons in dark header */
        .tbfm-card-header .demand-toggle-group .btn {
            font-size: 0.75rem;
            padding: 4px 12px;
            border-radius: 3px;
        }

        .tbfm-card-header .demand-toggle-group .btn-outline-light {
            border-color: rgba(255,255,255,0.5);
            color: rgba(255,255,255,0.9);
        }

        .tbfm-card-header .demand-toggle-group .btn-outline-light:hover {
            background-color: rgba(255,255,255,0.15);
            border-color: rgba(255,255,255,0.7);
        }

        .tbfm-card-header .demand-toggle-group .btn-outline-light.active {
            background-color: #5dade2 !important;
            border-color: #5dade2 !important;
            color: #fff !important;
        }

        /* TBFM/FSM Info card stat values */
        .perti-stat-value {
            font-family: "Inconsolata", "SF Mono", Consolas, monospace;
            font-weight: 600;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .demand-chart-container {
                height: 350px;
                min-height: 300px;
            }
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
            <!-- Current Time (UTC) - TBFM Style -->
            <div class="col-auto px-1">
                <div class="card shadow-sm perti-info-card perti-card-utc h-100">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <div class="perti-info-label">Current UTC</div>
                            <div id="demand_utc_clock" class="tbfm-clock text-primary">--:--:--</div>
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

            <!-- Rate Info (Config + Weather + AAR/ADR) -->
            <div class="col-auto px-1">
                <div class="card shadow-sm perti-info-card h-100" style="border-color: #6366f1; background: linear-gradient(135deg, #ffffff 0%, #f5f3ff 100%);">
                    <div class="card-body">
                        <div class="perti-info-label mb-1" style="color: #4f46e5;">
                            <i class="fas fa-tachometer-alt mr-1"></i> Rates
                            <span id="rate_weather_category" class="badge ml-1" style="background-color: #22c55e; color: #fff; font-size: 0.7rem;">--</span>
                        </div>
                        <div class="perti-stat-grid">
                            <div class="perti-stat-item">
                                <div class="perti-stat-category">Config</div>
                                <div id="rate_config_name" class="perti-stat-value text-dark" style="font-size: 0.8rem; max-width: 80px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">--</div>
                            </div>
                            <div class="perti-stat-item">
                                <div class="perti-stat-category">AAR/ADR</div>
                                <div id="rate_display" class="perti-stat-value" style="color: #4f46e5;">--/--</div>
                            </div>
                            <div class="perti-stat-item">
                                <div class="perti-stat-category">&nbsp;</div>
                                <div id="rate_match_score" class="perti-stat-value text-muted" style="font-size: 0.7rem;"></div>
                            </div>
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
                <div class="card-header tbfm-card-header d-flex justify-content-between align-items-center">
                    <span class="demand-section-title">
                        <i class="fas fa-filter mr-1"></i> Filters
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
                                <input type="radio" name="demand_granularity" id="granularity_15min" value="15min" autocomplete="off"> 15
                            </label>
                            <label class="btn btn-outline-secondary">
                                <input type="radio" name="demand_granularity" id="granularity_30min" value="30min" autocomplete="off"> 30
                            </label>
                            <label class="btn btn-outline-secondary active">
                                <input type="radio" name="demand_granularity" id="granularity_hourly" value="hourly" autocomplete="off" checked> 60
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

            <!-- Legend Card - Individual phase colors -->
            <div class="card shadow-sm mt-3">
                <div class="card-header tbfm-card-header">
                    <span class="demand-section-title">
                        <i class="fas fa-palette mr-1"></i> Legend
                    </span>
                </div>
                <div class="card-body py-3" id="phase-legend-container">
                    <!-- Legend items will be generated from phase-colors.js -->
                    <script>
                        // Generate legend from shared phase config
                        document.addEventListener('DOMContentLoaded', function() {
                            const container = document.getElementById('phase-legend-container');
                            if (typeof PHASE_ORDER !== 'undefined') {
                                let html = '';
                                PHASE_ORDER.forEach(phase => {
                                    const color = PHASE_COLORS[phase] || '#999';
                                    const label = PHASE_LABELS[phase] || phase;
                                    const desc = PHASE_DESCRIPTIONS[phase] || '';
                                    html += `<div class="demand-legend-item">
                                        <span class="demand-legend-color" style="background-color: ${color};"></span>
                                        <strong>${label}</strong>
                                        ${desc ? `<small class="text-muted ml-1">(${desc})</small>` : ''}
                                    </div>`;
                                });
                                html += `<hr class="my-2">
                                    <small class="text-muted d-block mt-1" style="font-size: 0.7rem;">
                                        <i class="fas fa-info-circle mr-1"></i>
                                        Current phase of flight at query time
                                    </small>`;
                                container.innerHTML = html;
                            }
                        });
                    </script>
                    <!-- Fallback if JS doesn't load -->
                    <noscript>
                        <div class="demand-legend-item"><span class="demand-legend-color" style="background-color: #1a1a1a;"></span><strong>Arrived</strong></div>
                        <div class="demand-legend-item"><span class="demand-legend-color" style="background-color: #f97316;"></span><strong>Disconnected</strong></div>
                        <div class="demand-legend-item"><span class="demand-legend-color" style="background-color: #991b1b;"></span><strong>Descending</strong></div>
                        <div class="demand-legend-item"><span class="demand-legend-color" style="background-color: #dc2626;"></span><strong>Enroute</strong></div>
                        <div class="demand-legend-item"><span class="demand-legend-color" style="background-color: #f87171;"></span><strong>Departed</strong></div>
                        <div class="demand-legend-item"><span class="demand-legend-color" style="background-color: #22c55e;"></span><strong>Taxiing</strong></div>
                        <div class="demand-legend-item"><span class="demand-legend-color" style="background-color: #06b6d4;"></span><strong>Prefile</strong></div>
                        <div class="demand-legend-item"><span class="demand-legend-color" style="background-color: #eab308;"></span><strong>Unknown</strong></div>
                    </noscript>
                </div>
            </div>
        </div>

        <!-- Right: Chart -->
        <div class="col-lg-9 mb-4">
            <div class="card shadow-sm tbfm-chart-card">
                <div class="card-header tbfm-card-header d-flex justify-content-between align-items-center">
                    <span class="demand-section-title">
                        <i class="fas fa-chart-bar mr-1"></i> Demand Chart
                    </span>
                    <div class="d-flex align-items-center">
                        <!-- Chart View Toggle -->
                        <div class="btn-group btn-group-toggle btn-group-sm demand-toggle-group mr-3" data-toggle="buttons" role="group">
                            <label class="btn btn-outline-light active" title="Show by flight status">
                                <input type="radio" name="demand_chart_view" id="view_status" value="status" autocomplete="off" checked> Status
                            </label>
                            <label class="btn btn-outline-light" title="Show arrivals by origin ARTCC">
                                <input type="radio" name="demand_chart_view" id="view_origin" value="origin" autocomplete="off"> Origin
                            </label>
                        </div>
                        <span class="text-light small" id="demand_last_update" style="opacity: 0.8;">--</span>
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

            <!-- Flight Summary Card - TBFM/FSM Style -->
            <div class="card shadow-sm mt-3 tbfm-chart-card">
                <div class="card-header tbfm-card-header d-flex justify-content-between align-items-center">
                    <span class="demand-section-title">
                        <i class="fas fa-list mr-1"></i> Flight Summary
                        <span class="badge badge-light ml-2" id="demand_flight_count" style="color: #2c3e50;">0 flights</span>
                    </span>
                    <button class="btn btn-sm btn-outline-light" id="demand_toggle_flights" type="button" title="Toggle flight details">
                        <i class="fas fa-chevron-down"></i>
                    </button>
                </div>
                <div class="card-body p-2" id="demand_flight_summary" style="display: none;">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card border mb-2" style="border-color: #bdc3c7;">
                                <div class="card-header py-2 px-3" style="background: #ecf0f1; border-bottom: 1px solid #bdc3c7;">
                                    <span class="demand-label" style="color: #2c3e50;">
                                        <i class="fas fa-map-marker-alt mr-1 text-danger"></i> Top Origin ARTCCs
                                    </span>
                                </div>
                                <div class="card-body p-2">
                                    <table class="table table-sm table-hover mb-0 tbfm-summary-table">
                                        <tbody id="demand_top_origins"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card border mb-2" style="border-color: #bdc3c7;">
                                <div class="card-header py-2 px-3" style="background: #ecf0f1; border-bottom: 1px solid #bdc3c7;">
                                    <span class="demand-label" style="color: #2c3e50;">
                                        <i class="fas fa-plane mr-1 text-primary"></i> Top Carriers
                                    </span>
                                </div>
                                <div class="card-body p-2">
                                    <table class="table table-sm table-hover mb-0 tbfm-summary-table">
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
