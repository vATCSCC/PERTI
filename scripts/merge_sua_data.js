#!/usr/bin/env node
/**
 * SUA Data Merge Script
 *
 * Merges sua_boundaries.json (FAA NASR source with rich metadata) with
 * SUA.geojson (ATCSCC source with more complete coverage).
 *
 * Usage:
 *   node merge_sua_data.js [--dry-run] [--verbose]
 */

const fs = require('fs');
const path = require('path');

// Parse command line options
const args = process.argv.slice(2);
const dryRun = args.includes('--dry-run');
const verbose = args.includes('--verbose');
const help = args.includes('--help');

if (help) {
    console.log('SUA Data Merge Script\n');
    console.log('Usage: node merge_sua_data.js [options]\n');
    console.log('Options:');
    console.log('  --dry-run    Show what would be done without making changes');
    console.log('  --verbose    Show detailed progress information');
    console.log('  --help       Show this help message');
    process.exit(0);
}

// File paths
const boundariesFile = path.join(__dirname, '..', 'api', 'data', 'sua_boundaries.json');
const suaGeojsonFile = path.join(__dirname, '..', 'assets', 'geojson', 'SUA.geojson');
const outputFile = path.join(__dirname, '..', 'api', 'data', 'sua_boundaries.json');
const backupFile = path.join(__dirname, '..', 'api', 'data', 'sua_boundaries.json.bak');

// Type mapping from SUA.geojson colorName to standard suaType
const typeMap = {
    'PROHIBITED': 'P',
    'RESTRICTED': 'R',
    'WARNING': 'W',
    'ALERT': 'A',
    'MOA': 'MOA',
    'NSA': 'NSA',
    'ATCAA': 'ATCAA',
    'TFR': 'TFR',
    'AR': 'AR',
    'ALTRV': 'ALTRV',
    'OPAREA': 'OPAREA',
    'AW': 'AW',
    'USN': 'USN',
    'DZ': 'DZ',
    'ADIZ': 'ADIZ',
    'OSARA': 'OSARA',
    'SUA': 'OTHER',
    'WSRP': 'WSRP',
    'SS': 'SS',
    'USArmy': 'USArmy',
    'LASER': 'LASER',
    'USAF': 'USAF',
    'ANG': 'ANG',
    'NUCLEAR': 'NUCLEAR',
    'NORAD': 'NORAD',
    'NOAA': 'NOAA',
    'NASA': 'NASA',
    'MODEC': 'MODEC',
    'FRZ': 'FRZ',
    '180': 'OTHER',
    '120': 'OTHER',
};

// Type display names
const typeNames = {
    'P': 'Prohibited',
    'R': 'Restricted',
    'W': 'Warning',
    'A': 'Alert',
    'MOA': 'MOA',
    'NSA': 'NSA',
    'ATCAA': 'ATCAA',
    'TFR': 'TFR',
    'AR': 'Air Refueling',
    'ALTRV': 'ALTRV',
    'OPAREA': 'Operating Area',
    'AW': 'AW',
    'USN': 'USN Area',
    'DZ': 'Drop Zone',
    'ADIZ': 'ADIZ',
    'OSARA': 'OSARA',
    'WSRP': 'Weather Radar',
    'SS': 'Supersonic',
    'USArmy': 'US Army',
    'LASER': 'Laser',
    'USAF': 'US Air Force',
    'ANG': 'Air Nat Guard',
    'NUCLEAR': 'Nuclear',
    'NORAD': 'NORAD',
    'NOAA': 'NOAA',
    'NASA': 'NASA',
    'MODEC': 'MODEC',
    'FRZ': 'FRZ',
    'OTHER': 'Other',
};

// Type colors (for new types not in sua_boundaries.json)
const typeColors = {
    'AR': '#00cccc',     // Cyan - Air Refueling
    'ALTRV': '#cc6600',  // Brown - ALTRV
    'OPAREA': '#996699', // Purple-gray - Operating Area
    'AW': '#669900',     // Olive - AW
    'USN': '#003366',    // Navy - USN Area
    'DZ': '#cc0066',     // Pink - Drop Zone
    'ADIZ': '#990000',   // Dark Red - ADIZ
    'OSARA': '#666600',  // Dark olive - OSARA
    'WSRP': '#009999',   // Teal - Weather Radar
    'SS': '#cc3300',     // Red-orange - Supersonic
    'USArmy': '#336600', // Army green
    'LASER': '#ff3399',  // Bright pink - Laser
    'USAF': '#0033cc',   // Air Force blue
    'ANG': '#006633',    // Guard green
    'NUCLEAR': '#ff0000',// Red - Nuclear
    'NORAD': '#333399',  // Dark blue - NORAD
    'NOAA': '#0099cc',   // Light blue - NOAA
    'NASA': '#ff6600',   // Orange - NASA
    'MODEC': '#999966',  // Olive gray
    'FRZ': '#cc0000',    // Dark red - FRZ
    'OTHER': '#999999',  // Gray
};

function log(msg, forceShow = false) {
    if (forceShow || verbose) {
        const timestamp = new Date().toISOString().replace('T', ' ').split('.')[0];
        console.log(`[${timestamp}] ${msg}`);
    }
}

// Normalize a name for comparison
function normalizeName(name) {
    let normalized = name.toUpperCase().trim();
    // Remove common suffixes for comparison
    normalized = normalized.replace(/\s+(MOA|AREA|ZONE)$/i, '');
    // Normalize whitespace
    normalized = normalized.replace(/\s+/g, ' ');
    // Remove punctuation
    normalized = normalized.replace(/[^\w\s]/g, '');
    return normalized;
}

