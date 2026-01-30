<?php
/**
 * Tier Query API
 *
 * Flexible API for querying facility proximity tiers.
 * Supports single/multi-facility queries, country/region filtering, named groups.
 *
 * Examples:
 *   GET ?facility=ZFW&tier=1                           - Tier 1 neighbors of ZFW
 *   GET ?facility=ZLA&tier=2                           - Tier 2 neighbors of ZLA
 *   GET ?facilities=ZBW,ZNY,ZDC&tier=1                 - Combined Tier 1 of multiple facilities
 *   GET ?facility=ZAU&tier=1&include=CAN              - Tier 1 + Canadian FIRs
 *   GET ?facility=ZHU&tier=2&include=CAN,MEX,LATAM,CAR - Tier 2 + international
 *   GET ?facility=EGTT&tier=4                         - International facility query
 *   GET ?group=6WEST                                   - Named tier group
 *   GET ?facility=ZFW&tier_min=1&tier_max=3           - Tier range 1-3
 *   GET ?facility=ZSE&tier=2&exclude=CAN              - Tier 2 excluding Canada
 *   GET ?facility=ZAB&tier=2&us_only=1                - US ARTCCs only
 *
 * @version 1.0.0
 * @date 2026-01-30
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../../load/connect.php';
require_once __DIR__ . '/../../load/services/GISService.php';

// =============================================================================
// CONFIGURATION
// =============================================================================

// Named tier groups (from ADL topology)
$NAMED_GROUPS = [
    '6WEST' => ['ZLA', 'ZLC', 'ZDV', 'ZOA', 'ZAB', 'ZSE'],
    '10WEST' => ['ZAB', 'ZDV', 'ZFW', 'ZHU', 'ZKC', 'ZLA', 'ZLC', 'ZMP', 'ZOA', 'ZSE'],
    '12WEST' => ['ZAB', 'ZAU', 'ZDV', 'ZFW', 'ZHU', 'ZKC', 'ZLA', 'ZLC', 'ZME', 'ZMP', 'ZOA', 'ZSE'],
    'EASTCOAST' => ['ZBW', 'ZNY', 'ZDC', 'ZJX', 'ZMA'],
    'WESTCOAST' => ['ZSE', 'ZOA', 'ZLA'],
    'GULF' => ['ZJX', 'ZMA', 'ZHU'],
    'CANWEST' => ['CZVR', 'CZEG'],
    'CANEAST' => ['CZWG', 'CZYZ', 'CZUL', 'CZQM'],
    'ALL' => ['ZAB', 'ZAU', 'ZBW', 'ZDC', 'ZDV', 'ZFW', 'ZHU', 'ZID', 'ZJX', 'ZKC',
              'ZLA', 'ZLC', 'ZMA', 'ZME', 'ZMP', 'ZNY', 'ZOA', 'ZOB', 'ZSE', 'ZTL'],
    'ALL+CANADA' => ['ZAB', 'ZAU', 'ZBW', 'ZDC', 'ZDV', 'ZFW', 'ZHU', 'ZID', 'ZJX', 'ZKC',
                    'ZLA', 'ZLC', 'ZMA', 'ZME', 'ZMP', 'ZNY', 'ZOA', 'ZOB', 'ZSE', 'ZTL',
                    'CZVR', 'CZEG', 'CZWG', 'CZYZ', 'CZUL', 'CZQM']
];

// Region mappings (code prefixes)
$REGION_PREFIXES = [
    'US' => ['KZ'],           // US CONUS ARTCCs (KZFW, KZLA, etc.)
    'CAN' => ['CZ'],          // Canadian FIRs (CZYZ, CZUL, etc.)
    'MEX' => ['MM'],          // Mexican FIRs (MMID, MMFR, etc.)
    'CAR' => ['M', 'T'],      // Caribbean (MUFH, TJZS, MKJK, etc.) - starts with M or T (not MM)
    'LATAM' => ['S', 'SK'],   // South/Central America
    'EUR' => ['E', 'L'],      // Europe (EGTT, LFFF, etc.)
    'ASIA' => ['R', 'Z', 'V'], // Asia-Pacific
    'AFR' => ['D', 'F', 'G', 'H'], // Africa
    'OCEANIC' => ['ZAK', 'ZWY', 'CZQO', 'CZQX'] // Oceanic FIRs
];

// =============================================================================
// HELPER FUNCTIONS
// =============================================================================

/**
 * Convert FAA code (ZFW) to ICAO code (KZFW) for US ARTCCs
 */
