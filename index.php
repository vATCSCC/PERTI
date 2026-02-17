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

    // Load organizations for plan org selector
    $org_options = [];
    $org_result = $conn_sqli->query("SELECT org_code, display_name FROM organizations ORDER BY display_name");
    if ($org_result) {
        while ($org_row = $org_result->fetch_assoc()) {
            $org_options[] = $org_row;
        }
    }
?>

<!DOCTYPE html>
<html>

<head>

    <!-- Import CSS -->
    <?php
        $page_title = __('home.pageTitle');
        include("load/header.php");
    ?>

</head>

<body>

<?php
include('load/nav.php');
?>

    <section class="d-flex align-items-center position-relative bg-position-center fh-section overflow-hidden pt-6 jarallax bg-dark text-light" data-jarallax data-speed="0.3" style="pointer-events: all;">
        <div class="container-fluid pt-2 pb-5 py-lg-6">
            <img class="jarallax-img" src="assets/img/jumbotron/main.png" alt="" style="opacity: 50%;">

            <center>
                <h1><?= __('home.welcome') ?> <b><span class="text-info">PERTI</span> <?= __('home.pertiPlanSite') ?></b></h1>
                <h4 class="text-white hvr-bob pl-1">
                    <a href="#plans" style="text-decoration: none; color: white;"><i class="fas fa-chevron-down text-danger"></i> <?= __('home.searchPlans') ?></a>
                </h4>
            </center>

        </div>
    </section>

    <div class="container-fluid mt-5 mb-5">
        <div class="row">
            <div class="col-2">
                <center>
                    <h2><?= __('home.pertiProcess') ?></h2>
                </center>
            </div>
            <div class="col-2">
                <center>
                    <h2>
                        <i class="fas fa-pencil-ruler"></i><br>
                        <?= __('home.plan') ?>
                    </h2>
                </center>
            </div>
            <div class="col-2">
                <center>
                    <h2>
                        <i class="fas fa-running"></i><br>
                        <?= __('home.execute') ?>
                    </h2>
                </center>
            </div>
            <div class="col-2">
                <center>
                    <h2>
                        <i class="fas fa-glasses"></i><br>
                        <?= __('home.review') ?>
                    </h2>
                </center>                    
            </div>
            <div class="col-2">
                <center>
                    <h2>
                        <i class="fas fa-chalkboard-teacher"></i><br>
                        <?= __('home.train') ?>
                    </h2>
                </center>                    
            </div>
            <div class="col-2">
                <center>
                    <h2>
                        <i class="fas fa-dumbbell"></i><br>
                        <?= __('home.improve') ?>
                    </h2>
                </center>
            </div>
        </div>
    </div>

    <hr>

    <div id="plans" class="container-fluid pl-3 mb-5">
        <center>
            <h3><?= __('home.pertiPlans') ?></h3>
            <p><?= __('home.plansDescription') ?></p>

            <?php if ($perm == true) { ?>
                <button class="mt-2 mb-2 btn btn-success btn-sm" data-target="#createplanModal" data-toggle="modal"><i class="fas fa-plus"></i> <?= __('home.createPlan') ?></button>
            <?php } ?>

            <table class="table table-sm table-striped table-bordered w-100">
                <thead class="table-dark text-light">
                    <th style="width: 20%;"><?= __('home.table.eventName') ?></th>
                    <th class="text-center" style="width: 8%;"><?= __('home.table.startDate') ?></th>
                    <th class="text-center" style="width: 6%;"><?= __('home.table.startTime') ?></th>
                    <th class="text-center" style="width: 8%;"><?= __('home.table.endDate') ?></th>
                    <th class="text-center" style="width: 6%;"><?= __('home.table.endTime') ?></th>
                    <th class="text-center" style="width: 14%;"><?= __('home.table.tmuOpLevel') ?></th>
                    <th class="text-center" style="width: 12%;"><?= __('home.table.lastUpdated') ?></th>
                    <th></th>
                </thead>

                <tbody id="plans_table"></tbody>
            </table>

        </center>
    </div>
</body>
    
<?php include('load/footer.php'); ?>


