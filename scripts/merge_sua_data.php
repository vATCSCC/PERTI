#!/usr/bin/env php
<?php
/**
 * SUA Data Merge Script
 *
 * Merges sua_boundaries.json (FAA NASR source with rich metadata) with
 * SUA.geojson (ATCSCC source with more complete coverage).
 *
 * Keeps existing entries from sua_boundaries.json and adds missing features
 * from SUA.geojson with appropriate type mappings.
 *
 * Usage:
 *   php merge_sua_data.php [--dry-run] [--verbose]
 *
 * @author PERTI System
 */

// Parse command line options
$options = getopt('', ['dry-run', 'verbose', 'help']);
$dryRun = isset($options['dry-run']);
$verbose = isset($options['verbose']);

if (isset($options['help'])) {
    echo "SUA Data Merge Script\n\n";
    echo "Usage: php merge_sua_data.php [options]\n\n";
    echo "Options:\n";
    echo "  --dry-run    Show what would be done without making changes\n";
    echo "  --verbose    Show detailed progress information\n";
    echo "  --help       Show this help message\n";
    exit(0);
}

// File paths
$boundariesFile = __DIR__ . '/../api/data/sua_boundaries.json';
$suaGeojsonFile = __DIR__ . '/../assets/geojson/SUA.geojson';
$outputFile = __DIR__ . '/../api/data/sua_boundaries.json';
$backupFile = __DIR__ . '/../api/data/sua_boundaries.json.bak';

// Type mapping from SUA.geojson colorName to standard suaType
$typeMap = [
    'PROHIBITED' => 'P',
    'RESTRICTED' => 'R',
    'WARNING' => 'W',
    'ALERT' => 'A',
    'MOA' => 'MOA',
    'NSA' => 'NSA',
    'ATCAA' => 'ATCAA',
    'TFR' => 'TFR',
    'AR' => 'AR',
    'ALTRV' => 'ALTRV',
    'OPAREA' => 'OPAREA',
    'AW' => 'AW',
    'USN' => 'USN',
    'DZ' => 'DZ',
    'ADIZ' => 'ADIZ',
    'OSARA' => 'OSARA',
    'SUA' => 'OTHER',
    'WSRP' => 'WSRP',
    'SS' => 'SS',
    'USArmy' => 'USArmy',
    'LASER' => 'LASER',
    'USAF' => 'USAF',
    'ANG' => 'ANG',
    'NUCLEAR' => 'NUCLEAR',
    'NORAD' => 'NORAD',
    'NOAA' => 'NOAA',
    'NASA' => 'NASA',
    'MODEC' => 'MODEC',
    'FRZ' => 'FRZ',
    '180' => 'OTHER',
    '120' => 'OTHER',
];

// Type display names
$typeNames = [
    'P' => 'Prohibited',
    'R' => 'Restricted',
    'W' => 'Warning',
    'A' => 'Alert',
    'MOA' => 'MOA',
    'NSA' => 'NSA',
    'ATCAA' => 'ATCAA',
    'TFR' => 'TFR',
    'AR' => 'Air Refueling',
    'ALTRV' => 'ALTRV',
    'OPAREA' => 'Operating Area',
    'AW' => 'AW',
    'USN' => 'USN Area',
    'DZ' => 'Drop Zone',
    'ADIZ' => 'ADIZ',
    'OSARA' => 'OSARA',
    'WSRP' => 'Weather Radar',
    'SS' => 'Supersonic',
    'USArmy' => 'US Army',
    'LASER' => 'Laser',
    'USAF' => 'US Air Force',
    'ANG' => 'Air Nat Guard',
    'NUCLEAR' => 'Nuclear',
    'NORAD' => 'NORAD',
    'NOAA' => 'NOAA',
    'NASA' => 'NASA',
    'MODEC' => 'MODEC',
    'FRZ' => 'FRZ',
    'OTHER' => 'Other',
];

