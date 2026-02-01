/**
 * FIR Scope Enhancement for GDT Ground Stop/GDP
 *
 * Adds international FIR-based scope selection alongside domestic ARTCC tiers.
 * Features:
 *   - Toggle between Domestic (ARTCC) and International (FIR) mode
 *   - Pattern-based FIR matching (EG** for UK, LF** for France, etc.)
 *   - Regional groupings (EUR, APAC, NAM, SAM, AFR, MID)
 *   - Integration with existing scope selector
 *
 * Dependencies:
 *   - fir_tiers.json (loaded on demand)
 *   - Existing TMI_TIER_INFO system for ARTCC mode
 */

(function() {
    'use strict';

    // FIR tier data (loaded from fir_tiers.json)
    let FIR_TIER_DATA = null;
    let FIR_MODE_ACTIVE = false;

    /**
     * Load FIR tier configuration from JSON file
     */
    function loadFirTiers() {
        if (FIR_TIER_DATA) {
            return Promise.resolve(FIR_TIER_DATA);
        }

        return fetch('assets/data/fir_tiers.json', { cache: 'default' })
            .then(function(res) {
                if (!res.ok) {throw new Error('Failed to load FIR tiers');}
                return res.json();
            })
            .then(function(data) {
                FIR_TIER_DATA = data;
                console.log('FIR tier data loaded:', Object.keys(data.byIcaoPrefix || {}).length, 'FIR prefixes');
                return data;
            })
            .catch(function(err) {
                console.error('Error loading FIR tiers:', err);
                return null;
            });
    }

    /**
     * Initialize the FIR mode toggle button
     */
    function initFirModeToggle() {
        // Find the scope selector container
        let scopeLabel = document.querySelector('label[for="gs_scope_select"]');
        if (!scopeLabel) {
            // Try to find by text content
            const labels = document.querySelectorAll('label');
            for (let i = 0; i < labels.length; i++) {
                if (labels[i].textContent.indexOf('ORIGIN CENTERS') !== -1 ||
                    labels[i].textContent.indexOf('SCOPE') !== -1) {
                    scopeLabel = labels[i];
                    break;
                }
            }
        }

        if (!scopeLabel) {
            console.warn('FIR toggle: Could not find scope selector label');
            return;
        }

        // Check if toggle already exists
        if (document.getElementById('gs_fir_mode_toggle')) {
            return;
        }

        // Create toggle button group
        const toggleContainer = document.createElement('div');
        toggleContainer.className = 'btn-group btn-group-sm ms-2';
        toggleContainer.style.cssText = 'display: inline-flex; margin-left: 10px;';
        toggleContainer.innerHTML = `
            <button type="button" id="gs_scope_mode_domestic" class="btn btn-primary btn-sm active" 
                    title="Domestic (US/Canada ARTCC tiers)">
                <i class="fas fa-flag-usa me-1"></i>Domestic
            </button>
            <button type="button" id="gs_scope_mode_intl" class="btn btn-outline-primary btn-sm" 
                    title="International (FIR-based scope)">
                <i class="fas fa-globe me-1"></i>International
            </button>
        `;

        // Insert after the label
        scopeLabel.parentNode.insertBefore(toggleContainer, scopeLabel.nextSibling);

        // Add event listeners
        const domesticBtn = document.getElementById('gs_scope_mode_domestic');
        const intlBtn = document.getElementById('gs_scope_mode_intl');

        domesticBtn.addEventListener('click', function() {
            if (!FIR_MODE_ACTIVE) {return;}
            FIR_MODE_ACTIVE = false;
            domesticBtn.classList.remove('btn-outline-primary');
            domesticBtn.classList.add('btn-primary', 'active');
            intlBtn.classList.remove('btn-primary', 'active');
            intlBtn.classList.add('btn-outline-primary');
            populateScopeSelectorForMode('domestic');
        });

        intlBtn.addEventListener('click', function() {
            if (FIR_MODE_ACTIVE) {return;}
            FIR_MODE_ACTIVE = true;
            intlBtn.classList.remove('btn-outline-primary');
            intlBtn.classList.add('btn-primary', 'active');
            domesticBtn.classList.remove('btn-primary', 'active');
            domesticBtn.classList.add('btn-outline-primary');

            // Load FIR data if needed, then populate
            loadFirTiers().then(function() {
                populateScopeSelectorForMode('international');
            });
        });

        console.log('FIR mode toggle initialized');
    }

    /**
     * Populate scope selector based on current mode
     */
    function populateScopeSelectorForMode(mode) {
        const sel = document.getElementById('gs_scope_select');
        if (!sel) {return;}

        sel.innerHTML = '';

        if (mode === 'domestic') {
            // Use existing ARTCC tier system (call original function if available)
            if (typeof window.populateScopeSelector === 'function') {
                window.populateScopeSelector();
            } else {
                populateScopeSelectorDomestic(sel);
            }
        } else {
            populateScopeSelectorInternational(sel);
        }
    }

    /**
     * Populate selector with domestic ARTCC options
     * (Fallback if original populateScopeSelector not exposed)
     */
    function populateScopeSelectorDomestic(sel) {
        // This should match the structure from the original populateScopeSelector
        // It's called when the original function isn't available in global scope

        if (typeof TMI_TIER_INFO !== 'undefined' && TMI_TIER_INFO.length > 0) {
            // Original function should handle this
            if (typeof populateScopeSelector === 'function') {
                populateScopeSelector();
                return;
            }
        }

        // Minimal fallback - add basic presets
        const optgroupPresets = document.createElement('optgroup');
        optgroupPresets.label = 'Presets';

        ['ALL', 'ALL+Canada', 'Manual'].forEach(function(code) {
            const opt = document.createElement('option');
            opt.value = code;
            opt.textContent = code;
            optgroupPresets.appendChild(opt);
        });

        sel.appendChild(optgroupPresets);
    }

    /**
     * Populate selector with international FIR options
     */
    function populateScopeSelectorInternational(sel) {
        if (!FIR_TIER_DATA) {
            const opt = document.createElement('option');
            opt.value = '';
            opt.textContent = 'Loading FIR data...';
            sel.appendChild(opt);
            return;
        }

        // Global presets
        const optgroupGlobal = document.createElement('optgroup');
        optgroupGlobal.label = 'Global Presets';

        Object.keys(FIR_TIER_DATA.global || {}).forEach(function(code) {
            const entry = FIR_TIER_DATA.global[code];
            const opt = document.createElement('option');
            opt.value = code;
            opt.dataset.type = 'fir-global';
            opt.dataset.patterns = JSON.stringify(entry.patterns || []);
            opt.textContent = entry.label || code;
            if (entry.description) {
                opt.title = entry.description;
            }
            optgroupGlobal.appendChild(opt);
        });
        sel.appendChild(optgroupGlobal);

        // Regional groups
        const optgroupRegional = document.createElement('optgroup');
        optgroupRegional.label = 'Regional Groups';

        Object.keys(FIR_TIER_DATA.regional || {}).forEach(function(code) {
            const entry = FIR_TIER_DATA.regional[code];
            const opt = document.createElement('option');
            opt.value = code;
            opt.dataset.type = 'fir-regional';
            opt.dataset.patterns = JSON.stringify(entry.patterns || []);
            opt.textContent = entry.label || code;
            if (entry.description) {
                opt.title = entry.description;
            }
            optgroupRegional.appendChild(opt);
        });
        sel.appendChild(optgroupRegional);

        // Individual FIR prefixes grouped by region
        const regions = {
            'EUR': { label: 'Europe', prefixes: [] },
            'APAC': { label: 'Asia-Pacific', prefixes: [] },
            'MID': { label: 'Middle East', prefixes: [] },
            'AFR': { label: 'Africa', prefixes: [] },
            'SAM': { label: 'South America', prefixes: [] },
            'NAM': { label: 'North America (Intl)', prefixes: [] },
        };

        // Categorize prefixes by region based on ICAO letter
        Object.keys(FIR_TIER_DATA.byIcaoPrefix || {}).forEach(function(prefix) {
            const entry = FIR_TIER_DATA.byIcaoPrefix[prefix];
            const firstLetter = prefix.charAt(0);

            // Determine region based on ICAO prefix
            let region = 'NAM'; // Default
            if ('EL'.indexOf(firstLetter) !== -1) {region = 'EUR';}
            else if ('RVWYZNPr'.indexOf(firstLetter) !== -1) {region = 'APAC';}
            else if ('O'.indexOf(firstLetter) !== -1) {region = 'MID';}
            else if ('DFGH'.indexOf(firstLetter) !== -1) {region = 'AFR';}
            else if ('S'.indexOf(firstLetter) !== -1) {region = 'SAM';}
            else if ('MT'.indexOf(firstLetter) !== -1) {region = 'NAM';}
            else if ('U'.indexOf(firstLetter) !== -1) {
                // Russia - could be EUR or APAC depending on location
                region = (prefix === 'UU' || prefix === 'UL' || prefix === 'UK') ? 'EUR' : 'APAC';
            }

            if (regions[region]) {
                regions[region].prefixes.push({ prefix: prefix, entry: entry });
            }
        });

        // Add optgroups for each region with entries
        Object.keys(regions).forEach(function(regionCode) {
            const region = regions[regionCode];
            if (region.prefixes.length === 0) {return;}

            const optgroup = document.createElement('optgroup');
            optgroup.label = region.label + ' FIRs';

            region.prefixes.sort(function(a, b) {
                return a.prefix.localeCompare(b.prefix);
            }).forEach(function(item) {
                const opt = document.createElement('option');
                opt.value = 'FIR_' + item.prefix;
                opt.dataset.type = 'fir-individual';
                opt.dataset.patterns = JSON.stringify(item.entry.patterns || [item.prefix + '*']);
                opt.textContent = item.prefix + ' - ' + item.entry.label;
                optgroup.appendChild(opt);
            });

            sel.appendChild(optgroup);
        });

        // Manual entry option
        const optgroupManual = document.createElement('optgroup');
        optgroupManual.label = 'Custom';
        const manualOpt = document.createElement('option');
        manualOpt.value = 'FIR_Manual';
        manualOpt.dataset.type = 'fir-manual';
        manualOpt.textContent = 'Manual (enter patterns)';
        optgroupManual.appendChild(manualOpt);
        sel.appendChild(optgroupManual);
    }

    /**
     * Get departure facilities/patterns from FIR scope selection
     * Called by recomputeScopeFromSelector when in FIR mode
     */
    function getFirScopePatterns() {
        const sel = document.getElementById('gs_scope_select');
        if (!sel) {return [];}

        const selected = Array.prototype.slice.call(sel.selectedOptions || []);
        let patterns = [];

        selected.forEach(function(opt) {
            const type = opt.dataset.type;
            if (type && type.startsWith('fir-')) {
                try {
                    const optPatterns = JSON.parse(opt.dataset.patterns || '[]');
                    patterns = patterns.concat(optPatterns);
                } catch (e) {
                    console.warn('Error parsing FIR patterns:', e);
                }
            }
        });

        return patterns;
    }

    /**
     * Check if a departure airport matches FIR scope patterns
     * @param {string} depIcao - Departure airport ICAO code
     * @param {string[]} patterns - Array of patterns (e.g., ['EG*', 'LF*'])
     * @returns {boolean}
     */
    function matchesFirPattern(depIcao, patterns) {
        if (!depIcao || !patterns || patterns.length === 0) {return true;}

        depIcao = depIcao.toUpperCase();

        for (let i = 0; i < patterns.length; i++) {
            const pattern = patterns[i].toUpperCase();

            if (pattern === '*') {
                return true;
            }

            // Convert wildcard pattern to regex
            // E* -> ^E
            // EG* -> ^EG
            // EG** -> ^EG (same as EG*)
            const regexStr = '^' + pattern.replace(/\*+/g, '');

            if (new RegExp(regexStr).test(depIcao)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if currently in FIR mode
     */
    function isFirModeActive() {
        return FIR_MODE_ACTIVE;
    }

    /**
     * Get current scope patterns (works for both ARTCC and FIR modes)
     */
    function getCurrentScopePatterns() {
        if (FIR_MODE_ACTIVE) {
            return getFirScopePatterns();
        } else {
            // Return ARTCC list from existing system
            const originCentersField = document.getElementById('gs_origin_centers');
            if (originCentersField && originCentersField.value) {
                return originCentersField.value.split(/\s+/).filter(function(x) { return x.length > 0; });
            }
            return [];
        }
    }

    // Export functions to global scope
    window.FIR_SCOPE = {
        loadFirTiers: loadFirTiers,
        initToggle: initFirModeToggle,
        populateSelector: populateScopeSelectorForMode,
        getPatterns: getFirScopePatterns,
        matchesPattern: matchesFirPattern,
        isActive: isFirModeActive,
        getCurrentPatterns: getCurrentScopePatterns,
        getData: function() { return FIR_TIER_DATA; },
    };

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            // Delay initialization to ensure other GDT scripts have loaded
            setTimeout(initFirModeToggle, 500);
        });
    } else {
        setTimeout(initFirModeToggle, 500);
    }

    console.log('FIR scope enhancement module loaded');
})();
