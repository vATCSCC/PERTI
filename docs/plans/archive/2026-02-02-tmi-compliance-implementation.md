# TMI Compliance Analysis UI Redesign - Implementation Plan

**Date:** 2026-02-02
**Branch:** feature/tmi-compliance-redesign
**Design Document:** [2026-02-02-tmi-compliance-ui-redesign.md](./2026-02-02-tmi-compliance-ui-redesign.md)

---

## Implementation Phases

### Phase 1: Color System Updates (Foundation)
**Estimated: 2 files, ~30 lines**

Add TMI color tokens to the global color system.

#### 1.1 Update `assets/css/perti-colors.css`

Add after line 165 (after weather panel colors):

```css
/* ===========================================
 * TMI COMPLIANCE ANALYSIS
 * Neutral data distinction (not judgmental)
 * =========================================== */

--tmi-data-neutral:      #6b7280;    /* Compliant pairs - baseline gray */
--tmi-data-attention:    #374151;    /* Non-compliant - darker, draws eye */
--tmi-scale-bar:         #374151;    /* Required spacing reference */
--tmi-gap-indicator:     #9ca3af;    /* Time gaps between non-consecutive flights */

/* Flow visualization */
--tmi-flow-cone-fill:    rgba(107, 114, 128, 0.12);
--tmi-flow-cone-border:  rgba(107, 114, 128, 0.35);
--tmi-stream-highlight:  #4b5563;
```

#### 1.2 Update `assets/js/lib/colors.js`

Add `tmiCompliance` object after the `airspace` object (around line 202):

```javascript
// ========================================
// TMI COMPLIANCE ANALYSIS
// ========================================
const tmiCompliance = {
    neutral: '#6b7280',        // Compliant pairs
    attention: '#374151',      // Non-compliant pairs
    scaleBar: '#374151',       // Required spacing reference
    gapIndicator: '#9ca3af',   // Non-consecutive gap marker
    flowConeFill: 'rgba(107, 114, 128, 0.12)',
    flowConeBorder: 'rgba(107, 114, 128, 0.35)',
    streamHighlight: '#4b5563',
};
```

Also add to public API return object.

---

### Phase 2: CSS Component Styles
**Estimated: 1 new file, ~300 lines**

Create `assets/css/tmi-compliance.css` with styles for the new layout.

#### 2.1 Layout Structure

```css
/* Master-detail layout */
.tmi-analysis-container {
    display: flex;
    gap: 0;
    min-height: 600px;
}

.tmi-list-panel {
    width: 280px;
    flex-shrink: 0;
    border-right: 1px solid var(--light-border);
    overflow-y: auto;
}

.tmi-detail-panel {
    flex: 1;
    overflow-y: auto;
    padding: 20px;
}

/* Responsive breakpoints */
@media (max-width: 1400px) {
    .tmi-list-panel { width: 240px; }
}

@media (max-width: 1000px) {
    .tmi-analysis-container { flex-direction: column; }
    .tmi-list-panel { width: 100%; border-right: none; border-bottom: 1px solid var(--light-border); }
    .tmi-detail-panel { padding: 15px; }
}
```

#### 2.2 L1 Summary Header

```css
.tmi-summary-header {
    padding: 20px;
    background: var(--light-bg-surface);
    border-bottom: 1px solid var(--light-border);
}

.tmi-summary-header .event-identity {
    font-size: 1.25em;
    font-weight: 600;
    margin-bottom: 4px;
}

.tmi-summary-header .event-window {
    color: var(--light-text-muted);
    font-size: 0.9em;
}

.tmi-summary-entries {
    display: flex;
    gap: 40px;
    margin-top: 16px;
}

.tmi-summary-group h4 {
    font-size: 0.75em;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--light-text-muted);
    margin-bottom: 8px;
}

.tmi-summary-line {
    font-size: 0.9em;
    line-height: 1.5;
}

.tmi-data-quality {
    margin-top: 16px;
    padding-top: 12px;
    border-top: 1px solid var(--light-border);
    font-size: 0.85em;
    color: var(--light-text-muted);
}
```

#### 2.3 L2 List Panel

