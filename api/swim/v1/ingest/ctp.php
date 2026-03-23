<?php
/**
 * VATSWIM API v1 - CTP Slot Assignment Ingest Endpoint
 *
 * Receives slot assignment results from the CTP API (vatsimnetwork/ctp-api)
 * after its SlotDistributionCreator optimizer runs. Updates ctp_flight_control
 * with EDCTs, route segments, and NAT track assignments; bridges to TMI for
 * EDCT pipeline; pushes to swim_flights for external consumers.
 *
 * @version 1.0.0
 * @since 2026-03-22
 * @see docs/superpowers/specs/2026-03-22-ctp-api-vatswim-integration.md
 *
 * Expected payload:
 * {
 *   "event_id": "CTP2026W",
 *   "session_id": 1,
 *   "source": "ctp-api",
 *   "source_version": "1.0.0",
 *   "slots": [
 *     {
 *       "callsign": "BAW117",
 *       "cid": 1234567,
 *       "dep_airport": "EGLL",
 *       "arr_airport": "KJFK",
 *       "departure_time": "2026-10-19T12:30:00Z",
 *       "projected_arrival_time": "2026-10-19T20:15:00Z",
 *       "route_segments": [
 *         {"segment": "NA", "route_string": "...", "group": "AMAS"},
 *         {"segment": "OCEANIC", "route_string": "...", "group": "NAT", "track_name": "NATA"},
 *         {"segment": "EU", "route_string": "...", "group": "EMEA"}
 *       ],
 *       "throughput_point_id": "NATA",
 *       "slot_delay_min": 15,
 *       "original_etd": "2026-10-19T12:15:00Z"
 *     }
 *   ],
 *   "optimization_metadata": { ... }
 * }
 */

require_once __DIR__ . '/../auth.php';

// Require authentication with write access
$auth = swim_init_auth(true, true);

// Validate source can write CTP slot data
if (!$auth->canWriteField('ctp')) {
    SwimResponse::error(
        'Source "' . $auth->getSourceId() . '" is not authorized to write CTP slot data. ' .
        'Requires System tier with ctp authority.',
        403,
        'INSUFFICIENT_PERMISSION'
    );
}

// Only accept POST
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    SwimResponse::error('Method not allowed. Use POST.', 405, 'METHOD_NOT_ALLOWED');
}

// Parse body
$body = swim_get_json_body();
if (!$body) {
    SwimResponse::error('Request body is required', 400, 'MISSING_BODY');
}

if (!isset($body['slots']) || !is_array($body['slots']) || empty($body['slots'])) {
    SwimResponse::error('Request must contain a non-empty "slots" array', 400, 'EMPTY_SLOTS');
}

$session_id = isset($body['session_id']) ? intval($body['session_id']) : 0;
if ($session_id <= 0) {
    SwimResponse::error('session_id is required and must be a positive integer', 400, 'MISSING_PARAM');
}

$source = trim($body['source'] ?? 'ctp-api');
$source_version = trim($body['source_version'] ?? '');
$event_id = trim($body['event_id'] ?? '');
$slots = $body['slots'];
$metadata = $body['optimization_metadata'] ?? [];

// Batch size limit
$max_batch = 1000;
if (count($slots) > $max_batch) {
    SwimResponse::error(
        "Batch size exceeded. Maximum {$max_batch} slots per request.",
        400,
        'BATCH_TOO_LARGE'
    );
}

// ============================================================================
// Validate session exists and is in an assignable state
// ============================================================================

$conn_tmi = get_conn_tmi();
if (!$conn_tmi) {
    SwimResponse::error('TMI database connection not available', 503, 'SERVICE_UNAVAILABLE');
}

$sess_stmt = sqlsrv_query($conn_tmi,
    "SELECT session_id, session_name, status, program_id, direction, constrained_firs
     FROM dbo.ctp_sessions WHERE session_id = ?",
    [$session_id]
);
if ($sess_stmt === false) {
    SwimResponse::error('Database error validating session', 500, 'DB_ERROR');
}
$session = sqlsrv_fetch_array($sess_stmt, SQLSRV_FETCH_ASSOC);
sqlsrv_free_stmt($sess_stmt);

