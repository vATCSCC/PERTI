<?php
/**
 * VATSWIM Splits - Sector Area Groupings
 *
 * GET /api/swim/v1/splits/areas
 *
 * Returns predefined sector area groupings (templates for quick position creation).
 *
 * Query params: ?facility=ZNY, ?format=json|xml
 *
 * @package PERTI\SWIM\Splits
 * @version 1.0.0
 */

require_once __DIR__ . '/common.php';

$auth = swim_init_auth(true);
$params = splits_parse_params();

$cache_params = ['endpoint' => 'splits_areas', 'facility' => $params['facility']];
if (SwimResponse::tryCachedFormatted('splits_areas', $cache_params, $params['format'])) {
    exit;
}

$conn = $GLOBALS['conn_swim'] ?? null;
if (!$conn) {
    SwimResponse::error('SWIM database not available', 503, 'SERVICE_UNAVAILABLE');
}

$sql_params = [];
$where = 'WHERE 1=1';

if (!empty($params['facility'])) {
    $where .= ' AND a.artcc = ?';
    $sql_params[] = $params['facility'];
}

$sql = "SELECT a.id, a.artcc, a.area_name, a.sectors, a.description, a.color,
               a.created_at, a.updated_at
        FROM dbo.splits_areas_swim a
        $where
        ORDER BY a.artcc, a.area_name";

$stmt = sqlsrv_query($conn, $sql, $sql_params);
if ($stmt === false) {
    SwimResponse::error('Query failed: ' . json_encode(sqlsrv_errors()), 500, 'DB_ERROR');
}

$areas = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    if (isset($row['sectors']) && is_string($row['sectors'])) {
        $row['sectors'] = json_decode($row['sectors'], true) ?? [];
    }
    foreach (['created_at', 'updated_at'] as $field) {
        if (isset($row[$field]) && $row[$field] instanceof DateTime) {
            $row[$field] = $row[$field]->format('Y-m-d\TH:i:s\Z');
        }
    }
    $areas[] = $row;
}
sqlsrv_free_stmt($stmt);

SwimResponse::formatted(
    ['areas' => $areas, 'count' => count($areas)],
    $params['format'],
    'splits_areas',
    $cache_params,
    ['root' => 'splits_response', 'item' => 'area']
);
