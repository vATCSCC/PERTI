<?php
/**
 * GDT Programs - ECR (EDCT Change Request) API
 *
 * POST /api/gdt/programs/ecr.php
 *
 * Processes EDCT change requests for flights under active GDP/AFP programs.
 * Supports three operations:
 *   - SWAP: Exchange two flights' slot assignments
 *   - DELAY: Push a flight to a later slot (next available)
 *   - ADVANCE: Pull a flight to an earlier slot (next available)
 *
 * Request body (JSON):
 * {
 *   "program_id": 1,
 *   "action": "SWAP|DELAY|ADVANCE",
 *   "flight_uid": 12345,                // Primary flight
 *   "swap_flight_uid": 67890,           // Required for SWAP only
 *   "target_ctd": "2026-01-15T14:30:00Z", // Optional: specific target time for DELAY/ADVANCE
 *   "reason": "Pilot request"           // Optional: reason for ECR
 * }
 *
 * @version 1.0.0
 * @date 2026-03-29
 */

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

define('GDT_API_INCLUDED', true);
require_once(__DIR__ . '/../common.php');
$auth_cid = gdt_require_auth();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond_json(405, [
        'status' => 'error',
        'message' => 'Method not allowed. Use POST.'
    ]);
}

$payload = read_request_payload();
$conn_tmi = gdt_get_conn_tmi();

// ============================================================================
// Validate Input
// ============================================================================

$program_id = isset($payload['program_id']) ? (int)$payload['program_id'] : 0;
$action = strtoupper(trim($payload['action'] ?? ''));
$flight_uid = isset($payload['flight_uid']) ? (int)$payload['flight_uid'] : 0;
$swap_flight_uid = isset($payload['swap_flight_uid']) ? (int)$payload['swap_flight_uid'] : 0;
$target_ctd = isset($payload['target_ctd']) ? parse_utc_datetime($payload['target_ctd']) : null;
$reason = trim($payload['reason'] ?? 'ECR');

if ($program_id <= 0) {
    respond_json(400, ['status' => 'error', 'message' => 'program_id is required']);
}

if (!in_array($action, ['SWAP', 'DELAY', 'ADVANCE'])) {
    respond_json(400, ['status' => 'error', 'message' => 'action must be SWAP, DELAY, or ADVANCE']);
}

if ($flight_uid <= 0) {
    respond_json(400, ['status' => 'error', 'message' => 'flight_uid is required']);
}

if ($action === 'SWAP' && $swap_flight_uid <= 0) {
    respond_json(400, ['status' => 'error', 'message' => 'swap_flight_uid is required for SWAP action']);
}

// ============================================================================
// Validate Program
// ============================================================================

$program = get_program($conn_tmi, $program_id);

if (!$program) {
    respond_json(404, ['status' => 'error', 'message' => 'Program not found']);
}

$program_type = $program['program_type'] ?? '';
if (strpos($program_type, 'GDP') === false && $program_type !== 'AFP') {
    respond_json(400, ['status' => 'error', 'message' => 'ECR only applies to GDP/AFP programs']);
}

if (($program['status'] ?? '') !== 'ACTIVE') {
    respond_json(400, ['status' => 'error', 'message' => 'ECR only applies to ACTIVE programs']);
}

// ============================================================================
// Fetch Flight Control Records
// ============================================================================

$flight_sql = "SELECT * FROM dbo.tmi_flight_control WHERE program_id = ? AND flight_uid = ?";
$flight_result = fetch_one($conn_tmi, $flight_sql, [$program_id, $flight_uid]);

if (!$flight_result['success'] || !$flight_result['data']) {
    respond_json(404, ['status' => 'error', 'message' => 'Flight not found in program']);
}

$flight = $flight_result['data'];

// For SWAP, also fetch the swap target
$swap_flight = null;
if ($action === 'SWAP') {
    $swap_result = fetch_one($conn_tmi, $flight_sql, [$program_id, $swap_flight_uid]);
    if (!$swap_result['success'] || !$swap_result['data']) {
        respond_json(404, ['status' => 'error', 'message' => 'Swap target flight not found in program']);
    }
    $swap_flight = $swap_result['data'];
}

// ============================================================================
// Execute ECR Action
// ============================================================================

$old_ctd = $flight['ctd_utc'];
$old_cta = $flight['cta_utc'];
$changes = [];

