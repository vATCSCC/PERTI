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
define('PERTI_MYSQL_ONLY', true);
include("../../../load/connect.php");
require_once __DIR__ . '/../../../load/services/NATTrackFunctions.php';

header('Content-Type: application/json; charset=utf-8');
perti_set_cors();

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

// 2. Fetch CTP overrides from TMI database (only when session_id requested —
//    avoids ~200-500ms lazy TMI connection for the common case)
$ctp_tracks = [];
if ($session_id !== null) {
    $ctp_tracks = fetchCTPTracks($session_id);
}

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

$fetched_at = null;
try {
    $stmt = $conn_pdo->prepare(
        "SELECT fetched_at FROM nat_track_cache WHERE cache_key = 'nattrak' ORDER BY fetched_at DESC LIMIT 1"
    );
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && $row['fetched_at']) {
        $fetched_at = $row['fetched_at'];
    }
} catch (Exception $e) {}

echo json_encode([
    'status'     => 'success',
    'count'      => count($merged),
    'tracks'     => $merged,
    'fetched_at' => $fetched_at,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
