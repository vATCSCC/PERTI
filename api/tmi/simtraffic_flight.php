<?php
// wwwroot/api/tmi/simtraffic_flight.php
// Proxy to SimTraffic /v1/flight/{CALLSIGN}.
//
// Goals:
//  - Reduce load on SimTraffic (success cache + negative cache)
//  - Stop hammering SimTraffic during error bursts (circuit breaker / cooldown)
//  - Map selected SimTraffic timing fields into ADL tables (optional)
//
// API key:
//   - Environment variable SIMTRAFFIC_API_KEY (preferred)
//   - Or define('SIMTRAFFIC_API_KEY', '...') globally

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$cs = isset($_GET['cs']) ? get_upper('cs') : '';
if ($cs === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing callsign (?cs=)']);
    exit;
}

// Sanitize callsign for URL + filesystem keys
$cs = preg_replace('/[^A-Z0-9]/', '', $cs);
$cs = substr($cs, 0, 16);
if ($cs === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid callsign']);
    exit;
}

// Optional behavior
$applyAdl = true;
if (isset($_GET['apply_adl'])) {
    $v = strtolower(trim(strval($_GET['apply_adl'])));
    $applyAdl = !($v === '0' || $v === 'false' || $v === 'no' || $v === 'off');
}

// target:
//  - gs_then_adl (default): update adl_flights_gs if callsign exists there; else adl_flights
//  - adl: only adl_flights
//  - gs: only adl_flights_gs
//  - both: update both (when found)
$target = isset($_GET['target']) ? strtolower(trim(strval($_GET['target']))) : 'gs_then_adl';
if (!in_array($target, ['gs_then_adl', 'adl', 'gs', 'both'], true)) {
    $target = 'gs_then_adl';
}

// -------------------------------
// Config
// -------------------------------
$CACHE_TTL_OK    = 120; // seconds
$CACHE_TTL_ERROR = 30;  // seconds

$CB_WINDOW_SEC   = 60;  // rolling window
$CB_MAX_ERRORS   = 6;   // errors in window to trigger cooldown
$CB_COOLDOWN_SEC = 180; // cooldown duration

// -------------------------------
// API key
// -------------------------------
$apiKey = getenv('SIMTRAFFIC_API_KEY');
if (!$apiKey && defined('SIMTRAFFIC_API_KEY')) {
    $apiKey = SIMTRAFFIC_API_KEY;
}
if (!$apiKey) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'SimTraffic API key not configured']);
    exit;
}

// -------------------------------
// Cache + circuit breaker state
// -------------------------------
$cacheRoot = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'vATCSCC_simtraffic_proxy';
if (!is_dir($cacheRoot)) {
    @mkdir($cacheRoot, 0777, true);
}

$cacheOkPath   = $cacheRoot . DIRECTORY_SEPARATOR . 'flight_' . $cs . '.json';
$cacheErrPath  = $cacheRoot . DIRECTORY_SEPARATOR . 'flight_' . $cs . '.err.json';
$statePath     = $cacheRoot . DIRECTORY_SEPARATOR . 'state.json';

function read_json_file($path) {
    if (!is_file($path)) return null;
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') return null;
    $obj = json_decode($raw, true);
    return is_array($obj) ? $obj : null;
}

function write_json_file_atomic($path, $data) {
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    @file_put_contents($tmp, json_encode($data));
    @rename($tmp, $path);
}

