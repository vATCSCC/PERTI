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
 * @version 1.0.0
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
    
    // Sort each category by valid_from or created_at
    usort($results['active'], function($a, $b) {
        return strtotime($b['valid_from'] ?? $b['created_at']) - strtotime($a['valid_from'] ?? $a['created_at']);
    });
    
    usort($results['scheduled'], function($a, $b) {
        return strtotime($a['valid_from'] ?? $a['created_at']) - strtotime($b['valid_from'] ?? $b['created_at']);
    });
    
    usort($results['cancelled'], function($a, $b) {
        return strtotime($b['cancelled_at'] ?? $b['updated_at']) - strtotime($a['cancelled_at'] ?? $a['updated_at']);
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
 * Get currently active NTML entries
 */
function getActiveNtmlEntries($conn, $limit) {
    // Check if tmi_entries table exists
    try {
        $check = $conn->query("SELECT TOP 1 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'tmi_entries'");
        if (!$check->fetch()) {
            return [];
        }
    } catch (Exception $e) {
        return [];
    }
    
    $sql = "SELECT TOP {$limit}
                e.entry_id,
                'ENTRY' as entity_type,
                e.entry_type,
                e.ctl_element,
                e.requesting_facility,
                e.providing_facility,
                e.restriction_value,
                e.reason_code,
                e.exclusions,
                e.valid_from,
                e.valid_until,
                e.raw_text,
                e.status,
                e.created_at,
                e.created_by
            FROM dbo.tmi_entries e
            WHERE e.status IN ('ACTIVE', 'PUBLISHED')
              AND (e.valid_until IS NULL OR e.valid_until > SYSUTCDATETIME())
              AND (e.valid_from IS NULL OR e.valid_from <= SYSUTCDATETIME())
            ORDER BY e.valid_from DESC";
    
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
    try {
        $check = $conn->query("SELECT TOP 1 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'tmi_entries'");
        if (!$check->fetch()) {
            return [];
        }
    } catch (Exception $e) {
        return [];
    }
    
    $sql = "SELECT TOP {$limit}
                e.entry_id,
                'ENTRY' as entity_type,
                e.entry_type,
                e.ctl_element,
                e.requesting_facility,
                e.providing_facility,
                e.restriction_value,
                e.reason_code,
                e.exclusions,
                e.valid_from,
                e.valid_until,
                e.raw_text,
                e.status,
                e.created_at,
                e.created_by
            FROM dbo.tmi_entries e
            WHERE e.status IN ('SCHEDULED', 'STAGED', 'PUBLISHED')
              AND e.valid_from > SYSUTCDATETIME()
            ORDER BY e.valid_from ASC";
    
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
    try {
        $check = $conn->query("SELECT TOP 1 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'tmi_entries'");
        if (!$check->fetch()) {
            return [];
        }
    } catch (Exception $e) {
        return [];
    }
    
    $sql = "SELECT TOP {$limit}
                e.entry_id,
                'ENTRY' as entity_type,
                e.entry_type,
                e.ctl_element,
                e.requesting_facility,
                e.providing_facility,
                e.restriction_value,
                e.reason_code,
                e.exclusions,
                e.valid_from,
                e.valid_until,
                e.raw_text,
                e.status,
                e.created_at,
                e.updated_at as cancelled_at,
                e.created_by
            FROM dbo.tmi_entries e
            WHERE e.status = 'CANCELLED'
              AND e.updated_at > DATEADD(HOUR, -{$hours}, SYSUTCDATETIME())
            ORDER BY e.updated_at DESC";
    
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
    try {
        $check = $conn->query("SELECT TOP 1 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'tmi_advisories'");
        if (!$check->fetch()) {
            return [];
        }
    } catch (Exception $e) {
        return [];
    }
    
    $sql = "SELECT TOP {$limit}
                a.advisory_id,
                'ADVISORY' as entity_type,
                a.advisory_type,
                a.advisory_number,
                a.facility_code,
                a.ctl_element,
                a.valid_from,
                a.valid_until,
                a.content_text,
                a.status,
                a.created_at,
                a.created_by
            FROM dbo.tmi_advisories a
            WHERE a.status IN ('ACTIVE', 'PUBLISHED')
              AND (a.valid_until IS NULL OR a.valid_until > SYSUTCDATETIME())
              AND (a.valid_from IS NULL OR a.valid_from <= SYSUTCDATETIME())
            ORDER BY a.valid_from DESC";
    
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
    try {
        $check = $conn->query("SELECT TOP 1 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'tmi_advisories'");
        if (!$check->fetch()) {
            return [];
        }
    } catch (Exception $e) {
        return [];
    }
    
    $sql = "SELECT TOP {$limit}
                a.advisory_id,
                'ADVISORY' as entity_type,
                a.advisory_type,
                a.advisory_number,
                a.facility_code,
                a.ctl_element,
                a.valid_from,
                a.valid_until,
                a.content_text,
                a.status,
                a.created_at,
                a.created_by
            FROM dbo.tmi_advisories a
            WHERE a.status IN ('SCHEDULED', 'STAGED', 'PUBLISHED')
              AND a.valid_from > SYSUTCDATETIME()
            ORDER BY a.valid_from ASC";
    
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
    try {
        $check = $conn->query("SELECT TOP 1 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'tmi_advisories'");
        if (!$check->fetch()) {
            return [];
        }
    } catch (Exception $e) {
        return [];
    }
    
    $sql = "SELECT TOP {$limit}
                a.advisory_id,
                'ADVISORY' as entity_type,
                a.advisory_type,
                a.advisory_number,
                a.facility_code,
                a.ctl_element,
                a.valid_from,
                a.valid_until,
                a.content_text,
                a.status,
                a.created_at,
                a.updated_at as cancelled_at,
                a.created_by
            FROM dbo.tmi_advisories a
            WHERE a.status = 'CANCELLED'
              AND a.updated_at > DATEADD(HOUR, -{$hours}, SYSUTCDATETIME())
            ORDER BY a.updated_at DESC";
    
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
        'entityId' => intval($row['entry_id']),
        'type' => 'ntml',
        'entryType' => $row['entry_type'],
        'summary' => buildNtmlSummary($row),
        'ctlElement' => $row['ctl_element'],
        'requestingFacility' => $row['requesting_facility'],
        'providingFacility' => $row['providing_facility'],
        'restrictionValue' => $row['restriction_value'],
        'reasonCode' => $row['reason_code'],
        'exclusions' => $row['exclusions'],
        'validFrom' => $row['valid_from'],
        'validUntil' => $row['valid_until'],
        'rawText' => $row['raw_text'],
        'status' => $row['status'],
        'createdAt' => $row['created_at'],
        'cancelledAt' => $row['cancelled_at'] ?? null,
        'createdBy' => $row['created_by']
    ];
}

/**
 * Format advisory for API response
 */
function formatAdvisory($row) {
    return [
        'entityType' => 'ADVISORY',
        'entityId' => intval($row['advisory_id']),
        'type' => 'advisory',
        'entryType' => $row['advisory_type'],
        'summary' => buildAdvisorySummary($row),
        'advisoryNumber' => $row['advisory_number'],
        'facilityCode' => $row['facility_code'],
        'ctlElement' => $row['ctl_element'],
        'validFrom' => $row['valid_from'],
        'validUntil' => $row['valid_until'],
        'contentText' => $row['content_text'],
        'status' => $row['status'],
        'createdAt' => $row['created_at'],
        'cancelledAt' => $row['cancelled_at'] ?? null,
        'createdBy' => $row['created_by']
    ];
}

/**
 * Build NTML summary line
 */
function buildNtmlSummary($row) {
    $parts = [];
    
    if (!empty($row['entry_type'])) {
        $parts[] = $row['entry_type'];
    }
    
    if (!empty($row['restriction_value'])) {
        $parts[] = $row['restriction_value'];
    }
    
    if (!empty($row['ctl_element'])) {
        $parts[] = $row['ctl_element'];
    }
    
    if (!empty($row['requesting_facility']) && !empty($row['providing_facility'])) {
        $parts[] = $row['requesting_facility'] . '→' . $row['providing_facility'];
    }
    
    if (!empty($row['reason_code'])) {
        $parts[] = $row['reason_code'];
    }
    
    return implode(' ', $parts) ?: 'NTML Entry';
}

/**
 * Build advisory summary line
 */
function buildAdvisorySummary($row) {
    $parts = [];
    
    if (!empty($row['advisory_type'])) {
        $parts[] = $row['advisory_type'];
    }
    
    if (!empty($row['advisory_number'])) {
        $parts[] = '#' . $row['advisory_number'];
    }
    
    if (!empty($row['ctl_element'])) {
        $parts[] = $row['ctl_element'];
    }
    
    if (!empty($row['facility_code'])) {
        $parts[] = $row['facility_code'];
    }
    
    return implode(' ', $parts) ?: 'Advisory';
}
