<?php
/**
 * VATSWIM VAM Cron Sync Script
 *
 * Run this script via cron to sync VAM flights to VATSWIM.
 *
 * Recommended cron schedule:
 *   * * * * * php /path/to/cron_sync.php  # Every minute
 *
 * @package VATSWIM
 * @subpackage VAM Integration
 * @version 1.0.0
 */

// Configuration
$config = [
    // VAM settings
    'vam_base_url' => getenv('VAM_BASE_URL') ?: 'https://your-vam.com',
    'vam_api_key' => getenv('VAM_API_KEY') ?: 'your_vam_api_key',

    // VATSWIM settings
    'vatswim_api_key' => getenv('VATSWIM_API_KEY') ?: 'your_vatswim_api_key',
    'vatswim_base_url' => getenv('VATSWIM_BASE_URL') ?: 'https://perti.vatcscc.org/api/swim/v1',

    // Sync settings
    'sync_active' => true,      // Sync active flights
    'sync_pireps' => true,      // Sync recent PIREPs
    'pirep_hours' => 24,        // Hours of PIREPs to sync

    // Logging
    'verbose' => getenv('VATSWIM_VERBOSE') === 'true'
];

// Autoload classes
spl_autoload_register(function ($class) {
    $prefix = 'VatSwim\\VAM\\';
    $baseDir = __DIR__ . '/src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

use VatSwim\VAM\VAMClient;
use VatSwim\VAM\SWIMClient;
use VatSwim\VAM\FlightSync;

// Initialize clients
$vamClient = new VAMClient($config['vam_base_url'], $config['vam_api_key']);
$swimClient = new SWIMClient($config['vatswim_api_key'], $config['vatswim_base_url']);
$swimClient->setVerbose($config['verbose']);

$flightSync = new FlightSync($vamClient, $swimClient);
$flightSync->setVerbose($config['verbose']);

$totalResults = [
    'active_flights' => null,
    'pireps' => null,
    'timestamp' => gmdate('c')
];

// Sync active flights
if ($config['sync_active']) {
    echo "[" . date('Y-m-d H:i:s') . "] Syncing active flights...\n";
    $totalResults['active_flights'] = $flightSync->syncActiveFlights();
    echo "  Processed: {$totalResults['active_flights']['processed']}, " .
         "Synced: {$totalResults['active_flights']['synced']}, " .
         "Errors: {$totalResults['active_flights']['errors']}\n";
}

// Sync recent PIREPs
if ($config['sync_pireps']) {
    echo "[" . date('Y-m-d H:i:s') . "] Syncing recent PIREPs ({$config['pirep_hours']} hours)...\n";
    $totalResults['pireps'] = $flightSync->syncRecentPireps($config['pirep_hours']);
    echo "  Processed: {$totalResults['pireps']['processed']}, " .
         "Synced: {$totalResults['pireps']['synced']}, " .
         "Errors: {$totalResults['pireps']['errors']}\n";
}

echo "[" . date('Y-m-d H:i:s') . "] Sync complete\n";

// Return results for logging/monitoring
if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json');
    echo json_encode($totalResults);
}
