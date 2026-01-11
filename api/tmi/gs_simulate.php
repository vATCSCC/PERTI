<?php
header('Content-Type: application/json; charset=utf-8');

require_once(__DIR__ . '/../../load/connect.php'); // provides $conn_adl

function split_codes($val) {
    if (is_array($val)) $val = implode(' ', $val);
    if (!is_string($val)) return [];
    $val = strtoupper(trim($val));
    if ($val === '') return [];
    $val = str_replace([",",";","\n","\r","\t"], " ", $val);
    $parts = preg_split('/\s+/', $val);
    $seen = []; $out = [];
    foreach ($parts as $p) { $p = trim($p); if ($p !== '' && !isset($seen[$p])) { $seen[$p]=1; $out[]=$p; } }
    return $out;
}
function parse_utc_datetime($s) {
    if (!is_string($s) || trim($s)==='') return null;
    try { $dt = new DateTime(trim($s)); } catch (Exception $e) { return null; }
    $dt->setTimezone(new DateTimeZone('UTC'));
    return $dt->format('Y-m-d H:i:s');
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;

$ctl_element        = isset($input['gs_ctl_element']) ? strtoupper(trim($input['gs_ctl_element'])) : 'UNKNOWN';
$gs_airports        = isset($input['gs_airports']) ? $input['gs_airports'] : '';
$gs_origin_airports = isset($input['gs_origin_airports']) ? $input['gs_origin_airports'] : '';
$gs_dep_facilities  = isset($input['gs_dep_facilities']) ? $input['gs_dep_facilities'] : '';
$gs_origin_centers  = isset($input['gs_origin_centers']) ? $input['gs_origin_centers'] : (isset($input['gs_scope_select']) ? $input['gs_scope_select'] : '');
$flt_type           = isset($input['gs_flt_incl_type']) ? strtoupper(trim($input['gs_flt_incl_type'])) : 'ALL';
$carriers_raw       = isset($input['gs_flt_incl_carrier']) ? $input['gs_flt_incl_carrier'] : '';
$gs_start_raw       = isset($input['gs_start']) ? $input['gs_start'] : null;
$gs_end_raw         = isset($input['gs_end']) ? $input['gs_end'] : null;

$taxi_out_minutes = 10;

$arrival_airports = split_codes($gs_airports);
$origin_airports  = split_codes($gs_origin_airports);
$carriers         = split_codes($carriers_raw);

$scope_centers = split_codes($gs_origin_centers);
$dep_centers   = split_codes($gs_dep_facilities);
if (count($dep_centers) > 0 && $dep_centers[0] === 'ALL') { $dep_centers = []; }
$origin_centers = array_values(array_unique(array_merge($scope_centers, $dep_centers)));

$gs_start = parse_utc_datetime($gs_start_raw);
$gs_end   = parse_utc_datetime($gs_end_raw);

if ($gs_end === null) {
    echo json_encode(['status'=>'error','message'=>'gs_end is required for simulation (UTC).'], JSON_PRETTY_PRINT);
    exit;
}

$conn = isset($conn_adl) ? $conn_adl : null;
if (!$conn) {
    echo json_encode(['status'=>'error','message'=>'ADL SQL connection not established (conn_adl is null).'], JSON_PRETTY_PRINT);
    exit;
}

// Build filter WHERE clause (same as preview)
$where = []; $params = [];

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
if ($flt_type !== '' && $flt_type !== 'ALL') {
    if ($flt_type === 'JET')  { $where[] = "(UPPER(ISNULL(ac_cat,'')) = 'JET')"; }
    if ($flt_type === 'PROP') { $where[] = "(UPPER(ISNULL(ac_cat,'')) = 'PROP')"; }
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
$where_sql = count($where) ? (" WHERE " . implode(" AND ", $where)) : "";

// Get common columns between vw_adl_flights (view) and adl_flights_gs (excluding identity)
// NOTE: Live flight data is in normalized tables, accessed via vw_adl_flights view
$cols_adl = []; $cols_gs = [];
$stmt = sqlsrv_query($conn, "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'vw_adl_flights' ORDER BY ORDINAL_POSITION");
if ($stmt === false) { echo json_encode(['status'=>'error','message'=>sqlsrv_errors()], JSON_PRETTY_PRINT); exit; }
while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) { $cols_adl[] = $r['COLUMN_NAME']; }

$stmt = sqlsrv_query($conn, "SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'adl_flights_gs' ORDER BY ORDINAL_POSITION");
if ($stmt === false) { echo json_encode(['status'=>'error','message'=>sqlsrv_errors()], JSON_PRETTY_PRINT); exit; }
$slot_is_nvarchar8 = false;
while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $cols_gs[] = $r['COLUMN_NAME'];
    if (strcasecmp($r['COLUMN_NAME'], 'slot_time') === 0 && strcasecmp($r['DATA_TYPE'], 'nvarchar') === 0 && (int)$r['CHARACTER_MAXIMUM_LENGTH'] === 8) {
        $slot_is_nvarchar8 = true;
    }
}