function http_json($code, $payload, $retryAfterSeconds = null) {
    if ($retryAfterSeconds !== null) {
        header('Retry-After: ' . intval($retryAfterSeconds));
    }
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

// Read state with best-effort locking
$state = [
    'cooldown_until' => 0,
    'error_times'    => []
];

$now = time();
$fh = @fopen($statePath, 'c+');
if ($fh) {
    if (@flock($fh, LOCK_EX)) {
        $raw = stream_get_contents($fh);
        if ($raw !== false && $raw !== '') {
            $tmp = json_decode($raw, true);
            if (is_array($tmp)) {
                $state = array_merge($state, $tmp);
            }
        }
        // Keep lock until we update below.
    }
}

// If currently in cooldown, fail fast
$cooldownUntil = isset($state['cooldown_until']) ? intval($state['cooldown_until']) : 0;
if ($cooldownUntil > $now) {
    $retry = max(1, $cooldownUntil - $now);
    if ($fh) { @flock($fh, LOCK_UN); @fclose($fh); }
    http_json(503, [
        'status'  => 'error',
        'message' => 'SimTraffic temporarily throttled due to upstream errors',
        'retry_after' => $retry
    ], $retry);
}

$cached = false;
$cacheAge = null;
$data = null;

// Serve cached success if fresh
if (is_file($cacheOkPath)) {
    $age = $now - intval(@filemtime($cacheOkPath));
    if ($age >= 0 && $age <= $CACHE_TTL_OK) {
        $raw = @file_get_contents($cacheOkPath);
        if ($raw !== false && $raw !== '') {
            $tmp = json_decode($raw, true);
            if (is_array($tmp)) {
                $data = $tmp;
                $cached = true;
                $cacheAge = $age;
            }
        }
    }
}

// Serve cached error if fresh (negative cache)
if ($data === null && is_file($cacheErrPath)) {
    $age = $now - intval(@filemtime($cacheErrPath));
    if ($age >= 0 && $age <= $CACHE_TTL_ERROR) {
        $err = read_json_file($cacheErrPath);
        $code = 502;
        $payload = [
            'status'  => 'error',
            'message' => 'Cached SimTraffic error'
        ];
        $retry = null;

        if (is_array($err)) {
            if (isset($err['http_status'])) $code = intval($err['http_status']);
            if (isset($err['payload']) && is_array($err['payload'])) $payload = $err['payload'];
            if (isset($err['retry_after'])) $retry = intval($err['retry_after']);
        }

        if ($fh) { @flock($fh, LOCK_UN); @fclose($fh); }
        http_json(($code >= 400 ? $code : 502), $payload, $retry);
    }
}

// -------------------------------
// Live call to SimTraffic (if needed)
// -------------------------------
if ($data === null) {
    $url = 'https://api.simtraffic.net/v1/flight/' . rawurlencode($cs);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,   // read Retry-After if present
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_HTTPHEADER     => [
            'Authorization: ' . $apiKey,
            'Accept: application/json'
        ]
    ]);

    $resp = curl_exec($ch);
    $errNo = curl_errno($ch);
    $httpStatus = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
    $headerSize = intval(curl_getinfo($ch, CURLINFO_HEADER_SIZE));
    curl_close($ch);

    $headersRaw = '';
    $body = '';
    if ($resp !== false && $headerSize > 0) {
        $headersRaw = substr($resp, 0, $headerSize);
        $body = substr($resp, $headerSize);
    } else {
        $body = $resp;
    }

    $retryAfter = null;
    if ($headersRaw) {
        foreach (explode("\r\n", $headersRaw) as $hline) {
            if (stripos($hline, 'Retry-After:') === 0) {
                $v = trim(substr($hline, strlen('Retry-After:')));
                if ($v !== '' && ctype_digit($v)) {
                    $retryAfter = intval($v);
                }
                break;
            }
        }
    }

    $cutoff = $now - $CB_WINDOW_SEC;

    if ($errNo) {
        $state['error_times'][] = $now;
        $state['error_times'] = array_values(array_filter($state['error_times'], function($t) use ($cutoff) { return intval($t) >= $cutoff; }));
        if (count($state['error_times']) >= $CB_MAX_ERRORS) {
            $state['cooldown_until'] = $now + $CB_COOLDOWN_SEC;
        }

        if ($fh) {
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($state));
            fflush($fh);
            @flock($fh, LOCK_UN);
            @fclose($fh);
        }

        write_json_file_atomic($cacheErrPath, [
            'http_status' => 502,
            'retry_after' => null,
            'payload' => [
                'status'  => 'error',
                'message' => 'cURL error ' . $errNo
            ]
        ]);

        http_json(502, [ 'status' => 'error', 'message' => 'cURL error ' . $errNo ]);
    }

    if ($httpStatus < 200 || $httpStatus >= 300) {
        $code = $httpStatus ?: 502;

        $state['error_times'][] = $now;
        $state['error_times'] = array_values(array_filter($state['error_times'], function($t) use ($cutoff) { return intval($t) >= $cutoff; }));

        if ($code == 429 || $code >= 500) {
            if (count($state['error_times']) >= $CB_MAX_ERRORS) {
                $state['cooldown_until'] = $now + $CB_COOLDOWN_SEC;
            }
        }

        if ($fh) {
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($state));
            fflush($fh);
            @flock($fh, LOCK_UN);
            @fclose($fh);
        }

        write_json_file_atomic($cacheErrPath, [
            'http_status' => $code,
            'retry_after' => $retryAfter,
            'payload' => [
                'status'  => 'error',
                'message' => 'SimTraffic returned HTTP ' . $code
            ]
        ]);

        http_json($code, [ 'status' => 'error', 'message' => 'SimTraffic returned HTTP ' . $code ], $retryAfter);
    }

    $tmp = json_decode($body, true);
    if ($tmp === null && json_last_error() !== JSON_ERROR_NONE) {
        $state['error_times'][] = $now;
        $state['error_times'] = array_values(array_filter($state['error_times'], function($t) use ($cutoff) { return intval($t) >= $cutoff; }));
        if (count($state['error_times']) >= $CB_MAX_ERRORS) {
            $state['cooldown_until'] = $now + $CB_COOLDOWN_SEC;
        }

        if ($fh) {
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($state));
            fflush($fh);
            @flock($fh, LOCK_UN);
            @fclose($fh);
        }

        write_json_file_atomic($cacheErrPath, [
            'http_status' => 502,
            'retry_after' => null,
            'payload' => [
                'status'  => 'error',
                'message' => 'Invalid JSON from SimTraffic'
            ]
        ]);

        http_json(502, [ 'status' => 'error', 'message' => 'Invalid JSON from SimTraffic' ]);
    }

    $data = is_array($tmp) ? $tmp : [];

    // Success: persist state (prune window), clear error cache, cache response
    if ($fh) {
        $state['error_times'] = array_values(array_filter($state['error_times'], function($t) use ($cutoff) { return intval($t) >= $cutoff; }));
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($state));
        fflush($fh);
        @flock($fh, LOCK_UN);
        @fclose($fh);
    }

    @unlink($cacheErrPath);
    @file_put_contents($cacheOkPath, json_encode($data));
} else {
    // Cached: still persist a pruned state (tidy)
    if ($fh) {
        $cutoff = $now - $CB_WINDOW_SEC;
        $state['error_times'] = array_values(array_filter($state['error_times'], function($t) use ($cutoff) { return intval($t) >= $cutoff; }));
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($state));
        fflush($fh);
        @flock($fh, LOCK_UN);
        @fclose($fh);
    }
}

