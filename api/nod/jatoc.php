<?php
/**
 * NOD JATOC Incidents API
 * 
 * Returns active JATOC incidents for NOD display
 * GET - Returns active incidents with summary
 * GET ?demo=1 - Returns demo incidents for testing
 */

header('Content-Type: application/json');

// Include database connections
$config_path = realpath(__DIR__ . '/../../load/config.php');
$connect_path = realpath(__DIR__ . '/../../load/connect.php');

if ($config_path) include($config_path);
if ($connect_path) include($connect_path);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$result = [
    'incidents' => [],
    'ops_level' => null,
    'summary' => [
        'total_active' => 0,
        'by_status' => [],
        'by_type' => []
    ],
    'debug' => [
        'connection' => false,
        'table_exists' => false,
        'query_error' => null
    ],
    'generated_at' => gmdate('Y-m-d\TH:i:s\Z')
];

// Demo mode for testing
$demoMode = isset($_GET['demo']) && $_GET['demo'] == '1';

if ($demoMode) {
    // Return demo incidents for testing layer display
    // Matches actual jatoc_incidents table structure
    $result['incidents'] = [
        [
            'id' => 1,
            'incident_number' => '251231001',
            'facility' => 'ZNY',
            'facility_type' => 'ARTCC',
            'incident_type' => 'ATC ALERT',      // From 'status' column
            'incident_status' => 'OPEN',          // From 'incident_status' column
            'status' => 'OPEN',                   // Alias for JS compatibility
            'trigger_code' => 'S',
            'trigger_desc' => 'Staffing (At Min)',
            'remarks' => 'Demo incident for testing',
            'start_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'update_count' => 0,
            'paged' => false
        ],
        [
            'id' => 2,
            'incident_number' => '251231002',
            'facility' => 'N90',
            'facility_type' => 'TRACON',
            'incident_type' => 'ATC LIMITED',
            'incident_status' => 'MONITORING',
            'status' => 'MONITORING',
            'trigger_code' => 'E',
            'trigger_desc' => 'Equipment',
            'remarks' => 'Demo TRACON incident - reduced ops',
            'start_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'update_count' => 2,
            'paged' => true
        ],
        [
            'id' => 3,
            'incident_number' => '251231003',
            'facility' => 'ZDC',
            'facility_type' => 'ARTCC',
            'incident_type' => 'ATC ZERO',
            'incident_status' => 'ESCALATED',
            'status' => 'ESCALATED',
            'trigger_code' => 'W',
            'trigger_desc' => 'Weather',
            'remarks' => 'Demo ATC Zero - full stop',
            'start_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'update_count' => 5,
            'paged' => true
        ]
    ];
    $result['ops_level'] = ['level' => 2, 'reason' => 'Demo mode - elevated'];
    $result['summary']['total_active'] = 3;
    $result['debug']['demo_mode'] = true;
    echo json_encode($result);
    exit;
}