$adl_set = array_flip($cols_adl);
$common  = [];
foreach ($cols_gs as $c) {
    if (strcasecmp($c,'id')===0) continue;        // Skip identity column
    if (strcasecmp($c,'scope')===0) continue;     // Skip scope - it's part of unique index, let it default to NULL
    if (!isset($adl_set[$c])) continue;
    $common[] = $c;
}
if (count($common) === 0) {
    echo json_encode(['status'=>'error','message'=>'No common columns found between vw_adl_flights and adl_flights_gs.'], JSON_PRETTY_PRINT);
    exit;
}

// Verify flight_key is in the column list (required for deduplication)
$has_flight_key = false;
foreach ($common as $c) {
    if (strcasecmp($c, 'flight_key') === 0) {
        $has_flight_key = true;
        break;
    }
}
if (!$has_flight_key) {
    echo json_encode(['status'=>'error','message'=>'flight_key column not found in common columns - cannot deduplicate.'], JSON_PRETTY_PRINT);
    exit;
}

$col_list = implode(',', array_map(function($c){ return '['.$c.']'; }, $common));

if (!sqlsrv_begin_transaction($conn)) {
    echo json_encode(['status'=>'error','message'=>'Failed to begin transaction','errors'=>sqlsrv_errors()], JSON_PRETTY_PRINT);
    exit;
}