// -------------------------------
// ADL mapping (optional)
// -------------------------------

function st_get($obj, $key, $default = null) {
    if (!is_array($obj)) return $default;
    return array_key_exists($key, $obj) ? $obj[$key] : $default;
}

function st_parse_utc($v) {
    if ($v === null) return null;
    if (is_array($v)) {
        // common patterns
        foreach (['utc', 'time', 'timestamp', 'value'] as $k) {
            if (array_key_exists($k, $v)) {
                return st_parse_utc($v[$k]);
            }
        }
        return null;
    }

    if (is_int($v) || is_float($v) || (is_string($v) && preg_match('/^-?\d+(\.\d+)?$/', $v))) {
        $num = floatval($v);
        if ($num <= 0) return null;
        // ms vs seconds
        if ($num > 20000000000) { // > ~year 2600 in seconds, so treat as ms
            $num = $num / 1000.0;
        }
        $sec = intval(floor($num));
        try {
            return (new DateTimeImmutable('@' . $sec))->setTimezone(new DateTimeZone('UTC'));
        } catch (Exception $e) {
            return null;
        }
    }

    $s = trim(strval($v));
    if ($s === '') return null;

    try {
        // If no TZ info, interpret as UTC
        return new DateTimeImmutable($s, new DateTimeZone('UTC'));
    } catch (Exception $e) {
        // fallback
        $t = strtotime($s);
        if ($t === false) return null;
        return (new DateTimeImmutable('@' . intval($t)))->setTimezone(new DateTimeZone('UTC'));
    }
}

