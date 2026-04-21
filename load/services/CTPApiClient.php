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
     * GET /api/route-segments — returns RouteSegment[] with nested Locations[].waypoint.
     *
     * @return array Raw CTP API response (array of RouteSegment objects)
     * @throws CTPApiException on HTTP error, timeout, or invalid JSON
     */
    public function fetchRoutes(): array {
        $url = $this->baseUrl . '/api/route-segments';

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
            $ch = curl_init($this->baseUrl . '/api/route-segments');
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
     * CTP API (camelCase):                          PERTI internal:
     * - identifier                               -> identifier
     * - routeString                              -> routestring
     * - routeSegmentGroup                        -> group
     * - routeSegmentTags (array, may be null)    -> tags (space-separated)
     * - locations[0].waypoint.identifier         -> origin
     * - locations[-1].waypoint.identifier        -> dest
     * - maximumAircraftPerHour                   -> throughput.peak_rate_hr (if > 0)
     * - enabled                                  -> enabled (bool)
     * - color                                    -> color
     * - facilities                               -> facilities
     *
     * @param array $ctpRoutes Raw CTP API response
     * @param bool  $enabledOnly Only include routes where enabled=true (default true)
     * @return array Routes in PERTI internal format
     */
    public static function transformRoutes(array $ctpRoutes, bool $enabledOnly = true): array {
        $result = [];
        foreach ($ctpRoutes as $seg) {
            // Skip disabled routes unless explicitly requested
            if ($enabledOnly && !($seg['enabled'] ?? true)) {
                continue;
            }

            $route = [
                'identifier'  => $seg['identifier'] ?? '',
                'group'       => $seg['routeSegmentGroup'] ?? '',
                'routestring' => $seg['routeString'] ?? '',
                'tags'        => is_array($seg['routeSegmentTags'] ?? null)
                                 ? implode(' ', $seg['routeSegmentTags'])
                                 : '',
                'facilities'  => $seg['facilities'] ?? '',
                'color'       => $seg['color'] ?? '',
                'enabled'     => (bool)($seg['enabled'] ?? true),
            ];

            // Extract origin/dest from nested Locations[].waypoint.identifier
            // Segment groups are partial routes — mask non-terminal endpoints as UNKN:
            //   AMAS (NA):  origin = airport, dest = UNKN  (ends at oceanic entry)
            //   OCA:        origin = UNKN,    dest = UNKN  (oceanic-only segment)
            //   EMEA (EU):  origin = UNKN,    dest = airport (starts at oceanic exit)
            //   FULL:       origin = airport, dest = airport (stitched end-to-end)
            $grpUpper = strtoupper($route['group']);
            $locs = $seg['locations'] ?? [];
            if (!empty($locs)) {
                // Sort by sortOrder to ensure correct origin/dest
                usort($locs, fn($a, $b) => ($a['sortOrder'] ?? 0) <=> ($b['sortOrder'] ?? 0));
                $first = $locs[0];
                $last  = end($locs);
                $firstId = $first['waypoint']['identifier'] ?? ($first['identifier'] ?? '');
                $lastId  = $last['waypoint']['identifier']  ?? ($last['identifier'] ?? '');

                if ($grpUpper === 'OCA') {
                    $route['origin'] = 'UNKN';
                    $route['dest']   = 'UNKN';
                } elseif ($grpUpper === 'AMAS') {
                    $route['origin'] = $firstId;
                    $route['dest']   = 'UNKN';
                } elseif ($grpUpper === 'EMEA') {
                    $route['origin'] = 'UNKN';
                    $route['dest']   = $lastId;
                } else {
                    $route['origin'] = $firstId;
                    $route['dest']   = $lastId;
                }
            } else {
                $route['origin'] = 'UNKN';
                $route['dest']   = 'UNKN';
            }

            // Strip origin/dest airport codes from routestring to avoid duplication
            // (airports already stored in origin/dest columns)
            $rsParts = preg_split('/\s+/', trim($route['routestring']));
            if (!empty($rsParts)) {
                if ($grpUpper === 'AMAS' && $route['origin'] !== 'UNKN'
                    && strtoupper($rsParts[0]) === strtoupper($route['origin'])) {
                    array_shift($rsParts);
                }
                if ($grpUpper === 'EMEA' && $route['dest'] !== 'UNKN'
                    && strtoupper(end($rsParts)) === strtoupper($route['dest'])) {
                    array_pop($rsParts);
                }
                $route['routestring'] = implode(' ', $rsParts);
            }

            // Extract throughput from maximumAircraftPerHour
            $maxRate = (int)($seg['maximumAircraftPerHour'] ?? 0);
            if ($maxRate > 0 && $maxRate < 65535) {
                $route['throughput'] = ['peak_rate_hr' => $maxRate];
            }

            $result[] = $route;
        }
        return $result;
    }

    /**
     * Fetch the latest slot revision from the CTP API.
     *
     * GET /api/events/{eventId}/slot-revisions/latest
     *
     * @param int $eventId CTP event ID (default 1)
     * @return array Slot revision object with 'slots' array
     * @throws CTPApiException on HTTP error, timeout, or invalid JSON
     */
    public function fetchSlotRevision(int $eventId = 1): array {
        $url = $this->baseUrl . "/api/events/{$eventId}/slot-revisions/latest";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
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
            throw new CTPApiException("CTP slot revision fetch failed: {$curlErr}");
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new CTPApiException("CTP slot revision returned HTTP {$httpCode}: " . substr($response, 0, 500));
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new CTPApiException("CTP slot revision returned invalid JSON");
        }

        return $data;
    }

    /**
     * Extract unique slot-assigned route combinations from a slot revision.
     *
     * Each slot has 3 route segments (AMAS + OCA + EMEA). This extracts
     * the unique combinations and stitches them into full route strings
     * with airports stripped (stored in origin/dest columns instead).
     *
     * @param array $slotRevision Raw slot revision from fetchSlotRevision()
     * @return array Routes in PERTI internal format with group='SLOTTED'
     */
    public static function extractSlotRoutes(array $slotRevision): array {
        $slots = $slotRevision['slots'] ?? [];
        $combos = [];

        foreach ($slots as $slot) {
            $segs = $slot['routeSegments'] ?? [];
            $byGroup = [];
            foreach ($segs as $seg) {
                $byGroup[$seg['routeSegmentGroup'] ?? ''] = $seg;
            }

            $na  = $byGroup['AMAS'] ?? null;
            $oca = $byGroup['OCA']  ?? null;
            $eu  = $byGroup['EMEA'] ?? null;
            if (!$na || !$oca || !$eu) continue;

            $naId  = $na['identifier']  ?? '';
            $ocaId = $oca['identifier'] ?? '';
            $euId  = $eu['identifier']  ?? '';
            $comboKey = "{$naId}|{$ocaId}|{$euId}";

            if (isset($combos[$comboKey])) {
                $combos[$comboKey]['slot_count']++;
                continue;
            }

            // Parse route strings
            $naParts  = preg_split('/\s+/', trim($na['routeString']  ?? ''));
            $ocaParts = preg_split('/\s+/', trim($oca['routeString'] ?? ''));
            $euParts  = preg_split('/\s+/', trim($eu['routeString']  ?? ''));

            $origin = strtoupper($naParts[0] ?? '');
            $dest   = strtoupper(end($euParts) ?: '');

            // Build full route: NA body (skip origin) + OCA body (skip entry) + EU body (skip exit + dest)
            $naBody  = implode(' ', array_slice($naParts, 1));
            $ocaBody = implode(' ', array_slice($ocaParts, 1));
            $euBody  = implode(' ', array_slice($euParts, 1, max(0, count($euParts) - 2)));

            $fullRs = $naBody;
            if ($ocaBody !== '') $fullRs .= ' ' . $ocaBody;
            if ($euBody  !== '') $fullRs .= ' ' . $euBody;

            $eid = "{$naId}_{$ocaId}_{$euId}";
            $combos[$comboKey] = [
                'identifier'  => $eid,
                'group'       => 'SLOTTED',
                'routestring' => trim($fullRs),
                'tags'        => "{$naId} {$ocaId} {$euId}",
                'facilities'  => '',
                'color'       => '',
                'enabled'     => true,
                'origin'      => $origin,
                'dest'        => $dest,
                'slot_count'  => 1,
            ];
        }

        return array_values($combos);
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

            // Extract waypoint identifiers from nested structure
            $locs = $r['locations'] ?? [];
            usort($locs, fn($a, $b) => ($a['sortOrder'] ?? 0) <=> ($b['sortOrder'] ?? 0));
            $locIds = array_map(
                fn($l) => $l['waypoint']['identifier'] ?? ($l['identifier'] ?? ''),
                $locs
            );

            $normalized[] = [
                'id' => $r['identifier'] ?? '',
                'rs' => strtoupper(trim($r['routeString'] ?? '')),
                'gr' => strtoupper($r['routeSegmentGroup'] ?? ''),
                'tg' => is_array($tags) ? implode(',', $tags) : '',
                'mr' => (int)($r['maximumAircraftPerHour'] ?? 0),
                'en' => (bool)($r['enabled'] ?? true),
                'lc' => $locIds,
            ];
        }
        usort($normalized, fn($a, $b) => strcmp($a['id'], $b['id']));
        return md5(json_encode($normalized));
    }
}