try {
    if (!isset($conn_adl) || !$conn_adl) {
        $result['debug']['connection'] = false;
        $result['debug']['query_error'] = 'Database connection not available';
        echo json_encode($result);
        exit;
    }
    
    $result['debug']['connection'] = true;
    
    // Check if table exists
    $check_sql = "SELECT OBJECT_ID('dbo.jatoc_incidents', 'U') as table_id";
    $check_stmt = @sqlsrv_query($conn_adl, $check_sql);
    if ($check_stmt) {
        $check_row = sqlsrv_fetch_array($check_stmt, SQLSRV_FETCH_ASSOC);
        $result['debug']['table_exists'] = !empty($check_row['table_id']);
        sqlsrv_free_stmt($check_stmt);
    }
    
    if (!$result['debug']['table_exists']) {
        $result['debug']['query_error'] = 'jatoc_incidents table does not exist';
        echo json_encode($result);
        exit;
    }
    
    // Get current operations level
    $ops_sql = "SELECT TOP 1 * FROM dbo.jatoc_ops_level ORDER BY set_utc DESC";
    $ops_stmt = @sqlsrv_query($conn_adl, $ops_sql);
    
    if ($ops_stmt) {
        $ops_row = sqlsrv_fetch_array($ops_stmt, SQLSRV_FETCH_ASSOC);
        if ($ops_row) {
            $result['ops_level'] = [
                'level' => (int)$ops_row['ops_level'],
                'reason' => $ops_row['reason'] ?? null,
                'updated_at' => ($ops_row['set_utc'] instanceof DateTime) 
                    ? $ops_row['set_utc']->format('Y-m-d\TH:i:s\Z') 
                    : $ops_row['set_utc']
            ];
        }
        sqlsrv_free_stmt($ops_stmt);
    }
    
    // Get active incidents
    // Note: 'status' column = incident type (ATC ZERO, ATC ALERT, etc.)
    //       'incident_status' column = lifecycle (OPEN, MONITORING, ESCALATED, CLOSED)
    
    // First, debug: get all incidents to see what status values exist
    $debug_sql = "SELECT TOP 5 id, facility, status, incident_status, start_utc FROM dbo.jatoc_incidents ORDER BY start_utc DESC";
    $debug_stmt = @sqlsrv_query($conn_adl, $debug_sql);
    if ($debug_stmt) {
        $result['debug']['recent_incidents'] = [];
        while ($drow = sqlsrv_fetch_array($debug_stmt, SQLSRV_FETCH_ASSOC)) {
            $result['debug']['recent_incidents'][] = [
                'id' => $drow['id'],
                'facility' => $drow['facility'],
                'status' => $drow['status'],
                'incident_status' => $drow['incident_status']
            ];
        }
        sqlsrv_free_stmt($debug_stmt);
    }
    
    // Use view if it exists, otherwise query table directly
    // Try without incident_status filter first to see what's there
    $incidents_sql = "SELECT TOP 20
            i.id,
            i.incident_number,
            i.facility,
            i.facility_type,
            i.status as incident_type,
            i.incident_status,
            i.trigger_code,
            i.trigger_desc,
            i.paged,
            i.remarks,
            i.start_utc,
            i.update_utc,
            i.closeout_utc,
            i.created_by,
            i.created_utc,
            i.updated_at,
            i.severity,
            (SELECT COUNT(*) FROM dbo.jatoc_updates WHERE incident_id = i.id) as update_count
        FROM dbo.jatoc_incidents i
        WHERE i.closeout_utc IS NULL
        ORDER BY i.start_utc DESC";
    
    $incidents_stmt = @sqlsrv_query($conn_adl, $incidents_sql);
    
    if ($incidents_stmt === false) {
        $errors = sqlsrv_errors();
        $result['debug']['query_error'] = $errors ? $errors[0]['message'] : 'Unknown query error';
    } else {
        while ($row = sqlsrv_fetch_array($incidents_stmt, SQLSRV_FETCH_ASSOC)) {
            // Format datetime fields
            foreach (['start_utc', 'update_utc', 'closeout_utc', 'created_utc', 'updated_at'] as $field) {
                if (isset($row[$field]) && $row[$field] instanceof DateTime) {
                    $row[$field] = $row[$field]->format('Y-m-d\TH:i:s\Z');
                }
            }
            
            $row['update_count'] = (int)($row['update_count'] ?? 0);
            $row['paged'] = (bool)($row['paged'] ?? false);
            
            // Map to expected field names for JS compatibility
            $row['status'] = $row['incident_status'];
            
            $result['incidents'][] = $row;
            
            // Count by status
            $status = $row['incident_status'];
            if (!isset($result['summary']['by_status'][$status])) {
                $result['summary']['by_status'][$status] = 0;
            }
            $result['summary']['by_status'][$status]++;
            
            // Count by type
            $type = $row['incident_type'];
            if (!isset($result['summary']['by_type'][$type])) {
                $result['summary']['by_type'][$type] = 0;
            }
            $result['summary']['by_type'][$type]++;
        }
        sqlsrv_free_stmt($incidents_stmt);
    }
    
    $result['summary']['total_active'] = count($result['incidents']);
    
    echo json_encode($result);
    
} catch (Exception $e) {
    // Return empty result with error message (table may not exist)
    $result['debug']['query_error'] = $e->getMessage();
    echo json_encode($result);
}
