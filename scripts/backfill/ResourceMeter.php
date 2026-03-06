<?php
/**
 * Resource Metering for Backfill Operations
 *
 * Provides adaptive throttling, pressure detection, and observability
 * for the hibernation recovery backfill script. All parameters are
 * runtime-tunable via adl_archive_config (no restart required).
 *
 * Pressure detection uses the same DMVs as monitoring_daemon.php:
 *   - sys.dm_exec_sessions (active connections)
 *   - sys.dm_exec_requests (blocking sessions)
 *   - /proc/meminfo (system memory on Linux)
 *
 * @package PERTI
 * @subpackage Backfill
 */

class ResourceMeter
{
    /** @var resource sqlsrv connection to VATSIM_ADL */
    private $conn_adl;

    // Throttle state
    private string $mode;
    private int $currentDelayMs;
    private int $consecutivePressure = 0;
    private int $batchCount = 0;

    // Cached config (refreshed periodically)
    private array $config = [];
    private int $configRefreshBatch = 0;
    private const CONFIG_REFRESH_EVERY = 10;

    // Last pressure snapshot (for logging)
    private array $lastPressure = [];

    // Adaptive batch sizing
    private int $currentBatchSize;
    private int $originalBatchSize;

    /**
     * @param resource $conn_adl     sqlsrv connection to VATSIM_ADL
     * @param string   $cliThrottle  CLI --throttle override: 'adaptive', 'fixed', 'off', or '' (use DB config)
     * @param int      $cliBatchSize CLI --batch=N value
     */
    public function __construct($conn_adl, string $cliThrottle, int $cliBatchSize)
    {
        $this->conn_adl = $conn_adl;
        $this->originalBatchSize = $cliBatchSize;
        $this->currentBatchSize = $cliBatchSize;

        $this->refreshConfig();

        // CLI --throttle overrides DB config
        $this->mode = ($cliThrottle !== '') ? $cliThrottle : $this->cfg('BACKFILL_THROTTLE_MODE', 'adaptive');
        $this->currentDelayMs = $this->getBaseDelay();
    }

