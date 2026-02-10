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

// Normalize to TMR format
$tmis = [];
foreach ($parsed as $p) {
    $tmi = [
        'type' => $p['type'] ?? 'Other',
        'element' => $p['dest'] ?? $p['fix'] ?? $p['airport'] ?? '',
        'detail' => buildDetail($p),
        'start_utc' => isset($p['start_time']) ? $p['start_time'] . 'z' : null,
        'end_utc' => isset($p['end_time']) ? $p['end_time'] . 'z' : null,
        'facility' => $p['requestor'] ?? $p['provider'] ?? null,
    ];

    // For GS/GDP programs, use program fields
    if (isset($p['program_type'])) {
        $tmi['type'] = $p['program_type'];
        $tmi['element'] = $p['airport'] ?? $tmi['element'];
    }

    // For reroute programs
    if (isset($p['reroute_type'])) {
        $tmi['type'] = 'Reroute';
    }

    $tmis[] = $tmi;
}

echo json_encode([
    'success' => true,
    'tmis' => $tmis,
    'count' => count($tmis),
]);

function buildDetail($p) {
    $parts = [];

    if (!empty($p['fix']) && !empty($p['value'])) {
        $parts[] = 'via ' . $p['fix'] . ' ' . $p['value'] . ($p['type'] ?? '');
    }

    if (!empty($p['requestor']) || !empty($p['provider'])) {
        $fac = [];
        if (!empty($p['requestor'])) $fac[] = $p['requestor'];
        if (!empty($p['provider'])) $fac[] = $p['provider'];
        $parts[] = implode(':', $fac);
    }

    if (!empty($p['cause'])) {
        $parts[] = $p['cause'];
    }

    if (!empty($p['raw']) && empty($parts)) {
        return $p['raw'];
    }

    return implode(' ', $parts) ?: ($p['raw'] ?? '');
}
