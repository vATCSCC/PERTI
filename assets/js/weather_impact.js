/**
 * Weather Impact Display Module for PERTI
 * 
 * Displays weather impact badges on flights and provides
 * impact summary information
 * 
 * @version 1.0
 * @date 2026-01-06
 */

const WeatherImpact = (function() {
    'use strict';

    // =========================================================================
    // CONFIGURATION
    // =========================================================================
    
    const CONFIG = {
        apiUrl: '/api/weather/impact.php',
        refreshInterval: 30000,  // 30 seconds
        
        // Impact badge colors
        colors: {
            DIRECT_CONVECTIVE: { bg: '#FF0000', text: '#FFFFFF', icon: '⚡' },
            DIRECT_TURB: { bg: '#FF6600', text: '#FFFFFF', icon: '≋' },
            DIRECT_ICE: { bg: '#00BFFF', text: '#000000', icon: '❄' },
            DIRECT: { bg: '#FF4444', text: '#FFFFFF', icon: '⚠' },
            NEAR_CONVECTIVE: { bg: '#FF6666', text: '#000000', icon: '⚡' },
            NEAR_TURB: { bg: '#FFA500', text: '#000000', icon: '≋' },
            NEAR_ICE: { bg: '#87CEEB', text: '#000000', icon: '❄' },
            NEAR: { bg: '#FFAA44', text: '#000000', icon: '⚠' }
        }
    };

    // =========================================================================
    // STATE
    // =========================================================================
    
    let initialized = false;
    let stats = null;
    let affectedFlights = new Map();  // flight_uid -> impact info
    let refreshTimer = null;
    let onUpdateCallbacks = [];

    // =========================================================================
    // INITIALIZATION
    // =========================================================================
    
    /**
     * Initialize the weather impact module
     */
    function init(options = {}) {
        if (initialized) return;
        
        if (options.refreshInterval) {
            CONFIG.refreshInterval = options.refreshInterval;
        }
        
        // Initial load
        refresh();
        
        // Start auto-refresh
        startAutoRefresh();
        
        initialized = true;
        console.log('WeatherImpact: Initialized');
    }

    // =========================================================================
    // DATA FETCHING
    // =========================================================================
    
    /**
     * Refresh weather impact data
     */
    function refresh() {
        // Fetch quick stats
        fetch(`${CONFIG.apiUrl}?_=${Date.now()}`)
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    stats = data;
                    
                    // If there are affected flights, fetch details
                    if (data.flights_affected > 0) {
                        fetchAffectedFlights();
                    } else {
                        affectedFlights.clear();
                        notifyUpdate();
                    }
                }
            })
            .catch(err => console.error('WeatherImpact: Stats fetch error', err));
    }
    
    /**
     * Fetch list of affected flights
     */
    function fetchAffectedFlights() {
        fetch(`${CONFIG.apiUrl}?affected=1&_=${Date.now()}`)
            .then(r => r.json())
            .then(data => {
                if (data.success && data.flights) {
                    affectedFlights.clear();
                    data.flights.forEach(f => {
                        affectedFlights.set(f.flight_uid, f);
                    });
                    notifyUpdate();
                }
            })
            .catch(err => console.error('WeatherImpact: Affected flights fetch error', err));
    }
    
    /**
     * Fetch impact summary
     */
    function fetchSummary() {
        return fetch(`${CONFIG.apiUrl}?summary=1&_=${Date.now()}`)
            .then(r => r.json());
    }
    
    /**
     * Fetch impact for specific flight
     */
    function fetchFlightImpact(flightUid) {
        return fetch(`${CONFIG.apiUrl}?flight_uid=${flightUid}&_=${Date.now()}`)
            .then(r => r.json());
    }

    // =========================================================================
    // BADGE RENDERING
    // =========================================================================
    
    /**
     * Get weather impact badge HTML for a flight
     * @param {number} flightUid - Flight UID
     * @returns {string} HTML string or empty string if no impact
     */
    function getBadgeHtml(flightUid) {
        const impact = affectedFlights.get(flightUid);
        if (!impact || !impact.weather_impact) return '';
        
        const style = CONFIG.colors[impact.weather_impact] || CONFIG.colors.NEAR;
        
        return `<span class="weather-impact-badge" 
                      style="background:${style.bg};color:${style.text}" 
                      title="${impact.hazard} - ${impact.impact_type}"
                      data-flight-uid="${flightUid}">
                    ${style.icon}
                </span>`;
    }
    
    /**
     * Get badge class for CSS styling
     */
    function getBadgeClass(flightUid) {
        const impact = affectedFlights.get(flightUid);
        if (!impact) return '';
        
        return `weather-${impact.impact_type.toLowerCase()}-${impact.hazard.toLowerCase()}`;
    }
    
    /**
     * Check if flight is affected by weather
     */
    function isAffected(flightUid) {
        return affectedFlights.has(flightUid);
    }
    
    /**
     * Get impact info for flight
     */
    function getImpact(flightUid) {
        return affectedFlights.get(flightUid) || null;
    }

    // =========================================================================
    // SUMMARY PANEL
    // =========================================================================
    
    /**
     * Build summary panel HTML
     */
    function buildSummaryPanel() {
        if (!stats) return '<div class="weather-impact-loading">Loading...</div>';
        
        if (stats.flights_affected === 0) {
            return `
                <div class="weather-impact-clear">
                    <i class="fa fa-check-circle"></i>
                    <span>No weather impacts</span>
                </div>
            `;
        }
        
        return `
            <div class="weather-impact-summary">
                <div class="impact-stat">
                    <span class="stat-value">${stats.flights_affected}</span>
                    <span class="stat-label">Flights Affected</span>
                </div>
                <div class="impact-stat direct">
                    <span class="stat-value">${stats.direct_impacts}</span>
                    <span class="stat-label">Direct</span>
                </div>
                <div class="impact-stat near">
                    <span class="stat-value">${stats.near_impacts}</span>
                    <span class="stat-label">Near</span>
                </div>
                <div class="impact-stat alerts">
                    <span class="stat-value">${stats.active_alerts}</span>
                    <span class="stat-label">Active Alerts</span>
                </div>
            </div>
        `;
    }
    
    /**
     * Build affected flights list HTML
     */
    function buildAffectedList() {
        if (affectedFlights.size === 0) {
            return '<div class="no-affected-flights">No flights currently affected</div>';
        }
        
        let html = '<div class="affected-flights-list">';
        
        // Group by hazard
        const byHazard = new Map();
        affectedFlights.forEach((f, uid) => {
            const key = f.hazard || 'OTHER';
            if (!byHazard.has(key)) byHazard.set(key, []);
            byHazard.get(key).push(f);
        });
        
        // Sort hazards by severity
        const hazardOrder = ['CONVECTIVE', 'TURB', 'ICE', 'IFR', 'MTN', 'OTHER'];
        const sortedHazards = [...byHazard.keys()].sort((a, b) => 
            hazardOrder.indexOf(a) - hazardOrder.indexOf(b)
        );
        
        for (const hazard of sortedHazards) {
            const flights = byHazard.get(hazard);
            html += `
                <div class="hazard-group ${hazard.toLowerCase()}">
                    <div class="hazard-header">${hazard} (${flights.length})</div>
                    <div class="hazard-flights">
            `;
            
            for (const f of flights.slice(0, 20)) {  // Limit to 20 per hazard
                const style = CONFIG.colors[f.weather_impact] || CONFIG.colors.NEAR;
                html += `
                    <div class="affected-flight" data-flight-uid="${f.flight_uid}">
                        <span class="flight-badge" style="background:${style.bg};color:${style.text}">${style.icon}</span>
                        <span class="flight-callsign">${f.callsign}</span>
                        <span class="flight-route">${f.departure || '???'} → ${f.destination || '???'}</span>
                        <span class="flight-impact-type">${f.impact_type}</span>
                    </div>
                `;
            }
            
            if (flights.length > 20) {
                html += `<div class="more-flights">+${flights.length - 20} more</div>`;
            }
            
            html += '</div></div>';
        }
        
        html += '</div>';
        return html;
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
    // EVENT HANDLING
    // =========================================================================
    
    function onUpdate(callback) {
        if (typeof callback === 'function') {
            onUpdateCallbacks.push(callback);
        }
    }
    
    function notifyUpdate() {
        onUpdateCallbacks.forEach(cb => cb(stats, affectedFlights));
    }

    // =========================================================================
    // PUBLIC API
    // =========================================================================
    
    return {
        init: init,
        refresh: refresh,
        fetchSummary: fetchSummary,
        fetchFlightImpact: fetchFlightImpact,
        
        // Badge helpers
        getBadgeHtml: getBadgeHtml,
        getBadgeClass: getBadgeClass,
        isAffected: isAffected,
        getImpact: getImpact,
        
        // Panel builders
        buildSummaryPanel: buildSummaryPanel,
        buildAffectedList: buildAffectedList,
        
        // Event handling
        onUpdate: onUpdate,
        
        // State access
        getStats: () => stats,
        getAffectedFlights: () => affectedFlights,
        getAffectedCount: () => affectedFlights.size,
        
        // Control
        startAutoRefresh: startAutoRefresh,
        stopAutoRefresh: stopAutoRefresh
    };
})();

// Export for module systems
if (typeof module !== 'undefined' && module.exports) {
    module.exports = WeatherImpact;
}
