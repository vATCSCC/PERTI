/**
 * PERTI Phase Color Configuration
 *
 * Unified color scheme for flight phases across all pages.
 * Edit colors here to update them everywhere (demand, status, etc.)
 *
 * STACKING ORDER (bottom to top on chart):
 *   arrived -> disconnected -> descending -> enroute -> departed -> taxiing -> prefile ->
 *   actual_gs -> simulated_gs -> proposed_gs -> actual_gdp -> simulated_gdp -> proposed_gdp -> unknown
 *
 * GS/GDP Status Colors (FAA-style):
 *   - Actual: EDCT has been issued
 *   - Simulated: Model run but not issued
 *   - Proposed: Created but not modeled
 *
 * i18n Support:
 *   - PHASE_LABEL_KEYS maps phases to i18n keys
 *   - getPhaseLabel() uses PERTII18n.t() when available
 *   - Fallback to hardcoded PHASE_LABELS if i18n not loaded
 */

// Flight phase colors for visualization
const PHASE_COLORS = {
    // Standard flight phases
    'arrived': '#1a1a1a',           // Black - Landed at destination
    'disconnected': '#f97316',      // Bright Orange - Disconnected mid-flight
    'descending': '#991b1b',        // Dark Red - On approach to destination
    'enroute': '#dc2626',           // Red - Cruising
    'departed': '#f87171',          // Light Red - Just took off from origin
    'taxiing': '#22c55e',           // Green - Taxiing at airport
    'prefile': '#3b82f6',           // Blue - Filed flight plan

    // Ground Stop statuses (yellow spectrum)
    'actual_gs': '#eab308',         // Yellow - EDCT issued (FAA style)
    'simulated_gs': '#fef08a',      // Light Yellow - Simulated but not issued
    'proposed_gs': '#ca8a04',       // Dark Yellow/Gold - Proposed but not modeled
    'gs': '#eab308',                // Yellow - Generic GS (same as actual)

    // Ground Delay Program statuses (brown spectrum)
    'actual_gdp': '#92400e',        // Brown - EDCT issued (FAA style)
    'simulated_gdp': '#d4a574',     // Light Brown/Tan - Simulated but not issued
    'proposed_gdp': '#78350f',      // Dark Brown - Proposed but not modeled
    'gdp': '#92400e',               // Brown - Generic GDP (same as actual)

    // Exempt flights
    'exempt': '#6b7280',            // Gray - Exempt from TMI

    // Uncontrolled (not assigned to any TMI)
    'uncontrolled': '#94a3b8',      // Light Gray - Not controlled by any TMI

    // Unknown/other
    'unknown': '#9333ea',            // Purple - Unknown/other phase (changed from yellow)
};

// Phase display names for legend/tooltips (fallback if i18n not loaded)
const PHASE_LABELS = {
    'arrived': 'Arrived',
    'disconnected': 'Disconnected',
    'descending': 'Descending',
    'enroute': 'Enroute',
    'departed': 'Departed',
    'taxiing': 'Taxiing',
    'prefile': 'Prefile',
    'actual_gs': 'GS (EDCT)',
    'simulated_gs': 'GS (Simulated)',
    'proposed_gs': 'GS (Proposed)',
    'gs': 'Ground Stop',
    'actual_gdp': 'GDP (EDCT)',
    'simulated_gdp': 'GDP (Simulated)',
    'proposed_gdp': 'GDP (Proposed)',
    'gdp': 'GDP',
    'exempt': 'Exempt',
    'uncontrolled': 'Uncontrolled',
    'unknown': 'Unknown',
};

// i18n keys for phase labels (used when PERTII18n is available)
const PHASE_LABEL_KEYS = {
    'arrived': 'phase.arrived',
    'disconnected': 'phase.disconnected',
    'descending': 'phase.descending',
    'enroute': 'phase.enroute',
    'departed': 'phase.departed',
    'taxiing': 'phase.taxiing',
    'prefile': 'phase.prefile',
    'actual_gs': 'tmi.actualGs',
    'simulated_gs': 'tmi.simulatedGs',
    'proposed_gs': 'tmi.proposedGs',
    'gs': 'tmi.gs',
    'actual_gdp': 'tmi.actualGdp',
    'simulated_gdp': 'tmi.simulatedGdp',
    'proposed_gdp': 'tmi.proposedGdp',
    'gdp': 'tmi.gdpShort',
    'exempt': 'tmi.exempt',
    'uncontrolled': 'tmi.uncontrolled',
    'unknown': 'common.unknown',
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
    'actual_gs': 'GS EDCT issued',
    'simulated_gs': 'GS simulated',
    'proposed_gs': 'GS proposed',
    'gs': 'ground stop',
    'actual_gdp': 'GDP EDCT issued',
    'simulated_gdp': 'GDP simulated',
    'proposed_gdp': 'GDP proposed',
    'gdp': 'GDP controlled',
    'exempt': 'exempt',
    'uncontrolled': 'not controlled',
    'unknown': '',
};

