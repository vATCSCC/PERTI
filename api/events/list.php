<?php
/**
 * Division Events List API Endpoint
 *
 * GET /api/events/list - List upcoming division events
 *
 * Query params:
 *   source   - Filter by source: 'VATUSA', 'VATCAN', 'VATSIM' (optional)
 *   division - Filter by division code (optional)
 *   days     - Number of days ahead to include (default: 30, max: 90)
 *   limit    - Max results (default: 100, max: 500)
 *   include_past - Include past events (default: false)
 *
 * @package PERTI\API\Events
 * @version 1.0.0
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
    $days = min(90, max(1, intval($_GET['days'] ?? 30)));
    $limit = min(500, max(1, intval($_GET['limit'] ?? 100)));
    $includePast = ($_GET['include_past'] ?? 'false') === 'true';

    // Build query
    $where = [];
    $params = [];

    if (!$includePast) {
        $where[] = "(end_utc IS NULL OR end_utc > SYSUTCDATETIME())";
    }

    $where[] = "start_utc <= DATEADD(day, ?, SYSUTCDATETIME())";
    $params[] = $days;

    if ($source && in_array($source, ['VATUSA', 'VATCAN', 'VATSIM'])) {
        $where[] = "source = ?";
        $params[] = $source;
    }

    if ($division) {
        $where[] = "division = ?";
        $params[] = $division;
    }

    $whereSql = implode(' AND ', $where);

    $sql = "
        SELECT TOP (?)
            event_id,
            source,
            external_id,
            event_name,
            event_type,
            event_link,
            banner_url,
            start_utc,
            end_utc,
            division,
            region,
            airports_json,
            routes_json,
            short_description,
            synced_at
        FROM dbo.division_events
        WHERE $whereSql
        ORDER BY start_utc ASC
    ";

    $stmt = $conn_adl->prepare($sql);
    $stmt->execute(array_merge([$limit], $params));

    $events = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Parse JSON fields
        $airports = $row['airports_json'] ? json_decode($row['airports_json'], true) : [];
        $routes = $row['routes_json'] ? json_decode($row['routes_json'], true) : [];

        $events[] = [
            'id' => (int)$row['event_id'],
            'source' => $row['source'],
            'external_id' => $row['external_id'],
            'name' => $row['event_name'],
            'type' => $row['event_type'],
            'link' => $row['event_link'],
            'banner' => $row['banner_url'],
            'start' => $row['start_utc'] instanceof DateTime
                ? $row['start_utc']->format('Y-m-d\TH:i:s\Z')
                : $row['start_utc'],
            'end' => $row['end_utc'] instanceof DateTime
                ? $row['end_utc']->format('Y-m-d\TH:i:s\Z')
                : $row['end_utc'],
            'division' => $row['division'],
            'region' => $row['region'],
            'airports' => $airports,
            'routes' => $routes,
            'short_description' => $row['short_description'],
            'synced_at' => $row['synced_at'] instanceof DateTime
                ? $row['synced_at']->format('Y-m-d\TH:i:s\Z')
                : $row['synced_at'],
        ];
    }

    // Group by source for stats
    $bySource = [];
    foreach ($events as $e) {
        $src = $e['source'];
        $bySource[$src] = ($bySource[$src] ?? 0) + 1;
    }

    echo json_encode([
        'success' => true,
        'count' => count($events),
        'by_source' => $bySource,
        'filters' => [
            'source' => $source,
            'division' => $division,
            'days' => $days,
            'include_past' => $includePast,
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
