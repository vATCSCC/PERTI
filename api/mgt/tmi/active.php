<?php
/**
 * TMI Active Entries API
 * 
 * Lists currently active TMI entries and advisories, plus scheduled 
 * and recently cancelled ones.
 * 
 * GET /api/mgt/tmi/active.php
 * Optional query params:
 *   - type: 'ntml' | 'advisory' | 'all' (default: 'all')
 *   - include_scheduled: '1' to include future TMIs (default: '1')
 *   - include_cancelled: '1' to include recently cancelled (default: '1')
 *   - cancelled_hours: hours of cancelled history (default: 4)
 *   - limit: max results (default: 100)
 * 
 * @package PERTI
 * @subpackage API/TMI
 * @version 2.0.0
 * @date 2026-01-27
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
$type = $_GET['type'] ?? 'all';
$includeScheduled = ($_GET['include_scheduled'] ?? '1') === '1';
$includeCancelled = ($_GET['include_cancelled'] ?? '1') === '1';
$cancelledHours = intval($_GET['cancelled_hours'] ?? 4);
$limit = min(intval($_GET['limit'] ?? 100), 500);

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

$results = [
    'active' => [],
    'scheduled' => [],
    'cancelled' => []
];

try {
    // Get active NTML entries
    if ($type === 'all' || $type === 'ntml') {
        $activeNtml = getActiveNtmlEntries($tmiConn, $limit);
        $results['active'] = array_merge($results['active'], $activeNtml);
        
        if ($includeScheduled) {
            $scheduledNtml = getScheduledNtmlEntries($tmiConn, $limit);
            $results['scheduled'] = array_merge($results['scheduled'], $scheduledNtml);
        }
        
        if ($includeCancelled) {
            $cancelledNtml = getCancelledNtmlEntries($tmiConn, $cancelledHours, $limit);
            $results['cancelled'] = array_merge($results['cancelled'], $cancelledNtml);
        }
    }
    
    // Get active advisories
    if ($type === 'all' || $type === 'advisory') {
        $activeAdv = getActiveAdvisories($tmiConn, $limit);
        $results['active'] = array_merge($results['active'], $activeAdv);
        
        if ($includeScheduled) {
            $scheduledAdv = getScheduledAdvisories($tmiConn, $limit);
            $results['scheduled'] = array_merge($results['scheduled'], $scheduledAdv);
        }
        
        if ($includeCancelled) {
            $cancelledAdv = getCancelledAdvisories($tmiConn, $cancelledHours, $limit);
            $results['cancelled'] = array_merge($results['cancelled'], $cancelledAdv);
        }
    }
    
    // Sort each category
    usort($results['active'], function($a, $b) {
        $timeA = strtotime($a['validFrom'] ?? $a['createdAt'] ?? '1970-01-01');
        $timeB = strtotime($b['validFrom'] ?? $b['createdAt'] ?? '1970-01-01');
        return $timeB - $timeA;
    });
    
    usort($results['scheduled'], function($a, $b) {
        $timeA = strtotime($a['validFrom'] ?? $a['createdAt'] ?? '2999-12-31');
        $timeB = strtotime($b['validFrom'] ?? $b['createdAt'] ?? '2999-12-31');
        return $timeA - $timeB;
    });
    
    usort($results['cancelled'], function($a, $b) {
        $timeA = strtotime($a['cancelledAt'] ?? $a['updatedAt'] ?? '1970-01-01');
        $timeB = strtotime($b['cancelledAt'] ?? $b['updatedAt'] ?? '1970-01-01');
        return $timeB - $timeA;
    });
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Query failed: ' . $e->getMessage()]);
    exit;
}

echo json_encode([
    'success' => true,
    'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
    'counts' => [
        'active' => count($results['active']),
        'scheduled' => count($results['scheduled']),
        'cancelled' => count($results['cancelled'])
    ],
    'data' => $results
]);

// ===========================================
// Helper Functions
// ===========================================

/**
 * Check if table exists
 */
