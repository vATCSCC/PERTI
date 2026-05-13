# Mobile Responsive Design — PERTI Plan Pages

**Date**: 2026-05-13
**Status**: Approved design
**Scope**: Mobile responsiveness for `plan.php`, `data.php`, `review.php`, `airport_config.php`, `index.php`, and shared navigation (`nav.php`, `nav_public.php`)
**Goal**: Full functional parity on mobile devices — all features work, including editing, form submission, and data entry
**Stack**: jQuery 2.2.4, Bootstrap 4.5, vanilla JS (no framework change)

---

## Current State (Verified)

### What works
- Viewport meta tag present in `header.php`
- `mobile.css` (1,010 lines) loaded last in CSS cascade with comprehensive breakpoints, touch targets, offcanvas nav infrastructure, and card-style table patterns
- `airport_config.php` already mostly mobile-functional — uses `config-stats` and `bulk-actions` classes targeted by mobile.css

### What's broken
- **Navbar bug**: Desktop nav items render on all screen sizes. In both `nav.php` (line 191) and `nav_public.php` (line 168), the `<div class="d-flex align-items-left order-lg-3">` wrapping the desktop nav `<ul class="navbar-nav">` lacks `d-none d-lg-flex`, so it displays on mobile alongside the hamburger menu
- **Sidebar layouts**: `plan.php`, `data.php`, and `review.php` all use `col-2`/`col-10` grids with zero responsive classes — sidebar collapses to an unreadable sliver on phones
- **No mobile table handling on index.php**: Plans table uses `table-layout:fixed` with `w-100` but has no `table-mobile-cards` class or `data-label` attributes. Table body is dynamically populated (`<tbody id="plans_table">`)
- **Dead CSS**: `mobile.css` defines `.plan-nav`, `.plan-sidebar`, `.plan-content`, and `.table-mobile-cards` rules that are never referenced in any PHP or JS file

---

## Breakpoints

Using existing `mobile.css` breakpoints:

| Range | Label | Behavior |
|-------|-------|----------|
| 0–575px | Phone portrait | Cards, bottom tab bar, single column |
| 576–767px | Phone landscape | Same as portrait, slightly wider cards |
| 768–991px | Tablet portrait | Compact scrollable tables, bottom tab bar |
| 992–1279px | Tablet landscape | Sidebar restored, full tables |
| 1280px+ | Desktop | Current layout unchanged |

---

## Design Decisions

### 1. Table Pattern: Hybrid

Tables adapt by breakpoint:
- **Phone (<768px)**: Card layout — each row becomes a stacked card with labeled fields using `data-label` attributes. Uses the existing `.table-mobile-cards` pattern from mobile.css
- **Tablet (768–991px)**: Compact scrollable table with horizontal scroll, sticky first column (`.table-sticky-col` from mobile.css)
- **Desktop (992px+)**: Full table, current layout unchanged

### 2. Sidebar Navigation: Domain-Split Bottom Tab Bar

The desktop sidebar (Bootstrap pill nav in `col-2`) is hidden on mobile (<992px) and replaced with a fixed bottom tab bar. Sections are grouped into domain categories. Each bottom tab either navigates directly (1–2 sub-sections) or opens a slide-up sheet (3+ sub-sections). Every section is reachable in exactly 2 taps. No generic "More" catchall tab.

### 3. Navbar Fix

Add `d-none d-lg-flex` to the desktop nav wrapper div in both `nav.php` and `nav_public.php`. The offcanvas mobile menu (already functional) then becomes the sole navigation on mobile viewports.

---

## Page-by-Page Specification

### plan.php — 14 Sections

**Desktop**: Unchanged (`col-2` sidebar / `col-10` content)

**Mobile (<992px)**: Hide sidebar, show fixed bottom tab bar with 4 tabs:

| Bottom Tab | Sub-sections | Behavior |
|------------|-------------|----------|
| **Overview** | Overview, DCC Staffing | Direct navigation (2 items — segmented toggle within content area) |
| **Terminal** | Timelines, Staffing, Configs, Planning | Slide-up sheet (4 items) |
| **En-Route** | Timelines, Staffing, Planning, Splits | Slide-up sheet (4 items) |
| **Data** | Historical, Forecast, Group Flights, Outlook | Slide-up sheet (4 items) |

**Mapping to existing tab IDs:**

| Bottom Tab | Tab IDs |
|------------|---------|
| Overview | `#overview`, `#dcc_staffing` |
| Terminal | `#t_timelines`, `#t_staffing`, `#configs`, `#t_planning` |
| En-Route | `#e_timelines`, `#e_staffing`, `#e_planning`, `#e_splits` |
| Data | `#historical`, `#forecast`, `#group_flights`, `#outlook` |

**Internal content changes:**
- `#overview` tab uses `col-6` columns (line 222) — add responsive classes (`col-12 col-md-6`)
- All tables within tab panes get hybrid card/table treatment

