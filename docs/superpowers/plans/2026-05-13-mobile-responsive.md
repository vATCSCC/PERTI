# Mobile Responsive Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make PERTI plan pages (`plan.php`, `data.php`, `review.php`, `airport_config.php`, `index.php`) fully functional on mobile devices with a domain-split bottom tab bar navigation pattern.

**Architecture:** Desktop layouts remain untouched. On viewports <992px, the existing Bootstrap pill sidebar is hidden and replaced by a fixed bottom tab bar. Groups with 3+ sub-sections use a slide-up sheet picker. A single new JS module (`mobile-nav.js`) handles all mobile navigation behavior. All new CSS goes into the existing `mobile.css`.

**Tech Stack:** jQuery 2.2.4, Bootstrap 4.5, vanilla JS, FontAwesome 5.15.4, CSS custom properties

**Spec:** `docs/superpowers/specs/2026-05-13-mobile-responsive-design.md`

**Testing:** No automated test suite exists. Each task includes manual browser verification at 375px (phone) and 768px (tablet) viewports. Use browser DevTools device toolbar or Playwright MCP.

---

## File Map

| Action | File | Responsibility |
|--------|------|---------------|
| Modify | `load/nav.php:191` | Hide desktop nav on mobile |
| Modify | `load/nav_public.php:168` | Hide desktop nav on mobile |
| Modify | `assets/css/mobile.css:686-722` | Remove dead `.plan-nav`/`.plan-sidebar`/`.plan-content` rules |
| Modify | `assets/css/mobile.css:375-468` | Fix `.table-mobile-cards` to use dark theme colors |
| Modify | `assets/css/mobile.css` (append) | Add bottom tab bar, slide-up sheet, sidebar-hide, content-padding CSS |
| Create | `assets/js/mobile-nav.js` | Reusable mobile navigation module |
| Modify | `assets/locales/en-US.json` | Add `mobile.*` i18n keys |
| Modify | `plan.php:193-217` | Responsive grid classes, bottom bar HTML, script include |
| Modify | `data.php:133-146` | Responsive grid classes, bottom bar HTML, script include |
| Modify | `review.php:510-545` | Responsive grid classes, bottom bar HTML, Export FAB, script include |
| Modify | `index.php:130-155` | Remove `<center>`, add `table-mobile-cards` class |
| Modify | `api/data/plans.l.php:217-280` | Add `data-label` attrs and CSS classes to `<td>` elements |
| Modify | `airport_config.php` | Minor touch-target and form-width tweaks |

---

### Task 1: Fix Navbar — Hide Desktop Nav Items on Mobile

**Files:**
- Modify: `load/nav.php:191`
- Modify: `load/nav_public.php:168`

**Context:** Both files have a `<div class="d-flex align-items-left order-lg-3">` wrapping the desktop navbar links. This div is NOT inside a Bootstrap `navbar-collapse`, so it displays on all screen sizes — including mobile, where it overlaps with the hamburger menu. Adding `d-none d-lg-flex` hides it below the `lg` breakpoint (992px).

- [ ] **Step 1: Edit `load/nav.php` line 191**

Change:
```php
    <div class="d-flex align-items-left order-lg-3">
```
To:
```php
    <div class="d-none d-lg-flex align-items-left order-lg-3">
```

- [ ] **Step 2: Edit `load/nav_public.php` line 168**

Change:
```php
    <div class="d-flex align-items-left order-lg-3">
```
To:
```php
    <div class="d-none d-lg-flex align-items-left order-lg-3">
```

- [ ] **Step 3: Verify**

Open any PERTI page (e.g., `index.php`) in browser DevTools at 375px width. Confirm:
- Desktop nav links are hidden
- Hamburger menu icon is visible and opens the offcanvas sidebar
- At 992px+ width, desktop nav links reappear

- [ ] **Step 4: Commit**

```bash
git add load/nav.php load/nav_public.php
git commit -m "fix: hide desktop nav items on mobile viewports

Add d-none d-lg-flex to the desktop nav wrapper div in both nav.php
and nav_public.php so only the offcanvas hamburger menu shows on
mobile (<992px)."
```

---

### Task 2: Remove Dead CSS from mobile.css

**Files:**
- Modify: `assets/css/mobile.css:686-722`

**Context:** Lines 686-722 define responsive rules for `.plan-nav.nav.flex-column`, `.plan-sidebar`, and `.plan-content`. These CSS classes are never used in any PHP or JS file — confirmed by grep. They are dead code from a previous implementation attempt.

- [ ] **Step 1: Delete the dead CSS block**

Remove the entire block from line 686 to line 722 in `assets/css/mobile.css`:
```css
@media (max-width: 991.98px) {
    /* Convert vertical nav to horizontal scrolling tabs */
    .plan-nav.nav.flex-column {
        flex-direction: row !important;
        flex-wrap: nowrap;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        padding-bottom: 8px;
        margin-bottom: 16px;
        border-bottom: 2px solid var(--gray-300);
        gap: 4px;
    }

    .plan-nav.nav.flex-column .nav-link {
        white-space: nowrap;
        padding: 10px 16px;
        border-radius: 20px;
        font-size: 0.9rem;
    }

    .plan-nav.nav.flex-column hr {
        display: none;
    }

    /* Sidebar becomes full width */
    .plan-sidebar {
        flex: 0 0 100% !important;
        max-width: 100% !important;
        margin-bottom: 16px;
    }

    /* Main content full width */
    .plan-content {
        flex: 0 0 100% !important;
        max-width: 100% !important;
    }
}
```

Replace the entire block with this section header comment (to preserve section numbering):
```css
/* =============================================================================
   SECTION 7: PAGE-SPECIFIC - PLAN / DATA / REVIEW PAGES (Mobile Nav)
   ============================================================================= */

/* Bottom tab bar, slide-up sheet, and sidebar-hide rules are appended
   at the end of this file in Section 12. */
```

