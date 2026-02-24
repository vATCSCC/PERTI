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

    $plan_info = $conn_sqli->query("SELECT * FROM p_plans WHERE id=$id")->fetch_assoc();

    require_once('load/org_context.php');
    $plan_org_code = $plan_info['org_code'] ?? null;
    $org_mismatch = ($plan_org_code !== null && $plan_org_code !== get_org_code());
?>

<!DOCTYPE html>
<html>

<head>

    <!-- Import CSS -->
    <?php
        $page_title = "PERTI Plan";
        include("load/header.php");
    ?>
    <link rel="stylesheet" href="assets/css/initiative_timeline.css<?= _v('assets/css/initiative_timeline.css') ?>">

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

    <link href="https://unpkg.com/maplibre-gl@3.6.2/dist/maplibre-gl.css" rel="stylesheet" />
    <script src="https://unpkg.com/maplibre-gl@3.6.2/dist/maplibre-gl.js"></script>

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

if ($org_mismatch):
    $stmt = $conn_sqli->prepare("SELECT display_name FROM organizations WHERE org_code = ?");
    $stmt->bind_param("s", $plan_org_code);
    $stmt->execute();
    $plan_org_display = $stmt->get_result()->fetch_assoc()['display_name'] ?? strtoupper($plan_org_code);
    $current_org_display = get_org_info($conn_sqli)['display_name'];
?>
    <div class="container mt-5 text-center">
        <div class="alert alert-warning py-4" role="alert">
            <h5 class="mb-3"><i class="fas fa-exchange-alt mr-2"></i> <?= __('org.mismatch.title') ?></h5>
            <p><?= __('org.mismatch.planBelongsTo', ['org' => htmlspecialchars($plan_org_display)]) ?>
               <?= __('org.mismatch.currentlyViewing', ['org' => htmlspecialchars($current_org_display)]) ?></p>
            <button class="btn btn-primary" onclick="switchOrg('<?= htmlspecialchars($plan_org_code) ?>')">
                <i class="fas fa-sync-alt mr-1"></i> <?= __('org.mismatch.switchTo', ['org' => htmlspecialchars($plan_org_display)]) ?>
            </button>
        </div>
    </div>
