<?php
/**
 * Quick script to get PERTI Plan IDs for OpLevel 3+ events
 */
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    include("../../load/config.php");
    include("../../load/connect.php");

    $query = "
        SELECT id, event_name, event_date, oplevel
        FROM p_plans
        WHERE oplevel >= 3 AND event_date >= '2026-01-01'
        ORDER BY event_date
    ";

    $result = $mysqli->query($query);
    if (!$result) {
        throw new Exception("Query failed: " . $mysqli->error);
    }

    $events = [];
    while ($row = $result->fetch_assoc()) {
        $events[] = $row;
    }

    echo json_encode(['success' => true, 'events' => $events], JSON_PRETTY_PRINT);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
