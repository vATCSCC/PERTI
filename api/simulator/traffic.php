<?php
/**
 * api/simulator/traffic.php
 * 
 * Traffic generation data API for the ATFM simulator
 * Provides access to:
 *   - Route patterns (sim_ref_route_patterns)
 *   - Airport demand profiles (sim_ref_airport_demand)
 *   - Carrier lookup (sim_ref_carrier_lookup)
 *   - Historical ADL snapshots for replay
 * 
 * GET Parameters:
 *   action - 'patterns', 'demand', 'carriers', 'historical', 'generate'
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../../load/config.php';
require_once __DIR__ . '/../../load/connect.php';

try {
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    
    switch ($action) {
        case 'patterns':
            handlePatterns();
            break;
        case 'demand':
            handleDemand();
            break;
        case 'carriers':
            handleCarriers();
            break;
        case 'historical':
            handleHistorical();
            break;
        case 'generate':
            handleGenerate();
            break;
        case 'scenarios':
            handleScenarios();
            break;
        default:
            echo json_encode([
                'status' => 'error', 
                'message' => 'Invalid action. Use: patterns, demand, carriers, historical, generate, scenarios'
            ]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

/**
 * Get route patterns for traffic generation
 * 
 * Query params:
 *   dest     - Destination airport (required)
 *   limit    - Max patterns to return (default 100)
 *   min_freq - Minimum frequency threshold (default 1)
 */
function handlePatterns() {
    global $conn_adl;
    
    $dest = strtoupper(trim($_GET['dest'] ?? ''));
    $limit = intval($_GET['limit'] ?? 100);
    $minFreq = intval($_GET['min_freq'] ?? 1);
    
    if (empty($dest)) {
        echo json_encode(['status' => 'error', 'message' => 'Missing dest parameter']);
        return;
    }
    
    // Normalize to ICAO (add K if 3-letter)
    if (strlen($dest) === 3) {
        $dest = 'K' . $dest;
    }
    
    // Check if we have the reference table
    if (!$conn_adl) {
        // Return fallback patterns
        echo json_encode([
            'status' => 'ok',
            'source' => 'fallback',
            'patterns' => getFallbackPatterns($dest)
        ]);
        return;
    }
    
    // Try to query sim_ref_route_patterns
    $sql = "SELECT TOP (?) 
                origin_icao, dest_icao, carrier_icao, aircraft_type,
                frequency, avg_flight_time_min
            FROM dbo.sim_ref_route_patterns
            WHERE dest_icao = ? AND frequency >= ?
            ORDER BY frequency DESC";
    
    $params = [$limit, $dest, $minFreq];
    $stmt = sqlsrv_query($conn_adl, $sql, $params);
    
    if ($stmt === false) {
        // Table might not exist - use fallback
        echo json_encode([
            'status' => 'ok',
            'source' => 'fallback',
            'patterns' => getFallbackPatterns($dest)
        ]);
        return;
    }
    
    $patterns = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $patterns[] = [
            'origin' => $row['origin_icao'],
            'dest' => $row['dest_icao'],
            'carrier' => $row['carrier_icao'],
            'aircraftType' => $row['aircraft_type'],
            'frequency' => intval($row['frequency']),
            'avgFlightTimeMin' => intval($row['avg_flight_time_min'] ?? 120)
        ];
    }
    sqlsrv_free_stmt($stmt);
    
    if (count($patterns) === 0) {
        $patterns = getFallbackPatterns($dest);
    }
    
    echo json_encode([
        'status' => 'ok',
        'source' => count($patterns) > 0 ? 'database' : 'fallback',
        'dest' => $dest,
        'count' => count($patterns),
        'patterns' => $patterns
    ]);
}

/**
 * Get airport demand profile
 * 
 * Query params:
 *   airport - Airport ICAO (required)
 */
function handleDemand() {
    global $conn_adl;
    
    $airport = strtoupper(trim($_GET['airport'] ?? ''));
    
    if (empty($airport)) {
        echo json_encode(['status' => 'error', 'message' => 'Missing airport parameter']);
        return;
    }
    
    if (strlen($airport) === 3) {
        $airport = 'K' . $airport;
    }
    
    // Try database first
    if ($conn_adl) {
        $sql = "SELECT hour_utc, avg_arrivals, avg_departures, peak_arrivals, peak_departures
                FROM dbo.sim_ref_airport_demand
                WHERE airport_icao = ?
                ORDER BY hour_utc";
        
        $stmt = sqlsrv_query($conn_adl, $sql, [$airport]);
        
        if ($stmt !== false) {
            $hourly = [];
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $hourly[] = [
                    'hour' => intval($row['hour_utc']),
                    'avgArrivals' => floatval($row['avg_arrivals']),
                    'avgDepartures' => floatval($row['avg_departures']),
                    'peakArrivals' => intval($row['peak_arrivals']),
                    'peakDepartures' => intval($row['peak_departures'])
                ];
            }
            sqlsrv_free_stmt($stmt);
            
            if (count($hourly) > 0) {
                echo json_encode([
                    'status' => 'ok',
                    'source' => 'database',
                    'airport' => $airport,
                    'hourly' => $hourly
                ]);
                return;
            }
        }
    }
    
    // Fallback demand profile
    echo json_encode([
        'status' => 'ok',
        'source' => 'fallback',
        'airport' => $airport,
        'hourly' => getFallbackDemand($airport)
    ]);
}

/**
 * Get carrier lookup table
 */
function handleCarriers() {
    global $conn_adl;
    
    if ($conn_adl) {
        $sql = "SELECT carrier_icao, carrier_name, callsign_prefix, hub_airports
                FROM dbo.sim_ref_carrier_lookup
                ORDER BY carrier_icao";
        
        $stmt = sqlsrv_query($conn_adl, $sql);
        
        if ($stmt !== false) {
            $carriers = [];
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $carriers[] = [
                    'icao' => $row['carrier_icao'],
                    'name' => $row['carrier_name'],
                    'callsign' => $row['callsign_prefix'],
                    'hubs' => $row['hub_airports']
                ];
            }
            sqlsrv_free_stmt($stmt);
            
            if (count($carriers) > 0) {
                echo json_encode(['status' => 'ok', 'source' => 'database', 'carriers' => $carriers]);
                return;
            }
        }
    }
    
    // Fallback carriers
    echo json_encode([
        'status' => 'ok',
        'source' => 'fallback',
        'carriers' => getFallbackCarriers()
    ]);
}

