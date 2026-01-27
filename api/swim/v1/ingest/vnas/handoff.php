<?php
/**
 * VATSWIM API v1 - vNAS Handoff Data Ingest Endpoint
 *
 * Receives handoff data from vNAS (ERAM/STARS) systems.
 * Updates handoff state in swim_flights and logs transitions in swim_handoff_log.
 *
 * @version 1.0.0
 * @since 2026-01-27
 *
 * Expected payload:
 * {
 *   "facility_id": "ZDC",           // Required - Source facility
 *   "handoffs": [
 *     {
 *       "callsign": "UAL123",              // Required
 *       "gufi": "VAT-20260127-...",        // Optional - Direct GUFI lookup
 *       "handoff_type": "AUTOMATED",       // Required - AUTOMATED/MANUAL/POINT_OUT
 *       "from_sector": "ZDC_33_CTR",       // Required - Transferring sector
 *       "to_sector": "ZNY_42_CTR",         // Required - Accepting sector
 *       "from_facility": "ZDC",            // Optional - Transferring facility (derived from from_sector)
 *       "to_facility": "ZNY",              // Optional - Accepting facility (derived from to_sector)
 *       "status": "INITIATED",             // Required - INITIATED/ACCEPTED/REJECTED/RECALLED/COMPLETED
 *       "initiated_at": "2026-01-27T15:30:00Z",  // Required
 *       "accepted_at": null,               // Optional - When accepted
 *       "boundary_fix": "SWANN"            // Optional - Fix at sector boundary
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

// Validate source can write track data (handoffs are part of track authority)
if (!$auth->canWriteField('track')) {
    SwimResponse::error(
        'Source "' . $auth->getSourceId() . '" is not authorized to write handoff data.',
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

if (empty($facility_id)) {
    SwimResponse::error('facility_id is required', 400, 'MISSING_FACILITY');
}

if (!isset($body['handoffs']) || !is_array($body['handoffs'])) {
    SwimResponse::error('Request must contain a "handoffs" array', 400, 'MISSING_HANDOFFS');
}

$handoffs = $body['handoffs'];
$max_batch = 200;  // Lower limit for handoff data (less frequent, more complex)

if (count($handoffs) > $max_batch) {
    SwimResponse::error("Batch size exceeded. Maximum {$max_batch} handoffs per request.", 400, 'BATCH_TOO_LARGE');
}

$processed = 0;
$updated = 0;
$logged = 0;
$not_found = 0;
$errors = [];

foreach ($handoffs as $index => $handoff) {
    try {
        $result = processVnasHandoffUpdate($handoff, $facility_id, $auth->getSourceId(), $conn_swim);
        if ($result['status'] === 'updated') {
            $updated++;
            if ($result['logged']) {
                $logged++;
            }
        } elseif ($result['status'] === 'not_found') {
            $not_found++;
        }
        $processed++;
    } catch (Exception $e) {
        $errors[] = [
            'index' => $index,
            'callsign' => $handoff['callsign'] ?? 'unknown',
            'error' => $e->getMessage()
        ];
    }
}

SwimResponse::success([
    'processed' => $processed,
    'updated' => $updated,
    'logged' => $logged,
    'not_found' => $not_found,
    'errors' => count($errors),
    'error_details' => array_slice($errors, 0, 10)
], [
    'source' => 'vnas',
    'facility' => $facility_id,
    'batch_size' => count($handoffs)
]);

/**
 * Process a single vNAS handoff update
 */
