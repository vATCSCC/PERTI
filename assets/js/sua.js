/**
 * SUA/TFR Management Page JavaScript
 * Uses MapLibre GL JS for map rendering
 */

// Type display names
const TYPE_NAMES = {
    'P': 'Prohibited',
    'R': 'Restricted',
    'W': 'Warning',
    'A': 'Alert',
    'MOA': 'MOA',
    'NSA': 'NSA',
    'ATCAA': 'ATCAA',
    'IR': 'IR Route',
    'VR': 'VR Route',
    'SR': 'SR Route',
    'AR': 'Air Refueling',
    'TFR': 'TFR',
    'ALTRV': 'ALTRV',
    'OPAREA': 'Operating Area',
    'AW': 'AWACS Orbit',
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
    'PROHIBITED': 'Prohibited',
    'RESTRICTED': 'Restricted',
    'WARNING': 'Warning',
    'ALERT': 'Alert',
    'OTHER': 'Other',
    'Unknown': 'Other'
};

// Types that should remain as lines (routes, tracks) - NOT converted to polygons
const LINE_TYPES = [
    'AR',      // Air Refueling tracks
    'ALTRV',   // Altitude Reservations
    'IR',      // IFR Routes
    'VR',      // VFR Routes
    'SR',      // Slow Routes
    'OSARA',   // Offshore Airspace Restricted Areas
    'SS'       // Supersonic corridors
];

// Map variables
var suaMap = null;
var currentView = 'map';
var popup = null;
var mapLoaded = false;
var layersInitialized = false;
var allFeatures = { areas: [], routes: [] }; // Store all loaded features for filtering

// Layer type mapping - maps colorName to layer group
const LAYER_GROUPS = {
    'PROHIBITED': 'PROHIBITED',
    'RESTRICTED': 'RESTRICTED',
    'WARNING': 'WARNING',
    'ALERT': 'ALERT',
    'MOA': 'MOA',
    'NSA': 'NSA',
    'TFR': 'TFR',
    'AR': 'AR',
    'ALTRV': 'ALTRV',
    'AW': 'AW',
    'USN': 'USN',
    'ADIZ': 'OTHER',
    'FRZ': 'OTHER',
    'OPAREA': 'OTHER',
    'DZ': 'OTHER',
    'LASER': 'OTHER',
    'NUCLEAR': 'OTHER',
    'USAF': 'OTHER',
    'USArmy': 'OTHER',
    'ANG': 'OTHER',
    'OSARA': 'OTHER',
    'SS': 'OTHER',
    'WSRP': 'OTHER',
    'NORAD': 'OTHER',
    'NASA': 'OTHER',
    'NOAA': 'OTHER',
    'MODEC': 'OTHER',
    'SUA': 'OTHER',
    'Unknown': 'OTHER',
    '120': 'OTHER',
    '180': 'OTHER'
};

// Get the layer group for a feature
function getLayerGroup(colorName) {
    return LAYER_GROUPS[colorName] || 'OTHER';
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

    // Filter area features
    var filteredAreas = allFeatures.areas.filter(function(f) {
        var colorName = f.properties.colorName || 'Unknown';
        var group = getLayerGroup(colorName);
        return enabledLayers.indexOf(group) !== -1;
    });

    // Filter route features
    var filteredRoutes = allFeatures.routes.filter(function(f) {
        var colorName = f.properties.colorName || 'Unknown';
        var group = getLayerGroup(colorName);
        return enabledLayers.indexOf(group) !== -1;
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
                    var typeName = TYPE_NAMES[sua.suaType] || TYPE_NAMES[sua.colorName] || sua.suaType;
                    var altDisplay = (sua.lowerLimit || '-') + ' - ' + (sua.upperLimit || '-');

                    html += '<tr>';
                    html += '<td><span class="badge badge-primary">' + typeName + '</span></td>';
                    html += '<td class="text-monospace">' + (sua.designator || '-') + '</td>';
                    html += '<td>' + (sua.name || '-') + '</td>';
                    html += '<td>' + (sua.artcc || '-') + '</td>';
                    html += '<td class="small">' + altDisplay + '</td>';
                    html += '<td class="small">' + (sua.scheduleDesc || sua.schedule || '-') + '</td>';
                    html += '<td>';
                    html += '<button class="btn btn-sm btn-success activation-btn" onclick="openScheduleModal(\'' +
                            escapeHtml(sua.designator || '') + '\', \'' +
                            escapeHtml(sua.suaType || '') + '\', \'' +
                            escapeHtml(sua.name || '') + '\', \'' +
                            escapeHtml(sua.artcc || '') + '\', \'' +
                            escapeHtml(sua.lowerLimit || '') + '\', \'' +
                            escapeHtml(sua.upperLimit || '') + '\')">';
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
function isLineType(colorName, suaType) {
    var typeToCheck = colorName || suaType || '';
    return LINE_TYPES.indexOf(typeToCheck) !== -1;
}

// Convert LineString to Polygon only for area types (not routes)
function processFeature(feature) {
    if (!feature.geometry) return feature;

    var props = feature.properties || {};
    var colorName = props.colorName || '';
    var suaType = props.suaType || '';

    // If this is a line type (route), keep it as a line
    if (isLineType(colorName, suaType)) {
        // Mark as line type for styling
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
    var colorName = props.colorName || props.suaType || 'Unknown';
    var typeName = TYPE_NAMES[colorName] || colorName;
    var altDisplay = (props.lowerLimit || '-') + ' - ' + (props.upperLimit || '-');
    var color = props._color || props.color || '#999';
    var featureType = props._isRoute ? 'Route' : 'Area';

    var popupContent = '<div class="sua-popup">' +
        '<strong>' + (props.name || props.designator || 'Unknown') + '</strong><br>' +
        '<span class="badge" style="background-color: ' + color + '; color: #fff;">' + typeName + '</span> ' +
        '<span class="badge badge-secondary">' + featureType + '</span><br>' +
        '<small><strong>Designator:</strong> ' + (props.designator || '-') + '</small><br>' +
        '<small><strong>ARTCC:</strong> ' + (props.artcc || '-') + '</small><br>' +
        '<small><strong>Altitude:</strong> ' + altDisplay + '</small><br>' +
        '<small><strong>Schedule:</strong> ' + (props.scheduleDesc || props.schedule || '-') + '</small><br>' +
        '<button class="btn btn-sm btn-success mt-2" onclick="openScheduleModal(\'' +
            escapeHtml(props.designator || '') + '\', \'' +
            escapeHtml(props.suaType || colorName) + '\', \'' +
            escapeHtml(props.name || '') + '\', \'' +
            escapeHtml(props.artcc || '') + '\', \'' +
            escapeHtml(props.lowerLimit || '') + '\', \'' +
            escapeHtml(props.upperLimit || '') + '\')">' +
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
