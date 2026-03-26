/**
 * Historical Routes - Map Module
 * MapLibre route visualization with frequency-based styling
 * Includes ARTCC/TRACON/sector boundary layers matching route.php
 */
window.RoutesMap = (function() {
    'use strict';

    var map = null;
    var mapReady = false;
    var pendingFeatures = null; // queued features waiting for style load
    var routeGeometryCache = {}; // dimId -> GeoJSON
    var currentRoutes = [];
    var highlightedDimId = null;
    var multiSelectDimIds = [];
    var routeCount = 0; // routes successfully plotted
    var MAX_MAP_ROUTES = 25;

    // Multi-select colors (up to 6)
    var MULTI_COLORS = ['#FF6B6B', '#4ECDC4', '#FFE66D', '#7B68EE', '#FF8C42', '#A8E6CF'];

    // Layer visibility state
    var layerState = {
        artcc: true,
        artcc_labels: true,
        tracon: false,
        high: false,
        low: false,
        superhigh: false,
        artcc_super: false,
        artcc_sub: false,
        artcc_deep: false
    };

    function init(containerId) {
        if (map) return; // already initialized

        map = new maplibregl.Map({
            container: containerId,
            style: {
                version: 8,
                name: 'PERTI Routes Dark',
                sources: {
                    'carto-dark': {
                        type: 'raster',
                        tiles: [
                            'https://a.basemaps.cartocdn.com/rastertiles/dark_nolabels/{z}/{x}/{y}.png',
                            'https://b.basemaps.cartocdn.com/rastertiles/dark_nolabels/{z}/{x}/{y}.png',
                            'https://c.basemaps.cartocdn.com/rastertiles/dark_nolabels/{z}/{x}/{y}.png',
                            'https://d.basemaps.cartocdn.com/rastertiles/dark_nolabels/{z}/{x}/{y}.png',
                        ],
                        tileSize: 256,
                        attribution: '&copy; CARTO',
                    },
                },
                layers: [{
                    id: 'carto-dark-layer',
                    type: 'raster',
                    source: 'carto-dark',
                }],
                glyphs: 'https://demotiles.maplibre.org/font/{fontstack}/{range}.pbf',
            },
            center: [-98.35, 39.5],
            zoom: 4,
        });

        map.addControl(new maplibregl.NavigationControl(), 'top-right');

        map.on('load', function() {
            mapReady = true;
            map.resize();
            loadBoundaryLayers();
            buildLayerControls(containerId);
            if (pendingFeatures) {
                addRoutesToMap(pendingFeatures);
                pendingFeatures = null;
            }
        });
    }

    /**
     * Load all boundary layers matching route.php
     * Sector colors match route-maplibre.js sectorLineDark (#1a1a1a)
     */
    function loadBoundaryLayers() {
        var sectorColor = '#555';
        var sectorWidth = 1;
        var sectorOpacity = 0.5;

        // ARTCC boundaries (L1 FIR lines)
        fetch('assets/geojson/artcc.json')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                map.addSource('artcc', { type: 'geojson', data: data });
                map.addLayer({
                    id: 'artcc-lines', type: 'line', source: 'artcc',
                    filter: ['any', ['==', ['get', 'hierarchy_level'], 1], ['!', ['has', 'hierarchy_level']]],
                    paint: { 'line-color': '#4A90D9', 'line-width': 1.5, 'line-opacity': 0.7 },
                });
                map.addLayer({
                    id: 'artcc-labels', type: 'symbol', source: 'artcc',
                    filter: ['any', ['==', ['get', 'hierarchy_level'], 1], ['!', ['has', 'hierarchy_level']]],
                    layout: {
                        'text-field': ['get', 'ICAOCODE'],
                        'text-font': ['Noto Sans Bold'],
                        'text-size': ['interpolate', ['linear'], ['zoom'], 3, 10, 5, 13, 8, 16],
                        'text-allow-overlap': false,
                        'text-ignore-placement': false,
                        'text-padding': 5,
                    },
                    paint: {
                        'text-color': '#4A90D9',
                        'text-halo-color': 'rgba(0, 0, 0, 0.8)',
                        'text-halo-width': 1.5,
                        'text-opacity': 0.8,
                    },
                });
            })
            .catch(function(err) { console.warn('[RoutesMap] Failed to load ARTCC boundaries:', err); });

        // ARTCC hierarchy layers
        fetch('assets/geojson/supercenter.json')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                map.addSource('artcc-super-source', { type: 'geojson', data: data });
                map.addLayer({
                    id: 'artcc-super-lines', type: 'line', source: 'artcc-super-source',
                    paint: { 'line-color': '#F0C946', 'line-width': 2.5, 'line-opacity': 0.8 },
                    layout: { visibility: 'none' },
                });
            })
            .catch(function() {});

        fetch('assets/geojson/artcc_area.json')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                map.addSource('artcc-area-source', { type: 'geojson', data: data });
                map.addLayer({
                    id: 'artcc-sub-lines', type: 'line', source: 'artcc-area-source',
                    filter: ['==', ['get', 'hierarchy_level'], 2],
                    paint: { 'line-color': '#2E6AAD', 'line-width': 1.2, 'line-opacity': 0.8, 'line-dasharray': [4, 3] },
                    layout: { visibility: 'none' },
                });
                map.addLayer({
                    id: 'artcc-deep-lines', type: 'line', source: 'artcc-area-source',
                    filter: ['>=', ['get', 'hierarchy_level'], 3],
                    paint: { 'line-color': '#1E4A7A', 'line-width': 0.8, 'line-opacity': 0.8, 'line-dasharray': [4, 3] },
                    layout: { visibility: 'none' },
                });
            })
            .catch(function() {});

        // TRACON boundaries (hidden by default)
        fetch('assets/geojson/tracon.json')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                map.addSource('tracon', { type: 'geojson', data: data });
                map.addLayer({
                    id: 'tracon-lines', type: 'line', source: 'tracon',
                    paint: { 'line-color': '#666', 'line-width': 0.8, 'line-opacity': 0.5 },
                    layout: { visibility: 'none' },
                });
            })
            .catch(function(err) { console.warn('[RoutesMap] Failed to load TRACON boundaries:', err); });

        // High altitude sectors (hidden by default)
        fetch('assets/geojson/high.json')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                map.addSource('high-sectors', { type: 'geojson', data: data });
                map.addLayer({
                    id: 'high-sector-lines', type: 'line', source: 'high-sectors',
                    paint: { 'line-color': sectorColor, 'line-width': sectorWidth, 'line-opacity': sectorOpacity },
                    layout: { visibility: 'none' },
                });
            })
            .catch(function() {});

        // Low altitude sectors (hidden by default)
        fetch('assets/geojson/low.json')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                map.addSource('low-sectors', { type: 'geojson', data: data });
                map.addLayer({
                    id: 'low-sector-lines', type: 'line', source: 'low-sectors',
                    paint: { 'line-color': sectorColor, 'line-width': sectorWidth, 'line-opacity': sectorOpacity },
                    layout: { visibility: 'none' },
                });
            })
            .catch(function() {});

        // Superhigh altitude sectors (hidden by default)
        fetch('assets/geojson/superhigh.json')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                map.addSource('superhigh-sectors', { type: 'geojson', data: data });
                map.addLayer({
                    id: 'superhigh-sector-lines', type: 'line', source: 'superhigh-sectors',
                    paint: { 'line-color': sectorColor, 'line-width': sectorWidth, 'line-opacity': sectorOpacity },
                    layout: { visibility: 'none' },
                });
            })
            .catch(function() {});
    }

    /**
     * Build layer toggle controls and route count badge
     */
    function buildLayerControls(containerId) {
        var container = document.getElementById(containerId);
        if (!container) return;

        // Layer control panel
        var panel = document.createElement('div');
        panel.className = 'rmap-layer-panel';

        // Toggle button
        var toggleBtn = document.createElement('div');
        toggleBtn.className = 'rmap-layer-toggle';
        toggleBtn.innerHTML = '<i class="fas fa-layer-group"></i> Overlays';
        toggleBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            var list = panel.querySelector('.rmap-layer-list');
            if (list) {
                var isShown = list.style.display !== 'none';
                list.style.display = isShown ? 'none' : 'block';
                toggleBtn.classList.toggle('expanded', !isShown);
            }
        });
        panel.appendChild(toggleBtn);

        var list = document.createElement('div');
        list.className = 'rmap-layer-list';
        list.style.display = 'none';

        // Separator helper
        function addSeparator(label) {
            var sep = document.createElement('div');
            sep.className = 'rmap-layer-separator';
            sep.textContent = label;
            list.appendChild(sep);
        }

        var layers = [
            { id: 'artcc', label: 'ARTCC Boundaries', layers: ['artcc-lines'], checked: true },
            { id: 'artcc_labels', label: 'ARTCC Labels', layers: ['artcc-labels'], checked: true },
            { id: 'artcc_super', label: 'Super Centers', layers: ['artcc-super-lines'], checked: false, separator: 'ARTCC Hierarchy' },
            { id: 'artcc_sub', label: 'Sub Areas', layers: ['artcc-sub-lines'], checked: false },
            { id: 'artcc_deep', label: 'Deep Sub Areas', layers: ['artcc-deep-lines'], checked: false },
            { id: 'tracon', label: 'TRACON', layers: ['tracon-lines'], checked: false, separator: 'Sectors' },
            { id: 'high', label: 'High Sectors', layers: ['high-sector-lines'], checked: false },
            { id: 'low', label: 'Low Sectors', layers: ['low-sector-lines'], checked: false },
            { id: 'superhigh', label: 'Superhigh Sectors', layers: ['superhigh-sector-lines'], checked: false }
        ];

        layers.forEach(function(layer) {
            if (layer.separator) addSeparator(layer.separator);

            var row = document.createElement('label');
            row.className = 'rmap-layer-row';

            var checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.checked = layer.checked;
            checkbox.className = 'rmap-layer-check';
            checkbox.addEventListener('change', function() {
                var vis = this.checked ? 'visible' : 'none';
                layer.layers.forEach(function(layerId) {
                    if (map.getLayer(layerId)) {
                        map.setLayoutProperty(layerId, 'visibility', vis);
                    }
                });
                layerState[layer.id] = this.checked;
            });

            var span = document.createElement('span');
            span.className = 'rmap-layer-label';
            span.textContent = layer.label;

            row.appendChild(checkbox);
            row.appendChild(span);
            list.appendChild(row);
        });

        panel.appendChild(list);

        // Close panel when clicking outside
        document.addEventListener('click', function(e) {
            if (!panel.contains(e.target)) {
                list.style.display = 'none';
                toggleBtn.classList.remove('expanded');
            }
        });

        container.appendChild(panel);

        // Route count badge
        var badge = document.createElement('div');
        badge.className = 'rmap-route-count';
        badge.id = 'rmap_route_count';
        badge.style.display = 'none';
        container.appendChild(badge);
    }

    function updateRouteCount(count, total) {
        var badge = document.getElementById('rmap_route_count');
        if (!badge) return;
        if (count <= 0) {
            badge.style.display = 'none';
            return;
        }
        var label = count + ' route' + (count !== 1 ? 's' : '') + ' on map';
        if (total > MAX_MAP_ROUTES) {
            label += ' (top ' + MAX_MAP_ROUTES + ' of ' + total + ')';
        }
        badge.textContent = label;
        badge.style.display = 'block';
    }

    /**
     * Plot routes on the map. Fetches geometry from analysis.php for each route.
     * @param {Array} routes - Array of route objects from search results
     * @param {number} totalFlights - Total flights across all routes (for frequency %)
     */
    function plotRoutes(routes, totalFlights) {
        clearRoutes();
        currentRoutes = routes;

        if (!map || !routes || routes.length === 0) {
            updateRouteCount(0, 0);
            return;
        }

        var totalRoutes = routes.length;
        var toFetch = routes.slice(0, MAX_MAP_ROUTES);
        var pendingCount = toFetch.length;
        var features = [];

        toFetch.forEach(function(route) {
            var dimId = route.route_dim_id;

            // Check cache
            if (routeGeometryCache[dimId]) {
                var feature = buildFeature(route, routeGeometryCache[dimId], totalFlights);
                if (feature) features.push(feature);
                pendingCount--;
                if (pendingCount === 0) {
                    addRoutesToMap(features);
                    updateRouteCount(features.length, totalRoutes);
                }
                return;
            }

            // Fetch from analysis.php
            $.ajax({
                url: 'api/data/route-history/analysis.php',
                method: 'GET',
                data: {
                    route_string: route.normalized_route,
                    origin: route.origin_icao,
                    dest: route.dest_icao
                },
                dataType: 'json',
                timeout: 15000,
                success: function(data) {
                    if (data.status === 'ok' && data.waypoints && data.waypoints.length >= 2) {
                        var coords = data.waypoints.map(function(wp) {
                            return [wp.lon, wp.lat];
                        });
                        routeGeometryCache[dimId] = {
                            coordinates: coords,
                            waypoints: data.waypoints,
                            total_dist_nm: data.total_dist_nm
                        };
                        var feature = buildFeature(route, routeGeometryCache[dimId], totalFlights);
                        if (feature) features.push(feature);
                    }
                },
                error: function(xhr, status, err) {
                    console.warn('[RoutesMap] Geometry fetch failed for route', dimId, status, err);
                },
                complete: function() {
                    pendingCount--;
                    if (pendingCount === 0) {
                        addRoutesToMap(features);
                        updateRouteCount(features.length, totalRoutes);
                    }
                }
            });
        });
    }

    function buildFeature(route, geometry, totalFlights) {
        if (!geometry || !geometry.coordinates || geometry.coordinates.length < 2) return null;

        var pct = totalFlights > 0 ? (route.flight_count / totalFlights * 100) : 0;
        var tier = pct > 10 ? 'high' : (pct >= 3 ? 'medium' : 'low');

        return {
            type: 'Feature',
            properties: {
                dim_id: route.route_dim_id,
                origin: route.origin_icao,
                dest: route.dest_icao,
                flight_count: route.flight_count,
                frequency_pct: pct,
                tier: tier,
                normalized_route: route.normalized_route
            },
            geometry: {
                type: 'LineString',
                coordinates: geometry.coordinates
            }
        };
    }

    function addRoutesToMap(features) {
        if (!map || features.length === 0) return;

        // Queue if style hasn't loaded yet
        if (!mapReady) {
            pendingFeatures = features;
            return;
        }

        // Remove existing route layers/sources (keep boundary layers)
        removeRouteLayers();

        var geojson = {
            type: 'FeatureCollection',
            features: features
        };

        map.addSource('routes', { type: 'geojson', data: geojson });

        // Route lines with spectral color ramp: blue (rare) -> yellow -> red (frequent)
        map.addLayer({
            id: 'routes-lines',
            type: 'line',
            source: 'routes',
            paint: {
                'line-color': ['interpolate', ['linear'], ['get', 'frequency_pct'],
                    0,  '#4fc3f7',   // light blue (rare)
                    2,  '#81d4fa',   // cyan
                    5,  '#66bb6a',   // green
                    10, '#ffee58',   // yellow
                    20, '#ffa726',   // orange
                    35, '#ef5350'    // red (very frequent)
                ],
                'line-width': ['interpolate', ['linear'], ['get', 'frequency_pct'],
                    0, 1.5,
                    5, 2,
                    15, 3,
                    35, 5
                ],
                'line-opacity': ['interpolate', ['linear'], ['get', 'frequency_pct'],
                    0, 0.55,
                    5, 0.7,
                    15, 0.85,
                    35, 1
                ]
            },
            layout: {
                'line-cap': 'round',
                'line-join': 'round'
            }
        });

        // Click handler for route selection
        map.on('click', 'routes-lines', function(e) {
            if (e.features && e.features.length > 0) {
                var dimId = e.features[0].properties.dim_id;
                if (typeof window.onRouteMapClick === 'function') {
                    window.onRouteMapClick(dimId);
                }
            }
        });

        // Cursor change on hover
        map.on('mouseenter', 'routes-lines', function() {
            map.getCanvas().style.cursor = 'pointer';
        });
        map.on('mouseleave', 'routes-lines', function() {
            map.getCanvas().style.cursor = '';
        });

        // Fit bounds to show all routes
        fitToRoutes(features);

        // Show airport markers after geometry is loaded
        showAirportMarkers(currentRoutes);
    }

    function highlightRoute(dimId) {
        if (!map) return;
        if (!map.getSource('routes')) return;
        highlightedDimId = dimId;

        if (map.getLayer('routes-highlight')) map.removeLayer('routes-highlight');

        map.addLayer({
            id: 'routes-highlight',
            type: 'line',
            source: 'routes',
            filter: ['==', ['get', 'dim_id'], dimId],
            paint: {
                'line-color': '#f59e0b',
                'line-width': 5,
                'line-opacity': 1
            },
            layout: {
                'line-cap': 'round',
                'line-join': 'round'
            }
        });

        showWaypoints(dimId);
    }

    function clearHighlight() {
        if (!map) return;
        highlightedDimId = null;
        if (map.getLayer('routes-highlight')) map.removeLayer('routes-highlight');
        hideWaypoints();
    }

    function highlightMultiple(dimIds) {
        if (!map) return;
        if (!map.getSource('routes')) return;
        multiSelectDimIds = dimIds;

        if (map.getLayer('routes-highlight')) map.removeLayer('routes-highlight');

        for (var i = 0; i < 6; i++) {
            if (map.getLayer('routes-multi-' + i)) map.removeLayer('routes-multi-' + i);
        }

        dimIds.forEach(function(dimId, index) {
            if (index >= 6) return;
            map.addLayer({
                id: 'routes-multi-' + index,
                type: 'line',
                source: 'routes',
                filter: ['==', ['get', 'dim_id'], dimId],
                paint: {
                    'line-color': MULTI_COLORS[index],
                    'line-width': 4,
                    'line-opacity': 0.9
                },
                layout: {
                    'line-cap': 'round',
                    'line-join': 'round'
                }
            });
        });
    }

    function showWaypoints(dimId) {
        hideWaypoints();
        var cached = routeGeometryCache[dimId];
        if (!cached || !cached.waypoints) return;

        var features = cached.waypoints.map(function(wp) {
            return {
                type: 'Feature',
                properties: { fix_name: wp.fix_name || '' },
                geometry: { type: 'Point', coordinates: [wp.lon, wp.lat] }
            };
        });

        map.addSource('route-waypoints', {
            type: 'geojson',
            data: { type: 'FeatureCollection', features: features }
        });

        map.addLayer({
            id: 'route-waypoint-dots',
            type: 'circle',
            source: 'route-waypoints',
            paint: {
                'circle-radius': 4,
                'circle-color': '#f59e0b',
                'circle-stroke-color': '#000',
                'circle-stroke-width': 1
            }
        });

        map.addLayer({
            id: 'route-waypoint-labels',
            type: 'symbol',
            source: 'route-waypoints',
            layout: {
                'text-field': ['get', 'fix_name'],
                'text-size': 10,
                'text-offset': [0, -1.2],
                'text-anchor': 'bottom',
                'text-font': ['Noto Sans Bold']
            },
            paint: {
                'text-color': '#fff',
                'text-halo-color': '#000',
                'text-halo-width': 1
            }
        });
    }

    function hideWaypoints() {
        if (!map) return;
        if (map.getLayer('route-waypoint-labels')) map.removeLayer('route-waypoint-labels');
        if (map.getLayer('route-waypoint-dots')) map.removeLayer('route-waypoint-dots');
        if (map.getSource('route-waypoints')) map.removeSource('route-waypoints');
    }

    function fitToRoutes(features) {
        if (!map || !features || features.length === 0) return;
        var bounds = new maplibregl.LngLatBounds();
        features.forEach(function(f) {
            f.geometry.coordinates.forEach(function(coord) {
                bounds.extend(coord);
            });
        });
        map.fitBounds(bounds, { padding: 50, maxZoom: 8 });
    }

    function clearRoutes() {
        removeRouteLayers();
        hideWaypoints();
        hideAirportMarkers();
        currentRoutes = [];
        highlightedDimId = null;
        multiSelectDimIds = [];
        updateRouteCount(0, 0);
    }

    function removeRouteLayers() {
        if (!map) return;
        for (var i = 0; i < 6; i++) {
            if (map.getLayer('routes-multi-' + i)) map.removeLayer('routes-multi-' + i);
        }
        if (map.getLayer('routes-highlight')) map.removeLayer('routes-highlight');
        if (map.getLayer('routes-lines')) map.removeLayer('routes-lines');
        if (map.getSource('routes')) map.removeSource('routes');
    }

    /**
     * Show airport markers for origin and destination airports.
     * Derives coordinates from route geometry cache (first/last waypoints).
     */
    function showAirportMarkers(routes) {
        hideAirportMarkers();
        if (!map || !mapReady || !routes || routes.length === 0) return;

        var airports = {}; // icao -> { lat, lon, type: 'origin'|'dest'|'both' }

        routes.forEach(function(route) {
            var cached = routeGeometryCache[route.route_dim_id];
            if (!cached || !cached.coordinates || cached.coordinates.length < 2) return;

            var origCoord = cached.coordinates[0];
            var destCoord = cached.coordinates[cached.coordinates.length - 1];

            if (route.origin_icao && origCoord) {
                if (!airports[route.origin_icao]) {
                    airports[route.origin_icao] = { lon: origCoord[0], lat: origCoord[1], type: 'origin' };
                } else if (airports[route.origin_icao].type === 'dest') {
                    airports[route.origin_icao].type = 'both';
                }
            }
            if (route.dest_icao && destCoord) {
                if (!airports[route.dest_icao]) {
                    airports[route.dest_icao] = { lon: destCoord[0], lat: destCoord[1], type: 'dest' };
                } else if (airports[route.dest_icao].type === 'origin') {
                    airports[route.dest_icao].type = 'both';
                }
            }
        });

        var features = [];
        Object.keys(airports).forEach(function(icao) {
            var apt = airports[icao];
            features.push({
                type: 'Feature',
                properties: { icao: icao, apt_type: apt.type },
                geometry: { type: 'Point', coordinates: [apt.lon, apt.lat] }
            });
        });

        if (features.length === 0) return;

        map.addSource('airport-markers', {
            type: 'geojson',
            data: { type: 'FeatureCollection', features: features }
        });

        // Airport dots: origin = green, dest = red, both = yellow
        map.addLayer({
            id: 'airport-marker-dots',
            type: 'circle',
            source: 'airport-markers',
            paint: {
                'circle-radius': 6,
                'circle-color': ['match', ['get', 'apt_type'],
                    'origin', '#4CAF50',
                    'dest', '#ef5350',
                    'both', '#ffee58',
                    '#fff'
                ],
                'circle-stroke-color': '#000',
                'circle-stroke-width': 2
            }
        });

        // Airport labels
        map.addLayer({
            id: 'airport-marker-labels',
            type: 'symbol',
            source: 'airport-markers',
            layout: {
                'text-field': ['get', 'icao'],
                'text-size': 12,
                'text-offset': [0, 1.4],
                'text-anchor': 'top',
                'text-font': ['Noto Sans Bold'],
                'text-allow-overlap': true
            },
            paint: {
                'text-color': '#fff',
                'text-halo-color': '#000',
                'text-halo-width': 1.5
            }
        });
    }

    function hideAirportMarkers() {
        if (!map) return;
        if (map.getLayer('airport-marker-labels')) map.removeLayer('airport-marker-labels');
        if (map.getLayer('airport-marker-dots')) map.removeLayer('airport-marker-dots');
        if (map.getSource('airport-markers')) map.removeSource('airport-markers');
    }

    // Public API
    return {
        init: init,
        plotRoutes: plotRoutes,
        highlightRoute: highlightRoute,
        clearHighlight: clearHighlight,
        highlightMultiple: highlightMultiple,
        showAirportMarkers: showAirportMarkers,
        fitToRoutes: function() { fitToRoutes(/* use existing features */ ); },
        clearRoutes: clearRoutes,
        getMap: function() { return map; }
    };
})();
