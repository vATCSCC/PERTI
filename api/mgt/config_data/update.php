<?php

// api/mgt/config_data/update.php
// Updates an airport configuration in ADL SQL Server

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

// Check Perms (S)
if ($perm == true) {
    // Do Nothing
} else {
    http_response_code(403);
    exit();
}
// (E)

// Get form data - accept both 'id' and 'config_id' for backwards compatibility
$id = isset($_POST['config_id']) ? intval($_POST['config_id']) : (isset($_POST['id']) ? intval($_POST['id']) : 0);
$airport = isset($_POST['airport_faa']) ? strtoupper(trim(strip_tags($_POST['airport_faa']))) : (isset($_POST['airport']) ? strtoupper(trim(strip_tags($_POST['airport']))) : '');
$config_name = isset($_POST['config_name']) ? trim(strip_tags($_POST['config_name'])) : 'Default';
$config_code = isset($_POST['config_code']) ? trim(strip_tags($_POST['config_code'])) : null;
$arr_runways = isset($_POST['arr_runways']) ? trim(strip_tags($_POST['arr_runways'])) : (isset($_POST['arr']) ? trim(strip_tags($_POST['arr'])) : '');
$dep_runways = isset($_POST['dep_runways']) ? trim(strip_tags($_POST['dep_runways'])) : (isset($_POST['dep']) ? trim(strip_tags($_POST['dep'])) : '');

// Build ICAO from FAA
$airport_faa = $airport;
$airport_icao = (strlen($airport) == 3) ? 'K' . $airport : $airport;

// Get VATSIM rates
$vatsim_rates = [
    ['weather' => 'VMC', 'type' => 'ARR', 'value' => isset($_POST['vatsim_vmc_aar']) ? intval($_POST['vatsim_vmc_aar']) : null],
    ['weather' => 'LVMC', 'type' => 'ARR', 'value' => isset($_POST['vatsim_lvmc_aar']) ? intval($_POST['vatsim_lvmc_aar']) : null],
    ['weather' => 'IMC', 'type' => 'ARR', 'value' => isset($_POST['vatsim_imc_aar']) ? intval($_POST['vatsim_imc_aar']) : null],
    ['weather' => 'LIMC', 'type' => 'ARR', 'value' => isset($_POST['vatsim_limc_aar']) ? intval($_POST['vatsim_limc_aar']) : null],
    ['weather' => 'VLIMC', 'type' => 'ARR', 'value' => isset($_POST['vatsim_vlimc_aar']) ? intval($_POST['vatsim_vlimc_aar']) : null],
    ['weather' => 'VMC', 'type' => 'DEP', 'value' => isset($_POST['vatsim_vmc_adr']) ? intval($_POST['vatsim_vmc_adr']) : null],
    ['weather' => 'LVMC', 'type' => 'DEP', 'value' => isset($_POST['vatsim_lvmc_adr']) ? intval($_POST['vatsim_lvmc_adr']) : null],
    ['weather' => 'IMC', 'type' => 'DEP', 'value' => isset($_POST['vatsim_imc_adr']) ? intval($_POST['vatsim_imc_adr']) : null],
    ['weather' => 'LIMC', 'type' => 'DEP', 'value' => isset($_POST['vatsim_limc_adr']) ? intval($_POST['vatsim_limc_adr']) : null],
    ['weather' => 'VLIMC', 'type' => 'DEP', 'value' => isset($_POST['vatsim_vlimc_adr']) ? intval($_POST['vatsim_vlimc_adr']) : null],
];