function dt_epoch($v) {
    if ($v === null) return null;
    if ($v instanceof DateTimeInterface) return $v->getTimestamp();
    if (is_string($v)) {
        $t = strtotime($v);
        return ($t === false) ? null : intval($t);
    }
    return null;
}

function dt_to_sql($dt) {
    if (!($dt instanceof DateTimeInterface)) return null;
    return $dt->format('Y-m-d H:i:s');
}

function adl_time_equals($oldVal, $newDt) {
    if ($newDt === null) return true; // nothing new
    $o = dt_epoch($oldVal);
    $n = dt_epoch($newDt);
    if ($o === null && $n === null) return true;
    if ($o === null || $n === null) return false;
    return abs($o - $n) <= 30; // seconds tolerance
}

function adl_table_exists($conn, $schema, $table) {
    $sql = "SELECT 1 AS ok FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?";
    $stmt = @sqlsrv_query($conn, $sql, [$schema, $table]);
    if ($stmt === false) return false;
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    return $row && isset($row['ok']);
}

function adl_table_cols($conn, $schema, $table) {
    $cols = [];
    $sql = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?";
    $stmt = @sqlsrv_query($conn, $sql, [$schema, $table]);
    if ($stmt === false) return $cols;
    while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        if (isset($r['COLUMN_NAME'])) {
            $cols[strtolower($r['COLUMN_NAME'])] = true;
        }
    }
    return $cols;
}

