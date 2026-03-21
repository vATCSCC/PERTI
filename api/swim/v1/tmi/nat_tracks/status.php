<?php
/**
 * VATSWIM API v1 - NAT Track Status
 *
 * Live snapshot of NAT track definitions (from VATSIM natTrak API)
 * with optional real-time occupancy from CTP throughput metrics.
 *
 * This endpoint runs under PERTI_SWIM_ONLY (set by auth.php) so MySQL,
 * TMI, ADL, and GIS connections are NOT available. Track definitions
 * are fetched directly from the natTrak HTTP API with APCu caching.
 *
 * Data Sources:
 * - Track definitions: VATSIM natTrak API (https://nattrak.vatsim.net/api/tracks)
 * - Occupancy/metrics: SWIM_API (dbo.swim_nat_track_metrics)
 * - CTP tracks:        SWIM_API (dbo.swim_flights resolved_nat_track)
 *
 * @version 1.0.0
 */

require_once __DIR__ . '/../../auth.php';

global $conn_swim;

if (!$conn_swim) {
    SwimResponse::error('SWIM database unavailable', 503, 'SERVICE_UNAVAILABLE');
}

// Public access (no auth required), but handle preflight
$auth = swim_init_auth(false);

// --------------------------------------------------------------------------
// Parameters
// --------------------------------------------------------------------------

$session_id = swim_get_int_param('session_id', 0);
$source     = swim_get_param('source', 'all');

$allowed_sources = ['nattrak', 'ctp', 'all'];
if (!in_array($source, $allowed_sources, true)) {
    SwimResponse::error('source must be nattrak, ctp, or all', 400, 'INVALID_PARAMETER');
}

// --------------------------------------------------------------------------
// 1. Fetch natTrak track definitions via HTTP (APCu cached, 5-min TTL)
// --------------------------------------------------------------------------

$nattrak_tracks = [];
if ($source === 'nattrak' || $source === 'all') {
    $nattrak_tracks = fetchNatTrakTracksHttp();
}

// --------------------------------------------------------------------------
// 2. Fetch CTP tracks from SWIM if session_id provided
// --------------------------------------------------------------------------

$ctp_tracks = [];
if ($session_id > 0 && ($source === 'ctp' || $source === 'all')) {
    $ctp_tracks = fetchCtpTracksFromSwim($conn_swim, $session_id);
}

// --------------------------------------------------------------------------
// 3. Fetch current occupancy if session_id provided
// --------------------------------------------------------------------------

$occupancy = [];
if ($session_id > 0) {
    $occupancy = fetchCurrentOccupancy($conn_swim, $session_id);
}

// --------------------------------------------------------------------------
// 4. Build merged track list
// --------------------------------------------------------------------------

$tracks = [];

foreach ($nattrak_tracks as $trk) {
    $name = $trk['name'];
    $entry = [
        'track_name'      => $name,
        'route_string'    => $trk['route_string'],
        'source'          => 'nattrak',
        'direction'       => $trk['direction'],
        'flight_levels'   => $trk['flight_levels'],
        'valid_from'      => $trk['valid_from'],
        'valid_to'        => $trk['valid_to'],
        'current_flights' => null,
        'current_rate_hr' => null,
        'slotted_pct'     => null,
    ];

    if (isset($occupancy[$name])) {
        $occ = $occupancy[$name];
        $entry['current_flights'] = $occ['flight_count'];
        $entry['current_rate_hr'] = $occ['peak_rate_hr'];
        $entry['slotted_pct']     = $occ['slotted_pct'];
    }

    $tracks[] = $entry;
}

foreach ($ctp_tracks as $trk) {
    $name = $trk['track_name'];
    $entry = [
        'track_name'      => $name,
        'route_string'    => $trk['route_string'] ?? null,
        'source'          => 'ctp',
        'direction'       => $trk['direction'] ?? null,
        'flight_levels'   => null,
        'valid_from'      => null,
        'valid_to'        => null,
        'current_flights' => $trk['flight_count'],
        'current_rate_hr' => null,
        'slotted_pct'     => null,
    ];

    if (isset($occupancy[$name])) {
        $occ = $occupancy[$name];
        $entry['current_rate_hr'] = $occ['peak_rate_hr'];
        $entry['slotted_pct']     = $occ['slotted_pct'];
    }

    $tracks[] = $entry;
}

SwimResponse::success([
    'tracks'     => $tracks,
    'fetched_at' => gmdate('Y-m-d\TH:i:s') . 'Z',
]);

// ==========================================================================
// Helper functions
// ==========================================================================

/**
 * Fetch NAT tracks from the VATSIM natTrak API with APCu caching.
 *
 * Unlike NATTrackFunctions::fetchNatTrakTracks() which uses MySQL for its
 * cache, this variant runs under PERTI_SWIM_ONLY where MySQL is unavailable.
 * Falls back to direct HTTP fetch when APCu is not installed.
 *
 * @return array  Normalized track list.
 */
