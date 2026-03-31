<?php
include("sessions/handler.php");
if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

include("load/config.php");
include("load/connect.php");

$perm = false;
$cid = null;
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
    $cid = 0;
}

if (!$perm) {
    header('Location: /login/');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <script>window.PERTI_USE_MAPLIBRE = true;</script>
    <link href="https://unpkg.com/maplibre-gl@4.5.0/dist/maplibre-gl.css" rel="stylesheet" />
    <script src="https://unpkg.com/maplibre-gl@4.5.0/dist/maplibre-gl.js"></script>
    <script src="https://unpkg.com/@turf/turf@6/turf.min.js"></script>
    <link rel="stylesheet" href="assets/css/route-analysis.css">
    <link rel="stylesheet" href="assets/css/rad.css">
</head>
<body>
<?php include("load/nav.php"); ?>

<div class="rad-map-section" id="rad_map_section">
    <div class="rad-map-controls">
        <textarea id="routeSearch" class="rad-route-input" rows="2"></textarea>
        <button id="plot_r" class="btn btn-sm btn-primary ml-2">Plot</button>
    </div>
    <div id="map_wrapper" class="rad-map-wrapper">
        <div id="placeholder"></div>
        <div id="graphic"></div>
    </div>
</div>

<div class="container-fluid rad-tabs-container mt-2">
    <ul class="nav nav-tabs" id="radTabs" role="tablist">
        <li class="nav-item">
            <a class="nav-link active" id="tab-search" data-toggle="tab" href="#pane-search" role="tab">
                <i class="fas fa-search mr-1"></i>Search
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="tab-detail" data-toggle="tab" href="#pane-detail" role="tab">
                <i class="fas fa-plane mr-1"></i>Detail
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="tab-edit" data-toggle="tab" href="#pane-edit" role="tab">
                <i class="fas fa-edit mr-1"></i>Edit
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="tab-monitoring" data-toggle="tab" href="#pane-monitoring" role="tab">
                <i class="fas fa-eye mr-1"></i>Monitoring
            </a>
        </li>
    </ul>

    <div class="tab-content" id="radTabContent">
        <div class="tab-pane fade show active" id="pane-search" role="tabpanel">
            <div class="rad-search-bar mt-2 mb-2">
                <input type="text" id="rad_cs_search" class="form-control form-control-sm d-inline-block" style="width:200px;" placeholder="Search flights">
            </div>
            <table class="table table-sm rad-table" id="rad_search_table">
                <thead><tr><th>Callsign</th><th>O/D</th><th>Type</th><th>Times</th><th>Status</th></tr></thead>
                <tbody id="rad_search_tbody"></tbody>
            </table>
        </div>

        <div class="tab-pane fade" id="pane-detail" role="tabpanel">
            <table class="table table-sm rad-table" id="rad_detail_table">
                <thead><tr><th>Callsign</th><th>O/D</th><th>TRACON</th><th>Center</th><th>Amendment</th><th>Route</th></tr></thead>
                <tbody id="rad_detail_tbody"></tbody>
            </table>
        </div>

        <div class="tab-pane fade" id="pane-edit" role="tabpanel">
            <div class="row mt-2">
                <div class="col-md-5 rad-edit-left">
                    <textarea id="rad_manual_route" class="form-control form-control-sm mb-2" rows="2"></textarea>
                    <button class="btn btn-sm btn-success" id="rad_validate_route">Validate</button>
                </div>
                <div class="col-md-7 rad-edit-right">
                    <div id="rad_amendment_preview" class="rad-amendment-preview mb-2"></div>
                    <button class="btn btn-success" id="rad_send_amendment">Send Amendment</button>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="pane-monitoring" role="tabpanel">
            <table class="table table-sm rad-table" id="rad_monitor_table">
                <thead><tr><th>Callsign</th><th>Status</th><th>Amdt</th><th>Sent</th></tr></thead>
                <tbody id="rad_monitor_tbody"></tbody>
            </table>
        </div>
    </div>
</div>

<?php include('load/footer.php'); ?>
<script src="assets/js/rad.js"></script>
</body>
</html>
