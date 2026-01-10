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

$query = mysqli_query($conn_sqli, ("SELECT * FROM p_plans ORDER BY event_date DESC LIMIT 15"));

// Hotline badge abbreviations
$hotline_badges = [
    'NY Metro' => 'NYC',
    'DC Metro' => 'DC',
    'Chicago' => 'CHI',
    'Atlanta' => 'ATL',
    'Florida' => 'FLA',
    'Texas' => 'TEX',
    'East Coast' => 'EC',
    'West Coast' => 'WC',
    'Canada' => 'CAN'
];

while ($data = mysqli_fetch_array($query)) {
    // Handle nullable end date/time values
    $event_end_date = $data['event_end_date'] ?? '';
    $event_end_time = $data['event_end_time'] ?? '';

    // Get badge abbreviation for hotline
    $hotline_badge = $hotline_badges[$data['hotline']] ?? $data['hotline'][0];

    // Add Canadian flag for Canada hotline events
    $flag_prefix = ($data['hotline'] === 'Canada') ? '<img src="https://flagcdn.com/16x12/ca.png" alt="Canada" style="vertical-align: middle; margin-right: 4px;">' : '';

    echo '<tr>';
    echo '<td>'.$flag_prefix.$data['event_name'].' <span class="badge badge-secondary" data-toggle="tooltip" title="'.$data['hotline'].' Hotline">'.$hotline_badge.'</span></td>';
    
    // Start Date
    echo '<td class="text-center">'.$data['event_date'].'</td>';
    
    // Start Time
    echo '<td class="text-center">'.$data['event_start'].'Z</td>';
    
    // End Date
    if (!empty($event_end_date)) {
        echo '<td class="text-center">'.$event_end_date.'</td>';
    } else {
        echo '<td class="text-center text-muted">—</td>';
    }
    
    // End Time
    if (!empty($event_end_time)) {
        echo '<td class="text-center">'.$event_end_time.'Z</td>';
    } else {
        echo '<td class="text-center text-muted">—</td>';
    }

    if ($data['oplevel'] == 1) {
        echo '<td class="text-dark text-center">'.$data['oplevel'].' - Steady State</td>';
    }
    elseif ($data['oplevel'] == 2) {
        echo '<td class="text-success text-center">'.$data['oplevel'].' - Localized Impact</td>';
    }
    elseif ($data['oplevel'] == 3) {
        echo '<td class="text-warning text-center">'.$data['oplevel'].' - Regional Impact</td>';
    }
    elseif ($data['oplevel'] == 4) {
        echo '<td class="text-danger text-center">'.$data['oplevel'].' - NAS-Wide Impact/td>';
    }

    echo '<td class="text-center">'.$data['updated_at'].'</td>';


        echo '<td><center>';
            echo '<a href="plan?'.$data['id'].'" data-toggle="tooltip" title="View PERTI Plan"><span class="badge badge-primary"><i class="fas fa-eye"></i> View</span></a>';
            echo ' ';
            echo '<a href="data?'.$data['id'].'" data-toggle="tooltip" title="View PERTI Staffing Data"><span class="badge badge-success"><i class="fas fa-table"></i> Data</span></a>';
            echo ' ';
            echo '<a href="review?'.$data['id'].'" data-toggle="tooltip" title="View Traffic Management Review"><span class="badge badge-info"><i class="fas fa-magnifying-glass"></i> TMR</span></a>';

            if ($perm == true) {
                echo ' ';
                echo '<a href="javascript:void(0)" data-toggle="tooltip" title="Edit PERTI Plan"><span class="badge badge-warning" data-toggle="modal" data-target="#editplanModal" data-id="'.$data['id'].'" data-event_name="'.$data['event_name'].'" data-event_date="'.$data['event_date'].'" data-event_start="'.$data['event_start'].'" data-event_end_date="'.$event_end_date.'" data-event_end_time="'.$event_end_time.'" data-oplevel="'.$data['oplevel'].'" data-hotline="'.$data['hotline'].'" data-event_banner="'.$data['event_banner'].'">
                    <i class="fas fa-pencil-alt"></i> Edit</span></a>';
                echo ' ';
                echo '<a href="javascript:void(0)" onclick="deletePlan('.$data['id'].')" data-toggle="tooltip" title="Delete PERTI Plan"><span class="badge badge-danger"><i class="fas fa-times"></i> Delete</span></a>';
            }
        echo '</center></td>';

    echo '</tr>';
}

?>
