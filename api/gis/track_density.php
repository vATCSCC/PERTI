<?php
/**
 * Track Density API
 *
 * Uses PostGIS to calculate traffic density for flight trajectories.
 * Supports multiple analysis modes including alpha shapes, proximity density,
 * and DBSCAN-based flow stream detection with merge zone identification.
 *
 * Results are cached by TMI/event ID to avoid recomputation for multiple users.
 *
 * Endpoints:
 *   POST ?action=calculate        - Grid-based density (legacy)
 *   POST ?action=flow_envelope    - Concave hull (alpha shape) of trajectory points
 *   POST ?action=segment_density  - Per-segment proximity density
 *   POST ?action=flow_streams     - DBSCAN clustering with merge zone detection
 *   POST ?action=analyze          - Combined streams + segment density (full analysis)
 *   POST ?action=branch_analysis  - Multi-level branch identification (O/D + DBSCAN + topology naming)
 *   GET  ?action=get_cached       - Retrieve cached results by cache_key
 *   POST ?action=invalidate       - Invalidate cache for a specific key
 *
 * @version 3.0.0
 */

set_time_limit(120); // PostGIS spatial queries can be slow for large trajectory sets
ini_set('memory_limit', '256M');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../../load/connect.php';

$response = ['success' => false, 'data' => null, 'error' => null, 'cached' => false];

try {
    $gis = get_conn_gis();
    if (!$gis) {
        throw new Exception('PostGIS connection unavailable');
    }

    $action = $_GET['action'] ?? 'calculate';

    // Ensure cache table exists
    ensureCacheTable($gis);

    // Handle cache retrieval (GET request)
    if ($action === 'get_cached' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $cacheKey = $_GET['cache_key'] ?? null;
        if (!$cacheKey) {
            throw new Exception('cache_key parameter required');
        }
        $cached = getCachedResult($gis, $cacheKey);
        if ($cached) {
            $response['success'] = true;
            $response['data'] = $cached['result'];
            $response['cached'] = true;
            $response['computed_at'] = $cached['computed_at'];
        } else {
            $response['success'] = false;
            $response['error'] = 'No cached result found';
        }
        echo json_encode($response);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('POST method required');
    }

    $input = json_decode(file_get_contents('php://input'), true);

    // Handle cache invalidation
    if ($action === 'invalidate') {
        $cacheKey = $input['cache_key'] ?? null;
        if (!$cacheKey) {
            throw new Exception('cache_key required for invalidation');
        }
        invalidateCache($gis, $cacheKey);
        $response['success'] = true;
        $response['data'] = ['invalidated' => $cacheKey];
        echo json_encode($response);
        exit;
    }

    if (!$input || !isset($input['trajectories'])) {
        throw new Exception('Missing trajectories in request body');
    }

    $trajectories = $input['trajectories'];
    $cacheKey = $input['cache_key'] ?? null; // TMI ID or event identifier
    $cacheTtlMinutes = $input['cache_ttl'] ?? 30; // Default 30 minute cache
    $forceRecalc = $input['force_recalc'] ?? false;

    if (empty($trajectories)) {
        $response['success'] = true;
        $response['data'] = ['trajectories' => [], 'max_density' => 0];
        echo json_encode($response);
        exit;
    }

    // Check cache first (if cache_key provided and not forcing recalc)
    if ($cacheKey && !$forceRecalc) {
        $cached = getCachedResult($gis, $cacheKey, $action);
        if ($cached) {
            $response['success'] = true;
            $response['data'] = $cached['result'];
            $response['cached'] = true;
            $response['computed_at'] = $cached['computed_at'];
            echo json_encode($response);
            exit;
        }
    }

    // Common parameters
    $proximityNm = $input['proximity_nm'] ?? 5;
    $hullShrinkFactor = $input['hull_shrink'] ?? 0.3;
    $fixPoint = $input['fix_point'] ?? null;
    $clusterEpsNm = $input['cluster_eps_nm'] ?? 3; // DBSCAN epsilon in nautical miles
    $clusterMinPoints = $input['cluster_min_points'] ?? 5; // DBSCAN minimum points
    $isArrival = $input['is_arrival'] ?? true; // true = converging to fix, false = diverging from fix
    $distanceBandNm = $input['distance_band_nm'] ?? 15; // NM width for grouping streams into position bands
    $knownFixes = $input['known_fixes'] ?? []; // Array of {id, lat, lon} for fix-relative naming

    // Convert NM to approximate degrees (at mid-latitudes, 1° ≈ 60nm)
    $proximityDeg = $proximityNm / 60.0;
    $clusterEpsDeg = $clusterEpsNm / 60.0;

    // Build temporary tables with trajectory data
    buildTempTables($gis, $trajectories);

    $result = null;

    switch ($action) {
        case 'flow_streams':
            $result = calculateFlowStreams($gis, $clusterEpsDeg, $clusterMinPoints, $hullShrinkFactor, $fixPoint, $isArrival, $distanceBandNm, $knownFixes);
            break;

        case 'flow_envelope':
            $result = calculateFlowEnvelope($gis, $hullShrinkFactor, $fixPoint);
            break;

        case 'segment_density':
            $result = calculateSegmentDensity($gis, $trajectories, $proximityDeg, $proximityNm);
            break;

        case 'analyze':
            // Full analysis: DBSCAN streams + segment density
            $streams = calculateFlowStreams($gis, $clusterEpsDeg, $clusterMinPoints, $hullShrinkFactor, $fixPoint, $isArrival, $distanceBandNm, $knownFixes);
            $density = calculateSegmentDensity($gis, $trajectories, $proximityDeg, $proximityNm);
            $result = [
                'streams' => $streams,
                'density' => $density
            ];
            break;

        case 'branch_analysis':
            $mitDistanceNm = $input['mit_distance_nm'] ?? 15;
            $maxDistanceNm = $input['max_distance_nm'] ?? 250;
            $flightMeta = $input['flight_meta'] ?? []; // {callsign: {dept, dest, waypoints[]}}
            $tmiType = $input['tmi_type'] ?? 'arrival'; // arrival, departure, overflight
            $result = calculateBranchAnalysis(
                $gis, $trajectories, $fixPoint,
                $mitDistanceNm, $maxDistanceNm,
                $flightMeta, $tmiType,
                $clusterEpsDeg, $clusterMinPoints,
                $knownFixes
            );
            break;

        case 'calculate':
        default:
            $gridSize = $input['grid_size'] ?? 0.05;
            $result = calculateGridDensity($gis, $trajectories, $gridSize);
            break;
    }

    // Cleanup temp tables
    $gis->exec("DROP TABLE IF EXISTS temp_traj_points");
    $gis->exec("DROP TABLE IF EXISTS temp_traj_segments");

    // Cache the result if cache_key provided
    if ($cacheKey && $result) {
        cacheResult($gis, $cacheKey, $action, $result, $cacheTtlMinutes);
    }

    $response['success'] = true;
    $response['data'] = $result;
    $response['cached'] = false;

} catch (Exception $e) {
    $response['error'] = $e->getMessage();
    http_response_code(500);
}

echo json_encode($response);

// ============================================================================
// CACHE FUNCTIONS
// ============================================================================

/**
 * Ensure the cache table exists in PostGIS database
 */
