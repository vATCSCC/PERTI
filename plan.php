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

    $uri = explode('?', $_SERVER['REQUEST_URI']);
    $id = $uri[1];

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

    $plan_info = $conn_sqli->query("SELECT * FROM p_plans WHERE id=$id")->fetch_assoc();
?>

<!DOCTYPE html>
<html>

<head>

    <!-- Import CSS -->
    <?php
        $page_title = "PERTI Plan";
        include("load/header.php");
    ?>
    <link rel="stylesheet" href="assets/css/initiative_timeline.css">

    <script>
        function tooltips() {
            $('[data-toggle="tooltip"]').tooltip('dispose');

            $(function () {
                $('[data-toggle="tooltip"]').tooltip()
            }); 
        }
    </script>

    <style>
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
            margin-bottom: 0.25rem;
        }

        .adv-facilities-grid input[type="checkbox"] {
            margin-right: 0.25rem;
        }

        .adv-facilities-grid label {
            font-size: 0.75rem;
            margin-bottom: 0;
        }
    </style>

    <script>
        // PERTI Discord Notification globals
        var PERTI_EVENT_NAME     = <?= json_encode($plan_info['event_name']); ?>;
        var PERTI_EVENT_DATE     = <?= json_encode($plan_info['event_date']); ?>;       // 'YYYY-MM-DD'
        var PERTI_EVENT_START    = <?= json_encode($plan_info['event_start']); ?>;      // 'hhmm' Z
        var PERTI_EVENT_END_DATE = <?= json_encode($plan_info['event_end_date'] ?? ''); ?>;  // 'YYYY-MM-DD'
        var PERTI_EVENT_END_TIME = <?= json_encode($plan_info['event_end_time'] ?? ''); ?>;  // 'hhmm' Z
        var PERTI_OPLEVEL        = <?= json_encode($plan_info['oplevel']); ?>;
        var PERTI_PLAN_ID        = <?= json_encode($id); ?>;
        var PERTI_HAS_PERM       = <?= json_encode($perm); ?>;
        
        // Compute event start/end as ISO strings for timeline
        var PERTI_EVENT_START_ISO = null;
        var PERTI_EVENT_END_ISO = null;
        
        (function() {
            // Parse event start
            if (PERTI_EVENT_DATE && PERTI_EVENT_START) {
                var startTime = PERTI_EVENT_START.padStart(4, '0');
                PERTI_EVENT_START_ISO = PERTI_EVENT_DATE + 'T' + startTime.substring(0,2) + ':' + startTime.substring(2,4) + ':00Z';
            }
            // Parse event end
            var endDate = PERTI_EVENT_END_DATE || PERTI_EVENT_DATE;
            var endTime = PERTI_EVENT_END_TIME || '';
            if (endDate && endTime) {
                endTime = endTime.padStart(4, '0');
                PERTI_EVENT_END_ISO = endDate + 'T' + endTime.substring(0,2) + ':' + endTime.substring(2,4) + ':00Z';
            } else if (PERTI_EVENT_START_ISO) {
                // Default to 6 hours after start if no end time specified
                var startDt = new Date(PERTI_EVENT_START_ISO);
                var endDt = new Date(startDt.getTime() + 6 * 60 * 60 * 1000);
                PERTI_EVENT_END_ISO = endDt.toISOString();
            }
        })();
    </script>
</head>

<body>

