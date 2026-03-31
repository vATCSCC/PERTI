<?php
/**
 * RouteQueryService — Unified route query across playbook, CDR, and historical sources.
 *
 * Orchestrates: facility token resolution → source queries → filter evaluation →
 * TMI annotation → merge/dedup → ranking → enrichment → response assembly.
 *
 * @see docs/superpowers/specs/2026-03-30-vatswim-route-query-api-design.md
 */

namespace PERTI\Services;

require_once __DIR__ . '/RouteFilterParser.php';

class RouteQueryService
{
    private $connSwim;   // sqlsrv — SWIM_API database
    private $connTmi;    // sqlsrv — VATSIM_TMI database
    private $connPdo;    // PDO — MySQL perti_site (for TRACON→airport expansion)

    // Facility token type constants
    private const FACILITY_AIRPORT = 'airport';
    private const FACILITY_ARTCC   = 'artcc';
    private const FACILITY_TRACON  = 'tracon';

    public function __construct($connSwim, $connTmi = null, $connPdo = null)
    {
        $this->connSwim = $connSwim;
        $this->connTmi = $connTmi;
        $this->connPdo = $connPdo;
    }

    /**
     * Execute a route query.
     *
     * @param array $request Validated request body
     * @return array Response data (results, summary, warnings)
     */
    public function query(array $request): array
    {
        $startTime = microtime(true);
        $warnings = [];

        $origins = $this->normalizeTokens($request['origin'] ?? null);
        $destinations = $this->normalizeTokens($request['destination'] ?? null);
        $sources = $request['sources'] ?? ['playbook', 'cdr', 'historical'];
        $filterExpr = $request['filter'] ?? null;
        $filters = $request['filters'] ?? [];
        $context = $request['context'] ?? [];
        $includes = $request['include'] ?? [];
        $sort = $request['sort'] ?? 'score';
        $limit = $request['limit'] ?? 20;
        $offset = $request['offset'] ?? 0;

        // Parse filter expression if provided
        $filterAST = null;
        if ($filterExpr !== null && $filterExpr !== '') {
            $parser = new RouteFilterParser();
            $parsed = $parser->parse($filterExpr);
            if ($parsed['error'] !== null) {
                return ['error' => 'Filter parse error: ' . $parsed['error']['message'], 'http_code' => 400];
            }
            $filterAST = $parsed['ast'];
        }

        // Classify facility tokens
        $originTokens = array_map([$this, 'classifyToken'], $origins);
        $destTokens = array_map([$this, 'classifyToken'], $destinations);

        // Query each source
        $allResults = [];
        $sourceCounts = [];

        if (in_array('playbook', $sources, true)) {
            $pbResults = $this->queryPlaybook($originTokens, $destTokens, $filters, $filterAST);
            $sourceCounts['playbook'] = count($pbResults);
            $allResults = array_merge($allResults, $pbResults);
        }

        if (in_array('cdr', $sources, true)) {
            $cdrResults = $this->queryCDR($originTokens, $destTokens, $filters);
            $sourceCounts['cdr'] = count($cdrResults);
            $allResults = array_merge($allResults, $cdrResults);
        }

        if (in_array('historical', $sources, true)) {
            $histResults = $this->queryHistorical($originTokens, $destTokens);
            $sourceCounts['historical'] = count($histResults);
            $allResults = array_merge($allResults, $histResults);
        }

        // Apply filter expression to CDR/historical results (playbook already filtered)
        if ($filterAST !== null) {
            $allResults = $this->applyFilterAST($allResults, $filterAST);
        }

        // TMI annotation
        $tmiFlags = [];
        if (!empty($context['include_active_tmis'])) {
            $tmiFlags = $this->fetchActiveTMIs($originTokens, $destTokens, $context);
            if ($tmiFlags === null) {
                $warnings[] = 'tmi_data_unavailable';
                $tmiFlags = [];
            }
        }

        // Merge and deduplicate
        $merged = $this->mergeAndDedup($allResults);

        // Attach TMI flags
        if (!empty($tmiFlags)) {
            $merged = $this->attachTMIFlags($merged, $tmiFlags);
        }

        // Rank
        $ranked = $this->rank($merged, $sort, !empty($context['include_active_tmis']));

        // Paginate
        $totalResults = count($ranked);
        $paged = array_slice($ranked, $offset, $limit);

        // Enrich (geometry, traversal, statistics)
        if (!empty($includes)) {
            $paged = $this->enrich($paged, $includes, $warnings);
        }

        // Assign ranks
        foreach ($paged as $i => &$row) {
            $row['rank'] = $offset + $i + 1;
        }
        unset($row);

        $queryTimeMs = (int)round((microtime(true) - $startTime) * 1000);

        return [
            'query' => [
                'origin' => $request['origin'] ?? null,
                'destination' => $request['destination'] ?? null,
                'filter' => $filterExpr,
                'sources_queried' => $sources,
            ],
            'results' => array_values($paged),
            'summary' => [
                'total_results' => $totalResults,
                'returned' => count($paged),
                'offset' => $offset,
                'sources_hit' => $sourceCounts,
                'active_tmis' => count($tmiFlags),
                'query_time_ms' => $queryTimeMs,
            ],
            'warnings' => $warnings,
        ];
    }

