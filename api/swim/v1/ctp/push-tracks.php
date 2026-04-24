<?php
/**
 * VATSWIM API v1 - CTP Push Tracks Endpoint
 *
 * Flowcontrol pushes track definitions to PERTI. Idempotent — re-pushing
 * same track updates it. Tracks not in the push are left unchanged.
 *
 * POST /api/swim/v1/ctp/push-tracks.php
 */

require_once __DIR__ . '/../auth.php';

global $conn_swim;
if (!$conn_swim) SwimResponse::error('SWIM database not available', 503, 'SERVICE_UNAVAILABLE');

$auth = swim_init_auth(true, true);
if (!$auth->canWriteField('ctp')) {
    SwimResponse::error('CTP write authority required', 403, 'FORBIDDEN');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    SwimResponse::error('Method not allowed', 405, 'METHOD_NOT_ALLOWED');
}

$conn_tmi = get_conn_tmi();
if (!$conn_tmi) SwimResponse::error('TMI database not available', 503, 'SERVICE_UNAVAILABLE');

$conn_adl = get_conn_adl();

$body = swim_get_json_body();
if (!$body) SwimResponse::error('Invalid JSON body', 400, 'INVALID_REQUEST');

$sessionRef = $body['session_name'] ?? $body['session_id'] ?? null;
if (!$sessionRef) SwimResponse::error('session_name or session_id required', 400, 'INVALID_REQUEST');

$tracks = $body['tracks'] ?? [];
if (!is_array($tracks) || empty($tracks)) {
    SwimResponse::error('tracks array required and must not be empty', 400, 'INVALID_REQUEST');
}

// Resolve session
require_once __DIR__ . '/../../../../load/services/CTPSlotEngine.php';
$engine = new PERTI\Services\CTPSlotEngine($conn_adl, $conn_tmi, $conn_swim);

$session = $engine->resolveSession($sessionRef);
if (!$session) SwimResponse::error('Session not found', 404, 'SESSION_NOT_FOUND');

$sessionId = (int)$session['session_id'];
$status = $session['status'] ?? '';
if (!in_array($status, ['DRAFT', 'ACTIVE'])) {
    SwimResponse::error('Session must be DRAFT or ACTIVE to push tracks', 409, 'SESSION_NOT_ACTIVE');
}

function computeRouteDistanceNm($conn_adl, string $entryFix, string $exitFix): ?float
{
    if (!$conn_adl || !$entryFix || !$exitFix) return null;

    $stmt = sqlsrv_query($conn_adl,
        "SELECT fix_name, lat, lon
         FROM dbo.nav_fixes
         WHERE fix_name IN (?, ?)
         ORDER BY fix_name",
        [$entryFix, $exitFix]
    );
    if (!$stmt) return null;

    $coords = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $coords[$row['fix_name']] = ['lat' => (float)$row['lat'], 'lon' => (float)$row['lon']];
    }
    sqlsrv_free_stmt($stmt);

    if (!isset($coords[$entryFix]) || !isset($coords[$exitFix])) return null;

    $lat1 = deg2rad($coords[$entryFix]['lat']);
    $lon1 = deg2rad($coords[$entryFix]['lon']);
    $lat2 = deg2rad($coords[$exitFix]['lat']);
    $lon2 = deg2rad($coords[$exitFix]['lon']);

    $dlat = $lat2 - $lat1;
    $dlon = $lon2 - $lon1;
    $a = sin($dlat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dlon / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $c * 3440.065; // Earth radius in nautical miles
}

$created = 0;
$updated = 0;

foreach ($tracks as $t) {
    $trackName = $t['track_name'] ?? '';
    $routeString = $t['route_string'] ?? '';
    $entryFix = $t['oceanic_entry_fix'] ?? '';
    $exitFix = $t['oceanic_exit_fix'] ?? '';
    $isActive = isset($t['is_active']) ? ($t['is_active'] ? 1 : 0) : 1;
    $maxAcph = isset($t['max_acph']) ? (int)$t['max_acph'] : 10;

    if (!$trackName || !$routeString || !$entryFix || !$exitFix) continue;

    $distNm = $t['route_distance_nm'] ?? computeRouteDistanceNm($conn_adl, $entryFix, $exitFix);

    // Check if track exists
    $stmt = sqlsrv_query($conn_tmi,
        "SELECT session_track_id FROM dbo.ctp_session_tracks
         WHERE session_id = ? AND track_name = ?",
        [$sessionId, $trackName]
    );
    $existing = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
    if ($stmt) sqlsrv_free_stmt($stmt);

    if ($existing) {
        $s = sqlsrv_query($conn_tmi,
            "UPDATE dbo.ctp_session_tracks SET
                route_string = ?, oceanic_entry_fix = ?, oceanic_exit_fix = ?,
                max_acph = ?, is_active = ?, route_distance_nm = ?,
                pushed_at = SYSUTCDATETIME(), updated_at = SYSUTCDATETIME()
             WHERE session_track_id = ?",
            [$routeString, $entryFix, $exitFix, $maxAcph, $isActive, $distNm,
             $existing['session_track_id']]
        );
        if ($s) sqlsrv_free_stmt($s);
        $updated++;
    } else {
        $s = sqlsrv_query($conn_tmi,
            "INSERT INTO dbo.ctp_session_tracks
                (session_id, track_name, route_string, oceanic_entry_fix, oceanic_exit_fix,
                 max_acph, is_active, route_distance_nm)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [$sessionId, $trackName, $routeString, $entryFix, $exitFix, $maxAcph, $isActive, $distNm]
        );
        if ($s) sqlsrv_free_stmt($s);
        $created++;
    }
}

SwimResponse::success([
    'tracks_received' => count($tracks),
    'tracks_created' => $created,
    'tracks_updated' => $updated,
]);
