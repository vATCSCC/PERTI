<?php
/**
 * System Monitoring Daemon
 *
 * Lightweight monitoring that logs system metrics every minute.
 * Outputs to /home/LogFiles/monitoring.log for trend analysis.
 *
 * Cost: FREE - no external services required
 *
 * Usage:
 *   php monitoring_daemon.php              # Run once
 *   php monitoring_daemon.php --loop       # Run continuously (every 60s)
 *
 * Output format (JSON lines):
 *   {"ts":"2024-01-17T12:00:00Z","fpm":{"active":5,"idle":15},"db":{"conns":12,"blocking":0},...}
 */

define('PID_FILE', sys_get_temp_dir() . '/perti_monitoring_daemon.pid');
define('LOG_FILE', '/home/LogFiles/monitoring.log');
define('METRICS_INTERVAL', 60); // seconds

function acquirePidLock(): bool {
    if (file_exists(PID_FILE)) {
        $existingPid = (int) file_get_contents(PID_FILE);
        if (PHP_OS_FAMILY === 'Windows') {
            exec("tasklist /FI \"PID eq {$existingPid}\" 2>NUL", $output);
            $processExists = count($output) > 1;
        } else {
            $processExists = posix_kill($existingPid, 0);
        }
        if ($processExists) {
            echo "ERROR: Another instance is already running (PID: {$existingPid})\n";
            return false;
        }
        unlink(PID_FILE);
    }
    file_put_contents(PID_FILE, getmypid());
    return true;
}

function releasePidLock(): void {
    if (file_exists(PID_FILE)) {
        unlink(PID_FILE);
    }
}

register_shutdown_function('releasePidLock');

require_once __DIR__ . '/../load/config.php';

class MonitoringDaemon
{
    private $conn = null;
    private $running = true;
    private $logFile;

