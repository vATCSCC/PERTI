<?php
/**
 * VATSWIM Splits - Preset Templates
 *
 * GET /api/swim/v1/splits/presets
 *
 * Returns reusable preset templates with their positions.
 *
 * Query params: ?facility=ZNY, ?strata=high|low|superhigh|all, ?format=json|xml
 *
 * @package PERTI\SWIM\Splits
 * @version 1.0.0
 */

require_once __DIR__ . '/common.php';

$auth = swim_init_auth(true);
$params = splits_parse_params();

$cache_params = ['endpoint' => 'splits_presets', 'facility' => $params['facility'], 'strata' => $params['strata']];
if (SwimResponse::tryCachedFormatted('splits_presets', $cache_params, $params['format'])) {
    exit;
}

$conn = $GLOBALS['conn_swim'] ?? null;
if (!$conn) {
    SwimResponse::error('SWIM database not available', 503, 'SERVICE_UNAVAILABLE');
}

$sql_params = [];
$where = 'WHERE 1=1';

if (!empty($params['facility'])) {
    $where .= ' AND p.artcc = ?';
    $sql_params[] = $params['facility'];
}

$sql = "SELECT p.id, p.preset_name, p.artcc, p.description, p.created_at, p.updated_at
        FROM dbo.splits_presets_swim p
        $where
        ORDER BY p.artcc, p.preset_name";

$stmt = sqlsrv_query($conn, $sql, $sql_params);
if ($stmt === false) {
    SwimResponse::error('Query failed: ' . json_encode(sqlsrv_errors()), 500, 'DB_ERROR');
}

$presets = [];
$preset_ids = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    foreach (['created_at', 'updated_at'] as $field) {
        if (isset($row[$field]) && $row[$field] instanceof DateTime) {
            $row[$field] = $row[$field]->format('Y-m-d\TH:i:s\Z');
        }
    }
    $presets[] = $row;
    $preset_ids[] = $row['id'];
}
sqlsrv_free_stmt($stmt);

// Load preset positions
if (!empty($preset_ids)) {
    $placeholders = implode(',', array_map('intval', $preset_ids));
    $sql = "SELECT id, preset_id, position_name, sectors, color, sort_order, frequency, strata_filter
            FROM dbo.splits_preset_positions_swim
            WHERE preset_id IN ($placeholders)
            ORDER BY preset_id, sort_order";

    $stmt = sqlsrv_query($conn, $sql);
    $positions_map = [];
    if ($stmt !== false) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            if (isset($row['sectors']) && is_string($row['sectors'])) {
                $row['sectors'] = json_decode($row['sectors'], true) ?? [];
            }
            if (isset($row['strata_filter']) && is_string($row['strata_filter'])) {
                $row['strata_filter'] = json_decode($row['strata_filter'], true);
            }
            // Apply strata filter
            if ($params['strata'] !== 'all' && $row['strata_filter'] !== null) {
                if (!($row['strata_filter'][$params['strata']] ?? true)) {
                    continue;
                }
            }
            $pid = $row['preset_id'];
            unset($row['preset_id']);
            $positions_map[$pid][] = $row;
        }
        sqlsrv_free_stmt($stmt);
    }

    foreach ($presets as &$preset) {
        $preset['positions'] = $positions_map[$preset['id']] ?? [];
    }
    unset($preset);
}

SwimResponse::formatted(
    ['presets' => $presets, 'count' => count($presets)],
    $params['format'],
    'splits_presets',
    $cache_params,
    ['root' => 'splits_response', 'item' => 'preset']
);
