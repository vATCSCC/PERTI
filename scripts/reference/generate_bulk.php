<?php
/**
 * VATSWIM Bulk Reference Data Generator
 *
 * Generates static JSON/GeoJSON/CSV files for bulk download.
 * Run post-AIRAC-update or manually via CLI/web.
 *
 * CLI:  php scripts/reference/generate_bulk.php [--force]
 * Web:  https://perti.vatcscc.org/scripts/reference/generate_bulk.php?run=1
 *
 * Output: data/bulk/{dataset}.{json|geojson|csv}
 */

$is_cli = (php_sapi_name() === 'cli');

if (!$is_cli) {
    // Web mode - require run param
    if (!isset($_GET['run'])) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'idle', 'usage' => 'Add ?run=1 to execute']);
        exit;
    }
    header('Content-Type: application/json');
}

// Load config
require_once __DIR__ . '/../../load/config.php';
require_once __DIR__ . '/../../load/connect.php';

$bulk_dir = __DIR__ . '/../../data/bulk';
if (!is_dir($bulk_dir)) {
    mkdir($bulk_dir, 0755, true);
}

$results = [];
$start = microtime(true);

function log_msg($msg) {
    global $is_cli;
    if ($is_cli) echo "$msg\n";
}

// === AIRPORTS ===
log_msg("Generating airports...");
$conn_gis = get_conn_gis();
if ($conn_gis) {
    $stmt = $conn_gis->query("SELECT icao_code, faa_lid, name, city, state_code, country_code, latitude, longitude, elevation_ft, mag_var, is_towered, airport_class FROM airports ORDER BY icao_code");
    $airports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    file_put_contents("$bulk_dir/airports.json", json_encode(['airports' => $airports, 'count' => count($airports)], JSON_PRETTY_PRINT));
    $results['airports'] = ['count' => count($airports), 'size' => filesize("$bulk_dir/airports.json")];
    log_msg("  airports: " . count($airports) . " records");
}

// === FIXES ===
log_msg("Generating fixes...");
if ($conn_gis) {
    $stmt = $conn_gis->query("SELECT fix_name, lat, lon, fix_type, artcc_code, country_code, airac_cycle FROM nav_fixes WHERE is_superseded = false OR is_superseded IS NULL ORDER BY fix_name");
    $fixes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    file_put_contents("$bulk_dir/fixes.json", json_encode(['fixes' => $fixes, 'count' => count($fixes)]));
    $results['fixes'] = ['count' => count($fixes), 'size' => filesize("$bulk_dir/fixes.json")];
    log_msg("  fixes: " . count($fixes) . " records");
}

// === AIRWAYS ===
log_msg("Generating airways...");
if ($conn_gis) {
    $stmt = $conn_gis->query("SELECT airway_name, airway_type, airac_cycle FROM airways WHERE is_superseded = false OR is_superseded IS NULL ORDER BY airway_name");
    $airways = $stmt->fetchAll(PDO::FETCH_ASSOC);
    file_put_contents("$bulk_dir/airways.json", json_encode(['airways' => $airways, 'count' => count($airways)]));
    $results['airways'] = ['count' => count($airways), 'size' => filesize("$bulk_dir/airways.json")];
    log_msg("  airways: " . count($airways) . " records");
}

// === PROCEDURES ===
log_msg("Generating procedures...");
if ($conn_gis) {
    $stmt = $conn_gis->query("SELECT computer_code, procedure_name, procedure_type, airport_icao, transition_name, transition_type, source, airac_cycle FROM nav_procedures WHERE is_superseded = false OR is_superseded IS NULL ORDER BY airport_icao, procedure_type, procedure_name");
    $procs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    file_put_contents("$bulk_dir/procedures.json", json_encode(['procedures' => $procs, 'count' => count($procs)]));
    $results['procedures'] = ['count' => count($procs), 'size' => filesize("$bulk_dir/procedures.json")];
    log_msg("  procedures: " . count($procs) . " records");
}