/**
 * Get historical ADL snapshot for replay
 * 
 * Query params:
 *   dest       - Destination airport (required)
 *   date       - Date (YYYY-MM-DD) to replay (required)
 *   start_hour - Start hour UTC (default 12)
 *   end_hour   - End hour UTC (default 18)
 */
function handleHistorical() {
    global $conn_adl;
    
    $dest = strtoupper(trim($_GET['dest'] ?? ''));
    $date = $_GET['date'] ?? '';
    $startHour = intval($_GET['start_hour'] ?? 12);
    $endHour = intval($_GET['end_hour'] ?? 18);
    
    if (empty($dest) || empty($date)) {
        echo json_encode(['status' => 'error', 'message' => 'Missing dest or date parameter']);
        return;
    }
    
    if (strlen($dest) === 3) {
        $dest = 'K' . $dest;
    }
    
    if (!$conn_adl) {
        echo json_encode(['status' => 'error', 'message' => 'Database connection unavailable']);
        return;
    }
    
    // Query historical ADL data
    // Note: This assumes we have archived flight data - adjust table/columns as needed
    $sql = "SELECT 
                acid as callsign,
                dept_apt as origin,
                arr_apt as destination,
                ac_type as aircraftType,
                major_carrier as carrier,
                CONVERT(varchar, etd_utc, 120) as etd,
                CONVERT(varchar, eta_utc, 120) as eta,
                filed_alt as altitude,
                filed_spd as speed,
                route as route
            FROM dbo.adl_flight_core c
            JOIN dbo.adl_flight_plan p ON c.flight_key = p.flight_key
            WHERE c.arr_apt = ?
              AND CAST(c.eta_utc AS DATE) = ?
              AND DATEPART(HOUR, c.eta_utc) >= ?
              AND DATEPART(HOUR, c.eta_utc) < ?
              AND c.flight_status NOT IN ('COMPLETED', 'CANCELLED')
            ORDER BY c.eta_utc";
    
    $params = [$dest, $date, $startHour, $endHour];
    $stmt = sqlsrv_query($conn_adl, $sql, $params);
    
    if ($stmt === false) {
        // Return synthetic data if historical query fails
        echo json_encode([
            'status' => 'ok',
            'source' => 'synthetic',
            'message' => 'Historical data not available, using synthetic generation',
            'flights' => generateSyntheticFlights($dest, $startHour, $endHour, 60)
        ]);
        return;
    }
    
    $flights = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $flights[] = [
            'callsign' => $row['callsign'],
            'origin' => $row['origin'],
            'destination' => $row['destination'],
            'aircraftType' => $row['aircraftType'] ?? 'B738',
            'carrier' => $row['carrier'],
            'etd' => $row['etd'],
            'eta' => $row['eta'],
            'altitude' => intval($row['altitude'] ?? 35000),
            'speed' => intval($row['speed'] ?? 450),
            'route' => $row['route']
        ];
    }
    sqlsrv_free_stmt($stmt);
    
    echo json_encode([
        'status' => 'ok',
        'source' => count($flights) > 0 ? 'historical' : 'synthetic',
        'dest' => $dest,
        'date' => $date,
        'timeRange' => sprintf('%02d:00-%02d:00Z', $startHour, $endHour),
        'count' => count($flights),
        'flights' => count($flights) > 0 ? $flights : generateSyntheticFlights($dest, $startHour, $endHour, 60)
    ]);
}

/**
 * Generate a set of flights for a scenario
 * 
 * POST body:
 *   dest        - Destination airport (required)
 *   startHour   - Start hour UTC (required)
 *   endHour     - End hour UTC (required)
 *   targetCount - Approximate number of flights to generate
 *   demandLevel - 'light', 'normal', 'heavy', 'extreme' (default 'normal')
 */
function handleGenerate() {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        // Try GET params
        $data = [
            'dest' => $_GET['dest'] ?? '',
            'startHour' => intval($_GET['start_hour'] ?? 12),
            'endHour' => intval($_GET['end_hour'] ?? 18),
            'targetCount' => intval($_GET['count'] ?? 60),
            'demandLevel' => $_GET['level'] ?? 'normal'
        ];
    }
    
    $dest = strtoupper(trim($data['dest'] ?? ''));
    $startHour = intval($data['startHour'] ?? 12);
    $endHour = intval($data['endHour'] ?? 18);
    $targetCount = intval($data['targetCount'] ?? 60);
    $demandLevel = $data['demandLevel'] ?? 'normal';
    
    if (empty($dest)) {
        echo json_encode(['status' => 'error', 'message' => 'Missing dest parameter']);
        return;
    }
    
    if (strlen($dest) === 3) {
        $dest = 'K' . $dest;
    }
    
    // Adjust count based on demand level
    $levelMultipliers = [
        'light' => 0.5,
        'normal' => 1.0,
        'heavy' => 1.5,
        'extreme' => 2.0
    ];
    $multiplier = $levelMultipliers[$demandLevel] ?? 1.0;
    $adjustedCount = intval($targetCount * $multiplier);
    
    $flights = generateSyntheticFlights($dest, $startHour, $endHour, $adjustedCount);
    
    echo json_encode([
        'status' => 'ok',
        'dest' => $dest,
        'timeRange' => sprintf('%02d:00-%02d:00Z', $startHour, $endHour),
        'demandLevel' => $demandLevel,
        'requestedCount' => $targetCount,
        'generatedCount' => count($flights),
        'flights' => $flights
    ]);
}

/**
 * Get pre-built training scenarios
 */
