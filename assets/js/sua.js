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
    'IR': 'IR',
    'VR': 'VR',
    'SR': 'SR',
    'AR': 'Air Refueling',
    'TFR': 'TFR',
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
    'OTHER': 'Other'
};

// Type colors for map display (fallback when no color in data)
const TYPE_COLORS = {
    'P': '#ff0000',    // Red - Prohibited
    'R': '#ff6600',    // Orange - Restricted
    'W': '#9900ff',    // Purple - Warning
    'A': '#ff00ff',    // Magenta - Alert
    'MOA': '#0066ff',  // Blue - MOA
    'NSA': '#00cc00',  // Green - NSA
    'ATCAA': '#006699', // Dark Blue - ATCAA
    'TFR': '#cc0000',  // Dark Red - TFR
    'AR': '#00cccc',   // Cyan - Air Refueling
    'ALTRV': '#cc6600', // Brown - ALTRV
    'OPAREA': '#996699', // Purple-gray - Operating Area
    'AW': '#669900',   // Olive - AW
    'USN': '#003366',  // Navy - USN Area
    'DZ': '#cc0066',   // Pink - Drop Zone
    'ADIZ': '#990000', // Dark Red - ADIZ
    'OSARA': '#666600', // Dark olive - OSARA
    'WSRP': '#009999', // Teal - Weather Radar
    'SS': '#cc3300',   // Red-orange - Supersonic
    'USArmy': '#336600', // Army green
    'LASER': '#ff3399', // Bright pink - Laser
    'USAF': '#0033cc', // Air Force blue
    'ANG': '#006633',  // Guard green
    'NUCLEAR': '#ff0000', // Red - Nuclear
    'NORAD': '#333399', // Dark blue - NORAD
    'NOAA': '#0099cc', // Light blue - NOAA
    'NASA': '#ff6600', // Orange - NASA
    'MODEC': '#999966', // Olive gray
    'FRZ': '#cc0000',  // Dark red - FRZ
    'OTHER': '#999999' // Gray - Other
};

// Map variables
var suaMap = null;
var currentView = 'map';
var popup = null;
var mapLoaded = false;
var layersInitialized = false;

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
                    var typeName = TYPE_NAMES[sua.suaType] || sua.suaType;
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
    // Convert to string to handle numbers and other types
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

    // Set default times (now to now+2hours)
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
    var radiusMeters = radiusNM * 1852; // Convert NM to meters
    var points = 36; // Number of points in circle
    var coordinates = [];

    for (var i = 0; i <= points; i++) {
        var angle = (i / points) * 2 * Math.PI;
        // Approximate offset (simplified, good enough for display)
        var dLat = (radiusMeters / 111320) * Math.cos(angle);
        var dLon = (radiusMeters / (111320 * Math.cos(lat * Math.PI / 180))) * Math.sin(angle);
        coordinates.push([lon + dLon, lat + dLat]);
    }

    return {
        type: 'Polygon',
        coordinates: [coordinates]
    };
}

// Convert LineString to Polygon if it's a closed shape
function convertToPolygon(feature) {
    if (feature.geometry && feature.geometry.type === 'LineString') {
        var coords = feature.geometry.coordinates;
        if (coords && coords.length >= 3) {
            // Check if it's closed (first and last points are same or very close)
            var first = coords[0];
            var last = coords[coords.length - 1];
            var isClosed = (first[0] === last[0] && first[1] === last[1]) ||
                          (Math.abs(first[0] - last[0]) < 0.0001 && Math.abs(first[1] - last[1]) < 0.0001);

            // If not closed, close it
            var polygonCoords = coords.slice();
            if (!isClosed) {
                polygonCoords.push(coords[0]);
            }

            return {
                type: 'Feature',
                properties: feature.properties,
                geometry: {
                    type: 'Polygon',
                    coordinates: [polygonCoords]
                }
            };
        }
    }
    return feature;
}

// Get color for a feature
function getFeatureColor(props) {
    // Use color from data if available
    if (props && props.color) {
        return props.color;
    }
    // Fall back to type-based color
    var suaType = (props && (props.suaType || props.colorName)) || 'OTHER';
    return TYPE_COLORS[suaType] || TYPE_COLORS['OTHER'];
}

