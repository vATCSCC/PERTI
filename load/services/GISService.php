<?php
/**
 * GIS Service - PostGIS Spatial Query Interface
 *
 * Provides boundary intersection, point-in-polygon, and route analysis
 * using the VATSIM_GIS PostgreSQL/PostGIS database.
 *
 * @version 1.0.0
 * @date 2026-01-29
 *
 * Usage:
 *   $gis = GISService::getInstance();
 *   if ($gis) {
 *       $artccs = $gis->getRouteARTCCs($waypoints);
 *       $boundaries = $gis->getRouteBoundaries($waypoints, 35000);
 *   }
 */

require_once(__DIR__ . '/../airport_aliases.php');

class GISService
{
    private ?PDO $conn = null;
    private static ?GISService $instance = null;
    private ?string $lastError = null;

    /**
     * Private constructor - use getInstance()
     */
    private function __construct()
    {
        // Get connection from connect.php
        if (function_exists('get_conn_gis')) {
            $this->conn = get_conn_gis();
        }
    }

    /**
     * Get singleton instance
     *
     * @return GISService|null Returns instance if connection successful, null otherwise
     */
    public static function getInstance(): ?GISService
    {
        if (self::$instance === null) {
            self::$instance = new GISService();
        }

        return self::$instance->conn ? self::$instance : null;
    }

    /**
     * Check if service is available
     *
     * @return bool
     */
    public function isConnected(): bool
    {
        return $this->conn !== null;
    }

    /**
     * Get the last error message
     *
     * @return string|null
     */
    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    // =========================================================================
    // ROUTE ANALYSIS METHODS
    // =========================================================================

    /**
     * Get ARTCCs traversed by a route (from waypoints)
     *
     * @param array $waypoints Array of ['lat' => float, 'lon' => float]
     * @return array ARTCC information in traversal order
     */
    public function getRouteARTCCs(array $waypoints): array
    {
        if (!$this->conn || count($waypoints) < 2) {
            return [];
        }

        try {
            // Convert waypoints to JSONB format expected by PostGIS function
            $waypointsJson = $this->formatWaypointsJson($waypoints);

            $sql = "SELECT * FROM get_route_artccs_from_waypoints(:waypoints::jsonb)";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':waypoints' => $waypointsJson]);

