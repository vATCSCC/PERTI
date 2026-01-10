/**
 * PERTI Phase Color Configuration
 *
 * Unified color scheme for flight phases across all pages.
 * Edit colors here to update them everywhere (demand, status, etc.)
 *
 * STACKING ORDER (bottom to top on chart):
 *   arrived -> disconnected -> descending -> enroute -> departed -> taxiing -> prefile -> unknown
 */

// Flight phase colors for visualization
const PHASE_COLORS = {
    'arrived': '#1a1a1a',       // Black - Landed at destination
    'disconnected': '#f97316',  // Bright Orange - Disconnected mid-flight
    'descending': '#991b1b',    // Dark Red - On approach to destination
    'enroute': '#dc2626',       // Red - Cruising
    'departed': '#f87171',      // Light Red - Just took off from origin
    'taxiing': '#22c55e',       // Green - Taxiing at airport
    'prefile': '#3b82f6',       // Blue - Filed flight plan
    'unknown': '#eab308'        // Yellow - Unknown/other phase
};

// Phase display names for legend/tooltips
const PHASE_LABELS = {
    'arrived': 'Arrived',
    'disconnected': 'Disconnected',
    'descending': 'Descending',
    'enroute': 'Enroute',
    'departed': 'Departed',
    'taxiing': 'Taxiing',
    'prefile': 'Prefile',
    'unknown': 'Unknown'
};

// Phase descriptions for tooltips/legends
const PHASE_DESCRIPTIONS = {
    'arrived': 'landed',
    'disconnected': 'disconnected',
    'descending': 'approach',
    'enroute': 'cruising',
    'departed': 'climbing',
    'taxiing': 'at origin',
    'prefile': 'filed',
    'unknown': ''
};

// Phase stacking order for main chart (bottom to top)
// These phases stack on top of each other in the bar chart
// Prefile is between taxiing and unknown
const PHASE_STACK_ORDER = ['arrived', 'disconnected', 'descending', 'enroute', 'departed', 'taxiing', 'prefile', 'unknown'];

// Phases displayed on separate axis (not stacked with main phases)
// Currently empty - all phases are stacked together
const PHASE_SEPARATE_AXIS = [];

// Complete phase order for iteration (same as stack order now)
const PHASE_ORDER = [...PHASE_STACK_ORDER, ...PHASE_SEPARATE_AXIS];

// Bootstrap badge classes for each phase
const PHASE_BADGE_CLASSES = {
    'arrived': 'badge-dark',
    'disconnected': 'badge-warning',
    'descending': 'badge-danger',
    'enroute': 'badge-danger',
    'departed': 'badge-danger',
    'taxiing': 'badge-success',
    'prefile': 'badge-primary',
    'unknown': 'badge-secondary'
};

// Helper function to get phase color
function getPhaseColor(phase) {
    return PHASE_COLORS[phase] || '#999999';
}

// Helper function to get phase label
function getPhaseLabel(phase) {
    return PHASE_LABELS[phase] || phase;
}

// Helper function to get phase badge class
function getPhaseBadgeClass(phase) {
    return PHASE_BADGE_CLASSES[phase] || 'badge-secondary';
}

// Helper function to lighten a color (for departure bars)
function lightenColor(hex, percent) {
    const num = parseInt(hex.replace('#', ''), 16);
    const amt = Math.round(2.55 * percent * 100);
    const R = Math.min(255, Math.max(0, (num >> 16) + amt));
    const G = Math.min(255, Math.max(0, ((num >> 8) & 0x00FF) + amt));
    const B = Math.min(255, Math.max(0, (num & 0x0000FF) + amt));
    return '#' + (0x1000000 + R * 0x10000 + G * 0x100 + B).toString(16).slice(1);
}

// Export for use in other scripts (if using modules)
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        PHASE_COLORS,
        PHASE_LABELS,
        PHASE_DESCRIPTIONS,
        PHASE_ORDER,
        PHASE_BADGE_CLASSES,
        getPhaseColor,
        getPhaseLabel,
        getPhaseBadgeClass,
        lightenColor
    };
}
