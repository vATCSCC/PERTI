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
    // Do Nothing
} else {
    http_response_code(403);
    exit();
}

function checkIfAssigned($id, $conn_sqli) {
    $data = $conn_sqli->query("SELECT COUNT(*) as `total` FROM assigned WHERE e_id=$id")->fetch_assoc();

    if ($data['total'] > 0) {
        // Not assigned
        return true;
    } else {
        // Not assigned
        return false;
    }
}

function cidtoName($cid, $conn_sqli) {
    $data = $conn_sqli->query("SELECT first_name, last_name FROM users WHERE cid=$cid LIMIT 1")->fetch_assoc();

    if ($data) {
        return $data['first_name'] . ' ' . $data['last_name'];
    }
}

if (isset($_GET['assigned'])) {
    $query = $conn_sqli->query("SELECT * FROM assigned ORDER BY e_date ASC");

    if ($query) {
        while ($data = mysqli_fetch_array($query)) {

                // Event not scheduled
                echo '<tr class="">';
                    echo '<td>'.$data['e_title'].'</td>';
                    echo '<td class="border-right">'.$data['e_date'].'</td>';

                    if ($data['p_cid'] > 0) {
                        echo '<td class="text-danger text-center">'.cidtoName($data['p_cid'], $conn_sqli).'</td>';
                    } else {
                        echo '<td class="text-center">Not Assigned</td>';
                    }

                    if ($data['e_cid'] > 0) {
                        echo '<td class="text-danger text-center">'.cidtoName($data['e_cid'], $conn_sqli).'</td>';
                    } else {
                        echo '<td class="text-center">Not Assigned</td>';
                    }

                    if ($data['t_cid'] > 0) {
                        echo '<td class="text-danger text-center">'.cidtoName($data['t_cid'], $conn_sqli).'</td>';
                    } else {
                        echo '<td class="text-center">Not Assigned</td>';
                    }

                    if ($data['r_cid'] > 0) {
                        echo '<td class="text-danger text-center">'.cidtoName($data['r_cid'], $conn_sqli).'</td>';
                    } else {
                        echo '<td class="text-center">Not Assigned</td>';
                    }

                    if ($data['i_cid'] > 0) {
                        echo '<td class="text-danger text-center">'.cidtoName($data['i_cid'], $conn_sqli).'</td>';
                    } else {
                        echo '<td class="text-center">Not Assigned</td>';
                    }

                    echo '<td class="border-left text-center">';
                        echo '<a href="javascript:void(0)" data-toggle="tooltip" title="Edit Assigned Personnel"><span class="badge badge-warning" data-toggle="modal" data-target="#editassignedModal" data-id="'.$data['id'].'" data-p_cid="'.$data['p_cid'].'" data-e_cid="'.$data['e_cid'].'" data-r_cid="'.$data['r_cid'].'" data-t_cid="'.$data['t_cid'].'" data-i_cid="'.$data['i_cid'].'">
                        <i class="fas fa-pencil-alt"></i> Edit</span></a>';
                        echo ' ';
                        echo '<a href="javascript:void(0)" onclick="deleteEvent('.$data['id'].')" data-toggle="tooltip" title="Delete Event from Schedule"><span class="badge badge-danger"><i class="fas fa-times"></i> Delete</span></a>';
                    echo '</td>';

                echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="8" class="text-center">No Events Assigned/Scheduled</td></tr>';
    }
} else {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_URL => "https://api.vatusa.net/v2/public/events/25"
    ]);
    
    $json = curl_exec($ch);
    $obj =  json_decode($json, true);
    
    foreach ($obj['data'] as &$data) {
        if (checkIfAssigned($data['id_event'], $conn_sqli) === false) {
            // Event not scheduled
            echo '<tr class="table-secondary">';
                echo '<td>'.$data['title'].'</td>';
                echo '<td class="border-right">'.$data['start_date'].'</td>';
                echo '<td class="text-center">Not Scheduled</td>';
                echo '<td class="text-center">Not Scheduled</td>';
                echo '<td class="text-center">Not Scheduled</td>';
                echo '<td class="text-center">Not Scheduled</td>';
                echo '<td class="text-center">Not Scheduled</td>';

                echo '<td class="border-left text-center"><a href="javascript:void(0)" onclick="schedule('.$data['id_event'].', `'.$data['title'].'`, `'.$data['start_date'].'`)" data-toggle="tooltip" title="Move Up to Schedule Event"><span class="badge badge-primary"><i class="fas fa-calendar"></i> Schedule</span></a></td>';
            echo '</tr>';
        }
    }
}


?>