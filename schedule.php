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

    if ($perm == true) {
        // Do nothing
    } else {
        http_response_code(403);
        exit();
    }

    $q = $conn_sqli->query("SELECT cid, first_name, last_name FROM users ORDER BY first_name ASC");
    $users = [];

    while ($user = mysqli_fetch_array($q)) {
        $users[] = ["cid" => $user['cid'], "first_name" => $user['first_name'], "last_name" => $user['last_name']];
    }

?>

<!DOCTYPE html>
<html>

<head>

    <!-- Import CSS -->
    <?php
        $page_title = "Schedule";
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
        </div>       
    </section>


    <div class="container-fluid mt-5 mb-5">
        <center>
            <table class="table table-sm w-75">
                <tbody id="assigned"></tbody>
                <tbody id="unassigned"></tbody>
            </table>

            <hr>

            <h5><?= __('schedule.page.systemPersonnel') ?>     <button class="btn btn-sm btn-outline-success" data-toggle="modal" data-target="#addpersonnelModal"><i class="fas fa-plus"></i> <?= __('common.add') ?></button></h5>
            <table class="table w-75">
                <thead>
                    <tr>
                        <th class="text-center">CID</th>
                        <th class="text-center">First Name</th>
                        <th class="text-center">Last Name</th>
                        <th class="text-center">Organization</th>
                        <th class="text-center">Last Updated</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody id="personnel"></tbody>
            </table>

        </center>
    </div>

    
<?php include('load/footer.php'); ?>

<!-- Edit Personnel Modal -->
<div class="modal fade" id="editassignedModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><?= __('schedule.page.editAssignedPersonnel') ?></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="editassigned">

                <div class="modal-body">

                    <input type="hidden" name="id" id="id">

                    <?= __('schedule.page.planPersonnel') ?>
                    <select class="form-control" name="p_cid" id="p_cid" required>
                        <option value='0'><?= __('schedule.page.noPersonnelAssigned') ?></option>
                        <?php 
                            foreach($users as &$user) {
                                echo '<option value="'.$user['cid'].'">'.$user['first_name'].' '.$user['last_name'].'</option>';                                
                            }
                        ?>
                    </select>

                    <?= __('schedule.page.executePersonnel') ?>
                    <select class="form-control" name="e_cid" id="e_cid" required>
                        <option value='0'><?= __('schedule.page.noPersonnelAssigned') ?></option>
                        <?php 
                            foreach($users as &$user) {
                                echo '<option value="'.$user['cid'].'">'.$user['first_name'].' '.$user['last_name'].'</option>';                                
                            }
                        ?>
                    </select>

                    <?= __('schedule.page.reviewPersonnel') ?>
                    <select class="form-control" name="t_cid" id="t_cid" required>
                        <option value='0'><?= __('schedule.page.noPersonnelAssigned') ?></option>
                        <?php 
                            foreach($users as &$user) {
                                echo '<option value="'.$user['cid'].'">'.$user['first_name'].' '.$user['last_name'].'</option>';                                
                            }
                        ?>
                    </select>

                    <?= __('schedule.page.trainPersonnel') ?>
                    <select class="form-control" name="r_cid" id="r_cid" required>
                        <option value='0'><?= __('schedule.page.noPersonnelAssigned') ?></option>
                        <?php 
                            foreach($users as &$user) {
                                echo '<option value="'.$user['cid'].'">'.$user['first_name'].' '.$user['last_name'].'</option>';                                
                            }
                        ?>
                    </select>

                    <?= __('schedule.page.improvePersonnel') ?>
                    <select class="form-control" name="i_cid" id="i_cid" required>
                        <option value='0'><?= __('schedule.page.noPersonnelAssigned') ?></option>
                        <?php 
                            foreach($users as &$user) {
                                echo '<option value="'.$user['cid'].'">'.$user['first_name'].' '.$user['last_name'].'</option>';                                
                            }
                        ?>
                    </select>

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-warning" value="Edit">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal">Close</button>
                </div>
        </div>

        </form>

    </div>
</div>


<!-- Add Personnel Modal -->
<div class="modal fade" id="addpersonnelModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><?= __('schedule.page.addPersonnel') ?></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="addpersonnel">

                <div class="modal-body">

                    <?= __('schedule.page.vatsimCid') ?>
                    <input type="text" name="cid" class="form-control" id="cid" maxlength="8"><hr>

                    <?= __('schedule.page.firstName') ?>
                    <input type="text" name="first_name" class="form-control" id="first_name">

                    <?= __('schedule.page.lastName') ?>
                    <input type="text" name="last_name" class="form-control" id="last_name">

                    <hr>
                    <label><b>Organization</b></label>
                    <?php
                        $orgs_q = $conn_sqli->query("SELECT org_code, display_name FROM organizations WHERE is_active = 1 ORDER BY org_code");
                        while ($org_row = mysqli_fetch_assoc($orgs_q)):
                    ?>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="orgs[]" value="<?= $org_row['org_code'] ?>" id="add-org-<?= $org_row['org_code'] ?>" <?= $org_row['org_code'] === 'vatcscc' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="add-org-<?= $org_row['org_code'] ?>"><?= $org_row['display_name'] ?></label>
                    </div>
                    <?php endwhile; ?>

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-success" value="Add">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal">Close</button>
                </div>
        </div>

        </form>

    </div>
</div>

<!-- Insert schedule.js Script -->
<script src="assets/js/schedule.js<?= _v('assets/js/schedule.js') ?>"></script>

</html>