<?php
/**
 * CDM Dashboard Data Endpoint
 *
 * Returns aggregated CDM data for the pilot dashboard:
 *   - Pilot readiness summary (from vw_cdm_current_readiness)
 *   - Compliance status (from cdm_compliance_live)
 *   - At-risk flights (from vw_cdm_at_risk_flights)
 *   - Airport CDM status snapshots (from cdm_airport_status)
 *   - Pending messages (from cdm_messages)
 *
 * Query params:
 *   ?airport=KJFK    Filter by airport ICAO (optional)
 *   ?limit=50        Max rows per section (default 100)
 *
 * Databases: VATSIM_TMI (cdm_* tables), VATSIM_ADL (adl_flight_*)
 */

if (session_status() == PHP_SESSION_NONE) {
    session_start();
    ob_start();
}

include(__DIR__ . "/../../../load/config.php");
include(__DIR__ . "/../../../load/connect.php");

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

$airport = isset($_GET['airport']) ? strtoupper(trim($_GET['airport'])) : null;
$limit   = isset($_GET['limit']) ? min(max((int)$_GET['limit'], 1), 500) : 100;

// Validate airport format if provided
if ($airport && !preg_match('/^[A-Z]{3,4}$/', $airport)) {
    echo json_encode(['success' => false, 'error' => 'Invalid airport code']);
    exit();
}

$conn_tmi = get_conn_tmi();
$conn_adl = get_conn_adl();

if (!$conn_tmi) {
    echo json_encode(['success' => false, 'error' => 'TMI database not available']);
    exit();
}

$result = [
    'success' => true,
    'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
    'data' => [
        'readiness'      => [],
        'compliance'     => [],
        'at_risk'        => [],
        'airport_status' => [],
        'messages'       => [],
        'summary'        => [],
    ]
];

// =========================================================================
// 1. Pilot Readiness (from vw_cdm_current_readiness in VATSIM_TMI)
// =========================================================================
$readiness_sql = "
    SELECT TOP (?)
        r.flight_uid,
        r.callsign,
        r.cid,
        r.readiness_state,
        r.reported_tobt,
        r.computed_tobt,
        r.source,
        r.dep_airport,
        r.arr_airport,
        r.created_utc
    FROM dbo.vw_cdm_current_readiness r
";
$readiness_params = [$limit];

if ($airport) {
    $readiness_sql .= " WHERE r.dep_airport = ?";
    $readiness_params[] = $airport;
}

$readiness_sql .= " ORDER BY r.created_utc DESC";

$stmt = sqlsrv_query($conn_tmi, $readiness_sql, $readiness_params);
if ($stmt) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $result['data']['readiness'][] = [
            'flight_uid'      => $row['flight_uid'],
            'callsign'        => $row['callsign'],
            'cid'             => $row['cid'],
            'readiness_state' => $row['readiness_state'],
            'reported_tobt'   => $row['reported_tobt'] ? $row['reported_tobt']->format('Y-m-d\TH:i:s\Z') : null,
            'computed_tobt'   => $row['computed_tobt'] ? $row['computed_tobt']->format('Y-m-d\TH:i:s\Z') : null,
            'source'          => $row['source'],
            'dep_airport'     => $row['dep_airport'],
            'arr_airport'     => $row['arr_airport'],
            'updated_at'      => $row['created_utc'] ? $row['created_utc']->format('Y-m-d\TH:i:s\Z') : null,
        ];
    }
    sqlsrv_free_stmt($stmt);
}

// =========================================================================
// 2. Compliance Status (from cdm_compliance_live in VATSIM_TMI)
// =========================================================================
$compliance_sql = "
    SELECT TOP (?)
        c.compliance_id,
        c.flight_uid,
        c.callsign,
        c.program_id,
        c.compliance_type,
        c.compliance_status,
        c.risk_level,
        c.expected_value,
        c.actual_value,
        c.delta_minutes,
        c.tolerance_min,
        c.tolerance_max,
        c.is_final,
        c.evaluated_utc
    FROM dbo.cdm_compliance_live c
    WHERE c.is_final = 0
";
$compliance_params = [$limit];

if ($airport) {
    // Join to get airport filter — compliance doesn't have airport directly
    $compliance_sql = "
        SELECT TOP (?)
            c.compliance_id,
            c.flight_uid,
            c.callsign,
            c.program_id,
            c.compliance_type,
            c.compliance_status,
            c.risk_level,
            c.expected_value,
            c.actual_value,
            c.delta_minutes,
            c.tolerance_min,
            c.tolerance_max,
            c.is_final,
            c.evaluated_utc
        FROM dbo.cdm_compliance_live c
        INNER JOIN dbo.tmi_programs p ON c.program_id = p.program_id
        WHERE c.is_final = 0 AND p.ctl_element = ?
    ";
    $compliance_params = [$limit, $airport];
}

