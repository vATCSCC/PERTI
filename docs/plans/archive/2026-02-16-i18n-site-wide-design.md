# Site-Wide Internationalization (i18n) Design

**Date**: 2026-02-16
**Status**: Approved
**Goal**: Enable full French (fr-CA) support so VATCAN users can interact with the entire PERTI site natively, with an architecture that trivially supports adding new languages (e.g., Spanish for VATCAR).

## Architecture Overview

Two parallel i18n layers sharing the same JSON locale files:

1. **PHP `__()` function** - Server-side translations for PHP-rendered HTML
2. **JS `PERTII18n.t()`** - Client-side translations for JS-generated content (already exists)

Both read from `assets/locales/{locale}.json` with automatic fallback to `en-US.json`.

## Design Principles

- **Locale vs Org are orthogonal**: Language (en-US/en-CA/fr-CA) is independent of organization (vatcscc/vatcan). Org-specific terms use `{commandCenter}` placeholders resolved from `window.PERTI_ORG`.
- **Delta locale files**: Only keys that differ from en-US go in en-CA/fr-CA. Missing keys automatically fall back.
- **Same JSON files for PHP and JS**: No duplication of translation strings.
- **YAGNI**: No database-stored translations, no translation management UI, no async loading. Synchronous JSON file reads on both sides.

---

## 1. PHP i18n Module (`load/i18n.php`)

New file: `load/i18n.php`

### API

```php
// Simple translation
__('common.save')                                    // "Save" or "Enregistrer"
__('error.loadFailed', ['resource' => 'flights'])    // "Failed to load flights"

// Pluralization
__p('flight', 5)                                     // "5 flights" or "5 vols"

// Check if key exists
__has('myFeature.title')                             // true/false
```

### Implementation

```php
class PERTII18n {
    private static $strings = [];
    private static $fallback = [];
    private static $locale = 'en-US';
    private static $initialized = false;

    public static function init($locale = null) {
        if (self::$initialized) return;
        self::$locale = $locale ?? $_SESSION['PERTI_LOCALE'] ?? 'en-US';

        // Load en-US as fallback
        $enPath = __DIR__ . '/../assets/locales/en-US.json';
        if (file_exists($enPath)) {
            self::$fallback = self::flatten(json_decode(file_get_contents($enPath), true) ?: []);
        }

        // Load active locale (if not en-US)
        if (self::$locale !== 'en-US') {
            $localePath = __DIR__ . '/../assets/locales/' . self::$locale . '.json';
            if (file_exists($localePath)) {
                self::$strings = self::flatten(json_decode(file_get_contents($localePath), true) ?: []);
            }
        } else {
            self::$strings = self::$fallback;
        }

        self::$initialized = true;
    }

    public static function t($key, $params = []) {
        $str = self::$strings[$key] ?? self::$fallback[$key] ?? $key;
        foreach ($params as $k => $v) {
            $str = str_replace('{' . $k . '}', $v, $str);
        }
        return $str;
    }

    public static function tp($key, $count, $params = []) {
        $pluralKey = $count === 1 ? "$key.one" : "$key.other";
        return self::t($pluralKey, array_merge(['count' => $count], $params));
    }

    public static function has($key) {
        return isset(self::$strings[$key]) || isset(self::$fallback[$key]);
    }

    public static function getLocale() {
        return self::$locale;
    }

    private static function flatten($arr, $prefix = '') {
        $result = [];
        foreach ($arr as $k => $v) {
            $newKey = $prefix ? "$prefix.$k" : $k;
            if (is_array($v)) {
                $result = array_merge($result, self::flatten($v, $newKey));
            } else {
                $result[$newKey] = $v;
            }
        }
        return $result;
    }
}

// Convenience functions (global scope)
function __($key, $params = []) {
    PERTII18n::init();
    return PERTII18n::t($key, $params);
}

function __p($key, $count, $params = []) {
    PERTII18n::init();
    return PERTII18n::tp($key, $count, $params);
}

function __has($key) {
    PERTII18n::init();
    return PERTII18n::has($key);
}
```

### Loading

`i18n.php` is included from `load/connect.php` (after session start) or `load/header.php`. The `init()` call is lazy — first `__()` call triggers it.

Locale detection priority (PHP side):
1. `$_SESSION['PERTI_LOCALE']` (set by JS locale switcher via API)
2. Org default locale from `organizations` table
3. `en-US` fallback

### Org-aware placeholders

After loading locale strings, the PHP module resolves `{commandCenter}` from the org context (same as JS side does in `index.js`):

```php
// In i18n.php init(), after loading strings:
$orgInfo = get_org_info($conn_sqli);
$displayName = $orgInfo['display_name'] ?? 'DCC';
// Walk all loaded strings and replace {commandCenter}
```

---

## 2. Locale File Strategy

### File structure

```
assets/locales/
  en-US.json    # Full English (US) — the authoritative source (~2,060 keys)
  en-CA.json    # Canadian English delta (~25 keys that differ)
  fr-CA.json    # Quebecois French — full translation of all ~2,060 keys
  index.js      # Locale loader (existing)
```

### Key naming convention

```
{page}.{section}.{element}
```

Examples:
- `nav.planning` = "Planning" (navigation menu)
- `plan.staffing.terminal` = "Terminal Staffing"
- `gdt.table.callsign` = "Callsign"
- `demand.chart.title` = "Fix Demand"

### What gets translated

- All user-facing UI text (labels, headings, buttons, tooltips, placeholders)
- Error messages, success messages, confirmation dialogs
- Navigation menu items
- Table column headers
- Chart labels and legends

