<?php
/**
 * VATSIM SWIM API v1 - ADL Ingest Endpoint
 * 
 * Receives flight data from authoritative sources and updates swim_flights in SWIM_API.
 * This endpoint allows external systems to push flight updates.
 * 
 * Uses SWIM_API database exclusively (not VATSIM_ADL).
 * 
 * @version 3.0.0 - SWIM_API database only
 */

require_once __DIR__ . '/../auth.php';

// Use SWIM_API database exclusively
global $conn_swim;

if (!$conn_swim) {
    SwimResponse::error('SWIM database connection not available', 503, 'SERVICE_UNAVAILABLE');
}

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
        $result = processFlightUpdate($flight, $auth->getSourceId(), $conn_swim);
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


function processFlightUpdate($flight, $source, $conn) {
    // Validate required fields
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
    
    // Check if flight exists in swim_flights
    $check_sql = "SELECT flight_uid FROM dbo.swim_flights WHERE gufi = ?";
    $check_stmt = sqlsrv_query($conn, $check_sql, [$gufi]);
    
    if ($check_stmt === false) {
        $errors = sqlsrv_errors();
        $msg = $errors[0]['message'] ?? 'Unknown database error';
        error_log('SWIM Ingest: Check query failed - ' . $msg);
        throw new Exception('Database error: ' . $msg);
    }
    
    $existing = sqlsrv_fetch_array($check_stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($check_stmt);
    
    if ($existing) {
        // Update existing flight
        $update_fields = [];
        $update_params = [];
        
        // Build dynamic update based on provided fields
        $field_map = [
            'aircraft_type' => 'aircraft_type',
            'aircraft_icao' => 'aircraft_icao',
            'cid' => 'cid',
            'route' => 'fp_route',
            'cruise_altitude' => 'fp_altitude_ft',
            'cruise_speed' => 'fp_tas_kts',
            'alternate' => 'fp_alt_icao',
            'phase' => 'phase',
            'is_active' => 'is_active',
            'latitude' => 'lat',
            'longitude' => 'lon',
            'altitude' => 'altitude_ft',
            'heading' => 'heading_deg',
            'ground_speed' => 'groundspeed_kts'
        ];
        
        foreach ($field_map as $input_field => $db_field) {
            if (isset($flight[$input_field])) {
                $update_fields[] = "$db_field = ?";
                $update_params[] = $flight[$input_field];
            }
        }
        
        // Handle TMI fields
        if (isset($flight['tmi'])) {
            $tmi = $flight['tmi'];
            if (isset($tmi['ctl_type'])) {
                $update_fields[] = 'ctl_type = ?';
                $update_params[] = $tmi['ctl_type'];
            }
            if (isset($tmi['gs_held'])) {
                $update_fields[] = 'gs_held = ?';
                $update_params[] = $tmi['gs_held'] ? 1 : 0;
            }
            if (isset($tmi['slot_time_utc'])) {
                $update_fields[] = 'slot_time_utc = ?';
                $update_params[] = $tmi['slot_time_utc'];
            }
            if (isset($tmi['delay_minutes'])) {
                $update_fields[] = 'delay_minutes = ?';
                $update_params[] = $tmi['delay_minutes'];
            }
            if (isset($tmi['program_id'])) {
                $update_fields[] = 'program_id = ?';
                $update_params[] = $tmi['program_id'];
            }
        }
        
        // Always update sync time
        $update_fields[] = 'last_sync_utc = GETUTCDATE()';
        $update_fields[] = 'last_seen_utc = GETUTCDATE()';
        
        if (!empty($update_fields)) {
            $update_params[] = $existing['flight_uid'];
            $update_sql = "UPDATE dbo.swim_flights SET " . implode(', ', $update_fields) . " WHERE flight_uid = ?";
            
            $stmt = sqlsrv_query($conn, $update_sql, $update_params);
            if ($stmt === false) {
                $errors = sqlsrv_errors();
                throw new Exception('Failed to update: ' . ($errors[0]['message'] ?? 'Unknown'));
            }
            sqlsrv_free_stmt($stmt);
        }
        
        return ['status' => 'updated', 'gufi' => $gufi, 'flight_uid' => $existing['flight_uid']];
        
    } else {
        // Insert new flight
        $flight_key = $flight['flight_key'] ?? sprintf('%s|%s|%s|%s', $callsign, $dept_icao, $dest_icao, gmdate('Ymd'));
        
        $insert_sql = "
            INSERT INTO dbo.swim_flights (
                gufi, flight_key, callsign, cid,
                fp_dept_icao, fp_dest_icao, fp_alt_icao,
                fp_altitude_ft, fp_tas_kts, fp_route,
                aircraft_type, aircraft_icao,
                phase, is_active,
                lat, lon, altitude_ft, heading_deg, groundspeed_kts,
                first_seen_utc, last_seen_utc, last_sync_utc
            ) VALUES (
                ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?,
                ?, ?,
                ?, ?,
                ?, ?, ?, ?, ?,
                GETUTCDATE(), GETUTCDATE(), GETUTCDATE()
            )
        ";
        
        $insert_params = [
            $gufi,
            $flight_key,
            $callsign,
            $flight['cid'] ?? null,
            $dept_icao,
            $dest_icao,
            isset($flight['alternate']) ? strtoupper(trim($flight['alternate'])) : null,
            $flight['cruise_altitude'] ?? $flight['fp_altitude_ft'] ?? null,
            $flight['cruise_speed'] ?? $flight['fp_tas_kts'] ?? null,
            $flight['route'] ?? $flight['fp_route'] ?? null,
            $flight['aircraft_type'] ?? null,
            $flight['aircraft_icao'] ?? null,
            $flight['phase'] ?? 'prefile',
            isset($flight['is_active']) ? ($flight['is_active'] ? 1 : 0) : 1,
            $flight['latitude'] ?? $flight['lat'] ?? null,
            $flight['longitude'] ?? $flight['lon'] ?? null,
            $flight['altitude'] ?? $flight['altitude_ft'] ?? null,
            $flight['heading'] ?? $flight['heading_deg'] ?? null,
            $flight['ground_speed'] ?? $flight['groundspeed_kts'] ?? null
        ];
        
        $stmt = sqlsrv_query($conn, $insert_sql, $insert_params);
        if ($stmt === false) {
            $errors = sqlsrv_errors();
            throw new Exception('Failed to create: ' . ($errors[0]['message'] ?? 'Unknown'));
        }
        sqlsrv_free_stmt($stmt);
        
        return ['status' => 'created', 'gufi' => $gufi];
    }
}