if (!$session) {
    SwimResponse::error(
        "CTP session {$session_id} not found",
        404,
        'SESSION_NOT_FOUND'
    );
}
if (!in_array($session['status'], ['ACTIVE', 'DRAFT', 'MONITORING'])) {
    SwimResponse::error(
        "CTP session {$session_id} is {$session['status']}. Slot assignments require ACTIVE, DRAFT, or MONITORING status.",
        409,
        'SESSION_NOT_ACTIVE'
    );
}

$program_id = isset($session['program_id']) ? (int)$session['program_id'] : null;
$session_name = $session['session_name'] ?? 'CTP';

// ============================================================================
// Process each slot assignment
// ============================================================================

$results = [
    'processed'  => 0,
    'matched'    => 0,
    'assigned'   => 0,
    'skipped'    => 0,
    'not_found'  => 0,
    'errors'     => 0,
];
$skip_reasons = [];
$not_found_flights = [];
$error_details = [];
$assigned_flight_uids = [];

foreach ($slots as $index => $slot) {
    $results['processed']++;

    try {
        $slot_result = processSlotAssignment(
            $conn_tmi, $session_id, $program_id, $session_name, $slot, $source, $index
        );

        switch ($slot_result['status']) {
            case 'assigned':
                $results['matched']++;
                $results['assigned']++;
                if (!empty($slot_result['flight_uid'])) {
                    $assigned_flight_uids[] = $slot_result['flight_uid'];
                }
                break;

            case 'skipped':
                $results['matched']++;
                $results['skipped']++;
                $skip_reasons[] = [
                    'callsign' => $slot['callsign'] ?? 'unknown',
                    'reason'   => $slot_result['reason'],
                    'existing_edct' => $slot_result['existing_edct'] ?? null,
                ];
                break;

            case 'not_found':
                $results['not_found']++;
                $not_found_flights[] = [
                    'callsign'    => $slot['callsign'] ?? 'unknown',
                    'dep_airport' => $slot['dep_airport'] ?? null,
                    'arr_airport' => $slot['arr_airport'] ?? null,
                ];
                break;
        }
    } catch (Exception $e) {
        $results['errors']++;
        $error_details[] = [
            'index'    => $index,
            'callsign' => $slot['callsign'] ?? 'unknown',
            'error'    => $e->getMessage(),
        ];
    }
}

// ============================================================================
// Immediate SWIM push for assigned flights
// ============================================================================

$swim_pushed = 0;
$conn_swim = get_conn_swim();
if ($conn_swim && !empty($assigned_flight_uids)) {
    $swim_pushed = pushToSwimFlights($conn_tmi, $conn_swim, $session_id, $assigned_flight_uids);
}

// ============================================================================
// Bulk audit log
// ============================================================================

$audit_detail = [
    'source'         => $source,
    'source_version' => $source_version,
    'event_id'       => $event_id,
    'slot_count'     => count($slots),
    'matched'        => $results['matched'],
    'assigned'       => $results['assigned'],
    'skipped'        => $results['skipped'],
    'not_found'      => $results['not_found'],
    'errors'         => $results['errors'],
    'algorithm'      => $metadata['algorithm'] ?? null,
    'revision'       => $metadata['revision'] ?? null,
];

$audit_json = json_encode($audit_detail, JSON_UNESCAPED_UNICODE);
sqlsrv_query($conn_tmi,
    "INSERT INTO dbo.ctp_audit_log (session_id, ctp_control_id, action_type, segment, action_detail_json, performed_by)
     VALUES (?, NULL, 'EDCT_BATCH_ASSIGN', 'GLOBAL', ?, ?)",
    [$session_id, $audit_json, $source]
);

// ============================================================================
// WebSocket event push
// ============================================================================

pushWebSocketEvent($session_id, $results['assigned'], $source);

// ============================================================================
// Response
// ============================================================================

SwimResponse::success([
    'processed'         => $results['processed'],
    'matched'           => $results['matched'],
    'assigned'          => $results['assigned'],
    'skipped'           => $results['skipped'],
    'not_found'         => $results['not_found'],
    'errors'            => $results['errors'],
    'skip_reasons'      => array_slice($skip_reasons, 0, 50),
    'not_found_flights' => array_slice($not_found_flights, 0, 50),
    'error_details'     => array_slice($error_details, 0, 20),
    'metadata'          => [
        'session_id'   => $session_id,
        'swim_pushed'  => $swim_pushed,
        'tmi_bridged'  => $program_id ? $results['assigned'] : 0,
        'audit_logged' => true,
    ],
], [
    'source'         => $auth->getSourceId(),
    'session_id'     => $session_id,
    'batch_size'     => count($slots),
]);


