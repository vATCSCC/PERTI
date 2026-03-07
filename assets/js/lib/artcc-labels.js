/**
 * ARTCC/FIR Label Utility for MapLibre GL maps.
 *
 * Adds a symbol layer on an existing ARTCC polygon source to render
 * facility code labels at each polygon centroid (MapLibre native placement).
 *
 * Usage:
 *   PERTIArtccLabels.addToMap(map, { source: 'artcc', visible: false });
 *   PERTIArtccLabels.toggle(map, true);
 *
 * Or load artcc.json + add source + labels in one call:
 *   PERTIArtccLabels.loadAndAdd(map, { visible: true });
 */
window.PERTIArtccLabels = (function() {
    'use strict';

    var LAYER_ID = 'artcc-labels';
    var DEFAULT_SOURCE = 'artcc-label-src';

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
     * MapLibre expression: strips K-prefix for US ARTCCs (KZLA→ZLA),
     * passes through all other ICAO codes unchanged.
     */
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
     * Add ARTCC label symbol layer on an existing polygon source.
     * @param {maplibregl.Map} map
     * @param {Object} [opts]
     * @param {string} [opts.source] - Source ID of the ARTCC polygon data
     * @param {boolean} [opts.visible=false]
     */
    function addToMap(map, opts) {
        opts = opts || {};
        var sourceId = opts.source || DEFAULT_SOURCE;
        var visible = opts.visible === true;

        if (map.getLayer(LAYER_ID)) return;
        if (!map.getSource(sourceId)) return;

        map.addLayer({
            id: LAYER_ID,
            type: 'symbol',
            source: sourceId,
            filter: ['any',
                ['==', ['get', 'hierarchy_level'], 1],
                ['!', ['has', 'hierarchy_level']]
            ],
            layout: {
                'text-field': displayCodeExpr,
                'text-font': opts.font || pickFont(map),
                'text-size': ['interpolate', ['linear'], ['zoom'], 3, 11, 5, 14, 8, 18],
                'text-allow-overlap': false,
                'text-ignore-placement': false,
                'text-padding': 5,
                'visibility': visible ? 'visible' : 'none',
            },
            paint: {
                'text-color': '#b0b0b0',
                'text-halo-color': 'rgba(0, 0, 0, 0.8)',
                'text-halo-width': 1.5,
                'text-opacity': 0.7,
            },
        });
    }

    /**
     * Toggle ARTCC label visibility.
     */
    function toggle(map, visible) {
        if (map.getLayer(LAYER_ID)) {
            map.setLayoutProperty(LAYER_ID, 'visibility', visible ? 'visible' : 'none');
        }
    }

    /**
     * Fetch artcc.json, add as source if needed, then add label layer.
     * For pages that don't already load ARTCC boundary data.
     */
    function loadAndAdd(map, opts) {
        opts = opts || {};
        var sourceId = opts.source || DEFAULT_SOURCE;

        if (map.getSource(sourceId)) {
            addToMap(map, opts);
            return;
        }

        fetch('assets/geojson/artcc.json')
            .then(function(r) { return r.ok ? r.json() : null; })
            .then(function(data) {
                if (!data) return;
                if (!map.getSource(sourceId)) {
                    map.addSource(sourceId, { type: 'geojson', data: data });
                }
                addToMap(map, { source: sourceId, visible: opts.visible, font: opts.font });
            })
            .catch(function(err) {
                console.warn('[ARTCC-LABELS] Failed to load artcc.json:', err);
            });
    }

    return {
        LAYER_ID: LAYER_ID,
        addToMap: addToMap,
        toggle: toggle,
        loadAndAdd: loadAndAdd,
    };
})();
