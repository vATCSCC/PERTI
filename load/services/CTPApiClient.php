<?php
/**
 * CTP API Client
 *
 * Fetches routes from the CTP API (vatsimnetwork/ctp-api), transforms
 * RouteSegment objects to PERTI internal format, and computes content
 * hashes for change detection.
 */

class CTPApiException extends \RuntimeException {}

class CTPApiClient
{
    private string $baseUrl;
    private string $apiKey;
    private int    $timeout;

    public function __construct(string $baseUrl, string $apiKey, int $timeout = 30) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey  = $apiKey;
        $this->timeout = $timeout;
    }

    /**
     * Fetch all routes from CTP API.
     *
     * GET /api/Routes — returns RouteSegment[] with Locations[].
     * ASP.NET Core serializes properties as camelCase.
     *
     * @return array Raw CTP API response (array of RouteSegment objects)
     * @throws CTPApiException on HTTP error, timeout, or invalid JSON
     */
    public function fetchRoutes(): array {
        $url = $this->baseUrl . '/api/Routes';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => [
                'X-API-Key: ' . $this->apiKey,
                'Accept: application/json',
            ],
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            // Retry once on timeout
            if (stripos($curlErr, 'timeout') !== false || stripos($curlErr, 'timed out') !== false) {
                $ch2 = curl_init($url);
                curl_setopt_array($ch2, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => $this->timeout,
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_HTTPHEADER     => [
                        'X-API-Key: ' . $this->apiKey,
                        'Accept: application/json',
                    ],
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS      => 3,
                ]);
                $response = curl_exec($ch2);
                $httpCode = (int)curl_getinfo($ch2, CURLINFO_HTTP_CODE);
                $curlErr  = curl_error($ch2);
                curl_close($ch2);
            }
            if ($response === false) {
                throw new CTPApiException("CTP API request failed: {$curlErr}");
            }
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new CTPApiException("CTP API returned HTTP {$httpCode}: " . substr($response, 0, 500));
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new CTPApiException("CTP API returned invalid JSON");
        }

        return $data;
    }

    /**
     * Check if CTP API is reachable.
     *
     * @return bool True if API responds with 2xx
     */
    public function isAvailable(): bool {
        try {
            $ch = curl_init($this->baseUrl . '/api/Routes');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_HTTPHEADER     => [
                    'X-API-Key: ' . $this->apiKey,
                    'Accept: application/json',
                ],
                CURLOPT_NOBODY => true,
            ]);
            curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return $code >= 200 && $code < 300;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Transform CTP API RouteSegment objects to PERTI internal route format.
     *
     * CTP API (camelCase):              PERTI internal:
     * - identifier                   -> identifier
     * - routeString                  -> routestring
     * - routeSegmentGroup            -> group
     * - routeSegmentTags (array)     -> tags (space-separated)
     * - locations[0].identifier      -> origin
     * - locations[-1].identifier     -> dest
     * - maximumAircraftPerHour       -> throughput.peak_rate_hr (if > 0)
     *
     * @param array $ctpRoutes Raw CTP API response
     * @return array Routes in PERTI internal format
     */
    public static function transformRoutes(array $ctpRoutes): array {
        $result = [];
        foreach ($ctpRoutes as $seg) {
            $route = [
                'identifier'  => $seg['identifier'] ?? '',
                'group'       => $seg['routeSegmentGroup'] ?? '',
                'routestring' => $seg['routeString'] ?? '',
                'tags'        => is_array($seg['routeSegmentTags'] ?? null)
                                 ? implode(' ', $seg['routeSegmentTags'])
                                 : '',
                'facilities'  => '',
            ];

            // Extract origin/dest from Locations array
            $locs = $seg['locations'] ?? [];
            if (!empty($locs)) {
                $route['origin'] = $locs[0]['identifier'] ?? '';
                $lastLoc = end($locs);
                $route['dest'] = $lastLoc['identifier'] ?? '';
            } else {
                $route['origin'] = '';
                $route['dest']   = '';
            }

            // Extract throughput from maximumAircraftPerHour
            $maxRate = (int)($seg['maximumAircraftPerHour'] ?? 0);
            if ($maxRate > 0) {
                $route['throughput'] = ['peak_rate_hr' => $maxRate];
            }

            $result[] = $route;
        }
        return $result;
    }

    /**
     * Compute a deterministic content hash of the CTP route set.
     *
     * Used for change detection: if the hash matches the last sync,
     * no data has changed and the sync can be skipped.
     *
     * @param array $ctpRoutes Raw CTP API response (before transformation)
     * @return string MD5 hash (32 hex chars)
     */
    public static function computeContentHash(array $ctpRoutes): string {
        $normalized = [];
        foreach ($ctpRoutes as $r) {
            $tags = $r['routeSegmentTags'] ?? [];
            if (is_array($tags)) sort($tags);
            $normalized[] = [
                'id' => $r['identifier'] ?? '',
                'rs' => strtoupper(trim($r['routeString'] ?? '')),
                'gr' => strtoupper($r['routeSegmentGroup'] ?? ''),
                'tg' => is_array($tags) ? implode(',', $tags) : '',
                'mr' => (int)($r['maximumAircraftPerHour'] ?? 0),
                'lc' => array_map(
                    fn($l) => $l['identifier'] ?? '',
                    $r['locations'] ?? []
                ),
            ];
        }
        usort($normalized, fn($a, $b) => strcmp($a['id'], $b['id']));
        return md5(json_encode($normalized));
    }
}
