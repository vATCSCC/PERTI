<?php
/**
 * VATSWIM Hoppie CPDLC Bridge
 *
 * Polls Hoppie ACARS for CPDLC messages and syncs to VATSWIM.
 * Run via cron every 30 seconds.
 *
 * @package VATSWIM
 * @subpackage Hoppie CPDLC Integration
 * @version 1.0.0
 */

// Configuration
$config = [
    'hoppie_logon' => getenv('HOPPIE_LOGON') ?: 'your_hoppie_logon',
    'hoppie_callsign' => getenv('HOPPIE_CALLSIGN') ?: 'VATCSCC',
    'vatswim_api_key' => getenv('VATSWIM_API_KEY') ?: 'your_vatswim_api_key',
    'vatswim_base_url' => getenv('VATSWIM_BASE_URL') ?: 'https://perti.vatcscc.org/api/swim/v1',
    'verbose' => getenv('VATSWIM_VERBOSE') === 'true'
];

// Autoload
spl_autoload_register(function ($class) {
    $prefix = 'VatSwim\\Hoppie\\';
    $baseDir = __DIR__ . '/src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) return;

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) require $file;
});

use VatSwim\Hoppie\HoppieClient;
use VatSwim\Hoppie\CPDLCParser;
use VatSwim\Hoppie\SWIMUpdater;

// Initialize
$hoppie = new HoppieClient($config['hoppie_logon'], $config['hoppie_callsign']);
$parser = new CPDLCParser();
$updater = new SWIMUpdater($config['vatswim_api_key'], $config['vatswim_base_url']);
$updater->setVerbose($config['verbose']);

// Poll for messages
echo "[" . date('Y-m-d H:i:s') . "] Polling Hoppie ACARS...\n";

$messages = $hoppie->poll();
$stats = ['received' => count($messages), 'parsed' => 0, 'synced' => 0, 'errors' => 0];

foreach ($messages as $message) {
    $clearance = $parser->parse($message);

    if ($clearance) {
        $stats['parsed']++;

        if ($updater->submitClearance($clearance)) {
            $stats['synced']++;
            echo "  Synced clearance for " . ($clearance['callsign'] ?? 'unknown') . "\n";
        } else {
            $stats['errors']++;
        }
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Complete: {$stats['received']} received, {$stats['parsed']} parsed, {$stats['synced']} synced\n";
