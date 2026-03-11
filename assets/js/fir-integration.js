/**
 * FIR Scope Integration for GDT Ground Stop/GDP
 *
 * This file extends the FIR scope functionality to integrate with the existing
 * GDT flight filtering and model API. It hooks into key functions to enable
 * international airport ground stops.
 *
 * Integration points:
 *   1. recomputeScopeFromSelector - Handle FIR pattern storage
 *   2. Flight filtering - Check FIR patterns for departure airports
 *   3. API calls - Send FIR patterns to backend
 *
 * Dependencies:
 *   - fir-scope.js (base FIR module)
 *   - gdt.js (main GDT module)
 */

(function() {
    'use strict';

    // Store current FIR patterns when in international mode
    let currentFirPatterns = [];
    // Store current ARTCC member codes (for member-based groups like USA, CANE, etc.)
    let currentArtccMembers = [];

    /**
     * Get FIR exclusion list for a given ICAO prefix from fir_tiers.json.
     * Returns array of excluded ICAO prefixes (e.g. ["EGYP"] for EG).
     * @param {string} prefix - ICAO prefix (e.g. "EG")
     * @returns {string[]}
     */
    function getFirExclusions(prefix) {
        if (!window.FIR_SCOPE || typeof window.FIR_SCOPE.getTierData !== 'function') {return [];}
        var data = window.FIR_SCOPE.getTierData();
        if (!data || !data.byIcaoPrefix) {return [];}
        var entry = data.byIcaoPrefix[prefix];
        return (entry && Array.isArray(entry.exclude)) ? entry.exclude : [];
    }

    /**
     * Check if a departure airport matches any FIR pattern,
     * respecting exclusions from fir_tiers.json (e.g. EGYP excluded from EG).
     * @param {string} depIcao - Departure ICAO code (e.g., "EGLL")
     * @param {string[]} patterns - FIR patterns (e.g., ["EG*", "LF*"])
     * @returns {boolean}
     */
    function matchesFirPatterns(depIcao, patterns) {
        if (!depIcao || !patterns || patterns.length === 0) {return true;}
        if (patterns.indexOf('*') !== -1) {return true;} // Global match

        depIcao = depIcao.toUpperCase();

        for (let i = 0; i < patterns.length; i++) {
            const pattern = patterns[i].toUpperCase().replace(/\*+$/, '');
            if (pattern === '' || pattern === '*') {return true;}
            if (depIcao.indexOf(pattern) === 0) {
                // Check exclusions for this pattern prefix
                var exclusions = getFirExclusions(pattern);
                var excluded = false;
                for (var e = 0; e < exclusions.length; e++) {
                    if (depIcao.indexOf(exclusions[e].toUpperCase()) === 0) {
                        excluded = true;
                        break;
                    }
                }
                if (!excluded) {return true;}
            }
        }

        return false;
    }

    /**
     * Convert FIR patterns to a format the backend can understand
     * Uses FIR: prefix to distinguish from ARTCC codes
     * @param {string[]} patterns - Array of FIR patterns
     * @returns {string} - Space-delimited string with FIR: prefix
     */
    function patternsToDepFacilities(patterns) {
        if (!patterns || patterns.length === 0) {return '';}

        // Prefix each pattern with FIR: so backend can distinguish
        return patterns.map(function(p) {
            const clean = p.replace(/\*+$/, '').toUpperCase();
            return 'FIR:' + clean;
        }).join(' ');
    }

    /**
     * Enhanced scope recomputation that handles FIR mode.
     * Supports two scope types from fir_tiers.json regional entries:
     *   - "artcc" (data-scope-type="artcc"): explicit L1 ARTCC member lists,
     *     sent as plain ARTCC codes the SP already understands
     *   - pattern-based: ICAO prefix patterns sent with FIR: prefix
     */
    function enhancedRecomputeScope() {
        const sel = document.getElementById('gs_scope_select');
        if (!sel) {return;}

        const isFirMode = window.FIR_SCOPE && window.FIR_SCOPE.isActive();

        if (!isFirMode) {
            currentFirPatterns = [];
            currentArtccMembers = [];

            if (typeof window._originalRecomputeScopeFromSelector === 'function') {
                window._originalRecomputeScopeFromSelector();
            }
            return;
        }

        // FIR mode - extract patterns and/or ARTCC members from selected options
        const selected = Array.prototype.slice.call(sel.selectedOptions || []);
        let patterns = [];
        let artccMembers = [];
        const scopeLabels = [];

        selected.forEach(function(opt) {
            const type = opt.dataset.type || '';
            if (type.indexOf('fir-') !== 0) {return;}

            scopeLabels.push(opt.textContent || opt.value);

            // ARTCC member-based group (e.g., USA, CANE, GULF)
            if (opt.dataset.scopeType === 'artcc' && opt.dataset.members) {
                try {
                    var members = JSON.parse(opt.dataset.members);
                    artccMembers = artccMembers.concat(members);
                } catch (e) {
                    console.warn('Error parsing ARTCC members:', e);
                }
                return;
            }

            // FIR pattern-based group (e.g., EUR, AFR, individual FIRs)
            try {
                var optPatterns = JSON.parse(opt.dataset.patterns || '[]');
                patterns = patterns.concat(optPatterns);
            } catch (e) {
                console.warn('Error parsing FIR patterns:', e);
            }
        });

        // Deduplicate
        currentFirPatterns = patterns;
        currentArtccMembers = artccMembers.filter(function(v, i, a) { return a.indexOf(v) === i; });

        // Update form fields
        const originCentersField = document.getElementById('gs_origin_centers');
        const depFacilitiesField = document.getElementById('gs_dep_facilities');

        if (originCentersField) {
            originCentersField.value = scopeLabels.join(', ') || ((typeof PERTII18n !== 'undefined') ? PERTII18n.t('fir.international') : 'International');
        }

        if (depFacilitiesField) {
            // Build dep_facilities: ARTCC members as plain codes + FIR patterns with FIR: prefix
            var parts = [];
            if (currentArtccMembers.length > 0) {
                parts.push(currentArtccMembers.join(' '));
            }
            if (patterns.length > 0) {
                parts.push(patternsToDepFacilities(patterns));
            }
            depFacilitiesField.value = parts.join(' ');
        }

        if (typeof buildAdvisory === 'function') {
            buildAdvisory();
        }

        console.log('FIR scope updated:', patterns.length, 'patterns,', currentArtccMembers.length, 'ARTCC members');
    }

    /**
     * Flight filter wrapper that checks FIR patterns and/or ARTCC membership.
     * @param {Object} flight - Flight object
     * @returns {boolean} - True if flight matches scope
     */
    function flightMatchesScope(flight) {
        if (!window.FIR_SCOPE || !window.FIR_SCOPE.isActive()) {
            return true; // Not in FIR mode, don't filter here
        }

        var hasPatterns = currentFirPatterns.length > 0;
        var hasMembers = currentArtccMembers.length > 0;

        if (!hasPatterns && !hasMembers) {
            return true; // No scope constraints = match all
        }

        // Check ARTCC membership (for groups like USA, CANE, GULF)
        if (hasMembers) {
            var depArtcc = (flight.fp_dept_artcc || flight.dep_artcc ||
                           flight.dep_center || flight.departure_artcc || '').toUpperCase();
            if (depArtcc && currentArtccMembers.indexOf(depArtcc) !== -1) {
                return true;
            }
        }

        // Check FIR pattern matching (for groups like EUR, AFR, individual FIRs)
        if (hasPatterns) {
            var dep = (flight.dep || flight.fp_dept_icao || flight.orig ||
                       flight.departure || flight.origin || '').toUpperCase();
            if (matchesFirPatterns(dep, currentFirPatterns)) {
                return true;
            }
        }

        // If we had constraints but nothing matched, flight is out of scope
        return false;
    }

    /**
     * Get current FIR patterns (for use by other modules)
     */
    function getCurrentFirPatterns() {
        return currentFirPatterns.slice(); // Return copy
    }

    /**
     * Check if currently in FIR mode with patterns or ARTCC members set
     */
    function hasFirScope() {
        return window.FIR_SCOPE &&
               window.FIR_SCOPE.isActive() &&
               (currentFirPatterns.length > 0 || currentArtccMembers.length > 0);
    }

    /**
     * Hook into the scope selector's change event
     */
    function hookScopeSelector() {
        const sel = document.getElementById('gs_scope_select');
        if (!sel) {
            console.warn('FIR integration: scope selector not found');
            return;
        }

        // Store original handler if we can find it
        // The original recomputeScopeFromSelector is inside an IIFE in gdt.js
        // We'll just add our handler alongside it

        sel.addEventListener('change', function() {
            if (window.FIR_SCOPE && window.FIR_SCOPE.isActive()) {
                enhancedRecomputeScope();
            }
        });

        console.log('FIR integration: hooked scope selector');
    }

    /**
     * Monkey-patch flight rendering to filter by FIR patterns
     */
    function hookFlightFiltering() {
        // Look for the global filterAdlFlight function or similar
        // Since it's inside an IIFE, we need a different approach

        // Instead, we'll expose a filter check that gdt.js can call
        window.FIR_INTEGRATION = window.FIR_INTEGRATION || {};
        window.FIR_INTEGRATION.matchesScope = flightMatchesScope;
        window.FIR_INTEGRATION.getPatterns = getCurrentFirPatterns;
        window.FIR_INTEGRATION.getArtccMembers = function() { return currentArtccMembers.slice(); };
        window.FIR_INTEGRATION.hasFirScope = hasFirScope;
        window.FIR_INTEGRATION.enhancedRecomputeScope = enhancedRecomputeScope;
        window.FIR_INTEGRATION.matchesFirPatterns = matchesFirPatterns;

        console.log('FIR integration: filter functions exposed');
    }

    /**
     * Initialize integration after DOM and base modules are ready
     */
    function init() {
        // Wait for FIR_SCOPE to be available
        if (!window.FIR_SCOPE) {
            console.log('FIR integration: waiting for FIR_SCOPE module...');
            setTimeout(init, 200);
            return;
        }

        hookScopeSelector();
        hookFlightFiltering();

        // Add listener for mode toggle
        const intlBtn = document.getElementById('gs_scope_mode_intl');
        const domesticBtn = document.getElementById('gs_scope_mode_domestic');

        if (intlBtn) {
            intlBtn.addEventListener('click', function() {
                // Clear and rebuild when switching to international
                setTimeout(enhancedRecomputeScope, 100);
            });
        }

        if (domesticBtn) {
            domesticBtn.addEventListener('click', function() {
                // Clear FIR patterns and ARTCC members when switching to domestic
                currentFirPatterns = [];
                currentArtccMembers = [];
            });
        }

        console.log('FIR integration module initialized');
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(init, 600); // After fir-scope.js initializes
        });
    } else {
        setTimeout(init, 600);
    }

})();