- [ ] **Step 2: Verify**

Confirm no visual change on any page at any viewport — these were dead rules targeting classes that don't exist in HTML.

- [ ] **Step 3: Commit**

```bash
git add assets/css/mobile.css
git commit -m "chore: remove dead plan-nav/plan-sidebar/plan-content CSS

These selectors were never used in any PHP or JS file. Replaced with
a placeholder comment pointing to the new Section 12."
```

---

### Task 3: Fix table-mobile-cards for Dark Theme

**Files:**
- Modify: `assets/css/mobile.css:372-468`

**Context:** The existing `.table-mobile-cards` CSS uses light-theme colors (`background: #fff`, `border: 1px solid var(--gray-300)`, `color: var(--gray-600)`). PERTI uses a dark theme. These card styles need to use the dark theme CSS variables so cards are visible when the class is applied.

- [ ] **Step 1: Update card background and border colors**

In `assets/css/mobile.css`, within the `@media (max-width: 767.98px)` block starting at line 373, replace:

```css
    .table-mobile-cards tbody tr {
        display: block;
        margin-bottom: 12px;
        background: #fff;
        border: 1px solid var(--gray-300);
        border-radius: 8px;
        padding: 12px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
    }

    .table-mobile-cards tbody tr:hover {
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.12);
    }
```

With:
```css
    .table-mobile-cards tbody tr {
        display: block;
        margin-bottom: 12px;
        background: var(--dark-bg-panel, #16213e);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 8px;
        padding: 12px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
    }

    .table-mobile-cards tbody tr:hover {
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
    }
```

- [ ] **Step 2: Update cell border and label colors**

Replace:
```css
    .table-mobile-cards tbody td {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 8px 0;
        border: none;
        border-bottom: 1px solid #f0f0f0;
        text-align: right;
    }
```

With:
```css
    .table-mobile-cards tbody td {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 8px 0;
        border: none;
        border-bottom: 1px solid rgba(255, 255, 255, 0.06);
        text-align: right;
        color: rgba(255, 255, 255, 0.85);
    }
```

- [ ] **Step 3: Update label color**

Replace:
```css
    .table-mobile-cards tbody td::before {
        content: attr(data-label);
        font-weight: 600;
        color: var(--gray-600);
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.02em;
        flex-shrink: 0;
        margin-right: 12px;
        text-align: left;
    }
```

With:
```css
    .table-mobile-cards tbody td::before {
        content: attr(data-label);
        font-weight: 600;
        color: rgba(255, 255, 255, 0.5);
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.02em;
        flex-shrink: 0;
        margin-right: 12px;
        text-align: left;
    }
```

- [ ] **Step 4: Update primary cell styling**

Replace:
```css
    .table-mobile-cards tbody td.td-primary,
    .table-mobile-cards tbody td[data-label="ACID"],
    .table-mobile-cards tbody td[data-label="Callsign"] {
        font-weight: 700;
        font-size: 1rem;
        background: var(--gray-100);
        margin: -12px -12px 8px -12px;
        padding: 10px 12px !important;
        border-radius: 8px 8px 0 0;
        border-bottom: 2px solid var(--gray-200);
        justify-content: flex-start;
    }
```

With:
```css
    .table-mobile-cards tbody td.td-primary,
    .table-mobile-cards tbody td[data-label="ACID"],
    .table-mobile-cards tbody td[data-label="Callsign"] {
        font-weight: 700;
        font-size: 1rem;
        background: rgba(255, 255, 255, 0.04);
        margin: -12px -12px 8px -12px;
        padding: 10px 12px !important;
        border-radius: 8px 8px 0 0;
        border-bottom: 2px solid rgba(255, 255, 255, 0.1);
        justify-content: flex-start;
        color: #fff;
    }
```

- [ ] **Step 5: Commit**

```bash
git add assets/css/mobile.css
git commit -m "fix: update table-mobile-cards CSS for dark theme

Replace hardcoded light colors (#fff, var(--gray-*)) with dark theme
variables and rgba values matching PERTI's dark UI."
```

---

### Task 4: Add i18n Keys

**Files:**
- Modify: `assets/locales/en-US.json`

**Context:** The locale file is a flat-nested JSON object. Keys are organized alphabetically by top-level namespace. We need to add a `mobile` namespace for bottom tab bar labels and the slide-up sheet header.

- [ ] **Step 1: Add mobile namespace to en-US.json**

Find the location in `en-US.json` between the `"map"` and `"nav"` top-level keys (alphabetical order). Insert the following block:

```json
  "mobile": {
    "tab": {
      "overview": "Overview",
      "terminal": "Terminal",
      "enroute": "En-Route",
      "data": "Data",
      "triggers": "Triggers",
      "review": "Review",
      "operations": "Operations",
      "assessment": "Assessment"
    },
    "sheet": {
      "selectSection": "Select Section",
      "swipeToClose": "Swipe down to close"
    }
  },
```

- [ ] **Step 2: Verify JSON is valid**

Run:
```bash
python -c "import json; json.load(open('assets/locales/en-US.json', encoding='utf-8')); print('Valid JSON')"
```
Expected: `Valid JSON`

- [ ] **Step 3: Commit**

```bash
git add assets/locales/en-US.json
git commit -m "feat(i18n): add mobile navigation keys to en-US.json

Adds mobile.tab.* and mobile.sheet.* keys for bottom tab bar labels
and slide-up sheet UI strings."
```

---

### Task 5: Add Bottom Tab Bar + Slide-Up Sheet + Sidebar-Hide CSS

**Files:**
- Modify: `assets/css/mobile.css` (append at end, before final closing brace)

**Context:** All new CSS for the mobile navigation system goes into a new Section 12 at the end of `mobile.css`. This includes the fixed bottom tab bar, the slide-up section picker sheet, responsive sidebar-hiding rules, and content padding adjustments.

