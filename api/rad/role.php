<?php
/** RAD API: Role Detection — GET /api/rad/role.php */
define('RAD_API_INCLUDED', true);
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../../load/services/VNASService.php';

$cid = rad_require_auth();
$cid_int = (int)$cid;

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
    rad_respond_json(200, [
        'status' => 'ok',
        'data' => [
            'role' => 'TMU',
            'cid' => $cid_int,
            'context' => [],
            'capabilities' => [
                'can_create_amendment' => true,
                'can_issue' => true,
                'can_accept_reject' => false,
                'can_submit_tos' => false,
                'can_resolve_tos' => true,
                'can_force' => true,
                'tabs' => ['search', 'detail', 'edit', 'monitoring'],
            ],
        ],
    ]);
}

// Priority 2: ATC (VNAS controller feed)
$controller = VNASService::findByCID($cid_int);
if ($controller) {
    $ctx = VNASService::extractContext($controller);
    rad_respond_json(200, [
        'status' => 'ok',
        'data' => [
            'role' => 'ATC',
            'cid' => $cid_int,
            'context' => $ctx,
            'capabilities' => [
                'can_create_amendment' => false,
                'can_issue' => true,
                'can_accept_reject' => true,
                'can_submit_tos' => true,
                'can_resolve_tos' => false,
                'can_force' => false,
                'tabs' => ['detail', 'monitoring'],
            ],
        ],
    ]);
}

// Priority 3: Check for VA role override (from request param)
$va_airline = $_GET['va_airline'] ?? null;
if ($va_airline) {
    rad_respond_json(200, [
        'status' => 'ok',
        'data' => [
            'role' => 'VA',
            'cid' => $cid_int,
            'context' => ['airline_icao' => strtoupper($va_airline)],
            'capabilities' => [
                'can_create_amendment' => false,
                'can_issue' => true,
                'can_accept_reject' => true,
                'can_submit_tos' => true,
                'can_resolve_tos' => false,
                'can_force' => false,
                'tabs' => ['detail', 'monitoring'],
            ],
        ],
    ]);
}

// Priority 4: Pilot (CID in adl_flight_core)
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
            rad_respond_json(200, [
                'status' => 'ok',
                'data' => [
                    'role' => 'PILOT',
                    'cid' => $cid_int,
                    'context' => [
                        'flight_gufi' => $pilot_row['gufi'],
                        'callsign' => $pilot_row['callsign'],
                    ],
                    'capabilities' => [
                        'can_create_amendment' => false,
                        'can_issue' => false,
                        'can_accept_reject' => true,
                        'can_submit_tos' => true,
                        'can_resolve_tos' => false,
                        'can_force' => false,
                        'tabs' => ['monitoring'],
                    ],
                ],
            ]);
        }
    }
}

// Priority 5: Observer (authenticated but no role)
rad_respond_json(200, [
    'status' => 'ok',
    'data' => [
        'role' => 'OBSERVER',
        'cid' => $cid_int,
        'context' => [],
        'capabilities' => [
            'can_create_amendment' => false,
            'can_issue' => false,
            'can_accept_reject' => false,
            'can_submit_tos' => false,
            'can_resolve_tos' => false,
            'can_force' => false,
            'tabs' => [],
        ],
    ],
]);
