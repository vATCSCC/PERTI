<?php
/**
 * VATSWIM Splits - Active Configurations
 *
 * GET /api/swim/v1/splits/active
 *
 * Returns currently active split configurations across all (or filtered) facilities.
 * Reads from SWIM_API mirror tables only.
 *
 * Query params: ?facility=ZNY, ?strata=high|low|superhigh|all, ?format=json|xml
 *
 * @package PERTI\SWIM\Splits
 * @version 1.0.0
 */

require_once __DIR__ . '/common.php';

$auth = swim_init_auth(true);
$params = splits_parse_params();

$cache_params = ['endpoint' => 'splits_active', 'facility' => $params['facility'], 'strata' => $params['strata']];
if (SwimResponse::tryCachedFormatted('splits_active', $cache_params, $params['format'])) {
    exit;
}

$conn = $GLOBALS['conn_swim'] ?? null;
if (!$conn) {
    SwimResponse::error('SWIM database not available', 503, 'SERVICE_UNAVAILABLE');
}

// Build query
$sql_params = [];
$where = "WHERE c.status = 'active'";

if (!empty($params['facility'])) {
    $where .= ' AND c.artcc = ?';
    $sql_params[] = $params['facility'];
}

$where .= splits_strata_where($params['strata'], $sql_params);

$sql = "SELECT c.id, c.artcc, c.config_name, c.status, c.sector_type,
               c.start_time_utc, c.end_time_utc, c.[source], c.source_id,
               c.activated_at, c.created_at, c.updated_at
        FROM dbo.splits_configs_swim c
        $where
        ORDER BY c.artcc, c.config_name";

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

// Load positions for all configs
$positions_map = splits_load_positions($conn, $config_ids, $params['strata']);
foreach ($configs as &$config) {
    $config['positions'] = $positions_map[$config['id']] ?? [];
}
unset($config);

SwimResponse::formatted(
    ['splits' => $configs, 'count' => count($configs)],
    $params['format'],
    'splits_active',
    $cache_params,
    ['root' => 'splits_response', 'item' => 'split']
);
