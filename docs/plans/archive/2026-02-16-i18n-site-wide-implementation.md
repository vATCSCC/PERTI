# Site-Wide Internationalization Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Make every user-facing string on the PERTI site translatable, with complete French (fr-CA) translations for VATCAN users.

**Architecture:** PHP `__()` function + JS `PERTII18n.t()` both read from `assets/locales/{locale}.json`. Delta locale files (en-CA/fr-CA) fall back to en-US.json automatically. Locale persisted in `$_SESSION['PERTI_LOCALE']` (PHP) and `localStorage.PERTI_LOCALE` (JS), synced via a tiny API endpoint.

**Tech Stack:** PHP 8.2, vanilla JS, JSON locale files, existing PERTII18n module

**Design doc:** `docs/plans/2026-02-16-i18n-site-wide-design.md`

**Worktree:** `C:/Temp/perti-worktrees/i18n-site-wide` on branch `feature/i18n-site-wide`

---

## Important Context

- **No automated test suite** — verify changes manually by loading pages with `?locale=fr-CA` URL parameter
- **en-US.json already has 1,609 keys** across 34 sections (common, status, phase, tmi, gdt, nod, splits, demand, plan, etc.)
- **fr-CA.json currently has ~120 keys** — needs full expansion
- **20 JS files already use `PERTII18n.t()`** but most still have hardcoded strings mixed in
- **Aviation abbreviations stay English** (ICAO codes, ARTCC names, callsigns, fix names, METAR, etc.)
- **`{commandCenter}` placeholders** are already resolved by `assets/locales/index.js` from `window.PERTI_ORG.orgInfo.display_name`
- **Supported locales**: en-US, en-CA, fr-CA (defined in `assets/locales/index.js` line 24)
- The `__()` function name is PHP's conventional i18n function name (WordPress, Laravel, etc.)

### Key Files Reference

| File | Purpose |
|------|---------|
| `load/i18n.php` | **NEW** — PHP i18n module with `__()`, `__p()`, `__has()` |
| `api/data/locale.php` | **NEW** — Locale session sync endpoint |
| `assets/locales/en-US.json` | English (US) — authoritative source |
| `assets/locales/en-CA.json` | Canadian English delta |
| `assets/locales/fr-CA.json` | Quebecois French — full translation |
| `assets/locales/index.js` | JS locale loader (auto-detects, loads JSON) |
| `assets/js/lib/i18n.js` | JS `PERTII18n` module (`t()`, `tp()`, `has()`) |
| `load/header.php` | HTML head — loads i18n.js + index.js + org context |
| `load/nav.php` | Navigation bar — data-driven config on lines 49-108 |
| `load/footer.php` | Footer — copyright + links |
| `load/connect.php` | DB connections — where `i18n.php` gets included |
| `load/org_context.php` | Org helpers — `get_org_info()`, `get_org_code()` |

---

### Task 1: Create PHP i18n Module

**Files:**
- Create: `load/i18n.php`
- Modify: `load/connect.php` (add `require_once` for i18n.php)

**Step 1: Create `load/i18n.php`**

