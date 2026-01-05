/**
 * SUA GeoJSON Transformation Script
 *
 * Transforms the existing SUA.geojson to the new schema format with:
 * - Proper geometry type classification (polygon/line)
 * - Border style detection (solid/dashed)
 * - Group/type/subtype classification
 * - Altitude and ARTCC extraction
 * - Closure of unclosed polygon geometries
 *
 * Usage: node scripts/transform_sua_geojson.js
 */

const fs = require('fs');
const path = require('path');

// Configuration
const INPUT_FILE = path.join(__dirname, '..', 'assets', 'geojson', 'SUA.geojson');
const OUTPUT_FILE = path.join(__dirname, '..', 'assets', 'geojson', 'SUA_transformed.geojson');
const REPORT_FILE = path.join(__dirname, '..', 'assets', 'geojson', 'SUA_transform_report.json');

// Type to Group mapping
const TYPE_TO_GROUP = {
    // Regulatory
    'P': 'REGULATORY',
    'R': 'REGULATORY',
    'W': 'REGULATORY',
    'A': 'REGULATORY',
    'NSA': 'REGULATORY',
    'PROHIBITED': 'REGULATORY',
    'RESTRICTED': 'REGULATORY',
    'WARNING': 'REGULATORY',
    'ALERT': 'REGULATORY',

    // Military
    'MOA': 'MILITARY',
    'ATCAA': 'MILITARY',
    'ALTRV': 'MILITARY',
    'USAF': 'MILITARY',
    'USArmy': 'MILITARY',
    'ANG': 'MILITARY',
    'USN': 'MILITARY',
    'NORAD': 'MILITARY',
    'OPAREA': 'MILITARY',

    // Routes
    'AR': 'ROUTES',
    'IR': 'ROUTES',
    'VR': 'ROUTES',
    'SR': 'ROUTES',
    'MTR': 'ROUTES',
    'OSARA': 'ROUTES',

    // Special
    'TFR': 'SPECIAL',
    'DZ': 'SPECIAL',
    'SPACE': 'SPECIAL',
    'SS': 'SPECIAL',
    'CARF': 'SPECIAL',
    'TSA': 'SPECIAL',
    'LASER': 'SPECIAL',
    'NUCLEAR': 'SPECIAL',

    // DC Area
    'SFRA': 'DC_AREA',
    'FRZ': 'DC_AREA',
    'ADIZ': 'DC_AREA',
    '120': 'DC_AREA',    // DC Speed ring
    '180': 'DC_AREA',    // DC SFRA

    // AWACS
    'AW': 'AWACS',
    'AWACS': 'AWACS',

    // Other
    'NOAA': 'OTHER',
    'NASA': 'OTHER',
    'NMS': 'OTHER',
    'WSRP': 'OTHER',
    'MODEC': 'OTHER',
    'SUA': 'OTHER',
    'Unknown': 'OTHER'
};

// Types that are inherently lines (not areas)
const LINE_TYPES = ['AR', 'IR', 'VR', 'SR', 'MTR', 'OSARA', 'ANG', 'WSRP'];

// Types that may have dashed borders
const DASHED_BORDER_TYPES = ['120', 'SS', 'ADIZ'];

// Default colors by group (if not specified in feature)
const GROUP_COLORS = {
    'REGULATORY': '#ff0000',
    'MILITARY': '#0000ff',
    'ROUTES': '#00ff00',
    'SPECIAL': '#ff00ff',
    'DC_AREA': '#ff8800',
    'SURFACE_OPS': '#888888',
    'AWACS': '#00ffff',
    'OTHER': '#999999'
};

// Statistics
const stats = {
    total: 0,
    polygons: { solid: 0, dashed: 0 },
    lines: { solid: 0, dashed: 0 },
    dashSegments: 0,
    closureFixed: 0,
    byGroup: {},
    byType: {},
    errors: []
};

/**
 * Check if coordinates form a closed ring
 */
function isClosed(coords) {
    if (!coords || coords.length < 3) return false;
    const first = coords[0];
    const last = coords[coords.length - 1];
    return first[0] === last[0] && first[1] === last[1];
}

/**
 * Check if coordinates are approximately closed (within tolerance)
 */
function isApproxClosed(coords, tolerance = 0.0001) {
    if (!coords || coords.length < 3) return false;
    const first = coords[0];
    const last = coords[coords.length - 1];
    return Math.abs(first[0] - last[0]) < tolerance &&
           Math.abs(first[1] - last[1]) < tolerance;
}

