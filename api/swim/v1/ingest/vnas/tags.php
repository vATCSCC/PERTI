<?php
/**
 * VATSWIM API v1 - vNAS Automation Tags Ingest Endpoint
 *
 * Receives automation tag data from vNAS (ERAM/STARS) systems.
 * Updates assigned altitudes, speeds, headings, scratchpads, and coordination status.
 *
 * @version 1.0.0
 * @since 2026-01-27
 *
 * Expected payload:
 * {
 *   "facility_id": "ZDC",           // Required - Source facility
 *   "system_type": "ERAM",          // Required - ERAM or STARS
 *   "tags": [
 *     {
 *       "callsign": "UAL123",                // Required
 *       "gufi": "VAT-20260127-...",          // Optional - Direct GUFI lookup
 *       "assigned_altitude": 35000,          // Optional - Assigned altitude (ft)
 *       "interim_altitude": 28000,           // Optional - Interim altitude (ERAM)
 *       "assigned_speed": 280,               // Optional - Assigned IAS (kts)
 *       "assigned_mach": 0.82,               // Optional - Assigned Mach
 *       "assigned_heading": 270,             // Optional - Assigned heading (magnetic)
 *       "scratchpad": "KJFK/31L",            // Optional - Primary scratchpad
 *       "scratchpad2": "GDP+15",             // Optional - Secondary (ERAM)
 *       "scratchpad3": "",                   // Optional - Tertiary (ERAM)
 *       "point_out_sector": "33",            // Optional - Point-out target
 *       "coordination_status": "TRACKED",    // Optional - UNTRACKED/TRACKED/ASSOCIATED/SUSPENDED
 *       "datablock_full": true,              // Optional - Full datablock displayed
 *       "conflict_alert": false,             // Optional - CA active
 *       "msaw_alert": false,                 // Optional - MSAW active
 *       "ca_alert": false,                   // Optional - Conflict alert active
 *       "timestamp": "2026-01-27T15:30:00Z"  // Optional - Tag update time
 *     }
 *   ]
 * }
 */

require_once __DIR__ . '/../../auth.php';

// Use SWIM_API database for all operations
global $conn_swim;

if (!$conn_swim) {
    SwimResponse::error('SWIM database connection not available', 503, 'SERVICE_UNAVAILABLE');
}

// Require authentication with write access
$auth = swim_init_auth(true, true);

// Validate source can write track data (tags are part of track authority)
if (!$auth->canWriteField('track')) {
    SwimResponse::error(
        'Source "' . $auth->getSourceId() . '" is not authorized to write automation tags.',
        403,
        'NOT_AUTHORITATIVE'
    );
}

// Get request body
$body = swim_get_json_body();
if (!$body) {
    SwimResponse::error('Request body is required', 400, 'MISSING_BODY');
}

// Validate facility context
$facility_id = strtoupper(trim($body['facility_id'] ?? ''));
$system_type = strtoupper(trim($body['system_type'] ?? ''));

if (empty($facility_id)) {
    SwimResponse::error('facility_id is required', 400, 'MISSING_FACILITY');
}

if (!empty($system_type) && !in_array($system_type, ['ERAM', 'STARS'])) {
    SwimResponse::error('system_type must be ERAM or STARS', 400, 'INVALID_SYSTEM_TYPE');
}

if (!isset($body['tags']) || !is_array($body['tags'])) {
    SwimResponse::error('Request must contain a "tags" array', 400, 'MISSING_TAGS');
}

$tags = $body['tags'];
$max_batch = 500;

if (count($tags) > $max_batch) {
    SwimResponse::error("Batch size exceeded. Maximum {$max_batch} tags per request.", 400, 'BATCH_TOO_LARGE');
}

$processed = 0;
$updated = 0;
$not_found = 0;
$errors = [];

foreach ($tags as $index => $tag) {
    try {
        $result = processVnasTagUpdate($tag, $facility_id, $system_type, $auth->getSourceId(), $conn_swim);
        if ($result['status'] === 'updated') {
            $updated++;
        } elseif ($result['status'] === 'not_found') {
            $not_found++;
        }
        $processed++;
    } catch (Exception $e) {
        $errors[] = [
            'index' => $index,
            'callsign' => $tag['callsign'] ?? 'unknown',
            'error' => $e->getMessage()
        ];
    }
}

SwimResponse::success([
    'processed' => $processed,
    'updated' => $updated,
    'not_found' => $not_found,
    'errors' => count($errors),
    'error_details' => array_slice($errors, 0, 10)
], [
    'source' => 'vnas',
    'facility' => $facility_id,
    'system' => $system_type,
    'batch_size' => count($tags)
]);

/**
 * Process a single vNAS automation tag update
 */
