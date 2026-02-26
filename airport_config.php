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

    // Check Perms
    $perm = false;
    if (!defined('DEV')) {
        if (isset($_SESSION['VATSIM_CID'])) {

            // Getting CID Value
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

?>

<!DOCTYPE html>
<html>

<head>

    <!-- Import CSS -->
    <?php
        $page_title = "Airport Configuration";
        include("load/header.php");
    ?>

    <style>
        .rate-grid {
            font-size: 0.85rem;
        }
        .rate-grid input {
            width: 78px;
            min-width: 78px;
            text-align: center;
        }
        .rate-grid input[type="number"] {
            padding-right: 1.2rem;
        }
        @media (max-width: 768px) {
            .rate-grid input {
                width: 66px;
                min-width: 66px;
            }
        }
        .rate-grid th, .rate-grid td {
            padding: 4px 8px;
            vertical-align: middle;
        }
        .rate-section {
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 10px;
            margin-bottom: 15px;
        }
        .rate-section h6 {
            margin-bottom: 10px;
            color: #495057;
        }
        /* Config table styling */
        #configs thead th {
            font-size: 0.8rem;
            padding: 4px 6px;
        }
        #configs tbody td {
            font-size: 0.85rem;
            padding: 4px 6px;
        }
        .vatsim-header {
            background-color: #2a5298 !important;
            color: white;
        }
        .vatsim-arr-header {
            background-color: #3d6ab3 !important;
            color: white;
        }
        .vatsim-dep-header {
            background-color: #5580c7 !important;
            color: white;
        }
        .rw-header {
            background-color: #4a7c59 !important;
            color: white;
        }
        .rw-arr-header {
            background-color: #5c9a6e !important;
            color: white;
        }
        .rw-dep-header {
            background-color: #6eb583 !important;
            color: white;
        }
        .weather-header {
            font-size: 0.7rem !important;
            font-weight: normal;
        }
        .section-divider {
            border-left: 3px solid #dee2e6 !important;
        }

        /* Sticky header for scrolling */
        #configs-container {
            max-height: 70vh;
            overflow-y: auto;
            position: relative;
        }
        #configs thead {
            position: sticky;
            top: 0;
            z-index: 10;
        }
        #configs thead tr:nth-child(1) th {
            position: sticky;
            top: 0;
            z-index: 11;
        }
        #configs thead tr:nth-child(2) th {
            position: sticky;
            top: 28px; /* Height of first row */
            z-index: 10;
        }
        #configs thead tr:nth-child(3) th {
            position: sticky;
            top: 52px; /* Height of first two rows */
            z-index: 9;
        }

        /* Rate comparison indicators */
        .rate-higher {
            position: relative;
        }
        .rate-higher::after {
            content: "▲";
            font-size: 0.6rem;
            color: #28a745;
            position: absolute;
            top: 2px;
            right: 2px;
        }
        .rate-lower {
            position: relative;
        }
        .rate-lower::after {
            content: "▼";
            font-size: 0.6rem;
            color: #dc3545;
            position: absolute;
            top: 2px;
            right: 2px;
        }
        .no-rw-data {
            opacity: 0.5;
        }

        /* Stats summary styling */
        .config-stats {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
        }
        .config-stats .stat-item {
            text-align: center;
            padding: 10px;
        }
        .config-stats .stat-value {
            font-size: 1.5rem;
            font-weight: bold;
            color: #2a5298;
        }
        .config-stats .stat-label {
            font-size: 0.8rem;
            color: #6c757d;
        }

        /* Sortable columns */
        .sortable {
            cursor: pointer;
            user-select: none;
        }
        .sortable:hover {
            background-color: rgba(255,255,255,0.1) !important;
        }
        .sortable::after {
            content: " ⇅";
            opacity: 0.5;
            font-size: 0.7rem;
        }
        .sortable.sort-asc::after {
            content: " ▲";
            opacity: 1;
        }
        .sortable.sort-desc::after {
            content: " ▼";
            opacity: 1;
        }

        /* Bulk selection */
        .bulk-checkbox {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        .bulk-actions {
            display: none;
            background: #fff3cd;
            padding: 10px 15px;
            border-radius: 4px;
            margin-bottom: 10px;
        }
        .bulk-actions.active {
            display: block;
        }

        /* Inactive config styling */
        .config-inactive {
            opacity: 0.5;
            background-color: #f0f0f0 !important;
        }
        .config-inactive td {
            text-decoration: line-through;
        }

        /* Weather impact badge */
        .weather-impact {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-left: 4px;
            vertical-align: middle;
        }
        .weather-impact-0 { background-color: #28a745; }
        .weather-impact-1 { background-color: #ffc107; }
        .weather-impact-2 { background-color: #fd7e14; }
        .weather-impact-3 { background-color: #dc3545; }

        /* Rate history */
        .rate-trend {
            font-size: 0.65rem;
            margin-left: 2px;
        }
        .rate-trend-up { color: #28a745; }
        .rate-trend-down { color: #dc3545; }
        .rate-trend-same { color: #6c757d; }

        /* History modal */
        .history-table {
            font-size: 0.85rem;
        }
        .history-table th {
            font-size: 0.75rem;
            text-transform: uppercase;
            color: #6c757d;
        }
        .change-increased { color: #28a745; }
        .change-decreased { color: #dc3545; }
        .change-new { color: #17a2b8; }
        .change-removed { color: #6c757d; text-decoration: line-through; }

        /* Modifier badges */
        .modifiers-cell {
            max-width: 200px;
            white-space: normal;
        }
        .badge-outline-primary {
            color: #3B82F6;
            background-color: transparent;
            border: 1px solid #3B82F6;
        }
        .badge-outline-info {
            color: #06B6D4;
            background-color: transparent;
            border: 1px solid #06B6D4;
        }
        .badge-outline-success {
            color: #10B981;
            background-color: transparent;
            border: 1px solid #10B981;
        }
        .badge-outline-warning {
            color: #F59E0B;
            background-color: transparent;
            border: 1px solid #F59E0B;
        }
        .badge-outline-danger {
            color: #EF4444;
            background-color: transparent;
            border: 1px solid #EF4444;
        }
        .badge-outline-secondary {
            color: #6B7280;
            background-color: transparent;
            border: 1px solid #6B7280;
        }
        .badge-outline-dark {
            color: #374151;
            background-color: transparent;
            border: 1px solid #374151;
        }
        .badge-sm {
            font-size: 0.7rem;
            padding: 2px 5px;
        }
        .badge-xs {
            font-size: 0.6rem;
            padding: 1px 3px;
        }

        /* Runway cell styling */
        .runway-cell {
            font-family: 'Consolas', 'Monaco', monospace;
            font-size: 0.85rem;
            white-space: nowrap;
        }
        .runway-cell small {
            font-size: 0.7rem;
        }

        /* Modifier legend */
        .modifier-legend {
            font-size: 0.75rem;
            padding: 8px;
            background: #f8f9fa;
            border-radius: 4px;
            margin-bottom: 10px;
        }
        .modifier-legend .badge {
            font-size: 0.65rem;
            margin-right: 8px;
        }

        /* Config name formatting */
        .config-name-cell {
            white-space: normal;
            line-height: 1.2;
        }
        .config-formatted {
            display: inline-block;
            text-align: left;
        }
        .config-label {
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        .config-arr .config-label {
            color: #dc3545;
        }
        .config-arr .runway-id {
            color: #dc3545;
            font-weight: 600;
            font-family: 'Consolas', 'Monaco', monospace;
        }
        .config-dep .config-label {
            color: #28a745;
        }
        .config-dep .runway-id {
            color: #28a745;
            font-weight: 600;
            font-family: 'Consolas', 'Monaco', monospace;
        }
        .config-sep {
            display: none;
        }
        .runway-id {
            font-family: 'Consolas', 'Monaco', monospace;
            font-weight: 600;
        }
    </style>

</head>

<body>

<?php
include('load/nav.php');
?>

    <section class="d-flex align-items-center position-relative bg-position-center fh-section overflow-hidden pt-6 jarallax bg-dark text-light" data-jarallax data-speed="0.3" style="pointer-events: all;">
        <div class="container-fluid pt-2 pb-5 py-lg-6">
            <img class="jarallax-img" src="assets/img/jumbotron/main.png" alt="" style="opacity: 50%;">

            <center>
                <h1><?= __('airportConfig.page.title') ?></h1>
                <h4 class="text-white hvr-bob pl-1">
                    <a href="#configs" style="text-decoration: none; color: white;"><i class="fas fa-chevron-down text-danger"></i> <?= __('airportConfig.page.subtitle') ?></a>
                </h4>
            </center>

        </div>
    </section>


    <div class="container-fluid">

        <!-- Stats Summary -->
        <div class="config-stats mt-3" id="config-stats">
            <div class="row">
                <div class="col-md-2 stat-item">
                    <div class="stat-value" id="stat-total">-</div>
                    <div class="stat-label">Total Configs</div>
                </div>
                <div class="col-md-2 stat-item">
                    <div class="stat-value" id="stat-airports">-</div>
                    <div class="stat-label"><?= __('eventAar.page.airport') ?></div>
                </div>
                <div class="col-md-2 stat-item">
                    <div class="stat-value" id="stat-with-vatsim">-</div>
                    <div class="stat-label">With VATSIM Rates</div>
                </div>
                <div class="col-md-2 stat-item">
                    <div class="stat-value" id="stat-with-rw">-</div>
                    <div class="stat-label">With RW Rates</div>
                </div>
                <div class="col-md-2 stat-item">
                    <div class="stat-value text-success" id="stat-vatsim-higher">-</div>
                    <div class="stat-label">VATSIM > RW</div>
                </div>
                <div class="col-md-2 stat-item">
                    <div class="stat-value text-danger" id="stat-vatsim-lower">-</div>
                    <div class="stat-label">VATSIM < RW</div>
                </div>
            </div>
        </div>

        <div class="row mb-2">
            <div class="col-md-3">
                <div class="input-group">
                    <input type="text" class="form-control" id="search" placeholder="Search FAA/ICAO/Config..." maxlength="10">
                    <div class="input-group-append">
                        <button class="btn btn-info btn-sm" id="searchBtn"><i class="fas fa-search"></i></button>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <select class="form-control form-control-sm" id="filterRates">
                    <option value="">All Configs</option>
                    <option value="has-rw">Has Real-World Rates</option>
                    <option value="no-rw">Missing Real-World Rates</option>
                    <option value="vatsim-higher">VATSIM Higher than RW</option>
                    <option value="vatsim-lower">VATSIM Lower than RW</option>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-control form-control-sm" id="filterActive">
                    <option value="active">Active Only</option>
                    <option value="all">All (incl. Inactive)</option>
                    <option value="inactive">Inactive Only</option>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-control form-control-sm" id="filterModifier">
                    <option value="">All Modifiers</option>
                    <optgroup label="Parallel Operations">
                        <option value="SIMOS">SIMOS</option>
                        <option value="STAGGERED">Staggered</option>
                        <option value="SIDE_BY_SIDE">Side-by-Side</option>
                        <option value="IN_TRAIL">In-Trail</option>
                    </optgroup>
                    <optgroup label="Approach Type">
                        <option value="ILS">ILS</option>
                        <option value="VOR">VOR</option>
                        <option value="RNAV">RNAV</option>
                        <option value="LDA">LDA</option>
                        <option value="LOC">LOC</option>
                        <option value="FMS_VISUAL">FMS Visual</option>
                    </optgroup>
                    <optgroup label="Special Operations">
                        <option value="LAHSO">LAHSO</option>
                        <option value="SINGLE_RWY">Single Runway</option>
                        <option value="CIRCLING">Circling</option>
                        <option value="VAP">Visual Approach</option>
                    </optgroup>
                    <optgroup label="Visibility Category">
                        <option value="CAT_II">CAT II</option>
                        <option value="CAT_III">CAT III</option>
                    </optgroup>
                    <optgroup label="Weather/Time">
                        <option value="WINTER">Winter</option>
                        <option value="NOISE">Noise Abatement</option>
                        <option value="DAY">Day Only</option>
                        <option value="NIGHT">Night Only</option>
                    </optgroup>
                </select>
            </div>
            <div class="col-md-3 text-right">
                <?php if ($perm == true) { ?>
                    <button class="btn btn-success btn-sm" data-target="#addconfigModal" data-toggle="modal"><i class="fas fa-plus"></i> <?= __('airportConfig.page.addConfig') ?></button>
                <?php } ?>
                <button class="btn btn-secondary btn-sm" id="exportBtn" data-toggle="tooltip" title="Export to CSV"><i class="fas fa-download"></i> <?= __('airportConfig.page.export') ?></button>
            </div>
        </div>

        <?php if ($perm == true) { ?>
        <!-- Bulk Actions Bar -->
        <div class="bulk-actions" id="bulkActions">
            <span id="bulkCount">0</span> configs selected:
            <button class="btn btn-sm btn-warning ml-2" id="bulkActivate"><i class="fas fa-check"></i> <?= __('airportConfig.page.activate') ?></button>
            <button class="btn btn-sm btn-secondary ml-1" id="bulkDeactivate"><i class="fas fa-ban"></i> <?= __('airportConfig.page.deactivate') ?></button>
            <button class="btn btn-sm btn-danger ml-1" id="bulkDelete"><i class="fas fa-trash"></i> <?= __('common.delete') ?></button>
            <button class="btn btn-sm btn-outline-dark ml-2" id="bulkClear"><i class="fas fa-times"></i> <?= __('airportConfig.page.clearSelection') ?></button>
        </div>
        <?php } ?>

        <!-- Modifier Legend -->
        <div class="modifier-legend" id="modifierLegend">
            <strong>Modifier Legend:</strong>
            <span class="badge badge-primary">SIMOS</span> Parallel Ops
            <span class="badge badge-info">ILS</span> Approach Type
            <span class="badge badge-success">ARR</span> Traffic Bias
            <span class="badge badge-warning">II</span> Visibility Cat
            <span class="badge badge-danger">LAHSO</span> Special Ops
            <span class="badge badge-secondary">NGT</span> Time
            <span class="badge badge-info">WNT</span> Weather
            |
            <span class="badge badge-outline-primary">Outline</span> = Runway-specific modifier
        </div>

        <div id="configs-container">
            <table class="table table-sm table-striped table-bordered" id="configs" style="width: 100%;">
                <thead class="table-dark text-light">
                    <!-- Row 1: Main categories -->
                    <tr>
                        <?php if ($perm == true) { ?>
                            <th rowspan="3" class="text-center align-middle" style="width: 30px;">
                                <input type="checkbox" class="bulk-checkbox" id="selectAll" title="Select All">
                            </th>
                        <?php } ?>
                        <th rowspan="3" class="text-center align-middle sortable" data-sort="0" data-type="text">FAA</th>
                        <th rowspan="3" class="text-center align-middle sortable" data-sort="1" data-type="text">ICAO</th>
                        <th rowspan="3" class="text-center align-middle">Config</th>
                        <th rowspan="3" class="text-center align-middle">Modifiers</th>
                        <th rowspan="3" class="text-center align-middle">ARR<br>Rwys</th>
                        <th rowspan="3" class="text-center align-middle">DEP<br>Rwys</th>
                        <th colspan="7" class="text-center vatsim-header section-divider">VATSIM Rates</th>
                        <th colspan="6" class="text-center rw-header section-divider">Real-World Rates</th>
                        <th rowspan="3" class="align-middle"></th>
                    </tr>
                    <!-- Row 2: ARR/DEP categories -->
                    <tr>
                        <th colspan="5" class="text-center vatsim-arr-header section-divider">AAR</th>
                        <th colspan="2" class="text-center vatsim-dep-header">ADR</th>
                        <th colspan="4" class="text-center rw-arr-header section-divider">AAR</th>
                        <th colspan="2" class="text-center rw-dep-header">ADR</th>
                    </tr>
                    <!-- Row 3: Weather categories (sortable by rate value) -->
                    <tr>
                        <!-- VATSIM AAR -->
                        <th class="text-center weather-header vatsim-arr-header section-divider sortable" data-sort="6" data-type="num" data-toggle="tooltip" title="VMC Arrival Rate - Click to sort">VMC</th>
                        <th class="text-center weather-header vatsim-arr-header sortable" data-sort="7" data-type="num" data-toggle="tooltip" title="LVMC Arrival Rate - Click to sort">LVMC</th>
                        <th class="text-center weather-header vatsim-arr-header sortable" data-sort="8" data-type="num" data-toggle="tooltip" title="IMC Arrival Rate - Click to sort">IMC</th>
                        <th class="text-center weather-header vatsim-arr-header sortable" data-sort="9" data-type="num" data-toggle="tooltip" title="LIMC Arrival Rate - Click to sort">LIMC</th>
                        <th class="text-center weather-header vatsim-arr-header sortable" data-sort="10" data-type="num" data-toggle="tooltip" title="VLIMC Arrival Rate - Click to sort">VLIMC</th>
                        <!-- VATSIM ADR -->
                        <th class="text-center weather-header vatsim-dep-header sortable" data-sort="11" data-type="num" data-toggle="tooltip" title="VMC Departure Rate - Click to sort">VMC</th>
                        <th class="text-center weather-header vatsim-dep-header sortable" data-sort="12" data-type="num" data-toggle="tooltip" title="IMC Departure Rate - Click to sort">IMC</th>
                        <!-- RW AAR -->
                        <th class="text-center weather-header rw-arr-header section-divider sortable" data-sort="13" data-type="num" data-toggle="tooltip" title="RW VMC Arrival Rate - Click to sort">VMC</th>
                        <th class="text-center weather-header rw-arr-header sortable" data-sort="14" data-type="num" data-toggle="tooltip" title="RW LVMC Arrival Rate - Click to sort">LVMC</th>
                        <th class="text-center weather-header rw-arr-header sortable" data-sort="15" data-type="num" data-toggle="tooltip" title="RW IMC Arrival Rate - Click to sort">IMC</th>
                        <th class="text-center weather-header rw-arr-header sortable" data-sort="16" data-type="num" data-toggle="tooltip" title="RW LIMC Arrival Rate - Click to sort">LIMC</th>
                        <!-- RW ADR -->
                        <th class="text-center weather-header rw-dep-header sortable" data-sort="17" data-type="num" data-toggle="tooltip" title="RW VMC Departure Rate - Click to sort">VMC</th>
                        <th class="text-center weather-header rw-dep-header sortable" data-sort="18" data-type="num" data-toggle="tooltip" title="RW IMC Departure Rate - Click to sort">IMC</th>
                    </tr>
                </thead>

                <tbody id="configs_table"></tbody>
            </table>
        </div><!-- /configs-container -->
    </div>


<?php include('load/footer.php'); ?>


<!-- Add Config Modal -->
<div class="modal fade" id="addconfigModal" tabindex="-1" role="dialog" aria-labelledby="addconfigModalLabel"
    aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addconfigModalLabel"><?= __('airportConfig.page.addConfiguration') ?></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="addconfig">

                <div class="modal-body">

                    <div class="row">
                        <div class="col-md-4">
                            <label><?= __('airportConfig.page.airportFaa') ?></label>
                            <input type="text" class="form-control" name="airport_faa" id="add_airport_faa" maxlength="4" placeholder="DTW" required>
                        </div>
                        <div class="col-md-4">
                            <label><?= __('airportConfig.page.airportIcao') ?></label>
                            <input type="text" class="form-control" name="airport_icao" id="add_airport_icao" maxlength="4" placeholder="KDTW">
                            <small class="text-muted">Auto-filled, edit if needed</small>
                        </div>
                        <div class="col-md-4">
                            <label><?= __('airportConfig.page.configCode') ?></label>
                            <input type="text" class="form-control" name="config_code" maxlength="16" placeholder="SF">
                        </div>
                    </div>

                    <div class="row mt-2">
                        <div class="col-md-12">
                            <label><?= __('airportConfig.page.configName') ?></label>
                            <input type="text" class="form-control" name="config_name" maxlength="32" placeholder="South Flow" required>
                        </div>
                    </div>

                    <hr>

                    <div class="row">
                        <div class="col-md-6">
                            <label><?= __('airportConfig.page.arrivalRunways') ?></label>
                            <input type="text" class="form-control" name="arr_runways" maxlength="32" placeholder="21L/22R" required>
                            <small class="text-muted">Separate with / in priority order</small>
                        </div>
                        <div class="col-md-6">
                            <label><?= __('airportConfig.page.departureRunways') ?></label>
                            <input type="text" class="form-control" name="dep_runways" maxlength="32" placeholder="22L/21R" required>
                            <small class="text-muted">Separate with / in priority order</small>
                        </div>
                    </div>

                    <div class="row mt-2">
                        <div class="col-md-12">
                            <label>Config Modifiers</label>
                            <input type="hidden" name="config_modifiers[]" value="">
                            <select class="form-control" name="config_modifiers[]" id="add_config_modifiers" multiple size="8">
                                <optgroup label="Parallel Operations">
                                    <option value="SIMOS">SIMOS</option>
                                    <option value="STAGGERED">Staggered</option>
                                    <option value="SIDE_BY_SIDE">Side-by-Side</option>
                                    <option value="IN_TRAIL">In-Trail</option>
                                </optgroup>
                                <optgroup label="Approach Type">
                                    <option value="ILS">ILS</option>
                                    <option value="VOR">VOR</option>
                                    <option value="RNAV">RNAV</option>
                                    <option value="LDA">LDA</option>
                                    <option value="LOC">LOC</option>
                                    <option value="FMS_VISUAL">FMS Visual</option>
                                </optgroup>
                                <optgroup label="Special Operations">
                                    <option value="LAHSO">LAHSO</option>
                                    <option value="SINGLE_RWY">Single Runway</option>
                                    <option value="CIRCLING">Circling</option>
                                    <option value="VAP">Visual Approach</option>
                                </optgroup>
                                <optgroup label="Visibility Category">
                                    <option value="CAT_II">CAT II</option>
                                    <option value="CAT_III">CAT III</option>
                                </optgroup>
                                <optgroup label="Weather/Time">
                                    <option value="WINTER">Winter</option>
                                    <option value="NOISE">Noise Abatement</option>
                                    <option value="DAY">Day Only</option>
                                    <option value="NIGHT">Night Only</option>
                                </optgroup>
                            </select>
                            <small class="text-muted">Config-level only. Hold Ctrl/Cmd to select multiple.</small>
                        </div>
                    </div>

                    <hr>

                    <!-- VATSIM Rates -->
                    <div class="rate-section">
                        <h6><i class="fas fa-plane"></i> <?= __('airportConfig.page.vatsimRates') ?></h6>
                        <table class="table table-sm rate-grid mb-0">
                            <thead>
                                <tr>
                                    <th></th>
                                    <th class="text-center">VMC</th>
                                    <th class="text-center">LVMC</th>
                                    <th class="text-center">IMC</th>
                                    <th class="text-center">LIMC</th>
                                    <th class="text-center">VLIMC</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><strong>ARR</strong></td>
                                    <td><input type="number" class="form-control form-control-sm" name="vatsim_vmc_aar" placeholder="76"></td>
                                    <td><input type="number" class="form-control form-control-sm" name="vatsim_lvmc_aar" placeholder="70"></td>
                                    <td><input type="number" class="form-control form-control-sm" name="vatsim_imc_aar" placeholder="64"></td>
                                    <td><input type="number" class="form-control form-control-sm" name="vatsim_limc_aar" placeholder="60"></td>
                                    <td><input type="number" class="form-control form-control-sm" name="vatsim_vlimc_aar" placeholder="50"></td>
                                </tr>
                                <tr>
                                    <td><strong>DEP</strong></td>
                                    <td><input type="number" class="form-control form-control-sm" name="vatsim_vmc_adr" placeholder="60"></td>
                                    <td><input type="number" class="form-control form-control-sm" name="vatsim_lvmc_adr" placeholder="55"></td>
                                    <td><input type="number" class="form-control form-control-sm" name="vatsim_imc_adr" placeholder="48"></td>
                                    <td><input type="number" class="form-control form-control-sm" name="vatsim_limc_adr" placeholder="44"></td>
                                    <td><input type="number" class="form-control form-control-sm" name="vatsim_vlimc_adr" placeholder="40"></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Real-World Rates (Collapsible) -->
                    <div class="rate-section">
                        <h6>
                            <a data-toggle="collapse" href="#addRwRates" role="button" aria-expanded="false">
                                <i class="fas fa-globe"></i> Real-World Rates <small class="text-muted">(click to expand)</small>
                            </a>
                        </h6>
                        <div class="collapse" id="addRwRates">
                            <table class="table table-sm rate-grid mb-0">
                                <thead>
                                    <tr>
                                        <th></th>
                                        <th class="text-center">VMC</th>
                                        <th class="text-center">LVMC</th>
                                        <th class="text-center">IMC</th>
                                        <th class="text-center">LIMC</th>
                                        <th class="text-center">VLIMC</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><strong>ARR</strong></td>
                                        <td><input type="number" class="form-control form-control-sm" name="rw_vmc_aar"></td>
                                        <td><input type="number" class="form-control form-control-sm" name="rw_lvmc_aar"></td>
                                        <td><input type="number" class="form-control form-control-sm" name="rw_imc_aar"></td>
                                        <td><input type="number" class="form-control form-control-sm" name="rw_limc_aar"></td>
                                        <td><input type="number" class="form-control form-control-sm" name="rw_vlimc_aar"></td>
                                    </tr>
                                    <tr>
                                        <td><strong>DEP</strong></td>
                                        <td><input type="number" class="form-control form-control-sm" name="rw_vmc_adr"></td>
                                        <td><input type="number" class="form-control form-control-sm" name="rw_lvmc_adr"></td>
                                        <td><input type="number" class="form-control form-control-sm" name="rw_imc_adr"></td>
                                        <td><input type="number" class="form-control form-control-sm" name="rw_limc_adr"></td>
                                        <td><input type="number" class="form-control form-control-sm" name="rw_vlimc_adr"></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-success" value="<?= __('airportConfig.page.addConfig') ?>">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal"><?= __('common.close') ?></button>
                </div>
        </div>

        </form>

    </div>
</div>

<!-- Update Config Modal -->
<div class="modal fade" id="updateconfigModal" tabindex="-1" role="dialog" aria-labelledby="updateconfigModalLabel"
    aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="updateconfigModalLabel"><?= __('airportConfig.page.updateConfiguration') ?></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="updateconfig">

                <div class="modal-body">

                    <input type="hidden" name="config_id" id="config_id" required>

                    <div class="row">
                        <div class="col-md-4">
                            <label><?= __('airportConfig.page.airportFaa') ?></label>
                            <input type="text" class="form-control" name="airport_faa" id="airport_faa" maxlength="4" placeholder="DTW" required>
                        </div>
                        <div class="col-md-4">
                            <label><?= __('airportConfig.page.airportIcao') ?></label>
                            <input type="text" class="form-control" name="airport_icao" id="airport_icao" maxlength="4" placeholder="KDTW">
                            <small class="text-muted">Auto-filled, edit if needed</small>
                        </div>
                        <div class="col-md-4">
                            <label><?= __('airportConfig.page.configCode') ?></label>
                            <input type="text" class="form-control" name="config_code" id="config_code" maxlength="16" placeholder="SF">
                        </div>
                    </div>

                    <div class="row mt-2">
                        <div class="col-md-12">
                            <label><?= __('airportConfig.page.configName') ?></label>
                            <input type="text" class="form-control" name="config_name" id="config_name" maxlength="32" placeholder="South Flow" required>
                        </div>
                    </div>

                    <hr>

                    <div class="row">
                        <div class="col-md-6">
                            <label><?= __('airportConfig.page.arrivalRunways') ?></label>
                            <input type="text" class="form-control" name="arr_runways" id="arr_runways" maxlength="32" placeholder="21L/22R" required>
                            <small class="text-muted">Separate with / in priority order</small>
                        </div>
                        <div class="col-md-6">
                            <label><?= __('airportConfig.page.departureRunways') ?></label>
                            <input type="text" class="form-control" name="dep_runways" id="dep_runways" maxlength="32" placeholder="22L/21R" required>
                            <small class="text-muted">Separate with / in priority order</small>
                        </div>
                    </div>

                    <div class="row mt-2">
                        <div class="col-md-12">
                            <label>Config Modifiers</label>
                            <input type="hidden" name="config_modifiers[]" value="">
                            <select class="form-control" name="config_modifiers[]" id="config_modifiers" multiple size="8">
                                <optgroup label="Parallel Operations">
                                    <option value="SIMOS">SIMOS</option>
                                    <option value="STAGGERED">Staggered</option>
                                    <option value="SIDE_BY_SIDE">Side-by-Side</option>
                                    <option value="IN_TRAIL">In-Trail</option>
                                </optgroup>
                                <optgroup label="Approach Type">
                                    <option value="ILS">ILS</option>
                                    <option value="VOR">VOR</option>
                                    <option value="RNAV">RNAV</option>
                                    <option value="LDA">LDA</option>
                                    <option value="LOC">LOC</option>
                                    <option value="FMS_VISUAL">FMS Visual</option>
                                </optgroup>
                                <optgroup label="Special Operations">
                                    <option value="LAHSO">LAHSO</option>
                                    <option value="SINGLE_RWY">Single Runway</option>
                                    <option value="CIRCLING">Circling</option>
                                    <option value="VAP">Visual Approach</option>
                                </optgroup>
                                <optgroup label="Visibility Category">
                                    <option value="CAT_II">CAT II</option>
                                    <option value="CAT_III">CAT III</option>
                                </optgroup>
                                <optgroup label="Weather/Time">
                                    <option value="WINTER">Winter</option>
                                    <option value="NOISE">Noise Abatement</option>
                                    <option value="DAY">Day Only</option>
                                    <option value="NIGHT">Night Only</option>
                                </optgroup>
                            </select>
                            <small class="text-muted">Config-level only. Hold Ctrl/Cmd to select multiple.</small>
                        </div>
                    </div>

                    <hr>

                    <!-- VATSIM Rates -->
                    <div class="rate-section">
                        <h6><i class="fas fa-plane"></i> <?= __('airportConfig.page.vatsimRates') ?></h6>
                        <table class="table table-sm rate-grid mb-0">
                            <thead>
                                <tr>
                                    <th></th>
                                    <th class="text-center">VMC</th>
                                    <th class="text-center">LVMC</th>
                                    <th class="text-center">IMC</th>
                                    <th class="text-center">LIMC</th>
                                    <th class="text-center">VLIMC</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><strong>ARR</strong></td>
                                    <td><input type="number" class="form-control form-control-sm" name="vatsim_vmc_aar" id="vatsim_vmc_aar"></td>
                                    <td><input type="number" class="form-control form-control-sm" name="vatsim_lvmc_aar" id="vatsim_lvmc_aar"></td>
                                    <td><input type="number" class="form-control form-control-sm" name="vatsim_imc_aar" id="vatsim_imc_aar"></td>
                                    <td><input type="number" class="form-control form-control-sm" name="vatsim_limc_aar" id="vatsim_limc_aar"></td>
                                    <td><input type="number" class="form-control form-control-sm" name="vatsim_vlimc_aar" id="vatsim_vlimc_aar"></td>
                                </tr>
                                <tr>
                                    <td><strong>DEP</strong></td>
                                    <td><input type="number" class="form-control form-control-sm" name="vatsim_vmc_adr" id="vatsim_vmc_adr"></td>
                                    <td><input type="number" class="form-control form-control-sm" name="vatsim_lvmc_adr" id="vatsim_lvmc_adr"></td>
                                    <td><input type="number" class="form-control form-control-sm" name="vatsim_imc_adr" id="vatsim_imc_adr"></td>
                                    <td><input type="number" class="form-control form-control-sm" name="vatsim_limc_adr" id="vatsim_limc_adr"></td>
                                    <td><input type="number" class="form-control form-control-sm" name="vatsim_vlimc_adr" id="vatsim_vlimc_adr"></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Real-World Rates -->
                    <div class="rate-section">
                        <h6>
                            <a data-toggle="collapse" href="#updateRwRates" role="button" aria-expanded="false">
                                <i class="fas fa-globe"></i> Real-World Rates <small class="text-muted">(click to expand)</small>
                            </a>
                        </h6>
                        <div class="collapse" id="updateRwRates">
                            <table class="table table-sm rate-grid mb-0">
                                <thead>
                                    <tr>
                                        <th></th>
                                        <th class="text-center">VMC</th>
                                        <th class="text-center">LVMC</th>
                                        <th class="text-center">IMC</th>
                                        <th class="text-center">LIMC</th>
                                        <th class="text-center">VLIMC</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><strong>ARR</strong></td>
                                        <td><input type="number" class="form-control form-control-sm" name="rw_vmc_aar" id="rw_vmc_aar"></td>
                                        <td><input type="number" class="form-control form-control-sm" name="rw_lvmc_aar" id="rw_lvmc_aar"></td>
                                        <td><input type="number" class="form-control form-control-sm" name="rw_imc_aar" id="rw_imc_aar"></td>
                                        <td><input type="number" class="form-control form-control-sm" name="rw_limc_aar" id="rw_limc_aar"></td>
                                        <td><input type="number" class="form-control form-control-sm" name="rw_vlimc_aar" id="rw_vlimc_aar"></td>
                                    </tr>
                                    <tr>
                                        <td><strong>DEP</strong></td>
                                        <td><input type="number" class="form-control form-control-sm" name="rw_vmc_adr" id="rw_vmc_adr"></td>
                                        <td><input type="number" class="form-control form-control-sm" name="rw_lvmc_adr" id="rw_lvmc_adr"></td>
                                        <td><input type="number" class="form-control form-control-sm" name="rw_imc_adr" id="rw_imc_adr"></td>
                                        <td><input type="number" class="form-control form-control-sm" name="rw_limc_adr" id="rw_limc_adr"></td>
                                        <td><input type="number" class="form-control form-control-sm" name="rw_vlimc_adr" id="rw_vlimc_adr"></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-warning" value="<?= __('common.update') ?>">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal"><?= __('common.close') ?></button>
                </div>
        </div>

        </form>

    </div>
</div>

<!-- Rate History Modal -->
<div class="modal fade" id="historyModal" tabindex="-1" role="dialog" aria-labelledby="historyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="historyModalLabel"><?= __('airportConfig.page.rateHistory') ?></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div id="historyLoading" class="text-center py-4">
                    <i class="fas fa-spinner fa-spin fa-2x"></i>
                    <p class="mt-2"><?= __('airportConfig.page.loadingHistory') ?></p>
                </div>
                <div id="historyContent" style="display: none;">
                    <div class="alert alert-info" id="historyInfo"></div>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped history-table">
                            <thead>
                                <tr>
                                    <th><?= __('airportConfig.page.dateTime') ?></th>
                                    <th><?= __('airportConfig.page.source') ?></th>
                                    <th><?= __('airportConfig.page.weather') ?></th>
                                    <th><?= __('airportConfig.page.type') ?></th>
                                    <th><?= __('airportConfig.page.change') ?></th>
                                    <th><?= __('airportConfig.page.user') ?></th>
                                </tr>
                            </thead>
                            <tbody id="historyTableBody"></tbody>
                        </table>
                    </div>
                    <div id="historyEmpty" class="text-center text-muted py-4" style="display: none;">
                        <i class="fas fa-history fa-2x"></i>
                        <p class="mt-2"><?= __('airportConfig.page.noHistory') ?></p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>


    <!-- Scripts -->
    <script async type="text/javascript">
        var configData = []; // Store loaded config data for filtering/export
        var hasCheckboxCol = <?php echo $perm ? 'true' : 'false'; ?>; // Whether checkbox column exists
        var colOffset = hasCheckboxCol ? 1 : 0; // Column offset for data columns

        function tooltips() {
            $('[data-toggle="tooltip"]').tooltip('dispose');
            $(function () {
                $('[data-toggle="tooltip"]').tooltip()
            });
        }

        function updateStats() {
            var rows = $('#configs_table tr:visible');
            var total = rows.length;
            var airports = new Set();
            var withVatsim = 0;
            var withRw = 0;
            var vatsimHigher = 0;
            var vatsimLower = 0;

            rows.each(function() {
                var cells = $(this).find('td');
                if (cells.length > 0) {
                    var faaIdx = colOffset;
                    var faa = cells.eq(faaIdx).text().trim();
                    airports.add(faa);

                    // Check VATSIM rates (offset by checkbox + modifiers columns)
                    var hasVatsim = false;
                    for (var i = 6 + colOffset; i <= 12 + colOffset; i++) {
                        if (cells.eq(i).text().trim() !== '-' && cells.eq(i).text().trim() !== '') {
                            hasVatsim = true;
                            break;
                        }
                    }
                    if (hasVatsim) withVatsim++;

                    // Check RW rates
                    var hasRw = false;
                    for (var i = 13 + colOffset; i <= 18 + colOffset; i++) {
                        if (cells.eq(i).text().trim() !== '-' && cells.eq(i).text().trim() !== '') {
                            hasRw = true;
                            break;
                        }
                    }
                    if (hasRw) withRw++;

                    // Compare VMC AAR
                    var vatsimVmc = parseInt(cells.eq(6 + colOffset).text()) || 0;
                    var rwVmc = parseInt(cells.eq(13 + colOffset).text()) || 0;
                    if (vatsimVmc > 0 && rwVmc > 0) {
                        if (vatsimVmc > rwVmc) vatsimHigher++;
                        else if (vatsimVmc < rwVmc) vatsimLower++;
                    }
                }
            });

            $('#stat-total').text(total);
            $('#stat-airports').text(airports.size);
            $('#stat-with-vatsim').text(withVatsim);
            $('#stat-with-rw').text(withRw);
            $('#stat-vatsim-higher').text(vatsimHigher);
            $('#stat-vatsim-lower').text(vatsimLower);
        }

        function applyFilters() {
            var filter = $('#filterRates').val();
            var activeFilter = $('#filterActive').val();

            $('#configs_table tr').each(function() {
                var row = $(this);
                var cells = row.find('td');
                if (cells.length === 0) return;

                var show = true;
                var isActive = row.data('active') !== false;

                // Active filter
                if (activeFilter === 'active' && !isActive) show = false;
                else if (activeFilter === 'inactive' && isActive) show = false;

                // Rate filter
                if (show && filter) {
                    var hasRw = false;
                    for (var i = 13 + colOffset; i <= 18 + colOffset; i++) {
                        if (cells.eq(i).text().trim() !== '-' && cells.eq(i).text().trim() !== '') {
                            hasRw = true;
                            break;
                        }
                    }
                    var vatsimVmc = parseInt(cells.eq(6 + colOffset).text()) || 0;
                    var rwVmc = parseInt(cells.eq(13 + colOffset).text()) || 0;

                    switch (filter) {
                        case 'has-rw': show = hasRw; break;
                        case 'no-rw': show = !hasRw; break;
                        case 'vatsim-higher': show = (vatsimVmc > 0 && rwVmc > 0 && vatsimVmc > rwVmc); break;
                        case 'vatsim-lower': show = (vatsimVmc > 0 && rwVmc > 0 && vatsimVmc < rwVmc); break;
                    }
                }

                row.toggle(show);
            });
            updateStats();
        }

        // Sorting functionality
        function sortTable(colIndex, sortType) {
            var rows = $('#configs_table tr').get();
            var isAsc = !$('.sortable[data-sort="'+colIndex+'"]').hasClass('sort-asc');

            // Clear other sort indicators
            $('.sortable').removeClass('sort-asc sort-desc');
            $('.sortable[data-sort="'+colIndex+'"]').addClass(isAsc ? 'sort-asc' : 'sort-desc');

            rows.sort(function(a, b) {
                var aVal = $(a).find('td').eq(colIndex + colOffset).text().trim();
                var bVal = $(b).find('td').eq(colIndex + colOffset).text().trim();

                if (sortType === 'num') {
                    aVal = parseInt(aVal) || 0;
                    bVal = parseInt(bVal) || 0;
                    return isAsc ? aVal - bVal : bVal - aVal;
                } else {
                    return isAsc ? aVal.localeCompare(bVal) : bVal.localeCompare(aVal);
                }
            });

            $.each(rows, function(i, row) {
                $('#configs_table').append(row);
            });
        }

        // Bulk selection
        function updateBulkActions() {
            var checked = $('.row-checkbox:checked').length;
            $('#bulkCount').text(checked);
            if (checked > 0) {
                $('#bulkActions').addClass('active');
            } else {
                $('#bulkActions').removeClass('active');
            }
        }

        function getSelectedIds() {
            var ids = [];
            $('.row-checkbox:checked').each(function() {
                ids.push($(this).data('id'));
            });
            return ids;
        }

        // Bulk actions
        function bulkAction(action) {
            var ids = getSelectedIds();
            if (ids.length === 0) return;

            var confirmMsg = action === 'delete'
                ? 'Are you sure you want to delete ' + ids.length + ' config(s)?'
                : 'Are you sure you want to ' + action + ' ' + ids.length + ' config(s)?';

            Swal.fire({
                title: 'Confirm ' + action,
                text: confirmMsg,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, proceed'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.ajax({
                        type: 'POST',
                        url: 'api/mgt/config_data/bulk',
                        data: { action: action, ids: ids },
                        success: function() {
                            Swal.fire({ toast: true, position: 'bottom-right', icon: 'success',
                                title: 'Success', text: ids.length + ' config(s) ' + action + 'd.',
                                timer: 3000, showConfirmButton: false });
                            loadData($('#search').val());
                        },
                        error: function() {
                            Swal.fire({ icon: 'error', title: 'Error', text: 'Bulk action failed.' });
                        }
                    });
                }
            });
        }

        function exportToCSV() {
            var csv = [];
            var headers = ['FAA', 'ICAO', 'Config', 'Modifiers', 'ARR Rwys', 'DEP Rwys',
                'VATSIM VMC AAR', 'VATSIM LVMC AAR', 'VATSIM IMC AAR', 'VATSIM LIMC AAR', 'VATSIM VLIMC AAR',
                'VATSIM VMC ADR', 'VATSIM IMC ADR',
                'RW VMC AAR', 'RW LVMC AAR', 'RW IMC AAR', 'RW LIMC AAR',
                'RW VMC ADR', 'RW IMC ADR'];
            csv.push(headers.join(','));

            $('#configs_table tr:visible').each(function() {
                var row = [];
                var startIdx = colOffset; // Skip checkbox column
                $(this).find('td').each(function(i) {
                    if (i >= startIdx && i < 19 + colOffset) {
                        var text = $(this).text().trim().replace(/"/g, '""');
                        row.push('"' + text + '"');
                    }
                });
                if (row.length > 0) csv.push(row.join(','));
            });

            var blob = new Blob([csv.join('\n')], { type: 'text/csv' });
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = 'airport_configs_' + new Date().toISOString().slice(0,10) + '.csv';
            a.click();
            URL.revokeObjectURL(url);
        }

        function loadData(search) {
            var activeFilter = $('#filterActive').val() || 'active';
            var modifierFilter = $('#filterModifier').val() || '';
            var url = `api/data/configs?search=${search}&active=${activeFilter}`;
            if (modifierFilter) {
                url += `&modifier=${modifierFilter}`;
            }
            $.get(url).done(function(data) {
                $('#configs_table').html(data);
                tooltips();
                applyFilters();
                updateBulkActions();
            });
        }

        // FUNC: showHistory [configId, airportFaa, configName]
        function showHistory(configId, airportFaa, configName) {
            $('#historyModalLabel').text('Rate Change History - ' + airportFaa + ' (' + configName + ')');
            $('#historyLoading').show();
            $('#historyContent').hide();
            $('#historyModal').modal('show');

            $.get('api/data/rate_history?config_id=' + configId + '&days=30').done(function(response) {
                $('#historyLoading').hide();
                $('#historyContent').show();

                if (response.history && response.history.length > 0) {
                    $('#historyInfo').html('Showing <strong>' + response.history.length + '</strong> change(s) in the last 30 days');
                    $('#historyEmpty').hide();

                    var html = '';
                    response.history.forEach(function(item) {
                        var changeClass = '';
                        var changeText = '';
                        if (item.direction === 'NEW') {
                            changeClass = 'change-new';
                            changeText = '<i class="fas fa-plus"></i> New: ' + item.new_value;
                        } else if (item.direction === 'REMOVED') {
                            changeClass = 'change-removed';
                            changeText = '<i class="fas fa-minus"></i> Removed: ' + item.old_value;
                        } else if (item.direction === 'INCREASED') {
                            changeClass = 'change-increased';
                            changeText = '<i class="fas fa-arrow-up"></i> ' + item.old_value + ' → ' + item.new_value;
                        } else if (item.direction === 'DECREASED') {
                            changeClass = 'change-decreased';
                            changeText = '<i class="fas fa-arrow-down"></i> ' + item.old_value + ' → ' + item.new_value;
                        }

                        html += '<tr>';
                        html += '<td>' + item.changed_utc + '</td>';
                        html += '<td><span class="badge badge-' + (item.source === 'VATSIM' ? 'primary' : 'success') + '">' + item.source + '</span></td>';
                        html += '<td>' + item.weather + '</td>';
                        html += '<td>' + item.rate_type + '</td>';
                        html += '<td class="' + changeClass + '">' + changeText + '</td>';
                        html += '<td>' + (item.changed_by_cid || '-') + '</td>';
                        html += '</tr>';
                    });
                    $('#historyTableBody').html(html);
                } else {
                    $('#historyInfo').hide();
                    $('#historyEmpty').show();
                    $('#historyTableBody').html('');
                }
            }).fail(function() {
                $('#historyLoading').hide();
                $('#historyContent').show();
                $('#historyInfo').html('<span class="text-danger">Failed to load history</span>');
                $('#historyEmpty').hide();
            });
        }

        // FUNC: deleteConfig [id:]
        function deleteConfig(id) {
            Swal.fire({
                title: 'Delete Config?',
                text: 'This action cannot be undone.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                confirmButtonText: 'Yes, delete'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.ajax({
                        type: 'POST',
                        url: 'api/mgt/config_data/delete',
                        data: {config_id: id},
                        success: function() {
                            Swal.fire({ toast: true, position: 'bottom-right', icon: 'success',
                                title: 'Deleted', timer: 2000, showConfirmButton: false });
                            loadData($('#search').val());
                        },
                        error: function() {
                            Swal.fire({ icon: 'error', title: 'Error', text: 'Delete failed.' });
                        }
                    });
                }
            });
        }

        // Auto-fill ICAO from FAA code using API lookup
        function updateIcao(faaInput, icaoInput) {
            var faa = $(faaInput).val().toUpperCase();
            $(faaInput).val(faa); // Force uppercase in field
            
            if (faa.length >= 3) {
                // Call API to look up ICAO from apts table
                $.get('api/util/icao_lookup.php', { faa: faa }, function(response) {
                    if (response.success && response.icao) {
                        $(icaoInput).val(response.icao);
                        // Show source indicator
                        if (response.source === 'apts') {
                            $(icaoInput).removeClass('is-invalid').addClass('is-valid');
                        } else {
                            $(icaoInput).removeClass('is-valid is-invalid');
                        }
                    }
                }).fail(function() {
                    // Fallback to simple prefix logic if API fails
                    var icao = faa;
                    if (faa.length === 4) {
                        icao = faa;
                    } else if (faa.charAt(0) === 'Y') {
                        icao = 'C' + faa;
                    } else if (faa.charAt(0) === 'P') {
                        icao = faa;
                    } else {
                        icao = 'K' + faa;
                    }
                    $(icaoInput).val(icao).removeClass('is-valid is-invalid');
                });
            } else {
                $(icaoInput).val('').removeClass('is-valid is-invalid');
            }
        }

        // Infer modifiers from free-text config/runway fields (legacy naming patterns)
        function detectModifierCodes(configName, arrRunways, depRunways) {
            var source = [configName || '', arrRunways || '', depRunways || ''].join(' ').toUpperCase();
            var found = {};
            var rules = [
                { code: 'SIMOS', regex: /\bSIMOS\b/ },
                { code: 'STAGGERED', regex: /\bSTAGGER(?:ED)?\b/ },
                { code: 'SIDE_BY_SIDE', regex: /\bSIDE[\s_-]*BY[\s_-]*SIDE\b|\bSIDEBY\b/ },
                { code: 'IN_TRAIL', regex: /\bIN[\s_-]*TRAIL\b|\bINTRAIL\b/ },
                { code: 'ILS', regex: /(^|[\s_\/-])ILS($|[\s_\/-])/ },
                { code: 'VOR', regex: /(^|[\s_\/-])VOR($|[\s_\/-])/ },
                { code: 'RNAV', regex: /(^|[\s_\/-])RNAV($|[\s_\/-])/ },
                { code: 'LDA', regex: /(^|[\s_\/-])LDA($|[\s_\/-])/ },
                { code: 'LOC', regex: /(^|[\s_\/-])LOC($|[\s_\/-])/ },
                { code: 'FMS_VISUAL', regex: /\bFMS[\s_-]*VISUAL\b/ },
                { code: 'LAHSO', regex: /\bLAHSO\b/ },
                { code: 'SINGLE_RWY', regex: /\bSINGLE[\s_-]*(RWY|RUNWAY)\b|\bSRO\b/ },
                { code: 'CIRCLING', regex: /\bCIRCL(?:ING)?\b/ },
                { code: 'VAP', regex: /(^|[\s_\/-])VAP($|[\s_\/-])|\bVISUAL\s+APPROACH\b/ },
                { code: 'CAT_II', regex: /\bCAT[\s_-]*II\b/ },
                { code: 'CAT_III', regex: /\bCAT[\s_-]*III\b/ },
                { code: 'WINTER', regex: /\bWINTER\b|\bSNOW\b/ },
                { code: 'NOISE', regex: /\bNOISE\b/ },
                { code: 'DAY', regex: /\bDAY\b/ },
                { code: 'NIGHT', regex: /\bNIGHT\b|\bNGT\b/ }
            ];

            rules.forEach(function(rule) {
                if (rule.regex.test(source)) {
                    found[rule.code] = true;
                }
            });

            return Object.keys(found);
        }

        function mergeAndSetModifiers(selectSelector, selectedCodes, configName, arrRunways, depRunways) {
            var merged = {};
            (selectedCodes || []).forEach(function(code) {
                if (code) merged[code] = true;
            });
            detectModifierCodes(configName, arrRunways, depRunways).forEach(function(code) {
                merged[code] = true;
            });
            $(selectSelector).val(Object.keys(merged));
        }

        $(document).ready(function() {
            loadData($('#search').val());

            // Auto-fill ICAO for Add modal
            $('#add_airport_faa').on('input', function() {
                updateIcao('#add_airport_faa', '#add_airport_icao');
            });

            // Auto-fill ICAO for Update modal
            $('#airport_faa').on('input', function() {
                updateIcao('#airport_faa', '#airport_icao');
            });

            // Search button
            $('#searchBtn').click(function() {
                loadData($('#search').val());
            });

            // Enter key in search
            $('#search').keypress(function(e) {
                if (e.which === 13) {
                    loadData($('#search').val());
                }
            });

            // Filter dropdowns
            $('#filterRates').change(function() {
                applyFilters();
            });

            $('#filterActive').change(function() {
                loadData($('#search').val());
            });

            $('#filterModifier').change(function() {
                loadData($('#search').val());
            });

            // Sortable columns
            $(document).on('click', '.sortable', function() {
                var colIndex = parseInt($(this).data('sort'));
                var sortType = $(this).data('type') || 'text';
                sortTable(colIndex, sortType);
            });

            // Bulk selection - Select All
            $(document).on('change', '#selectAll', function() {
                var isChecked = $(this).prop('checked');
                $('.row-checkbox:visible').prop('checked', isChecked);
                updateBulkActions();
            });

            // Bulk selection - Individual rows
            $(document).on('change', '.row-checkbox', function() {
                updateBulkActions();
                // Update select all checkbox state
                var total = $('.row-checkbox:visible').length;
                var checked = $('.row-checkbox:visible:checked').length;
                $('#selectAll').prop('checked', total === checked && total > 0);
            });

            // Bulk action buttons
            $('#bulkActivate').click(function() { bulkAction('activate'); });
            $('#bulkDeactivate').click(function() { bulkAction('deactivate'); });
            $('#bulkDelete').click(function() { bulkAction('delete'); });
            $('#bulkClear').click(function() {
                $('.row-checkbox, #selectAll').prop('checked', false);
                updateBulkActions();
            });

            // Export button
            $('#exportBtn').click(function() {
                exportToCSV();
            });

            // AJAX: #addconfig POST
            $("#addconfig").submit(function(e) {
                e.preventDefault();

                var url = 'api/mgt/config_data/post';

                $.ajax({
                    type:   'POST',
                    url:    url,
                    data:   $(this).serialize(),
                    success:function(data) {
                        Swal.fire({
                            toast:      true,
                            position:   'bottom-right',
                            icon:       'success',
                            title:      'Successfully Added',
                            text:       'You have successfully added a field config.',
                            timer:      3000,
                            showConfirmButton: false
                        });

                        loadData($('#search').val());
                        $('#addconfigModal').modal('hide');
                        $('#addconfig')[0].reset();
                        $('.modal-backdrop').remove();
                    },
                    error:function(data) {
                        Swal.fire({
                            icon:   'error',
                            title:  'Not Added',
                            text:   'There was an error in adding this field config.'
                        });
                    }
                });
            });

            // Update Config Modal - populate fields
            $('#updateconfigModal').on('show.bs.modal', function(event) {
                var button = $(event.relatedTarget);
                var modal = $(this);

                modal.find('#config_id').val(button.data('config_id'));
                modal.find('#airport_faa').val(button.data('airport_faa'));
                modal.find('#airport_icao').val(button.data('airport_icao'));
                modal.find('#config_name').val(button.data('config_name'));
                modal.find('#config_code').val(button.data('config_code'));
                modal.find('#arr_runways').val(button.data('arr_runways'));
                modal.find('#dep_runways').val(button.data('dep_runways'));
                var configModifiersRaw = (button.data('config_modifiers') || '').toString();
                var configModifiers = configModifiersRaw ? configModifiersRaw.split(',') : [];
                mergeAndSetModifiers(
                    '#config_modifiers',
                    configModifiers,
                    button.data('config_name'),
                    button.data('arr_runways'),
                    button.data('dep_runways')
                );

                // VATSIM rates
                modal.find('#vatsim_vmc_aar').val(button.data('vatsim_vmc_aar'));
                modal.find('#vatsim_lvmc_aar').val(button.data('vatsim_lvmc_aar'));
                modal.find('#vatsim_imc_aar').val(button.data('vatsim_imc_aar'));
                modal.find('#vatsim_limc_aar').val(button.data('vatsim_limc_aar'));
                modal.find('#vatsim_vlimc_aar').val(button.data('vatsim_vlimc_aar'));
                modal.find('#vatsim_vmc_adr').val(button.data('vatsim_vmc_adr'));
                modal.find('#vatsim_lvmc_adr').val(button.data('vatsim_lvmc_adr'));
                modal.find('#vatsim_imc_adr').val(button.data('vatsim_imc_adr'));
                modal.find('#vatsim_limc_adr').val(button.data('vatsim_limc_adr'));
                modal.find('#vatsim_vlimc_adr').val(button.data('vatsim_vlimc_adr'));

                // Real-world rates
                modal.find('#rw_vmc_aar').val(button.data('rw_vmc_aar'));
                modal.find('#rw_lvmc_aar').val(button.data('rw_lvmc_aar'));
                modal.find('#rw_imc_aar').val(button.data('rw_imc_aar'));
                modal.find('#rw_limc_aar').val(button.data('rw_limc_aar'));
                modal.find('#rw_vlimc_aar').val(button.data('rw_vlimc_aar'));
                modal.find('#rw_vmc_adr').val(button.data('rw_vmc_adr'));
                modal.find('#rw_lvmc_adr').val(button.data('rw_lvmc_adr'));
                modal.find('#rw_imc_adr').val(button.data('rw_imc_adr'));
                modal.find('#rw_limc_adr').val(button.data('rw_limc_adr'));
                modal.find('#rw_vlimc_adr').val(button.data('rw_vlimc_adr'));
            });

            // AJAX: #updateconfig POST
            $("#updateconfig").submit(function(e) {
                e.preventDefault();

                var url = 'api/mgt/config_data/update';

                $.ajax({
                    type:   'POST',
                    url:    url,
                    data:   $(this).serialize(),
                    success:function(data) {
                        Swal.fire({
                            toast:      true,
                            position:   'bottom-right',
                            icon:       'success',
                            title:      'Successfully Updated',
                            text:       'You have successfully updated the selected field config.',
                            timer:      3000,
                            showConfirmButton: false
                        });

                        loadData($('#search').val());
                        $('#updateconfigModal').modal('hide');
                        $('.modal-backdrop').remove();
                    },
                    error:function(data) {
                        Swal.fire({
                            icon:   'error',
                            title:  'Not Updated',
                            text:   'There was an error in updating the selected field config.'
                        });
                    }
                });
            });

        });
    </script>

</html>
