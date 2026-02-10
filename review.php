<?php

include("sessions/handler.php");
    // Session Start (S)
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
        ob_start();
    }
    // Session Start (E)

    include("load/config.php");
    include("load/connect.php");

    $uri = explode('?', $_SERVER['REQUEST_URI']);
    $id = $uri[1] ?? null;

    if (!$id || !is_numeric($id)) {
        http_response_code(400);
        echo '<h3 class="text-center mt-5">No plan ID specified. Please select a plan from the <a href="index.php">home page</a>.</h3>';
        exit;
    }

    // Check Perms
    $perm = false;
    if (!defined('DEV')) {
        if (isset($_SESSION['VATSIM_CID'])) {
            $cid = session_get('VATSIM_CID', '');
            $p_check = $conn_sqli->query("SELECT * FROM users WHERE cid='$cid'");
            if ($p_check) {
                $perm = true;
            }
        }
    } else {
        $perm = true;
        $_SESSION['VATSIM_FIRST_NAME'] = $_SESSION['VATSIM_LAST_NAME'] = $_SESSION['VATSIM_CID'] = 0;
    }

    $plan_info = $conn_sqli->query("SELECT * FROM p_plans WHERE id=$id")->fetch_assoc();

    if (!$plan_info) {
        http_response_code(404);
        echo '<h3 class="text-center mt-5">Plan not found.</h3>';
        exit;
    }

    // Get destinations from p_configs for this plan
    $dest_result = $conn_sqli->query("SELECT GROUP_CONCAT(DISTINCT airport) as destinations FROM p_configs WHERE p_id=$id");
    $dest_row = $dest_result ? $dest_result->fetch_assoc() : null;
    $plan_destinations = $dest_row && $dest_row['destinations'] ? $dest_row['destinations'] : '';

    // Get airport configs for demand charts
    $config_result = $conn_sqli->query("SELECT airport, weather, aar, adr, arrive, depart FROM p_configs WHERE p_id=$id");
    $plan_configs = [];
    while ($row = $config_result->fetch_assoc()) {
        $plan_configs[] = $row;
    }
?>

<!DOCTYPE html>
<html>

