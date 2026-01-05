<?php /* route.php - merged groups_v4 + updated_v3 (header comment added) */ ?>


<!DOCTYPE html>
<html>

<head>

    <!-- Import CSS -->
    <?php
        include("load/header.php");
    ?>

    <!-- Leaflet --> 
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script src='https://cdnjs.cloudflare.com/ajax/libs/leaflet-omnivore/0.3.4/leaflet-omnivore.min.js'></script>
    <script src='assets/js/leaflet.textpath.js'></script>
    <script src="https://cdn.jsdelivr.net/npm/leaflet.geodesic"></script>

    <style>
        .Incon {
            background: none;
            color: #fbff00;
            border: none;
            font-size: 1rem;
            font-family: Inconsolata;
        }

        /* Make the Plot Routes textarea monospace */
        #routeSearch {
            font-family: Inconsolata, monospace;
            font-size: 0.9rem;
        }

        /* Advisory builder: Facilities Included dropdown */
        .adv-facilities-wrapper {
            position: relative;
        }

        .adv-facilities-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            z-index: 1050;
            display: none;
            background-color: #ffffff;
            border: 1px solid rgba(0, 0, 0, 0.15);
            border-radius: 0.25rem;
            padding: 0.5rem;
            max-height: 260px;
            overflow-y: auto;
            min-width: 260px;
            box-shadow: 0 0.25rem 0.5rem rgba(0,0,0,0.15);
        }

        .adv-facilities-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            grid-column-gap: 0.75rem;
            grid-row-gap: 0.25rem;
        }

        .adv-facilities-grid .form-check {
            margin-bottom: 0.1rem;
        }

        .adv-facilities-grid label {
            font-size: 0.75rem;
            margin-bottom: 0;
        }

        /* ═══════════════════════════════════════════════════════════════════
           ADL LIVE FLIGHTS - TSD SYMBOLOGY STYLES
           ═══════════════════════════════════════════════════════════════════ */
        
        /* Controls bar styling - light theme */
        .adl-controls {
            background: #fff;
            padding: 6px 12px;
            border-radius: 6px;
            border: 1px solid #dee2e6;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .adl-controls .custom-switch {
            padding-left: 2.5rem;
        }
        .adl-controls .custom-control-label {
            cursor: pointer;
        }
        
        /* Status badge states */
        #adl_status_badge {
            font-size: 0.75rem;
            padding: 4px 10px;
            transition: all 0.3s ease;
            letter-spacing: 0.5px;
        }
        #adl_status_badge.live {
            background-color: #28a745 !important;
            animation: adl-pulse 2s infinite;
        }
        @keyframes adl-pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.8; }
        }

        /* Filter button */
        #adl_filter_toggle {
            padding: 4px 8px;
        }
        #adl_filter_toggle:disabled {
            opacity: 0.5;
        }

        /* Refresh status */
        #adl_refresh_status {
            color: #28a745;
            font-size: 0.7rem;
            font-family: 'Inconsolata', monospace;
        }

        /* Filter panel - floating over map, light theme */
        #adl_filter_panel {
            position: absolute;
            top: 10px;
            right: 10px;
            z-index: 1000;
            background: #fff;
            border: 1px solid #ccc;
            border-radius: 8px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.2);
            width: 320px;
        }
        #adl_filter_panel .card-body {
            padding: 12px !important;
        }
        #adl_filter_panel label {
            color: #555;
            font-size: 0.7rem;
            font-weight: 600;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
            display: block;
        }
        #adl_filter_panel .form-control-sm {
            font-size: 0.8rem;
            padding: 4px 8px;
            background: #fff;
            border: 1px solid #ccc;
            color: #333;
        }
        #adl_filter_panel .form-control-sm:focus {
            border-color: #239BCD;
            box-shadow: 0 0 0 2px rgba(35, 155, 205, 0.2);
        }
        #adl_filter_panel .form-control-sm::placeholder {
            color: #999;
        }
        #adl_filter_panel .custom-control-label {
            color: #333;
            font-size: 0.8rem;
            font-weight: normal;
        }
        #adl_filter_panel .border-top {
            border-color: #ddd !important;
        }
        #adl_filter_panel .text-muted {
            color: #666 !important;
        }
        #adl_filter_panel .close {
            color: #666;
            opacity: 0.7;
        }
        #adl_filter_panel .close:hover {
            color: #333;
            opacity: 1;
        }
        #adl_filter_panel .text-light {
            color: #333 !important;
        }

        /* TSD Aircraft Icon Styles - inline preview icons */
        .adl-icon-jumbo::before,
        .adl-icon-heavy::before,
        .adl-icon-jet::before,
        .adl-icon-prop::before {
            display: inline-block;
            width: 12px;
            height: 12px;
            content: '';
            background-size: contain;
            background-repeat: no-repeat;
            vertical-align: middle;
            margin-right: 2px;
        }
        .adl-icon-jumbo::before {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath fill='%2317a2b8' d='M21,16V14L13,9V3.5A1.5,1.5 0 0,0 11.5,2A1.5,1.5 0 0,0 10,3.5V9L2,14V16L10,13.5V19L8,20.5V22L11.5,21L15,22V20.5L13,19V13.5L21,16Z'/%3E%3C/svg%3E");
        }
        .adl-icon-heavy::before {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath fill='%2320c997' d='M21,16V14L13,9V3.5A1.5,1.5 0 0,0 11.5,2A1.5,1.5 0 0,0 10,3.5V9L2,14V16L10,13.5V19L8,20.5V22L11.5,21L15,22V20.5L13,19V13.5L21,16Z'/%3E%3C/svg%3E");
        }
        .adl-icon-jet::before {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath fill='%23555' d='M21,16V14L13,9V3.5A1.5,1.5 0 0,0 11.5,2A1.5,1.5 0 0,0 10,3.5V9L2,14V16L10,13.5V19L8,20.5V22L11.5,21L15,22V20.5L13,19V13.5L21,16Z'/%3E%3C/svg%3E");
        }
        .adl-icon-prop::before {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath fill='%23e6a800' d='M21,16V14L13,9V3.5A1.5,1.5 0 0,0 11.5,2A1.5,1.5 0 0,0 10,3.5V9L2,14V16L10,13.5V19L8,20.5V22L11.5,21L15,22V20.5L13,19V13.5L21,16Z'/%3E%3C/svg%3E");
        }

        /* Leaflet TSD marker icons */
        .adl-flight-icon {
            transition: transform 0.15s ease;
        }
        .adl-flight-icon:hover {
            transform: scale(1.4);
            z-index: 10000 !important;
        }
        .adl-flight-icon.tracked {
            filter: drop-shadow(0 0 6px #fff) drop-shadow(0 0 10px #239BCD);
            z-index: 9999 !important;
        }

        /* Flight popup styling */
        .leaflet-popup-content-wrapper {
            border-radius: 8px;
        }
        .adl-popup {
            font-family: 'Inconsolata', 'Consolas', monospace;
            font-size: 0.85rem;
            min-width: 200px;
        }
        .adl-popup .callsign {
            font-size: 1.1rem;
            font-weight: bold;
            color: #239BCD;
            border-bottom: 2px solid #239BCD;
            padding-bottom: 6px;
            margin-bottom: 8px;
        }
        .adl-popup .route {
            font-size: 1rem;
            color: #333;
            margin-bottom: 8px;
        }
        .adl-popup .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 3px 0;
            border-bottom: 1px dotted #ddd;
        }
        .adl-popup .detail-row:last-child {
            border-bottom: none;
        }
        .adl-popup .detail-label {
            color: #888;
            font-size: 0.7rem;
            text-transform: uppercase;
        }
        .adl-popup .detail-value {
            font-weight: 600;
            color: #333;
        }

        /* Context menu styling - light theme */
        .adl-context-menu {
            position: fixed;
            z-index: 10001;
            background: #fff;
            border: 1px solid #ccc;
            border-radius: 6px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.2);
            min-width: 160px;
            padding: 4px 0;
            font-size: 0.85rem;
        }
        .adl-context-menu .menu-header {
            padding: 8px 12px;
            font-weight: bold;
            color: #239BCD;
            background: #f5f5f5;
            border-bottom: 1px solid #ddd;
            font-family: 'Inconsolata', monospace;
            font-size: 1rem;
        }
        .adl-context-menu .menu-item {
            padding: 8px 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            color: #333;
            transition: background 0.15s;
        }
        .adl-context-menu .menu-item:hover {
            background: #e9f5fb;
            color: #239BCD;
        }
        .adl-context-menu .menu-item i {
            width: 18px;
            margin-right: 8px;
            color: #888;
        }
        .adl-context-menu .menu-item:hover i {
            color: #239BCD;
        }
        .adl-context-menu .menu-divider {
            height: 1px;
            background: #eee;
            margin: 4px 0;
        }

        /* Flight detail modal table */
        .adl-detail-table {
            font-size: 0.85rem;
        }
        .adl-detail-table th {
            width: 35%;
            font-weight: 500;
            color: #666;
            text-transform: uppercase;
            font-size: 0.65rem;
            letter-spacing: 0.5px;
            padding: 6px 8px;
        }
        .adl-detail-table td {
            font-family: 'Inconsolata', monospace;
            padding: 6px 8px;
            color: #333;
        }
        .route-string {
            font-family: 'Inconsolata', monospace;
            font-size: 0.75rem;
            word-break: break-all;
            max-height: 80px;
            overflow-y: auto;
            background: #f8f9fa;
            padding: 8px;
            border-radius: 4px;
            color: #333;
        }
    </style>
