<?php
/**
 * VATSWIM API v1 - Controllers Endpoint
 *
 * Returns ATC controller data from the swim_controllers table in SWIM_API.
 * Data is populated by the vNAS controller poll daemon and enriched with
 * ERAM/STARS sector assignments.
 *
 * Supported formats: json, fixm, xml, geojson, csv, ndjson
 *
 * @version 1.0.0
 */

require_once __DIR__ . '/auth.php';

global $conn_swim;

if (!$conn_swim) {
    SwimResponse::error('SWIM database connection not available', 503, 'SERVICE_UNAVAILABLE');
}

$auth = swim_init_auth(true, false);

// Get format parameter
$format = swim_validate_format(swim_get_param('format', 'fixm'), 'controllers');
if ($format === 'legacy' || $format === 'json') {
    $format = 'fixm';
}

// Summary mode returns facility staffing aggregation
$summary = swim_get_param('summary');
if ($summary === 'true' || $summary === '1') {
    $sumSql = "SELECT facility, facility_type, controller_count, active_controllers, observers, vnas_enriched
               FROM dbo.vw_swim_facility_staffing
               ORDER BY controller_count DESC";
    $sumStmt = @sqlsrv_query($conn_swim, $sumSql);
    if ($sumStmt === false) {
        SwimResponse::error('Database error', 500, 'DB_ERROR');
    }

    $facilities = [];
    while ($row = sqlsrv_fetch_array($sumStmt, SQLSRV_FETCH_ASSOC)) {
        $facilities[] = [
            'facility'           => $row['facility'],
            'facility_type'      => $row['facility_type'],
            'controller_count'   => (int)$row['controller_count'],
            'active_controllers' => (int)$row['active_controllers'],
            'observers'          => (int)$row['observers'],
            'vnas_enriched'      => (int)$row['vnas_enriched'],
        ];
    }
    sqlsrv_free_stmt($sumStmt);

    SwimResponse::json([
        'success'   => true,
        'count'     => count($facilities),
        'data'      => $facilities,
        'timestamp' => gmdate('c'),
    ]);
    exit;
}

// Get filter parameters
$status = swim_get_param('status', 'active');
$callsign = swim_get_param('callsign');
$facility_type = swim_get_param('facility_type');
$facility_id = swim_get_param('facility_id');
$artcc = swim_get_param('artcc');
$rating = swim_get_param('rating');
$has_vnas = swim_get_param('has_vnas');

$page = swim_get_int_param('page', 1, 1, 1000);
$per_page = swim_get_int_param('per_page', 100, 1, 500);
$offset = ($page - 1) * $per_page;

// Build cache key
$cache_params = array_filter([
    'format'        => $format,
    'status'        => $status,
    'callsign'      => $callsign,
    'facility_type' => $facility_type,
    'facility_id'   => $facility_id,
    'artcc'         => $artcc,
    'rating'        => $rating,
    'has_vnas'      => $has_vnas,
    'page'          => $page,
    'per_page'      => $per_page,
], fn($v) => $v !== null && $v !== '');

$format_options = [
    'root'     => 'swim_controllers',
    'item'     => 'controller',
    'name'     => 'VATSWIM Controllers',
    'filename' => 'swim_controllers_' . date('Ymd_His'),
];

// Check cache first
if (SwimResponse::tryCachedFormatted('controllers_list', $cache_params, $format, $format_options)) {
    exit;
}

// Build query
$where_clauses = [];
$params = [];

if ($status === 'active') {
    $where_clauses[] = "c.is_active = 1";
} elseif ($status !== 'all') {
    $where_clauses[] = "c.is_active = 1";
}

if ($callsign) {
    $callsign_pattern = strtoupper(str_replace('*', '%', $callsign));
    $where_clauses[] = "c.callsign LIKE ?";
    $params[] = $callsign_pattern;
}

if ($facility_type) {
    $type_list = array_map('trim', explode(',', strtoupper($facility_type)));
    $placeholders = implode(',', array_fill(0, count($type_list), '?'));
    $where_clauses[] = "c.facility_type IN ($placeholders)";
    $params = array_merge($params, $type_list);
}

if ($facility_id) {
    $id_list = array_map('trim', explode(',', strtoupper($facility_id)));
    $placeholders = implode(',', array_fill(0, count($id_list), '?'));
    $where_clauses[] = "c.facility_id IN ($placeholders)";
    $params = array_merge($params, $id_list);
}

if ($artcc) {
    $artcc_list = array_map('trim', explode(',', strtoupper($artcc)));
    $placeholders = implode(',', array_fill(0, count($artcc_list), '?'));
    // Search both parsed facility_id (for CTR positions) and vnas_artcc_id
    $where_clauses[] = "(c.vnas_artcc_id IN ($placeholders) OR (c.facility_type = 'CTR' AND c.facility_id IN ($placeholders)))";
    $params = array_merge($params, $artcc_list, $artcc_list);
}

