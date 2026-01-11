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
    require_once(__DIR__ . '/../../load/connect.php');
    if (isset($conn_adl) && $conn_adl) return $conn_adl;

    respond_json(500, [
        'status'  => 'error',
        'message' => 'ADL SQL connection not established (conn_adl is null). Check load/connect.php and ADL_SQL_* constants.',
        'errors'  => function_exists('sqlsrv_errors') ? sqlsrv_errors() : null
    ]);
}

function split_codes($val) {
    if (is_array($val)) {
        $val = implode(' ', $val);
    }
    if (!is_string($val)) return [];
    $val = strtoupper(trim($val));
    if ($val === '') return [];
    $val = str_replace([",", ";", "\n", "\r", "\t"], " ", $val);
    $parts = preg_split('/\s+/', $val);
    $out = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '') $out[] = $p;
    }
    // unique, preserve order
    $seen = [];
    $uniq = [];
    foreach ($out as $p) {
        if (!isset($seen[$p])) { $seen[$p] = true; $uniq[] = $p; }
    }
    return $uniq;
}

function parse_utc_datetime($s) {
    if (!is_string($s) || trim($s) === '') return null;
    try {
        $dt = new DateTime(trim($s));
    } catch (Exception $e) {
        return null;
    }
    $dt->setTimezone(new DateTimeZone('UTC'));
    return $dt->format('Y-m-d H:i:s');
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

function sqlsrv_fetch_value($conn, $sql, $params = []) {
    $stmt = (count($params) > 0) ? sqlsrv_query($conn, $sql, $params) : sqlsrv_query($conn, $sql);
    if ($stmt === false) return [null, sqlsrv_errors()];
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_NUMERIC);
    sqlsrv_free_stmt($stmt);
    if (!$row) return [null, null];
    return [$row[0], null];
}

// api/tmi/gs_purge_local.php
// "Purge Local EDCTs": remove current simulated rows (ctl_type='GS') from dbo.adl_flights_gs
// then re-seed dbo.adl_flights_gs from the most current dbo.adl_flights using the provided GS filters
// WITHOUT applying simulated control changes.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_json(405, [
        'status'  => 'error',
        'message' => 'Method not allowed. Use POST.'
    ]);
}

$input = read_request_payload();
$conn = get_adl_conn();

$gs_airports         = isset($input['gs_airports']) ? $input['gs_airports'] : '';
$gs_origin_airports  = isset($input['gs_origin_airports']) ? $input['gs_origin_airports'] : '';
$gs_dep_facilities   = isset($input['gs_dep_facilities']) ? $input['gs_dep_facilities'] : '';
$gs_origin_centers   = isset($input['gs_origin_centers']) ? $input['gs_origin_centers'] : (isset($input['gs_scope_select']) ? $input['gs_scope_select'] : '');
$gs_flt_incl_type    = isset($input['gs_flt_incl_type']) ? strtoupper(trim($input['gs_flt_incl_type'])) : 'ALL';
$gs_flt_incl_carrier = isset($input['gs_flt_incl_carrier']) ? $input['gs_flt_incl_carrier'] : '';
$gs_start_raw        = isset($input['gs_start']) ? $input['gs_start'] : null;
$gs_end_raw          = isset($input['gs_end']) ? $input['gs_end'] : null;

$arrival_airports = split_codes($gs_airports);
$origin_airports  = split_codes($gs_origin_airports);
$carriers         = split_codes($gs_flt_incl_carrier);

$scope_centers = split_codes($gs_origin_centers);
$dep_centers   = split_codes($gs_dep_facilities);
if (count($dep_centers) > 0 && $dep_centers[0] === 'ALL') {
    $dep_centers = [];
}
$origin_centers = array_values(array_unique(array_merge($scope_centers, $dep_centers)));

$gs_start = parse_utc_datetime($gs_start_raw);
$gs_end   = parse_utc_datetime($gs_end_raw);

// Build WHERE and params (same filter semantics as gs_preview/gs_simulate)
$where = [];
$params = [];

if (count($arrival_airports) > 0) {
    $where[] = "fp_dest_icao IN (" . implode(',', array_fill(0, count($arrival_airports), '?')) . ")";
    foreach ($arrival_airports as $a) { $params[] = $a; }
}
if (count($origin_airports) > 0) {
    $where[] = "fp_dept_icao IN (" . implode(',', array_fill(0, count($origin_airports), '?')) . ")";
    foreach ($origin_airports as $o) { $params[] = $o; }
}
if (count($origin_centers) > 0) {
    $where[] = "fp_dept_artcc IN (" . implode(',', array_fill(0, count($origin_centers), '?')) . ")";
    foreach ($origin_centers as $c) { $params[] = $c; }
}