function handleScenarios() {
    $scenarios = [
        [
            'id' => 'jfk_afternoon_rush',
            'name' => 'JFK Afternoon Rush',
            'description' => 'Heavy afternoon arrival bank at JFK with typical Northeast carriers',
            'dest' => 'KJFK',
            'startHour' => 14,
            'endHour' => 18,
            'targetCount' => 75,
            'demandLevel' => 'heavy',
            'suggestedAAR' => 44,
            'reducedAAR' => 28,
            'trainingFocus' => 'GDP timing and scope selection'
        ],
        [
            'id' => 'atl_weather_event',
            'name' => 'ATL Weather Event',
            'description' => 'Atlanta with convective weather reducing capacity',
            'dest' => 'KATL',
            'startHour' => 15,
            'endHour' => 20,
            'targetCount' => 100,
            'demandLevel' => 'heavy',
            'suggestedAAR' => 126,
            'reducedAAR' => 60,
            'trainingFocus' => 'Ground Stop decision-making'
        ],
        [
            'id' => 'sfo_marine_layer',
            'name' => 'SFO Marine Layer',
            'description' => 'San Francisco morning fog reducing to single runway operations',
            'dest' => 'KSFO',
            'startHour' => 13,
            'endHour' => 17,
            'targetCount' => 55,
            'demandLevel' => 'normal',
            'suggestedAAR' => 54,
            'reducedAAR' => 30,
            'trainingFocus' => 'Reduced capacity management'
        ],
        [
            'id' => 'ord_evening_push',
            'name' => 'ORD Evening Push',
            'description' => 'Chicago O\'Hare evening arrival push from west coast',
            'dest' => 'KORD',
            'startHour' => 18,
            'endHour' => 23,
            'targetCount' => 85,
            'demandLevel' => 'heavy',
            'suggestedAAR' => 108,
            'reducedAAR' => 70,
            'trainingFocus' => 'Multi-tier scope decisions'
        ],
        [
            'id' => 'lax_pacific_arrivals',
            'name' => 'LAX Pacific Arrivals',
            'description' => 'Los Angeles afternoon transpacific arrival wave',
            'dest' => 'KLAX',
            'startHour' => 14,
            'endHour' => 19,
            'targetCount' => 70,
            'demandLevel' => 'normal',
            'suggestedAAR' => 74,
            'reducedAAR' => 48,
            'trainingFocus' => 'Long-haul arrival sequencing'
        ],
        [
            'id' => 'dfw_volume',
            'name' => 'DFW High Volume',
            'description' => 'Dallas/Fort Worth with sustained high demand',
            'dest' => 'KDFW',
            'startHour' => 12,
            'endHour' => 18,
            'targetCount' => 90,
            'demandLevel' => 'heavy',
            'suggestedAAR' => 92,
            'reducedAAR' => 60,
            'trainingFocus' => 'Sustained demand management'
        ],
        [
            'id' => 'custom',
            'name' => 'Custom Scenario',
            'description' => 'Build your own scenario with custom parameters',
            'dest' => '',
            'startHour' => 12,
            'endHour' => 18,
            'targetCount' => 60,
            'demandLevel' => 'normal',
            'trainingFocus' => 'User-defined'
        ]
    ];
    
    echo json_encode([
        'status' => 'ok',
        'count' => count($scenarios),
        'scenarios' => $scenarios
    ]);
}

// ============================================================================
// Helper Functions
// ============================================================================

/**
 * Generate synthetic flights based on realistic patterns
 */
function generateSyntheticFlights($dest, $startHour, $endHour, $count) {
    $patterns = getFallbackPatterns($dest);
    $carriers = getFallbackCarriers();
    
    if (empty($patterns)) {
        return [];
    }
    
    $flights = [];
    $durationMinutes = ($endHour - $startHour) * 60;
    
    // Build weighted distribution of patterns
    $totalWeight = array_sum(array_column($patterns, 'frequency'));
    
    for ($i = 0; $i < $count; $i++) {
        // Select pattern based on frequency weighting
        $rand = mt_rand(1, max(1, $totalWeight));
        $cumulative = 0;
        $selectedPattern = $patterns[0];
        
        foreach ($patterns as $pattern) {
            $cumulative += $pattern['frequency'];
            if ($rand <= $cumulative) {
                $selectedPattern = $pattern;
                break;
            }
        }
        
        // Generate flight details
        $carrier = $selectedPattern['carrier'];
        $flightNum = mt_rand(100, 9999);
        $callsign = $carrier . $flightNum;
        
        // Distribute ETAs across the time window
        $etaOffsetMinutes = mt_rand(0, $durationMinutes);
        $etaHour = $startHour + intval($etaOffsetMinutes / 60);
        $etaMinute = $etaOffsetMinutes % 60;
        
        // Calculate ETD based on flight time
        $flightTimeMin = $selectedPattern['avgFlightTimeMin'] ?? 120;
        $flightTimeMin += mt_rand(-15, 15); // Add some variance
        
        $etdOffsetMinutes = $etaOffsetMinutes - $flightTimeMin;
        $etdHour = $startHour + intval($etdOffsetMinutes / 60);
        if ($etdHour < 0) $etdHour += 24;
        $etdMinute = abs($etdOffsetMinutes % 60);
        
        // Select aircraft type
        $aircraftTypes = ['B738', 'A320', 'B739', 'A321', 'E75L', 'B77W', 'B788', 'A319', 'CRJ9', 'E170'];
        $aircraftType = $selectedPattern['aircraftType'] ?? $aircraftTypes[array_rand($aircraftTypes)];
        
        // Cruise altitude based on distance (rough approximation)
        $altitudes = [31000, 33000, 35000, 37000, 39000, 41000];
        $altitude = $altitudes[array_rand($altitudes)];
        
        // Get route for this O-D pair
        $route = getRouteForPair($selectedPattern['origin'], $dest);
        
        $flights[] = [
            'callsign' => $callsign,
            'origin' => $selectedPattern['origin'],
            'destination' => $dest,
            'aircraftType' => $aircraftType,
            'carrier' => $carrier,
            'eta' => sprintf('%02d:%02d:00Z', $etaHour % 24, $etaMinute),
            'etd' => sprintf('%02d:%02d:00Z', $etdHour % 24, $etdMinute),
            'altitude' => $altitude,
            'speed' => mt_rand(440, 480),
            'flightTimeMin' => $flightTimeMin,
            'route' => $route['routeString'],
            'waypoints' => $route['waypoints']
        ];
    }
    
    // Sort by ETA
    usort($flights, function($a, $b) {
        return strcmp($a['eta'], $b['eta']);
    });
    
    return $flights;
}

/**
 * Fallback route patterns for major airports
 */