<!-- Create Plan Modal -->
<div class="modal fade" id="createplanModal" tabindex="-1" role="dialog" aria-labelledby="createplanModalLabel"
    aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="createplanModalLabel"><?= __('home.createPertiPlan') ?></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="createplan">

                <div class="modal-body">

                    <div class="form-group">
                        <label for="event_name"><?= __('home.eventName') ?></label>
                        <input type="text" class="form-control" name="event_name" required>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?= __('home.startDateTime') ?></label>
                                <div class="row">
                                    <div class="col-7">
                                        <input type="text" name="event_date" class="form-control" id="date" autocomplete="off" placeholder="YYYY-MM-DD" readonly>
                                    </div>
                                    <div class="col-5">
                                        <div class="input-group">
                                            <input type="text" name="event_start" class="form-control" autocomplete="off" placeholder="2300" maxlength="4" required>
                                            <div class="input-group-append">
                                                <span class="input-group-text">Z</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?= __('home.endDateTime') ?></label>
                                <div class="row">
                                    <div class="col-7">
                                        <input type="text" name="event_end_date" class="form-control" id="end-date" autocomplete="off" placeholder="YYYY-MM-DD" readonly>
                                    </div>
                                    <div class="col-5">
                                        <div class="input-group">
                                            <input type="text" name="event_end_time" class="form-control" autocomplete="off" placeholder="0300" maxlength="4">
                                            <div class="input-group-append">
                                                <span class="input-group-text">Z</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <hr>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?= __('home.tmuOpLevel') ?></label>
                                <select class="form-control" name="oplevel" required>
                                    <option value="1"><?= __('home.opLevel1') ?></option>
                                    <option value="2"><?= __('home.opLevel2') ?></option>
                                    <option value="3"><?= __('home.opLevel3') ?></option>
                                    <option value="4"><?= __('home.opLevel4') ?></option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?= __('home.hotline') ?></label>
                                <select class="form-control" name="hotline" required>
                                    <option>NY Metro</option>
                                    <option>DC Metro</option>
                                    <option>Chicago</option>
                                    <option>Atlanta</option>
                                    <option>Florida</option>
                                    <option>Texas</option>
                                    <option>East Coast</option>
                                    <option>West Coast</option>
                                    <option>Canada East</option>
                                    <option>Canada West</option>
                                    <option>Mexico</option>
                                    <option>Caribbean</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?= __('home.organization') ?></label>
                                <select class="form-control" name="org_code">
                                    <option value=""><?= __('home.orgGlobal') ?></option>
                                    <?php foreach ($org_options as $o): ?>
                                    <option value="<?= htmlspecialchars($o['org_code']) ?>"><?= htmlspecialchars($o['display_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="form-text text-muted"><?= __('home.orgHint') ?></small>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label><?= __('home.eventBannerUrl') ?></label>
                        <input type="text" class="form-control" name="event_banner" placeholder="https://..." required>
                    </div>

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-primary" value="<?= __('home.create') ?>">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal"><?= __('common.close') ?></button>
                </div>
        </div>

        </form>

    </div>
</div>

<!-- Edit Plan Modal -->
<div class="modal fade" id="editplanModal" tabindex="-1" role="dialog" aria-labelledby="editplanModalLabel"
    aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editplanModalLabel"><?= __('home.editPertiPlan') ?></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="editplan">

                <div class="modal-body">

                    <input type="hidden" name="id" id="id">

                    <div class="form-group">
                        <label for="event_name"><?= __('home.eventName') ?></label>
                        <input type="text" class="form-control" name="event_name" id="event_name" required>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?= __('home.startDateTime') ?></label>
                                <div class="row">
                                    <div class="col-7">
                                        <input type="text" name="event_date" class="form-control" id="e-date" autocomplete="off" placeholder="YYYY-MM-DD" readonly>
                                    </div>
                                    <div class="col-5">
                                        <div class="input-group">
                                            <input type="text" name="event_start" class="form-control" id="event_start" autocomplete="off" placeholder="2300" maxlength="4" required>
                                            <div class="input-group-append">
                                                <span class="input-group-text">Z</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?= __('home.endDateTime') ?></label>
                                <div class="row">
                                    <div class="col-7">
                                        <input type="text" name="event_end_date" class="form-control" id="e-end-date" autocomplete="off" placeholder="YYYY-MM-DD" readonly>
                                    </div>
                                    <div class="col-5">
                                        <div class="input-group">
                                            <input type="text" name="event_end_time" class="form-control" id="event_end_time" autocomplete="off" placeholder="0300" maxlength="4">
                                            <div class="input-group-append">
                                                <span class="input-group-text">Z</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <hr>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?= __('home.tmuOpLevel') ?></label>
                                <select class="form-control" name="oplevel" id="oplevel" required>
                                    <option value="1"><?= __('home.opLevel1') ?></option>
                                    <option value="2"><?= __('home.opLevel2') ?></option>
                                    <option value="3"><?= __('home.opLevel3') ?></option>
                                    <option value="4"><?= __('home.opLevel4') ?></option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?= __('home.hotline') ?></label>
                                <select class="form-control" name="hotline" id="hotline" required>
                                    <option>NY Metro</option>
                                    <option>DC Metro</option>
                                    <option>Chicago</option>
                                    <option>Atlanta</option>
                                    <option>Florida</option>
                                    <option>Texas</option>
                                    <option>East Coast</option>
                                    <option>West Coast</option>
                                    <option>Canada East</option>
                                    <option>Canada West</option>
                                    <option>Mexico</option>
                                    <option>Caribbean</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><?= __('home.organization') ?></label>
                                <select class="form-control" name="org_code" id="org_code">
                                    <option value=""><?= __('home.orgGlobal') ?></option>
                                    <?php foreach ($org_options as $o): ?>
                                    <option value="<?= htmlspecialchars($o['org_code']) ?>"><?= htmlspecialchars($o['display_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="form-text text-muted"><?= __('home.orgHint') ?></small>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label><?= __('home.eventBannerUrl') ?></label>
                        <input type="text" class="form-control" name="event_banner" id="event_banner" placeholder="https://..." required>
                    </div>

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-warning" value="<?= __('home.saveChanges') ?>">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal"><?= __('common.close') ?></button>
                </div>
        </div>

        </form>

    </div>
</div>

    <!-- Scripts -->
    <script async type="text/javascript">
        function tooltips() {
            $('[data-toggle="tooltip"]').tooltip('dispose');

            $(function () {
                $('[data-toggle="tooltip"]').tooltip()
            }); 
        }
        function loadData() {
            // Plans
            $.get('api/data/plans.l').done(function(data) {
                $('#plans_table').html(data);

                tooltips();           
            });
        }

        // FUNC: deletePlan [id:]
        function deletePlan(id) {
            $.ajax({
                type:   'POST',
                url:    'api/mgt/perti/delete',
                data:   {id: id},
                success:function(data) {
                    Swal.fire({
                        toast:      true,
                        position:   'bottom-right',
                        icon:       'success',
                        title:      PERTII18n.t('home.success.deleted'),
                        text:       PERTII18n.t('home.success.deletedText'),
                        timer:      3000,
                        showConfirmButton: false
                    });

                    loadData();
                },
                error:function(data) {
                    Swal.fire({
                        icon:   'error',
                        title:  PERTII18n.t('home.error.deleteFailed'),
                        text:   PERTII18n.t('home.error.deleteFailedText')
                    });
                }
            });
        }
        
        $(document).ready(function() {
            loadData();

            // Past events expand/collapse
            $(document).on('click', '.plan-past-toggle', function(e) {
                e.preventDefault();
                $('.plan-row-past-hidden').toggle();
                var total = $(this).data('total');
                if ($('.plan-row-past-hidden').first().is(':visible')) {
                    $(this).text(PERTII18n.t('home.section.showLess'));
                } else {
                    $(this).text(PERTII18n.t('home.section.showAllPast', { count: total }));
                }
                tooltips();
            });

            // Auto-default org based on hotline selection
            function hotlineToOrg(hotline) {
                if (hotline === 'Canada East' || hotline === 'Canada West') return 'vatcan';
                return '';
            }
            $('select[name="hotline"]').on('change', function() {
                var orgSelect = $(this).closest('form').find('select[name="org_code"]');
                orgSelect.val(hotlineToOrg($(this).val()));
            });

            // Init: Date Time Picker for Create modal - Start Date
            $('#date').datetimepicker({
                format: 'Y-m-d',
                inline: false,
                timepicker: false
            });

            // Init: Date Time Picker for Create modal - End Date
            $('#end-date').datetimepicker({
                format: 'Y-m-d',
                inline: false,
                timepicker: false
            });

            // Auto-set end date when start date changes (Create modal)
            $('#date').on('change', function() {
                var startDate = $(this).val();
                if (startDate && !$('#end-date').val()) {
                    $('#end-date').val(startDate);
                }
            });

            // AJAX: #createplan POST
            $("#createplan").submit(function(e) {
                e.preventDefault();

                var url = 'api/mgt/perti/post';

                $.ajax({
                    type:   'POST',
                    url:    url,
                    data:   $(this).serialize().replace(/'/g, "`"),
                    success:function(data) {
                        Swal.fire({
                            toast:      true,
                            position:   'bottom-right',
                            icon:       'success',
                            title:      PERTII18n.t('home.success.created'),
                            text:       PERTII18n.t('home.success.createdText'),
                            timer:      3000,
                            showConfirmButton: false
                        });

                        loadData();
                        $('#createplanModal').modal('hide');
                        $('.modal-backdrop').remove();

                        // Reset form
                        $('#createplan')[0].reset();
                    },
                    error:function(data) {
                        Swal.fire({
                            icon:   'error',
                            title:  PERTII18n.t('home.error.createFailed'),
                            text:   PERTII18n.t('home.error.createFailedText')
                        });
                    }
                });
            });

            // Edit Plan Modal
            $('#editplanModal').on('show.bs.modal', function(event) {
                var button = $(event.relatedTarget);

                var modal= $(this);

                modal.find('.modal-body #id').val(button.data('id'));
                modal.find('.modal-body #event_name').val(button.data('event_name'));
                modal.find('.modal-body #event_start').val(button.data('event_start'));
                modal.find('.modal-body #event_end_time').val(button.data('event_end_time'));
                modal.find('.modal-body #oplevel').val(button.data('oplevel')).trigger('change');
                modal.find('.modal-body #hotline').val(button.data('hotline')).trigger('change');
                modal.find('.modal-body #event_banner').val(button.data('event_banner'));
                modal.find('.modal-body #org_code').val(button.data('org_code') || '');

                // Init: Date Time Picker for Edit modal - Start Date
                $('#e-date').datetimepicker({
                    format: 'Y-m-d',
                    inline: false,
                    timepicker: false,
                    value: button.data('event_date')
                });

                // Init: Date Time Picker for Edit modal - End Date
                $('#e-end-date').datetimepicker({
                    format: 'Y-m-d',
                    inline: false,
                    timepicker: false,
                    value: button.data('event_end_date') || button.data('event_date')
                });
            });

            // AJAX: #editplan POST
            $("#editplan").submit(function(e) {
                e.preventDefault();

                var url = 'api/mgt/perti/update';

                $.ajax({
                    type:   'POST',
                    url:    url,
                    data:   $(this).serialize().replace(/'/g, "`"),
                    success:function(data) {
                        Swal.fire({
                            toast:      true,
                            position:   'bottom-right',
                            icon:       'success',
                            title:      PERTII18n.t('home.success.updated'),
                            text:       PERTII18n.t('home.success.updatedText'),
                            timer:      3000,
                            showConfirmButton: false
                        });

                        loadData();
                        $('#editplanModal').modal('hide');
                        $('.modal-backdrop').remove();
                    },
                    error:function(data) {
                        Swal.fire({
                            icon:   'error',
                            title:  PERTII18n.t('home.error.updateFailed'),
                            text:   PERTII18n.t('home.error.updateFailedText')
                        });
                    }
                });
            });

        });
    </script>

</html>