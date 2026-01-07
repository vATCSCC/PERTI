<?php
/**
 * import_wind_data.php - NOAA GFS/RAP Wind Data Import
 * 
 * Downloads wind data from NOMADS, parses GRIB2 files, and imports
 * into the VATSIM_ADL database for ETA calculations.
 * 
 * Usage:
 *   php import_wind_data.php [--source=RAP|GFS] [--debug] [--force]
 * 
 * Requirements:
 *   - Python 3 with pygrib OR wgrib2 binary
 *   - PHP cURL extension
 *   - Write access to temp directory
 * 
 * @version 1.0
 * @date 2026-01-07
 */

// Configuration
define('TEMP_DIR', __DIR__ . '/temp');
define('LOG_FILE', __DIR__ . '/logs/wind_import.log');
define('MAX_DATA_AGE_HOURS', 6);  // Purge data older than this

// NOMADS URLs
define('RAP_BASE_URL', 'https://nomads.ncep.noaa.gov/cgi-bin/filter_rap.pl');
define('GFS_BASE_URL', 'https://nomads.ncep.noaa.gov/cgi-bin/filter_gfs_0p25.pl');

// Geographic bounds (CONUS + buffer)
define('BOUNDS_NORTH', 55);
define('BOUNDS_SOUTH', 20);
define('BOUNDS_WEST', -130);
define('BOUNDS_EAST', -60);

// Pressure levels to download (maps to FL180-FL450)
$PRESSURE_LEVELS = [200, 250, 300, 350, 400, 500];

// Forecast hours to download
$FORECAST_HOURS = [0, 3, 6];

// GRIB parser preference: 'pygrib' or 'wgrib2'
define('GRIB_PARSER', 'pygrib');

/**
 * Main entry point
 */
