# TMI Compliance Analysis UI Redesign

**Date:** 2026-02-02
**Status:** Design Complete
**Author:** Claude (AI-assisted design)

## Overview

Comprehensive redesign of the TMI Compliance Analysis tab in `review.php` to transform it from a cluttered, AI-generated appearance to a professional, intuitive interface that serves multiple audiences through progressive disclosure.

## Design Philosophy

| Principle | Implementation |
|-----------|----------------|
| **Progressive disclosure** | Three layers: Summary → List → Detail |
| **Factual, not judgmental** | "Non-compliant" not "violations"; no good/bad color coding |
| **Minimal emphasis** | Visual weight only where necessary |
| **Intuitive navigation** | Master-detail panel; no instructions needed |
| **Unified design system** | Extend existing `perti-colors.css` and `PERTIColors` |

## Target Audiences

- Pilots (quick compliance check)
- General ATC (operational awareness)
- Facility TMU (tactical detail)
- National-level TMU (strategic analysis)
- Managers/Directors (executive summary)

All served by progressive disclosure — start simple, drill down for detail.

---

## Layout Structure

```
┌─────────────────────────────────────────────────────────────┐
│  L1: SUMMARY HEADER                                         │
│  Plan, window, NTML entries summary, advisories, data quality│
└─────────────────────────────────────────────────────────────┘
┌──────────────────────┬──────────────────────────────────────┐
│  L2: TMI LIST        │  L3: DETAIL PANEL                    │
│  Grouped, compact    │  Selected TMI details                │
│  Configurable order  │  Expandable sections                 │
└──────────────────────┴──────────────────────────────────────┘
```

---

## L1: Summary Header

The summary header provides the **5-second answer** to "Were TMIs complied with?" in purely factual terms.

### Content Structure

```
Plan 227 — TMI Analysis
2026-01-24 2359Z – 0400Z

NTML Entries
  MIT/MINIT: 847 pairs analyzed, 3 non-compliant
  APREQ/CFR: 8 tracked
  STOP: 1 stop, 0 departures during restriction
────────────────────────────────────────────────────
Advisories
  GS: 2 programs, 1 departure during restriction
  GDP: 1 program active

Data: 94% trajectory coverage | Gaps: 0312Z–0318Z
```

### Design Principles

- **Single horizontal band** — no cards, no boxes, just clean typography
- **Event identity first** — plan number and time window establish context
- **NTML Entries first** — MIT/MINIT, APREQ/CFR, STOP (compliance-measurable)
- **Advisories second** — GS, GDP, Reroute, AFP, etc. (informational)
- **Data quality inline** — coverage percentage and gap windows
- **No percentages by default** — raw counts are more concrete and less judgmental
- **No color coding** — all text is the same neutral color

### TMI Type Categorization

**NTML Entry Types (compliance-measurable):**
- MIT, MINIT, CFR, APREQ, STOP
- Delay Entries: D/D, E/D, A/D
- TBM, TBFM, Airport Configuration
- DSP, ESP, ASP
- ECFMP TMIs: MDI, ADI, RPH, Max IAS, Max Mach, IAS Reduction, Mach Reduction, Prohibit, Mandatory Route

**Advisory Types (informational):**
- GS, GDP, Reroute (ROUTE), FCA, FEA, AFP, CTOP, ICR, TOS, Operations Plan (OP), Hotline

---

## L2: TMI List Panel

The left panel is the **scannable index** of all TMIs.

### Layout

```
┌─────────────────────────┐
│ Group: [Type ▾]         │
│ Order: [Chronological ▾]│
├─────────────────────────┤
│ NTML Entries            │
│                         │
│  KAYOH    20MIT   12p   │
│  SLIDR    15MIT    8p   │
│  LAS      10MINIT  23p  │
│  ● LAS    STOP     0/1  │
│  NCT      APREQ    4    │
│                         │
│ Advisories              │
│                         │
│  LAS      GS (NCT) 1/3  │
│  SFO      GDP      --   │
│                         │
└─────────────────────────┘
```

### Design Principles

- **~250-300px width** — fixed, doesn't compete with detail panel
- **Three-column layout per row**: identifier, type+value, key metric
- **Minimal row height** — ~28-32px per row, dense but readable
- **Selected state** — subtle background highlight, no heavy borders
- **Non-compliant indicator** — small dot only on rows with non-compliant pairs

### Grouping & Ordering

**Grouping options:**
- By Type (default)
- Custom user preference

**Ordering options:**
- Chronological (default) — by TMI start time
- By traffic volume — pair count, highest first
- By non-compliant count — TMIs with issues first
- Alphabetical — by fix/identifier

---

## L3: Detail Panel

When a TMI is selected, the detail panel shows **everything about that TMI** organized in layers.

### Structure

