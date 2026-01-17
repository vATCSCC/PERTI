<?php
/**
 * ADL Route Parse Queue Daemon
 * 
 * Processes the parse queue to expand routes into waypoints and geometries.
 * Run this as a continuous process alongside the ingestion daemon.
 * 
 * IMPORTANT: Uses $conn_adl (Azure SQL VATSIM_ADL database), NOT the PERTI MySQL.
 * 
 * Usage:
 *   php parse_queue_daemon.php              # Run once
 *   php parse_queue_daemon.php --loop       # Run continuously
 *   php parse_queue_daemon.php --loop --batch=100  # Custom batch size
 */

// Include the PERTI connection setup which provides $conn_adl
require_once __DIR__ . '/../../load/connect.php';

// Configuration
define('DEFAULT_BATCH_SIZE', 50);
define('DEFAULT_INTERVAL', 10);  // seconds between queue checks (increased from 5 to reduce contention)
define('MAX_ITERATIONS', 20);    // max batches per cycle
define('STAGGER_OFFSET', 3);     // Seconds to offset from other daemons

class ParseQueueDaemon
{
    private $conn;
    private $batchSize;
    private $interval;
    private $running = true;
    
    public function __construct($conn_adl, int $batchSize = DEFAULT_BATCH_SIZE, int $interval = DEFAULT_INTERVAL)
    {
        $this->conn = $conn_adl;
        $this->batchSize = $batchSize;
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
     * Get queue statistics
     * NOTE: PENDING/PROCESSING counts ALL items (not time-filtered) to detect backlogs
     */
    private function getQueueStats(): array
    {
        // NOLOCK: Safe for stats query - we only need approximate counts
        $sql = "
            SELECT
                COUNT(CASE WHEN status = 'PENDING' AND next_eligible_utc <= SYSUTCDATETIME() THEN 1 END) AS pending,
                COUNT(CASE WHEN status = 'PROCESSING' THEN 1 END) AS processing,
                COUNT(CASE WHEN status = 'COMPLETE' AND completed_utc > DATEADD(HOUR, -1, SYSUTCDATETIME()) THEN 1 END) AS complete,
                COUNT(CASE WHEN status = 'FAILED' AND completed_utc > DATEADD(HOUR, -1, SYSUTCDATETIME()) THEN 1 END) AS failed,
                AVG(CASE WHEN status = 'COMPLETE' AND completed_utc > DATEADD(HOUR, -1, SYSUTCDATETIME())
                    THEN DATEDIFF(MILLISECOND, started_utc, completed_utc) END) AS avg_parse_ms
            FROM dbo.adl_parse_queue WITH (NOLOCK)
        ";
        
        $stmt = sqlsrv_query($this->conn, $sql);
        if ($stmt === false) return [];
        
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        
        return $row ?: [];
    }
    
    /**
     * Reset items stuck in PROCESSING for more than 5 minutes
     */
    private function resetStuckItems(): int
    {
        $sql = "
            UPDATE dbo.adl_parse_queue
            SET status = 'PENDING',
                next_eligible_utc = SYSUTCDATETIME(),
                started_utc = NULL
            WHERE status = 'PROCESSING'
              AND started_utc < DATEADD(MINUTE, -5, SYSUTCDATETIME())
        ";

        $stmt = sqlsrv_query($this->conn, $sql);
        if ($stmt === false) return 0;

        $rows = sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);

        if ($rows > 0) {
            $this->log("Reset {$rows} stuck PROCESSING items");
        }

        return $rows;
    }

    /**
     * Process a batch of routes directly (more control than sp_ProcessParseQueue)
     */
    private function processBatch(int $batchSize = null): int
    {
        $size = $batchSize ?? $this->batchSize;
        $sql = "EXEC dbo.sp_ParseRouteBatch @batch_size = ?, @tier = NULL";
        $stmt = sqlsrv_query($this->conn, $sql, [$size]);
        
        if ($stmt === false) {
            $errors = sqlsrv_errors();
            $this->log("ERROR: " . print_r($errors, true));
            return 0;
        }
        
        // The procedure returns stats
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        
        return $row['processed'] ?? 0;
    }
    