// Phase stacking order for main chart (bottom to top)
// TMI statuses appear on top of normal flight phases
const PHASE_STACK_ORDER = [
    'arrived',
    'disconnected',
    'descending',
    'enroute',
    'departed',
    'taxiing',
    'prefile',
    'uncontrolled',
    'exempt',
    'actual_gs',
    'simulated_gs',
    'proposed_gs',
    'actual_gdp',
    'simulated_gdp',
    'proposed_gdp',
    'unknown',
];

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
    'actual_gs': 'badge-warning',
    'simulated_gs': 'badge-warning',
    'proposed_gs': 'badge-warning',
    'gs': 'badge-warning',
    'actual_gdp': 'badge-warning',
    'simulated_gdp': 'badge-warning',
    'proposed_gdp': 'badge-warning',
    'gdp': 'badge-warning',
    'exempt': 'badge-secondary',
    'uncontrolled': 'badge-light',
    'unknown': 'badge-secondary',
};

// TMI status mapping for normalizing various status strings
const TMI_STATUS_MAP = {
    // Ground Stop variations
    'PROPOSED_GS': 'proposed_gs',
    'SIMULATED_GS': 'simulated_gs',
    'ACTUAL_GS': 'actual_gs',
    'GS': 'gs',
    'GROUND_STOP': 'gs',
    'GROUNDSTOP': 'gs',

    // GDP variations
    'PROPOSED_GDP': 'proposed_gdp',
    'SIMULATED_GDP': 'simulated_gdp',
    'ACTUAL_GDP': 'actual_gdp',
    'GDP': 'gdp',
    'GROUND_DELAY': 'gdp',

    // Exempt
    'EXEMPT': 'exempt',
    'EXEMPTED': 'exempt',
};

// Helper function to normalize TMI status to phase key
function normalizeTmiStatus(status) {
    if (!status) {return null;}
    const upper = String(status).toUpperCase().trim();
    return TMI_STATUS_MAP[upper] || null;
}

// Helper function to get phase color
function getPhaseColor(phase) {
    if (!phase) {return PHASE_COLORS['unknown'];}
    const normalized = String(phase).toLowerCase().trim();
    // Check direct match
    if (PHASE_COLORS[normalized]) {return PHASE_COLORS[normalized];}
    // Check TMI status map
    const tmiPhase = normalizeTmiStatus(phase);
    if (tmiPhase && PHASE_COLORS[tmiPhase]) {return PHASE_COLORS[tmiPhase];}
    return PHASE_COLORS['unknown'] || '#9333ea';
}

// Helper function to get phase label (with i18n support)
function getPhaseLabel(phase) {
    if (!phase) {
        // Use i18n if available, otherwise fallback
        if (typeof PERTII18n !== 'undefined') {
            return PERTII18n.t('common.unknown');
        }
        return 'Unknown';
    }
    const normalized = String(phase).toLowerCase().trim();

    // Try i18n first if available
    if (typeof PERTII18n !== 'undefined') {
        const i18nKey = PHASE_LABEL_KEYS[normalized];
        if (i18nKey) {
            return PERTII18n.t(i18nKey);
        }
        // Check TMI status map
        const tmiPhase = normalizeTmiStatus(phase);
        if (tmiPhase && PHASE_LABEL_KEYS[tmiPhase]) {
            return PERTII18n.t(PHASE_LABEL_KEYS[tmiPhase]);
        }
    }

    // Fallback to hardcoded labels
    if (PHASE_LABELS[normalized]) {return PHASE_LABELS[normalized];}
    const tmiPhase = normalizeTmiStatus(phase);
    if (tmiPhase && PHASE_LABELS[tmiPhase]) {return PHASE_LABELS[tmiPhase];}
    return phase;
}

// Helper function to get phase badge class
function getPhaseBadgeClass(phase) {
    if (!phase) {return 'badge-secondary';}
    const normalized = String(phase).toLowerCase().trim();
    if (PHASE_BADGE_CLASSES[normalized]) {return PHASE_BADGE_CLASSES[normalized];}
    const tmiPhase = normalizeTmiStatus(phase);
    if (tmiPhase && PHASE_BADGE_CLASSES[tmiPhase]) {return PHASE_BADGE_CLASSES[tmiPhase];}
    return 'badge-secondary';
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
        PHASE_LABEL_KEYS,
        PHASE_DESCRIPTIONS,
        PHASE_ORDER,
        PHASE_STACK_ORDER,
        PHASE_BADGE_CLASSES,
        TMI_STATUS_MAP,
        normalizeTmiStatus,
        getPhaseColor,
        getPhaseLabel,
        getPhaseBadgeClass,
        lightenColor,
    };
}
