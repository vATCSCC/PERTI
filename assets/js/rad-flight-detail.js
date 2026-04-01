/**
 * RAD Flight Detail Module
 * Displays expanded flight info for selected flights
 */
window.RADFlightDetail = (function() {
    var selectedFlights = [];

    function init() {
        bindEvents();

        // Listen for flight selections
        RADEventBus.on('flight:selected', function(data) {
            addFlight(data);
        });

        // Listen for amendment updates
        RADEventBus.on('amendment:updated', function(data) {
            updateAmendmentStatus(data);
        });
    }

    function bindEvents() {
        $('#rad_btn_select_all_detail').on('click', selectAll);
        $('#rad_btn_select_none_detail').on('click', selectNone);
        $('#rad_btn_remove_selected').on('click', removeSelected);

        // Row click to highlight on map
        $(document).on('click', '#rad_detail_tbody tr', function() {
            var gufi = $(this).data('gufi');
            var flight = selectedFlights.find(function(f) { return f.gufi === gufi; });
            if (flight) {
                RADEventBus.emit('flight:highlighted', flight);
                $('#rad_detail_tbody tr').removeClass('table-active');
                $(this).addClass('table-active');
            }
        });

        // Route history button
        $(document).on('click', '.rad-btn-history', function(e) {
            e.stopPropagation();
            var gufi = $(this).closest('tr').data('gufi');
            showRouteHistory(gufi);
        });
    }

    function addFlight(flight) {
        // Check if already added
        var exists = selectedFlights.some(function(f) { return f.gufi === flight.gufi; });
        if (exists) {
            PERTIDialog.warning(PERTII18n.t('rad.detail.alreadyAdded', { callsign: flight.callsign }));
            return;
        }

        selectedFlights.push(flight);
        renderTable();
        updateBadge();
    }

    function renderTable() {
        var tbody = $('#rad_detail_tbody');
        tbody.empty();

        if (selectedFlights.length === 0) {
            tbody.html('<tr><td colspan="10" class="text-center text-muted">' + PERTII18n.t('rad.detail.noFlights') + '</td></tr>');
            return;
        }

        selectedFlights.forEach(function(flight) {
            tbody.append(renderRow(flight));
        });
    }

    function renderRow(flight) {
        var row = $('<tr data-gufi="' + flight.gufi + '">');

        row.append('<td><input type="checkbox" class="rad-detail-cb"></td>');
        row.append('<td>' + (flight.callsign || '') + '</td>');
        row.append('<td>' + (flight.origin || '') + ' / ' + (flight.dest || '') + '</td>');
        row.append('<td>' + (flight.tracon || '') + '</td>');
        row.append('<td>' + (flight.center || '') + '</td>');
        row.append('<td>' + getAmendmentBadge(flight.amendment_status) + '</td>');
        row.append('<td class="text-truncate" style="max-width:200px;" title="' + (flight.route || '') + '">' + (flight.route || '') + '</td>');
        row.append('<td>' + (flight.actype || '') + '/' + (flight.weight_class || '') + '</td>');
        row.append('<td><div class="sub-top">ETD: ' + formatTime(flight.etd_utc) + '</div><div class="sub-bot">ETA: ' + formatTime(flight.eta_utc) + '</div></td>');
        row.append('<td>' + getPhaseBadge(flight.phase) + '</td>');
        row.append('<td><button class="btn btn-sm btn-outline-secondary rad-btn-history">' + PERTII18n.t('rad.detail.history') + '</button></td>');

        return row;
    }

    function getAmendmentBadge(status) {
        if (!status) return '<span class="rad-badge rad-badge-default">NONE</span>';

        var badgeClass = 'rad-badge-default';
        if (status === 'DRAFT') badgeClass = 'rad-badge-info';
        else if (status === 'SENT') badgeClass = 'rad-badge-warning';
        else if (status === 'DLVD') badgeClass = 'rad-badge-primary';
        else if (status === 'ACPT') badgeClass = 'rad-badge-success';
        else if (status === 'RJCT') badgeClass = 'rad-badge-danger';
        else if (status === 'EXPR') badgeClass = 'rad-badge-secondary';

        return '<span class="rad-badge ' + badgeClass + '">' + status + '</span>';
    }

    function getPhaseBadge(phase) {
        var badgeClass = 'rad-badge-default';
        var label = phase || 'UNKN';

        if (phase === 'AIRBORNE') badgeClass = 'rad-badge-success';
        else if (phase === 'PREFILED') badgeClass = 'rad-badge-info';
        else if (phase === 'DEPARTED') badgeClass = 'rad-badge-warning';
        else if (phase === 'ARRIVED') badgeClass = 'rad-badge-secondary';

        return '<span class="rad-badge ' + badgeClass + '">' + label + '</span>';
    }

    function formatTime(utcStr) {
        if (!utcStr) return '--:--';
        var d = new Date(utcStr);
        if (isNaN(d.getTime())) return '--:--';
        var hh = String(d.getUTCHours()).padStart(2, '0');
        var mm = String(d.getUTCMinutes()).padStart(2, '0');
        return hh + ':' + mm;
    }

    function selectAll() {
        $('#rad_detail_tbody .rad-detail-cb').prop('checked', true);
    }

    function selectNone() {
        $('#rad_detail_tbody .rad-detail-cb').prop('checked', false);
    }

    function removeSelected() {
        var toRemove = [];
        $('#rad_detail_tbody .rad-detail-cb:checked').each(function() {
            var gufi = $(this).closest('tr').data('gufi');
            toRemove.push(gufi);
        });

        if (toRemove.length === 0) {
            PERTIDialog.warning(PERTII18n.t('rad.detail.noSelection'));
            return;
        }

        selectedFlights = selectedFlights.filter(function(f) {
            return toRemove.indexOf(f.gufi) === -1;
        });

        toRemove.forEach(function(gufi) {
            RADEventBus.emit('flight:deselected', { gufi: gufi });
        });

        renderTable();
        updateBadge();
        PERTIDialog.success(PERTII18n.t('rad.detail.removed', { count: toRemove.length }));
    }

    function showRouteHistory(gufi) {
        $.get('api/rad/history.php', { gufi: gufi })
            .done(function(response) {
                if (response.status === 'ok') {
                    var history = response.data || [];
                    if (history.length === 0) {
                        PERTIDialog.warning(PERTII18n.t('rad.detail.noHistory'));
                        return;
                    }

                    var html = '<table class="table table-sm table-striped">' +
                        '<thead><tr><th>' + PERTII18n.t('rad.detail.timestamp') + '</th><th>' + PERTII18n.t('rad.detail.route') + '</th><th>' + PERTII18n.t('rad.detail.source') + '</th></tr></thead>' +
                        '<tbody>';

                    history.forEach(function(entry) {
                        html += '<tr>' +
                            '<td>' + formatDateTime(entry.timestamp) + '</td>' +
                            '<td class="text-monospace">' + (entry.route || '') + '</td>' +
                            '<td>' + (entry.source || '') + '</td>' +
                            '</tr>';
                    });

                    html += '</tbody></table>';

                    Swal.fire({
                        title: PERTII18n.t('rad.detail.routeHistory'),
                        html: html,
                        width: '80%',
                        showCloseButton: true,
                        showConfirmButton: false
                    });
                } else {
                    PERTIDialog.warning(response.message || PERTII18n.t('error.loadFailed'));
                }
            })
            .fail(function() {
                PERTIDialog.warning(PERTII18n.t('error.networkError'));
            });
    }

    function formatDateTime(utcStr) {
        if (!utcStr) return '';
        var d = new Date(utcStr);
        if (isNaN(d.getTime())) return utcStr;
        var date = d.getUTCFullYear() + '-' +
                   String(d.getUTCMonth() + 1).padStart(2, '0') + '-' +
                   String(d.getUTCDate()).padStart(2, '0');
        var time = String(d.getUTCHours()).padStart(2, '0') + ':' +
                   String(d.getUTCMinutes()).padStart(2, '0') + ':' +
                   String(d.getUTCSeconds()).padStart(2, '0');
        return date + ' ' + time + 'Z';
    }

    function updateAmendmentStatus(data) {
        var flight = selectedFlights.find(function(f) { return f.gufi === data.gufi; });
        if (flight) {
            flight.amendment_status = data.status;
            renderTable();
        }
    }

    function updateBadge() {
        $('#rad_detail_badge').text(selectedFlights.length);
        if (selectedFlights.length > 0) {
            $('#rad_detail_badge').show();
        } else {
            $('#rad_detail_badge').hide();
        }
    }

    function getSelected() {
        var selected = [];
        $('#rad_detail_tbody .rad-detail-cb:checked').each(function() {
            var gufi = $(this).closest('tr').data('gufi');
            var flight = selectedFlights.find(function(f) { return f.gufi === gufi; });
            if (flight) selected.push(flight);
        });
        return selected;
    }

    function getFlights() {
        return selectedFlights;
    }

    return {
        init: init,
        addFlight: addFlight,
        getSelected: getSelected,
        getFlights: getFlights
    };
})();