    /**
     * Run a single processing cycle
     */
    public function runOnce(): array
    {
        $startTime = microtime(true);
        $stats = [
            'processed' => 0,
            'duration_ms' => 0,
            'queue_stats' => []
        ];
        
        // Reset any stuck items first
        $this->resetStuckItems();

        // Get initial stats
        $queueStats = $this->getQueueStats();
        $pending = $queueStats['pending'] ?? 0;
        $processing = $queueStats['processing'] ?? 0;

        if ($pending == 0 && $processing == 0) {
            $this->log("Queue empty, nothing to process");
            $stats['queue_stats'] = $queueStats;
            return $stats;
        }

        // Use larger batch size when backlogged (>100 pending), then return to normal
        $isBacklogged = $pending > 100;
        $catchupBatchSize = $isBacklogged ? 500 : $this->batchSize;

        if ($isBacklogged) {
            $this->log("BACKLOG detected ({$pending} pending) - using batch size {$catchupBatchSize}");
        } else {
            $this->log("Processing queue ({$pending} pending)...");
        }

        // Process batches
        $iterations = 0;
        $totalProcessed = 0;

        while ($iterations < MAX_ITERATIONS && $this->running) {
            $processed = $this->processBatch($catchupBatchSize);
            $totalProcessed += $processed;
            $iterations++;

            if ($processed < $catchupBatchSize) {
                // Queue exhausted
                break;
            }
        }
        
        $stats['processed'] = $totalProcessed;
        $stats['duration_ms'] = round((microtime(true) - $startTime) * 1000);
        $stats['queue_stats'] = $this->getQueueStats();
        
        $this->log("Processed {$totalProcessed} routes in {$iterations} batches ({$stats['duration_ms']}ms)");
        
        return $stats;
    }
    
    /**
     * Run continuous loop
     */
    public function runLoop(): void
    {
        $this->log("Starting parse queue daemon (batch: {$this->batchSize}, interval: {$this->interval}s)");
        $this->log("Connected to VATSIM_ADL Azure SQL database");

        // Stagger start to avoid colliding with other daemons
        if (STAGGER_OFFSET > 0) {
            $this->log("Staggering start by " . STAGGER_OFFSET . "s to reduce contention...");
            sleep(STAGGER_OFFSET);
        }

        while ($this->running) {
            $stats = $this->runOnce();
            
            // Log queue status
            $qs = $stats['queue_stats'];
            $this->log(sprintf(
                "Queue: %d pending | %d complete | %d failed | avg %dms",
                $qs['pending'] ?? 0,
                $qs['complete'] ?? 0,
                $qs['failed'] ?? 0,
                $qs['avg_parse_ms'] ?? 0
            ));
            
            // Sleep until next cycle
            if ($this->running) {
                // Sleep longer if queue was empty
                $sleepTime = ($stats['processed'] == 0) ? $this->interval * 2 : $this->interval;
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

$options = getopt('', ['loop', 'batch::', 'interval::', 'help']);

if (isset($options['help'])) {
    echo "ADL Route Parse Queue Daemon\n";
    echo "============================\n";
    echo "Processes routes from the parse queue in VATSIM_ADL Azure SQL database.\n\n";
    echo "Usage: php parse_queue_daemon.php [options]\n";
    echo "  --loop           Run continuously\n";
    echo "  --batch=N        Routes per batch (default: 50)\n";
    echo "  --interval=N     Seconds between cycles (default: 5)\n";
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

$batchSize = isset($options['batch']) ? (int)$options['batch'] : DEFAULT_BATCH_SIZE;
$interval = isset($options['interval']) ? (int)$options['interval'] : DEFAULT_INTERVAL;

$daemon = new ParseQueueDaemon($conn_adl, $batchSize, $interval);

if (isset($options['loop'])) {
    $daemon->runLoop();
} else {
    $stats = $daemon->runOnce();
    echo json_encode($stats, JSON_PRETTY_PRINT) . "\n";
}
