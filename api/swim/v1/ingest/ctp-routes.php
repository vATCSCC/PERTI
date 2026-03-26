<?php
/**
 * VATSWIM API v1 — CTP Route Planner → Playbook Sync (Push Endpoint)
 *
 * Full-state sync: CTP sends complete route set after every save.
 * Delegates to CTPPlaybookSync service for the actual sync algorithm.
 *
 * POST /api/swim/v1/ingest/ctp-routes.php
 *
 * @version 2.0.0
 */

// ── Bootstrap ─────────────────────────────────────────────────────────
// Load MySQL + PostGIS BEFORE SWIM auth (auth.php would set PERTI_SWIM_ONLY)
require_once __DIR__ . '/../../../../load/config.php';
require_once __DIR__ . '/../../../../load/connect.php';
require_once __DIR__ . '/../../../../load/services/CTPPlaybookSync.php';
require_once __DIR__ . '/../auth.php';

// ── Auth ──────────────────────────────────────────────────────────────
$auth = swim_init_auth(true, true);
if (!$auth->canWriteField('ctp')) {
    SwimResponse::error('API key lacks CTP field write authority', 403, 'FORBIDDEN');
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') { SwimResponse::handlePreflight(); }
if ($method !== 'POST') {
    SwimResponse::error('Method not allowed', 405, 'METHOD_NOT_ALLOWED');
}

// ── Validate payload ──────────────────────────────────────────────────
$body = swim_get_json_body();
if (!$body || !is_array($body)) {
    SwimResponse::error('Invalid JSON body', 400, 'INVALID_JSON');
}

$session_id     = (int)($body['session_id'] ?? 0);
$revision       = (int)($body['revision'] ?? 0);
$group_mapping  = $body['group_mapping'] ?? null;
$routes_in      = $body['routes'] ?? null;
$changed_by_cid = $body['changed_by_cid'] ?? null;
$event_code     = trim($body['event_code'] ?? CTP_EVENT_CODE);

if ($session_id <= 0) SwimResponse::error('session_id is required (positive integer)', 400, 'MISSING_PARAM');
if ($revision <= 0)   SwimResponse::error('revision is required (positive integer)', 400, 'MISSING_PARAM');
if (!is_array($group_mapping) || empty($group_mapping))
    SwimResponse::error('group_mapping object is required', 400, 'MISSING_PARAM');
if (!is_array($routes_in))
    SwimResponse::error('routes array is required', 400, 'MISSING_PARAM');
if (count($routes_in) > 1000)
    SwimResponse::error('Maximum 1000 routes per request', 400, 'BATCH_LIMIT');
if ($event_code === '')
    SwimResponse::error('event_code is required (in payload or CTP_EVENT_CODE config)', 400, 'MISSING_PARAM');

$valid_scopes = ['full', 'na', 'eu', 'oca'];
foreach ($group_mapping as $grp => $scp) {
    if (!in_array(strtolower($scp), $valid_scopes))
        SwimResponse::error("Invalid scope '{$scp}' in group_mapping. Valid: " . implode(', ', $valid_scopes), 400, 'INVALID_PARAM');
}

foreach ($routes_in as $idx => $r) {
    if (empty($r['identifier']))  SwimResponse::error("Route[$idx]: identifier is required", 400, 'INVALID_ROUTE');
    if (!isset($r['group']))      SwimResponse::error("Route[$idx]: group is required", 400, 'INVALID_ROUTE');
    if (empty($r['routestring'])) SwimResponse::error("Route[$idx]: routestring is required", 400, 'INVALID_ROUTE');
}

// ── Delegate to sync service ─────────────────────────────────────────
global $conn_sqli;

$result = CTPPlaybookSync::run(
    $conn_sqli,
    $routes_in,
    $event_code,
    $session_id,
    $revision,
    $group_mapping,
    $changed_by_cid ? (string)$changed_by_cid : null,
    false  // skip_revision_check = false (push uses revision-based idempotency)
);

SwimResponse::success($result);
