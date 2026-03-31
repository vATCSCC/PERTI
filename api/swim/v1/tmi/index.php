<?php
/**
 * VATSWIM API v1 - TMI Index Endpoint
 * 
 * Returns overview of TMI (Traffic Management Initiative) data and endpoints.
 * 
 * GET /api/swim/v1/tmi/
 * 
 * @version 1.0.0
 */

require_once __DIR__ . '/../auth.php';

// SWIM database connection (SWIM-isolated: uses SWIM_API mirror tables)
global $conn_swim;

$auth = swim_init_auth(false, false);  // No auth required for index

// Get active counts from SWIM_API mirror tables
$counts = [
    'active_entries' => 0,
    'active_programs' => 0,
    'active_advisories' => 0,
    'active_reroutes' => 0,
    'active_public_routes' => 0,
    'active_flow_events' => 0,
    'active_flow_measures' => 0,
    'event_log_entries_24h' => 0,
    'delay_attributions' => 0,
    'facility_stats_airports' => 0
];

if ($conn_swim) {
    // Count active entries
    $sql = "SELECT COUNT(*) as cnt FROM dbo.vw_swim_active_entries";
    $stmt = sqlsrv_query($conn_swim, $sql);
    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $counts['active_entries'] = $row['cnt'];
    }
    if ($stmt) sqlsrv_free_stmt($stmt);

    // Count active programs
    $sql = "SELECT COUNT(*) as cnt FROM dbo.vw_swim_active_programs";
    $stmt = sqlsrv_query($conn_swim, $sql);
    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $counts['active_programs'] = $row['cnt'];
    }
    if ($stmt) sqlsrv_free_stmt($stmt);

    // Count active advisories
    $sql = "SELECT COUNT(*) as cnt FROM dbo.vw_swim_active_advisories";
    $stmt = sqlsrv_query($conn_swim, $sql);
    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $counts['active_advisories'] = $row['cnt'];
    }
    if ($stmt) sqlsrv_free_stmt($stmt);

    // Count active reroutes
    $sql = "SELECT COUNT(*) as cnt FROM dbo.vw_swim_active_reroutes";
    $stmt = sqlsrv_query($conn_swim, $sql);
    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $counts['active_reroutes'] = $row['cnt'];
    }
    if ($stmt) sqlsrv_free_stmt($stmt);

    // Count active public routes
    $sql = "SELECT COUNT(*) as cnt FROM dbo.vw_swim_active_public_routes";
    $stmt = sqlsrv_query($conn_swim, $sql);
    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $counts['active_public_routes'] = $row['cnt'];
    }
    if ($stmt) sqlsrv_free_stmt($stmt);

    // Count active flow events (external providers)
    $sql = "SELECT COUNT(*) as cnt FROM dbo.vw_swim_active_flow_events";
    $stmt = sqlsrv_query($conn_swim, $sql);
    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $counts['active_flow_events'] = $row['cnt'];
    }
    if ($stmt) sqlsrv_free_stmt($stmt);

    // Count active flow measures (external providers)
    $sql = "SELECT COUNT(*) as cnt FROM dbo.vw_swim_active_flow_measures";
    $stmt = sqlsrv_query($conn_swim, $sql);
    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $counts['active_flow_measures'] = $row['cnt'];
    }
    if ($stmt) sqlsrv_free_stmt($stmt);

    // Count event log entries in last 24 hours
    $sql = "SELECT COUNT(*) as cnt FROM dbo.swim_tmi_log_core WHERE event_utc >= DATEADD(HOUR, -24, GETUTCDATE())";
    $stmt = sqlsrv_query($conn_swim, $sql);
    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $counts['event_log_entries_24h'] = $row['cnt'];
    }
    if ($stmt) sqlsrv_free_stmt($stmt);

    // Count current delay attributions
    $sql = "SELECT COUNT(*) as cnt FROM dbo.swim_tmi_delay_attribution WHERE is_current = 1";
    $stmt = sqlsrv_query($conn_swim, $sql);
    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $counts['delay_attributions'] = $row['cnt'];
    }
    if ($stmt) sqlsrv_free_stmt($stmt);

    // Count distinct airports in facility stats (hourly, last 24h)
    $sql = "SELECT COUNT(DISTINCT airport_icao) as cnt FROM dbo.swim_tmi_facility_stats_hourly WHERE hour_utc >= DATEADD(HOUR, -24, GETUTCDATE())";
    $stmt = sqlsrv_query($conn_swim, $sql);
    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $counts['facility_stats_airports'] = $row['cnt'];
    }
    if ($stmt) sqlsrv_free_stmt($stmt);
} else {
    SwimResponse::error('SWIM database connection not available', 503, 'SERVICE_UNAVAILABLE');
}

$response = [
    'success' => true,
    'api' => [
        'name' => 'VATSWIM TMI API',
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
            'path' => '/api/swim/v1/tmi/event-log',
            'methods' => ['GET'],
            'description' => 'TMI event log with scope, parameters, impact, and references',
            'auth' => 'optional'
        ],
        [
            'path' => '/api/swim/v1/tmi/delay-attribution',
            'methods' => ['GET'],
            'description' => 'Delay attribution linking delays to causes, programs, and phases',
            'auth' => 'optional'
        ],
        [
            'path' => '/api/swim/v1/tmi/facility-stats',
            'methods' => ['GET'],
            'description' => 'Aggregated TMI facility statistics (hourly/daily)',
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
        ],
        [
            'path' => '/api/swim/v1/tmi/flow/ingest',
            'methods' => ['POST'],
            'description' => 'Push flow measures from external providers (ECFMP, vIFF, etc.)',
            'auth' => 'required (partner/system tier with flow_measure write access)'
        ]
    ],
    'active_counts' => $counts,
    'data_sources' => [
        'primary' => 'SWIM_API',
        'flight_data' => 'SWIM_API'
    ],
    'timestamp' => gmdate('c')
];

SwimResponse::success($response, ['source' => 'vatcscc']);
