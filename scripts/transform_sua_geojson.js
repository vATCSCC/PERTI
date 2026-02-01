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
    'Unknown': 'OTHER',
};

// Types that are inherently lines (not areas)
// Note: OSARA removed - they should render as polygons
// AW (AWACS orbits) are oval/racetrack patterns - should be lines NOT polygons
// ALTRV are altitude reservation routes
const LINE_TYPES = ['AR', 'IR', 'VR', 'SR', 'MTR', 'ANG', 'WSRP', 'AW', 'AWACS', 'ALTRV'];

// Types that may have dashed borders
const DASHED_BORDER_TYPES = ['120', 'SS', 'ADIZ'];

// Colors by type (user specified)
const TYPE_COLORS = {
    // Regulatory - user specified
    'PROHIBITED': '#FF00FF',  // Magenta
    'P': '#FF00FF',           // Magenta
    'RESTRICTED': '#FF0000',  // Red
    'R': '#FF0000',           // Red
    'WARNING': '#8B00FF',     // Violet
    'W': '#8B00FF',           // Violet
    'ALERT': '#FFFF00',       // Yellow
    'A': '#FFFF00',           // Yellow
    'NSA': '#00FF00',         // Green

    // Special - user specified
    'TFR': '#00FFFF',         // Cyan

    // Military
    'MOA': '#0000FF',         // Blue
    'ATCAA': '#4169E1',       // Royal Blue
    'ALTRV': '#FFD700',       // Gold
    'USN': '#000080',         // Navy
    'USAF': '#1E90FF',        // Dodger Blue
    'USArmy': '#556B2F',      // Dark Olive Green
    'ANG': '#2E8B57',         // Sea Green
    'NORAD': '#8B0000',       // Dark Red
    'OPAREA': '#8B4513',      // Saddle Brown

    // Routes
    'AR': '#4682B4',          // Steel Blue
    'IR': '#CD853F',          // Peru
    'VR': '#CD853F',          // Peru
    'SR': '#BDB76B',          // Dark Khaki
    'MTR': '#9ACD32',         // Yellow Green
    'OSARA': '#20B2AA',       // Light Sea Green

    // Special
    'DZ': '#FF6347',          // Tomato
    'SS': '#9932CC',          // Dark Orchid
    'LASER': '#FF4500',       // Orange Red
    'NUCLEAR': '#DC143C',     // Crimson

    // DC Area
    'SFRA': '#FF8C00',        // Dark Orange
    'FRZ': '#FF4500',         // Orange Red
    'ADIZ': '#FF8800',        // Orange
    '120': '#FFA500',         // Orange
    '180': '#FF8C00',         // Dark Orange

    // AWACS
    'AW': '#00CED1',          // Dark Turquoise
    'AWACS': '#00CED1',       // Dark Turquoise

    // Other
    'NOAA': '#4169E1',        // Royal Blue
    'NASA': '#FF6347',        // Tomato
    'WSRP': '#9370DB',        // Medium Purple
    'MODEC': '#808080',       // Gray
    'Unknown': '#999999',      // Gray
};

// Default colors by group (fallback)
const GROUP_COLORS = {
    'REGULATORY': '#FF0000',
    'MILITARY': '#0000FF',
    'ROUTES': '#4682B4',
    'SPECIAL': '#00FFFF',
    'DC_AREA': '#FF8800',
    'SURFACE_OPS': '#888888',
    'AWACS': '#00CED1',
    'OTHER': '#999999',
};

// Statistics
const stats = {
    total: 0,
    polygons: { solid: 0, dashed: 0 },
    lines: { solid: 0, dashed: 0 },
    dashSegments: 0,
    closureFixed: 0,
    closedFeatures: [],  // Track which features had closure fixed
    byGroup: {},
    byType: {},
    errors: [],
};

/**
 * Check if coordinates form a closed ring (exact match)
 */
function isClosed(coords) {
    if (!coords || coords.length < 3) {return false;}
    const first = coords[0];
    const last = coords[coords.length - 1];
    return first[0] === last[0] && first[1] === last[1];
}

/**
 * Check if coordinates are approximately closed (within tolerance)
 * Tolerance of 0.001 degrees = ~111 meters at equator
 */
function isApproxClosed(coords, tolerance = 0.001) {
    if (!coords || coords.length < 3) {return false;}
    const first = coords[0];
    const last = coords[coords.length - 1];
    return Math.abs(first[0] - last[0]) < tolerance &&
           Math.abs(first[1] - last[1]) < tolerance;
}