<?php
include('load/nav.php');
?>

    <section class="d-flex align-items-center position-relative bg-position-center overflow-hidden pt-6 jarallax bg-dark text-light" style="min-height: 250px" data-jarallax data-speed="0.3" style="pointer-events: all;">
        <div class="container-fluid pt-2 pb-5 py-lg-6">
            <img class="jarallax-img" src="assets/img/jumbotron/main.png" alt="" style="opacity: 50%; height: 100vh;">

            <center>
                <h1><b><span class="text-danger"><?= $plan_info['event_name']; ?></span> PERTI Plan</b></h1>
                <h5><a class="text-light" href="data?<?= $plan_info['id']; ?>"><i class="fas fa-table text-success"></i> Edit Staffing Data</a></h5>
            </center>

        </div>       
    </section>

    <div class="container-fluid mt-3 mb-3">
        <div class="row">
            <div class="col-2">
                <ul class="nav flex-column nav-pills" aria-orientation="vertical">
                    <li><a class="nav-link active rounded" data-toggle="tab" href="#overview">Overview</a></li>
                    <hr>
                    <li><a class="nav-link rounded" data-toggle="tab" href="#dcc_staffing">DCC Staffing</a></li>
                    <li><a class="nav-link rounded" data-toggle="tab" href="#historical">Historical Data</a></li>
                    <li><a class="nav-link rounded" data-toggle="tab" href="#forecast">Weather Forecast</a></li>
                    <hr>
                    <li><a class="nav-link rounded" data-toggle="tab" href="#t_initiatives">Terminal Initiatives</a></li>
                    <li><a class="nav-link rounded" data-toggle="tab" href="#t_staffing">Terminal Staffing</a></li>
                    <li><a class="nav-link rounded" data-toggle="tab" href="#configs">Field Configurations</a></li>
                    <li><a class="nav-link rounded" data-toggle="tab" href="#t_planning">Terminal Planning</a></li>
                    <hr>
                    <li><a class="nav-link rounded" data-toggle="tab" href="#e_initiatives">En-Route Initiatives</a></li>
                    <li><a class="nav-link rounded" data-toggle="tab" href="#e_staffing">En-Route Staffing</a></li>
                    <li><a class="nav-link rounded" data-toggle="tab" href="#e_planning">En-Route Planning</a></li>
                    <hr>
                    <li><a class="nav-link rounded" data-toggle="tab" href="#group_flights">Group Flights</a></li>
                    <li><a class="nav-link rounded" data-toggle="tab" href="#outlook">Extended Outlook</a></li>
                    <li><a class="nav-link rounded" data-toggle="tab" href="#advisories">Advisory Builder</a></li>
                </ul>
            </div>
            <div class="col-10">
                <div class="tab-content">
                    <!-- Tab: Overview -->
                    <div class="tab-pane fade show active" id="overview">
                        <div class="row">
                            <div class="col-6">
                                <img src="<?= $plan_info['event_banner']; ?>" class="rounded" style="width: 100%;" alt="<?= $plan_info['event_name']; ?> Event Banner">

                                <hr>

                                <?php if ($perm == true) { ?>
                                    <h4><b>Operational Goals</b> <span class="badge badge-success" data-toggle="modal" data-target="#addgoalModal"><i class="fas fa-plus" data-toggle="tooltip" title="Add Operational Goal"></i></span></h4>
                                <?php } else { ?>
                                    <h4><b>Operational Goals</b></h4>
                                <?php } ?>


                                <table class="table table-bordered">
                                    <tbody id="goals_table"></tbody>
                                </table>
                            </div>

                            <div class="col-6">
                                <h4><b>Event Information</b></h4>
                                <table class="table table-striped table-bordered">
                                    <tbody>
                                        <tr>
                                            <td><b>Event Name</b></td>
                                            <td><?= $plan_info['event_name']; ?></td>
                                        </tr>
                                        <tr>
                                            <td><b>Event Start Date</b></td>
                                            <td><?= $plan_info['event_date']; ?></td>
                                        </tr>
                                        <tr>
                                            <td><b>Event Start Time</b></td>
                                            <td><?= $plan_info['event_start']; ?>Z</td>
                                        </tr>
                                        <tr>
                                            <td><b>Event End Date</b></td>
                                            <td><?php
                                                $end_date = $plan_info['event_end_date'] ?? '';
                                                if (!empty($end_date)) {
                                                    echo $end_date;
                                                } else {
                                                    echo '<span class="text-muted">—</span>';
                                                }
                                            ?></td>
                                        </tr>
                                        <tr>
                                            <td><b>Event End Time</b></td>
                                            <td><?php
                                                $end_time = $plan_info['event_end_time'] ?? '';
                                                if (!empty($end_time)) {
                                                    echo $end_time . 'Z';
                                                } else {
                                                    echo '<span class="text-muted">—</span>';
                                                }
                                            ?></td>
                                        </tr>
                                        <tr>
                                            <td><b>TMU OpLevel</b></td>
                                            <?php
                                                if ($plan_info['oplevel'] == 1) {
                                                    echo '<td class="text-dark">'.$plan_info['oplevel'].' - Steady State</td>';
                                                }
                                                elseif ($plan_info['oplevel'] == 2) {
                                                    echo '<td class="text-success">'.$plan_info['oplevel'].' - Localized Impact</td>';
                                                }
                                                elseif ($plan_info['oplevel'] == 3) {
                                                    echo '<td class="text-warning">'.$plan_info['oplevel'].' - Regional Impact</td>';
                                                }
                                                elseif ($plan_info['oplevel'] == 4) {
                                                    echo '<td class="text-danger">'.$plan_info['oplevel'].' - NAS-Wide Impact/td>';
                                                }
                                            ?>
                                        </tr>
                                    </tbody>
                                </table>

                                <hr>

                                <button type="button" class="btn btn-sm btn-outline-secondary mb-2" onclick="AdvisoryConfig.showConfigModal();" data-toggle="tooltip" title="Switch between US DCC and Canadian NOC advisory formats">
                                    <i class="fas fa-globe"></i> <span id="advisoryOrgDisplay">DCC</span>
                                </button>

                                <button type="button" class="btn btn-sm btn-primary mb-2" onclick="openPertiModal();">
                                    Create PERTI Discord Notification
                                </button>

                                <button type="button" class="btn btn-sm btn-outline-primary mb-2" onclick="openOpsPlanModal();">
                                    Create Operations Plan Advisory
                                </button>

                                <hr>

                                <h4><b>Resources</b></h4>
                                <ul>
                                    <li><a href="https://perti.vatcscc.org/nod" target="_blank">PERTI NAS Operations Dashboard (NOD)</a> for NAS-wide information.</li>
                                    <li><a href="https://perti.vatcscc.org/splits" target="_blank">PERTI Active Splits</a> for airspace split coordination.</li>
                                    <li><a href="https://perti.vatcscc.org/gdt" target="_blank">PERTI Ground Delay Tool (GDT)</a> for ground delay program/ground stop management.</li>
                                    <li><a href="https://perti.vatcscc.org/jatoc" target="_blank">PERTI JATOC AWO Incident Monitor</a> for incident management and real-time tracking.</li>
                                    <li>vATCSCC Discord <a href="https://discord.com/channels/358264961233059843/358295136398082048/" target="_blank">#ntml</a> and <a href="https://discord.com/channels/358264961233059843/358300240236773376/" target="_blank">#advisories</a> for TMI data logging.</li>
                                    <li>VATUSA <a href="ts3server://ts.vatusa.net" target="_blank">TeamSpeak</a>, <span class="text-danger"><b><?= $plan_info['hotline']; ?></b></span> Hotline for real-time operational coordination.
                                        <ul><li>Any credentials in use will be posted in the #advisories channel in the vATCSCC Discord.</li>
                                        <li>The VATCAN <a href="ts3server://ts.vatcan.ca" target="_blank">TeamSpeak</a>, TMU Hang channel will serve as a primary backup if the VATUSA TeamSpeak fails.</li>
                                        <li>The vATCSCC Discord, <?= $plan_info['hotline']; ?> Hotline voice channel will serve as a secondary backup.</li></ul></li>
                                    <li>Post any known virtual airline/group flight entries into <a href="https://bit.ly/NTML_Entry" target="_blank">this form</a>.</li>
                                    <li>TMU personnel must utilize <b>authorized</b> callsigns (XX_XX_TMU) in accordance with <a href="https://www.vatusa.net/info/policies/authorized-tmu-callsigns" target="_blank">this policy</a>.</li>
                                    <li><a href="https://bit.l/vATCSCC_Transgression_Reporting_Form" target="_blank">vATCSCC Trangression Reporting Form</a> for incident reporting.</li>
                                </ul>

                            </div>
                        </div>
                    </div>

                    <!-- Tab: DCC Staffing -->
                    <div class="tab-pane fade" id="dcc_staffing">
                        
                        <?php if ($perm == true) { ?>
                            <button class="btn btn-sm btn-success" data-toggle="modal" data-target="#add_dccstaffingModal"><i class="fas fa-plus"></i> Add Personnel</button>      

                            <hr>
                        <?php } ?>

                        <div class="row">
                            <div class="col-6">
                                <h4><b>DCC Personnel</b></h4>

                                <table class="table table-striped table-bordered">
                                    <thead>
                                        <th class="text-center"><b>OIs</b></th>
                                        <th><b>Personnel Name</b></th>
                                        <th><b>Position Name</b></th>
                                        <?php if ($perm == true) {
                                            echo '<th></th>';
                                        }
                                        ?>
                                    </thead>
                                    <tbody id="dcc_table"></tbody>
                                </table>
                            </div>

                            <div class="col-6">
                                <h4><b>Facility Personnel</b></h4>

                                <table class="table table-striped table-bordered">
                                    <thead>
                                        <th class="text-center"><b>Facility</b></th>
                                        <th class="text-center"><b>OIs</b></th>
                                        <th><b>Personnel Name</b></th>
                                        <?php if ($perm == true) {
                                            echo '<th></th>';
                                        }
                                        ?>
                                    </thead>
                                    <tbody id="dcc_staffing_table"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Tab: Historical Data -->
                    <div class="tab-pane fade" id="historical">

                        <?php if ($perm == true) { ?>
                            <button class="btn btn-sm btn-success" data-toggle="modal" data-target="#addhistoricalModal"><i class="fas fa-plus"></i> Add Data</button>      

                            <hr>
                        <?php } ?>

                        <div class="row gutters-tiny py-20" id="historicaldata"></div>
                    </div>

                    <!-- Tab: Forecast Entry -->
                    <div class="tab-pane fade" id="forecast">
                        <?php if ($perm == true) { ?>
                            <button class="btn btn-sm btn-success" data-toggle="modal" data-target="#addforecastModal"><i class="fas fa-plus"></i> Add Forecast</button>      

                            <hr>
                        <?php } ?>

                        <div class="row gutters-tiny py-20" id="forecastdata"></div>
                    </div>

                    <!-- Tab: Terminal Initiatives -->
                    <div class="tab-pane fade" id="t_initiatives">
                        <?php if ($perm == true) { ?>
                            <button class="btn btn-sm btn-success mb-3" onclick="window.termInitTimeline && window.termInitTimeline.showAddModal()"><i class="fas fa-plus"></i> Add Initiative</button>
                        <?php } ?>
                        <div id="term_inits_timeline"></div>
                        
                        <!-- Legacy view (hidden by default, kept for backwards compatibility) -->
                        <div id="term_inits_legacy" style="display: none;">
                            <?php if ($perm == true) { ?>
                                <button class="btn btn-sm btn-success" data-toggle="modal" data-target="#addterminalinitModal"><i class="fas fa-plus"></i> Add Initiative (Legacy)</button>      
                                <hr>
                            <?php } ?>
                            <center><div id="term_inits"></div></center>
                        </div>
                    </div>

                    <!-- Tab: Terminal Staffing -->
                    <div class="tab-pane fade" id="t_staffing">
                        
                        <?php if ($perm == true) { ?>
                            <button class="btn btn-sm btn-success" data-toggle="modal" data-target="#addtermstaffingModal"><i class="fas fa-plus"></i> Add Staffing</button>      

                            <hr>
                        <?php } ?>


                        <center><table class="table table-sm table-striped table-bordered w-75">
                            <thead>
                                <th class="text-center"><b>Facility Name</b></th>
                                <th class="text-center"><b>Status</b></th>
                                <th class="text-center"><b>Quantity</b></th>
                                <th class="text-center"><b>Comments</b></th>
                                <?php if ($perm == true) {
                                    echo '<th></th>';
                                }
                                ?>
                            </thead>
                            <tbody id="term_staffing_table"></tbody>
                        </table></center>
                    </div>

                    <!-- Tab: Field Configs -->
                    <div class="tab-pane fade" id="configs">
                        
                        <?php if ($perm == true) { ?>
                            <button class="btn btn-sm btn-success" data-toggle="modal" data-target="#addconfigModal"><i class="fas fa-plus"></i> Add Config</button>      

                            <hr>
                        <?php } ?>

                        <center><table class="table table-sm table-striped table-bordered w-75">
                            <thead>
                                <th class="text-center"><b>Field</b></th>
                                <th class="text-center"><b>Conditions</b></th>
                                <th class="text-center"><b>Arriving</b></th>
                                <th class="text-center"><b>Departing</b></th>
                                <th class="text-center"><b>AAR</b></th>
                                <th class="text-center"><b>ADR</b></th>
                                <th class="text-center"><b>Comments</b></th>
                                <?php if ($perm == true) {
                                    echo '<th></th>';
                                }
                                ?>
                            </thead>
                            <tbody id="configs_table"></tbody>
                        </table></center>
                    </div>

                    <!-- Tab: Terminal Planning -->
                    <div class="tab-pane fade" id="t_planning">
                        <?php if ($perm == true) { ?>
                            <button class="btn btn-sm btn-success" data-toggle="modal" data-target="#addtermplanningModal"><i class="fas fa-plus"></i> Add Plan</button>      

                            <hr>
                        <?php } ?>

                        <div class="row gutters-tiny py-20" id="termplanningdata"></div>
                    </div>

                    <!-- Tab: Enroute Initiatives -->
                    <div class="tab-pane fade" id="e_initiatives">
                        <?php if ($perm == true) { ?>
                            <button class="btn btn-sm btn-success mb-3" onclick="window.enrouteInitTimeline && window.enrouteInitTimeline.showAddModal()"><i class="fas fa-plus"></i> Add Initiative</button>
                        <?php } ?>
                        <div id="enroute_inits_timeline"></div>
                        
                        <!-- Legacy view (hidden by default, kept for backwards compatibility) -->
                        <div id="enroute_inits_legacy" style="display: none;">
                            <?php if ($perm == true) { ?>
                                <button class="btn btn-sm btn-success" data-toggle="modal" data-target="#addenrouteinitModal"><i class="fas fa-plus"></i> Add Initiative (Legacy)</button>      
                                <hr>
                            <?php } ?>                          
                            <center><div id="enroute_inits"></div></center>
                        </div>
                    </div>

                    <!-- Tab: Enroute Staffing -->
                    <div class="tab-pane fade" id="e_staffing">
                        <?php if ($perm == true) { ?>
                            <button class="btn btn-sm btn-success" data-toggle="modal" data-target="#addenroutestaffingModal"><i class="fas fa-plus"></i> Add Staffing</button>      

                            <hr>
                        <?php } ?>                           

                        <center><table class="table table-sm table-striped table-bordered w-75">
                            <thead>
                                <th class="text-center"><b>Facility Name</b></th>
                                <th class="text-center"><b>Status</b></th>
                                <th class="text-center"><b>Quantity</b></th>
                                <th class="text-center"><b>Comments</b></th>
                                <?php if ($perm == true) {
                                    echo '<th></th>';
                                }
                                ?>
                            </thead>
                            <tbody id="enroute_staffing_table"></tbody>
                        </table></center>
                    </div>

                    <!-- Tab: Enroute Planning -->
                    <div class="tab-pane fade" id="e_planning">
                        <?php if ($perm == true) { ?>
                            <button class="btn btn-sm btn-success" data-toggle="modal" data-target="#addenrouteplanningModal"><i class="fas fa-plus"></i> Add Plan</button>      

                            <hr>
                        <?php } ?>                         

                        <div class="row gutters-tiny py-20" id="enrouteplanningdata"></div>
                    </div>

                    <!-- Tab: Group Flights -->
                    <div class="tab-pane fade" id="group_flights">
                        <?php if ($perm == true) { ?>
                            <button class="btn btn-sm btn-success" data-toggle="modal" data-target="#addgroupflightModal"><i class="fas fa-plus"></i> Add Flight</button>      

                            <hr>
                        <?php } ?>                           


                        <center><table class="table table-striped table-bordered w-75">
                            <thead>
                                <th class="text-center"><b>Group/Entity</b></th>
                                <th class="text-center"><b>DEP</b></th>
                                <th class="text-center"><b>ARR</b></th>
                                <th class="text-center"><b>ETD</b></th>
                                <th class="text-center"><b>ETA</b></th>
                                <th class="text-center"><b>Quantity</b></th>
                                <th class="text-center"><b>Route</b></th>
                                <?php if ($perm == true) {
                                    echo '<th></th>';
                                }
                                ?>
                            </thead>
                            <tbody id="group_flights_table"></tbody>
                        </table></center>
                    </div>

                    <!-- Tab: Extended Outlook -->
                    <div class="tab-pane fade" id="outlook">
                        <div class="row gutters-tiny py-20" id="outlook_data"></div>
                    </div>

                    <!-- Tab: Advisory Builder -->
                    <div class="tab-pane fade" id="advisories">
                        <div class="card">
                            <div class="card-header">
                                <strong>vATCSCC Advisory Builder (Discord)</strong>
                            </div>
                            <div class="card-body">
                                <div class="container-fluid">

                                    <!-- Line 1: vATCSCC ADVZY ... -->
                                    <div class="form-row">
                                        <div class="form-group col-md-3">
                                            <label for="advzyNumber">Advisory Number</label>
                                            <input type="text" class="form-control form-control-sm" id="advzyNumber" placeholder="e.g. 027">
                                            <small class="form-text text-muted">
                                                Numeric; will be zero-padded to 3 digits.
                                            </small>
                                        </div>
                                        <div class="form-group col-md-3">
                                            <label for="advzyFacility">Facility (optional)</label>
                                            <input type="text" class="form-control form-control-sm" id="advzyFacility" placeholder="e.g. DCC or JFK/ZNY">
                                        </div>
                                        <div class="form-group col-md-6">
                                            <label for="advzyDate">Advisory Date (UTC, mm/dd/yyyy)</label>
                                            <input type="text" class="form-control form-control-sm" id="advzyDate" placeholder="mm/dd/yyyy">
                                        </div>
                                    </div>

                                    <!-- Advisory Type/Name (OPERATIONS PLAN, ROUTE, etc.) -->
                                    <div class="form-row">
                                        <div class="form-group col-md-12">
                                            <label for="advzyType">Advisory Type/Name</label>
                                            <input type="text" class="form-control form-control-sm" id="advzyType" placeholder="e.g. OPERATIONS PLAN">
                                            <small class="form-text text-muted">
                                                Examples: OPERATIONS PLAN, ROUTE, PLAYBOOK, INFORMATIONAL, etc.
                                            </small>
                                        </div>
                                    </div>

                                    <!-- VALID FOR line -->
                                    <div class="form-row">
                                        <div class="form-group col-md-3">
                                            <label for="advzyValidFrom">Valid From (ddhhmm)</label>
                                            <input type="text" class="form-control form-control-sm" id="advzyValidFrom" placeholder="e.g. 301300">
                                        </div>
                                        <div class="form-group col-md-3">
                                            <label for="advzyValidTo">Valid To (ddhhmm)</label>
                                            <input type="text" class="form-control form-control-sm" id="advzyValidTo" placeholder="e.g. 301900">
                                        </div>
                                        <div class="form-group col-md-6">
                                            <small class="form-text text-muted mt-4">
                                                If both are filled, a <code>VALID FOR ddhhmm THRU ddhhmm</code> line will be added.
                                            </small>
                                        </div>
                                    </div>

                                    <hr class="my-2">

                                    <!-- Free-text advisory body -->
                                    <div class="form-group">
                                        <label for="advzyBody">Advisory Text</label>
                                        <textarea class="form-control" id="advzyBody" rows="6"></textarea>
                                        <small class="form-text text-muted">
                                            Free-form text; lines are wrapped to 68 characters per line.
                                        </small>
                                    </div>

                                    <!-- Effective time line -->
                                    <div class="form-row">
                                        <div class="form-group col-md-3">
                                            <label for="advzyEffFrom">Effective From (ddhhmm)</label>
                                            <input type="text" class="form-control form-control-sm" id="advzyEffFrom" placeholder="e.g. 301224">
                                        </div>
                                        <div class="form-group col-md-3">
                                            <label for="advzyEffTo">Effective To (ddhhmm)</label>
                                            <input type="text" class="form-control form-control-sm" id="advzyEffTo" placeholder="e.g. 301359">
                                        </div>
                                        <div class="form-group col-md-6">
                                            <small class="form-text text-muted mt-4">
                                                Creates the <code>ddhhmm-ddhhmm</code> line for advisory coverage time.
                                            </small>
                                        </div>
                                    </div>

                                    <!-- Signature line -->
                                    <div class="form-group">
                                        <label for="advzySignature">Signature Line</label>
                                        <input type="text" class="form-control form-control-sm" id="advzySignature" placeholder="YY/MM/DD hh:mm /OI">
                                        <small class="form-text text-muted">
                                            Example: <code>25/12/07 13:45 /HP</code>.
                                        </small>
                                    </div>

                                    <hr class="my-2">

                                    <!-- Build & copy -->
                                    <div class="form-group">
                                        <button type="button" class="btn btn-sm btn-primary" id="advzyBuildBtn">
                                            Generate Advisory Text
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-primary" id="advzyCopyBtn">
                                            Copy to Clipboard
                                        </button>
                                    </div>

                                    <!-- Final Discord-ready output -->
                                    <div class="form-group mt-2">
                                        <label for="advzyMessage">Advisory Text (copy into Discord)</label>
                                        <textarea class="form-control" id="advzyMessage" rows="14" style="font-family: Menlo, Consolas, monospace;"></textarea>
                                    </div>

                                </div>
                            </div>
                        </div>
                    </div>


                </div>
            </div>
        </div>
    </div>

