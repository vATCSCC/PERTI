<?php
/**
 * Route normalization for historical route grouping.
 *
 * Strips ICAO speed/level change tokens, DCT, direct-time estimates,
 * bare slashes, and other non-routing tokens while preserving SID/STAR
 * names and runway suffixes.
 *
 * Usage:
 *   require_once __DIR__ . '/normalize_route.php';
 *   $result = normalize_route('DEEZZ5 CANDR J60 DJB CPONE JOT J60 IOW/N0459F340 J60 DBL/N0459F360 J60 HVE');
 *   // $result['normalized'] => 'DEEZZ5 CANDR J60 DJB CPONE JOT J60 IOW J60 DBL J60 HVE'
 *   // $result['hash']       => binary MD5
 *   // $result['waypoint_count'] => 11
 */

/**
 * Normalize a filed route string into a canonical skeleton for grouping.
 *
 * @param string $raw Raw filed route string
 * @return array{normalized: string, hash: string, waypoint_count: int}
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
        ];
    }

    $tokens = preg_split('/\s+/', $route);
    $out = [];

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

        // Step 2: Strip mid-route speed/level appended to waypoints
        // e.g. BAYLI/N0460F360 → BAYLI, ADONI/K0898F320 → ADONI
        // But preserve SID/STAR+runway: AVBO1A/01L, LAM94D/34L
        if (strpos($token, '/') !== false) {
            // Check if this is a SID/STAR+runway suffix (/01L, /34R, /18, etc.)
            if (preg_match('/\/\d{2}[LRC]?$/', $token)) {
                // Keep the whole token — this is a runway reference
                $out[] = $token;
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

    return [
        'normalized'     => $normalized,
        'hash'           => md5($normalized, true),  // raw 16-byte binary
        'waypoint_count' => count($out),
    ];
}
