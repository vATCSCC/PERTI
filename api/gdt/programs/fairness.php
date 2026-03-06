<?php
/**
 * GDT Programs - Fairness & Anti-Gaming API
 *
 * GET /api/gdt/programs/fairness.php?program_id=N
 *
 * Computes filing-order reversal metrics and detects anti-gaming flags
 * for a GDP/AFP program. Returns reversal rate, flagged flights, and
 * gaming flag breakdown.
 *
 * Filing-order reversals (Bertsimas/Gupta 2016): Flight A filed before
 * flight B but B received an earlier CTA. Target: <15% reversal rate.
 *
 * Anti-gaming flags (informational only, not blocking):
 *   MULTI_FILING  - Same CID with >1 active flight to GDP destination
 *   DEST_SWITCH   - Destination changed to GDP airport after program start
 *   LATE_STRATEGIC - Popup filed after program start with CTA in last 30 min
 *
 * Response:
 * {
 *   "status": "ok",
 *   "data": {
 *     "program_id": 1,
 *     "reversal_count": 12,
 *     "reversal_pct": 8.33,
 *     "eligible_pairs": 144,
 *     "fairness_computed_utc": "2026-03-05T14:30:00Z",
 *     "gaming_flags_count": 2,
 *     "gaming_breakdown": {
 *       "multi_filing": 1,
 *       "dest_switch": 0,
 *       "late_strategic": 1
 *     },
 *     "flagged_flights": [ ... ]
 *   }
 * }
 *
 * @version 1.0.0
 * @date 2026-03-05
 */

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

define('GDT_API_INCLUDED', true);
require_once(__DIR__ . '/../common.php');
$auth_cid = gdt_require_auth();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    respond_json(405, [
        'status' => 'error',
        'message' => 'Method not allowed. Use GET.'
    ]);
}

$conn_tmi = gdt_get_conn_tmi();
$conn_adl = gdt_get_conn_adl();

// ============================================================================
// Validate Program
// ============================================================================

$program_id = isset($_GET['program_id']) ? (int)$_GET['program_id'] : 0;

if ($program_id <= 0) {
    respond_json(400, [
        'status' => 'error',
        'message' => 'program_id is required.'
    ]);
}

$program = get_program($conn_tmi, $program_id);

if ($program === null) {
    respond_json(404, [
        'status' => 'error',
        'message' => "Program not found: {$program_id}"
    ]);
}

$status = $program['status'] ?? '';
if (!in_array($status, ['ACTIVE', 'COMPLETED', 'EXTENDED'])) {
    respond_json(400, [
        'status' => 'error',
        'message' => "Fairness analysis only available for ACTIVE/COMPLETED programs. Current status: {$status}"
    ]);
}

$program_type = $program['program_type'] ?? '';
if ($program_type === 'GS') {
    respond_json(400, [
        'status' => 'error',
        'message' => 'Fairness analysis is not applicable to Ground Stop programs.'
    ]);
}

$ctl_element = $program['ctl_element'] ?? '';
$start_utc = $program['start_utc'] ?? null;
$end_utc = $program['end_utc'] ?? null;

// ============================================================================
// Step 1: Populate filing_time_utc for flights missing it (cross-DB bridge)
// Must run BEFORE reversal computation so the SP sees populated filing times.
// ============================================================================

$missing_result = fetch_all($conn_tmi,
    "SELECT fc.control_id, fc.flight_uid
     FROM dbo.tmi_flight_control fc
     WHERE fc.program_id = ?
       AND fc.filing_time_utc IS NULL
       AND fc.flight_uid IS NOT NULL",
    [$program_id]
);

if ($missing_result['success'] && count($missing_result['data']) > 0) {
    $flight_uids = array_column($missing_result['data'], 'flight_uid');
    $control_map = [];
    foreach ($missing_result['data'] as $row) {
        $control_map[$row['flight_uid']] = $row['control_id'];
    }

    // Query ADL for first_seen_utc in batches
    $batch_size = 100;
    $uid_chunks = array_chunk($flight_uids, $batch_size);

    foreach ($uid_chunks as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $adl_sql = "SELECT flight_uid, first_seen_utc FROM dbo.adl_flight_core WHERE flight_uid IN ({$placeholders})";
        $adl_result = fetch_all($conn_adl, $adl_sql, $chunk);

        if ($adl_result['success']) {
            foreach ($adl_result['data'] as $adl_row) {
                $fuid = $adl_row['flight_uid'];
                $first_seen = $adl_row['first_seen_utc'];
                if ($first_seen !== null && isset($control_map[$fuid])) {
                    $cid = $control_map[$fuid];
                    execute_query($conn_tmi,
                        "UPDATE dbo.tmi_flight_control SET filing_time_utc = ? WHERE control_id = ?",
                        [$first_seen, $cid]
                    );
                }
            }
        }
    }
}

// ============================================================================
// Step 2: Compute Filing-Order Reversals (SQL Server SP)
// ============================================================================

