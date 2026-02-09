<?php
/**
 * import_osm_airport_geometry.php
 * 
 * Imports airport geometry from OpenStreetMap via Overpass API
 * Targets: ASPM82 + Canada + Mexico + Latin America + Caribbean
 * 
 * Usage: php import_osm_airport_geometry.php [--airport=ICAO] [--dry-run] [--start-from=ICAO]
 */

// Use the main config file
require_once __DIR__ . '/../../load/config.php';

// ============================================================================
// DATABASE CONNECTION (Azure SQL via PDO or sqlsrv)
// ============================================================================

function getAdlConnection() {
    if (!defined('ADL_SQL_HOST') || !defined('ADL_SQL_DATABASE') || 
        !defined('ADL_SQL_USERNAME') || !defined('ADL_SQL_PASSWORD')) {
        throw new Exception("ADL_SQL_* constants not defined in config.php");
    }
    
    // Try PDO first (more commonly available)
    if (extension_loaded('pdo_sqlsrv')) {
        $dsn = "sqlsrv:Server=" . ADL_SQL_HOST . ";Database=" . ADL_SQL_DATABASE;
        $pdo = new PDO($dsn, ADL_SQL_USERNAME, ADL_SQL_PASSWORD);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return ['type' => 'pdo', 'conn' => $pdo];
    }
    
    // Fall back to sqlsrv
    if (function_exists('sqlsrv_connect')) {
        $connectionInfo = [
            "Database" => ADL_SQL_DATABASE,
            "UID"      => ADL_SQL_USERNAME,
            "PWD"      => ADL_SQL_PASSWORD,
            "CharacterSet" => "UTF-8"
        ];
        
        $conn = sqlsrv_connect(ADL_SQL_HOST, $connectionInfo);
        
        if ($conn === false) {
            $errors = sqlsrv_errors();
            $msg = $errors ? $errors[0]['message'] : 'Unknown error';
            throw new Exception("Connection failed: $msg");
        }
        
        return ['type' => 'sqlsrv', 'conn' => $conn];
    }
    
    // Try ODBC as last resort
    if (extension_loaded('pdo_odbc')) {
        $dsn = "odbc:Driver={ODBC Driver 17 for SQL Server};Server=" . ADL_SQL_HOST . ";Database=" . ADL_SQL_DATABASE;
        $pdo = new PDO($dsn, ADL_SQL_USERNAME, ADL_SQL_PASSWORD);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return ['type' => 'pdo', 'conn' => $pdo];
    }
    
    throw new Exception("No SQL Server extension available. Install pdo_sqlsrv, sqlsrv, or pdo_odbc.");
}

// ============================================================================
// AIRPORT LIST - 201 airports
// ============================================================================

