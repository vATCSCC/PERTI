<?php
/**
 * VNASService — VNAS Controller Feed Integration
 *
 * Polls the VNAS live data feed to detect active ATC controllers.
 * Feed URL: https://live.env.vnas.vatsim.net/data-feed/controllers.json
 * Cache: File-based with 60-second TTL.
 */
class VNASService
{
    private const FEED_URL = 'https://live.env.vnas.vatsim.net/data-feed/controllers.json';
    private const CACHE_TTL = 60;
    private const CACHE_FILE = '/tmp/vnas_controllers.json';

    /**
     * Get all active controllers from VNAS feed (cached).
     * @return array Array of controller objects
     */
    public static function getControllers(): array
    {
        $cache = self::readCache();
        if ($cache !== null) {
            return $cache;
        }

        $ch = curl_init(self::FEED_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            return self::readCache(true) ?? [];
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            return self::readCache(true) ?? [];
        }

        self::writeCache($data);

        return $data;
    }

    /**
     * Find a controller by VATSIM CID.
     * @param int $cid VATSIM CID
     * @return array|null Controller data or null
     */
    public static function findByCID(int $cid): ?array
    {
        $controllers = self::getControllers();
        $cidStr = (string)$cid;

        foreach ($controllers as $controller) {
            $controllerCid = $controller['vatsimData']['cid'] ?? null;
            if ($controllerCid !== null && (string)$controllerCid === $cidStr) {
                return $controller;
            }
        }

        return null;
    }

    /**
     * Quick check if CID is an active controller.
     */
    public static function isActiveController(int $cid): bool
    {
        return self::findByCID($cid) !== null;
    }

    /**
     * Extract role context from controller data.
     */
    public static function extractContext(array $controller): array
    {
        $positions = $controller['positions'] ?? [];
        $firstPos = $positions[0] ?? [];

        return [
            'artcc_id' => $controller['artccId'] ?? null,
            'facility_id' => $firstPos['facilityId'] ?? $controller['primaryFacilityId'] ?? null,
            'position_type' => $firstPos['positionType'] ?? null,
            'callsign' => $controller['vatsimData']['callsign'] ?? null,
            'facility_name' => $firstPos['facilityName'] ?? null,
        ];
    }

    private static function readCache(bool $ignoreExpiry = false): ?array
    {
        if (!file_exists(self::CACHE_FILE)) return null;

        $raw = @file_get_contents(self::CACHE_FILE);
        if ($raw === false) return null;

        $cached = json_decode($raw, true);
        if (!is_array($cached) || !isset($cached['data']) || !isset($cached['timestamp'])) return null;

        if (!$ignoreExpiry && (time() - $cached['timestamp']) > self::CACHE_TTL) {
            return null;
        }

        return $cached['data'];
    }

    private static function writeCache(array $data): void
    {
        $payload = json_encode([
            'timestamp' => time(),
            'data' => $data,
        ]);
        @file_put_contents(self::CACHE_FILE, $payload, LOCK_EX);
    }
}
