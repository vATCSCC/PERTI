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

header('Content-Type: application/json');

// Use ADL-only connection (avoids MySQL PDO issues)
require_once __DIR__ . '/connect_adl.php';

if (!$conn_adl) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed (ADL)']);
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
                exit;
            }
            $data = json_decode(file_get_contents('php://input'), true);
            updatePreset($conn_adl, get_int('id'), $data);
            break;

        case 'PATCH':
            // Partial update for a position (e.g., color only)
            if (!isset($_GET['position_id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing position_id']);
                exit;
            }
            $data = json_decode(file_get_contents('php://input'), true);
            patchPosition($conn_adl, get_int('position_id'), $data);
            break;

        case 'DELETE':
            if (!isset($_GET['id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing preset ID']);
                exit;
            }
            deletePreset($conn_adl, get_int('id'));
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * List all presets, optionally filtered by ARTCC
 */
function listPresets($conn, $artccFilter = null) {
    $sql = "SELECT 
                p.id,
                p.preset_name,
                p.artcc,
                p.description,
                p.created_at,
                p.updated_at,
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
        throw new Exception('Query failed: ' . print_r(sqlsrv_errors(), true));
    }
    
    $presets = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        // Convert DateTime objects to ISO strings with Z suffix for UTC
        if ($row['created_at'] instanceof DateTime) {
            $row['created_at'] = $row['created_at']->format('Y-m-d\TH:i:s\Z');
        }
        if ($row['updated_at'] instanceof DateTime) {
            $row['updated_at'] = $row['updated_at']->format('Y-m-d\TH:i:s\Z');
        }
        $presets[] = $row;
    }
    
    echo json_encode(['presets' => $presets]);
}

/**
 * Get a single preset with its positions
 */
function getPreset($conn, $id) {
    // Get preset info
    $sql = "SELECT id, preset_name, artcc, description, created_at, updated_at 
            FROM dbo.splits_presets WHERE id = ?";
    $stmt = sqlsrv_query($conn, $sql, [$id]);
    
    if ($stmt === false) {
        throw new Exception('Query failed');
    }
    
    $preset = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if (!$preset) {
        http_response_code(404);
        echo json_encode(['error' => 'Preset not found']);
        return;
    }
    
    // Convert DateTime objects to ISO strings with Z suffix for UTC
    if ($preset['created_at'] instanceof DateTime) {
        $preset['created_at'] = $preset['created_at']->format('Y-m-d\TH:i:s\Z');
    }
    if ($preset['updated_at'] instanceof DateTime) {
        $preset['updated_at'] = $preset['updated_at']->format('Y-m-d\TH:i:s\Z');
    }
    
    // Get positions
    $sql = "SELECT id, position_name, sectors, color, frequency, sort_order, filters, strata_filter
            FROM dbo.splits_preset_positions
            WHERE preset_id = ?
            ORDER BY sort_order";
    $stmt = sqlsrv_query($conn, $sql, [$id]);

    $positions = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        // Parse JSON fields
        $row['sectors'] = json_decode($row['sectors'], true) ?: [];
        $row['filters'] = json_decode($row['filters'], true);
        $row['strata_filter'] = json_decode($row['strata_filter'], true);
        $positions[] = $row;
    }
    
    $preset['positions'] = $positions;
    echo json_encode(['preset' => $preset]);
}

/**
 * Create a new preset
 */
function createPreset($conn, $data) {
    // Validate required fields
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
    
    // Begin transaction
    sqlsrv_begin_transaction($conn);
    
    try {
        // Insert preset
        $sql = "INSERT INTO dbo.splits_presets (preset_name, artcc, description, created_at, updated_at)
                OUTPUT INSERTED.id
                VALUES (?, ?, ?, GETUTCDATE(), GETUTCDATE())";
        
        $stmt = sqlsrv_query($conn, $sql, [
            $data['preset_name'],
            strtoupper($data['artcc']),
            $data['description'] ?? null
        ]);
        
        if ($stmt === false) {
            throw new Exception('Failed to insert preset: ' . print_r(sqlsrv_errors(), true));
        }
        
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $presetId = $row['id'];
        
        // Insert positions
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
                throw new Exception('Failed to insert position');
            }
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
    // Validate
    if (empty($data['preset_name']) || empty($data['artcc'])) {
        http_response_code(400);
        echo json_encode(['error' => 'preset_name and artcc are required']);
        return;
    }
    
    sqlsrv_begin_transaction($conn);
    
    try {
        // Update preset
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
            throw new Exception('Failed to update preset');
        }
        
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
                throw new Exception('Failed to insert position');
            }
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
        // Delete positions first (FK constraint)
        $sql = "DELETE FROM dbo.splits_preset_positions WHERE preset_id = ?";
        sqlsrv_query($conn, $sql, [$id]);
        
        // Delete preset
        $sql = "DELETE FROM dbo.splits_presets WHERE id = ?";
        $stmt = sqlsrv_query($conn, $sql, [$id]);
        
        if ($stmt === false) {
            throw new Exception('Failed to delete preset');
        }
        
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

    // Build update query for provided fields only
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