$reversal_sql = "
    DECLARE @reversal_count INT, @reversal_pct DECIMAL(5,2), @eligible_pairs BIGINT;
    EXEC dbo.sp_TMI_ComputeReversals
        @program_id = ?,
        @reversal_count = @reversal_count OUTPUT,
        @reversal_pct = @reversal_pct OUTPUT,
        @eligible_pairs = @eligible_pairs OUTPUT;
    SELECT
        @reversal_count AS reversal_count,
        @reversal_pct AS reversal_pct,
        @eligible_pairs AS eligible_pairs;
";

$reversal_stmt = sqlsrv_query($conn_tmi, $reversal_sql, [$program_id]);

$reversal_count = 0;
$reversal_pct = 0.0;
$eligible_pairs = 0;

if ($reversal_stmt !== false) {
    $rev_row = sqlsrv_fetch_array($reversal_stmt, SQLSRV_FETCH_ASSOC);
    if ($rev_row) {
        $reversal_count = (int)($rev_row['reversal_count'] ?? 0);
        $reversal_pct = round((float)($rev_row['reversal_pct'] ?? 0), 2);
        $eligible_pairs = (int)($rev_row['eligible_pairs'] ?? 0);
    }
    sqlsrv_free_stmt($reversal_stmt);
} else {
    $errors = filter_sqlsrv_errors();
    respond_json(500, [
        'status' => 'error',
        'message' => 'Failed to compute reversal metrics',
        'errors' => $errors
    ]);
}

// ============================================================================
// Step 3: Anti-Gaming Detection (cross-DB queries)
// ============================================================================

// Get controlled flights for this program
$controlled_result = fetch_all($conn_tmi,
    "SELECT control_id, flight_uid, callsign, dep_airport, arr_airport, cta_utc, is_popup, filing_time_utc
     FROM dbo.tmi_flight_control
     WHERE program_id = ? AND ctl_exempt = 0 AND flight_uid IS NOT NULL",
    [$program_id]
);

$controlled_flights = $controlled_result['success'] ? $controlled_result['data'] : [];
$gaming_updates = []; // control_id => gaming_flag

// --- 3A: MULTI_FILING ---
// Same CID with >1 active flight to GDP destination
if (count($controlled_flights) > 0) {
    $uids = array_column($controlled_flights, 'flight_uid');
    $uid_to_control = [];
    foreach ($controlled_flights as $cf) {
        $uid_to_control[$cf['flight_uid']] = $cf['control_id'];
    }

    // Query ADL for CIDs of controlled flights
    $uid_chunks = array_chunk($uids, 100);
    $uid_cid_map = []; // flight_uid => cid
    foreach ($uid_chunks as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $cid_sql = "SELECT flight_uid, cid FROM dbo.adl_flight_core WHERE flight_uid IN ({$placeholders})";
        $cid_result = fetch_all($conn_adl, $cid_sql, $chunk);
        if ($cid_result['success']) {
            foreach ($cid_result['data'] as $r) {
                $uid_cid_map[$r['flight_uid']] = $r['cid'];
            }
        }
    }

    // Group by CID, find duplicates to same destination
    $cid_flights = []; // cid => [flight_uid, ...]
    foreach ($uid_cid_map as $fuid => $cid) {
        if ($cid !== null) {
            $cid_flights[$cid][] = $fuid;
        }
    }

    foreach ($cid_flights as $cid => $flight_list) {
        if (count($flight_list) > 1) {
            // Multiple flights from same CID in this GDP — flag all
            foreach ($flight_list as $fuid) {
                if (isset($uid_to_control[$fuid])) {
                    $gaming_updates[$uid_to_control[$fuid]] = 'MULTI_FILING';
                }
            }
        }
    }
}

// --- 3B: DEST_SWITCH ---
// Destination changed to GDP airport after program start
if (count($controlled_flights) > 0 && $start_utc !== null) {
    $start_iso = datetime_to_iso($start_utc);
    $uids = array_column($controlled_flights, 'flight_uid');
    $uid_to_control_b = [];
    foreach ($controlled_flights as $cf) {
        $uid_to_control_b[$cf['flight_uid']] = $cf['control_id'];
    }

    $uid_chunks = array_chunk($uids, 100);
    foreach ($uid_chunks as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $dest_sql = "
            SELECT DISTINCT flight_uid
            FROM dbo.adl_flight_changelog
            WHERE flight_uid IN ({$placeholders})
              AND field_name = 'fp_dest_icao'
              AND new_value = ?
              AND change_utc >= ?
        ";
        $params = $chunk;
        $params[] = $ctl_element;
        $params[] = $start_iso;
        $dest_result = fetch_all($conn_adl, $dest_sql, $params);
        if ($dest_result['success']) {
            foreach ($dest_result['data'] as $r) {
                $fuid = $r['flight_uid'];
                if (isset($uid_to_control_b[$fuid])) {
                    // Only set if not already flagged with a higher-priority flag
                    if (!isset($gaming_updates[$uid_to_control_b[$fuid]])) {
                        $gaming_updates[$uid_to_control_b[$fuid]] = 'DEST_SWITCH';
                    }
                }
            }
        }
    }
}