/**
 * Calculate if a set of coordinates forms a closed shape (not just a line)
 * Uses bounding box ratio and point distribution to determine
 */
function looksLikeClosed(coords) {
    if (!coords || coords.length < 4) {return false;}

    // Find bounding box
    let minLon = Infinity, maxLon = -Infinity;
    let minLat = Infinity, maxLat = -Infinity;

    for (const [lon, lat] of coords) {
        minLon = Math.min(minLon, lon);
        maxLon = Math.max(maxLon, lon);
        minLat = Math.min(minLat, lat);
        maxLat = Math.max(maxLat, lat);
    }

    const width = maxLon - minLon;
    const height = maxLat - minLat;

    // If bounding box is too narrow (aspect ratio > 10:1), likely a line
    if (width === 0 || height === 0) {return false;}
    const ratio = Math.max(width, height) / Math.min(width, height);
    if (ratio > 15) {return false;}

    // Check if first and last points are close enough to be auto-closed
    const first = coords[0];
    const last = coords[coords.length - 1];
    const distance = Math.sqrt(
        Math.pow(first[0] - last[0], 2) +
        Math.pow(first[1] - last[1], 2),
    );

    // If distance between first and last is less than 5% of perimeter, consider it closeable
    const avgDim = (width + height) / 2;
    return distance < avgDim * 0.15;
}

/**
 * Close coordinates if they should form a ring
 */
function closeCoords(coords) {
    if (!coords || coords.length < 3) {return coords;}
    if (!isClosed(coords)) {
        return [...coords, coords[0]];
    }
    return coords;
}

/**
 * Detect if a MultiLineString represents dash segments
 * (many short 2-3 point segments = dashed line rendering)
 */
