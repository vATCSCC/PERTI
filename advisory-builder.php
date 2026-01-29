<?php
/**
 * OPTIMIZED: Public page - no session handler or DB needed
 */
include("load/config.php");
?>
<!DOCTYPE html>
<html lang="en">
<head>

    <?php $page_title = "vATCSCC Advisory Builder"; include("load/header.php"); ?>

    <!-- Info Bar Shared Styles -->
    <link rel="stylesheet" href="assets/css/info-bar.css">

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
        .card-header.bg-danger .tmi-section-title,
        .card-header.bg-dark .tmi-section-title,
        .card-header.text-white .tmi-section-title {
            color: #fff;
        }

        .tmi-advisory-preview {
            white-space: pre;
            font-family: "Inconsolata", monospace;
            font-size: 0.8rem;
            color: #333;
            background: #f8f9fa;
            padding: 15px;
            border-radius: 4px;
            max-height: 400px;
            overflow-y: auto;
        }

        .advisory-type-card {
            cursor: pointer;
            transition: all 0.2s ease;
            border: 2px solid transparent;
        }

        .advisory-type-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        .advisory-type-card.selected {
            border-color: #007bff;
            background: rgba(0, 123, 255, 0.05);
        }

        .advisory-type-icon {
            font-size: 1.5rem;
            margin-bottom: 5px;
        }

        .section-card {
            display: none;
        }

        .section-card.active {
            display: block;
        }

        /* Type-specific header colors */
        .adv-header-gdp { background: #ffc107 !important; color: #333 !important; }
        .adv-header-gs { background: #dc3545 !important; color: #fff !important; }
        .adv-header-afp { background: #17a2b8 !important; color: #fff !important; }
        .adv-header-ctop { background: #6f42c1 !important; color: #fff !important; }
        .adv-header-reroute { background: #28a745 !important; color: #fff !important; }
        .adv-header-atcscc { background: #6c757d !important; color: #fff !important; }
        .adv-header-mit { background: #fd7e14 !important; color: #fff !important; }
        .adv-header-cnx { background: #343a40 !important; color: #fff !important; }

        .scope-select {
            height: 140px;
        }

        .char-count {
            font-size: 0.75rem;
        }

        .char-count.warning { color: #ffc107; }
        .char-count.danger { color: #dc3545; }

        /* Ensure proper text contrast */
        .card-header.bg-warning .tmi-label,
        .card-header.bg-warning .tmi-section-title,
        .card-header.bg-warning small {
            color: #333;
        }

        pre {
            color: #333;
        }
    </style>

</head>

<body>

<?php include("load/nav_public.php"); ?>

<section class="d-flex align-items-center position-relative min-vh-25 py-4" data-jarallax data-speed="0.3" style="pointer-events: all;">
    <div class="container-fluid pt-2 pb-4 py-lg-5">
        <img class="jarallax-img" src="assets/img/jumbotron/main.png" alt="" style="opacity: 50%;">

        <center>
            <h1>Advisory Builder</h1>
            <h4 class="text-white hvr-bob pl-1">
                <i class="fas fa-bullhorn text-info"></i>
                FAA TFMS-Style Advisory Generator
            </h4>
        </center>
    </div>
</section>

<div class="container-fluid mt-3 mb-5">
    <!-- Info Bar: UTC Clock -->
    <div class="perti-info-bar mb-3">
        <div class="row d-flex flex-wrap align-items-stretch" style="gap: 8px; margin: 0 -4px;">
            <!-- Current Time (UTC) -->
            <div class="col-auto px-1">
                <div class="card shadow-sm perti-info-card perti-card-utc h-100">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <div class="perti-info-label">Current UTC</div>
                            <div id="adv_utc_clock" class="perti-clock-display perti-clock-display-lg"></div>
                        </div>
                        <div class="ml-3">
                            <i class="far fa-clock fa-lg text-primary"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Advisory Date Display -->
            <div class="col-auto px-1">
                <div class="card shadow-sm perti-info-card h-100">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <div class="perti-info-label">Advisory Date</div>
                            <div id="adv_date_display" class="perti-clock-display perti-clock-display-md"></div>
                        </div>
                        <div class="ml-3">
                            <i class="far fa-calendar-alt fa-lg text-info"></i>
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
                        <button class="btn btn-sm btn-outline-secondary" onclick="AdvisoryConfig.showConfigModal();" data-toggle="tooltip" title="Switch between US DCC and Canadian NOC advisory formats">
                            <i class="fas fa-globe mr-1"></i> <span id="advisoryOrgDisplay">DCC</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Left Column: Advisory Form -->
        <div class="col-lg-6 mb-4">
            <!-- Advisory Type Selector -->
            <div class="card shadow-sm mb-3">
                <div class="card-header">
                    <span class="tmi-section-title">
                        <i class="fas fa-list-alt mr-1"></i> Select Advisory Type
                    </span>
                </div>
                <div class="card-body">
                    <div class="row">
                        <!-- GDP -->
                        <div class="col-md-3 col-6 mb-2">
                            <div class="card advisory-type-card text-center p-2" data-type="GDP">
                                <div class="advisory-type-icon text-warning"><i class="fas fa-hourglass-half"></i></div>
                                <div class="small font-weight-bold">GDP</div>
                                <div class="text-muted" style="font-size: 0.65rem;">Ground Delay</div>
                            </div>
                        </div>
                        <!-- GS -->
                        <div class="col-md-3 col-6 mb-2">
                            <div class="card advisory-type-card text-center p-2" data-type="GS">
                                <div class="advisory-type-icon text-danger"><i class="fas fa-ban"></i></div>
                                <div class="small font-weight-bold">GS</div>
                                <div class="text-muted" style="font-size: 0.65rem;">Ground Stop</div>
                            </div>
                        </div>
                        <!-- AFP -->
                        <div class="col-md-3 col-6 mb-2">
                            <div class="card advisory-type-card text-center p-2" data-type="AFP">
                                <div class="advisory-type-icon text-info"><i class="fas fa-vector-square"></i></div>
                                <div class="small font-weight-bold">AFP</div>
                                <div class="text-muted" style="font-size: 0.65rem;">Airspace Flow</div>
                            </div>
                        </div>
                        <!-- CTOP -->
                        <div class="col-md-3 col-6 mb-2">
                            <div class="card advisory-type-card text-center p-2" data-type="CTOP">
                                <div class="advisory-type-icon text-purple" style="color: #6f42c1;"><i class="fas fa-route"></i></div>
                                <div class="small font-weight-bold">CTOP</div>
                                <div class="text-muted" style="font-size: 0.65rem;">Trajectory Options</div>
                            </div>
                        </div>
                        <!-- Reroute -->
                        <div class="col-md-3 col-6 mb-2">
                            <div class="card advisory-type-card text-center p-2" data-type="REROUTE">
                                <div class="advisory-type-icon text-success"><i class="fas fa-directions"></i></div>
                                <div class="small font-weight-bold">Reroute</div>
                                <div class="text-muted" style="font-size: 0.65rem;">Route Advisory</div>
                            </div>
                        </div>
                        <!-- Free-Form -->
                        <div class="col-md-3 col-6 mb-2">
                            <div class="card advisory-type-card text-center p-2" data-type="ATCSCC">
                                <div class="advisory-type-icon text-secondary"><i class="fas fa-file-alt"></i></div>
                                <div class="small font-weight-bold">Free-Form</div>
                                <div class="text-muted" style="font-size: 0.65rem;">ATCSCC Advisory</div>
                            </div>
                        </div>
                        <!-- MIT -->
                        <div class="col-md-3 col-6 mb-2">
                            <div class="card advisory-type-card text-center p-2" data-type="MIT">
                                <div class="advisory-type-icon" style="color: #fd7e14;"><i class="fas fa-ruler-horizontal"></i></div>
                                <div class="small font-weight-bold">MIT/MINIT</div>
                                <div class="text-muted" style="font-size: 0.65rem;">Miles-in-Trail</div>
                            </div>
                        </div>
                        <!-- Cancel -->
                        <div class="col-md-3 col-6 mb-2">
                            <div class="card advisory-type-card text-center p-2" data-type="CNX">
                                <div class="advisory-type-icon text-dark"><i class="fas fa-times-circle"></i></div>
                                <div class="small font-weight-bold">Cancel</div>
                                <div class="text-muted" style="font-size: 0.65rem;">CNX Advisory</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Basic Information (Always Visible) -->
            <div class="card shadow-sm mb-3" id="section_basic">
                <div class="card-header" id="section_basic_header">
                    <span class="tmi-section-title">
                        <i class="fas fa-info-circle mr-1"></i> Basic Information
                    </span>
                </div>
                <div class="card-body">
                    <div class="form-row">
                        <div class="form-group col-md-3">
                            <label class="tmi-label mb-0" for="adv_number">Advisory #</label>
                            <input type="text" class="form-control form-control-sm" id="adv_number" placeholder="001">
                        </div>
                        <div class="form-group col-md-3">
                            <label class="tmi-label mb-0" for="adv_facility">Facility</label>
                            <input type="text" class="form-control form-control-sm" id="adv_facility" value="DCC">
                        </div>
                        <div class="form-group col-md-3">
                            <label class="tmi-label mb-0" for="adv_ctl_element">CTL Element</label>
                            <input type="text" class="form-control form-control-sm" id="adv_ctl_element" placeholder="KATL">
                        </div>
                        <div class="form-group col-md-3">
                            <label class="tmi-label mb-0" for="adv_priority">Priority</label>
                            <select class="form-control form-control-sm" id="adv_priority">
                                <option value="1">HIGH</option>
                                <option value="2" selected>NORMAL</option>
                                <option value="3">LOW</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Timing Section -->
            <div class="card shadow-sm mb-3 section-card active" id="section_timing">
                <div class="card-header">
                    <span class="tmi-section-title">
                        <i class="fas fa-clock mr-1"></i> Timing
                    </span>
                </div>
                <div class="card-body">
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label class="tmi-label mb-0" for="adv_start">Start (UTC)</label>
                            <input type="datetime-local" class="form-control form-control-sm" id="adv_start">
                        </div>
                        <div class="form-group col-md-6">
                            <label class="tmi-label mb-0" for="adv_end">End (UTC)</label>
                            <input type="datetime-local" class="form-control form-control-sm" id="adv_end">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="col-12">
                            <small class="text-muted">
                                Effective: <span id="adv_effective_display" class="font-weight-bold text-primary">--/----Z - --/----Z</span>
                            </small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- GDP Section -->
            <div class="card shadow-sm mb-3 section-card" id="section_gdp">
                <div class="card-header adv-header-gdp">
                    <span class="tmi-section-title">
                        <i class="fas fa-hourglass-half mr-1"></i> GDP Configuration
                    </span>
                </div>
                <div class="card-body">
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label class="tmi-label mb-0" for="gdp_rate">Program Rate (/hr)</label>
                            <input type="number" class="form-control form-control-sm" id="gdp_rate" min="1" max="200" placeholder="40">
                        </div>
                        <div class="form-group col-md-4">
                            <label class="tmi-label mb-0" for="gdp_delay_cap">Max Delay (min)</label>
                            <input type="number" class="form-control form-control-sm" id="gdp_delay_cap" min="0" max="999" placeholder="90">
                        </div>
                        <div class="form-group col-md-4">
                            <label class="tmi-label mb-0" for="gdp_reason">Impacting Condition</label>
                            <select class="form-control form-control-sm" id="gdp_reason">
                                <option value="WEATHER">Weather</option>
                                <option value="VOLUME">Volume</option>
                                <option value="RUNWAY">Runway</option>
                                <option value="EQUIPMENT">Equipment</option>
                                <option value="OTHER">Other</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label class="tmi-label mb-0" for="gdp_scope_centers">Scope Centers</label>
                            <select multiple class="form-control form-control-sm scope-select" id="gdp_scope_centers">
                                <optgroup label="Tiers">
                                    <option value="CONUS">CONUS (All)</option>
                                    <option value="TIER1">Tier 1</option>
                                    <option value="TIER2">Tier 2</option>
                                </optgroup>
                                <optgroup label="East">
                                    <option value="ZBW">ZBW - Boston</option>
                                    <option value="ZNY">ZNY - New York</option>
                                    <option value="ZDC">ZDC - Washington</option>
                                    <option value="ZTL">ZTL - Atlanta</option>
                                    <option value="ZJX">ZJX - Jacksonville</option>
                                    <option value="ZMA">ZMA - Miami</option>
                                </optgroup>
                                <optgroup label="Central">
                                    <option value="ZOB">ZOB - Cleveland</option>
                                    <option value="ZID">ZID - Indianapolis</option>
                                    <option value="ZAU">ZAU - Chicago</option>
                                    <option value="ZMP">ZMP - Minneapolis</option>
                                    <option value="ZKC">ZKC - Kansas City</option>
                                    <option value="ZME">ZME - Memphis</option>
                                    <option value="ZFW">ZFW - Fort Worth</option>
                                    <option value="ZHU">ZHU - Houston</option>
                                </optgroup>
                                <optgroup label="West">
                                    <option value="ZDV">ZDV - Denver</option>
                                    <option value="ZAB">ZAB - Albuquerque</option>
                                    <option value="ZLA">ZLA - Los Angeles</option>
                                    <option value="ZOA">ZOA - Oakland</option>
                                    <option value="ZSE">ZSE - Seattle</option>
                                    <option value="ZLC">ZLC - Salt Lake</option>
                                </optgroup>
                            </select>
                            <small class="form-text text-muted">Hold Ctrl/Cmd to select multiple.</small>
                        </div>
                        <div class="form-group col-md-6">
                            <label class="tmi-label mb-0" for="gdp_scope_tiers">Airport Tiers</label>
                            <select multiple class="form-control form-control-sm" id="gdp_scope_tiers" size="4">
                                <option value="CORE30">Core 30</option>
                                <option value="OEP35">OEP 35</option>
                                <option value="ASPM77">ASPM 77</option>
                                <option value="ALL">All Airports</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- GS Section -->
            <div class="card shadow-sm mb-3 section-card" id="section_gs">
                <div class="card-header adv-header-gs">
                    <span class="tmi-section-title">
                        <i class="fas fa-ban mr-1"></i> Ground Stop Configuration
                    </span>
                </div>
                <div class="card-body">
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label class="tmi-label mb-0" for="gs_reason">Reason</label>
                            <select class="form-control form-control-sm" id="gs_reason">
                                <option value="WEATHER">Weather</option>
                                <option value="RUNWAY_CLOSURE">Runway Closure</option>
                                <option value="EQUIPMENT">Equipment</option>
                                <option value="SECURITY">Security</option>
                                <option value="VOLUME">Volume</option>
                                <option value="OTHER">Other</option>
                            </select>
                        </div>
                        <div class="form-group col-md-6">
                            <label class="tmi-label mb-0" for="gs_probability">Probability of Extension</label>
                            <select class="form-control form-control-sm" id="gs_probability">
                                <option value="">None</option>
                                <option value="LOW">LOW</option>
                                <option value="MODERATE">MODERATE</option>
                                <option value="HIGH">HIGH</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label class="tmi-label mb-0" for="gs_scope_centers">Scope Centers</label>
                            <select multiple class="form-control form-control-sm scope-select" id="gs_scope_centers">
                                <option value="CONUS">CONUS (All)</option>
                                <option value="ZBW">ZBW</option>
                                <option value="ZNY">ZNY</option>
                                <option value="ZDC">ZDC</option>
                                <option value="ZTL">ZTL</option>
                                <option value="ZJX">ZJX</option>
                                <option value="ZMA">ZMA</option>
                                <option value="ZOB">ZOB</option>
                                <option value="ZID">ZID</option>
                                <option value="ZAU">ZAU</option>
                                <option value="ZMP">ZMP</option>
                                <option value="ZKC">ZKC</option>
                                <option value="ZME">ZME</option>
                                <option value="ZFW">ZFW</option>
                                <option value="ZHU">ZHU</option>
                                <option value="ZDV">ZDV</option>
                                <option value="ZAB">ZAB</option>
                                <option value="ZLA">ZLA</option>
                                <option value="ZOA">ZOA</option>
                                <option value="ZSE">ZSE</option>
                                <option value="ZLC">ZLC</option>
                            </select>
                        </div>
                        <div class="form-group col-md-6">
                            <label class="tmi-label mb-0" for="gs_dep_airports">Departure Airports (optional)</label>
                            <input type="text" class="form-control form-control-sm" id="gs_dep_airports" placeholder="KJFK KLGA KEWR">
                            <small class="form-text text-muted">Space-separated list</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- AFP Section -->
            <div class="card shadow-sm mb-3 section-card" id="section_afp">
                <div class="card-header adv-header-afp">
                    <span class="tmi-section-title">
                        <i class="fas fa-vector-square mr-1"></i> AFP Configuration
                    </span>
                </div>
                <div class="card-body">
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label class="tmi-label mb-0" for="afp_fca">FCA Name</label>
                            <input type="text" class="form-control form-control-sm" id="afp_fca" placeholder="FCA_XXXX">
                        </div>
                        <div class="form-group col-md-4">
                            <label class="tmi-label mb-0" for="afp_rate">Rate (/hr)</label>
                            <input type="number" class="form-control form-control-sm" id="afp_rate" min="1" max="200" placeholder="30">
                        </div>
                        <div class="form-group col-md-4">
                            <label class="tmi-label mb-0" for="afp_reason">Reason</label>
                            <select class="form-control form-control-sm" id="afp_reason">
                                <option value="WEATHER">Weather</option>
                                <option value="VOLUME">Volume</option>
                                <option value="OTHER">Other</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="tmi-label mb-0" for="afp_scope">Scope Description</label>
                        <input type="text" class="form-control form-control-sm" id="afp_scope" placeholder="Traffic transiting FCA_XXXX">
                    </div>
                </div>
            </div>

            <!-- CTOP Section -->
            <div class="card shadow-sm mb-3 section-card" id="section_ctop">
                <div class="card-header adv-header-ctop">
                    <span class="tmi-section-title">
                        <i class="fas fa-route mr-1"></i> CTOP Configuration
                    </span>
                </div>
                <div class="card-body">
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label class="tmi-label mb-0" for="ctop_name">CTOP Name</label>
                            <input type="text" class="form-control form-control-sm" id="ctop_name" placeholder="CTOP_XXXX">
                        </div>
                        <div class="form-group col-md-6">
                            <label class="tmi-label mb-0" for="ctop_reason">Reason</label>
                            <select class="form-control form-control-sm" id="ctop_reason">
                                <option value="WEATHER">Weather</option>
                                <option value="VOLUME">Volume</option>
                                <option value="OTHER">Other</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="tmi-label mb-0" for="ctop_fcas">FCAs (comma-separated)</label>
                        <input type="text" class="form-control form-control-sm" id="ctop_fcas" placeholder="FCA_1, FCA_2, FCA_3">
                    </div>
                    <div class="form-group">
                        <label class="tmi-label mb-0" for="ctop_caps">Caps (comma-separated, matching FCAs)</label>
                        <input type="text" class="form-control form-control-sm" id="ctop_caps" placeholder="30, 40, 35">
                    </div>
                </div>
            </div>

            <!-- Reroute Section -->
            <div class="card shadow-sm mb-3 section-card" id="section_reroute">
                <div class="card-header adv-header-reroute">
                    <span class="tmi-section-title">
                        <i class="fas fa-directions mr-1"></i> Reroute Configuration
                    </span>
                </div>
                <div class="card-body">
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label class="tmi-label mb-0" for="reroute_name">Route Name</label>
                            <input type="text" class="form-control form-control-sm" id="reroute_name" placeholder="GOLDDR">
                        </div>
                        <div class="form-group col-md-4">
                            <label class="tmi-label mb-0" for="reroute_area">Constrained Area</label>
                            <input type="text" class="form-control form-control-sm" id="reroute_area" placeholder="ZNY">
                        </div>
                        <div class="form-group col-md-4">
                            <label class="tmi-label mb-0" for="reroute_reason">Reason</label>
                            <select class="form-control form-control-sm" id="reroute_reason">
                                <option value="WEATHER">Weather</option>
                                <option value="VOLUME">Volume</option>
                                <option value="CONSTRUCTION">Construction</option>
                                <option value="OTHER">Other</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="tmi-label mb-0" for="reroute_string">Route String</label>
                        <textarea class="form-control form-control-sm font-monospace" id="reroute_string" rows="2" placeholder="KJFK..MERIT..J75..MPASS..KATL"></textarea>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label class="tmi-label mb-0" for="reroute_from">Traffic From</label>
                            <input type="text" class="form-control form-control-sm" id="reroute_from" placeholder="KJFK/KLGA DEPARTURES">
                        </div>
                        <div class="form-group col-md-6">
                            <label class="tmi-label mb-0" for="reroute_to">Traffic To</label>
                            <input type="text" class="form-control form-control-sm" id="reroute_to" placeholder="KCLT/KATL">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="tmi-label mb-0" for="reroute_facilities">
                            Facilities Included
                            <span id="facilities_auto_badge" class="badge badge-info ml-1" style="font-size: 0.6rem; display: none;">AUTO</span>
                        </label>
                        <input type="text" class="form-control form-control-sm" id="reroute_facilities" placeholder="ZNY ZDC ZTL" data-auto-calculated="false">
                        <small class="form-text text-muted">ARTCCs traversed by route (auto-calculated from route string)</small>
                    </div>
                </div>
            </div>

            <!-- Free-Form Section -->
            <div class="card shadow-sm mb-3 section-card" id="section_atcscc">
                <div class="card-header adv-header-atcscc">
                    <span class="tmi-section-title">
                        <i class="fas fa-file-alt mr-1"></i> Free-Form Advisory
                    </span>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="tmi-label mb-0" for="atcscc_subject">Subject</label>
                        <input type="text" class="form-control form-control-sm" id="atcscc_subject" placeholder="Advisory Subject">
                    </div>
                    <div class="form-group">
                        <label class="tmi-label mb-0" for="atcscc_body">Body</label>
                        <textarea class="form-control form-control-sm" id="atcscc_body" rows="6" placeholder="Advisory body text..."></textarea>
                        <small class="form-text text-muted">Max 68 characters per line (IATA Type B format)</small>
                    </div>
                </div>
            </div>

            <!-- MIT Section -->
            <div class="card shadow-sm mb-3 section-card" id="section_mit">
                <div class="card-header adv-header-mit">
                    <span class="tmi-section-title">
                        <i class="fas fa-ruler-horizontal mr-1"></i> MIT/MINIT Configuration
                    </span>
                </div>
                <div class="card-body">
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label class="tmi-label mb-0" for="mit_facility">Facility</label>
                            <input type="text" class="form-control form-control-sm" id="mit_facility" placeholder="ZNY">
                        </div>
                        <div class="form-group col-md-4">
                            <label class="tmi-label mb-0" for="mit_miles">Miles/Minutes</label>
                            <input type="number" class="form-control form-control-sm" id="mit_miles" min="1" max="100" placeholder="20">
                        </div>
                        <div class="form-group col-md-4">
                            <label class="tmi-label mb-0" for="mit_type">Type</label>
                            <select class="form-control form-control-sm" id="mit_type">
                                <option value="MIT">MIT (Miles)</option>
                                <option value="MINIT">MINIT (Minutes)</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label class="tmi-label mb-0" for="mit_fix">At Fix</label>
                            <input type="text" class="form-control form-control-sm" id="mit_fix" placeholder="MERIT">
                        </div>
                        <div class="form-group col-md-6">
                            <label class="tmi-label mb-0" for="mit_reason">Reason</label>
                            <select class="form-control form-control-sm" id="mit_reason">
                                <option value="WEATHER">Weather</option>
                                <option value="VOLUME">Volume</option>
                                <option value="OTHER">Other</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Cancel Section -->
            <div class="card shadow-sm mb-3 section-card" id="section_cnx">
                <div class="card-header adv-header-cnx">
                    <span class="tmi-section-title">
                        <i class="fas fa-times-circle mr-1"></i> Cancel Advisory
                    </span>
                </div>
                <div class="card-body">
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label class="tmi-label mb-0" for="cnx_ref_number">Original Advisory #</label>
                            <input type="text" class="form-control form-control-sm" id="cnx_ref_number" placeholder="001">
                        </div>
                        <div class="form-group col-md-6">
                            <label class="tmi-label mb-0" for="cnx_ref_type">Original Type</label>
                            <select class="form-control form-control-sm" id="cnx_ref_type">
                                <option value="GDP">GDP</option>
                                <option value="GS">GS</option>
                                <option value="AFP">AFP</option>
                                <option value="CTOP">CTOP</option>
                                <option value="REROUTE">Reroute</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="tmi-label mb-0" for="cnx_comments">Cancel Comments</label>
                        <textarea class="form-control form-control-sm" id="cnx_comments" rows="2" placeholder="Cancellation reason or comments..."></textarea>
                    </div>
                </div>
            </div>

            <!-- Comments Section (Always Visible) -->
            <div class="card shadow-sm mb-3" id="section_comments">
                <div class="card-header">
                    <span class="tmi-section-title">
                        <i class="fas fa-comment mr-1"></i> Comments
                    </span>
                </div>
                <div class="card-body">
                    <div class="form-group mb-0">
                        <label class="tmi-label mb-0" for="adv_comments">Additional Comments</label>
                        <textarea class="form-control form-control-sm" id="adv_comments" rows="2" placeholder="Additional notes or comments..."></textarea>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="d-flex justify-content-between">
                <button class="btn btn-outline-secondary" id="btn_reset" type="button">
                    <i class="fas fa-undo mr-1"></i> Reset
                </button>
                <button class="btn btn-outline-primary" id="btn_save_draft" type="button">
                    <i class="fas fa-save mr-1"></i> Save Draft
                </button>
            </div>
        </div>

        <!-- Right Column: Preview & History -->
        <div class="col-lg-6 mb-4">
            <!-- Advisory Preview -->
            <div class="card shadow-sm mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span class="tmi-section-title">
                        <i class="fas fa-eye mr-1"></i> Advisory Preview
                    </span>
                    <span class="char-count" id="preview_char_count">0 / 2000</span>
                </div>
                <div class="card-body">
                    <pre id="adv_preview" class="tmi-advisory-preview" style="user-select: all;">Select an advisory type to begin...</pre>
                </div>
                <div class="card-footer d-flex justify-content-between">
                    <button class="btn btn-sm btn-outline-secondary" id="btn_copy" type="button">
                        <i class="fas fa-copy mr-1"></i> Copy to Clipboard
                    </button>
                    <button class="btn btn-sm btn-discord" id="btn_post_discord" type="button" style="background: #7289DA; color: #fff;">
                        <i class="fab fa-discord mr-1"></i> Post to Discord
                    </button>
                </div>
            </div>

            <!-- Discord Configuration -->
            <div class="card shadow-sm mb-3">
                <div class="card-header" data-toggle="collapse" data-target="#discord_config_body" style="cursor: pointer;">
                    <span class="tmi-section-title">
                        <i class="fab fa-discord mr-1"></i> Discord Configuration
                        <i class="fas fa-chevron-down float-right mt-1"></i>
                    </span>
                </div>
                <div class="collapse" id="discord_config_body">
                    <div class="card-body">
                        <div class="alert alert-info py-2 mb-3">
                            <small>
                                <i class="fas fa-info-circle mr-1"></i>
                                Configure Discord webhook URL in <code>load/config.php</code> using <code>DISCORD_WEBHOOK_ADVISORIES</code>.
                            </small>
                        </div>
                        <div class="form-group mb-2">
                            <label class="tmi-label mb-0">Webhook Status</label>
                            <div id="discord_status" class="small">
                                <span class="badge badge-secondary">Checking...</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Advisories -->
            <div class="card shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span class="tmi-section-title">
                        <i class="fas fa-history mr-1"></i> Recent Advisories
                    </span>
                    <button class="btn btn-sm btn-outline-secondary" id="btn_refresh_history" type="button">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="thead-light" style="position: sticky; top: 0;">
                                <tr>
                                    <th>Adv #</th>
                                    <th>Type</th>
                                    <th>Subject</th>
                                    <th>Created</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody id="advisory_history_body">
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-3">
                                        <i class="fas fa-inbox"></i> No recent advisories
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include("load/footer.php"); ?>

<!-- Advisory Organization Config Modal -->
<div class="modal fade" id="advisoryOrgModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-globe mr-2"></i>Advisory Organization</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="advisoryOrg" id="orgDCC" value="DCC">
                    <label class="form-check-label" for="orgDCC">
                        <strong>US DCC</strong><br><small class="text-muted">vATCSCC ADVZY ... DCC</small>
                    </label>
                </div>
                <div class="form-check mt-3">
                    <input class="form-check-input" type="radio" name="advisoryOrg" id="orgNOC" value="NOC">
                    <label class="form-check-label" for="orgNOC">
                        <strong>Canadian NOC</strong><br><small class="text-muted">vNAVCAN ADVZY ... NOC</small>
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm" id="advisoryOrgSaveBtn" onclick="AdvisoryConfig.saveOrg()">Save</button>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/advisory-config.js"></script>
<script src="assets/js/advisory-builder.js"></script>

</body>
</html>
