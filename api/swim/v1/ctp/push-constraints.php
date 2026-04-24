<?php
/**
 * VATSWIM API v1 - CTP Push Constraints Endpoint
 *
 * Flowcontrol pushes facility constraints (airport, FIR, fix, sector) that
 * the constraint advisor evaluates during slot requests. Idempotent — re-pushing
 * the same facility updates it.
 *
 * POST /api/swim/v1/ctp/push-constraints.php
 */

require_once __DIR__ . '/../auth.php';

global $conn_swim;
if (!$conn_swim) SwimResponse::error('SWIM database not available', 503, 'SERVICE_UNAVAILABLE');

$auth = swim_init_auth(true, true);
if (!$auth->canWriteField('ctp')) {
    SwimResponse::error('CTP write authority required', 403, 'FORBIDDEN');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    SwimResponse::error('Method not allowed', 405, 'METHOD_NOT_ALLOWED');
}

$conn_tmi = get_conn_tmi();
if (!$conn_tmi) SwimResponse::error('TMI database not available', 503, 'SERVICE_UNAVAILABLE');

$body = swim_get_json_body();
if (!$body) SwimResponse::error('Invalid JSON body', 400, 'INVALID_REQUEST');

$sessionRef = $body['session_name'] ?? $body['session_id'] ?? null;
if (!$sessionRef) SwimResponse::error('session_name or session_id required', 400, 'INVALID_REQUEST');

$constraints = $body['constraints'] ?? [];
if (!is_array($constraints) || empty($constraints)) {
    SwimResponse::error('constraints array required and must not be empty', 400, 'INVALID_REQUEST');
}

require_once __DIR__ . '/../../../../load/services/CTPSlotEngine.php';
$conn_adl = get_conn_adl();
$engine = new PERTI\Services\CTPSlotEngine($conn_adl, $conn_tmi, $conn_swim);

$session = $engine->resolveSession($sessionRef);
if (!$session) SwimResponse::error('Session not found', 404, 'SESSION_NOT_FOUND');

$sessionId = (int)$session['session_id'];
$status = $session['status'] ?? '';
if (!in_array($status, ['DRAFT', 'ACTIVE'])) {
    SwimResponse::error('Session must be DRAFT or ACTIVE to push constraints', 409, 'SESSION_NOT_ACTIVE');
}

$validTypes = ['airport', 'fir', 'fix', 'sector'];
$created = 0;
$updated = 0;

foreach ($constraints as $c) {
    $facilityName = trim($c['facility'] ?? $c['facility_name'] ?? '');
    $facilityType = strtolower(trim($c['facility_type'] ?? ''));
    $maxAcph = isset($c['maxAircraftPerHour']) ? (int)$c['maxAircraftPerHour']
             : (isset($c['max_acph']) ? (int)$c['max_acph'] : null);

    if (!$facilityName || !$facilityType || $maxAcph === null || $maxAcph < 1) continue;
    if (!in_array($facilityType, $validTypes)) continue;

    $stmt = sqlsrv_query($conn_tmi,
        "SELECT constraint_id FROM dbo.ctp_facility_constraints
         WHERE session_id = ? AND facility_name = ? AND facility_type = ?",
        [$sessionId, $facilityName, $facilityType]
    );
    $existing = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
    if ($stmt) sqlsrv_free_stmt($stmt);

    if ($existing) {
        $s = sqlsrv_query($conn_tmi,
            "UPDATE dbo.ctp_facility_constraints SET
                max_acph = ?, updated_at = SYSUTCDATETIME()
             WHERE constraint_id = ?",
            [$maxAcph, $existing['constraint_id']]
        );
        if ($s) sqlsrv_free_stmt($s);
        $updated++;
    } else {
        $s = sqlsrv_query($conn_tmi,
            "INSERT INTO dbo.ctp_facility_constraints
                (session_id, facility_name, facility_type, max_acph)
             VALUES (?, ?, ?, ?)",
            [$sessionId, $facilityName, $facilityType, $maxAcph]
        );
        if ($s) sqlsrv_free_stmt($s);
        $created++;
    }
}

SwimResponse::success([
    'constraints_received' => count($constraints),
    'constraints_created' => $created,
    'constraints_updated' => $updated,
]);
