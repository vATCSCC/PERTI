<?php
/**
 * ADL Database Diagnostic Endpoint
 *
 * Checks if required procedures and tables exist.
 */

header('Content-Type: application/json; charset=utf-8');

require_once(__DIR__ . '/../../load/config.php');
require_once(__DIR__ . '/../../load/connect.php');

if (!isset($conn_adl) || $conn_adl === null || $conn_adl === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not connect to VATSIM_ADL database']);
    exit;
}

$result = [
    'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
    'connection' => 'OK',
    'procedures' => [],
    'tables' => [],
    'counts' => []
];

// Check key procedures
$procedures = [
    'sp_Adl_RefreshFromVatsim_Normalized',
    'sp_ProcessParseQueue',
    'sp_CleanupParseQueue',
    'sp_ProcessTrajectoryBatch',
    'sp_ProcessZoneDetectionBatch',
    'sp_ProcessBoundaryDetectionBatch'
];

foreach ($procedures as $proc) {
    $sql = "SELECT COUNT(*) AS cnt FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.{$proc}') AND type = 'P'";
    $stmt = sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $result['procedures'][$proc] = ($row['cnt'] > 0) ? 'EXISTS' : 'MISSING';
        sqlsrv_free_stmt($stmt);
    } else {
        $result['procedures'][$proc] = 'ERROR';
    }
}

// Check key tables
$tables = [
    'adl_flight_core',
    'adl_flight_plan',
    'adl_flight_position',
    'adl_flight_times',
    'adl_flight_aircraft',
    'adl_parse_queue',
    'adl_trajectories',
    'adl_oooi_log',
    'adl_boundary_log',
    'apts',
    'ACD_Data'
];

foreach ($tables as $table) {
    $sql = "SELECT COUNT(*) AS cnt FROM sys.tables WHERE name = '{$table}'";
    $stmt = sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $result['tables'][$table] = ($row['cnt'] > 0) ? 'EXISTS' : 'MISSING';
        sqlsrv_free_stmt($stmt);
    } else {
        $result['tables'][$table] = 'ERROR';
    }
}

// Get row counts for key tables
$countTables = ['adl_flight_core', 'adl_flight_plan', 'adl_parse_queue'];
foreach ($countTables as $table) {
    if ($result['tables'][$table] === 'EXISTS') {
        $sql = "SELECT COUNT(*) AS cnt FROM dbo.{$table}";
        $stmt = sqlsrv_query($conn_adl, $sql);
        if ($stmt) {
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            $result['counts'][$table] = $row['cnt'];
            sqlsrv_free_stmt($stmt);
        }
    }
}

// Check active flights in core
$sql = "SELECT COUNT(*) AS cnt FROM dbo.adl_flight_core WHERE is_active = 1";
$stmt = sqlsrv_query($conn_adl, $sql);
if ($stmt) {
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    $result['counts']['active_in_core'] = $row['cnt'];
    sqlsrv_free_stmt($stmt);
}

// Check active flights WITH position (INNER JOIN - what normalized API returns)
$sql = "SELECT COUNT(*) AS cnt FROM dbo.adl_flight_core c INNER JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid WHERE c.is_active = 1";
$stmt = sqlsrv_query($conn_adl, $sql);
if ($stmt) {
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    $result['counts']['active_with_position'] = $row['cnt'];
    sqlsrv_free_stmt($stmt);
}

// Check active flights MISSING position (the gap)
$sql = "SELECT COUNT(*) AS cnt FROM dbo.adl_flight_core c LEFT JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid WHERE c.is_active = 1 AND p.flight_uid IS NULL";
$stmt = sqlsrv_query($conn_adl, $sql);
if ($stmt) {
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    $result['counts']['missing_position'] = $row['cnt'];
    sqlsrv_free_stmt($stmt);
}

// Calculate gap
$result['counts']['position_gap'] = ($result['counts']['active_in_core'] ?? 0) - ($result['counts']['active_with_position'] ?? 0);

