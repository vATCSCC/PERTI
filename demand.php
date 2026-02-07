<?php
/**
 * OPTIMIZED: Public page - no session handler or DB needed
 */
include("load/config.php");
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
    <!-- Filter Color Configuration -->
    <script src="assets/js/config/filter-colors.js"></script>

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
            background-color: transparent;
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

        /* Chart view toggle buttons (individual, not in btn-group) */
        .tbfm-card-header .demand-view-btn {
            font-size: 0.72rem;
            padding: 4px 10px;
            border-radius: 3px;
            background-color: transparent;
            border-color: rgba(255,255,255,0.5);
            color: rgba(255,255,255,0.9);
        }

        .tbfm-card-header .demand-view-btn:hover {
            background-color: rgba(255,255,255,0.15);
            border-color: rgba(255,255,255,0.7);
        }

        .tbfm-card-header .demand-view-btn.active {
            background-color: #5dade2 !important;
            border-color: #5dade2 !important;
            color: #fff !important;
        }

        /* Hide radio buttons inside view labels */
        .tbfm-card-header .demand-view-btn input[type="radio"] {
            position: absolute;
            clip: rect(0,0,0,0);
            pointer-events: none;
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

        /* Multi-ATIS badge styling */
        .atis-badge-group {
            display: inline-flex;
            align-items: center;
            gap: 2px;
            margin-left: 4px;
        }

        .atis-badge-group .badge-atis-type {
            font-size: 0.6rem;
            font-weight: 700;
            color: #6b7280;
            margin-right: 1px;
        }

        .atis-badge-group .badge-atis {
            font-size: 0.7rem;
            padding: 2px 6px;
        }

        .atis-badge-group .badge-age {
            font-size: 0.65rem;
            padding: 2px 5px;
            color: #fff;
            border-radius: 3px;
        }

        /* ATIS modal section styling */
        .atis-section:last-child {
            border-bottom: none !important;
            margin-bottom: 0 !important;
            padding-bottom: 0 !important;
        }

        /* Floating Phase Filter Panel */
        .phase-filter-floating {
            position: fixed;
            z-index: 9999;
            min-width: 160px;
            background: #fff;
            border-radius: 6px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.25);
            display: none;
        }
        .phase-filter-floating.visible {
            display: block;
        }
        .phase-filter-floating .panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 6px 10px;
            background: #1a1a2e;
            color: #fff;
            border-radius: 6px 6px 0 0;
            cursor: move;
            user-select: none;
        }
        .phase-filter-floating .panel-header .panel-title {
            font-size: 0.75rem;
            font-weight: 600;
        }
        .phase-filter-floating .panel-header .panel-controls {
            display: flex;
            gap: 6px;
        }
        .phase-filter-floating .panel-header .panel-btn {
            background: none;
            border: none;
            color: #aaa;
            cursor: pointer;
            padding: 2px 4px;
            font-size: 0.7rem;
            line-height: 1;
            transition: color 0.15s;
        }
        .phase-filter-floating .panel-header .panel-btn:hover {
            color: #fff;
        }
        .phase-filter-floating .panel-body {
            padding: 10px;
        }
        .phase-filter-floating.collapsed .panel-body {
            display: none;
        }
        .phase-filter-floating.collapsed {
            min-width: auto;
        }
        .phase-filter-popout-btn {
            background: none;
            border: none;
            color: #6c757d;
            cursor: pointer;
            padding: 0 4px;
            font-size: 0.7rem;
            transition: color 0.15s;
        }
        .phase-filter-popout-btn:hover {
            color: #1a1a2e;
        }

        /* Chart Loading Overlay */
        .chart-loading-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.85);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 100;
            border-radius: 4px;
        }
        .chart-loading-overlay.visible {
            display: flex;
        }
        .chart-loading-content {
            text-align: center;
            color: #1a1a2e;
        }
        .chart-loading-content .spinner {
            width: 32px;
            height: 32px;
            border: 3px solid #e0e0e0;
            border-top-color: #1a1a2e;
            border-radius: 50%;
            animation: chart-spin 0.8s linear infinite;
        }
        .chart-loading-content .loading-text {
            margin-top: 8px;
            font-size: 0.75rem;
            font-weight: 500;
            color: #6c757d;
        }
        @keyframes chart-spin {
            to { transform: rotate(360deg); }
        }
        .demand-chart-wrapper {
            position: relative;
        }

        /* Legend Toggle Area */
        .demand-legend-toggle-area {
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 8px 12px;
            border-top: 1px solid #e0e0e0;
            background: #fafafa;
            min-height: 32px;
        }

        .demand-legend-toggle-btn {
            background: none;
            border: none;
            color: #6c757d;
            font-size: 0.75rem;
            cursor: pointer;
            padding: 2px 8px;
            transition: color 0.15s;
        }

        .demand-legend-toggle-btn:hover {
            color: #333;
            text-decoration: underline;
        }

        .demand-legend-toggle-btn i {
            margin-right: 4px;
        }

        /* Header Rate Display */
        .demand-header-rates {
            text-align: right;
            font-size: 0.72rem;
            line-height: 1.4;
            font-family: "Inconsolata", "SF Mono", monospace;
        }

        .demand-header-rates .rate-row {
            white-space: nowrap;
        }

        .demand-header-rates .rate-label {
            color: rgba(255, 255, 255, 0.7);
        }

        .demand-header-rates .rate-value {
            font-weight: 600;
            color: #fff;
        }

        .demand-header-rates .rate-value.rw {
            color: #00FFFF;
        }

        .demand-header-rates .rate-separator {
            color: rgba(255, 255, 255, 0.4);
            margin: 0 6px;
        }

        .demand-header-rates .refresh-row {
            color: rgba(255, 255, 255, 0.8);
            margin-bottom: 2px;
        }
    </style>

