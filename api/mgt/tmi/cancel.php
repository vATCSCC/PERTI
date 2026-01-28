<?php
/**
 * TMI Cancel Entry API
 * 
 * Cancels an active TMI entry or advisory.
 * 
 * POST /api/mgt/tmi/cancel.php
 * Body: { "entityType": "ENTRY"|"ADVISORY", "entityId": 123, "reason": "optional reason" }
 * 
 * @package PERTI
 * @subpackage API/TMI
 * @version 1.0.0
 * @date 2026-01-27
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
$reason = $payload['reason'] ?? 'Cancelled via TMI Publisher';
$userCid = $payload['userCid'] ?? null;
$userName = $payload['userName'] ?? 'Unknown';

if (empty($entityType) || !in_array($entityType, ['ENTRY', 'ADVISORY', 'PROGRAM', 'REROUTE'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid entityType. Must be ENTRY, ADVISORY, PROGRAM, or REROUTE']);
    exit;
}

if ($entityId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid entityId']);
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
        // Cancel NTML entry
        $sql = "UPDATE dbo.tmi_entries
                SET status = 'CANCELLED',
                    cancelled_at = SYSUTCDATETIME(),
                    cancelled_by = :cancelled_by,
                    cancel_reason = :cancel_reason,
                    updated_at = SYSUTCDATETIME()
                WHERE entry_id = :entry_id
                  AND status NOT IN ('CANCELLED', 'EXPIRED')";

        $stmt = $tmiConn->prepare($sql);
        $stmt->execute([
            ':cancelled_by' => $userCid,
            ':cancel_reason' => $reason,
            ':entry_id' => $entityId
        ]);

        $rowsAffected = $stmt->rowCount();

        if ($rowsAffected === 0) {
            echo json_encode([
                'success' => false,
                'error' => 'Entry not found or already cancelled/expired'
            ]);
            exit;
        }

        // Log event
        logCancelEvent($tmiConn, 'ENTRY', $entityId, $reason, $userCid, $userName);

    } elseif ($entityType === 'ADVISORY') {
        // Cancel advisory
        $sql = "UPDATE dbo.tmi_advisories
                SET status = 'CANCELLED',
                    cancelled_at = SYSUTCDATETIME(),
                    cancelled_by = :cancelled_by,
                    cancel_reason = :cancel_reason,
                    updated_at = SYSUTCDATETIME()
                WHERE advisory_id = :advisory_id
                  AND status NOT IN ('CANCELLED', 'EXPIRED')";

        $stmt = $tmiConn->prepare($sql);
        $stmt->execute([
            ':cancelled_by' => $userCid,
            ':cancel_reason' => $reason,
            ':advisory_id' => $entityId
        ]);

        $rowsAffected = $stmt->rowCount();

        if ($rowsAffected === 0) {
            echo json_encode([
                'success' => false,
                'error' => 'Advisory not found or already cancelled/expired'
            ]);
            exit;
        }

        // Log event
        logCancelEvent($tmiConn, 'ADVISORY', $entityId, $reason, $userCid, $userName);

    } elseif ($entityType === 'PROGRAM') {
        // Cancel GDT program (Ground Stop or GDP)
        $sql = "UPDATE dbo.tmi_programs
                SET status = 'PURGED',
                    purged_utc = SYSUTCDATETIME(),
                    purged_by = :purged_by,
                    modified_utc = SYSUTCDATETIME(),
                    modified_by = :modified_by
                WHERE program_id = :program_id
                  AND status NOT IN ('PURGED', 'COMPLETED', 'SUPERSEDED')";

        $stmt = $tmiConn->prepare($sql);
        $stmt->execute([
            ':purged_by' => $userName,
            ':modified_by' => $userName,
            ':program_id' => $entityId
        ]);

        $rowsAffected = $stmt->rowCount();

        if ($rowsAffected === 0) {
            echo json_encode([
                'success' => false,
                'error' => 'Program not found or already cancelled/completed'
            ]);
            exit;
        }

        // Log event
        logCancelEvent($tmiConn, 'PROGRAM', $entityId, $reason, $userCid, $userName);

    } elseif ($entityType === 'REROUTE') {
        // Cancel reroute (status 5 = cancelled, TMI uses reroute_id and updated_at)
        $sql = "UPDATE dbo.tmi_reroutes
                SET status = 5,
                    updated_at = SYSUTCDATETIME()
                WHERE reroute_id = :reroute_id
                  AND status NOT IN (4, 5)";

        $stmt = $tmiConn->prepare($sql);
        $stmt->execute([
            ':reroute_id' => $entityId
        ]);

        $rowsAffected = $stmt->rowCount();

        if ($rowsAffected === 0) {
            echo json_encode([
                'success' => false,
                'error' => 'Reroute not found or already cancelled/expired'
            ]);
            exit;
        }

        // Log event
        logCancelEvent($tmiConn, 'REROUTE', $entityId, $reason, $userCid, $userName);
    }

    echo json_encode([
        'success' => true,
        'message' => "{$entityType} #{$entityId} cancelled successfully",
        'entityType' => $entityType,
        'entityId' => $entityId
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Cancel failed: ' . $e->getMessage()]);
}

/**
 * Log cancel event to tmi_events table
 */
function logCancelEvent($conn, $entityType, $entityId, $reason, $actorId, $actorName) {
    try {
        $sql = "INSERT INTO dbo.tmi_events (
                    entity_type, entity_id, event_type, event_detail,
                    source_type, actor_id, actor_name, actor_ip
                ) VALUES (
                    :entity_type, :entity_id, 'CANCELLED', :reason,
                    'WEB', :actor_id, :actor_name, :actor_ip
                )";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':entity_type' => $entityType,
            ':entity_id' => $entityId,
            ':reason' => substr($reason, 0, 64),
            ':actor_id' => $actorId,
            ':actor_name' => $actorName,
            ':actor_ip' => $_SERVER['REMOTE_ADDR'] ?? null
        ]);
    } catch (Exception $e) {
        // Log failure but don't fail the cancel
        error_log("Failed to log cancel event: " . $e->getMessage());
    }
}
