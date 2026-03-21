<?php
/**
 * VATSWIM API v1 - NAT Track Throughput Metrics
 *
 * Pre-computed NAT track throughput metrics for external consumers
 * (CANOC, ECFMP, third-party tools). Returns binned flight counts,
 * compliance rates, delay averages, and per-track totals.
 *
 * Data Source: SWIM_API (dbo.swim_nat_track_metrics)
 *
 * @version 1.0.0
 */

require_once __DIR__ . '/../../auth.php';

global $conn_swim;

if (!$conn_swim) {
    SwimResponse::error('SWIM database unavailable', 503, 'SERVICE_UNAVAILABLE');
}

$auth = swim_init_auth(true, false);

// --------------------------------------------------------------------------
// Parameters
// --------------------------------------------------------------------------

$session_id = swim_get_int_param('session_id', 0);
if ($session_id <= 0) {
    SwimResponse::error('session_id is required and must be a positive integer', 400, 'INVALID_PARAMETER');
}

$track_filter = swim_get_param('track');
$bin_min      = swim_get_int_param('bin_min', 15);
$from         = swim_get_param('from');
$to           = swim_get_param('to');
$direction    = swim_get_param('direction');

// Validate bin_min
$allowed_bins = [15, 30, 60];
if (!in_array($bin_min, $allowed_bins, true)) {
    SwimResponse::error('bin_min must be 15, 30, or 60', 400, 'INVALID_PARAMETER');
}

// Validate direction
if ($direction !== null) {
    $direction = strtoupper($direction);
    if (!in_array($direction, ['WESTBOUND', 'EASTBOUND'], true)) {
        SwimResponse::error('direction must be WESTBOUND or EASTBOUND', 400, 'INVALID_PARAMETER');
    }
}

// --------------------------------------------------------------------------
// Build query for 15-min base rows
// --------------------------------------------------------------------------

$where   = ['m.session_id = ?'];
$params  = [$session_id];

if ($track_filter) {
    $track_names = array_map('trim', explode(',', strtoupper($track_filter)));
    $placeholders = implode(',', array_fill(0, count($track_names), '?'));
    $where[] = "m.track_name IN ($placeholders)";
    $params  = array_merge($params, $track_names);
}

if ($direction) {
    $where[]  = 'm.direction = ?';
    $params[] = $direction;
}

if ($from) {
    $where[]  = 'm.bin_start_utc >= ?';
    $params[] = $from;
}

if ($to) {
    $where[]  = 'm.bin_end_utc <= ?';
    $params[] = $to;
}

$sql = "
    SELECT
        m.track_name,
        m.direction,
        m.bin_start_utc,
        m.bin_end_utc,
        m.flight_count,
        m.slotted_count,
        m.compliant_count,
        m.avg_delay_min,
        m.peak_rate_hr,
        m.flight_levels_json,
        m.origins_json,
        m.destinations_json
    FROM dbo.swim_nat_track_metrics m
    WHERE " . implode(' AND ', $where) . "
    ORDER BY m.track_name, m.direction, m.bin_start_utc
";

$stmt = sqlsrv_query($conn_swim, $sql, $params);
if ($stmt === false) {
    $errors = sqlsrv_errors();
    error_log('SWIM NAT metrics query error: ' . ($errors[0]['message'] ?? 'Unknown'));
    SwimResponse::error('Database query failed', 500, 'QUERY_ERROR');
}

// --------------------------------------------------------------------------
// Fetch all rows and normalize DateTime objects
// --------------------------------------------------------------------------

$rows = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    foreach (['bin_start_utc', 'bin_end_utc'] as $col) {
        if ($row[$col] instanceof \DateTime) {
            $row[$col] = $row[$col]->format('Y-m-d\TH:i:s') . 'Z';
        }
    }
    $rows[] = $row;
}
sqlsrv_free_stmt($stmt);

// --------------------------------------------------------------------------
// Aggregate rows if bin_min > 15  (PHP-side merge for JSON columns)
// --------------------------------------------------------------------------

if ($bin_min > 15) {
    $rows = aggregateMetricRows($rows, $bin_min);
}

// --------------------------------------------------------------------------
// Group by track_name + direction and compute totals
// --------------------------------------------------------------------------

$tracks_map = [];

foreach ($rows as $row) {
    $key = $row['track_name'] . '|' . $row['direction'];

    if (!isset($tracks_map[$key])) {
        $tracks_map[$key] = [
            'track_name' => $row['track_name'],
            'direction'  => $row['direction'],
            'bins'       => [],
            '_total_flights'   => 0,
            '_weighted_delay'  => 0.0,
            '_total_slotted'   => 0,
            '_total_compliant' => 0,
        ];
    }

    $flight_count   = (int)$row['flight_count'];
    $slotted_count  = (int)$row['slotted_count'];
    $compliant_count = (int)$row['compliant_count'];
    $avg_delay      = (float)$row['avg_delay_min'];
    $peak_rate      = (int)$row['peak_rate_hr'];

    $flight_levels = decodeJsonColumn($row['flight_levels_json'] ?? null);
    $origins       = decodeJsonColumn($row['origins_json'] ?? null);
    $destinations  = decodeJsonColumn($row['destinations_json'] ?? null);

    $tracks_map[$key]['bins'][] = [
        'bin_start'       => $row['bin_start_utc'],
        'bin_end'         => $row['bin_end_utc'],
        'flight_count'    => $flight_count,
        'slotted_count'   => $slotted_count,
        'compliant_count' => $compliant_count,
        'avg_delay_min'   => round($avg_delay, 1),
        'peak_rate_hr'    => $peak_rate,
        'flight_levels'   => $flight_levels,
        'origins'         => $origins,
        'destinations'    => $destinations,
    ];

    $tracks_map[$key]['_total_flights']   += $flight_count;
    $tracks_map[$key]['_weighted_delay']  += $avg_delay * $flight_count;
    $tracks_map[$key]['_total_slotted']   += $slotted_count;
    $tracks_map[$key]['_total_compliant'] += $compliant_count;
}

