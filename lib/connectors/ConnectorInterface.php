<?php
/**
 * ConnectorInterface — defines the contract for VATSWIM external connectors.
 *
 * Each connector describes one external data source that feeds VATSWIM:
 * its type (push/poll/bidirectional), endpoints, health state, and config.
 */

namespace PERTI\Lib\Connectors;

interface ConnectorInterface
{
    /**
     * Human-readable name (e.g., "vNAS", "SimTraffic", "ECFMP").
     */
    public function getName(): string;

    /**
     * Source identifier from $SWIM_DATA_SOURCES in swim_config.php.
     */
    public function getSourceId(): string;

    /**
     * Integration type: 'push', 'poll', or 'bidirectional'.
     */
    public function getType(): string;

    /**
     * Whether this connector is currently enabled (not hibernated, configured).
     */
    public function isEnabled(): bool;

    /**
     * Get health status for this connector.
     *
     * @return array{status: string, details: array}
     *   status: 'OK', 'DEGRADED', 'DOWN', 'DISABLED'
     *   details: connector-specific health data
     */
    public function getHealth(): array;

    /**
     * Get the ingest/API endpoint paths this connector uses.
     *
     * @return array<string, string> label => relative path
     */
    public function getEndpoints(): array;

    /**
     * Get connector configuration for status reporting.
     *
     * @return array Connector-specific config (sanitized — no secrets)
     */
    public function getConfig(): array;
}