function tableExists($conn, $tableName) {
    try {
        $check = $conn->query("SELECT TOP 1 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = '{$tableName}'");
        return $check->fetch() ? true : false;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Format datetime for JSON output
 */
function formatDatetime($value) {
    if ($value === null) return null;
    if ($value instanceof DateTime) {
        return $value->format('c');
    }
    if (is_string($value) && !empty($value)) {
        $ts = strtotime($value);
        if ($ts !== false) {
            return gmdate('c', $ts);
        }
    }
    return null;
}

/**
 * Get currently active NTML entries
 */
function getActiveNtmlEntries($conn, $limit) {
    if (!tableExists($conn, 'tmi_entries')) {
        return [];
    }
    
    $sql = "SELECT TOP {$limit}
                entry_id,
                entry_guid,
                entry_type,
                determinant_code,
                ctl_element,
                element_type,
                requesting_facility,
                providing_facility,
                restriction_value,
                restriction_unit,
                condition_text,
                qualifiers,
                exclusions,
                reason_code,
                reason_detail,
                valid_from,
                valid_until,
                raw_input,
                status,
                discord_message_id,
                created_at,
                created_by,
                created_by_name
            FROM dbo.tmi_entries
            WHERE status IN ('ACTIVE', 'PUBLISHED')
              AND (valid_until IS NULL OR valid_until > SYSUTCDATETIME())
              AND (valid_from IS NULL OR valid_from <= SYSUTCDATETIME())
            ORDER BY valid_from DESC";
    
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        
        $entries = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $entries[] = formatNtmlEntry($row);
        }
        return $entries;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Get scheduled (future) NTML entries
 */
function getScheduledNtmlEntries($conn, $limit) {
    if (!tableExists($conn, 'tmi_entries')) {
        return [];
    }
    
    $sql = "SELECT TOP {$limit}
                entry_id,
                entry_guid,
                entry_type,
                determinant_code,
                ctl_element,
                element_type,
                requesting_facility,
                providing_facility,
                restriction_value,
                restriction_unit,
                condition_text,
                qualifiers,
                exclusions,
                reason_code,
                reason_detail,
                valid_from,
                valid_until,
                raw_input,
                status,
                discord_message_id,
                created_at,
                created_by,
                created_by_name
            FROM dbo.tmi_entries
            WHERE status IN ('SCHEDULED', 'STAGED')
              AND valid_from > SYSUTCDATETIME()
            ORDER BY valid_from ASC";
    
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        
        $entries = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $entries[] = formatNtmlEntry($row);
        }
        return $entries;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Get recently cancelled NTML entries
 */
function getCancelledNtmlEntries($conn, $hours, $limit) {
    if (!tableExists($conn, 'tmi_entries')) {
        return [];
    }
    
    $sql = "SELECT TOP {$limit}
                entry_id,
                entry_guid,
                entry_type,
                determinant_code,
                ctl_element,
                element_type,
                requesting_facility,
                providing_facility,
                restriction_value,
                restriction_unit,
                condition_text,
                qualifiers,
                exclusions,
                reason_code,
                reason_detail,
                valid_from,
                valid_until,
                raw_input,
                status,
                discord_message_id,
                created_at,
                updated_at,
                cancelled_at,
                cancel_reason,
                created_by,
                created_by_name
            FROM dbo.tmi_entries
            WHERE status = 'CANCELLED'
              AND updated_at > DATEADD(HOUR, -{$hours}, SYSUTCDATETIME())
            ORDER BY updated_at DESC";
    
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        
        $entries = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $entries[] = formatNtmlEntry($row);
        }
        return $entries;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Get currently active advisories
 */
function getActiveAdvisories($conn, $limit) {
    if (!tableExists($conn, 'tmi_advisories')) {
        return [];
    }
    
    $sql = "SELECT TOP {$limit}
                advisory_id,
                advisory_guid,
                advisory_number,
                advisory_type,
                ctl_element,
                element_type,
                scope_facilities,
                subject,
                body_text,
                reason_code,
                reason_detail,
                effective_from,
                effective_until,
                status,
                discord_message_id,
                created_at,
                created_by,
                created_by_name
            FROM dbo.tmi_advisories
            WHERE status IN ('ACTIVE', 'PUBLISHED')
              AND (effective_until IS NULL OR effective_until > SYSUTCDATETIME())
              AND (effective_from IS NULL OR effective_from <= SYSUTCDATETIME())
            ORDER BY effective_from DESC";
    
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        
        $entries = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $entries[] = formatAdvisory($row);
        }
        return $entries;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Get scheduled advisories
 */
function getScheduledAdvisories($conn, $limit) {
    if (!tableExists($conn, 'tmi_advisories')) {
        return [];
    }
    
    $sql = "SELECT TOP {$limit}
                advisory_id,
                advisory_guid,
                advisory_number,
                advisory_type,
                ctl_element,
                element_type,
                scope_facilities,
                subject,
                body_text,
                reason_code,
                reason_detail,
                effective_from,
                effective_until,
                status,
                discord_message_id,
                created_at,
                created_by,
                created_by_name
            FROM dbo.tmi_advisories
            WHERE status IN ('SCHEDULED', 'STAGED')
              AND effective_from > SYSUTCDATETIME()
            ORDER BY effective_from ASC";
    
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        
        $entries = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $entries[] = formatAdvisory($row);
        }
        return $entries;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Get recently cancelled advisories
 */
function getCancelledAdvisories($conn, $hours, $limit) {
    if (!tableExists($conn, 'tmi_advisories')) {
        return [];
    }
    
    $sql = "SELECT TOP {$limit}
                advisory_id,
                advisory_guid,
                advisory_number,
                advisory_type,
                ctl_element,
                element_type,
                scope_facilities,
                subject,
                body_text,
                reason_code,
                reason_detail,
                effective_from,
                effective_until,
                status,
                discord_message_id,
                created_at,
                updated_at,
                cancelled_at,
                cancel_reason,
                created_by,
                created_by_name
            FROM dbo.tmi_advisories
            WHERE status = 'CANCELLED'
              AND updated_at > DATEADD(HOUR, -{$hours}, SYSUTCDATETIME())
            ORDER BY updated_at DESC";
    
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        
        $entries = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $entries[] = formatAdvisory($row);
        }
        return $entries;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Format NTML entry for API response
 */
function formatNtmlEntry($row) {
    return [
        'entityType' => 'ENTRY',
        'entityId' => intval($row['entry_id'] ?? 0),
        'guid' => $row['entry_guid'] ?? null,
        'type' => 'ntml',
        'entryType' => $row['entry_type'] ?? 'UNKNOWN',
        'determinantCode' => $row['determinant_code'] ?? null,
        'summary' => buildNtmlSummary($row),
        'ctlElement' => $row['ctl_element'] ?? null,
        'elementType' => $row['element_type'] ?? null,
        'requestingFacility' => $row['requesting_facility'] ?? null,
        'providingFacility' => $row['providing_facility'] ?? null,
        'restrictionValue' => $row['restriction_value'] ?? null,
        'restrictionUnit' => $row['restriction_unit'] ?? null,
        'conditionText' => $row['condition_text'] ?? null,
        'qualifiers' => $row['qualifiers'] ?? null,
        'exclusions' => $row['exclusions'] ?? null,
        'reasonCode' => $row['reason_code'] ?? null,
        'reasonDetail' => $row['reason_detail'] ?? null,
        'validFrom' => formatDatetime($row['valid_from'] ?? null),
        'validUntil' => formatDatetime($row['valid_until'] ?? null),
        'rawText' => $row['raw_input'] ?? null,
        'status' => $row['status'] ?? 'UNKNOWN',
        'discordMessageId' => $row['discord_message_id'] ?? null,
        'createdAt' => formatDatetime($row['created_at'] ?? null),
        'updatedAt' => formatDatetime($row['updated_at'] ?? null),
        'cancelledAt' => formatDatetime($row['cancelled_at'] ?? null),
        'cancelReason' => $row['cancel_reason'] ?? null,
        'createdBy' => $row['created_by'] ?? null,
        'createdByName' => $row['created_by_name'] ?? null
    ];
}

/**
 * Format advisory for API response
 */
function formatAdvisory($row) {
    return [
        'entityType' => 'ADVISORY',
        'entityId' => intval($row['advisory_id'] ?? 0),
        'guid' => $row['advisory_guid'] ?? null,
        'type' => 'advisory',
        'entryType' => $row['advisory_type'] ?? 'UNKNOWN',
        'advisoryNumber' => $row['advisory_number'] ?? null,
        'summary' => buildAdvisorySummary($row),
        'ctlElement' => $row['ctl_element'] ?? null,
        'elementType' => $row['element_type'] ?? null,
        'scopeFacilities' => $row['scope_facilities'] ?? null,
        'subject' => $row['subject'] ?? null,
        'bodyText' => $row['body_text'] ?? null,
        'reasonCode' => $row['reason_code'] ?? null,
        'reasonDetail' => $row['reason_detail'] ?? null,
        'validFrom' => formatDatetime($row['effective_from'] ?? null),
        'validUntil' => formatDatetime($row['effective_until'] ?? null),
        'status' => $row['status'] ?? 'UNKNOWN',
        'discordMessageId' => $row['discord_message_id'] ?? null,
        'createdAt' => formatDatetime($row['created_at'] ?? null),
        'updatedAt' => formatDatetime($row['updated_at'] ?? null),
        'cancelledAt' => formatDatetime($row['cancelled_at'] ?? null),
        'cancelReason' => $row['cancel_reason'] ?? null,
        'createdBy' => $row['created_by'] ?? null,
        'createdByName' => $row['created_by_name'] ?? null
    ];
}

/**
 * Build NTML summary line
 */
function buildNtmlSummary($row) {
    $parts = [];
    
    $entryType = $row['entry_type'] ?? '';
    if (!empty($entryType)) {
        $parts[] = $entryType;
    }
    
    $value = $row['restriction_value'] ?? '';
    $unit = $row['restriction_unit'] ?? '';
    if (!empty($value)) {
        $parts[] = $value . ($unit === 'NM' ? 'MIT' : ($unit === 'MIN' ? 'MINIT' : ''));
    }
    
    $element = $row['ctl_element'] ?? '';
    if (!empty($element)) {
        $parts[] = $element;
    }
    
    $via = $row['condition_text'] ?? '';
    if (!empty($via)) {
        $parts[] = 'via ' . $via;
    }
    
    $reqFac = $row['requesting_facility'] ?? '';
    $provFac = $row['providing_facility'] ?? '';
    if (!empty($reqFac) && !empty($provFac)) {
        $parts[] = $reqFac . ':' . $provFac;
    }
    
    $reason = $row['reason_code'] ?? '';
    $detail = $row['reason_detail'] ?? '';
    if (!empty($reason)) {
        $reasonStr = $reason;
        if (!empty($detail) && $detail !== $reason) {
            $reasonStr .= ':' . $detail;
        }
        $parts[] = $reasonStr;
    }
    
    return implode(' ', $parts) ?: 'NTML Entry';
}

/**
 * Build advisory summary line
 */
function buildAdvisorySummary($row) {
    $parts = [];
    
    $advType = $row['advisory_type'] ?? '';
    if (!empty($advType)) {
        $parts[] = $advType;
    }
    
    $advNum = $row['advisory_number'] ?? '';
    if (!empty($advNum)) {
        $parts[] = '#' . $advNum;
    }
    
    $subject = $row['subject'] ?? '';
    if (!empty($subject) && strlen($subject) <= 40) {
        $parts[] = $subject;
    }
    
    $element = $row['ctl_element'] ?? '';
    if (!empty($element)) {
        $parts[] = $element;
    }
    
    return implode(' ', $parts) ?: 'Advisory';
}
