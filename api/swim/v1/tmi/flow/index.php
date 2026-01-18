<?php
/**
 * VATSWIM API v1 - External Flow Management Index
 *
 * Overview of external flow management integration endpoints.
 * Supports multiple providers: ECFMP, NavCanada, VATPAC, etc.
 *
 * GET /api/swim/v1/tmi/flow/
 *
 * @version 1.0.0
 */

require_once __DIR__ . '/../../auth.php';

global $conn_tmi;

$auth = swim_init_auth(false, false);  // No auth required for index

// Get active counts if TMI connection available
$counts = [
    'active_providers' => 0,
    'active_events' => 0,
    'active_measures' => 0,
    'total_participants' => 0
];

if ($conn_tmi) {
    // Count active providers
    $sql = "SELECT COUNT(*) as cnt FROM dbo.tmi_flow_providers WHERE is_active = 1";
    $stmt = sqlsrv_query($conn_tmi, $sql);
    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $counts['active_providers'] = $row['cnt'];
    }
    if ($stmt) sqlsrv_free_stmt($stmt);

    // Count active events
    $sql = "SELECT COUNT(*) as cnt FROM dbo.vw_tmi_active_flow_events";
    $stmt = sqlsrv_query($conn_tmi, $sql);
    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $counts['active_events'] = $row['cnt'];
    }
    if ($stmt) sqlsrv_free_stmt($stmt);

    // Count active measures
    $sql = "SELECT COUNT(*) as cnt FROM dbo.vw_tmi_active_flow_measures";
    $stmt = sqlsrv_query($conn_tmi, $sql);
    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $counts['active_measures'] = $row['cnt'];
    }
    if ($stmt) sqlsrv_free_stmt($stmt);

    // Count participants in active events
    $sql = "SELECT COUNT(*) as cnt FROM dbo.tmi_flow_event_participants p
            JOIN dbo.vw_tmi_active_flow_events e ON p.event_id = e.event_id";
    $stmt = sqlsrv_query($conn_tmi, $sql);
    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $counts['total_participants'] = $row['cnt'];
    }
    if ($stmt) sqlsrv_free_stmt($stmt);
}

$response = [
    'success' => true,
    'api' => [
        'name' => 'VATSWIM External Flow Management API',
        'version' => '1.0.0',
        'description' => 'Provider-agnostic integration for external flow management systems (ECFMP, NavCanada, VATPAC, etc.)'
    ],
    'endpoints' => [
        [
            'path' => '/api/swim/v1/tmi/flow/providers',
            'methods' => ['GET'],
            'description' => 'List registered flow management providers',
            'auth' => 'optional'
        ],
        [
            'path' => '/api/swim/v1/tmi/flow/events',
            'methods' => ['GET'],
            'description' => 'Active special events (CTP, FNO, etc.) with participant data',
            'auth' => 'optional'
        ],
        [
            'path' => '/api/swim/v1/tmi/flow/measures',
            'methods' => ['GET'],
            'description' => 'Active flow measures (MIT, MINIT, MDI, etc.) from external providers',
            'auth' => 'optional'
        ]
    ],
    'providers' => [
        [
            'code' => 'VATCSCC',
            'name' => 'VATSIM Command Center (USA)',
            'description' => 'Authoritative source for USA TMI data'
        ],
        [
            'code' => 'ECFMP',
            'name' => 'EUROCONTROL Flow Management',
            'description' => 'European and NAT flow measures and events'
        ],
        [
            'code' => 'NAVCAN',
            'name' => 'NAV CANADA Flow',
            'description' => 'Canadian flow management (planned)',
            'status' => 'planned'
        ],
        [
            'code' => 'VATPAC',
            'name' => 'VATSIM Pacific Flow',
            'description' => 'Pacific region flow management (planned)',
            'status' => 'planned'
        ]
    ],
    'measure_types' => [
        ['code' => 'MIT', 'name' => 'Miles-In-Trail', 'unit' => 'NM'],
        ['code' => 'MINIT', 'name' => 'Minutes-In-Trail', 'unit' => 'MIN'],
        ['code' => 'MDI', 'name' => 'Minimum Departure Interval', 'unit' => 'SEC'],
        ['code' => 'RATE', 'name' => 'Departure Rate Cap', 'unit' => 'PER_HOUR'],
        ['code' => 'GS', 'name' => 'Ground Stop', 'unit' => null],
        ['code' => 'GDP', 'name' => 'Ground Delay Program', 'unit' => 'MIN'],
        ['code' => 'AFP', 'name' => 'Airspace Flow Program', 'unit' => 'MIN'],
        ['code' => 'REROUTE', 'name' => 'Mandatory Reroute', 'unit' => null]
    ],
    'active_counts' => $counts,
    'standards' => [
        'tfms' => 'FAA Traffic Flow Management System terminology',
        'fixm' => 'FIXM 4.3.0 field naming conventions',
        'icao' => 'ICAO Doc 4444 ATFM procedures'
    ],
    'data_sources' => [
        'primary' => 'VATSIM_TMI',
        'tables' => ['tmi_flow_providers', 'tmi_flow_events', 'tmi_flow_event_participants', 'tmi_flow_measures']
    ],
    'timestamp' => gmdate('c')
];

SwimResponse::success($response, ['source' => 'vatsim_tmi']);