</head>
<body>

<?php include("load/nav_public.php"); ?>

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
    <div class="perti-info-bar mb-3" style="overflow-x: auto;">
        <div class="row d-flex flex-nowrap align-items-stretch" style="gap: 10px; margin: 0; min-width: max-content;">
            <!-- Current Time (UTC) -->
            <div class="col-auto px-0">
                <div class="card shadow-sm perti-info-card perti-card-utc h-100">
                    <div class="card-body d-flex justify-content-between align-items-center py-2">
                        <div>
                            <div class="perti-info-label"><i class="far fa-clock"></i> UTC</div>
                            <div id="demand_utc_clock" class="tbfm-clock">--:--:--</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Selected Airport -->
            <div class="col-auto px-0">
                <div class="card shadow-sm perti-info-card perti-card-global h-100">
                    <div class="card-body py-2">
                        <div class="perti-info-label"><i class="fas fa-map-marker-alt"></i> Airport</div>
                        <div class="d-flex align-items-baseline">
                            <span id="demand_selected_airport" class="perti-stat-value" style="font-size: 1.2rem; color: var(--info-airport-color);">----</span>
                            <span id="demand_airport_name" class="ml-2 text-muted" style="font-size: 0.65rem; max-width: 100px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">Select airport</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Suggested Config Card -->
            <div class="col-auto px-0">
                <div class="card shadow-sm perti-info-card perti-card-config h-100">
                    <div class="card-body py-2">
                        <div class="perti-info-label">
                            <i class="fas fa-tachometer-alt"></i> Config
                            <span id="rate_weather_category" class="badge badge-weather-vmc ml-1">--</span>
                            <span id="rate_override_badge" class="badge badge-warning ml-1" style="display: none;">OVR</span>
                        </div>
                        <div class="d-flex align-items-center" style="gap: 14px;">
                            <!-- Runways -->
                            <div class="runway-display">
                                <div class="runway-row dep-runways">
                                    <i class="fas fa-plane-departure"></i>
                                    <span id="rate_dep_runways" class="runway-value">--</span>
                                </div>
                                <div class="runway-row arr-runways">
                                    <i class="fas fa-plane-arrival"></i>
                                    <span id="rate_arr_runways" class="runway-value">--</span>
                                </div>
                            </div>
                            <!-- Config name -->
                            <div class="perti-stat-item text-left" style="min-width: 50px;">
                                <div class="perti-stat-category">Config</div>
                                <div id="rate_config_name" class="perti-stat-value" style="font-size: 0.75rem; cursor: help; color: #334155;" title="">--</div>
                            </div>
                            <!-- AAR/ADR -->
                            <div class="perti-stat-item">
                                <div class="perti-stat-category">AAR/ADR</div>
                                <div class="perti-rate-display">
                                    <span id="rate_display" class="perti-rate-value" style="color: var(--info-config-color);">--/--</span>
                                </div>
                            </div>
                            <!-- Source -->
                            <div class="perti-stat-item">
                                <div class="perti-stat-category">Source</div>
                                <div id="rate_source" class="perti-stat-value text-muted" style="font-size: 0.7rem;">--</div>
                            </div>
                            <!-- Set Config Button -->
                            <div class="perti-stat-item d-flex align-items-center">
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="set_config_btn"
                                        title="Set airport configuration" style="display: none; padding: 2px 8px; font-size: 0.7rem;">
                                    <i class="fas fa-pencil-alt"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ATIS Info Card -->
            <div class="col-auto px-0" id="atis_card_container" style="display: none;">
                <div class="card shadow-sm perti-info-card perti-card-atis h-100" style="min-width: 200px;">
                    <div class="card-body py-2">
                        <div class="perti-info-label">
                            <i class="fas fa-broadcast-tower"></i> ATIS
                            <span id="atis_badges_container" class="d-inline-flex" style="gap: 4px;">
                                <!-- ATIS badges will be populated dynamically -->
                            </span>
                            <button type="button" class="btn btn-link btn-sm btn-icon ml-auto p-0" id="atis_details_btn" title="View full ATIS" style="color: var(--info-atis-color);">
                                <i class="fas fa-expand-alt"></i>
                            </button>
                        </div>
                        <div class="d-flex align-items-center" style="gap: 14px;">
                            <!-- Runways from ATIS -->
                            <div class="runway-display">
                                <div class="runway-row dep-runways">
                                    <i class="fas fa-plane-departure"></i>
                                    <span id="atis_dep_runways" class="runway-value">--</span>
                                </div>
                                <div class="runway-row arr-runways">
                                    <i class="fas fa-plane-arrival"></i>
                                    <span id="atis_arr_runways" class="runway-value">--</span>
                                </div>
                            </div>
                            <!-- Approach -->
                            <div class="perti-stat-item text-left">
                                <div class="perti-stat-category">Approach</div>
                                <div id="atis_approach" class="perti-stat-value text-muted" style="font-size: 0.75rem; max-width: 90px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="">--</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Arrival Stats -->
            <div class="col-auto px-0">
                <div class="card shadow-sm perti-info-card perti-card-arrivals h-100">
                    <div class="card-body py-2">
                        <div class="perti-info-label">
                            <i class="fas fa-plane-arrival"></i> Arrivals
                            <span id="demand_arr_total" class="badge badge-success badge-total ml-auto">0</span>
                        </div>
                        <div class="perti-stat-grid">
                            <div class="perti-stat-item">
                                <div class="perti-stat-category">Active</div>
                                <div id="demand_arr_active" class="perti-stat-value" style="color: #dc2626;">0</div>
                            </div>
                            <div class="perti-stat-item">
                                <div class="perti-stat-category">Sched</div>
                                <div id="demand_arr_scheduled" class="perti-stat-value" style="color: var(--info-arr-color);">0</div>
                            </div>
                            <div class="perti-stat-item">
                                <div class="perti-stat-category">Prop</div>
                                <div id="demand_arr_proposed" class="perti-stat-value" style="color: #3b82f6;">0</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Departure Stats -->
            <div class="col-auto px-0">
                <div class="card shadow-sm perti-info-card perti-card-departures h-100">
                    <div class="card-body py-2">
                        <div class="perti-info-label">
                            <i class="fas fa-plane-departure"></i> Departures
                            <span id="demand_dep_total" class="badge badge-warning text-dark badge-total ml-auto">0</span>
                        </div>
                        <div class="perti-stat-grid">
                            <div class="perti-stat-item">
                                <div class="perti-stat-category">Active</div>
                                <div id="demand_dep_active" class="perti-stat-value" style="color: #dc2626;">0</div>
                            </div>
                            <div class="perti-stat-item">
                                <div class="perti-stat-category">Sched</div>
                                <div id="demand_dep_scheduled" class="perti-stat-value" style="color: var(--info-dep-color);">0</div>
                            </div>
                            <div class="perti-stat-item">
                                <div class="perti-stat-category">Prop</div>
                                <div id="demand_dep_proposed" class="perti-stat-value" style="color: #3b82f6;">0</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Auto-Refresh Status -->
            <div class="col-auto px-0">
                <div class="card shadow-sm perti-info-card perti-card-refresh h-100">
                    <div class="card-body d-flex align-items-center py-2">
                        <div>
                            <div class="perti-info-label"><i class="fas fa-sync"></i> Refresh</div>
                            <div class="d-flex align-items-center">
                                <div class="custom-control custom-switch">
                                    <input type="checkbox" class="custom-control-input" id="demand_auto_refresh" checked>
                                    <label class="custom-control-label" for="demand_auto_refresh"></label>
                                </div>
                                <span class="demand-status-indicator demand-status-active ml-2" id="refresh_status">15s</span>
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary ml-3" id="demand_refresh_btn" title="Manual Refresh">
                            <i class="fas fa-sync-alt"></i>
                        </button>
                    </div>
                </div>
            </div>
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

                    <!-- Custom Time Range Inputs (hidden by default) -->
                    <div class="form-group" id="custom_time_range_container" style="display: none;">
                        <label class="demand-label mb-1">Start (UTC)</label>
                        <input type="datetime-local" class="form-control form-control-sm mb-2" id="demand_custom_start">
                        <label class="demand-label mb-1">End (UTC)</label>
                        <input type="datetime-local" class="form-control form-control-sm mb-2" id="demand_custom_end">
                        <button type="button" class="btn btn-primary btn-sm w-100" id="apply_custom_range">Apply Range</button>
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
                    <div class="form-group mb-2">
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

                    <hr class="my-2">

                    <!-- Flight Status Filter -->
                    <div class="form-group mb-0" id="phase-filter-inline-container">
                        <div class="d-flex align-items-center justify-content-between mb-1">
                            <label class="demand-label mb-0">Flight Status</label>
                            <button type="button" class="phase-filter-popout-btn" id="phase-filter-popout-btn" title="Pop out to floating panel">
                                <i class="fas fa-external-link-alt"></i>
                            </button>
                        </div>
                        <div id="phase-filter-checkboxes">
                            <div class="d-flex flex-column" style="gap: 3px;">
                                <label class="mb-0 d-flex align-items-center" style="cursor: pointer; font-size: 0.75rem;">
                                    <input type="checkbox" id="phase_prefile" checked style="margin-right: 6px;">
                                    <span style="background:#3b82f6;width:10px;height:10px;display:inline-block;border-radius:2px;margin-right:4px;"></span>
                                    Prefile
                                </label>
                                <label class="mb-0 d-flex align-items-center" style="cursor: pointer; font-size: 0.75rem;">
                                    <input type="checkbox" id="phase_departing" checked style="margin-right: 6px;">
                                    <span style="background:#22c55e;width:10px;height:10px;display:inline-block;border-radius:2px;margin-right:4px;"></span>
                                    Departing
                                </label>
                                <label class="mb-0 d-flex align-items-center" style="cursor: pointer; font-size: 0.75rem;">
                                    <input type="checkbox" id="phase_active" checked style="margin-right: 6px;">
                                    <span style="background:#dc2626;width:10px;height:10px;display:inline-block;border-radius:2px;margin-right:4px;"></span>
                                    Active
                                </label>
                                <label class="mb-0 d-flex align-items-center" style="cursor: pointer; font-size: 0.75rem;">
                                    <input type="checkbox" id="phase_arrived" checked style="margin-right: 6px;">
                                    <span style="background:#1a1a1a;width:10px;height:10px;display:inline-block;border-radius:2px;margin-right:4px;"></span>
                                    Arrived
                                </label>
                                <label class="mb-0 d-flex align-items-center" style="cursor: pointer; font-size: 0.75rem;">
                                    <input type="checkbox" id="phase_disconnected" checked style="margin-right: 6px;">
                                    <span style="background:#f97316;width:10px;height:10px;display:inline-block;border-radius:2px;margin-right:4px;"></span>
                                    Disconnected
                                </label>
                                <label class="mb-0 d-flex align-items-center text-muted" style="cursor: pointer; font-size: 0.75rem;">
                                    <input type="checkbox" id="phase_unknown" style="margin-right: 6px;">
                                    <span style="background:#9333ea;width:10px;height:10px;display:inline-block;border-radius:2px;margin-right:4px;"></span>
                                    Unknown
                                </label>
                            </div>
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
                    <div class="legend-group mb-2">
                        <div class="legend-group-title text-muted small mb-1" style="font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.05em;">
                            <i class="fas fa-question-circle mr-1"></i> Other
                        </div>
                        <div class="d-flex flex-wrap" style="gap: 2px 10px;">
                            <div class="demand-legend-item"><span class="demand-legend-color" style="background-color: #f97316;"></span>Disconnected</div>
                            <div class="demand-legend-item"><span class="demand-legend-color" style="background-color: #6b7280;"></span>Exempt</div>
                            <div class="demand-legend-item"><span class="demand-legend-color" style="background-color: #9333ea;"></span>Unknown</div>
                        </div>
                    </div>
                    <!-- TMI Statuses -->
                    <div class="legend-group mb-2">
                        <div class="legend-group-title text-muted small mb-1" style="font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.05em;">
                            <i class="fas fa-hand-paper mr-1"></i> Ground Stop
                        </div>
                        <div class="d-flex flex-wrap" style="gap: 2px 10px;">
                            <div class="demand-legend-item"><span class="demand-legend-color" style="background-color: #eab308;"></span>GS (EDCT)</div>
                            <div class="demand-legend-item"><span class="demand-legend-color" style="background-color: #fef08a;"></span>GS (Sim)</div>
                            <div class="demand-legend-item"><span class="demand-legend-color" style="background-color: #ca8a04;"></span>GS (Prop)</div>
                        </div>
                    </div>
                    <div class="legend-group">
                        <div class="legend-group-title text-muted small mb-1" style="font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.05em;">
                            <i class="fas fa-clock mr-1"></i> Ground Delay
                        </div>
                        <div class="d-flex flex-wrap" style="gap: 2px 10px;">
                            <div class="demand-legend-item"><span class="demand-legend-color" style="background-color: #92400e;"></span>GDP (EDCT)</div>
                            <div class="demand-legend-item"><span class="demand-legend-color" style="background-color: #d4a574;"></span>GDP (Sim)</div>
                            <div class="demand-legend-item"><span class="demand-legend-color" style="background-color: #78350f;"></span>GDP (Prop)</div>
                        </div>
                    </div>
                    <hr class="my-2">
                    <!-- Rate Lines Toggles -->
                    <div class="legend-group">
                        <div class="legend-group-title text-muted small mb-1" style="font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.05em;">
                            <i class="fas fa-minus mr-1"></i> Rate Lines
                        </div>
                        <div class="d-flex flex-column" style="gap: 4px;">
                            <!-- VATSIM Rates -->
                            <div class="d-flex align-items-center" style="gap: 8px;">
                                <label class="mb-0 d-flex align-items-center" style="cursor: pointer; font-size: 0.75rem;">
                                    <input type="checkbox" id="rate_vatsim_aar" checked style="margin-right: 4px;">
                                    <span style="display: inline-block; width: 16px; height: 2px; background: #000; margin-right: 4px; vertical-align: middle;"></span>
                                    AAR
                                </label>
                                <label class="mb-0 d-flex align-items-center" style="cursor: pointer; font-size: 0.75rem;">
                                    <input type="checkbox" id="rate_vatsim_adr" checked style="margin-right: 4px;">
                                    <span style="display: inline-block; width: 16px; height: 0; border-top: 2px dashed #000; margin-right: 4px; vertical-align: middle;"></span>
                                    ADR
                                </label>
                            </div>
                            <!-- Real World Rates -->
                            <div class="d-flex align-items-center" style="gap: 8px;">
                                <label class="mb-0 d-flex align-items-center" style="cursor: pointer; font-size: 0.75rem;">
                                    <input type="checkbox" id="rate_rw_aar" checked style="margin-right: 4px;">
                                    <span style="display: inline-block; width: 16px; height: 2px; background: #00FFFF; margin-right: 4px; vertical-align: middle;"></span>
                                    RW AAR
                                </label>
                                <label class="mb-0 d-flex align-items-center" style="cursor: pointer; font-size: 0.75rem;">
                                    <input type="checkbox" id="rate_rw_adr" checked style="margin-right: 4px;">
                                    <span style="display: inline-block; width: 16px; height: 0; border-top: 2px dashed #00FFFF; margin-right: 4px; vertical-align: middle;"></span>
                                    RW ADR
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right: Chart -->
        <div class="col-lg-9 mb-4">
            <div class="card shadow-sm tbfm-chart-card">
                <div class="card-header tbfm-card-header">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <span class="demand-section-title">
                            <i class="fas fa-chart-bar mr-1"></i> Demand Chart
                        </span>
                        <div class="demand-header-rates" id="demand_header_rates">
                            <div class="refresh-row" id="demand_last_update">--</div>
                            <div class="rate-row" id="demand_header_aar_row" style="display: none;">
                                <span class="rate-label">VATSIM AAR</span> <span class="rate-value" id="header_vatsim_aar">--</span>
                                <span class="rate-separator">|</span>
                                <span class="rate-label">RW AAR</span> <span class="rate-value rw" id="header_rw_aar">--</span>
                            </div>
                            <div class="rate-row" id="demand_header_adr_row" style="display: none;">
                                <span class="rate-label">VATSIM ADR</span> <span class="rate-value" id="header_vatsim_adr">--</span>
                                <span class="rate-separator">|</span>
                                <span class="rate-label">RW ADR</span> <span class="rate-value rw" id="header_rw_adr">--</span>
                            </div>
                        </div>
                    </div>
                    <!-- Chart View Toggle - on its own row for space -->
                    <div class="d-flex flex-wrap" style="gap: 4px;">
                        <label class="btn btn-outline-light btn-sm demand-view-btn active" title="Show by flight status">
                            <input type="radio" name="demand_chart_view" id="view_status" value="status" autocomplete="off" checked> Status
                        </label>
                        <label class="btn btn-outline-light btn-sm demand-view-btn" title="Show arrivals by origin ARTCC">
                            <input type="radio" name="demand_chart_view" id="view_origin" value="origin" autocomplete="off"> Origin
                        </label>
                        <label class="btn btn-outline-light btn-sm demand-view-btn" title="Show departures by destination ARTCC">
                            <input type="radio" name="demand_chart_view" id="view_dest" value="dest" autocomplete="off"> Dest
                        </label>
                        <label class="btn btn-outline-light btn-sm demand-view-btn" title="Show by carrier">
                            <input type="radio" name="demand_chart_view" id="view_carrier" value="carrier" autocomplete="off"> Carrier
                        </label>
                        <label class="btn btn-outline-light btn-sm demand-view-btn" title="Show by weight class">
                            <input type="radio" name="demand_chart_view" id="view_weight" value="weight" autocomplete="off"> Weight
                        </label>
                        <label class="btn btn-outline-light btn-sm demand-view-btn" title="Show by aircraft type">
                            <input type="radio" name="demand_chart_view" id="view_equipment" value="equipment" autocomplete="off"> Equip
                        </label>
                        <label class="btn btn-outline-light btn-sm demand-view-btn" title="Show by IFR/VFR">
                            <input type="radio" name="demand_chart_view" id="view_rule" value="rule" autocomplete="off"> Rule
                        </label>
                        <label class="btn btn-outline-light btn-sm demand-view-btn" title="Show departures by departure fix">
                            <input type="radio" name="demand_chart_view" id="view_dep_fix" value="dep_fix" autocomplete="off"> Dep Fix
                        </label>
                        <label class="btn btn-outline-light btn-sm demand-view-btn" title="Show arrivals by arrival fix">
                            <input type="radio" name="demand_chart_view" id="view_arr_fix" value="arr_fix" autocomplete="off"> Arr Fix
                        </label>
                        <label class="btn btn-outline-light btn-sm demand-view-btn" title="Show departures by SID">
                            <input type="radio" name="demand_chart_view" id="view_dp" value="dp" autocomplete="off"> DP
                        </label>
                        <label class="btn btn-outline-light btn-sm demand-view-btn" title="Show arrivals by STAR">
                            <input type="radio" name="demand_chart_view" id="view_star" value="star" autocomplete="off"> STAR
                        </label>
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
                    <div class="demand-chart-wrapper">
                        <div id="demand_chart" class="demand-chart-container" style="display: none;"></div>
                        <div class="chart-loading-overlay" id="chart_loading_overlay">
                            <div class="chart-loading-content">
                                <div class="spinner"></div>
                                <div class="loading-text">Updating chart...</div>
                            </div>
                        </div>
                    </div>

                    <!-- Legend Toggle Area -->
                    <div class="demand-legend-toggle-area" id="demand_legend_toggle_area" style="display: none;">
                        <button type="button" class="demand-legend-toggle-btn" id="demand_legend_toggle_btn">
                            <i class="fas fa-eye-slash"></i> <span id="legend_toggle_text">Hide Legend</span>
                        </button>
                    </div>
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
    /**
     * Format config name for display
     * Parses "ARR / DEP" pattern and adds explicit labels
     */
    function formatConfigName(configName, arrRunways, depRunways) {
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

        // Parse "ARR / DEP" pattern
        const match = configName.match(/^(.+?)\s*\/\s*(.+)$/);
        if (match) {
            const arrPart = match[1].trim().replace(/\//g, ' ');
            const depPart = match[2].trim().replace(/\//g, ' ');
            return `ARR: ${arrPart} | DEP: ${depPart}`;
        }

        return configName;
    }

    // Chart view toggle button handler
    $('.demand-view-btn').on('click', function() {
        // Remove active class from all buttons
        $('.demand-view-btn').removeClass('active');
        // Add active class to clicked button
        $(this).addClass('active');
        // Check the radio input
        $(this).find('input[type="radio"]').prop('checked', true).trigger('change');
    });

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

    // User info for config publishing (from session set by nav_public.php)
    window.DEMAND_USER = {
        loggedIn: <?= json_encode($logged_in) ?>,
        cid: <?= json_encode($logged_in ? ($_SESSION['VATSIM_CID'] ?? null) : null) ?>,
        name: <?= json_encode($logged_in ? (trim(($user_first_name ?? '') . ' ' . ($user_last_name ?? ''))) : null) ?>
    };

</script>

<!-- Floating Phase Filter Panel -->
<div class="phase-filter-floating" id="phase-filter-floating">
    <div class="panel-header">
        <span class="panel-title"><i class="fas fa-filter mr-1"></i> Flight Status</span>
        <div class="panel-controls">
            <button type="button" class="panel-btn" id="phase-filter-collapse-btn" title="Collapse">
                <i class="fas fa-minus"></i>
            </button>
            <button type="button" class="panel-btn" id="phase-filter-close-btn" title="Close (return to sidebar)">
                <i class="fas fa-times"></i>
            </button>
        </div>
    </div>
    <div class="panel-body" id="phase-filter-floating-body">
        <!-- Checkboxes will be moved here when popped out -->
    </div>
</div>

</body>
</html>
