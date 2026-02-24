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

    // NOTE: data.php is PUBLIC - no authentication required
    // This differs from sheet.php which requires login
    $perm = true;

    // For logged-in users, set session data
    if (isset($_SESSION['VATSIM_CID'])) {
        $cid = session_get('VATSIM_CID', '');
        $p_check = $conn_sqli->query("SELECT * FROM users WHERE cid='$cid'");
    } else {
        // Public access - set placeholder values
        $_SESSION['VATSIM_FIRST_NAME'] = 'Guest';
        $_SESSION['VATSIM_LAST_NAME'] = '';
        $_SESSION['VATSIM_CID'] = 0;
    }

    $plan_info = $conn_sqli->query("SELECT * FROM p_plans WHERE id=$id")->fetch_assoc();
?>

<!DOCTYPE html>
<html>

<head>

    <!-- Import CSS -->
    <?php
        $page_title = "PERTI Planning Data";
        include("load/header.php");
    ?>

    <link href="https://unpkg.com/maplibre-gl@3.6.2/dist/maplibre-gl.css" rel="stylesheet" />
    <script src="https://unpkg.com/maplibre-gl@3.6.2/dist/maplibre-gl.js"></script>

    <script>
        var plan_id = <?= $id ?>;
        var PERTI_EVENT_DATE     = <?= json_encode($plan_info['event_date']); ?>;
        var PERTI_EVENT_START    = <?= json_encode($plan_info['event_start']); ?>;
        var PERTI_EVENT_END_DATE = <?= json_encode($plan_info['event_end_date'] ?? ''); ?>;
        var PERTI_EVENT_END_TIME = <?= json_encode($plan_info['event_end_time'] ?? ''); ?>;
        var PERTI_EVENT_START_ISO = null;
        var PERTI_EVENT_END_ISO = null;
        (function() {
            if (PERTI_EVENT_DATE && PERTI_EVENT_START) {
                var startTime = PERTI_EVENT_START.padStart(4, '0');
                PERTI_EVENT_START_ISO = PERTI_EVENT_DATE + 'T' + startTime.substring(0,2) + ':' + startTime.substring(2,4) + ':00Z';
            }
            var endDate = PERTI_EVENT_END_DATE || PERTI_EVENT_DATE;
            var endTime = PERTI_EVENT_END_TIME || '';
            if (endDate && endTime.length >= 4) {
                endTime = endTime.padStart(4, '0');
                PERTI_EVENT_END_ISO = endDate + 'T' + endTime.substring(0,2) + ':' + endTime.substring(2,4) + ':00Z';
            } else if (PERTI_EVENT_START_ISO) {
                var startDt = new Date(PERTI_EVENT_START_ISO);
                var endDt = new Date(startDt.getTime() + 6 * 60 * 60 * 1000);
                PERTI_EVENT_END_ISO = endDt.toISOString();
            }
        })();
    </script>

    <link rel="stylesheet" href="assets/css/initiative_timeline.css<?= _v('assets/css/initiative_timeline.css') ?>">

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
include('load/nav_public.php');
?>

    <section class="d-flex align-items-center position-relative bg-position-center overflow-hidden pt-6 jarallax bg-dark text-light" style="min-height: 250px" data-jarallax data-speed="0.3" style="pointer-events: all;">
        <div class="position-absolute top-0 left-0 w-100 h-100">
            <img class="jarallax-img" src="assets/img/jumbotron/main.png" alt="" style="opacity: 50%; height: 100vh;">

            <center>
                <h1><b><span class="text-success"><?= $plan_info['event_name']; ?></span> Planning Data</b></h1>
                <h5><a class="text-light" href="plan?<?= $plan_info['id']; ?>"><i class="fas fa-eye text-danger"></i> View Full PERTI Plan</a></h5>
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
                    <li><a class="nav-link rounded" data-toggle="tab" href="#t_staffing">Terminal Staffing</a></li>
                    <li><a class="nav-link rounded" data-toggle="tab" href="#configs">Field Configurations</a></li>
                    <li><a class="nav-link rounded" data-toggle="tab" href="#e_staffing">En-Route Staffing</a></li>
                    <li><a class="nav-link rounded" data-toggle="tab" href="#e_splits"><?= __('plan.tabs.enrouteSplits') ?></a></li>
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

                                <h4><b>Operational Goals</b></h4>

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
                                        <?php if (!empty($plan_info['event_end_date'])): ?>
                                        <tr>
                                            <td><b>Event End Date</b></td>
                                            <td><?= $plan_info['event_end_date']; ?></td>
                                        </tr>
                                        <?php endif; ?>
                                        <?php if (!empty($plan_info['event_end_time'])): ?>
                                        <tr>
                                            <td><b>Event End Time</b></td>
                                            <td><?= $plan_info['event_end_time']; ?>Z</td>
                                        </tr>
                                        <?php endif; ?>
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
                                                    echo '<td class="text-danger">'.$plan_info['oplevel'].' - NAS-Wide Impact</td>';
                                                }
                                            ?>
                                        </tr>
                                    </tbody>
                                </table>

                                <hr>

                                <h4><b><?= __('plan.overview.resources') ?></b></h4>
                                <ul>
                                    <li><a href="https://perti.vatcscc.org/nod" target="_blank"><?= __('plan.overview.resourceNod') ?></a> <?= __('plan.overview.resourceNodDesc') ?></li>
                                    <li><a href="https://perti.vatcscc.org/splits" target="_blank"><?= __('plan.overview.resourceSplits') ?></a> <?= __('plan.overview.resourceSplitsDesc') ?></li>
                                    <li><a href="https://perti.vatcscc.org/gdt" target="_blank"><?= __('plan.overview.resourceGdt') ?></a> <?= __('plan.overview.resourceGdtDesc') ?></li>
                                    <li><a href="https://perti.vatcscc.org/jatoc" target="_blank"><?= __('plan.overview.resourceJatoc') ?></a> <?= __('plan.overview.resourceJatocDesc') ?></li>
                                    <li>vATCSCC Discord <a href="https://discord.com/channels/358264961233059843/358295136398082048/" target="_blank">#ntml</a> and <a href="https://discord.com/channels/358264961233059843/358300240236773376/" target="_blank">#advisories</a> <?= __('plan.overview.resourceDiscord') ?></li>
                                    <?php if (stripos($plan_info['hotline'] ?? '', 'Canada') !== false): ?>
                                    <li>VATCAN <a href="ts3server://ts.vatcan.ca" target="_blank">TeamSpeak</a>, <span class="text-danger"><b>TMU Hang</b></span> <?= __('plan.overview.resourceHotlineVatcan') ?>
                                        <ul><li><?= __('plan.overview.resourceCredentials') ?></li>
                                        <li>The VATUSA <a href="ts3server://ts.vatusa.net" target="_blank">TeamSpeak</a>, <?= $plan_info['hotline'] ?? ''; ?> <?= __('plan.overview.resourcePrimaryBackupVatusa') ?></li>
                                        <li>The vATCSCC Discord, <?= $plan_info['hotline'] ?? ''; ?> <?= __('plan.overview.resourceSecondaryBackup') ?></li></ul></li>
                                    <?php else: ?>
                                    <li>VATUSA <a href="ts3server://ts.vatusa.net" target="_blank">TeamSpeak</a>, <span class="text-danger"><b><?= $plan_info['hotline'] ?? ''; ?></b></span> <?= __('plan.overview.resourceHotlineVatusa') ?>
                                        <ul><li><?= __('plan.overview.resourceCredentials') ?></li>
                                        <li>The VATCAN <a href="ts3server://ts.vatcan.ca" target="_blank">TeamSpeak</a>, TMU Hang <?= __('plan.overview.resourcePrimaryBackupVatcan') ?></li>
                                        <li>The vATCSCC Discord, <?= $plan_info['hotline'] ?? ''; ?> <?= __('plan.overview.resourceSecondaryBackup') ?></li></ul></li>
                                    <?php endif; ?>
                                    <li><?= __('plan.overview.resourceGroupFlights') ?> <a href="https://bit.ly/NTML_Entry" target="_blank"><?= __('plan.overview.resourceGroupFlightsLink') ?></a>.</li>
                                    <li><?= __('plan.overview.resourceCallsigns') ?> <a href="https://www.vatusa.net/info/policies/authorized-tmu-callsigns" target="_blank"><?= __('plan.overview.resourceCallsignsLink') ?></a>.</li>
                                    <li><a href="https://bit.l/vATCSCC_Transgression_Reporting_Form" target="_blank"><?= __('plan.overview.resourceTransgression') ?></a> <?= __('plan.overview.resourceTransgressionDesc') ?></li>
                                </ul>

                            </div>
                        </div>
                    </div>

                    <!-- Tab: DCC Staffing -->
                    <div class="tab-pane fade" id="dcc_staffing">
                        <button class="btn btn-sm btn-outline-secondary plan-group-toggle mb-2" data-table="dccFacility"><i class="fas fa-list"></i> <?= __('plan.tables.flatView') ?></button>

                        <center><table class="table table-striped table-bordered w-75">
                            <thead>
                                <th class="text-center sortable" data-sort="position_facility" data-table="dccFacility"><b><?= __('plan.dcc.facility') ?></b> <i class="fas fa-sort sort-icon"></i></th>
                                <th class="text-center sortable" data-sort="personnel_ois" data-table="dccFacility"><b><?= __('plan.dcc.ois') ?></b> <i class="fas fa-sort sort-icon"></i></th>
                                <th class="sortable" data-sort="personnel_name" data-table="dccFacility"><b><?= __('plan.dcc.personnelName') ?></b> <i class="fas fa-sort sort-icon"></i></th>
                                <th></th>
                            </thead>
                            <tbody id="dcc_staffing_table"></tbody>
                        </table></center>
                    </div>

                    <!-- Tab: Terminal Staffing -->
                    <div class="tab-pane fade" id="t_staffing">
                        <button class="btn btn-sm btn-outline-secondary plan-group-toggle mb-2" data-table="termStaffing"><i class="fas fa-list"></i> <?= __('plan.tables.flatView') ?></button>

                        <center><table class="table table-sm table-striped table-bordered w-75">
                            <thead>
                                <th class="text-center sortable" data-sort="facility_name" data-table="termStaffing"><b><?= __('plan.staffing.facilityName') ?></b> <i class="fas fa-sort sort-icon"></i></th>
                                <th class="text-center sortable" data-sort="staffing_status" data-table="termStaffing"><b><?= __('plan.staffing.status') ?></b> <i class="fas fa-sort sort-icon"></i></th>
                                <th class="text-center sortable" data-sort="staffing_quantity" data-table="termStaffing"><b><?= __('plan.staffing.quantity') ?></b> <i class="fas fa-sort sort-icon"></i></th>
                                <th class="text-center"><b><?= __('plan.staffing.comments') ?></b></th>
                                <th></th>
                            </thead>
                            <tbody id="term_staffing_table"></tbody>
                        </table></center>
                    </div>

                    <!-- Tab: Field Configs -->
                    <div class="tab-pane fade" id="configs">
                        <button class="btn btn-sm btn-outline-secondary plan-group-toggle mb-2" data-table="configs"><i class="fas fa-list"></i> <?= __('plan.tables.flatView') ?></button>

                        <center><table class="table table-sm table-striped table-bordered w-75">
                            <thead>
                                <th class="text-center sortable" data-sort="airport" data-table="configs"><b><?= __('plan.configTab.field') ?></b> <i class="fas fa-sort sort-icon"></i></th>
                                <th class="text-center sortable" data-sort="weather" data-table="configs"><b><?= __('plan.configTab.conditions') ?></b> <i class="fas fa-sort sort-icon"></i></th>
                                <th class="text-center sortable" data-sort="arrive" data-table="configs"><b><?= __('plan.configTab.arriving') ?></b> <i class="fas fa-sort sort-icon"></i></th>
                                <th class="text-center sortable" data-sort="depart" data-table="configs"><b><?= __('plan.configTab.departing') ?></b> <i class="fas fa-sort sort-icon"></i></th>
                                <th class="text-center sortable" data-sort="aar" data-table="configs"><b>AAR</b> <i class="fas fa-sort sort-icon"></i></th>
                                <th class="text-center sortable" data-sort="adr" data-table="configs"><b>ADR</b> <i class="fas fa-sort sort-icon"></i></th>
                                <th class="text-center"><b><?= __('plan.staffing.comments') ?></b></th>
                                <th></th>
                            </thead>
                            <tbody id="configs_table"></tbody>
                        </table></center>
                    </div>

                    <!-- Tab: Enroute Staffing -->
                    <div class="tab-pane fade" id="e_staffing">
                        <button class="btn btn-sm btn-outline-secondary plan-group-toggle mb-2" data-table="enrouteStaffing"><i class="fas fa-list"></i> <?= __('plan.tables.flatView') ?></button>

                        <center><table class="table table-sm table-striped table-bordered w-75">
                            <thead>
                                <th class="text-center sortable" data-sort="facility_name" data-table="enrouteStaffing"><b><?= __('plan.staffing.facilityName') ?></b> <i class="fas fa-sort sort-icon"></i></th>
                                <th class="text-center sortable" data-sort="staffing_status" data-table="enrouteStaffing"><b><?= __('plan.staffing.status') ?></b> <i class="fas fa-sort sort-icon"></i></th>
                                <th class="text-center sortable" data-sort="staffing_quantity" data-table="enrouteStaffing"><b><?= __('plan.staffing.quantity') ?></b> <i class="fas fa-sort sort-icon"></i></th>
                                <th class="text-center"><b><?= __('plan.staffing.comments') ?></b></th>
                                <th></th>
                            </thead>
                            <tbody id="enroute_staffing_table"></tbody>
                        </table></center>
                    </div>

                    <!-- Tab: Enroute Splits -->
                    <div class="tab-pane fade" id="e_splits">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="mb-0"><?= __('plan.splits.title') ?></h5>
                            <div>
                                <button class="btn btn-sm btn-outline-secondary mr-1" id="btn_refresh_splits" title="<?= __('common.refresh') ?>">
                                    <i class="fas fa-sync-alt"></i>
                                </button>
                                <a href="./splits" class="btn btn-sm btn-outline-primary" target="_blank">
                                    <i class="fas fa-external-link-alt mr-1"></i><?= __('plan.splits.configureSplits') ?>
                                </a>
                            </div>
                        </div>
                        <div id="plan_splits_map" style="width:100%;height:450px;border-radius:6px;margin-bottom:12px;display:none;"></div>
                        <div id="plan_splits_map_controls" class="mb-3" style="display:none;">
                            <div class="d-flex align-items-center flex-wrap small">
                                <span class="mr-2 font-weight-bold"><?= __('plan.splits.mapLayers') ?>:</span>
                                <div class="custom-control custom-checkbox custom-control-inline">
                                    <input type="checkbox" class="custom-control-input" id="splits_layer_active" checked>
                                    <label class="custom-control-label" for="splits_layer_active">
                                        <span class="badge badge-success"><?= __('plan.splits.active') ?></span>
                                    </label>
                                </div>
                                <div class="custom-control custom-checkbox custom-control-inline">
                                    <input type="checkbox" class="custom-control-input" id="splits_layer_scheduled" checked>
                                    <label class="custom-control-label" for="splits_layer_scheduled">
                                        <span class="badge badge-info"><?= __('plan.splits.scheduled') ?></span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div id="plan_splits_container">
                            <div class="text-center text-muted py-4">
                                <i class="fas fa-spinner fa-spin"></i> <?= __('common.loading') ?>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

