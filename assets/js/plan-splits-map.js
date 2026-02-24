/**
 * Plan Splits Map — read-only MapLibre sector map for En-Route Splits tab
 * Used by plan.php and data.php to visualize active/scheduled split configs.
 */
(function(global) {
    'use strict';

    var map = null;
    var geoCache = { high: null, low: null, superhigh: null };
    var geoCacheLoaded = false;
    var mapReady = false;
    var pendingConfigs = null;

    function init() {
        var container = document.getElementById('plan_splits_map');
        if (!container || map) return;

        container.style.display = 'block';

        map = new maplibregl.Map({
            container: 'plan_splits_map',
            style: {
                version: 8,
                glyphs: 'https://cdn.protomaps.com/fonts/pbf/{fontstack}/{range}.pbf',
                sources: {
                    'carto-dark': {
                        type: 'raster',
                        tiles: [
                            'https://a.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}.png',
                            'https://b.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}.png',
                            'https://c.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}.png'
                        ],
                        tileSize: 256,
                        attribution: '&copy; CARTO'
                    }
                },
                layers: [{ id: 'carto-dark-layer', type: 'raster', source: 'carto-dark' }]
            },
            center: [-98, 39],
            zoom: 4
        });

        map.addControl(new maplibregl.NavigationControl(), 'top-left');

        map.on('load', function() {
            addLayers();
            mapReady = true;
            loadGeoJson();
        });
    }

    function addLayers() {
        // Active sectors — solid fill + solid outline
        map.addSource('active-sectors', {
            type: 'geojson',
            data: { type: 'FeatureCollection', features: [] }
        });
        map.addLayer({
            id: 'active-fill',
            type: 'fill',
            source: 'active-sectors',
            paint: { 'fill-color': ['get', 'color'], 'fill-opacity': 0.4 }
        });
        map.addLayer({
            id: 'active-line',
            type: 'line',
            source: 'active-sectors',
            paint: { 'line-color': ['get', 'color'], 'line-width': 2, 'line-opacity': 0.9 }
        });

        // Scheduled sectors — lower opacity fill + dashed outline
        map.addSource('scheduled-sectors', {
            type: 'geojson',
            data: { type: 'FeatureCollection', features: [] }
        });
        map.addLayer({
            id: 'scheduled-fill',
            type: 'fill',
            source: 'scheduled-sectors',
            paint: { 'fill-color': ['get', 'color'], 'fill-opacity': 0.2 }
        });
        map.addLayer({
            id: 'scheduled-line',
            type: 'line',
            source: 'scheduled-sectors',
            paint: {
                'line-color': ['get', 'color'],
                'line-width': 1.5,
                'line-opacity': 0.7,
                'line-dasharray': [6, 4]
            }
        });

        // Position labels at centroid
        map.addSource('sector-labels', {
            type: 'geojson',
            data: { type: 'FeatureCollection', features: [] }
        });
        map.addLayer({
            id: 'sector-labels',
            type: 'symbol',
            source: 'sector-labels',
            layout: {
                'text-field': ['get', 'label'],
                'text-size': 12,
                'text-font': ['Noto Sans Bold'],
                'text-anchor': 'center',
                'text-allow-overlap': true
            },
            paint: {
                'text-color': '#ffffff',
                'text-halo-color': ['get', 'color'],
                'text-halo-width': 3
            }
        });
    }

    function loadGeoJson() {
        if (geoCacheLoaded) {
            if (pendingConfigs) render(pendingConfigs);
            return;
        }
        var files = { high: 'assets/geojson/high.json', low: 'assets/geojson/low.json', superhigh: 'assets/geojson/superhigh.json' };
        var keys = Object.keys(files);
        var loaded = 0;
        keys.forEach(function(key) {
            $.getJSON(files[key]).done(function(data) {
                geoCache[key] = data;
            }).always(function() {
                loaded++;
                if (loaded === keys.length) {
                    geoCacheLoaded = true;
                    if (pendingConfigs) render(pendingConfigs);
                }
            });
        });
    }

    function findSectorGeometry(sectorId) {
        var upper = sectorId.toUpperCase();
        var types = ['high', 'low', 'superhigh'];
        for (var t = 0; t < types.length; t++) {
            var data = geoCache[types[t]];
            if (!data || !data.features) continue;
            for (var i = 0; i < data.features.length; i++) {
                var f = data.features[i];
                var p = f.properties || {};
                var label = (p.label || '').toUpperCase();
                var name = (p.name || '').toUpperCase();
                var id = (p.id || '').toUpperCase();
                var artcc = (p.artcc || '').toUpperCase();
                var sectorNum = p.sector;
                if (label === upper || name === upper || id === upper ||
                    (artcc && sectorNum && (artcc + sectorNum).toUpperCase() === upper)) {
                    return f.geometry;
                }
            }
        }
        return null;
    }

    function calculateCentroid(coordinates) {
        if (!coordinates || !coordinates.length) return null;
        var ring = Array.isArray(coordinates[0][0]) ? coordinates[0] : coordinates;
        var minX = Infinity, maxX = -Infinity, minY = Infinity, maxY = -Infinity;
        for (var i = 0; i < ring.length; i++) {
            var c = ring[i];
            if (Array.isArray(c) && c.length >= 2) {
                if (c[0] < minX) minX = c[0];
                if (c[0] > maxX) maxX = c[0];
                if (c[1] < minY) minY = c[1];
                if (c[1] > maxY) maxY = c[1];
            }
        }
        if (!isFinite(minX)) return null;
        return [(minX + maxX) / 2, (minY + maxY) / 2];
    }

    function render(configs) {
        if (!mapReady || !geoCacheLoaded) {
            pendingConfigs = configs;
            return;
        }
        pendingConfigs = null;

        var activeFeatures = [];
        var scheduledFeatures = [];
        var labelGroups = {}; // key: configId-posName -> { centroids, color, name }
        var bounds = new maplibregl.LngLatBounds();
        var hasFeatures = false;

        (configs || []).forEach(function(cfg) {
            var isScheduled = cfg.status === 'scheduled';
            var targetArr = isScheduled ? scheduledFeatures : activeFeatures;

            (cfg.positions || []).forEach(function(pos) {
                var groupKey = cfg.id + '-' + pos.position_name;
                if (!labelGroups[groupKey]) {
                    labelGroups[groupKey] = { centroids: [], color: pos.color, name: pos.position_name };
                }

                (pos.sectors || []).forEach(function(sectorId) {
                    var geom = findSectorGeometry(sectorId);
                    if (!geom) return;

                    targetArr.push({
                        type: 'Feature',
                        properties: { color: pos.color, position: pos.position_name, sector_id: sectorId },
                        geometry: geom
                    });

                    var centroid = calculateCentroid(geom.coordinates);
                    if (centroid) {
                        labelGroups[groupKey].centroids.push(centroid);
                        bounds.extend(centroid);
                        hasFeatures = true;
                    }
                });
            });
        });

        // Build label features — one per position group
        var labelFeatures = [];
        Object.keys(labelGroups).forEach(function(key) {
            var g = labelGroups[key];
            if (g.centroids.length === 0) return;
            var avgLng = 0, avgLat = 0;
            for (var i = 0; i < g.centroids.length; i++) {
                avgLng += g.centroids[i][0];
                avgLat += g.centroids[i][1];
            }
            avgLng /= g.centroids.length;
            avgLat /= g.centroids.length;
            labelFeatures.push({
                type: 'Feature',
                properties: { label: g.name, color: g.color },
                geometry: { type: 'Point', coordinates: [avgLng, avgLat] }
            });
        });

        // Update map sources
        if (map.getSource('active-sectors')) {
            map.getSource('active-sectors').setData({ type: 'FeatureCollection', features: activeFeatures });
        }
        if (map.getSource('scheduled-sectors')) {
            map.getSource('scheduled-sectors').setData({ type: 'FeatureCollection', features: scheduledFeatures });
        }
        if (map.getSource('sector-labels')) {
            map.getSource('sector-labels').setData({ type: 'FeatureCollection', features: labelFeatures });
        }

        // Show/hide map container based on whether there are features
        var mapContainer = document.getElementById('plan_splits_map');
        var controls = document.getElementById('plan_splits_map_controls');
        if (hasFeatures) {
            if (mapContainer) mapContainer.style.display = 'block';
            if (controls) controls.style.display = 'block';
            map.resize();
            map.fitBounds(bounds, { padding: 60, maxZoom: 8 });
        } else {
            if (mapContainer) mapContainer.style.display = 'none';
            if (controls) controls.style.display = 'none';
        }
    }

    function setLayerVisible(group, visible) {
        if (!map) return;
        var layerMap = {
            active: ['active-fill', 'active-line'],
            scheduled: ['scheduled-fill', 'scheduled-line']
        };
        var layers = layerMap[group] || [];
        var vis = visible ? 'visible' : 'none';
        layers.forEach(function(layerId) {
            if (map.getLayer(layerId)) {
                map.setLayoutProperty(layerId, 'visibility', vis);
            }
        });
    }

    global.PlanSplitsMap = {
        init: init,
        render: render,
        setLayerVisible: setLayerVisible
    };

})(window);