$AIRPORTS = [
    // ASPM82 - FAA Core 82 Airports
    'KATL' => 'Atlanta Hartsfield-Jackson',
    'KBOS' => 'Boston Logan',
    'KBWI' => 'Baltimore-Washington',
    'KCLE' => 'Cleveland Hopkins',
    'KCLT' => 'Charlotte Douglas',
    'KCVG' => 'Cincinnati/Northern Kentucky',
    'KDCA' => 'Washington Reagan National',
    'KDEN' => 'Denver International',
    'KDFW' => 'Dallas/Fort Worth',
    'KDTW' => 'Detroit Metro Wayne County',
    'KEWR' => 'Newark Liberty',
    'KFLL' => 'Fort Lauderdale-Hollywood',
    'KHNL' => 'Honolulu',
    'KHOU' => 'Houston Hobby',
    'KHPN' => 'Westchester County',
    'KIAD' => 'Washington Dulles',
    'KIAH' => 'Houston Bush Intercontinental',
    'KISP' => 'Long Island MacArthur',
    'KJFK' => 'New York JFK',
    'KLAS' => 'Las Vegas Harry Reid',
    'KLAX' => 'Los Angeles International',
    'KLGA' => 'New York LaGuardia',
    'KMCI' => 'Kansas City International',
    'KMCO' => 'Orlando International',
    'KMDW' => 'Chicago Midway',
    'KMEM' => 'Memphis International',
    'KMIA' => 'Miami International',
    'KMKE' => 'Milwaukee Mitchell',
    'KMSP' => 'Minneapolis-St Paul',
    'KMSY' => 'New Orleans Louis Armstrong',
    'KOAK' => 'Oakland International',
    'KONT' => 'Ontario International',
    'KORD' => 'Chicago O\'Hare',
    'KPBI' => 'Palm Beach International',
    'KPDX' => 'Portland International',
    'KPHL' => 'Philadelphia International',
    'KPHX' => 'Phoenix Sky Harbor',
    'KPIT' => 'Pittsburgh International',
    'KPVD' => 'Providence T.F. Green',
    'KRDU' => 'Raleigh-Durham',
    'KRSW' => 'Southwest Florida International',
    'KSAN' => 'San Diego International',
    'KSAT' => 'San Antonio International',
    'KSDF' => 'Louisville Muhammad Ali',
    'KSEA' => 'Seattle-Tacoma',
    'KSFO' => 'San Francisco International',
    'KSJC' => 'San Jose Norman Mineta',
    'KSLC' => 'Salt Lake City',
    'KSMF' => 'Sacramento International',
    'KSNA' => 'John Wayne Orange County',
    'KSTL' => 'St. Louis Lambert',
    'KSWF' => 'New York Stewart',
    'KTEB' => 'Teterboro',
    'KTPA' => 'Tampa International',
    'KAUS' => 'Austin-Bergstrom',
    'KABQ' => 'Albuquerque International',
    'KANC' => 'Anchorage Ted Stevens',
    'KBDL' => 'Hartford Bradley',
    'KBNA' => 'Nashville International',
    'KBUF' => 'Buffalo Niagara',
    'KBUR' => 'Hollywood Burbank',
    'KCHS' => 'Charleston International',
    'KCMH' => 'Columbus John Glenn',
    'KDAL' => 'Dallas Love Field',
    'KGSO' => 'Piedmont Triad',
    'KIND' => 'Indianapolis International',
    'KJAX' => 'Jacksonville International',
    'KMHT' => 'Manchester-Boston Regional',
    'KOMA' => 'Omaha Eppley',
    'KORF' => 'Norfolk International',
    'KPWM' => 'Portland International Jetport',
    'KRNO' => 'Reno-Tahoe',
    'KRIC' => 'Richmond International',
    'KSAV' => 'Savannah/Hilton Head',
    'KSYR' => 'Syracuse Hancock',
    'KTUL' => 'Tulsa International',
    
    // CANADA - Major Airports
    'CYYZ' => 'Toronto Pearson',
    'CYVR' => 'Vancouver International',
    'CYUL' => 'Montreal Trudeau',
    'CYYC' => 'Calgary International',
    'CYOW' => 'Ottawa Macdonald-Cartier',
    'CYEG' => 'Edmonton International',
    'CYWG' => 'Winnipeg James Richardson',
    'CYHZ' => 'Halifax Stanfield',
    'CYQB' => 'Quebec City Jean Lesage',
    'CYYJ' => 'Victoria International',
    'CYXE' => 'Saskatoon John G. Diefenbaker',
    'CYQR' => 'Regina International',
    'CYYT' => 'St. John\'s International',
    'CYTZ' => 'Toronto Billy Bishop',
    'CYQM' => 'Moncton Greater',
    'CYZF' => 'Yellowknife',
    'CYXY' => 'Whitehorse',
    
    // MEXICO - Major Airports
    'MMMX' => 'Mexico City Benito Juarez',
    'MMUN' => 'Cancun International',
    'MMTJ' => 'Tijuana General Abelardo Rodriguez',
    'MMMY' => 'Monterrey General Mariano Escobedo',
    'MMGL' => 'Guadalajara Miguel Hidalgo',
    'MMPR' => 'Puerto Vallarta Gustavo Diaz Ordaz',
    'MMSD' => 'Los Cabos International',
    'MMCZ' => 'Cozumel International',
    'MMMD' => 'Merida Manuel Crescencio Rejon',
    'MMHO' => 'Hermosillo General Ignacio Pesqueira Garcia',
    'MMCU' => 'Chihuahua General Roberto Fierro Villalobos',
    'MMMZ' => 'Mazatlan General Rafael Buelna',
    'MMTO' => 'Toluca Adolfo Lopez Mateos',
    'MMZH' => 'Ixtapa-Zihuatanejo',
    'MMAA' => 'Acapulco General Juan N. Alvarez',
    'MMVR' => 'Veracruz General Heriberto Jara',
    'MMTC' => 'Torreon Francisco Sarabia',
    'MMCL' => 'Culiacan Federal de Bachigualato',
    'MMAS' => 'Aguascalientes Lic. Jesus Teran Peredo',
    'MMBT' => 'Bahias de Huatulco',
    
    // CENTRAL AMERICA
    'MGGT' => 'Guatemala City La Aurora',
    'MSLP' => 'San Salvador Monsenor Romero',
    'MHTG' => 'Tegucigalpa Toncontin',
    'MNMG' => 'Managua Augusto C. Sandino',
    'MROC' => 'San Jose Juan Santamaria (Costa Rica)',
    'MPTO' => 'Panama City Tocumen',
    'MRLB' => 'Liberia Daniel Oduber (Costa Rica)',
    'MPHO' => 'Panama City Howard',
    'MZBZ' => 'Belize City Philip Goldson',
    
    // CARIBBEAN
    'TJSJ' => 'San Juan Luis Munoz Marin',
    'TJBQ' => 'Aguadilla Rafael Hernandez',
    'TIST' => 'St. Thomas Cyril E. King',
    'TISX' => 'St. Croix Henry E. Rohlsen',
    'MYNN' => 'Nassau Lynden Pindling',
    'MYEF' => 'Exuma International',
    'MYGF' => 'Grand Bahama International',
    'MUHA' => 'Havana Jose Marti',
    'MUVR' => 'Varadero Juan Gualberto Gomez',
    'MUCU' => 'Santiago de Cuba Antonio Maceo',
    'MKJP' => 'Kingston Norman Manley',
    'MKJS' => 'Montego Bay Sangster',
    'MDSD' => 'Santo Domingo Las Americas',
    'MDPP' => 'Puerto Plata Gregorio Luperon',
    'MDPC' => 'Punta Cana International',
    'MTPP' => 'Port-au-Prince Toussaint Louverture',
    'MWCR' => 'Grand Cayman Owen Roberts',
    'MBPV' => 'Providenciales International',
    'TNCM' => 'St. Maarten Princess Juliana',
    'TNCA' => 'Aruba Queen Beatrix',
    'TNCB' => 'Bonaire Flamingo',
    'TNCC' => 'Curacao Hato',
    'TBPB' => 'Barbados Grantley Adams',
    'TLPL' => 'St. Lucia Hewanorra',
    'TAPA' => 'Antigua V.C. Bird',
    'TKPK' => 'St. Kitts Robert L. Bradshaw',
    'TGPY' => 'Grenada Maurice Bishop',
    'TTPP' => 'Trinidad Piarco',
    'TUPJ' => 'Beef Island (British Virgin Islands)',
    'TFFR' => 'Guadeloupe Pointe-a-Pitre',
    'TFFF' => 'Martinique Aime Cesaire',
    'TFFJ' => 'St. Barthelemy Gustaf III',
    'TFFG' => 'St. Martin Grand Case',
    
    // SOUTH AMERICA
    'SBGR' => 'Sao Paulo Guarulhos',
    'SBSP' => 'Sao Paulo Congonhas',
    'SBRJ' => 'Rio de Janeiro Santos Dumont',
    'SBGL' => 'Rio de Janeiro Galeao',
    'SBKP' => 'Campinas Viracopos',
    'SBBR' => 'Brasilia',
    'SBCF' => 'Belo Horizonte Confins',
    'SBPA' => 'Porto Alegre Salgado Filho',
    'SBSV' => 'Salvador Deputado Luis Eduardo Magalhaes',
    'SBRF' => 'Recife Guararapes',
    'SBFZ' => 'Fortaleza Pinto Martins',
    'SBCT' => 'Curitiba Afonso Pena',
    'SBFL' => 'Florianopolis Hercilio Luz',
    'SAEZ' => 'Buenos Aires Ezeiza',
    'SABE' => 'Buenos Aires Aeroparque',
    'SACO' => 'Cordoba Ingeniero Aeronautico',
    'SAAR' => 'Rosario Islas Malvinas',
    'SAWH' => 'Ushuaia Malvinas Argentinas',
    'SANC' => 'San Carlos de Bariloche',
    'SAME' => 'Mendoza El Plumerillo',
    'SCEL' => 'Santiago Arturo Merino Benitez',
    'SCFA' => 'Antofagasta Andres Sabella Galvez',
    'SCIE' => 'Concepcion Carriel Sur',
    'SCTE' => 'Puerto Montt El Tepual',
    'SCDA' => 'Iquique Diego Aracena',
    'SKBO' => 'Bogota El Dorado',
    'SKRG' => 'Medellin Jose Maria Cordova',
    'SKCL' => 'Cali Alfonso Bonilla Aragon',
    'SKBQ' => 'Barranquilla Ernesto Cortissoz',
    'SKCG' => 'Cartagena Rafael Nunez',
    'SKSP' => 'San Andres Gustavo Rojas Pinilla',
    'SPJC' => 'Lima Jorge Chavez',
    'SPZO' => 'Cusco Alejandro Velasco Astete',
    'SPQU' => 'Arequipa Rodriguez Ballon',
    'SEQM' => 'Quito Mariscal Sucre',
    'SEGU' => 'Guayaquil Jose Joaquin de Olmedo',
    'SEGS' => 'Galapagos Seymour',
    'SVMI' => 'Caracas Simon Bolivar',
    'SVMC' => 'Maracaibo La Chinita',
    'SVVA' => 'Valencia Arturo Michelena',
    'SLLP' => 'La Paz El Alto',
    'SLVR' => 'Santa Cruz Viru Viru',
    'SGAS' => 'Asuncion Silvio Pettirossi',
    'SUMU' => 'Montevideo Carrasco',
    'SYCJ' => 'Georgetown Cheddi Jagan',
    'SMJP' => 'Paramaribo Johan Adolf Pengel',
];