### data.php — 6 Sections

**Desktop**: Unchanged

**Mobile (<992px)**: Hide sidebar, show fixed bottom tab bar with 3 tabs:

| Bottom Tab | Sub-sections | Behavior |
|------------|-------------|----------|
| **Overview** | Overview, DCC Staffing | Direct navigation (2 items) |
| **Terminal** | Terminal Staffing, Field Configurations | Direct navigation (2 items) |
| **En-Route** | En-Route Staffing, En-Route Splits | Direct navigation (2 items) |

All groups have only 2 sub-sections, so every tab navigates directly — no slide-up sheets needed. The bottom tab bar shows the 2 sub-sections as a segmented toggle or mini-tab strip within the content area.

**Mapping to existing tab IDs:**

| Bottom Tab | Tab IDs |
|------------|---------|
| Overview | `#overview`, `#dcc_staffing` |
| Terminal | `#t_staffing`, `#configs` |
| En-Route | `#e_staffing`, `#e_splits` |

**Internal content changes:**
- `#overview` tab uses `col-6` columns (line 151) — add responsive classes (`col-12 col-md-6`)
- Note: `data.php` uses `nav_public.php`, not `nav.php` — both get the navbar fix

### review.php — 14 Sections + 1 Action

**Desktop**: Unchanged

**Mobile (<992px)**: Hide `tmr-sidebar`, show fixed bottom tab bar with 4 tabs:

| Bottom Tab | Sub-sections | Behavior |
|------------|-------------|----------|
| **Triggers** | Triggers | Direct navigation (1 item) |
| **Review** | Overview, Airport Conditions, Weather, Special Events | Slide-up sheet (4 items) |
| **Operations** | TMIs, Equipment, Personnel, Operational Plan | Slide-up sheet (4 items) |
| **Assessment** | Findings, Recommendations, Scoring, Event Data, TMI Compliance | Slide-up sheet (5 items) |

**Export to Discord**: Handled as a floating action button (FAB) or action in the page header, not in the tab bar.

**Mapping to existing tab IDs:**

| Bottom Tab | Tab IDs |
|------------|---------|
| Triggers | `#tmr_triggers` |
| Review | `#tmr_overview`, `#tmr_airport`, `#tmr_weather`, `#tmr_events` |
| Operations | `#tmr_tmis`, `#tmr_equipment`, `#tmr_personnel`, `#tmr_plan` |
| Assessment | `#tmr_findings`, `#tmr_recs`, `#scoring`, `#event_data`, `#tmi_compliance` |

**TMR section label** ("TMR Report" / "Assessment" `<hr>` dividers in sidebar) — these groupings are for the desktop sidebar only. The mobile bottom bar uses the 4-tab domain grouping above, which cuts across the desktop groupings.

### airport_config.php — Already Mostly Works

No structural changes needed. The page already uses `config-stats` and `bulk-actions` classes targeted by mobile.css. Minor tweaks only:
- Verify touch target sizes on action buttons
- Ensure form inputs are full-width on phones
- Test bulk actions panel on narrow viewports

### index.php — Plans Table

The plans listing table needs mobile card treatment:
- Add `table-mobile-cards` class to the table element
- Add `data-label` attributes to `<td>` elements in JS (table body is dynamically built via `#plans_table`)
- Phone (<768px): Each plan row becomes a card showing event name, date, and status
- Tablet (768–991px): Compact scrollable table with sticky first column
- Remove `<center>` wrapper (deprecated HTML)

---

## Slide-Up Sheet Component

Used when a bottom tab has 3+ sub-sections. Behavior:

1. Tap a bottom tab (e.g., "Terminal")
2. A sheet slides up from the bottom, covering ~40% of the viewport
3. Sheet shows the sub-section list as large touch-target buttons (44px+ min height)
4. Tapping a sub-section activates that Bootstrap tab pane and dismisses the sheet
5. The bottom tab stays highlighted to show which domain group is active
6. Tapping the same bottom tab again re-opens the sheet to switch sub-sections
7. Sheet has a drag handle at the top and can be dismissed by swiping down or tapping outside

**Implementation**: A single reusable `<div class="mobile-section-sheet">` rendered once per page. JavaScript populates it with the appropriate sub-sections based on which bottom tab was tapped. Uses CSS transforms for slide animation.

---

## Bottom Tab Bar Component

A fixed-position bar at the bottom of the viewport, visible only on mobile (<992px).

**Structure:**
```html
<nav class="mobile-bottom-tabs d-lg-none">
  <button class="mobile-tab active" data-group="overview">
    <i class="fas fa-info-circle"></i>
    <span>Overview</span>
  </button>
  <button class="mobile-tab" data-group="terminal">
    <i class="fas fa-plane"></i>
    <span>Terminal</span>
  </button>
  <!-- ... -->
</nav>
```