function getFallbackPatterns($dest) {
    $patterns = [
        'KJFK' => [
            ['origin' => 'KLAX', 'carrier' => 'DAL', 'frequency' => 12, 'avgFlightTimeMin' => 330],
            ['origin' => 'KSFO', 'carrier' => 'JBU', 'frequency' => 10, 'avgFlightTimeMin' => 320],
            ['origin' => 'KMIA', 'carrier' => 'AAL', 'frequency' => 10, 'avgFlightTimeMin' => 180],
            ['origin' => 'KORD', 'carrier' => 'UAL', 'frequency' => 9, 'avgFlightTimeMin' => 150],
            ['origin' => 'KATL', 'carrier' => 'DAL', 'frequency' => 9, 'avgFlightTimeMin' => 140],
            ['origin' => 'KDFW', 'carrier' => 'AAL', 'frequency' => 8, 'avgFlightTimeMin' => 210],
            ['origin' => 'KBOS', 'carrier' => 'JBU', 'frequency' => 8, 'avgFlightTimeMin' => 60],
            ['origin' => 'KDCA', 'carrier' => 'JBU', 'frequency' => 7, 'avgFlightTimeMin' => 70],
            ['origin' => 'KDEN', 'carrier' => 'JBU', 'frequency' => 6, 'avgFlightTimeMin' => 240],
            ['origin' => 'KSEA', 'carrier' => 'DAL', 'frequency' => 5, 'avgFlightTimeMin' => 330],
            ['origin' => 'KLAS', 'carrier' => 'JBU', 'frequency' => 5, 'avgFlightTimeMin' => 300],
            ['origin' => 'KMCO', 'carrier' => 'JBU', 'frequency' => 5, 'avgFlightTimeMin' => 160],
            ['origin' => 'KFLL', 'carrier' => 'JBU', 'frequency' => 5, 'avgFlightTimeMin' => 180],
            ['origin' => 'KCLT', 'carrier' => 'AAL', 'frequency' => 4, 'avgFlightTimeMin' => 100],
            ['origin' => 'KPHL', 'carrier' => 'AAL', 'frequency' => 4, 'avgFlightTimeMin' => 40],
        ],
        'KATL' => [
            ['origin' => 'KLAX', 'carrier' => 'DAL', 'frequency' => 14, 'avgFlightTimeMin' => 270],
            ['origin' => 'KJFK', 'carrier' => 'DAL', 'frequency' => 12, 'avgFlightTimeMin' => 140],
            ['origin' => 'KORD', 'carrier' => 'DAL', 'frequency' => 11, 'avgFlightTimeMin' => 120],
            ['origin' => 'KDFW', 'carrier' => 'DAL', 'frequency' => 10, 'avgFlightTimeMin' => 150],
            ['origin' => 'KMIA', 'carrier' => 'DAL', 'frequency' => 9, 'avgFlightTimeMin' => 110],
            ['origin' => 'KDEN', 'carrier' => 'DAL', 'frequency' => 8, 'avgFlightTimeMin' => 180],
            ['origin' => 'KSFO', 'carrier' => 'DAL', 'frequency' => 7, 'avgFlightTimeMin' => 290],
            ['origin' => 'KBOS', 'carrier' => 'DAL', 'frequency' => 7, 'avgFlightTimeMin' => 160],
            ['origin' => 'KEWR', 'carrier' => 'DAL', 'frequency' => 6, 'avgFlightTimeMin' => 140],
            ['origin' => 'KMSP', 'carrier' => 'DAL', 'frequency' => 6, 'avgFlightTimeMin' => 150],
            ['origin' => 'KDCA', 'carrier' => 'DAL', 'frequency' => 5, 'avgFlightTimeMin' => 100],
            ['origin' => 'KSLC', 'carrier' => 'DAL', 'frequency' => 5, 'avgFlightTimeMin' => 210],
            ['origin' => 'KSEA', 'carrier' => 'DAL', 'frequency' => 5, 'avgFlightTimeMin' => 300],
        ],
        'KSFO' => [
            ['origin' => 'KLAX', 'carrier' => 'UAL', 'frequency' => 15, 'avgFlightTimeMin' => 80],
            ['origin' => 'KJFK', 'carrier' => 'UAL', 'frequency' => 10, 'avgFlightTimeMin' => 320],
            ['origin' => 'KDEN', 'carrier' => 'UAL', 'frequency' => 9, 'avgFlightTimeMin' => 150],
            ['origin' => 'KORD', 'carrier' => 'UAL', 'frequency' => 8, 'avgFlightTimeMin' => 250],
            ['origin' => 'KSEA', 'carrier' => 'UAL', 'frequency' => 7, 'avgFlightTimeMin' => 120],
            ['origin' => 'KSAN', 'carrier' => 'UAL', 'frequency' => 7, 'avgFlightTimeMin' => 70],
            ['origin' => 'KLAS', 'carrier' => 'UAL', 'frequency' => 6, 'avgFlightTimeMin' => 80],
            ['origin' => 'KPHX', 'carrier' => 'UAL', 'frequency' => 5, 'avgFlightTimeMin' => 100],
            ['origin' => 'KDFW', 'carrier' => 'UAL', 'frequency' => 5, 'avgFlightTimeMin' => 200],
            ['origin' => 'KEWR', 'carrier' => 'UAL', 'frequency' => 5, 'avgFlightTimeMin' => 320],
        ],
        'KORD' => [
            ['origin' => 'KLAX', 'carrier' => 'UAL', 'frequency' => 12, 'avgFlightTimeMin' => 240],
            ['origin' => 'KSFO', 'carrier' => 'UAL', 'frequency' => 10, 'avgFlightTimeMin' => 250],
            ['origin' => 'KJFK', 'carrier' => 'AAL', 'frequency' => 9, 'avgFlightTimeMin' => 150],
            ['origin' => 'KEWR', 'carrier' => 'UAL', 'frequency' => 8, 'avgFlightTimeMin' => 140],
            ['origin' => 'KDEN', 'carrier' => 'UAL', 'frequency' => 8, 'avgFlightTimeMin' => 150],
            ['origin' => 'KDFW', 'carrier' => 'AAL', 'frequency' => 7, 'avgFlightTimeMin' => 150],
            ['origin' => 'KMIA', 'carrier' => 'AAL', 'frequency' => 6, 'avgFlightTimeMin' => 180],
            ['origin' => 'KATL', 'carrier' => 'DAL', 'frequency' => 6, 'avgFlightTimeMin' => 120],
            ['origin' => 'KBOS', 'carrier' => 'UAL', 'frequency' => 5, 'avgFlightTimeMin' => 150],
            ['origin' => 'KSEA', 'carrier' => 'UAL', 'frequency' => 5, 'avgFlightTimeMin' => 250],
        ],
        'KLAX' => [
            ['origin' => 'KSFO', 'carrier' => 'UAL', 'frequency' => 15, 'avgFlightTimeMin' => 80],
            ['origin' => 'KJFK', 'carrier' => 'DAL', 'frequency' => 12, 'avgFlightTimeMin' => 330],
            ['origin' => 'KORD', 'carrier' => 'AAL', 'frequency' => 9, 'avgFlightTimeMin' => 240],
            ['origin' => 'KDEN', 'carrier' => 'UAL', 'frequency' => 8, 'avgFlightTimeMin' => 150],
            ['origin' => 'KDFW', 'carrier' => 'AAL', 'frequency' => 8, 'avgFlightTimeMin' => 180],
            ['origin' => 'KPHX', 'carrier' => 'AAL', 'frequency' => 7, 'avgFlightTimeMin' => 70],
            ['origin' => 'KLAS', 'carrier' => 'DAL', 'frequency' => 6, 'avgFlightTimeMin' => 60],
            ['origin' => 'KSEA', 'carrier' => 'DAL', 'frequency' => 6, 'avgFlightTimeMin' => 150],
            ['origin' => 'KATL', 'carrier' => 'DAL', 'frequency' => 6, 'avgFlightTimeMin' => 270],
            ['origin' => 'KSAN', 'carrier' => 'SWA', 'frequency' => 5, 'avgFlightTimeMin' => 60],
        ],
        'KDFW' => [
            ['origin' => 'KLAX', 'carrier' => 'AAL', 'frequency' => 12, 'avgFlightTimeMin' => 180],
            ['origin' => 'KJFK', 'carrier' => 'AAL', 'frequency' => 10, 'avgFlightTimeMin' => 210],
            ['origin' => 'KORD', 'carrier' => 'AAL', 'frequency' => 9, 'avgFlightTimeMin' => 150],
            ['origin' => 'KMIA', 'carrier' => 'AAL', 'frequency' => 8, 'avgFlightTimeMin' => 180],
            ['origin' => 'KSFO', 'carrier' => 'AAL', 'frequency' => 7, 'avgFlightTimeMin' => 200],
            ['origin' => 'KDEN', 'carrier' => 'AAL', 'frequency' => 7, 'avgFlightTimeMin' => 120],
            ['origin' => 'KPHX', 'carrier' => 'AAL', 'frequency' => 6, 'avgFlightTimeMin' => 120],
            ['origin' => 'KATL', 'carrier' => 'AAL', 'frequency' => 6, 'avgFlightTimeMin' => 150],
            ['origin' => 'KLAS', 'carrier' => 'AAL', 'frequency' => 5, 'avgFlightTimeMin' => 150],
            ['origin' => 'KSEA', 'carrier' => 'AAL', 'frequency' => 5, 'avgFlightTimeMin' => 220],
        ],
    ];
    
    // For airports not in the list, generate generic patterns
    if (!isset($patterns[$dest])) {
        $defaultOrigins = ['KJFK', 'KLAX', 'KORD', 'KATL', 'KDFW', 'KDEN', 'KSFO', 'KMIA', 'KBOS', 'KSEA'];
        $carriers = ['DAL', 'UAL', 'AAL', 'SWA', 'JBU'];
        
        $generated = [];
        foreach ($defaultOrigins as $i => $origin) {
            if ($origin !== $dest) {
                $generated[] = [
                    'origin' => $origin,
                    'carrier' => $carriers[$i % count($carriers)],
                    'frequency' => 10 - $i,
                    'avgFlightTimeMin' => 150 + mt_rand(-30, 60)
                ];
            }
        }
        return $generated;
    }
    
    // Add destination to each pattern
    return array_map(function($p) use ($dest) {
        $p['dest'] = $dest;
        return $p;
    }, $patterns[$dest]);
}

