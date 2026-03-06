/**
 * ARTCC/FIR Label Utility for MapLibre GL maps.
 *
 * Adds ARTCC/FIR code labels centered on each boundary polygon.
 * Uses label_lat/label_lon from the artcc.json GeoJSON properties.
 *
 * Usage:
 *   PERTIArtccLabels.addToMap(map, artccGeoJsonData, { visible: false });
 *   PERTIArtccLabels.toggle(map, true);  // show
 *   PERTIArtccLabels.toggle(map, false); // hide
 *
 * Or load + add in one call (fetches artcc.json if data not available):
 *   PERTIArtccLabels.loadAndAdd(map, { visible: true });
 */
window.PERTIArtccLabels = (function() {
    'use strict';

    const SOURCE_ID = 'artcc-label-points';
    const LAYER_ID  = 'artcc-labels';

    /**
     * Pick a bold font that works with the map's glyph server.
     * CartoDB → Open Sans Bold; demotiles/protomaps → Noto Sans Bold.
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
     * Build a point FeatureCollection from artcc polygon features.
     * Deduplicates by ICAOCODE, strips K-prefix for US ARTCCs.
     */
    function buildLabelPoints(artccData) {
        const seen = {};
        const features = [];
        (artccData.features || []).forEach(function(f) {
            var props = f.properties || {};
            var code = props.ICAOCODE || '';
            var lat  = props.label_lat;
            var lon  = props.label_lon;
            if (!code || lat == null || lon == null || seen[code]) return;
            seen[code] = true;
            // Display code: strip K-prefix for US ARTCCs (KZLA→ZLA)
            var displayCode = code;
            if (code.length === 4 && code.charAt(0) === 'K') {
                displayCode = code.substring(1);
            }
            features.push({
                type: 'Feature',
                geometry: { type: 'Point', coordinates: [lon, lat] },
                properties: { code: code, displayCode: displayCode },
            });
        });
        return { type: 'FeatureCollection', features: features };
    }

    /**
     * Add ARTCC label source + layer to a MapLibre map.
     * @param {maplibregl.Map} map
     * @param {Object} artccGeoJsonData - Parsed artcc.json FeatureCollection
     * @param {Object} [opts]
     * @param {boolean} [opts.visible=false] - Initial visibility
     */
    function addToMap(map, artccGeoJsonData, opts) {
        opts = opts || {};
        var visible = opts.visible === true;

        if (map.getSource(SOURCE_ID)) return; // Already added

        var labelData = buildLabelPoints(artccGeoJsonData);

        map.addSource(SOURCE_ID, { type: 'geojson', data: labelData });
        map.addLayer({
            id: LAYER_ID,
            type: 'symbol',
            source: SOURCE_ID,
            layout: {
                'text-field': ['get', 'displayCode'],
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

        console.log('[ARTCC-LABELS] Added', labelData.features.length, 'labels to map');
    }

    /**
     * Toggle ARTCC label visibility.
     * @param {maplibregl.Map} map
     * @param {boolean} visible
     */
    function toggle(map, visible) {
        if (map.getLayer(LAYER_ID)) {
            map.setLayoutProperty(LAYER_ID, 'visibility', visible ? 'visible' : 'none');
        }
    }

    /**
     * Fetch artcc.json and add labels to map in one call.
     * @param {maplibregl.Map} map
     * @param {Object} [opts]
     * @param {boolean} [opts.visible=false]
     */
    function loadAndAdd(map, opts) {
        fetch('assets/geojson/artcc.json')
            .then(function(r) { return r.ok ? r.json() : null; })
            .then(function(data) {
                if (data) addToMap(map, data, opts);
            })
            .catch(function(err) {
                console.warn('[ARTCC-LABELS] Failed to load artcc.json:', err);
            });
    }

    return {
        SOURCE_ID: SOURCE_ID,
        LAYER_ID: LAYER_ID,
        buildLabelPoints: buildLabelPoints,
        addToMap: addToMap,
        toggle: toggle,
        loadAndAdd: loadAndAdd,
    };
})();