// === BOUNDARIES (GeoJSON) ===
foreach (['artcc' => 'artcc_boundaries', 'tracon' => 'tracon_boundaries', 'sector' => 'sector_boundaries'] as $key => $table) {
    log_msg("Generating boundaries_$key...");
    if ($conn_gis) {
        $code_col = $key === 'artcc' ? 'artcc_code' : ($key === 'tracon' ? 'tracon_code' : 'sector_code');
        $stmt = $conn_gis->query("SELECT *, ST_AsGeoJSON(geom, 5) AS geometry FROM $table ORDER BY $code_col");
        $features = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $geom = json_decode($row['geometry'], true);
            unset($row['geometry'], $row['geom']);
            $features[] = ['type' => 'Feature', 'properties' => $row, 'geometry' => $geom];
        }
        $geojson = ['type' => 'FeatureCollection', 'features' => $features];
        file_put_contents("$bulk_dir/boundaries_{$key}.geojson", json_encode($geojson));
        $results["boundaries_$key"] = ['count' => count($features), 'size' => filesize("$bulk_dir/boundaries_{$key}.geojson")];
        log_msg("  boundaries_$key: " . count($features) . " features");
    }
}

// === CDRS ===
log_msg("Generating CDRs...");
if ($conn_gis) {
    $stmt = $conn_gis->query("SELECT cdr_code, cdr_type, origin_airport, dest_airport, route_string, airac_cycle FROM coded_departure_routes WHERE is_superseded = false OR is_superseded IS NULL ORDER BY cdr_code");
    $cdrs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    file_put_contents("$bulk_dir/cdrs.json", json_encode(['cdrs' => $cdrs, 'count' => count($cdrs)]));
    $results['cdrs'] = ['count' => count($cdrs), 'size' => filesize("$bulk_dir/cdrs.json")];
    log_msg("  cdrs: " . count($cdrs) . " records");
}

// === AIRCRAFT ===
log_msg("Generating aircraft...");
$conn_adl = get_conn_adl();
if ($conn_adl) {
    $stmt = sqlsrv_query($conn_adl, "SELECT * FROM dbo.ACD_Data ORDER BY ICAO_Code");
    $aircraft = [];
    if ($stmt !== false) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) { $aircraft[] = $row; }
        sqlsrv_free_stmt($stmt);
    }
    file_put_contents("$bulk_dir/aircraft.json", json_encode(['aircraft' => $aircraft, 'count' => count($aircraft)]));
    $results['aircraft'] = ['count' => count($aircraft), 'size' => filesize("$bulk_dir/aircraft.json")];
    log_msg("  aircraft: " . count($aircraft) . " records");
}

// === AIRLINES ===
log_msg("Generating airlines...");
if ($conn_adl) {
    $stmt = sqlsrv_query($conn_adl, "SELECT icao_code, iata_code, name, callsign, country FROM dbo.airlines ORDER BY icao_code");
    $airlines = [];
    if ($stmt !== false) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) { $airlines[] = $row; }
        sqlsrv_free_stmt($stmt);
    }
    file_put_contents("$bulk_dir/airlines.json", json_encode(['airlines' => $airlines, 'count' => count($airlines)]));
    $results['airlines'] = ['count' => count($airlines), 'size' => filesize("$bulk_dir/airlines.json")];
    log_msg("  airlines: " . count($airlines) . " records");
}

// === HIERARCHY ===
log_msg("Copying hierarchy...");
$hierarchy_src = __DIR__ . '/../../assets/data/hierarchy.json';
if (file_exists($hierarchy_src)) {
    copy($hierarchy_src, "$bulk_dir/hierarchy.json");
    $results['hierarchy'] = ['size' => filesize("$bulk_dir/hierarchy.json")];
}

// === CATALOG ===
$elapsed = round(microtime(true) - $start, 1);
$catalog = [
    'generated_utc' => gmdate('c'),
    'generation_time_sec' => $elapsed,
    'airac_cycle' => null,
    'datasets' => [],
];

foreach ($results as $key => $info) {
    $catalog['datasets'][] = [
        'key' => $key,
        'records' => $info['count'] ?? null,
        'size_bytes' => $info['size'] ?? null,
        'format' => str_contains($key, 'boundaries') ? 'geojson' : 'json',
        'url' => "/api/swim/v1/reference/bulk/$key",
    ];
}

file_put_contents("$bulk_dir/catalog.json", json_encode($catalog, JSON_PRETTY_PRINT));
log_msg("\nDone in {$elapsed}s. Catalog: $bulk_dir/catalog.json");

if (!$is_cli) {
    echo json_encode(['success' => true, 'results' => $results, 'elapsed_sec' => $elapsed]);
}