    public function __construct()
    {
        $this->logFile = LOG_FILE;

        // Ensure log directory exists
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        // Setup signal handlers
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'shutdown']);
            pcntl_signal(SIGINT, [$this, 'shutdown']);
        }
    }

    public function shutdown(): void
    {
        $this->running = false;
    }

    private function connectDb(): bool
    {
        if ($this->conn !== null) {
            return true;
        }

        if (!defined('ADL_SQL_HOST')) {
            return false;
        }

        $connInfo = [
            'Database' => ADL_SQL_DATABASE,
            'UID' => ADL_SQL_USERNAME,
            'PWD' => ADL_SQL_PASSWORD,
            'LoginTimeout' => 5,
        ];

        $this->conn = @sqlsrv_connect(ADL_SQL_HOST, $connInfo);
        return $this->conn !== false;
    }

    private function closeDb(): void
    {
        if ($this->conn !== null) {
            sqlsrv_close($this->conn);
            $this->conn = null;
        }
    }

    /**
     * Collect all metrics
     */
    public function collectMetrics(): array
    {
        $metrics = [
            'ts' => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        // PHP-FPM metrics
        $metrics['fpm'] = $this->getFpmMetrics();

        // Database metrics
        $metrics['db'] = $this->getDbMetrics();

        // Daemon status
        $metrics['daemons'] = $this->getDaemonMetrics();

        // Memory
        $metrics['mem'] = $this->getMemoryMetrics();

        // Request metrics (from recent logs)
        $metrics['requests'] = $this->getRequestMetrics();

        return $metrics;
    }

    private function getFpmMetrics(): array
    {
        $metrics = ['active' => 0, 'idle' => 0, 'queue' => 0, 'max_reached' => 0];

        // Try FPM status page
        $ctx = stream_context_create(['http' => ['timeout' => 2]]);
        $json = @file_get_contents('http://127.0.0.1/fpm-status?json', false, $ctx);

        if ($json !== false) {
            $data = json_decode($json, true);
            if ($data) {
                $metrics['active'] = $data['active processes'] ?? 0;
                $metrics['idle'] = $data['idle processes'] ?? 0;
                $metrics['queue'] = $data['listen queue'] ?? 0;
                $metrics['max_reached'] = $data['max children reached'] ?? 0;
                $metrics['total'] = $data['total processes'] ?? 0;
                $metrics['slow'] = $data['slow requests'] ?? 0;
            }
        } else {
            // Fallback: count processes
            if (PHP_OS_FAMILY !== 'Windows') {
                exec('ps aux | grep -c "[p]hp-fpm"', $output);
                $metrics['total'] = (int)($output[0] ?? 0);
            }
        }

        return $metrics;
    }

    private function getDbMetrics(): array
    {
        $metrics = ['conns' => 0, 'blocking' => 0, 'locks' => 0, 'latency_ms' => 0];

        if (!$this->connectDb()) {
            $metrics['error'] = 'connection_failed';
            return $metrics;
        }

        $start = microtime(true);

        // Active connections
        $sql = "SELECT COUNT(*) AS c FROM sys.dm_exec_sessions WHERE is_user_process = 1";
        $stmt = @sqlsrv_query($this->conn, $sql);
        if ($stmt) {
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            $metrics['conns'] = (int)($row['c'] ?? 0);
            sqlsrv_free_stmt($stmt);
        }

        // Blocking sessions
        $sql = "SELECT COUNT(*) AS c FROM sys.dm_exec_requests WHERE blocking_session_id != 0";
        $stmt = @sqlsrv_query($this->conn, $sql);
        if ($stmt) {
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            $metrics['blocking'] = (int)($row['c'] ?? 0);
            sqlsrv_free_stmt($stmt);
        }

        // App locks
        $sql = "SELECT COUNT(*) AS c FROM sys.dm_tran_locks WHERE resource_type = 'APPLICATION'";
        $stmt = @sqlsrv_query($this->conn, $sql);
        if ($stmt) {
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            $metrics['locks'] = (int)($row['c'] ?? 0);
            sqlsrv_free_stmt($stmt);
        }

        $metrics['latency_ms'] = round((microtime(true) - $start) * 1000);

        return $metrics;
    }

    private function getDaemonMetrics(): array
    {
        $daemons = [
            'boundary' => sys_get_temp_dir() . '/adl_boundary_daemon.pid',
            'parse' => sys_get_temp_dir() . '/adl_parse_queue_daemon.pid',
            'eta' => sys_get_temp_dir() . '/adl_waypoint_eta_daemon.pid',
            'archival' => sys_get_temp_dir() . '/perti_archival_daemon.pid',
        ];

        $running = 0;
        foreach ($daemons as $name => $pidFile) {
            if (file_exists($pidFile)) {
                $pid = (int)file_get_contents($pidFile);
                if (PHP_OS_FAMILY === 'Windows') {
                    exec("tasklist /FI \"PID eq {$pid}\" 2>NUL", $output);
                    $isRunning = count($output) > 1;
                } else {
                    $isRunning = posix_kill($pid, 0);
                }
                if ($isRunning) $running++;
            }
        }

        return ['running' => $running, 'total' => count($daemons)];
    }

    private function getMemoryMetrics(): array
    {
        $metrics = [
            'php_mb' => round(memory_get_usage(true) / 1024 / 1024, 1),
        ];

        // System memory (Linux)
        if (PHP_OS_FAMILY !== 'Windows' && file_exists('/proc/meminfo')) {
            $meminfo = file_get_contents('/proc/meminfo');
            preg_match('/MemTotal:\s+(\d+)/', $meminfo, $total);
            preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $available);

            if ($total && $available) {
                $totalMb = round($total[1] / 1024);
                $availableMb = round($available[1] / 1024);
                $metrics['sys_total_mb'] = $totalMb;
                $metrics['sys_avail_mb'] = $availableMb;
                $metrics['sys_used_pct'] = round((($totalMb - $availableMb) / $totalMb) * 100, 1);
            }
        }

        return $metrics;
    }

    private function getRequestMetrics(): array
    {
        // Parse nginx access log for request rates (last minute)
        $metrics = ['count' => 0, 'errors' => 0];

        $accessLog = '/var/log/nginx/access.log';
        if (!file_exists($accessLog)) {
            $accessLog = '/home/LogFiles/access.log';
        }

        if (file_exists($accessLog)) {
            $oneMinuteAgo = time() - 60;
            $lines = @file($accessLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

            if ($lines) {
                // Only look at last 1000 lines for performance
                $lines = array_slice($lines, -1000);
                foreach ($lines as $line) {
                    // Simple check for 5xx errors
                    if (preg_match('/\" (5\d{2}) /', $line)) {
                        $metrics['errors']++;
                    }
                    $metrics['count']++;
                }
            }
        }

        return $metrics;
    }

    /**
     * Log metrics to file
     */
    private function logMetrics(array $metrics): void
    {
        $line = json_encode($metrics, JSON_UNESCAPED_SLASHES) . "\n";
        file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Run once
     */
    public function runOnce(): array
    {
        $metrics = $this->collectMetrics();
        $this->logMetrics($metrics);
        $this->closeDb();
        return $metrics;
    }

    /**
     * Run continuously
     */
    public function runLoop(): void
    {
        echo "[" . gmdate('Y-m-d H:i:s') . "] Monitoring daemon started (interval: " . METRICS_INTERVAL . "s)\n";
        echo "Logging to: {$this->logFile}\n";

        while ($this->running) {
            $metrics = $this->collectMetrics();
            $this->logMetrics($metrics);

            // Print summary
            echo sprintf(
                "[%s] fpm:%d/%d db:%d/%d daemons:%d/%d mem:%d%%\n",
                gmdate('H:i:s'),
                $metrics['fpm']['active'] ?? 0,
                $metrics['fpm']['total'] ?? 0,
                $metrics['db']['conns'] ?? 0,
                $metrics['db']['blocking'] ?? 0,
                $metrics['daemons']['running'] ?? 0,
                $metrics['daemons']['total'] ?? 0,
                $metrics['mem']['sys_used_pct'] ?? 0
            );

            // Alert on issues
            if (($metrics['db']['blocking'] ?? 0) > 0) {
                echo "  WARNING: {$metrics['db']['blocking']} blocking sessions detected!\n";
            }
            if (($metrics['fpm']['queue'] ?? 0) > 5) {
                echo "  WARNING: FPM listen queue at {$metrics['fpm']['queue']}!\n";
            }

            sleep(METRICS_INTERVAL);

            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }

        $this->closeDb();
        echo "Monitoring daemon stopped.\n";
    }
}

// =============================================================================
// Main
// =============================================================================

$options = getopt('', ['loop', 'help']);

if (isset($options['help'])) {
    echo "PERTI System Monitoring Daemon\n";
    echo "==============================\n";
    echo "Collects system metrics and logs to " . LOG_FILE . "\n\n";
    echo "Usage: php monitoring_daemon.php [options]\n";
    echo "  --loop    Run continuously (every 60s)\n";
    echo "  --help    Show this help\n";
    exit(0);
}

if (isset($options['loop'])) {
    if (!acquirePidLock()) {
        exit(1);
    }
    echo "PID lock acquired (PID: " . getmypid() . ")\n";
}

$daemon = new MonitoringDaemon();

if (isset($options['loop'])) {
    $daemon->runLoop();
} else {
    $metrics = $daemon->runOnce();
    echo json_encode($metrics, JSON_PRETTY_PRINT) . "\n";
}
