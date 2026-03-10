/**
 * PlaybookCatalog — vATCSCC Playbook feature module
 * Browse, manage, and activate pre-coordinated SWAP route plays.
 */
(function() {
    'use strict';

    var API_LIST   = 'api/data/playbook/list.php';
    var API_GET    = 'api/data/playbook/get.php';
    var API_CATS   = 'api/data/playbook/categories.php';
    var API_LOG    = 'api/data/playbook/changelog.php';
    var API_SAVE   = 'api/mgt/playbook/save.php';
    var API_DELETE  = 'api/mgt/playbook/delete.php';
    var API_GROUPS      = 'api/data/playbook/groups.php';
    var API_GROUPS_SAVE = 'api/mgt/playbook/groups.php';

    var t = typeof PERTII18n !== 'undefined' ? PERTII18n.t.bind(PERTII18n) : function(k) { return k; };
    var hasPerm = window.PERTI_PLAYBOOK_PERM === true;

    // FAA National Playbook category display order
    var FAA_CATEGORY_ORDER = [
        'Airports', 'East to West Transcon', 'Equipment', 'Regional Routes',
        'Snowbird', 'Space Ops', 'Special Ops', 'SUA Activity', 'West to East Transcon'
    ];

    // Legacy consolidated TRACON aliases (FAA still uses these in playbook data)
    var TRACON_ALIASES = {
        'K90': 'A90',  // Cape TRACON → Boston TRACON (consolidated 2018)
    };

    // State
    var allPlays = [];          // Full loaded set from API
    var filteredPlays = [];     // After client-side filters
    var categoryData = {};      // { category_counts, categories, legacy_count }
    var activeCategory = '';    // '' = all
    var activeSource = '';      // '' = all
    var showLegacy = false;
    var searchText = '';
    var activePlayId = null;
    var activePlayData = null;
    var selectedRouteIds = new Set();
    var currentPage = 1;
    var playsPerPage = 200;
    var regionColorEnabled = true;
    var currentSearchClauses = [];  // Set by applyFilters(), read by route emphasis

    // Route group state
    var routeGroups = [];           // Array of { group_name, group_color, route_ids: Set, sort_order }
    var groupEditingIdx = -1;       // Index of group being edited (-1 = none)

    var GROUP_COLORS = [
        '#e74c3c', '#3498db', '#2ecc71', '#9b59b6', '#f39c12',
        '#1abc9c', '#e91e63', '#00bcd4', '#ff5722', '#607d8b',
        '#8bc34a', '#ff9800', '#673ab7', '#03a9f4', '#cddc39',
    ];

    // =========================================================================
    // HELPERS
    // =========================================================================

    function csvSplit(s) {
        return (s || '').split(',').map(function(x) { return x.trim(); }).filter(Boolean);
    }

    function escHtml(s) {
        var d = document.createElement('div');
        d.textContent = s || '';
        return d.innerHTML;
    }

    function getAiracCycle() {
        var now = new Date();
        var yy = String(now.getUTCFullYear()).slice(-2);
        var mm = String(now.getUTCMonth() + 1).padStart(2, '0');
        return yy + mm;
    }

    function normalizePlayName(name) {
        return (name || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
    }

    /**
     * Get checked values from a checkbox dropdown widget.
     */
    function getCheckedValues(dropdownId) {
        var vals = [];
        $('#' + dropdownId + ' input:checked').each(function() { vals.push($(this).val()); });
        return vals;
    }

    /**
     * Build a PB directive string from the current play and selection state.
     * Format: PB.{PLAYNAME} or PB.{PLAYNAME}.{ORIG} or PB.{PLAYNAME}..{DEST}
     * or PB.{PLAYNAME}.{ORIG}.{DEST}
     */
    function buildCurrentPBDirective() {
        if (!activePlayData) return '';
        var norm = activePlayData.play_name_norm || normalizePlayName(activePlayData.play_name);

        // Read from checkbox dropdown multi-selects
        var origVals = getCheckedValues('pb_select_origin');
        var destVals = getCheckedValues('pb_select_dest');

        var origCode = origVals.length ? origVals.map(function(v) { return v.split(':').pop(); }).join(' ') : '';
        var destCode = destVals.length ? destVals.map(function(v) { return v.split(':').pop(); }).join(' ') : '';

        if (!origCode && !destCode) return 'PB.' + norm;
        if (origCode && !destCode) return 'PB.' + norm + '.' + origCode;
        if (!origCode && destCode) return 'PB.' + norm + '..' + destCode;
        return 'PB.' + norm + '.' + origCode + '.' + destCode;
    }

    /**
     * Determine whether the current selection can be represented as a PB directive.
     * Returns true if all routes are selected, or a smart selection dropdown is active.
     * Returns false for manual cherry-picks (arbitrary subsets).
     */
    function canUsePBDirective(selected, allRoutes) {
        // FAA_HISTORICAL plays aren't in the PB CSV — must use individual route strings
        if (activePlayData && activePlayData.source === 'FAA_HISTORICAL') return false;
        if (selected.length === allRoutes.length) return true;
        if (getCheckedValues('pb_select_origin').length || getCheckedValues('pb_select_dest').length || getCheckedValues('pb_select_region').length) return true;
        return false;
    }

    function isLegacy(playName) {
        return (playName || '').indexOf('_old_') !== -1;
    }

    function updateUrl(playName) {
        if (!window.history || !window.history.replaceState) return;
        var url = new URL(window.location);
        if (playName) {
            url.searchParams.set('play', playName);
        } else {
            url.searchParams.delete('play');
        }
        window.history.replaceState(null, '', url);
    }

    function getUrlPlayName() {
        var params = new URLSearchParams(window.location.search);
        return params.get('play') || '';
    }

    function unique(arr) {
        var seen = {};
        return arr.filter(function(v) {
            if (seen[v]) return false;
            seen[v] = true;
            return true;
        });
    }

    // =========================================================================
    // SEARCH PARSER — Multi-token boolean search
    // Operators: AND (space/&), OR (,/|), NOT (- prefix)
    // Qualifiers: orig: dest: thru: (or via:) avoid:
    // avoid: is equivalent to -thru: (always negated, AND semantics)
    // =========================================================================

    function parseSearch(raw) {
        if (!raw || !raw.trim()) return [];
        // Split on whitespace or & → clauses (AND'd together)
        var clauses = raw.split(/[\s&]+/).filter(Boolean);
        return clauses.map(function(clause) {
            // Split on , or | → alternatives (OR'd)
            var alts = clause.split(/[,|]/).filter(Boolean);
            var inheritedQualifier = '';
            var inheritedNegated = false;
            return alts.map(function(alt, idx) {
                var negated = alt.charAt(0) === '-';
                var term = negated ? alt.substring(1) : alt;
                // Parse qualifier prefix (orig:, dest:, thru:, via:, avoid:)
                var qualifier = '';
                var colonIdx = term.indexOf(':');
                if (colonIdx > 0) {
                    var prefix = term.substring(0, colonIdx).toLowerCase();
                    if (prefix === 'orig' || prefix === 'dest' || prefix === 'thru' || prefix === 'via' || prefix === 'avoid') {
                        qualifier = prefix === 'via' ? 'thru' : prefix;
                        term = term.substring(colonIdx + 1);
                    }
                }
                // avoid: is always negated (equivalent to -thru:)
                if (qualifier === 'avoid') negated = true;
                // Propagate qualifier from first alt: orig:ZNY,ZDC → both get orig
                if (qualifier) { inheritedQualifier = qualifier; }
                else if (inheritedQualifier) { qualifier = inheritedQualifier; }
                // Propagate negation from first alt: -thru:ZFW,ZAU → both get negated
                if (idx === 0 && negated) { inheritedNegated = true; }
                else if (idx > 0 && !negated && inheritedNegated) { negated = true; }
                var upper = term.toUpperCase();
                // Normalize facility aliases (e.g. CZU → CZUL, CZE → CZEG)
                if (qualifier && typeof FacilityHierarchy !== 'undefined' && FacilityHierarchy.ALIAS_TO_CANONICAL) {
                    upper = FacilityHierarchy.ALIAS_TO_CANONICAL[upper] || upper;
                }
                return { term: upper, negated: negated, qualifier: qualifier };
            }).filter(function(a) { return a.term; });
        }).filter(function(c) { return c.length; });
    }

    function buildSearchIndex(p) {
        if (!p._searchText) {
            p._searchText = [
                p.play_name, p.display_name, p.description,
                p.category, p.impacted_area, p.facilities_involved,
                p.agg_route_strings
            ].filter(Boolean).join(' ').toUpperCase();
        }
        if (!p._facilityCodes) {
            // All facility codes (unqualified search)
            var codes = new Set();
            [p.facilities_involved, p.agg_origin_airports, p.agg_origin_tracons,
             p.agg_origin_artccs, p.agg_dest_airports, p.agg_dest_tracons,
             p.agg_dest_artccs, p.agg_traversed_artccs, p.agg_traversed_tracons,
             p.agg_traversed_sectors_low, p.agg_traversed_sectors_high,
             p.agg_traversed_sectors_superhigh].forEach(function(field) {
                csvSplit(field).forEach(function(c) { codes.add(c.toUpperCase()); });
            });
            (p.impacted_area || '').split(/[\/,]/).forEach(function(c) {
                var trimmed = c.trim().toUpperCase();
                if (trimmed) codes.add(trimmed);
            });
            p._facilityCodes = codes;

            // Origin-specific codes
            p._originCodes = new Set();
            [p.agg_origin_airports, p.agg_origin_tracons, p.agg_origin_artccs].forEach(function(field) {
                csvSplit(field).forEach(function(c) { p._originCodes.add(c.toUpperCase()); });
            });

            // Dest-specific codes
            p._destCodes = new Set();
            [p.agg_dest_airports, p.agg_dest_tracons, p.agg_dest_artccs].forEach(function(field) {
                csvSplit(field).forEach(function(c) { p._destCodes.add(c.toUpperCase()); });
            });

            // Traversed facilities (ARTCCs + TRACONs + sectors, includes origin + dest by definition)
            p._traversedCodes = new Set();
            [p.agg_traversed_artccs, p.agg_traversed_tracons,
             p.agg_traversed_sectors_low, p.agg_traversed_sectors_high,
             p.agg_traversed_sectors_superhigh,
             p.agg_origin_artccs, p.agg_dest_artccs,
             p.agg_origin_tracons, p.agg_dest_tracons].forEach(function(field) {
                csvSplit(field).forEach(function(c) { p._traversedCodes.add(c.toUpperCase()); });
            });
        }
    }

    function matchesSearch(p, clauses) {
        if (!clauses.length) return true;
        buildSearchIndex(p);
        // Every clause must pass (AND)
        for (var i = 0; i < clauses.length; i++) {
            var alts = clauses[i];

            // Partition: negated thru/avoid alts vs everything else
            var negatedThruAlts = alts.filter(function(a) { return a.negated && (a.qualifier === 'thru' || a.qualifier === 'avoid'); });
            var otherAlts = alts.filter(function(a) { return !(a.negated && (a.qualifier === 'thru' || a.qualifier === 'avoid')); });

            // Negated thru: exclude only if ALL are found (AND semantics)
            if (negatedThruAlts.length > 0) {
                var allThruFound = negatedThruAlts.every(function(a) { return p._traversedCodes.has(a.term); });
                if (allThruFound) return false;
            }

            // Process remaining alts with existing OR logic
            var clausePassed = false;
            for (var j = 0; j < otherAlts.length; j++) {
                var alt = otherAlts[j];
                var found = false;
                if (alt.qualifier === 'orig') {
                    found = p._originCodes.has(alt.term);
                } else if (alt.qualifier === 'dest') {
                    found = p._destCodes.has(alt.term);
                } else if (alt.qualifier === 'thru' || alt.qualifier === 'avoid') {
                    found = p._traversedCodes.has(alt.term);
                } else {
                    found = p._facilityCodes.has(alt.term) || p._searchText.indexOf(alt.term) !== -1;
                }
                if (alt.negated) {
                    if (found) return false; // Negated orig/dest/unqualified → OR exclusion
                } else {
                    if (found) clausePassed = true;
                }
            }
            var hasPositiveAlt = otherAlts.some(function(a) { return !a.negated; });
            if (hasPositiveAlt && !clausePassed) return false;
        }
        return true;
    }

    // =========================================================================
    // ROUTE-LEVEL SEARCH MATCHING — used for emphasis/dimming
    // =========================================================================

    /**
     * Evaluate a single route against parsed search clauses.
     * Same logic as matchesSearch() but at the route level.
     */
    function routeMatchesSearchClauses(route, clauses) {
        if (!clauses.length) return true;
        var origCodes = new Set();
        var destCodes = new Set();
        var thruCodes = new Set();
        csvSplit(route.origin_airports).concat(csvSplit(route.origin_tracons)).concat(csvSplit(route.origin_artccs)).forEach(function(c) { if (c) origCodes.add(c.toUpperCase()); });
        csvSplit(route.dest_airports).concat(csvSplit(route.dest_tracons)).concat(csvSplit(route.dest_artccs)).forEach(function(c) { if (c) destCodes.add(c.toUpperCase()); });
        csvSplit(route.traversed_artccs).forEach(function(c) { if (c) thruCodes.add(c.toUpperCase()); });
        csvSplit(route.traversed_tracons).forEach(function(c) { if (c) thruCodes.add(c.toUpperCase()); });
        csvSplit(route.traversed_sectors_low).forEach(function(c) { if (c) thruCodes.add(c.toUpperCase()); });
        csvSplit(route.traversed_sectors_high).forEach(function(c) { if (c) thruCodes.add(c.toUpperCase()); });
        csvSplit(route.traversed_sectors_superhigh).forEach(function(c) { if (c) thruCodes.add(c.toUpperCase()); });
        // Origin+dest ARTCCs and TRACONs are traversed by definition
        csvSplit(route.origin_artccs).forEach(function(c) { if (c) thruCodes.add(c.toUpperCase()); });
        csvSplit(route.dest_artccs).forEach(function(c) { if (c) thruCodes.add(c.toUpperCase()); });
        csvSplit(route.origin_tracons).forEach(function(c) { if (c) thruCodes.add(c.toUpperCase()); });
        csvSplit(route.dest_tracons).forEach(function(c) { if (c) thruCodes.add(c.toUpperCase()); });
        var allCodes = new Set();
        origCodes.forEach(function(c) { allCodes.add(c); });
        destCodes.forEach(function(c) { allCodes.add(c); });
        thruCodes.forEach(function(c) { allCodes.add(c); });
        var textBlob = ((route.route_string || '') + ' ' + (route.origin || '') + ' ' + (route.dest || '')).toUpperCase();

        for (var i = 0; i < clauses.length; i++) {
            var alts = clauses[i];

            // Partition: negated thru/avoid alts vs everything else
            var negatedThruAlts = alts.filter(function(a) { return a.negated && (a.qualifier === 'thru' || a.qualifier === 'avoid'); });
            var otherAlts = alts.filter(function(a) { return !(a.negated && (a.qualifier === 'thru' || a.qualifier === 'avoid')); });

            // Negated thru/avoid: exclude only if ALL are found (AND semantics)
            if (negatedThruAlts.length > 0) {
                var allThruFound = negatedThruAlts.every(function(a) { return thruCodes.has(a.term); });
                if (allThruFound) return false;
            }

            // Process remaining alts with existing OR logic
            var clausePassed = false;
            for (var j = 0; j < otherAlts.length; j++) {
                var alt = otherAlts[j];
                var found = false;
                if (alt.qualifier === 'orig') found = origCodes.has(alt.term);
                else if (alt.qualifier === 'dest') found = destCodes.has(alt.term);
                else if (alt.qualifier === 'thru' || alt.qualifier === 'avoid') found = thruCodes.has(alt.term);
                else found = allCodes.has(alt.term) || textBlob.indexOf(alt.term) !== -1;
                if (alt.negated) { if (found) return false; }
                else { if (found) clausePassed = true; }
            }
            var hasPositiveAlt = otherAlts.some(function(a) { return !a.negated; });
            if (hasPositiveAlt && !clausePassed) return false;
        }
        return true;
    }

    /**
     * Apply emphasis/dimming CSS classes to route table rows based on current search.
     * Called when search changes while a play is selected.
     */
    function applyRouteEmphasis() {
        var hasSearch = currentSearchClauses.length > 0;
        $('.pb-route-table tbody tr').each(function() {
            var rid = parseInt($(this).attr('data-route-id'));
            var route = (activePlayData && activePlayData.routes || []).find(function(r) { return r.route_id === rid; });
            if (!route) return;
            var matches = !hasSearch || routeMatchesSearchClauses(route, currentSearchClauses);
            $(this).toggleClass('pb-route-dimmed', hasSearch && !matches);
            $(this).toggleClass('pb-route-emphasized', hasSearch && matches);
        });
    }

    // =========================================================================
    // MAP FACILITY HIGHLIGHTING — color ARTCC/sector/TRACON boundaries on search
    // =========================================================================

    // Toggle state for highlight tiers (persisted via legend checkboxes)
    var highlightToggles = {
        artcc: true,
        tracon: true,
        sectorLow: true,
        sectorHigh: true,
        sectorSuperhigh: true,
        playOrigin: true,
        playDest: true,
        playTraversed: true,
    };
    // Cache last clauses so toggles can reapply without re-parsing
    var lastHighlightClauses = [];

    /**
     * Convert a facility code to ICAOCODE format used in artcc.json.
     * US ARTCCs: ZNY → KZNY, Canadian: CZEG stays CZEG
     */
    function toIcaoCode(code) {
        if (!code) return '';
        code = code.toUpperCase();
        // Already has K-prefix or C-prefix (4+ chars) → return as-is
        if (/^[KC]Z[A-Z]{1,2}$/.test(code) && code.length === 4) return code;
        // 3-char US ARTCC (ZNY, ZOB, ZAU etc.) → prepend K
        if (/^Z[A-Z]{2}$/.test(code)) return 'K' + code;
        return code;
    }

    /**
     * Classify a search term into its facility type.
     * Returns: { artccCode, traconCode, sectorLabel, isSector, isTracon }
     */
    function classifySearchTerm(term, hasFH) {
        var result = { artccCode: null, traconCode: null, sectorLabel: null };

        if (hasFH) {
            if (FacilityHierarchy.isArtcc(term)) {
                result.artccCode = term;
                return result;
            }
            if (FacilityHierarchy.isTracon(term)) {
                result.traconCode = term;
                result.artccCode = FacilityHierarchy.getParentArtcc(term);
                return result;
            }
        } else {
            if (/^Z[A-Z]{2}$/.test(term) || /^[KC]Z[A-Z]{1,2}$/.test(term)) {
                result.artccCode = term;
                return result;
            }
        }

        // Sector codes: {parent ARTCC/FIR}{sector name}
        if (term.length > 3) {
            result.sectorLabel = term;  // e.g., "ZNY56", "CZEGBA"
            if (hasFH) {
                if (term.length > 4 && FacilityHierarchy.isArtcc(term.substring(0, 4))) {
                    result.artccCode = term.substring(0, 4);
                } else if (FacilityHierarchy.isArtcc(term.substring(0, 3))) {
                    result.artccCode = term.substring(0, 3);
                }
            } else {
                var sectorMatch = term.match(/^(Z[A-Z]{2})\d/);
                if (sectorMatch) result.artccCode = sectorMatch[1];
            }
        }

        return result;
    }

    /**
     * Extract facility codes from parsed search clauses and update map layers.
     * Included (positive) terms → green fill, excluded (negated) → red fill.
     * Respects highlightToggles for ARTCC, TRACON, and sector tiers.
     */
    function updateMapHighlights(clauses) {
        lastHighlightClauses = clauses;
        var map = window.graphic_map;
        if (!map || !map.getLayer || !map.getLayer('artcc-search-include')) return;
        var hasLineLayer = map.getLayer('artcc-search-include-line');

        var includeCodes = [], excludeCodes = [];       // ARTCC ICAO codes
        var includeTracons = [], excludeTracons = [];   // TRACON codes (e.g., "A80")
        var includeSectors = [], excludeSectors = [];   // Sector labels (e.g., "ZNY56")
        var hasFH = typeof FacilityHierarchy !== 'undefined' && FacilityHierarchy.isLoaded;

        clauses.forEach(function(alts) {
            alts.forEach(function(alt) {
                var info = classifySearchTerm(alt.term, hasFH);
                var list;

                // ARTCC (parent or direct)
                if (info.artccCode) {
                    list = alt.negated ? excludeCodes : includeCodes;
                    list.push(toIcaoCode(info.artccCode));
                }
                // TRACON
                if (info.traconCode) {
                    list = alt.negated ? excludeTracons : includeTracons;
                    list.push(info.traconCode);
                }
                // Sector
                if (info.sectorLabel) {
                    list = alt.negated ? excludeSectors : includeSectors;
                    list.push(info.sectorLabel);
                }
            });
        });

        // ARTCC filters
        var emptyArtcc = ['in', 'ICAOCODE', ''];
        var inclFilter = (highlightToggles.artcc && includeCodes.length) ? ['in', 'ICAOCODE'].concat(includeCodes) : emptyArtcc;
        var exclFilter = (highlightToggles.artcc && excludeCodes.length) ? ['in', 'ICAOCODE'].concat(excludeCodes) : emptyArtcc;
        map.setFilter('artcc-search-include', inclFilter);
        map.setFilter('artcc-search-exclude', exclFilter);
        if (hasLineLayer) {
            map.setFilter('artcc-search-include-line', inclFilter);
            map.setFilter('artcc-search-exclude-line', exclFilter);
        }

        // TRACON filters
        var emptyTracon = ['in', 'sector', ''];
        var tInclFilter = (highlightToggles.tracon && includeTracons.length) ? ['in', 'sector'].concat(includeTracons) : emptyTracon;
        var tExclFilter = (highlightToggles.tracon && excludeTracons.length) ? ['in', 'sector'].concat(excludeTracons) : emptyTracon;
        if (map.getLayer('tracon-search-include')) {
            map.setFilter('tracon-search-include', tInclFilter);
            map.setFilter('tracon-search-exclude', tExclFilter);
        }

        // Sector filters (applied to all 3 tiers, each gated by its own toggle)
        var emptySector = ['in', 'label', ''];
        var sInclFilter = includeSectors.length ? ['in', 'label'].concat(includeSectors) : emptySector;
        var sExclFilter = excludeSectors.length ? ['in', 'label'].concat(excludeSectors) : emptySector;

        var sectorTiers = [
            { prefix: 'superhigh', toggle: 'sectorSuperhigh' },
            { prefix: 'high', toggle: 'sectorHigh' },
            { prefix: 'low', toggle: 'sectorLow' },
        ];
        sectorTiers.forEach(function(tier) {
            var inclId = tier.prefix + '-sector-search-include';
            var exclId = tier.prefix + '-sector-search-exclude';
            if (map.getLayer(inclId)) {
                map.setFilter(inclId, highlightToggles[tier.toggle] ? sInclFilter : emptySector);
                map.setFilter(exclId, highlightToggles[tier.toggle] ? sExclFilter : emptySector);
            }
        });
    }

    /**
     * Highlight origin/dest/traversed ARTCC boundaries when a play is selected.
     * Uses separate map layers with distinct fill colors.
     */
    function updatePlayHighlights(play, routes) {
        var map = window.graphic_map;
        if (!map || !map.getLayer || !map.getLayer('artcc-play-origin')) return;

        if (!play || !routes || !routes.length) {
            // Clear all play highlights
            map.setFilter('artcc-play-origin', ['in', 'ICAOCODE', '']);
            map.setFilter('artcc-play-dest', ['in', 'ICAOCODE', '']);
            map.setFilter('artcc-play-traversed', ['in', 'ICAOCODE', '']);
            return;
        }

        var origCodes = new Set(), destCodes = new Set(), travCodes = new Set();
        routes.forEach(function(r) {
            csvSplit(r.origin_artccs).forEach(function(c) { if (c) origCodes.add(toIcaoCode(c)); });
            csvSplit(r.dest_artccs).forEach(function(c) { if (c) destCodes.add(toIcaoCode(c)); });
            csvSplit(r.traversed_artccs).forEach(function(c) { if (c) travCodes.add(toIcaoCode(c)); });
        });

        // Remove origin/dest from traversed to avoid triple-fill
        origCodes.forEach(function(c) { travCodes.delete(c); });
        destCodes.forEach(function(c) { travCodes.delete(c); });

        var origArr = Array.from(origCodes);
        var destArr = Array.from(destCodes);
        var travArr = Array.from(travCodes);

        var emptyFilter = ['in', 'ICAOCODE', ''];
        map.setFilter('artcc-play-origin', (highlightToggles.playOrigin && origArr.length) ? ['in', 'ICAOCODE'].concat(origArr) : emptyFilter);
        map.setFilter('artcc-play-dest', (highlightToggles.playDest && destArr.length) ? ['in', 'ICAOCODE'].concat(destArr) : emptyFilter);
        map.setFilter('artcc-play-traversed', (highlightToggles.playTraversed && travArr.length) ? ['in', 'ICAOCODE'].concat(travArr) : emptyFilter);
    }

    // =========================================================================
    // SEARCH FILTER BADGES — colored by DCC region
    // =========================================================================

    function renderFilterBadges(clauses) {
        var container = $('#pb_filter_badges');
        if (!clauses.length) { container.empty(); return; }
        var hasFH = typeof FacilityHierarchy !== 'undefined' && FacilityHierarchy.isLoaded;
        var html = '';

        clauses.forEach(function(alts) {
            alts.forEach(function(alt) {
                var prefix = '';
                if (alt.qualifier === 'orig') prefix = 'ORIG: ';
                else if (alt.qualifier === 'dest') prefix = 'DEST: ';
                else if (alt.qualifier === 'avoid') prefix = 'AVOID: ';
                else if (alt.qualifier === 'thru') prefix = 'THRU: ';

                var label = (alt.negated ? '-' : '') + prefix + alt.term;

                // Badge FILL = DCC region bg color; BORDER = green (incl) or red (excl)
                var bgStyle = '';
                if (hasFH) {
                    var regionBg = FacilityHierarchy.getRegionBgColor(alt.term);
                    var regionColor = FacilityHierarchy.getRegionColor(alt.term);
                    // Sector codes (ZNY56, CZEGBA, CZQMCHARLO) → try parent ARTCC/FIR prefix
                    if (!regionBg && alt.term.length > 3) {
                        var prefix4 = alt.term.substring(0, 4);
                        var prefix3 = alt.term.substring(0, 3);
                        if (alt.term.length > 4) {
                            regionBg = FacilityHierarchy.getRegionBgColor(prefix4);
                            regionColor = FacilityHierarchy.getRegionColor(prefix4);
                        }
                        if (!regionBg) {
                            regionBg = FacilityHierarchy.getRegionBgColor(prefix3);
                            regionColor = FacilityHierarchy.getRegionColor(prefix3);
                        }
                    }
                    if (regionBg) bgStyle = 'background:' + regionBg + ';color:' + (regionColor || '#495057') + ';';
                }

                var borderColor = alt.negated ? '#dc3545' : '#28a745';
                var style = bgStyle + 'border-color:' + borderColor + ';';

                var cls = 'pb-filter-badge' + (alt.negated ? ' pb-filter-badge-negated' : '');
                html += '<span class="' + cls + '" style="' + style + '">' + escHtml(label) + '</span>';
            });
        });
        container.html(html);
    }

    // =========================================================================
    // TRACON/ARTCC CLASSIFICATION — for explicit origin/dest fields
    // =========================================================================

    function resolveTraconAlias(code) {
        return TRACON_ALIASES[code] || code;
    }

    function classifyOriginDest(fieldValue) {
        var airports = [], tracons = [], artccs = [];
        var hasFH = typeof FacilityHierarchy !== 'undefined' && FacilityHierarchy.isLoaded;
        (fieldValue || '').split(/[\s,\/]+/).filter(Boolean).forEach(function(tok) {
            tok = tok.toUpperCase();
            var resolved = resolveTraconAlias(tok);
            if (hasFH && FacilityHierarchy.isArtcc(resolved)) {
                artccs.push(resolved);
            } else if (hasFH && FacilityHierarchy.isTracon(resolved)) {
                tracons.push(resolved);
                var parent = FacilityHierarchy.getParentArtcc(resolved);
                if (parent) artccs.push(parent);
            } else if (/^[A-Z][0-9]{2}$/.test(tok)) {
                // Regex fallback for TRACON codes (letter + 2 digits)
                tracons.push(resolved);
                if (hasFH) {
                    var parent = FacilityHierarchy.getParentArtcc(resolved);
                    if (parent) artccs.push(parent);
                }
            } else if (/^(Z[A-Z]{2}|CZ[A-Z])$/.test(tok)) {
                // Regex fallback for ARTCC/FIR codes
                artccs.push(tok);
            } else if (/^[A-Z]{4}$/.test(tok) && !/^(Z[A-Z]{2}|CZ[A-Z])$/.test(tok.substring(0,3))) {
                // 4-letter ICAO airport
                airports.push(tok);
                if (hasFH) {
                    var tracon = FacilityHierarchy.AIRPORT_TO_TRACON ? FacilityHierarchy.AIRPORT_TO_TRACON[tok] : null;
                    if (tracon) tracons.push(tracon);
                    var artcc = FacilityHierarchy.getParentArtcc(tok);
                    if (artcc) artccs.push(artcc);
                }
            } else if (/^[A-Z]{3}$/.test(tok)) {
                // 3-letter FAA airport code — check if it's a TRACON first
                if (hasFH && FacilityHierarchy.isTracon(tok)) {
                    tracons.push(tok);
                    var parent = FacilityHierarchy.getParentArtcc(tok);
                    if (parent) artccs.push(parent);
                } else {
                    // Could be FAA airport ID — try to resolve parent
                    airports.push(tok);
                    if (hasFH) {
                        var artcc = FacilityHierarchy.getParentArtcc(tok);
                        if (artcc) artccs.push(artcc);
                    }
                }
            }
        });
        return { airports: unique(airports), tracons: unique(tracons), artccs: unique(artccs) };
    }

    // =========================================================================
    // REGION COLORING — wrap facility codes with DCC region colors
    // =========================================================================

    function regionColorWrap(code) {
        if (!regionColorEnabled || typeof FacilityHierarchy === 'undefined' || !FacilityHierarchy.isLoaded) {
            return escHtml(code);
        }
        var color = FacilityHierarchy.getRegionColor(code);
        var bg = FacilityHierarchy.getRegionBgColor(code);
        if (!color) return escHtml(code);
        return '<span class="pb-region-code" style="color:' + color + ';background:' + bg + ';">' + escHtml(code) + '</span>';
    }

    function renderFacilityCodes(str, separator) {
        if (!str) return '-';
        var sep = separator || /[,\/]/;
        var codes = str.split(sep).map(function(c) { return c.trim(); }).filter(Boolean);
        if (!codes.length) return '-';
        var joinSep = (separator === '/') ? '/<wbr>' : ', ';
        if (!regionColorEnabled) return escHtml(codes.join(separator === '/' ? '/' : ', ')).replace(/\//g, '/<wbr>');
        return codes.map(regionColorWrap).join(joinSep);
    }

    // =========================================================================
    // CATEGORY PILLS
    // =========================================================================

    function loadCategories() {
        $.getJSON(API_CATS, function(data) {
            if (!data || !data.success) return;
            categoryData = data;
            renderCategoryPills();
            populateCategoryDropdown();
        });
    }

    function populateCategoryDropdown() {
        var $sel = $('#pb_edit_category');
        var current = $sel.val() || '';
        $sel.find('option:not(:first)').remove();

        var cats = (categoryData.categories || []).slice();
        cats.sort(function(a, b) {
            var ia = FAA_CATEGORY_ORDER.indexOf(a);
            var ib = FAA_CATEGORY_ORDER.indexOf(b);
            if (ia === -1 && ib === -1) return a.localeCompare(b);
            if (ia === -1) return 1;
            if (ib === -1) return -1;
            return ia - ib;
        });

        cats.forEach(function(c) {
            $sel.append('<option value="' + escHtml(c) + '">' + escHtml(c) + '</option>');
        });
        $sel.append('<option value="__custom__">Custom...</option>');

        if (current) $sel.val(current);
    }

    function renderCategoryPills() {
        var container = $('#pb_category_pills');
        var counts = categoryData.category_counts || {};
        var cats = categoryData.categories || [];

        // Sort categories by FAA order; unknown categories go to the end alphabetically
        cats.sort(function(a, b) {
            var ia = FAA_CATEGORY_ORDER.indexOf(a);
            var ib = FAA_CATEGORY_ORDER.indexOf(b);
            if (ia === -1 && ib === -1) return a.localeCompare(b);
            if (ia === -1) return 1;
            if (ib === -1) return -1;
            return ia - ib;
        });

        // Total active plays
        var total = 0;
        for (var k in counts) total += counts[k];

        var html = '<span class="pb-pill' + (activeCategory === '' ? ' active' : '') + '" data-cat="">' +
                   t('playbook.allPlays') + ' <span class="pb-pill-count">' + total + '</span></span>';

        cats.forEach(function(cat) {
            var cnt = counts[cat] || 0;
            html += '<span class="pb-pill' + (activeCategory === cat ? ' active' : '') + '" data-cat="' + escHtml(cat) + '">' +
                    escHtml(cat) + ' <span class="pb-pill-count">' + cnt + '</span></span>';
        });

        container.html(html);
    }

    // =========================================================================
    // PLAY LOADING & CLIENT-SIDE FILTERING
    // =========================================================================

    function loadPlays() {
        var params = {
            per_page: 10000,
            hide_legacy: showLegacy ? 0 : 1
        };

        // When Legacy source is active, pass source filter and disable hide_legacy
        if (activeSource === 'FAA_HISTORICAL') {
            params.source = 'FAA_HISTORICAL';
            params.hide_legacy = 0;
        }

        currentPage = 1;

        var qstr = Object.keys(params).map(function(k) {
            return params[k] !== '' ? k + '=' + encodeURIComponent(params[k]) : '';
        }).filter(Boolean).join('&');

        $('#pb_play_list_container').html(
            '<div class="pb-loading"><div class="spinner-border text-primary" role="status"></div></div>'
        );

        $.getJSON(API_LIST + '?' + qstr, function(data) {
            if (!data || !data.success) {
                $('#pb_play_list_container').html(
                    '<div class="pb-empty-state"><i class="fas fa-exclamation-triangle"></i>' + t('common.error') + '</div>'
                );
                return;
            }

            allPlays = data.data || [];
            // Invalidate search indexes on reload
            allPlays.forEach(function(p) { delete p._searchText; delete p._facilityCodes; delete p._originCodes; delete p._destCodes; delete p._traversedCodes; });
            applyFilters();

            // Auto-open play from URL ?play=NAME on initial load
            if (!activePlayId) {
                var urlPlay = getUrlPlayName();
                if (urlPlay) {
                    var norm = urlPlay.toUpperCase().replace(/[^A-Z0-9]/g, '');
                    var match = allPlays.find(function(p) {
                        return p.play_name_norm === norm || (p.play_name || '').toUpperCase() === urlPlay.toUpperCase();
                    });
                    if (match) {
                        loadPlayDetail(match.play_id);
                    } else {
                        // Play might be legacy/hidden — search API for it
                        $.getJSON(API_LIST + '?search=' + encodeURIComponent(urlPlay) + '&status=&per_page=5', function(sr) {
                            if (sr && sr.success && sr.data && sr.data.length) {
                                var m = sr.data.find(function(p) {
                                    return p.play_name_norm === norm || (p.play_name || '').toUpperCase() === urlPlay.toUpperCase();
                                });
                                if (m) loadPlayDetail(m.play_id);
                            }
                        });
                    }
                }
            }
        }).fail(function() {
            $('#pb_play_list_container').html(
                '<div class="pb-empty-state"><i class="fas fa-exclamation-triangle"></i>' + t('common.error') + '</div>'
            );
        });
    }

    function applyFilters() {
        currentPage = 1;
        currentSearchClauses = parseSearch(searchText);

        filteredPlays = allPlays.filter(function(p) {
            // Category filter
            if (activeCategory && p.category !== activeCategory) return false;
            // Source filter
            if (activeSource && p.source !== activeSource) return false;
            // Multi-token boolean search
            if (currentSearchClauses.length && !matchesSearch(p, currentSearchClauses)) return false;
            return true;
        });

        filteredPlays.sort(function(a, b) {
            return (a.play_name || '').localeCompare(b.play_name || '');
        });

        $('#pb_stats').text(t('playbook.showingPlays', { count: filteredPlays.length }));
        if (!showLegacy && categoryData.legacy_count) {
            $('#pb_stats').append(' <span style="opacity:0.6;">(' + t('playbook.legacyHidden', { count: categoryData.legacy_count }) + ')</span>');
        }

        updateMapHighlights(currentSearchClauses);
        renderFilterBadges(currentSearchClauses);
        renderPlayList();

        // Re-apply route emphasis if a play is currently selected
        if (activePlayData) {
            applyRouteEmphasis();
            plotOnMap();
        }
    }

    function renderPlayList() {
        if (!filteredPlays.length) {
            $('#pb_play_list_container').html(
                '<div class="pb-empty-state"><i class="fas fa-book-open"></i>' + t('playbook.noPlaysFound') + '</div>'
            );
            return;
        }

        var totalPages = Math.ceil(filteredPlays.length / playsPerPage);
        if (currentPage > totalPages) currentPage = totalPages;
        if (currentPage < 1) currentPage = 1;
        var startIdx = (currentPage - 1) * playsPerPage;
        var pageSlice = filteredPlays.slice(startIdx, startIdx + playsPerPage);

        var html = '<ul class="pb-play-list">';
        pageSlice.forEach(function(p) {
            var isActive = p.play_id == activePlayId;
            html += '<li class="pb-play-row' + (isActive ? ' active' : '') + '" data-play-id="' + p.play_id + '">';
            html += '<span class="pb-play-row-name">' + escHtml(p.play_name) + '</span>';
            html += '<span class="pb-play-row-meta">';
            if (p.category) html += '<span class="pb-badge pb-badge-category">' + escHtml(p.category) + '</span>';
            html += '<span class="pb-badge pb-badge-routes">' + (p.route_count || 0) + '</span>';
            var srcLabel = p.source === 'FAA_HISTORICAL' ? 'Legacy' : (p.source || 'DCC');
            html += '<span class="pb-badge pb-badge-' + (p.source || 'dcc').toLowerCase() + '">' + escHtml(srcLabel) + '</span>';
            if (p.status === 'draft') html += '<span class="pb-badge pb-badge-draft">' + t('playbook.statusDraft') + '</span>';
            html += '</span>';
            html += '</li>';
        });
        html += '</ul>';

        // Pagination controls
        if (totalPages > 1) {
            html += '<div class="pb-pagination">';
            html += '<button class="btn btn-xs btn-outline-secondary pb-page-btn" data-page="prev"' + (currentPage <= 1 ? ' disabled' : '') + '><i class="fas fa-chevron-left"></i></button>';
            var startPage = Math.max(1, currentPage - 2);
            var endPage = Math.min(totalPages, startPage + 4);
            if (endPage - startPage < 4) startPage = Math.max(1, endPage - 4);
            for (var pg = startPage; pg <= endPage; pg++) {
                html += '<button class="btn btn-xs pb-page-btn' + (pg === currentPage ? ' btn-primary' : ' btn-outline-secondary') + '" data-page="' + pg + '">' + pg + '</button>';
            }
            html += '<button class="btn btn-xs btn-outline-secondary pb-page-btn" data-page="next"' + (currentPage >= totalPages ? ' disabled' : '') + '><i class="fas fa-chevron-right"></i></button>';
            html += '</div>';
        }

        $('#pb_play_list_container').html(html);
    }

    // =========================================================================
    // PLAY DETAIL
    // =========================================================================

    function loadPlayDetail(playId) {
        activePlayId = playId;
        selectedRouteIds.clear();
        routeGroups = [];

        // Highlight active row
        $('.pb-play-row').removeClass('active');
        $('[data-play-id="' + playId + '"]').addClass('active');

        var content = $('#pb_detail_content');
        content.html('<div class="pb-loading py-2"><div class="spinner-border spinner-border-sm text-primary"></div></div>');

        // Show info overlay with loading spinner
        $('#pb_info_title').html('<i class="fas fa-spinner fa-spin"></i> Loading...');
        $('#pb_info_content').html('<div class="pb-loading py-2"><div class="spinner-border spinner-border-sm text-primary"></div></div>');
        $('#pb_info_overlay').show();

        $.getJSON(API_GET + '?id=' + playId, function(data) {
            if (!data || !data.success) {
                content.html('<div class="text-danger small">' + t('common.error') + '</div>');
                return;
            }

            var play = data.play;
            var routes = data.routes || [];
            play.routes = routes;
            activePlayData = play;

            updateUrl(play.play_name);

            // Load groups then render
            loadGroups(playId, function() {
                renderDetailPanel(play, routes);
                plotOnMap();
            });
        });
    }

    function buildSelectionOptions(routes) {
        var origins = { airports: new Set(), tracons: new Set(), artccs: new Set() };
        var dests   = { airports: new Set(), tracons: new Set(), artccs: new Set() };
        var regions = new Set();

        routes.forEach(function(r) {
            csvSplit(r.origin_airports).forEach(function(a) { if (a) origins.airports.add(a); });
            csvSplit(r.origin_tracons).forEach(function(a) { if (a) origins.tracons.add(a); });
            csvSplit(r.origin_artccs).forEach(function(a) {
                if (a) {
                    origins.artccs.add(a);
                    if (typeof FacilityHierarchy !== 'undefined' && FacilityHierarchy.getRegion) {
                        var reg = FacilityHierarchy.getRegion(a);
                        if (reg) regions.add(reg);
                    }
                }
            });
            // Also pick up raw origin field for TRACON codes missed by auto-compute
            if (r.origin && !r.origin_airports && !r.origin_artccs) {
                var cls = classifyOriginDest(r.origin);
                cls.tracons.forEach(function(a) { origins.tracons.add(a); });
                cls.artccs.forEach(function(a) {
                    origins.artccs.add(a);
                    if (typeof FacilityHierarchy !== 'undefined' && FacilityHierarchy.getRegion) {
                        var reg = FacilityHierarchy.getRegion(a);
                        if (reg) regions.add(reg);
                    }
                });
            }
            csvSplit(r.dest_airports).forEach(function(a) { if (a) dests.airports.add(a); });
            csvSplit(r.dest_tracons).forEach(function(a) { if (a) dests.tracons.add(a); });
            csvSplit(r.dest_artccs).forEach(function(a) {
                if (a) {
                    dests.artccs.add(a);
                    if (typeof FacilityHierarchy !== 'undefined' && FacilityHierarchy.getRegion) {
                        var reg = FacilityHierarchy.getRegion(a);
                        if (reg) regions.add(reg);
                    }
                }
            });
        });

        return { origins: origins, dests: dests, regions: regions };
    }

    function buildCheckboxDropdown(id, label, facilitySet) {
        var groups = {};
        var artccs = Array.from(facilitySet.artccs).sort();
        var tracons = Array.from(facilitySet.tracons).sort();
        var airports = Array.from(facilitySet.airports).sort();
        if (artccs.length) groups['ARTCCs'] = artccs.map(function(c) { return 'artcc:' + c; });
        if (tracons.length) groups['TRACONs'] = tracons.map(function(c) { return 'tracon:' + c; });
        if (airports.length) groups['Airports'] = airports.map(function(c) { return 'airport:' + c; });

        var html = '<div class="pb-cb-dropdown" id="' + id + '">';
        html += '<button type="button" class="pb-cb-trigger">';
        html += escHtml(label) + ' <span class="pb-cb-count"></span>';
        html += '<i class="fas fa-caret-down ml-1"></i></button>';
        html += '<div class="pb-cb-menu">';
        Object.keys(groups).forEach(function(groupName) {
            var items = groups[groupName];
            if (!items.length) return;
            html += '<div class="pb-cb-group-label">' + escHtml(groupName) + '</div>';
            items.forEach(function(val) {
                var code = val.split(':').pop();
                var displayCode = regionColorEnabled ? regionColorWrap(code) : escHtml(code);
                html += '<label class="pb-cb-item"><input type="checkbox" value="' + escHtml(val) + '"> ' + displayCode + '</label>';
            });
        });
        html += '</div></div>';
        return html;
    }

    function buildRegionDropdown(id, label, regionsSet) {
        var regions = Array.from(regionsSet).sort();
        if (!regions.length) return '';
        var html = '<div class="pb-cb-dropdown" id="' + id + '">';
        html += '<button type="button" class="pb-cb-trigger">';
        html += escHtml(label) + ' <span class="pb-cb-count"></span>';
        html += '<i class="fas fa-caret-down ml-1"></i></button>';
        html += '<div class="pb-cb-menu">';
        regions.forEach(function(r) {
            html += '<label class="pb-cb-item"><input type="checkbox" value="' + escHtml(r) + '"> ' + escHtml(r) + '</label>';
        });
        html += '</div></div>';
        return html;
    }

    function renderInfoOverlay(play, routes) {
        // Set overlay title
        var titleHtml = '<i class="fas fa-route" style="color:#239BCD;"></i> ' + escHtml(play.play_name);
        if (play.display_name && play.display_name !== play.play_name) {
            titleHtml += ' <span style="font-size:0.72rem;color:#777;font-weight:400;">' + escHtml(play.display_name) + '</span>';
        }
        $('#pb_info_title').html(titleHtml);

        var html = '';

        // Action buttons
        html += '<div class="pb-actions mb-1" style="flex-wrap:wrap;">';
        html += '<button class="btn btn-outline-info btn-sm" id="pb_copy_link_btn"><i class="fas fa-link mr-1"></i>Copy Link</button>';
        html += '<button class="btn btn-warning btn-sm" id="pb_activate_btn"><i class="fas fa-paper-plane mr-1"></i>' + t('playbook.activateReroute') + '</button>';
        if (hasPerm) {
            html += '<button class="btn btn-outline-primary btn-sm" id="pb_duplicate_btn"><i class="fas fa-copy mr-1"></i>Duplicate</button>';
        }
        if (hasPerm && play.source !== 'FAA' && play.source !== 'FAA_HISTORICAL') {
            html += '<button class="btn btn-outline-secondary btn-sm" id="pb_edit_btn"><i class="fas fa-edit mr-1"></i>' + t('common.edit') + '</button>';
        }
        if (hasPerm) {
            if (play.status === 'active' || play.status === 'draft') {
                html += '<button class="btn btn-outline-danger btn-sm" id="pb_archive_btn"><i class="fas fa-archive mr-1"></i>' + t('playbook.archive') + '</button>';
            } else if (play.status === 'archived') {
                html += '<button class="btn btn-outline-success btn-sm" id="pb_restore_btn"><i class="fas fa-undo mr-1"></i>' + t('playbook.restore') + '</button>';
            }
        }
        html += '</div>';

        // Metadata badges
        var metaParts = [];
        if (play.category) metaParts.push('<span class="pb-badge pb-badge-category">' + escHtml(play.category) + '</span>');
        if (play.scenario_type) metaParts.push('<span class="badge badge-secondary" style="font-size:0.65rem;">' + escHtml(play.scenario_type) + '</span>');
        if (play.source) {
            var detailSrcLabel = play.source === 'FAA_HISTORICAL' ? 'Legacy' : play.source;
            metaParts.push('<span class="pb-badge pb-badge-' + (play.source || 'dcc').toLowerCase() + '">' + escHtml(detailSrcLabel) + '</span>');
        }
        if (play.airac_cycle) metaParts.push('<span style="font-size:0.65rem;color:#888;">AIRAC ' + escHtml(play.airac_cycle) + '</span>');
        if (metaParts.length) {
            html += '<div class="pb-detail-meta">' + metaParts.join('') + '</div>';
        }

        // Description
        if (play.description) {
            html += '<div class="pb-play-description">' + escHtml(play.description) + '</div>';
        }

        // Play-level remarks
        if (play.remarks) {
            html += '<div class="pb-play-remarks"><strong>' + t('playbook.remarks') + ':</strong> ' + escHtml(play.remarks) + '</div>';
        }

        // Facilities (inline-editable with optional region coloring)
        var facStr = play.impacted_area || play.facilities_involved || '';
        html += '<div class="pb-play-facilities">';
        html += '<strong>' + t('playbook.legendTitle') + ':</strong> ';
        html += '<span class="pb-fac-display" id="pb_fac_display">' + (facStr ? renderFacilityCodes(facStr, '/') : '<span class="text-muted">' + t('common.none') + '</span>') + '</span>';
        if (hasPerm && play.source !== 'FAA' && play.source !== 'FAA_HISTORICAL') {
            html += ' <button class="btn btn-xs pb-inline-edit-btn" id="pb_fac_edit_btn" title="' + t('playbook.editFacilities') + '"><i class="fas fa-pencil-alt"></i></button>';
            html += '<div class="pb-fac-edit-wrap" id="pb_fac_edit_wrap" style="display:none;">';
            html += '<input type="text" class="form-control form-control-sm pb-fac-input" id="pb_fac_input" value="' + escHtml(facStr) + '" placeholder="ZNY/ZOB/ZAU...">';
            html += '<div class="pb-fac-edit-actions">';
            html += '<button class="btn btn-xs btn-primary" id="pb_fac_save"><i class="fas fa-check"></i></button>';
            html += '<button class="btn btn-xs btn-secondary" id="pb_fac_cancel"><i class="fas fa-times"></i></button>';
            html += '</div></div>';
        }
        html += ' <label class="pb-region-toggle mb-0" title="' + t('playbook.regionColorsTooltip') + '"><input type="checkbox" id="pb_region_color_toggle"' + (regionColorEnabled ? ' checked' : '') + '><span class="small">' + t('playbook.regionColors') + '</span></label>';
        html += '</div>';

        // Included Traffic summary (with optional region coloring)
        if (routes.length) {
            var origSet = new Set(), destSet = new Set();
            var travArtccs = new Set(), travTracons = new Set();
            var travSecLow = new Set(), travSecHigh = new Set(), travSecSuper = new Set();
            routes.forEach(function(r) {
                csvSplit(r.origin_airports).forEach(function(a) { if (a) origSet.add(a.toUpperCase()); });
                csvSplit(r.origin_artccs).forEach(function(a) { if (a) origSet.add(a.toUpperCase()); });
                csvSplit(r.dest_airports).forEach(function(a) { if (a) destSet.add(a.toUpperCase()); });
                csvSplit(r.dest_artccs).forEach(function(a) { if (a) destSet.add(a.toUpperCase()); });
                csvSplit(r.traversed_artccs).forEach(function(a) { if (a) travArtccs.add(a.toUpperCase()); });
                csvSplit(r.traversed_tracons).forEach(function(a) { if (a) travTracons.add(a.toUpperCase()); });
                csvSplit(r.traversed_sectors_low).forEach(function(a) { if (a) travSecLow.add(a.toUpperCase()); });
                csvSplit(r.traversed_sectors_high).forEach(function(a) { if (a) travSecHigh.add(a.toUpperCase()); });
                csvSplit(r.traversed_sectors_superhigh).forEach(function(a) { if (a) travSecSuper.add(a.toUpperCase()); });
            });
            var origArr = Array.from(origSet).sort();
            var destArr = Array.from(destSet).sort();
            if (origArr.length || destArr.length) {
                html += '<div class="pb-play-traffic"><strong>' + t('playbook.includedTraffic') + ':</strong> ';
                if (origArr.length) {
                    html += origArr.map(regionColorWrap).join('/<wbr>') + ' ' + t('playbook.departures');
                }
                if (origArr.length && destArr.length) html += ' ' + t('playbook.to') + ' ';
                if (destArr.length) {
                    if (!origArr.length) html += t('playbook.arrivalsTo') + ' ';
                    html += destArr.map(regionColorWrap).join('/<wbr>');
                }
                html += '</div>';
            }

            // Traversed facilities summary (collapsible)
            var travArtccArr = Array.from(travArtccs).sort();
            var travTraconArr = Array.from(travTracons).sort();
            var travSecLowArr = Array.from(travSecLow).sort();
            var travSecHighArr = Array.from(travSecHigh).sort();
            var travSecSuperArr = Array.from(travSecSuper).sort();
            var hasTrav = travArtccArr.length || travTraconArr.length || travSecLowArr.length || travSecHighArr.length || travSecSuperArr.length;
            if (hasTrav) {
                html += '<div class="pb-play-traversed">';
                html += '<div class="pb-traversed-header" id="pb_traversed_toggle"><i class="fas fa-chevron-right"></i> <strong>' + t('playbook.traversedFacilities') + '</strong> <span class="text-muted small">(' + travArtccArr.length + ' ' + t('playbook.traversedArtccs');
                if (travTraconArr.length) html += ', ' + travTraconArr.length + ' ' + t('playbook.traversedTracons');
                html += ')</span></div>';
                html += '<div class="pb-traversed-body" id="pb_traversed_body" style="display:none;">';
                if (travArtccArr.length) {
                    html += '<div class="pb-trav-row"><span class="pb-trav-label">' + t('playbook.traversedArtccs') + ':</span> ' + travArtccArr.map(regionColorWrap).join(', ') + '</div>';
                }
                if (travTraconArr.length) {
                    html += '<div class="pb-trav-row"><span class="pb-trav-label">' + t('playbook.traversedTracons') + ':</span> ' + travTraconArr.map(function(c) { return '<span class="pb-fac-code">' + escHtml(c) + '</span>'; }).join(', ') + '</div>';
                }
                if (travSecSuperArr.length) {
                    html += '<div class="pb-trav-row"><span class="pb-trav-label">' + t('playbook.traversedSectorsSuperhigh') + ':</span> ' + travSecSuperArr.map(function(c) { return '<span class="pb-fac-code">' + escHtml(c) + '</span>'; }).join(', ') + '</div>';
                }
                if (travSecHighArr.length) {
                    html += '<div class="pb-trav-row"><span class="pb-trav-label">' + t('playbook.traversedSectorsHigh') + ':</span> ' + travSecHighArr.map(function(c) { return '<span class="pb-fac-code">' + escHtml(c) + '</span>'; }).join(', ') + '</div>';
                }
                if (travSecLowArr.length) {
                    html += '<div class="pb-trav-row"><span class="pb-trav-label">' + t('playbook.traversedSectorsLow') + ':</span> ' + travSecLowArr.map(function(c) { return '<span class="pb-fac-code">' + escHtml(c) + '</span>'; }).join(', ') + '</div>';
                }
                html += '</div></div>';
            }
        }

        // Select By toolbar
        if (routes.length) {
            var selOpts = buildSelectionOptions(routes);
            html += '<hr style="margin:0.4rem 0;">';
            html += '<div class="pb-select-toolbar">';
            html += '<span class="pb-select-label">' + t('playbook.selectBy') + '</span>';
            html += buildCheckboxDropdown('pb_select_origin', t('playbook.origin'), selOpts.origins);
            html += buildCheckboxDropdown('pb_select_dest', t('playbook.destination'), selOpts.dests);
            if (selOpts.regions.size) {
                html += buildRegionDropdown('pb_select_region', t('playbook.region'), selOpts.regions);
            }
            html += '<input type="text" class="form-control form-control-sm pb-select-route-text" id="pb_select_route_text" placeholder="' + t('playbook.routeTextPlaceholder') + '">';
            html += '<button class="btn btn-xs btn-outline-danger pb-clear-filters" id="pb_clear_filters" title="' + t('playbook.clearFilters') + '"><i class="fas fa-times"></i></button>';
            html += '</div>';

            // Route action toolbar
            html += '<div class="pb-route-toolbar" id="pb_route_toolbar">';
            html += '<button class="btn btn-xs btn-outline-primary" id="pb_open_route_page" title="' + t('playbook.openRoutePage') + '"><i class="fas fa-route mr-1"></i>' + t('playbook.openRoutePage') + '</button>';
            html += '<button class="btn btn-xs btn-outline-info" id="pb_activate_reroute" title="' + t('playbook.useInTMI') + '"><i class="fas fa-paper-plane mr-1"></i>' + t('playbook.useInTMI') + '</button>';
            html += '<span class="pb-toolbar-sep">|</span>';
            html += '<button class="btn btn-xs btn-outline-secondary" id="pb_copy_pb_directive" title="' + t('playbook.copyPB') + '"><i class="fas fa-clipboard mr-1"></i>' + t('playbook.copyPB') + '</button>';
            html += '</div>';
        }

        $('#pb_info_content').html(html);
        $('#pb_info_overlay').show();
    }

    function renderRouteTable(play, routes) {
        var html = '';
        var hasSearch = currentSearchClauses.length > 0;
        var hasGroups = routeGroups.length > 0;

        // Group toolbar container
        html += '<div id="pb_group_toolbar"></div>';

        if (routes.length) {
            // Select All row + route count
            html += '<div class="d-flex justify-content-between align-items-center mb-1">';
            html += '<span class="pb-select-all" id="pb_select_all">' + t('playbook.selectAll') + '</span>';
            html += '<span style="font-size:0.68rem;color:#999;">' + routes.length + ' ' + t('playbook.routes').toLowerCase() + '</span>';
            html += '</div>';

            // Sort routes: grouped first (by group order), ungrouped last
            var sortedRoutes = routes.slice();
            if (hasGroups) {
                sortedRoutes.sort(function(a, b) {
                    var ga = getRouteGroupIndex(a.route_id);
                    var gb = getRouteGroupIndex(b.route_id);
                    if (ga === -1 && gb === -1) return 0;
                    if (ga === -1) return 1;
                    if (gb === -1) return -1;
                    return ga - gb;
                });
            }

            html += '<div class="pb-route-table-wrap">';
            html += '<table class="pb-route-table"><thead><tr>';
            html += '<th class="pb-route-check"><input type="checkbox" id="pb_check_all"></th>';
            if (hasGroups) html += '<th style="width:6px;padding:0;"></th>';
            html += '<th>Origin</th>';
            html += '<th>TRACON</th>';
            html += '<th>ARTCC</th>';
            html += '<th>' + t('playbook.routeString') + '</th>';
            html += '<th>Dest</th>';
            html += '<th>TRACON</th>';
            html += '<th>ARTCC</th>';
            html += '<th>Traversed</th>';
            html += '</tr></thead><tbody>';

            sortedRoutes.forEach(function(r) {
                var origApt = r.origin_airports || r.origin || '-';
                var origTracon = r.origin_tracons || '-';
                var origArtcc = r.origin_artccs || '-';
                var destApt = r.dest_airports || r.dest || '-';
                var destTracon = r.dest_tracons || '-';
                var destArtcc = r.dest_artccs || '-';

                // If origin has unclassified TRACONs, resolve on the fly
                if (origTracon === '-' && r.origin && /^[A-Z][0-9]{2}/.test(r.origin)) {
                    var cls = classifyOriginDest(r.origin);
                    if (cls.tracons.length) origTracon = cls.tracons.join(',');
                    if (cls.artccs.length && origArtcc === '-') origArtcc = cls.artccs.join(',');
                }
                if (destTracon === '-' && r.dest && /^[A-Z][0-9]{2}/.test(r.dest)) {
                    var cls = classifyOriginDest(r.dest);
                    if (cls.tracons.length) destTracon = cls.tracons.join(',');
                    if (cls.artccs.length && destArtcc === '-') destArtcc = cls.artccs.join(',');
                }

                // Search emphasis classes
                var searchMatch = !hasSearch || routeMatchesSearchClauses(r, currentSearchClauses);
                var rowClasses = [];
                if (hasSearch) rowClasses.push(searchMatch ? 'pb-route-emphasized' : 'pb-route-dimmed');

                // Group indicator
                var groupColor = getRouteGroupColor(r.route_id);
                if (groupColor) rowClasses.push('pb-route-grouped');

                var rowClass = rowClasses.join(' ');

                html += '<tr data-route-id="' + r.route_id + '"' + (rowClass ? ' class="' + rowClass + '"' : '') + (groupColor ? ' style="border-left:4px solid ' + groupColor + ';"' : '') + '>';
                html += '<td class="pb-route-check"><input type="checkbox" class="pb-route-cb" value="' + r.route_id + '"' + (selectedRouteIds.has(r.route_id) ? ' checked' : '') + '></td>';
                if (hasGroups) {
                    html += '<td style="padding:0 2px;">' + (groupColor ? '<span class="pb-group-dot-inline" style="background:' + groupColor + ';"></span>' : '') + '</td>';
                }
                html += '<td>' + escHtml(origApt) + (r.origin_filter ? ' <small class="text-muted">' + escHtml(r.origin_filter) + '</small>' : '') + '</td>';
                html += '<td>' + renderFacilityCodes(origTracon, ',') + '</td>';
                html += '<td>' + renderFacilityCodes(origArtcc, ',') + '</td>';
                html += '<td>' + escHtml(r.route_string) + '</td>';
                html += '<td>' + escHtml(destApt) + (r.dest_filter ? ' <small class="text-muted">' + escHtml(r.dest_filter) + '</small>' : '') + '</td>';
                html += '<td>' + renderFacilityCodes(destTracon, ',') + '</td>';
                html += '<td>' + renderFacilityCodes(destArtcc, ',') + '</td>';
                html += '<td>' + renderFacilityCodes(r.traversed_artccs || '-', ',') + '</td>';
                html += '</tr>';
            });

            html += '</tbody></table></div>';
        } else {
            html += '<div class="pb-empty-state"><i class="fas fa-route"></i>' + t('playbook.noRoutes') + '</div>';
        }

        // Changelog toggle
        html += '<div class="pb-changelog">';
        html += '<div class="pb-changelog-header" id="pb_changelog_toggle"><i class="fas fa-chevron-right"></i> ' + t('playbook.changelog') + '</div>';
        html += '<div id="pb_changelog_content" style="display:none;"></div>';
        html += '</div>';

        $('#pb_detail_content').html(html);
        renderGroupToolbar();
        updateToolbarVisibility();
    }

    function renderDetailPanel(play, routes) {
        renderInfoOverlay(play, routes);
        renderRouteTable(play, routes);
        updatePlayHighlights(play, routes);
        $('#pb_map_legend').show();
    }

    function updateToolbarVisibility() {
        // Toolbar always visible; no-op kept for call sites
    }

    function hideDetail() {
        activePlayId = null;
        activePlayData = null;
        selectedRouteIds.clear();
        routeGroups = [];
        updateUrl(null);
        $('.pb-play-row').removeClass('active');
        $('#pb_info_overlay').hide();
        $('#pb_detail_content').html(
            '<div class="pb-detail-placeholder">' +
            '<i class="fas fa-hand-pointer"></i>' +
            '<div>' + t('playbook.selectPlayPrompt') + '</div>' +
            '</div>'
        );
        // Clear map highlights, legend, and routes
        updatePlayHighlights(null, null);
        $('#pb_map_legend').hide();
        var textarea = document.getElementById('routeSearch');
        var plotBtn = document.getElementById('plot_r');
        if (textarea && plotBtn) {
            textarea.value = '';
            plotBtn.click();
        }
    }

    // =========================================================================
    // SMART SELECTION — filter routes by origin/dest/region/text
    // =========================================================================

    /**
     * Apply filtered selection using AND across dimensions, OR within each.
     * Called whenever any checkbox dropdown changes.
     */
    function applyFilteredSelection() {
        if (!activePlayData || !activePlayData.routes) return;
        var routes = activePlayData.routes;

        var origVals = getCheckedValues('pb_select_origin');
        var destVals = getCheckedValues('pb_select_dest');
        var regionVals = getCheckedValues('pb_select_region');
        var textVal = ($('#pb_select_route_text').val() || '').trim().toUpperCase();

        // No filters active → clear selection (all routes visible)
        if (!origVals.length && !destVals.length && !regionVals.length && !textVal) {
            selectedRouteIds.clear();
            updateCheckboxes();
            plotOnMap();
            return;
        }

        selectedRouteIds.clear();
        routes.forEach(function(r) {
            // AND across dimensions, OR within each dimension
            if (origVals.length && !origVals.some(function(v) { return routeMatchesFacility(r, v, 'origin'); })) return;
            if (destVals.length && !destVals.some(function(v) { return routeMatchesFacility(r, v, 'dest'); })) return;
            if (regionVals.length && !regionVals.some(function(v) { return routeMatchesRegion(r, v); })) return;
            if (textVal && (r.route_string || '').toUpperCase().indexOf(textVal) === -1) return;
            selectedRouteIds.add(r.route_id);
        });

        updateCheckboxes();
        plotOnMap();
    }

    function updateCheckboxes() {
        var routes = (activePlayData && activePlayData.routes) || [];
        $('.pb-route-cb').each(function() {
            var rid = parseInt($(this).val());
            this.checked = selectedRouteIds.has(rid);
        });
        $('#pb_check_all').prop('checked', selectedRouteIds.size > 0 && selectedRouteIds.size === routes.length);
        updateToolbarVisibility();
    }

    function updateDropdownCounts() {
        $('.pb-cb-dropdown').each(function() {
            var cnt = $(this).find('input:checked').length;
            $(this).find('.pb-cb-count').text(cnt > 0 ? cnt : '');
        });
    }

    function routeMatchesFacility(route, qualifiedValue, side) {
        var parts = qualifiedValue.split(':');
        var level = parts[0]; // artcc, tracon, airport
        var code = parts[1];
        if (!code) return false;

        var prefix = side === 'origin' ? 'origin' : 'dest';
        if (level === 'artcc') {
            return csvSplit(route[prefix + '_artccs']).indexOf(code) !== -1;
        } else if (level === 'tracon') {
            return csvSplit(route[prefix + '_tracons']).indexOf(code) !== -1;
        } else if (level === 'airport') {
            return csvSplit(route[prefix + '_airports']).indexOf(code) !== -1 ||
                   (route[prefix === 'origin' ? 'origin' : 'dest'] || '').toUpperCase().indexOf(code) !== -1;
        }
        return false;
    }

    function routeMatchesRegion(route, regionName) {
        if (typeof FacilityHierarchy === 'undefined' || !FacilityHierarchy.getRegion) return false;
        var allCodes = csvSplit(route.origin_artccs).concat(csvSplit(route.dest_artccs));
        return allCodes.some(function(c) { return FacilityHierarchy.getRegion(c) === regionName; });
    }

    // =========================================================================
    // ROUTE GROUPS — data model, load/save, auto-group, configs
    // =========================================================================

    function nextGroupColor() {
        var usedColors = {};
        routeGroups.forEach(function(g) { usedColors[g.group_color] = true; });
        for (var i = 0; i < GROUP_COLORS.length; i++) {
            if (!usedColors[GROUP_COLORS[i]]) return GROUP_COLORS[i];
        }
        return GROUP_COLORS[routeGroups.length % GROUP_COLORS.length];
    }

    function getRouteGroupIndex(routeId) {
        for (var i = 0; i < routeGroups.length; i++) {
            if (routeGroups[i].route_ids.has(routeId)) return i;
        }
        return -1;
    }

    function getRouteGroupColor(routeId) {
        var idx = getRouteGroupIndex(routeId);
        return idx >= 0 ? routeGroups[idx].group_color : null;
    }

    function loadGroups(playId, callback) {
        $.getJSON(API_GROUPS + '?play_id=' + playId, function(data) {
            routeGroups = [];
            if (data && data.success && data.groups) {
                data.groups.forEach(function(g) {
                    routeGroups.push({
                        group_name: g.group_name,
                        group_color: g.group_color,
                        route_ids: new Set(g.route_ids || []),
                        sort_order: g.sort_order,
                        _autoField: null
                    });
                });
            }
            if (callback) callback();
        }).fail(function() {
            routeGroups = [];
            if (callback) callback();
        });
    }

    function saveGroups() {
        if (!activePlayData) return;
        var payload = {
            play_id: activePlayData.play_id,
            groups: routeGroups.map(function(g, idx) {
                return {
                    group_name: g.group_name,
                    group_color: g.group_color,
                    route_ids: Array.from(g.route_ids),
                    sort_order: idx
                };
            })
        };
        $.ajax({
            url: API_GROUPS_SAVE,
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(payload),
            dataType: 'json'
        });
    }

    function createGroupFromSelection(name, color) {
        var ids = new Set();
        selectedRouteIds.forEach(function(rid) { ids.add(rid); });
        if (!ids.size) return;

        // Remove these routes from other groups
        routeGroups.forEach(function(g) {
            ids.forEach(function(rid) { g.route_ids.delete(rid); });
        });
        // Remove empty groups
        routeGroups = routeGroups.filter(function(g) { return g.route_ids.size > 0; });

        routeGroups.push({
            group_name: name,
            group_color: color,
            route_ids: ids,
            sort_order: routeGroups.length,
            _autoField: null
        });

        saveGroups();
        refreshGroupUI();
    }

    function deleteGroup(idx) {
        if (idx < 0 || idx >= routeGroups.length) return;
        routeGroups.splice(idx, 1);
        saveGroups();
        refreshGroupUI();
    }

    function clearAllGroups() {
        routeGroups = [];
        saveGroups();
        refreshGroupUI();
    }

    function refreshGroupUI() {
        if (!activePlayData) return;
        renderGroupToolbar();
        renderRouteTable(activePlayData, activePlayData.routes || []);
        plotOnMap();
    }

    function suggestGroupName(routeIds) {
        if (!activePlayData || !activePlayData.routes) return t('playbook.groups.defaultName');
        var routes = activePlayData.routes.filter(function(r) { return routeIds.has(r.route_id); });
        if (!routes.length) return t('playbook.groups.defaultName');

        // Find most common origin TRACON
        var counts = {};
        routes.forEach(function(r) {
            csvSplit(r.origin_tracons).forEach(function(c) {
                if (c) counts[c] = (counts[c] || 0) + 1;
            });
        });
        var best = '', bestCount = 0;
        Object.keys(counts).forEach(function(k) {
            if (counts[k] > bestCount) { best = k; bestCount = counts[k]; }
        });
        if (best && bestCount >= routes.length * 0.5) return best + ' ' + t('playbook.groups.routesSuffix');

        // Try origin ARTCC
        counts = {};
        routes.forEach(function(r) {
            csvSplit(r.origin_artccs).forEach(function(c) {
                if (c) counts[c] = (counts[c] || 0) + 1;
            });
        });
        best = ''; bestCount = 0;
        Object.keys(counts).forEach(function(k) {
            if (counts[k] > bestCount) { best = k; bestCount = counts[k]; }
        });
        if (best) return best + ' ' + t('playbook.groups.routesSuffix');

        return t('playbook.groups.defaultName') + ' ' + (routeGroups.length + 1);
    }

    // ── Auto-grouping ──
    function autoGroupByField(fieldName, labelSuffix) {
        if (!activePlayData || !activePlayData.routes) return;
        var routes = activePlayData.routes;
        var buckets = {};

        routes.forEach(function(r) {
            var values = csvSplit(r[fieldName]);
            var key = values.length ? values[0] : 'Other';
            if (!buckets[key]) buckets[key] = new Set();
            buckets[key].add(r.route_id);
        });

        routeGroups = [];
        var colorIdx = 0;
        var keys = Object.keys(buckets).sort();
        keys.forEach(function(key) {
            if (buckets[key].size < 2) return;
            var displayName = (key === 'Other') ? t('playbook.groups.other') : key;
            routeGroups.push({
                group_name: displayName + (labelSuffix || ''),
                group_color: GROUP_COLORS[colorIdx % GROUP_COLORS.length],
                route_ids: buckets[key],
                sort_order: colorIdx,
                _autoField: fieldName
            });
            colorIdx++;
        });

        saveGroups();
        refreshGroupUI();
    }

    function autoGroupByDCCRegion(direction) {
        if (!activePlayData || !activePlayData.routes) return;
        var FH = (typeof FacilityHierarchy !== 'undefined') ? FacilityHierarchy : null;
        if (!FH || !FH.ARTCC_TO_REGION || !FH.DCC_REGIONS) {
            autoGroupByField(direction === 'dest' ? 'dest_artccs' : 'origin_artccs', '');
            return;
        }

        var routes = activePlayData.routes;
        var artccField = direction === 'dest' ? 'dest_artccs' : 'origin_artccs';
        var buckets = {};

        routes.forEach(function(r) {
            var artccs = csvSplit(r[artccField]);
            var artcc = artccs.length ? artccs[0] : '';
            var region = FH.ARTCC_TO_REGION[artcc] || 'OTHER';
            if (!buckets[region]) buckets[region] = new Set();
            buckets[region].add(r.route_id);
        });

        // Build groups using DCC region order and colors
        var regionOrder = ['NORTHEAST', 'SOUTHEAST', 'MIDWEST', 'SOUTH_CENTRAL', 'WEST',
                           'CANADA', 'MEXICO', 'CARIBBEAN', 'ECFMP', 'OTHER'];
        routeGroups = [];
        var sortIdx = 0;

        regionOrder.forEach(function(regionKey) {
            if (!buckets[regionKey] || buckets[regionKey].size < 2) return;
            var region = FH.DCC_REGIONS[regionKey];
            var regionName = region ? region.name : regionKey;
            var regionColor = region ? region.color : '#6c757d';
            routeGroups.push({
                group_name: regionName,
                group_color: regionColor,
                route_ids: buckets[regionKey],
                sort_order: sortIdx,
                _autoField: artccField
            });
            sortIdx++;
        });

        saveGroups();
        refreshGroupUI();
    }

    function autoGroupByCommonSegment() {
        if (!activePlayData || !activePlayData.routes) return;
        var routes = activePlayData.routes;

        // Count how often each fix appears across all routes
        var fixCounts = {};
        routes.forEach(function(r) {
            var tokens = (r.route_string || '').split(/\s+/).filter(Boolean);
            tokens.forEach(function(tok) {
                if (/^[A-Z]{2,5}$/.test(tok) && tok !== 'DCT') {
                    fixCounts[tok] = (fixCounts[tok] || 0) + 1;
                }
            });
        });

        // Find pivot fixes (appear in >25% of routes but not all)
        var threshold = Math.max(2, Math.floor(routes.length * 0.25));
        var pivots = Object.keys(fixCounts).filter(function(fix) {
            return fixCounts[fix] >= threshold && fixCounts[fix] < routes.length;
        }).sort(function(a, b) { return fixCounts[b] - fixCounts[a]; });

        if (!pivots.length) {
            autoGroupByField('origin_artccs', '');
            return;
        }

        // Greedy assignment: most frequent pivot first, each route assigned once
        var assigned = new Set();
        routeGroups = [];
        var colorIdx = 0;

        pivots.forEach(function(pivot) {
            var members = new Set();
            routes.forEach(function(r) {
                if (assigned.has(r.route_id)) return;
                if ((r.route_string || '').indexOf(pivot) !== -1) {
                    members.add(r.route_id);
                    assigned.add(r.route_id);
                }
            });
            if (members.size < 2) {
                members.forEach(function(rid) { assigned.delete(rid); });
            } else {
                routeGroups.push({
                    group_name: t('playbook.groups.viaPrefix') + ' ' + pivot,
                    group_color: GROUP_COLORS[colorIdx % GROUP_COLORS.length],
                    route_ids: members,
                    sort_order: colorIdx,
                    _autoField: 'route_contains'
                });
                colorIdx++;
            }
        });

        // Remaining unassigned routes
        var remaining = new Set();
        routes.forEach(function(r) {
            if (!assigned.has(r.route_id)) remaining.add(r.route_id);
        });
        if (remaining.size) {
            routeGroups.push({
                group_name: t('playbook.groups.other'),
                group_color: GROUP_COLORS[colorIdx % GROUP_COLORS.length],
                route_ids: remaining,
                sort_order: colorIdx,
                _autoField: null
            });
        }

        saveGroups();
        refreshGroupUI();
    }

    // ── Group Toolbar UI ──
    function renderGroupToolbar() {
        var $container = $('#pb_group_toolbar');
        if (!$container.length) return;
        if (!activePlayData) { $container.empty(); return; }

        var routes = activePlayData.routes || [];
        if (!routes.length) { $container.empty(); return; }

        var html = '<div class="pb-group-toolbar">';

        // Toolbar buttons
        html += '<div class="pb-group-actions">';
        html += '<button class="btn btn-xs btn-outline-success" id="pb_group_new" title="' + escHtml(t('playbook.groups.noRoutesSelected')) + '"><i class="fas fa-plus mr-1"></i>' + t('playbook.groups.newGroup') + '</button>';

        // Auto-Group dropdown
        html += '<div class="pb-cb-dropdown" id="pb_auto_group_dd">';
        html += '<button type="button" class="btn btn-xs btn-outline-info pb-cb-trigger"><i class="fas fa-magic mr-1"></i>' + t('playbook.groups.autoGroup') + ' <i class="fas fa-caret-down ml-1"></i></button>';
        html += '<div class="pb-cb-menu" style="min-width:180px;">';
        html += '<div class="pb-cb-item pb-auto-group-opt" data-field="origin_airports" data-suffix="">' + t('playbook.groups.byOriginAirport') + '</div>';
        html += '<div class="pb-cb-item pb-auto-group-opt" data-field="origin_tracons" data-suffix="">' + t('playbook.groups.byOriginTracon') + '</div>';
        html += '<div class="pb-cb-item pb-auto-group-opt" data-field="origin_artccs" data-suffix="">' + t('playbook.groups.byOriginArtcc') + '</div>';
        html += '<div class="pb-cb-item pb-auto-group-opt" data-field="dcc_region_origin">' + t('playbook.groups.byOriginDCCRegion') + '</div>';
        html += '<div style="border-top:1px solid #3a3a4e;margin:2px 0;"></div>';
        html += '<div class="pb-cb-item pb-auto-group-opt" data-field="dest_airports" data-suffix="">' + t('playbook.groups.byDestAirport') + '</div>';
        html += '<div class="pb-cb-item pb-auto-group-opt" data-field="dest_tracons" data-suffix="">' + t('playbook.groups.byDestTracon') + '</div>';
        html += '<div class="pb-cb-item pb-auto-group-opt" data-field="dest_artccs" data-suffix="">' + t('playbook.groups.byDestArtcc') + '</div>';
        html += '<div class="pb-cb-item pb-auto-group-opt" data-field="dcc_region_dest">' + t('playbook.groups.byDestDCCRegion') + '</div>';
        html += '<div style="border-top:1px solid #3a3a4e;margin:2px 0;"></div>';
        html += '<div class="pb-cb-item pb-auto-group-opt" data-field="common_segment">' + t('playbook.groups.byCommonSegment') + '</div>';
        html += '</div></div>';

        if (routeGroups.length) {
            html += '<button class="btn btn-xs btn-outline-danger" id="pb_group_clear"><i class="fas fa-trash mr-1"></i>' + t('playbook.groups.clearAll') + '</button>';
        }
        html += '</div>';

        // Inline create form (hidden by default)
        html += '<div class="pb-group-create-form" id="pb_group_create_form" style="display:none;">';
        html += '<label class="pb-group-form-label">' + t('playbook.groups.groupName') + '</label>';
        html += '<input type="text" class="form-control form-control-sm pb-group-name-input" id="pb_group_name_input" maxlength="100">';
        html += '<div class="pb-group-palette" id="pb_group_palette">';
        GROUP_COLORS.forEach(function(c) {
            html += '<span class="pb-group-swatch" data-color="' + c + '" style="background:' + c + ';" title="' + c + '"></span>';
        });
        html += '</div>';
        html += '<button class="btn btn-xs btn-success" id="pb_group_create_confirm"><i class="fas fa-check mr-1"></i>' + t('playbook.groups.create') + '</button>';
        html += '<button class="btn btn-xs btn-secondary" id="pb_group_create_cancel">' + t('playbook.groups.cancel') + '</button>';
        html += '</div>';

        // Group list
        if (routeGroups.length) {
            html += '<div class="pb-group-list">';
            routeGroups.forEach(function(g, idx) {
                html += '<div class="pb-group-item" data-group-idx="' + idx + '">';
                html += '<span class="pb-group-dot" style="background:' + escHtml(g.group_color) + ';"></span>';
                html += '<span class="pb-group-item-name">' + escHtml(g.group_name) + '</span>';
                html += '<span class="pb-group-item-count">(' + g.route_ids.size + ' ' + (g.route_ids.size === 1 ? t('playbook.groups.route') : t('playbook.groups.routes')) + ')</span>';
                html += '<button class="btn btn-xs pb-group-edit-btn" data-idx="' + idx + '" title="' + t('playbook.groups.edit') + '"><i class="fas fa-pencil-alt"></i></button>';
                html += '<button class="btn btn-xs pb-group-delete-btn" data-idx="' + idx + '" title="' + t('playbook.groups.delete') + '"><i class="fas fa-times"></i></button>';
                html += '</div>';
            });
            // Ungrouped count
            var allGrouped = new Set();
            routeGroups.forEach(function(g) { g.route_ids.forEach(function(rid) { allGrouped.add(rid); }); });
            var ungroupedCount = routes.filter(function(r) { return !allGrouped.has(r.route_id); }).length;
            if (ungroupedCount > 0) {
                html += '<div class="pb-group-item pb-group-ungrouped">';
                html += '<span class="pb-group-dot pb-group-dot-empty"></span>';
                html += '<span class="pb-group-item-name text-muted">' + t('playbook.groups.ungrouped') + '</span>';
                html += '<span class="pb-group-item-count text-muted">(' + ungroupedCount + ' ' + (ungroupedCount === 1 ? t('playbook.groups.route') : t('playbook.groups.routes')) + ')</span>';
                html += '</div>';
            }
            html += '</div>';
        }

        html += '</div>';
        $container.html(html);
    }

    // =========================================================================
    // MAP INTEGRATION
    // =========================================================================

    function getSelectedRoutes() {
        if (!activePlayData) return [];
        var routes = activePlayData.routes || [];
        if (!selectedRouteIds.size) return routes;
        return routes.filter(function(r) { return selectedRouteIds.has(r.route_id); });
    }

    function plotOnMap() {
        if (!activePlayData) return;
        var allRoutes = activePlayData.routes || [];
        var selected = getSelectedRoutes();
        if (!selected.length) { return; }

        var hasSearch = currentSearchClauses.length > 0;
        var hasGroups = routeGroups.length > 0;
        var text;

        if (hasSearch) {
            // Search active: plot non-matching first, matching on top for visibility
            var nonMatching = [], matching = [];
            selected.forEach(function(r) {
                var parts = [];
                if (r.origin) parts.push(r.origin);
                parts.push(r.route_string);
                if (r.dest) parts.push(r.dest);
                var routeStr = parts.join(' ');
                if (routeMatchesSearchClauses(r, currentSearchClauses)) {
                    matching.push(routeStr + ';#C70039');
                } else {
                    nonMatching.push(routeStr + ';#555555');
                }
            });
            text = nonMatching.concat(matching).join('\n');
        } else if (hasGroups) {
            // Groups active: use per-route group colors (skip PB directive path)
            text = selected.map(function(r) {
                var parts = [];
                if (r.origin) parts.push(r.origin);
                parts.push(r.route_string);
                if (r.dest) parts.push(r.dest);
                var routeStr = parts.join(' ');
                var color = getRouteGroupColor(r.route_id);
                if (color) routeStr += ';' + color;
                return routeStr;
            }).join('\n');
        } else {
            // No search, no groups: use PB directive or manual routes (default colors)
            var usePB = canUsePBDirective(selected, allRoutes);
            if (usePB) {
                text = buildCurrentPBDirective();
            } else {
                text = selected.map(function(r) {
                    var parts = [];
                    if (r.origin) parts.push(r.origin);
                    parts.push(r.route_string);
                    if (r.dest) parts.push(r.dest);
                    return parts.join(' ');
                }).join('\n');
            }
        }

        // Wrap each route line with mandatory markers so playbook routes render as solid lines.
        // Follows advisory rules: skip airport endpoints (first/last tokens) so only the
        // enroute portion is marked mandatory. SID/STAR correction is handled downstream
        // by advCorrectMandatoryProcedures in route-maplibre.js.
        text = text.split('\n').map(function(line) {
            var trimmed = line.trim();
            if (!trimmed) return line;
            // Skip PB directives — expandPlaybookDirective handles wrapping
            if (trimmed.toUpperCase().indexOf('PB.') === 0) return '>' + trimmed + '<';
            // Skip lines already containing mandatory markers
            if (trimmed.indexOf('>') !== -1) return line;
            // Separate color suffix (;#COLOR) if present
            var colorSuffix = '';
            var semiIdx = trimmed.indexOf(';');
            if (semiIdx !== -1) {
                colorSuffix = trimmed.slice(semiIdx);
                trimmed = trimmed.slice(0, semiIdx).trim();
            }
            var tokens = trimmed.split(/\s+/).filter(Boolean);
            if (tokens.length > 2) {
                // Wrap from second token to second-to-last (skip airport endpoints)
                tokens[1] = '>' + tokens[1];
                tokens[tokens.length - 2] = tokens[tokens.length - 2] + '<';
            } else {
                return '>' + trimmed + '<' + colorSuffix;
            }
            return tokens.join(' ') + colorSuffix;
        }).join('\n');

        var textarea = document.getElementById('routeSearch');
        var plotBtn = document.getElementById('plot_r');
        if (textarea && plotBtn) {
            textarea.value = text;
            plotBtn.click();
        }
    }

    function openInRoutePage() {
        if (!activePlayData) return;
        var allRoutes = activePlayData.routes || [];
        var selected = getSelectedRoutes();
        if (!selected.length) return;

        var usePB = canUsePBDirective(selected, allRoutes);
        var text;
        if (usePB) {
            text = buildCurrentPBDirective();
        } else {
            text = selected.map(function(r) {
                var parts = [];
                if (r.origin) parts.push(r.origin);
                parts.push(r.route_string);
                if (r.dest) parts.push(r.dest);
                return parts.join(' ');
            }).join('\n');
        }

        var encoded = btoa(text);
        window.open('route?routes_b64=' + encodeURIComponent(encoded), '_blank');
    }

    function activateAsReroute() {
        var play = activePlayData;
        if (!play) return;
        var routes = getSelectedRoutes();
        if (!routes.length) {
            if (typeof Swal !== 'undefined') {
                Swal.fire({ icon: 'info', title: t('playbook.noRoutes'), text: t('playbook.noRoutesText'), confirmButtonText: t('common.ok') });
            }
            return;
        }

        // Plot first so GeoJSON is available
        plotOnMap();

        var attempts = 0;
        var pollInterval = setInterval(function() {
            var features = (typeof MapLibreRoute !== 'undefined' && MapLibreRoute.getCurrentRouteFeatures)
                ? MapLibreRoute.getCurrentRouteFeatures() : null;
            attempts++;
            if (!features && attempts < 30) return;
            clearInterval(pollInterval);

            var rerouteData = {
                version: '1.0',
                timestamp: new Date().toISOString(),
                source: 'playbook',
                advisory: {
                    number: null,
                    facility: 'DCC',
                    name: play.display_name || play.play_name,
                    constrainedArea: play.impacted_area || '',
                    reason: play.scenario_type || 'WEATHER',
                    routeType: 'ROUTE',
                    includeTraffic: '',
                    validStart: '',
                    validEnd: '',
                    timeBasis: 'ETD',
                    probExtension: 'MEDIUM',
                    remarks: play.remarks || '',
                    restrictions: '',
                    modifications: ''
                },
                facilities: csvSplit(play.facilities_involved),
                routes: routes.map(function(r) {
                    return {
                        origin: r.origin || '',
                        originFilter: r.origin_filter || '',
                        originAirports: csvSplit(r.origin_airports),
                        originArtccs: csvSplit(r.origin_artccs),
                        destination: r.dest || '',
                        destFilter: r.dest_filter || '',
                        destAirports: csvSplit(r.dest_airports),
                        destArtccs: csvSplit(r.dest_artccs),
                        route: r.route_string
                    };
                }),
                rawInput: buildCurrentPBDirective(),
                procedures: ['PB: ' + buildCurrentPBDirective()],
                geojson: features
            };

            try {
                sessionStorage.setItem('tmi_reroute_draft', JSON.stringify(rerouteData));
                sessionStorage.setItem('tmi_reroute_draft_timestamp', Date.now().toString());
                localStorage.setItem('tmi_reroute_draft', JSON.stringify(rerouteData));
                localStorage.setItem('tmi_reroute_draft_timestamp', Date.now().toString());
            } catch (e) {
                console.error('[Playbook] Storage error:', e);
            }

            window.open('tmi-publish?mode=reroute&tab=reroute#reroutePanel', '_blank');
        }, 100);
    }

    // =========================================================================
    // AUTO-COMPUTATION
    // =========================================================================

    function autoComputeRouteFields(routeString) {
        if (typeof MapLibreRoute === 'undefined' || !MapLibreRoute.parseRoutesEnhanced) return null;
        if (typeof FacilityHierarchy === 'undefined') return null;

        var parsed = MapLibreRoute.parseRoutesEnhanced([routeString]);
        if (!parsed || !parsed.length) return null;
        var r = parsed[0];

        var origTracons = (r.origAirports || [])
            .map(function(a) { return FacilityHierarchy.AIRPORT_TO_TRACON ? FacilityHierarchy.AIRPORT_TO_TRACON[a] : null; })
            .filter(Boolean);

        var origArtccs = (r.origArtccs || []).slice();
        (r.origAirports || []).forEach(function(a) {
            var artcc = FacilityHierarchy.getParentArtcc ? FacilityHierarchy.getParentArtcc(a) : null;
            if (artcc) origArtccs.push(artcc);
        });

        var destTracons = (r.destAirports || [])
            .map(function(a) { return FacilityHierarchy.AIRPORT_TO_TRACON ? FacilityHierarchy.AIRPORT_TO_TRACON[a] : null; })
            .filter(Boolean);

        var destArtccs = (r.destArtccs || []).slice();
        (r.destAirports || []).forEach(function(a) {
            var artcc = FacilityHierarchy.getParentArtcc ? FacilityHierarchy.getParentArtcc(a) : null;
            if (artcc) destArtccs.push(artcc);
        });

        return {
            origin: r.orig || '',
            dest: r.dest || '',
            origin_airports: (r.origAirports || []).join(','),
            origin_tracons: unique(origTracons).join(','),
            origin_artccs: unique(origArtccs).join(','),
            dest_airports: (r.destAirports || []).join(','),
            dest_tracons: unique(destTracons).join(','),
            dest_artccs: unique(destArtccs).join(',')
        };
    }

    async function autoComputePlayFields(routes) {
        var allArtccs = new Set();
        routes.forEach(function(r) {
            csvSplit(r.origin_artccs).forEach(function(a) { allArtccs.add(a); });
            csvSplit(r.dest_artccs).forEach(function(a) { allArtccs.add(a); });
        });

        try {
            var routeStrings = routes.map(function(r) { return r.route_string; }).filter(Boolean);
            if (routeStrings.length) {
                var resp = await fetch('/api/gis/boundaries?action=expand_routes', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ routes: routeStrings })
                });
                var gis = await resp.json();
                if (gis && gis.artccs_all) {
                    gis.artccs_all.forEach(function(a) { allArtccs.add(a); });
                }
            }
        } catch (e) {
            console.warn('[Playbook] GIS facilities calculation failed:', e);
        }

        var sorted = Array.from(allArtccs).sort();
        return {
            facilities_involved: sorted.join(','),
            impacted_area: sorted.join('/')
        };
    }

    // =========================================================================
    // CRUD — CREATE / EDIT
    // =========================================================================

    function setCategoryDropdown(val) {
        var $sel = $('#pb_edit_category');
        if (val && !$sel.find('option[value="' + val + '"]').length) {
            $sel.find('option[value="__custom__"]').before(
                '<option value="' + escHtml(val) + '">' + escHtml(val) + '</option>'
            );
        }
        $sel.val(val || '');
    }

    function openCreateModal() {
        $('#pb_modal_title').text(t('playbook.createPlay'));
        $('#pb_edit_play_id').val(0);
        $('#pb_edit_play_name').val('');
        $('#pb_edit_display_name').val('');
        setCategoryDropdown('');
        $('#pb_edit_scenario_type').val('');
        $('#pb_edit_route_format').val('standard');
        $('#pb_edit_description').val('');
        $('#pb_edit_remarks').val('');
        $('#pb_edit_status').val('active');
        $('#pb_edit_source').val('DCC').prop('disabled', false);
        $('#pb_route_edit_body').empty();
        $('#pb_bulk_paste_area').hide();
        addEditRouteRow();
        $('#pb_play_modal').modal('show');
    }

    function openEditModal(play, routes) {
        $('#pb_modal_title').text(t('playbook.editPlay'));
        $('#pb_edit_play_id').val(play.play_id);
        $('#pb_edit_play_name').val(play.play_name || '');
        $('#pb_edit_display_name').val(play.display_name || '');
        setCategoryDropdown(play.category || '');
        $('#pb_edit_scenario_type').val(play.scenario_type || '');
        $('#pb_edit_route_format').val(play.route_format || 'standard');
        $('#pb_edit_description').val(play.description || '');
        $('#pb_edit_remarks').val(play.remarks || '');
        $('#pb_edit_status').val(play.status || 'active');
        $('#pb_edit_source').val(play.source || 'DCC').prop('disabled', true);

        var tbody = $('#pb_route_edit_body');
        tbody.empty();
        (routes || []).forEach(function(r) {
            addEditRouteRow(r);
        });

        $('#pb_bulk_paste_area').hide();
        $('#pb_play_modal').modal('show');
    }

    function duplicatePlay(play, routes) {
        var newName = (play.play_name || '') + '_MODIFIED';
        $('#pb_modal_title').text(t('playbook.duplicatePlay'));
        $('#pb_edit_play_id').val(0); // Create new, not update
        $('#pb_edit_play_name').val(newName);
        $('#pb_edit_display_name').val(play.display_name || '');
        setCategoryDropdown(play.category || '');
        $('#pb_edit_scenario_type').val(play.scenario_type || '');
        $('#pb_edit_route_format').val(play.route_format || 'standard');
        $('#pb_edit_description').val(play.description || '');
        $('#pb_edit_remarks').val(play.remarks || '');
        $('#pb_edit_status').val('draft');
        $('#pb_edit_source').val('DCC').prop('disabled', false);

        var tbody = $('#pb_route_edit_body');
        tbody.empty();
        (routes || []).forEach(function(r) {
            addEditRouteRow(r);
        });

        $('#pb_bulk_paste_area').hide();
        $('#pb_play_modal').modal('show');
    }

    function addEditRouteRow(r) {
        var route = r || {};
        var html = '<tr>';
        html += '<td class="pb-re-cell"><input type="text" class="form-control form-control-sm pb-re-origin pb-re-apt" value="' + escHtml(route.origin || '') + '" placeholder="KABC"></td>';
        html += '<td class="pb-re-cell"><input type="text" class="form-control form-control-sm pb-re-origin-filter pb-re-filter" value="' + escHtml(route.origin_filter || '') + '" placeholder="-APT"></td>';
        html += '<td class="pb-re-cell"><input type="text" class="form-control form-control-sm pb-re-dest pb-re-apt" value="' + escHtml(route.dest || '') + '" placeholder="KXYZ"></td>';
        html += '<td class="pb-re-cell"><input type="text" class="form-control form-control-sm pb-re-dest-filter pb-re-filter" value="' + escHtml(route.dest_filter || '') + '" placeholder="-APT"></td>';
        html += '<td class="pb-re-cell"><textarea class="form-control form-control-sm pb-re-route" rows="1" placeholder="DCT FIX1 J123 FIX2 DCT">' + escHtml(route.route_string || '') + '</textarea></td>';
        html += '<td class="pb-re-cell"><button class="btn btn-sm btn-outline-danger pb-re-delete" title="' + t('playbook.deleteRoute') + '"><i class="fas fa-times"></i></button></td>';
        html += '</tr>';
        $('#pb_route_edit_body').append(html);
    }

    function applyBulkPaste() {
        var text = $('#pb_bulk_paste_text').val().trim();
        if (!text) return;

        var hasParsed = typeof MapLibreRoute !== 'undefined' && MapLibreRoute.parseRoutesEnhanced;

        var lines = text.split('\n').filter(function(l) { return l.trim(); });
        lines.forEach(function(line) {
            var cleaned = line.trim()
                .replace(/[><]/g, '')   // Strip mandatory route markers
                .replace(/\s+/g, ' ')   // Normalize whitespace
                .trim();
            if (!cleaned) return;

            var routeData = { route_string: cleaned };

            // Parse to separate origin/route/dest and compute facility fields
            if (hasParsed) {
                var parsed = MapLibreRoute.parseRoutesEnhanced([cleaned]);
                if (parsed && parsed.length) {
                    var r = parsed[0];
                    // Use the route body (without orig/dest) as route_string
                    if (r.assignedRoute) routeData.route_string = r.assignedRoute;
                    routeData.origin = r.orig || '';
                    routeData.dest = r.dest || '';
                }
            }

            // Compute full facility fields (tracons, artccs) from detected origin/dest
            var computed = autoComputeRouteFields(cleaned);
            if (computed) {
                if (!routeData.origin) routeData.origin = computed.origin;
                if (!routeData.dest) routeData.dest = computed.dest;
                routeData.origin_airports = computed.origin_airports;
                routeData.origin_tracons = computed.origin_tracons;
                routeData.origin_artccs = computed.origin_artccs;
                routeData.dest_airports = computed.dest_airports;
                routeData.dest_tracons = computed.dest_tracons;
                routeData.dest_artccs = computed.dest_artccs;
            }

            // Post-classify explicit origin/dest for TRACON/ARTCC codes
            var origClass = classifyOriginDest(routeData.origin);
            var destClass = classifyOriginDest(routeData.dest);
            routeData.origin_tracons = unique(csvSplit(routeData.origin_tracons).concat(origClass.tracons)).join(',');
            routeData.origin_artccs = unique(csvSplit(routeData.origin_artccs).concat(origClass.artccs)).join(',');
            routeData.dest_tracons = unique(csvSplit(routeData.dest_tracons).concat(destClass.tracons)).join(',');
            routeData.dest_artccs = unique(csvSplit(routeData.dest_artccs).concat(destClass.artccs)).join(',');

            addEditRouteRow(routeData);
        });

        $('#pb_bulk_paste_text').val('');
        $('#pb_bulk_paste_area').slideUp(150);
    }

    async function savePlay() {
        var playId = parseInt($('#pb_edit_play_id').val()) || 0;
        var playName = $('#pb_edit_play_name').val().trim();
        if (!playName) {
            if (typeof Swal !== 'undefined') Swal.fire({ icon: 'warning', title: t('playbook.playNameRequired'), confirmButtonText: t('common.ok') });
            return;
        }

        var routes = [];
        $('#pb_route_edit_body tr').each(function() {
            var $tr = $(this);
            var routeStr = $tr.find('.pb-re-route').val().trim();
            if (!routeStr) return;

            var origin = $tr.find('.pb-re-origin').val().trim();
            var originFilter = $tr.find('.pb-re-origin-filter').val().trim();
            var dest = $tr.find('.pb-re-dest').val().trim();
            var destFilter = $tr.find('.pb-re-dest-filter').val().trim();

            var computed = autoComputeRouteFields(
                (origin ? origin + ' ' : '') + routeStr + (dest ? ' ' + dest : '')
            );

            // Post-classify explicit origin/dest for TRACON/ARTCC codes
            var origClass = classifyOriginDest(origin);
            var destClass = classifyOriginDest(dest);

            routes.push({
                route_string: routeStr,
                origin: origin || (computed ? computed.origin : ''),
                origin_filter: originFilter,
                dest: dest || (computed ? computed.dest : ''),
                dest_filter: destFilter,
                origin_airports: unique(csvSplit(computed ? computed.origin_airports : '').concat(origClass.airports)).join(','),
                origin_tracons: unique(csvSplit(computed ? computed.origin_tracons : '').concat(origClass.tracons)).join(','),
                origin_artccs: unique(csvSplit(computed ? computed.origin_artccs : '').concat(origClass.artccs)).join(','),
                dest_airports: unique(csvSplit(computed ? computed.dest_airports : '').concat(destClass.airports)).join(','),
                dest_tracons: unique(csvSplit(computed ? computed.dest_tracons : '').concat(destClass.tracons)).join(','),
                dest_artccs: unique(csvSplit(computed ? computed.dest_artccs : '').concat(destClass.artccs)).join(',')
            });
        });

        var playFields = await autoComputePlayFields(routes);

        // Source: disabled selects don't return .val(), so re-enable briefly
        var $srcSel = $('#pb_edit_source');
        var srcDisabled = $srcSel.prop('disabled');
        if (srcDisabled) $srcSel.prop('disabled', false);
        var sourceVal = $srcSel.val() || 'DCC';
        if (srcDisabled) $srcSel.prop('disabled', true);

        var body = {
            play_id: playId,
            play_name: playName,
            display_name: $('#pb_edit_display_name').val().trim(),
            description: $('#pb_edit_description').val().trim(),
            category: ($('#pb_edit_category').val() || '').replace('__custom__', '').trim(),
            scenario_type: $('#pb_edit_scenario_type').val(),
            route_format: $('#pb_edit_route_format').val(),
            status: $('#pb_edit_status').val(),
            source: sourceVal,
            airac_cycle: getAiracCycle(),
            facilities_involved: playFields.facilities_involved,
            impacted_area: playFields.impacted_area,
            remarks: $('#pb_edit_remarks').val().trim(),
            routes: routes
        };

        var $btn = $('#pb_save_play_btn');
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i>' + t('common.save'));

        $.ajax({
            url: API_SAVE,
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(body),
            dataType: 'json',
            success: function(data) {
                $btn.prop('disabled', false).html('<i class="fas fa-save mr-1"></i>' + t('common.save'));
                if (data && data.success) {
                    $('#pb_play_modal').modal('hide');
                    if (typeof Swal !== 'undefined') Swal.fire({ icon: 'success', title: t('common.success'), text: t('playbook.playSaved'), timer: 1500, showConfirmButton: false });
                    activePlayId = data.play_id;
                    loadPlays();
                    loadCategories();
                    // Auto-refresh the detail panel with updated data
                    loadPlayDetail(data.play_id);
                } else {
                    if (typeof Swal !== 'undefined') Swal.fire({ icon: 'error', title: t('common.error'), text: data.error || t('common.unknownError') });
                }
            },
            error: function() {
                $btn.prop('disabled', false).html('<i class="fas fa-save mr-1"></i>' + t('common.save'));
                if (typeof Swal !== 'undefined') Swal.fire({ icon: 'error', title: t('common.error'), text: t('playbook.saveFailed') });
            }
        });
    }

    // =========================================================================
    // CRUD — ARCHIVE / RESTORE
    // =========================================================================

    function archivePlay(playId) {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: 'warning',
                title: t('playbook.confirmArchive'),
                text: t('playbook.confirmArchiveText'),
                showCancelButton: true,
                confirmButtonText: t('common.confirm'),
                cancelButtonText: t('common.cancel')
            }).then(function(result) {
                if (result.isConfirmed) doAction(playId, 'archive');
            });
        } else {
            if (confirm(t('playbook.confirmArchive'))) doAction(playId, 'archive');
        }
    }

    function restorePlay(playId) {
        doAction(playId, 'restore');
    }

    function doAction(playId, action) {
        $.ajax({
            url: API_DELETE,
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ play_id: playId, action: action, airac_cycle: getAiracCycle() }),
            dataType: 'json',
            success: function(data) {
                if (data && data.success) {
                    if (typeof Swal !== 'undefined') Swal.fire({ icon: 'success', title: t('common.success'), timer: 1500, showConfirmButton: false });
                    hideDetail();
                    loadPlays();
                    loadCategories();
                } else {
                    if (typeof Swal !== 'undefined') Swal.fire({ icon: 'error', title: t('common.error'), text: data.error || t('common.unknownError') });
                }
            },
            error: function() {
                if (typeof Swal !== 'undefined') Swal.fire({ icon: 'error', title: t('common.error') });
            }
        });
    }

    // =========================================================================
    // CHANGELOG
    // =========================================================================

    function loadChangelog(playId) {
        var container = $('#pb_changelog_content');
        container.html('<div class="pb-loading py-1"><div class="spinner-border spinner-border-sm text-primary"></div></div>');

        $.getJSON(API_LOG + '?play_id=' + playId + '&per_page=20', function(data) {
            if (!data || !data.success || !data.data || !data.data.length) {
                container.html('<div class="small text-muted py-1">' + t('playbook.noChanges') + '</div>');
                return;
            }

            var html = '<ul class="pb-changelog-list">';
            data.data.forEach(function(entry) {
                html += '<li class="pb-changelog-item">';
                html += '<span class="pb-changelog-action">' + escHtml(entry.action) + '</span>';
                if (entry.field_name) {
                    html += ' <span class="pb-changelog-field">' + escHtml(entry.field_name) + '</span>';
                }
                if (entry.old_value || entry.new_value) {
                    html += ' <span class="pb-changelog-diff">';
                    if (entry.old_value) html += '<span class="pb-changelog-old">' + escHtml(entry.old_value.substring(0, 80)) + '</span>';
                    html += ' &rarr; ';
                    if (entry.new_value) html += '<span class="pb-changelog-new">' + escHtml(entry.new_value.substring(0, 80)) + '</span>';
                    html += '</span>';
                }
                html += ' <span class="text-muted" style="font-size:0.58rem;">' + escHtml(entry.changed_by || '') + ' ' + escHtml(entry.changed_at || '') + '</span>';
                html += '</li>';
            });
            html += '</ul>';
            container.html(html);
        });
    }

    // =========================================================================
    // EVENT HANDLERS
    // =========================================================================

    var searchTimer = null;
    var routeTextTimer = null;

    $(document).ready(function() {
        // Load facility hierarchy for region coloring, TRACON resolution, etc.
        if (typeof FacilityHierarchy !== 'undefined' && FacilityHierarchy.load) {
            FacilityHierarchy.load();
        }

        loadCategories();
        loadPlays();

        // Sync hierarchy layer visibility with legend checkbox defaults after map loads.
        // Hierarchy layers are created asynchronously by route-maplibre.js, so poll briefly.
        (function syncHierarchyDefaults() {
            var attempts = 0;
            var timer = setInterval(function() {
                var map = window.graphic_map;
                attempts++;
                if (!map || !map.getLayer || attempts > 40) { clearInterval(timer); return; }
                // Wait until the FIR labels layer exists (last hierarchy layer created)
                if (!map.getLayer('artcc-fir-labels')) return;
                clearInterval(timer);
                // Apply default states from the legend checkboxes
                $('[data-hier-toggle]').each(function() {
                    var level = $(this).data('hier-toggle');
                    var visible = this.checked;
                    var layerMap = {
                        super: ['artcc-super-lines'],
                        fir: ['artcc-fir-lines', 'artcc-fir-labels'],
                        sub: ['artcc-sub-lines'],
                        deep: ['artcc-deep-lines'],
                    };
                    var layers = layerMap[level];
                    if (layers) {
                        layers.forEach(function(layerId) {
                            if (map.getLayer(layerId)) {
                                map.setLayoutProperty(layerId, 'visibility', visible ? 'visible' : 'none');
                            }
                        });
                    }
                });
            }, 500);
        })();

        // Make overlays draggable via jQuery UI
        if ($.fn.draggable) {
            $('#pb_catalog_overlay').draggable({
                handle: '#pb_catalog_titlebar',
                containment: '#pb_map_section',
                scroll: false
            });
            $('#pb_info_overlay').draggable({
                handle: '#pb_info_titlebar',
                containment: '#pb_map_section',
                scroll: false
            });
        }

        // Search with debounce
        $('#pb_search').on('input', function() {
            searchText = ($(this).val() || '').trim();
            clearTimeout(searchTimer);
            searchTimer = setTimeout(applyFilters, 200);
        });

        // Category pills
        $(document).on('click', '.pb-pill', function() {
            activeCategory = $(this).data('cat') || '';
            $('.pb-pill').removeClass('active');
            $(this).addClass('active');
            applyFilters();
        });

        // Source toggle buttons
        $(document).on('click', '.pb-src-btn', function() {
            var newSource = $(this).data('source') || '';
            var needsReload = (newSource === 'FAA_HISTORICAL') !== (activeSource === 'FAA_HISTORICAL');
            activeSource = newSource;
            $('.pb-src-btn').removeClass('active');
            $(this).addClass('active');
            if (needsReload) {
                loadPlays();
            } else {
                applyFilters();
            }
        });

        // Legacy toggle
        $('#pb_legacy_toggle').on('change', function() {
            showLegacy = this.checked;
            loadPlays(); // Re-fetch from API since hide_legacy is server-side
        });

        // Pagination
        $(document).on('click', '.pb-page-btn', function() {
            var pg = $(this).data('page');
            if (pg === 'prev') { currentPage = Math.max(1, currentPage - 1); }
            else if (pg === 'next') { currentPage++; }
            else { currentPage = parseInt(pg); }
            renderPlayList();
            $('#pb_play_list_wrap').scrollTop(0);
        });

        // Play row click
        $(document).on('click', '.pb-play-row', function() {
            var playId = $(this).data('play-id');
            if (playId == activePlayId) {
                hideDetail();
            } else {
                loadPlayDetail(playId);
            }
        });

        // Route checkboxes
        $(document).on('change', '.pb-route-cb', function() {
            var rid = parseInt($(this).val());
            if (this.checked) { selectedRouteIds.add(rid); } else { selectedRouteIds.delete(rid); }
            updateToolbarVisibility();
        });
        $(document).on('change', '#pb_check_all', function() {
            var checked = this.checked;
            selectedRouteIds.clear();
            $('.pb-route-cb').each(function() {
                this.checked = checked;
                if (checked) selectedRouteIds.add(parseInt($(this).val()));
            });
            updateToolbarVisibility();
        });
        $(document).on('click', '#pb_select_all', function() {
            var allChecked = selectedRouteIds.size === $('.pb-route-cb').length;
            $('#pb_check_all').prop('checked', !allChecked).trigger('change');
        });

        // Checkbox dropdown multi-select: toggle open/close
        $(document).on('click', '.pb-cb-trigger', function(e) {
            e.stopPropagation();
            var $dd = $(this).closest('.pb-cb-dropdown');
            var wasOpen = $dd.hasClass('open');
            // Close all dropdowns first
            $('.pb-cb-dropdown').removeClass('open');
            if (!wasOpen) $dd.addClass('open');
        });
        // Close dropdowns on outside click
        $(document).on('click', function(e) {
            if (!$(e.target).closest('.pb-cb-dropdown').length) {
                $('.pb-cb-dropdown').removeClass('open');
            }
        });
        // Checkbox change → re-filter
        $(document).on('change', '.pb-cb-dropdown input[type="checkbox"]', function(e) {
            e.stopPropagation();
            updateDropdownCounts();
            applyFilteredSelection();
        });
        // Route text filter with debounce
        $(document).on('input', '#pb_select_route_text', function() {
            clearTimeout(routeTextTimer);
            routeTextTimer = setTimeout(applyFilteredSelection, 300);
        });
        // Clear all filters
        $(document).on('click', '#pb_clear_filters', function() {
            $('.pb-cb-dropdown input').prop('checked', false);
            $('.pb-cb-count').text('');
            $('#pb_select_route_text').val('');
            selectedRouteIds.clear();
            updateCheckboxes();
            plotOnMap();
        });

        // Overlay minimize/close handlers
        $(document).on('click', '#pb_catalog_minimize', function() {
            $('#pb_catalog_overlay').toggleClass('minimized');
        });
        $(document).on('click', '#pb_info_minimize', function() {
            $('#pb_info_overlay').toggleClass('minimized');
        });
        $(document).on('click', '#pb_info_close', function() {
            hideDetail();
        });

        // Search help dialog
        $(document).on('click', '#pb_search_help', function() {
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    title: t('playbook.searchHelp.title'),
                    html: t('playbook.searchHelp.body'),
                    width: 520,
                    confirmButtonText: t('common.ok'),
                    customClass: { htmlContainer: 'text-left' }
                });
            }
        });

        // Route action toolbar
        $(document).on('click', '#pb_open_route_page', openInRoutePage);
        $(document).on('click', '#pb_activate_reroute', activateAsReroute);
        $(document).on('click', '#pb_copy_pb_directive', function() {
            var directive = buildCurrentPBDirective();
            if (!directive) return;
            navigator.clipboard.writeText(directive).then(function() {
                PERTIDialog.toast('common.copied', 'success');
            });
        });

        // Highlight layer toggles (legend checkboxes)
        $(document).on('change', '[data-hl-toggle]', function() {
            var key = $(this).data('hl-toggle');
            if (highlightToggles.hasOwnProperty(key)) {
                highlightToggles[key] = this.checked;
                // Play-specific toggles re-apply play highlights
                if (key === 'playOrigin' || key === 'playDest' || key === 'playTraversed') {
                    if (activePlayData) {
                        updatePlayHighlights(activePlayData, activePlayData.routes || []);
                    }
                } else {
                    updateMapHighlights(lastHighlightClauses);
                }
            }
        });

        // ARTCC hierarchy toggles (super/fir/sub/deep boundary layers)
        $(document).on('change', '[data-hier-toggle]', function() {
            var level = $(this).data('hier-toggle');
            var visible = this.checked;
            var map = window.graphic_map;
            if (!map) return;
            // Map hierarchy level to route-maplibre.js layer IDs
            var layerMap = {
                super: ['artcc-super-fill', 'artcc-super-lines', 'artcc-super-labels'],
                fir: ['artcc-fir-fill', 'artcc-fir-lines', 'artcc-fir-labels'],
                sub: ['artcc-sub-fill', 'artcc-sub-lines', 'artcc-sub-labels'],
                deep: ['artcc-deep-fill', 'artcc-deep-lines', 'artcc-deep-labels'],
            };
            var layers = layerMap[level];
            if (layers) {
                layers.forEach(function(layerId) {
                    if (map.getLayer(layerId)) {
                        map.setLayoutProperty(layerId, 'visibility', visible ? 'visible' : 'none');
                    }
                });
            }
        });

        // Region color toggle
        $(document).on('change', '#pb_region_color_toggle', function() {
            regionColorEnabled = this.checked;
            if (activePlayData) renderDetailPanel(activePlayData, activePlayData.routes);
        });

        // Action buttons (in detail panel)
        $(document).on('click', '#pb_copy_link_btn', function() {
            if (!activePlayData) return;
            var url = new URL(window.location);
            url.searchParams.set('play', activePlayData.play_name);
            navigator.clipboard.writeText(url.toString()).then(function() {
                if (typeof Swal !== 'undefined') Swal.fire({ icon: 'success', title: t('playbook.linkCopied'), timer: 1200, showConfirmButton: false });
            });
        });
        $(document).on('click', '#pb_activate_btn', activateAsReroute);

        // Edit
        $(document).on('click', '#pb_edit_btn', function() {
            if (activePlayData) {
                openEditModal(activePlayData, activePlayData.routes || []);
            }
        });

        // Inline facilities edit
        $(document).on('click', '#pb_fac_edit_btn', function() {
            $('#pb_fac_display').hide();
            $(this).hide();
            $('#pb_fac_edit_wrap').show();
            $('#pb_fac_input').focus().select();
        });
        $(document).on('click', '#pb_fac_cancel', function() {
            $('#pb_fac_edit_wrap').hide();
            $('#pb_fac_display, #pb_fac_edit_btn').show();
        });
        $(document).on('click', '#pb_fac_save', function() {
            if (!activePlayData) return;
            var newVal = $('#pb_fac_input').val().trim();
            var play = activePlayData;
            // Save via full play save (reuses existing endpoint)
            var payload = {
                play_id: play.play_id,
                play_name: play.play_name,
                display_name: play.display_name || '',
                description: play.description || '',
                category: play.category || '',
                scenario_type: play.scenario_type || '',
                route_format: play.route_format || 'standard',
                status: play.status || 'active',
                airac_cycle: play.airac_cycle || '',
                facilities_involved: newVal,
                impacted_area: newVal,
                remarks: play.remarks || '',
                source: play.source || 'DCC',
                org_code: play.org_code || null,
                routes: (play.routes || []).map(function(r) {
                    return {
                        route_string: r.route_string,
                        origin: r.origin || '',
                        origin_filter: r.origin_filter || '',
                        dest: r.dest || '',
                        dest_filter: r.dest_filter || '',
                        origin_airports: r.origin_airports || '',
                        origin_tracons: r.origin_tracons || '',
                        origin_artccs: r.origin_artccs || '',
                        dest_airports: r.dest_airports || '',
                        dest_tracons: r.dest_tracons || '',
                        dest_artccs: r.dest_artccs || ''
                    };
                })
            };
            $.ajax({
                url: API_SAVE,
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(payload),
                success: function(res) {
                    if (res && res.success) {
                        play.facilities_involved = newVal;
                        play.impacted_area = newVal;
                        $('#pb_fac_display').html(newVal ? renderFacilityCodes(newVal, '/') : '<span class="text-muted">none</span>');
                        $('#pb_fac_edit_wrap').hide();
                        $('#pb_fac_display, #pb_fac_edit_btn').show();
                        if (typeof PERTIDialog !== 'undefined') PERTIDialog.toast(t('playbook.facilitiesUpdated'), 'success');
                    }
                },
                error: function() {
                    if (typeof PERTIDialog !== 'undefined') PERTIDialog.toast(t('playbook.saveFailed'), 'error');
                }
            });
        });
        $(document).on('keydown', '#pb_fac_input', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); $('#pb_fac_save').click(); }
            if (e.key === 'Escape') { $('#pb_fac_cancel').click(); }
        });

        // Duplicate
        $(document).on('click', '#pb_duplicate_btn', function() {
            if (activePlayData) {
                duplicatePlay(activePlayData, activePlayData.routes || []);
            }
        });

        // Category dropdown "Custom..." option
        $(document).on('change', '#pb_edit_category', function() {
            if ($(this).val() === '__custom__') {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        title: t('playbook.customCategory'),
                        input: 'text',
                        inputPlaceholder: t('playbook.customCategoryPlaceholder'),
                        showCancelButton: true,
                        confirmButtonText: t('common.ok'),
                        cancelButtonText: t('common.cancel'),
                        didOpen: function() {
                            // Prevent Bootstrap modal from stealing focus from SweetAlert2 input
                            $(document).off('focusin.bs.modal');
                        }
                    }).then(function(result) {
                        if (result.isConfirmed && result.value) {
                            var custom = result.value.trim();
                            var $sel = $('#pb_edit_category');
                            // Add custom option if not already present
                            if (!$sel.find('option[value="' + custom + '"]').length) {
                                $sel.find('option[value="__custom__"]').before(
                                    '<option value="' + escHtml(custom) + '">' + escHtml(custom) + '</option>'
                                );
                            }
                            $sel.val(custom);
                        } else {
                            setCategoryDropdown('');
                        }
                    });
                } else {
                    var custom = prompt('Enter category name:');
                    if (custom) {
                        var $sel = $('#pb_edit_category');
                        $sel.find('option[value="__custom__"]').before(
                            '<option value="' + escHtml(custom) + '">' + escHtml(custom) + '</option>'
                        );
                        $sel.val(custom);
                    } else {
                        setCategoryDropdown('');
                    }
                }
            }
        });

        // Archive / Restore
        $(document).on('click', '#pb_archive_btn', function() {
            if (activePlayId) archivePlay(activePlayId);
        });
        $(document).on('click', '#pb_restore_btn', function() {
            if (activePlayId) restorePlay(activePlayId);
        });

        // Create
        $('#pb_create_btn').on('click', openCreateModal);

        // Save
        $('#pb_save_play_btn').on('click', savePlay);

        // Add route row in edit modal
        $('#pb_add_route_btn').on('click', function() { addEditRouteRow(); });

        // Delete route row in edit modal
        $(document).on('click', '.pb-re-delete', function() {
            $(this).closest('tr').remove();
        });

        // Bulk paste toggle
        $('#pb_bulk_paste_btn').on('click', function() {
            $('#pb_bulk_paste_area').slideToggle(150);
        });
        $('#pb_bulk_paste_apply').on('click', applyBulkPaste);

        // Changelog toggle
        // Traversed facilities toggle
        $(document).on('click', '#pb_traversed_toggle', function() {
            var $this = $(this);
            var body = $('#pb_traversed_body');
            if (body.is(':visible')) {
                body.slideUp(150);
                $this.removeClass('expanded');
            } else {
                body.slideDown(150);
                $this.addClass('expanded');
            }
        });

        $(document).on('click', '#pb_changelog_toggle', function() {
            var $this = $(this);
            var content = $('#pb_changelog_content');
            if (content.is(':visible')) {
                content.slideUp(150);
                $this.removeClass('expanded');
            } else {
                content.slideDown(150);
                $this.addClass('expanded');
                if (activePlayId && !content.find('.pb-changelog-list').length) {
                    loadChangelog(activePlayId);
                }
            }
        });

        // Re-plot when route selection changes
        $(document).on('change', '.pb-route-cb, #pb_check_all', function() {
            if (activePlayData) plotOnMap();
        });

        // ── Group event handlers ──

        // New Group: show inline create form
        $(document).on('click', '#pb_group_new', function() {
            if (!selectedRouteIds.size) {
                PERTIDialog.toast(t('playbook.groups.noRoutesSelected'), 'info');
                return;
            }
            var suggested = suggestGroupName(selectedRouteIds);
            var color = nextGroupColor();
            $('#pb_group_name_input').val(suggested);
            // Pre-select swatch
            $('.pb-group-swatch').removeClass('active');
            $('.pb-group-swatch[data-color="' + color + '"]').addClass('active');
            $('#pb_group_create_form').slideDown(150);
            $('#pb_group_name_input').focus().select();
        });

        // Palette swatch click
        $(document).on('click', '.pb-group-swatch', function() {
            $('.pb-group-swatch').removeClass('active');
            $(this).addClass('active');
        });

        // Create/Update group confirm (unified handler)
        $(document).on('click', '#pb_group_create_confirm', function() {
            var name = $('#pb_group_name_input').val().trim() || t('playbook.groups.defaultName');
            var color = $('.pb-group-swatch.active').data('color') || nextGroupColor();

            if (groupEditingIdx >= 0 && groupEditingIdx < routeGroups.length) {
                // UPDATE existing group
                var g = routeGroups[groupEditingIdx];
                var ids = new Set();
                selectedRouteIds.forEach(function(rid) { ids.add(rid); });

                // Remove selected routes from other groups
                routeGroups.forEach(function(og, oi) {
                    if (oi === groupEditingIdx) return;
                    ids.forEach(function(rid) { og.route_ids.delete(rid); });
                });
                routeGroups = routeGroups.filter(function(og, oi) {
                    return oi === groupEditingIdx || og.route_ids.size > 0;
                });

                // Recalculate editing index after filter
                var newIdx = routeGroups.indexOf(g);
                if (newIdx >= 0) {
                    routeGroups[newIdx].group_name = name;
                    routeGroups[newIdx].group_color = color;
                    routeGroups[newIdx].route_ids = ids;
                }

                groupEditingIdx = -1;
                $('#pb_group_create_confirm').html('<i class="fas fa-check mr-1"></i>' + t('playbook.groups.create'));
                $('#pb_group_create_form').slideUp(150);
                saveGroups();
                refreshGroupUI();
                PERTIDialog.toast(t('playbook.groups.groupsSaved'), 'success');
            } else {
                // CREATE new group
                createGroupFromSelection(name, color);
                $('#pb_group_create_form').slideUp(150);
                PERTIDialog.toast(t('playbook.groups.groupCreated'), 'success');
            }
        });
        $(document).on('keydown', '#pb_group_name_input', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); $('#pb_group_create_confirm').click(); }
            if (e.key === 'Escape') { $('#pb_group_create_cancel').click(); }
        });

        // Create/Edit group cancel
        $(document).on('click', '#pb_group_create_cancel', function() {
            groupEditingIdx = -1;
            $('#pb_group_create_confirm').html('<i class="fas fa-check mr-1"></i>' + t('playbook.groups.create'));
            $('#pb_group_create_form').slideUp(150);
        });

        // Delete group
        $(document).on('click', '.pb-group-delete-btn', function() {
            var idx = parseInt($(this).data('idx'));
            deleteGroup(idx);
            PERTIDialog.toast(t('playbook.groups.groupDeleted'), 'success');
        });

        // Edit group (open inline form pre-filled, set edit state)
        $(document).on('click', '.pb-group-edit-btn', function() {
            var idx = parseInt($(this).data('idx'));
            if (idx < 0 || idx >= routeGroups.length) return;
            var g = routeGroups[idx];
            groupEditingIdx = idx;

            selectedRouteIds.clear();
            g.route_ids.forEach(function(rid) { selectedRouteIds.add(rid); });
            updateCheckboxes();

            $('#pb_group_name_input').val(g.group_name);
            $('.pb-group-swatch').removeClass('active');
            $('.pb-group-swatch[data-color="' + g.group_color + '"]').addClass('active');
            $('#pb_group_create_confirm').html('<i class="fas fa-check mr-1"></i>' + t('playbook.groups.update'));
            $('#pb_group_create_form').slideDown(150);
            $('#pb_group_name_input').focus().select();
        });

        // Clear all groups
        $(document).on('click', '#pb_group_clear', function() {
            PERTIDialog.confirmDanger(
                t('playbook.groups.confirmClear'),
                t('playbook.groups.confirmClearText')
            ).then(function(result) {
                if (result.isConfirmed) {
                    clearAllGroups();
                    PERTIDialog.toast(t('playbook.groups.groupsCleared'), 'success');
                }
            });
        });

        // Auto-group option click
        $(document).on('click', '.pb-auto-group-opt', function() {
            var field = $(this).data('field');
            var suffix = $(this).data('suffix') || '';
            $('.pb-cb-dropdown').removeClass('open');

            var doIt = function() {
                if (field === 'common_segment') {
                    autoGroupByCommonSegment();
                } else if (field === 'dcc_region_origin') {
                    autoGroupByDCCRegion('origin');
                } else if (field === 'dcc_region_dest') {
                    autoGroupByDCCRegion('dest');
                } else {
                    autoGroupByField(field, suffix);
                }
                PERTIDialog.toast(t('playbook.groups.groupsSaved'), 'success');
            };

            if (routeGroups.length) {
                PERTIDialog.confirmDanger(
                    t('playbook.groups.confirmReplace'),
                    t('playbook.groups.confirmReplaceText')
                ).then(function(result) {
                    if (result.isConfirmed) doIt();
                });
            } else {
                doIt();
            }
        });

        // Click group name to scroll to those routes
        $(document).on('click', '.pb-group-item-name', function() {
            var idx = parseInt($(this).closest('.pb-group-item').data('group-idx'));
            if (idx < 0 || idx >= routeGroups.length) return;
            var firstId = Array.from(routeGroups[idx].route_ids)[0];
            if (firstId) {
                var $row = $('tr[data-route-id="' + firstId + '"]');
                if ($row.length) {
                    $row[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
                    $row.addClass('pb-route-flash');
                    setTimeout(function() { $row.removeClass('pb-route-flash'); }, 1500);
                }
            }
        });
    });

})();
