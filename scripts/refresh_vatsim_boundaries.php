#!/usr/bin/env php
<?php
/**
 * VATSIM Boundary Data Refresh Script
 * 
 * Downloads and transforms boundary data from official VATSIM GitHub repositories:
 * - vatspy-data-project: FIR/ARTCC boundaries (worldwide)
 * - simaware-tracon-project: TRACON/APP boundaries (worldwide)
 * 
 * Usage: 
 *   php refresh_vatsim_boundaries.php [--dry-run] [--verbose]
 * 
 * Schedule with cron for periodic updates (e.g., daily or weekly):
 *   0 4 * * 0 /usr/bin/php /scripts/refresh_vatsim_boundaries.php >> /scripts/boundary_refresh.log 2>&1
 * 
 * @author PERTI System
 * @version 1.0.0
 */

// Configuration
$config = [
    // Source URLs
    'vatspy_url' => 'https://raw.githubusercontent.com/vatsimnetwork/vatspy-data-project/master/Boundaries.geojson',
    'tracon_release_url' => 'https://github.com/vatsimnetwork/simaware-tracon-project/releases/latest/download/TRACONBoundaries.geojson',
    'tracon_fallback_url' => 'https://raw.githubusercontent.com/vatsimnetwork/simaware-tracon-project/main/TRACONBoundaries.geojson',
    
    // Output paths (relative to wwwroot)
    'output_dir' => __DIR__ . '/../assets/geojson',
    'artcc_output' => 'artcc.json',
    'tracon_output' => 'tracon.json',
    
    // Backup settings
    'backup_enabled' => true,
    'backup_dir' => __DIR__ . '/../assets/geojson/backup',
    
    // Timeout for HTTP requests (seconds)
    'timeout' => 60,
    
    // User agent for requests
    'user_agent' => 'PERTI-VATSIM-Boundary-Updater/1.0',
];

// Parse command line options
$options = getopt('', ['dry-run', 'verbose', 'help']);
$dryRun = isset($options['dry-run']);
$verbose = isset($options['verbose']);

if (isset($options['help'])) {
    echo "VATSIM Boundary Data Refresh Script\n\n";
    echo "Usage: php refresh_vatsim_boundaries.php [options]\n\n";
    echo "Options:\n";
    echo "  --dry-run    Download and transform but don't save files\n";
    echo "  --verbose    Show detailed progress information\n";
    echo "  --help       Show this help message\n\n";
    exit(0);
}

/**
 * Log a message with timestamp
 */
function logMsg($message, $level = 'INFO') {
    global $verbose;
    $timestamp = date('Y-m-d H:i:s');
    $output = "[$timestamp] [$level] $message\n";
    
    if ($level === 'ERROR' || $verbose || $level === 'SUCCESS') {
        echo $output;
    }
    
    return $output;
}

/**
 * Download a file from URL with retry logic
 */
function downloadFile($url, $timeout = 60, $retries = 3) {
    global $config;
    
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: {$config['user_agent']}\r\n",
            'timeout' => $timeout,
            'follow_location' => true,
            'max_redirects' => 5,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ]
    ]);
    
    for ($i = 0; $i < $retries; $i++) {
        $content = @file_get_contents($url, false, $context);
        if ($content !== false) {
            return $content;
        }
        logMsg("Attempt " . ($i + 1) . " failed for $url, retrying...", 'WARN');
        sleep(2);
    }
    
    return false;
}

/**
 * Calculate polygon area using Shoelace formula (for flat coordinates)
 * Returns approximate area in square degrees
 */
function calculatePolygonArea($coordinates) {
    if (empty($coordinates) || count($coordinates) < 3) {
        return 0;
    }
    
    $n = count($coordinates);
    $area = 0;
    
    for ($i = 0; $i < $n; $i++) {
        $j = ($i + 1) % $n;
        $area += $coordinates[$i][0] * $coordinates[$j][1];
        $area -= $coordinates[$j][0] * $coordinates[$i][1];
    }
    
    return abs($area) / 2;
}

/**
 * Calculate polygon perimeter (for flat coordinates)
 * Returns approximate length in degrees
 */
function calculatePolygonLength($coordinates) {
    if (empty($coordinates) || count($coordinates) < 2) {
        return 0;
    }
    
    $length = 0;
    $n = count($coordinates);
    
    for ($i = 0; $i < $n - 1; $i++) {
        $dx = $coordinates[$i + 1][0] - $coordinates[$i][0];
        $dy = $coordinates[$i + 1][1] - $coordinates[$i][1];
        $length += sqrt($dx * $dx + $dy * $dy);
    }
    
    return $length;
}

/**
 * Get outer ring coordinates from a geometry
 */
function getOuterCoordinates($geometry) {
    if (!isset($geometry['type']) || !isset($geometry['coordinates'])) {
        return [];
    }
    
    switch ($geometry['type']) {
        case 'Polygon':
            return $geometry['coordinates'][0] ?? [];
        case 'MultiPolygon':
            // Return the first polygon's outer ring (largest is typically first)
            return $geometry['coordinates'][0][0] ?? [];
        default:
            return [];
    }
}