    // =========================================================================
    // FACILITY TOKEN RESOLUTION
    // =========================================================================

    private function normalizeTokens($value): array
    {
        if ($value === null) return [];
        if (is_string($value)) return [strtoupper(trim($value))];
        if (is_array($value)) return array_map(fn($v) => strtoupper(trim($v)), $value);
        return [];
    }

    /**
     * Classify a facility token as airport, ARTCC, or TRACON.
     * @return array ['code' => string, 'type' => string]
     */
    private function classifyToken(string $code): array
    {
        $len = strlen($code);

        // 4-char = airport ICAO
        if ($len === 4) {
            return ['code' => $code, 'type' => self::FACILITY_AIRPORT];
        }

        // 3-char starting with Z = ARTCC
        if ($len === 3 && $code[0] === 'Z') {
            return ['code' => $code, 'type' => self::FACILITY_ARTCC];
        }

        // 3-char = could be TRACON or FAA LID airport
        // TRACONs: N90, PCT, SCT, A80, C90, D10, I90, L30, P50, etc.
        // FAA LIDs: JFK, LAX, ORD, etc.
        // Heuristic: codes with digits are usually TRACONs
        if ($len === 3) {
            if (preg_match('/\d/', $code)) {
                return ['code' => $code, 'type' => self::FACILITY_TRACON];
            }
            // 3-letter alpha = FAA LID airport, resolve to ICAO
            return ['code' => $code, 'type' => self::FACILITY_AIRPORT];
        }

        // 2-char = likely ARTCC or FIR prefix, treat as ARTCC
        if ($len === 2) {
            return ['code' => $code, 'type' => self::FACILITY_ARTCC];
        }

        // Default: treat as airport
        return ['code' => $code, 'type' => self::FACILITY_AIRPORT];
    }

    // =========================================================================
    // SOURCE QUERIES
    // =========================================================================

    private function queryPlaybook(array $originTokens, array $destTokens, array $filters, ?array $filterAST): array
    {
        // Build SQL with text filter in WHERE (for performance)
        $where = ["p.status = 'active'", "p.visibility = 'public'"];
        $params = [];

        $textFilter = trim($filters['text'] ?? '');
        if ($textFilter !== '') {
            $where[] = "(r.route_string LIKE '%' + ? + '%' OR p.play_name LIKE '%' + ? + '%' OR r.remarks LIKE '%' + ? + '%')";
            $escapedText = $this->escapeLike($textFilter);
            $params[] = $escapedText;
            $params[] = $escapedText;
            $params[] = $escapedText;
        }

        $sql = "
            SELECT r.route_id, r.route_string, r.origin, r.dest,
                   r.origin_airports, r.dest_airports,
                   r.origin_artccs, r.dest_artccs, r.origin_tracons, r.dest_tracons,
                   r.traversed_artccs, r.traversed_tracons,
                   r.route_geometry, r.remarks, r.sort_order,
                   p.play_id, p.play_name, p.display_name, p.category, p.source AS play_source
            FROM dbo.swim_playbook_routes r
            JOIN dbo.swim_playbook_plays p ON r.play_id = p.play_id
            WHERE " . implode(' AND ', $where);

        $stmt = sqlsrv_query($this->connSwim, $sql, $params);
        if ($stmt === false) return [];

        $results = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            // Apply origin/dest token filtering in PHP (word-boundary CSV matching)
            if (!empty($originTokens) && !$this->matchesTokens($row, $originTokens, 'origin')) continue;
            if (!empty($destTokens) && !$this->matchesTokens($row, $destTokens, 'dest')) continue;

            // Apply filter AST evaluation
            if ($filterAST !== null) {
                $index = $this->buildRouteIndex($row);
                if (!RouteFilterParser::evaluate($filterAST, $index)) continue;
            }

            $results[] = [
                'source' => 'playbook',
                'route_string' => $this->normalizeRouteString($row['route_string']),
                '_raw_route' => $row['route_string'],
                'metadata' => [
                    'play_name' => $row['play_name'],
                    'play_id' => (int)$row['play_id'],
                    'display_name' => $row['display_name'],
                    'category' => $row['category'],
                    'cdr_code' => null,
                    'distance_nm' => $this->extractDistanceFromGeometry($row['route_geometry']),
                    'direction' => null,
                ],
                'statistics' => null,
                'tmi_flags' => [],
                'traversal' => $this->parseCSVTraversal($row),
                '_route_geometry_json' => $row['route_geometry'],
                '_traversed_artccs' => $this->parseCSV($row['traversed_artccs'] ?? ''),
            ];
        }
        sqlsrv_free_stmt($stmt);

