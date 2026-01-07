<?php
/**
 * VATSIM Data Ingestion Daemon
 *
 * Fetches live flight data from VATSIM API and processes it into the ADL database.
 * This is the primary data source for all flight tracking.
 *
 * Usage:
 *   php vatsim_ingest_daemon.php              # Run once
 *   php vatsim_ingest_daemon.php --loop       # Run continuously (recommended)
 *   php vatsim_ingest_daemon.php --loop --interval=15  # Custom interval
 */

// Include the PERTI connection setup which provides $conn_adl
require_once __DIR__ . '/../../load/connect.php';

// Configuration
define('VATSIM_DATA_URL', 'https://data.vatsim.net/v3/vatsim-data.json');
define('DEFAULT_INTERVAL', 15);  // seconds between fetches
define('USER_AGENT', 'PERTI-ADL/1.0 (vATCSCC Flight Tracking)');

class VatsimIngestDaemon
{
    private $conn;
    private int $interval;
    private bool $running = true;
    private int $successCount = 0;
    private int $errorCount = 0;

    public function __construct($conn_adl, int $interval = DEFAULT_INTERVAL)
    {
        $this->conn = $conn_adl;
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
     * Fetch VATSIM data from the API
     */
    private function fetchVatsimData(): ?string
    {
        $ch = curl_init(VATSIM_DATA_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => USER_AGENT,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Accept-Encoding: gzip'
            ],
            CURLOPT_ENCODING => 'gzip'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->log("CURL Error: {$error}");
            return null;
        }

        if ($httpCode !== 200) {
            $this->log("HTTP Error: {$httpCode}");
            return null;
        }

        return $response;
    }

    /**
     * Process the VATSIM JSON data through the stored procedure
     */
    private function processData(string $json): array
    {
        $stats = [
            'success' => false,
            'pilots_received' => 0,
            'new_flights' => 0,
            'updated_flights' => 0,
            'routes_queued' => 0,
            'elapsed_ms' => 0,
            'error' => null
        ];

        // Validate JSON structure
        $data = json_decode($json, true);
        if ($data === null) {
            $stats['error'] = 'Invalid JSON';
            return $stats;
        }

        $pilotCount = count($data['pilots'] ?? []);
        $stats['pilots_received'] = $pilotCount;

        if ($pilotCount === 0) {
            $stats['error'] = 'No pilots in data';
            return $stats;
        }

        // Call the stored procedure
        $sql = "EXEC dbo.sp_Adl_RefreshFromVatsim_Normalized @Json = ?";
        $stmt = sqlsrv_query($this->conn, $sql, [$json]);

        if ($stmt === false) {
            $errors = sqlsrv_errors();
            $stats['error'] = print_r($errors, true);
            return $stats;
        }

        // Get the result stats
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if ($row) {
            $stats['success'] = true;
            $stats['pilots_received'] = $row['pilots_received'] ?? $pilotCount;
            $stats['new_flights'] = $row['new_flights'] ?? 0;
            $stats['updated_flights'] = $row['updated_flights'] ?? 0;
            $stats['routes_queued'] = $row['routes_queued'] ?? 0;
            $stats['etas_calculated'] = $row['etas_calculated'] ?? 0;
            $stats['trajectories_logged'] = $row['trajectories_logged'] ?? 0;
            $stats['zone_transitions'] = $row['zone_transitions'] ?? 0;
            $stats['boundary_transitions'] = $row['boundary_transitions'] ?? 0;
            $stats['elapsed_ms'] = $row['elapsed_ms'] ?? 0;
        }

        sqlsrv_free_stmt($stmt);
        return $stats;
    }

    /**
     * Run a single ingestion cycle
     */
    public function runOnce(): array
    {
        $startTime = microtime(true);

        // Fetch data
        $this->log("Fetching VATSIM data...");
        $json = $this->fetchVatsimData();

        if ($json === null) {
            $this->errorCount++;
            return [
                'success' => false,
                'error' => 'Failed to fetch VATSIM data',
                'fetch_time_ms' => round((microtime(true) - $startTime) * 1000)
            ];
        }

        $fetchTime = round((microtime(true) - $startTime) * 1000);

        // Process data
        $stats = $this->processData($json);
        $stats['fetch_time_ms'] = $fetchTime;
        $stats['total_time_ms'] = round((microtime(true) - $startTime) * 1000);

        if ($stats['success']) {
            $this->successCount++;
            $this->log(sprintf(
                "Processed %d pilots | %d new | %d updated | %d queued | %dms proc | %dms total",
                $stats['pilots_received'],
                $stats['new_flights'],
                $stats['updated_flights'],
                $stats['routes_queued'],
                $stats['elapsed_ms'],
                $stats['total_time_ms']
            ));
        } else {
            $this->errorCount++;
            $this->log("ERROR: " . ($stats['error'] ?? 'Unknown error'));
        }

        return $stats;
    }

    /**
     * Run continuous loop
     */
    public function runLoop(): void
    {
        $this->log("Starting VATSIM ingestion daemon (interval: {$this->interval}s)");
        $this->log("Connected to VATSIM_ADL Azure SQL database");
        $this->log("Data source: " . VATSIM_DATA_URL);

        while ($this->running) {
            $stats = $this->runOnce();

            // Log running totals periodically
            if (($this->successCount + $this->errorCount) % 10 === 0) {
                $this->log(sprintf(
                    "Running totals: %d successful | %d errors",
                    $this->successCount,
                    $this->errorCount
                ));
            }

            // Sleep until next cycle
            if ($this->running) {
                sleep($this->interval);
            }

            // Check for signals
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }

        $this->log("Daemon stopped");
        $this->log(sprintf(
            "Final stats: %d successful | %d errors",
            $this->successCount,
            $this->errorCount
        ));
    }

    /**
     * Get current status
     */
    public function getStatus(): array
    {
        return [
            'running' => $this->running,
            'success_count' => $this->successCount,
            'error_count' => $this->errorCount,
            'interval' => $this->interval
        ];
    }
}

// =============================================================================
// Main entry point
// =============================================================================

$options = getopt('', ['loop', 'interval::', 'help']);

if (isset($options['help'])) {
    echo "VATSIM Data Ingestion Daemon\n";
    echo "============================\n";
    echo "Fetches flight data from VATSIM and processes it into VATSIM_ADL.\n\n";
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
if ($interval < 5) {
    echo "WARNING: Interval too short, using minimum of 5 seconds.\n";
    $interval = 5;
}

$daemon = new VatsimIngestDaemon($conn_adl, $interval);

if (isset($options['loop'])) {
    $daemon->runLoop();
} else {
    $stats = $daemon->runOnce();
    echo json_encode($stats, JSON_PRETTY_PRINT) . "\n";
}
