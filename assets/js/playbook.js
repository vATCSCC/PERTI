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
    var API_ACL         = 'api/data/playbook/acl.php';
    var API_ACL_MGT     = 'api/mgt/playbook/acl.php';
    var API_USER_SEARCH = 'api/data/playbook/user_search.php';
    var API_ORG_MEMBERS = 'api/data/playbook/org_members.php';
    var API_ORGS        = 'api/data/playbook/orgs.php';
    var API_ANALYSIS    = 'api/data/playbook/analysis.php';
    var API_THROUGHPUT  = 'api/swim/v1/playbook/throughput';
    var API_NAT_TRACKS  = 'api/data/playbook/nat_tracks.php';

    var t = typeof PERTII18n !== 'undefined' ? PERTII18n.t.bind(PERTII18n) : function(k) { return k; };
    var hasPerm = window.PERTI_PLAYBOOK_PERM === true;
    var sessionCid = window.PERTI_PLAYBOOK_CID || null;
    var isAdmin = window.PERTI_PLAYBOOK_ADMIN === true;

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

    // Changelog pagination state
    var clCompactPage = 1, clCompactPerPage = 20, clCompactPlayId = null;
    var clDetailPage  = 1, clDetailPerPage  = 25, clDetailPlayId  = null;
    var currentSearchClauses = [];  // Set by applyFilters(), read by route emphasis

    // Route group state
    var routeGroups = [];           // Array of { group_name, group_color, route_ids: Set, sort_order }
    var groupEditingIdx = -1;       // Index of group being edited (-1 = none)

    // Route view mode: 'standard' | 'consolidated' | 'compact'
    var routeViewMode = 'standard';

    // Route analysis state
    var activeAnalysisRouteId = null;

    // Throughput toggle state
    var throughputEnabled = false;
    var throughputData = null;
    var lastPlottedRouteOrder = []; // DB route_ids in plotting order → map routeId = index+1

    // NAT track cache: { 'NATA': 'FIX1 FIX2 ...', ... }
    var natTrackCache = {};

    // ACL sharing state
    var aclSearchTimer = null;
    var aclOrgCache = null;         // Cached org list from API
    var aclSelectedOrgs = new Set(); // Selected org codes for org sharing
    var aclExcludedCids = new Set(); // CIDs excluded from org sharing

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

    function visibilityBadge(vis) {
        if (!vis || vis === 'public') return '';
        var icon, label, cls;
        switch (vis) {
            case 'local':
                icon = 'fa-lock'; label = t('playbook.visibility.local'); cls = 'pb-vis-local'; break;
            case 'private_users':
                icon = 'fa-users'; label = t('playbook.visibility.privateUsers'); cls = 'pb-vis-private'; break;
            case 'private_org':
                icon = 'fa-building'; label = t('playbook.visibility.privateOrg'); cls = 'pb-vis-org'; break;
            default: return '';
        }
        return '<span class="pb-badge pb-visibility-badge ' + cls + '" title="' + escHtml(label) + '"><i class="fas ' + icon + '"></i></span>';
    }

    function canEditPlay(play) {
        if (isAdmin) return true;
        if (play.can_edit !== undefined) return !!play.can_edit;
        return hasPerm;
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
     * Build a filter group string from a DB filter field (e.g. "-KDFW -KAFW" -> "(-KDFW -KAFW)").
     * Returns empty string if no valid filters. Normalizes codes without '-' prefix.
     */
    function buildFilterGroup(filterStr) {
        if (!filterStr) return '';
        var filters = filterStr.trim().split(/\s+/).filter(Boolean);
        if (!filters.length) return '';
        return '(' + filters.map(function(f) {
            return f.charAt(0) === '-' ? f : '-' + f;
        }).join(' ') + ')';
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
                // Push canonical form (e.g. KZOA→ZOA, PAZA→ZAN)
                artccs.push(FacilityHierarchy.resolveAlias ? FacilityHierarchy.resolveAlias(resolved) : resolved);
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
            } else if ((typeof FacilityHierarchy !== 'undefined' && FacilityHierarchy.isArtcc && FacilityHierarchy.isArtcc(tok)) || /^(Z[A-Z]{2}|CZ[A-Z]{2})$/.test(tok)) {
                // Regex fallback for ARTCC/FIR codes — push canonical form
                artccs.push(hasFH && FacilityHierarchy.resolveAlias ? FacilityHierarchy.resolveAlias(tok) : tok);
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
        var joinSep = (separator === '/') ? '/<wbr>' : ',<wbr> ';
        if (!regionColorEnabled) return escHtml(codes.join(separator === '/' ? '/' : ', ')).replace(/[,\/]/g, function(m) { return m + '<wbr>'; });
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
            html += visibilityBadge(p.visibility);
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

        // Action buttons — use per-play can_edit from API
        var playEditable = canEditPlay(play);
        html += '<div class="pb-actions mb-1" style="flex-wrap:wrap;">';
        html += '<button class="btn btn-outline-info btn-sm" id="pb_copy_link_btn"><i class="fas fa-link mr-1"></i>Copy Link</button>';
        html += '<button class="btn btn-warning btn-sm" id="pb_activate_btn"><i class="fas fa-paper-plane mr-1"></i>' + t('playbook.activateReroute') + '</button>';
        if (hasPerm) {
            html += '<button class="btn btn-outline-primary btn-sm" id="pb_duplicate_btn"><i class="fas fa-copy mr-1"></i>Duplicate</button>';
        }
        if (playEditable && play.source !== 'FAA' && play.source !== 'FAA_HISTORICAL') {
            html += '<button class="btn btn-outline-secondary btn-sm" id="pb_edit_btn"><i class="fas fa-edit mr-1"></i>' + t('common.edit') + '</button>';
        }
        if (playEditable) {
            if (play.status === 'active' || play.status === 'draft') {
                html += '<button class="btn btn-outline-danger btn-sm" id="pb_archive_btn"><i class="fas fa-archive mr-1"></i>' + t('playbook.archive') + '</button>';
            } else if (play.status === 'archived') {
                html += '<button class="btn btn-outline-success btn-sm" id="pb_restore_btn"><i class="fas fa-undo mr-1"></i>' + t('playbook.restore') + '</button>';
            }
        }
        html += '<div class="btn-group btn-group-sm ml-1" role="group">';
        html += '<button class="btn btn-outline-primary btn-sm" id="pb_export_geojson" title="' + t('playbook.export.geojsonTitle') + '"><i class="fas fa-file-code mr-1"></i>GeoJSON</button>';
        html += '<button class="btn btn-outline-primary btn-sm" id="pb_export_kml" title="' + t('playbook.export.kmlTitle') + '"><i class="fas fa-globe mr-1"></i>KML</button>';
        html += '<button class="btn btn-outline-primary btn-sm" id="pb_export_csv" title="' + t('playbook.export.csvTitle') + '"><i class="fas fa-file-csv mr-1"></i>CSV</button>';
        html += '</div>';
        html += '</div>';

        // Metadata badges
        var metaParts = [];
        var visBadge = visibilityBadge(play.visibility);
        if (visBadge) metaParts.push(visBadge);
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
            html += '<div class="pb-play-description" style="white-space:pre-wrap;">' + escHtml(play.description) + '</div>';
        }

        // Play-level remarks
        if (play.remarks) {
            html += '<div class="pb-play-remarks" style="white-space:pre-wrap;"><strong>' + t('playbook.remarks') + ':</strong> ' + escHtml(play.remarks) + '</div>';
        }

        // Facilities (inline-editable with optional region coloring)
        var facStr = play.impacted_area || play.facilities_involved || '';
        html += '<div class="pb-play-facilities">';
        html += '<strong>' + t('playbook.legendTitle') + ':</strong> ';
        html += '<span class="pb-fac-display" id="pb_fac_display">' + (facStr ? renderFacilityCodes(facStr, '/') : '<span class="text-muted">' + t('common.none') + '</span>') + '</span>';
        if (playEditable && play.source !== 'FAA' && play.source !== 'FAA_HISTORICAL') {
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
            // Preserve route-of-flight order: JS Sets maintain insertion order,
            // so iterating routes in sort_order gives first-seen = traversal order.
            var travArtccArr = Array.from(travArtccs);
            var travTraconArr = Array.from(travTracons);
            var travSecLowArr = Array.from(travSecLow);
            var travSecHighArr = Array.from(travSecHigh);
            var travSecSuperArr = Array.from(travSecSuper);
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
            html += '<button class="btn btn-xs btn-outline-secondary" id="pb_copy_menu_trigger" title="' + t('playbook.copy') + '"><i class="fas fa-clipboard mr-1"></i>' + t('playbook.copy') + ' <i class="fas fa-caret-down" style="font-size:0.55rem;margin-left:2px;"></i></button>';
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
            // Select All row + route count + view toggle
            html += '<div class="d-flex justify-content-between align-items-center mb-1">';
            html += '<span class="pb-select-all" id="pb_select_all">' + t('playbook.selectAll') + '</span>';
            html += '<div class="d-flex align-items-center">';
            html += '<span style="font-size:0.68rem;color:#999;">' + routes.length + ' ' + t('playbook.routes').toLowerCase() + '</span>';
            html += '<div class="pb-view-toggle" id="pb_view_toggle">';
            html += '<button data-view="standard" class="' + (routeViewMode === 'standard' ? 'active' : '') + '" title="' + t('playbook.standardView') + '"><i class="fas fa-list"></i></button>';
            html += '<button data-view="consolidated" class="' + (routeViewMode === 'consolidated' ? 'active' : '') + '" title="' + t('playbook.consolidateRoutes') + '"><i class="fas fa-compress-arrows-alt"></i></button>';
            html += '<button data-view="compact" class="' + (routeViewMode === 'compact' ? 'active' : '') + '" title="' + t('playbook.compactView') + '"><i class="fas fa-columns"></i></button>';
            html += '</div>';
            html += '</div>';
            html += '</div>';

            if (routeViewMode === 'compact') {
                html += renderCompactViewHtml(routes);
            } else if (routeViewMode === 'consolidated') {
                html += renderConsolidatedTableHtml(routes);
            } else {
                html += renderStandardTableHtml(routes, hasSearch, hasGroups);
            }
        } else {
            html += '<div class="pb-empty-state"><i class="fas fa-route"></i>' + t('playbook.noRoutes') + '</div>';
        }

        // Throughput toggle button
        html += '<div class="mt-2 mb-1">';
        html += '<button class="btn btn-sm btn-outline-info" id="btn-throughput-toggle">';
        html += '<i class="fas fa-chart-bar mr-1"></i>' + t('playbook.throughput.toggle');
        html += '</button>';
        html += '</div>';

        // Changelog toggle (inline, legacy)
        html += '<div class="pb-changelog">';
        html += '<div class="pb-changelog-header" id="pb_changelog_toggle"><i class="fas fa-chevron-right"></i> ' + t('playbook.changelog') + '</div>';
        html += '<div id="pb_changelog_content" style="display:none;"></div>';
        html += '</div>';

        // Changelog tab (detailed view)
        html += '<div class="mt-1">';
        html += '<div class="pb-changelog-tab-header" id="pb_changelog_tab_toggle" style="cursor:pointer;font-size:0.72rem;">';
        html += '<i class="fas fa-chevron-right mr-1"></i>' + t('playbook.changelogTab.title');
        html += '</div>';
        html += '<div id="play-changelog-panel" style="display:none;"></div>';
        html += '</div>';

        $('#pb_detail_content').html(html);
        renderGroupToolbar();
        updateToolbarVisibility();

        // Reset throughput button state on re-render
        if (throughputEnabled) {
            $('#btn-throughput-toggle').removeClass('btn-outline-info').addClass('active btn-info');
        }
    }

    // ── Standard route table (original view) ──
    function renderStandardTableHtml(routes, hasSearch, hasGroups) {
        var html = '';
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
        html += '<th class="pb-route-check" style="width:24px"><input type="checkbox" id="pb_check_all"></th>';
        if (hasGroups) html += '<th style="width:8px;padding:0;"></th>';
        html += '<th style="width:8%">Origin</th>';
        html += '<th style="width:3.5%">TRACON</th>';
        html += '<th style="width:3.5%">ARTCC</th>';
        html += '<th>' + t('playbook.routeString') + '</th>';
        html += '<th style="width:8%">Dest</th>';
        html += '<th style="width:3.5%">TRACON</th>';
        html += '<th style="width:3.5%">ARTCC</th>';
        html += '<th style="width:7%">Traversed</th>';
        html += '<th style="width:3%">Remarks</th>';
        html += '</tr></thead><tbody>';

        sortedRoutes.forEach(function(r) {
            var origApt = r.origin_airports || r.origin || '-';
            var origTracon = r.origin_tracons || '-';
            var origArtcc = r.origin_artccs || '-';
            var destApt = r.dest_airports || r.dest || '-';
            var destTracon = r.dest_tracons || '-';
            var destArtcc = r.dest_artccs || '-';

            // Space-delimit airports for route-advisory style display
            var origDisplay = origApt !== '-' ? origApt.replace(/,\s*/g, ' ') : '-';
            var destDisplay = destApt !== '-' ? destApt.replace(/,\s*/g, ' ') : '-';

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

            var searchMatch = !hasSearch || routeMatchesSearchClauses(r, currentSearchClauses);
            var rowClasses = [];
            if (hasSearch) rowClasses.push(searchMatch ? 'pb-route-emphasized' : 'pb-route-dimmed');
            var groupColor = getRouteGroupColor(r.route_id);
            if (groupColor) rowClasses.push('pb-route-grouped');
            if (activeAnalysisRouteId != null && r.route_id === activeAnalysisRouteId) rowClasses.push('pb-route-analysis-active');
            var rowClass = rowClasses.join(' ');

            html += '<tr data-route-id="' + r.route_id + '"' + (rowClass ? ' class="' + rowClass + '"' : '') + (groupColor ? ' style="border-left:4px solid ' + groupColor + ';"' : '') + '>';
            html += '<td class="pb-route-check"><input type="checkbox" class="pb-route-cb" value="' + r.route_id + '"' + (selectedRouteIds.has(r.route_id) ? ' checked' : '') + '></td>';
            if (hasGroups) {
                html += '<td style="padding:0 2px;">' + (groupColor ? '<span class="pb-group-dot-inline" style="background:' + groupColor + ';"></span>' : '') + '</td>';
            }
            html += '<td class="pb-rt-airports">' + escHtml(origDisplay) + (r.origin_filter ? ' <small class="text-muted">' + escHtml(r.origin_filter) + '</small>' : '') + '</td>';
            html += '<td>' + renderFacilityCodes(origTracon, ',') + '</td>';
            html += '<td>' + renderFacilityCodes(origArtcc, ',') + '</td>';
            html += '<td>' + escHtml(r.route_string) + '</td>';
            html += '<td class="pb-rt-airports">' + escHtml(destDisplay) + (r.dest_filter ? ' <small class="text-muted">' + escHtml(r.dest_filter) + '</small>' : '') + '</td>';
            html += '<td>' + renderFacilityCodes(destTracon, ',') + '</td>';
            html += '<td>' + renderFacilityCodes(destArtcc, ',') + '</td>';
            html += '<td class="pb-rt-traversed">' + renderFacilityCodes(r.traversed_artccs || '-', ',') + '</td>';
            html += '<td style="white-space:pre-wrap;">' + escHtml(r.remarks || '') + '</td>';
            html += '</tr>';
        });

        html += '</tbody></table></div>';
        return html;
    }

    // ── Consolidated route table (merge rows with identical route strings) ──
    function consolidateRoutes(routes) {
        var groups = {};
        routes.forEach(function(r) {
            var key = (r.route_string || '').trim().toUpperCase();
            if (!groups[key]) {
                groups[key] = {
                    route_string: r.route_string,
                    origins: new Set(),
                    dests: new Set(),
                    origin_filters: new Set(),
                    dest_filters: new Set(),
                    origin_artccs: new Set(),
                    dest_artccs: new Set(),
                    traversed_artccs: new Set(),
                    route_ids: [],
                    remarks: []
                };
            }
            var g = groups[key];
            g.route_ids.push(r.route_id);
            var origLabel = r.origin_airports || r.origin || '';
            if (origLabel) origLabel.split(',').forEach(function(o) { if (o.trim()) g.origins.add(o.trim()); });
            var destLabel = r.dest_airports || r.dest || '';
            if (destLabel) destLabel.split(',').forEach(function(d) { if (d.trim()) g.dests.add(d.trim()); });
            if (r.origin_filter) r.origin_filter.split(/\s+/).forEach(function(f) { if (f) g.origin_filters.add(f); });
            if (r.dest_filter) r.dest_filter.split(/\s+/).forEach(function(f) { if (f) g.dest_filters.add(f); });
            csvSplit(r.origin_artccs).forEach(function(a) { g.origin_artccs.add(a); });
            csvSplit(r.dest_artccs).forEach(function(a) { g.dest_artccs.add(a); });
            csvSplit(r.traversed_artccs).forEach(function(a) { g.traversed_artccs.add(a); });
            if (r.remarks && r.remarks.trim()) g.remarks.push(r.remarks.trim());
        });
        return Object.values(groups);
    }

    function renderConsolidatedTableHtml(routes) {
        var groups = consolidateRoutes(routes);
        var html = '';

        html += '<div class="pb-route-table-wrap">';
        html += '<table class="pb-route-table"><thead><tr>';
        html += '<th class="pb-route-check" style="width:24px"><input type="checkbox" id="pb_check_all"></th>';
        html += '<th style="width:10%">Origin</th>';
        html += '<th style="width:5%">ARTCC</th>';
        html += '<th>' + t('playbook.routeString') + '</th>';
        html += '<th style="width:10%">Dest</th>';
        html += '<th style="width:5%">ARTCC</th>';
        html += '<th style="width:7%">Traversed</th>';
        html += '</tr></thead><tbody>';

        groups.forEach(function(g) {
            var origStr = Array.from(g.origins).sort().join(' ');
            var destStr = Array.from(g.dests).sort().join(' ');
            var origFilterStr = Array.from(g.origin_filters).sort().join(' ');
            var destFilterStr = Array.from(g.dest_filters).sort().join(' ');
            var origArtccStr = Array.from(g.origin_artccs).sort().join(',');
            var destArtccStr = Array.from(g.dest_artccs).sort().join(',');
            var travStr = Array.from(g.traversed_artccs).join(','); // preserve traversal order
            var allSelected = g.route_ids.every(function(rid) { return selectedRouteIds.has(rid); });
            var badge = g.route_ids.length > 1 ? ' <span class="pb-consolidated-badge">' + t('playbook.consolidatedBadge', { count: g.route_ids.length }) + '</span>' : '';
            var isActive = activeAnalysisRouteId != null && g.route_ids.indexOf(activeAnalysisRouteId) !== -1;

            html += '<tr data-route-ids="' + g.route_ids.join(',') + '"' + (isActive ? ' class="pb-route-analysis-active"' : '') + '>';
            html += '<td class="pb-route-check"><input type="checkbox" class="pb-route-cb-group" data-ids="' + g.route_ids.join(',') + '"' + (allSelected ? ' checked' : '') + '></td>';
            html += '<td class="pb-rt-airports">' + escHtml(origStr || '-') + (origFilterStr ? ' <small class="text-muted">' + escHtml(origFilterStr) + '</small>' : '') + '</td>';
            html += '<td>' + renderFacilityCodes(origArtccStr || '-', ',') + '</td>';
            html += '<td>' + escHtml(g.route_string) + badge + '</td>';
            html += '<td class="pb-rt-airports">' + escHtml(destStr || '-') + (destFilterStr ? ' <small class="text-muted">' + escHtml(destFilterStr) + '</small>' : '') + '</td>';
            html += '<td>' + renderFacilityCodes(destArtccStr || '-', ',') + '</td>';
            html += '<td class="pb-rt-traversed">' + renderFacilityCodes(travStr || '-', ',') + '</td>';
            html += '</tr>';
        });

        html += '</tbody></table></div>';
        return html;
    }

    // ── Compact view (FROM / TO pivot-based display) ──
    function findPivotWaypoints(routes) {
        var fixCounts = {};
        var fixPositions = {};
        var totalRoutes = 0;

        routes.forEach(function(r) {
            var tokens = (r.route_string || '').toUpperCase().split(/\s+/).filter(Boolean);
            if (!tokens.length) return;
            totalRoutes++;
            var routeLen = tokens.length;
            var seen = {};
            tokens.forEach(function(tok, idx) {
                if (/^[A-Z]{2,5}$/.test(tok) && tok !== 'DCT') {
                    if (!seen[tok]) {
                        seen[tok] = true;
                        fixCounts[tok] = (fixCounts[tok] || 0) + 1;
                        if (!fixPositions[tok]) fixPositions[tok] = [];
                        fixPositions[tok].push(routeLen > 1 ? idx / (routeLen - 1) : 0.5);
                    }
                }
            });
        });

        if (totalRoutes < 2) return [];

        var threshold = Math.max(2, Math.floor(totalRoutes * 0.25));
        return Object.keys(fixCounts)
            .filter(function(fix) { return fixCounts[fix] >= threshold && fixCounts[fix] < totalRoutes; })
            .map(function(fix) {
                var positions = fixPositions[fix];
                var avgPos = positions.reduce(function(a, b) { return a + b; }, 0) / positions.length;
                var centrality = 1 - Math.abs(avgPos - 0.5) * 2;
                var matchPct = fixCounts[fix] / totalRoutes;
                return { fix: fix, count: fixCounts[fix], avgPos: avgPos, score: centrality * 0.7 + matchPct * 0.3 };
            })
            .sort(function(a, b) { return b.score - a.score; });
    }

    function renderCompactViewHtml(routes) {
        var pivots = findPivotWaypoints(routes);
        if (!pivots.length) {
            // No pivots — fall back to consolidated
            return renderConsolidatedTableHtml(routes);
        }

        // Assign each route to its best pivot
        var pivotGroups = {};     // pivotFix -> { fromEntries, toEntries }
        var unmatched = [];

        routes.forEach(function(r) {
            var tokens = (r.route_string || '').toUpperCase().split(/\s+/).filter(Boolean);
            var bestPivot = null;
            var bestPivotIdx = -1;
            for (var p = 0; p < pivots.length; p++) {
                var pi = tokens.indexOf(pivots[p].fix);
                if (pi !== -1) {
                    bestPivot = pivots[p].fix;
                    bestPivotIdx = pi;
                    break;
                }
            }
            if (!bestPivot) {
                unmatched.push(r);
                return;
            }
            if (!pivotGroups[bestPivot]) pivotGroups[bestPivot] = { fromEntries: [], toEntries: [] };

            var fromSegment = tokens.slice(0, bestPivotIdx + 1).join(' ');
            var toSegment = tokens.slice(bestPivotIdx).join(' ');
            var origLabel = r.origin_airports || r.origin || '';
            var destLabel = r.dest_airports || r.dest || '';

            pivotGroups[bestPivot].fromEntries.push({
                origin: origLabel,
                origin_filter: r.origin_filter || '',
                segment: fromSegment,
                route_id: r.route_id
            });
            pivotGroups[bestPivot].toEntries.push({
                dest: destLabel,
                dest_filter: r.dest_filter || '',
                segment: toSegment,
                route_id: r.route_id
            });
        });

        var html = '<div class="pb-compact-wrap">';

        // Render each pivot group
        pivots.forEach(function(p) {
            var group = pivotGroups[p.fix];
            if (!group) return;

            html += '<div class="pb-compact-pivot">';
            html += '<span>' + t('playbook.pivotFix') + ':</span> ';
            html += '<span class="pb-compact-pivot-fix">' + escHtml(p.fix) + '</span>';
            html += '<span class="pb-compact-pivot-count">' + t('playbook.routesVia', { count: group.fromEntries.length, total: routes.length, fix: p.fix }) + '</span>';
            html += '</div>';

            // Consolidate FROM entries by segment
            var fromGroups = {};
            group.fromEntries.forEach(function(e) {
                var key = e.segment;
                if (!fromGroups[key]) fromGroups[key] = { origins: new Set(), filters: new Set(), segment: e.segment, ids: [] };
                if (e.origin) e.origin.split(',').forEach(function(o) { if (o.trim()) fromGroups[key].origins.add(o.trim()); });
                if (e.origin_filter) e.origin_filter.split(/\s+/).forEach(function(f) { if (f) fromGroups[key].filters.add(f); });
                fromGroups[key].ids.push(e.route_id);
            });

            html += '<div class="pb-compact-section-label">' + t('playbook.fromRoutes') + ':</div>';
            html += '<table class="pb-compact-table"><thead><tr>';
            html += '<th style="width:30%;">Origin</th>';
            html += '<th>' + t('playbook.routeString') + '</th>';
            html += '</tr></thead><tbody>';
            Object.values(fromGroups).forEach(function(fg) {
                var origStr = Array.from(fg.origins).sort().join(' / ');
                var filterStr = Array.from(fg.filters).sort().join(' ');
                var fgActive = activeAnalysisRouteId != null && fg.ids.indexOf(activeAnalysisRouteId) !== -1;
                html += '<tr data-route-ids="' + fg.ids.join(',') + '"' + (fgActive ? ' class="pb-route-analysis-active"' : '') + '>';
                html += '<td>' + escHtml(origStr || '-') + (filterStr ? ' <small class="text-muted">' + escHtml(filterStr) + '</small>' : '') + '</td>';
                html += '<td>' + escHtml(fg.segment) + '</td>';
                html += '</tr>';
            });
            html += '</tbody></table>';

            // Consolidate TO entries by segment
            var toGroups = {};
            group.toEntries.forEach(function(e) {
                var key = e.segment;
                if (!toGroups[key]) toGroups[key] = { dests: new Set(), filters: new Set(), segment: e.segment, ids: [] };
                if (e.dest) e.dest.split(',').forEach(function(d) { if (d.trim()) toGroups[key].dests.add(d.trim()); });
                if (e.dest_filter) e.dest_filter.split(/\s+/).forEach(function(f) { if (f) toGroups[key].filters.add(f); });
                toGroups[key].ids.push(e.route_id);
            });

            html += '<div class="pb-compact-section-label">' + t('playbook.toRoutes') + ':</div>';
            html += '<table class="pb-compact-table"><thead><tr>';
            html += '<th style="width:30%;">Dest</th>';
            html += '<th>' + t('playbook.routeString') + '</th>';
            html += '</tr></thead><tbody>';
            Object.values(toGroups).forEach(function(tg) {
                var destStr = Array.from(tg.dests).sort().join(' / ');
                var filterStr = Array.from(tg.filters).sort().join(' ');
                var tgActive = activeAnalysisRouteId != null && tg.ids.indexOf(activeAnalysisRouteId) !== -1;
                html += '<tr data-route-ids="' + tg.ids.join(',') + '"' + (tgActive ? ' class="pb-route-analysis-active"' : '') + '>';
                html += '<td>' + escHtml(destStr || '-') + (filterStr ? ' <small class="text-muted">' + escHtml(filterStr) + '</small>' : '') + '</td>';
                html += '<td>' + escHtml(tg.segment) + '</td>';
                html += '</tr>';
            });
            html += '</tbody></table>';
        });

        // Unmatched routes
        if (unmatched.length) {
            html += '<div class="pb-compact-unmatched">';
            html += '<div class="pb-compact-section-label" style="color:#666;">' + t('playbook.groups.other') + ' (' + unmatched.length + ')</div>';
            html += '<table class="pb-compact-table"><thead><tr>';
            html += '<th style="width:20%;">Origin</th>';
            html += '<th>' + t('playbook.routeString') + '</th>';
            html += '<th style="width:20%;">Dest</th>';
            html += '</tr></thead><tbody>';
            unmatched.forEach(function(r) {
                var umActive = activeAnalysisRouteId != null && r.route_id === activeAnalysisRouteId;
                html += '<tr data-route-id="' + r.route_id + '"' + (umActive ? ' class="pb-route-analysis-active"' : '') + '>';
                html += '<td>' + escHtml(r.origin_airports || r.origin || '-') + '</td>';
                html += '<td>' + escHtml(r.route_string) + '</td>';
                html += '<td>' + escHtml(r.dest_airports || r.dest || '-') + '</td>';
                html += '</tr>';
            });
            html += '</tbody></table>';
            html += '</div>';
        }

        html += '</div>';
        return html;
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
        routeViewMode = 'standard';
        activeAnalysisRouteId = null;
        if (typeof RouteAnalysisPanel !== 'undefined') RouteAnalysisPanel.clear();
        throughputEnabled = false;
        throughputData = null;
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
        // Standard view
        $('.pb-route-cb').each(function() {
            var rid = parseInt($(this).val());
            this.checked = selectedRouteIds.has(rid);
        });
        // Consolidated view
        $('.pb-route-cb-group').each(function() {
            var ids = $(this).data('ids').toString().split(',').map(Number);
            this.checked = ids.every(function(rid) { return selectedRouteIds.has(rid); });
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
        // Airport fields: show all groups even with 1 route (each origin/dest is meaningful)
        var isAirportField = (fieldName === 'origin_airports' || fieldName === 'dest_airports');

        routes.forEach(function(r) {
            var values = csvSplit(r[fieldName]);
            if (!values.length) {
                if (!buckets['Other']) buckets['Other'] = new Set();
                buckets['Other'].add(r.route_id);
            } else {
                // Place route in ALL matching buckets (multi-origin/dest support)
                values.forEach(function(val) {
                    if (!buckets[val]) buckets[val] = new Set();
                    buckets[val].add(r.route_id);
                });
            }
        });

        routeGroups = [];
        var colorIdx = 0;
        var keys = Object.keys(buckets).sort();
        keys.forEach(function(key) {
            if (!isAirportField && buckets[key].size < 2) return;
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
            if (!artccs.length) {
                if (!buckets['OTHER']) buckets['OTHER'] = new Set();
                buckets['OTHER'].add(r.route_id);
            } else {
                // Place route in ALL matching region buckets (multi-origin/dest support)
                var seenRegions = {};
                artccs.forEach(function(artcc) {
                    var region = FH.ARTCC_TO_REGION[artcc] || 'OTHER';
                    if (!seenRegions[region]) {
                        seenRegions[region] = true;
                        if (!buckets[region]) buckets[region] = new Set();
                        buckets[region].add(r.route_id);
                    }
                });
            }
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

        // 1. Parse each route into ordered fix/airway tokens
        var routeTokens = routes.map(function(r) {
            return (r.route_string || '').toUpperCase().split(/\s+/).filter(function(tok) {
                return tok.length >= 2 && tok.length <= 6 &&
                       /^[A-Z][A-Z0-9]+$/.test(tok) && tok !== 'DCT';
            });
        });

        // 2. Build n-gram (consecutive token sequence) → route index set
        //    These represent actual route segments where routes converge
        var segRoutes = {};
        var MAX_SEG = 8;
        routeTokens.forEach(function(tokens, ri) {
            for (var len = 2; len <= Math.min(MAX_SEG, tokens.length); len++) {
                for (var s = 0; s <= tokens.length - len; s++) {
                    var key = tokens.slice(s, s + len).join(' ');
                    if (!segRoutes[key]) segRoutes[key] = new Set();
                    segRoutes[key].add(ri);
                }
            }
        });

        // 3. Keep only segments shared by >=2 routes but not universal
        var shared = [];
        Object.keys(segRoutes).forEach(function(key) {
            var sz = segRoutes[key].size;
            if (sz >= 2 && sz < routes.length) {
                shared.push({ seg: key, routes: segRoutes[key], len: key.split(' ').length });
            }
        });

        if (!shared.length) {
            autoGroupByField('origin_artccs', '');
            return;
        }

        // 4. Sort longest first, then by route count desc — longest segments
        //    represent the most specific convergence points
        shared.sort(function(a, b) {
            return b.len !== a.len ? b.len - a.len : b.routes.size - a.routes.size;
        });

        // 5. Deduplicate: remove sub-segments whose route set is identical
        //    to a longer containing segment (the longer one is more descriptive)
        var kept = [];
        shared.forEach(function(cand) {
            var dominated = kept.some(function(k) {
                if (k.len <= cand.len) return false;
                if (k.seg.indexOf(cand.seg) === -1) return false;
                if (cand.routes.size !== k.routes.size) return false;
                var same = true;
                cand.routes.forEach(function(r) { if (!k.routes.has(r)) same = false; });
                return same;
            });
            if (!dominated) kept.push(cand);
        });

        // 6. Re-sort by discriminating power (length * route count)
        kept.sort(function(a, b) {
            return (b.len * b.routes.size) - (a.len * a.routes.size);
        });

        // 7. Greedy assignment: best segment first, each route assigned once
        var assigned = new Set();
        routeGroups = [];
        var colorIdx = 0;

        kept.forEach(function(seg) {
            var members = new Set();
            seg.routes.forEach(function(ri) {
                if (!assigned.has(ri)) {
                    members.add(routes[ri].route_id);
                    assigned.add(ri);
                }
            });
            if (members.size >= 2) {
                // Format display: show full segment up to 4 tokens,
                // abbreviate longer ones as "first second...last"
                var segTokens = seg.seg.split(' ');
                var display;
                if (segTokens.length <= 4) {
                    display = seg.seg;
                } else {
                    display = segTokens.slice(0, 2).join(' ') + '\u2026' +
                              segTokens.slice(-2).join(' ');
                }
                routeGroups.push({
                    group_name: t('playbook.groups.viaPrefix') + ' ' + display,
                    group_color: GROUP_COLORS[colorIdx % GROUP_COLORS.length],
                    route_ids: members,
                    sort_order: colorIdx,
                    _autoField: 'common_segment'
                });
                colorIdx++;
            }
        });

        // 8. Remaining unassigned routes
        var remaining = new Set();
        routes.forEach(function(r, idx) {
            if (!assigned.has(idx)) remaining.add(r.route_id);
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

    function autoGroupByCommonFix() {
        if (!activePlayData || !activePlayData.routes) return;
        var routes = activePlayData.routes;

        // 1. Parse each route into fix/airway tokens
        var routeTokens = routes.map(function(r) {
            return (r.route_string || '').toUpperCase().split(/\s+/).filter(function(tok) {
                return tok.length >= 2 && tok.length <= 6 &&
                       /^[A-Z][A-Z0-9]+$/.test(tok) && tok !== 'DCT';
            });
        });

        // 2. Build single fix → route index set
        var fixRoutes = {};
        routeTokens.forEach(function(tokens, ri) {
            var seen = {};
            tokens.forEach(function(tok) {
                if (!seen[tok]) {
                    seen[tok] = true;
                    if (!fixRoutes[tok]) fixRoutes[tok] = new Set();
                    fixRoutes[tok].add(ri);
                }
            });
        });

        // 3. Keep only fixes shared by >=2 routes but not universal
        var shared = [];
        Object.keys(fixRoutes).forEach(function(fix) {
            var sz = fixRoutes[fix].size;
            if (sz >= 2 && sz < routes.length) {
                shared.push({ fix: fix, routes: fixRoutes[fix] });
            }
        });

        if (!shared.length) {
            autoGroupByField('origin_artccs', '');
            return;
        }

        // 4. Sort by discriminating power: route count desc
        shared.sort(function(a, b) {
            return b.routes.size - a.routes.size;
        });

        // 5. Greedy assignment: best fix first, each route assigned once
        var assigned = new Set();
        routeGroups = [];
        var colorIdx = 0;

        shared.forEach(function(item) {
            var members = new Set();
            item.routes.forEach(function(ri) {
                if (!assigned.has(ri)) {
                    members.add(routes[ri].route_id);
                    assigned.add(ri);
                }
            });
            if (members.size >= 2) {
                routeGroups.push({
                    group_name: t('playbook.groups.viaPrefix') + ' ' + item.fix,
                    group_color: GROUP_COLORS[colorIdx % GROUP_COLORS.length],
                    route_ids: members,
                    sort_order: colorIdx,
                    _autoField: 'common_fix'
                });
                colorIdx++;
            }
        });

        // 6. Remaining unassigned routes
        var remaining = new Set();
        routes.forEach(function(r, idx) {
            if (!assigned.has(idx)) remaining.add(r.route_id);
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
        html += '<div class="pb-cb-item pb-auto-group-opt" data-field="common_fix">' + t('playbook.groups.byCommonFix') + '</div>';
        html += '</div></div>';

        // Route Tools dropdown
        html += '<div class="pb-cb-dropdown" id="pb_route_tools_dd">';
        html += '<button type="button" class="btn btn-xs btn-outline-warning pb-cb-trigger"><i class="fas fa-tools mr-1"></i>' + t('playbook.routeTools') + ' <i class="fas fa-caret-down ml-1"></i></button>';
        html += '<div class="pb-cb-menu" style="min-width:180px;">';
        html += '<div class="pb-cb-item pb-route-tool-opt" data-tool="consolidate"><i class="fas fa-compress-arrows-alt mr-1" style="width:14px;"></i>' + t('playbook.consolidateRoutes') + '</div>';
        html += '<div class="pb-cb-item pb-route-tool-opt" data-tool="compact"><i class="fas fa-columns mr-1" style="width:14px;"></i>' + t('playbook.compactView') + '</div>';
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

        // If throughput mode is active, delegate to throughput renderer
        if (throughputEnabled && throughputData) {
            applyThroughputToMap();
            return;
        }

        // Live-resolution path (calls PostGIS via route-maplibre.js)
        // Always use live resolution for proper symbology (fix labels, solid/dashed
        // segments, fan detection, per-segment coloring). Stored route_geometry is
        // retained in the database for archival/historical reference but not used
        // for rendering — PostGIS calls are fast enough (~70ms/route) and the
        // symbology pipeline provides significantly better UX.
        var hasSearch = currentSearchClauses.length > 0;
        var hasGroups = routeGroups.length > 0;
        var text;
        var lineRouteMap = []; // Parallel array: line index -> route object (for filter injection)

        if (hasSearch) {
            // Search active: plot non-matching first, matching on top for visibility
            var nonMatching = [], matching = [];
            var nonMatchingIds = [], matchingIds = [];
            var nonMatchingRoutes = [], matchingRoutes = [];
            selected.forEach(function(r) {
                var parts = [];
                if (r.origin) parts.push(r.origin);
                parts.push(r.route_string);
                if (r.dest) parts.push(r.dest);
                var routeStr = parts.join(' ');
                if (routeMatchesSearchClauses(r, currentSearchClauses)) {
                    matching.push(routeStr + ';#C70039');
                    matchingIds.push(r.route_id);
                    matchingRoutes.push(r);
                } else {
                    nonMatching.push(routeStr + ';#555555');
                    nonMatchingIds.push(r.route_id);
                    nonMatchingRoutes.push(r);
                }
            });
            lastPlottedRouteOrder = nonMatchingIds.concat(matchingIds);
            lineRouteMap = nonMatchingRoutes.concat(matchingRoutes);
            text = nonMatching.concat(matching).join('\n');
        } else if (hasGroups) {
            // Groups active: use per-route group colors (skip PB directive path)
            lastPlottedRouteOrder = selected.map(function(r) { return r.route_id; });
            lineRouteMap = selected.slice();
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
            // No search, no groups: assemble routes with origin/dest directly.
            // Always use DB route data (which includes origin/dest FIR codes)
            // rather than PB directive expansion (CSV data lacks origin/dest).
            lastPlottedRouteOrder = selected.map(function(r) { return r.route_id; });
            lineRouteMap = selected.slice();
            text = selected.map(function(r) {
                var parts = [];
                if (r.origin) parts.push(r.origin);
                parts.push(r.route_string);
                if (r.dest) parts.push(r.dest);
                return parts.join(' ');
            }).join('\n');
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

        // Inject filter groups from DB origin_filter/dest_filter fields.
        // Must happen AFTER mandatory wrapping to avoid >(-KDFW) corruption.
        if (lineRouteMap.length > 0) {
            text = text.split('\n').map(function(line, idx) {
                var r = lineRouteMap[idx];
                if (!r) return line;
                var origGroup = buildFilterGroup(r.origin_filter);
                var destGroup = buildFilterGroup(r.dest_filter);
                if (!origGroup && !destGroup) return line;
                // Separate color suffix
                var colorSuffix = '';
                var semiIdx = line.indexOf(';');
                if (semiIdx !== -1) { colorSuffix = line.slice(semiIdx); line = line.slice(0, semiIdx); }
                var tokens = line.trim().split(/\s+/);
                // Insert origin filter after first token (origin facility)
                if (origGroup && tokens.length > 1) tokens.splice(1, 0, origGroup);
                // Append dest filter before last token (dest facility)
                if (destGroup && tokens.length > 1) tokens.splice(tokens.length - 1, 0, destGroup);
                return tokens.join(' ') + colorSuffix;
            }).join('\n');
        }

        // Check for NAT track tokens and expand before plotting
        var hasNat = text.split('\n').some(function(line) {
            return findNatTokens(line).length > 0;
        });

        if (hasNat) {
            var lines = text.split('\n');
            var expandPromises = lines.map(function(line) {
                // Separate color suffix before expanding
                var colorSuffix = '';
                var lineForExpand = line;
                var semiIdx = line.indexOf(';');
                if (semiIdx !== -1 && line.indexOf('>') === -1) {
                    // Avoid splitting mandatory markers
                    colorSuffix = line.slice(semiIdx);
                    lineForExpand = line.slice(0, semiIdx);
                }
                if (findNatTokens(lineForExpand).length > 0) {
                    return expandNatTracks(lineForExpand).then(function(expanded) {
                        return expanded + colorSuffix;
                    });
                }
                return $.Deferred().resolve(line).promise();
            });
            $.when.apply($, expandPromises).then(function() {
                var expandedLines = expandPromises.length === 1
                    ? [arguments[0]]
                    : Array.prototype.slice.call(arguments);
                var expandedText = expandedLines.join('\n');
                var textarea = document.getElementById('routeSearch');
                var plotBtn = document.getElementById('plot_r');
                if (textarea && plotBtn) {
                    textarea.value = expandedText;
                    plotBtn.click();
                }
            });
        } else {
            var textarea = document.getElementById('routeSearch');
            var plotBtn = document.getElementById('plot_r');
            if (textarea && plotBtn) {
                textarea.value = text;
                plotBtn.click();
            }
        }
    }

    function openInRoutePage() {
        if (!activePlayData) return;
        var allRoutes = activePlayData.routes || [];
        var selected = getSelectedRoutes();
        if (!selected.length) return;

        var hasGroups = routeGroups.length > 0;
        var text;

        if (hasGroups) {
            // Groups active: forward per-route colors to route.php
            text = selected.map(function(r) {
                var parts = [];
                if (r.origin) parts.push(r.origin);
                var origGroup = buildFilterGroup(r.origin_filter);
                if (origGroup) parts.push(origGroup);
                parts.push(r.route_string);
                var destGroup = buildFilterGroup(r.dest_filter);
                if (destGroup) parts.push(destGroup);
                if (r.dest) parts.push(r.dest);
                var routeStr = parts.join(' ');
                var color = getRouteGroupColor(r.route_id);
                if (color) routeStr += ';' + color;
                return routeStr;
            }).join('\n');
        } else {
            // Always assemble with origin/dest from DB data (PB directive loses FIR codes)
            text = selected.map(function(r) {
                var parts = [];
                if (r.origin) parts.push(r.origin);
                var origGroup = buildFilterGroup(r.origin_filter);
                if (origGroup) parts.push(origGroup);
                parts.push(r.route_string);
                var destGroup = buildFilterGroup(r.dest_filter);
                if (destGroup) parts.push(destGroup);
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
        // Client-side preview only: collect origin/dest ARTCCs.
        // The server recomputes the authoritative facilities_involved from
        // per-route PostGIS spatial intersection results after save.
        var allArtccs = new Set();
        routes.forEach(function(r) {
            csvSplit(r.origin_artccs).forEach(function(a) { allArtccs.add(a); });
            csvSplit(r.dest_artccs).forEach(function(a) { allArtccs.add(a); });
        });

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
        $('#pb_edit_description').val('');
        $('#pb_edit_remarks').val('');
        $('#pb_edit_status').val('active');
        $('#pb_edit_source').val('DCC').prop('disabled', false);
        setVisibilityDropdown('public', false);
        $('#pb_route_edit_body').empty();
        $('#pb_bulk_paste_area').hide();
        $('#pb_advisory_parse_area').hide();
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
        $('#pb_edit_description').val(play.description || '');
        $('#pb_edit_remarks').val(play.remarks || '');
        $('#pb_edit_status').val(play.status || 'active');
        $('#pb_edit_source').val(play.source || 'DCC').prop('disabled', true);

        var isFAA = play.source === 'FAA' || play.source === 'FAA_HISTORICAL';
        setVisibilityDropdown(play.visibility || 'public', isFAA);
        if (!isFAA && (play.visibility === 'private_users' || play.visibility === 'private_org')) {
            loadAclList(play.play_id);
        }

        var tbody = $('#pb_route_edit_body');
        tbody.empty();
        (routes || []).forEach(function(r) {
            addEditRouteRow(r);
        });

        $('#pb_bulk_paste_area').hide();
        $('#pb_advisory_parse_area').hide();
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
        $('#pb_edit_description').val(play.description || '');
        $('#pb_edit_remarks').val(play.remarks || '');
        $('#pb_edit_status').val('draft');
        $('#pb_edit_source').val('DCC').prop('disabled', false);
        setVisibilityDropdown('public', false);

        var tbody = $('#pb_route_edit_body');
        tbody.empty();
        (routes || []).forEach(function(r) {
            addEditRouteRow(r);
        });

        $('#pb_bulk_paste_area').hide();
        $('#pb_advisory_parse_area').hide();
        $('#pb_play_modal').modal('show');
    }

    function addEditRouteRow(r) {
        var route = r || {};
        var hasRemarks = !!(route.remarks && route.remarks.trim());
        var html = '<tr>';
        html += '<td class="pb-re-cell"><input type="text" class="form-control form-control-sm pb-re-origin pb-re-apt" value="' + escHtml(route.origin || '') + '" placeholder="KABC"></td>';
        html += '<td class="pb-re-cell"><input type="text" class="form-control form-control-sm pb-re-origin-filter" value="' + escHtml(route.origin_filter || '') + '" placeholder="-APT"></td>';
        html += '<td class="pb-re-cell"><input type="text" class="form-control form-control-sm pb-re-dest pb-re-apt" value="' + escHtml(route.dest || '') + '" placeholder="KXYZ"></td>';
        html += '<td class="pb-re-cell"><input type="text" class="form-control form-control-sm pb-re-dest-filter" value="' + escHtml(route.dest_filter || '') + '" placeholder="-APT"></td>';
        html += '<td class="pb-re-cell"><textarea class="form-control form-control-sm pb-re-route" rows="1" placeholder="DCT FIX1 J123 FIX2 DCT">' + escHtml(route.route_string || '') + '</textarea></td>';
        html += '<td class="pb-re-cell" style="text-align:center;"><input type="hidden" class="pb-re-remarks" value="' + escHtml(route.remarks || '') + '"><button type="button" class="pb-re-remarks-btn' + (hasRemarks ? ' has-remarks' : '') + '" title="' + escHtml(route.remarks || t('playbook.remarks')) + '"><i class="fas fa-sticky-note"></i></button></td>';
        html += '<td class="pb-re-cell" style="text-align:center;"><button class="btn btn-sm btn-outline-danger pb-re-delete" title="' + t('playbook.deleteRoute') + '"><i class="fas fa-times"></i></button></td>';
        html += '</tr>';
        var $tr = $(html);
        $('#pb_route_edit_body').append($tr);

        // Auto-resize route textarea to fit content
        var $ta = $tr.find('.pb-re-route');
        autoResizeTextarea($ta[0]);
        $ta.on('input', function() { autoResizeTextarea(this); });

    }

    function autoResizeTextarea(el) {
        if (!el) return;
        el.style.height = 'auto';
        el.style.height = el.scrollHeight + 'px';
    }

    // Remarks popover — toggle on button click
    $(document).on('click', '.pb-re-remarks-btn', function(e) {
        e.stopPropagation();
        var $btn = $(this);
        var $td = $btn.closest('td');
        var $hidden = $td.find('.pb-re-remarks');

        // Close any existing popover
        $('.pb-re-remarks-popover').remove();

        var $pop = $('<div class="pb-re-remarks-popover"></div>');
        var $ta = $('<textarea rows="3" placeholder="' + t('playbook.remarks') + '..."></textarea>');
        $ta.val($hidden.val());
        $pop.append($ta);
        $td.css('position', 'relative').append($pop);
        $ta.focus();

        // Save on blur
        $ta.on('blur', function() {
            var val = $ta.val().trim();
            $hidden.val(val);
            $btn.toggleClass('has-remarks', !!val);
            $btn.attr('title', val || t('playbook.remarks'));
            $pop.remove();
        });

        // Close on Escape
        $ta.on('keydown', function(ev) {
            if (ev.key === 'Escape') { $ta.blur(); }
        });
    });

    // Close remarks popover when clicking elsewhere
    $(document).on('click', function() { $('.pb-re-remarks-popover').remove(); });
    $(document).on('click', '.pb-re-remarks-popover', function(e) { e.stopPropagation(); });

    // Auto-resize all route textareas when modal becomes visible
    $('#pb_play_modal').on('shown.bs.modal', function() {
        $('#pb_route_edit_body .pb-re-route').each(function() { autoResizeTextarea(this); });
    });

    /**
     * Detect and extract a FIR: pattern from the start of a route line.
     * Returns { origin: 'FIR:EB..,ED..', artccs: ['EBBU','EDGG',...], rest: 'remaining line' }
     * or null if no FIR pattern found.
     */
    /**
     * Expand a FIR: pattern string (e.g. "FIR:LS..,LIC.,LIR.") into individual ARTCC codes.
     * Returns array of codes like ['LSAG','LSAZ','LICC','LIRR',...] or empty array if unavailable.
     */
    function expandFirPattern(firStr) {
        var codes = [];
        if (typeof FacilityHierarchy !== 'undefined' && FacilityHierarchy.expandFirPattern) {
            var raw = firStr.replace(/^FIR:/i, '');
            raw.split(',').forEach(function(pat) {
                pat = pat.trim();
                if (!pat) return;
                codes = codes.concat(FacilityHierarchy.expandFirPattern('FIR:' + pat));
            });
            codes = unique(codes);
        }
        return codes;
    }

    /**
     * Extract FIR: patterns from start (origin) and/or end (dest) of a route line.
     * Returns { origins: ['KZOA','KZOB'], dests: ['EBBU','EDGG'], rest: 'route tokens' }
     * or null if no FIR patterns found.
     */
    function extractFirPatterns(line) {
        var origins = [], dests = [], rest = line;

        // Check for FIR: at the start
        var startMatch = line.match(/^(FIR:[A-Z0-9]{1,4}\.+(?:,[A-Z0-9]{1,4}\.+)*)\s+/i);
        if (startMatch) {
            origins = expandFirPattern(startMatch[1]);
            rest = line.substring(startMatch[0].length);
        }

        // Check for FIR: at the end of remaining text
        var endMatch = rest.match(/\s+(FIR:[A-Z0-9]{1,4}\.+(?:,[A-Z0-9]{1,4}\.+)*)$/i);
        if (endMatch) {
            dests = expandFirPattern(endMatch[1]);
            rest = rest.substring(0, rest.length - endMatch[0].length);
        }

        if (!origins.length && !dests.length) return null;
        return { origins: origins, dests: dests, rest: rest };
    }

    /**
     * Split tokens into leading ICAO airports, route body, and trailing ICAO airports.
     * ICAO airports are exactly 4 uppercase letters (LIEA, EGGW, etc.).
     * Waypoints (5+ letters), airways (letters+digits), and DCT are route tokens.
     */
    function splitOriginRouteDest(tokens) {
        // Walk from left: consecutive 4-letter alpha codes = origins
        var oi = 0;
        while (oi < tokens.length && /^[A-Z]{4}$/.test(tokens[oi])) oi++;
        // Walk from right: consecutive 4-letter alpha codes = destinations
        var di = tokens.length;
        while (di > oi && /^[A-Z]{4}$/.test(tokens[di - 1])) di--;
        return {
            origins: tokens.slice(0, oi),
            route: tokens.slice(oi, di),
            dests: tokens.slice(di)
        };
    }

    function applyBulkPaste() {
        var text = $('#pb_bulk_paste_text').val().trim();
        if (!text) return;

        var lines = text.split('\n').filter(function(l) { return l.trim(); });
        lines.forEach(function(line) {
            var trimmed = line.trim();

            // Extract FIR: patterns from start (origins) and/or end (dests) — expand to ARTCC codes
            var fir = extractFirPatterns(trimmed);
            var firOrigins = [], firDests = [];
            if (fir) {
                firOrigins = fir.origins;
                firDests = fir.dests;
                trimmed = fir.rest;
            }

            // Strip >< route markers (mandatory/non-mandatory segment annotations)
            var cleaned = trimmed
                .replace(/[><]/g, '')
                .replace(/\s+/g, ' ')
                .trim();
            if (!cleaned && !firOrigins.length) return;

            // UNKN is a valid origin/dest placeholder — let it pass through to token parsing

            // Split tokens into leading airports (origins), route body, trailing airports (dests)
            var tokens = cleaned ? cleaned.split(/\s+/) : [];
            var split = splitOriginRouteDest(tokens);

            // Merge FIR-expanded codes with token-detected airports
            var allOrigins = firOrigins.concat(split.origins);
            var allDests = firDests.concat(split.dests);

            var routeData = {
                origin: allOrigins.join(' '),
                route_string: split.route.join(' '),
                dest: allDests.join(' ')
            };

            // If no route body found (all tokens were airports), treat as plain route
            if (!routeData.route_string && cleaned) {
                routeData.route_string = cleaned;
                routeData.origin = firOrigins.join(' ');
                routeData.dest = firDests.join(' ');
            }

            // Classify origin facilities (FIR-expanded codes are ARTCC codes, classifyOriginDest handles them)
            if (allOrigins.length) {
                var oTracons = [], oArtccs = [];
                allOrigins.forEach(function(c) {
                    var cls = classifyOriginDest(c);
                    oTracons = oTracons.concat(cls.tracons);
                    oArtccs = oArtccs.concat(cls.artccs);
                });
                routeData.origin_tracons = unique(oTracons).join(',');
                routeData.origin_artccs = unique(oArtccs).join(',');
            }

            // Classify destination facilities
            if (allDests.length) {
                var dTracons = [], dArtccs = [];
                allDests.forEach(function(c) {
                    var cls = classifyOriginDest(c);
                    dTracons = dTracons.concat(cls.tracons);
                    dArtccs = dArtccs.concat(cls.artccs);
                });
                routeData.dest_tracons = unique(dTracons).join(',');
                routeData.dest_artccs = unique(dArtccs).join(',');
            }

            addEditRouteRow(routeData);
        });

        $('#pb_bulk_paste_text').val('');
        $('#pb_bulk_paste_area').slideUp(150);
    }

    // ── Route Advisory Parser (delegates to shared RouteAdvisoryParser) ────

    function applyAdvisoryParse() {
        var text = $('#pb_advisory_parse_text').val().trim();
        if (!text) return;

        // Detect NATOTS format
        if (/NATOTS|NORTH ATLANTIC DEPARTURES|TODAYS TRACKS/i.test(text)) {
            PERTIDialog.toast('playbook.advisoryNatotsUnsupported', 'warning');
            return;
        }

        var routes = RouteAdvisoryParser.parse(text);
        if (!routes.length) {
            PERTIDialog.toast('playbook.advisoryNoRoutes', 'warning');
            return;
        }

        // Add parsed routes as edit rows
        routes.forEach(function(r) { addEditRouteRow(r); });

        var msg = t('playbook.advisoryParsed', { count: routes.length });
        PERTIDialog.toast(msg, 'success');
        $('#pb_advisory_parse_text').val('');
        $('#pb_advisory_parse_area').slideUp(150);
    }

    // ── End Route Advisory Parser ──────────────────────────────────

    // ── Auto-Filter Detection ──
    function autoDetectEditFilters() {
        var FH = (typeof FacilityHierarchy !== 'undefined') ? FacilityHierarchy : null;
        if (!FH || !FH.isArtcc || !FH.getChildren) {
            PERTIDialog.toast(t('playbook.autoFiltersNone'), 'info');
            return;
        }

        // Collect routes from the edit form
        var routes = [];
        $('#pb_route_edit_body tr').each(function() {
            var $tr = $(this);
            routes.push({
                $tr: $tr,
                origin: ($tr.find('.pb-re-origin').val() || '').toUpperCase().trim(),
                dest: ($tr.find('.pb-re-dest').val() || '').toUpperCase().trim(),
                originFilter: ($tr.find('.pb-re-origin-filter').val() || '').toUpperCase().trim(),
                destFilter: ($tr.find('.pb-re-dest-filter').val() || '').toUpperCase().trim(),
                route: ($tr.find('.pb-re-route').val() || '').toUpperCase().trim()
            });
        });
        if (routes.length < 2) {
            PERTIDialog.toast(t('playbook.autoFiltersNone'), 'info');
            return;
        }

        // Build lookup: dest -> [{ orig, routeIdx, routeStr }]
        var destToOrigins = {};
        var origToDests = {};
        routes.forEach(function(r, idx) {
            var origins = r.origin.split(/[\s\/,]+/).filter(Boolean);
            var dests = r.dest.split(/[\s\/,]+/).filter(Boolean);
            var routeNorm = r.route;
            origins.forEach(function(orig) {
                dests.forEach(function(dest) {
                    if (!destToOrigins[dest]) destToOrigins[dest] = [];
                    destToOrigins[dest].push({ orig: orig, routeIdx: idx, routeStr: routeNorm });
                    if (!origToDests[orig]) origToDests[orig] = [];
                    origToDests[orig].push({ dest: dest, routeIdx: idx, routeStr: routeNorm });
                });
            });
        });

        var proposals = []; // { idx, side, filters }

        routes.forEach(function(r, idx) {
            var origins = r.origin.split(/[\s\/,]+/).filter(Boolean);
            var dests = r.dest.split(/[\s\/,]+/).filter(Boolean);

            // Check origin side
            origins.forEach(function(orig) {
                if (!FH.isArtcc(orig)) return;
                var children = FH.getChildren(orig);
                dests.forEach(function(dest) {
                    var artccEntries = (destToOrigins[dest] || []).filter(function(e) { return e.orig === orig && e.routeIdx === idx; });
                    if (!artccEntries.length) return;
                    var toExclude = [];
                    (destToOrigins[dest] || []).forEach(function(entry) {
                        if (entry.routeIdx === idx) return;
                        var parentArtcc = FH.AIRPORT_TO_ARTCC ? FH.AIRPORT_TO_ARTCC[entry.orig] : null;
                        if ((parentArtcc === orig || children.indexOf(entry.orig) !== -1) && entry.routeStr !== artccEntries[0].routeStr) {
                            toExclude.push('-' + entry.orig);
                        }
                    });
                    if (toExclude.length) {
                        proposals.push({ idx: idx, side: 'origin', filters: toExclude });
                    }
                });
            });

            // Check dest side
            dests.forEach(function(dest) {
                if (!FH.isArtcc(dest)) return;
                var children = FH.getChildren(dest);
                origins.forEach(function(orig) {
                    var artccEntries = (origToDests[orig] || []).filter(function(e) { return e.dest === dest && e.routeIdx === idx; });
                    if (!artccEntries.length) return;
                    var toExclude = [];
                    (origToDests[orig] || []).forEach(function(entry) {
                        if (entry.routeIdx === idx) return;
                        var parentArtcc = FH.AIRPORT_TO_ARTCC ? FH.AIRPORT_TO_ARTCC[entry.dest] : null;
                        if ((parentArtcc === dest || children.indexOf(entry.dest) !== -1) && entry.routeStr !== artccEntries[0].routeStr) {
                            toExclude.push('-' + entry.dest);
                        }
                    });
                    if (toExclude.length) {
                        proposals.push({ idx: idx, side: 'dest', filters: toExclude });
                    }
                });
            });
        });

        if (!proposals.length) {
            PERTIDialog.toast(t('playbook.autoFiltersNone'), 'info');
            return;
        }

        // Build summary
        var summaryLines = [];
        proposals.forEach(function(p) {
            var r = routes[p.idx];
            var label = p.side === 'origin' ? (r.origin || '?') : (r.dest || '?');
            summaryLines.push('<li>' + escHtml(label) + ' (' + p.side + '): <code>' + escHtml(p.filters.join(' ')) + '</code></li>');
        });

        Swal.fire({
            icon: 'question',
            title: t('playbook.autoFiltersConfirm', { count: proposals.length }),
            html: '<p>' + t('playbook.autoFiltersDesc') + '</p><ul style="text-align:left;font-size:0.85rem;">' + summaryLines.join('') + '</ul>',
            confirmButtonText: t('common.apply'),
            showCancelButton: true,
            cancelButtonText: t('playbook.groups.cancel')
        }).then(function(result) {
            if (!result.isConfirmed) return;

            proposals.forEach(function(p) {
                var $tr = routes[p.idx].$tr;
                var selector = p.side === 'origin' ? '.pb-re-origin-filter' : '.pb-re-dest-filter';
                var $input = $tr.find(selector);
                var existing = ($input.val() || '').toUpperCase().split(/\s+/).filter(Boolean);
                var merged = existing.slice();
                p.filters.forEach(function(f) { if (merged.indexOf(f) === -1) merged.push(f); });
                $input.val(merged.sort().join(' '));
            });

            PERTIDialog.toast(t('playbook.autoFiltersApplied', { count: proposals.length }), 'success');
        });
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
                dest_artccs: unique(csvSplit(computed ? computed.dest_artccs : '').concat(destClass.artccs)).join(','),
                remarks: $tr.find('.pb-re-remarks').val().trim()
            });
        });

        var playFields = await autoComputePlayFields(routes);

        // Source: disabled selects don't return .val(), so re-enable briefly
        var $srcSel = $('#pb_edit_source');
        var srcDisabled = $srcSel.prop('disabled');
        if (srcDisabled) $srcSel.prop('disabled', false);
        var sourceVal = $srcSel.val() || 'DCC';
        if (srcDisabled) $srcSel.prop('disabled', true);

        var visVal = $('#pb_edit_visibility').val() || 'public';

        var body = {
            play_id: playId,
            play_name: playName,
            display_name: $('#pb_edit_display_name').val().trim(),
            description: $('#pb_edit_description').val().trim(),
            category: ($('#pb_edit_category').val() || '').replace('__custom__', '').trim(),
            scenario_type: $('#pb_edit_scenario_type').val(),
            status: $('#pb_edit_status').val(),
            visibility: visVal,
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
    // VISIBILITY & ACL
    // =========================================================================

    var VISIBILITY_DESCS = {
        'public': 'playbook.visibility.publicDesc',
        'local': 'playbook.visibility.localDesc',
        'private_users': 'playbook.visibility.privateUsersDesc',
        'private_org': 'playbook.visibility.privateOrgDesc'
    };

    function setVisibilityDropdown(value, disabled) {
        var $sel = $('#pb_edit_visibility');
        $sel.val(value || 'public').prop('disabled', !!disabled);
        updateVisibilityUI(value || 'public');
    }

    function updateVisibilityUI(vis) {
        var descKey = VISIBILITY_DESCS[vis] || '';
        $('#pb_visibility_desc').text(descKey ? t(descKey) : '');
        var showAcl = (vis === 'private_users' || vis === 'private_org');
        if (showAcl) {
            $('#pb_acl_section').slideDown(150);
        } else {
            $('#pb_acl_section').slideUp(150);
        }
        // Show org picker only for private_org
        if (vis === 'private_org') {
            $('#pb_acl_org_section').slideDown(150);
            loadOrgPicker();
        } else {
            $('#pb_acl_org_section').slideUp(150);
        }
    }

    function loadAclList(playId) {
        var $list = $('#pb_acl_list');
        $list.html('<div class="pb-loading py-1"><div class="spinner-border spinner-border-sm text-primary"></div></div>');

        $.getJSON(API_ACL + '?play_id=' + playId, function(data) {
            if (!data || !data.success) {
                $list.html('<div class="small text-muted">' + t('playbook.acl.loadFailed') + '</div>');
                return;
            }
            renderAclList(data.acl || [], playId);
        }).fail(function() {
            $list.html('<div class="small text-muted">' + t('playbook.acl.loadFailed') + '</div>');
        });
    }

    function renderAclList(aclEntries, playId) {
        var $list = $('#pb_acl_list');
        if (!aclEntries.length) {
            $list.html('<div class="small text-muted py-1">' + t('playbook.acl.ownerNote') + '</div>');
            return;
        }
        var html = '<table class="pb-acl-table"><thead><tr>';
        html += '<th>CID</th>';
        html += '<th>' + t('playbook.acl.userName') + '</th>';
        html += '<th>' + t('playbook.acl.canView') + '</th>';
        html += '<th>' + t('playbook.acl.canManage') + '</th>';
        html += '<th>' + t('playbook.acl.canManageAcl') + '</th>';
        html += '<th></th>';
        html += '</tr></thead><tbody>';
        aclEntries.forEach(function(entry) {
            var nameStr = entry.name || '';
            var orgStr = (entry.orgs || []).join(', ');
            html += '<tr data-acl-cid="' + entry.cid + '">';
            html += '<td>' + escHtml(String(entry.cid)) + '</td>';
            html += '<td>' + escHtml(nameStr) + (orgStr ? ' <span class="text-muted" style="font-size:0.6rem;">(' + escHtml(orgStr) + ')</span>' : '') + '</td>';
            html += '<td><input type="checkbox" class="pb-acl-perm" data-perm="can_view"' + (entry.can_view ? ' checked' : '') + '></td>';
            html += '<td><input type="checkbox" class="pb-acl-perm" data-perm="can_manage"' + (entry.can_manage ? ' checked' : '') + '></td>';
            html += '<td><input type="checkbox" class="pb-acl-perm" data-perm="can_manage_acl"' + (entry.can_manage_acl ? ' checked' : '') + '></td>';
            html += '<td><button class="btn btn-xs btn-outline-danger pb-acl-remove" title="' + t('playbook.acl.removeUser') + '"><i class="fas fa-times"></i></button></td>';
            html += '</tr>';
        });
        html += '</tbody></table>';
        $list.html(html);
    }

    function aclAction(action, data) {
        var playId = parseInt($('#pb_edit_play_id').val()) || 0;
        if (!playId) return;

        data.play_id = playId;
        data.action = action;

        $.ajax({
            url: API_ACL_MGT,
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(data),
            dataType: 'json',
            success: function(resp) {
                if (resp && resp.success) {
                    loadAclList(playId);
                    var msg = action === 'add' ? t('playbook.acl.userAdded') :
                              action === 'remove' ? t('playbook.acl.userRemoved') :
                              t('playbook.acl.userUpdated');
                    PERTIDialog.toast(msg, 'success');
                } else {
                    PERTIDialog.toast(resp.error || t('playbook.acl.saveFailed'), 'error');
                }
            },
            error: function() {
                PERTIDialog.toast(t('playbook.acl.saveFailed'), 'error');
            }
        });
    }

    // =========================================================================
    // ACL USER SEARCH + ORG SHARING
    // =========================================================================

    /**
     * Perform debounced user search and show dropdown results.
     */
    function aclUserSearch(query) {
        if (aclSearchTimer) clearTimeout(aclSearchTimer);
        var $results = $('#pb_acl_search_results');

        if (query.length < 2) {
            $results.hide();
            return;
        }

        aclSearchTimer = setTimeout(function() {
            $.getJSON(API_USER_SEARCH + '?q=' + encodeURIComponent(query), function(data) {
                if (!data || !data.success || !data.users) {
                    $results.hide();
                    return;
                }
                renderSearchResults(data.users);
            });
        }, 250);
    }

    function renderSearchResults(users) {
        var $results = $('#pb_acl_search_results');
        if (!users.length) {
            $results.html('<div class="pb-acl-search-no-results">' + t('playbook.acl.noSearchResults') + '</div>').show();
            return;
        }

        // Get current ACL CIDs to mark already-added users
        var existingCids = new Set();
        $('#pb_acl_list .pb-acl-table [data-acl-cid]').each(function() {
            existingCids.add(parseInt($(this).data('acl-cid')));
        });

        var html = '';
        users.forEach(function(u) {
            var alreadyAdded = existingCids.has(u.cid);
            var orgNames = (u.orgs || []).map(function(o) { return o.display_name; }).join(', ');
            html += '<div class="pb-acl-search-item' + (alreadyAdded ? ' acl-already-added' : '') + '" data-cid="' + u.cid + '">';
            html += '<span class="acl-user-cid">' + u.cid + '</span>';
            html += '<span class="acl-user-name">' + escHtml(u.name) + '</span>';
            if (orgNames) html += '<span class="acl-user-orgs">' + escHtml(orgNames) + '</span>';
            if (alreadyAdded) html += '<span class="badge badge-secondary" style="font-size:0.55rem;">' + t('playbook.acl.alreadyAdded') + '</span>';
            html += '</div>';
        });
        $results.html(html).show();
    }

    /**
     * Load org picker chips for private_org sharing.
     */
    function loadOrgPicker() {
        var $picker = $('#pb_acl_org_picker');
        if (aclOrgCache) {
            renderOrgPicker(aclOrgCache);
            return;
        }
        $picker.html('<span class="small text-muted">' + t('common.loading') + '</span>');
        $.getJSON(API_ORGS, function(data) {
            if (data && data.success && data.orgs) {
                aclOrgCache = data.orgs;
                renderOrgPicker(data.orgs);
            }
        });
    }

    function renderOrgPicker(orgs) {
        var $picker = $('#pb_acl_org_picker');
        var html = '';
        orgs.forEach(function(org) {
            var active = aclSelectedOrgs.has(org.org_code) ? ' active' : '';
            html += '<div class="pb-acl-org-chip' + active + '" data-org="' + escHtml(org.org_code) + '">';
            html += '<i class="fas fa-building" style="font-size:0.6rem;"></i> ';
            html += escHtml(org.display_name);
            html += '</div>';
        });
        $picker.html(html);
    }

    /**
     * Load and display members of selected organization(s), allowing exclusion.
     */
    function loadOrgMembers() {
        var $members = $('#pb_acl_org_members');
        if (!aclSelectedOrgs.size) {
            $members.hide();
            return;
        }

        var orgCodes = Array.from(aclSelectedOrgs).join(',');
        $members.html('<div class="pb-acl-org-loading"><div class="spinner-border spinner-border-sm text-primary"></div> ' + t('common.loading') + '</div>').show();

        $.getJSON(API_ORG_MEMBERS + '?orgs=' + encodeURIComponent(orgCodes), function(data) {
            if (!data || !data.success) {
                $members.html('<div class="pb-acl-org-loading text-muted">' + t('playbook.acl.loadFailed') + '</div>');
                return;
            }
            renderOrgMembers(data.members || []);
        });
    }

    function renderOrgMembers(members) {
        var $members = $('#pb_acl_org_members');
        if (!members.length) {
            $members.html('<div class="pb-acl-org-loading text-muted">' + t('playbook.acl.noOrgMembers') + '</div>').show();
            return;
        }

        // Get current ACL CIDs
        var existingCids = new Set();
        $('#pb_acl_list .pb-acl-table [data-acl-cid]').each(function() {
            existingCids.add(parseInt($(this).data('acl-cid')));
        });

        var html = '<div class="pb-acl-org-header small px-2 py-1" style="background:#f0f0f0; display:flex; justify-content:space-between;">';
        html += '<span>' + t('playbook.acl.orgMembers') + ' (' + members.length + ')</span>';
        html += '<button class="btn btn-xs btn-outline-primary" id="pb_acl_org_add_all"><i class="fas fa-plus mr-1"></i>' + t('playbook.acl.addAllOrg') + '</button>';
        html += '</div>';

        members.forEach(function(m) {
            var isExcluded = aclExcludedCids.has(m.cid);
            var isAlreadyInAcl = existingCids.has(m.cid);
            var orgNames = (m.orgs || []).map(function(o) { return o.display_name; }).join(', ');
            html += '<div class="pb-acl-org-member-row' + (isExcluded ? ' excluded' : '') + '" data-member-cid="' + m.cid + '">';
            html += '<span class="acl-user-cid">' + m.cid + '</span>';
            html += '<span class="acl-user-name">' + escHtml(m.name) + '</span>';
            if (orgNames) html += '<span class="acl-user-orgs" style="font-size:0.6rem;color:#888;">' + escHtml(orgNames) + '</span>';
            if (isAlreadyInAcl) {
                html += '<span class="badge badge-secondary" style="font-size:0.55rem;">' + t('playbook.acl.alreadyAdded') + '</span>';
            } else {
                html += '<label class="acl-exclude-cb mb-0" title="' + t('playbook.acl.excludeUser') + '">';
                html += '<input type="checkbox" class="pb-acl-org-exclude" data-cid="' + m.cid + '"' + (isExcluded ? ' checked' : '') + '>';
                html += ' <span style="font-size:0.6rem;">' + t('playbook.acl.exclude') + '</span>';
                html += '</label>';
            }
            html += '</div>';
        });

        $members.html(html).show();
    }

    // =========================================================================
    // CHANGELOG
    // =========================================================================

    var _clMonths = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

    /** Format a changelog timestamp to "DD Mon YYYY HH:MMZ" */
    function fmtClTs(raw) {
        if (!raw) return '-';
        var d = new Date(raw.replace(' ', 'T') + (raw.indexOf('Z') < 0 ? 'Z' : ''));
        if (isNaN(d)) return raw;
        return d.getUTCDate() + ' ' + _clMonths[d.getUTCMonth()] + ' ' + d.getUTCFullYear() + ' ' +
            String(d.getUTCHours()).padStart(2,'0') + ':' + String(d.getUTCMinutes()).padStart(2,'0') + 'Z';
    }

    /** Return a date key "DD Mon YYYY" for grouping */
    function clDateKey(raw) {
        if (!raw) return '';
        var d = new Date(raw.replace(' ', 'T') + (raw.indexOf('Z') < 0 ? 'Z' : ''));
        if (isNaN(d)) return '';
        return d.getUTCDate() + ' ' + _clMonths[d.getUTCMonth()] + ' ' + d.getUTCFullYear();
    }

    function loadChangelog(playId, page) {
        if (playId !== clCompactPlayId) clCompactPage = 1;
        clCompactPlayId = playId;
        if (typeof page === 'number') clCompactPage = page;
        var container = $('#pb_changelog_content');
        container.html('<div class="pb-loading py-1"><div class="spinner-border spinner-border-sm text-primary"></div></div>');

        $.getJSON(API_LOG + '?play_id=' + playId + '&per_page=' + clCompactPerPage + '&page=' + clCompactPage, function(data) {
            if (!data || !data.success || !data.data || !data.data.length) {
                container.html('<div class="small text-muted py-1">' + t('playbook.noChanges') + '</div>');
                return;
            }

            var totalPages = Math.ceil((data.total || data.data.length) / clCompactPerPage);
            var lastDateKey = '';
            var html = '<ul class="pb-changelog-list">';
            data.data.forEach(function(entry) {
                // Date group divider
                var dk = clDateKey(entry.changed_at);
                if (dk && dk !== lastDateKey) {
                    lastDateKey = dk;
                    html += '<li class="pb-cl-date-divider">&mdash; ' + escHtml(dk) + ' &mdash;</li>';
                }
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
                html += ' <span class="text-muted" style="font-size:0.58rem;">' + escHtml(entry.changed_by || '') + ' ' + escHtml(fmtClTs(entry.changed_at)) + '</span>';
                html += '</li>';
            });
            html += '</ul>';

            // Pagination (prev / "Page X of Y" / next)
            if (totalPages > 1) {
                html += '<div class="pb-cl-pagination">';
                html += '<button class="btn btn-xs btn-outline-secondary pb-cl-page-btn" data-clview="compact" data-clpage="prev"' + (clCompactPage <= 1 ? ' disabled' : '') + '><i class="fas fa-chevron-left"></i></button>';
                html += '<span class="pb-cl-page-info">Page ' + clCompactPage + ' of ' + totalPages + '</span>';
                html += '<button class="btn btn-xs btn-outline-secondary pb-cl-page-btn" data-clview="compact" data-clpage="next"' + (clCompactPage >= totalPages ? ' disabled' : '') + '><i class="fas fa-chevron-right"></i></button>';
                html += '</div>';
            }
            container.html(html);
        });
    }

    // =========================================================================
    // ROUTE ANALYSIS PANEL (shared module — RouteAnalysisPanel)
    // =========================================================================

    /**
     * Bridge function: fetches analysis data and delegates to the shared
     * RouteAnalysisPanel module for rendering.
     *
     * @param {number|null} routeId   - DB route_id (used for map routeId lookup)
     * @param {string}      routeStr  - Filed route string
     * @param {string}      origin    - Origin ICAO
     * @param {string}      dest      - Destination ICAO
     */
    var PSEUDO_FIXES = { 'UNKN': true, 'VARIOUS': true };

    window.showRouteAnalysis = function(routeId, routeStr, origin, dest, routeObj) {
        if (!routeStr && !routeId) return;
        if (typeof RouteAnalysisPanel === 'undefined') return;

        // Strip pseudo-fix tokens from origin/dest
        if (origin && PSEUDO_FIXES[origin.toUpperCase()]) origin = '';
        if (dest && PSEUDO_FIXES[dest.toUpperCase()]) dest = '';

        // Toggle off if clicking the same route
        if (activeAnalysisRouteId === routeId && activeAnalysisRouteId != null) {
            RouteAnalysisPanel.clear();
            activeAnalysisRouteId = null;
            $('.pb-route-table tbody tr, .pb-compact-table tbody tr').removeClass('pb-route-analysis-active');
            return;
        }
        activeAnalysisRouteId = routeId;

        RouteAnalysisPanel.showLoading(routeStr || '', origin, dest);

        // Try client-resolved waypoints from map (map routeId = plotting index + 1)
        var mapRouteId = null;
        if (routeId != null) {
            var plotIdx = lastPlottedRouteOrder.indexOf(routeId);
            if (plotIdx >= 0) mapRouteId = plotIdx + 1;
        }

        var namedPts = null;
        if (mapRouteId != null && typeof MapLibreRoute !== 'undefined' && MapLibreRoute.getRoutePointsNamed) {
            var allNamed = MapLibreRoute.getRoutePointsNamed();
            if (allNamed[mapRouteId] && allNamed[mapRouteId].length >= 2) {
                namedPts = allNamed[mapRouteId];
            }
        }

        // Fallback: extract waypoints from stored frozen geometry (routeObj parameter)
        if (!namedPts && routeObj && routeObj.route_geometry) {
            try {
                var parsed = typeof routeObj.route_geometry === 'string'
                    ? JSON.parse(routeObj.route_geometry) : routeObj.route_geometry;
                if (parsed.waypoints && parsed.waypoints.length >= 2) {
                    namedPts = parsed.waypoints.map(function(wp) {
                        return { fix: wp.fix_name || 'UNK', lat: wp.lat, lon: wp.lon };
                    });
                }
            } catch (e) { /* fall through to Mode 2 */ }
        }

        var requestData = {
            route_waypoints: namedPts,
            route_string: routeStr || null,
            route_id: (!namedPts && routeId) ? routeId : null,
            origin: origin || null,
            dest: dest || null,
            cruise_kts: 460,
            facility_types: 'ARTCC,FIR,TRACON,SECTOR_HIGH,SECTOR_LOW,SECTOR_SUPERHIGH'
        };

        $.ajax({
            url: API_ANALYSIS,
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(requestData),
            dataType: 'json',
            success: function(resp) {
                if (!resp || resp.status !== 'success') {
                    RouteAnalysisPanel.showError();
                    return;
                }
                RouteAnalysisPanel.show(resp, routeStr, origin, dest, mapRouteId);
            },
            error: function() {
                RouteAnalysisPanel.showError();
            }
        });
    };

    // =========================================================================
    // THROUGHPUT TOGGLE
    // =========================================================================

    /**
     * Toggle throughput data display on route lines.
     * Fetches from SWIM API and applies color-coded styling to map routes.
     */
    function toggleThroughput() {
        var btn = $('#btn-throughput-toggle');
        if (!activePlayData) {
            if (typeof PERTIDialog !== 'undefined') {
                PERTIDialog.toast(t('playbook.throughput.noPlay'), 'info');
            }
            return;
        }

        if (throughputEnabled) {
            // Disable throughput overlay
            throughputEnabled = false;
            throughputData = null;
            btn.removeClass('active btn-info').addClass('btn-outline-info');
            // Re-plot without throughput colors
            plotOnMap();
            return;
        }

        // Fetch throughput data
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i>' + t('playbook.throughput.loading'));

        $.getJSON(API_THROUGHPUT + '?play_id=' + activePlayData.play_id, function(data) {
            btn.prop('disabled', false);

            if (!data || !data.success) {
                btn.html('<i class="fas fa-chart-bar mr-1"></i>' + t('playbook.throughput.toggle'));
                if (typeof PERTIDialog !== 'undefined') {
                    PERTIDialog.toast(data && data.error ? data.error : t('playbook.throughput.loadFailed'), 'error');
                }
                return;
            }

            throughputEnabled = true;
            throughputData = data.throughput || {};
            btn.removeClass('btn-outline-info').addClass('active btn-info');
            btn.html('<i class="fas fa-chart-bar mr-1"></i>' + t('playbook.throughput.toggle'));

            applyThroughputToMap();
        }).fail(function() {
            btn.prop('disabled', false);
            btn.html('<i class="fas fa-chart-bar mr-1"></i>' + t('playbook.throughput.toggle'));
            if (typeof PERTIDialog !== 'undefined') {
                PERTIDialog.toast(t('playbook.throughput.loadFailed'), 'error');
            }
        });
    }

    /**
     * Apply throughput color coding to currently plotted map routes.
     * Colors: green (low) -> yellow (medium) -> red (high) based on flight count.
     */
    function applyThroughputToMap() {
        if (!throughputEnabled || !throughputData || !activePlayData) return;

        var routes = activePlayData.routes || [];
        var maxCount = 0;

        // Find max throughput for normalization
        routes.forEach(function(r) {
            var key = String(r.route_id);
            var count = (throughputData[key] && throughputData[key].count) ? throughputData[key].count : 0;
            if (count > maxCount) maxCount = count;
        });

        if (maxCount === 0) {
            if (typeof PERTIDialog !== 'undefined') {
                PERTIDialog.toast(t('playbook.throughput.noData'), 'info');
            }
            return;
        }

        // Build color-coded route text for map plotting
        var selected = getSelectedRoutes();
        var text = selected.map(function(r) {
            var parts = [];
            if (r.origin) parts.push(r.origin);
            parts.push(r.route_string);
            if (r.dest) parts.push(r.dest);
            var routeStr = parts.join(' ');

            var key = String(r.route_id);
            var count = (throughputData[key] && throughputData[key].count) ? throughputData[key].count : 0;
            var ratio = maxCount > 0 ? count / maxCount : 0;
            var color = throughputRatioToColor(ratio);
            return routeStr + ';' + color;
        }).join('\n');

        // Apply mandatory markers
        text = text.split('\n').map(function(line) {
            var trimmed = line.trim();
            if (!trimmed) return line;
            if (trimmed.indexOf('>') !== -1) return line;
            var colorSuffix = '';
            var semiIdx = trimmed.indexOf(';');
            if (semiIdx !== -1) {
                colorSuffix = trimmed.slice(semiIdx);
                trimmed = trimmed.slice(0, semiIdx).trim();
            }
            var tokens = trimmed.split(/\s+/).filter(Boolean);
            if (tokens.length > 2) {
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

    /**
     * Map a 0-1 ratio to a green->yellow->red color string.
     */
    function throughputRatioToColor(ratio) {
        if (ratio <= 0) return '#28a745';
        if (ratio >= 1) return '#dc3545';
        // Green (0) -> Yellow (0.5) -> Red (1.0)
        var r, g, b;
        if (ratio < 0.5) {
            var t2 = ratio * 2;
            r = Math.round(40 + 215 * t2);
            g = Math.round(167 + 88 * t2);
            b = Math.round(69 - 69 * t2);
        } else {
            var t2 = (ratio - 0.5) * 2;
            r = Math.round(255 - 35 * t2);
            g = Math.round(255 - 202 * t2);
            b = Math.round(0 + 69 * t2);
        }
        return '#' + ((1 << 24) + (r << 16) + (g << 8) + b).toString(16).slice(1);
    }

    // =========================================================================
    // PLAYBOOK EXPORT (GeoJSON / KML / CSV)
    // =========================================================================

    function pbDownloadFile(content, filename, mimeType) {
        var blob = new Blob([content], { type: mimeType });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    function generatePlaybookRouteName(play, route) {
        var orig = (route.origin || '').replace(/\s+/g, '-');
        var dest = (route.dest || '').replace(/\s+/g, '-');
        return (play.play_name || 'play') + '_' + orig + '-' + dest;
    }

    /**
     * Build an array of route export objects with geometry from the map source.
     * Each object has { route, geometry, throughput }.
     */
    function collectPlaybookExportData() {
        if (!activePlayData) return [];
        var selected = getSelectedRoutes();
        if (!selected.length) return [];

        // Group map source features by routeId (1-based, matching plotter's ++currentRouteId)
        var segmentsByMapRouteId = {};
        var map = window.graphic_map;
        if (map && map.getSource('routes')) {
            var source = map.getSource('routes');
            var feats = (source._data && source._data.features) || [];
            feats.forEach(function(f) {
                if (!f.properties || !f.geometry) return;
                var rid = f.properties.routeId || 0;
                if (!rid) return;
                if (!segmentsByMapRouteId[rid]) segmentsByMapRouteId[rid] = [];
                segmentsByMapRouteId[rid].push(f);
            });
        }

        // Build DB route_id → map routeId mapping from stored plot order
        var dbToMapId = {};
        lastPlottedRouteOrder.forEach(function(dbId, idx) {
            dbToMapId[dbId] = idx + 1; // map routeIds are 1-based
        });

        var results = [];
        selected.forEach(function(r) {
            // Build the route string for metadata
            var parts = [];
            if (r.origin) parts.push(r.origin);
            parts.push(r.route_string);
            if (r.dest) parts.push(r.dest);
            var routeStr = parts.join(' ');

            // Match via stored routeId mapping (correct, order-based)
            var mapRouteId = dbToMapId[r.route_id];
            var segments = [];
            var coords = [];

            if (mapRouteId && segmentsByMapRouteId[mapRouteId]) {
                segmentsByMapRouteId[mapRouteId].forEach(function(f) {
                    segments.push(f);
                    if (f.geometry.type === 'LineString' && f.geometry.coordinates) {
                        coords.push(f.geometry.coordinates);
                    }
                });
            }

            var tp = throughputData ? throughputData[r.route_id] : null;

            results.push({
                route: r,
                routeStr: routeStr,
                segments: segments,
                coords: coords,
                throughput: tp || null
            });
        });
        return results;
    }

    function exportPlaybookGeoJSON() {
        if (!activePlayData) {
            PERTIDialog.toast(t('playbook.export.noRoutes'), 'warning');
            return;
        }
        var exportData = collectPlaybookExportData();
        if (!exportData.length) {
            PERTIDialog.toast(t('playbook.export.noRoutes'), 'warning');
            return;
        }

        var play = activePlayData;
        var features = [];

        exportData.forEach(function(d) {
            var r = d.route;
            var tp = d.throughput;

            // Build full route feature (MultiLineString)
            var geometry;
            if (d.coords.length > 0) {
                geometry = { type: 'MultiLineString', coordinates: d.coords };
            } else {
                // No geometry available — skip or use empty
                geometry = { type: 'MultiLineString', coordinates: [] };
            }

            features.push({
                type: 'Feature',
                properties: {
                    featureType: 'route',
                    routeId: r.route_id,
                    routeName: generatePlaybookRouteName(play, r),
                    playName: play.play_name || '',
                    playDisplayName: play.display_name || '',
                    playCategory: play.category || '',
                    playSource: play.source || '',
                    playDescription: play.description || '',
                    scenarioType: play.scenario_type || '',
                    impactedArea: play.impacted_area || '',
                    facilitiesInvolved: play.facilities_involved || '',
                    airacCycle: play.airac_cycle || '',
                    ctpScope: play.ctp_scope || '',
                    routeString: r.route_string || '',
                    origin: r.origin || '',
                    dest: r.dest || '',
                    originAirports: r.origin_airports || '',
                    originTracons: r.origin_tracons || '',
                    originArtccs: r.origin_artccs || '',
                    destAirports: r.dest_airports || '',
                    destTracons: r.dest_tracons || '',
                    destArtccs: r.dest_artccs || '',
                    traversedArtccs: r.traversed_artccs || '',
                    traversedTracons: r.traversed_tracons || '',
                    traversedSectorsLow: r.traversed_sectors_low || '',
                    traversedSectorsHigh: r.traversed_sectors_high || '',
                    traversedSectorsSuperHigh: r.traversed_sectors_superhigh || '',
                    remarks: r.remarks || '',
                    sortOrder: r.sort_order || 0,
                    throughputPlanned: tp ? tp.planned_count : null,
                    throughputSlots: tp ? tp.slot_count : null,
                    throughputPeakRate: tp ? tp.peak_rate_hr : null,
                    throughputAvgRate: tp ? tp.avg_rate_hr : null,
                    throughputPeriodStart: tp ? tp.period_start : null,
                    throughputPeriodEnd: tp ? tp.period_end : null
                },
                geometry: geometry
            });

            // Also include individual segment features if available
            d.segments.forEach(function(seg) {
                var sp = seg.properties || {};
                features.push({
                    type: 'Feature',
                    properties: {
                        featureType: 'route_segment',
                        routeId: r.route_id,
                        routeName: generatePlaybookRouteName(play, r),
                        playName: play.play_name || '',
                        fromFix: sp.fromFix || '',
                        toFix: sp.toFix || '',
                        distance: sp.distance || 0,
                        airway: sp.airway || '',
                        procedure: sp.procedure || '',
                        procedureType: sp.procedureType || '',
                        color: sp.color || '',
                        solid: sp.solid || false
                    },
                    geometry: seg.geometry
                });
            });
        });

        // Build route lookup for enriching merged segments
        var routeById = {};
        exportData.forEach(function(d) { routeById[d.route.route_id] = d.route; });

        // Layer 3: Merged individual segments — deduplicate identical geometries, flatten metadata
        var segFeatures = features.filter(function(f) { return f.properties.featureType === 'route_segment'; });
        var segByGeom = {};
        segFeatures.forEach(function(f) {
            var key = JSON.stringify(f.geometry.coordinates);
            if (!segByGeom[key]) segByGeom[key] = { geometry: f.geometry, entries: [] };
            segByGeom[key].entries.push(f.properties);
        });

        var pbMergeVals = function(entries, field) {
            var vals = [];
            entries.forEach(function(e) { if (e[field] && e[field] !== '') vals.push(e[field]); });
            return vals.filter(function(v, i, a) { return a.indexOf(v) === i; }).join('; ');
        };
        var pbUniqueArr = function(arr) {
            return arr.filter(function(v, i, a) { return v && a.indexOf(v) === i; });
        };

        Object.keys(segByGeom).forEach(function(gKey) {
            var group = segByGeom[gKey];
            var entries = group.entries;
            var first = entries[0];

            // Collect route-level data for all participating routes
            var routeIds = [], origins = [], dests = [], routeStrings = [];
            var origTracons = [], origArtccs = [], destTracons = [], destArtccs = [];
            var travArtccs = [], travTracons = [];
            entries.forEach(function(e) {
                routeIds.push(e.routeId);
                var r = routeById[e.routeId];
                if (r) {
                    if (r.origin) origins.push(r.origin);
                    if (r.dest) dests.push(r.dest);
                    if (r.route_string) routeStrings.push(r.route_string);
                    if (r.origin_tracons) origTracons.push(r.origin_tracons);
                    if (r.origin_artccs) origArtccs.push(r.origin_artccs);
                    if (r.dest_tracons) destTracons.push(r.dest_tracons);
                    if (r.dest_artccs) destArtccs.push(r.dest_artccs);
                    if (r.traversed_artccs) travArtccs.push(r.traversed_artccs);
                    if (r.traversed_tracons) travTracons.push(r.traversed_tracons);
                }
            });

            features.push({
                type: 'Feature',
                properties: {
                    featureType: 'merged_segment',
                    routeCount: entries.length,
                    routeIds: pbUniqueArr(routeIds).join(', '),
                    routeNames: pbMergeVals(entries, 'routeName'),
                    playName: first.playName || '',
                    fromFix: first.fromFix,
                    toFix: first.toFix,
                    distance: first.distance || 0,
                    airways: pbMergeVals(entries, 'airway'),
                    procedures: pbMergeVals(entries, 'procedure'),
                    origins: pbUniqueArr(origins).join('; '),
                    destinations: pbUniqueArr(dests).join('; '),
                    routeStrings: pbUniqueArr(routeStrings).join('; '),
                    originTracons: pbUniqueArr(origTracons).join('; '),
                    originArtccs: pbUniqueArr(origArtccs).join('; '),
                    destTracons: pbUniqueArr(destTracons).join('; '),
                    destArtccs: pbUniqueArr(destArtccs).join('; '),
                    traversedArtccs: pbUniqueArr(travArtccs).join('; '),
                    traversedTracons: pbUniqueArr(travTracons).join('; '),
                },
                geometry: group.geometry,
            });
        });

        // Layer 4: Merged routes — connect merged segments with shared metadata
        var mergedSegs = features.filter(function(f) { return f.properties.featureType === 'merged_segment'; });
        var pbMetaKey = function(p) {
            return [p.routeIds, p.origins, p.destinations, p.routeStrings].join('||');
        };

        var pbMetaGroups = {};
        mergedSegs.forEach(function(f) {
            var k = pbMetaKey(f.properties);
            if (!pbMetaGroups[k]) pbMetaGroups[k] = [];
            pbMetaGroups[k].push(f);
        });

        var pbPtKey = function(c) { return c[0].toFixed(6) + ',' + c[1].toFixed(6); };

        Object.keys(pbMetaGroups).forEach(function(mk) {
            var group = pbMetaGroups[mk];
            if (!group.length) return;

            var segs = group.map(function(f) {
                return { coords: f.geometry.coordinates, props: f.properties };
            });

            var byStart = {};
            segs.forEach(function(s, i) {
                var sk = pbPtKey(s.coords[0]);
                if (!byStart[sk]) byStart[sk] = [];
                byStart[sk].push(i);
            });

            var used = {};
            var chains = [];

            for (var i = 0; i < segs.length; i++) {
                if (used[i]) continue;
                var chain = [i];
                used[i] = true;

                var curEnd = pbPtKey(segs[i].coords[segs[i].coords.length - 1]);
                var seeking = true;
                while (seeking) {
                    seeking = false;
                    var cands = byStart[curEnd] || [];
                    for (var ci = 0; ci < cands.length; ci++) {
                        if (!used[cands[ci]]) {
                            chain.push(cands[ci]);
                            used[cands[ci]] = true;
                            curEnd = pbPtKey(segs[cands[ci]].coords[segs[cands[ci]].coords.length - 1]);
                            seeking = true;
                            break;
                        }
                    }
                }
                chains.push(chain);
            }

            chains.forEach(function(chain) {
                var firstProps = segs[chain[0]].props;
                var allFixes = [];
                var mergedCoords = [];
                var totalDist = 0;

                chain.forEach(function(idx, ci) {
                    var s = segs[idx];
                    allFixes.push(s.props.fromFix);
                    if (ci === chain.length - 1) allFixes.push(s.props.toFix);
                    totalDist += s.props.distance || 0;

                    if (ci === 0) {
                        mergedCoords = s.coords.slice();
                    } else {
                        mergedCoords = mergedCoords.concat(s.coords.slice(1));
                    }
                });

                features.push({
                    type: 'Feature',
                    properties: {
                        featureType: 'merged_route',
                        segmentCount: chain.length,
                        fixes: allFixes.join(', '),
                        totalDistance: totalDist,
                        routeCount: firstProps.routeCount,
                        routeIds: firstProps.routeIds,
                        routeNames: firstProps.routeNames,
                        playName: firstProps.playName || '',
                        origins: firstProps.origins,
                        destinations: firstProps.destinations,
                        routeStrings: firstProps.routeStrings || '',
                        originTracons: firstProps.originTracons || '',
                        originArtccs: firstProps.originArtccs || '',
                        destTracons: firstProps.destTracons || '',
                        destArtccs: firstProps.destArtccs || '',
                        traversedArtccs: firstProps.traversedArtccs || '',
                        traversedTracons: firstProps.traversedTracons || '',
                    },
                    geometry: {
                        type: 'LineString',
                        coordinates: mergedCoords,
                    },
                });
            });
        });

        var geojson = {
            type: 'FeatureCollection',
            properties: {
                exportType: 'playbook',
                playName: play.play_name || '',
                exportedAt: new Date().toISOString(),
                routeCount: exportData.length,
                segmentCount: segFeatures.length,
                mergedSegmentCount: Object.keys(segByGeom).length,
                mergedRouteCount: features.filter(function(f) { return f.properties.featureType === 'merged_route'; }).length
            },
            features: features
        };

        var filename = (play.play_name || 'playbook_export').replace(/[^a-zA-Z0-9_-]/g, '_') + '.geojson';
        pbDownloadFile(JSON.stringify(geojson, null, 2), filename, 'application/geo+json');
    }

    function exportPlaybookKML() {
        if (!activePlayData) {
            PERTIDialog.toast(t('playbook.export.noRoutes'), 'warning');
            return;
        }
        var exportData = collectPlaybookExportData();
        if (!exportData.length) {
            PERTIDialog.toast(t('playbook.export.noRoutes'), 'warning');
            return;
        }

        var play = activePlayData;
        var kml = '<?xml version="1.0" encoding="UTF-8"?>\n';
        kml += '<kml xmlns="http://www.opengis.net/kml/2.2">\n';
        kml += '<Document>\n';
        kml += '<name>' + escHtml(play.play_name || 'Playbook Export') + '</name>\n';
        kml += '<description><![CDATA[';
        kml += 'Play: ' + (play.play_name || '') + '\n';
        if (play.display_name) kml += 'Display Name: ' + play.display_name + '\n';
        if (play.category) kml += 'Category: ' + play.category + '\n';
        if (play.source) kml += 'Source: ' + play.source + '\n';
        if (play.scenario_type) kml += 'Scenario: ' + play.scenario_type + '\n';
        if (play.impacted_area) kml += 'Impacted Area: ' + play.impacted_area + '\n';
        if (play.facilities_involved) kml += 'Facilities: ' + play.facilities_involved + '\n';
        if (play.description) kml += 'Description: ' + play.description + '\n';
        kml += 'Exported: ' + new Date().toISOString() + '\n';
        kml += 'Routes: ' + exportData.length + '\n';
        kml += ']]></description>\n';

        exportData.forEach(function(d) {
            var r = d.route;
            var tp = d.throughput;
            var routeName = generatePlaybookRouteName(play, r);

            kml += '<Folder>\n';
            kml += '<name>' + escHtml(routeName) + '</name>\n';
            kml += '<description><![CDATA[';
            kml += 'Route: ' + (r.route_string || '') + '\n';
            kml += 'Origin: ' + (r.origin || '') + '\n';
            kml += 'Destination: ' + (r.dest || '') + '\n';
            if (r.origin_artccs) kml += 'Origin ARTCCs: ' + r.origin_artccs + '\n';
            if (r.dest_artccs) kml += 'Dest ARTCCs: ' + r.dest_artccs + '\n';
            if (r.traversed_artccs) kml += 'Traversed ARTCCs: ' + r.traversed_artccs + '\n';
            if (r.remarks) kml += 'Remarks: ' + r.remarks + '\n';
            if (tp) {
                kml += 'Planned Count: ' + (tp.planned_count || '') + '\n';
                kml += 'Slot Count: ' + (tp.slot_count || '') + '\n';
                kml += 'Peak Rate/hr: ' + (tp.peak_rate_hr || '') + '\n';
                kml += 'Avg Rate/hr: ' + (tp.avg_rate_hr || '') + '\n';
            }
            kml += ']]></description>\n';

            // Route segments as placemarks
            d.segments.forEach(function(seg, idx) {
                var sp = seg.properties || {};
                if (seg.geometry.type !== 'LineString' || !seg.geometry.coordinates) return;
                var coords = seg.geometry.coordinates;
                kml += '<Placemark>\n';
                kml += '<name>' + escHtml((sp.fromFix || '') + '-' + (sp.toFix || '')) + '</name>\n';
                kml += '<Style><LineStyle><color>ff' + hexToKmlColor(sp.color || '#3388ff') + '</color><width>3</width></LineStyle></Style>\n';
                kml += '<ExtendedData>';
                kml += '<Data name="fromFix"><value>' + escHtml(sp.fromFix || '') + '</value></Data>';
                kml += '<Data name="toFix"><value>' + escHtml(sp.toFix || '') + '</value></Data>';
                kml += '<Data name="distance"><value>' + (sp.distance || 0) + '</value></Data>';
                if (sp.airway) kml += '<Data name="airway"><value>' + escHtml(sp.airway) + '</value></Data>';
                if (sp.procedure) kml += '<Data name="procedure"><value>' + escHtml(sp.procedure) + '</value></Data>';
                kml += '</ExtendedData>\n';
                kml += '<LineString><coordinates>';
                kml += coords.map(function(c) { return c[0] + ',' + c[1] + ',0'; }).join(' ');
                kml += '</coordinates></LineString>\n';
                kml += '</Placemark>\n';
            });

            kml += '</Folder>\n';
        });

        kml += '</Document>\n</kml>';

        var filename = (play.play_name || 'playbook_export').replace(/[^a-zA-Z0-9_-]/g, '_') + '.kml';
        pbDownloadFile(kml, filename, 'application/vnd.google-earth.kml+xml');
    }

    /**
     * Convert hex color (#RRGGBB) to KML color (BBGGRR).
     */
    function hexToKmlColor(hex) {
        hex = hex.replace('#', '');
        if (hex.length === 3) hex = hex[0]+hex[0]+hex[1]+hex[1]+hex[2]+hex[2];
        return hex.substring(4,6) + hex.substring(2,4) + hex.substring(0,2);
    }

    function exportPlaybookCSV() {
        if (!activePlayData) {
            PERTIDialog.toast(t('playbook.export.noRoutes'), 'warning');
            return;
        }
        var exportData = collectPlaybookExportData();
        if (!exportData.length) {
            PERTIDialog.toast(t('playbook.export.noRoutes'), 'warning');
            return;
        }

        var play = activePlayData;
        var headers = [
            'play_name','display_name','category','source','scenario_type',
            'impacted_area','facilities_involved','airac_cycle','ctp_scope',
            'route_id','route_string','origin','dest',
            'origin_airports','origin_tracons','origin_artccs',
            'dest_airports','dest_tracons','dest_artccs',
            'traversed_artccs','traversed_tracons',
            'traversed_sectors_low','traversed_sectors_high','traversed_sectors_superhigh',
            'remarks','sort_order',
            'throughput_planned','throughput_slots','throughput_peak_rate_hr',
            'throughput_avg_rate_hr','throughput_period_start','throughput_period_end'
        ];

        var rows = [headers.join(',')];

        exportData.forEach(function(d) {
            var r = d.route;
            var tp = d.throughput;
            var vals = [
                csvQuote(play.play_name), csvQuote(play.display_name),
                csvQuote(play.category), csvQuote(play.source),
                csvQuote(play.scenario_type), csvQuote(play.impacted_area),
                csvQuote(play.facilities_involved), csvQuote(play.airac_cycle),
                csvQuote(play.ctp_scope),
                r.route_id || '', csvQuote(r.route_string),
                csvQuote(r.origin), csvQuote(r.dest),
                csvQuote(r.origin_airports), csvQuote(r.origin_tracons),
                csvQuote(r.origin_artccs), csvQuote(r.dest_airports),
                csvQuote(r.dest_tracons), csvQuote(r.dest_artccs),
                csvQuote(r.traversed_artccs), csvQuote(r.traversed_tracons),
                csvQuote(r.traversed_sectors_low), csvQuote(r.traversed_sectors_high),
                csvQuote(r.traversed_sectors_superhigh),
                csvQuote(r.remarks), r.sort_order || 0,
                tp ? (tp.planned_count || '') : '',
                tp ? (tp.slot_count || '') : '',
                tp ? (tp.peak_rate_hr || '') : '',
                tp ? (tp.avg_rate_hr || '') : '',
                tp ? csvQuote(tp.period_start) : '',
                tp ? csvQuote(tp.period_end) : ''
            ];
            rows.push(vals.join(','));
        });

        var filename = (play.play_name || 'playbook_export').replace(/[^a-zA-Z0-9_-]/g, '_') + '.csv';
        pbDownloadFile(rows.join('\n'), filename, 'text/csv');
    }

    function csvQuote(val) {
        if (val === null || val === undefined) return '';
        var s = String(val);
        if (s.indexOf(',') !== -1 || s.indexOf('"') !== -1 || s.indexOf('\n') !== -1) {
            return '"' + s.replace(/"/g, '""') + '"';
        }
        return s;
    }

    // =========================================================================
    // PLAY CHANGELOG TAB
    // =========================================================================

    /**
     * Load and render detailed change history for a play in the changelog panel.
     * Shows timestamp, author, action, field, and color-coded old/new values.
     */
    function loadPlayChangelog(playId, page) {
        if (playId !== clDetailPlayId) clDetailPage = 1;
        clDetailPlayId = playId;
        if (typeof page === 'number') clDetailPage = page;
        var panel = $('#play-changelog-panel');
        if (!panel.length) return;

        panel.html(
            '<div class="pb-loading py-2"><div class="spinner-border spinner-border-sm text-primary"></div> ' +
            '<span class="small text-muted">' + t('playbook.changelogTab.loading') + '</span></div>'
        ).slideDown(150);

        $.getJSON(API_LOG + '?play_id=' + playId + '&per_page=' + clDetailPerPage + '&page=' + clDetailPage, function(data) {
            if (!data || !data.success || !data.data || !data.data.length) {
                panel.html(
                    '<div class="small text-muted py-2"><i class="fas fa-history mr-1"></i>' +
                    t('playbook.noChanges') + '</div>'
                );
                return;
            }

            var entries = data.data;
            var totalPages = Math.ceil((data.total || entries.length) / clDetailPerPage);
            var html = '';
            html += '<div class="pb-changelog-tab-header small font-weight-bold mb-1">';
            html += '<i class="fas fa-history mr-1"></i>' + t('playbook.changelogTab.title') + ' (' + (data.total || entries.length) + ')';
            html += '</div>';

            // Human-readable action labels and badge classes
            var actionMap = {
                'play_created':  { label: 'Created',  cls: 'badge-success' },
                'play_updated':  { label: 'Updated',  cls: 'badge-info' },
                'play_deleted':  { label: 'Deleted',  cls: 'badge-danger' },
                'route_added':   { label: 'Route +',  cls: 'badge-success' },
                'route_updated': { label: 'Route \u0394', cls: 'badge-warning' },
                'route_deleted': { label: 'Route \u2212', cls: 'badge-danger' },
                'create':        { label: 'Created',  cls: 'badge-success' },
                'add':           { label: 'Added',    cls: 'badge-success' },
                'update':        { label: 'Updated',  cls: 'badge-info' },
                'edit':          { label: 'Updated',  cls: 'badge-info' },
                'delete':        { label: 'Deleted',  cls: 'badge-danger' },
                'remove':        { label: 'Removed',  cls: 'badge-danger' }
            };

            // Human-readable field labels
            var fieldMap = {
                'route_string': 'Route', 'play_name': 'Name', 'display_name': 'Display Name',
                'description': 'Desc', 'category': 'Category', 'scenario_type': 'Scenario',
                'route_format': 'Format', 'status': 'Status', 'facilities_involved': 'Facilities',
                'impacted_area': 'Area', 'remarks': 'Remarks', 'visibility': 'Visibility',
                'origin': 'Origin', 'dest': 'Dest', 'origin_filter': 'Orig Filter',
                'dest_filter': 'Dest Filter', 'origin_artccs': 'Orig ARTCCs',
                'dest_artccs': 'Dest ARTCCs', 'origin_airports': 'Orig Apts',
                'dest_airports': 'Dest Apts', 'origin_tracons': 'Orig TRACONs',
                'dest_tracons': 'Dest TRACONs'
            };

            html += '<div class="pb-changelog-table-wrap">';
            html += '<table class="pb-changelog-table">';
            html += '<thead><tr>';
            html += '<th class="pb-cl-ts">' + t('playbook.changelogTab.timestamp') + '</th>';
            html += '<th class="pb-cl-who">' + t('playbook.changelogTab.author') + '</th>';
            html += '<th class="pb-cl-act">' + t('playbook.changelogTab.action') + '</th>';
            html += '<th class="pb-cl-fld">' + t('playbook.changelogTab.field') + '</th>';
            html += '<th class="pb-cl-chg">' + t('playbook.changelogTab.changes') + '</th>';
            html += '</tr></thead><tbody>';

            var lastDateKey = '';
            entries.forEach(function(entry) {
                var raw = (entry.action || '').toLowerCase();
                var mapped = actionMap[raw] || { label: entry.action || '-', cls: 'badge-secondary' };
                var fieldLabel = fieldMap[entry.field_name] || entry.field_name || '-';
                var tsText = fmtClTs(entry.changed_at);

                // Date group header row
                var dk = clDateKey(entry.changed_at);
                if (dk && dk !== lastDateKey) {
                    lastDateKey = dk;
                    html += '<tr class="pb-cl-group-header"><td colspan="5">' + escHtml(dk) + '</td></tr>';
                }

                // Author: prefer name, fall back to CID
                var author = entry.changed_by_name || entry.changed_by || '-';

                html += '<tr>';
                html += '<td class="pb-cl-ts">' + escHtml(tsText) + '</td>';
                html += '<td class="pb-cl-who" title="CID: ' + escHtml(entry.changed_by || '') + '">' + escHtml(author) + '</td>';
                html += '<td class="pb-cl-act"><span class="badge ' + mapped.cls + '">' + escHtml(mapped.label) + '</span></td>';
                html += '<td class="pb-cl-fld">' + escHtml(fieldLabel) + '</td>';
                html += '<td class="pb-cl-chg">';

                if (entry.old_value || entry.new_value) {
                    if (entry.old_value) {
                        html += '<div class="pb-cl-old">' + escHtml(entry.old_value) + '</div>';
                    }
                    if (entry.new_value) {
                        html += '<div class="pb-cl-new">' + escHtml(entry.new_value) + '</div>';
                    }
                } else {
                    html += '<span class="text-muted">-</span>';
                }
                html += '</td>';
                html += '</tr>';
            });

            html += '</tbody></table></div>';

            // Pagination with numbered page buttons
            if (totalPages > 1) {
                html += '<div class="pb-cl-pagination">';
                html += '<button class="btn btn-xs btn-outline-secondary pb-cl-page-btn" data-clview="detail" data-clpage="prev"' + (clDetailPage <= 1 ? ' disabled' : '') + '><i class="fas fa-chevron-left"></i></button>';
                var startPg = Math.max(1, clDetailPage - 2);
                var endPg = Math.min(totalPages, startPg + 4);
                if (endPg - startPg < 4) startPg = Math.max(1, endPg - 4);
                for (var pg = startPg; pg <= endPg; pg++) {
                    html += '<button class="btn btn-xs pb-cl-page-btn' + (pg === clDetailPage ? ' btn-primary' : ' btn-outline-secondary') + '" data-clview="detail" data-clpage="' + pg + '">' + pg + '</button>';
                }
                html += '<button class="btn btn-xs btn-outline-secondary pb-cl-page-btn" data-clview="detail" data-clpage="next"' + (clDetailPage >= totalPages ? ' disabled' : '') + '><i class="fas fa-chevron-right"></i></button>';
                html += '</div>';
            }
            panel.html(html);
        }).fail(function() {
            panel.html(
                '<div class="small text-danger py-1"><i class="fas fa-exclamation-triangle mr-1"></i>' +
                t('playbook.changelogTab.loadFailed') + '</div>'
            );
        });
    }

    // =========================================================================
    // NAT TRACK RESOLUTION
    // =========================================================================

    /**
     * Check if a route string contains a NAT track token (NATA through NATZ).
     * Returns an array of NAT tokens found, or empty array if none.
     */
    function findNatTokens(routeString) {
        if (!routeString) return [];
        var tokens = routeString.toUpperCase().split(/\s+/);
        return tokens.filter(function(tok) {
            return /^NAT[A-Z]$/.test(tok);
        });
    }

    /**
     * Resolve a NAT track token to its expanded fix string.
     * Uses a cache to avoid redundant API calls.
     * Returns a jQuery Deferred that resolves with the expanded string.
     */
    function resolveNatTrack(natToken) {
        var deferred = $.Deferred();
        var upper = natToken.toUpperCase();

        if (natTrackCache[upper] !== undefined) {
            deferred.resolve(natTrackCache[upper]);
            return deferred.promise();
        }

        $.getJSON(API_NAT_TRACKS + '?name=' + encodeURIComponent(upper), function(data) {
            if (data && data.success && data.track) {
                var expanded = data.track.route || '';
                natTrackCache[upper] = expanded;
                deferred.resolve(expanded);
            } else {
                // Cache empty to avoid re-fetching
                natTrackCache[upper] = '';
                deferred.resolve('');
            }
        }).fail(function() {
            natTrackCache[upper] = '';
            deferred.resolve('');
        });

        return deferred.promise();
    }

    /**
     * Expand all NAT track tokens in a route string.
     * Returns a jQuery Deferred that resolves with the expanded route string.
     */
    function expandNatTracks(routeString) {
        var deferred = $.Deferred();
        var natTokens = findNatTokens(routeString);

        if (!natTokens.length) {
            deferred.resolve(routeString);
            return deferred.promise();
        }

        // Resolve all unique NAT tokens in parallel
        var uniqueTokens = [];
        var seen = {};
        natTokens.forEach(function(tok) {
            if (!seen[tok]) {
                seen[tok] = true;
                uniqueTokens.push(tok);
            }
        });

        var promises = uniqueTokens.map(function(tok) {
            return resolveNatTrack(tok);
        });

        $.when.apply($, promises).then(function() {
            var results = uniqueTokens.length === 1 ? [arguments[0]] : Array.prototype.slice.call(arguments);
            var expanded = routeString;
            uniqueTokens.forEach(function(tok, idx) {
                var replacement = results[idx] || tok;
                if (replacement) {
                    // Replace the NAT token with its expanded route (global replace)
                    var regex = new RegExp('\\b' + tok + '\\b', 'gi');
                    expanded = expanded.replace(regex, replacement);
                }
            });
            deferred.resolve(expanded);
        });

        return deferred.promise();
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

        // Changelog pagination
        $(document).on('click', '.pb-cl-page-btn', function() {
            var view = $(this).data('clview');
            var pg = $(this).data('clpage');
            if (view === 'compact') {
                if (pg === 'prev') clCompactPage = Math.max(1, clCompactPage - 1);
                else if (pg === 'next') clCompactPage++;
                else clCompactPage = parseInt(pg);
                if (clCompactPlayId) loadChangelog(clCompactPlayId, clCompactPage);
            } else if (view === 'detail') {
                if (pg === 'prev') clDetailPage = Math.max(1, clDetailPage - 1);
                else if (pg === 'next') clDetailPage++;
                else clDetailPage = parseInt(pg);
                if (clDetailPlayId) loadPlayChangelog(clDetailPlayId, clDetailPage);
            }
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
            // Standard view checkboxes
            $('.pb-route-cb').each(function() {
                this.checked = checked;
                if (checked) selectedRouteIds.add(parseInt($(this).val()));
            });
            // Consolidated view group checkboxes
            $('.pb-route-cb-group').each(function() {
                this.checked = checked;
                if (checked) {
                    $(this).data('ids').toString().split(',').forEach(function(id) {
                        selectedRouteIds.add(parseInt(id));
                    });
                }
            });
            updateToolbarVisibility();
        });
        $(document).on('click', '#pb_select_all', function() {
            var totalCbs = $('.pb-route-cb').length + $('.pb-route-cb-group').length;
            var allChecked = totalCbs > 0 && selectedRouteIds.size >= totalCbs;
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
            var $overlay = $('#pb_catalog_overlay').toggleClass('minimized');
            $(this).find('i').toggleClass('fa-chevron-up fa-chevron-down');
        });
        $(document).on('click', '#pb_info_minimize', function() {
            var $overlay = $('#pb_info_overlay').toggleClass('minimized');
            $(this).find('i').toggleClass('fa-chevron-up fa-chevron-down');
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

        // ── Copy menu (body-appended to escape overflow clipping) ──
        (function initCopyMenu() {
            var $menu = $('<div class="pb-copy-menu" id="pb_copy_menu"></div>');
            $menu.append('<a href="#" data-copy="pb">' + t('playbook.copyPB') + '</a>');
            $menu.append('<div class="pb-copy-menu-sep"></div>');
            $menu.append('<a href="#" data-copy="full"><i class="fas fa-list fa-fw"></i> ' + t('playbook.copyRoutesFull') + '</a>');
            $menu.append('<a href="#" data-copy="route_only"><i class="fas fa-align-left fa-fw"></i> ' + t('playbook.copyRoutesRouteOnly') + '</a>');
            $menu.append('<a href="#" data-copy="grouped"><i class="fas fa-compress-arrows-alt fa-fw"></i> ' + t('playbook.copyRoutesGrouped') + '</a>');
            $('body').append($menu);
        })();

        function closeCopyMenu() { $('#pb_copy_menu').removeClass('open'); }

        $(document).on('click', '#pb_copy_menu_trigger', function(e) {
            e.stopPropagation();
            var $menu = $('#pb_copy_menu');
            if ($menu.hasClass('open')) { closeCopyMenu(); return; }
            var rect = this.getBoundingClientRect();
            // Position above button if near bottom, else below
            var menuH = $menu.outerHeight() || 140;
            var spaceBelow = window.innerHeight - rect.bottom;
            var top = spaceBelow > menuH + 8 ? rect.bottom + 4 : rect.top - menuH - 4;
            $menu.css({
                top: top + 'px',
                right: (window.innerWidth - rect.right) + 'px'
            }).addClass('open');
        });

        $(document).on('click', function(e) {
            if (!$(e.target).closest('#pb_copy_menu, #pb_copy_menu_trigger').length) closeCopyMenu();
        });

        $(document).on('click', '#pb_copy_menu a', function(e) {
            e.preventDefault();
            var mode = $(this).data('copy');
            var text = '';

            if (mode === 'pb') {
                if (routeGroups.length > 0) {
                    var selected = getSelectedRoutes();
                    if (!selected.length) { closeCopyMenu(); return; }
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
                    text = buildCurrentPBDirective();
                }
            } else if (mode === 'full') {
                var selected = getSelectedRoutes();
                if (!selected.length) { closeCopyMenu(); return; }
                var hasGroups = routeGroups.length > 0;
                text = selected.map(function(r) {
                    var parts = [];
                    if (r.origin) parts.push(r.origin);
                    parts.push(r.route_string);
                    if (r.dest) parts.push(r.dest);
                    var line = parts.join(' ');
                    if (hasGroups) {
                        var color = getRouteGroupColor(r.route_id);
                        if (color) line += ';' + color;
                    }
                    return line;
                }).join('\n');
            } else if (mode === 'route_only') {
                var selected = getSelectedRoutes();
                if (!selected.length) { closeCopyMenu(); return; }
                var seen = new Set();
                var lines = [];
                selected.forEach(function(r) {
                    var key = (r.route_string || '').trim().toUpperCase();
                    if (!seen.has(key)) {
                        seen.add(key);
                        lines.push(r.route_string);
                    }
                });
                text = lines.join('\n');
            } else if (mode === 'grouped') {
                var selected = getSelectedRoutes();
                if (!selected.length) { closeCopyMenu(); return; }
                var groups = consolidateRoutes(selected);
                text = Object.keys(groups).map(function(key) {
                    var g = groups[key];
                    var origStr = Array.from(g.origins).sort().join('/');
                    var destStr = Array.from(g.dests).sort().join('/');
                    var parts = [];
                    if (origStr) parts.push(origStr);
                    parts.push(g.route_string);
                    if (destStr) parts.push(destStr);
                    return parts.join(' ');
                }).join('\n');
            }

            closeCopyMenu();
            if (!text) return;
            navigator.clipboard.writeText(text).then(function() {
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
            $('#pb_advisory_parse_area').slideUp(150);
            $('#pb_bulk_paste_area').slideToggle(150);
        });
        $('#pb_bulk_paste_apply').on('click', applyBulkPaste);

        // Auto-filter detection
        $('#pb_auto_filters_btn').on('click', autoDetectEditFilters);

        // Advisory parse toggle
        $('#pb_parse_advisory_btn').on('click', function() {
            $('#pb_bulk_paste_area').slideUp(150);
            $('#pb_advisory_parse_area').slideToggle(150);
        });
        $('#pb_advisory_parse_apply').on('click', applyAdvisoryParse);

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

        // ── Playbook export buttons ──
        $(document).on('click', '#pb_export_geojson', exportPlaybookGeoJSON);
        $(document).on('click', '#pb_export_kml', exportPlaybookKML);
        $(document).on('click', '#pb_export_csv', exportPlaybookCSV);

        // ── Route analysis panel: click route row to load analysis ──
        $(document).on('click', '.pb-route-table tbody tr, .pb-compact-table tbody tr', function(e) {
            // Skip if clicking a checkbox
            if ($(e.target).is('input[type="checkbox"]') || $(e.target).closest('.pb-route-check').length) return;
            var rid = parseInt($(this).attr('data-route-id'));
            if (!rid || isNaN(rid)) {
                // Consolidated/compact views use data-route-ids (comma-separated)
                var ids = $(this).attr('data-route-ids');
                if (ids) rid = parseInt(ids.split(',')[0]);
            }
            if (!rid || isNaN(rid)) return;
            // Highlight the clicked row
            $('.pb-route-table tbody tr, .pb-compact-table tbody tr').removeClass('pb-route-analysis-active');
            if (activeAnalysisRouteId !== rid) {
                $(this).addClass('pb-route-analysis-active');
            }
            // Look up route data for the shared module
            var route = (activePlayData && activePlayData.routes || []).find(function(r) { return r.route_id === rid; });
            var routeStr = route ? (route.route_string || '') : '';
            var origin = route ? (route.origin || '') : '';
            var dest = route ? (route.dest || '') : '';
            window.showRouteAnalysis(rid, routeStr, origin, dest, route);
        });

        // ── Section toggle (map / detail) ──
        $(document).on('click', '#pb_toggle_map', function() {
            $(this).toggleClass('active');
            var show = $(this).hasClass('active');
            $('#pb_map_section').toggleClass('pb-collapsed', !show);
            // Resize map when re-shown so tiles render correctly
            if (show && window.graphic_map) {
                setTimeout(function() { window.graphic_map.resize(); }, 350);
            }
        });
        $(document).on('click', '#pb_toggle_routes', function() {
            $(this).toggleClass('active');
            var show = $(this).hasClass('active');
            $('#pb_detail_section').toggleClass('pb-collapsed', !show);
        });

        // ── Throughput toggle ──
        $(document).on('click', '#btn-throughput-toggle', function() {
            toggleThroughput();
        });

        // ── Changelog tab ──
        $(document).on('click', '#pb_changelog_tab_toggle', function() {
            var panel = $('#play-changelog-panel');
            var $this = $(this);
            if (panel.is(':visible')) {
                panel.slideUp(150);
                $this.removeClass('expanded');
            } else {
                $this.addClass('expanded');
                if (activePlayId) {
                    loadPlayChangelog(activePlayId);
                }
            }
        });

        // Re-plot when route selection changes
        $(document).on('change', '.pb-route-cb, .pb-route-cb-group, #pb_check_all', function() {
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
                } else if (field === 'common_fix') {
                    autoGroupByCommonFix();
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

        // Route Tools dropdown option click
        $(document).on('click', '.pb-route-tool-opt', function() {
            var tool = $(this).data('tool');
            $('.pb-cb-dropdown').removeClass('open');
            if (tool === 'consolidate') {
                routeViewMode = (routeViewMode === 'consolidated') ? 'standard' : 'consolidated';
                if (activePlayData) renderRouteTable(activePlayData, activePlayData.routes || []);
            } else if (tool === 'compact') {
                routeViewMode = (routeViewMode === 'compact') ? 'standard' : 'compact';
                if (activePlayData) renderRouteTable(activePlayData, activePlayData.routes || []);
            }
        });

        // View toggle buttons
        $(document).on('click', '#pb_view_toggle button', function() {
            var mode = $(this).data('view');
            if (mode === routeViewMode) return;
            routeViewMode = mode;
            if (activePlayData) renderRouteTable(activePlayData, activePlayData.routes || []);
        });

        // Consolidated view: group checkbox selects/deselects all constituent routes
        $(document).on('change', '.pb-route-cb-group', function() {
            var ids = $(this).data('ids').toString().split(',').map(Number);
            var checked = this.checked;
            ids.forEach(function(rid) {
                if (checked) selectedRouteIds.add(rid);
                else selectedRouteIds.delete(rid);
            });
            updateToolbarVisibility();
            plotOnMap();
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

        // ── Visibility & ACL event handlers ──
        $('#pb_edit_visibility').on('change', function() {
            updateVisibilityUI($(this).val());
            var playId = parseInt($('#pb_edit_play_id').val()) || 0;
            if (playId && ($(this).val() === 'private_users' || $(this).val() === 'private_org')) {
                loadAclList(playId);
            }
        });

        // User search input (debounced)
        $(document).on('input', '#pb_acl_search', function() {
            aclUserSearch($(this).val().trim());
        });

        // Close search dropdown on blur (with delay for click events)
        $(document).on('blur', '#pb_acl_search', function() {
            setTimeout(function() { $('#pb_acl_search_results').hide(); }, 200);
        });

        // Click search result to add user
        $(document).on('click', '.pb-acl-search-item:not(.acl-already-added)', function() {
            var cid = parseInt($(this).data('cid'));
            if (!cid) return;
            aclAction('add', { cid: cid });
            $('#pb_acl_search').val('');
            $('#pb_acl_search_results').hide();
        });

        // Org chip toggle
        $(document).on('click', '.pb-acl-org-chip', function() {
            var orgCode = $(this).data('org');
            if (aclSelectedOrgs.has(orgCode)) {
                aclSelectedOrgs.delete(orgCode);
                $(this).removeClass('active');
            } else {
                aclSelectedOrgs.add(orgCode);
                $(this).addClass('active');
            }
            loadOrgMembers();
        });

        // Org member exclude checkbox
        $(document).on('change', '.pb-acl-org-exclude', function() {
            var cid = parseInt($(this).data('cid'));
            if (this.checked) {
                aclExcludedCids.add(cid);
                $(this).closest('.pb-acl-org-member-row').addClass('excluded');
            } else {
                aclExcludedCids.delete(cid);
                $(this).closest('.pb-acl-org-member-row').removeClass('excluded');
            }
        });

        // Add all org members (minus excluded) to ACL
        $(document).on('click', '#pb_acl_org_add_all', function() {
            var cids = [];
            $('.pb-acl-org-member-row').each(function() {
                var cid = parseInt($(this).data('member-cid'));
                if (!cid) return;
                if (aclExcludedCids.has(cid)) return;
                // Skip if already in ACL
                if ($(this).find('.badge-secondary').length) return;
                cids.push(cid);
            });
            if (!cids.length) {
                PERTIDialog.toast(t('playbook.acl.noUsersToAdd'), 'info');
                return;
            }
            aclAction('bulk_add', { cids: cids });
        });

        $(document).on('click', '#pb_acl_bulk_btn', function() {
            $('#pb_acl_bulk_area').slideToggle(150);
        });

        $(document).on('click', '#pb_acl_bulk_apply', function() {
            var raw = $('#pb_acl_bulk_input').val().trim();
            if (!raw) return;
            var cids = raw.split(/[,\s]+/).map(function(s) { return parseInt(s); }).filter(function(n) { return n > 0; });
            if (!cids.length) {
                PERTIDialog.toast(t('playbook.acl.invalidCid'), 'warning');
                return;
            }
            aclAction('bulk_add', { cids: cids });
            $('#pb_acl_bulk_input').val('');
            $('#pb_acl_bulk_area').slideUp(150);
        });

        $(document).on('click', '.pb-acl-remove', function() {
            var cid = parseInt($(this).closest('tr').data('acl-cid'));
            if (!cid) return;
            aclAction('remove', { cid: cid });
        });

        $(document).on('change', '.pb-acl-perm', function() {
            var $tr = $(this).closest('tr');
            var cid = parseInt($tr.data('acl-cid'));
            var perm = $(this).data('perm');
            var val = this.checked ? 1 : 0;
            var data = { cid: cid };
            data[perm] = val;
            aclAction('update', data);
        });
    });

})();
