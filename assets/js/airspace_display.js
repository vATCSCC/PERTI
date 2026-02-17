/**
 * SUA & TFR Display Module for TSD Map
 *
 * Displays Special Use Airspace boundaries and Temporary Flight Restrictions
 * on MapLibre GL map with authentic ATC-style rendering.
 *
 * Features:
 * - SUA boundaries (MOA, Restricted, Warning, Alert, Prohibited, NSA)
 * - TFR overlay with live FAA data
 * - Filter by type, ARTCC, altitude
 * - Active/inactive status indication
 * - Click-to-info popups
 *
 * @version 1.0.0
 * @author vATCSCC PERTI
 */

const AirspaceDisplay = (function() {
    'use strict';

    // =========================================================================
    // CONFIGURATION
    // =========================================================================

    const CONFIG = {
        suaEndpoint: '/api/data/sua.php',
        tfrEndpoint: '/api/data/tfr.php',

        // Refresh intervals
        suaRefreshInterval: 3600000,  // 1 hour (SUA changes rarely)
        tfrRefreshInterval: 300000,   // 5 minutes (TFRs can change)

        // Default visibility
        defaults: {
            showSua: true,
            showTfr: true,
            suaOpacity: 0.25,
            tfrOpacity: 0.4,
            showLabels: true,
            filterTypes: null,  // null = show all
            filterArtcc: null,
            filterAltitude: null,  // { min: FL, max: FL }
        },
    };

    // SUA type styling
    const SUA_STYLES = {
        'PA': {
            name: PERTII18n.t('airspace.suaProhibited'),
            color: '#ff0000',
            fillOpacity: 0.35,
            strokeWidth: 2,
            strokeDash: null,
            priority: 0,
        },
        'RA': {
            name: PERTII18n.t('airspace.suaRestricted'),
            color: '#ff4444',
            fillOpacity: 0.25,
            strokeWidth: 2,
            strokeDash: null,
            priority: 1,
        },
        'WA': {
            name: PERTII18n.t('airspace.suaWarning'),
            color: '#ffcc00',
            fillOpacity: 0.2,
            strokeWidth: 1.5,
            strokeDash: null,
            priority: 2,
        },
        'AA': {
            name: PERTII18n.t('airspace.suaAlert'),
            color: '#ff8800',
            fillOpacity: 0.15,
            strokeWidth: 1.5,
            strokeDash: null,
            priority: 3,
        },
        'MOA': {
            name: PERTII18n.t('airspace.suaMoa'),
            color: '#ff00ff',
            fillOpacity: 0.15,
            strokeWidth: 1,
            strokeDash: [5, 3],
            priority: 4,
        },
        'NSA': {
            name: PERTII18n.t('airspace.suaNsa'),
            color: '#0066ff',
            fillOpacity: 0.2,
            strokeWidth: 1.5,
            strokeDash: null,
            priority: 5,
        },
    };

    // TFR type styling
    const TFR_STYLES = {
        'VIP': {
            name: PERTII18n.t('airspace.tfrVip'),
            color: '#cc0000',
            fillOpacity: 0.4,
            strokeWidth: 3,
            strokeDash: null,
        },
        'SECURITY': {
            name: PERTII18n.t('airspace.tfrSecurity'),
            color: '#ff0000',
            fillOpacity: 0.35,
            strokeWidth: 2.5,
            strokeDash: null,
        },
        'HAZARDS': {
            name: PERTII18n.t('airspace.tfrHazards'),
            color: '#ff6600',
            fillOpacity: 0.3,
            strokeWidth: 2,
            strokeDash: [8, 4],
        },
        'SPACE': {
            name: PERTII18n.t('airspace.tfrSpaceOps'),
            color: '#9900ff',
            fillOpacity: 0.35,
            strokeWidth: 2,
            strokeDash: null,
        },
        'STADIUM': {
            name: PERTII18n.t('airspace.tfrStadium'),
            color: '#0066ff',
            fillOpacity: 0.25,
            strokeWidth: 1.5,
            strokeDash: [5, 5],
        },
        'SPECIAL': {
            name: PERTII18n.t('airspace.tfrSpecial'),
            color: '#ff00ff',
            fillOpacity: 0.25,
            strokeWidth: 1.5,
            strokeDash: [3, 3],
        },
        'OTHER': {
            name: PERTII18n.t('airspace.tfrOther'),
            color: '#888888',
            fillOpacity: 0.2,
            strokeWidth: 1,
            strokeDash: [3, 3],
        },
    };

    // =========================================================================
    // STATE
    // =========================================================================

    const state = {
        map: null,
        suaEnabled: false,
        tfrEnabled: false,

        // Data
        suaData: null,
        tfrData: null,

        // Settings
        suaOpacity: CONFIG.defaults.suaOpacity,
        tfrOpacity: CONFIG.defaults.tfrOpacity,
        showLabels: CONFIG.defaults.showLabels,
        filterTypes: CONFIG.defaults.filterTypes,
        filterArtcc: CONFIG.defaults.filterArtcc,
        filterAltitude: CONFIG.defaults.filterAltitude,

        // Refresh timers
        suaRefreshTimer: null,
        tfrRefreshTimer: null,

        // Layer tracking
        suaLayerIds: [],
        tfrLayerIds: [],

        // Popup
        popup: null,
    };

    // =========================================================================
    // DATA LOADING
    // =========================================================================

    /**
     * Fetch SUA data
     */
    async function fetchSuaData() {
        try {
            let url = CONFIG.suaEndpoint;
            const params = [];

            if (state.filterTypes) {
                params.push(`type=${state.filterTypes.join(',')}`);
            }
            if (state.filterArtcc) {
                params.push(`artcc=${state.filterArtcc.join(',')}`);
            }

            if (params.length > 0) {
                url += '?' + params.join('&');
            }

            const response = await fetch(url);
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();
            state.suaData = data;

            console.log(`[Airspace] Loaded ${data.count || 0} SUA boundaries`);

            return data;
        } catch (error) {
            console.error('[Airspace] Failed to fetch SUA data:', error);
            return null;
        }
    }

    /**
     * Fetch TFR data
     */
    async function fetchTfrData() {
        try {
            const url = CONFIG.tfrEndpoint;
            const params = ['include_no_geometry=0'];

            if (state.filterTypes) {
                // Filter TFR types if applicable
            }

            const response = await fetch(url + '?' + params.join('&'));
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();
            state.tfrData = data;

            console.log(`[Airspace] Loaded ${data.count || 0} TFRs`);

            return data;
        } catch (error) {
            console.error('[Airspace] Failed to fetch TFR data:', error);
            return null;
        }
    }

    // =========================================================================
    // LAYER MANAGEMENT
    // =========================================================================

    /**
     * Find a suitable layer to insert airspace layers before
     * This ensures airspace renders below labels/symbols but above base layers
     */
    function findInsertionLayer() {
        if (!state.map) {return undefined;}

        const layers = state.map.getStyle().layers;

        // Priority order of layers to insert before
        const preferredLayers = [
            'aeroway-line',
            'airport-label',
            'poi-label',
            'road-label',
            'waterway-label',
            'settlement-label',
        ];

        for (const preferred of preferredLayers) {
            if (layers.find(l => l.id === preferred)) {
                return preferred;
            }
        }

        // Fallback: find first symbol layer
        const firstSymbol = layers.find(l => l.type === 'symbol');
        if (firstSymbol) {
            return firstSymbol.id;
        }

        // No suitable layer found - add on top
        return undefined;
    }

    /**
     * Add SUA layers to map
     */
    function addSuaLayers() {
        if (!state.map || !state.suaData) {return;}

        // Remove existing layers
        removeSuaLayers();

        // Add source
        state.map.addSource('sua-source', {
            type: 'geojson',
            data: state.suaData,
        });

        // Create layers for each SUA type (in reverse priority order so higher priority renders on top)
        const types = Object.keys(SUA_STYLES).reverse();

        for (const type of types) {
            const style = SUA_STYLES[type];
            const fillLayerId = `sua-fill-${type}`;
            const strokeLayerId = `sua-stroke-${type}`;

            // Find a suitable layer to insert before (or undefined to add on top)
            const beforeLayer = findInsertionLayer();

            // Fill layer
            state.map.addLayer({
                id: fillLayerId,
                type: 'fill',
                source: 'sua-source',
                filter: ['==', ['get', 'sua_type'], type],
                paint: {
                    'fill-color': style.color,
                    'fill-opacity': state.suaOpacity * (style.fillOpacity / 0.25),
                },
            }, beforeLayer);

            // Stroke layer
            const strokePaint = {
                'line-color': style.color,
                'line-width': style.strokeWidth,
                'line-opacity': 0.8,
            };

            state.map.addLayer({
                id: strokeLayerId,
                type: 'line',
                source: 'sua-source',
                filter: ['==', ['get', 'sua_type'], type],
                paint: strokePaint,
                layout: style.strokeDash ? {
                    'line-cap': 'butt',
                } : {},
            }, beforeLayer);

            // Apply dash pattern if specified
            if (style.strokeDash) {
                state.map.setPaintProperty(strokeLayerId, 'line-dasharray', style.strokeDash);
            }

            state.suaLayerIds.push(fillLayerId, strokeLayerId);
        }

        // Add label layer if enabled
        if (state.showLabels) {
            state.map.addLayer({
                id: 'sua-labels',
                type: 'symbol',
                source: 'sua-source',
                layout: {
                    'text-field': ['get', 'designator'],
                    'text-size': 10,
                    'text-anchor': 'center',
                    'text-allow-overlap': false,
                    'text-ignore-placement': false,
                },
                paint: {
                    'text-color': '#ffffff',
                    'text-halo-color': '#000000',
                    'text-halo-width': 1,
                },
                minzoom: 7,
            });

            state.suaLayerIds.push('sua-labels');
        }

        // Add click handler
        for (const layerId of state.suaLayerIds) {
            if (layerId.includes('fill')) {
                state.map.on('click', layerId, handleSuaClick);
                state.map.on('mouseenter', layerId, () => {
                    state.map.getCanvas().style.cursor = 'pointer';
                });
                state.map.on('mouseleave', layerId, () => {
                    state.map.getCanvas().style.cursor = '';
                });
            }
        }
    }

    /**
     * Add TFR layers to map
     */
    function addTfrLayers() {
        if (!state.map || !state.tfrData) {return;}

        // Remove existing layers
        removeTfrLayers();

        // Add source
        state.map.addSource('tfr-source', {
            type: 'geojson',
            data: state.tfrData,
        });

        // Fill layer
        state.map.addLayer({
            id: 'tfr-fill',
            type: 'fill',
            source: 'tfr-source',
            paint: {
                'fill-color': ['get', 'color'],
                'fill-opacity': state.tfrOpacity,
            },
        }, 'aeroway-line');

        // Stroke layer (animated dash for emphasis)
        state.map.addLayer({
            id: 'tfr-stroke',
            type: 'line',
            source: 'tfr-source',
            paint: {
                'line-color': ['get', 'color'],
                'line-width': 2.5,
                'line-opacity': 0.9,
            },
        }, 'aeroway-line');

        // Label layer
        if (state.showLabels) {
            state.map.addLayer({
                id: 'tfr-labels',
                type: 'symbol',
                source: 'tfr-source',
                layout: {
                    'text-field': ['concat', 'TFR\n', ['get', 'notam']],
                    'text-size': 11,
                    'text-anchor': 'center',
                    'text-allow-overlap': false,
                    'text-font': ['Open Sans Bold', 'Arial Unicode MS Bold'],
                },
                paint: {
                    'text-color': '#ffffff',
                    'text-halo-color': '#cc0000',
                    'text-halo-width': 2,
                },
                minzoom: 6,
            });

            state.tfrLayerIds.push('tfr-labels');
        }

        state.tfrLayerIds.push('tfr-fill', 'tfr-stroke');

        // Add click handler
        state.map.on('click', 'tfr-fill', handleTfrClick);
        state.map.on('mouseenter', 'tfr-fill', () => {
            state.map.getCanvas().style.cursor = 'pointer';
        });
        state.map.on('mouseleave', 'tfr-fill', () => {
            state.map.getCanvas().style.cursor = '';
        });
    }

    /**
     * Remove SUA layers
     */
    function removeSuaLayers() {
        if (!state.map) {return;}

        for (const layerId of state.suaLayerIds) {
            if (state.map.getLayer(layerId)) {
                state.map.removeLayer(layerId);
            }
        }

        if (state.map.getSource('sua-source')) {
            state.map.removeSource('sua-source');
        }

        state.suaLayerIds = [];
    }

    /**
     * Remove TFR layers
     */
    function removeTfrLayers() {
        if (!state.map) {return;}

        for (const layerId of state.tfrLayerIds) {
            if (state.map.getLayer(layerId)) {
                state.map.removeLayer(layerId);
            }
        }

        if (state.map.getSource('tfr-source')) {
            state.map.removeSource('tfr-source');
        }

        state.tfrLayerIds = [];
    }

    // =========================================================================
    // INTERACTION HANDLERS
    // =========================================================================

    /**
     * Handle SUA feature click
     */
    function handleSuaClick(e) {
        if (!e.features || e.features.length === 0) {return;}

        const feature = e.features[0];
        const props = feature.properties;
        const coords = e.lngLat;

        const style = SUA_STYLES[props.sua_type] || {};

        // Build popup content
        const t = PERTII18n.t;
        const html = `
            <div class="airspace-popup sua-popup">
                <div class="popup-header" style="background: ${style.color}">
                    <strong>${props.designator}</strong>
                    <span class="popup-type">${style.name || props.sua_type}</span>
                </div>
                <div class="popup-body">
                    <div class="popup-row">
                        <span class="popup-label">${t('airspace.popupName')}:</span>
                        <span>${props.name || props.designator}</span>
                    </div>
                    <div class="popup-row">
                        <span class="popup-label">${t('airspace.popupAltitude')}:</span>
                        <span>${formatAltitude(props.lower_limit)} - ${formatAltitude(props.upper_limit)}</span>
                    </div>
                    <div class="popup-row">
                        <span class="popup-label">${t('airspace.popupSchedule')}:</span>
                        <span>${props.schedule_desc || props.schedule || t('common.unknown')}</span>
                    </div>
                    <div class="popup-row">
                        <span class="popup-label">${t('airspace.popupArtcc')}:</span>
                        <span>${props.artcc || t('common.unknown')}</span>
                    </div>
                </div>
            </div>
        `;

        showPopup(coords, html);
    }

    /**
     * Handle TFR feature click
     */
    function handleTfrClick(e) {
        if (!e.features || e.features.length === 0) {return;}

        const feature = e.features[0];
        const props = feature.properties;
        const coords = e.lngLat;

        const style = TFR_STYLES[props.type] || TFR_STYLES.OTHER;

        // Build popup content
        const t = PERTII18n.t;
        const html = `
            <div class="airspace-popup tfr-popup">
                <div class="popup-header" style="background: ${props.color || style.color}">
                    <strong>TFR ${props.notam}</strong>
                    <span class="popup-type">${style.name}</span>
                </div>
                <div class="popup-body">
                    <div class="popup-row">
                        <span class="popup-label">${t('airspace.popupFacility')}:</span>
                        <span>${props.facility || t('common.na')}</span>
                    </div>
                    <div class="popup-row">
                        <span class="popup-label">${t('airspace.popupState')}:</span>
                        <span>${props.state || t('common.na')}</span>
                    </div>
                    <div class="popup-row">
                        <span class="popup-label">${t('airspace.popupEffective')}:</span>
                        <span>${props.effective || t('common.na')}</span>
                    </div>
                    <div class="popup-row">
                        <span class="popup-label">${t('airspace.popupExpires')}:</span>
                        <span>${props.expire || t('common.na')}</span>
                    </div>
                    ${props.description ? `
                    <div class="popup-desc">
                        ${props.description.substring(0, 200)}${props.description.length > 200 ? '...' : ''}
                    </div>
                    ` : ''}
                    <div class="popup-link">
                        <a href="https://tfr.faa.gov/tfr2/list.html" target="_blank">${t('airspace.viewOnFaaTfr')}</a>
                    </div>
                </div>
            </div>
        `;

        showPopup(coords, html);
    }

    /**
     * Show popup at coordinates
     */
    function showPopup(lngLat, html) {
        // Close existing popup
        if (state.popup) {
            state.popup.remove();
        }

        state.popup = new maplibregl.Popup({
            closeButton: true,
            closeOnClick: true,
            maxWidth: '300px',
        })
            .setLngLat(lngLat)
            .setHTML(html)
            .addTo(state.map);
    }

    /**
     * Format altitude for display
     */
    function formatAltitude(alt) {
        if (alt === 'GND' || alt === 'SFC' || alt === 0) {return PERTII18n.t('airspace.surface');}
        if (alt === 'UNL' || alt === 'UNLIM') {return PERTII18n.t('airspace.unlimited');}
        if (typeof alt === 'number') {
            if (alt >= 18000) {
                return `FL${Math.round(alt / 100)}`;
            }
            return `${alt.toLocaleString()} ft`;
        }
        return alt;
    }

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    /**
     * Initialize airspace display module
     */
    function init(map, options = {}) {
        state.map = map;

        // Apply options
        if (typeof options.suaOpacity === 'number') {
            state.suaOpacity = options.suaOpacity;
        }
        if (typeof options.tfrOpacity === 'number') {
            state.tfrOpacity = options.tfrOpacity;
        }
        if (typeof options.showLabels === 'boolean') {
            state.showLabels = options.showLabels;
        }
        if (options.filterTypes) {
            state.filterTypes = options.filterTypes;
        }
        if (options.filterArtcc) {
            state.filterArtcc = options.filterArtcc;
        }

        console.log('[Airspace] Module initialized');

        return AirspaceDisplay;
    }

    /**
     * Enable SUA display
     */
    async function enableSua() {
        if (state.suaEnabled) {return;}

        state.suaEnabled = true;

        // Fetch data if not loaded
        if (!state.suaData) {
            await fetchSuaData();
        }

        addSuaLayers();

        // Start refresh timer
        state.suaRefreshTimer = setInterval(async () => {
            await fetchSuaData();
            if (state.suaEnabled && state.map) {
                // Update source data
                const source = state.map.getSource('sua-source');
                if (source) {
                    source.setData(state.suaData);
                }
            }
        }, CONFIG.suaRefreshInterval);

        document.dispatchEvent(new CustomEvent('sua-enabled'));
    }

    /**
     * Disable SUA display
     */
    function disableSua() {
        if (!state.suaEnabled) {return;}

        state.suaEnabled = false;
        removeSuaLayers();

        if (state.suaRefreshTimer) {
            clearInterval(state.suaRefreshTimer);
            state.suaRefreshTimer = null;
        }

        document.dispatchEvent(new CustomEvent('sua-disabled'));
    }

    /**
     * Toggle SUA display
     */
    async function toggleSua() {
        if (state.suaEnabled) {
            disableSua();
        } else {
            await enableSua();
        }
        return state.suaEnabled;
    }

    /**
     * Enable TFR display
     */
    async function enableTfr() {
        if (state.tfrEnabled) {return;}

        state.tfrEnabled = true;

        // Fetch data if not loaded
        if (!state.tfrData) {
            await fetchTfrData();
        }

        addTfrLayers();

        // Start refresh timer
        state.tfrRefreshTimer = setInterval(async () => {
            await fetchTfrData();
            if (state.tfrEnabled && state.map) {
                const source = state.map.getSource('tfr-source');
                if (source) {
                    source.setData(state.tfrData);
                }
            }
        }, CONFIG.tfrRefreshInterval);

        document.dispatchEvent(new CustomEvent('tfr-enabled'));
    }

    /**
     * Disable TFR display
     */
    function disableTfr() {
        if (!state.tfrEnabled) {return;}

        state.tfrEnabled = false;
        removeTfrLayers();

        if (state.tfrRefreshTimer) {
            clearInterval(state.tfrRefreshTimer);
            state.tfrRefreshTimer = null;
        }

        document.dispatchEvent(new CustomEvent('tfr-disabled'));
    }

    /**
     * Toggle TFR display
     */
    async function toggleTfr() {
        if (state.tfrEnabled) {
            disableTfr();
        } else {
            await enableTfr();
        }
        return state.tfrEnabled;
    }

    /**
     * Set SUA opacity
     */
    function setSuaOpacity(opacity) {
        state.suaOpacity = Math.max(0, Math.min(1, opacity));

        if (state.map) {
            for (const layerId of state.suaLayerIds) {
                if (layerId.includes('fill') && state.map.getLayer(layerId)) {
                    // Get base opacity for this type
                    const type = layerId.replace('sua-fill-', '');
                    const style = SUA_STYLES[type];
                    if (style) {
                        state.map.setPaintProperty(
                            layerId,
                            'fill-opacity',
                            state.suaOpacity * (style.fillOpacity / 0.25),
                        );
                    }
                }
            }
        }
    }

    /**
     * Set TFR opacity
     */
    function setTfrOpacity(opacity) {
        state.tfrOpacity = Math.max(0, Math.min(1, opacity));

        if (state.map && state.map.getLayer('tfr-fill')) {
            state.map.setPaintProperty('tfr-fill', 'fill-opacity', state.tfrOpacity);
        }
    }

    /**
     * Filter SUA by types
     */
    async function filterByType(types) {
        state.filterTypes = types ? (Array.isArray(types) ? types : [types]) : null;

        if (state.suaEnabled) {
            await fetchSuaData();
            removeSuaLayers();
            addSuaLayers();
        }
    }

    /**
     * Filter SUA by ARTCC
     */
    async function filterByArtcc(artccs) {
        state.filterArtcc = artccs ? (Array.isArray(artccs) ? artccs : [artccs]) : null;

        if (state.suaEnabled) {
            await fetchSuaData();
            removeSuaLayers();
            addSuaLayers();
        }
    }

    /**
     * Refresh all data
     */
    async function refresh() {
        const promises = [];

        if (state.suaEnabled) {
            promises.push(fetchSuaData().then(() => {
                const source = state.map?.getSource('sua-source');
                if (source) {source.setData(state.suaData);}
            }));
        }

        if (state.tfrEnabled) {
            promises.push(fetchTfrData().then(() => {
                const source = state.map?.getSource('tfr-source');
                if (source) {source.setData(state.tfrData);}
            }));
        }

        await Promise.all(promises);

        document.dispatchEvent(new CustomEvent('airspace-refreshed'));
    }

    /**
     * Get current state
     */
    function getState() {
        return {
            suaEnabled: state.suaEnabled,
            tfrEnabled: state.tfrEnabled,
            suaOpacity: state.suaOpacity,
            tfrOpacity: state.tfrOpacity,
            suaCount: state.suaData?.count || 0,
            tfrCount: state.tfrData?.count || 0,
            filterTypes: state.filterTypes,
            filterArtcc: state.filterArtcc,
        };
    }

    /**
     * Get SUA type definitions
     */
    function getSuaTypes() {
        return { ...SUA_STYLES };
    }

    /**
     * Get TFR type definitions
     */
    function getTfrTypes() {
        return { ...TFR_STYLES };
    }

    // =========================================================================
    // EXPORT
    // =========================================================================

    return {
        init,

        // SUA controls
        enableSua,
        disableSua,
        toggleSua,
        setSuaOpacity,

        // TFR controls
        enableTfr,
        disableTfr,
        toggleTfr,
        setTfrOpacity,

        // Filters
        filterByType,
        filterByArtcc,

        // Data
        refresh,
        getState,
        getSuaTypes,
        getTfrTypes,

        // Constants
        SUA_STYLES,
        TFR_STYLES,
    };

})();

// Export for module systems
if (typeof module !== 'undefined' && module.exports) {
    module.exports = AirspaceDisplay;
}