</head>

<body>

<?php
include('load/nav.php');
?>

    <section class="d-flex align-items-center position-relative bg-position-center overflow-hidden pt-6 jarallax bg-dark text-light" style="min-height: 250px" data-jarallax data-speed="0.3" style="pointer-events: all;">
        <div class="container-fluid pt-2 pb-5 py-lg-6">
            <img class="jarallax-img" src="assets/img/jumbotron/main.png" alt="" style="opacity: 50%; height: 100vh;">
        </div>       
    </section>


    <div class="container-fluid mt-5">
        <center>
            <div class="row mb-5">
                <div class="col-4">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <h4 class="mb-0">Plot Routes</h4>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="routeHelpToggle">
                            Show Help
                        </button>
                    </div>

                    <!-- Collapsible Plot Routes help (default: collapsed) -->
                    <div id="routeHelpPanel" class="text-left small mb-2" style="display: none;">
                        <ul class="pl-3 mb-2">
                            <li>
                                To utilize <strong>multiple</strong> routes, space each route individually by line
                                (press ENTER to create a new line).
                            </li>
                            <li>
                                To color-code routes add a semi-colon (<code>;</code>) to the END of the route
                                followed by either a hex (e.g., <code>#fff</code>) or the name of a standard color
                                (e.g., <code>blue</code>).
                            </li>
                            <li>
                                To use Playbook routes, use the following syntax:
                                <code>PB.play_name.orig_group.dest_group</code> where
                                <ul class="pl-3 mb-1">
                                    <li><code>play_name</code> is the name of the play</li>
                                    <li><code>orig_group</code> is the (list of) origin airport(s), separated by spaces</li>
                                    <li><code>dest_group</code> is the (list of) destination airport(s), separated by spaces</li>
                                </ul>
                            </li>
                            <li>
                                To mark a route segment as mandatory, enclose the segment in
                                <code>&gt;&lt;</code>. Multiple disjoint mandatory segments are allowed.
                            </li>
                            <li>
                                To use Coded Departure Routes (CDRs), just type the CDR code
                                (e.g., <code>ACKMKEN0</code>).
                            </li>
                            <li>
                                Multiple origins/destinations are allowed using space-delimited lists
                                (e.g., <code>KDFW KDAL ZHU [route string] KIAD KDCA KBWI</code>).
                            </li>
                            <li class="mb-1">
                                To handle many routes at once, you can create route groups using the following syntax:
                                <pre class="bg-light p-2 mb-1" style="font-family: Inconsolata, monospace; font-size: 0.8rem;">
<code>[ROUTE GROUP NAME 1]
ROUTE1
ROUTE2

[ROUTE GROUP NAME 2]
ROUTE3
ROUTE4
ROUTE5</code></pre>
                                <ul class="pl-3 mb-0">
                                    <li>
                                        Add <code>&gt;&lt;</code> and <code>;[color]</code> modifiers to the
                                        <code>[ROUTE GROUP]</code> to apply modifications to the
                                        <strong>entire</strong> group (e.g.,
                                        <code>&gt;[NORTHEAST]&lt;;white</code>).
                                    </li>
                                </ul>
                            </li>
                        </ul>
                    </div>

                    <textarea class="form-control" name="routeSearch" id="routeSearch" rows="20"></textarea>

                    <br>
                    <button class="btn btn-success" id="plot_r"><i class="fas fa-pencil"></i> Plot</button>
                    <button class="btn btn-info" id="plot_c"><i class="far fa-copy"></i> Copy</button>
                    <button class="btn btn-secondary" id="toggle_labels" type="button" onclick="toggleAllLabels();">
                        <i class="fas fa-tags"></i> Toggle Labels
                    </button>

                    <hr>

                    <button class="btn btn-sm btn-primary" id="export_ls"><i class="fas fa-file-export"></i> Export LS</button>
                    <button class="btn btn-sm btn-primary" id="export_mp"><i class="fas fa-file-export"></i> Export MP</button>
                </div>
                <div class="col-8 text-left">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <span class="small text-muted mr-2">Filter Airways:</span>
                            <div class="text-left input-group" style="width: 280px; display: inline-flex;">
                                <input type="text" name="filter" id="filter" class="form-control form-control-sm" placeholder="Separate by Space">
                                <div class="input-group-append">
                                    <button class="btn btn-outline-danger btn-sm" type="button" id="filter_c"><i class="fas fa-times"></i></button>
                                </div>
                            </div>
                        </div>
                        <!-- ADL Live Flights Toggle -->
                        <div class="adl-controls d-flex align-items-center">
                            <div class="custom-control custom-switch mr-3">
                                <input type="checkbox" class="custom-control-input" id="adl_toggle">
                                <label class="custom-control-label" for="adl_toggle">
                                    <span class="badge badge-dark" id="adl_status_badge">
                                        <i class="fas fa-plane mr-1"></i> Live Flights
                                    </span>
                                </label>
                            </div>
                            <button class="btn btn-sm btn-outline-info" id="adl_filter_toggle" disabled title="Configure filters">
                                <i class="fas fa-filter"></i>
                            </button>
                            <span class="small text-muted ml-2" id="adl_refresh_status"></span>
                        </div>
                    </div>

                    <div id="placeholder"></div>
                    <div id="graphic" style="height: 750px; position: relative;">
                        <!-- ADL Filter Panel (floats over map) -->
                        <div id="adl_filter_panel" style="display: none;">
                            <div class="card-body p-2">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="small text-uppercase font-weight-bold" style="letter-spacing: 1px; color: #239BCD;">
                                        <i class="fas fa-filter mr-1"></i> Flight Filters
                                    </span>
                                    <button type="button" class="close" id="adl_filter_close" style="font-size: 1rem;">
                                        <span>&times;</span>
                                    </button>
                                </div>
                                <div class="mb-2">
                                    <label class="small mb-1 text-uppercase">Aircraft Type</label>
                                    <div class="d-flex flex-wrap">
                                        <div class="custom-control custom-checkbox mr-3">
                                            <input type="checkbox" class="custom-control-input adl-weight-filter" id="adl_wc_super" value="SUPER" checked>
                                            <label class="custom-control-label small" for="adl_wc_super">
                                                <span class="adl-icon-jumbo"></span> Jumbo
                                            </label>
                                        </div>
                                        <div class="custom-control custom-checkbox mr-3">
                                            <input type="checkbox" class="custom-control-input adl-weight-filter" id="adl_wc_heavy" value="HEAVY" checked>
                                            <label class="custom-control-label small" for="adl_wc_heavy">
                                                <span class="adl-icon-heavy"></span> Heavy
                                            </label>
                                        </div>
                                        <div class="custom-control custom-checkbox mr-3">
                                            <input type="checkbox" class="custom-control-input adl-weight-filter" id="adl_wc_large" value="LARGE" checked>
                                            <label class="custom-control-label small" for="adl_wc_large">
                                                <span class="adl-icon-jet"></span> Jet
                                            </label>
                                        </div>
                                        <div class="custom-control custom-checkbox">
                                            <input type="checkbox" class="custom-control-input adl-weight-filter" id="adl_wc_small" value="SMALL" checked>
                                            <label class="custom-control-label small" for="adl_wc_small">
                                                <span class="adl-icon-prop"></span> Prop
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                <div class="mb-2">
                                    <label class="small mb-1 text-uppercase">Origin / Destination</label>
                                    <div class="d-flex">
                                        <input type="text" class="form-control form-control-sm adl-filter-input mr-2" id="adl_origin" placeholder="Origin" title="ARTCC (ZNY) or Airport (KJFK)" style="width: 100px;">
                                        <span class="text-muted align-self-center mx-1">→</span>
                                        <input type="text" class="form-control form-control-sm adl-filter-input" id="adl_dest" placeholder="Dest" title="ARTCC (ZMA) or Airport (KMIA)" style="width: 100px;">
                                    </div>
                                </div>
                                <div class="mb-2">
                                    <label class="small mb-1 text-uppercase">Carrier / Altitude</label>
                                    <div class="d-flex align-items-center">
                                        <input type="text" class="form-control form-control-sm adl-filter-input mr-2" id="adl_carrier" placeholder="Carrier" title="e.g., AAL, UAL" style="width: 70px;">
                                        <span class="text-muted small mr-1">FL</span>
                                        <input type="number" class="form-control form-control-sm adl-filter-input mr-1" id="adl_alt_min" placeholder="Min" style="width: 55px;">
                                        <span class="text-muted">-</span>
                                        <input type="number" class="form-control form-control-sm adl-filter-input ml-1" id="adl_alt_max" placeholder="Max" style="width: 55px;">
                                    </div>
                                </div>
                                <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                                    <small class="text-muted">
                                        <span id="adl_stats_display"><strong>0</strong> shown</span> / 
                                        <span id="adl_stats_total"><strong>0</strong> total</span>
                                    </small>
                                    <div>
                                        <button class="btn btn-sm btn-outline-secondary mr-1" id="adl_filter_clear" title="Clear all filters">
                                            <i class="fas fa-eraser"></i>
                                        </button>
                                        <button class="btn btn-sm btn-info" id="adl_filter_apply" title="Apply filters">
                                            <i class="fas fa-check"></i> Apply
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </center>
    </div>

    <!-- Reroute Advisory Builder (collapsible, below routes + map) -->
    <div class="container-fluid mt-4 mb-4">
        <div class="card bg-light text-dark">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-file-alt mr-2"></i> Reroute Advisory Builder</span>
                <button type="button" class="btn btn-sm btn-outline-dark" id="adv_panel_toggle">
                    Show
                </button>
            </div>

            <div class="card-body" id="adv_panel_body" style="display: none;">
                <p class="small text-muted mb-2">
                    Builds a vATCSCC-style <code>ROUTE RQD</code> advisory from the routes in the
                    <code>Plot Routes</code> box above (including <code>PB.*</code> playbooks and CDR codes).
                </p>

                <!-- Basic advisory metadata -->
                <div class="form-row">
                    <div class="form-group col-md-2 col-sm-4">
                        <label class="small mb-0" for="advNumber">Adv #</label>
                        <input type="text" class="form-control form-control-sm" id="advNumber" placeholder="001">
                    </div>
                    <div class="form-group col-md-2 col-sm-4">
                        <label class="small mb-0" for="advFacility">Facility</label>
                        <input type="text" class="form-control form-control-sm" id="advFacility" value="DCC">
                    </div>
                    <div class="form-group col-md-3 col-sm-4">
                        <label class="small mb-0" for="advDate">Date</label>
                        <input type="text" class="form-control form-control-sm" id="advDate" placeholder="MM/DD/YYYY">
                    </div>
                    <div class="form-group col-md-5 col-sm-8">
                        <label class="small mb-0" for="advAction">Type / Action</label>
                        <input type="text" class="form-control form-control-sm" id="advAction" value="ROUTE RQD">
                    </div>
                </div>

                <!-- Name / constrained area / reason -->
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label class="small mb-0" for="advName">Name</label>
                        <input type="text" class="form-control form-control-sm" id="advName" placeholder="NE_TO_FLORIDA">
                    </div>
                    <div class="form-group col-md-4">
                        <label class="small mb-0" for="advConstrainedArea">Constrained Area</label>
                        <input type="text" class="form-control form-control-sm" id="advConstrainedArea" placeholder="ZJX/ZMA">
                    </div>
                    <div class="form-group col-md-4">
                        <label class="small mb-0" for="advReason">Reason</label>
                        <input type="text" class="form-control form-control-sm" id="advReason" placeholder="VOLUME:VOLUME">
                    </div>
                </div>


                <div class="form-group">
                    <label class="small mb-0" for="advIncludeTraffic">Include Traffic</label>
                    <input type="text" class="form-control form-control-sm" id="advIncludeTraffic" placeholder="ZBW/ZDC/ZNY DEPARTURES TO ZJX/ZMA">
                </div>

                <div class="form-group">
                    <label class="small mb-0" for="advFacilities">Facilities Included</label>
                    <div class="adv-facilities-wrapper">
                        <div class="input-group input-group-sm">
                            <input type="text"
                                   class="form-control form-control-sm"
                                   id="advFacilities"
                                   placeholder="ZBW/ZDC/ZJX/ZMA/ZNY">
                            <div class="input-group-append">
                                <button class="btn btn-outline-secondary" type="button" id="advFacilitiesToggle">
                                    Select
                                </button>
                            </div>
                        </div>
                        <div id="advFacilitiesDropdown" class="adv-facilities-dropdown">
                            <div class="adv-facilities-grid" id="advFacilitiesGrid">
                                <!-- Populated by route_bu.js -->
                            </div>
                            <div class="mt-2 d-flex justify-content-between align-items-center">
                                <div>
                                    <button type="button" class="btn btn-sm btn-light mr-2" id="advFacilitiesSelectAll">
                                        Select All
                                    </button>
                                    <button type="button" class="btn btn-sm btn-light" id="advFacilitiesSelectUs">
                                        Select All US
                                    </button>
                                </div>
                                <div>
                                    <button type="button" class="btn btn-sm btn-primary mr-2" id="advFacilitiesApply">Apply</button>
                                    <button type="button" class="btn btn-sm btn-light" id="advFacilitiesClear">Clear</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group col-md-3 col-sm-6">
                        <label class="small mb-0 d-block">Flight Status</label>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="advFlightStatus" id="advFltAll" value="ALL FLIGHTS" checked>
                            <label class="form-check-label small" for="advFltAll">All</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="advFlightStatus" id="advFltAirborne" value="AIRBORNE">
                            <label class="form-check-label small" for="advFltAirborne">Airborne</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="advFlightStatus" id="advFltNotAirborne" value="NOT AIRBORNE">
                            <label class="form-check-label small" for="advFltNotAirborne">Not Airborne</label>
                        </div>
                    </div>
                    <div class="form-group col-md-3 col-sm-6">
                        <label class="small mb-0 d-block">Identify Flights Based On</label>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="advTimeBasis" id="advTimeEtd" value="ETD" checked>
                            <label class="form-check-label small" for="advTimeEtd">ETD</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="advTimeBasis" id="advTimeEta" value="ETA">
                            <label class="form-check-label small" for="advTimeEta">ETA</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="advTimeBasis" id="advTimeEntry" value="ENTRY">
                            <label class="form-check-label small" for="advTimeEntry">Entry Time</label>
                        </div>
                    </div>
                    <div class="form-group col-md-2 col-sm-4">
                        <label class="small mb-0" for="advValidStart">Valid From (DDHHMM)</label>
                        <input type="text" class="form-control form-control-sm" id="advValidStart" placeholder="DDHHMM">
                    </div>
                    <div class="form-group col-md-2 col-sm-4">
                        <label class="small mb-0" for="advValidEnd">Valid To (DDHHMM)</label>
                        <input type="text" class="form-control form-control-sm" id="advValidEnd" placeholder="DDHHMM">
                    </div>
                    <div class="form-group col-md-2 col-sm-4">
                        <label class="small mb-0" for="advProb">Prob. of Extension</label>
                        <input type="text" class="form-control form-control-sm" id="advProb" placeholder="LOW">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label class="small mb-0" for="advRemarks">Remarks</label>
                        <textarea class="form-control form-control-sm" id="advRemarks" rows="2"></textarea>
                    </div>
                    <div class="form-group col-md-6">
                        <label class="small mb-0" for="advRestrictions">Associated Restrictions</label>
                        <textarea class="form-control form-control-sm" id="advRestrictions" rows="2"></textarea>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label class="small mb-0" for="advMods">Modifications</label>
                        <textarea class="form-control form-control-sm" id="advMods" rows="2"></textarea>
                    </div>
                    <div class="form-group col-md-3">
                        <label class="small mb-0" for="advTmiId">TMI ID</label>
                        <input type="text" class="form-control form-control-sm" id="advTmiId" placeholder="RRDCC001">
                    </div>
                    <div class="form-group col-md-3">
                        <label class="small mb-0" for="advEffectiveTime">Effective Time Block</label>
                        <input type="text" class="form-control form-control-sm" id="advEffectiveTime" placeholder="DDHHMM-DDHHMM">
                    </div>
                </div>

                <div class="mb-2">
                    <button type="button" class="btn btn-sm btn-warning mr-2" id="adv_generate">
                        <i class="fas fa-file-alt"></i> Build Advisory
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-dark" id="adv_copy">
                        <i class="far fa-copy"></i> Copy Advisory
                    </button>
                </div>

                <!-- Monospaced, no visual wrapping (wrap="off"), wide textarea -->
                <textarea class="form-control bg-light text-dark"
                          id="advOutput"
                          rows="14"
                          wrap="off"
                          style="font-family: Inconsolata, monospace; font-size: 0.85rem; min-width: 80ch;"></textarea>
            </div>
        </div>
    </div>

<?php include('load/footer.php'); ?>

<!-- ADL Flight Detail Modal -->
<div class="modal fade" id="adlFlightDetailModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header py-2 bg-dark text-white">
                <h6 class="modal-title">
                    <i class="fas fa-plane mr-2"></i>
                    <span id="adl_modal_callsign">Flight Detail</span>
                </h6>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body p-3">
                <div class="row">
                    <!-- Left column: Basic info -->
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless adl-detail-table">
                            <tr><th>CALLSIGN</th><td id="adl_detail_callsign">--</td></tr>
                            <tr><th>ROUTE</th><td id="adl_detail_route">--</td></tr>
                            <tr><th>AIRCRAFT</th><td id="adl_detail_aircraft">--</td></tr>
                            <tr><th>WEIGHT CLASS</th><td id="adl_detail_weight">--</td></tr>
                            <tr><th>CARRIER</th><td id="adl_detail_carrier">--</td></tr>
                            <tr><th>PHASE</th><td id="adl_detail_phase">--</td></tr>
                            <tr><th>STATUS</th><td id="adl_detail_status">--</td></tr>
                        </table>
                    </div>
                    <!-- Right column: Position & Times -->
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless adl-detail-table">
                            <tr><th>POSITION</th><td id="adl_detail_position">--</td></tr>
                            <tr><th>ALTITUDE</th><td id="adl_detail_altitude">--</td></tr>
                            <tr><th>GROUNDSPEED</th><td id="adl_detail_speed">--</td></tr>
                            <tr><th>HEADING</th><td id="adl_detail_heading">--</td></tr>
                            <tr><th>FILED ALT</th><td id="adl_detail_filed_alt">--</td></tr>
                            <tr><th>ETD</th><td id="adl_detail_etd">--</td></tr>
                            <tr><th>ETA</th><td id="adl_detail_eta">--</td></tr>
                        </table>
                    </div>
                </div>
                <!-- Filed Route -->
                <div class="mt-2 p-2 bg-light rounded">
                    <small class="text-uppercase text-muted font-weight-bold">Filed Route</small>
                    <div class="route-string mt-1" id="adl_detail_fp_route">--</div>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-outline-info" id="adl_modal_track">
                    <i class="fas fa-crosshairs mr-1"></i> Track Flight
                </button>
                <button type="button" class="btn btn-sm btn-outline-primary" id="adl_modal_zoom">
                    <i class="fas fa-search-plus mr-1"></i> Zoom To
                </button>
                <button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ADL Context Menu (dynamically positioned) -->
<div id="adl_context_menu" class="adl-context-menu" style="display: none;">
    <div class="menu-header" id="adl_ctx_callsign">AAL123</div>
    <div class="menu-item" data-action="info">
        <i class="fas fa-info-circle"></i> Flight Info
    </div>
    <div class="menu-item" data-action="detail">
        <i class="fas fa-list-alt"></i> Flight Detail
    </div>
    <div class="menu-divider"></div>
    <div class="menu-item" data-action="zoom">
        <i class="fas fa-search-plus"></i> Zoom to Flight
    </div>
    <div class="menu-item" data-action="track">
        <i class="fas fa-crosshairs"></i> Track Flight
    </div>
    <div class="menu-divider"></div>
    <div class="menu-item" data-action="copy">
        <i class="fas fa-copy"></i> Copy Callsign
    </div>
</div>

<!-- Graphical Map Leaflet.js Generation -->
<script src="assets/js/awys.js"></script>
<script src="assets/js/route_bu.js"></script>

<!-- Simple JS to toggle Plot Routes help panel -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    var btn = document.getElementById('routeHelpToggle');
    var panel = document.getElementById('routeHelpPanel');

    if (btn && panel) {
        btn.addEventListener('click', function () {
            var isHidden = (panel.style.display === 'none' || panel.style.display === '');
            panel.style.display = isHidden ? 'block' : 'none';
            btn.textContent = isHidden ? 'Hide Help' : 'Show Help';
        });
    }
});
</script>

</body>
</html>
