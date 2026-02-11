<?php
/**
 * TMR TMI Lookup API - Historical TMIs for an event window
 *
 * GET ?p_id=N — Query tmi_programs, tmi_entries, tmi_advisories, tmi_reroutes
 *               from VATSIM_TMI for the plan's event window
 *
 * Returns combined TMI list with type, element, timing, status.
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

require_once __DIR__ . '/../../../load/config.php';
require_once __DIR__ . '/../../../load/connect.php';

$p_id = get_int('p_id');
if (!$p_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing p_id']);
    exit;
}

// Get plan date/time window from perti_site
$stmt = $conn_pdo->prepare("SELECT event_name, event_date, event_start, event_end_date, event_end_time FROM p_plans WHERE id = ?");
$stmt->execute([$p_id]);
$plan = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$plan) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Plan not found']);
    exit;
}

// Build UTC datetime range from plan fields
$dateStart = $plan['event_date'] . ' ' . ($plan['event_start'] ?: '00:00');
$dateEnd = ($plan['event_end_date'] ?: $plan['event_date']) . ' ' . ($plan['event_end_time'] ?: '23:59');

// Pad by 1 hour on each side to catch TMIs that overlap the event window
$dateStartPadded = date('Y-m-d H:i', strtotime($dateStart . ' UTC') - 3600);
$dateEndPadded = date('Y-m-d H:i', strtotime($dateEnd . ' UTC') + 3600);

// Connect to VATSIM_TMI via sqlsrv
$tmiConn = get_conn_tmi();

if (!$tmiConn) {
    echo json_encode([
        'success' => true,
        'tmis' => [],
        'event_window' => ['start' => $dateStart, 'end' => $dateEnd],
        'warning' => 'TMI database not configured'
    ]);
    exit;
}

$limit = 200;
$tmis = [];

// Fetch NTML entries
$tmis = array_merge($tmis, fetchNtmlEntries($tmiConn, $dateStartPadded, $dateEndPadded, $limit));

// Fetch programs (GDP, GS)
$tmis = array_merge($tmis, fetchPrograms($tmiConn, $dateStartPadded, $dateEndPadded, $limit));

// Fetch advisories
$tmis = array_merge($tmis, fetchAdvisories($tmiConn, $dateStartPadded, $dateEndPadded, $limit));

// Fetch reroutes
$tmis = array_merge($tmis, fetchReroutes($tmiConn, $dateStartPadded, $dateEndPadded, $limit));

// Sort by start time
usort($tmis, function($a, $b) {
    return strcmp($a['start_utc'] ?? '', $b['start_utc'] ?? '');
});

echo json_encode([
    'success' => true,
    'tmis' => $tmis,
    'event_window' => ['start' => $dateStart, 'end' => $dateEnd],
    'count' => count($tmis)
]);

// ---- Query functions (sqlsrv) ----

function fetchNtmlEntries($conn, $dateStart, $dateEnd, $limit) {
    $sql = "SELECT TOP $limit
                entry_id, entry_type, determinant_code, ctl_element,
                requesting_facility, providing_facility,
                restriction_value, restriction_unit,
                reason_code, valid_from, valid_until, status,
                source_type
            FROM dbo.tmi_entries
            WHERE valid_from IS NOT NULL AND valid_until IS NOT NULL
              AND valid_from <= ? AND valid_until >= ?
            ORDER BY valid_from DESC";

    $stmt = sqlsrv_query($conn, $sql, [$dateEnd, $dateStart]);
    if ($stmt === false) return [];

    $results = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $results[] = [
            'id' => $row['entry_id'],
            'category' => 'ntml',
            'type' => $row['entry_type'] ?: $row['determinant_code'],
            'element' => $row['ctl_element'],
            'facility' => $row['requesting_facility'] ?: $row['providing_facility'],
            'detail' => buildNtmlDetail($row),
            'start_utc' => formatDt($row['valid_from']),
            'end_utc' => formatDt($row['valid_until']),
            'status' => $row['status'],
        ];
    }
    sqlsrv_free_stmt($stmt);
    return $results;
}

function fetchPrograms($conn, $dateStart, $dateEnd, $limit) {
    $sql = "SELECT TOP $limit
                program_id, program_type, program_name, ctl_element,
                start_utc, end_utc, status,
                program_rate, cause_text, impacting_condition,
                scope_json, created_at, created_by
            FROM dbo.tmi_programs
            WHERE COALESCE(start_utc, created_at) <= ?
              AND COALESCE(end_utc, '2100-01-01') >= ?
              AND created_at BETWEEN ? AND ?
            ORDER BY start_utc DESC";

    $stmt = sqlsrv_query($conn, $sql, [$dateEnd, $dateStart, $dateStart, $dateEnd]);
    if ($stmt === false) return [];

    $results = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $results[] = [
            'id' => $row['program_id'],
            'category' => 'program',
            'type' => $row['program_type'],
            'element' => $row['ctl_element'],
            'facility' => $row['program_name'],
            'detail' => $row['cause_text'] ?: $row['program_rate'],
            'start_utc' => formatDt($row['start_utc']),
            'end_utc' => formatDt($row['end_utc']),
            'status' => $row['status'],
            'program_rate' => $row['program_rate'],
            'impacting_condition' => $row['impacting_condition'],
            'created_by' => $row['created_by'],
        ];
    }
    sqlsrv_free_stmt($stmt);
    return $results;
}

function fetchAdvisories($conn, $dateStart, $dateEnd, $limit) {
    $sql = "SELECT TOP $limit
                advisory_id, advisory_type, advisory_number,
                subject, body_text, ctl_element, scope_facilities,
                effective_from, effective_until, status,
                created_at, created_by
            FROM dbo.tmi_advisories
            WHERE COALESCE(effective_from, created_at) <= ?
              AND COALESCE(effective_until, '2100-01-01') >= ?
              AND created_at BETWEEN ? AND ?
            ORDER BY effective_from DESC";

    $stmt = sqlsrv_query($conn, $sql, [$dateEnd, $dateStart, $dateStart, $dateEnd]);
    if ($stmt === false) return [];

    $results = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $results[] = [
            'id' => $row['advisory_id'],
            'category' => 'advisory',
            'type' => $row['advisory_type'],
            'element' => $row['ctl_element'] ?: $row['scope_facilities'],
            'facility' => $row['scope_facilities'],
            'detail' => $row['subject'] ?: $row['body_text'],
            'start_utc' => formatDt($row['effective_from']),
            'end_utc' => formatDt($row['effective_until']),
            'status' => $row['status'],
            'advisory_number' => $row['advisory_number'],
            'created_by' => $row['created_by'],
        ];
    }
    sqlsrv_free_stmt($stmt);
    return $results;
}

function fetchReroutes($conn, $dateStart, $dateEnd, $limit) {
    $sql = "SELECT TOP $limit
                reroute_id, name, adv_number, status,
                protected_segment, protected_fixes, avoid_fixes,
                origin_airports, origin_centers, dest_airports, dest_centers,
                comments, start_utc, end_utc,
                created_at
            FROM dbo.tmi_reroutes
            WHERE COALESCE(start_utc, created_at) <= ?
              AND COALESCE(end_utc, '2100-01-01') >= ?
              AND created_at BETWEEN ? AND ?
            ORDER BY start_utc DESC";

    $stmt = sqlsrv_query($conn, $sql, [$dateEnd, $dateStart, $dateStart, $dateEnd]);
    if ($stmt === false) return [];

    $results = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $results[] = [
            'id' => $row['reroute_id'],
            'category' => 'reroute',
            'type' => 'Reroute',
            'element' => $row['name'],
            'facility' => trim(($row['origin_airports'] ?? '') . ' ' . ($row['origin_centers'] ?? '')),
            'detail' => $row['comments'] ?: $row['protected_segment'],
            'start_utc' => formatDt($row['start_utc']),
            'end_utc' => formatDt($row['end_utc']),
            'status' => $row['status'],
            'adv_number' => $row['adv_number'],
        ];
    }
    sqlsrv_free_stmt($stmt);
    return $results;
}

function buildNtmlDetail($row) {
    $parts = [];
    if ($row['restriction_value']) {
        $parts[] = $row['restriction_value'] . ($row['restriction_unit'] ? ' ' . $row['restriction_unit'] : '');
    }
    if ($row['reason_code']) {
        $parts[] = 'Reason: ' . $row['reason_code'];
    }
    return implode(' | ', $parts) ?: null;
}

function formatDt($val) {
    if ($val === null) return null;
    if ($val instanceof DateTime) return $val->format('Y-m-d H:i');
    return (string)$val;
}