function ensureCacheTable(PDO $gis): void
{
    $gis->exec("
        CREATE TABLE IF NOT EXISTS tmi_density_cache (
            cache_key VARCHAR(100) NOT NULL,
            analysis_type VARCHAR(50) NOT NULL,
            computed_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
            expires_at TIMESTAMP WITH TIME ZONE NOT NULL,
            result_json JSONB NOT NULL,
            PRIMARY KEY (cache_key, analysis_type)
        )
    ");

    // Create index for expiration cleanup
    $gis->exec("
        CREATE INDEX IF NOT EXISTS idx_tmi_density_cache_expires
        ON tmi_density_cache (expires_at)
    ");
}

/**
 * Get cached result if valid
 */
function getCachedResult(PDO $gis, string $cacheKey, ?string $analysisType = null): ?array
{
    $sql = "
        SELECT result_json, computed_at
        FROM tmi_density_cache
        WHERE cache_key = :cache_key
        AND expires_at > NOW()
    ";
    $params = [':cache_key' => $cacheKey];

    if ($analysisType) {
        $sql .= " AND analysis_type = :analysis_type";
        $params[':analysis_type'] = $analysisType;
    }

    $stmt = $gis->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        return [
            'result' => json_decode($row['result_json'], true),
            'computed_at' => $row['computed_at']
        ];
    }
    return null;
}

/**
 * Cache a computed result
 */
function cacheResult(PDO $gis, string $cacheKey, string $analysisType, array $result, int $ttlMinutes): void
{
    $stmt = $gis->prepare("
        INSERT INTO tmi_density_cache (cache_key, analysis_type, computed_at, expires_at, result_json)
        VALUES (:cache_key, :analysis_type, NOW(), NOW() + INTERVAL ':ttl minutes', :result_json)
        ON CONFLICT (cache_key, analysis_type)
        DO UPDATE SET
            computed_at = NOW(),
            expires_at = NOW() + INTERVAL ':ttl minutes',
            result_json = :result_json
    ");

    // PostgreSQL doesn't support parameter in INTERVAL, so we build it differently
    $expiresAt = date('Y-m-d H:i:s', strtotime("+{$ttlMinutes} minutes"));

    $stmt = $gis->prepare("
        INSERT INTO tmi_density_cache (cache_key, analysis_type, computed_at, expires_at, result_json)
        VALUES (:cache_key, :analysis_type, NOW(), :expires_at, :result_json)
        ON CONFLICT (cache_key, analysis_type)
        DO UPDATE SET
            computed_at = NOW(),
            expires_at = EXCLUDED.expires_at,
            result_json = EXCLUDED.result_json
    ");

    $stmt->execute([
        ':cache_key' => $cacheKey,
        ':analysis_type' => $analysisType,
        ':expires_at' => $expiresAt,
        ':result_json' => json_encode($result)
    ]);
}

/**
 * Invalidate cache for a specific key (all analysis types)
 */
function invalidateCache(PDO $gis, string $cacheKey): void
{
    $stmt = $gis->prepare("DELETE FROM tmi_density_cache WHERE cache_key = :cache_key");
    $stmt->execute([':cache_key' => $cacheKey]);
}

// ============================================================================
// DATA PREPARATION
// ============================================================================

/**
 * Build temporary tables with trajectory data
 */
function buildTempTables(PDO $gis, array $trajectories): void
{
    $gis->exec("DROP TABLE IF EXISTS temp_traj_points");
    $gis->exec("DROP TABLE IF EXISTS temp_traj_segments");

    $gis->exec("
        CREATE TEMP TABLE temp_traj_points (
            callsign VARCHAR(20),
            seq INTEGER,
            geom GEOMETRY(Point, 4326)
        )
    ");

    $gis->exec("
        CREATE TEMP TABLE temp_traj_segments (
            callsign VARCHAR(20),
            seg_idx INTEGER,
            geom GEOMETRY(LineString, 4326)
        )
    ");

    // Batch insert points and segments for performance (avoids per-row round-trips)
    $pointValues = [];
    $segValues = [];
    $batchSize = 500;

    foreach ($trajectories as $key => $traj) {
        $callsign = isset($traj['callsign']) ? $traj['callsign'] : $key;
        if (!isset($traj['coordinates']) || !is_array($traj['coordinates']) || count($traj['coordinates']) < 2) {
            continue;
        }

        $cs = $gis->quote($callsign);
        $coords = $traj['coordinates'];
        for ($i = 0; $i < count($coords); $i++) {
            if (!is_array($coords[$i]) || count($coords[$i]) < 2 ||
                !is_numeric($coords[$i][0]) || !is_numeric($coords[$i][1])) {
                continue;
            }

            $lon = (float)$coords[$i][0];
            $lat = (float)$coords[$i][1];
            $pointValues[] = "($cs, $i, ST_SetSRID(ST_MakePoint($lon, $lat), 4326))";

            if ($i < count($coords) - 1) {
                if (!is_array($coords[$i + 1]) || count($coords[$i + 1]) < 2 ||
                    !is_numeric($coords[$i + 1][0]) || !is_numeric($coords[$i + 1][1])) {
                    continue;
                }
                $lon2 = (float)$coords[$i + 1][0];
                $lat2 = (float)$coords[$i + 1][1];
                $segValues[] = "($cs, $i, ST_SetSRID(ST_MakeLine(ST_MakePoint($lon, $lat), ST_MakePoint($lon2, $lat2)), 4326))";
            }

            // Flush points batch
            if (count($pointValues) >= $batchSize) {
                $gis->exec("INSERT INTO temp_traj_points (callsign, seq, geom) VALUES " . implode(',', $pointValues));
                $pointValues = [];
            }
            // Flush segments batch
            if (count($segValues) >= $batchSize) {
                $gis->exec("INSERT INTO temp_traj_segments (callsign, seg_idx, geom) VALUES " . implode(',', $segValues));
                $segValues = [];
            }
        }
    }

    // Flush remaining
    if (!empty($pointValues)) {
        $gis->exec("INSERT INTO temp_traj_points (callsign, seq, geom) VALUES " . implode(',', $pointValues));
    }
    if (!empty($segValues)) {
        $gis->exec("INSERT INTO temp_traj_segments (callsign, seg_idx, geom) VALUES " . implode(',', $segValues));
    }

    $gis->exec("CREATE INDEX temp_traj_points_geom_idx ON temp_traj_points USING GIST(geom)");
    $gis->exec("CREATE INDEX temp_traj_segments_geom_idx ON temp_traj_segments USING GIST(geom)");
}

// ============================================================================
// FLOW STREAM DETECTION (DBSCAN + Merge Zones)
// ============================================================================

/**
 * Calculate flow streams using DBSCAN spatial clustering
 * Identifies distinct traffic flows and where they merge
 *
 * Returns streams with fix-relative hierarchical IDs:
 *   CAMRN/1   = Traffic via CAMRN fix, position 1 (upstream)
 *   NE/1      = Northeast arrivals (fallback if no fixes provided)
 *   CAMRN+LENDY/M1 = Merge zone of CAMRN and LENDY streams
 *
 * Each stream includes structured metadata:
 *   - stream_id: Human-readable fix-relative ID
 *   - components: Machine-parseable structure {fixes[], position, is_merge}
 *   - spatial: {bearing, distance_nm, cardinal}
 *   - display: {short, long} for UI rendering
 *
 * @param bool $isArrival If true, position 1 = furthest from fix; if false (departure), position 1 = closest to fix
 * @param float $distanceBandNm Width in NM for grouping streams into same position band
 * @param array $knownFixes Array of known fixes [{id: "CAMRN", lat: 40.5, lon: -73.2}, ...]
 */
function calculateFlowStreams(PDO $gis, float $epsDeg, int $minPoints, float $shrinkFactor, ?array $fixPoint, bool $isArrival = true, float $distanceBandNm = 15, array $knownFixes = []): array
{
    // Use ST_ClusterDBSCAN to cluster trajectory segments spatially
    // This identifies distinct traffic streams based on spatial proximity
    $clusterQuery = $gis->prepare("
        WITH segment_clusters AS (
            SELECT
                callsign,
                seg_idx,
                geom,
                ST_ClusterDBSCAN(geom, eps := :eps, minpoints := :minpoints)
                    OVER () as cluster_id
            FROM temp_traj_segments
        ),
        cluster_stats AS (
            SELECT
                cluster_id,
                COUNT(DISTINCT callsign) as track_count,
                COUNT(*) as segment_count,
                ST_Collect(geom) as collected_geom
            FROM segment_clusters
            WHERE cluster_id IS NOT NULL
            GROUP BY cluster_id
            HAVING COUNT(DISTINCT callsign) >= 2
        )
        SELECT
            cluster_id,
            track_count,
            segment_count,
            ST_AsGeoJSON(ST_ConcaveHull(collected_geom, :shrink_factor)) as hull_geom,
            ST_AsGeoJSON(ST_Centroid(collected_geom)) as centroid,
            ST_AsGeoJSON(ST_Envelope(collected_geom)) as bbox
        FROM cluster_stats
        ORDER BY track_count DESC
    ");

    $clusterQuery->execute([
        ':eps' => $epsDeg,
        ':minpoints' => $minPoints,
        ':shrink_factor' => $shrinkFactor
    ]);

    $streams = [];
    $streamHulls = []; // For merge zone detection

    while ($row = $clusterQuery->fetch(PDO::FETCH_ASSOC)) {
        $clusterId = (int)$row['cluster_id'];
        $hull = json_decode($row['hull_geom'], true);

        $streams[] = [
            'raw_cluster_id' => $clusterId,
            'track_count' => (int)$row['track_count'],
            'segment_count' => (int)$row['segment_count'],
            'hull' => $hull,
            'hull_geojson' => $row['hull_geom'], // Keep raw for merge detection
            'centroid' => json_decode($row['centroid'], true),
            'bbox' => json_decode($row['bbox'], true)
        ];

        $streamHulls[$clusterId] = $row['hull_geom'];
    }

    // Detect merge zones: where stream hulls intersect
    $mergeZones = detectMergeZones($gis, $streamHulls);

    // If fix point provided, calculate approach directions and distances for each stream
    if ($fixPoint && count($fixPoint) >= 2) {
        $streams = addStreamDirections($gis, $streams, $fixPoint);
    }

    // Match streams to known fixes (if provided)
    if (!empty($knownFixes)) {
        $streams = matchStreamsToFixes($gis, $streams, $knownFixes);
    }

    // Assign hierarchical stream addresses based on topology and distance from fix
    $addressedResult = assignStreamAddresses($gis, $streams, $mergeZones, $fixPoint, $isArrival, $distanceBandNm, $knownFixes);
    $streams = $addressedResult['streams'];
    $mergeZones = $addressedResult['merge_zones'];

    // Get overall envelope
    $envelopeQuery = $gis->query("
        SELECT
            ST_AsGeoJSON(ST_ConcaveHull(ST_Collect(geom), 0.3)) as overall_hull,
            COUNT(DISTINCT callsign) as total_tracks
        FROM temp_traj_segments
    ");
    $envelope = $envelopeQuery->fetch(PDO::FETCH_ASSOC);

    return [
        'streams' => $streams,
        'merge_zones' => $mergeZones,
        'overall_hull' => json_decode($envelope['overall_hull'], true),
        'total_tracks' => (int)$envelope['total_tracks'],
        'params' => [
            'eps_nm' => $epsDeg * 60,
            'min_points' => $minPoints,
            'shrink_factor' => $shrinkFactor,
            'is_arrival' => $isArrival,
            'distance_band_nm' => $distanceBandNm,
            'known_fixes' => array_map(fn($f) => $f['id'] ?? $f['name'] ?? 'UNKNOWN', $knownFixes)
        ]
    ];
}

/**
 * Match streams to known fixes based on spatial proximity
 * Each stream gets assigned its nearest fix (if within threshold)
 */
function matchStreamsToFixes(PDO $gis, array $streams, array $knownFixes): array
{
    if (empty($knownFixes)) {
        return $streams;
    }

    $maxMatchDistanceNm = 30; // Max distance to consider a fix match

    foreach ($streams as &$stream) {
        $centroid = $stream['centroid'];
        if (!$centroid || !isset($centroid['coordinates'])) {
            continue;
        }

        $cLon = $centroid['coordinates'][0];
        $cLat = $centroid['coordinates'][1];

        $nearestFix = null;
        $nearestDist = PHP_INT_MAX;

        foreach ($knownFixes as $fix) {
            if (!is_array($fix)) continue;

            $fixId = $fix['id'] ?? $fix['name'] ?? 'UNKNOWN';
            $fixLat = $fix['lat'] ?? $fix['latitude'] ?? null;
            $fixLon = $fix['lon'] ?? $fix['lng'] ?? $fix['longitude'] ?? null;

            // Validate fix has valid numeric coordinates
            if ($fixLat === null || $fixLon === null ||
                !is_numeric($fixLat) || !is_numeric($fixLon)) continue;

            // Calculate distance
            $distQuery = $gis->prepare("
                SELECT ST_Distance(
                    ST_SetSRID(ST_MakePoint(:c_lon, :c_lat), 4326)::geography,
                    ST_SetSRID(ST_MakePoint(:f_lon, :f_lat), 4326)::geography
                ) / 1852 as distance_nm
            ");
            $distQuery->execute([
                ':c_lon' => $cLon,
                ':c_lat' => $cLat,
                ':f_lon' => $fixLon,
                ':f_lat' => $fixLat
            ]);
            $result = $distQuery->fetch(PDO::FETCH_ASSOC);
            $distNm = (float)$result['distance_nm'];

            if ($distNm < $nearestDist && $distNm <= $maxMatchDistanceNm) {
                $nearestDist = $distNm;
                $nearestFix = [
                    'id' => $fixId,
                    'lat' => $fixLat,
                    'lon' => $fixLon,
                    'distance_nm' => round($distNm, 1)
                ];
            }
        }

        $stream['matched_fix'] = $nearestFix;
    }

    return $streams;
}

/**
 * Assign hierarchical stream addresses with fix-relative naming and structured metadata
 *
 * Address format: <FIX>/<position> or <FIX>+<FIX>/M<position>
 *   CAMRN/1     = Traffic via CAMRN, position 1 (upstream)
 *   NE/1        = Northeast arrivals (fallback if no fix matched)
 *   CAMRN+LENDY/M1 = Merge zone of CAMRN and LENDY streams
 *
 * Each stream includes structured metadata for both human display and machine parsing.
 */
function assignStreamAddresses(PDO $gis, array $streams, array $mergeZones, ?array $fixPoint, bool $isArrival, float $distanceBandNm, array $knownFixes = []): array
{
    if (empty($streams)) {
        return ['streams' => [], 'merge_zones' => []];
    }

    // Build merge graph: which clusters overlap with which
    $mergeGraph = [];
    foreach ($streams as $stream) {
        $mergeGraph[$stream['raw_cluster_id']] = [];
    }
    foreach ($mergeZones as $mz) {
        $c1 = $mz['streams'][0];
        $c2 = $mz['streams'][1];
        if (isset($mergeGraph[$c1]) && isset($mergeGraph[$c2])) {
            $mergeGraph[$c1][] = $c2;
            $mergeGraph[$c2][] = $c1;
        }
    }

    // Sort streams by distance from fix
    usort($streams, function($a, $b) use ($isArrival) {
        $distA = $a['distance_to_fix_nm'] ?? PHP_INT_MAX;
        $distB = $b['distance_to_fix_nm'] ?? PHP_INT_MAX;
        return $isArrival ? ($distB <=> $distA) : ($distA <=> $distB);
    });

    // Find connected components (streams that merge together)
    $visited = [];
    $components = [];
    foreach ($streams as $stream) {
        $cid = $stream['raw_cluster_id'];
        if (isset($visited[$cid])) continue;

        $component = [];
        $queue = [$cid];
        $visited[$cid] = true;

        while (!empty($queue)) {
            $current = array_shift($queue);
            $component[] = $current;
            foreach ($mergeGraph[$current] as $neighbor) {
                if (!isset($visited[$neighbor])) {
                    $visited[$neighbor] = true;
                    $queue[] = $neighbor;
                }
            }
        }
        $components[] = $component;
    }

    // Build stream ID for each stream
    $streamToName = []; // raw_cluster_id => fix name or cardinal
    $streamToParents = []; // raw_cluster_id => [parent fix names]

    foreach ($components as $component) {
        // Sort component streams by distance (outermost first)
        $componentStreams = array_values(array_filter($streams, function($s) use ($component) {
            return in_array($s['raw_cluster_id'], $component);
        }));
        usort($componentStreams, function($a, $b) use ($isArrival) {
            $distA = $a['distance_to_fix_nm'] ?? PHP_INT_MAX;
            $distB = $b['distance_to_fix_nm'] ?? PHP_INT_MAX;
            return $isArrival ? ($distB <=> $distA) : ($distA <=> $distB);
        });

        // Group into distance bands
        $bands = groupIntoDistanceBands($componentStreams, $distanceBandNm);

        foreach ($bands as $bandIdx => $bandStreams) {
            foreach ($bandStreams as $bs) {
                $cid = $bs['raw_cluster_id'];

                if ($bandIdx === 0) {
                    // Outermost band: use fix name or cardinal direction
                    $name = getStreamName($bs);
                    $streamToName[$cid] = $name;
                    $streamToParents[$cid] = [$name];
                } else {
                    // Inner bands: inherit from parent streams (merging)
                    $parentNames = [];
                    foreach ($mergeGraph[$cid] as $neighbor) {
                        if (isset($streamToParents[$neighbor])) {
                            $parentNames = array_merge($parentNames, $streamToParents[$neighbor]);
                        }
                    }
                    $parentNames = array_unique($parentNames);
                    sort($parentNames);

                    if (empty($parentNames)) {
                        // Fallback
                        $name = getStreamName($bs);
                        $parentNames = [$name];
                    }

                    $streamToName[$cid] = implode('+', $parentNames);
                    $streamToParents[$cid] = $parentNames;
                }
            }
        }
    }

    // Group streams by their name pattern and assign positions
    $nameGroups = [];
    foreach ($streams as $stream) {
        $name = $streamToName[$stream['raw_cluster_id']] ?? 'UNKNOWN';
        if (!isset($nameGroups[$name])) {
            $nameGroups[$name] = [];
        }
        $nameGroups[$name][] = $stream;
    }

    // Build final addressed streams with structured metadata
    $addressedStreams = [];
    foreach ($nameGroups as $name => $groupStreams) {
        usort($groupStreams, function($a, $b) use ($isArrival) {
            $distA = $a['distance_to_fix_nm'] ?? PHP_INT_MAX;
            $distB = $b['distance_to_fix_nm'] ?? PHP_INT_MAX;
            return $isArrival ? ($distB <=> $distA) : ($distA <=> $distB);
        });

        $isMerge = strpos($name, '+') !== false;
        $position = 1;

        foreach ($groupStreams as $gs) {
            $positionPrefix = $isMerge ? 'M' : '';
            $streamId = $name . '/' . $positionPrefix . $position;

            // Get source fix names
            $fixes = $streamToParents[$gs['raw_cluster_id']] ?? [$name];

            // Build structured metadata
            $gs['stream_id'] = $streamId;
            $gs['components'] = [
                'fixes' => $fixes,
                'position' => $position,
                'is_merge' => $isMerge
            ];
            $gs['display'] = [
                'short' => $isMerge
                    ? implode('+', array_map(fn($f) => substr($f, 0, 3), $fixes)) . '-M' . $position
                    : $name . '-' . $position,
                'long' => $isMerge
                    ? implode(' + ', $fixes) . ' Merge Zone, Position ' . $position
                    : $name . ' Stream, Position ' . $position
            ];
            $gs['spatial'] = [
                'bearing_to_fix' => $gs['bearing_to_fix'] ?? null,
                'distance_nm' => $gs['distance_to_fix_nm'] ?? null,
                'cardinal' => $gs['approach_direction'] ?? null
            ];

            // Preserve matched fix info if available
            if (isset($gs['matched_fix'])) {
                $gs['nav_reference'] = $gs['matched_fix'];
            }

            unset($gs['hull_geojson']);
            $addressedStreams[] = $gs;
            $position++;
        }
    }

    // Sort for consistent ordering
    usort($addressedStreams, function($a, $b) {
        return strcmp($a['stream_id'], $b['stream_id']);
    });

    // Update merge zones with new addressing
    $addressedMergeZones = [];
    foreach ($mergeZones as $mz) {
        $addr1 = findStreamAddress($addressedStreams, $mz['streams'][0]);
        $addr2 = findStreamAddress($addressedStreams, $mz['streams'][1]);

        // Extract fix names from addresses
        $fixes1 = extractFixesFromAddress($addr1);
        $fixes2 = extractFixesFromAddress($addr2);
        $allFixes = array_unique(array_merge($fixes1, $fixes2));
        sort($allFixes);

        $mz['stream_addresses'] = [$addr1, $addr2];
        $mz['merge_id'] = implode('+', $allFixes) . '/MERGE';
        $mz['components'] = [
            'parent_streams' => [$addr1, $addr2],
            'fixes' => $allFixes
        ];
        $mz['display'] = [
            'short' => implode('+', array_map(fn($f) => substr($f, 0, 3), $allFixes)) . '-MRG',
            'long' => implode(' and ', $allFixes) . ' Convergence Zone'
        ];

        $addressedMergeZones[] = $mz;
    }

    return [
        'streams' => $addressedStreams,
        'merge_zones' => $addressedMergeZones
    ];
}

/**
 * Get stream name: use matched fix if available, otherwise cardinal direction
 */
function getStreamName(array $stream): string
{
    // Prefer matched fix name
    if (isset($stream['matched_fix']['id'])) {
        return strtoupper($stream['matched_fix']['id']);
    }

    // Fall back to cardinal direction
    $cardinal = $stream['approach_direction'] ?? 'X';
    return $cardinal;
}

/**
 * Extract fix names from a stream address
 */
function extractFixesFromAddress(string $address): array
{
    // Address format: "FIX/1" or "FIX1+FIX2/M1"
    $slashPos = strpos($address, '/');
    $fixPart = $slashPos !== false ? substr($address, 0, $slashPos) : $address;
    return explode('+', $fixPart);
}

/**
 * Group streams into distance bands (clusters at similar distances from fix)
 */
function groupIntoDistanceBands(array $streams, float $bandWidthNm): array
{
    if (empty($streams)) return [];

    // Sort by distance
    $sorted = array_values($streams);
    usort($sorted, function($a, $b) {
        return ($a['distance_to_fix_nm'] ?? PHP_INT_MAX) <=> ($b['distance_to_fix_nm'] ?? PHP_INT_MAX);
    });

    $bands = [];
    $currentBand = [];
    $bandStart = $sorted[0]['distance_to_fix_nm'] ?? 0;

    foreach ($sorted as $stream) {
        $dist = $stream['distance_to_fix_nm'] ?? 0;
        if ($dist - $bandStart > $bandWidthNm && !empty($currentBand)) {
            $bands[] = $currentBand;
            $currentBand = [$stream];
            $bandStart = $dist;
        } else {
            $currentBand[] = $stream;
        }
    }

    if (!empty($currentBand)) {
        $bands[] = $currentBand;
    }

    // Reverse so outermost (furthest) is first
    return array_reverse($bands);
}

/**
 * Find stream address by raw cluster ID
 */
function findStreamAddress(array $streams, int $rawClusterId): string
{
    foreach ($streams as $s) {
        if ($s['raw_cluster_id'] === $rawClusterId) {
            return $s['stream_id'];
        }
    }
    return 'X.0';
}

/**
 * Convert numeric index to Excel-style column letter(s)
 * 0 = A, 25 = Z, 26 = AA, 27 = AB, ... 701 = ZZ, 702 = AAA, ...
 *
 * @param int $index Zero-based index
 * @return string Letter(s) representing the stream
 */
function indexToStreamLetter(int $index): string
{
    $result = '';
    $index++; // Convert to 1-based for the algorithm

    while ($index > 0) {
        $index--; // Adjust for 0-indexed letters
        $result = chr(65 + ($index % 26)) . $result; // 65 = 'A'
        $index = intdiv($index, 26);
    }

    return $result;
}

/**
 * Detect merge zones where stream hulls intersect
 */
function detectMergeZones(PDO $gis, array $streamHulls): array
{
    if (count($streamHulls) < 2) {
        return [];
    }

    $mergeZones = [];
    $streamIds = array_keys($streamHulls);

    // Compare each pair of streams for intersection
    for ($i = 0; $i < count($streamIds); $i++) {
        for ($j = $i + 1; $j < count($streamIds); $j++) {
            $id1 = $streamIds[$i];
            $id2 = $streamIds[$j];

            $intersectQuery = $gis->prepare("
                SELECT
                    ST_Intersects(
                        ST_GeomFromGeoJSON(:hull1),
                        ST_GeomFromGeoJSON(:hull2)
                    ) as intersects,
                    ST_AsGeoJSON(ST_Intersection(
                        ST_GeomFromGeoJSON(:hull1),
                        ST_GeomFromGeoJSON(:hull2)
                    )) as intersection_geom,
                    ST_Area(ST_Intersection(
                        ST_GeomFromGeoJSON(:hull1)::geography,
                        ST_GeomFromGeoJSON(:hull2)::geography
                    )) / 1000000 as area_sq_km
            ");

            $intersectQuery->execute([
                ':hull1' => $streamHulls[$id1],
                ':hull2' => $streamHulls[$id2]
            ]);

            $result = $intersectQuery->fetch(PDO::FETCH_ASSOC);

            if ($result && $result['intersects'] === true && $result['intersection_geom']) {
                $mergeZones[] = [
                    'streams' => [$id1, $id2],
                    'geometry' => json_decode($result['intersection_geom'], true),
                    'area_sq_km' => round((float)$result['area_sq_km'], 2)
                ];
            }
        }
    }

    return $mergeZones;
}

/**
 * Add approach direction and distance info to each stream relative to fix point
 */
function addStreamDirections(PDO $gis, array $streams, array $fixPoint): array
{
    $fixLon = $fixPoint[0];
    $fixLat = $fixPoint[1];

    foreach ($streams as &$stream) {
        $centroid = $stream['centroid'];
        if ($centroid && isset($centroid['coordinates'])) {
            $cLon = $centroid['coordinates'][0];
            $cLat = $centroid['coordinates'][1];

            // Calculate bearing and distance from stream centroid to fix
            $query = $gis->prepare("
                SELECT
                    degrees(ST_Azimuth(
                        ST_SetSRID(ST_MakePoint(:c_lon, :c_lat), 4326),
                        ST_SetSRID(ST_MakePoint(:f_lon, :f_lat), 4326)
                    )) as bearing_to_fix,
                    ST_Distance(
                        ST_SetSRID(ST_MakePoint(:c_lon, :c_lat), 4326)::geography,
                        ST_SetSRID(ST_MakePoint(:f_lon, :f_lat), 4326)::geography
                    ) / 1852 as distance_nm
            ");
            $query->execute([
                ':c_lon' => $cLon,
                ':c_lat' => $cLat,
                ':f_lon' => $fixLon,
                ':f_lat' => $fixLat
            ]);
            $result = $query->fetch(PDO::FETCH_ASSOC);

            $stream['bearing_to_fix'] = round((float)$result['bearing_to_fix'], 1);
            $stream['approach_direction'] = bearingToCardinal((float)$result['bearing_to_fix']);
            $stream['distance_to_fix_nm'] = round((float)$result['distance_nm'], 1);
        }
    }

    return $streams;
}

/**
 * Convert bearing to cardinal direction
 */
function bearingToCardinal(float $bearing): string
{
    $directions = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];
    $index = (int)round($bearing / 45) % 8;
    return $directions[$index];
}

// ============================================================================
// BRANCH ANALYSIS (Multi-level pre-grouping + DBSCAN + Topology naming)
// ============================================================================

/**
 * Identify upstream traffic branches converging on a measurement point.
 *
 * Uses hybrid O/D + navigational-element + DBSCAN approach:
 *   1. Pre-group flights by origin/destination (TMI-type dependent)
 *   2. Within each O/D group, run DBSCAN spatial clustering
 *   3. Match clusters to known fixes for navigational element naming
 *   4. Assign type-prefixed topological names (orig:, dest:, thru:, fix:, awy:)
 *
 * @param array $trajectories  Flight trajectories [{callsign, coordinates}]
 * @param array $fixPoint      [lon, lat] of measurement point
 * @param float $mitDistanceNm Inner sampling bound (MIT requirement)
 * @param float $maxDistanceNm Outer sampling bound (typically 250nm)
 * @param array $flightMeta    Per-callsign metadata {callsign: {dept, dest, waypoints[]}}
 * @param string $tmiType      TMI type: arrival, departure, overflight
 * @param float $epsDeg        DBSCAN epsilon in degrees
 * @param int $minPoints       DBSCAN minimum points
 * @param array $knownFixes    Known fixes near measurement point [{id, lat, lon}]
 *
 * @return array Branch analysis results with flight_assignments map
 */
function calculateBranchAnalysis(
    PDO $gis, array $trajectories, ?array $fixPoint,
    float $mitDistanceNm, float $maxDistanceNm,
    array $flightMeta, string $tmiType,
    float $epsDeg, int $minPoints,
    array $knownFixes
): array {
    if (!$fixPoint || count($fixPoint) < 2) {
        return ['branches' => [], 'flight_assignments' => [], 'error' => 'fix_point required'];
    }

    $fixLon = (float)$fixPoint[0];
    $fixLat = (float)$fixPoint[1];
    $mitDistanceM = $mitDistanceNm * 1852;
    $maxDistanceM = $maxDistanceNm * 1852;

    // Step 1: Assign O/D group keys to each callsign
    $callsignGroups = [];
    foreach ($trajectories as $key => $traj) {
        $callsign = $traj['callsign'] ?? $key;
        $meta = $flightMeta[$callsign] ?? [];

        $dept = strtoupper(trim($meta['dept'] ?? 'UNK'));
        $dest = strtoupper(trim($meta['dest'] ?? 'UNK'));

        // Determine group key based on TMI type
        switch ($tmiType) {
            case 'departure':
                $odKey = "dest:$dest";
                break;
            case 'overflight':
                $odKey = ($dept !== 'UNK' && $dest !== 'UNK')
                    ? "thru:$dept>$dest"
                    : 'thru:UNK';
                break;
            case 'arrival':
            default:
                $odKey = "orig:$dept";
                break;
        }

        $callsignGroups[$callsign] = $odKey;
    }

    // Step 2: Create temp table with group assignments
    $gis->exec("DROP TABLE IF EXISTS temp_branch_groups");
    $gis->exec("CREATE TEMP TABLE temp_branch_groups (callsign VARCHAR(20), group_key VARCHAR(100))");

    $insertStmt = $gis->prepare(
        "INSERT INTO temp_branch_groups (callsign, group_key) VALUES (:cs, :gk)"
    );
    foreach ($callsignGroups as $cs => $gk) {
        $insertStmt->execute([':cs' => $cs, ':gk' => $gk]);
    }

    // Step 3: Create fix reference point (avoids duplicate param issue in main query)
    $gis->exec("DROP TABLE IF EXISTS temp_fix_ref");
    $fixRefStmt = $gis->prepare(
        "CREATE TEMP TABLE temp_fix_ref AS SELECT ST_SetSRID(ST_MakePoint(:lon, :lat), 4326) as geom"
    );
    $fixRefStmt->execute([':lon' => $fixLon, ':lat' => $fixLat]);

    // Step 4: Filter segments in range, join with groups, run DBSCAN per group
    $clusterStmt = $gis->prepare("
        WITH filtered_segments AS (
            SELECT
                s.callsign,
                s.seg_idx,
                s.geom,
                g.group_key,
                ST_Distance(s.geom::geography, f.geom::geography) / 1852 as dist_nm
            FROM temp_traj_segments s
            JOIN temp_branch_groups g ON s.callsign = g.callsign
            CROSS JOIN temp_fix_ref f
            WHERE ST_DWithin(s.geom::geography, f.geom::geography, :max_dist_m)
              AND ST_Distance(s.geom::geography, f.geom::geography) > :min_dist_m
        ),
        clustered AS (
            SELECT
                callsign, seg_idx, geom, group_key, dist_nm,
                ST_ClusterDBSCAN(geom, eps := :eps, minpoints := :minpts)
                    OVER (PARTITION BY group_key) as cluster_id
            FROM filtered_segments
        ),
        branch_stats AS (
            SELECT
                group_key,
                cluster_id,
                COUNT(DISTINCT callsign) as track_count,
                COUNT(*) as segment_count,
                ST_AsGeoJSON(ST_ConcaveHull(ST_Collect(geom), 0.3)) as hull_geom,
                ST_AsGeoJSON(ST_Centroid(ST_Collect(geom))) as centroid,
                string_agg(DISTINCT callsign, ',' ORDER BY callsign) as callsign_list,
                ST_AsGeoJSON(ST_Centroid(ST_Collect(geom))) as centroid_geom,
                degrees(ST_Azimuth(
                    ST_Centroid(ST_Collect(geom)),
                    (SELECT geom FROM temp_fix_ref)
                )) as bearing_to_fix,
                ST_Distance(
                    ST_Centroid(ST_Collect(geom))::geography,
                    (SELECT geom FROM temp_fix_ref)::geography
                ) / 1852 as centroid_dist_nm
            FROM clustered
            WHERE cluster_id IS NOT NULL
            GROUP BY group_key, cluster_id
            HAVING COUNT(DISTINCT callsign) >= 2
        )
        SELECT * FROM branch_stats ORDER BY track_count DESC
    ");

    $clusterStmt->execute([
        ':max_dist_m' => $maxDistanceM,
        ':min_dist_m' => $mitDistanceM,
        ':eps' => $epsDeg,
        ':minpts' => $minPoints,
    ]);

    // Step 5: Build branch metadata and match to known fixes
    $branches = [];
    $groupClusters = []; // group_key => [cluster entries]

    while ($row = $clusterStmt->fetch(PDO::FETCH_ASSOC)) {
        $groupKey = $row['group_key'];
        $clusterId = (int)$row['cluster_id'];
        $callsigns = explode(',', $row['callsign_list']);
        $centroid = json_decode($row['centroid'], true);
        $bearingToFix = round((float)($row['bearing_to_fix'] ?? 0), 1);
        $distNm = round((float)($row['centroid_dist_nm'] ?? 0), 1);

        $branch = [
            'group_key' => $groupKey,
            'cluster_id' => $clusterId,
            'callsigns' => $callsigns,
            'track_count' => (int)$row['track_count'],
            'segment_count' => (int)$row['segment_count'],
            'hull' => json_decode($row['hull_geom'], true),
            'centroid' => $centroid,
            'bearing_to_fix' => $bearingToFix,
            'approach_direction' => bearingToCardinal($bearingToFix),
            'distance_to_fix_nm' => $distNm,
            'matched_fix' => null,
        ];

        // Match cluster centroid to known fixes
        if (!empty($knownFixes) && $centroid && isset($centroid['coordinates'])) {
            $cLon = $centroid['coordinates'][0];
            $cLat = $centroid['coordinates'][1];
            $bestFix = null;
            $bestDist = 30; // Max match distance in nm

            foreach ($knownFixes as $fix) {
                $fId = $fix['id'] ?? $fix['name'] ?? 'UNKNOWN';
                $fLat = $fix['lat'] ?? null;
                $fLon = $fix['lon'] ?? $fix['lng'] ?? null;
                if (!is_numeric($fLat) || !is_numeric($fLon)) continue;

                $distQuery = $gis->prepare("
                    SELECT ST_Distance(
                        ST_SetSRID(ST_MakePoint(:c_lon, :c_lat), 4326)::geography,
                        ST_SetSRID(ST_MakePoint(:f_lon, :f_lat), 4326)::geography
                    ) / 1852 as dist_nm
                ");
                $distQuery->execute([
                    ':c_lon' => $cLon, ':c_lat' => $cLat,
                    ':f_lon' => $fLon, ':f_lat' => $fLat,
                ]);
                $d = (float)$distQuery->fetch(PDO::FETCH_ASSOC)['dist_nm'];

                if ($d < $bestDist) {
                    $bestDist = $d;
                    $bestFix = ['id' => $fId, 'distance_nm' => round($d, 1)];
                }
            }
            $branch['matched_fix'] = $bestFix;
        }

        if (!isset($groupClusters[$groupKey])) {
            $groupClusters[$groupKey] = [];
        }
        $groupClusters[$groupKey][] = $branch;
        $branches[] = $branch;
    }

    // Step 6: Assign type-prefixed topological names
    $flightAssignments = []; // callsign => branch_id
    $namedBranches = [];

    foreach ($groupClusters as $groupKey => $clusters) {
        // Sort clusters within group by distance (farthest first for consistent naming)
        usort($clusters, function ($a, $b) {
            return $b['distance_to_fix_nm'] <=> $a['distance_to_fix_nm'];
        });

        foreach ($clusters as $position => $branch) {
            // Build branch_id: group_key/nav_element/position
            $navPart = '';
            if ($branch['matched_fix']) {
                $navPart = '/fix:' . strtoupper($branch['matched_fix']['id']);
            } elseif ($branch['approach_direction']) {
                $navPart = '/' . $branch['approach_direction'];
            }

            $positionNum = $position + 1;
            $branchId = $groupKey . $navPart . '/' . $positionNum;

            $branch['branch_id'] = $branchId;
            $branch['display'] = [
                'short' => buildBranchShortName($groupKey, $branch['matched_fix'], $positionNum),
                'long' => buildBranchLongName($groupKey, $branch['matched_fix'], $positionNum, $branch['track_count']),
            ];

            // Assign callsigns to this branch
            foreach ($branch['callsigns'] as $cs) {
                $flightAssignments[$cs] = $branchId;
            }

            $namedBranches[] = $branch;
        }
    }

    // Step 7: Identify ungrouped flights
    $allCallsigns = [];
    foreach ($trajectories as $key => $traj) {
        $allCallsigns[] = $traj['callsign'] ?? $key;
    }
    $ungroupedCount = count(array_diff($allCallsigns, array_keys($flightAssignments)));

    // Cleanup temp tables
    $gis->exec("DROP TABLE IF EXISTS temp_branch_groups");
    $gis->exec("DROP TABLE IF EXISTS temp_fix_ref");

    return [
        'branches' => $namedBranches,
        'flight_assignments' => $flightAssignments,
        'total_flights' => count($allCallsigns),
        'branch_count' => count($namedBranches),
        'ungrouped_flights' => $ungroupedCount,
        'params' => [
            'mit_distance_nm' => $mitDistanceNm,
            'max_distance_nm' => $maxDistanceNm,
            'tmi_type' => $tmiType,
            'eps_nm' => $epsDeg * 60,
            'min_points' => $minPoints,
        ],
    ];
}

/**
 * Build short display name for a branch (e.g., "ATL-CAMRN-1")
 */
function buildBranchShortName(string $groupKey, ?array $matchedFix, int $position): string
{
    // Extract the identifier from group key (e.g., "orig:KATL" → "KATL")
    $parts = explode(':', $groupKey, 2);
    $id = $parts[1] ?? $parts[0];
    // For directional thru groups, keep the arrow
    $id = str_replace('>', '→', $id);

    $fixName = $matchedFix ? $matchedFix['id'] : '';
    if ($fixName) {
        return strtoupper($id) . '-' . strtoupper($fixName) . '-' . $position;
    }
    return strtoupper($id) . '-' . $position;
}

/**
 * Build long display name for a branch (e.g., "ATL departures via CAMRN, Stream 1 (5 flights)")
 */
function buildBranchLongName(string $groupKey, ?array $matchedFix, int $position, int $trackCount): string
{
    $parts = explode(':', $groupKey, 2);
    $type = $parts[0] ?? '';
    $id = $parts[1] ?? $parts[0];

    $typeLabel = match ($type) {
        'orig' => "$id departures",
        'dest' => "arrivals to $id",
        'thru' => str_replace('>', ' → ', $id) . ' transit',
        default => $id,
    };

    $fixName = $matchedFix ? $matchedFix['id'] : '';
    $viaPart = $fixName ? " via $fixName" : '';

    return "$typeLabel$viaPart, Stream $position ($trackCount flights)";
}

// ============================================================================
// FLOW ENVELOPE (Concave Hull)
// ============================================================================

function calculateFlowEnvelope(PDO $gis, float $shrinkFactor, ?array $fixPoint): array
{
    $hullQuery = $gis->prepare("
        SELECT
            ST_AsGeoJSON(ST_ConcaveHull(ST_Collect(geom), :shrink_factor)) as hull_geom,
            ST_AsGeoJSON(ST_ConvexHull(ST_Collect(geom))) as convex_geom,
            ST_AsGeoJSON(ST_Centroid(ST_Collect(geom))) as centroid,
            COUNT(DISTINCT callsign) as track_count,
            COUNT(*) as point_count
        FROM temp_traj_points
    ");
    $hullQuery->execute([':shrink_factor' => $shrinkFactor]);
    $result = $hullQuery->fetch(PDO::FETCH_ASSOC);

    if (!$result || !$result['hull_geom']) {
        throw new Exception('Failed to compute flow envelope');
    }

    $envelope = [
        'concave_hull' => json_decode($result['hull_geom'], true),
        'convex_hull' => json_decode($result['convex_geom'], true),
        'centroid' => json_decode($result['centroid'], true),
        'track_count' => (int)$result['track_count'],
        'point_count' => (int)$result['point_count'],
        'shrink_factor' => $shrinkFactor
    ];

    return $envelope;
}

// ============================================================================
// SEGMENT DENSITY (Per-segment proximity)
// ============================================================================

function calculateSegmentDensity(PDO $gis, array $trajectories, float $proximityDeg, float $proximityNm): array
{
    $proximityMeters = $proximityNm * 1852;

    $densityQuery = $gis->prepare("
        SELECT
            s1.callsign,
            s1.seg_idx,
            COUNT(DISTINCT s2.callsign) as nearby_flights,
            ST_X(ST_StartPoint(s1.geom)) as start_lon,
            ST_Y(ST_StartPoint(s1.geom)) as start_lat,
            ST_X(ST_EndPoint(s1.geom)) as end_lon,
            ST_Y(ST_EndPoint(s1.geom)) as end_lat
        FROM temp_traj_segments s1
        LEFT JOIN temp_traj_segments s2 ON
            s1.callsign != s2.callsign AND
            ST_DWithin(s1.geom::geography, s2.geom::geography, :proximity_m)
        GROUP BY s1.callsign, s1.seg_idx, s1.geom
        ORDER BY s1.callsign, s1.seg_idx
    ");

    $densityQuery->execute([':proximity_m' => $proximityMeters]);

    $segmentDensities = [];
    $maxDensity = 1;

    while ($row = $densityQuery->fetch(PDO::FETCH_ASSOC)) {
        $cs = $row['callsign'];
        $segIdx = (int)$row['seg_idx'];
        $density = (int)$row['nearby_flights'];

        if (!isset($segmentDensities[$cs])) {
            $segmentDensities[$cs] = [];
        }
        $segmentDensities[$cs][$segIdx] = $density;

        if ($density > $maxDensity) {
            $maxDensity = $density;
        }
    }

    $enrichedTrajectories = [];

    foreach ($trajectories as $key => $traj) {
        // Handle both array format and associative format
        $callsign = isset($traj['callsign']) ? $traj['callsign'] : $key;

        $enrichedTraj = $traj;
        $densities = [];

        if (isset($traj['coordinates']) && is_array($traj['coordinates'])) {
            $coordCount = count($traj['coordinates']);
            for ($i = 0; $i < $coordCount; $i++) {
                $segIdx = ($i < $coordCount - 1) ? $i : $i - 1;
                $rawDensity = $segmentDensities[$callsign][$segIdx] ?? 0;
                $densities[] = $rawDensity / $maxDensity;
            }
        }

        $enrichedTraj['densities'] = $densities;
        $enrichedTraj['raw_densities'] = array_values($segmentDensities[$callsign] ?? []);
        $enrichedTraj['avg_density'] = count($densities) > 0
            ? array_sum($densities) / count($densities)
            : 0;

        $enrichedTrajectories[$callsign] = $enrichedTraj;
    }

    return [
        'trajectories' => $enrichedTrajectories,
        'max_density' => $maxDensity,
        'proximity_nm' => $proximityNm,
        'method' => 'segment_proximity'
    ];
}

// ============================================================================
// LEGACY GRID DENSITY
// ============================================================================

function calculateGridDensity(PDO $gis, array $trajectories, float $gridSize): array
{
    $bbox = $gis->query("
        SELECT
            ST_XMin(ST_Extent(geom)) as min_lon,
            ST_YMin(ST_Extent(geom)) as min_lat,
            ST_XMax(ST_Extent(geom)) as max_lon,
            ST_YMax(ST_Extent(geom)) as max_lat
        FROM temp_traj_points
    ")->fetch(PDO::FETCH_ASSOC);

    if (!$bbox['min_lon']) {
        throw new Exception('No valid trajectory points');
    }

    $gridSizeParam = floatval($gridSize);

    $densityQuery = $gis->prepare("
        WITH grid AS (
            SELECT (ST_HexagonGrid(:grid_size, ST_MakeEnvelope(:min_lon, :min_lat, :max_lon, :max_lat, 4326))).*
        ),
        cell_counts AS (
            SELECT
                g.i, g.j,
                g.geom as cell_geom,
                COUNT(DISTINCT t.callsign) as flight_count
            FROM grid g
            LEFT JOIN temp_traj_points t ON ST_Intersects(g.geom, t.geom)
            GROUP BY g.i, g.j, g.geom
            HAVING COUNT(DISTINCT t.callsign) > 0
        )
        SELECT
            i, j,
            ST_X(ST_Centroid(cell_geom)) as center_lon,
            ST_Y(ST_Centroid(cell_geom)) as center_lat,
            flight_count
        FROM cell_counts
    ");

    $densityQuery->execute([
        ':grid_size' => $gridSizeParam,
        ':min_lon' => $bbox['min_lon'] - $gridSizeParam,
        ':min_lat' => $bbox['min_lat'] - $gridSizeParam,
        ':max_lon' => $bbox['max_lon'] + $gridSizeParam,
        ':max_lat' => $bbox['max_lat'] + $gridSizeParam
    ]);

    $densityGrid = [];
    $maxDensity = 1;

    while ($row = $densityQuery->fetch(PDO::FETCH_ASSOC)) {
        $key = $row['i'] . ',' . $row['j'];
        $count = (int)$row['flight_count'];
        $densityGrid[$key] = [
            'count' => $count,
            'lon' => (float)$row['center_lon'],
            'lat' => (float)$row['center_lat']
        ];
        if ($count > $maxDensity) {
            $maxDensity = $count;
        }
    }

    $pointDensityQuery = $gis->prepare("
        WITH grid AS (
            SELECT (ST_HexagonGrid(:grid_size, ST_MakeEnvelope(:min_lon, :min_lat, :max_lon, :max_lat, 4326))).*
        ),
        cell_counts AS (
            SELECT
                g.i, g.j,
                g.geom as cell_geom,
                COUNT(DISTINCT t.callsign) as flight_count
            FROM grid g
            JOIN temp_traj_points t ON ST_Intersects(g.geom, t.geom)
            GROUP BY g.i, g.j, g.geom
        )
        SELECT
            t.callsign,
            ST_X(t.geom) as lon,
            ST_Y(t.geom) as lat,
            COALESCE(c.flight_count, 0) as density
        FROM temp_traj_points t
        LEFT JOIN grid g ON ST_Intersects(g.geom, t.geom)
        LEFT JOIN cell_counts c ON g.i = c.i AND g.j = c.j
        ORDER BY t.callsign
    ");

    $pointDensityQuery->execute([
        ':grid_size' => $gridSizeParam,
        ':min_lon' => $bbox['min_lon'] - $gridSizeParam,
        ':min_lat' => $bbox['min_lat'] - $gridSizeParam,
        ':max_lon' => $bbox['max_lon'] + $gridSizeParam,
        ':max_lat' => $bbox['max_lat'] + $gridSizeParam
    ]);

    $pointDensities = [];
    while ($row = $pointDensityQuery->fetch(PDO::FETCH_ASSOC)) {
        $cs = $row['callsign'];
        if (!isset($pointDensities[$cs])) {
            $pointDensities[$cs] = [];
        }
        $posKey = round($row['lon'], 3) . ',' . round($row['lat'], 3);
        $pointDensities[$cs][$posKey] = (int)$row['density'];
    }

    $enrichedTrajectories = [];
    foreach ($trajectories as $key => $traj) {
        // Handle both array format and associative format
        $callsign = isset($traj['callsign']) ? $traj['callsign'] : $key;

        $enrichedTraj = $traj;
        $densities = [];

        if (isset($traj['coordinates']) && is_array($traj['coordinates'])) {
            foreach ($traj['coordinates'] as $coord) {
                // Validate coordinate point
                if (!is_array($coord) || count($coord) < 2 ||
                    !is_numeric($coord[0]) || !is_numeric($coord[1])) {
                    $densities[] = 0;
                    continue;
                }
                $posKey = round($coord[0], 3) . ',' . round($coord[1], 3);
                $density = $pointDensities[$callsign][$posKey] ?? 0;
                $densities[] = $density / $maxDensity;
            }
        }

        $enrichedTraj['densities'] = $densities;
        $enrichedTraj['avg_density'] = count($densities) > 0
            ? array_sum($densities) / count($densities)
            : 0;

        $enrichedTrajectories[$callsign] = $enrichedTraj;
    }

    return [
        'trajectories' => $enrichedTrajectories,
        'max_density' => $maxDensity,
        'grid_size' => $gridSizeParam,
        'cell_count' => count($densityGrid),
        'method' => 'grid'
    ];
}