```css
.tmi-list-header {
    padding: 12px 16px;
    border-bottom: 1px solid var(--light-border);
    display: flex;
    gap: 8px;
}

.tmi-list-header select {
    font-size: 0.8em;
    padding: 4px 8px;
}

.tmi-list-section-label {
    padding: 8px 16px;
    font-size: 0.7em;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--light-text-muted);
    background: var(--light-bg-subtle);
}

.tmi-list-item {
    display: grid;
    grid-template-columns: 70px 80px 50px;
    gap: 8px;
    padding: 8px 16px;
    cursor: pointer;
    font-size: 0.85em;
    border-left: 3px solid transparent;
}

.tmi-list-item:hover {
    background: var(--light-bg-hover);
}

.tmi-list-item.selected {
    background: var(--light-bg-subtle);
    border-left-color: var(--brand-primary);
}

.tmi-list-item .identifier {
    font-weight: 600;
    font-family: monospace;
}

.tmi-list-item .type-value {
    color: var(--light-text-secondary);
}

.tmi-list-item .metric {
    text-align: right;
    color: var(--light-text-muted);
}

/* Non-compliant indicator dot */
.tmi-list-item .nc-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--tmi-data-attention);
    display: inline-block;
    margin-right: 4px;
}
```

#### 2.4 L3 Detail Panel

```css
.tmi-detail-header {
    margin-bottom: 20px;
}

.tmi-detail-header .tmi-identity {
    font-size: 1.1em;
    font-weight: 600;
    margin-bottom: 4px;
}

.tmi-detail-header .tmi-standardized {
    font-size: 0.85em;
    color: var(--light-text-muted);
    font-family: monospace;
}

.tmi-detail-overview {
    display: flex;
    flex-wrap: wrap;
    gap: 24px;
    padding: 16px;
    background: var(--light-bg-subtle);
    border-radius: 6px;
    margin-bottom: 20px;
}

.tmi-detail-overview .stat {
    text-align: center;
}

.tmi-detail-overview .stat-value {
    font-size: 1.5em;
    font-weight: 600;
}

.tmi-detail-overview .stat-label {
    font-size: 0.75em;
    color: var(--light-text-muted);
}

/* Expandable sections */
.tmi-section {
    border: 1px solid var(--light-border);
    border-radius: 6px;
    margin-bottom: 12px;
}

.tmi-section-header {
    display: flex;
    align-items: center;
    padding: 12px 16px;
    cursor: pointer;
    user-select: none;
}

.tmi-section-header:hover {
    background: var(--light-bg-subtle);
}

.tmi-section-header .chevron {
    margin-right: 8px;
    transition: transform 0.2s;
}

.tmi-section-header.expanded .chevron {
    transform: rotate(90deg);
}

.tmi-section-content {
    padding: 16px;
    border-top: 1px solid var(--light-border);
    display: none;
}

.tmi-section.expanded .tmi-section-content {
    display: block;
}
```

#### 2.5 Spacing Diagram

```css
.tmi-spacing-diagram {
    padding: 20px;
    overflow-x: auto;
}

.tmi-spacing-timeline {
    display: flex;
    align-items: flex-start;
    min-width: max-content;
    gap: 0;
}

.tmi-spacing-flight {
    display: flex;
    flex-direction: column;
    align-items: center;
    width: 60px;
}

.tmi-spacing-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: var(--tmi-data-neutral);
}

.tmi-spacing-dot.non-compliant {
    background: var(--tmi-data-attention);
}

.tmi-spacing-callsign {
    font-family: monospace;
    font-size: 0.75em;
    margin-top: 4px;
}

.tmi-spacing-time {
    font-size: 0.65em;
    color: var(--light-text-muted);
}

.tmi-spacing-segment {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 0 4px;
}

.tmi-spacing-line {
    height: 2px;
    background: var(--tmi-data-neutral);
    min-width: 40px;
}

.tmi-spacing-line.non-compliant {
    background: var(--tmi-data-attention);
}

.tmi-spacing-value {
    font-size: 0.7em;
    color: var(--light-text-secondary);
    margin-top: 4px;
}

.tmi-spacing-gap {
    display: flex;
    align-items: center;
    padding: 0 12px;
    color: var(--tmi-gap-indicator);
    font-size: 0.75em;
}

.tmi-spacing-scale {
    margin-top: 20px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.tmi-spacing-scale-bar {
    width: 60px;
    height: 3px;
    background: var(--tmi-scale-bar);
}

.tmi-spacing-scale-label {
    font-size: 0.75em;
    color: var(--light-text-muted);
}
```

#### 2.6 Pairs Table

