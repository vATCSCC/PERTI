<?php

// api/data/configs.php
// Retrieves airport configuration data from ADL SQL Server

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

// Helper: Get color for rate value
function getRateColor($rate) {
    if ($rate === null || $rate === '') return '';
    $r = intval($rate);
    if ($r < 12) return '#ee3e3e';
    if ($r < 25) return '#ee5f5f';
    if ($r < 36) return '#ef7f3c';
    if ($r < 46) return '#efc83c';
    if ($r < 58) return '#ecef3c';
    if ($r < 72) return '#b4ef3c';
    if ($r < 82) return '#6eef3c';
    if ($r < 96) return '#61b142';
    if ($r < 102) return '#42b168';
    if ($r < 112) return '#42b192';
    if ($r < 200) return '#428bb1';
    return '';
}

// Helper: Output rate cell with color
function rateCell($rate) {
    $color = getRateColor($rate);
    $style = $color ? " style=\"background-color: {$color}\"" : '';
    $val = ($rate !== null && $rate !== '') ? intval($rate) : '-';
    echo "<td class=\"text-center\"{$style}>{$val}</td>";
}

$search = isset($_GET['search']) ? strip_tags($_GET['search']) : '';

// Check if ADL connection is available
if (!$conn_adl) {
    // Fallback to MySQL (legacy)
    $query = mysqli_query($conn_sqli, "SELECT * FROM config_data WHERE airport LIKE '%$search%' ORDER BY airport ASC LIMIT 50");

    while ($data = mysqli_fetch_array($query)) {
        echo '<tr>';
        echo '<td class="text-center">' . htmlspecialchars($data['airport']) . '</td>';
        echo '<td class="text-center">K' . htmlspecialchars($data['airport']) . '</td>';
        echo '<td class="text-center">-</td>';
        echo '<td class="text-center">' . htmlspecialchars($data['arr']) . '</td>';
        echo '<td class="text-center">' . htmlspecialchars($data['dep']) . '</td>';

        rateCell($data['vmc_aar']);
        rateCell($data['lvmc_aar']);
        rateCell($data['imc_aar']);
        rateCell($data['limc_aar']);
        rateCell(null); // VLIMC not in legacy
        rateCell($data['vmc_adr']);
        rateCell($data['imc_adr']);

        if ($perm == true) {
            echo '<td><center>';
            echo '<a href="javascript:void(0)" data-toggle="tooltip" title="Update Field Configuration">';
            echo '<span class="badge badge-warning" data-toggle="modal" data-target="#updateconfigModal" ';
            echo 'data-id="' . $data['id'] . '" ';
            echo 'data-airport="' . htmlspecialchars($data['airport']) . '" ';
            echo 'data-arr="' . htmlspecialchars($data['arr']) . '" ';
            echo 'data-dep="' . htmlspecialchars($data['dep']) . '" ';
            echo 'data-vmc_aar="' . $data['vmc_aar'] . '" ';
            echo 'data-lvmc_aar="' . $data['lvmc_aar'] . '" ';
            echo 'data-imc_aar="' . $data['imc_aar'] . '" ';
            echo 'data-limc_aar="' . $data['limc_aar'] . '" ';
            echo 'data-vmc_adr="' . $data['vmc_adr'] . '" ';
            echo 'data-imc_adr="' . $data['imc_adr'] . '">';
            echo '<i class="fas fa-pencil-alt"></i> Update</span></a>';
            echo ' ';
            echo '<a href="javascript:void(0)" onclick="deleteConfig(' . $data['id'] . ')" data-toggle="tooltip" title="Delete Field Configuration">';
            echo '<span class="badge badge-danger"><i class="fas fa-times"></i> Delete</span></a>';
            echo '</center></td>';
        }
        echo '</tr>';
    }
} else {
    // Use ADL SQL Server - Join summary and rates views
    $sql = "
        SELECT
            s.config_id,
            s.airport_faa,
            s.airport_icao,
            s.config_name,
            s.config_code,
            s.arr_runways,
            s.dep_runways,
            r.vatsim_vmc_aar,
            r.vatsim_lvmc_aar,
            r.vatsim_imc_aar,
            r.vatsim_limc_aar,
            r.vatsim_vlimc_aar,
            r.vatsim_vmc_adr,
            r.vatsim_lvmc_adr,
            r.vatsim_imc_adr,
            r.vatsim_limc_adr,
            r.vatsim_vlimc_adr,
            r.rw_vmc_aar,
            r.rw_lvmc_aar,
            r.rw_imc_aar,
            r.rw_limc_aar,
            r.rw_vlimc_aar,
            r.rw_vmc_adr,
            r.rw_lvmc_adr,
            r.rw_imc_adr,
            r.rw_limc_adr,
            r.rw_vlimc_adr
        FROM dbo.vw_airport_config_summary s
        LEFT JOIN dbo.vw_airport_config_rates r ON s.config_id = r.config_id
        WHERE s.airport_faa LIKE ? OR s.airport_icao LIKE ? OR s.config_name LIKE ?
        ORDER BY s.airport_faa ASC, s.config_name ASC
    ";

    $searchParam = '%' . $search . '%';
    $params = [$searchParam, $searchParam, $searchParam];

    $stmt = sqlsrv_query($conn_adl, $sql, $params);

    if ($stmt === false) {
        error_log("ADL configs query failed: " . adl_sql_error_message());
        echo '<tr><td colspan="12" class="text-center text-danger">Error loading configurations</td></tr>';
    } else {
        while ($data = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            echo '<tr>';

            // Airport FAA
            echo '<td class="text-center">' . htmlspecialchars($data['airport_faa']) . '</td>';

            // Airport ICAO
            echo '<td class="text-center">' . htmlspecialchars($data['airport_icao']) . '</td>';

            // Config Name
            $configDisplay = htmlspecialchars($data['config_name']);
            if ($data['config_code']) {
                $configDisplay .= ' <small class="text-muted">(' . htmlspecialchars($data['config_code']) . ')</small>';
            }
            echo '<td class="text-center">' . $configDisplay . '</td>';

            // Arrival Runways
            echo '<td class="text-center">' . htmlspecialchars($data['arr_runways'] ?? '-') . '</td>';

            // Departure Runways
            echo '<td class="text-center">' . htmlspecialchars($data['dep_runways'] ?? '-') . '</td>';

            // VATSIM Rates (ARR: VMC, LVMC, IMC, LIMC, VLIMC)
            rateCell($data['vatsim_vmc_aar']);
            rateCell($data['vatsim_lvmc_aar']);
            rateCell($data['vatsim_imc_aar']);
            rateCell($data['vatsim_limc_aar']);
            rateCell($data['vatsim_vlimc_aar']);

            // VATSIM Rates (DEP: VMC, IMC)
            rateCell($data['vatsim_vmc_adr']);
            rateCell($data['vatsim_imc_adr']);

            // Actions
            if ($perm == true) {
                echo '<td><center>';

                // Build data attributes for modal
                echo '<a href="javascript:void(0)" data-toggle="tooltip" title="Update Field Configuration">';
                echo '<span class="badge badge-warning" data-toggle="modal" data-target="#updateconfigModal" ';
                echo 'data-id="' . $data['config_id'] . '" ';
                echo 'data-airport="' . htmlspecialchars($data['airport_faa']) . '" ';
                echo 'data-icao="' . htmlspecialchars($data['airport_icao']) . '" ';
                echo 'data-config_name="' . htmlspecialchars($data['config_name']) . '" ';
                echo 'data-config_code="' . htmlspecialchars($data['config_code'] ?? '') . '" ';
                echo 'data-arr="' . htmlspecialchars($data['arr_runways'] ?? '') . '" ';
                echo 'data-dep="' . htmlspecialchars($data['dep_runways'] ?? '') . '" ';

                // VATSIM rates
                echo 'data-vatsim_vmc_aar="' . ($data['vatsim_vmc_aar'] ?? '') . '" ';
                echo 'data-vatsim_lvmc_aar="' . ($data['vatsim_lvmc_aar'] ?? '') . '" ';
                echo 'data-vatsim_imc_aar="' . ($data['vatsim_imc_aar'] ?? '') . '" ';
                echo 'data-vatsim_limc_aar="' . ($data['vatsim_limc_aar'] ?? '') . '" ';
                echo 'data-vatsim_vlimc_aar="' . ($data['vatsim_vlimc_aar'] ?? '') . '" ';
                echo 'data-vatsim_vmc_adr="' . ($data['vatsim_vmc_adr'] ?? '') . '" ';
                echo 'data-vatsim_lvmc_adr="' . ($data['vatsim_lvmc_adr'] ?? '') . '" ';
                echo 'data-vatsim_imc_adr="' . ($data['vatsim_imc_adr'] ?? '') . '" ';
                echo 'data-vatsim_limc_adr="' . ($data['vatsim_limc_adr'] ?? '') . '" ';
                echo 'data-vatsim_vlimc_adr="' . ($data['vatsim_vlimc_adr'] ?? '') . '" ';

                // Real-World rates
                echo 'data-rw_vmc_aar="' . ($data['rw_vmc_aar'] ?? '') . '" ';
                echo 'data-rw_lvmc_aar="' . ($data['rw_lvmc_aar'] ?? '') . '" ';
                echo 'data-rw_imc_aar="' . ($data['rw_imc_aar'] ?? '') . '" ';
                echo 'data-rw_limc_aar="' . ($data['rw_limc_aar'] ?? '') . '" ';
                echo 'data-rw_vlimc_aar="' . ($data['rw_vlimc_aar'] ?? '') . '" ';
                echo 'data-rw_vmc_adr="' . ($data['rw_vmc_adr'] ?? '') . '" ';
                echo 'data-rw_lvmc_adr="' . ($data['rw_lvmc_adr'] ?? '') . '" ';
                echo 'data-rw_imc_adr="' . ($data['rw_imc_adr'] ?? '') . '" ';
                echo 'data-rw_limc_adr="' . ($data['rw_limc_adr'] ?? '') . '" ';
                echo 'data-rw_vlimc_adr="' . ($data['rw_vlimc_adr'] ?? '') . '">';

                echo '<i class="fas fa-pencil-alt"></i> Update</span></a>';
                echo ' ';
                echo '<a href="javascript:void(0)" onclick="deleteConfig(' . $data['config_id'] . ')" data-toggle="tooltip" title="Delete Field Configuration">';
                echo '<span class="badge badge-danger"><i class="fas fa-times"></i> Delete</span></a>';
                echo '</center></td>';
            }

            echo '</tr>';
        }

        sqlsrv_free_stmt($stmt);
    }
}

?>
