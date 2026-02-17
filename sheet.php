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

    // Check Perms - sheet.php requires login (data.php is the public equivalent)
    $perm = false;
    if (!defined('DEV')) {
        if (isset($_SESSION['VATSIM_CID'])) {

            // Getting CID Value
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

    // Redirect unauthenticated users to the public data page
    if (!$perm) {
        header("Location: data?" . $id);
        exit;
    }

    $plan_info = $conn_sqli->query("SELECT * FROM p_plans WHERE id=$id")->fetch_assoc();
?>

<!DOCTYPE html>
<html>

<head>

    <!-- Import CSS -->
    <?php
        $page_title = "PERTI Planning Sheet";
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
                <h1><b><span class="text-success"><?= $plan_info['event_name']; ?></span> <?= __('sheet.page.planningSheet') ?></b></h1>
                <h5><a class="text-light" href="plan?<?= $plan_info['id']; ?>"><i class="fas fa-eye text-danger"></i> <?= __('sheet.page.viewFullPlan') ?></a></h5>
            </center>

        </div>       
    </section>

    <div class="container-fluid mt-3 mb-3">
        <div class="row">
            <div class="col-2">
                <ul class="nav flex-column nav-pills" aria-orientation="vertical">
                    <li><a class="nav-link active rounded" data-toggle="tab" href="#overview"><?= __('sheet.page.overview') ?></a></li>
                    <hr>
                    <li><a class="nav-link rounded" data-toggle="tab" href="#dcc_staffing"><?= __('sheet.page.dccStaffing') ?></a></li>
                    <li><a class="nav-link rounded" data-toggle="tab" href="#t_staffing"><?= __('sheet.page.terminalStaffing') ?></a></li>
                    <li><a class="nav-link rounded" data-toggle="tab" href="#configs"><?= __('sheet.page.fieldConfigurations') ?></a></li>
                    <li><a class="nav-link rounded" data-toggle="tab" href="#e_staffing"><?= __('sheet.page.enrouteStaffing') ?></a></li>
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

                                <h4><b><?= __('sheet.page.operationalGoals') ?></b></h4>


                                <table class="table table-bordered">
                                    <tbody id="goals_table"></tbody>
                                </table>
                            </div>

                            <div class="col-6">
                                <h4><b><?= __('sheet.page.eventInformation') ?></b></h4>
                                <table class="table table-striped table-bordered">
                                    <tbody>
                                        <tr>
                                            <td><b><?= __('sheet.page.eventName') ?></b></td>
                                            <td><?= $plan_info['event_name']; ?></td>
                                        </tr>
                                        <tr>
                                            <td><b><?= __('sheet.page.eventDate') ?></b></td>
                                            <td><?= $plan_info['event_date']; ?></td>
                                        </tr>
                                        <tr>
                                            <td><b><?= __('sheet.page.eventStart') ?></b></td>
                                            <td><?= $plan_info['event_start']; ?>Z</td>
                                        </tr>
                                        <tr>
                                            <td><b><?= __('sheet.page.tmuOpLevel') ?></b></td>
                                            <?php
                                                if ($plan_info['oplevel'] == 1) {
                                                    echo '<td class="text-dark">'.$plan_info['oplevel'].' - '.__('sheet.page.opLevel1').'</td>';
                                                }
                                                elseif ($plan_info['oplevel'] == 2) {
                                                    echo '<td class="text-success">'.$plan_info['oplevel'].' - '.__('sheet.page.opLevel2').'</td>';
                                                }
                                                elseif ($plan_info['oplevel'] == 3) {
                                                    echo '<td class="text-warning">'.$plan_info['oplevel'].' - '.__('sheet.page.opLevel3').'</td>';
                                                }
                                                elseif ($plan_info['oplevel'] == 4) {
                                                    echo '<td class="text-danger">'.$plan_info['oplevel'].' - '.__('sheet.page.opLevel4').'</td>';
                                                }
                                            ?>
                                        </tr>
                                    </tbody>
                                </table>

                                <hr>

                                <h4><b><?= __('sheet.page.resources') ?></b></h4>
                                <ul>
                                    <li><?= __('sheet.page.resTmuDashboard') ?> <a href="https://vats.im/vATCSCC_TMU_Dashboard" target="_blank"><?= __('sheet.page.resHere') ?></a>.</li>
                                    <li><?= __('sheet.page.resJatocMonitor') ?> <a href="https://vats.im/JATOC" target="_blank"><?= __('sheet.page.resHere') ?></a>.</li>
                                    <li><?= __('sheet.page.resDiscordChannels') ?></li>
                                    <li><?= __('sheet.page.resNtos') ?> <a href="https://www.vatusa.net/mgt/tmu#notices" target="_blank"><?= __('sheet.page.resHere') ?></a>.
                                        <ul><li><?= __('sheet.page.resNtosNote') ?></li></ul></li>
                                    <?php if (stripos($plan_info['hotline'], 'Canada') !== false): ?>
                                    <li><?= __('sheet.page.resVatcanTs') ?>
                                        <ul><li><?= __('sheet.page.resCredentials') ?></li>
                                        <li><?= __('sheet.page.resVatusaTsBackup', ['hotline' => $plan_info['hotline']]) ?></li>
                                        <li><?= __('sheet.page.resDiscordBackup', ['hotline' => $plan_info['hotline']]) ?></li></ul></li>
                                    <?php else: ?>
                                    <li><?= __('sheet.page.resVatusaTs', ['hotline' => $plan_info['hotline']]) ?>
                                        <ul><li><?= __('sheet.page.resCredentials') ?></li>
                                        <li><?= __('sheet.page.resVatcanTsBackup') ?></li>
                                        <li><?= __('sheet.page.resDiscordBackup', ['hotline' => $plan_info['hotline']]) ?></li></ul></li>
                                    <?php endif; ?>
                                    <li><?= __('sheet.page.resGroupFlights') ?> <a href="https://vats.im/dcc/PERTI_Staffing" target="_blank"><?= __('sheet.page.resHere') ?></a>.</li>
                                    <li><?= __('sheet.page.resTrafficDashboards') ?>
                                        <ul>
                                            <li><a href="https://vats.im/dcc/VATUSA_Traffic_Dashboard" target="_blank">https://vats.im/dcc/VATUSA_Traffic_Dashboard</a></li>
                                            <li><a href="https://vats.im/dcc/Current_Traffic_Dashboard" target="_blank">https://vats.im/dcc/Current_Traffic_Dashboard</a></li>
                                        </ul>
                                    </li>
                                    <li><?= __('sheet.page.resCdrPrd') ?>
                                        <ul>
                                            <li><a href="https://vats.im/dcc/CDR" target="_blank">https://vats.im/dcc/CDR</a></li>
                                            <li><a href="https://vats.im/dcc/PRD" target="_blank">https://vats.im/dcc/PRD</a></li>
                                        </ul>
                                    </li>
                                    <li><?= __('sheet.page.resTmuCallsigns') ?> <a href="https://www.vatusa.net/info/policies/authorized-tmu-callsigns" target="_blank"><?= __('sheet.page.resThisPolicy') ?></a>.</li>
                                    <li><?= __('sheet.page.resTransgressionForm') ?> <a href="https://bit.l/vATCSCC_Transgression_Reporting_Form" target="_blank"><?= __('sheet.page.resHere') ?></a>.</li>
                                </ul>

                            </div>
                        </div>
                    </div>

                    <!-- Tab: DCC Staffing -->
                    <div class="tab-pane fade" id="dcc_staffing">
                        <center><table class="table table-striped table-bordered w-75">
                            <thead>
                                <th class="text-center"><b><?= __('sheet.page.facilityCol') ?></b></th>
                                <th class="text-center"><b><?= __('sheet.page.oisCol') ?></b></th>
                                <th><b><?= __('sheet.page.personnelNameCol') ?></b></th>
                                <th></th>
                            </thead>
                            <tbody id="dcc_staffing_table"></tbody>
                        </table></center>
                    </div>

                    <!-- Tab: Terminal Staffing -->
                    <div class="tab-pane fade" id="t_staffing">
                        <center><table class="table table-sm table-striped table-bordered w-75">
                            <thead>
                                <th class="text-center"><b><?= __('sheet.page.facilityNameCol') ?></b></th>
                                <th class="text-center"><b><?= __('sheet.page.statusCol') ?></b></th>
                                <th class="text-center"><b><?= __('sheet.page.quantityCol') ?></b></th>
                                <th class="text-center"><b><?= __('sheet.page.commentsCol') ?></b></th>
                                <th></th>
                            </thead>
                            <tbody id="term_staffing_table"></tbody>
                        </table></center>
                    </div>

                    <!-- Tab: Field Configs -->
                    <div class="tab-pane fade" id="configs">
                        <center><table class="table table-sm table-striped table-bordered w-75">
                            <thead>
                                <th class="text-center"><b><?= __('sheet.page.fieldCol') ?></b></th>
                                <th class="text-center"><b><?= __('sheet.page.conditionsCol') ?></b></th>
                                <th class="text-center"><b><?= __('sheet.page.arrivingCol') ?></b></th>
                                <th class="text-center"><b><?= __('sheet.page.departingCol') ?></b></th>
                                <th class="text-center"><b>AAR</b></th>
                                <th class="text-center"><b>ADR</b></th>
                                <th class="text-center"><b><?= __('sheet.page.commentsCol') ?></b></th>
                                <th></th>
                            </thead>
                            <tbody id="configs_table"></tbody>
                        </table></center>
                    </div>

                    <!-- Tab: Enroute Staffing -->
                    <div class="tab-pane fade" id="e_staffing">                         
                        <center><table class="table table-sm table-striped table-bordered w-75">
                            <thead>
                                <th class="text-center"><b><?= __('sheet.page.facilityNameCol') ?></b></th>
                                <th class="text-center"><b><?= __('sheet.page.statusCol') ?></b></th>
                                <th class="text-center"><b><?= __('sheet.page.quantityCol') ?></b></th>
                                <th class="text-center"><b><?= __('sheet.page.commentsCol') ?></b></th>
                                <th></th>
                            </thead>
                            <tbody id="enroute_staffing_table"></tbody>
                        </table></center>
                    </div>

                </div>
            </div>
        </div>
    </div>

</body>
<?php include('load/footer.php'); ?>

<!-- Edit DCC Personnel Modal -->
<div class="modal fade" id="edit_dccstaffingModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><?= __('sheet.page.editPersonnel') ?></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="edit_dccstaffing">

                <div class="modal-body">

                    <input type="hidden" name="id" id="id">

                    <?= __('sheet.page.facilityCol') ?>:
                    <input type="text" class="form-control" name="position_facility" id="position_facility" readonly required>

                    <hr>

                    <?= __('sheet.page.personnelName') ?>
                    <input type="text" class="form-control" name="personnel_name" id="personnel_name" maxlength="128" placeholder="<?= __('sheet.page.leaveBlankVacancy') ?>">

                    <?= __('sheet.page.personnelOis') ?>
                    <input type="text" class="form-control" name="personnel_ois" id="personnel_ois" maxlength="2" placeholder="<?= __('sheet.page.leaveBlankVacancy') ?>">

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-warning" value="Edit">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal">Close</button>
                </div>
        </div>

        </form>

    </div>
</div>

<!-- Edit Terminal Staffing Modal -->
<div class="modal fade" id="edittermstaffingModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><?= __('sheet.page.editTermStaffing') ?></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="edittermstaffing">

                <div class="modal-body">

                    <input type="hidden" name="id" id="id">

                    <?= __('sheet.page.facilityName') ?>
                    <input type="text" class="form-control" name="facility_name" id="facility_name" placeholder="SCT - SoCal TRACON" readonly required>

                    <?= __('sheet.page.staffingStatus') ?>
                    <select class="form-control" name="staffing_status" id="staffing_status">
                        <option value="0"><?= __('sheet.page.statusUnknown') ?></option>
                        <option value="3"><?= __('sheet.page.statusUnderstaffed') ?></option>
                        <option value="1"><?= __('sheet.page.statusTopDown') ?></option>
                        <option value="2"><?= __('sheet.page.statusYes') ?></option>
                        <option value="4"><?= __('sheet.page.statusNo') ?></option>
                    </select>

                    <?= __('sheet.page.staffingQuantity') ?>
                    <input type="text" class="form-control" name="staffing_quantity" id="staffing_quantity" maxlength="2" required>

                    <?= __('sheet.page.commentsCol') ?>:
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

<!-- Edit Config Modal -->
<div class="modal fade" id="editconfigModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><?= __('sheet.page.editConfigEntry') ?></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="editconfig">

                <div class="modal-body">

                    <input type="hidden" name="id" id="sheet_editconfig_id">

                    <!-- Config Picker Section -->
                    <div class="form-group">
                        <div class="custom-control custom-checkbox mb-2">
                            <input type="checkbox" class="custom-control-input" id="sheet_editconfig_use_adl">
                            <label class="custom-control-label" for="sheet_editconfig_use_adl"><?= __('sheet.page.loadFromAdl') ?></label>
                        </div>
                        <div id="sheet_editconfig_picker" style="display: none;">
                            <select class="form-control mb-2" id="sheet_editconfig_select" disabled>
                                <option value=""><?= __('sheet.page.selectConfiguration') ?></option>
                            </select>
                            <small class="text-muted"><?= __('sheet.page.selectConfigHint') ?></small>
                        </div>
                    </div>

                    <hr class="my-2">

                    <?= __('sheet.page.fieldCol') ?>:
                    <input type="text" class="form-control" name="airport" id="sheet_editconfig_airport" placeholder="BWI" maxlength="4" readonly required>

                    <?= __('sheet.page.meteorologicalCondition') ?>
                    <select class="form-control" name="weather" id="sheet_editconfig_weather">
                        <option value="0"><?= __('sheet.page.statusUnknown') ?></option>
                        <option value="1">VMC</option>
                        <option value="2">LVMC</option>
                        <option value="3">IMC</option>
                        <option value="4">LIMC</option>
                    </select>

                    <?= __('sheet.page.arrivalRunways') ?>
                    <input type="text" class="form-control" name="arrive" id="sheet_editconfig_arrive" placeholder="33L/33R">

                    <?= __('sheet.page.departureRunways') ?>
                    <input type="text" class="form-control" name="depart" id="sheet_editconfig_depart" placeholder="33R/28">

                    <?= __('sheet.page.commentsCol') ?>:
                    <input type="text" class="form-control" name="comments" id="sheet_editconfig_comments">

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-warning" value="Edit">
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
                <h5 class="modal-title"><?= __('sheet.page.editEnrouteStaffing') ?></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="editenroutestaffing">

                <div class="modal-body">

                    <input type="hidden" name="id" id="id">

                    <?= __('sheet.page.facilityName') ?>
                    <input type="text" class="form-control" name="facility_name" id="facility_name" readonly required>

                    <?= __('sheet.page.staffingStatus') ?>
                    <select class="form-control" name="staffing_status" id="staffing_status">
                        <option value="0"><?= __('sheet.page.statusUnknown') ?></option>
                        <option value="2"><?= __('sheet.page.statusUnderstaffed') ?></option>
                        <option value="1"><?= __('sheet.page.statusYes') ?></option>
                        <option value="3"><?= __('sheet.page.statusNo') ?></option>
                    </select>

                    <?= __('sheet.page.staffingQuantity') ?>
                    <input type="text" class="form-control" name="staffing_quantity" id="staffing_quantity" maxlength="2" required>

                    <?= __('sheet.page.commentsCol') ?>:
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

<!-- Insert sheet.js Script -->
<script src="assets/js/sheet.js"></script>

</html>
