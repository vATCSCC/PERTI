<?php
/**
 * API: List events missing AAR/ADR data
 */

header('Content-Type: application/json');

include("../../load/config.php");
include("../../load/connect.php");

if (!$conn_adl) {
    http_response_code(500);
    echo json_encode(['error' => 'ADL database connection not available']);
    exit;
}

$sql = "SELECT
            ea.event_idx,
            ea.airport_icao,
            e.event_name,
            e.event_type,
            CONVERT(VARCHAR(10), e.start_utc, 120) as start_date,
            e.duration_hours,
            ea.total_arrivals,
            ea.total_departures,
            ea.total_operations,
            (SELECT COUNT(*) FROM dbo.vatusa_event_hourly h
             WHERE h.event_idx = ea.event_idx AND h.airport_icao = ea.airport_icao) as hourly_count,
            e.source
        FROM dbo.vatusa_event_airport ea
        JOIN dbo.vatusa_event e ON ea.event_idx = e.event_idx
        WHERE ea.peak_vatsim_aar IS NULL
        ORDER BY e.start_utc DESC, ea.airport_icao";

$stmt = sqlsrv_query($conn_adl, $sql);

if ($stmt === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Query failed: ' . adl_sql_error_message()]);
    exit;
}

$events = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $events[] = $row;
}

sqlsrv_free_stmt($stmt);

echo json_encode([
    'success' => true,
    'count' => count($events),
    'events' => $events
]);