function main($argv) {
    global $PRESSURE_LEVELS, $FORECAST_HOURS;
    
    // Parse arguments
    $source = 'RAP';
    $debug = false;
    $force = false;
    
    foreach ($argv as $arg) {
        if (strpos($arg, '--source=') === 0) {
            $source = strtoupper(substr($arg, 9));
        }
        if ($arg === '--debug') {
            $debug = true;
        }
        if ($arg === '--force') {
            $force = true;
        }
    }
    
    if (!in_array($source, ['RAP', 'GFS'])) {
        logMsg("ERROR: Invalid source '$source'. Use RAP or GFS.", true);
        exit(1);
    }
    
    logMsg("=== Wind Data Import Started ===", $debug);
    logMsg("Source: $source, Debug: " . ($debug ? 'Yes' : 'No') . ", Force: " . ($force ? 'Yes' : 'No'), $debug);
    
    // Ensure temp directory exists
    if (!is_dir(TEMP_DIR)) {
        mkdir(TEMP_DIR, 0755, true);
    }
    
    // Connect to database
    $db = connectDatabase();
    if (!$db) {
        logMsg("ERROR: Database connection failed", true);
        exit(1);
    }
    
    // Determine latest available cycle
    $cycleInfo = getLatestCycle($source);
    if (!$cycleInfo) {
        logMsg("ERROR: Could not determine latest cycle", true);
        exit(1);
    }
    
    logMsg("Latest cycle: {$cycleInfo['date']} {$cycleInfo['cycle']}Z", $debug);
    
    // Check if we already have this data
    if (!$force && hasWindData($db, $source, $cycleInfo['valid_time'])) {
        logMsg("Wind data for {$cycleInfo['valid_time']} already imported. Use --force to reimport.", true);
        exit(0);
    }
    
    // Start import log
    $importId = startImportLog($db, $source, $cycleInfo['cycle_time'], 0);
    
    $totalPoints = 0;
    $startTime = microtime(true);
    
    try {
        // Process each forecast hour
        foreach ($FORECAST_HOURS as $fhr) {
            logMsg("Processing forecast hour f" . str_pad($fhr, 2, '0', STR_PAD_LEFT) . "...", $debug);
            
            // Build NOMADS URL
            $url = buildNomadsUrl($source, $cycleInfo, $fhr, $PRESSURE_LEVELS);
            logMsg("  URL: $url", $debug);
            
            // Download GRIB2 file
            $gribFile = TEMP_DIR . "/wind_{$source}_f{$fhr}.grib2";
            $downloadStart = microtime(true);
            $fileSize = downloadGrib($url, $gribFile);
            $downloadMs = (int)((microtime(true) - $downloadStart) * 1000);
            
            if ($fileSize === false) {
                logMsg("  ERROR: Download failed", true);
                continue;
            }
            
            logMsg("  Downloaded: " . round($fileSize / 1024, 1) . " KB in {$downloadMs}ms", $debug);
            
            // Parse GRIB2 to JSON
            $parseStart = microtime(true);
            $windData = parseGrib($gribFile, $debug);
            $parseMs = (int)((microtime(true) - $parseStart) * 1000);
            
            if (!$windData || count($windData) === 0) {
                logMsg("  ERROR: Parse failed or no data", true);
                continue;
            }
            
            logMsg("  Parsed: " . count($windData) . " grid points in {$parseMs}ms", $debug);
            
            // Calculate valid time for this forecast hour
            $validTime = date('Y-m-d H:i:s', strtotime($cycleInfo['cycle_time']) + ($fhr * 3600));
            
            // Insert into database
            $importStart = microtime(true);
            $inserted = insertWindData($db, $source, $validTime, $fhr, $windData);
            $importMs = (int)((microtime(true) - $importStart) * 1000);
            
            logMsg("  Inserted: $inserted rows in {$importMs}ms", $debug);
            $totalPoints += $inserted;
            
            // Update import log
            updateImportLog($db, $importId, $inserted, $fileSize, $downloadMs, $parseMs, $importMs);
            
            // Cleanup temp file
            @unlink($gribFile);
        }
        
        // Purge old data
        $purged = purgeOldData($db);
        if ($purged > 0) {
            logMsg("Purged $purged old wind grid rows", $debug);
        }
        
        // Complete import log
        completeImportLog($db, $importId, 'SUCCESS');
        
        $elapsed = round(microtime(true) - $startTime, 1);
        logMsg("=== Import Complete: $totalPoints points in {$elapsed}s ===", true);
        
    } catch (Exception $e) {
        completeImportLog($db, $importId, 'FAILED', $e->getMessage());
        logMsg("ERROR: " . $e->getMessage(), true);
        exit(1);
    }
}

/**
 * Connect to VATSIM_ADL database
 */
function connectDatabase() {
    // Load connection settings
    $configFile = __DIR__ . '/../config/db_config.php';
    if (file_exists($configFile)) {
        include $configFile;
    }
    
    // Default to environment variables if config not found
    $server = defined('DB_SERVER') ? DB_SERVER : getenv('DB_SERVER');
    $database = defined('DB_NAME') ? DB_NAME : getenv('DB_NAME');
    $username = defined('DB_USER') ? DB_USER : getenv('DB_USER');
    $password = defined('DB_PASS') ? DB_PASS : getenv('DB_PASS');
    
    try {
        $conn = new PDO(
            "sqlsrv:Server=$server;Database=$database",
            $username,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::SQLSRV_ATTR_QUERY_TIMEOUT => 30
            ]
        );
        return $conn;
    } catch (PDOException $e) {
        logMsg("Database error: " . $e->getMessage(), true);
        return null;
    }
}

/**
 * Determine the latest available model cycle
 */
function getLatestCycle($source) {
    $now = new DateTime('now', new DateTimeZone('UTC'));
    
    if ($source === 'RAP') {
        // RAP runs hourly, available ~45 min after cycle
        $now->modify('-50 minutes');
        $cycle = $now->format('H');
        $date = $now->format('Ymd');
    } else {
        // GFS runs 4x daily (00, 06, 12, 18), available ~4 hours after
        $now->modify('-4 hours');
        $hour = (int)$now->format('H');
        $cycle = str_pad((int)floor($hour / 6) * 6, 2, '0', STR_PAD_LEFT);
        $date = $now->format('Ymd');
    }
    
    $cycleTime = substr($date, 0, 4) . "-" . substr($date, 4, 2) . "-" . substr($date, 6, 2) . " {$cycle}:00:00";
    
    return [
        'date' => $date,
        'cycle' => $cycle,
        'cycle_time' => $cycleTime,
        'valid_time' => $cycleTime  // For f00, valid = cycle time
    ];
}