// Extract a clean designator from a name
function extractDesignator(name, colorName) {
    // Try to extract standard designators like R-2303, P-40, W-137, etc.
    const match = name.match(/^([PRWA])-?(\d+[A-Z]?)/);
    if (match) {
        return `${match[1]}-${match[2]}`;
    }
    // For MOAs and other types, use the full name
    return name;
}

log('Starting SUA data merge...', true);

// Load existing boundaries
if (!fs.existsSync(boundariesFile)) {
    log(`ERROR: sua_boundaries.json not found at ${boundariesFile}`, true);
    process.exit(1);
}

const boundaries = JSON.parse(fs.readFileSync(boundariesFile, 'utf8'));
if (!boundaries || !boundaries.features) {
    log('ERROR: Invalid sua_boundaries.json format', true);
    process.exit(1);
}

log(`Loaded ${boundaries.features.length} features from sua_boundaries.json`);

// Load SUA.geojson
if (!fs.existsSync(suaGeojsonFile)) {
    log(`ERROR: SUA.geojson not found at ${suaGeojsonFile}`, true);
    process.exit(1);
}

const suaGeojson = JSON.parse(fs.readFileSync(suaGeojsonFile, 'utf8'));
if (!suaGeojson || !suaGeojson.features) {
    log('ERROR: Invalid SUA.geojson format', true);
    process.exit(1);
}

log(`Loaded ${suaGeojson.features.length} features from SUA.geojson`);

// Build index of existing names for deduplication
const existingNames = new Set();
const existingDesignators = new Set();
for (const feature of boundaries.features) {
    const name = feature.properties?.name || '';
    const designator = feature.properties?.designator || '';
    if (name) {
        existingNames.add(normalizeName(name));
    }
    if (designator) {
        existingDesignators.add(designator.toUpperCase());
    }
}

log(`Built index of ${existingNames.size} existing names and ${existingDesignators.size} designators`);

// Track statistics
const stats = {
    added: 0,
    skippedDuplicate: 0,
    byType: {}
};

// Process SUA.geojson features
const newFeatures = [];
for (const feature of suaGeojson.features) {
    const props = feature.properties || {};
    const name = props.name || '';
    const colorName = props.colorName || 'OTHER';

    if (!name) {
        continue;
    }

    // Check if this feature already exists
    const normalizedName = normalizeName(name);
    const designator = extractDesignator(name, colorName);

    if (existingNames.has(normalizedName) || existingDesignators.has(designator.toUpperCase())) {
        stats.skippedDuplicate++;
        log(`Skipping duplicate: ${name}`);
        continue;
    }

    // Map the type
    const suaType = typeMap[colorName] || 'OTHER';
    const typeName = typeNames[suaType] || suaType;
    const color = props.color || typeColors[suaType] || '#999999';

    // Create enriched feature
    const newFeature = {
        type: 'Feature',
        properties: {
            id: designator,
            designator: designator,
            name: name,
            suaType: suaType,
            type_name: typeName,
            upperLimit: null,  // Unknown from this source
            lowerLimit: null,  // Unknown from this source
            schedule: null,
            scheduleDesc: 'See NOTAM',
            artcc: null,
            priority: 10,  // Lower priority than FAA NASR data
            color: color,
            source: 'ATCSCC'
        },
        geometry: feature.geometry
    };

    newFeatures.push(newFeature);
    stats.added++;
    stats.byType[suaType] = (stats.byType[suaType] || 0) + 1;

    // Mark as existing to prevent duplicates within SUA.geojson
    existingNames.add(normalizedName);
    existingDesignators.add(designator.toUpperCase());

    log(`Adding: ${name} (${suaType})`);
}

// Merge features
const mergedFeatures = [...boundaries.features, ...newFeatures];

// Update type counts
const typeCounts = {};
for (const feature of mergedFeatures) {
    const type = feature.properties?.suaType || 'OTHER';
    typeCounts[type] = (typeCounts[type] || 0) + 1;
}

// Create output structure
const output = {
    type: 'FeatureCollection',
    name: 'FAA Special Use Airspace (Merged)',
    generated: new Date().toISOString(),
    source: 'FAA NASR AIXM 5.0 + ATCSCC SUA.geojson',
    count: mergedFeatures.length,
    types: typeCounts,
    features: mergedFeatures
};

// Output statistics
console.log('');
console.log('=== Merge Statistics ===');
console.log(`Original features: ${boundaries.features.length}`);
console.log(`Added from SUA.geojson: ${stats.added}`);
console.log(`Skipped (duplicates): ${stats.skippedDuplicate}`);
console.log(`Total merged features: ${mergedFeatures.length}`);
console.log('');
console.log('Added by type:');
for (const [type, count] of Object.entries(stats.byType).sort((a, b) => b[1] - a[1])) {
    console.log(`  ${type}: ${count}`);
}
console.log('');
console.log('Final type counts:');
for (const [type, count] of Object.entries(typeCounts).sort((a, b) => b[1] - a[1])) {
    console.log(`  ${type}: ${count}`);
}

if (dryRun) {
    console.log('');
    console.log('DRY RUN - No changes made');
    process.exit(0);
}

// Create backup
if (fs.existsSync(outputFile)) {
    fs.copyFileSync(outputFile, backupFile);
    log(`Created backup at ${backupFile}`);
}

// Write output
fs.writeFileSync(outputFile, JSON.stringify(output, null, 2));
console.log('');
console.log(`Successfully wrote merged data to ${outputFile}`);
console.log('Merge complete!');
