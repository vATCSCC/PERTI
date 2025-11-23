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

$search = strip_tags($_GET['search']);

$query = mysqli_query($conn_sqli, ("SELECT * FROM config_data WHERE airport LIKE '%$search%' ORDER BY airport ASC LIMIT 50"));

while ($data = mysqli_fetch_array($query)) {
    echo '<tr>';

        echo '<td class="text-center">'.$data['airport'].'</td>';
        echo '<td class="text-center">'.$data['arr'].'</td>';
        echo '<td class="text-center">'.$data['dep'].'</td>';

        // (S) vmc_aar
        if ($data['vmc_aar'] < 12) {
            echo '<td class="text-center" style="background-color: #ee3e3e">'.$data['vmc_aar'].'</td>';
        } elseif ($data['vmc_aar'] < 25) {
            echo '<td class="text-center" style="background-color: #ee5f5f">'.$data['vmc_aar'].'</td>';
        } elseif ($data['vmc_aar'] < 36) {
            echo '<td class="text-center" style="background-color: #ef7f3c">'.$data['vmc_aar'].'</td>';
        } elseif ($data['vmc_aar'] < 46) {
            echo '<td class="text-center" style="background-color: #efc83c">'.$data['vmc_aar'].'</td>';
        } elseif ($data['vmc_aar'] < 58) {
            echo '<td class="text-center" style="background-color: #ecef3c">'.$data['vmc_aar'].'</td>';
        } elseif ($data['vmc_aar'] < 64) {
            echo '<td class="text-center" style="background-color: #b4ef3c">'.$data['vmc_aar'].'</td>';
        } elseif ($data['vmc_aar'] < 72) {
            echo '<td class="text-center" style="background-color: #b4ef3c">'.$data['vmc_aar'].'</td>';
        } elseif ($data['vmc_aar'] < 82) {
            echo '<td class="text-center" style="background-color: #6eef3c">'.$data['vmc_aar'].'</td>';
        } elseif ($data['vmc_aar'] < 96) {
            echo '<td class="text-center" style="background-color: #61b142">'.$data['vmc_aar'].'</td>';
        } elseif ($data['vmc_aar'] < 102) {
            echo '<td class="text-center" style="background-color: #42b168">'.$data['vmc_aar'].'</td>';
        } elseif ($data['vmc_aar'] < 112) {
            echo '<td class="text-center" style="background-color: #42b192">'.$data['vmc_aar'].'</td>';
        } elseif ($data['vmc_aar'] < 200) {
            echo '<td class="text-center" style="background-color: #428bb1">'.$data['vmc_aar'].'</td>';
        } else {
            echo '<td class="text-center">'.$data['vmc_aar'].'</td>';
        }
        // (E) vmc_aar

        // (S) lvmc_aar
        if ($data['lvmc_aar'] < 12) {
            echo '<td class="text-center" style="background-color: #ee3e3e">'.$data['lvmc_aar'].'</td>';
        } elseif ($data['lvmc_aar'] < 25) {
            echo '<td class="text-center" style="background-color: #ee5f5f">'.$data['lvmc_aar'].'</td>';
        } elseif ($data['lvmc_aar'] < 36) {
            echo '<td class="text-center" style="background-color: #ef7f3c">'.$data['lvmc_aar'].'</td>';
        } elseif ($data['lvmc_aar'] < 46) {
            echo '<td class="text-center" style="background-color: #efc83c">'.$data['lvmc_aar'].'</td>';
        } elseif ($data['lvmc_aar'] < 58) {
            echo '<td class="text-center" style="background-color: #ecef3c">'.$data['lvmc_aar'].'</td>';
        } elseif ($data['lvmc_aar'] < 64) {
            echo '<td class="text-center" style="background-color: #b4ef3c">'.$data['lvmc_aar'].'</td>';
        } elseif ($data['lvmc_aar'] < 72) {
            echo '<td class="text-center" style="background-color: #b4ef3c">'.$data['lvmc_aar'].'</td>';
        } elseif ($data['lvmc_aar'] < 82) {
            echo '<td class="text-center" style="background-color: #6eef3c">'.$data['lvmc_aar'].'</td>';
        } elseif ($data['lvmc_aar'] < 96) {
            echo '<td class="text-center" style="background-color: #61b142">'.$data['lvmc_aar'].'</td>';
        } elseif ($data['lvmc_aar'] < 102) {
            echo '<td class="text-center" style="background-color: #42b168">'.$data['lvmc_aar'].'</td>';
        } elseif ($data['lvmc_aar'] < 112) {
            echo '<td class="text-center" style="background-color: #42b192">'.$data['lvmc_aar'].'</td>';
        } elseif ($data['lvmc_aar'] < 200) {
            echo '<td class="text-center" style="background-color: #428bb1">'.$data['lvmc_aar'].'</td>';
        } else {
            echo '<td class="text-center">'.$data['lvmc_aar'].'</td>';
        }
        // (E) lvmc_aar

        // (S) imc_aar
        if ($data['imc_aar'] < 12) {
            echo '<td class="text-center" style="background-color: #ee3e3e">'.$data['imc_aar'].'</td>';
        } elseif ($data['imc_aar'] < 25) {
            echo '<td class="text-center" style="background-color: #ee5f5f">'.$data['imc_aar'].'</td>';
        } elseif ($data['imc_aar'] < 36) {
            echo '<td class="text-center" style="background-color: #ef7f3c">'.$data['imc_aar'].'</td>';
        } elseif ($data['imc_aar'] < 46) {
            echo '<td class="text-center" style="background-color: #efc83c">'.$data['imc_aar'].'</td>';
        } elseif ($data['imc_aar'] < 58) {
            echo '<td class="text-center" style="background-color: #ecef3c">'.$data['imc_aar'].'</td>';
        } elseif ($data['imc_aar'] < 64) {
            echo '<td class="text-center" style="background-color: #b4ef3c">'.$data['imc_aar'].'</td>';
        } elseif ($data['imc_aar'] < 72) {
            echo '<td class="text-center" style="background-color: #b4ef3c">'.$data['imc_aar'].'</td>';
        } elseif ($data['imc_aar'] < 82) {
            echo '<td class="text-center" style="background-color: #6eef3c">'.$data['imc_aar'].'</td>';
        } elseif ($data['imc_aar'] < 96) {
            echo '<td class="text-center" style="background-color: #61b142">'.$data['imc_aar'].'</td>';
        } elseif ($data['imc_aar'] < 102) {
            echo '<td class="text-center" style="background-color: #42b168">'.$data['imc_aar'].'</td>';
        } elseif ($data['imc_aar'] < 112) {
            echo '<td class="text-center" style="background-color: #42b192">'.$data['imc_aar'].'</td>';
        } elseif ($data['imc_aar'] < 200) {
            echo '<td class="text-center" style="background-color: #428bb1">'.$data['imc_aar'].'</td>';
        } else {
            echo '<td class="text-center">'.$data['imc_aar'].'</td>';
        }
        // (E) imc_aar

        // (S) limc_aar
        if ($data['limc_aar'] < 12) {
            echo '<td class="text-center" style="background-color: #ee3e3e">'.$data['limc_aar'].'</td>';
        } elseif ($data['limc_aar'] < 25) {
            echo '<td class="text-center" style="background-color: #ee5f5f">'.$data['limc_aar'].'</td>';
        } elseif ($data['limc_aar'] < 36) {
            echo '<td class="text-center" style="background-color: #ef7f3c">'.$data['limc_aar'].'</td>';
        } elseif ($data['limc_aar'] < 46) {
            echo '<td class="text-center" style="background-color: #efc83c">'.$data['limc_aar'].'</td>';
        } elseif ($data['limc_aar'] < 58) {
            echo '<td class="text-center" style="background-color: #ecef3c">'.$data['limc_aar'].'</td>';
        } elseif ($data['limc_aar'] < 64) {
            echo '<td class="text-center" style="background-color: #b4ef3c">'.$data['limc_aar'].'</td>';
        } elseif ($data['limc_aar'] < 72) {
            echo '<td class="text-center" style="background-color: #b4ef3c">'.$data['limc_aar'].'</td>';
        } elseif ($data['limc_aar'] < 82) {
            echo '<td class="text-center" style="background-color: #6eef3c">'.$data['limc_aar'].'</td>';
        } elseif ($data['limc_aar'] < 96) {
            echo '<td class="text-center" style="background-color: #61b142">'.$data['limc_aar'].'</td>';
        } elseif ($data['limc_aar'] < 102) {
            echo '<td class="text-center" style="background-color: #42b168">'.$data['limc_aar'].'</td>';
        } elseif ($data['limc_aar'] < 112) {
            echo '<td class="text-center" style="background-color: #42b192">'.$data['limc_aar'].'</td>';
        } elseif ($data['limc_aar'] < 200) {
            echo '<td class="text-center" style="background-color: #428bb1">'.$data['limc_aar'].'</td>';
        } else {
            echo '<td class="text-center">'.$data['limc_aar'].'</td>';
        }
        // (E) limc_aar
        

        echo '<td class="text-center">'.$data['vmc_adr'].'</td>';
        echo '<td class="text-center">'.$data['imc_adr'].'</td>';

        if ($perm == true) {
        echo '<td><center>';
                echo '<a href="javascript:void(0)" data-toggle="tooltip" title="Update Field Configuration"><span class="badge badge-warning" data-toggle="modal" data-target="#updateconfigModal" data-id="'.$data['id'].'" data-airport="'.$data['airport'].'" data-arr="'.$data['arr'].'" data-dep="'.$data['dep'].'" data-vmc_aar="'.$data['vmc_aar'].'" data-lvmc_aar="'.$data['lvmc_aar'].'" data-imc_aar="'.$data['imc_aar'].'" data-limc_aar="'.$data['limc_aar'].'" data-vmc_adr="'.$data['vmc_adr'].'" data-imc_adr="'.$data['imc_adr'].'">
                    <i class="fas fa-pencil-alt"></i> Update</span></a>';
                echo ' ';
                echo '<a href="javascript:void(0)" onclick="deleteConfig('.$data['id'].')" data-toggle="tooltip" title="Delete Field Configuration"><span class="badge badge-danger"><i class="fas fa-times"></i> Delete</span></a>';
        echo '</center></td>';
        }

    echo '</tr>';
}

?>