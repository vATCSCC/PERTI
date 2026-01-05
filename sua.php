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

    <!-- Leaflet CSS for map -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
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
            <div class="card-header bg-secondary text-light">
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
                                <option value="P">Prohibited (P)</option>
                                <option value="R">Restricted (R)</option>
                                <option value="W">Warning (W)</option>
                                <option value="A">Alert (A)</option>
                                <option value="MOA">MOA</option>
                                <option value="NSA">NSA</option>
                                <option value="ATCAA">ATCAA</option>
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
                    <div class="mt-2">
                        <small class="text-muted">
                            <span class="badge" style="background-color: #ff0000; color: #fff;">P</span> Prohibited
                            <span class="badge" style="background-color: #ff6600; color: #fff;">R</span> Restricted
                            <span class="badge" style="background-color: #9900ff; color: #fff;">W</span> Warning
                            <span class="badge" style="background-color: #ff00ff; color: #fff;">A</span> Alert
                            <span class="badge" style="background-color: #0066ff; color: #fff;">MOA</span> Military Operations Area
                            <span class="badge" style="background-color: #00cc00; color: #fff;">NSA</span> National Security Area
                        </small>
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
                    <input type="hidden" name="sua_type" id="schedule_sua_type">

                    <div class="row">
                        <div class="col-md-8">
                            <div class="form-group">
                                <label>SUA Name</label>
                                <input type="text" class="form-control" name="name" id="schedule_name" required>
                            </div>
                        </div>
                        <div class="col-md-4">
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

<?php include('load/footer.php'); ?>

<!-- Leaflet JS -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

<script src="assets/js/sua.js"></script>

</body>
</html>
