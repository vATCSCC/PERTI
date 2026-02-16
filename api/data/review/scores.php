<?php

// Session Start (S)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
  }
// Session Start (E)

include("../../../load/config.php");
define('PERTI_MYSQL_ONLY', true);
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

require_once(dirname(__DIR__, 3) . '/load/org_context.php');
if (!validate_plan_org((int)$p_id, $conn_sqli)) {
    http_response_code(403);
    exit();
}

$c_q = $conn_sqli->query("SELECT COUNT(*) AS 'total' FROM r_scores WHERE p_id='$p_id'")->fetch_assoc();


if ($c_q['total'] > 0) {
    $query = $conn_sqli->query("SELECT * FROM r_scores WHERE p_id='$p_id'");

    while ($data = mysqli_fetch_array($query)) {
        // Setting Values
        $staffing = $data['staffing'];
        $tactical = $data['tactical'];
        $other = $data['other'];
        $perti = $data['perti'];
        $ntml = $data['ntml'];
        $tmi = $data['tmi'];
        $ace = $data['ace'];
        $overall = ($staffing + $tactical + $other + $perti + $ntml + $tmi + $ace) / 7;

        echo '<tr>';

            echo '<td class="text-center" style="width: 70%;">Staffing Score</td>';

            if ($staffing == 1) {
                echo '<td class="text-center" style="background-color: #f75d3e">1</td>';
            } elseif ($staffing == 2) {
                echo '<td class="text-center" style="background-color: #f79b3e">2</td>';
            } elseif ($staffing == 3) {
                echo '<td class="text-center" style="background-color: #f7e83e">3</td>';
            }  elseif ($staffing == 4) {
                echo '<td class="text-center" style="background-color: #bff73e">4</td>';
            } else {
                echo '<td class="text-center" style="background-color: #69f73e">5</td>';
            }
    
        echo '</tr>';

        echo '<tr>';

            echo '<td class="text-center" style="width: 70%;">Tactical (Real-Time) Score</td>';

            if ($tactical == 1) {
                echo '<td class="text-center" style="background-color: #f75d3e">1</td>';
            } elseif ($tactical == 2) {
                echo '<td class="text-center" style="background-color: #f79b3e">2</td>';
            } elseif ($tactical == 3) {
                echo '<td class="text-center" style="background-color: #f7e83e">3</td>';
            }  elseif ($tactical == 4) {
                echo '<td class="text-center" style="background-color: #bff73e">4</td>';
            } else {
                echo '<td class="text-center" style="background-color: #69f73e">5</td>';
            }
    
        echo '</tr>';

        echo '<tr>';

            echo '<td class="text-center" style="width: 70%;">Other Coordination Score</td>';

            if ($other == 1) {
                echo '<td class="text-center" style="background-color: #f75d3e">1</td>';
            } elseif ($other == 2) {
                echo '<td class="text-center" style="background-color: #f79b3e">2</td>';
            } elseif ($other == 3) {
                echo '<td class="text-center" style="background-color: #f7e83e">3</td>';
            }  elseif ($other == 4) {
                echo '<td class="text-center" style="background-color: #bff73e">4</td>';
            } else {
                echo '<td class="text-center" style="background-color: #69f73e">5</td>';
            }
    
        echo '</tr>';

        echo '<tr>';

            echo '<td class="text-center" style="width: 70%;">PERTI Plan Score</td>';

            if ($perti == 1) {
                echo '<td class="text-center" style="background-color: #f75d3e">1</td>';
            } elseif ($perti == 2) {
                echo '<td class="text-center" style="background-color: #f79b3e">2</td>';
            } elseif ($perti == 3) {
                echo '<td class="text-center" style="background-color: #f7e83e">3</td>';
            }  elseif ($perti == 4) {
                echo '<td class="text-center" style="background-color: #bff73e">4</td>';
            } else {
                echo '<td class="text-center" style="background-color: #69f73e">5</td>';
            }
    
        echo '</tr>';

        echo '<tr>';

            echo '<td class="text-center" style="width: 70%;">NTML/Advisory Usage Score</td>';

            if ($ntml == 1) {
                echo '<td class="text-center" style="background-color: #f75d3e">1</td>';
            } elseif ($ntml == 2) {
                echo '<td class="text-center" style="background-color: #f79b3e">2</td>';
            } elseif ($ntml == 3) {
                echo '<td class="text-center" style="background-color: #f7e83e">3</td>';
            }  elseif ($ntml == 4) {
                echo '<td class="text-center" style="background-color: #bff73e">4</td>';
            } else {
                echo '<td class="text-center" style="background-color: #69f73e">5</td>';
            }
    
        echo '</tr>';

        echo '<tr>';

            echo '<td class="text-center" style="width: 70%;">TMI Score</td>';

            if ($tmi == 1) {
                echo '<td class="text-center" style="background-color: #f75d3e">1</td>';
            } elseif ($tmi == 2) {
                echo '<td class="text-center" style="background-color: #f79b3e">2</td>';
            } elseif ($tmi == 3) {
                echo '<td class="text-center" style="background-color: #f7e83e">3</td>';
            }  elseif ($tmi == 4) {
                echo '<td class="text-center" style="background-color: #bff73e">4</td>';
            } else {
                echo '<td class="text-center" style="background-color: #69f73e">5</td>';
            }
    
        echo '</tr>';

        echo '<tr>';

            echo '<td class="text-center" style="width: 70%;">ACE Team Implementation Score</td>';

            if ($ace == 1) {
                echo '<td class="text-center" style="background-color: #f75d3e">1</td>';
            } elseif ($ace == 2) {
                echo '<td class="text-center" style="background-color: #f79b3e">2</td>';
            } elseif ($ace == 3) {
                echo '<td class="text-center" style="background-color: #f7e83e">3</td>';
            }  elseif ($ace == 4) {
                echo '<td class="text-center" style="background-color: #bff73e">4</td>';
            } else {
                echo '<td class="text-center" style="background-color: #69f73e">5</td>';
            }
    
        echo '</tr>';

        echo '<tr style="border-top: 3px solid #000;">';

            echo '<td class="text-center" style="width: 70%;">Overall Score</td>';

            if ($overall <= 1) {
                echo '<td class="text-center" style="background-color: #f75d3e">1</td>';
            } elseif ($overall <= 2 && $overall > 1) {
                echo '<td class="text-center" style="background-color: #f79b3e">2</td>';
            } elseif ($overall <= 3 && $overall > 2) {
                echo '<td class="text-center" style="background-color: #f7e83e">3</td>';
            }  elseif ($overall <= 4 && $overall > 3) {
                echo '<td class="text-center" style="background-color: #bff73e">4</td>';
            } else {
                echo '<td class="text-center" style="background-color: #69f73e">5</td>';
            }
    
        echo '</tr>';

        if ($perm == true) {
            echo '<tr><td colspan="2">';
                echo '<a href="javascript:void(0)" data-toggle="tooltip" title="Edit Score"><span class="badge badge-warning" data-toggle="modal" data-target="#editscoreModal" data-id="'.$data['id'].'" data-staffing="'.$staffing.'" data-tactical="'.$tactical.'" data-other="'.$other.'" data-perti="'.$perti.'" data-ntml="'.$ntml.'" data-tmi="'.$tmi.'" data-ace="'.$ace.'">
                <i class="fas fa-pencil-alt"></i> Edit</span></a>';
                echo ' ';
                echo '<a href="javascript:void(0)" onclick="deleteScore('.$data['id'].')" data-toggle="tooltip" title="Delete Score"><span class="badge badge-danger"><i class="fas fa-times"></i> Delete</span></a>';
            echo '</td></tr>';
        }

    }
} else {
    echo '<tr><td class="text-center" colspan="2">No Scores Added</td></tr>';
}


?>