</body>
<?php include('load/footer.php'); ?>


<?php if (perm == true) { ?>
<!-- Add Goal Modal -->
<div class="modal fade" id="addgoalModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Operational Goal</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="addgoal">

                <div class="modal-body">

                    <input type="hidden" name="p_id" value="<?= $id; ?>">

                    Operational Goal:
                    <textarea class="form-control" name="comments" placeholder="Manage DTW airport operations to keep departure and airborne arrival delays to less than 30 minutes." rows="3"></textarea>

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-success" value="Add">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal">Close</button>
                </div>
        </div>

        </form>

    </div>
</div>

<!-- Edit Goal Modal -->
<div class="modal fade" id="editgoalModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Operational Goal</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="editgoal">

                <div class="modal-body">

                    <input type="hidden" name="id" id="id">

                    Operational Goal:
                    <textarea class="form-control" name="comments" id="comments" placeholder="Manage DTW airport operations to keep departure and airborne arrival delays to less than 30 minutes." rows="3"></textarea>

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-warning" value="Edit">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal">Close</button>
                </div>
        </div>

        </form>

    </div>
</div>

<!-- Add DCC Personnel Modal -->
<div class="modal fade" id="add_dccstaffingModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Personnel</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="add_dccstaffing">

                <div class="modal-body">

                    <input type="hidden" name="p_id" value="<?= $id; ?>">

                    Facility:
                    <select class="form-control" name="position_facility" required>
                        <option value="DCC">DCC - Command Center</option>
                        <option value="VATCAN">VATCAN - Command Center</option>
                        <option value="ECFMP">ECFMP - EUROCONTROL</option>
                        <option value="ZLA">ZLA - Los Angeles ARTCC</option>
                        <option value="ZOA">ZOA - Oakland ARTCC</option>
                        <option value="ZAK">ZAK - Oakland OCA</option>
                        <option value="HCF">HCF - Honolulu Control Facility</option>
                        <option value="ZAN">ZAN - Anchorage ARTCC</option>
                        <option value="ZUA">ZUA - Guam CERAP</option>
                        <option value="ZLC">ZLC - Salt Lake City ARTCC</option>
                        <option value="ZSE">ZSE - Seattle ARTCC</option>
                        <option value="ZAB">ZAB - Albuquerque ARTCC</option>
                        <option value="ZFW">ZFW - Fort Worth ARTCC</option>
                        <option value="ZHU">ZHU - Houston ARTCC</option>
                        <option value="ZME">ZME - Memphis ARTCC</option>
                        <option value="ZAU">ZAU - Chicago ARTCC</option>
                        <option value="ZDV">ZDV - Denver ARTCC</option>
                        <option value="ZKC">ZKC - Kansas City ARTCC</option>
                        <option value="ZMP">ZMP - Minneapolis ARTCC</option>
                        <option value="ZBW">ZBW - Boston ARTCC</option>
                        <option value="ZOB">ZOB - Cleveland ARTCC</option>
                        <option value="ZNY">ZNY - New York ARTCC</option>
                        <option value="ZWY">ZWY - New York OCA</option>
                        <option value="ZDC">ZDC - Washington D.C. ARTCC</option>
                        <option value="ZTL">ZTL - Atlanta ARTCC</option>
                        <option value="ZID">ZID - Indianapolis ARTCC</option>
                        <option value="ZJX">ZJX - Jacksonville ARTCC</option>
                        <option value="ZMA">ZMA - Miami ARTCC</option>
                        <option value="ZMO">ZMO - Miami OCA</option>
                        <option value="ZSU">ZSU - San Juan CERAP</option>
                        <option value="CZVR">CZVR - Vancouver FIR</option>
                        <option value="CZEG">CZEG - Edmonton FIR</option>
                        <option value="CZWG">CZWG - Winnipeg FIR</option>
                        <option value="CZYZ">CZYZ - Toronto FIR</option>
                        <option value="CZUL">CZUL - Montreal FIR</option>
                        <option value="CZQM">CZQM - Moncton FIR</option>
                        <option value="CZZV">CZZV - Sept-Iles FIR</option>
                        <option value="CZQX">CZQX - Gander FIR</option>
                        <option value="CZQO">CZQX - Gander OCA</option>
                        <option value="MUFH">MUFH - Havana FIR</option>
                        <option value="MYNN">MYNN - Nassau FIR</option>
                        <option value="MMZT">MMZT - Mazatlan FIR</option>
                        <option value="MMTY">MMTY - Monterrey FIR</option>
                        <option value="MMID">MMID - Merida FIR</option>
                    </select>

                    Position:
                    <select class="form-control" name="position_name" required>
                        <option>Traffic Management Coordinator (TMC/STMC)</option>
                        <option>Western NTMS/NTMO (VATUSA94)</option>
                        <option>South Central NTMS/NTMO (VATUSA95)</option>
                        <option>Midwestern NTMS/NTMO (VATUSA96)</option>
                        <option>Northeastern NTMS/NTMO (VATUSA97)</option>
                        <option>Southeastern NTMS/NTMO (VATUSA98)</option>
                        <option>Canada Traffic Management (VATCAN)</option>
                        <option>Mexico Traffic Management (VATMEX)</option>
                        <option>Caribbean Traffic Management (VATCAR)</option>
                        <option>Gander OCA Coordinator</option>
                        <option>Operations Planner (OP)</option>
                        <option>National Operations Manager (NOM)</option>
                        <option>Network Manager (NM)</option>
                    </select>

                    <hr>

                    Personnel Name:
                    <input type="text" class="form-control" name="personnel_name" maxlength="128" placeholder="Leave Blank for Vacancy">

                    Personnel OIs:
                    <input type="text" class="form-control" name="personnel_ois" maxlength="2" placeholder="Leave Blank for Vacancy">

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-success" value="Add">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal">Close</button>
                </div>
        </div>

        </form>

    </div>
</div>

<!-- Edit DCC Personnel Modal -->
<div class="modal fade" id="edit_dccstaffingModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Personnel</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="edit_dccstaffing">

                <div class="modal-body">

                    <input type="hidden" name="id" id="id">

                    Facility:
                    <select class="form-control" name="position_facility" id="position_facility" required>
                        <option value="DCC">DCC - Command Center</option>
                        <option value="VATCAN">VATCAN - Command Center</option>
                        <option value="ECFMP">ECFMP - EUROCONTROL</option>
                        <option value="ZLA">ZLA - Los Angeles ARTCC</option>
                        <option value="ZOA">ZOA - Oakland ARTCC</option>
                        <option value="ZAK">ZAK - Oakland OCA</option>
                        <option value="HCF">HCF - Honolulu Control Facility</option>
                        <option value="ZAN">ZAN - Anchorage ARTCC</option>
                        <option value="ZUA">ZUA - Guam CERAP</option>
                        <option value="ZLC">ZLC - Salt Lake City ARTCC</option>
                        <option value="ZSE">ZSE - Seattle ARTCC</option>
                        <option value="ZAB">ZAB - Albuquerque ARTCC</option>
                        <option value="ZFW">ZFW - Fort Worth ARTCC</option>
                        <option value="ZHU">ZHU - Houston ARTCC</option>
                        <option value="ZME">ZME - Memphis ARTCC</option>
                        <option value="ZAU">ZAU - Chicago ARTCC</option>
                        <option value="ZDV">ZDV - Denver ARTCC</option>
                        <option value="ZKC">ZKC - Kansas City ARTCC</option>
                        <option value="ZMP">ZMP - Minneapolis ARTCC</option>
                        <option value="ZBW">ZBW - Boston ARTCC</option>
                        <option value="ZOB">ZOB - Cleveland ARTCC</option>
                        <option value="ZNY">ZNY - New York ARTCC</option>
                        <option value="ZWY">ZWY - New York OCA</option>
                        <option value="ZDC">ZDC - Washington D.C. ARTCC</option>
                        <option value="ZTL">ZTL - Atlanta ARTCC</option>
                        <option value="ZID">ZID - Indianapolis ARTCC</option>
                        <option value="ZJX">ZJX - Jacksonville ARTCC</option>
                        <option value="ZMA">ZMA - Miami ARTCC</option>
                        <option value="ZMO">ZMO - Miami OCA</option>
                        <option value="ZSU">ZSU - San Juan CERAP</option>
                        <option value="CZVR">CZVR - Vancouver FIR</option>
                        <option value="CZEG">CZEG - Edmonton FIR</option>
                        <option value="CZWG">CZWG - Winnipeg FIR</option>
                        <option value="CZYZ">CZYZ - Toronto FIR</option>
                        <option value="CZUL">CZUL - Montreal FIR</option>
                        <option value="CZQM">CZQM - Moncton FIR</option>
                        <option value="CZZV">CZZV - Sept-Iles FIR</option>
                        <option value="CZQX">CZQX - Gander FIR</option>
                        <option value="CZQO">CZQX - Gander OCA</option>
                        <option value="MUFH">MUFH - Havana FIR</option>
                        <option value="MYNN">MYNN - Nassau FIR</option>
                        <option value="MMZT">MMZT - Mazatlan FIR</option>
                        <option value="MMTY">MMTY - Monterrey FIR</option>
                        <option value="MMID">MMID - Merida FIR</option>
                    </select>

                    Position:
                    <select class="form-control" name="position_name" id="position_name" required>
                        <option>Traffic Management Coordinator (TMC/STMC)</option>
                        <option>Western NTMS/NTMO (VATUSA94)</option>
                        <option>South Central NTMS/NTMO (VATUSA95)</option>
                        <option>Midwestern NTMS/NTMO (VATUSA96)</option>
                        <option>Northeastern NTMS/NTMO (VATUSA97)</option>
                        <option>Southeastern NTMS/NTMO (VATUSA98)</option>
                        <option>Canada Traffic Management (VATCAN)</option>
                        <option>Mexico Traffic Management (VATMEX)</option>
                        <option>Caribbean Traffic Management (VATCAR)</option>
                        <option>Gander OCA Coordinator</option>
                        <option>Operations Planner (OP)</option>
                        <option>National Operations Manager (NOM)</option>
                        <option>Network Manager (NM)</option>
                    </select>

                    <hr>

                    Personnel Name:
                    <input type="text" class="form-control" name="personnel_name" id="personnel_name" maxlength="128" placeholder="Leave Blank for Vacancy">

                    Personnel OIs:
                    <input type="text" class="form-control" name="personnel_ois" id="personnel_ois" maxlength="2" placeholder="Leave Blank for Vacancy">

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-warning" value="Edit">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal">Close</button>
                </div>
        </div>

        </form>

    </div>
</div>

<!-- Add Historical Modal -->
<div class="modal fade" id="addhistoricalModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Historical Data</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="addhistorical">

                <div class="modal-body">

                    <input type="hidden" name="p_id" value="<?= $id; ?>">

                    Title:
                    <input type="text" class="form-control" name="title">

                    Date:
                    <input type="text" class="form-control" id="ah_date" name="date" readonly>

                    Summary:
                    <textarea class="form-control" name="summary" rows="3"></textarea>

                    <hr>

                    Image (URL):
                    <input type="text" class="form-control" name="image_url">

                    Source (URL):
                    <input type="text" class="form-control" name="source_url">

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-success" value="Add">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal">Close</button>
                </div>
        </div>

        </form>

    </div>
</div>

<!-- Edit Historical Modal -->
<div class="modal fade" id="edithistoricalModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Historical Data</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="edithistorical">

                <div class="modal-body">

                    <input type="hidden" name="id" id="id">

                    Title:
                    <input type="text" class="form-control" name="title" id="title">

                    Date:
                    <input type="text" class="form-control" id="eh_date" name="date" readonly>

                    Summary:
                    <textarea class="form-control" name="summary" id="summary" rows="3"></textarea>

                    <hr>

                    Image (URL):
                    <input type="text" class="form-control" name="image_url" id="image_url">

                    Source (URL):
                    <input type="text" class="form-control" name="source_url" id="source_url">

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-warning" value="Edit">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal">Close</button>
                </div>
        </div>

        </form>

    </div>
</div>

<!-- Add Forecast Modal -->
<div class="modal fade" id="addforecastModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Forecast Entry</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="addforecast">

                <div class="modal-body">

                    <input type="hidden" name="p_id" value="<?= $id; ?>">

                    Date:
                    <input type="text" class="form-control" id="af_date" name="date" readonly>

                    Summary:
                    <textarea class="form-control" name="summary" rows="3"></textarea>

                    <hr>

                    Image (URL):
                    <input type="text" class="form-control" name="image_url">

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-success" value="Add">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal">Close</button>
                </div>
        </div>

        </form>

    </div>
</div>

<!-- Edit Forecast Modal -->
<div class="modal fade" id="editforecastModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Forecast Entry</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="editforecast">

                <div class="modal-body">

                    <input type="hidden" name="id" id="id">

                    Date:
                    <input type="text" class="form-control" id="ef_date" name="date" readonly>

                    Summary:
                    <textarea class="form-control" name="summary" id="summary" rows="3"></textarea>

                    <hr>

                    Image (URL):
                    <input type="text" class="form-control" name="image_url" id="image_url">

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-warning" value="Edit">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal">Close</button>
                </div>
        </div>

        </form>

    </div>
</div>

<!-- Add Terminal Initiative Modal -->
<div class="modal fade" id="addterminalinitModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Terminal Initiative</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="addterminalinit">

                <div class="modal-body">

                    <input type="hidden" name="p_id" value="<?= $id; ?>">

                    Facility/Initiative:
                    <input type="text" class="form-control" name="title" placeholder="D21/PCT - CFR/Metering/MIT">

                    Cause/Context:
                    <input type="text" class="form-control" name="context" placeholder="Volume">

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-success" value="Add">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal">Close</button>
                </div>
        </div>

        </form>

    </div>
</div>

<!-- Edit Terminal Initiative Modal -->
<div class="modal fade" id="editterminalinitModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Terminal Initiative</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="editterminalinit">

                <div class="modal-body">

                    <input type="hidden" name="id" id="id">

                    Facility/Initiative:
                    <input type="text" class="form-control" name="title" id="title" placeholder="D21/PCT - CFR/Metering/MIT">

                    Cause/Context:
                    <input type="text" class="form-control" name="context" id="context" placeholder="Volume">

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-warning" value="Edit">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal">Close</button>
                </div>
        </div>

        </form>

    </div>
</div>

<!-- Add Terminal Staffing Modal -->
<div class="modal fade" id="addtermstaffingModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Terminal Staffing Entry</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="addtermstaffing">

                <div class="modal-body">

                    <input type="hidden" name="p_id" value="<?= $id; ?>">

                    Facility Name:
                    <input type="text" class="form-control" name="facility_name" placeholder="SCT - SoCal TRACON" required>

                    Staffing Status:
                    <select class="form-control" name="staffing_status">
                        <option value="0">Unknown</option>
                        <option value="3">Understaffed</option>
                        <option value="1">Top Down</option>
                        <option value="2">Yes</option>
                        <option value="4">No</option>
                    </select>

                    Staffing Quantity:
                    <input type="text" class="form-control" name="staffing_quantity" maxlength="2" required>

                    Comments:
                    <input type="text" class="form-control" name="comments">

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-success" value="Add">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal">Close</button>
                </div>
        </div>

        </form>

    </div>
</div>

<!-- Edit Terminal Initiative Modal -->
<div class="modal fade" id="edittermstaffingModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Terminal Staffing Entry</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="edittermstaffing">

                <div class="modal-body">

                    <input type="hidden" name="id" id="id">

                    Facility Name:
                    <input type="text" class="form-control" name="facility_name" id="facility_name" placeholder="SCT - SoCal TRACON" required>

                    Staffing Status:
                    <select class="form-control" name="staffing_status" id="staffing_status">
                        <option value="0">Unknown</option>
                        <option value="3">Understaffed</option>
                        <option value="1">Top Down</option>
                        <option value="2">Yes</option>
                        <option value="4">No</option>
                    </select>

                    Staffing Quantity:
                    <input type="text" class="form-control" name="staffing_quantity" id="staffing_quantity" maxlength="2" required>

                    Comments:
                    <input type="text" class="form-control" name="comments" id="comments">

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-warning" value="Edit">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal">Close</button>
                </div>
        </div>

        </form>

    </div>
</div>

<!-- Add Config Modal -->
<div class="modal fade" id="addconfigModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Config Entry</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="addconfig">

                <div class="modal-body">

                    <input type="hidden" name="p_id" value="<?= $id; ?>">

                    Field:
                    <input type="text" class="form-control" name="airport" placeholder="BWI" maxlength="4" required>

                    Meteorological Condition:
                    <select class="form-control" name="weather">
                        <option value="0">Unknown</option>
                        <option value="1">VMC</option>
                        <option value="2">LVMC</option>
                        <option value="3">IMC</option>
                        <option value="4">LIMC</option>
                    </select>

                    Arrival Runways:
                    <input type="text" class="form-control" name="arrive" placeholder="33L/33R">

                    Departure Runways:
                    <input type="text" class="form-control" name="depart" placeholder="33R/28">

                    Airport Arrival Rate (AAR):
                    <input type="text" class="form-control" name="aar" maxlength="3">

                    Airport Departure Rate (ADR):
                    <input type="text" class="form-control" name="adr" maxlength="3">

                    Comments:
                    <input type="text" class="form-control" name="comments">

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-success" value="Add">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal">Close</button>
                </div>
        </div>

        </form>

    </div>
</div>

<!-- Edit Config Modal -->
<div class="modal fade" id="editconfigModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Config Entry</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="editconfig">

                <div class="modal-body">

                    <input type="hidden" name="id" id="id">

                    Field:
                    <input type="text" class="form-control" name="airport" id="airport" placeholder="BWI" maxlength="4" required>

                    Meteorological Condition:
                    <select class="form-control" name="weather" id="weather">
                        <option value="0">Unknown</option>
                        <option value="1">VMC</option>
                        <option value="2">LVMC</option>
                        <option value="3">IMC</option>
                        <option value="4">LIMC</option>
                    </select>

                    Arrival Runways:
                    <input type="text" class="form-control" name="arrive" id="arrive" placeholder="33L/33R">

                    Departure Runways:
                    <input type="text" class="form-control" name="depart" id="depart" placeholder="33R/28">

                    Airport Arrival Rate (AAR):
                    <input type="text" class="form-control" name="aar" id="aar" maxlength="3">

                    Airport Departure Rate (ADR):
                    <input type="text" class="form-control" name="adr" id="adr" maxlength="3">

                    Comments:
                    <input type="text" class="form-control" name="comments" id="comments">

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-warning" value="Edit">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal">Close</button>
                </div>
        </div>

        </form>

    </div>
</div>

<!-- Add Terminal Planning Modal -->
<div class="modal fade" id="addtermplanningModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Terminal Planning Entry</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="addtermplanning">

                <div class="modal-body">

                    <input type="hidden" name="p_id" value="<?= $id; ?>">

                    Facility Name:
                    <input type="text" class="form-control" name="facility_name" required>

                    Comments:
                    <textarea class="form-control rounded-0" name="comments" id="atp_comments" rows="5"></textarea>

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-success" value="Add">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal">Close</button>
                </div>
        </div>

        </form>

    </div>
</div>

<!-- Edit Terminal Planning Modal -->
<div class="modal fade" id="edittermplanningModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Terminal Planning Entry</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="edittermplanning">

                <div class="modal-body">

                    <input type="hidden" name="id" id="id">

                    Facility Name:
                    <input type="text" class="form-control" name="facility_name" id="facility_name" required>

                    Comments:
                    <textarea class="form-control rounded-0" name="comments" id="etp_comments" rows="5"></textarea>

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-warning" value="Edit">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal">Close</button>
                </div>
        </div>

        </form>

    </div>
</div>

<!-- Add Terminal Constraint Modal -->
<div class="modal fade" id="addtermconstraintModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Terminal Constraint</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="addtermconstraint">

                <div class="modal-body">

                    <input type="hidden" name="p_id" value="<?= $id; ?>">

                    Location:
                    <input type="text" class="form-control" name="location" placeholder="DTW/IAD/BWI">

                    Cause/Context:
                    <input type="text" class="form-control" name="context" placeholder="WX">

                    Through (Date):
                    <input type="text" class="form-control" name="date" id="at_date" readonly>

                    Impact:
                    <input type="text" class="form-control" name="impact" placeholder="Reduced AAR">

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-success" value="Add">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal">Close</button>
                </div>
        </div>

        </form>

    </div>
</div>

<!-- Edit Terminal Constraint Modal -->
<div class="modal fade" id="edittermconstraintModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Terminal Constraint</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="edittermconstraint">

                <div class="modal-body">

                    <input type="hidden" name="id" id="id">

                    Location:
                    <input type="text" class="form-control" name="location" id="location" placeholder="DTW/IAD/BWI">

                    Cause/Context:
                    <input type="text" class="form-control" name="context" id="context" placeholder="WX">

                    Through (Date):
                    <input type="text" class="form-control" name="date" id="et_date" readonly>

                    Impact:
                    <input type="text" class="form-control" name="impact" id="impact" placeholder="Reduced AAR">

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-warning" value="Edit">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal">Close</button>
                </div>
        </div>

        </form>

    </div>
</div>


<!-- Add Enroute Initiative Modal -->
<div class="modal fade" id="addenrouteinitModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Enroute Initiative</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="addenrouteinit">

                <div class="modal-body">

                    <input type="hidden" name="p_id" value="<?= $id; ?>">

                    Facility/Initiative:
                    <input type="text" class="form-control" name="title" placeholder="ZOB/ZDC/ZNY - Reroutes">

                    Cause/Context:
                    <input type="text" class="form-control" name="context" placeholder="Structure">

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-success" value="Add">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal">Close</button>
                </div>
        </div>

        </form>

    </div>
</div>

<!-- Edit Enroute Initiative Modal -->
<div class="modal fade" id="editenrouteinitModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Enroute Initiative</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="editenrouteinit">

                <div class="modal-body">

                    <input type="hidden" name="id" id="id">

                    Facility/Initiative:
                    <input type="text" class="form-control" name="title" id="title" placeholder="ZOB/ZDC/ZNY - Reroutes">

                    Cause/Context:
                    <input type="text" class="form-control" name="context" id="context" placeholder="Structure">

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-warning" value="Edit">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal">Close</button>
                </div>
        </div>

        </form>

    </div>
</div>

<!-- Add Enroute Staffing Modal -->
<div class="modal fade" id="addenroutestaffingModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Enroute Staffing Entry</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="addenroutestaffing">

                <div class="modal-body">

                    <input type="hidden" name="p_id" value="<?= $id; ?>">

                    Facility Name:
                    <select class="form-control" name="facility_name" required>
                        <option>ZLA - Los Angeles ARTCC</option>
                        <option>ZOA - Oakland ARTCC</option>
                        <option>ZAK - Oakland OCA</option>
                        <option>HCF - Honolulu Control Facility</option>
                        <option>ZAN - Anchorage ARTCC</option>
                        <option>ZUA - Guam CERAP</option>
                        <option>ZLC - Salt Lake City ARTCC</option>
                        <option>ZSE - Seattle ARTCC</option>
                        <option>ZAB - Albuquerque ARTCC</option>
                        <option>ZFW - Fort Worth ARTCC</option>
                        <option>ZHU - Houston ARTCC</option>
                        <option>ZME - Memphis ARTCC</option>
                        <option>ZAU - Chicago ARTCC</option>
                        <option>ZDV - Denver ARTCC</option>
                        <option>ZKC - Kansas City ARTCC</option>
                        <option>ZMP - Minneapolis ARTCC</option>
                        <option>ZBW - Boston ARTCC</option>
                        <option>ZOB - Cleveland ARTCC</option>
                        <option>ZNY - New York ARTCC</option>
                        <option>ZWY - New York OCA</option>
                        <option>ZDC - Washington D.C. ARTCC</option>
                        <option>ZTL - Atlanta ARTCC</option>
                        <option>ZID - Indianapolis ARTCC</option>
                        <option>ZJX - Jacksonville ARTCC</option>
                        <option>ZMA - Miami ARTCC</option>
                        <option>ZMO - Miami OCA</option>
                        <option>ZSU - San Juan CERAP</option>
                        <option>CZVR - Vancouver FIR</option>
                        <option>CZEG - Edmonton FIR</option>
                        <option>CZWG - Winnipeg FIR</option>
                        <option>CZYZ - Toronto FIR</option>
                        <option>CZUL - Montreal FIR</option>
                        <option>CZQM - Moncton FIR</option>
                        <option>CZZV - Sept-Iles FIR</option>
                        <option>CZQX - Gander FIR</option>
                        <option>CZQX - Gander OCA</option>
                        <option>MUFH - Havana FIR</option>
                        <option>MYNN - Nassau FIR</option>
                        <option>MMZT - Mazatlan FIR</option>
                        <option>MMTY - Monterrey FIR</option>
                        <option>MMID - Merida FIR</option>
                    </select>

                    Staffing Status:
                    <select class="form-control" name="staffing_status">
                        <option value="0">Unknown</option>
                        <option value="2">Understaffed</option>
                        <option value="1">Yes</option>
                        <option value="3">No</option>
                    </select>

                    Staffing Quantity:
                    <input type="text" class="form-control" name="staffing_quantity" maxlength="2" required>

                    Comments:
                    <input type="text" class="form-control" name="comments">

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-success" value="Add">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal">Close</button>
                </div>
        </div>

        </form>

    </div>
</div>

<!-- Edit Enroute Staffing Modal -->
<div class="modal fade" id="editenroutestaffingModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Enroute Staffing Entry</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="editenroutestaffing">

                <div class="modal-body">

                    <input type="hidden" name="id" id="id">

                    Facility Name:
                    <select class="form-control" name="facility_name" id="facility_name" required>
                        <option>ZLA - Los Angeles ARTCC</option>
                        <option>ZOA - Oakland ARTCC</option>
                        <option>ZAK - Oakland OCA</option>
                        <option>HCF - Honolulu Control Facility</option>
                        <option>ZAN - Anchorage ARTCC</option>
                        <option>ZUA - Guam CERAP</option>
                        <option>ZLC - Salt Lake City ARTCC</option>
                        <option>ZSE - Seattle ARTCC</option>
                        <option>ZAB - Albuquerque ARTCC</option>
                        <option>ZFW - Fort Worth ARTCC</option>
                        <option>ZHU - Houston ARTCC</option>
                        <option>ZME - Memphis ARTCC</option>
                        <option>ZAU - Chicago ARTCC</option>
                        <option>ZDV - Denver ARTCC</option>
                        <option>ZKC - Kansas City ARTCC</option>
                        <option>ZMP - Minneapolis ARTCC</option>
                        <option>ZBW - Boston ARTCC</option>
                        <option>ZOB - Cleveland ARTCC</option>
                        <option>ZNY - New York ARTCC</option>
                        <option>ZWY - New York OCA</option>
                        <option>ZDC - Washington D.C. ARTCC</option>
                        <option>ZTL - Atlanta ARTCC</option>
                        <option>ZID - Indianapolis ARTCC</option>
                        <option>ZJX - Jacksonville ARTCC</option>
                        <option>ZMA - Miami ARTCC</option>
                        <option>ZMO - Miami OCA</option>
                        <option>ZSU - San Juan CERAP</option>
                        <option>CZVR - Vancouver FIR</option>
                        <option>CZEG - Edmonton FIR</option>
                        <option>CZWG - Winnipeg FIR</option>
                        <option>CZYZ - Toronto FIR</option>
                        <option>CZUL - Montreal FIR</option>
                        <option>CZQM - Moncton FIR</option>
                        <option>CZZV - Sept-Iles FIR</option>
                        <option>CZQX - Gander FIR</option>
                        <option>CZQX - Gander OCA</option>
                        <option>MUFH - Havana FIR</option>
                        <option>MYNN - Nassau FIR</option>
                        <option>MMZT - Mazatlan FIR</option>
                        <option>MMTY - Monterrey FIR</option>
                        <option>MMID - Merida FIR</option>
                    </select>

                    Staffing Status:
                    <select class="form-control" name="staffing_status" id="staffing_status">
                        <option value="0">Unknown</option>
                        <option value="2">Understaffed</option>
                        <option value="1">Yes</option>
                        <option value="3">No</option>
                    </select>

                    Staffing Quantity:
                    <input type="text" class="form-control" name="staffing_quantity" id="staffing_quantity" maxlength="2" required>

                    Comments:
                    <input type="text" class="form-control" name="comments" id="comments">

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-warning" value="Edit">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal">Close</button>
                </div>
        </div>

        </form>

    </div>
</div>

<!-- Add Enroute Planning Modal -->
<div class="modal fade" id="addenrouteplanningModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Enroute Planning Entry</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="addenrouteplanning">

                <div class="modal-body">

                    <input type="hidden" name="p_id" value="<?= $id; ?>">

                    Facility Name:
                    <select class="form-control" name="facility_name" id="facility_name" required>
                        <option>ZLA - Los Angeles ARTCC</option>
                        <option>ZOA - Oakland ARTCC</option>
                        <option>ZAK - Oakland OCA</option>
                        <option>HCF - Honolulu Control Facility</option>
                        <option>ZAN - Anchorage ARTCC</option>
                        <option>ZUA - Guam CERAP</option>
                        <option>ZLC - Salt Lake City ARTCC</option>
                        <option>ZSE - Seattle ARTCC</option>
                        <option>ZAB - Albuquerque ARTCC</option>
                        <option>ZFW - Fort Worth ARTCC</option>
                        <option>ZHU - Houston ARTCC</option>
                        <option>ZME - Memphis ARTCC</option>
                        <option>ZAU - Chicago ARTCC</option>
                        <option>ZDV - Denver ARTCC</option>
                        <option>ZKC - Kansas City ARTCC</option>
                        <option>ZMP - Minneapolis ARTCC</option>
                        <option>ZBW - Boston ARTCC</option>
                        <option>ZOB - Cleveland ARTCC</option>
                        <option>ZNY - New York ARTCC</option>
                        <option>ZWY - New York OCA</option>
                        <option>ZDC - Washington D.C. ARTCC</option>
                        <option>ZTL - Atlanta ARTCC</option>
                        <option>ZID - Indianapolis ARTCC</option>
                        <option>ZJX - Jacksonville ARTCC</option>
                        <option>ZMA - Miami ARTCC</option>
                        <option>ZMO - Miami OCA</option>
                        <option>ZSU - San Juan CERAP</option>
                        <option>CZVR - Vancouver FIR</option>
                        <option>CZEG - Edmonton FIR</option>
                        <option>CZWG - Winnipeg FIR</option>
                        <option>CZYZ - Toronto FIR</option>
                        <option>CZUL - Montreal FIR</option>
                        <option>CZQM - Moncton FIR</option>
                        <option>CZZV - Sept-Iles FIR</option>
                        <option>CZQX - Gander FIR</option>
                        <option>CZQX - Gander OCA</option>
                        <option>MUFH - Havana FIR</option>
                        <option>MYNN - Nassau FIR</option>
                        <option>MMZT - Mazatlan FIR</option>
                        <option>MMTY - Monterrey FIR</option>
                        <option>MMID - Merida FIR</option>
                    </select>

                    Comments:
                    <textarea class="form-control rounded-0" name="comments" id="aep_comments" rows="5"></textarea>

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-success" value="Add">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal">Close</button>
                </div>
        </div>

        </form>

    </div>
</div>

<!-- Edit Enroute Planning Modal -->
<div class="modal fade" id="editenrouteplanningModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Enroute Planning Entry</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="editenrouteplanning">

                <div class="modal-body">

                    <input type="hidden" name="id" id="id">

                    Facility Name:
                    <select class="form-control" name="facility_name" id="facility_name" required>
                        <option>ZLA - Los Angeles ARTCC</option>
                        <option>ZOA - Oakland ARTCC</option>
                        <option>ZAK - Oakland OCA</option>
                        <option>HCF - Honolulu Control Facility</option>
                        <option>ZAN - Anchorage ARTCC</option>
                        <option>ZUA - Guam CERAP</option>
                        <option>ZLC - Salt Lake City ARTCC</option>
                        <option>ZSE - Seattle ARTCC</option>
                        <option>ZAB - Albuquerque ARTCC</option>
                        <option>ZFW - Fort Worth ARTCC</option>
                        <option>ZHU - Houston ARTCC</option>
                        <option>ZME - Memphis ARTCC</option>
                        <option>ZAU - Chicago ARTCC</option>
                        <option>ZDV - Denver ARTCC</option>
                        <option>ZKC - Kansas City ARTCC</option>
                        <option>ZMP - Minneapolis ARTCC</option>
                        <option>ZBW - Boston ARTCC</option>
                        <option>ZOB - Cleveland ARTCC</option>
                        <option>ZNY - New York ARTCC</option>
                        <option>ZWY - New York OCA</option>
                        <option>ZDC - Washington D.C. ARTCC</option>
                        <option>ZTL - Atlanta ARTCC</option>
                        <option>ZID - Indianapolis ARTCC</option>
                        <option>ZJX - Jacksonville ARTCC</option>
                        <option>ZMA - Miami ARTCC</option>
                        <option>ZMO - Miami OCA</option>
                        <option>ZSU - San Juan CERAP</option>
                        <option>CZVR - Vancouver FIR</option>
                        <option>CZEG - Edmonton FIR</option>
                        <option>CZWG - Winnipeg FIR</option>
                        <option>CZYZ - Toronto FIR</option>
                        <option>CZUL - Montreal FIR</option>
                        <option>CZQM - Moncton FIR</option>
                        <option>CZZV - Sept-Iles FIR</option>
                        <option>CZQX - Gander FIR</option>
                        <option>CZQX - Gander OCA</option>
                        <option>MUFH - Havana FIR</option>
                        <option>MYNN - Nassau FIR</option>
                        <option>MMZT - Mazatlan FIR</option>
                        <option>MMTY - Monterrey FIR</option>
                        <option>MMID - Merida FIR</option>
                    </select>

                    Comments:
                    <textarea class="form-control rounded-0" name="comments" id="eep_comments" rows="5"></textarea>

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-warning" value="Edit">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal">Close</button>
                </div>
        </div>

        </form>

    </div>
</div>

<!-- Add Enroute Constraint Modal -->
<div class="modal fade" id="addenrouteconstraintModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Enroute Constraint</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="addenrouteconstraint">

                <div class="modal-body">

                    <input type="hidden" name="p_id" value="<?= $id; ?>">

                    Location:
                    <input type="text" class="form-control" name="location" placeholder="ZJX/ZMA/ZHU">

                    Cause/Context:
                    <input type="text" class="form-control" name="context" placeholder="WX">

                    Through (Date):
                    <input type="text" class="form-control" name="date" id="ae_date" readonly>

                    Impact:
                    <input type="text" class="form-control" name="impact" placeholder="Reduced AAR">

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-success" value="Add">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal">Close</button>
                </div>
        </div>

        </form>

    </div>
</div>

<!-- Edit Enroute Constraint Modal -->
<div class="modal fade" id="editenrouteconstraintModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Enroute Constraint</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="editenrouteconstraint">

                <div class="modal-body">

                    <input type="hidden" name="id" id="id">

                    Location:
                    <input type="text" class="form-control" name="location" id="location" placeholder="ZJX/ZMA/ZHU">

                    Cause/Context:
                    <input type="text" class="form-control" name="context" id="context" placeholder="WX">

                    Through (Date):
                    <input type="text" class="form-control" name="date" id="ee_date" readonly>

                    Impact:
                    <input type="text" class="form-control" name="impact" id="impact" placeholder="Reduced AAR">

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-warning" value="Edit">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal">Close</button>
                </div>
        </div>

        </form>

    </div>
</div>

<!-- Add Group Flight Modal -->
<div class="modal fade" id="addgroupflightModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Group Flight Entry</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="addgroupflight">

                <div class="modal-body">

                    <input type="hidden" name="p_id" value="<?= $id; ?>">

                    Group/Entity Name:
                    <input type="text" class="form-control" name="entity" required>

                    Departure Field:
                    <input type="text" class="form-control" name="dep" placeholder="DTW" maxlength="4" required>

                    Arrival Field:
                    <input type="text" class="form-control" name="arr" placeholder="BWI" maxlength="4" required>

                    Estimated Time of Departure (ETD, in Zulu):
                    <input type="text" class="form-control" name="etd" placeholder="2300" maxlength="4">

                    Estimated Time of Arrival (ETA, in Zulu):
                    <input type="text" class="form-control" name="eta" placeholder="0100" maxlength="4">

                    Pilot Quantity:
                    <input type="text" class="form-control" name="pilot_quantity" placeholder="5" value="0" maxlength="2" required>

                    Route:
                    <textarea class="form-control" name="route" id="route" rows="3"></textarea>

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-success" value="Add">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal">Close</button>
                </div>
        </div>

        </form>

    </div>
</div>

<!-- Edit Group Flight Modal -->
<div class="modal fade" id="editgroupflightModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Group Flight Entry</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="editgroupflight">

                <div class="modal-body">

                    <input type="hidden" name="id" id="id">

                    Group/Entity Name:
                    <input type="text" class="form-control" name="entity" id="entity" required>

                    Departure Field:
                    <input type="text" class="form-control" name="dep" id="dep" placeholder="DTW" maxlength="4" required>

                    Arrival Field:
                    <input type="text" class="form-control" name="arr" id="arr" placeholder="BWI" maxlength="4" required>

                    Estimated Time of Departure (ETD, in Zulu):
                    <input type="text" class="form-control" name="etd" id="etd" placeholder="2300" maxlength="4">

                    Estimated Time of Arrival (ETA, in Zulu):
                    <input type="text" class="form-control" name="eta" id="eta" placeholder="0100" maxlength="4">

                    Pilot Quantity:
                    <input type="text" class="form-control" name="pilot_quantity" id="pilot_quantity" placeholder="5" maxlength="2" required>

                    Route:
                    <textarea class="form-control" name="route" id="route" rows="3"></textarea>

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-warning" value="Edit">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal">Close</button>
                </div>
        </div>

        </form>

    </div>
</div>
<?php } ?>

