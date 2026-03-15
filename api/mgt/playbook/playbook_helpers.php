<?php
/**
 * Shared helper functions for playbook save/route endpoints.
 * Extracted from save.php so route.php can reuse traversal computation.
 */

function normalizePlayName($name) {
    return strtoupper(preg_replace('/[^A-Z0-9]/i', '', $name));
}

/**
 * Normalize ARTCC codes:
 * - US ICAO K-prefix stripping: KZNY->ZNY, KZMA->ZMA, etc.
 * - Canadian FAA 3-letter to ICAO 4-letter: CZE->CZEG, CZU->CZUL, etc.
 */
function normalizeCanadianArtcc($code) {
    static $map = [
        'CZE' => 'CZEG', 'CZU' => 'CZUL', 'CZV' => 'CZVR',
        'CZW' => 'CZWG', 'CZY' => 'CZYZ', 'CZM' => 'CZQM',
        'CZQ' => 'CZQX', 'CZO' => 'CZQO',
        'PAZA' => 'ZAN',
    ];
    $code = strtoupper(trim($code));
    if (preg_match('/^KZ[A-Z]{2}$/', $code)) $code = substr($code, 1);
    return $map[$code] ?? $code;
}

function normalizeCanadianArtccCsv($csv) {
    if (trim($csv) === '') return $csv;
    return implode(',', array_map('normalizeCanadianArtcc', explode(',', $csv)));
}

function normalizeRouteCanadian($rs) {
    static $codes = ['CZE','CZU','CZV','CZW','CZY','CZM','CZQ','CZO'];
    $parts = preg_split('/\s+/', trim($rs));
    $changed = false;
    foreach ($parts as &$p) {
        if (in_array(strtoupper($p), $codes)) {
            $old = $p;
            $p = normalizeCanadianArtcc($p);
            if ($p !== $old) $changed = true;
        }
    }
    return $changed ? implode(' ', $parts) : $rs;
}

/**
 * Extract a route endpoint identifier for LINESTRING bookending.
 * PostGIS resolve_waypoint() handles airports (KJFK), TRACONs (A90, PCT),
 * ARTCCs (ZNY, ZBW), and FAA codes (JFK) via nav_fixes + airports + area_centers.
 *
 * Priority: origin_airports (most specific) -> origin label -> origin_artccs (fallback)
 */
function _extractRouteEndpoint($label, $airportsCsv = '', $artccsCsv = '') {
    // 1. Try airports CSV first -- most specific endpoint
    if ($airportsCsv !== '') {
        $first = strtoupper(trim(explode(',', $airportsCsv)[0]));
        if ($first !== '' && preg_match('/^[A-Z]{3,4}$/', $first)) {
            return $first;
        }
    }

    // 2. Try the label field (airport ICAO, TRACON code, ARTCC code, etc.)
    $label = strtoupper(trim($label));
    if ($label !== '' && preg_match('/^[A-Z][A-Z0-9]{1,4}$/', $label)) {
        return $label;
    }

    // 3. Fall back to first ARTCC in artccs CSV
    if ($artccsCsv !== '') {
        $first = strtoupper(trim(explode(',', $artccsCsv)[0]));
        if ($first !== '' && $first !== 'UNKN' && preg_match('/^[A-Z]{2,4}$/', $first)) {
            return $first;
        }
    }

    return '';
}

/**
 * Compute traversed facilities using the PostGIS expand_route_with_artccs() function.
 * This reuses the same route parsing/expansion pipeline as route.php and the ADL
 * parse queue -- properly resolving airways, DPs/STARs, airports, and FBD tokens.
 *
 * The origin/dest airports are prepended/appended to the route_string so the
 * resulting LINESTRING spans the full origin-to-destination path.
 *
 * Returns array with: artccs, tracons, sectors_low, sectors_high, sectors_superhigh
 * Each value is a comma-separated string of boundary codes.
 */
