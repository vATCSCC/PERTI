<?php
header('Content-Type: application/json; charset=utf-8');

require_once(__DIR__ . '/../../load/connect.php');  // provides $conn_adl

// -------------------------------
// Helpers
// -------------------------------
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

    // Accept "YYYY-MM-DDTHH:MM", "YYYY-MM-DDTHH:MM:SS", and "...Z"
    try {
        $dt = new DateTime(trim($s));
    } catch (Exception $e) {
        return null;
    }
    $dt->setTimezone(new DateTimeZone('UTC'));
    return $dt->format('Y-m-d H:i:s');
}

// -------------------------------
// Input
// -------------------------------
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;

$gs_airports         = isset($input['gs_airports']) ? $input['gs_airports'] : '';
$gs_origin_airports  = isset($input['gs_origin_airports']) ? $input['gs_origin_airports'] : '';
$gs_dep_facilities   = isset($input['gs_dep_facilities']) ? $input['gs_dep_facilities'] : '';
$gs_origin_centers   = isset($input['gs_origin_centers']) ? $input['gs_origin_centers'] : (isset($input['gs_scope_select']) ? $input['gs_scope_select'] : '');
$gs_flt_incl_type    = isset($input['gs_flt_incl_type']) ? strtoupper(trim($input['gs_flt_incl_type'])) : 'ALL';
$gs_flt_incl_carrier = isset($input['gs_flt_incl_carrier']) ? $input['gs_flt_incl_carrier'] : '';
$gs_start_raw        = isset($input['gs_start']) ? $input['gs_start'] : null;
$gs_end_raw          = isset($input['gs_end']) ? $input['gs_end'] : null;

// Normalize lists
$arrival_airports = split_codes($gs_airports);
$origin_airports  = split_codes($gs_origin_airports);
$carriers         = split_codes($gs_flt_incl_carrier);

// Origin centers: union of scope + dep facilities, unless dep facilities is ALL
$scope_centers = split_codes($gs_origin_centers);
$dep_centers   = split_codes($gs_dep_facilities);
if (count($dep_centers) > 0 && $dep_centers[0] === 'ALL') {
    $dep_centers = [];
}
$origin_centers = array_values(array_unique(array_merge($scope_centers, $dep_centers)));

$gs_start = parse_utc_datetime($gs_start_raw);
$gs_end   = parse_utc_datetime($gs_end_raw);

// -------------------------------
// Connection
// -------------------------------
$conn = isset($conn_adl) ? $conn_adl : null;
if (!$conn) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'ADL SQL connection not established (conn_adl is null). Check ADL_SQL_* constants and sqlsrv extension.',
        'errors'  => function_exists('sqlsrv_errors') ? sqlsrv_errors() : null
    ], JSON_PRETTY_PRINT);
    exit;
}

// -------------------------------
// Query building
// -------------------------------
$where = [];
$params = [];

// CRITICAL: Ground Stop only affects flights STILL ON THE GROUND
// Exclude flights that have already departed (ETD in the past)
// Use a small buffer (5 min) to account for timing differences
$where[] = "(etd_runway_utc > DATEADD(MINUTE, -5, GETUTCDATE()) OR etd_runway_utc IS NULL)";

// Exclude flights that are already airborne or arrived (only want prefile/taxiing)
// phase values: prefile, taxiing, departed, enroute, descending, arrived
$where[] = "(phase IS NULL OR phase IN ('prefile', 'taxiing', 'unknown'))";

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
    // Prefer ac_cat if populated by your refresh SP
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

// For GS: filter by DEPARTURE time window (not arrival)
// Flights departing during the GS period are held
if ($gs_start !== null && $gs_end !== null) {
    $where[] = "(etd_runway_utc >= ? AND etd_runway_utc <= ?)";
    $params[] = $gs_start;
    $params[] = $gs_end;
} elseif ($gs_end !== null) {
    // If only end time specified, get flights departing before GS ends
    $where[] = "(etd_runway_utc <= ?)";
    $params[] = $gs_end;
}

// Final SQL
$sql = "SELECT * FROM dbo.adl_flights";
if (count($where) > 0) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY etd_runway_utc ASC";

// -------------------------------
// Execute
// -------------------------------
$stmt = null;
if (count($params) > 0) {
    $stmt = sqlsrv_query($conn, $sql, $params);
} else {
    $stmt = sqlsrv_query($conn, $sql);
}

if ($stmt === false) {
    echo json_encode([
        'status'  => 'error',
        'message' => sqlsrv_errors()
    ], JSON_PRETTY_PRINT);
    exit;
}

$flights = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $flights[] = $row;
}

echo json_encode([
    'status'  => 'ok',
    'message' => 'Preview retrieved',
    'total'   => count($flights),
    'filters' => [
        'arrival_airports' => $arrival_airports,
        'origin_airports'  => $origin_airports,
        'origin_centers'   => $origin_centers,
        'carriers'         => $carriers,
        'aircraft_filter'  => $gs_flt_incl_type,
        'gs_start_utc'     => $gs_start,
        'gs_end_utc'       => $gs_end
    ],
    'flights' => $flights
], JSON_PRETTY_PRINT);
?>
