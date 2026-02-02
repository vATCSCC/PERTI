/**
 * playbook-cdr-search.js
 *
 * Playbook & CDR Search Module for PERTI Route Planning
 * Provides search, filter, and display functionality for Playbook routes and CDRs
 *
 * Dependencies:
 * - jQuery
 * - cdrMap (loaded by route.js / route-maplibre.js)
 * - playbookRoutes (loaded by route.js / route-maplibre.js)
 */

const PlaybookCDRSearch = (function() {
    'use strict';

    // ═══════════════════════════════════════════════════════════════════════════
    // MODULE STATE
    // ═══════════════════════════════════════════════════════════════════════════

    let initialized = false;
    let searchType = 'playbook'; // 'playbook', 'cdr', or 'all'
    let currentResults = [];
    const selectedIndices = new Set();
    const MAX_RESULTS = 200;

    // Local copies of data (populated from global scope)
    let localCdrMap = {};
    let localCdrList = []; // Array form for searching
    let localPlaybookRoutes = [];
    let localPlaybookByPlayName = {};

    // ═══════════════════════════════════════════════════════════════════════════
    // INITIALIZATION
    // ═══════════════════════════════════════════════════════════════════════════

    function init() {
        if (initialized) {return;}

        console.log('[PBCDR] Initializing Playbook/CDR Search module...');

        // Try to get data from global scope (loaded by route.js)
        loadDataFromGlobalScope();

        // Bind event handlers
        bindEvents();

        initialized = true;
        console.log('[PBCDR] Module initialized. Playbooks: ' + localPlaybookRoutes.length + ', CDRs: ' + localCdrList.length);
    }

    function loadDataFromGlobalScope() {
        // Wait for data to be available (route.js loads this)
        const checkInterval = setInterval(function() {
            let hasData = false;

            // Check for cdrMap
            if (typeof window.cdrMap !== 'undefined' && Object.keys(window.cdrMap).length > 0) {
                localCdrMap = window.cdrMap;
                // Convert to array for searching
                localCdrList = Object.entries(localCdrMap).map(function(entry) {
                    return {
                        code: entry[0],
                        route: entry[1],
                        type: 'cdr',
                    };
                });
                hasData = true;
            }

            // Check for playbookRoutes
            if (typeof window.playbookRoutes !== 'undefined' && window.playbookRoutes.length > 0) {
                localPlaybookRoutes = window.playbookRoutes;
                hasData = true;
            }

            // Check for playbookByPlayName index
            if (typeof window.playbookByPlayName !== 'undefined') {
                localPlaybookByPlayName = window.playbookByPlayName;
            }

            if (hasData || localCdrList.length > 0 || localPlaybookRoutes.length > 0) {
                clearInterval(checkInterval);
                console.log('[PBCDR] Data loaded from global scope');
            }
        }, 100);

        // Timeout after 10 seconds
        setTimeout(function() {
            clearInterval(checkInterval);
            if (localCdrList.length === 0 && localPlaybookRoutes.length === 0) {
                console.warn('[PBCDR] Timeout waiting for route data. Search may not work.');
            }
        }, 10000);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // EVENT BINDING
    // ═══════════════════════════════════════════════════════════════════════════

    function bindEvents() {
        // Search button
        $('#pbcdr_search_btn').off('click').on('click', performSearch);

        // Enter key in search fields
        $('#pbcdr_search_panel input').off('keypress').on('keypress', function(e) {
            if (e.which === 13) {
                performSearch();
            }
        });

        // Clear filters
        $('#pbcdr_clear_filters').off('click').on('click', clearFilters);

        // Select all checkbox
        $('#pbcdr_select_all').off('change').on('change', function() {
            const checked = $(this).prop('checked');
            selectAll(checked);
        });

        // Add selected to textarea
        $('#pbcdr_add_selected').off('click').on('click', function() {
            addSelectedToTextarea();
        });

        // Plot selected
        $('#pbcdr_plot_selected').off('click').on('click', function() {
            plotSelected();
        });

        // Copy selected
        $('#pbcdr_copy_selected').off('click').on('click', function() {
            copySelectedToClipboard();
        });

        // Clear routes textarea
        $('#pbcdr_clear_routes').off('click').on('click', function() {
            clearRoutesTextarea();
        });

        // Result item click (delegate)
        $('#pbcdr_results_list').off('click', '.pbcdr-result-item').on('click', '.pbcdr-result-item', function(e) {
            // Don't toggle if clicking on action button
            if ($(e.target).closest('.pbcdr-action-btn').length) {return;}

            const idx = $(this).data('idx');
            toggleSelection(idx);
        });

        // Quick add button
        $('#pbcdr_results_list').off('click', '.pbcdr-quick-add').on('click', '.pbcdr-quick-add', function(e) {
            e.stopPropagation();
            const idx = $(this).closest('.pbcdr-result-item').data('idx');
            quickAddRoute(idx);
        });

        // Quick plot button
        $('#pbcdr_results_list').off('click', '.pbcdr-quick-plot').on('click', '.pbcdr-quick-plot', function(e) {
            e.stopPropagation();
            const idx = $(this).closest('.pbcdr-result-item').data('idx');
            quickPlotRoute(idx);
        });
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // SEARCH FUNCTIONALITY
    // ═══════════════════════════════════════════════════════════════════════════

    function setSearchType(type) {
        searchType = type;
    }

    function getFilterValues() {
        return {
            name: $('#pbcdr_name').val().trim().toUpperCase(),
            routeText: $('#pbcdr_route_text').val().trim().toUpperCase(),
            origApt: $('#pbcdr_orig_apt').val().trim().toUpperCase(),
            origTracon: $('#pbcdr_orig_tracon').val().trim().toUpperCase(),
            origArtcc: $('#pbcdr_orig_artcc').val().trim().toUpperCase(),
            destApt: $('#pbcdr_dest_apt').val().trim().toUpperCase(),
            destTracon: $('#pbcdr_dest_tracon').val().trim().toUpperCase(),
            destArtcc: $('#pbcdr_dest_artcc').val().trim().toUpperCase(),
        };
    }

    function performSearch() {
        const filters = getFilterValues();
        let results = [];
        const startTime = performance.now();

        // Check if any filter is set
        const hasFilter = Object.values(filters).some(function(v) { return v !== ''; });
        if (!hasFilter) {
            showNoResults('Enter at least one search criterion');
            return;
        }

        // Search playbooks
        if (searchType === 'playbook' || searchType === 'all') {
            const pbResults = searchPlaybooks(filters);
            results = results.concat(pbResults);
        }

        // Search CDRs
        if (searchType === 'cdr' || searchType === 'all') {
            const cdrResults = searchCDRs(filters);
            results = results.concat(cdrResults);
        }

        // Sort results
        results.sort(function(a, b) {
            // Sort by type first (playbook before CDR), then by name
            if (a.type !== b.type) {
                return a.type === 'playbook' ? -1 : 1;
            }
            return (a.displayName || '').localeCompare(b.displayName || '');
        });

        // Limit results
        const limited = results.length > MAX_RESULTS;
        if (limited) {
            results = results.slice(0, MAX_RESULTS);
        }

        currentResults = results;
        selectedIndices.clear();

        const elapsed = (performance.now() - startTime).toFixed(1);
        console.log('[PBCDR] Search completed: ' + results.length + ' results in ' + elapsed + 'ms');

        renderResults(results, limited);
        updateBulkActionState();
    }

    function searchPlaybooks(filters) {
        const results = [];

        // Optimization: Use play name index if only searching by name
        let candidateRoutes;
        if (filters.name && !filters.routeText && !filters.origApt && !filters.origTracon &&
            !filters.origArtcc && !filters.destApt && !filters.destTracon && !filters.destArtcc) {
            // Search by play name prefix in index
            candidateRoutes = [];
            const normName = normalizePlayName(filters.name);
            for (const key in localPlaybookByPlayName) {
                if (key.indexOf(normName) !== -1 || normName.indexOf(key) !== -1 ||
                    key.startsWith(normName) || normName.startsWith(key)) {
                    candidateRoutes = candidateRoutes.concat(localPlaybookByPlayName[key]);
                }
            }
        } else {
            candidateRoutes = localPlaybookRoutes;
        }

        for (let i = 0; i < candidateRoutes.length && results.length < MAX_RESULTS * 2; i++) {
            const pb = candidateRoutes[i];

            // Name filter
            if (filters.name) {
                const nameMatch = (pb.playName && pb.playName.toUpperCase().indexOf(filters.name) !== -1) ||
                               (pb.playNameNorm && pb.playNameNorm.indexOf(normalizePlayName(filters.name)) !== -1);
                if (!nameMatch) {continue;}
            }

            // Route text filter
            if (filters.routeText && pb.fullRoute.indexOf(filters.routeText) === -1) {
                continue;
            }

            // Origin airport filter
            if (filters.origApt) {
                const origAptNorm = normalizeAirportCode(filters.origApt);
                if (!pb.originAirportsSet || !matchesAnyToken(origAptNorm, pb.originAirportsSet)) {
                    continue;
                }
            }

            // Origin TRACON filter
            if (filters.origTracon) {
                if (!pb.originTraconsSet || !matchesAnyToken(filters.origTracon, pb.originTraconsSet)) {
                    continue;
                }
            }

            // Origin ARTCC filter
            if (filters.origArtcc) {
                if (!pb.originArtccsSet || !matchesAnyToken(filters.origArtcc, pb.originArtccsSet)) {
                    continue;
                }
            }

            // Dest airport filter
            if (filters.destApt) {
                const destAptNorm = normalizeAirportCode(filters.destApt);
                if (!pb.destAirportsSet || !matchesAnyToken(destAptNorm, pb.destAirportsSet)) {
                    continue;
                }
            }

            // Dest TRACON filter
            if (filters.destTracon) {
                if (!pb.destTraconsSet || !matchesAnyToken(filters.destTracon, pb.destTraconsSet)) {
                    continue;
                }
            }

            // Dest ARTCC filter
            if (filters.destArtcc) {
                if (!pb.destArtccsSet || !matchesAnyToken(filters.destArtcc, pb.destArtccsSet)) {
                    continue;
                }
            }

            // Build PB directive with applicable filters
            const pbDirective = buildPBDirective(pb.playName, filters);

            // Add to results
            results.push({
                type: 'playbook',
                displayName: pb.playName,
                route: pb.fullRoute,
                origAirports: pb.originAirports || [],
                origTracons: pb.originTracons || [],
                origArtccs: pb.originArtccs || [],
                destAirports: pb.destAirports || [],
                destTracons: pb.destTracons || [],
                destArtccs: pb.destArtccs || [],
                pbDirective: pbDirective,
                // Store filter context for potential re-building
                filterContext: {
                    origApt: filters.origApt,
                    origTracon: filters.origTracon,
                    origArtcc: filters.origArtcc,
                    destApt: filters.destApt,
                    destTracon: filters.destTracon,
                    destArtcc: filters.destArtcc,
                },
            });
        }

        return results;
    }

    /**
     * Build a PB directive string with applicable filters
     * Format: PB.{playname}.{origins}.{destinations}
     * Multiple values in a segment are space-separated
     */
    function buildPBDirective(playName, filters) {
        const parts = ['PB', playName];

        // Build origin part (include all origin filters)
        const originParts = [];
        if (filters.origApt) {originParts.push(normalizeAirportCode(filters.origApt));}
        if (filters.origTracon) {originParts.push(filters.origTracon.toUpperCase());}
        if (filters.origArtcc) {originParts.push(filters.origArtcc.toUpperCase());}

        // Build destination part (include all dest filters)
        const destParts = [];
        if (filters.destApt) {destParts.push(normalizeAirportCode(filters.destApt));}
        if (filters.destTracon) {destParts.push(filters.destTracon.toUpperCase());}
        if (filters.destArtcc) {destParts.push(filters.destArtcc.toUpperCase());}

        // Build the directive
        // PB.PLAY (no filters)
        // PB.PLAY.ORIG (origin only)
        // PB.PLAY..DEST (dest only)
        // PB.PLAY.ORIG.DEST (both)
        if (originParts.length > 0 || destParts.length > 0) {
            parts.push(originParts.join(' ')); // May be empty for dest-only
            if (destParts.length > 0) {
                parts.push(destParts.join(' '));
            }
        }

        return parts.join('.');
    }

    function searchCDRs(filters) {
        const results = [];

        for (let i = 0; i < localCdrList.length && results.length < MAX_RESULTS * 2; i++) {
            const cdr = localCdrList[i];

            // Name filter (CDR code)
            if (filters.name && cdr.code.indexOf(filters.name) === -1) {
                continue;
            }

            // Route text filter
            if (filters.routeText && cdr.route.toUpperCase().indexOf(filters.routeText) === -1) {
                continue;
            }

            // Extract origin/dest from CDR code (format: XXXYYY# where XXX=orig, YYY=dest)
            let cdrOrigin = '';
            let cdrDest = '';
            if (cdr.code.length >= 6) {
                cdrOrigin = cdr.code.substring(0, 3);
                cdrDest = cdr.code.substring(3, 6);
            }

            // Also try to extract from route string (usually starts with KXXX and ends with KYYY)
            const routeTokens = cdr.route.split(/\s+/);
            let routeOrigin = '';
            let routeDest = '';
            if (routeTokens.length > 0 && routeTokens[0].match(/^K[A-Z]{3}$/)) {
                routeOrigin = routeTokens[0]; // Keep K prefix for matching
            }
            if (routeTokens.length > 1) {
                const lastToken = routeTokens[routeTokens.length - 1];
                if (lastToken.match(/^K[A-Z]{3}$/)) {
                    routeDest = lastToken;
                }
            }

            // Origin airport filter - DIRECTIONAL: must match ORIGIN only
            if (filters.origApt) {
                const origSearch = filters.origApt.replace(/^K/, ''); // Remove K if present
                let origMatch = false;

                // Check CDR code origin (first 3 chars)
                if (cdrOrigin && cdrOrigin.toUpperCase() === origSearch.toUpperCase()) {
                    origMatch = true;
                }
                // Check route string origin
                if (!origMatch && routeOrigin) {
                    const routeOrigCode = routeOrigin.replace(/^K/, '');
                    if (routeOrigCode.toUpperCase() === origSearch.toUpperCase()) {
                        origMatch = true;
                    }
                }

                if (!origMatch) {continue;}
            }

            // Origin TRACON filter - check if route passes through
            if (filters.origTracon && cdr.route.indexOf(filters.origTracon) === -1) {
                continue;
            }

            // Origin ARTCC filter - check if route passes through
            if (filters.origArtcc && cdr.route.indexOf(filters.origArtcc) === -1) {
                continue;
            }

            // Dest airport filter - DIRECTIONAL: must match DESTINATION only
            if (filters.destApt) {
                const destSearch = filters.destApt.replace(/^K/, ''); // Remove K if present
                let destMatch = false;

                // Check CDR code destination (chars 4-6)
                if (cdrDest && cdrDest.toUpperCase() === destSearch.toUpperCase()) {
                    destMatch = true;
                }
                // Check route string destination
                if (!destMatch && routeDest) {
                    const routeDestCode = routeDest.replace(/^K/, '');
                    if (routeDestCode.toUpperCase() === destSearch.toUpperCase()) {
                        destMatch = true;
                    }
                }

                if (!destMatch) {continue;}
            }

            // Dest TRACON filter - check if route passes through
            if (filters.destTracon && cdr.route.indexOf(filters.destTracon) === -1) {
                continue;
            }

            // Dest ARTCC filter - check if route passes through
            if (filters.destArtcc && cdr.route.indexOf(filters.destArtcc) === -1) {
                continue;
            }

            results.push({
                type: 'cdr',
                displayName: cdr.code,
                route: cdr.route,
                origAirports: cdrOrigin ? [cdrOrigin] : [],
                destAirports: cdrDest ? [cdrDest] : [],
                cdrCode: cdr.code,
            });
        }

        return results;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // HELPER FUNCTIONS
    // ═══════════════════════════════════════════════════════════════════════════

    function normalizePlayName(name) {
        if (!name) {return '';}
        return String(name).toUpperCase().replace(/[\s\-_]/g, '');
    }

    function normalizeAirportCode(code) {
        // Use FacilityHierarchy.normalizeIcao if available (handles Canada, Alaska, Hawaii, etc.)
        if (typeof FacilityHierarchy !== 'undefined' && FacilityHierarchy.normalizeIcao) {
            return FacilityHierarchy.normalizeIcao(code);
        }
        // Fallback: simple K-prefix for 3-letter codes
        if (!code) {return '';}
        code = code.toUpperCase().trim();
        if (code.length === 3 && /^[A-Z]{3}$/.test(code)) {
            return 'K' + code;
        }
        return code;
    }

    function matchesAnyToken(searchTerm, tokenSet) {
        if (!tokenSet || tokenSet.size === 0) {return false;}

        // Direct match
        if (tokenSet.has(searchTerm)) {return true;}

        // Partial match - check if any token contains or starts with search term
        const arr = Array.from(tokenSet);
        for (let i = 0; i < arr.length; i++) {
            if (arr[i].indexOf(searchTerm) !== -1 || searchTerm.indexOf(arr[i]) !== -1) {
                return true;
            }
        }
        return false;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // RENDERING
    // ═══════════════════════════════════════════════════════════════════════════

    function renderResults(results, limited) {
        const $container = $('#pbcdr_results_list');
        $container.empty();

        if (results.length === 0) {
            showNoResults('No matching routes found');
            return;
        }

        // Update count
        $('#pbcdr_results_shown').text(results.length);
        if (limited) {
            $('#pbcdr_results_limited').show();
            $('#pbcdr_results_limit').text(MAX_RESULTS);
        } else {
            $('#pbcdr_results_limited').hide();
        }

        // Render each result
        for (let i = 0; i < results.length; i++) {
            const r = results[i];
            const html = renderResultItem(r, i);
            $container.append(html);
        }
    }

    function renderResultItem(result, idx) {
        const typeClass = result.type === 'playbook' ? 'playbook' : 'cdr';
        const typeLabel = result.type === 'playbook' ? 'Playbook' : 'CDR';

        // Build metadata badges
        let metaHtml = '';
        if (result.origAirports && result.origAirports.length > 0) {
            metaHtml += '<span title="Origin Airports">' + result.origAirports.slice(0, 3).join(' ') +
                       (result.origAirports.length > 3 ? '...' : '') + '</span>';
        }
        if (result.origArtccs && result.origArtccs.length > 0) {
            metaHtml += '<span title="Origin ARTCCs">' + result.origArtccs.slice(0, 2).join(' ') + '</span>';
        }
        if (result.destAirports && result.destAirports.length > 0) {
            metaHtml += '<span title="Dest Airports">→ ' + result.destAirports.slice(0, 3).join(' ') +
                       (result.destAirports.length > 3 ? '...' : '') + '</span>';
        }
        if (result.destArtccs && result.destArtccs.length > 0) {
            metaHtml += '<span title="Dest ARTCCs">→ ' + result.destArtccs.slice(0, 2).join(' ') + '</span>';
        }

        return '<div class="pbcdr-result-item" data-idx="' + idx + '">' +
            '<div class="pbcdr-result-header">' +
                '<div class="d-flex align-items-center">' +
                    '<input type="checkbox" class="mr-2 pbcdr-result-checkbox" data-idx="' + idx + '">' +
                    '<span class="pbcdr-result-name">' + escapeHtml(result.displayName) + '</span>' +
                '</div>' +
                '<div class="d-flex align-items-center">' +
                    '<span class="pbcdr-result-type ' + typeClass + '">' + typeLabel + '</span>' +
                    '<div class="pbcdr-result-actions ml-2">' +
                        '<button class="btn btn-outline-primary pbcdr-action-btn pbcdr-quick-add" title="Add to textarea">' +
                            '<i class="fas fa-plus"></i>' +
                        '</button>' +
                        '<button class="btn btn-outline-success pbcdr-action-btn pbcdr-quick-plot ml-1" title="Plot route">' +
                            '<i class="fas fa-pencil-alt"></i>' +
                        '</button>' +
                    '</div>' +
                '</div>' +
            '</div>' +
            '<div class="pbcdr-result-route">' + escapeHtml(result.route) + '</div>' +
            (metaHtml ? '<div class="pbcdr-result-meta">' + metaHtml + '</div>' : '') +
        '</div>';
    }

    function showNoResults(message) {
        $('#pbcdr_results_list').html(
            '<div class="pbcdr-no-results">' +
                '<i class="fas fa-search d-block"></i>' +
                '<p class="mb-0">' + escapeHtml(message) + '</p>' +
            '</div>',
        );
        $('#pbcdr_results_shown').text('0');
        $('#pbcdr_results_limited').hide();
    }

    function escapeHtml(str) {
        if (!str) {return '';}
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // SELECTION & ACTIONS
    // ═══════════════════════════════════════════════════════════════════════════

    function toggleSelection(idx) {
        if (selectedIndices.has(idx)) {
            selectedIndices.delete(idx);
        } else {
            selectedIndices.add(idx);
        }
        updateSelectionUI();
        updateBulkActionState();
    }

    function selectAll(checked) {
        selectedIndices.clear();
        if (checked) {
            for (let i = 0; i < currentResults.length; i++) {
                selectedIndices.add(i);
            }
        }
        updateSelectionUI();
        updateBulkActionState();
    }

    function updateSelectionUI() {
        $('.pbcdr-result-checkbox').each(function() {
            const idx = $(this).data('idx');
            $(this).prop('checked', selectedIndices.has(idx));
        });

        // Update select all checkbox
        const allSelected = selectedIndices.size === currentResults.length && currentResults.length > 0;
        $('#pbcdr_select_all').prop('checked', allSelected);
    }

    function updateBulkActionState() {
        const hasSelection = selectedIndices.size > 0;
        $('#pbcdr_add_selected').prop('disabled', !hasSelection);
        $('#pbcdr_plot_selected').prop('disabled', !hasSelection);
        $('#pbcdr_copy_selected').prop('disabled', !hasSelection);
    }

    function buildPlaybookDirective(result) {
        // Build PB directive with applicable filters: PB.{play}.{origins}.{dests}
        // Use the search filters that were active when the result was found
        const filters = getFilterValues();
        const parts = ['PB', result.displayName];

        // Build origin part from filters (normalize airport codes)
        const originParts = [];
        if (filters.origApt) {originParts.push(normalizeAirportCode(filters.origApt));}
        if (filters.origTracon) {originParts.push(filters.origTracon.toUpperCase());}
        if (filters.origArtcc) {originParts.push(filters.origArtcc.toUpperCase());}

        // Build dest part from filters (normalize airport codes)
        const destParts = [];
        if (filters.destApt) {destParts.push(normalizeAirportCode(filters.destApt));}
        if (filters.destTracon) {destParts.push(filters.destTracon.toUpperCase());}
        if (filters.destArtcc) {destParts.push(filters.destArtcc.toUpperCase());}

        // Add origin segment if any origin filters
        if (originParts.length > 0) {
            parts.push(originParts.join(' '));
        } else if (destParts.length > 0) {
            // Need empty origin segment if we have dest
            parts.push('');
        }

        // Add dest segment if any dest filters
        if (destParts.length > 0) {
            parts.push(destParts.join(' '));
        }

        return parts.join('.');
    }

    function getSelectedRouteStrings() {
        const routes = [];
        selectedIndices.forEach(function(idx) {
            const result = currentResults[idx];
            if (result) {
                // For playbooks, build PB directive with filters; for CDRs, use code
                if (result.type === 'playbook') {
                    routes.push(buildPlaybookDirective(result));
                } else if (result.type === 'cdr' && result.cdrCode) {
                    routes.push(result.cdrCode);
                } else {
                    routes.push(result.route);
                }
            }
        });
        return routes;
    }

    function getExistingRoutes() {
        // Get current routes in textarea as a Set for deduplication
        const $textarea = $('#routeSearch');
        const currentVal = $textarea.val().trim();
        if (!currentVal) {return new Set();}

        const lines = currentVal.split('\n').map(function(line) {
            return line.trim().toUpperCase();
        }).filter(function(line) {
            return line !== '';
        });

        return new Set(lines);
    }

    function addRoutesToTextarea(routes, showFeedback) {
        if (!routes || routes.length === 0) {return 0;}

        const $textarea = $('#routeSearch');
        const existingRoutes = getExistingRoutes();

        // Filter out duplicates - both against existing AND within the batch
        const seen = new Set();
        const newRoutes = [];

        for (let i = 0; i < routes.length; i++) {
            const route = routes[i];
            const normalized = route.trim().toUpperCase();

            // Skip if empty, already in textarea, or already seen in this batch
            if (!normalized || existingRoutes.has(normalized) || seen.has(normalized)) {
                continue;
            }

            seen.add(normalized);
            newRoutes.push(route);
        }

        if (newRoutes.length === 0) {
            if (showFeedback) {
                showToast('All routes already in textarea', 'info');
            }
            return 0;
        }

        const currentVal = $textarea.val().trim();
        const newRoutesStr = newRoutes.join('\n');

        if (currentVal) {
            $textarea.val(currentVal + '\n' + newRoutesStr);
        } else {
            $textarea.val(newRoutesStr);
        }

        const skipped = routes.length - newRoutes.length;
        if (showFeedback) {
            let msg = 'Added ' + newRoutes.length + ' route(s)';
            if (skipped > 0) {
                msg += ' (' + skipped + ' duplicate' + (skipped > 1 ? 's' : '') + ' skipped)';
            }
            showToast(msg, 'success');
        }

        return newRoutes.length;
    }

    function getSelectedFullRouteStrings() {
        const routes = [];
        selectedIndices.forEach(function(idx) {
            const result = currentResults[idx];
            if (result) {
                routes.push(result.route);
            }
        });
        return routes;
    }

    function addSelectedToTextarea() {
        const routes = getSelectedRouteStrings();
        addRoutesToTextarea(routes, true);
    }

    function plotSelected() {
        const routes = getSelectedRouteStrings();
        if (routes.length === 0) {return;}

        const added = addRoutesToTextarea(routes, false);

        // Trigger plot button
        $('#plot_r').click();

        if (added > 0) {
            showToast('Plotting ' + added + ' route(s)', 'success');
        } else {
            showToast('Routes already plotted', 'info');
        }
    }

    function copySelectedToClipboard() {
        const routes = getSelectedFullRouteStrings();
        if (routes.length === 0) {return;}

        const text = routes.join('\n');

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function() {
                showToast('Copied ' + routes.length + ' route(s) to clipboard', 'success');
            }).catch(function() {
                fallbackCopy(text);
            });
        } else {
            fallbackCopy(text);
        }
    }

    function fallbackCopy(text) {
        const $temp = $('<textarea>');
        $('body').append($temp);
        $temp.val(text).select();
        document.execCommand('copy');
        $temp.remove();
        showToast('Copied to clipboard', 'success');
    }

    function quickAddRoute(idx) {
        const result = currentResults[idx];
        if (!result) {return;}

        let routeStr;
        if (result.type === 'playbook') {
            routeStr = buildPlaybookDirective(result);
        } else if (result.type === 'cdr') {
            routeStr = result.cdrCode;
        } else {
            routeStr = result.route;
        }

        const added = addRoutesToTextarea([routeStr], false);

        if (added > 0) {
            showToast('Added: ' + result.displayName, 'success');
        } else {
            showToast('Already in textarea: ' + result.displayName, 'info');
        }
    }

    function quickPlotRoute(idx) {
        const result = currentResults[idx];
        if (!result) {return;}

        let routeStr;
        if (result.type === 'playbook') {
            routeStr = buildPlaybookDirective(result);
        } else if (result.type === 'cdr') {
            routeStr = result.cdrCode;
        } else {
            routeStr = result.route;
        }

        addRoutesToTextarea([routeStr], false);

        // Trigger plot
        $('#plot_r').click();

        showToast('Plotting: ' + result.displayName, 'success');
    }

    function clearRoutesTextarea() {
        $('#routeSearch').val('');
        showToast('Routes cleared', 'success');
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // UI HELPERS
    // ═══════════════════════════════════════════════════════════════════════════

    function clearFilters() {
        $('#pbcdr_name').val('');
        $('#pbcdr_route_text').val('');
        $('#pbcdr_orig_apt').val('');
        $('#pbcdr_orig_tracon').val('');
        $('#pbcdr_orig_artcc').val('');
        $('#pbcdr_dest_apt').val('');
        $('#pbcdr_dest_tracon').val('');
        $('#pbcdr_dest_artcc').val('');

        currentResults = [];
        selectedIndices.clear();
        showNoResults('Enter search criteria above');
        updateBulkActionState();
    }

    function showToast(message, type) {
        // Simple toast notification
        const bgColor = type === 'success' ? '#28a745' :
            type === 'error' ? '#dc3545' :
                type === 'info' ? '#17a2b8' : '#6f42c1';

        const $toast = $('<div class="pbcdr-toast">' + escapeHtml(message) + '</div>');
        $toast.css({
            position: 'fixed',
            bottom: '80px',
            left: '50%',
            transform: 'translateX(-50%)',
            background: bgColor,
            color: 'white',
            padding: '8px 16px',
            borderRadius: '4px',
            fontSize: '0.85rem',
            zIndex: 9999,
            boxShadow: '0 2px 8px rgba(0,0,0,0.2)',
            opacity: 0,
            transition: 'opacity 0.2s',
        });

        $('body').append($toast);

        // Animate in
        setTimeout(function() {
            $toast.css('opacity', 1);
        }, 10);

        // Remove after delay
        setTimeout(function() {
            $toast.css('opacity', 0);
            setTimeout(function() {
                $toast.remove();
            }, 200);
        }, 2000);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // PUBLIC API
    // ═══════════════════════════════════════════════════════════════════════════

    return {
        init: init,
        setSearchType: setSearchType,
        search: performSearch,
        clearFilters: clearFilters,
        clearRoutes: clearRoutesTextarea,

        // For external access to data
        getPlaybookRoutes: function() { return localPlaybookRoutes; },
        getCDRList: function() { return localCdrList; },
    };

})();

// Auto-initialize when document is ready
$(document).ready(function() {
    // Delay init slightly to ensure route.js has loaded data
    setTimeout(function() {
        if (typeof PlaybookCDRSearch !== 'undefined') {
            PlaybookCDRSearch.init();
        }
    }, 500);
});
