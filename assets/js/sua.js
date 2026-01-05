/**
 * SUA/TFR Management Page JavaScript
 * Uses MapLibre GL JS for map rendering
 */

// Type display names
const TYPE_NAMES = {
    'P': 'Prohibited Area',
    'R': 'Restricted Area',
    'W': 'Warning Area',
    'A': 'Alert Area',
    'MOA': 'Military Operations Area',
    'NSA': 'National Security Area',
    'ATCAA': 'ATC Assigned Airspace',
    'IR': 'IR Route',
    'VR': 'VR Route',
    'SR': 'SR Route',
    'AR': 'Aerial Refueling',
    'TFR': 'Temporary Flight Restriction',
    'ALTRV': 'Altitude Reservation',
    'OPAREA': 'Operating Area',
    'AW': 'AWACS Orbit',
    'USN': 'US Navy',
    'DZ': 'Drop Zone',
    'ADIZ': 'Air Defense Identification Zone',
    'OSARA': 'Offshore Airspace Restricted Area',
    'WSRP': 'Weather Surveillance Radar Program',
    'SS': 'Supersonic',
    'USArmy': 'US Army',
    'LASER': 'Laser',
    'USAF': 'US Air Force',
    'ANG': 'Air National Guard',
    'NUCLEAR': 'Nuclear',
    'NORAD': 'NORAD',
    'NOAA': 'NOAA',
    'NASA': 'NASA',
    'MODEC': 'Mode C Veil',
    'FRZ': 'Flight Restricted Zone',
    'SFRA': 'Special Flight Rules Area',
    'PROHIBITED': 'Prohibited Area',
    'RESTRICTED': 'Restricted Area',
    'WARNING': 'Warning Area',
    'ALERT': 'Alert Area',
    'OTHER': 'Other',
    'Unknown': 'Other',
    '120': 'DC Speed Restriction',
    '180': 'DC Special Flight Rules Area',
};

// Group display names (for new schema)
const GROUP_NAMES = {
    'REGULATORY': 'Regulatory',
    'MILITARY': 'Military',
    'ROUTES': 'Routes',
    'SPECIAL': 'Special',
    'DC_AREA': 'DC NCR',
    'SURFACE_OPS': 'Surface Ops',
    'AWACS': 'AWACS',
    'OTHER': 'Other'
};

// Types that should remain as lines (routes, tracks) - NOT converted to polygons
// Note: OSARA removed - they should render as polygons
const LINE_TYPES = [
    'AR',      // Air Refueling tracks
    'ALTRV',   // Altitude Reservations
    'IR',      // IFR Routes
    'VR',      // VFR Routes
    'SR',      // Slow Routes
    'SS'       // Supersonic corridors
];

// Map variables
var suaMap = null;
var currentView = 'map';
var popup = null;
var mapLoaded = false;
var layersInitialized = false;
var allFeatures = { areas: [], routes: [] }; // Store all loaded features for filtering

// Drawing variables
var draw = null;
var isDrawingMode = false;
var drawnGeometry = null;

// Layer type mapping - maps colorName to layer group (legacy support)
const LAYER_GROUPS = {
    'PROHIBITED': 'REGULATORY',
    'RESTRICTED': 'REGULATORY',
    'WARNING': 'REGULATORY',
    'ALERT': 'REGULATORY',
    'P': 'REGULATORY',
    'R': 'REGULATORY',
    'W': 'REGULATORY',
    'A': 'REGULATORY',
    'NSA': 'REGULATORY',
    'MOA': 'MILITARY',
    'ATCAA': 'MILITARY',
    'ALTRV': 'MILITARY',
    'USAF': 'MILITARY',
    'USArmy': 'MILITARY',
    'ANG': 'MILITARY',
    'USN': 'MILITARY',
    'NORAD': 'MILITARY',
    'OPAREA': 'MILITARY',
    'AR': 'ROUTES',
    'IR': 'ROUTES',
    'VR': 'ROUTES',
    'SR': 'ROUTES',
    'MTR': 'ROUTES',
    'OSARA': 'ROUTES',
    'TFR': 'SPECIAL',
    'DZ': 'SPECIAL',
    'SS': 'SPECIAL',
    'LASER': 'SPECIAL',
    'NUCLEAR': 'SPECIAL',
    'SFRA': 'DC_AREA',
    'FRZ': 'DC_AREA',
    'ADIZ': 'DC_AREA',
    '120': 'DC_AREA',
    '180': 'DC_AREA',
    'AW': 'AWACS',
    'NOAA': 'OTHER',
    'NASA': 'OTHER',
    'MODEC': 'OTHER',
    'WSRP': 'OTHER',
    'SUA': 'OTHER',
    'Unknown': 'OTHER'
};

// Get the layer group for a feature
// Supports both new schema (sua_group) and legacy (colorName lookup)
function getLayerGroup(feature) {
    var props = feature.properties || feature;
    // New schema: use sua_group directly
    if (props.sua_group) {
        return props.sua_group;
    }
    // Legacy: map colorName to group
    var colorName = props.colorName || props.sua_type || 'Unknown';
    return LAYER_GROUPS[colorName] || 'OTHER';
}

