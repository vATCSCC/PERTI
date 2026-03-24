/**
 * Historical Routes - Page Controller
 * Search and analyze historically filed flight plan routes
 */
(function() {
    'use strict';

    // ========================================================================
    // STATE MANAGEMENT
    // ========================================================================

    var state = {
        filters: {
            origins: [],
            destinations: [],
            origMode: 'airport',
            destMode: 'airport'
        },
        results: null,
        selectedRoute: null,
        page: 1,
        sort: 'frequency',
        view: 'grouped'
    };

    // ========================================================================
    // INITIALIZATION
    // ========================================================================

    $(document).ready(function() {
        console.log('[Routes] Initializing...');
        initFilters();
        parseUrlState();

        // If URL had filters, auto-search
        if (hasActiveFilters()) {
            console.log('[Routes] Found filters in URL, auto-searching');
            doSearch();
        }
    });

    // ========================================================================
    // FILTER INITIALIZATION
    // ========================================================================

    function initFilters() {
        // Origin tag input
        initTagInput('origin');

        // Destination tag input
        initTagInput('dest');

        // Mode pills
        $('.routes-mode-pill').on('click', function() {
            var mode = $(this).data('mode');
            var target = $(this).data('target');

            // Update active state
            $('.routes-mode-pill[data-target="' + target + '"]').removeClass('active');
            $(this).addClass('active');

            // Update state
            if (target === 'origin') {
                state.filters.origMode = mode;
            } else {
                state.filters.destMode = mode;
            }

            console.log('[Routes] Mode changed:', target, mode);
        });

        // Search button
        $('#routes_search_btn').on('click', function() {
            state.page = 1; // Reset to page 1
            doSearch();
        });

        // Clear all button
        $('#routes_clear_btn').on('click', function() {
            clearAllFilters();
        });
    }

    function initTagInput(target) {
        var inputId = target + '_input';
        var containerId = target + '_tags_container';
        var filterKey = target === 'origin' ? 'origins' : 'destinations';

        var $input = $('#' + inputId);
        var $container = $('#' + containerId);

        // Handle Enter key and comma
        $input.on('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ',') {
                e.preventDefault();
                var value = $input.val().trim().toUpperCase();
                if (value && value !== ',') {
                    addTag(target, value);
                    $input.val('');
                }
            } else if (e.key === 'Backspace' && $input.val() === '') {
                // Remove last tag on backspace
                if (state.filters[filterKey].length > 0) {
                    removeTag(target, state.filters[filterKey][state.filters[filterKey].length - 1]);
                }
            }
        });

        // Handle blur (when clicking outside)
        $input.on('blur', function() {
            var value = $input.val().trim().toUpperCase();
            if (value && value !== ',') {
                addTag(target, value);
                $input.val('');
            }
        });

        // Uppercase as you type
        $input.on('input', function() {
            var pos = this.selectionStart;
            this.value = this.value.toUpperCase();
            this.setSelectionRange(pos, pos);
        });
    }

    function addTag(target, code) {
        var filterKey = target === 'origin' ? 'origins' : 'destinations';

        // Remove any non-alphanumeric chars except comma (for split)
        code = code.replace(/[^A-Z0-9,]/g, '');

        // Split by comma if multiple
        var codes = code.split(',').filter(function(c) { return c.length > 0; });

        codes.forEach(function(c) {
            // Prevent duplicates
            if (state.filters[filterKey].indexOf(c) === -1) {
                state.filters[filterKey].push(c);
            }
        });

        renderTags(target);
        renderFilterChips();
        updateClearButton();
    }

    function removeTag(target, code) {
        var filterKey = target === 'origin' ? 'origins' : 'destinations';
        state.filters[filterKey] = state.filters[filterKey].filter(function(c) {
            return c !== code;
        });
        renderTags(target);
        renderFilterChips();
        updateClearButton();
    }

    function renderTags(target) {
        var filterKey = target === 'origin' ? 'origins' : 'destinations';
        var containerId = target + '_tags_container';
        var inputId = target + '_input';

        var $container = $('#' + containerId);
        var $input = $('#' + inputId);

        // Remove existing tags
        $container.find('.routes-tag').remove();

        // Add tags before input
        state.filters[filterKey].forEach(function(code) {
            var $tag = $('<span class="routes-tag"></span>')
                .text(code)
                .append('<i class="fas fa-times routes-tag-remove"></i>');

            $tag.find('.routes-tag-remove').on('click', function() {
                removeTag(target, code);
            });

            $input.before($tag);
        });
    }

    // ========================================================================
    // FILTER CHIPS
    // ========================================================================

    function renderFilterChips() {
        var $bar = $('#routes_filter_chips');
        $bar.empty();

        // Origin chips
        state.filters.origins.forEach(function(code) {
            var label = PERTII18n.t('routes.filters.origin') + ': ' + code;
            if (state.filters.origMode !== 'airport') {
                label += ' (' + state.filters.origMode + ')';
            }
            $bar.append(buildChip(label, function() {
                removeTag('origin', code);
                doSearch();
            }));
        });

        // Destination chips
        state.filters.destinations.forEach(function(code) {
            var label = PERTII18n.t('routes.filters.destination') + ': ' + code;
            if (state.filters.destMode !== 'airport') {
                label += ' (' + state.filters.destMode + ')';
            }
            $bar.append(buildChip(label, function() {
                removeTag('dest', code);
                doSearch();
            }));
        });
    }

    function buildChip(label, onRemove) {
        var $chip = $('<span class="routes-filter-chip"></span>')
            .text(label)
            .append('<i class="fas fa-times routes-chip-remove"></i>');

        $chip.find('.routes-chip-remove').on('click', onRemove);
        return $chip;
    }

    // ========================================================================
    // SEARCH
    // ========================================================================

    function doSearch() {
        var params = filtersToQueryString();

        if (!params) {
            showNoFiltersState();
            return;
        }

        console.log('[Routes] Searching with params:', params);
        showLoadingState();

        $.ajax({
            url: 'api/data/route-history/search.php?' + params,
            method: 'GET',
            dataType: 'json',
            success: function(data) {
                console.log('[Routes] Search response:', data);
                if (data.success) {
                    state.results = data;
                    renderRouteList(data);
                    renderFilterChips();
                    updateUrl();
                } else {
                    showError(data.error || PERTII18n.t('error.loadFailed', { resource: 'routes' }));
                }
            },
            error: function(xhr, status, error) {
                console.error('[Routes] Search failed:', status, error);
                showError(PERTII18n.t('error.loadFailed', { resource: 'routes' }));
            }
        });
    }

    function filtersToQueryString() {
        var params = [];

        // Location filters
        if (state.filters.origins.length > 0) {
            params.push('orig=' + state.filters.origins.join(','));
            if (state.filters.origMode !== 'airport') {
                params.push('orig_mode=' + state.filters.origMode);
            }
        }

        if (state.filters.destinations.length > 0) {
            params.push('dest=' + state.filters.destinations.join(','));
            if (state.filters.destMode !== 'airport') {
                params.push('dest_mode=' + state.filters.destMode);
            }
        }

        // Need at least one filter
        if (params.length === 0) {
            return null;
        }

        // Sort and view
        params.push('sort=' + state.sort);
        params.push('view=' + state.view);
        params.push('page=' + state.page);

        return params.join('&');
    }

    function hasActiveFilters() {
        return state.filters.origins.length > 0 || state.filters.destinations.length > 0;
    }

    // ========================================================================
    // ROUTE LIST RENDERING
    // ========================================================================

    function renderRouteList(data) {
        var $list = $('#routes_list');
        $list.empty();

        if (!data.routes || data.routes.length === 0) {
            showNoResultsState();
            return;
        }

        // Summary bar
        var $summary = $('<div class="routes-summary"></div>');
        $summary.html(
            '<span><strong>' + data.total_routes.toLocaleString() + '</strong> ' +
            PERTII18n.t('routes.results.totalRoutes') + '</span>' +
            '<span><strong>' + data.total_flights.toLocaleString() + '</strong> ' +
            PERTII18n.t('routes.results.totalFlights') + '</span>'
        );
        $list.append($summary);

        // Controls (sort + view toggle)
        var $controls = $('<div class="routes-controls"></div>');

        // Sort dropdown
        var $sortGroup = $('<div></div>');
        $sortGroup.append('<label style="color: #aaa; font-size: 0.8rem; margin-right: 8px;">' +
            PERTII18n.t('routes.results.sortBy') + ':</label>');
        var $sortSelect = $('<select class="routes-sort-select"></select>');
        $sortSelect.append('<option value="frequency">' + PERTII18n.t('routes.results.sortFrequency') + '</option>');
        $sortSelect.append('<option value="distance">' + PERTII18n.t('routes.results.sortDistance') + '</option>');
        $sortSelect.append('<option value="ete">' + PERTII18n.t('routes.results.sortEte') + '</option>');
        $sortSelect.append('<option value="last_filed">' + PERTII18n.t('routes.results.sortLastFiled') + '</option>');
        $sortSelect.val(state.sort);
        $sortSelect.on('change', function() {
            state.sort = $(this).val();
            state.page = 1;
            doSearch();
        });
        $sortGroup.append($sortSelect);
        $controls.append($sortGroup);

        // View toggle
        var $viewToggle = $('<div class="routes-view-toggle"></div>');
        var $groupedBtn = $('<button class="routes-view-btn"></button>')
            .text(PERTII18n.t('routes.results.grouped'))
            .addClass(state.view === 'grouped' ? 'active' : '')
            .on('click', function() {
                state.view = 'grouped';
                state.page = 1;
                doSearch();
            });
        var $rawBtn = $('<button class="routes-view-btn"></button>')
            .text(PERTII18n.t('routes.results.raw'))
            .addClass(state.view === 'raw' ? 'active' : '')
            .on('click', function() {
                state.view = 'raw';
                state.page = 1;
                doSearch();
            });
        $viewToggle.append($groupedBtn, $rawBtn);
        $controls.append($viewToggle);

        $list.append($controls);

        // Route items
        data.routes.forEach(function(route) {
            var $item = buildRouteItem(route);
            $list.append($item);
        });

        // Load more button
        if (data.page < data.total_pages) {
            var $more = $('<button class="routes-load-more"></button>')
                .html('<i class="fas fa-chevron-down"></i> ' + PERTII18n.t('routes.results.loadMore') +
                      ' (' + (data.total_pages - data.page) + ' ' + PERTII18n.t('routes.results.totalRoutes') + ')')
                .on('click', function() {
                    state.page++;
                    doSearch();
                });
            $list.append($more);
        }
    }

    function buildRouteItem(route) {
        var $item = $('<div class="routes-item"></div>');

        // Header: airports
        var $header = $('<div class="routes-item-header"></div>');
        var $airports = $('<div class="routes-item-airports"></div>')
            .html(route.origin_icao + ' <span class="arrow">&rarr;</span> ' + route.dest_icao);
        var $stats = $('<div class="routes-item-stats"></div>')
            .text(route.flight_count.toLocaleString() + ' ' + PERTII18n.t('routes.results.flights'));
        $header.append($airports, $stats);
        $item.append($header);

        // Route string (truncated)
        var routeText = route.normalized_route || '';
        if (routeText.length > 60) {
            routeText = routeText.substring(0, 60) + '...';
        }
        var $route = $('<div class="routes-item-route"></div>').text(routeText);
        $item.append($route);

        // Metadata
        var $meta = $('<div class="routes-item-meta"></div>');

        if (route.variant_count > 1) {
            $meta.append('<span><i class="fas fa-code-branch"></i> ' +
                route.variant_count + ' ' + PERTII18n.t('routes.results.variants') + '</span>');
        }

        if (route.avg_distance_nm) {
            $meta.append('<span><i class="fas fa-ruler"></i> ' +
                Math.round(route.avg_distance_nm) + ' nm ' + PERTII18n.t('routes.results.avgDist') + '</span>');
        }

        if (route.avg_ete_minutes) {
            var hours = Math.floor(route.avg_ete_minutes / 60);
            var mins = Math.round(route.avg_ete_minutes % 60);
            $meta.append('<span><i class="fas fa-clock"></i> ' + hours + 'h' + mins + 'm ' +
                PERTII18n.t('routes.results.avgEte') + '</span>');
        }

        if (route.last_filed) {
            var lastFiled = formatRelativeDate(route.last_filed);
            $meta.append('<span><i class="fas fa-calendar"></i> ' + lastFiled + '</span>');
        }

        $item.append($meta);

        // Click handler
        $item.on('click', function() {
            selectRoute(route);
        });

        // Mark as selected if matches current selection
        if (state.selectedRoute && state.selectedRoute.route_dim_id === route.route_dim_id) {
            $item.addClass('selected');
        }

        return $item;
    }

    function selectRoute(route) {
        console.log('[Routes] Route selected:', route);
        state.selectedRoute = route;

        // Update selected state in list
        $('.routes-item').removeClass('selected');
        $('.routes-item').filter(function() {
            return $(this).data('route_id') === route.route_dim_id;
        }).addClass('selected');

        // Fetch detail
        $.ajax({
            url: 'api/data/route-history/detail.php?route_dim_id=' + route.route_dim_id,
            method: 'GET',
            dataType: 'json',
            success: function(data) {
                console.log('[Routes] Detail response:', data);
                if (data.success) {
                    showDetailPanel(data);
                } else {
                    console.error('[Routes] Detail fetch failed:', data.error);
                }
            },
            error: function(xhr, status, error) {
                console.error('[Routes] Detail fetch error:', status, error);
            }
        });
    }

    function showDetailPanel(data) {
        var $panel = $('#routes_bottom_panel');
        $panel.empty();

        // Basic variant list for now
        var html = '<div style="padding: 20px;">';
        html += '<h4 style="margin-bottom: 16px;">' + PERTII18n.t('routes.detail.variants') +
                ' (' + data.variants.length + ')</h4>';

        html += '<table class="table table-sm table-striped">';
        html += '<thead><tr>';
        html += '<th>' + PERTII18n.t('routes.detail.rawRoute') + '</th>';
        html += '<th>' + PERTII18n.t('routes.detail.count') + '</th>';
        html += '<th>' + PERTII18n.t('routes.detail.lastFiled') + '</th>';
        html += '</tr></thead><tbody>';

        data.variants.forEach(function(variant) {
            html += '<tr>';
            html += '<td style="font-family: monospace; font-size: 0.85rem;">' +
                    (variant.raw_route || variant.normalized_route) + '</td>';
            html += '<td>' + variant.flight_count.toLocaleString() + '</td>';
            html += '<td>' + formatDate(variant.last_filed) + '</td>';
            html += '</tr>';
        });

        html += '</tbody></table></div>';

        $panel.html(html);
        $panel.show();
    }

    // ========================================================================
    // EMPTY STATES
    // ========================================================================

    function showNoFiltersState() {
        var $list = $('#routes_list');
        $list.empty();

        var $empty = $('<div class="routes-empty-state"></div>');
        $empty.append('<i class="fas fa-filter"></i>');
        $empty.append('<h3>' + PERTII18n.t('routes.title') + '</h3>');
        $empty.append('<p>' + PERTII18n.t('routes.results.noFilters') + '</p>');
        $list.append($empty);
    }

    function showNoResultsState() {
        var $list = $('#routes_list');
        $list.empty();

        var $empty = $('<div class="routes-empty-state"></div>');
        $empty.append('<i class="fas fa-search"></i>');
        $empty.append('<h3>' + PERTII18n.t('routes.results.noResults') + '</h3>');
        $empty.append('<p>' + PERTII18n.t('routes.results.noResults') + '</p>');
        $list.append($empty);
    }

    function showLoadingState() {
        var $list = $('#routes_list');
        $list.empty();

        var $loading = $('<div class="routes-empty-state"></div>');
        $loading.append('<i class="fas fa-spinner fa-spin"></i>');
        $loading.append('<h3>' + PERTII18n.t('routes.results.loading') + '</h3>');
        $list.append($loading);
    }

    function showError(message) {
        var $list = $('#routes_list');
        $list.empty();

        var $error = $('<div class="routes-empty-state"></div>');
        $error.append('<i class="fas fa-exclamation-triangle" style="color: #ff6b6b;"></i>');
        $error.append('<h3 style="color: #ff6b6b;">Error</h3>');
        $error.append('<p>' + message + '</p>');
        $list.append($error);
    }

    // ========================================================================
    // URL STATE MANAGEMENT
    // ========================================================================

    function updateUrl() {
        var qs = filtersToQueryString();
        if (qs) {
            history.pushState(state, '', 'routes.php?' + qs);
        } else {
            history.pushState(state, '', 'routes.php');
        }
    }

    function parseUrlState() {
        var params = new URLSearchParams(window.location.search);

        // Origin
        if (params.get('orig')) {
            state.filters.origins = params.get('orig').split(',');
        }
        if (params.get('orig_mode')) {
            state.filters.origMode = params.get('orig_mode');
        }

        // Destination
        if (params.get('dest')) {
            state.filters.destinations = params.get('dest').split(',');
        }
        if (params.get('dest_mode')) {
            state.filters.destMode = params.get('dest_mode');
        }

        // Sort/view/page
        if (params.get('sort')) {
            state.sort = params.get('sort');
        }
        if (params.get('view')) {
            state.view = params.get('view');
        }
        if (params.get('page')) {
            state.page = parseInt(params.get('page')) || 1;
        }

        // Populate UI from state
        populateFiltersFromState();
    }

    function populateFiltersFromState() {
        // Render tags
        renderTags('origin');
        renderTags('dest');

        // Update mode pills
        $('.routes-mode-pill[data-target="origin"]').removeClass('active');
        $('.routes-mode-pill[data-target="origin"][data-mode="' + state.filters.origMode + '"]').addClass('active');

        $('.routes-mode-pill[data-target="dest"]').removeClass('active');
        $('.routes-mode-pill[data-target="dest"][data-mode="' + state.filters.destMode + '"]').addClass('active');

        // Update chips
        renderFilterChips();
        updateClearButton();
    }

    // ========================================================================
    // CLEAR FILTERS
    // ========================================================================

    function clearAllFilters() {
        state.filters.origins = [];
        state.filters.destinations = [];
        state.filters.origMode = 'airport';
        state.filters.destMode = 'airport';
        state.page = 1;

        populateFiltersFromState();
        showNoFiltersState();
        updateUrl();
    }

    function updateClearButton() {
        if (hasActiveFilters()) {
            $('#routes_clear_btn').show();
        } else {
            $('#routes_clear_btn').hide();
        }
    }

    // ========================================================================
    // HISTORY API
    // ========================================================================

    window.addEventListener('popstate', function(e) {
        if (e.state) {
            console.log('[Routes] popstate:', e.state);
            state = e.state;
            populateFiltersFromState();
            if (hasActiveFilters()) {
                doSearch();
            } else {
                showNoFiltersState();
            }
        }
    });

    // ========================================================================
    // UTILITY FUNCTIONS
    // ========================================================================

    function formatDate(dateStr) {
        if (!dateStr) return 'N/A';
        var d = new Date(dateStr);
        return d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
    }

    function formatRelativeDate(dateStr) {
        if (!dateStr) return 'N/A';
        var d = new Date(dateStr);
        var now = new Date();
        var diffMs = now - d;
        var diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24));

        if (diffDays === 0) {
            return PERTII18n.t('common.today') || 'today';
        } else if (diffDays === 1) {
            return 'yesterday';
        } else if (diffDays < 7) {
            return diffDays + ' days ago';
        } else if (diffDays < 30) {
            var weeks = Math.floor(diffDays / 7);
            return weeks + ' week' + (weeks > 1 ? 's' : '') + ' ago';
        } else if (diffDays < 365) {
            var months = Math.floor(diffDays / 30);
            return months + ' month' + (months > 1 ? 's' : '') + ' ago';
        } else {
            var years = Math.floor(diffDays / 365);
            return years + ' year' + (years > 1 ? 's' : '') + ' ago';
        }
    }

})();
