/**
 * NavData AIRAC Changelog
 * Loads JSON changelogs, provides search/filter/tab functionality.
 */
(function () {
    'use strict';

    var state = {
        changelog: null,      // current changelog JSON
        allChanges: [],       // full changes array
        filtered: [],         // after search/filter/tab
        searchTerm: '',
        activeType: 'all',
        activeAction: 'all',
        activeScope: 'all',   // 'all', 'nasr', 'intl'
        availableCycles: [],
        currentPage: 1,
        pageSize: 100
    };

    // ─── Initialization ───────────────────────────────────────────

    $(document).ready(function () {
        discoverCycles();
        bindEvents();
    });

    var CACHE_BUST = '?_=' + Date.now();

    function discoverCycles() {
        $.getJSON('assets/data/logs/changelog_index.json' + CACHE_BUST)
            .done(function (index) {
                state.availableCycles = index.cycles || [];
                populateCycleSelector();
            })
            .fail(function () {
                tryLoadChangelog('2602', '2603');
            });
    }

    function populateCycleSelector() {
        var $sel = $('#cycle-selector');
        $sel.empty();
        if (state.availableCycles.length === 0) {
            $sel.append('<option value="">No changelogs available</option>');
            showEmpty(PERTII18n.t('navdata.empty.noCycles'));
            return;
        }
        state.availableCycles.forEach(function (c) {
            $sel.append(
                $('<option>').val(c.from + '_' + c.to).text(c.from + ' \u2192 ' + c.to)
            );
        });
        var first = state.availableCycles[0];
        loadChangelog(first.from, first.to);
    }

    function tryLoadChangelog(from, to) {
        var url = 'assets/data/logs/AIRAC_CHANGELOG_' + from + '_' + to + '.json' + CACHE_BUST;
        $.getJSON(url)
            .done(function (data) {
                var $sel = $('#cycle-selector');
                $sel.empty().append(
                    $('<option>').val(from + '_' + to).text(from + ' \u2192 ' + to)
                );
                onChangelogLoaded(data);
            })
            .fail(function () {
                showEmpty(PERTII18n.t('navdata.empty.noData'));
            });
    }

    function loadChangelog(from, to) {
        showLoading();
        var url = 'assets/data/logs/AIRAC_CHANGELOG_' + from + '_' + to + '.json' + CACHE_BUST;
        $.getJSON(url)
            .done(onChangelogLoaded)
            .fail(function () {
                showEmpty(PERTII18n.t('navdata.empty.loadFailed'));
            });
    }

    function onChangelogLoaded(data) {
        state.changelog = data;
        state.allChanges = data.changes || [];
        renderSummaryCards(data);
        renderStats(data);
        updateTabCounts();
        applyFilters();
        hideLoading();
    }

    // ─── Event Bindings ───────────────────────────────────────────

    function bindEvents() {
        // Cycle selector
        $('#cycle-selector').on('change', function () {
            var parts = $(this).val().split('_');
            if (parts.length === 2) loadChangelog(parts[0], parts[1]);
        });

        // Search
        var searchTimer;
        $('#search-input').on('input', function () {
            clearTimeout(searchTimer);
            var val = $(this).val();
            searchTimer = setTimeout(function () {
                state.searchTerm = val.trim().toUpperCase();
                $('#search-clear').toggle(val.length > 0);
                applyFilters();
            }, 200);
        });
        $('#search-clear').on('click', function () {
            $('#search-input').val('');
            state.searchTerm = '';
            $(this).hide();
            applyFilters();
        });

        // Type tabs
        $('#type-tabs').on('click', 'a', function (e) {
            e.preventDefault();
            $('#type-tabs .nav-link').removeClass('active');
            $(this).addClass('active');
            state.activeType = $(this).data('type');
            applyFilters();
        });

        // Action filter
        $('#action-filter').on('click', 'button', function () {
            $('#action-filter .btn').removeClass('active');
            $(this).addClass('active');
            state.activeAction = $(this).data('action');
            applyFilters();
        });

        // Scope filter
        $('#scope-filter').on('click', 'button', function () {
            $('#scope-filter .btn').removeClass('active');
            $(this).addClass('active');
            state.activeScope = $(this).data('scope');
            renderSummaryCards(state.changelog);
            updateTabCounts();
            applyFilters();
        });

        // Pagination
        $('#page-prev').on('click', function () {
            if (state.currentPage > 1) {
                state.currentPage--;
                renderPage();
            }
        });
        $('#page-next').on('click', function () {
            var pages = state.pageSize ? Math.ceil(state.filtered.length / state.pageSize) : 1;
            if (state.currentPage < pages) {
                state.currentPage++;
                renderPage();
            }
        });
        $('#page-size').on('change', function () {
            state.pageSize = parseInt($(this).val(), 10);
            state.currentPage = 1;
            renderPage();
            updateResultCount();
        });

        // Expandable detail rows
        $('#changes-body').on('click', 'tr.change-row', function () {
            var $row = $(this);
            var $detail = $row.next('tr.detail-row');
            if ($row.hasClass('expanded')) {
                $row.removeClass('expanded');
                $detail.remove();
            } else {
                // Collapse any other expanded row
                $('#changes-body tr.change-row.expanded').removeClass('expanded');
                $('#changes-body tr.detail-row').remove();
                $row.addClass('expanded');
                var idx = parseInt($row.data('idx'), 10);
                var change = state.filtered[idx];
                if (change) {
                    var detailHtml = buildDetailPanel(change);
                    $row.after('<tr class="detail-row"><td colspan="4">' + detailHtml + '</td></tr>');
                }
            }
        });
    }

    // ─── Filtering ────────────────────────────────────────────────

    function applyFilters() {
        var result = state.allChanges;

        if (state.activeType !== 'all') {
            result = result.filter(function (c) { return c.type === state.activeType; });
        }
        if (state.activeAction !== 'all') {
            result = result.filter(function (c) { return c.action === state.activeAction; });
        }
        if (state.activeScope !== 'all') {
            result = result.filter(function (c) {
                if (!c.source) return true;
                return c.source === state.activeScope;
            });
        }
        if (state.searchTerm) {
            var term = state.searchTerm;
            result = result.filter(function (c) {
                if ((c.name && c.name.toUpperCase().indexOf(term) !== -1) ||
                    (c.old_name && c.old_name.toUpperCase().indexOf(term) !== -1) ||
                    (c.detail && c.detail.toUpperCase().indexOf(term) !== -1) ||
                    (c.new_value && String(c.new_value).toUpperCase().indexOf(term) !== -1) ||
                    (c.old_value && String(c.old_value).toUpperCase().indexOf(term) !== -1)) {
                    return true;
                }
                // Search airports and ARTCCs for playbook/dp/star entries
                if (c.airports && c.airports.some(function(a) { return a.toUpperCase().indexOf(term) !== -1; })) {
                    return true;
                }
                if (c.artccs && c.artccs.some(function(a) { return a.toUpperCase().indexOf(term) !== -1; })) {
                    return true;
                }
                if (c.computer_codes && c.computer_codes.some(function(a) { return a.toUpperCase().indexOf(term) !== -1; })) {
                    return true;
                }
                if (c.source && c.source.toUpperCase().indexOf(term) !== -1) {
                    return true;
                }
                return false;
            });
        }

        state.filtered = result;
        state.currentPage = 1;
        $('#changes-body').empty();
        renderPage();
        updateResultCount();
    }

    // ─── Rendering ────────────────────────────────────────────────

    function renderPage() {
        var total = state.filtered.length;
        if (total === 0) {
            showEmpty(state.searchTerm
                ? PERTII18n.t('navdata.empty.noResults')
                : PERTII18n.t('navdata.empty.noChanges'));
            $('#pagination-controls').hide();
            return;
        }

        $('#empty-state').hide();
        var $body = $('#changes-body').empty();

        // "All" mode: pageSize === 0
        var pageSize = state.pageSize || total;
        var start = (state.currentPage - 1) * pageSize;
        var end = Math.min(start + pageSize, total);
        var batch = state.filtered.slice(start, end);

        batch.forEach(function (c, i) {
            $body.append(renderChangeRow(c, start + i));
        });

        // Update pagination controls
        var pages = Math.ceil(total / pageSize);
        if (state.pageSize === 0) pages = 1;
        var from = start + 1;
        var to = end;

        $('#page-info').text(
            PERTII18n.t('navdata.pagination.showing', { from: from, to: to, total: total })
        );
        $('#page-prev').prop('disabled', state.currentPage <= 1);
        $('#page-next').prop('disabled', state.currentPage >= pages);
        $('#pagination-controls').toggle(total > 0);
    }

    function renderChangeRow(c, idx) {
        var name = escapeHtml(c.name || '');
        if (state.searchTerm) name = highlightMatch(name, state.searchTerm);

        var detail = buildDetailText(c);
        if (state.searchTerm) detail = highlightMatch(detail, state.searchTerm);

        var typeBadges = '<span class="badge badge-type">' + escapeHtml(c.type || '') + '</span>';
        if (c.source) {
            var srcClass = c.source === 'nasr' ? 'badge-source-nasr' : 'badge-source-intl';
            var srcLabel = c.source === 'nasr'
                ? PERTII18n.t('navdata.scope.nasr')
                : PERTII18n.t('navdata.scope.intl');
            typeBadges += ' <span class="badge ' + srcClass + '">' + escapeHtml(srcLabel) + '</span>';
        }

        return '<tr class="change-row" data-idx="' + idx + '">' +
            '<td class="change-name">' + name + '</td>' +
            '<td>' + typeBadges + '</td>' +
            '<td><span class="badge badge-action badge-' + (c.action || '') + '">' + escapeHtml(c.action || '') + '</span></td>' +
            '<td class="change-detail">' + detail + '</td>' +
            '</tr>';
    }

    function buildDetailText(c) {
        var parts = [];
        if (c.delta_nm) {
            parts.push('<span class="delta-nm">' + c.delta_nm + ' nm</span>');
        }
        if (c.type === 'playbook') {
            parts.push(escapeHtml(c.detail));
            if (c.route_count) {
                parts.push('<span class="text-muted">(' + PERTII18n.t('navdata.playbook.routeTotal', { count: c.route_count }) + ')</span>');
            }
            if (c.artccs && c.artccs.length) {
                c.artccs.forEach(function(a) {
                    parts.push('<span class="badge badge-secondary" style="font-size:0.65rem;padding:1px 4px">' +
                        escapeHtml(a) + '</span>');
                });
            }
            return parts.join(' ');
        }
        if (c.type === 'dp' || c.type === 'star') {
            parts.push(escapeHtml(c.detail));
            // Fix-level diff summary
            if (c.fixes_removed && c.fixes_removed.length) {
                c.fixes_removed.forEach(function(f) {
                    parts.push('<span class="diff-removed" style="font-size:0.7rem">' + escapeHtml(f) + '</span>');
                });
            }
            if (c.fixes_added && c.fixes_added.length) {
                c.fixes_added.forEach(function(f) {
                    parts.push('<span class="diff-added" style="font-size:0.7rem">' + escapeHtml(f) + '</span>');
                });
            }
            if (c.transition_count) {
                parts.push('<span class="text-muted">(' +
                    PERTII18n.t('navdata.procedure.transitionCount', { count: c.transition_count }) + ')</span>');
            }
            if (c.artccs && c.artccs.length) {
                c.artccs.forEach(function(a) {
                    parts.push('<span class="badge badge-secondary" style="font-size:0.65rem;padding:1px 4px">' +
                        escapeHtml(a) + '</span>');
                });
            }
            if (c.airports && c.airports.length) {
                var shown = c.airports.slice(0, 5);
                shown.forEach(function(a) {
                    parts.push('<span class="badge badge-info" style="font-size:0.65rem;padding:1px 4px">' +
                        escapeHtml(a) + '</span>');
                });
                if (c.airports.length > 5) {
                    parts.push('<span class="text-muted" style="font-size:0.65rem">+' +
                        (c.airports.length - 5) + '</span>');
                }
            }
            return parts.join(' ');
        }
        if (c.detail) {
            parts.push(escapeHtml(c.detail));
        }
        if (c.old_name && c.action !== 'added') {
            parts.push('<span class="text-muted">(' + escapeHtml(c.old_name) + ')</span>');
        }
        return parts.join(' ');
    }

    // ─── Detail Panel ─────────────────────────────────────────────

    function buildDetailPanel(c) {
        var type = c.type || '';
        var action = c.action || '';

        if (type === 'fix' || type === 'navaid') {
            return buildFixDetail(c);
        } else if (type === 'airway' || type === 'cdr') {
            return buildRouteDetail(c);
        } else if (type === 'dp' || type === 'star') {
            return buildProcedureDetail(c);
        } else if (type === 'playbook') {
            return buildPlaybookDetail(c);
        }
        return buildGenericDetail(c);
    }

    function buildFixDetail(c) {
        var html = '<div class="navdata-detail">';

        if (c.action === 'moved') {
            html += '<div class="navdata-detail-section">' +
                '<div class="navdata-detail-label">' + PERTII18n.t('navdata.detail.previousPosition') + '</div>' +
                '<div class="navdata-detail-value coord-old">' + formatCoord(c.old_lat, c.old_lon) + '</div>' +
                '</div>';
            html += '<div class="navdata-detail-section">' +
                '<div class="navdata-detail-label">' + PERTII18n.t('navdata.detail.newPosition') + '</div>' +
                '<div class="navdata-detail-value coord-new">' + formatCoord(c.lat, c.lon) + '</div>' +
                '</div>';
            if (c.delta_nm) {
                html += '<div class="navdata-detail-section">' +
                    '<div class="navdata-detail-label">' + PERTII18n.t('navdata.detail.displacement') + '</div>' +
                    '<div class="navdata-detail-value"><span class="delta-nm">' + c.delta_nm + ' nm</span></div>' +
                    '</div>';
            }
        } else if (c.action === 'added') {
            html += '<div class="navdata-detail-section">' +
                '<div class="navdata-detail-label">' + PERTII18n.t('navdata.detail.position') + '</div>' +
                '<div class="navdata-detail-value coord-new">' + formatCoord(c.lat, c.lon) + '</div>' +
                '</div>';
        } else if (c.action === 'removed') {
            html += '<div class="navdata-detail-section">' +
                '<div class="navdata-detail-label">' + PERTII18n.t('navdata.detail.formerPosition') + '</div>' +
                '<div class="navdata-detail-value coord-old">' + formatCoord(c.old_lat, c.old_lon) + '</div>' +
                '</div>';
        }

        html += '</div>';
        return html;
    }

    function buildRouteDetail(c) {
        var html = '<div class="navdata-detail" style="flex-direction:column;gap:0.5rem;">';

        if (c.action === 'changed' && c.old_value && c.new_value) {
            html += '<div class="navdata-detail-label">' + PERTII18n.t('navdata.detail.routeChanges') + '</div>';
            html += '<div class="route-diff">' + diffRouteStrings(String(c.old_value), String(c.new_value)) + '</div>';
        } else if (c.action === 'added' && c.new_value) {
            html += '<div class="navdata-detail-label">' + PERTII18n.t('navdata.detail.newRoute') + '</div>';
            html += '<div class="navdata-detail-value">' + escapeHtml(String(c.new_value)) + '</div>';
        } else if (c.action === 'removed' && c.old_value) {
            html += '<div class="navdata-detail-label">' + PERTII18n.t('navdata.detail.removedRoute') + '</div>';
            html += '<div class="navdata-detail-value" style="text-decoration:line-through;color:#a00;">' +
                escapeHtml(String(c.old_value)) + '</div>';
        } else {
            html += buildGenericDetail(c);
        }

        html += '</div>';
        return html;
    }

    function buildProcedureDetail(c) {
        var html = '<div class="navdata-detail" style="flex-direction:column;gap:0.5rem;">';
        var typeLabel = c.type === 'dp'
            ? PERTII18n.t('navdata.detail.departureProc')
            : PERTII18n.t('navdata.detail.arrivalProc');

        // Header: type label + transition count + ARTCCs + airports
        html += '<div style="display:flex;gap:0.75rem;align-items:center;flex-wrap:wrap">';
        html += '<span class="badge badge-dark" style="font-size:0.7rem">' + typeLabel + '</span>';
        if (c.transition_count) {
            html += '<span class="badge badge-info">' +
                PERTII18n.t('navdata.procedure.transitionCount', { count: c.transition_count }) + '</span>';
        }
        if (c.artccs && c.artccs.length) {
            html += '<span class="text-muted" style="font-size:0.8rem">' +
                PERTII18n.t('navdata.playbook.artccs') + ': ' +
                escapeHtml(c.artccs.join(', ')) + '</span>';
        }
        if (c.airports && c.airports.length) {
            html += '<span class="text-muted" style="font-size:0.8rem">' +
                PERTII18n.t('navdata.procedure.airports') + ': ' +
                escapeHtml(c.airports.join(', ')) + '</span>';
        }
        if (c.computer_codes && c.computer_codes.length) {
            html += '<span class="text-muted" style="font-size:0.75rem;font-family:monospace">' +
                escapeHtml(c.computer_codes.join(', ')) + '</span>';
        }
        html += '</div>';

        if (c.action === 'changed') {
            html += buildProcChangedSection(c);
        } else if (c.action === 'added') {
            html += buildProcTransitionList(
                c.added_transitions || [],
                PERTII18n.t('navdata.procedure.transitions'),
                '#28a745', '+'
            );
        } else if (c.action === 'removed') {
            html += buildProcTransitionList(
                c.removed_transitions || [],
                PERTII18n.t('navdata.procedure.transitions'),
                '#dc3545', null, true
            );
        }

        html += '</div>';
        return html;
    }

    function buildProcChangedSection(c) {
        var html = '';
        var PAGE_SZ = 20;

        // Fix-level summary (which fixes were added/removed across all transitions)
        if ((c.fixes_removed && c.fixes_removed.length) || (c.fixes_added && c.fixes_added.length)) {
            html += '<div style="margin-bottom:0.5rem;font-size:0.8rem">';
            if (c.fixes_removed && c.fixes_removed.length) {
                c.fixes_removed.forEach(function(f) {
                    html += '<span class="diff-removed" style="margin-right:4px">' + escapeHtml(f) + '</span>';
                });
            }
            if (c.fixes_added && c.fixes_added.length) {
                c.fixes_added.forEach(function(f) {
                    html += '<span class="diff-added" style="margin-right:4px">' + escapeHtml(f) + '</span>';
                });
            }
            html += '</div>';
        }

        // Modified transitions with word-level diff
        if (c.modified_transitions && c.modified_transitions.length) {
            var modId = 'proc-mod-' + Date.now();
            html += '<div class="navdata-detail-label" style="color:#0d6efd">' +
                PERTII18n.t('navdata.procedure.modifiedTransitions', { count: c.modified_transitions.length }) + '</div>';
            html += '<div id="' + modId + '" class="route-diff" style="font-size:0.75rem;font-family:monospace">';
            c.modified_transitions.forEach(function(t, i) {
                var hidden = i >= PAGE_SZ ? ' style="display:none"' : '';
                var label = t.name || t.code || PERTII18n.t('navdata.procedure.mainBody');
                html += '<div class="pb-route-pair" data-page-group="' + modId + '"' + hidden + '>' +
                    '<div style="margin-bottom:2px"><strong style="font-size:0.7rem;color:#6c757d">' +
                    escapeHtml(label) + '</strong></div>' +
                    '<div style="padding:2px 0">' + diffRouteStrings(t.old_route, t.new_route) + '</div>' +
                    '</div>';
            });
            if (c.modified_transitions.length > PAGE_SZ) {
                html += buildPaginationControls(modId, c.modified_transitions.length, PAGE_SZ);
            }
            html += '</div>';
        }

        // New transitions
        if (c.added_transitions && c.added_transitions.length) {
            html += buildProcTransitionList(
                c.added_transitions,
                PERTII18n.t('navdata.procedure.newTransitions'),
                '#28a745', '+'
            );
        }

        // Removed transitions
        if (c.removed_transitions && c.removed_transitions.length) {
            html += buildProcTransitionList(
                c.removed_transitions,
                PERTII18n.t('navdata.procedure.removedTransitions'),
                '#dc3545', null, true
            );
        }

        return html;
    }

    function buildProcTransitionList(transitions, label, color, prefix, strikethrough) {
        if (!transitions || !transitions.length) return '';
        var PAGE_SZ = 20;
        var listId = 'proc-list-' + Date.now() + '-' + Math.random().toString(36).substr(2, 4);
        var html = '<div class="navdata-detail-label" style="color:' + color + '">' +
            label + ' (' + transitions.length + ')</div>';
        html += '<div id="' + listId + '" style="font-size:0.75rem;font-family:monospace">';
        transitions.forEach(function(t, i) {
            var hidden = i >= PAGE_SZ ? ' style="display:none"' : '';
            var tLabel = t.name || t.code || '';
            var route = t.route_points || '';
            var style = 'color:' + color + ';padding:1px 0' +
                (strikethrough ? ';text-decoration:line-through' : '');
            html += '<div class="pb-route-item" data-page-group="' + listId + '"' + hidden + '>' +
                '<span style="' + style + '">' +
                (prefix ? prefix + ' ' : '') +
                '<strong>' + escapeHtml(tLabel) + '</strong>' +
                (route ? ' &mdash; ' + escapeHtml(route) : '') +
                '</span></div>';
        });
        if (transitions.length > PAGE_SZ) {
            html += buildPaginationControls(listId, transitions.length, PAGE_SZ);
        }
        html += '</div>';
        return html;
    }

    function buildPlaybookDetail(c) {
        var html = '<div class="navdata-detail" style="flex-direction:column;gap:0.5rem;">';

        // Header: route count + ARTCCs
        html += '<div style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap">';
        if (c.route_count) {
            html += '<span class="badge badge-info">' + PERTII18n.t('navdata.playbook.routeCount', { count: c.route_count }) + '</span>';
        }
        if (c.artccs && c.artccs.length) {
            html += '<span class="text-muted" style="font-size:0.8rem">' + PERTII18n.t('navdata.playbook.artccs') + ': ' +
                escapeHtml(c.artccs.join(', ')) + '</span>';
        }
        html += '</div>';

        if (c.action === 'changed') {
            html += buildPlaybookChangedSection(c);
        } else if (c.action === 'added') {
            html += buildPlaybookRouteList(c.added_routes || [], PERTII18n.t('navdata.playbook.routes'), '#28a745', '+');
        } else if (c.action === 'removed') {
            html += buildPlaybookRouteList(c.removed_routes || [], PERTII18n.t('navdata.playbook.routes'), '#dc3545', null, true);
        }

        html += '</div>';
        return html;
    }

    function buildPlaybookChangedSection(c) {
        var html = '';
        var PAGE_SZ = 20;

        // Modified routes (paired old→new with word-level diff)
        if (c.modified_routes && c.modified_routes.length) {
            var modId = 'pb-mod-' + Date.now();
            html += '<div class="navdata-detail-label" style="color:#0d6efd">' +
                PERTII18n.t('navdata.playbook.modifiedRoutes', { count: c.modified_routes.length }) + '</div>';
            html += '<div id="' + modId + '" class="route-diff" style="font-size:0.75rem;font-family:monospace">';
            c.modified_routes.forEach(function(pair, i) {
                var hidden = i >= PAGE_SZ ? ' style="display:none"' : '';
                html += '<div class="pb-route-pair" data-page-group="' + modId + '"' + hidden + '>' +
                    '<div style="padding:2px 0">' + diffRouteStrings(pair.old, pair['new']) + '</div>' +
                    '</div>';
            });
            if (c.modified_routes.length > PAGE_SZ) {
                html += buildPaginationControls(modId, c.modified_routes.length, PAGE_SZ);
            }
            html += '</div>';
        }

        // Truly new routes (no matching old route)
        if (c.added_routes && c.added_routes.length) {
            html += buildPlaybookRouteList(c.added_routes, PERTII18n.t('navdata.playbook.newRoutes'), '#28a745', '+');
        }

        // Truly removed routes (no matching new route)
        if (c.removed_routes && c.removed_routes.length) {
            html += buildPlaybookRouteList(c.removed_routes, PERTII18n.t('navdata.playbook.removedRoutes'), '#dc3545', null, true);
        }

        return html;
    }

    function buildPlaybookRouteList(routes, label, color, prefix, strikethrough) {
        if (!routes || !routes.length) return '';
        var PAGE_SZ = 20;
        var listId = 'pb-list-' + Date.now() + '-' + Math.random().toString(36).substr(2, 4);
        var html = '<div class="navdata-detail-label" style="color:' + color + '">' +
            label + ' (' + routes.length + ')</div>';
        html += '<div id="' + listId + '" style="font-size:0.75rem;font-family:monospace">';
        routes.forEach(function(r, i) {
            var hidden = i >= PAGE_SZ ? ' style="display:none"' : '';
            var style = 'color:' + color + ';padding:1px 0' +
                (strikethrough ? ';text-decoration:line-through' : '');
            html += '<div class="pb-route-item" data-page-group="' + listId + '"' + hidden + '>' +
                '<span style="' + style + '">' +
                (prefix ? prefix + ' ' : '') + escapeHtml(r) +
                '</span></div>';
        });
        if (routes.length > PAGE_SZ) {
            html += buildPaginationControls(listId, routes.length, PAGE_SZ);
        }
        html += '</div>';
        return html;
    }

    function buildPaginationControls(groupId, total, pageSize) {
        var pages = Math.ceil(total / pageSize);
        return '<div class="pb-pagination" style="margin-top:4px;display:flex;gap:4px;align-items:center">' +
            '<span class="text-muted" style="font-size:0.7rem">' + PERTII18n.t('navdata.playbook.page', { current: 1, total: pages }) + '</span> ' +
            '<button class="btn btn-sm btn-outline-secondary py-0 px-1" style="font-size:0.65rem" ' +
                'onclick="window._pbPageChange(\'' + groupId + '\',-1,' + pageSize + ',' + total + ',this)"' +
                ' disabled>&laquo; ' + PERTII18n.t('navdata.playbook.prev') + '</button>' +
            '<button class="btn btn-sm btn-outline-secondary py-0 px-1" style="font-size:0.65rem" ' +
                'onclick="window._pbPageChange(\'' + groupId + '\',1,' + pageSize + ',' + total + ',this)"' +
                '">' + PERTII18n.t('navdata.playbook.next') + ' &raquo;</button>' +
            '</div>';
    }

    // Global pagination handler (needs to be on window for inline onclick)
    window._pbPageState = {};
    window._pbPageChange = function(groupId, dir, pageSize, total, btn) {
        var cur = window._pbPageState[groupId] || 0;
        var pages = Math.ceil(total / pageSize);
        cur += dir;
        if (cur < 0) cur = 0;
        if (cur >= pages) cur = pages - 1;
        window._pbPageState[groupId] = cur;

        var items = document.querySelectorAll('[data-page-group="' + groupId + '"]');
        var start = cur * pageSize;
        var end = start + pageSize;
        for (var i = 0; i < items.length; i++) {
            items[i].style.display = (i >= start && i < end) ? '' : 'none';
        }

        var controls = btn.parentElement;
        controls.querySelector('span').textContent = PERTII18n.t('navdata.playbook.page', { current: cur + 1, total: pages });
        var btns = controls.querySelectorAll('button');
        btns[0].disabled = (cur === 0);
        btns[1].disabled = (cur >= pages - 1);
    };

    function buildGenericDetail(c) {
        var html = '';
        if (c.detail) {
            html += '<span class="text-muted">' + escapeHtml(c.detail) + '</span>';
        }
        return html || '<span class="text-muted">' + PERTII18n.t('navdata.detail.noAdditional') + '</span>';
    }

    // ─── Route Diff ───────────────────────────────────────────────

    function diffRouteStrings(oldStr, newStr) {
        var oldParts = oldStr.split(/\s+/);
        var newParts = newStr.split(/\s+/);

        // Build LCS for word-level diff
        var lcs = computeLCS(oldParts, newParts);
        var result = [];
        var oi = 0, ni = 0, li = 0;

        while (oi < oldParts.length || ni < newParts.length) {
            if (li < lcs.length && oi < oldParts.length && oldParts[oi] === lcs[li] &&
                ni < newParts.length && newParts[ni] === lcs[li]) {
                result.push('<span class="diff-unchanged">' + escapeHtml(lcs[li]) + '</span>');
                oi++; ni++; li++;
            } else if (li < lcs.length && ni < newParts.length && newParts[ni] === lcs[li]) {
                // Old part removed
                result.push('<span class="diff-removed">' + escapeHtml(oldParts[oi]) + '</span>');
                oi++;
            } else if (li < lcs.length && oi < oldParts.length && oldParts[oi] === lcs[li]) {
                // New part added
                result.push('<span class="diff-added">' + escapeHtml(newParts[ni]) + '</span>');
                ni++;
            } else {
                // Neither matches LCS
                if (oi < oldParts.length && (li >= lcs.length || oldParts[oi] !== lcs[li])) {
                    result.push('<span class="diff-removed">' + escapeHtml(oldParts[oi]) + '</span>');
                    oi++;
                }
                if (ni < newParts.length && (li >= lcs.length || newParts[ni] !== lcs[li])) {
                    result.push('<span class="diff-added">' + escapeHtml(newParts[ni]) + '</span>');
                    ni++;
                }
            }
        }

        return result.join(' ');
    }

    function computeLCS(a, b) {
        var m = a.length, n = b.length;
        var dp = [];
        for (var i = 0; i <= m; i++) {
            dp[i] = [];
            for (var j = 0; j <= n; j++) {
                if (i === 0 || j === 0) dp[i][j] = 0;
                else if (a[i-1] === b[j-1]) dp[i][j] = dp[i-1][j-1] + 1;
                else dp[i][j] = Math.max(dp[i-1][j], dp[i][j-1]);
            }
        }
        // Backtrack
        var result = [];
        var i = m, j = n;
        while (i > 0 && j > 0) {
            if (a[i-1] === b[j-1]) {
                result.unshift(a[i-1]);
                i--; j--;
            } else if (dp[i-1][j] > dp[i][j-1]) {
                i--;
            } else {
                j--;
            }
        }
        return result;
    }

    // ─── Summary / Stats ──────────────────────────────────────────

    function renderSummaryCards(data) {
        var $row = $('#summary-cards').empty();
        var summary = data.summary || {};
        var nasrSummary = data.nasr_summary || {};
        var intlSummary = data.intl_summary || {};
        var typeLabels = {
            fixes: { label: PERTII18n.t('navdata.type.fixes'), icon: 'fa-map-pin' },
            navaids: { label: PERTII18n.t('navdata.type.navaids'), icon: 'fa-broadcast-tower' },
            airways: { label: PERTII18n.t('navdata.type.airways'), icon: 'fa-route' },
            cdrs: { label: PERTII18n.t('navdata.type.cdrs'), icon: 'fa-code-branch' },
            dps: { label: PERTII18n.t('navdata.type.dps'), icon: 'fa-plane-departure' },
            stars: { label: PERTII18n.t('navdata.type.stars'), icon: 'fa-plane-arrival' },
            playbook: { label: PERTII18n.t('navdata.type.playbook'), icon: 'fa-book' }
        };
        Object.keys(typeLabels).forEach(function (key) {
            var info = typeLabels[key];
            var activeScope = state.activeScope;

            // Pick the right summary based on scope filter
            var s;
            if (activeScope === 'nasr' && (nasrSummary[key] || intlSummary[key])) {
                s = nasrSummary[key] || {};
            } else if (activeScope === 'intl' && (nasrSummary[key] || intlSummary[key])) {
                s = intlSummary[key] || {};
            } else {
                s = summary[key] || {};
            }
            var hasChanges = (s.added || 0) + (s.modified || 0) + (s.removed || 0) > 0;
            var total = (data.meta && data.meta.totals) ? (data.meta.totals[key] || data.meta.totals[key.replace(/s$/, '')] || 0) : 0;

            var statsHtml = '';
            if (s.added) statsHtml += '<span class="stat-added">+' + s.added + '</span> ';
            if (s.modified) statsHtml += '<span class="stat-modified">~' + s.modified + '</span> ';
            if (s.removed) statsHtml += '<span class="stat-removed">-' + s.removed + '</span> ';
            if (!statsHtml) statsHtml = '<span class="stat-preserved">' + PERTII18n.t('navdata.noChanges') + '</span>';

            // Sub-counts for types with mixed NASR/Intl data when showing all
            var subHtml = '';
            if (activeScope === 'all') {
                var ns = nasrSummary[key] || {};
                var is = intlSummary[key] || {};
                var hasNasr = (ns.added || 0) + (ns.modified || 0) + (ns.removed || 0) > 0;
                var hasIntl = (is.added || 0) + (is.modified || 0) + (is.removed || 0) > 0;
                if (hasNasr || hasIntl) {
                    subHtml = '<div style="font-size:0.6rem;margin-top:2px;line-height:1.4">';
                    if (hasNasr) {
                        subHtml += '<span class="summary-scope-label">' + PERTII18n.t('navdata.scope.nasr') + ':</span>';
                        if (ns.added) subHtml += '<span class="stat-added">+' + ns.added + '</span> ';
                        if (ns.modified) subHtml += '<span class="stat-modified">~' + ns.modified + '</span> ';
                        if (ns.removed) subHtml += '<span class="stat-removed">-' + ns.removed + '</span> ';
                    }
                    if (hasNasr && hasIntl) subHtml += '<br>';
                    if (hasIntl) {
                        subHtml += '<span class="summary-scope-label">' + PERTII18n.t('navdata.scope.intl') + ':</span>';
                        if (is.added) subHtml += '<span class="stat-added">+' + is.added + '</span> ';
                        if (is.modified) subHtml += '<span class="stat-modified">~' + is.modified + '</span> ';
                        if (is.removed) subHtml += '<span class="stat-removed">-' + is.removed + '</span> ';
                    }
                    subHtml += '</div>';
                }
            }

            $row.append(
                '<div class="col-sm-6 col-md-4 col-lg mb-2">' +
                '<div class="card navdata-card' + (hasChanges ? ' has-changes' : '') + '">' +
                '<div class="card-body">' +
                '<div class="card-title"><i class="fas ' + info.icon + ' mr-1"></i>' + info.label + '</div>' +
                '<div class="card-stat">' + statsHtml + '</div>' +
                subHtml +
                (total ? '<div class="text-muted" style="font-size:0.65rem">' + total.toLocaleString() + ' ' + PERTII18n.t('navdata.total') + '</div>' : '') +
                '</div></div></div>'
            );
        });
    }

    function renderStats(data) {
        if (!data.meta || !data.meta.totals) {
            $('#stats-panel').hide();
            return;
        }
        var $row = $('#stats-row').empty();
        var totals = data.meta.totals;
        var items = [
            { label: PERTII18n.t('navdata.type.fixes'), val: totals.points || 0 },
            { label: PERTII18n.t('navdata.type.navaids'), val: totals.navaids || 0 },
            { label: PERTII18n.t('navdata.type.airways'), val: totals.airways || 0 },
            { label: PERTII18n.t('navdata.type.cdrs'), val: totals.cdrs || 0 },
            { label: PERTII18n.t('navdata.type.dps'), val: totals.dps || 0 },
            { label: PERTII18n.t('navdata.type.stars'), val: totals.stars || 0 }
        ];
        items.forEach(function (item) {
            $row.append(
                '<div class="col stat-item">' +
                '<div class="stat-value">' + item.val.toLocaleString() + '</div>' +
                '<div class="stat-label">' + item.label + '</div>' +
                '</div>'
            );
        });
        $('#stats-panel').show();
    }

    function updateTabCounts() {
        var scopeFiltered = state.allChanges;
        if (state.activeScope !== 'all') {
            scopeFiltered = scopeFiltered.filter(function (c) {
                if (!c.source) return true;
                return c.source === state.activeScope;
            });
        }
        var counts = {};
        scopeFiltered.forEach(function (c) {
            counts[c.type] = (counts[c.type] || 0) + 1;
        });
        $('#type-tabs .nav-link').each(function () {
            var type = $(this).data('type');
            var $count = $(this).find('.tab-count');
            if ($count.length === 0) {
                $count = $('<span class="tab-count"></span>');
                $(this).append($count);
            }
            if (type === 'all') {
                $count.text(scopeFiltered.length);
            } else {
                $count.text(counts[type] || 0);
            }
        });
    }

    function updateResultCount() {
        var total = state.filtered.length;
        var text = total + ' ' + PERTII18n.t('navdata.results');
        if (state.searchTerm || state.activeType !== 'all' || state.activeAction !== 'all' || state.activeScope !== 'all') {
            text += ' (' + PERTII18n.t('navdata.filtered') + ')';
        }
        $('#result-count').text(text);
    }

    // ─── UI State Helpers ─────────────────────────────────────────

    function showLoading() {
        $('#loading-state').show();
        $('#empty-state').hide();
        $('#changes-body').empty();
        $('#pagination-controls').hide();
    }

    function hideLoading() {
        $('#loading-state').hide();
    }

    function showEmpty(message) {
        $('#loading-state').hide();
        $('#empty-state').show();
        $('#empty-message').text(message);
    }

    // ─── Utilities ────────────────────────────────────────────────

    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                  .replace(/"/g, '&quot;');
    }

    function highlightMatch(html, term) {
        if (!term) return html;
        var regex = new RegExp('(' + term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
        return html.replace(regex, '<span class="search-match">$1</span>');
    }

    function formatCoord(lat, lon) {
        if (lat == null || lon == null) return 'N/A';
        var ns = lat >= 0 ? 'N' : 'S';
        var ew = lon >= 0 ? 'E' : 'W';
        return Math.abs(lat).toFixed(6) + '\u00B0' + ns + ' ' +
               Math.abs(lon).toFixed(6) + '\u00B0' + ew;
    }

})();
