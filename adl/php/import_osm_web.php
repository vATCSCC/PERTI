<?php
/**
 * import_osm_web.php
 * 
 * Web-callable version of OSM airport geometry import
 * Run via browser or: curl "http://localhost/adl/php/import_osm_web.php?airport=KJFK"
 * 
 * Parameters:
 *   ?airport=ICAO  - Import single airport
 *   ?start=ICAO    - Start from this airport
 *   ?batch=N       - Process N airports per request (default 10)
 */

header('Content-Type: text/plain; charset=utf-8');
set_time_limit(300); // 5 minutes max

require_once __DIR__ . '/../../load/config.php';
require_once __DIR__ . '/../../load/connect.php';

// Check ADL connection
if (!$conn_adl) {
    die("ERROR: ADL database connection not available\n");
}

// ============================================================================
// AIRPORT LIST
// ============================================================================

$AIRPORTS = [
    // ASPM82
    'KATL','KBOS','KBWI','KCLE','KCLT','KCVG','KDCA','KDEN','KDFW','KDTW',
    'KEWR','KFLL','KHNL','KHOU','KHPN','KIAD','KIAH','KISP','KJFK','KLAS',
    'KLAX','KLGA','KMCI','KMCO','KMDW','KMEM','KMIA','KMKE','KMSP','KMSY',
    'KOAK','KONT','KORD','KPBI','KPDX','KPHL','KPHX','KPIT','KPVD','KRDU',
    'KRSW','KSAN','KSAT','KSDF','KSEA','KSFO','KSJC','KSLC','KSMF','KSNA',
    'KSTL','KSWF','KTEB','KTPA','KAUS','KABQ','KANC','KBDL','KBNA','KBUF',
    'KBUR','KCHS','KCMH','KDAL','KGSO','KIND','KJAX','KMHT','KOMA','KORF',
    'KPWM','KRNO','KRIC','KSAV','KSYR','KTUL',
    // Canada
    'CYYZ','CYVR','CYUL','CYYC','CYOW','CYEG','CYWG','CYHZ','CYQB','CYYJ',
    'CYXE','CYQR','CYYT','CYTZ','CYQM','CYZF','CYXY',
    // Mexico
    'MMMX','MMUN','MMTJ','MMMY','MMGL','MMPR','MMSD','MMCZ','MMMD','MMHO',
    'MMCU','MMMZ','MMTO','MMZH','MMAA','MMVR','MMTC','MMCL','MMAS','MMBT',
    // Central America
    'MGGT','MSLP','MHTG','MNMG','MROC','MPTO','MRLB','MPHO','MZBZ',
    // Caribbean
    'TJSJ','TJBQ','TIST','TISX','MYNN','MYEF','MYGF','MUHA','MUVR','MUCU',
    'MKJP','MKJS','MDSD','MDPP','MDPC','MTPP','MWCR','MBPV','TNCM','TNCA',
    'TNCB','TNCC','TBPB','TLPL','TAPA','TKPK','TGPY','TTPP','TUPJ','TFFR',
    'TFFF','TFFJ','TFFG',
    // South America
    'SBGR','SBSP','SBRJ','SBGL','SBKP','SBBR','SBCF','SBPA','SBSV','SBRF',
    'SBFZ','SBCT','SBFL','SAEZ','SABE','SACO','SAAR','SAWH','SANC','SAME',
    'SCEL','SCFA','SCIE','SCTE','SCDA','SKBO','SKRG','SKCL','SKBQ','SKCG',
    'SKSP','SPJC','SPZO','SPQU','SEQM','SEGU','SEGS','SVMI','SVMC','SVVA',
    'SLLP','SLVR','SGAS','SUMU','SYCJ','SMJP',
];

// ============================================================================
// FUNCTIONS
// ============================================================================

function fetchFromOverpass($icao) {
    $icaoLower = strtolower($icao);
    $query = <<<QUERY
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

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://overpass-api.de/api/interpreter',
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => 'data=' . urlencode($query),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['User-Agent: PERTI-VATSIM-OOOI/1.0'],
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) return null;
    return $response;
}

function processOsmResponse($json) {
    $data = json_decode($json, true);
    if (!$data || !isset($data['elements'])) return [];
    
    $nodes = [];
    $zones = [];
    
    foreach ($data['elements'] as $elem) {
        if ($elem['type'] === 'node' && isset($elem['lat'])) {
            $nodes[$elem['id']] = ['lat' => $elem['lat'], 'lon' => $elem['lon']];
        }
    }
    
    $typeMap = ['runway'=>'RUNWAY','taxiway'=>'TAXIWAY','taxilane'=>'TAXILANE','apron'=>'APRON',
                'parking_position'=>'PARKING','gate'=>'GATE','holding_position'=>'HOLD'];
    $bufferMap = ['RUNWAY'=>45,'TAXIWAY'=>20,'TAXILANE'=>15,'APRON'=>50,'PARKING'=>25,'GATE'=>20,'HOLD'=>15];
    
    foreach ($data['elements'] as $elem) {
        $aeroway = $elem['tags']['aeroway'] ?? null;
        if (!$aeroway || !isset($typeMap[$aeroway])) continue;
        
        $zone = [
            'osm_id' => $elem['id'],
            'zone_type' => $typeMap[$aeroway],
            'zone_name' => $elem['tags']['ref'] ?? $elem['tags']['name'] ?? null,
            'buffer' => $bufferMap[$typeMap[$aeroway]],
        ];
        
        if ($elem['type'] === 'node' && isset($elem['lat'])) {
            $zone['lat'] = $elem['lat'];
            $zone['lon'] = $elem['lon'];
        } elseif ($elem['type'] === 'way' && isset($elem['nodes'])) {
            $coords = [];
            foreach ($elem['nodes'] as $nid) {
                if (isset($nodes[$nid])) $coords[] = $nodes[$nid];
            }
            if (count($coords) >= 2) {
                $zone['lat'] = array_sum(array_column($coords, 'lat')) / count($coords);
                $zone['lon'] = array_sum(array_column($coords, 'lon')) / count($coords);
            }
        }
        
        if (isset($zone['lat'])) $zones[] = $zone;
    }
    
    return $zones;
}