- [ ] **Step 1: Append new Section 12 to mobile.css**

Add the following at the end of `assets/css/mobile.css` (after the scrollbar section that ends at line 1010):

```css
/* =============================================================================
   SECTION 12: MOBILE BOTTOM TAB BAR & SLIDE-UP SHEET
   ============================================================================= */

/* --- Bottom Tab Bar --- */
.mobile-bottom-tabs {
    display: none;
}

@media (max-width: 991.98px) {
    .mobile-bottom-tabs {
        display: flex;
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        z-index: 1040;
        background: var(--dark-bg-panel, #16213e);
        border-top: 1px solid rgba(255, 255, 255, 0.1);
        padding: 6px 0 calc(6px + env(safe-area-inset-bottom, 0px));
        justify-content: space-around;
        align-items: stretch;
    }

    .mobile-tab {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        flex: 1;
        background: none;
        border: none;
        color: rgba(255, 255, 255, 0.45);
        font-size: 0.65rem;
        padding: 4px 2px;
        min-height: var(--touch-target-min);
        cursor: pointer;
        transition: color 0.15s;
        -webkit-tap-highlight-color: transparent;
    }

    .mobile-tab i {
        font-size: 1.15rem;
        margin-bottom: 2px;
    }

    .mobile-tab.active {
        color: #239BCD;
    }

    .mobile-tab:active {
        opacity: 0.7;
    }

    /* Segmented toggle for direct-nav groups (1-2 sub-sections) */
    .mobile-segment-toggle {
        display: flex;
        gap: 4px;
        padding: 4px;
        background: rgba(255, 255, 255, 0.06);
        border-radius: 8px;
        margin-bottom: 12px;
    }

    .mobile-segment-toggle .mobile-segment-btn {
        flex: 1;
        text-align: center;
        padding: 8px 12px;
        border-radius: 6px;
        background: none;
        border: none;
        color: rgba(255, 255, 255, 0.6);
        font-size: 0.85rem;
        font-weight: 500;
        cursor: pointer;
        transition: background 0.15s, color 0.15s;
        -webkit-tap-highlight-color: transparent;
    }

    .mobile-segment-toggle .mobile-segment-btn.active {
        background: #239BCD;
        color: #fff;
    }

    /* --- Sidebar hide on mobile --- */
    .mobile-hide-sidebar {
        display: none !important;
    }

    .mobile-full-width {
        flex: 0 0 100% !important;
        max-width: 100% !important;
    }

    /* Extra bottom padding on tab content to clear the bottom bar */
    .mobile-bottom-tabs ~ .container-fluid .tab-content,
    .has-mobile-tabs .tab-content {
        padding-bottom: calc(70px + env(safe-area-inset-bottom, 0px));
    }
}

/* --- Slide-Up Sheet --- */
.mobile-section-sheet-backdrop {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 1041;
    background: rgba(0, 0, 0, 0.5);
    opacity: 0;
    transition: opacity 0.2s;
}

.mobile-section-sheet-backdrop.show {
    display: block;
    opacity: 1;
}

.mobile-section-sheet {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    z-index: 1042;
    background: var(--dark-bg-page, #1a1a2e);
    border-radius: 16px 16px 0 0;
    transform: translateY(100%);
    transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    max-height: 60vh;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
    padding-bottom: env(safe-area-inset-bottom, 0px);
}

.mobile-section-sheet.show {
    transform: translateY(0);
}

.mobile-section-sheet-handle {
    width: 36px;
    height: 4px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 2px;
    margin: 10px auto 4px;
}

.mobile-section-sheet-title {
    color: rgba(255, 255, 255, 0.5);
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    padding: 4px 20px 8px;
}

.mobile-section-sheet-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 20px;
    color: rgba(255, 255, 255, 0.85);
    font-size: 0.95rem;
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);
    cursor: pointer;
    transition: background 0.15s;
    -webkit-tap-highlight-color: transparent;
}

.mobile-section-sheet-item:last-child {
    border-bottom: none;
}

.mobile-section-sheet-item:active {
    background: rgba(255, 255, 255, 0.06);
}

.mobile-section-sheet-item i {
    width: 20px;
    text-align: center;
    color: rgba(255, 255, 255, 0.4);
    font-size: 0.9rem;
}

.mobile-section-sheet-item.active {
    color: #239BCD;
}

.mobile-section-sheet-item.active i {
    color: #239BCD;
}

/* --- Export FAB (review page) --- */
.mobile-export-fab {
    display: none;
}

@media (max-width: 991.98px) {
    .mobile-export-fab {
        display: flex;
        position: fixed;
        right: 16px;
        bottom: calc(70px + env(safe-area-inset-bottom, 0px));
        z-index: 1039;
        width: 48px;
        height: 48px;
        border-radius: 50%;
        background: #28a745;
        color: #fff;
        border: none;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
        cursor: pointer;
        -webkit-tap-highlight-color: transparent;
    }

    .mobile-export-fab:active {
        transform: scale(0.92);
    }
}
```

- [ ] **Step 2: Verify CSS parses**

Open any PERTI page in a browser and check DevTools console for CSS parse errors. There should be none.

- [ ] **Step 3: Commit**

```bash
git add assets/css/mobile.css
git commit -m "feat: add bottom tab bar, slide-up sheet, and sidebar-hide CSS

New Section 12 in mobile.css with all styles for the mobile navigation
system: fixed bottom tab bar, slide-up section picker sheet, segmented
toggles for direct-nav groups, sidebar-hide utilities, and Export FAB
for the review page."
```

---

### Task 6: Create mobile-nav.js Module

**Files:**
- Create: `assets/js/mobile-nav.js`

