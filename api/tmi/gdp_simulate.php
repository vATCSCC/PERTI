<?php
/**
 * GDP Simulate API
 * 
 * Runs the ETA-Based Slot Allocation (EBSA) algorithm:
 * 1. Clears GDP sandbox table
 * 2. Copies matching flights to sandbox
 * 3. Generates arrival slots based on program rate (15-min bins)
 * 4. Assigns flights to slots in ETA order
 * 5. Calculates CTD = CTA - ETE and delay metrics
 * 
 * Input (JSON POST):
 *   - gdp_airport: Destination airport (CTL element)
 *   - gdp_start: Program start time (UTC)
 *   - gdp_end: Program end time (UTC)
 *   - program_rate: Arrivals per hour (simple mode)
 *   - program_rates_hourly: JSON object of hourly rates (detailed mode)
 *   - reserve_rate: Reserved slots per hour for pop-ups
 *   - Plus scope/filter params (same as preview)
 * 
 * Output:
 *   - Flights with slot assignments
 *   - Slot allocation summary
 *   - Delay metrics
 */

header('Content-Type: application/json; charset=utf-8');

require_once(__DIR__ . '/../../load/connect.php');

// -------------------------------
// Helpers
// -------------------------------
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

function datetime_to_iso($val) {
    if ($val === null) return null;
    if ($val instanceof \DateTimeInterface) {
        $utc = clone $val;
        if (method_exists($utc, 'setTimezone')) {
            $utc->setTimezone(new \DateTimeZone('UTC'));
        }
        return $utc->format('Y-m-d\TH:i:s') . 'Z';
    }
    if (is_string($val)) return $val;
    return $val;
}

function is_flight_exempt($flight, $exemptions) {
    if (empty($exemptions)) return false;
    
    if (!empty($exemptions['orig_airports'])) {
        $exempt_origins = split_codes($exemptions['orig_airports']);
        if (in_array(strtoupper($flight['fp_dept_icao'] ?? ''), $exempt_origins)) return true;
    }
    if (!empty($exemptions['orig_tracons'])) {
        $exempt_tracons = split_codes($exemptions['orig_tracons']);
        if (in_array(strtoupper($flight['fp_dept_tracon'] ?? ''), $exempt_tracons)) return true;
    }
    if (!empty($exemptions['orig_artccs'])) {
        $exempt_artccs = split_codes($exemptions['orig_artccs']);
        if (in_array(strtoupper($flight['fp_dept_artcc'] ?? ''), $exempt_artccs)) return true;
    }
    if (!empty($exemptions['dest_airports'])) {
        $exempt_dests = split_codes($exemptions['dest_airports']);
        if (in_array(strtoupper($flight['fp_dest_icao'] ?? ''), $exempt_dests)) return true;
    }
    if (!empty($exemptions['carriers'])) {
        $exempt_carriers = split_codes($exemptions['carriers']);
        if (in_array(strtoupper($flight['major_carrier'] ?? ''), $exempt_carriers)) return true;
    }
    if (!empty($exemptions['callsigns'])) {
        $exempt_callsigns = split_codes($exemptions['callsigns']);
        if (in_array(strtoupper($flight['callsign'] ?? ''), $exempt_callsigns)) return true;
    }
    if (!empty($exemptions['type_jet']) && strtoupper($flight['ac_cat'] ?? '') === 'JET') return true;
    if (!empty($exemptions['type_prop']) && strtoupper($flight['ac_cat'] ?? '') === 'PROP') return true;
    if (!empty($exemptions['airborne'])) {
        $phase = strtolower($flight['phase'] ?? '');
        if (in_array($phase, ['departed', 'enroute', 'descending'])) return true;
    }
    
    return false;
}

/**
 * Calculate great circle distance between two points using Haversine formula
 * Returns distance in nautical miles
 */
