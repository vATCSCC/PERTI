/**
 * PERTI Global Constants
 *
 * Centralized configuration for timing, thresholds, and magic numbers.
 * Import this file before any scripts that need these values.
 *
 * @package PERTI
 * @subpackage Assets/JS/Config
 */

(function(global) {
    'use strict';

    // ===========================================
    // TIME UNIT CONVERSIONS
    // ===========================================

    const TIME = {
        SECOND_MS: 1000,
        MINUTE_MS: 60 * 1000,
        HOUR_MS: 60 * 60 * 1000,
        DAY_MS: 24 * 60 * 60 * 1000,

        // Epoch threshold for seconds vs milliseconds detection
        // Year 2100 in Unix seconds = 4102444800
        // If a timestamp < this, it's in seconds and needs *1000
        EPOCH_SECONDS_THRESHOLD: 4102444800,
    };

    // ===========================================
    // REFRESH INTERVALS
    // ===========================================

    const REFRESH = {
        // Fast refresh (15 seconds) - real-time data
        TRAFFIC: 15000,
        DEMAND: 15000,
        ROUTE: 15000,
        REROUTE: 15000,
        FLIGHT_STATS: 15000,

        // Medium refresh (30 seconds)
        TMI_STAGED: 30000,
        ADVISORIES: 30000,
        JATOC: 30000,
        WEATHER_IMPACT: 30000,
        DEMAND_LAYER: 30000,
        PUBLIC_ROUTES: 30000,

        // Slow refresh (60 seconds)
        TMI_ACTIVE: 60000,
        TMU_OPS_LEVEL: 60000,
        INITIATIVE: 60000,
        ROUTE_DATA_TIMEOUT: 60000,

        // Clock updates (1 second)
        CLOCK: 1000,
        UTC_CLOCK: 1000,
        LOCAL_CLOCK: 1000,
    };

    // ===========================================
    // ENRICH/CACHE CONFIGURATION (GDT)
    // ===========================================

    const ENRICH_CACHE = {
        MIN_INTERVAL_MS: 90 * 1000,           // Min time between enrich batches
        CALLSIGN_MIN_MS: 8 * 60 * 1000,       // Min time before re-enriching same callsign
        SUCCESS_TTL_MS: 10 * 60 * 1000,       // How long to cache successful lookups
        NEGATIVE_TTL_MS: 2 * 60 * 1000,       // How long to cache failed lookups
        MAX_DELAY_MS: 10000,                  // Max delay for rate limiting
        ERROR_WINDOW_MS: 60 * 1000,           // Error tracking window
        COOLDOWN_429_MS: 90 * 1000,           // Cooldown after rate limit (429)
        COOLDOWN_5XX_MS: 3 * 60 * 1000,       // Cooldown after server error (5xx)
        MAX_ENTRIES: 500,                     // Max cache entries before cleanup
    };

    // ===========================================
    // UI DELAYS & DEBOUNCE
    // ===========================================

    const UI = {
        DEBOUNCE_MS: 500,
        ANIMATION_DELAY_MS: 500,
        STATUS_MESSAGE_TIMEOUT_MS: 3000,
        WEATHER_ANIMATION_SPEED_MS: 500,
    };

    // ===========================================
    // ALTITUDE & DISTANCE THRESHOLDS
    // ===========================================

    const THRESHOLDS = {
        // Altitude threshold for ARR/DEP classification (feet)
        ALTITUDE_ARR_DEP: 10000,

        // Delay thresholds for coloring (minutes)
        DELAY_WARNING_MIN: 30,
        DELAY_CRITICAL_MIN: 60,

        // EDCT adjustment (minutes)
        EDCT_ADJUSTMENT_MIN: 15,

        // Label proximity/grouping thresholds (milliseconds)
        LABEL_PROXIMITY_MS: 30 * 60 * 1000,
        LABEL_GROUPING_MS: 30 * 60 * 1000,
        LABEL_STACK_MS: 30 * 60 * 1000,
    };

    // ===========================================
    // DATA LIMITS & PAGINATION
    // ===========================================

    const LIMITS = {
        // Default query/display limits
        DEFAULT_PAGE_SIZE: 50,
        MAX_PAGE_SIZE: 500,
        ADL_DEFAULT_LIMIT: 10000,
        MAX_CDR_SEARCH_RESULTS: 200,
        MAX_ROUTES_TO_PROCESS: 50,

        // Cache limits
        GDT_CACHE_MAX_ENTRIES: 500,

        // Text formatting
        ADVISORY_MAX_LINE_LENGTH: 68,
        DISCORD_MAX_MESSAGE_LENGTH: 2000,

        // Distance defaults
        DEFAULT_GDP_DISTANCE_NM: 500,
        MAX_SCOPE_RETRIES: 20,
    };

    // ===========================================
    // DEFAULT TIME WINDOWS
    // ===========================================

    const TIME_WINDOWS = {
        // Demand chart default window
        DEMAND_START_OFFSET_MS: -2 * TIME.HOUR_MS,
        DEMAND_END_OFFSET_MS: 14 * TIME.HOUR_MS,
    };

    // ===========================================
    // EARTH RADIUS (for distance calculations)
    // ===========================================

    const EARTH = {
        RADIUS_KM: 6371,
        RADIUS_NM: 3440.065,
    };

    // ===========================================
    // REGIONAL CARRIERS (for filtering)
    // ===========================================

    const REGIONAL_CARRIERS = [
        'SKW', 'RPA', 'ENY', 'PDT', 'PSA', 'ASQ', 'GJS', 'CPZ',
        'EDV', 'QXE', 'ASH', 'OO', 'AIP', 'MES', 'JIA', 'SCX',
    ];

    // ===========================================
    // HELPER FUNCTIONS
    // ===========================================

    /**
     * Convert epoch timestamp to milliseconds, auto-detecting seconds vs ms
     * @param {number} epoch - Epoch timestamp (seconds or milliseconds)
     * @returns {number} - Epoch in milliseconds
     */
    function toMilliseconds(epoch) {
        if (typeof epoch !== 'number') {return null;}
        return epoch < TIME.EPOCH_SECONDS_THRESHOLD ? epoch * 1000 : epoch;
    }

    // ===========================================
    // EXPORT
    // ===========================================

    global.PERTIConstants = {
        TIME,
        REFRESH,
        ENRICH_CACHE,
        UI,
        THRESHOLDS,
        LIMITS,
        TIME_WINDOWS,
        EARTH,
        REGIONAL_CARRIERS,
        toMilliseconds,
    };

})(typeof window !== 'undefined' ? window : this);
