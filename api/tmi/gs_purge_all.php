<?php
header('Content-Type: application/json; charset=utf-8');

// Allow preflight
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function respond_json($code, $payload) {
    http_response_code($code);
    echo json_encode($payload, JSON_PRETTY_PRINT);
    exit;
}

function read_request_payload() {
    $raw = file_get_contents('php://input');
    if ($raw !== false && strlen(trim($raw)) > 0) {
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }
    }
    return array_merge($_GET ?? [], $_POST ?? []);
}

function get_adl_conn() {
    require_once(__DIR__ . '/../../load/connect.php');
    if (isset($conn_adl) && $conn_adl) return $conn_adl;

    respond_json(500, [
        'status'  => 'error',
        'message' => 'ADL SQL connection not established (conn_adl is null). Check load/connect.php and ADL_SQL_* constants.',
        'errors'  => function_exists('sqlsrv_errors') ? sqlsrv_errors() : null
    ]);
}

function sqlsrv_fetch_value($conn, $sql, $params = []) {
    $stmt = (count($params) > 0) ? sqlsrv_query($conn, $sql, $params) : sqlsrv_query($conn, $sql);
    if ($stmt === false) return [null, sqlsrv_errors()];
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_NUMERIC);
    sqlsrv_free_stmt($stmt);
    if (!$row) return [null, null];
    return [$row[0], null];
}

// api/tmi/gs_purge_all.php
// "Purge All EDCTs": remove all local control fields from dbo.adl_flights
// and clear simulated GS rows from dbo.adl_flights_gs.

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond_json(405, [
        'status'  => 'error',
        'message' => 'Method not allowed. Use POST.'
    ]);
}

$payload = read_request_payload();
$conn = get_adl_conn();

if (!sqlsrv_begin_transaction($conn)) {
    respond_json(500, ['status'=>'error','message'=>'Failed to begin transaction','errors'=>sqlsrv_errors()]);
}

try {
    // Reset controlled fields back to an uncontrolled state.
    // We keep baseline ETA in beta_utc and restore eta_runway_utc from beta_utc when available.
    $purge_sql = "
        UPDATE dbo.adl_flights
        SET
            ctl_type = NULL,
            ctl_element = NULL,
            ctd_utc = NULL,
            octd_utc = NULL,
            cta_utc = NULL,
            octa_utc = NULL,
            delay_status = 'NORMAL',
            eta_runway_utc = CASE WHEN beta_utc IS NOT NULL THEN beta_utc ELSE eta_runway_utc END,
            eta_prefix = CASE
                            WHEN (eta_prefix = 'C' OR delay_status = 'GSD' OR ctl_type IS NOT NULL OR ctd_utc IS NOT NULL OR cta_utc IS NOT NULL)
                            THEN 'B'
                            ELSE eta_prefix
                         END,
            absolute_delay_min = NULL,
            schedule_variation_min = NULL,
            program_delay_min = NULL
        WHERE
            ctl_type IS NOT NULL
            OR ctl_element IS NOT NULL
            OR ctd_utc IS NOT NULL
            OR cta_utc IS NOT NULL
            OR octd_utc IS NOT NULL
            OR octa_utc IS NOT NULL
            OR delay_status = 'GSD'
            OR eta_prefix = 'C'
    ";

    $purge_stmt = sqlsrv_query($conn, $purge_sql);
    if ($purge_stmt === false) {
        throw new Exception('Purge UPDATE failed: ' . json_encode(sqlsrv_errors()));
    }
    $purged_rows = sqlsrv_rows_affected($purge_stmt);
    sqlsrv_free_stmt($purge_stmt);

    // Clear ALL swap table rows (not just GS - ensures clean state)
    $del_stmt = sqlsrv_query($conn, "DELETE FROM dbo.adl_flights_gs");
    if ($del_stmt === false) {
        throw new Exception('DELETE failed: ' . json_encode(sqlsrv_errors()));
    }
    $cleared_rows = sqlsrv_rows_affected($del_stmt);
    sqlsrv_free_stmt($del_stmt);

    if (!sqlsrv_commit($conn)) {
        throw new Exception('Commit failed: ' . json_encode(sqlsrv_errors()));
    }

} catch (Exception $e) {
    sqlsrv_rollback($conn);
    respond_json(500, ['status'=>'error','message'=>$e->getMessage()]);
}

list($utc_now, $utc_err) = sqlsrv_fetch_value($conn, 'SELECT SYSUTCDATETIME()');
if ($utc_err) $utc_now = null;

respond_json(200, [
    'status'  => 'ok',
    'message' => 'All local EDCT/control fields purged from live ADL; simulated GS rows cleared.',
    'data'    => [
        'server_utc'        => $utc_now,
        'purged_flights'    => ($purged_rows === false ? null : $purged_rows),
        'cleared_gs_rows'   => ($cleared_rows === false ? null : $cleared_rows),
        'received'          => $payload
    ]
]);

?>
