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
                <h1><b><span class="text-danger"><?= $plan_info['event_name']; ?></span> Review</b></h1>
                <h5><a class="text-light" href="plan?<?= $plan_info['id']; ?>"><i class="fas fa-eye text-primary"></i> View PERTI Plan</a></h5>
            </center>

        </div>       
    </section>

    <div class="container-fluid mt-3 mb-3">
        <div class="row">
            <div class="col-2">
                <ul class="nav flex-column nav-pills" aria-orientation="vertical">
                    <li><a class="nav-link active rounded" data-toggle="tab" href="#scoring">Scoring</a></li>
                    <li><a class="nav-link rounded" data-toggle="tab" href="#event_data">Event Data</a></li>
                </ul>
            </div>
            
            <div class="col-10">
                <div class="tab-content">

                    <!-- Tab: Scoring -->
                    <div class="tab-pane fade show active" id="scoring">
                        <div class="row">
                            <div class="col-4">
                                <!-- Scoring -->

                                <?php if ($perm == true) { ?>
                                    <button class="btn btn-sm btn-success" data-toggle="modal" data-target="#addscoreModal"><i class="fas fa-plus"></i> Add Score</button>      

                                    <hr>
                                <?php } ?>

                                <table class="table table-bordered">
                                    <thead class="text-center bg-secondary">
                                        <th>Category</th>
                                        <th>Score</th>
                                    </thead>
                                    <tbody id="scores"></tbody>
                                </table>
                            </div>

                            <div class="col-8">
                                <!-- Comments -->

                                <?php if ($perm == true) { ?>
                                    <button class="btn btn-sm btn-success" data-toggle="modal" data-target="#addcommentModal"><i class="fas fa-plus"></i> Add Comment</button>      

                                    <hr>
                                <?php } ?>

                                <table class="table table-bordered">
                                    <thead class="text-center bg-secondary">
                                        <th>Category</th>
                                        <th>Comments</th>
                                    </thead>
                                    <tbody id="comments"></tbody>
                                </table>
                            </div>

                        </div>
                    </div>

                    <!-- Tab: Event Data -->
                    <div class="tab-pane fade" id="event_data">

                        <?php if ($perm == true) { ?>
                            <button class="btn btn-sm btn-success" data-toggle="modal" data-target="#adddataModal"><i class="fas fa-plus"></i> Add Data</button>      

                            <hr>
                        <?php } ?>

                        <div class="row gutters-tiny py-20" id="data"></div>
                    </div>                   


                </div>
            </div>
        </div>
    </div>

</body>
<?php include('load/footer.php'); ?>


