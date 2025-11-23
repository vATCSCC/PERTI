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
        include("load/header.php");
    ?>

    <script>
        function tooltips() {
            $('[data-toggle="tooltip"]').tooltip('dispose');

            $(function () {
                $('[data-toggle="tooltip"]').tooltip()
            }); 
        }
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
                    <li><a class="nav-link rounded" data-toggle="tab" href="#t_constraints">Terminal Constraints</a></li>
                    <hr>
                    <li><a class="nav-link rounded" data-toggle="tab" href="#e_initiatives">En-Route Initiatives</a></li>
                    <li><a class="nav-link rounded" data-toggle="tab" href="#e_staffing">En-Route Staffing</a></li>
                    <li><a class="nav-link rounded" data-toggle="tab" href="#e_planning">En-Route Planning</a></li>
                    <li><a class="nav-link rounded" data-toggle="tab" href="#e_constraints">En-Route Constraints</a></li>
                    <hr>
                    <li><a class="nav-link rounded" data-toggle="tab" href="#group_flights">Group Flights</a></li>
                    <li><a class="nav-link rounded" data-toggle="tab" href="#outlook">Extended Outlook</a></li>
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
                                            <td><b>Event Date</b></td>
                                            <td><?= $plan_info['event_date']; ?></td>
                                        </tr>
                                        <tr>
                                            <td><b>Event Start</b></td>
                                            <td><?= $plan_info['event_start']; ?>Z</td>
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

                                <h4><b>Resources</b></h4>
                                <ul>
                                    <li>TMU Dashboard for operational status and real-time issue tracking <a href="https://vats.im/vATCSCC_TMU_Dashboard" target="_blank">here</a>.</li>
                                    <li>JATOC AWO Incident Monitor for incident management and real-time tracking <a href="https://vats.im/JATOC" target="_blank">here</a>.</li>
                                    <li>vATCSCC Discord <a href="https://discord.com/channels/358264961233059843/358295136398082048/" target="_blank">#ntml</a> and <a href="https://discord.com/channels/358264961233059843/358300240236773376/" target="_blank">#advisories</a> for TMI data logging.</li>
                                    <li>VATUSA NTOS for public-facing, real-time TMI notices <a href="https://www.vatusa.net/mgt/tmu#notices" target="_blank">here</a>.
                                        <ul><li><b>ALL</b> NTOS entries must be accompanied by an NTML entry.</li></ul></li>
                                    <li>VATUSA <a href="ts3server://ts.vatusa.net" target="_blank">TeamSpeak</a>, <span class="text-danger"><b><?= $plan_info['hotline']; ?></b></span> Hotline for real-time operational coordination.
                                        <ul><li>Any credentials in use will be posted in the #advisories channel in the vATCSCC Discord.</li>
                                        <li>The VATCAN <a href="ts3server://ts.vatcan.ca" target="_blank">TeamSpeak</a>, TMU Hang channel will serve as a primary backup if the VATUSA TeamSpeak fails.</li>
                                        <li>The vATCSCC Discord, <?= $plan_info['hotline']; ?> Hotline voice channel will serve as a secondary backup.</li></ul></li>
                                    <li>Post any known virtual airline/group flight entries into <a href="https://bit.ly/NTML_Entry" target="_blank">this form</a>.</li>
                                    <li>Monthly & Current Traffic Dashboards:
                                        <ul>
                                            <li><a href="https://vats.im/dcc/VATUSA_Traffic_Dashboard" target="_blank">https://vats.im/dcc/VATUSA_Traffic_Dashboard</a></li>
                                            <li><a href="https://vats.im/dcc/Current_Traffic_Dashboard" target="_blank">https://vats.im/dcc/Current_Traffic_Dashboard</a></li>
                                        </ul>
                                    </li>
                                    <li>CDR & Preferred Route Databases:
                                        <ul>
                                            <li><a href="https://vats.im/dcc/CDR" target="_blank">https://vats.im/dcc/CDR</a></li>
                                            <li><a href="https://vats.im/dcc/PRD" target="_blank">https://vats.im/dcc/PRD</a></li>
                                        </ul>
                                    </li>
                                    <li>TMU personnel must utilize <b>authorized</b> callsigns (XX_XX_TMU) in accordance with <a href="https://www.vatusa.net/info/policies/authorized-tmu-callsigns" target="_blank">this policy</a>.</li>
                                    <li>Trangression Reporting Form for incident reporting available <a href="https://bit.l/vATCSCC_Transgression_Reporting_Form" target="_blank">here</a>.</li>
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
                            <button class="btn btn-sm btn-success" data-toggle="modal" data-target="#addterminalinitModal"><i class="fas fa-plus"></i> Add Initiative</button>      

                            <hr>
                        <?php } ?>


                        <center><div id="term_inits"></div></center>
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

                    <!-- Tab: Terminal Constraints -->
                    <div class="tab-pane fade" id="t_constraints">
                        <?php if ($perm == true) { ?>
                            <button class="btn btn-sm btn-success" data-toggle="modal" data-target="#addtermconstraintModal"><i class="fas fa-plus"></i> Add Constraint</button>      

                            <hr>
                        <?php } ?>                        

                        <center><table class="table table-striped table-bordered w-75">
                            <thead>
                                <th class="text-center"><b>Location - Cause/Context</b></th>
                                <th class="text-center"><b>Date</b></th>
                                <th class="text-center"><b>Impact</b></th>
                                <?php if ($perm == true) {
                                    echo '<th></th>';
                                }
                                ?>
                            </thead>
                            <tbody id="term_constraints_table"></tbody>
                        </table></center>
                    </div>

                    <!-- Tab: Enroute Initiatives -->
                    <div class="tab-pane fade" id="e_initiatives">
                        <?php if ($perm == true) { ?>
                            <button class="btn btn-sm btn-success" data-toggle="modal" data-target="#addenrouteinitModal"><i class="fas fa-plus"></i> Add Initiative</button>      

                            <hr>
                        <?php } ?>                          

                        <center><div id="enroute_inits"></div></center>
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

                    <!-- Tab: Enroute Constraints -->
                    <div class="tab-pane fade" id="e_constraints">
                        <?php if ($perm == true) { ?>
                            <button class="btn btn-sm btn-success" data-toggle="modal" data-target="#addenrouteconstraintModal"><i class="fas fa-plus"></i> Add Constraint</button>      

                            <hr>
                        <?php } ?>                         

                        <center><table class="table table-striped table-bordered w-75">
                            <thead>
                                <th class="text-center"><b>Location - Cause/Context</b></th>
                                <th class="text-center"><b>Date</b></th>
                                <th class="text-center"><b>Impact</b></th>
                                <?php if ($perm == true) {
                                    echo '<th></th>';
                                }
                                ?>
                            </thead>
                            <tbody id="enroute_constraints_table"></tbody>
                        </table></center>
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

<!-- Insert plan.js Script -->
<script src="assets/js/plan.js"></script>

</html>
