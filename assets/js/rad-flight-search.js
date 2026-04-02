/**
 * RAD Flight Search Module
 * Filters, searches, and selects flights for amendment
 */
window.RADFlightSearch = (function() {
    var currentFilters = {};
    var searchResults = [];
    var sortState = { column: null, direction: null }; // 'asc', 'desc', or null
    var debounceTimer = null;

    function init() {
        buildFilterPanel();
        bindEvents();
        loadPresets();

        // Listen for map clicks
        RADEventBus.on('map:flight-clicked', function(data) {
            if (data && data.gufi) {
                highlightFlight(data.gufi);
            }
        });
    }

    function buildFilterPanel() {
        var html = '<div class="row mb-3">' +
            '<div class="col-md-2">' +
            '<label>' + PERTII18n.t('rad.search.callsign') + '</label>' +
            '<input type="text" id="rad_filter_callsign" class="form-control" placeholder="' + PERTII18n.t('rad.search.callsignPlaceholder') + '">' +
            '</div>' +
            '<div class="col-md-2">' +
            '<label>' + PERTII18n.t('rad.search.origin') + '</label>' +
            '<input type="text" id="rad_filter_origin" class="form-control" placeholder="ICAO" maxlength="4">' +
            '</div>' +
            '<div class="col-md-2">' +
            '<label>' + PERTII18n.t('rad.search.dest') + '</label>' +
            '<input type="text" id="rad_filter_dest" class="form-control" placeholder="ICAO" maxlength="4">' +
            '</div>' +
            '<div class="col-md-2">' +
            '<label>' + PERTII18n.t('rad.search.type') + '</label>' +
            '<select id="rad_filter_type" class="form-control">' +
            '<option value="">' + PERTII18n.t('common.all') + '</option>' +
            '<option value="IFR">IFR</option>' +
            '<option value="VFR">VFR</option>' +
            '</select>' +
            '</div>' +
            '<div class="col-md-2">' +
            '<label>' + PERTII18n.t('rad.search.carrier') + '</label>' +
            '<input type="text" id="rad_filter_carrier" class="form-control" placeholder="AAL, UAL...">' +
            '</div>' +
            '<div class="col-md-2">' +
            '<label>' + PERTII18n.t('rad.search.route') + '</label>' +
            '<input type="text" id="rad_filter_route" class="form-control" placeholder="Fix/Airway">' +
            '</div>' +
            '</div>' +
            '<div class="row mb-3">' +
            '<div class="col-md-3">' +
            '<label>' + PERTII18n.t('rad.search.timeRange') + '</label>' +
            '<select id="rad_filter_time" class="form-control">' +
            '<option value="all">' + PERTII18n.t('rad.search.allFlights') + '</option>' +
            '<option value="airborne">' + PERTII18n.t('rad.search.airborne') + '</option>' +
            '<option value="departure_1h">' + PERTII18n.t('rad.search.departing1h') + '</option>' +
            '<option value="departure_2h">' + PERTII18n.t('rad.search.departing2h') + '</option>' +
            '</select>' +
            '</div>' +
            '<div class="col-md-3">' +
            '<label>' + PERTII18n.t('rad.search.preset') + '</label>' +
            '<select id="rad_filter_preset" class="form-control">' +
            '<option value="">' + PERTII18n.t('common.none') + '</option>' +
            '</select>' +
            '</div>' +
            '<div class="col-md-6 d-flex align-items-end">' +
            '<button id="rad_btn_search" class="btn btn-primary mr-2">' + PERTII18n.t('common.search') + '</button>' +
            '<button id="rad_btn_clear" class="btn btn-secondary mr-2">' + PERTII18n.t('common.clear') + '</button>' +
            '<button id="rad_btn_save_preset" class="btn btn-outline-primary">' + PERTII18n.t('rad.search.savePreset') + '</button>' +
            '</div>' +
            '</div>';

        $('#rad_filter_panel').html(html);
    }

    function bindEvents() {
        $('#rad_btn_search').on('click', executeSearch);
        $('#rad_btn_clear').on('click', clearFilters);
        $('#rad_btn_save_preset').on('click', savePreset);
        $('#rad_filter_preset').on('change', loadPreset);
        $('#rad_btn_select_all').on('click', selectAll);
        $('#rad_btn_add_to_detail').on('click', addToDetail);

        // Debounced callsign search
        $('#rad_filter_callsign').on('keyup', function() {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(executeSearch, 300);
        });

        // Enter key triggers search
        $('#rad_filter_panel input').on('keypress', function(e) {
            if (e.which === 13) executeSearch();
        });

        // Sort headers
        $('#rad_search_thead th[data-sort]').on('click', function() {
            var column = $(this).data('sort');
            handleSort(column);
        });
    }

    function executeSearch() {
        currentFilters = {
            cs: $('#rad_filter_callsign').val().trim(),
            orig: $('#rad_filter_origin').val().trim().toUpperCase(),
            dest: $('#rad_filter_dest').val().trim().toUpperCase(),
            type: $('#rad_filter_type').val(),
            carrier: $('#rad_filter_carrier').val().trim().toUpperCase(),
            route: $('#rad_filter_route').val().trim().toUpperCase()
        };

        // Time range filter → compute time_start/time_end
        var timeVal = $('#rad_filter_time').val();
        if (timeVal === 'airborne') {
            currentFilters.status = 'ACTIVE';
        } else if (timeVal === 'departure_1h') {
            currentFilters.time_start = new Date().toISOString();
            currentFilters.time_end = new Date(Date.now() + 3600000).toISOString();
        } else if (timeVal === 'departure_2h') {
            currentFilters.time_start = new Date().toISOString();
            currentFilters.time_end = new Date(Date.now() + 7200000).toISOString();
        }

        $.get('api/rad/search.php', currentFilters)
            .done(function(response) {
                if (response.status === 'ok') {
                    searchResults = response.data || [];
                    renderResults();
                    $('#rad_search_count').text(searchResults.length);
                } else {
                    PERTIDialog.warning(response.message || PERTII18n.t('error.searchFailed'));
                    searchResults = [];
                    renderResults();
                }
            })
            .fail(function() {
                PERTIDialog.warning(PERTII18n.t('error.networkError'));
            });
    }

    function renderResults() {
        var tbody = $('#rad_search_tbody');
        tbody.empty();

        if (searchResults.length === 0) {
            tbody.html('<tr><td colspan="6" class="text-center text-muted">' + PERTII18n.t('rad.search.noResults') + '</td></tr>');
            return;
        }

        searchResults.forEach(function(flight) {
            tbody.append(renderRow(flight));
        });
    }

    function renderRow(flight) {
        var routeSnippet = flight.route || '';
        var phaseBadge = getPhaseBadge(flight.phase);
        var csColor = RADEventBus.callsignColor(flight.callsign);

        var row = $('<tr data-gufi="' + flight.gufi + '">');
        row.append('<td><input type="checkbox" class="rad-search-cb"></td>');
        row.append('<td><span class="sub-top rad-cs" style="color:' + csColor + ';">' + (flight.callsign || '') + '</span><span class="sub-bot">' + (flight.actype || '') + '</span></td>');
        row.append('<td><span class="sub-top">' + (flight.origin || '') + ' → ' + (flight.dest || '') + '</span><span class="sub-bot">' + routeSnippet + '</span></td>');
        row.append('<td>' + (flight.actype || '') + '/' + (flight.weight_class || '') + '</td>');
        row.append('<td><span class="sub-top">ETD: ' + formatTime(flight.etd_utc) + '</span><span class="sub-bot">ETA: ' + formatTime(flight.eta_utc) + '</span></td>');
        row.append('<td>' + phaseBadge + '</td>');

        return row;
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

    function handleSort(column) {
        if (sortState.column === column) {
            if (sortState.direction === 'asc') sortState.direction = 'desc';
            else if (sortState.direction === 'desc') {
                sortState.column = null;
                sortState.direction = null;
            } else {
                sortState.direction = 'asc';
            }
        } else {
            sortState.column = column;
            sortState.direction = 'asc';
        }

        if (sortState.column) {
            searchResults.sort(function(a, b) {
                var aVal = a[column] || '';
                var bVal = b[column] || '';
                if (aVal < bVal) return sortState.direction === 'asc' ? -1 : 1;
                if (aVal > bVal) return sortState.direction === 'asc' ? 1 : -1;
                return 0;
            });
        }

        renderResults();
        updateSortIndicators();
    }

    function updateSortIndicators() {
        $('#rad_search_thead th[data-sort]').each(function() {
            var col = $(this).data('sort');
            $(this).find('.sort-indicator').remove();
            if (col === sortState.column) {
                var icon = sortState.direction === 'asc' ? '▲' : '▼';
                $(this).append(' <span class="sort-indicator">' + icon + '</span>');
            }
        });
    }

    function selectAll() {
        var checked = $('#rad_search_tbody .rad-search-cb:checked').length < searchResults.length;
        $('#rad_search_tbody .rad-search-cb').prop('checked', checked);
    }

    function addToDetail() {
        var selected = [];
        $('#rad_search_tbody .rad-search-cb:checked').each(function() {
            var gufi = $(this).closest('tr').data('gufi');
            var flight = searchResults.find(function(f) { return f.gufi === gufi; });
            if (flight) selected.push(flight);
        });

        if (selected.length === 0) {
            PERTIDialog.warning(PERTII18n.t('rad.search.noSelection'));
            return;
        }

        selected.forEach(function(flight) {
            RADEventBus.emit('flight:selected', flight);
        });

        PERTIDialog.success(PERTII18n.t('rad.search.addedToDetail', { count: selected.length }));
        $('#rad_search_tbody .rad-search-cb:checked').prop('checked', false);
    }

    function clearFilters() {
        $('#rad_filter_callsign').val('');
        $('#rad_filter_origin').val('');
        $('#rad_filter_dest').val('');
        $('#rad_filter_type').val('');
        $('#rad_filter_carrier').val('');
        $('#rad_filter_route').val('');
        $('#rad_filter_time').val('all');
        currentFilters = {};
        searchResults = [];
        renderResults();
    }

    function savePreset() {
        Swal.fire({
            title: PERTII18n.t('rad.search.savePresetTitle'),
            input: 'text',
            inputPlaceholder: PERTII18n.t('rad.search.presetNamePlaceholder'),
            showCancelButton: true
        }).then(function(result) {
            if (result.isConfirmed && result.value) {
                $.post('api/rad/filters.php', {
                    action: 'save',
                    name: result.value,
                    filters: currentFilters
                }).done(function(response) {
                    if (response.status === 'ok') {
                        loadPresets();
                        PERTIDialog.success(PERTII18n.t('rad.search.presetSaved'));
                    }
                });
            }
        });
    }

    function loadPresets() {
        $.get('api/rad/filters.php')
            .done(function(response) {
                if (response.status === 'ok') {
                    var select = $('#rad_filter_preset');
                    select.find('option:not(:first)').remove();
                    (response.data || []).forEach(function(preset) {
                        select.append('<option value="' + preset.id + '">' + preset.name + '</option>');
                    });
                }
            });
    }

    function loadPreset() {
        var presetId = $('#rad_filter_preset').val();
        if (!presetId) return;

        $.get('api/rad/filters.php', { id: presetId })
            .done(function(response) {
                if (response.status === 'ok' && response.data) {
                    var f = response.data.filters || {};
                    $('#rad_filter_callsign').val(f.cs || f.callsign || '');
                    $('#rad_filter_origin').val(f.orig || f.origin || '');
                    $('#rad_filter_dest').val(f.dest || '');
                    $('#rad_filter_type').val(f.type || '');
                    $('#rad_filter_carrier').val(f.carrier || '');
                    $('#rad_filter_route').val(f.route || '');
                    $('#rad_filter_time').val(f.time || 'all');
                    executeSearch();
                }
            });
    }

    function highlightFlight(gufi) {
        $('#rad_search_tbody tr').removeClass('table-active');
        $('#rad_search_tbody tr[data-gufi="' + gufi + '"]').addClass('table-active');
    }

    function refresh() {
        if (Object.keys(currentFilters).length > 0) {
            executeSearch();
        }
    }

    function getSelected() {
        var selected = [];
        $('#rad_search_tbody .rad-search-cb:checked').each(function() {
            var gufi = $(this).closest('tr').data('gufi');
            var flight = searchResults.find(function(f) { return f.gufi === gufi; });
            if (flight) selected.push(flight);
        });
        return selected;
    }

    return {
        init: init,
        refresh: refresh,
        getSelected: getSelected
    };
})();