**Rules:**
- Hidden on desktop via `d-lg-none`
- Fixed to bottom, above any browser chrome (safe area inset padding)
- Each tab button is at least 44px tall (touch target)
- Active tab uses the PERTI accent color (`#239BCD`)
- Tab count per page: plan.php (4), data.php (3), review.php (4)
- Page-specific: each PHP page renders its own bottom bar with the correct tabs and sub-section mappings

**Interaction with existing Bootstrap tabs:** The bottom bar and slide-up sheet programmatically trigger `$('a[href="#tab_id"]').tab('show')` on the existing Bootstrap tab pill links. The desktop sidebar nav pills remain in the DOM (hidden on mobile) and Bootstrap's tab state is shared.

---

## CSS Architecture

### Cleanup dead code
Remove unused selectors from `mobile.css`:
- `.plan-nav.nav.flex-column` (line 688)
- `.plan-sidebar` (line 711)
- `.plan-content` (line 718)

### New CSS additions in mobile.css

| Selector | Purpose |
|----------|---------|
| `.mobile-bottom-tabs` | Fixed bottom tab bar container |
| `.mobile-tab` | Individual tab button |
| `.mobile-section-sheet` | Slide-up sheet overlay |
| `.mobile-section-sheet-item` | Tappable sub-section item in sheet |
| `.tmr-sidebar` responsive rules | Hide sidebar on mobile (currently has no responsive CSS) |

### Responsive overrides
- `col-2` sidebar containers: `d-none d-lg-block` on mobile
- `col-10` content containers: `col-12 col-lg-10` for full width on mobile
- Existing `.tab-content` panes: add bottom padding to clear the bottom tab bar
- Safe area insets: `padding-bottom: env(safe-area-inset-bottom)` on bottom bar for iPhones with home indicator

---

## JavaScript Architecture

### New file: `assets/js/mobile-nav.js`

Single module handling all mobile navigation behavior across pages. Loaded conditionally or gated by viewport width check.

**Responsibilities:**
1. Detect mobile viewport and show/hide bottom tab bar
2. Handle bottom tab tap → either switch tab directly or open slide-up sheet
3. Populate slide-up sheet with sub-sections based on page config
4. Handle sheet item tap → activate Bootstrap tab pane, dismiss sheet
5. Handle sheet dismissal (swipe down, tap outside, escape key)
6. Sync active state between bottom bar and desktop sidebar pills
7. On viewport resize (e.g., device rotation), recalculate visibility

**Page configuration:** Each page passes its tab-group mapping as a JS config object:
```javascript
MobileNav.init({
  groups: [
    { id: 'overview', label: 'Overview', icon: 'fa-info-circle', tabs: ['#overview', '#dcc_staffing'] },
    { id: 'terminal', label: 'Terminal', icon: 'fa-plane', tabs: ['#t_timelines', '#t_staffing', '#configs', '#t_planning'] },
    // ...
  ],
  sheetThreshold: 3  // slide-up sheet when 3+ tabs in group
});
```

### Modifications to existing JS
- `assets/js/plan.js`: Add `MobileNav.init()` call with plan page groups
- `assets/js/review.js` (or inline in `review.php`): Add `MobileNav.init()` call with review page groups
- `data.php` inline JS: Add `MobileNav.init()` call with data page groups
- Index page JS (dynamic table builder): Add `data-label` attributes to `<td>` elements

### i18n
All new user-facing strings use `PERTII18n.t()`. Keys to add to `en-US.json`:
- `mobile.tab.overview`, `mobile.tab.terminal`, `mobile.tab.enroute`, `mobile.tab.data`
- `mobile.tab.triggers`, `mobile.tab.review`, `mobile.tab.operations`, `mobile.tab.assessment`
- `mobile.sheet.selectSection`

---

## Implementation Order

1. **Navbar fix** — `nav.php` and `nav_public.php` `d-none d-lg-flex` addition
2. **CSS cleanup** — Remove dead code from `mobile.css`
3. **Bottom tab bar + slide-up sheet components** — New CSS in `mobile.css` + new `mobile-nav.js`
4. **plan.php** — Responsive grid classes, bottom bar integration, internal content responsiveness
5. **data.php** — Same pattern, 3-tab variant
6. **review.php** — Same pattern, 4-tab variant with Export to Discord as FAB
7. **index.php** — Card treatment for plans table, remove `<center>`
8. **airport_config.php** — Minor touch-target and form-width tweaks
9. **Testing** — Manual testing on iPhone/Android viewports across all pages

---

## Out of Scope

- Framework migration (no React/Vue/etc.)
- jQuery version upgrade
- Offline/PWA support
- New pages or features
- Desktop layout changes (all desktop views remain unchanged)
- Non-plan pages (demand.php, splits.php, route.php, etc.) — future phases
