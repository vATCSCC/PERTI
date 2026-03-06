<?php
/**
 * Hibernation Mode Check
 *
 * Included from config.php. When HIBERNATION_MODE is enabled:
 * - Redirects hibernated web pages to /hibernation info page
 * - Returns HTTP 503 JSON for SWIM API endpoints
 * - Tracks hit counts for demand analysis (hibernation_hits table)
 *
 * @see docs/HIBERNATION_RUNBOOK.md for full operational guide
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
$_hibernated_pages = [
    'demand.php',
    'nod.php',
    'review.php',
    'swim.php',
    'swim-doc.php',
    'swim-docs.php',
    'swim-keys.php',
    'simulator.php',
    'gdt.php',
    'cdm.php',
    'sua.php',
    'event-aar.php',
];

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

// SWIM API: return 503 Service Unavailable
if (strpos($_request_uri, '/api/swim/') === 0) {
    // Extract endpoint path for tracking (e.g. "flights", "health")
    $swim_path = substr($_request_uri, strlen('/api/swim/'));
    $swim_path = strtok($swim_path, '?'); // strip query string
    _hibernation_track_hit('swim:' . ($swim_path ?: 'root'), 'api');

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(503);
    }
    echo json_encode([
        'error' => 'Service Temporarily Unavailable',
        'message' => 'VATSWIM API is currently in hibernation mode. Service will resume at a future date.',
        'status' => 503,
        'info' => 'https://perti.vatcscc.org/hibernation'
    ], JSON_UNESCAPED_SLASHES);
    exit();
}