```php
<?php
/**
 * PERTI PHP Internationalization Module
 *
 * Reads the same JSON locale files as the JS PERTII18n module.
 * Provides __(), __p(), and __has() convenience functions.
 *
 * Usage:
 *   __('common.save')                              // "Save" or "Enregistrer"
 *   __('error.loadFailed', ['resource' => 'vols']) // "Failed to load vols"
 *   __p('flight', 5)                               // "5 flights" or "5 vols"
 *
 * @version 1.0.0
 */

if (defined('I18N_PHP_LOADED')) {
    return;
}
define('I18N_PHP_LOADED', true);

class PERTII18nPHP {
    private static $strings = [];
    private static $fallback = [];
    private static $locale = 'en-US';
    private static $initialized = false;

    /** Supported locales — must match assets/locales/index.js SUPPORTED_LOCALES */
    const SUPPORTED_LOCALES = ['en-US', 'en-CA', 'fr-CA'];

    /**
     * Initialize the i18n system. Called lazily on first __() call.
     * @param string|null $locale Override locale (otherwise reads session/org/default)
     */
    public static function init($locale = null) {
        if (self::$initialized) return;

        // Determine locale: explicit > session > org default > en-US
        if ($locale && in_array($locale, self::SUPPORTED_LOCALES)) {
            self::$locale = $locale;
        } elseif (!empty($_SESSION['PERTI_LOCALE']) && in_array($_SESSION['PERTI_LOCALE'], self::SUPPORTED_LOCALES)) {
            self::$locale = $_SESSION['PERTI_LOCALE'];
        } else {
            self::$locale = 'en-US';
        }

        // Load en-US as fallback (always)
        $enPath = __DIR__ . '/../assets/locales/en-US.json';
        if (file_exists($enPath)) {
            $data = json_decode(file_get_contents($enPath), true);
            if ($data) {
                self::$fallback = self::flatten($data);
            }
        }

        // Load active locale
        if (self::$locale !== 'en-US') {
            $localePath = __DIR__ . '/../assets/locales/' . self::$locale . '.json';
            if (file_exists($localePath)) {
                $data = json_decode(file_get_contents($localePath), true);
                if ($data) {
                    self::$strings = self::flatten($data);
                }
            }
        } else {
            self::$strings = self::$fallback;
        }

        // Resolve {commandCenter} placeholders from org context
        self::resolveOrgPlaceholders();

        self::$initialized = true;
    }

    /**
     * Translate a key with optional parameter interpolation
     */
    public static function t($key, $params = []) {
        self::init();
        $str = self::$strings[$key] ?? self::$fallback[$key] ?? $key;
        foreach ($params as $k => $v) {
            $str = str_replace('{' . $k . '}', (string)$v, $str);
        }
        return $str;
    }

    /**
     * Translate with pluralization (key.one / key.other)
     */
    public static function tp($key, $count, $params = []) {
        $pluralKey = $count === 1 ? "$key.one" : "$key.other";
        return self::t($pluralKey, array_merge(['count' => $count], $params));
    }

    /**
     * Check if a translation key exists
     */
    public static function has($key) {
        self::init();
        return isset(self::$strings[$key]) || isset(self::$fallback[$key]);
    }

    /**
     * Get current locale
     */
    public static function getLocale() {
        self::init();
        return self::$locale;
    }

    /**
     * Flatten nested array to dot-notation keys
     */
    private static function flatten($arr, $prefix = '') {
        $result = [];
        foreach ($arr as $k => $v) {
            $newKey = $prefix !== '' ? "$prefix.$k" : $k;
            if (is_array($v)) {
                $result = array_merge($result, self::flatten($v, $newKey));
            } else {
                $result[$newKey] = $v;
            }
        }
        return $result;
    }

    /**
     * Replace {commandCenter} in all loaded strings with org display name
     */
    private static function resolveOrgPlaceholders() {
        // Try to get org display name from session cache or org_context
        $displayName = 'DCC'; // default
        if (function_exists('get_org_info') && !empty($GLOBALS['conn_sqli'])) {
            $orgInfo = get_org_info($GLOBALS['conn_sqli']);
            $displayName = $orgInfo['display_name'] ?? 'DCC';
        } elseif (!empty($_SESSION['ORG_INFO_' . ($_SESSION['ORG_CODE'] ?? 'vatcscc')])) {
            $cached = $_SESSION['ORG_INFO_' . ($_SESSION['ORG_CODE'] ?? 'vatcscc')];
            $displayName = $cached['display_name'] ?? 'DCC';
        }

        // Walk strings and resolve placeholders
        foreach (self::$strings as $k => &$v) {
            if (is_string($v) && strpos($v, '{commandCenter}') !== false) {
                $v = str_replace('{commandCenter}', $displayName, $v);
            }
        }
        foreach (self::$fallback as $k => &$v) {
            if (is_string($v) && strpos($v, '{commandCenter}') !== false) {
                $v = str_replace('{commandCenter}', $displayName, $v);
            }
        }
    }

    /**
     * Reset for testing purposes
     */
    public static function reset() {
        self::$strings = [];
        self::$fallback = [];
        self::$locale = 'en-US';
        self::$initialized = false;
    }
}

// =========================================================================
// Global convenience functions
// =========================================================================

if (!function_exists('__')) {
    function __($key, $params = []) {
        return PERTII18nPHP::t($key, $params);
    }
}

if (!function_exists('__p')) {
    function __p($key, $count, $params = []) {
        return PERTII18nPHP::tp($key, $count, $params);
    }
}

if (!function_exists('__has')) {
    function __has($key) {
        return PERTII18nPHP::has($key);
    }
}
```

**Step 2: Add `require_once` to `load/connect.php`**

In `load/connect.php`, after the `require_once(__DIR__ . '/input.php');` line (line 16), add:

```php
require_once(__DIR__ . '/i18n.php');
```

This ensures `__()` is available on every page that includes `connect.php`.

**Step 3: Verify by loading any page**

Navigate to `https://perti.vatcscc.org` and confirm no PHP errors. Then test with `?locale=fr-CA` — `__('common.save')` should return "Enregistrer" (fr-CA.json has this key).

**Step 4: Commit**

```bash
git add load/i18n.php load/connect.php
git commit -m "feat(i18n): add PHP i18n module with __() translation function"
```

---

### Task 2: Create Locale Session Sync Endpoint

**Files:**
- Create: `api/data/locale.php`
- Modify: `load/nav.php` (update `setLocale()` JS function to call API)

**Step 1: Create `api/data/locale.php`**

