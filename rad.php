<?php
include("sessions/handler.php");
if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

include("load/config.php");
define('PERTI_MYSQL_ONLY', true);
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
    <?php
        $page_title = __('rad.pageTitle');
        include("load/header.php");
    ?>

    <!-- MapLibre GL JS -->
    <script>window.PERTI_USE_MAPLIBRE = true;</script>
    <link href="https://unpkg.com/maplibre-gl@4.5.0/dist/maplibre-gl.css" rel="stylesheet" />
    <script src="https://unpkg.com/maplibre-gl@4.5.0/dist/maplibre-gl.js"></script>
    <script src="https://unpkg.com/@turf/turf@6/turf.min.js"></script>

    <link rel="stylesheet" href="assets/css/route-analysis.css<?= _v('assets/css/route-analysis.css') ?>">
    <link rel="stylesheet" href="assets/css/playbook-search-panel.css<?= _v('assets/css/playbook-search-panel.css') ?>">
    <link rel="stylesheet" href="assets/css/rad.css<?= _v('assets/css/rad.css') ?>">
</head>
<body>
<?php include("load/nav.php"); ?>

<div class="rad-app">
<div class="rad-role-bar d-flex align-items-center px-3 py-1" style="background:#16213e; border-bottom:1px solid #334; font-size:0.82rem;">
    <span class="text-muted mr-2" data-i18n="rad.role.detecting">Detecting role...</span>
    <span id="rad_role_indicator"></span>
    <div id="rad_va_selector" class="ml-auto" style="display:none;">
        <select id="rad_va_airline" class="form-control form-control-sm" style="width:auto;display:inline-block;background:#111;color:#ccc;border-color:#445;font-size:0.82rem;">
            <option value="">-- Select VA --</option>
        </select>
    </div>
</div>
<div class="rad-map-section" id="rad_map_section">
    <div class="rad-map-controls">
        <textarea id="routeSearch" class="rad-route-input" rows="2"></textarea>
        <div class="rad-map-controls-row">
            <button id="plot_r" class="btn btn-sm btn-primary" data-i18n="rad.edit.plot">Plot</button>
            <button id="rad_btn_pbcdr" class="btn btn-sm btn-outline-light" title="Playbook / CDR / Preferred Routes"><i class="fas fa-book"></i> PB/CDR/PR</button>
            <button id="rad_btn_live_traffic" class="btn btn-sm btn-outline-light" title="Toggle Live Traffic"><i class="fas fa-plane"></i> <span data-i18n="rad.map.liveTraffic">Live</span></button>
            <input type="checkbox" id="adl_toggle" style="display:none;">
        </div>
    </div>
    <div id="map_wrapper" class="rad-map-wrapper">
        <div id="placeholder"></div>
        <div id="graphic"></div>
    </div>
    <?php include('load/playbook_search_panel.php'); ?>
</div>

