<?php
/**
 * TMR Weather API - Fetch historical METARs for plan airports
 *
 * GET ?p_id=N — Fetches METARs from Iowa Environmental Mesonet (IEM) ASOS archive
 *               for all airports in the plan's event window.
 *
 * Returns JSON: { success, airports: { "KJFK": { metars: [...], dominant_category } } }
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

require_once __DIR__ . '/../../../load/config.php';
define('PERTI_MYSQL_ONLY', true);
require_once __DIR__ . '/../../../load/connect.php';

$p_id = get_int('p_id');
if (!$p_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing p_id']);
    exit;
}

// Get plan event window
$stmt = $conn_pdo->prepare("SELECT event_date, event_start, event_end_date, event_end_time FROM p_plans WHERE id = ?");
$stmt->execute([$p_id]);
$plan = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$plan) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Plan not found']);
    exit;
}

// Get airports from p_configs
$stmt = $conn_pdo->prepare("SELECT DISTINCT airport FROM p_configs WHERE p_id = ?");
$stmt->execute([$p_id]);
$airports = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (empty($airports)) {
    echo json_encode(['success' => true, 'airports' => new \stdClass(), 'warning' => 'No airports configured']);
    exit;
}

// Build UTC time range
$dateStart = $plan['event_date'] . ' ' . ($plan['event_start'] ?: '00:00');
$dateEnd = ($plan['event_end_date'] ?: $plan['event_date']) . ' ' . ($plan['event_end_time'] ?: '23:59');

$startTs = strtotime($dateStart . ' UTC');
$endTs = strtotime($dateEnd . ' UTC');

// Pad by 30 min each side
$startTs -= 1800;
$endTs += 1800;

$results = [];

foreach ($airports as $apt) {
    // Convert ICAO to FAA station ID for IEM (strip leading K for US airports)
    $station = $apt;
    if (strlen($station) === 4 && $station[0] === 'K') {
        $station = substr($station, 1);
    }

    $url = sprintf(
        'https://mesonet.agron.iastate.edu/cgi-bin/request/asos.py?station=%s&data=metar&tz=Etc/UTC&format=onlycomma&year1=%s&month1=%s&day1=%s&hour1=%s&minute1=0&year2=%s&month2=%s&day2=%s&hour2=%s&minute2=59',
        urlencode($station),
        date('Y', $startTs), date('m', $startTs), date('d', $startTs), date('H', $startTs),
        date('Y', $endTs), date('m', $endTs), date('d', $endTs), date('H', $endTs)
    );

    $ctx = stream_context_create(['http' => ['timeout' => 15, 'ignore_errors' => true]]);
    $csv = @file_get_contents($url, false, $ctx);

    if ($csv === false) {
        $results[$apt] = ['metars' => [], 'dominant_category' => null, 'error' => 'Fetch failed'];
        continue;
    }

    // Parse CSV: station,valid,metar
    $lines = explode("\n", trim($csv));
    $metars = [];
    $categories = [];

    foreach ($lines as $line) {
        if (strpos($line, 'station,') === 0) continue; // header
        $parts = str_getcsv($line);
        if (count($parts) < 3) continue;

        $metar_text = trim($parts[2]);
        if (empty($metar_text)) continue;

        $cat = classifyMetar($metar_text);
        $metars[] = [
            'time_utc' => $parts[1],
            'metar' => $metar_text,
            'category' => $cat,
        ];
        $categories[] = $cat;
    }

    // Determine dominant category
    $dominant = null;
    if (!empty($categories)) {
        $counts = array_count_values($categories);
        arsort($counts);
        $dominant = array_key_first($counts);
    }

    $results[$apt] = [
        'metars' => $metars,
        'dominant_category' => $dominant,
        'count' => count($metars),
    ];
}

echo json_encode([
    'success' => true,
    'airports' => empty($results) ? new \stdClass() : $results,
]);

/**
 * Classify METAR text into flight category (VFR/MVFR/IFR/LIFR)
 * Based on ceiling and visibility thresholds per FAA standards.
 */
function classifyMetar($metar) {
    $ceiling = extractCeiling($metar);
    $vis = extractVisibility($metar);

    // LIFR: ceiling < 500 or vis < 1
    if (($ceiling !== null && $ceiling < 500) || ($vis !== null && $vis < 1)) return 'LIFR';
    // IFR: ceiling 500-999 or vis 1-2.99
    if (($ceiling !== null && $ceiling < 1000) || ($vis !== null && $vis < 3)) return 'IFR';
    // MVFR: ceiling 1000-2999 or vis 3-4.99
    if (($ceiling !== null && $ceiling < 3000) || ($vis !== null && $vis < 5)) return 'MVFR';
    // VFR
    return 'VFR';
}

function extractCeiling($metar) {
    // Look for BKN or OVC cloud layers
    if (preg_match_all('/(BKN|OVC)(\d{3})/i', $metar, $matches)) {
        $lowest = PHP_INT_MAX;
        foreach ($matches[2] as $alt) {
            $ft = intval($alt) * 100;
            if ($ft < $lowest) $lowest = $ft;
        }
        return $lowest === PHP_INT_MAX ? null : $lowest;
    }
    // VV (vertical visibility) counts as ceiling
    if (preg_match('/VV(\d{3})/i', $metar, $m)) {
        return intval($m[1]) * 100;
    }
    return null;
}

function extractVisibility($metar) {
    // Statute miles: "3SM", "1/2SM", "1 1/2SM", "P6SM", "10SM"
    if (preg_match('/\bP?(\d+)\s+(\d+)\/(\d+)SM\b/', $metar, $m)) {
        return intval($m[1]) + intval($m[2]) / intval($m[3]);
    }
    if (preg_match('/\b(\d+)\/(\d+)SM\b/', $metar, $m)) {
        return intval($m[1]) / intval($m[2]);
    }
    if (preg_match('/\bP6SM\b/', $metar)) {
        return 7;
    }
    if (preg_match('/\b(\d+)SM\b/', $metar, $m)) {
        return intval($m[1]);
    }
    return null;
}
