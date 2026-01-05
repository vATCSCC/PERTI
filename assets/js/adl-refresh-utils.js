/**
 * ADL Refresh Utilities
 * 
 * Provides double-buffering and seamless data refresh patterns to prevent
 * UI "flashing" or data gaps during periodic refreshes.
 * 
 * Key patterns:
 * 1. Never clear existing data until replacement data is ready
 * 2. Build complete HTML before swapping table contents
 * 3. Keep old state during API calls, only replace on success
 * 4. Use subtle loading indicators instead of clearing content
 */

const ADLRefreshUtils = (function() {
    'use strict';

    // =========================================================================
    // Buffered Fetch - Keeps old data until new data arrives
    // =========================================================================

    /**
     * Create a buffered data fetcher that maintains old data during refresh
     * 
     * @param {Object} options Configuration options
     * @param {string} options.url - API endpoint URL
     * @param {Function} options.onSuccess - Called with new data when fetch succeeds
     * @param {Function} options.onError - Called on fetch error (optional)
     * @param {Function} options.transform - Transform response data (optional)
     * @param {string} options.statusElementId - Element to show subtle loading status (optional)
     * @returns {Object} Controller with refresh() method and current data getter
     * 
     * @example
     * const trafficFetcher = ADLRefreshUtils.createBufferedFetcher({
     *     url: 'api/adl/current.php',
     *     onSuccess: (data) => {
     *         renderTrafficTable(data);
     *         updateStats(data);
     *     },
     *     statusElementId: 'lastUpdateTime'
     * });
     * 
     * // Manual refresh
     * trafficFetcher.refresh();
     * 
     * // Set up interval (data persists between refreshes)
     * setInterval(() => trafficFetcher.refresh(), 15000);
     */
    function createBufferedFetcher(options) {
        let currentData = null;
        let isLoading = false;
        let lastError = null;
        let lastUpdate = null;

        async function refresh() {
            if (isLoading) {
                console.log('[ADLRefresh] Skipping refresh - already in progress');
                return currentData;
            }

            isLoading = true;
            showLoadingIndicator(options.statusElementId, true);

            try {
                const response = await fetch(options.url, { cache: 'no-cache' });
                
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                let newData = await response.json();

                // Apply transform if provided
                if (typeof options.transform === 'function') {
                    newData = options.transform(newData);
                }

                // Only update if we got valid data
                if (newData !== null && newData !== undefined) {
                    currentData = newData;
                    lastUpdate = new Date();
                    lastError = null;

                    // Call success handler with new data
                    if (typeof options.onSuccess === 'function') {
                        options.onSuccess(newData);
                    }
                }

            } catch (error) {
                console.error('[ADLRefresh] Fetch error:', error);
                lastError = error;

                // Call error handler but DON'T clear currentData
                if (typeof options.onError === 'function') {
                    options.onError(error, currentData);
                }
                // Note: currentData remains unchanged - old data persists

            } finally {
                isLoading = false;
                showLoadingIndicator(options.statusElementId, false);
            }

            return currentData;
        }

        function showLoadingIndicator(elementId, loading) {
            if (!elementId) return;
            
            const el = document.getElementById(elementId);
            if (!el) return;

            if (loading) {
                // Add subtle loading class instead of clearing content
                el.classList.add('adl-refreshing');
            } else {
                el.classList.remove('adl-refreshing');
                // Update timestamp if we have one
                if (lastUpdate) {
                    const timeStr = lastUpdate.toISOString().substr(11, 5) + 'Z';
                    el.textContent = timeStr;
                }
            }
        }

        return {
            refresh,
            getData: () => currentData,
            isLoading: () => isLoading,
            getLastUpdate: () => lastUpdate,
            getLastError: () => lastError,
            // Allow manual data setting (for initialization)
            setData: (data) => { currentData = data; }
        };
    }


    // =========================================================================
    // Buffered Table Rendering - Never shows empty state during refresh
    // =========================================================================

    /**
     * Swap table body content atomically - builds complete HTML before replacing
     * 
     * @param {string|HTMLElement} tbody - Table body element or its ID
     * @param {Array} rows - Array of row data
     * @param {Function} rowBuilder - Function that takes a row and returns HTML string
     * @param {Object} options - Additional options
     * @param {string} options.emptyMessage - Message to show if no rows (only on initial load)
     * @param {number} options.colspan - Number of columns for empty message
     * @param {boolean} options.preserveOnEmpty - If true, keep existing content when rows is empty
     * 
     * @example
     * ADLRefreshUtils.swapTableContent('flight_table_body', flights, (flight) => {
     *     return `<tr>
     *         <td>${flight.callsign}</td>
     *         <td>${flight.origin}</td>
     *         <td>${flight.dest}</td>
     *     </tr>`;
     * }, { colspan: 3, emptyMessage: 'No flights found' });
     */
    function swapTableContent(tbody, rows, rowBuilder, options = {}) {
        const tbodyEl = typeof tbody === 'string' ? document.getElementById(tbody) : tbody;
        if (!tbodyEl) {
            console.warn('[ADLRefresh] Table body not found:', tbody);
            return;
        }

        const {
            emptyMessage = 'No data available',
            colspan = 1,
            preserveOnEmpty = true
        } = options;

        // If no rows and preserveOnEmpty is true, keep existing content
        if ((!rows || rows.length === 0) && preserveOnEmpty && tbodyEl.innerHTML.trim() !== '') {
            // Add a subtle indicator that refresh returned empty
            tbodyEl.classList.add('adl-stale-data');
            return;
        }

        // Build complete HTML BEFORE touching the DOM
        let html = '';
        
        if (!rows || rows.length === 0) {
            html = `<tr><td colspan="${colspan}" class="text-muted text-center py-3">${escapeHtml(emptyMessage)}</td></tr>`;
        } else {
            rows.forEach((row, index) => {
                try {
                    html += rowBuilder(row, index);
                } catch (e) {
                    console.error('[ADLRefresh] Row builder error:', e, row);
                }
            });
        }

        // Atomic swap - single DOM update
        tbodyEl.classList.remove('adl-stale-data');
        tbodyEl.innerHTML = html;
    }


    // =========================================================================
    // State Buffer - Maintains previous state during async operations
    // =========================================================================

    /**
     * Create a buffered state container that never nullifies during updates
     * 
     * @param {*} initialState - Initial state value
     * @returns {Object} State container with get/set/update methods
     * 
     * @example
     * const flightState = ADLRefreshUtils.createStateBuffer([]);
     * 
     * // During refresh - old data remains accessible
     * async function refresh() {
     *     const newData = await fetchFlights();
     *     flightState.update(newData); // Only updates if newData is valid
     * }
     * 
     * // Render always has data
     * function render() {
     *     const flights = flightState.get(); // Never null during refresh
     *     renderTable(flights);
     * }
     */
    function createStateBuffer(initialState) {
        let current = initialState;
        let previous = null;
        let updateCount = 0;

        return {
            get: () => current,
            getPrevious: () => previous,
            
            /**
             * Update state - only replaces if newState is valid
             * @param {*} newState - New state value
             * @param {Function} validator - Optional validator function
             */
            update: (newState, validator) => {
                // Validate new state if validator provided
                if (typeof validator === 'function' && !validator(newState)) {
                    console.warn('[ADLRefresh] State update rejected by validator');
                    return false;
                }

                // Don't replace with null/undefined
                if (newState === null || newState === undefined) {
                    console.warn('[ADLRefresh] Ignoring null/undefined state update');
                    return false;
                }

                // For arrays, don't replace with empty if we have data
                // (unless explicitly set)
                if (Array.isArray(current) && current.length > 0 &&
                    Array.isArray(newState) && newState.length === 0) {
                    console.log('[ADLRefresh] Keeping existing array data (new data empty)');
                    return false;
                }

                previous = current;
                current = newState;
                updateCount++;
                return true;
            },

            /**
             * Force set state regardless of validation
             */
            set: (newState) => {
                previous = current;
                current = newState;
                updateCount++;
            },

            /**
             * Get update count (useful for change detection)
             */
            getUpdateCount: () => updateCount,

            /**
             * Check if state has changed since last render
             */
            hasChanged: (lastSeenCount) => updateCount !== lastSeenCount
        };
    }


    // =========================================================================
    // Map Data Buffer - For MapLibre/Leaflet GeoJSON updates
    // =========================================================================

    /**
     * Update MapLibre source data without clearing during fetch
     * 
     * @param {Object} map - MapLibre map instance
     * @param {string} sourceId - Source ID to update
     * @param {Object} newData - New GeoJSON data
     * @param {Object} options - Additional options
     * 
     * @example
     * // Don't do this (causes flash):
     * map.getSource('traffic').setData({ type: 'FeatureCollection', features: [] });
     * const data = await fetch(...);
     * map.getSource('traffic').setData(data);
     * 
     * // Do this instead:
     * const data = await fetch(...);
     * ADLRefreshUtils.updateMapSource(map, 'traffic', data);
     */
    function updateMapSource(map, sourceId, newData, options = {}) {
        if (!map || !sourceId) return false;

        const source = map.getSource(sourceId);
        if (!source) {
            console.warn('[ADLRefresh] Map source not found:', sourceId);
            return false;
        }

        // Validate GeoJSON structure
        if (!newData || typeof newData !== 'object') {
            console.warn('[ADLRefresh] Invalid GeoJSON data');
            return false;
        }

        // Ensure it's a valid FeatureCollection
        const geoJson = newData.type === 'FeatureCollection' 
            ? newData 
            : { type: 'FeatureCollection', features: newData.features || [] };

        // Only update if we have features (unless forceEmpty is set)
        if (geoJson.features.length === 0 && !options.forceEmpty) {
            console.log('[ADLRefresh] Skipping empty GeoJSON update');
            return false;
        }

        source.setData(geoJson);
        return true;
    }


    // =========================================================================
    // Refresh Coordinator - Manages multiple data sources
    // =========================================================================

    /**
     * Coordinate multiple data refreshes to update UI only when all are ready
     * 
     * @example
     * const coordinator = ADLRefreshUtils.createRefreshCoordinator({
     *     sources: {
     *         traffic: { url: 'api/adl/current.php' },
     *         weather: { url: 'api/weather/radar.php' },
     *         tmi: { url: 'api/tmi/active.php' }
     *     },
     *     onAllReady: (data) => {
     *         // All sources loaded - update UI atomically
     *         renderTraffic(data.traffic);
     *         renderWeather(data.weather);
     *         renderTMI(data.tmi);
     *     }
     * });
     * 
     * coordinator.refreshAll();
     */
    function createRefreshCoordinator(options) {
        const fetchers = {};
        const { sources, onAllReady, onPartialReady } = options;

        // Create a buffered fetcher for each source
        Object.entries(sources).forEach(([key, config]) => {
            fetchers[key] = createBufferedFetcher({
                url: config.url,
                transform: config.transform,
                onSuccess: (data) => {
                    if (typeof config.onSuccess === 'function') {
                        config.onSuccess(data);
                    }
                },
                onError: config.onError
            });
        });

        async function refreshAll() {
            const results = {};
            const promises = Object.entries(fetchers).map(async ([key, fetcher]) => {
                results[key] = await fetcher.refresh();
            });

            await Promise.all(promises);

            // Call onAllReady with all data
            if (typeof onAllReady === 'function') {
                onAllReady(results);
            }

            return results;
        }

        async function refreshOne(key) {
            if (!fetchers[key]) {
                console.warn('[ADLRefresh] Unknown source:', key);
                return null;
            }
            return fetchers[key].refresh();
        }

        function getAllData() {
            const data = {};
            Object.entries(fetchers).forEach(([key, fetcher]) => {
                data[key] = fetcher.getData();
            });
            return data;
        }

        return {
            refreshAll,
            refreshOne,
            getAllData,
            getFetcher: (key) => fetchers[key]
        };
    }


    // =========================================================================
    // Stats Counter Buffer - For count displays
    // =========================================================================

    /**
     * Update stat counters with smooth transitions (no zeroing)
     * 
     * @param {string|HTMLElement} element - Element or ID
     * @param {number} newValue - New count value
     * @param {Object} options - Animation options
     */
    function updateStatCounter(element, newValue, options = {}) {
        const el = typeof element === 'string' ? document.getElementById(element) : element;
        if (!el) return;

        const { 
            format = (n) => n.toLocaleString(),
            animateDuration = 0,
            prefix = '',
            suffix = ''
        } = options;

        // Don't update to null/undefined/NaN
        if (newValue === null || newValue === undefined || isNaN(newValue)) {
            return;
        }

        const formatted = prefix + format(newValue) + suffix;

        if (animateDuration > 0) {
            // Animate the change
            const currentValue = parseInt(el.textContent.replace(/[^0-9-]/g, ''), 10) || 0;
            animateCounter(el, currentValue, newValue, animateDuration, format, prefix, suffix);
        } else {
            el.textContent = formatted;
        }
    }

    function animateCounter(el, from, to, duration, format, prefix, suffix) {
        const startTime = performance.now();
        const diff = to - from;

        function tick(currentTime) {
            const elapsed = currentTime - startTime;
            const progress = Math.min(elapsed / duration, 1);
            
            // Ease out
            const eased = 1 - Math.pow(1 - progress, 3);
            const current = Math.round(from + diff * eased);
            
            el.textContent = prefix + format(current) + suffix;

            if (progress < 1) {
                requestAnimationFrame(tick);
            }
        }

        requestAnimationFrame(tick);
    }


    // =========================================================================
    // Utility Functions
    // =========================================================================

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }


    // =========================================================================
    // CSS Injection for Loading States
    // =========================================================================

    function injectStyles() {
        if (document.getElementById('adl-refresh-styles')) return;

        const styles = document.createElement('style');
        styles.id = 'adl-refresh-styles';
        styles.textContent = `
            /* Subtle loading indicator - pulsing opacity */
            .adl-refreshing {
                animation: adl-pulse 1s ease-in-out infinite;
            }
            
            @keyframes adl-pulse {
                0%, 100% { opacity: 1; }
                50% { opacity: 0.6; }
            }
            
            /* Stale data indicator - subtle border */
            .adl-stale-data {
                position: relative;
            }
            
            .adl-stale-data::after {
                content: '';
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                border: 1px dashed rgba(255, 193, 7, 0.3);
                pointer-events: none;
            }
            
            /* Table row fade-in for smooth updates */
            .adl-fade-in tr {
                animation: adl-row-fade 0.3s ease-out;
            }
            
            @keyframes adl-row-fade {
                from { opacity: 0.5; }
                to { opacity: 1; }
            }
        `;
        document.head.appendChild(styles);
    }

    // Auto-inject styles when module loads
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', injectStyles);
    } else {
        injectStyles();
    }


    // =========================================================================
    // Public API
    // =========================================================================

    return {
        createBufferedFetcher,
        swapTableContent,
        createStateBuffer,
        updateMapSource,
        createRefreshCoordinator,
        updateStatCounter,
        escapeHtml,
        
        // Version for debugging
        version: '1.0.0'
    };

})();

// Make available globally
window.ADLRefreshUtils = ADLRefreshUtils;
