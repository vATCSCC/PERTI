<?php
/**
 * VATSWIM API v1 - Airport Taxi Time Reference Data
 *
 * Returns unimpeded taxi-out reference times per airport, computed using
 * FAA ASPM methodology (p5-p15 average over 90-day rolling window).
 *
 * @version 1.0.0
 * @since 2026-02-08
 *
 * Endpoints:
 *   GET /reference/taxi-times              - All airports with taxi reference data
 *   GET /reference/taxi-times/{airport}    - Single airport with dimensional breakdown
 *
 * Query Parameters:
 *   confidence   - Filter by confidence level (HIGH|MEDIUM|LOW|DEFAULT)
 *   min_samples  - Minimum sample count filter (integer)
 *   format       - Response format: json (default), fixm, xml, csv, ndjson
 *
 * Methodology:
 *   Unimpeded taxi-out time = average of taxi-out observations between the
 *   5th and 15th percentiles over a 90-day rolling window. Requires a minimum
 *   of 50 observations. Airports below threshold receive a default of 600s (10 min).
 *   Refreshed daily at 02:00Z via sp_RefreshAirportTaxiReference.
 */

require_once __DIR__ . '/../auth.php';

// Require authentication (read-only)
$auth = swim_init_auth(true, false);

// Parse request path
$request_uri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($request_uri, PHP_URL_PATH);
$path = preg_replace('#^.*/reference/taxi-times/?#', '', $path);
$path_parts = array_values(array_filter(explode('/', $path)));

$airport = !empty($path_parts[0]) ? strtoupper(trim($path_parts[0])) : null;

// Validate airport code if provided
if ($airport !== null && (strlen($airport) < 3 || strlen($airport) > 4)) {
    SwimResponse::error('Invalid airport code. Use 3-letter FAA or 4-letter ICAO.', 400, 'INVALID_AIRPORT');
}

// Query parameters
$confidence_filter = swim_get_param('confidence');
$min_samples = swim_get_int_param('min_samples', 0, 0, 100000);

// Get format parameter - supports json, fixm, xml, csv, ndjson
$format = swim_validate_format(swim_get_param('format', 'json'), 'reference');
$use_fixm = ($format === 'fixm');

// Format-specific options for output
$format_options = [
    'root' => 'swim_taxi_reference',
    'item' => 'airport',
    'name' => 'VATSWIM Taxi Reference' . ($airport ? ' - ' . $airport : ''),
    'filename' => 'swim_taxi_reference' . ($airport ? '_' . $airport : '') . '_' . date('Ymd_His')
];

// Build cache key parameters
$cache_params = array_filter([
    'airport' => $airport,
    'confidence' => $confidence_filter,
    'min_samples' => $min_samples > 0 ? (string)$min_samples : null,
    'format' => $format
], fn($v) => $v !== null && $v !== '');

// Check cache first
if (SwimResponse::tryCachedFormatted('reference', $cache_params, $format, $format_options)) {
    exit;
}

// Get ADL database connection
$conn_adl = get_conn_adl();
if (!$conn_adl) {
    SwimResponse::error('ADL database connection not available', 503, 'SERVICE_UNAVAILABLE');
}

// Methodology description included in every response
$methodology = [
    'description' => 'FAA ASPM p5-p15 average, 90-day rolling window',
    'min_samples' => 50,
    'default_taxi_sec' => 600,
    'refresh_schedule' => 'Daily at 02:00Z',
    'source' => 'VATSIM OOOI data (out_utc, off_utc)'
];

if ($airport !== null) {
    // Single airport - include detail breakdown
    handleSingleAirport($conn_adl, $airport, $confidence_filter, $use_fixm, $format, $cache_params, $format_options, $methodology);
} else {
    // List all airports
    handleAirportList($conn_adl, $confidence_filter, $min_samples, $use_fixm, $format, $cache_params, $format_options, $methodology);
}

/**
 * Handle single airport request with dimensional breakdown
 */
function handleSingleAirport($conn, $airport, $confidence_filter, $use_fixm, $format, $cache_params, $format_options, $methodology) {
    // Query main taxi reference
    $sql = "SELECT airport_icao, unimpeded_taxi_sec, sample_count, confidence,
                   percentile_5, percentile_15, last_refreshed_utc
            FROM dbo.airport_taxi_reference
            WHERE airport_icao = ?";
    $params = [$airport];

    if ($confidence_filter) {
        $sql .= " AND confidence = ?";
        $params[] = strtoupper($confidence_filter);
    }

    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        $err = sqlsrv_errors();
        SwimResponse::error('Database query failed: ' . ($err[0]['message'] ?? 'Unknown'), 500, 'DB_ERROR');
    }

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    if (!$row) {
        SwimResponse::error("No taxi reference data for airport: $airport", 404, 'NOT_FOUND');
    }

    // Format datetime fields
    foreach ($row as $key => $val) {
        if ($val instanceof DateTime) {
            $row[$key] = $val->format('c');
        }
    }

    // Rename to API field names
    $airport_data = formatAirportRow($row, $use_fixm);

    // Query detail breakdown
    $detail_sql = "SELECT dimension, dimension_value, unimpeded_taxi_sec, sample_count
                   FROM dbo.airport_taxi_reference_detail
                   WHERE airport_icao = ?
                   ORDER BY dimension, sample_count DESC";
    $detail_stmt = sqlsrv_query($conn, $detail_sql, [$airport]);

    $details = [];
    if ($detail_stmt !== false) {
        while ($detail_row = sqlsrv_fetch_array($detail_stmt, SQLSRV_FETCH_ASSOC)) {
            $details[] = formatDetailRow($detail_row, $use_fixm);
        }
        sqlsrv_free_stmt($detail_stmt);
    }

    $response_data = [
        'airport' => $airport_data,
        'details' => $details,
        'detail_count' => count($details),
        'methodology' => $methodology
    ];

    SwimResponse::formatted($response_data, $format, 'reference', $cache_params, $format_options);
}