<!-- PERTI Discord Notification Modal -->
<div class="modal fade" id="pertiModal" tabindex="-1" role="dialog" aria-labelledby="pertiModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="pertiModalLabel">PERTI Discord Notification</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">

            <!-- Start / End date & time (all UTC) -->
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label for="pertiStartDate">Start Date (UTC)</label>
                    <input type="date" class="form-control form-control-sm" id="pertiStartDate">
                    <small class="form-text text-muted">
                        Defaults from event date.
                    </small>
                </div>
                <div class="form-group col-md-6">
                    <label for="pertiStartTime">Start Time (UTC)</label>
                    <input type="text" class="form-control form-control-sm" id="pertiStartTime" placeholder="e.g. 1800">
                    <small class="form-text text-muted">
                        Defaults from event start time.
                    </small>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label for="pertiEndDate">End Date (UTC)</label>
                    <input type="date" class="form-control form-control-sm" id="pertiEndDate">
                    <small class="form-text text-muted">
                        Defaults from event end date, or calculated from start if not set.
                    </small>
                </div>
                <div class="form-group col-md-6">
                    <label for="pertiEndTime">End Time (UTC)</label>
                    <input type="text" class="form-control form-control-sm" id="pertiEndTime" placeholder="e.g. 0300">
                    <small class="form-text text-muted">
                        Defaults from event end time. Used for Discord timestamps.
                    </small>
                </div>
            </div>

                <!-- Facilities selector -->
                <div class="form-group mt-3">
                    <label class="small mb-0" for="advFacilities">Facilities Included</label>
                    <div class="adv-facilities-wrapper">
                        <div class="input-group input-group-sm">
                            <input type="text"
                                   class="form-control form-control-sm"
                                   id="advFacilities"
                                   placeholder="ZTL/ZDC/ZNY">
                            <div class="input-group-append">
                                <button class="btn btn-outline-secondary" type="button" id="advFacilitiesToggle">
                                    Select
                                </button>
                            </div>
                        </div>
                        <div id="advFacilitiesDropdown" class="adv-facilities-dropdown">
                            <div class="adv-facilities-grid" id="advFacilitiesGrid">
                                <!-- Populated by JS -->
                            </div>
                            <div class="mt-2 d-flex justify-content-between align-items-center">
                                <div>
                                    <button type="button" class="btn btn-sm btn-light mr-2" id="advFacilitiesClear">Clear</button>
                                    <button type="button" class="btn btn-sm btn-light mr-2" id="advFacilitiesSelectAll">All</button>
                                    <button type="button" class="btn btn-sm btn-light" id="advFacilitiesSelectUs">US ARTCCs</button>
                                </div>
                                <div>
                                    <button type="button" class="btn btn-sm btn-primary" id="advFacilitiesApply">Apply</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Final message -->
                <div class="form-group mt-3">
                    <label for="pertiMessage">Notification Text (copy into Discord)</label>
                    <textarea class="form-control" id="pertiMessage" rows="20" style="font-family: Menlo, Consolas, monospace;"></textarea>
                </div>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal">Close</button>
                <button type="button" class="btn btn-sm btn-outline-primary" id="pertiCopyBtn">Copy to Clipboard</button>
            </div>
        </div>
    </div>
