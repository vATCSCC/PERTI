<?php
/**
 * NAT Track Lookup API
 *
 * Returns active NAT (North Atlantic Track) definitions.
 * Primary source: VATSIM natTrak API (https://nattrak.vatsim.net/api/tracks)
 * Override source: CTP route templates (dbo.ctp_route_templates) — CTP tracks
 * take precedence over natTrak tracks with the same letter.
 *
 * GET /api/data/playbook/nat_tracks.php               - All active NAT tracks
 * GET /api/data/playbook/nat_tracks.php?name=NATC     - Single track by name
 * GET /api/data/playbook/nat_tracks.php?session_id=X  - CTP session-specific tracks
 *
 * @version 2.0.0
 */

include("../../../load/config.php");
include("../../../load/connect.php");

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : null;
$name       = isset($_GET['name']) ? strtoupper(trim($_GET['name'])) : null;

// 1. Fetch natTrak tracks (primary source, cached 30 min in MySQL)
$nattrak_tracks = fetchNatTrakTracks();

// 2. Fetch CTP overrides from TMI database
$ctp_tracks = fetchCTPTracks($session_id);

// 3. Merge: CTP overrides natTrak for same track letter
$merged = mergeTrackSources($nattrak_tracks, $ctp_tracks);

// 4. Filter by name if requested
if ($name !== null) {
    $normalized = normalizeNATName($name);
    $merged = array_filter($merged, function($trk) use ($normalized, $name) {
        $trk_norm = normalizeNATName($trk['name']);
        return $trk_norm === $normalized || strtoupper($trk['name']) === $name;
    });
    $merged = array_values($merged);
}

echo json_encode([
    'status' => 'success',
    'count'  => count($merged),
    'tracks' => $merged,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

// ============================================================================
// natTrak API (VATSIM)
// ============================================================================

/**
 * Fetch NAT tracks from VATSIM natTrak API with MySQL-backed cache (30 min TTL).
 */
function fetchNatTrakTracks() {
    global $conn_pdo;

    // Check MySQL cache
    try {
        $stmt = $conn_pdo->prepare(
            "SELECT cache_data, fetched_at FROM nat_track_cache WHERE cache_key = 'nattrak' AND fetched_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE) LIMIT 1"
        );
        $stmt->execute();
        $cached = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($cached) {
            $data = json_decode($cached['cache_data'], true);
            if ($data !== null) {
                return $data;
            }
        }
    } catch (Exception $e) {
        // Cache table may not exist yet — proceed to fetch
    }

    // Fetch from natTrak API
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 10,
            'header'  => "Accept: application/json\r\nUser-Agent: PERTI/2.0 (perti.vatcscc.org)\r\n",
        ],
        'ssl' => ['verify_peer' => true],
    ]);

    $raw = @file_get_contents('https://nattrak.vatsim.net/api/tracks', false, $ctx);
    if ($raw === false) {
        // Fetch failed — try stale cache
        try {
            $stmt = $conn_pdo->prepare(
                "SELECT cache_data FROM nat_track_cache WHERE cache_key = 'nattrak' ORDER BY fetched_at DESC LIMIT 1"
            );
            $stmt->execute();
            $stale = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($stale) {
                $data = json_decode($stale['cache_data'], true);
                if ($data !== null) return $data;
            }
        } catch (Exception $e) {
            // no cache available
        }
        return [];
    }

    $api_tracks = json_decode($raw, true);
    if (!is_array($api_tracks)) {
        return [];
    }

    // Transform to our format
    $tracks = [];
    foreach ($api_tracks as $trk) {
        if (empty($trk['identifier']) || empty($trk['last_routeing'])) continue;
        if (isset($trk['active']) && !$trk['active']) continue;

        $letter = strtoupper($trk['identifier']);
        $name = 'NAT' . $letter;
        $route_string = trim($trk['last_routeing']);

        $flight_levels = null;
        if (!empty($trk['flight_levels']) && is_array($trk['flight_levels'])) {
            $fls = array_map(function($fl) {
                return 'FL' . str_pad((int)($fl / 100), 3, '0', STR_PAD_LEFT);
            }, $trk['flight_levels']);
            $flight_levels = implode(' ', $fls);
        }

        $tracks[] = [
            'name'           => $name,
            'route_string'   => $route_string,
            'source'         => 'nattrak',
            'direction'      => $trk['direction'] ?? null,
            'flight_levels'  => $flight_levels,
            'valid_from'     => $trk['valid_from'] ?? null,
            'valid_to'       => $trk['valid_to'] ?? null,
            'aliases'        => buildNATAliases($name),
        ];
    }

    // Store in cache
    try {
        $stmt = $conn_pdo->prepare(
            "INSERT INTO nat_track_cache (cache_key, cache_data, fetched_at)
             VALUES ('nattrak', :data, NOW())
             ON DUPLICATE KEY UPDATE cache_data = :data2, fetched_at = NOW()"
        );
        $json = json_encode($tracks, JSON_UNESCAPED_UNICODE);
        $stmt->execute([':data' => $json, ':data2' => $json]);
    } catch (Exception $e) {
        // Cache write failed — non-fatal
    }

    return $tracks;
}