```php
<?php
/**
 * Locale Session Sync
 *
 * POST: Set session locale (called by JS locale switcher)
 * GET:  Return current session locale
 */

include("../../load/config.php");
define('PERTI_MYSQL_ONLY', true);
include("../../load/connect.php");

// Start session if not started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

$supported = ['en-US', 'en-CA', 'fr-CA'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $locale = $input['locale'] ?? '';

    if (!in_array($locale, $supported)) {
        http_response_code(400);
        echo json_encode(['error' => 'Unsupported locale', 'supported' => $supported]);
        exit;
    }

    $_SESSION['PERTI_LOCALE'] = $locale;
    echo json_encode(['success' => true, 'locale' => $locale]);
} else {
    echo json_encode(['locale' => $_SESSION['PERTI_LOCALE'] ?? 'en-US']);
}
```

**Step 2: Update `setLocale()` in `load/nav.php`**

Find the existing `setLocale` function (around line 322-325) and replace it with:

```javascript
function setLocale(locale) {
    localStorage.setItem('PERTI_LOCALE', locale);
    fetch('/api/data/locale.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({locale: locale})
    }).then(function() {
        window.location.reload();
    });
}
```

This syncs the locale to both localStorage (for JS) and the PHP session (for server-side rendering).

**Step 3: Verify**

1. Navigate to the site as VATCAN org
2. Click FR button in navbar
3. Reload should now have `$_SESSION['PERTI_LOCALE']` = `fr-CA`
4. Any `__('common.save')` calls should return French

**Step 4: Commit**

```bash
git add api/data/locale.php load/nav.php
git commit -m "feat(i18n): add locale session sync endpoint and update setLocale"
```

---

### Task 3: Internationalize Navigation & Footer

**Files:**
- Modify: `load/nav.php`
- Modify: `load/footer.php`
- Modify: `assets/locales/en-US.json` (add `nav.*` and `footer.*` keys)
- Modify: `assets/locales/fr-CA.json` (add French translations)

**Step 1: Add navigation keys to `en-US.json`**

Add a new top-level `"nav"` section to `assets/locales/en-US.json`:

```json
"nav": {
    "planning": "Planning",
    "plans": "Plans",
    "configs": "Configs",
    "routes": "Routes",
    "simulator": "Simulator",
    "data": "Data",
    "nod": "NOD",
    "gdt": "GDT",
    "demand": "Demand",
    "splits": "Splits",
    "tools": "Tools",
    "jatoc": "JATOC",
    "eventAar": "Event AAR",
    "tmiPublisher": "TMI Publisher",
    "status": "Status",
    "admin": "Admin",
    "schedule": "Schedule",
    "sua": "SUA",
    "crossings": "Crossings",
    "swim": "SWIM",
    "overview": "Overview",
    "apiKeys": "API Keys",
    "apiDocs": "API Docs",
    "technicalDocs": "Technical Docs",
    "about": "About",
    "infrastructure": "Infrastructure",
    "privacyPolicy": "Privacy Policy",
    "login": "Login",
    "logout": "Logout",
    "menu": "Menu"
},
"footer": {
    "copyright": "Copyright \u00a9 {year} vATCSCC - All Rights Reserved.",
    "disclaimer": "For Flight Simulation Use Only. This site is not intended for real world navigation, and not affiliated with any governing aviation body. All content contained is approved only for use on the VATSIM network.",
    "privacyPolicy": "Privacy Policy"
}
```

**Step 2: Add French translations to `fr-CA.json`**

Add to `fr-CA.json`:

```json
"nav": {
    "planning": "Planification",
    "plans": "Plans",
    "configs": "Configurations",
    "routes": "Routes",
    "simulator": "Simulateur",
    "data": "Donn\u00e9es",
    "tools": "Outils",
    "eventAar": "TAA \u00e9v\u00e9nement",
    "tmiPublisher": "Publieur IGC",
    "status": "\u00c9tat",
    "admin": "Administration",
    "schedule": "Horaire",
    "crossings": "Croisements",
    "overview": "Aper\u00e7u",
    "apiKeys": "Cl\u00e9s API",
    "apiDocs": "Docs API",
    "technicalDocs": "Docs techniques",
    "about": "\u00c0 propos",
    "infrastructure": "Infrastructure",
    "privacyPolicy": "Politique de confidentialit\u00e9",
    "login": "Connexion",
    "logout": "D\u00e9connexion",
    "menu": "Menu"
},
"footer": {
    "copyright": "Copyright \u00a9 {year} vATCSCC - Tous droits r\u00e9serv\u00e9s.",
    "disclaimer": "Pour usage en simulation de vol uniquement. Ce site n'est pas destin\u00e9 \u00e0 la navigation r\u00e9elle et n'est affili\u00e9 \u00e0 aucun organisme de r\u00e9glementation a\u00e9ronautique. Tout le contenu est approuv\u00e9 uniquement pour utilisation sur le r\u00e9seau VATSIM.",
    "privacyPolicy": "Politique de confidentialit\u00e9"
}
```

**Step 3: Update `load/nav.php` to use `__()`**

Add `require_once __DIR__ . '/i18n.php';` near the top of nav.php (after the `include_once("connect.php")` on line 18).