<div class="container-fluid rad-tabs-container">
    <ul class="nav nav-tabs" id="radTabs" role="tablist">
        <li class="nav-item">
            <a class="nav-link active" id="tab-search" data-toggle="tab" href="#pane-search" role="tab">
                <i class="fas fa-search mr-1"></i><span data-i18n="rad.tabs.search">Search</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="tab-detail" data-toggle="tab" href="#pane-detail" role="tab">
                <i class="fas fa-plane mr-1"></i><span data-i18n="rad.tabs.detail">Detail</span> <span class="badge badge-primary" id="rad_detail_badge" style="display:none;"></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="tab-edit" data-toggle="tab" href="#pane-edit" role="tab">
                <i class="fas fa-edit mr-1"></i><span data-i18n="rad.tabs.edit">Edit</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="tab-monitoring" data-toggle="tab" href="#pane-monitoring" role="tab">
                <i class="fas fa-eye mr-1"></i><span data-i18n="rad.tabs.monitoring">Monitoring</span> <span class="badge badge-warning" id="rad_monitoring_badge" style="display:none;"></span>
            </a>
        </li>
    </ul>

    <div class="tab-content" id="radTabContent">
        <div class="tab-pane fade show active" id="pane-search" role="tabpanel">
            <div id="rad_filter_panel" class="mt-2"></div>
            <div class="d-flex align-items-center mb-2">
                <button class="btn btn-sm btn-outline-secondary mr-2" id="rad_btn_select_all"><span data-i18n="rad.detail.selectAll">Select All</span></button>
                <button class="btn btn-sm btn-outline-primary mr-2" id="rad_btn_add_to_detail"><span data-i18n="rad.search.addToDetail">Add to Detail</span></button>
                <span class="text-muted ml-auto"><span id="rad_search_count">0</span> <span data-i18n="rad.search.resultCount">flights</span></span>
            </div>
            <table class="table table-sm rad-table" id="rad_search_table">
                <thead id="rad_search_thead"><tr>
                    <th style="width:30px;"></th>
                    <th data-sort="callsign" data-i18n="rad.search.callsign">Callsign</th>
                    <th data-sort="origin" data-i18n="rad.search.origDest">Orig/Dest</th>
                    <th data-sort="etd_utc" data-i18n="rad.search.times">Times</th>
                    <th data-sort="phase" data-i18n="rad.search.status">Status</th>
                </tr></thead>
                <tbody id="rad_search_tbody"></tbody>
            </table>
        </div>

        <div class="tab-pane fade" id="pane-detail" role="tabpanel">
            <div class="d-flex align-items-center mt-2 mb-2">
                <button class="btn btn-sm btn-outline-secondary mr-1" id="rad_btn_select_all_detail"><span data-i18n="rad.detail.selectAll">Select All</span></button>
                <button class="btn btn-sm btn-outline-secondary mr-1" id="rad_btn_select_none_detail"><span data-i18n="rad.detail.selectNone">Select None</span></button>
                <button class="btn btn-sm btn-outline-danger mr-2" id="rad_btn_remove_selected"><span data-i18n="rad.detail.removeSelected">Remove Selected</span></button>
                <button class="btn btn-sm btn-outline-primary" id="rad_btn_plot_all"><i class="fas fa-route mr-1"></i><span data-i18n="rad.detail.plotAll">Plot All Routes</span></button>
            </div>
            <table class="table table-sm rad-table" id="rad_detail_table">
                <thead><tr>
                    <th style="width:30px;"></th>
                    <th data-i18n="rad.search.callsign">Callsign</th>
                    <th data-i18n="rad.search.origDest">O/D</th>
                    <th data-i18n="rad.detail.tracon">TRACON</th>
                    <th data-i18n="rad.detail.center">Center</th>
                    <th data-i18n="rad.detail.amendment">Amendment</th>
                    <th data-i18n="rad.detail.route">Route</th>
                    <th data-i18n="rad.search.type">Type</th>
                    <th data-i18n="rad.search.times">Times</th>
                    <th data-i18n="rad.search.status">Phase</th>
                    <th></th>
                </tr></thead>
                <tbody id="rad_detail_tbody"></tbody>
            </table>
        </div>

        <div class="tab-pane fade" id="pane-edit" role="tabpanel">
            <div class="row mt-2">
                <!-- Left: Retrieve Routes -->
                <div class="col-md-5 rad-edit-left">
                    <label data-i18n="rad.edit.routeOptions">Route Options</label>
                    <div class="btn-group btn-group-sm mb-2">
                        <button class="btn btn-outline-secondary" id="rad_btn_recent" data-i18n="rad.edit.recentlySent">Recently Sent</button>
                        <button class="btn btn-outline-secondary" id="rad_btn_search_db" data-i18n="rad.edit.searchDb">Search DB</button>
                        <button class="btn btn-outline-secondary" id="rad_btn_route_options" data-i18n="rad.edit.routeOptions">Route Options</button>
                    </div>
                    <div class="input-group input-group-sm mb-2">
                        <input type="text" id="rad_cdr_code" class="form-control" placeholder="CDR Code">
                        <div class="input-group-append">
                            <button class="btn btn-outline-secondary" id="rad_btn_get_cdr" data-i18n="rad.edit.cdrLookup">CDR</button>
                        </div>
                    </div>
                    <div id="rad_substring_mode" class="rad-substring-panel mb-2">
                        <label><i class="fas fa-exchange-alt mr-1"></i><span data-i18n="rad.edit.substringReplace">Substring Replace</span></label>
                        <div class="input-group input-group-sm mb-1">
                            <div class="input-group-prepend"><span class="input-group-text rad-prepend-label" data-i18n="rad.edit.find">Find</span></div>
                            <input type="text" id="rad_find" class="form-control rad-mono-input" placeholder="e.g., J60 PHILA">
                        </div>
                        <div class="input-group input-group-sm mb-1">
                            <div class="input-group-prepend"><span class="input-group-text rad-prepend-label" data-i18n="rad.edit.replace">Replace</span></div>
                            <input type="text" id="rad_replace" class="form-control rad-mono-input" placeholder="e.g., J80 BRIGS">
                        </div>
                        <button class="btn btn-sm btn-outline-primary" id="rad_btn_apply_substr"><i class="fas fa-sync-alt mr-1"></i><span data-i18n="rad.edit.applyToSelected">Apply to Selected</span></button>
                    </div>
                    <label data-i18n="rad.edit.addRoute">Add Route</label>
                    <textarea id="rad_manual_route" class="form-control form-control-sm mb-2" rows="3" placeholder="Enter route string..."></textarea>
                    <div class="d-flex align-items-center mb-2">
                        <button class="btn btn-sm btn-info mr-1" id="rad_btn_validate" data-i18n="common.validate">Validate</button>
                        <button class="btn btn-sm btn-primary mr-1" id="rad_btn_plot" data-i18n="rad.edit.plot">Plot</button>
                        <span class="rad-color-label mr-1" data-i18n="rad.edit.color">Color:</span>
                        <div class="rad-color-palette" id="rad_color_palette">
                            <div class="rad-color-swatch active" data-color="#4ECDC4" style="background:#4ECDC4;" title="Teal"></div>
                            <div class="rad-color-swatch" data-color="#FF6B6B" style="background:#FF6B6B;" title="Red"></div>
                            <div class="rad-color-swatch" data-color="#FFD93D" style="background:#FFD93D;" title="Yellow"></div>
                            <div class="rad-color-swatch" data-color="#9B59B6" style="background:#9B59B6;" title="Purple"></div>
                            <div class="rad-color-swatch" data-color="#E94560" style="background:#E94560;" title="Accent"></div>
                            <div class="rad-color-swatch" data-color="#FFFFFF" style="background:#FFFFFF;" title="White"></div>
                        </div>
                        <input type="hidden" id="rad_route_color" value="#4ECDC4">
                    </div>
                </div>
                <!-- Right: Current Routes + Create Amendment -->
                <div class="col-md-7 rad-edit-right">
                    <label data-i18n="rad.edit.currentRoutes">Current Routes</label>
                    <div id="rad_current_routes" class="mb-2"></div>
                    <label data-i18n="rad.edit.amendmentPreview">Amendment Preview</label>
                    <div id="rad_amendment_preview" class="rad-amendment-preview mb-2"></div>
                    <label data-i18n="rad.clearance.builder">Clearance Builder</label>
                    <div id="rad_clearance_builder" class="mb-2"></div>
                    <div class="mb-2">
                        <label data-i18n="rad.edit.tmiAssociation">TMI Association</label>
                        <select id="rad_tmi_assoc" class="form-control form-control-sm"></select>
                    </div>
                    <div class="mb-2">
                        <label data-i18n="rad.edit.deliveryChannels">Delivery Channels</label>
                        <div class="form-check form-check-inline">
                            <input type="checkbox" class="form-check-input" id="rad_ch_cpdlc" checked>
                            <label class="form-check-label" for="rad_ch_cpdlc">CPDLC</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input type="checkbox" class="form-check-input" id="rad_ch_swim" checked>
                            <label class="form-check-label" for="rad_ch_swim">SWIM</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input type="checkbox" class="form-check-input" id="rad_ch_discord">
                            <label class="form-check-label" for="rad_ch_discord">Discord</label>
                        </div>
                    </div>
                    <div class="d-flex">
                        <button class="btn btn-sm btn-outline-success mr-1" id="rad_btn_save_draft" data-i18n="rad.edit.saveDraft">Save Draft</button>
                        <button class="btn btn-sm btn-success" id="rad_btn_send_amendment" data-i18n="rad.edit.sendAmendment">Send Amendment</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="pane-monitoring" role="tabpanel">
            <div id="rad_summary_cards" class="d-flex flex-wrap mt-2 mb-2"></div>
            <div id="rad_aggregate_bar" class="rad-aggregate-bar mb-2"></div>
            <div class="d-flex align-items-center mb-2">
                <div class="btn-group btn-group-sm mr-3">
                    <button class="btn btn-outline-secondary active" id="rad_filter_all" data-i18n="common.all">All</button>
                    <button class="btn btn-outline-secondary" id="rad_filter_pending" data-i18n="rad.monitoring.pending">Pending</button>
                    <button class="btn btn-outline-secondary" id="rad_filter_noncompliant" data-i18n="rad.monitoring.nonCompliant">Non-Compliant</button>
                    <button class="btn btn-outline-secondary" id="rad_filter_alerts" data-i18n="rad.monitoring.alerts">Alerts</button>
                </div>
                <select id="rad_tmi_filter" class="form-control form-control-sm rad-tmi-filter"></select>
                <div class="rad-batch-actions ml-auto" id="rad_batch_bar" style="display:none;">
                    <button class="btn btn-sm btn-outline-success mr-1" id="rad_batch_send" style="display:none;"><i class="fas fa-paper-plane mr-1"></i><span></span></button>
                    <button class="btn btn-sm btn-outline-info mr-1" id="rad_batch_issue" style="display:none;"><i class="fas fa-check-circle mr-1"></i><span></span></button>
                    <button class="btn btn-sm btn-outline-success mr-1" id="rad_batch_accept" style="display:none;"><i class="fas fa-thumbs-up mr-1"></i><span></span></button>
                    <button class="btn btn-sm btn-outline-warning mr-1" id="rad_batch_reject" style="display:none;"><i class="fas fa-thumbs-down mr-1"></i><span></span></button>
                    <button class="btn btn-sm btn-outline-danger" id="rad_batch_delete"><i class="fas fa-trash mr-1"></i><span></span></button>
                </div>
            </div>
            <table class="table table-sm rad-table" id="rad_monitoring_table">
                <thead><tr>
                    <th style="width:30px;"><input type="checkbox" id="rad_select_all" title="Select all"></th>
                    <th data-i18n="rad.search.callsign">Callsign</th>
                    <th data-i18n="rad.search.origDest">O/D</th>
                    <th data-i18n="rad.search.status">Status</th>
                    <th>RRSTAT</th>
                    <th>TMI</th>
                    <th data-i18n="rad.monitoring.assigned">Assigned</th>
                    <th data-i18n="rad.monitoring.filed">Filed</th>
                    <th data-i18n="rad.monitoring.delta">Delta</th>
                    <th data-i18n="rad.monitoring.sent">Sent</th>
                    <th data-i18n="rad.monitoring.delivery">Delivery</th>
                    <th data-i18n="common.actions">Actions</th>
                </tr></thead>
                <tbody id="rad_monitoring_tbody"></tbody>
            </table>
        </div>
    </div>
