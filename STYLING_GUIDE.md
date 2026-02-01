# PERTI Styling & Formatting Guide

This document provides a comprehensive reference for all styling patterns, color schemes, typography, and component conventions used across the PERTI application.

---

## Table of Contents

1. [CSS Framework & Architecture](#css-framework--architecture)
2. [Color Palettes](#color-palettes)
3. [Typography](#typography)
4. [Component Patterns](#component-patterns)
5. [Interactive UI Patterns](#interactive-ui-patterns)
6. [Layout Patterns](#layout-patterns)
7. [Responsive Design](#responsive-design)
8. [JavaScript Dynamic Styling](#javascript-dynamic-styling)
9. [Animations & Transitions](#animations--transitions)
10. [Shadows & Depth](#shadows--depth)
11. [Borders & Radius](#borders--radius)
12. [Z-Index Layering](#z-index-layering)
13. [Icon System](#icon-system)
14. [Toast & Notification System](#toast--notification-system)
15. [Loading States](#loading-states)
16. [Vendor & Plugin CSS](#vendor--plugin-css)
17. [Map UI Patterns](#map-ui-patterns)
18. [Chart & Graph Styling](#chart--graph-styling)
19. [Accessibility Patterns](#accessibility-patterns)
20. [Print Styles](#print-styles)
21. [Page-Specific Patterns](#page-specific-patterns)
22. [SCSS Component Reference](#scss-component-reference)
23. [File Reference](#file-reference)

---

## CSS Framework & Architecture

### Foundation

- **Base Framework**: Bootstrap 4.5.2 with extensive customizations
- **CSS Preprocessor**: SCSS with modular component architecture
- **Theme Override**: Custom `perti_theme.css` for brand identity

### Responsive Breakpoints

| Breakpoint | Width | Usage |
|------------|-------|-------|
| xs | 0px | Mobile phones (portrait) |
| sm | 576px | Mobile phones (landscape) |
| md | 768px | Tablets |
| lg | 992px | Small laptops |
| xl | 1280px | Desktops and larger |

### Core CSS Files

| File | Purpose |
|------|---------|
| `assets/css/theme.css` | Bootstrap 4.5.2 base (388KB) with CSS variables |
| `assets/css/perti_theme.css` | PERTI brand customizations and animations |
| `assets/css/mobile.css` | Mobile-first responsive design |
| `assets/css/info-bar.css` | Info bar component styling |
| `assets/css/helpers/_variables.scss` | SCSS color and spacing variables |
| `assets/css/helpers/_mixins.scss` | SCSS mixins for gradients and utilities |

---

## Color Palettes

### Brand Colors (Primary Palette)

| Color | Hex | SCSS Variable | Usage |
|-------|-----|---------------|-------|
| Primary | `#766df4` | `$primary` | Main brand color, links, primary buttons |
| Secondary | `#f7f7fc` | `$secondary` | Light backgrounds, secondary elements |
| Info | `#6a9bf4` | `$info` | Informational elements, highlights |
| Success | `#16c995` | `$success` | Positive actions, confirmations |
| Warning | `#ffb15c` | `$warning` | Caution indicators, alerts |
| Danger | `#f74f78` | `$danger` | Errors, destructive actions |
| Light | `#ffffff` | `$light` | Light backgrounds |
| Dark | `#37384e` | `$dark` | Text, dark backgrounds |

### Grayscale

| Color | Hex | Usage |
|-------|-----|-------|
| Gray-100 | `#f7f7fc` | Lightest backgrounds |
| Gray-200 | `#f3f3f9` | Subtle backgrounds |
| Gray-300 | `#e9e9f2` | Borders, dividers |
| Gray-400 | `#dfdfeb` | Disabled states |
| Gray-500 | `#9e9fb4` | Muted text |
| Gray-600 | `#737491` | Secondary text |
| Gray-700 | `#5a5b75` | Labels |
| Gray-800 | `#4a4b65` | Darker text |
| Gray-900 | `#37384e` | Headings, primary text |

### Flight Phase Colors

Used in `assets/js/config/phase-colors.js`:

| Phase | Hex | Badge Class |
|-------|-----|-------------|
| Arrived | `#1a1a1a` | `badge-dark` |
| Taxiing | `#22c55e` | `badge-warning` |
| Departed | `#f87171` | `badge-danger` |
| Enroute | `#dc2626` | `badge-danger` |
| Descending | `#991b1b` | `badge-danger` |
| Prefile | `#3b82f6` | `badge-primary` |
| Disconnected | `#f97316` | `badge-warning` |
| Ground Stop | `#eab308` | `badge-light` (dark text) |
| GDP | `#92400e` | `badge-warning` |
| Exempt | `#6b7280` | `badge-secondary` |
| Unknown | `#9333ea` | `badge-info` |

### Weather Impact Colors

Used in `assets/js/config/rate-colors.js`:

| Category | Hex | Description |
|----------|-----|-------------|
| VMC | `#22c55e` | Visual Meteorological Conditions (Green) |
| LVMC | `#eab308` | Low Visibility VMC (Yellow) |
| IMC | `#f97316` | Instrument Meteorological Conditions (Orange) |
| LIMC | `#ef4444` | Low IMC (Red) |
| VLIMC | `#dc2626` | Very Low IMC (Dark Red) |

### Rate Line Colors

| Type | Hex | Style |
|------|-----|-------|
| AAR (Active) | `#000000` | Solid line |
| ADR (Dashed) | `#000000` | Dashed line |
| RW (Real World) | `#00FFFF` | Cyan, dotted |

### ARTCC/FIR Region Colors

Used in `assets/js/config/filter-colors.js`:

| Region | Hex |
|--------|-----|
| DCC West | `#dc3545` |
| DCC South Central | `#fd7e14` |
| DCC Midwest | `#28a745` |
| DCC Southeast | `#ffc107` |
| DCC Northeast | `#007bff` |
| Canada East | `#9b59b6` |
| Canada West | `#ff69b4` |

### Major Airline Carrier Colors

| Carrier | Hex |
|---------|-----|
| American | `#0078d2` |
| United | `#0033a0` |
| Delta | `#e01933` |
| Southwest | `#f9b612` |
| JetBlue | `#003876` |
| Alaska | `#00a8e0` |
| Spirit | `#ffc82e` |
| Frontier | `#006847` |
| Hawaiian | `#2d1f69` |

---

## Typography

### Font Stack

```css
/* Primary font */
font-family: 'Jost', sans-serif;

/* Fallback */
font-family: 'Inter', sans-serif;

/* Monospace (clocks, data values) */
font-family: "Inconsolata", "SF Mono", "SFMono-Regular", Menlo, Monaco,
             Consolas, "Liberation Mono", "Courier New", monospace;
```

### Heading Styles

| Element | Weight | Line Height | Margin Bottom |
|---------|--------|-------------|---------------|
| h1-h6 | 500 | 1 (tight) | 0.75rem |

### Text Hierarchy

| Purpose | Size | Weight | Color |
|---------|------|--------|-------|
| Body | 1rem | 400 | `#262633` |
| Muted text | 1rem | 400 | `#9e9fb4` |
| Info labels | 0.65rem | 700 | `#64748b` |
| Stat values | 0.9rem | 700 | Varies |
| Badge text | 0.75rem | bold | Varies |
| Clock display | 1.2rem | 700 | Varies |
| Tiny labels | 0.55rem | 500 | Muted |

### Label Conventions

```css
/* Info labels in cards */
.perti-info-label {
    font-size: 0.65rem;
    font-weight: 700;
    text-transform: uppercase;
    color: #64748b;
    letter-spacing: 0.05em;
}
```

---

## Component Patterns

### Buttons

#### Base Classes

| Class | Description |
|-------|-------------|
| `.btn` | Base button class |
| `.btn-{color}` | Solid color variants (primary, success, danger, etc.) |
| `.btn-outline-{color}` | Outline variants |
| `.btn-translucent-{color}` | Semi-transparent variants |
| `.btn-pill` | Rounded pill buttons |
| `.btn-icon` | Icon-only buttons with custom padding |
| `.btn-square` | No border radius |
| `.btn-gradient` | Gradient background (primary to info) |

#### Button Sizing

```css
/* Standard */
.btn { padding: 0.5rem 1rem; }

/* Small */
.btn-sm { padding: 0.25rem 0.5rem; font-size: 0.875rem; }

/* Large */
.btn-lg { padding: 0.75rem 1.5rem; font-size: 1.25rem; }
```

### Cards

```css
/* Standard card */
.card {
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05), 0 1px 2px rgba(0,0,0,0.03);
}

/* Hover effect */
.card:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
}
```

### Info Bar Cards

Custom card system for displaying data:

```css
/* Base info card */
.perti-info-card {
    background: white;
    border-radius: 10px;
    padding: 10px 14px;
    min-width: 100px;
    border-left: 3px solid;
}

/* Card variants */
.perti-card-utc { border-color: #3b82f6; }      /* Blue */
.perti-card-global { border-color: #06b6d4; }   /* Cyan */
.perti-card-config { border-color: #8b5cf6; }   /* Purple */
.perti-card-atis { border-color: #22c55e; }     /* Green */
.perti-card-arrivals { border-color: #22c55e; } /* Green */
.perti-card-departures { border-color: #f97316; } /* Orange */
.perti-card-refresh { border-color: #9ca3af; }  /* Gray */
```

### Forms

#### Input Fields

```css
/* Standard input */
.form-control {
    min-height: 44px;           /* Touch-friendly */
    padding: 0.5rem 0.75rem;
    border-radius: 4px;
    border: 1px solid #dee2e6;
}

/* Focus state */
.form-control:focus {
    border-color: #766df4;
    box-shadow: 0 0 0 0.2rem rgba(118, 109, 244, 0.25);
}
```

#### Custom Switch

```css
.custom-switch {
    /* Animated toggle switch */
}

.custom-switch .custom-control-input:checked ~ .custom-control-label::before {
    background-color: #766df4;
}
```

### Tables

#### Desktop Tables

```css
.table {
    width: 100%;
    border-collapse: collapse;
}

.table th {
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.75rem;
    color: #737491;
}

.table td {
    padding: 0.75rem;
    border-bottom: 1px solid #e9e9f2;
}
```

#### Mobile Tables (Card Layout)

On screens < 576px, tables convert to card-style layouts:

```css
@media (max-width: 575.98px) {
    .table-responsive-card tbody tr {
        display: block;
        margin-bottom: 1rem;
        border: 1px solid #dee2e6;
        border-radius: 8px;
        padding: 0.75rem;
    }

    .table-responsive-card td {
        display: flex;
        justify-content: space-between;
        padding: 0.5rem 0;
    }

    .table-responsive-card td::before {
        content: attr(data-label);
        font-weight: 600;
    }
}
```

### Modals

```css
.modal {
    /* Fade animation */
    animation: fadeIn 0.25s ease;
}

.modal-dialog {
    max-width: 500px;
    margin: 1.75rem auto;
}

/* Mobile: Full-screen modals */
@media (max-width: 575.98px) {
    .modal-dialog {
        margin: 0;
        max-width: 100%;
        height: 100%;
    }

    .modal-content {
        border-radius: 0;
        height: 100%;
    }
}
```

### Badges

```css
/* Standard badge */
.badge {
    font-size: 0.75rem;
    font-weight: bold;
    padding: 0.25em 0.5em;
    border-radius: 4px;
}

/* Weather impact badge */
.weather-impact-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    font-size: 0.6rem;
    font-weight: bold;
}
```

### Navigation

```css
/* Sticky navbar */
.navbar-sticky {
    position: sticky;
    top: 0;
    z-index: 1020;
    background: white;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

/* Navbar show animation */
@keyframes navbar-show {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
```

---

## Interactive UI Patterns

### Draggable Floating Panels

The demand page implements fully draggable floating panels with viewport constraints.

**Implementation** (from `assets/js/demand.js`):

```javascript
// Dragging state
let isDragging = false;
let dragOffset = { x: 0, y: 0 };

// Start drag on header mousedown
$panelHeader.on('mousedown', function(e) {
    if ($(e.target).closest('.panel-btn').length) return; // Skip buttons
    isDragging = true;

    const currentLeft = parseInt($floatingPanel.css('left')) || 0;
    const currentTop = parseInt($floatingPanel.css('top')) || 0;
    dragOffset.x = e.clientX - currentLeft;
    dragOffset.y = e.clientY - currentTop;

    $('body').css('user-select', 'none');
    $('#demand_chart').css('pointer-events', 'none'); // Allow drag over chart
});

// Track mouse movement
$(document).on('mousemove', function(e) {
    if (!isDragging) return;

    let newX = e.clientX - dragOffset.x;
    let newY = e.clientY - dragOffset.y;

    // Keep within viewport bounds
    const panelWidth = $floatingPanel.outerWidth();
    const panelHeight = $floatingPanel.outerHeight();
    newX = Math.max(0, Math.min(newX, window.innerWidth - panelWidth));
    newY = Math.max(0, Math.min(newY, window.innerHeight - panelHeight));

    $floatingPanel.css({ left: newX + 'px', top: newY + 'px' });
});

// End drag on mouseup or mouse leaving window
$(document).on('mouseup mouseleave', endDrag);

// Keep panel in bounds on window resize
$(window).on('resize', constrainPanelToBounds);
```

**Floating Panel CSS**:

```css
.floating-panel {
    position: fixed;
    background: white;
    border-radius: 8px;
    box-shadow: 0 6px 20px rgba(0,0,0,0.15);
    z-index: 1030;
    min-width: 200px;
    opacity: 0;
    visibility: hidden;
    transition: opacity 0.2s, visibility 0.2s;
}

.floating-panel.visible {
    opacity: 1;
    visibility: visible;
}

.floating-panel.collapsed .panel-body {
    display: none;
}

.panel-header {
    cursor: grab;
    padding: 8px 12px;
    background: #f8f9fa;
    border-bottom: 1px solid #dee2e6;
    border-radius: 8px 8px 0 0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.panel-header:active {
    cursor: grabbing;
}
```

### Collapsible Sections

```css
/* Accordion with +/- indicator */
.accordion-indicator {
    transition: transform 0.2s ease;
}

.accordion-indicator.collapsed {
    transform: rotate(45deg); /* + to - */
}

/* Collapse animation */
.collapse {
    transition: height 0.35s ease;
}

.collapsing {
    height: 0;
    overflow: hidden;
}
```

### Offcanvas Navigation

```css
/* Mobile offcanvas sidebar */
.cs-offcanvas {
    position: fixed;
    top: 0;
    left: 0;
    width: var(--offcanvas-width);
    height: 100%;
    background: white;
    z-index: 1035;
    transform: translateX(-100%);
    transition: transform 0.3s ease;
    display: flex;
    flex-direction: column;
}

.cs-offcanvas.show {
    transform: translateX(0);
}

/* Offcanvas backdrop */
.offcanvas-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
    opacity: 0;
    visibility: hidden;
    transition: opacity 0.3s, visibility 0.3s;
    z-index: 1034;
}

.offcanvas-backdrop.show {
    opacity: 1;
    visibility: visible;
}

/* Touch-optimized with dynamic viewport height */
.offcanvas-mobile {
    height: 100dvh; /* Dynamic viewport height for mobile browsers */
    -webkit-overflow-scrolling: touch;
}
```

### Slide-In Panels

```css
/* Right slide-in panel */
.slide-panel {
    position: fixed;
    right: 0;
    top: var(--navbar-height);
    width: 360px;
    height: calc(100vh - var(--navbar-height));
    background: white;
    box-shadow: -4px 0 20px rgba(0,0,0,0.15);
    transform: translateX(100%);
    transition: transform 0.3s cubic-bezier(.165, .84, .44, 1);
    z-index: 1025;
}

.slide-panel.active {
    transform: translateX(0);
}

/* Left variant */
.slide-panel-left {
    left: 0;
    right: auto;
    transform: translateX(-100%);
}

.slide-panel-left.active {
    transform: translateX(0);
}
```

### Tabs

```css
/* Standard tabs */
.nav-tabs {
    border-bottom: 1px solid #dee2e6;
}

.nav-tabs .nav-link {
    border: none;
    border-bottom: 2px solid transparent;
    color: #737491;
    transition: color 0.2s, border-color 0.2s;
}

.nav-tabs .nav-link:hover {
    color: #766df4;
    border-color: transparent;
}

.nav-tabs .nav-link.active {
    color: #766df4;
    border-bottom-color: #766df4;
    background: transparent;
}

/* Light variant */
.nav-tabs-light .nav-link.active {
    color: #37384e;
    border-color: #37384e;
}

/* Publisher tabs with underline */
.nav-tabs-publisher .nav-link.active {
    border-bottom: 3px solid #766df4;
}
```

---

## Layout Patterns

### Grid System

```css
/* Bootstrap grid with custom gutter */
$grid-gutter-width: 30px;

/* Container max-widths */
$container-max-widths: (
    sm: 540px,
    md: 720px,
    lg: 960px,
    xl: 1260px
);
```

### Flexbox Utilities

```css
/* Common flex patterns */
.d-flex { display: flex; }
.flex-column { flex-direction: column; }
.flex-wrap { flex-wrap: wrap; }
.justify-content-between { justify-content: space-between; }
.justify-content-center { justify-content: center; }
.align-items-center { align-items: center; }
.gap-2 { gap: 0.5rem; }
.gap-3 { gap: 1rem; }
```

### Stat Grid Layout

```css
/* Flexible stat display */
.perti-stat-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
}

.perti-stat-item {
    flex: 0 0 auto;
    min-width: 80px;
}

/* Clock grid */
.perti-clock-grid {
    display: flex;
    gap: 8px;
    align-items: baseline;
}
```

### Sidebar Layout

```css
/* Sidebar enabled layout */
.cs-sidebar-enabled {
    position: relative;
}

.cs-sidebar-enabled::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 40%;
    height: 100%;
    background-color: var(--sidebar-bg, #f7f7fc);
}

.cs-sidebar {
    position: relative;
    z-index: 5;
}

.cs-content {
    position: relative;
    z-index: 2;
}

@media (max-width: 991.98px) {
    .cs-sidebar-enabled::before {
        display: none;
    }
}
```

### Timeline Layout

```css
/* Initiative timeline */
.dcccp-timeline-wrapper {
    position: relative;
    overflow-x: auto;
}

.dcccp-timeline-row {
    height: 32px;
    display: flex;
    align-items: center;
}

.dcccp-timeline-label {
    width: 120px;
    flex-shrink: 0;
    font-size: 0.75rem;
    font-weight: 600;
}

.dcccp-timeline-content {
    flex: 1;
    position: relative;
}
```

---

## Responsive Design

### CSS Custom Properties

```css
:root {
    --touch-target-min: 44px;
    --mobile-spacing: 12px;
    --offcanvas-width: 280px;
    --navbar-height: 77px;
}
```

### Mobile-First Patterns

#### Phone (< 576px)

- Offcanvas navigation (280px width)
- Touch targets: minimum 44px
- Full-width buttons with `.btn-stack-mobile`
- Tables convert to card layout
- Full-screen modals
- Compact stat grids (2 columns)

#### Tablet (768px - 991px)

- Narrower panels (360px)
- Compact table sizing
- Horizontal scrolling navigation
- Chart height: 350px
- Modal max-width: 90%

#### Desktop (992px+)

- Full-width layouts
- Sidebar positioning
- Standard modal sizing
- Full navigation display

### Key Responsive Classes

```css
/* Mobile stacked buttons */
.btn-stack-mobile {
    width: 100%;
    display: block;
    margin-bottom: 0.5rem;
}

/* Touch-friendly scrolling */
.mobile-scroll {
    -webkit-overflow-scrolling: touch;
    overflow-x: auto;
}

/* Hide on mobile */
.d-mobile-none {
    display: none !important;
}

@media (min-width: 768px) {
    .d-mobile-none {
        display: block !important;
    }
}
```

---

## JavaScript Dynamic Styling

### Phase Colors API

```javascript
// assets/js/config/phase-colors.js

// Get color for a flight phase
getPhaseColor(phase)         // Returns hex color
getPhaseLabel(phase)         // Returns display label
getPhaseBadgeClass(phase)    // Returns Bootstrap badge class
```

### Rate Colors API

```javascript
// assets/js/config/rate-colors.js

// Build rate display mark lines for charts
buildRateMarkLines(rateData, direction)

// Get weather category styling
getWeatherColor(category)    // Returns hex color
getWeatherLabel(category)    // Returns display label

// Format rate display
formatRateDisplay(aar, adr)  // Returns formatted string
```

### Filter Colors API

```javascript
// assets/js/config/filter-colors.js

// Get color for filter category
getFilterColor(category, value)   // Returns hex color
getFilterLabel(category, value)   // Returns display label

// ARTCC region colors
getDCCRegionColor(artcc)          // Returns hex color
getDCCRegion(artcc)               // Returns region name
```

---

## Animations & Transitions

### Standard Transitions

```css
/* Standard hover transition */
.transition-standard {
    transition: all 0.2s ease;
}

/* Card hover */
.card {
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

/* Button hover */
.btn {
    transition: background-color 0.15s ease-in-out,
                border-color 0.15s ease-in-out,
                box-shadow 0.15s ease-in-out;
}
```

### Keyframe Animations

```css
/* Fade in opacity (for images) */
@keyframes fadeInOpacityImg {
    0% { opacity: 0; }
    50% { opacity: 0.5; }
    100% { opacity: 1; }
}

/* Flash animation */
@keyframes flashInOpacityImg {
    0%, 49% { opacity: 0; }
    50%, 100% { opacity: 1; }
}

/* Subtle pulse */
@keyframes pulse-subtle {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.7; }
}

/* Floating bob effect */
@keyframes hvr-bob {
    0% { transform: translateY(-8px); }
    50% { transform: translateY(-4px); }
    100% { transform: translateY(-8px); }
}

/* Cycle image visibility */
@keyframes cycle-image {
    0%, 49% { opacity: 0; }
    50%, 100% { opacity: 1; }
}
```

### Animation Classes

```css
/* Apply pulse to active elements */
.pulse-active {
    animation: pulse-subtle 2s infinite;
}

/* Floating effect */
.float-effect {
    animation: hvr-bob 1.5s ease-in-out infinite;
}

/* Flash indicator */
.flash-indicator {
    animation: flashInOpacityImg 2s infinite;
}
```

---

## Shadows & Depth

### Shadow Scale

```css
/* Light shadow (cards, subtle elevation) */
box-shadow: 0 1px 3px rgba(0,0,0,0.05), 0 1px 2px rgba(0,0,0,0.03);

/* Medium shadow (dropdowns, popovers) */
box-shadow: 0 4px 12px rgba(0,0,0,0.08);

/* Heavy shadow (modals, overlays) */
box-shadow: 0 6px 20px rgba(0,0,0,0.4);

/* Panel shadow (offcanvas, sidebars) */
box-shadow: 4px 0 20px rgba(0,0,0,0.3);

/* Navbar shadow */
box-shadow: 0 2px 8px rgba(0,0,0,0.1);
```

### Elevation Classes

```css
.elevation-1 { box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
.elevation-2 { box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
.elevation-3 { box-shadow: 0 6px 20px rgba(0,0,0,0.15); }
```

---

## Borders & Radius

### Border Radius

| Element | Radius |
|---------|--------|
| Cards | 8px |
| Info bar cards | 10px |
| Buttons (standard) | 4px |
| Buttons (pill) | 50% |
| Buttons (square) | 0 |
| Badges (standard) | 4px |
| Badges (rounded) | 12px |
| FAB (floating action) | 50% |
| Modals (desktop) | 8px |
| Modals (mobile) | 0 |

### Border Colors

```css
/* Primary border */
border-color: #766df4;

/* Info border */
border-color: #6a9bf4;

/* Muted border */
border-color: rgba(148, 163, 184, 0.2);

/* Divider */
border-color: #dee2e6;

/* Focus ring */
border-color: #766df4;
box-shadow: 0 0 0 0.2rem rgba(118, 109, 244, 0.25);
```

---

## Z-Index Layering

### Z-Index Hierarchy

| Layer | Z-Index | Usage |
|-------|---------|-------|
| Background elements | -1 | Decorative backgrounds, pseudo-elements |
| Default content | 1-5 | Cards, images, standard content |
| Carousel controls | 10 | Slider navigation |
| Scroll-to-top button | 1025 | Fixed position utilities |
| Toolbar | 1026 | Floating toolbars |
| Navbar (sticky) | 1020 | Sticky navigation |
| Slide panels | 1025 | Side panels |
| Floating panels | 1030 | Draggable overlays |
| Offcanvas backdrop | 1034 | Mobile nav backdrop |
| Offcanvas | 1035 | Mobile navigation |
| Toasts | 1040 | Notification toasts |
| Modals backdrop | 1040 | Modal overlay |
| Modals | 1050 | Modal dialogs |
| Tooltips | 1070 | Hover tooltips |
| Popovers | 1060 | Click popovers |

### SCSS Variables

```scss
$zindex-btn-scroll-top: 1025;
$zindex-toolbar: 1026;
$zindex-offcanvas: 1035;
$zindex-toast: 1040;
```

### Stacking Context Management

```css
/* Create new stacking context */
.stacking-context {
    position: relative;
    z-index: 1;
}

/* Overlay layers */
.overlay-low { z-index: 5; }
.overlay-mid { z-index: 10; }
.overlay-high { z-index: 25; }
```

---

## Icon System

### Feather Icons

The project uses Feather Icons with 200+ icons via a custom font.

**Font Declaration** (from `_icons.scss`):

```css
@font-face {
    font-family: 'Feather';
    src: url('../fonts/feather.woff2') format('woff2'),
         url('../fonts/feather.woff') format('woff');
    font-weight: normal;
    font-style: normal;
}

[class^="fe-"], [class*=" fe-"] {
    font-family: 'Feather' !important;
    speak: never;
    font-style: normal;
    font-weight: normal;
    font-variant: normal;
    text-transform: none;
    line-height: 1;
    vertical-align: middle;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}
```

### Common Icon Classes

| Icon | Class | Unicode |
|------|-------|---------|
| Chevron Down | `.fe-chevron-down` | `\e92f` |
| Chevron Up | `.fe-chevron-up` | `\e932` |
| Chevron Left | `.fe-chevron-left` | `\e930` |
| Chevron Right | `.fe-chevron-right` | `\e931` |
| Check | `.fe-check` | `\e92b` |
| X (Close) | `.fe-x` | `\e9b8` |
| Plus | `.fe-plus` | `\e985` |
| Minus | `.fe-minus` | `\e96f` |
| Search | `.fe-search` | `\e993` |
| Settings | `.fe-settings` | `\e995` |
| User | `.fe-user` | `\e9a5` |
| Menu | `.fe-menu` | `\e96c` |
| Loader | `.fe-loader` | `\e992` |
| Alert Circle | `.fe-alert-circle` | `\e900` |
| Info | `.fe-info` | `\e959` |
| Eye | `.fe-eye` | `\e941` |
| Eye Off | `.fe-eye-off` | `\e942` |
| Edit | `.fe-edit` | `\e938` |
| Trash | `.fe-trash-2` | `\e9a0` |
| Download | `.fe-download` | `\e935` |
| Upload | `.fe-upload` | `\e9a6` |
| Refresh | `.fe-refresh-cw` | `\e98c` |

### Icon Sizing

```css
/* Small icon */
.fe-sm { font-size: 0.875rem; }

/* Default icon */
.fe { font-size: 1rem; }

/* Large icon */
.fe-lg { font-size: 1.25rem; }

/* Extra large */
.fe-xl { font-size: 1.5rem; }

/* In buttons */
.btn .fe {
    margin-right: 0.25rem;
    vertical-align: -0.125em;
}
```

---

## Toast & Notification System

### SweetAlert2 Integration

```javascript
// Success toast
Swal.fire({
    toast: true,
    position: 'bottom-end',
    icon: 'success',
    title: 'Saved successfully',
    showConfirmButton: false,
    timer: 3000,
    timerProgressBar: true
});

// Error notification
Swal.fire({
    icon: 'error',
    title: 'Error',
    text: 'Something went wrong',
    confirmButtonColor: '#766df4'
});

// Confirmation dialog
Swal.fire({
    title: 'Are you sure?',
    text: "This action cannot be undone",
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#f74f78',
    cancelButtonColor: '#737491',
    confirmButtonText: 'Yes, delete it'
});
```

### Bootstrap Toasts

```css
.toast {
    background: white;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    border: none;
}

.toast-header {
    background: transparent;
    border-bottom: 1px solid #e9e9f2;
    padding: 0.75rem 1rem;
}

.toast-header .fe {
    font-size: 1.25rem;
    margin-right: 0.5rem;
}

.toast-body {
    padding: 1rem;
}

/* Toast variants */
.toast-success .toast-header { color: #16c995; }
.toast-error .toast-header { color: #f74f78; }
.toast-warning .toast-header { color: #ffb15c; }
.toast-info .toast-header { color: #6a9bf4; }
```

---

## Loading States

### Spinner

```css
/* CSS spinner */
.spinner {
    width: 24px;
    height: 24px;
    border: 2px solid #e9e9f2;
    border-top-color: #766df4;
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* Spinner sizes */
.spinner-sm { width: 16px; height: 16px; }
.spinner-lg { width: 32px; height: 32px; }
```

### Loading Overlay

```css
.loading-overlay {
    position: absolute;
    inset: 0;
    background: rgba(255, 255, 255, 0.8);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10;
    opacity: 0;
    visibility: hidden;
    transition: opacity 0.2s, visibility 0.2s;
}

.loading-overlay.active {
    opacity: 1;
    visibility: visible;
}
```

### Button Loading State

```css
.btn.loading {
    position: relative;
    color: transparent !important;
    pointer-events: none;
}

.btn.loading::after {
    content: '';
    position: absolute;
    width: 16px;
    height: 16px;
    top: 50%;
    left: 50%;
    margin: -8px 0 0 -8px;
    border: 2px solid currentColor;
    border-right-color: transparent;
    border-radius: 50%;
    animation: spin 0.6s linear infinite;
}
```

### Chart Loading

```javascript
// Show loading state on chart
function showChartLoading(chartId) {
    const $chart = $('#' + chartId);
    $chart.css('opacity', '0.5');
    $chart.append('<div class="chart-loading"><div class="spinner"></div></div>');
}

function hideChartLoading(chartId) {
    const $chart = $('#' + chartId);
    $chart.css('opacity', '1');
    $chart.find('.chart-loading').remove();
}
```

---

## Vendor & Plugin CSS

### FlatPickr (Date Picker)

```css
/* Calendar container */
.flatpickr-calendar {
    width: 325px;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    border: none;
}

/* Day cells */
.flatpickr-day {
    border-radius: 4px;
    transition: background 0.15s;
}

.flatpickr-day:hover {
    background: #f7f7fc;
}

.flatpickr-day.selected {
    background: #766df4;
    border-color: #766df4;
}

/* Date range selection */
.flatpickr-day.inRange {
    background: rgba(118, 109, 244, 0.1);
    box-shadow: -5px 0 0 rgba(118, 109, 244, 0.1),
                5px 0 0 rgba(118, 109, 244, 0.1);
}

.flatpickr-day.startRange {
    border-radius: 4px 0 0 4px;
}

.flatpickr-day.endRange {
    border-radius: 0 4px 4px 0;
}
```

### Select2

```css
/* Multi-select container */
.select2-container--default .select2-selection--multiple {
    border: 1px solid #dee2e6;
    border-radius: 4px;
    min-height: 44px;
    padding: 4px 8px;
}

.select2-container--default .select2-selection--multiple:focus,
.select2-container--default.select2-container--focus .select2-selection--multiple {
    border-color: #766df4;
    box-shadow: 0 0 0 0.2rem rgba(118, 109, 244, 0.25);
}

/* Selected tags */
.select2-selection__choice {
    background: #766df4 !important;
    border: none !important;
    border-radius: 4px !important;
    color: white !important;
    padding: 4px 8px !important;
}

.select2-selection__choice__remove {
    color: white !important;
    margin-right: 4px;
}

/* Dropdown */
.select2-dropdown {
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    border: none;
}

.select2-results__option--highlighted[aria-selected] {
    background: #766df4;
}
```

### SimpleBar (Custom Scrollbar)

```css
/* Scrollbar track */
.simplebar-track {
    background: transparent;
}

.simplebar-track.simplebar-vertical {
    width: 8px;
}

.simplebar-track.simplebar-horizontal {
    height: 8px;
}

/* Scrollbar thumb */
.simplebar-scrollbar::before {
    background: #9e9fb4;
    border-radius: 4px;
    opacity: 0;
    transition: opacity 0.2s;
}

.simplebar-scrollbar.simplebar-visible::before {
    opacity: 0.5;
}

.simplebar-scrollbar:hover::before {
    opacity: 0.7;
}

/* Inverse (light scrollbar for dark backgrounds) */
[data-simplebar-inverse] .simplebar-scrollbar::before {
    background: rgba(255, 255, 255, 0.5);
}
```

### noUiSlider (Range Slider)

```css
.cs-range-slider-ui {
    height: 4px;
    background: #e9e9f2;
    border: none;
    border-radius: 2px;
}

.cs-range-slider-ui .noUi-connect {
    background: #766df4;
}

.cs-range-slider-ui .noUi-handle {
    width: 16px;
    height: 16px;
    border-radius: 50%;
    background: white;
    border: 2px solid #766df4;
    box-shadow: 0 2px 4px rgba(0,0,0,0.15);
    cursor: grab;
}

.cs-range-slider-ui .noUi-handle:active {
    cursor: grabbing;
}

.cs-range-slider-ui .noUi-tooltip {
    background: #37384e;
    color: white;
    border: none;
    border-radius: 4px;
    font-size: 0.75rem;
    padding: 4px 8px;
}
```

---

## Map UI Patterns

### Leaflet/MapLibre Integration

```css
/* Map container */
.map-container {
    width: 100%;
    height: 100%;
    min-height: 400px;
    border-radius: 8px;
    overflow: hidden;
}

/* Full-screen map */
.map-fullscreen {
    position: absolute;
    inset: 0;
    min-height: auto;
    border-radius: 0;
}
```

### Map Controls

```css
/* Layer toggle buttons */
.map-layer-control {
    position: absolute;
    top: 10px;
    right: 10px;
    z-index: 1000;
    background: white;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
    padding: 8px;
}

.map-layer-btn {
    display: block;
    width: 32px;
    height: 32px;
    border: none;
    background: transparent;
    border-radius: 4px;
    cursor: pointer;
    transition: background 0.15s;
}

.map-layer-btn:hover {
    background: #f7f7fc;
}

.map-layer-btn.active {
    background: #766df4;
    color: white;
}
```

### Map Popups & Tooltips

```css
/* Custom popup */
.leaflet-popup-content-wrapper {
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.leaflet-popup-content {
    margin: 12px;
    font-size: 0.875rem;
}

/* Datablock with leader line */
.map-datablock {
    position: absolute;
    background: rgba(0, 0, 0, 0.8);
    color: white;
    padding: 4px 8px;
    border-radius: 4px;
    font-family: monospace;
    font-size: 0.75rem;
    white-space: nowrap;
    pointer-events: none;
}

.map-datablock::before {
    content: '';
    position: absolute;
    width: 1px;
    height: 20px;
    background: rgba(255, 255, 255, 0.5);
    bottom: 100%;
    left: 50%;
}
```

### Layer Visibility States

```javascript
// Layer visibility management
const layerState = {
    artcc: true,
    high: true,
    low: false,
    superhigh: false,
    tracon: true,
    areas: false,
    presets: true
};

function toggleLayer(layerName) {
    layerState[layerName] = !layerState[layerName];
    updateLayerVisibility();
}
```

---

## Chart & Graph Styling

### ECharts Configuration

```javascript
// Common chart options
const chartTheme = {
    color: ['#766df4', '#6a9bf4', '#16c995', '#ffb15c', '#f74f78'],
    backgroundColor: 'transparent',
    textStyle: {
        fontFamily: 'Jost, Inter, sans-serif',
        color: '#737491'
    },
    title: {
        textStyle: {
            color: '#37384e',
            fontWeight: 500
        }
    },
    legend: {
        textStyle: {
            color: '#737491'
        }
    },
    tooltip: {
        backgroundColor: 'rgba(55, 56, 78, 0.95)',
        borderColor: 'transparent',
        textStyle: {
            color: '#ffffff'
        }
    },
    grid: {
        borderColor: '#e9e9f2'
    },
    categoryAxis: {
        axisLine: { lineStyle: { color: '#e9e9f2' } },
        axisTick: { lineStyle: { color: '#e9e9f2' } },
        axisLabel: { color: '#737491' }
    },
    valueAxis: {
        axisLine: { lineStyle: { color: '#e9e9f2' } },
        splitLine: { lineStyle: { color: '#f3f3f9' } },
        axisLabel: { color: '#737491' }
    }
};
```

### Chart Container

```css
.chart-card {
    background: white;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}

.chart-card-header {
    padding: 12px 16px;
    border-bottom: 1px solid #e9e9f2;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.chart-card-title {
    font-size: 0.875rem;
    font-weight: 600;
    color: #37384e;
    margin: 0;
}

.chart-container {
    padding: 16px;
    min-height: 300px;
}
```

### Rate Mark Lines

```javascript
// Rate line styling for demand charts
function buildRateMarkLines(rateData, direction) {
    const lines = [];

    if (rateData.aar) {
        lines.push({
            name: 'AAR',
            yAxis: rateData.aar,
            lineStyle: { color: '#000000', type: 'solid', width: 2 },
            label: { show: true, formatter: 'AAR: {c}' }
        });
    }

    if (rateData.adr) {
        lines.push({
            name: 'ADR',
            yAxis: rateData.adr,
            lineStyle: { color: '#000000', type: 'dashed', width: 2 },
            label: { show: true, formatter: 'ADR: {c}' }
        });
    }

    if (rateData.realWorld) {
        lines.push({
            name: 'RW',
            yAxis: rateData.realWorld,
            lineStyle: { color: '#00FFFF', type: 'dotted', width: 2 },
            label: { show: true, formatter: 'RW: {c}' }
        });
    }

    return lines;
}
```

### Chartist.js (Legacy)

```css
/* Line chart */
.ct-line {
    stroke-width: 2px;
    fill: none;
}

.ct-point {
    stroke-width: 8px;
}

/* Bar chart */
.ct-bar {
    stroke-width: 20px;
}

/* Pie chart labels */
.ct-chart-pie .ct-label {
    fill: white;
    font-size: 0.75rem;
    font-weight: bold;
}

/* Grid */
.ct-grid {
    stroke: #e9e9f2;
    stroke-dasharray: 2px;
}

/* Series colors */
.ct-series-a .ct-line,
.ct-series-a .ct-point,
.ct-series-a .ct-bar { stroke: #766df4; }

.ct-series-b .ct-line,
.ct-series-b .ct-point,
.ct-series-b .ct-bar { stroke: #6a9bf4; }

.ct-series-c .ct-line,
.ct-series-c .ct-point,
.ct-series-c .ct-bar { stroke: #16c995; }
```

---

## Accessibility Patterns

### Screen Reader Support

```css
/* Screen reader only content */
.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}

/* Focusable sr-only (skip links) */
.sr-only-focusable:active,
.sr-only-focusable:focus {
    position: static;
    width: auto;
    height: auto;
    overflow: visible;
    clip: auto;
    white-space: normal;
}
```

### Focus States

```css
/* Remove focus outline for mouse users */
[tabindex="-1"]:focus:not(:focus-visible) {
    outline: 0 !important;
}

/* Visible focus for keyboard navigation */
:focus-visible {
    outline: 2px solid #766df4;
    outline-offset: 2px;
}

/* Button focus */
.btn:focus {
    box-shadow: 0 0 0 0.2rem rgba(118, 109, 244, 0.25);
}

/* Link focus */
a:focus-visible {
    outline: 2px solid #766df4;
    outline-offset: 2px;
    border-radius: 2px;
}
```

### ARIA Attributes

```css
/* Expanded state indicator */
[aria-expanded="true"] .accordion-indicator {
    transform: rotate(180deg);
}

/* Selected state in lists */
[aria-selected="true"] {
    background: #766df4;
    color: white;
}

/* Disabled state */
[aria-disabled="true"] {
    opacity: 0.5;
    pointer-events: none;
}

/* Current page in navigation */
[aria-current="page"] {
    font-weight: 600;
    color: #766df4;
}
```

### Touch Targets

```css
/* Minimum touch target size (44x44px per WCAG) */
:root {
    --touch-target-min: 44px;
}

.touch-target {
    min-width: var(--touch-target-min);
    min-height: var(--touch-target-min);
}

/* Ensure clickable elements meet minimum size on mobile */
@media (max-width: 767.98px) {
    .btn,
    .nav-link,
    .dropdown-item,
    input[type="checkbox"] + label,
    input[type="radio"] + label {
        min-height: var(--touch-target-min);
        display: inline-flex;
        align-items: center;
    }
}
```

### Reduced Motion

```css
/* Respect user's motion preferences */
@media (prefers-reduced-motion: reduce) {
    *,
    *::before,
    *::after {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
    }
}
```

---

## Print Styles

### Print Utilities

```css
/* Hide on print */
.d-print-none {
    @media print { display: none !important; }
}

/* Show only on print */
.d-print-block {
    display: none !important;
    @media print { display: block !important; }
}

.d-print-inline {
    display: none !important;
    @media print { display: inline !important; }
}

.d-print-flex {
    display: none !important;
    @media print { display: flex !important; }
}
```

### Print-Optimized Styles

```css
@media print {
    /* Reset backgrounds */
    body {
        background: white !important;
        color: black !important;
    }

    /* Hide navigation and interactive elements */
    .navbar,
    .sidebar,
    .offcanvas,
    .modal,
    .btn,
    .floating-panel {
        display: none !important;
    }

    /* Ensure content prints */
    .container,
    .card {
        box-shadow: none !important;
        border: 1px solid #ddd;
    }

    /* Page breaks */
    .page-break-before { page-break-before: always; }
    .page-break-after { page-break-after: always; }
    .avoid-break { page-break-inside: avoid; }

    /* Links */
    a[href]::after {
        content: " (" attr(href) ")";
        font-size: 0.8em;
        color: #666;
    }
}
```

---

## Page-Specific Patterns

### Demand Page

```css
/* Info bar with horizontal scroll */
.perti-info-bar {
    display: flex;
    gap: 12px;
    padding: 10px 16px;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

/* TBFM/FSM chart container */
.chart-container {
    background: white;
    border-radius: 8px;
    padding: 16px;
}

/* Rate display */
.perti-rate-display {
    font-family: monospace;
    font-size: 0.9rem;
    display: flex;
    gap: 8px;
}
```

### Route Page

```css
/* Route symbology */
.route-active { color: #22c55e; }
.route-proposed { color: #3b82f6; }
.route-affected { color: #f97316; }

/* Map container */
.map-container {
    height: calc(100vh - var(--navbar-height));
    width: 100%;
}
```

### Simulator Page

```css
/* Full-screen map */
.sim-map-container {
    position: absolute;
    inset: 0;
    z-index: 1;
}

/* Slide-in panel */
.sim-panel {
    position: fixed;
    right: 0;
    top: var(--navbar-height);
    width: 360px;
    height: calc(100vh - var(--navbar-height));
    background: white;
    box-shadow: -4px 0 20px rgba(0,0,0,0.15);
    transform: translateX(100%);
    transition: transform 0.3s ease;
}

.sim-panel.active {
    transform: translateX(0);
}

/* Floating action button */
.sim-fab {
    position: fixed;
    bottom: 24px;
    right: 24px;
    width: 56px;
    height: 56px;
    border-radius: 50%;
    background: #766df4;
    color: white;
    box-shadow: 0 4px 12px rgba(118, 109, 244, 0.4);
}
```

### GDT Page

```css
/* Flight table card */
.gdt-table-card {
    max-height: 400px;
    overflow-y: auto;
}

/* Map container */
.gdt-map {
    height: 500px;
    border-radius: 8px;
}

/* Control panel */
.gdt-controls {
    display: flex;
    gap: 12px;
    padding: 12px;
    background: #f7f7fc;
    border-radius: 8px;
}
```

---

## SCSS Component Reference

### Complete Component List

All SCSS components are located in `assets/css/components/`:

| Component | File | Description |
|-----------|------|-------------|
| Alerts | `_alert.scss` | Alert boxes and dismissible messages |
| Animations | `_animations.scss` | Keyframe animations and utility classes |
| Badges | `_badge.scss` | Status badges and labels |
| Breadcrumbs | `_breadcrumb.scss` | Navigation breadcrumbs |
| Button Groups | `_button-group.scss` | Grouped button layouts |
| Buttons | `_buttons.scss` | All button variants and states |
| Cards | `_card.scss` | Card containers and variants |
| Carousel | `_carousel.scss` | Image/content sliders |
| Charts | `_charts.scss` | Chartist.js chart styling |
| Close Button | `_close.scss` | Dismiss/close button |
| Code | `_code.scss` | Code blocks and inline code |
| Comments | `_comments.scss` | Comment thread styling |
| Countdown | `_countdown.scss` | Timer/countdown display |
| Custom Scrollbar | `_custom-scrollbar.scss` | SimpleBar integration |
| Date Picker | `_date-picker.scss` | FlatPickr styling |
| Dropdowns | `_dropdown.scss` | Dropdown menus |
| Forms | `_forms.scss` | Form controls and inputs |
| Frames | `_frames.scss` | Device frame mockups |
| Gallery | `_gallery.scss` | Image gallery layouts |
| Hotspots | `_hotspots.scss` | Interactive hotspot markers |
| Icons | `_icons.scss` | Feather icon font |
| Input Groups | `_input-group.scss` | Input with addons |
| List Groups | `_list-group.scss` | List items and actions |
| Masonry Grid | `_masonry-grid.scss` | Pinterest-style layouts |
| Modals | `_modal.scss` | Modal dialogs |
| Navigation | `_nav.scss` | Nav items and tabs |
| Navbar | `_navbar.scss` | Main navigation bar |
| Offcanvas | `_offcanvas.scss` | Slide-out panels |
| Pagination | `_pagination.scss` | Page navigation |
| Parallax | `_parallax.scss` | Scroll parallax effects |
| Popovers | `_popover.scss` | Click-triggered popups |
| Pricing | `_pricing.scss` | Pricing table cards |
| Shop | `_shop.scss` | E-commerce components |
| Sidebar | `_sidebar.scss` | Sidebar layouts |
| Social Buttons | `_social-buttons.scss` | Social media buttons |
| Tables | `_tables.scss` | Table styling |
| Toasts | `_toasts.scss` | Toast notifications |
| Typography | `_type.scss` | Text utilities |
| Video Popup | `_video-popup.scss` | Video modal/lightbox |
| Widgets | `_widgets.scss` | Dashboard widgets |

### Helper Files

| File | Description |
|------|-------------|
| `helpers/_variables.scss` | SCSS variables (colors, spacing, breakpoints) |
| `helpers/_mixins.scss` | Reusable SCSS mixins |
| `_reboot.scss` | CSS reset and normalization |
| `_utilities.scss` | Utility classes |

---

## File Reference

### Core Styling Files

| Path | Description |
|------|-------------|
| `assets/css/theme.css` | Bootstrap 4.5.2 base with customizations |
| `assets/css/perti_theme.css` | PERTI brand overrides |
| `assets/css/mobile.css` | Responsive design patterns |
| `assets/css/info-bar.css` | Info bar component |
| `assets/css/weather_card.css` | Weather display components |
| `assets/css/weather_impact_panel.css` | Weather impact panels |

### SCSS Helpers

| Path | Description |
|------|-------------|
| `assets/css/helpers/_variables.scss` | Color and spacing variables |
| `assets/css/helpers/_mixins.scss` | Reusable SCSS mixins |

### SCSS Components

| Path | Description |
|------|-------------|
| `assets/css/components/_buttons.scss` | Button styles |
| `assets/css/components/_forms.scss` | Form controls |
| `assets/css/components/_card.scss` | Card styles |
| `assets/css/components/_modal.scss` | Modal dialogs |
| `assets/css/components/_navbar.scss` | Navigation |
| `assets/css/components/_tables.scss` | Table styles |

### JavaScript Config

| Path | Description |
|------|-------------|
| `assets/js/config/phase-colors.js` | Flight phase colors |
| `assets/js/config/rate-colors.js` | Rate and weather colors |
| `assets/js/config/filter-colors.js` | Carrier, aircraft, ARTCC colors |

### Vendor Libraries

| Path | Description |
|------|-------------|
| `assets/vendor/` | Third-party CSS/JS |
| `assets/css/plugins/` | Plugin-specific styles |

---

## Quick Reference

### Adding New Colors

1. Add SCSS variable in `assets/css/helpers/_variables.scss`
2. Create utility classes in component files
3. For JS dynamic colors, add to appropriate config file in `assets/js/config/`

### Creating New Components

1. Create SCSS file in `assets/css/components/`
2. Import in main stylesheet
3. Follow BEM-like naming: `.component`, `.component-element`, `.component--modifier`

### Responsive Breakpoints

```scss
// Mobile first
@media (min-width: 576px) { /* sm and up */ }
@media (min-width: 768px) { /* md and up */ }
@media (min-width: 992px) { /* lg and up */ }
@media (min-width: 1280px) { /* xl and up */ }

// Target specific breakpoints
@media (max-width: 575.98px) { /* xs only */ }
@media (max-width: 767.98px) { /* sm and down */ }
```

---

*Last updated: February 2026*
*Generated by Claude Code from codebase analysis*
