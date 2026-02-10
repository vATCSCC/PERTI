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

$c_q = $conn_sqli->query("SELECT COUNT(*) AS 'total' FROM p_configs WHERE p_id='$p_id'")->fetch_assoc();


if ($c_q['total'] > 0) {
    $query = $conn_sqli->query("SELECT * FROM p_configs WHERE p_id='$p_id'");

    while ($data = mysqli_fetch_array($query)) {
        echo '<tr>';

            echo '<td class="text-center" style="width: 10%;">'.$data['airport'].'</td>';

            if ($data['weather'] == 0) {
                echo '<td class="text-center" style="width: 10%;">Unknown</td>';
            } elseif ($data['weather'] == 1) {
                echo '<td class="text-center" style="width: 10%; background-color: #5CFF5C;">VMC</td>'; 
            } elseif ($data['weather'] == 2) {
                echo '<td class="text-center" style="width: 10%; background-color: #85bd02;">LVMC</td>'; 
            } elseif ($data['weather'] == 3) {
                echo '<td class="text-center" style="width: 10%; background-color: #02bef7;">IMC</td>'; 
            } else {
                echo '<td class="text-center" style="width: 10%; background-color: #6122f5;">LIMC</td>';  
            }

            echo '<td class="text-center" style="width: 15%;">'.$data['arrive'].'</td>';
            echo '<td class="text-center" style="width: 15%;">'.$data['depart'].'</td>';
            echo '<td class="text-center" style="width: 10%;">'.$data['aar'].'</td>';
            echo '<td class="text-center" style="width: 10%;">'.$data['adr'].'</td>';
            echo '<td class="text-center">'.$data['comments'].'</td>';
    
            if ($perm == true) {
                echo '<td style="width: 15%;"><center>';
                    $c_fc = $conn_sqli->query("SELECT COUNT(*) AS 'total', vmc_aar, lvmc_aar, imc_aar, limc_aar, vmc_adr, imc_adr FROM config_data WHERE airport='$data[airport]' AND arr='$data[arrive]' AND dep='$data[depart]'")->fetch_assoc();
                    if ($c_fc['total'] > 0) {
                        if ($data['weather'] == 0) {
                            $aar = 0;
                            $adr = 0;
                        } elseif ($data['weather'] == 1) {
                            $aar = $c_fc['vmc_aar'];
                            $adr = $c_fc['vmc_adr'];
                        } elseif ($data['weather'] == 2) {
                            $aar = $c_fc['lvmc_aar'];
                            $adr = $c_fc['vmc_adr'];
                        } elseif ($data['weather'] == 3) {
                            $aar = $c_fc['imc_aar'];
                            $adr = $c_fc['imc_adr'];
                        } else {
                            $aar = $c_fc['limc_aar'];
                            $adr = $c_fc['imc_adr'];
                        }

                        echo '<a href="javascript:void(0)" onclick="autoConfig(`'.$data['id'].'`, `'.$aar.'`, `'.$adr.'`)"><span class="badge badge-info"><i class="fas fa-robot"></i> Autofill</span></a>'; 
                    } else {
                        echo '<a href="javascript:void(0)"><span class="badge badge-secondary"><i class="fas fa-robot"></i> <s>Autofill</s></span></a>';  
                    }

                    echo ' ';
                    echo '<a href="javascript:void(0)" data-toggle="tooltip" title="Edit Config"><span class="badge badge-warning" data-toggle="modal" data-target="#editconfigModal" data-id="'.$data['id'].'" data-airport="'.$data['airport'].'" data-weather="'.$data['weather'].'" data-depart="'.$data['depart'].'" data-arrive="'.$data['arrive'].'" data-aar="'.$data['aar'].'" data-adr="'.$data['adr'].'" data-comments="'.$data['comments'].'">
                        <i class="fas fa-pencil-alt"></i> Edit</span></a>';
                    echo ' ';
                    echo '<a href="javascript:void(0)" onclick="deleteConfig('.$data['id'].')" data-toggle="tooltip" title="Delete Config"><span class="badge badge-danger"><i class="fas fa-times"></i> Delete</span></a>';
                echo '</center></td>';
            }
    
        echo '</tr>';
    }
} else {
    echo '<tr><td class="text-center" colspan="8">No Configs Filed</td></tr>';
}


?>