function processVnasHandoffUpdate($handoff, $facility_id, $source, $conn) {
    // Validate required fields
    if (empty($handoff['callsign'])) {
        throw new Exception('Missing required field: callsign');
    }
    if (empty($handoff['handoff_type'])) {
        throw new Exception('Missing required field: handoff_type');
    }
    if (empty($handoff['from_sector'])) {
        throw new Exception('Missing required field: from_sector');
    }
    if (empty($handoff['to_sector'])) {
        throw new Exception('Missing required field: to_sector');
    }
    if (empty($handoff['status'])) {
        throw new Exception('Missing required field: status');
    }
    if (empty($handoff['initiated_at'])) {
        throw new Exception('Missing required field: initiated_at');
    }

    $callsign = strtoupper(trim($handoff['callsign']));
    $gufi = $handoff['gufi'] ?? null;

    // Validate handoff_type
    $valid_types = ['AUTOMATED', 'MANUAL', 'POINT_OUT'];
    $handoff_type = strtoupper($handoff['handoff_type']);
    if (!in_array($handoff_type, $valid_types)) {
        throw new Exception('Invalid handoff_type: must be AUTOMATED, MANUAL, or POINT_OUT');
    }

    // Validate status
    $valid_statuses = ['INITIATED', 'ACCEPTED', 'REJECTED', 'RECALLED', 'COMPLETED'];
    $status = strtoupper($handoff['status']);
    if (!in_array($status, $valid_statuses)) {
        throw new Exception('Invalid status: must be INITIATED, ACCEPTED, REJECTED, RECALLED, or COMPLETED');
    }

    // Parse sectors and facilities
    $from_sector = strtoupper(trim($handoff['from_sector']));
    $to_sector = strtoupper(trim($handoff['to_sector']));
    $from_facility = strtoupper(trim($handoff['from_facility'] ?? extractFacilityFromSector($from_sector)));
    $to_facility = strtoupper(trim($handoff['to_facility'] ?? extractFacilityFromSector($to_sector)));
    $boundary_fix = isset($handoff['boundary_fix']) ? strtoupper(trim($handoff['boundary_fix'])) : null;

    // Parse timestamps
    $initiated_at = parseIsoTimestamp($handoff['initiated_at']);
    $accepted_at = isset($handoff['accepted_at']) ? parseIsoTimestamp($handoff['accepted_at']) : null;

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
        return ['status' => 'not_found', 'callsign' => $callsign, 'logged' => false];
    }

    // Update swim_flights with current handoff state
    $set_clauses = [
        'handoff_status = ?',
        'controlling_sector = ?',
        'next_sector = ?',
        'handoff_initiated_utc = ?',
        'last_sync_utc = GETUTCDATE()',
        'vnas_sync_utc = GETUTCDATE()',
        'vnas_source_facility = ?'
    ];
    $params = [
        $status,
        $from_sector,
        $to_sector,
        $initiated_at,
        $facility_id
    ];

    if ($accepted_at !== null) {
        $set_clauses[] = 'handoff_accepted_utc = ?';
        $params[] = $accepted_at;
    }

    if ($boundary_fix !== null) {
        $set_clauses[] = 'boundary_fix = ?';
        $params[] = $boundary_fix;
    }

    // If handoff is accepted or completed, update controlling sector
    if (in_array($status, ['ACCEPTED', 'COMPLETED'])) {
        $set_clauses[] = 'controlling_sector = ?';
        $params[] = $to_sector;
        $set_clauses[] = 'next_sector = NULL';
    }

    // Add flight_uid to params
    $params[] = $existing['flight_uid'];

    $update_sql = "UPDATE dbo.swim_flights SET " . implode(', ', $set_clauses) . " WHERE flight_uid = ?";

    $stmt = sqlsrv_query($conn, $update_sql, $params);
    if ($stmt === false) {
        $errors = sqlsrv_errors();
        throw new Exception('Failed to update handoff state: ' . ($errors[0]['message'] ?? 'Unknown'));
    }
    sqlsrv_free_stmt($stmt);

    // Log handoff to swim_handoff_log
    $logged = false;
    $log_sql = "INSERT INTO dbo.swim_handoff_log
                (flight_uid, gufi, callsign, handoff_type, from_facility, from_sector,
                 to_facility, to_sector, boundary_fix, status, initiated_utc, accepted_utc, source_system)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $log_params = [
        $existing['flight_uid'],
        $existing['gufi'],
        $callsign,
        $handoff_type,
        $from_facility,
        $from_sector,
        $to_facility,
        $to_sector,
        $boundary_fix,
        $status,
        $initiated_at,
        $accepted_at,
        $source
    ];

    $log_stmt = sqlsrv_query($conn, $log_sql, $log_params);
    if ($log_stmt !== false) {
        sqlsrv_free_stmt($log_stmt);
        $logged = true;
    }

    return ['status' => 'updated', 'gufi' => $existing['gufi'], 'logged' => $logged];
}

/**
 * Extract facility ID from sector ID (e.g., ZDC_33_CTR -> ZDC)
 */
function extractFacilityFromSector($sector) {
    // ERAM format: ZDC_33_CTR
    if (preg_match('/^(Z[A-Z]{2})_/', $sector, $matches)) {
        return $matches[1];
    }
    // STARS format: N90_ENR or simple format
    if (preg_match('/^([A-Z0-9]{3,4})_/', $sector, $matches)) {
        return $matches[1];
    }
    // Return first 3-4 chars as fallback
    return substr($sector, 0, 4);
}

/**
 * Parse ISO 8601 timestamp to DATETIME2 format
 */
function parseIsoTimestamp($timestamp) {
    if (empty($timestamp)) {
        return null;
    }
    $dt = new DateTime($timestamp);
    return $dt->format('Y-m-d H:i:s');
}
