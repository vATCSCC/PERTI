# Page Format Consistency Audit

**Date**: 2026-02-25
**Scope**: All 27 top-level PHP pages across login states, organizations, and locales
**Method**: Code-level analysis of every page's HTML structure, CSS patterns, includes, and conditional rendering

---

## Executive Summary

The PERTI site has **6 distinct page layout families** that evolved organically. Key inconsistencies include:

- **3 different hero section patterns** (full-height, compact, and micro/none)
- **3 different body backgrounds** (#1a1a2e dark, #0f172a slate, default white)
- **2 navigation architectures** (nav.php with DB, nav_public.php without)
- **Inconsistent page titles** (some i18n, some hardcoded; some with "- PERTI" suffix, most without)
- **3 pages include footer.php twice** (plan.php, sheet.php, review.php)
- **Mixed `<html>` tags** (some have `lang="en"`, most don't)
- **Mixed container widths** (`container` narrow vs `container-fluid` full-width)
- **13 pages missing i18n.php** (all content hardcoded English)

---

## Page Inventory

### Navigation Architecture

| Nav Type | Pages (14) |
|----------|-----------|
| **`nav.php`** (full, DB-connected) | index, plan, sheet, review, airport_config, schedule, event-aar, sua, tmi-publish, swim-keys, status, route, playbook, data |
| **`nav_public.php`** (lightweight, no DB) | demand, nod, gdt, swim, swim-doc, swim-docs, fmds-comparison, simulator, transparency, privacy, jatoc, splits |

**Difference when logged in**: `nav.php` shows Admin dropdown (users, configs, TMI staging), username display, org switcher with colors, EN/FR toggle for CANOC. `nav_public.php` now (post-PR#93) mirrors the same org switcher + locale toggle, but still lacks the Admin dropdown.

**Difference when logged out**: `nav.php` shows Login button (red). `nav_public.php` shows Login button (red). Both hide the username and Admin dropdown. Org switcher still visible if session has org data.

### Authentication Requirements

| Access Level | Pages |
|-------------|-------|
| **Login required** (403 if not logged in) | plan, sheet, review, schedule, sua, tmi-publish, swim-keys, airport_config, data |
| **Public** (accessible without login) | index, demand, nod, gdt, swim, swim-doc, swim-docs, fmds-comparison, simulator, transparency, privacy, jatoc, splits, route, playbook, event-aar, status, nod |

---

## Layout Pattern Analysis

### 1. Hero Section Patterns

Six distinct hero patterns exist:

#### Pattern A: Full-Height Hero (`fh-section`)
```html
<section class="d-flex align-items-center position-relative bg-position-center fh-section overflow-hidden pt-6 jarallax bg-dark text-light" data-jarallax data-speed="0.3">
```
- **Used by**: index, airport_config, transparency, privacy
- **Characteristics**: Full viewport height, jarallax parallax, dark overlay
- **tmi-publish** uses `pt-4` instead of `pt-6` (variant)

#### Pattern B: Compact Hero (`min-vh-25`)
```html
<section class="d-flex align-items-center position-relative min-vh-25 py-4" data-jarallax data-speed="0.3">
```
- **Used by**: gdt, splits, swim, swim-keys, swim-docs, demand
- **Characteristics**: 25vh minimum height, compact padding, jarallax parallax

#### Pattern C: Fixed-Height Hero (`min-height` inline)
```html
<section class="... overflow-hidden pt-6 jarallax bg-dark text-light" style="min-height: 250px">
```
- **Used by**: plan (250px), sheet (250px), data (250px), route (250px), schedule (250px), review (200px), sua (200px), event-aar (120px)
- **Characteristics**: Arbitrary inline min-heights, no `fh-section` or `min-vh-25`
- **event-aar** is unique: 120px, no jarallax, no parallax (`bg-dark text-light` only)

#### Pattern D: Custom Micro Hero
```html
<section class="d-flex align-items-center position-relative" style="background: #0f172a; min-height: 80px; margin-top: 60px;">
```
- **Used by**: jatoc only
- **Characteristics**: Minimal 80px hero, explicit `#0f172a` background, manual `margin-top: 60px` for nav offset

#### Pattern E: No Hero (Map/Tool fills page)
- **Used by**: nod, simulator, playbook, status
- **Characteristics**: No hero section at all. Content begins immediately after nav.

#### Pattern F: Dark Page with Hero-like header (swim-doc)
- **Used by**: swim-doc
- **Characteristics**: No standard hero section, uses dark background `#1a1a2e` for entire page

### 2. Body Background Themes

| Theme | Color | Pages |
|-------|-------|-------|
| **Dark Navy** | `#1a1a2e` | swim, swim-keys, swim-docs, swim-doc, fmds-comparison, nod, splits, route, demand, status, simulator |
| **Dark Slate** | `#0f172a` | jatoc only |
| **Light (Bootstrap default)** | white | index, plan, sheet, review, schedule, airport_config, event-aar, transparency, privacy, sua, tmi-publish, gdt, playbook, data |

**How dark theme is applied**: Varies per page:
- Some use `body { background-color: #1a1a2e; }` in inline `<style>` tags
- `jatoc.php` uses `body { background-color: #0f172a !important; }`
- `simulator.php` uses `body { overflow: hidden; margin: 0; padding: 0; }` (relies on map fill)
- No shared dark-theme CSS class exists

### 3. Container Patterns

| Pattern | Pages |
|---------|-------|
| **`container-fluid`** (full-width content) | index, plan, sheet, data, route, schedule, airport_config, event-aar, sua, tmi-publish, gdt, swim-keys, splits, demand, playbook, jatoc, review, status |
| **`container`** (max-width constrained) | transparency, privacy, swim, swim-docs, swim-doc, fmds-comparison |
| **No container** (full-bleed) | nod, simulator (map fills viewport) |

**Content area padding variants** (inside hero sections):
- `pt-2 pb-5 py-lg-6`: index, airport_config, transparency, privacy, sheet, data, plan, route, schedule
- `pt-2 pb-4 py-lg-5`: gdt, swim, swim-keys, swim-docs, sua, review, splits, demand
- `pt-1 pb-2 py-lg-3`: tmi-publish
- `pt-2 pb-3`: event-aar
- `pt-2 pb-2`: jatoc

### 4. Page Title Patterns

| Pattern | Pages | Example |
|---------|-------|---------|
| **i18n `__()`** | index, demand, gdt, splits | `__('demand.page.title')` |
| **Hardcoded, no suffix** | route, nod, jatoc, sua, schedule, playbook, event-aar, simulator, tmi-publish, airport_config, privacy | `"Route Plotter"` |
| **Hardcoded with "PERTI" in title** | sheet, data, plan, review, status | `"PERTI Planning Sheet"` |
| **Hardcoded with "- PERTI" suffix** | swim, swim-keys, swim-docs, swim-doc, transparency, fmds-comparison | `"SWIM API - PERTI"` |
| **Default fallback** | (if `$page_title` unset) | `"PERTI Planning"` |

### 5. `<html>` Tag Consistency

| Pattern | Pages |
|---------|-------|
| **`<html lang="en">`** | demand, gdt, nod, jatoc, tmi-publish, status, simulator, splits |
| **`<html>`** (no lang) | index, plan, sheet, review, route, schedule, airport_config, event-aar, sua, swim, swim-keys, swim-docs, swim-doc, fmds-comparison, transparency, privacy, playbook, data |

### 6. i18n Include

| Has `load/i18n.php` | Missing `load/i18n.php` |
|---------------------|------------------------|
| index*, plan*, sheet*, review*, schedule*, demand, gdt, nod, splits, jatoc, route, swim, swim-doc, swim-docs, fmds-comparison, simulator, transparency, privacy | tmi-publish, swim-keys, status, event-aar, sua, airport_config, playbook, data |

*Pages marked with `*` get i18n via `load/connect.php` → `load/org_context.php` chain rather than direct include.

**Note**: Pages without `i18n.php` cannot use `__()` in PHP — all their text is hardcoded English.

### 7. Footer Issues

| Issue | Pages |
|-------|-------|
| **Footer included TWICE** | plan.php (lines 171, 646), sheet.php (lines 94, 281), review.php (lines 470, 1130) |
| **Footer included once** | All other pages |
| **No footer** | None (all pages include footer) |

**Root cause**: These 3 pages use an **org mismatch early-exit pattern**:
```php
if ($org_mismatch):
    // Display "wrong org" alert + switch button
    include('load/footer.php');  // ← first include (for the early-exit HTML)
    exit;                        // ← exits if mismatch
endif;
// ... normal page content ...
include('load/footer.php');      // ← second include (normal page end)
```
Both the early-exit branch and the normal branch include `footer.php`. If the org matches (normal case), only the second runs. But the HTML structure means the file is *referenced* twice in the source, and if both branches somehow execute (or the `exit` is bypassed), you get duplicate `<footer>`, duplicate JS plugin loads (Bootstrap-select, Summernote, Jarallax, theme.min.js), and duplicate page-loader dismiss scripts.

---

## Organization-Specific Differences

### Nav Bar Org Badge

| Org | Badge Color | Display Name | Locale Toggle |
|-----|------------|--------------|---------------|
| **DCC (vatcscc)** | `#1a73e8` (blue) | "DCC" | None |
| **CANOC** | `#d32f2f` (red) | "CANOC" | EN/FR buttons shown |
| **ECFMP** | `#7b1fa2` (purple) | "ECFMP" | None |
| **Global** | `#f9a825` (amber) | "GLOBAL" | None |

For single-org users: static badge. For multi-org users: dropdown to switch.

### Footer Copyright

Uses i18n key `footer.copyright` with `{year}` and `{commandCenter}` placeholders:
- **DCC**: "Copyright &copy; 2026 DCC - All Rights Reserved."
- **CANOC**: Same pattern with "CANOC"
- **ECFMP**: Same pattern with "ECFMP"

### Home Page Welcome Heading

Uses i18n key `home.welcome` with `{commandCenter}` placeholder:
- **DCC/en-US**: "Welcome to DCC's"
- **CANOC/en-CA**: "Welcome to CANOC's"
- **CANOC/fr-CA**: "Bienvenue sur le site de CANOC,"
- **ECFMP/en-EU**: "Welcome to ECFMP's"

---

## Locale-Specific Differences

### Available Locales
- `en-US`: Base/fallback (450+ keys)
- `en-CA`: CANOC English overlay (inherits en-US, overrides terminology like "Center" → "Centre", "ARTCC" → "ACC")
- `fr-CA`: CANOC French (near-complete translation)
- `en-EU`: ECFMP English overlay (minimal overrides, e.g., "Hotline" → "Landline")

### Pages That Change Visually Per Locale

Only pages with both PHP `__()` calls AND JS `PERTII18n.t()` calls change significantly:
- **High impact**: index (welcome heading), demand (all labels), gdt (all labels), splits (all labels), nod (labels), jatoc (all labels)
- **Medium impact**: nav (all dropdown labels), footer (copyright, disclaimer)
- **Low impact**: swim, swim-docs, fmds-comparison, transparency, privacy (mostly static content pages)

### Pages That Do NOT Change Per Locale

These lack `load/i18n.php` and have no JS i18n:
- tmi-publish, swim-keys, status, event-aar, sua, airport_config, playbook, data
- These will always render in English regardless of locale setting

---

## Duplicate `style` Attribute Bug

Two pages have duplicate `style` attributes on the `<section>` hero element (HTML spec: only first is applied):

```html
<!-- schedule.php line 78 -->
<section class="..." style="min-height: 250px" data-jarallax data-speed="0.3" style="pointer-events: all;">

<!-- route.php line 1372 -->
<section class="..." style="min-height: 250px" data-jarallax data-speed="0.3" style="pointer-events: all;">
```

The second `style="pointer-events: all;"` is ignored by browsers.

---

## Summary of Inconsistencies by Category

### Critical (Functional Impact)
| # | Issue | Affected Pages |
|---|-------|---------------|
| 1 | Footer included twice (duplicate JS loads) | plan, sheet, review |
| 2 | Duplicate `style` attribute (second ignored) | schedule, route |

### Moderate (Visual Inconsistency)
| # | Issue | Details |
|---|-------|---------|
| 3 | 3 different hero heights | fh-section vs min-vh-25 vs inline min-height (120-250px) |
| 4 | 2 different dark backgrounds | #1a1a2e (11 pages) vs #0f172a (jatoc only) |
| 5 | No shared dark-theme class | Each page re-declares dark styles inline |
| 6 | container vs container-fluid | 6 pages use narrow `container`, 18 use full-width |
| 7 | Page titles inconsistent | 4 different patterns across 27 pages |
| 8 | `<html lang>` inconsistent | 8 pages have `lang="en"`, 19 don't |

### Low (Code Quality)
| # | Issue | Details |
|---|-------|---------|
| 9 | 13 pages missing i18n | Always English, no locale support |
| 10 | No standard body background class | Dark pages each set their own inline styles |
| 11 | Hero padding varies per page | 5 different padding combinations |
| 12 | event-aar hero has no jarallax | Only page with static dark hero (120px) |

---

## Additional Findings

### Content Layout Sub-Patterns

Beyond hero sections, pages use 3 distinct content layout patterns:

| Pattern | Pages | Structure |
|---------|-------|-----------|
| **Sidebar tabs** (`.col-2` nav-pills + `.col-10` tab-content) | plan, sheet, review, data | 2-column layout with vertical pill navigation on left |
| **Full-width cards/tables** | index, schedule, gdt, demand, splits, sua, event-aar, airport_config, swim-keys, tmi-publish | Single column of cards, tables, or sections |
| **Full-bleed map + floating overlays** | route, playbook, nod, jatoc, simulator | Map fills viewport, UI controls float with absolute positioning |

### MapLibre GL Version Inconsistency

| Version | Pages |
|---------|-------|
| **4.7.1** | sua |
| **4.5.0** | route, playbook, jatoc |
| **3.6.2** | nod, splits, simulator, plan, demand |
| **None** | All other pages |

### CSS Architecture Issues

1. **No shared dark-theme class**: 11 pages use `#1a1a2e` but each declares it in inline `<style>` blocks. A single `body.perti-dark` class in `perti_theme.css` would eliminate ~50 lines of duplicated CSS.
2. **476 CSS variables defined but underused**: `perti-colors.css` defines a comprehensive design token system, but pages use hardcoded hex values (e.g., `#0f172a`, `#1e293b`) instead of variables like `var(--dark-bg-page)`.
3. **Inline `<style>` blocks vary from 0 to 1000+ lines**: jatoc.php has ~1000 lines of inline CSS, while privacy.php has zero. No consistent approach to page-specific styles (some use external CSS files like `playbook.css`, most inline).
4. **Font inconsistency**: Most pages inherit `'Jost', sans-serif` from `theme.css`, but several pages override with monospace fonts (`'Inconsolata'`, `'SF Mono'`, `'Consolas'`) for data display without a shared utility class.

### Session/Include Chain Variations

| Pattern | Pages |
|---------|-------|
| **Standard**: sessions → config → connect → header → nav | index, plan, sheet, review, schedule, airport_config, event-aar, sua, swim-keys, data |
| **Lightweight**: sessions → config → i18n → header → nav_public | demand, gdt, nod, swim, swim-doc, swim-docs, fmds-comparison, simulator, transparency, privacy, splits |
| **Minimal**: sessions → i18n → header → nav | route (skips config + connect) |
| **Custom**: config → i18n → manual session_start() → nav_public | jatoc (no sessions/handler.php) |
| **Auth-gated**: sessions → config → connect → perm check → 403/exit | tmi-publish, status, playbook |

### Pages That Hide Footer via CSS (Not Missing It)

Several map pages include `footer.php` but hide it with CSS:
```css
.cs-footer { display: none !important; }
```
- **nod.php**, **simulator.php**, **jatoc.php**: Footer is loaded (JS plugins execute) but visually hidden
- This means plugin JS (Jarallax, Summernote, etc.) still loads on these pages even though it's unused

---

## Recommended Standard (for future normalization)

If you wanted to standardize, here's what "consistent" would look like:

### Standard Page Template
```php
<?php
include("sessions/handler.php");
include("load/config.php");
include("load/connect.php");   // or PERTI_MYSQL_ONLY
include("load/i18n.php");
// ... permission checks ...
?>
<!DOCTYPE html>
<html lang="<?= substr(__locale(), 0, 2) ?>">
<head>
    <?php $page_title = __('pageName.title'); include("load/header.php"); ?>
</head>
<body>
<?php include('load/nav.php'); /* or nav_public.php */ ?>

<!-- Hero: Pick ONE pattern -->
<section class="perti-hero perti-hero--compact"> <!-- or --full, --micro, --none -->
    ...
</section>

<!-- Content -->
<div class="container-fluid mt-4 mb-5">
    ...
</div>

<?php include('load/footer.php'); /* ONCE */ ?>
</body>
</html>
```

### Proposed Hero Classes (to replace 6 ad-hoc patterns)
| Class | Height | Use Case |
|-------|--------|----------|
| `perti-hero--full` | 100vh | Landing pages (index, privacy, transparency) |
| `perti-hero--standard` | 250px | Standard pages with header context |
| `perti-hero--compact` | 25vh | Data pages (gdt, demand, splits) |
| `perti-hero--micro` | 80px | Tool pages (jatoc) |
| (none) | 0 | Full-bleed map/tool pages (nod, simulator, playbook) |

### Proposed Dark Theme
A single `body.perti-dark` class in `perti_theme.css` instead of per-page inline styles:
```css
body.perti-dark {
    background-color: #1a1a2e;
    color: #e2e8f0;
}
```

---

## Raw Data: Complete Page Matrix

| Page | Nav | Hero | Height | Body BG | Container | Title | lang= | i18n | Footer | Login | Map |
|------|-----|------|--------|---------|-----------|-------|-------|------|--------|-------|-----|
| index | nav | A | fh | light | fluid | i18n | no | yes | 1x | no | no |
| plan | nav | C | 250px | light | fluid | hardcoded+PERTI | no | via chain | **2x** | yes | no |
| sheet | nav | C | 250px | light | fluid | hardcoded+PERTI | no | via chain | **2x** | yes | no |
| review | nav | C | 200px | light | fluid | hardcoded+PERTI | no | via chain | **2x** | yes | no |
| data | nav | C | 250px | light | fluid | hardcoded+PERTI | no | via chain | 1x | yes | no |
| schedule | nav | C | 250px | light | fluid | hardcoded | no | via chain | 1x | yes | no |
| airport_config | nav | A | fh | light | fluid | hardcoded | no | no | 1x | yes | no |
| event-aar | nav | C | 120px | light | fluid | hardcoded | no | no | 1x | no | no |
| sua | nav | C | 200px | light | fluid | hardcoded | no | no | 1x | yes | no |
| tmi-publish | nav | A* | fh(pt-4) | light | fluid | hardcoded | en | no | 1x | yes | no |
| swim-keys | nav | B | 25vh | light | fluid | hardcoded-PERTI | no | no | 1x | yes | no |
| status | nav | E | none | dark#1a1a2e | fluid | hardcoded+PERTI | en | no | 1x | no | no |
| route | nav | C | 250px | dark#1a1a2e | fluid | hardcoded | no | yes | 1x | no | yes |
| playbook | nav | E | none | light | fluid | hardcoded | no | no | 1x | no | yes |
| demand | pub | B | 25vh | dark#1a1a2e | fluid | i18n | en | yes | 1x | no | no |
| gdt | pub | B | 25vh | light | fluid | i18n | en | yes | 1x | no | no |
| splits | pub | B | 25vh | dark#1a1a2e | fluid | i18n | no | yes | 1x | no | yes |
| nod | pub | E | none | dark#1a1a2e | none | hardcoded | en | yes | 1x | no | yes |
| jatoc | pub | D | 80px | dark#0f172a | fluid | hardcoded | en | yes | 1x | no | no |
| swim | pub | B | 25vh | dark#1a1a2e | container | hardcoded-PERTI | no | yes | 1x | no | no |
| swim-doc | pub | F | none | dark#1a1a2e | container | hardcoded-PERTI | no | yes | 1x | no | no |
| swim-docs | pub | B | 25vh | dark#1a1a2e | container | hardcoded-PERTI | no | yes | 1x | no | no |
| fmds-comparison | pub | none | none | dark#1a1a2e | container | hardcoded-PERTI | no | yes | 1x | no | no |
| simulator | pub | E | none | dark#1a1a2e | none | hardcoded | en | yes | 1x | no | yes |
| transparency | pub | A | fh | light | container | hardcoded-PERTI | no | yes | 1x | no | no |
| privacy | pub | A | fh | light | container | hardcoded | no | yes | 1x | no | no |