// Types that have dedicated layer checkboxes
const LAYER_CHECKBOX_TYPES = [
    'PROHIBITED', 'RESTRICTED', 'WARNING', 'ALERT', 'NSA',
    'MOA', 'ATCAA', 'ALTRV', 'USN', 'OPAREA', 'AW',
    'AR', 'TFR', 'DZ', 'SS'
];

// Types that map to DC_AREA checkbox
const DC_AREA_TYPES = ['ADIZ', 'FRZ', 'SFRA', '120', '180'];

// Get the filter key for a feature (matches checkbox values)
function getFeatureFilterType(feature) {
    var props = feature.properties || feature;
    var suaType = props.sua_type || props.colorName || props.suaType || '';

    // Direct match to checkbox types
    if (LAYER_CHECKBOX_TYPES.indexOf(suaType) !== -1) {
        return suaType;
    }

    // Map short codes to full type names
    var typeMap = {
        'P': 'PROHIBITED',
        'R': 'RESTRICTED',
        'W': 'WARNING',
        'A': 'ALERT'
    };
    if (typeMap[suaType]) {
        return typeMap[suaType];
    }

    // DC Area types map to DC_AREA checkbox
    if (DC_AREA_TYPES.indexOf(suaType) !== -1) {
        return 'DC_AREA';
    }

    // Check group for DC_AREA
    var group = props.sua_group || LAYER_GROUPS[suaType];
    if (group === 'DC_AREA') {
        return 'DC_AREA';
    }

    // Everything else is OTHER
    return 'OTHER';
}

// Get currently enabled layer types from checkboxes
function getEnabledLayers() {
    var enabled = [];
    $('.layer-toggle:checked').each(function() {
        enabled.push($(this).val());
    });
    return enabled;
}

// Toggle all layers on or off
function toggleAllLayers(state) {
    $('.layer-toggle').prop('checked', state);
    applyLayerFilters();
}

// Apply layer filters to the map
function applyLayerFilters() {
    if (!suaMap || !mapLoaded || !layersInitialized) return;

    var enabledLayers = getEnabledLayers();

    // Filter area features based on individual type checkboxes
    var filteredAreas = allFeatures.areas.filter(function(f) {
        var filterType = getFeatureFilterType(f);
        return enabledLayers.indexOf(filterType) !== -1;
    });

    // Filter route features based on individual type checkboxes
    var filteredRoutes = allFeatures.routes.filter(function(f) {
        var filterType = getFeatureFilterType(f);
        return enabledLayers.indexOf(filterType) !== -1;
    });

    // Update sources
    var areaSource = suaMap.getSource('sua-areas');
    var routeSource = suaMap.getSource('sua-routes');

    if (areaSource) {
        areaSource.setData({
            type: 'FeatureCollection',
            features: filteredAreas
        });
    }

    if (routeSource) {
        routeSource.setData({
            type: 'FeatureCollection',
            features: filteredRoutes
        });
    }

    // Update visible count
    $('#sua_count').text(filteredAreas.length + filteredRoutes.length);
}

// Tooltip helper
function tooltips() {
    $('[data-toggle="tooltip"]').tooltip('dispose');
    $(function () {
        $('[data-toggle="tooltip"]').tooltip();
    });
}

// Load activations table
function loadActivations() {
    var status = $('#statusFilter').val();
    var url = 'api/data/sua/activations.l.php';
    if (status) {
        url += '?status=' + status;
    }

    $.get(url).done(function(data) {
        $('#activations_table').html(data);
        tooltips();
    }).fail(function() {
        $('#activations_table').html('<tr><td colspan="8" class="text-center text-danger">Failed to load activations</td></tr>');
    });
}