try {
    // 1) Clear ALL rows from swap table (not just GS - avoids unique constraint conflicts)
    // Use DELETE instead of TRUNCATE since TRUNCATE can't be rolled back in transaction
    $del = sqlsrv_query($conn, "DELETE FROM dbo.adl_flights_gs");
    if ($del === false) throw new Exception('DELETE failed: ' . json_encode(sqlsrv_errors()));

    // 2) Seed GS with filtered ADL snapshot from vw_adl_flights (normalized tables view)
    //    Use CTE with ROW_NUMBER to dedupe by flight_key (unique index includes scope + flight_key)
    //    Partition by flight_key alone since scope will be NULL for all inserted rows
    $ins_sql = ";WITH deduped AS (
                    SELECT $col_list,
                           ROW_NUMBER() OVER (PARTITION BY flight_key ORDER BY eta_runway_utc ASC) as rn
                    FROM dbo.vw_adl_flights
                    $where_sql
                )
                INSERT INTO dbo.adl_flights_gs ($col_list)
                SELECT $col_list FROM deduped WHERE rn = 1";
    $ins_stmt = (count($params) > 0) ? sqlsrv_query($conn, $ins_sql, $params) : sqlsrv_query($conn, $ins_sql);
    if ($ins_stmt === false) throw new Exception('INSERT failed: ' . json_encode(sqlsrv_errors()));

    // 3) First, ensure ete_minutes is populated for all flights
    //    If ete_minutes is NULL, calculate it from etd_runway_utc and eta_runway_utc
    $ete_fix_sql = "
        UPDATE dbo.adl_flights_gs
        SET ete_minutes = DATEDIFF(MINUTE, etd_runway_utc, eta_runway_utc)
        WHERE ete_minutes IS NULL 
          AND etd_runway_utc IS NOT NULL 
          AND eta_runway_utc IS NOT NULL
    ";
    $ete_fix_stmt = sqlsrv_query($conn, $ete_fix_sql);
    if ($ete_fix_stmt === false) throw new Exception('ETE fix UPDATE failed: ' . json_encode(sqlsrv_errors()));

    // 4) Apply GS controls
    //    slot_time must respect current schema: nvarchar(8) 'dd/HHmm'
    $slot_expr = "RIGHT('0'+CONVERT(VARCHAR(2), DAY(?)), 2) + '/' + RIGHT('0'+CONVERT(VARCHAR(2), DATEPART(HOUR, ?)), 2) + RIGHT('0'+CONVERT(VARCHAR(2), DATEPART(MINUTE, ?)), 2)";

    // Calculate ETE inline as fallback: COALESCE(cete_minutes, ete_minutes, DATEDIFF(MINUTE, etd_runway_utc, eta_runway_utc), 0)
    $ete_expr = "COALESCE(cete_minutes, ete_minutes, DATEDIFF(MINUTE, etd_runway_utc, eta_runway_utc), 0)";

    $upd_sql = "
        UPDATE dbo.adl_flights_gs
        SET
            ctl_type        = 'GS',
            ctl_element     = ?,

            -- baseline/original times (fill if null)
            oetd_utc        = ISNULL(oetd_utc, etd_runway_utc),
            betd_utc        = ISNULL(betd_utc, etd_runway_utc),
            oeta_utc        = COALESCE(oeta_utc, eta_runway_utc, DATEADD(MINUTE, " . $ete_expr . ", etd_runway_utc)),
            beta_utc        = COALESCE(beta_utc, eta_runway_utc, DATEADD(MINUTE, " . $ete_expr . ", etd_runway_utc)),
            oete_minutes    = COALESCE(oete_minutes, ete_minutes, DATEDIFF(MINUTE, etd_runway_utc, eta_runway_utc)),
            cete_minutes    = COALESCE(cete_minutes, ete_minutes, DATEDIFF(MINUTE, etd_runway_utc, eta_runway_utc)),

            -- control times: CTD = GS End, CTA = CTD + ETE
            octd_utc        = ?,
            ctd_utc         = ?,
            cta_utc         = DATEADD(MINUTE, " . $ete_expr . ", ?),
            octa_utc        = DATEADD(MINUTE, " . $ete_expr . ", ?),

            slot_time       = " . $slot_expr . ",

            delay_status    = 'GSD',

            eta_prefix      = 'C',
            eta_runway_utc  = DATEADD(MINUTE, " . $ete_expr . ", ?),

            igta_utc        = COALESCE(igta_utc, beta_utc, eta_runway_utc, DATEADD(MINUTE, " . $ete_expr . ", etd_runway_utc))
        " . $where_sql . "
    ";

    $upd_params = [
        $ctl_element,
        $gs_end, $gs_end, // octd, ctd
        $gs_end, $gs_end, // base for cta, octa
        $gs_end, $gs_end, $gs_end, // slot dd/HHmm from gs_end
        $gs_end           // base for eta_runway_utc
    ];
    // Append the WHERE params (same as used for insert)
    $upd_params = array_merge($upd_params, $params);

    $upd_stmt = sqlsrv_query($conn, $upd_sql, $upd_params);
    if ($upd_stmt === false) throw new Exception('UPDATE failed: ' . json_encode(sqlsrv_errors()));

    // 5) Delay metrics
    $delay_sql = "
        UPDATE dbo.adl_flights_gs
        SET
            schedule_variation_min =
                CASE WHEN igta_utc IS NOT NULL OR beta_utc IS NOT NULL
                     THEN DATEDIFF(MINUTE, DATEADD(MINUTE, -?, COALESCE(igta_utc, beta_utc)), eta_runway_utc)
                     ELSE 0 END,
            absolute_delay_min =
                CASE WHEN (igta_utc IS NOT NULL OR beta_utc IS NOT NULL) 
                      AND DATEDIFF(MINUTE, DATEADD(MINUTE, -?, COALESCE(igta_utc, beta_utc)), eta_runway_utc) > 0
                     THEN DATEDIFF(MINUTE, DATEADD(MINUTE, -?, COALESCE(igta_utc, beta_utc)), eta_runway_utc)
                     ELSE 0 END,
            program_delay_min =
                CASE WHEN beta_utc IS NOT NULL AND cta_utc IS NOT NULL 
                      AND DATEDIFF(MINUTE, beta_utc, cta_utc) > 0
                     THEN DATEDIFF(MINUTE, beta_utc, cta_utc)
                     ELSE 0 END
        WHERE ctl_type = 'GS'
    ";
    $delay_params = [$taxi_out_minutes, $taxi_out_minutes, $taxi_out_minutes];
    $delay_stmt = sqlsrv_query($conn, $delay_sql, $delay_params);
    if ($delay_stmt === false) throw new Exception('Delay UPDATE failed: ' . json_encode(sqlsrv_errors()));

    if (!sqlsrv_commit($conn)) throw new Exception('Commit failed: ' . json_encode(sqlsrv_errors()));

} catch (Exception $e) {
    sqlsrv_rollback($conn);
    echo json_encode(['status'=>'error','message'=>$e->getMessage()], JSON_PRETTY_PRINT);
    exit;
}