/**
 * Handle airport list request
 */
function handleAirportList($conn, $confidence_filter, $min_samples, $use_fixm, $format, $cache_params, $format_options, $methodology) {
    $where_clauses = [];
    $params = [];

    if ($confidence_filter) {
        $where_clauses[] = "confidence = ?";
        $params[] = strtoupper($confidence_filter);
    }

    if ($min_samples > 0) {
        $where_clauses[] = "sample_count >= ?";
        $params[] = $min_samples;
    }

    $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

    $sql = "SELECT airport_icao, unimpeded_taxi_sec, sample_count, confidence,
                   percentile_5, percentile_15, last_refreshed_utc
            FROM dbo.airport_taxi_reference
            {$where_sql}
            ORDER BY airport_icao";

    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        $err = sqlsrv_errors();
        SwimResponse::error('Database query failed: ' . ($err[0]['message'] ?? 'Unknown'), 500, 'DB_ERROR');
    }

    $airports = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        foreach ($row as $key => $val) {
            if ($val instanceof DateTime) {
                $row[$key] = $val->format('c');
            }
        }
        $airports[] = formatAirportRow($row, $use_fixm);
    }
    sqlsrv_free_stmt($stmt);

    // Summary stats
    $total = count($airports);
    $high_count = 0;
    $medium_count = 0;
    $low_count = 0;
    $default_count = 0;
    $conf_key = $use_fixm ? 'confidenceLevel' : 'confidence';

    foreach ($airports as $a) {
        switch ($a[$conf_key] ?? '') {
            case 'HIGH': $high_count++; break;
            case 'MEDIUM': $medium_count++; break;
            case 'LOW': $low_count++; break;
            case 'DEFAULT': $default_count++; break;
        }
    }

    $response_data = [
        'airports' => $airports,
        'count' => $total,
        'summary' => [
            'high_confidence' => $high_count,
            'medium_confidence' => $medium_count,
            'low_confidence' => $low_count,
            'default' => $default_count
        ],
        'methodology' => $methodology
    ];

    SwimResponse::formatted($response_data, $format, 'reference', $cache_params, $format_options);
}

/**
 * Format an airport_taxi_reference row with API field names
 */
function formatAirportRow($row, $use_fixm = false) {
    if ($use_fixm) {
        return [
            'aerodromeIcao' => $row['airport_icao'],
            'unimpededTaxiOutSeconds' => (int)$row['unimpeded_taxi_sec'],
            'sampleCount' => (int)$row['sample_count'],
            'confidenceLevel' => $row['confidence'],
            'percentile5' => isset($row['percentile_5']) ? (int)$row['percentile_5'] : null,
            'percentile15' => isset($row['percentile_15']) ? (int)$row['percentile_15'] : null,
            'lastRefreshedTime' => $row['last_refreshed_utc']
        ];
    }

    return [
        'airport_icao' => $row['airport_icao'],
        'unimpeded_taxi_out_sec' => (int)$row['unimpeded_taxi_sec'],
        'sample_count' => (int)$row['sample_count'],
        'confidence' => $row['confidence'],
        'percentile_5' => isset($row['percentile_5']) ? (int)$row['percentile_5'] : null,
        'percentile_15' => isset($row['percentile_15']) ? (int)$row['percentile_15'] : null,
        'last_refreshed_utc' => $row['last_refreshed_utc']
    ];
}

/**
 * Format an airport_taxi_reference_detail row with API field names
 */
function formatDetailRow($row, $use_fixm = false) {
    if ($use_fixm) {
        return [
            'dimension' => $row['dimension'],
            'dimensionValue' => $row['dimension_value'],
            'unimpededTaxiOutSeconds' => (int)$row['unimpeded_taxi_sec'],
            'sampleCount' => (int)$row['sample_count']
        ];
    }

    return [
        'dimension' => $row['dimension'],
        'dimension_value' => $row['dimension_value'],
        'unimpeded_taxi_out_sec' => (int)$row['unimpeded_taxi_sec'],
        'sample_count' => (int)$row['sample_count']
    ];
}
