<?php
/**
 * SUA (Special Use Airspace) API Endpoint
 * 
 * Returns SUA boundaries in GeoJSON format.
 * Source: FAA NASR AIXM 5.0 data (pre-parsed to JSON)
 * 
 * Types:
 * - PA: Prohibited Area
 * - RA: Restricted Area
 * - WA: Warning Area
 * - AA: Alert Area
 * - MOA: Military Operations Area
 * - NSA: National Security Area
 * 
 * Parameters:
 * - type: Filter by type (comma-separated)
 * - artcc: Filter by ARTCC
 * - active: Only show currently active (based on schedule)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: max-age=3600'); // Cache for 1 hour

// Static GeoJSON file path (generated from AIXM data)
$sua_file = __DIR__ . '/sua_boundaries.json';

// Check if pre-generated file exists
if (file_exists($sua_file)) {
    $data = json_decode(file_get_contents($sua_file), true);
    
    // Apply filters if requested
    $type_filter = isset($_GET['type']) ? explode(',', strtoupper($_GET['type'])) : null;
    $artcc_filter = isset($_GET['artcc']) ? strtoupper($_GET['artcc']) : null;
    
    if ($type_filter || $artcc_filter) {
        $data['features'] = array_filter($data['features'], function($f) use ($type_filter, $artcc_filter) {
            $props = $f['properties'] ?? [];
            
            if ($type_filter && !in_array($props['suaType'] ?? '', $type_filter)) {
                return false;
            }
            
            if ($artcc_filter && ($props['artcc'] ?? '') !== $artcc_filter) {
                return false;
            }
            
            return true;
        });
        
        $data['features'] = array_values($data['features']);
    }
    
    echo json_encode($data);
    exit;
}

// Return empty GeoJSON if file doesn't exist
// This allows the NOD to work without SUA data
echo json_encode([
    'type' => 'FeatureCollection',
    'name' => 'Special Use Airspace',
    'source' => 'FAA NASR AIXM 5.0',
    'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
    'count' => 0,
    'features' => [],
    'note' => 'SUA boundaries not yet configured. Run AIXM parser to generate sua_boundaries.json'
], JSON_PRETTY_PRINT);
