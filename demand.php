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
                        <div class="perti-info-label mb-1 d-flex align-items-center" style="color: #4f46e5;">
                            <i class="fas fa-tachometer-alt mr-1"></i> Rates
                            <span id="rate_weather_category" class="badge ml-1" style="background-color: #22c55e; color: #fff; font-size: 0.7rem;">--</span>
                            <span id="rate_override_badge" class="badge badge-warning ml-1" style="display: none; font-size: 0.65rem;">OVERRIDE</span>
                            <button type="button" class="btn btn-link btn-sm ml-auto p-0" id="rate_override_btn" title="Set manual rate override" style="font-size: 0.7rem; color: #6366f1;">
                                <i class="fas fa-edit"></i>
                            </button>
                        </div>
                        <div class="d-flex align-items-start" style="gap: 12px;">
                            <div class="perti-stat-item">
                                <div class="perti-stat-category">Config</div>
                                <div id="rate_config_name" class="perti-stat-value text-dark" style="font-size: 0.75rem; cursor: help;" title="">--</div>
                            </div>
                            <div class="perti-stat-item">
                                <div class="perti-stat-category">AAR/ADR</div>
                                <div id="rate_display" class="perti-stat-value" style="color: #4f46e5; font-size: 1rem;">--/--</div>
                            </div>
                            <div class="perti-stat-item">
                                <div class="perti-stat-category">Source</div>
                                <div id="rate_source" class="perti-stat-value text-muted" style="font-size: 0.7rem;">--</div>
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

            <!-- Legend Card - Organized by flight stage -->
            <div class="card shadow-sm mt-3">
                <div class="card-header tbfm-card-header">
                    <span class="demand-section-title">
                        <i class="fas fa-palette mr-1"></i> Legend
                    </span>
                </div>
                <div class="card-body py-2" id="phase-legend-container">
                    <!-- Airborne Phases -->
                    <div class="legend-group mb-2">
                        <div class="legend-group-title text-muted small mb-1" style="font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.05em;">
                            <i class="fas fa-plane mr-1"></i> Airborne
                        </div>
                        <div class="d-flex flex-wrap" style="gap: 2px 10px;">
                            <div class="demand-legend-item"><span class="demand-legend-color" style="background-color: #f87171;"></span>Departed</div>
                            <div class="demand-legend-item"><span class="demand-legend-color" style="background-color: #dc2626;"></span>Enroute</div>
                            <div class="demand-legend-item"><span class="demand-legend-color" style="background-color: #991b1b;"></span>Descending</div>
                        </div>
                    </div>
                    <!-- Ground Phases -->
                    <div class="legend-group mb-2">
                        <div class="legend-group-title text-muted small mb-1" style="font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.05em;">
                            <i class="fas fa-road mr-1"></i> Ground
                        </div>
                        <div class="d-flex flex-wrap" style="gap: 2px 10px;">
                            <div class="demand-legend-item"><span class="demand-legend-color" style="background-color: #3b82f6;"></span>Prefile</div>
                            <div class="demand-legend-item"><span class="demand-legend-color" style="background-color: #22c55e;"></span>Taxiing</div>
                            <div class="demand-legend-item"><span class="demand-legend-color" style="background-color: #1a1a1a;"></span>Arrived</div>
                        </div>
                    </div>
                    <!-- Other -->
                    <div class="legend-group">
                        <div class="legend-group-title text-muted small mb-1" style="font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.05em;">
                            <i class="fas fa-question-circle mr-1"></i> Other
                        </div>
                        <div class="d-flex flex-wrap" style="gap: 2px 10px;">
                            <div class="demand-legend-item"><span class="demand-legend-color" style="background-color: #f97316;"></span>Disconnected</div>
                            <div class="demand-legend-item"><span class="demand-legend-color" style="background-color: #eab308;"></span>Unknown</div>
                        </div>
                    </div>
                    <hr class="my-2">
                    <!-- Rate Lines Legend -->
                    <div class="legend-group">
                        <div class="legend-group-title text-muted small mb-1" style="font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.05em;">
                            <i class="fas fa-minus mr-1"></i> Rate Lines
                        </div>
                        <div class="d-flex flex-wrap" style="gap: 2px 10px;">
                            <div class="demand-legend-item"><span style="display: inline-block; width: 16px; height: 2px; background: #fff; border: 1px solid #999; margin-right: 6px; vertical-align: middle;"></span><small>AAR (solid)</small></div>
                            <div class="demand-legend-item"><span style="display: inline-block; width: 16px; height: 2px; border-top: 2px dashed #888; margin-right: 6px; vertical-align: middle;"></span><small>ADR (dashed)</small></div>
                        </div>
                    </div>
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

    // Rate Override Modal Handler
    $('#rate_override_btn').on('click', function() {
        const airport = DEMAND_STATE.selectedAirport;
        if (!airport) {
            Swal.fire({
                icon: 'warning',
                title: 'No Airport Selected',
                text: 'Please select an airport first.',
                toast: true,
                position: 'bottom-right',
                timer: 3000,
                showConfirmButton: false
            });
            return;
        }

        const rateData = DEMAND_STATE.rateData || {};
        const currentAAR = rateData.rates?.vatsim_aar || '';
        const currentADR = rateData.rates?.vatsim_adr || '';
        const hasOverride = rateData.has_override || false;

        // Calculate default times (now to +4 hours)
        const now = new Date();
        const startDefault = now.toISOString().slice(0, 16); // YYYY-MM-DDTHH:MM
        const endDefault = new Date(now.getTime() + 4 * 60 * 60 * 1000).toISOString().slice(0, 16);

        Swal.fire({
            title: `<i class="fas fa-edit mr-2"></i> Rate Override: ${airport}`,
            html: `
                <div class="text-left">
                    ${hasOverride ? '<div class="alert alert-warning py-2 mb-3"><i class="fas fa-exclamation-triangle mr-1"></i> An override is already active. Creating a new one will replace it.</div>' : ''}
                    <div class="row">
                        <div class="col-6">
                            <div class="form-group">
                                <label class="small font-weight-bold">AAR (Arrival Rate)</label>
                                <input type="number" id="swal_aar" class="form-control form-control-sm" value="${currentAAR}" min="0" max="200" placeholder="e.g. 44">
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="form-group">
                                <label class="small font-weight-bold">ADR (Departure Rate)</label>
                                <input type="number" id="swal_adr" class="form-control form-control-sm" value="${currentADR}" min="0" max="200" placeholder="e.g. 48">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-6">
                            <div class="form-group">
                                <label class="small font-weight-bold">Start Time (UTC)</label>
                                <input type="datetime-local" id="swal_start" class="form-control form-control-sm" value="${startDefault}">
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="form-group">
                                <label class="small font-weight-bold">End Time (UTC)</label>
                                <input type="datetime-local" id="swal_end" class="form-control form-control-sm" value="${endDefault}">
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="small font-weight-bold">Reason (optional)</label>
                        <input type="text" id="swal_reason" class="form-control form-control-sm" placeholder="e.g. Event traffic, weather, construction">
                    </div>
                    <small class="text-muted">Override will take effect immediately and show on the chart.</small>
                </div>
            `,
            width: 500,
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-check mr-1"></i> Set Override',
            cancelButtonText: 'Cancel',
            showDenyButton: hasOverride,
            denyButtonText: '<i class="fas fa-times mr-1"></i> Cancel Override',
            focusConfirm: false,
            customClass: {
                confirmButton: 'btn btn-primary',
                cancelButton: 'btn btn-secondary',
                denyButton: 'btn btn-danger'
            },
            preConfirm: () => {
                const aar = document.getElementById('swal_aar').value;
                const adr = document.getElementById('swal_adr').value;
                const start = document.getElementById('swal_start').value;
                const end = document.getElementById('swal_end').value;
                const reason = document.getElementById('swal_reason').value;

                if (!aar && !adr) {
                    Swal.showValidationMessage('Please enter at least AAR or ADR');
                    return false;
                }

                if (!start || !end) {
                    Swal.showValidationMessage('Please select start and end times');
                    return false;
                }

                if (new Date(end) <= new Date(start)) {
                    Swal.showValidationMessage('End time must be after start time');
                    return false;
                }

                return { aar, adr, start, end, reason };
            }
        }).then((result) => {
            if (result.isConfirmed && result.value) {
                // Create override
                createRateOverride(airport, result.value);
            } else if (result.isDenied) {
                // Cancel existing override
                cancelRateOverride(airport);
            }
        });
    });

    // Create rate override via API
    function createRateOverride(airport, data) {
        const payload = {
            airport: airport,
            start_utc: new Date(data.start).toISOString(),
            end_utc: new Date(data.end).toISOString(),
            aar: data.aar ? parseInt(data.aar) : null,
            adr: data.adr ? parseInt(data.adr) : null,
            reason: data.reason || null
        };

        fetch('api/demand/override.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(r => r.json())
        .then(response => {
            if (response.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Override Created',
                    text: `Rates will be overridden from ${formatTimeShort(data.start)} to ${formatTimeShort(data.end)} UTC`,
                    timer: 3000,
                    showConfirmButton: false
                });
                // Refresh demand data to show new override
                loadDemandData();
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: response.error || 'Failed to create override'
                });
            }
        })
        .catch(err => {
            console.error('Override API error:', err);
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'Failed to connect to server'
            });
        });
    }

    // Cancel existing rate override
    function cancelRateOverride(airport) {
        fetch(`api/demand/override.php?airport=${encodeURIComponent(airport)}`, {
            method: 'DELETE'
        })
        .then(r => r.json())
        .then(response => {
            if (response.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Override Cancelled',
                    text: `${response.overrides_cancelled} override(s) removed`,
                    timer: 2000,
                    showConfirmButton: false
                });
                // Refresh demand data
                loadDemandData();
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: response.error || 'Failed to cancel override'
                });
            }
        })
        .catch(err => {
            console.error('Override cancel error:', err);
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'Failed to connect to server'
            });
        });
    }

    // Format time for display
    function formatTimeShort(isoString) {
        const d = new Date(isoString);
        return d.getUTCHours().toString().padStart(2, '0') + ':' +
               d.getUTCMinutes().toString().padStart(2, '0');
    }
</script>

</body>
</html>
