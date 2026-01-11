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
    'generated_at' => gmdate('Y-m-d\TH:i:s\Z')
];

// Demo mode for testing
$demoMode = isset($_GET['demo']) && $_GET['demo'] == '1';

if ($demoMode) {
    // Return demo incidents for testing layer display
    $result['incidents'] = [
        [
            'id' => 1,
            'incident_number' => '251231001',
            'facility' => 'ZNY',
            'facility_type' => 'ARTCC',
            'incident_type' => 'ATC_ALERT',
            'lifecycle_status' => 'ACTIVE',
            'trigger_code' => 'K',
            'trigger_desc' => 'Staffing (At Minimum)',
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
            'incident_type' => 'ATC_LIMITED',
            'lifecycle_status' => 'MONITORING',
            'trigger_code' => 'E',
            'trigger_desc' => 'Datafeed (Other)',
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
            'incident_type' => 'ATC_ZERO',
            'lifecycle_status' => 'ESCALATED',
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
    echo json_encode($result);
    exit;
}

try {
    if (!isset($conn_adl) || !$conn_adl) {
        echo json_encode($result);
        exit;
    }

    // Get current operations level
    $ops_sql = "SELECT TOP 1 * FROM dbo.jatoc_ops_level ORDER BY id DESC";
    $ops_stmt = @sqlsrv_query($conn_adl, $ops_sql);

    if ($ops_stmt) {
        $ops_row = sqlsrv_fetch_array($ops_stmt, SQLSRV_FETCH_ASSOC);
        if ($ops_row) {
            $result['ops_level'] = [
                'level' => (int)$ops_row['ops_level'],
                'reason' => $ops_row['reason'] ?? null,
                'updated_at' => ($ops_row['set_at'] instanceof DateTime)
                    ? $ops_row['set_at']->format('Y-m-d\TH:i:s\Z')
                    : $ops_row['set_at']
            ];
        }
        sqlsrv_free_stmt($ops_stmt);
    }

    // Get active incidents using new column names with fallback
    $incidents_sql = "SELECT TOP 20
            i.id,
            i.incident_number,
            i.facility,
            i.facility_type,
            COALESCE(i.incident_type, i.status) as incident_type,
            COALESCE(i.lifecycle_status, i.incident_status) as lifecycle_status,
            i.trigger_code,
            i.trigger_desc,
            i.paged,
            i.remarks,
            i.start_utc,
            i.update_utc,
            i.closeout_utc,
            i.created_by,
            i.updated_at,
            (SELECT COUNT(*) FROM dbo.jatoc_incident_updates WHERE incident_id = i.id) as update_count
        FROM dbo.jatoc_incidents i
        WHERE i.closeout_utc IS NULL
        ORDER BY i.start_utc DESC";

    $incidents_stmt = @sqlsrv_query($conn_adl, $incidents_sql);

    if ($incidents_stmt !== false) {
        while ($row = sqlsrv_fetch_array($incidents_stmt, SQLSRV_FETCH_ASSOC)) {
            // Format datetime fields
            foreach (['start_utc', 'update_utc', 'closeout_utc', 'updated_at'] as $field) {
                if (isset($row[$field]) && $row[$field] instanceof DateTime) {
                    $row[$field] = $row[$field]->format('Y-m-d\TH:i:s\Z');
                }
            }

            $row['update_count'] = (int)($row['update_count'] ?? 0);
            $row['paged'] = (bool)($row['paged'] ?? false);

            $result['incidents'][] = $row;

            // Count by lifecycle status
            $status = $row['lifecycle_status'];
            if (!isset($result['summary']['by_status'][$status])) {
                $result['summary']['by_status'][$status] = 0;
            }
            $result['summary']['by_status'][$status]++;

            // Count by incident type
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
    echo json_encode($result);
}
