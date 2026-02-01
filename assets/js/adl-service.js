/**
 * ADL Data Service - Centralized ADL data management with buffered refreshes
 *
 * This module provides:
 * 1. Single shared data fetch for all ADL consumers
 * 2. Buffered state that never goes empty during refreshes
 * 3. Subscriber pattern for multiple page components
 * 4. Automatic refresh management
 *
 * Usage:
 *   // Subscribe to ADL updates
 *   ADLService.subscribe('my-component', (data) => {
 *       renderMyTable(data.flights);
 *   });
 *
 *   // Start auto-refresh (only first caller sets the interval)
 *   ADLService.startAutoRefresh(15000);
 *
 *   // Manual refresh
 *   ADLService.refresh();
 *
 *   // Get current data (never null after first load)
 *   const flights = ADLService.getFlights();
 */

const ADLService = (function() {
    'use strict';

    // =========================================================================
    // Configuration
    // =========================================================================

    const CONFIG = {
        apiUrl: 'api/adl/current.php',
        defaultParams: {
            limit: 10000,
            active: 1,
        },
        minRefreshInterval: 5000,  // Minimum 5s between refreshes
        defaultRefreshInterval: 15000,
    };

    // =========================================================================
    // State - Uses double-buffering pattern
    // =========================================================================

    const state = {
        // Current data (never set to null/empty after first successful load)
        flights: [],
        stats: null,
        snapshotUtc: null,

        // Previous data (for change detection)
        previousFlights: [],

        // Loading state
        isLoading: false,
        lastRefresh: null,
        lastError: null,
        refreshCount: 0,

        // Auto-refresh
        refreshInterval: null,
        refreshIntervalMs: CONFIG.defaultRefreshInterval,

        // Subscribers
        subscribers: new Map(),

        // Request deduplication
        pendingRequest: null,
    };

    // =========================================================================
    // Core Data Fetching with Buffering
    // =========================================================================

    /**
     * Fetch ADL data with buffering - keeps old data until new data arrives
     * @param {Object} options - Fetch options
     * @param {boolean} options.force - Force refresh even if loading
     * @returns {Promise} Resolves with flight data
     */
    async function refresh(options = {}) {
        // Deduplicate concurrent requests
        if (state.isLoading && state.pendingRequest && !options.force) {
            console.log('[ADLService] Refresh already in progress, returning pending request');
            return state.pendingRequest;
        }

        // Rate limiting
        if (state.lastRefresh && !options.force) {
            const elapsed = Date.now() - state.lastRefresh.getTime();
            if (elapsed < CONFIG.minRefreshInterval) {
                console.log('[ADLService] Rate limited, returning cached data');
                return Promise.resolve(state.flights);
            }
        }

        state.isLoading = true;
        notifyLoadingState(true);

        const params = new URLSearchParams({
            ...CONFIG.defaultParams,
            ...(options.params || {}),
        });

        const url = `${CONFIG.apiUrl}?${params.toString()}`;

        state.pendingRequest = fetch(url, { cache: 'no-cache' })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                // Extract flights from various response formats
                const newFlights = data.flights || data.rows || data || [];

                // Validate we got actual data
                if (!Array.isArray(newFlights)) {
                    console.warn('[ADLService] Invalid response format');
                    return state.flights; // Return old data
                }

                // Only update if we got data (prevents clearing on empty response)
                if (newFlights.length > 0 || state.flights.length === 0) {
                    state.previousFlights = state.flights;
                    state.flights = newFlights;

                    // Update metadata
                    state.snapshotUtc = data.snapshot_utc || data.snapshotUtc || new Date().toISOString();
                    state.stats = data.stats || null;
                } else {
                    console.log('[ADLService] Empty response, keeping previous data');
                }

                state.lastRefresh = new Date();
                state.lastError = null;
                state.refreshCount++;

                // Notify all subscribers
                notifySubscribers();

                return state.flights;
            })
            .catch(error => {
                console.error('[ADLService] Fetch error:', error);
                state.lastError = error;

                // DON'T clear state.flights - keep old data available
                // Notify subscribers of error (they can check lastError)
                notifySubscribers();

                return state.flights; // Return old data even on error
            })
            .finally(() => {
                state.isLoading = false;
                state.pendingRequest = null;
                notifyLoadingState(false);
            });

        return state.pendingRequest;
    }

    // =========================================================================
    // Subscriber Management
    // =========================================================================

    /**
     * Subscribe to ADL data updates
     * @param {string} id - Unique subscriber ID
     * @param {Function} callback - Called with { flights, stats, snapshotUtc, isError }
     * @param {Object} options - Subscriber options
     * @param {Function} options.filter - Optional filter function for flights
     * @param {boolean} options.immediate - Call immediately with current data
     */
    function subscribe(id, callback, options = {}) {
        if (typeof callback !== 'function') {
            console.error('[ADLService] Callback must be a function');
            return;
        }

        state.subscribers.set(id, {
            callback,
            filter: options.filter || null,
            options,
        });

        console.log(`[ADLService] Subscriber added: ${id} (total: ${state.subscribers.size})`);

        // Optionally call immediately with current data
        if (options.immediate && state.flights.length > 0) {
            const data = getSubscriberData(state.subscribers.get(id));
            try {
                callback(data);
            } catch (e) {
                console.error(`[ADLService] Error in immediate callback for ${id}:`, e);
            }
        }

        return () => unsubscribe(id);
    }

    /**
     * Unsubscribe from updates
     * @param {string} id - Subscriber ID
     */
    function unsubscribe(id) {
        if (state.subscribers.delete(id)) {
            console.log(`[ADLService] Subscriber removed: ${id} (total: ${state.subscribers.size})`);
        }
    }

    /**
     * Notify all subscribers of data update
     */
    function notifySubscribers() {
        state.subscribers.forEach((sub, id) => {
            try {
                const data = getSubscriberData(sub);
                sub.callback(data);
            } catch (e) {
                console.error(`[ADLService] Error notifying subscriber ${id}:`, e);
            }
        });
    }

    /**
     * Get data for a specific subscriber (with optional filter applied)
     */
    function getSubscriberData(sub) {
        let flights = state.flights;

        // Apply subscriber's filter if provided
        if (typeof sub.filter === 'function') {
            flights = flights.filter(sub.filter);
        }

        return {
            flights,
            allFlights: state.flights,
            stats: state.stats,
            snapshotUtc: state.snapshotUtc,
            lastRefresh: state.lastRefresh,
            isLoading: state.isLoading,
            isError: state.lastError !== null,
            error: state.lastError,
            refreshCount: state.refreshCount,
        };
    }

    /**
     * Notify subscribers of loading state change
     */
    function notifyLoadingState(isLoading) {
        // Update any loading indicators
        document.querySelectorAll('.adl-loading-indicator').forEach(el => {
            el.classList.toggle('adl-refreshing', isLoading);
        });

        // Update timestamp elements
        if (!isLoading && state.lastRefresh) {
            document.querySelectorAll('.adl-last-update').forEach(el => {
                el.textContent = state.lastRefresh.toISOString().substr(11, 5) + 'Z';
            });
        }
    }

    // =========================================================================
    // Auto-Refresh Management
    // =========================================================================

    /**
     * Start auto-refresh interval
     * @param {number} intervalMs - Refresh interval in milliseconds
     */
    function startAutoRefresh(intervalMs) {
        const interval = Math.max(intervalMs || CONFIG.defaultRefreshInterval, CONFIG.minRefreshInterval);

        // Don't restart if already running at same interval
        if (state.refreshInterval && state.refreshIntervalMs === interval) {
            console.log('[ADLService] Auto-refresh already running at', interval, 'ms');
            return;
        }

        // Clear existing interval
        stopAutoRefresh();

        state.refreshIntervalMs = interval;
        state.refreshInterval = setInterval(() => {
            refresh();
        }, interval);

        console.log(`[ADLService] Auto-refresh started: ${interval}ms`);
    }

    /**
     * Stop auto-refresh
     */
    function stopAutoRefresh() {
        if (state.refreshInterval) {
            clearInterval(state.refreshInterval);
            state.refreshInterval = null;
            console.log('[ADLService] Auto-refresh stopped');
        }
    }

    /**
     * Update refresh interval without stopping
     * @param {number} intervalMs - New interval
     */
    function setRefreshInterval(intervalMs) {
        if (state.refreshInterval) {
            stopAutoRefresh();
            startAutoRefresh(intervalMs);
        } else {
            state.refreshIntervalMs = intervalMs;
        }
    }

    // =========================================================================
    // Data Accessors (Always return current buffered data)
    // =========================================================================

    /**
     * Get all flights (never null)
     */
    function getFlights() {
        return state.flights;
    }

    /**
     * Get filtered flights
     * @param {Function} filterFn - Filter function
     */
    function getFilteredFlights(filterFn) {
        if (typeof filterFn !== 'function') {return state.flights;}
        return state.flights.filter(filterFn);
    }

    /**
     * Get flights with valid coordinates
     */
    function getFlightsWithPosition() {
        return state.flights.filter(f =>
            f.lat != null && f.lon != null &&
            !isNaN(parseFloat(f.lat)) && !isNaN(parseFloat(f.lon)),
        );
    }

    /**
     * Get flight by callsign
     */
    function getFlightByCallsign(callsign) {
        const cs = (callsign || '').toUpperCase().trim();
        return state.flights.find(f =>
            (f.callsign || '').toUpperCase().trim() === cs,
        );
    }

    /**
     * Get flights by destination
     */
    function getFlightsByDestination(dest) {
        const d = (dest || '').toUpperCase().trim();
        return state.flights.filter(f =>
            (f.fp_dest_icao || '').toUpperCase().includes(d) ||
            (f.arr || '').toUpperCase().includes(d),
        );
    }

    /**
     * Get flights by origin
     */
    function getFlightsByOrigin(origin) {
        const o = (origin || '').toUpperCase().trim();
        return state.flights.filter(f =>
            (f.fp_dept_icao || '').toUpperCase().includes(o) ||
            (f.dep || '').toUpperCase().includes(o),
        );
    }

    /**
     * Get current stats
     */
    function getStats() {
        return state.stats;
    }

    /**
     * Get service state (for debugging)
     */
    function getState() {
        return {
            flightCount: state.flights.length,
            lastRefresh: state.lastRefresh,
            isLoading: state.isLoading,
            hasError: state.lastError !== null,
            subscriberCount: state.subscribers.size,
            refreshCount: state.refreshCount,
            autoRefreshActive: state.refreshInterval !== null,
            refreshIntervalMs: state.refreshIntervalMs,
        };
    }

    // =========================================================================
    // Utility: Table Rendering Helper
    // =========================================================================

    /**
     * Render a flight table with buffered updates
     * @param {string|HTMLElement} tbody - Table body element or ID
     * @param {Array} flights - Flight data array
     * @param {Function} rowBuilder - Function(flight, index) returning HTML string
     * @param {Object} options - Render options
     */
    function renderFlightTable(tbody, flights, rowBuilder, options = {}) {
        const tbodyEl = typeof tbody === 'string' ? document.getElementById(tbody) : tbody;
        if (!tbodyEl) {return;}

        const {
            emptyMessage = 'No flights found',
            colspan = 8,
            preserveOnEmpty = true,
            sortFn = null,
        } = options;

        // Sort if requested
        let sortedFlights = flights;
        if (typeof sortFn === 'function') {
            sortedFlights = [...flights].sort(sortFn);
        }

        // Preserve existing content if new data is empty
        if (sortedFlights.length === 0 && preserveOnEmpty && tbodyEl.innerHTML.trim() !== '') {
            tbodyEl.classList.add('adl-stale-data');
            return;
        }

        // Build complete HTML before touching DOM
        let html = '';
        if (sortedFlights.length === 0) {
            html = `<tr><td colspan="${colspan}" class="text-muted text-center py-3">${escapeHtml(emptyMessage)}</td></tr>`;
        } else {
            sortedFlights.forEach((flight, index) => {
                try {
                    html += rowBuilder(flight, index);
                } catch (e) {
                    console.error('[ADLService] Row builder error:', e);
                }
            });
        }

        // Atomic DOM update
        tbodyEl.classList.remove('adl-stale-data');
        tbodyEl.innerHTML = html;
    }

    /**
     * Update stat counter without showing 0 during refresh
     * @param {string|HTMLElement} element - Element or ID
     * @param {number} value - New value
     * @param {Object} options - Format options
     */
    function updateCounter(element, value, options = {}) {
        const el = typeof element === 'string' ? document.getElementById(element) : element;
        if (!el) {return;}

        // Don't update to invalid values
        if (value === null || value === undefined || isNaN(value)) {return;}

        // Don't show 0 if we had data (unless forced)
        const currentText = el.textContent || '';
        const currentVal = parseInt(currentText.replace(/[^0-9]/g, ''), 10);
        if (value === 0 && currentVal > 0 && !options.allowZero) {return;}

        const { prefix = '', suffix = '', format = n => n.toLocaleString() } = options;
        el.textContent = prefix + format(value) + suffix;
    }

    // =========================================================================
    // Utility Functions
    // =========================================================================

    function escapeHtml(str) {
        if (str === null || str === undefined) {return '';}
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // =========================================================================
    // CSS Injection
    // =========================================================================

    function injectStyles() {
        if (document.getElementById('adl-service-styles')) {return;}

        const styles = document.createElement('style');
        styles.id = 'adl-service-styles';
        styles.textContent = `
            /* Loading indicator pulse */
            .adl-refreshing {
                animation: adl-pulse 1s ease-in-out infinite;
            }
            @keyframes adl-pulse {
                0%, 100% { opacity: 1; }
                50% { opacity: 0.6; }
            }
            
            /* Stale data indicator */
            .adl-stale-data {
                opacity: 0.7;
            }
            
            /* Smooth row updates */
            .adl-updated {
                animation: adl-highlight 0.5s ease-out;
            }
            @keyframes adl-highlight {
                from { background-color: rgba(23, 162, 184, 0.2); }
                to { background-color: transparent; }
            }
        `;
        document.head.appendChild(styles);
    }

    // Initialize styles
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', injectStyles);
    } else {
        injectStyles();
    }

    // =========================================================================
    // Public API
    // =========================================================================

    return {
        // Core operations
        refresh,
        subscribe,
        unsubscribe,

        // Auto-refresh
        startAutoRefresh,
        stopAutoRefresh,
        setRefreshInterval,

        // Data accessors (always return buffered data)
        getFlights,
        getFilteredFlights,
        getFlightsWithPosition,
        getFlightByCallsign,
        getFlightsByDestination,
        getFlightsByOrigin,
        getStats,
        getState,

        // Rendering helpers
        renderFlightTable,
        updateCounter,
        escapeHtml,

        // Configuration
        configure: (config) => Object.assign(CONFIG, config),

        // Version
        version: '1.0.0',
    };

})();

// Make available globally
window.ADLService = ADLService;
