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
    require_once __DIR__ . '/../../../load/perti_constants.php';
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

// Date filter: if provided, get TMIs active on that date (YYYY-MM-DD format)
$filterDate = $_GET['date'] ?? null;
$filterDateStart = null;
$filterDateEnd = null;
if ($filterDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDate)) {
    $filterDateStart = $filterDate . ' 00:00:00';
    $filterDateEnd = $filterDate . ' 23:59:59';
}

// Source filter: PRODUCTION (default), STAGING, or ALL
$source = strtoupper($_GET['source'] ?? 'PRODUCTION');
$includeStaging = ($source === 'ALL' || $source === 'STAGING');
$stagingOnly = ($source === 'STAGING');

// Get org code from session context
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
$org_code = $_SESSION['ORG_CODE'] ?? 'vatcscc';

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
    // ADL connection failure is non-fatal - just log and continue without reroutes
    $adlConn = null;
}

$results = [
    'active' => [],
    'scheduled' => [],
    'cancelled' => []
];

try {
    // If date filter is provided, get historical data for that date
    if ($filterDateStart && $filterDateEnd) {
        // Get TMIs that were active on the specified date
        if ($type === 'all' || $type === 'ntml') {
            $results['active'] = array_merge($results['active'],
                getHistoricalNtmlEntries($tmiConn, $filterDateStart, $filterDateEnd, $limit, $includeStaging, $stagingOnly));
        }
        if ($type === 'all' || $type === 'advisory' || $type === 'advisories') {
            $results['active'] = array_merge($results['active'],
                getHistoricalAdvisories($tmiConn, $filterDateStart, $filterDateEnd, $limit, $includeStaging, $stagingOnly));
        }
        if ($type === 'all' || $type === 'program' || $type === 'programs' || $type === 'gdt') {
            $results['active'] = array_merge($results['active'],
                getHistoricalPrograms($tmiConn, $filterDateStart, $filterDateEnd, $limit));
        }
        $rerouteConn = $tmiConn ?? $adlConn;
        if (($type === 'all' || $type === 'reroute' || $type === 'reroutes') && $rerouteConn) {
            $results['active'] = array_merge($results['active'],
                getHistoricalReroutes($rerouteConn, $filterDateStart, $filterDateEnd, $limit));
        }
        if (($type === 'all' || $type === 'reroute' || $type === 'reroutes' || $type === 'publicroute') && $tmiConn) {
            $results['active'] = array_merge($results['active'],
                getHistoricalPublicRoutes($tmiConn, $filterDateStart, $filterDateEnd, $limit));
        }
    } else {
        // Normal behavior - get current active/scheduled/cancelled
        // Get active NTML entries
        if ($type === 'all' || $type === 'ntml') {
            $activeNtml = getActiveNtmlEntries($tmiConn, $limit, $includeStaging, $stagingOnly);
            $results['active'] = array_merge($results['active'], $activeNtml);

            if ($includeScheduled) {
                $scheduledNtml = getScheduledNtmlEntries($tmiConn, $limit, $includeStaging, $stagingOnly);
                $results['scheduled'] = array_merge($results['scheduled'], $scheduledNtml);
            }

            if ($includeCancelled) {
                $cancelledNtml = getCancelledNtmlEntries($tmiConn, $cancelledHours, $limit);
                $results['cancelled'] = array_merge($results['cancelled'], $cancelledNtml);
            }
        }

        // Get active advisories
        if ($type === 'all' || $type === 'advisory' || $type === 'advisories') {
            $activeAdv = getActiveAdvisories($tmiConn, $limit, $includeStaging, $stagingOnly);
            $results['active'] = array_merge($results['active'], $activeAdv);

            if ($includeScheduled) {
                $scheduledAdv = getScheduledAdvisories($tmiConn, $limit, $includeStaging, $stagingOnly);
                $results['scheduled'] = array_merge($results['scheduled'], $scheduledAdv);
            }

            if ($includeCancelled) {
                $cancelledAdv = getCancelledAdvisories($tmiConn, $cancelledHours, $limit);
                $results['cancelled'] = array_merge($results['cancelled'], $cancelledAdv);
            }
        }

        // Get active GDT programs (Ground Stops, GDPs)
        if ($type === 'all' || $type === 'program' || $type === 'programs' || $type === 'gdt') {
            $activePrograms = getActivePrograms($tmiConn, $limit);
            $results['active'] = array_merge($results['active'], $activePrograms);

            if ($includeScheduled) {
                $scheduledPrograms = getScheduledPrograms($tmiConn, $limit);
                $results['scheduled'] = array_merge($results['scheduled'], $scheduledPrograms);
            }

            if ($includeCancelled) {
                $cancelledPrograms = getCancelledPrograms($tmiConn, $cancelledHours, $limit);
                $results['cancelled'] = array_merge($results['cancelled'], $cancelledPrograms);
            }
        }

        // Get active reroutes (prefer TMI database, fallback to ADL)
        // Note: Public routes have been migrated to VATSIM_TMI database
        $rerouteConn = $tmiConn ?? $adlConn;
        if (($type === 'all' || $type === 'reroute' || $type === 'reroutes') && $rerouteConn) {
            $activeReroutes = getActiveReroutes($rerouteConn, $limit);
            $results['active'] = array_merge($results['active'], $activeReroutes);

            if ($includeScheduled) {
                $scheduledReroutes = getScheduledReroutes($rerouteConn, $limit);
                $results['scheduled'] = array_merge($results['scheduled'], $scheduledReroutes);
            }

            if ($includeCancelled) {
                $cancelledReroutes = getCancelledReroutes($rerouteConn, $cancelledHours, $limit);
                $results['cancelled'] = array_merge($results['cancelled'], $cancelledReroutes);
            }
        }

        // Get active public routes (from tmi_public_routes table)
        if (($type === 'all' || $type === 'reroute' || $type === 'reroutes' || $type === 'publicroute') && $tmiConn) {
            $activePublicRoutes = getActivePublicRoutes($tmiConn, $limit);
            $results['active'] = array_merge($results['active'], $activePublicRoutes);

            if ($includeScheduled) {
                $scheduledPublicRoutes = getScheduledPublicRoutes($tmiConn, $limit);
                $results['scheduled'] = array_merge($results['scheduled'], $scheduledPublicRoutes);
            }

            if ($includeCancelled) {
                $cancelledPublicRoutes = getCancelledPublicRoutes($tmiConn, $cancelledHours, $limit);
                $results['cancelled'] = array_merge($results['cancelled'], $cancelledPublicRoutes);
            }
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
function getActiveNtmlEntries($conn, $limit, $includeStaging = false, $stagingOnly = false) {
    global $org_code;
    if (!tableExists($conn, 'tmi_entries')) {
        return [];
    }

    // Status filter based on source selection
    if ($stagingOnly) {
        $statusIn = "('STAGED')";
    } elseif ($includeStaging) {
        $statusIn = "('ACTIVE', 'PUBLISHED', 'STAGED')";
    } else {
        $statusIn = "('ACTIVE', 'PUBLISHED')";
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
            WHERE status IN {$statusIn}
              AND org_code = :org_code
              AND (valid_until IS NULL OR valid_until > SYSUTCDATETIME())
              AND (valid_from IS NULL OR valid_from <= SYSUTCDATETIME())
            ORDER BY valid_from DESC";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute([':org_code' => $org_code ?? 'vatcscc']);
        
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
 * Includes entries with explicit SCHEDULED status AND entries with ACTIVE/PUBLISHED
 * status but future valid_from (e.g., CONFIG entries published for a future time)
 */
function getScheduledNtmlEntries($conn, $limit, $includeStaging = false, $stagingOnly = false) {
    global $org_code;
    if (!tableExists($conn, 'tmi_entries')) {
        return [];
    }

    // Build WHERE clause based on source selection
    // Include: SCHEDULED status OR (ACTIVE/PUBLISHED with future valid_from)
    if ($stagingOnly) {
        $whereClause = "(status = 'STAGED' AND valid_from > SYSUTCDATETIME())";
    } elseif ($includeStaging) {
        $whereClause = "(
            (status IN ('SCHEDULED', 'STAGED') AND valid_from > SYSUTCDATETIME())
            OR (status IN ('ACTIVE', 'PUBLISHED') AND valid_from > SYSUTCDATETIME())
        )";
    } else {
        $whereClause = "(
            (status = 'SCHEDULED' AND valid_from > SYSUTCDATETIME())
            OR (status IN ('ACTIVE', 'PUBLISHED') AND valid_from > SYSUTCDATETIME())
        )";
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
            WHERE {$whereClause}
              AND org_code = :org_code
            ORDER BY valid_from ASC";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute([':org_code' => $org_code ?? 'vatcscc']);

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
    global $org_code;
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
              AND org_code = :org_code
              AND updated_at > DATEADD(HOUR, -{$hours}, SYSUTCDATETIME())
            ORDER BY updated_at DESC";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute([':org_code' => $org_code ?? 'vatcscc']);

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
function getActiveAdvisories($conn, $limit, $includeStaging = false, $stagingOnly = false) {
    global $org_code;
    if (!tableExists($conn, 'tmi_advisories')) {
        return [];
    }

    // Status filter based on source selection
    if ($stagingOnly) {
        $statusIn = "('STAGED')";
    } elseif ($includeStaging) {
        $statusIn = "('ACTIVE', 'PUBLISHED', 'STAGED')";
    } else {
        $statusIn = "('ACTIVE', 'PUBLISHED')";
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
            WHERE status IN {$statusIn}
              AND org_code = :org_code
              AND (effective_until IS NULL OR effective_until > SYSUTCDATETIME())
              AND (effective_from IS NULL OR effective_from <= SYSUTCDATETIME())
            ORDER BY effective_from DESC";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute([':org_code' => $org_code ?? 'vatcscc']);
        
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
function getScheduledAdvisories($conn, $limit, $includeStaging = false, $stagingOnly = false) {
    global $org_code;
    if (!tableExists($conn, 'tmi_advisories')) {
        return [];
    }

    // Status filter based on source selection
    if ($stagingOnly) {
        $statusIn = "('STAGED')";
    } elseif ($includeStaging) {
        $statusIn = "('SCHEDULED', 'STAGED')";
    } else {
        $statusIn = "('SCHEDULED')";
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
            WHERE status IN {$statusIn}
              AND org_code = :org_code
              AND effective_from > SYSUTCDATETIME()
            ORDER BY effective_from ASC";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute([':org_code' => $org_code ?? 'vatcscc']);
        
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
    global $org_code;
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
              AND org_code = :org_code
              AND updated_at > DATEADD(HOUR, -{$hours}, SYSUTCDATETIME())
            ORDER BY updated_at DESC";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute([':org_code' => $org_code ?? 'vatcscc']);
        
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

// ===========================================
// GDT Programs (Ground Stops, GDPs)
// ===========================================

/**
 * Get currently active GDT programs
 */
function getActivePrograms($conn, $limit) {
    global $org_code;
    if (!tableExists($conn, 'tmi_programs')) {
        return [];
    }

    $sql = "SELECT TOP {$limit}
                program_id,
                program_guid,
                ctl_element,
                element_type,
                program_type,
                program_name,
                adv_number,
                start_utc,
                end_utc,
                status,
                program_rate,
                scope_type,
                scope_centers_json,
                impacting_condition,
                cause_text,
                comments,
                total_flights,
                controlled_flights,
                exempt_flights,
                created_by,
                created_utc,
                modified_utc,
                activated_utc
            FROM dbo.tmi_programs
            WHERE status IN ('ACTIVE', 'MODELING')
              AND org_code = :org_code
              AND (end_utc IS NULL OR end_utc > SYSUTCDATETIME())
              AND (start_utc IS NULL OR start_utc <= SYSUTCDATETIME())
            ORDER BY start_utc DESC";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute([':org_code' => $org_code ?? 'vatcscc']);

        $entries = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $entries[] = formatProgram($row);
        }
        return $entries;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Get scheduled (future) GDT programs
 */
function getScheduledPrograms($conn, $limit) {
    global $org_code;
    if (!tableExists($conn, 'tmi_programs')) {
        return [];
    }

    $sql = "SELECT TOP {$limit}
                program_id,
                program_guid,
                ctl_element,
                element_type,
                program_type,
                program_name,
                adv_number,
                start_utc,
                end_utc,
                status,
                program_rate,
                scope_type,
                scope_centers_json,
                impacting_condition,
                cause_text,
                comments,
                total_flights,
                controlled_flights,
                exempt_flights,
                created_by,
                created_utc,
                modified_utc
            FROM dbo.tmi_programs
            WHERE status IN ('PROPOSED', 'SCHEDULED')
              AND org_code = :org_code
              AND start_utc > SYSUTCDATETIME()
            ORDER BY start_utc ASC";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute([':org_code' => $org_code ?? 'vatcscc']);

        $entries = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $entries[] = formatProgram($row);
        }
        return $entries;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Get recently cancelled/completed GDT programs
 */
function getCancelledPrograms($conn, $hours, $limit) {
    global $org_code;
    if (!tableExists($conn, 'tmi_programs')) {
        return [];
    }

    $sql = "SELECT TOP {$limit}
                program_id,
                program_guid,
                ctl_element,
                element_type,
                program_type,
                program_name,
                adv_number,
                start_utc,
                end_utc,
                status,
                program_rate,
                scope_type,
                scope_centers_json,
                impacting_condition,
                cause_text,
                comments,
                total_flights,
                controlled_flights,
                exempt_flights,
                created_by,
                created_utc,
                modified_utc,
                completed_utc,
                purged_utc
            FROM dbo.tmi_programs
            WHERE status IN ('COMPLETED', 'PURGED', 'SUPERSEDED')
              AND org_code = :org_code
              AND modified_utc > DATEADD(HOUR, -{$hours}, SYSUTCDATETIME())
            ORDER BY modified_utc DESC";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute([':org_code' => $org_code ?? 'vatcscc']);

        $entries = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $entries[] = formatProgram($row);
        }
        return $entries;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Format GDT program for API response
 */
function formatProgram($row) {
    $programType = $row['program_type'] ?? 'UNKNOWN';

    return [
        'entityType' => 'PROGRAM',
        'entityId' => intval($row['program_id'] ?? 0),
        'guid' => $row['program_guid'] ?? null,
        'type' => 'program',
        'entryType' => $programType,
        'summary' => buildProgramSummary($row),
        'ctlElement' => $row['ctl_element'] ?? null,
        'elementType' => $row['element_type'] ?? null,
        'programName' => $row['program_name'] ?? null,
        'advisoryNumber' => $row['adv_number'] ?? null,
        'programRate' => $row['program_rate'] ?? null,
        'scopeType' => $row['scope_type'] ?? null,
        'scopeCenters' => $row['scope_centers_json'] ?? null,
        'impactingCondition' => $row['impacting_condition'] ?? null,
        'causeText' => $row['cause_text'] ?? null,
        'comments' => $row['comments'] ?? null,
        'totalFlights' => $row['total_flights'] ?? null,
        'controlledFlights' => $row['controlled_flights'] ?? null,
        'exemptFlights' => $row['exempt_flights'] ?? null,
        'validFrom' => formatDatetime($row['start_utc'] ?? null),
        'validUntil' => formatDatetime($row['end_utc'] ?? null),
        'status' => $row['status'] ?? 'UNKNOWN',
        'createdAt' => formatDatetime($row['created_utc'] ?? null),
        'updatedAt' => formatDatetime($row['modified_utc'] ?? null),
        'activatedAt' => formatDatetime($row['activated_utc'] ?? null),
        'cancelledAt' => formatDatetime($row['completed_utc'] ?? $row['purged_utc'] ?? null),
        'createdBy' => $row['created_by'] ?? null
    ];
}

/**
 * Build program summary line
 */
function buildProgramSummary($row) {
    $parts = [];

    $programType = $row['program_type'] ?? '';
    if (!empty($programType)) {
        // Simplify type display
        $typeMap = [
            'GS' => 'GS',
            'GDP-DAS' => 'GDP',
            'GDP-GAAP' => 'GDP',
            'GDP-UDP' => 'GDP',
            'AFP' => 'AFP'
        ];
        $parts[] = $typeMap[$programType] ?? $programType;
    }

    $element = $row['ctl_element'] ?? '';
    if (!empty($element)) {
        $parts[] = $element;
    }

    $rate = $row['program_rate'] ?? '';
    if (!empty($rate)) {
        $parts[] = "({$rate}/hr)";
    }

    $cause = $row['impacting_condition'] ?? '';
    if (!empty($cause)) {
        $parts[] = $cause;
    }

    return implode(' ', $parts) ?: 'Program';
}

// ===========================================
// Reroutes
// ===========================================

/**
 * Get currently active reroutes
 * Note: TMI database uses reroute_id, created_at, updated_at
 * Uses aliases to normalize column names for formatReroute()
 */
function getActiveReroutes($conn, $limit) {
    global $org_code;
    if (!tableExists($conn, 'tmi_reroutes')) {
        return [];
    }

    $sql = "SELECT TOP {$limit}
                reroute_id AS id,
                name,
                adv_number,
                status,
                start_utc,
                end_utc,
                protected_segment,
                protected_fixes,
                avoid_fixes,
                route_type,
                origin_airports,
                origin_centers,
                dest_airports,
                dest_centers,
                impacting_condition,
                comments,
                advisory_text,
                created_by,
                created_at AS created_utc,
                updated_at AS updated_utc,
                activated_at AS activated_utc
            FROM dbo.tmi_reroutes
            WHERE status IN (2, 3)  -- active, monitoring
              AND org_code = :org_code
              AND (end_utc IS NULL OR end_utc > GETUTCDATE())
              AND (start_utc IS NULL OR start_utc <= GETUTCDATE())
            ORDER BY start_utc DESC";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute([':org_code' => $org_code ?? 'vatcscc']);

        $entries = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $entries[] = formatReroute($row);
        }
        return $entries;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Get scheduled (future) reroutes
 */
function getScheduledReroutes($conn, $limit) {
    global $org_code;
    if (!tableExists($conn, 'tmi_reroutes')) {
        return [];
    }

    $sql = "SELECT TOP {$limit}
                reroute_id AS id,
                name,
                adv_number,
                status,
                start_utc,
                end_utc,
                protected_segment,
                protected_fixes,
                avoid_fixes,
                route_type,
                origin_airports,
                origin_centers,
                dest_airports,
                dest_centers,
                impacting_condition,
                comments,
                advisory_text,
                created_by,
                created_at AS created_utc,
                updated_at AS updated_utc
            FROM dbo.tmi_reroutes
            WHERE status IN (0, 1)  -- draft, proposed
              AND org_code = :org_code
              AND start_utc > GETUTCDATE()
            ORDER BY start_utc ASC";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute([':org_code' => $org_code ?? 'vatcscc']);

        $entries = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $entries[] = formatReroute($row);
        }
        return $entries;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Get recently cancelled/expired reroutes
 */
function getCancelledReroutes($conn, $hours, $limit) {
    global $org_code;
    if (!tableExists($conn, 'tmi_reroutes')) {
        return [];
    }

    $sql = "SELECT TOP {$limit}
                reroute_id AS id,
                name,
                adv_number,
                status,
                start_utc,
                end_utc,
                protected_segment,
                protected_fixes,
                avoid_fixes,
                route_type,
                origin_airports,
                origin_centers,
                dest_airports,
                dest_centers,
                impacting_condition,
                comments,
                advisory_text,
                created_by,
                created_at AS created_utc,
                updated_at AS updated_utc
            FROM dbo.tmi_reroutes
            WHERE status IN (4, 5)  -- expired, cancelled
              AND org_code = :org_code
              AND updated_at > DATEADD(HOUR, -{$hours}, GETUTCDATE())
            ORDER BY updated_at DESC";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute([':org_code' => $org_code ?? 'vatcscc']);

        $entries = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $entries[] = formatReroute($row);
        }
        return $entries;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Format reroute for API response
 * Note: ADL database uses id (not reroute_id), created_utc, updated_utc, activated_utc
 */
function formatReroute($row) {
    // Map numeric status to string
    $statusMap = [
        0 => 'DRAFT',
        1 => 'PROPOSED',
        2 => 'ACTIVE',
        3 => 'MONITORING',
        4 => 'EXPIRED',
        5 => 'CANCELLED'
    ];
    $status = $statusMap[intval($row['status'] ?? 0)] ?? 'UNKNOWN';

    return [
        'entityType' => 'REROUTE',
        'entityId' => intval($row['id'] ?? 0),
        'type' => 'reroute',
        'entryType' => 'REROUTE',
        'summary' => buildRerouteSummary($row),
        'name' => $row['name'] ?? null,
        'advisoryNumber' => $row['adv_number'] ?? null,
        'protectedSegment' => $row['protected_segment'] ?? null,
        'protectedFixes' => $row['protected_fixes'] ?? null,
        'avoidFixes' => $row['avoid_fixes'] ?? null,
        'routeType' => $row['route_type'] ?? null,
        'originAirports' => $row['origin_airports'] ?? null,
        'originCenters' => $row['origin_centers'] ?? null,
        'destAirports' => $row['dest_airports'] ?? null,
        'destCenters' => $row['dest_centers'] ?? null,
        'impactingCondition' => $row['impacting_condition'] ?? null,
        'comments' => $row['comments'] ?? null,
        'advisoryText' => $row['advisory_text'] ?? null,
        'validFrom' => formatDatetime($row['start_utc'] ?? null),
        'validUntil' => formatDatetime($row['end_utc'] ?? null),
        'status' => $status,
        'createdAt' => formatDatetime($row['created_utc'] ?? null),
        'updatedAt' => formatDatetime($row['updated_utc'] ?? null),
        'activatedAt' => formatDatetime($row['activated_utc'] ?? null),
        'createdBy' => $row['created_by'] ?? null
    ];
}

/**
 * Build reroute summary line
 */
function buildRerouteSummary($row) {
    $parts = [];

    $name = $row['name'] ?? '';
    if (!empty($name)) {
        $parts[] = $name;
    }

    $origin = $row['origin_centers'] ?? $row['origin_airports'] ?? '';
    $dest = $row['dest_centers'] ?? $row['dest_airports'] ?? '';
    if (!empty($origin) && !empty($dest)) {
        $parts[] = "{$origin}→{$dest}";
    } elseif (!empty($origin)) {
        $parts[] = "from {$origin}";
    } elseif (!empty($dest)) {
        $parts[] = "to {$dest}";
    }

    $cause = $row['impacting_condition'] ?? '';
    if (!empty($cause)) {
        $parts[] = "({$cause})";
    }

    return implode(' ', $parts) ?: 'Reroute';
}

// ===========================================
// Public Routes (from tmi_public_routes)
// ===========================================

/**
 * Get currently active public routes
 */
function getActivePublicRoutes($conn, $limit) {
    global $org_code;
    if (!tableExists($conn, 'tmi_public_routes')) {
        return [];
    }

    $sql = "SELECT TOP {$limit}
                route_id,
                route_guid,
                name,
                adv_number,
                status,
                route_string,
                advisory_text,
                valid_start_utc,
                valid_end_utc,
                constrained_area,
                reason,
                origin_filter,
                dest_filter,
                facilities,
                created_by,
                created_at,
                updated_at
            FROM dbo.tmi_public_routes
            WHERE status = 1
              AND org_code = :org_code
              AND (valid_end_utc IS NULL OR valid_end_utc > SYSUTCDATETIME())
              AND (valid_start_utc IS NULL OR valid_start_utc <= SYSUTCDATETIME())
            ORDER BY valid_start_utc DESC";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute([':org_code' => $org_code ?? 'vatcscc']);

        $entries = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $entries[] = formatPublicRoute($row);
        }
        return $entries;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Get scheduled (future) public routes
 */
function getScheduledPublicRoutes($conn, $limit) {
    global $org_code;
    if (!tableExists($conn, 'tmi_public_routes')) {
        return [];
    }

    $sql = "SELECT TOP {$limit}
                route_id,
                route_guid,
                name,
                adv_number,
                status,
                route_string,
                advisory_text,
                valid_start_utc,
                valid_end_utc,
                constrained_area,
                reason,
                origin_filter,
                dest_filter,
                facilities,
                created_by,
                created_at,
                updated_at
            FROM dbo.tmi_public_routes
            WHERE status = 1
              AND org_code = :org_code
              AND valid_start_utc > SYSUTCDATETIME()
            ORDER BY valid_start_utc ASC";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute([':org_code' => $org_code ?? 'vatcscc']);

        $entries = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $entries[] = formatPublicRoute($row);
        }
        return $entries;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Get recently cancelled/expired public routes
 */
function getCancelledPublicRoutes($conn, $hours, $limit) {
    global $org_code;
    if (!tableExists($conn, 'tmi_public_routes')) {
        return [];
    }

    $sql = "SELECT TOP {$limit}
                route_id,
                route_guid,
                name,
                adv_number,
                status,
                route_string,
                advisory_text,
                valid_start_utc,
                valid_end_utc,
                constrained_area,
                reason,
                origin_filter,
                dest_filter,
                facilities,
                created_by,
                created_at,
                updated_at
            FROM dbo.tmi_public_routes
            WHERE (status = 0 OR valid_end_utc < SYSUTCDATETIME())
              AND org_code = :org_code
              AND updated_at > DATEADD(HOUR, -{$hours}, SYSUTCDATETIME())
            ORDER BY updated_at DESC";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute([':org_code' => $org_code ?? 'vatcscc']);

        $entries = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $entries[] = formatPublicRoute($row);
        }
        return $entries;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Format public route for API response
 */
function formatPublicRoute($row) {
    // Parse JSON fields for origin/dest filters
    $originFilter = !empty($row['origin_filter']) ? json_decode($row['origin_filter'], true) : null;
    $destFilter = !empty($row['dest_filter']) ? json_decode($row['dest_filter'], true) : null;

    // Convert arrays to space-separated strings for display
    $originStr = is_array($originFilter) ? implode(' ', $originFilter) : ($originFilter ?? '');
    $destStr = is_array($destFilter) ? implode(' ', $destFilter) : ($destFilter ?? '');

    // Map numeric status to string
    $statusMap = [
        0 => 'CANCELLED',
        1 => 'ACTIVE'
    ];
    $status = $statusMap[intval($row['status'] ?? 0)] ?? 'UNKNOWN';

    return [
        'entityType' => 'REROUTE',
        'entityId' => intval($row['route_id'] ?? 0),
        'guid' => $row['route_guid'] ?? null,
        'type' => 'publicroute',
        'entryType' => 'REROUTE',
        'summary' => buildPublicRouteSummary($row),
        'name' => $row['name'] ?? null,
        'advisoryNumber' => $row['adv_number'] ?? null,
        'routeString' => $row['route_string'] ?? null,
        'advisoryText' => $row['advisory_text'] ?? null,
        'constrainedArea' => $row['constrained_area'] ?? null,
        'originCenters' => $originStr,
        'destCenters' => $destStr,
        'facilities' => $row['facilities'] ?? null,
        'impactingCondition' => $row['reason'] ?? null,
        'validFrom' => formatDatetime($row['valid_start_utc'] ?? null),
        'validUntil' => formatDatetime($row['valid_end_utc'] ?? null),
        'status' => $status,
        'createdAt' => formatDatetime($row['created_at'] ?? null),
        'updatedAt' => formatDatetime($row['updated_at'] ?? null),
        'createdBy' => $row['created_by'] ?? null
    ];
}

/**
 * Build public route summary line
 */
function buildPublicRouteSummary($row) {
    $parts = [];

    $name = $row['name'] ?? '';
    if (!empty($name)) {
        $parts[] = $name;
    }

    // Parse origin/dest filters
    $originFilter = !empty($row['origin_filter']) ? json_decode($row['origin_filter'], true) : null;
    $destFilter = !empty($row['dest_filter']) ? json_decode($row['dest_filter'], true) : null;

    $origin = $row['constrained_area'] ?? (is_array($originFilter) ? implode('/', array_slice($originFilter, 0, 2)) : '');
    $dest = is_array($destFilter) ? implode('/', array_slice($destFilter, 0, 2)) : '';

    if (!empty($origin) && !empty($dest)) {
        $parts[] = "{$origin}→{$dest}";
    } elseif (!empty($origin)) {
        $parts[] = $origin;
    } elseif (!empty($dest)) {
        $parts[] = "to {$dest}";
    }

    $cause = $row['reason'] ?? '';
    if (!empty($cause)) {
        $parts[] = "({$cause})";
    }

    return implode(' ', $parts) ?: 'Public Route';
}

// ===========================================
// Historical Query Functions (Date Filter)
// ===========================================

/**
 * Get NTML entries that were active on a specific date
 */
function getHistoricalNtmlEntries($conn, $dateStart, $dateEnd, $limit, $includeStaging = false, $stagingOnly = false) {
    global $org_code;
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
            WHERE org_code = :org_code
              AND (valid_from <= :dateEnd OR valid_from IS NULL)
              AND (valid_until >= :dateStart OR valid_until IS NULL)
              AND created_at <= :dateEnd2
            ORDER BY valid_from DESC";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':org_code' => $org_code ?? 'vatcscc',
            ':dateStart' => $dateStart,
            ':dateEnd' => $dateEnd,
            ':dateEnd2' => $dateEnd
        ]);

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
 * Get advisories that were active on a specific date
 */
function getHistoricalAdvisories($conn, $dateStart, $dateEnd, $limit, $includeStaging = false, $stagingOnly = false) {
    global $org_code;
    if (!tableExists($conn, 'tmi_advisories')) {
        return [];
    }

    $sql = "SELECT TOP {$limit}
                advisory_id,
                advisory_guid,
                advisory_type,
                advisory_number,
                title,
                summary,
                full_text,
                faa_text,
                constrained_area,
                facilities,
                origin_filter,
                dest_filter,
                altitude_filter,
                affected_traffic,
                valid_from,
                valid_until,
                status,
                discord_message_id,
                created_at,
                created_by
            FROM dbo.tmi_advisories
            WHERE org_code = :org_code
              AND (valid_from <= :dateEnd OR valid_from IS NULL)
              AND (valid_until >= :dateStart OR valid_until IS NULL)
              AND created_at <= :dateEnd2
            ORDER BY valid_from DESC";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':org_code' => $org_code ?? 'vatcscc',
            ':dateStart' => $dateStart,
            ':dateEnd' => $dateEnd,
            ':dateEnd2' => $dateEnd
        ]);

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
 * Get GDT programs that were active on a specific date
 */
function getHistoricalPrograms($conn, $dateStart, $dateEnd, $limit) {
    global $org_code;
    if (!tableExists($conn, 'tmi_programs')) {
        return [];
    }

    $sql = "SELECT TOP {$limit}
                program_id, program_guid, program_type, ctl_element,
                scope_airports, scope_centers, scope_routes,
                issued_utc, start_utc, end_utc, status,
                parameters, remarks, created_at, created_by
            FROM dbo.tmi_programs
            WHERE org_code = :org_code
              AND (start_utc <= :dateEnd OR start_utc IS NULL)
              AND (end_utc >= :dateStart OR end_utc IS NULL)
              AND created_at <= :dateEnd2
            ORDER BY start_utc DESC";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':org_code' => $org_code ?? 'vatcscc',
            ':dateStart' => $dateStart,
            ':dateEnd' => $dateEnd,
            ':dateEnd2' => $dateEnd
        ]);

        $entries = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $entries[] = formatProgram($row);
        }
        return $entries;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Get reroutes that were active on a specific date
 */
function getHistoricalReroutes($conn, $dateStart, $dateEnd, $limit) {
    global $org_code;
    if (!tableExists($conn, 'tmi_reroutes')) {
        return [];
    }

    // Note: TMI database uses created_at/updated_at/activated_at (not _utc suffix)
    // Use aliases to normalize for formatReroute()
    $sql = "SELECT TOP {$limit}
                reroute_id AS id, name, adv_number, status,
                protected_segment, protected_fixes, avoid_fixes,
                route_type, origin_airports, origin_centers,
                dest_airports, dest_centers,
                impacting_condition, comments, advisory_text,
                start_utc, end_utc,
                created_at AS created_utc, updated_at AS updated_utc, activated_at AS activated_utc, created_by
            FROM dbo.tmi_reroutes
            WHERE org_code = :org_code
              AND (start_utc <= :dateEnd OR start_utc IS NULL)
              AND (end_utc >= :dateStart OR end_utc IS NULL)
              AND created_at <= :dateEnd2
            ORDER BY start_utc DESC";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':org_code' => $org_code ?? 'vatcscc',
            ':dateStart' => $dateStart,
            ':dateEnd' => $dateEnd,
            ':dateEnd2' => $dateEnd
        ]);

        $entries = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $entries[] = formatReroute($row);
        }
        return $entries;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Get public routes that were active on a specific date
 */
function getHistoricalPublicRoutes($conn, $dateStart, $dateEnd, $limit) {
    global $org_code;
    if (!tableExists($conn, 'tmi_public_routes')) {
        return [];
    }

    $sql = "SELECT TOP {$limit}
                route_id, route_guid, status, name, adv_number,
                route_string, advisory_text, color, line_weight, line_style,
                valid_start_utc, valid_end_utc,
                constrained_area, reason, origin_filter, dest_filter, facilities,
                created_by, created_at, updated_at
            FROM dbo.tmi_public_routes
            WHERE org_code = :org_code
              AND (valid_start_utc <= :dateEnd OR valid_start_utc IS NULL)
              AND (valid_end_utc >= :dateStart OR valid_end_utc IS NULL)
              AND created_at <= :dateEnd2
            ORDER BY valid_start_utc DESC";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':org_code' => $org_code ?? 'vatcscc',
            ':dateStart' => $dateStart,
            ':dateEnd' => $dateEnd,
            ':dateEnd2' => $dateEnd
        ]);

        $entries = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $entries[] = formatPublicRoute($row);
        }
        return $entries;
    } catch (Exception $e) {
        return [];
    }
}
