<?php
/**
 * Webhook Subscription Management
 *
 * GET    /api/swim/v1/webhooks/register.php           - List subscriptions
 * POST   /api/swim/v1/webhooks/register.php           - Create subscription
 * DELETE /api/swim/v1/webhooks/register.php?id=N       - Deactivate subscription
 *
 * Requires System tier API key.
 *
 * @package PERTI\SWIM\Webhooks
 */

require_once __DIR__ . '/../auth.php';

header('Content-Type: application/json');

$conn = get_conn_swim();

// Require system tier
$auth = swim_init_auth(true, false);
$keyInfo = $auth->getKeyInfo();
if (($keyInfo['tier'] ?? '') !== 'system') {
    http_response_code(403);
    echo json_encode(['error' => 'Requires System tier API key']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

switch ($method) {
    case 'GET':
        $sql = "SELECT id, source_id, direction, callback_url, event_types, is_active,
                       created_utc, updated_utc, last_success_utc, last_failure_utc,
                       consecutive_failures
                FROM dbo.swim_webhook_subscriptions
                ORDER BY source_id, direction";
        $stmt = sqlsrv_query($conn, $sql);
        if ($stmt === false) {
            http_response_code(500);
            echo json_encode(['error' => 'Database query failed']);
            exit;
        }
        $rows = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            // Convert DateTime objects to strings
            foreach (['created_utc', 'updated_utc', 'last_success_utc', 'last_failure_utc'] as $col) {
                if ($row[$col] instanceof \DateTime) {
                    $row[$col] = $row[$col]->format('Y-m-d H:i:s');
                }
            }
            $rows[] = $row;
        }
        sqlsrv_free_stmt($stmt);
        echo json_encode(['subscriptions' => $rows]);
        break;

    case 'POST':
        $body = json_decode(file_get_contents('php://input'), true);
        if (!$body) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON']);
            exit;
        }

        $required = ['source_id', 'direction', 'callback_url', 'shared_secret'];
        foreach ($required as $field) {
            if (empty($body[$field])) {
                http_response_code(400);
                echo json_encode(['error' => "Missing required field: {$field}"]);
                exit;
            }
        }

        if (!in_array($body['direction'], ['inbound', 'outbound'])) {
            http_response_code(400);
            echo json_encode(['error' => 'direction must be "inbound" or "outbound"']);
            exit;
        }

        $sql = "INSERT INTO dbo.swim_webhook_subscriptions
                    (source_id, direction, callback_url, shared_secret, event_types)
                VALUES (?, ?, ?, ?, ?)";
        $params = [
            $body['source_id'],
            $body['direction'],
            $body['callback_url'],
            $body['shared_secret'],
            $body['event_types'] ?? '*',
        ];

        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt === false) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to create subscription']);
            exit;
        }
        sqlsrv_free_stmt($stmt);

        // Get inserted ID
        $idStmt = sqlsrv_query($conn, "SELECT SCOPE_IDENTITY() AS id");
        $idRow = sqlsrv_fetch_array($idStmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($idStmt);

        echo json_encode(['success' => true, 'id' => $idRow['id']]);
        break;

    case 'DELETE':
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing or invalid subscription id']);
            exit;
        }

        $sql = "UPDATE dbo.swim_webhook_subscriptions
                SET is_active = 0, updated_utc = SYSUTCDATETIME()
                WHERE id = ?";
        $stmt = sqlsrv_query($conn, $sql, [$id]);
        if ($stmt === false) {
            http_response_code(500);
            echo json_encode(['error' => 'Database update failed']);
            exit;
        }
        $rows = sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);

        echo json_encode(['success' => $rows > 0, 'deactivated' => $rows]);
        break;

    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
}