function fetchNatTrakTracksHttp(): array {
    $cache_key = 'swim_nattrak_tracks';
    $cache_ttl = 300; // 5 minutes

    // Try APCu cache
    if (function_exists('apcu_fetch')) {
        $cached = apcu_fetch($cache_key, $hit);
        if ($hit && is_array($cached)) {
            return $cached;
        }
    }

    $ctx = stream_context_create([
        'http' => [
            'timeout' => 10,
            'header'  => "Accept: application/json\r\nUser-Agent: PERTI-SWIM/1.0 (perti.vatcscc.org)\r\n",
        ],
        'ssl' => ['verify_peer' => true],
    ]);

    $raw = @file_get_contents('https://nattrak.vatsim.net/api/tracks', false, $ctx);
    if ($raw === false) {
        return [];
    }

    $api_tracks = json_decode($raw, true);
    if (!is_array($api_tracks)) {
        return [];
    }

    $tracks = [];
    foreach ($api_tracks as $trk) {
        if (empty($trk['identifier']) || empty($trk['last_routeing'])) {
            continue;
        }
        if (isset($trk['active']) && !$trk['active']) {
            continue;
        }

        $letter = strtoupper($trk['identifier']);
        $name   = 'NAT' . $letter;

        $flight_levels = null;
        if (!empty($trk['flight_levels']) && is_array($trk['flight_levels'])) {
            $fls = array_map(function ($fl) {
                return 'FL' . str_pad((int)($fl / 100), 3, '0', STR_PAD_LEFT);
            }, $trk['flight_levels']);
            $flight_levels = implode(' ', $fls);
        }

        $tracks[] = [
            'name'          => $name,
            'route_string'  => trim($trk['last_routeing']),
            'direction'     => $trk['direction'] ?? null,
            'flight_levels' => $flight_levels,
            'valid_from'    => $trk['valid_from'] ?? null,
            'valid_to'      => $trk['valid_to'] ?? null,
        ];
    }

    // Store in APCu if available
    if (function_exists('apcu_store')) {
        apcu_store($cache_key, $tracks, $cache_ttl);
    }

    return $tracks;
}

/**
 * Fetch CTP-source tracks from swim_nat_track_metrics for a given session.
 *
 * swim_flights only has resolved_nat_track (no session_id or direction).
 * swim_nat_track_metrics has session_id, track_name, and direction — use
 * it to derive the CTP track list by summing across all time bins.
 *
 * @param resource $conn_swim  SWIM database connection.
 * @param int      $session_id CTP session ID.
 * @return array  Track entries with aggregated flight counts.
 */
function fetchCtpTracksFromSwim($conn_swim, int $session_id): array {
    $sql = "
        SELECT
            m.track_name,
            m.direction,
            SUM(m.flight_count) AS flight_count
        FROM dbo.swim_nat_track_metrics m
        WHERE m.session_id = ?
        GROUP BY m.track_name, m.direction
        ORDER BY m.track_name
    ";

    $stmt = sqlsrv_query($conn_swim, $sql, [$session_id]);
    if ($stmt === false) {
        return [];
    }

    $tracks = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $tracks[] = [
            'track_name'   => $row['track_name'],
            'direction'    => $row['direction'],
            'route_string' => null,
            'flight_count' => (int)$row['flight_count'],
        ];
    }
    sqlsrv_free_stmt($stmt);

    return $tracks;
}

/**
 * Fetch the current 15-minute occupancy window from swim_nat_track_metrics.
 *
 * Returns per-track occupancy keyed by track_name for easy lookup.
 *
 * @param resource $conn_swim  SWIM database connection.
 * @param int      $session_id CTP session ID.
 * @return array  Associative array keyed by track_name.
 */
function fetchCurrentOccupancy($conn_swim, int $session_id): array {
    $sql = "
        SELECT
            m.track_name,
            m.flight_count,
            m.slotted_count,
            m.peak_rate_hr
        FROM dbo.swim_nat_track_metrics m
        WHERE m.session_id = ?
          AND m.bin_start_utc <= GETUTCDATE()
          AND m.bin_end_utc   >  GETUTCDATE()
    ";

    $stmt = sqlsrv_query($conn_swim, $sql, [$session_id]);
    if ($stmt === false) {
        return [];
    }

    $result = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $name = $row['track_name'];
        $fc   = (int)$row['flight_count'];
        $sc   = (int)$row['slotted_count'];

        $result[$name] = [
            'flight_count' => $fc,
            'peak_rate_hr' => (int)$row['peak_rate_hr'],
            'slotted_pct'  => ($fc > 0) ? round(($sc / $fc) * 100, 1) : 0.0,
        ];
    }
    sqlsrv_free_stmt($stmt);

    return $result;
}
