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

$search_param = '%' . $conn_sqli->real_escape_string($search) . '%';
$query = mysqli_query($conn_sqli, "SELECT * FROM route_playbook WHERE pb_name LIKE '$search_param' ORDER BY pb_id ASC LIMIT 50");

while ($data = mysqli_fetch_array($query)) {
echo '<tr>';

        echo '<td class="text-center">'.htmlspecialchars($data['pb_category'], ENT_QUOTES, 'UTF-8').'</td>';
        echo '<td class="text-center">'.htmlspecialchars($data['pb_name'], ENT_QUOTES, 'UTF-8').'</td>';
        echo '<td class="text-center">'.htmlspecialchars($data['pb_route_advisory'], ENT_QUOTES, 'UTF-8').'</td>';	}

        if ($perm == true) {
        echo '<td><center>';
                echo '<a href="javascript:void(0)" data-toggle="tooltip" title="Update Reroute Database"><span class="badge badge-warning" data-toggle="modal" data-target="#updatererouteModal" data-pb_id="'.intval($data['pb_id']).'" data-pb-name="'.htmlspecialchars($data['pb_name'], ENT_QUOTES, 'UTF-8').'" data-pb-category="'.htmlspecialchars($data['pb_category'], ENT_QUOTES, 'UTF-8').'" data-pb-route-advisory="'.htmlspecialchars($data['pb_route_advisory'], ENT_QUOTES, 'UTF-8').'">
                    <i class="fas fa-pencil-alt"></i> Update</span></a>';
                echo ' ';
                echo '<a href="javascript:void(0)" onclick="deleteReroute('.intval($data['pb_id']).')" data-toggle="tooltip" title="Delete Reroute"><span class="badge badge-danger"><i class="fas fa-times"></i> Delete</span></a>';
        echo '</center></td>';
        }

    echo '</tr>';
}

?>
