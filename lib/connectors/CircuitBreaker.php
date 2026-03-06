<?php
/**
 * File-based circuit breaker for VATSWIM connector poll daemons.
 *
 * Tracks errors within a rolling time window and trips into a cooldown
 * state when the error threshold is exceeded. State is persisted to a
 * JSON file so it survives daemon restarts.
 *
 * Extracted from 3 duplicate implementations in:
 *   - scripts/simtraffic_swim_poll.php
 *   - scripts/ecfmp_poll_daemon.php
 *   - scripts/vacdm_poll_daemon.php
 *
 * Usage:
 *   $cb = new CircuitBreaker('/tmp/perti_myservice_state.json');
 *   if ($cb->isOpen()) { return; }
 *   try { ... } catch (...) {
 *       if ($cb->recordError()) { echo "Tripped!"; }
 *   }
 *   $cb->recordSuccess(); // reset on success
 */

namespace PERTI\Lib\Connectors;

class CircuitBreaker
{
    private string $stateFile;
    private int $windowSec;
    private int $maxErrors;
    private int $cooldownSec;

    /**
     * @param string $stateFile   Path to JSON state file
     * @param int    $windowSec   Rolling error window in seconds (default 60)
     * @param int    $maxErrors   Errors within window to trip (default 6)
     * @param int    $cooldownSec Cooldown duration after trip (default 180)
     */
    public function __construct(
        string $stateFile,
        int $windowSec = 60,
        int $maxErrors = 6,
        int $cooldownSec = 180
    ) {
        $this->stateFile   = $stateFile;
        $this->windowSec   = $windowSec;
        $this->maxErrors   = $maxErrors;
        $this->cooldownSec = $cooldownSec;
    }

    /**
     * Check if the circuit breaker is currently open (in cooldown).
     */
    public function isOpen(): bool
    {
        $state = $this->readState();

        // In cooldown?
        if (!empty($state['cooldown_until']) && $state['cooldown_until'] > time()) {
            return true;
        }

        // Check recent error count (covers edge case where cooldown expired
        // but errors are still within window and above threshold)
        $cutoff = time() - $this->windowSec;
        $recent = array_filter($state['errors'], fn($t) => $t > $cutoff);
        return count($recent) >= $this->maxErrors;
    }

    /**
     * Record an error. Auto-trips the circuit if threshold is exceeded.
     *
     * @return bool True if the circuit tripped on this call
     */
    public function recordError(): bool
    {
        $state = $this->readState();
        $now = time();

        // Prune errors outside the rolling window
        $cutoff = $now - $this->windowSec;
        $state['errors'] = array_values(array_filter(
            $state['errors'] ?? [],
            fn($ts) => $ts > $cutoff
        ));

        // Add this error
        $state['errors'][] = $now;

        // Check if we should trip
        $tripped = false;
        if (count($state['errors']) >= $this->maxErrors) {
            $state['cooldown_until'] = $now + $this->cooldownSec;
            $state['errors'] = [];
            $tripped = true;
        }

        $this->writeState($state);
        return $tripped;
    }

    /**
     * Record a success — resets the circuit breaker state.
     */
    public function recordSuccess(): void
    {
        $this->reset();
    }

    /**
     * Reset the circuit breaker (clear errors and cooldown).
     */
    public function reset(): void
    {
        $this->writeState(['errors' => [], 'cooldown_until' => 0]);
    }

    /**
     * Get the current state for health reporting.
     *
     * @return array{errors: int[], cooldown_until: int, is_open: bool, recent_errors: int}
     */
    public function getState(): array
    {
        $state = $this->readState();
        $cutoff = time() - $this->windowSec;
        $recentErrors = array_filter($state['errors'] ?? [], fn($t) => $t > $cutoff);

        return [
            'errors'         => $state['errors'] ?? [],
            'cooldown_until' => $state['cooldown_until'] ?? 0,
            'is_open'        => $this->isOpen(),
            'recent_errors'  => count($recentErrors),
        ];
    }

    /**
     * Get the state file path (for health/status reporting).
     */
    public function getStateFile(): string
    {
        return $this->stateFile;
    }

    // ─── Internal ────────────────────────────────────────────────────────

    private function readState(): array
    {
        if (!file_exists($this->stateFile)) {
            return ['errors' => [], 'cooldown_until' => 0];
        }
        $data = @json_decode(@file_get_contents($this->stateFile), true);
        return is_array($data) ? $data : ['errors' => [], 'cooldown_until' => 0];
    }

    private function writeState(array $state): void
    {
        $dir = dirname($this->stateFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($this->stateFile, json_encode($state), LOCK_EX);
    }
}
