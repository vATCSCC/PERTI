/**
 * NavData AIRAC Changelog
 * Loads JSON changelogs, provides search/filter/tab functionality.
 */
(function () {
    'use strict';

    var PAGE_SIZE = 100;

    var state = {
        changelog: null,      // current changelog JSON
        allChanges: [],       // full changes array
        filtered: [],         // after search/filter/tab
        displayed: 0,         // how many rows currently shown
        searchTerm: '',
        activeType: 'all',
        activeAction: 'all',
        availableCycles: []
    };

    // ─── Initialization ───────────────────────────────────────────

    $(document).ready(function () {
        discoverCycles();
        bindEvents();
    });

    function discoverCycles() {
        // Fetch the list of available changelog files via a directory listing approach.
        // Since we can't list directories client-side, we use a known pattern:
        // Try loading an index file, or fall back to a hardcoded recent cycle.
        $.getJSON('assets/data/logs/changelog_index.json')
            .done(function (index) {
                state.availableCycles = index.cycles || [];
                populateCycleSelector();
            })
            .fail(function () {
                // No index file — try the most likely current cycle pair
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
        // Load first (most recent)
        var first = state.availableCycles[0];
        loadChangelog(first.from, first.to);
    }

    function tryLoadChangelog(from, to) {
        var url = 'assets/data/logs/AIRAC_CHANGELOG_' + from + '_' + to + '.json';
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
        var url = 'assets/data/logs/AIRAC_CHANGELOG_' + from + '_' + to + '.json';
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

        // Load more
        $('#load-more-btn').on('click', function () {
            renderMore();
        });
    }

    // ─── Filtering ────────────────────────────────────────────────

    function applyFilters() {
        var result = state.allChanges;

        // Type filter
        if (state.activeType !== 'all') {
            result = result.filter(function (c) { return c.type === state.activeType; });
        }

        // Action filter
        if (state.activeAction !== 'all') {
            result = result.filter(function (c) { return c.action === state.activeAction; });
        }

        // Search
        if (state.searchTerm) {
            var term = state.searchTerm;
            result = result.filter(function (c) {
                return (c.name && c.name.toUpperCase().indexOf(term) !== -1) ||
                       (c.old_name && c.old_name.toUpperCase().indexOf(term) !== -1) ||
                       (c.detail && c.detail.toUpperCase().indexOf(term) !== -1) ||
                       (c.new_value && String(c.new_value).toUpperCase().indexOf(term) !== -1);
            });
        }

        state.filtered = result;
        state.displayed = 0;
        $('#changes-body').empty();
        renderMore();
        updateResultCount();
    }

    // ─── Rendering ────────────────────────────────────────────────

    function renderMore() {
        var batch = state.filtered.slice(state.displayed, state.displayed + PAGE_SIZE);
        if (batch.length === 0 && state.displayed === 0) {
            showEmpty(state.searchTerm
                ? PERTII18n.t('navdata.empty.noResults')
                : PERTII18n.t('navdata.empty.noChanges'));
            $('#load-more-row').hide();
            return;
        }

        $('#empty-state').hide();
        var $body = $('#changes-body');
        batch.forEach(function (c) {
            $body.append(renderChangeRow(c));
        });
        state.displayed += batch.length;

        var remaining = state.filtered.length - state.displayed;
        if (remaining > 0) {
            $('#load-more-row').show();
            $('#load-more-btn').text(
                PERTII18n.t('navdata.loadMore') + ' (' + remaining + ' ' + PERTII18n.t('navdata.remaining') + ')'
            );
        } else {
            $('#load-more-row').hide();
        }
    }

    function renderChangeRow(c) {
        var name = escapeHtml(c.name || '');
        if (state.searchTerm) name = highlightMatch(name, state.searchTerm);

        var detail = buildDetailText(c);
        if (state.searchTerm) detail = highlightMatch(detail, state.searchTerm);

        return '<tr>' +
            '<td class="change-name">' + name + '</td>' +
            '<td><span class="badge badge-type">' + escapeHtml(c.type || '') + '</span></td>' +
            '<td><span class="badge badge-action badge-' + (c.action || '') + '">' + escapeHtml(c.action || '') + '</span></td>' +
            '<td class="change-detail">' + detail + '</td>' +
            '</tr>';
    }

    function buildDetailText(c) {
        var parts = [];
        if (c.delta_nm) {
            parts.push('<span class="delta-nm">' + c.delta_nm + ' nm</span>');
        }
        if (c.detail) {
            parts.push(escapeHtml(c.detail));
        }
        if (c.old_name && c.action !== 'added') {
            parts.push('<span class="text-muted">(' + escapeHtml(c.old_name) + ')</span>');
        }
        return parts.join(' ');
    }

    function renderSummaryCards(data) {
        var $row = $('#summary-cards').empty();
        var summary = data.summary || {};
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
            var s = summary[key] || {};
            var hasChanges = (s.added || 0) + (s.modified || 0) + (s.removed || 0) > 0;
            var total = (data.meta && data.meta.totals) ? (data.meta.totals[key] || data.meta.totals[key.replace(/s$/, '')] || 0) : 0;

            var statsHtml = '';
            if (s.added) statsHtml += '<span class="stat-added">+' + s.added + '</span> ';
            if (s.modified) statsHtml += '<span class="stat-modified">~' + s.modified + '</span> ';
            if (s.removed) statsHtml += '<span class="stat-removed">-' + s.removed + '</span> ';
            if (!statsHtml) statsHtml = '<span class="stat-preserved">' + PERTII18n.t('navdata.noChanges') + '</span>';

            $row.append(
                '<div class="col-sm-6 col-md-4 col-lg mb-2">' +
                '<div class="card navdata-card' + (hasChanges ? ' has-changes' : '') + '">' +
                '<div class="card-body">' +
                '<div class="card-title"><i class="fas ' + info.icon + ' mr-1"></i>' + info.label + '</div>' +
                '<div class="card-stat">' + statsHtml + '</div>' +
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
        var counts = {};
        state.allChanges.forEach(function (c) {
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
                $count.text(state.allChanges.length);
            } else {
                $count.text(counts[type] || 0);
            }
        });
    }

    function updateResultCount() {
        var total = state.filtered.length;
        var text = total + ' ' + PERTII18n.t('navdata.results');
        if (state.searchTerm || state.activeType !== 'all' || state.activeAction !== 'all') {
            text += ' (' + PERTII18n.t('navdata.filtered') + ')';
        }
        $('#result-count').text(text);
    }

    // ─── UI State Helpers ─────────────────────────────────────────

    function showLoading() {
        $('#loading-state').show();
        $('#empty-state').hide();
        $('#changes-body').empty();
        $('#load-more-row').hide();
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
        // Simple case-insensitive highlight (works on already-escaped HTML text)
        var regex = new RegExp('(' + term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
        return html.replace(regex, '<span class="search-match">$1</span>');
    }

})();