// Build final tracks array with totals
$tracks = [];
foreach ($tracks_map as $entry) {
    $total   = $entry['_total_flights'];
    $slotted = $entry['_total_slotted'];

    $compliance_pct = ($slotted > 0)
        ? round(($entry['_total_compliant'] / $slotted) * 100, 1)
        : 0.0;

    $avg_delay = ($total > 0)
        ? round($entry['_weighted_delay'] / $total, 1)
        : 0.0;

    $tracks[] = [
        'track_name' => $entry['track_name'],
        'direction'  => $entry['direction'],
        'bins'       => $entry['bins'],
        'totals'     => [
            'total_flights'  => $total,
            'avg_delay_min'  => $avg_delay,
            'compliance_pct' => $compliance_pct,
        ],
    ];
}

SwimResponse::success([
    'session_id' => $session_id,
    'bin_min'    => $bin_min,
    'tracks'     => $tracks,
]);

// ==========================================================================
// Helper functions
// ==========================================================================

/**
 * Aggregate 15-min metric rows into larger time buckets (30 or 60 min).
 *
 * Groups by track_name, direction, and a floored time bucket. JSON array
 * columns (flight_levels, origins, destinations) are merged and de-duped
 * in PHP rather than in SQL.
 *
 * @param array $rows  Flat rows from the database, already DateTime-normalized.
 * @param int   $bin_min  Target bin size in minutes (30 or 60).
 * @return array  Aggregated rows.
 */
function aggregateMetricRows(array $rows, int $bin_min): array {
    $buckets = [];

    foreach ($rows as $row) {
        $ts = strtotime($row['bin_start_utc']);
        // Floor to the nearest $bin_min boundary
        $floored = $ts - ($ts % ($bin_min * 60));
        $bucket_start = gmdate('Y-m-d\TH:i:s', $floored) . 'Z';
        $bucket_end   = gmdate('Y-m-d\TH:i:s', $floored + $bin_min * 60) . 'Z';

        $key = $row['track_name'] . '|' . $row['direction'] . '|' . $bucket_start;

        if (!isset($buckets[$key])) {
            $buckets[$key] = [
                'track_name'       => $row['track_name'],
                'direction'        => $row['direction'],
                'bin_start_utc'    => $bucket_start,
                'bin_end_utc'      => $bucket_end,
                'flight_count'     => 0,
                'slotted_count'    => 0,
                'compliant_count'  => 0,
                'weighted_delay'   => 0.0,
                'peak_rate_hr'     => 0,
                'flight_levels'    => [],
                'origins'          => [],
                'destinations'     => [],
            ];
        }

        $fc = (int)$row['flight_count'];
        $b  = &$buckets[$key];

        $b['flight_count']    += $fc;
        $b['slotted_count']   += (int)$row['slotted_count'];
        $b['compliant_count'] += (int)$row['compliant_count'];
        $b['weighted_delay']  += (float)$row['avg_delay_min'] * $fc;
        $b['peak_rate_hr']     = max($b['peak_rate_hr'], (int)$row['peak_rate_hr']);

        // Merge JSON array columns
        $b['flight_levels'] = array_merge($b['flight_levels'], decodeJsonColumn($row['flight_levels_json'] ?? null));
        $b['origins']       = array_merge($b['origins'],       decodeJsonColumn($row['origins_json'] ?? null));
        $b['destinations']  = array_merge($b['destinations'],  decodeJsonColumn($row['destinations_json'] ?? null));

        unset($b);
    }

    // Finalize: compute weighted avg and de-dup JSON arrays
    $result = [];
    foreach ($buckets as $b) {
        $fc = $b['flight_count'];
        $result[] = [
            'track_name'       => $b['track_name'],
            'direction'        => $b['direction'],
            'bin_start_utc'    => $b['bin_start_utc'],
            'bin_end_utc'      => $b['bin_end_utc'],
            'flight_count'     => $fc,
            'slotted_count'    => $b['slotted_count'],
            'compliant_count'  => $b['compliant_count'],
            'avg_delay_min'    => ($fc > 0) ? round($b['weighted_delay'] / $fc, 1) : 0.0,
            'peak_rate_hr'     => $b['peak_rate_hr'],
            'flight_levels_json' => json_encode(array_values(array_unique($b['flight_levels']))),
            'origins_json'       => json_encode(array_values(array_unique($b['origins']))),
            'destinations_json'  => json_encode(array_values(array_unique($b['destinations']))),
        ];
    }

    // Preserve ordering: track_name, direction, bin_start_utc
    usort($result, function ($a, $b) {
        return strcmp($a['track_name'], $b['track_name'])
            ?: strcmp($a['direction'], $b['direction'])
            ?: strcmp($a['bin_start_utc'], $b['bin_start_utc']);
    });

    return $result;
}

/**
 * Decode a JSON column value into an array, returning [] on failure.
 *
 * @param string|null $json  Raw JSON string or null.
 * @return array
 */
function decodeJsonColumn($json): array {
    if ($json === null || $json === '') {
        return [];
    }
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}