/**
 * Fallback demand profile (generic pattern)
 */
function getFallbackDemand($airport) {
    // Major hub peak hours differ slightly
    $hubPeaks = [
        'KJFK' => [8, 14, 18],
        'KATL' => [7, 12, 17, 21],
        'KORD' => [7, 12, 17, 21],
        'KLAX' => [8, 14, 22],
        'KDFW' => [7, 12, 17, 21],
        'KDEN' => [8, 13, 18],
        'KSFO' => [9, 14, 18],
    ];
    
    $peaks = $hubPeaks[$airport] ?? [8, 14, 18];
    $hourly = [];
    
    for ($h = 0; $h < 24; $h++) {
        // Base demand varies by time
        $base = 10;
        
        // Increase during peak hours
        foreach ($peaks as $peak) {
            $dist = abs($h - $peak);
            if ($dist <= 2) {
                $base += (20 - $dist * 5);
            }
        }
        
        // Night reduction (0-5 UTC is typically low)
        if ($h >= 0 && $h <= 5) {
            $base = max(5, $base * 0.3);
        }
        
        $hourly[] = [
            'hour' => $h,
            'avgArrivals' => round($base, 1),
            'avgDepartures' => round($base * 0.9, 1),
            'peakArrivals' => intval($base * 1.5),
            'peakDepartures' => intval($base * 1.4)
        ];
    }
    
    return $hourly;
}

/**
 * Fallback carrier data
 */
