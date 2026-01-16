<?php
/**
 * Azure App Service Health Check Endpoint
 *
 * This file is used by Azure's health probe to verify the container is healthy.
 * It does NOT connect to any database to avoid slow responses causing restart loops.
 *
 * Configure in Azure Portal:
 *   App Service > Configuration > Health check > Path: /healthcheck.php
 */

// Prevent any session/output buffering overhead
if (session_status() == PHP_SESSION_ACTIVE) {
    session_abort();
}

// Set response headers
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

// Check that PHP-FPM is responding (if we got here, it is)
$status = [
    'status' => 'healthy',
    'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
    'php_version' => PHP_VERSION,
    'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2)
];

// Optional: Check if critical files exist
$criticalFiles = [
    __DIR__ . '/load/config.php',
    __DIR__ . '/load/connect.php',
    __DIR__ . '/index.php'
];

$missingFiles = [];
foreach ($criticalFiles as $file) {
    if (!file_exists($file)) {
        $missingFiles[] = basename($file);
    }
}

if (!empty($missingFiles)) {
    $status['status'] = 'degraded';
    $status['missing_files'] = $missingFiles;
}

// Return 200 OK for healthy/degraded, let Azure know we're alive
http_response_code(200);
echo json_encode($status);
