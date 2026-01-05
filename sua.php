<?php

    include("sessions/handler.php");
    // Session Start
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
        ob_start();
    }

    include("load/config.php");
    include("load/connect.php");

    // Check Perms
    $perm = false;
    if (!defined('DEV')) {
        if (isset($_SESSION['VATSIM_CID'])) {
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

    if ($perm != true) {
        http_response_code(403);
        exit();
    }

    // ARTCC list for filters
    $artccs = ['ZAB', 'ZAU', 'ZBW', 'ZDC', 'ZDV', 'ZFW', 'ZHU', 'ZID', 'ZJX', 'ZKC', 'ZLA', 'ZLC', 'ZMA', 'ZME', 'ZMP', 'ZNY', 'ZOA', 'ZOB', 'ZSE', 'ZTL'];

    // TFR subtypes
    $tfr_subtypes = [
        'HAZARD' => 'Hazard/Disaster (91.137)',
        'VIP' => 'VIP Movement (91.141)',
        'SECURITY' => 'Security (99.7)',
        'HAWAII' => 'Hawaii Disaster (91.138)',
        'EMERGENCY' => 'Emergency (91.139)',
        'EVENT' => 'Event/Airshow (91.145)',
        'PRESSURE' => 'High Pressure (91.144)',
        'SPACE' => 'Space Operations',
        'MASS_GATHERING' => 'Mass Gathering',
        'OTHER' => 'Other'
    ];

?>

<!DOCTYPE html>
<html>

<head>
    <?php include("load/header.php"); ?>

    <style>
        .sua-browser {
            max-height: 500px;
            overflow-y: auto;
        }
        .sua-browser table {
            font-size: 0.85rem;
        }
        .sua-browser th {
            position: sticky;
            top: 0;
            background: #343a40;
            color: #fff;
            z-index: 10;
        }
        .filter-row {
            background: #e9ecef;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
            color: #212529;
        }
        .filter-row label {
            color: #212529;
        }
        .activation-btn {
            cursor: pointer;
        }
        .activation-btn:hover {
            opacity: 0.8;
        }
        /* Ensure modal form labels are readable */
        .modal-body label {
            color: #212529;
            font-weight: 500;
        }
        .modal-body .form-control {
            color: #495057;
        }
        /* Improve table readability */
        .table-striped tbody tr:nth-of-type(odd) {
            background-color: rgba(0, 0, 0, 0.03);
        }
        /* SUA Map container */
        #sua-map {
            height: 400px;
            width: 100%;
            border-radius: 5px;
            border: 1px solid #dee2e6;
        }
    </style>

    <!-- MapLibre GL CSS for map -->
    <link rel="stylesheet" href="https://unpkg.com/maplibre-gl@4.7.1/dist/maplibre-gl.css" crossorigin=""/>
    <!-- MapLibre Draw CSS -->
    <link rel="stylesheet" href="https://unpkg.com/@mapbox/mapbox-gl-draw@1.4.3/dist/mapbox-gl-draw.css" crossorigin=""/>
</head>

<body>

<?php include('load/nav.php'); ?>

<!-- Hero Section -->
<section class="d-flex align-items-center position-relative bg-position-center overflow-hidden pt-6 jarallax bg-dark text-light" style="min-height: 200px" data-jarallax data-speed="0.3">
    <div class="container-fluid pt-2 pb-4 py-lg-5">
        <img class="jarallax-img" src="assets/img/jumbotron/main.png" alt="" style="opacity: 50%; height: 100vh;">
        <center>
            <h2>SUA/TFR Management</h2>
            <p class="text-muted">Schedule Special Use Airspace activations and create Temporary Flight Restrictions</p>
        </center>
    </div>
</section>

<div class="container-fluid mt-4 mb-5">
    <center>

        <!-- Action Buttons -->
        <div class="mb-4">
            <button class="btn btn-success btn-sm" data-toggle="modal" data-target="#scheduleModal">
                <i class="fas fa-calendar-plus"></i> Schedule SUA Activation
            </button>
            <button class="btn btn-warning btn-sm" data-toggle="modal" data-target="#tfrModal">
                <i class="fas fa-exclamation-triangle"></i> Create TFR
            </button>
            <button class="btn btn-info btn-sm" onclick="startAltrvDrawing()">
                <i class="fas fa-draw-polygon"></i> Draw ALTRV
            </button>
        </div>

        <!-- Activations Table -->
        <div class="card mb-4" style="max-width: 1400px;">
            <div class="card-header bg-dark text-light">
                <strong>Scheduled Activations</strong>
                <div class="float-right">
                    <select id="statusFilter" class="form-control form-control-sm d-inline-block" style="width: auto;">
                        <option value="">Active & Scheduled</option>
                        <option value="ALL">All</option>
                        <option value="ACTIVE">Active Only</option>
                        <option value="SCHEDULED">Scheduled Only</option>
                        <option value="EXPIRED">Expired</option>
                        <option value="CANCELLED">Cancelled</option>
                    </select>
                    <button class="btn btn-sm btn-outline-light ml-2" onclick="loadActivations()">
                        <i class="fas fa-sync"></i>
                    </button>
                </div>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-striped table-bordered mb-0">
                    <thead class="table-dark text-light">
                        <tr>
                            <th style="width: 10%;">Type</th>
                            <th style="width: 25%;">Name</th>
                            <th style="width: 8%;">ARTCC</th>
                            <th style="width: 12%;">Start (UTC)</th>
                            <th style="width: 12%;">End (UTC)</th>
                            <th style="width: 12%;">Altitude</th>
                            <th style="width: 10%;">Status</th>
                            <th style="width: 11%;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="activations_table"></tbody>
                </table>
            </div>
        </div>

        <!-- SUA Browser -->
        <div class="card" style="max-width: 1400px;">
            <div class="card-header bg-dark text-light">
                <strong>SUA Browser</strong>
                <span class="badge badge-light ml-2" id="sua_count">0</span>
                <div class="float-right">
                    <div class="btn-group btn-group-sm" role="group">
                        <button type="button" class="btn btn-outline-light active" id="viewMapBtn" onclick="toggleView('map')">
                            <i class="fas fa-map"></i> Map
                        </button>
                        <button type="button" class="btn btn-outline-light" id="viewTableBtn" onclick="toggleView('table')">
                            <i class="fas fa-table"></i> Table
                        </button>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <!-- Filters -->
                <div class="filter-row">
                    <div class="row">
                        <div class="col-md-4">
                            <input type="text" class="form-control form-control-sm" id="suaSearch" placeholder="Search by name or designator...">
                        </div>
                        <div class="col-md-3">
                            <select class="form-control form-control-sm" id="suaTypeFilter">
                                <option value="">All Types</option>
                                <optgroup label="Airspace Areas">
                                    <option value="PROHIBITED">Prohibited</option>
                                    <option value="RESTRICTED">Restricted</option>
                                    <option value="WARNING">Warning</option>
                                    <option value="ALERT">Alert</option>
                                    <option value="MOA">MOA</option>
                                    <option value="NSA">NSA</option>
                                    <option value="TFR">TFR</option>
                                    <option value="ADIZ">ADIZ</option>
                                    <option value="FRZ">FRZ</option>
                                </optgroup>
                                <optgroup label="Military Areas">
                                    <option value="USN">USN (Navy)</option>
                                    <option value="USAF">USAF</option>
                                    <option value="USArmy">US Army</option>
                                    <option value="ANG">Air National Guard</option>
                                    <option value="OPAREA">Operating Area</option>
                                    <option value="DZ">Drop Zone</option>
                                    <option value="LASER">Laser</option>
                                    <option value="NUCLEAR">Nuclear</option>
                                </optgroup>
                                <optgroup label="Routes/Tracks">
                                    <option value="AR">Air Refueling (AR)</option>
                                    <option value="ALTRV">ALTRV</option>
                                    <option value="OSARA">OSARA</option>
                                    <option value="SS">Supersonic</option>
                                </optgroup>
                                <optgroup label="Special">
                                    <option value="AW">AWACS Orbit</option>
                                    <option value="WSRP">Weather Radar</option>
                                    <option value="NORAD">NORAD</option>
                                    <option value="NASA">NASA</option>
                                    <option value="NOAA">NOAA</option>
                                </optgroup>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select class="form-control form-control-sm" id="suaArtccFilter">
                                <option value="">All ARTCCs</option>
                                <?php foreach ($artccs as $artcc): ?>
                                    <option value="<?= $artcc ?>"><?= $artcc ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-sm btn-primary btn-block" onclick="loadSuaBrowser()">
                                <i class="fas fa-search"></i> Search
                            </button>
                        </div>
                    </div>
                </div>

                <!-- SUA Map -->
                <div id="sua-map-container" class="mb-3">
                    <div id="sua-map"></div>
                    <!-- Layer Controls -->
                    <div class="mt-2 p-2 bg-light border rounded">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <strong class="text-dark">Map Layers</strong>
                            <div class="btn-group btn-group-sm" role="group">
                                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="toggleAllLayers(true)">All On</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="toggleAllLayers(false)">All Off</button>
                            </div>
                        </div>
                        <!-- Regulatory Airspace -->
                        <div class="mb-2">
                            <small class="text-muted d-block mb-1"><strong>Regulatory</strong></small>
                            <div class="d-flex flex-wrap">
                                <div class="custom-control custom-checkbox custom-control-inline">
                                    <input type="checkbox" class="custom-control-input layer-toggle" id="layer-PROHIBITED" value="PROHIBITED" checked>
                                    <label class="custom-control-label" for="layer-PROHIBITED"><span class="badge" style="background-color: #FF00FF; color: #fff;">P</span></label>
                                </div>
                                <div class="custom-control custom-checkbox custom-control-inline">
                                    <input type="checkbox" class="custom-control-input layer-toggle" id="layer-RESTRICTED" value="RESTRICTED" checked>
                                    <label class="custom-control-label" for="layer-RESTRICTED"><span class="badge" style="background-color: #0004B0; color: #fff;">R</span></label>
                                </div>
                                <div class="custom-control custom-checkbox custom-control-inline">
                                    <input type="checkbox" class="custom-control-input layer-toggle" id="layer-WARNING" value="WARNING" checked>
                                    <label class="custom-control-label" for="layer-WARNING"><span class="badge" style="background-color: #3D6003; color: #fff;">W</span></label>
                                </div>
                                <div class="custom-control custom-checkbox custom-control-inline">
                                    <input type="checkbox" class="custom-control-input layer-toggle" id="layer-ALERT" value="ALERT" checked>
                                    <label class="custom-control-label" for="layer-ALERT"><span class="badge" style="background-color: #199696; color: #fff;">A</span></label>
                                </div>
                                <div class="custom-control custom-checkbox custom-control-inline">
                                    <input type="checkbox" class="custom-control-input layer-toggle" id="layer-NSA" value="NSA" checked>
                                    <label class="custom-control-label" for="layer-NSA"><span class="badge" style="background-color: #00FF00; color: #000;">NSA</span></label>
                                </div>
                            </div>
                        </div>
                        <!-- Military -->
                        <div class="mb-2">
                            <small class="text-muted d-block mb-1"><strong>Military</strong></small>
                            <div class="d-flex flex-wrap">
                                <div class="custom-control custom-checkbox custom-control-inline">
                                    <input type="checkbox" class="custom-control-input layer-toggle" id="layer-MOA" value="MOA" checked>
                                    <label class="custom-control-label" for="layer-MOA"><span class="badge" style="background-color: #087DD4; color: #fff;">MOA</span></label>
                                </div>
                                <div class="custom-control custom-checkbox custom-control-inline">
                                    <input type="checkbox" class="custom-control-input layer-toggle" id="layer-ATCAA" value="ATCAA" checked>
                                    <label class="custom-control-label" for="layer-ATCAA"><span class="badge" style="background-color: #4169E1; color: #fff;">ATCAA</span></label>
                                </div>
                                <div class="custom-control custom-checkbox custom-control-inline">
                                    <input type="checkbox" class="custom-control-input layer-toggle" id="layer-ALTRV" value="ALTRV" checked>
                                    <label class="custom-control-label" for="layer-ALTRV"><span class="badge" style="background-color: #E1E101; color: #000;">ALTRV</span></label>
                                </div>
                                <div class="custom-control custom-checkbox custom-control-inline">
                                    <input type="checkbox" class="custom-control-input layer-toggle" id="layer-USN" value="USN" checked>
                                    <label class="custom-control-label" for="layer-USN"><span class="badge" style="background-color: #6C2B00; color: #fff;">USN</span></label>
                                </div>
                                <div class="custom-control custom-checkbox custom-control-inline">
                                    <input type="checkbox" class="custom-control-input layer-toggle" id="layer-OPAREA" value="OPAREA" checked>
                                    <label class="custom-control-label" for="layer-OPAREA"><span class="badge" style="background-color: #8B4513; color: #fff;">OPAREA</span></label>
                                </div>
                                <div class="custom-control custom-checkbox custom-control-inline">
                                    <input type="checkbox" class="custom-control-input layer-toggle" id="layer-AW" value="AW" checked>
                                    <label class="custom-control-label" for="layer-AW"><span class="badge" style="background-color: #0045FF; color: #fff;">AWACS</span></label>
                                </div>
                            </div>
                        </div>
                        <!-- Routes & Special -->
                        <div class="mb-2">
                            <small class="text-muted d-block mb-1"><strong>Routes & Special</strong></small>
                            <div class="d-flex flex-wrap">
                                <div class="custom-control custom-checkbox custom-control-inline">
                                    <input type="checkbox" class="custom-control-input layer-toggle" id="layer-AR" value="AR" checked>
                                    <label class="custom-control-label" for="layer-AR"><span class="badge" style="background-color: #164856; color: #fff;">AR</span></label>
                                </div>
                                <div class="custom-control custom-checkbox custom-control-inline">
                                    <input type="checkbox" class="custom-control-input layer-toggle" id="layer-TFR" value="TFR" checked>
                                    <label class="custom-control-label" for="layer-TFR"><span class="badge" style="background-color: #EF4AC0; color: #fff;">TFR</span></label>
                                </div>
                                <div class="custom-control custom-checkbox custom-control-inline">
                                    <input type="checkbox" class="custom-control-input layer-toggle" id="layer-DZ" value="DZ" checked>
                                    <label class="custom-control-label" for="layer-DZ"><span class="badge" style="background-color: #FF6347; color: #fff;">DZ</span></label>
                                </div>
                                <div class="custom-control custom-checkbox custom-control-inline">
                                    <input type="checkbox" class="custom-control-input layer-toggle" id="layer-SS" value="SS" checked>
                                    <label class="custom-control-label" for="layer-SS"><span class="badge" style="background-color: #9932CC; color: #fff;">SS</span></label>
                                </div>
                                <div class="custom-control custom-checkbox custom-control-inline">
                                    <input type="checkbox" class="custom-control-input layer-toggle" id="layer-DC_AREA" value="DC_AREA" checked>
                                    <label class="custom-control-label" for="layer-DC_AREA"><span class="badge" style="background-color: #ff8800; color: #fff;">DC NCR</span></label>
                                </div>
                                <div class="custom-control custom-checkbox custom-control-inline">
                                    <input type="checkbox" class="custom-control-input layer-toggle" id="layer-OTHER" value="OTHER" checked>
                                    <label class="custom-control-label" for="layer-OTHER"><span class="badge badge-secondary">Other</span></label>
                                </div>
                            </div>
                        </div>
                        <!-- Boundaries -->
                        <div class="mb-2">
                            <small class="text-muted d-block mb-1"><strong>Boundaries</strong></small>
                            <div class="d-flex flex-wrap">
                                <div class="custom-control custom-checkbox custom-control-inline">
                                    <input type="checkbox" class="custom-control-input boundary-toggle" id="boundary-artcc" value="artcc" checked>
                                    <label class="custom-control-label" for="boundary-artcc"><span class="badge" style="background-color: #515151; color: #fff;">ARTCC</span></label>
                                </div>
                                <div class="custom-control custom-checkbox custom-control-inline">
                                    <input type="checkbox" class="custom-control-input boundary-toggle" id="boundary-superhigh" value="superhigh">
                                    <label class="custom-control-label" for="boundary-superhigh"><span class="badge" style="background-color: #303030; color: #fff;">Superhigh</span></label>
                                </div>
                                <div class="custom-control custom-checkbox custom-control-inline">
                                    <input type="checkbox" class="custom-control-input boundary-toggle" id="boundary-high" value="high">
                                    <label class="custom-control-label" for="boundary-high"><span class="badge" style="background-color: #303030; color: #fff;">High</span></label>
                                </div>
                                <div class="custom-control custom-checkbox custom-control-inline">
                                    <input type="checkbox" class="custom-control-input boundary-toggle" id="boundary-low" value="low">
                                    <label class="custom-control-label" for="boundary-low"><span class="badge" style="background-color: #303030; color: #fff;">Low</span></label>
                                </div>
                                <div class="custom-control custom-checkbox custom-control-inline">
                                    <input type="checkbox" class="custom-control-input boundary-toggle" id="boundary-tracon" value="tracon">
                                    <label class="custom-control-label" for="boundary-tracon"><span class="badge" style="background-color: #505050; color: #fff;">TRACON</span></label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SUA Table -->
                <div class="sua-browser" id="sua-table-container" style="display: none;">
                    <table class="table table-sm table-striped table-bordered">
                        <thead class="table-dark text-light">
                            <tr>
                                <th style="width: 8%;">Type</th>
                                <th style="width: 10%;">Designator</th>
                                <th style="width: 30%;">Name</th>
                                <th style="width: 8%;">ARTCC</th>
                                <th style="width: 15%;">Altitude</th>
                                <th style="width: 15%;">Schedule</th>
                                <th style="width: 14%;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="sua_browser_table"></tbody>
                    </table>
                </div>
            </div>
        </div>

    </center>
</div>

<!-- Schedule SUA Modal -->
<div class="modal fade" id="scheduleModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-success text-light">
                <h5 class="modal-title"><i class="fas fa-calendar-plus"></i> Schedule SUA Activation</h5>
                <button type="button" class="close text-light" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="scheduleForm">
                <div class="modal-body">
                    <input type="hidden" name="sua_id" id="schedule_sua_id">

                    <div class="row">
                        <div class="col-md-5">
                            <div class="form-group">
                                <label>SUA Name</label>
                                <input type="text" class="form-control" name="name" id="schedule_name" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>SUA Type</label>
                                <select class="form-control" name="sua_type" id="schedule_sua_type" required>
                                    <option value="">Select Type</option>
                                    <option value="P">Prohibited (P)</option>
                                    <option value="R">Restricted (R)</option>
                                    <option value="W">Warning (W)</option>
                                    <option value="A">Alert (A)</option>
                                    <option value="MOA">MOA</option>
                                    <option value="NSA">NSA</option>
                                    <option value="ATCAA">ATCAA</option>
                                    <option value="OTHER">Other</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>ARTCC</label>
                                <select class="form-control" name="artcc" id="schedule_artcc">
                                    <option value="">Select ARTCC</option>
                                    <?php foreach ($artccs as $artcc): ?>
                                        <option value="<?= $artcc ?>"><?= $artcc ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Start Time (UTC)</label>
                                <input type="datetime-local" class="form-control" name="start_utc" id="schedule_start" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>End Time (UTC)</label>
                                <input type="datetime-local" class="form-control" name="end_utc" id="schedule_end" required>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Lower Altitude</label>
                                <input type="text" class="form-control" name="lower_alt" id="schedule_lower_alt" placeholder="e.g., GND, FL180">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Upper Altitude</label>
                                <input type="text" class="form-control" name="upper_alt" id="schedule_upper_alt" placeholder="e.g., FL350, UNLTD">
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Remarks</label>
                        <textarea class="form-control" name="remarks" id="schedule_remarks" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-success" value="Schedule Activation">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Create TFR Modal -->
<div class="modal fade" id="tfrModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title"><i class="fas fa-exclamation-triangle"></i> Create Temporary Flight Restriction</h5>
                <button type="button" class="close text-dark" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="tfrForm">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>TFR Type</label>
                                <select class="form-control" name="tfr_subtype" id="tfr_subtype" required>
                                    <?php foreach ($tfr_subtypes as $key => $label): ?>
                                        <option value="<?= $key ?>"><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>ARTCC</label>
                                <select class="form-control" name="artcc" id="tfr_artcc">
                                    <option value="">Select ARTCC</option>
                                    <?php foreach ($artccs as $artcc): ?>
                                        <option value="<?= $artcc ?>"><?= $artcc ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>TFR Name/Description</label>
                        <input type="text" class="form-control" name="name" id="tfr_name" required placeholder="e.g., VIP Movement - KJFK">
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Start Time (UTC)</label>
                                <input type="datetime-local" class="form-control" name="start_utc" id="tfr_start" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>End Time (UTC)</label>
                                <input type="datetime-local" class="form-control" name="end_utc" id="tfr_end" required>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Lower Altitude</label>
                                <input type="text" class="form-control" name="lower_alt" id="tfr_lower_alt" value="GND">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Upper Altitude</label>
                                <input type="text" class="form-control" name="upper_alt" id="tfr_upper_alt" value="FL180">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>NOTAM Number</label>
                                <input type="text" class="form-control" name="notam_number" id="tfr_notam" placeholder="Optional">
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Center Point (for circular TFR)</label>
                        <div class="row">
                            <div class="col-md-4">
                                <input type="text" class="form-control" id="tfr_lat" placeholder="Latitude (e.g., 40.6413)">
                            </div>
                            <div class="col-md-4">
                                <input type="text" class="form-control" id="tfr_lon" placeholder="Longitude (e.g., -73.7781)">
                            </div>
                            <div class="col-md-4">
                                <input type="text" class="form-control" id="tfr_radius" placeholder="Radius NM (e.g., 3)">
                            </div>
                        </div>
                        <small class="text-muted">Enter center coordinates and radius to generate circular TFR geometry</small>
                    </div>

                    <div class="form-group">
                        <label>Remarks</label>
                        <textarea class="form-control" name="remarks" id="tfr_remarks" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-warning" value="Create TFR">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Activation Modal -->
<div class="modal fade" id="editModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title"><i class="fas fa-edit"></i> Edit Activation</h5>
                <button type="button" class="close text-dark" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="editForm">
                <div class="modal-body">
                    <input type="hidden" name="id" id="edit_id">

                    <div class="form-group">
                        <label>Name</label>
                        <input type="text" class="form-control" id="edit_name" readonly>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Start Time (UTC)</label>
                                <input type="datetime-local" class="form-control" name="start_utc" id="edit_start">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>End Time (UTC)</label>
                                <input type="datetime-local" class="form-control" name="end_utc" id="edit_end">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Lower Altitude</label>
                                <input type="text" class="form-control" name="lower_alt" id="edit_lower_alt">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Upper Altitude</label>
                                <input type="text" class="form-control" name="upper_alt" id="edit_upper_alt">
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Status</label>
                        <select class="form-control" name="status" id="edit_status">
                            <option value="SCHEDULED">Scheduled</option>
                            <option value="ACTIVE">Active</option>
                            <option value="EXPIRED">Expired</option>
                            <option value="CANCELLED">Cancelled</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Remarks</label>
                        <textarea class="form-control" name="remarks" id="edit_remarks" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-warning" value="Update">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ALTRV Creation Modal -->
<div class="modal fade" id="altrvModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-info text-light">
                <h5 class="modal-title"><i class="fas fa-draw-polygon"></i> Create ALTRV</h5>
                <button type="button" class="close text-light" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="altrvForm">
                <div class="modal-body">
                    <input type="hidden" name="geometry" id="altrv_geometry">

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>ALTRV Name/Designator</label>
                                <input type="text" class="form-control" name="name" id="altrv_name" placeholder="e.g., AR-123" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>ARTCC</label>
                                <select class="form-control" name="artcc" id="altrv_artcc">
                                    <option value="">Select ARTCC</option>
                                    <?php foreach ($artccs as $artcc): ?>
                                        <option value="<?= $artcc ?>"><?= $artcc ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Start Time (UTC)</label>
                                <input type="datetime-local" class="form-control" name="start_utc" id="altrv_start" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>End Time (UTC)</label>
                                <input type="datetime-local" class="form-control" name="end_utc" id="altrv_end" required>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Lower Altitude</label>
                                <input type="text" class="form-control" name="lower_alt" id="altrv_lower" placeholder="e.g., FL180 or 18000">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Upper Altitude</label>
                                <input type="text" class="form-control" name="upper_alt" id="altrv_upper" placeholder="e.g., FL350 or 35000">
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Route/Description</label>
                        <textarea class="form-control" name="description" id="altrv_description" rows="2" placeholder="Route description or remarks"></textarea>
                    </div>

                    <div class="alert alert-info" id="altrv_geometry_info">
                        <i class="fas fa-info-circle"></i> <span id="altrv_point_count">No geometry drawn</span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" onclick="clearAltrvDrawing()">
                        <i class="fas fa-eraser"></i> Clear Drawing
                    </button>
                    <input type="submit" class="btn btn-info" value="Create ALTRV">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal" onclick="cancelAltrvDrawing()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include('load/footer.php'); ?>

<!-- MapLibre GL JS -->
<script src="https://unpkg.com/maplibre-gl@4.7.1/dist/maplibre-gl.js" crossorigin=""></script>
<!-- MapLibre Draw (Mapbox GL Draw compatible) -->
<script src="https://unpkg.com/@mapbox/mapbox-gl-draw@1.4.3/dist/mapbox-gl-draw.js" crossorigin=""></script>

<script src="assets/js/sua.js"></script>

</body>
</html>
