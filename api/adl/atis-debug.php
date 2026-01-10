<?php
/**
 * ATIS Debug & Cleanup API Endpoint
 *
 * GET  /api/adl/atis-debug.php              - View pending ATIS with parse results
 * POST /api/adl/atis-debug.php              - Clear pending queue (mark as PARSED)
 * GET  /api/adl/atis-debug.php?clear=1      - Clear pending queue (alternative)
 * GET  /api/adl/atis-debug.php?airport=KJFK - Filter by airport
 * GET  /api/adl/atis-debug.php?limit=20     - Limit results (default 10)
 */

header('Content-Type: application/json; charset=utf-8');

require_once(__DIR__ . '/../../load/config.php');
require_once(__DIR__ . '/../../load/connect.php');
require_once(__DIR__ . '/../../scripts/atis_parser.php');

// Check ADL connection
if (!isset($conn_adl) || $conn_adl === null || $conn_adl === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not connect to VATSIM_ADL database']);
    exit;
}

$clearQueue = ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['clear']));
$airport = isset($_GET['airport']) ? strtoupper(trim($_GET['airport'])) : null;
$limit = isset($_GET['limit']) ? min(100, max(1, intval($_GET['limit']))) : 10;

$result = [
    'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
    'status' => [],
    'samples' => [],
];

// Get queue status
$sql = "SELECT
    COUNT(*) AS total,
    COUNT(CASE WHEN parse_status = 'PENDING' THEN 1 END) AS pending,
    COUNT(CASE WHEN parse_status = 'PARSED' THEN 1 END) AS parsed,
    COUNT(CASE WHEN parse_status = 'FAILED' THEN 1 END) AS failed,
    MIN(CASE WHEN parse_status = 'PENDING' THEN fetched_utc END) AS oldest_pending,
    MAX(CASE WHEN parse_status = 'PENDING' THEN fetched_utc END) AS newest_pending
FROM dbo.vatsim_atis
WHERE fetched_utc > DATEADD(HOUR, -24, SYSUTCDATETIME())";

$stmt = sqlsrv_query($conn_adl, $sql);
if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $result['status'] = [
        'total_24h' => $row['total'],
        'pending' => $row['pending'],
        'parsed' => $row['parsed'],
        'failed' => $row['failed'],
        'oldest_pending' => $row['oldest_pending'] ? $row['oldest_pending']->format('Y-m-d\TH:i:s\Z') : null,
        'newest_pending' => $row['newest_pending'] ? $row['newest_pending']->format('Y-m-d\TH:i:s\Z') : null,
    ];
    sqlsrv_free_stmt($stmt);
}

// Handle clear operation
if ($clearQueue) {
    $result['action'] = 'clear';

    // Mark all PENDING as PARSED (with a note that they were force-cleared)
    $sql = "UPDATE dbo.vatsim_atis
            SET parse_status = 'PARSED',
                parse_error = 'Force-cleared via atis-debug API'
            WHERE parse_status = 'PENDING'";

    $stmt = sqlsrv_query($conn_adl, $sql);
    if ($stmt) {
        $result['cleared'] = sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);
    } else {
        $errors = sqlsrv_errors();
        $result['error'] = $errors[0]['message'] ?? 'Unknown error';
    }

    // Get updated status
    $sql = "SELECT
        COUNT(CASE WHEN parse_status = 'PENDING' THEN 1 END) AS pending,
        COUNT(CASE WHEN parse_status = 'PARSED' THEN 1 END) AS parsed,
        COUNT(CASE WHEN parse_status = 'FAILED' THEN 1 END) AS failed
    FROM dbo.vatsim_atis
    WHERE fetched_utc > DATEADD(HOUR, -24, SYSUTCDATETIME())";

    $stmt = sqlsrv_query($conn_adl, $sql);
    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $result['status_after'] = $row;
        sqlsrv_free_stmt($stmt);
    }

    echo json_encode($result, JSON_PRETTY_PRINT);
    exit;
}

// Get sample pending ATIS with parse results
$sql = "SELECT TOP ($limit)
    atis_id,
    airport_icao,
    callsign,
    atis_type,
    atis_code,
    atis_text,
    fetched_utc,
    parse_status,
    parse_error
FROM dbo.vatsim_atis
WHERE parse_status = 'PENDING'
  AND atis_text IS NOT NULL
  AND LEN(atis_text) > 10";

if ($airport) {
    $sql .= " AND airport_icao = ?";
}

$sql .= " ORDER BY fetched_utc DESC";

$params = $airport ? [$airport] : [];
$stmt = sqlsrv_query($conn_adl, $sql, $params);

if ($stmt) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $atisText = $row['atis_text'] ?? '';

        // Parse the ATIS text
        $parsed = parseAtisRunways($atisText);

        $sample = [
            'atis_id' => $row['atis_id'],
            'airport' => $row['airport_icao'],
            'callsign' => $row['callsign'],
            'type' => $row['atis_type'],
            'code' => $row['atis_code'],
            'fetched' => $row['fetched_utc'] ? $row['fetched_utc']->format('Y-m-d\TH:i:s\Z') : null,
            'text' => $atisText,
            'text_filtered' => filterAtisText($atisText),
            'parsed' => [
                'landing' => $parsed['landing'],
                'departing' => $parsed['departing'],
                'approaches' => $parsed['approaches'],
                'summary' => formatRunwaySummary($parsed['landing'], $parsed['departing']),
            ],
            'would_import' => !empty($parsed['landing']) || !empty($parsed['departing']),
        ];

        $result['samples'][] = $sample;
    }
    sqlsrv_free_stmt($stmt);
}

// Also get recent failed records
$sql = "SELECT TOP 5
    atis_id,
    airport_icao,
    callsign,
    atis_text,
    parse_error,
    fetched_utc
FROM dbo.vatsim_atis
WHERE parse_status = 'FAILED'
ORDER BY fetched_utc DESC";

$stmt = sqlsrv_query($conn_adl, $sql);
if ($stmt) {
    $failed = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $failed[] = [
            'atis_id' => $row['atis_id'],
            'airport' => $row['airport_icao'],
            'callsign' => $row['callsign'],
            'text' => $row['atis_text'],
            'error' => $row['parse_error'],
            'fetched' => $row['fetched_utc'] ? $row['fetched_utc']->format('Y-m-d\TH:i:s\Z') : null,
        ];
    }
    if (!empty($failed)) {
        $result['recent_failed'] = $failed;
    }
    sqlsrv_free_stmt($stmt);
}

sqlsrv_close($conn_adl);

echo json_encode($result, JSON_PRETTY_PRINT);