// ============================================================================
// CONFIGURATION
// ============================================================================

$CONFIG = [
    'overpass_url' => 'https://overpass-api.de/api/interpreter',
    'timeout' => 60,
    'delay_between_requests' => 2,
    'max_retries' => 3,
    'retry_delay' => 10,
];

// ============================================================================
// OVERPASS QUERY TEMPLATE
// ============================================================================

function buildOverpassQuery($icao) {
    $icaoLower = strtolower($icao);
    return <<<QUERY
[out:json][timeout:60];
(
  area["icao"="{$icao}"]->.airport;
  area["icao"="{$icaoLower}"]->.airport2;
);
(
  way["aeroway"="runway"](area.airport);
  way["aeroway"="runway"](area.airport2);
  way["aeroway"="taxiway"](area.airport);
  way["aeroway"="taxiway"](area.airport2);
  way["aeroway"="taxilane"](area.airport);
  way["aeroway"="taxilane"](area.airport2);
  way["aeroway"="apron"](area.airport);
  way["aeroway"="apron"](area.airport2);
  node["aeroway"="parking_position"](area.airport);
  node["aeroway"="parking_position"](area.airport2);
  node["aeroway"="holding_position"](area.airport);
  node["aeroway"="holding_position"](area.airport2);
  node["aeroway"="gate"](area.airport);
  node["aeroway"="gate"](area.airport2);
);
out body;
>;
out skel qt;
QUERY;
}

