<?php
/**
 * TMI Staged Entries API
 * 
 * Lists TMI entries and advisories currently in staging status,
 * waiting for promotion to production.
 * 
 * GET /api/mgt/tmi/staged.php
 * Optional query params:
 *   - type: 'ntml' | 'advisory' | 'all' (default: 'all')
 *   - org: organization code filter
 *   - limit: max results (default: 50)
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
$orgFilter = $_GET['org'] ?? null;
$limit = min(intval($_GET['limit'] ?? 50), 100);

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
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

if (!$tmiConn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database not configured']);
    exit;
}

$results = [];

try {
    // Get staged NTML entries
    if ($type === 'all' || $type === 'ntml') {
        $ntmlEntries = getStagedNtmlEntries($tmiConn, $orgFilter, $limit);
        $results = array_merge($results, $ntmlEntries);
    }
    
    // Get staged advisories
    if ($type === 'all' || $type === 'advisory') {
        $advisories = getStagedAdvisories($tmiConn, $orgFilter, $limit);
        $results = array_merge($results, $advisories);
    }
    
    // Sort by created_at descending
    usort($results, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
    
    // Apply limit
    $results = array_slice($results, 0, $limit);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Query failed: ' . $e->getMessage()]);
    exit;
}

echo json_encode([
    'success' => true,
    'count' => count($results),
    'entries' => $results
]);

// ===========================================
// Helper Functions
// ===========================================

/**
 * Get staged NTML entries
 */
function getStagedNtmlEntries($conn, $orgFilter, $limit) {
    $sql = "SELECT 
                e.entry_id,
                'ENTRY' as entity_type,
                e.entry_type,
                e.ctl_element,
                e.requesting_facility,
                e.providing_facility,
                e.restriction_value,
                e.reason_code,
                e.raw_text,
                e.status,
                e.created_at,
                e.created_by,
                (
                    SELECT STRING_AGG(p.org_code, ',') 
                    FROM dbo.tmi_discord_posts p 
                    WHERE p.entity_type = 'ENTRY' 
                      AND p.entity_id = e.entry_id 
                      AND p.channel_purpose LIKE '%staging%'
                      AND p.status = 'POSTED'
                ) as staged_orgs,
                (
                    SELECT COUNT(*) 
                    FROM dbo.tmi_discord_posts p 
                    WHERE p.entity_type = 'ENTRY' 
                      AND p.entity_id = e.entry_id 
                      AND p.channel_purpose LIKE '%staging%'
                      AND p.status = 'POSTED'
                ) as staging_post_count
            FROM dbo.tmi_entries e
            WHERE e.status = 'STAGED'
            ORDER BY e.created_at DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    
    $entries = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Filter by org if specified
        if ($orgFilter) {
            $stagedOrgs = explode(',', $row['staged_orgs'] ?? '');
            if (!in_array($orgFilter, $stagedOrgs)) {
                continue;
            }
        }
        
        $entries[] = [
            'entityType' => 'ENTRY',
            'entityId' => intval($row['entry_id']),
            'type' => 'ntml',
            'entryType' => $row['entry_type'],
            'summary' => buildNtmlSummary($row),
            'content' => $row['raw_text'],
            'status' => $row['status'],
            'created_at' => $row['created_at'],
            'created_by' => $row['created_by'],
            'stagedOrgs' => array_filter(explode(',', $row['staged_orgs'] ?? '')),
            'stagingPostCount' => intval($row['staging_post_count'])
        ];
    }
    
    return $entries;
}

/**
 * Get staged advisories
 */
function getStagedAdvisories($conn, $orgFilter, $limit) {
    $sql = "SELECT 
                a.advisory_id,
                'ADVISORY' as entity_type,
                a.advisory_type,
                a.advisory_number,
                a.facility_code,
                a.ctl_element,
                a.content_text,
                a.status,
                a.created_at,
                a.created_by,
                (
                    SELECT STRING_AGG(p.org_code, ',') 
                    FROM dbo.tmi_discord_posts p 
                    WHERE p.entity_type = 'ADVISORY' 
                      AND p.entity_id = a.advisory_id 
                      AND p.channel_purpose LIKE '%staging%'
                      AND p.status = 'POSTED'
                ) as staged_orgs,
                (
                    SELECT COUNT(*) 
                    FROM dbo.tmi_discord_posts p 
                    WHERE p.entity_type = 'ADVISORY' 
                      AND p.entity_id = a.advisory_id 
                      AND p.channel_purpose LIKE '%staging%'
                      AND p.status = 'POSTED'
                ) as staging_post_count
            FROM dbo.tmi_advisories a
            WHERE a.status = 'STAGED'
            ORDER BY a.created_at DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    
    $entries = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Filter by org if specified
        if ($orgFilter) {
            $stagedOrgs = explode(',', $row['staged_orgs'] ?? '');
            if (!in_array($orgFilter, $stagedOrgs)) {
                continue;
            }
        }
        
        $entries[] = [
            'entityType' => 'ADVISORY',
            'entityId' => intval($row['advisory_id']),
            'type' => 'advisory',
            'entryType' => $row['advisory_type'],
            'summary' => buildAdvisorySummary($row),
            'content' => $row['content_text'],
            'status' => $row['status'],
            'created_at' => $row['created_at'],
            'created_by' => $row['created_by'],
            'stagedOrgs' => array_filter(explode(',', $row['staged_orgs'] ?? '')),
            'stagingPostCount' => intval($row['staging_post_count'])
        ];
    }
    
    return $entries;
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