<head>
    <?php
        $page_title = "PERTI TMR";
        include("load/header.php");
    ?>

    <script>
        function tooltips() {
            $('[data-toggle="tooltip"]').tooltip('dispose');
            $(function () {
                $('[data-toggle="tooltip"]').tooltip()
            });
        }
    </script>

    <!-- MapLibre GL for TMI compliance maps -->
    <link rel="stylesheet" href="https://unpkg.com/maplibre-gl@4.7.1/dist/maplibre-gl.css" crossorigin=""/>

    <!-- ECharts for demand charts -->
    <script src="https://cdn.jsdelivr.net/npm/echarts@5/dist/echarts.min.js"></script>
    <!-- Chart.js for statsim rates -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

    <style>
        /* TMR Sidebar */
        .tmr-sidebar .nav-link {
            padding: 6px 12px;
            font-size: 0.85rem;
            color: #ccc;
            border-left: 3px solid transparent;
        }
        .tmr-sidebar .nav-link:hover {
            color: #fff;
            background: rgba(255,255,255,0.05);
        }
        .tmr-sidebar .nav-link.active {
            color: #ffc107;
            background: rgba(255,193,7,0.1);
            border-left-color: #ffc107;
        }
        .tmr-sidebar .nav-section-label {
            font-size: 0.65rem;
            text-transform: uppercase;
            color: #666;
            padding: 10px 12px 2px;
            letter-spacing: 0.5px;
        }
        .tmr-sidebar hr {
            border-color: #333;
            margin: 6px 0;
        }

        /* TMR Section headers */
        .tmr-section {
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #333;
        }
        .tmr-section:last-child {
            border-bottom: none;
        }
        .tmr-section-header {
            color: #ffc107;
            font-size: 1.1rem;
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 2px solid #ffc107;
        }

        /* TMR Trigger checkboxes */
        .trigger-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 8px;
        }
        .trigger-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 10px;
            background: rgba(255,255,255,0.03);
            border-radius: 4px;
            cursor: pointer;
        }
        .trigger-item:hover {
            background: rgba(255,255,255,0.08);
        }

        /* Y/N/NA toggle buttons */
        .yn-toggle .btn {
            padding: 4px 16px;
            font-size: 0.8rem;
        }
        .yn-toggle .btn.active {
            font-weight: bold;
        }

        /* TMI table */
        .tmi-table {
            font-size: 0.85rem;
        }
        .tmi-table th {
            font-size: 0.75rem;
            text-transform: uppercase;
            background: #2a2a3e;
            color: #aaa;
        }
        .tmi-badge-ntml { background: #17a2b8; }
        .tmi-badge-program { background: #dc3545; }
        .tmi-badge-advisory { background: #ffc107; color: #000; }
        .tmi-badge-reroute { background: #28a745; }

        /* Demand chart containers */
        .demand-chart-container {
            height: 300px;
            border: 1px solid #333;
            border-radius: 4px;
            margin-bottom: 15px;
        }

        /* Save indicator */
        .save-indicator {
            position: fixed;
            top: 70px;
            right: 20px;
            z-index: 1050;
            padding: 6px 14px;
            border-radius: 4px;
            font-size: 0.8rem;
            display: none;
        }
        .save-indicator.saving {
            display: block;
            background: #ffc107;
            color: #000;
        }
        .save-indicator.saved {
            display: block;
            background: #28a745;
            color: #fff;
        }
        .save-indicator.error {
            display: block;
            background: #dc3545;
            color: #fff;
        }

        /* Statsim Section Styles */
        .statsim-section {
            border: 1px solid #333;
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .statsim-section h6 {
            color: #ffc107;
            margin-bottom: 15px;
            font-weight: bold;
        }
        .statsim-form-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 10px;
        }
        .statsim-form-row .form-group {
            flex: 1;
            min-width: 150px;
            margin-bottom: 0;
        }
        .statsim-form-row label {
            font-size: 0.75rem;
            text-transform: uppercase;
            color: #888;
            margin-bottom: 2px;
        }
        .statsim-results {
            margin-top: 15px;
        }
        .statsim-results table {
            font-size: 0.85rem;
        }
        .statsim-results th {
            text-transform: uppercase;
            font-size: 0.75rem;
            background: #e6e6e6;
        }
        .statsim-loading {
            text-align: center;
            padding: 20px;
            color: #888;
        }
        .statsim-url-display {
            font-size: 0.8rem;
            word-break: break-all;
            background: #0a0a15;
            padding: 8px;
            border-radius: 4px;
            margin-top: 10px;
        }
        .statsim-totals {
            background: #e6e6e6;
            padding: 10px;
            border-radius: 4px;
            margin-top: 10px;
        }
        .statsim-totals .total-item {
            display: inline-block;
            margin-right: 20px;
        }
        .statsim-totals .total-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            color: #888;
        }
        .statsim-totals .total-value {
            font-size: 1.2rem;
            font-weight: bold;
        }
        .statsim-totals .total-value.arrivals {
            color: #f00;
        }
        .statsim-totals .total-value.departures {
            color: #0f0;
        }
        .text-arr { color: #c00 !important; }
        .text-dep { color: #080 !important; }
        .badge-arr { background-color: #f00; color: #fff; }
        .badge-dep { background-color: #0f0; color: #000; }
        .btn-cyan { background-color: #0cc; border-color: #0bb; color: #000; }
        .btn-cyan:hover { background-color: #0bb; border-color: #0aa; color: #000; }
        .airport-rates-card {
            background-color: #c6c6c6;
            border: 1px solid #333;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .airport-rates-header {
            padding: 10px 15px;
            border-bottom: 1px solid #333;
            background: navy;
            color: #ffc107;
            font-size: 0.95rem;
        }
        .airport-rates-header .badge { font-size: 0.75rem; padding: 4px 8px; }
        .hourly-rates-section {
            border: 1px solid #333;
            border-radius: 4px;
            padding: 15px;
            margin-top: 20px;
        }
        .hourly-rates-section h6 {
            color: #17a2b8;
            margin-bottom: 15px;
            font-weight: bold;
        }
        .quick-fill-section { margin-bottom: 15px; }
        .quick-fill-row {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 10px;
            padding: 8px 10px;
            border-radius: 4px;
            background: #e8e8e8;
        }
        .quick-fill-group { display: flex; align-items: center; gap: 4px; }
        .quick-fill-group label { font-size: 0.7rem; text-transform: uppercase; color: #555; margin: 0; white-space: nowrap; }
        .quick-fill-group input { width: 48px; text-align: center; padding: 2px 4px; font-size: 0.8rem; }
        .quick-fill-group .btn { padding: 0.15rem 0.4rem; font-size: 0.7rem; }
        .chart-container { border-radius: 4px; padding: 15px; height: 250px; }
        .airport-table-section { margin-bottom: 20px; }
        .airport-table-header { background: #2a2a3e; padding: 8px 12px; margin-bottom: 0; border-radius: 4px 4px 0 0; color: #ffc107; }
        .hourly-rates-table { font-size: 0.8rem; margin-top: 0; }
        .hourly-rates-table th { text-transform: uppercase; font-size: 0.65rem; font-weight: bold; background: #999999; text-align: center; padding: 6px 4px; }
        .hourly-rates-table .statsim-header { background: #333; color: #aaa; }
        .hourly-rates-table .vatsim-header { background: #444; color: #fff; }
        .hourly-rates-table .rw-header { background: #17525d; color: #0ff; }
        .hourly-rates-table td { padding: 3px 2px; vertical-align: middle; text-align: center; }
        .hourly-rates-table input { width: 42px; text-align: center; padding: 2px 2px; font-size: 0.75rem; }
        .hourly-rates-table .time-cell { font-family: 'Consolas', 'Monaco', monospace; font-weight: bold; font-size: 0.8rem; white-space: nowrap; text-align: center; }
    </style>
</head>

<body>

<?php include('load/nav.php'); ?>

    <section class="d-flex align-items-center position-relative bg-position-center overflow-hidden pt-6 jarallax bg-dark text-light" style="min-height: 200px" data-jarallax data-speed="0.3">
        <div class="container-fluid pt-2 pb-4 py-lg-5">
            <img class="jarallax-img" src="assets/img/jumbotron/main.png" alt="" style="opacity: 50%; height: 100vh;">
            <center>
                <h1><b><span class="text-danger"><?= htmlspecialchars($plan_info['event_name']); ?></span> TMR</b></h1>
                <h6>
                    <a class="text-light" href="plan?<?= $plan_info['id']; ?>"><i class="fas fa-eye text-primary"></i> View PERTI Plan</a>
                    <span class="mx-2">|</span>
                    <span class="text-muted">
                        <?= $plan_info['event_date']; ?>
                        <?= $plan_info['event_start'] ? substr($plan_info['event_start'], 0, 5) . 'z' : ''; ?>
                        <?= $plan_info['event_end_time'] ? '- ' . substr($plan_info['event_end_time'], 0, 5) . 'z' : ''; ?>
                    </span>
                </h6>
            </center>
        </div>
    </section>

    <!-- Save indicator -->
    <div id="saveIndicator" class="save-indicator"></div>

    <!-- Plan data for JS modules -->
    <input type="hidden" id="plan_id" value="<?= $plan_info['id']; ?>">
    <script>
        window.planData = {
            id: <?= json_encode($plan_info['id']); ?>,
            event_name: <?= json_encode($plan_info['event_name']); ?>,
            event_date: <?= json_encode($plan_info['event_date']); ?>,
            event_start: <?= json_encode($plan_info['event_start']); ?>,
            event_end_date: <?= json_encode($plan_info['event_end_date']); ?>,
            event_end_time: <?= json_encode($plan_info['event_end_time']); ?>,
            destinations: <?= json_encode($plan_destinations); ?>,
            configs: <?= json_encode($plan_configs); ?>,
            perm: <?= $perm ? 'true' : 'false'; ?>
        };
    </script>

    <div class="container-fluid mt-3 mb-3">
        <div class="row">
            <!-- TMR Sidebar Navigation -->
            <div class="col-2">
                <div class="tmr-sidebar" style="position: sticky; top: 80px;">
                    <ul class="nav flex-column nav-pills" aria-orientation="vertical">
                        <div class="nav-section-label">TMR Report</div>
                        <li><a class="nav-link active rounded" data-toggle="tab" href="#tmr_triggers"><i class="fas fa-bolt fa-fw"></i> Triggers</a></li>
                        <li><a class="nav-link rounded" data-toggle="tab" href="#tmr_overview"><i class="fas fa-align-left fa-fw"></i> Overview</a></li>
                        <li><a class="nav-link rounded" data-toggle="tab" href="#tmr_airport"><i class="fas fa-plane-arrival fa-fw"></i> Airport Conditions</a></li>
                        <li><a class="nav-link rounded" data-toggle="tab" href="#tmr_weather"><i class="fas fa-cloud-sun fa-fw"></i> Weather</a></li>
                        <li><a class="nav-link rounded" data-toggle="tab" href="#tmr_events"><i class="fas fa-calendar-alt fa-fw"></i> Special Events</a></li>
                        <li><a class="nav-link rounded" data-toggle="tab" href="#tmr_tmis"><i class="fas fa-traffic-light fa-fw"></i> TMIs</a></li>
                        <li><a class="nav-link rounded" data-toggle="tab" href="#tmr_equipment"><i class="fas fa-tools fa-fw"></i> Equipment</a></li>
                        <li><a class="nav-link rounded" data-toggle="tab" href="#tmr_personnel"><i class="fas fa-users fa-fw"></i> Personnel</a></li>
                        <li><a class="nav-link rounded" data-toggle="tab" href="#tmr_plan"><i class="fas fa-file-alt fa-fw"></i> Operational Plan</a></li>
                        <li><a class="nav-link rounded" data-toggle="tab" href="#tmr_findings"><i class="fas fa-search fa-fw"></i> Findings</a></li>
                        <li><a class="nav-link rounded" data-toggle="tab" href="#tmr_recs"><i class="fas fa-lightbulb fa-fw"></i> Recommendations</a></li>
                        <hr>
                        <div class="nav-section-label">Assessment</div>
                        <li><a class="nav-link rounded" data-toggle="tab" href="#scoring"><i class="fas fa-star fa-fw"></i> Scoring</a></li>
                        <li><a class="nav-link rounded" data-toggle="tab" href="#event_data"><i class="fas fa-chart-bar fa-fw"></i> Event Data</a></li>
                        <li><a class="nav-link rounded" data-toggle="tab" href="#tmi_compliance"><i class="fas fa-chart-line fa-fw"></i> TMI Compliance</a></li>
                        <hr>
                        <li><a class="nav-link rounded text-success" href="javascript:void(0)" id="tmr_export_btn"><i class="fas fa-share-alt fa-fw"></i> Export to Discord</a></li>
                        <li>
                            <span class="nav-link text-muted" style="font-size: 0.75rem; padding: 4px 12px;" id="tmr_status_label">
                                <i class="fas fa-circle text-secondary"></i> Not loaded
                            </span>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Main Content -->
            <div class="col-10">
                <div class="tab-content">

                    <!-- ============================================ -->
                    <!-- TMR REPORT SECTIONS -->
                    <!-- ============================================ -->

                    <!-- Tab: Triggers -->
                    <div class="tab-pane fade show active" id="tmr_triggers">
                        <div class="tmr-section">
                            <h5 class="tmr-section-header"><i class="fas fa-bolt"></i> TMR Triggers</h5>
                            <p class="text-muted small mb-3">Select all triggers that apply to this event.</p>

                            <div class="trigger-grid">
                                <label class="trigger-item">
                                    <input type="checkbox" class="tmr-trigger" value="holding_15">
                                    Airborne holding in excess of 15 minutes
                                </label>
                                <label class="trigger-item">
                                    <input type="checkbox" class="tmr-trigger" value="delays_30">
                                    Departure delays in excess of 30 minutes
                                </label>
                                <label class="trigger-item">
                                    <input type="checkbox" class="tmr-trigger" value="no_notice_holding">
                                    No notice airborne holding
                                </label>
                                <label class="trigger-item">
                                    <input type="checkbox" class="tmr-trigger" value="reroutes">
                                    Reroutes
                                </label>
                                <label class="trigger-item">
                                    <input type="checkbox" class="tmr-trigger" value="ground_stop">
                                    Ground stop
                                </label>
                                <label class="trigger-item">
                                    <input type="checkbox" class="tmr-trigger" value="gdp">
                                    Ground Delay Program
                                </label>
                                <label class="trigger-item">
                                    <input type="checkbox" class="tmr-trigger" value="equipment">
                                    Equipment
                                </label>
                            </div>

                            <div class="form-group mt-3">
                                <label class="small text-muted">Host ARTCC</label>
                                <input type="text" class="form-control form-control-sm tmr-field"
                                       id="tmr_host_artcc" data-field="host_artcc"
                                       placeholder="e.g., ZNY" style="max-width: 200px;">
                            </div>
                        </div>
                    </div>

                    <!-- Tab: Overview -->
                    <div class="tab-pane fade" id="tmr_overview">
                        <div class="tmr-section">
                            <h5 class="tmr-section-header"><i class="fas fa-align-left"></i> Overview</h5>
                            <p class="text-muted small mb-3">Provide a 3-4 sentence summary of the event.</p>
                            <textarea class="form-control tmr-field" id="tmr_overview" data-field="overview"
                                      rows="6" placeholder="Summarize the event, key decisions made, and overall outcome..."></textarea>
                        </div>
                    </div>

                    <!-- Tab: Airport Conditions -->
                    <div class="tab-pane fade" id="tmr_airport">
                        <div class="tmr-section">
                            <h5 class="tmr-section-header"><i class="fas fa-plane-arrival"></i> Airport Conditions</h5>
                            <p class="text-muted small mb-3">
                                Format: [FAA Code] | [Landing RWY(s)] | [Departing RWY(s)] | [AAR]/[ADR]
                            </p>
                            <textarea class="form-control tmr-field" id="tmr_airport_conditions" data-field="airport_conditions"
                                      rows="4" placeholder="KJFK | 22L,22R | 31L | 40/44&#10;KEWR | 22L | 22R | 30/30"></textarea>

                            <div class="form-group mt-3">
                                <label class="small">Was the airport configuration validated as correct?</label>
                                <div class="yn-toggle btn-group btn-group-sm" data-field="airport_config_correct">
                                    <button class="btn btn-outline-success" data-value="1">Y</button>
                                    <button class="btn btn-outline-danger" data-value="0">N</button>
                                    <button class="btn btn-outline-secondary active" data-value="">N/A</button>
                                </div>
                            </div>
                        </div>

                        <!-- Demand Charts for plan airports -->
                        <div class="tmr-section">
                            <h6 class="text-info"><i class="fas fa-chart-area"></i> Demand Data (VATSIM ADL)</h6>
                            <p class="text-muted small">Historical arrival/departure demand during the event window.</p>
                            <div id="tmr_demand_charts">
                                <?php if (count($plan_configs) > 0): ?>
                                    <?php foreach ($plan_configs as $cfg): ?>
                                        <div class="mb-3">
                                            <h6 class="text-warning"><?= htmlspecialchars($cfg['airport']); ?>
                                                <span class="text-muted small">AAR: <?= $cfg['aar'] ?? '?' ?> | ADR: <?= $cfg['adr'] ?? '?' ?></span>
                                            </h6>
                                            <div class="demand-chart-container" id="demand_chart_<?= htmlspecialchars($cfg['airport']); ?>"></div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="text-muted text-center py-3">
                                        <i class="fas fa-info-circle"></i> No airport configurations found for this plan.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Tab: Weather -->
                    <div class="tab-pane fade" id="tmr_weather">
                        <div class="tmr-section">
                            <h5 class="tmr-section-header"><i class="fas fa-cloud-sun"></i> Prevailing Weather Conditions</h5>
                            <div class="form-group">
                                <label class="small">Weather Category</label>
                                <select class="form-control form-control-sm tmr-field" id="tmr_weather_category" data-field="weather_category" style="max-width: 200px;">
                                    <option value="">-- Select --</option>
                                    <option value="VFR">VFR</option>
                                    <option value="IFR">IFR</option>
                                    <option value="MVFR">MVFR</option>
                                    <option value="LIFR">LIFR</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="small">Weather Summary</label>
                                <textarea class="form-control tmr-field" id="tmr_weather_summary" data-field="weather_summary"
                                          rows="3" placeholder="Describe weather impact on operations..."></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Tab: Special Events -->
                    <div class="tab-pane fade" id="tmr_events">
                        <div class="tmr-section">
                            <h5 class="tmr-section-header"><i class="fas fa-calendar-alt"></i> Special Events</h5>
                            <textarea class="form-control tmr-field" id="tmr_special_events" data-field="special_events"
                                      rows="4" placeholder="Describe any special events that affected operations (leave blank if N/A)..."></textarea>
                        </div>
                    </div>

                    <!-- Tab: TMIs -->
                    <div class="tab-pane fade" id="tmr_tmis">
                        <div class="tmr-section">
                            <h5 class="tmr-section-header"><i class="fas fa-traffic-light"></i> Traffic Management Initiatives</h5>

                            <!-- DB TMI Lookup -->
                            <div class="mb-3">
                                <button class="btn btn-sm btn-primary" id="tmr_load_db_tmis">
                                    <i class="fas fa-database"></i> Load TMIs from Database
                                </button>
                                <button class="btn btn-sm btn-outline-info" id="tmr_bulk_paste_toggle">
                                    <i class="fas fa-clipboard-list"></i> Bulk Paste
                                </button>
                                <button class="btn btn-sm btn-outline-success" id="tmr_add_manual_tmi">
                                    <i class="fas fa-plus"></i> Add Manual Entry
                                </button>
                                <span class="text-muted small ml-2" id="tmr_tmi_status"></span>
                            </div>

                            <!-- Bulk paste input (hidden by default) -->
                            <div id="tmr_bulk_paste_form" style="display: none;" class="card mb-3">
                                <div class="card-body py-2">
                                    <label class="small">Paste NTML entries (one per line)</label>
                                    <textarea class="form-control form-control-sm" id="tmr_bulk_ntml_input" rows="6"
                                        placeholder="Paste NTML entries here, e.g.:
LAS via FLCHR 20MIT ZLA:ZOA 2359Z-0400Z
LAS via ELLDA 20MIT ZLA:ZAB 2359Z-0400Z
LAS GS (NCT) 0230Z-0315Z issued 0244Z
JFK GDP AAR:30 2200Z-0200Z
BOS 15MIT ZBW:ZNY 2300Z-0100Z"></textarea>
                                    <div class="mt-2">
                                        <button class="btn btn-sm btn-info" id="tmr_parse_bulk_ntml">
                                            <i class="fas fa-file-import"></i> Parse & Add to Table
                                        </button>
                                        <span class="text-muted small ml-2" id="tmr_bulk_parse_status"></span>
                                    </div>
                                </div>
                            </div>

                            <!-- TMI Table -->
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered tmi-table" id="tmr_tmi_table">
                                    <thead>
                                        <tr>
                                            <th style="width: 30px;"><input type="checkbox" id="tmr_tmi_select_all" checked></th>
                                            <th>Type</th>
                                            <th>Element</th>
                                            <th>Detail</th>
                                            <th>Start</th>
                                            <th>End</th>
                                            <th>Status</th>
                                            <th>Source</th>
                                        </tr>
                                    </thead>
                                    <tbody id="tmr_tmi_tbody">
                                        <tr><td colspan="8" class="text-center text-muted py-3">Click "Load TMIs from Database" or add entries manually.</td></tr>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Manual TMI entry form (hidden by default) -->
                            <div id="tmr_manual_tmi_form" style="display: none;" class="card mb-3">
                                <div class="card-body py-2">
                                    <div class="row">
                                        <div class="col-md-2">
                                            <label class="small">Type</label>
                                            <select class="form-control form-control-sm" id="manual_tmi_type">
                                                <option>MIT</option>
                                                <option>MINIT</option>
                                                <option>GS</option>
                                                <option>GDP</option>
                                                <option>AFP</option>
                                                <option>Reroute</option>
                                                <option>APREQ</option>
                                                <option>Delay</option>
                                                <option>Config</option>
                                                <option>Other</option>
                                            </select>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="small">Element</label>
                                            <input type="text" class="form-control form-control-sm" id="manual_tmi_element" placeholder="KJFK, HUBBB...">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="small">Detail</label>
                                            <input type="text" class="form-control form-control-sm" id="manual_tmi_detail" placeholder="20MIT ZLA:ZNY">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="small">Start (UTC)</label>
                                            <input type="text" class="form-control form-control-sm" id="manual_tmi_start" placeholder="2200z">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="small">End (UTC)</label>
                                            <input type="text" class="form-control form-control-sm" id="manual_tmi_end" placeholder="0100z">
                                        </div>
                                        <div class="col-md-1 d-flex align-items-end">
                                            <button class="btn btn-sm btn-success" id="tmr_save_manual_tmi"><i class="fas fa-check"></i></button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <hr>

                            <!-- TMI Assessment -->
                            <h6 class="text-warning mt-3">TMI Assessment</h6>
                            <div class="row">
                                <div class="col-md-4">
                                    <label class="small">Were TMIs complied with?</label>
                                    <div class="yn-toggle btn-group btn-group-sm mb-2" data-field="tmi_complied">
                                        <button class="btn btn-outline-success" data-value="1">Y</button>
                                        <button class="btn btn-outline-danger" data-value="0">N</button>
                                        <button class="btn btn-outline-secondary active" data-value="">N/A</button>
                                    </div>
                                    <textarea class="form-control form-control-sm tmr-field" data-field="tmi_complied_details" rows="2" placeholder="Details..."></textarea>
                                </div>
                                <div class="col-md-4">
                                    <label class="small">Were TMIs effective?</label>
                                    <div class="yn-toggle btn-group btn-group-sm mb-2" data-field="tmi_effective">
                                        <button class="btn btn-outline-success" data-value="1">Y</button>
                                        <button class="btn btn-outline-danger" data-value="0">N</button>
                                        <button class="btn btn-outline-secondary active" data-value="">N/A</button>
                                    </div>
                                    <textarea class="form-control form-control-sm tmr-field" data-field="tmi_effective_details" rows="2" placeholder="Details..."></textarea>
                                </div>
                                <div class="col-md-4">
                                    <label class="small">Were TMIs timely?</label>
                                    <div class="yn-toggle btn-group btn-group-sm mb-2" data-field="tmi_timely">
                                        <button class="btn btn-outline-success" data-value="1">Y</button>
                                        <button class="btn btn-outline-danger" data-value="0">N</button>
                                        <button class="btn btn-outline-secondary active" data-value="">N/A</button>
                                    </div>
                                    <textarea class="form-control form-control-sm tmr-field" data-field="tmi_timely_details" rows="2" placeholder="Details..."></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tab: Equipment -->
                    <div class="tab-pane fade" id="tmr_equipment">
                        <div class="tmr-section">
                            <h5 class="tmr-section-header"><i class="fas fa-tools"></i> Equipment</h5>
                            <textarea class="form-control tmr-field" id="tmr_equipment" data-field="equipment"
                                      rows="4" placeholder="Describe any equipment issues (leave blank if N/A)..."></textarea>
                        </div>
                    </div>

                    <!-- Tab: Personnel -->
                    <div class="tab-pane fade" id="tmr_personnel">
                        <div class="tmr-section">
                            <h5 class="tmr-section-header"><i class="fas fa-users"></i> Personnel / Staffing</h5>
                            <div class="form-group">
                                <label class="small">Was the operational area properly staffed?</label>
                                <div class="yn-toggle btn-group btn-group-sm" data-field="personnel_adequate">
                                    <button class="btn btn-outline-success" data-value="1">Y</button>
                                    <button class="btn btn-outline-danger" data-value="0">N</button>
                                    <button class="btn btn-outline-secondary active" data-value="">N/A</button>
                                </div>
                            </div>
                            <textarea class="form-control tmr-field" id="tmr_personnel_details" data-field="personnel_details"
                                      rows="4" placeholder="Staffing details, gaps, or issues..."></textarea>
                        </div>
                    </div>

                    <!-- Tab: Operational Plan -->
                    <div class="tab-pane fade" id="tmr_plan">
                        <div class="tmr-section">
                            <h5 class="tmr-section-header"><i class="fas fa-file-alt"></i> Operational Plan</h5>
                            <div class="form-group">
                                <label class="small">Link to vATCSCC ADVZY / Operational Plan</label>
                                <input type="text" class="form-control tmr-field" id="tmr_operational_plan_link" data-field="operational_plan_link"
                                       placeholder="https://perti.vatcscc.org/plan?...">
                            </div>
                        </div>
                    </div>

                    <!-- Tab: Findings -->
                    <div class="tab-pane fade" id="tmr_findings">
                        <div class="tmr-section">
                            <h5 class="tmr-section-header"><i class="fas fa-search"></i> Findings</h5>
                            <p class="text-muted small mb-3">Factual observations from the event. What happened?</p>
                            <textarea class="form-control tmr-field" id="tmr_findings" data-field="findings"
                                      rows="8" placeholder="List factual observations about the event..."></textarea>
                        </div>
                    </div>

                    <!-- Tab: Recommendations -->
                    <div class="tab-pane fade" id="tmr_recs">
                        <div class="tmr-section">
                            <h5 class="tmr-section-header"><i class="fas fa-lightbulb"></i> Recommendations & Conclusions</h5>
                            <p class="text-muted small mb-3">Assessed solutions and areas for improvement.</p>
                            <textarea class="form-control tmr-field" id="tmr_recommendations" data-field="recommendations"
                                      rows="8" placeholder="Recommendations for future events..."></textarea>
                        </div>
                    </div>

                    <!-- ============================================ -->
                    <!-- EXISTING ASSESSMENT SECTIONS -->
                    <!-- ============================================ -->

                    <!-- Tab: Scoring -->
                    <div class="tab-pane fade" id="scoring">
                        <div class="row">
                            <div class="col-4">
                                <?php if ($perm == true) { ?>
                                    <button class="btn btn-sm btn-success" data-toggle="modal" data-target="#addscoreModal"><i class="fas fa-plus"></i> Add Score</button>
                                    <hr>
                                <?php } ?>

                                <table class="table table-bordered">
                                    <thead class="text-center bg-secondary">
                                        <th>Category</th>
                                        <th>Score</th>
                                    </thead>
                                    <tbody id="scores"></tbody>
                                </table>
                            </div>

                            <div class="col-8">
                                <?php if ($perm == true) { ?>
                                    <button class="btn btn-sm btn-success" data-toggle="modal" data-target="#addcommentModal"><i class="fas fa-plus"></i> Add Comment</button>
                                    <hr>
                                <?php } ?>

                                <table class="table table-bordered">
                                    <thead class="text-center bg-secondary">
                                        <th>Category</th>
                                        <th>Comments</th>
                                    </thead>
                                    <tbody id="comments"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Tab: Event Data -->
                    <div class="tab-pane fade" id="event_data">
                        <!-- Statsim Traffic Data Section -->
                        <div class="statsim-section">
                            <h6><i class="fas fa-chart-bar"></i> STATSIM TRAFFIC DATA</h6>
                            <div class="statsim-form-row">
                                <div class="form-group">
                                    <label>Airports (ICAO)</label>
                                    <input type="text" class="form-control form-control-sm" id="statsim_airports" placeholder="KJFK, KLAX, KEWR">
                                </div>
                                <div class="form-group">
                                    <label>From (UTC)</label>
                                    <input type="text" class="form-control form-control-sm" id="statsim_from" placeholder="2025-11-28 18:00">
                                </div>
                                <div class="form-group">
                                    <label>To (UTC)</label>
                                    <input type="text" class="form-control form-control-sm" id="statsim_to" placeholder="2025-11-29 01:00">
                                </div>
                                <div class="form-group" style="flex: 0 0 auto; align-self: flex-end;">
                                    <button class="btn btn-sm btn-primary" id="statsim_fetch" title="Fetch from Statsim">
                                        <i class="fas fa-download"></i> Fetch
                                    </button>
                                    <button class="btn btn-sm btn-secondary" id="statsim_open_url" title="Open Statsim URL">
                                        <i class="fas fa-external-link-alt"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-warning" id="statsim_reset_defaults" title="Reset to Plan Defaults">
                                        <i class="fas fa-undo"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="small text-muted mb-2">
                                <i class="fas fa-info-circle"></i>
                                Defaults: T-1hr to max(T+6hr, event end +2hr). Snaps to :00 times.
                            </div>
                            <div id="statsim_url_display" class="statsim-url-display" style="display: none;">
                                <a href="#" target="_blank" id="statsim_url_link"></a>
                            </div>
                            <div id="statsim_results" class="statsim-results"></div>
                        </div>

                        <!-- Hourly Rates Section -->
                        <div class="hourly-rates-section">
                            <h6><i class="fas fa-tachometer-alt"></i> HOURLY RATES (AAR/ADR)</h6>
                            <div id="hourly_rates_container">
                                <div class="text-muted text-center py-3">
                                    <i class="fas fa-info-circle"></i> Fetch Statsim data to populate airport-specific hourly rate inputs.
                                </div>
                            </div>
                            <div class="rates-actions" id="rates_actions" style="display: none;">
                                <button class="btn btn-sm btn-success" onclick="HourlyRates.saveRates()">
                                    <i class="fas fa-save"></i> Save Rates
                                </button>
                                <button class="btn btn-sm btn-secondary" onclick="HourlyRates.exportCSV()">
                                    <i class="fas fa-file-csv"></i> Export CSV
                                </button>
                                <button class="btn btn-sm btn-outline-danger ml-2" onclick="HourlyRates.clearAll()">
                                    <i class="fas fa-eraser"></i> Clear All
                                </button>
                            </div>
                        </div>

                        <hr>

                        <?php if ($perm == true) { ?>
                            <button class="btn btn-sm btn-success" data-toggle="modal" data-target="#adddataModal"><i class="fas fa-plus"></i> Add Data</button>
                            <hr>
                        <?php } ?>

                        <div class="row gutters-tiny py-20" id="data"></div>
                    </div>

                    <!-- Tab: TMI Compliance -->
                    <div class="tab-pane fade" id="tmi_compliance">
                        <div class="tmi-compliance-section">
                            <h5 class="text-warning mb-3"><i class="fas fa-chart-line"></i> TMI Compliance Analysis</h5>

                            <!-- NTML Input Section -->
                            <div class="card mb-3">
                                <div class="card-header py-2" data-toggle="collapse" data-target="#ntmlInputSection" style="cursor: pointer;">
                                    <i class="fas fa-clipboard-list"></i> NTML / Advisory Input
                                    <i class="fas fa-chevron-down float-right"></i>
                                </div>
                                <div class="collapse" id="ntmlInputSection">
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-md-4">
                                                <div class="form-group">
                                                    <label class="small">Facilities (comma-separated)</label>
                                                    <input type="text" class="form-control form-control-sm" id="tmi_destinations"
                                                           placeholder="ZLA, A80, KLAS">
                                                </div>
                                                <div class="form-group">
                                                    <label class="small">Event Start (UTC)</label>
                                                    <input type="text" class="form-control form-control-sm" id="tmi_event_start"
                                                           placeholder="2026-01-17 22:00">
                                                </div>
                                                <div class="form-group">
                                                    <label class="small">Event End (UTC)</label>
                                                    <input type="text" class="form-control form-control-sm" id="tmi_event_end"
                                                           placeholder="2026-01-18 05:00">
                                                </div>
                                            </div>
                                            <div class="col-md-8">
                                                <div class="form-group">
                                                    <label class="small">NTML Entries (paste from Discord)</label>
                                                    <textarea class="form-control form-control-sm" id="tmi_ntml_input" rows="8"
                                                        placeholder="Paste NTML entries here, e.g.:
LAS via FLCHR 20MIT ZLA:ZOA 2359Z-0400Z
LAS via ELLDA 20MIT ZLA:ZAB 2359Z-0400Z
LAS GS (NCT) 0230Z-0315Z issued 0244Z"></textarea>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="mt-2">
                                            <button class="btn btn-sm btn-outline-primary" id="save_ntml_config">
                                                <i class="fas fa-save"></i> Save Configuration
                                            </button>
                                            <button class="btn btn-sm btn-outline-secondary" id="load_ntml_config">
                                                <i class="fas fa-folder-open"></i> Load Saved
                                            </button>
                                            <span class="text-muted small ml-2" id="ntml_save_status"></span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Results Section -->
                            <div class="mb-3">
                                <button class="btn btn-sm btn-primary" id="load_tmi_results">
                                    <i class="fas fa-download"></i> Load Results
                                </button>
                                <button class="btn btn-sm btn-success" id="run_tmi_analysis">
                                    <i class="fas fa-play"></i> Run Analysis
                                </button>
                                <span class="text-muted small ml-2" id="tmi_status"></span>
                            </div>

                            <div id="tmi_results_container">
                                <div class="text-muted text-center py-4">
                                    <i class="fas fa-info-circle"></i>
                                    Click "Load Results" to view TMI compliance analysis for this event.
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

</body>
<?php include('load/footer.php'); ?>


<?php if ($perm == true) { ?>

<!-- Add Score Modal -->
<div class="modal fade" id="addscoreModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Score</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="post" id="addscore">
                <div class="modal-body">
                    <input type="hidden" name="p_id" value="<?= $id; ?>">
                    Staffing: <input type="number" class="form-control" name="staffing" min="1" max="5">
                    Tactical (Real-Time): <input type="number" class="form-control" name="tactical" min="1" max="5">
                    Other Coordination: <input type="number" class="form-control" name="other" min="1" max="5">
                    PERTI Plan: <input type="number" class="form-control" name="perti" min="1" max="5">
                    NTML/Advisory Usage: <input type="number" class="form-control" name="ntml" min="1" max="5">
                    TMI: <input type="number" class="form-control" name="tmi" min="1" max="5">
                    ACE Team Implementation: <input type="number" class="form-control" name="ace" min="1" max="5">
                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-success" value="Add">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal">Close</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Score Modal -->
<div class="modal fade" id="editscoreModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Score</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="post" id="editscore">
                <div class="modal-body">
                    <input type="hidden" name="id" id="id">
                    Staffing: <input type="number" class="form-control" name="staffing" id="staffing" min="1" max="5">
                    Tactical (Real-Time): <input type="number" class="form-control" name="tactical" id="tactical" min="1" max="5">
                    Other Coordination: <input type="number" class="form-control" name="other" id="other" min="1" max="5">
                    PERTI Plan: <input type="number" class="form-control" name="perti" id="perti" min="1" max="5">
                    NTML/Advisory Usage: <input type="number" class="form-control" name="ntml" id="ntml" min="1" max="5">
                    TMI: <input type="number" class="form-control" name="tmi" id="tmi" min="1" max="5">
                    ACE Team Implementation: <input type="number" class="form-control" name="ace" id="ace" min="1" max="5">
                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-warning" value="Edit">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal">Close</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Comment Modal -->
<div class="modal fade" id="addcommentModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Comment</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="post" id="addcomment">
                <div class="modal-body">
                    <input type="hidden" name="p_id" value="<?= $id; ?>">
                    Staffing: <textarea class="form-control rounded-0" name="staffing" id="a_staffing" rows="5"></textarea><hr>
                    Tactical (Real-Time): <textarea class="form-control rounded-0" name="tactical" id="a_tactical" rows="5"></textarea><hr>
                    Other Coordination: <textarea class="form-control rounded-0" name="other" id="a_other" rows="5"></textarea><hr>
                    PERTI Plan: <textarea class="form-control rounded-0" name="perti" id="a_perti" rows="5"></textarea><hr>
                    NTML/Advisory Usage: <textarea class="form-control rounded-0" name="ntml" id="a_ntml" rows="5"></textarea><hr>
                    TMI: <textarea class="form-control rounded-0" name="tmi" id="a_tmi" rows="5"></textarea><hr>
                    ACE Team Implementation: <textarea class="form-control rounded-0" name="ace" id="a_ace" rows="5"></textarea>
                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-success" value="Add">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal">Close</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Comment Modal -->
<div class="modal fade" id="editcommentModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Comment</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="post" id="editcomment">
                <div class="modal-body">
                    <input type="hidden" name="id" id="id">
                    Staffing: <textarea class="form-control rounded-0" name="staffing" id="e_staffing" rows="5"></textarea><hr>
                    Tactical (Real-Time): <textarea class="form-control rounded-0" name="tactical" id="e_tactical" rows="5"></textarea><hr>
                    Other Coordination: <textarea class="form-control rounded-0" name="other" id="e_other" rows="5"></textarea><hr>
                    PERTI Plan: <textarea class="form-control rounded-0" name="perti" id="e_perti" rows="5"></textarea><hr>
                    NTML/Advisory Usage: <textarea class="form-control rounded-0" name="ntml" id="e_ntml" rows="5"></textarea><hr>
                    TMI: <textarea class="form-control rounded-0" name="tmi" id="e_tmi" rows="5"></textarea><hr>
                    ACE Team Implementation: <textarea class="form-control rounded-0" name="ace" id="e_ace" rows="5"></textarea>
                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-warning" value="Edit">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal">Close</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Event Data Modal -->
<div class="modal fade" id="adddataModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Event Data</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="post" id="adddata">
                <div class="modal-body">
                    <input type="hidden" name="p_id" value="<?= $id; ?>">
                    Summary: <textarea class="form-control" name="summary" rows="3"></textarea>
                    <hr>
                    Image (URL): <input type="text" class="form-control" name="image_url">
                    Source (URL): <input type="text" class="form-control" name="source_url">
                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-success" value="Add">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal">Close</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Event Data Modal -->
<div class="modal fade" id="editdataModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Event Data</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="post" id="editdata">
                <div class="modal-body">
                    <input type="hidden" name="id" id="id">
                    Summary: <textarea class="form-control" name="summary" id="summary" rows="3"></textarea>
                    <hr>
                    Image (URL): <input type="text" class="form-control" name="image_url" id="image_url">
                    Source (URL): <input type="text" class="form-control" name="source_url" id="source_url">
                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-warning" value="Edit">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal">Close</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php } ?>

<!-- Scripts -->
<script src="assets/js/lib/i18n.js"></script>
<script src="assets/locales/index.js"></script>
<script src="assets/js/config/phase-colors.js"></script>
<script src="assets/js/config/filter-colors.js"></script>
<script src="assets/js/statsim_rates.js?v=2" defer></script>
<script src="assets/js/demand.js"></script>
<script src="assets/js/review.js"></script>
<script src="assets/js/tmr_report.js?v=4"></script>
<script src="https://unpkg.com/maplibre-gl@4.7.1/dist/maplibre-gl.js" crossorigin=""></script>
<script src="assets/js/tmi_compliance.js"></script>

</html>