            $artccs = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $artccs[] = [
                    'artcc_code' => $row['artcc_code'],
                    'fir_name' => $row['fir_name'],
                    'traversal_order' => (float)$row['traversal_order']
                ];
            }

            return $artccs;

        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            error_log('GISService::getRouteARTCCs error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get TRACONs traversed by a route
     *
     * @param array $waypoints Array of ['lat' => float, 'lon' => float]
     * @return array TRACON information in traversal order
     */
    public function getRouteTRACONs(array $waypoints): array
    {
        if (!$this->conn || count($waypoints) < 2) {
            return [];
        }

        try {
            $waypointsJson = $this->formatWaypointsJson($waypoints);

            $sql = "SELECT * FROM get_route_tracons(:waypoints::jsonb)";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':waypoints' => $waypointsJson]);

            $tracons = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $tracons[] = [
                    'tracon_code' => $row['tracon_code'],
                    'tracon_name' => $row['tracon_name'],
                    'parent_artcc' => $row['parent_artcc'],
                    'traversal_order' => (float)$row['traversal_order']
                ];
            }

            return $tracons;

        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            error_log('GISService::getRouteTRACONs error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get all boundaries traversed by a route
     *
     * @param array $waypoints Array of ['lat' => float, 'lon' => float]
     * @param int $cruiseAltitude Cruise altitude in feet for sector filtering
     * @param bool $includeSectors Include sector-level detail
     * @return array Grouped by boundary type
     */
    public function getRouteBoundaries(array $waypoints, int $cruiseAltitude = 35000, bool $includeSectors = true): array
    {
        if (!$this->conn || count($waypoints) < 2) {
            return [
                'artccs' => [],
                'tracons' => [],
                'sectors_low' => [],
                'sectors_high' => [],
                'sectors_superhigh' => []
            ];
        }

        try {
            $waypointsJson = $this->formatWaypointsJson($waypoints);

            $sql = "SELECT * FROM get_route_boundaries(:waypoints::jsonb, :altitude, :include_sectors)";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                ':waypoints' => $waypointsJson,
                ':altitude' => $cruiseAltitude,
                ':include_sectors' => $includeSectors ? 'true' : 'false'
            ]);

            $result = [
                'artccs' => [],
                'tracons' => [],
                'sectors_low' => [],
                'sectors_high' => [],
                'sectors_superhigh' => []
            ];

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $boundary = [
                    'code' => $row['boundary_code'],
                    'name' => $row['boundary_name'],
                    'parent_artcc' => $row['parent_artcc'],
                    'floor' => $row['floor_altitude'],
                    'ceiling' => $row['ceiling_altitude'],
                    'order' => (float)$row['traversal_order'],
                    'entry_point' => json_decode($row['entry_point'] ?? 'null', true),
                    'exit_point' => json_decode($row['exit_point'] ?? 'null', true)
                ];

                switch ($row['boundary_type']) {
                    case 'ARTCC':
                        $result['artccs'][] = $boundary;
                        break;
                    case 'TRACON':
                        $result['tracons'][] = $boundary;
                        break;
                    case 'SECTOR_LOW':
                        $result['sectors_low'][] = $boundary;
                        break;
                    case 'SECTOR_HIGH':
                        $result['sectors_high'][] = $boundary;
                        break;
                    case 'SECTOR_SUPERHIGH':
                        $result['sectors_superhigh'][] = $boundary;
                        break;
                }
            }

            // Sort by traversal order
            usort($result['artccs'], fn($a, $b) => $a['order'] <=> $b['order']);
            usort($result['tracons'], fn($a, $b) => $a['order'] <=> $b['order']);

            return $result;

        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            error_log('GISService::getRouteBoundaries error: ' . $e->getMessage());
            return [
                'artccs' => [],
                'tracons' => [],
                'sectors_low' => [],
                'sectors_high' => [],
                'sectors_superhigh' => []
            ];
        }
    }

    // =========================================================================
    // POINT-IN-POLYGON METHODS
    // =========================================================================

    /**
     * Get all boundaries containing a geographic point
     *
     * @param float $lat Latitude
     * @param float $lon Longitude
     * @param int|null $altitude Altitude in feet (for sector filtering)
     * @return array Boundary information grouped by type
     */
    public function getBoundariesAtPoint(float $lat, float $lon, ?int $altitude = null): array
    {
        if (!$this->conn) {
            return [];
        }

        try {
            $sql = "SELECT * FROM get_boundaries_at_point(:lat, :lon, :alt)";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                ':lat' => $lat,
                ':lon' => $lon,
                ':alt' => $altitude
            ]);

            $result = [
                'artcc' => null,
                'tracon' => null,
                'sectors_low' => [],
                'sectors_high' => [],
                'sectors_superhigh' => []
            ];

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $boundary = [
                    'code' => $row['boundary_code'],
                    'name' => $row['boundary_name'],
                    'parent_artcc' => $row['parent_artcc'],
                    'floor' => $row['floor_altitude'],
                    'ceiling' => $row['ceiling_altitude'],
                    'is_oceanic' => $row['is_oceanic'] ?? false
                ];

                switch ($row['boundary_type']) {
                    case 'ARTCC':
                        $result['artcc'] = $boundary;
                        break;
                    case 'TRACON':
                        $result['tracon'] = $boundary;
                        break;
                    case 'SECTOR_LOW':
                        $result['sectors_low'][] = $boundary;
                        break;
                    case 'SECTOR_HIGH':
                        $result['sectors_high'][] = $boundary;
                        break;
                    case 'SECTOR_SUPERHIGH':
                        $result['sectors_superhigh'][] = $boundary;
                        break;
                }
            }

            return $result;

        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            error_log('GISService::getBoundariesAtPoint error: ' . $e->getMessage());
            return [];
        }
    }

    // =========================================================================
    // TMI ROUTE ANALYSIS
    // =========================================================================

    /**
     * Analyze TMI route proposal
     *
     * @param array|string $routeGeojson GeoJSON geometry or coordinates array
     * @param string|null $originIcao Origin airport ICAO (optional)
     * @param string|null $destIcao Destination airport ICAO (optional)
     * @param int $cruiseAltitude Cruise altitude for sector filtering
     * @return array Analysis result with facilities_traversed, artccs_traversed, etc.
     */
    public function analyzeTMIRoute($routeGeojson, ?string $originIcao = null, ?string $destIcao = null, int $cruiseAltitude = 35000): array
    {
        if (!$this->conn) {
            return [
                'facilities_traversed' => [],
                'artccs_traversed' => [],
                'tracons_traversed' => [],
                'sectors_traversed' => [],
                'origin_artcc' => null,
                'dest_artcc' => null
            ];
        }

        try {
            // Convert to JSON string if array
            $geojson = is_string($routeGeojson) ? $routeGeojson : json_encode($routeGeojson);

            $sql = "SELECT * FROM analyze_tmi_route(:geojson::jsonb, :origin, :dest, :altitude)";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                ':geojson' => $geojson,
                ':origin' => $originIcao,
                ':dest' => $destIcao,
                ':altitude' => $cruiseAltitude
            ]);

            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                return [
                    'facilities_traversed' => [],
                    'artccs_traversed' => [],
                    'tracons_traversed' => [],
                    'sectors_traversed' => [],
                    'origin_artcc' => null,
                    'dest_artcc' => null
                ];
            }

            return [
                'facilities_traversed' => $this->pgArrayToPhp($row['facilities_traversed']),
                'artccs_traversed' => $this->pgArrayToPhp($row['artccs_traversed']),
                'tracons_traversed' => $this->pgArrayToPhp($row['tracons_traversed']),
                'sectors_traversed' => json_decode($row['sectors_traversed'] ?? '[]', true),
                'origin_artcc' => $row['origin_artcc'],
                'dest_artcc' => $row['dest_artcc']
            ];

        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            error_log('GISService::analyzeTMIRoute error: ' . $e->getMessage());
            return [
                'facilities_traversed' => [],
                'artccs_traversed' => [],
                'tracons_traversed' => [],
                'sectors_traversed' => [],
                'origin_artcc' => null,
                'dest_artcc' => null
            ];
        }
    }

    /**
     * Get ARTCC codes traversed by a route (convenience method for TMI coordination)
     *
     * @param array|string $routeGeojson GeoJSON geometry or coordinates
     * @return array Array of ARTCC codes (e.g., ['ZFW', 'ZHU', 'ZJX'])
     */
    public function getTraversedFacilities($routeGeojson): array
    {
        $analysis = $this->analyzeTMIRoute($routeGeojson);
        return $analysis['facilities_traversed'] ?? [];
    }

    // =========================================================================
    // ROUTE STRING EXPANSION METHODS
    // =========================================================================

    /**
     * Expand a route string (parses airways, fixes, airports)
     *
     * @param string $routeString Route string (e.g., "KDFW BNA KMCO" or "KDFW Q40 BFOLO J4 ABQ")
     * @return array|null {waypoints, artccs, artccs_display, geojson, distance_nm}
     */
    public function expandRoute(string $routeString): ?array
    {
        if (!$this->conn) return null;

        try {
            $sql = "
                SELECT
                    waypoints,
                    artccs_traversed,
                    ST_AsGeoJSON(route_geometry) as geojson,
                    ST_Length(route_geometry::geography) / 1852.0 as distance_nm
                FROM expand_route_with_artccs(:route)
            ";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':route' => $routeString]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) return null;

            $artccs = $this->pgArrayToPhp($row['artccs_traversed']);
            $artccsClean = $this->cleanArtccCodes($artccs);

            return [
                'route' => $routeString,
                'waypoints' => json_decode($row['waypoints'], true) ?? [],
                'artccs' => $artccsClean,
                'artccs_raw' => $artccs,
                'artccs_display' => implode(' -> ', $artccsClean),
                'geojson' => $row['geojson'] ? json_decode($row['geojson'], true) : null,
                'distance_nm' => round((float)$row['distance_nm'], 1)
            ];

        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            error_log('GISService::expandRoute error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Resolve a Fix/Bearing/Distance token to projected coordinates
     *
     * @param string $token FBD token (e.g., "BDR228018")
     * @param float|null $prevLat Previous waypoint latitude for disambiguation
     * @param float|null $prevLon Previous waypoint longitude for disambiguation
     * @param float|null $nextLat Next waypoint latitude for disambiguation
     * @param float|null $nextLon Next waypoint longitude for disambiguation
     * @return array|null {fix_id, lat, lon, source} or null
     */
    public function resolveFBD(string $token, ?float $prevLat = null, ?float $prevLon = null, ?float $nextLat = null, ?float $nextLon = null): ?array
    {
        if (!$this->conn) return null;

        try {
            $sql = "SELECT fix_id, lat, lon, source FROM resolve_fbd_waypoint(:token, :prev_lat, :prev_lon, :next_lat, :next_lon)";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                ':token' => $token,
                ':prev_lat' => $prevLat,
                ':prev_lon' => $prevLon,
                ':next_lat' => $nextLat,
                ':next_lon' => $nextLon,
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row || !$row['lat']) return null;

            return [
                'fix_id' => $row['fix_id'],
                'lat' => (float)$row['lat'],
                'lon' => (float)$row['lon'],
                'source' => $row['source'],
            ];

        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            error_log('GISService::resolveFBD error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Expand multiple routes at once (batch)
     *
     * @param array $routes Array of route strings
     * @return array Array of results with index
     */
    public function expandRoutesBatch(array $routes): array
    {
        if (!$this->conn || empty($routes)) return [];

        try {
            $routesArray = $this->formatPostgresTextArray($routes);

            $sql = "
                SELECT
                    route_index,
                    route_input,
                    waypoint_count,
                    artccs,
                    artccs_display,
                    distance_nm,
                    geojson,
                    error_message
                FROM expand_routes_with_geojson(:routes)
            ";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':routes' => $routesArray]);

            $results = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $results[] = [
                    'index' => (int)$row['route_index'],
                    'route' => $row['route_input'],
                    'waypoint_count' => (int)$row['waypoint_count'],
                    'artccs' => $this->pgArrayToPhp($row['artccs']),
                    'artccs_display' => $row['artccs_display'],
                    'distance_nm' => round((float)$row['distance_nm'], 1),
                    'geojson' => $row['geojson'] ? json_decode($row['geojson'], true) : null,
                    'error' => $row['error_message']
                ];
            }

            return $results;

        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            error_log('GISService::expandRoutesBatch error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Full route analysis with sectors and TRACONs
     *
     * @param string $routeString Route string
     * @param int $altitude Cruise altitude in feet (default FL350)
     * @return array|null Complete boundary analysis
     */
    public function analyzeRouteFull(string $routeString, int $altitude = 35000): ?array
    {
        if (!$this->conn) return null;

        try {
            $routesArray = $this->formatPostgresTextArray([$routeString]);

            $sql = "SELECT * FROM expand_routes_full(:routes, :altitude)";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                ':routes' => $routesArray,
                ':altitude' => $altitude
            ]);

            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) return null;

            return [
                'route' => $routeString,
                'waypoints' => json_decode($row['waypoints'], true) ?? [],
                'artccs' => $this->cleanArtccCodes($this->pgArrayToPhp($row['artccs'])),
                'sectors_low' => $this->pgArrayToPhp($row['sectors_low']),
                'sectors_high' => $this->pgArrayToPhp($row['sectors_high']),
                'sectors_superhi' => $this->pgArrayToPhp($row['sectors_superhi']),
                'tracons' => $this->pgArrayToPhp($row['tracons']),
                'distance_nm' => round((float)$row['distance_nm'], 1),
                'geojson' => $row['geojson'] ? json_decode($row['geojson'], true) : null,
                'error' => $row['error_message']
            ];

        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            error_log('GISService::analyzeRouteFull error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get ARTCCs from a route string (simple)
     *
     * @param string $routeString Route string
     * @return array Array of ARTCC codes (ZFW, ZME, etc.)
     */
    public function getRouteARTCCsFromString(string $routeString): array
    {
        $result = $this->expandRoute($routeString);
        return $result ? $result['artccs'] : [];
    }

    /**
     * Expand a playbook route
     *
     * @param string $pbCode Playbook code (e.g., "PB.ROD.KSAN.KJFK")
     * @return array|null {route_string, waypoints, artccs, geojson}
     */
    public function expandPlaybookRoute(string $pbCode): ?array
    {
        if (!$this->conn) return null;

        try {
            $sql = "
                SELECT
                    waypoints,
                    artccs_traversed,
                    route_string,
                    ST_AsGeoJSON(route_geometry) as geojson
                FROM expand_playbook_route(:pb_code)
            ";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':pb_code' => $pbCode]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) return null;

            $artccs = $this->pgArrayToPhp($row['artccs_traversed']);

            return [
                'pb_code' => $pbCode,
                'route_string' => $row['route_string'],
                'waypoints' => json_decode($row['waypoints'], true) ?? [],
                'artccs' => $this->cleanArtccCodes($artccs),
                'artccs_display' => implode(' -> ', $this->cleanArtccCodes($artccs)),
                'geojson' => $row['geojson'] ? json_decode($row['geojson'], true) : null
            ];

        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            error_log('GISService::expandPlaybookRoute error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get GeoJSON FeatureCollection for multiple routes
     *
     * @param array $routes Array of route strings
     * @return array GeoJSON FeatureCollection
     */
    public function routesToGeoJSON(array $routes): array
    {
        if (!$this->conn || empty($routes)) {
            return ['type' => 'FeatureCollection', 'features' => []];
        }

        try {
            $routesArray = $this->formatPostgresTextArray($routes);

            $sql = "SELECT routes_to_geojson_collection(:routes) as collection";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':routes' => $routesArray]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return $row ? json_decode($row['collection'], true) : ['type' => 'FeatureCollection', 'features' => []];

        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            error_log('GISService::routesToGeoJSON error: ' . $e->getMessage());
            return ['type' => 'FeatureCollection', 'features' => []];
        }
    }

    /**
     * Resolve a waypoint/fix to coordinates
     *
     * @param string $fixName Fix identifier (e.g., "BNA", "KDFW", "ZBW")
     * @return array|null {fix_id, lat, lon, source}
     */
    public function resolveWaypoint(string $fixName): ?array
    {
        if (!$this->conn) return null;

        try {
            $sql = "SELECT fix_id, lat, lon, source FROM resolve_waypoint(:fix)";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':fix' => $fixName]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) return null;

            return [
                'fix_id' => $row['fix_id'],
                'lat' => (float)$row['lat'],
                'lon' => (float)$row['lon'],
                'source' => $row['source']
            ];

        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            error_log('GISService::resolveWaypoint error: ' . $e->getMessage());
            return null;
        }
    }

    // =========================================================================
    // AIRPORT METHODS
    // =========================================================================

    /**
     * Get the ARTCC containing an airport
     *
     * @param string $icao Airport ICAO code
     * @return string|null ARTCC code or null if not found
     */
    public function getAirportARTCC(string $icao): ?string
    {
        if (!$this->conn) {
            return null;
        }

        try {
            $sql = "SELECT get_artcc_for_airport(:icao) AS artcc";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':icao' => strtoupper($icao)]);

            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? $row['artcc'] : null;

        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            return null;
        }
    }

    /**
     * Get airports within an ARTCC
     *
     * @param string $artccCode ARTCC code
     * @return array List of airports
     */
    public function getAirportsInARTCC(string $artccCode): array
    {
        if (!$this->conn) {
            return [];
        }

        try {
            $sql = "SELECT * FROM get_airports_in_artcc(:artcc)";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':artcc' => strtoupper($artccCode)]);

            $airports = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $airports[] = [
                    'icao' => $row['icao_id'],
                    'name' => applyAirportDisplayName($row['airport_name']),
                    'lat' => (float)$row['lat'],
                    'lon' => (float)$row['lon']
                ];
            }

            return $airports;

        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            return [];
        }
    }

    // =========================================================================
    // BATCH BOUNDARY DETECTION METHODS (for daemon processing)
    // =========================================================================

    /**
     * Detect boundaries for multiple flights at once
     *
     * @param array $flights Array of [flight_uid, lat, lon, altitude]
     * @return array Results with ARTCC/TRACON for each flight
     */
    public function detectBoundariesBatch(array $flights): array
    {
        if (!$this->conn || empty($flights)) {
            return [];
        }

        try {
            // Format flights as JSONB array
            $flightsJson = json_encode(array_map(function($f) {
                return [
                    'flight_uid' => (int)($f['flight_uid'] ?? $f[0] ?? 0),
                    'lat' => (float)($f['lat'] ?? $f[1] ?? 0),
                    'lon' => (float)($f['lon'] ?? $f[2] ?? 0),
                    'altitude' => (int)($f['altitude'] ?? $f[3] ?? 0)
                ];
            }, $flights));

            // Use optimized set-based function for large batches
            $sql = "SELECT * FROM detect_boundaries_batch_optimized(:flights::jsonb)";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':flights' => $flightsJson]);

            $results = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $results[] = [
                    'flight_uid' => (int)$row['flight_uid'],
                    'lat' => (float)$row['lat'],
                    'lon' => (float)$row['lon'],
                    'altitude' => (int)$row['altitude'],
                    'artcc_code' => $row['artcc_code'],
                    'artcc_name' => $row['artcc_name'],
                    'tracon_code' => $row['tracon_code'],
                    'tracon_name' => $row['tracon_name'],
                    'is_oceanic' => $row['is_oceanic'] === true || $row['is_oceanic'] === 't'
                ];
            }

            return $results;

        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            error_log('GISService::detectBoundariesBatch error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get ARTCC at a single point
     *
     * @param float $lat Latitude
     * @param float $lon Longitude
     * @return array|null ARTCC info or null if not found
     */
    public function getARTCCAtPoint(float $lat, float $lon): ?array
    {
        if (!$this->conn) {
            return null;
        }

        try {
            $sql = "SELECT * FROM get_artcc_at_point(:lat, :lon)";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':lat' => $lat, ':lon' => $lon]);

            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || !$row['artcc_code']) {
                return null;
            }

            return [
                'artcc_code' => $row['artcc_code'],
                'artcc_name' => $row['artcc_name'],
                'is_oceanic' => $row['is_oceanic'] === true || $row['is_oceanic'] === 't'
            ];

        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            error_log('GISService::getARTCCAtPoint error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get sector for a flight at altitude
     *
     * @param float $lat Latitude
     * @param float $lon Longitude
     * @param int $altitude Altitude in feet
     * @return array Sector information (may be empty)
     */
    public function getSectorAtPoint(float $lat, float $lon, int $altitude): array
    {
        if (!$this->conn) {
            return [];
        }

        try {
            $sql = "SELECT * FROM detect_sector_for_flight(:lat, :lon, :alt)";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':lat' => $lat, ':lon' => $lon, ':alt' => $altitude]);

            $sectors = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $sectors[] = [
                    'sector_code' => $row['sector_code'],
                    'sector_name' => $row['sector_name'],
                    'parent_artcc' => $row['parent_artcc'],
                    'sector_type' => $row['sector_type'],
                    'floor' => $row['floor_altitude'],
                    'ceiling' => $row['ceiling_altitude']
                ];
            }

            return $sectors;

        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            error_log('GISService::getSectorAtPoint error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Detect sectors for multiple flights at once (batch)
     *
     * Returns LOW, HIGH, and SUPERHIGH sectors containing each position.
     * All boundaries are treated as SFC to UNL (no altitude filtering).
     *
     * @param array $flights Array of [flight_uid, lat, lon, altitude]
     * @return array Results with sector info for each flight
     */
    public function detectSectorsBatch(array $flights): array
    {
        if (!$this->conn || empty($flights)) {
            return [];
        }

        try {
            // Format flights as JSONB array
            $flightsJson = json_encode(array_map(function($f) {
                return [
                    'flight_uid' => (int)($f['flight_uid'] ?? $f[0] ?? 0),
                    'lat' => (float)($f['lat'] ?? $f[1] ?? 0),
                    'lon' => (float)($f['lon'] ?? $f[2] ?? 0),
                    'altitude' => (int)($f['altitude'] ?? $f[3] ?? 0)
                ];
            }, $flights));

            $sql = "SELECT * FROM detect_sectors_batch_optimized(:flights::jsonb)";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':flights' => $flightsJson]);

            $results = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $results[] = [
                    'flight_uid' => (int)$row['flight_uid'],
                    'lat' => (float)$row['lat'],
                    'lon' => (float)$row['lon'],
                    'altitude' => (int)$row['altitude'],
                    'sector_low' => $row['sector_low'],
                    'sector_low_name' => $row['sector_low_name'],
                    'sector_high' => $row['sector_high'],
                    'sector_high_name' => $row['sector_high_name'],
                    'sector_superhigh' => $row['sector_superhigh'],
                    'sector_superhigh_name' => $row['sector_superhigh_name'],
                    'parent_artcc' => $row['parent_artcc']
                ];
            }

            return $results;

        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            error_log('GISService::detectSectorsBatch error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Detect ARTCC, TRACON, and sectors for multiple flights in one call
     *
     * This is the most efficient method for boundary_gis_daemon as it
     * combines all spatial lookups into a single database round-trip.
     *
     * @param array $flights Array of [flight_uid, lat, lon, altitude]
     * @return array Results with ARTCC/TRACON/sectors for each flight
     */
    public function detectBoundariesAndSectorsBatch(array $flights): array
    {
        if (!$this->conn || empty($flights)) {
            return [];
        }

        try {
            // Format flights as JSONB array
            $flightsJson = json_encode(array_map(function($f) {
                return [
                    'flight_uid' => (int)($f['flight_uid'] ?? $f[0] ?? 0),
                    'lat' => (float)($f['lat'] ?? $f[1] ?? 0),
                    'lon' => (float)($f['lon'] ?? $f[2] ?? 0),
                    'altitude' => (int)($f['altitude'] ?? $f[3] ?? 0)
                ];
            }, $flights));

            $sql = "SELECT * FROM detect_boundaries_and_sectors_batch(:flights::jsonb)";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':flights' => $flightsJson]);

            $results = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $results[] = [
                    'flight_uid' => (int)$row['flight_uid'],
                    'lat' => (float)$row['lat'],
                    'lon' => (float)$row['lon'],
                    'altitude' => (int)$row['altitude'],
                    'artcc_code' => $row['artcc_code'],
                    'artcc_name' => $row['artcc_name'],
                    'tracon_code' => $row['tracon_code'],
                    'tracon_name' => $row['tracon_name'],
                    'is_oceanic' => $row['is_oceanic'] === true || $row['is_oceanic'] === 't',
                    'sector_low' => $row['sector_low'],
                    'sector_high' => $row['sector_high'],
                    'sector_superhigh' => $row['sector_superhigh'],
                    'sector_strata' => $row['sector_strata']
                ];
            }

            return $results;

        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            error_log('GISService::detectBoundariesAndSectorsBatch error: ' . $e->getMessage());
            return [];
        }
    }

    // =========================================================================
    // UTILITY METHODS
    // =========================================================================

    /**
     * Format waypoints array for PostGIS JSONB parameter
     *
     * @param array $waypoints Array of waypoints (various formats supported)
     * @return string JSON string
     */
    private function formatWaypointsJson(array $waypoints): string
    {
        $formatted = [];

        foreach ($waypoints as $wp) {
            // Support multiple input formats
            if (isset($wp['lon']) && isset($wp['lat'])) {
                // {lon: x, lat: y} format
                $formatted[] = [
                    'lon' => (float)$wp['lon'],
                    'lat' => (float)$wp['lat']
                ];
            } elseif (isset($wp['lng']) && isset($wp['lat'])) {
                // {lng: x, lat: y} format (Leaflet style)
                $formatted[] = [
                    'lon' => (float)$wp['lng'],
                    'lat' => (float)$wp['lat']
                ];
            } elseif (isset($wp['longitude']) && isset($wp['latitude'])) {
                // {longitude: x, latitude: y} format
                $formatted[] = [
                    'lon' => (float)$wp['longitude'],
                    'lat' => (float)$wp['latitude']
                ];
            } elseif (is_array($wp) && count($wp) >= 2) {
                // [lat, lon] or [lon, lat] array format
                // Assume GeoJSON convention: [lon, lat]
                $formatted[] = [
                    'lon' => (float)$wp[0],
                    'lat' => (float)$wp[1]
                ];
            }
        }

        return json_encode($formatted);
    }

    /**
     * Convert PostgreSQL array string to PHP array
     *
     * @param string|null $pgArray PostgreSQL array format {a,b,c}
     * @return array PHP array
     */
    private function pgArrayToPhp(?string $pgArray): array
    {
        if (!$pgArray || $pgArray === '{}' || $pgArray === 'NULL') {
            return [];
        }

        // Handle PostgreSQL array format: {value1,value2,value3}
        $pgArray = trim($pgArray, '{}');
        if ($pgArray === '') {
            return [];
        }

        // Split by comma, handling quoted values
        $values = str_getcsv($pgArray);
        return array_filter($values, fn($v) => $v !== '' && $v !== null);
    }

    /**
     * Format PHP array as PostgreSQL TEXT[] array literal
     *
     * @param array $arr PHP array of strings
     * @return string PostgreSQL array literal
     */
    private function formatPostgresTextArray(array $arr): string
    {
        $escaped = array_map(function($s) {
            // Escape special characters and wrap in quotes
            $s = str_replace('\\', '\\\\', $s);
            $s = str_replace('"', '\\"', $s);
            return '"' . $s . '"';
        }, $arr);

        return '{' . implode(',', $escaped) . '}';
    }

    /**
     * Clean ARTCC codes - remove K prefix from ICAO-style codes
     *
     * @param array $artccs Array of ARTCC codes
     * @return array Cleaned codes (KZFW -> ZFW)
     */
    private function cleanArtccCodes(array $artccs): array
    {
        return array_map(function($a) {
            // KZFW -> ZFW
            if (strlen($a) === 4 && substr($a, 0, 1) === 'K') {
                return substr($a, 1);
            }
            return $a;
        }, $artccs);
    }

    // =========================================================================
    // TRAJECTORY CROSSING METHODS
    // =========================================================================

    /**
     * Get ARTCC boundary crossings along a trajectory
     *
     * Uses PostGIS line-polygon intersection for precise crossing points.
     *
     * @param array $waypoints Array of waypoints with lat, lon, sequence_num
     * @return array Crossings with artcc_code, coordinates, distance_nm, crossing_type
     */
    public function getTrajectoryArtccCrossings(array $waypoints): array
    {
        if (!$this->conn || empty($waypoints)) {
            return [];
        }

        try {
            $waypointsJson = $this->formatWaypointsJsonWithSequence($waypoints);

            $sql = "SELECT * FROM get_trajectory_artcc_crossings(:waypoints::jsonb)";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':waypoints' => $waypointsJson]);

            $results = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $results[] = [
                    'artcc_code' => $row['artcc_code'],
                    'artcc_name' => $row['artcc_name'],
                    'is_oceanic' => $row['is_oceanic'] === true || $row['is_oceanic'] === 't',
                    'crossing_lat' => (float)$row['crossing_lat'],
                    'crossing_lon' => (float)$row['crossing_lon'],
                    'crossing_fraction' => (float)$row['crossing_fraction'],
                    'distance_nm' => (float)$row['distance_nm'],
                    'crossing_type' => $row['crossing_type']
                ];
            }

            return $results;

        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            error_log('GISService::getTrajectoryArtccCrossings error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get sector boundary crossings along a trajectory
     *
     * @param array $waypoints Array of waypoints with lat, lon, sequence_num
     * @param string|null $sectorType Filter by sector type ('LOW', 'HIGH', 'SUPERHIGH')
     * @return array Crossings with sector_code, coordinates, distance_nm, crossing_type
     */
    public function getTrajectorySectorCrossings(array $waypoints, ?string $sectorType = null): array
    {
        if (!$this->conn || empty($waypoints)) {
            return [];
        }

        try {
            $waypointsJson = $this->formatWaypointsJsonWithSequence($waypoints);

            $sql = "SELECT * FROM get_trajectory_sector_crossings(:waypoints::jsonb, :sector_type)";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                ':waypoints' => $waypointsJson,
                ':sector_type' => $sectorType
            ]);

            $results = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $results[] = [
                    'sector_code' => $row['sector_code'],
                    'sector_name' => $row['sector_name'],
                    'sector_type' => $row['sector_type'],
                    'parent_artcc' => $row['parent_artcc'],
                    'crossing_lat' => (float)$row['crossing_lat'],
                    'crossing_lon' => (float)$row['crossing_lon'],
                    'crossing_fraction' => (float)$row['crossing_fraction'],
                    'distance_nm' => (float)$row['distance_nm'],
                    'crossing_type' => $row['crossing_type']
                ];
            }

            return $results;

        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            error_log('GISService::getTrajectorySectorCrossings error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get all boundary crossings (ARTCC + sectors) along a trajectory
     *
     * @param array $waypoints Array of waypoints with lat, lon, sequence_num
     * @return array All crossings in route order
     */
    public function getTrajectoryAllCrossings(array $waypoints): array
    {
        if (!$this->conn || empty($waypoints)) {
            return [];
        }

        try {
            $waypointsJson = $this->formatWaypointsJsonWithSequence($waypoints);

            $sql = "SELECT * FROM get_trajectory_all_crossings(:waypoints::jsonb)";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':waypoints' => $waypointsJson]);

            $results = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $results[] = [
                    'boundary_type' => $row['boundary_type'],
                    'boundary_code' => $row['boundary_code'],
                    'boundary_name' => $row['boundary_name'],
                    'parent_artcc' => $row['parent_artcc'],
                    'crossing_lat' => (float)$row['crossing_lat'],
                    'crossing_lon' => (float)$row['crossing_lon'],
                    'crossing_fraction' => (float)$row['crossing_fraction'],
                    'distance_nm' => (float)$row['distance_nm'],
                    'crossing_type' => $row['crossing_type']
                ];
            }

            return $results;

        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            error_log('GISService::getTrajectoryAllCrossings error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Calculate ETAs for boundary crossings along a trajectory
     *
     * This is the main method for trajectory-based ETA calculation.
     * Returns all future boundary crossings with calculated ETAs.
     *
     * @param array $waypoints Array of waypoints with lat, lon, sequence_num
     * @param float $currentLat Current aircraft latitude
     * @param float $currentLon Current aircraft longitude
     * @param float $distFlownNm Distance already flown from origin
     * @param int $groundspeedKts Current groundspeed in knots
     * @param string|null $currentTime Current UTC time (ISO format)
     * @return array Future crossings with ETAs
     */
    public function calculateCrossingEtas(
        array $waypoints,
        float $currentLat,
        float $currentLon,
        float $distFlownNm,
        int $groundspeedKts,
        ?string $currentTime = null
    ): array {
        if (!$this->conn || empty($waypoints)) {
            return [];
        }

        try {
            $waypointsJson = $this->formatWaypointsJsonWithSequence($waypoints);

            $sql = "SELECT * FROM calculate_crossing_etas(
                :waypoints::jsonb,
                :current_lat,
                :current_lon,
                :dist_flown,
                :groundspeed,
                :current_time::timestamptz
            )";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                ':waypoints' => $waypointsJson,
                ':current_lat' => $currentLat,
                ':current_lon' => $currentLon,
                ':dist_flown' => $distFlownNm,
                ':groundspeed' => $groundspeedKts,
                ':current_time' => $currentTime ?? gmdate('Y-m-d H:i:s')
            ]);

            $results = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $results[] = [
                    'boundary_type' => $row['boundary_type'],
                    'boundary_code' => $row['boundary_code'],
                    'boundary_name' => $row['boundary_name'],
                    'parent_artcc' => $row['parent_artcc'],
                    'crossing_lat' => (float)$row['crossing_lat'],
                    'crossing_lon' => (float)$row['crossing_lon'],
                    'distance_from_origin_nm' => (float)$row['distance_from_origin_nm'],
                    'distance_remaining_nm' => (float)$row['distance_remaining_nm'],
                    'eta_utc' => $row['eta_utc'],
                    'crossing_type' => $row['crossing_type']
                ];
            }

            return $results;

        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            error_log('GISService::calculateCrossingEtas error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Batch calculate crossing ETAs for multiple flights
     *
     * @param array $flights Array of flight objects with waypoints and current state
     * @return array Crossings grouped by flight_uid
     */
    public function calculateCrossingsBatch(array $flights): array
    {
        if (!$this->conn || empty($flights)) {
            return [];
        }

        try {
            // Format flights with nested waypoints for JSONB
            $flightsJson = json_encode(array_map(function($f) {
                return [
                    'flight_uid' => (int)($f['flight_uid'] ?? 0),
                    'waypoints' => $this->formatWaypointsArray($f['waypoints'] ?? []),
                    'current_lat' => (float)($f['current_lat'] ?? $f['lat'] ?? 0),
                    'current_lon' => (float)($f['current_lon'] ?? $f['lon'] ?? 0),
                    'dist_flown_nm' => (float)($f['dist_flown_nm'] ?? 0),
                    'groundspeed_kts' => (int)($f['groundspeed_kts'] ?? $f['groundspeed'] ?? 450),
                    'current_time' => $f['current_time'] ?? gmdate('Y-m-d H:i:s')
                ];
            }, $flights));

            $sql = "SELECT * FROM calculate_crossings_batch(:flights::jsonb)";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':flights' => $flightsJson]);

            // Group results by flight_uid
            $results = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $flightUid = (int)$row['flight_uid'];
                if (!isset($results[$flightUid])) {
                    $results[$flightUid] = [];
                }
                $results[$flightUid][] = [
                    'boundary_type' => $row['boundary_type'],
                    'boundary_code' => $row['boundary_code'],
                    'boundary_name' => $row['boundary_name'],
                    'crossing_lat' => (float)$row['crossing_lat'],
                    'crossing_lon' => (float)$row['crossing_lon'],
                    'distance_from_origin_nm' => (float)$row['distance_from_origin_nm'],
                    'distance_remaining_nm' => (float)$row['distance_remaining_nm'],
                    'eta_utc' => $row['eta_utc'],
                    'crossing_type' => $row['crossing_type']
                ];
            }

            return $results;

        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            error_log('GISService::calculateCrossingsBatch error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get ARTCCs traversed by route (simple array of codes)
     *
     * @param array $waypoints Array of waypoints
     * @return array ARTCC codes in traversal order
     */
    public function getArtccsTraversed(array $waypoints): array
    {
        if (!$this->conn || empty($waypoints)) {
            return [];
        }

        try {
            $waypointsJson = $this->formatWaypointsJsonWithSequence($waypoints);

            $sql = "SELECT get_artccs_traversed(:waypoints::jsonb) AS artccs";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':waypoints' => $waypointsJson]);

            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && $row['artccs']) {
                return $this->pgArrayToPhp($row['artccs']);
            }

            return [];

        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            error_log('GISService::getArtccsTraversed error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Format waypoints array with sequence numbers for trajectory functions
     *
     * @param array $waypoints Array of waypoints (various formats)
     * @return string JSON string with sequence_num included
     */
    private function formatWaypointsJsonWithSequence(array $waypoints): string
    {
        $formatted = [];
        $seq = 0;

        foreach ($waypoints as $wp) {
            $point = null;

            // Support multiple input formats
            if (isset($wp['lon']) && isset($wp['lat'])) {
                $point = ['lon' => (float)$wp['lon'], 'lat' => (float)$wp['lat']];
            } elseif (isset($wp['lng']) && isset($wp['lat'])) {
                $point = ['lon' => (float)$wp['lng'], 'lat' => (float)$wp['lat']];
            } elseif (isset($wp['longitude']) && isset($wp['latitude'])) {
                $point = ['lon' => (float)$wp['longitude'], 'lat' => (float)$wp['latitude']];
            } elseif (is_array($wp) && count($wp) >= 2 && is_numeric($wp[0])) {
                // [lon, lat] GeoJSON convention
                $point = ['lon' => (float)$wp[0], 'lat' => (float)$wp[1]];
            }

            if ($point) {
                // Use existing sequence_num or assign one
                $point['sequence_num'] = (int)($wp['sequence_num'] ?? $wp['seq'] ?? $seq);
                $formatted[] = $point;
                $seq++;
            }
        }

        return json_encode($formatted);
    }

    /**
     * Format waypoints array without JSON encoding (for nested JSONB)
     *
     * @param array $waypoints Raw waypoints
     * @return array Formatted waypoints array
     */
    private function formatWaypointsArray(array $waypoints): array
    {
        $formatted = [];
        $seq = 0;

        foreach ($waypoints as $wp) {
            $point = null;

            if (isset($wp['lon']) && isset($wp['lat'])) {
                $point = ['lon' => (float)$wp['lon'], 'lat' => (float)$wp['lat']];
            } elseif (isset($wp['lng']) && isset($wp['lat'])) {
                $point = ['lon' => (float)$wp['lng'], 'lat' => (float)$wp['lat']];
            } elseif (isset($wp['longitude']) && isset($wp['latitude'])) {
                $point = ['lon' => (float)$wp['longitude'], 'lat' => (float)$wp['latitude']];
            } elseif (is_array($wp) && count($wp) >= 2 && is_numeric($wp[0])) {
                $point = ['lon' => (float)$wp[0], 'lat' => (float)$wp[1]];
            }

            if ($point) {
                $point['sequence_num'] = (int)($wp['sequence_num'] ?? $wp['seq'] ?? $seq);
                $formatted[] = $point;
                $seq++;
            }
        }

        return $formatted;
    }

    /**
     * Execute a raw PostGIS query (for advanced use cases)
     *
     * @param string $sql SQL query with named parameters
     * @param array $params Parameters for the query
     * @return array|null Result rows or null on error
     */
    public function query(string $sql, array $params = []): ?array
    {
        if (!$this->conn) {
            return null;
        }

        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);

            $results = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $results[] = $row;
            }

            return $results;

        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            error_log('GISService::query error: ' . $e->getMessage());
            return null;
        }
    }

    // =========================================================================
    // BOUNDARY ADJACENCY NETWORK METHODS
    // =========================================================================

    /**
     * Compute all boundary adjacencies
     *
     * This is a heavy operation - run after importing new boundaries.
     * Populates the boundary_adjacency table with precomputed relationships.
     *
     * @return array Summary of computed adjacencies by category
     */
    public function computeAllAdjacencies(): array
    {
        if (!$this->conn) {
            return [];
        }

        try {
            $sql = "SELECT * FROM compute_all_adjacencies()";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();

            $results = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $results[] = [
                    'category' => $row['category'],
                    'inserted' => (int)$row['inserted'],
                    'elapsed_ms' => (float)$row['elapsed_ms']
                ];
            }

            return $results;

        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            error_log('GISService::computeAllAdjacencies error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get neighbors of a specific boundary
     *
     * @param string $boundaryType Type: 'ARTCC', 'TRACON', 'SECTOR_LOW', 'SECTOR_HIGH', 'SECTOR_SUPERHIGH'
     * @param string $boundaryCode The boundary code (e.g., 'ZFW', 'A80', 'ZFW15')
     * @param string|null $adjacencyClass Filter: 'POINT', 'LINE', 'POLY', or null for all
     * @return array List of neighboring boundaries
     */
    public function getBoundaryNeighbors(string $boundaryType, string $boundaryCode, ?string $adjacencyClass = null): array
    {
        if (!$this->conn) {
            return [];
        }

        try {
            $sql = "SELECT * FROM get_boundary_neighbors(:type, :code, :class::adjacency_type)";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                ':type' => strtoupper($boundaryType),
                ':code' => strtoupper($boundaryCode),
                ':class' => $adjacencyClass ? strtoupper($adjacencyClass) : null
            ]);

            $results = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $results[] = [
                    'neighbor_type' => $row['neighbor_type'],
                    'neighbor_code' => $row['neighbor_code'],
                    'neighbor_name' => $row['neighbor_name'],
                    'adjacency_class' => $row['adjacency_class'],
                    'shared_length_nm' => $row['shared_length_nm'] !== null ? (float)$row['shared_length_nm'] : null,
                    'shared_points' => $row['shared_points'] !== null ? (int)$row['shared_points'] : null
                ];
            }

            return $results;

        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            error_log('GISService::getBoundaryNeighbors error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get adjacency network summary statistics
     *
     * @return array Statistics by relationship type
     */
    public function getAdjacencyStats(): array
    {
        if (!$this->conn) {
            return [];
        }

        try {
            $sql = "SELECT * FROM get_adjacency_stats()";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();

            $results = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $results[] = [
                    'relationship' => $row['relationship'],
                    'total_pairs' => (int)$row['total_pairs'],
                    'point_adjacencies' => (int)$row['point_adjacencies'],
                    'line_adjacencies' => (int)$row['line_adjacencies'],
                    'poly_adjacencies' => (int)$row['poly_adjacencies'],
                    'avg_shared_length_nm' => $row['avg_shared_length_nm'] !== null ? (float)$row['avg_shared_length_nm'] : null,
                    'max_shared_length_nm' => $row['max_shared_length_nm'] !== null ? (float)$row['max_shared_length_nm'] : null
                ];
            }

            return $results;

        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            error_log('GISService::getAdjacencyStats error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Export adjacency network as edge list for graph analysis tools
     *
     * @param array|null $types Filter by boundary types (null = all)
     * @param string $minAdjacency Minimum adjacency level: 'POINT', 'LINE', 'POLY'
     * @return array Edge list with source_id, target_id, weight, edge_type
     */
    public function exportAdjacencyEdges(?array $types = null, string $minAdjacency = 'POINT'): array
    {
        if (!$this->conn) {
            return [];
        }

        try {
            $typesParam = $types ? $this->formatPostgresTextArray($types) : null;

            $sql = "SELECT * FROM export_adjacency_edges(:types, :min_adj::adjacency_type)";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                ':types' => $typesParam,
                ':min_adj' => strtoupper($minAdjacency)
            ]);

            $results = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $results[] = [
                    'source_id' => $row['source_id'],
                    'target_id' => $row['target_id'],
                    'weight' => (float)$row['weight'],
                    'edge_type' => $row['edge_type']
                ];
            }

            return $results;

        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            error_log('GISService::exportAdjacencyEdges error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Find traversal path between two boundaries using BFS
     *
     * @param string $sourceType Source boundary type
     * @param string $sourceCode Source boundary code
     * @param string $targetType Target boundary type
     * @param string $targetCode Target boundary code
     * @param int $maxHops Maximum number of boundary transitions
     * @param bool $sameTypeOnly Only traverse through same boundary type
     * @return array Path from source to target
     */
    public function findBoundaryPath(
        string $sourceType,
        string $sourceCode,
        string $targetType,
        string $targetCode,
        int $maxHops = 10,
        bool $sameTypeOnly = false
    ): array {
        if (!$this->conn) {
            return [];
        }

        try {
            $sql = "SELECT * FROM find_boundary_path(:src_type, :src_code, :tgt_type, :tgt_code, :max_hops, :same_type)";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                ':src_type' => strtoupper($sourceType),
                ':src_code' => strtoupper($sourceCode),
                ':tgt_type' => strtoupper($targetType),
                ':tgt_code' => strtoupper($targetCode),
                ':max_hops' => $maxHops,
                ':same_type' => $sameTypeOnly ? 'true' : 'false'
            ]);

            $results = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $results[] = [
                    'hop' => (int)$row['hop'],
                    'boundary_type' => $row['boundary_type'],
                    'boundary_code' => $row['boundary_code'],
                    'boundary_name' => $row['boundary_name']
                ];
            }

            return $results;

        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            error_log('GISService::findBoundaryPath error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get ARTCC adjacency map (all ARTCC-to-ARTCC connections)
     *
     * Convenience method for getting the ARTCC network graph.
     *
     * @param bool $lineOnlyOnly include LINE adjacencies (default true for traversable only)
     * @return array Map of artcc_code => [neighbor_codes]
     */
    public function getArtccAdjacencyMap(bool $lineOnly = true): array
    {
        if (!$this->conn) {
            return [];
        }

        try {
            $sql = "
                SELECT source_code, target_code, adjacency_class, shared_length_nm
                FROM boundary_adjacency
                WHERE source_type = 'ARTCC'
                  AND target_type = 'ARTCC'
                " . ($lineOnly ? "AND adjacency_class = 'LINE'" : "") . "
                ORDER BY source_code, shared_length_nm DESC NULLS LAST
            ";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();

            $map = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $source = $row['source_code'];
                if (!isset($map[$source])) {
                    $map[$source] = [];
                }
                $map[$source][] = [
                    'neighbor' => $row['target_code'],
                    'adjacency' => $row['adjacency_class'],
                    'shared_length_nm' => $row['shared_length_nm'] !== null ? (float)$row['shared_length_nm'] : null
                ];
            }

            return $map;

        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            error_log('GISService::getArtccAdjacencyMap error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get sector adjacency within an ARTCC
     *
     * @param string $artccCode ARTCC code (e.g., 'ZFW')
     * @param string $sectorType Sector type: 'LOW', 'HIGH', 'SUPERHIGH'
     * @return array Sector adjacency network within the ARTCC
     */
    public function getSectorAdjacencyInArtcc(string $artccCode, string $sectorType = 'HIGH'): array
    {
        if (!$this->conn) {
            return [];
        }

        $boundaryType = 'SECTOR_' . strtoupper($sectorType);

        try {
            $sql = "
                SELECT ba.source_code, ba.source_name, ba.target_code, ba.target_name,
                       ba.adjacency_class, ba.shared_length_nm
                FROM boundary_adjacency ba
                JOIN sector_boundaries sb ON sb.sector_code = ba.source_code
                WHERE ba.source_type = :btype
                  AND ba.target_type = :btype
                  AND sb.parent_artcc = :artcc
                ORDER BY ba.source_code, ba.shared_length_nm DESC NULLS LAST
            ";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                ':btype' => $boundaryType,
                ':artcc' => strtoupper($artccCode)
            ]);

            $results = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $results[] = [
                    'source_code' => $row['source_code'],
                    'source_name' => $row['source_name'],
                    'target_code' => $row['target_code'],
                    'target_name' => $row['target_name'],
                    'adjacency_class' => $row['adjacency_class'],
                    'shared_length_nm' => $row['shared_length_nm'] !== null ? (float)$row['shared_length_nm'] : null
                ];
            }

            return $results;

        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            error_log('GISService::getSectorAdjacencyInArtcc error: ' . $e->getMessage());
            return [];
        }
    }

    // =========================================================================
    // PROXIMITY TIER METHODS
    // =========================================================================

    /**
     * Get all boundaries within N proximity tiers of a given boundary
     *
     * Tier 0 = self, Tier 1 = LINE adjacent, Tier 1.5 = POINT (corner) adjacent,
     * Tier 2 = LINE adjacent to Tier 1, etc.
     *
     * @param string $boundaryType Type: 'ARTCC', 'TRACON', 'SECTOR_LOW', 'SECTOR_HIGH', 'SECTOR_SUPERHIGH'
     * @param string $boundaryCode The boundary code (e.g., 'ZFW', 'A80')
     * @param float $maxTier Maximum tier to search (default 5.0)
     * @param bool $sameTypeOnly Only include boundaries of the same type
     * @return array List of boundaries with their tier
     */
    public function getProximityTiers(
        string $boundaryType,
        string $boundaryCode,
        float $maxTier = 5.0,
        bool $sameTypeOnly = false
    ): array {
        if (!$this->conn) {
            return [];
        }

        try {
            $sql = "SELECT * FROM get_proximity_tiers(:type, :code, :max_tier, :same_type)";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                ':type' => strtoupper($boundaryType),
                ':code' => strtoupper($boundaryCode),
                ':max_tier' => $maxTier,
                ':same_type' => $sameTypeOnly ? 'true' : 'false'
            ]);

            $results = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $results[] = [
                    'tier' => (float)$row['tier'],
                    'boundary_type' => $row['boundary_type'],
                    'boundary_code' => $row['boundary_code'],
                    'boundary_name' => $row['boundary_name'],
                    'adjacency_from' => $row['adjacency_from'],
                    'adjacency_class' => $row['adjacency_class']
                ];
            }

            return $results;

        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            error_log('GISService::getProximityTiers error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get the proximity tier distance between two boundaries
     *
     * @param string $sourceType Source boundary type
     * @param string $sourceCode Source boundary code
     * @param string $targetType Target boundary type
     * @param string $targetCode Target boundary code
     * @param float $maxTier Maximum tier to search
     * @param bool $sameTypeOnly Only traverse same boundary type
     * @return float|null The tier distance, or null if not reachable
     */
    public function getProximityDistance(
        string $sourceType,
        string $sourceCode,
        string $targetType,
        string $targetCode,
        float $maxTier = 10.0,
        bool $sameTypeOnly = false
    ): ?float {
        if (!$this->conn) {
            return null;
        }

        try {
            $sql = "SELECT get_proximity_distance(:src_type, :src_code, :tgt_type, :tgt_code, :max_tier, :same_type) AS tier";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                ':src_type' => strtoupper($sourceType),
                ':src_code' => strtoupper($sourceCode),
                ':tgt_type' => strtoupper($targetType),
                ':tgt_code' => strtoupper($targetCode),
                ':max_tier' => $maxTier,
                ':same_type' => $sameTypeOnly ? 'true' : 'false'
            ]);

            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row['tier'] !== null ? (float)$row['tier'] : null;

        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            error_log('GISService::getProximityDistance error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get boundaries at a specific tier (or tier range)
     *
     * @param string $boundaryType Origin boundary type
     * @param string $boundaryCode Origin boundary code
     * @param float $tierMin Minimum tier (inclusive)
     * @param float|null $tierMax Maximum tier (inclusive), null = same as min
     * @param bool $sameTypeOnly Only include same boundary type
     * @return array Boundaries at the specified tier(s)
     */
    public function getBoundariesAtTier(
        string $boundaryType,
        string $boundaryCode,
        float $tierMin,
        ?float $tierMax = null,
        bool $sameTypeOnly = false
    ): array {
        if (!$this->conn) {
            return [];
        }

        try {
            $sql = "SELECT * FROM get_boundaries_at_tier(:type, :code, :tier_min, :tier_max, :same_type)";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                ':type' => strtoupper($boundaryType),
                ':code' => strtoupper($boundaryCode),
                ':tier_min' => $tierMin,
                ':tier_max' => $tierMax,
                ':same_type' => $sameTypeOnly ? 'true' : 'false'
            ]);

            $results = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $results[] = [
                    'tier' => (float)$row['tier'],
                    'boundary_type' => $row['boundary_type'],
                    'boundary_code' => $row['boundary_code'],
                    'boundary_name' => $row['boundary_name'],
                    'adjacency_class' => $row['adjacency_class']
                ];
            }

            return $results;

        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            error_log('GISService::getBoundariesAtTier error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get proximity summary (count per tier)
     *
     * @param string $boundaryType Origin boundary type
     * @param string $boundaryCode Origin boundary code
     * @param float $maxTier Maximum tier to include
     * @param bool $sameTypeOnly Only include same boundary type
     * @return array Summary with count and codes per tier
     */
    public function getProximitySummary(
        string $boundaryType,
        string $boundaryCode,
        float $maxTier = 5.0,
        bool $sameTypeOnly = false
    ): array {
        if (!$this->conn) {
            return [];
        }

        try {
            $sql = "SELECT * FROM get_proximity_summary(:type, :code, :max_tier, :same_type)";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                ':type' => strtoupper($boundaryType),
                ':code' => strtoupper($boundaryCode),
                ':max_tier' => $maxTier,
                ':same_type' => $sameTypeOnly ? 'true' : 'false'
            ]);

            $results = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                // Parse PostgreSQL array format {a,b,c} to PHP array
                $codes = $row['boundary_codes'];
                if ($codes && preg_match('/^\{(.+)\}$/', $codes, $m)) {
                    $codes = explode(',', $m[1]);
                } else {
                    $codes = [];
                }

                $results[] = [
                    'tier' => (float)$row['tier'],
                    'count' => (int)$row['boundary_count'],
                    'boundary_codes' => $codes
                ];
            }

            return $results;

        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            error_log('GISService::getProximitySummary error: ' . $e->getMessage());
            return [];
        }
    }

    // =========================================================================
    // OPTIMIZED ARTCC TIER METHODS (using materialized view)
    // =========================================================================

    /**
     * Get ARTCC tiers using precomputed materialized view
     *
     * This is much faster than get_proximity_tiers() for ARTCC-to-ARTCC queries
     * because it uses a precomputed materialized view instead of BFS traversal.
     *
     * @param string $artccCode ARTCC code (e.g., 'KZFW' or 'ZFW')
     * @param float $maxTier Maximum tier to return (default 3.0)
     * @param bool $usOnly Only include US ARTCCs (default true)
     * @return array List of tiers with neighbor info
     */
    public function getArtccTierMatrix(string $artccCode, float $maxTier = 3.0, bool $usOnly = true): array
    {
        if (!$this->conn) {
            return [];
        }

        // Normalize to ICAO format (KZXX)
        $artccCode = strtoupper($artccCode);
        if (!str_starts_with($artccCode, 'K') && strlen($artccCode) === 3) {
            $artccCode = 'K' . $artccCode;
        }

        try {
            $sql = "SELECT * FROM get_artcc_tier_matrix(:code, :max_tier, :us_only)";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                ':code' => $artccCode,
                ':max_tier' => $maxTier,
                ':us_only' => $usOnly ? 'true' : 'false'
            ]);

            $results = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $results[] = [
                    'tier' => (float)$row['tier'],
                    'boundary_type' => $row['boundary_type'],
                    'boundary_code' => $row['boundary_code'],
                    'boundary_name' => $row['boundary_name'],
                    'adjacency_from' => $row['adjacency_from'],
                    'adjacency_class' => $row['adjacency_class']
                ];
            }

            return $results;

        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            error_log('GISService::getArtccTierMatrix error: ' . $e->getMessage());
            // Fall back to dynamic calculation if materialized view doesn't exist
            return $this->getProximityTiers('ARTCC', $artccCode, $maxTier, true);
        }
    }

    /**
     * Get all US ARTCC tiers in one query (optimized for GDT bulk export)
     *
     * Returns tier data for all 20 CONUS ARTCCs in a single query,
     * avoiding 20 separate round-trips to the database.
     *
     * @param float $maxTier Maximum tier to return (default 2.0)
     * @return array Grouped by origin_code => [tier => [neighbor_codes]]
     */
    public function getAllArtccTiers(float $maxTier = 2.0): array
    {
        if (!$this->conn) {
            return [];
        }

        try {
            $sql = "SELECT * FROM get_all_artcc_tiers(:max_tier)";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':max_tier' => $maxTier]);

            $results = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $origin = $row['origin_code'];
                $tier = (float)$row['tier'];
                $neighbor = $row['neighbor_code'];

                if (!isset($results[$origin])) {
                    $results[$origin] = [];
                }
                if (!isset($results[$origin][$tier])) {
                    $results[$origin][$tier] = [];
                }
                $results[$origin][$tier][] = $neighbor;
            }

            return $results;

        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            error_log('GISService::getAllArtccTiers error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Refresh the ARTCC tier matrix materialized view
     *
     * Call this after updating boundary_adjacency or artcc_boundaries tables.
     *
     * @return string Status message
     */
    public function refreshArtccTierMatrix(): string
    {
        if (!$this->conn) {
            return 'No database connection';
        }

        try {
            $sql = "SELECT refresh_artcc_tier_matrix() AS result";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row['result'] ?? 'Refresh completed';

        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            error_log('GISService::refreshArtccTierMatrix error: ' . $e->getMessage());
            return 'Error: ' . $e->getMessage();
        }
    }

    // =========================================================================
    // FACILITY BOUNDARY GEOJSON METHODS
    // =========================================================================

    /**
     * Get facility boundary as GeoJSON Feature
     *
     * Returns a GeoJSON Feature with the boundary polygon and properties
     * for display on MapLibre maps.
     *
     * @param string $facilityType Type: 'ARTCC', 'TRACON', 'SECTOR_LOW', 'SECTOR_HIGH', 'SECTOR_SUPERHIGH'
     * @param string $facilityCode The facility code (e.g., 'ZNY', 'N90', 'ZFW15')
     * @return array|null GeoJSON Feature or null if not found
     */
    public function getFacilityBoundaryGeoJSON(string $facilityType, string $facilityCode): ?array
    {
        if (!$this->conn) {
            return null;
        }

        $facilityType = strtoupper($facilityType);
        $facilityCode = strtoupper($facilityCode);

        try {
            switch ($facilityType) {
                case 'ARTCC':
                    $sql = "SELECT
                                artcc_code AS code,
                                fir_name AS name,
                                'ARTCC' AS type,
                                ST_AsGeoJSON(geom) AS geojson,
                                label_lat, label_lon
                            FROM artcc_boundaries
                            WHERE artcc_code = :code
                            LIMIT 1";
                    break;

                case 'TRACON':
                    $sql = "SELECT
                                tracon_code AS code,
                                tracon_name AS name,
                                'TRACON' AS type,
                                parent_artcc,
                                ST_AsGeoJSON(geom) AS geojson,
                                label_lat, label_lon
                            FROM tracon_boundaries
                            WHERE tracon_code = :code
                            LIMIT 1";
                    break;

                case 'SECTOR_LOW':
                case 'SECTOR_HIGH':
                case 'SECTOR_SUPERHIGH':
                    $sectorType = str_replace('SECTOR_', '', $facilityType);
                    $sql = "SELECT
                                sector_code AS code,
                                sector_name AS name,
                                sector_type AS type,
                                parent_artcc,
                                floor_altitude,
                                ceiling_altitude,
                                ST_AsGeoJSON(geom) AS geojson,
                                label_lat, label_lon
                            FROM sector_boundaries
                            WHERE sector_code = :code AND sector_type = :sector_type
                            LIMIT 1";
                    break;

                default:
                    $this->lastError = "Unknown facility type: $facilityType";
                    return null;
            }

            $stmt = $this->conn->prepare($sql);
            $params = [':code' => $facilityCode];
            if (isset($sectorType)) {
                $params[':sector_type'] = $sectorType;
            }
            $stmt->execute($params);

            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }

            // Build GeoJSON Feature
            $geometry = json_decode($row['geojson'], true);
            $properties = [
                'code' => $row['code'],
                'name' => $row['name'] ?? '',
                'type' => $facilityType,
                'parent_artcc' => $row['parent_artcc'] ?? null,
                'label_lat' => $row['label_lat'] ? (float)$row['label_lat'] : null,
                'label_lon' => $row['label_lon'] ? (float)$row['label_lon'] : null
            ];

            if (isset($row['floor_altitude'])) {
                $properties['floor_altitude'] = (int)$row['floor_altitude'];
                $properties['ceiling_altitude'] = (int)$row['ceiling_altitude'];
            }

            return [
                'type' => 'Feature',
                'properties' => $properties,
                'geometry' => $geometry
            ];

        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            error_log('GISService::getFacilityBoundaryGeoJSON error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get multiple facility boundaries as GeoJSON FeatureCollection
     *
     * @param array $facilities Array of ['type' => 'ARTCC', 'code' => 'ZNY'] objects
     * @return array GeoJSON FeatureCollection
     */
    public function getFacilityBoundariesGeoJSON(array $facilities): array
    {
        $features = [];

        foreach ($facilities as $fac) {
            $type = $fac['type'] ?? 'ARTCC';
            $code = $fac['code'] ?? '';

            if (!$code) continue;

            $feature = $this->getFacilityBoundaryGeoJSON($type, $code);
            if ($feature) {
                $features[] = $feature;
            }
        }

        return [
            'type' => 'FeatureCollection',
            'features' => $features
        ];
    }

    /**
     * Get TMI context map data (requesting/providing facilities + fix locations)
     *
     * Returns all data needed to render a TMI context map:
     * - Requestor and provider facility boundaries
     * - Fix location(s)
     * - Origin/destination airports
     * - Shared boundary between facilities (if applicable)
     *
     * @param string $requestor Requestor facility code (e.g., 'N90')
     * @param string $provider Provider facility code (e.g., 'ZNY')
     * @param array $fixes Array of fix names to resolve (e.g., ['CAMRN', 'BEUTY'])
     * @param array|null $origins Array of origin airport codes (optional)
     * @param array|null $destinations Array of destination airport codes (optional)
     * @return array Map data for rendering
     */
    public function getTMIMapData(
        string $requestor,
        string $provider,
        array $fixes = [],
        ?array $origins = null,
        ?array $destinations = null
    ): array {
        $result = [
            'facilities' => [],
            'fixes' => [],
            'airports' => [],
            'shared_boundary' => null,
            'center' => null,
            'bounds' => null
        ];

        // Detect facility types
        $requestorType = $this->detectFacilityType($requestor);
        $providerType = $this->detectFacilityType($provider);

        // Get facility boundaries
        if ($requestor) {
            $reqBoundary = $this->getFacilityBoundaryGeoJSON($requestorType, $requestor);
            if ($reqBoundary) {
                $reqBoundary['properties']['role'] = 'requestor';
                $result['facilities'][] = $reqBoundary;
            }
        }

        if ($provider && $provider !== $requestor) {
            $provBoundary = $this->getFacilityBoundaryGeoJSON($providerType, $provider);
            if ($provBoundary) {
                $provBoundary['properties']['role'] = 'provider';
                $result['facilities'][] = $provBoundary;
            }
        }

        // Get shared boundary between facilities (handoff boundary)
        if ($requestor && $provider && $requestor !== $provider) {
            $sharedBoundary = $this->getSharedBoundary($requestorType, $requestor, $providerType, $provider);
            if ($sharedBoundary) {
                $result['shared_boundary'] = $sharedBoundary;
            }
        }

        // Resolve fix locations
        foreach ($fixes as $fix) {
            if (!$fix || strtoupper($fix) === 'ALL') continue;

            $fixData = $this->resolveWaypoint($fix);
            if ($fixData) {
                $result['fixes'][] = [
                    'type' => 'Feature',
                    'properties' => [
                        'name' => $fixData['fix_id'],
                        'source' => $fixData['source']
                    ],
                    'geometry' => [
                        'type' => 'Point',
                        'coordinates' => [$fixData['lon'], $fixData['lat']]
                    ]
                ];
            }
        }

        // Resolve airport locations
        $airportCodes = array_merge($origins ?? [], $destinations ?? []);
        foreach (array_unique($airportCodes) as $apt) {
            if (!$apt) continue;

            $aptData = $this->resolveWaypoint($apt);
            if ($aptData) {
                $isOrigin = $origins && in_array($apt, $origins);
                $isDest = $destinations && in_array($apt, $destinations);

                $result['airports'][] = [
                    'type' => 'Feature',
                    'properties' => [
                        'code' => $apt,
                        'is_origin' => $isOrigin,
                        'is_destination' => $isDest,
                        'role' => $isOrigin ? ($isDest ? 'both' : 'origin') : 'destination'
                    ],
                    'geometry' => [
                        'type' => 'Point',
                        'coordinates' => [$aptData['lon'], $aptData['lat']]
                    ]
                ];
            }
        }

        // Calculate bounds from all features
        $allCoords = [];
        foreach ($result['facilities'] as $fac) {
            if ($fac['geometry']) {
                $this->extractCoords($fac['geometry'], $allCoords);
            }
        }
        foreach ($result['fixes'] as $fix) {
            $allCoords[] = $fix['geometry']['coordinates'];
        }
        foreach ($result['airports'] as $apt) {
            $allCoords[] = $apt['geometry']['coordinates'];
        }

        if (!empty($allCoords)) {
            $result['bounds'] = $this->calculateBounds($allCoords);
            $result['center'] = [
                ($result['bounds'][0] + $result['bounds'][2]) / 2,
                ($result['bounds'][1] + $result['bounds'][3]) / 2
            ];
        }

        return $result;
    }

    /**
     * Get shared boundary (intersection line) between two facilities
     *
     * @param string $type1 First facility type
     * @param string $code1 First facility code
     * @param string $type2 Second facility type
     * @param string $code2 Second facility code
     * @return array|null GeoJSON Feature with LineString geometry
     */
    public function getSharedBoundary(string $type1, string $code1, string $type2, string $code2): ?array
    {
        if (!$this->conn) {
            return null;
        }

        try {
            // Build SQL based on facility types
            $table1 = $this->getFacilityTable($type1);
            $table2 = $this->getFacilityTable($type2);
            $codeCol1 = $this->getFacilityCodeColumn($type1);
            $codeCol2 = $this->getFacilityCodeColumn($type2);

            if (!$table1 || !$table2) {
                return null;
            }

            $sql = "
                SELECT ST_AsGeoJSON(
                    ST_Intersection(
                        ST_Boundary(a.geom),
                        ST_Boundary(b.geom)
                    )
                ) AS geojson
                FROM {$table1} a, {$table2} b
                WHERE a.{$codeCol1} = :code1
                  AND b.{$codeCol2} = :code2
                  AND ST_Intersects(a.geom, b.geom)
                LIMIT 1
            ";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                ':code1' => strtoupper($code1),
                ':code2' => strtoupper($code2)
            ]);

            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || !$row['geojson']) {
                return null;
            }

            $geometry = json_decode($row['geojson'], true);
            if (!$geometry) {
                return null;
            }

            return [
                'type' => 'Feature',
                'properties' => [
                    'type' => 'shared_boundary',
                    'facility1' => $code1,
                    'facility2' => $code2,
                    'description' => "{$code1} / {$code2} handoff boundary"
                ],
                'geometry' => $geometry
            ];

        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            error_log('GISService::getSharedBoundary error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Detect facility type from code pattern
     *
     * @param string $code Facility code
     * @return string Facility type (ARTCC, TRACON, SECTOR_HIGH, etc.)
     */
    private function detectFacilityType(string $code): string
    {
        $code = strtoupper($code);

        // ARTCC: Z + 2 letters (ZNY, ZDC) or K + Z + 2 letters (KZNY)
        if (preg_match('/^K?Z[A-Z]{2}$/', $code)) {
            return 'ARTCC';
        }

        // Canadian FIR: CZ + 2 letters
        if (preg_match('/^CZ[A-Z]{2}$/', $code)) {
            return 'ARTCC';
        }

        // Sector: ARTCC + digits (ZNY66, ZFW15)
        if (preg_match('/^Z[A-Z]{2}\d+$/', $code)) {
            return 'SECTOR_HIGH'; // Default to high, could check DB
        }

        // TRACON: Letter + 2 digits (N90, A80, C90)
        if (preg_match('/^[A-Z]\d{2}$/', $code)) {
            return 'TRACON';
        }

        // 3-letter TRACONs (PCT, SCT, NCT)
        if (preg_match('/^[A-Z]{3}$/', $code) && !preg_match('/^[A-Z]{3}$/', $code)) {
            // Try TRACON lookup
            return 'TRACON';
        }

        // Default to ARTCC
        return 'ARTCC';
    }

    /**
     * Get table name for facility type
     */
    private function getFacilityTable(string $type): ?string
    {
        return match (strtoupper($type)) {
            'ARTCC' => 'artcc_boundaries',
            'TRACON' => 'tracon_boundaries',
            'SECTOR_LOW', 'SECTOR_HIGH', 'SECTOR_SUPERHIGH' => 'sector_boundaries',
            default => null
        };
    }

    /**
     * Get code column name for facility type
     */
    private function getFacilityCodeColumn(string $type): ?string
    {
        return match (strtoupper($type)) {
            'ARTCC' => 'artcc_code',
            'TRACON' => 'tracon_code',
            'SECTOR_LOW', 'SECTOR_HIGH', 'SECTOR_SUPERHIGH' => 'sector_code',
            default => null
        };
    }

    /**
     * Extract all coordinates from a GeoJSON geometry
     */
    private function extractCoords(array $geometry, array &$coords): void
    {
        $type = $geometry['type'] ?? '';

        if ($type === 'Point') {
            $coords[] = $geometry['coordinates'];
        } elseif ($type === 'LineString' || $type === 'MultiPoint') {
            foreach ($geometry['coordinates'] as $coord) {
                $coords[] = $coord;
            }
        } elseif ($type === 'Polygon' || $type === 'MultiLineString') {
            foreach ($geometry['coordinates'] as $ring) {
                foreach ($ring as $coord) {
                    $coords[] = $coord;
                }
            }
        } elseif ($type === 'MultiPolygon') {
            foreach ($geometry['coordinates'] as $poly) {
                foreach ($poly as $ring) {
                    foreach ($ring as $coord) {
                        $coords[] = $coord;
                    }
                }
            }
        }
    }

    /**
     * Calculate bounding box from coordinates
     *
     * @param array $coords Array of [lon, lat] coordinates
     * @return array [minLon, minLat, maxLon, maxLat]
     */
    private function calculateBounds(array $coords): array
    {
        $minLon = $maxLon = $coords[0][0];
        $minLat = $maxLat = $coords[0][1];

        foreach ($coords as $coord) {
            $minLon = min($minLon, $coord[0]);
            $maxLon = max($maxLon, $coord[0]);
            $minLat = min($minLat, $coord[1]);
            $maxLat = max($maxLat, $coord[1]);
        }

        return [$minLon, $minLat, $maxLon, $maxLat];
    }
}
