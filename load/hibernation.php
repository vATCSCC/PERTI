<?php
/**
 * Hibernation Mode Check
 *
 * Included from config.php. Three levels of hibernation:
 *
 * Level 1 (HIBERNATION_MODE): Pauses downstream daemons, redirects 7 pages.
 *   SWIM API and daemons remain fully operational.
 *
 * Level 2 (DEEP_HIBERNATION_MODE): Stops ADL ingest, raw JSON capture only.
 *   SWIM pages redirect, SWIM API returns 503.
 *
 * Level 3 (FREEZE_MODE): All processing stopped, zero daemons.
 *   Only planning pages + route.php remain accessible.
 *   All APIs except planning endpoints return 503.
 *
 * @see docs/operations/HIBERNATION_RUNBOOK.md for full operational guide
 */

$_freeze_active = defined('FREEZE_MODE') && FREEZE_MODE;
if ((!defined('HIBERNATION_MODE') || !HIBERNATION_MODE) && !$_freeze_active) {
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

$_current_page = basename($_SERVER['PHP_SELF'] ?? '');
$_request_uri = $_SERVER['REQUEST_URI'] ?? '';
$_freeze_mode = $_freeze_active;
$_deep_hibernation = defined('DEEP_HIBERNATION_MODE') && DEEP_HIBERNATION_MODE;

// =========================================================================
// LEVEL 3 — FREEZE MODE (allowlist approach)
// Only planning pages, route.php, and infrastructure pages are accessible.
// All other pages redirect; all non-planning APIs return 503.
// =========================================================================
if ($_freeze_mode) {
    $_allowed_pages = [
        'index.php',
        'plan.php',
        'schedule.php',
        'sheet.php',
        'review.php',
        'route.php',
        'playbook.php',
        'hibernation.php',
        'healthcheck.php',
        'status.php',
        'transparency.php',
        'privacy.php',
        'fmds-comparison.php',
        'logout.php',
        'callback.php',
    ];

    $_allowed_api_prefixes = [
        '/api/data/plans/',
        '/api/data/sheet/',
        '/api/data/review/',
        '/api/data/schedule',
        '/api/data/plans.l',
        '/api/data/routes',
        '/api/data/route_share',
        '/api/data/reroutes',
        '/api/data/reroute_advisory',
        '/api/data/playbook/',
        '/api/data/natots',
        '/api/data/locale',
        '/api/data/personnel',
        '/api/data/hibernation_stats',
        '/api/mgt/perti/',
        '/api/mgt/goals/',
        '/api/mgt/historical/',
        '/api/mgt/forecast/',
        '/api/mgt/terminal_inits/',
        '/api/mgt/terminal_staffing/',
        '/api/mgt/terminal_planning/',
        '/api/mgt/terminal_constraints/',
        '/api/mgt/terminal_init_times/',
        '/api/mgt/enroute_initializations/',
        '/api/mgt/enroute_staffing/',
        '/api/mgt/enroute_planning/',
        '/api/mgt/enroute_constraints/',
        '/api/mgt/configs/',
        '/api/mgt/group_flights/',
        '/api/mgt/dcc/',
        '/api/mgt/scores/',
        '/api/mgt/event_data/',
        '/api/mgt/comments/',
        '/api/mgt/schedule/',
        '/api/mgt/personnel/',
        '/api/mgt/playbook/',
        '/api/mgt/tmi/advisory-number',
        '/api/splits/active',
        '/api/session/',
        '/api/user/',
        '/login/',
    ];

    // Redirect non-allowed pages
    if ($_current_page && !in_array($_current_page, $_allowed_pages)) {
        $is_login_dir = (strpos($_request_uri, '/login/') === 0);
        if (!$is_login_dir) {
            _hibernation_track_hit($_current_page, 'page');
            if (!headers_sent()) {
                header('Location: /hibernation');
            }
            exit();
        }
    }

    // Block non-allowed API endpoints with 503
    if (preg_match('#^/api/#', $_request_uri)) {
        $api_allowed = false;
        foreach ($_allowed_api_prefixes as $prefix) {
            if (strpos($_request_uri, $prefix) === 0) {
                $api_allowed = true;
                break;
            }
        }
        if (!$api_allowed) {
            _hibernation_track_hit('api_frozen', 'api');
            if (!headers_sent()) {
                header('HTTP/1.1 503 Service Unavailable');
                header('Content-Type: application/json');
                header('Retry-After: 86400');
            }
            echo json_encode([
                'error' => 'Service suspended',
                'mode' => 'freeze',
                'message' => 'PERTI is in freeze mode. Only planning features are available.',
            ]);
            exit();
        }
    }

    return;
}

// =========================================================================
// LEVEL 1 & 2 — HIBERNATION / DEEP HIBERNATION (blocklist approach)
// =========================================================================

// Pages that redirect to the hibernation info page
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
if ($_deep_hibernation) {
    $_hibernated_pages = array_merge($_hibernated_pages, [
        'swim.php',
        'swim-doc.php',
        'swim-docs.php',
        'swim-keys.php',
    ]);
}

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