</div>
</div><!-- /.rad-app -->

<?php include('load/footer.php'); ?>
<!-- Map rendering pipeline (same as route.php) -->
<script src="assets/js/config/phase-colors.js<?= _v('assets/js/config/phase-colors.js') ?>"></script>
<script src="assets/js/config/filter-colors.js<?= _v('assets/js/config/filter-colors.js') ?>"></script>
<script src="assets/js/awys.js<?= _v('assets/js/awys.js') ?>"></script>
<script src="assets/js/procs_enhanced.js<?= _v('assets/js/procs_enhanced.js') ?>"></script>
<script src="assets/js/route-symbology.js<?= _v('assets/js/route-symbology.js') ?>"></script>
<script src="assets/js/route-maplibre.js<?= _v('assets/js/route-maplibre.js') ?>"></script>
<script src="assets/js/playbook-cdr-search.js<?= _v('assets/js/playbook-cdr-search.js') ?>"></script>
<!-- RAD modules -->
<script src="assets/js/rad-event-bus.js<?= _v('assets/js/rad-event-bus.js') ?>"></script>
<script src="assets/js/rad-role.js<?= _v('assets/js/rad-role.js') ?>"></script>
<script src="assets/js/rad-flight-search.js<?= _v('assets/js/rad-flight-search.js') ?>"></script>
<script src="assets/js/rad-flight-detail.js<?= _v('assets/js/rad-flight-detail.js') ?>"></script>
<script src="assets/js/rad-amendment.js<?= _v('assets/js/rad-amendment.js') ?>"></script>
<script src="assets/js/rad-clearance-builder.js<?= _v('assets/js/rad-clearance-builder.js') ?>"></script>
<script src="assets/js/rad-tos.js<?= _v('assets/js/rad-tos.js') ?>"></script>
<script src="assets/js/rad-monitoring.js<?= _v('assets/js/rad-monitoring.js') ?>"></script>
<script src="assets/js/rad.js<?= _v('assets/js/rad.js') ?>"></script>
</body>
</html>
