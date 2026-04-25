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

/**
 * Compute total route distance in NM through all waypoints.
 *
 * Parses the route string to resolve all waypoints (coordinate tokens and
 * named fixes). Named fixes are disambiguated using proximity to the nearest
 * coordinate waypoint, matching the pattern used by PostGIS resolve_waypoint()
 * (migration 004/020) and sp_ParseRoute in Azure SQL.
 *
 * NAT half-degree format: DDDD[NS] where first 2 digits = latitude degrees,
 * remaining digits = longitude degrees (west for N, east for S).
 * Examples: 4750N = 47N 50W, 4917N = 49N 17W, 2150N = 21N 50W
 */
function computeRouteDistanceNm($conn_adl, string $routeString, string $entryFix, string $exitFix): ?float
{
    if (!$conn_adl || !$routeString || !$entryFix || !$exitFix) return null;

    $tokens = preg_split('/\s+/', trim($routeString));
    if (count($tokens) < 2) return null;

    // Phase 1: Parse tokens into waypoints, resolving coordinate tokens immediately
    $waypoints = [];
    foreach ($tokens as $token) {
        $token = strtoupper(trim($token));
        if ($token === '') continue;

        // NAT half-degree: DDDD[NS] or DDDDD[NS]
        // 4750N = 47N 50W, 4917N = 49N 17W, 2150N = 21N 50W
        if (preg_match('/^(\d{2})(\d{2,3})([NS])$/', $token, $m)) {
            $lat = (float)$m[1];
            $lon = (float)$m[2];
            if ($m[3] === 'N') $lon = -$lon; // West longitude for North Atlantic
            if ($m[3] === 'S') $lat = -$lat;
            $waypoints[] = ['name' => $token, 'lat' => $lat, 'lon' => $lon];
            continue;
        }

        // Skip speed/altitude restrictions, airway references with route segments
        if (preg_match('/^[A-Z]{1,2}\d{1,4}[A-Z]?$/', $token) && !preg_match('/^[A-Z]{3,5}$/', $token)) {
            // Looks like an airway — skip for distance calculation
            continue;
        }

        $waypoints[] = ['name' => $token, 'lat' => null, 'lon' => null];
    }

    // Phase 2: Resolve named fixes using proximity to nearest coordinate waypoint
    // Same approach as PostGIS resolve_waypoint(fix, context_lat, context_lon)
    $contextLat = null;
    $contextLon = null;

    // Find first coordinate waypoint for initial context
    foreach ($waypoints as $wp) {
        if ($wp['lat'] !== null) {
            $contextLat = $wp['lat'];
            $contextLon = $wp['lon'];
            break;
        }
    }

    foreach ($waypoints as &$wp) {
        if ($wp['lat'] !== null) {
            // Update context from known coordinates
            $contextLat = $wp['lat'];
            $contextLon = $wp['lon'];
            continue;
        }

        // Resolve named fix with proximity disambiguation
        if ($contextLat !== null) {
            $stmt = sqlsrv_query($conn_adl,
                "SELECT TOP 1 fix_name, lat, lon FROM dbo.nav_fixes
                 WHERE fix_name = ?
                 ORDER BY POWER(lat - ?, 2) +
                          POWER((lon - ?) * COS(RADIANS(?)), 2)",
                [$wp['name'], $contextLat, $contextLon, $contextLat]
            );
        } else {
            // No context: prefer northern/western hemisphere (PostGIS migration 020 pattern)
            $stmt = sqlsrv_query($conn_adl,
                "SELECT TOP 1 fix_name, lat, lon FROM dbo.nav_fixes
                 WHERE fix_name = ? ORDER BY lat DESC, lon ASC",
                [$wp['name']]
            );
        }

        if ($stmt) {
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt);
            if ($row) {
                $wp['lat'] = (float)$row['lat'];
                $wp['lon'] = (float)$row['lon'];
                $contextLat = $wp['lat'];
                $contextLon = $wp['lon'];
            }
        }
    }
    unset($wp);

    // Phase 3: Sum great-circle distances through all resolved waypoints
    $totalDist = 0.0;
    $prevLat = null;
    $prevLon = null;

    foreach ($waypoints as $wp) {
        if ($wp['lat'] === null || $wp['lon'] === null) continue;
        if ($prevLat !== null) {
            $totalDist += haversineNm($prevLat, $prevLon, $wp['lat'], $wp['lon']);
        }
        $prevLat = $wp['lat'];
        $prevLon = $wp['lon'];
    }

    return $totalDist > 0 ? $totalDist : null;
}

function haversineNm(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $lat1 = deg2rad($lat1);
    $lon1 = deg2rad($lon1);
    $lat2 = deg2rad($lat2);
    $lon2 = deg2rad($lon2);
    $dlat = $lat2 - $lat1;
    $dlon = $lon2 - $lon1;
    $a = sin($dlat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dlon / 2) ** 2;
    return 2 * atan2(sqrt($a), sqrt(1 - $a)) * 3440.065;
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

    $distNm = $t['route_distance_nm'] ?? computeRouteDistanceNm($conn_adl, $routeString, $entryFix, $exitFix);

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
