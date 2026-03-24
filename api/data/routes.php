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
$query = mysqli_query($conn_sqli, "SELECT * FROM route_cdr WHERE rte_orig LIKE '$search_param' ORDER BY cdr_code ASC LIMIT 50");

while ($data = mysqli_fetch_array($query)) {
    echo '<tr>';

        echo '<td class="text-center">'.htmlspecialchars($data['cdr_code'], ENT_QUOTES, 'UTF-8').'</td>';
        echo '<td class="text-center">'.htmlspecialchars($data['rte_orig'], ENT_QUOTES, 'UTF-8').'</td>';
        echo '<td class="text-center">'.htmlspecialchars($data['rte_dest'], ENT_QUOTES, 'UTF-8').'</td>';
        echo '<td class="text-center">'.htmlspecialchars($data['rte_dep_fix'], ENT_QUOTES, 'UTF-8').'</td>';
        echo '<td class="text-center">'.htmlspecialchars($data['rte_string'], ENT_QUOTES, 'UTF-8').'</td>';
        echo '<td class="text-center">'.htmlspecialchars($data['rte_dep_artcc'], ENT_QUOTES, 'UTF-8').'</td>';
        echo '<td class="text-center">'.htmlspecialchars($data['rte_arr_artcc'], ENT_QUOTES, 'UTF-8').'</td>';
        echo '<td class="text-center">'.htmlspecialchars($data['pb_name'], ENT_QUOTES, 'UTF-8').'</td>';

        if ($perm == true) {
        echo '<td><center>';
                echo '<a href="javascript:void(0)" data-toggle="tooltip" title="Update Field Configuration"><span class="badge badge-warning" data-toggle="modal" data-target="#updateconfigModal" data-id="'.intval($data['id']).'" data-airport="'.htmlspecialchars($data['airport'], ENT_QUOTES, 'UTF-8').'" data-arr="'.htmlspecialchars($data['arr'], ENT_QUOTES, 'UTF-8').'" data-dep="'.htmlspecialchars($data['dep'], ENT_QUOTES, 'UTF-8').'" data-vmc_aar="'.htmlspecialchars($data['vmc_aar'], ENT_QUOTES, 'UTF-8').'" data-lvmc_aar="'.htmlspecialchars($data['lvmc_aar'], ENT_QUOTES, 'UTF-8').'" data-imc_aar="'.htmlspecialchars($data['imc_aar'], ENT_QUOTES, 'UTF-8').'" data-limc_aar="'.htmlspecialchars($data['limc_aar'], ENT_QUOTES, 'UTF-8').'" data-vmc_adr="'.htmlspecialchars($data['vmc_adr'], ENT_QUOTES, 'UTF-8').'" data-imc_adr="'.htmlspecialchars($data['imc_adr'], ENT_QUOTES, 'UTF-8').'">
                    <i class="fas fa-pencil-alt"></i> Update</span></a>';
                echo ' ';
                echo '<a href="javascript:void(0)" onclick="deleteConfig('.intval($data['id']).')" data-toggle="tooltip" title="Delete Field Configuration"><span class="badge badge-danger"><i class="fas fa-times"></i> Delete</span></a>';
        echo '</center></td>';
        }

    echo '</tr>';
}

?>
