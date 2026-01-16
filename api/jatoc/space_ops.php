<?php
/**
 * JATOC Space Operations API
 * Fetches space operations from en route initiatives for display in JATOC
 * GET: Public access - returns active and upcoming space operations
 */
header('Content-Type: application/json');

include("../../load/config.php");
include("../../load/connect.php");

if (!isset($conn_sqli) || $conn_sqli->connect_error) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed', 'data' => []]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    // Get space operations from en route initiatives
    // Look for items with Space_Op level or space-related TMI types
    // Include items from today and next 3 days
    $now = gmdate('Y-m-d H:i:s');
    $threeDaysOut = gmdate('Y-m-d H:i:s', strtotime('+3 days'));

    $sql = "SELECT
                id, p_id, facility, area, tmi_type, tmi_type_other, cause,
                start_datetime, end_datetime, level, notes, advzy_number
            FROM p_enroute_init_timeline
            WHERE (
                level = 'Space_Op'
                OR tmi_type IN ('Rocket Launch', 'Reentry', 'Launch Window', 'Hazard Area')
            )
            AND end_datetime >= ?
            AND start_datetime <= ?
            ORDER BY start_datetime ASC
            LIMIT 20";

    $stmt = $conn_sqli->prepare($sql);
    if (!$stmt) {
        throw new Exception('Query preparation failed');
    }

    $stmt->bind_param("ss", $now, $threeDaysOut);

    if (!$stmt->execute()) {
        throw new Exception('Query execution failed');
    }

    $result = $stmt->get_result();
    $spaceOps = [];

    while ($row = $result->fetch_assoc()) {
        // Format start time for display (dd/HHmm format)
        $startDt = new DateTime($row['start_datetime'], new DateTimeZone('UTC'));
        $timeStr = $startDt->format('d/Hi');

        // Determine status based on time
        $nowDt = new DateTime('now', new DateTimeZone('UTC'));
        $endDt = new DateTime($row['end_datetime'], new DateTimeZone('UTC'));

        $status = 'future';
        if ($nowDt >= $startDt && $nowDt <= $endDt) {
            $status = 'active';
        } elseif ($nowDt > $endDt) {
            $status = 'past';
        } elseif ($startDt->diff($nowDt)->h < 1 && $startDt > $nowDt) {
            $status = 'imminent';
        }

        // Build display name
        $name = $row['tmi_type'];
        if ($row['tmi_type_other']) {
            $name .= ' - ' . $row['tmi_type_other'];
        }
        if ($row['facility']) {
            $name .= ' (' . $row['facility'] . ')';
        }

        $spaceOps[] = [
            'id' => intval($row['id']),
            'time' => $timeStr,
            'name' => $name,
            'facility' => $row['facility'],
            'area' => $row['area'],
            'tmi_type' => $row['tmi_type'],
            'level' => $row['level'],
            'notes' => $row['notes'],
            'start_datetime' => $row['start_datetime'],
            'end_datetime' => $row['end_datetime'],
            'advzy_number' => $row['advzy_number'],
            'status' => $status
        ];
    }

    $stmt->close();

    echo json_encode([
        'success' => true,
        'data' => $spaceOps,
        'count' => count($spaceOps),
        'generated_at' => gmdate('Y-m-d\TH:i:s\Z')
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'data' => []
    ]);
}
