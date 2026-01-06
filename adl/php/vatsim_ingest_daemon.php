<?php
/**
 * ADL VATSIM Data Ingestion Daemon
 * 
 * Fetches live data from VATSIM Data API and upserts into normalized ADL schema.
 * Run this as a continuous process or via cron every 15-30 seconds.
 * 
 * IMPORTANT: Uses $conn_adl (Azure SQL VATSIM_ADL database), NOT the PERTI MySQL.
 * 
 * Usage:
 *   php vatsim_ingest_daemon.php              # Run once
 *   php vatsim_ingest_daemon.php --loop       # Run continuously
 *   php vatsim_ingest_daemon.php --loop --interval=15  # Custom interval
 */

// Include the PERTI connection setup which provides $conn_adl
require_once __DIR__ . '/../../load/connect.php';
require_once __DIR__ . '/AdlFlightUpsert.php';

// Configuration
define('VATSIM_DATA_URL', 'https://data.vatsim.net/v3/vatsim-data.json');
define('DEFAULT_INTERVAL', 15);  // seconds between fetches
define('STALE_THRESHOLD', 5);    // minutes before marking flights inactive

class VatsimIngestDaemon
{
    private $conn;
    private $adl;
    private $interval;
    private $running = true;
    
    public function __construct($conn_adl, int $interval = DEFAULT_INTERVAL)
    {
        $this->conn = $conn_adl;
        $this->adl = new AdlFlightUpsert($conn_adl);
        $this->interval = $interval;
        
        // Handle graceful shutdown
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'shutdown']);
            pcntl_signal(SIGINT, [$this, 'shutdown']);
        }
    }
    
    public function shutdown(): void
    {
        $this->log("Shutdown signal received");
        $this->running = false;
    }
    
    private function log(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        echo "[{$timestamp}] {$message}\n";
    }
    
    /**
     * Fetch VATSIM data from API
     */
    private function fetchVatsimData(): ?array
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'header' => "User-Agent: PERTI-ADL/1.0\r\n"
            ]
        ]);
        
        $json = @file_get_contents(VATSIM_DATA_URL, false, $context);
        
        if ($json === false) {
            $this->log("ERROR: Failed to fetch VATSIM data");
            return null;
        }
        
        $data = json_decode($json, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log("ERROR: Invalid JSON from VATSIM API");
            return null;
        }
        
        return $data;
    }
    
    /**
     * Run a single ingestion cycle
     */
    public function runOnce(): array
    {
        $startTime = microtime(true);
        $stats = [
            'pilots_received' => 0,
            'flights_upserted' => 0,
            'flights_inactive' => 0,
            'duration_ms' => 0,
            'errors' => []
        ];
        
        // Fetch data
        $data = $this->fetchVatsimData();
        if ($data === null) {
            $stats['errors'][] = 'Failed to fetch VATSIM data';
            return $stats;
        }
        
        $stats['pilots_received'] = count($data['pilots'] ?? []);
        $this->log("Received {$stats['pilots_received']} pilots from VATSIM");
        
        // Process pilots
        $stats['flights_upserted'] = $this->adl->processVatsimData($data);
        $this->log("Upserted {$stats['flights_upserted']} flights");
        
        // Mark stale flights inactive
        $stats['flights_inactive'] = $this->adl->markInactive(STALE_THRESHOLD);
        if ($stats['flights_inactive'] > 0) {
            $this->log("Marked {$stats['flights_inactive']} flights inactive");
        }
        
        // Calculate duration
        $stats['duration_ms'] = round((microtime(true) - $startTime) * 1000);
        $this->log("Cycle complete in {$stats['duration_ms']}ms");
        
        return $stats;
    }
    
    /**
     * Run continuous loop
     */
    public function runLoop(): void
    {
        $this->log("Starting VATSIM ingestion daemon (interval: {$this->interval}s)");
        $this->log("Connected to VATSIM_ADL Azure SQL database");
        
        while ($this->running) {
            $stats = $this->runOnce();
            
            // Log stats summary
            $dbStats = $this->adl->getStats();
            $this->log(sprintf(
                "Active: %d | Pending parse: %d | Parsed: %d",
                $dbStats['active_flights'] ?? 0,
                $dbStats['pending_parse'] ?? 0,
                $dbStats['routes_parsed'] ?? 0
            ));
            
            // Sleep until next cycle
            if ($this->running) {
                $sleepTime = max(1, $this->interval - ($stats['duration_ms'] / 1000));
                sleep((int)$sleepTime);
            }
            
            // Check for signals
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }
        
        $this->log("Daemon stopped");
    }
}

// =============================================================================
// Main entry point
// =============================================================================

// Parse command line arguments
$options = getopt('', ['loop', 'interval::', 'help']);

if (isset($options['help'])) {
    echo "VATSIM ADL Ingestion Daemon\n";
    echo "===========================\n";
    echo "Fetches VATSIM data and upserts into VATSIM_ADL Azure SQL database.\n\n";
    echo "Usage: php vatsim_ingest_daemon.php [options]\n";
    echo "  --loop           Run continuously\n";
    echo "  --interval=N     Seconds between fetches (default: 15)\n";
    echo "  --help           Show this help\n";
    exit(0);
}

// Check that we have the ADL connection
if (!isset($conn_adl) || $conn_adl === null || $conn_adl === false) {
    echo "ERROR: Could not connect to VATSIM_ADL database.\n";
    echo "Check that ADL_SQL_* constants are defined in load/config.php\n";
    echo "and that the sqlsrv extension is loaded.\n";
    exit(1);
}

echo "Connected to VATSIM_ADL database successfully.\n";

$interval = isset($options['interval']) ? (int)$options['interval'] : DEFAULT_INTERVAL;
$daemon = new VatsimIngestDaemon($conn_adl, $interval);

if (isset($options['loop'])) {
    $daemon->runLoop();
} else {
    $stats = $daemon->runOnce();
    echo json_encode($stats, JSON_PRETTY_PRINT) . "\n";
}
