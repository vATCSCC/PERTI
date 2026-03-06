<?php
/**
 * CDM Compliance API Endpoint
 *
 * Returns real-time compliance data for flights under TMI control.
 *
 * GET /api/swim/v1/cdm/compliance?program_id=123
 * GET /api/swim/v1/cdm/compliance?flight_uid=456
 * GET /api/swim/v1/cdm/compliance?airport=KJFK&status=AT_RISK
 *
 * Access: Requires valid SWIM API key
 *
 * @package PERTI
 * @subpackage CDM
 * @version 1.0.0
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../../../../load/services/CDMService.php';

SwimResponse::handlePreflight();
$auth = swim_init_auth(true);

$conn_tmi = get_conn_tmi();
$conn_adl = get_conn_adl();
if (!$conn_tmi || !$conn_adl) {
    SwimResponse::error('Database connection not available', 503);
}

$program_id = swim_get_param('program_id');
$flight_uid = swim_get_param('flight_uid');
$airport = swim_get_param('airport');
$status_filter = swim_get_param('status'); // COMPLIANT, NON_COMPLIANT, AT_RISK, PENDING
$limit = swim_get_int_param('limit', 100, 1, 500);

$cdm = new CDMService($conn_tmi, $conn_adl);

if ($program_id) {
    // Program compliance summary + individual flight records
    $program_id = (int)$program_id;
    $summary = $cdm->getProgramCompliance($program_id);

    // Get individual records
    $sql = "SELECT TOP (?) c.compliance_id, c.flight_uid, c.callsign,
                   c.compliance_type, c.compliance_status, c.risk_level,
                   c.expected_value, c.actual_value, c.delta_minutes,
                   c.tolerance_min, c.tolerance_max,
                   c.evaluated_utc, c.is_final
            FROM dbo.cdm_compliance_live c
            WHERE c.program_id = ?";
    $params = [$limit, $program_id];

    if ($status_filter) {
        $sql .= " AND c.compliance_status = ?";
        $params[] = strtoupper($status_filter);
    }

    $sql .= " ORDER BY c.evaluated_utc DESC";

    $stmt = sqlsrv_query($conn_tmi, $sql, $params);
    $records = [];
    if ($stmt !== false) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            // Format DateTime objects
            if ($row['evaluated_utc'] instanceof DateTime) {
                $row['evaluated_utc'] = $row['evaluated_utc']->format('Y-m-d H:i:s');
            }
            $records[] = $row;
        }
        sqlsrv_free_stmt($stmt);
    }

    SwimResponse::success([
        'program_id' => $program_id,
        'summary' => $summary,
        'records' => $records,
        'count' => count($records)
    ]);

} elseif ($flight_uid) {
    // Single flight compliance
    $flight_uid = (int)$flight_uid;
    $sql = "SELECT compliance_type, compliance_status, risk_level,
                   expected_value, actual_value, delta_minutes,
                   tolerance_min, tolerance_max,
                   evaluated_utc, is_final, program_id
            FROM dbo.cdm_compliance_live
            WHERE flight_uid = ?
            ORDER BY evaluated_utc DESC";

    $stmt = sqlsrv_query($conn_tmi, $sql, [$flight_uid]);
    $records = [];
    if ($stmt !== false) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            if ($row['evaluated_utc'] instanceof DateTime) {
                $row['evaluated_utc'] = $row['evaluated_utc']->format('Y-m-d H:i:s');
            }
            $records[] = $row;
        }
        sqlsrv_free_stmt($stmt);
    }

    SwimResponse::success([
        'flight_uid' => $flight_uid,
        'records' => $records,
        'count' => count($records)
    ]);

} elseif ($airport) {
    // Airport at-risk flights
    $sql = "SELECT TOP (?) c.flight_uid, c.callsign, c.program_id,
                   c.compliance_type, c.compliance_status, c.risk_level,
                   c.expected_value, c.delta_minutes, c.evaluated_utc
            FROM dbo.cdm_compliance_live c
            JOIN dbo.tmi_flight_control fc ON c.flight_uid = fc.flight_uid AND c.program_id = fc.program_id
            WHERE fc.dep_airport = ? AND c.is_final = 0";
    $params = [$limit, $airport];

    if ($status_filter) {
        $sql .= " AND c.compliance_status = ?";
        $params[] = strtoupper($status_filter);
    } else {
        $sql .= " AND c.compliance_status IN ('AT_RISK', 'NON_COMPLIANT')";
    }

    $sql .= " ORDER BY c.risk_level DESC, c.delta_minutes DESC";

    $stmt = sqlsrv_query($conn_tmi, $sql, $params);
    $records = [];
    if ($stmt !== false) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            if ($row['evaluated_utc'] instanceof DateTime) {
                $row['evaluated_utc'] = $row['evaluated_utc']->format('Y-m-d H:i:s');
            }
            $records[] = $row;
        }
        sqlsrv_free_stmt($stmt);
    }

    SwimResponse::success([
        'airport' => $airport,
        'records' => $records,
        'count' => count($records)
    ]);

} else {
    // At-risk flights system-wide
    $sql = "SELECT TOP (?) * FROM dbo.vw_cdm_at_risk_flights ORDER BY risk_level DESC, delta_minutes DESC";
    $stmt = sqlsrv_query($conn_tmi, $sql, [$limit]);
    $records = [];
    if ($stmt !== false) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            if (isset($row['evaluated_utc']) && $row['evaluated_utc'] instanceof DateTime) {
                $row['evaluated_utc'] = $row['evaluated_utc']->format('Y-m-d H:i:s');
            }
            $records[] = $row;
        }
        sqlsrv_free_stmt($stmt);
    }

    SwimResponse::success([
        'at_risk_flights' => $records,
        'count' => count($records)
    ]);
}