// Get Real-World rates
$rw_rates = [
    ['weather' => 'VMC', 'type' => 'ARR', 'value' => isset($_POST['rw_vmc_aar']) ? intval($_POST['rw_vmc_aar']) : null],
    ['weather' => 'LVMC', 'type' => 'ARR', 'value' => isset($_POST['rw_lvmc_aar']) ? intval($_POST['rw_lvmc_aar']) : null],
    ['weather' => 'IMC', 'type' => 'ARR', 'value' => isset($_POST['rw_imc_aar']) ? intval($_POST['rw_imc_aar']) : null],
    ['weather' => 'LIMC', 'type' => 'ARR', 'value' => isset($_POST['rw_limc_aar']) ? intval($_POST['rw_limc_aar']) : null],
    ['weather' => 'VLIMC', 'type' => 'ARR', 'value' => isset($_POST['rw_vlimc_aar']) ? intval($_POST['rw_vlimc_aar']) : null],
    ['weather' => 'VMC', 'type' => 'DEP', 'value' => isset($_POST['rw_vmc_adr']) ? intval($_POST['rw_vmc_adr']) : null],
    ['weather' => 'LVMC', 'type' => 'DEP', 'value' => isset($_POST['rw_lvmc_adr']) ? intval($_POST['rw_lvmc_adr']) : null],
    ['weather' => 'IMC', 'type' => 'DEP', 'value' => isset($_POST['rw_imc_adr']) ? intval($_POST['rw_imc_adr']) : null],
    ['weather' => 'LIMC', 'type' => 'DEP', 'value' => isset($_POST['rw_limc_adr']) ? intval($_POST['rw_limc_adr']) : null],
    ['weather' => 'VLIMC', 'type' => 'DEP', 'value' => isset($_POST['rw_vlimc_adr']) ? intval($_POST['rw_vlimc_adr']) : null],
];

// Validate required fields
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Config ID is required']);
    exit();
}

// Check if ADL connection is available
if (!$conn_adl) {
    // Fallback to MySQL (legacy)
    $vmc_aar = $_POST['vatsim_vmc_aar'] ?? $_POST['vmc_aar'] ?? 0;
    $lvmc_aar = $_POST['vatsim_lvmc_aar'] ?? $_POST['lvmc_aar'] ?? 0;
    $imc_aar = $_POST['vatsim_imc_aar'] ?? $_POST['imc_aar'] ?? 0;
    $limc_aar = $_POST['vatsim_limc_aar'] ?? $_POST['limc_aar'] ?? 0;
    $vmc_adr = $_POST['vatsim_vmc_adr'] ?? $_POST['vmc_adr'] ?? 0;
    $imc_adr = $_POST['vatsim_imc_adr'] ?? $_POST['imc_adr'] ?? 0;

    $query = $conn_sqli->query("UPDATE config_data SET airport='$airport_faa', arr='$arr_runways', dep='$dep_runways', vmc_aar='$vmc_aar', lvmc_aar='$lvmc_aar', imc_aar='$imc_aar', limc_aar='$limc_aar', vmc_adr='$vmc_adr', imc_adr='$imc_adr' WHERE id=$id");

    if ($query) {
        http_response_code(200);
        echo json_encode(['success' => true]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Database error']);
    }
    exit();
}

// Use ADL SQL Server
$config_id = $id;

// Get user CID for history tracking
$changed_by_cid = isset($_SESSION['VATSIM_CID']) ? intval($_SESSION['VATSIM_CID']) : null;

// Fetch existing rates BEFORE updating for history comparison
$existing_rates = [];
$sql = "SELECT source, weather, rate_type, rate_value FROM dbo.airport_config_rate WHERE config_id = ?";
$stmt = sqlsrv_query($conn_adl, $sql, [$config_id]);
if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $key = $row['source'] . '_' . $row['weather'] . '_' . $row['rate_type'];
        $existing_rates[$key] = $row['rate_value'];
    }
    sqlsrv_free_stmt($stmt);
}

sqlsrv_begin_transaction($conn_adl);