function importZones($conn, $icao, $zones) {
    // Delete existing OSM zones
    $sql = "DELETE FROM dbo.airport_geometry WHERE airport_icao = ? AND source = 'OSM'";
    $stmt = sqlsrv_query($conn, $sql, [$icao]);
    sqlsrv_free_stmt($stmt);
    
    $stats = ['inserted' => 0, 'runways' => 0, 'taxiways' => 0, 'parking' => 0];
    
    $insertSql = "INSERT INTO dbo.airport_geometry (airport_icao, zone_type, zone_name, osm_id, geometry, center_lat, center_lon, source)
                  VALUES (?, ?, ?, ?, geography::Point(?, ?, 4326).STBuffer(?), ?, ?, 'OSM')";
    
    foreach ($zones as $z) {
        $params = [$icao, $z['zone_type'], $z['zone_name'], $z['osm_id'], $z['lat'], $z['lon'], $z['buffer'], $z['lat'], $z['lon']];
        $stmt = sqlsrv_query($conn, $insertSql, $params);
        if ($stmt) {
            sqlsrv_free_stmt($stmt);
            $stats['inserted']++;
            if ($z['zone_type'] === 'RUNWAY') $stats['runways']++;
            if (in_array($z['zone_type'], ['TAXIWAY','TAXILANE'])) $stats['taxiways']++;
            if (in_array($z['zone_type'], ['PARKING','GATE'])) $stats['parking']++;
        }
    }
    
    // Log
    $logSql = "INSERT INTO dbo.airport_geometry_import_log (airport_icao, source, zones_imported, runways_count, taxiways_count, parking_count, success) VALUES (?, 'OSM', ?, ?, ?, ?, 1)";
    sqlsrv_query($conn, $logSql, [$icao, $stats['inserted'], $stats['runways'], $stats['taxiways'], $stats['parking']]);
    
    return $stats;
}

function generateFallback($conn, $icao) {
    $sql = "EXEC dbo.sp_GenerateFallbackZones @airport_icao = ?";
    sqlsrv_query($conn, $sql, [$icao]);
}

// ============================================================================
// MAIN
// ============================================================================

$singleAirport = isset($_GET['airport']) ? strtoupper($_GET['airport']) : null;
$startFrom = isset($_GET['start']) ? strtoupper($_GET['start']) : null;
$batchSize = isset($_GET['batch']) ? get_int('batch') : 10;

echo "=======================================================================\n";
echo "  PERTI OSM Airport Geometry Import (Web)\n";
echo "  " . date('Y-m-d H:i:s') . "\n";
echo "=======================================================================\n\n";

if ($singleAirport) {
    // Single airport mode
    echo "Importing: $singleAirport\n\n";
    
    $json = fetchFromOverpass($singleAirport);
    if (!$json) {
        echo "ERROR: Failed to fetch from Overpass API\n";
        exit(1);
    }
    
    $zones = processOsmResponse($json);
    if (empty($zones)) {
        echo "No OSM data found, generating fallback...\n";
        generateFallback($conn_adl, $singleAirport);
        echo "Done (fallback)\n";
    } else {
        $stats = importZones($conn_adl, $singleAirport, $zones);
        echo "Imported: {$stats['inserted']} zones (RWY:{$stats['runways']} TWY:{$stats['taxiways']} PARK:{$stats['parking']})\n";
    }
} else {
    // Batch mode
    $skip = ($startFrom !== null);
    $processed = 0;
    $success = 0;
    $noData = 0;
    
    echo "Batch size: $batchSize\n";
    if ($startFrom) echo "Starting from: $startFrom\n";
    echo "\n";
    
    foreach ($AIRPORTS as $icao) {
        if ($skip) {
            if ($icao === $startFrom) $skip = false;
            else continue;
        }
        
        if ($processed >= $batchSize) {
            $nextIdx = array_search($icao, $AIRPORTS);
            echo "\n---\n";
            echo "Batch complete. Next: ?start=$icao\n";
            echo "Remaining: " . (count($AIRPORTS) - $nextIdx) . " airports\n";
            break;
        }
        
        echo "[$icao] ";
        flush();
        
        $json = fetchFromOverpass($icao);
        if (!$json) {
            echo "FAILED (API)\n";
            continue;
        }
        
        $zones = processOsmResponse($json);
        if (empty($zones)) {
            generateFallback($conn_adl, $icao);
            echo "fallback\n";
            $noData++;
        } else {
            $stats = importZones($conn_adl, $icao, $zones);
            echo "{$stats['inserted']} zones\n";
            $success++;
        }
        
        $processed++;
        sleep(2); // Rate limit
    }
    
    echo "\n=======================================================================\n";
    echo "Processed: $processed | Success: $success | Fallback: $noData\n";
    echo "=======================================================================\n";
}