function adl_fetch_row_by_callsign($conn, $fullTable, $callsign) {
    // adl_flights and adl_flights_gs both have is_active/last_seen_utc/id
    $sql = "SELECT TOP (1) * FROM {$fullTable} WHERE callsign = ? ORDER BY is_active DESC, last_seen_utc DESC, id DESC";
    $stmt = @sqlsrv_query($conn, $sql, [$callsign]);
    if ($stmt === false) return null;
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function adl_apply_simtraffic_row($row, $cols, $data) {
    $dep = st_get($data, 'departure', []);
    $arr = st_get($data, 'arrival', []);

    // Support alternate nesting
    if (!is_array($dep)) $dep = [];
    if (!is_array($arr)) $arr = [];

    // Departure
    $pushDt     = st_parse_utc(st_get($dep, 'push_time', st_get($data, 'push_time')));
    $taxiDt     = st_parse_utc(st_get($dep, 'taxi_time', st_get($data, 'taxi_time')));
    $seqDt      = st_parse_utc(st_get($dep, 'sequence_time', st_get($data, 'sequence_time')));
    $hsDt       = st_parse_utc(st_get($dep, 'holdshort_time', st_get($data, 'holdshort_time')));
    $runwayDt   = st_parse_utc(st_get($dep, 'runway_time', st_get($data, 'runway_time')));
    $takeoffDt  = st_parse_utc(st_get($dep, 'takeoff_time', st_get($data, 'takeoff_time')));
    $edctDt     = st_parse_utc(st_get($dep, 'edct', st_get($data, 'edct')));

    // Arrival
    $etaDt      = st_parse_utc(st_get($arr, 'eta', st_get($data, 'eta')));
    $arrived    = st_get($arr, 'arrived', st_get($data, 'arrived', false)) ? true : false;

    $etaMfDt    = st_parse_utc(st_get($arr, 'eta_mf', st_get($arr, 'etaMF')));
    $mftDt      = st_parse_utc(st_get($arr, 'mft', st_get($arr, 'MFT')));

    $etaVtDt    = st_parse_utc(st_get($arr, 'eta_vt', st_get($arr, 'eta_vertex', st_get($arr, 'eta_vertex_time'))));
    $vtDt       = st_parse_utc(st_get($arr, 'vt', st_get($arr, 'vertex_time', st_get($arr, 'vertex_time_utc'))));

    // Best ETD source order
    $bestEtdDt = null;
    if ($takeoffDt) $bestEtdDt = $takeoffDt;
    else if ($edctDt) $bestEtdDt = $edctDt;
    else if ($taxiDt) $bestEtdDt = $taxiDt;
    else if ($pushDt) $bestEtdDt = $pushDt;

    $updates = [];
    $flags = [
        'etd_changed' => false,
        'edct_changed' => false,
        'eta_changed' => false
    ];

    $col = function($name) use ($cols) {
        return isset($cols[strtolower($name)]);
    };

    // Push/taxi/takeoff/EDCT mapping to ADL
    if ($pushDt) {
        foreach (['out_utc','lgtd_utc','igtd_utc'] as $c) {
            if ($col($c) && !adl_time_equals(st_get($row, $c), $pushDt)) {
                $updates[$c] = dt_to_sql($pushDt);
            }
        }
    }

    if ($seqDt && $col('sequence_time_utc') && !adl_time_equals(st_get($row, 'sequence_time_utc'), $seqDt)) {
        $updates['sequence_time_utc'] = dt_to_sql($seqDt);
    }
    if ($hsDt && $col('holdshort_time_utc') && !adl_time_equals(st_get($row, 'holdshort_time_utc'), $hsDt)) {
        $updates['holdshort_time_utc'] = dt_to_sql($hsDt);
    }
    if ($runwayDt && $col('runway_time_utc') && !adl_time_equals(st_get($row, 'runway_time_utc'), $runwayDt)) {
        $updates['runway_time_utc'] = dt_to_sql($runwayDt);
    }

    if ($takeoffDt) {
        foreach (['etd_runway_utc','off_utc','lrtd_utc','artd_utc','oetd_utc','betd_utc'] as $c) {
            if ($col($c) && !adl_time_equals(st_get($row, $c), $takeoffDt)) {
                $updates[$c] = dt_to_sql($takeoffDt);
            }
        }
    }

    if ($edctDt) {
        // etd_runway_utc set via $bestEtdDt below, but we still force these fields
        foreach (['pgtd_utc','betd_utc','octd_utc','ctd_utc'] as $c) {
            if ($col($c) && !adl_time_equals(st_get($row, $c), $edctDt)) {
                $updates[$c] = dt_to_sql($edctDt);
                if ($c === 'ctd_utc') $flags['edct_changed'] = true;
            }
        }
    }

    if ($bestEtdDt && $col('etd_runway_utc') && !adl_time_equals(st_get($row, 'etd_runway_utc'), $bestEtdDt)) {
        $updates['etd_runway_utc'] = dt_to_sql($bestEtdDt);
        $flags['etd_changed'] = true;
    }

    // ETD prefix + phase
    if ($col('etd_prefix')) {
        $desiredEtdPrefix = null;
        if ($takeoffDt) $desiredEtdPrefix = 'A';
        else if ($edctDt) $desiredEtdPrefix = 'P';
        else if ($pushDt || $taxiDt) $desiredEtdPrefix = 'T';

        if ($desiredEtdPrefix !== null) {
            $cur = st_get($row, 'etd_prefix');
            if ($cur !== $desiredEtdPrefix) {
                $updates['etd_prefix'] = $desiredEtdPrefix;
            }
        }
    }

    if ($col('phase')) {
        $desiredPhase = null;
        if ($arrived && $etaDt) $desiredPhase = 'arrived';
        else if ($takeoffDt) $desiredPhase = 'enroute';
        else if ($pushDt || $taxiDt) $desiredPhase = 'taxiing';

        if ($desiredPhase !== null) {
            $cur = st_get($row, 'phase');
            if ($cur !== $desiredPhase) {
                $updates['phase'] = $desiredPhase;
            }
        }
    }

    // ETA mapping
    if ($etaDt) {
        // estimated_* convenience
        if ($bestEtdDt && $col('estimated_dep_utc') && !adl_time_equals(st_get($row, 'estimated_dep_utc'), $bestEtdDt)) {
            $updates['estimated_dep_utc'] = dt_to_sql($bestEtdDt);
        }
        if ($col('estimated_arr_utc') && !adl_time_equals(st_get($row, 'estimated_arr_utc'), $etaDt)) {
            $updates['estimated_arr_utc'] = dt_to_sql($etaDt);
        }
        if ($col('eta_source')) {
            $cur = st_get($row, 'eta_source');
            if ($cur !== 'simtraffic') {
                $updates['eta_source'] = 'simtraffic';
            }
        }

        if ($arrived) {
            // SimTraffic may have an explicit on-time; prefer it if present
            $onDt = st_parse_utc(st_get($arr, 'on_time', st_get($arr, 'on_utc')));
            if (!$onDt) $onDt = $etaDt;

            foreach (['eta_runway_utc','on_utc','sgta_utc','igta_utc','pgta_utc','lrta_utc','lgta_utc'] as $c) {
                if ($col($c) && !adl_time_equals(st_get($row, $c), $onDt)) {
                    $updates[$c] = dt_to_sql($onDt);
                }
            }

            if ($col('eta_prefix')) {
                $cur = st_get($row, 'eta_prefix');
                if ($cur !== 'A') $updates['eta_prefix'] = 'A';
            }

            $flags['eta_changed'] = true;
        } else {
            $etaCols = ['eta_runway_utc','sgta_utc','igta_utc','pgta_utc','lrta_utc','lgta_utc','erta_utc','oeta_utc'];
            foreach ($etaCols as $c) {
                if ($col($c) && !adl_time_equals(st_get($row, $c), $etaDt)) {
                    $updates[$c] = dt_to_sql($etaDt);
                }
            }

            // If EDCT exists, SimTraffic ETA also becomes CTA
            if ($edctDt && $col('cta_utc') && !adl_time_equals(st_get($row, 'cta_utc'), $etaDt)) {
                $updates['cta_utc'] = dt_to_sql($etaDt);
            }

            if ($col('eta_prefix')) {
                $desiredEtaPrefix = $edctDt ? 'C' : 'E';
                $cur = st_get($row, 'eta_prefix');
                if ($cur !== $desiredEtaPrefix) $updates['eta_prefix'] = $desiredEtaPrefix;
            }

            $flags['eta_changed'] = true;
        }
    }

    // mft/eta_mf -> tma_rt_utc
    $tmaDt = $etaMfDt ? $etaMfDt : $mftDt;
    if ($tmaDt && $col('tma_rt_utc') && !adl_time_equals(st_get($row, 'tma_rt_utc'), $tmaDt)) {
        $updates['tma_rt_utc'] = dt_to_sql($tmaDt);
    }

    // vertex times
    if ($etaVtDt && $col('eta_vt_utc') && !adl_time_equals(st_get($row, 'eta_vt_utc'), $etaVtDt)) {
        $updates['eta_vt_utc'] = dt_to_sql($etaVtDt);
    }
    if ($vtDt && $col('vt_utc') && !adl_time_equals(st_get($row, 'vt_utc'), $vtDt)) {
        $updates['vt_utc'] = dt_to_sql($vtDt);
    }

    // If ETD changed and SimTraffic provides ETA, ensure ETA updates are included
    if ($flags['etd_changed'] && $etaDt) {
        // (already handled above; this flag is kept for diagnostics)
    }

    return [ $updates, $flags ];
}

function adl_update_table($conn, $schema, $table, $callsign, $data) {
    $full = $schema . '.' . $table;

    $info = [
        'table' => $full,
        'found' => false,
        'changed' => false,
        'rows_affected' => 0,
        'updates' => [],
        'flags' => []
    ];

    $row = adl_fetch_row_by_callsign($conn, $full, $callsign);
    if (!$row) return $info;

    $info['found'] = true;

    $cols = adl_table_cols($conn, $schema, $table);
    list($updates, $flags) = adl_apply_simtraffic_row($row, $cols, $data);

    $info['updates'] = array_keys($updates);
    $info['flags'] = $flags;

    if (!$updates) return $info;

    // Build UPDATE by id
    if (!array_key_exists('id', $row)) return $info;
    $id = $row['id'];

    $sets = [];
    $params = [];
    foreach ($updates as $colName => $val) {
        $sets[] = $colName . ' = ?';
        $params[] = $val;
    }
    $params[] = $id;

    $sql = 'UPDATE ' . $full . ' SET ' . implode(', ', $sets) . ' WHERE id = ?';
    $stmt = @sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        return $info;
    }

    $ra = sqlsrv_rows_affected($stmt);
    if ($ra === false) $ra = 0;

    $info['rows_affected'] = $ra;
    $info['changed'] = ($ra > 0);
    return $info;
}