Replace the `$nav_config` array labels (lines 49-108) with `__()` calls:

```php
$nav_config = [
    'planning' => [
        'label' => __('nav.planning'),
        'items' => [
            ['label' => __('nav.plans'), 'path' => './'],
            ['label' => __('nav.configs'), 'path' => './airport_config'],
            ['label' => __('nav.routes'), 'path' => './route'],
            ['label' => __('nav.simulator'), 'path' => './simulator'],
        ]
    ],
    'data' => [
        'label' => __('nav.data'),
        'items' => [
            ['label' => __('nav.nod'), 'path' => './nod'],
            ['label' => __('nav.gdt'), 'path' => './gdt'],
            ['label' => __('nav.demand'), 'path' => './demand'],
            ['label' => __('nav.splits'), 'path' => './splits'],
        ]
    ],
    'tools' => [
        'label' => __('nav.tools'),
        'items' => [
            ['label' => __('nav.jatoc'), 'path' => './jatoc'],
            ['label' => __('nav.eventAar'), 'path' => './event-aar'],
            ['label' => __('nav.tmiPublisher'), 'path' => './tmi-publish', 'perm' => true],
            ['label' => __('nav.status'), 'path' => './status'],
        ]
    ],
    'admin' => [
        'label' => __('nav.admin'),
        'perm' => true,
        'items' => [
            ['label' => __('nav.schedule'), 'path' => './schedule'],
            ['label' => __('nav.sua'), 'path' => './sua'],
            ['label' => __('nav.crossings'), 'path' => './airspace-elements'],
        ]
    ],
    'swim' => [
        'label' => __('nav.swim'),
        'items' => [
            ['label' => __('nav.overview'), 'path' => './swim'],
            ['label' => __('nav.apiKeys'), 'path' => './swim-keys'],
            ['label' => __('nav.apiDocs'), 'path' => './docs/swim/', 'external' => true],
            ['label' => __('nav.technicalDocs'), 'path' => './swim-docs'],
        ]
    ],
    'about' => [
        'label' => __('nav.about'),
        'items' => [
            ['label' => __('nav.infrastructure'), 'path' => './transparency'],
            ['label' => __('nav.privacyPolicy'), 'path' => './privacy'],
        ]
    ],
];
```

Also replace hardcoded strings in the HTML portions of nav.php:
- Line 237: `Login` → `<?= __('nav.login') ?>`
- Line 249: `Menu` → `<?= __('nav.menu') ?>`
- Line 292-294: `Logout (...)` → `<?= __('nav.logout') ?> (<?= $_SESSION['VATSIM_FIRST_NAME'] ?>)`
- Line 298: `Login` → `<?= __('nav.login') ?>`

**Step 4: Update `load/footer.php` to use `__()`**

Replace the hardcoded text in `load/footer.php` (lines 79-88):

```php
<p class="font-size-sm text-center"><span class="text-light opacity-50"><?= __('footer.copyright', ['year' => date('Y')]) ?></span></p>
<p class="font-size-sm text-center"><span class="text-light opacity-50"><?= __('footer.disclaimer') ?></span></p>

<p class="font-size-sm text-center mb-n2">
    <a href="<?php echo "https://" . SITE_DOMAIN; ?>/privacy" target="_blank" class="text-light opacity-50"><?= __('footer.privacyPolicy') ?></a>
    &nbsp; <i class="fas fa-angle-right text-light opacity-50"></i>&nbsp;
    <a href="https://vatsim.net/" target="_blank" class="text-light opacity-50">VATSIM</a>
    &nbsp; <i class="fas fa-angle-right text-light opacity-50"></i>&nbsp;
    <a href="https://vatusa.net/" target="_blank" class="text-light opacity-50">VATUSA</a>
</p>
```

Note: "VATSIM" and "VATUSA" are proper nouns/brand names — they do NOT get translated.

**Step 5: Verify**

1. Load the site normally — navigation should appear identical to before
2. Load with `?locale=fr-CA` — navigation labels should appear in French
3. Check the mobile offcanvas menu uses the same translated labels (it reads from `$nav_config` too)

**Step 6: Commit**

```bash
git add load/nav.php load/footer.php assets/locales/en-US.json assets/locales/fr-CA.json
git commit -m "feat(i18n): internationalize navigation menu and footer"
```

---

### Task 4: Internationalize index.php (Home Page)

**Files:**
- Modify: `index.php`
- Modify: `assets/locales/en-US.json` (add `home.*` keys)
- Modify: `assets/locales/fr-CA.json` (add French translations)

**Step 1: Add `home.*` keys to `en-US.json`**