function getFallbackCarriers() {
    return [
        ['icao' => 'AAL', 'name' => 'American Airlines', 'callsign' => 'AMERICAN', 'hubs' => 'KDFW,KCLT,KMIA,KORD,KPHL,KPHX'],
        ['icao' => 'DAL', 'name' => 'Delta Air Lines', 'callsign' => 'DELTA', 'hubs' => 'KATL,KDTW,KJFK,KLAX,KMSP,KSEA,KBOS'],
        ['icao' => 'UAL', 'name' => 'United Airlines', 'callsign' => 'UNITED', 'hubs' => 'KORD,KDEN,KEWR,KIAH,KLAX,KSFO'],
        ['icao' => 'SWA', 'name' => 'Southwest Airlines', 'callsign' => 'SOUTHWEST', 'hubs' => 'KBWI,KDAL,KDEN,KLAS,KMDW,KOAK,KPHX'],
        ['icao' => 'JBU', 'name' => 'JetBlue Airways', 'callsign' => 'JETBLUE', 'hubs' => 'KJFK,KBOS,KFLL'],
        ['icao' => 'ASA', 'name' => 'Alaska Airlines', 'callsign' => 'ALASKA', 'hubs' => 'KSEA,KPDX,KLAX'],
        ['icao' => 'FFT', 'name' => 'Frontier Airlines', 'callsign' => 'FRONTIER', 'hubs' => 'KDEN'],
        ['icao' => 'NKS', 'name' => 'Spirit Airlines', 'callsign' => 'SPIRIT', 'hubs' => 'KFLL,KLAS'],
        ['icao' => 'SKW', 'name' => 'SkyWest Airlines', 'callsign' => 'SKYWEST', 'hubs' => 'Regional'],
        ['icao' => 'RPA', 'name' => 'Republic Airways', 'callsign' => 'BRICKYARD', 'hubs' => 'Regional'],
        ['icao' => 'ENY', 'name' => 'Envoy Air', 'callsign' => 'ENVOY', 'hubs' => 'Regional'],
        ['icao' => 'PDT', 'name' => 'Piedmont Airlines', 'callsign' => 'PIEDMONT', 'hubs' => 'Regional'],
        ['icao' => 'FDX', 'name' => 'FedEx Express', 'callsign' => 'FEDEX', 'hubs' => 'KMEM'],
        ['icao' => 'UPS', 'name' => 'UPS Airlines', 'callsign' => 'UPS', 'hubs' => 'KSDF'],
        ['icao' => 'GTI', 'name' => 'Atlas Air', 'callsign' => 'GIANT', 'hubs' => 'Cargo'],
        ['icao' => 'HAL', 'name' => 'Hawaiian Airlines', 'callsign' => 'HAWAIIAN', 'hubs' => 'PHNL'],
        ['icao' => 'VXS', 'name' => 'Avelo Airlines', 'callsign' => 'AVELO', 'hubs' => 'KHWD,KBUR'],
    ];
}

/**
 * Get route and waypoints for an O-D pair
 * Uses sequential proximity resolution like sp_ParseRoute
 */
function getRouteForPair($origin, $dest) {
    // Get common route or use direct
    $routeString = getCommonRouteInternal($origin, $dest) ?: 'DCT';
    
    // Expand to waypoints using sequential proximity resolution
    $waypoints = [];
    
    // Start with origin coordinates
    $prevLat = null;
    $prevLon = null;
    
    // Add origin
    $originCoords = getFixCoordinatesProximity($origin, null, null);
    if ($originCoords) {
        $waypoints[] = [
            'name' => $origin,
            'lat' => $originCoords['lat'],
            'lon' => $originCoords['lon'],
            'type' => 'AIRPORT'
        ];
        $prevLat = $originCoords['lat'];
        $prevLon = $originCoords['lon'];
    }
    
    // Parse route and add intermediate fixes with sequential proximity resolution
    if ($routeString !== 'DCT') {
        $elements = preg_split('/\s+/', $routeString);
        foreach ($elements as $element) {
            // Skip airways, SIDs/STARs, DCT
            if (empty($element) || $element === 'DCT') continue;
            if (preg_match('/^[JQTV]\d+$/', $element)) continue;
            if (preg_match('/\d$/', $element) && strlen($element) > 5) continue;
            
            // Use previous fix's coordinates for proximity resolution
            $coords = getFixCoordinatesProximity($element, $prevLat, $prevLon);
            if ($coords) {
                $waypoints[] = [
                    'name' => $element,
                    'lat' => $coords['lat'],
                    'lon' => $coords['lon'],
                    'type' => $coords['type'] ?? 'FIX'
                ];
                // Update previous coordinates for next iteration
                $prevLat = $coords['lat'];
                $prevLon = $coords['lon'];
            }
        }
    }
    
    // Add destination using proximity to last waypoint
    $destCoords = getFixCoordinatesProximity($dest, $prevLat, $prevLon);
    if ($destCoords) {
        $waypoints[] = [
            'name' => $dest,
            'lat' => $destCoords['lat'],
            'lon' => $destCoords['lon'],
            'type' => 'AIRPORT'
        ];
    }
    
    return [
        'routeString' => $routeString,
        'waypoints' => $waypoints
    ];
}

