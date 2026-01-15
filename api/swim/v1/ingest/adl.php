<?php
/**
 * VATSIM SWIM API v1 - ADL Ingest Endpoint
 * 
 * Receives ADL data from authoritative sources and updates the flight cache.
 * 
 * @version 1.0.0
 */

require_once __DIR__ . '/../auth.php';

$auth = swim_init_auth(true, true);

if (!$auth->canWriteField('adl')) {
    SwimResponse::error(
        'Source "' . $auth->getSourceId() . '" is not authorized to write ADL data.',
        403, 'NOT_AUTHORITATIVE'
    );
}

$body = swim_get_json_body();
if (!$body) {
    SwimResponse::error('Request body is required', 400, 'MISSING_BODY');
}

if (!isset($body['flights']) || !is_array($body['flights'])) {
    SwimResponse::error('Request must contain a "flights" array', 400, 'MISSING_FLIGHTS');
}

$flights = $body['flights'];
$max_batch = 500;

if (count($flights) > $max_batch) {
    SwimResponse::error("Batch size exceeded. Maximum {$max_batch} flights per request.", 400, 'BATCH_TOO_LARGE');
}

$processed = 0;
$errors = [];
$created = 0;
$updated = 0;

foreach ($flights as $index => $flight) {
    try {
        $result = processFlightUpdate($flight, $auth->getSourceId());
        if ($result['status'] === 'created') $created++;
        elseif ($result['status'] === 'updated') $updated++;
        $processed++;
    } catch (Exception $e) {
        $errors[] = [
            'index' => $index,
            'callsign' => $flight['callsign'] ?? 'unknown',
            'error' => $e->getMessage()
        ];
    }
}

SwimResponse::success([
    'processed' => $processed,
    'created' => $created,
    'updated' => $updated,
    'errors' => count($errors),
    'error_details' => array_slice($errors, 0, 10)
], ['source' => $auth->getSourceId(), 'batch_size' => count($flights)]);

function processFlightUpdate($flight, $source) {
    global $conn_adl;
    
    $required = ['callsign', 'dept_icao', 'dest_icao'];
    foreach ($required as $field) {
        if (empty($flight[$field])) {
            throw new Exception("Missing required field: {$field}");
        }
    }
    
    $callsign = strtoupper(trim($flight['callsign']));
    $dept_icao = strtoupper(trim($flight['dept_icao']));
    $dest_icao = strtoupper(trim($flight['dest_icao']));
    $gufi = swim_generate_gufi($callsign, $dept_icao, $dest_icao);
    
    $check_stmt = sqlsrv_query($conn_adl, "SELECT id, version FROM dbo.swim_flight_cache WHERE gufi = ?", [$gufi]);
    if ($check_stmt === false) {
        throw new Exception('Database error');
    }
    
    $existing = sqlsrv_fetch_array($check_stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($check_stmt);
    
    $unified = buildUnifiedRecord($flight);
    $unified_json = json_encode($unified, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
    if ($existing) {
        $stmt = sqlsrv_query($conn_adl,
            "UPDATE dbo.swim_flight_cache SET unified_record = ?, adl_updated_at = GETUTCDATE(), 
             updated_at = GETUTCDATE(), version = version + 1 WHERE id = ?",
            [$unified_json, $existing['id']]);
        if ($stmt === false) throw new Exception('Failed to update');
        sqlsrv_free_stmt($stmt);
        return ['status' => 'updated', 'gufi' => $gufi];
    } else {
        $flight_key = $flight['flight_key'] ?? sprintf('%s_%s_%s_%s', $callsign, $dept_icao, $dest_icao, gmdate('Ymd'));
        $status = $flight['status'] ?? 'active';
        
        $stmt = sqlsrv_query($conn_adl,
            "INSERT INTO dbo.swim_flight_cache (gufi, flight_key, callsign, dept_icao, dest_icao, status, 
             unified_record, adl_updated_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, GETUTCDATE(), GETUTCDATE(), GETUTCDATE())",
            [$gufi, $flight_key, $callsign, $dept_icao, $dest_icao, $status, $unified_json]);
        if ($stmt === false) throw new Exception('Failed to create');
        sqlsrv_free_stmt($stmt);
        return ['status' => 'created', 'gufi' => $gufi];
    }
}

function buildUnifiedRecord($flight) {
    $record = [
        'identity' => [
            'callsign' => strtoupper($flight['callsign']),
            'cid' => $flight['cid'] ?? null,
            'aircraft_type' => $flight['aircraft_type'] ?? null
        ],
        'flight_plan' => [
            'departure' => strtoupper($flight['dept_icao']),
            'destination' => strtoupper($flight['dest_icao']),
            'route' => $flight['route'] ?? null
        ],
        'adl' => $flight['adl'] ?? [
            'phase' => $flight['current_phase'] ?? 'PROPOSED',
            'is_active' => $flight['is_active'] ?? true
        ],
        '_source' => 'vatcscc',
        '_updated' => gmdate('c')
    ];
    
    if (isset($flight['tmi'])) {
        $record['tmi'] = $flight['tmi'];
    }
    
    return $record;
}