// Load SUA browser table
function loadSuaBrowser() {
    var search = $('#suaSearch').val();
    var type = $('#suaTypeFilter').val();
    var artcc = $('#suaArtccFilter').val();

    var params = [];
    if (search) params.push('search=' + encodeURIComponent(search));
    if (type) params.push('type=' + type);
    if (artcc) params.push('artcc=' + artcc);

    var url = 'api/data/sua/sua_list.php';
    if (params.length > 0) {
        url += '?' + params.join('&');
    }

    $.getJSON(url).done(function(response) {
        if (response.status === 'ok') {
            var html = '';
            var data = response.data;
            $('#sua_count').text(response.count);

            if (data.length === 0) {
                html = '<tr><td colspan="7" class="text-center text-muted">No SUAs found</td></tr>';
            } else {
                data.forEach(function(sua) {
                    // Support both new and legacy schema
                    var suaType = sua.sua_type || sua.suaType || sua.colorName || 'Unknown';
                    var typeName = TYPE_NAMES[suaType] || suaType;
                    var floorAlt = sua.floor_alt || sua.lowerLimit || '-';
                    var ceilingAlt = sua.ceiling_alt || sua.upperLimit || '-';
                    var altDisplay = floorAlt + ' - ' + ceilingAlt;
                    var suaId = sua.sua_id || sua.designator || '-';

                    html += '<tr>';
                    html += '<td><span class="badge badge-primary">' + typeName + '</span></td>';
                    html += '<td class="text-monospace">' + suaId + '</td>';
                    html += '<td>' + (sua.name || '-') + '</td>';
                    html += '<td>' + (sua.artcc || '-') + '</td>';
                    html += '<td class="small">' + altDisplay + '</td>';
                    html += '<td class="small">' + (sua.scheduleDesc || sua.schedule || '-') + '</td>';
                    html += '<td>';
                    html += '<button class="btn btn-sm btn-success activation-btn" onclick="openScheduleModal(\'' +
                            escapeHtml(suaId) + '\', \'' +
                            escapeHtml(suaType) + '\', \'' +
                            escapeHtml(sua.name || '') + '\', \'' +
                            escapeHtml(sua.artcc || '') + '\', \'' +
                            escapeHtml(floorAlt) + '\', \'' +
                            escapeHtml(ceilingAlt) + '\')">';
                    html += '<i class="fas fa-plus"></i> Activate</button>';
                    html += '</td>';
                    html += '</tr>';
                });
            }

            $('#sua_browser_table').html(html);
        } else {
            $('#sua_browser_table').html('<tr><td colspan="7" class="text-center text-danger">Error loading SUAs</td></tr>');
        }
    }).fail(function() {
        $('#sua_browser_table').html('<tr><td colspan="7" class="text-center text-danger">Failed to load SUAs</td></tr>');
    });

    // Also update the map if it's initialized and loaded
    if (suaMap && mapLoaded) {
        loadSuaMapData();
    }
}

// Escape HTML for safe insertion
function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    var str = String(text);
    return str.replace(/[&<>"']/g, function(m) {
        return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[m];
    });
}

// Open schedule modal with SUA data
function openScheduleModal(designator, suaType, name, artcc, lowerAlt, upperAlt) {
    $('#schedule_sua_id').val(designator);
    $('#schedule_sua_type').val(suaType);
    $('#schedule_name').val(name);
    $('#schedule_artcc').val(artcc);
    $('#schedule_lower_alt').val(lowerAlt);
    $('#schedule_upper_alt').val(upperAlt);

    var now = new Date();
    var end = new Date(now.getTime() + 2 * 60 * 60 * 1000);

    $('#schedule_start').val(formatDateTimeLocal(now));
    $('#schedule_end').val(formatDateTimeLocal(end));

    $('#scheduleModal').modal('show');
}

// Format date for datetime-local input
function formatDateTimeLocal(date) {
    var year = date.getUTCFullYear();
    var month = String(date.getUTCMonth() + 1).padStart(2, '0');
    var day = String(date.getUTCDate()).padStart(2, '0');
    var hours = String(date.getUTCHours()).padStart(2, '0');
    var minutes = String(date.getUTCMinutes()).padStart(2, '0');
    return year + '-' + month + '-' + day + 'T' + hours + ':' + minutes;
}

// Cancel activation
function cancelActivation(id, name) {
    Swal.fire({
        title: 'Cancel Activation?',
        text: 'Are you sure you want to cancel "' + name + '"?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, cancel it'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                type: 'POST',
                url: 'api/mgt/sua/delete.php',
                data: { id: id },
                success: function(data) {
                    Swal.fire({
                        toast: true,
                        position: 'bottom-right',
                        icon: 'success',
                        title: 'Activation cancelled',
                        timer: 3000,
                        showConfirmButton: false
                    });
                    loadActivations();
                },
                error: function() {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Failed to cancel activation'
                    });
                }
            });
        }
    });
}

// Generate circular TFR geometry
function generateCircularGeometry(lat, lon, radiusNM) {
    var radiusMeters = radiusNM * 1852;
    var points = 36;
    var coordinates = [];

    for (var i = 0; i <= points; i++) {
        var angle = (i / points) * 2 * Math.PI;
        var dLat = (radiusMeters / 111320) * Math.cos(angle);
        var dLon = (radiusMeters / (111320 * Math.cos(lat * Math.PI / 180))) * Math.sin(angle);
        coordinates.push([lon + dLon, lat + dLat]);
    }

    return {
        type: 'Polygon',
        coordinates: [coordinates]
    };
}

// Check if a type should be rendered as a line (route) vs area (polygon)
// Supports both new schema (geometry_type) and legacy (type detection)
function isLineType(feature) {
    var props = feature.properties || feature;

    // New schema: use geometry_type directly
    if (props.geometry_type) {
        return props.geometry_type === 'line' || props.geometry_type === 'dash_segments';
    }

    // Legacy: check colorName/suaType against LINE_TYPES
    var typeToCheck = props.colorName || props.sua_type || props.suaType || '';
    return LINE_TYPES.indexOf(typeToCheck) !== -1;
}

