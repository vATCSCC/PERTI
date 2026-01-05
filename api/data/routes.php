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

$query = mysqli_query($conn_sqli, ("SELECT * FROM route_cdr WHERE rte_orig LIKE '%$search%' ORDER BY cdr_code ASC LIMIT 50"));

while ($data = mysqli_fetch_array($query)) {
    echo '<tr>';

        echo '<td class="text-center">'.$data['cdr_code'].'</td>';
        echo '<td class="text-center">'.$data['rte_orig'].'</td>';
        echo '<td class="text-center">'.$data['rte_dest'].'</td>';
        echo '<td class="text-center">'.$data['rte_dep_fix'].'</td>';
        echo '<td class="text-center">'.$data['rte_string'].'</td>';
        echo '<td class="text-center">'.$data['rte_dep_artcc'].'</td>';
        echo '<td class="text-center">'.$data['rte_arr_artcc'].'</td>';
        echo '<td class="text-center">'.$data['pb_name'].'</td>';

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
