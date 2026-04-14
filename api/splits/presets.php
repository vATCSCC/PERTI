<?php
/**
 * Splits Presets API
 *
 * Manages globally saved split presets for easy re-use.
 * These are configuration templates without time constraints.
 *
 * Endpoints:
 *   GET    /api/splits/presets.php           - List all presets (optionally filter by ?artcc=XXX)
 *   GET    /api/splits/presets.php?id=N      - Get single preset with positions
 *   POST   /api/splits/presets.php           - Create new preset
 *   PUT    /api/splits/presets.php?id=N      - Update preset
 *   DELETE /api/splits/presets.php?id=N      - Delete preset
 */

// Suppress stray output that could corrupt JSON
ob_start();

require_once __DIR__ . '/../../load/config.php';
require_once __DIR__ . '/../../load/perti_constants.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
perti_set_cors();
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    ob_end_clean();
    http_response_code(204);
    exit;
}

// Suppress PHP errors from appearing in output
ini_set('display_errors', '0');
error_reporting(0);

// Use ADL-only connection (avoids MySQL PDO issues)
require_once __DIR__ . '/connect_adl.php';

// Clear any accidental output, start fresh buffer for JSON
ob_end_clean();
ob_start();

if (!$conn_adl) {
    error_log('[SPLITS PRESETS] Database connection failed (ADL): ' . ($conn_adl_error ?? 'unknown'));
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed (ADL)']);
    ob_end_flush();
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ($method) {
        case 'GET':
            if (isset($_GET['id'])) {
                getPreset($conn_adl, get_int('id'));
            } else {
                listPresets($conn_adl, $_GET['artcc'] ?? null);
            }
            break;

        case 'POST':
            $data = json_decode(file_get_contents('php://input'), true);
            createPreset($conn_adl, $data);
            break;

        case 'PUT':
            if (!isset($_GET['id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing preset ID']);
                break;
            }
            $data = json_decode(file_get_contents('php://input'), true);
            updatePreset($conn_adl, get_int('id'), $data);
            break;

        case 'PATCH':
            if (!isset($_GET['position_id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing position_id']);
                break;
            }
            $data = json_decode(file_get_contents('php://input'), true);
            patchPosition($conn_adl, get_int('position_id'), $data);
            break;

        case 'DELETE':
            if (!isset($_GET['id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing preset ID']);
                break;
            }
            deletePreset($conn_adl, get_int('id'));
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    error_log('[SPLITS PRESETS] ' . $method . ' error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error', 'message' => $e->getMessage()]);
}

ob_end_flush();

/**
 * List all presets, optionally filtered by ARTCC
 */
function listPresets($conn, $artccFilter = null) {
    $sql = "SELECT
                p.id,
                p.preset_name,
                p.artcc,
                p.description,
                FORMAT(p.created_at, 'yyyy-MM-dd\"T\"HH:mm:ss\"Z\"') as created_at,
                FORMAT(p.updated_at, 'yyyy-MM-dd\"T\"HH:mm:ss\"Z\"') as updated_at,
                (SELECT COUNT(*) FROM dbo.splits_preset_positions WHERE preset_id = p.id) as position_count
            FROM dbo.splits_presets p";

    $params = [];
    if ($artccFilter) {
        $sql .= " WHERE p.artcc = ?";
        $params[] = strtoupper($artccFilter);
    }

    $sql .= " ORDER BY p.artcc, p.preset_name";

    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        throw new Exception('Query failed: ' . adl_sql_error_message());
    }

    $presets = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $presets[] = $row;
    }
    sqlsrv_free_stmt($stmt);

    echo json_encode(['presets' => $presets]);
}

/**
 * Get a single preset with its positions
 */