$compliance_sql .= " ORDER BY c.evaluated_utc DESC";

$stmt = sqlsrv_query($conn_tmi, $compliance_sql, $compliance_params);
if ($stmt) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $result['data']['compliance'][] = [
            'compliance_id'    => $row['compliance_id'],
            'flight_uid'       => $row['flight_uid'],
            'callsign'         => $row['callsign'],
            'program_id'       => $row['program_id'],
            'compliance_type'  => $row['compliance_type'],
            'compliance_status'=> $row['compliance_status'],
            'risk_level'       => $row['risk_level'],
            'expected_value'   => $row['expected_value'],
            'actual_value'     => $row['actual_value'],
            'delta_minutes'    => $row['delta_minutes'] !== null ? round((float)$row['delta_minutes'], 1) : null,
            'tolerance_min'    => (float)$row['tolerance_min'],
            'tolerance_max'    => (float)$row['tolerance_max'],
            'is_final'         => (bool)$row['is_final'],
            'evaluated_at'     => $row['evaluated_utc'] ? $row['evaluated_utc']->format('Y-m-d\TH:i:s\Z') : null,
        ];
    }
    sqlsrv_free_stmt($stmt);
}

// =========================================================================
// 3. At-Risk Flights (from vw_cdm_at_risk_flights in VATSIM_TMI)
// =========================================================================
$atrisk_sql = "SELECT TOP (?) * FROM dbo.vw_cdm_at_risk_flights";
$atrisk_params = [$limit];

if ($airport) {
    $atrisk_sql = "
        SELECT TOP (?) ar.*
        FROM dbo.vw_cdm_at_risk_flights ar
        INNER JOIN dbo.tmi_programs p ON ar.program_id = p.program_id
        WHERE p.ctl_element = ?
    ";
    $atrisk_params = [$limit, $airport];
}

$stmt = sqlsrv_query($conn_tmi, $atrisk_sql, $atrisk_params);
if ($stmt) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $result['data']['at_risk'][] = [
            'flight_uid'        => $row['flight_uid'],
            'callsign'          => $row['callsign'],
            'compliance_type'   => $row['compliance_type'],
            'compliance_status' => $row['compliance_status'],
            'risk_level'        => $row['risk_level'],
            'delta_minutes'     => $row['delta_minutes'] !== null ? round((float)$row['delta_minutes'], 1) : null,
            'evaluated_at'      => isset($row['evaluated_utc']) && $row['evaluated_utc'] ? $row['evaluated_utc']->format('Y-m-d\TH:i:s\Z') : null,
        ];
    }
    sqlsrv_free_stmt($stmt);
}

// =========================================================================
// 4. Airport CDM Status (latest snapshot per airport from cdm_airport_status)
// =========================================================================
$airport_sql = "
    SELECT s.*
    FROM dbo.cdm_airport_status s
    INNER JOIN (
        SELECT airport_icao, MAX(snapshot_utc) AS max_snapshot
        FROM dbo.cdm_airport_status
        GROUP BY airport_icao
    ) latest ON s.airport_icao = latest.airport_icao AND s.snapshot_utc = latest.max_snapshot
";
$airport_params = [];

if ($airport) {
    $airport_sql .= " WHERE s.airport_icao = ?";
    $airport_params[] = $airport;
}

$airport_sql .= " ORDER BY s.airport_icao";

$stmt = sqlsrv_query($conn_tmi, $airport_sql, $airport_params);
if ($stmt) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $result['data']['airport_status'][] = [
            'airport_icao'       => $row['airport_icao'],
            'snapshot_utc'       => $row['snapshot_utc'] ? $row['snapshot_utc']->format('Y-m-d\TH:i:s\Z') : null,
            'ready_count'        => (int)$row['ready_count'],
            'gate_held_count'    => (int)$row['gate_held_count'],
            'taxiing_count'      => (int)$row['taxiing_count'],
            'boarding_count'     => (int)$row['boarding_count'],
            'planning_count'     => (int)$row['planning_count'],
            'avg_taxi_time_sec'  => $row['avg_taxi_time_sec'] !== null ? (int)$row['avg_taxi_time_sec'] : null,
            'baseline_taxi_sec'  => $row['baseline_taxi_sec'] !== null ? (int)$row['baseline_taxi_sec'] : null,
            'avg_gate_hold_min'  => $row['avg_gate_hold_min'] !== null ? round((float)$row['avg_gate_hold_min'], 1) : null,
            'weather_category'   => $row['weather_category'],
            'aar'                => $row['aar'] !== null ? (int)$row['aar'] : null,
            'adr'                => $row['adr'] !== null ? (int)$row['adr'] : null,
            'is_controlled'      => (bool)$row['is_controlled'],
        ];
    }
    sqlsrv_free_stmt($stmt);
}

