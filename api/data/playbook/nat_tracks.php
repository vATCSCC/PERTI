<?php
/**
 * NAT Track Lookup API
 *
 * Returns active NAT (North Atlantic Track) definitions from CTP route templates.
 * Used by the route plotter to resolve NAT tokens (e.g., NATC) to route strings.
 *
 * GET /api/data/playbook/nat_tracks.php               - All active NAT tracks
 * GET /api/data/playbook/nat_tracks.php?session_id=X  - Session-specific tracks
 * GET /api/data/playbook/nat_tracks.php?name=NATC     - Single track by name
 *
 * @version 1.0.0
 */

include("../../../load/config.php");
include("../../../load/connect.php");

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : null;
$name       = isset($_GET['name']) ? strtoupper(trim($_GET['name'])) : null;

// Need TMI connection for ctp_route_templates
$conn_tmi = get_conn_tmi();
if (!$conn_tmi) {
    http_response_code(503);
    echo json_encode(['status' => 'error', 'message' => 'TMI database unavailable']);
    exit;
}

// Build query
$sql = "SELECT template_id, session_id, segment, template_name, route_string,
               altitude_range, priority, origin_filter, dest_filter,
               created_by, created_at, updated_at
        FROM dbo.ctp_route_templates
        WHERE segment = 'OCEANIC' AND is_active = 1";
$params = [];

if ($session_id !== null) {
    $sql .= " AND (session_id = ? OR session_id IS NULL)";
    $params[] = $session_id;
}

if ($name !== null) {
    // Normalize: NAT-C -> NATC, TRACK C -> NATC, NAT C -> NATC
    $normalized = preg_replace('/^(NAT|TRACK|NATA?)\s*-?\s*/i', 'NAT', $name);
    $normalized = strtoupper($normalized);

    $sql .= " AND (
        UPPER(REPLACE(REPLACE(template_name, '-', ''), ' ', '')) = ?
        OR UPPER(template_name) = ?
        OR UPPER(template_name) = ?
    )";
    $params[] = $normalized;
    $params[] = $name;
    // Also try with hyphen
    $with_hyphen = strlen($normalized) > 3 ? substr($normalized, 0, 3) . '-' . substr($normalized, 3) : $name;
    $params[] = $with_hyphen;
}

$sql .= " ORDER BY priority ASC, template_name ASC";

$stmt = sqlsrv_query($conn_tmi, $sql, $params);
if ($stmt === false) {
    http_response_code(500);
    $errors = sqlsrv_errors();
    echo json_encode(['status' => 'error', 'message' => 'Query failed', 'errors' => $errors]);
    exit;
}

$tracks = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    // Build aliases for this track name
    $base_name = $row['template_name'];
    $aliases = buildNATAliases($base_name);

    // Convert DateTimes
    foreach (['created_at', 'updated_at'] as $col) {
        if ($row[$col] instanceof DateTimeInterface) {
            $utc = clone $row[$col];
            $utc->setTimezone(new DateTimeZone('UTC'));
            $row[$col] = $utc->format('Y-m-d\TH:i:s') . 'Z';
        }
    }

    $tracks[] = [
        'template_id'    => (int)$row['template_id'],
        'session_id'     => $row['session_id'] !== null ? (int)$row['session_id'] : null,
        'name'           => $row['template_name'],
        'route_string'   => $row['route_string'],
        'altitude_range' => $row['altitude_range'],
        'priority'       => (int)$row['priority'],
        'aliases'        => $aliases,
        'origin_filter'  => $row['origin_filter'] ? json_decode($row['origin_filter'], true) : null,
        'dest_filter'    => $row['dest_filter'] ? json_decode($row['dest_filter'], true) : null,
        'created_by'     => $row['created_by'],
        'created_at'     => $row['created_at'],
        'updated_at'     => $row['updated_at'],
    ];
}
sqlsrv_free_stmt($stmt);

echo json_encode([
    'status' => 'success',
    'count'  => count($tracks),
    'tracks' => $tracks,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

// ============================================================================
// Helpers
// ============================================================================

/**
 * Build NAT track aliases from a template name.
 * e.g., "NAT-A" -> ["NAT-A", "NATA", "NAT A", "Track A"]
 */
function buildNATAliases($name) {
    $aliases = [$name];
    $upper = strtoupper($name);

    // Extract the letter(s) after NAT
    if (preg_match('/NAT[\s-]*([A-Z]+)/i', $upper, $m)) {
        $letter = $m[1];
        $aliases[] = 'NAT' . $letter;
        $aliases[] = 'NAT-' . $letter;
        $aliases[] = 'NAT ' . $letter;
        $aliases[] = 'TRACK ' . $letter;
        $aliases[] = 'TRACK' . $letter;
    }

    return array_values(array_unique($aliases));
}