if ($rating) {
    $rating_list = array_map('intval', explode(',', $rating));
    $placeholders = implode(',', array_fill(0, count($rating_list), '?'));
    $where_clauses[] = "c.rating IN ($placeholders)";
    $params = array_merge($params, $rating_list);
}

if ($has_vnas === 'true' || $has_vnas === '1') {
    $where_clauses[] = "c.vnas_artcc_id IS NOT NULL";
}

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// Count total
$countSql = "SELECT COUNT(*) AS total FROM dbo.swim_controllers c $where_sql";
$countStmt = @sqlsrv_query($conn_swim, $countSql, $params);
$total = 0;
if ($countStmt) {
    $row = sqlsrv_fetch_array($countStmt, SQLSRV_FETCH_ASSOC);
    $total = (int)($row['total'] ?? 0);
    sqlsrv_free_stmt($countStmt);
}

// Fetch page
$dataSql = "SELECT
    c.cid, c.callsign, c.frequency, c.visual_range, c.rating,
    c.logon_utc, c.lat, c.lon,
    c.facility_type, c.facility_id,
    c.vnas_artcc_id, c.vnas_facility_id, c.vnas_position_id,
    c.vnas_position_name, c.vnas_position_type, c.vnas_radio_name,
    c.vnas_role, c.vnas_eram_sector_id, c.vnas_stars_sector_id,
    c.vnas_stars_area_id, c.is_observer,
    c.is_active, c.last_source, c.first_seen_utc, c.last_seen_utc, c.vnas_updated_utc
FROM dbo.swim_controllers c
$where_sql
ORDER BY c.callsign
OFFSET ? ROWS FETCH NEXT ? ROWS ONLY";

$dataParams = array_merge($params, [$offset, $per_page]);
$dataStmt = @sqlsrv_query($conn_swim, $dataSql, $dataParams);

if ($dataStmt === false) {
    $errors = sqlsrv_errors();
    SwimResponse::error('Database error: ' . ($errors[0]['message'] ?? 'unknown'), 500, 'DB_ERROR');
}

$controllers = [];
while ($row = sqlsrv_fetch_array($dataStmt, SQLSRV_FETCH_ASSOC)) {
    $controllers[] = formatControllerRecord($row);
}
sqlsrv_free_stmt($dataStmt);

SwimResponse::paginatedFormatted($controllers, $total, $page, $per_page, $format, 'controllers_list', $cache_params, $format_options);

/**
 * Format a controller record for API output.
 */
function formatControllerRecord(array $row): array {
    // Format DateTime objects to ISO 8601
    $logonUtc = $row['logon_utc'];
    if ($logonUtc instanceof \DateTime) $logonUtc = $logonUtc->format('Y-m-d\TH:i:s\Z');

    $firstSeen = $row['first_seen_utc'];
    if ($firstSeen instanceof \DateTime) $firstSeen = $firstSeen->format('Y-m-d\TH:i:s\Z');

    $lastSeen = $row['last_seen_utc'];
    if ($lastSeen instanceof \DateTime) $lastSeen = $lastSeen->format('Y-m-d\TH:i:s\Z');

    $vnasUpdated = $row['vnas_updated_utc'];
    if ($vnasUpdated instanceof \DateTime) $vnasUpdated = $vnasUpdated->format('Y-m-d\TH:i:s\Z');

    $record = [
        'cid'       => (int)$row['cid'],
        'callsign'  => $row['callsign'],
        'frequency' => $row['frequency'] !== null ? (float)$row['frequency'] : null,
        'facility'  => [
            'type' => $row['facility_type'],
            'id'   => $row['facility_id'],
        ],
        'position' => ($row['lat'] !== null && $row['lon'] !== null) ? [
            'latitude'  => (float)$row['lat'],
            'longitude' => (float)$row['lon'],
        ] : null,
        'rating'     => $row['rating'] !== null ? (int)$row['rating'] : null,
        'logon_time' => $logonUtc,
        'is_observer' => (bool)$row['is_observer'],
        'is_active'   => (bool)$row['is_active'],
    ];

    // vNAS enrichment (only present for vNAS-tracked controllers)
    if ($row['vnas_artcc_id'] !== null) {
        $record['vnas'] = [
            'artcc_id'        => $row['vnas_artcc_id'],
            'facility_id'     => $row['vnas_facility_id'],
            'position_name'   => $row['vnas_position_name'],
            'position_type'   => $row['vnas_position_type'],
            'radio_name'      => $row['vnas_radio_name'],
            'role'            => $row['vnas_role'],
            'eram_sector_id'  => $row['vnas_eram_sector_id'],
            'stars_sector_id' => $row['vnas_stars_sector_id'],
            'stars_area_id'   => $row['vnas_stars_area_id'],
            'updated'         => $vnasUpdated,
        ];
    }

    $record['last_source']  = $row['last_source'];
    $record['first_seen']   = $firstSeen;
    $record['last_updated'] = $lastSeen;

    return $record;
}
