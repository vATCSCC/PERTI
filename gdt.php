<?php

include("sessions/handler.php");
if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

include("load/config.php");
include("load/connect.php");

?>
<!DOCTYPE html>
<html lang="en">
<head>

    <?php $page_title = "vATCSCC Ground Delay Tool"; include("load/header.php"); ?>

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
        .card-header.bg-dark .tmi-section-title,
        .card-header.text-white .tmi-section-title {
            color: #fff;
        }

        .tmi-advisory-preview {
            white-space: pre;
            font-family: "Inconsolata", monospace;
            font-size: 0.8rem;
            color: #333;
        }

        .tmi-flight-table {
            font-size: 0.8rem;
        }

        .tmi-badge-status {
            font-size: 0.7rem;
            text-transform: uppercase;
        }

        .tmi-airport-badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-right: 4px;
            margin-bottom: 2px;
            color: #000;
        }

        .tmi-airports-input {
            transition: color 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .tmi-scope-select {
            height: 140px;
        }

        .tmi-scope-help {
            font-size: 0.7rem;
        }

        /* Ensure proper text contrast for card headers */
        .card-header {
            color: #333;
        }

        .card-header.bg-light {
            color: #333;
        }

        .card-header.bg-light .tmi-label {
            color: #333;
        }

        .card-header.bg-warning {
            color: #333;
        }

        .card-header.bg-warning .tmi-label,
        .card-header.bg-warning .font-weight-bold,
        .card-header.bg-warning small {
            color: #333;
        }

        /* Ensure secondary headers keep white text */
        .card-header.bg-secondary {
            color: #fff;
        }

        .card-header.bg-secondary .tmi-label {
            color: #fff;
        }

        /* Ensure info headers keep white text */
        .card-header.bg-info {
            color: #fff;
        }

        .card-header.bg-info .tmi-label {
            color: #fff;
        }

        /* Ensure primary headers keep white text */
        .card-header.bg-primary {
            color: #fff;
        }

        .card-header.bg-primary .tmi-label {
            color: #fff;
        }

        /* Ensure success headers keep white text */
        .card-header.bg-success {
            color: #fff;
        }

        /* Fix text-muted on dark table headers */
        .thead-dark .text-muted {
            color: rgba(255, 255, 255, 0.7) !important;
        }

        /* Ensure table header text is readable */
        .tmi-flight-table thead th {
            color: #333;
        }

        .tmi-flight-table .thead-dark th {
            color: #fff;
        }

        .tmi-flight-table .thead-light th {
            color: #333;
        }

        /* Badge text contrast fixes */
        .badge-warning {
            color: #333 !important;
        }

        /* Ensure text-warning is visible */
        .tmi-flight-table .text-warning {
            color: #c67c00 !important;
        }

        /* Ensure table text-muted is visible */
        .tmi-flight-table .text-muted {
            color: #6c757d !important;
        }

        /* Origin/Destination Analysis labels */
        .text-uppercase.text-muted.font-weight-bold {
            color: #555 !important;
        }

        /* Modal header text fixes */
        .modal-header.bg-success .modal-title {
            color: #fff;
        }

        .modal-header.bg-success .close {
            color: #fff;
        }

        /* Summary stats table readability */
        .table-borderless td {
            color: #333;
        }

        /* Small text readability in cards */
        .card-body .small,
        .card-body small {
            color: #333;
        }

        /* Pre/code readability */
        pre {
            color: #333;
        }
    </style>

</head>

<body>

<?php include("load/nav.php"); ?>

<section class="d-flex align-items-center position-relative min-vh-25 py-4" data-jarallax data-speed="0.3" style="pointer-events: all;">
    <div class="container-fluid pt-2 pb-4 py-lg-5">
        <img class="jarallax-img" src="assets/img/jumbotron/main.png" alt="" style="opacity: 50%;">

        <center>
            <h1>Ground Delay Tool</h1>
            <h4 class="text-white hvr-bob pl-1">
                <a href="#gs_section" style="text-decoration: none; color: #fff;">
                    <i class="fas fa-chevron-down text-danger"></i>
                    Ground Stop Coordination
                </a>
            </h4>
        </center>
    </div>
</section>

<div class="container-fluid mt-3 mb-5" id="gs_section">
    <!-- Info Bar: UTC Clock, Local Times, Flight Stats -->
    <div class="perti-info-bar mb-3">
        <div class="row d-flex flex-wrap align-items-stretch" style="gap: 8px; margin: 0 -4px;">
            <!-- Current Time (UTC) -->
            <div class="col-auto px-1">
                <div class="card shadow-sm perti-info-card perti-card-utc h-100">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <div class="perti-info-label">Current UTC</div>
                            <div id="tmi_utc_clock" class="perti-clock-display perti-clock-display-lg"></div>
                        </div>
                        <div class="ml-3">
                            <i class="far fa-clock fa-lg text-primary"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Current Time (US Time Zones) -->
            <div class="col-auto px-1">
                <div class="card shadow-sm perti-info-card perti-card-local h-100">
                    <div class="card-body">
                        <div class="perti-info-label mb-1">US Local Times</div>
                        <div class="perti-clock-grid">
                            <div class="perti-clock-item">
                                <div class="perti-clock-tz">GM</div>
                                <div id="tmi_clock_guam" class="perti-clock-display perti-clock-display-md"></div>
                            </div>
                            <div class="perti-clock-item">
                                <div class="perti-clock-tz">HI</div>
                                <div id="tmi_clock_hi" class="perti-clock-display perti-clock-display-md"></div>
                            </div>
                            <div class="perti-clock-item">
                                <div class="perti-clock-tz">AK</div>
                                <div id="tmi_clock_ak" class="perti-clock-display perti-clock-display-md"></div>
                            </div>
                            <div class="perti-clock-item">
                                <div class="perti-clock-tz">PT</div>
                                <div id="tmi_clock_pac" class="perti-clock-display perti-clock-display-md"></div>
                            </div>
                            <div class="perti-clock-item">
                                <div class="perti-clock-tz">MT</div>
                                <div id="tmi_clock_mtn" class="perti-clock-display perti-clock-display-md"></div>
                            </div>
                            <div class="perti-clock-item">
                                <div class="perti-clock-tz">CT</div>
                                <div id="tmi_clock_cent" class="perti-clock-display perti-clock-display-md"></div>
                            </div>
                            <div class="perti-clock-item">
                                <div class="perti-clock-tz">ET</div>
                                <div id="tmi_clock_east" class="perti-clock-display perti-clock-display-md"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Global Flight Counts -->
            <div class="col-auto px-1">
                <div class="card shadow-sm perti-info-card perti-card-global h-100">
                    <div class="card-body">
                        <div class="perti-info-label mb-1">
                            Global Flights
                            <span id="tmi_stats_global_total" class="badge badge-info badge-total ml-1">-</span>
                        </div>
                        <div class="perti-stat-grid">
                            <div class="perti-stat-item">
                                <div class="perti-stat-category">D→D</div>
                                <div id="tmi_stats_dd" class="perti-stat-value">-</div>
                            </div>
                            <div class="perti-stat-item">
                                <div class="perti-stat-category">D→I</div>
                                <div id="tmi_stats_di" class="perti-stat-value">-</div>
                            </div>
                            <div class="perti-stat-item">
                                <div class="perti-stat-category">I→D</div>
                                <div id="tmi_stats_id" class="perti-stat-value">-</div>
                            </div>
                            <div class="perti-stat-item">
                                <div class="perti-stat-category">I→I</div>
                                <div id="tmi_stats_ii" class="perti-stat-value">-</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Domestic Flight Counts -->
            <div class="col-auto px-1">
                <div class="card shadow-sm perti-info-card perti-card-domestic h-100">
                    <div class="card-body">
                        <div class="perti-info-label mb-1">
                            Domestic Arrivals
                            <span id="tmi_stats_domestic_total" class="badge badge-success badge-total ml-1">-</span>
                        </div>
                        <div class="d-flex">
                            <!-- By DCC Region -->
                            <div class="perti-stat-section">
                                <div class="perti-info-sublabel">DCC Region</div>
                                <div class="perti-badge-group">
                                    <span class="badge badge-light" title="Northeast"><strong>NE</strong> <span id="tmi_stats_dcc_ne">-</span></span>
                                    <span class="badge badge-light" title="Southeast"><strong>SE</strong> <span id="tmi_stats_dcc_se">-</span></span>
                                    <span class="badge badge-light" title="Midwest"><strong>MW</strong> <span id="tmi_stats_dcc_mw">-</span></span>
                                    <span class="badge badge-light" title="South Central"><strong>SC</strong> <span id="tmi_stats_dcc_sc">-</span></span>
                                    <span class="badge badge-light" title="West"><strong>W</strong> <span id="tmi_stats_dcc_w">-</span></span>
                                </div>
                            </div>
                            <!-- By Airport Tier -->
                            <div class="perti-stat-section">
                                <div class="perti-info-sublabel">Airport Tier</div>
                                <div class="perti-badge-group">
                                    <span class="badge badge-warning text-dark" title="ASPM 77 Airports"><strong>ASPM77</strong> <span id="tmi_stats_aspm77">-</span></span>
                                    <span class="badge badge-primary" title="OEP 35 Airports"><strong>OEP35</strong> <span id="tmi_stats_oep35">-</span></span>
                                    <span class="badge badge-danger" title="Core 30 Airports"><strong>Core30</strong> <span id="tmi_stats_core30">-</span></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Spacer -->
            <div class="col"></div>
        </div>
    </div>
    <div class="row">
        <!-- Left: Ground Stop editor -->
        <div class="col-lg-6 mb-4">
            <div class="card shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span class="tmi-section-title">
                        <i class="fas fa-ban mr-1 text-danger"></i> Ground Stop Setup
                    </span>
                    <span class="badge badge-secondary tmi-badge-status" id="gs_status_badge">Draft (local)</span>
                </div>

                <div class="card-body">

                    <!-- Basic metadata (GS Name removed - always "CDM GROUND STOP") -->
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label class="tmi-label mb-0" for="gs_ctl_element">CTL ELEMENT</label>
                            <input type="text" class="form-control form-control-sm" id="gs_ctl_element"
                                   placeholder="ATL">
                        </div>
                        <div class="form-group col-md-4">
                            <label class="tmi-label mb-0" for="gs_element_type">Element Type</label>
                            <select class="form-control form-control-sm" id="gs_element_type">
                                <option value="APT" selected>APT</option>
                                <option value="CTR">CTR</option>
                            </select>
                        </div>
                        <div class="form-group col-md-4">
                            <label class="tmi-label mb-0" for="gs_adv_number">Adv #</label>
                            <input type="text" class="form-control form-control-sm" id="gs_adv_number"
                                   placeholder="001">
                        </div>
                    </div>

                    <!-- Period (treated as UTC by JS) -->
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label class="tmi-label mb-0" for="gs_start">GS Start (UTC)</label>
                            <input type="datetime-local" class="form-control form-control-sm" id="gs_start">
                        </div>
                        <div class="form-group col-md-6">
                            <label class="tmi-label mb-0" for="gs_end">GS End (UTC)</label>
                            <input type="datetime-local" class="form-control form-control-sm" id="gs_end">
                        </div>
                    </div>

                    <!-- Airports & scope -->
                    <div class="form-group">
                        <label class="tmi-label mb-0" for="gs_airports">Arrival Airports</label>
                        <input type="text" class="form-control form-control-sm tmi-airports-input" id="gs_airports"
                               placeholder="e.g. KATL KBOS KJFK (space-separated list)">
                        <small class="form-text text-muted">
                            Flights landing at any of these airports will be considered for the GS.
                        </small>
                        <div id="gs_airports_legend" class="mt-1"></div>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label class="tmi-label mb-0" for="gs_scope_select">Origin Centers (Scope)</label>
                            <select multiple class="form-control form-control-sm tmi-scope-select" id="gs_scope_select"></select>
                            <input type="hidden" id="gs_origin_centers">
                            <small class="form-text text-muted tmi-scope-help">
                                Use Tier presets, named groups (e.g. 12West), and individual ARTCCs. Hold Ctrl/Cmd to select multiple.
                            </small>
                        </div>
                        <div class="form-group col-md-6">
                            <label class="tmi-label mb-0" for="gs_origin_airports">Origin Airports</label>
                            <input type="text" class="form-control form-control-sm" id="gs_origin_airports"
                                   placeholder="optional: origin airport list">
                        </div>
                    </div>

                    <!-- Flight inclusion -->
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label class="tmi-label mb-0" for="gs_flt_incl_carrier">FLT INCL Carrier(s)</label>
                            <input type="text" class="form-control form-control-sm" id="gs_flt_incl_carrier"
                                   placeholder="e.g. DAL UAL AAL">
                        </div>
                        <div class="form-group col-md-4">
                            <label class="tmi-label mb-0" for="gs_flt_incl_type">Aircraft Type</label>
                            <select class="form-control form-control-sm" id="gs_flt_incl_type">
                                <option value="ALL" selected>ALL</option>
                                <option value="JET">JET</option>
                                <option value="PROP">PROP</option>
                            </select>
                        </div>
                        <div class="form-group col-md-4">
                            <label class="tmi-label mb-0" for="gs_dep_facilities">DEP FACILITIES INCLUDED</label>
                            <input type="text" class="form-control form-control-sm" id="gs_dep_facilities"
                                   placeholder="ALL or ZTL ZJX ZMA">
                        </div>
                    </div>

                    <!-- Probability / Impacting Condition -->
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label class="tmi-label mb-0" for="gs_prob_ext">Probability of Extension</label>
                            <input type="text" class="form-control form-control-sm" id="gs_prob_ext"
                                   placeholder="Low / Medium / High">
                        </div>
                        <div class="form-group col-md-8">
                            <label class="tmi-label mb-0" for="gs_impacting_condition">Impacting Condition</label>
                            <input type="text" class="form-control form-control-sm" id="gs_impacting_condition"
                                   placeholder="Primary driver or constraint (WX / EQUIP / VOLUME)">
                        </div>
                    </div>

                    <!-- Flight Exemptions Section (Collapsible) -->
                    <div class="card border-warning mb-3">
                        <div class="card-header py-1 px-2 bg-warning text-dark d-flex justify-content-between align-items-center" 
                             data-toggle="collapse" data-target="#gs_exemptions_body" style="cursor: pointer;">
                            <span class="tmi-label mb-0">
                                <i class="fas fa-shield-alt mr-1"></i> Flight Exemptions
                                <span class="badge badge-dark ml-2" id="gs_exemption_count_badge">0 rules</span>
                            </span>
                            <i class="fas fa-chevron-down" id="gs_exemptions_toggle_icon"></i>
                        </div>
                        <div class="collapse" id="gs_exemptions_body">
                            <div class="card-body py-2">
                                <small class="text-muted d-block mb-2">
                                    <i class="fas fa-info-circle"></i> Exempted flights receive no departure delay. Based on FSM User Guide exemption criteria.
                                </small>

                                <!-- Origin Exemptions -->
                                <div class="border rounded p-2 mb-2 bg-light">
                                    <div class="tmi-label text-primary mb-1"><i class="fas fa-plane-departure mr-1"></i> Origin Exemptions</div>
                                    <div class="form-row">
                                        <div class="form-group col-md-4 mb-1">
                                            <label class="small mb-0">Exempt Airports</label>
                                            <input type="text" class="form-control form-control-sm" id="gs_exempt_orig_airports"
                                                   placeholder="e.g. KJFK KLGA KEWR">
                                        </div>
                                        <div class="form-group col-md-4 mb-1">
                                            <label class="small mb-0">Exempt TRACONs</label>
                                            <input type="text" class="form-control form-control-sm" id="gs_exempt_orig_tracons"
                                                   placeholder="e.g. N90 A80 PCT">
                                        </div>
                                        <div class="form-group col-md-4 mb-1">
                                            <label class="small mb-0">Exempt ARTCCs</label>
                                            <input type="text" class="form-control form-control-sm" id="gs_exempt_orig_artccs"
                                                   placeholder="e.g. ZNY ZDC ZBW">
                                        </div>
                                    </div>
                                </div>

                                <!-- Destination Exemptions -->
                                <div class="border rounded p-2 mb-2 bg-light">
                                    <div class="tmi-label text-primary mb-1"><i class="fas fa-plane-arrival mr-1"></i> Destination Exemptions</div>
                                    <div class="form-row">
                                        <div class="form-group col-md-4 mb-1">
                                            <label class="small mb-0">Exempt Airports</label>
                                            <input type="text" class="form-control form-control-sm" id="gs_exempt_dest_airports"
                                                   placeholder="e.g. KORD KDFW">
                                        </div>
                                        <div class="form-group col-md-4 mb-1">
                                            <label class="small mb-0">Exempt TRACONs</label>
                                            <input type="text" class="form-control form-control-sm" id="gs_exempt_dest_tracons"
                                                   placeholder="e.g. C90 D10">
                                        </div>
                                        <div class="form-group col-md-4 mb-1">
                                            <label class="small mb-0">Exempt ARTCCs</label>
                                            <input type="text" class="form-control form-control-sm" id="gs_exempt_dest_artccs"
                                                   placeholder="e.g. ZAU ZFW">
                                        </div>
                                    </div>
                                </div>

                                <!-- Aircraft Type & Status Exemptions -->
                                <div class="border rounded p-2 mb-2 bg-light">
                                    <div class="tmi-label text-primary mb-1"><i class="fas fa-fighter-jet mr-1"></i> Aircraft & Status Exemptions</div>
                                    <div class="form-row">
                                        <div class="form-group col-md-4 mb-1">
                                            <label class="small mb-0">Exempt Aircraft Types</label>
                                            <div class="d-flex flex-wrap">
                                                <div class="custom-control custom-checkbox mr-3">
                                                    <input type="checkbox" class="custom-control-input" id="gs_exempt_type_jet">
                                                    <label class="custom-control-label small" for="gs_exempt_type_jet">Jet</label>
                                                </div>
                                                <div class="custom-control custom-checkbox mr-3">
                                                    <input type="checkbox" class="custom-control-input" id="gs_exempt_type_turboprop">
                                                    <label class="custom-control-label small" for="gs_exempt_type_turboprop">Turboprop</label>
                                                </div>
                                                <div class="custom-control custom-checkbox">
                                                    <input type="checkbox" class="custom-control-input" id="gs_exempt_type_prop">
                                                    <label class="custom-control-label small" for="gs_exempt_type_prop">Prop</label>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="form-group col-md-4 mb-1">
                                            <label class="small mb-0">Flight Status Exemptions</label>
                                            <div class="d-flex flex-wrap">
                                                <div class="custom-control custom-checkbox mr-3">
                                                    <input type="checkbox" class="custom-control-input" id="gs_exempt_has_edct">
                                                    <label class="custom-control-label small" for="gs_exempt_has_edct">Already has EDCT</label>
                                                </div>
                                                <div class="custom-control custom-checkbox">
                                                    <input type="checkbox" class="custom-control-input" id="gs_exempt_active_only">
                                                    <label class="custom-control-label small" for="gs_exempt_active_only">Active flights only</label>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="form-group col-md-4 mb-1">
                                            <label class="small mb-0">Exempt Departing Within</label>
                                            <div class="input-group input-group-sm">
                                                <input type="number" class="form-control form-control-sm" id="gs_exempt_depart_within" 
                                                       placeholder="0" min="0" max="120" value="">
                                                <div class="input-group-append">
                                                    <span class="input-group-text">minutes</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Altitude Exemptions -->
                                <div class="border rounded p-2 mb-2 bg-light">
                                    <div class="tmi-label text-primary mb-1"><i class="fas fa-arrows-alt-v mr-1"></i> Altitude Exemptions</div>
                                    <div class="form-row">
                                        <div class="form-group col-md-6 mb-1">
                                            <label class="small mb-0">Exempt Below (FL)</label>
                                            <input type="number" class="form-control form-control-sm" id="gs_exempt_alt_below"
                                                   placeholder="e.g. 180 (FL180)" min="0" max="600">
                                        </div>
                                        <div class="form-group col-md-6 mb-1">
                                            <label class="small mb-0">Exempt Above (FL)</label>
                                            <input type="number" class="form-control form-control-sm" id="gs_exempt_alt_above"
                                                   placeholder="e.g. 410 (FL410)" min="0" max="600">
                                        </div>
                                    </div>
                                </div>

                                <!-- Individual Flight Exemptions -->
                                <div class="border rounded p-2 bg-light">
                                    <div class="tmi-label text-primary mb-1"><i class="fas fa-plane mr-1"></i> Individual Flight Exemptions</div>
                                    <div class="form-group mb-1">
                                        <label class="small mb-0">Exempt Specific Flights (ACIDs)</label>
                                        <input type="text" class="form-control form-control-sm" id="gs_exempt_flights"
                                               placeholder="e.g. DAL123 UAL456 AAL789 (space-separated)">
                                        <small class="form-text text-muted">Priority flights to exempt from the GS.</small>
                                    </div>
                                </div>

                                <!-- Exemption Summary -->
                                <div class="mt-2 small" id="gs_exemption_summary">
                                    <span class="text-muted">No exemption rules configured.</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Comments -->
                    <div class="form-group">
                        <label class="tmi-label mb-0" for="gs_comments">Comments</label>
                        <textarea class="form-control form-control-sm" id="gs_comments" rows="2"
                                  placeholder="WX / EQUIP / VOLUME, convective impact area, notes"></textarea>
                    </div>

                    <!-- Buttons -->
                    <div class="d-flex justify-content-between">
                        <div>
                            <button class="btn btn-sm btn-outline-secondary" id="gs_reset_btn" type="button">Reset</button>
                        </div>
                        <div>
                            <button class="btn btn-sm btn-outline-info" id="gs_preview_flights_btn" type="button">
                                Preview Impacted Flights
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Advisory preview -->
            <div class="card mt-3 shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span class="tmi-section-title">
                        <i class="fas fa-file-alt mr-1"></i> Advisory Preview
                    </span>
                    <button class="btn btn-sm btn-outline-secondary" id="gs_copy_advisory_btn" type="button" title="Copy advisory to clipboard for Discord">
                        <i class="fas fa-copy"></i> Copy
                    </button>
                </div>
                <div class="card-body">
                    <pre id="gs_advisory_preview" class="tmi-advisory-preview" style="user-select: all;"></pre>
                </div>
            </div>
        </div>

        <!-- Right: Flights impacted -->
        <div class="col-lg-6 mb-4">
            <div class="card shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span class="tmi-section-title">
                        <i class="fas fa-plane-departure mr-1"></i> Flights Matching GS Filters
                        <span class="badge badge-pill badge-info ml-2" id="gs_flight_count_badge">0</span>
                    </span>
                    <div>
                        <span class="badge badge-secondary tmi-badge-status" id="gs_adl_mode_badge" title="Current dataset">ADL: LIVE</span>
                    </div>
                </div>
                <div class="card-body p-2">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <small class="text-muted" id="gs_adl_status"></small>
                        <div class="btn-toolbar" role="toolbar" aria-label="GS workflow controls">
                            <div class="btn-group btn-group-sm mr-1" role="group">
                                <button class="btn btn-outline-info" id="gs_preview_btn" type="button" title="Preview flights from live ADL">Preview</button>
                                <button class="btn btn-outline-primary" id="gs_simulate_btn" type="button" title="Simulate GS and calculate EDCTs">Simulate</button>
                                <button class="btn btn-outline-secondary" id="gs_send_actual_btn" type="button" title="Run 'Simulate' first">Send Actual</button>
                            </div>
                            <div class="btn-group btn-group-sm mr-1" role="group">
                                <button class="btn btn-outline-secondary" id="gs_view_flight_list_btn" type="button" title="View GS Flight List">
                                    <i class="fas fa-list-alt"></i>
                                </button>
                                <button class="btn btn-outline-primary" id="gs_open_model_btn" type="button" title="Open Model GS Data Graph">
                                    <i class="fas fa-chart-line"></i>
                                </button>
                            </div>
                            <div class="btn-group btn-group-sm" role="group">
                                <button class="btn btn-outline-warning" id="gs_purge_local_btn" type="button" title="Clear simulation sandbox">Purge Local</button>
                                <button class="btn btn-outline-danger" id="gs_purge_all_btn" type="button" title="Clear all GS controls from live ADL">Purge All</button>
                            </div>
                        </div>
                    </div>

                    <div class="form-row align-items-end mb-2">
                        <div class="form-group col-md-4 mb-1">
                            <label class="tmi-label mb-0" for="gs_time_basis">Time Filter Basis <small class="text-info">(GS: Departure-based)</small></label>
                            <select class="form-control form-control-sm" id="gs_time_basis">
                                <option value="NONE" selected>No time filter</option>
                                <option value="ETD">Departure ETD</option>
                                <option value="EDCT">Departure EDCT/CTD</option>
                                <option value="TAKEOFF">Departure Takeoff</option>
                            </select>
                        </div>
                        <div class="form-group col-md-4 mb-1">
                            <label class="tmi-label mb-0" for="gs_time_start">Time Start (UTC)</label>
                            <input type="datetime-local" class="form-control form-control-sm" id="gs_time_start">
                        </div>
                        <div class="form-group col-md-4 mb-1">
                            <label class="tmi-label mb-0" for="gs_time_end">Time End (UTC)</label>
                            <input type="datetime-local" class="form-control form-control-sm" id="gs_time_end">
                        </div>
                    </div>

                    <div class="table-responsive" style="max-height: 420px; overflow-y: auto;">
                        <table class="table table-sm table-striped table-hover tmi-flight-table mb-0" id="gs_flights_matching_table">
                            <thead class="thead-light" style="position: sticky; top: 0; z-index: 5;">
                              <tr>
                                <th class="gs-matching-sortable" data-sort="acid" style="cursor:pointer;">ACID <i class="fas fa-sort fa-xs text-muted"></i></th>
                                <th class="gs-matching-sortable" data-sort="etd" style="cursor:pointer;">ETD <i class="fas fa-sort fa-xs text-muted"></i></th>
                                <th class="gs-matching-sortable" data-sort="edct" style="cursor:pointer;">CTD <i class="fas fa-sort fa-xs text-muted"></i></th>
                                <th class="gs-matching-sortable" data-sort="eta" style="cursor:pointer;">ETA <i class="fas fa-sort fa-xs text-muted"></i></th>
                                <th class="gs-matching-sortable" data-sort="dcenter" style="cursor:pointer;">DCTR <i class="fas fa-sort fa-xs text-muted"></i></th>
                                <th class="gs-matching-sortable" data-sort="orig" style="cursor:pointer;">ORIG <i class="fas fa-sort fa-xs text-muted"></i></th>
                                <th class="gs-matching-sortable" data-sort="dest" style="cursor:pointer;">DEST <i class="fas fa-sort fa-xs text-muted"></i></th>
                                <th>STATUS</th>
                              </tr>
                            </thead>
                            <tbody id="gs_flight_table_body">
                            </tbody>
                        </table>
                    </div>

                    <div class="row mt-2" id="gs_delay_breakdowns_row">
                        <div class="col-md-6 mb-2">
                            <div class="card border-light">
                                <div class="card-header py-1 px-2">
                                    <span class="tmi-label">Delay by Origin Airport</span>
                                </div>
                                <div class="card-body p-1">
                                    <table class="table table-sm table-hover tmi-flight-table mb-0">
                                        <thead>
                                            <tr>
                                                <th>Origin</th>
                                                <th class="text-right">Total</th>
                                                <th class="text-right">Max</th>
                                                <th class="text-right">Avg</th>
                                            </tr>
                                        </thead>
                                        <tbody id="gs_delay_origin_ap"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 mb-2">
                            <div class="card border-light">
                                <div class="card-header py-1 px-2">
                                    <span class="tmi-label">Delay by Origin ARTCC</span>
                                </div>
                                <div class="card-body p-1">
                                    <table class="table table-sm table-hover tmi-flight-table mb-0">
                                        <thead>
                                            <tr>
                                                <th>Center</th>
                                                <th class="text-right">Total</th>
                                                <th class="text-right">Max</th>
                                                <th class="text-right">Avg</th>
                                            </tr>
                                        </thead>
                                        <tbody id="gs_delay_origin_center"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 mb-2">
                            <div class="card border-light">
                                <div class="card-header py-1 px-2">
                                    <span class="tmi-label">Delay by Carrier</span>
                                </div>
                                <div class="card-body p-1">
                                    <table class="table table-sm table-hover tmi-flight-table mb-0">
                                        <thead>
                                            <tr>
                                                <th>Carrier</th>
                                                <th class="text-right">Total</th>
                                                <th class="text-right">Max</th>
                                                <th class="text-right">Avg</th>
                                            </tr>
                                        </thead>
                                        <tbody id="gs_delay_carrier"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 mb-2">
                            <div class="card border-light">
                                <div class="card-header py-1 px-2">
                                    <span class="tmi-label">Delay by EDCT Hour (UTC)</span>
                                </div>
                                <div class="card-body p-1">
                                    <table class="table table-sm table-hover tmi-flight-table mb-0">
                                        <thead>
                                            <tr>
                                                <th>Hour</th>
                                                <th class="text-right">Total</th>
                                                <th class="text-right">Max</th>
                                                <th class="text-right">Avg</th>
                                            </tr>
                                        </thead>
                                        <tbody id="gs_delay_hour_bin"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mt-3">
                        <span class="tmi-label mb-0">Flight Counts Summary</span>
                        <button class="btn btn-sm btn-outline-secondary" id="gs_toggle_counts_btn" type="button">Hide</button>
                    </div>

                    <div class="row mt-2" id="gs_counts_row">
                        <div class="col-md-6 mb-2">
                            <div class="card border-light">
                                <div class="card-header py-1 px-2">
                                    <span class="tmi-label">Origin Centers (ARTCC)</span>
                                </div>
                                <div class="card-body p-1">
                                    <table class="table table-sm table-hover tmi-flight-table mb-0">
                                        <tbody id="gs_counts_origin_center"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 mb-2">
                            <div class="card border-light">
                                <div class="card-header py-1 px-2">
                                    <span class="tmi-label">Destination Centers (ARTCC)</span>
                                </div>
                                <div class="card-body p-1">
                                    <table class="table table-sm table-hover tmi-flight-table mb-0">
                                        <tbody id="gs_counts_dest_center"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 mb-2">
                            <div class="card border-light">
                                <div class="card-header py-1 px-2">
                                    <span class="tmi-label">Origin Airports</span>
                                </div>
                                <div class="card-body p-1">
                                    <table class="table table-sm table-hover tmi-flight-table mb-0">
                                        <tbody id="gs_counts_origin_ap"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 mb-2">
                            <div class="card border-light">
                                <div class="card-header py-1 px-2">
                                    <span class="tmi-label">Destination Airports</span>
                                </div>
                                <div class="card-body p-1">
                                    <table class="table table-sm table-hover tmi-flight-table mb-0">
                                        <tbody id="gs_counts_dest_ap"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 mb-2">
                            <div class="card border-light">
                                <div class="card-header py-1 px-2">
                                    <span class="tmi-label">Carriers</span>
                                </div>
                                <div class="card-body p-1">
                                    <table class="table table-sm table-hover tmi-flight-table mb-0">
                                        <tbody id="gs_counts_carrier"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <small class="text-muted">
                        Data from <code>https://data.vatsim.net/v3/vatsim-data.json</code> (pilots + prefiles).
                    </small>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Model GS Section (FSM User Guide Chapter 19, Modeling Options Tab) -->
<div class="container-fluid mb-4" id="gs_model_section" style="display: none;">
    <div class="row">
        <div class="col-12">
            <div class="card shadow-sm border-primary">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <span class="tmi-section-title">
                        <i class="fas fa-chart-line mr-1"></i> Model GS - Power Run Analysis
                    </span>
                    <button type="button" class="btn btn-sm btn-light" id="gs_model_close_btn">
                        <i class="fas fa-times"></i> Close
                    </button>
                </div>
                <div class="card-body">
                    <!-- Filter Controls Row -->
                    <div class="row mb-3">
                        <div class="col-md-2">
                            <label class="tmi-label mb-0">Chart View</label>
                            <select class="form-control form-control-sm" id="gs_model_chart_view">
                                <option value="hourly" selected>By Hour (UTC)</option>
                                <option value="orig_artcc">By Origin ARTCC</option>
                                <option value="dest_artcc">By Dest ARTCC</option>
                                <option value="orig_ap">By Origin Airport</option>
                                <option value="dest_ap">By Dest Airport</option>
                                <option value="orig_tracon">By Origin TRACON</option>
                                <option value="dest_tracon">By Dest TRACON</option>
                                <option value="carrier">By Carrier</option>
                                <option value="tier">By ARTCC Tier</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="tmi-label mb-0">Time Window</label>
                            <select class="form-control form-control-sm" id="gs_model_time_window">
                                <option value="all" selected>All Flights</option>
                                <option value="60">Next 60 min</option>
                                <option value="30">Next 30 min</option>
                                <option value="15">Next 15 min</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="tmi-label mb-0">Time Basis</label>
                            <select class="form-control form-control-sm" id="gs_model_time_basis">
                                <option value="ctd" selected>CTD (Controlled)</option>
                                <option value="etd">ETD (Original)</option>
                                <option value="cta">CTA (Arr Controlled)</option>
                                <option value="eta">ETA (Arr Original)</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="tmi-label mb-0">Filter by Origin ARTCC</label>
                            <input type="text" class="form-control form-control-sm" id="gs_model_filter_artcc" placeholder="e.g., ZTL ZDC ZNY">
                        </div>
                        <div class="col-md-3">
                            <label class="tmi-label mb-0">Filter by Carrier</label>
                            <input type="text" class="form-control form-control-sm" id="gs_model_filter_carrier" placeholder="e.g., DAL UAL AAL">
                        </div>
                    </div>

                    <!-- Main Chart and Legend Row -->
                    <div class="row mb-3">
                        <div class="col-md-8">
                            <div class="card border-secondary">
                                <div class="card-header py-1 px-2 bg-info text-white d-flex justify-content-between align-items-center">
                                    <small class="text-uppercase font-weight-bold"><i class="fas fa-chart-bar mr-1"></i> <span id="gs_model_chart_title">Data Graph - Delay Statistics by Hour</span></small>
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-light btn-sm" id="gs_model_chart_type_bar" title="Bar Chart"><i class="fas fa-chart-bar"></i></button>
                                        <button class="btn btn-outline-light btn-sm" id="gs_model_chart_type_line" title="Line Chart"><i class="fas fa-chart-line"></i></button>
                                    </div>
                                </div>
                                <div class="card-body p-2">
                                    <div id="gs_model_data_graph_chart" style="height: 300px; position: relative;">
                                        <canvas id="gs_model_data_graph_canvas"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card border-secondary h-100">
                                <div class="card-header py-1 px-2 bg-info text-white">
                                    <small class="text-uppercase font-weight-bold"><i class="fas fa-calculator mr-1"></i> Summary Statistics</small>
                                </div>
                                <div class="card-body p-2">
                                    <table class="table table-sm table-borderless mb-0" style="font-size: 0.8rem;">
                                        <tbody>
                                            <tr><td><span class="badge badge-danger">&nbsp;</span> Total Flights</td><td class="text-right font-weight-bold" id="gs_model_total_flts">0</td></tr>
                                            <tr><td><span class="badge badge-info">&nbsp;</span> Affected Flights</td><td class="text-right font-weight-bold" id="gs_model_affected_flts">0</td></tr>
                                            <tr><td><span class="badge badge-primary">&nbsp;</span> Total Delay</td><td class="text-right font-weight-bold" id="gs_model_total_delay">0 min</td></tr>
                                            <tr><td><span class="badge badge-dark">&nbsp;</span> Max Delay</td><td class="text-right font-weight-bold" id="gs_model_max_delay">0 min</td></tr>
                                            <tr><td><span class="badge badge-secondary">&nbsp;</span> Avg Delay</td><td class="text-right font-weight-bold" id="gs_model_avg_delay">0 min</td></tr>
                                        </tbody>
                                    </table>
                                    <hr class="my-2">
                                    <table class="table table-sm table-borderless mb-0" style="font-size: 0.75rem;">
                                        <tbody>
                                            <tr><td class="text-muted">Within 60 min:</td><td class="text-right" id="gs_model_horizon_60">0 flts</td></tr>
                                            <tr><td class="text-muted">Within 30 min:</td><td class="text-right" id="gs_model_horizon_30">0 flts</td></tr>
                                            <tr><td class="text-muted">Within 15 min:</td><td class="text-right" id="gs_model_horizon_15">0 flts</td></tr>
                                        </tbody>
                                    </table>
                                    <hr class="my-2">
                                    <div class="small">
                                        <strong>GS Program:</strong> <span id="gs_model_ctl_element" class="text-primary">-</span><br>
                                        <span class="text-muted">Start:</span> <span id="gs_model_gs_start">-</span><br>
                                        <span class="text-muted">End:</span> <span id="gs_model_gs_end">-</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Original vs Controlled Times Comparison -->
                    <div class="row mb-3">
                        <div class="col-12">
                            <div class="card border-info">
                                <div class="card-header py-1 px-2 bg-info text-white">
                                    <small class="text-uppercase font-weight-bold"><i class="fas fa-exchange-alt mr-1"></i> Original vs Controlled Times Comparison</small>
                                </div>
                                <div class="card-body p-2">
                                    <div style="height: 200px; position: relative;">
                                        <canvas id="gs_model_comparison_canvas"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Detailed Breakdown Tables -->
                    <div class="row">
                        <!-- Origin Analysis -->
                        <div class="col-md-6 mb-3">
                            <div class="card border-light">
                                <div class="card-header py-1 px-2 bg-light">
                                    <span class="tmi-label"><i class="fas fa-plane-departure mr-1"></i> Origin Analysis</span>
                                </div>
                                <div class="card-body p-1">
                                    <div class="row">
                                        <div class="col-6">
                                            <small class="text-uppercase text-muted font-weight-bold">By ARTCC</small>
                                            <div style="max-height: 140px; overflow-y: auto;">
                                                <table class="table table-sm table-hover mb-0" style="font-size: 0.75rem;">
                                                    <thead><tr><th>ARTCC</th><th class="text-right">Flts</th><th class="text-right">Delay</th><th class="text-right">Avg</th></tr></thead>
                                                    <tbody id="gs_model_by_orig_artcc"></tbody>
                                                </table>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <small class="text-uppercase text-muted font-weight-bold">By Airport</small>
                                            <div style="max-height: 140px; overflow-y: auto;">
                                                <table class="table table-sm table-hover mb-0" style="font-size: 0.75rem;">
                                                    <thead><tr><th>Apt</th><th class="text-right">Flts</th><th class="text-right">Delay</th><th class="text-right">Avg</th></tr></thead>
                                                    <tbody id="gs_model_by_orig_ap"></tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row mt-2">
                                        <div class="col-6">
                                            <small class="text-uppercase text-muted font-weight-bold">By TRACON</small>
                                            <div style="max-height: 140px; overflow-y: auto;">
                                                <table class="table table-sm table-hover mb-0" style="font-size: 0.75rem;">
                                                    <thead><tr><th>TRACON</th><th class="text-right">Flts</th><th class="text-right">Delay</th><th class="text-right">Avg</th></tr></thead>
                                                    <tbody id="gs_model_by_orig_tracon"></tbody>
                                                </table>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <small class="text-uppercase text-muted font-weight-bold">By Tier</small>
                                            <div style="max-height: 140px; overflow-y: auto;">
                                                <table class="table table-sm table-hover mb-0" style="font-size: 0.75rem;">
                                                    <thead><tr><th>Tier</th><th class="text-right">Flts</th><th class="text-right">Delay</th><th class="text-right">Avg</th></tr></thead>
                                                    <tbody id="gs_model_by_orig_tier"></tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Destination Analysis -->
                        <div class="col-md-6 mb-3">
                            <div class="card border-light">
                                <div class="card-header py-1 px-2 bg-light">
                                    <span class="tmi-label"><i class="fas fa-plane-arrival mr-1"></i> Destination Analysis</span>
                                </div>
                                <div class="card-body p-1">
                                    <div class="row">
                                        <div class="col-6">
                                            <small class="text-uppercase text-muted font-weight-bold">By ARTCC</small>
                                            <div style="max-height: 140px; overflow-y: auto;">
                                                <table class="table table-sm table-hover mb-0" style="font-size: 0.75rem;">
                                                    <thead><tr><th>ARTCC</th><th class="text-right">Flts</th><th class="text-right">Delay</th><th class="text-right">Avg</th></tr></thead>
                                                    <tbody id="gs_model_by_dest_artcc"></tbody>
                                                </table>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <small class="text-uppercase text-muted font-weight-bold">By Airport</small>
                                            <div style="max-height: 140px; overflow-y: auto;">
                                                <table class="table table-sm table-hover mb-0" style="font-size: 0.75rem;">
                                                    <thead><tr><th>Apt</th><th class="text-right">Flts</th><th class="text-right">Delay</th><th class="text-right">Avg</th></tr></thead>
                                                    <tbody id="gs_model_by_dest_ap"></tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row mt-2">
                                        <div class="col-6">
                                            <small class="text-uppercase text-muted font-weight-bold">By TRACON</small>
                                            <div style="max-height: 140px; overflow-y: auto;">
                                                <table class="table table-sm table-hover mb-0" style="font-size: 0.75rem;">
                                                    <thead><tr><th>TRACON</th><th class="text-right">Flts</th><th class="text-right">Delay</th><th class="text-right">Avg</th></tr></thead>
                                                    <tbody id="gs_model_by_dest_tracon"></tbody>
                                                </table>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <small class="text-uppercase text-muted font-weight-bold">By Tier</small>
                                            <div style="max-height: 140px; overflow-y: auto;">
                                                <table class="table table-sm table-hover mb-0" style="font-size: 0.75rem;">
                                                    <thead><tr><th>Tier</th><th class="text-right">Flts</th><th class="text-right">Delay</th><th class="text-right">Avg</th></tr></thead>
                                                    <tbody id="gs_model_by_dest_tier"></tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Carrier and Delay Distribution -->
                    <div class="row">
                        <div class="col-md-4 mb-2">
                            <div class="card border-light">
                                <div class="card-header py-1 px-2"><span class="tmi-label"><i class="fas fa-building mr-1"></i> By Carrier</span></div>
                                <div class="card-body p-1" style="max-height: 180px; overflow-y: auto;">
                                    <table class="table table-sm table-hover mb-0" style="font-size: 0.75rem;">
                                        <thead><tr><th>Carrier</th><th class="text-right">Flts</th><th class="text-right">Total</th><th class="text-right">Avg</th></tr></thead>
                                        <tbody id="gs_model_by_carrier"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 mb-2">
                            <div class="card border-light">
                                <div class="card-header py-1 px-2"><span class="tmi-label"><i class="fas fa-clock mr-1"></i> By Delay Range</span></div>
                                <div class="card-body p-1" style="max-height: 180px; overflow-y: auto;">
                                    <table class="table table-sm table-hover mb-0" style="font-size: 0.75rem;">
                                        <thead><tr><th>Range</th><th class="text-right">Flts</th><th class="text-right">%</th></tr></thead>
                                        <tbody id="gs_model_by_delay_range"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 mb-2">
                            <div class="card border-light">
                                <div class="card-header py-1 px-2"><span class="tmi-label"><i class="fas fa-history mr-1"></i> By Hour (UTC)</span></div>
                                <div class="card-body p-1" style="max-height: 180px; overflow-y: auto;">
                                    <table class="table table-sm table-hover mb-0" style="font-size: 0.75rem;">
                                        <thead><tr><th>Hour</th><th class="text-right">Flts</th><th class="text-right">Total</th><th class="text-right">Avg</th></tr></thead>
                                        <tbody id="gs_model_by_hour"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================================ -->
<!-- GDP Section (Ground Delay Program) - FSM User Guide Chapter 15-17 -->
<!-- ============================================================================ -->
<?php include 'load/gdp_section.php'; ?>

<!-- ECR (EDCT Change Request) Modal - FSM User Guide Chapter 14 -->
<div class="modal fade" id="ecr_modal" tabindex="-1" role="dialog" aria-labelledby="ecr_modal_label" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
        <div class="modal-content">
            <div class="modal-header bg-info text-white py-2">
                <h5 class="modal-title" id="ecr_modal_label">
                    <i class="fas fa-clock mr-2"></i>EDCT Change Request (ECR)
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <!-- ECR Header Info -->
                <div class="alert alert-info py-2 mb-3">
                    <small>
                        <i class="fas fa-info-circle mr-1"></i>
                        <strong>ECR</strong> allows you to change Estimated Departure Clearance Times (EDCTs) for controlled flights.
                        <span class="text-muted">(FSM User Guide Ch. 14)</span>
                    </small>
                </div>

                <!-- Find Flight Section -->
                <div class="card border-primary mb-3">
                    <div class="card-header py-1 px-2 bg-primary text-white">
                        <span class="tmi-label mb-0"><i class="fas fa-search mr-1"></i> Find Flight</span>
                    </div>
                    <div class="card-body py-2">
                        <div class="form-row">
                            <div class="form-group col-md-4 mb-1">
                                <label class="small mb-0">ACID (Callsign)</label>
                                <input type="text" class="form-control form-control-sm" id="ecr_acid" placeholder="e.g. DAL123">
                            </div>
                            <div class="form-group col-md-4 mb-1">
                                <label class="small mb-0">Origin (ORIG)</label>
                                <input type="text" class="form-control form-control-sm" id="ecr_orig" placeholder="e.g. KATL">
                            </div>
                            <div class="form-group col-md-4 mb-1">
                                <label class="small mb-0">Destination (DEST)</label>
                                <input type="text" class="form-control form-control-sm" id="ecr_dest" placeholder="e.g. KJFK">
                            </div>
                        </div>
                        <div class="form-row align-items-end">
                            <div class="form-group col-md-6 mb-1">
                                <label class="small mb-0">Earliest EDCT (UTC)</label>
                                <input type="datetime-local" class="form-control form-control-sm" id="ecr_earliest_edct">
                            </div>
                            <div class="form-group col-md-6 mb-1">
                                <button class="btn btn-sm btn-primary w-100" id="ecr_get_flight_btn" type="button">
                                    <i class="fas fa-search mr-1"></i> Get Flight Data
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Current Flight Data Section -->
                <div class="card border-secondary mb-3" id="ecr_flight_data_section" style="display: none;">
                    <div class="card-header py-1 px-2 bg-info text-white">
                        <span class="tmi-label mb-0"><i class="fas fa-plane mr-1"></i> Current Flight Data</span>
                    </div>
                    <div class="card-body py-2">
                        <div class="row">
                            <div class="col-md-6">
                                <table class="table table-sm table-borderless mb-0" style="font-size: 0.8rem;">
                                    <tbody>
                                        <tr><td class="text-muted" style="width:40%;">IGTD:</td><td id="ecr_igtd">-</td></tr>
                                        <tr><td class="text-muted">CTD:</td><td id="ecr_ctd" class="font-weight-bold text-primary">-</td></tr>
                                        <tr><td class="text-muted">ETD:</td><td id="ecr_etd">-</td></tr>
                                        <tr><td class="text-muted">ERTD:</td><td id="ecr_ertd">-</td></tr>
                                        <tr><td class="text-muted">ETE:</td><td id="ecr_ete">-</td></tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <table class="table table-sm table-borderless mb-0" style="font-size: 0.8rem;">
                                    <tbody>
                                        <tr><td class="text-muted" style="width:40%;">IGTA:</td><td id="ecr_igta">-</td></tr>
                                        <tr><td class="text-muted">CTA:</td><td id="ecr_cta" class="font-weight-bold text-primary">-</td></tr>
                                        <tr><td class="text-muted">ETA:</td><td id="ecr_eta">-</td></tr>
                                        <tr><td class="text-muted">ERTA:</td><td id="ecr_erta">-</td></tr>
                                        <tr><td class="text-muted">Delay:</td><td id="ecr_delay" class="font-weight-bold">-</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="mt-2 pt-2 border-top">
                            <div class="row small">
                                <div class="col-6"><strong>Control Type:</strong> <span id="ecr_ctl_type" class="badge badge-info">-</span></div>
                                <div class="col-6"><strong>Delay Status:</strong> <span id="ecr_delay_status">-</span></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Update Options Section -->
                <div class="card border-warning mb-3" id="ecr_update_section" style="display: none;">
                    <div class="card-header py-1 px-2 bg-warning text-dark">
                        <span class="tmi-label mb-0"><i class="fas fa-edit mr-1"></i> Update Options</span>
                    </div>
                    <div class="card-body py-2">
                        <!-- CTA Range Controls -->
                        <div class="form-row mb-2">
                            <div class="form-group col-md-4 mb-1">
                                <label class="small mb-0">CTA Range (min)</label>
                                <input type="number" class="form-control form-control-sm" id="ecr_cta_range" value="60" min="30">
                            </div>
                            <div class="form-group col-md-4 mb-1">
                                <label class="small mb-0">Max Additional Delay</label>
                                <input type="number" class="form-control form-control-sm" id="ecr_max_add_delay" value="60" min="30" readonly>
                            </div>
                            <div class="form-group col-md-4 mb-1 d-flex align-items-end">
                                <button class="btn btn-sm btn-outline-secondary w-100" id="ecr_default_range_btn" type="button">
                                    Default Range
                                </button>
                            </div>
                        </div>

                        <!-- Update Method Selection -->
                        <div class="border rounded p-2 bg-light mb-2">
                            <div class="tmi-label text-dark mb-2">Select Update Method:</div>
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="custom-control custom-radio">
                                        <input type="radio" id="ecr_method_scs" name="ecr_method" class="custom-control-input" value="SCS" checked>
                                        <label class="custom-control-label" for="ecr_method_scs">
                                            <strong>SCS</strong>
                                            <small class="d-block text-muted">Slot Credit Substitution</small>
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="custom-control custom-radio">
                                        <input type="radio" id="ecr_method_limited" name="ecr_method" class="custom-control-input" value="LIMITED">
                                        <label class="custom-control-label" for="ecr_method_limited">
                                            <strong>Limited</strong>
                                            <small class="d-block text-muted">Within CTA Range</small>
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="custom-control custom-radio">
                                        <input type="radio" id="ecr_method_unlimited" name="ecr_method" class="custom-control-input" value="UNLIMITED">
                                        <label class="custom-control-label" for="ecr_method_unlimited">
                                            <strong>Unlimited</strong>
                                            <small class="d-block text-muted">Any available slot</small>
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="custom-control custom-radio">
                                        <input type="radio" id="ecr_method_manual" name="ecr_method" class="custom-control-input" value="MANUAL">
                                        <label class="custom-control-label" for="ecr_method_manual">
                                            <strong>Manual</strong>
                                            <small class="d-block text-muted">Specify exact time</small>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Manual EDCT Entry (shown only when Manual selected) -->
                        <div class="form-row" id="ecr_manual_section" style="display: none;">
                            <div class="form-group col-md-6 mb-1">
                                <label class="small mb-0">New EDCT (Manual)</label>
                                <input type="datetime-local" class="form-control form-control-sm" id="ecr_manual_edct">
                            </div>
                            <div class="form-group col-md-6 mb-1">
                                <label class="small mb-0">Calculated CTA</label>
                                <input type="text" class="form-control form-control-sm" id="ecr_manual_cta" readonly>
                            </div>
                        </div>

                        <!-- Modeled Results -->
                        <div class="border rounded p-2 bg-white" id="ecr_model_results" style="display: none;">
                            <div class="tmi-label text-success mb-2"><i class="fas fa-calculator mr-1"></i> Modeled Update Results:</div>
                            <div class="row small">
                                <div class="col-md-4"><strong>New CTD:</strong> <span id="ecr_new_ctd" class="text-primary font-weight-bold">-</span></div>
                                <div class="col-md-4"><strong>New CTA:</strong> <span id="ecr_new_cta" class="text-primary font-weight-bold">-</span></div>
                                <div class="col-md-4"><strong>Delay Change:</strong> <span id="ecr_delay_change">-</span></div>
                            </div>
                        </div>

                        <!-- ERTA Update Option -->
                        <div class="mt-2 pt-2 border-top">
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input" id="ecr_update_erta">
                                <label class="custom-control-label small" for="ecr_update_erta">
                                    Update ERTA (Earliest Runway Time of Arrival)
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ECR Response Section -->
                <div class="alert alert-success py-2 mb-0" id="ecr_response_section" style="display: none;">
                    <strong><i class="fas fa-check-circle mr-1"></i> ECR Response:</strong>
                    <pre id="ecr_response_text" class="mb-0 mt-1" style="font-size: 0.8rem; white-space: pre-wrap;"></pre>
                </div>
            </div>
            <div class="modal-footer py-2">
                <div class="mr-auto">
                    <small class="text-muted">
                        Control Types: <span class="badge badge-info">ECR</span> ATCSCC SCS |
                        <span class="badge badge-secondary">SCS</span> Customer SCS |
                        <span class="badge badge-warning text-dark">UPD</span> EDCT Update |
                        <span class="badge badge-success">BRG</span> Bridged
                    </small>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="ecr_clear_btn">Clear All</button>
                <button type="button" class="btn btn-sm btn-info" id="ecr_apply_model_btn">Apply Model</button>
                <button type="button" class="btn btn-sm btn-success" id="ecr_send_request_btn" disabled>Send Request</button>
                <button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal">Cancel</button>
            </div>
        </div>
    </div>
</div>

<!-- GS Flight List Modal -->
<div class="modal fade" id="gs_flight_list_modal" tabindex="-1" role="dialog" aria-labelledby="gs_flight_list_modal_label" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable" role="document" style="max-width: 95%;">
        <div class="modal-content">
            <div class="modal-header bg-success text-white py-2">
                <h5 class="modal-title" id="gs_flight_list_modal_label">
                    <i class="fas fa-list-alt mr-2"></i>GS Flight List - Affected Flights
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <!-- Program Info & Summary Row -->
                <div class="row mb-3">
                    <div class="col-md-4">
                        <div class="card border-info h-100">
                            <div class="card-header py-1 px-2 bg-info text-white">
                                <small class="text-uppercase font-weight-bold">GS Program Information</small>
                            </div>
                            <div class="card-body py-2 px-3">
                                <div class="row small">
                                    <div class="col-6"><strong>CTL Element:</strong> <span id="gs_flt_list_ctl_element">-</span></div>
                                    <div class="col-6"><strong>GS Start:</strong> <span id="gs_flt_list_start">-</span></div>
                                    <div class="col-6"><strong>Program:</strong> <span id="gs_flt_list_program">Ground Stop</span></div>
                                    <div class="col-6"><strong>GS End:</strong> <span id="gs_flt_list_end">-</span></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-warning h-100">
                            <div class="card-header py-1 px-2 bg-warning text-dark">
                                <small class="text-uppercase font-weight-bold">Delay Statistics</small>
                            </div>
                            <div class="card-body py-2 px-3">
                                <div class="row small">
                                    <div class="col-6"><strong>Total Flights:</strong> <span id="gs_flt_list_total">0</span></div>
                                    <div class="col-6"><strong>Affected Flights:</strong> <span id="gs_flt_list_affected">0</span></div>
                                    <div class="col-6"><strong>Max Delay:</strong> <span id="gs_flt_list_max_delay">0</span> min</div>
                                    <div class="col-6"><strong>Avg Delay:</strong> <span id="gs_flt_list_avg_delay">0</span> min</div>
                                    <div class="col-6"><strong>Total Delay:</strong> <span id="gs_flt_list_total_delay">0</span> min</div>
                                    <div class="col-6"><strong>Generated:</strong> <span id="gs_flt_list_timestamp">-</span></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-secondary h-100">
                            <div class="card-header py-1 px-2 bg-info text-white">
                                <small class="text-uppercase font-weight-bold">View Options</small>
                            </div>
                            <div class="card-body py-2 px-3">
                                <div class="row small">
                                    <div class="col-6">
                                        <label class="tmi-label mb-0">Group By:</label>
                                        <select class="form-control form-control-sm" id="gs_flt_list_group_by">
                                            <option value="none">None (Flat List)</option>
                                            <option value="carrier">Carrier</option>
                                            <option value="orig_airport">Origin Airport</option>
                                            <option value="orig_center">Origin Center</option>
                                            <option value="dest_airport">Dest Airport</option>
                                            <option value="dest_center">Dest Center</option>
                                            <option value="delay_bucket">Delay Range</option>
                                        </select>
                                    </div>
                                    <div class="col-6">
                                        <label class="tmi-label mb-0">Sort By:</label>
                                        <select class="form-control form-control-sm" id="gs_flt_list_sort_by">
                                            <option value="acid_asc">ACID (A-Z)</option>
                                            <option value="acid_desc">ACID (Z-A)</option>
                                            <option value="delay_desc">Delay (High-Low)</option>
                                            <option value="delay_asc">Delay (Low-High)</option>
                                            <option value="etd_asc">ETD (Earliest)</option>
                                            <option value="etd_desc">ETD (Latest)</option>
                                            <option value="orig_asc">Origin (A-Z)</option>
                                            <option value="dest_asc">Dest (A-Z)</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Link to Model GS Section -->
                <div class="alert alert-info py-2 mb-3">
                    <i class="fas fa-chart-line mr-1"></i> <strong>Data Graph:</strong> 
                    <a href="#" id="gs_flt_list_open_model" class="alert-link">Open Model GS Section</a> for detailed Power Run analysis and delay distribution charts (FSM Chapter 19).
                </div>

                <!-- Export & Action Buttons -->
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div>
                        <span class="tmi-label">Flight List for ATC Facility Coordination</span>
                        <span class="badge badge-info ml-2" id="gs_flt_list_count_badge">0 flights</span>
                    </div>
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-outline-secondary" id="gs_flt_list_copy_btn" type="button" title="Copy to clipboard">
                            <i class="fas fa-copy"></i> Copy
                        </button>
                        <button class="btn btn-outline-secondary" id="gs_flt_list_export_csv_btn" type="button" title="Export as CSV">
                            <i class="fas fa-file-csv"></i> CSV
                        </button>
                        <button class="btn btn-outline-secondary" id="gs_flt_list_print_btn" type="button" title="Print flight list">
                            <i class="fas fa-print"></i> Print
                        </button>
                    </div>
                </div>

                <!-- Main Flight List Table with sortable headers -->
                <div class="table-responsive" style="max-height: 380px; overflow-y: auto;">
                    <table class="table table-sm table-striped table-hover tmi-flight-table mb-0" id="gs_flight_list_table">
                        <thead class="thead-dark" style="position: sticky; top: 0; z-index: 10;">
                            <tr>
                                <th class="gs-sortable" data-sort="acid" style="cursor:pointer;">ACID <i class="fas fa-sort fa-xs text-muted"></i></th>
                                <th class="gs-sortable" data-sort="carrier" style="cursor:pointer;">Carrier <i class="fas fa-sort fa-xs text-muted"></i></th>
                                <th class="gs-sortable" data-sort="orig" style="cursor:pointer;">ORIG <i class="fas fa-sort fa-xs text-muted"></i></th>
                                <th class="gs-sortable" data-sort="dest" style="cursor:pointer;">DEST <i class="fas fa-sort fa-xs text-muted"></i></th>
                                <th class="gs-sortable" data-sort="dcenter" style="cursor:pointer;">DCTR <i class="fas fa-sort fa-xs text-muted"></i></th>
                                <th class="gs-sortable" data-sort="acenter" style="cursor:pointer;">ACTR <i class="fas fa-sort fa-xs text-muted"></i></th>
                                <th class="gs-sortable" data-sort="oetd" style="cursor:pointer;">OETD <i class="fas fa-sort fa-xs text-muted"></i></th>
                                <th class="gs-sortable" data-sort="etd" style="cursor:pointer;">ETD <i class="fas fa-sort fa-xs text-muted"></i></th>
                                <th>CTD/EDCT</th>
                                <th class="gs-sortable" data-sort="oeta" style="cursor:pointer;">OETA <i class="fas fa-sort fa-xs text-muted"></i></th>
                                <th class="gs-sortable" data-sort="eta" style="cursor:pointer;">ETA <i class="fas fa-sort fa-xs text-muted"></i></th>
                                <th>CTA</th>
                                <th class="gs-sortable" data-sort="delay" style="cursor:pointer;">Delay <i class="fas fa-sort fa-xs text-muted"></i></th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="gs_flight_list_body">
                        </tbody>
                    </table>
                </div>

                <!-- Summary Tables Row -->
                <div class="row mt-3">
                    <div class="col-md-3 mb-2">
                        <div class="card border-light">
                            <div class="card-header py-1 px-2">
                                <span class="tmi-label">By Origin Center</span>
                            </div>
                            <div class="card-body p-1" style="max-height: 130px; overflow-y: auto;">
                                <table class="table table-sm table-hover tmi-flight-table mb-0">
                                    <thead><tr><th>Center</th><th class="text-right">Count</th></tr></thead>
                                    <tbody id="gs_flt_list_by_dcenter"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-2">
                        <div class="card border-light">
                            <div class="card-header py-1 px-2">
                                <span class="tmi-label">By Origin Airport</span>
                            </div>
                            <div class="card-body p-1" style="max-height: 130px; overflow-y: auto;">
                                <table class="table table-sm table-hover tmi-flight-table mb-0">
                                    <thead><tr><th>Airport</th><th class="text-right">Count</th></tr></thead>
                                    <tbody id="gs_flt_list_by_orig"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-2">
                        <div class="card border-light">
                            <div class="card-header py-1 px-2">
                                <span class="tmi-label">By Dest Airport</span>
                            </div>
                            <div class="card-body p-1" style="max-height: 130px; overflow-y: auto;">
                                <table class="table table-sm table-hover tmi-flight-table mb-0">
                                    <thead><tr><th>Airport</th><th class="text-right">Count</th></tr></thead>
                                    <tbody id="gs_flt_list_by_dest"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-2">
                        <div class="card border-light">
                            <div class="card-header py-1 px-2">
                                <span class="tmi-label">By Carrier</span>
                            </div>
                            <div class="card-body p-1" style="max-height: 130px; overflow-y: auto;">
                                <table class="table table-sm table-hover tmi-flight-table mb-0">
                                    <thead><tr><th>Carrier</th><th class="text-right">Count</th></tr></thead>
                                    <tbody id="gs_flt_list_by_carrier"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer py-2">
                <small class="text-muted mr-auto">
                    <i class="fas fa-info-circle"></i> Use this flight list to coordinate with affected ATC facilities (TFMS/FSM Flight List reference Ch 6 & 19).
                </small>
                <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Chart.js CDN for Data Graph -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation@2.2.1/dist/chartjs-plugin-annotation.min.js"></script>
<!-- D3.js for GDP Demand/Capacity visualization -->
<script src="https://cdn.jsdelivr.net/npm/d3@7.8.5/dist/d3.min.js"></script>

<?php include("load/footer.php"); ?>

<script src="assets/js/gdt.js"></script>
<script src="assets/js/gdp.js"></script>

</body>
</html>