**Context:** This is the single reusable JavaScript module that handles all mobile navigation behavior. Each page calls `MobileNav.init()` with a configuration object specifying tab groups and their associated Bootstrap tab IDs. The module renders the bottom tab bar HTML, handles tap interactions, manages the slide-up sheet, and syncs with existing Bootstrap tab state.

- [ ] **Step 1: Create `assets/js/mobile-nav.js`**

```javascript
/**
 * MobileNav — Mobile bottom tab bar + slide-up sheet navigation.
 *
 * Usage:
 *   MobileNav.init({
 *     groups: [
 *       { id: 'overview', icon: 'fa-info-circle', tabs: ['#overview', '#dcc_staffing'] },
 *       { id: 'terminal', icon: 'fa-plane', tabs: ['#t_timelines', '#t_staffing', '#configs', '#t_planning'] },
 *     ],
 *     sheetThreshold: 3,  // slide-up sheet when group has >= this many tabs
 *     sidebarSelector: null  // optional: selector for the sidebar col-2 to hide
 *   });
 */
var MobileNav = (function($) {
    'use strict';

    var LG_BREAKPOINT = 992;
    var config = null;
    var $bottomBar = null;
    var $sheet = null;
    var $backdrop = null;
    var activeGroupId = null;
    var initialized = false;

    // --- i18n helper (falls back to raw key) ---
    function t(key) {
        return (typeof PERTII18n !== 'undefined' && PERTII18n.t) ? PERTII18n.t(key) : key.split('.').pop();
    }

    // --- Check if mobile viewport ---
    function isMobile() {
        return window.innerWidth < LG_BREAKPOINT;
    }

    // --- Find which group a tab belongs to ---
    function findGroupForTab(tabId) {
        if (!config) return null;
        var normalized = tabId.replace(/^#/, '');
        for (var i = 0; i < config.groups.length; i++) {
            var g = config.groups[i];
            for (var j = 0; j < g.tabs.length; j++) {
                if (g.tabs[j].replace(/^#/, '') === normalized) return g;
            }
        }
        return null;
    }

    // --- Get the label for a tab ID from the desktop nav pill ---
    function getTabLabel(tabId) {
        var $link = $('a[data-toggle="tab"][href="' + tabId + '"]');
        if ($link.length) {
            // Strip icons, get text only
            var $clone = $link.clone();
            $clone.find('i').remove();
            return $.trim($clone.text());
        }
        // Fallback: humanize the ID
        return tabId.replace(/^#/, '').replace(/_/g, ' ').replace(/\btmr\b/i, '').trim();
    }

    // --- Get FontAwesome icon class for a tab from the desktop nav pill ---
    function getTabIcon(tabId) {
        var $icon = $('a[data-toggle="tab"][href="' + tabId + '"] i.fas');
        if ($icon.length) {
            var classes = $icon.attr('class').split(/\s+/);
            for (var i = 0; i < classes.length; i++) {
                if (classes[i].indexOf('fa-') === 0 && classes[i] !== 'fa-fw') return classes[i];
            }
        }
        return 'fa-circle';
    }

    // --- Render bottom bar HTML ---
    function renderBottomBar() {
        var html = '<nav class="mobile-bottom-tabs" role="tablist">';
        for (var i = 0; i < config.groups.length; i++) {
            var g = config.groups[i];
            var label = t('mobile.tab.' + g.id);
            var activeClass = (i === 0) ? ' active' : '';
            html += '<button class="mobile-tab' + activeClass + '" data-group="' + g.id + '" role="tab" aria-label="' + label + '">';
            html += '<i class="fas ' + g.icon + '"></i>';
            html += '<span>' + label + '</span>';
            html += '</button>';
        }
        html += '</nav>';
        return html;
    }

    // --- Render sheet HTML (empty, populated on demand) ---
    function renderSheet() {
        var html = '<div class="mobile-section-sheet-backdrop"></div>';
        html += '<div class="mobile-section-sheet">';
        html += '<div class="mobile-section-sheet-handle"></div>';
        html += '<div class="mobile-section-sheet-title"></div>';
        html += '<div class="mobile-section-sheet-body"></div>';
        html += '</div>';
        return html;
    }

    // --- Render segmented toggle for a group ---
    function renderSegmentToggle(group) {
        var html = '<div class="mobile-segment-toggle" data-group="' + group.id + '">';
        for (var i = 0; i < group.tabs.length; i++) {
            var tabId = group.tabs[i];
            var label = getTabLabel(tabId);
            var activeClass = (i === 0) ? ' active' : '';
            html += '<button class="mobile-segment-btn' + activeClass + '" data-tab="' + tabId + '">' + label + '</button>';
        }
        html += '</div>';
        return html;
    }

    // --- Open slide-up sheet for a group ---
    function openSheet(group) {
        var $body = $sheet.find('.mobile-section-sheet-body');
        $body.empty();

        $sheet.find('.mobile-section-sheet-title').text(t('mobile.sheet.selectSection'));

        // Get currently active tab
        var $activePane = $('.tab-pane.active.show');
        var activePaneId = $activePane.length ? '#' + $activePane.attr('id') : '';

        for (var i = 0; i < group.tabs.length; i++) {
            var tabId = group.tabs[i];
            var label = getTabLabel(tabId);
            var icon = getTabIcon(tabId);
            var isActive = (tabId === activePaneId) ? ' active' : '';
            $body.append(
                '<div class="mobile-section-sheet-item' + isActive + '" data-tab="' + tabId + '">' +
                '<i class="fas ' + icon + '"></i>' +
                '<span>' + label + '</span>' +
                '</div>'
            );
        }

        $backdrop.addClass('show');
        $sheet.addClass('show');
    }

    // --- Close slide-up sheet ---
    function closeSheet() {
        $sheet.removeClass('show');
        $backdrop.removeClass('show');
    }

    // --- Activate a tab (delegates to Bootstrap) ---
    function activateTab(tabId) {
        var $link = $('a[data-toggle="tab"][href="' + tabId + '"]');
        if ($link.length) {
            $link.tab('show');
        }
    }

    // --- Set active group in bottom bar ---
    function setActiveGroup(groupId) {
        activeGroupId = groupId;
        $bottomBar.find('.mobile-tab').removeClass('active');
        $bottomBar.find('.mobile-tab[data-group="' + groupId + '"]').addClass('active');
    }

    // --- Handle group tap ---
    function onGroupTap(groupId) {
        var group = null;
        for (var i = 0; i < config.groups.length; i++) {
            if (config.groups[i].id === groupId) { group = config.groups[i]; break; }
        }
        if (!group) return;

        var threshold = config.sheetThreshold || 3;

        if (group.tabs.length >= threshold) {
            // Slide-up sheet
            if (activeGroupId === groupId && $sheet.hasClass('show')) {
                closeSheet();
            } else {
                setActiveGroup(groupId);
                openSheet(group);
            }
        } else {
            // Direct navigation — activate the first tab in the group
            // and show segment toggle
            setActiveGroup(groupId);
            activateTab(group.tabs[0]);
            showSegmentToggle(group);
        }
    }

    // --- Show/hide segment toggle strips ---
    function showSegmentToggle(group) {
        // Remove any existing segment toggles
        $('.mobile-segment-toggle').remove();

        if (group.tabs.length <= 1) return;

        // Insert segment toggle at the top of the active tab pane
        var $toggle = $(renderSegmentToggle(group));
        var $tabContent = $('.tab-content').first();
        $tabContent.prepend($toggle);

        // Sync active state with currently visible pane
        var $activePane = $('.tab-pane.active.show');
        if ($activePane.length) {
            $toggle.find('.mobile-segment-btn').removeClass('active');
            $toggle.find('.mobile-segment-btn[data-tab="#' + $activePane.attr('id') + '"]').addClass('active');
        }
    }

    // --- Handle tab shown event from Bootstrap (sync bottom bar) ---
    function onTabShown(e) {
        if (!isMobile()) return;
        var tabId = $(e.target).attr('href');
        var group = findGroupForTab(tabId);
        if (group) {
            setActiveGroup(group.id);

            // Update segment toggle if visible
            var $toggle = $('.mobile-segment-toggle[data-group="' + group.id + '"]');
            if ($toggle.length) {
                $toggle.find('.mobile-segment-btn').removeClass('active');
                $toggle.find('.mobile-segment-btn[data-tab="' + tabId + '"]').addClass('active');
            }
        }
    }

    // --- Handle viewport resize ---
    function onResize() {
        if (!config) return;
        if (isMobile()) {
            $bottomBar.show();
            // Hide sidebar
            if (config.sidebarSelector) {
                $(config.sidebarSelector).addClass('mobile-hide-sidebar');
            }
            // Make content full width
            if (config.contentSelector) {
                $(config.contentSelector).addClass('mobile-full-width');
            }
            // Add body class for padding
            $('body').addClass('has-mobile-tabs');
        } else {
            $bottomBar.hide();
            closeSheet();
            $('.mobile-segment-toggle').remove();
            if (config.sidebarSelector) {
                $(config.sidebarSelector).removeClass('mobile-hide-sidebar');
            }
            if (config.contentSelector) {
                $(config.contentSelector).removeClass('mobile-full-width');
            }
            $('body').removeClass('has-mobile-tabs');
        }
    }

    // --- Touch handling for sheet swipe-to-dismiss ---
    function initSheetTouch() {
        var startY = 0;
        var currentY = 0;
        var isDragging = false;
        var sheetEl = $sheet[0];

        sheetEl.addEventListener('touchstart', function(e) {
            startY = e.touches[0].clientY;
            isDragging = true;
        }, { passive: true });

        sheetEl.addEventListener('touchmove', function(e) {
            if (!isDragging) return;
            currentY = e.touches[0].clientY;
            var diff = currentY - startY;
            if (diff > 0) {
                sheetEl.style.transform = 'translateY(' + diff + 'px)';
            }
        }, { passive: true });

        sheetEl.addEventListener('touchend', function() {
            if (!isDragging) return;
            isDragging = false;
            var diff = currentY - startY;
            if (diff > 80) {
                closeSheet();
            }
            sheetEl.style.transform = '';
        }, { passive: true });
    }

    // --- Public init ---
    function init(cfg) {
        if (initialized) return;
        config = cfg;
        initialized = true;

        // Inject bottom bar
        var barHtml = renderBottomBar();
        $('body').append(barHtml);
        $bottomBar = $('.mobile-bottom-tabs');

        // Inject sheet
        var sheetHtml = renderSheet();
        $('body').append(sheetHtml);
        $sheet = $('.mobile-section-sheet');
        $backdrop = $('.mobile-section-sheet-backdrop');

        // Set initial active group
        activeGroupId = config.groups[0].id;

        // --- Event handlers ---

        // Bottom tab tap
        $bottomBar.on('click', '.mobile-tab', function() {
            onGroupTap($(this).data('group'));
        });

        // Sheet item tap
        $sheet.on('click', '.mobile-section-sheet-item', function() {
            var tabId = $(this).data('tab');
            activateTab(tabId);
            closeSheet();
        });

        // Backdrop tap
        $backdrop.on('click', function() {
            closeSheet();
        });

        // Segment toggle tap
        $(document).on('click', '.mobile-segment-btn', function() {
            var tabId = $(this).data('tab');
            activateTab(tabId);
            // Update active state
            $(this).closest('.mobile-segment-toggle').find('.mobile-segment-btn').removeClass('active');
            $(this).addClass('active');
        });

        // Escape key
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape' && $sheet.hasClass('show')) {
                closeSheet();
            }
        });

        // Bootstrap tab shown event
        $(document).on('shown.bs.tab', 'a[data-toggle="tab"]', onTabShown);

        // Viewport resize
        $(window).on('resize', onResize);

        // Touch swipe on sheet
        initSheetTouch();

        // Initial state
        onResize();
    }

    return { init: init };

})(jQuery);
```

