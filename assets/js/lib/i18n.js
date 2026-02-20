/**
 * PERTI Internationalization (i18n) Module
 *
 * Centralized translation and localization support.
 * Follows the same IIFE pattern as PERTILogger and PERTIDateTime.
 *
 * Usage:
 *   PERTII18n.t('dialog.confirm.title')
 *   PERTII18n.t('error.loadFailed', { resource: 'flights' })
 *   PERTII18n.tp('flight', 5) // "5 flights"
 *
 * @module lib/i18n
 * @version 1.0.0
 */

const PERTII18n = (function() {
    'use strict';

    // Current locale
    let currentLocale = 'en-US';

    // Loaded translation strings (flat key-value map)
    let strings = {};

    // Fallback strings (loaded initially, used when key not found)
    let fallbackStrings = {};

    /**
     * Set the current locale
     * @param {string} locale - Locale code (e.g., 'en-US', 'en-GB')
     */
    function setLocale(locale) {
        currentLocale = locale;
    }

    /**
     * Get the current locale
     * @returns {string}
     */
    function getLocale() {
        return currentLocale;
    }

    /**
     * Load translation strings
     * @param {Object} localeStrings - Flat or nested object of translations
     * @param {boolean} [asFallback=false] - If true, load as fallback strings
     */
    function loadStrings(localeStrings, asFallback = false) {
        const flattened = flattenObject(localeStrings);
        if (asFallback) {
            fallbackStrings = { ...fallbackStrings, ...flattened };
        } else {
            strings = { ...strings, ...flattened };
        }
    }

    /**
     * Flatten nested object to dot-notation keys
     * { dialog: { title: 'Hello' } } -> { 'dialog.title': 'Hello' }
     * @param {Object} obj
     * @param {string} [prefix='']
     * @returns {Object}
     */
    function flattenObject(obj, prefix = '') {
        const result = {};
        for (const key in obj) {
            if (Object.prototype.hasOwnProperty.call(obj, key)) {
                const newKey = prefix ? `${prefix}.${key}` : key;
                if (typeof obj[key] === 'object' && obj[key] !== null && !Array.isArray(obj[key])) {
                    Object.assign(result, flattenObject(obj[key], newKey));
                } else {
                    result[newKey] = obj[key];
                }
            }
        }
        return result;
    }

    /**
     * Translate a key with optional parameter interpolation
     * @param {string} key - Translation key (e.g., 'dialog.title')
     * @param {Object} [params={}] - Parameters to interpolate
     * @returns {string} Translated string or key if not found
     *
     * @example
     * t('error.loadFailed', { resource: 'flights' })
     * // With string "Failed to load {resource}" -> "Failed to load flights"
     */
    function t(key, params = {}) {
        // Look up in current strings, then fallback, then return key
        let str = strings[key] ?? fallbackStrings[key] ?? key;

        // Warn about missing keys in development (helps catch typos)
        if (str === key && key && key.includes('.')) {
            console.warn('[i18n] Missing translation key:', key);
        }

        // Interpolate parameters: {param} -> value
        if (params && typeof params === 'object') {
            Object.keys(params).forEach(param => {
                const regex = new RegExp(`\\{${param}\\}`, 'g');
                str = str.replace(regex, String(params[param]));
            });
        }

        return str;
    }

    /**
     * Translate with pluralization
     * @param {string} key - Base key (will look for key.one and key.other)
     * @param {number} count - Count for pluralization
     * @param {Object} [params={}] - Additional parameters
     * @returns {string}
     *
     * @example
     * // With strings: { "flight.one": "{count} flight", "flight.other": "{count} flights" }
     * tp('flight', 1) // "1 flight"
     * tp('flight', 5) // "5 flights"
     */
    function tp(key, count, params = {}) {
        const pluralKey = count === 1 ? `${key}.one` : `${key}.other`;
        return t(pluralKey, { count, ...params });
    }

    /**
     * Check if a translation key exists
     * @param {string} key
     * @returns {boolean}
     */
    function has(key) {
        return key in strings || key in fallbackStrings;
    }

    /**
     * Get all loaded keys (for debugging)
     * @returns {string[]}
     */
    function getKeys() {
        return [...new Set([...Object.keys(strings), ...Object.keys(fallbackStrings)])];
    }

    /**
     * Format a number according to locale
     * @param {number} num
     * @param {Object} [options={}] - Intl.NumberFormat options
     * @returns {string}
     */
    function formatNumber(num, options = {}) {
        try {
            return new Intl.NumberFormat(currentLocale, options).format(num);
        } catch {
            return String(num);
        }
    }

    /**
     * Format a date according to locale (non-aviation use only)
     * Note: Aviation times should use PERTIDateTime for UTC/Zulu formats
     * @param {Date} date
     * @param {Object} [options={}] - Intl.DateTimeFormat options
     * @returns {string}
     */
    function formatDate(date, options = {}) {
        if (!date || !(date instanceof Date) || isNaN(date)) {
            return '';
        }
        try {
            return new Intl.DateTimeFormat(currentLocale, options).format(date);
        } catch {
            return date.toISOString();
        }
    }

    /**
     * Register strings from inline object (convenience for page-specific strings)
     * @param {Object} pageStrings
     */
    function register(pageStrings) {
        loadStrings(pageStrings);
    }

    // Public API
    return {
        setLocale,
        getLocale,
        loadStrings,
        register,
        t,
        tp,
        has,
        getKeys,
        formatNumber,
        formatDate,
    };
})();

// Export for ES modules if available
if (typeof module !== 'undefined' && module.exports) {
    module.exports = PERTII18n;
}
