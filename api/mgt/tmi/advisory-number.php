<?php
/**
 * TMI Advisory Number API
 *
 * Returns the next advisory number for today.
 *
 * GET /api/mgt/tmi/advisory-number.php
 * Query params:
 *   - peek=1: Return next number without consuming it (default)
 *   - reserve=1: Reserve the number (increments counter)
 *
 * Response:
 * {
 *   "success": true,
 *   "advisory_number": "ADVZY 001",
 *   "sequence": 1,
 *   "date": "2026-01-30",
 *   "reserved": false
 * }
 *
 * @package PERTI
 * @subpackage API/TMI
 * @version 1.0.0
 * @date 2026-01-30
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
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Config load error']);
    exit;
}

// Parse query params
$reserve = isset($_GET['reserve']) && $_GET['reserve'] == '1';

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
    $todayUtc = gmdate('Y-m-d');
    $advisoryNumber = null;
    $sequence = null;
    $reserved = false;

    if ($reserve) {
        // Reserve mode: Call stored procedure to get and increment
        $stmt = $tmiConn->prepare("DECLARE @num NVARCHAR(16); EXEC dbo.sp_GetNextAdvisoryNumber @next_number = @num OUTPUT; SELECT @num AS num;");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $advisoryNumber = $row['num'] ?? null;
        $reserved = true;

        // Extract sequence number from advisory number (e.g., "ADVZY 001" -> 1)
        if ($advisoryNumber && preg_match('/ADVZY\s*(\d+)/', $advisoryNumber, $matches)) {
            $sequence = intval($matches[1]);
        }
    } else {
        // Peek mode: Just read current sequence and calculate next without incrementing
        $stmt = $tmiConn->prepare("SELECT seq_number FROM dbo.tmi_advisory_sequences WHERE seq_date = :today");
        $stmt->execute([':today' => $todayUtc]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            // Next number is current + 1
            $sequence = intval($row['seq_number']) + 1;
        } else {
            // No sequence for today yet, next would be 1
            $sequence = 1;
        }

        $advisoryNumber = 'ADVZY ' . str_pad($sequence, 3, '0', STR_PAD_LEFT);
    }

    // Fallback if something went wrong
    if (!$advisoryNumber) {
        $advisoryNumber = 'ADVZY 001';
        $sequence = 1;
    }

    echo json_encode([
        'success' => true,
        'advisory_number' => $advisoryNumber,
        'sequence' => $sequence,
        'date' => $todayUtc,
        'reserved' => $reserved
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database query failed',
        'message' => $e->getMessage()
    ]);
}