/**
 * Close coordinates if they should form a ring
 */
function closeCoords(coords) {
    if (!coords || coords.length < 3) return coords;
    if (!isClosed(coords) && !isApproxClosed(coords)) {
        return [...coords, coords[0]];
    }
    return coords;
}

/**
 * Detect if a MultiLineString represents dash segments
 * (many short 2-3 point segments = dashed line rendering)
 */
function isDashSegments(geometry) {
    if (geometry.type !== 'MultiLineString') return false;

    const coords = geometry.coordinates;
    if (coords.length < 5) return false;

    // Check if most segments are very short (2-3 points)
    let shortSegments = 0;
    for (const segment of coords) {
        if (segment.length <= 3) shortSegments++;
    }

    return shortSegments / coords.length > 0.8;
}

/**
 * Determine the geometry classification for a feature
 */
function classifyGeometry(feature) {
    const props = feature.properties || {};
    const geom = feature.geometry;
    const colorName = props.colorName || 'Unknown';

    // Check if type is inherently a line
    const isLineType = LINE_TYPES.includes(colorName);

    // Check for dash segments (MultiLineString with many short segments)
    if (isDashSegments(geom)) {
        return { type: 'dash_segments', border: 'dashed' };
    }

    // For LineString
    if (geom.type === 'LineString') {
        const coords = geom.coordinates;
        const closed = isClosed(coords) || isApproxClosed(coords);

        if (isLineType) {
            return { type: 'line', border: 'solid' };
        }

        if (closed || coords.length > 15) {
            // Likely a polygon (closed or has many points)
            return { type: 'polygon', border: 'solid' };
        }

        return { type: 'line', border: 'solid' };
    }

    // For MultiLineString
    if (geom.type === 'MultiLineString') {
        if (isLineType) {
            return { type: 'line', border: 'solid' };
        }

        // Check if segments form closed areas
        let closedSegments = 0;
        for (const segment of geom.coordinates) {
            if (isClosed(segment) || isApproxClosed(segment) || segment.length > 15) {
                closedSegments++;
            }
        }

        if (closedSegments > geom.coordinates.length / 2) {
            return { type: 'polygon', border: 'solid' };
        }

        // Check for dashed border types
        if (DASHED_BORDER_TYPES.includes(colorName)) {
            return { type: 'polygon', border: 'dashed' };
        }

        return { type: 'polygon', border: 'solid' };
    }

    // Already a polygon type
    if (geom.type === 'Polygon' || geom.type === 'MultiPolygon') {
        const border = DASHED_BORDER_TYPES.includes(colorName) ? 'dashed' : 'solid';
        return { type: 'polygon', border };
    }

    return { type: 'unknown', border: 'solid' };
}

/**
 * Convert geometry to proper type (LineString -> Polygon if needed)
 */
function convertGeometry(geometry, classification) {
    if (classification.type === 'dash_segments') {
        // Keep as-is for now, these need manual review
        return geometry;
    }

    if (classification.type === 'polygon') {
        if (geometry.type === 'LineString') {
            let coords = geometry.coordinates;
            if (!isClosed(coords)) {
                coords = closeCoords(coords);
                stats.closureFixed++;
            }
            return {
                type: 'Polygon',
                coordinates: [coords]
            };
        }

        if (geometry.type === 'MultiLineString') {
            const polygons = [];
            for (const segment of geometry.coordinates) {
                if (segment.length >= 3) {
                    let coords = segment;
                    if (!isClosed(coords)) {
                        coords = closeCoords(coords);
                        stats.closureFixed++;
                    }
                    polygons.push([coords]);
                }
            }

            if (polygons.length === 1) {
                return { type: 'Polygon', coordinates: polygons[0] };
            }
            return { type: 'MultiPolygon', coordinates: polygons };
        }
    }

    return geometry;
}

/**
 * Generate a unique SUA ID from feature properties
 */
function generateSuaId(props, index) {
    const type = props.colorName || props.suaType || 'UNK';
    const name = (props.name || props.designator || `FEATURE_${index}`)
        .replace(/[^a-zA-Z0-9]/g, '_')
        .toUpperCase()
        .substring(0, 50);

    return `${type}_${name}`;
}

/**
 * Extract ARTCC from feature properties or name
 */
