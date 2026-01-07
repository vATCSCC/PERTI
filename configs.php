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
            $cid = strip_tags($_SESSION['VATSIM_CID']);

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
        include("load/header.php");
    ?>

    <style>
        .rate-grid {
            font-size: 0.85rem;
        }
        .rate-grid input {
            width: 60px;
            text-align: center;
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
                <h1>Field Configuration Data</h1>
                <h4 class="text-white hvr-bob pl-1">
                    <a href="#configs" style="text-decoration: none; color: white;"><i class="fas fa-chevron-down text-danger"></i> Search for Configs</a>
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
                    <div class="stat-label">Airports</div>
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
            <div class="col-md-3">
                <select class="form-control form-control-sm" id="filterRates">
                    <option value="">All Configs</option>
                    <option value="has-rw">Has Real-World Rates</option>
                    <option value="no-rw">Missing Real-World Rates</option>
                    <option value="vatsim-higher">VATSIM Higher than RW</option>
                    <option value="vatsim-lower">VATSIM Lower than RW</option>
                </select>
            </div>
            <div class="col-md-6 text-right">
                <?php if ($perm == true) { ?>
                    <button class="btn btn-success btn-sm" data-target="#addconfigModal" data-toggle="modal"><i class="fas fa-plus"></i> Add Config</button>
                <?php } ?>
                <button class="btn btn-secondary btn-sm" id="exportBtn" data-toggle="tooltip" title="Export to CSV"><i class="fas fa-download"></i> Export</button>
            </div>
        </div>

        <div id="configs-container">
            <table class="table table-sm table-striped table-bordered" id="configs" style="width: 100%;">
                <thead class="table-dark text-light">
                    <!-- Row 1: Main categories -->
                    <tr>
                        <th rowspan="3" class="text-center align-middle">FAA</th>
                        <th rowspan="3" class="text-center align-middle">ICAO</th>
                        <th rowspan="3" class="text-center align-middle">Config</th>
                        <th rowspan="3" class="text-center align-middle">ARR<br>Rwys</th>
                        <th rowspan="3" class="text-center align-middle">DEP<br>Rwys</th>
                        <th colspan="7" class="text-center vatsim-header section-divider">VATSIM Rates</th>
                        <th colspan="6" class="text-center rw-header section-divider">Real-World Rates</th>
                        <?php if ($perm == true) { ?>
                            <th rowspan="3" class="align-middle"></th>
                        <?php } ?>
                    </tr>
                    <!-- Row 2: ARR/DEP categories -->
                    <tr>
                        <th colspan="5" class="text-center vatsim-arr-header section-divider">AAR</th>
                        <th colspan="2" class="text-center vatsim-dep-header">ADR</th>
                        <th colspan="4" class="text-center rw-arr-header section-divider">AAR</th>
                        <th colspan="2" class="text-center rw-dep-header">ADR</th>
                    </tr>
                    <!-- Row 3: Weather categories -->
                    <tr>
                        <!-- VATSIM AAR -->
                        <th class="text-center weather-header vatsim-arr-header section-divider" data-toggle="tooltip" title="Visual Meteorological Conditions">VMC</th>
                        <th class="text-center weather-header vatsim-arr-header" data-toggle="tooltip" title="Low Visual Meteorological Conditions">LVMC</th>
                        <th class="text-center weather-header vatsim-arr-header" data-toggle="tooltip" title="Instrument Meteorological Conditions">IMC</th>
                        <th class="text-center weather-header vatsim-arr-header" data-toggle="tooltip" title="Low Instrument Meteorological Conditions">LIMC</th>
                        <th class="text-center weather-header vatsim-arr-header" data-toggle="tooltip" title="Very Low Instrument Meteorological Conditions">VLIMC</th>
                        <!-- VATSIM ADR -->
                        <th class="text-center weather-header vatsim-dep-header" data-toggle="tooltip" title="Visual Meteorological Conditions">VMC</th>
                        <th class="text-center weather-header vatsim-dep-header" data-toggle="tooltip" title="Instrument Meteorological Conditions">IMC</th>
                        <!-- RW AAR -->
                        <th class="text-center weather-header rw-arr-header section-divider" data-toggle="tooltip" title="Visual Meteorological Conditions">VMC</th>
                        <th class="text-center weather-header rw-arr-header" data-toggle="tooltip" title="Low Visual Meteorological Conditions">LVMC</th>
                        <th class="text-center weather-header rw-arr-header" data-toggle="tooltip" title="Instrument Meteorological Conditions">IMC</th>
                        <th class="text-center weather-header rw-arr-header" data-toggle="tooltip" title="Low Instrument Meteorological Conditions">LIMC</th>
                        <!-- RW ADR -->
                        <th class="text-center weather-header rw-dep-header" data-toggle="tooltip" title="Visual Meteorological Conditions">VMC</th>
                        <th class="text-center weather-header rw-dep-header" data-toggle="tooltip" title="Instrument Meteorological Conditions">IMC</th>
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
                <h5 class="modal-title" id="addconfigModalLabel">Add Configuration</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="addconfig">

                <div class="modal-body">

                    <div class="row">
                        <div class="col-md-4">
                            <label>Airport (FAA):</label>
                            <input type="text" class="form-control" name="airport_faa" id="add_airport_faa" maxlength="4" placeholder="DTW" required>
                        </div>
                        <div class="col-md-4">
                            <label>Airport (ICAO):</label>
                            <input type="text" class="form-control" name="airport_icao" id="add_airport_icao" maxlength="4" placeholder="KDTW" readonly>
                        </div>
                        <div class="col-md-4">
                            <label>Config Code:</label>
                            <input type="text" class="form-control" name="config_code" maxlength="16" placeholder="SF">
                        </div>
                    </div>

                    <div class="row mt-2">
                        <div class="col-md-12">
                            <label>Config Name:</label>
                            <input type="text" class="form-control" name="config_name" maxlength="32" placeholder="South Flow" required>
                        </div>
                    </div>

                    <hr>

                    <div class="row">
                        <div class="col-md-6">
                            <label>Arrival Runways:</label>
                            <input type="text" class="form-control" name="arr_runways" maxlength="32" placeholder="21L/22R" required>
                            <small class="text-muted">Separate with / in priority order</small>
                        </div>
                        <div class="col-md-6">
                            <label>Departure Runways:</label>
                            <input type="text" class="form-control" name="dep_runways" maxlength="32" placeholder="22L/21R" required>
                            <small class="text-muted">Separate with / in priority order</small>
                        </div>
                    </div>

                    <hr>

                    <!-- VATSIM Rates -->
                    <div class="rate-section">
                        <h6><i class="fas fa-plane"></i> VATSIM Rates</h6>
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
                    <input type="submit" class="btn btn-sm btn-success" value="Add Config">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal">Close</button>
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
                <h5 class="modal-title" id="updateconfigModalLabel">Update Configuration</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="updateconfig">

                <div class="modal-body">

                    <input type="hidden" name="config_id" id="config_id" required>

                    <div class="row">
                        <div class="col-md-4">
                            <label>Airport (FAA):</label>
                            <input type="text" class="form-control" name="airport_faa" id="airport_faa" maxlength="4" placeholder="DTW" required>
                        </div>
                        <div class="col-md-4">
                            <label>Airport (ICAO):</label>
                            <input type="text" class="form-control" name="airport_icao" id="airport_icao" maxlength="4" placeholder="KDTW" readonly>
                        </div>
                        <div class="col-md-4">
                            <label>Config Code:</label>
                            <input type="text" class="form-control" name="config_code" id="config_code" maxlength="16" placeholder="SF">
                        </div>
                    </div>

                    <div class="row mt-2">
                        <div class="col-md-12">
                            <label>Config Name:</label>
                            <input type="text" class="form-control" name="config_name" id="config_name" maxlength="32" placeholder="South Flow" required>
                        </div>
                    </div>

                    <hr>

                    <div class="row">
                        <div class="col-md-6">
                            <label>Arrival Runways:</label>
                            <input type="text" class="form-control" name="arr_runways" id="arr_runways" maxlength="32" placeholder="21L/22R" required>
                            <small class="text-muted">Separate with / in priority order</small>
                        </div>
                        <div class="col-md-6">
                            <label>Departure Runways:</label>
                            <input type="text" class="form-control" name="dep_runways" id="dep_runways" maxlength="32" placeholder="22L/21R" required>
                            <small class="text-muted">Separate with / in priority order</small>
                        </div>
                    </div>

                    <hr>

                    <!-- VATSIM Rates -->
                    <div class="rate-section">
                        <h6><i class="fas fa-plane"></i> VATSIM Rates</h6>
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
                    <input type="submit" class="btn btn-sm btn-warning" value="Update">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal">Close</button>
                </div>
        </div>

        </form>

    </div>
</div>


    <!-- Scripts -->
    <script async type="text/javascript">
        var configData = []; // Store loaded config data for filtering/export

        function tooltips() {
            $('[data-toggle="tooltip"]').tooltip('dispose');

            $(function () {
                $('[data-toggle="tooltip"]').tooltip()
            });
        }

        function updateStats() {
            var rows = $('#configs_table tr');
            var total = rows.length;
            var airports = new Set();
            var withVatsim = 0;
            var withRw = 0;
            var vatsimHigher = 0;
            var vatsimLower = 0;

            rows.each(function() {
                var cells = $(this).find('td');
                if (cells.length > 0) {
                    var faa = cells.eq(0).text().trim();
                    airports.add(faa);

                    // Check VATSIM rates (columns 5-11)
                    var hasVatsim = false;
                    for (var i = 5; i <= 11; i++) {
                        if (cells.eq(i).text().trim() !== '-' && cells.eq(i).text().trim() !== '') {
                            hasVatsim = true;
                            break;
                        }
                    }
                    if (hasVatsim) withVatsim++;

                    // Check RW rates (columns 12-17)
                    var hasRw = false;
                    for (var i = 12; i <= 17; i++) {
                        if (cells.eq(i).text().trim() !== '-' && cells.eq(i).text().trim() !== '') {
                            hasRw = true;
                            break;
                        }
                    }
                    if (hasRw) withRw++;

                    // Compare VMC AAR (VATSIM col 5 vs RW col 12)
                    var vatsimVmc = parseInt(cells.eq(5).text()) || 0;
                    var rwVmc = parseInt(cells.eq(12).text()) || 0;
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

        function applyFilter(filter) {
            var rows = $('#configs_table tr');
            rows.each(function() {
                var row = $(this);
                var cells = row.find('td');
                if (cells.length === 0) return;

                var show = true;

                // Check RW rates (columns 12-17)
                var hasRw = false;
                for (var i = 12; i <= 17; i++) {
                    if (cells.eq(i).text().trim() !== '-' && cells.eq(i).text().trim() !== '') {
                        hasRw = true;
                        break;
                    }
                }

                // Compare VMC AAR
                var vatsimVmc = parseInt(cells.eq(5).text()) || 0;
                var rwVmc = parseInt(cells.eq(12).text()) || 0;

                switch (filter) {
                    case 'has-rw':
                        show = hasRw;
                        break;
                    case 'no-rw':
                        show = !hasRw;
                        break;
                    case 'vatsim-higher':
                        show = (vatsimVmc > 0 && rwVmc > 0 && vatsimVmc > rwVmc);
                        break;
                    case 'vatsim-lower':
                        show = (vatsimVmc > 0 && rwVmc > 0 && vatsimVmc < rwVmc);
                        break;
                }

                row.toggle(show);
            });
        }

        function exportToCSV() {
            var csv = [];
            var headers = ['FAA', 'ICAO', 'Config', 'ARR Rwys', 'DEP Rwys',
                'VATSIM VMC AAR', 'VATSIM LVMC AAR', 'VATSIM IMC AAR', 'VATSIM LIMC AAR', 'VATSIM VLIMC AAR',
                'VATSIM VMC ADR', 'VATSIM IMC ADR',
                'RW VMC AAR', 'RW LVMC AAR', 'RW IMC AAR', 'RW LIMC AAR',
                'RW VMC ADR', 'RW IMC ADR'];
            csv.push(headers.join(','));

            $('#configs_table tr:visible').each(function() {
                var row = [];
                $(this).find('td').each(function(i) {
                    if (i < 18) { // Skip action column
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
            $.get(`api/data/configs?search=${search}`).done(function(data) {
                $('#configs_table').html(data);
                tooltips();
                updateStats();

                // Re-apply filter if one is selected
                var filter = $('#filterRates').val();
                if (filter) applyFilter(filter);
            });
        }

        // FUNC: deleteConfig [id:]
        function deleteConfig(id) {
            $.ajax({
                type:   'POST',
                url:    'api/mgt/config_data/delete',
                data:   {config_id: id},
                success:function(data) {
                    Swal.fire({
                        toast:      true,
                        position:   'bottom-right',
                        icon:       'success',
                        title:      'Successfully Deleted',
                        text:       'You have successfully deleted the selected field config.',
                        timer:      3000,
                        showConfirmButton: false
                    });

                    loadData($('#search').val());
                },
                error:function(data) {
                    Swal.fire({
                        icon:   'error',
                        title:  'Not Deleted',
                        text:   'There was an error in deleting the selected field config.'
                    });
                }
            });
        }

        // Auto-fill ICAO from FAA code
        function updateIcao(faaInput, icaoInput) {
            var faa = $(faaInput).val().toUpperCase();
            if (faa.length >= 3) {
                // US airports: prepend K (except for Alaska/Hawaii which start with P)
                if (faa.charAt(0) === 'P' || faa.length === 4) {
                    $(icaoInput).val(faa);
                } else {
                    $(icaoInput).val('K' + faa);
                }
            } else {
                $(icaoInput).val('');
            }
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

            // Filter dropdown
            $('#filterRates').change(function() {
                applyFilter($(this).val());
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
