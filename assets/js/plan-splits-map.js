/**
 * Plan Splits Map — read-only MapLibre sector map for En-Route Splits tab
 * Used by plan.php and data.php to visualize active/scheduled split configs
 * with optional ARTCC, High, Low, SuperHigh, and TRACON base layers.
 */
(function(global) {
    'use strict';

    // ── Globalized color configuration from PERTIColors.airspace ──
    var _ac = (typeof PERTIColors !== 'undefined' && PERTIColors.airspace) || {};
    var COLORS = {
        low: _ac.low || '#228B22',
        high: _ac.high || '#FF6347',
        superhigh: _ac.superhigh || '#9932CC',
        artcc: _ac.artcc || '#4682B4',
        tracon: _ac.tracon || '#20B2AA'
    };

    var map = null;
    var geoCache = { high: null, low: null, superhigh: null, artcc: null, tracon: null };
    var geoCacheLoaded = false;
    var mapReady = false;
    var pendingConfigs = null;
    var baseLayersPopulated = false;
    var configVisibility = {};  // config_id -> boolean
    var lastConfigs = null;     // last rendered configs for re-render on toggle

    // ── Base layer definitions (order = render order, bottom to top) ──
    var BASE_LAYER_DEFS = {
        artcc:     { color: COLORS.artcc,     lineWidth: 2,   fillOpacity: 0,    lineOpacity: 0.5, labelSize: 14, labelProp: 'id', font: 'Noto Sans Bold',    haloWidth: 2,   defaultVisible: true  },
        superhigh: { color: COLORS.superhigh,  lineWidth: 1,   fillOpacity: 0.15, lineOpacity: 0.5, labelSize: 10, labelProp: 'label', font: 'Noto Sans Regular', haloWidth: 1.5, defaultVisible: false },
        high:      { color: COLORS.high,       lineWidth: 1,   fillOpacity: 0.15, lineOpacity: 0.5, labelSize: 10, labelProp: 'label', font: 'Noto Sans Regular', haloWidth: 1.5, defaultVisible: false },
        low:       { color: COLORS.low,        lineWidth: 1,   fillOpacity: 0.15, lineOpacity: 0.5, labelSize: 10, labelProp: 'label', font: 'Noto Sans Regular', haloWidth: 1.5, defaultVisible: false },
        tracon:    { color: COLORS.tracon,     lineWidth: 1.5, fillOpacity: 0.1,  lineOpacity: 0.5, labelSize: 11, labelProp: 'id', font: 'Noto Sans Regular', haloWidth: 1.5, defaultVisible: false }
    };

    function init() {
        var container = document.getElementById('plan_splits_map');
        if (!container || map) return;

        container.style.display = 'block';
        var controls = document.getElementById('plan_splits_map_controls');
        if (controls) controls.style.display = 'block';

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
        // ── Base layers (rendered below active/scheduled splits) ──
        var layerOrder = ['artcc', 'superhigh', 'high', 'low', 'tracon'];
        for (var li = 0; li < layerOrder.length; li++) {
            var id = layerOrder[li];
            var def = BASE_LAYER_DEFS[id];
            var vis = def.defaultVisible ? 'visible' : 'none';

            map.addSource('base-' + id, {
                type: 'geojson',
                data: { type: 'FeatureCollection', features: [] }
            });
            map.addSource('base-' + id + '-labels', {
                type: 'geojson',
                data: { type: 'FeatureCollection', features: [] }
            });

            map.addLayer({
                id: 'base-' + id + '-fill',
                type: 'fill',
                source: 'base-' + id,
                paint: { 'fill-color': def.color, 'fill-opacity': def.fillOpacity },
                layout: { visibility: vis }
            });
            map.addLayer({
                id: 'base-' + id + '-line',
                type: 'line',
                source: 'base-' + id,
                paint: { 'line-color': def.color, 'line-width': def.lineWidth, 'line-opacity': def.lineOpacity },
                layout: { visibility: vis }
            });
            map.addLayer({
                id: 'base-' + id + '-labels',
                type: 'symbol',
                source: 'base-' + id + '-labels',
                layout: {
                    'text-field': ['get', 'label'],
                    'text-size': def.labelSize,
                    'text-font': [def.font],
                    'text-anchor': 'center',
                    'text-allow-overlap': false,
                    'visibility': vis
                },
                paint: {
                    'text-color': def.color,
                    'text-halo-color': '#000',
                    'text-halo-width': def.haloWidth
                }
            });
        }

        // ── Active sectors — solid fill + solid outline + labels ──
        map.addSource('active-sectors', {
            type: 'geojson',
            data: { type: 'FeatureCollection', features: [] }
        });
        map.addSource('active-labels', {
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
        map.addLayer({
            id: 'active-labels',
            type: 'symbol',
            source: 'active-labels',
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

        // ── Scheduled sectors — lower opacity fill + dashed outline + labels ──
        map.addSource('scheduled-sectors', {
            type: 'geojson',
            data: { type: 'FeatureCollection', features: [] }
        });
        map.addSource('scheduled-labels', {
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
        map.addLayer({
            id: 'scheduled-labels',
            type: 'symbol',
            source: 'scheduled-labels',
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
            populateBaseLayers();
            if (pendingConfigs) render(pendingConfigs);
            return;
        }
        var files = {
            high: 'assets/geojson/high.json',
            low: 'assets/geojson/low.json',
            superhigh: 'assets/geojson/superhigh.json',
            artcc: 'assets/geojson/artcc.json',
            tracon: 'assets/geojson/tracon.json'
        };
        var keys = Object.keys(files);
        var loaded = 0;
        var sectorKeys = ['high', 'low', 'superhigh'];
        var sectorLoaded = 0;
        var sectorReady = false;

        keys.forEach(function(key) {
            $.getJSON(files[key]).done(function(data) {
                geoCache[key] = data;
            }).always(function() {
                loaded++;
                if (sectorKeys.indexOf(key) !== -1) {
                    sectorLoaded++;
                    if (sectorLoaded === sectorKeys.length && !sectorReady) {
                        sectorReady = true;
                        if (pendingConfigs) render(pendingConfigs);
                    }
                }
                if (loaded === keys.length) {
                    geoCacheLoaded = true;
                    populateBaseLayers();
                    if (!sectorReady && pendingConfigs) render(pendingConfigs);
                }
            });
        });
    }

    function populateBaseLayers() {
        if (baseLayersPopulated || !mapReady || !geoCacheLoaded) return;
        baseLayersPopulated = true;

        var layerIds = ['artcc', 'superhigh', 'high', 'low', 'tracon'];
        for (var i = 0; i < layerIds.length; i++) {
            var id = layerIds[i];
            var data = geoCache[id];
            if (!data) continue;

            var src = map.getSource('base-' + id);
            if (src) src.setData(data);

            var def = BASE_LAYER_DEFS[id];
            var labelFeatures = createLabelFeatures(data, def.labelProp);
            var labelSrc = map.getSource('base-' + id + '-labels');
            if (labelSrc) labelSrc.setData({ type: 'FeatureCollection', features: labelFeatures });
        }
    }

    function createLabelFeatures(geojson, labelProp) {
        var features = [];
        if (!geojson || !geojson.features) return features;

        for (var i = 0; i < geojson.features.length; i++) {
            var f = geojson.features[i];
            var props = f.properties || {};
            var label = props[labelProp] || props.label || props.name || props.id || props.ID;
            if (!label) continue;

            var centroid = calculateCentroid(f.geometry ? f.geometry.coordinates : null);
            if (!centroid) continue;

            features.push({
                type: 'Feature',
                properties: { label: label },
                geometry: { type: 'Point', coordinates: centroid }
            });
        }
        return features;
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

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function render(configs) {
        var sectorCacheReady = geoCache.high || geoCache.low || geoCache.superhigh;
        if (!mapReady || !sectorCacheReady) {
            pendingConfigs = configs;
            return;
        }
        pendingConfigs = null;
        lastConfigs = configs;

        // Initialize visibility for new configs (default visible)
        var seenIds = {};
        (configs || []).forEach(function(cfg) {
            seenIds[cfg.id] = true;
            if (configVisibility[cfg.id] === undefined) {
                configVisibility[cfg.id] = true;
            }
        });
        // Clean up old config entries
        Object.keys(configVisibility).forEach(function(id) {
            if (!seenIds[id]) delete configVisibility[id];
        });

        var activeFeatures = [];
        var scheduledFeatures = [];
        var activeLabelGroups = {};
        var scheduledLabelGroups = {};
        var bounds = new maplibregl.LngLatBounds();
        var hasFeatures = false;

        (configs || []).forEach(function(cfg) {
            var isScheduled = cfg.status === 'scheduled';
            var targetArr = isScheduled ? scheduledFeatures : activeFeatures;
            var labelGroups = isScheduled ? scheduledLabelGroups : activeLabelGroups;
            var visible = configVisibility[cfg.id] !== false;

            (cfg.positions || []).forEach(function(pos) {
                var groupKey = cfg.id + '-' + pos.position_name;
                if (!labelGroups[groupKey]) {
                    labelGroups[groupKey] = { centroids: [], color: pos.color, name: pos.position_name, config_id: cfg.id };
                }

                (pos.sectors || []).forEach(function(sectorId) {
                    var geom = findSectorGeometry(sectorId);
                    if (!geom) return;

                    targetArr.push({
                        type: 'Feature',
                        properties: { color: pos.color, position: pos.position_name, sector_id: sectorId, config_id: cfg.id },
                        geometry: geom
                    });

                    var centroid = calculateCentroid(geom.coordinates);
                    if (centroid) {
                        labelGroups[groupKey].centroids.push(centroid);
                        if (visible) {
                            bounds.extend(centroid);
                            hasFeatures = true;
                        }
                    }
                });
            });
        });

        // Build label features per status group
        var activeLabelFeatures = buildLabelFeatures(activeLabelGroups);
        var scheduledLabelFeatures = buildLabelFeatures(scheduledLabelGroups);

        // Update map sources
        if (map.getSource('active-sectors')) {
            map.getSource('active-sectors').setData({ type: 'FeatureCollection', features: activeFeatures });
        }
        if (map.getSource('scheduled-sectors')) {
            map.getSource('scheduled-sectors').setData({ type: 'FeatureCollection', features: scheduledFeatures });
        }
        if (map.getSource('active-labels')) {
            map.getSource('active-labels').setData({ type: 'FeatureCollection', features: activeLabelFeatures });
        }
        if (map.getSource('scheduled-labels')) {
            map.getSource('scheduled-labels').setData({ type: 'FeatureCollection', features: scheduledLabelFeatures });
        }

        // Apply per-config filters
        updateConfigFilters();

        // Fit bounds to visible split features
        if (hasFeatures) {
            map.resize();
            map.fitBounds(bounds, { padding: 60, maxZoom: 8 });
        }

        // Build individual config toggles
        buildConfigToggles(configs);
    }

    function buildLabelFeatures(labelGroups) {
        var features = [];
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
            features.push({
                type: 'Feature',
                properties: { label: g.name, color: g.color, config_id: g.config_id },
                geometry: { type: 'Point', coordinates: [avgLng, avgLat] }
            });
        });
        return features;
    }

    function updateConfigFilters() {
        if (!map) return;
        var visibleIds = [];
        Object.keys(configVisibility).forEach(function(id) {
            if (configVisibility[id]) visibleIds.push(Number(id));
        });

        var filter;
        if (visibleIds.length === Object.keys(configVisibility).length) {
            // All visible — no filter needed
            filter = null;
        } else if (visibleIds.length === 0) {
            // None visible
            filter = ['==', ['get', 'config_id'], -9999];
        } else {
            filter = ['in', ['get', 'config_id'], ['literal', visibleIds]];
        }

        var layerIds = ['active-fill', 'active-line', 'active-labels',
                        'scheduled-fill', 'scheduled-line', 'scheduled-labels'];
        for (var i = 0; i < layerIds.length; i++) {
            if (map.getLayer(layerIds[i])) {
                map.setFilter(layerIds[i], filter);
            }
        }
    }

    function buildConfigToggles(configs) {
        var container = document.getElementById('plan_splits_config_toggles');
        if (!container || !configs || configs.length === 0) {
            if (container) container.innerHTML = '';
            return;
        }

        var html = '';
        configs.forEach(function(cfg) {
            var checked = configVisibility[cfg.id] !== false ? ' checked' : '';
            var label = cfg.artcc + ' \u2014 ' + cfg.config_name;
            var isScheduled = cfg.status === 'scheduled';
            var badgeClass = isScheduled ? 'badge-info' : 'badge-success';
            var cfgId = 'splits_cfg_' + cfg.id;

            html += '<div class="custom-control custom-checkbox custom-control-inline">';
            html += '<input type="checkbox" class="custom-control-input splits-config-toggle" id="' + cfgId + '" data-config-id="' + cfg.id + '"' + checked + '>';
            html += '<label class="custom-control-label" for="' + cfgId + '">';
            html += '<span class="badge ' + badgeClass + '">' + escapeHtml(label) + '</span>';
            html += '</label></div>';
        });

        container.innerHTML = html;

        // Bind change handlers
        var toggles = container.querySelectorAll('.splits-config-toggle');
        for (var i = 0; i < toggles.length; i++) {
            toggles[i].addEventListener('change', function() {
                var id = Number(this.getAttribute('data-config-id'));
                configVisibility[id] = this.checked;
                updateConfigFilters();
            });
        }
    }

    function setLayerVisible(group, visible) {
        if (!map) return;
        var layerMap = {
            active:    ['active-fill', 'active-line', 'active-labels'],
            scheduled: ['scheduled-fill', 'scheduled-line', 'scheduled-labels'],
            artcc:     ['base-artcc-fill', 'base-artcc-line', 'base-artcc-labels'],
            high:      ['base-high-fill', 'base-high-line', 'base-high-labels'],
            low:       ['base-low-fill', 'base-low-line', 'base-low-labels'],
            superhigh: ['base-superhigh-fill', 'base-superhigh-line', 'base-superhigh-labels'],
            tracon:    ['base-tracon-fill', 'base-tracon-line', 'base-tracon-labels']
        };
        var layers = layerMap[group] || [];
        var vis = visible ? 'visible' : 'none';
        for (var i = 0; i < layers.length; i++) {
            if (map.getLayer(layers[i])) {
                map.setLayoutProperty(layers[i], 'visibility', vis);
            }
        }
    }

    global.PlanSplitsMap = {
        init: init,
        render: render,
        setLayerVisible: setLayerVisible,
        COLORS: COLORS
    };

})(window);