// Initialize the SUA map with MapLibre GL
function initSuaMap() {
    if (suaMap) return; // Already initialized

    // Create map centered on CONUS
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

    // Add navigation controls
    suaMap.addControl(new maplibregl.NavigationControl(), 'top-right');

    // Create popup for interactions
    popup = new maplibregl.Popup({
        closeButton: true,
        closeOnClick: false,
        maxWidth: '300px'
    });

    // Set up event handlers ONCE when map loads
    suaMap.on('load', function() {
        console.log('Map loaded');
        mapLoaded = true;

        // Add empty source
        suaMap.addSource('sua-data', {
            type: 'geojson',
            data: {
                type: 'FeatureCollection',
                features: []
            }
        });

        // Add fill layer for polygons
        suaMap.addLayer({
            id: 'sua-fill',
            type: 'fill',
            source: 'sua-data',
            filter: ['==', '$type', 'Polygon'],
            paint: {
                'fill-color': ['coalesce', ['get', '_color'], '#999999'],
                'fill-opacity': 0.25
            }
        });

        // Add line layer for outlines
        suaMap.addLayer({
            id: 'sua-line',
            type: 'line',
            source: 'sua-data',
            paint: {
                'line-color': ['coalesce', ['get', '_color'], '#999999'],
                'line-width': 2,
                'line-opacity': 0.9
            }
        });

        // Add click handlers (only once)
        suaMap.on('click', 'sua-fill', function(e) {
            if (e.features && e.features.length > 0) {
                showFeaturePopup(e.features[0], e.lngLat);
            }
        });

        suaMap.on('click', 'sua-line', function(e) {
            if (e.features && e.features.length > 0) {
                showFeaturePopup(e.features[0], e.lngLat);
            }
        });

        // Change cursor on hover
        suaMap.on('mouseenter', 'sua-fill', function() {
            suaMap.getCanvas().style.cursor = 'pointer';
        });
        suaMap.on('mouseleave', 'sua-fill', function() {
            suaMap.getCanvas().style.cursor = '';
        });
        suaMap.on('mouseenter', 'sua-line', function() {
            suaMap.getCanvas().style.cursor = 'pointer';
        });
        suaMap.on('mouseleave', 'sua-line', function() {
            suaMap.getCanvas().style.cursor = '';
        });

        layersInitialized = true;

        // Now load the actual data
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

        // Convert LineStrings to Polygons and add colors
        var processedFeatures = geojson.features.map(function(feature, index) {
            var converted = convertToPolygon(feature);
            // Add a unique ID for interaction
            if (!converted.properties) converted.properties = {};
            converted.properties._id = index;
            // Ensure color is set
            converted.properties._color = getFeatureColor(converted.properties);
            return converted;
        });

        var processedGeojson = {
            type: 'FeatureCollection',
            features: processedFeatures
        };

        console.log('Processed', processedFeatures.length, 'features');

        // Update the source data
        var source = suaMap.getSource('sua-data');
        if (source) {
            source.setData(processedGeojson);
            console.log('Updated map source');
        } else {
            console.error('Source sua-data not found');
        }

        // Update count
        $('#sua_count').text(processedFeatures.length);

    }).fail(function(xhr, status, error) {
        console.error('Failed to load SUA map data:', status, error);
    });
}

// Show popup for a feature
function showFeaturePopup(feature, lngLat) {
    var props = feature.properties || {};
    var typeName = TYPE_NAMES[props.suaType] || props.suaType || props.colorName || 'Unknown';
    var altDisplay = (props.lowerLimit || '-') + ' - ' + (props.upperLimit || '-');
    var color = props._color || getFeatureColor(props);

    var popupContent = '<div class="sua-popup">' +
        '<strong>' + (props.name || props.designator || 'Unknown') + '</strong><br>' +
        '<span class="badge" style="background-color: ' + color + '; color: #fff;">' + typeName + '</span><br>' +
        '<small><strong>Designator:</strong> ' + (props.designator || '-') + '</small><br>' +
        '<small><strong>ARTCC:</strong> ' + (props.artcc || '-') + '</small><br>' +
        '<small><strong>Altitude:</strong> ' + altDisplay + '</small><br>' +
        '<small><strong>Schedule:</strong> ' + (props.scheduleDesc || props.schedule || '-') + '</small><br>' +
        '<button class="btn btn-sm btn-success mt-2" onclick="openScheduleModal(\'' +
            escapeHtml(props.designator || '') + '\', \'' +
            escapeHtml(props.suaType || '') + '\', \'' +
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

        // Resize map after showing (fixes display issues)
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
    // Initial load
    loadActivations();
    loadSuaBrowser();

    // Initialize SUA map
    initSuaMap();

    // Status filter change
    $('#statusFilter').change(function() {
        loadActivations();
    });

    // Search on enter key
    $('#suaSearch').keypress(function(e) {
        if (e.which === 13) {
            loadSuaBrowser();
        }
    });

    // Schedule form submission
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

    // TFR form submission
    $('#tfrForm').submit(function(e) {
        e.preventDefault();

        var formData = $(this).serialize();

        // Generate geometry if coordinates provided
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

    // Edit modal population
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

    // Edit form submission
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

    // Auto-refresh activations every 60 seconds
    setInterval(function() {
        loadActivations();
    }, 60000);

    // Set default TFR times
    var now = new Date();
    var end = new Date(now.getTime() + 2 * 60 * 60 * 1000);
    $('#tfr_start').val(formatDateTimeLocal(now));
    $('#tfr_end').val(formatDateTimeLocal(end));
});
