<?php
/** RAD API: Amendment CRUD — GET/POST/DELETE /api/rad/amendment.php */
define('RAD_API_INCLUDED', true);
require_once __DIR__ . '/common.php';

$cid = rad_require_auth();
$svc = rad_get_service();
$method = $_SERVER['REQUEST_METHOD'];
$body = rad_read_payload();
$action = $body['action'] ?? $_GET['action'] ?? null;

// POST and DELETE operations: TMU required by default, role-aware for specific actions
if ($method === 'POST' || $method === 'DELETE') {
    $role_actions = ['issue', 'accept', 'reject', 'revert'];
    if (!in_array($action, $role_actions)) {
        rad_require_tmu($cid);
    }
}

if ($method === 'GET') {
    $filters = [
        'gufi'           => $_GET['gufi'] ?? null,
        'status'         => $_GET['status'] ?? null,
        'tmi_reroute_id' => $_GET['tmi_reroute_id'] ?? null,
        'page'           => $_GET['page'] ?? 1,
        'limit'          => $_GET['limit'] ?? 50,
    ];
    $result = $svc->getAmendments($filters);
    rad_respond_json(200, ['status' => 'ok', 'data' => $result]);

} elseif ($method === 'POST') {

    // ---- Batch operations: POST with ids[] array ----
    $ids = $body['ids'] ?? null;
    if (is_array($ids) && !empty($ids) && in_array($action, ['send', 'issue', 'accept', 'reject', 'revert', 'cancel'])) {
        // Role checks for batch
        if ($action === 'issue') {
            $role = rad_require_role((int)$cid, ['TMU', 'ATC', 'VA']);
        } elseif ($action === 'accept') {
            $role = rad_require_role((int)$cid, ['TMU', 'ATC', 'VA', 'PILOT']);
        } elseif ($action === 'reject') {
            $role = rad_require_role((int)$cid, ['ATC', 'VA', 'PILOT']);
        } elseif ($action === 'revert') {
            $role = rad_require_role((int)$cid, ['TMU', 'ATC', 'VA']);
        }

        $results = [];
        $errors = [];
        foreach ($ids as $rawId) {
            $batchId = (int)$rawId;
            if (!$batchId) continue;
            $r = null;
            if ($action === 'send') $r = $svc->sendAmendment($batchId, (int)$cid);
            elseif ($action === 'issue') $r = $svc->issueAmendment($batchId, (int)$cid, $role);
            elseif ($action === 'accept') $r = $svc->acceptAmendment($batchId, (int)$cid, $role);
            elseif ($action === 'reject') $r = $svc->rejectAmendment($batchId, (int)$cid, $role, false);
            elseif ($action === 'revert') $r = $svc->revertAmendment($batchId, (int)$cid, $role);
            elseif ($action === 'cancel') $r = $svc->cancelAmendment($batchId, (int)$cid);

            if ($r && isset($r['error'])) {
                $errors[] = $batchId . ': ' . $r['error'];
            } else {
                $results[] = $batchId;
            }
        }
        if (!empty($errors) && empty($results)) {
            rad_respond_json(400, ['status' => 'error', 'message' => implode('; ', $errors)]);
        }
        rad_respond_json(200, ['status' => 'ok', 'data' => ['processed' => $results, 'errors' => $errors]]);
    }

    // ---- Single-item operations ----
    if ($action === 'send' && !empty($body['id'])) {
        // Send an existing DRAFT amendment by ID
        $id = (int)$body['id'];
        $result = $svc->sendAmendment($id, (int)$cid);
        if (isset($result['error'])) rad_respond_json(400, ['status' => 'error', 'message' => $result['error']]);
        rad_respond_json(200, ['status' => 'ok', 'data' => $result]);

    } elseif ($action === 'resend') {
        $id = (int)($body['id'] ?? 0);
        if (!$id) rad_respond_json(400, ['status' => 'error', 'message' => 'id required']);
        $result = $svc->resendAmendment($id, (int)$cid);
        if (isset($result['error'])) rad_respond_json(400, ['status' => 'error', 'message' => $result['error']]);
        rad_respond_json(200, ['status' => 'ok', 'data' => $result]);

    } elseif ($action === 'cancel') {
        $id = (int)($body['id'] ?? 0);
        if (!$id) rad_respond_json(400, ['status' => 'error', 'message' => 'id required']);
        $result = $svc->cancelAmendment($id, (int)$cid);
        if (isset($result['error'])) rad_respond_json(400, ['status' => 'error', 'message' => $result['error']]);
        rad_respond_json(200, ['status' => 'ok', 'data' => $result]);

    } elseif ($action === 'issue') {
        $id = (int)($body['id'] ?? 0);
        if (!$id) rad_respond_json(400, ['status' => 'error', 'message' => 'id required']);
        $role = rad_require_role((int)$cid, ['TMU', 'ATC', 'VA']);
        $result = $svc->issueAmendment($id, (int)$cid, $role);
        if (isset($result['error'])) rad_respond_json(400, ['status' => 'error', 'message' => $result['error']]);
        rad_respond_json(200, ['status' => 'ok', 'data' => $result]);

    } elseif ($action === 'accept') {
        $id = (int)($body['id'] ?? 0);
        if (!$id) rad_respond_json(400, ['status' => 'error', 'message' => 'id required']);
        $role = rad_require_role((int)$cid, ['TMU', 'ATC', 'VA', 'PILOT']);
        $result = $svc->acceptAmendment($id, (int)$cid, $role);
        if (isset($result['error'])) rad_respond_json(400, ['status' => 'error', 'message' => $result['error']]);
        rad_respond_json(200, ['status' => 'ok', 'data' => $result]);

    } elseif ($action === 'reject') {
        $id = (int)($body['id'] ?? 0);
        if (!$id) rad_respond_json(400, ['status' => 'error', 'message' => 'id required']);
        $role = rad_require_role((int)$cid, ['ATC', 'VA', 'PILOT']);
        $with_tos = !empty($body['with_tos']);
        $result = $svc->rejectAmendment($id, (int)$cid, $role, $with_tos);
        if (isset($result['error'])) rad_respond_json(400, ['status' => 'error', 'message' => $result['error']]);
        rad_respond_json(200, ['status' => 'ok', 'data' => $result]);

    } elseif ($action === 'revert') {
        $id = (int)($body['id'] ?? 0);
        if (!$id) rad_respond_json(400, ['status' => 'error', 'message' => 'id required']);
        $role = rad_require_role((int)$cid, ['TMU', 'ATC', 'VA']);
        $result = $svc->revertAmendment($id, (int)$cid, $role);
        if (isset($result['error'])) rad_respond_json(400, ['status' => 'error', 'message' => $result['error']]);
        rad_respond_json(200, ['status' => 'ok', 'data' => $result]);

    } else {
        // Create new amendment — accept both JS and direct field names
        $route  = $body['route'] ?? $body['assigned_route'] ?? null;
        $routes = $body['routes'] ?? null;  // Per-flight: { gufi: route_string }

        // JS sends flights[] (array of GUFIs) or gufi (single)
        $gufis = [];
        if (!empty($body['flights']) && is_array($body['flights'])) {
            $gufis = $body['flights'];
        } elseif (!empty($body['gufi'])) {
            $gufis = [$body['gufi']];
        }

        if (empty($gufis) || (!$route && empty($routes))) {
            rad_respond_json(400, ['status' => 'error', 'message' => 'flights/gufi and route/routes required']);
        }

        // Channels: accept array or comma-separated string
        $channels = $body['channels'] ?? $body['delivery_channels'] ?? 'CPDLC,SWIM';
        if (is_array($channels)) $channels = implode(',', $channels);

        $options = [
            'delivery_channels'  => $channels,
            'tmi_reroute_id'     => $body['tmi_reroute_id'] ?? null,
            'tmi_id_label'       => $body['tmi_id'] ?? $body['tmi_id_label'] ?? null,
            'route_color'        => $body['route_color'] ?? null,
            'notes'              => $body['notes'] ?? null,
            'send'               => ($action === 'send') || !empty($body['send']),
            'created_by'         => (int)$cid,
            'clearance_text'     => $body['clearance_text'] ?? null,
            'clearance_segments' => $body['clearance_segments'] ?? null,
            'closing_phrase'     => $body['closing_phrase'] ?? null,
        ];

        // Create amendment for each GUFI
        $results = [];
        $errors = [];
        foreach ($gufis as $gufi) {
            // Resolve per-flight route (substring replace) or fall back to single route
            $assigned_route = null;
            if (is_array($routes) && isset($routes[$gufi])) {
                $assigned_route = $routes[$gufi];
            } elseif ($route) {
                $assigned_route = $route;
            }
            if (!$assigned_route) {
                $errors[] = $gufi . ': no route specified';
                continue;
            }
            $result = $svc->createAmendment($gufi, $assigned_route, $options);
            if (isset($result['error'])) {
                $errors[] = $gufi . ': ' . $result['error'];
            } else {
                $results[] = $result;
            }
        }

        if (!empty($errors) && empty($results)) {
            rad_respond_json(400, ['status' => 'error', 'message' => implode('; ', $errors)]);
        }
        rad_respond_json(201, ['status' => 'ok', 'data' => $results, 'errors' => $errors]);
    }

} elseif ($method === 'DELETE') {
    // Batch delete: ids[] array or single id
    $ids = $body['ids'] ?? null;
    if (is_array($ids) && !empty($ids)) {
        $results = [];
        $errors = [];
        foreach ($ids as $rawId) {
            $delId = (int)$rawId;
            if (!$delId) continue;
            $r = $svc->cancelAmendment($delId, (int)$cid);
            if (isset($r['error'])) {
                $errors[] = $delId . ': ' . $r['error'];
            } else {
                $results[] = $delId;
            }
        }
        if (!empty($errors) && empty($results)) {
            rad_respond_json(400, ['status' => 'error', 'message' => implode('; ', $errors)]);
        }
        rad_respond_json(200, ['status' => 'ok', 'data' => ['deleted' => $results, 'errors' => $errors]]);
    }

    $id = (int)($_GET['id'] ?? $body['id'] ?? 0);
    if (!$id) rad_respond_json(400, ['status' => 'error', 'message' => 'id or ids[] required']);
    $result = $svc->cancelAmendment($id, (int)$cid);
    if (isset($result['error'])) rad_respond_json(400, ['status' => 'error', 'message' => $result['error']]);
    rad_respond_json(200, ['status' => 'ok', 'data' => $result]);

} else {
    rad_respond_json(405, ['status' => 'error', 'message' => 'Method not allowed']);
}
