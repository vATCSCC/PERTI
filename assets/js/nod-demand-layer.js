/**
 * NOD Traffic Demand Visualization Layer
 *
 * Displays traffic demand on fixes and route segments like Google Maps traffic layer.
 * Users can click on the map to add monitors, view time-bucketed demand with
 * configurable thresholds, and see color-coded congestion levels.
 */

const NODDemandLayer = (function() {
    'use strict';

    // =========================================
    // Configuration
    // =========================================

    const CONFIG = {
        apiEndpoint: 'api/adl/demand/batch.php',
        refreshInterval: 30000,      // 30 seconds
        bucketMinutes: 15,
        defaultHorizonHours: 4,
        maxMonitors: 50,
        localStorageKey: 'nod_demand_monitors'
    };

    // Traffic demand colors (Google Maps style)
    const DEMAND_COLORS = {
        GREEN:  '#28a745',  // Low - free flow
        YELLOW: '#ffc107',  // Moderate - building
        ORANGE: '#fd7e14',  // High - congested
        RED:    '#dc3545',  // Critical - severe
        GRAY:   '#6c757d'   // No data
    };

    // =========================================
    // State
    // =========================================

    const state = {
        map: null,
        enabled: false,
        monitors: [],                // User's monitored points
        demandData: null,            // Last API response
        settings: {
            thresholdMode: 'percentile',  // 'percentile', 'absolute', 'custom'
            absoluteThresholds: { green: 5, yellow: 10, orange: 15 },
            customThresholds: {},         // Per-monitor overrides { monitor_id: { green, yellow, orange } }
            horizonHours: 4,
            bucketMinutes: 15,
            selectedBucket: null          // null = aggregate all, or specific bucket index
        },
        clickMode: null,             // null, 'fix', or 'segment'
        segmentFirstClick: null,     // For segment mode: { lat, lon, fix }
        refreshTimer: null,
        sourcesAdded: false,
        detailsPopup: null           // MapLibre popup for flight details
    };

    // =========================================
    // Initialization
    // =========================================

    /**
     * Initialize the demand layer with a MapLibre map instance
     */
    function init(map) {
        if (!map) {
            console.error('[DemandLayer] Map instance required');
            return;
        }

        state.map = map;

        // Load monitors from localStorage immediately, then merge with API
        loadMonitors().then(() => {
            // After loading, refresh if enabled
            if (state.enabled) {
                refresh();
            }
            renderMonitorsList();
            updateSliderRange();  // Set slider to match saved horizon

            // Sync horizon dropdown with saved setting
            const horizonSelect = document.getElementById('demand-horizon');
            if (horizonSelect) {
                horizonSelect.value = state.settings.horizonHours;
            }

            // Trigger NOD traffic layer update if FEA match mode is active
            // This ensures flights are re-colored after monitors are loaded
            if (state.monitors.length > 0) {
                updateNODColorLegend();
                // Also trigger traffic layer refresh if NOD is available
                if (typeof window.NOD !== 'undefined' && window.NOD.updateTrafficLayer) {
                    console.log('[DemandLayer] Triggering traffic layer update after monitors loaded');
                    window.NOD.updateTrafficLayer();
                }
            }
        });

        // Add sources and layers - try multiple approaches for reliability
        const tryAddSources = () => {
            if (state.sourcesAdded) return true;
            try {
                // Check if map style is ready
                if (!map.getStyle()) {
                    console.log('[DemandLayer] Map style not ready yet');
                    return false;
                }
                addSourcesAndLayers();
                return true;
            } catch (e) {
                console.error('[DemandLayer] Error adding sources:', e);
                return false;
            }
        };

        // Try immediately
        const styleLoaded = map.isStyleLoaded();
        console.log('[DemandLayer] Style loaded check:', styleLoaded);

        if (styleLoaded && map.getStyle()) {
            tryAddSources();
        }

        // Also listen for load event
        if (!state.sourcesAdded) {
            map.on('load', () => {
                console.log('[DemandLayer] map.load event fired');
                tryAddSources();
            });
        }

        // Also listen for style.load event
        if (!state.sourcesAdded) {
            map.on('style.load', () => {
                console.log('[DemandLayer] style.load event fired');
                tryAddSources();
            });
        }

        // Use idle event as another fallback
        if (!state.sourcesAdded) {
            map.once('idle', () => {
                console.log('[DemandLayer] map.idle event fired');
                tryAddSources();
            });
        }

        // Aggressive fallback: poll until sources are added
        const pollInterval = setInterval(() => {
            if (state.sourcesAdded) {
                clearInterval(pollInterval);
                return;
            }
            if (map.getStyle()) {
                console.log('[DemandLayer] Fallback poll: attempting to add sources');
                if (tryAddSources()) {
                    clearInterval(pollInterval);
                }
            }
        }, 200);

        // Clear interval after 5 seconds to prevent infinite polling
        setTimeout(() => {
            clearInterval(pollInterval);
            if (!state.sourcesAdded) {
                console.error('[DemandLayer] Failed to add sources after 5 seconds');
            }
        }, 5000);

        // Set up click handler
        map.on('click', handleMapClick);

        console.log('[DemandLayer] Initialized');
    }

    /**
     * Add MapLibre sources and layers for demand visualization
     */
    function addSourcesAndLayers() {
        console.log('[DemandLayer] addSourcesAndLayers called, sourcesAdded:', state.sourcesAdded);
        if (state.sourcesAdded) return;

        const map = state.map;
        if (!map) {
            console.error('[DemandLayer] addSourcesAndLayers: no map instance');
            return;
        }

        // Add fix monitors source (GeoJSON Points)
        map.addSource('demand-fixes-source', {
            type: 'geojson',
            data: { type: 'FeatureCollection', features: [] }
        });

        // Add segment monitors source (GeoJSON LineStrings)
        map.addSource('demand-segments-source', {
            type: 'geojson',
            data: { type: 'FeatureCollection', features: [] }
        });

        // Fix layers
        // Glow layer (outer)
        map.addLayer({
            id: 'demand-fixes-glow',
            type: 'circle',
            source: 'demand-fixes-source',
            paint: {
                'circle-radius': [
                    'interpolate', ['linear'], ['get', 'count'],
                    0, 10,
                    5, 14,
                    15, 20,
                    30, 26
                ],
                'circle-color': ['get', 'color'],
                'circle-opacity': 0.3,
                'circle-blur': 0.5
            }
        });

        // Core layer (inner)
        map.addLayer({
            id: 'demand-fixes-core',
            type: 'circle',
            source: 'demand-fixes-source',
            paint: {
                'circle-radius': [
                    'interpolate', ['linear'], ['get', 'count'],
                    0, 5,
                    5, 7,
                    15, 10,
                    30, 13
                ],
                'circle-color': ['get', 'color'],
                'circle-stroke-width': 2,
                'circle-stroke-color': '#000'
            }
        });

        // Labels layer
        map.addLayer({
            id: 'demand-fixes-labels',
            type: 'symbol',
            source: 'demand-fixes-source',
            layout: {
                'text-field': ['concat', 'FEA_', ['get', 'label'], '/', ['to-string', ['get', 'count']], 'FLTS'],
                'text-size': 11,
                'text-anchor': 'top',
                'text-offset': [0, 1.2],
                'text-allow-overlap': true
            },
            paint: {
                'text-color': '#fff',
                'text-halo-color': '#000',
                'text-halo-width': 1.5
            }
        });

        // Segment layers
        // Glow layer (wide)
        map.addLayer({
            id: 'demand-segments-glow',
            type: 'line',
            source: 'demand-segments-source',
            paint: {
                'line-color': ['get', 'color'],
                'line-width': [
                    'interpolate', ['linear'], ['get', 'count'],
                    0, 8,
                    5, 12,
                    15, 18,
                    30, 24
                ],
                'line-opacity': 0.25,
                'line-blur': 3
            }
        });

        // Core layer (narrow)
        map.addLayer({
            id: 'demand-segments-core',
            type: 'line',
            source: 'demand-segments-source',
            paint: {
                'line-color': ['get', 'color'],
                'line-width': [
                    'interpolate', ['linear'], ['get', 'count'],
                    0, 2,
                    5, 3,
                    15, 5,
                    30, 7
                ]
            }
        });

        // Segment labels
        map.addLayer({
            id: 'demand-segments-labels',
            type: 'symbol',
            source: 'demand-segments-source',
            layout: {
                'text-field': ['concat', 'FEA_', ['get', 'label'], '/', ['to-string', ['get', 'count']], 'FLTS'],
                'text-size': 10,
                'symbol-placement': 'line-center',
                'text-allow-overlap': true
            },
            paint: {
                'text-color': '#fff',
                'text-halo-color': '#000',
                'text-halo-width': 1.5
            }
        });

        // Initially hide all layers
        setLayersVisibility(false);

        state.sourcesAdded = true;
        console.log('[DemandLayer] Sources and layers added');

        // Initialize click handlers for showing flight details
        initMapClickHandlers();
    }

    // =========================================
    // Enable/Disable
    // =========================================

    /**
     * Enable the demand layer and start refreshing
     */
    function enable() {
        if (state.enabled) return;

        state.enabled = true;
        setLayersVisibility(true);

        // Start refresh timer
        refresh();
        state.refreshTimer = setInterval(refresh, CONFIG.refreshInterval);

        // Show add controls
        const addControls = document.getElementById('demand-add-controls');
        if (addControls) addControls.style.display = 'block';

        // Show demand section in toolbar
        const demandControls = document.getElementById('demandControls');
        if (demandControls) demandControls.style.display = '';

        // Render monitors list with persisted monitors
        renderMonitorsList();

        console.log('[DemandLayer] Enabled');
    }

    /**
     * Disable the demand layer and stop refreshing
     */
    function disable() {
        if (!state.enabled) return;

        state.enabled = false;
        setLayersVisibility(false);

        // Stop refresh timer
        if (state.refreshTimer) {
            clearInterval(state.refreshTimer);
            state.refreshTimer = null;
        }

        // Exit click mode if active
        if (state.clickMode) {
            toggleClickMode(null);
        }

        // Hide add controls
        const addControls = document.getElementById('demand-add-controls');
        if (addControls) addControls.style.display = 'none';

        const demandControls = document.getElementById('demandControls');
        if (demandControls) demandControls.style.display = 'none';

        console.log('[DemandLayer] Disabled');
    }

    /**
     * Set visibility of all demand layers
     */
    function setLayersVisibility(visible) {
        if (!state.map || !state.sourcesAdded) return;

        const visibility = visible ? 'visible' : 'none';
        const layers = [
            'demand-fixes-glow', 'demand-fixes-core', 'demand-fixes-labels',
            'demand-segments-glow', 'demand-segments-core', 'demand-segments-labels'
        ];

        layers.forEach(layerId => {
            if (state.map.getLayer(layerId)) {
                state.map.setLayoutProperty(layerId, 'visibility', visibility);
            }
        });
    }

    // =========================================
    // NOD Integration Helpers
    // =========================================

    /**
     * Update NOD color legend if FEA match mode is active
     * Called when monitors are added/removed to keep the legend in sync
     */
    function updateNODColorLegend() {
        if (typeof window.NOD !== 'undefined' && window.NOD.renderColorLegend) {
            window.NOD.renderColorLegend();
        }
    }

    // =========================================
    // Monitor Management
    // =========================================

    /**
     * Add a monitor to track
     */
    function addMonitor(monitor) {
        if (state.monitors.length >= CONFIG.maxMonitors) {
            console.warn('[DemandLayer] Maximum monitors reached');
            return false;
        }

        // Validate monitor based on type
        if (monitor.type === 'fix' && !monitor.fix) {
            console.error('[DemandLayer] Fix monitor missing fix name');
            return false;
        }
        if (monitor.type === 'segment' && (!monitor.from || !monitor.to)) {
            console.error('[DemandLayer] Segment monitor missing from/to');
            return false;
        }
        if (monitor.type === 'airway' && !monitor.airway) {
            console.error('[DemandLayer] Airway monitor missing airway name');
            return false;
        }
        if (monitor.type === 'airway_segment' && (!monitor.airway || !monitor.from || !monitor.to)) {
            console.error('[DemandLayer] Airway segment monitor missing airway/from/to');
            return false;
        }
        if (monitor.type === 'via_fix' && (!monitor.via || !monitor.filter)) {
            console.error('[DemandLayer] Via-fix monitor missing via or filter');
            return false;
        }

        // Check for duplicates
        const id = getMonitorId(monitor);
        if (state.monitors.some(m => getMonitorId(m) === id)) {
            console.warn('[DemandLayer] Monitor already exists:', id);
            return false;
        }

        state.monitors.push(monitor);
        saveToLocalStorage();

        // Save to global API (async, don't block)
        saveMonitorToAPI(monitor).then(() => {
            // Optionally update UI after API save
        });

        // Refresh immediately and update UI
        if (state.enabled) {
            refresh();
        }
        renderMonitorsList();

        // Update NOD color legend if showing FEA match mode
        updateNODColorLegend();

        console.log('[DemandLayer] Added monitor:', id);
        return true;
    }

    /**
     * Remove a monitor by ID
     */
    function removeMonitor(id) {
        const idx = state.monitors.findIndex(m => getMonitorId(m) === id);
        if (idx === -1) return false;

        const monitor = state.monitors[idx];
        state.monitors.splice(idx, 1);
        saveToLocalStorage();

        // Delete from global API if it was a global monitor (async, don't block)
        if (monitor._global) {
            deleteMonitorFromAPI(id);
        }

        // Refresh immediately and update UI
        if (state.enabled) {
            refresh();
        }
        renderMonitorsList();

        // Update NOD color legend if showing FEA match mode
        updateNODColorLegend();

        console.log('[DemandLayer] Removed monitor:', id);
        return true;
    }

    /**
     * Clear all monitors
     */
    function clearMonitors() {
        state.monitors = [];
        saveToLocalStorage();

        // Clear map data and update UI
        updateMapData([]);
        renderMonitorsList();

        // Update NOD color legend if showing FEA match mode
        updateNODColorLegend();

        console.log('[DemandLayer] Cleared all monitors');
    }

    /**
     * Generate monitor ID
     */
    function getMonitorId(monitor) {
        switch (monitor.type) {
            case 'fix':
                return 'fix_' + monitor.fix.toUpperCase();
            case 'segment':
                return 'segment_' + monitor.from.toUpperCase() + '_' + monitor.to.toUpperCase();
            case 'airway':
                return 'airway_' + monitor.airway.toUpperCase();
            case 'airway_segment':
                return 'airway_' + monitor.airway.toUpperCase() + '_' + monitor.from.toUpperCase() + '_' + monitor.to.toUpperCase();
            case 'via_fix':
                return 'via_' + monitor.filter.type + '_' + monitor.filter.code + '_' + monitor.filter.direction + '_' + monitor.via.toUpperCase();
            default:
                return 'unknown_' + Date.now();
        }
    }

    // =========================================
    // Data Fetching
    // =========================================

    /**
     * Fetch demand data from API
     */
    async function refresh() {
        console.log('[DemandLayer] refresh() called, enabled:', state.enabled, 'monitors:', state.monitors.length);

        if (!state.enabled || state.monitors.length === 0) {
            console.log('[DemandLayer] Skipping refresh: enabled=' + state.enabled + ', monitors=' + state.monitors.length);
            updateMapData([]);
            return;
        }

        try {
            const monitorsJson = JSON.stringify(state.monitors);
            console.log('[DemandLayer] Sending monitors to API:', monitorsJson);

            const params = new URLSearchParams({
                monitors: monitorsJson,
                bucket_minutes: state.settings.bucketMinutes,
                horizon_hours: state.settings.horizonHours
            });

            const url = `${CONFIG.apiEndpoint}?${params}`;
            console.log('[DemandLayer] API URL:', url.substring(0, 200) + '...');

            const response = await fetch(url);
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();
            console.log('[DemandLayer] API response:', JSON.stringify(data).substring(0, 500));
            state.demandData = data;

            // Log any SQL errors from API
            if (data.sql_errors && data.sql_errors.length > 0) {
                console.error('[DemandLayer] SQL errors:', data.sql_errors);
            }

            // Debug: log monitor data
            if (data.monitors && data.monitors.length > 0) {
                data.monitors.forEach((m, i) => {
                    if (m.type === 'airway_segment' || m.type === 'segment') {
                        console.log(`[DemandLayer] Monitor ${i}: id=${m.id}, type=${m.type}, from_lat=${m.from_lat}, to_lat=${m.to_lat}, geometry=${m.geometry ? m.geometry.length + ' pts' : 'none'}, total=${m.total}`);
                    } else if (m.type === 'airway') {
                        console.log(`[DemandLayer] Monitor ${i}: id=${m.id}, type=${m.type}, lat=${m.lat}, geometry=${m.geometry ? m.geometry.length + ' pts' : 'none'}, total=${m.total}`);
                    } else {
                        console.log(`[DemandLayer] Monitor ${i}: id=${m.id}, type=${m.type}, lat=${m.lat}, lon=${m.lon}, total=${m.total}`);
                    }
                });
            } else {
                console.warn('[DemandLayer] API returned 0 monitors despite sending', state.monitors.length);
            }

            // Update map visualization
            updateMapData(data.monitors || []);

            // Update UI
            updateTimeline(data);
            updateThresholdInfo();

            console.log('[DemandLayer] Refreshed:', data.monitors?.length || 0, 'monitors');
        } catch (error) {
            console.error('[DemandLayer] Refresh failed:', error);
        }
    }

    // =========================================
    // Map Visualization
    // =========================================

    /**
     * Update map sources with demand data
     */
    function updateMapData(monitors) {
        if (!state.map) {
            console.warn('[DemandLayer] updateMapData: no map instance');
            return;
        }
        if (!state.sourcesAdded) {
            console.warn('[DemandLayer] updateMapData: sources not added yet');
            return;
        }

        console.log('[DemandLayer] updateMapData called with', monitors.length, 'monitors');

        const fixFeatures = [];
        const segmentFeatures = [];

        // Collect all counts for percentile calculation
        const allCounts = monitors.map(m => getDisplayCount(m)).filter(c => c > 0);

        monitors.forEach(monitor => {
            const count = getDisplayCount(monitor);
            const color = getDemandColor(count, monitor.id, allCounts);

            // Fix monitors - render as points
            if (monitor.type === 'fix' && monitor.lat != null && monitor.lon != null) {
                fixFeatures.push({
                    type: 'Feature',
                    geometry: {
                        type: 'Point',
                        coordinates: [monitor.lon, monitor.lat]
                    },
                    properties: {
                        id: monitor.id,
                        fix: monitor.fix,
                        count: count,
                        total: monitor.total,
                        color: color,
                        label: monitor.fix
                    }
                });
            }
            // Segment monitors - render as lines
            else if (monitor.type === 'segment' && monitor.from_lat != null && monitor.to_lat != null) {
                segmentFeatures.push({
                    type: 'Feature',
                    geometry: {
                        type: 'LineString',
                        coordinates: [
                            [monitor.from_lon, monitor.from_lat],
                            [monitor.to_lon, monitor.to_lat]
                        ]
                    },
                    properties: {
                        id: monitor.id,
                        from_fix: monitor.from_fix,
                        to_fix: monitor.to_fix,
                        count: count,
                        total: monitor.total,
                        color: color,
                        label: `${monitor.from_fix}→${monitor.to_fix}`
                    }
                });
            }
            // Airway segment monitors - render as lines with full geometry if available
            else if (monitor.type === 'airway_segment' && monitor.from_lat != null && monitor.to_lat != null) {
                // Use full geometry array if available, otherwise fall back to from/to endpoints
                let coordinates;
                if (monitor.geometry && Array.isArray(monitor.geometry) && monitor.geometry.length >= 2) {
                    coordinates = monitor.geometry; // Already in [lon, lat] format
                } else {
                    coordinates = [
                        [monitor.from_lon, monitor.from_lat],
                        [monitor.to_lon, monitor.to_lat]
                    ];
                }

                segmentFeatures.push({
                    type: 'Feature',
                    geometry: {
                        type: 'LineString',
                        coordinates: coordinates
                    },
                    properties: {
                        id: monitor.id,
                        from_fix: monitor.from_fix,
                        to_fix: monitor.to_fix,
                        airway: monitor.airway,
                        count: count,
                        total: monitor.total,
                        color: color,
                        label: `${monitor.from_fix} ${monitor.airway} ${monitor.to_fix}`
                    }
                });
            }
            // Full airway monitors - render as lines if geometry available, else point
            else if (monitor.type === 'airway' && monitor.lat != null && monitor.lon != null) {
                // If full geometry is available, render as line
                if (monitor.geometry && Array.isArray(monitor.geometry) && monitor.geometry.length >= 2) {
                    segmentFeatures.push({
                        type: 'Feature',
                        geometry: {
                            type: 'LineString',
                            coordinates: monitor.geometry
                        },
                        properties: {
                            id: monitor.id,
                            airway: monitor.airway,
                            count: count,
                            total: monitor.total,
                            color: color,
                            label: monitor.airway
                        }
                    });
                } else {
                    // Fallback to point if no geometry
                    fixFeatures.push({
                        type: 'Feature',
                        geometry: {
                            type: 'Point',
                            coordinates: [monitor.lon, monitor.lat]
                        },
                        properties: {
                            id: monitor.id,
                            fix: monitor.airway,
                            count: count,
                            total: monitor.total,
                            color: color,
                            label: monitor.airway
                        }
                    });
                }
            }
            // Via-fix monitors - render as points at via location
            else if (monitor.type === 'via_fix' && monitor.lat != null && monitor.lon != null) {
                const label = monitor.filter ?
                    `${monitor.filter.code}${monitor.filter.direction === 'arr' ? '↓' : monitor.filter.direction === 'dep' ? '↑' : '↕'} via ${monitor.via}` :
                    monitor.via;
                fixFeatures.push({
                    type: 'Feature',
                    geometry: {
                        type: 'Point',
                        coordinates: [monitor.lon, monitor.lat]
                    },
                    properties: {
                        id: monitor.id,
                        fix: monitor.via,
                        count: count,
                        total: monitor.total,
                        color: color,
                        label: label
                    }
                });
            }
        });

        console.log('[DemandLayer] Built features: fixes=' + fixFeatures.length + ', segments=' + segmentFeatures.length);

        // Update sources
        const fixSource = state.map.getSource('demand-fixes-source');
        const segSource = state.map.getSource('demand-segments-source');

        if (!fixSource || !segSource) {
            console.error('[DemandLayer] Map sources not found!');
            return;
        }

        fixSource.setData({
            type: 'FeatureCollection',
            features: fixFeatures
        });

        segSource.setData({
            type: 'FeatureCollection',
            features: segmentFeatures
        });

        console.log('[DemandLayer] Map sources updated');
    }

    /**
     * Get count to display based on selected bucket
     */
    function getDisplayCount(monitor) {
        if (!monitor.counts || monitor.counts.length === 0) return 0;

        if (state.settings.selectedBucket === null) {
            // Aggregate: sum first 4 buckets (next hour)
            const numBuckets = Math.min(4, monitor.counts.length);
            let sum = 0;
            for (let i = 0; i < numBuckets; i++) {
                sum += monitor.counts[i] || 0;
            }
            return sum;
        } else {
            // Specific bucket
            return monitor.counts[state.settings.selectedBucket] || 0;
        }
    }

    // =========================================
    // Color Calculation
    // =========================================

    /**
     * Get demand color based on count and threshold mode
     */
    function getDemandColor(count, monitorId, allCounts) {
        if (count === null || count === undefined || count === 0) {
            return DEMAND_COLORS.GRAY;
        }

        let thresholds;

        switch (state.settings.thresholdMode) {
            case 'percentile':
                thresholds = calculatePercentileThresholds(allCounts);
                break;
            case 'absolute':
                thresholds = state.settings.absoluteThresholds;
                break;
            case 'custom':
                thresholds = state.settings.customThresholds[monitorId] ||
                            state.settings.absoluteThresholds;
                break;
            default:
                thresholds = { green: 5, yellow: 10, orange: 15 };
        }

        if (count <= thresholds.green) return DEMAND_COLORS.GREEN;
        if (count <= thresholds.yellow) return DEMAND_COLORS.YELLOW;
        if (count <= thresholds.orange) return DEMAND_COLORS.ORANGE;
        return DEMAND_COLORS.RED;
    }

    /**
     * Calculate percentile-based thresholds from current data
     */
    function calculatePercentileThresholds(counts) {
        if (!counts || counts.length === 0) {
            return { green: 5, yellow: 10, orange: 15 };
        }

        const sorted = [...counts].sort((a, b) => a - b);

        const p50 = sorted[Math.floor(sorted.length * 0.50)] || 1;
        const p75 = sorted[Math.floor(sorted.length * 0.75)] || p50 + 1;
        const p90 = sorted[Math.floor(sorted.length * 0.90)] || p75 + 1;

        return {
            green: p50,
            yellow: p75,
            orange: p90
        };
    }

    // =========================================
    // Click-to-Add Interaction
    // =========================================

    /**
     * Toggle click mode for adding monitors
     */
    function toggleClickMode(mode) {
        // If clicking same mode, turn off
        if (state.clickMode === mode) {
            mode = null;
        }

        state.clickMode = mode;
        state.segmentFirstClick = null;

        // Update cursor
        const canvas = state.map.getCanvas();
        canvas.style.cursor = mode ? 'crosshair' : '';

        // Update button states
        const fixBtn = document.getElementById('demand-add-fix-btn');
        const segmentBtn = document.getElementById('demand-add-segment-btn');

        if (fixBtn) fixBtn.classList.toggle('active', mode === 'fix');
        if (segmentBtn) segmentBtn.classList.toggle('active', mode === 'segment');

        // Update status message
        const status = document.getElementById('demand-click-status');
        if (status) {
            if (mode === 'fix') {
                status.textContent = 'Click on map to add fix monitor';
                status.style.display = 'block';
            } else if (mode === 'segment') {
                status.textContent = 'Click first fix for segment';
                status.style.display = 'block';
            } else {
                status.style.display = 'none';
            }
        }

        console.log('[DemandLayer] Click mode:', mode || 'off');
    }

    /**
     * Handle map click for adding monitors
     */
    async function handleMapClick(e) {
        if (!state.clickMode || !state.enabled) return;

        const { lng, lat } = e.lngLat;

        // Find nearest fix
        const fix = await findNearestFix(lat, lng);
        if (!fix) {
            console.warn('[DemandLayer] No fix found near click');
            return;
        }

        if (state.clickMode === 'fix') {
            // Add fix monitor
            addMonitor({ type: 'fix', fix: fix.name });
            toggleClickMode(null);

        } else if (state.clickMode === 'segment') {
            if (!state.segmentFirstClick) {
                // First click - store it
                state.segmentFirstClick = fix;
                const status = document.getElementById('demand-click-status');
                if (status) {
                    status.textContent = `From: ${fix.name} - Click second fix`;
                }
            } else {
                // Second click - create segment
                addMonitor({
                    type: 'segment',
                    from: state.segmentFirstClick.name,
                    to: fix.name
                });
                toggleClickMode(null);
            }
        }
    }

    /**
     * Find nearest fix to coordinates
     */
    async function findNearestFix(lat, lng) {
        try {
            // Use nav data API to find fix
            const response = await fetch(`api/simulator/navdata.php?action=nearest_fix&lat=${lat}&lon=${lng}&radius=50`);
            if (!response.ok) {
                // Fallback: prompt user for fix name
                const fixName = prompt('Enter fix name:');
                return fixName ? { name: fixName.toUpperCase() } : null;
            }
            const data = await response.json();
            if (data.fix) {
                return { name: data.fix.fix_name, lat: data.fix.lat, lon: data.fix.lon };
            }
        } catch (error) {
            console.error('[DemandLayer] Error finding fix:', error);
        }

        // Fallback: prompt
        const fixName = prompt('Enter fix name:');
        return fixName ? { name: fixName.toUpperCase() } : null;
    }

    // =========================================
    // Settings
    // =========================================

    /**
     * Set threshold mode
     */
    function setThresholdMode(mode) {
        if (!['percentile', 'absolute', 'custom'].includes(mode)) return;

        state.settings.thresholdMode = mode;
        saveToLocalStorage();

        // Update UI
        document.querySelectorAll('#demandControls [data-mode]').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.mode === mode);
        });

        // Show/hide threshold inputs
        const absoluteInputs = document.getElementById('absolute-threshold-inputs');
        const percentileInfo = document.getElementById('percentile-info');

        if (absoluteInputs) {
            absoluteInputs.style.display = (mode === 'absolute' || mode === 'custom') ? 'block' : 'none';
        }
        if (percentileInfo) {
            percentileInfo.style.display = mode === 'percentile' ? 'block' : 'none';
        }

        // Refresh visualization
        if (state.demandData) {
            updateMapData(state.demandData.monitors || []);
        }

        console.log('[DemandLayer] Threshold mode:', mode);
    }

    /**
     * Set absolute threshold value
     */
    function setAbsoluteThreshold(level, value) {
        const val = parseInt(value, 10);
        if (isNaN(val) || val < 0) return;

        state.settings.absoluteThresholds[level] = val;
        saveToLocalStorage();

        // Refresh visualization
        if (state.demandData) {
            updateMapData(state.demandData.monitors || []);
        }
    }

    /**
     * Set projection horizon
     */
    function setHorizonHours(hours) {
        hours = Math.max(1, Math.min(12, hours));
        state.settings.horizonHours = hours;
        saveToLocalStorage();

        // Update slider max and end label
        updateSliderRange();

        // Reset bucket selection if current selection is beyond new range
        const maxBuckets = Math.floor((hours * 60) / state.settings.bucketMinutes);
        if (state.settings.selectedBucket !== null && state.settings.selectedBucket >= maxBuckets) {
            state.settings.selectedBucket = null;
        }

        // Refresh immediately with new horizon
        if (state.enabled) {
            refresh();
        }

        console.log('[DemandLayer] Horizon hours:', hours);
    }

    /**
     * Update slider range based on current horizon and bucket settings
     */
    function updateSliderRange() {
        const slider = document.getElementById('demand-time-slider');
        const endLabel = document.getElementById('demand-slider-end');

        if (!slider) return;

        // Calculate max buckets: (hours * 60 minutes) / bucket_minutes - 1
        const maxBuckets = Math.floor((state.settings.horizonHours * 60) / state.settings.bucketMinutes) - 1;
        slider.max = maxBuckets;

        // Update end label (e.g., "+4:00", "+6:00")
        if (endLabel) {
            const totalMinutes = state.settings.horizonHours * 60;
            const hours = Math.floor(totalMinutes / 60);
            const mins = totalMinutes % 60;
            endLabel.textContent = `+${hours}:${mins.toString().padStart(2, '0')}`;
        }

        console.log('[DemandLayer] Slider range updated: max=' + maxBuckets);
    }

    /**
     * Set selected time bucket
     */
    function setBucket(index) {
        state.settings.selectedBucket = index === null || index === '' ? null : parseInt(index, 10);

        // Update slider UI
        const slider = document.getElementById('demand-time-slider');
        const currentLabel = document.getElementById('demand-slider-current');

        if (slider && state.settings.selectedBucket !== null) {
            slider.value = state.settings.selectedBucket;
        }

        if (currentLabel) {
            if (state.settings.selectedBucket === null) {
                currentLabel.textContent = 'Now+60m';
            } else {
                const minutes = state.settings.selectedBucket * state.settings.bucketMinutes;
                const hours = Math.floor(minutes / 60);
                const mins = minutes % 60;
                currentLabel.textContent = `+${hours}:${mins.toString().padStart(2, '0')}`;
            }
        }

        // Refresh visualization
        if (state.demandData) {
            updateMapData(state.demandData.monitors || []);
        }
    }

    // =========================================
    // UI Updates
    // =========================================

    /**
     * Update timeline display
     */
    function updateTimeline(data) {
        const container = document.getElementById('demand-bucket-chart');
        if (!container || !data || !data.monitors) return;

        // Calculate max count across all monitors for scaling
        let maxCount = 1;
        data.monitors.forEach(m => {
            if (m.counts) {
                m.counts.forEach(c => { if (c > maxCount) maxCount = c; });
            }
        });

        // Clear container
        container.innerHTML = '';

        // Get all counts for percentile
        const allCounts = data.monitors.flatMap(m => m.counts || []).filter(c => c > 0);

        // Create bar for each bucket
        const numBuckets = data.num_buckets || 16;
        const barWidth = Math.max(4, Math.floor(container.clientWidth / numBuckets) - 1);

        for (let i = 0; i < numBuckets && i < (data.buckets?.length || 0); i++) {
            // Sum counts across all monitors for this bucket
            let bucketTotal = 0;
            data.monitors.forEach(m => {
                bucketTotal += (m.counts && m.counts[i]) || 0;
            });

            const height = Math.max(2, (bucketTotal / maxCount) * 36);
            const color = getDemandColor(bucketTotal, null, allCounts);

            const bar = document.createElement('div');
            bar.className = 'demand-bucket-bar';
            bar.style.cssText = `
                display: inline-block;
                width: ${barWidth}px;
                height: ${height}px;
                background: ${color};
                margin-right: 1px;
                cursor: pointer;
                vertical-align: bottom;
                border-radius: 2px 2px 0 0;
            `;
            bar.title = `+${i * state.settings.bucketMinutes}min: ${bucketTotal} flights`;
            bar.dataset.bucket = i;
            bar.onclick = () => setBucket(i);

            container.appendChild(bar);
        }
    }

    /**
     * Update threshold info display
     */
    function updateThresholdInfo() {
        if (state.settings.thresholdMode !== 'percentile') return;

        const info = document.getElementById('percentile-values');
        if (!info || !state.demandData) return;

        const allCounts = state.demandData.monitors
            .map(m => getDisplayCount(m))
            .filter(c => c > 0);

        const thresholds = calculatePercentileThresholds(allCounts);
        info.textContent = `Current: Green≤${thresholds.green}, Yellow≤${thresholds.yellow}, Orange≤${thresholds.orange}`;
    }

    // =========================================
    // Persistence (Global API + localStorage fallback)
    // =========================================

    /**
     * Load monitors from global API
     */
    async function loadFromAPI() {
        try {
            const response = await fetch('api/adl/demand/monitors.php');
            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const data = await response.json();
            if (!data.monitors || !Array.isArray(data.monitors)) return;

            // Convert API format to internal monitor format
            const apiMonitors = data.monitors.map(m => ({
                id: m.key,
                ...m.definition,
                _apiId: m.id,
                _global: true
            }));

            // Merge with existing monitors (API takes precedence)
            const existingKeys = new Set(apiMonitors.map(m => m.id));
            const localOnly = state.monitors.filter(m => !existingKeys.has(m.id) && !m._global);

            state.monitors = [...apiMonitors, ...localOnly];
            console.log('[DemandLayer] Loaded', apiMonitors.length, 'global monitors from API');
        } catch (error) {
            console.warn('[DemandLayer] Failed to load from API:', error);
        }
    }

    /**
     * Save monitor to global API
     */
    async function saveMonitorToAPI(monitor) {
        try {
            const response = await fetch('api/adl/demand/monitors.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    type: monitor.type,
                    definition: monitor,
                    label: getMonitorLabel(monitor),
                    created_by: null
                })
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const result = await response.json();
            if (result.success) {
                monitor._global = true;
                monitor._apiId = result.id;
                console.log('[DemandLayer] Saved monitor to API:', result.key);
            }
            return result;
        } catch (error) {
            console.warn('[DemandLayer] Failed to save monitor to API:', error);
            return null;
        }
    }

    /**
     * Delete monitor from global API
     */
    async function deleteMonitorFromAPI(monitorId) {
        try {
            const response = await fetch(`api/adl/demand/monitors.php?monitor_key=${encodeURIComponent(monitorId)}`, {
                method: 'DELETE'
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const result = await response.json();
            console.log('[DemandLayer] Deleted monitor from API:', monitorId);
            return result;
        } catch (error) {
            console.warn('[DemandLayer] Failed to delete monitor from API:', error);
            return null;
        }
    }

    /**
     * Get human-readable label for a monitor
     */
    function getMonitorLabel(monitor) {
        switch (monitor.type) {
            case 'fix':
                return monitor.fix;
            case 'segment':
                return `${monitor.from}-${monitor.to}`;
            case 'airway':
                return monitor.airway;
            case 'airway_segment':
                return `${monitor.from} ${monitor.airway} ${monitor.to}`;
            case 'via_fix':
                if (monitor.filter) {
                    const dir = monitor.filter.direction === 'arr' ? '↓' :
                                monitor.filter.direction === 'dep' ? '↑' : '↕';
                    return `${monitor.filter.code}${dir} via ${monitor.via}`;
                }
                return monitor.via;
            default:
                return monitor.id || 'Unknown';
        }
    }

    /**
     * Save state to localStorage (as fallback/cache)
     */
    function saveToLocalStorage() {
        try {
            const data = {
                version: 1,
                monitors: state.monitors,
                settings: state.settings
            };
            localStorage.setItem(CONFIG.localStorageKey, JSON.stringify(data));
        } catch (error) {
            console.warn('[DemandLayer] Failed to save to localStorage:', error);
        }
    }

    /**
     * Load state from localStorage
     */
    function loadFromLocalStorage() {
        try {
            const json = localStorage.getItem(CONFIG.localStorageKey);
            if (!json) return;

            const data = JSON.parse(json);
            if (data.version !== 1) return;

            if (Array.isArray(data.monitors)) {
                state.monitors = data.monitors;
            }
            if (data.settings) {
                Object.assign(state.settings, data.settings);
            }

            console.log('[DemandLayer] Loaded', state.monitors.length, 'monitors from localStorage');
        } catch (error) {
            console.warn('[DemandLayer] Failed to load from localStorage:', error);
        }
    }

    /**
     * Load monitors - from API first, then localStorage as fallback
     */
    async function loadMonitors() {
        // First load from localStorage for immediate display
        loadFromLocalStorage();

        // Then load from API and merge
        await loadFromAPI();

        // Save merged state back to localStorage
        saveToLocalStorage();
    }

    // =========================================
    // Text-Based Monitor Input Parsing
    // =========================================

    // Known ARTCC codes (3-letter Z-prefixed)
    const ARTCC_CODES = ['ZNY', 'ZBW', 'ZDC', 'ZOB', 'ZID', 'ZAU', 'ZMP', 'ZKC', 'ZME', 'ZFW',
                         'ZHU', 'ZLA', 'ZOA', 'ZSE', 'ZLC', 'ZDV', 'ZAB', 'ZTL', 'ZJX', 'ZMA',
                         'ZAN', 'ZHN', 'ZSU', 'ZUA'];

    // Common TRACON codes (typically 3 chars, some start with letter + digits)
    const TRACON_PATTERNS = /^(N90|A80|A90|C90|D01|D10|D21|I90|L30|M98|NCT|NOR|P50|P80|PCT|PHL|POT|S46|S56|SCT|Y90)$/i;

    // Airway patterns (J/V/Q/T/Y/L/M/A/B/G/R followed by numbers)
    // J = Jet routes, V = Victor routes, Q/T = RNAV, Y = RNAV, L/M/A/B/G/R = International
    const AIRWAY_PATTERN = /^[JVQTYLMABGR]\d+$/i;

    /**
     * Parse natural language monitor input string
     * Supports:
     *   MERIT               -> fix
     *   J48                 -> airway
     *   LANNA J48 MOL       -> airway_segment
     *   KBOS arrivals via MERIT -> via_fix with airport filter
     *   N90 departures via WAVEY -> via_fix with tracon filter
     *   ZDC via J48         -> via_fix with artcc filter
     *
     * Flight filters (append to any pattern):
     *   MERIT airline:UAL              -> fix with airline filter
     *   J48 type:B738                  -> airway with aircraft_type filter
     *   KBOS arr via MERIT category:HEAVY -> via_fix with aircraft_category filter
     *   MERIT origin:KJFK dest:KLAX    -> fix with origin/destination filter
     */
    function parseMonitorInput(input) {
        if (!input || typeof input !== 'string') return null;

        input = input.trim().toUpperCase();
        if (!input) return null;

        // Extract flight filter key:value pairs from the end
        // Patterns: airline:UAL, type:B738, category:HEAVY, origin:KJFK, dest:KLAX
        const flightFilter = {};
        let cleanedInput = input;

        // Extract filter patterns from the input
        const filterPatterns = [
            { regex: /\s+AIRLINE:(\w+)/i, key: 'airline' },
            { regex: /\s+TYPE:(\w+)/i, key: 'aircraft_type' },
            { regex: /\s+CATEGORY:(HEAVY|LARGE|SMALL)/i, key: 'aircraft_category' },
            { regex: /\s+ORIGIN:(\w+)/i, key: 'origin' },
            { regex: /\s+DEST(?:INATION)?:(\w+)/i, key: 'destination' }
        ];

        filterPatterns.forEach(({ regex, key }) => {
            const match = cleanedInput.match(regex);
            if (match) {
                let value = match[1];
                // Normalize airport codes for origin/destination
                if (key === 'origin' || key === 'destination') {
                    value = normalizeAirportCode(value);
                }
                flightFilter[key] = value;
                cleanedInput = cleanedInput.replace(regex, '').trim();
            }
        });

        // Now parse the cleaned input (without filter parts)
        const result = parseMonitorCore(cleanedInput);

        if (result && Object.keys(flightFilter).length > 0) {
            result.flight_filter = flightFilter;
        }

        return result;
    }

    /**
     * Core monitor parsing (without flight filters)
     */
    function parseMonitorCore(input) {
        if (!input) return null;

        // Pattern 1: "[location] arrivals/departures via [fix/airway]"
        // Examples: "KBOS arrivals via MERIT", "N90 dep via WAVEY", "ZDC via J48"
        const viaMatch = input.match(/^(\w+)\s+(arrivals?|arr|inbound|departures?|dep|outbound)?\s*(?:via|through|over)\s+(.+)$/i);
        if (viaMatch) {
            const location = viaMatch[1];
            const directionRaw = viaMatch[2] || '';
            const viaTarget = viaMatch[3].trim();

            // Determine filter type (airport, tracon, artcc)
            let filterType = classifyLocation(location);
            if (!filterType) {
                // Treat as airport by default
                filterType = { type: 'airport', code: normalizeAirportCode(location) };
            }

            // Determine direction
            let direction = 'both';
            if (/^(arrivals?|arr|inbound)$/i.test(directionRaw)) {
                direction = 'arr';
            } else if (/^(departures?|dep|outbound)$/i.test(directionRaw)) {
                direction = 'dep';
            }

            // Determine if via is fix or airway
            const isAirway = AIRWAY_PATTERN.test(viaTarget);

            return {
                type: 'via_fix',
                via: viaTarget,
                via_type: isAirway ? 'airway' : 'fix',
                filter: {
                    type: filterType.type,
                    code: filterType.code,
                    direction: direction
                }
            };
        }

        // Pattern 2: "[fix] [airway] [fix]" - airway segment
        // Examples: "LANNA J48 MOL", "MERIT V1 SBJ", "REMIS Y280 LEV"
        const segmentMatch = input.match(/^(\w+)\s+([JVQTYLMABGR]\d+)\s+(\w+)$/i);
        if (segmentMatch) {
            return {
                type: 'airway_segment',
                airway: segmentMatch[2],
                from: segmentMatch[1],
                to: segmentMatch[3]
            };
        }

        // Pattern 3: Single airway
        // Examples: "J48", "V1", "Q100"
        if (AIRWAY_PATTERN.test(input)) {
            return {
                type: 'airway',
                airway: input
            };
        }

        // Pattern 4: Simple segment "FIX1 FIX2" (two fixes)
        const twoFixMatch = input.match(/^(\w+)\s+(\w+)$/);
        if (twoFixMatch && !AIRWAY_PATTERN.test(twoFixMatch[1]) && !AIRWAY_PATTERN.test(twoFixMatch[2])) {
            return {
                type: 'segment',
                from: twoFixMatch[1],
                to: twoFixMatch[2]
            };
        }

        // Pattern 5: Single fix (default fallback)
        // Examples: "MERIT", "CAM", "WAVEY"
        if (/^\w+$/.test(input)) {
            return {
                type: 'fix',
                fix: input
            };
        }

        return null;
    }

    /**
     * Classify a location code as airport, tracon, or artcc
     */
    function classifyLocation(code) {
        code = code.toUpperCase();

        // Check ARTCC (Z-prefixed 3 letters)
        if (ARTCC_CODES.includes(code)) {
            return { type: 'artcc', code: code };
        }

        // Check TRACON pattern
        if (TRACON_PATTERNS.test(code)) {
            return { type: 'tracon', code: code };
        }

        // Assume airport (normalize to ICAO)
        return { type: 'airport', code: normalizeAirportCode(code) };
    }

    /**
     * Normalize airport code to ICAO format
     * JFK -> KJFK, LAX -> KLAX, KJFK -> KJFK
     */
    function normalizeAirportCode(code) {
        code = code.toUpperCase();

        // Already 4 chars starting with K/C/P - likely ICAO
        if (code.length === 4 && /^[KCP]/.test(code)) {
            return code;
        }

        // 3-letter code (IATA) - add K prefix for US airports
        if (code.length === 3) {
            return 'K' + code;
        }

        return code;
    }

    /**
     * Add monitor from text input
     */
    function addMonitorFromInput(input) {
        const parsed = parseMonitorInput(input);

        if (!parsed) {
            console.warn('[DemandLayer] Could not parse input:', input);
            showParseError('Invalid input format');
            return false;
        }

        // Add the monitor (addMonitor handles renderMonitorsList)
        const success = addMonitor(parsed);

        if (success) {
            // Clear input field
            const inputEl = document.getElementById('demand-monitor-input');
            if (inputEl) inputEl.value = '';
        }

        return success;
    }

    /**
     * Show parse error feedback
     */
    function showParseError(message) {
        const inputEl = document.getElementById('demand-monitor-input');
        if (inputEl) {
            inputEl.classList.add('is-invalid');
            setTimeout(() => inputEl.classList.remove('is-invalid'), 2000);
        }
        console.warn('[DemandLayer] Parse error:', message);
    }

    /**
     * Get human-readable label for a monitor
     */
    function getMonitorLabel(monitor) {
        switch (monitor.type) {
            case 'fix':
                return monitor.fix;
            case 'segment':
                return `${monitor.from} → ${monitor.to}`;
            case 'airway':
                return monitor.airway;
            case 'airway_segment':
                return `${monitor.from} ${monitor.airway} ${monitor.to}`;
            case 'via_fix':
                const dir = monitor.filter.direction === 'arr' ? '↓' :
                           monitor.filter.direction === 'dep' ? '↑' : '↕';
                return `${monitor.filter.code}${dir} via ${monitor.via}`;
            default:
                return JSON.stringify(monitor);
        }
    }

    /**
     * Render the active monitors list in the UI
     */
    function renderMonitorsList() {
        const container = document.getElementById('demand-monitors-list');
        if (!container) return;

        if (state.monitors.length === 0) {
            container.innerHTML = '<div class="text-muted small text-center py-2">No monitors active</div>';
            return;
        }

        let html = '';
        state.monitors.forEach((monitor, idx) => {
            const id = getMonitorId(monitor);
            const label = getMonitorLabel(monitor);
            const typeIcon = monitor.type === 'fix' ? 'fa-map-marker-alt' :
                            monitor.type === 'airway' ? 'fa-route' :
                            monitor.type.includes('segment') ? 'fa-arrows-alt-h' :
                            'fa-filter';

            // Get count from current demand data
            let count = null;
            if (state.demandData && state.demandData.monitors) {
                const monitorData = state.demandData.monitors.find(m => m.id === id);
                if (monitorData) {
                    count = getDisplayCount(monitorData);
                }
            }

            const countBadge = count !== null
                ? `<span class="demand-count-badge" style="background: ${getDemandColor(count, id, [])}; color: #fff; padding: 1px 6px; border-radius: 8px; font-size: 10px; font-weight: 600; margin-left: 6px;">${count}</span>`
                : '';

            html += `
                <div class="demand-monitor-item d-flex align-items-center justify-content-between py-1 px-2 mb-1"
                     style="background: rgba(255,255,255,0.05); border-radius: 3px; font-size: 11px; cursor: pointer;"
                     onclick="NODDemandLayer.showMonitorFlights(${idx})"
                     title="Click to see flights">
                    <span>
                        <i class="fas ${typeIcon} text-info mr-2" style="width: 14px;"></i>
                        ${label}${countBadge}
                    </span>
                    <button class="btn btn-sm btn-link text-danger p-0"
                            onclick="event.stopPropagation(); NODDemandLayer.removeMonitor('${id}')"
                            title="Remove monitor">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `;
        });

        container.innerHTML = html;
    }

    /**
     * Show flights for a monitor from the list (index-based)
     */
    async function showMonitorFlights(idx) {
        if (idx < 0 || idx >= state.monitors.length) return;

        const monitor = state.monitors[idx];
        const label = getMonitorLabel(monitor);

        // Show in a side panel or modal instead of map popup
        const container = document.getElementById('demand-flights-detail');
        if (!container) {
            // Fallback: show in alert or console
            console.log('[DemandLayer] showMonitorFlights: no container, fetching...');
            try {
                const flights = await fetchFlightDetails(monitor);
                console.log(`[DemandLayer] ${label}: ${flights.length} flights`, flights);
            } catch (e) {
                console.error('[DemandLayer] Failed to fetch flights:', e);
            }
            return;
        }

        // Show loading
        container.innerHTML = `
            <div class="demand-flights-header d-flex justify-content-between align-items-center mb-2">
                <span class="font-weight-bold">${label}</span>
                <button class="btn btn-sm btn-link text-muted p-0" onclick="document.getElementById('demand-flights-detail').style.display='none'">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="text-center py-3">
                <i class="fas fa-spinner fa-spin"></i> Loading...
            </div>
        `;
        container.style.display = 'block';

        try {
            const flights = await fetchFlightDetails(monitor);
            renderFlightsInPanel(container, label, flights);
        } catch (error) {
            container.innerHTML = `
                <div class="demand-flights-header d-flex justify-content-between align-items-center mb-2">
                    <span class="font-weight-bold">${label}</span>
                    <button class="btn btn-sm btn-link text-muted p-0" onclick="document.getElementById('demand-flights-detail').style.display='none'">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="text-danger text-center py-3">Failed to load flights</div>
            `;
        }
    }

    /**
     * Render flights in the side panel
     */
    function renderFlightsInPanel(container, label, flights) {
        if (!flights || flights.length === 0) {
            container.innerHTML = `
                <div class="demand-flights-header d-flex justify-content-between align-items-center mb-2">
                    <span class="font-weight-bold">${label}</span>
                    <button class="btn btn-sm btn-link text-muted p-0" onclick="document.getElementById('demand-flights-detail').style.display='none'">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="text-muted text-center py-3">No flights in time window</div>
            `;
            return;
        }

        const rows = flights.map(f => {
            const eta = f.minutes_until >= 0 ? `+${f.minutes_until}m` : `${f.minutes_until}m`;
            return `
                <tr onclick="NODDemandLayer.selectFlight(${f.flight_uid})" style="cursor: pointer;">
                    <td style="color: #4a9eff; font-weight: 600;">${f.callsign}</td>
                    <td>${f.departure} → ${f.destination}</td>
                    <td class="text-muted">${f.aircraft_type}</td>
                    <td class="text-success text-right">${eta}</td>
                </tr>
            `;
        }).join('');

        container.innerHTML = `
            <div class="demand-flights-header d-flex justify-content-between align-items-center mb-2">
                <span class="font-weight-bold">${label}</span>
                <span class="badge badge-info">${flights.length} flights</span>
                <button class="btn btn-sm btn-link text-muted p-0" onclick="document.getElementById('demand-flights-detail').style.display='none'">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div style="max-height: 250px; overflow-y: auto;">
                <table class="table table-sm table-dark mb-0" style="font-size: 11px;">
                    <thead>
                        <tr>
                            <th>Callsign</th>
                            <th>Route</th>
                            <th>Type</th>
                            <th class="text-right">ETA</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${rows}
                    </tbody>
                </table>
            </div>
        `;
    }

    // =========================================
    // Flight Details Popup
    // =========================================

    /**
     * Initialize click handlers for demand elements on the map
     */
    function initMapClickHandlers() {
        if (!state.map) return;

        // Click handler for fix monitors
        state.map.on('click', 'demand-fixes-core', handleDemandClick);

        // Click handler for segment monitors
        state.map.on('click', 'demand-segments-core', handleDemandClick);

        // Change cursor on hover
        state.map.on('mouseenter', 'demand-fixes-core', () => {
            state.map.getCanvas().style.cursor = 'pointer';
        });
        state.map.on('mouseleave', 'demand-fixes-core', () => {
            if (!state.clickMode) state.map.getCanvas().style.cursor = '';
        });
        state.map.on('mouseenter', 'demand-segments-core', () => {
            state.map.getCanvas().style.cursor = 'pointer';
        });
        state.map.on('mouseleave', 'demand-segments-core', () => {
            if (!state.clickMode) state.map.getCanvas().style.cursor = '';
        });

        console.log('[DemandLayer] Map click handlers initialized');
    }

    /**
     * Handle click on demand element to show flight details
     */
    async function handleDemandClick(e) {
        if (!e.features || e.features.length === 0) return;
        if (state.clickMode) return; // Don't show details while in add mode

        e.preventDefault();
        e.originalEvent.stopPropagation();

        const feature = e.features[0];
        const props = feature.properties;

        // Find the monitor from state
        const monitorId = props.id;
        const monitor = findMonitorById(monitorId);

        if (!monitor) {
            console.warn('[DemandLayer] Monitor not found:', monitorId);
            return;
        }

        // Show loading popup
        showFlightDetailsPopup(e.lngLat, monitor, null, true);

        // Fetch flight details
        try {
            const flights = await fetchFlightDetails(monitor);
            showFlightDetailsPopup(e.lngLat, monitor, flights, false);
        } catch (error) {
            console.error('[DemandLayer] Failed to fetch flight details:', error);
            showFlightDetailsPopup(e.lngLat, monitor, [], false, 'Failed to load flights');
        }
    }

    /**
     * Find monitor by ID
     */
    function findMonitorById(id) {
        return state.monitors.find(m => getMonitorId(m) === id) || null;
    }

    /**
     * Fetch flight details from API
     */
    async function fetchFlightDetails(monitor) {
        const params = new URLSearchParams();
        params.set('type', monitor.type);
        params.set('minutes_ahead', state.settings.horizonHours * 60);

        switch (monitor.type) {
            case 'fix':
                params.set('fix', monitor.fix);
                break;
            case 'segment':
                params.set('from', monitor.from);
                params.set('to', monitor.to);
                break;
            case 'airway':
                params.set('airway', monitor.airway);
                break;
            case 'airway_segment':
                params.set('airway', monitor.airway);
                params.set('from', monitor.from);
                params.set('to', monitor.to);
                break;
            case 'via_fix':
                params.set('via', monitor.via);
                params.set('via_type', monitor.via_type || 'fix');
                params.set('filter_type', monitor.filter.type);
                params.set('filter_code', monitor.filter.code);
                params.set('direction', monitor.filter.direction);
                break;
        }

        // Add flight filter parameters if present
        if (monitor.flight_filter) {
            if (monitor.flight_filter.airline) {
                params.set('airline', monitor.flight_filter.airline);
            }
            if (monitor.flight_filter.aircraft_type) {
                params.set('aircraft_type', monitor.flight_filter.aircraft_type);
            }
            if (monitor.flight_filter.aircraft_category) {
                params.set('aircraft_category', monitor.flight_filter.aircraft_category);
            }
            if (monitor.flight_filter.origin) {
                params.set('origin', monitor.flight_filter.origin);
            }
            if (monitor.flight_filter.destination) {
                params.set('destination', monitor.flight_filter.destination);
            }
        }

        const response = await fetch(`api/adl/demand/details.php?${params}`);
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const data = await response.json();
        return data.flights || [];
    }

    /**
     * Show flight details in a popup
     */
    function showFlightDetailsPopup(lngLat, monitor, flights, loading = false, error = null) {
        // Remove existing popup
        if (state.detailsPopup) {
            state.detailsPopup.remove();
        }

        const label = getMonitorLabel(monitor);
        let content = '';

        if (loading) {
            content = `
                <div class="demand-details-popup">
                    <div class="popup-header">${label}</div>
                    <div class="popup-loading">
                        <i class="fas fa-spinner fa-spin"></i> Loading flights...
                    </div>
                </div>
            `;
        } else if (error) {
            content = `
                <div class="demand-details-popup">
                    <div class="popup-header">${label}</div>
                    <div class="popup-error text-danger">${error}</div>
                </div>
            `;
        } else if (!flights || flights.length === 0) {
            content = `
                <div class="demand-details-popup">
                    <div class="popup-header">${label}</div>
                    <div class="popup-empty text-muted">No flights in time window</div>
                </div>
            `;
        } else {
            const flightRows = flights.slice(0, 15).map(f => {
                const eta = f.minutes_until >= 0 ? `+${f.minutes_until}m` : `${f.minutes_until}m`;
                return `
                    <tr onclick="NODDemandLayer.selectFlight(${f.flight_uid})" style="cursor:pointer;">
                        <td class="callsign">${f.callsign}</td>
                        <td class="route">${f.departure} → ${f.destination}</td>
                        <td class="aircraft">${f.aircraft_type}</td>
                        <td class="eta">${eta}</td>
                    </tr>
                `;
            }).join('');

            const moreText = flights.length > 15 ? `<div class="popup-more text-muted">+${flights.length - 15} more flights</div>` : '';

            content = `
                <div class="demand-details-popup">
                    <div class="popup-header">
                        <span>${label}</span>
                        <span class="popup-count">${flights.length} flights</span>
                    </div>
                    <table class="popup-flights">
                        <thead>
                            <tr>
                                <th>Callsign</th>
                                <th>Route</th>
                                <th>Type</th>
                                <th>ETA</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${flightRows}
                        </tbody>
                    </table>
                    ${moreText}
                </div>
            `;
        }

        // Create MapLibre popup
        state.detailsPopup = new maplibregl.Popup({
            closeOnClick: true,
            closeButton: true,
            maxWidth: '350px',
            className: 'demand-popup'
        })
        .setLngLat(lngLat)
        .setHTML(content)
        .addTo(state.map);
    }

    /**
     * Select a flight and highlight it on the map
     */
    function selectFlight(flightUid) {
        // If NOD has a flight selection function, use it
        if (typeof NOD !== 'undefined' && NOD.selectFlight) {
            NOD.selectFlight(flightUid);
        }
        // Close the popup
        if (state.detailsPopup) {
            state.detailsPopup.remove();
            state.detailsPopup = null;
        }
        console.log('[DemandLayer] Selected flight:', flightUid);
    }

    /**
     * Close flight details popup
     */
    function closeFlightDetailsPopup() {
        if (state.detailsPopup) {
            state.detailsPopup.remove();
            state.detailsPopup = null;
        }
    }

    // =========================================
    // FEA Flight Matching
    // =========================================

    /**
     * Distinct colors for FEA monitors (avoid traffic colors)
     */
    const FEA_COLORS = [
        '#17a2b8', // Cyan
        '#e83e8c', // Pink
        '#6f42c1', // Purple
        '#20c997', // Teal
        '#fd7e14', // Orange
        '#6610f2', // Indigo
        '#007bff', // Blue
        '#28a745', // Green
        '#ffc107', // Yellow
        '#dc3545', // Red
        '#795548', // Brown
        '#607d8b', // Blue-gray
    ];

    /**
     * Get the color assigned to a monitor by its index
     */
    function getMonitorColor(monitorIndex) {
        return FEA_COLORS[monitorIndex % FEA_COLORS.length];
    }

    /**
     * Check if a flight matches a specific monitor
     * @param {Object} flight - Flight object with waypoints_json
     * @param {Object} monitor - Monitor definition
     * @returns {boolean} True if flight matches the monitor
     */
    function flightMatchesMonitor(flight, monitor) {
        // Get waypoints from flight
        let waypoints = null;
        if (flight.waypoints_json) {
            try {
                waypoints = typeof flight.waypoints_json === 'string'
                    ? JSON.parse(flight.waypoints_json)
                    : flight.waypoints_json;
            } catch (e) {
                return false;
            }
        }

        if (!waypoints || !Array.isArray(waypoints) || waypoints.length === 0) {
            return false;
        }

        const waypointNames = waypoints.map(w => (w.fix_name || w.name || '').toUpperCase());
        // on_airway can be comma-separated (e.g., "Q75,T420"), so flatten to array of individual airways
        const waypointAirways = waypoints.flatMap(w => (w.on_airway || '').toUpperCase().split(',').filter(a => a));

        switch (monitor.type) {
            case 'fix':
                // Check if any waypoint matches the fix name
                const fixName = (monitor.fix || '').toUpperCase();
                return waypointNames.includes(fixName);

            case 'segment':
                // Check if waypoints contain both from and to fixes
                const fromFix = (monitor.from || '').toUpperCase();
                const toFix = (monitor.to || '').toUpperCase();
                const hasFrom = waypointNames.includes(fromFix);
                const hasTo = waypointNames.includes(toFix);
                return hasFrom && hasTo;

            case 'airway':
                // Check if any waypoint is on this airway (handles comma-separated values)
                const airwayName = (monitor.airway || '').toUpperCase();
                return waypointAirways.includes(airwayName);

            case 'airway_segment':
                // Check if flight has both endpoint fixes
                // Note: on_airway field is often not populated for entry/exit fixes,
                // so we match if both fixes are present in the route
                const airway = (monitor.airway || '').toUpperCase();
                const from = (monitor.from || '').toUpperCase();
                const to = (monitor.to || '').toUpperCase();
                const hasFromFix = waypointNames.includes(from);
                const hasToFix = waypointNames.includes(to);
                // Match if both fixes are present - the airway is implied by the segment definition
                return hasFromFix && hasToFix;

            case 'via_fix':
                // Check via fix/airway and apply origin/destination filters
                const viaValue = (monitor.via || '').toUpperCase();
                const viaType = (monitor.via_type || 'fix').toLowerCase();
                const filter = monitor.filter || {};

                // First check if flight goes through via point
                let passesVia = false;
                if (viaType === 'airway') {
                    passesVia = waypointAirways.includes(viaValue);
                } else {
                    passesVia = waypointNames.includes(viaValue);
                }

                if (!passesVia) return false;

                // Apply filter (airport/tracon/artcc, direction)
                if (filter.type && filter.code) {
                    const filterCode = filter.code.toUpperCase();
                    const direction = (filter.direction || 'both').toLowerCase();

                    // Get flight origin/destination info
                    const flightDep = (flight.departure || flight.fp_dept_icao || '').toUpperCase();
                    const flightDest = (flight.destination || flight.fp_dest_icao || '').toUpperCase();
                    const flightDepTracon = (flight.fp_dept_tracon || '').toUpperCase();
                    const flightDestTracon = (flight.fp_dest_tracon || '').toUpperCase();
                    const flightDepArtcc = (flight.fp_dept_artcc || '').toUpperCase();
                    const flightDestArtcc = (flight.fp_dest_artcc || '').toUpperCase();

                    let matchesDep = false;
                    let matchesArr = false;

                    switch (filter.type.toLowerCase()) {
                        case 'airport':
                            matchesDep = flightDep === filterCode || flightDep === 'K' + filterCode;
                            matchesArr = flightDest === filterCode || flightDest === 'K' + filterCode;
                            break;
                        case 'tracon':
                            matchesDep = flightDepTracon === filterCode;
                            matchesArr = flightDestTracon === filterCode;
                            break;
                        case 'artcc':
                            matchesDep = flightDepArtcc === filterCode;
                            matchesArr = flightDestArtcc === filterCode;
                            break;
                    }

                    if (direction === 'arr' && !matchesArr) return false;
                    if (direction === 'dep' && !matchesDep) return false;
                    if (direction === 'both' && !matchesDep && !matchesArr) return false;
                }

                return true;

            default:
                return false;
        }
    }

    /**
     * Get all active monitors with their assigned colors
     * @returns {Array} Array of { monitor, label, color, index }
     */
    function getActiveMonitors() {
        return state.monitors.map((monitor, idx) => ({
            monitor,
            label: getMonitorLabel(monitor),
            color: getMonitorColor(idx),
            index: idx
        }));
    }

    /**
     * Get the FEA match result for a flight
     * Returns the first matching monitor's color, or null if no match
     * NOTE: This works independently of state.enabled - monitors can be used
     * for flight coloring even when the demand layer visualization is disabled
     * @param {Object} flight - Flight object
     * @returns {Object|null} { color, label, index } or null
     */
    function getFlightFEAMatch(flight) {
        // Only check if monitors exist - don't require state.enabled
        // This allows FEA match coloring to work even when demand visualization is off
        if (state.monitors.length === 0) {
            return null;
        }

        for (let i = 0; i < state.monitors.length; i++) {
            const monitor = state.monitors[i];
            if (flightMatchesMonitor(flight, monitor)) {
                return {
                    color: getMonitorColor(i),
                    label: getMonitorLabel(monitor),
                    index: i
                };
            }
        }

        return null;
    }

    /**
     * Get all FEA matches for a flight (if it matches multiple monitors)
     * NOTE: This works independently of state.enabled - monitors can be used
     * for flight coloring even when the demand layer visualization is disabled
     * @param {Object} flight - Flight object
     * @returns {Array} Array of { color, label, index }
     */
    function getFlightFEAMatches(flight) {
        // Only check if monitors exist - don't require state.enabled
        if (state.monitors.length === 0) {
            return [];
        }

        const matches = [];
        for (let i = 0; i < state.monitors.length; i++) {
            const monitor = state.monitors[i];
            if (flightMatchesMonitor(flight, monitor)) {
                matches.push({
                    color: getMonitorColor(i),
                    label: getMonitorLabel(monitor),
                    index: i
                });
            }
        }

        return matches;
    }

    // =========================================
    // Public API
    // =========================================

    return {
        init,
        enable,
        disable,
        addMonitor,
        removeMonitor,
        clearMonitors,
        refresh,
        setThresholdMode,
        setAbsoluteThreshold,
        setHorizonHours,
        setBucket,
        toggleClickMode,
        parseMonitorInput,
        addMonitorFromInput,
        renderMonitorsList,
        getMonitorLabel,
        selectFlight,
        closeFlightDetailsPopup,
        showMonitorFlights,
        getState: () => ({ ...state }),
        COLORS: DEMAND_COLORS,
        // FEA matching functions
        FEA_COLORS,
        getMonitorColor,
        getActiveMonitors,
        getFlightFEAMatch,
        getFlightFEAMatches,
        flightMatchesMonitor
    };
})();

// Export for global access
window.NODDemandLayer = NODDemandLayer;