function computeTraversedFacilities($route_string, $origin_artccs, $dest_artccs,
                                    $origin = '', $dest = '',
                                    $origin_airports = '', $dest_airports = '') {
    static $conn_gis_cached = null;
    static $gis_available = null;

    $result = [
        'artccs' => '',
        'tracons' => '',
        'sectors_low' => '',
        'sectors_high' => '',
        'sectors_superhigh' => '',
    ];

    // Lazy-init GIS connection (only once per request)
    if ($gis_available === null) {
        if (function_exists('get_conn_gis')) {
            $conn_gis_cached = get_conn_gis();
            $gis_available = ($conn_gis_cached !== null && $conn_gis_cached !== false);
        } else {
            $gis_available = false;
        }
    }

    if (!$gis_available || trim($route_string) === '') {
        return $result;
    }

    $artccs = [];
    $tracons = [];
    $sectors_low = [];
    $sectors_high = [];
    $sectors_superhigh = [];

    try {
        // Build full route string: prepend origin airport, append dest airport
        // so the LINESTRING spans the complete origin->destination path.
        $fullRoute = strtoupper(trim($route_string));

        // Resolve origin/dest endpoints: airports -> label -> ARTCCs
        // PostGIS resolve_waypoint() handles all types (airports, TRACONs, ARTCCs)
        $origEndpoint = _extractRouteEndpoint($origin, $origin_airports, $origin_artccs);
        $destEndpoint = _extractRouteEndpoint($dest, $dest_airports, $dest_artccs);

        // Don't prepend/append if route already starts/ends with the endpoint
        // (avoids duplicate tokens like "ZLA ZLA TRM..." that cause mid-route misresolution)
        $routeParts = preg_split('/\s+/', $fullRoute);
        $firstToken = strtoupper($routeParts[0] ?? '');
        $lastToken = strtoupper($routeParts[count($routeParts) - 1] ?? '');
        if ($origEndpoint && $origEndpoint !== $firstToken) {
            $fullRoute = $origEndpoint . ' ' . $fullRoute;
        }
        if ($destEndpoint && $destEndpoint !== $lastToken) {
            $fullRoute = $fullRoute . ' ' . $destEndpoint;
        }

        // Use expand_route_with_artccs() for proper route expansion (handles
        // airways, DPs/STARs, airports, FBD tokens -- same as route.php).
        // Then intersect the resulting geometry with TRACON + sector boundaries.
        $sql = "WITH route AS (
                    SELECT artccs_traversed, route_geometry AS geom
                    FROM expand_route_with_artccs(?)
                )
                SELECT sub.btype, sub.code FROM (
                    SELECT 'artcc' AS btype, u.code, u.ord AS trav_order
                    FROM route, unnest(route.artccs_traversed) WITH ORDINALITY AS u(code, ord)
                    WHERE route.geom IS NOT NULL
                    UNION ALL
                    SELECT 'tracon', t.tracon_code,
                        ST_LineLocatePoint(route.geom, ST_Centroid(ST_Intersection(route.geom, t.geom)))
                    FROM route JOIN tracon_boundaries t ON ST_Intersects(route.geom, t.geom)
                    WHERE route.geom IS NOT NULL
                    UNION ALL
                    SELECT CONCAT('sector_', LOWER(s.sector_type)), s.sector_code,
                        ST_LineLocatePoint(route.geom, ST_Centroid(ST_Intersection(route.geom, s.geom)))
                    FROM route JOIN sector_boundaries s ON ST_Intersects(route.geom, s.geom)
                    WHERE route.geom IS NOT NULL
                ) sub
                ORDER BY
                    CASE WHEN sub.btype = 'artcc' THEN 1
                         WHEN sub.btype = 'tracon' THEN 2
                         ELSE 3 END,
                    sub.trav_order";

        $stmt = $conn_gis_cached->prepare($sql);
        $stmt->execute([$fullRoute]);

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $code = $row['code'];
            switch ($row['btype']) {
                case 'artcc':
                    $code = normalizeCanadianArtcc($code);
                    $artccs[] = $code;
                    break;
                case 'tracon':
                    $tracons[] = $code;
                    break;
                case 'sector_low':
                    $sectors_low[] = $code;
                    break;
                case 'sector_high':
                    $sectors_high[] = $code;
                    break;
                case 'sector_superhigh':
                    $sectors_superhigh[] = $code;
                    break;
            }
        }
    } catch (\Exception $e) {
        // Silently fail -- traversal data will just be empty
    }

    // Merge origin ARTCCs BEFORE GIS results, dest ARTCCs AFTER.
    // array_unique() preserves first occurrence, so insertion order matters:
    // origin -> GIS spatial -> destination gives correct traversal ordering.
    $origin_list = [];
    foreach (explode(',', $origin_artccs) as $a) {
        $a = trim($a);
        if ($a !== '' && strtoupper($a) !== 'UNKN') $origin_list[] = $a;
    }
    $dest_list = [];
    foreach (explode(',', $dest_artccs) as $a) {
        $a = trim($a);
        if ($a !== '' && strtoupper($a) !== 'UNKN') $dest_list[] = $a;
    }
    $artccs = array_merge($origin_list, $artccs, $dest_list);

    $result['artccs'] = implode(',', array_unique(array_filter($artccs)));
    $result['tracons'] = implode(',', array_unique(array_filter($tracons)));
    $result['sectors_low'] = implode(',', array_unique(array_filter($sectors_low)));
    $result['sectors_high'] = implode(',', array_unique(array_filter($sectors_high)));
    $result['sectors_superhigh'] = implode(',', array_unique(array_filter($sectors_superhigh)));

    return $result;
}
