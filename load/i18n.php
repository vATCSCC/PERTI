<?php
/**
 * PERTI PHP Internationalization (i18n) Module
 *
 * Server-side translation support using the same JSON locale files
 * as the JavaScript PERTII18n module. Provides __(), __p(), and __has()
 * global convenience functions.
 *
 * Usage:
 *   __('common.save')                              // "Save"
 *   __('error.loadFailed', ['resource' => 'flights']) // "Failed to load flights"
 *   __p('flight', 3)                               // "3 flights"
 *   __has('common.save')                           // true
 *
 * Locale detection priority:
 *   1. Explicit parameter to init()
 *   2. $_SESSION['PERTI_LOCALE']
 *   3. 'en-US' fallback
 *
 * @version 1.0.0
 */

if (defined('I18N_PHP_LOADED')) {
    return;
}
define('I18N_PHP_LOADED', true);

/** Supported locale codes */
const SUPPORTED_LOCALES = ['en-US', 'en-CA', 'fr-CA'];

/**
 * PHP i18n translation class.
 *
 * Mirrors the JavaScript PERTII18n module: same JSON files, same
 * dot-notation keys, same fallback logic, same {param} interpolation.
 */
class PERTII18nPHP
{
    /** @var bool Whether init() has been called */
    private static bool $initialized = false;

    /** @var string Active locale code */
    private static string $locale = 'en-US';

    /** @var array<string, string> Primary translation strings (flat dot-notation keys) */
    private static array $strings = [];

    /** @var array<string, string> Fallback (en-US) strings */
    private static array $fallbackStrings = [];

    /**
     * Initialize the i18n system.
     *
     * Loads en-US as fallback, then the active locale on top.
     * Resolves {commandCenter} placeholders from org context.
     *
     * @param string|null $locale  Force a specific locale (bypasses session)
     */
    public static function init(?string $locale = null): void
    {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;

        self::$locale = self::detectLocale($locale);

        $basePath = self::localesBasePath();

        // Always load en-US as fallback
        $fallbackFile = $basePath . '/en-US.json';
        if (is_file($fallbackFile)) {
            $data = json_decode(file_get_contents($fallbackFile), true);
            if (is_array($data)) {
                self::$fallbackStrings = self::flatten($data);
            }
        }

        // Load active locale as primary strings
        if (self::$locale !== 'en-US') {
            $localeFile = $basePath . '/' . self::$locale . '.json';
            if (is_file($localeFile)) {
                $data = json_decode(file_get_contents($localeFile), true);
                if (is_array($data)) {
                    self::$strings = self::flatten($data);
                }
            }
        }

        self::resolveOrgPlaceholders();
    }

    /**
     * Translate a key with optional parameter interpolation.
     *
     * @param string               $key    Dot-notation key (e.g. 'common.save')
     * @param array<string, mixed> $params Interpolation values for {param} placeholders
     * @return string Translated string, or the raw key when not found
     */
    public static function t(string $key, array $params = []): string
    {
        self::ensureInit();

        $str = self::$strings[$key]
            ?? self::$fallbackStrings[$key]
            ?? $key;

        if ($params) {
            foreach ($params as $name => $value) {
                $str = str_replace('{' . $name . '}', (string)$value, $str);
            }
        }

        return $str;
    }

    /**
     * Translate with pluralization.
     *
     * Looks up "$key.one" when $count === 1, "$key.other" otherwise,
     * and automatically injects {count} into params.
     *
     * @param string               $key    Base key (e.g. 'flight')
     * @param int                  $count  Count for plural selection
     * @param array<string, mixed> $params Additional interpolation values
     * @return string
     */
    public static function tp(string $key, int $count, array $params = []): string
    {
        $pluralKey = $count === 1 ? $key . '.one' : $key . '.other';
        return self::t($pluralKey, ['count' => $count] + $params);
    }

    /**
     * Check whether a translation key exists.
     *
     * @param string $key Dot-notation key
     * @return bool
     */
    public static function has(string $key): bool
    {
        self::ensureInit();
        return isset(self::$strings[$key]) || isset(self::$fallbackStrings[$key]);
    }

    /**
     * Get the active locale code.
     *
     * @return string e.g. 'en-US'
     */
    public static function getLocale(): string
    {
        self::ensureInit();
        return self::$locale;
    }

    /**
     * Reset all state. Primarily useful for testing.
     */
    public static function reset(): void
    {
        self::$initialized = false;
        self::$locale = 'en-US';
        self::$strings = [];
        self::$fallbackStrings = [];
    }

