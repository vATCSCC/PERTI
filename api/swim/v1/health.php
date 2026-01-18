<?php
/**
 * VATSWIM API Health Check Endpoint
 *
 * Returns health status and metrics for the VATSWIM API infrastructure.
 * Includes database connectivity, sync status, rate limit status, and API stats.
 *
 * Access: Requires valid API key OR localhost access
 *
 * @package PERTI
 * @subpackage SWIM
 * @version 1.0.0
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, no-store, must-revalidate');

// Load dependencies - config.php defines SWIM_SQL_* constants
require_once(__DIR__ . '/../../../load/config.php');

// Simple auth check - allow localhost or require any valid SWIM API key
$isLocalhost = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', 'localhost']);
$apiKey = $_GET['key'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$apiKey = str_replace('Bearer ', '', $apiKey);

// For health check, accept any valid-looking SWIM key or monitoring key
$validKeyFormat = preg_match('/^swim_(sys|par|dev|pub)_/', $apiKey);
$monitoringKey = (defined('MONITORING_API_KEY') && $apiKey === MONITORING_API_KEY);

if (!$isLocalhost && !$validKeyFormat && !$monitoringKey) {
    http_response_code(401);
    echo json_encode([
        'error' => 'Unauthorized',
        'message' => 'Provide a valid SWIM API key or access from localhost'
    ]);
    exit;
}

$health = [
    'api' => 'VATSWIM',
    'version' => '1.0.0',
    'timestamp_utc' => gmdate('Y-m-d\TH:i:s\Z'),
    'status' => 'healthy',
    'checks' => [],
];

// ============================================================================
// 1. SWIM Database Connection
// ============================================================================
$dbCheck = ['status' => 'unknown', 'message' => ''];

try {
    // Use SWIM_SQL_* constants from config.php (canonical source)
    // Fall back to environment variables for Azure App Service deployment
    $serverName = defined('SWIM_SQL_HOST') ? SWIM_SQL_HOST : (getenv('SWIM_DB_SERVER') ?: getenv('DB_SERVER'));
    $database = defined('SWIM_SQL_DATABASE') ? SWIM_SQL_DATABASE : (getenv('SWIM_DB_NAME') ?: 'SWIM_API');
    $uid = defined('SWIM_SQL_USERNAME') ? SWIM_SQL_USERNAME : (getenv('SWIM_DB_USER') ?: getenv('DB_USER'));
    $pwd = defined('SWIM_SQL_PASSWORD') ? SWIM_SQL_PASSWORD : (getenv('SWIM_DB_PASS') ?: getenv('DB_PASS'));

    if (!$serverName || !$uid) {
        $dbCheck['status'] = 'not_configured';
        $dbCheck['message'] = 'Database credentials not configured';
    } else {
        $connInfo = [
            'Database' => $database,
            'UID' => $uid,
            'PWD' => $pwd,
            'LoginTimeout' => 5,
            'Encrypt' => true,
            'TrustServerCertificate' => false,
        ];

        $startTime = microtime(true);
        $conn = @sqlsrv_connect($serverName, $connInfo);
        $connectTime = round((microtime(true) - $startTime) * 1000);

        if ($conn !== false) {
            // Get flight count
            $sql = "SELECT COUNT(*) AS cnt FROM dbo.swim_flights WHERE is_active = 1";
            $stmt = @sqlsrv_query($conn, $sql);
            $flightCount = 0;
            if ($stmt) {
                $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
                $flightCount = (int)($row['cnt'] ?? 0);
                sqlsrv_free_stmt($stmt);
            }

            // Get last sync time
            $sql = "SELECT MAX(last_sync_utc) AS last_sync FROM dbo.swim_flights";
            $stmt = @sqlsrv_query($conn, $sql);
            $lastSync = null;
            $syncAge = null;
            if ($stmt) {
                $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
                if ($row['last_sync'] instanceof DateTime) {
                    $lastSync = $row['last_sync']->format('Y-m-d\TH:i:s\Z');
                    $syncAge = time() - $row['last_sync']->getTimestamp();
                }
                sqlsrv_free_stmt($stmt);
            }

            sqlsrv_close($conn);

            $dbCheck['status'] = 'healthy';
            $dbCheck['connect_time_ms'] = $connectTime;
            $dbCheck['active_flights'] = $flightCount;
            $dbCheck['last_sync_utc'] = $lastSync;
            $dbCheck['sync_age_seconds'] = $syncAge;

            // Warn if sync is stale (>5 minutes old)
            if ($syncAge !== null && $syncAge > 300) {
                $dbCheck['status'] = 'warning';
                $dbCheck['message'] = 'Sync is stale (last sync ' . round($syncAge / 60) . ' minutes ago)';
            }
        } else {
            $errors = sqlsrv_errors();
            $dbCheck['status'] = 'error';
            $dbCheck['message'] = $errors[0]['message'] ?? 'Connection failed';
            $dbCheck['connect_time_ms'] = $connectTime;
        }
    }
} catch (Exception $e) {
    $dbCheck['status'] = 'error';
    $dbCheck['message'] = $e->getMessage();
}

$health['checks']['database'] = $dbCheck;

// ============================================================================
// 2. API Key Statistics
// ============================================================================
$apiKeyCheck = ['status' => 'unknown'];

try {
    if (isset($conn) && $conn !== false) {
        // Reconnect if needed
    } elseif ($serverName && $uid) {
        $conn = @sqlsrv_connect($serverName, $connInfo);
    }

    if ($conn) {
        // Count active API keys by tier
        $sql = "
            SELECT tier, COUNT(*) AS cnt
            FROM dbo.swim_api_keys
            WHERE is_active = 1
            GROUP BY tier
        ";
        $stmt = @sqlsrv_query($conn, $sql);
        $keysByTier = [];
        if ($stmt) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $keysByTier[$row['tier']] = (int)$row['cnt'];
            }
            sqlsrv_free_stmt($stmt);
        }

        // Count requests in last hour
        $sql = "
            SELECT COUNT(*) AS cnt
            FROM dbo.swim_audit_log
            WHERE created_at > DATEADD(HOUR, -1, GETUTCDATE())
        ";
        $stmt = @sqlsrv_query($conn, $sql);
        $requestsLastHour = 0;
        if ($stmt) {
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            $requestsLastHour = (int)($row['cnt'] ?? 0);
            sqlsrv_free_stmt($stmt);
        }

        $apiKeyCheck['status'] = 'healthy';
        $apiKeyCheck['active_keys_by_tier'] = $keysByTier;
        $apiKeyCheck['requests_last_hour'] = $requestsLastHour;

        sqlsrv_close($conn);
    }
} catch (Exception $e) {
    $apiKeyCheck['status'] = 'error';
    $apiKeyCheck['message'] = $e->getMessage();
}

$health['checks']['api_keys'] = $apiKeyCheck;

// ============================================================================
// 3. WebSocket Server Status
// ============================================================================
$wsCheck = ['status' => 'unknown'];

// Try to connect to WebSocket status endpoint
$wsStatusUrl = 'http://127.0.0.1:8090/status';
$ctx = stream_context_create(['http' => ['timeout' => 2]]);
$wsStatus = @file_get_contents($wsStatusUrl, false, $ctx);

if ($wsStatus !== false) {
    $wsData = json_decode($wsStatus, true);
    $wsCheck['status'] = 'running';
    $wsCheck['active_connections'] = $wsData['connections'] ?? 0;
    $wsCheck['subscriptions'] = $wsData['subscriptions'] ?? 0;
} else {
    $wsCheck['status'] = 'not_running';
    $wsCheck['message'] = 'WebSocket server not responding on port 8090';
}

$health['checks']['websocket'] = $wsCheck;

// ============================================================================
// 4. Cache Status (APCu)
// ============================================================================
$cacheCheck = ['status' => 'unknown'];

if (function_exists('apcu_enabled') && apcu_enabled()) {
    $cacheInfo = apcu_cache_info(true);
    $cacheCheck['status'] = 'enabled';
    $cacheCheck['memory_used_mb'] = round(($cacheInfo['mem_size'] ?? 0) / 1024 / 1024, 2);
    $cacheCheck['entries'] = $cacheInfo['num_entries'] ?? 0;
    $cacheCheck['hits'] = $cacheInfo['num_hits'] ?? 0;
    $cacheCheck['misses'] = $cacheInfo['num_misses'] ?? 0;

    $total = ($cacheCheck['hits'] + $cacheCheck['misses']);
    $cacheCheck['hit_rate'] = $total > 0
        ? round(($cacheCheck['hits'] / $total) * 100, 1) . '%'
        : 'N/A';
} else {
    $cacheCheck['status'] = 'disabled';
    $cacheCheck['message'] = 'APCu not enabled';
}

$health['checks']['cache'] = $cacheCheck;

// ============================================================================
// 5. Rate Limit Status
// ============================================================================
$rateLimitCheck = ['status' => 'healthy', 'tiers' => []];

// Get rate limit config
if (file_exists(__DIR__ . '/../../../load/swim_config.php')) {
    require_once(__DIR__ . '/../../../load/swim_config.php');

    if (isset($SWIM_RATE_LIMITS)) {
        foreach ($SWIM_RATE_LIMITS as $tier => $limit) {
            $rateLimitCheck['tiers'][$tier] = [
                'limit_per_minute' => $limit,
            ];
        }
    }
}

$health['checks']['rate_limits'] = $rateLimitCheck;

// ============================================================================
// Final Status Determination
// ============================================================================
$overallStatus = 'healthy';

foreach ($health['checks'] as $check) {
    if (isset($check['status'])) {
        if ($check['status'] === 'error' || $check['status'] === 'not_configured') {
            $overallStatus = 'unhealthy';
            break;
        } elseif ($check['status'] === 'warning' || $check['status'] === 'not_running') {
            if ($overallStatus !== 'unhealthy') {
                $overallStatus = 'degraded';
            }
        }
    }
}

$health['status'] = $overallStatus;

// Set HTTP status code
if ($overallStatus === 'unhealthy') {
    http_response_code(503);
} elseif ($overallStatus === 'degraded') {
    http_response_code(200); // Still 200 but status shows degraded
}

echo json_encode($health, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