</div>
<!-- End PERTI Discord Notification Modal -->


<!-- Operations Plan Advisory Modal -->
<div class="modal fade" id="opsPlanModal" tabindex="-1" role="dialog" aria-labelledby="opsPlanModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="opsPlanModalLabel">Operations Plan Advisory</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="container-fluid">
                    <div class="form-row">
                        <div class="form-group col-md-3">
                            <label for="opsAdvNum">Advisory Number</label>
                            <input type="text" class="form-control form-control-sm" id="opsAdvNum" placeholder="e.g. 001">
                        </div>
                        <div class="form-group col-md-5">
                            <label for="opsAdvDate">Advisory Date (UTC, mm/dd/yyyy)</label>
                            <input type="text" class="form-control form-control-sm" id="opsAdvDate" placeholder="mm/dd/yyyy">
                            <small class="form-text text-muted">
                                Defaults from event date if left blank.
                            </small>
                        </div>
                    </div>

                    <hr class="my-2">

                    <div class="form-row">
                        <div class="form-group col-md-3">
                            <label for="opsStartDate">Start Date (UTC)</label>
                            <input type="date" class="form-control form-control-sm" id="opsStartDate">
                        </div>
                        <div class="form-group col-md-3">
                            <label for="opsStartTime">Start Time (UTC)</label>
                            <input type="text" class="form-control form-control-sm" id="opsStartTime" placeholder="e.g. 2359">
                        </div>
                        <div class="form-group col-md-3">
                            <label for="opsEndDate">End Date (UTC)</label>
                            <input type="date" class="form-control form-control-sm" id="opsEndDate">
                        </div>
                        <div class="form-group col-md-3">
                            <label for="opsEndTime">End Time (UTC)</label>
                            <input type="text" class="form-control form-control-sm" id="opsEndTime" placeholder="e.g. 0400">
                        </div>
                    </div>

                    <div class="form-group mt-2">
                        <label for="opsNarrative">Narrative</label>
                        <textarea class="form-control" id="opsNarrative" rows="4"></textarea>
                        <small class="form-text text-muted">
                            This text populates the NARRATIVE section of the advisory.
                        </small>
                    </div>

                    <div class="form-group mt-2">
                        <label for="opsPlanMessage">Operations Plan Advisory Text</label>
                        <small class="form-text text-muted mb-1">
                            Auto-generated from the PERTI plan. If the text exceeds Discord’s 2000-character limit, it will be split into multiple parts labeled <code>(PART 1 OF N)</code>, <code>(PART 2 OF N)</code>, etc.
                        </small>
                        <textarea class="form-control" id="opsPlanMessage" rows="18" style="font-family: Menlo, Consolas, monospace;"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal">Close</button>
                <button type="button" class="btn btn-sm btn-outline-primary" id="opsPlanCopyBtn">Copy to Clipboard</button>
            </div>
        </div>
    </div>
