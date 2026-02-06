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

    <script>
        var plan_id = <?= $id ?>;
    </script>

    <link rel="stylesheet" href="assets/css/initiative_timeline.css">

</head>

<body class="toolbar-enabled">

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

    <section class="bg-secondary pb-6 pt-4">
        <div class="container">
            <div class="row">
                <div class="col-12">
                    <div class="card border-0 box-shadow">
                        <div class="card-body">
                            <div id="sheet-content">
                                <!-- Content will be loaded here via sheet.js -->
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

<?php
include("load/footer.php");
?>

</body>

<script src="assets/js/sheet.js"></script>

</html>