function haversine_nm($lat1, $lon1, $lat2, $lon2) {
    $earth_radius_nm = 3440.065;
    
    $lat1_rad = deg2rad($lat1);
    $lat2_rad = deg2rad($lat2);
    $delta_lat = deg2rad($lat2 - $lat1);
    $delta_lon = deg2rad($lon2 - $lon1);
    
    $a = sin($delta_lat / 2) * sin($delta_lat / 2) +
         cos($lat1_rad) * cos($lat2_rad) *
         sin($delta_lon / 2) * sin($delta_lon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    
    return $earth_radius_nm * $c;
}

/**
 * Generate arrival slots for the GDP period
 * Slots are evenly distributed within each 15-minute bin to spread demand
 * Returns array of slot objects with time, type, bin info
 */
function generate_slots($start_dt, $end_dt, $program_rate, $reserve_rate, $program_rates_hourly = null, $reserve_rates_hourly = null) {
    $slots = [];
    $slot_index = 1;
    
    // Round start to nearest 15-min boundary
    $start_min = (int)$start_dt->format('i');
    $quarter = floor($start_min / 15) * 15;
    $current = clone $start_dt;
    $current->setTime((int)$current->format('G'), $quarter, 0);
    
    while ($current < $end_dt) {
        $hour = (int)$current->format('G');
        $hour_key = (string)$hour;
        $quarter_min = (int)$current->format('i');
        
        // Get rate for this hour (detailed mode overrides simple)
        $hourly_rate = $program_rate;
        if ($program_rates_hourly && isset($program_rates_hourly[$hour_key])) {
            $hourly_rate = (int)$program_rates_hourly[$hour_key];
        }
        
        $hourly_reserve = $reserve_rate;
        if ($reserve_rates_hourly && isset($reserve_rates_hourly[$hour_key])) {
            $hourly_reserve = (int)$reserve_rates_hourly[$hour_key];
        }
        
        // Calculate slots for this 15-min bin
        $total_per_quarter = max(1, floor($hourly_rate / 4));
        $reserve_per_quarter = min($total_per_quarter - 1, floor($hourly_reserve / 4));
        $regular_per_quarter = max(1, $total_per_quarter - $reserve_per_quarter);
        
        // Evenly distribute slots within the 15-minute bin (900 seconds)
        // Regular slots first, then reserved slots at the end
        $total_slots_this_bin = $regular_per_quarter + $reserve_per_quarter;
        
        if ($total_slots_this_bin > 0) {
            // Calculate even spacing: distribute across the full 15 minutes
            $interval_seconds = floor(900 / $total_slots_this_bin);
            
            // Generate regular slots (evenly spaced from start of bin)
            for ($i = 0; $i < $regular_per_quarter; $i++) {
                $slot_time = clone $current;
                // Offset each slot to distribute evenly
                $offset_seconds = $i * $interval_seconds;
                $slot_time->modify("+{$offset_seconds} seconds");
                
                // Don't exceed program end
                if ($slot_time >= $end_dt) break;
                
                $slots[] = [
                    'slot_index' => $slot_index++,
                    'slot_time_utc' => $slot_time->format('Y-m-d H:i:s'),
                    'slot_type' => 'REGULAR',
                    'bin_hour' => $hour,
                    'bin_quarter' => $quarter_min
                ];
            }
            
            // Generate reserved slots (continue after regular slots)
            for ($i = 0; $i < $reserve_per_quarter; $i++) {
                $slot_time = clone $current;
                $offset_seconds = ($regular_per_quarter + $i) * $interval_seconds;
                $slot_time->modify("+{$offset_seconds} seconds");
                
                if ($slot_time >= $end_dt) break;
                
                $slots[] = [
                    'slot_index' => $slot_index++,
                    'slot_time_utc' => $slot_time->format('Y-m-d H:i:s'),
                    'slot_type' => 'RESERVED',
                    'bin_hour' => $hour,
                    'bin_quarter' => $quarter_min
                ];
            }
        }
        
        // Move to next 15-minute bin
        $current->modify('+15 minutes');
    }
    
    return $slots;
}

// -------------------------------
// Input
// -------------------------------
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;

$ctl_element         = isset($input['gdp_airport']) ? strtoupper(trim($input['gdp_airport'])) : '';
$gdp_origin_airports = isset($input['gdp_origin_airports']) ? $input['gdp_origin_airports'] : '';
$gdp_origin_centers  = isset($input['gdp_origin_centers']) ? $input['gdp_origin_centers'] : '';
$gdp_dep_facilities  = isset($input['gdp_dep_facilities']) ? $input['gdp_dep_facilities'] : '';
$flt_type            = isset($input['gdp_flt_incl_type']) ? strtoupper(trim($input['gdp_flt_incl_type'])) : 'ALL';
$carriers_raw        = isset($input['gdp_flt_incl_carrier']) ? $input['gdp_flt_incl_carrier'] : '';
$gdp_start_raw       = isset($input['gdp_start']) ? $input['gdp_start'] : null;
$gdp_end_raw         = isset($input['gdp_end']) ? $input['gdp_end'] : null;
$program_rate        = isset($input['program_rate']) ? (int)$input['program_rate'] : 40;
$reserve_rate        = isset($input['reserve_rate']) ? (int)$input['reserve_rate'] : 0;
$delay_limit         = isset($input['delay_limit']) ? (int)$input['delay_limit'] : 180;
$program_rates_hourly = isset($input['program_rates_hourly']) ? $input['program_rates_hourly'] : null;
$reserve_rates_hourly = isset($input['reserve_rates_hourly']) ? $input['reserve_rates_hourly'] : null;
$exemptions          = isset($input['exemptions']) ? $input['exemptions'] : [];
$distance_nm         = isset($input['distance_nm']) ? (int)$input['distance_nm'] : 0;
$adv_number          = isset($input['adv_number']) ? trim($input['adv_number']) : '';

$origin_airports = split_codes($gdp_origin_airports);
$carriers        = split_codes($carriers_raw);

// Origin centers: use dep_facilities (the expanded list of actual ARTCC codes)
$scope_codes = split_codes($gdp_origin_centers);  // For reference only
$dep_centers = split_codes($gdp_dep_facilities);  // The actual ARTCC codes to filter
if (count($dep_centers) > 0 && $dep_centers[0] === 'ALL') { $dep_centers = []; }
$origin_centers = $dep_centers;  // Use only the expanded facility list

$gdp_start = parse_utc_datetime($gdp_start_raw);
$gdp_end   = parse_utc_datetime($gdp_end_raw);

// Validate required fields
if ($ctl_element === '') {
    echo json_encode(['status'=>'error','message'=>'gdp_airport (CTL element) is required.'], JSON_PRETTY_PRINT);
    exit;
}
if ($gdp_start === null || $gdp_end === null) {
    echo json_encode(['status'=>'error','message'=>'gdp_start and gdp_end are required for simulation (UTC).'], JSON_PRETTY_PRINT);
    exit;
}

// Generate program ID
$program_id = 'GDP-' . $ctl_element . '-' . date('YmdHi', strtotime($gdp_start));

// Connection
$conn = isset($conn_adl) ? $conn_adl : null;
if (!$conn) {
    echo json_encode(['status'=>'error','message'=>'ADL SQL connection not established.'], JSON_PRETTY_PRINT);
    exit;
}

// -------------------------------
// Build filter WHERE clause
// -------------------------------
$where = []; $params = [];

// GDP filters by destination
$where[] = "fp_dest_icao = ?";
$params[] = $ctl_element;

// Arrival time window
$where[] = "(eta_runway_utc >= ? AND eta_runway_utc <= ?)";
$params[] = $gdp_start;
$params[] = $gdp_end;

// Origin filters (only if not using distance mode)
if ($distance_nm == 0 && count($origin_airports) > 0) {
    $where[] = "fp_dept_icao IN (" . implode(',', array_fill(0, count($origin_airports), '?')) . ")";
    foreach ($origin_airports as $o) { $params[] = $o; }
}
if ($distance_nm == 0 && count($origin_centers) > 0) {
    $where[] = "fp_dept_artcc IN (" . implode(',', array_fill(0, count($origin_centers), '?')) . ")";
    foreach ($origin_centers as $c) { $params[] = $c; }
}

// Aircraft type
if ($flt_type !== '' && $flt_type !== 'ALL') {
    if ($flt_type === 'JET')  { $where[] = "(UPPER(ISNULL(ac_cat,'')) = 'JET')"; }
    if ($flt_type === 'PROP') { $where[] = "(UPPER(ISNULL(ac_cat,'')) = 'PROP')"; }
}

// Carriers
if (count($carriers) > 0) {
    $where[] = "major_carrier IN (" . implode(',', array_fill(0, count($carriers), '?')) . ")";
    foreach ($carriers as $mc) { $params[] = $mc; }
}

// Exclude landed flights
$where[] = "(phase IS NULL OR phase != 'arrived')";

// Distance-based scope (if specified)
if ($distance_nm > 0) {
    $apt_stmt = sqlsrv_query($conn, "
        SELECT icao_code, latitude, longitude 
        FROM dbo.airports 
        WHERE icao_code = ?
    ", [$ctl_element]);
    
    if ($apt_stmt !== false && ($apt_row = sqlsrv_fetch_array($apt_stmt, SQLSRV_FETCH_ASSOC))) {
        $gdp_lat = (float)$apt_row['latitude'];
        $gdp_lon = (float)$apt_row['longitude'];
        
        $all_apts_stmt = sqlsrv_query($conn, "
            SELECT icao_code, latitude, longitude 
            FROM dbo.airports 
            WHERE latitude IS NOT NULL AND longitude IS NOT NULL
        ");
        
        $origin_airports_by_distance = [];
        if ($all_apts_stmt !== false) {
            while ($apt = sqlsrv_fetch_array($all_apts_stmt, SQLSRV_FETCH_ASSOC)) {
                $dist = haversine_nm($gdp_lat, $gdp_lon, (float)$apt['latitude'], (float)$apt['longitude']);
                if ($dist <= $distance_nm) {
                    $origin_airports_by_distance[] = $apt['icao_code'];
                }
            }
        }
        
        if (count($origin_airports_by_distance) > 0) {
            $where[] = "fp_dept_icao IN (" . implode(',', array_fill(0, count($origin_airports_by_distance), '?')) . ")";
            foreach ($origin_airports_by_distance as $apt) { $params[] = $apt; }
        }
    }
}

$where_sql = count($where) ? (" WHERE " . implode(" AND ", $where)) : "";

// -------------------------------
// Get column list for sandbox copy
// -------------------------------
$cols_adl = []; $cols_gdp = [];

// Query vw_adl_flights (view over normalized tables) for column discovery
$stmt = sqlsrv_query($conn, "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'vw_adl_flights' ORDER BY ORDINAL_POSITION");
if ($stmt === false) { echo json_encode(['status'=>'error','message'=>sqlsrv_errors()], JSON_PRETTY_PRINT); exit; }
while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) { $cols_adl[] = $r['COLUMN_NAME']; }

$stmt = sqlsrv_query($conn, "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'adl_flights_gdp' ORDER BY ORDINAL_POSITION");
if ($stmt === false) { 
    echo json_encode(['status'=>'error','message'=>'adl_flights_gdp table not found. Run migration first.','errors'=>sqlsrv_errors()], JSON_PRETTY_PRINT); 
    exit; 
}
while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) { $cols_gdp[] = $r['COLUMN_NAME']; }

// Find common columns (excluding identity and GDP-specific)
$adl_set = array_flip($cols_adl);
$gdp_specific = ['id', 'scope', 'gdp_program_id', 'gdp_slot_index', 'gdp_slot_time_utc', 'gdp_original_eta_utc', 'gdp_scope_id', 'gdp_scope_user', 'gdp_scope_created_utc'];
$common = [];
foreach ($cols_gdp as $c) {
    if (in_array(strtolower($c), array_map('strtolower', $gdp_specific))) continue;
    if (!isset($adl_set[$c])) continue;
    $common[] = $c;
}

if (count($common) === 0) {
    echo json_encode(['status'=>'error','message'=>'No common columns found.'], JSON_PRETTY_PRINT);
    exit;
}

$col_list = implode(',', array_map(function($c){ return '['.$c.']'; }, $common));

// -------------------------------
// Transaction: Clear sandbox, seed flights, generate slots, assign
// -------------------------------
if (!sqlsrv_begin_transaction($conn)) {
    echo json_encode(['status'=>'error','message'=>'Failed to begin transaction'], JSON_PRETTY_PRINT);
    exit;
}

try {
    // 1) Clear sandbox tables
    $del = sqlsrv_query($conn, "DELETE FROM dbo.adl_flights_gdp");
    if ($del === false) throw new Exception('DELETE adl_flights_gdp failed: ' . json_encode(sqlsrv_errors()));
    
    $del = sqlsrv_query($conn, "DELETE FROM dbo.adl_slots_gdp WHERE program_id = ?", [$program_id]);
    if ($del === false) throw new Exception('DELETE adl_slots_gdp failed: ' . json_encode(sqlsrv_errors()));
    
    // 2) Seed sandbox with filtered flights from vw_adl_flights (deduped by flight_key)
    $ins_sql = ";WITH deduped AS (
                    SELECT $col_list,
                           ROW_NUMBER() OVER (PARTITION BY flight_key ORDER BY eta_runway_utc ASC) as rn
                    FROM dbo.vw_adl_flights
                    $where_sql
                )
                INSERT INTO dbo.adl_flights_gdp ($col_list)
                SELECT $col_list FROM deduped WHERE rn = 1";
    $ins_stmt = (count($params) > 0) ? sqlsrv_query($conn, $ins_sql, $params) : sqlsrv_query($conn, $ins_sql);
    if ($ins_stmt === false) throw new Exception('INSERT into sandbox failed: ' . json_encode(sqlsrv_errors()));
    
    // 3) Store original ETA before we modify it
    $upd = sqlsrv_query($conn, "UPDATE dbo.adl_flights_gdp SET gdp_original_eta_utc = eta_runway_utc, gdp_program_id = ?", [$program_id]);
    if ($upd === false) throw new Exception('UPDATE original ETA failed: ' . json_encode(sqlsrv_errors()));
    
    // 4) Ensure ETE is populated
    $ete_fix = sqlsrv_query($conn, "
        UPDATE dbo.adl_flights_gdp
        SET ete_minutes = DATEDIFF(MINUTE, etd_runway_utc, eta_runway_utc)
        WHERE ete_minutes IS NULL 
          AND etd_runway_utc IS NOT NULL 
          AND eta_runway_utc IS NOT NULL
    ");
    if ($ete_fix === false) throw new Exception('ETE fix failed: ' . json_encode(sqlsrv_errors()));
    
    // 5) Generate slots
    $start_dt = new DateTime($gdp_start, new DateTimeZone('UTC'));
    $end_dt = new DateTime($gdp_end, new DateTimeZone('UTC'));
    
    // Decode hourly rates if JSON string
    if (is_string($program_rates_hourly)) {
        $program_rates_hourly = json_decode($program_rates_hourly, true);
    }
    if (is_string($reserve_rates_hourly)) {
        $reserve_rates_hourly = json_decode($reserve_rates_hourly, true);
    }
    
    $slots = generate_slots($start_dt, $end_dt, $program_rate, $reserve_rate, $program_rates_hourly, $reserve_rates_hourly);
    
    // Insert slots into database
    foreach ($slots as $slot) {
        $slot_ins = sqlsrv_query($conn, "
            INSERT INTO dbo.adl_slots_gdp 
                (program_id, slot_time_utc, slot_index, bin_hour, bin_quarter, slot_type, slot_status, created_utc)
            VALUES (?, ?, ?, ?, ?, ?, 'OPEN', GETUTCDATE())
        ", [
            $program_id,
            $slot['slot_time_utc'],
            $slot['slot_index'],
            $slot['bin_hour'],
            $slot['bin_quarter'],
            $slot['slot_type']
        ]);
        if ($slot_ins === false) throw new Exception('INSERT slot failed: ' . json_encode(sqlsrv_errors()));
    }
    
    // 6) Fetch flights ordered by ETA for slot assignment
    $flights_stmt = sqlsrv_query($conn, "
        SELECT id, flight_key, callsign, major_carrier, fp_dept_icao, 
               eta_runway_utc, etd_runway_utc, ete_minutes, gdp_original_eta_utc
        FROM dbo.adl_flights_gdp
        ORDER BY gdp_original_eta_utc ASC
    ");
    if ($flights_stmt === false) throw new Exception('SELECT flights failed: ' . json_encode(sqlsrv_errors()));
    
    $flights_to_assign = [];
    while ($f = sqlsrv_fetch_array($flights_stmt, SQLSRV_FETCH_ASSOC)) {
        // Convert DateTimes
        foreach ($f as $k => $v) {
            if ($v instanceof DateTimeInterface) $f[$k] = $v->format('Y-m-d H:i:s');
        }
        $flights_to_assign[] = $f;
    }
    
    // 7) Assign flights to slots (EBSA algorithm)
    // First pass: assign to REGULAR slots
    // Second pass: assign remaining to RESERVED slots
    
    $assigned_count = 0;
    $stack_count = 0;
    
    foreach ($flights_to_assign as &$flight) {
        $original_eta = $flight['gdp_original_eta_utc'];
        $ete = (int)($flight['ete_minutes'] ?? 0);
        
        // Find first available REGULAR slot at or after flight's ETA
        $slot_query = sqlsrv_query($conn, "
            SELECT TOP 1 id, slot_index, slot_time_utc
            FROM dbo.adl_slots_gdp
            WHERE program_id = ?
              AND slot_status = 'OPEN'
              AND slot_type = 'REGULAR'
              AND slot_time_utc >= ?
            ORDER BY slot_time_utc ASC
        ", [$program_id, $original_eta]);
        
        $slot = ($slot_query !== false) ? sqlsrv_fetch_array($slot_query, SQLSRV_FETCH_ASSOC) : null;
        
        // If no regular slot, try reserved
        if (!$slot) {
            $slot_query = sqlsrv_query($conn, "
                SELECT TOP 1 id, slot_index, slot_time_utc
                FROM dbo.adl_slots_gdp
                WHERE program_id = ?
                  AND slot_status = 'OPEN'
                  AND slot_type = 'RESERVED'
                  AND slot_time_utc >= ?
                ORDER BY slot_time_utc ASC
            ", [$program_id, $original_eta]);
            $slot = ($slot_query !== false) ? sqlsrv_fetch_array($slot_query, SQLSRV_FETCH_ASSOC) : null;
        }
        
        if ($slot) {
            // Convert slot_time if needed
            $slot_time = $slot['slot_time_utc'];
            if ($slot_time instanceof DateTimeInterface) {
                $slot_time = $slot_time->format('Y-m-d H:i:s');
            }
            
            // Update slot assignment
            $upd_slot = sqlsrv_query($conn, "
                UPDATE dbo.adl_slots_gdp
                SET slot_status = 'ASSIGNED',
                    assigned_flight_key = ?,
                    assigned_callsign = ?,
                    assigned_carrier = ?,
                    assigned_origin = ?,
                    assigned_utc = GETUTCDATE(),
                    modified_utc = GETUTCDATE()
                WHERE id = ?
            ", [
                $flight['flight_key'],
                $flight['callsign'],
                $flight['major_carrier'],
                $flight['fp_dept_icao'],
                $slot['id']
            ]);
            if ($upd_slot === false) throw new Exception('UPDATE slot assignment failed: ' . json_encode(sqlsrv_errors()));
            
            // Calculate delay and apply cap if needed
            $original_eta = $flight['gdp_original_eta_utc'];
            $slot_time_dt = new DateTime($slot_time, new DateTimeZone('UTC'));
            $orig_eta_dt = new DateTime($original_eta, new DateTimeZone('UTC'));
            $raw_delay_min = ($slot_time_dt->getTimestamp() - $orig_eta_dt->getTimestamp()) / 60;
            
            // Apply delay limit cap
            $capped = false;
            $actual_delay_min = $raw_delay_min;
            $final_cta = $slot_time;
            
            if ($raw_delay_min > $delay_limit) {
                $actual_delay_min = $delay_limit;
                $capped = true;
                // Recalculate CTA based on capped delay
                $capped_cta_dt = clone $orig_eta_dt;
                $capped_cta_dt->modify("+{$delay_limit} minutes");
                $final_cta = $capped_cta_dt->format('Y-m-d H:i:s');
            }
            
            // Update flight with slot assignment
            // CTD = CTA - ETE, CTA = slot_time (or capped time)
            $upd_flight = sqlsrv_query($conn, "
                UPDATE dbo.adl_flights_gdp
                SET gdp_slot_index = ?,
                    gdp_slot_time_utc = ?,
                    cta_utc = ?,
                    ctd_utc = DATEADD(MINUTE, -COALESCE(ete_minutes, 60), ?),
                    ctl_type = CASE WHEN ? = 1 THEN 'GDP-CAP' ELSE 'GDP' END,
                    ctl_element = ?,
                    delay_status = CASE WHEN ? = 1 THEN 'GDP-CAPPED' ELSE 'GDP' END,
                    program_delay_min = ?
                WHERE id = ?
            ", [
                $slot['slot_index'],
                $slot_time,
                $final_cta,
                $final_cta,
                $capped ? 1 : 0,
                $ctl_element,
                $capped ? 1 : 0,
                (int)$actual_delay_min,
                $flight['id']
            ]);
            if ($upd_flight === false) throw new Exception('UPDATE flight slot failed: ' . json_encode(sqlsrv_errors()));
            
            $assigned_count++;
        } else {
            // No slot available - flight goes to stack (past program end)
            $stack_count++;
            
            $upd_flight = sqlsrv_query($conn, "
                UPDATE dbo.adl_flights_gdp
                SET ctl_type = 'GDP-STK',
                    ctl_element = ?,
                    delay_status = 'GDP-STACK'
                WHERE id = ?
            ", [$ctl_element, $flight['id']]);
        }
    }
    
    // 8) Calculate delay metrics
    $taxi_out_minutes = 10;
    $delay_upd = sqlsrv_query($conn, "
        UPDATE dbo.adl_flights_gdp
        SET
            oetd_utc = ISNULL(oetd_utc, etd_runway_utc),
            betd_utc = ISNULL(betd_utc, etd_runway_utc),
            oeta_utc = ISNULL(oeta_utc, gdp_original_eta_utc),
            beta_utc = ISNULL(beta_utc, gdp_original_eta_utc),
            schedule_variation_min = CASE 
                WHEN gdp_original_eta_utc IS NOT NULL AND cta_utc IS NOT NULL
                THEN DATEDIFF(MINUTE, gdp_original_eta_utc, cta_utc)
                ELSE 0 END,
            absolute_delay_min = CASE 
                WHEN gdp_original_eta_utc IS NOT NULL AND cta_utc IS NOT NULL 
                     AND DATEDIFF(MINUTE, gdp_original_eta_utc, cta_utc) > 0
                THEN DATEDIFF(MINUTE, gdp_original_eta_utc, cta_utc)
                ELSE 0 END
        WHERE ctl_type LIKE 'GDP%'
    ");
    if ($delay_upd === false) throw new Exception('Delay metrics UPDATE failed: ' . json_encode(sqlsrv_errors()));
    
    if (!sqlsrv_commit($conn)) throw new Exception('Commit failed: ' . json_encode(sqlsrv_errors()));
    
} catch (Exception $e) {
    sqlsrv_rollback($conn);
    echo json_encode(['status'=>'error','message'=>$e->getMessage()], JSON_PRETTY_PRINT);
    exit;
}

// -------------------------------
// Gather results
// -------------------------------
$summary = [
    'total_flights' => 0,
    'assigned_flights' => $assigned_count,
    'stack_flights' => $stack_count,
    'total_slots' => count($slots),
    'open_slots' => 0,
    'avg_delay_min' => null,
    'max_delay_min' => null,
    'sum_delay_min' => null
];

// Get slot utilization
$slot_stats = sqlsrv_query($conn, "
    SELECT 
        COUNT(*) AS total_slots,
        SUM(CASE WHEN slot_status = 'OPEN' THEN 1 ELSE 0 END) AS open_slots,
        SUM(CASE WHEN slot_status = 'ASSIGNED' THEN 1 ELSE 0 END) AS assigned_slots
    FROM dbo.adl_slots_gdp
    WHERE program_id = ?
", [$program_id]);
if ($slot_stats !== false && ($r = sqlsrv_fetch_array($slot_stats, SQLSRV_FETCH_ASSOC))) {
    $summary['total_slots'] = (int)$r['total_slots'];
    $summary['open_slots'] = (int)$r['open_slots'];
    $summary['slot_utilization'] = $summary['total_slots'] > 0 
        ? round(((int)$r['assigned_slots'] / $summary['total_slots']) * 100, 1) 
        : 0;
}

// Get delay metrics
$delay_stats = sqlsrv_query($conn, "
    SELECT
        COUNT(*) AS total_flights,
        AVG(CAST(program_delay_min AS FLOAT)) AS avg_delay,
        MAX(program_delay_min) AS max_delay,
        SUM(CAST(program_delay_min AS BIGINT)) AS sum_delay
    FROM dbo.adl_flights_gdp
    WHERE ctl_type LIKE 'GDP%' AND program_delay_min IS NOT NULL
");
if ($delay_stats !== false && ($r = sqlsrv_fetch_array($delay_stats, SQLSRV_FETCH_ASSOC))) {
    $summary['total_flights'] = (int)$r['total_flights'];
    $summary['avg_delay_min'] = $r['avg_delay'] !== null ? round((float)$r['avg_delay'], 1) : null;
    $summary['max_delay_min'] = (int)$r['max_delay'];
    $summary['sum_delay_min'] = (int)$r['sum_delay'];
}

// Fetch all flights with assignments
$flights_out = [];
$flights_stmt = sqlsrv_query($conn, "
    SELECT * FROM dbo.adl_flights_gdp
    ORDER BY gdp_slot_index ASC, gdp_original_eta_utc ASC
");
if ($flights_stmt !== false) {
    while ($row = sqlsrv_fetch_array($flights_stmt, SQLSRV_FETCH_ASSOC)) {
        foreach ($row as $key => $val) {
            if ($val instanceof DateTimeInterface) {
                $row[$key] = datetime_to_iso($val);
            }
        }
        $flights_out[] = $row;
    }
}

// Fetch slot allocation
$slots_out = [];
$slots_stmt = sqlsrv_query($conn, "
    SELECT * FROM dbo.adl_slots_gdp
    WHERE program_id = ?
    ORDER BY slot_index ASC
", [$program_id]);
if ($slots_stmt !== false) {
    while ($row = sqlsrv_fetch_array($slots_stmt, SQLSRV_FETCH_ASSOC)) {
        foreach ($row as $key => $val) {
            if ($val instanceof DateTimeInterface) {
                $row[$key] = datetime_to_iso($val);
            }
        }
        $slots_out[] = $row;
    }
}

echo json_encode([
    'status' => 'ok',
    'message' => 'GDP simulation complete.',
    'program_id' => $program_id,
    'filters' => [
        'gdp_airport' => $ctl_element,
        'origin_airports' => $origin_airports,
        'origin_centers' => $origin_centers,
        'carriers' => $carriers,
        'aircraft_filter' => $flt_type,
        'gdp_start_utc' => $gdp_start,
        'gdp_end_utc' => $gdp_end,
        'program_rate' => $program_rate,
        'reserve_rate' => $reserve_rate
    ],
    'summary' => $summary,
    'flights' => $flights_out,
    'slots' => $slots_out
], JSON_PRETTY_PRINT);
?>