// ============================================================================
// CTP Override Source
// ============================================================================

/**
 * Fetch CTP route templates from TMI database (override source).
 */
function fetchCTPTracks($session_id = null) {
    $conn_tmi = get_conn_tmi();
    if (!$conn_tmi) return [];

    $sql = "SELECT template_id, session_id, template_name, route_string,
                   altitude_range, priority, origin_filter, dest_filter,
                   created_by, created_at, updated_at
            FROM dbo.ctp_route_templates
            WHERE segment = 'OCEANIC' AND is_active = 1";
    $params = [];

    if ($session_id !== null) {
        $sql .= " AND (session_id = ? OR session_id IS NULL)";
        $params[] = $session_id;
    }

    $sql .= " ORDER BY priority ASC, template_name ASC";

    $stmt = sqlsrv_query($conn_tmi, $sql, $params);
    if ($stmt === false) return [];

    $tracks = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        foreach (['created_at', 'updated_at'] as $col) {
            if ($row[$col] instanceof DateTimeInterface) {
                $utc = clone $row[$col];
                $utc->setTimezone(new DateTimeZone('UTC'));
                $row[$col] = $utc->format('Y-m-d\TH:i:s') . 'Z';
            }
        }

        $tracks[] = [
            'name'           => $row['template_name'],
            'route_string'   => $row['route_string'],
            'source'         => 'ctp',
            'template_id'    => (int)$row['template_id'],
            'session_id'     => $row['session_id'] !== null ? (int)$row['session_id'] : null,
            'altitude_range' => $row['altitude_range'],
            'priority'       => (int)$row['priority'],
            'origin_filter'  => $row['origin_filter'] ? json_decode($row['origin_filter'], true) : null,
            'dest_filter'    => $row['dest_filter'] ? json_decode($row['dest_filter'], true) : null,
            'created_by'     => $row['created_by'],
            'created_at'     => $row['created_at'],
            'updated_at'     => $row['updated_at'],
            'aliases'        => buildNATAliases($row['template_name']),
        ];
    }
    sqlsrv_free_stmt($stmt);

    return $tracks;
}

// ============================================================================
// Merge Logic
// ============================================================================

/**
 * Merge natTrak and CTP tracks. CTP overrides natTrak for the same track letter.
 */
function mergeTrackSources($nattrak, $ctp) {
    // Index natTrak tracks by normalized name
    $by_letter = [];
    foreach ($nattrak as $trk) {
        $norm = normalizeNATName($trk['name']);
        $by_letter[$norm] = $trk;
    }

    // CTP tracks override same-letter natTrak tracks
    foreach ($ctp as $trk) {
        $norm = normalizeNATName($trk['name']);
        $by_letter[$norm] = $trk;
    }

    return array_values($by_letter);
}

// ============================================================================
// Helpers
// ============================================================================

/**
 * Normalize NAT name to canonical form (e.g., "NAT-C" -> "NATC", "TRACKB" -> "NATB").
 */
function normalizeNATName($name) {
    $upper = strtoupper(trim($name));
    // Strip common prefixes to get the letter
    $upper = preg_replace('/^(TRACK|TRK|NAT)\s*-?\s*/', 'NAT', $upper);
    return $upper;
}

/**
 * Build NAT track aliases from a canonical name.
 * e.g., "NATA" -> ["NATA", "NAT-A", "TRACKA", "TRKA", ...]
 */
function buildNATAliases($name) {
    $aliases = [$name];
    $upper = strtoupper($name);

    if (preg_match('/NAT[\s-]*([A-Z]+)/i', $upper, $m)) {
        $letter = $m[1];
        $aliases[] = 'NAT' . $letter;
        $aliases[] = 'NAT-' . $letter;
        $aliases[] = 'TRACK' . $letter;
        $aliases[] = 'TRK' . $letter;
    }

    return array_values(array_unique($aliases));
}