```json
"home": {
    "welcome": "Welcome to vATCSCC's",
    "pertiPlanSite": "PERTI Planning Site",
    "searchPlans": "Search for Plans",
    "pertiProcess": "The PERTI Process",
    "plan": "Plan",
    "execute": "Execute",
    "review": "Review",
    "train": "Train",
    "improve": "Improve",
    "pertiPlans": "PERTI Plans",
    "plansDescription": "Below you will find all available PERTI Plans for viewing and review prior or after the operational execution of an event.",
    "createPlan": "Create Plan",
    "createPertiPlan": "Create PERTI Plan",
    "editPertiPlan": "Edit PERTI Plan",
    "eventName": "Event Name",
    "startDateTime": "Start Date / Time (Zulu)",
    "endDateTime": "End Date / Time (Zulu)",
    "tmuOpLevel": "TMU OpLevel",
    "hotline": "Hotline",
    "eventBannerUrl": "Event Banner URL",
    "create": "Create",
    "saveChanges": "Save Changes",
    "table": {
        "eventName": "Event Name",
        "startDate": "Start Date",
        "startTime": "Start Time",
        "endDate": "End Date",
        "endTime": "End Time",
        "tmuOpLevel": "TMU OpLevel",
        "lastUpdated": "Last Updated"
    },
    "opLevel1": "OpLevel 1 - Steady State",
    "opLevel2": "OpLevel 2 - Localized Impact",
    "opLevel3": "OpLevel 3 - Regional Impact",
    "opLevel4": "OpLevel 4 - NAS-Wide Impact",
    "success": {
        "created": "Successfully Created",
        "createdText": "You have successfully created a PERTI Plan.",
        "deleted": "Successfully Deleted",
        "deletedText": "You have successfully deleted the selected PERTI Plan.",
        "updated": "Successfully Updated",
        "updatedText": "You have successfully edited the selected PERTI Plan."
    },
    "error": {
        "createFailed": "Not Created",
        "createFailedText": "There was an error in creating this PERTI Plan.",
        "deleteFailed": "Not Deleted",
        "deleteFailedText": "There was an error in deleting this PERTI Plan.",
        "updateFailed": "Not Edited",
        "updateFailedText": "There was an error in editing this PERTI Plan."
    }
}
```

**Step 2: Add French translations to `fr-CA.json`**

```json
"home": {
    "welcome": "Bienvenue sur le site",
    "pertiPlanSite": "de planification PERTI de vATCSCC",
    "searchPlans": "Rechercher des plans",
    "pertiProcess": "Le processus PERTI",
    "plan": "Planifier",
    "execute": "Ex\u00e9cuter",
    "review": "\u00c9valuer",
    "train": "Former",
    "improve": "Am\u00e9liorer",
    "pertiPlans": "Plans PERTI",
    "plansDescription": "Vous trouverez ci-dessous tous les plans PERTI disponibles pour consultation et r\u00e9vision avant ou apr\u00e8s l'ex\u00e9cution op\u00e9rationnelle d'un \u00e9v\u00e9nement.",
    "createPlan": "Cr\u00e9er un plan",
    "createPertiPlan": "Cr\u00e9er un plan PERTI",
    "editPertiPlan": "Modifier un plan PERTI",
    "eventName": "Nom de l'\u00e9v\u00e9nement",
    "startDateTime": "Date / Heure de d\u00e9but (Zulu)",
    "endDateTime": "Date / Heure de fin (Zulu)",
    "tmuOpLevel": "Niveau op\u00e9rationnel TMU",
    "hotline": "Ligne directe",
    "eventBannerUrl": "URL de la banni\u00e8re",
    "create": "Cr\u00e9er",
    "saveChanges": "Enregistrer les modifications",
    "table": {
        "eventName": "Nom de l'\u00e9v\u00e9nement",
        "startDate": "Date de d\u00e9but",
        "startTime": "Heure de d\u00e9but",
        "endDate": "Date de fin",
        "endTime": "Heure de fin",
        "tmuOpLevel": "Niveau op\u00e9rationnel TMU",
        "lastUpdated": "Derni\u00e8re mise \u00e0 jour"
    },
    "opLevel1": "Niveau 1 - \u00c9tat stable",
    "opLevel2": "Niveau 2 - Impact localis\u00e9",
    "opLevel3": "Niveau 3 - Impact r\u00e9gional",
    "opLevel4": "Niveau 4 - Impact NAS",
    "success": {
        "created": "Cr\u00e9ation r\u00e9ussie",
        "createdText": "Vous avez cr\u00e9\u00e9 un plan PERTI avec succ\u00e8s.",
        "deleted": "Suppression r\u00e9ussie",
        "deletedText": "Vous avez supprim\u00e9 le plan PERTI s\u00e9lectionn\u00e9 avec succ\u00e8s.",
        "updated": "Mise \u00e0 jour r\u00e9ussie",
        "updatedText": "Vous avez modifi\u00e9 le plan PERTI s\u00e9lectionn\u00e9 avec succ\u00e8s."
    },
    "error": {
        "createFailed": "Non cr\u00e9\u00e9",
        "createFailedText": "Une erreur est survenue lors de la cr\u00e9ation de ce plan PERTI.",
        "deleteFailed": "Non supprim\u00e9",
        "deleteFailedText": "Une erreur est survenue lors de la suppression de ce plan PERTI.",
        "updateFailed": "Non modifi\u00e9",
        "updateFailedText": "Une erreur est survenue lors de la modification de ce plan PERTI."
    }
}
```