// --- 3C: LATE_STRATEGIC ---
// Popup filed after program start with CTA in last 30 min of window
if ($end_utc !== null && $start_utc !== null) {
    $start_iso = datetime_to_iso($start_utc);
    foreach ($controlled_flights as $cf) {
        $cid = $cf['control_id'];
        // Already flagged with higher priority?
        if (isset($gaming_updates[$cid])) continue;

        $is_popup = (int)($cf['is_popup'] ?? 0);
        $filing = $cf['filing_time_utc'] ?? null;
        $cta = $cf['cta_utc'] ?? null;

        if (!$is_popup || $filing === null || $cta === null) continue;

        // Filing time after program start?
        $filing_str = ($filing instanceof DateTimeInterface) ? $filing->format('Y-m-d H:i:s') : (string)$filing;
        $start_str = ($start_utc instanceof DateTimeInterface) ? $start_utc->format('Y-m-d H:i:s') : (string)$start_utc;
        $end_str = ($end_utc instanceof DateTimeInterface) ? $end_utc->format('Y-m-d H:i:s') : (string)$end_utc;

        if ($filing_str > $start_str) {
            // CTA in last 30 min of program window?
            $end_dt = new DateTime($end_str, new DateTimeZone('UTC'));
            $threshold_dt = clone $end_dt;
            $threshold_dt->modify('-30 minutes');
            $cta_str = ($cta instanceof DateTimeInterface) ? $cta->format('Y-m-d H:i:s') : (string)$cta;

            if ($cta_str >= $threshold_dt->format('Y-m-d H:i:s') && $cta_str <= $end_dt->format('Y-m-d H:i:s')) {
                $gaming_updates[$cid] = 'LATE_STRATEGIC';
            }
        }
    }
}

// ============================================================================
// Step 4: Apply Gaming Flag Updates
// ============================================================================

// Clear existing gaming flags for this program first
execute_query($conn_tmi,
    "UPDATE dbo.tmi_flight_control SET gaming_flag = NULL WHERE program_id = ? AND gaming_flag IS NOT NULL",
    [$program_id]
);

// Apply new flags
$flags_applied = 0;
foreach ($gaming_updates as $control_id => $flag) {
    $upd = execute_query($conn_tmi,
        "UPDATE dbo.tmi_flight_control SET gaming_flag = ? WHERE control_id = ?",
        [$flag, $control_id]
    );
    if ($upd['success']) {
        $flags_applied++;
    }
}

// Update gaming_flags_count on program
execute_query($conn_tmi,
    "UPDATE dbo.tmi_programs SET gaming_flags_count = ? WHERE program_id = ?",
    [$flags_applied, $program_id]
);

// ============================================================================
// Step 5: Get Gaming Flag Breakdown
// ============================================================================

$breakdown_result = fetch_one($conn_tmi,
    "SELECT
        SUM(CASE WHEN gaming_flag = 'MULTI_FILING' THEN 1 ELSE 0 END) AS multi_filing,
        SUM(CASE WHEN gaming_flag = 'DEST_SWITCH' THEN 1 ELSE 0 END) AS dest_switch,
        SUM(CASE WHEN gaming_flag = 'LATE_STRATEGIC' THEN 1 ELSE 0 END) AS late_strategic
     FROM dbo.tmi_flight_control
     WHERE program_id = ? AND gaming_flag IS NOT NULL",
    [$program_id]
);

$breakdown = [
    'multi_filing' => 0,
    'dest_switch' => 0,
    'late_strategic' => 0
];
if ($breakdown_result['success'] && $breakdown_result['data'] !== null) {
    $breakdown['multi_filing'] = (int)($breakdown_result['data']['multi_filing'] ?? 0);
    $breakdown['dest_switch'] = (int)($breakdown_result['data']['dest_switch'] ?? 0);
    $breakdown['late_strategic'] = (int)($breakdown_result['data']['late_strategic'] ?? 0);
}

// ============================================================================
// Step 6: Get Flagged Flights List
// ============================================================================

$flagged_result = fetch_all($conn_tmi,
    "SELECT control_id, flight_uid, callsign, dep_airport, arr_airport,
            gaming_flag, cta_utc, filing_time_utc, is_popup
     FROM dbo.tmi_flight_control
     WHERE program_id = ? AND gaming_flag IS NOT NULL
     ORDER BY gaming_flag, callsign",
    [$program_id]
);

$flagged_flights = $flagged_result['success'] ? $flagged_result['data'] : [];

// ============================================================================
// Step 7: Refresh program to get updated fairness_computed_utc
// ============================================================================

$program = get_program($conn_tmi, $program_id);

// ============================================================================
// Response
// ============================================================================

respond_json(200, [
    'status' => 'ok',
    'data' => [
        'program_id' => $program_id,
        'reversal_count' => $reversal_count,
        'reversal_pct' => $reversal_pct,
        'eligible_pairs' => $eligible_pairs,
        'fairness_computed_utc' => datetime_to_iso($program['fairness_computed_utc'] ?? null),
        'gaming_flags_count' => $flags_applied,
        'gaming_breakdown' => $breakdown,
        'flagged_flights' => $flagged_flights
    ]
]);
