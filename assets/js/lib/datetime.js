/**
 * PERTI DateTime Utilities
 *
 * Centralized date/time handling - ALL times in UTC.
 * Use these functions instead of raw Date methods.
 *
 * @module lib/datetime
 * @version 1.0.0
 */

const PERTIDateTime = (function() {
    'use strict';

    /**
     * Get current UTC time as ISO string
     * @returns {string} ISO 8601 UTC string
     */
    function nowIso() {
        return new Date().toISOString();
    }

    /**
     * Get current UTC time formatted as HH:MM:SSZ
     * Use instead of: new Date().toISOString().substr(11, 8)
     * @returns {string}
     */
    function nowTimeZ() {
        return new Date().toISOString().slice(11, 19) + 'Z';
    }

    /**
     * Get current UTC time formatted as HH:MMZ
     * Use instead of: new Date().toISOString().substr(11, 5) + 'Z'
     * @returns {string}
     */
    function nowTimeShortZ() {
        return new Date().toISOString().slice(11, 16) + 'Z';
    }

    /**
     * Get current UTC date as YYYY-MM-DD
     * @returns {string}
     */
    function todayIso() {
        return new Date().toISOString().slice(0, 10);
    }

    /**
     * Format a Date object as HH:MM:SSZ
     * @param {Date} date
     * @returns {string}
     */
    function formatTimeZ(date) {
        if (!date || !(date instanceof Date) || isNaN(date)) {
            return '--:--:--Z';
        }
        return date.toISOString().slice(11, 19) + 'Z';
    }

    /**
     * Format a Date object as HH:MMZ
     * @param {Date} date
     * @returns {string}
     */
    function formatTimeShortZ(date) {
        if (!date || !(date instanceof Date) || isNaN(date)) {
            return '--:--Z';
        }
        return date.toISOString().slice(11, 16) + 'Z';
    }

    /**
     * Format a Date as YYYY-MM-DD HH:MMZ
     * Use instead of: d.toISOString().substr(0, 16).replace('T', ' ') + 'Z'
     * @param {Date} date
     * @returns {string}
     */
    function formatDateTimeZ(date) {
        if (!date || !(date instanceof Date) || isNaN(date)) {
            return '----/--/-- --:--Z';
        }
        return date.toISOString().slice(0, 16).replace('T', ' ') + 'Z';
    }

    /**
     * Format for NTML signature: YY/MM/DD HH:MM
     * @param {Date} date
     * @returns {string}
     */
    function formatSignature(date) {
        if (!date || !(date instanceof Date) || isNaN(date)) {
            date = new Date();
        }
        const y = String(date.getUTCFullYear()).slice(2, 4);
        const m = String(date.getUTCMonth() + 1).padStart(2, '0');
        const d = String(date.getUTCDate()).padStart(2, '0');
        const h = String(date.getUTCHours()).padStart(2, '0');
        const min = String(date.getUTCMinutes()).padStart(2, '0');
        return `${y}/${m}/${d} ${h}:${min}`;
    }

    /**
     * Format for program times: DD/HHMMZ
     * @param {Date} date
     * @returns {string}
     */
    function formatProgramTime(date) {
        if (!date || !(date instanceof Date) || isNaN(date)) {
            return '--/----Z';
        }
        const d = String(date.getUTCDate()).padStart(2, '0');
        const h = String(date.getUTCHours()).padStart(2, '0');
        const min = String(date.getUTCMinutes()).padStart(2, '0');
        return `${d}/${h}${min}Z`;
    }

    /**
     * Format for ADL times: HHMMZ
     * @param {Date} date
     * @returns {string}
     */
    function formatAdlTime(date) {
        if (!date || !(date instanceof Date) || isNaN(date)) {
            return '----Z';
        }
        const h = String(date.getUTCHours()).padStart(2, '0');
        const min = String(date.getUTCMinutes()).padStart(2, '0');
        return `${h}${min}Z`;
    }

    /**
     * Format for DDHHMM (no Z)
     * @param {Date} date
     * @returns {string}
     */
    function formatDDHHMM(date) {
        if (!date || !(date instanceof Date) || isNaN(date)) {
            return '------';
        }
        const d = String(date.getUTCDate()).padStart(2, '0');
        const h = String(date.getUTCHours()).padStart(2, '0');
        const min = String(date.getUTCMinutes()).padStart(2, '0');
        return `${d}${h}${min}`;
    }

    /**
     * Format for datetime-local input: YYYY-MM-DDTHH:MM
     * @param {Date} date
     * @returns {string}
     */
    function formatForInput(date) {
        if (!date || !(date instanceof Date) || isNaN(date)) {
            return '';
        }
        return date.toISOString().slice(0, 16);
    }

    /**
     * Format MM/DD/YYYY for display
     * @param {Date} date
     * @returns {string}
     */
    function formatMDY(date) {
        if (!date || !(date instanceof Date) || isNaN(date)) {
            return '--/--/----';
        }
        const m = String(date.getUTCMonth() + 1).padStart(2, '0');
        const d = String(date.getUTCDate()).padStart(2, '0');
        const y = date.getUTCFullYear();
        return `${m}/${d}/${y}`;
    }

    /**
     * Get 2-digit UTC year
     * @returns {string}
     */
    function yearShort() {
        return String(new Date().getUTCFullYear()).slice(2, 4);
    }

    /**
     * Parse a datetime string to Date object
     * @param {string} str
     * @returns {Date|null}
     */
    function parse(str) {
        if (!str) {return null;}
        const date = new Date(str);
        return isNaN(date) ? null : date;
    }

    /**
     * Parse DDHHMM format to Date (assumes current month)
     * @param {string} str Format: DDHHMM
     * @returns {Date|null}
     */
    function parseDDHHMM(str) {
        if (!str || str.length !== 6) {return null;}

        const day = parseInt(str.slice(0, 2), 10);
        const hours = parseInt(str.slice(2, 4), 10);
        const mins = parseInt(str.slice(4, 6), 10);

        if (isNaN(day) || isNaN(hours) || isNaN(mins)) {return null;}

        const now = new Date();
        const date = new Date(Date.UTC(
            now.getUTCFullYear(),
            now.getUTCMonth(),
            day,
            hours,
            mins,
        ));

        return isNaN(date) ? null : date;
    }

    /**
     * Calculate minutes between two dates
     * @param {Date} from
     * @param {Date} to
     * @returns {number}
     */
    function diffMinutes(from, to) {
        return Math.round((to.getTime() - from.getTime()) / 60000);
    }

    /**
     * Check if date is in the past (UTC)
     * @param {Date} date
     * @returns {boolean}
     */
    function isPast(date) {
        return date.getTime() < Date.now();
    }

    /**
     * Check if date is in the future (UTC)
     * @param {Date} date
     * @returns {boolean}
     */
    function isFuture(date) {
        return date.getTime() > Date.now();
    }

    /**
     * Add minutes to a date
     * @param {Date} date
     * @param {number} minutes
     * @returns {Date}
     */
    function addMinutes(date, minutes) {
        return new Date(date.getTime() + minutes * 60000);
    }

    // Public API
    return {
        nowIso,
        nowTimeZ,
        nowTimeShortZ,
        todayIso,
        formatTimeZ,
        formatTimeShortZ,
        formatDateTimeZ,
        formatSignature,
        formatProgramTime,
        formatAdlTime,
        formatDDHHMM,
        formatForInput,
        formatMDY,
        yearShort,
        parse,
        parseDDHHMM,
        diffMinutes,
        isPast,
        isFuture,
        addMinutes,
    };
})();

// Export for ES modules if available
if (typeof module !== 'undefined' && module.exports) {
    module.exports = PERTIDateTime;
}