try {
    // 1. Update airport_config
    $sql = "UPDATE dbo.airport_config SET
            airport_faa = ?,
            airport_icao = ?,
            config_name = ?,
            config_code = ?,
            updated_utc = GETUTCDATE()
            WHERE config_id = ?";
    $params = [$airport_faa, $airport_icao, $config_name, $config_code ?: null, $config_id];

    $stmt = sqlsrv_query($conn_adl, $sql, $params);
    if ($stmt === false) {
        throw new Exception("Failed to update config: " . adl_sql_error_message());
    }
    sqlsrv_free_stmt($stmt);

    // 2. Delete existing runways (will re-insert)
    $sql = "DELETE FROM dbo.airport_config_runway WHERE config_id = ?";
    $stmt = sqlsrv_query($conn_adl, $sql, [$config_id]);
    if ($stmt === false) {
        throw new Exception("Failed to delete runways: " . adl_sql_error_message());
    }
    sqlsrv_free_stmt($stmt);

    // 3. Insert arrival runways
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

    // 4. Insert departure runways
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

    // 5. Delete existing rates (will re-insert)
    $sql = "DELETE FROM dbo.airport_config_rate WHERE config_id = ?";
    $stmt = sqlsrv_query($conn_adl, $sql, [$config_id]);
    if ($stmt === false) {
        throw new Exception("Failed to delete rates: " . adl_sql_error_message());
    }
    sqlsrv_free_stmt($stmt);

    // 6. Insert VATSIM rates
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

    // 7. Insert Real-World rates
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

    // 8. Log rate changes to history (if history table exists)
    $historyTableExists = false;
    $checkTableSql = "SELECT 1 FROM sys.tables WHERE name = 'airport_config_rate_history'";
    $checkResult = sqlsrv_query($conn_adl, $checkTableSql);
    if ($checkResult && sqlsrv_fetch($checkResult)) {
        $historyTableExists = true;
    }

    if ($historyTableExists) {
        // Build new rates map
        $new_rates = [];
        foreach ($vatsim_rates as $rate) {
            if ($rate['value'] !== null && $rate['value'] > 0) {
                $key = 'VATSIM_' . $rate['weather'] . '_' . $rate['type'];
                $new_rates[$key] = $rate['value'];
            }
        }
        foreach ($rw_rates as $rate) {
            if ($rate['value'] !== null && $rate['value'] > 0) {
                $key = 'RW_' . $rate['weather'] . '_' . $rate['type'];
                $new_rates[$key] = $rate['value'];
            }
        }

        // Find changes and log them
        $all_keys = array_unique(array_merge(array_keys($existing_rates), array_keys($new_rates)));
        foreach ($all_keys as $key) {
            $parts = explode('_', $key, 3);
            if (count($parts) !== 3) continue;
            list($source, $weather, $rate_type) = $parts;

            $old_value = $existing_rates[$key] ?? null;
            $new_value = $new_rates[$key] ?? null;

            // Determine change type
            $change_type = null;
            if ($old_value === null && $new_value !== null) {
                $change_type = 'INSERT';
            } elseif ($old_value !== null && $new_value === null) {
                $change_type = 'DELETE';
            } elseif ($old_value !== null && $new_value !== null && $old_value != $new_value) {
                $change_type = 'UPDATE';
            }

            // Log the change
            if ($change_type !== null) {
                $histSql = "INSERT INTO dbo.airport_config_rate_history
                           (config_id, source, weather, rate_type, old_value, new_value, change_type, changed_by_cid)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                $histParams = [$config_id, $source, $weather, $rate_type, $old_value, $new_value, $change_type, $changed_by_cid];
                $histStmt = sqlsrv_query($conn_adl, $histSql, $histParams);
                if ($histStmt !== false) {
                    sqlsrv_free_stmt($histStmt);
                }
                // Don't fail the main update if history logging fails
            }
        }
    }

    // Commit transaction
    sqlsrv_commit($conn_adl);
    http_response_code(200);
    echo json_encode(['success' => true, 'config_id' => $config_id]);

} catch (Exception $e) {
    sqlsrv_rollback($conn_adl);
    http_response_code(500);
    error_log("ADL config update failed: " . $e->getMessage());
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}

?>
