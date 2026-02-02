<?php
/**
 * PERTI Events List API Endpoint
 *
 * GET /api/events/list - List upcoming PERTI events
 *
 * Query params:
 *   source   - Filter by source: 'VATUSA', 'VATCAN', 'VATSIM' (optional)
 *   division - Filter by division code (optional, searches divisions field)
 *   type     - Filter by event type: 'FNO', 'SNO', 'CTP', etc. (optional)
 *   days     - Number of days ahead to include (default: 30, max: 90)
 *   limit    - Max results (default: 100, max: 500)
 *   include_past - Include past events (default: false)
 *   logging_only - Only events with logging enabled (default: false)
 *
 * @package PERTI\API\Events
 * @version 2.0.0
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../../load/config.php';
require_once __DIR__ . '/../../load/connect.php';

try {
    global $conn_adl;
    if (!$conn_adl) {
        throw new Exception('ADL database not available');
    }

    // Parse parameters
    $source = isset($_GET['source']) ? strtoupper($_GET['source']) : null;
    $division = isset($_GET['division']) ? strtoupper($_GET['division']) : null;
    $eventType = isset($_GET['type']) ? strtoupper($_GET['type']) : null;
    $days = min(90, max(1, intval($_GET['days'] ?? 30)));
    $limit = min(500, max(1, intval($_GET['limit'] ?? 100)));
    $includePast = ($_GET['include_past'] ?? 'false') === 'true';
    $loggingOnly = ($_GET['logging_only'] ?? 'false') === 'true';

    // Build query
    $where = [];
    $params = [];

    if (!$includePast) {
        $where[] = "end_utc > SYSUTCDATETIME()";
    }

    $where[] = "start_utc <= DATEADD(day, ?, SYSUTCDATETIME())";
    $params[] = $days;

    if ($source && in_array($source, ['VATUSA', 'VATCAN', 'VATSIM', 'MANUAL'])) {
        $where[] = "source = ?";
        $params[] = $source;
    }

    if ($division) {
        // Search in comma-separated divisions field
        $where[] = "(divisions LIKE ? OR divisions LIKE ? OR divisions LIKE ? OR divisions = ?)";
        $params[] = "$division,%";  // Start
        $params[] = "%,$division,%"; // Middle
        $params[] = "%,$division";  // End
        $params[] = $division;      // Exact
    }

    if ($eventType) {
        $where[] = "event_type = ?";
        $params[] = $eventType;
    }

    if ($loggingOnly) {
        $where[] = "logging_enabled = 1";
    }

    $whereSql = implode(' AND ', $where);

    $sql = "
        SELECT TOP (?)
            event_id,
            event_name,
            event_type,
            start_utc,
            end_utc,
            logging_start_utc,
            logging_end_utc,
            divisions,
            featured_airports,
            source,
            external_id,
            external_url,
            banner_url,
            status,
            logging_enabled,
            positions_logged,
            description,
            synced_utc
        FROM dbo.perti_events
        WHERE $whereSql
        ORDER BY start_utc ASC
    ";

    $stmt = $conn_adl->prepare($sql);
    $stmt->execute(array_merge([$limit], $params));

    $events = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Parse JSON fields
        $airports = $row['featured_airports'] ? json_decode($row['featured_airports'], true) : [];

        // Parse divisions (comma-separated to array)
        $divisionsList = $row['divisions'] ? explode(',', $row['divisions']) : [];

        $events[] = [
            'id' => (int)$row['event_id'],
            'name' => $row['event_name'],
            'type' => $row['event_type'],
            'start' => $row['start_utc'] instanceof DateTime
                ? $row['start_utc']->format('Y-m-d\TH:i:s\Z')
                : $row['start_utc'],
            'end' => $row['end_utc'] instanceof DateTime
                ? $row['end_utc']->format('Y-m-d\TH:i:s\Z')
                : $row['end_utc'],
            'logging_window' => [
                'start' => $row['logging_start_utc'] instanceof DateTime
                    ? $row['logging_start_utc']->format('Y-m-d\TH:i:s\Z')
                    : $row['logging_start_utc'],
                'end' => $row['logging_end_utc'] instanceof DateTime
                    ? $row['logging_end_utc']->format('Y-m-d\TH:i:s\Z')
                    : $row['logging_end_utc'],
            ],
            'divisions' => $divisionsList,
            'airports' => $airports,
            'source' => $row['source'],
            'external_id' => $row['external_id'],
            'link' => $row['external_url'],
            'banner' => $row['banner_url'],
            'status' => $row['status'],
            'logging_enabled' => (bool)$row['logging_enabled'],
            'positions_logged' => (int)$row['positions_logged'],
            'description' => $row['description'],
            'synced_at' => $row['synced_utc'] instanceof DateTime
                ? $row['synced_utc']->format('Y-m-d\TH:i:s\Z')
                : $row['synced_utc'],
        ];
    }

    // Group by source for stats
    $bySource = [];
    $byType = [];
    foreach ($events as $e) {
        $src = $e['source'];
        $bySource[$src] = ($bySource[$src] ?? 0) + 1;
        $typ = $e['type'];
        $byType[$typ] = ($byType[$typ] ?? 0) + 1;
    }

    echo json_encode([
        'success' => true,
        'count' => count($events),
        'by_source' => $bySource,
        'by_type' => $byType,
        'filters' => [
            'source' => $source,
            'division' => $division,
            'type' => $eventType,
            'days' => $days,
            'include_past' => $includePast,
            'logging_only' => $loggingOnly,
        ],
        'events' => $events,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
