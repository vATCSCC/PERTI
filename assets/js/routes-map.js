/**
 * Historical Routes - Map Module
 * MapLibre route visualization with frequency-based styling
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

    // Multi-select colors (up to 6)
    var MULTI_COLORS = ['#FF6B6B', '#4ECDC4', '#FFE66D', '#7B68EE', '#FF8C42', '#A8E6CF'];

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
            // Ensure map renders correctly after container is visible
            map.resize();
            if (pendingFeatures) {
                addRoutesToMap(pendingFeatures);
                pendingFeatures = null;
            }
        });
    }

    /**
     * Plot routes on the map. Fetches geometry from analysis.php for each route.
     * @param {Array} routes - Array of route objects from search results
     * @param {number} totalFlights - Total flights across all routes (for frequency %)
     */
    function plotRoutes(routes, totalFlights) {
        clearRoutes();
        currentRoutes = routes;

        if (!map || !routes || routes.length === 0) return;

        // Fetch geometry for top N routes (limit to 10 for performance)
        var toFetch = routes.slice(0, 10);
        var pendingCount = toFetch.length;
        var features = [];

        toFetch.forEach(function(route) {
            var dimId = route.route_dim_id;

            // Check cache
            if (routeGeometryCache[dimId]) {
                var feature = buildFeature(route, routeGeometryCache[dimId], totalFlights);
                if (feature) features.push(feature);
                pendingCount--;
                if (pendingCount === 0) addRoutesToMap(features);
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
                success: function(data) {
                    if (data.status === 'ok' && data.waypoints && data.waypoints.length >= 2) {
                        // Build GeoJSON coordinates from waypoints
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
                error: function() {
                    console.warn('[RoutesMap] Failed to fetch geometry for route', dimId);
                },
                complete: function() {
                    pendingCount--;
                    if (pendingCount === 0) addRoutesToMap(features);
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

        // Remove existing layers/sources if present
        removeMapLayers();

        var geojson = {
            type: 'FeatureCollection',
            features: features
        };

        map.addSource('routes', { type: 'geojson', data: geojson });

        // Route lines with frequency-based styling
        map.addLayer({
            id: 'routes-lines',
            type: 'line',
            source: 'routes',
            paint: {
                'line-color': ['case',
                    ['==', ['get', 'tier'], 'high'], '#00b4d8',
                    ['==', ['get', 'tier'], 'medium'], '#0077b6',
                    '#023e8a' // low
                ],
                'line-width': ['case',
                    ['==', ['get', 'tier'], 'high'], 4,
                    ['==', ['get', 'tier'], 'medium'], 2.5,
                    1.5
                ],
                'line-opacity': ['case',
                    ['==', ['get', 'tier'], 'high'], 0.9,
                    ['==', ['get', 'tier'], 'medium'], 0.6,
                    0.35
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
                // Notify routes.js of selection
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
    }

    function highlightRoute(dimId) {
        if (!map) return;
        if (!map.getSource('routes')) return; // No routes plotted yet
        highlightedDimId = dimId;

        // Add highlight layer
        if (map.getLayer('routes-highlight')) map.removeLayer('routes-highlight');

        map.addLayer({
            id: 'routes-highlight',
            type: 'line',
            source: 'routes',
            filter: ['==', ['get', 'dim_id'], dimId],
            paint: {
                'line-color': '#f59e0b', // Amber highlight
                'line-width': 5,
                'line-opacity': 1
            },
            layout: {
                'line-cap': 'round',
                'line-join': 'round'
            }
        });

        // Add waypoint markers if geometry is cached
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
        if (!map.getSource('routes')) return; // No routes plotted yet
        multiSelectDimIds = dimIds;

        // Remove any single highlight
        if (map.getLayer('routes-highlight')) map.removeLayer('routes-highlight');

        // Remove existing multi-select layers
        for (var i = 0; i < 6; i++) {
            if (map.getLayer('routes-multi-' + i)) map.removeLayer('routes-multi-' + i);
        }

        // Add colored layers for each selected route
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
                'text-font': ['Open Sans Regular']
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
        removeMapLayers();
        hideWaypoints();
        currentRoutes = [];
        highlightedDimId = null;
        multiSelectDimIds = [];
    }

    function removeMapLayers() {
        if (!map) return;
        // Remove multi-select layers
        for (var i = 0; i < 6; i++) {
            if (map.getLayer('routes-multi-' + i)) map.removeLayer('routes-multi-' + i);
        }
        if (map.getLayer('routes-highlight')) map.removeLayer('routes-highlight');
        if (map.getLayer('routes-lines')) map.removeLayer('routes-lines');
        if (map.getSource('routes')) map.removeSource('routes');
    }

    // Public API
    return {
        init: init,
        plotRoutes: plotRoutes,
        highlightRoute: highlightRoute,
        clearHighlight: clearHighlight,
        highlightMultiple: highlightMultiple,
        fitToRoutes: function() { fitToRoutes(/* use existing features */ ); },
        clearRoutes: clearRoutes,
        getMap: function() { return map; }
    };
})();