**Step 3: Replace hardcoded strings in `index.php`**

Replace all hardcoded English text in `index.php` with `__()` calls for PHP-rendered content and `PERTII18n.t()` calls for JS-rendered content (Swal dialogs). The key areas:

- Hero section (lines 59-64): Welcome text, search link
- PERTI process icons (lines 73-115): Plan, Execute, Review, Train, Improve
- Plans table header (lines 123-139)
- Create Plan modal (lines 152-262): All labels, option values, buttons
- Edit Plan modal (lines 264-376): Same labels
- Swal messages in inline JS (lines 397-551): Use `PERTII18n.t()` for all toast/dialog text

For the inline `<script>` Swal calls, the pattern is:
```javascript
// Before:
title: 'Successfully Deleted',
text: 'You have successfully deleted the selected PERTI Plan.',

// After:
title: PERTII18n.t('home.success.deleted'),
text: PERTII18n.t('home.success.deletedText'),
```

**Step 4: Verify**

1. Load `index.php` normally — should appear identical
2. Load with `?locale=fr-CA` — all text should be French
3. Create and delete a plan — Swal messages should be French

**Step 5: Commit**

```bash
git add index.php assets/locales/en-US.json assets/locales/fr-CA.json
git commit -m "feat(i18n): internationalize home page (index.php)"
```

---

### Task 5: Internationalize plan.php (PERTI Plan Detail Page)

**Files:**
- Modify: `plan.php`
- Modify: `assets/js/plan.js`
- Modify: `assets/locales/en-US.json` (expand `plan.*` section)
- Modify: `assets/locales/fr-CA.json`

This is the most complex page. It has ~15 sections (terminal/enroute staffing, constraints, initiatives, configs, forecast, historical, group flights, goals, DCC staffing). The `plan.*` section in en-US.json already has 120 keys.

**Step 1: Audit existing `plan.*` keys in en-US.json**

Read the existing `plan` section and identify what's already covered vs what's missing. The existing keys likely cover JS-generated content. PHP-rendered section headings, tab labels, and form labels may be missing.

**Step 2: Add missing keys to `en-US.json`**

Scan `plan.php` for every hardcoded English string. Add keys like:
- `plan.title` = "PERTI Plan"
- `plan.tabs.configs` = "Airport Configs"
- `plan.tabs.terminalStaffing` = "Terminal Staffing"
- `plan.tabs.enrouteStaffing` = "Enroute Staffing"
- `plan.tabs.constraints` = "Constraints"
- `plan.tabs.initiatives` = "Initiatives"
- `plan.tabs.forecast` = "Forecast"
- `plan.tabs.historical` = "Historical"
- `plan.tabs.groupFlights` = "Group Flights"
- `plan.tabs.goals` = "Op Goals"
- `plan.tabs.dccStaffing` = "DCC Staffing"
- All table headers, form labels, and modal titles

**Step 3: Add French translations to `fr-CA.json`**

Translate all new plan keys to French.

**Step 4: Replace hardcoded strings in `plan.php`**

Use `<?= __('plan.tabs.configs') ?>` for PHP-rendered HTML. For JS in plan.js that already uses `PERTII18n.t()`, verify the keys exist; for hardcoded JS strings, replace with `PERTII18n.t()`.

**Step 5: Verify and commit**

```bash
git add plan.php assets/js/plan.js assets/locales/en-US.json assets/locales/fr-CA.json
git commit -m "feat(i18n): internationalize plan.php detail page"
```

---

### Task 6: Internationalize gdt.php + nod.php

**Files:**
- Modify: `gdt.php`, `nod.php`
- Modify: `assets/js/gdt.js`, `assets/js/nod.js`
- Modify: `assets/locales/en-US.json` (expand `gdt.*` and `nod.*`)
- Modify: `assets/locales/fr-CA.json`

Both `gdt.*` (293 keys) and `nod.*` (184 keys) already have extensive JS key coverage in en-US.json. These tasks focus on:
1. PHP-rendered headings and structural HTML in the .php files
2. Completing any remaining hardcoded strings in the .js files
3. Adding all French translations

**Step 1: Audit gdt.php and nod.php for hardcoded strings**

Read both files, identify PHP-rendered English text (headings, labels, table headers rendered server-side).

**Step 2: Add missing keys and French translations**

Add new keys to en-US.json as needed, then translate to fr-CA.json.

**Step 3: Replace hardcoded strings**

In PHP files: `<?= __('gdt.pageTitle') ?>` etc.
In JS files: Replace any remaining hardcoded strings with `PERTII18n.t()`.

**Step 4: Verify both pages with `?locale=fr-CA` and commit**

