<?php
/**
 * ConnectorHealth — aggregates health status across all VATSWIM connectors.
 *
 * Used by the lightweight health endpoint to provide a quick summary:
 *   { status: "OK"|"DEGRADED"|"DOWN", connectors: [{name, status}] }
 */

namespace PERTI\Lib\Connectors;

class ConnectorHealth
{
    /**
     * Get aggregate health across all registered connectors.
     *
     * @return array{status: string, connectors: array, checked_at: string}
     */
    public static function getAggregate(): array
    {
        $connectors = ConnectorRegistry::getAll();
        $summary = [];
        $statuses = [];

        foreach ($connectors as $key => $connector) {
            $health = $connector->getHealth();
            $summary[] = [
                'name'   => $connector->getName(),
                'key'    => $key,
                'status' => $health['status'],
            ];
            $statuses[] = $health['status'];
        }

        // Determine aggregate status
        $aggregate = self::computeAggregate($statuses);

        return [
            'status'     => $aggregate,
            'connectors' => $summary,
            'checked_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * Get detailed health for all connectors (status endpoint).
     *
     * @return array Full per-connector health including details
     */
    public static function getDetailed(): array
    {
        $connectors = ConnectorRegistry::getAll();
        $result = [];

        foreach ($connectors as $key => $connector) {
            $result[$key] = $connector->toArray();
        }

        return [
            'status'     => self::computeAggregate(
                array_map(fn($c) => $c['status'], $result)
            ),
            'connectors' => $result,
            'checked_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * Compute aggregate status from individual statuses.
     *
     * Rules:
     *   - All OK → OK
     *   - All DISABLED → DISABLED
     *   - Any DOWN → DEGRADED (not all connectors are down)
     *   - All DOWN → DOWN
     *   - Any DEGRADED → DEGRADED
     *   - Mix of OK and DISABLED → OK
     */
    private static function computeAggregate(array $statuses): string
    {
        if (empty($statuses)) {
            return 'OK';
        }

        $unique = array_unique($statuses);

        // All same status
        if (count($unique) === 1) {
            return $unique[0];
        }

        // Filter out DISABLED for aggregate calculation
        $active = array_filter($statuses, fn($s) => $s !== 'DISABLED');
        if (empty($active)) {
            return 'DISABLED';
        }

        $activeUnique = array_unique($active);

        if (count($activeUnique) === 1 && $activeUnique[0] === 'DOWN') {
            return 'DOWN';
        }

        if (in_array('DOWN', $active) || in_array('DEGRADED', $active)) {
            return 'DEGRADED';
        }

        return 'OK';
    }
}