/**
 * Check if we already have wind data for this time
 */
function hasWindData($db, $source, $validTime) {
    $sql = "SELECT COUNT(*) FROM dbo.wx_wind_grid 
            WHERE source = ? AND valid_time_utc = ? AND forecast_hour = 0";
    $stmt = $db->prepare($sql);
    $stmt->execute([$source, $validTime]);
    return $stmt->fetchColumn() > 0;
}

/**
 * Build NOMADS grib_filter URL
 */
function buildNomadsUrl($source, $cycleInfo, $forecastHour, $levels) {
    $date = $cycleInfo['date'];
    $cycle = $cycleInfo['cycle'];
    $fhr = str_pad($forecastHour, 2, '0', STR_PAD_LEFT);
    
    if ($source === 'RAP') {
        $baseUrl = RAP_BASE_URL;
        $dir = "/rap.{$date}";
        $file = "rap.t{$cycle}z.wrfprsf{$fhr}.grib2";
    } else {
        $baseUrl = GFS_BASE_URL;
        $dir = "/gfs.{$date}/{$cycle}/atmos";
        $file = "gfs.t{$cycle}z.pgrb2.0p25.f" . str_pad($forecastHour, 3, '0', STR_PAD_LEFT);
    }
    
    // Build query parameters
    $params = [
        'dir' => $dir,
        'file' => $file,
        'var_UGRD' => 'on',
        'var_VGRD' => 'on',
        'subregion' => '',
        'toplat' => BOUNDS_NORTH,
        'bottomlat' => BOUNDS_SOUTH,
        'leftlon' => BOUNDS_WEST,
        'rightlon' => BOUNDS_EAST
    ];
    
    // Add pressure levels
    foreach ($levels as $level) {
        $params["lev_{$level}_mb"] = 'on';
    }
    
    return $baseUrl . '?' . http_build_query($params);
}

/**
 * Download GRIB2 file from NOMADS
 */
function downloadGrib($url, $outputFile) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'PERTI/1.0 Wind Data Import'
    ]);
    
    $data = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($httpCode !== 200 || !$data) {
        logMsg("  cURL error: $error (HTTP $httpCode)", true);
        return false;
    }
    
    // Check if response is actually GRIB (starts with "GRIB")
    if (substr($data, 0, 4) !== 'GRIB') {
        logMsg("  Invalid response (not GRIB data)", true);
        return false;
    }
    
    file_put_contents($outputFile, $data);
    return strlen($data);
}

/**
 * Parse GRIB2 file to extract wind data
 */
function parseGrib($gribFile, $debug = false) {
    if (GRIB_PARSER === 'pygrib') {
        return parseGribPygrib($gribFile, $debug);
    } else {
        return parseGribWgrib2($gribFile, $debug);
    }
}

/**
 * Parse GRIB using Python pygrib
 */
