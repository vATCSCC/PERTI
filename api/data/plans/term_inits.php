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

$times = [
    '21',
    '22',
    '23',
    '00',
    '01',
    '02',
    '03',
    '04',
    '05',
    '06',
    '07',
    '08',
    '09',
    '10',
    '11',
    '12',
    '13',
    '14',
    '15',
    '16',
    '17',
    '18',
    '19',
    '20',
];

$p_id = strip_tags($_GET['p_id']);

$c_q = $conn_sqli->query("SELECT COUNT(*) AS 'total' FROM p_terminal_init WHERE p_id='$p_id'")->fetch_assoc();

if ($c_q['total'] > 0) {
    $query = $conn_sqli->query("SELECT * FROM p_terminal_init WHERE p_id='$p_id'");

    while ($data = mysqli_fetch_array($query)) {
        // Setting Values
        $init_id = $data['id'];

        echo '<table class="table table-bordered w-75 text-left mb-0">';
            echo '<tbody>';
                echo '<tr class="bg-dark text-light">';
                    if ($perm == true) {
                        echo '<td colspan="20">'.$data['title'].'</td>'; 

                        echo '<td class="text-center" colspan="5">';
                            echo '<a href="javascript:void(0)" data-toggle="tooltip" title="Edit Terminal Initiative"><span class="badge badge-warning" data-toggle="modal" data-target="#editterminalinitModal" data-id="'.$data['id'].'" data-title="'.$data['title'].'" data-context="'.$data['context'].'">
                            <i class="fas fa-pencil-alt"></i> Edit</span></a>';
                            echo ' ';
                            echo '<a href="javascript:void(0)" onclick="deleteTerminalInit('.$data['id'].')" data-toggle="tooltip" title="Delete Terminal Initiative"><span class="badge badge-danger"><i class="fas fa-times"></i> Delete</span></a>';
                        echo '</td>';

                    } else {
                        echo '<td colspan="25">'.$data['title'].'</td>'; 
                    }

                echo '</tr>';
                echo '<tr class="bg-secondary">';
                    echo '<td>'.$data['context'].'</td>';

                    foreach ($times as &$time) {
                        echo '<td style="width: 3%;">'.$time.'Z</td>';
                    }
                echo '</tr>';
                echo '<tr>';
                    echo '<td>&nbsp;</td>';

                    foreach ($times as &$time) {
                        $ft = $time . '00';
                        $t_q = $conn_sqli->query("SELECT COUNT(*) AS 'total', probability, id FROM p_terminal_init_times WHERE init_id='$init_id' AND time='$ft'")->fetch_assoc();;

                        if ($t_q['total'] > 0) {
                            if ($t_q['probability'] == 0) {
                                // CDW
                                if ($perm == true) {
                                    echo '<td style="width: 3%; background-color: #ffd501;" onclick="changeTermTime(`'.$t_q['id'].'`, `'.$t_q['probability'].'`)" data-toggle="tooltip" title="Critical Decision Window"></td>';
                                } else {
                                    echo '<td style="width: 3%; background-color: #ffd501;" data-toggle="tooltip" title="Critical Decision Window"></td>';
                                }

                            } elseif ($t_q['probability'] == 1) {
                                // Possible
                                if ($perm == true) {
                                    echo '<td style="width: 3%;" onclick="changeTermTime(`'.$t_q['id'].'`, `'.$t_q['probability'].'`)" class="bg-info" data-toggle="tooltip" title="Possible"></td>';
                                } else {
                                    echo '<td style="width: 3%;" class="bg-info" data-toggle="tooltip" title="Possible"></td>';
                                }

                            } elseif ($t_q['probability'] == 2) {
                                // Probable
                                if ($perm == true) {
                                    echo '<td style="width: 3%; background-color: #ff5601;" onclick="changeTermTime(`'.$t_q['id'].'`, `'.$t_q['probability'].'`)" data-toggle="tooltip" title="Probable"></td>';
                                } else {
                                    echo '<td style="width: 3%; background-color: #ff5601;" data-toggle="tooltip" title="Probable"></td>';
                                }
                            } elseif ($t_q['probability'] == 3) {
                                // Expected
                                if ($perm == true) {
                                    echo '<td style="width: 3%; background-color: #ff012b;" onclick="changeTermTime(`'.$t_q['id'].'`, `'.$t_q['probability'].'`)" data-toggle="tooltip" title="Expected"></td>';
                                } else {
                                    echo '<td style="width: 3%; background-color: #ff012b;" data-toggle="tooltip" title="Expected"></td>';
                                }

                            } else {
                                // Actual
                                if ($perm == true) {
                                    echo '<td style="width: 3%; background-color: #4400CD;" onclick="deleteTermTime(`'.$t_q['id'].'`)" data-toggle="tooltip" title="Actual"></td>';
                                } else {
                                    echo '<td style="width: 3%; background-color: #4400CD;" data-toggle="tooltip" title="Actual"></td>';
                                }

                            }
                        } else {
                            if ($perm == true) {
                                echo '<td style="width: 3%;" onclick="createTermTime(`'.$init_id.'`, `'.$ft.'`)" data-toggle="tooltip" title="Toggle Terminal Initiative for '.$ft.'Z"></td>';
                            } else {
                                echo '<td style="width: 3%;"></td>';
                            }
                        }

                    }
                echo '</tr>';
            echo '</tbody>';
        echo '</table>';
    }
} else {
    echo '<h5 class="text-center w-100">No Terminal Initiatives</h5>';
}

?>                
                                
                                
                                
