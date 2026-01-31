<?php
/**
 * TMI Advisory Number API
 *
 * Returns advisory number information for today.
 *
 * GET /api/mgt/tmi/advisory-number.php
 * Query params:
 *   - peek=1: Return next number without consuming it (default)
 *   - reserve=1: Reserve the number (increments counter)
 *   - all=1: Return previous, current, and next numbers
 *
 * Response:
 * {
 *   "success": true,
 *   "advisory_number": "ADVZY 001",
 *   "sequence": 1,
 *   "date": "2026-01-30",
 *   "reserved": false,
 *   "previous": 0,          // Only with all=1
 *   "current": 0            // Only with all=1
 * }
 *
 * @package PERTI
 * @subpackage API/TMI
 * @version 2.0.0
 * @date 2026-01-31
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only allow GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Load dependencies
try {
    require_once __DIR__ . '/../../../load/config.php';
    require_once __DIR__ . '/../../tmi/AdvisoryNumber.php';
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Config load error']);
    exit;
}

// Parse query params
$reserve = isset($_GET['reserve']) && $_GET['reserve'] == '1';
$all = isset($_GET['all']) && $_GET['all'] == '1';

// Connect to TMI database
$tmiConn = null;
try {
    if (defined('TMI_SQL_HOST') && TMI_SQL_HOST) {
        $tmiConn = new PDO(
            "sqlsrv:Server=" . TMI_SQL_HOST . ";Database=" . TMI_SQL_DATABASE,
            TMI_SQL_USERNAME,
            TMI_SQL_PASSWORD
        );
        $tmiConn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

if (!$tmiConn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database not configured']);
    exit;
}

try {
    $advNum = new AdvisoryNumber($tmiConn, 'pdo');

    if ($all) {
        // Return all info: previous, current, next
        $info = $advNum->getAll($reserve);
        echo json_encode([
            'success' => true,
            'advisory_number' => $info['next'],
            'sequence' => $info['next_raw'],
            'date' => $info['date'],
            'reserved' => $info['reserved'],
            'previous' => $info['previous'],
            'previous_formatted' => $info['previous_formatted'],
            'current' => $info['current'],
            'current_formatted' => $info['current_formatted']
        ]);
    } else if ($reserve) {
        // Reserve mode: Get and increment
        $advisoryNumber = $advNum->reserve();
        $sequence = $advNum->parse($advisoryNumber) ?? 1;
        echo json_encode([
            'success' => true,
            'advisory_number' => $advisoryNumber,
            'sequence' => $sequence,
            'date' => gmdate('Y-m-d'),
            'reserved' => true
        ]);
    } else {
        // Peek mode: Just read without incrementing
        $advisoryNumber = $advNum->peek();
        $sequence = $advNum->parse($advisoryNumber) ?? 1;
        echo json_encode([
            'success' => true,
            'advisory_number' => $advisoryNumber,
            'sequence' => $sequence,
            'date' => gmdate('Y-m-d'),
            'reserved' => false
        ]);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database query failed',
        'message' => $e->getMessage()
    ]);
}
