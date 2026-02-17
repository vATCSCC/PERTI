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

    if ($perm != true) {
        http_response_code(403);
        exit();
    }

    // ARTCC list for filters
    $artccs = ['ZAB', 'ZAU', 'ZBW', 'ZDC', 'ZDV', 'ZFW', 'ZHU', 'ZID', 'ZJX', 'ZKC', 'ZLA', 'ZLC', 'ZMA', 'ZME', 'ZMP', 'ZNY', 'ZOA', 'ZOB', 'ZSE', 'ZTL'];

    // TFR subtypes
    $tfr_subtypes = [
        'HAZARD' => __('sua.page.tfrSubtypeHazard'),
        'VIP' => __('sua.page.tfrSubtypeVip'),
        'SECURITY' => __('sua.page.tfrSubtypeSecurity'),
        'HAWAII' => __('sua.page.tfrSubtypeHawaii'),
        'EMERGENCY' => __('sua.page.tfrSubtypeEmergency'),
        'EVENT' => __('sua.page.tfrSubtypeEvent'),
        'PRESSURE' => __('sua.page.tfrSubtypePressure'),
        'SPACE' => __('sua.page.tfrSubtypeSpace'),
        'MASS_GATHERING' => __('sua.page.tfrSubtypeMassGathering'),
        'OTHER' => __('sua.page.tfrSubtypeOther')
    ];

?>

<!DOCTYPE html>
<html>

<head>
    <?php $page_title = "vATCSCC SUA"; include("load/header.php"); ?>

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
            <h2><?= __('sua.page.title') ?></h2>
            <p class="text-muted"><?= __('sua.page.subtitle') ?></p>
        </center>
    </div>
</section>