</div>
<!-- End Operations Plan Advisory Modal -->

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
<!-- End Advisory Organization Config Modal -->

<!-- Insert advisory-config.js Script -->
<script src="assets/js/advisory-config.js"></script>

<!-- Insert plan.js Script -->
<script src="assets/js/plan.js"></script>

<!-- Insert Initiative Timeline Script -->
<script src="assets/js/initiative_timeline.js"></script>
<script>
    // Initialize Initiative Timelines when DOM is ready
    $(function() {
        // Terminal Initiatives Timeline
        if (document.getElementById('term_inits_timeline')) {
            window.termInitTimeline = new InitiativeTimeline({
                type: 'terminal',
                containerId: 'term_inits_timeline',
                planId: PERTI_PLAN_ID,
                eventStart: PERTI_EVENT_START_ISO,
                eventEnd: PERTI_EVENT_END_ISO,
                hasPerm: PERTI_HAS_PERM
            });
        }
        
        // En Route Initiatives Timeline
        if (document.getElementById('enroute_inits_timeline')) {
            window.enrouteInitTimeline = new InitiativeTimeline({
                type: 'enroute',
                containerId: 'enroute_inits_timeline',
                planId: PERTI_PLAN_ID,
                eventStart: PERTI_EVENT_START_ISO,
                eventEnd: PERTI_EVENT_END_ISO,
                hasPerm: PERTI_HAS_PERM
            });
        }
    });