function extractArtcc(props) {
    if (props.artcc) return props.artcc;

    const artccPattern = /\b(ZAB|ZAU|ZBW|ZDC|ZDV|ZFW|ZHU|ZID|ZJX|ZKC|ZLA|ZLC|ZMA|ZME|ZMP|ZNY|ZOA|ZOB|ZSE|ZTL)\b/i;
    const name = props.name || props.designator || '';
    const match = name.match(artccPattern);

    return match ? match[1].toUpperCase() : null;
}

/**
 * Transform a single feature to the new schema
 */
function transformFeature(feature, index) {
    const props = feature.properties || {};
    const colorName = props.colorName || 'Unknown';

    // Classify geometry
    const classification = classifyGeometry(feature);

    // Update stats
    if (classification.type === 'polygon') {
        stats.polygons[classification.border]++;
    } else if (classification.type === 'line') {
        stats.lines[classification.border]++;
    } else if (classification.type === 'dash_segments') {
        stats.dashSegments++;
    }

    // Get group
    const group = TYPE_TO_GROUP[colorName] || 'OTHER';
    stats.byGroup[group] = (stats.byGroup[group] || 0) + 1;
    stats.byType[colorName] = (stats.byType[colorName] || 0) + 1;

    // Convert geometry if needed
    const newGeometry = convertGeometry(feature.geometry, classification);

    // Build new properties
    const newProps = {
        // Identification
        sua_id: generateSuaId(props, index),

        // Classification
        sua_group: group,
        sua_type: colorName,
        sua_subtype: props.subtype || null,

        // Display
        name: props.name || props.designator || null,
        area_name: props.areaName || null,
        area_reference: props.areaRef || null,

        // Altitudes
        floor_alt: props.lowerLimit || props.floorAlt || null,
        ceiling_alt: props.upperLimit || props.ceilingAlt || null,

        // Render
        geometry_type: classification.type,
        border_style: classification.border,
        color: props.color || GROUP_COLORS[group] || '#999999',

        // Location
        artcc: extractArtcc(props),

        // Legacy (keep for compatibility)
        colorName: colorName,
        designator: props.designator || null,
        schedule: props.schedule || null,
        scheduleDesc: props.scheduleDesc || null
    };

    return {
        type: 'Feature',
        properties: newProps,
        geometry: newGeometry
    };
}

/**
 * Main transformation function
 */
function transform() {
    console.log('Reading input file:', INPUT_FILE);

    if (!fs.existsSync(INPUT_FILE)) {
        console.error('Input file not found:', INPUT_FILE);
        process.exit(1);
    }

    const inputData = fs.readFileSync(INPUT_FILE, 'utf8');
    const geojson = JSON.parse(inputData);

    if (!geojson.features || !Array.isArray(geojson.features)) {
        console.error('Invalid GeoJSON: no features array');
        process.exit(1);
    }

    stats.total = geojson.features.length;
    console.log(`Processing ${stats.total} features...`);

    const transformedFeatures = [];

    for (let i = 0; i < geojson.features.length; i++) {
        try {
            const transformed = transformFeature(geojson.features[i], i);
            transformedFeatures.push(transformed);
        } catch (err) {
            stats.errors.push({
                index: i,
                error: err.message
            });
            // Keep original feature on error
            transformedFeatures.push(geojson.features[i]);
        }

        if ((i + 1) % 500 === 0) {
            console.log(`  Processed ${i + 1}/${stats.total} features...`);
        }
    }

    const output = {
        type: 'FeatureCollection',
        features: transformedFeatures
    };

    // Write output
    console.log('Writing output file:', OUTPUT_FILE);
    fs.writeFileSync(OUTPUT_FILE, JSON.stringify(output, null, 2));

    // Write report
    console.log('Writing report file:', REPORT_FILE);
    fs.writeFileSync(REPORT_FILE, JSON.stringify(stats, null, 2));

    // Print summary
    console.log('\n=== Transformation Complete ===');
    console.log(`Total features: ${stats.total}`);
    console.log(`Polygons (solid): ${stats.polygons.solid}`);
    console.log(`Polygons (dashed): ${stats.polygons.dashed}`);
    console.log(`Lines (solid): ${stats.lines.solid}`);
    console.log(`Lines (dashed): ${stats.lines.dashed}`);
    console.log(`Dash segments: ${stats.dashSegments}`);
    console.log(`Closures fixed: ${stats.closureFixed}`);
    console.log(`Errors: ${stats.errors.length}`);
    console.log('\nBy Group:');
    for (const [group, count] of Object.entries(stats.byGroup).sort((a, b) => b[1] - a[1])) {
        console.log(`  ${group}: ${count}`);
    }
}

// Run transformation
transform();