switch ($action) {
    case 'SWAP':
        // Exchange slot assignments between two flights
        $flight_slot_id = $flight['slot_id'];
        $flight_ctd = $flight['ctd_utc'];
        $flight_cta = $flight['cta_utc'];
        $swap_slot_id = $swap_flight['slot_id'];
        $swap_ctd = $swap_flight['ctd_utc'];
        $swap_cta = $swap_flight['cta_utc'];

        // Update flight 1 with flight 2's slot
        $update1 = execute_query($conn_tmi,
            "UPDATE dbo.tmi_flight_control SET slot_id = ?, ctd_utc = ?, cta_utc = ?, ecr_pending = 0, modified_utc = SYSUTCDATETIME() WHERE control_id = ?",
            [$swap_slot_id, formatDatetimeForEcr($swap_ctd), formatDatetimeForEcr($swap_cta), (int)$flight['control_id']]
        );

        // Update flight 2 with flight 1's slot
        $update2 = execute_query($conn_tmi,
            "UPDATE dbo.tmi_flight_control SET slot_id = ?, ctd_utc = ?, cta_utc = ?, ecr_pending = 0, modified_utc = SYSUTCDATETIME() WHERE control_id = ?",
            [$flight_slot_id, formatDatetimeForEcr($flight_ctd), formatDatetimeForEcr($flight_cta), (int)$swap_flight['control_id']]
        );

        // Update slot assignments if slots exist
        if ($flight_slot_id && $swap_slot_id) {
            execute_query($conn_tmi,
                "UPDATE dbo.tmi_slots SET assigned_flight_uid = ? WHERE slot_id = ?",
                [$swap_flight_uid, $flight_slot_id]
            );
            execute_query($conn_tmi,
                "UPDATE dbo.tmi_slots SET assigned_flight_uid = ? WHERE slot_id = ?",
                [$flight_uid, $swap_slot_id]
            );
        }

        // Recalculate delay for both flights
        recalculateFlightDelay($conn_tmi, (int)$flight['control_id']);
        recalculateFlightDelay($conn_tmi, (int)$swap_flight['control_id']);

        $changes = [
            'flight_1' => ['uid' => $flight_uid, 'old_slot' => $flight_slot_id, 'new_slot' => $swap_slot_id],
            'flight_2' => ['uid' => $swap_flight_uid, 'old_slot' => $swap_slot_id, 'new_slot' => $flight_slot_id],
        ];
        break;

    case 'DELAY':
        // Push flight to a later slot or specific target time
        if ($target_ctd) {
            // Validate target is after current CTD
            $currentCtdStr = formatDatetimeForEcr($flight['ctd_utc']);
            if ($currentCtdStr && $target_ctd <= $currentCtdStr) {
                respond_json(400, ['status' => 'error', 'message' => 'Target CTD must be later than current CTD for DELAY']);
            }

            // Find the slot at or after target time
            $slot = findSlotNearTime($conn_tmi, $program_id, $target_ctd, 'AFTER');
            if ($slot) {
                assignFlightToSlot($conn_tmi, $flight, $slot);
            } else {
                // No slot found — just update CTD directly
                updateFlightCtd($conn_tmi, $flight, $target_ctd);
            }
        } else {
            // Find next available slot after current
            $slot = findNextAvailableSlot($conn_tmi, $program_id, $flight['slot_id'], 'AFTER');
            if (!$slot) {
                respond_json(400, ['status' => 'error', 'message' => 'No later slot available for DELAY']);
            }
            assignFlightToSlot($conn_tmi, $flight, $slot);
        }

        // Refresh flight data
        $updated_flight = fetch_one($conn_tmi, $flight_sql, [$program_id, $flight_uid]);
        $changes = [
            'old_ctd' => datetime_to_iso($old_ctd),
            'new_ctd' => $updated_flight['data']['ctd_utc'] ?? null,
        ];
        break;

    case 'ADVANCE':
        // Pull flight to an earlier slot or specific target time
        if ($target_ctd) {
            $currentCtdStr = formatDatetimeForEcr($flight['ctd_utc']);
            if ($currentCtdStr && $target_ctd >= $currentCtdStr) {
                respond_json(400, ['status' => 'error', 'message' => 'Target CTD must be earlier than current CTD for ADVANCE']);
            }

            $slot = findSlotNearTime($conn_tmi, $program_id, $target_ctd, 'BEFORE');
            if ($slot) {
                assignFlightToSlot($conn_tmi, $flight, $slot);
            } else {
                updateFlightCtd($conn_tmi, $flight, $target_ctd);
            }
        } else {
            $slot = findNextAvailableSlot($conn_tmi, $program_id, $flight['slot_id'], 'BEFORE');
            if (!$slot) {
                respond_json(400, ['status' => 'error', 'message' => 'No earlier slot available for ADVANCE']);
            }
            assignFlightToSlot($conn_tmi, $flight, $slot);
        }

        $updated_flight = fetch_one($conn_tmi, $flight_sql, [$program_id, $flight_uid]);
        $changes = [
            'old_ctd' => datetime_to_iso($old_ctd),
            'new_ctd' => $updated_flight['data']['ctd_utc'] ?? null,
        ];
        break;
}

