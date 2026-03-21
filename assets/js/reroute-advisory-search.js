/**
 * reroute-advisory-search.js
 *
 * Reroute/FCA Advisory Parser module for PERTI Route Planning.
 * Fetches FAA reroute advisories (ROUTE RQD/RMD, FCA RQD), parses routes
 * using RouteAdvisoryParser, and allows adding/plotting routes in the route plotter.
 *
 * Dependencies:
 * - jQuery
 * - PERTII18n (i18n)
 * - RouteAdvisoryParser (route-advisory-parser.js)
 */

const RerouteAdvisorySearch = (function() {
    'use strict';

    // =========================================================================
    // MODULE STATE
    // =========================================================================

    let initialized = false;
    let currentAdvisory = null;
    let loading = false;

    // =========================================================================
    // INITIALIZATION
    // =========================================================================

    function init() {
        if (initialized) return;
        bindEvents();
        initialized = true;
        console.log('[Reroute] Module initialized');
    }

    // =========================================================================
    // EVENT BINDING
    // =========================================================================

    function bindEvents() {
        // Fetch by URL
        $('#reroute_fetch_url_btn').off('click').on('click', function() {
            var url = $('#reroute_url_input').val().trim();
            if (!url) return;
            fetchAdvisory({ url: url });
        });

        // Fetch by date + advisory number
        $('#reroute_fetch_date_btn').off('click').on('click', function() {
            var date = $('#reroute_date_input').val().trim();
            var advn = $('#reroute_advn_input').val().trim();
            if (!date || !advn) return;
            var faaDate = formatDateForFAA(date);
            if (!faaDate) return;
            fetchAdvisory({ date: faaDate, advn: advn });
        });

        // Enter key in URL field
        $('#reroute_url_input').off('keypress').on('keypress', function(e) {
            if (e.which === 13) $('#reroute_fetch_url_btn').click();
        });

        // Enter key in advisory number field
        $('#reroute_advn_input').off('keypress').on('keypress', function(e) {
            if (e.which === 13) $('#reroute_fetch_date_btn').click();
        });

        // Bulk add selected
        $('#reroute_add_selected').off('click').on('click', addSelectedToTextarea);

        // Bulk plot selected
        $('#reroute_plot_selected').off('click').on('click', plotSelected);

        // Delegate: section toggle (collapse/expand)
        $('#reroute_results').off('click', '.reroute-section-header').on('click', '.reroute-section-header', function() {
            $(this).closest('.reroute-section').toggleClass('collapsed');
        });

        // Delegate: individual route add button
        $('#reroute_results').off('click', '.reroute-route-add').on('click', '.reroute-route-add', function(e) {
            e.stopPropagation();
            var route = $(this).closest('.reroute-route-row').data('plotroute');
            if (route) addRoutes([route], true);
        });

        // Delegate: individual route plot button
        $('#reroute_results').off('click', '.reroute-route-plot').on('click', '.reroute-route-plot', function(e) {
            e.stopPropagation();
            var route = $(this).closest('.reroute-route-row').data('plotroute');
            if (route) {
                addRoutes([route], false);
                $('#plot_r').click();
                showToast(PERTII18n.t('rerouteAdvisory.plottingRoute'), 'success');
            }
        });

        // Delegate: section "Add All" button
        $('#reroute_results').off('click', '.reroute-section-addall').on('click', '.reroute-section-addall', function(e) {
            e.stopPropagation();
            var $section = $(this).closest('.reroute-section');
            var routes = [];
            $section.find('.reroute-route-row').each(function() {
                routes.push($(this).data('plotroute'));
            });
            addRoutes(routes, true);
        });

        // Delegate: route checkbox change
        $('#reroute_results').off('change', '.reroute-route-check').on('change', '.reroute-route-check', updateBulkState);
    }

    // =========================================================================
    // FETCH
    // =========================================================================

    function fetchAdvisory(params) {
        if (loading) return;
        loading = true;

        var $results = $('#reroute_results');
        $results.html(
            '<div class="reroute-loading">' +
                '<i class="fas fa-spinner fa-spin"></i> ' +
                PERTII18n.t('rerouteAdvisory.fetching') +
            '</div>'
        );

        var queryParams = {};
        if (params.url) {
            queryParams.url = params.url;
        } else {
            queryParams.date = params.date;
            queryParams.advn = params.advn;
        }

        $.ajax({
            url: 'api/data/reroute_advisory.php',
            method: 'GET',
            data: queryParams,
            dataType: 'json',
            timeout: 20000,
        })
        .done(function(resp) {
            if (resp.status === 'success' && resp.advisory) {
                currentAdvisory = resp.advisory;
                renderAdvisory(resp.advisory);
            } else {
                showError(resp.message || PERTII18n.t('rerouteAdvisory.fetchFailed'));
            }
        })
        .fail(function(xhr) {
            var msg = PERTII18n.t('rerouteAdvisory.fetchFailed');
            if (xhr.responseJSON && xhr.responseJSON.message) {
                msg = xhr.responseJSON.message;
            }
            showError(msg);
        })
        .always(function() {
            loading = false;
        });
    }

    // =========================================================================
    // RENDERING
    // =========================================================================

    function renderAdvisory(advisory) {
        var $results = $('#reroute_results');
        $results.empty();

        // Advisory header
        var headerHtml = '<div class="reroute-advisory-header">';
        if (advisory.number) {
            headerHtml += '<span class="reroute-advzy-badge">ADVZY ' + pad3(advisory.number) + '</span>';
        }
        if (advisory.type) {
            headerHtml += '<span class="reroute-type-badge">' + escapeHtml(advisory.type) + '</span>';
        }
        if (advisory.date) {
            headerHtml += '<span class="reroute-date">' + escapeHtml(advisory.date) + '</span>';
        }
        if (advisory.facilities) {
            headerHtml += '<span class="reroute-facility-badge">' + escapeHtml(advisory.facilities) + '</span>';
        }
        headerHtml += '</div>';

        // Metadata lines
        var metaLines = [];
        if (advisory.name) metaLines.push('<strong>NAME:</strong> ' + escapeHtml(advisory.name));
        if (advisory.valid) metaLines.push('<strong>VALID:</strong> ' + escapeHtml(advisory.valid));
        if (advisory.reason) metaLines.push('<strong>REASON:</strong> ' + escapeHtml(advisory.reason));
        if (advisory.include_traffic) metaLines.push('<strong>TRAFFIC:</strong> ' + escapeHtml(advisory.include_traffic));
        if (advisory.constrained_area) metaLines.push('<strong>AREA:</strong> ' + escapeHtml(advisory.constrained_area));

        if (metaLines.length > 0) {
            headerHtml += '<div class="reroute-meta">' + metaLines.join('<br>') + '</div>';
        }

        $results.append(headerHtml);

        // Parse routes from the raw text using RouteAdvisoryParser
        var parsed = [];
        if (advisory.routes_text && typeof RouteAdvisoryParser !== 'undefined') {
            parsed = RouteAdvisoryParser.parse(advisory.routes_text);
        }

        if (parsed.length === 0) {
            $results.append('<div class="reroute-empty">' + PERTII18n.t('rerouteAdvisory.noRoutes') + '</div>');
            return;
        }

        // Group routes by destination code
        var groups = {};
        var groupOrder = [];
        for (var i = 0; i < parsed.length; i++) {
            var r = parsed[i];
            var destKey = r.dest || 'UNSPECIFIED';
            if (!groups[destKey]) {
                groups[destKey] = [];
                groupOrder.push(destKey);
            }
            groups[destKey].push(r);
        }

        // Render each destination group as a section
        for (var gi = 0; gi < groupOrder.length; gi++) {
            var destCode = groupOrder[gi];
            var groupRoutes = groups[destCode];
            $results.append(renderSection(destCode, groupRoutes));
        }

        // Summary
        $results.append(
            '<div class="reroute-summary">' +
                groupOrder.length + ' ' + PERTII18n.t('rerouteAdvisory.destinations') + ', ' +
                parsed.length + ' ' + PERTII18n.t('rerouteAdvisory.routes') +
            '</div>'
        );

        updateBulkState();
    }

    function renderSection(destCode, routes) {
        var html = '<div class="reroute-section">';

        // Section header
        html += '<div class="reroute-section-header">';
        html += '<div class="d-flex align-items-center">';
        html += '<i class="fas fa-chevron-down reroute-section-chevron mr-2"></i>';
        html += '<span class="reroute-section-dest">' + escapeHtml(destCode) + '</span>';
        html += '<span class="reroute-section-count ml-2">(' + routes.length + ')</span>';
        html += '</div>';
        html += '<button class="btn btn-outline-warning btn-sm reroute-section-addall" title="' + PERTII18n.t('rerouteAdvisory.addAll') + '">';
        html += '<i class="fas fa-plus mr-1"></i>' + PERTII18n.t('rerouteAdvisory.addAll');
        html += '</button>';
        html += '</div>';

        // Routes
        html += '<div class="reroute-section-body">';
        for (var i = 0; i < routes.length; i++) {
            var r = routes[i];
            var plotRoute = buildPlotRoute(r);
            var displayRoute = r.route_string;
            var originLabel = r.origin || '';
            if (r.origin_filter) originLabel += '(' + r.origin_filter + ')';

            html += '<div class="reroute-route-row" data-plotroute="' + escapeAttr(plotRoute) + '">';
            html += '<input type="checkbox" class="reroute-route-check mr-2">';
            html += '<span class="reroute-route-origin" title="' + escapeAttr(originLabel) + '">' + escapeHtml(originLabel || '-') + '</span>';
            html += '<span class="reroute-route-string" title="' + escapeAttr(displayRoute) + '">' + escapeHtml(displayRoute) + '</span>';
            html += '<div class="reroute-route-actions">';
            html += '<button class="btn btn-outline-warning reroute-route-add" title="' + PERTII18n.t('rerouteAdvisory.addToTextarea') + '"><i class="fas fa-plus"></i></button>';
            html += '<button class="btn btn-outline-success reroute-route-plot" title="' + PERTII18n.t('rerouteAdvisory.plotRoute') + '"><i class="fas fa-pencil-alt"></i></button>';
            html += '</div>';
            html += '</div>';
        }
        html += '</div>';

        html += '</div>';
        return html;
    }

    /**
     * Build a plottable route string by prepending origin and appending dest airports.
     * Handles both ICAO (4-letter) and FAA LID (3-letter) codes.
     * Excludes ARTCC identifiers (Z-prefix like ZDC, ZNY + CZY).
     */
    function buildPlotRoute(r) {
        var tokens = r.route_string.trim().split(/\s+/);
        var origin = r.origin || '';
        var dest = r.dest || '';

        // Only prepend if it looks like an airport (not an ARTCC)
        if (origin && isAirportCode(origin)) {
            var firstToken = tokens[0] ? tokens[0].toUpperCase() : '';
            if (firstToken !== origin.toUpperCase()) {
                tokens.unshift(origin);
            }
        }

        // Only append if it looks like an airport
        if (dest && isAirportCode(dest)) {
            var lastToken = tokens[tokens.length - 1] ? tokens[tokens.length - 1].toUpperCase() : '';
            if (lastToken !== dest.toUpperCase()) {
                tokens.push(dest);
            }
        }

        return tokens.join(' ');
    }

    /**
     * Check if a code looks like an airport (not an ARTCC).
     * ARTCCs: Z-prefix (ZDC, ZNY, ZBW) except ZZZ-type, plus CZY (Canadian).
     * Airports: 3 or 4 alpha chars (FAA LID or ICAO), not starting with Z unless 4+ chars.
     */
    function isAirportCode(code) {
        if (!code) return false;
        code = code.toUpperCase();
        // Skip ARTCC codes: 3-letter Z-prefix
        if (/^Z[A-Z]{2}$/.test(code)) return false;
        // Skip Canadian FIR codes (CZY, etc.)
        if (/^CZ[A-Z]$/.test(code)) return false;
        // Accept 3-4 letter alpha codes and ICAO codes
        if (/^[A-Z]{3,4}$/.test(code)) return true;
        return false;
    }

    function showError(message) {
        $('#reroute_results').html(
            '<div class="reroute-error">' +
                '<i class="fas fa-exclamation-triangle mr-2"></i>' +
                escapeHtml(message) +
            '</div>'
        );
    }

    // =========================================================================
    // ACTIONS
    // =========================================================================

    function getSelectedRoutes() {
        var routes = [];
        $('#reroute_results .reroute-route-check:checked').each(function() {
            var plotRoute = $(this).closest('.reroute-route-row').data('plotroute');
            if (plotRoute) routes.push(plotRoute);
        });
        return routes;
    }

    function addSelectedToTextarea() {
        var routes = getSelectedRoutes();
        if (routes.length === 0) {
            showToast(PERTII18n.t('rerouteAdvisory.noneSelected'), 'info');
            return;
        }
        addRoutes(routes, true);
    }

    function plotSelected() {
        var routes = getSelectedRoutes();
        if (routes.length === 0) {
            showToast(PERTII18n.t('rerouteAdvisory.noneSelected'), 'info');
            return;
        }
        addRoutes(routes, false);
        $('#plot_r').click();
        showToast(PERTII18n.t('rerouteAdvisory.plottingRoutes', { count: routes.length }), 'success');
    }

    function addRoutes(routes, showFeedback) {
        if (!routes || routes.length === 0) return 0;

        var $textarea = $('#routeSearch');
        var currentVal = $textarea.val().trim();

        // Build set of existing routes for dedup
        var existing = new Set();
        if (currentVal) {
            currentVal.split('\n').forEach(function(line) {
                var t = line.trim().toUpperCase();
                if (t) existing.add(t);
            });
        }

        var newRoutes = [];
        var seen = new Set();
        for (var i = 0; i < routes.length; i++) {
            var norm = routes[i].trim().toUpperCase();
            if (norm && !existing.has(norm) && !seen.has(norm)) {
                seen.add(norm);
                newRoutes.push(routes[i]);
            }
        }

        if (newRoutes.length === 0) {
            if (showFeedback) showToast(PERTII18n.t('rerouteAdvisory.allAlreadyAdded'), 'info');
            return 0;
        }

        var newStr = newRoutes.join('\n');
        $textarea.val(currentVal ? currentVal + '\n' + newStr : newStr);

        if (showFeedback) {
            var skipped = routes.length - newRoutes.length;
            var msg = PERTII18n.t('rerouteAdvisory.addedRoutes', { count: newRoutes.length });
            if (skipped > 0) {
                msg += ' ' + PERTII18n.t('rerouteAdvisory.duplicatesSkipped', { count: skipped });
            }
            showToast(msg, 'success');
        }

        return newRoutes.length;
    }

    function updateBulkState() {
        var checked = $('#reroute_results .reroute-route-check:checked').length;
        $('#reroute_add_selected, #reroute_plot_selected').prop('disabled', checked === 0);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    function formatDateForFAA(dateStr) {
        var parts = dateStr.split('-');
        if (parts.length === 3) {
            return parts[1] + parts[2] + parts[0];
        }
        if (/^\d{8}$/.test(dateStr)) return dateStr;
        return null;
    }

    function pad3(n) {
        return String(n).padStart(3, '0');
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function escapeAttr(str) {
        return escapeHtml(str).replace(/'/g, '&#39;');
    }

    function showToast(message, type) {
        var bgColor = type === 'success' ? '#28a745' :
            type === 'error' ? '#dc3545' :
                type === 'info' ? '#17a2b8' : '#e67e22';

        var $toast = $('<div class="reroute-toast">' + escapeHtml(message) + '</div>');
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
        setTimeout(function() { $toast.css('opacity', 1); }, 10);
        setTimeout(function() {
            $toast.css('opacity', 0);
            setTimeout(function() { $toast.remove(); }, 200);
        }, 2000);
    }

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    return {
        init: init,
        fetchAdvisory: fetchAdvisory,
        getAdvisory: function() { return currentAdvisory; },
    };

})();
