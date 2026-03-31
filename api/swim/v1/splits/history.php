<?php
/**
 * VATSWIM Splits - State Transition History
 *
 * GET /api/swim/v1/splits/history
 *
 * Returns recent split state transitions (activated, deactivated, modified, ingested).
 * Reads from the append-only splits_history_swim audit log.
 *
 * Query params: ?facility=ZNY, ?since=ISO8601, ?limit=50 (max 500), ?format=json|xml
 *
 * @package PERTI\SWIM\Splits
 * @version 1.0.0
 */

require_once __DIR__ . '/common.php';

$auth = swim_init_auth(true);
$params = splits_parse_params();
$since = swim_get_param('since', '');
$limit = swim_get_int_param('limit', 50, 1, 500);

$cache_params = [
    'endpoint' => 'splits_history',
    'facility' => $params['facility'],
    'since' => $since,
    'limit' => $limit,
];
if (SwimResponse::tryCachedFormatted('splits_history', $cache_params, $params['format'])) {
    exit;
}

$conn = $GLOBALS['conn_swim'] ?? null;
if (!$conn) {
    SwimResponse::error('SWIM database not available', 503, 'SERVICE_UNAVAILABLE');
}

$sql_params = [];
$where = 'WHERE 1=1';

if (!empty($params['facility'])) {
    $where .= ' AND h.facility = ?';
    $sql_params[] = $params['facility'];
}

if (!empty($since)) {
    $parsed = str_replace('T', ' ', rtrim($since, 'Z'));
    $where .= ' AND h.event_at >= ?';
    $sql_params[] = $parsed;
}

$sql = "SELECT TOP ($limit) h.id, h.config_id, h.facility, h.event_type,
               h.config_snapshot, h.[source], h.event_at, h.synced_utc
        FROM dbo.splits_history_swim h
        $where
        ORDER BY h.event_at DESC";

$stmt = sqlsrv_query($conn, $sql, $sql_params);
if ($stmt === false) {
    SwimResponse::error('Query failed: ' . json_encode(sqlsrv_errors()), 500, 'DB_ERROR');
}

$events = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    if (isset($row['config_snapshot']) && is_string($row['config_snapshot'])) {
        $row['config_snapshot'] = json_decode($row['config_snapshot'], true);
    }
    foreach (['event_at', 'synced_utc'] as $field) {
        if (isset($row[$field]) && $row[$field] instanceof DateTime) {
            $row[$field] = $row[$field]->format('Y-m-d\TH:i:s\Z');
        }
    }
    $events[] = $row;
}
sqlsrv_free_stmt($stmt);

SwimResponse::formatted(
    ['events' => $events, 'count' => count($events)],
    $params['format'],
    'splits_history',
    $cache_params,
    ['root' => 'splits_response', 'item' => 'event']
);