function getCommonRouteInternal($origin, $dest) {
    $routes = [
        // Northeast to/from Southeast
        'KJFK-KATL' => 'MERIT SBJ NAVHO',
        'KATL-KJFK' => 'JACCC CUTTN MERIT',
        'KJFK-KMIA' => 'MERIT SBJ SAV CEBEE',
        'KMIA-KJFK' => 'WINCO SAV SBJ MERIT',
        'KBOS-KATL' => 'HAYED SBJ NAVHO',
        'KATL-KBOS' => 'JACCC CUTTN HAYED',
        
        // Northeast to/from Midwest
        'KJFK-KORD' => 'MERIT AIR DENNT',
        'KORD-KJFK' => 'EARND AIR MERIT',
        'KBOS-KORD' => 'HAYED AIR DENNT',
        'KORD-KBOS' => 'EARND AIR HAYED',
        
        // Northeast to/from West
        'KJFK-KLAX' => 'BIGGY SLT PKE DAGGZ',
        'KLAX-KJFK' => 'DOTSS SLT BIGGY',
        'KJFK-KSFO' => 'BIGGY SLT PKE SERFR',
        'KSFO-KJFK' => 'SSTIK SLT BIGGY',
        
        // Midwest to/from Southeast
        'KORD-KATL' => 'EARND HNN NAVHO',
        'KATL-KORD' => 'JACCC HNN DENNT',
        'KORD-KMIA' => 'EARND HNN SAV',
        'KMIA-KORD' => 'WINCO SAV HNN DENNT',
        
        // Midwest to/from West
        'KORD-KLAX' => 'EARND DBQ OBH DAGGZ',
        'KLAX-KORD' => 'DOTSS OBH DBQ DENNT',
        'KORD-KSFO' => 'EARND DBQ OBH SERFR',
        'KSFO-KORD' => 'SSTIK OBH DBQ DENNT',
        
        // West Coast
        'KLAX-KSFO' => 'VNY AVE SERFR',
        'KSFO-KLAX' => 'SSTIK AVE SEAVU',
        'KLAX-KSEA' => 'VNY AVE OAL HAWKZ',
        'KSEA-KLAX' => 'SUMMA TOU AVE SEAVU',
        
        // Southeast to/from West
        'KATL-KLAX' => 'JACCC MEI DFW DAGGZ',
        'KLAX-KATL' => 'DOTSS DFW MEI NAVHO',
        'KMIA-KLAX' => 'WINCO MEI DFW DAGGZ',
        'KLAX-KMIA' => 'DOTSS DFW MEI SAV',
        
        // DFW Hub
        'KDFW-KJFK' => 'AKUNA MEM SBJ MERIT',
        'KJFK-KDFW' => 'MERIT SBJ MEM FINGR',
        'KDFW-KORD' => 'AKUNA STL DENNT',
        'KORD-KDFW' => 'EARND STL FINGR',
        'KDFW-KLAX' => 'LOWGN DAGGZ',
        'KLAX-KDFW' => 'DOTSS FINGR',
        
        // DEN Hub
        'KDEN-KJFK' => 'TOMSN SLT PKE MERIT',
        'KJFK-KDEN' => 'BIGGY SLT RAMMS',
        'KDEN-KORD' => 'TOMSN OBH DENNT',
        'KORD-KDEN' => 'EARND OBH RAMMS',
        'KDEN-KLAX' => 'TOMSN DAGGZ',
        'KLAX-KDEN' => 'DOTSS RAMMS',
        
        // Additional common pairs
        'KEWR-KATL' => 'BIGGY SBJ NAVHO',
        'KATL-KEWR' => 'JACCC SBJ BIGGY',
        'KLGA-KATL' => 'ELIOT SBJ NAVHO',
        'KATL-KLGA' => 'JACCC SBJ ELIOT',
    ];
    
    return $routes[$origin . '-' . $dest] ?? null;
}

/**
 * Get fix coordinates using sequential proximity resolution
 * If prevLat/prevLon provided, picks the closest candidate to those coordinates
 * Falls back to CONUS bounding box if no previous coordinates
 * This mirrors the logic in sp_ParseRoute v4.1
 */
function getFixCoordinatesProximity($fix, $prevLat, $prevLon) {
    global $conn_adl;
    
    // Try database with proximity-based resolution
    if ($conn_adl) {
        if ($prevLat !== null && $prevLon !== null) {
            // Use previous coordinates for proximity resolution
            // Order by distance to previous fix (using simplified Euclidean for speed)
            $sql = "SELECT TOP 1 lat, lon, fix_type 
                    FROM nav_fixes 
                    WHERE fix_name = ? 
                    AND lat BETWEEN 24 AND 50 
                    AND lon BETWEEN -125 AND -66
                    ORDER BY 
                        POWER(lat - ?, 2) + POWER((lon - ?) * COS(RADIANS(?)), 2)";
            $stmt = sqlsrv_query($conn_adl, $sql, [$fix, $prevLat, $prevLon, $prevLat]);
        } else {
            // No previous coordinates - just use CONUS bounds + type priority
            $sql = "SELECT TOP 1 lat, lon, fix_type 
                    FROM nav_fixes 
                    WHERE fix_name = ? 
                    AND lat BETWEEN 24 AND 50 
                    AND lon BETWEEN -125 AND -66
                    ORDER BY 
                        CASE fix_type WHEN 'AIRPORT' THEN 0 WHEN 'VORTAC' THEN 1 WHEN 'VOR' THEN 2 ELSE 3 END";
            $stmt = sqlsrv_query($conn_adl, $sql, [$fix]);
        }
        
        if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            sqlsrv_free_stmt($stmt);
            return [
                'lat' => floatval($row['lat']),
                'lon' => floatval($row['lon']),
                'type' => $row['fix_type']
            ];
        }
    }
    
    // Fall back to hardcoded CONUS fixes
    return getFixCoordinatesInternal($fix);
}

/**
 * Fallback function for hardcoded fix coordinates
 * Used when database lookup fails or is unavailable
 */
