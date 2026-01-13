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
    var currentFirPatterns = [];
    
    /**
     * Check if a departure airport matches any FIR pattern
     * @param {string} depIcao - Departure ICAO code (e.g., "EGLL")
     * @param {string[]} patterns - FIR patterns (e.g., ["EG*", "LF*"])
     * @returns {boolean}
     */
    function matchesFirPatterns(depIcao, patterns) {
        if (!depIcao || !patterns || patterns.length === 0) return true;
        if (patterns.indexOf('*') !== -1) return true; // Global match
        
        depIcao = depIcao.toUpperCase();
        
        for (var i = 0; i < patterns.length; i++) {
            var pattern = patterns[i].toUpperCase().replace(/\*+$/, '');
            if (pattern === '' || pattern === '*') return true;
            if (depIcao.indexOf(pattern) === 0) return true;
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
        if (!patterns || patterns.length === 0) return '';
        
        // Prefix each pattern with FIR: so backend can distinguish
        return patterns.map(function(p) {
            var clean = p.replace(/\*+$/, '').toUpperCase();
            return 'FIR:' + clean;
        }).join(' ');
    }

    /**
     * Enhanced scope recomputation that handles FIR mode
     */
    function enhancedRecomputeScope() {
        var sel = document.getElementById('gs_scope_select');
        if (!sel) return;

        var isFirMode = window.FIR_SCOPE && window.FIR_SCOPE.isActive();
        
        if (!isFirMode) {
            // Use original ARTCC logic - clear FIR patterns
            currentFirPatterns = [];
            
            // Call original if available, otherwise do nothing (original will handle it)
            if (typeof window._originalRecomputeScopeFromSelector === 'function') {
                window._originalRecomputeScopeFromSelector();
            }
            return;
        }

        // FIR mode - extract patterns from selected options
        var selected = Array.prototype.slice.call(sel.selectedOptions || []);
        var patterns = [];
        var scopeLabels = [];

        selected.forEach(function(opt) {
            var type = opt.dataset.type || '';
            if (type.indexOf('fir-') === 0) {
                try {
                    var optPatterns = JSON.parse(opt.dataset.patterns || '[]');
                    patterns = patterns.concat(optPatterns);
                    scopeLabels.push(opt.textContent || opt.value);
                } catch (e) {
                    console.warn('Error parsing FIR patterns:', e);
                }
            }
        });

        // Store current patterns
        currentFirPatterns = patterns;

        // Update form fields
        var originCentersField = document.getElementById('gs_origin_centers');
        var depFacilitiesField = document.getElementById('gs_dep_facilities');

        if (originCentersField) {
            // Store human-readable scope description
            originCentersField.value = scopeLabels.join(', ') || 'International';
        }

        if (depFacilitiesField) {
            // Store FIR patterns in the format backend expects
            depFacilitiesField.value = patternsToDepFacilities(patterns);
        }

        // Trigger advisory rebuild if function exists
        if (typeof buildAdvisory === 'function') {
            buildAdvisory();
        }

        console.log('FIR scope updated:', patterns.length, 'patterns');
    }

    /**
     * Flight filter wrapper that checks FIR patterns
     * @param {Object} flight - Flight object
     * @returns {boolean} - True if flight matches scope
     */
    function flightMatchesScope(flight) {
        if (!window.FIR_SCOPE || !window.FIR_SCOPE.isActive()) {
            return true; // Not in FIR mode, don't filter here
        }

        if (currentFirPatterns.length === 0) {
            return true; // No patterns = match all
        }

        var dep = (flight.dep || flight.fp_dept_icao || flight.orig || 
                   flight.departure || flight.origin || '').toUpperCase();
        
        return matchesFirPatterns(dep, currentFirPatterns);
    }

    /**
     * Get current FIR patterns (for use by other modules)
     */
    function getCurrentFirPatterns() {
        return currentFirPatterns.slice(); // Return copy
    }

    /**
     * Check if currently in FIR mode with patterns set
     */
    function hasFirScope() {
        return window.FIR_SCOPE && 
               window.FIR_SCOPE.isActive() && 
               currentFirPatterns.length > 0;
    }

    /**
     * Hook into the scope selector's change event
     */
    function hookScopeSelector() {
        var sel = document.getElementById('gs_scope_select');
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
        var intlBtn = document.getElementById('gs_scope_mode_intl');
        var domesticBtn = document.getElementById('gs_scope_mode_domestic');

        if (intlBtn) {
            intlBtn.addEventListener('click', function() {
                // Clear and rebuild when switching to international
                setTimeout(enhancedRecomputeScope, 100);
            });
        }

        if (domesticBtn) {
            domesticBtn.addEventListener('click', function() {
                // Clear FIR patterns when switching to domestic
                currentFirPatterns = [];
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