// Process feature for map display
// New schema: geometry already converted by transformation script
// Legacy: convert LineString to Polygon for area types
function processFeature(feature) {
    if (!feature.geometry) return feature;

    var props = feature.properties || {};

    // New schema: geometry already processed, just set _isRoute flag
    if (props.geometry_type) {
        props._isRoute = (props.geometry_type === 'line' || props.geometry_type === 'dash_segments');
        props._hasDashedBorder = (props.border_style === 'dashed');
        return {
            type: 'Feature',
            properties: props,
            geometry: feature.geometry
        };
    }

    // Legacy processing: convert LineString to Polygon for area types
    var colorName = props.colorName || '';
    var suaType = props.suaType || '';

    // If this is a line type (route), keep it as a line
    if (LINE_TYPES.indexOf(colorName) !== -1 || LINE_TYPES.indexOf(suaType) !== -1) {
        props._isRoute = true;
        return {
            type: 'Feature',
            properties: props,
            geometry: feature.geometry
        };
    }

    // For area types, convert LineString to Polygon
    if (feature.geometry.type === 'LineString') {
        var coords = feature.geometry.coordinates;
        if (coords && coords.length >= 3) {
            var first = coords[0];
            var last = coords[coords.length - 1];
            var isClosed = (first[0] === last[0] && first[1] === last[1]) ||
                          (Math.abs(first[0] - last[0]) < 0.0001 && Math.abs(first[1] - last[1]) < 0.0001);

            var polygonCoords = coords.slice();
            if (!isClosed) {
                polygonCoords.push(coords[0]);
            }

            props._isRoute = false;
            return {
                type: 'Feature',
                properties: props,
                geometry: {
                    type: 'Polygon',
                    coordinates: [polygonCoords]
                }
            };
        }
    }

    // For MultiLineString (like MOAs), convert to MultiPolygon
    if (feature.geometry.type === 'MultiLineString') {
        var polygons = [];
        feature.geometry.coordinates.forEach(function(lineCoords) {
            if (lineCoords && lineCoords.length >= 3) {
                var first = lineCoords[0];
                var last = lineCoords[lineCoords.length - 1];
                var isClosed = (first[0] === last[0] && first[1] === last[1]) ||
                              (Math.abs(first[0] - last[0]) < 0.0001 && Math.abs(first[1] - last[1]) < 0.0001);

                var polygonCoords = lineCoords.slice();
                if (!isClosed) {
                    polygonCoords.push(lineCoords[0]);
                }
                polygons.push([polygonCoords]);
            }
        });

        if (polygons.length > 0) {
            props._isRoute = false;
            return {
                type: 'Feature',
                properties: props,
                geometry: {
                    type: 'MultiPolygon',
                    coordinates: polygons
                }
            };
        }
    }

    props._isRoute = false;
    return {
        type: 'Feature',
        properties: props,
        geometry: feature.geometry
    };
}

// Get color for a feature - prefer the color from the GeoJSON data
function getFeatureColor(props) {
    if (props && props.color) {
        return props.color;
    }
    return '#999999';
}

// Boundary layer visibility state
var boundaryLayersVisible = {
    artcc: true,
    superhigh: false,
    high: false,
    low: false,
    tracon: false
};

// Load boundary layers (ARTCC, superhigh, high, low, TRACON)
function loadBoundaryLayers() {
    var boundaryConfigs = [
        { id: 'artcc', url: 'assets/geojson/artcc.json', color: '#515151', weight: 1.5, visible: boundaryLayersVisible.artcc },
        { id: 'superhigh', url: 'assets/geojson/superhigh.json', color: '#303030', weight: 1.5, visible: boundaryLayersVisible.superhigh },
        { id: 'high', url: 'assets/geojson/high.json', color: '#303030', weight: 1.5, visible: boundaryLayersVisible.high },
        { id: 'low', url: 'assets/geojson/low.json', color: '#303030', weight: 1.5, visible: boundaryLayersVisible.low },
        { id: 'tracon', url: 'assets/geojson/tracon.json', color: '#505050', weight: 1.0, visible: boundaryLayersVisible.tracon }
    ];

    boundaryConfigs.forEach(function(config) {
        $.getJSON(config.url).done(function(data) {
            // Add source
            suaMap.addSource('boundary-' + config.id, {
                type: 'geojson',
                data: data
            });

            // Add line layer (insert below SUA layers)
            suaMap.addLayer({
                id: 'boundary-' + config.id + '-line',
                type: 'line',
                source: 'boundary-' + config.id,
                layout: {
                    'visibility': config.visible ? 'visible' : 'none'
                },
                paint: {
                    'line-color': config.color,
                    'line-width': config.weight,
                    'line-opacity': 1
                }
            }, 'sua-area-fill'); // Insert below SUA fill layer

            console.log('Loaded boundary layer:', config.id, '- features:', data.features ? data.features.length : 0);
        }).fail(function() {
            console.warn('Failed to load boundary layer:', config.id);
        });
    });
}

