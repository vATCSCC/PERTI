/**
 * CTP Oceanic Slot Management Module
 *
 * IIFE module following gdt.js/playbook.js patterns.
 * Manages CTP sessions, flight lists, filtering, and map visualization.
 *
 * Submodules:
 *   - SessionManager: Session CRUD, lifecycle, selector
 *   - FlightTable: Rendering, sorting, pagination, row expand/collapse
 *   - FlightFilter: Search qualifier parsing, filter state
 *   - MapController: MapLibre map with oceanic boundaries, routes, entry/exit points
 *   - RouteEditor: (Phase 3) Three-tab route editor
 *   - EDCTManager: (Phase 4) EDCT assignment
 *   - ComplianceMonitor: (Phase 5) Compliance tracking
 *
 * @version 1.0.0
 */
(function() {
    'use strict';

    var t = typeof PERTII18n !== 'undefined' ? PERTII18n.t.bind(PERTII18n) : function(k) { return k; };

    // ========================================================================
    // API Endpoints
    // ========================================================================
    var API = {
        sessions: {
            list:     'api/ctp/sessions/list.php',
            get:      'api/ctp/sessions/get.php',
            create:   'api/ctp/sessions/create.php',
            update:   'api/ctp/sessions/update.php',
            activate: 'api/ctp/sessions/activate.php'
        },
        flights: {
            list:              'api/ctp/flights/list.php',
            get:               'api/ctp/flights/get.php',
            detect:            'api/ctp/flights/detect.php',
            routes_geojson:    'api/ctp/flights/routes_geojson.php',
            validate_route:    'api/ctp/flights/validate_route.php',
            modify_route:      'api/ctp/flights/modify_route.php',
            assign_edct:       'api/ctp/flights/assign_edct.php',
            assign_edct_batch: 'api/ctp/flights/assign_edct_batch.php',
            remove_edct:       'api/ctp/flights/remove_edct.php',
            compliance:        'api/ctp/flights/compliance.php',
            exclude:           'api/ctp/flights/exclude.php'
        },
        routes: {
            suggest:   'api/ctp/routes/suggest.php',
            templates: 'api/ctp/routes/templates.php'
        },
        sessions_complete: 'api/ctp/sessions/complete.php',
        stats:      'api/ctp/stats.php',
        audit_log:  'api/ctp/audit_log.php',
        changelog:  'api/ctp/changelog.php',
        nat_tracks: 'api/data/playbook/nat_tracks.php',
        demand:     'api/ctp/demand.php',
        boundaries: 'api/ctp/boundaries.php',
        throughput: {
            list:    'api/ctp/throughput/list.php',
            create:  'api/ctp/throughput/create.php',
            update:  'api/ctp/throughput/update.php',
            delete:  'api/ctp/throughput/delete.php',
            preview: 'api/ctp/throughput/preview.php'
        },
        planning: {
            scenarios:         'api/ctp/planning/scenarios.php',
            scenario_save:     'api/ctp/planning/scenario_save.php',
            scenario_delete:   'api/ctp/planning/scenario_delete.php',
            scenario_clone:    'api/ctp/planning/scenario_clone.php',
            block_save:        'api/ctp/planning/block_save.php',
            block_delete:      'api/ctp/planning/block_delete.php',
            assignment_save:   'api/ctp/planning/assignment_save.php',
            assignment_delete: 'api/ctp/planning/assignment_delete.php',
            compute:           'api/ctp/planning/compute.php',
            apply_to_session:  'api/ctp/planning/apply_to_session.php'
        }
    };

    // ========================================================================
    // State
    // ========================================================================
    var state = {
        currentSession: null,
        sessionDetail: null,
        flights: [],
        total: 0,
        summary: null,
        selectedIds: new Set(),
        expandedId: null,

        // Filters
        search: '',
        edctStatusFilter: null,
        routeStatusFilter: null,
        hideExcluded: false,
        perspective: 'ALL',

        // Sort & pagination
        sort: 'oceanic_entry_utc',
        sortDir: 'asc',
        limit: 100,
        offset: 0,

        // UI
        refreshTimer: null,
        requestInFlight: false,
        userPerspectives: [],

        // Map
        mapReady: false,
        mapHighlightedId: null,
        boundariesLoaded: false,

        // Demand chart
        demandGroupBy: 'status',
        demandBinMin: 60
    };

    // ========================================================================
    // SessionManager
    // ========================================================================
    var SessionManager = {
        init: function() {
            this.bindEvents();
            this.loadSessions();
        },

        bindEvents: function() {
            $('#ctp_session_select').on('change', function() {
                var id = parseInt($(this).val(), 10);
                if (id > 0) {
                    SessionManager.selectSession(id);
                } else {
                    SessionManager.clearSession();
                }
            });

            $('#ctp_btn_session_settings').on('click', function() {
                $('#ctpCreateSessionModal').modal('show');
            });

            $('#ctp_create_submit').on('click', function() {
                SessionManager.createSession();
            });

            $('#ctp_btn_detect').on('click', function() {
                FlightTable.detectFlights();
            });
        },

        loadSessions: function() {
            $.getJSON(API.sessions.list, { status: 'DRAFT,ACTIVE,MONITORING' }, function(resp) {
                if (resp.status !== 'ok') return;
                var $sel = $('#ctp_session_select');
                $sel.find('option:not(:first)').remove();
                (resp.data.sessions || []).forEach(function(s) {
                    var label = s.session_name + ' [' + s.status + ']';
                    $sel.append($('<option>').val(s.session_id).text(label));
                });

                // Auto-select if only one active session
                var active = (resp.data.sessions || []).filter(function(s) { return s.status === 'ACTIVE'; });
                if (active.length === 1) {
                    $sel.val(active[0].session_id).trigger('change');
                }
            });
        },

        selectSession: function(sessionId) {
            $.getJSON(API.sessions.get, { session_id: sessionId }, function(resp) {
                if (resp.status !== 'ok' || !resp.data.session) return;
                state.currentSession = resp.data.session;
                state.sessionDetail = resp.data;
                state.userPerspectives = resp.data.user_perspectives || [];

                SessionManager.updateUI();
                FlightTable.reset();
                FlightTable.load();
                MapController.loadSessionData();
                DemandChart.refresh();
                NATTracks.loaded = false;
                AuditLog.reset();
                AuditLog.load();
                SessionManager.startAutoRefresh();
            });
        },

        clearSession: function() {
            state.currentSession = null;
            state.sessionDetail = null;
            state.flights = [];
            state.total = 0;
            state.summary = null;
            state.userPerspectives = [];

            SessionManager.stopAutoRefresh();
            SessionManager.updateUI();
            FlightTable.render();
            MapController.clearSessionData();
            DemandChart.clear();
        },

        updateUI: function() {
            var s = state.currentSession;
            var $badge = $('#ctp_status_badge');
            var $dirBadge = $('#ctp_direction_badge');
            var $actions = $('#ctp_session_actions');
            var $stats = $('#ctp_stats_bar');

            if (!s) {
                $badge.text(t('ctp.session.noSession')).attr('class', 'badge badge-secondary ctp-status-badge');
                $dirBadge.addClass('d-none');
                $actions.hide();
                $stats.hide();
                $('#ctp_demand_section').hide();
                return;
            }

            // Status badge
            var statusClass = 'badge-status-' + s.status.toLowerCase();
            $badge.text(s.status).attr('class', 'badge ctp-status-badge ' + statusClass);

            // Direction badge
            $dirBadge.text(s.direction).removeClass('d-none');

            // Actions
            $actions.toggle(s.status === 'DRAFT' || s.status === 'ACTIVE' || s.status === 'MONITORING');

            // Stats
            var detail = state.sessionDetail;
            if (detail && detail.stats) {
                var st = detail.stats;
                $stats.show();
                $('#ctp_stat_total .ctp-stat-value').text(st.total_flights || 0);
                $('#ctp_stat_slotted .ctp-stat-value').text(st.slotted_flights || 0);
                $('#ctp_stat_modified .ctp-stat-value').text(st.modified_flights || 0);
                $('#ctp_stat_excluded .ctp-stat-value').text(st.excluded_flights || 0);
            } else {
                $stats.hide();
            }

            // Demand chart visibility
            $('#ctp_demand_section').toggle(s.status === 'ACTIVE' || s.status === 'MONITORING');
        },

        createSession: function() {
            var name = $('#ctp_create_name').val().trim();
            if (!name) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire(t('ctp.dialog.error'), t('ctp.session.nameRequired'), 'error');
                }
                return;
            }

            var firs = $('#ctp_create_firs').val().trim();
            var firArray = firs ? firs.split(/[,\s]+/).map(function(f) { return f.trim().toUpperCase(); }).filter(Boolean) : [];

            var payload = {
                session_name: name,
                direction: $('#ctp_create_direction').val(),
                constraint_window_start: $('#ctp_create_start').val() ? new Date($('#ctp_create_start').val()).toISOString() : null,
                constraint_window_end: $('#ctp_create_end').val() ? new Date($('#ctp_create_end').val()).toISOString() : null,
                constrained_firs: firArray,
                slot_interval_min: parseInt($('#ctp_create_interval').val(), 10) || 5,
                max_slots_per_hour: $('#ctp_create_max_slots').val() ? parseInt($('#ctp_create_max_slots').val(), 10) : null,
                flow_event_id: $('#ctp_create_event').val() ? parseInt($('#ctp_create_event').val(), 10) : null
            };

            $.ajax({
                url: API.sessions.create,
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(payload),
                success: function(resp) {
                    if (resp.status === 'ok') {
                        $('#ctpCreateSessionModal').modal('hide');
                        SessionManager.loadSessions();
                        if (resp.data.session_id) {
                            setTimeout(function() {
                                $('#ctp_session_select').val(resp.data.session_id).trigger('change');
                            }, 500);
                        }
                        if (typeof Swal !== 'undefined') {
                            Swal.fire(t('ctp.dialog.success'), t('ctp.session.created'), 'success');
                        }
                    } else {
                        if (typeof Swal !== 'undefined') {
                            Swal.fire(t('ctp.dialog.error'), resp.message || t('ctp.dialog.unknownError'), 'error');
                        }
                    }
                },
                error: function() {
                    if (typeof Swal !== 'undefined') {
                        Swal.fire(t('ctp.dialog.error'), t('ctp.dialog.networkError'), 'error');
                    }
                }
            });
        },

        startAutoRefresh: function() {
            SessionManager.stopAutoRefresh();
            state.refreshTimer = setInterval(function() {
                if (!state.requestInFlight) {
                    FlightTable.load(true);
                    ComplianceMonitor.refreshCompliance();
                }
            }, 30000);
        },

        stopAutoRefresh: function() {
            if (state.refreshTimer) {
                clearInterval(state.refreshTimer);
                state.refreshTimer = null;
            }
        }
    };

    // ========================================================================
    // FlightFilter
    // ========================================================================
    var FlightFilter = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            var searchTimeout = null;
            $('#ctp_search').on('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(function() {
                    state.search = $('#ctp_search').val().trim();
                    state.offset = 0;
                    FlightTable.load();
                }, 400);
            });

            // EDCT status filter checkboxes
            $('#ctp_filter_edct_status input').on('change', function() {
                FlightFilter.updateFilters();
            });

            // Route status filter checkboxes
            $('#ctp_filter_route_status input').on('change', function() {
                FlightFilter.updateFilters();
            });

            // Hide excluded checkbox
            $('#ctp_filter_hide_excluded').on('change', function() {
                state.hideExcluded = $(this).is(':checked');
                state.offset = 0;
                FlightTable.load();
            });

            // Perspective tabs
            $('#ctp_perspective_tabs .btn').on('click', function() {
                $('#ctp_perspective_tabs .btn').removeClass('active');
                $(this).addClass('active');
                state.perspective = $(this).data('perspective');
                state.offset = 0;
                FlightTable.load();
            });
        },

        updateFilters: function() {
            // Gather checked EDCT statuses
            var edctChecked = [];
            $('#ctp_filter_edct_status input:checked').each(function() { edctChecked.push($(this).val()); });
            state.edctStatusFilter = edctChecked.length === 5 ? null : edctChecked.join(',');

            // Gather checked route statuses
            var routeChecked = [];
            $('#ctp_filter_route_status input:checked').each(function() { routeChecked.push($(this).val()); });
            state.routeStatusFilter = routeChecked.length === 4 ? null : routeChecked.join(',');

            state.offset = 0;
            FlightTable.load();
        },

        buildQueryParams: function() {
            var params = {
                session_id: state.currentSession.session_id,
                limit: state.limit,
                offset: state.offset,
                sort: state.sort,
                sort_dir: state.sortDir
            };

            if (state.search) params.search = state.search;
            if (state.edctStatusFilter) params.edct_status = state.edctStatusFilter;
            if (state.routeStatusFilter) params.route_status = state.routeStatusFilter;
            if (state.hideExcluded) params.is_excluded = 0;

            return params;
        }
    };

    // ========================================================================
    // FlightTable
    // ========================================================================
    var FlightTable = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            // Sort headers
            $('.ctp-sortable').on('click', function() {
                var col = $(this).data('sort');
                if (state.sort === col) {
                    state.sortDir = state.sortDir === 'asc' ? 'desc' : 'asc';
                } else {
                    state.sort = col;
                    state.sortDir = 'asc';
                }
                state.offset = 0;
                FlightTable.updateSortUI();
                FlightTable.load();
            });

            // Check all
            $('#ctp_check_all').on('change', function() {
                var checked = $(this).is(':checked');
                state.flights.forEach(function(f) {
                    if (checked) state.selectedIds.add(f.ctp_control_id);
                    else state.selectedIds.delete(f.ctp_control_id);
                });
                FlightTable.render();
            });

            // Row click (expand/collapse + map highlight)
            $('#ctp_flight_tbody').on('click', 'tr.ctp-flight-row', function(e) {
                if ($(e.target).is('input[type="checkbox"]') || $(e.target).closest('.ctp-col-check').length) return;
                var id = parseInt($(this).data('id'), 10);
                if (state.expandedId === id) {
                    state.expandedId = null;
                    MapController.highlightFlight(null);
                } else {
                    state.expandedId = id;
                    MapController.highlightFlight(id);
                }
                FlightTable.render();
            });

            // Double-click opens route editor sidebar
            $('#ctp_flight_tbody').on('dblclick', 'tr.ctp-flight-row', function(e) {
                if ($(e.target).is('input[type="checkbox"]') || $(e.target).closest('.ctp-col-check').length) return;
                var id = parseInt($(this).data('id'), 10);
                RouteEditor.open(id);
            });

            // Row checkbox
            $('#ctp_flight_tbody').on('change', '.ctp-row-check', function() {
                var id = parseInt($(this).data('id'), 10);
                if ($(this).is(':checked')) {
                    state.selectedIds.add(id);
                } else {
                    state.selectedIds.delete(id);
                }
            });

            // Pagination
            $('#ctp_page_prev').on('click', function() {
                if (state.offset >= state.limit) {
                    state.offset -= state.limit;
                    FlightTable.load();
                }
            });

            $('#ctp_page_next').on('click', function() {
                if (state.offset + state.limit < state.total) {
                    state.offset += state.limit;
                    FlightTable.load();
                }
            });
        },

        reset: function() {
            state.flights = [];
            state.total = 0;
            state.summary = null;
            state.selectedIds = new Set();
            state.expandedId = null;
            state.offset = 0;
        },

        load: function(silent) {
            if (!state.currentSession) return;
            if (state.requestInFlight) return;

            state.requestInFlight = true;
            var params = FlightFilter.buildQueryParams();

            $.getJSON(API.flights.list, params, function(resp) {
                state.requestInFlight = false;
                if (resp.status !== 'ok') return;

                state.flights = resp.data.flights || [];
                state.total = resp.data.total || 0;
                state.summary = resp.data.summary || null;

                FlightTable.render();
                FlightTable.updatePagination();
                FlightTable.updateStats();
            }).fail(function() {
                state.requestInFlight = false;
            });
        },

        render: function() {
            var $tbody = $('#ctp_flight_tbody');
            $tbody.empty();

            if (state.flights.length === 0) {
                $tbody.append(
                    '<tr class="ctp-empty-row"><td colspan="12" class="text-center text-muted py-4">' +
                    '<i class="fas fa-plane-slash fa-2x mb-2 d-block"></i>' +
                    t('ctp.flights.noData') +
                    '</td></tr>'
                );
                return;
            }

            state.flights.forEach(function(f) {
                var isSelected = state.selectedIds.has(f.ctp_control_id);
                var rowClass = 'ctp-flight-row';
                if (isSelected) rowClass += ' ctp-row-selected';
                if (f.is_excluded) rowClass += ' ctp-row-excluded';
                if (f.is_priority) rowClass += ' ctp-row-priority';

                var entryUtc = f.oceanic_entry_utc ? FlightTable.formatUtcShort(f.oceanic_entry_utc) : '--';
                var edctUtc = f.edct_utc ? FlightTable.formatUtcShort(f.edct_utc) : '--';

                var row = '<tr class="' + rowClass + '" data-id="' + f.ctp_control_id + '">' +
                    '<td class="ctp-col-check"><input type="checkbox" class="ctp-row-check" data-id="' + f.ctp_control_id + '"' + (isSelected ? ' checked' : '') + '></td>' +
                    '<td class="ctp-col-cs font-weight-bold">' + FlightTable.escHtml(f.callsign) + '</td>' +
                    '<td class="ctp-col-apt">' + FlightTable.escHtml(f.dep_airport || '') + '</td>' +
                    '<td class="ctp-col-apt">' + FlightTable.escHtml(f.arr_airport || '') + '</td>' +
                    '<td class="ctp-col-type">' + FlightTable.escHtml(f.aircraft_type || '') + '</td>' +
                    '<td class="ctp-col-fix">' + FlightTable.escHtml(f.oceanic_entry_fix || '') + '</td>' +
                    '<td class="ctp-col-time">' + entryUtc + '</td>' +
                    '<td class="ctp-col-time">' + edctUtc + '</td>' +
                    '<td class="ctp-col-seg">' + FlightTable.segDot(f.seg_na_status) + '</td>' +
                    '<td class="ctp-col-seg">' + FlightTable.segDot(f.seg_oceanic_status) + '</td>' +
                    '<td class="ctp-col-seg">' + FlightTable.segDot(f.seg_eu_status) + '</td>' +
                    '<td class="ctp-col-status">' + FlightTable.statusBadge(f.route_status) + '</td>' +
                    '</tr>';

                $tbody.append(row);

                // Expand row if this is the expanded flight
                if (state.expandedId === f.ctp_control_id) {
                    $tbody.append(FlightTable.buildExpandRow(f));
                }
            });
        },

        buildExpandRow: function(f) {
            var exitUtc = f.oceanic_exit_utc ? FlightTable.formatUtcFull(f.oceanic_exit_utc) : '--';
            var entryUtcFull = f.oceanic_entry_utc ? FlightTable.formatUtcFull(f.oceanic_entry_utc) : '--';
            var edctFull = f.edct_utc ? FlightTable.formatUtcFull(f.edct_utc) : '--';
            var origEtd = f.original_etd_utc ? FlightTable.formatUtcFull(f.original_etd_utc) : '--';
            var delay = f.slot_delay_min !== null && f.slot_delay_min !== undefined ? f.slot_delay_min + ' min' : '--';
            var compliance = f.compliance_status || '--';

            var html = '<tr class="ctp-expand-row"><td colspan="12"><div class="ctp-expand-content">';

            // Column 1: Times
            html += '<div class="ctp-expand-section">' +
                '<h6>' + t('ctp.flights.times') + '</h6>' +
                FlightTable.expandField(t('ctp.flights.origEtd'), origEtd) +
                FlightTable.expandField(t('ctp.flights.edct'), edctFull) +
                FlightTable.expandField(t('ctp.flights.delay'), delay) +
                FlightTable.expandField(t('ctp.flights.entryUtcFull'), entryUtcFull) +
                FlightTable.expandField(t('ctp.flights.exitUtc'), exitUtc) +
                FlightTable.expandField(t('ctp.flights.compliance'), compliance) +
                '</div>';

            // Column 2: Oceanic
            html += '<div class="ctp-expand-section">' +
                '<h6>' + t('ctp.flights.oceanic') + '</h6>' +
                FlightTable.expandField(t('ctp.flights.entryFir'), f.oceanic_entry_fir || '--') +
                FlightTable.expandField(t('ctp.flights.exitFir'), f.oceanic_exit_fir || '--') +
                FlightTable.expandField(t('ctp.flights.entryFix'), f.oceanic_entry_fix || '--') +
                FlightTable.expandField(t('ctp.flights.exitFix'), f.oceanic_exit_fix || '--') +
                FlightTable.expandField(t('ctp.flights.depArtcc'), f.dep_artcc || '--') +
                FlightTable.expandField(t('ctp.flights.arrArtcc'), f.arr_artcc || '--') +
                '</div>';

            // Column 3: Status
            html += '<div class="ctp-expand-section">' +
                '<h6>' + t('ctp.flights.status') + '</h6>' +
                FlightTable.expandField(t('ctp.flights.routeStatus'), FlightTable.statusBadge(f.route_status)) +
                FlightTable.expandField(t('ctp.flights.edctStatus'), FlightTable.statusBadge(f.edct_status)) +
                FlightTable.expandField(t('ctp.perspective.naShort'), FlightTable.segBadge(f.seg_na_status)) +
                FlightTable.expandField(t('ctp.perspective.ocaShort'), FlightTable.segBadge(f.seg_oceanic_status)) +
                FlightTable.expandField(t('ctp.perspective.euShort'), FlightTable.segBadge(f.seg_eu_status)) +
                (f.notes ? FlightTable.expandField(t('ctp.flights.notes'), FlightTable.escHtml(f.notes)) : '') +
                '</div>';

            html += '</div></td></tr>';
            return html;
        },

        detectFlights: function() {
            if (!state.currentSession) return;

            var $btn = $('#ctp_btn_detect');
            $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> ' + t('ctp.flights.detecting'));

            $.ajax({
                url: API.flights.detect,
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ session_id: state.currentSession.session_id }),
                success: function(resp) {
                    $btn.prop('disabled', false).html('<i class="fas fa-satellite-dish"></i> ' + t('ctp.flights.detect'));
                    if (resp.status === 'ok') {
                        var d = resp.data;
                        var msg = t('ctp.flights.detectResult', {
                            detected: d.detected,
                            candidates: d.total_candidates,
                            skippedEvent: d.skipped_event,
                            skippedExisting: d.skipped_existing
                        });
                        if (typeof Swal !== 'undefined') {
                            Swal.fire(t('ctp.dialog.success'), msg, 'success');
                        }
                        FlightTable.load();
                        SessionManager.selectSession(state.currentSession.session_id);
                    } else {
                        if (typeof Swal !== 'undefined') {
                            Swal.fire(t('ctp.dialog.error'), resp.message || t('ctp.dialog.unknownError'), 'error');
                        }
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).html('<i class="fas fa-satellite-dish"></i> ' + t('ctp.flights.detect'));
                    if (typeof Swal !== 'undefined') {
                        Swal.fire(t('ctp.dialog.error'), t('ctp.dialog.networkError'), 'error');
                    }
                }
            });
        },

        updatePagination: function() {
            var page = Math.floor(state.offset / state.limit) + 1;
            var totalPages = Math.ceil(state.total / state.limit) || 1;

            $('#ctp_page_label').text(t('ctp.pagination.label', { page: page, total: totalPages }));
            $('#ctp_page_prev').prop('disabled', state.offset === 0);
            $('#ctp_page_next').prop('disabled', state.offset + state.limit >= state.total);
            $('#ctp_page_info').text(t('ctp.pagination.showing', {
                from: state.total > 0 ? state.offset + 1 : 0,
                to: Math.min(state.offset + state.limit, state.total),
                total: state.total
            }));
        },

        updateStats: function() {
            var s = state.summary;
            if (!s) return;
            $('#ctp_stats_bar').show();
            $('#ctp_stat_total .ctp-stat-value').text(s.total || 0);
            $('#ctp_stat_slotted .ctp-stat-value').text(s.slotted || 0);
            $('#ctp_stat_modified .ctp-stat-value').text(s.modified || 0);
            $('#ctp_stat_excluded .ctp-stat-value').text(s.excluded || 0);
        },

        updateSortUI: function() {
            $('.ctp-sortable').removeClass('sort-asc sort-desc');
            var $active = $('.ctp-sortable[data-sort="' + state.sort + '"]');
            $active.addClass(state.sortDir === 'asc' ? 'sort-asc' : 'sort-desc');
        },

        // -- Rendering Helpers --

        formatUtcShort: function(iso) {
            if (!iso) return '--';
            try {
                var d = new Date(iso);
                var h = String(d.getUTCHours()).padStart(2, '0');
                var m = String(d.getUTCMinutes()).padStart(2, '0');
                return h + ':' + m + 'Z';
            } catch (e) { return '--'; }
        },

        formatUtcFull: function(iso) {
            if (!iso) return '--';
            try {
                var d = new Date(iso);
                var mo = String(d.getUTCMonth() + 1).padStart(2, '0');
                var dy = String(d.getUTCDate()).padStart(2, '0');
                var h = String(d.getUTCHours()).padStart(2, '0');
                var m = String(d.getUTCMinutes()).padStart(2, '0');
                return mo + '/' + dy + ' ' + h + ':' + m + 'Z';
            } catch (e) { return '--'; }
        },

        segDot: function(status) {
            var cls = 'seg-' + (status || 'filed').toLowerCase();
            return '<span class="ctp-seg-dot ' + cls + '" title="' + (status || 'FILED') + '"></span>';
        },

        segBadge: function(status) {
            var st = (status || 'FILED').toLowerCase();
            return '<span class="badge ctp-badge badge-' + st + '">' + status + '</span>';
        },

        statusBadge: function(status) {
            if (!status) return '--';
            var st = status.toLowerCase().replace(/_/g, '-');
            return '<span class="badge ctp-badge badge-' + st + '">' + status + '</span>';
        },

        expandField: function(label, value) {
            return '<div class="ctp-expand-field"><span class="field-label">' + label + '</span><span class="field-value">' + value + '</span></div>';
        },

        escHtml: function(str) {
            if (!str) return '';
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(str));
            return div.innerHTML;
        },

        scrollToRow: function(ctpControlId) {
            var $row = $('tr.ctp-flight-row[data-id="' + ctpControlId + '"]');
            if ($row.length) {
                var wrapper = $row.closest('.ctp-table-wrapper');
                if (wrapper.length) {
                    var rowTop = $row.position().top;
                    var wrapperTop = wrapper.scrollTop();
                    wrapper.animate({ scrollTop: wrapperTop + rowTop - 60 }, 300);
                }
            }
        }
    };

    // ========================================================================
    // MapController (MapLibre integration)
    // ========================================================================
    var MapController = {
        map: null,
        popup: null,

        init: function() {
            if (typeof maplibregl === 'undefined') {
                console.warn('[CTP] MapLibre GL JS not loaded');
                return;
            }
            this.initMap();
        },

        initMap: function() {
            var container = document.getElementById('ctp_map');
            if (!container) return;

            this.map = new maplibregl.Map({
                container: 'ctp_map',
                style: {
                    version: 8,
                    name: 'CTP Dark',
                    sources: {
                        'carto-dark': {
                            type: 'raster',
                            tiles: [
                                'https://a.basemaps.cartocdn.com/rastertiles/dark_nolabels/{z}/{x}/{y}.png',
                                'https://b.basemaps.cartocdn.com/rastertiles/dark_nolabels/{z}/{x}/{y}.png',
                                'https://c.basemaps.cartocdn.com/rastertiles/dark_nolabels/{z}/{x}/{y}.png',
                                'https://d.basemaps.cartocdn.com/rastertiles/dark_nolabels/{z}/{x}/{y}.png'
                            ],
                            tileSize: 256,
                            attribution: '&copy; CARTO'
                        }
                    },
                    layers: [{
                        id: 'carto-dark-layer',
                        type: 'raster',
                        source: 'carto-dark'
                    }],
                    glyphs: 'https://demotiles.maplibre.org/font/{fontstack}/{range}.pbf'
                },
                center: [-30, 52],
                zoom: 3,
                minZoom: 2,
                maxZoom: 10
            });

            this.map.addControl(new maplibregl.NavigationControl(), 'top-left');

            var self = this;
            this.map.on('load', function() {
                state.mapReady = true;
                self.addSources();
                self.addLayers();
                self.bindMapEvents();
                self.hidePlaceholder();
            });
        },

        hidePlaceholder: function() {
            var placeholder = document.getElementById('ctp_map_placeholder');
            if (placeholder) placeholder.style.display = 'none';
        },

        showPlaceholder: function() {
            var placeholder = document.getElementById('ctp_map_placeholder');
            if (placeholder) placeholder.style.display = '';
        },

        addSources: function() {
            var empty = { type: 'FeatureCollection', features: [] };

            // Oceanic FIR boundaries
            this.map.addSource('ctp-boundaries', { type: 'geojson', data: empty });

            // Flight routes (lines from route_geojson)
            this.map.addSource('ctp-routes', { type: 'geojson', data: empty });

            // Highlighted route (selected flight)
            this.map.addSource('ctp-route-highlight', { type: 'geojson', data: empty });

            // Entry/exit point markers
            this.map.addSource('ctp-entry-points', { type: 'geojson', data: empty });
            this.map.addSource('ctp-exit-points', { type: 'geojson', data: empty });
        },

        addLayers: function() {
            // -- Oceanic FIR boundary fills --
            this.map.addLayer({
                id: 'ctp-boundary-fills',
                type: 'fill',
                source: 'ctp-boundaries',
                paint: {
                    'fill-color': '#00bcd4',
                    'fill-opacity': 0.08
                }
            });

            // -- Oceanic FIR boundary lines --
            this.map.addLayer({
                id: 'ctp-boundary-lines',
                type: 'line',
                source: 'ctp-boundaries',
                paint: {
                    'line-color': '#00bcd4',
                    'line-width': 1.5,
                    'line-dasharray': [4, 2]
                }
            });

            // -- Oceanic FIR labels --
            this.map.addLayer({
                id: 'ctp-boundary-labels',
                type: 'symbol',
                source: 'ctp-boundaries',
                layout: {
                    'text-field': ['get', 'boundary_code'],
                    'text-size': 12,
                    'text-font': ['Open Sans Regular'],
                    'text-variable-anchor': ['center'],
                    'text-allow-overlap': false
                },
                paint: {
                    'text-color': '#00bcd4',
                    'text-halo-color': 'rgba(0,0,0,0.7)',
                    'text-halo-width': 1.5
                }
            });

            // -- Flight route lines --
            this.map.addLayer({
                id: 'ctp-route-lines',
                type: 'line',
                source: 'ctp-routes',
                paint: {
                    'line-color': [
                        'match', ['get', 'route_status'],
                        'VALIDATED', '#28a745',
                        'MODIFIED', '#007bff',
                        'REJECTED', '#dc3545',
                        '#6c757d'
                    ],
                    'line-width': [
                        'match', ['get', 'route_status'],
                        'VALIDATED', 2,
                        'MODIFIED', 1.5,
                        1
                    ],
                    'line-opacity': [
                        'match', ['get', 'edct_status'],
                        'ASSIGNED', 0.9,
                        'DELIVERED', 0.9,
                        'COMPLIANT', 1.0,
                        'NON_COMPLIANT', 0.9,
                        0.5
                    ]
                },
                layout: {
                    'line-cap': 'round',
                    'line-join': 'round'
                }
            });

            // -- Highlighted (selected) route --
            this.map.addLayer({
                id: 'ctp-route-highlight',
                type: 'line',
                source: 'ctp-route-highlight',
                paint: {
                    'line-color': '#ffc107',
                    'line-width': 4,
                    'line-opacity': 0.9
                },
                layout: {
                    'line-cap': 'round',
                    'line-join': 'round'
                }
            });

            // -- Entry point circles --
            this.map.addLayer({
                id: 'ctp-entry-circles',
                type: 'circle',
                source: 'ctp-entry-points',
                paint: {
                    'circle-radius': [
                        'interpolate', ['linear'], ['get', 'flight_count'],
                        1, 5,
                        10, 8,
                        50, 14
                    ],
                    'circle-color': '#17a2b8',
                    'circle-stroke-color': '#fff',
                    'circle-stroke-width': 1.5,
                    'circle-opacity': 0.85
                }
            });

            // -- Entry point labels --
            this.map.addLayer({
                id: 'ctp-entry-labels',
                type: 'symbol',
                source: 'ctp-entry-points',
                layout: {
                    'text-field': ['concat', ['get', 'fix_name'], '\n', ['to-string', ['get', 'flight_count']]],
                    'text-size': 10,
                    'text-font': ['Open Sans Regular'],
                    'text-offset': [0, 1.5],
                    'text-anchor': 'top',
                    'text-allow-overlap': false
                },
                paint: {
                    'text-color': '#17a2b8',
                    'text-halo-color': 'rgba(0,0,0,0.8)',
                    'text-halo-width': 1
                }
            });

            // -- Exit point circles --
            this.map.addLayer({
                id: 'ctp-exit-circles',
                type: 'circle',
                source: 'ctp-exit-points',
                paint: {
                    'circle-radius': [
                        'interpolate', ['linear'], ['get', 'flight_count'],
                        1, 5,
                        10, 8,
                        50, 14
                    ],
                    'circle-color': '#28a745',
                    'circle-stroke-color': '#fff',
                    'circle-stroke-width': 1.5,
                    'circle-opacity': 0.85
                }
            });

            // -- Exit point labels --
            this.map.addLayer({
                id: 'ctp-exit-labels',
                type: 'symbol',
                source: 'ctp-exit-points',
                layout: {
                    'text-field': ['concat', ['get', 'fix_name'], '\n', ['to-string', ['get', 'flight_count']]],
                    'text-size': 10,
                    'text-font': ['Open Sans Regular'],
                    'text-offset': [0, 1.5],
                    'text-anchor': 'top',
                    'text-allow-overlap': false
                },
                paint: {
                    'text-color': '#28a745',
                    'text-halo-color': 'rgba(0,0,0,0.8)',
                    'text-halo-width': 1
                }
            });
        },

        bindMapEvents: function() {
            var self = this;

            // Click on route line -> select flight in table
            this.map.on('click', 'ctp-route-lines', function(e) {
                if (!e.features || !e.features[0]) return;
                var props = e.features[0].properties;
                var ctpId = props.ctp_control_id;
                if (ctpId) {
                    state.expandedId = ctpId;
                    self.highlightFlight(ctpId);
                    FlightTable.render();
                    FlightTable.scrollToRow(ctpId);
                }
            });

            // Click on entry/exit point -> filter by fix
            this.map.on('click', 'ctp-entry-circles', function(e) {
                if (!e.features || !e.features[0]) return;
                var fixName = e.features[0].properties.fix_name;
                if (fixName) {
                    $('#ctp_search').val('entry:' + fixName).trigger('input');
                }
            });

            this.map.on('click', 'ctp-exit-circles', function(e) {
                if (!e.features || !e.features[0]) return;
                var fixName = e.features[0].properties.fix_name;
                if (fixName) {
                    $('#ctp_search').val('exit:' + fixName).trigger('input');
                }
            });

            // Hover cursors
            ['ctp-route-lines', 'ctp-entry-circles', 'ctp-exit-circles'].forEach(function(layerId) {
                self.map.on('mouseenter', layerId, function() {
                    self.map.getCanvas().style.cursor = 'pointer';
                });
                self.map.on('mouseleave', layerId, function() {
                    self.map.getCanvas().style.cursor = '';
                });
            });

            // Route hover popup
            this.map.on('mouseenter', 'ctp-route-lines', function(e) {
                if (!e.features || !e.features[0]) return;
                var props = e.features[0].properties;
                var html = '<strong>' + (props.callsign || '') + '</strong><br>' +
                    (props.dep_airport || '') + ' → ' + (props.arr_airport || '') + '<br>' +
                    '<small>' + (props.route_status || '') + ' | ' + (props.edct_status || 'NO EDCT') + '</small>';

                if (self.popup) self.popup.remove();
                self.popup = new maplibregl.Popup({ closeButton: false, maxWidth: '250px' })
                    .setLngLat(e.lngLat)
                    .setHTML(html)
                    .addTo(self.map);
            });

            this.map.on('mouseleave', 'ctp-route-lines', function() {
                if (self.popup) {
                    self.popup.remove();
                    self.popup = null;
                }
            });

            // Entry/exit point popup
            ['ctp-entry-circles', 'ctp-exit-circles'].forEach(function(layerId) {
                self.map.on('mouseenter', layerId, function(e) {
                    if (!e.features || !e.features[0]) return;
                    var props = e.features[0].properties;
                    var typeLabel = props.point_type === 'entry' ? t('ctp.map.entryPoint') : t('ctp.map.exitPoint');
                    var html = '<strong>' + (props.fix_name || '') + '</strong><br>' +
                        typeLabel + '<br>' +
                        t('ctp.map.flightCount', { count: props.flight_count || 0 });

                    if (self.popup) self.popup.remove();
                    self.popup = new maplibregl.Popup({ closeButton: false, maxWidth: '200px' })
                        .setLngLat(e.lngLat)
                        .setHTML(html)
                        .addTo(self.map);
                });

                self.map.on('mouseleave', layerId, function() {
                    if (self.popup) {
                        self.popup.remove();
                        self.popup = null;
                    }
                });
            });
        },

        loadBoundaries: function() {
            if (!state.mapReady || state.boundariesLoaded) return;

            var self = this;
            var url = API.boundaries;
            // If session has constrained FIRs, filter to those
            if (state.currentSession && state.currentSession.constrained_firs) {
                var firs = state.currentSession.constrained_firs;
                if (typeof firs === 'string') {
                    try { firs = JSON.parse(firs); } catch (e) { firs = []; }
                }
                if (Array.isArray(firs) && firs.length > 0) {
                    url += '?firs=' + encodeURIComponent(firs.join(','));
                }
            }

            fetch(url)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.type === 'FeatureCollection' && self.map.getSource('ctp-boundaries')) {
                        self.map.getSource('ctp-boundaries').setData(data);
                        state.boundariesLoaded = true;

                        // Fit to boundary extent if features exist
                        if (data.features && data.features.length > 0) {
                            self.fitToBoundaries(data);
                        }
                    }
                })
                .catch(function(err) {
                    console.error('[CTP] Failed to load boundaries:', err);
                });
        },

        loadRoutes: function() {
            if (!state.mapReady || !state.currentSession) return;

            var self = this;
            var url = API.flights.routes_geojson + '?session_id=' + state.currentSession.session_id;

            fetch(url)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    // Load route lines
                    if (data.type === 'FeatureCollection' && self.map.getSource('ctp-routes')) {
                        self.map.getSource('ctp-routes').setData(data);
                    }

                    // Load entry/exit point markers
                    if (data.entry_exit_points && data.entry_exit_points.type === 'FeatureCollection') {
                        var entryFeatures = [];
                        var exitFeatures = [];

                        data.entry_exit_points.features.forEach(function(f) {
                            if (f.properties.point_type === 'entry') {
                                entryFeatures.push(f);
                            } else {
                                exitFeatures.push(f);
                            }
                        });

                        if (self.map.getSource('ctp-entry-points')) {
                            self.map.getSource('ctp-entry-points').setData({
                                type: 'FeatureCollection',
                                features: entryFeatures
                            });
                        }
                        if (self.map.getSource('ctp-exit-points')) {
                            self.map.getSource('ctp-exit-points').setData({
                                type: 'FeatureCollection',
                                features: exitFeatures
                            });
                        }
                    }
                })
                .catch(function(err) {
                    console.error('[CTP] Failed to load routes:', err);
                });
        },

        loadSessionData: function() {
            if (!state.mapReady) {
                // Map not ready yet; try again after load
                var self = this;
                if (this.map) {
                    this.map.once('load', function() {
                        self.loadSessionData();
                    });
                }
                return;
            }
            this.loadBoundaries();
            this.loadRoutes();
        },

        clearSessionData: function() {
            if (!state.mapReady) return;
            var empty = { type: 'FeatureCollection', features: [] };

            if (this.map.getSource('ctp-routes')) this.map.getSource('ctp-routes').setData(empty);
            if (this.map.getSource('ctp-route-highlight')) this.map.getSource('ctp-route-highlight').setData(empty);
            if (this.map.getSource('ctp-entry-points')) this.map.getSource('ctp-entry-points').setData(empty);
            if (this.map.getSource('ctp-exit-points')) this.map.getSource('ctp-exit-points').setData(empty);

            state.mapHighlightedId = null;
        },

        highlightFlight: function(ctpControlId) {
            if (!state.mapReady) return;

            state.mapHighlightedId = ctpControlId;

            if (!ctpControlId) {
                if (this.map.getSource('ctp-route-highlight')) {
                    this.map.getSource('ctp-route-highlight').setData({ type: 'FeatureCollection', features: [] });
                }
                return;
            }

            // Find the route feature matching this ID
            var source = this.map.getSource('ctp-routes');
            if (!source || !source._data) return;

            var data = source._data;
            if (!data.features) return;

            var matchedFeature = null;
            for (var i = 0; i < data.features.length; i++) {
                if (data.features[i].id === ctpControlId ||
                    (data.features[i].properties && data.features[i].properties.ctp_control_id === ctpControlId)) {
                    matchedFeature = data.features[i];
                    break;
                }
            }

            if (matchedFeature && this.map.getSource('ctp-route-highlight')) {
                this.map.getSource('ctp-route-highlight').setData({
                    type: 'FeatureCollection',
                    features: [matchedFeature]
                });

                // Pan to the route
                this.fitToFeature(matchedFeature);
            } else {
                this.map.getSource('ctp-route-highlight').setData({ type: 'FeatureCollection', features: [] });
            }
        },

        fitToBoundaries: function(geojson) {
            if (!geojson.features || geojson.features.length === 0) return;

            try {
                var bounds = new maplibregl.LngLatBounds();
                geojson.features.forEach(function(f) {
                    if (!f.geometry || !f.geometry.coordinates) return;
                    var coords = f.geometry.coordinates;
                    var flat = [];

                    if (f.geometry.type === 'Polygon') {
                        flat = coords[0];
                    } else if (f.geometry.type === 'MultiPolygon') {
                        coords.forEach(function(poly) { flat = flat.concat(poly[0]); });
                    }

                    flat.forEach(function(c) {
                        if (Array.isArray(c) && c.length >= 2) bounds.extend(c);
                    });
                });

                if (!bounds.isEmpty()) {
                    this.map.fitBounds(bounds, { padding: 40, duration: 1000 });
                }
            } catch (e) {
                console.warn('[CTP] fitToBoundaries error:', e);
            }
        },

        fitToFeature: function(feature) {
            if (!feature || !feature.geometry) return;

            try {
                var bounds = new maplibregl.LngLatBounds();
                var coords = feature.geometry.coordinates;

                if (feature.geometry.type === 'LineString') {
                    coords.forEach(function(c) { bounds.extend(c); });
                } else if (feature.geometry.type === 'MultiLineString') {
                    coords.forEach(function(line) {
                        line.forEach(function(c) { bounds.extend(c); });
                    });
                }

                if (!bounds.isEmpty()) {
                    this.map.fitBounds(bounds, { padding: 60, duration: 800, maxZoom: 7 });
                }
            } catch (e) {
                console.warn('[CTP] fitToFeature error:', e);
            }
        },

        resize: function() {
            if (this.map) {
                this.map.resize();
            }
        }
    };

    // ========================================================================
    // Route Editor (three-tab segment editor in right sidebar)
    // ========================================================================
    var RouteEditor = {
        currentFlight: null,
        activeSegment: 'NA',
        segmentData: { NA: {}, OCEANIC: {}, EU: {} },
        validationResult: null,

        init: function() {
            var self = this;

            // Tab clicks
            $('#ctp_route_tabs .nav-link').on('click', function(e) {
                e.preventDefault();
                var seg = $(this).data('segment');
                if (seg) self.switchTab(seg);
            });

            // Suggest button
            $('#ctp_btn_suggest').on('click', function() {
                self.fetchSuggestions();
            });

            // Validate button
            $('#ctp_btn_validate').on('click', function() {
                self.validateRoute();
            });

            // Save segment button
            $('#ctp_btn_save_segment').on('click', function() {
                self.saveSegment();
            });

            // Close sidebar
            $('#ctp_sidebar_close').on('click', function() {
                self.close();
            });
        },

        open: function(ctpControlId) {
            if (!ctpControlId || !state.currentSession) return;
            var self = this;

            // Find flight in current state
            var flight = null;
            for (var i = 0; i < state.flights.length; i++) {
                if (state.flights[i].ctp_control_id == ctpControlId) {
                    flight = state.flights[i];
                    break;
                }
            }
            if (!flight) return;

            this.currentFlight = flight;
            this.validationResult = null;
            $('#ctp_validation_result').hide().empty();
            $('#ctp_route_suggestions_wrapper').hide();
            $('#ctp_route_suggestions').empty();

            // Set sidebar title
            $('#ctp_sidebar_title').text(
                (flight.callsign || '???') + ' (' +
                (flight.dep_airport || '?') + ' \u2192 ' + (flight.arr_airport || '?') + ')'
            );

            // Flight info summary
            var info = '<div class="small">';
            info += '<div class="d-flex justify-content-between mb-1">';
            info += '<span class="text-muted">' + t('ctp.flights.type') + '</span>';
            info += '<strong>' + (flight.aircraft_type || '-') + '</strong></div>';
            info += '<div class="d-flex justify-content-between mb-1">';
            info += '<span class="text-muted">' + t('ctp.flights.entryFix') + '</span>';
            info += '<strong>' + (flight.oceanic_entry_fix || '-') + '</strong></div>';
            info += '<div class="d-flex justify-content-between mb-1">';
            info += '<span class="text-muted">' + t('ctp.flights.exitFix') + '</span>';
            info += '<strong>' + (flight.oceanic_exit_fix || '-') + '</strong></div>';
            info += '<div class="d-flex justify-content-between mb-1">';
            info += '<span class="text-muted">' + t('ctp.flights.entryUtcFull') + '</span>';
            info += '<strong>' + self.formatUtc(flight.oceanic_entry_utc) + '</strong></div>';
            info += '</div>';
            $('#ctp_sidebar_flight_info').html(info);

            // Populate segment data from flight record
            this.segmentData = {
                NA: {
                    original: flight.seg_na_route || self.extractSegment(flight.filed_route, 'NA'),
                    modified: flight.seg_na_route || '',
                    status: flight.seg_na_status || 'FILED'
                },
                OCEANIC: {
                    original: flight.seg_oceanic_route || self.extractSegment(flight.filed_route, 'OCEANIC'),
                    modified: flight.seg_oceanic_route || '',
                    status: flight.seg_oceanic_status || 'FILED'
                },
                EU: {
                    original: flight.seg_eu_route || self.extractSegment(flight.filed_route, 'EU'),
                    modified: flight.seg_eu_route || '',
                    status: flight.seg_eu_status || 'FILED'
                }
            };

            // Set perspective-based editability
            var perspectives = state.userPerspectives || [];
            var allEditable = perspectives.length === 0; // no restriction = all editable

            $('#ctp_route_tabs .nav-link').each(function() {
                var seg = $(this).data('segment');
                var canEdit = allEditable || perspectives.indexOf(seg) >= 0;
                $(this).toggleClass('disabled text-muted', !canEdit);
            });

            // Notes and altitude
            $('#ctp_seg_notes').val(flight.notes || '');
            $('#ctp_seg_altitude').val(flight.modified_altitude ? Math.round(flight.modified_altitude / 100) : '');

            // Switch to first editable tab or default NA
            var firstEditable = 'NA';
            if (!allEditable && perspectives.length > 0) {
                firstEditable = perspectives[0];
            }
            this.switchTab(firstEditable);

            // Show sidebar
            $('#ctp_sidebar').removeClass('d-none');

            // Load audit log
            this.loadAuditLog(ctpControlId);
        },

        close: function() {
            this.currentFlight = null;
            $('#ctp_sidebar').addClass('d-none');
            // Deselect in table
            $('#ctp_flight_tbody tr.ctp-row-selected').removeClass('ctp-row-selected');
            MapController.highlightFlight(null);
        },

        switchTab: function(segment) {
            this.activeSegment = segment;

            // Update tab UI
            $('#ctp_route_tabs .nav-link').removeClass('active');
            $('#ctp_route_tabs .nav-link[data-segment="' + segment + '"]').addClass('active');

            var data = this.segmentData[segment] || {};
            var perspectives = state.userPerspectives || [];
            var canEdit = perspectives.length === 0 || perspectives.indexOf(segment) >= 0;

            // Status badge
            var status = (data.status || 'FILED').toUpperCase();
            var statusClass = 'badge-filed';
            if (status === 'MODIFIED') statusClass = 'badge-modified';
            else if (status === 'VALIDATED') statusClass = 'badge-validated';
            $('#ctp_seg_status_badge').text(status).attr('class', 'badge ctp-badge ml-1 ' + statusClass);

            // Original route
            $('#ctp_seg_original').text(data.original || t('ctp.route.filed'));

            // Modified route input
            $('#ctp_seg_route_input').val(data.modified || '').prop('disabled', !canEdit);

            // Action buttons
            $('#ctp_btn_save_segment').prop('disabled', !canEdit);
            $('#ctp_btn_validate').prop('disabled', !canEdit);

            // Clear validation and suggestions
            $('#ctp_validation_result').hide().empty();
            $('#ctp_route_suggestions_wrapper').hide();
            $('#ctp_route_suggestions').empty();
        },

        extractSegment: function(filedRoute, segment) {
            // Basic fallback: full route for any segment if no decomposition exists
            if (!filedRoute) return '';
            return filedRoute;
        },

        formatUtc: function(val) {
            if (!val) return '-';
            try {
                var d = new Date(val);
                if (isNaN(d.getTime())) return val;
                return ('0' + d.getUTCHours()).slice(-2) + ':' + ('0' + d.getUTCMinutes()).slice(-2) + 'Z';
            } catch (e) {
                return val;
            }
        },

        fetchSuggestions: function() {
            if (!this.currentFlight || !state.currentSession) return;
            var self = this;
            var flight = this.currentFlight;
            var segment = this.activeSegment;

            $('#ctp_route_suggestions').html('<div class="text-muted small"><i class="fas fa-spinner fa-spin"></i> ' + t('ctp.route.loadingSuggestions') + '</div>');
            $('#ctp_route_suggestions_wrapper').show();

            $.ajax({
                url: API.routes.suggest,
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    session_id: state.currentSession,
                    dep_airport: flight.dep_airport || '',
                    arr_airport: flight.arr_airport || '',
                    is_event_flight: !!flight.is_event_flight,
                    segment: segment
                }),
                success: function(resp) {
                    if (resp.status !== 'ok' || !resp.data) {
                        $('#ctp_route_suggestions').html('<div class="text-muted small">' + t('ctp.route.noSuggestions') + '</div>');
                        return;
                    }

                    var segKey = segment.toLowerCase() + '_suggestions';
                    var suggestions = resp.data[segKey] || [];
                    if (suggestions.length === 0) {
                        $('#ctp_route_suggestions').html('<div class="text-muted small">' + t('ctp.route.noSuggestions') + '</div>');
                        return;
                    }

                    self.renderSuggestions(suggestions);
                },
                error: function() {
                    $('#ctp_route_suggestions').html('<div class="text-danger small">' + t('ctp.dialog.networkError') + '</div>');
                }
            });
        },

        renderSuggestions: function(suggestions) {
            var self = this;
            var html = '';
            for (var i = 0; i < suggestions.length; i++) {
                var s = suggestions[i];
                var sourceLabel = self.getSourceLabel(s.source);
                var scoreClass = s.score >= 80 ? 'text-success' : (s.score >= 40 ? 'text-info' : 'text-muted');

                html += '<div class="ctp-suggestion-card" data-idx="' + i + '">';
                html += '<div class="d-flex justify-content-between align-items-center mb-1">';
                html += '<span class="font-weight-bold small">' + self.escHtml(s.name) + '</span>';
                html += '<span class="badge badge-light small">' + sourceLabel + '</span>';
                html += '</div>';
                html += '<div class="ctp-suggestion-route">' + self.escHtml(s.route) + '</div>';
                html += '<div class="d-flex justify-content-between align-items-center mt-1">';
                html += '<span class="' + scoreClass + ' small">\u2605 ' + s.score + '</span>';
                html += '<button class="btn btn-sm btn-outline-primary ctp-apply-suggestion" data-route="' + self.escAttr(s.route) + '">' + t('ctp.route.apply') + '</button>';
                html += '</div>';
                html += '</div>';
            }
            $('#ctp_route_suggestions').html(html);

            // Bind apply buttons
            $('#ctp_route_suggestions .ctp-apply-suggestion').on('click', function() {
                var route = $(this).data('route');
                $('#ctp_seg_route_input').val(route);
            });
        },

        getSourceLabel: function(source) {
            switch (source) {
                case 'ctp_template': return t('ctp.route.sourceTemplate');
                case 'reroute':     return t('ctp.route.sourceReroute');
                case 'public_route': return t('ctp.route.sourcePublic');
                case 'cdr':         return t('ctp.route.sourceCdr');
                case 'playbook':    return t('ctp.route.sourcePlaybook');
                default:            return source;
            }
        },

        validateRoute: function() {
            if (!this.currentFlight) return;
            var self = this;
            var routeStr = $('#ctp_seg_route_input').val().trim();
            if (!routeStr) return;

            var $result = $('#ctp_validation_result');
            $result.html('<div class="text-muted small"><i class="fas fa-spinner fa-spin"></i> ' + t('ctp.route.validating') + '</div>').show();

            $.ajax({
                url: API.flights.validate_route,
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    route_string: routeStr,
                    session_id: state.currentSession,
                    dep_airport: this.currentFlight.dep_airport,
                    arr_airport: this.currentFlight.arr_airport,
                    altitude: parseInt($('#ctp_seg_altitude').val()) || null
                }),
                success: function(resp) {
                    if (resp.status !== 'ok' || !resp.data || !resp.data.validation) {
                        $result.html('<div class="alert alert-warning small py-1 px-2 mb-0">' + t('ctp.route.validationUnavailable') + '</div>');
                        return;
                    }

                    self.validationResult = resp.data.validation;
                    var v = resp.data.validation;
                    var html = '';

                    if (v.valid) {
                        html += '<div class="alert alert-success small py-1 px-2 mb-1">';
                        html += '<i class="fas fa-check-circle mr-1"></i>' + t('ctp.route.validationPassed');
                        html += '</div>';
                    } else {
                        html += '<div class="alert alert-danger small py-1 px-2 mb-1">';
                        html += '<i class="fas fa-times-circle mr-1"></i>' + t('ctp.route.validationFailed');
                        html += '</div>';
                    }

                    if (v.errors && v.errors.length > 0) {
                        html += '<div class="small text-danger mb-1">';
                        for (var i = 0; i < v.errors.length; i++) {
                            html += '<div>\u2022 ' + self.escHtml(v.errors[i]) + '</div>';
                        }
                        html += '</div>';
                    }

                    if (v.warnings && v.warnings.length > 0) {
                        html += '<div class="small text-warning mb-1">';
                        for (var i = 0; i < v.warnings.length; i++) {
                            html += '<div>\u26A0 ' + self.escHtml(v.warnings[i]) + '</div>';
                        }
                        html += '</div>';
                    }

                    if (v.distance_nm) {
                        html += '<div class="small text-muted">' + t('ctp.route.distance') + ': ' + Math.round(v.distance_nm) + ' nm</div>';
                    }

                    $result.html(html);
                },
                error: function() {
                    $result.html('<div class="alert alert-warning small py-1 px-2 mb-0">' + t('ctp.dialog.networkError') + '</div>');
                }
            });
        },

        saveSegment: function() {
            if (!this.currentFlight) return;
            var self = this;
            var routeStr = $('#ctp_seg_route_input').val().trim();
            if (!routeStr) return;

            var altitude = parseInt($('#ctp_seg_altitude').val()) || null;
            var notes = $('#ctp_seg_notes').val().trim() || null;

            $('#ctp_btn_save_segment').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');

            $.ajax({
                url: API.flights.modify_route,
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    ctp_control_id: this.currentFlight.ctp_control_id,
                    segment: this.activeSegment,
                    route_string: routeStr,
                    altitude: altitude,
                    notes: notes
                }),
                success: function(resp) {
                    $('#ctp_btn_save_segment').prop('disabled', false).html('<i class="fas fa-save"></i> ' + t('ctp.route.saveSegment'));

                    if (resp.status === 'ok') {
                        // Update local segment data
                        self.segmentData[self.activeSegment].modified = routeStr;
                        self.segmentData[self.activeSegment].status = 'MODIFIED';
                        self.switchTab(self.activeSegment);

                        // Show validation result if included
                        if (resp.data && resp.data.validation) {
                            self.validationResult = resp.data.validation;
                        }

                        // Refresh flight list and map
                        FlightTable.loadFlights();
                        MapController.loadRoutes();

                        if (typeof PERTIDialog !== 'undefined') {
                            PERTIDialog.success('ctp.route.saved');
                        }
                    } else {
                        var msg = (resp && resp.message) || t('ctp.dialog.unknownError');
                        if (typeof PERTIDialog !== 'undefined') {
                            PERTIDialog.error(msg);
                        }
                    }
                },
                error: function(xhr) {
                    $('#ctp_btn_save_segment').prop('disabled', false).html('<i class="fas fa-save"></i> ' + t('ctp.route.saveSegment'));
                    var msg = t('ctp.dialog.networkError');
                    try {
                        var body = JSON.parse(xhr.responseText);
                        if (body && body.message) msg = body.message;
                    } catch (e) {}
                    if (typeof PERTIDialog !== 'undefined') {
                        PERTIDialog.error(msg);
                    }
                }
            });
        },

        loadAuditLog: function(ctpControlId) {
            if (!state.currentSession) {
                $('#ctp_audit_list').html('<span class="text-muted">' + t('ctp.audit.noActions') + '</span>');
                return;
            }

            $.ajax({
                url: API.audit_log,
                method: 'GET',
                data: {
                    session_id: state.currentSession.session_id,
                    ctp_control_id: ctpControlId,
                    limit: 10
                },
                success: function(resp) {
                    if (resp.status !== 'ok' || !resp.data || !resp.data.entries || resp.data.entries.length === 0) {
                        $('#ctp_audit_list').html('<span class="text-muted">' + t('ctp.audit.noActions') + '</span>');
                        return;
                    }

                    var html = '';
                    resp.data.entries.forEach(function(e) {
                        var ts = e.performed_at ? RouteEditor.escHtml(e.performed_at).substring(11, 16) + 'Z' : '';
                        var action = RouteEditor.escHtml(e.action_type || '');
                        var who = RouteEditor.escHtml(e.performed_by || '');
                        html += '<div class="ctp-audit-entry">' +
                            '<span class="badge badge-secondary badge-sm">' + action + '</span> ' +
                            '<span class="text-muted">' + ts + '</span> ' +
                            '<span class="text-info">' + who + '</span>' +
                            '</div>';
                    });
                    $('#ctp_audit_list').html(html);
                },
                error: function() {
                    $('#ctp_audit_list').html('<span class="text-muted">' + t('ctp.audit.noActions') + '</span>');
                }
            });
        },

        escHtml: function(str) {
            if (!str) return '';
            return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        },

        escAttr: function(str) {
            return this.escHtml(str).replace(/'/g, '&#39;');
        }
    };

    // ========================================================================
    // EDCT Manager (single/batch assignment, removal)
    // ========================================================================
    var EDCTManager = {
        init: function() {
            var self = this;

            // Bulk EDCT button
            $(document).on('click', '#ctp_btn_bulk_edct', function() {
                self.openBulkModal();
            });

            // Auto-assign button
            $(document).on('click', '#ctp_btn_auto_assign', function() {
                self.openAutoAssignModal();
            });
        },

        assignSingle: function(ctpControlId, edctUtc, callback) {
            $.ajax({
                url: API.flights.assign_edct,
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    ctp_control_id: ctpControlId,
                    edct_utc: edctUtc
                }),
                success: function(resp) {
                    if (resp.status === 'ok') {
                        FlightTable.loadFlights();
                        if (typeof PERTIDialog !== 'undefined') {
                            PERTIDialog.success('ctp.edct.assignedSuccess');
                        }
                    } else {
                        if (typeof PERTIDialog !== 'undefined') {
                            PERTIDialog.error(resp.message || t('ctp.dialog.unknownError'));
                        }
                    }
                    if (callback) callback(resp);
                },
                error: function(xhr) {
                    var msg = t('ctp.dialog.networkError');
                    try { msg = JSON.parse(xhr.responseText).message || msg; } catch (e) {}
                    if (typeof PERTIDialog !== 'undefined') PERTIDialog.error(msg);
                    if (callback) callback(null);
                }
            });
        },

        removeSingle: function(ctpControlId, callback) {
            $.ajax({
                url: API.flights.remove_edct,
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ ctp_control_id: ctpControlId }),
                success: function(resp) {
                    if (resp.status === 'ok') {
                        FlightTable.loadFlights();
                        if (typeof PERTIDialog !== 'undefined') {
                            PERTIDialog.success('ctp.edct.removedSuccess');
                        }
                    }
                    if (callback) callback(resp);
                },
                error: function(xhr) {
                    var msg = t('ctp.dialog.networkError');
                    try { msg = JSON.parse(xhr.responseText).message || msg; } catch (e) {}
                    if (typeof PERTIDialog !== 'undefined') PERTIDialog.error(msg);
                    if (callback) callback(null);
                }
            });
        },

        openBulkModal: function() {
            var selected = Array.from(state.selectedIds);
            if (selected.length === 0) {
                if (typeof PERTIDialog !== 'undefined') {
                    PERTIDialog.warning(t('ctp.edct.selectFlights'));
                }
                return;
            }

            var self = this;
            if (typeof Swal === 'undefined') return;

            Swal.fire({
                title: t('ctp.edct.bulkAssign'),
                html: '<div class="form-group text-left">' +
                      '<label>' + t('ctp.edct.baseTime') + '</label>' +
                      '<input type="datetime-local" class="form-control" id="swal_bulk_base_time">' +
                      '</div>' +
                      '<div class="form-group text-left">' +
                      '<label>' + t('ctp.edct.interval') + '</label>' +
                      '<input type="number" class="form-control" id="swal_bulk_interval" value="5" min="1" max="60">' +
                      '</div>' +
                      '<p class="text-muted small">' + t('ctp.edct.bulkDescription', { count: selected.length }) + '</p>',
                showCancelButton: true,
                confirmButtonText: t('ctp.edct.assignAll'),
                preConfirm: function() {
                    var baseTime = document.getElementById('swal_bulk_base_time').value;
                    var interval = parseInt(document.getElementById('swal_bulk_interval').value) || 5;
                    if (!baseTime) {
                        Swal.showValidationMessage(t('ctp.edct.baseTimeRequired'));
                        return false;
                    }
                    return { baseTime: baseTime + ':00Z', interval: interval };
                }
            }).then(function(result) {
                if (!result.isConfirmed) return;
                self.executeBatch(selected, result.value.baseTime, result.value.interval);
            });
        },

        openAutoAssignModal: function() {
            if (!state.currentSession) return;
            var self = this;
            if (typeof Swal === 'undefined') return;

            Swal.fire({
                title: t('ctp.edct.autoAssign'),
                html: '<div class="form-group text-left">' +
                      '<label>' + t('ctp.edct.baseTime') + '</label>' +
                      '<input type="datetime-local" class="form-control" id="swal_auto_base_time">' +
                      '</div>' +
                      '<p class="text-muted small">' + t('ctp.edct.autoDescription') + '</p>',
                showCancelButton: true,
                confirmButtonText: t('ctp.edct.autoAssign'),
                preConfirm: function() {
                    var baseTime = document.getElementById('swal_auto_base_time').value;
                    if (!baseTime) {
                        Swal.showValidationMessage(t('ctp.edct.baseTimeRequired'));
                        return false;
                    }
                    return { baseTime: baseTime + ':00Z' };
                }
            }).then(function(result) {
                if (!result.isConfirmed) return;

                $.ajax({
                    url: API.flights.assign_edct_batch,
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({
                        session_id: state.currentSession,
                        auto_assign: true,
                        base_time_utc: result.value.baseTime
                    }),
                    success: function(resp) {
                        if (resp.status === 'ok' && resp.data) {
                            FlightTable.loadFlights();
                            DemandChart.refresh();
                            if (typeof PERTIDialog !== 'undefined') {
                                PERTIDialog.success(t('ctp.edct.batchResult', {
                                    assigned: resp.data.assigned,
                                    total: resp.data.total
                                }));
                            }
                        }
                    },
                    error: function() {
                        if (typeof PERTIDialog !== 'undefined') PERTIDialog.error(t('ctp.dialog.networkError'));
                    }
                });
            });
        },

        executeBatch: function(flightIds, baseTime, interval) {
            $.ajax({
                url: API.flights.assign_edct_batch,
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    session_id: state.currentSession,
                    auto_assign: true,
                    base_time_utc: baseTime,
                    interval_min: interval,
                    flight_ids: flightIds
                }),
                success: function(resp) {
                    if (resp.status === 'ok' && resp.data) {
                        state.selectedIds.clear();
                        FlightTable.loadFlights();
                        DemandChart.refresh();
                        if (typeof PERTIDialog !== 'undefined') {
                            PERTIDialog.success(t('ctp.edct.batchResult', {
                                assigned: resp.data.assigned,
                                total: resp.data.total
                            }));
                        }
                    }
                },
                error: function() {
                    if (typeof PERTIDialog !== 'undefined') PERTIDialog.error(t('ctp.dialog.networkError'));
                }
            });
        }
    };

    // ========================================================================
    // Demand Chart (ECharts stacked bar for oceanic entry demand)
    // ========================================================================
    var DemandChart = {
        chart: null,
        container: null,
        resizeHandler: null,

        init: function() {
            this.container = document.getElementById('ctp_demand_chart_container');

            // Bind group_by dropdown
            var groupBySelect = document.getElementById('ctp_demand_group_by');
            if (groupBySelect) {
                groupBySelect.addEventListener('change', function() {
                    state.demandGroupBy = this.value;
                    DemandChart.refresh();
                });
            }
        },

        refresh: function() {
            if (!this.container || !state.currentSession) return;
            if (typeof echarts === 'undefined') return;
            var self = this;

            $.ajax({
                url: API.demand,
                method: 'GET',
                data: {
                    session_id: state.currentSession,
                    group_by: state.demandGroupBy || 'status',
                    bin_min: state.demandBinMin || 60
                },
                success: function(resp) {
                    if (resp.status !== 'ok' || !resp.data) return;
                    self.render(resp.data);
                }
            });
        },

        render: function(data) {
            if (!this.container) return;

            var binMin = data.bin_min || 60;
            var series = [];
            var datasets = data.datasets || [];

            for (var i = 0; i < datasets.length; i++) {
                var ds = datasets[i];
                var seriesData = [];
                for (var j = 0; j < (data.labels || []).length; j++) {
                    var parts = data.labels[j].split(':');
                    var ts = Date.UTC(2026, 0, 1, parseInt(parts[0]), parseInt(parts[1]));
                    seriesData.push([ts + (binMin * 30000), ds.data[j] || 0]);
                }

                var seriesItem = {
                    name: ds.label,
                    type: 'bar',
                    stack: 'demand',
                    data: seriesData,
                    itemStyle: { color: ds.backgroundColor },
                    barMaxWidth: 30
                };

                if (i === 0 && data.rate_cap_per_bin) {
                    seriesItem.markLine = {
                        silent: true,
                        symbol: 'none',
                        lineStyle: { color: '#ff5252', type: 'dashed', width: 2 },
                        data: [{ yAxis: data.rate_cap_per_bin,
                                 label: { formatter: t('ctp.demand.rateCap') + ': ' + data.rate_cap_per_bin } }]
                    };
                }

                series.push(seriesItem);
            }

            if (this.chart) { this.chart.dispose(); }
            this.chart = echarts.init(this.container);

            var option = {
                tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' } },
                legend: { top: 0, textStyle: { fontSize: 10 }, itemWidth: 12 },
                grid: { left: 50, right: 20, top: 40, bottom: 30 },
                xAxis: {
                    type: 'time',
                    name: t('ctp.demand.timeUtc'),
                    axisLabel: { fontSize: 10, formatter: function(v) {
                        var d = new Date(v);
                        return ('0'+d.getUTCHours()).slice(-2) + ':' + ('0'+d.getUTCMinutes()).slice(-2);
                    }}
                },
                yAxis: {
                    type: 'value',
                    name: t('ctp.demand.flights'),
                    minInterval: 1
                },
                series: series
            };

            this.chart.setOption(option);

            // Handle resize (debounced, single listener)
            if (!this.resizeHandler) {
                var self = this;
                this.resizeHandler = function() { if (self.chart) self.chart.resize(); };
                window.addEventListener('resize', this.resizeHandler);
            }
        },

        clear: function() {
            if (this.chart) {
                this.chart.dispose();
                this.chart = null;
            }
        }
    };

    // ========================================================================
    // Compliance Monitor (periodic compliance check + status display)
    // ========================================================================
    var ComplianceMonitor = {
        init: function() {
            var self = this;

            // Check compliance button
            $(document).on('click', '#ctp_btn_check_compliance', function() {
                self.runCheck();
            });

            // Exclude button (toolbar)
            $(document).on('click', '#ctp_btn_exclude', function() {
                self.excludeSelected(true);
            });

            // Include button (toolbar)
            $(document).on('click', '#ctp_btn_include', function() {
                self.excludeSelected(false);
            });

            // Complete session button
            $(document).on('click', '#ctp_btn_complete_session', function() {
                self.completeSession('COMPLETED');
            });

            // Cancel session button
            $(document).on('click', '#ctp_btn_cancel_session', function() {
                self.completeSession('CANCELLED');
            });
        },

        runCheck: function() {
            if (!state.currentSession) return;

            $.ajax({
                url: API.flights.compliance,
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ session_id: state.currentSession.session_id }),
                success: function(resp) {
                    if (resp.status === 'ok' && resp.data) {
                        var d = resp.data;
                        var msg = t('ctp.compliance.checkResult', {
                            onTime: d.on_time || 0,
                            early: d.early || 0,
                            late: d.late || 0,
                            noShow: d.no_show || 0,
                            pending: d.pending || 0
                        });
                        if (typeof PERTIDialog !== 'undefined') {
                            PERTIDialog.success(msg);
                        }
                        FlightTable.load();
                    }
                },
                error: function() {
                    if (typeof PERTIDialog !== 'undefined') PERTIDialog.error(t('ctp.dialog.networkError'));
                }
            });
        },

        excludeSelected: function(exclude) {
            var ids = Array.from(state.selectedIds);
            if (ids.length === 0) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire(t('ctp.dialog.error'), t('ctp.exclude.selectFirst'), 'warning');
                }
                return;
            }

            $.ajax({
                url: API.flights.exclude,
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    ctp_control_ids: ids,
                    exclude: exclude
                }),
                success: function(resp) {
                    if (resp.status === 'ok') {
                        state.selectedIds.clear();
                        FlightTable.load();
                        if (typeof PERTIDialog !== 'undefined') {
                            PERTIDialog.success(t(exclude ? 'ctp.exclude.excluded' : 'ctp.exclude.included', {
                                count: resp.data.updated || ids.length
                            }));
                        }
                    }
                },
                error: function() {
                    if (typeof PERTIDialog !== 'undefined') PERTIDialog.error(t('ctp.dialog.networkError'));
                }
            });
        },

        completeSession: function(newStatus) {
            if (!state.currentSession) return;

            var confirmKey = newStatus === 'CANCELLED' ? 'ctp.session.confirmCancel' : 'ctp.session.confirmComplete';
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    title: t(confirmKey),
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: t('common.confirm'),
                    cancelButtonText: t('common.cancel')
                }).then(function(result) {
                    if (!result.isConfirmed) return;

                    $.ajax({
                        url: API.sessions_complete,
                        method: 'POST',
                        contentType: 'application/json',
                        data: JSON.stringify({
                            session_id: state.currentSession.session_id,
                            status: newStatus
                        }),
                        success: function(resp) {
                            if (resp.status === 'ok') {
                                SessionManager.loadSessions();
                                SessionManager.selectSession(state.currentSession.session_id);
                                if (typeof PERTIDialog !== 'undefined') {
                                    PERTIDialog.success(t('ctp.session.statusChanged', { status: newStatus }));
                                }
                            }
                        },
                        error: function() {
                            if (typeof PERTIDialog !== 'undefined') PERTIDialog.error(t('ctp.dialog.networkError'));
                        }
                    });
                });
            }
        },

        refreshCompliance: function() {
            // Called by auto-refresh cycle — lightweight compliance summary fetch
            if (!state.currentSession) return;
            var s = state.currentSession;
            if (s.status !== 'ACTIVE' && s.status !== 'MONITORING') return;

            $.ajax({
                url: API.flights.compliance,
                method: 'GET',
                data: { session_id: s.session_id },
                success: function(resp) {
                    if (resp.status === 'ok' && resp.data) {
                        ComplianceMonitor.updateBadge(resp.data);
                    }
                }
            });
        },

        updateBadge: function(data) {
            var $badge = $('#ctp_compliance_badge');
            if (!$badge.length) return;

            var total = (data.on_time || 0) + (data.early || 0) + (data.late || 0) + (data.no_show || 0);
            if (total === 0) {
                $badge.hide();
                return;
            }

            var compliant = (data.on_time || 0) + (data.early || 0);
            var pct = Math.round((compliant / total) * 100);
            var cls = pct >= 80 ? 'badge-success' : (pct >= 50 ? 'badge-warning' : 'badge-danger');

            $badge.attr('class', 'badge ' + cls + ' ml-1')
                  .text(pct + '% ' + t('ctp.compliance.compliant'))
                  .show();
        }
    };

    // ========================================================================
    // Resize Handle (drag to resize map/table split)
    // ========================================================================
    var ResizeHandle = {
        init: function() {
            var handle = document.getElementById('ctp_resize_handle');
            var container = document.getElementById('ctp_container');
            var mapSection = document.getElementById('ctp_map_section');

            if (!handle || !container || !mapSection) return;

            var dragging = false;
            var startY = 0;
            var startHeight = 0;

            handle.addEventListener('mousedown', function(e) {
                e.preventDefault();
                dragging = true;
                startY = e.clientY;
                startHeight = mapSection.offsetHeight;
                document.body.style.cursor = 'ns-resize';
                document.body.style.userSelect = 'none';
            });

            document.addEventListener('mousemove', function(e) {
                if (!dragging) return;
                var delta = e.clientY - startY;
                var newHeight = Math.max(150, Math.min(startHeight + delta, container.offsetHeight - 250));
                mapSection.style.flex = '0 0 ' + newHeight + 'px';
            });

            document.addEventListener('mouseup', function() {
                if (dragging) {
                    dragging = false;
                    document.body.style.cursor = '';
                    document.body.style.userSelect = '';
                    MapController.resize();
                }
            });
        }
    };

    // ========================================================================
    // NAT Tracks Reference
    // ========================================================================

    var NATTracks = {
        loaded: false,

        init: function() {
            $('#ctp_nat_toggle').on('click', function() {
                var $body = $('#ctp_nat_body');
                var $icon = $(this).find('.fa-chevron-down, .fa-chevron-up');
                $body.slideToggle(200);
                $icon.toggleClass('fa-chevron-down fa-chevron-up');
                if (!NATTracks.loaded) {
                    NATTracks.load();
                }
            });
        },

        load: function() {
            if (!state.currentSession) return;
            $.getJSON(API.nat_tracks, { session_id: state.currentSession.session_id }, function(resp) {
                NATTracks.loaded = true;
                var $tbody = $('#ctp_nat_tbody');
                $tbody.empty();
                if (!resp || !resp.tracks || resp.tracks.length === 0) {
                    $tbody.html('<tr><td colspan="2" class="text-center text-muted py-2">' + t('ctp.nat.noTracks') + '</td></tr>');
                    return;
                }
                resp.tracks.forEach(function(trk) {
                    var $row = $('<tr>');
                    $row.append($('<td>').addClass('font-weight-bold').css('color', '#239BCD').text(trk.name || ''));
                    $row.append($('<td>').css({'font-family': 'Inconsolata, monospace', 'font-size': '0.65rem', 'word-break': 'break-all'}).text(trk.route_string || ''));
                    $tbody.append($row);
                });
            }).fail(function() {
                $('#ctp_nat_tbody').html('<tr><td colspan="2" class="text-center text-danger">' + t('ctp.nat.loadError') + '</td></tr>');
            });
        }
    };

    // ========================================================================
    // Enhanced Audit Log
    // ========================================================================

    var AuditLog = {
        page: 0,
        pageSize: 20,

        init: function() {
            $('#ctp_audit_toggle').on('click', function() {
                var $body = $('#ctp_audit_body');
                var $icon = $(this).find('.fa-chevron-down, .fa-chevron-up');
                $body.slideToggle(200);
                $icon.toggleClass('fa-chevron-down fa-chevron-up');
            });

            $('#ctp_audit_load_more').on('click', function() {
                AuditLog.page++;
                AuditLog.load(true);
            });
        },

        load: function(append) {
            if (!state.currentSession) return;
            var params = {
                session_id: state.currentSession.session_id,
                limit: AuditLog.pageSize,
                offset: AuditLog.page * AuditLog.pageSize
            };
            $.getJSON(API.changelog, params, function(resp) {
                var $list = $('#ctp_audit_list');
                if (!append) $list.empty();

                if (!resp || !resp.data || resp.data.length === 0) {
                    if (!append) {
                        $list.html('<div class="text-center text-muted py-2">' + t('ctp.changelog.noEntries') + '</div>');
                    }
                    $('#ctp_audit_load_more').hide();
                    return;
                }

                resp.data.forEach(function(entry) {
                    var $item = $('<div>').addClass('ctp-audit-entry border-bottom py-1');

                    var timeStr = '--';
                    if (entry.performed_at) {
                        try {
                            var d = new Date(entry.performed_at);
                            timeStr = d.toISOString().substring(11, 16) + 'Z';
                        } catch(e) {}
                    }

                    var who = entry.performed_by_name || entry.performed_by || t('common.unknown');
                    var action = entry.action_type || '';
                    var segment = entry.segment ? ' [' + entry.segment + ']' : '';

                    $item.append(
                        $('<div>').addClass('d-flex justify-content-between').append(
                            $('<span>').addClass('font-weight-bold').text(action + segment),
                            $('<span>').addClass('text-muted').text(timeStr)
                        )
                    );
                    $item.append($('<div>').addClass('text-muted').text(t('ctp.changelog.by') + ' ' + who));

                    // Show before/after values if present
                    var rawDetail = entry.action_detail || entry.action_detail_json;
                    if (rawDetail) {
                        try {
                            var detail = typeof rawDetail === 'string' ? JSON.parse(rawDetail) : rawDetail;
                            if (detail.old_value !== undefined || detail.new_value !== undefined) {
                                var $diff = $('<div>').addClass('mt-1').css('font-size', '0.65rem');
                                if (detail.old_value !== undefined && detail.old_value !== null) {
                                    $diff.append($('<div>').css({'color': '#dc3545', 'font-family': 'Inconsolata, monospace'}).text('- ' + detail.old_value));
                                }
                                if (detail.new_value !== undefined && detail.new_value !== null) {
                                    $diff.append($('<div>').css({'color': '#28a745', 'font-family': 'Inconsolata, monospace'}).text('+ ' + detail.new_value));
                                }
                                $item.append($diff);
                            }
                        } catch(e) {}
                    }

                    $list.append($item);
                });

                $('#ctp_audit_load_more').toggle(resp.data.length >= AuditLog.pageSize);
            });
        },

        reset: function() {
            AuditLog.page = 0;
            $('#ctp_audit_list').empty();
            $('#ctp_audit_load_more').hide();
        }
    };

    // ========================================================================
    // Throughput Config Manager
    // ========================================================================
    var ThroughputManager = {
        configs: [],

        init: function() {
            var self = this;
            // Bind create button
            $(document).on('click', '#ctp_throughput_create', function() { self.showCreateDialog(); });
            // Bind refresh when tab shown
            $(document).on('shown.bs.tab', 'a[href="#ctp_throughput_panel"]', function() { self.refresh(); });
        },

        refresh: function() {
            if (!state.currentSession) return;
            var self = this;

            $.ajax({
                url: API.throughput.list,
                data: { session_id: state.currentSession },
                success: function(resp) {
                    if (resp.status === 'ok' && resp.data) {
                        self.configs = resp.data.configs || [];
                        self.renderTable(self.configs);
                    }
                }
            });
        },

        renderTable: function(configs) {
            var $tbody = $('#ctp_throughput_table tbody');
            $tbody.empty();

            if (!configs || configs.length === 0) {
                $tbody.append('<tr><td colspan="7" class="text-center text-muted">' + t('ctp.throughput.noConfigs') + '</td></tr>');
                return;
            }

            configs.forEach(function(cfg) {
                var tracks = cfg.tracks_json ? JSON.parse(cfg.tracks_json).join(', ') : t('ctp.throughput.wildcard');
                var origins = cfg.origins_json ? JSON.parse(cfg.origins_json).join(', ') : t('ctp.throughput.wildcard');
                var dests = cfg.destinations_json ? JSON.parse(cfg.destinations_json).join(', ') : t('ctp.throughput.wildcard');

                var $row = $('<tr>')
                    .append($('<td>').text(cfg.config_label || ''))
                    .append($('<td>').text(tracks))
                    .append($('<td>').text(origins))
                    .append($('<td>').text(dests))
                    .append($('<td>').text(cfg.max_acph || '-'))
                    .append($('<td>').text(cfg.priority || '-'))
                    .append($('<td>').html(
                        '<button class="btn btn-xs btn-outline-primary mr-1 ctp-tp-edit" data-id="' + cfg.config_id + '"><i class="fas fa-edit"></i></button>' +
                        '<button class="btn btn-xs btn-outline-info mr-1 ctp-tp-preview" data-id="' + cfg.config_id + '"><i class="fas fa-chart-area"></i></button>' +
                        '<button class="btn btn-xs btn-outline-danger ctp-tp-delete" data-id="' + cfg.config_id + '"><i class="fas fa-trash"></i></button>'
                    ));

                $tbody.append($row);
            });

            // Bind action buttons
            var self = this;
            $tbody.find('.ctp-tp-edit').on('click', function() {
                var id = $(this).data('id');
                var cfg = self.configs.find(function(c) { return c.config_id === id; });
                if (cfg) self.showEditDialog(cfg);
            });
            $tbody.find('.ctp-tp-preview').on('click', function() {
                self.showPreview($(this).data('id'));
            });
            $tbody.find('.ctp-tp-delete').on('click', function() {
                self.deleteConfig($(this).data('id'));
            });
        },

        showCreateDialog: function() {
            var self = this;
            Swal.fire({
                title: t('ctp.throughput.createConfig'),
                html:
                    '<input id="swal_tp_label" class="swal2-input" placeholder="' + t('ctp.throughput.configLabel') + '">' +
                    '<input id="swal_tp_tracks" class="swal2-input" placeholder="' + t('ctp.throughput.tracks') + ' (e.g. NATA,NATB)">' +
                    '<input id="swal_tp_origins" class="swal2-input" placeholder="' + t('ctp.throughput.origins') + ' (e.g. KJFK,KBOS)">' +
                    '<input id="swal_tp_dests" class="swal2-input" placeholder="' + t('ctp.throughput.destinations') + ' (e.g. EGLL,LFPG)">' +
                    '<input id="swal_tp_max_acph" class="swal2-input" type="number" placeholder="' + t('ctp.throughput.maxAcph') + '">' +
                    '<input id="swal_tp_priority" class="swal2-input" type="number" placeholder="' + t('ctp.throughput.priority') + '" value="10">',
                focusConfirm: false,
                showCancelButton: true,
                preConfirm: function() {
                    return {
                        config_label: $('#swal_tp_label').val(),
                        tracks_json: $('#swal_tp_tracks').val() ? JSON.stringify($('#swal_tp_tracks').val().toUpperCase().split(',').map(function(s) { return s.trim(); })) : null,
                        origins_json: $('#swal_tp_origins').val() ? JSON.stringify($('#swal_tp_origins').val().toUpperCase().split(',').map(function(s) { return s.trim(); })) : null,
                        destinations_json: $('#swal_tp_dests').val() ? JSON.stringify($('#swal_tp_dests').val().toUpperCase().split(',').map(function(s) { return s.trim(); })) : null,
                        max_acph: parseInt($('#swal_tp_max_acph').val()) || null,
                        priority: parseInt($('#swal_tp_priority').val()) || 10
                    };
                }
            }).then(function(result) {
                if (result.isConfirmed && result.value) {
                    result.value.session_id = state.currentSession;
                    $.ajax({
                        url: API.throughput.create,
                        method: 'POST',
                        contentType: 'application/json',
                        data: JSON.stringify(result.value),
                        success: function(resp) {
                            if (resp.status === 'ok') {
                                Swal.fire({ icon: 'success', title: t('ctp.throughput.saved'), timer: 1500, showConfirmButton: false });
                                self.refresh();
                            } else {
                                Swal.fire({ icon: 'error', title: resp.message || 'Error' });
                            }
                        }
                    });
                }
            });
        },

        showEditDialog: function(cfg) {
            var self = this;
            var tracks = cfg.tracks_json ? JSON.parse(cfg.tracks_json).join(',') : '';
            var origins = cfg.origins_json ? JSON.parse(cfg.origins_json).join(',') : '';
            var dests = cfg.destinations_json ? JSON.parse(cfg.destinations_json).join(',') : '';

            Swal.fire({
                title: t('ctp.throughput.editConfig'),
                html:
                    '<input id="swal_tp_label" class="swal2-input" value="' + (cfg.config_label || '') + '" placeholder="' + t('ctp.throughput.configLabel') + '">' +
                    '<input id="swal_tp_tracks" class="swal2-input" value="' + tracks + '" placeholder="' + t('ctp.throughput.tracks') + '">' +
                    '<input id="swal_tp_origins" class="swal2-input" value="' + origins + '" placeholder="' + t('ctp.throughput.origins') + '">' +
                    '<input id="swal_tp_dests" class="swal2-input" value="' + dests + '" placeholder="' + t('ctp.throughput.destinations') + '">' +
                    '<input id="swal_tp_max_acph" class="swal2-input" type="number" value="' + (cfg.max_acph || '') + '" placeholder="' + t('ctp.throughput.maxAcph') + '">' +
                    '<input id="swal_tp_priority" class="swal2-input" type="number" value="' + (cfg.priority || 10) + '" placeholder="' + t('ctp.throughput.priority') + '">',
                focusConfirm: false,
                showCancelButton: true,
                preConfirm: function() {
                    return {
                        config_id: cfg.config_id,
                        config_label: $('#swal_tp_label').val(),
                        tracks_json: $('#swal_tp_tracks').val() ? JSON.stringify($('#swal_tp_tracks').val().toUpperCase().split(',').map(function(s) { return s.trim(); })) : null,
                        origins_json: $('#swal_tp_origins').val() ? JSON.stringify($('#swal_tp_origins').val().toUpperCase().split(',').map(function(s) { return s.trim(); })) : null,
                        destinations_json: $('#swal_tp_dests').val() ? JSON.stringify($('#swal_tp_dests').val().toUpperCase().split(',').map(function(s) { return s.trim(); })) : null,
                        max_acph: parseInt($('#swal_tp_max_acph').val()) || null,
                        priority: parseInt($('#swal_tp_priority').val()) || 10,
                        expected_updated_at: cfg.updated_at
                    };
                }
            }).then(function(result) {
                if (result.isConfirmed && result.value) {
                    $.ajax({
                        url: API.throughput.update,
                        method: 'POST',
                        contentType: 'application/json',
                        data: JSON.stringify(result.value),
                        success: function(resp) {
                            if (resp.status === 'ok') {
                                Swal.fire({ icon: 'success', title: t('ctp.throughput.updated'), timer: 1500, showConfirmButton: false });
                                self.refresh();
                            } else if (resp.code === 409) {
                                Swal.fire({ icon: 'warning', title: t('ctp.throughput.conflictDetected', { configs: '' }) });
                                self.refresh();
                            } else {
                                Swal.fire({ icon: 'error', title: resp.message || 'Error' });
                            }
                        }
                    });
                }
            });
        },

        deleteConfig: function(configId) {
            var self = this;
            Swal.fire({
                title: t('ctp.throughput.deleteConfig'),
                text: t('ctp.throughput.confirmDelete'),
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545'
            }).then(function(result) {
                if (result.isConfirmed) {
                    $.ajax({
                        url: API.throughput.delete,
                        method: 'POST',
                        contentType: 'application/json',
                        data: JSON.stringify({ config_id: configId }),
                        success: function(resp) {
                            if (resp.status === 'ok') {
                                Swal.fire({ icon: 'success', title: t('ctp.throughput.deleted'), timer: 1500, showConfirmButton: false });
                                self.refresh();
                            }
                        }
                    });
                }
            });
        },

        showPreview: function(configId) {
            if (!state.currentSession) return;

            $.ajax({
                url: API.throughput.preview,
                data: { session_id: state.currentSession, config_id: configId },
                success: function(resp) {
                    if (resp.status === 'ok' && resp.data) {
                        var d = resp.data;
                        var html = '<table class="table table-sm"><thead><tr>' +
                            '<th>' + t('ctp.demand.timeUtc') + '</th>' +
                            '<th>' + t('ctp.throughput.acph') + '</th>' +
                            '<th>' + t('ctp.throughput.maxAcph') + '</th>' +
                            '<th>' + t('ctp.throughput.utilization') + '</th>' +
                            '</tr></thead><tbody>';
                        (d.bins || []).forEach(function(bin) {
                            var pct = bin.utilization_pct || 0;
                            var cls = pct > 100 ? 'text-danger font-weight-bold' : (pct > 80 ? 'text-warning' : 'text-success');
                            html += '<tr><td>' + bin.bin_label + '</td><td>' + bin.actual_acph + '</td><td>' + bin.max_acph + '</td><td class="' + cls + '">' + pct + '%</td></tr>';
                        });
                        html += '</tbody></table>';
                        Swal.fire({ title: t('ctp.throughput.previewImpact'), html: html, width: 600 });
                    }
                }
            });
        }
    };

    // ========================================================================
    // Planning Simulator
    // ========================================================================
    var PlanningSimulator = {
        scenarios: [],
        currentScenario: null,

        init: function() {
            var self = this;
            $(document).on('click', '#ctp_planning_create', function() { self.createScenario(); });
            $(document).on('shown.bs.tab', 'a[href="#ctp_planning_panel"]', function() { self.loadScenarios(); });
        },

        loadScenarios: function() {
            if (!state.currentSession) return;
            var self = this;

            $.ajax({
                url: API.planning.scenarios,
                data: { session_id: state.currentSession },
                success: function(resp) {
                    if (resp.status === 'ok' && resp.data) {
                        self.scenarios = resp.data.scenarios || [];
                        self.renderScenarioList(self.scenarios);
                    }
                }
            });
        },

        renderScenarioList: function(scenarios) {
            var $list = $('#ctp_planning_scenario_list');
            $list.empty();

            if (!scenarios || scenarios.length === 0) {
                $list.html('<div class="text-center text-muted py-3">' + t('ctp.planning.noScenarios') + '</div>');
                return;
            }

            var self = this;
            scenarios.forEach(function(sc) {
                var $item = $('<div class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">')
                    .append($('<span>').text(sc.scenario_name || t('ctp.planning.scenario') + ' #' + sc.scenario_id))
                    .append($('<span class="btn-group btn-group-sm">')
                        .append('<button class="btn btn-outline-primary ps-compute" data-id="' + sc.scenario_id + '" title="' + t('ctp.planning.compute') + '"><i class="fas fa-calculator"></i></button>')
                        .append('<button class="btn btn-outline-secondary ps-clone" data-id="' + sc.scenario_id + '" title="' + t('ctp.planning.cloneScenario') + '"><i class="fas fa-copy"></i></button>')
                        .append('<button class="btn btn-outline-danger ps-delete" data-id="' + sc.scenario_id + '"><i class="fas fa-trash"></i></button>')
                    );
                $list.append($item);
            });

            $list.find('.ps-compute').on('click', function() { self.compute($(this).data('id')); });
            $list.find('.ps-clone').on('click', function() { self.cloneScenario($(this).data('id')); });
            $list.find('.ps-delete').on('click', function() { self.deleteScenario($(this).data('id')); });
        },

        createScenario: function() {
            var self = this;
            Swal.fire({
                title: t('ctp.planning.createScenario'),
                input: 'text',
                inputPlaceholder: t('ctp.planning.scenarioName'),
                showCancelButton: true
            }).then(function(result) {
                if (result.isConfirmed && result.value) {
                    $.ajax({
                        url: API.planning.scenarios,
                        method: 'POST',
                        contentType: 'application/json',
                        data: JSON.stringify({
                            session_id: state.currentSession,
                            scenario_name: result.value
                        }),
                        success: function(resp) {
                            if (resp.status === 'ok') {
                                self.loadScenarios();
                            }
                        }
                    });
                }
            });
        },

        cloneScenario: function(scenarioId) {
            var self = this;
            $.ajax({
                url: API.planning.scenario_clone,
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ scenario_id: scenarioId }),
                success: function(resp) {
                    if (resp.status === 'ok') {
                        self.loadScenarios();
                    }
                }
            });
        },

        deleteScenario: function(scenarioId) {
            var self = this;
            Swal.fire({
                title: t('ctp.planning.scenario'),
                text: t('ctp.throughput.confirmDelete'),
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545'
            }).then(function(result) {
                if (result.isConfirmed) {
                    $.ajax({
                        url: API.planning.scenario_delete,
                        method: 'POST',
                        contentType: 'application/json',
                        data: JSON.stringify({ scenario_id: scenarioId }),
                        success: function(resp) {
                            if (resp.status === 'ok') {
                                self.loadScenarios();
                            }
                        }
                    });
                }
            });
        },

        compute: function(scenarioId) {
            var self = this;
            Swal.fire({ title: t('ctp.planning.computing'), allowOutsideClick: false, didOpen: function() { Swal.showLoading(); } });

            $.ajax({
                url: API.planning.compute,
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ scenario_id: scenarioId }),
                success: function(resp) {
                    Swal.close();
                    if (resp.status === 'ok' && resp.data) {
                        self.renderResults(resp.data, scenarioId);
                    } else {
                        Swal.fire({ icon: 'error', title: resp.message || 'Compute failed' });
                    }
                },
                error: function() {
                    Swal.close();
                    Swal.fire({ icon: 'error', title: 'Compute request failed' });
                }
            });
        },

        renderResults: function(data, scenarioId) {
            var $results = $('#ctp_planning_results');
            $results.empty().show();

            // Constraint checks table
            if (data.constraint_checks && data.constraint_checks.length > 0) {
                var $table = $('<table class="table table-sm table-bordered"><thead><tr>' +
                    '<th>' + t('ctp.throughput.configLabel') + '</th>' +
                    '<th>' + t('ctp.planning.planned') + '</th>' +
                    '<th>' + t('ctp.throughput.maxAcph') + '</th>' +
                    '<th>' + t('ctp.planning.constraintCheck') + '</th>' +
                    '</tr></thead><tbody></tbody></table>');

                data.constraint_checks.forEach(function(check) {
                    var status = check.violated
                        ? '<span class="text-danger"><i class="fas fa-exclamation-triangle"></i> ' + t('ctp.planning.violated') + '</span>'
                        : '<span class="text-success"><i class="fas fa-check"></i> ' + t('ctp.planning.withinLimits') + '</span>';
                    $table.find('tbody').append(
                        '<tr><td>' + (check.config_label || '-') + '</td><td>' + (check.actual_acph || 0) + '</td><td>' + (check.max_acph || '-') + '</td><td>' + status + '</td></tr>'
                    );
                });
                $results.append($table);
            }

            // Track summary
            if (data.track_summaries && data.track_summaries.length > 0) {
                var $summary = $('<table class="table table-sm"><thead><tr>' +
                    '<th>' + t('ctp.nat.trackResolved') + '</th>' +
                    '<th>' + t('ctp.planning.flightCount') + '</th>' +
                    '<th>' + t('ctp.planning.arrivalProfile') + '</th>' +
                    '</tr></thead><tbody></tbody></table>');

                data.track_summaries.forEach(function(ts) {
                    $summary.find('tbody').append(
                        '<tr><td>' + ts.track_name + '</td><td>' + ts.flight_count + '</td><td>' + (ts.peak_rate_hr || '-') + '/hr peak</td></tr>'
                    );
                });
                $results.append($summary);
            }

            // Apply to session button
            var self = this;
            $results.append(
                '<button class="btn btn-sm btn-warning mt-2" id="ctp_planning_apply">' +
                '<i class="fas fa-upload"></i> ' + t('ctp.planning.applyToSession') + '</button>'
            );
            $('#ctp_planning_apply').on('click', function() {
                self.applyToSession(scenarioId);
            });
        },

        applyToSession: function(scenarioId) {
            Swal.fire({
                title: t('ctp.planning.applyToSession'),
                text: t('ctp.planning.applyConfirm'),
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#f0ad4e'
            }).then(function(result) {
                if (result.isConfirmed) {
                    $.ajax({
                        url: API.planning.apply_to_session,
                        method: 'POST',
                        contentType: 'application/json',
                        data: JSON.stringify({ scenario_id: scenarioId, session_id: state.currentSession }),
                        success: function(resp) {
                            if (resp.status === 'ok') {
                                Swal.fire({ icon: 'success', title: t('ctp.planning.appliedSuccessfully'), timer: 2000, showConfirmButton: false });
                                ThroughputManager.refresh();
                            } else {
                                Swal.fire({ icon: 'error', title: resp.message || 'Apply failed' });
                            }
                        }
                    });
                }
            });
        }
    };

    // ========================================================================
    // Init
    // ========================================================================
    $(document).ready(function() {
        SessionManager.init();
        FlightFilter.init();
        FlightTable.init();
        MapController.init();
        RouteEditor.init();
        EDCTManager.init();
        DemandChart.init();
        ComplianceMonitor.init();
        NATTracks.init();
        AuditLog.init();
        ThroughputManager.init();
        PlanningSimulator.init();
        ResizeHandle.init();
        FlightTable.updateSortUI();
    });

})();
