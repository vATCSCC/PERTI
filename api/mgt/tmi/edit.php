<?php
/**
 * TMI Edit Entry API
 *
 * Edits an active TMI entry or advisory (validity times, values).
 *
 * POST /api/mgt/tmi/edit.php
 * Body: {
 *   "entityType": "ENTRY"|"ADVISORY",
 *   "entityId": 123,
 *   "updates": { "validFrom": "...", "validUntil": "...", "restrictionValue": "..." }
 * }
 *
 * @package PERTI
 * @subpackage API/TMI
 * @version 1.0.0
 * @date 2026-01-28
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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

// Parse request body
$input = file_get_contents('php://input');
$payload = json_decode($input, true);

if (!$payload) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON body']);
    exit;
}

$entityType = strtoupper($payload['entityType'] ?? '');
$entityId = intval($payload['entityId'] ?? 0);
$updates = $payload['updates'] ?? [];
$userCid = $payload['userCid'] ?? null;
$userName = $payload['userName'] ?? 'Unknown';

if (empty($entityType) || !in_array($entityType, ['ENTRY', 'ADVISORY'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid entityType. Must be ENTRY or ADVISORY']);
    exit;
}

if ($entityId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid entityId']);
    exit;
}

if (empty($updates)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No updates provided']);
    exit;
}

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
    echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

if (!$tmiConn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database not configured']);
    exit;
}

try {
    if ($entityType === 'ENTRY') {
        // Update NTML entry
        $setClauses = ['updated_at = SYSUTCDATETIME()'];
        $params = [':entry_id' => $entityId];

        if (isset($updates['validFrom']) && $updates['validFrom']) {
            $setClauses[] = 'valid_from = :valid_from';
            $params[':valid_from'] = parseUtcDateTime($updates['validFrom']);
        }

        if (isset($updates['validUntil']) && $updates['validUntil']) {
            $setClauses[] = 'valid_until = :valid_until';
            $params[':valid_until'] = parseUtcDateTime($updates['validUntil']);
        }

        if (isset($updates['restrictionValue']) && $updates['restrictionValue'] !== null) {
            $setClauses[] = 'restriction_value = :restriction_value';
            $params[':restriction_value'] = intval($updates['restrictionValue']);
        }

        $sql = "UPDATE dbo.tmi_entries
                SET " . implode(', ', $setClauses) . "
                WHERE entry_id = :entry_id
                  AND status NOT IN ('CANCELLED', 'EXPIRED')";

        $stmt = $tmiConn->prepare($sql);
        $stmt->execute($params);

        $rowsAffected = $stmt->rowCount();

        if ($rowsAffected === 0) {
            echo json_encode([
                'success' => false,
                'error' => 'Entry not found or already cancelled/expired'
            ]);
            exit;
        }

        // Log event
        logEditEvent($tmiConn, 'ENTRY', $entityId, $updates, $userCid, $userName);

    } else {
        // Update advisory
        $setClauses = ['updated_at = SYSUTCDATETIME()'];
        $params = [':advisory_id' => $entityId];

        if (isset($updates['validFrom']) && $updates['validFrom']) {
            $setClauses[] = 'effective_from = :effective_from';
            $params[':effective_from'] = parseUtcDateTime($updates['validFrom']);
        }

        if (isset($updates['validUntil']) && $updates['validUntil']) {
            $setClauses[] = 'effective_until = :effective_until';
            $params[':effective_until'] = parseUtcDateTime($updates['validUntil']);
        }

        $sql = "UPDATE dbo.tmi_advisories
                SET " . implode(', ', $setClauses) . "
                WHERE advisory_id = :advisory_id
                  AND status NOT IN ('CANCELLED', 'EXPIRED')";

        $stmt = $tmiConn->prepare($sql);
        $stmt->execute($params);

        $rowsAffected = $stmt->rowCount();

        if ($rowsAffected === 0) {
            echo json_encode([
                'success' => false,
                'error' => 'Advisory not found or already cancelled/expired'
            ]);
            exit;
        }

        // Log event
        logEditEvent($tmiConn, 'ADVISORY', $entityId, $updates, $userCid, $userName);
    }

    echo json_encode([
        'success' => true,
        'message' => "{$entityType} #{$entityId} updated successfully",
        'entityType' => $entityType,
        'entityId' => $entityId
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Update failed: ' . $e->getMessage()]);
}

/**
 * Parse datetime and ensure it's stored as UTC
 */
function parseUtcDateTime($dateStr) {
    if (empty($dateStr)) return null;

    // Parse as UTC - append 'Z' if no timezone specified
    $ts = strtotime($dateStr . ' UTC');
    if ($ts === false) {
        $ts = strtotime($dateStr);
    }
    return gmdate('Y-m-d H:i:s', $ts);
}

/**
 * Log edit event to tmi_events table
 */
function logEditEvent($conn, $entityType, $entityId, $updates, $actorId, $actorName) {
    try {
        $sql = "INSERT INTO dbo.tmi_events (
                    entity_type, entity_id, event_type, event_detail,
                    source_type, actor_id, actor_name, actor_ip
                ) VALUES (
                    :entity_type, :entity_id, 'EDITED', :detail,
                    'WEB', :actor_id, :actor_name, :actor_ip
                )";

        $detail = json_encode($updates);
        if (strlen($detail) > 64) {
            $detail = 'Multiple fields updated';
        }

        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':entity_type' => $entityType,
            ':entity_id' => $entityId,
            ':detail' => $detail,
            ':actor_id' => $actorId,
            ':actor_name' => $actorName,
            ':actor_ip' => $_SERVER['REMOTE_ADDR'] ?? null
        ]);
    } catch (Exception $e) {
        // Log failure but don't fail the edit
        error_log("Failed to log edit event: " . $e->getMessage());
    }
}
