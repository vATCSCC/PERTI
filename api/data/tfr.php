<?php
/**
 * TFR (Temporary Flight Restrictions) API Endpoint
 * 
 * Returns active TFRs in GeoJSON format.
 * Source: FAA TFR feed (https://tfr.faa.gov)
 * 
 * Types:
 * - VIP: VIP movement
 * - SECURITY: Security related
 * - HAZARDS: Hazards (fire, disaster)
 * - SPACE: Space operations
 * - STADIUM: Stadium/sporting events
 * - SPECIAL: Special events
 * 
 * Parameters:
 * - active: Only show currently active (default: true)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: max-age=300'); // Cache for 5 minutes

// Static/cached TFR file path
$tfr_file = __DIR__ . '/../../assets/geojson/tfr_active.json';

// Check if cached file exists and is recent (< 10 min old)
if (file_exists($tfr_file)) {
    $file_age = time() - filemtime($tfr_file);
    
    if ($file_age < 600) { // Less than 10 minutes old
        $data = json_decode(file_get_contents($tfr_file), true);
        echo json_encode($data);
        exit;
    }
}

// TODO: Implement live TFR fetch from FAA
// For now, return empty GeoJSON
// 
// Live implementation would:
// 1. Fetch from https://tfr.faa.gov/save_api/save/getItem 
// 2. Parse XML response
// 3. Convert geometry to GeoJSON
// 4. Cache result

echo json_encode([
    'type' => 'FeatureCollection',
    'name' => 'Temporary Flight Restrictions',
    'source' => 'https://tfr.faa.gov',
    'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
    'count' => 0,
    'features' => [],
    'note' => 'TFR feed not yet configured. Implement FAA TFR parser for live data.'
], JSON_PRETTY_PRINT);