```bash
git add gdt.php nod.php assets/js/gdt.js assets/js/nod.js assets/locales/en-US.json assets/locales/fr-CA.json
git commit -m "feat(i18n): internationalize GDT and NOD pages"
```

---

### Task 7: Internationalize demand.php + splits.php

**Files:**
- Modify: `demand.php`, `splits.php`
- Modify: `assets/js/demand.js`, `assets/js/splits.js`
- Modify: `assets/locales/en-US.json` (expand `demand.*` 158 keys, `splits.*` 148 keys)
- Modify: `assets/locales/fr-CA.json`

Same pattern as Task 6. Both have significant JS key coverage already.

**Step 1-4:** Same audit → add keys → replace strings → verify → commit pattern.

```bash
git commit -m "feat(i18n): internationalize Demand and Splits pages"
```

---

### Task 8: Internationalize tmi-publish.php + schedule.php + review.php + sheet.php

**Files:**
- Modify: `tmi-publish.php`, `schedule.php`, `review.php`, `sheet.php`
- Modify: `assets/js/tmi-publish.js`, `assets/js/schedule.js`, `assets/js/review.js`, `assets/js/sheet.js`
- Modify: `assets/locales/en-US.json`
- Modify: `assets/locales/fr-CA.json`

`tmi-publish.js` has the most extensive i18n coverage already (93 `tmiPublish.*` keys). The others have moderate coverage.

**Step 1-4:** Same pattern. These are smaller pages, so they batch well together.

```bash
git commit -m "feat(i18n): internationalize TMI Publish, Schedule, Review, and Sheet pages"
```

---

### Task 9: Internationalize jatoc.php + sua.php + route.php

**Files:**
- Modify: `jatoc.php`, `sua.php`, `route.php`
- Modify: `assets/js/jatoc.js`, `assets/js/sua.js`, `assets/js/route-maplibre.js`
- Modify: `assets/locales/en-US.json`
- Modify: `assets/locales/fr-CA.json`

`jatoc.*` (81 keys) and `sua.*` (34 keys) have JS coverage. `route-maplibre.js` has no i18n yet.

```bash
git commit -m "feat(i18n): internationalize JATOC, SUA, and Route pages"
```

---

### Task 10: Internationalize SWIM pages + status.php + remaining pages

**Files:**
- Modify: `swim.php`, `swim-keys.php`, `swim-docs.php`, `swim-doc.php`, `status.php`
- Modify: `airport_config.php`, `event-aar.php`, `airspace-elements.php`
- Modify: `transparency.php`, `privacy.php`, `fmds-comparison.php`, `simulator.php`
- Modify: `assets/locales/en-US.json` (add `swim.*`, `status.*`, `airport.*`, etc.)
- Modify: `assets/locales/fr-CA.json`

These are simpler pages with less dynamic content. Many are mostly static text.

**Step 1:** Scan each file for hardcoded strings. Group by similarity:
- SWIM pages share terminology (API, keys, documentation)
- status.php is system status
- transparency.php and privacy.php are long-form text pages

**Step 2:** Add keys and translations.

**Step 3:** For long-form text pages (privacy, transparency), use paragraph-level keys rather than sentence-level:
```json
"privacy": {
    "title": "Privacy Policy",
    "intro": "This privacy policy outlines...",
    "dataCollection": "Data Collection",
    "dataCollectionText": "We collect..."
}
```

**Step 4:** Verify and commit.

```bash
git commit -m "feat(i18n): internationalize SWIM, status, and remaining pages"
```

---

### Task 11: Complete JS File i18n Migration

**Files:**
- Modify: `assets/js/advisory-builder.js`
- Modify: `assets/js/advisory-config.js`
- Modify: `assets/js/tmi-active-display.js`
- Modify: `assets/js/tmi-gdp.js`
- Modify: `assets/js/public-routes.js`
- Modify: `assets/js/playbook-cdr-search.js`
- Modify: `assets/js/airspace_display.js`
- Modify: `assets/js/nod-demand-layer.js`
- Modify: `assets/js/weather_radar.js`
- Modify: `assets/js/weather_hazards.js`
- Modify: `assets/js/initiative_timeline.js`
- Modify: `assets/js/tmr_report.js`
- Modify: `assets/js/tmi_compliance.js`
- Modify: `assets/locales/en-US.json`
- Modify: `assets/locales/fr-CA.json`

These JS files either have zero i18n or partial coverage. For each file:

1. Search for hardcoded English strings in `.text()`, `.html()`, `.val()`, `.attr('title',`, `.attr('placeholder',`, Swal calls, template literals, and string concatenation
2. Replace with `PERTII18n.t('section.key')`
3. Add corresponding keys to en-US.json and fr-CA.json

**Important:** `tmi_compliance.js` is ~280KB — only replace user-facing display strings, not internal logging or data processing strings.

```bash
git commit -m "feat(i18n): complete JS file i18n migration for remaining modules"
```

---

### Task 12: en-CA.json Canadian English Delta

