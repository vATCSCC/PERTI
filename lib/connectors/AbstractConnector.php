<?php
/**
 * AbstractConnector — base class for VATSWIM connector descriptors.
 *
 * Provides shared logic: hibernation check, source config lookup from
 * swim_config.php, and a health template. Subclasses define source-specific
 * details (endpoints, daemon PID files, circuit breakers).
 */

namespace PERTI\Lib\Connectors;

abstract class AbstractConnector implements ConnectorInterface
{
    protected string $name;
    protected string $sourceId;
    protected string $type;
    protected ?CircuitBreaker $circuitBreaker = null;

    /**
     * Daemon PID file path (for poll/bidirectional connectors).
     * Null if this connector has no daemon.
     */
    protected ?string $daemonPidFile = null;

    public function getName(): string
    {
        return $this->name;
    }

    public function getSourceId(): string
    {
        return $this->sourceId;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function isEnabled(): bool
    {
        if (defined('HIBERNATION_MODE') && HIBERNATION_MODE) {
            return false;
        }
        return true;
    }

    public function getCircuitBreaker(): ?CircuitBreaker
    {
        return $this->circuitBreaker;
    }

    public function getDaemonPidFile(): ?string
    {
        return $this->daemonPidFile;
    }

    /**
     * Check if the daemon process is alive (if this connector has one).
     */
    public function isDaemonRunning(): ?bool
    {
        if ($this->daemonPidFile === null) {
            return null;
        }
        if (!file_exists($this->daemonPidFile)) {
            return false;
        }
        $pid = trim(@file_get_contents($this->daemonPidFile));
        if (empty($pid)) {
            return false;
        }
        // Check if process is alive (POSIX platforms)
        if (function_exists('posix_kill')) {
            return posix_kill((int)$pid, 0);
        }
        // Fallback: /proc check (Linux)
        return is_dir("/proc/{$pid}");
    }

    /**
     * Default health implementation. Subclasses can override for richer checks.
     */
    public function getHealth(): array
    {
        if (!$this->isEnabled()) {
            return ['status' => 'DISABLED', 'details' => ['reason' => 'hibernation']];
        }

        $details = [];

        // Circuit breaker status
        if ($this->circuitBreaker) {
            $cbState = $this->circuitBreaker->getState();
            $details['circuit_breaker'] = [
                'is_open'       => $cbState['is_open'],
                'recent_errors' => $cbState['recent_errors'],
                'cooldown_until' => $cbState['cooldown_until'] > 0
                    ? gmdate('Y-m-d\TH:i:s\Z', $cbState['cooldown_until'])
                    : null,
            ];
            if ($cbState['is_open']) {
                return ['status' => 'DOWN', 'details' => $details];
            }
        }

        // Daemon status
        $daemonAlive = $this->isDaemonRunning();
        if ($daemonAlive !== null) {
            $details['daemon_running'] = $daemonAlive;
            if (!$daemonAlive && in_array($this->type, ['poll', 'bidirectional'])) {
                return ['status' => 'DEGRADED', 'details' => $details];
            }
        }

        return ['status' => 'OK', 'details' => $details];
    }

    public function getConfig(): array
    {
        return [
            'name'       => $this->name,
            'source_id'  => $this->sourceId,
            'type'       => $this->type,
            'enabled'    => $this->isEnabled(),
            'has_daemon' => $this->daemonPidFile !== null,
        ];
    }

    /**
     * Serialize to array for API response.
     */
    public function toArray(): array
    {
        $health = $this->getHealth();
        return [
            'name'      => $this->name,
            'source_id' => $this->sourceId,
            'type'      => $this->type,
            'enabled'   => $this->isEnabled(),
            'status'    => $health['status'],
            'health'    => $health['details'],
            'endpoints' => $this->getEndpoints(),
            'config'    => $this->getConfig(),
        ];
    }
}