function isDashSegments(geometry) {
    if (geometry.type !== 'MultiLineString') {return false;}

    const coords = geometry.coordinates;
    if (coords.length < 5) {return false;}

    // Check if most segments are very short (2-3 points)
    let shortSegments = 0;
    for (const segment of coords) {
        if (segment.length <= 3) {shortSegments++;}
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

    // Check if type is inherently a line (routes, orbits, etc.)
    const isLineType = LINE_TYPES.includes(colorName);

    // Check for dash segments (MultiLineString with many short segments)
    if (isDashSegments(geom)) {
        return { type: 'dash_segments', border: 'dashed' };
    }

    // For LineString
    if (geom.type === 'LineString') {
        const coords = geom.coordinates;

        // Line types always stay as lines
        if (isLineType) {
            return { type: 'line', border: 'solid' };
        }

        // Check if it's closed or looks like it should be closed
        const closed = isClosed(coords) || isApproxClosed(coords);
        const shouldClose = looksLikeClosed(coords);

        if (closed || shouldClose || coords.length > 10) {
            // Likely a polygon
            return { type: 'polygon', border: 'solid' };
        }

        return { type: 'line', border: 'solid' };
    }

    // For MultiLineString
    if (geom.type === 'MultiLineString') {
        // Line types always stay as lines
        if (isLineType) {
            return { type: 'line', border: 'solid' };
        }

        // Check if segments form closed areas
        let closedSegments = 0;
        for (const segment of geom.coordinates) {
            if (isClosed(segment) || isApproxClosed(segment) || looksLikeClosed(segment) || segment.length > 10) {
                closedSegments++;
            }
        }

        if (closedSegments > 0) {
            // Check for dashed border types
            const border = DASHED_BORDER_TYPES.includes(colorName) ? 'dashed' : 'solid';
            return { type: 'polygon', border };
        }

        return { type: 'line', border: 'solid' };
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
 * @param {Object} geometry - The geometry object
 * @param {Object} classification - The classification result
 * @param {string} featureName - Name of the feature for tracking
 * @param {string} featureType - Type of the feature for tracking
 */
function convertGeometry(geometry, classification, featureName, featureType) {
    if (classification.type === 'dash_segments') {
        // Keep as-is for now, these need manual review
        return geometry;
    }

    let closedThisFeature = false;

    if (classification.type === 'polygon') {
        if (geometry.type === 'LineString') {
            let coords = geometry.coordinates;
            if (!isClosed(coords)) {
                coords = closeCoords(coords);
                stats.closureFixed++;
                closedThisFeature = true;
            }
            if (closedThisFeature) {
                stats.closedFeatures.push({
                    name: featureName || 'Unknown',
                    type: featureType || 'Unknown',
                    originalGeom: 'LineString',
                    points: geometry.coordinates.length,
                });
            }
            return {
                type: 'Polygon',
                coordinates: [coords],
            };
        }

        if (geometry.type === 'MultiLineString') {
            const polygons = [];
            let segmentsClosed = 0;
            for (const segment of geometry.coordinates) {
                if (segment.length >= 3) {
                    let coords = segment;
                    if (!isClosed(coords)) {
                        coords = closeCoords(coords);
                        stats.closureFixed++;
                        segmentsClosed++;
                    }
                    polygons.push([coords]);
                }
            }

            if (segmentsClosed > 0) {
                stats.closedFeatures.push({
                    name: featureName || 'Unknown',
                    type: featureType || 'Unknown',
                    originalGeom: 'MultiLineString',
                    segments: geometry.coordinates.length,
                    segmentsClosed: segmentsClosed,
                });
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
    if (props.artcc) {return props.artcc;}

    const artccPattern = /\b(ZAB|ZAU|ZBW|ZDC|ZDV|ZFW|ZHU|ZID|ZJX|ZKC|ZLA|ZLC|ZMA|ZME|ZMP|ZNY|ZOA|ZOB|ZSE|ZTL)\b/i;
    const name = props.name || props.designator || '';
    const match = name.match(artccPattern);

    return match ? match[1].toUpperCase() : null;
}

/**
 * Check if a feature should be skipped (garbage data)
 */
function shouldSkipFeature(feature) {
    const props = feature.properties || {};
    const geom = feature.geometry;
    const name = props.name || '';
    const colorName = props.colorName || '';

    // Skip AWACS/AW features that are just small reference boxes
    // These have names like "//" or ";" and only 5-7 coordinates
    if (colorName === 'AW') {
        if (name === '//' || name === ';' || name === '//;' || name === '') {
            if (geom.type === 'Polygon' && geom.coordinates[0] && geom.coordinates[0].length < 10) {
                return true;
            }
            if (geom.type === 'LineString' && geom.coordinates && geom.coordinates.length < 10) {
                return true;
            }
        }
    }

    // Skip features with no valid geometry
    if (!geom || !geom.coordinates) {
        return true;
    }

    return false;
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

    // Get feature name for tracking
    const featureName = props.name || props.designator || `Feature_${index}`;

    // Convert geometry if needed (pass name and type for closure tracking)
    const newGeometry = convertGeometry(feature.geometry, classification, featureName, colorName);

    // Get color: prefer type-specific color, then group color, then default
    const featureColor = TYPE_COLORS[colorName] || GROUP_COLORS[group] || '#999999';

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
        color: featureColor,

        // Location
        artcc: extractArtcc(props),

        // Legacy (keep for compatibility)
        colorName: colorName,
        designator: props.designator || null,
        schedule: props.schedule || null,
        scheduleDesc: props.scheduleDesc || null,
    };

    return {
        type: 'Feature',
        properties: newProps,
        geometry: newGeometry,
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

    let skipped = 0;
    for (let i = 0; i < geojson.features.length; i++) {
        try {
            // Skip garbage features (AWACS boxes, empty geometries)
            if (shouldSkipFeature(geojson.features[i])) {
                skipped++;
                continue;
            }
            const transformed = transformFeature(geojson.features[i], i);
            transformedFeatures.push(transformed);
        } catch (err) {
            stats.errors.push({
                index: i,
                error: err.message,
            });
            // Keep original feature on error
            transformedFeatures.push(geojson.features[i]);
        }

        if ((i + 1) % 500 === 0) {
            console.log(`  Processed ${i + 1}/${stats.total} features...`);
        }
    }
    stats.skipped = skipped;

    const output = {
        type: 'FeatureCollection',
        features: transformedFeatures,
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
    console.log(`Skipped (garbage): ${stats.skipped}`);
    console.log(`Output features: ${transformedFeatures.length}`);
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

    // Print closed features (first 20)
    if (stats.closedFeatures.length > 0) {
        console.log(`\n=== Closed Features (${stats.closedFeatures.length} total) ===`);
        const toShow = stats.closedFeatures.slice(0, 20);
        toShow.forEach(f => {
            if (f.originalGeom === 'LineString') {
                console.log(`  [${f.type}] ${f.name} (${f.points} points)`);
            } else {
                console.log(`  [${f.type}] ${f.name} (${f.segmentsClosed}/${f.segments} segments closed)`);
            }
        });
        if (stats.closedFeatures.length > 20) {
            console.log(`  ... and ${stats.closedFeatures.length - 20} more`);
        }
    }
}

// Run transformation
transform();