function toIcaoCode(string $code): string
{
    $code = strtoupper(trim($code));

    // Already has K prefix
    if (preg_match('/^KZ[A-Z]{2}$/', $code)) {
        return $code;
    }

    // US ARTCC without K prefix
    if (preg_match('/^Z[A-Z]{2}$/', $code)) {
        return 'K' . $code;
    }

    // International or other format - return as-is
    return $code;
}

/**
 * Convert ICAO code (KZFW) to FAA code (ZFW) for display
 */
function toFaaCode(string $code): string
{
    $code = strtoupper(trim($code));

    // Strip K prefix from US ARTCCs
    if (preg_match('/^KZ[A-Z]{2}$/', $code)) {
        return substr($code, 1);
    }

    return $code;
}

/**
 * Check if a facility code matches a region
 */
function matchesRegion(string $code, string $region, array $regionPrefixes): bool
{
    $region = strtoupper($region);
    $code = strtoupper($code);

    if (!isset($regionPrefixes[$region])) {
        return false;
    }

    foreach ($regionPrefixes[$region] as $prefix) {
        if (str_starts_with($code, $prefix)) {
            // Special handling for CAR to exclude MEX (MM)
            if ($region === 'CAR' && str_starts_with($code, 'MM')) {
                continue;
            }
            return true;
        }
    }

    return false;
}

/**
 * Get country/region code from facility code
 */
function getRegionFromCode(string $code): string
{
    global $REGION_PREFIXES;

    foreach ($REGION_PREFIXES as $region => $prefixes) {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($code, $prefix)) {
                if ($region === 'CAR' && str_starts_with($code, 'MM')) {
                    continue;
                }
                return $region;
            }
        }
    }

    return 'OTHER';
}

// =============================================================================
// MAIN LOGIC
// =============================================================================

$gis = GISService::getInstance();

if (!$gis) {
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'error' => 'GIS service unavailable',
        'error_code' => 'SERVICE_UNAVAILABLE'
    ]);
    exit;
}

// Parse parameters
$facility = strtoupper($_GET['facility'] ?? '');
$facilities = isset($_GET['facilities']) ? array_map('strtoupper', array_map('trim', explode(',', $_GET['facilities']))) : [];
$group = strtoupper($_GET['group'] ?? '');

$tier = isset($_GET['tier']) ? (float)$_GET['tier'] : null;
$tierMin = isset($_GET['tier_min']) ? (float)$_GET['tier_min'] : null;
$tierMax = isset($_GET['tier_max']) ? (float)$_GET['tier_max'] : null;
$maxTier = isset($_GET['max_tier']) ? (float)$_GET['max_tier'] : 5.0;

$include = isset($_GET['include']) ? array_map('strtoupper', array_map('trim', explode(',', $_GET['include']))) : [];
$exclude = isset($_GET['exclude']) ? array_map('strtoupper', array_map('trim', explode(',', $_GET['exclude']))) : [];
// Default to US-only unless include regions are specified or us_only=0
$usOnly = !isset($_GET['include']) && ($_GET['us_only'] ?? '1') !== '0';
$sameTypeOnly = ($_GET['same_type'] ?? '1') === '1';  // Default to same type (ARTCC-to-ARTCC)

