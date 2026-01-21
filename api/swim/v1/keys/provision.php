<?php
/**
 * VATSWIM API Key Provisioning Endpoint
 *
 * Auto-provisions API keys for pilot clients after VATSIM OAuth authentication.
 * Creates developer-tier keys tied to the pilot's CID.
 *
 * POST /api/swim/v1/keys/provision
 *
 * Request body:
 *   - access_token: VATSIM OAuth access token (required)
 *   - client_name: Name of the requesting client application (optional)
 *   - client_version: Version of the requesting client (optional)
 *
 * Response:
 *   - api_key: The provisioned API key
 *   - tier: Key tier (always 'developer')
 *   - rate_limit: Requests per minute allowed
 *   - expires_at: Key expiration date (null for non-expiring)
 *   - cid: VATSIM CID the key is tied to
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
$client_name = trim($body['client_name'] ?? 'Unknown Client');
$client_version = trim($body['client_version'] ?? '1.0.0');

// Validate token with VATSIM Connect API
$vatsim_user = validateVatsimToken($access_token);

if (!$vatsim_user) {
    SwimResponse::error('Invalid or expired VATSIM OAuth token', 401, 'INVALID_TOKEN');
}

$cid = $vatsim_user['cid'];
$pilot_name = $vatsim_user['personal']['name_full'] ?? $vatsim_user['personal']['name_first'] ?? 'Unknown';
$pilot_email = $vatsim_user['personal']['email'] ?? null;
$pilot_rating = $vatsim_user['vatsim']['rating']['short'] ?? 'P0';

// Check if key already exists for this CID and client
global $conn_swim, $conn_adl;
$conn = $conn_swim ?: $conn_adl;

if (!$conn) {
    SwimResponse::error('Database connection not available', 503, 'SERVICE_UNAVAILABLE');
}

$existing_key = getExistingKey($conn, $cid, $client_name);

if ($existing_key) {
    // Return existing key if still active and not expired
    if ($existing_key['is_active'] && (!$existing_key['expires_at'] || strtotime($existing_key['expires_at']) > time())) {
        // Update last_used_at
        @sqlsrv_query($conn,
            "UPDATE dbo.swim_api_keys SET last_used_at = GETUTCDATE() WHERE id = ?",
            [$existing_key['id']]);

        SwimResponse::success([
            'api_key' => $existing_key['api_key'],
            'tier' => $existing_key['tier'],
            'rate_limit' => swim_get_rate_limit($existing_key['tier']),
            'expires_at' => $existing_key['expires_at'] ? date('c', strtotime($existing_key['expires_at'])) : null,
            'cid' => $cid,
            'pilot_name' => $pilot_name,
            'pilot_rating' => $pilot_rating,
            'created_at' => date('c', strtotime($existing_key['created_at'])),
            'is_new' => false
        ], ['message' => 'Existing API key returned']);
    }

    // Existing key is expired/inactive - deactivate it and create new one
    @sqlsrv_query($conn, "UPDATE dbo.swim_api_keys SET is_active = 0 WHERE id = ?", [$existing_key['id']]);
}

// Generate new API key
$api_key = generateApiKey('developer');

// Set expiration (developer keys expire after 1 year)
$expires_at = date('Y-m-d H:i:s', strtotime('+1 year'));

// Insert new key
$sql = "INSERT INTO dbo.swim_api_keys
        (api_key, tier, owner_name, owner_email, owner_cid, source_id, can_write,
         client_name, client_version, ip_whitelist, expires_at, created_at, is_active)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETUTCDATE(), 1)";

$params = [
    $api_key,
    'developer',
    $pilot_name,
    $pilot_email,
    $cid,
    'pilot_client',  // Source identifier for pilot client submissions
    1,               // Allow write access for flight data submission
    $client_name,
    $client_version,
    null,            // No IP whitelist for developer keys
    $expires_at
];

$result = sqlsrv_query($conn, $sql, $params);

if ($result === false) {
    error_log('SWIM Key Provision: Database insert failed - ' . print_r(sqlsrv_errors(), true));
    SwimResponse::error('Failed to provision API key', 500, 'PROVISION_FAILED');
}

// Log the provisioning event
logProvisionEvent($conn, $cid, $client_name, $api_key);

SwimResponse::success([
    'api_key' => $api_key,
    'tier' => 'developer',
    'rate_limit' => swim_get_rate_limit('developer'),
    'expires_at' => date('c', strtotime($expires_at)),
    'cid' => $cid,
    'pilot_name' => $pilot_name,
    'pilot_rating' => $pilot_rating,
    'created_at' => gmdate('c'),
    'is_new' => true
], ['message' => 'New API key provisioned successfully']);

/**
 * Validate VATSIM OAuth access token
 *
 * @param string $access_token OAuth access token
 * @return array|null User data if valid, null otherwise
 */
function validateVatsimToken($access_token) {
    // VATSIM Connect API user info endpoint
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
 * Get existing API key for CID and client
 *
 * @param resource $conn Database connection
 * @param string $cid VATSIM CID
 * @param string $client_name Client application name
 * @return array|null Key data if found
 */
function getExistingKey($conn, $cid, $client_name) {
    $sql = "SELECT id, api_key, tier, owner_name, owner_email, expires_at, created_at, is_active
            FROM dbo.swim_api_keys
            WHERE owner_cid = ? AND client_name = ? AND tier = 'developer'
            ORDER BY created_at DESC";

    $stmt = sqlsrv_query($conn, $sql, [$cid, $client_name]);

    if ($stmt === false) {
        return null;
    }

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    return $row ?: null;
}

/**
 * Generate a new API key
 *
 * @param string $tier Key tier (system, partner, developer, public)
 * @return string Generated API key
 */
function generateApiKey($tier) {
    global $SWIM_KEY_PREFIXES;

    $prefix = $SWIM_KEY_PREFIXES[$tier] ?? 'swim_dev_';

    // Generate 32 bytes of random data, encode as hex (64 chars)
    $random = bin2hex(random_bytes(32));

    return $prefix . $random;
}

/**
 * Log API key provisioning event
 *
 * @param resource $conn Database connection
 * @param string $cid VATSIM CID
 * @param string $client_name Client name
 * @param string $api_key Generated key (masked for logging)
 */
function logProvisionEvent($conn, $cid, $client_name, $api_key) {
    // Mask the key for logging (show first 12 chars only)
    $masked_key = substr($api_key, 0, 12) . '****';

    $sql = "INSERT INTO dbo.swim_audit_log
            (api_key_id, endpoint, method, ip_address, user_agent, request_time, details)
            VALUES (NULL, '/keys/provision', 'POST', ?, ?, GETUTCDATE(), ?)";

    $details = json_encode([
        'cid' => $cid,
        'client_name' => $client_name,
        'key_prefix' => $masked_key,
        'action' => 'key_provisioned'
    ]);

    @sqlsrv_query($conn, $sql, [
        $_SERVER['REMOTE_ADDR'] ?? '',
        $_SERVER['HTTP_USER_AGENT'] ?? '',
        $details
    ]);
}
