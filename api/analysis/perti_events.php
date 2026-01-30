<?php
/**
 * PERTI Events API
 *
 * Returns a list of PERTI events for TMI compliance data fetching.
 *
 * Endpoints:
 *   GET ?min_level=3&start_date=2026-01-01  - Get events with oplevel >= min_level
 */

header('Content-Type: application/json');

include("../../load/config.php");
include("../../load/connect.php");

$response = [
    'success' => true,
    'events' => [],
    'message' => ''
];

try {
    // Get parameters
    $min_level = isset($_GET['min_level']) ? intval($_GET['min_level']) : 3;
    $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '2026-01-01';

    // Query events with OpLevel >= min_level
    $query = "
        SELECT
            p.id,
            p.event_name,
            p.event_date,
            p.event_start,
            p.event_end_date,
            p.event_end_time,
            p.oplevel,
            p.hotline,
            GROUP_CONCAT(DISTINCT c.airport) as destinations
        FROM p_plans p
        LEFT JOIN p_configs c ON p.id = c.p_id
        WHERE p.oplevel >= ?
          AND p.event_date >= ?
        GROUP BY p.id, p.event_name, p.event_date, p.event_start,
                 p.event_end_date, p.event_end_time, p.oplevel, p.hotline
        ORDER BY p.event_date DESC
    ";

    $stmt = $mysqli->prepare($query);
    $stmt->bind_param('is', $min_level, $start_date);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $response['events'][] = [
            'id' => intval($row['id']),
            'event_name' => $row['event_name'],
            'event_date' => $row['event_date'],
            'event_start' => $row['event_start'],
            'event_end_date' => $row['event_end_date'],
            'event_end_time' => $row['event_end_time'],
            'oplevel' => intval($row['oplevel']),
            'hotline' => $row['hotline'],
            'destinations' => $row['destinations'] ? explode(',', $row['destinations']) : []
        ];
    }

    $stmt->close();

    $response['message'] = "Found " . count($response['events']) . " events with OpLevel >= $min_level since $start_date";

    echo json_encode($response, JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