```
┌────────────────────────────────────────────────────────────┐
│  LAS via SLIDR  15MIT  ZLA:ZOA  0015Z–0245Z               │
│  Standardized: LAS via SLIDR 15MIT ZLA:ZOA 0015Z-0245Z    │
├────────────────────────────────────────────────────────────┤
│  OVERVIEW                                                  │
│  23 crossings  |  22 pairs analyzed  |  2 non-compliant   │
│  Spacing: 8.2–31.4nm  avg 17.1nm                          │
├────────────────────────────────────────────────────────────┤
│  ▸ Spacing Diagram                                         │
│  ▸ All Pairs (22)                                          │
│  ▸ Non-Compliant (2)                                       │
│  ▸ Context Map                                             │
└────────────────────────────────────────────────────────────┘
```

### Design Principles

- **Header** — TMI identity clearly stated at top
- **Overview section** — always visible, key facts in one glance
- **Expandable sections** — collapsed by default, user chooses what to see
- **Section labels are factual** — "Non-Compliant (2)" not "Violations (2)"

---

## Spacing Diagram

Simplified SVG timeline visualization.

### Layout

```
●─────────●───────────●          ─ 8 min ─          ●─────────────●──────────●
AAL123   UAL456     DAL789                        SWA012       JBU345      FFT678
0021Z    0024Z      0028Z                         0041Z        0048Z       0054Z

  12.1nm    18.3nm                                   22.7nm       19.8nm


                                                    ├──────────┤ 15nm required
```

### Design Principles

- **Dots for flights** — each dot is a crossing
- **Lines only between consecutive pairs** — no line where there's a gap
- **Gap indicator** — whitespace + duration label (e.g., `─ 8 min ─`)
- **Scale bar** — map-style reference at bottom showing required spacing
- **Spacing values below segments** — clean, readable
- **Label collision handling** — show first/last of clusters, "+N more" for hidden

### What's Removed

- Color-coded segments (red/green/blue/yellow)
- Hatched gap patterns
- Floating legend box
- Click-to-popup interactivity (moved to table)
- "Exempt" dashed lines

---

## Pairs Tables

Two expandable tables: **All Pairs** and **Non-Compliant** (same structure, filtered view).

### Layout

```
┌─────────────────────────────────────────────────────────────────────────┐
│  ▾ All Pairs (22)                                                       │
├──────────┬──────────┬──────────┬─────────┬─────────────────────────────┤
│  Lead    │  Trail   │ Gap      │ Spacing │                             │
│          │          │ (mm:ss)  │         │                             │
├──────────┼──────────┼──────────┼─────────┼─────────────────────────────┤
│  AAL123  │  UAL456  │  03:12   │ 12.1nm  │  ████████░░░░░░  (-2.9nm)  │
│  0021Z   │  0024Z   │          │         │                             │
├──────────┼──────────┼──────────┼─────────┼─────────────────────────────┤
│  UAL456  │  DAL789  │  04:05   │ 18.3nm  │  ████████████░░░  (+3.3nm) │
│  0024Z   │  0028Z   │          │         │                             │
└──────────┴──────────┴──────────┴─────────┴─────────────────────────────┘
```

### Columns

| Column | Content |
|--------|---------|
| **Lead** | Callsign + crossing time (stacked) |
| **Trail** | Callsign + crossing time (stacked) |
| **Gap (mm:ss)** | Time between crossings, always 2-digit each |
| **Spacing** | Actual spacing value |
| **Visual** | Inline bar + difference from required |

### Design Principles

- **Compact rows** — callsign and time stacked
- **mm:ss format** — always 2 digits each (03:12, not 3:12)
- **Inline spacing bar** — small, proportional
- **Difference shown** — (+3.3nm) or (-2.9nm)
- **No row coloring** — non-compliant rows not highlighted
- **Sortable** — click column headers

---

## Context Map

Geographic context with configurable layers.

### Layer Structure

```
Layers
│
├─ Airspace
│   ├─ ☐ ARTCC boundaries
│   ├─ ☐ Superhigh sectors
│   ├─ ☐ High sectors
│   ├─ ☐ Low sectors
│   └─ ☐ TRACON boundaries
│
├─ Flow
│   ├─ ☐ Flow cone/corridor
│   └─ ☐ Stream highlighting
│
├─ Flights
│   ├─ ☐ Tracks
│   │   └─ ☐ Labels
│   └─ ☐ Non-compliant pairs
│
└─ Reference
    └─ ☑ Fix marker
```

### Defaults

| Group | Default | Notes |
|-------|---------|-------|
| **Airspace** | All off | User enables as needed |
| **Flow** | Off | Shows expected traffic flow pattern |
| **Flights** | Off | Tracks are heavy — opt-in only |
| **Reference** | Fix on | Always show measurement point |

### Design Principles