// Type colors (for new types not in sua_boundaries.json)
$typeColors = [
    'AR' => '#00cccc',     // Cyan - Air Refueling
    'ALTRV' => '#cc6600',  // Brown - ALTRV
    'OPAREA' => '#996699', // Purple-gray - Operating Area
    'AW' => '#669900',     // Olive - AW
    'USN' => '#003366',    // Navy - USN Area
    'DZ' => '#cc0066',     // Pink - Drop Zone
    'ADIZ' => '#990000',   // Dark Red - ADIZ
    'OSARA' => '#666600',  // Dark olive - OSARA
    'WSRP' => '#009999',   // Teal - Weather Radar
    'SS' => '#cc3300',     // Red-orange - Supersonic
    'USArmy' => '#336600', // Army green
    'LASER' => '#ff3399',  // Bright pink - Laser
    'USAF' => '#0033cc',   // Air Force blue
    'ANG' => '#006633',    // Guard green
    'NUCLEAR' => '#ff0000',// Red - Nuclear
    'NORAD' => '#333399',  // Dark blue - NORAD
    'NOAA' => '#0099cc',   // Light blue - NOAA
    'NASA' => '#ff6600',   // Orange - NASA
    'MODEC' => '#999966',  // Olive gray
    'FRZ' => '#cc0000',    // Dark red - FRZ
    'OTHER' => '#999999',  // Gray
];

function log_msg($msg, $verbose = false, $forceShow = false) {
    global $verbose;
    if ($forceShow || $verbose) {
        echo date('[Y-m-d H:i:s] ') . $msg . "\n";
    }
}

// Normalize a name for comparison (lowercase, remove extra spaces, standardize punctuation)
function normalizeName($name) {
    $name = strtoupper(trim($name));
    // Remove common suffixes for comparison
    $name = preg_replace('/\s+(MOA|AREA|ZONE)$/i', '', $name);
    // Normalize whitespace
    $name = preg_replace('/\s+/', ' ', $name);
    // Remove punctuation
    $name = preg_replace('/[^\w\s]/', '', $name);
    return $name;
}

// Extract a clean designator from a name
function extractDesignator($name, $colorName) {
    // Try to extract standard designators like R-2303, P-40, W-137, etc.
    if (preg_match('/^([PRWA])-?(\d+[A-Z]?)/', $name, $matches)) {
        return $matches[1] . '-' . $matches[2];
    }
    // For MOAs and other types, use the full name
    return $name;
}

log_msg("Starting SUA data merge...", true, true);

// Load existing boundaries
if (!file_exists($boundariesFile)) {
    log_msg("ERROR: sua_boundaries.json not found at $boundariesFile", true, true);
    exit(1);
}

$boundaries = json_decode(file_get_contents($boundariesFile), true);
if (!$boundaries || !isset($boundaries['features'])) {
    log_msg("ERROR: Invalid sua_boundaries.json format", true, true);
    exit(1);
}

log_msg("Loaded " . count($boundaries['features']) . " features from sua_boundaries.json", true);

// Load SUA.geojson
if (!file_exists($suaGeojsonFile)) {
    log_msg("ERROR: SUA.geojson not found at $suaGeojsonFile", true, true);
    exit(1);
}

$suaGeojson = json_decode(file_get_contents($suaGeojsonFile), true);
if (!$suaGeojson || !isset($suaGeojson['features'])) {
    log_msg("ERROR: Invalid SUA.geojson format", true, true);
    exit(1);
}

log_msg("Loaded " . count($suaGeojson['features']) . " features from SUA.geojson", true);

// Build index of existing names for deduplication
$existingNames = [];
$existingDesignators = [];
foreach ($boundaries['features'] as $feature) {
    $name = $feature['properties']['name'] ?? '';
    $designator = $feature['properties']['designator'] ?? '';
    if ($name) {
        $existingNames[normalizeName($name)] = true;
    }
    if ($designator) {
        $existingDesignators[strtoupper($designator)] = true;
    }
}

log_msg("Built index of " . count($existingNames) . " existing names and " . count($existingDesignators) . " designators", true);

// Track statistics
$stats = [
    'added' => 0,
    'skipped_duplicate' => 0,
    'by_type' => []
];

