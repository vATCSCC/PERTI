<?php
/**
 * SUA Activations API Endpoint
 *
 * Returns SUA/TFR activations from the database.
 *
 * Parameters:
 * - status: Filter by status (comma-separated: SCHEDULED,ACTIVE,EXPIRED,CANCELLED)
 * - type: Filter by sua_type
 * - artcc: Filter by ARTCC
 * - include_geometry: Include geometry for TFRs (default: false)
 * - active_only: Only return currently active (start <= now <= end)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: max-age=60'); // Cache for 1 minute

include(__DIR__ . "/../../../load/config.php");
include(__DIR__ . "/../../../load/connect.php");

// Check ADL connection
if (!$conn_adl) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Database connection not available',
        'data' => []
    ]);
    exit;
}

// Build query with parameterized queries for security
$where = [];
$params = [];

// Status filter (using parameterized IN clause)
if (has_get('status')) {
    $statusInput = get_upper('status');
    $statuses = array_map('trim', explode(',', $statusInput));
    $validStatuses = array_filter($statuses, fn($s) => in_array($s, ['SCHEDULED', 'ACTIVE', 'EXPIRED', 'CANCELLED']));
    if (!empty($validStatuses)) {
        $placeholders = implode(',', array_fill(0, count($validStatuses), '?'));
        $where[] = "status IN ($placeholders)";
        $params = array_merge($params, $validStatuses);
    }
}

// Type filter (parameterized)
if (has_get('type')) {
    $where[] = "sua_type = ?";
    $params[] = get_upper('type');
}

// ARTCC filter (parameterized)
if (has_get('artcc')) {
    $where[] = "artcc = ?";
    $params[] = get_upper('artcc');
}

// Active only filter (currently within time window)
if (get_lower('active_only') === 'true') {
    $where[] = "start_utc <= GETUTCDATE() AND end_utc >= GETUTCDATE()";
}

// Build SQL
$sql = "SELECT id, sua_id, sua_type, tfr_subtype, name, artcc,
               start_utc, end_utc, status, lower_alt, upper_alt,
               remarks, notam_number, created_by, created_at, updated_at";

// Include geometry if requested
if (get_lower('include_geometry') === 'true') {
    $sql .= ", geometry";
}

$sql .= " FROM sua_activations";

if (!empty($where)) {
    $sql .= " WHERE " . implode(' AND ', $where);
}

$sql .= " ORDER BY start_utc ASC";

$stmt = sqlsrv_query($conn_adl, $sql, $params);

if ($stmt === false) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database query failed'
    ]);
    exit;
}

$results = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    // Format DateTime objects
    if (isset($row['start_utc']) && $row['start_utc'] instanceof DateTime) {
        $row['start_utc'] = $row['start_utc']->format('Y-m-d\TH:i:s\Z');
    }
    if (isset($row['end_utc']) && $row['end_utc'] instanceof DateTime) {
        $row['end_utc'] = $row['end_utc']->format('Y-m-d\TH:i:s\Z');
    }
    if (isset($row['created_at']) && $row['created_at'] instanceof DateTime) {
        $row['created_at'] = $row['created_at']->format('Y-m-d\TH:i:s\Z');
    }
    if (isset($row['updated_at']) && $row['updated_at'] instanceof DateTime) {
        $row['updated_at'] = $row['updated_at']->format('Y-m-d\TH:i:s\Z');
    }

    // Parse geometry JSON if present
    if (isset($row['geometry']) && $row['geometry']) {
        $row['geometry'] = json_decode($row['geometry'], true);
    }

    $results[] = $row;
}

sqlsrv_free_stmt($stmt);

echo json_encode([
    'status' => 'ok',
    'count' => count($results),
    'data' => $results
]);
