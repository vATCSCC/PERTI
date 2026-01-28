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

if (empty($updates)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No updates provided']);
    exit;
}

// Connect to TMI database (for entries, advisories, programs)
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

// Connect to ADL database (for reroutes)
$adlConn = null;
try {
    if (defined('ADL_SQL_HOST') && ADL_SQL_HOST) {
        $adlConn = new PDO(
            "sqlsrv:Server=" . ADL_SQL_HOST . ";Database=" . ADL_SQL_DATABASE,
            ADL_SQL_USERNAME,
            ADL_SQL_PASSWORD
        );
        $adlConn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
} catch (Exception $e) {
    // ADL connection failure is non-fatal for non-reroute operations
    $adlConn = null;
}

try {
    if ($entityType === 'ENTRY') {
        // Update NTML entry
        $setClauses = ['updated_at = SYSUTCDATETIME()'];
        $params = [':entry_id' => $entityId];

        // Time fields
        if (isset($updates['validFrom']) && $updates['validFrom']) {
            $setClauses[] = 'valid_from = :valid_from';
            $params[':valid_from'] = parseUtcDateTime($updates['validFrom']);
        }

        if (isset($updates['validUntil']) && $updates['validUntil']) {
            $setClauses[] = 'valid_until = :valid_until';
            $params[':valid_until'] = parseUtcDateTime($updates['validUntil']);
        }

        // Restriction value/unit
        if (isset($updates['restrictionValue']) && $updates['restrictionValue'] !== null) {
            $setClauses[] = 'restriction_value = :restriction_value';
            $params[':restriction_value'] = intval($updates['restrictionValue']);
        }

        if (isset($updates['restrictionUnit'])) {
            $setClauses[] = 'restriction_unit = :restriction_unit';
            $params[':restriction_unit'] = substr(trim($updates['restrictionUnit']), 0, 8);
        }

        // Control element and facilities
        if (isset($updates['ctlElement'])) {
            $setClauses[] = 'ctl_element = :ctl_element';
            $params[':ctl_element'] = substr(strtoupper(trim($updates['ctlElement'])), 0, 8);
        }

        if (isset($updates['requestingFacility'])) {
            $setClauses[] = 'requesting_facility = :requesting_facility';
            $params[':requesting_facility'] = substr(strtoupper(trim($updates['requestingFacility'])), 0, 64);
        }

        if (isset($updates['providingFacility'])) {
            $setClauses[] = 'providing_facility = :providing_facility';
            $params[':providing_facility'] = substr(strtoupper(trim($updates['providingFacility'])), 0, 64);
        }

        // Condition and qualifiers
        if (isset($updates['conditionText'])) {
            $setClauses[] = 'condition_text = :condition_text';
            $params[':condition_text'] = substr(trim($updates['conditionText']), 0, 500);
        }

        if (isset($updates['qualifiers'])) {
            $setClauses[] = 'qualifiers = :qualifiers';
            $params[':qualifiers'] = substr(trim($updates['qualifiers']), 0, 200);
        }

        if (isset($updates['exclusions'])) {
            $setClauses[] = 'exclusions = :exclusions';
            $params[':exclusions'] = substr(trim($updates['exclusions']), 0, 200);
        }

        // Reason
        if (isset($updates['reasonCode'])) {
            $setClauses[] = 'reason_code = :reason_code';
            $params[':reason_code'] = substr(strtoupper(trim($updates['reasonCode'])), 0, 16);
        }

        if (isset($updates['reasonDetail'])) {
            $setClauses[] = 'reason_detail = :reason_detail';
            $params[':reason_detail'] = substr(trim($updates['reasonDetail']), 0, 200);
        }

        // Raw input text
        if (isset($updates['rawInput'])) {
            $setClauses[] = 'raw_input = :raw_input';
            $params[':raw_input'] = $updates['rawInput'];
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

    } elseif ($entityType === 'ADVISORY') {
        // Update advisory
        $setClauses = ['updated_at = SYSUTCDATETIME()'];
        $params = [':advisory_id' => $entityId];

        // Time fields
        if (isset($updates['validFrom']) && $updates['validFrom']) {
            $setClauses[] = 'effective_from = :effective_from';
            $params[':effective_from'] = parseUtcDateTime($updates['validFrom']);
        }

        if (isset($updates['validUntil']) && $updates['validUntil']) {
            $setClauses[] = 'effective_until = :effective_until';
            $params[':effective_until'] = parseUtcDateTime($updates['validUntil']);
        }

        // Subject
        if (isset($updates['subject'])) {
            $setClauses[] = 'subject = :subject';
            $params[':subject'] = substr(trim($updates['subject']), 0, 256);
        }

        // Scope
        if (isset($updates['ctlElement'])) {
            $setClauses[] = 'ctl_element = :ctl_element';
            $params[':ctl_element'] = substr(strtoupper(trim($updates['ctlElement'])), 0, 8);
        }

        if (isset($updates['scopeFacilities'])) {
            $setClauses[] = 'scope_facilities = :scope_facilities';
            $params[':scope_facilities'] = strtoupper(trim($updates['scopeFacilities']));
        }

        // Reason
        if (isset($updates['reasonCode'])) {
            $setClauses[] = 'reason_code = :reason_code';
            $params[':reason_code'] = substr(strtoupper(trim($updates['reasonCode'])), 0, 16);
        }

        if (isset($updates['reasonDetail'])) {
            $setClauses[] = 'reason_detail = :reason_detail';
            $params[':reason_detail'] = substr(trim($updates['reasonDetail']), 0, 200);
        }

        // Body text
        if (isset($updates['bodyText'])) {
            $setClauses[] = 'body_text = :body_text';
            $params[':body_text'] = $updates['bodyText'];
        }

        // Program parameters
        if (isset($updates['programRate']) && $updates['programRate'] !== null) {
            $setClauses[] = 'program_rate = :program_rate';
            $params[':program_rate'] = intval($updates['programRate']);
        }

        if (isset($updates['delayCap']) && $updates['delayCap'] !== null) {
            $setClauses[] = 'delay_cap = :delay_cap';
            $params[':delay_cap'] = intval($updates['delayCap']);
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

    } elseif ($entityType === 'PROGRAM') {
        // Update GDT program (Ground Stop or GDP)
        $setClauses = ['modified_utc = SYSUTCDATETIME()'];
        $params = [':program_id' => $entityId];

        // Time fields
        if (isset($updates['validFrom']) && $updates['validFrom']) {
            $setClauses[] = 'start_utc = :start_utc';
            $params[':start_utc'] = parseUtcDateTime($updates['validFrom']);
        }

        if (isset($updates['validUntil']) && $updates['validUntil']) {
            $setClauses[] = 'end_utc = :end_utc';
            $params[':end_utc'] = parseUtcDateTime($updates['validUntil']);
        }

        // Program-specific fields
        if (isset($updates['programRate']) && $updates['programRate'] !== null) {
            $setClauses[] = 'program_rate = :program_rate';
            $params[':program_rate'] = intval($updates['programRate']);
        }

        if (isset($updates['impactingCondition'])) {
            $setClauses[] = 'impacting_condition = :impacting_condition';
            $params[':impacting_condition'] = substr(strtoupper(trim($updates['impactingCondition'])), 0, 32);
        }

        if (isset($updates['causeText'])) {
            $setClauses[] = 'cause_text = :cause_text';
            $params[':cause_text'] = substr(trim($updates['causeText']), 0, 512);
        }

        if (isset($updates['comments'])) {
            $setClauses[] = 'comments = :comments';
            $params[':comments'] = $updates['comments'];
        }

        if (isset($updates['scopeType'])) {
            $setClauses[] = 'scope_type = :scope_type';
            $params[':scope_type'] = substr(strtoupper(trim($updates['scopeType'])), 0, 16);
        }

        if (isset($updates['scopeCenters'])) {
            $setClauses[] = 'scope_centers_json = :scope_centers';
            $params[':scope_centers'] = is_array($updates['scopeCenters'])
                ? json_encode($updates['scopeCenters'])
                : $updates['scopeCenters'];
        }

        // Modified by
        if ($userName) {
            $setClauses[] = 'modified_by = :modified_by';
            $params[':modified_by'] = $userName;
        }

        $sql = "UPDATE dbo.tmi_programs
                SET " . implode(', ', $setClauses) . "
                WHERE program_id = :program_id
                  AND status NOT IN ('PURGED', 'COMPLETED', 'SUPERSEDED')";

        $stmt = $tmiConn->prepare($sql);
        $stmt->execute($params);

        $rowsAffected = $stmt->rowCount();

        if ($rowsAffected === 0) {
            echo json_encode([
                'success' => false,
                'error' => 'Program not found or already cancelled/completed'
            ]);
            exit;
        }

        // Log event
        logEditEvent($tmiConn, 'PROGRAM', $entityId, $updates, $userCid, $userName);

    } elseif ($entityType === 'REROUTE') {
        // Update reroute (ADL uses id, updated_utc)
        if (!$adlConn) {
            echo json_encode([
                'success' => false,
                'error' => 'ADL database not configured for reroute operations'
            ]);
            exit;
        }

        $setClauses = ['updated_utc = GETUTCDATE()'];
        $params = [':id' => $entityId];

        // Time fields
        if (isset($updates['validFrom']) && $updates['validFrom']) {
            $setClauses[] = 'start_utc = :start_utc';
            $params[':start_utc'] = parseUtcDateTime($updates['validFrom']);
        }

        if (isset($updates['validUntil']) && $updates['validUntil']) {
            $setClauses[] = 'end_utc = :end_utc';
            $params[':end_utc'] = parseUtcDateTime($updates['validUntil']);
        }

        // Reroute-specific fields
        if (isset($updates['name'])) {
            $setClauses[] = 'name = :name';
            $params[':name'] = substr(trim($updates['name']), 0, 64);
        }

        if (isset($updates['protectedSegment'])) {
            $setClauses[] = 'protected_segment = :protected_segment';
            $params[':protected_segment'] = $updates['protectedSegment'];
        }

        if (isset($updates['protectedFixes'])) {
            $setClauses[] = 'protected_fixes = :protected_fixes';
            $params[':protected_fixes'] = $updates['protectedFixes'];
        }

        if (isset($updates['avoidFixes'])) {
            $setClauses[] = 'avoid_fixes = :avoid_fixes';
            $params[':avoid_fixes'] = $updates['avoidFixes'];
        }

        if (isset($updates['originCenters'])) {
            $setClauses[] = 'origin_centers = :origin_centers';
            $params[':origin_centers'] = $updates['originCenters'];
        }

        if (isset($updates['destCenters'])) {
            $setClauses[] = 'dest_centers = :dest_centers';
            $params[':dest_centers'] = $updates['destCenters'];
        }

        if (isset($updates['impactingCondition'])) {
            $setClauses[] = 'impacting_condition = :impacting_condition';
            $params[':impacting_condition'] = substr(trim($updates['impactingCondition']), 0, 64);
        }

        if (isset($updates['comments'])) {
            $setClauses[] = 'comments = :comments';
            $params[':comments'] = $updates['comments'];
        }

        if (isset($updates['advisoryText'])) {
            $setClauses[] = 'advisory_text = :advisory_text';
            $params[':advisory_text'] = $updates['advisoryText'];
        }

        // Status 4=expired, 5=cancelled
        $sql = "UPDATE dbo.tmi_reroutes
                SET " . implode(', ', $setClauses) . "
                WHERE id = :id
                  AND status NOT IN (4, 5)";

        $stmt = $adlConn->prepare($sql);
        $stmt->execute($params);

        $rowsAffected = $stmt->rowCount();

        if ($rowsAffected === 0) {
            echo json_encode([
                'success' => false,
                'error' => 'Reroute not found or already cancelled/expired'
            ]);
            exit;
        }

        // Log event (to TMI events table)
        logEditEvent($tmiConn, 'REROUTE', $entityId, $updates, $userCid, $userName);
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
