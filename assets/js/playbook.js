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
    var regionColorEnabled = false;
    var currentSearchClauses = [];  // Set by applyFilters(), read by route emphasis

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
    // Qualifiers: orig: dest: thru: (or via:)
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
                // Parse qualifier prefix (orig:, dest:, thru:, via:)
                var qualifier = '';
                var colonIdx = term.indexOf(':');
                if (colonIdx > 0) {
                    var prefix = term.substring(0, colonIdx).toLowerCase();
                    if (prefix === 'orig' || prefix === 'dest' || prefix === 'thru' || prefix === 'via') {
                        qualifier = prefix === 'via' ? 'thru' : prefix;
                        term = term.substring(colonIdx + 1);
                    }
                }
                // Propagate qualifier from first alt: orig:ZNY,ZDC → both get orig
                if (qualifier) { inheritedQualifier = qualifier; }
                else if (inheritedQualifier) { qualifier = inheritedQualifier; }
                // Propagate negation from first alt: -thru:ZFW,ZAU → both get negated
                if (idx === 0 && negated) { inheritedNegated = true; }
                else if (idx > 0 && !negated && inheritedNegated) { negated = true; }
                return { term: term.toUpperCase(), negated: negated, qualifier: qualifier };
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
             p.agg_origin_artccs, p.agg_dest_artccs].forEach(function(field) {
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

            // Partition: negated thru alts vs everything else
            var negatedThruAlts = alts.filter(function(a) { return a.negated && a.qualifier === 'thru'; });
            var otherAlts = alts.filter(function(a) { return !(a.negated && a.qualifier === 'thru'); });

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
                } else if (alt.qualifier === 'thru') {
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
        // Origin+dest ARTCCs are traversed by definition
        csvSplit(route.origin_artccs).forEach(function(c) { if (c) thruCodes.add(c.toUpperCase()); });
        csvSplit(route.dest_artccs).forEach(function(c) { if (c) thruCodes.add(c.toUpperCase()); });
        var allCodes = new Set();
        origCodes.forEach(function(c) { allCodes.add(c); });
        destCodes.forEach(function(c) { allCodes.add(c); });
        thruCodes.forEach(function(c) { allCodes.add(c); });
        var textBlob = ((route.route_string || '') + ' ' + (route.origin || '') + ' ' + (route.dest || '')).toUpperCase();

        for (var i = 0; i < clauses.length; i++) {
            var alts = clauses[i];

            // Partition: negated thru alts vs everything else
            var negatedThruAlts = alts.filter(function(a) { return a.negated && a.qualifier === 'thru'; });
            var otherAlts = alts.filter(function(a) { return !(a.negated && a.qualifier === 'thru'); });

            // Negated thru: exclude only if ALL are found (AND semantics)
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
                else if (alt.qualifier === 'thru') found = thruCodes.has(alt.term);
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
    // MAP FACILITY HIGHLIGHTING — color ARTCC boundaries based on search
    // =========================================================================

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
     * Extract facility codes from parsed search clauses and update map layers.
     * Included (positive) terms → green fill, excluded (negated) → red fill.
     */
    function updateMapHighlights(clauses) {
        var map = window.graphic_map;
        if (!map || !map.getLayer || !map.getLayer('artcc-search-include')) return;
        var hasLineLayer = map.getLayer('artcc-search-include-line');

        var includeCodes = [];
        var excludeCodes = [];
        var hasFH = typeof FacilityHierarchy !== 'undefined' && FacilityHierarchy.isLoaded;

        clauses.forEach(function(alts) {
            alts.forEach(function(alt) {
                var term = alt.term;
                // Only highlight recognized ARTCC codes on the map
                var isArtcc = false;
                if (hasFH) {
                    isArtcc = FacilityHierarchy.isArtcc(term);
                } else {
                    isArtcc = /^Z[A-Z]{2}$/.test(term) || /^[KC]Z[A-Z]{1,2}$/.test(term);
                }
                if (isArtcc) {
                    var icao = toIcaoCode(term);
                    if (alt.negated) {
                        excludeCodes.push(icao);
                    } else {
                        includeCodes.push(icao);
                    }
                }
            });
        });

        // Build MapLibre filter expressions: ['in', 'ICAOCODE', 'KZNY', 'KZOB', ...]
        var inclFilter = includeCodes.length ? ['in', 'ICAOCODE'].concat(includeCodes) : ['in', 'ICAOCODE', ''];
        var exclFilter = excludeCodes.length ? ['in', 'ICAOCODE'].concat(excludeCodes) : ['in', 'ICAOCODE', ''];
        map.setFilter('artcc-search-include', inclFilter);
        map.setFilter('artcc-search-exclude', exclFilter);
        if (hasLineLayer) {
            map.setFilter('artcc-search-include-line', inclFilter);
            map.setFilter('artcc-search-exclude-line', exclFilter);
        }
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

        map.setFilter('artcc-play-origin', origArr.length ? ['in', 'ICAOCODE'].concat(origArr) : ['in', 'ICAOCODE', '']);
        map.setFilter('artcc-play-dest', destArr.length ? ['in', 'ICAOCODE'].concat(destArr) : ['in', 'ICAOCODE', '']);
        map.setFilter('artcc-play-traversed', travArr.length ? ['in', 'ICAOCODE'].concat(travArr) : ['in', 'ICAOCODE', '']);
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
                else if (alt.qualifier === 'thru') prefix = 'THRU: ';

                var label = (alt.negated ? '-' : '') + prefix + alt.term;

                // Badge FILL = DCC region bg color; BORDER = green (incl) or red (excl)
                var bgStyle = '';
                if (hasFH) {
                    var regionBg = FacilityHierarchy.getRegionBgColor(alt.term);
                    var regionColor = FacilityHierarchy.getRegionColor(alt.term);
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
            per_page: 1000,
            hide_legacy: showLegacy ? 0 : 1
        };

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

        var html = '<ul class="pb-play-list">';
        filteredPlays.forEach(function(p) {
            var isActive = p.play_id == activePlayId;
            html += '<li class="pb-play-row' + (isActive ? ' active' : '') + '" data-play-id="' + p.play_id + '">';
            html += '<span class="pb-play-row-name">' + escHtml(p.play_name) + '</span>';
            html += '<span class="pb-play-row-meta">';
            if (p.category) html += '<span class="pb-badge pb-badge-category">' + escHtml(p.category) + '</span>';
            html += '<span class="pb-badge pb-badge-routes">' + (p.route_count || 0) + '</span>';
            html += '<span class="pb-badge pb-badge-' + (p.source || 'dcc').toLowerCase() + '">' + escHtml(p.source || 'DCC') + '</span>';
            if (p.status === 'draft') html += '<span class="pb-badge pb-badge-draft">' + t('playbook.statusDraft') + '</span>';
            html += '</span>';
            html += '</li>';
        });
        html += '</ul>';

        $('#pb_play_list_container').html(html);
    }

    // =========================================================================
    // PLAY DETAIL
    // =========================================================================

    function loadPlayDetail(playId) {
        activePlayId = playId;
        selectedRouteIds.clear();

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
            renderDetailPanel(play, routes);

            // Auto-plot routes on map
            plotOnMap();
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
        if (hasPerm && play.source !== 'FAA') {
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
        if (play.source) metaParts.push('<span class="pb-badge pb-badge-' + (play.source || 'dcc').toLowerCase() + '">' + escHtml(play.source) + '</span>');
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
            html += '<div class="pb-play-remarks"><strong>Remarks:</strong> ' + escHtml(play.remarks) + '</div>';
        }

        // Facilities (inline-editable with optional region coloring)
        var facStr = play.impacted_area || play.facilities_involved || '';
        html += '<div class="pb-play-facilities">';
        html += '<strong>Facilities:</strong> ';
        html += '<span class="pb-fac-display" id="pb_fac_display">' + (facStr ? renderFacilityCodes(facStr, '/') : '<span class="text-muted">none</span>') + '</span>';
        if (hasPerm && play.source !== 'FAA') {
            html += ' <button class="btn btn-xs pb-inline-edit-btn" id="pb_fac_edit_btn" title="Edit facilities"><i class="fas fa-pencil-alt"></i></button>';
            html += '<div class="pb-fac-edit-wrap" id="pb_fac_edit_wrap" style="display:none;">';
            html += '<input type="text" class="form-control form-control-sm pb-fac-input" id="pb_fac_input" value="' + escHtml(facStr) + '" placeholder="ZNY/ZOB/ZAU...">';
            html += '<div class="pb-fac-edit-actions">';
            html += '<button class="btn btn-xs btn-primary" id="pb_fac_save"><i class="fas fa-check"></i></button>';
            html += '<button class="btn btn-xs btn-secondary" id="pb_fac_cancel"><i class="fas fa-times"></i></button>';
            html += '</div></div>';
        }
        html += ' <label class="pb-region-toggle mb-0" title="Color by DCC region"><input type="checkbox" id="pb_region_color_toggle"' + (regionColorEnabled ? ' checked' : '') + '><span class="small">Region colors</span></label>';
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
                html += '<div class="pb-play-traffic"><strong>Included Traffic:</strong> ';
                if (origArr.length) {
                    html += origArr.map(regionColorWrap).join('/<wbr>') + ' departures';
                }
                if (origArr.length && destArr.length) html += ' to ';
                if (destArr.length) {
                    if (!origArr.length) html += 'Arrivals to ';
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
                if (travSecHighArr.length) {
                    html += '<div class="pb-trav-row"><span class="pb-trav-label">' + t('playbook.traversedSectorsHigh') + ':</span> ' + travSecHighArr.map(function(c) { return '<span class="pb-fac-code">' + escHtml(c) + '</span>'; }).join(', ') + '</div>';
                }
                if (travSecLowArr.length) {
                    html += '<div class="pb-trav-row"><span class="pb-trav-label">' + t('playbook.traversedSectorsLow') + ':</span> ' + travSecLowArr.map(function(c) { return '<span class="pb-fac-code">' + escHtml(c) + '</span>'; }).join(', ') + '</div>';
                }
                if (travSecSuperArr.length) {
                    html += '<div class="pb-trav-row"><span class="pb-trav-label">' + t('playbook.traversedSectorsSuperhigh') + ':</span> ' + travSecSuperArr.map(function(c) { return '<span class="pb-fac-code">' + escHtml(c) + '</span>'; }).join(', ') + '</div>';
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
                html += buildRegionDropdown('pb_select_region', 'Region', selOpts.regions);
            }
            html += '<input type="text" class="form-control form-control-sm pb-select-route-text" id="pb_select_route_text" placeholder="Route text...">';
            html += '<button class="btn btn-xs btn-outline-danger pb-clear-filters" id="pb_clear_filters" title="' + t('playbook.clearFilters') + '"><i class="fas fa-times"></i></button>';
            html += '</div>';

            // Route action toolbar
            html += '<div class="pb-route-toolbar" id="pb_route_toolbar">';
            html += '<button class="btn btn-xs btn-outline-primary" id="pb_open_route_page" title="Open in Route Page"><i class="fas fa-route mr-1"></i>' + t('playbook.openRoutePage') + '</button>';
            html += '<button class="btn btn-xs btn-outline-info" id="pb_activate_reroute" title="Use in TMI Publish"><i class="fas fa-paper-plane mr-1"></i>' + t('playbook.useInTMI') + '</button>';
            html += '<span class="pb-toolbar-sep">|</span>';
            html += '<button class="btn btn-xs btn-outline-secondary" id="pb_copy_pb_directive" title="Copy PB directive to clipboard"><i class="fas fa-clipboard mr-1"></i>' + t('playbook.copyPB') + '</button>';
            html += '</div>';
        }

        $('#pb_info_content').html(html);
        $('#pb_info_overlay').show();
    }

    function renderRouteTable(play, routes) {
        var html = '';
        var hasSearch = currentSearchClauses.length > 0;

        if (routes.length) {
            // Select All row + route count
            html += '<div class="d-flex justify-content-between align-items-center mb-1">';
            html += '<span class="pb-select-all" id="pb_select_all">' + t('playbook.selectAll') + '</span>';
            html += '<span style="font-size:0.68rem;color:#999;">' + routes.length + ' ' + t('playbook.routes').toLowerCase() + '</span>';
            html += '</div>';

            html += '<div class="pb-route-table-wrap">';
            html += '<table class="pb-route-table"><thead><tr>';
            html += '<th class="pb-route-check"><input type="checkbox" id="pb_check_all"></th>';
            html += '<th>Origin</th>';
            html += '<th>TRACON</th>';
            html += '<th>ARTCC</th>';
            html += '<th>' + t('playbook.routeString') + '</th>';
            html += '<th>Dest</th>';
            html += '<th>TRACON</th>';
            html += '<th>ARTCC</th>';
            html += '<th>Traversed</th>';
            html += '</tr></thead><tbody>';

            routes.forEach(function(r) {
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
                var rowClass = hasSearch ? (searchMatch ? 'pb-route-emphasized' : 'pb-route-dimmed') : '';

                html += '<tr data-route-id="' + r.route_id + '"' + (rowClass ? ' class="' + rowClass + '"' : '') + '>';
                html += '<td class="pb-route-check"><input type="checkbox" class="pb-route-cb" value="' + r.route_id + '"' + (selectedRouteIds.has(r.route_id) ? ' checked' : '') + '></td>';
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
        updateToolbarVisibility();
    }

    function renderDetailPanel(play, routes) {
        renderInfoOverlay(play, routes);
        renderRouteTable(play, routes);
        updatePlayHighlights(play, routes);
        $('#pb_map_legend').css('display', '');
    }

    function updateToolbarVisibility() {
        // Toolbar always visible; no-op kept for call sites
    }

    function hideDetail() {
        activePlayId = null;
        activePlayData = null;
        selectedRouteIds.clear();
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
        } else {
            // No search: use PB directive or manual routes (default colors)
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
        $('#pb_modal_title').text('Duplicate Play');
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
            activeSource = $(this).data('source') || '';
            $('.pb-src-btn').removeClass('active');
            $(this).addClass('active');
            applyFilters();
        });

        // Legacy toggle
        $('#pb_legacy_toggle').on('change', function() {
            showLegacy = this.checked;
            loadPlays(); // Re-fetch from API since hide_legacy is server-side
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
                if (typeof Swal !== 'undefined') Swal.fire({ icon: 'success', title: 'Link copied', timer: 1200, showConfirmButton: false });
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
                        title: 'Custom Category',
                        input: 'text',
                        inputPlaceholder: 'Enter category name',
                        showCancelButton: true,
                        confirmButtonText: t('common.ok'),
                        cancelButtonText: t('common.cancel')
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
    });

})();
