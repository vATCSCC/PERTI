<?php
/**
 * ADL Daemon Diagnostic Endpoint
 *
 * Returns health status of the ADL data ingestion system.
 *
 * Usage: GET /api/adl/diagnostic.php
 *
 * Returns JSON with:
 *   - database_connected: bool
 *   - last_refresh: timestamp of last VATSIM data refresh
 *   - refresh_age_seconds: seconds since last refresh
 *   - active_flights: number of active flights
 *   - queue_status: parse queue statistics
 *   - atis_status: ATIS import statistics
 *   - health: overall health status (ok, warning, critical)
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

require_once __DIR__ . '/../../load/connect.php';

$response = [
    'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
    'database_connected' => false,
    'last_refresh' => null,
    'refresh_age_seconds' => null,
    'active_flights' => 0,
    'queue_status' => [
        'pending' => 0,
        'processing' => 0,
        'complete_1h' => 0,
        'failed_1h' => 0,
    ],
    'atis_status' => [
        'total_1h' => 0,
        'parsed_1h' => 0,
        'pending' => 0,
    ],
    'health' => 'critical',
    'issues' => [],
];

// Check ADL connection
if (!isset($conn_adl) || $conn_adl === false) {
    $response['issues'][] = 'ADL database connection not available';
    echo json_encode($response, JSON_PRETTY_PRINT);
    exit;
}

$response['database_connected'] = true;

try {
    // Get last refresh time from adl_run_log
    $sql = "SELECT TOP 1
                run_utc,
                DATEDIFF(SECOND, run_utc, SYSUTCDATETIME()) AS age_seconds,
                pilots_received,
                new_flights,
                updated_flights
            FROM dbo.adl_run_log
            ORDER BY run_utc DESC";

    $stmt = sqlsrv_query($conn_adl, $sql);
    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $response['last_refresh'] = $row['run_utc']->format('Y-m-d\TH:i:s\Z');
        $response['refresh_age_seconds'] = $row['age_seconds'];
        $response['last_refresh_stats'] = [
            'pilots_received' => $row['pilots_received'],
            'new_flights' => $row['new_flights'],
            'updated_flights' => $row['updated_flights'],
        ];
        sqlsrv_free_stmt($stmt);
    }

    // Get active flight count - use view which supports normalized schema
    $sql = "SELECT COUNT(*) AS cnt FROM dbo.vw_adl_flights WHERE is_active = 1";
    $stmt = sqlsrv_query($conn_adl, $sql);
    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $response['active_flights'] = $row['cnt'];
        sqlsrv_free_stmt($stmt);
    }

    // Fallback: get last snapshot time from flights if run_log is empty
    if ($response['last_refresh'] === null) {
        $sql = "SELECT TOP 1 snapshot_utc, DATEDIFF(SECOND, snapshot_utc, SYSUTCDATETIME()) AS age_seconds
                FROM dbo.vw_adl_flights ORDER BY snapshot_utc DESC";
        $stmt = sqlsrv_query($conn_adl, $sql);
        if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            if ($row['snapshot_utc'] instanceof DateTimeInterface) {
                $response['last_refresh'] = $row['snapshot_utc']->format('Y-m-d\TH:i:s\Z');
            }
            $response['refresh_age_seconds'] = $row['age_seconds'];
            sqlsrv_free_stmt($stmt);
        }
    }

    // Get parse queue status
    $sql = "SELECT
                COUNT(CASE WHEN status = 'PENDING' THEN 1 END) AS pending,
                COUNT(CASE WHEN status = 'PROCESSING' THEN 1 END) AS processing,
                COUNT(CASE WHEN status = 'COMPLETE' AND completed_utc > DATEADD(HOUR, -1, SYSUTCDATETIME()) THEN 1 END) AS complete_1h,
                COUNT(CASE WHEN status = 'FAILED' AND completed_utc > DATEADD(HOUR, -1, SYSUTCDATETIME()) THEN 1 END) AS failed_1h
            FROM dbo.adl_parse_queue";

    $stmt = sqlsrv_query($conn_adl, $sql);
    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $response['queue_status'] = [
            'pending' => $row['pending'],
            'processing' => $row['processing'],
            'complete_1h' => $row['complete_1h'],
            'failed_1h' => $row['failed_1h'],
        ];
        sqlsrv_free_stmt($stmt);
    }

    // Get ATIS status
    $sql = "SELECT
                COUNT(CASE WHEN received_utc > DATEADD(HOUR, -1, SYSUTCDATETIME()) THEN 1 END) AS total_1h,
                COUNT(CASE WHEN parse_status = 'PARSED' AND received_utc > DATEADD(HOUR, -1, SYSUTCDATETIME()) THEN 1 END) AS parsed_1h,
                COUNT(CASE WHEN parse_status = 'PENDING' THEN 1 END) AS pending
            FROM dbo.vatsim_atis";

    $stmt = sqlsrv_query($conn_adl, $sql);
    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $response['atis_status'] = [
            'total_1h' => $row['total_1h'],
            'parsed_1h' => $row['parsed_1h'],
            'pending' => $row['pending'],
        ];
        sqlsrv_free_stmt($stmt);
    }

    // Determine health status
    $health = 'ok';

    // Check refresh age
    if ($response['refresh_age_seconds'] !== null) {
        if ($response['refresh_age_seconds'] > 120) {
            $health = 'critical';
            $response['issues'][] = 'Data refresh is stale (>2 minutes old)';
        } elseif ($response['refresh_age_seconds'] > 60) {
            $health = 'warning';
            $response['issues'][] = 'Data refresh is delayed (>1 minute old)';
        }
    } else {
        $health = 'critical';
        $response['issues'][] = 'No refresh data found in run log';
    }

    // Check parse queue backlog
    if ($response['queue_status']['pending'] > 500) {
        if ($health !== 'critical') $health = 'warning';
        $response['issues'][] = 'Large parse queue backlog (>' . $response['queue_status']['pending'] . ' pending)';
    }

    // Check for stuck processing items
    if ($response['queue_status']['processing'] > 100) {
        if ($health !== 'critical') $health = 'warning';
        $response['issues'][] = 'Many items stuck in processing state';
    }

    $response['health'] = $health;

} catch (Exception $e) {
    $response['issues'][] = 'Query error: ' . $e->getMessage();
    $response['health'] = 'critical';
}

echo json_encode($response, JSON_PRETTY_PRINT);
