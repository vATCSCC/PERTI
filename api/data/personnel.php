<?php

// Session Start (S)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
  }
// Session Start (E)

include("../../load/config.php");
include("../../load/connect.php");

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

if ($perm !== false) {
    $query = mysqli_query($conn_sqli, ("SELECT * FROM users ORDER BY last_name DESC"));

    while ($data = mysqli_fetch_array($query)) {
        echo '<tr>';
        echo '<td class="text-center text-primary">'.$data['cid'].'</td>';
        echo '<td class="text-center">'.$data['first_name'].'</td>';
        echo '<td class="text-center">'.$data['last_name'].'</td>';
        echo '<td class="text-center">'.$data['updated_at'].'</td>';
    
    
            echo '<td><center>';
                echo '<a href="javascript:void(0)" onclick="deletePersonnel('.$data['id'].')" data-toggle="tooltip" title="Delete Personnel"><span class="badge badge-danger"><i class="fas fa-times"></i> Delete</span></a>';
            echo '</center></td>';
    
        echo '</tr>';
    }
}

?>