function parseGribPygrib($gribFile, $debug = false) {
    // Create Python script
    $pyScript = TEMP_DIR . '/parse_grib.py';
    $pyCode = <<<'PYTHON'
#!/usr/bin/env python3
import sys
import json
import pygrib

def parse_wind_data(grib_file):
    grbs = pygrib.open(grib_file)
    
    result = []
    u_data = {}
    v_data = {}
    
    # First pass: collect all U and V components
    for grb in grbs:
        if grb.shortName == 'u' and grb.typeOfLevel == 'isobaricInhPa':
            level = int(grb.level)
            data, lats, lons = grb.data()
            u_data[level] = {'data': data, 'lats': lats, 'lons': lons}
        elif grb.shortName == 'v' and grb.typeOfLevel == 'isobaricInhPa':
            level = int(grb.level)
            data, lats, lons = grb.data()
            v_data[level] = {'data': data, 'lats': lats, 'lons': lons}
    
    grbs.close()
    
    # Second pass: combine U and V at each grid point
    # Downsample to 1-degree grid
    for level in u_data:
        if level not in v_data:
            continue
            
        u = u_data[level]
        v = v_data[level]
        
        nlats, nlons = u['data'].shape
        
        for i in range(nlats):
            for j in range(nlons):
                lat = float(u['lats'][i, j])
                lon = float(u['lons'][i, j])
                
                # Normalize longitude to -180 to 180
                if lon > 180:
                    lon -= 360
                
                # Only keep 1-degree grid points
                if abs(lat - round(lat)) > 0.1 or abs(lon - round(lon)) > 0.1:
                    continue
                
                u_val = float(u['data'][i, j])
                v_val = float(v['data'][i, j])
                
                # Store as m/s * 10 for integer precision
                result.append({
                    'pressure_mb': level,
                    'lat': round(lat, 2),
                    'lon': round(lon, 2),
                    'u_wind_mps': int(u_val * 10),
                    'v_wind_mps': int(v_val * 10)
                })
    
    return result

if __name__ == '__main__':
    grib_file = sys.argv[1]
    data = parse_wind_data(grib_file)
    print(json.dumps(data))
PYTHON;
    
    file_put_contents($pyScript, $pyCode);
    
    // Execute Python script
    $output = [];
    $returnCode = 0;
    exec("python3 " . escapeshellarg($pyScript) . " " . escapeshellarg($gribFile) . " 2>&1", $output, $returnCode);
    
    // Cleanup
    @unlink($pyScript);
    
    if ($returnCode !== 0) {
        logMsg("  Python error: " . implode("\n", $output), true);
        return [];
    }
    
    $json = implode('', $output);
    return json_decode($json, true) ?: [];
}

/**
 * Parse GRIB using wgrib2 command-line tool
 */
function parseGribWgrib2($gribFile, $debug = false) {
    $csvFile = TEMP_DIR . '/wind_data.csv';
    
    // Use wgrib2 to extract data
    $cmd = "wgrib2 " . escapeshellarg($gribFile) . 
           " -match ':(UGRD|VGRD):' -csv " . escapeshellarg($csvFile);
    
    exec($cmd . " 2>&1", $output, $returnCode);
    
    if ($returnCode !== 0 || !file_exists($csvFile)) {
        logMsg("  wgrib2 error: " . implode("\n", $output), true);
        return [];
    }
    
    // Parse CSV output
    $result = [];
    $handle = fopen($csvFile, 'r');
    
    $u_data = [];
    $v_data = [];
    
    while (($row = fgetcsv($handle)) !== false) {
        // wgrib2 CSV format: time,var,level,lon,lat,value
        if (count($row) < 6) continue;
        
        $var = $row[1];
        $level = (int)$row[2];
        $lon = (float)$row[3];
        $lat = (float)$row[4];
        $value = (float)$row[5];
        
        // Normalize longitude
        if ($lon > 180) $lon -= 360;
        
        // Only keep 1-degree grid points
        if (abs($lat - round($lat)) > 0.1 || abs($lon - round($lon)) > 0.1) {
            continue;
        }
        
        $key = "{$level}_{$lat}_{$lon}";
        
        if (strpos($var, 'UGRD') !== false) {
            $u_data[$key] = ['level' => $level, 'lat' => round($lat, 2), 'lon' => round($lon, 2), 'value' => $value];
        } elseif (strpos($var, 'VGRD') !== false) {
            $v_data[$key] = ['level' => $level, 'lat' => round($lat, 2), 'lon' => round($lon, 2), 'value' => $value];
        }
    }
    
    fclose($handle);
    @unlink($csvFile);
    
    // Combine U and V
    foreach ($u_data as $key => $u) {
        if (!isset($v_data[$key])) continue;
        
        $result[] = [
            'pressure_mb' => $u['level'],
            'lat' => $u['lat'],
            'lon' => $u['lon'],
            'u_wind_mps' => (int)($u['value'] * 10),
            'v_wind_mps' => (int)($v_data[$key]['value'] * 10)
        ];
    }
    
    return $result;
}