// Report current query source
$result['query_source'] = defined('ADL_QUERY_SOURCE') ? ADL_QUERY_SOURCE : 'view (default)';

// Test the full normalized query count (to identify WHERE the filtering happens)
$sql = "SELECT COUNT(*) AS cnt FROM dbo.adl_flight_core c
        LEFT JOIN dbo.adl_flight_position p ON p.flight_uid = c.flight_uid
        LEFT JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
        LEFT JOIN dbo.adl_flight_aircraft ac ON ac.flight_uid = c.flight_uid
        LEFT JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
        WHERE c.is_active = 1";
$stmt = sqlsrv_query($conn_adl, $sql);
if ($stmt) {
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    $result['counts']['full_join_count'] = $row['cnt'];
    sqlsrv_free_stmt($stmt);
}

// Check how many active flights have times records (the ORDER BY uses times)
$sql = "SELECT COUNT(*) AS cnt FROM dbo.adl_flight_core c
        INNER JOIN dbo.adl_flight_times t ON t.flight_uid = c.flight_uid
        WHERE c.is_active = 1";
$stmt = sqlsrv_query($conn_adl, $sql);
if ($stmt) {
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    $result['counts']['with_times'] = $row['cnt'];
    sqlsrv_free_stmt($stmt);
}

// Check how many have flight plans
$sql = "SELECT COUNT(*) AS cnt FROM dbo.adl_flight_core c
        INNER JOIN dbo.adl_flight_plan fp ON fp.flight_uid = c.flight_uid
        WHERE c.is_active = 1";
$stmt = sqlsrv_query($conn_adl, $sql);
if ($stmt) {
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    $result['counts']['with_plan'] = $row['cnt'];
    sqlsrv_free_stmt($stmt);
}

// Check how many have aircraft records
$sql = "SELECT COUNT(*) AS cnt FROM dbo.adl_flight_core c
        INNER JOIN dbo.adl_flight_aircraft ac ON ac.flight_uid = c.flight_uid
        WHERE c.is_active = 1";
$stmt = sqlsrv_query($conn_adl, $sql);
if ($stmt) {
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    $result['counts']['with_aircraft'] = $row['cnt'];
    sqlsrv_free_stmt($stmt);
}

// Check ATIS data (last hour)
$sql = "SELECT COUNT(*) AS cnt FROM dbo.vatsim_atis WHERE fetched_utc > DATEADD(HOUR, -1, SYSUTCDATETIME())";
$stmt = @sqlsrv_query($conn_adl, $sql);
if ($stmt) {
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    $result['counts']['atis_last_hour'] = $row['cnt'];
    sqlsrv_free_stmt($stmt);
}

// Check last flight update time
$sql = "SELECT TOP 1 snapshot_utc FROM dbo.adl_flight_core WHERE is_active = 1 ORDER BY snapshot_utc DESC";
$stmt = @sqlsrv_query($conn_adl, $sql);
if ($stmt) {
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if ($row && $row['snapshot_utc'] instanceof DateTimeInterface) {
        $result['last_flight_update'] = $row['snapshot_utc']->format('Y-m-d H:i:s') . ' UTC';
    }
    sqlsrv_free_stmt($stmt);
}

// Check last ATIS update time
$sql = "SELECT TOP 1 fetched_utc FROM dbo.vatsim_atis ORDER BY fetched_utc DESC";
$stmt = @sqlsrv_query($conn_adl, $sql);
if ($stmt) {
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if ($row && $row['fetched_utc'] instanceof DateTimeInterface) {
        $result['last_atis_update'] = $row['fetched_utc']->format('Y-m-d H:i:s') . ' UTC';
    }
    sqlsrv_free_stmt($stmt);
}

// Test a simple insert/select to verify write permissions
$result['write_test'] = 'NOT_TESTED';

sqlsrv_close($conn_adl);

echo json_encode($result, JSON_PRETTY_PRINT);
