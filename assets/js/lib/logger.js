/**
 * PERTI Logging Utilities
 *
 * Conditional logging based on DEBUG mode.
 * Use instead of console.log() throughout codebase.
 *
 * Enable debug mode:
 *   - Set PERTI_DEBUG=true in window
 *   - Or add ?debug=1 to URL
 *   - Or set localStorage.PERTI_DEBUG = 'true'
 *
 * @module lib/logger
 * @version 1.0.0
 */

const PERTILogger = (function() {
    'use strict';

    // Check if debug mode is enabled
    function isDebugEnabled() {
        // Check window flag
        if (typeof window !== 'undefined' && window.PERTI_DEBUG === true) {
            return true;
        }
        // Check URL param
        if (typeof location !== 'undefined' && location.search.includes('debug=1')) {
            return true;
        }
        // Check localStorage
        if (typeof localStorage !== 'undefined') {
            try {
                return localStorage.getItem('PERTI_DEBUG') === 'true';
            } catch {
                // localStorage may be blocked
            }
        }
        return false;
    }

    // Cache debug state (recalculate on demand)
    let debugEnabled = null;

    function checkDebug() {
        if (debugEnabled === null) {
            debugEnabled = isDebugEnabled();
        }
        return debugEnabled;
    }

    /**
     * Reset debug cache (call if debug mode changes at runtime)
     */
    function resetDebugCache() {
        debugEnabled = null;
    }

    /**
     * Enable debug mode programmatically
     */
    function enableDebug() {
        if (typeof window !== 'undefined') {
            window.PERTI_DEBUG = true;
        }
        debugEnabled = true;
    }

    /**
     * Disable debug mode programmatically
     */
    function disableDebug() {
        if (typeof window !== 'undefined') {
            window.PERTI_DEBUG = false;
        }
        debugEnabled = false;
    }

    /**
     * Format a log prefix with timestamp
     * @param {string} level
     * @param {string} module
     * @returns {string}
     */
    function formatPrefix(level, module) {
        const timestamp = new Date().toISOString().slice(11, 23);
        const mod = module ? `[${module}]` : '';
        return `[${timestamp}] [${level}]${mod}`;
    }

    /**
     * Debug log - only outputs if DEBUG mode enabled
     * @param {string} module Module name for prefix
     * @param {...any} args Log arguments
     */
    function debug(module, ...args) {
        if (checkDebug()) {
            console.log(formatPrefix('DEBUG', module), ...args);
        }
    }

    /**
     * Info log - only outputs if DEBUG mode enabled
     * @param {string} module
     * @param {...any} args
     */
    function info(module, ...args) {
        if (checkDebug()) {
            console.info(formatPrefix('INFO', module), ...args);
        }
    }

    /**
     * Warning log - always outputs
     * @param {string} module
     * @param {...any} args
     */
    function warn(module, ...args) {
        console.warn(formatPrefix('WARN', module), ...args);
    }

    /**
     * Error log - always outputs, with stack trace
     * @param {string} module
     * @param {...any} args
     */
    function error(module, ...args) {
        console.error(formatPrefix('ERROR', module), ...args);
    }

    /**
     * Table log - only outputs if DEBUG mode enabled
     * @param {string} module
     * @param {any} data
     */
    function table(module, data) {
        if (checkDebug()) {
            console.log(formatPrefix('TABLE', module));
            console.table(data);
        }
    }

    /**
     * Group logs - only starts group if DEBUG mode enabled
     * @param {string} module
     * @param {string} label
     */
    function group(module, label) {
        if (checkDebug()) {
            console.group(formatPrefix('GROUP', module) + ' ' + label);
        }
    }

    /**
     * End log group
     */
    function groupEnd() {
        if (checkDebug()) {
            console.groupEnd();
        }
    }

    /**
     * Time measurement start - only if DEBUG mode enabled
     * @param {string} label
     */
    function time(label) {
        if (checkDebug()) {
            console.time(label);
        }
    }

    /**
     * Time measurement end
     * @param {string} label
     */
    function timeEnd(label) {
        if (checkDebug()) {
            console.timeEnd(label);
        }
    }

    /**
     * Assert with custom message - always checked, only logs if failed
     * @param {boolean} condition
     * @param {string} module
     * @param {string} message
     */
    function assert(condition, module, message) {
        if (!condition) {
            console.error(formatPrefix('ASSERT FAILED', module), message);
        }
    }

    /**
     * Create a module-scoped logger
     * @param {string} moduleName
     * @returns {Object} Logger with bound module name
     *
     * @example
     * const log = PERTILogger.create('MyModule');
     * log.debug('Starting...');
     * log.error('Something broke');
     */
    function create(moduleName) {
        return {
            debug: (...args) => debug(moduleName, ...args),
            info: (...args) => info(moduleName, ...args),
            warn: (...args) => warn(moduleName, ...args),
            error: (...args) => error(moduleName, ...args),
            table: (data) => table(moduleName, data),
            group: (label) => group(moduleName, label),
            groupEnd: groupEnd,
            time: time,
            timeEnd: timeEnd,
            assert: (cond, msg) => assert(cond, moduleName, msg),
        };
    }

    // Public API
    return {
        debug,
        info,
        warn,
        error,
        table,
        group,
        groupEnd,
        time,
        timeEnd,
        assert,
        create,
        enableDebug,
        disableDebug,
        resetDebugCache,
        isDebugEnabled: checkDebug,
    };
})();

// Export for ES modules if available
if (typeof module !== 'undefined' && module.exports) {
    module.exports = PERTILogger;
}