<div class="container-fluid mt-4 mb-5">
    <center>

        <!-- Action Buttons -->
        <div class="mb-4">
            <button class="btn btn-success btn-sm" data-toggle="modal" data-target="#scheduleModal">
                <i class="fas fa-calendar-plus"></i> <?= __('sua.page.scheduleSuaActivation') ?>
            </button>
            <button class="btn btn-warning btn-sm" data-toggle="modal" data-target="#tfrModal">
                <i class="fas fa-exclamation-triangle"></i> <?= __('sua.page.createTfr') ?>
            </button>
            <button class="btn btn-info btn-sm" onclick="startAltrvDrawing()">
                <i class="fas fa-draw-polygon"></i> <?= __('sua.page.drawAltrv') ?>
            </button>
        </div>

        <!-- Activations Table -->
        <div class="card mb-4" style="max-width: 1400px;">
            <div class="card-header bg-dark text-light">
                <strong><?= __('sua.page.scheduledActivations') ?></strong>
                <div class="float-right">
                    <select id="statusFilter" class="form-control form-control-sm d-inline-block" style="width: auto;">
                        <option value=""><?= __('sua.page.activeAndScheduled') ?></option>
                        <option value="ALL"><?= __('common.all') ?></option>
                        <option value="ACTIVE"><?= __('sua.page.activeOnly') ?></option>
                        <option value="SCHEDULED"><?= __('sua.page.scheduledOnly') ?></option>
                        <option value="EXPIRED"><?= __('sua.page.expired') ?></option>
                        <option value="CANCELLED"><?= __('sua.page.cancelled') ?></option>
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
                            <th style="width: 10%;"><?= __('sua.page.colType') ?></th>
                            <th style="width: 25%;"><?= __('sua.page.colName') ?></th>
                            <th style="width: 8%;"><?= __('sua.page.colArtcc') ?></th>
                            <th style="width: 12%;"><?= __('sua.page.colStartUtc') ?></th>
                            <th style="width: 12%;"><?= __('sua.page.colEndUtc') ?></th>
                            <th style="width: 12%;"><?= __('sua.page.colAltitude') ?></th>
                            <th style="width: 10%;"><?= __('sua.page.colStatus') ?></th>
                            <th style="width: 11%;"><?= __('sua.page.colActions') ?></th>
                        </tr>
                    </thead>
                    <tbody id="activations_table"></tbody>
                </table>
            </div>
        </div>

        <!-- SUA Browser -->
        <div class="card" style="max-width: 1400px;">
            <div class="card-header bg-dark text-light">
                <strong><?= __('sua.page.suaBrowser') ?></strong>
                <span class="badge badge-light ml-2" id="sua_count">0</span>
                <div class="float-right">
                    <div class="btn-group btn-group-sm" role="group">
                        <button type="button" class="btn btn-outline-light active" id="viewMapBtn" onclick="toggleView('map')">
                            <i class="fas fa-map"></i> <?= __('sua.page.map') ?>
                        </button>
                        <button type="button" class="btn btn-outline-light" id="viewTableBtn" onclick="toggleView('table')">
                            <i class="fas fa-table"></i> <?= __('sua.page.table') ?>
                        </button>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <!-- Filters -->
                <div class="filter-row">
                    <div class="row">
                        <div class="col-md-4">
                            <input type="text" class="form-control form-control-sm" id="suaSearch" placeholder="<?= __('sua.page.searchPlaceholder') ?>">
                        </div>
                        <div class="col-md-3">
                            <select class="form-control form-control-sm" id="suaTypeFilter">
                                <option value=""><?= __('sua.page.allTypes') ?></option>
                                <optgroup label="<?= __('sua.page.airspaceAreas') ?>">
                                    <option value="PROHIBITED"><?= __('sua.page.prohibited') ?></option>
                                    <option value="RESTRICTED"><?= __('sua.page.restricted') ?></option>
                                    <option value="WARNING"><?= __('sua.page.warning') ?></option>
                                    <option value="ALERT"><?= __('sua.page.alert') ?></option>
                                    <option value="MOA">MOA</option>
                                    <option value="NSA">NSA</option>
                                    <option value="TFR">TFR</option>
                                    <option value="ADIZ">ADIZ</option>
                                    <option value="FRZ">FRZ</option>
                                </optgroup>
                                <optgroup label="<?= __('sua.page.militaryAreas') ?>">
                                    <option value="USN"><?= __('sua.page.usnNavy') ?></option>
                                    <option value="USAF">USAF</option>
                                    <option value="USArmy"><?= __('sua.page.usArmy') ?></option>
                                    <option value="ANG"><?= __('sua.page.airNationalGuard') ?></option>
                                    <option value="OPAREA"><?= __('sua.page.operatingArea') ?></option>
                                    <option value="DZ"><?= __('sua.page.dropZone') ?></option>
                                    <option value="LASER"><?= __('sua.page.laser') ?></option>
                                    <option value="NUCLEAR"><?= __('sua.page.nuclear') ?></option>
                                </optgroup>
                                <optgroup label="<?= __('sua.page.routesTracks') ?>">
                                    <option value="AR"><?= __('sua.page.airRefueling') ?></option>
                                    <option value="ALTRV">ALTRV</option>
                                    <option value="OSARA">OSARA</option>
                                    <option value="SS"><?= __('sua.page.supersonic') ?></option>
                                </optgroup>
                                <optgroup label="<?= __('sua.page.special') ?>">
                                    <option value="AW"><?= __('sua.page.awacsOrbit') ?></option>
                                    <option value="WSRP"><?= __('sua.page.weatherRadar') ?></option>
                                    <option value="NORAD">NORAD</option>
                                    <option value="NASA">NASA</option>
                                    <option value="NOAA">NOAA</option>
                                </optgroup>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select class="form-control form-control-sm" id="suaArtccFilter">
                                <option value=""><?= __('sua.page.allArtccs') ?></option>
                                <?php foreach ($artccs as $artcc): ?>
                                    <option value="<?= $artcc ?>"><?= $artcc ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-sm btn-primary btn-block" onclick="loadSuaBrowser()">
                                <i class="fas fa-search"></i> <?= __('sua.page.searchBtn') ?>
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
                            <strong class="text-dark"><?= __('sua.page.mapLayers') ?></strong>
                            <div class="btn-group btn-group-sm" role="group">
                                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="toggleAllLayers(true)"><?= __('sua.page.allOn') ?></button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="toggleAllLayers(false)"><?= __('sua.page.allOff') ?></button>
                            </div>
                        </div>
                        <!-- Regulatory Airspace -->
                        <div class="mb-2">
                            <small class="text-muted d-block mb-1"><strong><?= __('sua.page.regulatory') ?></strong></small>
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
                            <small class="text-muted d-block mb-1"><strong><?= __('sua.page.military') ?></strong></small>
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
                            <small class="text-muted d-block mb-1"><strong><?= __('sua.page.routesAndSpecial') ?></strong></small>
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
                            <small class="text-muted d-block mb-1"><strong><?= __('sua.page.boundaries') ?></strong></small>
                            <div class="d-flex flex-wrap">
                                <div class="custom-control custom-checkbox custom-control-inline">
                                    <input type="checkbox" class="custom-control-input boundary-toggle" id="boundary-artcc" value="artcc" checked>
                                    <label class="custom-control-label" for="boundary-artcc"><span class="badge" style="background-color: #515151; color: #fff;">ARTCC</span></label>
                                </div>
                                <div class="custom-control custom-checkbox custom-control-inline">
                                    <input type="checkbox" class="custom-control-input boundary-toggle" id="boundary-superhigh" value="superhigh">
                                    <label class="custom-control-label" for="boundary-superhigh"><span class="badge" style="background-color: #303030; color: #fff;"><?= __('sua.page.superhigh') ?></span></label>
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
                                <th style="width: 8%;"><?= __('sua.page.colType') ?></th>
                                <th style="width: 10%;"><?= __('sua.page.colDesignator') ?></th>
                                <th style="width: 30%;"><?= __('sua.page.colName') ?></th>
                                <th style="width: 8%;"><?= __('sua.page.colArtcc') ?></th>
                                <th style="width: 15%;"><?= __('sua.page.colAltitude') ?></th>
                                <th style="width: 15%;"><?= __('sua.page.colSchedule') ?></th>
                                <th style="width: 14%;"><?= __('sua.page.colAction') ?></th>
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
                <h5 class="modal-title"><i class="fas fa-calendar-plus"></i> <?= __('sua.page.scheduleModalTitle') ?></h5>
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
                                <label><?= __('sua.page.suaName') ?></label>
                                <input type="text" class="form-control" name="name" id="schedule_name" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label><?= __('sua.page.suaType') ?></label>
                                <select class="form-control" name="sua_type" id="schedule_sua_type" required>
                                    <option value=""><?= __('sua.page.selectType') ?></option>
                                    <option value="P"><?= __('sua.page.prohibitedP') ?></option>
                                    <option value="R"><?= __('sua.page.restrictedR') ?></option>
                                    <option value="W"><?= __('sua.page.warningW') ?></option>
                                    <option value="A"><?= __('sua.page.alertA') ?></option>
                                    <option value="MOA">MOA</option>
                                    <option value="NSA">NSA</option>
                                    <option value="ATCAA">ATCAA</option>
                                    <option value="OTHER"><?= __('common.other') ?></option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label><?= __('sua.page.colArtcc') ?></label>
                                <select class="form-control" name="artcc" id="schedule_artcc">
                                    <option value=""><?= __('sua.page.selectArtcc') ?></option>
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
                                <label><?= __('sua.page.startTimeUtc') ?></label>
                                <input type="datetime-local" class="form-control" name="start_utc" id="schedule_start" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?= __('sua.page.endTimeUtc') ?></label>
                                <input type="datetime-local" class="form-control" name="end_utc" id="schedule_end" required>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?= __('sua.page.lowerAltitude') ?></label>
                                <input type="text" class="form-control" name="lower_alt" id="schedule_lower_alt" placeholder="<?= __('sua.page.lowerAltPlaceholder') ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?= __('sua.page.upperAltitude') ?></label>
                                <input type="text" class="form-control" name="upper_alt" id="schedule_upper_alt" placeholder="<?= __('sua.page.upperAltPlaceholder') ?>">
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label><?= __('sua.page.remarksLabel') ?></label>
                        <textarea class="form-control" name="remarks" id="schedule_remarks" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-success" value="<?= __('sua.page.scheduleActivationBtn') ?>">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal"><?= __('sua.page.cancel') ?></button>
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
                <h5 class="modal-title"><i class="fas fa-exclamation-triangle"></i> <?= __('sua.page.createTfrTitle') ?></h5>
                <button type="button" class="close text-dark" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="tfrForm">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?= __('sua.page.tfrType') ?></label>
                                <select class="form-control" name="tfr_subtype" id="tfr_subtype" required>
                                    <?php foreach ($tfr_subtypes as $key => $label): ?>
                                        <option value="<?= $key ?>"><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?= __('sua.page.colArtcc') ?></label>
                                <select class="form-control" name="artcc" id="tfr_artcc">
                                    <option value=""><?= __('sua.page.selectArtcc') ?></option>
                                    <?php foreach ($artccs as $artcc): ?>
                                        <option value="<?= $artcc ?>"><?= $artcc ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label><?= __('sua.page.tfrName') ?></label>
                        <input type="text" class="form-control" name="name" id="tfr_name" required placeholder="<?= __('sua.page.tfrNamePlaceholder') ?>">
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?= __('sua.page.startTimeUtc') ?></label>
                                <input type="datetime-local" class="form-control" name="start_utc" id="tfr_start" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?= __('sua.page.endTimeUtc') ?></label>
                                <input type="datetime-local" class="form-control" name="end_utc" id="tfr_end" required>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label><?= __('sua.page.lowerAltitude') ?></label>
                                <input type="text" class="form-control" name="lower_alt" id="tfr_lower_alt" value="GND">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label><?= __('sua.page.upperAltitude') ?></label>
                                <input type="text" class="form-control" name="upper_alt" id="tfr_upper_alt" value="FL180">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label><?= __('sua.page.notamNumber') ?></label>
                                <input type="text" class="form-control" name="notam_number" id="tfr_notam" placeholder="<?= __('sua.page.optional') ?>">
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label><?= __('sua.page.centerPoint') ?></label>
                        <div class="row">
                            <div class="col-md-4">
                                <input type="text" class="form-control" id="tfr_lat" placeholder="<?= __('sua.page.latPlaceholder') ?>">
                            </div>
                            <div class="col-md-4">
                                <input type="text" class="form-control" id="tfr_lon" placeholder="<?= __('sua.page.lonPlaceholder') ?>">
                            </div>
                            <div class="col-md-4">
                                <input type="text" class="form-control" id="tfr_radius" placeholder="<?= __('sua.page.radiusPlaceholder') ?>">
                            </div>
                        </div>
                        <small class="text-muted"><?= __('sua.page.circularTfrHint') ?></small>
                    </div>

                    <div class="form-group">
                        <label><?= __('sua.page.remarksLabel') ?></label>
                        <textarea class="form-control" name="remarks" id="tfr_remarks" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-warning" value="<?= __('sua.page.createTfrBtn') ?>">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal"><?= __('sua.page.cancel') ?></button>
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
                <h5 class="modal-title"><i class="fas fa-edit"></i> <?= __('sua.page.editActivationTitle') ?></h5>
                <button type="button" class="close text-dark" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="editForm">
                <div class="modal-body">
                    <input type="hidden" name="id" id="edit_id">

                    <div class="form-group">
                        <label><?= __('sua.page.name') ?></label>
                        <input type="text" class="form-control" id="edit_name" readonly>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?= __('sua.page.startTimeUtc') ?></label>
                                <input type="datetime-local" class="form-control" name="start_utc" id="edit_start">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?= __('sua.page.endTimeUtc') ?></label>
                                <input type="datetime-local" class="form-control" name="end_utc" id="edit_end">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?= __('sua.page.lowerAltitude') ?></label>
                                <input type="text" class="form-control" name="lower_alt" id="edit_lower_alt">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?= __('sua.page.upperAltitude') ?></label>
                                <input type="text" class="form-control" name="upper_alt" id="edit_upper_alt">
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label><?= __('sua.page.status') ?></label>
                        <select class="form-control" name="status" id="edit_status">
                            <option value="SCHEDULED"><?= __('sua.page.scheduled') ?></option>
                            <option value="ACTIVE"><?= __('sua.page.active') ?></option>
                            <option value="EXPIRED"><?= __('sua.page.expired') ?></option>
                            <option value="CANCELLED"><?= __('sua.page.cancelled') ?></option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label><?= __('sua.page.remarksLabel') ?></label>
                        <textarea class="form-control" name="remarks" id="edit_remarks" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-warning" value="<?= __('sua.page.updateBtn') ?>">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal"><?= __('sua.page.cancel') ?></button>
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
                <h5 class="modal-title"><i class="fas fa-draw-polygon"></i> <?= __('sua.page.createAltrvTitle') ?></h5>
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
                                <label><?= __('sua.page.altrvName') ?></label>
                                <input type="text" class="form-control" name="name" id="altrv_name" placeholder="<?= __('sua.page.altrvNamePlaceholder') ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?= __('sua.page.colArtcc') ?></label>
                                <select class="form-control" name="artcc" id="altrv_artcc">
                                    <option value=""><?= __('sua.page.selectArtcc') ?></option>
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
                                <label><?= __('sua.page.startTimeUtc') ?></label>
                                <input type="datetime-local" class="form-control" name="start_utc" id="altrv_start" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?= __('sua.page.endTimeUtc') ?></label>
                                <input type="datetime-local" class="form-control" name="end_utc" id="altrv_end" required>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?= __('sua.page.lowerAltitude') ?></label>
                                <input type="text" class="form-control" name="lower_alt" id="altrv_lower" placeholder="<?= __('sua.page.lowerAltPlaceholder') ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?= __('sua.page.upperAltitude') ?></label>
                                <input type="text" class="form-control" name="upper_alt" id="altrv_upper" placeholder="<?= __('sua.page.upperAltPlaceholder') ?>">
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label><?= __('sua.page.routeDescription') ?></label>
                        <textarea class="form-control" name="description" id="altrv_description" rows="2" placeholder="<?= __('sua.page.routeDescPlaceholder') ?>"></textarea>
                    </div>

                    <div class="alert alert-info" id="altrv_geometry_info">
                        <i class="fas fa-info-circle"></i> <span id="altrv_point_count"><?= __('sua.page.noGeometryDrawn') ?></span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" onclick="clearAltrvDrawing()">
                        <i class="fas fa-eraser"></i> <?= __('sua.page.clearDrawing') ?>
                    </button>
                    <input type="submit" class="btn btn-info" value="<?= __('sua.page.createAltrvBtn') ?>">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal" onclick="cancelAltrvDrawing()"><?= __('sua.page.cancel') ?></button>
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