// Process SUA.geojson features
$newFeatures = [];
foreach ($suaGeojson['features'] as $feature) {
    $props = $feature['properties'] ?? [];
    $name = $props['name'] ?? '';
    $colorName = $props['colorName'] ?? 'OTHER';

    if (!$name) {
        continue;
    }

    // Check if this feature already exists
    $normalizedName = normalizeName($name);
    $designator = extractDesignator($name, $colorName);

    if (isset($existingNames[$normalizedName]) || isset($existingDesignators[strtoupper($designator)])) {
        $stats['skipped_duplicate']++;
        log_msg("Skipping duplicate: $name", true);
        continue;
    }

    // Map the type
    $suaType = $typeMap[$colorName] ?? 'OTHER';
    $typeName = $typeNames[$suaType] ?? $suaType;
    $color = $props['color'] ?? ($typeColors[$suaType] ?? '#999999');

    // Create enriched feature
    $newFeature = [
        'type' => 'Feature',
        'properties' => [
            'id' => $designator,
            'designator' => $designator,
            'name' => $name,
            'suaType' => $suaType,
            'type_name' => $typeName,
            'upperLimit' => null,  // Unknown from this source
            'lowerLimit' => null,  // Unknown from this source
            'schedule' => null,
            'scheduleDesc' => 'See NOTAM',
            'artcc' => null,
            'priority' => 10,  // Lower priority than FAA NASR data
            'color' => $color,
            'source' => 'ATCSCC'
        ],
        'geometry' => $feature['geometry']
    ];

    $newFeatures[] = $newFeature;
    $stats['added']++;
    $stats['by_type'][$suaType] = ($stats['by_type'][$suaType] ?? 0) + 1;

    // Mark as existing to prevent duplicates within SUA.geojson
    $existingNames[$normalizedName] = true;
    $existingDesignators[strtoupper($designator)] = true;

    log_msg("Adding: $name ($suaType)", true);
}

// Merge features
$mergedFeatures = array_merge($boundaries['features'], $newFeatures);

// Update type counts
$typeCounts = [];
foreach ($mergedFeatures as $feature) {
    $type = $feature['properties']['suaType'] ?? 'OTHER';
    $typeCounts[$type] = ($typeCounts[$type] ?? 0) + 1;
}

// Create output structure
$output = [
    'type' => 'FeatureCollection',
    'name' => 'FAA Special Use Airspace (Merged)',
    'generated' => date('c'),
    'source' => 'FAA NASR AIXM 5.0 + ATCSCC SUA.geojson',
    'count' => count($mergedFeatures),
    'types' => $typeCounts,
    'features' => $mergedFeatures
];

// Output statistics
log_msg("", true, true);
log_msg("=== Merge Statistics ===", true, true);
log_msg("Original features: " . count($boundaries['features']), true, true);
log_msg("Added from SUA.geojson: " . $stats['added'], true, true);
log_msg("Skipped (duplicates): " . $stats['skipped_duplicate'], true, true);
log_msg("Total merged features: " . count($mergedFeatures), true, true);
log_msg("", true, true);
log_msg("Added by type:", true, true);
foreach ($stats['by_type'] as $type => $count) {
    log_msg("  $type: $count", true, true);
}
log_msg("", true, true);
log_msg("Final type counts:", true, true);
foreach ($typeCounts as $type => $count) {
    log_msg("  $type: $count", true, true);
}

if ($dryRun) {
    log_msg("", true, true);
    log_msg("DRY RUN - No changes made", true, true);
    exit(0);
}

// Create backup
if (file_exists($outputFile)) {
    if (copy($outputFile, $backupFile)) {
        log_msg("Created backup at $backupFile", true);
    }
}

// Write output
$json = json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if (file_put_contents($outputFile, $json)) {
    log_msg("Successfully wrote merged data to $outputFile", true, true);
} else {
    log_msg("ERROR: Failed to write output file", true, true);
    exit(1);
}

log_msg("Merge complete!", true, true);