function getFixCoordinatesInternal($fix) {
    // Hardcoded CONUS fixes for fallback
    $known = [
        // Airports
        'KATL' => ['lat' => 33.6407, 'lon' => -84.4277, 'type' => 'AIRPORT'],
        'KORD' => ['lat' => 41.9742, 'lon' => -87.9073, 'type' => 'AIRPORT'],
        'KDFW' => ['lat' => 32.8998, 'lon' => -97.0403, 'type' => 'AIRPORT'],
        'KDEN' => ['lat' => 39.8561, 'lon' => -104.6737, 'type' => 'AIRPORT'],
        'KJFK' => ['lat' => 40.6413, 'lon' => -73.7781, 'type' => 'AIRPORT'],
        'KLAX' => ['lat' => 33.9425, 'lon' => -118.4081, 'type' => 'AIRPORT'],
        'KSFO' => ['lat' => 37.6213, 'lon' => -122.3790, 'type' => 'AIRPORT'],
        'KSEA' => ['lat' => 47.4502, 'lon' => -122.3088, 'type' => 'AIRPORT'],
        'KMIA' => ['lat' => 25.7959, 'lon' => -80.2870, 'type' => 'AIRPORT'],
        'KBOS' => ['lat' => 42.3656, 'lon' => -71.0096, 'type' => 'AIRPORT'],
        'KEWR' => ['lat' => 40.6895, 'lon' => -74.1745, 'type' => 'AIRPORT'],
        'KLGA' => ['lat' => 40.7769, 'lon' => -73.8740, 'type' => 'AIRPORT'],
        'KPHL' => ['lat' => 39.8719, 'lon' => -75.2411, 'type' => 'AIRPORT'],
        'KDCA' => ['lat' => 38.8521, 'lon' => -77.0402, 'type' => 'AIRPORT'],
        'KIAD' => ['lat' => 38.9531, 'lon' => -77.4565, 'type' => 'AIRPORT'],
        'KCLT' => ['lat' => 35.2140, 'lon' => -80.9431, 'type' => 'AIRPORT'],
        'KMSP' => ['lat' => 44.8848, 'lon' => -93.2223, 'type' => 'AIRPORT'],
        'KDTW' => ['lat' => 42.2162, 'lon' => -83.3554, 'type' => 'AIRPORT'],
        'KPHX' => ['lat' => 33.4373, 'lon' => -112.0078, 'type' => 'AIRPORT'],
        'KLAS' => ['lat' => 36.0840, 'lon' => -115.1537, 'type' => 'AIRPORT'],
        'KIAH' => ['lat' => 29.9902, 'lon' => -95.3368, 'type' => 'AIRPORT'],
        'KMCO' => ['lat' => 28.4312, 'lon' => -81.3081, 'type' => 'AIRPORT'],
        'KFLL' => ['lat' => 26.0742, 'lon' => -80.1506, 'type' => 'AIRPORT'],
        'KTPA' => ['lat' => 27.9756, 'lon' => -82.5333, 'type' => 'AIRPORT'],
        'KSAN' => ['lat' => 32.7336, 'lon' => -117.1897, 'type' => 'AIRPORT'],
        'KPDX' => ['lat' => 45.5898, 'lon' => -122.5951, 'type' => 'AIRPORT'],
        'KSLC' => ['lat' => 40.7884, 'lon' => -111.9778, 'type' => 'AIRPORT'],
        
        // Key fixes
        'MERIT' => ['lat' => 40.2500, 'lon' => -73.6667, 'type' => 'FIX'],
        'BIGGY' => ['lat' => 40.4000, 'lon' => -74.2167, 'type' => 'FIX'],
        'HAYED' => ['lat' => 42.1833, 'lon' => -71.2333, 'type' => 'FIX'],
        'JACCC' => ['lat' => 33.8833, 'lon' => -84.3000, 'type' => 'FIX'],
        'CUTTN' => ['lat' => 34.1167, 'lon' => -84.1500, 'type' => 'FIX'],
        'NAVHO' => ['lat' => 33.5167, 'lon' => -84.6667, 'type' => 'FIX'],
        'WINCO' => ['lat' => 25.9000, 'lon' => -80.1500, 'type' => 'FIX'],
        'EARND' => ['lat' => 41.8667, 'lon' => -87.5833, 'type' => 'FIX'],
        'DENNT' => ['lat' => 41.9833, 'lon' => -87.8333, 'type' => 'FIX'],
        'DOTSS' => ['lat' => 33.7833, 'lon' => -118.4500, 'type' => 'FIX'],
        'DAGGZ' => ['lat' => 34.8500, 'lon' => -116.8500, 'type' => 'FIX'],
        'SSTIK' => ['lat' => 37.4167, 'lon' => -122.2500, 'type' => 'FIX'],
        'SERFR' => ['lat' => 37.2500, 'lon' => -122.0000, 'type' => 'FIX'],
        'SEAVU' => ['lat' => 33.8500, 'lon' => -118.5000, 'type' => 'FIX'],
        'TOMSN' => ['lat' => 39.7000, 'lon' => -104.5000, 'type' => 'FIX'],
        'RAMMS' => ['lat' => 39.6500, 'lon' => -104.8500, 'type' => 'FIX'],
        'LOWGN' => ['lat' => 33.0833, 'lon' => -97.2000, 'type' => 'FIX'],
        'FINGR' => ['lat' => 32.8333, 'lon' => -97.0000, 'type' => 'FIX'],
        'AKUNA' => ['lat' => 33.0000, 'lon' => -96.8500, 'type' => 'FIX'],
        'CEBEE' => ['lat' => 26.5000, 'lon' => -80.2000, 'type' => 'FIX'],
        'ELIOT' => ['lat' => 40.7000, 'lon' => -73.9000, 'type' => 'FIX'],
        'HAWKZ' => ['lat' => 47.5000, 'lon' => -122.5000, 'type' => 'FIX'],
        'SUMMA' => ['lat' => 47.4000, 'lon' => -122.4000, 'type' => 'FIX'],
        
        // VORs
        'SLT' => ['lat' => 40.8500, 'lon' => -111.9667, 'type' => 'VOR'],
        'PKE' => ['lat' => 40.9667, 'lon' => -118.5667, 'type' => 'VOR'],
        'OBH' => ['lat' => 41.4500, 'lon' => -100.6833, 'type' => 'VOR'],
        'DBQ' => ['lat' => 42.4000, 'lon' => -90.7000, 'type' => 'VOR'],
        'SBJ' => ['lat' => 39.8500, 'lon' => -74.5333, 'type' => 'VOR'],
        'HNN' => ['lat' => 38.4333, 'lon' => -82.6667, 'type' => 'VOR'],
        'AIR' => ['lat' => 40.4667, 'lon' => -80.7500, 'type' => 'VOR'],
        'MEM' => ['lat' => 35.0500, 'lon' => -89.9833, 'type' => 'VOR'],
        'MEI' => ['lat' => 32.3333, 'lon' => -88.7500, 'type' => 'VOR'],
        'SAV' => ['lat' => 32.1333, 'lon' => -81.2000, 'type' => 'VOR'],
        'AVE' => ['lat' => 34.9500, 'lon' => -118.4333, 'type' => 'VOR'],
        'STL' => ['lat' => 38.8667, 'lon' => -90.4833, 'type' => 'VOR'],
        'DFW' => ['lat' => 32.8500, 'lon' => -97.0333, 'type' => 'VOR'],
        'VNY' => ['lat' => 34.2167, 'lon' => -118.4833, 'type' => 'VOR'],
        'OAL' => ['lat' => 40.0833, 'lon' => -122.5000, 'type' => 'VOR'],
        'TOU' => ['lat' => 44.0000, 'lon' => -122.0000, 'type' => 'VOR'],
    ];
    
    return $known[$fix] ?? null;
}
