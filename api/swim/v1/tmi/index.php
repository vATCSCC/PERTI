<?php
/**
 * VATSIM SWIM API v1 - TMI Index Endpoint
 * 
 * Returns overview of TMI (Traffic Management Initiative) data and endpoints.
 * 
 * GET /api/swim/v1/tmi/
 * 
 * @version 1.0.0
 */

require_once __DIR__ . '/../auth.php';

// TMI database connection
global $conn_tmi, $conn_adl, $conn_swim;

$auth = swim_init_auth(false, false);  // No auth required for index

// Get active counts from VATSIM_TMI if available
$counts = [
    'active_entries' => 0,
    'active_programs' => 0,
    'active_advisories' => 0,
    'active_reroutes' => 0,
    'active_public_routes' => 0,
    'active_flow_events' => 0,
    'active_flow_measures' => 0
];

if ($conn_tmi) {
    // Count active entries
    $sql = "SELECT COUNT(*) as cnt FROM dbo.vw_tmi_active_entries";
    $stmt = sqlsrv_query($conn_tmi, $sql);
    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $counts['active_entries'] = $row['cnt'];
    }
    if ($stmt) sqlsrv_free_stmt($stmt);
    
    // Count active programs
    $sql = "SELECT COUNT(*) as cnt FROM dbo.vw_tmi_active_programs";
    $stmt = sqlsrv_query($conn_tmi, $sql);
    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $counts['active_programs'] = $row['cnt'];
    }
    if ($stmt) sqlsrv_free_stmt($stmt);
    
    // Count active advisories
    $sql = "SELECT COUNT(*) as cnt FROM dbo.vw_tmi_active_advisories";
    $stmt = sqlsrv_query($conn_tmi, $sql);
    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $counts['active_advisories'] = $row['cnt'];
    }
    if ($stmt) sqlsrv_free_stmt($stmt);
    
    // Count active reroutes
    $sql = "SELECT COUNT(*) as cnt FROM dbo.vw_tmi_active_reroutes";
    $stmt = sqlsrv_query($conn_tmi, $sql);
    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $counts['active_reroutes'] = $row['cnt'];
    }
    if ($stmt) sqlsrv_free_stmt($stmt);
    
    // Count active public routes
    $sql = "SELECT COUNT(*) as cnt FROM dbo.vw_tmi_active_public_routes";
    $stmt = sqlsrv_query($conn_tmi, $sql);
    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $counts['active_public_routes'] = $row['cnt'];
    }
    if ($stmt) sqlsrv_free_stmt($stmt);

    // Count active flow events (external providers)
    $sql = "SELECT COUNT(*) as cnt FROM dbo.vw_tmi_active_flow_events";
    $stmt = sqlsrv_query($conn_tmi, $sql);
    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $counts['active_flow_events'] = $row['cnt'];
    }
    if ($stmt) sqlsrv_free_stmt($stmt);

    // Count active flow measures (external providers)
    $sql = "SELECT COUNT(*) as cnt FROM dbo.vw_tmi_active_flow_measures";
    $stmt = sqlsrv_query($conn_tmi, $sql);
    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $counts['active_flow_measures'] = $row['cnt'];
    }
    if ($stmt) sqlsrv_free_stmt($stmt);
}

$response = [
    'success' => true,
    'api' => [
        'name' => 'VATSIM SWIM TMI API',
        'version' => '1.0.0',
        'description' => 'Traffic Management Initiative data for VATSIM network'
    ],
    'endpoints' => [
        [
            'path' => '/api/swim/v1/tmi/programs',
            'methods' => ['GET'],
            'description' => 'Active TMI programs (Ground Stops, GDPs)',
            'auth' => 'optional'
        ],
        [
            'path' => '/api/swim/v1/tmi/controlled',
            'methods' => ['GET'],
            'description' => 'Flights currently under TMI control',
            'auth' => 'required'
        ],
        [
            'path' => '/api/swim/v1/tmi/entries',
            'methods' => ['GET'],
            'description' => 'NTML log entries (MIT, MINIT, STOP, etc.)',
            'auth' => 'optional'
        ],
        [
            'path' => '/api/swim/v1/tmi/advisories',
            'methods' => ['GET'],
            'description' => 'Formal TMI advisories (GS, GDP, Reroute)',
            'auth' => 'optional'
        ],
        [
            'path' => '/api/swim/v1/tmi/reroutes',
            'methods' => ['GET'],
            'description' => 'Active reroute definitions',
            'auth' => 'optional'
        ],
        [
            'path' => '/api/swim/v1/tmi/routes',
            'methods' => ['GET'],
            'description' => 'Public route display (GeoJSON)',
            'auth' => 'optional'
        ],
        [
            'path' => '/api/swim/v1/tmi/measures',
            'methods' => ['GET'],
            'description' => 'Unified TMI measures (USA + external providers)',
            'auth' => 'optional'
        ],
        [
            'path' => '/api/swim/v1/tmi/flow/',
            'methods' => ['GET'],
            'description' => 'External flow management index',
            'auth' => 'optional'
        ],
        [
            'path' => '/api/swim/v1/tmi/flow/providers',
            'methods' => ['GET'],
            'description' => 'Registered flow management providers',
            'auth' => 'optional'
        ],
        [
            'path' => '/api/swim/v1/tmi/flow/events',
            'methods' => ['GET'],
            'description' => 'External events (CTP, FNO, etc.)',
            'auth' => 'optional'
        ],
        [
            'path' => '/api/swim/v1/tmi/flow/measures',
            'methods' => ['GET'],
            'description' => 'External flow measures (MIT, MDI, etc.)',
            'auth' => 'optional'
        ]
    ],
    'active_counts' => $counts,
    'data_sources' => [
        'primary' => 'VATSIM_TMI',
        'fallback' => 'VATSIM_ADL (legacy)',
        'flight_data' => 'SWIM_API'
    ],
    'timestamp' => gmdate('c')
];

SwimResponse::success($response, ['source' => 'vatcscc']);