$format = strtolower($_GET['format'] ?? 'full');  // full, simple, codes, gdt

// =============================================================================
// GDT FORMAT: Generate full tier configuration CSV for GDT scope builder
// =============================================================================
if ($format === 'gdt' || $format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: inline; filename="TierInfo.csv"');

    echo "code,facility,select,departureFacilitiesIncluded\n";

    // Global groups
    echo "ALL,,(ALL)," . implode(' ', $NAMED_GROUPS['ALL']) . "\n";
    echo "ALL+Canada,,(ALL+Canada)," . implode(' ', $NAMED_GROUPS['ALL+CANADA']) . "\n";
    echo "Manual,,(Manual),\n";

    // Named tier groups
    $namedGroupsToExport = ['6WEST', '10WEST', '12WEST', 'EASTCOAST', 'WESTCOAST', 'GULF'];
    foreach ($namedGroupsToExport as $grpName) {
        if (isset($NAMED_GROUPS[$grpName])) {
            $members = $NAMED_GROUPS[$grpName];
            sort($members);
            echo "{$grpName},,({$grpName})," . implode(' ', $members) . "\n";
        }
    }

    // Generate per-facility tier configs using precomputed tier matrix
    // This uses a single bulk query instead of 20 separate queries
    $allArtccs = $NAMED_GROUPS['ALL'];
    $tierMatrix = $gis->getAllArtccTiers(2.0);  // Single query for all ARTCCs

    foreach ($allArtccs as $artcc) {
        $icaoCode = toIcaoCode($artcc);

        // Get precomputed tiers for this ARTCC
        $artccTiers = $tierMatrix[$icaoCode] ?? [];

        // Group results by tier (cumulative)
        $tier0 = [$artcc];  // Self
        $tier1 = [$artcc];  // Self + tier 1
        $tier2 = [$artcc];  // Self + tier 1 + tier 2

        // Process tier 0 (self - already included)
        // Process tier 1 neighbors
        if (isset($artccTiers[1.0])) {
            foreach ($artccTiers[1.0] as $neighborCode) {
                $code = toFaaCode($neighborCode);
                $region = getRegionFromCode($neighborCode);
                if ($region === 'US' && !in_array($code, $tier1)) {
                    $tier1[] = $code;
                    $tier2[] = $code;
                }
            }
        }

        // Process tier 2 neighbors
        if (isset($artccTiers[2.0])) {
            foreach ($artccTiers[2.0] as $neighborCode) {
                $code = toFaaCode($neighborCode);
                $region = getRegionFromCode($neighborCode);
                if ($region === 'US' && !in_array($code, $tier2)) {
                    $tier2[] = $code;
                }
            }
        }

        sort($tier0);
        sort($tier1);
        sort($tier2);

        // Output facility configs
        echo "{$artcc}1,{$artcc},(Internal)," . implode(' ', $tier0) . "\n";
        echo "{$artcc}2,{$artcc},(1stTier)," . implode(' ', $tier1) . "\n";
        echo "{$artcc}3,{$artcc},(2ndTier)," . implode(' ', $tier2) . "\n";
    }

    exit;
}