// Summary + results
$summary = [
    'total_flights' => 0,
    'avg_program_delay_min' => null,
    'max_program_delay_min' => null,
    'sum_program_delay_min' => null
];

$sum_stmt = sqlsrv_query($conn, "
    SELECT
        COUNT(*) AS total_flights,
        AVG(CAST(program_delay_min AS FLOAT)) AS avg_program_delay_min,
        MAX(program_delay_min) AS max_program_delay_min,
        SUM(CAST(program_delay_min AS BIGINT)) AS sum_program_delay_min
    FROM dbo.adl_flights_gs
    WHERE ctl_type = 'GS'
");
if ($sum_stmt !== false && ($r = sqlsrv_fetch_array($sum_stmt, SQLSRV_FETCH_ASSOC))) {
    $summary['total_flights'] = (int)$r['total_flights'];
    $summary['avg_program_delay_min'] = $r['avg_program_delay_min'] !== null ? round((float)$r['avg_program_delay_min'], 2) : null;
    $summary['max_program_delay_min'] = $r['max_program_delay_min'];
    $summary['sum_program_delay_min'] = $r['sum_program_delay_min'];
}

$stmt = sqlsrv_query($conn, "SELECT * FROM dbo.adl_flights_gs WHERE ctl_type = 'GS' ORDER BY ctd_utc ASC, etd_runway_utc ASC");
if ($stmt === false) {
    echo json_encode(['status'=>'error','message'=>sqlsrv_errors()], JSON_PRETTY_PRINT);
    exit;
}

// Helper to convert DateTime to ISO string
function datetime_to_iso($val) {
    if ($val === null) return null;
    if ($val instanceof \DateTimeInterface) {
        // Ensure UTC timezone and format with milliseconds
        $utc = clone $val;
        if (method_exists($utc, 'setTimezone')) {
            $utc->setTimezone(new \DateTimeZone('UTC'));
        }
        return $utc->format('Y-m-d\TH:i:s') . 'Z';
    }
    // If it's already a string that looks like a date, return as-is
    if (is_string($val)) return $val;
    return $val;
}

$flights = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    // Convert ALL DateTime objects to ISO strings for proper JSON serialization
    foreach ($row as $key => $val) {
        if ($val instanceof \DateTimeInterface) {
            $row[$key] = datetime_to_iso($val);
        }
    }
    $flights[] = $row;
}

echo json_encode([
    'status'  => 'ok',
    'message' => 'GS simulation applied.',
    'filters' => [
        'arrival_airports' => $arrival_airports,
        'origin_airports'  => $origin_airports,
        'origin_centers'   => $origin_centers,
        'carriers'         => $carriers,
        'aircraft_filter'  => $flt_type,
        'gs_start_utc'     => $gs_start,
        'gs_end_utc'       => $gs_end,
        'taxi_out_minutes' => $taxi_out_minutes,
        'ctl_element'      => $ctl_element
    ],
    'summary' => $summary,
    'flights' => $flights
], JSON_PRETTY_PRINT);
?>
