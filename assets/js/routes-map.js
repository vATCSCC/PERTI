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
    var activePopup = null; // current picker popup
    var routeEventsRegistered = false; // prevent duplicate map event handlers
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
                glyphs: '/assets/fonts/{fontstack}/{range}.pbf',
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
     * Load all boundary layers in deterministic order matching route-maplibre.js.
     * Order (bottom to top): sectors -> TRACON -> ARTCC hierarchy -> ARTCC lines/labels
     */
    function loadBoundaryLayers() {
        var sectorColor = '#555';
        var sectorWidth = 1;
        var sectorOpacity = 0.5;

        // Helper: fetch JSON, return null on failure
        function fetchJson(url) {
            return fetch(url).then(function(r) { return r.json(); }).catch(function() { return null; });
        }

        // Load all GeoJSON in parallel, add layers in deterministic order
        Promise.all([
            fetchJson('assets/geojson/superhigh.json'),  // [0]
            fetchJson('assets/geojson/high.json'),        // [1]
            fetchJson('assets/geojson/low.json'),         // [2]
            fetchJson('assets/geojson/tracon.json'),      // [3]
            fetchJson('assets/geojson/artcc_area.json'),  // [4]
            fetchJson('assets/geojson/supercenter.json'), // [5]
            fetchJson('assets/geojson/artcc.json')        // [6]
        ]).then(function(results) {
            // 1. Superhigh sectors (bottom, hidden)
            if (results[0]) {
                map.addSource('superhigh-sectors', { type: 'geojson', data: results[0] });
                map.addLayer({
                    id: 'superhigh-sector-lines', type: 'line', source: 'superhigh-sectors',
                    paint: { 'line-color': sectorColor, 'line-width': sectorWidth, 'line-opacity': sectorOpacity },
                    layout: { visibility: 'none' },
                });
            }

            // 2. High sectors (hidden)
            if (results[1]) {
                map.addSource('high-sectors', { type: 'geojson', data: results[1] });
                map.addLayer({
                    id: 'high-sector-lines', type: 'line', source: 'high-sectors',
                    paint: { 'line-color': sectorColor, 'line-width': sectorWidth, 'line-opacity': sectorOpacity },
                    layout: { visibility: 'none' },
                });
            }

            // 3. Low sectors (hidden)
            if (results[2]) {
                map.addSource('low-sectors', { type: 'geojson', data: results[2] });
                map.addLayer({
                    id: 'low-sector-lines', type: 'line', source: 'low-sectors',
                    paint: { 'line-color': sectorColor, 'line-width': sectorWidth, 'line-opacity': sectorOpacity },
                    layout: { visibility: 'none' },
                });
            }

            // 4. TRACON (hidden)
            if (results[3]) {
                map.addSource('tracon', { type: 'geojson', data: results[3] });
                map.addLayer({
                    id: 'tracon-lines', type: 'line', source: 'tracon',
                    paint: { 'line-color': '#666', 'line-width': 0.8, 'line-opacity': 0.5 },
                    layout: { visibility: 'none' },
                });
            }

            // 5. ARTCC deep sub-areas (hidden)
            if (results[4]) {
                map.addSource('artcc-area-source', { type: 'geojson', data: results[4] });
                map.addLayer({
                    id: 'artcc-deep-lines', type: 'line', source: 'artcc-area-source',
                    filter: ['>=', ['get', 'hierarchy_level'], 3],
                    paint: { 'line-color': '#1E4A7A', 'line-width': 0.8, 'line-opacity': 0.8, 'line-dasharray': [4, 3] },
                    layout: { visibility: 'none' },
                });
                map.addLayer({
                    id: 'artcc-sub-lines', type: 'line', source: 'artcc-area-source',
                    filter: ['==', ['get', 'hierarchy_level'], 2],
                    paint: { 'line-color': '#2E6AAD', 'line-width': 1.2, 'line-opacity': 0.8, 'line-dasharray': [4, 3] },
                    layout: { visibility: 'none' },
                });
            }

            // 6. ARTCC super centers (hidden)
            if (results[5]) {
                map.addSource('artcc-super-source', { type: 'geojson', data: results[5] });
                map.addLayer({
                    id: 'artcc-super-lines', type: 'line', source: 'artcc-super-source',
                    paint: { 'line-color': '#F0C946', 'line-width': 2.5, 'line-opacity': 0.8 },
                    layout: { visibility: 'none' },
                });
            }

            // 7. ARTCC boundaries + labels (top of boundary stack)
            if (results[6]) {
                map.addSource('artcc', { type: 'geojson', data: results[6] });
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
            }

            console.log('[RoutesMap] Boundary layers loaded in order');
        }).catch(function(err) {
            console.warn('[RoutesMap] Failed to load boundary layers:', err);
        });
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
        toggleBtn.innerHTML = '<i class="fas fa-layer-group"></i> ' + PERTII18n.t('routes.map.overlays');
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
            { id: 'artcc', label: PERTII18n.t('routes.map.artccBoundaries'), layers: ['artcc-lines'], checked: true },
            { id: 'artcc_labels', label: PERTII18n.t('routes.map.artccLabels'), layers: ['artcc-labels'], checked: true },
            { id: 'artcc_super', label: PERTII18n.t('routes.map.superCenters'), layers: ['artcc-super-lines'], checked: false, separator: PERTII18n.t('routes.map.artccHierarchy') },
            { id: 'artcc_sub', label: PERTII18n.t('routes.map.subAreas'), layers: ['artcc-sub-lines'], checked: false },
            { id: 'artcc_deep', label: PERTII18n.t('routes.map.deepSubAreas'), layers: ['artcc-deep-lines'], checked: false },
            { id: 'tracon', label: PERTII18n.t('routes.map.tracon'), layers: ['tracon-lines'], checked: false, separator: PERTII18n.t('routes.map.terminal') },
            { id: 'superhigh', label: PERTII18n.t('routes.map.superhighSectors'), layers: ['superhigh-sector-lines'], checked: false, separator: PERTII18n.t('routes.map.sectors') },
            { id: 'high', label: PERTII18n.t('routes.map.highSectors'), layers: ['high-sector-lines'], checked: false },
            { id: 'low', label: PERTII18n.t('routes.map.lowSectors'), layers: ['low-sector-lines'], checked: false }
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

    /**
     * Unwrap coordinates that cross the antimeridian so MapLibre
     * draws the short arc instead of wrapping around the globe.
     * Allows longitudes outside [-180,180].
     */
    function unwrapAntimeridian(coords) {
        if (coords.length < 2) return coords;
        var result = [coords[0].slice()];
        var offset = 0;
        for (var i = 1; i < coords.length; i++) {
            var prevLon = coords[i - 1][0] + offset;
            var curLon = coords[i][0] + offset;
            var diff = curLon - prevLon;
            if (diff > 180) { offset -= 360; }
            else if (diff < -180) { offset += 360; }
            result.push([coords[i][0] + offset, coords[i][1]]);
        }
        return result;
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
                coordinates: unwrapAntimeridian(geometry.coordinates)
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

        // Route lines with green -> yellow -> red ramp (green = infrequent, red = frequent)
        map.addLayer({
            id: 'routes-lines',
            type: 'line',
            source: 'routes',
            paint: {
                'line-color': ['interpolate', ['linear'], ['get', 'frequency_pct'],
                    0,  '#66bb6a',   // green (rare)
                    2,  '#a5d6a7',   // light green
                    5,  '#fff176',   // yellow
                    10, '#ffb300',   // amber
                    20, '#ff7043',   // deep orange
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

        // Click handler for route selection — registered once only
        if (!routeEventsRegistered) {
        routeEventsRegistered = true;
        map.on('click', function(e) {
            if (activePopup) { activePopup.remove(); activePopup = null; }
            if (!map.getLayer('routes-lines')) return;

            var bbox = [[e.point.x - 8, e.point.y - 8], [e.point.x + 8, e.point.y + 8]];
            var features = map.queryRenderedFeatures(bbox, { layers: ['routes-lines'] });
            if (!features || features.length === 0) return;

            // Deduplicate by dim_id
            var seen = {};
            var unique = [];
            features.forEach(function(f) {
                var id = f.properties.dim_id;
                if (!seen[id]) {
                    seen[id] = true;
                    unique.push(f.properties);
                }
            });

            if (unique.length === 1) {
                // Single route — select directly
                if (typeof window.onRouteMapClick === 'function') {
                    window.onRouteMapClick(unique[0].dim_id);
                }
                return;
            }

            // Multiple overlapping routes — show picker popup
            var options = unique.map(function(props) {
                var route = (props.normalized_route || '');
                var label = route.length > 35 ? route.substring(0, 32) + '...' : route;
                var od = (props.origin || '?') + '\u2192' + (props.dest || '?');
                return '<div class="rmap-picker-option" data-dim-id="' + props.dim_id + '"'
                    + ' style="padding:5px 8px;cursor:pointer;border-bottom:1px solid #eee;display:flex;align-items:center;gap:6px;"'
                    + ' onmouseover="this.style.background=\'#f0f0f0\'" onmouseout="this.style.background=\'white\'">'
                    + '<span style="font-size:10px;font-weight:700;color:#495057;white-space:nowrap;">' + od + '</span>'
                    + '<span style="flex:1;font-size:9px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#6c757d;" title="' + route.replace(/"/g, '&quot;') + '">' + label + '</span>'
                    + '<span style="font-size:9px;color:#868e96;">' + (props.flight_count || '') + '</span>'
                    + '</div>';
            }).join('');

            var content = '<div style="font-family:\'Inconsolata\',monospace;min-width:200px;">'
                + '<div style="font-weight:bold;font-size:10px;color:#6c757d;padding:5px 8px;border-bottom:2px solid #ddd;text-transform:uppercase;">'
                + PERTII18n.t('route.routePicker.overlappingRoutes', { count: unique.length })
                + '</div>'
                + options
                + '<div style="font-size:8px;color:#adb5bd;padding:3px 8px;text-align:center;">'
                + PERTII18n.t('route.routePicker.clickToSelect')
                + '</div></div>';

            activePopup = new maplibregl.Popup({ closeButton: true, closeOnClick: true, maxWidth: '320px' })
                .setLngLat(e.lngLat)
                .setHTML(content)
                .addTo(map);
            activePopup.on('close', function() { activePopup = null; });

            setTimeout(function() {
                document.querySelectorAll('.rmap-picker-option').forEach(function(el) {
                    el.addEventListener('click', function() {
                        var dimId = parseInt(this.dataset.dimId);
                        if (activePopup) { activePopup.remove(); activePopup = null; }
                        if (typeof window.onRouteMapClick === 'function') {
                            window.onRouteMapClick(dimId);
                        }
                    });
                });
            }, 50);
        });

        // Cursor change on hover — wide hitbox
        map.on('mousemove', function(e) {
            if (!map.getLayer('routes-lines')) { map.getCanvas().style.cursor = ''; return; }
            var bbox = [[e.point.x - 8, e.point.y - 8], [e.point.x + 8, e.point.y + 8]];
            var feats = map.queryRenderedFeatures(bbox, { layers: ['routes-lines'] });
            map.getCanvas().style.cursor = (feats && feats.length > 0) ? 'pointer' : '';
        });
        } // end routeEventsRegistered guard

        // Fit bounds to show all routes
        fitToRoutes(features);

        // Show airport markers for routes actually displayed on map
        var displayedRoutes = currentRoutes.slice(0, MAX_MAP_ROUTES);
        showAirportMarkers(displayedRoutes);
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
        // Also remove multi-select color layers
        for (var i = 0; i < 6; i++) {
            if (map.getLayer('routes-multi-' + i)) map.removeLayer('routes-multi-' + i);
        }
        multiSelectDimIds = [];
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

    function setMaxRoutes(n) {
        MAX_MAP_ROUTES = n;
    }

    // Public API
    return {
        init: init,
        plotRoutes: plotRoutes,
        setMaxRoutes: setMaxRoutes,
        highlightRoute: highlightRoute,
        clearHighlight: clearHighlight,
        highlightMultiple: highlightMultiple,
        showAirportMarkers: showAirportMarkers,
        fitToRoutes: function() { fitToRoutes(/* use existing features */ ); },
        clearRoutes: clearRoutes,
        getMap: function() { return map; }
    };
})();