    /**
     * Read BACKFILL_* config from adl_archive_config.
     * Called at init and every CONFIG_REFRESH_EVERY batches.
     */
    private function refreshConfig(): void
    {
        $sql = "SELECT config_key, config_value
                FROM dbo.adl_archive_config WITH (NOLOCK)
                WHERE config_key LIKE 'BACKFILL[_]%'";
        $stmt = @sqlsrv_query($this->conn_adl, $sql, [], ['QueryTimeout' => 5]);
        if ($stmt === false) {
            return;
        }

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $this->config[$row['config_key']] = $row['config_value'];
        }
        sqlsrv_free_stmt($stmt);
    }

    private function cfg(string $key, string $default = ''): string
    {
        return $this->config[$key] ?? $default;
    }

    private function cfgInt(string $key, int $default = 0): int
    {
        return (int)($this->config[$key] ?? $default);
    }

    private function cfgFloat(string $key, float $default = 0.0): float
    {
        return (float)($this->config[$key] ?? $default);
    }

    private function getBaseDelay(): int
    {
        return match ($this->mode) {
            'fixed'    => $this->cfgInt('BACKFILL_FIXED_DELAY_MS', 2000),
            'adaptive' => $this->cfgInt('BACKFILL_ADAPTIVE_BASE_DELAY_MS', 500),
            default    => 0,
        };
    }

    /**
     * Called BEFORE each batch in Phases 2-4.
     *
     * @return array{batchSize: int, delay_ms: int, pressure: array}
     */
    public function preBatch(): array
    {
        $this->batchCount++;

        // Periodically refresh config from DB (hot-reconfig)
        if ($this->batchCount - $this->configRefreshBatch >= self::CONFIG_REFRESH_EVERY) {
            $this->refreshConfig();
            $this->configRefreshBatch = $this->batchCount;
        }

        if ($this->mode === 'off') {
            return [
                'batchSize' => $this->originalBatchSize,
                'delay_ms'  => 0,
                'pressure'  => [],
            ];
        }

        // 1. Detect resource pressure
        $pressure = $this->detectPressure();
        $this->lastPressure = $pressure;
        $isUnderPressure = $pressure['under_pressure'];

        // 2. Calculate delay and batch size
        if ($this->mode === 'fixed') {
            $delayMs = $this->cfgInt('BACKFILL_FIXED_DELAY_MS', 2000);
            $this->currentBatchSize = $this->originalBatchSize;
        } else {
            // Adaptive mode
            $multiplier = $this->cfgFloat('BACKFILL_BACKOFF_MULTIPLIER', 2.0);
            $baseDelay = $this->cfgInt('BACKFILL_ADAPTIVE_BASE_DELAY_MS', 500);

            if ($isUnderPressure) {
                $this->consecutivePressure++;
                $maxDelay = $this->cfgInt('BACKFILL_ADAPTIVE_MAX_DELAY_MS', 30000);
                $floor = $this->cfgInt('BACKFILL_BATCH_SIZE_FLOOR', 10);

                // Exponential backoff: base * multiplier^consecutive (capped)
                $this->currentDelayMs = (int)min(
                    $baseDelay * pow($multiplier, $this->consecutivePressure),
                    $maxDelay
                );

                // Shrink batch size under pressure (cap exponent at 4 to avoid tiny batches)
                $this->currentBatchSize = max(
                    $floor,
                    (int)($this->originalBatchSize / pow($multiplier, min($this->consecutivePressure, 4)))
                );
            } else {
                // No pressure: decay toward base
                if ($this->consecutivePressure > 0) {
                    $this->consecutivePressure--;
                }
                $this->currentDelayMs = $baseDelay;

                // Grow batch size back toward original (+10 per batch)
                $ceiling = min(
                    $this->originalBatchSize,
                    $this->cfgInt('BACKFILL_BATCH_SIZE_CEILING', 200)
                );
                $this->currentBatchSize = min($ceiling, $this->currentBatchSize + 10);
            }

            $delayMs = $this->currentDelayMs;
        }

        // 3. Apply delay
        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }

        return [
            'batchSize' => $this->currentBatchSize,
            'delay_ms'  => $delayMs,
            'pressure'  => $pressure,
        ];
    }

    /**
     * Detect system resource pressure.
     *
     * Checks Azure SQL connections, blocking sessions, query latency, and system memory.
     * Uses the same DMVs as scripts/monitoring_daemon.php (lines 176-191).
     *
     * @return array{db_connections: int, db_blocking: int, db_latency_ms: int, memory_pct: int, under_pressure: bool, reasons: string[]}
     */
    private function detectPressure(): array
    {
        $metrics = [
            'db_connections' => 0,
            'db_blocking'    => 0,
            'db_latency_ms'  => 0,
            'memory_pct'     => 0,
            'under_pressure' => false,
            'reasons'        => [],
        ];

        $start = microtime(true);

        // Active user connections (same query as monitoring_daemon.php:176)
        $stmt = @sqlsrv_query(
            $this->conn_adl,
            "SELECT COUNT(*) AS c FROM sys.dm_exec_sessions WHERE is_user_process = 1",
            [],
            ['QueryTimeout' => 3]
        );
        if ($stmt) {
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            $metrics['db_connections'] = (int)($row['c'] ?? 0);
            sqlsrv_free_stmt($stmt);
        }

        // Blocking sessions (same query as monitoring_daemon.php:185)
        $stmt = @sqlsrv_query(
            $this->conn_adl,
            "SELECT COUNT(*) AS c FROM sys.dm_exec_requests WHERE blocking_session_id != 0",
            [],
            ['QueryTimeout' => 3]
        );
        if ($stmt) {
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            $metrics['db_blocking'] = (int)($row['c'] ?? 0);
            sqlsrv_free_stmt($stmt);
        }

        // Latency = round-trip time of the above queries
        $metrics['db_latency_ms'] = (int)((microtime(true) - $start) * 1000);

        // System memory (Linux only — App Service runs Linux)
        if (PHP_OS_FAMILY !== 'Windows' && is_readable('/proc/meminfo')) {
            $meminfo = @file_get_contents('/proc/meminfo');
            if ($meminfo !== false) {
                preg_match('/MemTotal:\s+(\d+)/', $meminfo, $total);
                preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $available);
                if (!empty($total[1]) && !empty($available[1])) {
                    $totalKb = (int)$total[1];
                    $availKb = (int)$available[1];
                    if ($totalKb > 0) {
                        $metrics['memory_pct'] = (int)round((($totalKb - $availKb) / $totalKb) * 100);
                    }
                }
            }
        }

        // Evaluate thresholds
        $connThreshold    = $this->cfgInt('BACKFILL_PRESSURE_CONN_THRESHOLD', 40);
        $blockThreshold   = $this->cfgInt('BACKFILL_PRESSURE_BLOCKING_THRESHOLD', 2);
        $latencyThreshold = $this->cfgInt('BACKFILL_PRESSURE_LATENCY_THRESHOLD_MS', 500);
        $memoryThreshold  = $this->cfgInt('BACKFILL_PRESSURE_MEMORY_PCT_THRESHOLD', 85);

        if ($metrics['db_connections'] >= $connThreshold) {
            $metrics['under_pressure'] = true;
            $metrics['reasons'][] = "conns={$metrics['db_connections']}>={$connThreshold}";
        }
        if ($metrics['db_blocking'] >= $blockThreshold) {
            $metrics['under_pressure'] = true;
            $metrics['reasons'][] = "blocking={$metrics['db_blocking']}>={$blockThreshold}";
        }
        if ($metrics['db_latency_ms'] >= $latencyThreshold) {
            $metrics['under_pressure'] = true;
            $metrics['reasons'][] = "latency={$metrics['db_latency_ms']}ms>={$latencyThreshold}ms";
        }
        if ($metrics['memory_pct'] >= $memoryThreshold) {
            $metrics['under_pressure'] = true;
            $metrics['reasons'][] = "memory={$metrics['memory_pct']}%>={$memoryThreshold}%";
        }

        return $metrics;
    }

    /**
     * Whether to log resource metrics this batch.
     */
    public function shouldLogMetrics(): bool
    {
        $interval = $this->cfgInt('BACKFILL_LOG_METRICS_INTERVAL', 5);
        return $interval > 0 && ($this->batchCount % $interval === 0);
    }

    /**
     * Format metrics for log output.
     */
    public function formatMetrics(): string
    {
        $p = $this->lastPressure;
        return sprintf(
            'METER: mode=%s delay=%dms batch=%d conns=%d blocking=%d latency=%dms mem=%d%% pressure=%s backoff=%d',
            $this->mode,
            $this->currentDelayMs,
            $this->currentBatchSize,
            $p['db_connections'] ?? 0,
            $p['db_blocking'] ?? 0,
            $p['db_latency_ms'] ?? 0,
            $p['memory_pct'] ?? 0,
            !empty($p['under_pressure']) ? 'YES(' . implode(',', $p['reasons'] ?? []) . ')' : 'no',
            $this->consecutivePressure
        );
    }

    /**
     * Get current throttle mode.
     */
    public function getMode(): string
    {
        return $this->mode;
    }

    /**
     * Get batch counter.
     */
    public function getBatchCount(): int
    {
        return $this->batchCount;
    }
}
