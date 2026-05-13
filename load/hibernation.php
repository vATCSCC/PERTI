<?php
/**
 * Hibernation Mode Check
 *
 * Included from config.php. When HIBERNATION_MODE is enabled:
 * - Redirects hibernated web pages to /hibernation info page
 * - Tracks hit counts for demand analysis (hibernation_hits table)
 * - SWIM API endpoints are exempt (remain fully operational)
 *
 * When DEEP_HIBERNATION_MODE is also enabled:
 * - SWIM pages additionally redirect to /hibernation
 * - SWIM API endpoints return 503 JSON
 *
 * @see docs/operations/HIBERNATION_RUNBOOK.md for full operational guide
 */

if (!defined('HIBERNATION_MODE') || !HIBERNATION_MODE) {
    return;
}

/**
 * Record a hit to a hibernated resource for demand tracking.
 * Uses a standalone PDO connection (config.php constants are available).
 * Silently fails — must never break redirects or 503 responses.
 */
function _hibernation_track_hit($page, $type = 'page') {
    try {
        if (!defined('SQL_HOST')) return;
        $pdo = new PDO(
            'mysql:host=' . SQL_HOST . ';dbname=' . SQL_DATABASE . ';charset=utf8mb4',
            SQL_USERNAME, SQL_PASSWORD,
            [PDO::ATTR_TIMEOUT => 2, PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]
        );
        $stmt = $pdo->prepare(
            "INSERT INTO hibernation_hits (page, hit_type, ip_hash, hit_utc) VALUES (?, ?, ?, UTC_TIMESTAMP())"
        );
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $stmt->execute([$page, $type, hash('sha256', $ip . '_perti_hib')]);
    } catch (Exception $e) {
        // Silent — never interfere with redirect/503
    }
}

// Pages that redirect to the hibernation info page
// NOTE: In Level 1 hibernation, SWIM pages are exempt.
// In Level 2 (deep), SWIM pages are also redirected.
$_hibernated_pages = [
    'demand.php',
    'nod.php',
    'simulator.php',
    'gdt.php',
    'cdm.php',
    'sua.php',
    'event-aar.php',
];

// Deep hibernation: also redirect SWIM pages
$_deep_hibernation = defined('DEEP_HIBERNATION_MODE') && DEEP_HIBERNATION_MODE;
if ($_deep_hibernation) {
    $_hibernated_pages = array_merge($_hibernated_pages, [
        'swim.php',
        'swim-doc.php',
        'swim-docs.php',
        'swim-keys.php',
    ]);
}

$_current_page = basename($_SERVER['PHP_SELF'] ?? '');
$_request_uri = $_SERVER['REQUEST_URI'] ?? '';

// Redirect hibernated web pages to info page
if (in_array($_current_page, $_hibernated_pages)) {
    _hibernation_track_hit($_current_page, 'page');
    if (!headers_sent()) {
        header('Location: /hibernation');
    }
    exit();
}

// Deep hibernation: SWIM API returns 503 JSON
if ($_deep_hibernation && preg_match('#^/api/swim/#', $_request_uri)) {
    _hibernation_track_hit('api/swim', 'api');
    if (!headers_sent()) {
        header('HTTP/1.1 503 Service Unavailable');
        header('Content-Type: application/json');
        header('Retry-After: 86400');
    }
    echo json_encode([
        'error' => 'Service suspended',
        'mode' => 'deep_hibernation',
        'message' => 'SWIM API is suspended during deep hibernation. Data is being archived for post-processing.',
    ]);
    exit();
}

// Level 1: SWIM API exempt from hibernation — VATSWIM remains fully operational
// (Previously returned 503; removed to keep SWIM API available during Level 1 hibernation)
