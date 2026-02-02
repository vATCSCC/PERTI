/**
 * PERTI Locale Loader
 *
 * Loads the appropriate locale file and initializes PERTII18n.
 * Include this after i18n.js but before other scripts that use translations.
 *
 * Usage in HTML:
 *   <script src="/assets/js/lib/i18n.js"></script>
 *   <script src="/assets/locales/index.js"></script>
 *   <script src="/assets/js/lib/dialog.js"></script>
 *
 * @module locales/index
 * @version 1.0.0
 * @requires lib/i18n (PERTII18n)
 */

(function() {
    'use strict';

    // Default locale
    const DEFAULT_LOCALE = 'en-US';

    // Supported locales
    const SUPPORTED_LOCALES = ['en-US'];

    /**
     * Detect user's preferred locale
     * Priority: URL param > localStorage > browser > default
     */
    function detectLocale() {
        // Check URL parameter (?locale=en-GB)
        if (typeof location !== 'undefined') {
            const params = new URLSearchParams(location.search);
            const urlLocale = params.get('locale');
            if (urlLocale && SUPPORTED_LOCALES.includes(urlLocale)) {
                return urlLocale;
            }
        }

        // Check localStorage
        if (typeof localStorage !== 'undefined') {
            try {
                const stored = localStorage.getItem('PERTI_LOCALE');
                if (stored && SUPPORTED_LOCALES.includes(stored)) {
                    return stored;
                }
            } catch {
                // localStorage may be blocked
            }
        }

        // Check browser language
        if (typeof navigator !== 'undefined' && navigator.language) {
            const browserLocale = navigator.language;
            if (SUPPORTED_LOCALES.includes(browserLocale)) {
                return browserLocale;
            }
            // Try base language (e.g., 'en' from 'en-GB')
            const baseLang = browserLocale.split('-')[0];
            const match = SUPPORTED_LOCALES.find(l => l.startsWith(baseLang + '-'));
            if (match) {
                return match;
            }
        }

        return DEFAULT_LOCALE;
    }

    /**
     * Load locale data synchronously (inline for initial load)
     * For production, this would fetch from a JSON file
     */
    function loadLocaleSync(locale) {
        // Inline English locale for synchronous loading
        // This avoids async issues during page initialization
        if (locale === 'en-US') {
            return {
                "common": {
                    "ok": "OK",
                    "cancel": "Cancel",
                    "close": "Close",
                    "save": "Save",
                    "delete": "Delete",
                    "confirm": "Confirm",
                    "yes": "Yes",
                    "no": "No",
                    "yesDelete": "Yes, delete it",
                    "yesCancel": "Yes, cancel it",
                    "loading": "Loading...",
                    "error": "Error",
                    "success": "Success",
                    "warning": "Warning",
                    "info": "Info",
                    "submit": "Submit",
                    "apply": "Apply",
                    "reset": "Reset",
                    "refresh": "Refresh",
                    "copy": "Copy",
                    "copied": "Copied!",
                    "select": "Select",
                    "search": "Search",
                    "filter": "Filter",
                    "clear": "Clear",
                    "add": "Add",
                    "remove": "Remove",
                    "edit": "Edit",
                    "update": "Update",
                    "view": "View",
                    "details": "Details",
                    "back": "Back",
                    "next": "Next",
                    "done": "Done",
                    "enabled": "Enabled",
                    "disabled": "Disabled",
                    "none": "None",
                    "all": "All",
                    "other": "Other",
                    "unknown": "Unknown"
                },
                "status": {
                    "active": "Active",
                    "pending": "Pending",
                    "cancelled": "Cancelled",
                    "expired": "Expired",
                    "draft": "Draft",
                    "proposed": "Proposed",
                    "simulated": "Simulated",
                    "actual": "Actual",
                    "published": "Published",
                    "coordinating": "Coordinating",
                    "complete": "Complete",
                    "failed": "Failed",
                    "unknown": "Unknown"
                },
                "phase": {
                    "arrived": "Arrived",
                    "departed": "Departed",
                    "enroute": "Enroute",
                    "taxiing": "Taxiing",
                    "prefile": "Prefile",
                    "descending": "Descending",
                    "disconnected": "Disconnected",
                    "unknown": "Unknown"
                },
                "tmi": {
                    "gdp": "Ground Delay Program",
                    "gdpShort": "GDP",
                    "gs": "Ground Stop",
                    "gsShort": "GS",
                    "edct": "EDCT",
                    "reroute": "Reroute",
                    "exempt": "Exempt",
                    "uncontrolled": "Uncontrolled",
                    "actualGdp": "GDP (EDCT)",
                    "simulatedGdp": "GDP (Simulated)",
                    "proposedGdp": "GDP (Proposed)",
                    "actualGs": "GS (EDCT)",
                    "simulatedGs": "GS (Simulated)",
                    "proposedGs": "GS (Proposed)"
                },
                "weightClass": {
                    "J": "Super",
                    "H": "Heavy",
                    "L": "Large",
                    "S": "Small",
                    "unknown": "Unknown"
                },
                "flightRule": {
                    "I": "IFR",
                    "V": "VFR"
                },
                "dccRegion": {
                    "west": "West",
                    "southCentral": "South Central",
                    "midwest": "Midwest",
                    "southeast": "Southeast",
                    "northeast": "Northeast",
                    "canadaEast": "Canada East",
                    "canadaWest": "Canada West"
                },
                "dialog": {
                    "loading": "Loading...",
                    "submitting": "Submitting...",
                    "publishing": "Publishing...",
                    "saving": "Saving...",
                    "processing": "Processing..."
                },
                "error": {
                    "loadFailed": "Failed to load {resource}",
                    "saveFailed": "Failed to save {resource}",
                    "networkError": "Network error: {message}",
                    "connectionFailed": "Failed to connect to server",
                    "invalidInput": "Invalid input"
                },
                "flight": {
                    "one": "{count} flight",
                    "other": "{count} flights"
                }
            };
        }

        // Return empty for unsupported locales
        return {};
    }

    /**
     * Initialize i18n system
     */
    function init() {
        if (typeof PERTII18n === 'undefined') {
            console.error('[LocaleLoader] PERTII18n not loaded. Include i18n.js before this script.');
            return;
        }

        const locale = detectLocale();
        const strings = loadLocaleSync(locale);

        PERTII18n.setLocale(locale);
        PERTII18n.loadStrings(strings, true); // Load as fallback

        // Also load as primary strings
        PERTII18n.loadStrings(strings);

        // Store detected locale
        if (typeof localStorage !== 'undefined') {
            try {
                localStorage.setItem('PERTI_LOCALE', locale);
            } catch {
                // localStorage may be blocked
            }
        }

        console.log('[LocaleLoader] Initialized with locale:', locale);
    }

    // Initialize on load
    init();

    // Expose for manual reloading if needed
    if (typeof window !== 'undefined') {
        window.PERTILocaleLoader = {
            reload: init,
            detectLocale,
            SUPPORTED_LOCALES,
        };
    }
})();
