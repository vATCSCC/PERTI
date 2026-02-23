<?php
/**
 * TMR NTML Parsing API - Server-side NTML text parsing
 *
 * POST { "text": "..." } — Parse NTML/ADVZY text into structured TMIs
 *
 * Uses parse_tmi_text() from tmi_config.php for full ADVZY block handling.
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$text = trim($input['text'] ?? '');

if (empty($text)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No text provided']);
    exit;
}

// Include the parser
require_once __DIR__ . '/../../analysis/tmi_config.php';

$event_start = $input['event_start'] ?? null;
$parsed = parse_tmi_text($text, $event_start);

// Pass through all parsed fields with category + element + normalized times
$tmis = [];
foreach ($parsed as $p) {
    // Determine category
    $type = $p['type'] ?? 'Other';
    switch ($type) {
        case 'GS_PROGRAM':
            $category = 'program';
            $type = 'GS';
            break;
        case 'GS':
        case 'GS_CNX':
        case 'GDP':
        case 'AFP':
            $category = 'program';
            break;
        case 'REROUTE_PROGRAM':
            $category = 'reroute';
            $type = 'Reroute';
            break;
        case 'REROUTE':
        case 'REROUTE_CNX':
            $category = 'reroute';
            break;
        default:
            $category = 'ntml';
            break;
    }

    // For program types, also override type from program_type field
    if (isset($p['program_type'])) {
        $type = $p['program_type'];
        $category = 'program';
    }
    if (isset($p['reroute_type'])) {
        $type = 'Reroute';
        $category = 'reroute';
    }

    // Resolve element via field fallback
    $element = $p['dest'] ?? $p['airport'] ?? $p['fix'] ?? $p['ctl_element'] ?? '';

    // Normalize times: start_time/end_time or effective_start/effective_end → start_utc/end_utc
    $start_utc = null;
    $end_utc = null;
    if (isset($p['start_time'])) {
        $start_utc = $p['start_time'] . 'z';
    } elseif (isset($p['effective_start'])) {
        $start_utc = $p['effective_start'] . 'z';
    }
    if (isset($p['end_time'])) {
        $end_utc = $p['end_time'] . 'z';
    } elseif (isset($p['effective_end'])) {
        $end_utc = $p['effective_end'] . 'z';
    }

    // Build entry: all parser fields + overrides
    $tmi = $p;
    $tmi['type'] = $type;
    $tmi['category'] = $category;
    $tmi['element'] = $element;
    $tmi['start_utc'] = $start_utc;
    $tmi['end_utc'] = $end_utc;

    $tmis[] = $tmi;
}

echo json_encode([
    'success' => true,
    'tmis' => $tmis,
    'count' => count($tmis),
]);