function adl_apply_simtraffic($callsign, $data, $target) {
    $out = [
        'applied' => false,
        'target' => $target,
        'tables' => [],
        'error' => null
    ];

    // Load ADL constants
    $cfgPath = __DIR__ . '/../../load/config.php';
    if (is_file($cfgPath)) {
        require_once($cfgPath);
    }

    if (!defined('ADL_SQL_HOST') || !defined('ADL_SQL_DATABASE') || !defined('ADL_SQL_USERNAME') || !defined('ADL_SQL_PASSWORD')) {
        $out['error'] = 'ADL_SQL_* constants not defined; skipping ADL update';
        return $out;
    }

    if (!function_exists('sqlsrv_connect')) {
        $out['error'] = 'sqlsrv extension not available; skipping ADL update';
        return $out;
    }

    $conn = @sqlsrv_connect(ADL_SQL_HOST, [
        'Database' => ADL_SQL_DATABASE,
        'UID' => ADL_SQL_USERNAME,
        'PWD' => ADL_SQL_PASSWORD
    ]);
    if ($conn === false) {
        $out['error'] = 'Failed to connect to ADL SQL';
        return $out;
    }

    $schema = 'dbo';

    $hasAdl = adl_table_exists($conn, $schema, 'adl_flights');
    $hasGs  = adl_table_exists($conn, $schema, 'adl_flights_gs');

    $tablesToTry = [];
    if ($target === 'adl') {
        if ($hasAdl) $tablesToTry[] = 'adl_flights';
    } else if ($target === 'gs') {
        if ($hasGs) $tablesToTry[] = 'adl_flights_gs';
    } else if ($target === 'both') {
        if ($hasGs) $tablesToTry[] = 'adl_flights_gs';
        if ($hasAdl) $tablesToTry[] = 'adl_flights';
    } else {
        // gs_then_adl
        if ($hasGs) $tablesToTry[] = 'adl_flights_gs';
        if ($hasAdl) $tablesToTry[] = 'adl_flights';
    }

    $results = [];
    $updatedAny = false;
    $foundAny = false;

    foreach ($tablesToTry as $t) {
        $r = adl_update_table($conn, $schema, $t, $callsign, $data);
        $results[] = $r;
        if ($r['found']) $foundAny = true;
        if ($r['changed']) $updatedAny = true;

        if ($target === 'gs_then_adl' && $t === 'adl_flights_gs' && $r['found']) {
            // Found in GS table; don't fall through to ADL in this mode
            break;
        }
    }

    @sqlsrv_close($conn);

    $out['applied'] = true;
    $out['found_any'] = $foundAny;
    $out['updated_any'] = $updatedAny;
    $out['tables'] = $results;
    return $out;
}

$adlInfo = null;
if ($applyAdl) {
    $adlInfo = adl_apply_simtraffic($cs, $data, $target);
}

// Metadata for debugging (safe for JS consumers; extra keys ignored)
$data['__proxy'] = [
    'cached' => $cached,
    'cache_age_sec' => $cacheAge,
    'target' => $target,
    'apply_adl' => $applyAdl
];
if ($adlInfo !== null) {
    $data['__adl'] = $adlInfo;
}

echo json_encode($data);
