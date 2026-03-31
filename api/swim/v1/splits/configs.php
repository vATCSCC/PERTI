<?php
/**
 * VATSWIM Splits - All Saved Configurations
 *
 * GET /api/swim/v1/splits/configs
 *
 * Returns all saved configurations (any status: draft, scheduled, active, inactive).
 * Archived configs are excluded from the SWIM mirror.
 *
 * Query params: ?facility=ZNY, ?status=active, ?strata=high|low|superhigh|all, ?format=json|xml
 *
 * @package PERTI\SWIM\Splits
 * @version 1.0.0
 */

require_once __DIR__ . '/common.php';

$auth = swim_init_auth(true);
$params = splits_parse_params();
$status = strtolower(trim(swim_get_param('status', '')));

$cache_params = [
    'endpoint' => 'splits_configs',
    'facility' => $params['facility'],
    'strata' => $params['strata'],
    'status' => $status,
];
if (SwimResponse::tryCachedFormatted('splits_configs', $cache_params, $params['format'])) {
    exit;
}

$conn = $GLOBALS['conn_swim'] ?? null;
if (!$conn) {
    SwimResponse::error('SWIM database not available', 503, 'SERVICE_UNAVAILABLE');
}

$sql_params = [];
$where = 'WHERE 1=1';

if (!empty($params['facility'])) {
    $where .= ' AND c.artcc = ?';
    $sql_params[] = $params['facility'];
}

if (!empty($status) && in_array($status, ['draft', 'scheduled', 'active', 'inactive'])) {
    $where .= ' AND c.status = ?';
    $sql_params[] = $status;
}

$where .= splits_strata_where($params['strata'], $sql_params);

$sql = "SELECT c.id, c.artcc, c.config_name, c.status, c.sector_type,
               c.start_time_utc, c.end_time_utc, c.[source], c.source_id,
               c.created_by, c.activated_at, c.created_at, c.updated_at
        FROM dbo.splits_configs_swim c
        $where
        ORDER BY c.updated_at DESC";

$stmt = sqlsrv_query($conn, $sql, $sql_params);
if ($stmt === false) {
    SwimResponse::error('Query failed: ' . json_encode(sqlsrv_errors()), 500, 'DB_ERROR');
}

$configs = [];
$config_ids = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $configs[] = splits_format_config($row);
    $config_ids[] = $row['id'];
}
sqlsrv_free_stmt($stmt);

$positions_map = splits_load_positions($conn, $config_ids, $params['strata']);
foreach ($configs as &$config) {
    $config['positions'] = $positions_map[$config['id']] ?? [];
}
unset($config);

SwimResponse::formatted(
    ['configs' => $configs, 'count' => count($configs)],
    $params['format'],
    'splits_configs',
    $cache_params,
    ['root' => 'splits_response', 'item' => 'config']
);
