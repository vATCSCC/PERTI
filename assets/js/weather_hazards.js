/**
 * Weather Hazards Display Module for PERTI TSD Map
 * 
 * Displays SIGMET/AIRMET polygons on MapLibre GL JS map
 * 
 * Features:
 * - Real-time weather hazard polygons (SIGMET, AIRMET, Convective)
 * - Color-coded by hazard type
 * - Toggle visibility by hazard category
 * - Auto-refresh every 5 minutes
 * - Popup with hazard details on click
 * - Weather panel showing active alerts
 * 
 * @version 1.0
 * @date 2026-01-06
 */

const WeatherHazards = (function() {
    'use strict';

    // =========================================================================
    // CONFIGURATION
    // =========================================================================
    
    const CONFIG = {
        apiUrl: '/api/weather/alerts.php',
        refreshInterval: 300000,  // 5 minutes
        
        // Hazard type colors (FSM-style)
        colors: {
            CONVECTIVE: {
                fill: 'rgba(255, 0, 0, 0.25)',
                stroke: '#FF0000',
                strokeWidth: 2
            },
            TURB: {
                fill: 'rgba(255, 165, 0, 0.25)',
                stroke: '#FFA500',
                strokeWidth: 2
            },
            ICE: {
                fill: 'rgba(0, 191, 255, 0.25)',
                stroke: '#00BFFF',
                strokeWidth: 2
            },
            IFR: {
                fill: 'rgba(128, 128, 128, 0.20)',
                stroke: '#808080',
                strokeWidth: 1.5
            },
            MTN: {
                fill: 'rgba(139, 69, 19, 0.20)',
                stroke: '#8B4513',
                strokeWidth: 1.5
            },
            DEFAULT: {
                fill: 'rgba(255, 255, 0, 0.20)',
                stroke: '#FFFF00',
                strokeWidth: 1.5
            }
        },
        
        // Severity modifiers
        severityOpacity: {
            SEV: 1.0,
            MOD: 0.8,
            LGT: 0.6
        }
    };

    // =========================================================================
    // STATE
    // =========================================================================
    
    let map = null;
    let initialized = false;
    let alerts = [];
    let refreshTimer = null;
    let visibleHazards = new Set(['CONVECTIVE', 'TURB', 'ICE', 'IFR', 'MTN']);
    let onUpdateCallbacks = [];

    // Source and layer IDs
    const SOURCE_ID = 'weather-hazards-source';
    const FILL_LAYER_ID = 'weather-hazards-fill';
    const LINE_LAYER_ID = 'weather-hazards-line';
    const LABEL_LAYER_ID = 'weather-hazards-label';

    // =========================================================================
    // INITIALIZATION
    // =========================================================================
    
    /**
     * Initialize the weather hazards module
     * @param {maplibregl.Map} mapInstance - MapLibre map instance
     * @param {Object} options - Configuration options
     */
    function init(mapInstance, options = {}) {
        if (initialized) {
            console.warn('WeatherHazards: Already initialized');
            return;
        }
        
        map = mapInstance;
        
        // Merge options
        if (options.refreshInterval) {
            CONFIG.refreshInterval = options.refreshInterval;
        }
        
        // Wait for map to be ready
        if (map.loaded()) {
            setupLayers();
        } else {
            map.on('load', setupLayers);
        }
        
        initialized = true;
        console.log('WeatherHazards: Initialized');
    }

    /**
     * Setup map sources and layers
     */
    function setupLayers() {
        // Add empty GeoJSON source
        if (!map.getSource(SOURCE_ID)) {
            map.addSource(SOURCE_ID, {
                type: 'geojson',
                data: {
                    type: 'FeatureCollection',
                    features: []
                }
            });
        }
        
        // Add fill layer
        if (!map.getLayer(FILL_LAYER_ID)) {
            map.addLayer({
                id: FILL_LAYER_ID,
                type: 'fill',
                source: SOURCE_ID,
                paint: {
                    'fill-color': ['get', 'fillColor'],
                    'fill-opacity': ['get', 'fillOpacity']
                }
            });
        }
        
        // Add outline layer
        if (!map.getLayer(LINE_LAYER_ID)) {
            map.addLayer({
                id: LINE_LAYER_ID,
                type: 'line',
                source: SOURCE_ID,
                paint: {
                    'line-color': ['get', 'strokeColor'],
                    'line-width': ['get', 'strokeWidth'],
                    'line-opacity': 0.9
                }
            });
        }
        
        // Add label layer
        if (!map.getLayer(LABEL_LAYER_ID)) {
            map.addLayer({
                id: LABEL_LAYER_ID,
                type: 'symbol',
                source: SOURCE_ID,
                layout: {
                    'text-field': ['get', 'label'],
                    'text-size': 11,
                    'text-font': ['Open Sans Bold', 'Arial Unicode MS Bold'],
                    'text-anchor': 'center',
                    'text-allow-overlap': false
                },
                paint: {
                    'text-color': ['get', 'strokeColor'],
                    'text-halo-color': 'rgba(0, 0, 0, 0.8)',
                    'text-halo-width': 1.5
                }
            });
        }
        
        // Add click handler for popups
        map.on('click', FILL_LAYER_ID, handlePolygonClick);
        map.on('mouseenter', FILL_LAYER_ID, () => {
            map.getCanvas().style.cursor = 'pointer';
        });
        map.on('mouseleave', FILL_LAYER_ID, () => {
            map.getCanvas().style.cursor = '';
        });
        
        // Initial load
        refresh();
        
        // Start auto-refresh
        startAutoRefresh();
    }

    // =========================================================================
    // DATA FETCHING
    // =========================================================================
    
    /**
     * Fetch and display weather alerts
     */
    function refresh() {
        fetch(`${CONFIG.apiUrl}?format=geojson&_=${Date.now()}`)
            .then(response => {
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                return response.json();
            })
            .then(data => {
                if (data.type === 'FeatureCollection') {
                    processAlerts(data);
                } else {
                    console.error('WeatherHazards: Invalid response format');
                }
            })
            .catch(error => {
                console.error('WeatherHazards: Fetch error', error);
            });
    }

    /**
     * Process and display alerts
     */
    function processAlerts(geojson) {
        // Store raw alerts
        alerts = geojson.features.map(f => f.properties);
        
        // Add styling properties to features
        const styledFeatures = geojson.features.map(feature => {
            const props = feature.properties;
            const hazard = props.hazard || 'DEFAULT';
            const severity = props.severity || 'MOD';
            const colors = CONFIG.colors[hazard] || CONFIG.colors.DEFAULT;
            const opacityMod = CONFIG.severityOpacity[severity] || 0.8;
            
            // Parse fill color and apply opacity
            let fillOpacity = 0.25 * opacityMod;
            
            // Create label
            const label = `${props.hazard || ''} ${props.source_id || ''}`.trim();
            
            return {
                ...feature,
                properties: {
                    ...props,
                    fillColor: colors.stroke,  // Use stroke color for fill with low opacity
                    fillOpacity: fillOpacity,
                    strokeColor: colors.stroke,
                    strokeWidth: colors.strokeWidth,
                    label: label,
                    visible: visibleHazards.has(hazard)
                }
            };
        });
        
        // Filter to visible hazards
        const visibleFeatures = styledFeatures.filter(f => f.properties.visible);
        
        // Update source
        const source = map.getSource(SOURCE_ID);
        if (source) {
            source.setData({
                type: 'FeatureCollection',
                features: visibleFeatures
            });
        }
        
        // Notify listeners
        onUpdateCallbacks.forEach(cb => cb(alerts));
        
        console.log(`WeatherHazards: Updated ${visibleFeatures.length} visible alerts`);
    }

    /**
     * Handle click on weather polygon
     */
    function handlePolygonClick(e) {
        if (!e.features || e.features.length === 0) return;
        
        const props = e.features[0].properties;
        
        // Build popup content
        const content = `
            <div class="weather-hazard-popup">
                <div class="hazard-header ${props.hazard.toLowerCase()}">
                    <strong>${props.type}</strong> - ${props.hazard}
                    ${props.severity ? `<span class="severity">${props.severity}</span>` : ''}
                </div>
                <div class="hazard-details">
                    <div><strong>ID:</strong> ${props.source_id}</div>
                    <div><strong>Valid:</strong> ${formatTime(props.valid_from)} - ${formatTime(props.valid_to)}</div>
                    <div><strong>Altitude:</strong> FL${props.floor_fl || '000'} - FL${props.ceiling_fl || '---'}</div>
                    <div><strong>Expires:</strong> ${props.minutes_remaining} min</div>
                </div>
                ${props.raw_text ? `<div class="hazard-raw"><pre>${escapeHtml(props.raw_text)}</pre></div>` : ''}
            </div>
        `;
        
        new maplibregl.Popup()
            .setLngLat(e.lngLat)
            .setHTML(content)
            .addTo(map);
    }

    // =========================================================================
    // VISIBILITY CONTROLS
    // =========================================================================
    
    /**
     * Show/hide specific hazard type
     */
    function setHazardVisibility(hazard, visible) {
        if (visible) {
            visibleHazards.add(hazard);
        } else {
            visibleHazards.delete(hazard);
        }
        
        // Re-process alerts with new visibility
        if (alerts.length > 0) {
            const geojson = {
                type: 'FeatureCollection',
                features: alerts.map(a => ({
                    type: 'Feature',
                    geometry: a.geometry,
                    properties: a
                }))
            };
            processAlerts(geojson);
        }
    }
    
    /**
     * Show all hazards
     */
    function showAll() {
        visibleHazards = new Set(['CONVECTIVE', 'TURB', 'ICE', 'IFR', 'MTN']);
        refresh();
    }
    
    /**
     * Hide all hazards
     */
    function hideAll() {
        visibleHazards.clear();
        const source = map.getSource(SOURCE_ID);
        if (source) {
            source.setData({ type: 'FeatureCollection', features: [] });
        }
    }
    
    /**
     * Toggle all weather hazard layers
     */
    function toggle(visible) {
        const visibility = visible ? 'visible' : 'none';
        
        if (map.getLayer(FILL_LAYER_ID)) {
            map.setLayoutProperty(FILL_LAYER_ID, 'visibility', visibility);
        }
        if (map.getLayer(LINE_LAYER_ID)) {
            map.setLayoutProperty(LINE_LAYER_ID, 'visibility', visibility);
        }
        if (map.getLayer(LABEL_LAYER_ID)) {
            map.setLayoutProperty(LABEL_LAYER_ID, 'visibility', visibility);
        }
    }

    // =========================================================================
    // AUTO-REFRESH
    // =========================================================================
    
    function startAutoRefresh() {
        stopAutoRefresh();
        refreshTimer = setInterval(refresh, CONFIG.refreshInterval);
    }
    
    function stopAutoRefresh() {
        if (refreshTimer) {
            clearInterval(refreshTimer);
            refreshTimer = null;
        }
    }

    // =========================================================================
    // EVENT HANDLERS
    // =========================================================================
    
    /**
     * Register callback for alert updates
     */
    function onUpdate(callback) {
        if (typeof callback === 'function') {
            onUpdateCallbacks.push(callback);
        }
    }

    // =========================================================================
    // UTILITIES
    // =========================================================================
    
    function formatTime(isoString) {
        if (!isoString) return '---';
        try {
            const dt = new Date(isoString);
            return dt.toISOString().substring(11, 16) + 'Z';
        } catch {
            return isoString;
        }
    }
    
    function escapeHtml(str) {
        if (!str) return '';
        return str
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // =========================================================================
    // PUBLIC API
    // =========================================================================
    
    return {
        init: init,
        refresh: refresh,
        toggle: toggle,
        showAll: showAll,
        hideAll: hideAll,
        setHazardVisibility: setHazardVisibility,
        onUpdate: onUpdate,
        getAlerts: () => alerts,
        isInitialized: () => initialized,
        
        // Expose layer IDs for external control
        layers: {
            fill: FILL_LAYER_ID,
            line: LINE_LAYER_ID,
            label: LABEL_LAYER_ID
        }
    };
})();

// Export for module systems
if (typeof module !== 'undefined' && module.exports) {
    module.exports = WeatherHazards;
}