### What does NOT get translated

- Aviation abbreviations (ICAO codes, ARTCC names, callsigns, fix names)
- Database field names in API responses
- Log messages (server-side)
- Developer-facing error messages in API JSON responses

---

## 3. PHP Page Migration Pattern

### Before (hardcoded English)

```php
<h2>Terminal Staffing</h2>
<th>Facility</th>
<button>Save Changes</button>
```

### After (i18n)

```php
<h2><?= __('plan.staffing.terminal') ?></h2>
<th><?= __('plan.table.facility') ?></th>
<button><?= __('common.save') ?> <?= __('common.changes') ?></button>
```

### Migration scope

**User-facing PHP pages** (28 files in project root):
- `index.php`, `plan.php`, `sheet.php`, `review.php`, `schedule.php`
- `demand.php`, `gdt.php`, `nod.php`, `splits.php`, `route.php`
- `tmi-publish.php`, `sua.php`, `jatoc.php`, `airport_config.php`
- `airspace-elements.php`, `event-aar.php`, `status.php`
- `swim.php`, `swim-doc.php`, `swim-docs.php`, `swim-keys.php`
- `simulator.php`, `transparency.php`, `privacy.php`
- `fmds-comparison.php`, `data.php`
- `login/` pages

**Shared includes** (high-impact, translate once):
- `load/nav.php` - Navigation menu (~30 strings)
- `load/nav_public.php` - Public navigation
- `load/footer.php` - Footer
- `load/breadcrumb.php` - Breadcrumbs
- `load/gdp_section.php` - GDP section partial

### Exclusions (no i18n needed)

- API endpoints (`api/`) - return JSON data, not user-facing HTML
- Scripts (`scripts/`) - server-side only
- Daemons (`adl/php/`) - no user-facing output
- `healthcheck.php`, `logout.php` - no translatable content

---

## 4. JS Migration Scope

**20 JS files already use `PERTII18n.t()`** — need to complete the migration (replace remaining hardcoded strings):

Files with partial coverage (use t() but still have hardcoded strings):
- `tmi-publish.js`, `gdt.js`, `nod.js`, `demand.js`, `splits.js`
- `plan.js`, `sheet.js`, `review.js`, `jatoc.js`
- `weather_impact.js`, `reroute.js`, `sua.js`, `schedule.js`
- `tmr_report.js`, `tmi_compliance.js`

Files with no i18n yet:
- `route-maplibre.js`, `advisory-builder.js`, `advisory-config.js`
- `tmi-active-display.js`, `tmi-gdp.js`
- `public-routes.js`, `playbook-cdr-search.js`
- `airspace_display.js`, `nod-demand-layer.js`
- `weather_radar.js`, `weather_hazards.js`
- `initiative_timeline.js`

The mechanical pattern is:
```javascript
// Before
$('#title').text('Loading flights...');
// After
$('#title').text(PERTII18n.t('common.loading'));
```

---

## 5. fr-CA.json Expansion

Current state: fr-CA.json has ~115 lines covering common/status/tmi/phase/dialog/error/flight/dccRegion/tmr sections (~120 keys).

en-US.json has ~2,060 keys. After adding PHP page keys (nav, headings, labels, etc.), total will be ~2,400+ keys.

**fr-CA.json needs ~2,280 new French translations.** This is the single largest piece of work.

### Approach

1. Add new en-US.json keys as PHP pages are migrated (batch per page)
2. Add corresponding fr-CA.json translations in the same batch
3. en-CA.json only needs delta entries (Canadian English spelling differences)

### Translation quality

- Use proper Quebecois French (not France French) — e.g., "courriel" not "e-mail"
- Aviation terms that are universally English stay English (ICAO, METAR, NOTAM, etc.)
- TMI terms have established French equivalents (GDP = Programme d'attente au sol, etc.) already in fr-CA.json

---

## 6. Locale Persistence & Switching

### Current state

- JS: `localStorage.PERTI_LOCALE` + URL param `?locale=xx`
- PHP: No locale session variable (new)

### Design

Add `$_SESSION['PERTI_LOCALE']` synced from JS:

1. JS locale switcher calls `POST /api/data/locale.php` with `{ locale: 'fr-CA' }`
2. PHP sets `$_SESSION['PERTI_LOCALE'] = $locale` and returns OK
3. JS reloads page — PHP now renders in new locale
4. JS `index.js` reads same locale from localStorage (already works)

New file: `api/data/locale.php` (3 lines of PHP — validate locale, set session, return 200)

### Locale switcher UI

Add language toggle to `load/nav.php` — small dropdown or flag buttons in the navbar. Only shows locales supported by the active org (vatcscc = en-US only, vatcan = en-US/en-CA/fr-CA).

---

## File Impact Summary

| Category | Files | New Keys |
|----------|-------|----------|
| New: `load/i18n.php` | 1 | 0 |
| New: `api/data/locale.php` | 1 | 0 |
| Modify: `load/nav.php` | 1 | ~30 |
| Modify: `load/footer.php` | 1 | ~5 |
| Modify: `load/header.php` | 1 | 0 |
| Modify: `load/connect.php` | 1 | 0 |
| Modify: PHP pages (root) | ~22 | ~400 |
| Modify: JS files | ~30 | ~200 |
| Modify: `en-US.json` | 1 | ~600 |
| Modify: `fr-CA.json` | 1 | ~2,280 |
| Modify: `en-CA.json` | 1 | ~10 |
| **Total** | **~62** | **~2,900** |