// ============================================================================
// OVERPASS API CLIENT
// ============================================================================

function fetchFromOverpass($icao, $config) {
    $query = buildOverpassQuery($icao);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $config['overpass_url'],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => 'data=' . urlencode($query),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $config['timeout'],
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'User-Agent: PERTI-VATSIM-OOOI/1.0'
        ],
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) throw new Exception("cURL error: $error");
    if ($httpCode === 429) throw new Exception("Rate limited");
    if ($httpCode !== 200) throw new Exception("HTTP error: $httpCode");
    
    return $response;
}

// ============================================================================
// GEOMETRY PROCESSING
// ============================================================================

function processOsmResponse($json, $icao) {
    $data = json_decode($json, true);
    if (!$data || !isset($data['elements'])) {
        return ['zones' => [], 'error' => 'Invalid JSON response'];
    }
    
    $elements = $data['elements'];
    $nodes = [];
    $zones = [];
    
    foreach ($elements as $elem) {
        if ($elem['type'] === 'node' && isset($elem['lat'])) {
            $nodes[$elem['id']] = ['lat' => $elem['lat'], 'lon' => $elem['lon']];
        }
    }
    
    foreach ($elements as $elem) {
        $tags = $elem['tags'] ?? [];
        $aeroway = $tags['aeroway'] ?? null;
        if (!$aeroway) continue;
        
        $zoneTypeMap = [
            'runway' => 'RUNWAY', 'taxiway' => 'TAXIWAY', 'taxilane' => 'TAXILANE',
            'apron' => 'APRON', 'parking_position' => 'PARKING', 'gate' => 'GATE', 'holding_position' => 'HOLD'
        ];
        $bufferMap = ['RUNWAY' => 45, 'TAXIWAY' => 20, 'TAXILANE' => 15, 'APRON' => 50, 'PARKING' => 25, 'GATE' => 20, 'HOLD' => 15];
        
        $zone = [
            'osm_id' => $elem['id'],
            'zone_type' => $zoneTypeMap[$aeroway] ?? 'UNKNOWN',
            'zone_name' => $tags['ref'] ?? $tags['name'] ?? null,
            'heading' => isset($tags['heading']) ? intval($tags['heading']) : null,
        ];
        $zone['buffer'] = $bufferMap[$zone['zone_type']] ?? 20;
        
        if ($elem['type'] === 'node' && isset($elem['lat'])) {
            $zone['lat'] = $elem['lat'];
            $zone['lon'] = $elem['lon'];
        } elseif ($elem['type'] === 'way' && isset($elem['nodes'])) {
            $coords = [];
            foreach ($elem['nodes'] as $nodeId) {
                if (isset($nodes[$nodeId])) $coords[] = $nodes[$nodeId];
            }
            if (count($coords) >= 2) {
                $sumLat = $sumLon = 0;
                foreach ($coords as $c) { $sumLat += $c['lat']; $sumLon += $c['lon']; }
                $zone['lat'] = $sumLat / count($coords);
                $zone['lon'] = $sumLon / count($coords);
            }
        }
        
        if (isset($zone['lat'])) $zones[] = $zone;
    }
    
    return ['zones' => $zones, 'error' => null];
}

