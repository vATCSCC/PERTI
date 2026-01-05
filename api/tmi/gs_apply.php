<?php
header('Content-Type: application/json; charset=utf-8');

// Allow preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
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
    require_once(__DIR__ . '/../../load/connect.php'); // expected to provide $conn_adl
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

function get_table_columns_lower($conn, $table_name) {
    $cols = [];
    $stmt = sqlsrv_query($conn,
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ? ORDER BY ORDINAL_POSITION",
        [$table_name]
    );
    if ($stmt === false) return [[], sqlsrv_errors()];
    while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        if (!isset($r['COLUMN_NAME'])) continue;
        $cols[] = strtolower($r['COLUMN_NAME']);
    }
    sqlsrv_free_stmt($stmt);
    return [$cols, null];
}

// api/tmi/gs_apply.php
// "Send Actual GS": merge simulated GS fields from dbo.adl_flights_gs into dbo.adl_flights
// then clear the simulated rows from dbo.adl_flights_gs.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_json(405, [
        'status'  => 'error',
        'message' => 'Method not allowed. Use POST.'
    ]);
}

$payload = read_request_payload();
$conn = get_adl_conn();

// Count simulated flights
list($gs_count, $gs_err) = sqlsrv_fetch_value($conn, "SELECT COUNT(*) FROM dbo.adl_flights_gs WHERE ctl_type = 'GS'");
if ($gs_err) {
    respond_json(500, ['status'=>'error','message'=>$gs_err]);
}
$gs_count = (int)($gs_count ?? 0);
if ($gs_count <= 0) {
    respond_json(200, [
        'status'  => 'ok',
        'message' => 'No simulated GS rows found in dbo.adl_flights_gs (ctl_type=GS). Nothing to apply.',
        'data'    => [
            'simulated_rows' => 0,
            'updated_rows'   => 0,
            'cleared_rows'   => 0,
            'received'       => $payload
        ]
    ]);
}

// Determine safe columns to copy (only those present in BOTH tables)
list($adl_cols, $adl_cols_err) = get_table_columns_lower($conn, 'adl_flights');
if ($adl_cols_err) respond_json(500, ['status'=>'error','message'=>$adl_cols_err]);
list($gs_cols, $gs_cols_err) = get_table_columns_lower($conn, 'adl_flights_gs');
if ($gs_cols_err) respond_json(500, ['status'=>'error','message'=>$gs_cols_err]);

$adl_set = array_fill_keys($adl_cols, true);
$gs_set  = array_fill_keys($gs_cols, true);

// Columns we *want* to apply (if they exist)
$desired = [
    'ctl_type', 'ctl_element',
    'ctd_utc', 'octd_utc',
    'cta_utc', 'octa_utc',
    'delay_status',
    'eta_prefix', 'eta_runway_utc',
    'oetd_utc', 'betd_utc',
    'oeta_utc', 'beta_utc',
    'oete_minutes', 'cete_minutes',
    'igta_utc',
    'absolute_delay_min', 'schedule_variation_min', 'program_delay_min'
];

$apply_cols = [];
foreach ($desired as $c) {
    if (isset($adl_set[$c]) && isset($gs_set[$c])) {
        $apply_cols[] = $c;
    }
}

if (!isset($adl_set['flight_key']) || !isset($gs_set['flight_key'])) {
    respond_json(500, [
        'status'  => 'error',
        'message' => 'flight_key column missing from adl_flights and/or adl_flights_gs. Cannot apply GS.'
    ]);
}

if (count($apply_cols) === 0) {
    respond_json(500, [
        'status'  => 'error',
        'message' => 'No applicable columns found to apply from adl_flights_gs to adl_flights.'
    ]);
}

$set_parts = [];
foreach ($apply_cols as $c) {
    $set_parts[] = "a.[{$c}] = g.[{$c}]";
}

$update_sql = "
    UPDATE a
    SET " . implode(",\n        ", $set_parts) . "
    FROM dbo.adl_flights a
    INNER JOIN dbo.adl_flights_gs g
        ON g.flight_key = a.flight_key
    WHERE g.ctl_type = 'GS'
";

if (!sqlsrv_begin_transaction($conn)) {
    respond_json(500, ['status'=>'error','message'=>'Failed to begin transaction','errors'=>sqlsrv_errors()]);
}

try {
    $upd_stmt = sqlsrv_query($conn, $update_sql);
    if ($upd_stmt === false) {
        throw new Exception('UPDATE failed: ' . json_encode(sqlsrv_errors()));
    }
    $updated_rows = sqlsrv_rows_affected($upd_stmt);
    sqlsrv_free_stmt($upd_stmt);

    // Clear ALL swap table rows after applying (ensures clean state for next simulation)
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

// Return server UTC for UI/debugging
list($utc_now, $utc_err) = sqlsrv_fetch_value($conn, "SELECT SYSUTCDATETIME()");
if ($utc_err) $utc_now = null;

// Fetch the list of affected GS flights for the flight list display
$affected_flights = [];
$flights_sql = "
    SELECT 
        f.callsign AS acid,
        f.fp_dept_icao AS orig,
        f.fp_dest_icao AS dest,
        f.dep_center AS dcenter,
        f.arr_center AS acenter,
        f.etd_runway_utc AS etd_utc,
        f.ctd_utc,
        f.eta_runway_utc AS eta_utc,
        f.cta_utc,
        f.ctl_type,
        f.ctl_element,
        f.delay_status,
        f.program_delay_min,
        f.absolute_delay_min,
        f.flight_status
    FROM dbo.adl_flights f
    WHERE f.ctl_type = 'GS'
    ORDER BY f.ctd_utc ASC, f.eta_runway_utc ASC, f.callsign ASC
";

$flights_stmt = sqlsrv_query($conn, $flights_sql);
if ($flights_stmt !== false) {
    while ($frow = sqlsrv_fetch_array($flights_stmt, SQLSRV_FETCH_ASSOC)) {
        // Convert DateTime objects to ISO strings
        foreach ($frow as $fk => $fv) {
            if ($fv instanceof DateTimeInterface) {
                $frow[$fk] = $fv->format("Y-m-d\\TH:i:s\\Z");
            }
        }
        $affected_flights[] = $frow;
    }
    sqlsrv_free_stmt($flights_stmt);
}

// Calculate delay statistics
$total_delay = 0;
$max_delay = 0;
$delay_count = 0;
foreach ($affected_flights as $af) {
    $delay = isset($af['program_delay_min']) ? (int)$af['program_delay_min'] : 0;
    if ($delay > 0) {
        $total_delay += $delay;
        if ($delay > $max_delay) $max_delay = $delay;
        $delay_count++;
    }
}
$avg_delay = $delay_count > 0 ? round($total_delay / $delay_count, 1) : 0;

respond_json(200, [
    'status'  => 'ok',
    'message' => 'Simulated GS applied to live ADL flights table.',
    'data'    => [
        'server_utc'      => $utc_now,
        'simulated_rows'  => $gs_count,
        'updated_rows'    => ($updated_rows === false ? null : $updated_rows),
        'cleared_rows'    => ($cleared_rows === false ? null : $cleared_rows),
        'applied_columns' => $apply_cols,
        'received'        => $payload,
        'note'            => 'SimTraffic EDCT callout is not implemented yet (placeholder only).'
    ],
    'flight_list' => [
        'flights'     => $affected_flights,
        'total'       => count($affected_flights),
        'total_delay' => $total_delay,
        'max_delay'   => $max_delay,
        'avg_delay'   => $avg_delay,
        'generated_utc' => $utc_now
    ]
]);

?>
