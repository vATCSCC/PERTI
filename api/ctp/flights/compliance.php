<?php
/**
 * CTP Flights - Compliance Check API
 *
 * POST /api/ctp/flights/compliance.php
 *
 * Updates compliance status for flights with assigned EDCTs.
 * Compares actual departure time against assigned EDCT.
 *
 * Request body:
 * {
 *   "session_id": 1
 * }
 *
 * GET /api/ctp/flights/compliance.php?session_id=N
 *   Returns compliance summary without updating.
 */

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

define('CTP_API_INCLUDED', true);
require_once(__DIR__ . '/../common.php');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$conn_tmi = ctp_get_conn_tmi();

if ($method === 'GET') {
    // Read-only summary
    $session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
    if ($session_id <= 0) {
        respond_json(400, ['status' => 'error', 'message' => 'session_id is required.']);
    }
    output_compliance_summary($conn_tmi, $session_id);
} elseif ($method === 'POST') {
    // Update compliance, then return summary
    $cid = ctp_require_auth();
    $payload = read_request_payload();
    $session_id = isset($payload['session_id']) ? (int)$payload['session_id'] : 0;
    if ($session_id <= 0) {
        respond_json(400, ['status' => 'error', 'message' => 'session_id is required.']);
    }
    update_compliance($conn_tmi, $session_id);
    output_compliance_summary($conn_tmi, $session_id);
} else {
    respond_json(405, ['status' => 'error', 'message' => 'Method not allowed.']);
}

function update_compliance($conn, $session_id) {
    $conn_adl = ctp_get_conn_adl();

    // Get flights with EDCT assigned that need compliance check
    $sql = "
        SELECT c.ctp_control_id, c.flight_uid, c.edct_utc, c.compliance_status
        FROM dbo.ctp_flight_control c
        WHERE c.session_id = ?
          AND c.edct_status = 'ASSIGNED'
          AND c.is_excluded = 0
          AND c.edct_utc IS NOT NULL
    ";
    $result = ctp_fetch_all($conn, $sql, [$session_id]);
    if (!$result['success']) return;

    $flight_uids = [];
    $flights_by_uid = [];
    foreach ($result['data'] as $f) {
        $uid = (int)$f['flight_uid'];
        $flight_uids[] = $uid;
        $flights_by_uid[$uid] = $f;
    }

    if (empty($flight_uids)) return;

    // Batch fetch actual departure times from ADL
    $chunks = array_chunk($flight_uids, 500);
    $actual_times = [];

    foreach ($chunks as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $adl_sql = "
            SELECT t.flight_uid, t.off_utc, t.out_utc, c.flight_phase
            FROM dbo.adl_flight_times t
            JOIN dbo.adl_flight_core c ON c.flight_uid = t.flight_uid
            WHERE t.flight_uid IN ($placeholders)
        ";
        $adl_result = ctp_fetch_all($conn_adl, $adl_sql, $chunk);
        if ($adl_result['success']) {
            foreach ($adl_result['data'] as $row) {
                $actual_times[(int)$row['flight_uid']] = $row;
            }
        }
    }

    // Update compliance for each flight
    $now_ts = time();
    foreach ($flights_by_uid as $uid => $f) {
        $edct_str = ($f['edct_utc'] instanceof DateTimeInterface) ? $f['edct_utc']->format('Y-m-d H:i:s') : $f['edct_utc'];
        $edct_ts = strtotime($edct_str);

        $actual_dep = null;
        $compliance_status = 'PENDING';
        $delta_min = null;

        if (isset($actual_times[$uid])) {
            $adl = $actual_times[$uid];
            // Prefer off_utc (wheels up), fall back to out_utc (gate push)
            $dep_val = $adl['off_utc'] ?? $adl['out_utc'] ?? null;
            if ($dep_val) {
                $dep_str = ($dep_val instanceof DateTimeInterface) ? $dep_val->format('Y-m-d H:i:s') : $dep_val;
                $dep_ts = strtotime($dep_str);
                if ($dep_ts) {
                    $actual_dep = $dep_str;
                    $delta_min = (int)round(($dep_ts - $edct_ts) / 60);

                    if ($delta_min < -5) {
                        $compliance_status = 'EARLY';
                    } elseif ($delta_min <= 15) {
                        $compliance_status = 'ON_TIME';
                    } else {
                        $compliance_status = 'LATE';
                    }
                }
            }

            // Check for no-show: flight phase departed/completed but no dep time, or EDCT + 30min passed
            if (!$actual_dep && $edct_ts && ($now_ts - $edct_ts) > 1800) {
                $phase = $adl['flight_phase'] ?? '';
                if (in_array($phase, ['COMPLETED', 'CANCELLED'])) {
                    $compliance_status = 'NO_SHOW';
                } elseif ($phase !== 'ACTIVE' && $phase !== 'PREFILED') {
                    // Still on ground but EDCT window passed
                    $compliance_status = 'LATE';
                }
            }
        }

        // Only update if status changed
        if ($compliance_status !== ($f['compliance_status'] ?? 'PENDING')) {
            ctp_execute($conn,
                "UPDATE dbo.ctp_flight_control SET
                    compliance_status = ?,
                    compliance_delta_min = ?,
                    actual_dep_utc = ?,
                    edct_status = CASE WHEN ? IN ('ON_TIME','EARLY') THEN 'COMPLIANT'
                                       WHEN ? IN ('LATE','NO_SHOW') THEN 'NON_COMPLIANT'
                                       ELSE edct_status END
                 WHERE ctp_control_id = ?",
                [
                    $compliance_status,
                    $delta_min,
                    $actual_dep,
                    $compliance_status,
                    $compliance_status,
                    (int)$f['ctp_control_id']
                ]
            );
        }
    }
}

function output_compliance_summary($conn, $session_id) {
    $sql = "
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN edct_status = 'ASSIGNED' THEN 1 ELSE 0 END) AS assigned,
            SUM(CASE WHEN compliance_status = 'ON_TIME' THEN 1 ELSE 0 END) AS on_time,
            SUM(CASE WHEN compliance_status = 'EARLY' THEN 1 ELSE 0 END) AS early,
            SUM(CASE WHEN compliance_status = 'LATE' THEN 1 ELSE 0 END) AS late,
            SUM(CASE WHEN compliance_status = 'NO_SHOW' THEN 1 ELSE 0 END) AS no_show,
            SUM(CASE WHEN compliance_status = 'PENDING' THEN 1 ELSE 0 END) AS pending,
            AVG(CASE WHEN compliance_delta_min IS NOT NULL THEN compliance_delta_min END) AS avg_delta_min,
            AVG(CASE WHEN slot_delay_min IS NOT NULL THEN slot_delay_min END) AS avg_delay_min
        FROM dbo.ctp_flight_control
        WHERE session_id = ? AND edct_status != 'NONE' AND is_excluded = 0
    ";
    $result = ctp_fetch_one($conn, $sql, [$session_id]);
    if (!$result['success'] || !$result['data']) {
        respond_json(200, ['status' => 'ok', 'data' => [
            'total' => 0, 'assigned' => 0, 'on_time' => 0, 'early' => 0,
            'late' => 0, 'no_show' => 0, 'pending' => 0,
            'avg_delta_min' => null, 'avg_delay_min' => null
        ]]);
    }

    $data = $result['data'];
    foreach ($data as $k => $v) {
        if (is_numeric($v) && strpos($k, 'avg_') === 0) {
            $data[$k] = $v !== null ? round((float)$v, 1) : null;
        } else {
            $data[$k] = (int)$v;
        }
    }

    respond_json(200, ['status' => 'ok', 'data' => $data]);
}