// Toggle boundary layer visibility
function toggleBoundaryLayer(layerId, visible) {
    if (!suaMap || !mapLoaded) return;

    boundaryLayersVisible[layerId] = visible;
    var mapLayerId = 'boundary-' + layerId + '-line';

    if (suaMap.getLayer(mapLayerId)) {
        suaMap.setLayoutProperty(mapLayerId, 'visibility', visible ? 'visible' : 'none');
    }
}

// Initialize the SUA map with MapLibre GL
function initSuaMap() {
    if (suaMap) return;

    suaMap = new maplibregl.Map({
        container: 'sua-map',
        style: {
            version: 8,
            sources: {
                'osm': {
                    type: 'raster',
                    tiles: [
                        'https://tile.openstreetmap.org/{z}/{x}/{y}.png'
                    ],
                    tileSize: 256,
                    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                }
            },
            layers: [
                {
                    id: 'osm-layer',
                    type: 'raster',
                    source: 'osm',
                    minzoom: 0,
                    maxzoom: 18
                }
            ]
        },
        center: [-98.35, 39.5],
        zoom: 4
    });

    suaMap.addControl(new maplibregl.NavigationControl(), 'top-right');

    popup = new maplibregl.Popup({
        closeButton: true,
        closeOnClick: false,
        maxWidth: '300px'
    });

    suaMap.on('load', function() {
        console.log('Map loaded');
        mapLoaded = true;

        // Add empty source for area features (polygons)
        suaMap.addSource('sua-areas', {
            type: 'geojson',
            data: { type: 'FeatureCollection', features: [] }
        });

        // Add empty source for route features (lines)
        suaMap.addSource('sua-routes', {
            type: 'geojson',
            data: { type: 'FeatureCollection', features: [] }
        });

        // Area fill layer
        suaMap.addLayer({
            id: 'sua-area-fill',
            type: 'fill',
            source: 'sua-areas',
            paint: {
                'fill-color': ['coalesce', ['get', '_color'], '#999999'],
                'fill-opacity': 0.3
            }
        });

        // Area outline layer
        suaMap.addLayer({
            id: 'sua-area-outline',
            type: 'line',
            source: 'sua-areas',
            paint: {
                'line-color': ['coalesce', ['get', '_color'], '#999999'],
                'line-width': 1.5,
                'line-opacity': 0.8
            }
        });

        // Route line layer (thicker, more visible)
        suaMap.addLayer({
            id: 'sua-route-line',
            type: 'line',
            source: 'sua-routes',
            paint: {
                'line-color': ['coalesce', ['get', '_color'], '#999999'],
                'line-width': 3,
                'line-opacity': 0.9
            }
        });

        // Route line casing for visibility
        suaMap.addLayer({
            id: 'sua-route-casing',
            type: 'line',
            source: 'sua-routes',
            paint: {
                'line-color': '#ffffff',
                'line-width': 5,
                'line-opacity': 0.4,
                'line-gap-width': 0
            }
        }, 'sua-route-line'); // Insert below route line

        // Load boundary layers (ARTCC, high, low, etc.)
        loadBoundaryLayers();

        // Click handlers
        suaMap.on('click', 'sua-area-fill', function(e) {
            if (e.features && e.features.length > 0) {
                showFeaturePopup(e.features[0], e.lngLat);
            }
        });

        suaMap.on('click', 'sua-route-line', function(e) {
            if (e.features && e.features.length > 0) {
                showFeaturePopup(e.features[0], e.lngLat);
            }
        });

        // Cursor changes
        suaMap.on('mouseenter', 'sua-area-fill', function() {
            suaMap.getCanvas().style.cursor = 'pointer';
        });
        suaMap.on('mouseleave', 'sua-area-fill', function() {
            suaMap.getCanvas().style.cursor = '';
        });
        suaMap.on('mouseenter', 'sua-route-line', function() {
            suaMap.getCanvas().style.cursor = 'pointer';
        });
        suaMap.on('mouseleave', 'sua-route-line', function() {
            suaMap.getCanvas().style.cursor = '';
        });

        layersInitialized = true;

        // Initialize MapboxDraw for ALTRV drawing
        draw = new MapboxDraw({
            displayControlsDefault: false,
            controls: {
                line_string: true,
                polygon: true,
                trash: true
            },
            defaultMode: 'simple_select',
            styles: [
                // Active line (while drawing)
                {
                    id: 'gl-draw-line-active',
                    type: 'line',
                    filter: ['all', ['==', '$type', 'LineString'], ['==', 'active', 'true']],
                    paint: {
                        'line-color': '#E1E101',
                        'line-dasharray': [0.2, 2],
                        'line-width': 3
                    }
                },
                // Active vertex points
                {
                    id: 'gl-draw-point-active',
                    type: 'circle',
                    filter: ['all', ['==', '$type', 'Point'], ['==', 'meta', 'vertex']],
                    paint: {
                        'circle-radius': 6,
                        'circle-color': '#E1E101',
                        'circle-stroke-color': '#fff',
                        'circle-stroke-width': 2
                    }
                },
                // Inactive completed line
                {
                    id: 'gl-draw-line-inactive',
                    type: 'line',
                    filter: ['all', ['==', '$type', 'LineString'], ['!=', 'active', 'true']],
                    paint: {
                        'line-color': '#E1E101',
                        'line-width': 3
                    }
                },
                // Polygon fill
                {
                    id: 'gl-draw-polygon-fill',
                    type: 'fill',
                    filter: ['all', ['==', '$type', 'Polygon']],
                    paint: {
                        'fill-color': '#E1E101',
                        'fill-opacity': 0.3
                    }
                },
                // Polygon outline
                {
                    id: 'gl-draw-polygon-stroke',
                    type: 'line',
                    filter: ['all', ['==', '$type', 'Polygon']],
                    paint: {
                        'line-color': '#E1E101',
                        'line-width': 2
                    }
                }
            ]
        });

        suaMap.addControl(draw, 'top-left');

        // Draw event handlers
        suaMap.on('draw.create', function(e) {
            updateDrawnGeometry();
        });

        suaMap.on('draw.update', function(e) {
            updateDrawnGeometry();
        });

        suaMap.on('draw.delete', function(e) {
            updateDrawnGeometry();
        });

        loadSuaMapData();
    });
}

