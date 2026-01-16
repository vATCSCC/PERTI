<?php

// Session Start (S)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
  }
// Session Start (E)

include("../../../load/config.php");
include("../../../load/connect.php");

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

$p_id = get_input('p_id');

$c_q = $conn_sqli->query("SELECT COUNT(*) AS 'total' FROM r_comments WHERE p_id='$p_id'")->fetch_assoc();


if ($c_q['total'] > 0) {
    $query = $conn_sqli->query("SELECT * FROM r_comments WHERE p_id='$p_id'");

    while ($data = mysqli_fetch_array($query)) {
        echo '<tr>';

            echo '<td class="text-center" style="width: 25%; vertical-align:middle;">Staffing Comments</td>';

            echo '<td><p class="text-wrap">'.$data['staffing'].'</p></td>';

        echo '</tr>';

        echo '<tr>';

            echo '<td class="text-center" style="width: 25%; vertical-align:middle;">Tactical (Real-Time) Comments</td>';

            echo '<td><p class="text-wrap">'.$data['tactical'].'</p></td>';

        echo '</tr>';
        
        echo '<tr>';

            echo '<td class="text-center" style="width: 25%; vertical-align:middle;">Other Coordination Comments</td>';

            echo '<td><p class="text-wrap">'.$data['other'].'</p></td>';

        echo '</tr>'; 

        echo '<tr>';

            echo '<td class="text-center" style="width: 25%; vertical-align:middle;">PERTI Plan Comments</td>';

            echo '<td><p class="text-wrap">'.$data['perti'].'</p></td>';

        echo '</tr>'; 

        echo '<tr>';

            echo '<td class="text-center" style="width: 25%; vertical-align:middle;">NTML/Advisory Usage Comments</td>';

            echo '<td><p class="text-wrap">'.$data['ntml'].'</p></td>';

        echo '</tr>'; 

        echo '<tr>';

            echo '<td class="text-center" style="width: 25%; vertical-align:middle;">TMI Comments</td>';

            echo '<td><p class="text-wrap">'.$data['tmi'].'</p></td>';

        echo '</tr>'; 

        echo '<tr>';

            echo '<td class="text-center" style="width: 25%; vertical-align:middle;">ACE Team Implementation Comments</td>';

            echo '<td><p class="text-wrap">'.$data['ace'].'</p></td>';

        echo '</tr>'; 

        if ($perm == true) {
            echo '<tr><td colspan="2">';
                echo '<a href="javascript:void(0)" data-toggle="tooltip" title="Edit Comment"><span class="badge badge-warning" data-toggle="modal" data-target="#editcommentModal" data-id="'.$data['id'].'" data-staffing="'.$data['staffing'].'" data-tactical="'.$data['tactical'].'" data-other="'.$data['other'].'" data-perti="'.$data['perti'].'" data-ntml="'.$data['ntml'].'" data-tmi="'.$data['tmi'].'" data-ace="'.$data['ace'].'">
                <i class="fas fa-pencil-alt"></i> Edit</span></a>';
                echo ' ';
                echo '<a href="javascript:void(0)" onclick="deleteComment('.$data['id'].')" data-toggle="tooltip" title="Delete Comment"><span class="badge badge-danger"><i class="fas fa-times"></i> Delete</span></a>';
            echo '</td></tr>';
        }

    }
} else {
    echo '<tr><td class="text-center" colspan="2">No Comments Added</td></tr>';
}

?>