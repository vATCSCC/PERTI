<?php
/**
 * VATSWIM API v1 - Flow Providers Endpoint
 *
 * Returns registered external flow management providers.
 * Provider-agnostic design supports ECFMP, NavCanada, VATPAC, and future integrations.
 *
 * GET /api/swim/v1/tmi/flow/providers
 * GET /api/swim/v1/tmi/flow/providers?active_only=true
 * GET /api/swim/v1/tmi/flow/providers?region=EUR,NAT
 *
 * @version 1.0.0
 */

require_once __DIR__ . '/../../auth.php';

global $conn_tmi;

if (!$conn_tmi) {
    SwimResponse::error('TMI database connection not available', 503, 'SERVICE_UNAVAILABLE');
}

$auth = swim_init_auth(false, false);  // Auth optional for read

// Get filter parameters
$active_only = swim_get_param('active_only', 'true') === 'true';
$region = swim_get_param('region');  // Filter by region code (EUR, NAM, etc.)
$provider_code = swim_get_param('provider');  // Specific provider code

// Build query
$where_clauses = [];
$params = [];

if ($active_only) {
    $where_clauses[] = "is_active = 1";
}

if ($provider_code) {
    $provider_list = array_map('trim', explode(',', strtoupper($provider_code)));
    $placeholders = implode(',', array_fill(0, count($provider_list), '?'));
    $where_clauses[] = "provider_code IN ($placeholders)";
    $params = array_merge($params, $provider_list);
}

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

$sql = "
    SELECT
        provider_id,
        provider_guid,
        provider_code,
        provider_name,
        api_base_url,
        api_version,
        auth_type,
        region_codes_json,
        fir_codes_json,
        sync_interval_sec,
        sync_enabled,
        last_sync_utc,
        last_sync_status,
        is_active,
        priority,
        created_at,
        updated_at
    FROM dbo.tmi_flow_providers
    $where_sql
    ORDER BY priority ASC, provider_name ASC
";

$stmt = sqlsrv_query($conn_tmi, $sql, $params);
if ($stmt === false) {
    $errors = sqlsrv_errors();
    SwimResponse::error('Database error: ' . ($errors[0]['message'] ?? 'Unknown'), 500, 'DB_ERROR');
}

$providers = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    // Parse JSON fields
    $region_codes = json_decode($row['region_codes_json'] ?? '[]', true) ?: [];
    $fir_codes = json_decode($row['fir_codes_json'] ?? '[]', true) ?: [];

    // Filter by region if specified
    if ($region) {
        $region_filter = array_map('trim', explode(',', strtoupper($region)));
        $has_match = !empty(array_intersect($region_filter, $region_codes));
        if (!$has_match) continue;
    }

    $providers[] = [
        'id' => $row['provider_id'],
        'guid' => $row['provider_guid'],
        'code' => $row['provider_code'],
        'name' => $row['provider_name'],

        // Integration
        'api' => [
            'base_url' => $row['api_base_url'],
            'version' => $row['api_version'],
            'auth_type' => $row['auth_type']
        ],

        // Coverage (FIXM: flightInformationRegion)
        'coverage' => [
            'regions' => $region_codes,
            'firs' => $fir_codes
        ],

        // Sync status
        'sync' => [
            'enabled' => (bool)$row['sync_enabled'],
            'interval_sec' => $row['sync_interval_sec'],
            'last_sync_utc' => formatDT($row['last_sync_utc']),
            'last_status' => $row['last_sync_status']
        ],

        'is_active' => (bool)$row['is_active'],
        'priority' => $row['priority'],

        '_created_at' => formatDT($row['created_at']),
        '_updated_at' => formatDT($row['updated_at'])
    ];
}
sqlsrv_free_stmt($stmt);

$response = [
    'providers' => $providers,
    'count' => count($providers)
];

SwimResponse::success($response, [
    'source' => 'vatsim_tmi',
    'table' => 'tmi_flow_providers',
    'filters' => [
        'active_only' => $active_only,
        'region' => $region,
        'provider' => $provider_code
    ]
]);

function formatDT($dt) {
    if ($dt === null) return null;
    return ($dt instanceof DateTime) ? $dt->format('c') : $dt;
}
