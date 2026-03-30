<?php
/**
 * OPTIMIZED: Public page - no DB needed
 */
include("sessions/handler.php");
include("load/config.php");
include("load/i18n.php");
?>
<!DOCTYPE html>
<html lang="en">
<head>

    <?php $page_title = __('demand.page.title'); include("load/header.php"); ?>

    <!-- Info Bar Shared Styles -->
    <link rel="stylesheet" href="assets/css/info-bar.css<?= _v('assets/css/info-bar.css') ?>">

    <!-- ECharts CDN -->
    <script src="https://cdn.jsdelivr.net/npm/echarts@5.4.3/dist/echarts.min.js"></script>

    <!-- Shared Phase Color Configuration -->
    <script src="assets/js/config/phase-colors.js<?= _v('assets/js/config/phase-colors.js') ?>"></script>
    <!-- Rate Line Color Configuration -->
    <script src="assets/js/config/rate-colors.js<?= _v('assets/js/config/rate-colors.js') ?>"></script>
    <!-- Filter Color Configuration -->
    <script src="assets/js/config/filter-colors.js<?= _v('assets/js/config/filter-colors.js') ?>"></script>

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

        /* TMI Timeline Bar */
        .demand-tmi-timeline {
            position: relative;
            background: #f8f9fa;
            border: 1px solid #d0d0d0;
            border-bottom: none;
            border-radius: 3px 3px 0 0;
            overflow: hidden;
            font-family: "Inconsolata", "SF Mono", Consolas, monospace;
            font-size: 10px;
        }

        .tmi-timeline-track {
            position: relative;
            min-height: 28px;
            padding: 4px 0;
        }

        .tmi-timeline-bar {
            position: absolute;
            height: 20px;
            border-radius: 2px;
            border: 1px solid;
            overflow: hidden;
            white-space: nowrap;
            line-height: 20px;
            padding: 0 4px;
            box-sizing: border-box;
            z-index: 2;
        }

        .tmi-timeline-bar-label {
            color: #000;
            font-weight: 700;
            font-size: 10px;
            text-shadow: 0 0 2px rgba(255,255,255,0.6);
            pointer-events: none;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 100%;
            display: inline-block;
        }

        .tmi-timeline-bar.tmi-status-completed,
        .tmi-timeline-bar.tmi-status-cancelled {
            opacity: 0.45;
        }

        .tmi-timeline-bar.tmi-status-cancelled {
            background-image: repeating-linear-gradient(
                -45deg, transparent, transparent 3px,
                rgba(0,0,0,0.15) 3px, rgba(0,0,0,0.15) 6px
            ) !important;
        }

        .tmi-timeline-bar .tmi-cnx-label {
            position: absolute;
            right: 2px;
            top: 0;
            font-size: 9px;
            font-weight: 700;
            color: #721c24;
            line-height: 20px;
        }

        .tmi-timeline-bar .tmi-update-marker {
            position: absolute;
            top: 50%;
            width: 5px;
            height: 5px;
            background: #000;
            transform: translate(-50%, -50%) rotate(45deg);
            z-index: 3;
            pointer-events: none;
            opacity: 0.7;
        }

        .tmi-timeline-now {
            position: absolute;
            top: 0;
            bottom: 0;
            width: 0;
            border-left: 2px dashed #dc3545;
            z-index: 10;
            pointer-events: none;
        }

        .tmi-timeline-ticks {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 100%;
            pointer-events: none;
            z-index: 1;
        }

        .tmi-timeline-tick {
            position: absolute;
            top: 0;
            bottom: 0;
            border-left: 1px solid rgba(0,0,0,0.08);
        }

        .tmi-timeline-tick-label {
            position: absolute;
            top: 1px;
            left: 2px;
            font-size: 8px;
            color: #999;
            white-space: nowrap;
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

        /* Comparison Mode Grid */
        #demand_chart_grid {
            display: none;
        }
        #demand_chart_grid.active {
            display: grid !important;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }
        #demand_chart_grid.single-col {
            grid-template-columns: 1fr;
        }
        .compare-panel {
            border: 2px solid #2c3e50;
            border-radius: 4px;
            background: #f8f9fa;
            overflow: hidden;
        }
        .compare-panel-header {
            background: #ecf0f1;
            border-bottom: 1px solid #bdc3c7;
            padding: 4px 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .compare-panel-header .airport-code {
            font-weight: 700;
            font-size: 0.85rem;
            color: #2c3e50;
        }
        .compare-panel-header .airport-meta {
            font-size: 0.65rem;
            color: #666;
            font-family: 'Roboto Mono', monospace;
        }
        .compare-panel-chart {
            height: 340px;
        }
        .compare-panel-chart.side-by-side {
            height: 380px;
        }
        .compare-chip {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 8px;
            background: #2c3e50;
            color: #fff;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        .compare-chip .chip-remove {
            cursor: pointer;
            opacity: 0.7;
            font-size: 0.6rem;
        }
        .compare-chip .chip-remove:hover {
            opacity: 1;
        }
        /* Stats tab strip for comparison mode */
        .summary-tab-strip {
            display: flex;
            gap: 4px;
            margin-bottom: 8px;
        }
        .summary-tab {
            padding: 2px 10px;
            border: 1px solid #bdc3c7;
            border-radius: 3px;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            background: #fff;
            color: #2c3e50;
        }
        .summary-tab.active {
            background: #2c3e50;
            color: #fff;
            border-color: #2c3e50;
        }

        /* Select2 overrides — match .demand-filter-card light theme */
        .demand-filter-card .select2-container--default .select2-selection--multiple {
            background-color: #fff;
            border: 1px solid #ced4da;
            min-height: calc(1.5em + .5rem + 2px);
            font-size: 0.85rem;
        }
        .demand-filter-card .select2-container--default .select2-selection--multiple .select2-selection__choice {
            background-color: #e9ecef;
            border: 1px solid #ced4da;
            color: #333;
            font-size: 0.78rem;
            padding: 1px 6px;
            margin-top: 3px;
        }
        .demand-filter-card .select2-container--default .select2-selection--multiple .select2-selection__choice__remove {
            color: #6c757d;
        }
        .demand-filter-card .select2-container--default .select2-selection--multiple .select2-selection__rendered {
            padding: 2px 4px;
        }
        .select2-dropdown {
            border-color: #ced4da;
            font-size: 0.85rem;
        }
        .select2-results__option--highlighted[aria-selected] {
            background-color: #3498db !important;
        }
        .select2-search--dropdown .select2-search__field {
            border-color: #ced4da;
            font-size: 0.85rem;
        }
    </style>

</head>
<body>

<?php include("load/nav_public.php"); ?>

<!-- Hero Section -->
<section class="perti-hero perti-hero--dark-tool" data-jarallax data-speed="0.3">
    <div class="container-fluid pt-2 pb-4 py-lg-5">
        <img class="jarallax-img" src="assets/img/jumbotron/main.png" alt="" style="opacity: 50%;">

        <center>
            <h1><?= __('demand.page.title') ?></h1>
            <h4 class="text-white hvr-bob pl-1">
                <a href="#demand_section" style="text-decoration: none; color: #fff;">
                    <i class="fas fa-chevron-down text-danger"></i>
                    <?= __('demand.page.subtitle') ?>
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
                            <div class="perti-info-label"><i class="far fa-clock"></i> <?= __('demand.page.utc') ?></div>
                            <div id="demand_utc_clock" class="tbfm-clock">--:--:--</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Selected Airport -->
            <div class="col-auto px-0">
                <div class="card shadow-sm perti-info-card perti-card-global h-100">
                    <div class="card-body py-2">
                        <div class="perti-info-label"><i class="fas fa-map-marker-alt"></i> <?= __('demand.page.airport') ?></div>
                        <div class="d-flex align-items-baseline">
                            <span id="demand_selected_airport" class="perti-stat-value" style="font-size: 1.2rem; color: var(--info-airport-color);">----</span>
                            <span id="demand_airport_name" class="ml-2 text-muted" style="font-size: 0.65rem; max-width: 100px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?= __('demand.page.selectAirport') ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Suggested Config Card -->
            <div class="col-auto px-0">
                <div class="card shadow-sm perti-info-card perti-card-config h-100">
                    <div class="card-body py-2">
                        <div class="perti-info-label">
                            <i class="fas fa-tachometer-alt"></i> <?= __('demand.page.config') ?>
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
                                <div class="perti-stat-category"><?= __('demand.page.config') ?></div>
                                <div id="rate_config_name" class="perti-stat-value" style="font-size: 0.75rem; cursor: help; color: #334155;" title="">--</div>
                            </div>
                            <!-- AAR/ADR -->
                            <div class="perti-stat-item">
                                <div class="perti-stat-category"><?= __('demand.page.aarAdr') ?></div>
                                <div class="perti-rate-display">
                                    <span id="rate_display" class="perti-rate-value" style="color: var(--info-config-color);">--/--</span>
                                </div>
                            </div>
                            <!-- Source -->
                            <div class="perti-stat-item">
                                <div class="perti-stat-category"><?= __('demand.page.source') ?></div>
                                <div id="rate_source" class="perti-stat-value text-muted" style="font-size: 0.7rem;">--</div>
                            </div>
                            <!-- Set Config Button -->
                            <div class="perti-stat-item d-flex align-items-center">
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="set_config_btn"
                                        title="<?= __('demand.page.setAirportConfig') ?>" style="display: none; padding: 2px 8px; font-size: 0.7rem;">
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
                            <i class="fas fa-broadcast-tower"></i> <?= __('demand.page.atis') ?>
                            <span id="atis_badges_container" class="d-inline-flex" style="gap: 4px;">
                                <!-- ATIS badges will be populated dynamically -->
                            </span>
                            <button type="button" class="btn btn-link btn-sm btn-icon ml-auto p-0" id="atis_details_btn" title="<?= __('demand.page.viewFullAtis') ?>" style="color: var(--info-atis-color);">
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
                                <div class="perti-stat-category"><?= __('demand.page.approach') ?></div>
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
                            <i class="fas fa-plane-arrival"></i> <?= __('demand.page.arrivals') ?>
                            <span id="demand_arr_total" class="badge badge-success badge-total ml-auto">0</span>
                        </div>
                        <div class="perti-stat-grid">
                            <div class="perti-stat-item">
                                <div class="perti-stat-category"><?= __('demand.page.active') ?></div>
                                <div id="demand_arr_active" class="perti-stat-value" style="color: #dc2626;">0</div>
                            </div>
                            <div class="perti-stat-item">
                                <div class="perti-stat-category"><?= __('demand.page.scheduled') ?></div>
                                <div id="demand_arr_scheduled" class="perti-stat-value" style="color: var(--info-arr-color);">0</div>
                            </div>
                            <div class="perti-stat-item">
                                <div class="perti-stat-category"><?= __('demand.page.proposed') ?></div>
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
                            <i class="fas fa-plane-departure"></i> <?= __('demand.page.departures') ?>
                            <span id="demand_dep_total" class="badge badge-warning text-dark badge-total ml-auto">0</span>
                        </div>
                        <div class="perti-stat-grid">
                            <div class="perti-stat-item">
                                <div class="perti-stat-category"><?= __('demand.page.active') ?></div>
                                <div id="demand_dep_active" class="perti-stat-value" style="color: #dc2626;">0</div>
                            </div>
                            <div class="perti-stat-item">
                                <div class="perti-stat-category"><?= __('demand.page.scheduled') ?></div>
                                <div id="demand_dep_scheduled" class="perti-stat-value" style="color: var(--info-dep-color);">0</div>
                            </div>
                            <div class="perti-stat-item">
                                <div class="perti-stat-category"><?= __('demand.page.proposed') ?></div>
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
                            <div class="perti-info-label"><i class="fas fa-sync"></i> <?= __('demand.page.refresh') ?></div>
                            <div class="d-flex align-items-center">
                                <div class="custom-control custom-switch">
                                    <input type="checkbox" class="custom-control-input" id="demand_auto_refresh" checked>
                                    <label class="custom-control-label" for="demand_auto_refresh"></label>
                                </div>
                                <span class="demand-status-indicator demand-status-active ml-2" id="refresh_status">15s</span>
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary ml-3" id="demand_refresh_btn" title="<?= __('demand.page.manualRefresh') ?>">
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
                        <i class="fas fa-filter mr-1"></i> <?= __('demand.page.filters') ?>
                    </span>
                </div>

                <div class="card-body">
                    <!-- Demand Type Selection -->
                    <div class="form-group">
                        <label class="demand-label mb-1" for="demand_type"><?= __('demand.facility.type.label') ?></label>
                        <select class="form-control form-control-sm" id="demand_type">
                            <option value="airport"><?= __('demand.facility.type.airport') ?></option>
                            <option value="tracon"><?= __('demand.facility.type.tracon') ?></option>
                            <option value="artcc"><?= __('demand.facility.type.artccFir') ?></option>
                            <option value="group"><?= __('demand.facility.type.group') ?></option>
                        </select>
                    </div>

                    <!-- Airport Selection -->
                    <div class="form-group">
                        <label class="demand-label mb-1" for="demand_airport"><?= __('demand.page.airport') ?></label>
                        <select class="form-control form-control-sm" id="demand_airport">
                            <option value=""><?= __('demand.page.selectAirportOption') ?></option>
                        </select>
                    </div>

                    <!-- Comparison Mode Toggle -->
                    <div class="form-group mb-2" id="compare_toggle_container">
                        <div class="d-flex align-items-center" style="gap: 8px;">
                            <label class="mb-0 d-flex align-items-center" style="cursor: pointer; font-size: 0.8rem;">
                                <input type="checkbox" id="compare_mode_toggle" style="margin-right: 4px;">
                                <i class="fas fa-columns mr-1 text-muted"></i> <?= __('demand.compare.enable') ?>
                            </label>
                            <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2" id="compare_add_btn" style="display: none; font-size: 0.7rem;">
                                + <?= __('demand.compare.addAirport') ?>
                            </button>
                        </div>
                        <!-- Chip bar for selected airports -->
                        <div id="compare_chip_bar" class="d-flex flex-wrap mt-1" style="gap: 4px; display: none;"></div>
                        <small class="text-danger" id="compare_max_msg" style="display: none;"><?= __('demand.compare.maxReached') ?></small>
                    </div>

                    <!-- Facility Selection (hidden by default, shown for non-airport types) -->
                    <div class="form-group" id="facility_selector_container" style="display: none;">
                        <label class="demand-label mb-1" for="demand_facility"><?= __('demand.facility.infoBar.facilityName') ?></label>
                        <select class="form-control form-control-sm" id="demand_facility">
                            <option value="">--</option>
                        </select>
                    </div>

                    <!-- Category Filter -->
                    <div class="form-group">
                        <label class="demand-label mb-1" for="demand_category"><?= __('demand.page.category') ?></label>
                        <select class="form-control form-control-sm" id="demand_category">
                            <option value="all"><?= __('demand.page.allAirports') ?></option>
                            <option value="core30">Core30</option>
                            <option value="oep35">OEP35</option>
                            <option value="aspm82">ASPM82</option>
                        </select>
                    </div>

                    <!-- ARTCC Filter -->
                    <div class="form-group">
                        <label class="demand-label mb-1" for="demand_artcc"><?= __('demand.page.artcc') ?></label>
                        <select class="form-control form-control-sm" id="demand_artcc">
                            <option value=""><?= __('demand.page.allArtccs') ?></option>
                        </select>
                    </div>

                    <!-- Tier Filter -->
                    <div class="form-group">
                        <label class="demand-label mb-1" for="demand_tier"><?= __('demand.page.tier') ?></label>
                        <select class="form-control form-control-sm" id="demand_tier">
                            <option value="all"><?= __('demand.page.allTiers') ?></option>
                        </select>
                    </div>

                    <!-- Mode Toggle (hidden by default, shown for non-airport types) -->
                    <div class="form-group" id="mode_toggle_container" style="display: none;">
                        <label class="demand-label mb-1"><?= __('demand.facility.mode.label') ?></label>
                        <div class="btn-group btn-group-toggle btn-group-sm demand-toggle-group w-100" data-toggle="buttons" role="group">
                            <label class="btn btn-outline-secondary active">
                                <input type="radio" name="demand_mode" id="mode_airport" value="airport" autocomplete="off" checked> <?= __('demand.facility.mode.airportCounts') ?>
                            </label>
                            <label class="btn btn-outline-secondary">
                                <input type="radio" name="demand_mode" id="mode_crossing" value="crossing" autocomplete="off"> <?= __('demand.facility.mode.boundaryCrossings') ?>
                            </label>
                        </div>
                    </div>

                    <hr>

                    <!-- Time Range -->
                    <div class="form-group">
                        <label class="demand-label mb-1" for="demand_time_range"><?= __('demand.page.timeRange') ?></label>
                        <select class="form-control form-control-sm" id="demand_time_range">
                            <!-- Populated by JavaScript -->
                        </select>
                    </div>

                    <!-- Custom Time Range Inputs (hidden by default) -->
                    <div class="form-group" id="custom_time_range_container" style="display: none;">
                        <label class="demand-label mb-1"><?= __('demand.page.startUtc') ?></label>
                        <input type="datetime-local" class="form-control form-control-sm mb-2" id="demand_custom_start">
                        <label class="demand-label mb-1"><?= __('demand.page.endUtc') ?></label>
                        <input type="datetime-local" class="form-control form-control-sm mb-2" id="demand_custom_end">
                        <button type="button" class="btn btn-primary btn-sm w-100" id="apply_custom_range"><?= __('demand.page.applyRange') ?></button>
                    </div>

                    <!-- Granularity Toggle -->
                    <div class="form-group">
                        <label class="demand-label mb-1"><?= __('demand.page.granularity') ?></label>
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
                        <label class="demand-label mb-1"><?= __('demand.page.direction') ?></label>
                        <div class="btn-group btn-group-toggle btn-group-sm demand-toggle-group w-100" data-toggle="buttons" role="group">
                            <label class="btn btn-outline-secondary active">
                                <input type="radio" name="demand_direction" id="direction_both" value="both" autocomplete="off" checked> <?= __('demand.page.both') ?>
                            </label>
                            <label class="btn btn-outline-secondary">
                                <input type="radio" name="demand_direction" id="direction_arr" value="arr" autocomplete="off"> <?= __('demand.page.arr') ?>
                            </label>
                            <label class="btn btn-outline-secondary">
                                <input type="radio" name="demand_direction" id="direction_dep" value="dep" autocomplete="off"> <?= __('demand.page.dep') ?>
                            </label>
                            <label class="btn btn-outline-secondary" id="direction_thru_label" style="display: none;">
                                <input type="radio" name="demand_direction" id="direction_thru" value="thru" autocomplete="off"> <?= __('demand.facility.direction.thru') ?>
                            </label>
                        </div>
                    </div>

                    <hr class="my-2">

                    <!-- Enhanced Filters (Feature 2) -->
                    <div id="enhanced_filters_section">
                        <!-- Carrier Filter -->
                        <div class="form-group mb-2">
                            <label class="demand-label mb-1"><?= __('demand.page.carrierFilter') ?></label>
                            <select class="form-control form-control-sm" id="filter_carrier" multiple="multiple" style="width: 100%;">
                            </select>
                        </div>

                        <!-- Weight Class Filter -->
                        <div class="form-group mb-2">
                            <label class="demand-label mb-1"><?= __('demand.page.weightClassFilter') ?></label>
                            <div class="d-flex flex-wrap" style="gap: 4px 10px;">
                                <label class="mb-0 d-flex align-items-center" style="cursor: pointer; font-size: 0.75rem;">
                                    <input type="checkbox" class="weight-class-filter" value="H" checked style="margin-right: 3px;">
                                    <span style="background:#dc2626;width:8px;height:8px;display:inline-block;border-radius:50%;margin-right:3px;"></span> H
                                </label>
                                <label class="mb-0 d-flex align-items-center" style="cursor: pointer; font-size: 0.75rem;">
                                    <input type="checkbox" class="weight-class-filter" value="L" checked style="margin-right: 3px;">
                                    <span style="background:#3b82f6;width:8px;height:8px;display:inline-block;border-radius:50%;margin-right:3px;"></span> L
                                </label>
                                <label class="mb-0 d-flex align-items-center" style="cursor: pointer; font-size: 0.75rem;">
                                    <input type="checkbox" class="weight-class-filter" value="S" checked style="margin-right: 3px;">
                                    <span style="background:#22c55e;width:8px;height:8px;display:inline-block;border-radius:50%;margin-right:3px;"></span> S
                                </label>
                                <label class="mb-0 d-flex align-items-center" style="cursor: pointer; font-size: 0.75rem;">
                                    <input type="checkbox" class="weight-class-filter" value="+" checked style="margin-right: 3px;">
                                    <span style="background:#9333ea;width:8px;height:8px;display:inline-block;border-radius:50%;margin-right:3px;"></span> +
                                </label>
                            </div>
                        </div>

                        <!-- Equipment Filter -->
                        <div class="form-group mb-2">
                            <label class="demand-label mb-1"><?= __('demand.page.equipmentFilter') ?></label>
                            <select class="form-control form-control-sm" id="filter_equipment" multiple="multiple" style="width: 100%;">
                            </select>
                        </div>

                        <!-- Origin ARTCC Filter -->
                        <div class="form-group mb-2">
                            <label class="demand-label mb-1"><?= __('demand.page.originArtccFilter') ?></label>
                            <select class="form-control form-control-sm" id="filter_origin_artcc" multiple="multiple" style="width: 100%;">
                            </select>
                        </div>

                        <!-- Dest ARTCC Filter -->
                        <div class="form-group mb-2">
                            <label class="demand-label mb-1"><?= __('demand.page.destArtccFilter') ?></label>
                            <select class="form-control form-control-sm" id="filter_dest_artcc" multiple="multiple" style="width: 100%;">
                            </select>
                        </div>

                        <!-- Reset Filters Link -->
                        <div class="text-center" id="reset_filters_container" style="display: none;">
                            <a href="#" id="reset_filters_link" class="small text-danger">
                                <i class="fas fa-times-circle mr-1"></i><?= __('demand.page.resetFilters') ?>
                            </a>
                        </div>
                    </div>

                    <hr class="my-2">

                    <!-- Flight Status Filter -->
                    <div class="form-group mb-0" id="phase-filter-inline-container">
                        <div class="d-flex align-items-center justify-content-between mb-1">
                            <label class="demand-label mb-0"><?= __('demand.page.flightStatus') ?></label>
                            <button type="button" class="phase-filter-popout-btn" id="phase-filter-popout-btn" title="<?= __('demand.page.popoutTooltip') ?>">
                                <i class="fas fa-external-link-alt"></i>
                            </button>
                        </div>
                        <div id="phase-filter-checkboxes">
                            <div class="d-flex flex-column" style="gap: 3px;">
                                <label class="mb-0 d-flex align-items-center" style="cursor: pointer; font-size: 0.75rem;">
                                    <input type="checkbox" id="phase_prefile" checked style="margin-right: 6px;">
                                    <span style="background:#3b82f6;width:10px;height:10px;display:inline-block;border-radius:2px;margin-right:4px;"></span>
                                    <?= __('demand.page.prefile') ?>
                                </label>
                                <label class="mb-0 d-flex align-items-center" style="cursor: pointer; font-size: 0.75rem;">
                                    <input type="checkbox" id="phase_departing" checked style="margin-right: 6px;">
                                    <span style="background:#22c55e;width:10px;height:10px;display:inline-block;border-radius:2px;margin-right:4px;"></span>
                                    <?= __('demand.page.departing') ?>
                                </label>
                                <label class="mb-0 d-flex align-items-center" style="cursor: pointer; font-size: 0.75rem;">
                                    <input type="checkbox" id="phase_active" checked style="margin-right: 6px;">
                                    <span style="background:#dc2626;width:10px;height:10px;display:inline-block;border-radius:2px;margin-right:4px;"></span>
                                    <?= __('demand.page.active') ?>
                                </label>
                                <label class="mb-0 d-flex align-items-center" style="cursor: pointer; font-size: 0.75rem;">
                                    <input type="checkbox" id="phase_arrived" checked style="margin-right: 6px;">
                                    <span style="background:#1a1a1a;width:10px;height:10px;display:inline-block;border-radius:2px;margin-right:4px;"></span>
                                    <?= __('demand.page.arrived') ?>
                                </label>
                                <label class="mb-0 d-flex align-items-center" style="cursor: pointer; font-size: 0.75rem;">
                                    <input type="checkbox" id="phase_disconnected" checked style="margin-right: 6px;">
                                    <span style="background:#f97316;width:10px;height:10px;display:inline-block;border-radius:2px;margin-right:4px;"></span>
                                    <?= __('demand.page.disconnected') ?>
                                </label>
                                <label class="mb-0 d-flex align-items-center" style="cursor: pointer; font-size: 0.75rem;">
                                    <input type="checkbox" id="phase_controlled" checked style="margin-right: 6px;">
                                    <span style="background:#b45309;width:10px;height:10px;display:inline-block;border-radius:2px;margin-right:4px;"></span>
                                    <?= __('demand.phase.controlled') ?>
                                </label>
                                <label class="mb-0 d-flex align-items-center text-muted" style="cursor: pointer; font-size: 0.75rem;">
                                    <input type="checkbox" id="phase_unknown" style="margin-right: 6px;">
                                    <span style="background:#9333ea;width:10px;height:10px;display:inline-block;border-radius:2px;margin-right:4px;"></span>
                                    <?= __('demand.page.unknown') ?>
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
                        <i class="fas fa-palette mr-1"></i> <?= __('demand.page.legend') ?>
                    </span>
                </div>
                <div class="card-body py-2" id="phase-legend-container">
                    <!-- Airborne Phases -->
                    <div class="legend-group mb-2">
                        <div class="legend-group-title text-muted small mb-1" style="font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.05em;">
                            <i class="fas fa-plane mr-1"></i> <?= __('demand.page.airborne') ?>
                        </div>
                        <div class="d-flex flex-wrap" style="gap: 2px 10px;">
                            <div class="demand-legend-item"><span class="demand-legend-color" style="background-color: #f87171;"></span><?= __('demand.page.departed') ?></div>
                            <div class="demand-legend-item"><span class="demand-legend-color" style="background-color: #dc2626;"></span><?= __('demand.page.enroute') ?></div>
                            <div class="demand-legend-item"><span class="demand-legend-color" style="background-color: #991b1b;"></span><?= __('demand.page.descending') ?></div>
                        </div>
                    </div>
                    <!-- Ground Phases -->
                    <div class="legend-group mb-2">
                        <div class="legend-group-title text-muted small mb-1" style="font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.05em;">
                            <i class="fas fa-road mr-1"></i> <?= __('demand.page.ground') ?>
                        </div>
                        <div class="d-flex flex-wrap" style="gap: 2px 10px;">
                            <div class="demand-legend-item"><span class="demand-legend-color" style="background-color: #3b82f6;"></span><?= __('demand.page.prefile') ?></div>
                            <div class="demand-legend-item"><span class="demand-legend-color" style="background-color: #22c55e;"></span><?= __('demand.page.taxiing') ?></div>
                            <div class="demand-legend-item"><span class="demand-legend-color" style="background-color: #1a1a1a;"></span><?= __('demand.page.arrived') ?></div>
                        </div>
                    </div>
                    <!-- Other -->
                    <div class="legend-group mb-2">
                        <div class="legend-group-title text-muted small mb-1" style="font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.05em;">
                            <i class="fas fa-question-circle mr-1"></i> <?= __('demand.page.other') ?>
                        </div>
                        <div class="d-flex flex-wrap" style="gap: 2px 10px;">
                            <div class="demand-legend-item"><span class="demand-legend-color" style="background-color: #b45309;"></span><?= __('demand.phase.controlled') ?></div>
                            <div class="demand-legend-item"><span class="demand-legend-color" style="background-color: #f97316;"></span><?= __('demand.page.disconnected') ?></div>
                            <div class="demand-legend-item"><span class="demand-legend-color" style="background-color: #6b7280;"></span><?= __('demand.page.exempt') ?></div>
                            <div class="demand-legend-item"><span class="demand-legend-color" style="background-color: #9333ea;"></span><?= __('demand.page.unknown') ?></div>
                        </div>
                    </div>
                    <!-- TMI Statuses -->
                    <div class="legend-group mb-2">
                        <div class="legend-group-title text-muted small mb-1" style="font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.05em;">
                            <i class="fas fa-hand-paper mr-1"></i> <?= __('demand.page.groundStop') ?>
                        </div>
                        <div class="d-flex flex-wrap" style="gap: 2px 10px;">
                            <div class="demand-legend-item"><span class="demand-legend-color" style="background-color: #eab308;"></span><?= __('demand.page.gsEdct') ?></div>
                            <div class="demand-legend-item"><span class="demand-legend-color" style="background-color: #fef08a;"></span><?= __('demand.page.gsSim') ?></div>
                            <div class="demand-legend-item"><span class="demand-legend-color" style="background-color: #ca8a04;"></span><?= __('demand.page.gsProp') ?></div>
                        </div>
                    </div>
                    <div class="legend-group">
                        <div class="legend-group-title text-muted small mb-1" style="font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.05em;">
                            <i class="fas fa-clock mr-1"></i> <?= __('demand.page.groundDelay') ?>
                        </div>
                        <div class="d-flex flex-wrap" style="gap: 2px 10px;">
                            <div class="demand-legend-item"><span class="demand-legend-color" style="background-color: #92400e;"></span><?= __('demand.page.gdpEdct') ?></div>
                            <div class="demand-legend-item"><span class="demand-legend-color" style="background-color: #d4a574;"></span><?= __('demand.page.gdpSim') ?></div>
                            <div class="demand-legend-item"><span class="demand-legend-color" style="background-color: #78350f;"></span><?= __('demand.page.gdpProp') ?></div>
                        </div>
                    </div>
                    <hr class="my-2">
                    <!-- Rate Lines Toggles -->
                    <div class="legend-group">
                        <div class="legend-group-title text-muted small mb-1" style="font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.05em;">
                            <i class="fas fa-minus mr-1"></i> <?= __('demand.page.rateLines') ?>
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
                                    <?= __('demand.page.rwAar') ?>
                                </label>
                                <label class="mb-0 d-flex align-items-center" style="cursor: pointer; font-size: 0.75rem;">
                                    <input type="checkbox" id="rate_rw_adr" checked style="margin-right: 4px;">
                                    <span style="display: inline-block; width: 16px; height: 0; border-top: 2px dashed #00FFFF; margin-right: 4px; vertical-align: middle;"></span>
                                    <?= __('demand.page.rwAdr') ?>
                                </label>
                            </div>
                        </div>
                    </div>
                    <hr class="my-2">
                    <!-- TMI Overlay Toggles -->
                    <div class="legend-group">
                        <div class="legend-group-title text-muted small mb-1" style="font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.05em;">
                            <i class="fas fa-layer-group mr-1"></i> <?= __('demand.tmiToggles.overlays') ?>
                        </div>
                        <div class="d-flex flex-column" style="gap: 4px;">
                            <label class="mb-0 d-flex align-items-center" style="cursor: pointer; font-size: 0.75rem;">
                                <input type="checkbox" id="tmi_toggle_timeline" checked style="margin-right: 4px;">
                                <span style="display: inline-block; width: 16px; height: 4px; background: #ffc107; margin-right: 4px; vertical-align: middle; border-radius: 1px;"></span>
                                <?= __('demand.tmiToggles.timeline') ?>
                            </label>
                            <label class="mb-0 d-flex align-items-center" style="cursor: pointer; font-size: 0.75rem;">
                                <input type="checkbox" id="tmi_toggle_markers" checked style="margin-right: 4px;">
                                <span style="display: inline-block; width: 0; height: 12px; border-left: 2px solid #dc3545; margin-right: 4px; vertical-align: middle;"></span>
                                <?= __('demand.tmiToggles.markers') ?>
                            </label>
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
                            <i class="fas fa-chart-bar mr-1"></i> <?= __('demand.page.demandChart') ?>
                        </span>
                        <div class="demand-header-rates" id="demand_header_rates">
                            <div class="refresh-row" id="demand_last_update">--</div>
                            <div class="rate-row" id="demand_header_aar_row" style="display: none;">
                                <span class="rate-label"><?= __('demand.page.vatsimAar') ?></span> <span class="rate-value" id="header_vatsim_aar">--</span>
                                <span class="rate-separator">|</span>
                                <span class="rate-label"><?= __('demand.page.rwAar') ?></span> <span class="rate-value rw" id="header_rw_aar">--</span>
                            </div>
                            <div class="rate-row" id="demand_header_adr_row" style="display: none;">
                                <span class="rate-label"><?= __('demand.page.vatsimAdr') ?></span> <span class="rate-value" id="header_vatsim_adr">--</span>
                                <span class="rate-separator">|</span>
                                <span class="rate-label"><?= __('demand.page.rwAdr') ?></span> <span class="rate-value rw" id="header_rw_adr">--</span>
                            </div>
                        </div>
                    </div>
                    <!-- Chart View Toggle - on its own row for space -->
                    <div class="d-flex flex-wrap" style="gap: 4px;">
                        <label class="btn btn-outline-light btn-sm demand-view-btn active" title="<?= __('demand.page.showByFlightStatus') ?>">
                            <input type="radio" name="demand_chart_view" id="view_status" value="status" autocomplete="off" checked> <?= __('demand.page.status') ?>
                        </label>
                        <label class="btn btn-outline-light btn-sm demand-view-btn" title="<?= __('demand.page.showArrivalsByOriginArtcc') ?>">
                            <input type="radio" name="demand_chart_view" id="view_origin" value="origin" autocomplete="off"> <?= __('demand.page.origin') ?>
                        </label>
                        <label class="btn btn-outline-light btn-sm demand-view-btn" title="<?= __('demand.page.showDeparturesByDestArtcc') ?>">
                            <input type="radio" name="demand_chart_view" id="view_dest" value="dest" autocomplete="off"> <?= __('demand.page.dest') ?>
                        </label>
                        <label class="btn btn-outline-light btn-sm demand-view-btn" title="<?= __('demand.page.showByCarrier') ?>">
                            <input type="radio" name="demand_chart_view" id="view_carrier" value="carrier" autocomplete="off"> <?= __('demand.page.carrier') ?>
                        </label>
                        <label class="btn btn-outline-light btn-sm demand-view-btn" title="<?= __('demand.page.showByWeightClass') ?>">
                            <input type="radio" name="demand_chart_view" id="view_weight" value="weight" autocomplete="off"> <?= __('demand.page.weight') ?>
                        </label>
                        <label class="btn btn-outline-light btn-sm demand-view-btn" title="<?= __('demand.page.showByAircraftType') ?>">
                            <input type="radio" name="demand_chart_view" id="view_equipment" value="equipment" autocomplete="off"> <?= __('demand.page.equip') ?>
                        </label>
                        <label class="btn btn-outline-light btn-sm demand-view-btn" title="<?= __('demand.page.showByIfrVfr') ?>">
                            <input type="radio" name="demand_chart_view" id="view_rule" value="rule" autocomplete="off"> <?= __('demand.page.rule') ?>
                        </label>
                        <label class="btn btn-outline-light btn-sm demand-view-btn" title="<?= __('demand.page.showDeparturesByDepFix') ?>">
                            <input type="radio" name="demand_chart_view" id="view_dep_fix" value="dep_fix" autocomplete="off"> <?= __('demand.page.depFix') ?>
                        </label>
                        <label class="btn btn-outline-light btn-sm demand-view-btn" title="<?= __('demand.page.showArrivalsByArrFix') ?>">
                            <input type="radio" name="demand_chart_view" id="view_arr_fix" value="arr_fix" autocomplete="off"> <?= __('demand.page.arrFix') ?>
                        </label>
                        <label class="btn btn-outline-light btn-sm demand-view-btn" title="<?= __('demand.page.showDeparturesBySid') ?>">
                            <input type="radio" name="demand_chart_view" id="view_dp" value="dp" autocomplete="off"> DP
                        </label>
                        <label class="btn btn-outline-light btn-sm demand-view-btn" title="<?= __('demand.page.showArrivalsByStar') ?>">
                            <input type="radio" name="demand_chart_view" id="view_star" value="star" autocomplete="off"> STAR
                        </label>
                        <label class="btn btn-outline-light btn-sm demand-view-btn" id="view_airport_label" style="display: none;" title="<?= __('demand.facility.view.airportTooltip') ?>">
                            <input type="radio" name="demand_chart_view" id="view_airport" value="airport" autocomplete="off"> <?= __('demand.facility.view.airport') ?>
                        </label>
                    </div>
                </div>
                <div class="card-body p-2">
                    <!-- Empty State (shown when no airport selected) -->
                    <div id="demand_empty_state" class="demand-empty-state">
                        <i class="fas fa-chart-bar"></i>
                        <h5><?= __('demand.page.noAirportSelected') ?></h5>
                        <p class="text-muted"><?= __('demand.page.selectAirportPrompt') ?></p>
                    </div>

                    <!-- Facility Empty State (shown when demand type is facility but no facility selected) -->
                    <div id="facility_empty_state" class="demand-empty-state" style="display: none;">
                        <i class="fas fa-building"></i>
                        <h5><?= __('demand.facility.empty.selectFacility') ?></h5>
                    </div>

                    <!-- Chart Container (hidden initially) -->
                    <div class="demand-chart-wrapper">
                        <!-- TMI Timeline Bar (GS/GDP programs) -->
                        <div id="demand_tmi_timeline" class="demand-tmi-timeline" style="display: none;">
                            <div class="tmi-timeline-track" id="tmi_timeline_track"></div>
                        </div>
                        <!-- Comparison grid (hidden by default, replaces single chart in comparison mode) -->
                        <div id="demand_chart_grid" style="display: none; gap: 8px;"></div>
                        <div id="demand_chart" class="demand-chart-container" style="display: none;"></div>
                        <div class="chart-loading-overlay" id="chart_loading_overlay">
                            <div class="chart-loading-content">
                                <div class="spinner"></div>
                                <div class="loading-text"><?= __('demand.page.updatingChart') ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Legend Toggle Area -->
                    <div class="demand-legend-toggle-area" id="demand_legend_toggle_area" style="display: none;">
                        <button type="button" class="demand-legend-toggle-btn" id="demand_legend_toggle_btn">
                            <i class="fas fa-eye-slash"></i> <span id="legend_toggle_text"><?= __('demand.page.hideLegend') ?></span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Enhanced Flight Summary Card — 6-Card Grid -->
            <div class="card shadow-sm mt-3 tbfm-chart-card">
                <div class="card-header tbfm-card-header d-flex justify-content-between align-items-center">
                    <span class="demand-section-title">
                        <i class="fas fa-list mr-1"></i> <?= __('demand.page.flightSummary') ?>
                        <span class="badge badge-light ml-2" id="demand_flight_count" style="color: #2c3e50;">0 <?= __('demand.page.flights') ?></span>
                    </span>
                    <button class="btn btn-sm btn-outline-light" id="demand_toggle_flights" type="button" title="<?= __('demand.page.toggleFlightsTooltip') ?>">
                        <i class="fas fa-chevron-down"></i>
                    </button>
                </div>
                <div class="card-body p-2" id="demand_flight_summary" style="display: none;">
                    <div class="row" id="summary_card_grid">
                        <!-- Card 1: Peak Hour -->
                        <div class="col-md-4 mb-2">
                            <div class="border" style="border-color: #bdc3c7; border-radius: 4px; overflow: hidden;">
                                <div style="background: #2c3e50; color: #fff; padding: 4px 8px; font-weight: 700; font-size: 9px; text-transform: uppercase; letter-spacing: 0.05em;">
                                    <i class="fas fa-clock mr-1"></i> <?= __('demand.summary.peakHour') ?>
                                </div>
                                <div style="padding: 6px 8px;" id="summary_peak_hour">
                                    <span class="text-muted small">--</span>
                                </div>
                            </div>
                        </div>
                        <!-- Card 2: TMI Control -->
                        <div class="col-md-4 mb-2">
                            <div class="border" style="border-color: #bdc3c7; border-radius: 4px; overflow: hidden;">
                                <div style="background: #2c3e50; color: #fff; padding: 4px 8px; font-weight: 700; font-size: 9px; text-transform: uppercase; letter-spacing: 0.05em;">
                                    <i class="fas fa-hand-paper mr-1"></i> <?= __('demand.summary.tmiControl') ?>
                                </div>
                                <div style="padding: 6px 8px;" id="summary_tmi_control">
                                    <span class="text-muted small">--</span>
                                </div>
                            </div>
                        </div>
                        <!-- Card 3: Weight Mix -->
                        <div class="col-md-4 mb-2">
                            <div class="border" style="border-color: #bdc3c7; border-radius: 4px; overflow: hidden;">
                                <div style="background: #2c3e50; color: #fff; padding: 4px 8px; font-weight: 700; font-size: 9px; text-transform: uppercase; letter-spacing: 0.05em;">
                                    <i class="fas fa-balance-scale mr-1"></i> <?= __('demand.summary.weightMix') ?>
                                </div>
                                <div style="padding: 6px 8px;" id="summary_weight_mix">
                                    <span class="text-muted small">--</span>
                                </div>
                            </div>
                        </div>
                        <!-- Card 4: Top Origins -->
                        <div class="col-md-4 mb-2">
                            <div class="border" style="border-color: #bdc3c7; border-radius: 4px; overflow: hidden;">
                                <div style="background: #2c3e50; color: #fff; padding: 4px 8px; font-weight: 700; font-size: 9px; text-transform: uppercase; letter-spacing: 0.05em;">
                                    <i class="fas fa-map-marker-alt mr-1"></i> <?= __('demand.summary.topOrigins') ?>
                                </div>
                                <div style="padding: 4px 8px;" id="summary_top_origins">
                                    <span class="text-muted small">--</span>
                                </div>
                            </div>
                        </div>
                        <!-- Card 5: Top Carriers -->
                        <div class="col-md-4 mb-2">
                            <div class="border" style="border-color: #bdc3c7; border-radius: 4px; overflow: hidden;">
                                <div style="background: #2c3e50; color: #fff; padding: 4px 8px; font-weight: 700; font-size: 9px; text-transform: uppercase; letter-spacing: 0.05em;">
                                    <i class="fas fa-plane mr-1"></i> <?= __('demand.summary.topCarriers') ?>
                                </div>
                                <div style="padding: 4px 8px;" id="summary_top_carriers">
                                    <span class="text-muted small">--</span>
                                </div>
                            </div>
                        </div>
                        <!-- Card 6: Top Fixes -->
                        <div class="col-md-4 mb-2">
                            <div class="border" style="border-color: #bdc3c7; border-radius: 4px; overflow: hidden;">
                                <div style="background: #2c3e50; color: #fff; padding: 4px 8px; font-weight: 700; font-size: 9px; text-transform: uppercase; letter-spacing: 0.05em;">
                                    <i class="fas fa-thumbtack mr-1"></i> <span id="summary_fixes_title"><?= __('demand.summary.topArrFixes') ?></span>
                                </div>
                                <div style="padding: 4px 8px;" id="summary_top_fixes">
                                    <span class="text-muted small">--</span>
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
<script src="assets/js/demand.js<?= _v('assets/js/demand.js') ?>"></script>

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
            statusEl.text('<?= __('demand.page.paused') ?>').removeClass('demand-status-active').addClass('demand-status-paused');
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
        <span class="panel-title"><i class="fas fa-filter mr-1"></i> <?= __('demand.page.flightStatus') ?></span>
        <div class="panel-controls">
            <button type="button" class="panel-btn" id="phase-filter-collapse-btn" title="<?= __('demand.page.collapse') ?>">
                <i class="fas fa-minus"></i>
            </button>
            <button type="button" class="panel-btn" id="phase-filter-close-btn" title="<?= __('demand.page.close') ?>">
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