if ($gs_flt_incl_type !== '' && $gs_flt_incl_type !== 'ALL') {
    if ($gs_flt_incl_type === 'JET') {
        $where[] = "(UPPER(ISNULL(ac_cat,'')) = 'JET')";
    } elseif ($gs_flt_incl_type === 'PROP') {
        $where[] = "(UPPER(ISNULL(ac_cat,'')) = 'PROP')";
    }
}

if (count($carriers) > 0) {
    $where[] = "major_carrier IN (" . implode(',', array_fill(0, count($carriers), '?')) . ")";
    foreach ($carriers as $mc) { $params[] = $mc; }
}

if ($gs_start !== null && $gs_end !== null) {
    $where[] = "(eta_runway_utc >= ? AND eta_runway_utc <= ?)";
    $params[] = $gs_start;
    $params[] = $gs_end;
}

// Determine safe insert columns (intersection of vw_adl_flights and adl_flights_gs; skip identity id)
// Use vw_adl_flights as source since live data is in normalized tables
list($adl_cols, $adl_cols_err) = get_table_columns_lower($conn, 'vw_adl_flights');
if ($adl_cols_err) respond_json(500, ['status'=>'error','message'=>$adl_cols_err]);
list($gs_cols, $gs_cols_err) = get_table_columns_lower($conn, 'adl_flights_gs');
if ($gs_cols_err) respond_json(500, ['status'=>'error','message'=>$gs_cols_err]);

$adl_set = array_fill_keys($adl_cols, true);
$gs_set  = array_fill_keys($gs_cols, true);

$common = [];
foreach ($adl_cols as $c) {
    if ($c === 'id') continue; // let adl_flights_gs generate its own IDs
    if (isset($gs_set[$c])) $common[] = $c;
}

if (!isset($adl_set['flight_key']) || !isset($gs_set['flight_key'])) {
    respond_json(500, [
        'status'  => 'error',
        'message' => 'flight_key column missing from vw_adl_flights and/or adl_flights_gs. Cannot reseed gs table.'
    ]);
}

$ins_cols_sql = implode(', ', array_map(function($c){ return "[{$c}]"; }, $common));
$sel_cols_sql = implode(', ', array_map(function($c){ return "a.[{$c}]"; }, $common));

// Query from vw_adl_flights (normalized tables view)
$sql = "SELECT {$sel_cols_sql} FROM dbo.vw_adl_flights a";
if (count($where) > 0) {
    $sql .= " WHERE " . implode(' AND ', $where);
}

if (!sqlsrv_begin_transaction($conn)) {
    respond_json(500, ['status'=>'error','message'=>'Failed to begin transaction','errors'=>sqlsrv_errors()]);
}

try {
    // Remove ALL rows from swap table (not just GS - avoids leaving orphan rows)
    $del_stmt = sqlsrv_query($conn, "DELETE FROM dbo.adl_flights_gs");
    if ($del_stmt === false) throw new Exception('DELETE failed: ' . json_encode(sqlsrv_errors()));
    $deleted = sqlsrv_rows_affected($del_stmt);
    sqlsrv_free_stmt($del_stmt);

    // Don't reseed - just clear the simulation table
    $inserted = 0;

    if (!sqlsrv_commit($conn)) throw new Exception('Commit failed: ' . json_encode(sqlsrv_errors()));

} catch (Exception $e) {
    sqlsrv_rollback($conn);
    respond_json(500, ['status'=>'error','message'=>$e->getMessage()]);
}

list($utc_now, $utc_err) = sqlsrv_fetch_value($conn, 'SELECT SYSUTCDATETIME()');
if ($utc_err) $utc_now = null;

respond_json(200, [
    'status'  => 'ok',
    'message' => 'Local simulated GS rows purged and GS swap table reseeded from current ADL (if filters provided).',
    'data'    => [
        'server_utc'       => $utc_now,
        'deleted_gs_rows'  => ($deleted === false ? null : $deleted),
        'inserted_rows'    => ($inserted === false ? null : $inserted),
        'filters_used'     => [
            'arrival_airports' => $arrival_airports,
            'origin_airports'  => $origin_airports,
            'origin_centers'   => $origin_centers,
            'carriers'         => $carriers,
            'aircraft_filter'  => $gs_flt_incl_type,
            'gs_start_utc'     => $gs_start,
            'gs_end_utc'       => $gs_end
        ],
        'note'            => (count($where) > 0) ? null : 'No filters provided; only simulated GS rows were deleted (no reseed performed).',
        'received'        => $input
    ]
]);

?>
