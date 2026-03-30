/**
 * NAS Event Log — client-side module
 * Fetches from /api/tmi/event-log.php with filter controls.
 */
(function() {
    'use strict';

    var API = 'api/tmi/event-log.php';
    var currentPage = 1;
    var autoTimer = null;

    var severityIcons = {
        'INFO': '<span class="text-muted" title="Info">&#9679;</span>',
        'ADVISORY': '<span class="text-warning" title="Advisory">&#9679;</span>',
        'URGENT': '<span class="text-danger" title="Urgent">&#9679;</span>',
        'CRITICAL': '<span class="text-danger font-weight-bold" title="Critical">&#9679;</span>'
    };

    function getFilters() {
        return {
            hours: $('#log-hours').val(),
            category: $('#log-category').val(),
            facility: $('#log-facility').val().trim().toUpperCase(),
            org: $('#log-org').val(),
            page: currentPage,
            per_page: 100
        };
    }

    function loadLog() {
        var params = getFilters();
        var qs = $.param(params);

        $.getJSON(API + '?' + qs, function(resp) {
            if (!resp || !resp.success) {
                $('#log-body').html('<tr><td colspan="9" class="text-center text-danger">Failed to load</td></tr>');
                return;
            }

            var entries = resp.data || [];
            var pag = resp.pagination || {};
            $('#log-count').text(pag.total + ' entries');

            if (entries.length === 0) {
                $('#log-body').html('<tr><td colspan="9" class="text-center text-muted">No events found</td></tr>');
                updatePagination(pag);
                return;
            }

            var html = '';
            entries.forEach(function(e) {
                var time = (e.event_utc || '').substring(0, 19).replace('T', ' ');
                var icon = severityIcons[e.severity] || severityIcons['INFO'];
                html += '<tr class="log-row" data-logid="' + e.log_id + '">'
                    + '<td class="small">' + time + '</td>'
                    + '<td>' + icon + '</td>'
                    + '<td><span class="badge badge-secondary">' + (e.action_category || '') + '</span></td>'
                    + '<td class="small">' + (e.action_type || '') + '</td>'
                    + '<td class="small">' + (e.program_type || '') + '</td>'
                    + '<td class="small font-weight-bold">' + (e.ctl_element || '') + '</td>'
                    + '<td class="small">' + escHtml(e.summary || '') + '</td>'
                    + '<td class="small">' + (e.issuing_facility || '') + '</td>'
                    + '<td class="small">' + (e.user_name || e.user_cid || '') + '</td>'
                    + '</tr>';

                // Expandable detail row (hidden by default)
                html += '<tr class="log-detail" style="display:none" data-logid="' + e.log_id + '">'
                    + '<td colspan="9" class="small bg-light">'
                    + buildDetail(e)
                    + '</td></tr>';
            });

            $('#log-body').html(html);
            updatePagination(pag);
        }).fail(function() {
            $('#log-body').html('<tr><td colspan="9" class="text-center text-danger">Request failed</td></tr>');
        });
    }

    function buildDetail(e) {
        var parts = [];
        if (e.effective_start_utc) parts.push('<b>Start:</b> ' + e.effective_start_utc);
        if (e.effective_end_utc) parts.push('<b>End:</b> ' + e.effective_end_utc);
        if (e.rate_value) parts.push('<b>Rate:</b> ' + e.rate_value + ' ' + (e.rate_unit || ''));
        if (e.total_flights) parts.push('<b>Flights:</b> ' + e.total_flights + ' (ctl: ' + (e.controlled_flights || 0) + ')');
        if (e.avg_delay_min) parts.push('<b>Avg delay:</b> ' + e.avg_delay_min + ' min');
        if (e.max_delay_min) parts.push('<b>Max delay:</b> ' + e.max_delay_min + ' min');
        if (e.param_cause_category) parts.push('<b>Cause:</b> ' + e.param_cause_category + (e.cause_detail ? ' - ' + e.cause_detail : ''));
        if (e.cancellation_reason) parts.push('<b>Cancel reason:</b> ' + e.cancellation_reason);
        if (e.program_id) parts.push('<b>Program:</b> #' + e.program_id);
        if (e.advisory_number) parts.push('<b>Advisory:</b> ' + e.advisory_number);
        if (e.ntml_formatted) parts.push('<hr><pre class="mb-0 small">' + escHtml(e.ntml_formatted).substring(0, 500) + '</pre>');
        return parts.length ? parts.join(' &middot; ') : '<em>No additional details</em>';
    }

    function updatePagination(pag) {
        var total = pag.pages || 1;
        $('#log-page-info').text('Page ' + (pag.page || 1) + ' of ' + total);
        $('#log-prev').prop('disabled', (pag.page || 1) <= 1);
        $('#log-next').prop('disabled', (pag.page || 1) >= total);
    }

    function escHtml(s) {
        return $('<span>').text(s).html();
    }

    // Event handlers
    $(document).ready(function() {
        loadLog();

        $('#log-refresh').on('click', function() { currentPage = 1; loadLog(); });
        $('#log-hours, #log-category, #log-org').on('change', function() { currentPage = 1; loadLog(); });
        $('#log-facility').on('keyup', function(e) { if (e.key === 'Enter') { currentPage = 1; loadLog(); } });
        $('#log-prev').on('click', function() { currentPage = Math.max(1, currentPage - 1); loadLog(); });
        $('#log-next').on('click', function() { currentPage++; loadLog(); });

        // Row expand/collapse
        $(document).on('click', '.log-row', function() {
            var id = $(this).data('logid');
            var detail = $('.log-detail[data-logid="' + id + '"]');
            detail.toggle();
        });

        // Auto-refresh toggle
        $('#log-auto').on('change', function() {
            if (this.checked) {
                autoTimer = setInterval(function() { loadLog(); }, 30000);
            } else {
                clearInterval(autoTimer);
                autoTimer = null;
            }
        });
    });
})();
