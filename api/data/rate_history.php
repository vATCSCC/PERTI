<?php

// api/data/rate_history.php
// Returns rate change history for a config or recent changes

if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

include("../../load/config.php");
include("../../load/connect.php");

header('Content-Type: application/json');

// Check if ADL connection is available
if (!$conn_adl) {
    echo json_encode(['error' => 'ADL database not available', 'history' => []]);
    exit();
}

$configId = isset($_GET['config_id']) ? intval($_GET['config_id']) : 0;
$days = isset($_GET['days']) ? intval($_GET['days']) : 30;
$limit = isset($_GET['limit']) ? min(100, intval($_GET['limit'])) : 50;

if ($configId > 0) {
    // Get history for specific config
    $sql = "
        SELECT
            h.history_id,
            h.source,
            h.weather,
            h.rate_type,
            h.old_value,
            h.new_value,
            h.change_type,
            h.changed_by_cid,
            h.changed_utc,
            h.notes
        FROM dbo.airport_config_rate_history h
        WHERE h.config_id = ?
          AND h.changed_utc >= DATEADD(DAY, -?, GETUTCDATE())
        ORDER BY h.changed_utc DESC
    ";
    $params = [$configId, $days];
} else {
    // Get recent changes across all configs
    $sql = "
        SELECT TOP (?)
            h.history_id,
            h.config_id,
            c.airport_faa,
            c.airport_icao,
            c.config_name,
            h.source,
            h.weather,
            h.rate_type,
            h.old_value,
            h.new_value,
            h.change_type,
            h.changed_by_cid,
            h.changed_utc,
            h.notes
        FROM dbo.airport_config_rate_history h
        JOIN dbo.airport_config c ON h.config_id = c.config_id
        ORDER BY h.changed_utc DESC
    ";
    $params = [$limit];
}

$stmt = sqlsrv_query($conn_adl, $sql, $params);

if ($stmt === false) {
    echo json_encode(['error' => adl_sql_error_message(), 'history' => []]);
    exit();
}

$history = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    // Convert DateTime to string
    if ($row['changed_utc'] instanceof DateTime) {
        $row['changed_utc'] = $row['changed_utc']->format('Y-m-d H:i:s');
    }

    // Add change direction
    if ($row['old_value'] === null) {
        $row['direction'] = 'NEW';
    } elseif ($row['new_value'] === null) {
        $row['direction'] = 'REMOVED';
    } elseif ($row['new_value'] > $row['old_value']) {
        $row['direction'] = 'INCREASED';
    } elseif ($row['new_value'] < $row['old_value']) {
        $row['direction'] = 'DECREASED';
    } else {
        $row['direction'] = 'UNCHANGED';
    }

    $history[] = $row;
}

sqlsrv_free_stmt($stmt);

echo json_encode([
    'success' => true,
    'count' => count($history),
    'config_id' => $configId,
    'days' => $days,
    'history' => $history
]);

?>