// ============================================================================
// Processing Functions
// ============================================================================

/**
 * Process a single slot assignment
 *
 * @param resource $conn_tmi TMI database connection
 * @param int $session_id CTP session ID
 * @param int|null $program_id TMI program ID (for EDCT bridge)
 * @param string $session_name Session name for TMI ctl_elem
 * @param array $slot Slot data from CTP API
 * @param string $source Source identifier
 * @param int $index Array index (for error reporting)
 * @return array Result with 'status' key (assigned|skipped|not_found)
 */
function processSlotAssignment($conn_tmi, $session_id, $program_id, $session_name, $slot, $source, $index) {
    // Validate required fields
    $callsign = isset($slot['callsign']) ? strtoupper(trim($slot['callsign'])) : '';
    $dep_airport = isset($slot['dep_airport']) ? strtoupper(trim($slot['dep_airport'])) : '';
    $arr_airport = isset($slot['arr_airport']) ? strtoupper(trim($slot['arr_airport'])) : '';
    $departure_time = isset($slot['departure_time']) ? trim($slot['departure_time']) : '';

    if ($callsign === '' || $departure_time === '') {
        throw new Exception("Slot index {$index}: callsign and departure_time are required");
    }

    // Parse the departure time (this becomes the EDCT)
    $edct_utc = parseUtcDatetime($departure_time);
    if (!$edct_utc) {
        throw new Exception("Slot index {$index}: invalid departure_time format");
    }

    // Find matching flight in ctp_flight_control
    $flight = findMatchingFlight($conn_tmi, $session_id, $callsign, $dep_airport, $arr_airport, $slot);
    if (!$flight) {
        return ['status' => 'not_found'];
    }

    $ctp_control_id = (int)$flight['ctp_control_id'];
    $flight_uid = (int)$flight['flight_uid'];

    // Check if already assigned with same EDCT (idempotency)
    if ($flight['edct_status'] === 'ASSIGNED' && !empty($flight['edct_utc'])) {
        $existing_edct_ts = ($flight['edct_utc'] instanceof DateTimeInterface)
            ? $flight['edct_utc']->getTimestamp()
            : strtotime($flight['edct_utc']);
        $new_edct_ts = strtotime($edct_utc);

        if ($existing_edct_ts === $new_edct_ts) {
            // Same EDCT already assigned - idempotent skip
            return [
                'status' => 'skipped',
                'reason' => 'already_assigned',
                'existing_edct' => ($flight['edct_utc'] instanceof DateTimeInterface)
                    ? $flight['edct_utc']->format('Y-m-d\TH:i:s') . 'Z'
                    : $flight['edct_utc'],
            ];
        }
    }

    // Check if flight is excluded
    if (!empty($flight['is_excluded'])) {
        return ['status' => 'skipped', 'reason' => 'flight_excluded'];
    }

    // Calculate delay
    $original_etd = isset($slot['original_etd']) ? trim($slot['original_etd']) : '';
    $original_etd_utc = $original_etd ? parseUtcDatetime($original_etd) : null;
    $slot_delay_min = isset($slot['slot_delay_min']) ? intval($slot['slot_delay_min']) : null;

    if ($slot_delay_min === null && $original_etd_utc) {
        $orig_ts = strtotime($original_etd_utc);
        $edct_ts = strtotime($edct_utc);
        if ($orig_ts && $edct_ts) {
            $slot_delay_min = (int)round(($edct_ts - $orig_ts) / 60);
        }
    }

    // Extract route segments
    $seg_na = null;
    $seg_oceanic = null;
    $seg_eu = null;
    $track_name = null;

    if (isset($slot['route_segments']) && is_array($slot['route_segments'])) {
        foreach ($slot['route_segments'] as $seg) {
            $seg_type = strtoupper(trim($seg['segment'] ?? ''));
            $route_string = trim($seg['route_string'] ?? '');

            // Map CTP API group names to PERTI segment names
            if ($seg_type === '' && isset($seg['group'])) {
                $group = strtoupper(trim($seg['group']));
                $group_map = ['AMAS' => 'NA', 'NAT' => 'OCEANIC', 'EMEA' => 'EU'];
                $seg_type = $group_map[$group] ?? $group;
            }

            if ($route_string === '') continue;

            switch ($seg_type) {
                case 'NA':
                    $seg_na = $route_string;
                    break;
                case 'OCEANIC':
                    $seg_oceanic = $route_string;
                    if (isset($seg['track_name'])) {
                        $track_name = strtoupper(trim($seg['track_name']));
                    }
                    break;
                case 'EU':
                    $seg_eu = $route_string;
                    break;
            }
        }
    }

    // If no track_name from route_segments, try throughput_point_id
    if (!$track_name && isset($slot['throughput_point_id'])) {
        $tp = strtoupper(trim($slot['throughput_point_id']));
        if (preg_match('/^NAT[A-Z]$/', $tp)) {
            $track_name = $tp;
        }
    }

    $now = gmdate('Y-m-d H:i:s');

    // Build UPDATE for ctp_flight_control
    $set_clauses = [
        'edct_utc = ?',
        'edct_status = ?',
        'edct_assigned_by = ?',
        'edct_assigned_at = ?',
        'slot_delay_min = ?',
        'swim_push_version = swim_push_version + 1',
        'updated_at = SYSUTCDATETIME()',
    ];
    $params = [$edct_utc, 'ASSIGNED', $source, $now, $slot_delay_min];

    if ($original_etd_utc && empty($flight['original_etd_utc'])) {
        $set_clauses[] = 'original_etd_utc = ?';
        $params[] = $original_etd_utc;
    }

    if ($seg_na !== null) {
        $set_clauses[] = 'seg_na_route = ?';
        $set_clauses[] = "seg_na_status = 'VALIDATED'";
        $set_clauses[] = 'seg_na_modified_by = ?';
        $set_clauses[] = 'seg_na_modified_at = ?';
        $params[] = $seg_na;
        $params[] = $source;
        $params[] = $now;
    }

    if ($seg_oceanic !== null) {
        $set_clauses[] = 'seg_oceanic_route = ?';
        $set_clauses[] = "seg_oceanic_status = 'VALIDATED'";
        $set_clauses[] = 'seg_oceanic_modified_by = ?';
        $set_clauses[] = 'seg_oceanic_modified_at = ?';
        $params[] = $seg_oceanic;
        $params[] = $source;
        $params[] = $now;
    }

    if ($seg_eu !== null) {
        $set_clauses[] = 'seg_eu_route = ?';
        $set_clauses[] = "seg_eu_status = 'VALIDATED'";
        $set_clauses[] = 'seg_eu_modified_by = ?';
        $set_clauses[] = 'seg_eu_modified_at = ?';
        $params[] = $seg_eu;
        $params[] = $source;
        $params[] = $now;
    }

    if ($track_name) {
        $set_clauses[] = 'resolved_nat_track = ?';
        $set_clauses[] = 'nat_track_resolved_at = ?';
        $set_clauses[] = "nat_track_source = 'CTP_API'";
        $params[] = $track_name;
        $params[] = $now;
    }

    $params[] = $ctp_control_id;

    $update_sql = "UPDATE dbo.ctp_flight_control SET "
        . implode(', ', $set_clauses)
        . " WHERE ctp_control_id = ?";

    $stmt = sqlsrv_query($conn_tmi, $update_sql, $params);
    if ($stmt === false) {
        $errors = sqlsrv_errors();
        throw new Exception("Failed to update ctp_flight_control: " . ($errors[0]['message'] ?? 'Unknown'));
    }
    sqlsrv_free_stmt($stmt);

    // TMI bridge: create/update tmi_flight_control for EDCT pipeline
    if ($program_id && $flight_uid) {
        bridgeToTMI(
            $conn_tmi, $program_id, $flight_uid, $ctp_control_id,
            $flight, $edct_utc, $original_etd_utc, $slot_delay_min,
            $session_name, $source
        );
    }

    return [
        'status'     => 'assigned',
        'flight_uid' => $flight_uid,
    ];
}

