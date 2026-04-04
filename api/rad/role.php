<?php
/** RAD API: Role Detection — GET /api/rad/role.php */
define('RAD_API_INCLUDED', true);
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../../load/services/VNASService.php';

$cid = rad_require_auth();
$cid_int = (int)$cid;

// Role capability definitions
$ROLE_CAPS = [
    'TMU' => [
        'can_create_amendment' => true,
        'can_issue' => true,
        'can_accept_reject' => false,
        'can_submit_tos' => false,
        'can_resolve_tos' => true,
        'can_force' => true,
        'tabs' => ['search', 'detail', 'edit', 'monitoring'],
    ],
    'ATC' => [
        'can_create_amendment' => false,
        'can_issue' => true,
        'can_accept_reject' => true,
        'can_submit_tos' => true,
        'can_resolve_tos' => false,
        'can_force' => false,
        'tabs' => ['detail', 'monitoring'],
    ],
    'VA' => [
        'can_create_amendment' => false,
        'can_issue' => true,
        'can_accept_reject' => true,
        'can_submit_tos' => true,
        'can_resolve_tos' => false,
        'can_force' => false,
        'tabs' => ['detail', 'monitoring'],
    ],
    'PILOT' => [
        'can_create_amendment' => false,
        'can_issue' => false,
        'can_accept_reject' => true,
        'can_submit_tos' => true,
        'can_resolve_tos' => false,
        'can_force' => false,
        'tabs' => ['monitoring'],
    ],
    'OBSERVER' => [
        'can_create_amendment' => false,
        'can_issue' => false,
        'can_accept_reject' => false,
        'can_submit_tos' => false,
        'can_resolve_tos' => false,
        'can_force' => false,
        'tabs' => [],
    ],
];

// Role hierarchy (higher index = more privileged)
$ROLE_RANK = ['OBSERVER' => 0, 'PILOT' => 1, 'VA' => 2, 'ATC' => 3, 'TMU' => 4];

// ---- Auto-detect natural role ----

$detected_role = 'OBSERVER';
$detected_context = [];

// Priority 1: TMU (admin_users check)
$is_tmu = false;
global $conn_sqli;
$stmt = $conn_sqli->prepare("SELECT 1 FROM admin_users WHERE cid=? LIMIT 1");
$stmt->bind_param('i', $cid_int);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $result->num_rows > 0) {
    $is_tmu = true;
}
$stmt->close();

if ($is_tmu) {
    $detected_role = 'TMU';
}

// Priority 2: ATC (VNAS controller feed)
if ($detected_role === 'OBSERVER') {
    $controller = VNASService::findByCID($cid_int);
    if ($controller) {
        $detected_role = 'ATC';
        $detected_context = VNASService::extractContext($controller);
    }
}

// Priority 3: VA context (from request param)
$va_airline = $_GET['va_airline'] ?? null;
if ($detected_role === 'OBSERVER' && $va_airline) {
    $detected_role = 'VA';
    $detected_context = ['airline_icao' => strtoupper($va_airline)];
}

// Priority 4: Pilot (CID in adl_flight_core)
if ($detected_role === 'OBSERVER') {
    global $conn_adl;
    if ($conn_adl) {
        $sql = "SELECT TOP 1 flight_key AS gufi, callsign
                FROM dbo.adl_flight_core
                WHERE cid = ? AND is_active = 1
                ORDER BY inserted_utc DESC";
        $pilot_stmt = sqlsrv_query($conn_adl, $sql, [$cid_int]);
        if ($pilot_stmt) {
            $pilot_row = sqlsrv_fetch_array($pilot_stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($pilot_stmt);
            if ($pilot_row) {
                $detected_role = 'PILOT';
                $detected_context = [
                    'flight_gufi' => $pilot_row['gufi'],
                    'callsign' => $pilot_row['callsign'],
                ];
            }
        }
    }
}

// ---- Handle role override ----

$override_role = strtoupper($_GET['override_role'] ?? '');
$effective_role = $detected_role;
$effective_context = $detected_context;
$is_override = false;

if ($override_role && isset($ROLE_CAPS[$override_role]) && $override_role !== $detected_role) {
    // TMU users can assume any role; others can only pick roles at or below their detected level
    $detected_rank = $ROLE_RANK[$detected_role] ?? 0;
    $override_rank = $ROLE_RANK[$override_role] ?? 0;

    if ($is_tmu || $override_rank <= $detected_rank) {
        $effective_role = $override_role;
        $is_override = true;
        // VA override needs airline context
        if ($override_role === 'VA' && $va_airline) {
            $effective_context = ['airline_icao' => strtoupper($va_airline)];
        } elseif ($override_role !== $detected_role) {
            $effective_context = [];
        }
    }
}

// Build list of roles this user is allowed to select
$allowed_roles = [];
$detected_rank = $ROLE_RANK[$detected_role] ?? 0;
foreach ($ROLE_RANK as $role => $rank) {
    if ($is_tmu || $rank <= $detected_rank) {
        $allowed_roles[] = $role;
    }
}

rad_respond_json(200, [
    'status' => 'ok',
    'data' => [
        'role' => $effective_role,
        'detected_role' => $detected_role,
        'is_override' => $is_override,
        'allowed_roles' => $allowed_roles,
        'cid' => $cid_int,
        'context' => $effective_context,
        'capabilities' => $ROLE_CAPS[$effective_role],
    ],
]);