/**
 * Transform VATSpy boundaries to ARTCC format
 */
function transformVatspyToArtcc($sourceData) {
    logMsg("Transforming VATSpy boundaries to ARTCC format...");
    
    $features = [];
    $fid = 0;
    
    foreach ($sourceData['features'] as $feature) {
        $props = $feature['properties'] ?? [];
        $geometry = $feature['geometry'] ?? null;
        
        if (!$geometry) {
            continue;
        }
        
        // Map VATSpy properties to ARTCC schema
        $newProps = [
            'fid' => $fid++,
            'FIRname' => $props['id'] ?? 'Unknown',
            'ICAOCODE' => $props['id'] ?? null,
            'VATSIM Reg' => $props['region'] ?? null,
            'VATSIM Div' => $props['division'] ?? null,
            'VATSIM Sub' => $props['id'] ?? null,
            'FLOOR' => null,
            'CEILING' => null,
            'Status' => null,
            'COORDINATE' => null,
            // Preserve additional useful properties
            'oceanic' => isset($props['oceanic']) ? (int)$props['oceanic'] : 0,
            'label_lat' => isset($props['label_lat']) ? (float)$props['label_lat'] : null,
            'label_lon' => isset($props['label_lon']) ? (float)$props['label_lon'] : null,
        ];
        
        $features[] = [
            'type' => 'Feature',
            'properties' => $newProps,
            'geometry' => $geometry,
        ];
    }
    
    logMsg("Transformed " . count($features) . " ARTCC/FIR features");
    
    return [
        'type' => 'FeatureCollection',
        'name' => 'VATSIM_FIR_Boundaries',
        'crs' => [
            'type' => 'name',
            'properties' => ['name' => 'urn:ogc:def:crs:OGC:1.3:CRS84']
        ],
        'features' => $features,
        'metadata' => [
            'source' => 'vatsimnetwork/vatspy-data-project',
            'updated_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'feature_count' => count($features),
        ]
    ];
}

/**
 * Transform SimAware TRACON boundaries to local TRACON format
 */
function transformSimawareToTracon($sourceData) {
    logMsg("Transforming SimAware TRACON boundaries to local format...");
    
    $features = [];
    $objectId = 1;
    
    foreach ($sourceData['features'] as $feature) {
        $props = $feature['properties'] ?? [];
        $geometry = $feature['geometry'] ?? null;
        
        if (!$geometry) {
            continue;
        }
        
        // Calculate geometry metrics
        $coords = getOuterCoordinates($geometry);
        $shapeLength = calculatePolygonLength($coords);
        $shapeArea = calculatePolygonArea($coords);
        
        // Determine ARTCC from id or prefix
        $id = $props['id'] ?? '';
        $prefixes = $props['prefix'] ?? [];
        $firstPrefix = is_array($prefixes) && !empty($prefixes) ? $prefixes[0] : $id;
        
        // Try to derive ARTCC from prefix (for US facilities)
        $artcc = '';
        if (strlen($firstPrefix) === 4 && substr($firstPrefix, 0, 1) === 'K') {
            // US airport code - could map to ARTCC but we'll use facility ID
            $artcc = strtolower(substr($firstPrefix, 1, 3));
        } else if (strlen($firstPrefix) >= 3) {
            $artcc = strtolower(substr($firstPrefix, 0, 3));
        }
        
        // Build sector identifier
        $sector = $id;
        
        // Build label (use name if available, otherwise id)
        $label = $props['name'] ?? $id;
        
        $newProps = [
            'OBJECTID' => $objectId++,
            'artcc' => $artcc,
            'sector' => $sector,
            'label' => $label,
            'Shape_Length' => round($shapeLength, 10),
            'Shape_Area' => round($shapeArea, 10),
            // Preserve label coordinates if available
            'label_lat' => isset($props['label_lat']) ? (float)$props['label_lat'] : null,
            'label_lon' => isset($props['label_lon']) ? (float)$props['label_lon'] : null,
        ];
        
        $features[] = [
            'type' => 'Feature',
            'id' => $objectId - 1,
            'geometry' => $geometry,
            'properties' => $newProps,
        ];
    }
    
    logMsg("Transformed " . count($features) . " TRACON features");
    
    return [
        'type' => 'FeatureCollection',
        'features' => $features,
        'metadata' => [
            'source' => 'vatsimnetwork/simaware-tracon-project',
            'updated_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'feature_count' => count($features),
        ]
    ];
}

/**
 * Create backup of existing file
 */
