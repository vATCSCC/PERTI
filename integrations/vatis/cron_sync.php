<?php
/**
 * VATSWIM vATIS Correlation Cron Job
 *
 * Run via cron every minute to correlate ATIS data with active flights.
 *
 * Usage:
 *   * * * * * /usr/bin/php /path/to/cron_sync.php >> /var/log/vatswim-vatis.log 2>&1
 *
 * @package VATSWIM
 * @subpackage vATIS Integration
 * @version 1.0.0
 */

// Load configuration
$config = require __DIR__ . '/config.php';

// Autoload
spl_autoload_register(function ($class) {
    $prefix = 'VatSwim\\VATIS\\';
    $baseDir = __DIR__ . '/src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) return;

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) require $file;
});

use VatSwim\VATIS\ATISMonitor;
use VatSwim\VATIS\RunwayCorrelator;
use VatSwim\VATIS\WeatherExtractor;
use VatSwim\VATIS\SWIMSync;

// Initialize components
$atisMonitor = new ATISMonitor();
$atisMonitor->setVerbose($config['logging']['verbose']);

$correlator = new RunwayCorrelator($atisMonitor);
$weather = new WeatherExtractor($atisMonitor);

$sync = new SWIMSync(
    $config['vatswim']['api_key'],
    $config['vatswim']['base_url'],
    $correlator,
    $weather
);
$sync->setVerbose($config['logging']['verbose']);

// Start sync
$startTime = microtime(true);
echo "[" . date('Y-m-d H:i:s') . "] Starting vATIS correlation sync...\n";

// Get airports with active ATIS
$activeAirports = $atisMonitor->getActiveAirports();
echo "  Found " . count($activeAirports) . " airports with active ATIS\n";

if (empty($activeAirports)) {
    echo "[" . date('Y-m-d H:i:s') . "] No active ATIS found, exiting\n";
    exit(0);
}

// Apply airport filters
$airports = $activeAirports;

if (!empty($config['airports']['include'])) {
    $airports = array_intersect($airports, $config['airports']['include']);
}

if (!empty($config['airports']['exclude'])) {
    $airports = array_diff($airports, $config['airports']['exclude']);
}

if ($config['airports']['us_only']) {
    $airports = array_filter($airports, fn($icao) => str_starts_with($icao, 'K'));
}

echo "  Processing " . count($airports) . " airports after filtering\n";

// Get active flights from VATSWIM
$flights = $sync->getActiveFlights($airports);
echo "  Found " . count($flights) . " active flights\n";

if (empty($flights)) {
    echo "[" . date('Y-m-d H:i:s') . "] No active flights at ATIS airports, exiting\n";
    exit(0);
}

// Limit batch size
if (count($flights) > $config['polling']['batch_size']) {
    $flights = array_slice($flights, 0, $config['polling']['batch_size']);
    echo "  Limited to " . $config['polling']['batch_size'] . " flights per batch\n";
}

// Sync correlated data
$stats = $sync->syncActiveFlights($flights);

// Sync airport weather
$weatherSynced = 0;
foreach ($airports as $icao) {
    if ($sync->syncAirportWeather($icao)) {
        $weatherSynced++;
    }
}

$elapsed = round((microtime(true) - $startTime) * 1000);

echo "[" . date('Y-m-d H:i:s') . "] Complete:\n";
echo "  Flights processed: {$stats['processed']}\n";
echo "  Flights updated:   {$stats['updated']}\n";
echo "  Flights skipped:   {$stats['skipped']}\n";
echo "  Errors:            {$stats['errors']}\n";
echo "  Weather synced:    {$weatherSynced}\n";
echo "  Elapsed time:      {$elapsed}ms\n";