- [ ] **Step 2: Verify file exists and has no syntax errors**

Open browser DevTools console after including the script. There should be no JS errors. `MobileNav` should be defined as a global object.

- [ ] **Step 3: Commit**

```bash
git add assets/js/mobile-nav.js
git commit -m "feat: create mobile-nav.js module

Reusable bottom tab bar + slide-up sheet navigation for mobile. Each
page calls MobileNav.init() with a group config mapping tab IDs to
domain categories. Handles direct navigation, slide-up sheet picker,
segment toggles, touch swipe dismiss, and Bootstrap tab state sync."
```

---

### Task 7: Integrate plan.php

**Files:**
- Modify: `plan.php:193-217` (sidebar grid classes)
- Modify: `plan.php` (add script include and MobileNav.init call)

**Context:** `plan.php` has a `col-2` sidebar at line 195 and `col-10` content at line 217. These need responsive classes added. The bottom bar HTML is rendered dynamically by `mobile-nav.js`, so we just need to include the script and call `MobileNav.init()`. The `#overview` tab also has `col-6` columns at line 222 that need responsive classes.

- [ ] **Step 1: Add responsive classes to the sidebar div**

In `plan.php`, change line 195:
```php
            <div class="col-2">
```
To:
```php
            <div class="col-2 d-none d-lg-block" id="plan-sidebar">
```