// Load SUA data with geometry for the map
function loadSuaMapData() {
    if (!suaMap || !mapLoaded || !layersInitialized) {
        console.log('Map not ready yet');
        return;
    }

    var search = $('#suaSearch').val();
    var type = $('#suaTypeFilter').val();
    var artcc = $('#suaArtccFilter').val();

    var params = [];
    if (search) params.push('search=' + encodeURIComponent(search));
    if (type) params.push('type=' + type);
    if (artcc) params.push('artcc=' + artcc);

    var url = 'api/data/sua/sua_geojson.php';
    if (params.length > 0) {
        url += '?' + params.join('&');
    }

    console.log('Loading SUA data from:', url);

    $.getJSON(url).done(function(geojson) {
        console.log('Received GeoJSON with', geojson.features ? geojson.features.length : 0, 'features');

        if (!geojson || !geojson.features) {
            console.error('Invalid GeoJSON data');
            return;
        }

        var areaFeatures = [];
        var routeFeatures = [];

        geojson.features.forEach(function(feature, index) {
            var processed = processFeature(feature);

            // Ensure properties exist
            if (!processed.properties) processed.properties = {};
            processed.properties._id = index;
            processed.properties._color = getFeatureColor(processed.properties);

            // Separate into areas and routes
            if (processed.properties._isRoute) {
                routeFeatures.push(processed);
            } else {
                areaFeatures.push(processed);
            }
        });

        console.log('Processed:', areaFeatures.length, 'areas,', routeFeatures.length, 'routes');

        // Store all features for layer filtering
        allFeatures.areas = areaFeatures;
        allFeatures.routes = routeFeatures;

        // Apply layer filters (this will update the map sources)
        applyLayerFilters();

        // Show total count (before filtering)
        console.log('Total features loaded:', areaFeatures.length + routeFeatures.length);

    }).fail(function(xhr, status, error) {
        console.error('Failed to load SUA map data:', status, error);
    });
}

// Show popup for a feature
function showFeaturePopup(feature, lngLat) {
    var props = feature.properties || {};

    // Support both new and legacy schema
    var suaType = props.sua_type || props.colorName || props.suaType || 'Unknown';
    var typeName = TYPE_NAMES[suaType] || suaType;
    var suaGroup = props.sua_group || getLayerGroup(feature);
    var groupName = GROUP_NAMES[suaGroup] || suaGroup;

    // Altitude display - new schema uses floor_alt/ceiling_alt
    var floorAlt = props.floor_alt || props.lowerLimit || '-';
    var ceilingAlt = props.ceiling_alt || props.upperLimit || '-';
    var altDisplay = floorAlt + ' - ' + ceilingAlt;

    var color = props._color || props.color || '#999';
    var featureType = props._isRoute ? 'Route' : 'Area';

    // Name display
    var displayName = props.name || props.designator || 'Unknown';
    var areaName = props.area_name;

    var popupContent = '<div class="sua-popup">' +
        '<strong>' + displayName + '</strong>';

    if (areaName) {
        popupContent += '<br><small class="text-muted">' + areaName + '</small>';
    }

    popupContent += '<br>' +
        '<span class="badge" style="background-color: ' + color + '; color: #fff;">' + typeName + '</span> ' +
        '<span class="badge badge-info">' + groupName + '</span> ' +
        '<span class="badge badge-secondary">' + featureType + '</span><br>' +
        '<small><strong>ID:</strong> ' + (props.sua_id || props.designator || '-') + '</small><br>' +
        '<small><strong>ARTCC:</strong> ' + (props.artcc || '-') + '</small><br>' +
        '<small><strong>Altitude:</strong> ' + altDisplay + '</small><br>' +
        '<small><strong>Schedule:</strong> ' + (props.scheduleDesc || props.schedule || '-') + '</small><br>' +
        '<button class="btn btn-sm btn-success mt-2" onclick="openScheduleModal(\'' +
            escapeHtml(props.sua_id || props.designator || '') + '\', \'' +
            escapeHtml(suaType) + '\', \'' +
            escapeHtml(props.name || '') + '\', \'' +
            escapeHtml(props.artcc || '') + '\', \'' +
            escapeHtml(floorAlt) + '\', \'' +
            escapeHtml(ceilingAlt) + '\')">' +
            '<i class="fas fa-plus"></i> Activate</button>' +
        '</div>';

    popup.setLngLat(lngLat)
        .setHTML(popupContent)
        .addTo(suaMap);
}