<?php if (perm == true) { ?>

<!-- Add Score Modal -->
<div class="modal fade" id="addscoreModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Score</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="addscore">

                <div class="modal-body">

                    <input type="hidden" name="p_id" value="<?= $id; ?>">

                    Staffing:
                    <input type="number" class="form-control" name="staffing" min="1" max="5">

                    Tactical (Real-Time):
                    <input type="number" class="form-control" name="tactical" min="1" max="5">

                    Other Coordination:
                    <input type="number" class="form-control" name="other" min="1" max="5">

                    PERTI Plan:
                    <input type="number" class="form-control" name="perti" min="1" max="5">

                    NTML/Advisory Usage:
                    <input type="number" class="form-control" name="ntml" min="1" max="5">

                    TMI:
                    <input type="number" class="form-control" name="tmi" min="1" max="5">

                    ACE Team Implementation:
                    <input type="number" class="form-control" name="ace" min="1" max="5">

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-success" value="Add">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal">Close</button>
                </div>
        </div>

        </form>

    </div>
</div>

<!-- Edit Score Modal -->
<div class="modal fade" id="editscoreModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Score</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="editscore">

                <div class="modal-body">

                    <input type="hidden" name="id" id="id">

                    Staffing:
                    <input type="number" class="form-control" name="staffing" id="staffing" min="1" max="5">

                    Tactical (Real-Time):
                    <input type="number" class="form-control" name="tactical" id="tactical" min="1" max="5">

                    Other Coordination:
                    <input type="number" class="form-control" name="other" id="other" min="1" max="5">

                    PERTI Plan:
                    <input type="number" class="form-control" name="perti" id="perti" min="1" max="5">

                    NTML/Advisory Usage:
                    <input type="number" class="form-control" name="ntml" id="ntml" min="1" max="5">

                    TMI:
                    <input type="number" class="form-control" name="tmi" id="tmi" min="1" max="5">

                    ACE Team Implementation:
                    <input type="number" class="form-control" name="ace" id="ace" min="1" max="5">

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-warning" value="Edit">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal">Close</button>
                </div>
        </div>

        </form>

    </div>
</div>

<!-- Add Comment Modal -->
<div class="modal fade" id="addcommentModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Comment</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="addcomment">

                <div class="modal-body">

                    <input type="hidden" name="p_id" value="<?= $id; ?>">

                    Staffing:
                    <textarea class="form-control rounded-0" name="staffing" id="a_staffing" rows="5"></textarea><hr>

                    Tactical (Real-Time):
                    <textarea class="form-control rounded-0" name="tactical" id="a_tactical" rows="5"></textarea><hr>

                    Other Coordination:
                    <textarea class="form-control rounded-0" name="other" id="a_other" rows="5"></textarea><hr>

                    PERTI Plan:
                    <textarea class="form-control rounded-0" name="perti" id="a_perti" rows="5"></textarea><hr>

                    NTML/Advisory Usage:
                    <textarea class="form-control rounded-0" name="ntml" id="a_ntml" rows="5"></textarea><hr>

                    TMI:
                    <textarea class="form-control rounded-0" name="tmi" id="a_tmi" rows="5"></textarea><hr>

                    ACE Team Implementation:
                    <textarea class="form-control rounded-0" name="ace" id="a_ace" rows="5"></textarea>

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-success" value="Add">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal">Close</button>
                </div>
        </div>

        </form>

    </div>
</div>

<!-- Edit Comment Modal -->
<div class="modal fade" id="editcommentModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Comment</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="editcomment">

                <div class="modal-body">

                    <input type="hidden" name="id" id="id">

                    Staffing:
                    <textarea class="form-control rounded-0" name="staffing" id="e_staffing" rows="5"></textarea><hr>

                    Tactical (Real-Time):
                    <textarea class="form-control rounded-0" name="tactical" id="e_tactical" rows="5"></textarea><hr>

                    Other Coordination:
                    <textarea class="form-control rounded-0" name="other" id="e_other" rows="5"></textarea><hr>

                    PERTI Plan:
                    <textarea class="form-control rounded-0" name="perti" id="e_perti" rows="5"></textarea><hr>

                    NTML/Advisory Usage:
                    <textarea class="form-control rounded-0" name="ntml" id="e_ntml" rows="5"></textarea><hr>

                    TMI:
                    <textarea class="form-control rounded-0" name="tmi" id="e_tmi" rows="5"></textarea><hr>

                    ACE Team Implementation:
                    <textarea class="form-control rounded-0" name="ace" id="e_ace" rows="5"></textarea>

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-warning" value="Edit">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal">Close</button>
                </div>
        </div>

        </form>

    </div>
</div>

<!-- Add Event Data Modal -->
<div class="modal fade" id="adddataModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Event Data</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="adddata">

                <div class="modal-body">

                    <input type="hidden" name="p_id" value="<?= $id; ?>">

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

<!-- Edit Event Data Modal -->
<div class="modal fade" id="editdataModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Event Data</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="editdata">

                <div class="modal-body">

                    <input type="hidden" name="id" id="id">

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


<?php } ?>

<!-- Insert review.js Script -->
<script src="assets/js/review.js"></script>

</html>