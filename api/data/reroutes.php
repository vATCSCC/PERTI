<?php

// Session Start (S)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
  }
// Session Start (E)

include("../../load/config.php");
define('PERTI_MYSQL_ONLY', true);
include("../../load/connect.php");

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

$search = get_input('search');

$query = mysqli_query($conn_sqli, ("SELECT * FROM route_playbook WHERE pb_name LIKE '%$search%' ORDER BY pb_id ASC LIMIT 50"));

while ($data = mysqli_fetch_array($query)) {
echo '<tr>';

        echo '<td class="text-center">'.$data['pb_category'].'</td>';
        echo '<td class="text-center">'.$data['pb_name'].'</td>';
        echo '<td class="text-center">'.$data['pb_route_advisory'].'</td>';	}

        if ($perm == true) {
        echo '<td><center>';
                echo '<a href="javascript:void(0)" data-toggle="tooltip" title="Update Reroute Database"><span class="badge badge-warning" data-toggle="modal" data-target="#updatererouteModal" data-pb_id="'.$data['pb_id'].'" data-pb-name="'.$data['pb_name'].'" data-pb-category="'.$data['pb_category'].'" data-pb-route-advisory="'.$data['pb_route_advisory'].'">
                    <i class="fas fa-pencil-alt"></i> Update</span></a>';
                echo ' ';
                echo '<a href="javascript:void(0)" onclick="deleteReroute('.$data['pb_id'].')" data-toggle="tooltip" title="Delete Reroute"><span class="badge badge-danger"><i class="fas fa-times"></i> Delete</span></a>';
        echo '</center></td>';
        }

    echo '</tr>';
}

?>