// =========================================================================
// 5. Pending Messages (from cdm_messages in VATSIM_TMI)
// =========================================================================
$msg_sql = "
    SELECT TOP (?)
        m.message_id,
        m.flight_uid,
        m.callsign,
        m.cid,
        m.message_type,
        m.delivery_channel,
        m.delivery_status,
        m.ack_type,
        m.created_utc,
        m.expires_utc
    FROM dbo.cdm_messages m
    WHERE m.delivery_status IN ('PENDING', 'SENT')
";
$msg_params = [$limit];

$msg_sql .= " ORDER BY m.created_utc DESC";

$stmt = sqlsrv_query($conn_tmi, $msg_sql, $msg_params);
if ($stmt) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $result['data']['messages'][] = [
            'message_id'      => $row['message_id'],
            'flight_uid'      => $row['flight_uid'],
            'callsign'        => $row['callsign'],
            'cid'             => $row['cid'],
            'message_type'    => $row['message_type'],
            'delivery_channel'=> $row['delivery_channel'],
            'delivery_status' => $row['delivery_status'],
            'ack_type'        => $row['ack_type'],
            'created_at'      => $row['created_utc'] ? $row['created_utc']->format('Y-m-d\TH:i:s\Z') : null,
            'expires_at'      => $row['expires_utc'] ? $row['expires_utc']->format('Y-m-d\TH:i:s\Z') : null,
        ];
    }
    sqlsrv_free_stmt($stmt);
}

// =========================================================================
// 6. Summary Counts
// =========================================================================
$summary = [
    'total_readiness'     => 0,
    'ready_count'         => 0,
    'boarding_count'      => 0,
    'planning_count'      => 0,
    'taxiing_count'       => 0,
    'cancelled_count'     => 0,
    'total_compliance'    => 0,
    'compliant_count'     => 0,
    'non_compliant_count' => 0,
    'at_risk_count'       => 0,
    'pending_count'       => 0,
    'exempt_count'        => 0,
    'pending_messages'    => 0,
    'airports_controlled' => 0,
];

// Readiness summary
$stmt = sqlsrv_query($conn_tmi, "
    SELECT readiness_state, COUNT(*) AS cnt
    FROM dbo.vw_cdm_current_readiness
    GROUP BY readiness_state
");
if ($stmt) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $state = strtolower($row['readiness_state']);
        $cnt = (int)$row['cnt'];
        $summary['total_readiness'] += $cnt;
        $key = $state . '_count';
        if (isset($summary[$key])) {
            $summary[$key] = $cnt;
        }
    }
    sqlsrv_free_stmt($stmt);
}

// Compliance summary
$stmt = sqlsrv_query($conn_tmi, "
    SELECT compliance_status, COUNT(*) AS cnt
    FROM dbo.cdm_compliance_live
    WHERE is_final = 0
    GROUP BY compliance_status
");
if ($stmt) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $status = strtolower($row['compliance_status']);
        $cnt = (int)$row['cnt'];
        $summary['total_compliance'] += $cnt;
        $statusMap = [
            'compliant'      => 'compliant_count',
            'non_compliant'  => 'non_compliant_count',
            'at_risk'        => 'at_risk_count',
            'pending'        => 'pending_count',
            'exempt'         => 'exempt_count',
        ];
        if (isset($statusMap[$status])) {
            $summary[$statusMap[$status]] = $cnt;
        }
    }
    sqlsrv_free_stmt($stmt);
}

// Pending message count
$stmt = sqlsrv_query($conn_tmi, "
    SELECT COUNT(*) AS cnt FROM dbo.cdm_messages
    WHERE delivery_status IN ('PENDING', 'SENT')
");
if ($stmt) {
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    $summary['pending_messages'] = (int)$row['cnt'];
    sqlsrv_free_stmt($stmt);
}

// Controlled airport count
$stmt = sqlsrv_query($conn_tmi, "
    SELECT COUNT(DISTINCT s.airport_icao) AS cnt
    FROM dbo.cdm_airport_status s
    INNER JOIN (
        SELECT airport_icao, MAX(snapshot_utc) AS max_snapshot
        FROM dbo.cdm_airport_status
        GROUP BY airport_icao
    ) latest ON s.airport_icao = latest.airport_icao AND s.snapshot_utc = latest.max_snapshot
    WHERE s.is_controlled = 1
");
if ($stmt) {
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    $summary['airports_controlled'] = (int)$row['cnt'];
    sqlsrv_free_stmt($stmt);
}

$result['data']['summary'] = $summary;

echo json_encode($result, JSON_UNESCAPED_UNICODE);