<?php include('load/footer.php'); ?>
</body></html>
<?php exit; endif; ?>

    <section class="d-flex align-items-center position-relative bg-position-center overflow-hidden pt-6 jarallax bg-dark text-light" style="min-height: 250px" data-jarallax data-speed="0.3" style="pointer-events: all;">
        <div class="container-fluid pt-2 pb-5 py-lg-6">
            <img class="jarallax-img" src="assets/img/jumbotron/main.png" alt="" style="opacity: 50%; height: 100vh;">

            <center>
                <h1><b><span class="text-danger"><?= $plan_info['event_name']; ?></span> <?= __('plan.pageTitle') ?></b></h1>
                <h5><a class="text-light" href="data?<?= $plan_info['id']; ?>"><i class="fas fa-table text-success"></i> <?= __('plan.editStaffingData') ?></a></h5>
            </center>

        </div>       
    </section>

    <div class="container-fluid mt-3 mb-3">
        <div class="row">
            <div class="col-2">
                <ul class="nav flex-column nav-pills" aria-orientation="vertical">
                    <li><a class="nav-link active rounded" data-toggle="tab" href="#overview"><?= __('plan.tabs.overview') ?></a></li>
                    <hr>
                    <li><a class="nav-link rounded" data-toggle="tab" href="#dcc_staffing"><?= __('plan.tabs.dccStaffing') ?></a></li>
                    <li><a class="nav-link rounded" data-toggle="tab" href="#historical"><?= __('plan.tabs.historical') ?></a></li>
                    <li><a class="nav-link rounded" data-toggle="tab" href="#forecast"><?= __('plan.tabs.forecast') ?></a></li>
                    <hr>
                    <li><a class="nav-link rounded" data-toggle="tab" href="#t_initiatives"><?= __('plan.tabs.termInitiatives') ?></a></li>
                    <li><a class="nav-link rounded" data-toggle="tab" href="#t_staffing"><?= __('plan.tabs.termStaffing') ?></a></li>
                    <li><a class="nav-link rounded" data-toggle="tab" href="#configs"><?= __('plan.tabs.configs') ?></a></li>
                    <li><a class="nav-link rounded" data-toggle="tab" href="#t_planning"><?= __('plan.tabs.termPlanning') ?></a></li>
                    <hr>
                    <li><a class="nav-link rounded" data-toggle="tab" href="#e_initiatives"><?= __('plan.tabs.enrouteInitiatives') ?></a></li>
                    <li><a class="nav-link rounded" data-toggle="tab" href="#e_staffing"><?= __('plan.tabs.enrouteStaffing') ?></a></li>
                    <li><a class="nav-link rounded" data-toggle="tab" href="#e_planning"><?= __('plan.tabs.enroutePlanning') ?></a></li>
                    <li><a class="nav-link rounded" data-toggle="tab" href="#e_splits"><?= __('plan.tabs.enrouteSplits') ?></a></li>
                    <hr>
                    <li><a class="nav-link rounded" data-toggle="tab" href="#group_flights"><?= __('plan.tabs.groupFlights') ?></a></li>
                    <li><a class="nav-link rounded" data-toggle="tab" href="#outlook"><?= __('plan.tabs.outlook') ?></a></li>
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
                                    <h4><b><?= __('plan.overview.operationalGoals') ?></b> <span class="badge badge-success" data-toggle="modal" data-target="#addgoalModal"><i class="fas fa-plus" data-toggle="tooltip" title="<?= __('plan.overview.addGoalTooltip') ?>"></i></span></h4>
                                <?php } else { ?>
                                    <h4><b><?= __('plan.overview.operationalGoals') ?></b></h4>
                                <?php } ?>


                                <table class="table table-bordered">
                                    <tbody id="goals_table"></tbody>
                                </table>
                            </div>

                            <div class="col-6">
                                <h4><b><?= __('plan.overview.eventInformation') ?></b></h4>
                                <table class="table table-striped table-bordered">
                                    <tbody>
                                        <tr>
                                            <td><b><?= __('plan.overview.eventName') ?></b></td>
                                            <td><?= $plan_info['event_name']; ?></td>
                                        </tr>
                                        <tr>
                                            <td><b><?= __('plan.overview.eventStartDate') ?></b></td>
                                            <td><?= $plan_info['event_date']; ?></td>
                                        </tr>
                                        <tr>
                                            <td><b><?= __('plan.overview.eventStartTime') ?></b></td>
                                            <td><?= $plan_info['event_start']; ?>Z</td>
                                        </tr>
                                        <tr>
                                            <td><b><?= __('plan.overview.eventEndDate') ?></b></td>
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
                                            <td><b><?= __('plan.overview.eventEndTime') ?></b></td>
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
                                            <td><b><?= __('plan.overview.tmuOpLevel') ?></b></td>
                                            <?php
                                                if ($plan_info['oplevel'] == 1) {
                                                    echo '<td class="text-dark">'.$plan_info['oplevel'].' - '.__('plan.overview.opLevel1').'</td>';
                                                }
                                                elseif ($plan_info['oplevel'] == 2) {
                                                    echo '<td class="text-success">'.$plan_info['oplevel'].' - '.__('plan.overview.opLevel2').'</td>';
                                                }
                                                elseif ($plan_info['oplevel'] == 3) {
                                                    echo '<td class="text-warning">'.$plan_info['oplevel'].' - '.__('plan.overview.opLevel3').'</td>';
                                                }
                                                elseif ($plan_info['oplevel'] == 4) {
                                                    echo '<td class="text-danger">'.$plan_info['oplevel'].' - '.__('plan.overview.opLevel4').'</td>';
                                                }
                                            ?>
                                        </tr>
                                    </tbody>
                                </table>

                                <hr>

                                <button type="button" class="btn btn-sm btn-outline-secondary mb-2" onclick="AdvisoryConfig.showConfigModal();" data-toggle="tooltip" title="<?= __('plan.advisoryOrg.switchTooltip') ?>">
                                    <i class="fas fa-globe"></i> <span id="advisoryOrgDisplay">DCC</span>
                                </button>

                                <button type="button" class="btn btn-sm btn-primary mb-2" onclick="openPertiModal();">
                                    <?= __('plan.overview.createPertiNotification') ?>
                                </button>

                                <button type="button" class="btn btn-sm btn-outline-primary mb-2" onclick="openOpsPlanModal();">
                                    <?= __('plan.overview.createOpsPlanAdvisory') ?>
                                </button>

                                <hr>

                                <h4><b><?= __('plan.overview.resources') ?></b></h4>
                                <ul>
                                    <li><a href="https://perti.vatcscc.org/nod" target="_blank"><?= __('plan.overview.resourceNod') ?></a> <?= __('plan.overview.resourceNodDesc') ?></li>
                                    <li><a href="https://perti.vatcscc.org/splits" target="_blank"><?= __('plan.overview.resourceSplits') ?></a> <?= __('plan.overview.resourceSplitsDesc') ?></li>
                                    <li><a href="https://perti.vatcscc.org/gdt" target="_blank"><?= __('plan.overview.resourceGdt') ?></a> <?= __('plan.overview.resourceGdtDesc') ?></li>
                                    <li><a href="https://perti.vatcscc.org/jatoc" target="_blank"><?= __('plan.overview.resourceJatoc') ?></a> <?= __('plan.overview.resourceJatocDesc') ?></li>
                                    <li>vATCSCC Discord <a href="https://discord.com/channels/358264961233059843/358295136398082048/" target="_blank">#ntml</a> and <a href="https://discord.com/channels/358264961233059843/358300240236773376/" target="_blank">#advisories</a> <?= __('plan.overview.resourceDiscord') ?></li>
                                    <?php if (stripos($plan_info['hotline'], 'Canada') !== false): ?>
                                    <li>VATCAN <a href="ts3server://ts.vatcan.ca" target="_blank">TeamSpeak</a>, <span class="text-danger"><b>TMU Hang</b></span> <?= __('plan.overview.resourceHotlineVatcan') ?>
                                        <ul><li><?= __('plan.overview.resourceCredentials') ?></li>
                                        <li>The VATUSA <a href="ts3server://ts.vatusa.net" target="_blank">TeamSpeak</a>, <?= $plan_info['hotline']; ?> <?= __('plan.overview.resourcePrimaryBackupVatusa') ?></li>
                                        <li>The vATCSCC Discord, <?= $plan_info['hotline']; ?> <?= __('plan.overview.resourceSecondaryBackup') ?></li></ul></li>
                                    <?php else: ?>
                                    <li>VATUSA <a href="ts3server://ts.vatusa.net" target="_blank">TeamSpeak</a>, <span class="text-danger"><b><?= $plan_info['hotline']; ?></b></span> <?= __('plan.overview.resourceHotlineVatusa') ?>
                                        <ul><li><?= __('plan.overview.resourceCredentials') ?></li>
                                        <li>The VATCAN <a href="ts3server://ts.vatcan.ca" target="_blank">TeamSpeak</a>, TMU Hang <?= __('plan.overview.resourcePrimaryBackupVatcan') ?></li>
                                        <li>The vATCSCC Discord, <?= $plan_info['hotline']; ?> <?= __('plan.overview.resourceSecondaryBackup') ?></li></ul></li>
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
                        
                        <?php if ($perm == true) { ?>
                            <button class="btn btn-sm btn-success" data-toggle="modal" data-target="#add_dccstaffingModal"><i class="fas fa-plus"></i> <?= __('plan.dcc.addPersonnel') ?></button>

                            <hr>
                        <?php } ?>

                        <h4><b><?= __('plan.dcc.dccPersonnel') ?></b></h4>
                        <button class="btn btn-sm btn-outline-secondary plan-group-toggle mb-2" data-table="dccPersonnel"><i class="fas fa-list"></i> <?= __('plan.tables.flatView') ?></button>

                        <table class="table table-striped table-bordered">
                            <thead>
                                <th class="text-center sortable" data-sort="position_facility" data-table="dccPersonnel" style="width:12%;"><b><?= __('plan.dcc.facility') ?></b> <i class="fas fa-sort sort-icon"></i></th>
                                <th class="sortable" data-sort="position_name" data-table="dccPersonnel" style="width:22%;"><b><?= __('plan.dcc.positionName') ?></b> <i class="fas fa-sort sort-icon"></i></th>
                                <th class="text-center sortable" data-sort="personnel_ois" data-table="dccPersonnel" style="width:8%;"><b><?= __('plan.dcc.ois') ?></b> <i class="fas fa-sort sort-icon"></i></th>
                                <th class="sortable" data-sort="personnel_name" data-table="dccPersonnel"><b><?= __('plan.dcc.personnelName') ?></b> <i class="fas fa-sort sort-icon"></i></th>
                                <?php if ($perm == true) {
                                    echo '<th></th>';
                                }
                                ?>
                            </thead>
                            <tbody id="dcc_table"></tbody>
                        </table>

                        <h4 class="mt-4"><b><?= __('plan.dcc.facilityPersonnel') ?></b></h4>
                        <button class="btn btn-sm btn-outline-secondary plan-group-toggle mb-2" data-table="dccFacility"><i class="fas fa-list"></i> <?= __('plan.tables.flatView') ?></button>

                        <table class="table table-striped table-bordered">
                            <thead>
                                <th class="text-center sortable" data-sort="position_facility" data-table="dccFacility" style="width:12%;"><b><?= __('plan.dcc.facility') ?></b> <i class="fas fa-sort sort-icon"></i></th>
                                <th class="sortable" data-sort="position_name" data-table="dccFacility" style="width:22%;"><b><?= __('plan.dcc.positionName') ?></b> <i class="fas fa-sort sort-icon"></i></th>
                                <th class="text-center sortable" data-sort="personnel_ois" data-table="dccFacility" style="width:8%;"><b><?= __('plan.dcc.ois') ?></b> <i class="fas fa-sort sort-icon"></i></th>
                                <th class="sortable" data-sort="personnel_name" data-table="dccFacility"><b><?= __('plan.dcc.personnelName') ?></b> <i class="fas fa-sort sort-icon"></i></th>
                                <?php if ($perm == true) {
                                    echo '<th></th>';
                                }
                                ?>
                            </thead>
                            <tbody id="dcc_staffing_table"></tbody>
                        </table>
                    </div>

                    <!-- Tab: Historical Data -->
                    <div class="tab-pane fade" id="historical">

                        <?php if ($perm == true) { ?>
                            <button class="btn btn-sm btn-success" data-toggle="modal" data-target="#addhistoricalModal"><i class="fas fa-plus"></i> <?= __('plan.historicalTab.addData') ?></button>

                            <hr>
                        <?php } ?>

                        <div class="row gutters-tiny py-20" id="historicaldata"></div>
                    </div>

                    <!-- Tab: Forecast Entry -->
                    <div class="tab-pane fade" id="forecast">
                        <?php if ($perm == true) { ?>
                            <button class="btn btn-sm btn-success" data-toggle="modal" data-target="#addforecastModal"><i class="fas fa-plus"></i> <?= __('plan.forecastTab.addForecast') ?></button>

                            <hr>
                        <?php } ?>

                        <div class="row gutters-tiny py-20" id="forecastdata"></div>
                    </div>

                    <!-- Tab: Terminal Initiatives -->
                    <div class="tab-pane fade" id="t_initiatives">
                        <?php if ($perm == true) { ?>
                            <button class="btn btn-sm btn-success mb-3" onclick="window.termInitTimeline && window.termInitTimeline.showAddModal()"><i class="fas fa-plus"></i> <?= __('plan.initiatives.addInitiative') ?></button>
                        <?php } ?>
                        <div id="term_inits_timeline"></div>

                        <!-- Legacy view (hidden by default, kept for backwards compatibility) -->
                        <div id="term_inits_legacy" style="display: none;">
                            <?php if ($perm == true) { ?>
                                <button class="btn btn-sm btn-success" data-toggle="modal" data-target="#addterminalinitModal"><i class="fas fa-plus"></i> <?= __('plan.initiatives.addInitiativeLegacy') ?></button>      
                                <hr>
                            <?php } ?>
                            <center><div id="term_inits"></div></center>
                        </div>
                    </div>

                    <!-- Tab: Terminal Staffing -->
                    <div class="tab-pane fade" id="t_staffing">
                        
                        <?php if ($perm == true) { ?>
                            <button class="btn btn-sm btn-success" data-toggle="modal" data-target="#addtermstaffingModal"><i class="fas fa-plus"></i> <?= __('plan.staffing.addStaffing') ?></button>

                            <hr>
                        <?php } ?>


                        <button class="btn btn-sm btn-outline-secondary plan-group-toggle mb-2" data-table="termStaffing"><i class="fas fa-list"></i> <?= __('plan.tables.flatView') ?></button>

                        <center><table class="table table-sm table-striped table-bordered w-75">
                            <thead>
                                <th class="text-center sortable" data-sort="facility_name" data-table="termStaffing"><b><?= __('plan.staffing.facilityName') ?></b> <i class="fas fa-sort sort-icon"></i></th>
                                <th class="text-center sortable" data-sort="staffing_status" data-table="termStaffing"><b><?= __('plan.staffing.status') ?></b> <i class="fas fa-sort sort-icon"></i></th>
                                <th class="text-center sortable" data-sort="staffing_quantity" data-table="termStaffing"><b><?= __('plan.staffing.quantity') ?></b> <i class="fas fa-sort sort-icon"></i></th>
                                <th class="text-center"><b><?= __('plan.staffing.comments') ?></b></th>
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
                            <button class="btn btn-sm btn-success" data-toggle="modal" data-target="#addconfigModal"><i class="fas fa-plus"></i> <?= __('plan.configTab.addConfig') ?></button>

                            <hr>
                        <?php } ?>

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
                            <button class="btn btn-sm btn-success" data-toggle="modal" data-target="#addtermplanningModal"><i class="fas fa-plus"></i> <?= __('plan.planning.addPlan') ?></button>

                            <hr>
                        <?php } ?>

                        <div class="row gutters-tiny py-20" id="termplanningdata"></div>
                    </div>

                    <!-- Tab: Enroute Initiatives -->
                    <div class="tab-pane fade" id="e_initiatives">
                        <?php if ($perm == true) { ?>
                            <button class="btn btn-sm btn-success mb-3" onclick="window.enrouteInitTimeline && window.enrouteInitTimeline.showAddModal()"><i class="fas fa-plus"></i> <?= __('plan.initiatives.addInitiative') ?></button>
                        <?php } ?>
                        <div id="enroute_inits_timeline"></div>

                        <!-- Legacy view (hidden by default, kept for backwards compatibility) -->
                        <div id="enroute_inits_legacy" style="display: none;">
                            <?php if ($perm == true) { ?>
                                <button class="btn btn-sm btn-success" data-toggle="modal" data-target="#addenrouteinitModal"><i class="fas fa-plus"></i> <?= __('plan.initiatives.addInitiativeLegacy') ?></button>      
                                <hr>
                            <?php } ?>                          
                            <center><div id="enroute_inits"></div></center>
                        </div>
                    </div>

                    <!-- Tab: Enroute Staffing -->
                    <div class="tab-pane fade" id="e_staffing">
                        <?php if ($perm == true) { ?>
                            <button class="btn btn-sm btn-success" data-toggle="modal" data-target="#addenroutestaffingModal"><i class="fas fa-plus"></i> <?= __('plan.staffing.addStaffing') ?></button>

                            <hr>
                        <?php } ?>

                        <button class="btn btn-sm btn-outline-secondary plan-group-toggle mb-2" data-table="enrouteStaffing"><i class="fas fa-list"></i> <?= __('plan.tables.flatView') ?></button>

                        <center><table class="table table-sm table-striped table-bordered w-75">
                            <thead>
                                <th class="text-center sortable" data-sort="facility_name" data-table="enrouteStaffing"><b><?= __('plan.staffing.facilityName') ?></b> <i class="fas fa-sort sort-icon"></i></th>
                                <th class="text-center sortable" data-sort="staffing_status" data-table="enrouteStaffing"><b><?= __('plan.staffing.status') ?></b> <i class="fas fa-sort sort-icon"></i></th>
                                <th class="text-center sortable" data-sort="staffing_quantity" data-table="enrouteStaffing"><b><?= __('plan.staffing.quantity') ?></b> <i class="fas fa-sort sort-icon"></i></th>
                                <th class="text-center"><b><?= __('plan.staffing.comments') ?></b></th>
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
                            <button class="btn btn-sm btn-success" data-toggle="modal" data-target="#addenrouteplanningModal"><i class="fas fa-plus"></i> <?= __('plan.planning.addPlan') ?></button>

                            <hr>
                        <?php } ?>

                        <div class="row gutters-tiny py-20" id="enrouteplanningdata"></div>
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
                                <span class="mx-2 text-muted">|</span>
                                <div class="custom-control custom-checkbox custom-control-inline">
                                    <input type="checkbox" class="custom-control-input" id="splits_layer_artcc" checked>
                                    <label class="custom-control-label" for="splits_layer_artcc">
                                        <span class="badge" style="background:#4682B4;color:#fff"><?= __('plan.splits.layerArtcc') ?></span>
                                    </label>
                                </div>
                                <div class="custom-control custom-checkbox custom-control-inline">
                                    <input type="checkbox" class="custom-control-input" id="splits_layer_superhigh">
                                    <label class="custom-control-label" for="splits_layer_superhigh">
                                        <span class="badge" style="background:#9932CC;color:#fff"><?= __('plan.splits.layerSuperhigh') ?></span>
                                    </label>
                                </div>
                                <div class="custom-control custom-checkbox custom-control-inline">
                                    <input type="checkbox" class="custom-control-input" id="splits_layer_high">
                                    <label class="custom-control-label" for="splits_layer_high">
                                        <span class="badge" style="background:#FF6347;color:#fff"><?= __('plan.splits.layerHigh') ?></span>
                                    </label>
                                </div>
                                <div class="custom-control custom-checkbox custom-control-inline">
                                    <input type="checkbox" class="custom-control-input" id="splits_layer_low">
                                    <label class="custom-control-label" for="splits_layer_low">
                                        <span class="badge" style="background:#228B22;color:#fff"><?= __('plan.splits.layerLow') ?></span>
                                    </label>
                                </div>
                                <div class="custom-control custom-checkbox custom-control-inline">
                                    <input type="checkbox" class="custom-control-input" id="splits_layer_tracon">
                                    <label class="custom-control-label" for="splits_layer_tracon">
                                        <span class="badge" style="background:#20B2AA;color:#fff"><?= __('plan.splits.layerTracon') ?></span>
                                    </label>
                                </div>
                            </div>
                            <div id="plan_splits_config_toggles" class="d-flex align-items-center flex-wrap small mt-1"></div>
                        </div>
                        <div id="plan_splits_container">
                            <div class="text-center text-muted py-4">
                                <i class="fas fa-spinner fa-spin"></i> <?= __('common.loading') ?>
                            </div>
                        </div>
                    </div>

                    <!-- Tab: Group Flights -->
                    <div class="tab-pane fade" id="group_flights">
                        <?php if ($perm == true) { ?>
                            <button class="btn btn-sm btn-success" data-toggle="modal" data-target="#addgroupflightModal"><i class="fas fa-plus"></i> <?= __('plan.groupFlights.addFlight') ?></button>

                            <hr>
                        <?php } ?>


                        <center><table class="table table-striped table-bordered w-75">
                            <thead>
                                <th class="text-center"><b><?= __('plan.groupFlights.groupEntity') ?></b></th>
                                <th class="text-center"><b><?= __('plan.groupFlights.dep') ?></b></th>
                                <th class="text-center"><b><?= __('plan.groupFlights.arr') ?></b></th>
                                <th class="text-center"><b><?= __('plan.groupFlights.etd') ?></b></th>
                                <th class="text-center"><b><?= __('plan.groupFlights.eta') ?></b></th>
                                <th class="text-center"><b><?= __('plan.staffing.quantity') ?></b></th>
                                <th class="text-center"><b><?= __('plan.groupFlights.route') ?></b></th>
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


