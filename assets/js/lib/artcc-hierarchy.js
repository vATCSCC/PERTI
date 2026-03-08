/**
 * ARTCC/FIR Hierarchy Layer Utility for MapLibre GL maps.
 *
 * Loads and renders four hierarchy levels of ARTCC boundaries:
 *   L0 = Super Centers, L1 = FIRs/ARTCCs, L2 = Sub-Areas, L3+ = Deep Sub-Areas
 *
 * Each level gets its own line + label layers with distinct colors, widths,
 * and dash patterns, ordered bottom-to-top (deep → sub → fir → super).
 *
 * Usage:
 *   // One-step load + add:
 *   PERTIArtccHierarchy.loadAndAdd(map, { beforeLayer: 'my-layer' }).then(data => ...);
 *
 *   // Or separately:
 *   PERTIArtccHierarchy.loadData().then(data => {
 *       PERTIArtccHierarchy.addLayers(map, data, opts);
 *   });
 *
 *   // Toggle individual levels:
 *   PERTIArtccHierarchy.toggleLevel(map, 'artcc', 'super', true);
 *
 *   // Set opacity for all hierarchy layers:
 *   PERTIArtccHierarchy.setOpacity(map, 'artcc', 0.7);
 */
window.PERTIArtccHierarchy = (function() {
    'use strict';

    var DEFAULTS = {
        colors: {
            super: '#F0C946',
            fir: '#4A90D9',
            sub: '#2E6AAD',
            deep: '#1E4A7A',
        },
        lineWidths: { super: 3.5, fir: 2.5, sub: 2, deep: 1.5 },
        textSizes: {
            super: 16,
            fir: ['interpolate', ['linear'], ['zoom'], 3, 11, 5, 14, 8, 18],
            sub: 10,
            deep: 9,
        },
        dashed: { super: false, fir: false, sub: true, deep: true },
        visible: { super: false, fir: true, sub: false, deep: false },
    };

    // Iteration order: bottom → top (deep first, super last = top of z-stack)
    var LEVELS = [
        { key: 'deep', file: 'artcc_area', filterExpr: ['>=', ['get', 'hierarchy_level'], 3] },
        { key: 'sub',  file: 'artcc_area', filterExpr: ['==', ['get', 'hierarchy_level'], 2] },
        { key: 'fir',  file: 'artcc',      filterExpr: undefined },
        { key: 'super', file: 'supercenter', filterExpr: undefined },
    ];

    // MapLibre expression: strip K-prefix for US ARTCCs (KZLA → ZLA)
    var displayCodeExpr = [
        'case',
        ['all',
            ['==', ['length', ['get', 'ICAOCODE']], 4],
            ['==', ['slice', ['get', 'ICAOCODE'], 0, 1], 'K']
        ],
        ['slice', ['get', 'ICAOCODE'], 1],
        ['get', 'ICAOCODE']
    ];

    /**
     * Pick a bold font that works with the map's glyph server.
     */
    function pickFont(map) {
        try {
            var glyphs = (map.getStyle() || {}).glyphs || '';
            if (glyphs.indexOf('cartocdn') !== -1 || glyphs.indexOf('openmaptiles') !== -1) {
                return ['Open Sans Bold', 'Arial Unicode MS Bold'];
            }
        } catch (e) { /* style not ready */ }
        return ['Noto Sans Bold'];
    }

    /**
     * Load all hierarchy GeoJSON files.
     * @param {string} [basePath='assets/geojson/']
     * @returns {Promise<{artcc: Object, supercenter: Object, artcc_area: Object}>}
     */
    function loadData(basePath) {
        basePath = basePath || 'assets/geojson/';
        return Promise.all([
            fetch(basePath + 'artcc.json').then(function(r) { return r.ok ? r.json() : null; }).catch(function() { return null; }),
            fetch(basePath + 'supercenter.json').then(function(r) { return r.ok ? r.json() : null; }).catch(function() { return null; }),
            fetch(basePath + 'artcc_area.json').then(function(r) { return r.ok ? r.json() : null; }).catch(function() { return null; }),
        ]).then(function(results) {
            return {
                artcc: results[0],
                supercenter: results[1],
                artcc_area: results[2],
            };
        });
    }

    /**
     * Build source ID mapping for a given prefix.
     */
    function buildSourceMap(prefix) {
        return {
            artcc: prefix + '-source',
            supercenter: prefix + '-super-source',
            artcc_area: prefix + '-area-source',
        };
    }

    /**
     * Add hierarchy layers to a MapLibre map.
     *
     * @param {maplibregl.Map} map
     * @param {Object} data - { artcc, supercenter, artcc_area }
     * @param {Object} [opts]
     * @param {string} [opts.prefix='artcc'] - Layer ID prefix
     * @param {string} [opts.beforeLayer] - Insert layers before this layer
     * @param {Object} [opts.colors] - Override default colors { super, fir, sub, deep }
     * @param {Object} [opts.visible] - Override default visibility { super, fir, sub, deep }
     * @param {Object} [opts.lineWidths] - Override line widths per level
     * @param {boolean} [opts.fillEnabled=true] - Whether to add fill layers
     * @param {boolean} [opts.labelsEnabled=true] - Whether to add label layers
     * @param {Object} [opts.existingSources] - Map file keys to existing source IDs
     *        e.g. { artcc: 'my-artcc-source' } to reuse an existing source
     */
    function addLayers(map, data, opts) {
        opts = opts || {};
        var prefix = opts.prefix || 'artcc';
        var colors = mergeObj(DEFAULTS.colors, opts.colors);
        var visible = mergeObj(DEFAULTS.visible, opts.visible);
        var lineWidths = mergeObj(DEFAULTS.lineWidths, opts.lineWidths);
        var textSizes = mergeObj(DEFAULTS.textSizes, opts.textSizes);
        var dashed = mergeObj(DEFAULTS.dashed, opts.dashed);
        var fillEnabled = opts.fillEnabled !== false;
        var labelsEnabled = opts.labelsEnabled !== false;
        var beforeLayer = opts.beforeLayer;
        var existingSources = opts.existingSources || {};

        var sourceMap = buildSourceMap(prefix);
        var labelFont = pickFont(map);

        // Add sources (respecting existingSources overrides)
        ['artcc', 'supercenter', 'artcc_area'].forEach(function(key) {
            var sourceId = existingSources[key] || sourceMap[key];
            sourceMap[key] = sourceId; // Update map in case override was provided
            if (data[key] && !map.getSource(sourceId)) {
                map.addSource(sourceId, { type: 'geojson', data: data[key] });
            }
        });

        // Add layers bottom-to-top: deep → sub → fir → super
        LEVELS.forEach(function(level) {
            var sourceId = sourceMap[level.file];
            if (!data[level.file] || !map.getSource(sourceId)) return;

            var color = colors[level.key];
            var vis = visible[level.key] ? 'visible' : 'none';
            var width = lineWidths[level.key];
            var textSize = textSizes[level.key];
            var isDashed = dashed[level.key];
            var filter = level.filterExpr;

            var layerPrefix = prefix + '-' + level.key;

            // Fill layer
            if (fillEnabled && !map.getLayer(layerPrefix + '-fill')) {
                var fillDef = {
                    id: layerPrefix + '-fill', type: 'fill', source: sourceId,
                    paint: { 'fill-color': color, 'fill-opacity': 0 },
                    layout: { visibility: vis },
                };
                if (filter) fillDef.filter = filter;
                map.addLayer(fillDef, beforeLayer);
            }

            // Line layer
            if (!map.getLayer(layerPrefix + '-lines')) {
                var linePaint = { 'line-color': color, 'line-width': width, 'line-opacity': 0.8 };
                if (isDashed) linePaint['line-dasharray'] = [4, 3];
                var lineDef = {
                    id: layerPrefix + '-lines', type: 'line', source: sourceId,
                    paint: linePaint,
                    layout: { visibility: vis },
                };
                if (filter) lineDef.filter = filter;
                map.addLayer(lineDef, beforeLayer);
            }

            // Label layer
            if (labelsEnabled && !map.getLayer(layerPrefix + '-labels')) {
                var labelDef = {
                    id: layerPrefix + '-labels', type: 'symbol', source: sourceId,
                    layout: {
                        'text-field': displayCodeExpr, 'text-font': labelFont,
                        'text-size': textSize, 'text-allow-overlap': false,
                        'text-ignore-placement': false, 'text-padding': 5,
                        'visibility': vis,
                    },
                    paint: {
                        'text-color': color,
                        'text-halo-color': 'rgba(0, 0, 0, 0.7)',
                        'text-halo-width': 1.5,
                        'text-opacity': 0.9,
                    },
                };
                if (filter) labelDef.filter = filter;
                map.addLayer(labelDef);
            }
        });
    }

    /**
     * Get all layer IDs for a given prefix, optionally scoped to a single level.
     * @param {string} [prefix='artcc']
     * @param {string} [level] - 'super', 'fir', 'sub', or 'deep'
     * @returns {string[]}
     */
    function getLayerIds(prefix, level) {
        prefix = prefix || 'artcc';
        if (level) {
            return [
                prefix + '-' + level + '-fill',
                prefix + '-' + level + '-lines',
                prefix + '-' + level + '-labels',
            ];
        }
        var ids = [];
        LEVELS.forEach(function(l) {
            ids.push(prefix + '-' + l.key + '-fill');
            ids.push(prefix + '-' + l.key + '-lines');
            ids.push(prefix + '-' + l.key + '-labels');
        });
        return ids;
    }

    /**
     * Get all layer IDs that currently exist on the map.
     */
    function getExistingLayerIds(map, prefix) {
        return getLayerIds(prefix).filter(function(id) {
            return !!map.getLayer(id);
        });
    }

    /**
     * Toggle visibility of a specific hierarchy level.
     * @param {maplibregl.Map} map
     * @param {string} prefix - Layer ID prefix
     * @param {string} level - 'super', 'fir', 'sub', or 'deep'
     * @param {boolean} visible
     */
    function toggleLevel(map, prefix, level, visible) {
        var ids = getLayerIds(prefix, level);
        var vis = visible ? 'visible' : 'none';
        ids.forEach(function(id) {
            if (map.getLayer(id)) {
                map.setLayoutProperty(id, 'visibility', vis);
            }
        });
    }

    /**
     * Toggle all hierarchy levels on/off, respecting individual level states.
     * @param {maplibregl.Map} map
     * @param {string} prefix
     * @param {boolean} masterVisible - Master toggle state
     * @param {Object} levelStates - { super: bool, fir: bool, sub: bool, deep: bool }
     */
    function toggleAll(map, prefix, masterVisible, levelStates) {
        LEVELS.forEach(function(l) {
            var vis = masterVisible && (levelStates ? levelStates[l.key] : true);
            toggleLevel(map, prefix, l.key, vis);
        });
    }

    /**
     * Set opacity for all hierarchy layers.
     * @param {maplibregl.Map} map
     * @param {string} prefix
     * @param {number} opacity - 0.0 to 1.0
     */
    function setOpacity(map, prefix, opacity) {
        LEVELS.forEach(function(level) {
            var base = prefix + '-' + level.key;
            if (map.getLayer(base + '-fill')) {
                map.setPaintProperty(base + '-fill', 'fill-opacity', opacity * 0.1);
            }
            if (map.getLayer(base + '-lines')) {
                map.setPaintProperty(base + '-lines', 'line-opacity', opacity);
            }
            if (map.getLayer(base + '-labels')) {
                map.setPaintProperty(base + '-labels', 'text-opacity', opacity);
            }
        });
    }

    /**
     * Convenience: load data + add layers in one call.
     * @param {maplibregl.Map} map
     * @param {Object} [opts] - Same as addLayers() opts plus basePath
     * @returns {Promise<{artcc: Object, supercenter: Object, artcc_area: Object}>}
     */
    function loadAndAdd(map, opts) {
        opts = opts || {};
        return loadData(opts.basePath).then(function(data) {
            addLayers(map, data, opts);
            return data;
        });
    }

    /**
     * Merge two plain objects (shallow), with src overriding base.
     */
    function mergeObj(base, src) {
        var out = {};
        var k;
        for (k in base) { if (base.hasOwnProperty(k)) out[k] = base[k]; }
        if (src) { for (k in src) { if (src.hasOwnProperty(k)) out[k] = src[k]; } }
        return out;
    }

    return {
        DEFAULTS: DEFAULTS,
        LEVELS: LEVELS,
        displayCodeExpr: displayCodeExpr,
        loadData: loadData,
        addLayers: addLayers,
        getLayerIds: getLayerIds,
        getExistingLayerIds: getExistingLayerIds,
        toggleLevel: toggleLevel,
        toggleAll: toggleAll,
        setOpacity: setOpacity,
        loadAndAdd: loadAndAdd,
    };
})();