// ============================================================================
// DATABASE IMPORT (supports both PDO and sqlsrv)
// ============================================================================

function importZonesToDatabase($db, $icao, $zones, $dryRun = false) {
    $stats = ['deleted' => 0, 'inserted' => 0, 'runways' => 0, 'taxiways' => 0, 'parking' => 0, 'errors' => []];
    
    if ($dryRun) {
        $stats['inserted'] = count($zones);
        foreach ($zones as $z) {
            if ($z['zone_type'] === 'RUNWAY') $stats['runways']++;
            if (in_array($z['zone_type'], ['TAXIWAY', 'TAXILANE'])) $stats['taxiways']++;
            if (in_array($z['zone_type'], ['PARKING', 'GATE'])) $stats['parking']++;
        }
        return $stats;
    }
    
    $type = $db['type'];
    $conn = $db['conn'];
    
    // Delete existing
    $deleteSql = "DELETE FROM dbo.airport_geometry WHERE airport_icao = ? AND source = 'OSM'";
    if ($type === 'pdo') {
        $stmt = $conn->prepare($deleteSql);
        $stmt->execute([$icao]);
        $stats['deleted'] = $stmt->rowCount();
    } else {
        $stmt = sqlsrv_query($conn, $deleteSql, [$icao]);
        $stats['deleted'] = sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);
    }
    
    // Insert zones
    $insertSql = "INSERT INTO dbo.airport_geometry (airport_icao, zone_type, zone_name, osm_id, geometry, center_lat, center_lon, heading_deg, source)
                  VALUES (?, ?, ?, ?, geography::Point(?, ?, 4326).STBuffer(?), ?, ?, ?, 'OSM')";
    
    foreach ($zones as $zone) {
        $params = [$icao, $zone['zone_type'], $zone['zone_name'], $zone['osm_id'], 
                   $zone['lat'], $zone['lon'], $zone['buffer'], $zone['lat'], $zone['lon'], $zone['heading']];
        try {
            if ($type === 'pdo') {
                $stmt = $conn->prepare($insertSql);
                $stmt->execute($params);
            } else {
                $stmt = sqlsrv_query($conn, $insertSql, $params);
                if ($stmt === false) throw new Exception(print_r(sqlsrv_errors(), true));
                sqlsrv_free_stmt($stmt);
            }
            $stats['inserted']++;
            if ($zone['zone_type'] === 'RUNWAY') $stats['runways']++;
            if (in_array($zone['zone_type'], ['TAXIWAY', 'TAXILANE'])) $stats['taxiways']++;
            if (in_array($zone['zone_type'], ['PARKING', 'GATE'])) $stats['parking']++;
        } catch (Exception $e) {
            $stats['errors'][] = "Zone {$zone['osm_id']}: " . $e->getMessage();
        }
    }
    
    // Log
    $logSql = "INSERT INTO dbo.airport_geometry_import_log (airport_icao, source, zones_imported, runways_count, taxiways_count, parking_count, success) VALUES (?, 'OSM', ?, ?, ?, ?, 1)";
    if ($type === 'pdo') {
        $conn->prepare($logSql)->execute([$icao, $stats['inserted'], $stats['runways'], $stats['taxiways'], $stats['parking']]);
    } else {
        sqlsrv_query($conn, $logSql, [$icao, $stats['inserted'], $stats['runways'], $stats['taxiways'], $stats['parking']]);
    }
    
    return $stats;
}