<?php
include("load/footer.php");
?>

</body>

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
                    <input type="text" class="form-control" name="position_facility" id="position_facility" readonly required>

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

<!-- Edit Terminal Staffing Modal -->
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
                    <input type="text" class="form-control" name="facility_name" id="facility_name" placeholder="SCT - SoCal TRACON" readonly required>

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

                    <input type="hidden" name="id" id="sheet_editconfig_id">

                    <!-- Config Picker Section -->
                    <div class="form-group">
                        <div class="custom-control custom-checkbox mb-2">
                            <input type="checkbox" class="custom-control-input" id="sheet_editconfig_use_adl">
                            <label class="custom-control-label" for="sheet_editconfig_use_adl">Load from ADL Config</label>
                        </div>
                        <div id="sheet_editconfig_picker" style="display: none;">
                            <select class="form-control mb-2" id="sheet_editconfig_select" disabled>
                                <option value="">-- Select configuration --</option>
                            </select>
                            <small class="text-muted">Select a configuration to load runway info</small>
                        </div>
                    </div>

                    <hr class="my-2">

                    Field:
                    <input type="text" class="form-control" name="airport" id="sheet_editconfig_airport" placeholder="BWI" maxlength="4" readonly required>

                    Meteorological Condition:
                    <select class="form-control" name="weather" id="sheet_editconfig_weather">
                        <option value="0">Unknown</option>
                        <option value="1">VMC</option>
                        <option value="2">LVMC</option>
                        <option value="3">IMC</option>
                        <option value="4">LIMC</option>
                    </select>

                    Arrival Runways:
                    <input type="text" class="form-control" name="arrive" id="sheet_editconfig_arrive" placeholder="33L/33R">

                    Departure Runways:
                    <input type="text" class="form-control" name="depart" id="sheet_editconfig_depart" placeholder="33R/28">

                    Comments:
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
                <h5 class="modal-title">Edit Enroute Staffing Entry</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="editenroutestaffing">

                <div class="modal-body">

                    <input type="hidden" name="id" id="id">

                    Facility Name:
                    <input type="text" class="form-control" name="facility_name" id="facility_name" readonly required>

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

