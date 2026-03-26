<?php
/**
 * Route normalization for historical route grouping.
 *
 * Strips ICAO speed/level change tokens, DCT, direct-time estimates,
 * bare slashes, and other non-routing tokens while preserving SID/STAR
 * names and runway suffixes.
 *
 * Extracts runway designators from /{RWY} tokens (SimBrief format):
 *   AMLUH2G/33  → dep_rwy=33,  procedure kept as AMLUH2G
 *   BK83A/16L   → arr_rwy=16L, procedure kept as BK83A
 *   /09R        → standalone runway token stripped
 *
 * Usage:
 *   require_once __DIR__ . '/normalize_route.php';
 *   $result = normalize_route('DEEZZ5/28 CANDR J60 DJB J60 HVE SPESA5C/07R');
 *   // $result['normalized']     => 'DEEZZ5 CANDR J60 DJB J60 HVE SPESA5C'
 *   // $result['hash']           => binary MD5
 *   // $result['waypoint_count'] => 6
 *   // $result['dep_rwy']        => '28'
 *   // $result['arr_rwy']        => '07R'
 */

/**
 * Normalize a filed route string into a canonical skeleton for grouping.
 *
 * @param string $raw Raw filed route string
 * @return array{normalized: string, hash: string, waypoint_count: int, dep_rwy: ?string, arr_rwy: ?string}
 */
function normalize_route(string $raw): array
{
    $route = strtoupper(trim($raw));

    // Sole DCT exception
    if ($route === 'DCT') {
        return [
            'normalized'     => 'DCT',
            'hash'           => md5('DCT', true),
            'waypoint_count' => 0,
            'dep_rwy'        => null,
            'arr_rwy'        => null,
        ];
    }

    $tokens = preg_split('/\s+/', $route);
    $out = [];
    $runways = [];  // collect all runway designators in order

    foreach ($tokens as $i => $token) {
        if ($token === '') {
            continue;
        }

        // Step 1: Strip initial cruise token (first token only)
        // Matches: N0450F350, K0846S1100, M078F370, etc.
        if ($i === 0 && preg_match('/^[NKM]\d{2,4}[FSA]\d{3,4}$/', $token)) {
            continue;
        }

        // Step 7: Strip bare slashes (/, //, ////, etc.)
        if (preg_match('/^\/+$/', $token)) {
            continue;
        }

        // Step 4: Strip direct-time tokens (/D0030, /D0100, etc.)
        if (preg_match('/^\/D\d{3,4}$/', $token)) {
            continue;
        }

        // Step 5: Strip standalone altitude tokens (/A040, /A015, etc.)
        if (preg_match('/^\/A\d{3}$/', $token)) {
            continue;
        }

        // Step 6: Strip speed-only notations (/N0460, /K0850, /M084 without level)
        if (preg_match('/^\/[NKM]\d{2,4}$/', $token)) {
            continue;
        }

        // Step 5b: Strip standalone runway tokens (/09R, /27L, /18, etc.)
        if (preg_match('/^\/(\d{2}[LCR]?)$/', $token, $rwyMatch)) {
            $runways[] = $rwyMatch[1];
            continue;
        }

        // Step 2: Strip mid-route speed/level appended to waypoints
        // e.g. BAYLI/N0460F360 → BAYLI, ADONI/K0898F320 → ADONI
        // SID/STAR+runway: AVBO1A/01L → keep AVBO1A, capture runway 01L
        if (strpos($token, '/') !== false) {
            // Check if this is a SID/STAR+runway suffix (/01L, /34R, /18, etc.)
            if (preg_match('/^(.+)\/(\d{2}[LRC]?)$/', $token, $rwyMatch)) {
                $out[] = $rwyMatch[1];       // keep procedure name
                $runways[] = $rwyMatch[2];   // capture runway
                continue;
            }

            // Strip speed/level change suffix
            $cleaned = preg_replace('/\/[NKM]\d{2,4}[FSA]\d{3,4}$/', '', $token);
            if ($cleaned !== '' && $cleaned !== $token) {
                $out[] = $cleaned;
                continue;
            }

            // Strip /RA/ (Random Area Navigation) tokens
            if (preg_match('/^\/RA\//', $token)) {
                continue;
            }

            // Keep other slash-containing tokens as-is
            $out[] = $token;
            continue;
        }

        // Step 3: Strip standalone DCT
        if ($token === 'DCT') {
            continue;
        }

        $out[] = $token;
    }

    // Step 9: Collapse whitespace (handled by join)
    $normalized = implode(' ', $out);

    // Edge case: if everything was stripped, use original (minus known noise)
    if ($normalized === '') {
        $normalized = $route;
    }

    // First runway found = departure, last = arrival (if different)
    $depRwy = !empty($runways) ? $runways[0] : null;
    $arrRwy = count($runways) > 1 ? $runways[count($runways) - 1] : null;

    return [
        'normalized'     => $normalized,
        'hash'           => md5($normalized, true),  // raw 16-byte binary
        'waypoint_count' => count($out),
        'dep_rwy'        => $depRwy,
        'arr_rwy'        => $arrRwy,
    ];
}