function processVnasTagUpdate($tag, $facility_id, $system_type, $source, $conn) {
    // Validate required fields
    if (empty($tag['callsign'])) {
        throw new Exception('Missing required field: callsign');
    }

    $callsign = strtoupper(trim($tag['callsign']));
    $gufi = $tag['gufi'] ?? null;

    // Look up flight - try GUFI first, then callsign
    $existing = null;

    if (!empty($gufi)) {
        $check_sql = "SELECT TOP 1 flight_uid, gufi
                      FROM dbo.swim_flights
                      WHERE gufi = ? AND is_active = 1";
        $check_stmt = sqlsrv_query($conn, $check_sql, [$gufi]);
        if ($check_stmt !== false) {
            $existing = sqlsrv_fetch_array($check_stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($check_stmt);
        }
    }

    if (!$existing) {
        $check_sql = "SELECT TOP 1 flight_uid, gufi
                      FROM dbo.swim_flights
                      WHERE callsign = ? AND is_active = 1
                      ORDER BY last_seen_utc DESC";
        $check_stmt = sqlsrv_query($conn, $check_sql, [$callsign]);
        if ($check_stmt === false) {
            throw new Exception('Database error looking up flight');
        }
        $existing = sqlsrv_fetch_array($check_stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($check_stmt);
    }

    if (!$existing) {
        return ['status' => 'not_found', 'callsign' => $callsign];
    }

    // Build dynamic UPDATE with only provided fields
    $set_clauses = [
        'last_sync_utc = GETUTCDATE()',
        'vnas_sync_utc = GETUTCDATE()'
    ];
    $params = [];

    // Assigned values
    if (isset($tag['assigned_altitude'])) {
        $set_clauses[] = 'assigned_altitude_ft = ?';
        $params[] = intval($tag['assigned_altitude']);
    }
    if (isset($tag['interim_altitude'])) {
        $set_clauses[] = 'interim_altitude_ft = ?';
        $params[] = intval($tag['interim_altitude']);
    }
    if (isset($tag['assigned_speed'])) {
        $set_clauses[] = 'assigned_speed_kts = ?';
        $params[] = intval($tag['assigned_speed']);
    }
    if (isset($tag['assigned_mach'])) {
        $set_clauses[] = 'assigned_mach = ?';
        $params[] = floatval($tag['assigned_mach']);
    }
    if (isset($tag['assigned_heading'])) {
        $set_clauses[] = 'assigned_heading_deg = ?';
        $params[] = intval($tag['assigned_heading']) % 360;
    }

    // Scratchpads
    if (isset($tag['scratchpad'])) {
        $set_clauses[] = 'scratchpad = ?';
        $params[] = substr($tag['scratchpad'], 0, 16);
    }
    if (isset($tag['scratchpad2'])) {
        $set_clauses[] = 'scratchpad2 = ?';
        $params[] = substr($tag['scratchpad2'], 0, 16);
    }
    if (isset($tag['scratchpad3'])) {
        $set_clauses[] = 'scratchpad3 = ?';
        $params[] = substr($tag['scratchpad3'], 0, 16);
    }

    // Coordination
    if (isset($tag['point_out_sector'])) {
        $set_clauses[] = 'point_out_sector = ?';
        $params[] = substr($tag['point_out_sector'], 0, 16);
    }
    if (isset($tag['coordination_status'])) {
        $valid_statuses = ['UNTRACKED', 'TRACKED', 'ASSOCIATED', 'SUSPENDED'];
        $status = strtoupper($tag['coordination_status']);
        if (in_array($status, $valid_statuses)) {
            $set_clauses[] = 'coordination_status = ?';
            $params[] = $status;
        }
    }

    // Alert flags
    if (isset($tag['conflict_alert'])) {
        $set_clauses[] = 'conflict_alert = ?';
        $params[] = $tag['conflict_alert'] ? 1 : 0;
    }
    if (isset($tag['msaw_alert'])) {
        $set_clauses[] = 'msaw_alert = ?';
        $params[] = $tag['msaw_alert'] ? 1 : 0;
    }
    if (isset($tag['ca_alert'])) {
        $set_clauses[] = 'ca_alert = ?';
        $params[] = $tag['ca_alert'] ? 1 : 0;
    }

    // vNAS source tracking
    if (!empty($facility_id)) {
        $set_clauses[] = 'vnas_source_facility = ?';
        $params[] = $facility_id;
    }
    if (!empty($system_type)) {
        $set_clauses[] = 'vnas_source_system = ?';
        $params[] = $system_type;
    }

    // Add flight_uid to params
    $params[] = $existing['flight_uid'];

    $update_sql = "UPDATE dbo.swim_flights SET " . implode(', ', $set_clauses) . " WHERE flight_uid = ?";

    $stmt = sqlsrv_query($conn, $update_sql, $params);
    if ($stmt === false) {
        $errors = sqlsrv_errors();
        throw new Exception('Failed to update tags: ' . ($errors[0]['message'] ?? 'Unknown'));
    }
    sqlsrv_free_stmt($stmt);

    return ['status' => 'updated', 'gufi' => $existing['gufi']];
}