/**
 * Find matching flight in ctp_flight_control
 *
 * Priority: callsign + dep_airport + arr_airport, then CID via ADL lookup
 */
function findMatchingFlight($conn_tmi, $session_id, $callsign, $dep_airport, $arr_airport, $slot) {
    $select_cols = "ctp_control_id, flight_uid, callsign, dep_airport, arr_airport,
                    edct_utc, edct_status, original_etd_utc, tmi_control_id, is_excluded";

    // Try exact match: callsign + airports
    if ($callsign && $dep_airport && $arr_airport) {
        $stmt = sqlsrv_query($conn_tmi,
            "SELECT TOP 1 {$select_cols}
             FROM dbo.ctp_flight_control
             WHERE session_id = ? AND callsign = ? AND dep_airport = ? AND arr_airport = ?",
            [$session_id, $callsign, $dep_airport, $arr_airport]
        );
        if ($stmt !== false) {
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt);
            if ($row) return $row;
        }
    }

    // Fallback: callsign only (if airports not provided or no match)
    if ($callsign) {
        $stmt = sqlsrv_query($conn_tmi,
            "SELECT TOP 1 {$select_cols}
             FROM dbo.ctp_flight_control
             WHERE session_id = ? AND callsign = ?",
            [$session_id, $callsign]
        );
        if ($stmt !== false) {
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt);
            if ($row) return $row;
        }
    }

    // Fallback: CID lookup (if provided) — find flight_uid from ADL, then match
    $cid = isset($slot['cid']) ? intval($slot['cid']) : 0;
    if ($cid > 0) {
        $conn_adl = get_conn_adl();
        if ($conn_adl) {
            $adl_stmt = sqlsrv_query($conn_adl,
                "SELECT TOP 1 flight_uid FROM dbo.adl_flight_core
                 WHERE cid = ? AND is_active = 1 ORDER BY flight_uid DESC",
                [$cid]
            );
            if ($adl_stmt !== false) {
                $adl_row = sqlsrv_fetch_array($adl_stmt, SQLSRV_FETCH_ASSOC);
                sqlsrv_free_stmt($adl_stmt);
                if ($adl_row) {
                    $stmt = sqlsrv_query($conn_tmi,
                        "SELECT TOP 1 {$select_cols}
                         FROM dbo.ctp_flight_control
                         WHERE session_id = ? AND flight_uid = ?",
                        [$session_id, (int)$adl_row['flight_uid']]
                    );
                    if ($stmt !== false) {
                        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
                        sqlsrv_free_stmt($stmt);
                        if ($row) return $row;
                    }
                }
            }
        }
    }

    return null;
}

