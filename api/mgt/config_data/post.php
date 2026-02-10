<?php

// api/mgt/config_data/post.php
// Creates a new airport configuration in ADL SQL Server

// Session Start (S)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}
// Session Start (E)

include("../../../load/config.php");
include("../../../load/connect.php");

$domain = strip_tags(SITE_DOMAIN);

// Check Perms
$perm = false;
if (!defined('DEV')) {
    if (isset($_SESSION['VATSIM_CID'])) {
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

// Check Perms (S)
if ($perm == true) {
    // Do Nothing
} else {
    http_response_code(403);
    exit();
}
// (E)

// Get form data
$airport = isset($_POST['airport_faa']) ? strtoupper(trim(post_input('airport_faa'))) : (isset($_POST['airport']) ? strtoupper(trim(post_input('airport'))) : '');
$config_name = isset($_POST['config_name']) ? trim(post_input('config_name')) : 'Default';
$config_code = isset($_POST['config_code']) ? trim(post_input('config_code')) : null;
$arr_runways = isset($_POST['arr_runways']) ? trim(post_input('arr_runways')) : (isset($_POST['arr']) ? trim(post_input('arr')) : '');
$dep_runways = isset($_POST['dep_runways']) ? trim(post_input('dep_runways')) : (isset($_POST['dep']) ? trim(post_input('dep')) : '');

// Get ICAO - use user-provided value if given, otherwise look up from apts table
$airport_faa = $airport;
$airport_icao = isset($_POST['airport_icao']) ? strtoupper(trim(post_input('airport_icao'))) : '';

// If no ICAO provided (or empty), look up from apts table or use fallback logic
if (empty($airport_icao) && $conn_adl) {
    $lookup_sql = "SELECT ICAO_ID FROM dbo.apts WHERE ARPT_ID = ?";
    $lookup_stmt = sqlsrv_query($conn_adl, $lookup_sql, [$airport_faa]);
    if ($lookup_stmt !== false) {
        $lookup_row = sqlsrv_fetch_array($lookup_stmt, SQLSRV_FETCH_ASSOC);
        if ($lookup_row && !empty($lookup_row['ICAO_ID'])) {
            $airport_icao = $lookup_row['ICAO_ID'];
        }
        sqlsrv_free_stmt($lookup_stmt);
    }
}

// If still empty, use fallback prefix logic
if (empty($airport_icao)) {
    if (strlen($airport) == 4) {
        $airport_icao = $airport;
    } elseif (strlen($airport) == 3 && $airport[0] === 'Y') {
        $airport_icao = 'C' . $airport;
    } elseif (strlen($airport) == 3) {
        $airport_icao = 'K' . $airport;
    } else {
        $airport_icao = $airport;
    }
}

// Get VATSIM rates
$vatsim_rates = [
    ['weather' => 'VMC', 'type' => 'ARR', 'value' => isset($_POST['vatsim_vmc_aar']) ? post_int('vatsim_vmc_aar') : null],
    ['weather' => 'LVMC', 'type' => 'ARR', 'value' => isset($_POST['vatsim_lvmc_aar']) ? post_int('vatsim_lvmc_aar') : null],
    ['weather' => 'IMC', 'type' => 'ARR', 'value' => isset($_POST['vatsim_imc_aar']) ? post_int('vatsim_imc_aar') : null],
    ['weather' => 'LIMC', 'type' => 'ARR', 'value' => isset($_POST['vatsim_limc_aar']) ? post_int('vatsim_limc_aar') : null],
    ['weather' => 'VLIMC', 'type' => 'ARR', 'value' => isset($_POST['vatsim_vlimc_aar']) ? post_int('vatsim_vlimc_aar') : null],
    ['weather' => 'VMC', 'type' => 'DEP', 'value' => isset($_POST['vatsim_vmc_adr']) ? post_int('vatsim_vmc_adr') : null],
    ['weather' => 'LVMC', 'type' => 'DEP', 'value' => isset($_POST['vatsim_lvmc_adr']) ? post_int('vatsim_lvmc_adr') : null],
    ['weather' => 'IMC', 'type' => 'DEP', 'value' => isset($_POST['vatsim_imc_adr']) ? post_int('vatsim_imc_adr') : null],
    ['weather' => 'LIMC', 'type' => 'DEP', 'value' => isset($_POST['vatsim_limc_adr']) ? post_int('vatsim_limc_adr') : null],
    ['weather' => 'VLIMC', 'type' => 'DEP', 'value' => isset($_POST['vatsim_vlimc_adr']) ? post_int('vatsim_vlimc_adr') : null],
];

// Get Real-World rates
$rw_rates = [
    ['weather' => 'VMC', 'type' => 'ARR', 'value' => isset($_POST['rw_vmc_aar']) ? post_int('rw_vmc_aar') : null],
    ['weather' => 'LVMC', 'type' => 'ARR', 'value' => isset($_POST['rw_lvmc_aar']) ? post_int('rw_lvmc_aar') : null],
    ['weather' => 'IMC', 'type' => 'ARR', 'value' => isset($_POST['rw_imc_aar']) ? post_int('rw_imc_aar') : null],
    ['weather' => 'LIMC', 'type' => 'ARR', 'value' => isset($_POST['rw_limc_aar']) ? post_int('rw_limc_aar') : null],
    ['weather' => 'VLIMC', 'type' => 'ARR', 'value' => isset($_POST['rw_vlimc_aar']) ? post_int('rw_vlimc_aar') : null],
    ['weather' => 'VMC', 'type' => 'DEP', 'value' => isset($_POST['rw_vmc_adr']) ? post_int('rw_vmc_adr') : null],
    ['weather' => 'LVMC', 'type' => 'DEP', 'value' => isset($_POST['rw_lvmc_adr']) ? post_int('rw_lvmc_adr') : null],
    ['weather' => 'IMC', 'type' => 'DEP', 'value' => isset($_POST['rw_imc_adr']) ? post_int('rw_imc_adr') : null],
    ['weather' => 'LIMC', 'type' => 'DEP', 'value' => isset($_POST['rw_limc_adr']) ? post_int('rw_limc_adr') : null],
    ['weather' => 'VLIMC', 'type' => 'DEP', 'value' => isset($_POST['rw_vlimc_adr']) ? post_int('rw_vlimc_adr') : null],
];

// Validate required fields
if (empty($airport_faa)) {
    http_response_code(400);
    echo json_encode(['error' => 'Airport code is required']);
    exit();
}

// Check if ADL connection is available
if (!$conn_adl) {
    // Fallback to MySQL (legacy)
    try {
        $conn_pdo->beginTransaction();

        // Map new fields to old schema
        $vmc_aar = $_POST['vatsim_vmc_aar'] ?? $_POST['vmc_aar'] ?? 0;
        $lvmc_aar = $_POST['vatsim_lvmc_aar'] ?? $_POST['lvmc_aar'] ?? 0;
        $imc_aar = $_POST['vatsim_imc_aar'] ?? $_POST['imc_aar'] ?? 0;
        $limc_aar = $_POST['vatsim_limc_aar'] ?? $_POST['limc_aar'] ?? 0;
        $vmc_adr = $_POST['vatsim_vmc_adr'] ?? $_POST['vmc_adr'] ?? 0;
        $imc_adr = $_POST['vatsim_imc_adr'] ?? $_POST['imc_adr'] ?? 0;

        $sql = "INSERT INTO config_data (airport, arr, dep, vmc_aar, lvmc_aar, imc_aar, limc_aar, vmc_adr, imc_adr)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn_pdo->prepare($sql);
        $stmt->execute([$airport_faa, $arr_runways, $dep_runways, $vmc_aar, $lvmc_aar, $imc_aar, $limc_aar, $vmc_adr, $imc_adr]);

        $conn_pdo->commit();
        http_response_code(200);
        echo json_encode(['success' => true, 'id' => $conn_pdo->lastInsertId()]);
    } catch (PDOException $e) {
        $conn_pdo->rollback();
        http_response_code(500);
        error_log("Config insert failed: " . $e->getMessage());
        echo json_encode(['error' => 'Database error']);
    }
    exit();
}

// Use ADL SQL Server
sqlsrv_begin_transaction($conn_adl);

try {
    // 1. Insert into airport_config
    $sql = "INSERT INTO dbo.airport_config (airport_faa, airport_icao, config_name, config_code)
            OUTPUT INSERTED.config_id
            VALUES (?, ?, ?, ?)";
    $params = [$airport_faa, $airport_icao, $config_name, $config_code ?: null];

    $stmt = sqlsrv_query($conn_adl, $sql, $params);

    if ($stmt === false) {
        throw new Exception("Failed to insert config: " . adl_sql_error_message());
    }

    // Get the inserted config_id
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    $config_id = $row['config_id'];
    sqlsrv_free_stmt($stmt);

    // 2. Insert arrival runways
    if (!empty($arr_runways)) {
        $runways = preg_split('/[,\/\s]+/', $arr_runways);
        $priority = 1;
        foreach ($runways as $rwy) {
            $rwy = strtoupper(trim($rwy));
            if (empty($rwy)) continue;

            $sql = "INSERT INTO dbo.airport_config_runway (config_id, runway_id, runway_use, priority) VALUES (?, ?, 'ARR', ?)";
            $stmt = sqlsrv_query($conn_adl, $sql, [$config_id, $rwy, $priority]);
            if ($stmt === false) {
                throw new Exception("Failed to insert arrival runway: " . adl_sql_error_message());
            }
            sqlsrv_free_stmt($stmt);
            $priority++;
        }
    }

    // 3. Insert departure runways
    if (!empty($dep_runways)) {
        $runways = preg_split('/[,\/\s]+/', $dep_runways);
        $priority = 1;
        foreach ($runways as $rwy) {
            $rwy = strtoupper(trim($rwy));
            if (empty($rwy)) continue;

            $sql = "INSERT INTO dbo.airport_config_runway (config_id, runway_id, runway_use, priority) VALUES (?, ?, 'DEP', ?)";
            $stmt = sqlsrv_query($conn_adl, $sql, [$config_id, $rwy, $priority]);
            if ($stmt === false) {
                throw new Exception("Failed to insert departure runway: " . adl_sql_error_message());
            }
            sqlsrv_free_stmt($stmt);
            $priority++;
        }
    }

    // 4. Insert VATSIM rates
    foreach ($vatsim_rates as $rate) {
        if ($rate['value'] !== null && $rate['value'] > 0) {
            $sql = "INSERT INTO dbo.airport_config_rate (config_id, source, weather, rate_type, rate_value) VALUES (?, 'VATSIM', ?, ?, ?)";
            $stmt = sqlsrv_query($conn_adl, $sql, [$config_id, $rate['weather'], $rate['type'], $rate['value']]);
            if ($stmt === false) {
                throw new Exception("Failed to insert VATSIM rate: " . adl_sql_error_message());
            }
            sqlsrv_free_stmt($stmt);
        }
    }

    // 5. Insert Real-World rates
    foreach ($rw_rates as $rate) {
        if ($rate['value'] !== null && $rate['value'] > 0) {
            $sql = "INSERT INTO dbo.airport_config_rate (config_id, source, weather, rate_type, rate_value) VALUES (?, 'RW', ?, ?, ?)";
            $stmt = sqlsrv_query($conn_adl, $sql, [$config_id, $rate['weather'], $rate['type'], $rate['value']]);
            if ($stmt === false) {
                throw new Exception("Failed to insert RW rate: " . adl_sql_error_message());
            }
            sqlsrv_free_stmt($stmt);
        }
    }

    // Commit transaction
    sqlsrv_commit($conn_adl);
    http_response_code(200);
    echo json_encode(['success' => true, 'config_id' => $config_id]);

} catch (Exception $e) {
    sqlsrv_rollback($conn_adl);
    http_response_code(500);
    error_log("ADL config insert failed: " . $e->getMessage());
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}

?>