- **Minimal by default** — user builds up
- **State persists** — preferences saved
- **Use global colors** — `PERTIColors.airspace.*` tokens

---

## Configuration Panel

### Layout

```
┌─────────────────────────────────────────────────────────────────────────┐
│  ▾ Configuration                                           [Run Analysis]│
├─────────────────────────────────────────────────────────────────────────┤
│                                                                         │
│  Featured Facilities  [KLAS, KSFO, ...]   Event Window  [2026-01-24 2359Z]│
│                                                          [2026-01-25 0400Z]│
│                                                                         │
│  NTML Entries                                                           │
│  ┌─────────────────────────────────────────────────────────────────┐   │
│  │ LAS via SLIDR 15MIT ZLA:ZOA 0015Z-0245Z                         │   │
│  │ LAS GS (NCT) 0230Z-0315Z issued 0244Z                           │   │
│  └─────────────────────────────────────────────────────────────────┘   │
│                                                        [Auto-saved ✓]   │
└─────────────────────────────────────────────────────────────────────────┘
```

### Design Principles

- **Collapsed by default** — once analysis has run
- **"Featured Facilities"** — not "destinations" (TMIs affect departures, arrivals, overflights)
- **Run Analysis always visible** — in header row
- **Auto-save** — no explicit save button needed

---

## Event Timeline (Gantt Chart)

### Layout (collapsed by default)

```
┌─────────────────────────────────────────────────────────────────────────┐
│  ▾ Event Timeline                                                       │
├─────────────────────────────────────────────────────────────────────────┤
│        2359Z    0030Z    0100Z    0130Z    0200Z    0230Z    0300Z      │
│          │        │        │        │        │        │        │        │
│  SLIDR   ████████████████████████████████░░░░░░░░░░░░░                  │
│  KAYOH   ░░░░░░░░████████████████████████████████████░░░░░              │
│  LAS GS  ░░░░░░░░░░░░░░░░░░░░░░░░░░████████░░░░░░░░░░░░░                │
│                                                                         │
│  Legend: ████ Active   ░░░░ Inactive   ▓▓▓▓ Cancelled                  │
└─────────────────────────────────────────────────────────────────────────┘
```

---

## Empty & Loading States

### Loading State

```
                    Analyzing TMI Compliance

                ████████████████░░░░░░░░░░░░  67%

                Querying flight trajectories...
```

### Empty State

```
                    No analysis results loaded

     Configure NTML entries above and click "Run Analysis"
                 or load previously saved results.
```

### No Matches State

```
                  No matching flights found

   0 flights crossed the specified fixes during the event window.
   Check that featured facilities and time window are correct.
```

---

## Responsive Behavior

| Viewport | L2 List Width | L3 Detail Width |
|----------|---------------|-----------------|
| Large (>1400px) | 280px fixed | Remaining space |
| Medium (1000-1400px) | 240px fixed | Remaining space |
| Small (<1000px) | Full width | Full width (stacked) |

**On small screens:**
- L2 and L3 stack vertically
- Selecting a TMI scrolls to L3 detail
- "Back to list" link at top of L3
- L1 summary remains fixed (condensed)

---

## Color System Additions

### Add to `assets/css/perti-colors.css`

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

### Add to `assets/js/lib/colors.js`

```javascript
// TMI Compliance Analysis
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

### Use Existing Tokens

- Sector colors: `PERTIColors.airspace.low/high/superhigh`
- Boundary colors: `PERTIColors.airspace.artcc/tracon`
- Phase colors: `PHASE_COLORS` from `phase-colors.js`

---

## What This Replaces

| Current | New |
|---------|-----|
| Everything visible at once | Progressive disclosure |
| Color-coded judgment (red/green) | Neutral grays with value distinction |
| Cluttered cards with 10+ sections | Compact overview + expandable details |
| Inline hardcoded colors | Global design tokens |
| "Violations" language | "Non-compliant" (factual) |
| Fixed layout | Master-detail with responsive behavior |
| "Destinations" field | "Featured Facilities" |

---

## Files to Modify

### Primary

- `assets/js/tmi_compliance.js` — main module, rendering logic
- `review.php` — tab structure, initial HTML
- `assets/css/perti-colors.css` — add TMI color tokens
- `assets/js/lib/colors.js` — add TMI color definitions

### Secondary

- `api/analysis/tmi_compliance.php` — may need response structure adjustments
- `assets/css/theme.css` or new `tmi-compliance.css` — component styles

---

## Implementation Notes

1. **Preserve existing data structures** — UI changes only, API responses stay compatible
2. **Feature flag consideration** — could introduce behind flag for A/B testing
3. **Mobile testing** — verify responsive behavior on tablets and phones
4. **Accessibility** — ensure keyboard navigation, screen reader support
5. **Performance** — lazy-load expanded sections, virtualize long tables if needed
