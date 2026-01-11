<?php
/**
 * JATOC Special Emphasis API
 * GET: Public access
 * POST/DELETE: Requires VATSIM auth with special_emphasis permission (DCC role)
 */
header('Content-Type: application/json');
include("../../sessions/handler.php");
if (session_status() == PHP_SESSION_NONE) session_start();
include("../../load/config.php");
include("../../load/connect.php");

// Include JATOC utilities
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/datetime.php';
require_once __DIR__ . '/validators.php';
require_once __DIR__ . '/auth.php';

JatocAuth::setConnection($conn_adl);

$method = $_SERVER['REQUEST_METHOD'];

// POST and DELETE require DCC role (special_emphasis permission)
if ($method === 'POST' || $method === 'DELETE') {
    JatocAuth::requirePermission('special_emphasis');
}

$today = gmdate('Y-m-d');

try {
    if ($method === 'GET') {
        $stmt = sqlsrv_query($conn_adl, "SELECT * FROM jatoc_special_emphasis WHERE active = 1 AND (effective_start IS NULL OR effective_start <= ?) AND (effective_end IS NULL OR effective_end >= ?) ORDER BY priority DESC, id", [$today, $today]);
        if ($stmt === false) throw new Exception('Query failed');
        $data = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            // Format datetime fields
            foreach (['effective_start', 'effective_end', 'created_at'] as $field) {
                if (isset($row[$field]) && $row[$field] instanceof DateTime) {
                    $row[$field] = $row[$field]->format('Y-m-d');
                }
            }
            $data[] = $row;
        }
        echo json_encode(['success' => true, 'data' => $data]);

    } elseif ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);

        // Validate content
        if (empty($input['content'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Content is required']);
            return;
        }

        $error = JatocValidators::stringLength($input['content'], 1, 1000, 'Content');
        if ($error) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => $error]);
            return;
        }

        // Validate dates if provided
        if (!empty($input['effective_start'])) {
            $error = JatocValidators::datetime($input['effective_start'], false);
            if ($error) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid effective_start date']);
                return;
            }
        }

        if (!empty($input['effective_end'])) {
            $error = JatocValidators::datetime($input['effective_end'], false);
            if ($error) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid effective_end date']);
                return;
            }
        }

        // Validate priority
        $priority = intval($input['priority'] ?? 0);
        if ($priority < 0 || $priority > 100) {
            $priority = 0;
        }

        $stmt = sqlsrv_query($conn_adl, "INSERT INTO jatoc_special_emphasis (content, priority, effective_start, effective_end, created_by) VALUES (?, ?, ?, ?, ?)",
            [
                $input['content'],
                $priority,
                JatocDateTime::toSqlServer($input['effective_start'] ?? null),
                JatocDateTime::toSqlServer($input['effective_end'] ?? null),
                $input['created_by'] ?? JatocAuth::getLogIdentifier()
            ]);
        if ($stmt === false) throw new Exception('Insert failed');
        echo json_encode(['success' => true]);

    } elseif ($method === 'DELETE') {
        $id = $_GET['id'] ?? null;
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing id']);
            return;
        }
        sqlsrv_query($conn_adl, "DELETE FROM jatoc_special_emphasis WHERE id = ?", [$id]);
        echo json_encode(['success' => true]);

    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