<?php if ($perm == true) { ?>
<!-- Add Goal Modal -->
<div class="modal fade" id="addgoalModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><?= __('plan.modal.addGoalTitle') ?></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="addgoal">

                <div class="modal-body">

                    <input type="hidden" name="p_id" value="<?= $id; ?>">

                    <?= __('plan.modal.operationalGoalLabel') ?>:
                    <textarea class="form-control" name="comments" placeholder="Manage DTW airport operations to keep departure and airborne arrival delays to less than 30 minutes." rows="3"></textarea>

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-success" value="<?= __('plan.modal.add') ?>">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal"><?= __('plan.modal.close') ?></button>
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
                <h5 class="modal-title"><?= __('plan.modal.editGoalTitle') ?></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="editgoal">

                <div class="modal-body">

                    <input type="hidden" name="id" id="id">

                    <?= __('plan.modal.operationalGoalLabel') ?>:
                    <textarea class="form-control" name="comments" id="comments" placeholder="Manage DTW airport operations to keep departure and airborne arrival delays to less than 30 minutes." rows="3"></textarea>

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-warning" value="<?= __('plan.modal.edit') ?>">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal"><?= __('plan.modal.close') ?></button>
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
                <h5 class="modal-title"><?= __('plan.dcc.addPersonnel') ?></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="add_dccstaffing">

                <div class="modal-body">

                    <input type="hidden" name="p_id" value="<?= $id; ?>">

                    <?= __('plan.dcc.facilityType') ?>:
                    <select class="form-control" id="add_dcc_type" onchange="planDcc.onTypeChange('add')">
                        <option value=""><?= __('plan.dcc.selectType') ?></option>
                    </select>

                    <div id="add_dcc_service_row" style="display:none; margin-top:8px;">
                        <?= __('plan.dcc.service') ?>:
                        <select class="form-control" id="add_dcc_service" onchange="planDcc.onServiceChange('add')">
                            <option value=""><?= __('plan.dcc.selectTypeFirst') ?></option>
                        </select>
                    </div>

                    <div id="add_dcc_facility_row" style="display:none; margin-top:8px;">
                        <?= __('plan.dcc.facility') ?>:
                        <select class="form-control" id="add_dcc_facility">
                            <option value=""><?= __('plan.dcc.selectTypeFirst') ?></option>
                        </select>
                    </div>

                    <div style="margin-top:8px;">
                        <?= __('plan.dcc.role') ?>:
                        <select class="form-control" id="add_dcc_role">
                            <option value=""><?= __('plan.dcc.selectTypeFirst') ?></option>
                        </select>
                    </div>

                    <input type="hidden" name="position_facility" id="add_position_facility">
                    <input type="hidden" name="position_name" id="add_position_name">

                    <hr>

                    <?= __('plan.dcc.personnelNameLabel') ?>:
                    <input type="text" class="form-control" name="personnel_name" maxlength="128" placeholder="<?= __('plan.dcc.leaveBlankForVacancy') ?>">

                    <?= __('plan.dcc.personnelOis') ?>:
                    <input type="text" class="form-control" name="personnel_ois" maxlength="2" placeholder="<?= __('plan.dcc.leaveBlankForVacancy') ?>">

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-success" value="<?= __('plan.modal.add') ?>">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal"><?= __('plan.modal.close') ?></button>
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
                <h5 class="modal-title"><?= __('plan.dcc.editPersonnel') ?></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="edit_dccstaffing">

                <div class="modal-body">

                    <input type="hidden" name="id" id="id">

                    <?= __('plan.dcc.facilityType') ?>:
                    <select class="form-control" id="edit_dcc_type" onchange="planDcc.onTypeChange('edit')">
                        <option value=""><?= __('plan.dcc.selectType') ?></option>
                    </select>

                    <div id="edit_dcc_service_row" style="display:none; margin-top:8px;">
                        <?= __('plan.dcc.service') ?>:
                        <select class="form-control" id="edit_dcc_service" onchange="planDcc.onServiceChange('edit')">
                            <option value=""><?= __('plan.dcc.selectTypeFirst') ?></option>
                        </select>
                    </div>

                    <div id="edit_dcc_facility_row" style="display:none; margin-top:8px;">
                        <?= __('plan.dcc.facility') ?>:
                        <select class="form-control" id="edit_dcc_facility">
                            <option value=""><?= __('plan.dcc.selectTypeFirst') ?></option>
                        </select>
                    </div>

                    <div style="margin-top:8px;">
                        <?= __('plan.dcc.role') ?>:
                        <select class="form-control" id="edit_dcc_role">
                            <option value=""><?= __('plan.dcc.selectTypeFirst') ?></option>
                        </select>
                    </div>

                    <input type="hidden" name="position_facility" id="edit_position_facility">
                    <input type="hidden" name="position_name" id="edit_position_name">

                    <hr>

                    <?= __('plan.dcc.personnelNameLabel') ?>:
                    <input type="text" class="form-control" name="personnel_name" id="personnel_name" maxlength="128" placeholder="<?= __('plan.dcc.leaveBlankForVacancy') ?>">

                    <?= __('plan.dcc.personnelOis') ?>:
                    <input type="text" class="form-control" name="personnel_ois" id="personnel_ois" maxlength="2" placeholder="<?= __('plan.dcc.leaveBlankForVacancy') ?>">

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-warning" value="<?= __('plan.modal.edit') ?>">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal"><?= __('plan.modal.close') ?></button>
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
                <h5 class="modal-title"><?= __('plan.historicalTab.addTitle') ?></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="addhistorical">

                <div class="modal-body">

                    <input type="hidden" name="p_id" value="<?= $id; ?>">

                    <?= __('plan.historicalTab.title') ?>:
                    <input type="text" class="form-control" name="title">

                    <?= __('plan.historicalTab.date') ?>:
                    <input type="text" class="form-control" id="ah_date" name="date" readonly>

                    <?= __('plan.historicalTab.summary') ?>:
                    <textarea class="form-control" name="summary" rows="3"></textarea>

                    <hr>

                    <?= __('plan.historicalTab.imageUrl') ?>:
                    <input type="text" class="form-control" name="image_url">

                    <?= __('plan.historicalTab.sourceUrl') ?>:
                    <input type="text" class="form-control" name="source_url">

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-success" value="<?= __('plan.modal.add') ?>">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal"><?= __('plan.modal.close') ?></button>
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
                <h5 class="modal-title"><?= __('plan.historicalTab.editTitle') ?></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="edithistorical">

                <div class="modal-body">

                    <input type="hidden" name="id" id="id">

                    <?= __('plan.historicalTab.title') ?>:
                    <input type="text" class="form-control" name="title" id="title">

                    <?= __('plan.historicalTab.date') ?>:
                    <input type="text" class="form-control" id="eh_date" name="date" readonly>

                    <?= __('plan.historicalTab.summary') ?>:
                    <textarea class="form-control" name="summary" id="summary" rows="3"></textarea>

                    <hr>

                    <?= __('plan.historicalTab.imageUrl') ?>:
                    <input type="text" class="form-control" name="image_url" id="image_url">

                    <?= __('plan.historicalTab.sourceUrl') ?>:
                    <input type="text" class="form-control" name="source_url" id="source_url">

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-warning" value="<?= __('plan.modal.edit') ?>">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal"><?= __('plan.modal.close') ?></button>
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
                <h5 class="modal-title"><?= __('plan.forecastTab.addTitle') ?></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="addforecast">

                <div class="modal-body">

                    <input type="hidden" name="p_id" value="<?= $id; ?>">

                    <?= __('plan.historicalTab.date') ?>:
                    <input type="text" class="form-control" id="af_date" name="date" readonly>

                    <?= __('plan.historicalTab.summary') ?>:
                    <textarea class="form-control" name="summary" rows="3"></textarea>

                    <hr>

                    <?= __('plan.historicalTab.imageUrl') ?>:
                    <input type="text" class="form-control" name="image_url">

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-success" value="<?= __('plan.modal.add') ?>">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal"><?= __('plan.modal.close') ?></button>
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
                <h5 class="modal-title"><?= __('plan.forecastTab.editTitle') ?></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="editforecast">

                <div class="modal-body">

                    <input type="hidden" name="id" id="id">

                    <?= __('plan.historicalTab.date') ?>:
                    <input type="text" class="form-control" id="ef_date" name="date" readonly>

                    <?= __('plan.historicalTab.summary') ?>:
                    <textarea class="form-control" name="summary" id="summary" rows="3"></textarea>

                    <hr>

                    <?= __('plan.historicalTab.imageUrl') ?>:
                    <input type="text" class="form-control" name="image_url" id="image_url">

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-warning" value="<?= __('plan.modal.edit') ?>">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal"><?= __('plan.modal.close') ?></button>
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
                <h5 class="modal-title"><?= __('plan.initiatives.addTerminalTitle') ?></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="addterminalinit">

                <div class="modal-body">

                    <input type="hidden" name="p_id" value="<?= $id; ?>">

                    <?= __('plan.initiatives.facilityInitiative') ?>:
                    <input type="text" class="form-control" name="title" placeholder="D21/PCT - CFR/Metering/MIT">

                    <?= __('plan.initiatives.causeContext') ?>:
                    <input type="text" class="form-control" name="context" placeholder="Volume">

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-success" value="<?= __('plan.modal.add') ?>">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal"><?= __('plan.modal.close') ?></button>
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
                <h5 class="modal-title"><?= __('plan.initiatives.editTerminalTitle') ?></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="editterminalinit">

                <div class="modal-body">

                    <input type="hidden" name="id" id="id">

                    <?= __('plan.initiatives.facilityInitiative') ?>:
                    <input type="text" class="form-control" name="title" id="title" placeholder="D21/PCT - CFR/Metering/MIT">

                    <?= __('plan.initiatives.causeContext') ?>:
                    <input type="text" class="form-control" name="context" id="context" placeholder="Volume">

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-warning" value="<?= __('plan.modal.edit') ?>">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal"><?= __('plan.modal.close') ?></button>
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
                <h5 class="modal-title"><?= __('plan.staffing.addTerminalTitle') ?></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="addtermstaffing">

                <div class="modal-body">

                    <input type="hidden" name="p_id" value="<?= $id; ?>">

                    <?= __('plan.staffing.facilityName') ?>:
                    <input type="text" class="form-control" name="facility_name" placeholder="SCT - SoCal TRACON" required>

                    <?= __('plan.staffing.staffingStatus') ?>:
                    <select class="form-control" name="staffing_status">
                        <option value="0"><?= __('plan.staffing.statusUnknown') ?></option>
                        <option value="3"><?= __('plan.staffing.statusUnderstaffed') ?></option>
                        <option value="1"><?= __('plan.staffing.statusTopDown') ?></option>
                        <option value="2"><?= __('plan.staffing.statusYes') ?></option>
                        <option value="4"><?= __('plan.staffing.statusNo') ?></option>
                    </select>

                    <?= __('plan.staffing.staffingQuantity') ?>:
                    <input type="text" class="form-control" name="staffing_quantity" maxlength="2" required>

                    <?= __('plan.staffing.comments') ?>:
                    <input type="text" class="form-control" name="comments">

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-success" value="<?= __('plan.modal.add') ?>">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal"><?= __('plan.modal.close') ?></button>
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
                <h5 class="modal-title"><?= __('plan.staffing.editTerminalTitle') ?></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="edittermstaffing">

                <div class="modal-body">

                    <input type="hidden" name="id" id="id">

                    <?= __('plan.staffing.facilityName') ?>:
                    <input type="text" class="form-control" name="facility_name" id="facility_name" placeholder="SCT - SoCal TRACON" required>

                    <?= __('plan.staffing.staffingStatus') ?>:
                    <select class="form-control" name="staffing_status" id="staffing_status">
                        <option value="0"><?= __('plan.staffing.statusUnknown') ?></option>
                        <option value="3"><?= __('plan.staffing.statusUnderstaffed') ?></option>
                        <option value="1"><?= __('plan.staffing.statusTopDown') ?></option>
                        <option value="2"><?= __('plan.staffing.statusYes') ?></option>
                        <option value="4"><?= __('plan.staffing.statusNo') ?></option>
                    </select>

                    <?= __('plan.staffing.staffingQuantity') ?>:
                    <input type="text" class="form-control" name="staffing_quantity" id="staffing_quantity" maxlength="2" required>

                    <?= __('plan.staffing.comments') ?>:
                    <input type="text" class="form-control" name="comments" id="comments">

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-warning" value="<?= __('plan.modal.edit') ?>">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal"><?= __('plan.modal.close') ?></button>
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
                <h5 class="modal-title"><?= __('plan.configTab.addTitle') ?></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="addconfig">

                <div class="modal-body">

                    <input type="hidden" name="p_id" value="<?= $id; ?>">

                    <!-- Config Picker Section -->
                    <div class="form-group">
                        <div class="custom-control custom-checkbox mb-2">
                            <input type="checkbox" class="custom-control-input" id="addconfig_use_adl">
                            <label class="custom-control-label" for="addconfig_use_adl"><?= __('plan.configTab.loadFromAdl') ?></label>
                        </div>
                        <div id="addconfig_picker" style="display: none;">
                            <select class="form-control mb-2" id="addconfig_select" disabled>
                                <option value=""><?= __('plan.configTab.selectConfiguration') ?></option>
                            </select>
                            <small class="text-muted"><?= __('plan.configTab.selectConfigHintAdd') ?></small>
                        </div>
                    </div>

                    <hr class="my-2">

                    <?= __('plan.configTab.field') ?>:
                    <input type="text" class="form-control" name="airport" id="addconfig_airport" placeholder="BWI" maxlength="4" required>

                    <?= __('plan.configTab.meteorologicalCondition') ?>:
                    <select class="form-control" name="weather" id="addconfig_weather">
                        <option value="0"><?= __('plan.staffing.statusUnknown') ?></option>
                        <option value="1">VMC</option>
                        <option value="2">LVMC</option>
                        <option value="3">IMC</option>
                        <option value="4">LIMC</option>
                    </select>

                    <?= __('plan.configTab.arrivalRunways') ?>:
                    <input type="text" class="form-control" name="arrive" id="addconfig_arrive" placeholder="33L/33R">

                    <?= __('plan.configTab.departureRunways') ?>:
                    <input type="text" class="form-control" name="depart" id="addconfig_depart" placeholder="33R/28">

                    <?= __('plan.configTab.airportArrivalRate') ?>:
                    <input type="text" class="form-control" name="aar" id="addconfig_aar" maxlength="3">

                    <?= __('plan.configTab.airportDepartureRate') ?>:
                    <input type="text" class="form-control" name="adr" id="addconfig_adr" maxlength="3">

                    <?= __('plan.staffing.comments') ?>:
                    <input type="text" class="form-control" name="comments">

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-success" value="<?= __('plan.modal.add') ?>">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal"><?= __('plan.modal.close') ?></button>
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
                <h5 class="modal-title"><?= __('plan.configTab.editTitle') ?></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="editconfig">

                <div class="modal-body">

                    <input type="hidden" name="id" id="editconfig_id">

                    <!-- Config Picker Section -->
                    <div class="form-group">
                        <div class="custom-control custom-checkbox mb-2">
                            <input type="checkbox" class="custom-control-input" id="editconfig_use_adl">
                            <label class="custom-control-label" for="editconfig_use_adl"><?= __('plan.configTab.loadFromAdl') ?></label>
                        </div>
                        <div id="editconfig_picker" style="display: none;">
                            <select class="form-control mb-2" id="editconfig_select" disabled>
                                <option value=""><?= __('plan.configTab.selectConfiguration') ?></option>
                            </select>
                            <small class="text-muted"><?= __('plan.configTab.selectConfigHintEdit') ?></small>
                        </div>
                    </div>

                    <hr class="my-2">

                    <?= __('plan.configTab.field') ?>:
                    <input type="text" class="form-control" name="airport" id="editconfig_airport" placeholder="BWI" maxlength="4" required>

                    <?= __('plan.configTab.meteorologicalCondition') ?>:
                    <select class="form-control" name="weather" id="editconfig_weather">
                        <option value="0"><?= __('plan.staffing.statusUnknown') ?></option>
                        <option value="1">VMC</option>
                        <option value="2">LVMC</option>
                        <option value="3">IMC</option>
                        <option value="4">LIMC</option>
                    </select>

                    <?= __('plan.configTab.arrivalRunways') ?>:
                    <input type="text" class="form-control" name="arrive" id="editconfig_arrive" placeholder="33L/33R">

                    <?= __('plan.configTab.departureRunways') ?>:
                    <input type="text" class="form-control" name="depart" id="editconfig_depart" placeholder="33R/28">

                    <?= __('plan.configTab.airportArrivalRate') ?>:
                    <input type="text" class="form-control" name="aar" id="editconfig_aar" maxlength="3">

                    <?= __('plan.configTab.airportDepartureRate') ?>:
                    <input type="text" class="form-control" name="adr" id="editconfig_adr" maxlength="3">

                    <?= __('plan.staffing.comments') ?>:
                    <input type="text" class="form-control" name="comments" id="editconfig_comments">

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-warning" value="<?= __('plan.modal.edit') ?>">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal"><?= __('plan.modal.close') ?></button>
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
                <h5 class="modal-title"><?= __('plan.planning.addTerminalTitle') ?></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="addtermplanning">

                <div class="modal-body">

                    <input type="hidden" name="p_id" value="<?= $id; ?>">

                    <?= __('plan.staffing.facilityName') ?>:
                    <input type="text" class="form-control" name="facility_name" required>

                    <?= __('plan.staffing.comments') ?>:
                    <textarea class="form-control rounded-0" name="comments" id="atp_comments" rows="5"></textarea>

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-success" value="<?= __('plan.modal.add') ?>">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal"><?= __('plan.modal.close') ?></button>
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
                <h5 class="modal-title"><?= __('plan.planning.editTerminalTitle') ?></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="edittermplanning">

                <div class="modal-body">

                    <input type="hidden" name="id" id="id">

                    <?= __('plan.staffing.facilityName') ?>:
                    <input type="text" class="form-control" name="facility_name" id="facility_name" required>

                    <?= __('plan.staffing.comments') ?>:
                    <textarea class="form-control rounded-0" name="comments" id="etp_comments" rows="5"></textarea>

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-warning" value="<?= __('plan.modal.edit') ?>">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal"><?= __('plan.modal.close') ?></button>
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
                <h5 class="modal-title"><?= __('plan.constraints.addTerminalTitle') ?></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="addtermconstraint">

                <div class="modal-body">

                    <input type="hidden" name="p_id" value="<?= $id; ?>">

                    <?= __('plan.constraints.location') ?>:
                    <input type="text" class="form-control" name="location" placeholder="DTW/IAD/BWI">

                    <?= __('plan.initiatives.causeContext') ?>:
                    <input type="text" class="form-control" name="context" placeholder="WX">

                    <?= __('plan.constraints.throughDate') ?>:
                    <input type="text" class="form-control" name="date" id="at_date" readonly>

                    <?= __('plan.constraints.impact') ?>:
                    <input type="text" class="form-control" name="impact" placeholder="Reduced AAR">

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-success" value="<?= __('plan.modal.add') ?>">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal"><?= __('plan.modal.close') ?></button>
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
                <h5 class="modal-title"><?= __('plan.constraints.editTerminalTitle') ?></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="edittermconstraint">

                <div class="modal-body">

                    <input type="hidden" name="id" id="id">

                    <?= __('plan.constraints.location') ?>:
                    <input type="text" class="form-control" name="location" id="location" placeholder="DTW/IAD/BWI">

                    <?= __('plan.initiatives.causeContext') ?>:
                    <input type="text" class="form-control" name="context" id="context" placeholder="WX">

                    <?= __('plan.constraints.throughDate') ?>:
                    <input type="text" class="form-control" name="date" id="et_date" readonly>

                    <?= __('plan.constraints.impact') ?>:
                    <input type="text" class="form-control" name="impact" id="impact" placeholder="Reduced AAR">

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-warning" value="<?= __('plan.modal.edit') ?>">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal"><?= __('plan.modal.close') ?></button>
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
                <h5 class="modal-title"><?= __('plan.initiatives.addEnrouteTitle') ?></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="addenrouteinit">

                <div class="modal-body">

                    <input type="hidden" name="p_id" value="<?= $id; ?>">

                    <?= __('plan.initiatives.facilityInitiative') ?>:
                    <input type="text" class="form-control" name="title" placeholder="ZOB/ZDC/ZNY - Reroutes">

                    <?= __('plan.initiatives.causeContext') ?>:
                    <input type="text" class="form-control" name="context" placeholder="Structure">

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-success" value="<?= __('plan.modal.add') ?>">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal"><?= __('plan.modal.close') ?></button>
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
                <h5 class="modal-title"><?= __('plan.initiatives.editEnrouteTitle') ?></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="editenrouteinit">

                <div class="modal-body">

                    <input type="hidden" name="id" id="id">

                    <?= __('plan.initiatives.facilityInitiative') ?>:
                    <input type="text" class="form-control" name="title" id="title" placeholder="ZOB/ZDC/ZNY - Reroutes">

                    <?= __('plan.initiatives.causeContext') ?>:
                    <input type="text" class="form-control" name="context" id="context" placeholder="Structure">

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-warning" value="<?= __('plan.modal.edit') ?>">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal"><?= __('plan.modal.close') ?></button>
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
                <h5 class="modal-title"><?= __('plan.staffing.addEnrouteTitle') ?></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="addenroutestaffing">

                <div class="modal-body">

                    <input type="hidden" name="p_id" value="<?= $id; ?>">

                    <?= __('plan.staffing.facilityName') ?>:
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
                        <option>TJZS - San Juan FIR</option>
                        <option>CZVR - Vancouver FIR</option>
                        <option>CZEG - Edmonton FIR</option>
                        <option>CZWG - Winnipeg FIR</option>
                        <option>CZYZ - Toronto FIR</option>
                        <option>CZUL - Montreal FIR</option>
                        <option>CZQM - Moncton FIR</option>

                        <option>CZQX - Gander FIR</option>
                        <option>CZQX - Gander OCA</option>
                        <option>MUFH - Havana FIR</option>
                        <option>MYNN - Nassau FIR</option>
                        <option>MMZT - Mazatlan FIR</option>
                        <option>MMTY - Monterrey FIR</option>
                        <option>MMID - Merida FIR</option>
                    </select>

                    <?= __('plan.staffing.staffingStatus') ?>:
                    <select class="form-control" name="staffing_status">
                        <option value="0"><?= __('plan.staffing.statusUnknown') ?></option>
                        <option value="2"><?= __('plan.staffing.statusUnderstaffed') ?></option>
                        <option value="1"><?= __('plan.staffing.statusYes') ?></option>
                        <option value="3"><?= __('plan.staffing.statusNo') ?></option>
                    </select>

                    <?= __('plan.staffing.staffingQuantity') ?>:
                    <input type="text" class="form-control" name="staffing_quantity" maxlength="2" required>

                    <?= __('plan.staffing.comments') ?>:
                    <input type="text" class="form-control" name="comments">

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-success" value="<?= __('plan.modal.add') ?>">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal"><?= __('plan.modal.close') ?></button>
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
                <h5 class="modal-title"><?= __('plan.staffing.editEnrouteTitle') ?></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="editenroutestaffing">

                <div class="modal-body">

                    <input type="hidden" name="id" id="id">

                    <?= __('plan.staffing.facilityName') ?>:
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
                        <option>TJZS - San Juan FIR</option>
                        <option>CZVR - Vancouver FIR</option>
                        <option>CZEG - Edmonton FIR</option>
                        <option>CZWG - Winnipeg FIR</option>
                        <option>CZYZ - Toronto FIR</option>
                        <option>CZUL - Montreal FIR</option>
                        <option>CZQM - Moncton FIR</option>

                        <option>CZQX - Gander FIR</option>
                        <option>CZQX - Gander OCA</option>
                        <option>MUFH - Havana FIR</option>
                        <option>MYNN - Nassau FIR</option>
                        <option>MMZT - Mazatlan FIR</option>
                        <option>MMTY - Monterrey FIR</option>
                        <option>MMID - Merida FIR</option>
                    </select>

                    <?= __('plan.staffing.staffingStatus') ?>:
                    <select class="form-control" name="staffing_status" id="staffing_status">
                        <option value="0"><?= __('plan.staffing.statusUnknown') ?></option>
                        <option value="2"><?= __('plan.staffing.statusUnderstaffed') ?></option>
                        <option value="1"><?= __('plan.staffing.statusYes') ?></option>
                        <option value="3"><?= __('plan.staffing.statusNo') ?></option>
                    </select>

                    <?= __('plan.staffing.staffingQuantity') ?>:
                    <input type="text" class="form-control" name="staffing_quantity" id="staffing_quantity" maxlength="2" required>

                    <?= __('plan.staffing.comments') ?>:
                    <input type="text" class="form-control" name="comments" id="comments">

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-warning" value="<?= __('plan.modal.edit') ?>">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal"><?= __('plan.modal.close') ?></button>
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
                <h5 class="modal-title"><?= __('plan.planning.addEnrouteTitle') ?></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="addenrouteplanning">

                <div class="modal-body">

                    <input type="hidden" name="p_id" value="<?= $id; ?>">

                    <?= __('plan.staffing.facilityName') ?>:
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
                        <option>TJZS - San Juan FIR</option>
                        <option>CZVR - Vancouver FIR</option>
                        <option>CZEG - Edmonton FIR</option>
                        <option>CZWG - Winnipeg FIR</option>
                        <option>CZYZ - Toronto FIR</option>
                        <option>CZUL - Montreal FIR</option>
                        <option>CZQM - Moncton FIR</option>

                        <option>CZQX - Gander FIR</option>
                        <option>CZQX - Gander OCA</option>
                        <option>MUFH - Havana FIR</option>
                        <option>MYNN - Nassau FIR</option>
                        <option>MMZT - Mazatlan FIR</option>
                        <option>MMTY - Monterrey FIR</option>
                        <option>MMID - Merida FIR</option>
                    </select>

                    <?= __('plan.staffing.comments') ?>:
                    <textarea class="form-control rounded-0" name="comments" id="aep_comments" rows="5"></textarea>

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-success" value="<?= __('plan.modal.add') ?>">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal"><?= __('plan.modal.close') ?></button>
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
                <h5 class="modal-title"><?= __('plan.planning.editEnrouteTitle') ?></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="editenrouteplanning">

                <div class="modal-body">

                    <input type="hidden" name="id" id="id">

                    <?= __('plan.staffing.facilityName') ?>:
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
                        <option>TJZS - San Juan FIR</option>
                        <option>CZVR - Vancouver FIR</option>
                        <option>CZEG - Edmonton FIR</option>
                        <option>CZWG - Winnipeg FIR</option>
                        <option>CZYZ - Toronto FIR</option>
                        <option>CZUL - Montreal FIR</option>
                        <option>CZQM - Moncton FIR</option>

                        <option>CZQX - Gander FIR</option>
                        <option>CZQX - Gander OCA</option>
                        <option>MUFH - Havana FIR</option>
                        <option>MYNN - Nassau FIR</option>
                        <option>MMZT - Mazatlan FIR</option>
                        <option>MMTY - Monterrey FIR</option>
                        <option>MMID - Merida FIR</option>
                    </select>

                    <?= __('plan.staffing.comments') ?>:
                    <textarea class="form-control rounded-0" name="comments" id="eep_comments" rows="5"></textarea>

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-warning" value="<?= __('plan.modal.edit') ?>">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal"><?= __('plan.modal.close') ?></button>
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
                <h5 class="modal-title"><?= __('plan.constraints.addEnrouteTitle') ?></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="addenrouteconstraint">

                <div class="modal-body">

                    <input type="hidden" name="p_id" value="<?= $id; ?>">

                    <?= __('plan.constraints.location') ?>:
                    <input type="text" class="form-control" name="location" placeholder="ZJX/ZMA/ZHU">

                    <?= __('plan.initiatives.causeContext') ?>:
                    <input type="text" class="form-control" name="context" placeholder="WX">

                    <?= __('plan.constraints.throughDate') ?>:
                    <input type="text" class="form-control" name="date" id="ae_date" readonly>

                    <?= __('plan.constraints.impact') ?>:
                    <input type="text" class="form-control" name="impact" placeholder="Reduced AAR">

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-success" value="<?= __('plan.modal.add') ?>">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal"><?= __('plan.modal.close') ?></button>
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
                <h5 class="modal-title"><?= __('plan.constraints.editEnrouteTitle') ?></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="editenrouteconstraint">

                <div class="modal-body">

                    <input type="hidden" name="id" id="id">

                    <?= __('plan.constraints.location') ?>:
                    <input type="text" class="form-control" name="location" id="location" placeholder="ZJX/ZMA/ZHU">

                    <?= __('plan.initiatives.causeContext') ?>:
                    <input type="text" class="form-control" name="context" id="context" placeholder="WX">

                    <?= __('plan.constraints.throughDate') ?>:
                    <input type="text" class="form-control" name="date" id="ee_date" readonly>

                    <?= __('plan.constraints.impact') ?>:
                    <input type="text" class="form-control" name="impact" id="impact" placeholder="Reduced AAR">

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-warning" value="<?= __('plan.modal.edit') ?>">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal"><?= __('plan.modal.close') ?></button>
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
                <h5 class="modal-title"><?= __('plan.groupFlights.addTitle') ?></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="addgroupflight">

                <div class="modal-body">

                    <input type="hidden" name="p_id" value="<?= $id; ?>">

                    <?= __('plan.groupFlights.groupEntityName') ?>:
                    <input type="text" class="form-control" name="entity" required>

                    <?= __('plan.groupFlights.departureField') ?>:
                    <input type="text" class="form-control" name="dep" placeholder="DTW" maxlength="4" required>

                    <?= __('plan.groupFlights.arrivalField') ?>:
                    <input type="text" class="form-control" name="arr" placeholder="BWI" maxlength="4" required>

                    <?= __('plan.groupFlights.etdZulu') ?>:
                    <input type="text" class="form-control" name="etd" placeholder="2300" maxlength="4">

                    <?= __('plan.groupFlights.etaZulu') ?>:
                    <input type="text" class="form-control" name="eta" placeholder="0100" maxlength="4">

                    <?= __('plan.groupFlights.pilotQuantity') ?>:
                    <input type="text" class="form-control" name="pilot_quantity" placeholder="5" value="0" maxlength="2" required>

                    <?= __('plan.groupFlights.route') ?>:
                    <textarea class="form-control" name="route" id="route" rows="3"></textarea>

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-success" value="<?= __('plan.modal.add') ?>">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal"><?= __('plan.modal.close') ?></button>
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
                <h5 class="modal-title"><?= __('plan.groupFlights.editTitle') ?></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="editgroupflight">

                <div class="modal-body">

                    <input type="hidden" name="id" id="id">

                    <?= __('plan.groupFlights.groupEntityName') ?>:
                    <input type="text" class="form-control" name="entity" id="entity" required>

                    <?= __('plan.groupFlights.departureField') ?>:
                    <input type="text" class="form-control" name="dep" id="dep" placeholder="DTW" maxlength="4" required>

                    <?= __('plan.groupFlights.arrivalField') ?>:
                    <input type="text" class="form-control" name="arr" id="arr" placeholder="BWI" maxlength="4" required>

                    <?= __('plan.groupFlights.etdZulu') ?>:
                    <input type="text" class="form-control" name="etd" id="etd" placeholder="2300" maxlength="4">

                    <?= __('plan.groupFlights.etaZulu') ?>:
                    <input type="text" class="form-control" name="eta" id="eta" placeholder="0100" maxlength="4">

                    <?= __('plan.groupFlights.pilotQuantity') ?>:
                    <input type="text" class="form-control" name="pilot_quantity" id="pilot_quantity" placeholder="5" maxlength="2" required>

                    <?= __('plan.groupFlights.route') ?>:
                    <textarea class="form-control" name="route" id="route" rows="3"></textarea>

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-warning" value="<?= __('plan.modal.edit') ?>">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal"><?= __('plan.modal.close') ?></button>
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
                <h5 class="modal-title" id="pertiModalLabel"><?= __('plan.pertiModal.title') ?></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">

            <!-- Start / End date & time (all UTC) -->
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label for="pertiStartDate"><?= __('plan.pertiModal.startDateUtc') ?></label>
                    <input type="date" class="form-control form-control-sm" id="pertiStartDate">
                    <small class="form-text text-muted">
                        <?= __('plan.pertiModal.startDateHint') ?>
                    </small>
                </div>
                <div class="form-group col-md-6">
                    <label for="pertiStartTime"><?= __('plan.pertiModal.startTimeUtc') ?></label>
                    <input type="text" class="form-control form-control-sm" id="pertiStartTime" placeholder="e.g. 1800">
                    <small class="form-text text-muted">
                        <?= __('plan.pertiModal.startTimeHint') ?>
                    </small>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label for="pertiEndDate"><?= __('plan.pertiModal.endDateUtc') ?></label>
                    <input type="date" class="form-control form-control-sm" id="pertiEndDate">
                    <small class="form-text text-muted">
                        <?= __('plan.pertiModal.endDateHint') ?>
                    </small>
                </div>
                <div class="form-group col-md-6">
                    <label for="pertiEndTime"><?= __('plan.pertiModal.endTimeUtc') ?></label>
                    <input type="text" class="form-control form-control-sm" id="pertiEndTime" placeholder="e.g. 0300">
                    <small class="form-text text-muted">
                        <?= __('plan.pertiModal.endTimeHint') ?>
                    </small>
                </div>
            </div>

                <!-- Facilities selector -->
                <div class="form-group mt-3">
                    <label class="small mb-0" for="advFacilities"><?= __('plan.pertiModal.facilitiesIncluded') ?></label>
                    <div class="adv-facilities-wrapper">
                        <div class="input-group input-group-sm">
                            <input type="text"
                                   class="form-control form-control-sm"
                                   id="advFacilities"
                                   placeholder="ZTL/ZDC/ZNY">
                            <div class="input-group-append">
                                <button class="btn btn-outline-secondary" type="button" id="advFacilitiesToggle">
                                    <?= __('plan.pertiModal.select') ?>
                                </button>
                            </div>
                        </div>
                        <div id="advFacilitiesDropdown" class="adv-facilities-dropdown">
                            <div class="adv-facilities-grid" id="advFacilitiesGrid">
                                <!-- Populated by JS -->
                            </div>
                            <div class="mt-2 d-flex justify-content-between align-items-center">
                                <div>
                                    <button type="button" class="btn btn-sm btn-light mr-2" id="advFacilitiesClear"><?= __('plan.pertiModal.clear') ?></button>
                                    <button type="button" class="btn btn-sm btn-light mr-2" id="advFacilitiesSelectAll"><?= __('plan.pertiModal.all') ?></button>
                                    <button type="button" class="btn btn-sm btn-light" id="advFacilitiesSelectUs"><?= __('plan.pertiModal.usArtccs') ?></button>
                                </div>
                                <div>
                                    <button type="button" class="btn btn-sm btn-primary" id="advFacilitiesApply"><?= __('plan.pertiModal.apply') ?></button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Final message -->
                <div class="form-group mt-3">
                    <label for="pertiMessage"><?= __('plan.pertiModal.notificationText') ?></label>
                    <textarea class="form-control" id="pertiMessage" rows="20" style="font-family: Menlo, Consolas, monospace;"></textarea>
                </div>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal"><?= __('plan.modal.close') ?></button>
                <button type="button" class="btn btn-sm btn-outline-primary" id="pertiCopyBtn"><?= __('plan.pertiModal.copyToClipboard') ?></button>
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
                <h5 class="modal-title" id="opsPlanModalLabel"><?= __('plan.opsPlanModal.title') ?></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="container-fluid">
                    <div class="form-row">
                        <div class="form-group col-md-3">
                            <label for="opsAdvNum"><?= __('plan.opsPlanModal.advisoryNumber') ?></label>
                            <input type="text" class="form-control form-control-sm" id="opsAdvNum" placeholder="e.g. 001">
                        </div>
                        <div class="form-group col-md-5">
                            <label for="opsAdvDate"><?= __('plan.opsPlanModal.advisoryDateUtc') ?></label>
                            <input type="text" class="form-control form-control-sm" id="opsAdvDate" placeholder="mm/dd/yyyy">
                            <small class="form-text text-muted">
                                <?= __('plan.opsPlanModal.advisoryDateHint') ?>
                            </small>
                        </div>
                    </div>

                    <hr class="my-2">

                    <div class="form-row">
                        <div class="form-group col-md-3">
                            <label for="opsStartDate"><?= __('plan.opsPlanModal.startDateUtc') ?></label>
                            <input type="date" class="form-control form-control-sm" id="opsStartDate">
                        </div>
                        <div class="form-group col-md-3">
                            <label for="opsStartTime"><?= __('plan.opsPlanModal.startTimeUtc') ?></label>
                            <input type="text" class="form-control form-control-sm" id="opsStartTime" placeholder="e.g. 2359">
                        </div>
                        <div class="form-group col-md-3">
                            <label for="opsEndDate"><?= __('plan.opsPlanModal.endDateUtc') ?></label>
                            <input type="date" class="form-control form-control-sm" id="opsEndDate">
                        </div>
                        <div class="form-group col-md-3">
                            <label for="opsEndTime"><?= __('plan.opsPlanModal.endTimeUtc') ?></label>
                            <input type="text" class="form-control form-control-sm" id="opsEndTime" placeholder="e.g. 0400">
                        </div>
                    </div>

                    <div class="form-group mt-2">
                        <label for="opsNarrative"><?= __('plan.opsPlanModal.narrative') ?></label>
                        <textarea class="form-control" id="opsNarrative" rows="4"></textarea>
                        <small class="form-text text-muted">
                            <?= __('plan.opsPlanModal.narrativeHint') ?>
                        </small>
                    </div>

                    <div class="form-group mt-2">
                        <label for="opsPlanMessage"><?= __('plan.opsPlanModal.outputLabel') ?></label>
                        <small class="form-text text-muted mb-1">
                            <?= __('plan.opsPlanModal.outputHint') ?>
                        </small>
                        <textarea class="form-control" id="opsPlanMessage" rows="18" style="font-family: Menlo, Consolas, monospace;"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal"><?= __('plan.modal.close') ?></button>
                <button type="button" class="btn btn-sm btn-outline-primary" id="opsPlanCopyBtn"><?= __('plan.opsPlanModal.copyToClipboard') ?></button>
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
                <h5 class="modal-title"><i class="fas fa-globe mr-2"></i><?= __('plan.advisoryOrg.title') ?></h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="advisoryOrg" id="orgDCC" value="DCC">
                    <label class="form-check-label" for="orgDCC">
                        <strong><?= __('plan.advisoryOrg.usDcc') ?></strong><br><small class="text-muted"><?= __('plan.advisoryOrg.usDccHint') ?></small>
                    </label>
                </div>
                <div class="form-check mt-3">
                    <input class="form-check-input" type="radio" name="advisoryOrg" id="orgNOC" value="NOC">
                    <label class="form-check-label" for="orgNOC">
                        <strong><?= __('plan.advisoryOrg.canadianNoc') ?></strong><br><small class="text-muted"><?= __('plan.advisoryOrg.canadianNocHint') ?></small>
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal"><?= __('plan.advisoryOrg.cancel') ?></button>
                <button type="button" class="btn btn-primary btn-sm" id="advisoryOrgSaveBtn" onclick="AdvisoryConfig.saveOrg()"><?= __('plan.advisoryOrg.save') ?></button>
            </div>
        </div>
    </div>
</div>
<!-- End Advisory Organization Config Modal -->

<!-- Insert advisory-config.js Script -->
<script src="assets/js/advisory-config.js<?= _v('assets/js/advisory-config.js') ?>"></script>

<!-- Insert facility-roles config (shared with JATOC) -->
<script src="assets/js/config/facility-roles.js<?= _v('assets/js/config/facility-roles.js') ?>"></script>

<!-- Insert plan-tables.js + plan-splits-map.js + plan.js Scripts -->
<script src="assets/js/plan-tables.js<?= _v('assets/js/plan-tables.js') ?>"></script>
<script src="assets/js/plan-splits-map.js<?= _v('assets/js/plan-splits-map.js') ?>"></script>
<script src="assets/js/plan.js<?= _v('assets/js/plan.js') ?>"></script>

<!-- Insert Initiative Timeline Script -->
<script src="assets/js/initiative_timeline.js<?= _v('assets/js/initiative_timeline.js') ?>"></script>
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

</html>
