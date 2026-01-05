<?php
/**
 * SIGMET (Significant Meteorological Information) API Endpoint
 * 
 * Returns active SIGMETs in GeoJSON format.
 * Source: NOAA Aviation Weather Center (AWC)
 * 
 * Hazard Types:
 * - CONVECTIVE: Thunderstorms, convection
 * - TURB: Turbulence
 * - ICING: Icing conditions
 * - MTN OBSCN: Mountain obscuration
 * - IFR: IFR conditions
 * - ASH: Volcanic ash
 * - TC: Tropical cyclone
 * 
 * Parameters:
 * - hazard: Filter by hazard type
 * - region: Filter by region
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: max-age=600'); // Cache for 10 minutes

// AWC SIGMET feed URL
// https://aviationweather.gov/api/data/sigmet?format=json
$awc_url = 'https://aviationweather.gov/api/data/sigmet?format=json';

// Try to fetch live data
$response = @file_get_contents($awc_url);

if ($response !== false) {
    $data = json_decode($response, true);
    
    // Convert AWC format to GeoJSON
    $features = [];
    
    if (isset($data['features'])) {
        // Already GeoJSON format
        $features = $data['features'];
    } elseif (is_array($data)) {
        // Need to convert
        foreach ($data as $sigmet) {
            if (isset($sigmet['geom'])) {
                $features[] = [
                    'type' => 'Feature',
                    'properties' => [
                        'id' => $sigmet['airSigmetId'] ?? '',
                        'hazard' => $sigmet['hazard'] ?? 'UNKNOWN',
                        'severity' => $sigmet['severity'] ?? '',
                        'validTimeFrom' => $sigmet['validTimeFrom'] ?? '',
                        'validTimeTo' => $sigmet['validTimeTo'] ?? '',
                        'altitudeLow' => $sigmet['altitudeLow1'] ?? 0,
                        'altitudeHigh' => $sigmet['altitudeHi1'] ?? 600,
                        'rawText' => $sigmet['rawAirSigmet'] ?? ''
                    ],
                    'geometry' => json_decode($sigmet['geom'], true)
                ];
            }
        }
    }
    
    echo json_encode([
        'type' => 'FeatureCollection',
        'name' => 'SIGMETs',
        'source' => 'NOAA Aviation Weather Center',
        'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        'count' => count($features),
        'features' => $features
    ], JSON_PRETTY_PRINT);
    exit;
}

// Fallback: Return empty GeoJSON if fetch fails
echo json_encode([
    'type' => 'FeatureCollection',
    'name' => 'SIGMETs',
    'source' => 'NOAA Aviation Weather Center',
    'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
    'count' => 0,
    'features' => [],
    'note' => 'Unable to fetch SIGMET data from AWC'
], JSON_PRETTY_PRINT);