// ============================================================================
// Log ECR Event
// ============================================================================

$event_details = json_encode([
    'action' => $action,
    'flight_uid' => $flight_uid,
    'swap_flight_uid' => $action === 'SWAP' ? $swap_flight_uid : null,
    'reason' => $reason,
    'changes' => $changes,
    'performed_by' => $auth_cid,
], JSON_UNESCAPED_UNICODE);

execute_query($conn_tmi, "
    INSERT INTO dbo.tmi_events (
        event_type, program_id, ctl_element,
        description, event_source, details_json
    ) VALUES (?, ?, ?, ?, ?, ?)
", [
    'ECR_' . $action,
    $program_id,
    $program['ctl_element'] ?? null,
    "ECR {$action}: flight {$flight_uid}" . ($action === 'SWAP' ? " swapped with {$swap_flight_uid}" : '') . " by {$auth_cid}",
    'USER',
    $event_details
]);

// ============================================================================
// Update Program Metrics
// ============================================================================

execute_query($conn_tmi, "
    UPDATE dbo.tmi_programs SET
        avg_delay_min = (SELECT ISNULL(AVG(CAST(program_delay_min AS DECIMAL(8,2))), 0) FROM dbo.tmi_flight_control WHERE program_id = ? AND ctl_exempt = 0 AND program_delay_min IS NOT NULL),
        max_delay_min = (SELECT ISNULL(MAX(program_delay_min), 0) FROM dbo.tmi_flight_control WHERE program_id = ? AND ctl_exempt = 0),
        total_delay_min = (SELECT ISNULL(SUM(program_delay_min), 0) FROM dbo.tmi_flight_control WHERE program_id = ? AND ctl_exempt = 0),
        modified_utc = SYSUTCDATETIME()
    WHERE program_id = ?
", [$program_id, $program_id, $program_id, $program_id]);

// ============================================================================
// Response
// ============================================================================

// Refresh program data for response
$program = get_program($conn_tmi, $program_id);

// Log to TMI unified log
log_tmi_action($conn_tmi, [
    'action_category' => 'SLOT',
    'action_type'     => 'ECR',
    'program_type'    => $program['program_type'] ?? null,
    'summary'         => 'ECR applied: ' . ($program['ctl_element'] ?? ''),
    'user_cid'        => $auth_cid,
    'issuing_org'     => $program['org_code'] ?? null,
], [
    'ctl_element' => $program['ctl_element'] ?? null,
    'element_type' => 'AIRPORT',
], null, [
    'ecr_action'    => $action,
    'flight_uid'    => $flight_uid,
    'changes'       => $changes,
], [
    'program_id' => $program_id,
]);

respond_json(200, [
    'status' => 'ok',
    'message' => "ECR {$action} processed successfully",
    'data' => [
        'program_id' => $program_id,
        'action' => $action,
        'flight_uid' => $flight_uid,
        'changes' => $changes,
        'updated_metrics' => [
            'avg_delay_min' => $program['avg_delay_min'] ?? 0,
            'max_delay_min' => $program['max_delay_min'] ?? 0,
            'total_delay_min' => $program['total_delay_min'] ?? 0,
        ]
    ]
]);

// ============================================================================
// Helper Functions
// ============================================================================

function formatDatetimeForEcr($val) {
    if ($val === null) return null;
    if ($val instanceof DateTimeInterface) return $val->format('Y-m-d H:i:s');
    return (string)$val;
}

function findSlotNearTime($conn, $program_id, $target_time, $direction) {
    $op = $direction === 'AFTER' ? '>=' : '<=';
    $order = $direction === 'AFTER' ? 'ASC' : 'DESC';

    $sql = "
        SELECT TOP 1 slot_id, slot_time_utc, slot_index
        FROM dbo.tmi_slots
        WHERE program_id = ?
          AND slot_time_utc {$op} ?
          AND (assigned_flight_uid IS NULL OR slot_type = 'RESERVE')
        ORDER BY slot_time_utc {$order}
    ";
    $result = fetch_one($conn, $sql, [$program_id, $target_time]);
    return ($result['success'] && $result['data']) ? $result['data'] : null;
}

function findNextAvailableSlot($conn, $program_id, $current_slot_id, $direction) {
    if (!$current_slot_id) return null;

    // Get current slot index
    $current = fetch_one($conn, "SELECT slot_index FROM dbo.tmi_slots WHERE slot_id = ?", [$current_slot_id]);
    if (!$current['success'] || !$current['data']) return null;
    $current_index = (int)$current['data']['slot_index'];

    $op = $direction === 'AFTER' ? '>' : '<';
    $order = $direction === 'AFTER' ? 'ASC' : 'DESC';

    $sql = "
        SELECT TOP 1 slot_id, slot_time_utc, slot_index
        FROM dbo.tmi_slots
        WHERE program_id = ?
          AND slot_index {$op} ?
          AND (assigned_flight_uid IS NULL OR slot_type = 'RESERVE')
        ORDER BY slot_index {$order}
    ";
    $result = fetch_one($conn, $sql, [$program_id, $current_index]);
    return ($result['success'] && $result['data']) ? $result['data'] : null;
}

function assignFlightToSlot($conn, $flight, $slot) {
    $control_id = (int)$flight['control_id'];
    $flight_uid = (int)$flight['flight_uid'];
    $old_slot_id = $flight['slot_id'];
    $new_slot_id = (int)$slot['slot_id'];
    $new_ctd = formatDatetimeForEcr($slot['slot_time_utc']);

    // Calculate new CTA based on en-route time
    $orig_etd = $flight['orig_etd_utc'];
    $orig_eta = $flight['orig_eta_utc'];
    $ete_min = null;
    if ($orig_etd && $orig_eta) {
        $etdStr = formatDatetimeForEcr($orig_etd);
        $etaStr = formatDatetimeForEcr($orig_eta);
        if ($etdStr && $etaStr) {
            $ete_min = (strtotime($etaStr) - strtotime($etdStr)) / 60;
        }
    }

    $new_cta = null;
    if ($ete_min && $new_ctd) {
        $new_cta = date('Y-m-d H:i:s', strtotime($new_ctd) + ($ete_min * 60));
    }

    // Unassign old slot
    if ($old_slot_id) {
        execute_query($conn,
            "UPDATE dbo.tmi_slots SET assigned_flight_uid = NULL WHERE slot_id = ?",
            [$old_slot_id]
        );
    }

    // Assign new slot
    execute_query($conn,
        "UPDATE dbo.tmi_slots SET assigned_flight_uid = ? WHERE slot_id = ?",
        [$flight_uid, $new_slot_id]
    );

    // Update flight control
    execute_query($conn,
        "UPDATE dbo.tmi_flight_control SET slot_id = ?, ctd_utc = ?, cta_utc = ?, ecr_pending = 0, modified_utc = SYSUTCDATETIME() WHERE control_id = ?",
        [$new_slot_id, $new_ctd, $new_cta, $control_id]
    );

    recalculateFlightDelay($conn, $control_id);
}

function updateFlightCtd($conn, $flight, $new_ctd) {
    $control_id = (int)$flight['control_id'];

    $orig_etd = $flight['orig_etd_utc'];
    $orig_eta = $flight['orig_eta_utc'];
    $ete_min = null;
    if ($orig_etd && $orig_eta) {
        $etdStr = formatDatetimeForEcr($orig_etd);
        $etaStr = formatDatetimeForEcr($orig_eta);
        if ($etdStr && $etaStr) {
            $ete_min = (strtotime($etaStr) - strtotime($etdStr)) / 60;
        }
    }

    $new_cta = null;
    if ($ete_min && $new_ctd) {
        $new_cta = date('Y-m-d H:i:s', strtotime($new_ctd) + ($ete_min * 60));
    }

    execute_query($conn,
        "UPDATE dbo.tmi_flight_control SET ctd_utc = ?, cta_utc = ?, ecr_pending = 0, modified_utc = SYSUTCDATETIME() WHERE control_id = ?",
        [$new_ctd, $new_cta, $control_id]
    );

    recalculateFlightDelay($conn, $control_id);
}

function recalculateFlightDelay($conn, $control_id) {
    execute_query($conn, "
        UPDATE dbo.tmi_flight_control
        SET program_delay_min = CASE
            WHEN ctd_utc IS NOT NULL AND orig_etd_utc IS NOT NULL
            THEN GREATEST(0, DATEDIFF(MINUTE, orig_etd_utc, ctd_utc))
            ELSE 0
        END
        WHERE control_id = ?
    ", [$control_id]);
}