function getPreset($conn, $id) {
    $sql = "SELECT id, preset_name, artcc, description,
                   FORMAT(created_at, 'yyyy-MM-dd\"T\"HH:mm:ss\"Z\"') as created_at,
                   FORMAT(updated_at, 'yyyy-MM-dd\"T\"HH:mm:ss\"Z\"') as updated_at
            FROM dbo.splits_presets WHERE id = ?";
    $stmt = sqlsrv_query($conn, $sql, [$id]);

    if ($stmt === false) {
        throw new Exception('Query failed: ' . adl_sql_error_message());
    }

    $preset = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    if (!$preset) {
        http_response_code(404);
        echo json_encode(['error' => 'Preset not found']);
        return;
    }

    // Get positions
    $sql = "SELECT id, position_name, sectors, color, frequency, sort_order, filters, strata_filter
            FROM dbo.splits_preset_positions
            WHERE preset_id = ?
            ORDER BY sort_order";
    $stmt = sqlsrv_query($conn, $sql, [$id]);

    $positions = [];
    if ($stmt !== false) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $row['sectors'] = json_decode($row['sectors'], true) ?: [];
            $row['filters'] = json_decode($row['filters'], true);
            $row['strata_filter'] = json_decode($row['strata_filter'], true);
            $positions[] = $row;
        }
        sqlsrv_free_stmt($stmt);
    }

    $preset['positions'] = $positions;
    echo json_encode(['preset' => $preset]);
}

/**
 * Create a new preset
 */