function backupFile($filepath, $backupDir) {
    if (!file_exists($filepath)) {
        return true;
    }
    
    if (!is_dir($backupDir)) {
        if (!mkdir($backupDir, 0755, true)) {
            logMsg("Failed to create backup directory: $backupDir", 'ERROR');
            return false;
        }
    }
    
    $filename = basename($filepath);
    $timestamp = date('Ymd_His');
    $backupPath = "$backupDir/{$filename}.{$timestamp}.bak";
    
    if (!copy($filepath, $backupPath)) {
        logMsg("Failed to create backup: $backupPath", 'ERROR');
        return false;
    }
    
    logMsg("Created backup: $backupPath");
    
    // Clean up old backups (keep last 5)
    $pattern = "$backupDir/{$filename}.*.bak";
    $backups = glob($pattern);
    if (count($backups) > 5) {
        usort($backups, function($a, $b) {
            return filemtime($a) - filemtime($b);
        });
        $toDelete = array_slice($backups, 0, count($backups) - 5);
        foreach ($toDelete as $old) {
            unlink($old);
            logMsg("Removed old backup: $old");
        }
    }
    
    return true;
}

/**
 * Save GeoJSON data to file
 */
function saveGeoJson($data, $filepath, $dryRun = false) {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
    if ($json === false) {
        logMsg("Failed to encode JSON: " . json_last_error_msg(), 'ERROR');
        return false;
    }
    
    if ($dryRun) {
        logMsg("DRY RUN: Would save " . strlen($json) . " bytes to $filepath");
        return true;
    }
    
    $result = file_put_contents($filepath, $json);
    if ($result === false) {
        logMsg("Failed to write file: $filepath", 'ERROR');
        return false;
    }
    
    logMsg("Saved " . strlen($json) . " bytes to $filepath", 'SUCCESS');
    return true;
}

// Main execution
logMsg("=== VATSIM Boundary Data Refresh Started ===");
logMsg("Dry run: " . ($dryRun ? 'YES' : 'NO'));

$success = true;
$stats = [
    'artcc_features' => 0,
    'tracon_features' => 0,
];

// Ensure output directory exists
if (!is_dir($config['output_dir'])) {
    if (!$dryRun && !mkdir($config['output_dir'], 0755, true)) {
        logMsg("Failed to create output directory: {$config['output_dir']}", 'ERROR');
        exit(1);
    }
}

// ============================================
// Process VATSpy ARTCC/FIR Boundaries
// ============================================
logMsg("Downloading VATSpy FIR boundaries from GitHub...");
$vatspyData = downloadFile($config['vatspy_url'], $config['timeout']);

if ($vatspyData === false) {
    logMsg("Failed to download VATSpy boundaries", 'ERROR');
    $success = false;
} else {
    logMsg("Downloaded " . strlen($vatspyData) . " bytes");
    
    $vatspyJson = json_decode($vatspyData, true);
    if ($vatspyJson === null) {
        logMsg("Failed to parse VATSpy JSON: " . json_last_error_msg(), 'ERROR');
        $success = false;
    } else {
        $artccData = transformVatspyToArtcc($vatspyJson);
        $stats['artcc_features'] = count($artccData['features']);
        
        $artccPath = $config['output_dir'] . '/' . $config['artcc_output'];
        
        if ($config['backup_enabled'] && !$dryRun) {
            backupFile($artccPath, $config['backup_dir']);
        }
        
        if (!saveGeoJson($artccData, $artccPath, $dryRun)) {
            $success = false;
        }
    }
}

// ============================================
// Process SimAware TRACON Boundaries
// ============================================
logMsg("Downloading SimAware TRACON boundaries from GitHub...");

// Try release URL first, then fallback
$traconData = downloadFile($config['tracon_release_url'], $config['timeout']);
if ($traconData === false) {
    logMsg("Release URL failed, trying fallback...", 'WARN');
    $traconData = downloadFile($config['tracon_fallback_url'], $config['timeout']);
}

if ($traconData === false) {
    logMsg("Failed to download TRACON boundaries", 'ERROR');
    $success = false;
} else {
    logMsg("Downloaded " . strlen($traconData) . " bytes");
    
    $traconJson = json_decode($traconData, true);
    if ($traconJson === null) {
        logMsg("Failed to parse TRACON JSON: " . json_last_error_msg(), 'ERROR');
        $success = false;
    } else {
        $traconOutput = transformSimawareToTracon($traconJson);
        $stats['tracon_features'] = count($traconOutput['features']);
        
        $traconPath = $config['output_dir'] . '/' . $config['tracon_output'];
        
        if ($config['backup_enabled'] && !$dryRun) {
            backupFile($traconPath, $config['backup_dir']);
        }
        
        if (!saveGeoJson($traconOutput, $traconPath, $dryRun)) {
            $success = false;
        }
    }
}

// Summary
logMsg("=== Refresh Complete ===");
logMsg("ARTCC/FIR features: " . $stats['artcc_features']);
logMsg("TRACON features: " . $stats['tracon_features']);
logMsg("Status: " . ($success ? 'SUCCESS' : 'FAILED'), $success ? 'SUCCESS' : 'ERROR');

exit($success ? 0 : 1);
