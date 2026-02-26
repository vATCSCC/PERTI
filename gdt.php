<?php
include("sessions/handler.php");
include("load/config.php");
include("load/i18n.php");
?>
<!DOCTYPE html>
<html lang="en">
<head>

    <?php $page_title = __('gdt.page.title'); include("load/header.php"); ?>

    <!-- Info Bar Shared Styles -->
    <link rel="stylesheet" href="assets/css/info-bar.css<?= _v('assets/css/info-bar.css') ?>">

    <style>
        .tmi-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 600;
            color: #333;
        }

        .tmi-section-title {
            font-weight: 600;
            font-size: 0.9rem;
            text-transform: uppercase;
        }

        /* Default dark text for section titles in light backgrounds */
        .card-header .tmi-section-title {
            color: #333;
        }

        /* White text for section titles in dark/colored backgrounds */
        .card-header.bg-primary .tmi-section-title,
        .card-header.bg-secondary .tmi-section-title,
        .card-header.bg-info .tmi-section-title,
        .card-header.bg-success .tmi-section-title,
        .card-header.bg-dark .tmi-section-title,
        .card-header.text-white .tmi-section-title {
            color: #fff;
        }

        .tmi-advisory-preview {
            white-space: pre;
            font-family: "Inconsolata", monospace;
            font-size: 0.8rem;
            color: #333;
        }

        .tmi-flight-table {
            font-size: 0.8rem;
        }

        .tmi-badge-status {
            font-size: 0.7rem;
            text-transform: uppercase;
        }

        .tmi-airport-badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-right: 4px;
            margin-bottom: 2px;
            color: #000;
        }

        .tmi-airports-input {
            transition: color 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .tmi-scope-select {
            height: 140px;
        }

        .tmi-scope-help {
            font-size: 0.7rem;
        }

        /* Ensure proper text contrast for card headers */
        .card-header {
            color: #333;
        }

        .card-header.bg-light {
            color: #333;
        }

        .card-header.bg-light .tmi-label {
            color: #333;
        }

        .card-header.bg-warning {
            color: #333;
        }

        .card-header.bg-warning .tmi-label,
        .card-header.bg-warning .font-weight-bold,
        .card-header.bg-warning small {
            color: #333;
        }

        /* Ensure secondary headers keep white text */
        .card-header.bg-secondary {
            color: #fff;
        }

        .card-header.bg-secondary .tmi-label {
            color: #fff;
        }

        /* Ensure info headers keep white text */
        .card-header.bg-info {
            color: #fff;
        }

        .card-header.bg-info .tmi-label {
            color: #fff;
        }

        /* Ensure primary headers keep white text */
        .card-header.bg-primary {
            color: #fff;
        }

        .card-header.bg-primary .tmi-label {
            color: #fff;
        }

        /* Ensure success headers keep white text */
        .card-header.bg-success {
            color: #fff;
        }

        /* Fix text-muted on dark table headers */
        .thead-dark .text-muted {
            color: rgba(255, 255, 255, 0.7) !important;
        }

        /* Ensure table header text is readable */
        .tmi-flight-table thead th {
            color: #333;
        }

        .tmi-flight-table .thead-dark th {
            color: #fff;
        }

        .tmi-flight-table .thead-light th {
            color: #333;
        }

        /* Badge text contrast fixes */
        .badge-warning {
            color: #333 !important;
        }

        /* Ensure text-warning is visible */
        .tmi-flight-table .text-warning {
            color: #c67c00 !important;
        }

        /* Ensure table text-muted is visible */
        .tmi-flight-table .text-muted {
            color: #6c757d !important;
        }

        /* Origin/Destination Analysis labels */
        .text-uppercase.text-muted.font-weight-bold {
            color: #555 !important;
        }

        /* Modal header text fixes */
        .modal-header.bg-success .modal-title {
            color: #fff;
        }

        .modal-header.bg-success .close {
            color: #fff;
        }

        /* Summary stats table readability */
        .table-borderless td {
            color: #333;
        }

        /* Small text readability in cards */
        .card-body .small,
        .card-body small {
            color: #333;
        }

        /* Pre/code readability */
        pre {
            color: #333;
        }

        /* ========== Active Programs Dashboard ========== */
        .gdt-program-card {
            min-width: 260px;
            max-width: 340px;
            flex: 1 1 260px;
            border-radius: 6px;
            border: 1px solid #dee2e6;
            padding: 10px 14px;
            background: #fff;
            cursor: pointer;
            transition: border-color 0.15s, box-shadow 0.15s;
            position: relative;
        }

        .gdt-program-card:hover {
            border-color: #007bff;
            box-shadow: 0 0 0 2px rgba(0,123,255,0.15);
        }

        .gdt-program-card.selected {
            border-color: #007bff;
            box-shadow: 0 0 0 2px rgba(0,123,255,0.25);
        }

        .gdt-program-card .gdt-card-type {
            display: inline-block;
            padding: 1px 6px;
            border-radius: 3px;
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            color: #fff;
        }

        .gdt-card-type-gs { background: #dc3545; }
        .gdt-card-type-gdp { background: #e67e22; }
        .gdt-card-type-afp { background: #007bff; }

        .gdt-program-card .gdt-card-status {
            display: inline-block;
            padding: 1px 5px;
            border-radius: 3px;
            font-size: 0.6rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .gdt-card-status-active { background: #d4edda; color: #155724; }
        .gdt-card-status-modeling { background: #fff3cd; color: #856404; }
        .gdt-card-status-proposed { background: #cce5ff; color: #004085; }
        .gdt-card-status-transitioned { background: #e2e3e5; color: #383d41; }
        .gdt-card-status-cancelled { background: #f8d7da; color: #721c24; }
        .gdt-card-status-completed { background: #e2e3e5; color: #383d41; }

        .gdt-program-card .gdt-card-element {
            font-size: 1.1rem;
            font-weight: 700;
            color: #333;
        }

        .gdt-program-card .gdt-card-artcc {
            font-size: 0.75rem;
            color: #6c757d;
        }

        .gdt-program-card .gdt-card-time {
            font-size: 0.75rem;
            font-family: 'Inconsolata', monospace;
            color: #555;
        }

        .gdt-program-card .gdt-card-progress {
            height: 4px;
            border-radius: 2px;
            background: #e9ecef;
            margin-top: 6px;
        }

        .gdt-program-card .gdt-card-progress-bar {
            height: 100%;
            border-radius: 2px;
            background: #28a745;
            transition: width 0.3s ease;
        }

        .gdt-program-card .gdt-card-metrics {
            display: flex;
            gap: 12px;
            margin-top: 6px;
            font-size: 0.7rem;
            color: #555;
        }

        .gdt-program-card .gdt-card-metric-value {
            font-weight: 700;
            color: #333;
        }

        .gdt-program-card .gdt-card-actions {
            display: flex;
            gap: 4px;
            margin-top: 8px;
        }

        .gdt-program-card .gdt-card-actions .btn {
            font-size: 0.65rem;
            padding: 1px 6px;
        }

        /* ========== Workflow Stepper ========== */
        .gdt-step {
            display: flex;
            flex-direction: column;
            align-items: center;
            min-width: 80px;
        }

        .gdt-step-circle {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #e9ecef;
            color: #6c757d;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.85rem;
            border: 2px solid #dee2e6;
            transition: all 0.2s ease;
        }

        .gdt-step.active .gdt-step-circle {
            background: #007bff;
            color: #fff;
            border-color: #007bff;
        }

        .gdt-step.completed .gdt-step-circle {
            background: #28a745;
            color: #fff;
            border-color: #28a745;
        }

        .gdt-step.completed .gdt-step-circle::after {
            content: '\f00c';
            font-family: 'Font Awesome 5 Free';
            font-weight: 900;
        }

        .gdt-step-label {
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            color: #adb5bd;
            margin-top: 4px;
            letter-spacing: 0.03em;
        }

        .gdt-step.active .gdt-step-label {
            color: #007bff;
        }

        .gdt-step.completed .gdt-step-label {
            color: #28a745;
        }

        .gdt-step-line {
            flex: 1;
            height: 2px;
            background: #dee2e6;
            margin: 14px 8px 0;
            min-width: 40px;
            max-width: 120px;
            transition: background 0.2s ease;
        }

        .gdt-step-line.completed {
            background: #28a745;
        }

        /* Icon-only button sizing fix */
        /* Ensures icon buttons in btn-group-sm have minimum width for visibility */
        .btn-group-sm > .btn:has(> i.fas):not(:has(span)):not(:has(.sr-only)) {
            min-width: 32px;
            padding-left: 0.5rem;
            padding-right: 0.5rem;
        }

        /* Fallback for browsers without :has() support */
        #gs_view_flight_list_btn,
        #gs_open_model_btn {
            min-width: 36px;
            padding: 0.25rem 0.5rem;
        }

        /* Ensure FontAwesome icons are visible */
        #gs_view_flight_list_btn i,
        #gs_open_model_btn i {
            font-size: 0.875rem;
        }
    </style>

</head>

<body>

<?php include("load/nav_public.php"); ?>

<section class="perti-hero perti-hero--dark-tool" data-jarallax data-speed="0.3">
    <div class="container-fluid pt-2 pb-4 py-lg-5">
        <img class="jarallax-img" src="assets/img/jumbotron/main.png" alt="" style="opacity: 50%;">

        <center>
            <h1><?= __('gdt.page.title') ?></h1>
            <h4 class="text-white hvr-bob pl-1">
                <a href="#gs_section" style="text-decoration: none; color: #fff;">
                    <i class="fas fa-chevron-down text-danger"></i>
                    <?= __('gdt.page.gsCoordination') ?>
                </a>
            </h4>
        </center>
    </div>
</section>

<div class="container-fluid mt-3 mb-5" id="gs_section">
    <!-- Info Bar: UTC Clock, Local Times, Flight Stats -->
    <div class="perti-info-bar mb-3">
        <div class="row d-flex flex-wrap align-items-stretch" style="gap: 8px; margin: 0 -4px;">
            <!-- Current Time (UTC) -->
            <div class="col-auto px-1">
                <div class="card shadow-sm perti-info-card perti-card-utc h-100">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <div class="perti-info-label"><?= __('gdt.page.currentUtc') ?></div>
                            <div id="tmi_utc_clock" class="perti-clock-display perti-clock-display-lg"></div>
                        </div>
                        <div class="ml-3">
                            <i class="far fa-clock fa-lg text-primary"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Current Time (US Time Zones) -->
            <div class="col-auto px-1">
                <div class="card shadow-sm perti-info-card perti-card-local h-100">
                    <div class="card-body">
                        <div class="perti-info-label mb-1"><?= __('gdt.page.usLocalTimes') ?></div>
                        <div class="perti-clock-grid">
                            <div class="perti-clock-item">
                                <div class="perti-clock-tz">GM</div>
                                <div id="tmi_clock_guam" class="perti-clock-display perti-clock-display-md"></div>
                            </div>
                            <div class="perti-clock-item">
                                <div class="perti-clock-tz">HI</div>
                                <div id="tmi_clock_hi" class="perti-clock-display perti-clock-display-md"></div>
                            </div>
                            <div class="perti-clock-item">
                                <div class="perti-clock-tz">AK</div>
                                <div id="tmi_clock_ak" class="perti-clock-display perti-clock-display-md"></div>
                            </div>
                            <div class="perti-clock-item">
                                <div class="perti-clock-tz">PT</div>
                                <div id="tmi_clock_pac" class="perti-clock-display perti-clock-display-md"></div>
                            </div>
                            <div class="perti-clock-item">
                                <div class="perti-clock-tz">MT</div>
                                <div id="tmi_clock_mtn" class="perti-clock-display perti-clock-display-md"></div>
                            </div>
                            <div class="perti-clock-item">
                                <div class="perti-clock-tz">CT</div>
                                <div id="tmi_clock_cent" class="perti-clock-display perti-clock-display-md"></div>
                            </div>
                            <div class="perti-clock-item">
                                <div class="perti-clock-tz">ET</div>
                                <div id="tmi_clock_east" class="perti-clock-display perti-clock-display-md"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Global Flight Counts -->
            <div class="col-auto px-1">
                <div class="card shadow-sm perti-info-card perti-card-global h-100">
                    <div class="card-body">
                        <div class="perti-info-label mb-1">
                            <?= __('gdt.page.globalFlights') ?>
                            <span id="tmi_stats_global_total" class="badge badge-info badge-total ml-1">-</span>
                        </div>
                        <div class="perti-stat-grid">
                            <div class="perti-stat-item">
                                <div class="perti-stat-category">D→D</div>
                                <div id="tmi_stats_dd" class="perti-stat-value">-</div>
                            </div>
                            <div class="perti-stat-item">
                                <div class="perti-stat-category">D→I</div>
                                <div id="tmi_stats_di" class="perti-stat-value">-</div>
                            </div>
                            <div class="perti-stat-item">
                                <div class="perti-stat-category">I→D</div>
                                <div id="tmi_stats_id" class="perti-stat-value">-</div>
                            </div>
                            <div class="perti-stat-item">
                                <div class="perti-stat-category">I→I</div>
                                <div id="tmi_stats_ii" class="perti-stat-value">-</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Domestic Flight Counts -->
            <div class="col-auto px-1">
                <div class="card shadow-sm perti-info-card perti-card-domestic h-100">
                    <div class="card-body">
                        <div class="perti-info-label mb-1">
                            <?= __('gdt.page.domesticArrivals') ?>
                            <span id="tmi_stats_domestic_total" class="badge badge-success badge-total ml-1">-</span>
                        </div>
                        <div class="d-flex">
                            <!-- By DCC Region -->
                            <div class="perti-stat-section">
                                <div class="perti-info-sublabel"><?= __('gdt.page.dccRegion') ?></div>
                                <div class="perti-badge-group">
                                    <span class="badge badge-light" title="<?= __('gdt.page.northeastTooltip') ?>"><strong>NE</strong> <span id="tmi_stats_dcc_ne">-</span></span>
                                    <span class="badge badge-light" title="<?= __('gdt.page.southeastTooltip') ?>"><strong>SE</strong> <span id="tmi_stats_dcc_se">-</span></span>
                                    <span class="badge badge-light" title="<?= __('gdt.page.midwestTooltip') ?>"><strong>MW</strong> <span id="tmi_stats_dcc_mw">-</span></span>
                                    <span class="badge badge-light" title="<?= __('gdt.page.southCentralTooltip') ?>"><strong>SC</strong> <span id="tmi_stats_dcc_sc">-</span></span>
                                    <span class="badge badge-light" title="<?= __('gdt.page.westTooltip') ?>"><strong>W</strong> <span id="tmi_stats_dcc_w">-</span></span>
                                </div>
                            </div>
                            <!-- By Airport Tier -->
                            <div class="perti-stat-section">
                                <div class="perti-info-sublabel"><?= __('gdt.page.airportTier') ?></div>
                                <div class="perti-badge-group">
                                    <span class="badge badge-warning text-dark" title="<?= __('gdt.page.aspm82Tooltip') ?>"><strong>ASPM82</strong> <span id="tmi_stats_aspm82">-</span></span>
                                    <span class="badge badge-primary" title="<?= __('gdt.page.oep35Tooltip') ?>"><strong>OEP35</strong> <span id="tmi_stats_oep35">-</span></span>
                                    <span class="badge badge-danger" title="<?= __('gdt.page.core30Tooltip') ?>"><strong>Core30</strong> <span id="tmi_stats_core30">-</span></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Spacer -->
            <div class="col"></div>

            <!-- Advisory Org Config -->
            <div class="col-auto px-1">
                <div class="card shadow-sm perti-info-card h-100">
                    <div class="card-body d-flex justify-content-between align-items-center py-2 px-3">
                        <button class="btn btn-sm btn-outline-secondary" onclick="AdvisoryConfig.showConfigModal();" data-toggle="tooltip" title="<?= __('gdt.page.advisoryFormatTooltip') ?>">
                            <i class="fas fa-globe mr-1"></i> <span id="advisoryOrgDisplay">DCC</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Active Programs Dashboard -->
    <div id="gdt_dashboard" class="mb-3" style="display: none;">
        <div class="card shadow-sm">
            <div class="card-header bg-dark text-white py-2 d-flex justify-content-between align-items-center"
                 style="cursor: pointer;" onclick="toggleDashboard();">
                <span class="tmi-section-title">
                    <i class="fas fa-tachometer-alt mr-1"></i> <?= __('gdt.page.activePrograms') ?>
                    <span id="gdt_dashboard_count" class="badge badge-light ml-1" style="display: none;">0</span>
                </span>
                <div>
                    <button class="btn btn-sm btn-outline-light mr-1" id="gdt_new_program_btn"
                            onclick="event.stopPropagation(); resetAndNewProgram();"
                            data-toggle="tooltip" title="<?= __('gdt.page.createProgramTooltip') ?>">
                        <i class="fas fa-plus mr-1"></i> <?= __('gdt.page.newProgram') ?>
                    </button>
                    <i class="fas fa-chevron-down" id="gdt_dashboard_chevron"></i>
                </div>
            </div>
            <div class="card-body py-2 px-3" id="gdt_dashboard_body">
                <!-- No programs message -->
                <div id="gdt_dashboard_empty" class="text-center text-muted py-2" style="display: none;">
                    <i class="fas fa-check-circle mr-1"></i> <?= __('gdt.page.noActivePrograms') ?>
                </div>
                <!-- Program cards container -->
                <div id="gdt_dashboard_cards" class="d-flex flex-wrap" style="gap: 10px;">
                </div>
                <!-- Summary row -->
                <div id="gdt_dashboard_summary" class="mt-2 pt-2 border-top small text-muted" style="display: none;">
                    <i class="fas fa-plane mr-1"></i>
                    <span id="gdt_dashboard_total_controlled">0</span> <?= __('gdt.page.controlledAcrossAll') ?>
                    <span class="mx-2">|</span>
                    <i class="fas fa-clock mr-1"></i> <?= __('gdt.page.lastRefreshed') ?> <span id="gdt_dashboard_refresh_time">-</span>
                    <span class="mx-2">|</span>
                    <a href="#" onclick="event.preventDefault(); toggleTimeline();" class="text-info">
                        <i class="fas fa-chart-bar mr-1"></i><span id="gdt_timeline_toggle_text"><?= __('gdt.page.showTimeline') ?></span>
                    </a>
                </div>
                <!-- Multi-Program Timeline -->
                <div id="gdt_timeline_container" class="mt-2" style="display: none;">
                    <div style="position: relative; height: 120px; max-height: 200px;">
                        <canvas id="gdt_timeline_canvas"></canvas>
                    </div>
                    <div id="gdt_timeline_conflicts" class="mt-1 small"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Workflow Stepper -->
    <div id="gdt_stepper" class="mb-3">
        <div class="d-flex align-items-center justify-content-center py-2">
            <div class="gdt-step active" id="gdt_step_1" data-step="configure">
                <div class="gdt-step-circle">1</div>
                <div class="gdt-step-label"><?= __('gdt.page.stepConfigure') ?></div>
            </div>
            <div class="gdt-step-line" id="gdt_step_line_1"></div>
            <div class="gdt-step" id="gdt_step_2" data-step="preview">
                <div class="gdt-step-circle">2</div>
                <div class="gdt-step-label"><?= __('gdt.page.stepPreview') ?></div>
            </div>
            <div class="gdt-step-line" id="gdt_step_line_2"></div>
            <div class="gdt-step" id="gdt_step_3" data-step="model">
                <div class="gdt-step-circle">3</div>
                <div class="gdt-step-label"><?= __('gdt.page.stepModel') ?></div>
            </div>
            <div class="gdt-step-line" id="gdt_step_line_3"></div>
            <div class="gdt-step" id="gdt_step_4" data-step="active">
                <div class="gdt-step-circle">4</div>
                <div class="gdt-step-label"><?= __('gdt.page.stepActive') ?></div>
            </div>
            <!-- What-If badge and discard button (hidden by default) -->
            <span class="badge badge-warning ml-3 d-none" id="gdt_whatif_badge" style="font-size:0.75rem; vertical-align: middle;">WHAT-IF MODE</span>
            <button class="btn btn-sm btn-outline-secondary ml-2 d-none" id="gdt_whatif_discard_btn" onclick="exitWhatIfMode();" style="vertical-align: middle;">
                <i class="fas fa-undo mr-1"></i> <?= __('gdt.page.discardWhatIf') ?>
            </button>
        </div>
    </div>

    <div class="row">
        <!-- Left: Ground Stop editor -->
        <div class="col-lg-6 mb-4">
            <div class="card shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span class="tmi-section-title" id="gs_setup_header_label">
                        <i class="fas fa-clock mr-1 text-primary"></i> Program Setup
                    </span>
                    <span class="badge badge-secondary tmi-badge-status" id="gs_status_badge">Draft (local)</span>
                </div>

                <div class="card-body">

                    <!-- Program Type & Element -->
                    <div class="form-row">
                        <div class="form-group col-md-3">
                            <label class="tmi-label mb-0" for="gs_program_type">Program Type</label>
                            <select class="form-control form-control-sm" id="gs_program_type">
                                <option value="GS" selected>Ground Stop</option>
                                <option value="GDP-DAS">GDP (DAS)</option>
                                <option value="GDP-GAAP">GDP (GAAP)</option>
                                <option value="GDP-UDP">GDP (UDP)</option>
                            </select>
                        </div>
                        <div class="form-group col-md-3">
                            <label class="tmi-label mb-0" for="gs_ctl_element"><?= __('gdt.page.ctlElement') ?></label>
                            <input type="text" class="form-control form-control-sm" id="gs_ctl_element"
                                   placeholder="<?= __('gdt.page.placeholderAirport') ?>">
                        </div>
                        <div class="form-group col-md-3">
                            <label class="tmi-label mb-0" for="gs_element_type"><?= __('gdt.page.elementType') ?></label>
                            <select class="form-control form-control-sm" id="gs_element_type">
                                <option value="APT" selected>APT</option>
                                <option value="CTR">CTR</option>
                            </select>
                        </div>
                        <div class="form-group col-md-3">
                            <label class="tmi-label mb-0" for="gs_adv_number"><?= __('gdt.page.advNumber') ?></label>
                            <input type="text" class="form-control form-control-sm" id="gs_adv_number"
                                   placeholder="Auto-assigned" readonly>
                        </div>
                    </div>

                    <!-- GDP-specific rate/delay fields (hidden for GS) -->
                    <div class="form-row" id="gs_gdp_rate_row" style="display: none;">
                        <div class="form-group col-md-4">
                            <label class="tmi-label mb-0" for="gs_program_rate">Acceptance Rate (AAR)</label>
                            <input type="number" class="form-control form-control-sm" id="gs_program_rate"
                                   placeholder="e.g., 30" min="1" max="120">
                            <small class="form-text text-muted">Arrivals per hour</small>
                        </div>
                        <div class="form-group col-md-4">
                            <label class="tmi-label mb-0" for="gs_delay_limit">Max Delay (min)</label>
                            <input type="number" class="form-control form-control-sm" id="gs_delay_limit"
                                   placeholder="e.g., 180" min="0" max="600" value="180">
                            <small class="form-text text-muted">Delay cap in minutes</small>
                        </div>
                        <div class="form-group col-md-4">
                            <label class="tmi-label mb-0" for="gs_reserve_rate">Reserve Rate</label>
                            <input type="number" class="form-control form-control-sm" id="gs_reserve_rate"
                                   placeholder="e.g., 5" min="0" max="30">
                            <small class="form-text text-muted">Reserved slots/hour (GAAP/UDP)</small>
                        </div>
                    </div>

                    <!-- Period (treated as UTC by JS) -->
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label class="tmi-label mb-0" for="gs_start"><?= __('gdt.page.gsStartUtc') ?></label>
                            <input type="datetime-local" class="form-control form-control-sm" id="gs_start">
                        </div>
                        <div class="form-group col-md-6">
                            <label class="tmi-label mb-0" for="gs_end"><?= __('gdt.page.gsEndUtc') ?></label>
                            <input type="datetime-local" class="form-control form-control-sm" id="gs_end">
                        </div>
                    </div>

                    <!-- Airports & scope -->
                    <div class="form-group">
                        <label class="tmi-label mb-0" for="gs_airports"><?= __('gdt.page.arrivalAirports') ?></label>
                        <input type="text" class="form-control form-control-sm tmi-airports-input" id="gs_airports"
                               placeholder="<?= __('gdt.page.placeholderAirportsList') ?>">
                        <small class="form-text text-muted">
                            <?= __('gdt.page.arrivalAirportsHelp') ?>
                        </small>
                        <div id="gs_airports_legend" class="mt-1"></div>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label class="tmi-label mb-0" for="gs_scope_select"><?= __('gdt.page.originCentersScope') ?></label>
                            <select multiple class="form-control form-control-sm tmi-scope-select" id="gs_scope_select"></select>
                            <input type="hidden" id="gs_origin_centers">
                            <small class="form-text text-muted tmi-scope-help">
                                <?= __('gdt.page.originCentersHelp') ?>
                            </small>
                        </div>
                        <div class="form-group col-md-6">
                            <label class="tmi-label mb-0" for="gs_origin_airports"><?= __('gdt.page.originAirports') ?></label>
                            <input type="text" class="form-control form-control-sm" id="gs_origin_airports"
                                   placeholder="<?= __('gdt.page.placeholderOriginList') ?>">
                        </div>
                    </div>

                    <!-- Flight inclusion -->
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label class="tmi-label mb-0" for="gs_flt_incl_carrier"><?= __('gdt.page.fltInclCarrier') ?></label>
                            <input type="text" class="form-control form-control-sm" id="gs_flt_incl_carrier"
                                   placeholder="<?= __('gdt.page.placeholderCarriers') ?>">
                        </div>
                        <div class="form-group col-md-4">
                            <label class="tmi-label mb-0" for="gs_flt_incl_type"><?= __('gdt.page.aircraftType') ?></label>
                            <select class="form-control form-control-sm" id="gs_flt_incl_type">
                                <option value="ALL" selected>ALL</option>
                                <option value="JET">JET</option>
                                <option value="PROP">PROP</option>
                            </select>
                        </div>
                        <div class="form-group col-md-4">
                            <label class="tmi-label mb-0" for="gs_dep_facilities"><?= __('gdt.page.depFacilitiesIncluded') ?></label>
                            <input type="text" class="form-control form-control-sm" id="gs_dep_facilities"
                                   placeholder="<?= __('gdt.page.placeholderFacilities') ?>">
                        </div>
                    </div>

                    <!-- Probability / Impacting Condition -->
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label class="tmi-label mb-0" for="gs_prob_ext"><?= __('gdt.page.probabilityOfExtension') ?></label>
                            <input type="text" class="form-control form-control-sm" id="gs_prob_ext"
                                   placeholder="<?= __('gdt.page.placeholderProbability') ?>">
                        </div>
                        <div class="form-group col-md-8">
                            <label class="tmi-label mb-0" for="gs_impacting_condition"><?= __('gdt.page.impactingCondition') ?></label>
                            <input type="text" class="form-control form-control-sm" id="gs_impacting_condition"
                                   placeholder="<?= __('gdt.page.placeholderConstraint') ?>">
                        </div>
                    </div>

                    <!-- Flight Exemptions Section (Collapsible) -->
                    <div class="card border-warning mb-3">
                        <div class="card-header py-1 px-2 bg-warning text-dark d-flex justify-content-between align-items-center" 
                             data-toggle="collapse" data-target="#gs_exemptions_body" style="cursor: pointer;">
                            <span class="tmi-label mb-0">
                                <i class="fas fa-shield-alt mr-1"></i> <?= __('gdt.page.flightExemptions') ?>
                                <span class="badge badge-dark ml-2" id="gs_exemption_count_badge">0 rules</span>
                            </span>
                            <i class="fas fa-chevron-down" id="gs_exemptions_toggle_icon"></i>
                        </div>
                        <div class="collapse" id="gs_exemptions_body">
                            <div class="card-body py-2">
                                <small class="text-muted d-block mb-2">
                                    <i class="fas fa-info-circle"></i> <?= __('gdt.page.exemptionInfo') ?>
                                </small>

                                <!-- Origin Exemptions -->
                                <div class="border rounded p-2 mb-2 bg-light">
                                    <div class="tmi-label text-primary mb-1"><i class="fas fa-plane-departure mr-1"></i> <?= __('gdt.page.originExemptions') ?></div>
                                    <div class="form-row">
                                        <div class="form-group col-md-4 mb-1">
                                            <label class="small mb-0"><?= __('gdt.page.exemptAirports') ?></label>
                                            <input type="text" class="form-control form-control-sm" id="gs_exempt_orig_airports"
                                                   placeholder="<?= __('gdt.page.placeholderArrAirports') ?>">
                                        </div>
                                        <div class="form-group col-md-4 mb-1">
                                            <label class="small mb-0"><?= __('gdt.page.exemptTracons') ?></label>
                                            <input type="text" class="form-control form-control-sm" id="gs_exempt_orig_tracons"
                                                   placeholder="<?= __('gdt.page.placeholderMeters') ?>">
                                        </div>
                                        <div class="form-group col-md-4 mb-1">
                                            <label class="small mb-0"><?= __('gdt.page.exemptArtccs') ?></label>
                                            <input type="text" class="form-control form-control-sm" id="gs_exempt_orig_artccs"
                                                   placeholder="<?= __('gdt.page.placeholderImpactFacilities') ?>">
                                        </div>
                                    </div>
                                </div>

                                <!-- Destination Exemptions -->
                                <div class="border rounded p-2 mb-2 bg-light">
                                    <div class="tmi-label text-primary mb-1"><i class="fas fa-plane-arrival mr-1"></i> <?= __('gdt.page.destinationExemptions') ?></div>
                                    <div class="form-row">
                                        <div class="form-group col-md-4 mb-1">
                                            <label class="small mb-0"><?= __('gdt.page.exemptAirports') ?></label>
                                            <input type="text" class="form-control form-control-sm" id="gs_exempt_dest_airports"
                                                   placeholder="<?= __('gdt.page.placeholderImpactArrivals') ?>">
                                        </div>
                                        <div class="form-group col-md-4 mb-1">
                                            <label class="small mb-0"><?= __('gdt.page.exemptTracons') ?></label>
                                            <input type="text" class="form-control form-control-sm" id="gs_exempt_dest_tracons"
                                                   placeholder="<?= __('gdt.page.placeholderImpactTracons') ?>">
                                        </div>
                                        <div class="form-group col-md-4 mb-1">
                                            <label class="small mb-0"><?= __('gdt.page.exemptArtccs') ?></label>
                                            <input type="text" class="form-control form-control-sm" id="gs_exempt_dest_artccs"
                                                   placeholder="<?= __('gdt.page.placeholderImpactArtccs') ?>">
                                        </div>
                                    </div>
                                </div>

                                <!-- Aircraft Type & Status Exemptions -->
                                <div class="border rounded p-2 mb-2 bg-light">
                                    <div class="tmi-label text-primary mb-1"><i class="fas fa-fighter-jet mr-1"></i> <?= __('gdt.page.aircraftStatusExemptions') ?></div>
                                    <div class="form-row">
                                        <div class="form-group col-md-4 mb-1">
                                            <label class="small mb-0"><?= __('gdt.page.exemptAircraftTypes') ?></label>
                                            <div class="d-flex flex-wrap">
                                                <div class="custom-control custom-checkbox mr-3">
                                                    <input type="checkbox" class="custom-control-input" id="gs_exempt_type_jet">
                                                    <label class="custom-control-label small" for="gs_exempt_type_jet"><?= __('gdt.page.jet') ?></label>
                                                </div>
                                                <div class="custom-control custom-checkbox mr-3">
                                                    <input type="checkbox" class="custom-control-input" id="gs_exempt_type_turboprop">
                                                    <label class="custom-control-label small" for="gs_exempt_type_turboprop"><?= __('gdt.page.turboprop') ?></label>
                                                </div>
                                                <div class="custom-control custom-checkbox">
                                                    <input type="checkbox" class="custom-control-input" id="gs_exempt_type_prop">
                                                    <label class="custom-control-label small" for="gs_exempt_type_prop"><?= __('gdt.page.prop') ?></label>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="form-group col-md-4 mb-1">
                                            <label class="small mb-0"><?= __('gdt.page.flightStatusExemptions') ?></label>
                                            <div class="d-flex flex-wrap">
                                                <div class="custom-control custom-checkbox mr-3">
                                                    <input type="checkbox" class="custom-control-input" id="gs_exempt_has_edct">
                                                    <label class="custom-control-label small" for="gs_exempt_has_edct"><?= __('gdt.page.alreadyHasEdct') ?></label>
                                                </div>
                                                <div class="custom-control custom-checkbox">
                                                    <input type="checkbox" class="custom-control-input" id="gs_exempt_active_only">
                                                    <label class="custom-control-label small" for="gs_exempt_active_only"><?= __('gdt.page.activeFlightsOnly') ?></label>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="form-group col-md-4 mb-1">
                                            <label class="small mb-0"><?= __('gdt.page.exemptDepartingWithin') ?></label>
                                            <div class="input-group input-group-sm">
                                                <input type="number" class="form-control form-control-sm" id="gs_exempt_depart_within" 
                                                       placeholder="<?= __('gdt.page.placeholderDelayMin') ?>" min="0" max="120" value="">
                                                <div class="input-group-append">
                                                    <span class="input-group-text"><?= __('gdt.page.minutes') ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Altitude Exemptions -->
                                <div class="border rounded p-2 mb-2 bg-light">
                                    <div class="tmi-label text-primary mb-1"><i class="fas fa-arrows-alt-v mr-1"></i> <?= __('gdt.page.altitudeExemptions') ?></div>
                                    <div class="form-row">
                                        <div class="form-group col-md-6 mb-1">
                                            <label class="small mb-0"><?= __('gdt.page.exemptBelowFl') ?></label>
                                            <input type="number" class="form-control form-control-sm" id="gs_exempt_alt_below"
                                                   placeholder="<?= __('gdt.page.placeholderMinAlt') ?>" min="0" max="600">
                                        </div>
                                        <div class="form-group col-md-6 mb-1">
                                            <label class="small mb-0"><?= __('gdt.page.exemptAboveFl') ?></label>
                                            <input type="number" class="form-control form-control-sm" id="gs_exempt_alt_above"
                                                   placeholder="<?= __('gdt.page.placeholderMaxAlt') ?>" min="0" max="600">
                                        </div>
                                    </div>
                                </div>

                                <!-- Individual Flight Exemptions -->
                                <div class="border rounded p-2 bg-light">
                                    <div class="tmi-label text-primary mb-1"><i class="fas fa-plane mr-1"></i> <?= __('gdt.page.individualFlightExemptions') ?></div>
                                    <div class="form-group mb-1">
                                        <label class="small mb-0"><?= __('gdt.page.exemptSpecificFlights') ?></label>
                                        <input type="text" class="form-control form-control-sm" id="gs_exempt_flights"
                                               placeholder="<?= __('gdt.page.placeholderCallsigns') ?>">
                                        <small class="form-text text-muted"><?= __('gdt.page.exemptSpecificFlightsHelp') ?></small>
                                    </div>
                                </div>

                                <!-- Exemption Summary -->
                                <div class="mt-2 small" id="gs_exemption_summary">
                                    <span class="text-muted"><?= __('gdt.page.noExemptionRules') ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Comments -->
                    <div class="form-group">
                        <label class="tmi-label mb-0" for="gs_comments"><?= __('gdt.page.comments') ?></label>
                        <textarea class="form-control form-control-sm" id="gs_comments" rows="2"
                                  placeholder="<?= __('gdt.page.placeholderComments') ?>"></textarea>
                    </div>

                    <!-- Buttons -->
                    <div class="d-flex justify-content-between">
                        <div>
                            <button class="btn btn-sm btn-outline-secondary" id="gs_reset_btn" type="button"><?= __('gdt.page.reset') ?></button>
                        </div>
                        <div>
                            <button class="btn btn-sm btn-outline-info" id="gs_preview_flights_btn" type="button">
                                <?= __('gdt.page.previewImpactedFlights') ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Advisory preview -->
            <div class="card mt-3 shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span class="tmi-section-title">
                        <i class="fas fa-file-alt mr-1"></i> <?= __('gdt.page.advisoryPreviewTitle') ?>
                    </span>
                    <button class="btn btn-sm btn-outline-secondary" id="gs_copy_advisory_btn" type="button" title="<?= __('gdt.page.copyAdvisoryTooltip') ?>">
                        <i class="fas fa-copy"></i> <?= __('gdt.page.copy') ?>
                    </button>
                </div>
                <div class="card-body">
                    <pre id="gs_advisory_preview" class="tmi-advisory-preview" style="user-select: all;"></pre>
                </div>
            </div>
        </div>

        <!-- Right: Flights impacted -->
        <div class="col-lg-6 mb-4">
            <div class="card shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span class="tmi-section-title">
                        <i class="fas fa-plane-departure mr-1"></i> <?= __('gdt.page.flightsMatchingGsFilters') ?>
                        <span class="badge badge-pill badge-info ml-2" id="gs_flight_count_badge">0</span>
                    </span>
                    <div>
                        <span class="badge badge-secondary tmi-badge-status" id="gs_adl_mode_badge" title="<?= __('gdt.page.currentDatasetTooltip') ?>">ADL: LIVE</span>
                    </div>
                </div>
                <div class="card-body p-2">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <small class="text-muted" id="gs_adl_status"></small>
                        <div class="btn-toolbar" role="toolbar" aria-label="<?= __('gdt.page.gsWorkflowControls') ?>">
                            <div class="btn-group btn-group-sm mr-1" role="group">
                                <button class="btn btn-outline-info" id="gs_preview_btn" type="button" title="<?= __('gdt.page.previewTooltip') ?>"><?= __('gdt.page.preview') ?></button>
                                <button class="btn btn-outline-primary" id="gs_simulate_btn" type="button" title="<?= __('gdt.page.simulateTooltip') ?>"><?= __('gdt.page.simulate') ?></button>
                                <button class="btn btn-outline-success" id="gs_submit_tmi_btn" type="button" title="<?= __('gdt.page.submitToTmiTooltip') ?>" disabled>
                                    <i class="fas fa-paper-plane mr-1"></i><?= __('gdt.page.submitToTmi') ?>
                                </button>
                                <button class="btn btn-success" id="gs_send_actual_btn" type="button" title="<?= __('gdt.page.sendActualTooltip') ?>" disabled>
                                    <i class="fas fa-bolt mr-1"></i><?= __('gdt.page.sendActual') ?>
                                </button>
                            </div>
                            <div class="btn-group btn-group-sm mr-1" role="group">
                                <button class="btn btn-outline-secondary" id="gs_view_flight_list_btn" type="button" title="<?= __('gdt.page.viewFlightListTooltip') ?>">
                                    <i class="fas fa-list-alt mr-1"></i><?= __('gdt.page.listButton') ?>
                                </button>
                                <button class="btn btn-outline-primary" id="gs_open_model_btn" type="button" title="<?= __('gdt.page.openModelTooltip') ?>">
                                    <i class="fas fa-chart-line mr-1"></i><?= __('gdt.page.modelButton') ?>
                                </button>
                            </div>
                            <div class="btn-group btn-group-sm" role="group">
                                <button class="btn btn-outline-warning" id="gs_purge_local_btn" type="button" title="<?= __('gdt.page.clearSandboxTooltip') ?>"><?= __('gdt.page.purgeLocal') ?></button>
                                <button class="btn btn-outline-danger" id="gs_purge_all_btn" type="button" title="<?= __('gdt.page.clearAllGsTooltip') ?>"><?= __('gdt.page.purgeAll') ?></button>
                            </div>
                        </div>
                    </div>

                    <div class="form-row align-items-end mb-2">
                        <div class="form-group col-md-4 mb-1">
                            <label class="tmi-label mb-0" for="gs_time_basis"><?= __('gdt.page.timeFilterBasis') ?> <small class="text-info">(<?= __('gdt.page.gsDepBased') ?>)</small></label>
                            <select class="form-control form-control-sm" id="gs_time_basis">
                                <option value="NONE" selected><?= __('gdt.page.noTimeFilter') ?></option>
                                <option value="ETD"><?= __('gdt.page.departureEtd') ?></option>
                                <option value="EDCT"><?= __('gdt.page.departureEdctCtd') ?></option>
                                <option value="TAKEOFF"><?= __('gdt.page.departureTakeoff') ?></option>
                            </select>
                        </div>
                        <div class="form-group col-md-4 mb-1">
                            <label class="tmi-label mb-0" for="gs_time_start"><?= __('gdt.page.timeStartUtc') ?></label>
                            <input type="datetime-local" class="form-control form-control-sm" id="gs_time_start">
                        </div>
                        <div class="form-group col-md-4 mb-1">
                            <label class="tmi-label mb-0" for="gs_time_end"><?= __('gdt.page.timeEndUtc') ?></label>
                            <input type="datetime-local" class="form-control form-control-sm" id="gs_time_end">
                        </div>
                    </div>

                    <!-- Show All Flights Toggle -->
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div class="custom-control custom-switch">
                            <input type="checkbox" class="custom-control-input" id="gs_show_all_flights">
                            <label class="custom-control-label small" for="gs_show_all_flights">
                                <?= __('gdt.page.showAllFlights') ?> <span class="text-muted"><?= __('gdt.page.includingAirborneExempt') ?></span>
                            </label>
                        </div>
                        <small id="gs_flight_count_label" class="text-muted"></small>
                    </div>

                    <div class="table-responsive" style="max-height: 420px; overflow-y: auto;">
                        <table class="table table-sm table-striped table-hover tmi-flight-table mb-0" id="gs_flights_matching_table">
                            <thead class="thead-light" style="position: sticky; top: 0; z-index: 5;">
                              <tr>
                                <th class="gs-matching-sortable" data-sort="acid" style="cursor:pointer;">ACID <i class="fas fa-sort fa-xs text-muted"></i></th>
                                <th class="gs-matching-sortable" data-sort="etd" style="cursor:pointer;">ETD <i class="fas fa-sort fa-xs text-muted"></i></th>
                                <th class="gs-matching-sortable" data-sort="edct" style="cursor:pointer;">CTD <i class="fas fa-sort fa-xs text-muted"></i></th>
                                <th class="gs-matching-sortable" data-sort="eta" style="cursor:pointer;">ETA <i class="fas fa-sort fa-xs text-muted"></i></th>
                                <th class="gs-matching-sortable" data-sort="dcenter" style="cursor:pointer;">DCTR <i class="fas fa-sort fa-xs text-muted"></i></th>
                                <th class="gs-matching-sortable" data-sort="orig" style="cursor:pointer;">ORIG <i class="fas fa-sort fa-xs text-muted"></i></th>
                                <th class="gs-matching-sortable" data-sort="dest" style="cursor:pointer;">DEST <i class="fas fa-sort fa-xs text-muted"></i></th>
                                <th>STATUS</th>
                              </tr>
                            </thead>
                            <tbody id="gs_flight_table_body">
                            </tbody>
                        </table>
                    </div>

                    <div class="row mt-2" id="gs_delay_breakdowns_row">
                        <div class="col-md-6 mb-2">
                            <div class="card border-light">
                                <div class="card-header py-1 px-2">
                                    <span class="tmi-label"><?= __('gdt.page.delayByOriginAirport') ?></span>
                                </div>
                                <div class="card-body p-1">
                                    <table class="table table-sm table-hover tmi-flight-table mb-0">
                                        <thead>
                                            <tr>
                                                <th><?= __('gdt.page.origin') ?></th>
                                                <th class="text-right"><?= __('gdt.page.total') ?></th>
                                                <th class="text-right"><?= __('gdt.page.max') ?></th>
                                                <th class="text-right"><?= __('gdt.page.avg') ?></th>
                                            </tr>
                                        </thead>
                                        <tbody id="gs_delay_origin_ap"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 mb-2">
                            <div class="card border-light">
                                <div class="card-header py-1 px-2">
                                    <span class="tmi-label"><?= __('gdt.page.delayByOriginArtcc') ?></span>
                                </div>
                                <div class="card-body p-1">
                                    <table class="table table-sm table-hover tmi-flight-table mb-0">
                                        <thead>
                                            <tr>
                                                <th><?= __('gdt.page.center') ?></th>
                                                <th class="text-right"><?= __('gdt.page.total') ?></th>
                                                <th class="text-right"><?= __('gdt.page.max') ?></th>
                                                <th class="text-right"><?= __('gdt.page.avg') ?></th>
                                            </tr>
                                        </thead>
                                        <tbody id="gs_delay_origin_center"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 mb-2">
                            <div class="card border-light">
                                <div class="card-header py-1 px-2">
                                    <span class="tmi-label"><?= __('gdt.page.delayByCarrier') ?></span>
                                </div>
                                <div class="card-body p-1">
                                    <table class="table table-sm table-hover tmi-flight-table mb-0">
                                        <thead>
                                            <tr>
                                                <th><?= __('gdt.page.carrier') ?></th>
                                                <th class="text-right"><?= __('gdt.page.total') ?></th>
                                                <th class="text-right"><?= __('gdt.page.max') ?></th>
                                                <th class="text-right"><?= __('gdt.page.avg') ?></th>
                                            </tr>
                                        </thead>
                                        <tbody id="gs_delay_carrier"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 mb-2">
                            <div class="card border-light">
                                <div class="card-header py-1 px-2">
                                    <span class="tmi-label"><?= __('gdt.page.delayByEdctHour') ?></span>
                                </div>
                                <div class="card-body p-1">
                                    <table class="table table-sm table-hover tmi-flight-table mb-0">
                                        <thead>
                                            <tr>
                                                <th><?= __('gdt.page.hour') ?></th>
                                                <th class="text-right"><?= __('gdt.page.total') ?></th>
                                                <th class="text-right"><?= __('gdt.page.max') ?></th>
                                                <th class="text-right"><?= __('gdt.page.avg') ?></th>
                                            </tr>
                                        </thead>
                                        <tbody id="gs_delay_hour_bin"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mt-3">
                        <span class="tmi-label mb-0"><?= __('gdt.page.flightCountsSummary') ?></span>
                        <button class="btn btn-sm btn-outline-secondary" id="gs_toggle_counts_btn" type="button"><?= __('gdt.page.hide') ?></button>
                    </div>

                    <div class="row mt-2" id="gs_counts_row">
                        <div class="col-md-6 mb-2">
                            <div class="card border-light">
                                <div class="card-header py-1 px-2">
                                    <span class="tmi-label"><?= __('gdt.page.originCentersArtcc') ?></span>
                                </div>
                                <div class="card-body p-1">
                                    <table class="table table-sm table-hover tmi-flight-table mb-0">
                                        <tbody id="gs_counts_origin_center"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 mb-2">
                            <div class="card border-light">
                                <div class="card-header py-1 px-2">
                                    <span class="tmi-label"><?= __('gdt.page.destCentersArtcc') ?></span>
                                </div>
                                <div class="card-body p-1">
                                    <table class="table table-sm table-hover tmi-flight-table mb-0">
                                        <tbody id="gs_counts_dest_center"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 mb-2">
                            <div class="card border-light">
                                <div class="card-header py-1 px-2">
                                    <span class="tmi-label"><?= __('gdt.page.originAirportsLabel') ?></span>
                                </div>
                                <div class="card-body p-1">
                                    <table class="table table-sm table-hover tmi-flight-table mb-0">
                                        <tbody id="gs_counts_origin_ap"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 mb-2">
                            <div class="card border-light">
                                <div class="card-header py-1 px-2">
                                    <span class="tmi-label"><?= __('gdt.page.destAirports') ?></span>
                                </div>
                                <div class="card-body p-1">
                                    <table class="table table-sm table-hover tmi-flight-table mb-0">
                                        <tbody id="gs_counts_dest_ap"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 mb-2">
                            <div class="card border-light">
                                <div class="card-header py-1 px-2">
                                    <span class="tmi-label"><?= __('gdt.page.carriers') ?></span>
                                </div>
                                <div class="card-body p-1">
                                    <table class="table table-sm table-hover tmi-flight-table mb-0">
                                        <tbody id="gs_counts_carrier"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <small class="text-muted">
                        <?= __('gdt.page.dataSource') ?> <code>https://data.vatsim.net/v3/vatsim-data.json</code> (pilots + prefiles).
                    </small>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Model GS Section (FSM User Guide Chapter 19, Modeling Options Tab) -->
<div class="container-fluid mb-4" id="gs_model_section" style="display: none;">
    <div class="row">
        <div class="col-12">
            <div class="card shadow-sm border-primary">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <span class="tmi-section-title">
                        <i class="fas fa-chart-line mr-1"></i> <?= __('gdt.page.modelGsPowerRun') ?>
                    </span>
                    <button type="button" class="btn btn-sm btn-light" id="gs_model_close_btn">
                        <i class="fas fa-times"></i> <?= __('gdt.page.close') ?>
                    </button>
                </div>
                <div class="card-body">
                    <!-- Filter Controls Row -->
                    <div class="row mb-3">
                        <div class="col-md-2">
                            <label class="tmi-label mb-0"><?= __('gdt.page.chartView') ?></label>
                            <select class="form-control form-control-sm" id="gs_model_chart_view">
                                <option value="hourly" selected><?= __('gdt.page.byHourUtc') ?></option>
                                <option value="orig_artcc"><?= __('gdt.page.byOriginArtcc') ?></option>
                                <option value="dest_artcc"><?= __('gdt.page.byDestArtcc') ?></option>
                                <option value="orig_ap"><?= __('gdt.page.byOriginAirport') ?></option>
                                <option value="dest_ap"><?= __('gdt.page.byDestAirport') ?></option>
                                <option value="orig_tracon"><?= __('gdt.page.byOriginTracon') ?></option>
                                <option value="dest_tracon"><?= __('gdt.page.byDestTracon') ?></option>
                                <option value="carrier"><?= __('gdt.page.byCarrier') ?></option>
                                <option value="tier"><?= __('gdt.page.byTier') ?></option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="tmi-label mb-0"><?= __('gdt.page.timeWindow') ?></label>
                            <select class="form-control form-control-sm" id="gs_model_time_window">
                                <option value="all" selected><?= __('gdt.page.allFlights') ?></option>
                                <option value="60"><?= __('gdt.page.next60min') ?></option>
                                <option value="30"><?= __('gdt.page.next30min') ?></option>
                                <option value="15"><?= __('gdt.page.next15min') ?></option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="tmi-label mb-0"><?= __('gdt.page.timeBasis') ?></label>
                            <select class="form-control form-control-sm" id="gs_model_time_basis">
                                <option value="ctd" selected><?= __('gdt.page.ctdControlled') ?></option>
                                <option value="etd"><?= __('gdt.page.etdOriginal') ?></option>
                                <option value="cta"><?= __('gdt.page.ctaArrControlled') ?></option>
                                <option value="eta"><?= __('gdt.page.etaArrOriginal') ?></option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="tmi-label mb-0"><?= __('gdt.page.filterByOriginArtcc') ?></label>
                            <input type="text" class="form-control form-control-sm" id="gs_model_filter_artcc" placeholder="<?= __('gdt.page.placeholderFilterArtcc') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="tmi-label mb-0"><?= __('gdt.page.filterByCarrier') ?></label>
                            <input type="text" class="form-control form-control-sm" id="gs_model_filter_carrier" placeholder="<?= __('gdt.page.placeholderFilterCarrier') ?>">
                        </div>
                    </div>

                    <!-- Demand Profile Chart (ECharts) - FSM/TBFM Style -->
                    <div class="row mb-3">
                        <div class="col-12">
                            <div class="card border-info">
                                <div class="card-header py-1 px-2 bg-info text-white d-flex justify-content-between align-items-center">
                                    <small class="text-uppercase font-weight-bold">
                                        <i class="fas fa-chart-area mr-1"></i>
                                        <span id="gs_demand_chart_title"><?= __('gdt.page.demandProfile') ?></span>
                                        <span class="badge badge-light text-dark ml-2" id="gs_demand_airport_badge">--</span>
                                    </small>
                                    <div class="d-flex align-items-center">
                                        <!-- Direction Toggle -->
                                        <div class="btn-group btn-group-sm mr-2" role="group">
                                            <button class="btn btn-light btn-sm active" id="gs_demand_dir_both" title="<?= __('gdt.page.showBoth') ?>"><?= __('gdt.page.both') ?></button>
                                            <button class="btn btn-outline-light btn-sm" id="gs_demand_dir_arr" title="<?= __('gdt.page.arrivalsOnly') ?>"><?= __('gdt.page.arr') ?></button>
                                            <button class="btn btn-outline-light btn-sm" id="gs_demand_dir_dep" title="<?= __('gdt.page.departuresOnly') ?>"><?= __('gdt.page.dep') ?></button>
                                        </div>
                                        <!-- Granularity Toggle -->
                                        <div class="btn-group btn-group-sm mr-2" role="group">
                                            <button class="btn btn-outline-light btn-sm" id="gs_demand_gran_15" title="<?= __('gdt.page.bins15min') ?>">15</button>
                                            <button class="btn btn-outline-light btn-sm" id="gs_demand_gran_30" title="<?= __('gdt.page.bins30min') ?>">30</button>
                                            <button class="btn btn-light btn-sm active" id="gs_demand_gran_60" title="<?= __('gdt.page.binsHourly') ?>">60</button>
                                        </div>
                                        <!-- Refresh Button -->
                                        <button class="btn btn-outline-light btn-sm" id="gs_demand_refresh_btn" title="<?= __('gdt.page.refreshDemandTooltip') ?>">
                                            <i class="fas fa-sync-alt"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="card-body p-2" style="background: #ffffff;">
                                    <!-- Rate Info Bar -->
                                    <div class="d-flex justify-content-between align-items-center mb-2 px-2" style="font-size: 0.75rem;">
                                        <div>
                                            <span class="text-muted mr-2"><?= __('gdt.page.config') ?></span>
                                            <span id="gs_demand_config_name" class="font-weight-bold">--</span>
                                            <span id="gs_demand_weather_badge" class="badge badge-success ml-2">VMC</span>
                                        </div>
                                        <div>
                                            <span class="text-muted mr-1"><?= __('gdt.page.rates') ?></span>
                                            <span class="font-weight-bold">AAR <span id="gs_demand_aar" class="text-primary">--</span></span>
                                            <span class="mx-1">/</span>
                                            <span class="font-weight-bold">ADR <span id="gs_demand_adr" class="text-primary">--</span></span>
                                            <span class="text-muted ml-2">(<span id="gs_demand_rate_source">--</span>)</span>
                                        </div>
                                        <div>
                                            <span class="text-muted mr-1"><?= __('gdt.page.lastUpdate') ?></span>
                                            <span id="gs_demand_last_update" class="text-info">--</span>
                                        </div>
                                    </div>
                                    <!-- Demand Chart Container (ECharts) -->
                                    <div id="gs_demand_chart" style="height: 320px; width: 100%;"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Main Chart and Legend Row -->
                    <div class="row mb-3">
                        <div class="col-md-8">
                            <div class="card border-secondary">
                                <div class="card-header py-1 px-2 bg-info text-white d-flex justify-content-between align-items-center">
                                    <small class="text-uppercase font-weight-bold"><i class="fas fa-chart-bar mr-1"></i> <span id="gs_model_chart_title">Data Graph - Delay Statistics by Hour</span></small>
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-light btn-sm" id="gs_model_chart_type_bar" title="<?= __('gdt.page.barChartTooltip') ?>"><i class="fas fa-chart-bar"></i></button>
                                        <button class="btn btn-outline-light btn-sm" id="gs_model_chart_type_line" title="<?= __('gdt.page.lineChartTooltip') ?>"><i class="fas fa-chart-line"></i></button>
                                    </div>
                                </div>
                                <div class="card-body p-2">
                                    <div id="gs_model_data_graph_chart" style="height: 300px; position: relative;">
                                        <canvas id="gs_model_data_graph_canvas"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card border-secondary h-100">
                                <div class="card-header py-1 px-2 bg-info text-white">
                                    <small class="text-uppercase font-weight-bold"><i class="fas fa-calculator mr-1"></i> <?= __('gdt.page.summaryStatistics') ?></small>
                                </div>
                                <div class="card-body p-2">
                                    <table class="table table-sm table-borderless mb-0" style="font-size: 0.8rem;">
                                        <tbody>
                                            <tr><td><span class="badge badge-danger">&nbsp;</span> <?= __('gdt.page.totalFlights') ?></td><td class="text-right font-weight-bold" id="gs_model_total_flts">0</td></tr>
                                            <tr><td><span class="badge badge-info">&nbsp;</span> <?= __('gdt.page.affectedFlights') ?></td><td class="text-right font-weight-bold" id="gs_model_affected_flts">0</td></tr>
                                            <tr><td><span class="badge badge-primary">&nbsp;</span> <?= __('gdt.page.totalDelay') ?></td><td class="text-right font-weight-bold" id="gs_model_total_delay">0 min</td></tr>
                                            <tr><td><span class="badge badge-dark">&nbsp;</span> <?= __('gdt.page.maxDelay') ?></td><td class="text-right font-weight-bold" id="gs_model_max_delay">0 min</td></tr>
                                            <tr><td><span class="badge badge-secondary">&nbsp;</span> <?= __('gdt.page.avgDelay') ?></td><td class="text-right font-weight-bold" id="gs_model_avg_delay">0 min</td></tr>
                                        </tbody>
                                    </table>
                                    <hr class="my-2">
                                    <table class="table table-sm table-borderless mb-0" style="font-size: 0.75rem;">
                                        <tbody>
                                            <tr><td class="text-muted"><?= __('gdt.page.within60min') ?></td><td class="text-right" id="gs_model_horizon_60">0 flts</td></tr>
                                            <tr><td class="text-muted"><?= __('gdt.page.within30min') ?></td><td class="text-right" id="gs_model_horizon_30">0 flts</td></tr>
                                            <tr><td class="text-muted"><?= __('gdt.page.within15min') ?></td><td class="text-right" id="gs_model_horizon_15">0 flts</td></tr>
                                        </tbody>
                                    </table>
                                    <hr class="my-2">
                                    <div class="small">
                                        <strong><?= __('gdt.page.gsProgram') ?></strong> <span id="gs_model_ctl_element" class="text-primary">-</span><br>
                                        <span class="text-muted"><?= __('gdt.page.start') ?></span> <span id="gs_model_gs_start">-</span><br>
                                        <span class="text-muted"><?= __('gdt.page.end') ?></span> <span id="gs_model_gs_end">-</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Original vs Controlled Times Comparison -->
                    <div class="row mb-3">
                        <div class="col-12">
                            <div class="card border-info">
                                <div class="card-header py-1 px-2 bg-info text-white">
                                    <small class="text-uppercase font-weight-bold"><i class="fas fa-exchange-alt mr-1"></i> <?= __('gdt.page.origVsControlledTimes') ?></small>
                                </div>
                                <div class="card-body p-2">
                                    <div style="height: 200px; position: relative;">
                                        <canvas id="gs_model_comparison_canvas"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Detailed Breakdown Tables -->
                    <div class="row">
                        <!-- Origin Analysis -->
                        <div class="col-md-6 mb-3">
                            <div class="card border-light">
                                <div class="card-header py-1 px-2 bg-light">
                                    <span class="tmi-label"><i class="fas fa-plane-departure mr-1"></i> <?= __('gdt.page.originAnalysis') ?></span>
                                </div>
                                <div class="card-body p-1">
                                    <div class="row">
                                        <div class="col-6">
                                            <small class="text-uppercase text-muted font-weight-bold"><?= __('gdt.page.byArtcc') ?></small>
                                            <div style="max-height: 140px; overflow-y: auto;">
                                                <table class="table table-sm table-hover mb-0" style="font-size: 0.75rem;">
                                                    <thead><tr><th>ARTCC</th><th class="text-right">Flts</th><th class="text-right">Delay</th><th class="text-right">Avg</th></tr></thead>
                                                    <tbody id="gs_model_by_orig_artcc"></tbody>
                                                </table>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <small class="text-uppercase text-muted font-weight-bold"><?= __('gdt.page.byAirport') ?></small>
                                            <div style="max-height: 140px; overflow-y: auto;">
                                                <table class="table table-sm table-hover mb-0" style="font-size: 0.75rem;">
                                                    <thead><tr><th>Apt</th><th class="text-right">Flts</th><th class="text-right">Delay</th><th class="text-right">Avg</th></tr></thead>
                                                    <tbody id="gs_model_by_orig_ap"></tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row mt-2">
                                        <div class="col-6">
                                            <small class="text-uppercase text-muted font-weight-bold"><?= __('gdt.page.byTracon') ?></small>
                                            <div style="max-height: 140px; overflow-y: auto;">
                                                <table class="table table-sm table-hover mb-0" style="font-size: 0.75rem;">
                                                    <thead><tr><th>TRACON</th><th class="text-right">Flts</th><th class="text-right">Delay</th><th class="text-right">Avg</th></tr></thead>
                                                    <tbody id="gs_model_by_orig_tracon"></tbody>
                                                </table>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <small class="text-uppercase text-muted font-weight-bold"><?= __('gdt.page.byTier') ?></small>
                                            <div style="max-height: 140px; overflow-y: auto;">
                                                <table class="table table-sm table-hover mb-0" style="font-size: 0.75rem;">
                                                    <thead><tr><th>Tier</th><th class="text-right">Flts</th><th class="text-right">Delay</th><th class="text-right">Avg</th></tr></thead>
                                                    <tbody id="gs_model_by_orig_tier"></tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Destination Analysis -->
                        <div class="col-md-6 mb-3">
                            <div class="card border-light">
                                <div class="card-header py-1 px-2 bg-light">
                                    <span class="tmi-label"><i class="fas fa-plane-arrival mr-1"></i> <?= __('gdt.page.destAnalysis') ?></span>
                                </div>
                                <div class="card-body p-1">
                                    <div class="row">
                                        <div class="col-6">
                                            <small class="text-uppercase text-muted font-weight-bold"><?= __('gdt.page.byArtcc') ?></small>
                                            <div style="max-height: 140px; overflow-y: auto;">
                                                <table class="table table-sm table-hover mb-0" style="font-size: 0.75rem;">
                                                    <thead><tr><th>ARTCC</th><th class="text-right">Flts</th><th class="text-right">Delay</th><th class="text-right">Avg</th></tr></thead>
                                                    <tbody id="gs_model_by_dest_artcc"></tbody>
                                                </table>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <small class="text-uppercase text-muted font-weight-bold"><?= __('gdt.page.byAirport') ?></small>
                                            <div style="max-height: 140px; overflow-y: auto;">
                                                <table class="table table-sm table-hover mb-0" style="font-size: 0.75rem;">
                                                    <thead><tr><th>Apt</th><th class="text-right">Flts</th><th class="text-right">Delay</th><th class="text-right">Avg</th></tr></thead>
                                                    <tbody id="gs_model_by_dest_ap"></tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row mt-2">
                                        <div class="col-6">
                                            <small class="text-uppercase text-muted font-weight-bold"><?= __('gdt.page.byTracon') ?></small>
                                            <div style="max-height: 140px; overflow-y: auto;">
                                                <table class="table table-sm table-hover mb-0" style="font-size: 0.75rem;">
                                                    <thead><tr><th>TRACON</th><th class="text-right">Flts</th><th class="text-right">Delay</th><th class="text-right">Avg</th></tr></thead>
                                                    <tbody id="gs_model_by_dest_tracon"></tbody>
                                                </table>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <small class="text-uppercase text-muted font-weight-bold"><?= __('gdt.page.byTier') ?></small>
                                            <div style="max-height: 140px; overflow-y: auto;">
                                                <table class="table table-sm table-hover mb-0" style="font-size: 0.75rem;">
                                                    <thead><tr><th>Tier</th><th class="text-right">Flts</th><th class="text-right">Delay</th><th class="text-right">Avg</th></tr></thead>
                                                    <tbody id="gs_model_by_dest_tier"></tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Carrier and Delay Distribution -->
                    <div class="row">
                        <div class="col-md-4 mb-2">
                            <div class="card border-light">
                                <div class="card-header py-1 px-2"><span class="tmi-label"><i class="fas fa-building mr-1"></i> <?= __('gdt.page.byCarrier') ?></span></div>
                                <div class="card-body p-1" style="max-height: 180px; overflow-y: auto;">
                                    <table class="table table-sm table-hover mb-0" style="font-size: 0.75rem;">
                                        <thead><tr><th>Carrier</th><th class="text-right">Flts</th><th class="text-right">Total</th><th class="text-right">Avg</th></tr></thead>
                                        <tbody id="gs_model_by_carrier"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 mb-2">
                            <div class="card border-light">
                                <div class="card-header py-1 px-2"><span class="tmi-label"><i class="fas fa-clock mr-1"></i> <?= __('gdt.page.byDelayRange') ?></span></div>
                                <div class="card-body p-1" style="max-height: 180px; overflow-y: auto;">
                                    <table class="table table-sm table-hover mb-0" style="font-size: 0.75rem;">
                                        <thead><tr><th>Range</th><th class="text-right">Flts</th><th class="text-right">%</th></tr></thead>
                                        <tbody id="gs_model_by_delay_range"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 mb-2">
                            <div class="card border-light">
                                <div class="card-header py-1 px-2"><span class="tmi-label"><i class="fas fa-history mr-1"></i> <?= __('gdt.page.byHourUtc') ?></span></div>
                                <div class="card-body p-1" style="max-height: 180px; overflow-y: auto;">
                                    <table class="table table-sm table-hover mb-0" style="font-size: 0.75rem;">
                                        <thead><tr><th>Hour</th><th class="text-right">Flts</th><th class="text-right">Total</th><th class="text-right">Avg</th></tr></thead>
                                        <tbody id="gs_model_by_hour"></tbody>
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

<!-- ============================================================================ -->
<!-- GDP Section (Ground Delay Program) - FSM User Guide Chapter 15-17 -->
<!-- ============================================================================ -->
<?php include 'load/gdp_section.php'; ?>

<!-- ECR (EDCT Change Request) Modal - FSM User Guide Chapter 14 -->
<div class="modal fade" id="ecr_modal" tabindex="-1" role="dialog" aria-labelledby="ecr_modal_label" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
        <div class="modal-content">
            <div class="modal-header bg-info text-white py-2">
                <h5 class="modal-title" id="ecr_modal_label">
                    <i class="fas fa-clock mr-2"></i><?= __('gdt.page.ecrTitle') ?>
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="<?= __('gdt.page.closeLabel') ?>">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <!-- ECR Header Info -->
                <div class="alert alert-info py-2 mb-3">
                    <small>
                        <i class="fas fa-info-circle mr-1"></i>
                        <strong>ECR</strong> <?= __('gdt.page.ecrInfo') ?>
                        <span class="text-muted"><?= __('gdt.page.ecrFsmRef') ?></span>
                    </small>
                </div>

                <!-- Find Flight Section -->
                <div class="card border-primary mb-3">
                    <div class="card-header py-1 px-2 bg-primary text-white">
                        <span class="tmi-label mb-0"><i class="fas fa-search mr-1"></i> <?= __('gdt.page.findFlight') ?></span>
                    </div>
                    <div class="card-body py-2">
                        <div class="form-row">
                            <div class="form-group col-md-4 mb-1">
                                <label class="small mb-0"><?= __('gdt.page.acidCallsign') ?></label>
                                <input type="text" class="form-control form-control-sm" id="ecr_acid" placeholder="<?= __('gdt.page.placeholderAcid') ?>">
                            </div>
                            <div class="form-group col-md-4 mb-1">
                                <label class="small mb-0"><?= __('gdt.page.originOrig') ?></label>
                                <input type="text" class="form-control form-control-sm" id="ecr_orig" placeholder="<?= __('gdt.page.placeholderOrig') ?>">
                            </div>
                            <div class="form-group col-md-4 mb-1">
                                <label class="small mb-0"><?= __('gdt.page.destinationDest') ?></label>
                                <input type="text" class="form-control form-control-sm" id="ecr_dest" placeholder="<?= __('gdt.page.placeholderDest') ?>">
                            </div>
                        </div>
                        <div class="form-row align-items-end">
                            <div class="form-group col-md-6 mb-1">
                                <label class="small mb-0"><?= __('gdt.page.earliestEdctUtc') ?></label>
                                <input type="datetime-local" class="form-control form-control-sm" id="ecr_earliest_edct">
                            </div>
                            <div class="form-group col-md-6 mb-1">
                                <button class="btn btn-sm btn-primary w-100" id="ecr_get_flight_btn" type="button">
                                    <i class="fas fa-search mr-1"></i> <?= __('gdt.page.getFlightData') ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Current Flight Data Section -->
                <div class="card border-secondary mb-3" id="ecr_flight_data_section" style="display: none;">
                    <div class="card-header py-1 px-2 bg-info text-white">
                        <span class="tmi-label mb-0"><i class="fas fa-plane mr-1"></i> <?= __('gdt.page.currentFlightData') ?></span>
                    </div>
                    <div class="card-body py-2">
                        <div class="row">
                            <div class="col-md-6">
                                <table class="table table-sm table-borderless mb-0" style="font-size: 0.8rem;">
                                    <tbody>
                                        <tr><td class="text-muted" style="width:40%;">IGTD:</td><td id="ecr_igtd">-</td></tr>
                                        <tr><td class="text-muted">CTD:</td><td id="ecr_ctd" class="font-weight-bold text-primary">-</td></tr>
                                        <tr><td class="text-muted">ETD:</td><td id="ecr_etd">-</td></tr>
                                        <tr><td class="text-muted">ERTD:</td><td id="ecr_ertd">-</td></tr>
                                        <tr><td class="text-muted">ETE:</td><td id="ecr_ete">-</td></tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <table class="table table-sm table-borderless mb-0" style="font-size: 0.8rem;">
                                    <tbody>
                                        <tr><td class="text-muted" style="width:40%;">IGTA:</td><td id="ecr_igta">-</td></tr>
                                        <tr><td class="text-muted">CTA:</td><td id="ecr_cta" class="font-weight-bold text-primary">-</td></tr>
                                        <tr><td class="text-muted">ETA:</td><td id="ecr_eta">-</td></tr>
                                        <tr><td class="text-muted">ERTA:</td><td id="ecr_erta">-</td></tr>
                                        <tr><td class="text-muted">Delay:</td><td id="ecr_delay" class="font-weight-bold">-</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="mt-2 pt-2 border-top">
                            <div class="row small">
                                <div class="col-6"><strong><?= __('gdt.page.controlType') ?></strong> <span id="ecr_ctl_type" class="badge badge-info">-</span></div>
                                <div class="col-6"><strong><?= __('gdt.page.delayStatus') ?></strong> <span id="ecr_delay_status">-</span></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Update Options Section -->
                <div class="card border-warning mb-3" id="ecr_update_section" style="display: none;">
                    <div class="card-header py-1 px-2 bg-warning text-dark">
                        <span class="tmi-label mb-0"><i class="fas fa-edit mr-1"></i> <?= __('gdt.page.updateOptions') ?></span>
                    </div>
                    <div class="card-body py-2">
                        <!-- CTA Range Controls -->
                        <div class="form-row mb-2">
                            <div class="form-group col-md-4 mb-1">
                                <label class="small mb-0"><?= __('gdt.page.ctaRangeMin') ?></label>
                                <input type="number" class="form-control form-control-sm" id="ecr_cta_range" value="60" min="30">
                            </div>
                            <div class="form-group col-md-4 mb-1">
                                <label class="small mb-0"><?= __('gdt.page.maxAdditionalDelay') ?></label>
                                <input type="number" class="form-control form-control-sm" id="ecr_max_add_delay" value="60" min="30" readonly>
                            </div>
                            <div class="form-group col-md-4 mb-1 d-flex align-items-end">
                                <button class="btn btn-sm btn-outline-secondary w-100" id="ecr_default_range_btn" type="button">
                                    <?= __('gdt.page.defaultRange') ?>
                                </button>
                            </div>
                        </div>

                        <!-- Update Method Selection -->
                        <div class="border rounded p-2 bg-light mb-2">
                            <div class="tmi-label text-dark mb-2"><?= __('gdt.page.selectUpdateMethod') ?></div>
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="custom-control custom-radio">
                                        <input type="radio" id="ecr_method_scs" name="ecr_method" class="custom-control-input" value="SCS" checked>
                                        <label class="custom-control-label" for="ecr_method_scs">
                                            <strong><?= __('gdt.page.scs') ?></strong>
                                            <small class="d-block text-muted"><?= __('gdt.page.slotCreditSubstitution') ?></small>
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="custom-control custom-radio">
                                        <input type="radio" id="ecr_method_limited" name="ecr_method" class="custom-control-input" value="LIMITED">
                                        <label class="custom-control-label" for="ecr_method_limited">
                                            <strong><?= __('gdt.page.limited') ?></strong>
                                            <small class="d-block text-muted"><?= __('gdt.page.withinCtaRange') ?></small>
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="custom-control custom-radio">
                                        <input type="radio" id="ecr_method_unlimited" name="ecr_method" class="custom-control-input" value="UNLIMITED">
                                        <label class="custom-control-label" for="ecr_method_unlimited">
                                            <strong><?= __('gdt.page.unlimited') ?></strong>
                                            <small class="d-block text-muted"><?= __('gdt.page.anyAvailableSlot') ?></small>
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="custom-control custom-radio">
                                        <input type="radio" id="ecr_method_manual" name="ecr_method" class="custom-control-input" value="MANUAL">
                                        <label class="custom-control-label" for="ecr_method_manual">
                                            <strong><?= __('gdt.page.manual') ?></strong>
                                            <small class="d-block text-muted"><?= __('gdt.page.specifyExactTime') ?></small>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Manual EDCT Entry (shown only when Manual selected) -->
                        <div class="form-row" id="ecr_manual_section" style="display: none;">
                            <div class="form-group col-md-6 mb-1">
                                <label class="small mb-0"><?= __('gdt.page.newEdctManual') ?></label>
                                <input type="datetime-local" class="form-control form-control-sm" id="ecr_manual_edct">
                            </div>
                            <div class="form-group col-md-6 mb-1">
                                <label class="small mb-0"><?= __('gdt.page.calculatedCta') ?></label>
                                <input type="text" class="form-control form-control-sm" id="ecr_manual_cta" readonly>
                            </div>
                        </div>

                        <!-- Modeled Results -->
                        <div class="border rounded p-2 bg-white" id="ecr_model_results" style="display: none;">
                            <div class="tmi-label text-success mb-2"><i class="fas fa-calculator mr-1"></i> <?= __('gdt.page.modeledUpdateResults') ?></div>
                            <div class="row small">
                                <div class="col-md-4"><strong><?= __('gdt.page.newCtd') ?></strong> <span id="ecr_new_ctd" class="text-primary font-weight-bold">-</span></div>
                                <div class="col-md-4"><strong><?= __('gdt.page.newCta') ?></strong> <span id="ecr_new_cta" class="text-primary font-weight-bold">-</span></div>
                                <div class="col-md-4"><strong><?= __('gdt.page.delayChange') ?></strong> <span id="ecr_delay_change">-</span></div>
                            </div>
                        </div>

                        <!-- ERTA Update Option -->
                        <div class="mt-2 pt-2 border-top">
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input" id="ecr_update_erta">
                                <label class="custom-control-label small" for="ecr_update_erta">
                                    <?= __('gdt.page.updateErta') ?>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ECR Response Section -->
                <div class="alert alert-success py-2 mb-0" id="ecr_response_section" style="display: none;">
                    <strong><i class="fas fa-check-circle mr-1"></i> <?= __('gdt.page.ecrResponse') ?></strong>
                    <pre id="ecr_response_text" class="mb-0 mt-1" style="font-size: 0.8rem; white-space: pre-wrap;"></pre>
                </div>
            </div>
            <div class="modal-footer py-2">
                <div class="mr-auto">
                    <small class="text-muted">
                        Control Types: <span class="badge badge-info">ECR</span> ATCSCC SCS |
                        <span class="badge badge-secondary">SCS</span> Customer SCS |
                        <span class="badge badge-warning text-dark">UPD</span> EDCT Update |
                        <span class="badge badge-success">BRG</span> Bridged
                    </small>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="ecr_clear_btn"><?= __('gdt.page.clearAll') ?></button>
                <button type="button" class="btn btn-sm btn-info" id="ecr_apply_model_btn"><?= __('gdt.page.applyModel') ?></button>
                <button type="button" class="btn btn-sm btn-success" id="ecr_send_request_btn" disabled><?= __('gdt.page.sendRequest') ?></button>
                <button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal"><?= __('gdt.page.cancel') ?></button>
            </div>
        </div>
    </div>
</div>

<!-- GS Flight List Modal -->
<div class="modal fade" id="gs_flight_list_modal" tabindex="-1" role="dialog" aria-labelledby="gs_flight_list_modal_label" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable" role="document" style="max-width: 95%;">
        <div class="modal-content">
            <div class="modal-header bg-success text-white py-2">
                <h5 class="modal-title" id="gs_flight_list_modal_label">
                    <i class="fas fa-list-alt mr-2"></i><?= __('gdt.page.gsFlightListTitle') ?>
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="<?= __('gdt.page.closeLabel') ?>">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <!-- Program Info & Summary Row -->
                <div class="row mb-3">
                    <div class="col-md-4">
                        <div class="card border-info h-100">
                            <div class="card-header py-1 px-2 bg-info text-white">
                                <small class="text-uppercase font-weight-bold"><?= __('gdt.page.gsProgramInfo') ?></small>
                            </div>
                            <div class="card-body py-2 px-3">
                                <div class="row small">
                                    <div class="col-6"><strong><?= __('gdt.page.ctlElementLabel') ?></strong> <span id="gs_flt_list_ctl_element">-</span></div>
                                    <div class="col-6"><strong><?= __('gdt.page.gsStartLabel') ?></strong> <span id="gs_flt_list_start">-</span></div>
                                    <div class="col-6"><strong><?= __('gdt.page.programLabel') ?></strong> <span id="gs_flt_list_program"><?= __('gdt.page.groundStop') ?></span></div>
                                    <div class="col-6"><strong><?= __('gdt.page.gsEndLabel') ?></strong> <span id="gs_flt_list_end">-</span></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-warning h-100">
                            <div class="card-header py-1 px-2 bg-warning text-dark">
                                <small class="text-uppercase font-weight-bold"><?= __('gdt.page.delayStatistics') ?></small>
                            </div>
                            <div class="card-body py-2 px-3">
                                <div class="row small">
                                    <div class="col-6"><strong><?= __('gdt.page.totalFlightsLabel') ?></strong> <span id="gs_flt_list_total">0</span></div>
                                    <div class="col-6"><strong><?= __('gdt.page.affectedFlightsLabel') ?></strong> <span id="gs_flt_list_affected">0</span></div>
                                    <div class="col-6"><strong><?= __('gdt.page.maxDelayLabel') ?></strong> <span id="gs_flt_list_max_delay">0</span> min</div>
                                    <div class="col-6"><strong><?= __('gdt.page.avgDelayLabel') ?></strong> <span id="gs_flt_list_avg_delay">0</span> min</div>
                                    <div class="col-6"><strong><?= __('gdt.page.totalDelayLabel') ?></strong> <span id="gs_flt_list_total_delay">0</span> min</div>
                                    <div class="col-6"><strong><?= __('gdt.page.generatedLabel') ?></strong> <span id="gs_flt_list_timestamp">-</span></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-secondary h-100">
                            <div class="card-header py-1 px-2 bg-info text-white">
                                <small class="text-uppercase font-weight-bold"><?= __('gdt.page.viewOptions') ?></small>
                            </div>
                            <div class="card-body py-2 px-3">
                                <div class="row small">
                                    <div class="col-6">
                                        <label class="tmi-label mb-0"><?= __('gdt.page.groupBy') ?></label>
                                        <select class="form-control form-control-sm" id="gs_flt_list_group_by">
                                            <option value="none"><?= __('gdt.page.noneFlat') ?></option>
                                            <option value="carrier"><?= __('gdt.page.carrier') ?></option>
                                            <option value="orig_airport"><?= __('gdt.page.origAirport') ?></option>
                                            <option value="orig_center"><?= __('gdt.page.origCenter') ?></option>
                                            <option value="dest_airport"><?= __('gdt.page.destAirport') ?></option>
                                            <option value="dest_center"><?= __('gdt.page.destCenter') ?></option>
                                            <option value="delay_bucket"><?= __('gdt.page.delayRange') ?></option>
                                        </select>
                                    </div>
                                    <div class="col-6">
                                        <label class="tmi-label mb-0"><?= __('gdt.page.sortBy') ?></label>
                                        <select class="form-control form-control-sm" id="gs_flt_list_sort_by">
                                            <option value="acid_asc"><?= __('gdt.page.acidAZ') ?></option>
                                            <option value="acid_desc"><?= __('gdt.page.acidZA') ?></option>
                                            <option value="delay_desc"><?= __('gdt.page.delayHighLow') ?></option>
                                            <option value="delay_asc"><?= __('gdt.page.delayLowHigh') ?></option>
                                            <option value="etd_asc"><?= __('gdt.page.etdEarliest') ?></option>
                                            <option value="etd_desc"><?= __('gdt.page.etdLatest') ?></option>
                                            <option value="orig_asc"><?= __('gdt.page.origAZ') ?></option>
                                            <option value="dest_asc"><?= __('gdt.page.destAZ') ?></option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Link to Model GS Section -->
                <div class="alert alert-info py-2 mb-3">
                    <i class="fas fa-chart-line mr-1"></i> <strong><?= __('gdt.page.dataGraph') ?></strong>
                    <a href="#" id="gs_flt_list_open_model" class="alert-link"><?= __('gdt.page.openModelGsSection') ?></a> <?= __('gdt.page.dataGraphFsmRef') ?>
                </div>

                <!-- Export & Action Buttons -->
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div>
                        <span class="tmi-label"><?= __('gdt.page.flightListCoordination') ?></span>
                        <span class="badge badge-info ml-2" id="gs_flt_list_count_badge">0 flights</span>
                    </div>
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-outline-secondary" id="gs_flt_list_copy_btn" type="button" title="<?= __('gdt.page.copyToClipboardTooltip') ?>">
                            <i class="fas fa-copy"></i> <?= __('gdt.page.copy') ?>
                        </button>
                        <button class="btn btn-outline-secondary" id="gs_flt_list_export_csv_btn" type="button" title="<?= __('gdt.page.exportCsvTooltip') ?>">
                            <i class="fas fa-file-csv"></i> <?= __('gdt.page.csv') ?>
                        </button>
                        <button class="btn btn-outline-secondary" id="gs_flt_list_print_btn" type="button" title="<?= __('gdt.page.printFlightListTooltip') ?>">
                            <i class="fas fa-print"></i> <?= __('gdt.page.print') ?>
                        </button>
                    </div>
                </div>

                <!-- Main Flight List Table with sortable headers -->
                <div class="table-responsive" style="max-height: 380px; overflow-y: auto;">
                    <table class="table table-sm table-striped table-hover tmi-flight-table mb-0" id="gs_flight_list_table">
                        <thead class="thead-dark" style="position: sticky; top: 0; z-index: 10;">
                            <tr>
                                <th class="gs-sortable" data-sort="acid" style="cursor:pointer;">ACID <i class="fas fa-sort fa-xs text-muted"></i></th>
                                <th class="gs-sortable" data-sort="carrier" style="cursor:pointer;"><?= __('gdt.page.carrier') ?> <i class="fas fa-sort fa-xs text-muted"></i></th>
                                <th class="gs-sortable" data-sort="orig" style="cursor:pointer;">ORIG <i class="fas fa-sort fa-xs text-muted"></i></th>
                                <th class="gs-sortable" data-sort="dest" style="cursor:pointer;">DEST <i class="fas fa-sort fa-xs text-muted"></i></th>
                                <th class="gs-sortable" data-sort="dcenter" style="cursor:pointer;">DCTR <i class="fas fa-sort fa-xs text-muted"></i></th>
                                <th class="gs-sortable" data-sort="acenter" style="cursor:pointer;">ACTR <i class="fas fa-sort fa-xs text-muted"></i></th>
                                <th class="gs-sortable" data-sort="oetd" style="cursor:pointer;">OETD <i class="fas fa-sort fa-xs text-muted"></i></th>
                                <th class="gs-sortable" data-sort="etd" style="cursor:pointer;">ETD <i class="fas fa-sort fa-xs text-muted"></i></th>
                                <th>CTD/EDCT</th>
                                <th class="gs-sortable" data-sort="oeta" style="cursor:pointer;">OETA <i class="fas fa-sort fa-xs text-muted"></i></th>
                                <th class="gs-sortable" data-sort="eta" style="cursor:pointer;">ETA <i class="fas fa-sort fa-xs text-muted"></i></th>
                                <th>CTA</th>
                                <th class="gs-sortable" data-sort="delay" style="cursor:pointer;"><?= __('gdt.page.delay') ?> <i class="fas fa-sort fa-xs text-muted"></i></th>
                                <th><?= __('common.status') ?></th>
                            </tr>
                        </thead>
                        <tbody id="gs_flight_list_body">
                        </tbody>
                    </table>
                </div>

                <!-- Summary Tables Row -->
                <div class="row mt-3">
                    <div class="col-md-3 mb-2">
                        <div class="card border-light">
                            <div class="card-header py-1 px-2">
                                <span class="tmi-label"><?= __('gdt.page.byOriginCenter') ?></span>
                            </div>
                            <div class="card-body p-1" style="max-height: 130px; overflow-y: auto;">
                                <table class="table table-sm table-hover tmi-flight-table mb-0">
                                    <thead><tr><th><?= __('gdt.page.center') ?></th><th class="text-right"><?= __('gdt.page.count') ?></th></tr></thead>
                                    <tbody id="gs_flt_list_by_dcenter"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-2">
                        <div class="card border-light">
                            <div class="card-header py-1 px-2">
                                <span class="tmi-label"><?= __('gdt.page.byOriginAirport2') ?></span>
                            </div>
                            <div class="card-body p-1" style="max-height: 130px; overflow-y: auto;">
                                <table class="table table-sm table-hover tmi-flight-table mb-0">
                                    <thead><tr><th><?= __('gdt.page.apt') ?></th><th class="text-right"><?= __('gdt.page.count') ?></th></tr></thead>
                                    <tbody id="gs_flt_list_by_orig"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-2">
                        <div class="card border-light">
                            <div class="card-header py-1 px-2">
                                <span class="tmi-label"><?= __('gdt.page.byDestAirport2') ?></span>
                            </div>
                            <div class="card-body p-1" style="max-height: 130px; overflow-y: auto;">
                                <table class="table table-sm table-hover tmi-flight-table mb-0">
                                    <thead><tr><th><?= __('gdt.page.apt') ?></th><th class="text-right"><?= __('gdt.page.count') ?></th></tr></thead>
                                    <tbody id="gs_flt_list_by_dest"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-2">
                        <div class="card border-light">
                            <div class="card-header py-1 px-2">
                                <span class="tmi-label"><?= __('gdt.page.byCarrier') ?></span>
                            </div>
                            <div class="card-body p-1" style="max-height: 130px; overflow-y: auto;">
                                <table class="table table-sm table-hover tmi-flight-table mb-0">
                                    <thead><tr><th><?= __('gdt.page.carrier') ?></th><th class="text-right"><?= __('gdt.page.count') ?></th></tr></thead>
                                    <tbody id="gs_flt_list_by_carrier"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer py-2">
                <small class="text-muted mr-auto">
                    <i class="fas fa-info-circle"></i> <?= __('gdt.page.flightListHelp') ?>
                </small>
                <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Chart.js CDN for Data Graph -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation@2.2.1/dist/chartjs-plugin-annotation.min.js"></script>
<!-- D3.js for GDP Demand/Capacity visualization -->
<script src="https://cdn.jsdelivr.net/npm/d3@7.8.5/dist/d3.min.js"></script>
<!-- ECharts for Demand Visualization -->
<script src="https://cdn.jsdelivr.net/npm/echarts@5.4.3/dist/echarts.min.js"></script>
<!-- Shared Phase Color Configuration -->
<script src="assets/js/config/phase-colors.js<?= _v('assets/js/config/phase-colors.js') ?>"></script>
<!-- Rate Line Color Configuration -->
<script src="assets/js/config/rate-colors.js<?= _v('assets/js/config/rate-colors.js') ?>"></script>
<!-- Shared Demand Chart Core (from demand.js) -->
<script src="assets/js/demand.js<?= _v('assets/js/demand.js') ?>"></script>

<?php include("load/footer.php"); ?>

<!-- Advisory Organization Config Modal -->
<div class="modal fade" id="advisoryOrgModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-globe mr-2"></i><?= __('gdt.page.advisoryOrg') ?></h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="advisoryOrg" id="orgDCC" value="DCC">
                    <label class="form-check-label" for="orgDCC">
                        <strong><?= __('gdt.page.usDcc') ?></strong><br><small class="text-muted"><?= __('gdt.page.usDccHint') ?></small>
                    </label>
                </div>
                <div class="form-check mt-3">
                    <input class="form-check-input" type="radio" name="advisoryOrg" id="orgNOC" value="NOC">
                    <label class="form-check-label" for="orgNOC">
                        <strong><?= __('gdt.page.canadianNoc') ?></strong><br><small class="text-muted"><?= __('gdt.page.canadianNocHint') ?></small>
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal"><?= __('gdt.page.cancel') ?></button>
                <button type="button" class="btn btn-primary btn-sm" id="advisoryOrgSaveBtn" onclick="AdvisoryConfig.saveOrg()"><?= __('gdt.page.save') ?></button>
            </div>
        </div>
    </div>
</div>

<!-- Extend Program Modal -->
<div class="modal fade" id="gdt_extend_modal" tabindex="-1" role="dialog" aria-labelledby="gdt_extend_modal_label" aria-hidden="true">
    <div class="modal-dialog modal-md">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white py-2">
                <h5 class="modal-title" id="gdt_extend_modal_label">
                    <i class="fas fa-clock mr-1"></i> <?= __('gdt.page.extendProgram') ?>
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="font-weight-bold"><?= __('gdt.page.program') ?></label>
                    <div id="gdt_extend_program_info" class="text-muted">-</div>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label class="font-weight-bold"><?= __('gdt.page.currentEndTime') ?></label>
                        <input type="text" class="form-control form-control-sm" id="gdt_extend_current_end" readonly>
                    </div>
                    <div class="form-group col-md-6">
                        <label class="font-weight-bold"><?= __('gdt.page.newEndTimeUtc') ?></label>
                        <input type="datetime-local" class="form-control form-control-sm" id="gdt_extend_new_end" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="font-weight-bold"><?= __('gdt.page.probabilityOfExtension') ?></label>
                    <select class="form-control form-control-sm" id="gdt_extend_prob_ext">
                        <option value=""><?= __('gdt.page.select') ?></option>
                        <option value="LOW">LOW</option>
                        <option value="MODERATE">MODERATE</option>
                        <option value="HIGH">HIGH</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="font-weight-bold"><?= __('gdt.page.extensionComments') ?></label>
                    <textarea class="form-control form-control-sm" id="gdt_extend_comments" rows="2" placeholder="<?= __('gdt.page.placeholderExtensionReason') ?>"></textarea>
                </div>
                <!-- Advisory Preview -->
                <div class="form-group">
                    <label class="font-weight-bold"><?= __('gdt.page.advisoryPreviewLabel') ?></label>
                    <pre id="gdt_extend_advisory_preview" class="border bg-light p-2 small" style="max-height:200px; overflow-y:auto; white-space:pre-wrap; font-family:monospace; font-size:0.7rem;"></pre>
                </div>
                <div id="gdt_extend_error" class="alert alert-danger small py-1 px-2 d-none"></div>
            </div>
            <div class="modal-footer py-1">
                <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal"><?= __('gdt.page.cancel') ?></button>
                <button type="button" class="btn btn-primary btn-sm" id="gdt_extend_submit_btn" onclick="submitExtend();">
                    <i class="fas fa-clock mr-1"></i> <?= __('gdt.page.extendProgram') ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Revise Program Modal -->
<div class="modal fade" id="gdt_revise_modal" tabindex="-1" role="dialog" aria-labelledby="gdt_revise_modal_label" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark py-2">
                <h5 class="modal-title" id="gdt_revise_modal_label">
                    <i class="fas fa-edit mr-1"></i> <?= __('gdt.page.reviseProgram') ?>
                </h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="font-weight-bold"><?= __('gdt.page.program') ?></label>
                    <div id="gdt_revise_program_info" class="text-muted">-</div>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label class="font-weight-bold"><?= __('gdt.page.programRateArrHr') ?></label>
                        <input type="number" class="form-control form-control-sm" id="gdt_revise_rate" min="1" max="120" placeholder="<?= __('gdt.page.placeholderRate') ?>">
                    </div>
                    <div class="form-group col-md-4">
                        <label class="font-weight-bold"><?= __('gdt.page.delayCapMinutes') ?></label>
                        <input type="number" class="form-control form-control-sm" id="gdt_revise_delay_cap" min="0" max="600" placeholder="<?= __('gdt.page.placeholderDelayCap') ?>">
                    </div>
                    <div class="form-group col-md-4">
                        <label class="font-weight-bold"><?= __('gdt.page.newEndTimeUtc') ?></label>
                        <input type="datetime-local" class="form-control form-control-sm" id="gdt_revise_end_utc">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label class="font-weight-bold"><?= __('gdt.page.impactingConditionLabel') ?></label>
                        <select class="form-control form-control-sm" id="gdt_revise_impacting">
                            <option value="WEATHER">WEATHER</option>
                            <option value="VOLUME">VOLUME</option>
                            <option value="EQUIPMENT">EQUIPMENT</option>
                            <option value="RUNWAY">RUNWAY</option>
                            <option value="OTHER">OTHER</option>
                        </select>
                    </div>
                    <div class="form-group col-md-6">
                        <label class="font-weight-bold"><?= __('gdt.page.probabilityOfExtension') ?></label>
                        <select class="form-control form-control-sm" id="gdt_revise_prob_ext">
                            <option value=""><?= __('gdt.page.select') ?></option>
                            <option value="LOW">LOW</option>
                            <option value="MODERATE">MODERATE</option>
                            <option value="HIGH">HIGH</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="font-weight-bold"><?= __('gdt.page.revisionComments') ?></label>
                    <textarea class="form-control form-control-sm" id="gdt_revise_comments" rows="2" placeholder="<?= __('gdt.page.placeholderChangedWhy') ?>"></textarea>
                </div>
                <div class="form-group">
                    <label class="font-weight-bold"><?= __('gdt.page.advisoryPreviewLabel') ?></label>
                    <pre id="gdt_revise_advisory_preview" class="border bg-light p-2 small" style="max-height:200px; overflow-y:auto; white-space:pre-wrap; font-family:monospace; font-size:0.7rem;"></pre>
                </div>
                <div id="gdt_revise_error" class="alert alert-danger small py-1 px-2 d-none"></div>
            </div>
            <div class="modal-footer py-1">
                <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal"><?= __('gdt.page.cancel') ?></button>
                <button type="button" class="btn btn-warning btn-sm" id="gdt_revise_submit_btn" onclick="submitRevise();">
                    <i class="fas fa-edit mr-1"></i> <?= __('gdt.page.reviseProgram') ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- GS-to-GDP Transition Modal -->
<div class="modal fade" id="gdt_transition_modal" tabindex="-1" role="dialog" aria-labelledby="gdt_transition_modal_label" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white py-2">
                <h5 class="modal-title" id="gdt_transition_modal_label">
                    <i class="fas fa-exchange-alt mr-1"></i> <?= __('gdt.page.gsToGdpTransition') ?>
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <!-- Phase indicator -->
                <div class="mb-3">
                    <div class="d-flex align-items-center">
                        <span class="badge badge-secondary mr-2" id="gdt_transition_phase_badge">PHASE 1: PROPOSE</span>
                        <small class="text-muted" id="gdt_transition_phase_help">Create a proposed GDP while the GS remains active.</small>
                    </div>
                </div>

                <!-- Parent GS info -->
                <div class="form-group">
                    <label class="font-weight-bold"><?= __('gdt.page.parentGroundStop') ?></label>
                    <div id="gdt_transition_gs_info" class="text-muted border rounded p-2 bg-light">-</div>
                </div>

                <!-- Proposed GDP already exists banner (hidden until phase 2) -->
                <div class="alert alert-info small py-2 d-none" id="gdt_transition_proposed_banner">
                    <i class="fas fa-info-circle mr-1"></i>
                    A proposed GDP (<strong id="gdt_transition_proposed_id">-</strong>) is ready.
                    Activating will cancel the GS and make the GDP live.
                </div>

                <!-- GDP Parameters (Phase 1 only) -->
                <div id="gdt_transition_params">
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label class="font-weight-bold"><?= __('gdt.page.gdpType') ?></label>
                            <select class="form-control form-control-sm" id="gdt_transition_gdp_type">
                                <option value="GDP-DAS">GDP-DAS (Default)</option>
                                <option value="GDP-GAAP">GDP-GAAP</option>
                                <option value="GDP-UDP">GDP-UDP</option>
                            </select>
                        </div>
                        <div class="form-group col-md-4">
                            <label class="font-weight-bold"><?= __('gdt.page.programRateArrHr') ?></label>
                            <input type="number" class="form-control form-control-sm" id="gdt_transition_rate" min="1" max="120" placeholder="<?= __('gdt.page.placeholderTransitionRate') ?>">
                        </div>
                        <div class="form-group col-md-4">
                            <label class="font-weight-bold"><?= __('gdt.page.reserveRate') ?></label>
                            <input type="number" class="form-control form-control-sm" id="gdt_transition_reserve" min="0" max="30" placeholder="<?= __('gdt.page.placeholderReserve') ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label class="font-weight-bold"><?= __('gdt.page.gdpEndTimeUtc') ?></label>
                            <input type="datetime-local" class="form-control form-control-sm" id="gdt_transition_end_utc" required>
                        </div>
                        <div class="form-group col-md-4">
                            <label class="font-weight-bold"><?= __('gdt.page.delayCapMinutes') ?></label>
                            <input type="number" class="form-control form-control-sm" id="gdt_transition_delay_cap" min="0" max="600" value="180" placeholder="<?= __('gdt.page.placeholderTransitionDelay') ?>">
                        </div>
                        <div class="form-group col-md-4">
                            <label class="font-weight-bold"><?= __('gdt.page.impactingConditionLabel') ?></label>
                            <select class="form-control form-control-sm" id="gdt_transition_impacting">
                                <option value="WEATHER">WEATHER</option>
                                <option value="VOLUME">VOLUME</option>
                                <option value="EQUIPMENT">EQUIPMENT</option>
                                <option value="RUNWAY">RUNWAY</option>
                                <option value="OTHER">OTHER</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold"><?= __('gdt.page.extensionComments') ?></label>
                        <textarea class="form-control form-control-sm" id="gdt_transition_comments" rows="2" placeholder="<?= __('gdt.page.placeholderTransitionComments') ?>"></textarea>
                    </div>
                </div>

                <!-- Cumulative Period (shown after propose) -->
                <div class="form-group d-none" id="gdt_transition_cumulative_row">
                    <label class="font-weight-bold"><?= __('gdt.page.cumulativeProgramPeriod') ?></label>
                    <div id="gdt_transition_cumulative" class="text-muted">-</div>
                </div>

                <!-- Advisory Preview -->
                <div class="form-group">
                    <label class="font-weight-bold"><?= __('gdt.page.advisoryPreviewLabel') ?></label>
                    <pre id="gdt_transition_advisory_preview" class="border bg-light p-2 small" style="max-height:200px; overflow-y:auto; white-space:pre-wrap; font-family:monospace; font-size:0.7rem;"></pre>
                </div>
                <div id="gdt_transition_error" class="alert alert-danger small py-1 px-2 d-none"></div>
            </div>
            <div class="modal-footer py-1">
                <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal"><?= __('gdt.page.close') ?></button>
                <button type="button" class="btn btn-info btn-sm" id="gdt_transition_propose_btn" onclick="submitTransitionPropose();">
                    <i class="fas fa-file-alt mr-1"></i> <?= __('gdt.page.proposeGdp') ?>
                </button>
                <button type="button" class="btn btn-success btn-sm d-none" id="gdt_transition_activate_btn" onclick="submitTransitionActivate();">
                    <i class="fas fa-check-circle mr-1"></i> <?= __('gdt.page.activateGdpCancelGs') ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/advisory-config.js<?= _v('assets/js/advisory-config.js') ?>"></script>
<!-- FIR (International) Scope Support -->
<script src="assets/js/fir-scope.js<?= _v('assets/js/fir-scope.js') ?>"></script>
<script src="assets/js/fir-integration.js<?= _v('assets/js/fir-integration.js') ?>"></script>
<script src="assets/js/gdt.js<?= _v('assets/js/gdt.js') ?>"></script>
<script src="assets/js/gdp.js<?= _v('assets/js/gdp.js') ?>"></script>

</body>
</html>