/**
 * Insert wind data into database
 */
function insertWindData($db, $source, $validTime, $forecastHour, $windData) {
    // Delete existing data for this source/time/forecast
    $sql = "DELETE FROM dbo.wx_wind_grid 
            WHERE source = ? AND valid_time_utc = ? AND forecast_hour = ?";
    $stmt = $db->prepare($sql);
    $stmt->execute([$source, $validTime, $forecastHour]);
    
    // Bulk insert
    $inserted = 0;
    
    $sql = "INSERT INTO dbo.wx_wind_grid 
            (source, valid_time_utc, forecast_hour, pressure_mb, lat, lon, 
             u_wind_mps, v_wind_mps, wind_speed_kts, wind_dir_deg)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $db->prepare($sql);
    
    foreach ($windData as $point) {
        // Calculate wind speed and direction
        $u = $point['u_wind_mps'] / 10.0;  // m/s
        $v = $point['v_wind_mps'] / 10.0;  // m/s
        
        $speedMps = sqrt($u * $u + $v * $v);
        $speedKts = (int)round($speedMps * 1.944);
        
        // Wind direction (meteorological: direction wind comes FROM)
        $dirRad = atan2(-$u, -$v);
        $dirDeg = (int)round(fmod(rad2deg($dirRad) + 360, 360));
        
        $stmt->execute([
            $source,
            $validTime,
            $forecastHour,
            $point['pressure_mb'],
            $point['lat'],
            $point['lon'],
            $point['u_wind_mps'],
            $point['v_wind_mps'],
            $speedKts,
            $dirDeg
        ]);
        
        $inserted++;
    }
    
    return $inserted;
}

/**
 * Start an import log entry
 */
function startImportLog($db, $source, $cycleTime, $forecastHour) {
    $sql = "INSERT INTO dbo.wx_wind_import_log 
            (source, cycle_time_utc, forecast_hour, import_started_utc, status)
            VALUES (?, ?, ?, SYSUTCDATETIME(), 'RUNNING');
            SELECT SCOPE_IDENTITY()";
    $stmt = $db->prepare($sql);
    $stmt->execute([$source, $cycleTime, $forecastHour]);
    $stmt->nextRowset();
    return $stmt->fetchColumn();
}

/**
 * Update import log with progress
 */
function updateImportLog($db, $importId, $gridPoints, $fileSize, $downloadMs, $parseMs, $importMs) {
    $sql = "UPDATE dbo.wx_wind_import_log 
            SET grid_points = ISNULL(grid_points, 0) + ?,
                file_size_kb = ISNULL(file_size_kb, 0) + ?,
                download_ms = ISNULL(download_ms, 0) + ?,
                parse_ms = ISNULL(parse_ms, 0) + ?,
                import_ms = ISNULL(import_ms, 0) + ?
            WHERE import_id = ?";
    $stmt = $db->prepare($sql);
    $stmt->execute([
        $gridPoints, 
        (int)($fileSize / 1024), 
        $downloadMs, 
        $parseMs, 
        $importMs, 
        $importId
    ]);
}

/**
 * Complete import log entry
 */
function completeImportLog($db, $importId, $status, $error = null) {
    $sql = "UPDATE dbo.wx_wind_import_log 
            SET import_completed_utc = SYSUTCDATETIME(),
                status = ?,
                error_message = ?
            WHERE import_id = ?";
    $stmt = $db->prepare($sql);
    $stmt->execute([$status, $error, $importId]);
}

/**
 * Purge old wind data
 */
function purgeOldData($db) {
    $sql = "EXEC dbo.sp_PurgeOldWindData @keep_hours = ?";
    $stmt = $db->prepare($sql);
    $stmt->execute([MAX_DATA_AGE_HOURS]);
    return 0;
}

/**
 * Log a message
 */
function logMsg($msg, $toConsole = false) {
    $timestamp = date('Y-m-d H:i:s');
    $line = "[$timestamp] $msg\n";
    
    if ($toConsole) {
        echo $line;
    }
    
    // Append to log file
    $logDir = dirname(LOG_FILE);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

// Run main
main($argv);