- [ ] **Step 2: Add responsive classes to the content div**

Change line 217:
```php
            <div class="col-10">
```
To:
```php
            <div class="col-12 col-lg-10">
```

- [ ] **Step 3: Add responsive classes to overview tab columns**

Change line 222:
```php
                            <div class="col-6">
```
To:
```php
                            <div class="col-12 col-md-6">
```

Find the other `col-6` in the overview tab (the right column) and make the same change. Search for the next `col-6` after line 222 within the `#overview` tab pane.

- [ ] **Step 4: Add mobile-nav.js script include and init call**

After line 2274 (after the `plan.js` script tag), add:

```php
<script src="assets/js/mobile-nav.js<?= _v('assets/js/mobile-nav.js') ?>"></script>
<script>
$(function() {
    MobileNav.init({
        groups: [
            { id: 'overview', icon: 'fa-info-circle', tabs: ['#overview', '#dcc_staffing'] },
            { id: 'terminal', icon: 'fa-plane', tabs: ['#t_timelines', '#t_staffing', '#configs', '#t_planning'] },
            { id: 'enroute',  icon: 'fa-globe-americas', tabs: ['#e_timelines', '#e_staffing', '#e_planning', '#e_splits'] },
            { id: 'data',     icon: 'fa-chart-bar', tabs: ['#historical', '#forecast', '#group_flights', '#outlook'] }
        ],
        sheetThreshold: 3,
        sidebarSelector: '#plan-sidebar',
        contentSelector: '#plan-sidebar + div'
    });
});
</script>
```

- [ ] **Step 5: Verify at 375px**

Open `plan.php` in browser DevTools at 375px:
- Sidebar is hidden
- Bottom tab bar shows 4 tabs: Overview, Terminal, En-Route, Data
- Tapping "Overview" shows segment toggle with Overview / DCC Staffing
- Tapping "Terminal" opens slide-up sheet with 4 sub-sections
- Tapping a sheet item switches to that tab and dismisses the sheet
- At 992px+, sidebar reappears and bottom bar disappears

- [ ] **Step 6: Commit**

```bash
git add plan.php assets/js/mobile-nav.js
git commit -m "feat: integrate mobile bottom tab bar into plan.php

Hide sidebar on mobile, show 4-tab domain-split bottom bar (Overview,
Terminal, En-Route, Data). Groups with 3+ items use slide-up sheet.
Add responsive column classes to overview tab."
```

---

### Task 8: Integrate data.php

**Files:**
- Modify: `data.php:133-146` (sidebar grid classes)
- Modify: `data.php` (add script include and MobileNav.init call)

**Context:** `data.php` uses the same `col-2`/`col-10` pattern. It has only 6 sections split into 3 groups of 2, so all groups use direct navigation (no slide-up sheets). It loads scripts at line 588.

- [ ] **Step 1: Add responsive classes to the sidebar div**

In `data.php`, change line 135:
```php
            <div class="col-2">
```
To:
```php
            <div class="col-2 d-none d-lg-block" id="data-sidebar">
```

- [ ] **Step 2: Add responsive classes to the content div**

Change line 146:
```php
            <div class="col-10">
```
To:
```php
            <div class="col-12 col-lg-10">
```

- [ ] **Step 3: Add responsive classes to overview tab columns**

Change line 151:
```php
                            <div class="col-6">
```
To:
```php
                            <div class="col-12 col-md-6">
```

Find and change the other `col-6` in the overview tab similarly.

- [ ] **Step 4: Add mobile-nav.js script include and init call**

After line 591 (after the `sheet.js` script tag), add:

```php
<script src="assets/js/mobile-nav.js<?= _v('assets/js/mobile-nav.js') ?>"></script>
<script>
$(function() {
    MobileNav.init({
        groups: [
            { id: 'overview', icon: 'fa-info-circle', tabs: ['#overview', '#dcc_staffing'] },
            { id: 'terminal', icon: 'fa-plane', tabs: ['#t_staffing', '#configs'] },
            { id: 'enroute',  icon: 'fa-globe-americas', tabs: ['#e_staffing', '#e_splits'] }
        ],
        sheetThreshold: 3,
        sidebarSelector: '#data-sidebar',
        contentSelector: '#data-sidebar + div'
    });
});
</script>
```

