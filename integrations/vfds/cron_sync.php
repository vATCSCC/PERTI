<?php
/**
 * VATSWIM vFDS Cron Sync
 *
 * Scheduled bidirectional sync between vFDS and VATSWIM.
 *
 * Usage:
 *   * * * * * /usr/bin/php /path/to/cron_sync.php >> /var/log/vatswim-vfds.log 2>&1
 *
 * @package VATSWIM
 * @subpackage vFDS Integration
 * @version 1.0.0
 */

// Load configuration
$config = require __DIR__ . '/config.php';

// Autoload
spl_autoload_register(function ($class) {
    $prefix = 'VatSwim\\VFDS\\';
    $baseDir = __DIR__ . '/src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) return;

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) require $file;
});

use VatSwim\VFDS\EDSTClient;
use VatSwim\VFDS\TDLSSync;
use VatSwim\VFDS\DepartureSequencer;
use VatSwim\VFDS\SWIMBridge;

// Initialize components
$edstClient = new EDSTClient(
    $config['vfds']['base_url'],
    $config['vfds']['api_key'],
    $config['vfds']['facility_id']
);

$tdlsSync = new TDLSSync(
    $edstClient,
    $config['vatswim']['api_key'],
    $config['vatswim']['base_url']
);

$sequencer = new DepartureSequencer();

// Configure sequencer
$sequencer->addConstraint('runway_config', [
    'type' => $config['sequencing']['runway_config']
]);

$bridge = new SWIMBridge(
    $edstClient,
    $tdlsSync,
    $sequencer,
    $config['vatswim']['api_key'],
    $config['vatswim']['base_url']
);

$bridge->setVerbose($config['logging']['verbose']);

// Start sync
$startTime = microtime(true);
echo "[" . date('Y-m-d H:i:s') . "] Starting vFDS sync for {$config['vfds']['facility_id']}...\n";

// Determine airports to sync
$airports = $config['airports']['include'];

if (empty($airports)) {
    // Sync all airports (no filter)
    $stats = $bridge->sync(null);
    echo "  Synced all airports\n";
} else {
    // Sync each airport
    $totalStats = [
        'vfds_to_swim' => ['departures' => 0, 'arrivals' => 0, 'tmi' => 0, 'errors' => 0],
        'swim_to_vfds' => ['flights' => 0, 'tmi' => 0, 'errors' => 0],
        'sequencing' => ['processed' => 0, 'updated' => 0]
    ];

    foreach ($airports as $airport) {
        if (in_array($airport, $config['airports']['exclude'])) {
            continue;
        }

        echo "  Processing $airport...\n";
        $stats = $bridge->sync($airport);

        // Accumulate stats
        foreach ($stats as $direction => $dirStats) {
            foreach ($dirStats as $key => $value) {
                $totalStats[$direction][$key] += $value;
            }
        }
    }

    $stats = $totalStats;
}

$elapsed = round((microtime(true) - $startTime) * 1000);

echo "[" . date('Y-m-d H:i:s') . "] Complete:\n";
echo "  vFDS → SWIM:\n";
echo "    Departures:  {$stats['vfds_to_swim']['departures']}\n";
echo "    TMI:         {$stats['vfds_to_swim']['tmi']}\n";
echo "    Errors:      {$stats['vfds_to_swim']['errors']}\n";
echo "  SWIM → vFDS:\n";
echo "    Flights:     {$stats['swim_to_vfds']['flights']}\n";
echo "    TMI:         {$stats['swim_to_vfds']['tmi']}\n";
echo "    Errors:      {$stats['swim_to_vfds']['errors']}\n";
echo "  Sequencing:\n";
echo "    Processed:   {$stats['sequencing']['processed']}\n";
echo "    Updated:     {$stats['sequencing']['updated']}\n";
echo "  Elapsed:       {$elapsed}ms\n";