// Toggle between map and table view
function toggleView(view) {
    currentView = view;

    if (view === 'map') {
        $('#sua-map-container').show();
        $('#sua-table-container').hide();
        $('#viewMapBtn').addClass('active');
        $('#viewTableBtn').removeClass('active');

        setTimeout(function() {
            if (suaMap) {
                suaMap.resize();
            }
        }, 100);
    } else {
        $('#sua-map-container').hide();
        $('#sua-table-container').show();
        $('#viewMapBtn').removeClass('active');
        $('#viewTableBtn').addClass('active');
    }
}

// Update drawn geometry from MapboxDraw
function updateDrawnGeometry() {
    if (!draw) return;

    var data = draw.getAll();
    if (data.features.length === 0) {
        drawnGeometry = null;
        $('#altrv_geometry').val('');
        $('#altrv_point_count').text('No geometry drawn');
        return;
    }

    // Get the first feature (we only support one ALTRV at a time)
    var feature = data.features[0];
    drawnGeometry = feature.geometry;

    // Store in hidden form field
    $('#altrv_geometry').val(JSON.stringify(drawnGeometry));

    // Update point count display
    var pointCount = 0;
    var geomType = drawnGeometry.type;
    if (geomType === 'LineString') {
        pointCount = drawnGeometry.coordinates.length;
        $('#altrv_point_count').html('<i class="fas fa-check text-success"></i> Line drawn with <strong>' + pointCount + ' points</strong>');
    } else if (geomType === 'Polygon') {
        pointCount = drawnGeometry.coordinates[0].length - 1; // Subtract closing point
        $('#altrv_point_count').html('<i class="fas fa-check text-success"></i> Polygon drawn with <strong>' + pointCount + ' points</strong>');
    }
}

// Start ALTRV drawing mode
function startAltrvDrawing() {
    if (!suaMap || !mapLoaded || !draw) {
        Swal.fire({
            icon: 'warning',
            title: 'Map Not Ready',
            text: 'Please wait for the map to load before drawing.'
        });
        return;
    }

    isDrawingMode = true;

    // Clear any existing drawings
    draw.deleteAll();
    drawnGeometry = null;
    $('#altrv_geometry').val('');
    $('#altrv_point_count').text('Click on the map to draw points. Double-click to finish.');

    // Enter draw line mode (ALTRVs are typically routes/lines)
    draw.changeMode('draw_line_string');

    // Set default times
    var now = new Date();
    var end = new Date(now.getTime() + 2 * 60 * 60 * 1000);
    $('#altrv_start').val(formatDateTimeLocal(now));
    $('#altrv_end').val(formatDateTimeLocal(end));

    // Show the modal
    $('#altrvModal').modal('show');

    // Show toast instruction
    Swal.fire({
        toast: true,
        position: 'top',
        icon: 'info',
        title: 'Click points on the map to draw the ALTRV route. Double-click to finish.',
        timer: 5000,
        showConfirmButton: false
    });
}

// Clear ALTRV drawing
function clearAltrvDrawing() {
    if (draw) {
        draw.deleteAll();
        drawnGeometry = null;
        $('#altrv_geometry').val('');
        $('#altrv_point_count').text('No geometry drawn. Click on the map to start drawing.');

        // Re-enter draw mode
        draw.changeMode('draw_line_string');
    }
}

// Cancel ALTRV drawing
function cancelAltrvDrawing() {
    isDrawingMode = false;
    if (draw) {
        draw.deleteAll();
        draw.changeMode('simple_select');
    }
    drawnGeometry = null;
    $('#altrv_geometry').val('');
    $('#altrvForm')[0].reset();
}