- [ ] **Step 5: Verify at 375px**

Open `data.php` in browser DevTools at 375px:
- Sidebar is hidden
- Bottom tab bar shows 3 tabs: Overview, Terminal, En-Route
- Tapping any tab shows segment toggle (2 sub-sections each)
- All navigation direct — no slide-up sheets
- At 992px+, sidebar reappears

- [ ] **Step 6: Commit**

```bash
git add data.php
git commit -m "feat: integrate mobile bottom tab bar into data.php

Hide sidebar on mobile, show 3-tab bottom bar (Overview, Terminal,
En-Route). All groups have 2 items, so all use direct navigation
with segmented toggle."
```

---

### Task 9: Integrate review.php

**Files:**
- Modify: `review.php:510-545` (sidebar grid classes)
- Modify: `review.php` (add script include, MobileNav.init, Export FAB)

**Context:** `review.php` has the `tmr-sidebar` class and inline sticky styles on the sidebar. The Export to Discord button (line 534, `#tmr_export_btn`) needs to become a floating action button on mobile. The review page includes `review.js` at line 1319.

- [ ] **Step 1: Add responsive classes to the sidebar div**

In `review.php`, change line 513:
```php
            <div class="col-2">
```
To:
```php
            <div class="col-2 d-none d-lg-block" id="tmr-sidebar-col">
```

- [ ] **Step 2: Add responsive classes to the content div**

Change line 545:
```php
            <div class="col-10">
```
To:
```php
            <div class="col-12 col-lg-10">
```

- [ ] **Step 3: Add Export to Discord FAB markup**

After the closing `</div>` of the `container-fluid` (after the tab content area), add:

```php
<!-- Mobile Export FAB -->
<button class="mobile-export-fab" id="tmr_export_fab" title="<?= __('review.page.exportToDiscord') ?>">
    <i class="fas fa-share-alt"></i>
</button>
```

- [ ] **Step 4: Add mobile-nav.js script include and init call**

After line 1320 (after the `tmr_report.js` script tag), add:

```php
<script src="assets/js/mobile-nav.js<?= _v('assets/js/mobile-nav.js') ?>"></script>
<script>
$(function() {
    MobileNav.init({
        groups: [
            { id: 'triggers',   icon: 'fa-bolt', tabs: ['#tmr_triggers'] },
            { id: 'review',     icon: 'fa-align-left', tabs: ['#tmr_overview', '#tmr_airport', '#tmr_weather', '#tmr_events'] },
            { id: 'operations', icon: 'fa-traffic-light', tabs: ['#tmr_tmis', '#tmr_equipment', '#tmr_personnel', '#tmr_plan'] },
            { id: 'assessment', icon: 'fa-star', tabs: ['#tmr_findings', '#tmr_recs', '#scoring', '#event_data', '#tmi_compliance'] }
        ],
        sheetThreshold: 3,
        sidebarSelector: '#tmr-sidebar-col',
        contentSelector: '#tmr-sidebar-col + div'
    });

    // Wire up mobile Export FAB to same handler as desktop button
    $('#tmr_export_fab').on('click', function() {
        $('#tmr_export_btn').trigger('click');
    });
});
</script>
```

- [ ] **Step 5: Verify at 375px**

Open `review.php` in browser DevTools at 375px:
- Sidebar is hidden
- Bottom tab bar shows 4 tabs: Triggers, Review, Operations, Assessment
- Tapping "Triggers" navigates directly (1 item)
- Tapping "Review" opens slide-up sheet with 4 items
- Tapping "Operations" opens slide-up sheet with 4 items
- Tapping "Assessment" opens slide-up sheet with 5 items
- Green FAB button visible in bottom-right corner
- At 992px+, sidebar reappears, FAB disappears, bottom bar disappears

- [ ] **Step 6: Commit**

```bash
git add review.php
git commit -m "feat: integrate mobile bottom tab bar into review.php

Hide tmr-sidebar on mobile, show 4-tab bottom bar (Triggers, Review,
Operations, Assessment). Export to Discord becomes a floating action
button on mobile."
```

---

### Task 10: Mobile Cards for index.php Plans Table

**Files:**
- Modify: `index.php:130-154` (remove `<center>`, add `table-mobile-cards`)
- Modify: `api/data/plans.l.php:217-280` (add `data-label` and CSS classes to `<td>` elements)

**Context:** The plans table on `index.php` has server-rendered rows from `api/data/plans.l.php`. The `render_plan_row()` function generates `<td>` elements without `data-label` attributes. The `<center>` tag wrapping the table section is deprecated HTML.

- [ ] **Step 1: Fix index.php — remove `<center>` and add table class**

In `index.php`, change lines 131 and 154:

Replace:
```php
        <center>
            <h3><?= __('home.pertiPlans') ?></h3>
            <p><?= __('home.plansDescription') ?></p>
```
With:
```php
        <div class="text-center">
            <h3><?= __('home.pertiPlans') ?></h3>
            <p><?= __('home.plansDescription') ?></p>
```

Replace:
```php
            <table class="table table-sm table-striped table-bordered w-100" style="table-layout:fixed;">
```
With:
```php
            <table class="table table-sm table-striped table-bordered w-100 table-mobile-cards" style="table-layout:fixed;">
```

Replace the closing:
```php
        </center>
```
With:
```php
        </div>
```

- [ ] **Step 2: Add `data-label` and CSS classes to `render_plan_row()` in `api/data/plans.l.php`**

In `api/data/plans.l.php`, modify the `render_plan_row()` function. Replace the table cell output section (lines ~220-280):

Replace:
```php
    echo '<td class="plan-event-name">';
```
With:
```php
    echo '<td class="plan-event-name td-primary" data-label="' . __('home.table.eventName') . '">';
```

