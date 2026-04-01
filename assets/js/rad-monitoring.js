/**
 * RAD Monitoring Module
 * Amendment tracking with compliance polling
 */
window.RADMonitoring = (function() {
    var pollInterval = null;
    var amendments = [];
    var previousExpiredCount = 0;
    var currentFilter = 'all';
    var currentTMIFilter = '';

    function init() {
        bindEvents();

        // Listen for new amendments
        RADEventBus.on('amendment:created', function() {
            refresh();
        });

        RADEventBus.on('amendment:sent', function() {
            refresh();
        });

        // Initial load
        refresh();
    }

    function bindEvents() {
        $('#rad_filter_all').on('click', function() { setFilter('all'); });
        $('#rad_filter_pending').on('click', function() { setFilter('pending'); });
        $('#rad_filter_noncompliant').on('click', function() { setFilter('noncompliant'); });
        $('#rad_filter_alerts').on('click', function() { setFilter('alerts'); });

        $('#rad_tmi_filter').on('change', function() {
            currentTMIFilter = $(this).val();
            renderTable();
        });

        // Action buttons
        $(document).on('click', '.rad-btn-resend', function() {
            var id = $(this).data('id');
            resendAmendment(id);
        });

        $(document).on('click', '.rad-btn-send', function() {
            var id = $(this).data('id');
            sendDraft(id);
        });

        $(document).on('click', '.rad-btn-delete', function() {
            var id = $(this).data('id');
            deleteAmendment(id);
        });

        // Load TMI programs for filter
        loadTMIPrograms();
    }

    function startPolling() {
        if (pollInterval) return;
        refresh();
        pollInterval = setInterval(refresh, 30000);
    }

    function stopPolling() {
        if (pollInterval) {
            clearInterval(pollInterval);
            pollInterval = null;
        }
    }

    function refresh() {
        $.get('api/rad/compliance.php')
            .done(function(response) {
                if (response && response.status === 'ok') {
                    amendments = (response.data && response.data.amendments) || [];
                    checkForNewExpired();
                    renderSummary();
                    renderTable();
                    renderAggregateBar();
                    updateBadge();
                }
                // Silently ignore non-success (DB tables may not exist yet)
            })
            .fail(function(jqXHR) {
                // Silently ignore — API may return 404/500 before migrations run
                if (jqXHR.status !== 404 && jqXHR.status !== 500) {
                    console.warn('RAD compliance poll failed:', jqXHR.status);
                }
            });
    }

    function checkForNewExpired() {
        var expiredCount = amendments.filter(function(a) { return a.status === 'EXPR'; }).length;
        if (expiredCount > previousExpiredCount) {
            var newExpired = expiredCount - previousExpiredCount;
            amendments.filter(function(a) { return a.status === 'EXPR'; }).slice(0, newExpired).forEach(function(a) {
                PERTIDialog.warning(PERTII18n.t('rad.monitoring.amendmentExpired', { callsign: a.callsign }));
            });
        }
        previousExpiredCount = expiredCount;
    }

    function renderSummary() {
        var counts = {
            total: amendments.length,
            draft: 0,
            sent: 0,
            dlvd: 0,
            acpt: 0,
            rjct: 0,
            expr: 0
        };

        amendments.forEach(function(a) {
            var status = (a.status || '').toUpperCase();
            if (status === 'DRAFT') counts.draft++;
            else if (status === 'SENT') counts.sent++;
            else if (status === 'DLVD') counts.dlvd++;
            else if (status === 'ACPT') counts.acpt++;
            else if (status === 'RJCT') counts.rjct++;
            else if (status === 'EXPR') counts.expr++;
        });

        var html = '';
        html += buildCard(PERTII18n.t('rad.monitoring.total'), counts.total, 'default');
        html += buildCard(PERTII18n.t('rad.monitoring.draft'), counts.draft, 'info');
        html += buildCard(PERTII18n.t('rad.monitoring.sent'), counts.sent, 'warning');
        html += buildCard(PERTII18n.t('rad.monitoring.delivered'), counts.dlvd, 'primary');
        html += buildCard(PERTII18n.t('rad.monitoring.accepted'), counts.acpt, 'success');
        html += buildCard(PERTII18n.t('rad.monitoring.rejected'), counts.rjct, 'danger');
        html += buildCard(PERTII18n.t('rad.monitoring.expired'), counts.expr, 'secondary');

        $('#rad_summary_cards').html(html);
    }

    function buildCard(label, count, type) {
        return '<div class="rad-card rad-card-' + type + '">' +
            '<div class="rad-card-count">' + count + '</div>' +
            '<div class="rad-card-label">' + label + '</div>' +
            '</div>';
    }

    function renderTable() {
        var filtered = getFilteredAmendments();
        var tbody = $('#rad_monitoring_tbody');
        tbody.empty();

        if (filtered.length === 0) {
            tbody.html('<tr><td colspan="10" class="text-center text-muted">' + PERTII18n.t('rad.monitoring.noAmendments') + '</td></tr>');
            return;
        }

        filtered.forEach(function(amendment) {
            tbody.append(renderRow(amendment));
        });
    }

    function renderRow(a) {
        var rowClass = a.status === 'EXPR' ? 'rad-alert-row' : '';
        var row = $('<tr class="' + rowClass + '">');

        row.append('<td>' + (a.callsign || '') + '</td>');
        row.append('<td>' + (a.origin || '') + ' / ' + (a.dest || '') + '</td>');
        row.append('<td>' + getStatusBadge(a.status) + '</td>');
        row.append('<td>' + getRRSTATBadge(a.rrstat) + '</td>');
        row.append('<td>' + (a.tmi_id || '--') + '</td>');
        row.append('<td class="text-monospace text-truncate" style="max-width:150px;" title="' + (a.assigned_route || '') + '">' + (a.assigned_route || '') + '</td>');
        row.append('<td class="text-monospace text-truncate" style="max-width:150px;" title="' + (a.filed_route || '') + '">' + (a.filed_route || '') + '</td>');
        row.append('<td>' + formatDateTime(a.sent_at) + '</td>');
        row.append('<td>' + (a.delivery_status || '--') + '</td>');
        row.append('<td>' + getActionButtons(a) + '</td>');

        return row;
    }

    function getStatusBadge(status) {
        var badgeClass = 'rad-badge-default';
        var label = status || 'UNKN';

        if (status === 'DRAFT') badgeClass = 'rad-badge-info';
        else if (status === 'SENT') badgeClass = 'rad-badge-warning';
        else if (status === 'DLVD') badgeClass = 'rad-badge-primary';
        else if (status === 'ACPT') badgeClass = 'rad-badge-success';
        else if (status === 'RJCT') badgeClass = 'rad-badge-danger';
        else if (status === 'EXPR') badgeClass = 'rad-badge-secondary';

        return '<span class="rad-badge ' + badgeClass + '">' + label + '</span>';
    }

    function getRRSTATBadge(rrstat) {
        if (!rrstat) return '<span class="rad-badge rad-badge-default">UNKN</span>';

        var badgeClass = 'rad-badge-default';
        var label = rrstat;

        if (rrstat === 'C') {
            badgeClass = 'rad-badge-success';
            label = 'COMPLIANT';
        } else if (rrstat === 'NC') {
            badgeClass = 'rad-badge-danger';
            label = 'NON-COMPLIANT';
        } else if (rrstat === 'EXC') {
            badgeClass = 'rad-badge-warning';
            label = 'EXCEPTION';
        }

        return '<span class="rad-badge ' + badgeClass + '">' + label + '</span>';
    }

    function getActionButtons(a) {
        var html = '';

        if (a.status === 'SENT' || a.status === 'DLVD') {
            html += '<button class="btn btn-sm btn-outline-primary rad-btn-resend mr-1" data-id="' + a.id + '">' + PERTII18n.t('rad.monitoring.resend') + '</button>';
        }

        if (a.status === 'DRAFT') {
            html += '<button class="btn btn-sm btn-outline-success rad-btn-send mr-1" data-id="' + a.id + '">' + PERTII18n.t('rad.monitoring.send') + '</button>';
        }

        if (a.status === 'DRAFT' || a.status === 'SENT') {
            html += '<button class="btn btn-sm btn-outline-danger rad-btn-delete" data-id="' + a.id + '">' + PERTII18n.t('common.delete') + '</button>';
        }

        return html;
    }

    function formatDateTime(utcStr) {
        if (!utcStr) return '--';
        var d = new Date(utcStr);
        if (isNaN(d.getTime())) return '--';
        var hh = String(d.getUTCHours()).padStart(2, '0');
        var mm = String(d.getUTCMinutes()).padStart(2, '0');
        var ss = String(d.getUTCSeconds()).padStart(2, '0');
        return hh + ':' + mm + ':' + ss;
    }

    function setFilter(filter) {
        currentFilter = filter;
        $('#rad_filter_all, #rad_filter_pending, #rad_filter_noncompliant, #rad_filter_alerts').removeClass('active');
        $('#rad_filter_' + filter).addClass('active');
        renderTable();
    }

    function getFilteredAmendments() {
        var filtered = amendments;

        // TMI filter
        if (currentTMIFilter) {
            filtered = filtered.filter(function(a) { return a.tmi_id === currentTMIFilter; });
        }

        // Status filter
        if (currentFilter === 'pending') {
            filtered = filtered.filter(function(a) {
                return a.status === 'SENT' || a.status === 'DLVD';
            });
        } else if (currentFilter === 'noncompliant') {
            filtered = filtered.filter(function(a) {
                return a.rrstat === 'NC';
            });
        } else if (currentFilter === 'alerts') {
            filtered = filtered.filter(function(a) {
                return a.status === 'EXPR' || a.status === 'RJCT';
            });
        }

        return filtered;
    }

    function renderAggregateBar() {
        var compliant = 0;
        var noncompliant = 0;
        var unknown = 0;
        var exception = 0;

        amendments.forEach(function(a) {
            if (a.rrstat === 'C') compliant++;
            else if (a.rrstat === 'NC') noncompliant++;
            else if (a.rrstat === 'EXC') exception++;
            else unknown++;
        });

        var total = amendments.length || 1;
        var cPct = (compliant / total * 100).toFixed(1);
        var ncPct = (noncompliant / total * 100).toFixed(1);
        var excPct = (exception / total * 100).toFixed(1);
        var unknPct = (unknown / total * 100).toFixed(1);

        var html = '<div class="rad-aggregate-segment rad-aggregate-compliant" style="width:' + cPct + '%" title="' + PERTII18n.t('rad.monitoring.compliant') + ': ' + compliant + '"></div>' +
            '<div class="rad-aggregate-segment rad-aggregate-noncompliant" style="width:' + ncPct + '%" title="' + PERTII18n.t('rad.monitoring.nonCompliant') + ': ' + noncompliant + '"></div>' +
            '<div class="rad-aggregate-segment rad-aggregate-exception" style="width:' + excPct + '%" title="' + PERTII18n.t('rad.monitoring.exception') + ': ' + exception + '"></div>' +
            '<div class="rad-aggregate-segment rad-aggregate-unknown" style="width:' + unknPct + '%" title="' + PERTII18n.t('rad.monitoring.unknown') + ': ' + unknown + '"></div>';

        $('#rad_aggregate_bar').html(html);
    }

    function updateBadge() {
        var active = amendments.filter(function(a) {
            return a.status !== 'ACPT' && a.status !== 'RJCT' && a.status !== 'EXPR';
        }).length;

        $('#rad_monitoring_badge').text(active);
        if (active > 0) {
            $('#rad_monitoring_badge').show();
        } else {
            $('#rad_monitoring_badge').hide();
        }
    }

    function resendAmendment(id) {
        $.post('api/rad/amendment.php', { id: id, action: 'resend' })
            .done(function(response) {
                if (response.status === 'ok') {
                    PERTIDialog.success(PERTII18n.t('rad.monitoring.resent'));
                    refresh();
                } else {
                    PERTIDialog.warning(response.message || PERTII18n.t('error.resendFailed'));
                }
            })
            .fail(function() {
                PERTIDialog.warning(PERTII18n.t('error.networkError'));
            });
    }

    function sendDraft(id) {
        $.post('api/rad/amendment.php', { id: id, action: 'send' })
            .done(function(response) {
                if (response.status === 'ok') {
                    PERTIDialog.success(PERTII18n.t('rad.monitoring.sent'));
                    refresh();
                } else {
                    PERTIDialog.warning(response.message || PERTII18n.t('error.sendFailed'));
                }
            })
            .fail(function() {
                PERTIDialog.warning(PERTII18n.t('error.networkError'));
            });
    }

    function deleteAmendment(id) {
        PERTIDialog.confirm(PERTII18n.t('rad.monitoring.confirmDelete'))
            .then(function(result) {
                if (result.isConfirmed) {
                    $.ajax({
                        url: 'api/rad/amendment.php?id=' + id,
                        type: 'DELETE'
                    })
                        .done(function(response) {
                            if (response.status === 'ok') {
                                PERTIDialog.success(PERTII18n.t('common.deleted'));
                                refresh();
                            } else {
                                PERTIDialog.warning(response.message || PERTII18n.t('error.deleteFailed'));
                            }
                        })
                        .fail(function() {
                            PERTIDialog.warning(PERTII18n.t('error.networkError'));
                        });
                }
            });
    }

    function loadTMIPrograms() {
        $.get('api/tmi/gdp_preview.php')
            .done(function(response) {
                if (response.status === 'ok' && response.data) {
                    var select = $('#rad_tmi_filter');
                    select.empty();
                    select.append('<option value="">' + PERTII18n.t('rad.monitoring.allTMI') + '</option>');

                    (response.data || []).forEach(function(program) {
                        select.append('<option value="' + program.program_id + '">' + program.name + '</option>');
                    });
                }
            });
    }

    return {
        init: init,
        startPolling: startPolling,
        stopPolling: stopPolling
    };
})();