<!-- Insert plan-tables.js + plan-splits-map.js + sheet.js Scripts -->
<script src="assets/js/plan-tables.js<?= _v('assets/js/plan-tables.js') ?>"></script>
<script src="assets/js/plan-splits-map.js<?= _v('assets/js/plan-splits-map.js') ?>"></script>
<script src="assets/js/sheet.js<?= _v('assets/js/sheet.js') ?>"></script>
<script>
// Lazy-load splits overview on first tab visit
var _splitsTabLoaded = false;
$('a[data-toggle="tab"][href="#e_splits"]').on('shown.bs.tab', function() {
    if (!_splitsTabLoaded) {
        _splitsTabLoaded = true;
        if (typeof PlanSplitsMap !== 'undefined') PlanSplitsMap.init();
        PlanTables.loadSplitsOverview();
    }
});
$('#btn_refresh_splits').on('click', function() {
    PlanTables.loadSplitsOverview();
});
$('#splits_layer_active').on('change', function() {
    if (typeof PlanSplitsMap !== 'undefined') PlanSplitsMap.setLayerVisible('active', this.checked);
});
$('#splits_layer_scheduled').on('change', function() {
    if (typeof PlanSplitsMap !== 'undefined') PlanSplitsMap.setLayerVisible('scheduled', this.checked);
});
</script>

</html>
