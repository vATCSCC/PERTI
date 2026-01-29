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
                    'name' => $row['airport_name'],
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
}
