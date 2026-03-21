/**
 * natots-search.js
 *
 * NATOTs Advisory Parser module for PERTI Route Planning.
 * Fetches FAA NATOTs advisories, parses departure routes by facility,
 * and allows adding/plotting routes in the route plotter.
 *
 * Dependencies:
 * - jQuery
 * - PERTII18n (i18n)
 */

const NATOTsSearch = (function() {
    'use strict';

    // ═══════════════════════════════════════════════════════════════════════════
    // MODULE STATE
    // ═══════════════════════════════════════════════════════════════════════════

    let initialized = false;
    let currentAdvisory = null;  // Parsed advisory data
    let loading = false;

    // ═══════════════════════════════════════════════════════════════════════════
    // INITIALIZATION
    // ═══════════════════════════════════════════════════════════════════════════

    function init() {
        if (initialized) return;
        bindEvents();
        initialized = true;
        console.log('[NATOTs] Module initialized');
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // EVENT BINDING
    // ═══════════════════════════════════════════════════════════════════════════

    function bindEvents() {
        // Fetch by URL
        $('#natots_fetch_url_btn').off('click').on('click', function() {
            var url = $('#natots_url_input').val().trim();
            if (!url) return;
            fetchAdvisory({ url: url });
        });

        // Fetch by date + advisory number
        $('#natots_fetch_date_btn').off('click').on('click', function() {
            var date = $('#natots_date_input').val().trim();
            var advn = $('#natots_advn_input').val().trim();
            if (!date || !advn) return;
            // Convert date from YYYY-MM-DD (HTML date input) to MMDDYYYY
            var faaDate = formatDateForFAA(date);
            if (!faaDate) return;
            fetchAdvisory({ date: faaDate, advn: advn });
        });

        // Enter key in URL field
        $('#natots_url_input').off('keypress').on('keypress', function(e) {
            if (e.which === 13) $('#natots_fetch_url_btn').click();
        });

        // Enter key in advisory number field
        $('#natots_advn_input').off('keypress').on('keypress', function(e) {
            if (e.which === 13) $('#natots_fetch_date_btn').click();
        });

        // Bulk add selected
        $('#natots_add_selected').off('click').on('click', addSelectedToTextarea);

        // Bulk plot selected
        $('#natots_plot_selected').off('click').on('click', plotSelected);

        // Delegate: section toggle (collapse/expand)
        $('#natots_results').off('click', '.natots-section-header').on('click', '.natots-section-header', function() {
            $(this).closest('.natots-section').toggleClass('collapsed');
        });

        // Delegate: individual track add button
        $('#natots_results').off('click', '.natots-track-add').on('click', '.natots-track-add', function(e) {
            e.stopPropagation();
            var route = $(this).closest('.natots-track-row').data('converted');
            if (route) addRoutes([route], true);
        });

        // Delegate: individual track plot button
        $('#natots_results').off('click', '.natots-track-plot').on('click', '.natots-track-plot', function(e) {
            e.stopPropagation();
            var route = $(this).closest('.natots-track-row').data('converted');
            if (route) {
                addRoutes([route], false);
                $('#plot_r').click();
                showToast(PERTII18n.t('natots.plottingRoute'), 'success');
            }
        });

        // Delegate: section "Add All" button
        $('#natots_results').off('click', '.natots-section-addall').on('click', '.natots-section-addall', function(e) {
            e.stopPropagation();
            var $section = $(this).closest('.natots-section');
            var routes = [];
            $section.find('.natots-track-row').each(function() {
                routes.push($(this).data('converted'));
            });
            addRoutes(routes, true);
        });

        // Delegate: track checkbox change
        $('#natots_results').off('change', '.natots-track-check').on('change', '.natots-track-check', updateBulkState);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // FETCH
    // ═══════════════════════════════════════════════════════════════════════════

    function fetchAdvisory(params) {
        if (loading) return;
        loading = true;

        var $results = $('#natots_results');
        $results.html(
            '<div class="natots-loading">' +
                '<i class="fas fa-spinner fa-spin"></i> ' +
                PERTII18n.t('natots.fetching') +
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
            url: 'api/data/natots.php',
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
                showError(resp.message || PERTII18n.t('natots.fetchFailed'));
            }
        })
        .fail(function(xhr) {
            var msg = PERTII18n.t('natots.fetchFailed');
            if (xhr.responseJSON && xhr.responseJSON.message) {
                msg = xhr.responseJSON.message;
            }
            showError(msg);
        })
        .always(function() {
            loading = false;
        });
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // RENDERING
    // ═══════════════════════════════════════════════════════════════════════════

    function renderAdvisory(advisory) {
        var $results = $('#natots_results');
        $results.empty();

        // Advisory header
        var headerHtml = '<div class="natots-advisory-header">';
        if (advisory.number) {
            headerHtml += '<span class="natots-advzy-badge">ADVZY ' + pad3(advisory.number) + '</span>';
        }
        if (advisory.date) {
            headerHtml += '<span class="natots-date">' + escapeHtml(advisory.date) + '</span>';
        }
        if (advisory.constrained_facilities) {
            headerHtml += '<span class="natots-facility-badge">' + escapeHtml(advisory.constrained_facilities) + '</span>';
        }
        headerHtml += '</div>';

        if (advisory.event_time) {
            headerHtml += '<div class="natots-event-time">' +
                '<i class="fas fa-clock mr-1"></i>' +
                escapeHtml(advisory.event_time) +
            '</div>';
        }

        $results.append(headerHtml);

        // Sections
        if (!advisory.sections || advisory.sections.length === 0) {
            $results.append('<div class="natots-empty">' + PERTII18n.t('natots.noSections') + '</div>');
            return;
        }

        for (var i = 0; i < advisory.sections.length; i++) {
            $results.append(renderSection(advisory.sections[i]));
        }

        // Summary
        $results.append(
            '<div class="natots-summary">' +
                advisory.section_count + ' ' + PERTII18n.t('natots.sections') + ', ' +
                advisory.track_count + ' ' + PERTII18n.t('natots.tracks') +
            '</div>'
        );

        updateBulkState();
    }

    function renderSection(section) {
        var html = '<div class="natots-section">';

        // Section header (clickable to collapse)
        html += '<div class="natots-section-header">';
        html += '<div class="d-flex align-items-center">';
        html += '<i class="fas fa-chevron-down natots-section-chevron mr-2"></i>';
        html += '<span class="natots-section-facility">' + escapeHtml(section.facility || '?') + '</span>';
        html += '<span class="natots-section-count ml-2">(' + section.tracks.length + ')</span>';
        html += '</div>';
        html += '<button class="btn btn-outline-primary btn-sm natots-section-addall" title="' + PERTII18n.t('natots.addAll') + '">';
        html += '<i class="fas fa-plus mr-1"></i>' + PERTII18n.t('natots.addAll');
        html += '</button>';
        html += '</div>';

        // Tracks
        html += '<div class="natots-section-body">';
        for (var i = 0; i < section.tracks.length; i++) {
            var trk = section.tracks[i];
            html += '<div class="natots-track-row" data-converted="' + escapeAttr(trk.converted_route) + '">';
            html += '<input type="checkbox" class="natots-track-check mr-2">';
            html += '<span class="natots-track-letter">' + escapeHtml(trk.letter) + '</span>';
            html += '<span class="natots-track-route" title="' + escapeAttr(trk.raw_route) + '">' + escapeHtml(trk.raw_route) + '</span>';
            html += '<div class="natots-track-actions">';
            html += '<button class="btn btn-outline-primary natots-track-add" title="' + PERTII18n.t('natots.addToTextarea') + '"><i class="fas fa-plus"></i></button>';
            html += '<button class="btn btn-outline-success natots-track-plot" title="' + PERTII18n.t('natots.plotRoute') + '"><i class="fas fa-pencil-alt"></i></button>';
            html += '</div>';
            html += '</div>';
        }
        html += '</div>';

        html += '</div>';
        return html;
    }

    function showError(message) {
        $('#natots_results').html(
            '<div class="natots-error">' +
                '<i class="fas fa-exclamation-triangle mr-2"></i>' +
                escapeHtml(message) +
            '</div>'
        );
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // ACTIONS
    // ═══════════════════════════════════════════════════════════════════════════

    function getSelectedRoutes() {
        var routes = [];
        $('#natots_results .natots-track-check:checked').each(function() {
            var converted = $(this).closest('.natots-track-row').data('converted');
            if (converted) routes.push(converted);
        });
        return routes;
    }

    function addSelectedToTextarea() {
        var routes = getSelectedRoutes();
        if (routes.length === 0) {
            showToast(PERTII18n.t('natots.noneSelected'), 'info');
            return;
        }
        addRoutes(routes, true);
    }

    function plotSelected() {
        var routes = getSelectedRoutes();
        if (routes.length === 0) {
            showToast(PERTII18n.t('natots.noneSelected'), 'info');
            return;
        }
        addRoutes(routes, false);
        $('#plot_r').click();
        showToast(PERTII18n.t('natots.plottingRoutes', { count: routes.length }), 'success');
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
            if (showFeedback) showToast(PERTII18n.t('natots.allAlreadyAdded'), 'info');
            return 0;
        }

        var newStr = newRoutes.join('\n');
        $textarea.val(currentVal ? currentVal + '\n' + newStr : newStr);

        if (showFeedback) {
            var skipped = routes.length - newRoutes.length;
            var msg = PERTII18n.t('natots.addedRoutes', { count: newRoutes.length });
            if (skipped > 0) {
                msg += ' ' + PERTII18n.t('natots.duplicatesSkipped', { count: skipped });
            }
            showToast(msg, 'success');
        }

        return newRoutes.length;
    }

    function updateBulkState() {
        var checked = $('#natots_results .natots-track-check:checked').length;
        $('#natots_add_selected, #natots_plot_selected').prop('disabled', checked === 0);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════════════════════

    function formatDateForFAA(dateStr) {
        // Convert YYYY-MM-DD to MMDDYYYY
        var parts = dateStr.split('-');
        if (parts.length === 3) {
            return parts[1] + parts[2] + parts[0];
        }
        // Already in MMDDYYYY format?
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
                type === 'info' ? '#17a2b8' : '#6f42c1';

        var $toast = $('<div class="natots-toast">' + escapeHtml(message) + '</div>');
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

    // ═══════════════════════════════════════════════════════════════════════════
    // PUBLIC API
    // ═══════════════════════════════════════════════════════════════════════════

    return {
        init: init,
        fetchAdvisory: fetchAdvisory,
        getAdvisory: function() { return currentAdvisory; },
    };

})();