</script>

<script>
    function wrapTo68(line) {
        line = line.trim();
        if (!line) return [];
        var words = line.split(/\s+/);
        var out = [];
        var current = '';

        for (var i = 0; i < words.length; i++) {
            var w = words[i];
            if (!w) continue;
            if (!current) {
                current = w;
            } else if (current.length + 1 + w.length > 68) {
                out.push(current);
                current = w;
            } else {
                current += ' ' + w;
            }
        }
        if (current) out.push(current);
        return out;
    }

    function buildAdvzyText() {
        var num       = (document.getElementById('advzyNumber').value || '').trim();
        var facility  = (document.getElementById('advzyFacility').value || '').trim();
        var dateStr   = (document.getElementById('advzyDate').value || '').trim();
        var typeName  = (document.getElementById('advzyType').value || '').trim();
        var validFrom = (document.getElementById('advzyValidFrom').value || '').trim();
        var validTo   = (document.getElementById('advzyValidTo').value || '').trim();
        var body      = (document.getElementById('advzyBody').value || '').trim();
        var effFrom   = (document.getElementById('advzyEffFrom').value || '').trim();
        var effTo     = (document.getElementById('advzyEffTo').value || '').trim();
        var signature = (document.getElementById('advzySignature').value || '').trim();

        // Default advisory date from event date if empty
        if (!dateStr && typeof PERTI_EVENT_DATE !== 'undefined' && PERTI_EVENT_DATE) {
            var parts = PERTI_EVENT_DATE.split('-'); // 'YYYY-MM-DD'
            if (parts.length === 3) {
                var mm0 = parts[1].padStart(2, '0');
                var dd0 = parts[2].padStart(2, '0');
                var yyyy0 = parts[0];
                dateStr = mm0 + '/' + dd0 + '/' + yyyy0;
                document.getElementById('advzyDate').value = dateStr;
            }
        }

        // Advisory number: zero-pad to 3 digits if numeric
        if (num && /^\d+$/.test(num)) {
            num = num.padStart(3, '0');
        }

        // Date: allow mm/dd/yyyy or mm/dd/yy; header shows mm/dd/yy
        var headerDate = dateStr;
        var m = dateStr.match(/^(\d{1,2})\/(\d{1,2})\/(\d{2,4})$/);
        if (m) {
            var mm = m[1].padStart(2, '0');
            var dd = m[2].padStart(2, '0');
            var yyyyOrYY = m[3];
            var yy = yyyyOrYY.length === 4 ? yyyyOrYY.slice(-2) : yyyyOrYY.padStart(2, '0');
            headerDate = mm + '/' + dd + '/' + yy;
        }

        // Default type if blank
        if (!typeName) {
            typeName = 'OPERATIONS PLAN';
            document.getElementById('advzyType').value = typeName;
        }

        // Line 1: ATCSCC ADVZY ### [FACILITY] mm/dd/yy TYPE
        var line1Parts = ['vATCSCC', 'ADVZY'];
        if (num) {
            line1Parts.push(num);
        } else {
            line1Parts.push('###');
        }
        if (facility) {
            line1Parts.push(facility);
        }
        if (headerDate) {
            line1Parts.push(headerDate);
        }
        if (typeName) {
            line1Parts.push(typeName.toUpperCase());
        }

        var lines = [];
        lines.push(line1Parts.join(' '));

        // VALID FOR line (optional)
        if (validFrom && validTo) {
            lines.push('VALID FOR ' + validFrom + ' THRU ' + validTo);
        }

        // Body: wrap to 68 chars per line, uppercase
        if (body) {
            var bodyLines = body.split(/\r?\n/);
            for (var i = 0; i < bodyLines.length; i++) {
                var chunk = bodyLines[i];
                var wrapped = wrapTo68(chunk);
                for (var j = 0; j < wrapped.length; j++) {
                    lines.push(wrapped[j].toUpperCase());
                }
            }
        }

        // Effective time line: ddhhmm-ddhhmm
        if (effFrom && effTo) {
            lines.push(effFrom + '-' + effTo);
        }

        // Default signature if empty
        if (!signature) {
            var now = new Date();
            var yy = String(now.getUTCFullYear()).slice(-2);
            var mm2 = String(now.getUTCMonth() + 1).padStart(2, '0');
            var dd2 = String(now.getUTCDate()).padStart(2, '0');
            var hh = String(now.getUTCHours()).padStart(2, '0');
            var mi = String(now.getUTCMinutes()).padStart(2, '0');
            signature = yy + '/' + mm2 + '/' + dd2 + ' ' + hh + ':' + mi + ' DCC';
            document.getElementById('advzySignature').value = signature;
        }

        lines.push(signature);

        document.getElementById('advzyMessage').value = lines.join('\n');
    }

    $(function () {
        $('#advzyBuildBtn').on('click', function () {
            buildAdvzyText();
        });

        $('#advzyCopyBtn').on('click', function () {
            var ta = document.getElementById('advzyMessage');
            if (!ta) return;
            ta.focus();
            ta.select();
            try {
                document.execCommand('copy');
            } catch (e) {
                // ignore
            }
        });
    });
</script>

</html>
