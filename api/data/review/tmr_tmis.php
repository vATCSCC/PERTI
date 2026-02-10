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

// Connect to VATSIM_TMI
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
    echo json_encode(['success' => false, 'error' => 'TMI database connection failed']);
    exit;
}

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

// ---- Query functions ----

function fetchNtmlEntries($conn, $dateStart, $dateEnd, $limit) {
    $sql = "SELECT TOP $limit
                entry_id, entry_type, determinant_code, ctl_element,
                requesting_facility, providing_facility,
                restriction_value, restriction_unit,
                reason_code, valid_from, valid_until, status,
                raw_input, created_at, created_by_name
            FROM dbo.tmi_entries
            WHERE (valid_from <= :dateEnd OR valid_from IS NULL)
              AND (valid_until >= :dateStart OR valid_until IS NULL)
              AND created_at <= :dateEnd2
            ORDER BY valid_from DESC";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':dateStart' => $dateStart,
            ':dateEnd' => $dateEnd,
            ':dateEnd2' => $dateEnd
        ]);

        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
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
                'raw' => $row['raw_input'],
                'created_by' => $row['created_by_name'],
            ];
        }
        return $results;
    } catch (Exception $e) {
        return [];
    }
}

function fetchPrograms($conn, $dateStart, $dateEnd, $limit) {
    $sql = "SELECT TOP $limit
                program_id, program_type, ctl_element,
                scope_airports, start_utc, end_utc, status,
                parameters, remarks, created_at, created_by
            FROM dbo.tmi_programs
            WHERE (start_utc <= :dateEnd OR start_utc IS NULL)
              AND (end_utc >= :dateStart OR end_utc IS NULL)
              AND created_at <= :dateEnd2
            ORDER BY start_utc DESC";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':dateStart' => $dateStart,
            ':dateEnd' => $dateEnd,
            ':dateEnd2' => $dateEnd
        ]);

        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = [
                'id' => $row['program_id'],
                'category' => 'program',
                'type' => $row['program_type'],
                'element' => $row['ctl_element'],
                'facility' => $row['scope_airports'],
                'detail' => $row['remarks'] ?: $row['parameters'],
                'start_utc' => formatDt($row['start_utc']),
                'end_utc' => formatDt($row['end_utc']),
                'status' => $row['status'],
                'created_by' => $row['created_by'],
            ];
        }
        return $results;
    } catch (Exception $e) {
        return [];
    }
}

function fetchAdvisories($conn, $dateStart, $dateEnd, $limit) {
    $sql = "SELECT TOP $limit
                advisory_id, advisory_type, advisory_number,
                title, summary, constrained_area, facilities,
                valid_from, valid_until, status,
                created_at, created_by
            FROM dbo.tmi_advisories
            WHERE (valid_from <= :dateEnd OR valid_from IS NULL)
              AND (valid_until >= :dateStart OR valid_until IS NULL)
              AND created_at <= :dateEnd2
            ORDER BY valid_from DESC";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':dateStart' => $dateStart,
            ':dateEnd' => $dateEnd,
            ':dateEnd2' => $dateEnd
        ]);

        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = [
                'id' => $row['advisory_id'],
                'category' => 'advisory',
                'type' => $row['advisory_type'],
                'element' => $row['constrained_area'] ?: $row['facilities'],
                'facility' => $row['facilities'],
                'detail' => $row['title'] ?: $row['summary'],
                'start_utc' => formatDt($row['valid_from']),
                'end_utc' => formatDt($row['valid_until']),
                'status' => $row['status'],
                'advisory_number' => $row['advisory_number'],
                'created_by' => $row['created_by'],
            ];
        }
        return $results;
    } catch (Exception $e) {
        return [];
    }
}

function fetchReroutes($conn, $dateStart, $dateEnd, $limit) {
    $sql = "SELECT TOP $limit
                reroute_id, name, adv_number, status,
                protected_segment, protected_fixes, avoid_fixes,
                origin_airports, origin_centers, dest_airports, dest_centers,
                comments, start_utc, end_utc,
                created_at, created_by
            FROM dbo.tmi_reroutes
            WHERE (start_utc <= :dateEnd OR start_utc IS NULL)
              AND (end_utc >= :dateStart OR end_utc IS NULL)
              AND created_at <= :dateEnd2
            ORDER BY start_utc DESC";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':dateStart' => $dateStart,
            ':dateEnd' => $dateEnd,
            ':dateEnd2' => $dateEnd
        ]);

        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
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
                'created_by' => $row['created_by'],
            ];
        }
        return $results;
    } catch (Exception $e) {
        return [];
    }
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
