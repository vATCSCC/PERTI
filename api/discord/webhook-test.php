<?php
/**
 * Discord Webhook Diagnostic Endpoint
 *
 * Use this to check if your server is properly configured for Discord webhooks.
 * DELETE THIS FILE after setup is complete for security.
 */

header('Content-Type: application/json');

$config_path = realpath(__DIR__ . '/../../load/config.php');
if ($config_path) require_once($config_path);

$diagnostics = [
    'timestamp' => date('c'),
    'checks' => []
];

// Check 1: PHP Version
$diagnostics['checks']['php_version'] = [
    'status' => version_compare(PHP_VERSION, '7.2.0', '>=') ? 'OK' : 'FAIL',
    'value' => PHP_VERSION,
    'required' => '7.2.0+'
];

// Check 2: Sodium Extension
$sodiumAvailable = function_exists('sodium_crypto_sign_verify_detached');
$diagnostics['checks']['sodium_extension'] = [
    'status' => $sodiumAvailable ? 'OK' : 'FAIL',
    'value' => $sodiumAvailable ? 'Enabled' : 'Not available',
    'required' => 'Required for Ed25519 signature verification'
];

// Check 3: DISCORD_PUBLIC_KEY configured
$publicKeySet = defined('DISCORD_PUBLIC_KEY') && !empty(DISCORD_PUBLIC_KEY);
$publicKeyLength = $publicKeySet ? strlen(DISCORD_PUBLIC_KEY) : 0;
$diagnostics['checks']['discord_public_key'] = [
    'status' => $publicKeySet ? 'OK' : 'FAIL',
    'value' => $publicKeySet ? "Configured ({$publicKeyLength} chars)" : 'Not set or empty',
    'required' => 'Required - get from Discord Developer Portal > Your App > General Information'
];

// Check 4: Public key format (should be 64 hex chars)
if ($publicKeySet) {
    $validFormat = preg_match('/^[a-fA-F0-9]{64}$/', DISCORD_PUBLIC_KEY);
    $diagnostics['checks']['public_key_format'] = [
        'status' => $validFormat ? 'OK' : 'FAIL',
        'value' => $validFormat ? 'Valid (64 hex characters)' : "Invalid format ({$publicKeyLength} chars, expected 64 hex)",
        'required' => '64 hexadecimal characters'
    ];
}

// Check 5: Request headers (what Discord would send)
$diagnostics['checks']['request_method'] = [
    'status' => 'INFO',
    'value' => $_SERVER['REQUEST_METHOD'],
    'note' => 'Discord sends POST requests'
];

$signatureHeader = $_SERVER['HTTP_X_SIGNATURE_ED25519'] ?? null;
$timestampHeader = $_SERVER['HTTP_X_SIGNATURE_TIMESTAMP'] ?? null;

$diagnostics['checks']['signature_headers'] = [
    'status' => 'INFO',
    'x_signature_ed25519' => $signatureHeader ? 'Present (' . strlen($signatureHeader) . ' chars)' : 'Not present',
    'x_signature_timestamp' => $timestampHeader ? 'Present' : 'Not present',
    'note' => 'These headers are sent by Discord with each request'
];

// Overall status
$allOk = true;
foreach ($diagnostics['checks'] as $check) {
    if (isset($check['status']) && $check['status'] === 'FAIL') {
        $allOk = false;
        break;
    }
}

$diagnostics['overall_status'] = $allOk ? 'READY' : 'NOT_READY';
$diagnostics['message'] = $allOk
    ? 'Server appears ready for Discord webhook verification'
    : 'Some checks failed - see details above';

// Instructions if not ready
if (!$allOk) {
    $diagnostics['instructions'] = [];

    if (!$sodiumAvailable) {
        $diagnostics['instructions'][] = 'Enable the sodium PHP extension on your server (usually: extension=sodium in php.ini)';
    }

    if (!$publicKeySet) {
        $diagnostics['instructions'][] = 'Add DISCORD_PUBLIC_KEY to your config.php - find it in Discord Developer Portal > Your App > General Information > Public Key';
    }
}

echo json_encode($diagnostics, JSON_PRETTY_PRINT);