function generateFallbackZones($db, $icao) {
    $sql = "EXEC dbo.sp_GenerateFallbackZones @airport_icao = ?";
    if ($db['type'] === 'pdo') {
        $db['conn']->prepare($sql)->execute([$icao]);
    } else {
        sqlsrv_query($db['conn'], $sql, [$icao]);
    }
}

// ============================================================================
// MAIN
// ============================================================================

function main($argv) {
    global $AIRPORTS, $CONFIG;
    
    $singleAirport = null;
    $dryRun = false;
    $startFrom = null;
    
    foreach ($argv as $arg) {
        if (preg_match('/^--airport=(\w+)$/i', $arg, $m)) $singleAirport = strtoupper($m[1]);
        if ($arg === '--dry-run') $dryRun = true;
        if (preg_match('/^--start-from=(\w+)$/i', $arg, $m)) $startFrom = strtoupper($m[1]);
    }
    
    echo "=======================================================================\n";
    echo "  PERTI OSM Airport Geometry Import\n";
    echo "  Airports: " . count($AIRPORTS) . " (ASPM82 + CA + MX + LatAm + Caribbean)\n";
    echo "  " . date('Y-m-d H:i:s') . "\n";
    echo "=======================================================================\n\n";
    
    if ($dryRun) echo "*** DRY RUN MODE ***\n\n";
    
    $db = null;
    if (!$dryRun) {
        try {
            $db = getAdlConnection();
            echo "Connected via {$db['type']}\n\n";
        } catch (Exception $e) {
            echo "Database connection failed: " . $e->getMessage() . "\n";
            exit(1);
        }
    }
    
    $airportList = $singleAirport ? [$singleAirport => $AIRPORTS[$singleAirport] ?? 'Unknown'] : $AIRPORTS;
    $total = count($airportList);
    $current = $success = $failed = $noData = $skipped = 0;
    $skipUntilFound = ($startFrom !== null);
    
    echo "Processing $total airports...\n\n";
    
    foreach ($airportList as $icao => $name) {
        $current++;
        
        if ($skipUntilFound) {
            if ($icao === $startFrom) $skipUntilFound = false;
            else { $skipped++; continue; }
        }
        
        echo sprintf("[%3d/%3d] %-4s - %s\n", $current, $total, $icao, $name);
        
        $retries = 0;
        $json = null;
        while ($retries < $CONFIG['max_retries']) {
            try {
                $json = fetchFromOverpass($icao, $CONFIG);
                break;
            } catch (Exception $e) {
                $retries++;
                echo "  ! Retry $retries: " . $e->getMessage() . "\n";
                if ($retries < $CONFIG['max_retries']) sleep($CONFIG['retry_delay']);
            }
        }
        
        if (!$json) { echo "  X Failed\n"; $failed++; continue; }
        
        $result = processOsmResponse($json, $icao);
        if ($result['error']) { echo "  X {$result['error']}\n"; $failed++; continue; }
        
        $zones = $result['zones'];
        if (empty($zones)) {
            echo "  o No OSM data";
            $noData++;
            if (!$dryRun && $db) {
                try { generateFallbackZones($db, $icao); echo " -> fallback created"; }
                catch (Exception $e) { echo " -> fallback failed"; }
            }
            echo "\n";
            continue;
        }
        
        try {
            $stats = importZonesToDatabase($db, $icao, $zones, $dryRun);
            echo "  + {$stats['inserted']} zones (RWY:{$stats['runways']} TWY:{$stats['taxiways']} PARK:{$stats['parking']})\n";
            $success++;
        } catch (Exception $e) {
            echo "  X Import error: " . $e->getMessage() . "\n";
            $failed++;
        }
        
        if (!$singleAirport && $current < $total) sleep($CONFIG['delay_between_requests']);
    }
    
    if ($db && $db['type'] === 'sqlsrv') sqlsrv_close($db['conn']);
    
    echo "\n=======================================================================\n";
    echo "  Complete: $success success, $noData fallback, $failed failed";
    if ($skipped) echo ", $skipped skipped";
    echo "\n=======================================================================\n";
    
    return $failed > 0 ? 1 : 0;
}

exit(main($argv));