```css
.tmi-pairs-table {
    width: 100%;
    font-size: 0.85em;
}

.tmi-pairs-table th {
    font-weight: 600;
    font-size: 0.75em;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: var(--light-text-muted);
    padding: 8px 12px;
    border-bottom: 2px solid var(--light-border);
}

.tmi-pairs-table td {
    padding: 10px 12px;
    border-bottom: 1px solid var(--light-border);
    vertical-align: middle;
}

.tmi-pairs-table .flight-cell {
    line-height: 1.3;
}

.tmi-pairs-table .callsign {
    font-family: monospace;
    font-weight: 600;
}

.tmi-pairs-table .crossing-time {
    font-size: 0.85em;
    color: var(--light-text-muted);
}

.tmi-pairs-table .gap-cell {
    font-family: monospace;
}

.tmi-pairs-table .spacing-cell {
    font-weight: 600;
}

.tmi-pairs-table .spacing-bar-cell {
    min-width: 120px;
}

.tmi-spacing-bar-inline {
    display: flex;
    align-items: center;
    gap: 8px;
}

.tmi-spacing-bar-track {
    flex: 1;
    height: 8px;
    background: var(--light-bg-subtle);
    border-radius: 4px;
    overflow: hidden;
}

.tmi-spacing-bar-fill {
    height: 100%;
    background: var(--tmi-data-neutral);
}

.tmi-spacing-bar-fill.non-compliant {
    background: var(--tmi-data-attention);
}

.tmi-spacing-diff {
    font-size: 0.85em;
    color: var(--light-text-muted);
    white-space: nowrap;
}
```

---

### Phase 3: JavaScript Module Refactor
**Estimated: Major rewrite of tmi_compliance.js**

#### 3.1 New Module Structure

```javascript
const TMICompliance = {
    // State
    planId: null,
    results: null,
    selectedTmi: null,      // Currently selected TMI ID
    listGrouping: 'type',   // 'type' or 'custom'
    listOrdering: 'chrono', // 'chrono', 'volume', 'noncompliant', 'alpha'
    expandedSections: {},   // Track which detail sections are expanded

    // Config (unchanged)
    filters: { ... },

    // Initialization
    init: function() { ... },

    // Data loading (unchanged)
    loadResults: function() { ... },
    runAnalysis: function() { ... },
    saveConfig: function() { ... },
    loadConfig: function() { ... },

    // NEW: Rendering Methods
    renderLayout: function() { ... },
    renderSummaryHeader: function() { ... },
    renderListPanel: function() { ... },
    renderDetailPanel: function() { ... },

    // NEW: Detail Components
    renderDetailOverview: function(tmi) { ... },
    renderSpacingDiagram: function(tmi) { ... },
    renderPairsTable: function(tmi, filter) { ... },
    renderContextMap: function(tmi) { ... },

    // NEW: Interaction Handlers
    selectTmi: function(tmiId) { ... },
    toggleSection: function(sectionId) { ... },
    updateListGrouping: function(value) { ... },
    updateListOrdering: function(value) { ... },

    // Utilities (mostly unchanged)
    formatTime: function(time) { ... },
    formatGap: function(seconds) { ... },  // NEW: mm:ss format
    calculateDataQuality: function() { ... },
};
```

#### 3.2 Key New Methods

**renderLayout()** - Creates the master-detail structure:

```javascript
renderLayout: function() {
    const html = `
        <div class="tmi-summary-header">
            ${this.renderSummaryHeader()}
        </div>
        <div class="tmi-analysis-container">
            <div class="tmi-list-panel">
                ${this.renderListPanel()}
            </div>
            <div class="tmi-detail-panel" id="tmi-detail-panel">
                <div class="text-muted text-center py-5">
                    Select a TMI from the list to view details
                </div>
            </div>
        </div>
    `;
    $('#tmi_results_container').html(html);
    this.bindEvents();
}
```

**renderSummaryHeader()** - The 5-second answer:

```javascript
renderSummaryHeader: function() {
    const r = this.results;
    const summary = r.summary || {};

    // Categorize TMIs
    const ntmlEntries = this.getNtmlEntries();
    const advisories = this.getAdvisories();

    return `
        <div class="event-identity">Plan ${r.plan_id || '?'} — TMI Analysis</div>
        <div class="event-window">${r.event_start} – ${r.event_end}</div>

        <div class="tmi-summary-entries">
            <div class="tmi-summary-group">
                <h4>NTML Entries</h4>
                ${this.renderNtmlSummaryLines(ntmlEntries)}
            </div>
            <div class="tmi-summary-group">
                <h4>Advisories</h4>
                ${this.renderAdvisorySummaryLines(advisories)}
            </div>
        </div>

        <div class="tmi-data-quality">
            Data: ${this.calculateTrajectoryCoverage()}% trajectory coverage
            ${this.renderDataGaps()}
        </div>
    `;
}
```

**selectTmi()** - Handle TMI selection:

