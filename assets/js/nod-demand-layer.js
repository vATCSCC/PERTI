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
        sourcesAdded: false
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
        loadFromLocalStorage();

        // Add sources and layers when map style is loaded
        if (map.isStyleLoaded()) {
            addSourcesAndLayers();
        } else {
            map.on('style.load', addSourcesAndLayers);
        }

        // Set up click handler
        map.on('click', handleMapClick);

        console.log('[DemandLayer] Initialized');
    }

    /**
     * Add MapLibre sources and layers for demand visualization
     */
    function addSourcesAndLayers() {
        if (state.sourcesAdded) return;

        const map = state.map;

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
                'text-field': ['concat', ['get', 'fix'], '\n', ['to-string', ['get', 'count']]],
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
                'text-field': ['concat', ['get', 'from_fix'], '-', ['get', 'to_fix'], '\n', ['to-string', ['get', 'count']]],
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

        const demandControls = document.getElementById('demandControls');
        if (demandControls) demandControls.style.display = 'block';

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

        // Validate monitor
        if (monitor.type === 'fix' && !monitor.fix) {
            console.error('[DemandLayer] Fix monitor missing fix name');
            return false;
        }
        if (monitor.type === 'segment' && (!monitor.from || !monitor.to)) {
            console.error('[DemandLayer] Segment monitor missing from/to');
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

        // Refresh immediately
        if (state.enabled) {
            refresh();
        }

        console.log('[DemandLayer] Added monitor:', id);
        return true;
    }

    /**
     * Remove a monitor by ID
     */
    function removeMonitor(id) {
        const idx = state.monitors.findIndex(m => getMonitorId(m) === id);
        if (idx === -1) return false;

        state.monitors.splice(idx, 1);
        saveToLocalStorage();

        // Refresh immediately
        if (state.enabled) {
            refresh();
        }

        console.log('[DemandLayer] Removed monitor:', id);
        return true;
    }

    /**
     * Clear all monitors
     */
    function clearMonitors() {
        state.monitors = [];
        saveToLocalStorage();

        // Clear map data
        updateMapData([]);

        console.log('[DemandLayer] Cleared all monitors');
    }

    /**
     * Generate monitor ID
     */
    function getMonitorId(monitor) {
        if (monitor.type === 'fix') {
            return 'fix_' + monitor.fix.toUpperCase();
        } else if (monitor.type === 'segment') {
            return 'segment_' + monitor.from.toUpperCase() + '_' + monitor.to.toUpperCase();
        }
        return null;
    }

    // =========================================
    // Data Fetching
    // =========================================

    /**
     * Fetch demand data from API
     */
    async function refresh() {
        if (!state.enabled || state.monitors.length === 0) {
            updateMapData([]);
            return;
        }

        try {
            const params = new URLSearchParams({
                monitors: JSON.stringify(state.monitors),
                bucket_minutes: state.settings.bucketMinutes,
                horizon_hours: state.settings.horizonHours
            });

            const response = await fetch(`${CONFIG.apiEndpoint}?${params}`);
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();
            state.demandData = data;

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
        if (!state.map || !state.sourcesAdded) return;

        const fixFeatures = [];
        const segmentFeatures = [];

        // Collect all counts for percentile calculation
        const allCounts = monitors.map(m => getDisplayCount(m)).filter(c => c > 0);

        monitors.forEach(monitor => {
            const count = getDisplayCount(monitor);
            const color = getDemandColor(count, monitor.id, allCounts);

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
                        color: color
                    }
                });
            } else if (monitor.type === 'segment' &&
                       monitor.from_lat != null && monitor.to_lat != null) {
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
                        color: color
                    }
                });
            }
        });

        // Update sources
        state.map.getSource('demand-fixes-source').setData({
            type: 'FeatureCollection',
            features: fixFeatures
        });

        state.map.getSource('demand-segments-source').setData({
            type: 'FeatureCollection',
            features: segmentFeatures
        });
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

        // Refresh immediately with new horizon
        if (state.enabled) {
            refresh();
        }

        console.log('[DemandLayer] Horizon hours:', hours);
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
    // Persistence
    // =========================================

    /**
     * Save state to localStorage
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

            console.log('[DemandLayer] Loaded', state.monitors.length, 'monitors from storage');
        } catch (error) {
            console.warn('[DemandLayer] Failed to load from localStorage:', error);
        }
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
        getState: () => ({ ...state }),
        COLORS: DEMAND_COLORS
    };
})();

// Export for global access
window.NODDemandLayer = NODDemandLayer;