// Document ready
$(document).ready(function() {
    loadActivations();
    loadSuaBrowser();
    initSuaMap();

    $('#statusFilter').change(function() {
        loadActivations();
    });

    $('#suaSearch').keypress(function(e) {
        if (e.which === 13) {
            loadSuaBrowser();
        }
    });

    // Layer toggle checkboxes
    $('.layer-toggle').change(function() {
        applyLayerFilters();
    });

    // Boundary toggle checkboxes
    $('.boundary-toggle').change(function() {
        var layerId = $(this).val();
        var visible = $(this).is(':checked');
        toggleBoundaryLayer(layerId, visible);
    });

    $('#scheduleForm').submit(function(e) {
        e.preventDefault();

        $.ajax({
            type: 'POST',
            url: 'api/mgt/sua/activate.php',
            data: $(this).serialize(),
            success: function(data) {
                Swal.fire({
                    toast: true,
                    position: 'bottom-right',
                    icon: 'success',
                    title: 'Activation scheduled',
                    timer: 3000,
                    showConfirmButton: false
                });
                loadActivations();
                $('#scheduleModal').modal('hide');
                $('.modal-backdrop').remove();
                $('#scheduleForm')[0].reset();
            },
            error: function(xhr) {
                var msg = 'Failed to schedule activation';
                try {
                    var resp = JSON.parse(xhr.responseText);
                    if (resp.message) msg = resp.message;
                } catch (e) {}
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: msg
                });
            }
        });
    });

    $('#tfrForm').submit(function(e) {
        e.preventDefault();

        var formData = $(this).serialize();

        var lat = parseFloat($('#tfr_lat').val());
        var lon = parseFloat($('#tfr_lon').val());
        var radius = parseFloat($('#tfr_radius').val());

        if (!isNaN(lat) && !isNaN(lon) && !isNaN(radius) && radius > 0) {
            var geometry = generateCircularGeometry(lat, lon, radius);
            formData += '&geometry=' + encodeURIComponent(JSON.stringify(geometry));
        }

        $.ajax({
            type: 'POST',
            url: 'api/mgt/sua/tfr_create.php',
            data: formData,
            success: function(data) {
                Swal.fire({
                    toast: true,
                    position: 'bottom-right',
                    icon: 'success',
                    title: 'TFR created',
                    timer: 3000,
                    showConfirmButton: false
                });
                loadActivations();
                $('#tfrModal').modal('hide');
                $('.modal-backdrop').remove();
                $('#tfrForm')[0].reset();
            },
            error: function(xhr) {
                var msg = 'Failed to create TFR';
                try {
                    var resp = JSON.parse(xhr.responseText);
                    if (resp.message) msg = resp.message;
                } catch (e) {}
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: msg
                });
            }
        });
    });

    // ALTRV form submission
    $('#altrvForm').submit(function(e) {
        e.preventDefault();

        // Validate geometry
        if (!drawnGeometry) {
            Swal.fire({
                icon: 'warning',
                title: 'No Geometry',
                text: 'Please draw the ALTRV route on the map before submitting.'
            });
            return;
        }

        $.ajax({
            type: 'POST',
            url: 'api/mgt/sua/altrv_create.php',
            data: $(this).serialize(),
            success: function(data) {
                Swal.fire({
                    toast: true,
                    position: 'bottom-right',
                    icon: 'success',
                    title: 'ALTRV created',
                    timer: 3000,
                    showConfirmButton: false
                });
                loadActivations();
                loadSuaMapData();
                cancelAltrvDrawing();
                $('#altrvModal').modal('hide');
                $('.modal-backdrop').remove();
            },
            error: function(xhr) {
                var msg = 'Failed to create ALTRV';
                try {
                    var resp = JSON.parse(xhr.responseText);
                    if (resp.message) msg = resp.message;
                } catch (e) {}
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: msg
                });
            }
        });
    });

    // Close ALTRV modal cleanup
    $('#altrvModal').on('hidden.bs.modal', function() {
        cancelAltrvDrawing();
    });

    $('#editModal').on('show.bs.modal', function(event) {
        var button = $(event.relatedTarget);
        var modal = $(this);

        modal.find('#edit_id').val(button.data('id'));
        modal.find('#edit_name').val(button.data('name'));
        modal.find('#edit_start').val(button.data('start'));
        modal.find('#edit_end').val(button.data('end'));
        modal.find('#edit_lower_alt').val(button.data('lower-alt'));
        modal.find('#edit_upper_alt').val(button.data('upper-alt'));
        modal.find('#edit_remarks').val(button.data('remarks'));
    });

    $('#editForm').submit(function(e) {
        e.preventDefault();

        $.ajax({
            type: 'POST',
            url: 'api/mgt/sua/update.php',
            data: $(this).serialize(),
            success: function(data) {
                Swal.fire({
                    toast: true,
                    position: 'bottom-right',
                    icon: 'success',
                    title: 'Activation updated',
                    timer: 3000,
                    showConfirmButton: false
                });
                loadActivations();
                $('#editModal').modal('hide');
                $('.modal-backdrop').remove();
            },
            error: function(xhr) {
                var msg = 'Failed to update activation';
                try {
                    var resp = JSON.parse(xhr.responseText);
                    if (resp.message) msg = resp.message;
                } catch (e) {}
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: msg
                });
            }
        });
    });

    setInterval(function() {
        loadActivations();
    }, 60000);

    var now = new Date();
    var end = new Date(now.getTime() + 2 * 60 * 60 * 1000);
    $('#tfr_start').val(formatDateTimeLocal(now));
    $('#tfr_end').val(formatDateTimeLocal(end));
});