```javascript
selectTmi: function(tmiId) {
    this.selectedTmi = tmiId;

    // Update list selection state
    $('.tmi-list-item').removeClass('selected');
    $(`.tmi-list-item[data-tmi-id="${tmiId}"]`).addClass('selected');

    // Find TMI data
    const tmi = this.findTmiById(tmiId);
    if (!tmi) return;

    // Render detail panel
    $('#tmi-detail-panel').html(this.renderDetailPanel(tmi));

    // On mobile, scroll to detail
    if (window.innerWidth < 1000) {
        $('#tmi-detail-panel')[0].scrollIntoView({ behavior: 'smooth' });
    }
}
```

**renderSpacingDiagram()** - Simplified SVG diagram:

```javascript
renderSpacingDiagram: function(tmi) {
    const pairs = tmi.pair_details || [];
    if (pairs.length === 0) return '<div class="text-muted">No pairs to display</div>';

    // Build flight sequence
    const flights = this.buildFlightSequence(pairs);

    let html = '<div class="tmi-spacing-diagram"><div class="tmi-spacing-timeline">';

    let lastTime = null;
    for (let i = 0; i < flights.length; i++) {
        const flight = flights[i];

        // Check for gap (>10 min between flights)
        if (lastTime) {
            const gapMinutes = (flight.time - lastTime) / 60000;
            if (gapMinutes > 10) {
                html += `<div class="tmi-spacing-gap">─ ${Math.round(gapMinutes)} min ─</div>`;
            } else {
                // Render segment line
                const segment = pairs.find(p => p.trail_callsign === flight.callsign);
                if (segment) {
                    const isNonCompliant = segment.spacing_category === 'UNDER';
                    html += `
                        <div class="tmi-spacing-segment">
                            <div class="tmi-spacing-line ${isNonCompliant ? 'non-compliant' : ''}"></div>
                            <div class="tmi-spacing-value">${segment.actual_nm?.toFixed(1)}nm</div>
                        </div>
                    `;
                }
            }
        }

        // Render flight dot
        const isNonCompliant = this.isFlightInNonCompliantPair(flight.callsign, pairs);
        html += `
            <div class="tmi-spacing-flight">
                <div class="tmi-spacing-dot ${isNonCompliant ? 'non-compliant' : ''}"></div>
                <div class="tmi-spacing-callsign">${flight.callsign}</div>
                <div class="tmi-spacing-time">${this.formatTimeShort(flight.time)}</div>
            </div>
        `;

        lastTime = flight.time;
    }

    html += '</div>';

    // Scale bar
    html += `
        <div class="tmi-spacing-scale">
            <div class="tmi-spacing-scale-bar"></div>
            <span class="tmi-spacing-scale-label">${tmi.required}${tmi.unit === 'min' ? ' min' : 'nm'} required</span>
        </div>
    `;

    html += '</div>';
    return html;
}
```

---

### Phase 4: review.php Updates
**Estimated: Minor HTML structure changes**

Update the TMI Compliance tab structure in review.php to use the new layout.

---

### Phase 5: Testing & Polish
- Test responsive behavior at different viewport sizes
- Verify keyboard navigation
- Test with real data from existing compliance reports
- Performance check with large datasets

---

## File Change Summary

| File | Action | Scope |
|------|--------|-------|
| `assets/css/perti-colors.css` | Modify | Add ~10 lines |
| `assets/js/lib/colors.js` | Modify | Add ~15 lines |
| `assets/css/tmi-compliance.css` | Create | ~300 lines |
| `assets/js/tmi_compliance.js` | Major refactor | ~800 lines changed |
| `review.php` | Modify | ~20 lines |

---

## Implementation Order

1. **Phase 1** first - establish color tokens
2. **Phase 2** - create CSS before JS so styles are ready
3. **Phase 3** - JavaScript refactor (bulk of work)
4. **Phase 4** - Wire up in review.php
5. **Phase 5** - Testing

---

## Rollback Plan

If issues arise, the feature branch can be abandoned. No breaking changes to API or data structures - this is purely a UI refactor.

---

## Success Criteria

- [ ] L1 Summary Header shows "5-second answer"
- [ ] L2 List Panel is compact and scannable
- [ ] L3 Detail Panel shows selected TMI with expandable sections
- [ ] Spacing diagram uses simplified dot-line-gap design
- [ ] No color coding (red/green) for compliance
- [ ] All colors use global tokens
- [ ] Responsive layout works at 1400px, 1000px breakpoints
- [ ] Mobile view stacks panels vertically