/**
 * Bridge EDCT to tmi_flight_control for the TMI->ADL sync pipeline
 *
 * Follows the exact pattern from api/ctp/flights/assign_edct.php
 */
function bridgeToTMI($conn_tmi, $program_id, $flight_uid, $ctp_control_id,
                     $flight, $edct_utc, $original_etd_utc, $slot_delay_min,
                     $session_name, $source) {

    $tmi_control_id = !empty($flight['tmi_control_id']) ? (int)$flight['tmi_control_id'] : null;

    if ($tmi_control_id) {
        // Update existing tmi_flight_control
        sqlsrv_query($conn_tmi,
            "UPDATE dbo.tmi_flight_control SET
                ctd_utc = ?, program_delay_min = ?, modified_utc = SYSUTCDATETIME()
             WHERE control_id = ?",
            [$edct_utc, $slot_delay_min, $tmi_control_id]
        );
    } else {
        // Insert new tmi_flight_control
        $orig_etd_val = null;
        if ($original_etd_utc) {
            $orig_etd_val = $original_etd_utc;
        } elseif (!empty($flight['original_etd_utc'])) {
            $v = $flight['original_etd_utc'];
            $orig_etd_val = ($v instanceof DateTimeInterface) ? $v->format('Y-m-d H:i:s') : $v;
        }

        $insert_sql = "
            INSERT INTO dbo.tmi_flight_control (
                flight_uid, callsign, program_id,
                ctl_type, ctl_elem,
                ctd_utc, octd_utc,
                orig_etd_utc,
                program_delay_min,
                dep_airport, arr_airport,
                control_assigned_utc
            ) VALUES (?, ?, ?, 'CTP', ?, ?, ?, ?, ?, ?, ?, SYSUTCDATETIME());
            SELECT SCOPE_IDENTITY() AS control_id;
        ";

        $stmt = sqlsrv_query($conn_tmi, $insert_sql, [
            $flight_uid,
            $flight['callsign'],
            $program_id,
            $session_name,
            $edct_utc,
            $edct_utc,
            $orig_etd_val,
            $slot_delay_min,
            $flight['dep_airport'],
            $flight['arr_airport'],
        ]);

        if ($stmt !== false) {
            sqlsrv_next_result($stmt);
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            $new_control_id = $row ? (int)$row['control_id'] : null;
            sqlsrv_free_stmt($stmt);

            // Link back to ctp_flight_control
            if ($new_control_id) {
                $link_stmt = sqlsrv_query($conn_tmi,
                    "UPDATE dbo.ctp_flight_control SET tmi_control_id = ? WHERE ctp_control_id = ?",
                    [$new_control_id, $ctp_control_id]
                );
                if ($link_stmt === false) {
                    error_log("CTP ingest: Failed to link tmi_control_id {$new_control_id} to ctp_control_id {$ctp_control_id}");
                } elseif ($link_stmt) {
                    sqlsrv_free_stmt($link_stmt);
                }
            }
        }
    }
}