Replace:
```php
    echo '<td class="text-center">' . $data['event_date'] . '</td>';
    echo '<td class="text-center">' . $data['event_start'] . 'Z</td>';
    echo !empty($event_end_date) ? '<td class="text-center">' . $event_end_date . '</td>' : '<td class="text-center text-muted">&mdash;</td>';
    echo !empty($event_end_time) ? '<td class="text-center">' . $event_end_time . 'Z</td>' : '<td class="text-center text-muted">&mdash;</td>';
```
With:
```php
    echo '<td class="text-center" data-label="' . __('home.table.startDate') . '">' . $data['event_date'] . '</td>';
    echo '<td class="text-center" data-label="' . __('home.table.startTime') . '">' . $data['event_start'] . 'Z</td>';
    echo !empty($event_end_date) ? '<td class="text-center" data-label="' . __('home.table.endDate') . '">' . $event_end_date . '</td>' : '<td class="text-center text-muted" data-label="' . __('home.table.endDate') . '">&mdash;</td>';
    echo !empty($event_end_time) ? '<td class="text-center" data-label="' . __('home.table.endTime') . '">' . $event_end_time . 'Z</td>' : '<td class="text-center text-muted" data-label="' . __('home.table.endTime') . '">&mdash;</td>';
```

Replace the OpLevel cell:
```php
    echo '<td class="' . ($ol_classes[$ol] ?? 'text-dark') . ' text-center" style="white-space:nowrap;">' . $ol . ' - ' . ($ol_labels[$ol] ?? '') . '</td>';
```
With:
```php
    echo '<td class="' . ($ol_classes[$ol] ?? 'text-dark') . ' text-center" style="white-space:nowrap;" data-label="' . __('home.table.tmuOpLevel') . '">' . $ol . ' - ' . ($ol_labels[$ol] ?? '') . '</td>';
```

Replace the Updated At cell:
```php
    echo '<td class="text-center" style="white-space:nowrap;">' . $data['updated_at'] . '</td>';
```
With:
```php
    echo '<td class="text-center" style="white-space:nowrap;" data-label="' . __('home.table.lastUpdated') . '">' . $data['updated_at'] . '</td>';
```

Replace the Actions cell opener:
```php
    echo '<td style="white-space:nowrap;"><center>';
```
With:
```php
    echo '<td class="td-actions" style="white-space:nowrap;">';
```

Replace the Actions cell closer:
```php
    echo '</center></td>';
```
With:
```php
    echo '</td>';
```

- [ ] **Step 3: Verify at 375px**

Open `index.php` in browser DevTools at 375px:
- Each plan row appears as a stacked card
- Event name is the prominent header
- Each field has a label (Start Date, Start Time, etc.)
- Action badges (View, Data, TMR, Edit, Delete) are centered at the bottom of the card
- At 768px+, table format returns

- [ ] **Step 4: Commit**

```bash
git add index.php api/data/plans.l.php
git commit -m "feat: add mobile card layout to plans table on index.php

Add table-mobile-cards class and data-label attributes to plan rows.
Replace deprecated <center> tag with div.text-center. Plan rows now
display as stacked cards on phones with labeled fields."
```

---

### Task 11: airport_config.php Minor Polish

**Files:**
- Modify: `airport_config.php` (minor responsive tweaks)

**Context:** This page already mostly works on mobile via `config-stats` and `bulk-actions` CSS. This task is for minor touch-target and form-width improvements.

- [ ] **Step 1: Verify current mobile state**

Open `airport_config.php` at 375px in browser DevTools. Document what works and what doesn't:
- Check form inputs (are they full-width?)
- Check action buttons (are they at least 44px touch targets?)
- Check bulk actions panel (does it overflow?)

- [ ] **Step 2: Apply any needed fixes**

Based on findings from Step 1, apply targeted CSS fixes. Likely candidates:

Add to `assets/css/mobile.css` Section 12 (if needed):
```css
/* --- airport_config.php responsive tweaks --- */
@media (max-width: 767.98px) {
    .config-stats .form-control,
    .config-stats select {
        width: 100% !important;
        min-height: var(--touch-target-min);
    }

    .bulk-actions .btn {
        min-height: var(--touch-target-min);
        padding: 10px 16px;
    }
}
```

Only add these if the verification in Step 1 reveals the issues.

- [ ] **Step 3: Commit (if changes were needed)**

```bash
git add assets/css/mobile.css airport_config.php
git commit -m "fix: improve airport_config.php touch targets and form width on mobile"
```

If no changes were needed after verification, skip this commit.

---

### Task 12: Final Cross-Page Verification

**Files:** None (verification only)

- [ ] **Step 1: Test plan.php at 375px, 768px, and 1024px**

At each width, verify:
- Navigation works (bottom bar, slide-up sheet, segment toggles)
- All 14 sections are reachable
- Forms and tables are usable
- No horizontal overflow
- Bottom bar doesn't overlap content

- [ ] **Step 2: Test data.php at 375px, 768px, and 1024px**

Same checks. All 6 sections reachable via 3-tab bottom bar with segment toggles.

- [ ] **Step 3: Test review.php at 375px, 768px, and 1024px**

Same checks plus:
- Export to Discord FAB works
- All 14 sections reachable via 4-tab bottom bar
- TMR forms are usable on mobile

- [ ] **Step 4: Test index.php at 375px and 768px**

- Cards display correctly on phone
- Table displays correctly on tablet
- Action badges (View, Data, TMR) are tappable

- [ ] **Step 5: Test desktop (1280px+) regression**

Open all pages at full desktop width. Verify NOTHING changed:
- Sidebars visible
- Bottom bar hidden
- Tables in original format
- No new elements visible

- [ ] **Step 6: Final commit (if any fixes needed)**

Fix any issues found during testing and commit.
