<?php
/**
 * ConnectorRegistry — static registry of all VATSWIM connectors.
 *
 * Lazy-instantiates source connector classes. Used by the health/status
 * API endpoints and ConnectorHealth aggregator.
 */

namespace PERTI\Lib\Connectors;

class ConnectorRegistry
{
    private static ?array $connectors = null;

    /**
     * Source connector class map.
     * Keys are short names; values are class names under Sources namespace.
     */
    private static array $sourceClasses = [
        'vnas'             => 'VNASConnector',
        'simtraffic'       => 'SimTrafficConnector',
        'vacdm'            => 'VACDMConnector',
        'ecfmp'            => 'ECFMPConnector',
        'hoppie'           => 'HoppieConnector',
        'vatis'            => 'VATISConnector',
        'virtual_airline'  => 'VirtualAirlineConnector',
    ];

    /**
     * Get all registered connectors (lazy-loaded).
     *
     * @return ConnectorInterface[]
     */
    public static function getAll(): array
    {
        if (self::$connectors === null) {
            self::$connectors = self::loadAll();
        }
        return self::$connectors;
    }

    /**
     * Get a single connector by short name.
     */
    public static function get(string $name): ?ConnectorInterface
    {
        $all = self::getAll();
        return $all[$name] ?? null;
    }

    /**
     * Get connectors filtered by type.
     *
     * @param string $type 'push', 'poll', or 'bidirectional'
     * @return ConnectorInterface[]
     */
    public static function getByType(string $type): array
    {
        return array_filter(self::getAll(), fn($c) => $c->getType() === $type);
    }

    /**
     * Reset the registry (for testing).
     */
    public static function reset(): void
    {
        self::$connectors = null;
    }

    /**
     * Load all source connector instances.
     */
    private static function loadAll(): array
    {
        $connectors = [];
        $sourcesDir = __DIR__ . '/sources';

        foreach (self::$sourceClasses as $key => $className) {
            $file = $sourcesDir . '/' . $className . '.php';
            if (!file_exists($file)) {
                continue;
            }
            require_once $file;
            $fqcn = __NAMESPACE__ . '\\Sources\\' . $className;
            if (class_exists($fqcn)) {
                $connectors[$key] = new $fqcn();
            }
        }

        return $connectors;
    }
}