/**
 * Push NAT track + EDCT data to swim_flights for all assigned flights
 */
function pushToSwimFlights($conn_tmi, $conn_swim, $session_id, $flight_uids) {
    $pushed = 0;

    // Batch fetch CTP data for assigned flights
    foreach (array_chunk($flight_uids, 100) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $params = array_merge([$session_id], $chunk);

        $stmt = sqlsrv_query($conn_tmi,
            "SELECT flight_uid, resolved_nat_track, nat_track_resolved_at, nat_track_source,
                    edct_utc, edct_status
             FROM dbo.ctp_flight_control
             WHERE session_id = ? AND flight_uid IN ({$placeholders})",
            $params
        );

        if ($stmt === false) continue;

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $uid = (int)$row['flight_uid'];

            $nat_track = $row['resolved_nat_track'];
            $nat_resolved = $row['nat_track_resolved_at'];
            $nat_source = $row['nat_track_source'];

            if ($nat_resolved instanceof DateTimeInterface) {
                $nat_resolved = $nat_resolved->format('Y-m-d H:i:s');
            }

            $swim_stmt = sqlsrv_query($conn_swim,
                "UPDATE dbo.swim_flights SET
                    resolved_nat_track = COALESCE(?, resolved_nat_track),
                    nat_track_resolved_at = COALESCE(?, nat_track_resolved_at),
                    nat_track_source = COALESCE(?, nat_track_source),
                    last_sync_utc = GETUTCDATE()
                 WHERE flight_uid = ?",
                [$nat_track, $nat_resolved, $nat_source, $uid]
            );

            if ($swim_stmt === false) {
                error_log("CTP SWIM push: Failed to update swim_flights for flight_uid {$uid}");
            } else {
                if (sqlsrv_rows_affected($swim_stmt) > 0) {
                    $pushed++;
                }
                sqlsrv_free_stmt($swim_stmt);
            }
        }
        sqlsrv_free_stmt($stmt);
    }

    return $pushed;
}

/**
 * Push WebSocket event for CTP slot optimization
 */
function pushWebSocketEvent($session_id, $assigned_count, $source) {
    $events = [[
        'type' => 'ctp.slots.optimized',
        'data' => [
            'session_id' => $session_id,
            'count'      => $assigned_count,
            'source'     => $source,
        ],
    ]];

    $eventFile = sys_get_temp_dir() . '/swim_ws_events.json';
    $existingEvents = [];
    if (file_exists($eventFile)) {
        $content = @file_get_contents($eventFile);
        if ($content) {
            $existingEvents = json_decode($content, true) ?: [];
        }
    }

    foreach ($events as $event) {
        $existingEvents[] = array_merge($event, [
            '_received_at' => gmdate('Y-m-d\TH:i:s.v\Z'),
        ]);
    }

    if (count($existingEvents) > 10000) {
        $existingEvents = array_slice($existingEvents, -5000);
    }

    $tempFile = $eventFile . '.tmp.' . getmypid();
    if (file_put_contents($tempFile, json_encode($existingEvents)) !== false) {
        @rename($tempFile, $eventFile);
    }
}

/**
 * Parse UTC datetime string to SQL-compatible format
 */
function parseUtcDatetime($s) {
    if (!is_string($s) || trim($s) === '') return null;
    try {
        $dt = new DateTime(trim($s));
        $dt->setTimezone(new DateTimeZone('UTC'));
        return $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return null;
    }
}