try {
    // ==========================================================================
    // NAMED GROUP QUERY
    // ==========================================================================
    if ($group) {
        if (!isset($NAMED_GROUPS[$group])) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => "Unknown tier group: {$group}",
                'error_code' => 'UNKNOWN_GROUP',
                'available_groups' => array_keys($NAMED_GROUPS)
            ]);
            exit;
        }

        $members = $NAMED_GROUPS[$group];

        echo json_encode([
            'success' => true,
            'query_type' => 'named_group',
            'group' => $group,
            'facilities' => $members,
            'facilities_display' => implode('/', $members),
            'count' => count($members)
        ]);
        exit;
    }

    // ==========================================================================
    // VALIDATE INPUTS
    // ==========================================================================
    if (!$facility && empty($facilities)) {
        // Show help
        echo json_encode([
            'success' => true,
            'service' => 'PERTI Tier Query API',
            'version' => '1.0.0',
            'usage' => [
                'single_facility' => '?facility=ZFW&tier=1',
                'multi_facility' => '?facilities=ZBW,ZNY,ZDC&tier=1',
                'tier_range' => '?facility=ZFW&tier_min=1&tier_max=3',
                'with_canada' => '?facility=ZAU&tier=1&include=CAN',
                'international' => '?facility=ZHU&tier=2&include=CAN,MEX,LATAM,CAR',
                'us_only' => '?facility=ZAB&tier=2&us_only=1',
                'exclude_region' => '?facility=ZSE&tier=2&exclude=CAN',
                'named_group' => '?group=6WEST',
                'international_origin' => '?facility=EGTT&tier=2'
            ],
            'parameters' => [
                'facility' => 'Single facility code (ZFW, KZFW, EGTT, CZYZ)',
                'facilities' => 'Comma-separated list for union query',
                'tier' => 'Exact tier to return (1, 1.5, 2, etc.)',
                'tier_min' => 'Minimum tier (inclusive)',
                'tier_max' => 'Maximum tier (inclusive)',
                'max_tier' => 'Maximum tier depth to search (default 5)',
                'include' => 'Regions to include: US, CAN, MEX, CAR, LATAM, EUR, ASIA, AFR',
                'exclude' => 'Regions to exclude',
                'us_only' => 'Only return US ARTCCs (shorthand for include=US)',
                'same_type' => 'Only same boundary type (default 1)',
                'group' => 'Named group: 6WEST, 10WEST, 12WEST, EASTCOAST, WESTCOAST, GULF, ALL, ALL+CANADA',
                'format' => 'Output format: full, simple, codes'
            ],
            'regions' => array_keys($REGION_PREFIXES),
            'named_groups' => array_keys($NAMED_GROUPS)
        ]);
        exit;
    }

    // ==========================================================================
    // BUILD FACILITY LIST
    // ==========================================================================
    $queryFacilities = [];

    if ($facility) {
        $queryFacilities[] = $facility;
    }

    if (!empty($facilities)) {
        $queryFacilities = array_merge($queryFacilities, $facilities);
    }

    $queryFacilities = array_unique($queryFacilities);

    // Determine tier range
    if ($tier !== null) {
        $tierMin = $tier;
        $tierMax = $tier;
    } elseif ($tierMin === null && $tierMax === null) {
        // Default to tier 1 if not specified
        $tierMin = 1.0;
        $tierMax = 1.0;
    } elseif ($tierMin !== null && $tierMax === null) {
        $tierMax = $tierMin;
    } elseif ($tierMin === null && $tierMax !== null) {
        $tierMin = 0.0;
    }

    // Ensure we search deep enough
    $searchMaxTier = max($maxTier, $tierMax);

    // ==========================================================================
    // QUERY GIS FOR EACH FACILITY
    // ==========================================================================
    $allResults = [];
    $facilityCodes = [];

    foreach ($queryFacilities as $fac) {
        $icaoCode = toIcaoCode($fac);

        $tiers = $gis->getProximityTiers('ARTCC', $icaoCode, $searchMaxTier, $sameTypeOnly);

        foreach ($tiers as $t) {
            // Skip self (tier 0) unless explicitly requested
            if ($t['tier'] == 0 && $tierMin > 0) {
                continue;
            }

            // Filter by tier range
            if ($t['tier'] < $tierMin || $t['tier'] > $tierMax) {
                continue;
            }

            $code = toFaaCode($t['boundary_code']);
            $region = getRegionFromCode($t['boundary_code']);

            // Apply US-only filter
            if ($usOnly && $region !== 'US') {
                continue;
            }

            // Apply include filter (if specified, ONLY these regions)
            if (!empty($include)) {
                $included = false;
                foreach ($include as $inc) {
                    if (matchesRegion($t['boundary_code'], $inc, $REGION_PREFIXES)) {
                        $included = true;
                        break;
                    }
                }
                // Always include origin facility's region by default
                if (!$included && $region === 'US') {
                    $included = true;
                }
                if (!$included) {
                    continue;
                }
            }

            // Apply exclude filter
            if (!empty($exclude)) {
                $excluded = false;
                foreach ($exclude as $exc) {
                    if (matchesRegion($t['boundary_code'], $exc, $REGION_PREFIXES)) {
                        $excluded = true;
                        break;
                    }
                }
                if ($excluded) {
                    continue;
                }
            }

            // Build unique key for deduplication (multi-facility union)
            $key = $code;

            if (!isset($allResults[$key])) {
                $allResults[$key] = [
                    'code' => $code,
                    'name' => $t['boundary_name'],
                    'tier' => $t['tier'],
                    'region' => $region,
                    'adjacency_class' => $t['adjacency_class'],
                    'reached_from' => [toFaaCode($t['adjacency_from'] ?? '')]
                ];
            } else {
                // Take minimum tier if found from multiple origins
                if ($t['tier'] < $allResults[$key]['tier']) {
                    $allResults[$key]['tier'] = $t['tier'];
                }
                // Track all paths
                $from = toFaaCode($t['adjacency_from'] ?? '');
                if ($from && !in_array($from, $allResults[$key]['reached_from'])) {
                    $allResults[$key]['reached_from'][] = $from;
                }
            }

            $facilityCodes[$code] = true;
        }
    }

    // Sort by tier, then by code
    uasort($allResults, function($a, $b) {
        if ($a['tier'] !== $b['tier']) {
            return $a['tier'] <=> $b['tier'];
        }
        return $a['code'] <=> $b['code'];
    });

    $allResults = array_values($allResults);
    $facilityCodes = array_keys($facilityCodes);
    sort($facilityCodes);

    // ==========================================================================
    // FORMAT OUTPUT
    // ==========================================================================

    // Group by tier
    $byTier = [];
    foreach ($allResults as $r) {
        $tierKey = (string)$r['tier'];
        if (!isset($byTier[$tierKey])) {
            $byTier[$tierKey] = [];
        }
        $byTier[$tierKey][] = $r['code'];
    }

    // Group by region
    $byRegion = [];
    foreach ($allResults as $r) {
        $region = $r['region'];
        if (!isset($byRegion[$region])) {
            $byRegion[$region] = [];
        }
        $byRegion[$region][] = $r['code'];
    }

    // Build response based on format
    $response = [
        'success' => true,
        'query' => [
            'facilities' => array_map('toFaaCode', $queryFacilities),
            'tier_min' => $tierMin,
            'tier_max' => $tierMax,
            'include_regions' => $include ?: null,
            'exclude_regions' => $exclude ?: null,
            'us_only' => $usOnly
        ]
    ];

    if ($format === 'codes') {
        // Minimal: just the codes
        $response['facilities'] = $facilityCodes;
        $response['facilities_display'] = implode('/', $facilityCodes);
        $response['count'] = count($facilityCodes);
    } elseif ($format === 'simple') {
        // Simple: grouped by tier
        $response['by_tier'] = $byTier;
        $response['by_region'] = $byRegion;
        $response['facilities'] = $facilityCodes;
        $response['facilities_display'] = implode('/', $facilityCodes);
        $response['count'] = count($facilityCodes);
    } else {
        // Full: all details
        $response['results'] = $allResults;
        $response['by_tier'] = $byTier;
        $response['by_region'] = $byRegion;
        $response['facilities'] = $facilityCodes;
        $response['facilities_display'] = implode('/', $facilityCodes);
        $response['count'] = count($facilityCodes);
    }

    echo json_encode($response, JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'error_code' => 'SERVER_ERROR',
        'message' => $e->getMessage()
    ]);
}
