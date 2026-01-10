/**
 * PERTI Rate Line Configuration
 *
 * Unified styling for AAR/ADR rate lines across all pages.
 * Edit colors and styles here to update them everywhere (demand, status, etc.)
 *
 * RATE TYPES:
 *   AAR = Airport Arrival Rate (solid line)
 *   ADR = Airport Departure Rate (dashed line)
 *
 * SOURCES:
 *   VATSIM = Rates configured for VATSIM operations
 *   RW = Real-world rates from FAA capacity data
 *
 * STATES:
 *   Active = Airport has controller with ATIS (rates are confirmed)
 *   Suggested = No ATIS, rates inferred from weather/default config
 */

// Rate line styling configuration
const RATE_LINE_CONFIG = {
    // Active rates (when airport has controller with ATIS)
    active: {
        vatsim: {
            color: '#FFFFFF',
            label: 'VATSIM'
        },
        rw: {
            color: '#00FFFF',
            label: 'Real World'
        }
    },

    // Suggested rates (no controller, based on weather/config matching)
    suggested: {
        vatsim: {
            color: '#888888',
            label: 'VATSIM (Suggested)'
        },
        rw: {
            color: '#008080',
            label: 'Real World (Suggested)'
        }
    },

    // Line styles by rate type
    lineStyle: {
        aar: {
            type: 'solid',
            width: 2
        },
        adr: {
            type: 'dashed',
            width: 2,
            dashOffset: 0
        }
    },

    // Label positioning and styling
    label: {
        position: 'end',           // end, start, insideEndTop, insideStartTop
        fontSize: 10,
        fontWeight: 'bold',
        fontFamily: '"Roboto Mono", monospace',
        distance: 5,               // Distance from line end
        backgroundColor: 'rgba(0,0,0,0.6)',
        padding: [2, 4],
        borderRadius: 2
    },

    // Weather category display colors (for info panels)
    weatherColors: {
        'VMC': '#22c55e',          // Green - Visual conditions
        'LVMC': '#eab308',         // Yellow - Low VMC
        'IMC': '#f97316',          // Orange - Instrument conditions
        'LIMC': '#ef4444',         // Red - Low IMC
        'VLIMC': '#dc2626'         // Dark red - Very low IMC
    },

    // Weather category labels
    weatherLabels: {
        'VMC': 'VMC',
        'LVMC': 'Low VMC',
        'IMC': 'IMC',
        'LIMC': 'Low IMC',
        'VLIMC': 'Very Low IMC'
    }
};

/**
 * Build rate mark lines for ECharts
 * @param {Object} rateData - Rate data from API
 * @param {string} direction - 'arr' or 'dep' to filter which rates to show
 * @returns {Array} Array of markLine data objects
 */
function buildRateMarkLines(rateData, direction = 'both') {
    if (!rateData || !rateData.rates) return [];

    const lines = [];
    const cfg = RATE_LINE_CONFIG;
    const styleKey = rateData.is_suggested ? 'suggested' : 'active';

    // Helper to create a rate line
    const addLine = (value, source, rateType) => {
        if (!value) return;

        const sourceStyle = cfg[styleKey][source];
        const lineStyle = cfg.lineStyle[rateType];
        const label = rateType.toUpperCase();

        lines.push({
            yAxis: value,
            lineStyle: {
                color: sourceStyle.color,
                width: lineStyle.width,
                type: lineStyle.type
            },
            label: {
                show: true,
                formatter: `${label} ${value}`,
                position: cfg.label.position,
                color: sourceStyle.color,
                fontSize: cfg.label.fontSize,
                fontWeight: cfg.label.fontWeight,
                fontFamily: cfg.label.fontFamily,
                backgroundColor: cfg.label.backgroundColor,
                padding: cfg.label.padding,
                borderRadius: cfg.label.borderRadius
            }
        });
    };

    // Add VATSIM rates
    if (direction === 'both' || direction === 'arr') {
        addLine(rateData.rates.vatsim_aar, 'vatsim', 'aar');
    }
    if (direction === 'both' || direction === 'dep') {
        addLine(rateData.rates.vatsim_adr, 'vatsim', 'adr');
    }

    // Add Real World rates
    if (direction === 'both' || direction === 'arr') {
        addLine(rateData.rates.rw_aar, 'rw', 'aar');
    }
    if (direction === 'both' || direction === 'dep') {
        addLine(rateData.rates.rw_adr, 'rw', 'adr');
    }

    return lines;
}

/**
 * Get weather category color
 * @param {string} category - Weather category (VMC, LVMC, IMC, LIMC, VLIMC)
 * @returns {string} Hex color code
 */
function getWeatherColor(category) {
    return RATE_LINE_CONFIG.weatherColors[category] || '#999999';
}

/**
 * Get weather category label
 * @param {string} category - Weather category code
 * @returns {string} Human-readable label
 */
function getWeatherLabel(category) {
    return RATE_LINE_CONFIG.weatherLabels[category] || category;
}

/**
 * Format rate display string
 * @param {number} aar - Arrival rate
 * @param {number} adr - Departure rate
 * @returns {string} Formatted string like "44/48" or "--/--"
 */
function formatRateDisplay(aar, adr) {
    const arrStr = aar ? aar.toString() : '--';
    const depStr = adr ? adr.toString() : '--';
    return `${arrStr}/${depStr}`;
}

// Export for use in other scripts (if using modules)
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        RATE_LINE_CONFIG,
        buildRateMarkLines,
        getWeatherColor,
        getWeatherLabel,
        formatRateDisplay
    };
}