**Files:**
- Modify: `assets/locales/en-CA.json`

**Step 1: Review all en-US.json keys for Canadian English differences**

Canadian English differences to look for:
- "Cancelled" (same spelling, already in en-CA)
- "Co-ordinating" (already in en-CA)
- "Programme" vs "Program" (GDP → "Ground Delay Programme")
- "Colour" vs "Color"
- "Centre" vs "Center"
- "Licence" vs "License"
- "Analyse" vs "Analyze"
- "Defence" vs "Defense"
- "Catalogue" vs "Catalog"
- Regional names (already done: "Western Canada", "Atlantic Canada", etc.)

**Step 2: Add any new delta entries**

Only add entries where en-CA spelling differs from en-US. Most keys are identical and need no entry.

**Step 3: Commit**

```bash
git add assets/locales/en-CA.json
git commit -m "feat(i18n): expand en-CA.json Canadian English deltas"
```

---

### Task 13: Verify Complete fr-CA.json Coverage

**Files:**
- Modify: `assets/locales/fr-CA.json`

**Step 1: Build a coverage report**

Write a quick script (or manually compare) to find all keys in en-US.json that are NOT in fr-CA.json:

```bash
node -e "
const en = require('./assets/locales/en-US.json');
const fr = require('./assets/locales/fr-CA.json');
function flatten(obj, prefix='') {
  let result = {};
  for (let k in obj) {
    let key = prefix ? prefix+'.'+k : k;
    if (typeof obj[k] === 'object' && obj[k] !== null) {
      Object.assign(result, flatten(obj[k], key));
    } else {
      result[key] = obj[k];
    }
  }
  return result;
}
const enFlat = flatten(en), frFlat = flatten(fr);
const missing = Object.keys(enFlat).filter(k => !(k in frFlat));
console.log('Missing:', missing.length, 'keys');
missing.forEach(k => console.log(' ', k));
"
```

**Step 2: Add any missing translations**

If the per-task translations were done correctly in Tasks 3-11, this should show zero missing keys. If any remain, add them now.

**Step 3: Validate JSON**

```bash
node -e "JSON.parse(require('fs').readFileSync('./assets/locales/fr-CA.json','utf8')); console.log('Valid JSON')"
```

**Step 4: Commit if changes were needed**

```bash
git add assets/locales/fr-CA.json
git commit -m "feat(i18n): verify and complete fr-CA.json coverage"
```

---

### Task 14: Integration Testing & Polish

**Step 1: Test all pages with en-US locale**

Visit every page and confirm nothing is broken (no `__()` raw output, no missing keys showing as key paths):
- index.php, plan.php, gdt.php, nod.php, demand.php, splits.php
- tmi-publish.php, schedule.php, review.php, sheet.php
- jatoc.php, sua.php, route.php, swim.php, swim-keys.php, status.php
- transparency.php, privacy.php

**Step 2: Test all pages with fr-CA locale**

Switch to `?locale=fr-CA` and verify:
- Navigation is in French
- Footer is in French
- Page content is in French
- Modals and dialogs are in French
- No untranslated English strings remain (check for keys showing as `section.key.name` — that means the key is missing)

**Step 3: Test locale switching**

1. Click FR button in VATCAN navbar → page reloads in French
2. Click EN button → page reloads in English
3. Navigate between pages → locale persists
4. Open a new browser tab → locale persists (via localStorage)

**Step 4: Test org switching with locale**

1. Switch to VATCAN org → FR button appears
2. Switch locale to fr-CA
3. Switch to DCC org → locale should reset to en-US (DCC doesn't support fr-CA)
4. Switch back to VATCAN → fr-CA should be remembered

**Step 5: Fix any issues found, commit**

```bash
git commit -m "fix(i18n): integration testing fixes"
```

---

## Summary

| Task | Scope | Est. New Keys |
|------|-------|---------------|
| 1. PHP i18n module | `load/i18n.php` + `connect.php` | 0 |
| 2. Locale sync endpoint | `api/data/locale.php` + nav.php JS | 0 |
| 3. Nav + Footer | `nav.php`, `footer.php` | ~35 |
| 4. Home page | `index.php` | ~45 |
| 5. Plan detail | `plan.php`, `plan.js` | ~40 |
| 6. GDT + NOD | `gdt.php`, `nod.php`, JS | ~30 |
| 7. Demand + Splits | `demand.php`, `splits.php`, JS | ~25 |
| 8. TMI + Schedule + Review + Sheet | 4 PHP + 4 JS files | ~40 |
| 9. JATOC + SUA + Route | 3 PHP + 3 JS files | ~30 |
| 10. SWIM + remaining pages | ~12 PHP files | ~80 |
| 11. JS file completion | ~13 JS files | ~50 |
| 12. en-CA.json delta | en-CA.json | ~15 |
| 13. fr-CA.json verification | fr-CA.json | (fill gaps) |
| 14. Integration testing | All pages | 0 |