    // ------------------------------------------------------------------
    // Internal helpers
    // ------------------------------------------------------------------

    /**
     * Trigger lazy initialization on first call.
     */
    private static function ensureInit(): void
    {
        if (!self::$initialized) {
            self::init();
        }
    }

    /**
     * Detect locale from explicit param, session, or default.
     */
    private static function detectLocale(?string $explicit): string
    {
        if ($explicit !== null && in_array($explicit, SUPPORTED_LOCALES, true)) {
            return $explicit;
        }

        if (isset($_SESSION['PERTI_LOCALE']) && in_array($_SESSION['PERTI_LOCALE'], SUPPORTED_LOCALES, true)) {
            return $_SESSION['PERTI_LOCALE'];
        }

        return 'en-US';
    }

    /**
     * Resolve the filesystem path to the locales directory.
     *
     * Works whether the web root is one level up from /load/ or
     * the current working directory.
     */
    private static function localesBasePath(): string
    {
        return dirname(__DIR__) . '/assets/locales';
    }

    /**
     * Flatten a nested associative array into dot-notation keys.
     *
     * Example: ['dialog' => ['title' => 'Hello']] becomes ['dialog.title' => 'Hello']
     *
     * @param array  $data
     * @param string $prefix
     * @return array<string, string>
     */
    private static function flatten(array $data, string $prefix = ''): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            $fullKey = $prefix === '' ? (string)$key : $prefix . '.' . $key;

            if (is_array($value)) {
                $result += self::flatten($value, $fullKey);
            } else {
                $result[$fullKey] = (string)$value;
            }
        }

        return $result;
    }

    /**
     * Replace {commandCenter} in all loaded strings using org context.
     *
     * Falls back to 'DCC' when the database connection or org_context
     * module is unavailable (e.g. PERTI_MYSQL_ONLY before connect, CLI).
     */
    private static function resolveOrgPlaceholders(): void
    {
        $displayName = self::resolveOrgDisplayName();

        foreach (self::$fallbackStrings as $key => $value) {
            if (str_contains($value, '{commandCenter}')) {
                self::$fallbackStrings[$key] = str_replace('{commandCenter}', $displayName, $value);
            }
        }

        foreach (self::$strings as $key => $value) {
            if (str_contains($value, '{commandCenter}')) {
                self::$strings[$key] = str_replace('{commandCenter}', $displayName, $value);
            }
        }
    }

    /**
     * Determine the org display name for placeholder resolution.
     *
     * Priority:
     *   1. Session cache (ORG_INFO_*)
     *   2. get_org_info() if available and $conn_sqli is connected
     *   3. 'DCC' as safe fallback
     */
    private static function resolveOrgDisplayName(): string
    {
        // Try session cache first (set by get_org_info in org_context.php)
        $orgCode = $_SESSION['ORG_CODE'] ?? 'vatcscc';
        $cacheKey = 'ORG_INFO_' . $orgCode;

        if (!empty($_SESSION[$cacheKey]['display_name'])) {
            return $_SESSION[$cacheKey]['display_name'];
        }

        // Try get_org_info() with the global MySQL connection
        if (function_exists('get_org_info')) {
            global $conn_sqli;
            if ($conn_sqli) {
                $info = get_org_info($conn_sqli);
                if (!empty($info['display_name'])) {
                    return $info['display_name'];
                }
            }
        }

        return 'DCC';
    }
}

// -----------------------------------------------------------------------
// Global convenience functions
// -----------------------------------------------------------------------

if (!function_exists('__')) {
    /**
     * Translate a key.
     *
     * @param string               $key    Dot-notation i18n key
     * @param array<string, mixed> $params Interpolation parameters
     * @return string
     */
    function __(string $key, array $params = []): string
    {
        return PERTII18nPHP::t($key, $params);
    }
}

if (!function_exists('__p')) {
    /**
     * Translate a key with pluralization.
     *
     * @param string               $key    Base key
     * @param int                  $count  Count for plural selection
     * @param array<string, mixed> $params Additional interpolation parameters
     * @return string
     */
    function __p(string $key, int $count, array $params = []): string
    {
        return PERTII18nPHP::tp($key, $count, $params);
    }
}

if (!function_exists('__has')) {
    /**
     * Check whether a translation key exists.
     *
     * @param string $key Dot-notation i18n key
     * @return bool
     */
    function __has(string $key): bool
    {
        return PERTII18nPHP::has($key);
    }
}