function createPreset($conn, $data) {
    if (empty($data['preset_name']) || empty($data['artcc'])) {
        http_response_code(400);
        echo json_encode(['error' => 'preset_name and artcc are required']);
        return;
    }

    $positions = $data['positions'] ?? [];
    if (empty($positions)) {
        http_response_code(400);
        echo json_encode(['error' => 'At least one position is required']);
        return;
    }

    sqlsrv_begin_transaction($conn);

    try {
        $sql = "INSERT INTO dbo.splits_presets (preset_name, artcc, description, created_at, updated_at)
                OUTPUT INSERTED.id
                VALUES (?, ?, ?, GETUTCDATE(), GETUTCDATE())";

        $stmt = sqlsrv_query($conn, $sql, [
            $data['preset_name'],
            strtoupper($data['artcc']),
            $data['description'] ?? null
        ]);

        if ($stmt === false) {
            throw new Exception('Failed to insert preset: ' . adl_sql_error_message());
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $presetId = $row['id'];
        sqlsrv_free_stmt($stmt);

        foreach ($positions as $i => $pos) {
            $sql = "INSERT INTO dbo.splits_preset_positions
                    (preset_id, position_name, sectors, color, frequency, sort_order, filters, strata_filter)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

            $stmt = sqlsrv_query($conn, $sql, [
                $presetId,
                $pos['name'] ?? $pos['position_name'],
                json_encode($pos['sectors'] ?? []),
                $pos['color'] ?? '#4dabf7',
                $pos['frequency'] ?? null,
                $pos['sort_order'] ?? $i,
                isset($pos['filters']) ? json_encode($pos['filters']) : null,
                isset($pos['strataFilter']) ? json_encode($pos['strataFilter']) : null
            ]);

            if ($stmt === false) {
                throw new Exception('Failed to insert position: ' . adl_sql_error_message());
            }
            sqlsrv_free_stmt($stmt);
        }

        sqlsrv_commit($conn);

        echo json_encode([
            'success' => true,
            'message' => 'Preset created successfully',
            'preset_id' => $presetId
        ]);

    } catch (Exception $e) {
        sqlsrv_rollback($conn);
        throw $e;
    }
}

/**
 * Update an existing preset
 */
function updatePreset($conn, $id, $data) {
    if (empty($data['preset_name']) || empty($data['artcc'])) {
        http_response_code(400);
        echo json_encode(['error' => 'preset_name and artcc are required']);
        return;
    }

    sqlsrv_begin_transaction($conn);

    try {
        $sql = "UPDATE dbo.splits_presets
                SET preset_name = ?, artcc = ?, description = ?, updated_at = GETUTCDATE()
                WHERE id = ?";

        $stmt = sqlsrv_query($conn, $sql, [
            $data['preset_name'],
            strtoupper($data['artcc']),
            $data['description'] ?? null,
            $id
        ]);

        if ($stmt === false) {
            throw new Exception('Failed to update preset: ' . adl_sql_error_message());
        }
        sqlsrv_free_stmt($stmt);

        // Delete existing positions
        $sql = "DELETE FROM dbo.splits_preset_positions WHERE preset_id = ?";
        sqlsrv_query($conn, $sql, [$id]);

        // Re-insert positions
        $positions = $data['positions'] ?? [];
        foreach ($positions as $i => $pos) {
            $sql = "INSERT INTO dbo.splits_preset_positions
                    (preset_id, position_name, sectors, color, frequency, sort_order, filters, strata_filter)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

            $stmt = sqlsrv_query($conn, $sql, [
                $id,
                $pos['name'] ?? $pos['position_name'],
                json_encode($pos['sectors'] ?? []),
                $pos['color'] ?? '#4dabf7',
                $pos['frequency'] ?? null,
                $pos['sort_order'] ?? $i,
                isset($pos['filters']) ? json_encode($pos['filters']) : null,
                isset($pos['strataFilter']) ? json_encode($pos['strataFilter']) : null
            ]);

            if ($stmt === false) {
                throw new Exception('Failed to insert position: ' . adl_sql_error_message());
            }
            sqlsrv_free_stmt($stmt);
        }

        sqlsrv_commit($conn);

        echo json_encode([
            'success' => true,
            'message' => 'Preset updated successfully'
        ]);

    } catch (Exception $e) {
        sqlsrv_rollback($conn);
        throw $e;
    }
}

/**
 * Delete a preset
 */
function deletePreset($conn, $id) {
    sqlsrv_begin_transaction($conn);

    try {
        $sql = "DELETE FROM dbo.splits_preset_positions WHERE preset_id = ?";
        sqlsrv_query($conn, $sql, [$id]);

        $sql = "DELETE FROM dbo.splits_presets WHERE id = ?";
        $stmt = sqlsrv_query($conn, $sql, [$id]);

        if ($stmt === false) {
            throw new Exception('Failed to delete preset: ' . adl_sql_error_message());
        }
        sqlsrv_free_stmt($stmt);

        sqlsrv_commit($conn);

        echo json_encode([
            'success' => true,
            'message' => 'Preset deleted successfully'
        ]);

    } catch (Exception $e) {
        sqlsrv_rollback($conn);
        throw $e;
    }
}

/**
 * Partial update for a position (e.g., color only)
 */
function patchPosition($conn, $positionId, $data) {
    if (!$data) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON input']);
        return;
    }

    $updates = [];
    $params = [];

    if (array_key_exists('color', $data)) {
        $updates[] = "color = ?";
        $params[] = $data['color'];
    }

    if (array_key_exists('strata_filter', $data)) {
        $updates[] = "strata_filter = ?";
        $params[] = $data['strata_filter'] ? json_encode($data['strata_filter']) : null;
    }

    if (empty($updates)) {
        http_response_code(400);
        echo json_encode(['error' => 'No valid fields to update']);
        return;
    }

    $params[] = $positionId;

    $sql = "UPDATE dbo.splits_preset_positions SET " . implode(", ", $updates) . " WHERE id = ?";
    $stmt = sqlsrv_query($conn, $sql, $params);

    if ($stmt === false) {
        throw new Exception("Update failed: " . adl_sql_error_message());
    }

    $affected = sqlsrv_rows_affected($stmt);
    sqlsrv_free_stmt($stmt);

    if ($affected === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Position not found']);
        return;
    }

    echo json_encode(['success' => true, 'message' => 'Position updated successfully']);
}
