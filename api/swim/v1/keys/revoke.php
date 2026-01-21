<?php
/**
 * VATSWIM API Key Revocation Endpoint
 *
 * Allows users to revoke their own API keys.
 *
 * POST /api/swim/v1/keys/revoke
 *
 * Request body:
 *   - access_token: VATSIM OAuth access token (required)
 *   - api_key: The API key to revoke (optional - revokes all if not specified)
 *   - client_name: Revoke keys for specific client only (optional)
 *
 * @package PERTI
 * @subpackage SWIM
 * @version 1.0.0
 */

// Load PERTI core
define('PERTI_LOADED', true);
require_once __DIR__ . '/../../../../load/config.php';
require_once __DIR__ . '/../../../../load/connect.php';
require_once __DIR__ . '/../../../../load/swim_config.php';
require_once __DIR__ . '/../auth.php';

// Handle CORS preflight
SwimResponse::handlePreflight();

// Only allow POST requests
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    SwimResponse::error('Method not allowed. Use POST.', 405, 'METHOD_NOT_ALLOWED');
}

// Get request body
$body = swim_get_json_body();

if (!$body || empty($body['access_token'])) {
    SwimResponse::error('Missing required field: access_token', 400, 'MISSING_TOKEN');
}

$access_token = trim($body['access_token']);
$target_key = isset($body['api_key']) ? trim($body['api_key']) : null;
$client_name = isset($body['client_name']) ? trim($body['client_name']) : null;

// Validate token with VATSIM Connect API
$vatsim_user = validateVatsimToken($access_token);

if (!$vatsim_user) {
    SwimResponse::error('Invalid or expired VATSIM OAuth token', 401, 'INVALID_TOKEN');
}

$cid = $vatsim_user['cid'];

global $conn_swim, $conn_adl;
$conn = $conn_swim ?: $conn_adl;

if (!$conn) {
    SwimResponse::error('Database connection not available', 503, 'SERVICE_UNAVAILABLE');
}

// Build the revocation query
$conditions = ['owner_cid = ?', 'is_active = 1'];
$params = [$cid];

if ($target_key) {
    $conditions[] = 'api_key = ?';
    $params[] = $target_key;
}

if ($client_name) {
    $conditions[] = 'client_name = ?';
    $params[] = $client_name;
}

$where = implode(' AND ', $conditions);

// Count keys to be revoked
$count_sql = "SELECT COUNT(*) as cnt FROM dbo.swim_api_keys WHERE $where";
$count_stmt = sqlsrv_query($conn, $count_sql, $params);

if ($count_stmt === false) {
    SwimResponse::error('Database query failed', 500, 'DB_ERROR');
}

$count_row = sqlsrv_fetch_array($count_stmt, SQLSRV_FETCH_ASSOC);
sqlsrv_free_stmt($count_stmt);
$revoke_count = $count_row['cnt'] ?? 0;

if ($revoke_count === 0) {
    SwimResponse::error('No active API keys found matching criteria', 404, 'NO_KEYS_FOUND');
}

// Revoke the keys
$revoke_sql = "UPDATE dbo.swim_api_keys SET is_active = 0, revoked_at = GETUTCDATE() WHERE $where";
$revoke_result = sqlsrv_query($conn, $revoke_sql, $params);

if ($revoke_result === false) {
    error_log('SWIM Key Revoke: Database update failed - ' . print_r(sqlsrv_errors(), true));
    SwimResponse::error('Failed to revoke API keys', 500, 'REVOKE_FAILED');
}

// Log the revocation event
logRevocationEvent($conn, $cid, $target_key, $client_name, $revoke_count);

SwimResponse::success([
    'revoked_count' => $revoke_count,
    'cid' => $cid,
    'target_key' => $target_key ? substr($target_key, 0, 12) . '****' : null,
    'client_name' => $client_name
], ['message' => "Successfully revoked $revoke_count API key(s)"]);

/**
 * Validate VATSIM OAuth access token
 */
function validateVatsimToken($access_token) {
    $url = 'https://auth.vatsim.net/api/user';

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $access_token,
            'Accept: application/json'
        ],
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200 || empty($response)) {
        return null;
    }

    $data = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE || !isset($data['data']['cid'])) {
        return null;
    }

    return $data['data'];
}

/**
 * Log API key revocation event
 */
function logRevocationEvent($conn, $cid, $target_key, $client_name, $count) {
    $sql = "INSERT INTO dbo.swim_audit_log
            (api_key_id, endpoint, method, ip_address, user_agent, request_time, details)
            VALUES (NULL, '/keys/revoke', 'POST', ?, ?, GETUTCDATE(), ?)";

    $details = json_encode([
        'cid' => $cid,
        'target_key' => $target_key ? substr($target_key, 0, 12) . '****' : 'all',
        'client_name' => $client_name,
        'revoked_count' => $count,
        'action' => 'keys_revoked'
    ]);

    @sqlsrv_query($conn, $sql, [
        $_SERVER['REMOTE_ADDR'] ?? '',
        $_SERVER['HTTP_USER_AGENT'] ?? '',
        $details
    ]);
}
