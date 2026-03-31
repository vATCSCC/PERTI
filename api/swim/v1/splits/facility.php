<?php
/**
 * VATSWIM Splits - Single Facility
 *
 * GET /api/swim/v1/splits/facility
 *
 * Returns active splits for a specific facility. Optionally includes scheduled configs.
 *
 * Required: ?facility=ZNY
 * Optional: ?include_scheduled=1, ?strata=high|low|superhigh|all, ?format=json|xml
 *
 * @package PERTI\SWIM\Splits
 * @version 1.0.0
 */

require_once __DIR__ . '/common.php';

$auth = swim_init_auth(true);
$params = splits_parse_params();

if (empty($params['facility'])) {
    SwimResponse::error('facility parameter is required (e.g., ?facility=ZNY)', 400, 'MISSING_FACILITY');
}

$include_scheduled = swim_get_param('include_scheduled', '0') === '1';

$cache_params = [
    'endpoint' => 'splits_facility',
    'facility' => $params['facility'],
    'strata' => $params['strata'],
    'scheduled' => $include_scheduled ? '1' : '0',
];
if (SwimResponse::tryCachedFormatted('splits_facility', $cache_params, $params['format'])) {
    exit;
}

$conn = $GLOBALS['conn_swim'] ?? null;
if (!$conn) {
    SwimResponse::error('SWIM database not available', 503, 'SERVICE_UNAVAILABLE');
}

// Build query
$sql_params = [$params['facility']];
$status_filter = $include_scheduled ? "c.status IN ('active', 'scheduled')" : "c.status = 'active'";
$where = "WHERE $status_filter AND c.artcc = ?";
$where .= splits_strata_where($params['strata'], $sql_params);

$sql = "SELECT c.id, c.artcc, c.config_name, c.status, c.sector_type,
               c.start_time_utc, c.end_time_utc, c.[source], c.source_id,
               c.activated_at, c.created_at, c.updated_at
        FROM dbo.splits_configs_swim c
        $where
        ORDER BY c.status, c.start_time_utc";

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
    ['facility' => $params['facility'], 'splits' => $configs, 'count' => count($configs)],
    $params['format'],
    'splits_facility',
    $cache_params,
    ['root' => 'splits_response', 'item' => 'split']
);
