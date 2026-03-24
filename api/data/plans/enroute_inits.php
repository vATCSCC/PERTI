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

$p_id = intval(get_input('p_id'));

require_once(dirname(__DIR__, 3) . '/load/org_context.php');
if (!validate_plan_org($p_id, $conn_sqli)) {
    http_response_code(403);
    exit();
}

$c_q = $conn_sqli->query("SELECT COUNT(*) AS 'total' FROM p_enroute_init WHERE p_id='$p_id'")->fetch_assoc();

if ($c_q['total'] > 0) {
    $query = $conn_sqli->query("SELECT * FROM p_enroute_init WHERE p_id='$p_id'");

    while ($data = mysqli_fetch_array($query)) {
        // Setting Values
        $init_id = intval($data['id']);

        echo '<table class="table table-bordered w-75 text-left mb-0">';
            echo '<tbody>';
                echo '<tr class="bg-dark text-light">';
                    if ($perm == true) {
                        echo '<td colspan="20">'.htmlspecialchars($data['title'], ENT_QUOTES, 'UTF-8').'</td>';

                        echo '<td class="text-center" colspan="5">';
                            echo '<a href="javascript:void(0)" data-toggle="tooltip" title="Edit Enroute Initiative"><span class="badge badge-warning" data-toggle="modal" data-target="#editenrouteinitModal" data-id="'.intval($data['id']).'" data-title="'.htmlspecialchars($data['title'], ENT_QUOTES, 'UTF-8').'" data-context="'.htmlspecialchars($data['context'], ENT_QUOTES, 'UTF-8').'">
                            <i class="fas fa-pencil-alt"></i> Edit</span></a>';
                            echo ' ';
                            echo '<a href="javascript:void(0)" onclick="deleteEnrouteInit('.intval($data['id']).')" data-toggle="tooltip" title="Delete Enroute Initiative"><span class="badge badge-danger"><i class="fas fa-times"></i> Delete</span></a>';
                        echo '</td>';

                    } else {
                        echo '<td colspan="25">'.htmlspecialchars($data['title'], ENT_QUOTES, 'UTF-8').'</td>'; 
                    }

                echo '</tr>';
                echo '<tr class="bg-secondary">';
                    echo '<td>'.htmlspecialchars($data['context'], ENT_QUOTES, 'UTF-8').'</td>';

                    foreach ($times as &$time) {
                        echo '<td style="width: 3%;">'.$time.'Z</td>';
                    }
                echo '</tr>';
                echo '<tr>';
                    echo '<td>&nbsp;</td>';

                    foreach ($times as &$time) {
                        $ft = $time . '00';
                        $t_q = $conn_sqli->query("SELECT COUNT(*) AS 'total', probability, id FROM p_enroute_init_times WHERE init_id='$init_id' AND time='$ft'")->fetch_assoc();;

                        if ($t_q['total'] > 0) {
                            if ($t_q['probability'] == 0) {
                                // CDW
                                if ($perm == true) {
                                    echo '<td style="width: 3%; background-color: #ffd501;" onclick="changeEnrouteTime(`'.intval($t_q['id']).'`, `'.intval($t_q['probability']).'`)" data-toggle="tooltip" title="Critical Decision Window"></td>';
                                } else {
                                    echo '<td style="width: 3%; background-color: #ffd501;" data-toggle="tooltip" title="Critical Decision Window"></td>';
                                }

                            } elseif ($t_q['probability'] == 1) {
                                // Possible
                                if ($perm == true) {
                                    echo '<td style="width: 3%;" onclick="changeEnrouteTime(`'.intval($t_q['id']).'`, `'.intval($t_q['probability']).'`)" class="bg-info" data-toggle="tooltip" title="Possible"></td>';
                                } else {
                                    echo '<td style="width: 3%;" class="bg-info" data-toggle="tooltip" title="Possible"></td>';
                                }

                            } elseif ($t_q['probability'] == 2) {
                                // Probable
                                if ($perm == true) {
                                    echo '<td style="width: 3%; background-color: #ff5601;" onclick="changeEnrouteTime(`'.intval($t_q['id']).'`, `'.intval($t_q['probability']).'`)" data-toggle="tooltip" title="Probable"></td>';
                                } else {
                                    echo '<td style="width: 3%; background-color: #ff5601;" data-toggle="tooltip" title="Probable"></td>';
                                }
                            } elseif ($t_q['probability'] == 3) {
                                // Expected
                                if ($perm == true) {
                                    echo '<td style="width: 3%; background-color: #ff012b;" onclick="changeEnrouteTime(`'.intval($t_q['id']).'`, `'.intval($t_q['probability']).'`)" data-toggle="tooltip" title="Expected"></td>';
                                } else {
                                    echo '<td style="width: 3%; background-color: #ff012b;" data-toggle="tooltip" title="Expected"></td>';
                                }

                            } else {
                                // Actual
                                if ($perm == true) {
                                    echo '<td style="width: 3%; background-color: #4400CD;" onclick="deleteEnrouteTime(`'.intval($t_q['id']).'`)" data-toggle="tooltip" title="Actual"></td>';
                                } else {
                                    echo '<td style="width: 3%; background-color: #4400CD;" data-toggle="tooltip" title="Actual"></td>';
                                }

                            }
                        } else {
                            if ($perm == true) {
                                echo '<td style="width: 3%;" onclick="createEnrouteTime(`'.$init_id.'`, `'.$ft.'`)" data-toggle="tooltip" title="Toggle Enroute Initiative for '.$ft.'Z"></td>';
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
    echo '<h5 class="text-center w-100">No Enroute Initiatives</h5>';
}

?>