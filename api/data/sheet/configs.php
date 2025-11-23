<?php

// Session Start (S)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
  }
// Session Start (E)

include("../../../load/config.php");
include("../../../load/connect.php");


$p_id = strip_tags($_GET['p_id']);

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
    
            echo '<td style="width: 15%;"><center>';
                echo '<a href="javascript:void(0)" data-toggle="tooltip" title="Edit Config"><span class="badge badge-warning" data-toggle="modal" data-target="#editconfigModal" data-id="'.$data['id'].'" data-airport="'.$data['airport'].'" data-weather="'.$data['weather'].'" data-depart="'.$data['depart'].'" data-arrive="'.$data['arrive'].'" data-comments="'.$data['comments'].'">
                    <i class="fas fa-pencil-alt"></i> Edit</span></a>';
            echo '</center></td>';
    
        echo '</tr>';
    }
} else {
    echo '<tr><td class="text-center" colspan="8">No Configs Filed</td></tr>';
}


?>