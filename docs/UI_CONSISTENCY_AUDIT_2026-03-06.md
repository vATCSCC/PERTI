# UI Consistency Audit - 2026-03-06

## Scope
- Two-pass audit across major UI surfaces and shared front-end code.
- Files reviewed in depth: `jatoc.php`, `tmi-publish.php`, `route.php`, `review.php`, `gdt.php`, `status.php`, plus high-volume JS renderers.

## Method
- Pass 1: broad codebase scan for inline styles, fixed sizing, repeated date/time formatting, and hardcoded UI strings.
- Pass 2: targeted sweep for missed issues (a11y labels/button semantics, i18n drift, overlap/overflow risk, z-index layering drift).

## Quantitative hotspots
- Inline `style=` in PHP templates (top files):
  - `status.php` 172
  - `demand.php` 99
  - `gdt.php` 86
  - `tmi-publish.php` 76
  - `nod.php` 67
  - `splits.php` 62
  - `jatoc.php` 58
  - `route.php` 50
- Inline `style=` in JS-generated HTML (top files):
  - `assets/js/tmi_compliance.js` 192
  - `assets/js/nod.js` 133
  - `assets/js/splits.js` 72
  - `assets/js/route-maplibre.js` 59
  - `assets/js/tmi-publish.js` 50
- Repeated raw date formatting (`toISOString().slice(...)` / ad-hoc formatters) despite shared utility:
  - `assets/js/tmi-publish.js` 14
  - `assets/js/jatoc.js` 6
  - `assets/js/tmi-active-display.js` 4
  - `assets/js/gdt.js` 3
  - `assets/js/splits.js` 2

## Findings (prioritized)

### P1 - Inline style sprawl is the main consistency blocker
- Symptoms:
  - Same UI primitives are restyled ad hoc per page (button heights, micro-font sizes, panel backgrounds, legend chips).
  - Changes to one area do not propagate visually to similar controls.
- Evidence:
  - `jatoc.php:455`, `jatoc.php:456`, `jatoc.php:457` (manual `height:31px` button alignment)
  - `tmi-publish.php:1349`, `tmi-publish.php:1350`, `tmi-publish.php:1352`, `tmi-publish.php:1426` (hardcoded table column widths)
  - `status.php:3823` through `status.php:3837` (repeated stat-box inline visual styles)
  - `assets/js/route-maplibre.js:2867` through `assets/js/route-maplibre.js:2873` equivalent block with repeated inline popup styling
- Recommendation:
  - Create shared utility classes for `btn-compact`, `panel-scroll`, `legend-chip`, `mono-small`, `table-tight`.
  - Convert repeated inline patterns in top 5 hotspot files first.

### P1 - Date/time rendering is fragmented
- Symptoms:
  - Slightly different UTC formats appear across modules; risk of display mismatch and parsing errors.
- Evidence:
  - `assets/js/jatoc.js:1479` and `assets/js/jatoc.js:1480` define local formatters.
  - `assets/js/tmi-publish.js:1944`, `assets/js/tmi-publish.js:3077`, `assets/js/tmi-publish.js:4557` use direct `toISOString().slice(...)`.
  - `assets/js/tmi-active-display.js:1752`, `assets/js/tmi-active-display.js:1888` duplicate input-format logic.
  - Shared utility exists in `assets/js/lib/datetime.js:82` and `assets/js/lib/datetime.js:155`.
- Recommendation:
  - Standardize on `PERTIDateTime` utility for all display/input formatting.
  - Add lint rule or grep gate in CI for new raw `toISOString().slice(...)` in app code.

### P1 - Responsive overflow risk from fixed pixel widths/heights
- Symptoms:
  - Dense controls/tables pin width in px and can collide/overflow on narrow viewports.
- Evidence:
  - `route.php:1815`, `route.php:1817`, `route.php:1823`, `route.php:1825`, `route.php:1827`
  - `tmi-publish.php:715` through `tmi-publish.php:723`
  - `tmi-publish.php:1421` through `tmi-publish.php:1426`
  - `review.php:755`, `review.php:761`, `review.php:762`, `review.php:763`
- Recommendation:
  - Replace fixed widths with semantic column classes and responsive breakpoints.
  - Use stacked/mobile layout for filter rows under 768px.

### P2 - Accessibility gaps in icon-only actions and button semantics
- Symptoms:
  - Some icon-only buttons have no text/`aria-label`; many buttons omit explicit `type`.
- Evidence:
  - Icon-only buttons: `jatoc.php:387`, `jatoc.php:391`, `review.php:810`.
  - Missing explicit `type` appears widely (e.g., `tmi-publish.php:693`, `route.php:1607`, `review.php:649`, `jatoc.php:455`).
- Recommendation:
  - Add `aria-label` to icon-only controls and provide tooltip text from locale keys.
  - Set `type="button"` for non-submit actions to avoid accidental form submits.

### P2 - i18n drift in placeholders/titles and static labels
- Symptoms:
  - Mixed localized and hardcoded English copy in the same screens.
- Evidence:
  - `review.php:574`, `review.php:595`, `review.php:721`, `review.php:984`
  - `route.php:1925`, `route.php:1929`, `route.php:1937` through `route.php:1961`
  - `tmi-publish.php:444`, `tmi-publish.php:467`, `tmi-publish.php:558`, `tmi-publish.php:1312`, `tmi-publish.php:1565`
- Recommendation:
  - Move remaining literals to locale JSON keys.
  - Add a static check to flag plain English in `placeholder=` / `title=` on localized pages.

### P3 - Color and layer token inconsistency
- Symptoms:
  - Frequent hardcoded hex values and ad-hoc z-index values increase visual drift and overlap risk.
- Evidence:
  - Frequent literals in CSS include `#fff` (364), `#000` (32), `#28a745` (23), `#dc3545` (17).
  - Inline z-index usage: `route.php:108`, `route.php:125`, `route.php:382`, plus high values in JS (`assets/js/tmi-active-display.js:575`, `assets/js/route-maplibre.js:2098`).
- Recommendation:
  - Extend token map in shared theme variables (`assets/css/perti-colors.css` / `assets/css/perti_theme.css`).
  - Define z-index scale tokens (dropdown, sticky, modal, toast, overlay) and migrate ad hoc values.

## Quick-win backlog (small changes)
1. Add shared compact control class and remove manual `height:31px` in JATOC filter/action row.
2. Add `type="button"` sweep for non-submit buttons in `jatoc.php`, `review.php`, `route.php`, `tmi-publish.php`.
3. Add `aria-label` for all icon-only buttons (start with `jatoc.php:387`, `jatoc.php:391`, `review.php:810`).
4. Centralize datetime formatting in `PERTIDateTime` for JATOC, TMI Publish, TMI Active Display, GDT.
5. Replace hardcoded placeholder/title strings with locale keys in `review.php`, `route.php`, `tmi-publish.php`.
6. Create `table-col-*` helper classes for repeated fixed-width table headers in TMI Publish and Review.

## Suggested rollout
1. Phase A (1 PR): a11y + button semantics + zero-behavior style helpers.
2. Phase B (1 PR): datetime normalization to shared utility.
3. Phase C (1-2 PRs): i18n placeholder/title migration + fixed-width table cleanup.
4. Phase D (ongoing): inline-style reduction in top 5 hotspots by count.

## Second sweep completion note
- Second sweep was completed after initial hotspot scan and specifically re-checked:
  - icon-only controls and button semantics,
  - localization drift,
  - responsive overlap/overflow vectors,
  - time formatting duplication.

