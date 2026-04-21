<?php
/**
 * NAT Track Resolver
 *
 * Resolves which NAT track a CTP flight is using via hybrid approach:
 * 1. Token detection in filed route (fast path)
 * 2. Full sequence match against oceanic waypoints (fallback)
 *
 * @see docs/superpowers/specs/2026-03-21-ctp-swim-nat-track-throughput-design.md Section 5
 */

if (defined('NAT_TRACK_RESOLVER_LOADED')) return;
define('NAT_TRACK_RESOLVER_LOADED', true);

require_once __DIR__ . '/NATTrackFunctions.php';

/**
 * Resolve which NAT track a flight is using.
 *
 * @param string $filed_route       Full filed route string (may contain NAT token)
 * @param string $seg_oceanic_route Oceanic segment route (space-separated waypoints)
 * @param array  $active_tracks     Array of track arrays, each with 'name' and 'route_string'
 * @return array|null ['track' => 'NATA', 'source' => 'TOKEN'|'SEQUENCE'] or null
 */
function resolveNATTrack(
    string $filed_route,
    string $seg_oceanic_route,
    array $active_tracks
): ?array {
    // Step 1: Token detection (fast path)
    $result = resolveNATTrackByToken($filed_route, $active_tracks);
    if ($result !== null) {
        return $result;
    }

    // Step 2: Full sequence match (fallback)
    if ($seg_oceanic_route !== '') {
        $result = resolveNATTrackBySequence($seg_oceanic_route, $active_tracks);
        if ($result !== null) {
            return $result;
        }
    }

    return null;
}

/**
 * Step 1: Scan route for NAT token pattern.
 */
function resolveNATTrackByToken(string $route, array $active_tracks): ?array {
    // Pattern: NAT, TRACK, TRAK, TRK with optional hyphen, 1-5 alphanumeric chars
    if (!preg_match('/\b(?:NAT|TRACK|TRAK|TRK)-?([A-Z0-9]{1,5})\b/i', $route, $m)) {
        return null;
    }

    $identifier = strtoupper($m[1]);
    $canonical = 'NAT' . $identifier;

    // Verify track exists in active tracks
    foreach ($active_tracks as $trk) {
        $norm = normalizeNATName($trk['name']);
        if ($norm === $canonical) {
            return ['track' => $canonical, 'source' => 'TOKEN'];
        }
    }

    return null; // Token found but not in active tracks
}

/**
 * Step 2: Match flight's oceanic waypoint sequence against track route strings.
 * Requires exact full match (same fixes, same order, same count).
 */
function resolveNATTrackBySequence(string $seg_oceanic_route, array $active_tracks): ?array {
    $flight_wpts = parseWaypointSequence($seg_oceanic_route);
    if (empty($flight_wpts)) {
        return null;
    }

    $matches = [];
    foreach ($active_tracks as $trk) {
        if (empty($trk['route_string'])) continue;

        $track_wpts = parseWaypointSequence($trk['route_string']);
        if (empty($track_wpts)) continue;

        // Full match: same fixes, same order, same count
        if ($flight_wpts === $track_wpts) {
            $matches[] = normalizeNATName($trk['name']);
        }
    }

    // Exactly one match = resolved; zero or multiple = ambiguous
    if (count($matches) === 1) {
        return ['track' => $matches[0], 'source' => 'SEQUENCE'];
    }

    return null;
}

/**
 * Parse a route string into an ordered array of uppercase waypoint identifiers.
 * Strips DCT, SID/STAR tokens, airway designators — keeps only fix names.
 */
function parseWaypointSequence(string $route): array {
    $tokens = preg_split('/\s+/', strtoupper(trim($route)));
    $wpts = [];
    foreach ($tokens as $tok) {
        $tok = trim($tok);
        if ($tok === '' || $tok === 'DCT' || $tok === 'DIRECT') continue;
        // Skip airway designators (letter + digits + optional suffix like J584, UB881, N693A)
        if (preg_match('/^[A-Z]{1,2}\d{1,4}[A-Z]?$/', $tok)) continue;
        $wpts[] = $tok;
    }
    return $wpts;
}

/**
 * Get active tracks with caching for batch operations.
 * Call once per sync cycle, pass result to resolveNATTrack for each flight.
 *
 * @param int|null $session_id CTP session ID for CTP overrides
 * @return array Active track definitions
 */
function getActiveTracksForResolution(?int $session_id = null): array {
    $nattrak = fetchNatTrakTracks();
    $ctp = fetchCTPTracks($session_id);
    return mergeTrackSources($nattrak, $ctp);
}

/**
 * Resolve and persist NAT track for a single CTP flight.
 *
 * @param resource $conn_tmi  Azure SQL connection to VATSIM_TMI
 * @param int      $ctp_control_id
 * @param string   $filed_route
 * @param string   $seg_oceanic_route
 * @param array    $active_tracks
 * @return array|null Resolution result or null
 */
function resolveAndPersistNATTrack(
    $conn_tmi,
    int $ctp_control_id,
    string $filed_route,
    string $seg_oceanic_route,
    array $active_tracks
): ?array {
    $result = resolveNATTrack($filed_route, $seg_oceanic_route, $active_tracks);

    if ($result !== null) {
        $sql = "UPDATE dbo.ctp_flight_control
                SET resolved_nat_track = ?,
                    nat_track_resolved_at = SYSUTCDATETIME(),
                    nat_track_source = ?,
                    swim_push_version = swim_push_version + 1
                WHERE ctp_control_id = ?";
        sqlsrv_query($conn_tmi, $sql, [$result['track'], $result['source'], $ctp_control_id]);
    }

    return $result;
}