        return $results;
    }

    private function queryCDR(array $originTokens, array $destTokens, array $filters): array
    {
        $where = ['is_active = 1'];
        $params = [];

        // Build origin WHERE from tokens
        $originWhere = $this->buildCDRTokenWhere($originTokens, 'origin', $params);
        if ($originWhere) $where[] = $originWhere;

        $destWhere = $this->buildCDRTokenWhere($destTokens, 'dest', $params);
        if ($destWhere) $where[] = $destWhere;

        // Direction filter
        if (!empty($filters['direction'])) {
            $where[] = 'direction = ?';
            $params[] = strtoupper(trim($filters['direction']));
        }

        // Altitude filters
        if (!empty($filters['altitude_min'])) {
            $where[] = '(altitude_max_ft IS NULL OR altitude_max_ft >= ?)';
            $params[] = (int)$filters['altitude_min'];
        }
        if (!empty($filters['altitude_max'])) {
            $where[] = '(altitude_min_ft IS NULL OR altitude_min_ft <= ?)';
            $params[] = (int)$filters['altitude_max'];
        }

        // Text filter
        $textFilter = trim($filters['text'] ?? '');
        if ($textFilter !== '') {
            $where[] = "(cdr_code LIKE '%' + ? + '%' OR full_route LIKE '%' + ? + '%')";
            $escapedText = $this->escapeLike($textFilter);
            $params[] = $escapedText;
            $params[] = $escapedText;
        }

        $sql = "
            SELECT cdr_id, cdr_code, full_route, origin_icao, dest_icao,
                   dep_artcc, arr_artcc, direction, altitude_min_ft, altitude_max_ft
            FROM dbo.swim_coded_departure_routes
            WHERE " . implode(' AND ', $where);

        $stmt = sqlsrv_query($this->connSwim, $sql, $params);
        if ($stmt === false) return [];

        $results = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $results[] = [
                'source' => 'cdr',
                'route_string' => $this->normalizeRouteString($row['full_route']),
                '_raw_route' => $row['full_route'],
                'metadata' => [
                    'play_name' => null,
                    'play_id' => null,
                    'cdr_code' => $row['cdr_code'],
                    'distance_nm' => null,
                    'direction' => $row['direction'],
                ],
                'statistics' => null,
                'tmi_flags' => [],
                'traversal' => null,
                '_route_geometry_json' => null,
                '_traversed_artccs' => array_filter([$row['dep_artcc'], $row['arr_artcc']]),
            ];
        }
        sqlsrv_free_stmt($stmt);

        return $results;
    }

    private function queryHistorical(array $originTokens, array $destTokens): array
    {
        // Expand tokens to ICAO airport lists for the IN clause
        $originAirports = $this->expandTokensToAirports($originTokens);
        $destAirports = $this->expandTokensToAirports($destTokens);

        if (empty($originAirports) && empty($destAirports)) return [];

        $where = [];
        $params = [];

        if (!empty($originAirports)) {
            $placeholders = implode(',', array_fill(0, count($originAirports), '?'));
            $where[] = "origin_icao IN ($placeholders)";
            $params = array_merge($params, $originAirports);
        }

        if (!empty($destAirports)) {
            $placeholders = implode(',', array_fill(0, count($destAirports), '?'));
            $where[] = "dest_icao IN ($placeholders)";
            $params = array_merge($params, $destAirports);
        }

        $sql = "
            SELECT origin_icao, dest_icao, normalized_route, route_hash,
                   flight_count, usage_pct, avg_altitude_ft,
                   common_aircraft, common_operators, first_seen, last_seen
            FROM dbo.swim_route_stats
            WHERE " . implode(' AND ', $where) . "
            ORDER BY flight_count DESC
        ";

        $stmt = sqlsrv_query($this->connSwim, $sql, $params);
        if ($stmt === false) return [];

        $results = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $firstSeen = $row['first_seen'] instanceof \DateTime ? $row['first_seen']->format('Y-m-d') : (string)$row['first_seen'];
            $lastSeen = $row['last_seen'] instanceof \DateTime ? $row['last_seen']->format('Y-m-d') : (string)$row['last_seen'];

            $results[] = [
                'source' => 'historical',
                'route_string' => $this->normalizeRouteString($row['normalized_route']),
                '_raw_route' => $row['normalized_route'],
                'metadata' => [
                    'play_name' => null,
                    'play_id' => null,
                    'cdr_code' => null,
                    'distance_nm' => null,
                    'direction' => null,
                ],
                'statistics' => [
                    'flight_count' => (int)$row['flight_count'],
                    'usage_pct' => round((float)$row['usage_pct'], 1),
                    'avg_altitude_ft' => $row['avg_altitude_ft'] !== null ? (int)$row['avg_altitude_ft'] : null,
                    'common_aircraft' => $row['common_aircraft'] ? explode(',', $row['common_aircraft']) : [],
                    'common_operators' => $row['common_operators'] ? explode(',', $row['common_operators']) : [],
                    'first_seen' => $firstSeen,
                    'last_seen' => $lastSeen,
                ],
                'tmi_flags' => [],
                'traversal' => null,
                '_route_geometry_json' => null,
                '_traversed_artccs' => [],
            ];
        }
        sqlsrv_free_stmt($stmt);

        return $results;
    }

    // =========================================================================
    // TMI ANNOTATION
    // =========================================================================

    private function fetchActiveTMIs(array $originTokens, array $destTokens, array $context): ?array
    {
        if (!$this->connTmi) return null;

        // Collect airports and ARTCCs from tokens
        $airports = [];
        $artccs = [];
        foreach (array_merge($originTokens, $destTokens) as $token) {
            if ($token['type'] === self::FACILITY_AIRPORT) $airports[] = $token['code'];
            elseif ($token['type'] === self::FACILITY_ARTCC) $artccs[] = $token['code'];
        }

        if (empty($airports) && empty($artccs)) return [];

        $where = ["status IN ('ACTIVE', 'PROPOSED', 'PENDING_COORD')"];
        $params = [];

        $orClauses = [];
        if (!empty($airports)) {
            $placeholders = implode(',', array_fill(0, count($airports), '?'));
            $orClauses[] = "ctl_element IN ($placeholders)";
            $params = array_merge($params, $airports);
        }
        // scope_json LIKE matching for ARTCCs
        foreach ($artccs as $artcc) {
            $orClauses[] = "scope_json LIKE ?";
            $params[] = '%' . $this->escapeLike($artcc) . '%';
        }

        if (!empty($orClauses)) {
            $where[] = '(' . implode(' OR ', $orClauses) . ')';
        }

        // Time window
        if (!empty($context['departure_time_utc'])) {
            $where[] = "(end_utc IS NULL OR end_utc >= ?)";
            $params[] = $context['departure_time_utc'];
        }

        $sql = "
            SELECT program_id, program_type, ctl_element, status,
                   program_rate, scope_json, start_utc, end_utc
            FROM dbo.tmi_programs
            WHERE " . implode(' AND ', $where);

        $stmt = sqlsrv_query($this->connTmi, $sql, $params);
        if ($stmt === false) return null;

        $tmis = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $type = $row['program_type'];
            // Simplify program type for consumers
            $simpleType = str_starts_with($type, 'GDP') ? 'GDP' : $type;

            $impact = 'flow_restriction';
            if ($simpleType === 'GS') $impact = 'ground_stop';
            elseif ($simpleType === 'GDP') $impact = 'arrival_delay';
            elseif ($simpleType === 'AFP') $impact = 'flow_restriction';

            $tmis[] = [
                'type' => $simpleType,
                'airport' => $row['ctl_element'],
                'program_id' => (int)$row['program_id'],
                'aar' => $row['program_rate'] !== null ? (int)$row['program_rate'] : null,
                'status' => strtolower($row['status']),
                'impact' => $impact,
                '_scope_artccs' => $row['scope_json'] ? (json_decode($row['scope_json'], true) ?: []) : [],
            ];
        }
        sqlsrv_free_stmt($stmt);

        return $tmis;
    }

    private function attachTMIFlags(array $results, array $tmis): array
    {
        foreach ($results as &$result) {
            $flags = [];
            $routeArtccs = $result['_traversed_artccs'] ?? [];

            foreach ($tmis as $tmi) {
                $hit = false;
                // Check if TMI airport matches route origin/dest
                // (route_string doesn't contain airports, so check metadata)
                if ($tmi['airport']) $hit = true; // TMI at a relevant airport (already filtered by query tokens)

                // Check ARTCC scope overlap
                if (!$hit && !empty($tmi['_scope_artccs']) && !empty($routeArtccs)) {
                    $hit = !empty(array_intersect($tmi['_scope_artccs'], $routeArtccs));
                }

                if ($hit) {
                    $flags[] = [
                        'type' => $tmi['type'],
                        'airport' => $tmi['airport'],
                        'program_id' => $tmi['program_id'],
                        'aar' => $tmi['aar'],
                        'status' => $tmi['status'],
                        'impact' => $tmi['impact'],
                    ];
                }
            }

            $result['tmi_flags'] = $flags;
        }
        unset($result);
        return $results;
    }

    // =========================================================================
    // MERGE, DEDUP, RANK
    // =========================================================================

    private function mergeAndDedup(array $results): array
    {
        $byRoute = [];
        foreach ($results as $result) {
            $key = $result['route_string'];
            if (!isset($byRoute[$key])) {
                $byRoute[$key] = $result;
                $byRoute[$key]['also_in'] = [];
            } else {
                // Merge: keep the one with more metadata, record other source
                $existing = &$byRoute[$key];
                $existing['also_in'][] = $result['source'];

                // Merge metadata from other sources
                if ($result['source'] === 'playbook' && $existing['source'] !== 'playbook') {
                    // Playbook wins as primary
                    $result['also_in'] = array_merge([$existing['source']], $existing['also_in']);
                    $result['statistics'] = $existing['statistics'] ?? $result['statistics'];
                    $result['tmi_flags'] = array_merge($existing['tmi_flags'], $result['tmi_flags']);
                    $result['_traversed_artccs'] = !empty($result['_traversed_artccs']) ? $result['_traversed_artccs'] : $existing['_traversed_artccs'];
                    $byRoute[$key] = $result;
                } else {
                    // Merge statistics from historical onto existing
                    if ($result['statistics'] !== null && $existing['statistics'] === null) {
                        $existing['statistics'] = $result['statistics'];
                    }
                    // Merge CDR code
                    if (!empty($result['metadata']['cdr_code']) && empty($existing['metadata']['cdr_code'])) {
                        $existing['metadata']['cdr_code'] = $result['metadata']['cdr_code'];
                    }
                }
                unset($existing);
            }
        }

        return array_values($byRoute);
    }

    private function rank(array $results, string $sortMode, bool $tmiActive): array
    {
        // Find max flight count for normalization
        $maxCount = 1;
        foreach ($results as $r) {
            $count = $r['statistics']['flight_count'] ?? 0;
            if ($count > $maxCount) $maxCount = $count;
        }

        // Score each result
        foreach ($results as &$r) {
            $score = 0.0;

            // Historical popularity (0-50)
            $flightCount = $r['statistics']['flight_count'] ?? 0;
            $score += min(50, ($flightCount / $maxCount) * 50);

            // Source authority (0-20)
            if ($r['source'] === 'playbook') $score += 20;
            elseif ($r['source'] === 'cdr') $score += 15;
            else $score += 10;

            // Recency (0-15)
            $lastSeen = $r['statistics']['last_seen'] ?? null;
            if ($lastSeen) {
                $daysSince = (time() - strtotime($lastSeen)) / 86400;
                if ($daysSince <= 7) $score += 15;
                elseif ($daysSince <= 30) $score += 10;
                elseif ($daysSince <= 90) $score += 5;
            }

            // TMI compliance (0-15)
            if ($tmiActive) {
                $score += empty($r['tmi_flags']) ? 15 : 0;
            }

            $r['score'] = round($score, 1);
        }
        unset($r);

        // Sort
        usort($results, function ($a, $b) use ($sortMode) {
            switch ($sortMode) {
                case 'popularity':
                    return ($b['statistics']['flight_count'] ?? 0) <=> ($a['statistics']['flight_count'] ?? 0);
                case 'distance':
                    return ($a['metadata']['distance_nm'] ?? PHP_INT_MAX) <=> ($b['metadata']['distance_nm'] ?? PHP_INT_MAX);
                case 'recency':
                    return ($b['statistics']['last_seen'] ?? '') <=> ($a['statistics']['last_seen'] ?? '');
                default: // 'score'
                    $cmp = $b['score'] <=> $a['score'];
                    if ($cmp !== 0) return $cmp;
                    return ($b['statistics']['flight_count'] ?? 0) <=> ($a['statistics']['flight_count'] ?? 0);
            }
        });

        return $results;
    }

    // =========================================================================
    // ENRICHMENT
    // =========================================================================

    private function enrich(array $results, array $includes, array &$warnings): array
    {
        $includeGeometry = in_array('geometry', $includes, true);
        $includeTraversal = in_array('traversal', $includes, true);
        $includeStatistics = in_array('statistics', $includes, true);

        // Geometry enrichment via PostGIS
        if ($includeGeometry) {
            $results = $this->enrichGeometry($results, $warnings);
        }

        // Statistics enrichment (attach historical stats to playbook/CDR routes that lack them)
        if ($includeStatistics) {
            $results = $this->enrichStatistics($results);
        }

        return $results;
    }

    private function enrichGeometry(array $results, array &$warnings): array
    {
        require_once __DIR__ . '/GISService.php';

        $gis = \GISService::getInstance();
        if (!$gis) {
            $warnings[] = 'geometry_unavailable';
            return $results;
        }

        // Collect routes needing expansion (skip those with frozen geometry)
        $needsExpansion = [];
        foreach ($results as $i => $r) {
            if (!empty($r['_route_geometry_json'])) {
                // Parse frozen geometry
                $geo = json_decode($r['_route_geometry_json'], true);
                if ($geo && isset($geo['geojson'])) {
                    $results[$i]['geometry'] = $geo['geojson'];
                    if (isset($geo['distance_nm'])) {
                        $results[$i]['metadata']['distance_nm'] = round((float)$geo['distance_nm'], 1);
                    }
                    continue;
                }
            }
            $needsExpansion[$i] = $r['_raw_route'];
        }

        // Batch expand remaining routes
        if (!empty($needsExpansion)) {
            $routes = array_values($needsExpansion);
            $indices = array_keys($needsExpansion);
            $expanded = $gis->expandRoutesBatch($routes);

            foreach ($expanded as $exp) {
                $idx = $indices[$exp['index']] ?? null;
                if ($idx === null) continue;

                if ($exp['geojson'] && empty($exp['error'])) {
                    $results[$idx]['geometry'] = $exp['geojson'];
                    $results[$idx]['metadata']['distance_nm'] = $exp['distance_nm'];
                    if (!empty($exp['artccs'])) {
                        $results[$idx]['_traversed_artccs'] = $exp['artccs'];
                        $results[$idx]['traversal'] = [
                            'artccs' => $exp['artccs'],
                            'tracons' => [],
                        ];
                    }
                }
            }
        }

        return $results;
    }

    private function enrichStatistics(array $results): array
    {
        // Find playbook/CDR results that lack statistics
        $needStats = [];
        foreach ($results as $i => $r) {
            if ($r['statistics'] === null) {
                $needStats[$i] = $r['route_string'];
            }
        }

        if (empty($needStats)) return $results;

        // Lookup in swim_route_stats by normalized route string
        // Note: NVARCHAR(MAX) cannot be indexed; relies on scan (~50K rows max, acceptable for v1)
        foreach ($needStats as $i => $routeStr) {
            $sql = "SELECT TOP 1 flight_count, usage_pct, avg_altitude_ft,
                           common_aircraft, common_operators, first_seen, last_seen
                    FROM dbo.swim_route_stats
                    WHERE normalized_route = ?
                    ORDER BY flight_count DESC";
            $stmt = sqlsrv_query($this->connSwim, $sql, [$routeStr]);
            if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $firstSeen = $row['first_seen'] instanceof \DateTime ? $row['first_seen']->format('Y-m-d') : (string)$row['first_seen'];
                $lastSeen = $row['last_seen'] instanceof \DateTime ? $row['last_seen']->format('Y-m-d') : (string)$row['last_seen'];
                $results[$i]['statistics'] = [
                    'flight_count' => (int)$row['flight_count'],
                    'usage_pct' => round((float)$row['usage_pct'], 1),
                    'avg_altitude_ft' => $row['avg_altitude_ft'] !== null ? (int)$row['avg_altitude_ft'] : null,
                    'common_aircraft' => $row['common_aircraft'] ? explode(',', $row['common_aircraft']) : [],
                    'common_operators' => $row['common_operators'] ? explode(',', $row['common_operators']) : [],
                    'first_seen' => $firstSeen,
                    'last_seen' => $lastSeen,
                ];
            }
            if ($stmt) sqlsrv_free_stmt($stmt);
        }

        return $results;
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function matchesTokens(array $row, array $tokens, string $direction): bool
    {
        foreach ($tokens as $token) {
            $code = $token['code'];
            $type = $token['type'];

            $col = ($direction === 'origin') ? 'origin' : 'dest';

            if ($type === self::FACILITY_AIRPORT) {
                if ($this->csvContains($row[$col . '_airports'] ?? '', $code)) return true;
            } elseif ($type === self::FACILITY_ARTCC) {
                if ($this->csvContains($row[$col . '_artccs'] ?? '', $code)) return true;
            } elseif ($type === self::FACILITY_TRACON) {
                if ($this->csvContains($row[$col . '_tracons'] ?? '', $code)) return true;
            }
        }
        return false;
    }

    private function csvContains(string $csv, string $value): bool
    {
        if ($csv === '') return false;
        $items = array_map('trim', explode(',', $csv));
        return in_array($value, $items, true);
    }

    private function parseCSV(string $csv): array
    {
        if ($csv === '') return [];
        return array_filter(array_map('trim', explode(',', $csv)));
    }

    private function parseCSVTraversal(array $row): ?array
    {
        $artccs = $this->parseCSV($row['traversed_artccs'] ?? '');
        $tracons = $this->parseCSV($row['traversed_tracons'] ?? '');
        if (empty($artccs) && empty($tracons)) return null;
        return ['artccs' => $artccs, 'tracons' => $tracons];
    }

    private function buildRouteIndex(array $row): array
    {
        $originCodes = array_merge(
            $this->parseCSV($row['origin_airports'] ?? ''),
            $this->parseCSV($row['origin_artccs'] ?? ''),
            $this->parseCSV($row['origin_tracons'] ?? '')
        );
        $destCodes = array_merge(
            $this->parseCSV($row['dest_airports'] ?? ''),
            $this->parseCSV($row['dest_artccs'] ?? ''),
            $this->parseCSV($row['dest_tracons'] ?? '')
        );
        $thruCodes = array_merge(
            $this->parseCSV($row['traversed_artccs'] ?? ''),
            $this->parseCSV($row['traversed_tracons'] ?? '')
        );
        $allCodes = array_unique(array_merge($originCodes, $destCodes, $thruCodes));
        $searchText = strtolower(implode(' ', [
            $row['route_string'] ?? '',
            $row['play_name'] ?? '',
            $row['remarks'] ?? '',
        ]));

        return [
            'originCodes' => $originCodes,
            'destCodes' => $destCodes,
            'thruCodes' => $thruCodes,
            'allCodes' => $allCodes,
            'searchText' => $searchText,
        ];
    }

    private function applyFilterAST(array $results, array $filterAST): array
    {
        // For CDR/historical results that weren't already filtered by the playbook query
        return array_values(array_filter($results, function ($r) use ($filterAST) {
            if ($r['source'] === 'playbook') return true; // already filtered
            // Build a minimal index for CDR/historical
            $index = [
                'originCodes' => [],
                'destCodes' => [],
                'thruCodes' => $r['_traversed_artccs'] ?? [],
                'allCodes' => $r['_traversed_artccs'] ?? [],
                'searchText' => strtolower($r['_raw_route'] ?? ''),
            ];
            return RouteFilterParser::evaluate($filterAST, $index);
        }));
    }

    /**
     * Escape SQL Server LIKE wildcard characters in user input.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['[', '%', '_'], ['[[]', '[%]', '[_]'], $value);
    }

    private function normalizeRouteString(string $route): string
    {
        // Strip leading/trailing ICAO airport codes, collapse whitespace, uppercase
        $route = strtoupper(trim($route));
        $route = preg_replace('/\s+/', ' ', $route);

        // Strip leading airport (4-char K/P prefix or international)
        $parts = explode(' ', $route);
        if (count($parts) > 2 && strlen($parts[0]) === 4 && preg_match('/^[A-Z]{4}$/', $parts[0])) {
            array_shift($parts);
        }
        // Strip trailing airport
        if (count($parts) > 1 && strlen(end($parts)) === 4 && preg_match('/^[A-Z]{4}$/', end($parts))) {
            array_pop($parts);
        }

        return implode(' ', $parts);
    }

    private function extractDistanceFromGeometry(?string $geoJson): ?float
    {
        if (!$geoJson) return null;
        $geo = json_decode($geoJson, true);
        if ($geo && isset($geo['distance_nm'])) {
            return round((float)$geo['distance_nm'], 1);
        }
        return null;
    }

    private function buildCDRTokenWhere(array $tokens, string $direction, array &$params): ?string
    {
        if (empty($tokens)) return null;

        $col = ($direction === 'origin') ? 'origin_icao' : 'dest_icao';
        $artccCol = ($direction === 'origin') ? 'dep_artcc' : 'arr_artcc';

        $orParts = [];
        $airportCodes = [];
        $artccCodes = [];

        foreach ($tokens as $token) {
            if ($token['type'] === self::FACILITY_AIRPORT) {
                $airportCodes[] = $token['code'];
            } elseif ($token['type'] === self::FACILITY_ARTCC) {
                $artccCodes[] = $token['code'];
            } elseif ($token['type'] === self::FACILITY_TRACON) {
                // TRACON tokens need expansion -- for now treat as airport-ish
                // CDRs don't have TRACON data, skip
            }
        }

        if (!empty($airportCodes)) {
            $placeholders = implode(',', array_fill(0, count($airportCodes), '?'));
            $orParts[] = "$col IN ($placeholders)";
            $params = array_merge($params, $airportCodes);
        }

        if (!empty($artccCodes)) {
            $placeholders = implode(',', array_fill(0, count($artccCodes), '?'));
            $orParts[] = "$artccCol IN ($placeholders)";
            $params = array_merge($params, $artccCodes);
        }

        if (empty($orParts)) return null;
        return '(' . implode(' OR ', $orParts) . ')';
    }

    private function expandTokensToAirports(array $tokens): array
    {
        $airports = [];
        foreach ($tokens as $token) {
            if ($token['type'] === self::FACILITY_AIRPORT) {
                $airports[] = $token['code'];
            } elseif ($token['type'] === self::FACILITY_ARTCC || $token['type'] === self::FACILITY_TRACON) {
                // Expand ARTCC/TRACON to airport list via swim_playbook_routes scope columns
                // For historical queries, we do a simpler approach: just pass the code through
                // and let the SQL handle it (historical stats are per-airport already)
                // This means ARTCC/TRACON queries on historical data require airport expansion
                $expanded = $this->expandFacilityToAirports($token);
                $airports = array_merge($airports, $expanded);
            }
        }
        return array_unique($airports);
    }

    private function expandFacilityToAirports(array $token): array
    {
        // Use SWIM_API airport reference or ADL airports table
        // Simple approach: query distinct airports from swim_playbook_routes scope columns
        $code = $token['code'];
        $type = $token['type'];

        if ($type === self::FACILITY_ARTCC) {
            // Get airports from playbook routes tagged with this ARTCC
            $sql = "SELECT DISTINCT value FROM (
                        SELECT TRIM(value) AS value FROM dbo.swim_playbook_routes
                        CROSS APPLY STRING_SPLIT(origin_airports, ',')
                        WHERE origin_artccs LIKE '%' + ? + '%'
                        UNION
                        SELECT TRIM(value) AS value FROM dbo.swim_playbook_routes
                        CROSS APPLY STRING_SPLIT(dest_airports, ',')
                        WHERE dest_artccs LIKE '%' + ? + '%'
                    ) t WHERE LEN(value) = 4";
            $escapedCode = $this->escapeLike($code);
            $stmt = sqlsrv_query($this->connSwim, $sql, [$escapedCode, $escapedCode]);
            if (!$stmt) return [];
            $airports = [];
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $airports[] = $row['value'];
            }
            sqlsrv_free_stmt($stmt);
            return $airports;
        }

        if ($type === self::FACILITY_TRACON) {
            $sql = "SELECT DISTINCT value FROM (
                        SELECT TRIM(value) AS value FROM dbo.swim_playbook_routes
                        CROSS APPLY STRING_SPLIT(origin_airports, ',')
                        WHERE origin_tracons LIKE '%' + ? + '%'
                        UNION
                        SELECT TRIM(value) AS value FROM dbo.swim_playbook_routes
                        CROSS APPLY STRING_SPLIT(dest_airports, ',')
                        WHERE dest_tracons LIKE '%' + ? + '%'
                    ) t WHERE LEN(value) = 4";
            $escapedCode = $this->escapeLike($code);
            $stmt = sqlsrv_query($this->connSwim, $sql, [$escapedCode, $escapedCode]);
            if (!$stmt) return [];
            $airports = [];
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $airports[] = $row['value'];
            }
            sqlsrv_free_stmt($stmt);
            return $airports;
        }

        return [];
    }

    /**
     * Format results for API response (strip internal fields).
     */
    public static function formatResults(array $results): array
    {
        return array_map(function ($r) {
            $out = [
                'rank' => $r['rank'] ?? 0,
                'score' => $r['score'] ?? 0,
                'source' => $r['source'],
                'route_string' => $r['route_string'],
                'metadata' => $r['metadata'],
            ];

            if (!empty($r['also_in'])) $out['also_in'] = array_values(array_unique($r['also_in']));
            if ($r['statistics'] !== null) $out['statistics'] = $r['statistics'];
            if (!empty($r['tmi_flags'])) $out['tmi_flags'] = $r['tmi_flags'];
            if ($r['traversal'] !== null) $out['traversal'] = $r['traversal'];
            if (isset($r['geometry'])) $out['geometry'] = $r['geometry'];

            return $out;
        }, $results);
    }
}
