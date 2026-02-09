/**
 * PERTI Color Palette
 *
 * Centralized color definitions for all visualizations.
 * Use these instead of hardcoded hex values throughout codebase.
 *
 * IMPORTANT: These colors are for JavaScript contexts (Canvas, MapLibre, Charts)
 * where CSS variables cannot be used. For DOM elements, prefer using CSS
 * variables from perti-colors.css instead.
 *
 * These values are synchronized with /assets/css/perti-colors.css
 *
 * NOTE: This module uses PERTI namespace for DCC region lookups when available.
 * Load lib/perti.js before this file for full integration.
 *
 * @module lib/colors
 * @version 1.2.0
 */

const PERTIColors = (function() {
    'use strict';

    // Reference to PERTI namespace if available
    const _PERTI = (typeof PERTI !== 'undefined') ? PERTI : null;

    // ========================================
    // BRAND COLORS (matches perti-colors.css)
    // ========================================
    const brand = {
        primary: '#766df4',        // --brand-primary
        primaryLight: '#9990f7',   // --brand-primary-light
        primaryDark: '#5a4fd1',    // --brand-primary-dark
        secondary: '#f7f7fc',      // --brand-secondary
        accent: '#6a9bf4',         // --brand-accent
    };

    // ========================================
    // STATUS COLORS (matches perti-colors.css)
    // ========================================
    const semantic = {
        primary: '#766df4',   // --brand-primary (PERTI Purple)
        secondary: '#6c757d', // Bootstrap secondary (for compatibility)
        success: '#16c995',   // --status-success
        danger: '#f74f78',    // --status-danger
        warning: '#ffb15c',   // --status-warning
        info: '#6a9bf4',      // --status-info
        light: '#f7f7fc',     // --brand-secondary
        dark: '#1a1a2e',      // --dark-bg-page
        white: '#ffffff',
        black: '#000000',
    };

    // ========================================
    // FLIGHT WEIGHT CLASSES
    // ========================================
    const weightClass = {
        J: '#ffc107',    // Super - Amber
        H: '#dc3545',    // Heavy - Red
        L: '#28a745',    // Large - Green
        S: '#17a2b8',    // Small - Cyan
        UNKNOWN: '#6c757d',
    };

    // ========================================
    // FLIGHT RULES
    // FAA flight plan types: I=IFR, V=VFR, Y=IFR/VFR, Z=VFR/IFR
    // Special VFR types: D=DVFR (Defense), S=SVFR (Special)
    // ========================================
    const flightRules = {
        I: '#007bff',    // IFR - Blue
        V: '#28a745',    // VFR - Green
        Y: '#fd7e14',    // Y-IFR (IFR then VFR) - Orange
        Z: '#e83e8c',    // Z-VFR (VFR then IFR) - Pink
        D: '#9c27b0',    // DVFR (Defense VFR) - Purple
        S: '#17a2b8',    // SVFR (Special VFR) - Cyan
    };

    // ========================================
    // FLIGHT PHASES
    // ========================================
    const phase = {
        arrived: '#1a1a1a',          // Black - Landed
        disconnected: '#f97316',     // Orange - Mid-flight disconnect
        descending: '#991b1b',       // Dark Red - On approach
        enroute: '#dc2626',          // Red - Cruising
        departed: '#f87171',         // Light Red - Just took off
        taxiing: '#22c55e',          // Green - Ground movement
        prefile: '#3b82f6',          // Blue - Filed, not connected
        unknown: '#9333ea',          // Purple - Unknown phase

        // TMI phases
        actual_gs: '#eab308',        // Yellow - GS EDCT issued
        simulated_gs: '#fef08a',     // Light Yellow - GS simulated
        proposed_gs: '#ca8a04',      // Gold - GS proposed
        actual_gdp: '#92400e',       // Brown - GDP EDCT issued
        simulated_gdp: '#d4a574',    // Tan - GDP simulated
        proposed_gdp: '#78350f',     // Dark Brown - GDP proposed
        exempt: '#6b7280',           // Gray - Exempt from TMI
    };

    // ========================================
    // DCC REGIONS
    // Colors for DCC regions - uses PERTI as source of truth when available
    // ========================================
    const region = (_PERTI && _PERTI.GEOGRAPHIC && _PERTI.GEOGRAPHIC.DCC_REGIONS) ? (function() {
        // Build color map from PERTI.GEOGRAPHIC.DCC_REGIONS
        const colors = {};
        Object.entries(_PERTI.GEOGRAPHIC.DCC_REGIONS).forEach(([key, data]) => {
            colors[key] = data.color;
        });
        return colors;
    })() : {
        // Fallback when PERTI not loaded
        WEST: '#dc3545',
        SOUTH_CENTRAL: '#fd7e14',
        MIDWEST: '#28a745',
        SOUTHEAST: '#ffc107',
        NORTHEAST: '#007bff',
        CANADA: '#6f42c1',
        OTHER: '#6c757d',
    };

    // ARTCC/FIR to DCC Region mapping - uses PERTI as source of truth
    const regionMapping = (_PERTI && _PERTI.GEOGRAPHIC && _PERTI.GEOGRAPHIC.ARTCC_TO_DCC) ? _PERTI.GEOGRAPHIC.ARTCC_TO_DCC : {
        // Fallback when PERTI not loaded
        ZAK: 'WEST', ZAN: 'WEST', ZHN: 'WEST', ZLA: 'WEST',
        ZLC: 'WEST', ZOA: 'WEST', ZSE: 'WEST',
        ZAB: 'SOUTH_CENTRAL', ZFW: 'SOUTH_CENTRAL', ZHO: 'SOUTH_CENTRAL',
        ZHU: 'SOUTH_CENTRAL', ZME: 'SOUTH_CENTRAL',
        ZAU: 'MIDWEST', ZDV: 'MIDWEST', ZKC: 'MIDWEST', ZMP: 'MIDWEST',
        ZID: 'SOUTHEAST', ZJX: 'SOUTHEAST', ZMA: 'SOUTHEAST',
        ZMO: 'SOUTHEAST', ZTL: 'SOUTHEAST',
        ZBW: 'NORTHEAST', ZDC: 'NORTHEAST', ZNY: 'NORTHEAST',
        ZOB: 'NORTHEAST', ZWY: 'NORTHEAST',
        CZYZ: 'CANADA', CZUL: 'CANADA',
        CZQM: 'CANADA', CZQX: 'CANADA', CZQO: 'CANADA',
        CZWG: 'CANADA', CZEG: 'CANADA', CZVR: 'CANADA',
    };

    // ========================================
    // OPERATOR GROUPS
    // Classification by carrier type
    // ========================================
    const operatorGroup = {
        MAJOR: '#dc3545',      // Red - Major carriers (AAL, UAL, DAL, etc.)
        REGIONAL: '#28a745',   // Green - Regional carriers (SKW, RPA, etc.)
        FREIGHT: '#007bff',    // Blue - Freight/cargo (FDX, UPS, etc.)
        GA: '#ffc107',         // Yellow - General aviation
        MILITARY: '#6f42c1',   // Purple - Military (RCH, REACH, etc.)
        OTHER: '#6c757d',      // Gray - Unclassified
    };

    // ========================================
    // WEATHER CONDITIONS
    // ========================================
    // Uses PERTI.WEATHER.CATEGORIES as source of truth when available
    const weather = (_PERTI && _PERTI.WEATHER && _PERTI.WEATHER.CATEGORIES) ? (function() {
        var cats = _PERTI.WEATHER.CATEGORIES, m = {};
        Object.keys(cats).forEach(function(k) { m[k] = cats[k].color; });
        return m;
    })() : {
        VMC: '#22c55e',    // Green - Visual conditions
        LVMC: '#eab308',   // Yellow - Low VMC
        IMC: '#f97316',    // Orange - Instrument conditions
        LIMC: '#ef4444',   // Red - Low IMC
        VLIMC: '#dc2626',  // Dark Red - Very Low IMC
    };

    // ========================================
    // RADAR REFLECTIVITY (dBZ)
    // ========================================
    const radarDbz = {
        5: '#04e9e7',     // Very Light
        10: '#019ff4',
        15: '#0300f4',
        20: '#02fd02',    // Light
        25: '#01c501',
        30: '#008e00',    // Moderate
        35: '#fffe00',
        40: '#e5bc00',    // Heavy
        45: '#fd9500',
        50: '#fd0000',    // Extreme
        55: '#d40000',
        60: '#bc0000',
        65: '#f800fd',    // Hail
    };

    // ========================================
    // TMI RATE DISPLAY
    // ========================================
    const rate = {
        vatsimActive: '#000000',    // Black - Strategic rates
        realWorldActive: '#00FFFF', // Cyan - RW rates
        vatsimSuggested: '#6b7280', // Gray - Inferred rates
        realWorldSuggested: '#0d9488', // Teal - RW inferred
    };

    // ========================================
    // STATUS INDICATORS (matches perti-colors.css)
    // ========================================
    const status = {
        active: '#16c995',     // --status-success
        pending: '#ffb15c',    // --status-warning
        expired: '#6b7280',    // Gray (muted)
        cancelled: '#f74f78',  // --status-danger
        draft: '#6a9bf4',      // --status-info
    };

    // ========================================
    // AIRSPACE BOUNDARIES & SECTORS
    // Used for ARTCC/TRACON/Sector visualization
    // ========================================
    const airspace = {
        // Sector strata - fill and boundary colors
        low: '#228B22',        // Forest Green - Low altitude sectors
        high: '#FF6347',       // Tomato Red - High altitude sectors
        superhigh: '#9932CC',  // Purple - Super-high altitude sectors

        // Facility boundary colors
        artcc: '#4682B4',      // Steel Blue - ARTCC/FIR boundaries
        tracon: '#20B2AA',     // Light Sea Green - TRACON boundaries

        // Sector boundary line colors (for base map overlays)
        sectorLine: '#505050',     // Medium gray - Generic sector lines
        sectorLineDark: '#303030', // Darker gray - Sector lines (low/high/superhigh overlays)
        artccLine: '#515151',      // ARTCC boundary lines on base maps

        // Unassigned/neutral sectors
        unassigned: '#444444', // Dark gray - Sectors not assigned to a position
        unassignedLabel: '#666666',
    };

    // ========================================
    // TMI COMPLIANCE ANALYSIS
    // Neutral data distinction (not judgmental)
    // ========================================
    const tmiCompliance = {
        neutral: '#6b7280',        // Compliant pairs - baseline gray
        attention: '#374151',      // Non-compliant pairs - darker, draws eye
        scaleBar: '#374151',       // Required spacing reference
        gapIndicator: '#9ca3af',   // Time gaps between non-consecutive flights
        flowConeFill: 'rgba(107, 114, 128, 0.12)',
        flowConeBorder: 'rgba(107, 114, 128, 0.35)',
        streamHighlight: '#4b5563',
    };

    // ========================================
    // CSS VARIABLE REFERENCES
    // Use these when you need to get CSS variable values in JS
    // ========================================
    const cssVar = {
        /**
         * Get a CSS variable value from the document root
         * @param {string} name - Variable name without -- prefix
         * @returns {string} Color value
         */
        get(name) {
            return getComputedStyle(document.documentElement)
                .getPropertyValue('--' + name).trim();
        },

        /**
         * Get brand primary color from CSS
         * @returns {string}
         */
        brandPrimary() { return this.get('brand-primary'); },

        /**
         * Get status success color from CSS
         * @returns {string}
         */
        statusSuccess() { return this.get('status-success'); },

        /**
         * Get status danger color from CSS
         * @returns {string}
         */
        statusDanger() { return this.get('status-danger'); },

        /**
         * Get status warning color from CSS
         * @returns {string}
         */
        statusWarning() { return this.get('status-warning'); },

        /**
         * Get status info color from CSS
         * @returns {string}
         */
        statusInfo() { return this.get('status-info'); },
    };

    // ========================================
    // CHART PALETTES
    // ========================================
    const chartPalette = {
        // 12-color categorical palette for charts
        categorical: [
            '#3b82f6', '#ef4444', '#22c55e', '#f59e0b',
            '#8b5cf6', '#ec4899', '#06b6d4', '#84cc16',
            '#f97316', '#6366f1', '#14b8a6', '#a855f7',
        ],

        // Sequential blue palette (light to dark)
        sequentialBlue: [
            '#dbeafe', '#bfdbfe', '#93c5fd', '#60a5fa',
            '#3b82f6', '#2563eb', '#1d4ed8', '#1e40af',
        ],

        // Diverging red-blue palette
        diverging: [
            '#dc2626', '#f87171', '#fca5a5', '#fecaca',
            '#e0e7ff', '#a5b4fc', '#818cf8', '#4f46e5',
        ],
    };

    // ========================================
    // UTILITY FUNCTIONS
    // ========================================

    /**
     * Convert hex color to RGBA
     * @param {string} hex - Hex color (with or without #)
     * @param {number} alpha - Alpha value 0-1
     * @returns {string} RGBA string
     */
    function hexToRgba(hex, alpha = 1) {
        const h = hex.replace('#', '');
        const r = parseInt(h.slice(0, 2), 16);
        const g = parseInt(h.slice(2, 4), 16);
        const b = parseInt(h.slice(4, 6), 16);
        return `rgba(${r}, ${g}, ${b}, ${alpha})`;
    }

    /**
     * Convert hex to RGB array [r, g, b] normalized 0-1
     * @param {string} hex
     * @returns {number[]}
     */
    function hexToRgbNormalized(hex) {
        const h = hex.replace('#', '');
        return [
            parseInt(h.slice(0, 2), 16) / 255,
            parseInt(h.slice(2, 4), 16) / 255,
            parseInt(h.slice(4, 6), 16) / 255,
        ];
    }

    /**
     * Convert hex to RGB array [r, g, b] 0-255
     * @param {string} hex
     * @returns {number[]}
     */
    function hexToRgb(hex) {
        const h = hex.replace('#', '');
        return [
            parseInt(h.slice(0, 2), 16),
            parseInt(h.slice(2, 4), 16),
            parseInt(h.slice(4, 6), 16),
        ];
    }

    /**
     * Lighten a color by percentage
     * @param {string} hex
     * @param {number} percent 0-100
     * @returns {string}
     */
    function lighten(hex, percent) {
        const [r, g, b] = hexToRgb(hex);
        const amt = Math.round(2.55 * percent);
        return '#' + [
            Math.min(255, r + amt),
            Math.min(255, g + amt),
            Math.min(255, b + amt),
        ].map(c => c.toString(16).padStart(2, '0')).join('');
    }

    /**
     * Darken a color by percentage
     * @param {string} hex
     * @param {number} percent 0-100
     * @returns {string}
     */
    function darken(hex, percent) {
        const [r, g, b] = hexToRgb(hex);
        const amt = Math.round(2.55 * percent);
        return '#' + [
            Math.max(0, r - amt),
            Math.max(0, g - amt),
            Math.max(0, b - amt),
        ].map(c => c.toString(16).padStart(2, '0')).join('');
    }

    /**
     * Get color for a weight class
     * @param {string} wtc - Weight class code (J, H, L, S)
     * @returns {string} Hex color
     */
    function forWeightClass(wtc) {
        return weightClass[wtc] || weightClass.UNKNOWN;
    }

    /**
     * Get color for a flight phase
     * @param {string} phaseName
     * @returns {string} Hex color
     */
    function forPhase(phaseName) {
        return phase[phaseName] || phase.unknown;
    }

    /**
     * Get color for a region
     * @param {string} regionName
     * @returns {string} Hex color
     */
    function forRegion(regionName) {
        return region[regionName] || region.OTHER;
    }

    /**
     * Get DCC region color for an ARTCC/FIR code
     * @param {string} artcc - ARTCC or FIR code (e.g., 'ZNY', 'CZYZ')
     * @returns {string} Hex color based on DCC region
     */
    function forARTCC(artcc) {
        // Use PERTI helper if available
        if (_PERTI) {
            return _PERTI.getDCCColor(_PERTI.getDCCRegion(artcc));
        }
        const regionName = regionMapping[artcc];
        return region[regionName] || region.OTHER;
    }

    /**
     * Get DCC region name for an ARTCC/FIR code
     * @param {string} artcc - ARTCC or FIR code
     * @returns {string} Region name (e.g., 'NORTHEAST', 'CANADA')
     */
    function getRegion(artcc) {
        // Use PERTI helper if available
        if (_PERTI) {
            return _PERTI.getDCCRegion(artcc);
        }
        return regionMapping[artcc] || 'OTHER';
    }

    /**
     * Get color for weather category
     * @param {string} cat - VMC, LVMC, IMC, LIMC, VLIMC
     * @returns {string} Hex color
     */
    function forWeather(cat) {
        return weather[cat] || semantic.secondary;
    }

    /**
     * Get color for operator group
     * @param {string} group - MAJOR, REGIONAL, FREIGHT, GA, MILITARY, OTHER
     * @returns {string} Hex color
     */
    function forOperatorGroup(group) {
        return operatorGroup[group] || operatorGroup.OTHER;
    }

    /**
     * Get color from categorical palette by index
     * @param {number} index
     * @returns {string} Hex color
     */
    function categorical(index) {
        return chartPalette.categorical[index % chartPalette.categorical.length];
    }

    /**
     * Get color for airspace/sector type
     * @param {string} type - low, high, superhigh, artcc, tracon
     * @returns {string} Hex color
     */
    function forAirspace(type) {
        return airspace[type] || airspace.sectorLine;
    }

    // Public API
    return {
        // Brand colors (matches perti-colors.css)
        brand,

        // Color palettes
        semantic,
        weightClass,
        flightRules,
        phase,
        region,
        regionMapping,  // ARTCC/FIR to DCC region mapping
        operatorGroup,  // Carrier group colors (MAJOR, REGIONAL, etc.)
        weather,
        radarDbz,
        rate,
        status,
        airspace,
        tmiCompliance,
        chartPalette,

        // CSS variable access
        cssVar,

        // Utility functions
        hexToRgba,
        hexToRgbNormalized,
        hexToRgb,
        lighten,
        darken,

        // Lookup functions
        forWeightClass,
        forPhase,
        forRegion,
        forARTCC,         // Get DCC region color for ARTCC
        getRegion,        // Get DCC region name for ARTCC
        forOperatorGroup, // Get color for operator group
        forWeather,
        forAirspace,
        categorical,
    };
})();

// Export for ES modules if available
if (typeof module !== 'undefined' && module.exports) {
    module.exports = PERTIColors;
}
