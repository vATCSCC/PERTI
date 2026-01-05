<?php

// Session Start (S)
if (session_status() == PHP_SESSION_NONE) {
  session_start();
  ob_start();
}
// Session Start (E)

include("config.php");
include("connect.php");

//  Check Perms
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

$filepath = "";

?>

<!-- Enable Tooltips -->
<script>
        $( document ).ready(function() {
            $('[data-toggle="tooltip"]').tooltip({'placement': 'top'});
        });
</script>

<nav class="cs-header navbar navbar-expand-lg navbar-dark navbar-floating">
  <div class="container px-0 px-xl-3">
    <button class="navbar-toggler ml-n2 mr-2" type="button" data-toggle="offcanvas" data-offcanvas-id="primaryMenu">
        <span class="navbar-toggler-icon"></span>
    </button>
    
    <a class="navbar-brand order-lg-1 mx-auto ml-lg-0 pr-lg-2 mr-lg-4" href="<?= $filepath; ?>./">
        <img class="navbar-floating-logo d-none d-lg-block" width="200" src="assets/img/logo.png">
        <img class="navbar-stuck-logo" width="200" src="assets/img/logo.png" alt="vATCSCC Logo"/>
    </a>

    <div class="d-flex align-items-left order-lg-3">
        <ul class="navbar-nav">
            <li class="nav-item">
                <a class="nav-link" href="<?= $filepath; ?>./">
                    Plans
                </a>
            </li>

            <li class="nav-item">
                <a class="nav-link" href="<?= $filepath; ?>./configs">
                    Configs
                </a>
            </li>

            <li class="nav-item">
                <a class="nav-link" href="<?= $filepath; ?>./route">
                    Routes
                </a>
            </li>
            
            <li class="nav-item">
                <a class="nav-link" href="<?= $filepath; ?>./nod">
                    NOD
                </a>
            </li>

            <li class="nav-item">
                <a class="nav-link" href="<?= $filepath; ?>./gdt">
                    GDT
                </a>
            </li>
            
            <li class="nav-item">
                <a class="nav-link" href="<?= $filepath; ?>./jatoc">
                    JATOC
                </a>
            </li>

            <li class="nav-item">
                <a class="nav-link" href="<?= $filepath; ?>./demand">
                    Demand
                </a>
            </li>

            <!--Reroutes button
            <li class="nav-item">
                <a class="nav-link" href="<?= $filepath; ?>./reroutes">
                    Reroutes
                </a>
            </li>
            -->
            
            <li class="nav-item">
                <a class="nav-link" href="<?= $filepath; ?>./splits">
                    Splits
                </a>
            </li>

            <?php if ($perm == true) { ?>
                <li class="nav-item">
                    <a class="nav-link" href="<?= $filepath; ?>./schedule">
                        Schedule
                    </a>
                </li>
            <?php } ?>
        </ul>
    </div>
    
    <div class="d-flex align-items-center order-lg-3 ml-lg-auto">
        <!-- Users Login/Dropdown -->
        <?php if ($perm == true) { ?>
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link" href="/logout" id="profile">
                        <i class="fas fa-user-circle"></i> <?php echo $_SESSION['VATSIM_FIRST_NAME'] . " " . $_SESSION['VATSIM_LAST_NAME']; ?>
                    </a>
                </li>
            </ul>
        <?php } else { ?>
            <a class="btn btn-sm btn-danger" href="<?= $filepath; ?>login" rel="noopener"><i class="fas fa-user font-size-lg mr-2"></i>Login</span></a>
        <?php } ?>
        
    </div>

    </div>
  </div>
</nav>