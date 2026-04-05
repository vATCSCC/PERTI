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
            apply_to_session:  'api/ctp/planning/apply_to_session.php',
            track_constraints: 'api/ctp/planning/track_constraints.php'
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

            $('#ctp_btn_new_session').on('click', function() {
                $('#ctpCreateSessionModal').modal('show');
            });
            $('#ctp_btn_session_settings').on('click', function() {
                SessionManager.editSession();
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
                $('#ctp_bottom_tabs').hide();
                $('#ctp_bottom_resize_handle').hide();
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

            // Bottom tabs visibility (demand, throughput, planning)
            var showBottom = s.status === 'ACTIVE' || s.status === 'MONITORING' || s.status === 'DRAFT';
            $('#ctp_bottom_tabs').toggle(showBottom);
            $('#ctp_bottom_resize_handle').toggle(showBottom);
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
        },

        editSession: function() {
            var s = state.currentSession;
            if (!s) {
                Swal.fire(t('ctp.dialog.error'), t('ctp.session.noSession'), 'warning');
                return;
            }

            var start = s.constraint_window_start || '';
            var end = s.constraint_window_end || '';
            if (start) start = start.replace(/Z$/, '').replace(/\.\d+$/, '').substring(0, 16);
            if (end) end = end.replace(/Z$/, '').replace(/\.\d+$/, '').substring(0, 16);

            var firs = '';
            if (s.constrained_firs) {
                try {
                    var firsArr = typeof s.constrained_firs === 'string' ? JSON.parse(s.constrained_firs) : s.constrained_firs;
                    firs = Array.isArray(firsArr) ? firsArr.join(', ') : String(s.constrained_firs);
                } catch(e) { firs = String(s.constrained_firs); }
            }

            var activateHtml = '';
            if (s.status === 'DRAFT') {
                activateHtml = '<div class="mt-2 pt-2 border-top text-center">' +
                    '<label style="cursor:pointer;font-size:0.9rem;"><input type="checkbox" id="swal_sess_activate"> <strong>' + t('ctp.session.activate') + '</strong> (DRAFT &rarr; ACTIVE)</label>' +
                    '</div>';
            }

            Swal.fire({
                title: t('ctp.session.settings'),
                html:
                    '<input id="swal_sess_name" class="swal2-input" value="' + (s.session_name || '').replace(/"/g, '&quot;') + '" placeholder="' + t('ctp.session.name') + '">' +
                    '<label class="swal2-input-label">' + t('ctp.session.direction') + '</label>' +
                    '<select id="swal_sess_dir" class="swal2-select">' +
                        '<option value="WESTBOUND"' + (s.direction === 'WESTBOUND' ? ' selected' : '') + '>' + t('ctp.session.westbound') + '</option>' +
                        '<option value="EASTBOUND"' + (s.direction === 'EASTBOUND' ? ' selected' : '') + '>' + t('ctp.session.eastbound') + '</option>' +
                        '<option value="BOTH"' + (s.direction === 'BOTH' ? ' selected' : '') + '>' + t('ctp.session.both') + '</option>' +
                    '</select>' +
                    '<label class="swal2-input-label">' + t('ctp.session.windowStart') + '</label>' +
                    '<input id="swal_sess_start" class="swal2-input" type="datetime-local" value="' + start + '">' +
                    '<label class="swal2-input-label">' + t('ctp.session.windowEnd') + '</label>' +
                    '<input id="swal_sess_end" class="swal2-input" type="datetime-local" value="' + end + '">' +
                    '<label class="swal2-input-label">' + t('ctp.session.constrainedFirs') + '</label>' +
                    '<input id="swal_sess_firs" class="swal2-input" value="' + firs.replace(/"/g, '&quot;') + '" placeholder="CZQX, BIRD, EGGX, LPPO">' +
                    '<label class="swal2-input-label">' + t('ctp.session.slotInterval') + '</label>' +
                    '<input id="swal_sess_interval" class="swal2-input" type="number" value="' + (s.slot_interval_min || 5) + '">' +
                    '<label class="swal2-input-label">' + t('ctp.session.maxSlotsPerHour') + '</label>' +
                    '<input id="swal_sess_max" class="swal2-input" type="number" value="' + (s.max_slots_per_hour || '') + '" placeholder="' + t('ctp.session.unlimited') + '">' +
                    activateHtml,
                focusConfirm: false,
                showCancelButton: true,
                confirmButtonText: t('common.save'),
                width: 500,
                preConfirm: function() {
                    var name = $('#swal_sess_name').val().trim();
                    if (!name) { Swal.showValidationMessage(t('ctp.session.nameRequired')); return false; }
                    var firVal = $('#swal_sess_firs').val().trim();
                    var firArray = firVal ? firVal.split(/[,\s]+/).map(function(f) { return f.trim().toUpperCase(); }).filter(Boolean) : [];
                    var payload = {
                        session_id: s.session_id,
                        session_name: name,
                        direction: $('#swal_sess_dir').val(),
                        slot_interval_min: parseInt($('#swal_sess_interval').val(), 10) || 5,
                        constrained_firs: firArray
                    };
                    var startVal = $('#swal_sess_start').val();
                    var endVal = $('#swal_sess_end').val();
                    if (startVal) payload.constraint_window_start = new Date(startVal).toISOString();
                    if (endVal) payload.constraint_window_end = new Date(endVal).toISOString();
                    var maxSlots = $('#swal_sess_max').val();
                    payload.max_slots_per_hour = maxSlots ? parseInt(maxSlots, 10) : null;
                    payload._activate = $('#swal_sess_activate').is(':checked');
                    return payload;
                }
            }).then(function(result) {
                if (!result.isConfirmed || !result.value) return;
                var payload = result.value;
                var wantActivate = payload._activate;
                delete payload._activate;

                $.ajax({
                    url: API.sessions.update,
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify(payload),
                    success: function(resp) {
                        if (resp.status !== 'ok') {
                            Swal.fire(t('ctp.dialog.error'), resp.message || t('ctp.dialog.unknownError'), 'error');
                            return;
                        }
                        if (wantActivate) {
                            $.ajax({
                                url: API.sessions.activate,
                                method: 'POST',
                                contentType: 'application/json',
                                data: JSON.stringify({ session_id: s.session_id }),
                                success: function(aResp) {
                                    if (aResp.status === 'ok') {
                                        Swal.fire({ icon: 'success', title: t('ctp.session.activated'), timer: 1500, showConfirmButton: false });
                                    } else {
                                        Swal.fire({ icon: 'warning', title: t('common.saved'), text: aResp.message || '' });
                                    }
                                    SessionManager.loadSessions();
                                    SessionManager.selectSession(s.session_id);
                                },
                                error: function() {
                                    Swal.fire({ icon: 'warning', title: t('common.saved'), text: t('ctp.dialog.networkError') });
                                    SessionManager.loadSessions();
                                    SessionManager.selectSession(s.session_id);
                                }
                            });
                        } else {
                            Swal.fire({ icon: 'success', title: t('common.saved'), timer: 1500, showConfirmButton: false });
                            SessionManager.loadSessions();
                            SessionManager.selectSession(s.session_id);
                        }
                    },
                    error: function() {
                        Swal.fire(t('ctp.dialog.error'), t('ctp.dialog.networkError'), 'error');
                    }
                });
            });
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
                var icon = state.currentSession ? 'fa-plane-slash' : 'fa-globe-americas';
                var msg = state.currentSession ? t('ctp.flights.noData') : t('ctp.flights.selectSession');
                $tbody.append(
                    '<tr class="ctp-empty-row"><td colspan="12" class="text-center text-muted py-4">' +
                    '<i class="fas ' + icon + ' fa-2x mb-2 d-block"></i>' +
                    msg +
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

                var entryUtc = f.oceanic_entry_utc ? 'E' + FlightTable.formatUtcShort(f.oceanic_entry_utc) : '--';
                var edctUtc = f.edct_utc ? 'C' + FlightTable.formatUtcShort(f.edct_utc) : '--';

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
            var exitUtc = f.oceanic_exit_utc ? 'E' + FlightTable.formatUtcFull(f.oceanic_exit_utc) : '--';
            var entryUtcFull = f.oceanic_entry_utc ? 'E' + FlightTable.formatUtcFull(f.oceanic_entry_utc) : '--';
            var edctFull = f.edct_utc ? 'C' + FlightTable.formatUtcFull(f.edct_utc) : '--';
            var origEtd = f.original_etd_utc ? 'E' + FlightTable.formatUtcFull(f.original_etd_utc) : '--';
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
                    glyphs: '/assets/fonts/{fontstack}/{range}.pbf'
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
                    session_id: state.currentSession.session_id,
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
                    session_id: state.currentSession.session_id,
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
                        FlightTable.load();
                        MapController.loadSessionData();

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
                        FlightTable.load();
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
                        FlightTable.load();
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
                        session_id: state.currentSession.session_id,
                        auto_assign: true,
                        base_time_utc: result.value.baseTime
                    }),
                    success: function(resp) {
                        if (resp.status === 'ok' && resp.data) {
                            FlightTable.load();
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
                    session_id: state.currentSession.session_id,
                    auto_assign: true,
                    base_time_utc: baseTime,
                    interval_min: interval,
                    flight_ids: flightIds
                }),
                success: function(resp) {
                    if (resp.status === 'ok' && resp.data) {
                        state.selectedIds.clear();
                        FlightTable.load();
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
                    session_id: state.currentSession.session_id,
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
        mapCollapsed: false,
        panelCollapsed: false,
        savedMapFlex: null,

        init: function() {
            var self = this;
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

            // Map collapse toggle
            $('#ctp_map_toggle').on('click', function() {
                self.toggleMap();
            });

            // Bottom panel collapse toggle
            $('#ctp_panel_toggle').on('click', function() {
                self.togglePanel();
            });

            // Bottom panel resize handle (drag top edge to resize)
            var bottomHandle = document.getElementById('ctp_bottom_resize_handle');
            var bottomTabs = document.getElementById('ctp_bottom_tabs');
            if (bottomHandle && bottomTabs) {
                var bDragging = false;
                var bStartY = 0;
                var bStartHeight = 0;

                bottomHandle.addEventListener('mousedown', function(e) {
                    e.preventDefault();
                    bDragging = true;
                    bStartY = e.clientY;
                    bStartHeight = bottomTabs.offsetHeight;
                    document.body.style.cursor = 'ns-resize';
                    document.body.style.userSelect = 'none';
                });

                document.addEventListener('mousemove', function(e) {
                    if (!bDragging) return;
                    var delta = bStartY - e.clientY; // inverted: drag up = grow
                    var newHeight = Math.max(80, Math.min(bStartHeight + delta, container.offsetHeight - 160));
                    bottomTabs.style.maxHeight = newHeight + 'px';
                    bottomTabs.style.flex = '0 0 ' + newHeight + 'px';
                });

                document.addEventListener('mouseup', function() {
                    if (bDragging) {
                        bDragging = false;
                        document.body.style.cursor = '';
                        document.body.style.userSelect = '';
                    }
                });
            }
        },

        toggleMap: function() {
            var $map = $('#ctp_map_section');
            var $icon = $('#ctp_map_toggle i');
            this.mapCollapsed = !this.mapCollapsed;

            if (this.mapCollapsed) {
                this.savedMapFlex = $map[0].style.flex || '';
                $map.addClass('ctp-map-collapsed');
                $icon.removeClass('fa-chevron-up').addClass('fa-chevron-down');
                $('#ctp_map_toggle').attr('title', t('ctp.layout.showMap') || 'Show Map').html('<i class="fas fa-chevron-down mr-1"></i>' + (t('ctp.layout.showMap') || 'Show Map'));
            } else {
                $map.removeClass('ctp-map-collapsed');
                if (this.savedMapFlex) {
                    $map[0].style.flex = this.savedMapFlex;
                } else {
                    $map[0].style.flex = '';
                }
                $icon.removeClass('fa-chevron-down').addClass('fa-chevron-up');
                $('#ctp_map_toggle').attr('title', t('ctp.layout.hideMap') || 'Hide Map').html('<i class="fas fa-chevron-up"></i>');
                setTimeout(function() { MapController.resize(); }, 50);
            }
        },

        togglePanel: function() {
            var $tabs = $('#ctp_bottom_tabs');
            var $icon = $('#ctp_panel_toggle i');
            this.panelCollapsed = !this.panelCollapsed;

            if (this.panelCollapsed) {
                $tabs.addClass('ctp-panel-collapsed');
                $icon.removeClass('fa-chevron-down').addClass('fa-chevron-up');
            } else {
                $tabs.removeClass('ctp-panel-collapsed');
                $icon.removeClass('fa-chevron-up').addClass('fa-chevron-down');
            }
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
                data: { session_id: state.currentSession.session_id },
                success: function(resp) {
                    if (resp.status === 'ok' && resp.data) {
                        self.configs = resp.data || [];
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
                var tracks = cfg.tracks_json ? [].concat(cfg.tracks_json).join(', ') : t('ctp.throughput.wildcard');
                var origins = cfg.origins_json ? [].concat(cfg.origins_json).join(', ') : t('ctp.throughput.wildcard');
                var dests = cfg.destinations_json ? [].concat(cfg.destinations_json).join(', ') : t('ctp.throughput.wildcard');

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
                    result.value.session_id = state.currentSession.session_id;
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
                                Swal.fire({ icon: 'error', title: resp.message || t('common.error') });
                            }
                        }
                    });
                }
            });
        },

        showEditDialog: function(cfg) {
            var self = this;
            var tracks = cfg.tracks_json ? [].concat(cfg.tracks_json).join(',') : '';
            var origins = cfg.origins_json ? [].concat(cfg.origins_json).join(',') : '';
            var dests = cfg.destinations_json ? [].concat(cfg.destinations_json).join(',') : '';

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
                            } else {
                                Swal.fire({ icon: 'error', title: resp.message || t('common.error') });
                            }
                        },
                        error: function(xhr) {
                            if (xhr.status === 409) {
                                Swal.fire({ icon: 'warning', title: t('ctp.throughput.conflictDetected') || 'Conflict detected — config was modified. Refreshing.' });
                                self.refresh();
                            } else {
                                var msg = xhr.responseJSON ? xhr.responseJSON.message : t('common.error');
                                Swal.fire({ icon: 'error', title: msg });
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
                data: { session_id: state.currentSession.session_id, config_id: configId },
                success: function(resp) {
                    if (resp.status === 'ok' && resp.data) {
                        var d = resp.data;
                        var html = '<div class="mb-2 small text-muted">' + t('ctp.throughput.totalFlights') + ': ' + (d.total_flights || 0) +
                            ' &middot; ' + t('ctp.throughput.binsExceeding') + ': ' + (d.bins_exceeding || 0) + '</div>' +
                            '<table class="table table-sm"><thead><tr>' +
                            '<th>' + t('ctp.demand.timeUtc') + '</th>' +
                            '<th>' + t('ctp.throughput.flights') + '</th>' +
                            '<th>' + t('ctp.throughput.maxAcph') + '</th>' +
                            '<th>' + t('ctp.throughput.status') + '</th>' +
                            '</tr></thead><tbody>';
                        (d.bins || []).forEach(function(bin) {
                            var cls = bin.exceeds ? 'text-danger font-weight-bold' : 'text-success';
                            var label = (bin.bin_start || '').substring(11, 16);
                            html += '<tr><td>' + label + '</td><td>' + bin.flight_count + '</td><td>' + bin.max_acph + '</td>' +
                                '<td class="' + cls + '">' + (bin.exceeds ? '+' + bin.overage : 'OK') + '</td></tr>';
                        });
                        html += '</tbody></table>';
                        Swal.fire({ title: d.config_label || t('ctp.throughput.previewImpact'), html: html, width: 600 });
                    }
                }
            });
        }
    };

    // ========================================================================
    // Compute Export Utilities
    // ========================================================================

    var ComputeExport = {
        _lastData: null,

        storeData: function(data) {
            this._lastData = data;
        },

        // ----------------------------------------------------------------
        // Low-level download helpers
        // ----------------------------------------------------------------

        _download: function(blob, filename) {
            var link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = filename;
            link.style.display = 'none';
            document.body.appendChild(link);
            link.click();
            setTimeout(function() {
                document.body.removeChild(link);
                URL.revokeObjectURL(link.href);
            }, 150);
        },

        _copyToClipboard: function(text, label) {
            var notify = function() {
                Swal.fire({ icon: 'success', title: (label || 'Data') + ' copied', toast: true, position: 'top-end', timer: 1500, showConfirmButton: false });
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(notify);
            } else {
                var ta = document.createElement('textarea');
                ta.value = text;
                ta.style.cssText = 'position:fixed;opacity:0;left:-9999px';
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
                notify();
            }
        },

        _csvEscape: function(cell) {
            var s = String(cell == null ? '' : cell);
            if (s.indexOf(',') >= 0 || s.indexOf('"') >= 0 || s.indexOf('\n') >= 0) {
                return '"' + s.replace(/"/g, '""') + '"';
            }
            return s;
        },

        _toCSV: function(headers, rows) {
            var self = this;
            var csv = headers.map(self._csvEscape).join(',') + '\n';
            rows.forEach(function(row) {
                csv += row.map(self._csvEscape).join(',') + '\n';
            });
            return csv;
        },

        _toTXT: function(headers, rows) {
            // Fixed-width aligned plain text
            var allRows = [headers].concat(rows);
            var widths = headers.map(function(h, i) {
                var max = String(h).length;
                rows.forEach(function(r) { max = Math.max(max, String(r[i] == null ? '' : r[i]).length); });
                return Math.min(max + 1, 40); // cap at 40
            });
            var line = function(row) {
                return row.map(function(c, i) {
                    var s = String(c == null ? '' : c);
                    return s.length > widths[i] ? s.substring(0, widths[i]) : s + new Array(widths[i] - s.length + 1).join(' ');
                }).join(' | ');
            };
            var txt = line(headers) + '\n';
            txt += widths.map(function(w) { return new Array(w + 1).join('-'); }).join('-+-') + '\n';
            rows.forEach(function(r) { txt += line(r) + '\n'; });
            return txt;
        },

        _ensureSheetJS: function(callback) {
            if (typeof XLSX !== 'undefined') { callback(); return; }
            var script = document.createElement('script');
            script.src = 'https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.mini.min.js';
            script.onload = callback;
            script.onerror = function() { Swal.fire({ icon: 'error', title: t('ctp.error.xlsxLoadFailed') }); };
            document.head.appendChild(script);
        },

        _downloadXLSX: function(filename, sheetMap) {
            // sheetMap: { SheetName: { headers: [], rows: [] }, ... }
            this._ensureSheetJS(function() {
                var wb = XLSX.utils.book_new();
                Object.keys(sheetMap).forEach(function(name) {
                    var s = sheetMap[name];
                    var aoa = [s.headers].concat(s.rows);
                    var ws = XLSX.utils.aoa_to_sheet(aoa);
                    XLSX.utils.book_append_sheet(wb, ws, name.substring(0, 31)); // sheet name max 31 chars
                });
                XLSX.writeFile(wb, filename);
            });
        },

        // ----------------------------------------------------------------
        // Dataset builders — return { sheets: { name: { headers, rows } } }
        // ----------------------------------------------------------------

        _buildFlightList: function() {
            var d = this._lastData;
            if (!d || !d.flight_list) return null;
            var headers = ['#', 'Origin', 'Dest', 'Track', 'Block', 'Route', 'DEP_UTC', 'Entry_UTC', 'Exit_UTC', 'ARR_UTC', 'Pre_Ocean_min', 'Ocean_min', 'Post_Ocean_min', 'Total_min'];
            var rows = d.flight_list.map(function(f, i) {
                return [i + 1, f.origin, f.dest, f.track, f.block_label, f.route, f.dep_utc, f.entry_utc, f.exit_utc, f.arr_utc, f.pre_oceanic_min, f.oceanic_min, f.post_oceanic_min, f.total_min];
            });
            return { Flights: { headers: headers, rows: rows } };
        },

        _buildDistributions: function() {
            var d = this._lastData;
            if (!d || !d.distributions) return null;
            var dist = d.distributions;
            var sheets = {};
            if (dist.origins) sheets.Origins = { headers: ['Origin', 'Count', 'Pct'], rows: dist.origins.map(function(r) { return [r.origin, r.count, r.pct]; }) };
            if (dist.destinations) sheets.Destinations = { headers: ['Dest', 'Count', 'Pct'], rows: dist.destinations.map(function(r) { return [r.dest, r.count, r.pct]; }) };
            if (dist.od_pairs) sheets['OD Pairs'] = { headers: ['Origin', 'Dest', 'Count', 'Pct'], rows: dist.od_pairs.map(function(r) { return [r.origin, r.dest, r.count, r.pct]; }) };
            if (dist.origin_track) sheets['Origin-Track'] = { headers: ['Origin', 'Track', 'Count'], rows: dist.origin_track.map(function(r) { return [r.origin, r.track, r.count]; }) };
            if (dist.track_dest) sheets['Track-Dest'] = { headers: ['Track', 'Dest', 'Count'], rows: dist.track_dest.map(function(r) { return [r.track, r.dest, r.count]; }) };
            return sheets;
        },

        _buildTimeBins: function() {
            var d = this._lastData;
            if (!d) return null;
            var headers = ['Profile', 'Resolution', 'Start_UTC', 'End_UTC', 'Count'];
            var rows = [];
            var addBins = function(name, res, bins) {
                if (!bins) return;
                bins.forEach(function(b) { rows.push([name, res, b.start_utc, b.end_utc || '', b.count]); });
            };
            if (d.departure_profile) addBins('Departure', '15min', d.departure_profile.bins);
            if (d.oceanic_entry_profile) addBins('Oceanic_Entry', '15min', d.oceanic_entry_profile.bins);
            if (d.oceanic_exit_profile) addBins('Oceanic_Exit', '15min', d.oceanic_exit_profile.bins);
            if (d.arrival_profile) addBins('Arrival', '15min', d.arrival_profile.bins);
            if (d.multi_resolution) {
                addBins('Departure', '30min', d.multi_resolution.departure_30min);
                addBins('Departure', '60min', d.multi_resolution.departure_60min);
                addBins('Oceanic_Entry', '30min', d.multi_resolution.oceanic_entry_30min);
                addBins('Oceanic_Entry', '60min', d.multi_resolution.oceanic_entry_60min);
                addBins('Oceanic_Exit', '30min', d.multi_resolution.oceanic_exit_30min);
                addBins('Oceanic_Exit', '60min', d.multi_resolution.oceanic_exit_60min);
                addBins('Arrival', '30min', d.multi_resolution.arrival_30min);
                addBins('Arrival', '60min', d.multi_resolution.arrival_60min);
            }
            return { 'Time Bins': { headers: headers, rows: rows } };
        },

        _buildConstraints: function() {
            var d = this._lastData;
            if (!d || !d.constraint_checks || d.constraint_checks.length === 0) return null;
            var headers = ['Config', 'Peak_Actual', 'Max_ACPH', 'Violated', 'Bins_Over', 'Type'];
            var rows = d.constraint_checks.map(function(c) {
                return [c.config_label, c.peak_actual || '', c.max_acph || '', c.violated ? 'YES' : 'NO', c.bins_over || 0, c.violation_type || 'ACPH'];
            });
            return { Constraints: { headers: headers, rows: rows } };
        },

        _buildSummary: function() {
            var d = this._lastData;
            if (!d) return null;
            var sheets = {};
            // Track summary
            if (d.track_summary) {
                sheets['Track Summary'] = {
                    headers: ['Track', 'Flights', 'Avg_Transit_min', 'Peak_Rate_hr'],
                    rows: d.track_summary.map(function(ts) { return [ts.track, ts.flight_count, ts.avg_transit_min, ts.peak_rate_hr]; })
                };
            }
            // Volume profiles
            if (d.volume_profiles) {
                sheets['Volume Profiles'] = {
                    headers: ['Track', 'Flights', 'Pre_Ocean_avg', 'Ocean_avg', 'Post_Ocean_avg', 'Total_avg'],
                    rows: d.volume_profiles.map(function(v) { return [v.track, v.flight_count, v.avg_pre_oceanic_min, v.avg_oceanic_min, v.avg_post_oceanic_min, v.avg_total_min]; })
                };
            }
            return sheets;
        },

        // ----------------------------------------------------------------
        // Format dispatchers
        // ----------------------------------------------------------------

        exportAs: function(dataset, format) {
            var sheets;
            switch (dataset) {
                case 'flights': sheets = this._buildFlightList(); break;
                case 'distributions': sheets = this._buildDistributions(); break;
                case 'bins': sheets = this._buildTimeBins(); break;
                case 'constraints': sheets = this._buildConstraints(); break;
                case 'summary': sheets = this._buildSummary(); break;
                case 'all': sheets = this._buildAll(); break;
                default: return;
            }
            if (!sheets) return;

            var basename = 'ctp_' + dataset;
            switch (format) {
                case 'csv':
                    var csv = '';
                    var names = Object.keys(sheets);
                    names.forEach(function(name, idx) {
                        var s = sheets[name];
                        if (names.length > 1) csv += '--- ' + name + ' ---\n';
                        csv += this._toCSV(s.headers, s.rows);
                        if (idx < names.length - 1) csv += '\n';
                    }.bind(this));
                    this._download(new Blob([csv], { type: 'text/csv;charset=utf-8;' }), basename + '.csv');
                    break;
                case 'txt':
                    var txt = '';
                    var names2 = Object.keys(sheets);
                    names2.forEach(function(name, idx) {
                        var s = sheets[name];
                        if (names2.length > 1) txt += '=== ' + name.toUpperCase() + ' ===\n\n';
                        txt += this._toTXT(s.headers, s.rows);
                        if (idx < names2.length - 1) txt += '\n\n';
                    }.bind(this));
                    this._download(new Blob([txt], { type: 'text/plain;charset=utf-8;' }), basename + '.txt');
                    break;
                case 'xlsx':
                    this._downloadXLSX(basename + '.xlsx', sheets);
                    break;
                case 'json':
                    var jsonData = {};
                    Object.keys(sheets).forEach(function(name) {
                        var s = sheets[name];
                        jsonData[name] = s.rows.map(function(row) {
                            var obj = {};
                            s.headers.forEach(function(h, i) { obj[h] = row[i]; });
                            return obj;
                        });
                    });
                    this._download(new Blob([JSON.stringify(jsonData, null, 2)], { type: 'application/json' }), basename + '.json');
                    break;
                case 'clipboard':
                    var tsv = '';
                    var namesC = Object.keys(sheets);
                    namesC.forEach(function(name, idx) {
                        var s = sheets[name];
                        if (namesC.length > 1) tsv += '--- ' + name + ' ---\n';
                        tsv += s.headers.join('\t') + '\n';
                        s.rows.forEach(function(row) {
                            tsv += row.map(function(c) { return c == null ? '' : String(c); }).join('\t') + '\n';
                        });
                        if (idx < namesC.length - 1) tsv += '\n';
                    });
                    this._copyToClipboard(tsv, dataset);
                    break;
            }
        },

        _buildAll: function() {
            var all = {};
            var merge = function(s) { if (s) { Object.keys(s).forEach(function(k) { all[k] = s[k]; }); } };
            merge(this._buildFlightList());
            merge(this._buildDistributions());
            merge(this._buildTimeBins());
            merge(this._buildConstraints());
            merge(this._buildSummary());
            return all;
        },

        exportFullJSON: function() {
            if (!this._lastData) return;
            this._download(
                new Blob([JSON.stringify(this._lastData, null, 2)], { type: 'application/json' }),
                'ctp_compute_results.json'
            );
        }
    };

    // ========================================================================
    // Simulated Demand Charts (ECharts stacked bar from compute bins)
    // ========================================================================

    var SimDemandCharts = {
        _charts: [],
        _palette: [
            '#5470c6', '#91cc75', '#fac858', '#ee6666', '#73c0de',
            '#3ba272', '#fc8452', '#9a60b4', '#ea7ccc', '#4dc9f6',
            '#f7797d', '#c4b5fd', '#34d399', '#f59e0b', '#6366f1'
        ],
        _data: null,
        _resolution: '15min',
        _activeView: 'dep_track',
        _trackFilter: null, // null = All, string = specific track/origin/dest

        dispose: function() {
            this._charts.forEach(function(c) { if (c) c.dispose(); });
            this._charts = [];
        },

        render: function($container, data) {
            this.dispose();
            if (typeof echarts === 'undefined') return;

            var self = this;
            self._data = data;
            self._resolution = '15min';
            self._activeView = 'dep_track';
            self._trackFilter = null;
            var uid = 'sim_chart_' + Date.now();
            self._uid = uid;

            // View tabs (two rows for space)
            var viewTabs = [
                { key: 'dep_track', label: t('ctp.planning.depByTrack') },
                { key: 'dep_origin', label: t('ctp.planning.depByOrigin') },
                { key: 'entry_track', label: t('ctp.planning.entryByTrack') },
                { key: 'entry_origin', label: t('ctp.planning.entryByOrigin') },
                { key: 'entry_dest', label: t('ctp.planning.entryByDest') },
                { key: 'exit_track', label: t('ctp.planning.exitByTrack') },
                { key: 'exit_origin', label: t('ctp.planning.exitByOrigin') },
                { key: 'exit_dest', label: t('ctp.planning.exitByDest') },
                { key: 'arr_track', label: t('ctp.planning.arrByTrack') },
                { key: 'arr_dest', label: t('ctp.planning.arrByDest') }
            ];
            var tabsHtml = '';
            viewTabs.forEach(function(vt) {
                var cls = vt.key === 'dep_track' ? 'active' : '';
                tabsHtml += '<button class="btn btn-outline-info btn-sm ' + cls + '" data-chart="' + vt.key + '">' + vt.label + '</button>';
            });

            // Resolution toggle
            var resHtml =
                '<div class="btn-group btn-group-sm ml-2" id="' + uid + '_res">' +
                '<button class="btn btn-outline-secondary btn-sm active" data-res="15min">15m</button>' +
                '<button class="btn btn-outline-secondary btn-sm" data-res="30min">30m</button>' +
                '<button class="btn btn-outline-secondary btn-sm" data-res="60min">60m</button>' +
                '</div>';

            $container.append(
                '<div class="mt-2 mb-1">' +
                '<div class="d-flex align-items-start flex-wrap mb-1">' +
                '<div class="btn-group btn-group-sm flex-wrap" id="' + uid + '_tabs">' + tabsHtml + '</div>' +
                resHtml +
                '<select class="form-control form-control-sm ml-2" id="' + uid + '_filter" style="width:auto;max-width:140px;display:inline-block;background:#2a2a4a;color:#ccc;border-color:#555;font-size:11px;">' +
                '<option value="">All</option></select>' +
                '</div>' +
                '<div id="' + uid + '_chart" style="width:100%;height:280px;"></div>' +
                '</div>'
            );

            var chartEl = document.getElementById(uid + '_chart');
            if (!chartEl) return;

            var chart = echarts.init(chartEl);
            self._charts.push(chart);
            self._chart = chart;

            // Initial render
            self._updateChart();

            // Populate filter dropdown for initial view
            self._populateFilter();

            // View tab switching
            $('#' + uid + '_tabs').on('click', 'button', function() {
                self._activeView = $(this).data('chart');
                $(this).addClass('active').siblings().removeClass('active');
                self._trackFilter = null;
                self._populateFilter();
                self._updateChart();
            });

            // Resolution switching
            $('#' + uid + '_res').on('click', 'button', function() {
                self._resolution = $(this).data('res');
                $(this).addClass('active').siblings().removeClass('active');
                self._updateChart();
            });

            // Filter switching
            $('#' + uid + '_filter').on('change', function() {
                self._trackFilter = $(this).val() || null;
                self._updateChart();
            });

            // Resize on window resize
            window.addEventListener('resize', function() { chart.resize(); });
            setTimeout(function() { chart.resize(); }, 200);
        },

        _getBins: function(view, resolution) {
            var d = this._data;
            if (!d) return [];
            var mr = d.multi_resolution || {};
            // Map view prefix to profile key and multi-resolution key prefix
            var profileMap = {
                dep: { profile: 'departure_profile', mr_prefix: 'departure' },
                entry: { profile: 'oceanic_entry_profile', mr_prefix: 'oceanic_entry' },
                exit: { profile: 'oceanic_exit_profile', mr_prefix: 'oceanic_exit' },
                arr: { profile: 'arrival_profile', mr_prefix: 'arrival' }
            };
            var parts = view.split('_');
            var prefix = parts[0];
            var pm = profileMap[prefix];
            if (!pm) return [];

            if (resolution === '15min') {
                return (d[pm.profile] && d[pm.profile].bins) ? d[pm.profile].bins : [];
            }
            var mrKey = pm.mr_prefix + '_' + resolution;
            return mr[mrKey] || [];
        },

        _getConstraintLines: function(view) {
            var d = this._data;
            if (!d) return [];
            var lines = [];
            // Only show ACPH constraints on entry_* views when a specific track is filtered
            if (view.indexOf('entry_') !== 0) return lines;
            var filter = this._trackFilter;
            if (!filter) return lines; // No constraints on stacked "All" view

            // 1. Track constraint (planner-defined cap) — solid red line
            var tcValue = null;
            if (d.track_constraints) {
                d.track_constraints.forEach(function(tc) {
                    if (!tc.max_acph || tc.track_name !== filter) return;
                    tcValue = tc.max_acph;
                    lines.push({
                        label: filter + ' Constraint',
                        value: tc.max_acph,
                        color: '#ff4444',
                        lineType: 'solid'
                    });
                });
            }

            // 2. Aggregate throughput config — dashed orange, deduped, skip per-block
            if (d.throughput_configs) {
                var seen = {};
                d.throughput_configs.forEach(function(cfg) {
                    if (!cfg.max_acph) return;
                    // Only aggregate configs (skip per-block origin-specific configs)
                    if (cfg.config_label.indexOf('Aggregate') < 0) return;
                    var tracks = cfg.tracks_json || [];
                    if (typeof tracks === 'string') { try { tracks = JSON.parse(tracks); } catch(e) { tracks = []; } }
                    if (tracks.length > 0 && tracks.indexOf(filter) < 0) return;
                    // Deduplicate by ACPH value
                    if (seen[cfg.max_acph]) return;
                    seen[cfg.max_acph] = true;
                    // Skip if same value as track constraint (avoid duplicate line)
                    if (cfg.max_acph === tcValue) return;
                    lines.push({
                        label: cfg.config_label,
                        value: cfg.max_acph,
                        color: '#ff8800',
                        lineType: 'dashed'
                    });
                });
            }
            return lines;
        },

        _getEntryWindows: function(view) {
            var d = this._data;
            if (!d || !d.track_constraints) return [];
            // Show ocean entry windows on entry_* views
            if (view.indexOf('entry_') !== 0) return [];
            var self = this;
            var filter = self._trackFilter;
            var windows = [];
            d.track_constraints.forEach(function(tc) {
                if (tc.ocean_entry_start && tc.ocean_entry_end) {
                    // If filtering to a specific track, only show that track's window
                    if (filter && tc.track_name !== filter) return;
                    windows.push({
                        label: tc.track_name,
                        start: self._parseUtc(tc.ocean_entry_start),
                        end: self._parseUtc(tc.ocean_entry_end)
                    });
                }
            });
            return windows;
        },

        _updateChart: function() {
            var self = this;
            if (!self._chart || !self._data) return;
            var bins = self._getBins(self._activeView, self._resolution);
            var parts = self._activeView.split('_');
            var breakdownKey = 'by_' + parts.slice(1).join('_');

            // Build title
            var viewLabels = {
                dep_track: t('ctp.planning.departures') + ' (' + t('ctp.planning.byTrack') + ')',
                dep_origin: t('ctp.planning.departures') + ' (' + t('ctp.planning.byOrigin') + ')',
                entry_track: t('ctp.planning.oceanEntry') + ' (' + t('ctp.planning.byTrack') + ')',
                entry_origin: t('ctp.planning.oceanEntry') + ' (' + t('ctp.planning.byOrigin') + ')',
                entry_dest: t('ctp.planning.oceanEntry') + ' (' + t('ctp.planning.byDest') + ')',
                exit_track: t('ctp.planning.oceanExit') + ' (' + t('ctp.planning.byTrack') + ')',
                exit_origin: t('ctp.planning.oceanExit') + ' (' + t('ctp.planning.byOrigin') + ')',
                exit_dest: t('ctp.planning.oceanExit') + ' (' + t('ctp.planning.byDest') + ')',
                arr_track: t('ctp.planning.arrivals') + ' (' + t('ctp.planning.byTrack') + ')',
                arr_dest: t('ctp.planning.arrivals') + ' (' + t('ctp.planning.byDest') + ')'
            };
            var title = (viewLabels[self._activeView] || '') + (self._trackFilter ? ' — ' + self._trackFilter : '') + ' [' + self._resolution + ']';
            var constraints = self._getConstraintLines(self._activeView);
            var entryWindows = self._getEntryWindows(self._activeView);
            var config = self._buildConfig(bins, breakdownKey, title, constraints, entryWindows);
            self._chart.setOption(config, true);
        },

        _buildConfig: function(bins, breakdownKey, title, constraints, entryWindows) {
            var self = this;
            constraints = constraints || [];
            entryWindows = entryWindows || [];

            // Convert bin counts to hourly rates so constraint ACPH lines align directly
            var binMinutes = self._resolution === '60min' ? 60 : (self._resolution === '30min' ? 30 : 15);
            var rateMultiplier = 60 / binMinutes; // e.g. 15min bins × 4 = hourly rate

            // Collect all category keys across bins
            var keys = {};
            bins.forEach(function(bin) {
                var bd = bin[breakdownKey] || {};
                Object.keys(bd).forEach(function(k) { keys[k] = true; });
            });
            var sortedKeys = Object.keys(keys).sort();
            var filter = self._trackFilter;

            // Build series — values are hourly rates
            // When filter is set, only show that one series (unstacked, as bar)
            var displayKeys = filter ? sortedKeys.filter(function(k) { return k === filter; }) : sortedKeys;
            var series = displayKeys.map(function(key, idx) {
                // Use the same color index as in the full set so colors stay consistent
                var fullIdx = sortedKeys.indexOf(key);
                var seriesData = bins.map(function(bin) {
                    var ts = self._parseUtc(bin.start_utc);
                    var count = (bin[breakdownKey] || {})[key] || 0;
                    return [ts, count * rateMultiplier];
                });
                return {
                    name: key,
                    type: 'bar',
                    stack: filter ? undefined : 'demand',
                    data: seriesData,
                    itemStyle: { color: self._palette[fullIdx % self._palette.length] },
                    barMaxWidth: 28,
                    emphasis: { focus: 'series' }
                };
            });

            // Add total line only when showing all (stacked)
            if (!filter) {
                var totalData = bins.map(function(bin) {
                    return [self._parseUtc(bin.start_utc), (bin.count || 0) * rateMultiplier];
                });
                series.push({
                    name: 'Total',
                    type: 'line',
                    data: totalData,
                    lineStyle: { color: '#fff', width: 1.5, type: 'dashed' },
                    itemStyle: { color: '#fff' },
                    symbol: 'circle',
                    symbolSize: 4,
                    z: 10
                });
            }

            // Add constraint markLines at actual ACPH values (Y-axis is now in AC/hr)
            var markLineData = [];
            constraints.forEach(function(c) {
                if (c.value) {
                    markLineData.push({
                        yAxis: c.value,
                        lineStyle: { color: c.color, width: 2, type: c.lineType || 'dashed' },
                        label: { show: false }
                    });
                }
            });
            if (markLineData.length > 0 && series.length > 0) {
                series[series.length - 1].markLine = {
                    silent: true,
                    symbol: ['none', 'none'],
                    data: markLineData
                };
            }

            // Add entry window markAreas
            var markAreaData = [];
            entryWindows.forEach(function(w) {
                markAreaData.push([
                    { xAxis: w.start, itemStyle: { color: 'rgba(50,205,50,0.08)' } },
                    { xAxis: w.end }
                ]);
            });
            if (markAreaData.length > 0 && series.length > 0) {
                series[series.length - 1].markArea = {
                    silent: true,
                    data: markAreaData
                };
            }

            // Build constraint legend text for title area
            var constraintInfo = '';
            if (constraints.length > 0) {
                var parts = constraints.map(function(c) { return c.label + ': ' + c.value + ' AC/hr'; });
                constraintInfo = '  |  ' + parts.join('  |  ');
            }

            // Compute time bounds from bin data
            var intervalMs = binMinutes * 60 * 1000;
            var xMin = bins.length > 0 ? self._parseUtc(bins[0].start_utc) : null;
            var xMax = bins.length > 0 ? self._parseUtc(bins[bins.length - 1].start_utc) + intervalMs : null;

            var config = {
                title: {
                    text: title,
                    subtext: constraintInfo || undefined,
                    left: 'center',
                    top: 0,
                    textStyle: { fontSize: 12, color: '#ccc', fontFamily: '"Inconsolata", "SF Mono", monospace' },
                    subtextStyle: { fontSize: 10, color: '#aaa' }
                },
                tooltip: {
                    trigger: 'axis',
                    axisPointer: { type: 'shadow' },
                    backgroundColor: 'rgba(30, 30, 50, 0.95)',
                    borderColor: 'rgba(255,255,255,0.15)',
                    textStyle: { fontSize: 11, color: '#eee' },
                    formatter: function(params) {
                        if (!params || params.length === 0) return '';
                        var ts = params[0].value[0];
                        var d1 = new Date(ts);
                        var d2 = new Date(ts + intervalMs);
                        var fmt = function(dt) {
                            return ('0' + dt.getUTCHours()).slice(-2) + ('0' + dt.getUTCMinutes()).slice(-2);
                        };
                        var tip = '<b>' + fmt(d1) + ' - ' + fmt(d2) + 'Z</b><hr style="margin:3px 0;border-color:rgba(255,255,255,0.15)">';
                        var total = 0;
                        params.forEach(function(p) {
                            if (p.seriesName !== 'Total' && p.value[1] > 0) {
                                tip += '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' + p.color + ';margin-right:4px;"></span>' +
                                    p.seriesName + ': <b>' + p.value[1] + ' AC/hr</b><br>';
                                total += p.value[1];
                            }
                        });
                        if (params.length > 1) {
                            tip += '<hr style="margin:3px 0;border-color:rgba(255,255,255,0.15)">Total: <b>' + total + ' AC/hr</b>';
                        }
                        // Show constraint info in tooltip
                        constraints.forEach(function(c) {
                            var status = total > c.value ? ' <span style="color:#ff4444">OVER</span>' : ' <span style="color:#50fa7b">OK</span>';
                            tip += '<br><span style="color:' + c.color + '">' + c.label + ': ' + c.value + ' AC/hr</span>' + status;
                        });
                        return tip;
                    }
                },
                legend: { top: constraintInfo ? 30 : 18, textStyle: { fontSize: 10, color: '#aaa' }, itemWidth: 12, type: 'scroll' },
                grid: { left: 50, right: 20, top: constraintInfo ? 65 : 55, bottom: 35 },
                xAxis: {
                    type: 'time',
                    maxInterval: 3600 * 1000,
                    axisTick: { alignWithLabel: true, lineStyle: { color: '#555' } },
                    axisLine: { lineStyle: { color: '#555', width: 1 } },
                    axisLabel: {
                        fontSize: 10,
                        color: '#aaa',
                        fontFamily: '"Inconsolata", monospace',
                        formatter: function(v) {
                            var d = new Date(v);
                            return ('0' + d.getUTCHours()).slice(-2) + ('0' + d.getUTCMinutes()).slice(-2) + 'Z';
                        }
                    },
                    splitLine: { show: true, lineStyle: { color: 'rgba(255,255,255,0.06)', type: 'solid' } }
                },
                yAxis: {
                    type: 'value',
                    name: 'AC/hr',
                    nameTextStyle: { fontSize: 10, color: '#888' },
                    minInterval: 1,
                    axisLine: { show: true, lineStyle: { color: '#555', width: 1 } },
                    axisTick: { show: true, lineStyle: { color: '#555' } },
                    axisLabel: { fontSize: 10, color: '#aaa', fontFamily: '"Inconsolata", monospace' },
                    splitLine: { show: true, lineStyle: { color: 'rgba(255,255,255,0.08)', type: 'dashed' } }
                },
                backgroundColor: 'rgba(26, 26, 46, 0.85)',
                series: series
            };

            // Set explicit time bounds if we have bin data
            if (xMin !== null) {
                config.xAxis.min = xMin;
                config.xAxis.max = xMax;
            }

            return config;
        },

        _parseUtc: function(utcStr) {
            if (!utcStr) return 0;
            var s = String(utcStr).replace('Z', '').replace('T', ' ');
            var p = s.split(/[- :]/);
            return Date.UTC(+p[0], +p[1] - 1, +p[2], +p[3] || 0, +p[4] || 0, +p[5] || 0);
        },

        _populateFilter: function() {
            var self = this;
            var $sel = $('#' + self._uid + '_filter');
            if (!$sel.length) return;
            $sel.empty().append('<option value="">All</option>');

            // Collect unique keys from the current view's breakdown
            var bins = self._getBins(self._activeView, '15min');
            var parts = self._activeView.split('_');
            var breakdownKey = 'by_' + parts.slice(1).join('_');
            var keys = {};
            bins.forEach(function(bin) {
                var bd = bin[breakdownKey] || {};
                Object.keys(bd).forEach(function(k) { keys[k] = true; });
            });
            Object.keys(keys).sort().forEach(function(k) {
                $sel.append('<option value="' + k + '">' + k + '</option>');
            });
            $sel.val('');
        }
    };

    // ========================================================================
    // Planning Simulator
    // ========================================================================
    var PlanningSimulator = {
        scenarios: [],
        currentScenario: null,
        blockCache: {},

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
                data: { session_id: state.currentSession.session_id },
                success: function(resp) {
                    if (resp.status === 'ok' && resp.data) {
                        self.scenarios = resp.data || [];
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
                var statusBadge = sc.status ? '<span class="badge badge-' + (sc.status === 'ACTIVE' ? 'success' : sc.status === 'ARCHIVED' ? 'secondary' : 'light') + ' ml-1" style="font-size:0.6rem;">' + sc.status + '</span>' : '';
                var $item = $('<div class="list-group-item list-group-item-action">')
                    .append($('<div class="d-flex justify-content-between align-items-center">')
                        .append($('<span class="ps-expand" data-id="' + sc.scenario_id + '" style="cursor:pointer;">').html('<i class="fas fa-chevron-right mr-1 ps-chevron" style="font-size:0.6rem;transition:transform 0.15s;"></i>' + (sc.scenario_name || t('ctp.planning.scenario') + ' #' + sc.scenario_id) + statusBadge))
                        .append($('<span class="btn-group btn-group-sm">')
                            .append('<button class="btn btn-outline-info ps-edit" data-id="' + sc.scenario_id + '" title="' + t('common.edit') + '"><i class="fas fa-pencil-alt"></i> ' + t('common.edit') + '</button>')
                            .append('<button class="btn btn-outline-primary ps-compute" data-id="' + sc.scenario_id + '" title="' + t('ctp.planning.compute') + '"><i class="fas fa-calculator"></i> ' + t('ctp.planning.compute') + '</button>')
                        )
                        .append($('<span class="btn-group btn-group-sm ml-2">')
                            .append('<button class="btn btn-outline-secondary ps-clone" data-id="' + sc.scenario_id + '" title="' + t('ctp.planning.cloneScenario') + '"><i class="fas fa-copy"></i> ' + t('ctp.planning.cloneScenario') + '</button>')
                            .append('<button class="btn btn-outline-danger ps-delete" data-id="' + sc.scenario_id + '"><i class="fas fa-trash"></i></button>')
                        )
                    )
                    .append($('<div class="ps-blocks-panel mt-2" data-scenario="' + sc.scenario_id + '" style="display:none;"></div>'));
                $list.append($item);
            });

            $list.find('.ps-expand').on('click', function(e) {
                e.stopPropagation();
                var id = $(this).data('id');
                var $panel = $list.find('.ps-blocks-panel[data-scenario="' + id + '"]');
                var $chev = $(this).find('.ps-chevron');
                if ($panel.is(':visible')) {
                    $panel.slideUp(150);
                    $chev.css('transform', 'rotate(0deg)');
                } else {
                    $panel.slideDown(150);
                    $chev.css('transform', 'rotate(90deg)');
                    self.loadBlocks(id, $panel);
                }
            });
            $list.find('.ps-edit').on('click', function(e) { e.stopPropagation(); self.editScenario($(this).data('id')); });
            $list.find('.ps-compute').on('click', function(e) { e.stopPropagation(); self.compute($(this).data('id')); });
            $list.find('.ps-clone').on('click', function(e) { e.stopPropagation(); self.cloneScenario($(this).data('id')); });
            $list.find('.ps-delete').on('click', function(e) { e.stopPropagation(); self.deleteScenario($(this).data('id')); });
        },

        createScenario: function() {
            var self = this;
            var sess = state.currentSession;
            var defStart = sess.window_start_utc || '';
            var defEnd = sess.window_end_utc || '';
            // Convert ISO to datetime-local format
            if (defStart) defStart = defStart.replace(/Z$/, '').replace(/\.\d+$/, '').substring(0, 16);
            if (defEnd) defEnd = defEnd.replace(/Z$/, '').replace(/\.\d+$/, '').substring(0, 16);

            Swal.fire({
                title: t('ctp.planning.createScenario'),
                html:
                    '<input id="swal_ps_name" class="swal2-input" placeholder="' + t('ctp.planning.scenarioName') + '">' +
                    '<label class="swal2-input-label">' + t('ctp.session.windowStart') + '</label>' +
                    '<input id="swal_ps_start" class="swal2-input" type="datetime-local" value="' + defStart + '">' +
                    '<label class="swal2-input-label">' + t('ctp.session.windowEnd') + '</label>' +
                    '<input id="swal_ps_end" class="swal2-input" type="datetime-local" value="' + defEnd + '">',
                focusConfirm: false,
                showCancelButton: true,
                preConfirm: function() {
                    var name = $('#swal_ps_name').val();
                    var start = $('#swal_ps_start').val();
                    var end = $('#swal_ps_end').val();
                    if (!name || !start || !end) {
                        Swal.showValidationMessage(t('ctp.planning.allFieldsRequired') || 'All fields are required');
                        return false;
                    }
                    return { scenario_name: name, departure_window_start: start + ':00Z', departure_window_end: end + ':00Z' };
                }
            }).then(function(result) {
                if (result.isConfirmed && result.value) {
                    result.value.session_id = sess.session_id;
                    $.ajax({
                        url: API.planning.scenarios,
                        method: 'POST',
                        contentType: 'application/json',
                        data: JSON.stringify(result.value),
                        success: function(resp) {
                            if (resp.status === 'ok') {
                                Swal.fire({ icon: 'success', title: t('common.saved'), timer: 1500, showConfirmButton: false });
                                self.loadScenarios();
                            }
                        }
                    });
                }
            });
        },

        cloneScenario: function(scenarioId) {
            var self = this;
            var orig = this.scenarios.find(function(s) { return s.scenario_id === scenarioId; });
            var defaultName = (orig ? orig.scenario_name + ' (copy)' : '');

            Swal.fire({
                title: t('ctp.planning.cloneScenario'),
                input: 'text',
                inputValue: defaultName,
                inputPlaceholder: t('ctp.planning.scenarioName'),
                showCancelButton: true
            }).then(function(result) {
                if (result.isConfirmed && result.value) {
                    $.ajax({
                        url: API.planning.scenario_clone,
                        method: 'POST',
                        contentType: 'application/json',
                        data: JSON.stringify({ scenario_id: scenarioId, new_name: result.value }),
                        success: function(resp) {
                            if (resp.status === 'ok') {
                                self.loadScenarios();
                            }
                        }
                    });
                }
            });
        },

        editScenario: function(scenarioId) {
            var self = this;
            var sc = this.scenarios.find(function(s) { return s.scenario_id === scenarioId; });
            if (!sc) return;

            var start = sc.departure_window_start || '';
            var end = sc.departure_window_end || '';
            if (start) start = start.replace(/Z$/, '').replace(/\.\d+$/, '').substring(0, 16);
            if (end) end = end.replace(/Z$/, '').replace(/\.\d+$/, '').substring(0, 16);

            Swal.fire({
                title: t('ctp.planning.editScenario'),
                html:
                    '<input id="swal_es_name" class="swal2-input" value="' + (sc.scenario_name || '').replace(/"/g, '&quot;') + '" placeholder="' + t('ctp.planning.scenarioName') + '">' +
                    '<label class="swal2-input-label">' + t('ctp.session.windowStart') + '</label>' +
                    '<input id="swal_es_start" class="swal2-input" type="datetime-local" value="' + start + '">' +
                    '<label class="swal2-input-label">' + t('ctp.session.windowEnd') + '</label>' +
                    '<input id="swal_es_end" class="swal2-input" type="datetime-local" value="' + end + '">' +
                    '<label class="swal2-input-label">' + t('common.notes') + '</label>' +
                    '<textarea id="swal_es_notes" class="swal2-textarea" style="font-size:0.85rem;">' + (sc.notes || '') + '</textarea>' +
                    '<label class="swal2-input-label">' + t('common.status') + '</label>' +
                    '<select id="swal_es_status" class="swal2-select">' +
                        '<option value="DRAFT"' + (sc.status === 'DRAFT' ? ' selected' : '') + '>DRAFT</option>' +
                        '<option value="ACTIVE"' + (sc.status === 'ACTIVE' ? ' selected' : '') + '>ACTIVE</option>' +
                        '<option value="ARCHIVED"' + (sc.status === 'ARCHIVED' ? ' selected' : '') + '>ARCHIVED</option>' +
                    '</select>',
                focusConfirm: false,
                showCancelButton: true,
                preConfirm: function() {
                    var name = $('#swal_es_name').val();
                    if (!name) { Swal.showValidationMessage(t('ctp.planning.scenarioName') + ' required'); return false; }
                    var data = { scenario_id: scenarioId, scenario_name: name, status: $('#swal_es_status').val() };
                    var s = $('#swal_es_start').val();
                    var e = $('#swal_es_end').val();
                    if (s) data.departure_window_start = s + ':00Z';
                    if (e) data.departure_window_end = e + ':00Z';
                    var notes = $('#swal_es_notes').val();
                    if (notes !== (sc.notes || '')) data.notes = notes;
                    return data;
                }
            }).then(function(result) {
                if (result.isConfirmed && result.value) {
                    $.ajax({
                        url: API.planning.scenario_save,
                        method: 'POST',
                        contentType: 'application/json',
                        data: JSON.stringify(result.value),
                        success: function(resp) {
                            if (resp.status === 'ok') {
                                Swal.fire({ icon: 'success', title: t('common.saved'), timer: 1500, showConfirmButton: false });
                                self.loadScenarios();
                            } else {
                                Swal.fire({ icon: 'error', title: resp.message || t('common.error') });
                            }
                        }
                    });
                }
            });
        },

        // -- Block / Assignment CRUD --

        loadBlocks: function(scenarioId, $panel) {
            var self = this;
            // Always re-query DOM for fresh panel reference — loadScenarios() can rebuild
            // the scenario list while a compute AJAX is in-flight, detaching stale $panel refs
            var freshSelector = '.ps-blocks-panel[data-scenario="' + scenarioId + '"]';
            var $fresh = $(freshSelector);
            if ($fresh.length) $panel = $fresh;

            var blocksHtml =
                '<div class="pl-3 border-left border-primary" style="border-width:2px !important;">' +
                    '<div class="d-flex justify-content-between align-items-center mb-1">' +
                        '<small class="font-weight-bold text-uppercase" style="font-size:0.68rem;">' + t('ctp.planning.trafficBlocks') + '</small>' +
                        '<button class="btn btn-outline-primary btn-sm ps-add-block" data-scenario="' + scenarioId + '" style="font-size:0.65rem;padding:1px 6px;">' +
                            '<i class="fas fa-plus mr-1"></i>' + t('ctp.planning.addBlock') +
                        '</button>' +
                    '</div>' +
                    '<div class="ps-blocks-list" data-scenario="' + scenarioId + '">' +
                        '<div class="text-muted small py-1">' + t('ctp.planning.noBlocks') + '</div>' +
                    '</div>' +
                '</div>';
            $panel.html(blocksHtml);

            $.ajax({
                url: API.planning.compute,
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ scenario_id: scenarioId }),
                success: function(resp) {
                    if (resp.status === 'ok' && resp.data && resp.data.blocks) {
                        // Re-query DOM for fresh reference — scenarios may have reloaded during AJAX
                        var $livePanel = $(freshSelector);
                        var $target = $livePanel.length ? $livePanel.find('.ps-blocks-list') : $panel.find('.ps-blocks-list');
                        self.renderBlocks(scenarioId, resp.data.blocks, $target);
                    }
                }
            });

            $panel.find('.ps-add-block').off('click').on('click', function() {
                self.addBlock(scenarioId, $panel);
            });
        },

        renderBlocks: function(scenarioId, blocks, $container) {
            var self = this;
            self.blockCache[scenarioId] = blocks || [];
            $container.empty();

            if (!blocks || blocks.length === 0) {
                $container.html('<div class="text-muted small py-1">' + t('ctp.planning.noBlocks') + '</div>');
                return;
            }

            blocks.forEach(function(blk) {
                var origins = blk.origins_json ? [].concat(blk.origins_json).join(', ') : '*';
                var dests = blk.destinations_json ? [].concat(blk.destinations_json).join(', ') : '*';
                var $blk = $('<div class="card card-body p-2 mb-1" style="font-size:0.72rem;">' +
                    '<div class="d-flex justify-content-between align-items-start">' +
                        '<div>' +
                            '<strong>' + (blk.block_label || t('ctp.planning.trafficBlock')) + '</strong>' +
                            '<div class="text-muted">' + origins + ' → ' + dests + '</div>' +
                            '<div>' + t('ctp.planning.flightCount') + ': ' + (blk.flight_count || 0) + ' &middot; ' + (blk.dep_distribution || 'UNIFORM') + '</div>' +
                        '</div>' +
                        '<div class="btn-group btn-group-sm">' +
                            '<button class="btn btn-outline-info blk-edit" data-id="' + blk.block_id + '" style="font-size:0.6rem;padding:0 4px;"><i class="fas fa-pencil-alt"></i></button>' +
                            '<button class="btn btn-outline-danger blk-delete" data-id="' + blk.block_id + '" style="font-size:0.6rem;padding:0 4px;"><i class="fas fa-trash"></i></button>' +
                        '</div>' +
                    '</div>' +
                    '<div class="blk-assignments mt-1" data-block="' + blk.block_id + '"></div>' +
                '</div>');
                $container.append($blk);

                // Render assignments within block
                if (blk.assignments && blk.assignments.length > 0) {
                    var $asgn = $blk.find('.blk-assignments');
                    blk.assignments.forEach(function(a) {
                        $asgn.append(
                            '<div class="d-flex justify-content-between align-items-center pl-2 border-left" style="font-size:0.68rem;">' +
                                '<span><i class="fas fa-route mr-1 text-primary" style="font-size:0.6rem;"></i>' + (a.track_name || '') + ' (' + (a.flight_count || 0) + ' ' + t('ctp.throughput.flights') + ')' + (a.altitude_range ? ' ' + a.altitude_range : '') + '</span>' +
                                '<span class="btn-group btn-group-sm">' +
                                    '<button class="btn btn-outline-info asgn-edit" data-id="' + a.assignment_id + '" data-block="' + blk.block_id + '" style="font-size:0.55rem;padding:0 3px;"><i class="fas fa-pencil-alt"></i></button>' +
                                    '<button class="btn btn-outline-danger asgn-delete" data-id="' + a.assignment_id + '" style="font-size:0.55rem;padding:0 3px;"><i class="fas fa-trash"></i></button>' +
                                '</span>' +
                            '</div>'
                        );
                    });
                }

                // Add assignment button
                $blk.find('.blk-assignments').append(
                    '<button class="btn btn-outline-secondary btn-sm asgn-add mt-1" data-block="' + blk.block_id + '" style="font-size:0.6rem;padding:0 4px;">' +
                        '<i class="fas fa-plus mr-1"></i>' + t('ctp.planning.addAssignment') +
                    '</button>'
                );
            });

            // Bind events
            $container.find('.blk-edit').on('click', function() { self.editBlock($(this).data('id'), scenarioId, $container); });
            $container.find('.blk-delete').on('click', function() { self.deleteBlock($(this).data('id'), scenarioId, $container); });
            $container.find('.asgn-add').on('click', function() { self.addAssignment($(this).data('block'), scenarioId, $container); });
            $container.find('.asgn-edit').on('click', function() { self.editAssignment($(this).data('id'), $(this).data('block'), scenarioId, $container); });
            $container.find('.asgn-delete').on('click', function() { self.deleteAssignment($(this).data('id'), scenarioId, $container); });
        },

        addBlock: function(scenarioId, $panel) {
            var self = this;
            Swal.fire({
                title: t('ctp.planning.addBlock'),
                html:
                    '<input id="swal_bl_label" class="swal2-input" placeholder="' + t('ctp.planning.blockLabel') + '">' +
                    '<input id="swal_bl_origins" class="swal2-input" placeholder="' + t('ctp.throughput.origins') + ' (KJFK,KBOS)">' +
                    '<input id="swal_bl_dests" class="swal2-input" placeholder="' + t('ctp.throughput.destinations') + ' (EGLL,LFPG)">' +
                    '<input id="swal_bl_count" class="swal2-input" type="number" placeholder="' + t('ctp.planning.flightCount') + '" value="100">' +
                    '<select id="swal_bl_dist" class="swal2-select">' +
                        '<option value="UNIFORM">' + t('ctp.planning.uniform') + '</option>' +
                        '<option value="FRONT_LOADED">' + t('ctp.planning.frontLoaded') + '</option>' +
                        '<option value="BACK_LOADED">' + t('ctp.planning.backLoaded') + '</option>' +
                    '</select>',
                focusConfirm: false,
                showCancelButton: true,
                preConfirm: function() {
                    var origins = $('#swal_bl_origins').val().split(',').map(function(s) { return s.trim(); }).filter(Boolean);
                    var dests = $('#swal_bl_dests').val().split(',').map(function(s) { return s.trim(); }).filter(Boolean);
                    if (!origins.length || !dests.length) { Swal.showValidationMessage(t('ctp.planning.originsDestsRequired')); return false; }
                    return {
                        scenario_id: scenarioId,
                        block_label: $('#swal_bl_label').val(),
                        origins_json: origins,
                        destinations_json: dests,
                        flight_count: parseInt($('#swal_bl_count').val()) || 0,
                        dep_distribution: $('#swal_bl_dist').val()
                    };
                }
            }).then(function(result) {
                if (result.isConfirmed && result.value) {
                    $.ajax({
                        url: API.planning.block_save,
                        method: 'POST',
                        contentType: 'application/json',
                        data: JSON.stringify(result.value),
                        success: function(resp) {
                            if (resp.status === 'ok') {
                                self.loadBlocks(scenarioId, $panel.closest('.ps-blocks-panel'));
                            } else {
                                Swal.fire({ icon: 'error', title: resp.message || t('common.error') });
                            }
                        }
                    });
                }
            });
        },

        editBlock: function(blockId, scenarioId, $container) {
            var self = this;
            var blocks = self.blockCache[scenarioId] || [];
            var blk = blocks.find(function(b) { return b.block_id === blockId; }) || {};
            var curDist = blk.dep_distribution || 'UNIFORM';
            Swal.fire({
                title: t('ctp.planning.editBlock'),
                html:
                    '<input id="swal_bl_label" class="swal2-input" placeholder="' + t('ctp.planning.blockLabel') + '" value="' + ((blk.block_label || '').replace(/"/g, '&quot;')) + '">' +
                    '<input id="swal_bl_count" class="swal2-input" type="number" placeholder="' + t('ctp.planning.flightCount') + '" value="' + (blk.flight_count || '') + '">' +
                    '<select id="swal_bl_dist" class="swal2-select">' +
                        '<option value="UNIFORM"' + (curDist === 'UNIFORM' ? ' selected' : '') + '>' + t('ctp.planning.uniform') + '</option>' +
                        '<option value="FRONT_LOADED"' + (curDist === 'FRONT_LOADED' ? ' selected' : '') + '>' + t('ctp.planning.frontLoaded') + '</option>' +
                        '<option value="BACK_LOADED"' + (curDist === 'BACK_LOADED' ? ' selected' : '') + '>' + t('ctp.planning.backLoaded') + '</option>' +
                    '</select>',
                focusConfirm: false,
                showCancelButton: true,
                preConfirm: function() {
                    var data = { block_id: blockId };
                    var label = $('#swal_bl_label').val();
                    if (label) data.block_label = label;
                    var count = $('#swal_bl_count').val();
                    if (count) data.flight_count = parseInt(count);
                    data.dep_distribution = $('#swal_bl_dist').val();
                    return data;
                }
            }).then(function(result) {
                if (result.isConfirmed && result.value) {
                    $.ajax({
                        url: API.planning.block_save,
                        method: 'POST',
                        contentType: 'application/json',
                        data: JSON.stringify(result.value),
                        success: function(resp) {
                            if (resp.status === 'ok') {
                                self.loadBlocks(scenarioId, $container.closest('.ps-blocks-panel'));
                            } else {
                                Swal.fire({ icon: 'error', title: resp.message || t('common.error') });
                            }
                        }
                    });
                }
            });
        },

        deleteBlock: function(blockId, scenarioId, $container) {
            var self = this;
            Swal.fire({
                title: t('ctp.planning.removeBlock'),
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545'
            }).then(function(result) {
                if (result.isConfirmed) {
                    $.ajax({
                        url: API.planning.block_delete,
                        method: 'POST',
                        contentType: 'application/json',
                        data: JSON.stringify({ block_id: blockId }),
                        success: function(resp) {
                            if (resp.status === 'ok') {
                                self.loadBlocks(scenarioId, $container.closest('.ps-blocks-panel'));
                            }
                        }
                    });
                }
            });
        },

        addAssignment: function(blockId, scenarioId, $container) {
            var self = this;
            Swal.fire({
                title: t('ctp.planning.addAssignment'),
                html:
                    '<input id="swal_as_track" class="swal2-input" placeholder="' + t('ctp.planning.trackAssignment') + ' (e.g. NAT A)">' +
                    '<input id="swal_as_route" class="swal2-input" placeholder="' + t('ctp.planning.routeString') + '">' +
                    '<input id="swal_as_count" class="swal2-input" type="number" placeholder="' + t('ctp.planning.flightCount') + '" value="10">' +
                    '<input id="swal_as_alt" class="swal2-input" placeholder="' + t('ctp.planning.altitudeRange') + ' (e.g. FL340-FL360)">',
                focusConfirm: false,
                showCancelButton: true,
                preConfirm: function() {
                    var count = parseInt($('#swal_as_count').val()) || 0;
                    if (count <= 0) { Swal.showValidationMessage(t('ctp.planning.flightCount') + ' > 0'); return false; }
                    return {
                        block_id: blockId,
                        track_name: $('#swal_as_track').val(),
                        route_string: $('#swal_as_route').val(),
                        flight_count: count,
                        altitude_range: $('#swal_as_alt').val() || null
                    };
                }
            }).then(function(result) {
                if (result.isConfirmed && result.value) {
                    $.ajax({
                        url: API.planning.assignment_save,
                        method: 'POST',
                        contentType: 'application/json',
                        data: JSON.stringify(result.value),
                        success: function(resp) {
                            if (resp.status === 'ok') {
                                self.loadBlocks(scenarioId, $container.closest('.ps-blocks-panel'));
                            } else {
                                Swal.fire({ icon: 'error', title: resp.message || t('common.error') });
                            }
                        }
                    });
                }
            });
        },

        editAssignment: function(assignmentId, blockId, scenarioId, $container) {
            var self = this;
            var blocks = self.blockCache[scenarioId] || [];
            var parentBlock = blocks.find(function(b) { return b.block_id === blockId; });
            var asgn = {};
            if (parentBlock && parentBlock.assignments) {
                asgn = parentBlock.assignments.find(function(a) { return a.assignment_id === assignmentId; }) || {};
            }
            Swal.fire({
                title: t('ctp.planning.editAssignment'),
                html:
                    '<input id="swal_as_track" class="swal2-input" placeholder="' + t('ctp.planning.trackAssignment') + '" value="' + ((asgn.track_name || '').replace(/"/g, '&quot;')) + '">' +
                    '<input id="swal_as_route" class="swal2-input" placeholder="' + t('ctp.planning.routeString') + '" value="' + ((asgn.route_string || '').replace(/"/g, '&quot;')) + '">' +
                    '<input id="swal_as_count" class="swal2-input" type="number" placeholder="' + t('ctp.planning.flightCount') + '" value="' + (asgn.flight_count || '') + '">' +
                    '<input id="swal_as_alt" class="swal2-input" placeholder="' + t('ctp.planning.altitudeRange') + '" value="' + ((asgn.altitude_range || '').replace(/"/g, '&quot;')) + '">',
                focusConfirm: false,
                showCancelButton: true,
                preConfirm: function() {
                    var data = { assignment_id: assignmentId };
                    var track = $('#swal_as_track').val();
                    if (track) data.track_name = track;
                    var route = $('#swal_as_route').val();
                    if (route) data.route_string = route;
                    var count = $('#swal_as_count').val();
                    if (count) {
                        count = parseInt(count);
                        if (count <= 0) { Swal.showValidationMessage(t('ctp.planning.flightCount') + ' > 0'); return false; }
                        data.flight_count = count;
                    }
                    var alt = $('#swal_as_alt').val();
                    if (alt) data.altitude_range = alt;
                    return data;
                }
            }).then(function(result) {
                if (result.isConfirmed && result.value) {
                    $.ajax({
                        url: API.planning.assignment_save,
                        method: 'POST',
                        contentType: 'application/json',
                        data: JSON.stringify(result.value),
                        success: function(resp) {
                            if (resp.status === 'ok') {
                                self.loadBlocks(scenarioId, $container.closest('.ps-blocks-panel'));
                            } else {
                                Swal.fire({ icon: 'error', title: resp.message || t('common.error') });
                            }
                        }
                    });
                }
            });
        },

        deleteAssignment: function(assignmentId, scenarioId, $container) {
            var self = this;
            Swal.fire({
                title: t('ctp.planning.deleteAssignment'),
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545'
            }).then(function(result) {
                if (result.isConfirmed) {
                    $.ajax({
                        url: API.planning.assignment_delete,
                        method: 'POST',
                        contentType: 'application/json',
                        data: JSON.stringify({ assignment_id: assignmentId }),
                        success: function(resp) {
                            if (resp.status === 'ok') {
                                self.loadBlocks(scenarioId, $container.closest('.ps-blocks-panel'));
                            }
                        }
                    });
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
                        Swal.fire({ icon: 'error', title: resp.message || t('ctp.error.computeFailed') });
                    }
                },
                error: function() {
                    Swal.close();
                    Swal.fire({ icon: 'error', title: t('ctp.error.computeRequestFailed') });
                }
            });
        },

        renderResults: function(data, scenarioId) {
            var $results = $('#ctp_planning_results');
            $results.empty().show();
            ComputeExport.storeData(data);

            // Export toolbar with format dropdowns
            var exportBtn = function(dataset, label, icon) {
                return '<div class="btn-group btn-group-sm mr-1">' +
                    '<button class="btn btn-outline-secondary dropdown-toggle" data-toggle="dropdown"><i class="fas fa-' + icon + ' mr-1"></i>' + label + '</button>' +
                    '<div class="dropdown-menu dropdown-menu-right">' +
                    '<a class="dropdown-item ctp-export-item" data-ds="' + dataset + '" data-fmt="csv"><i class="fas fa-file-csv mr-2 text-success"></i>CSV</a>' +
                    '<a class="dropdown-item ctp-export-item" data-ds="' + dataset + '" data-fmt="xlsx"><i class="fas fa-file-excel mr-2 text-success"></i>Excel (XLSX)</a>' +
                    '<a class="dropdown-item ctp-export-item" data-ds="' + dataset + '" data-fmt="txt"><i class="fas fa-file-alt mr-2 text-muted"></i>Plain Text</a>' +
                    '<a class="dropdown-item ctp-export-item" data-ds="' + dataset + '" data-fmt="json"><i class="fas fa-file-code mr-2 text-info"></i>JSON</a>' +
                    '<div class="dropdown-divider"></div>' +
                    '<a class="dropdown-item ctp-export-item" data-ds="' + dataset + '" data-fmt="clipboard"><i class="fas fa-clipboard mr-2 text-warning"></i>Copy to Clipboard</a>' +
                    '</div></div>';
            };
            $results.append(
                '<div class="mb-2 d-flex align-items-center flex-wrap">' +
                '<span class="small font-weight-bold mr-2">' + t('ctp.planning.computeResults') + '</span>' +
                exportBtn('flights', t('ctp.planning.flightList'), 'plane') +
                exportBtn('distributions', t('ctp.planning.distributions'), 'chart-pie') +
                exportBtn('bins', t('ctp.planning.timeBins'), 'clock') +
                exportBtn('summary', t('ctp.planning.volumeProfiles'), 'tachometer-alt') +
                exportBtn('all', t('ctp.planning.exportAll'), 'file-archive') +
                '<button class="btn btn-sm btn-outline-info ml-1" id="ctp_export_raw_json" title="' + t('ctp.planning.exportJSON') + '"><i class="fas fa-file-download mr-1"></i>Raw JSON</button>' +
                '</div>'
            );
            $results.find('.ctp-export-item').on('click', function(e) {
                e.preventDefault();
                ComputeExport.exportAs($(this).data('ds'), $(this).data('fmt'));
            });
            $('#ctp_export_raw_json').on('click', function() { ComputeExport.exportFullJSON(); });

            // Simulated demand charts (Departure by Origin, Oceanic Entry by Track, Arrival by Dest)
            SimDemandCharts.render($results, data);

            // Constraint checks table
            if (data.constraint_checks && data.constraint_checks.length > 0) {
                var $table = $('<table class="table table-sm table-bordered"><thead><tr>' +
                    '<th>' + t('ctp.throughput.configLabel') + '</th>' +
                    '<th>' + t('ctp.planning.planned') + '</th>' +
                    '<th>' + t('ctp.throughput.maxAcph') + '</th>' +
                    '<th>' + t('ctp.planning.constraintCheck') + '</th>' +
                    '</tr></thead><tbody></tbody></table>');

                data.constraint_checks.forEach(function(check) {
                    var status, planned, limit;
                    if (check.violation_type === 'OCEAN_ENTRY_WINDOW') {
                        status = '<span class="text-danger"><i class="fas fa-exclamation-triangle"></i> ' + t('ctp.planning.oceanWindowViolation') + ' (' + check.flights_outside + '/' + check.total_flights + ')</span>';
                        planned = check.total_flights;
                        limit = (check.window_start || '').substring(11, 16) + '-' + (check.window_end || '').substring(11, 16) + 'Z';
                    } else {
                        status = check.violated
                            ? '<span class="text-danger"><i class="fas fa-exclamation-triangle"></i> ' + t('ctp.planning.violated') + '</span>'
                            : '<span class="text-success"><i class="fas fa-check"></i> ' + t('ctp.planning.withinLimits') + '</span>';
                        planned = check.peak_actual || 0;
                        limit = check.max_acph || '-';
                    }
                    $table.find('tbody').append(
                        '<tr><td>' + (check.config_label || '-') + '</td><td>' + planned + '</td><td>' + limit + '</td><td>' + status + '</td></tr>'
                    );
                });
                $results.append($table);
            }

            // Track summary
            if (data.track_summary && data.track_summary.length > 0) {
                var $summary = $('<table class="table table-sm"><thead><tr>' +
                    '<th>' + t('ctp.nat.trackResolved') + '</th>' +
                    '<th>' + t('ctp.planning.flightCount') + '</th>' +
                    '<th>' + t('ctp.planning.arrivalProfile') + '</th>' +
                    '</tr></thead><tbody></tbody></table>');

                data.track_summary.forEach(function(ts) {
                    $summary.find('tbody').append(
                        '<tr><td>' + ts.track + '</td><td>' + ts.flight_count + '</td><td>' + (ts.peak_rate_hr || '-') + '/hr peak</td></tr>'
                    );
                });
                $results.append($summary);
            }

            // Volume profiles (segment timing per track)
            if (data.volume_profiles && data.volume_profiles.length > 0) {
                $results.append('<h6 class="mt-3 mb-1 text-uppercase text-muted" style="font-size:0.7rem;letter-spacing:0.05em;">' + t('ctp.planning.volumeProfiles') + '</h6>');
                var $vp = $('<table class="table table-sm table-bordered"><thead><tr>' +
                    '<th>' + t('ctp.nat.trackResolved') + '</th><th>' + t('ctp.planning.flightCount') + '</th>' +
                    '<th>' + t('ctp.planning.preOceanic') + '</th><th>' + t('ctp.planning.oceanic') + '</th>' +
                    '<th>' + t('ctp.planning.postOceanic') + '</th><th>' + t('ctp.planning.totalTime') + '</th>' +
                    '</tr></thead><tbody></tbody></table>');
                data.volume_profiles.forEach(function(vp) {
                    $vp.find('tbody').append('<tr><td>' + vp.track + '</td><td>' + vp.flight_count +
                        '</td><td>' + vp.avg_pre_oceanic_min + 'm</td><td>' + vp.avg_oceanic_min +
                        'm</td><td>' + vp.avg_post_oceanic_min + 'm</td><td>' + vp.avg_total_min + 'm</td></tr>');
                });
                $results.append($vp);
            }

            // Distribution tables in a collapsible accordion
            if (data.distributions) {
                $results.append('<h6 class="mt-3 mb-1 text-uppercase text-muted" style="font-size:0.7rem;letter-spacing:0.05em;">' + t('ctp.planning.distributions') + '</h6>');
                var distId = 'ctp_dist_accordion_' + Date.now();
                var $accordion = $('<div class="accordion" id="' + distId + '"></div>');

                var distSections = [
                    { key: 'origins', title: t('ctp.planning.originDist'), cols: ['origin', 'count', 'pct'] },
                    { key: 'destinations', title: t('ctp.planning.destDist'), cols: ['dest', 'count', 'pct'] },
                    { key: 'od_pairs', title: t('ctp.planning.odPairs'), cols: ['origin', 'dest', 'count', 'pct'] },
                    { key: 'origin_track', title: t('ctp.planning.originToTrack'), cols: ['origin', 'track', 'count'] },
                    { key: 'track_dest', title: t('ctp.planning.trackToDest'), cols: ['track', 'dest', 'count'] }
                ];

                distSections.forEach(function(sec, idx) {
                    var rows = data.distributions[sec.key];
                    if (!rows || rows.length === 0) return;
                    var cardId = distId + '_' + idx;
                    var $card = $('<div class="card"><div class="card-header py-1 px-2"><a class="small" data-toggle="collapse" href="#' + cardId + '_body">' +
                        sec.title + ' (' + rows.length + ')</a></div>' +
                        '<div id="' + cardId + '_body" class="collapse" data-parent="#' + distId + '">' +
                        '<div class="card-body p-0"></div></div></div>');
                    var $tbl = $('<table class="table table-sm table-bordered mb-0"><thead><tr></tr></thead><tbody></tbody></table>');
                    sec.cols.forEach(function(c) { $tbl.find('thead tr').append('<th>' + c + '</th>'); });
                    rows.forEach(function(r) {
                        var $row = $('<tr>');
                        sec.cols.forEach(function(c) { $row.append('<td>' + (r[c] !== undefined ? r[c] : '-') + (c === 'pct' ? '%' : '') + '</td>'); });
                        $tbl.find('tbody').append($row);
                    });
                    $card.find('.card-body').append($tbl);
                    $accordion.append($card);
                });
                $results.append($accordion);
            }

            // Flight list (collapsible, sorted by ocean entry)
            if (data.flight_list && data.flight_list.length > 0) {
                $results.append('<h6 class="mt-3 mb-1 text-uppercase text-muted" style="font-size:0.7rem;letter-spacing:0.05em;">' + t('ctp.planning.flightList') + ' (' + data.flight_list.length + ')</h6>');
                var flId = 'ctp_flight_list_' + Date.now();
                var $flWrap = $('<div><a class="small" data-toggle="collapse" href="#' + flId + '">' + t('ctp.planning.showFlightList') + '</a>' +
                    '<div id="' + flId + '" class="collapse"><div class="table-responsive" style="max-height:300px;overflow-y:auto;"></div></div></div>');
                var $flTbl = $('<table class="table table-sm table-bordered mb-0" style="font-size:0.7rem;"><thead><tr>' +
                    '<th>#</th><th>' + t('ctp.planning.originLabel') + '</th><th>' + t('ctp.planning.destLabel') + '</th>' +
                    '<th>' + t('ctp.nat.trackResolved') + '</th><th>DEP</th><th>ENTRY</th><th>EXIT</th><th>ARR</th>' +
                    '<th>' + t('ctp.planning.totalTime') + '</th></tr></thead><tbody></tbody></table>');
                data.flight_list.forEach(function(f, i) {
                    var depTime = (f.dep_utc || '').substring(11, 16);
                    var entryTime = (f.entry_utc || '').substring(11, 16);
                    var exitTime = (f.exit_utc || '').substring(11, 16);
                    var arrTime = (f.arr_utc || '').substring(11, 16);
                    $flTbl.find('tbody').append('<tr><td>' + (i + 1) + '</td><td>' + f.origin + '</td><td>' + f.dest +
                        '</td><td>' + f.track + '</td><td>' + depTime + 'Z</td><td>' + entryTime + 'Z</td><td>' + exitTime +
                        'Z</td><td>' + arrTime + 'Z</td><td>' + f.total_min + 'm</td></tr>');
                });
                $flWrap.find('.table-responsive').append($flTbl);
                $results.append($flWrap);
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
                        data: JSON.stringify({ scenario_id: scenarioId }),
                        success: function(resp) {
                            if (resp.status === 'ok') {
                                Swal.fire({ icon: 'success', title: t('ctp.planning.appliedSuccessfully'), timer: 2000, showConfirmButton: false });
                                ThroughputManager.refresh();
                                SessionManager.loadSessions();
                            } else {
                                Swal.fire({ icon: 'error', title: resp.message || 'Apply failed' });
                            }
                        },
                        error: function(xhr) {
                            var msg = 'Apply failed';
                            try { var r = JSON.parse(xhr.responseText); msg = r.message || msg; } catch(e) {}
                            Swal.fire({ icon: 'error', title: msg });
                        }
                    });
                }
            });
        }
    };

    // ========================================================================
    // Track Constraint Manager
    // ========================================================================

    var TrackConstraintManager = {
        constraints: [],

        init: function() {
            var self = this;
            $(document).on('click', '#ctp_track_constraint_add', function() { self.showAddDialog(); });
            $(document).on('shown.bs.tab', 'a[href="#ctp_planning_panel"]', function() { self.refresh(); });
        },

        refresh: function() {
            if (!state.currentSession) return;
            var self = this;
            $.ajax({
                url: API.planning.track_constraints,
                data: { session_id: state.currentSession.session_id },
                success: function(resp) {
                    if (resp.status === 'ok' && resp.data) {
                        self.constraints = resp.data;
                        self.renderTable();
                    }
                }
            });
        },

        renderTable: function() {
            var $container = $('#ctp_track_constraints_table');
            $container.empty();

            if (!this.constraints || this.constraints.length === 0) {
                $container.html('<div class="text-center text-muted py-2 small">' + t('ctp.planning.noConstraints') + '</div>');
                return;
            }

            var $table = $('<table class="table table-sm table-bordered table-striped mb-0"><thead><tr>' +
                '<th>' + t('ctp.nat.trackResolved') + '</th>' +
                '<th>' + t('ctp.throughput.maxAcph') + '</th>' +
                '<th>' + t('ctp.planning.oceanEntryStart') + '</th>' +
                '<th>' + t('ctp.planning.oceanEntryEnd') + '</th>' +
                '<th>' + t('ctp.planning.flMin') + '</th>' +
                '<th>' + t('ctp.planning.flMax') + '</th>' +
                '<th>' + t('common.actions') + '</th>' +
                '</tr></thead><tbody></tbody></table>');

            var self = this;
            this.constraints.forEach(function(c) {
                var entryStart = c.ocean_entry_start ? c.ocean_entry_start.replace('T', ' ').replace('Z', '') : '-';
                var entryEnd = c.ocean_entry_end ? c.ocean_entry_end.replace('T', ' ').replace('Z', '') : '-';
                var $row = $('<tr>' +
                    '<td>' + c.track_name + '</td>' +
                    '<td>' + (c.max_acph || '-') + '</td>' +
                    '<td>' + entryStart + '</td>' +
                    '<td>' + entryEnd + '</td>' +
                    '<td>' + (c.fl_min || '-') + '</td>' +
                    '<td>' + (c.fl_max || '-') + '</td>' +
                    '<td>' +
                        '<button class="btn btn-xs btn-outline-info tc-edit" data-id="' + c.constraint_id + '"><i class="fas fa-pencil-alt"></i></button> ' +
                        '<button class="btn btn-xs btn-outline-danger tc-delete" data-id="' + c.constraint_id + '"><i class="fas fa-trash"></i></button>' +
                    '</td></tr>');
                $table.find('tbody').append($row);
            });

            $container.append($table);

            $container.find('.tc-edit').on('click', function() {
                var id = $(this).data('id');
                var constraint = self.constraints.find(function(c) { return c.constraint_id === id; });
                if (constraint) self.showEditDialog(constraint);
            });
            $container.find('.tc-delete').on('click', function() {
                var id = $(this).data('id');
                self.deleteConstraint(id);
            });
        },

        showAddDialog: function() {
            if (!state.currentSession) return;
            this._showDialog(null);
        },

        showEditDialog: function(constraint) {
            this._showDialog(constraint);
        },

        _showDialog: function(existing) {
            var self = this;
            var isEdit = !!existing;
            var title = isEdit ? t('ctp.planning.editConstraint') : t('ctp.planning.addConstraint');

            Swal.fire({
                title: title,
                html:
                    '<div class="form-group text-left mb-2"><label class="small font-weight-bold">' + t('ctp.nat.trackResolved') + '</label>' +
                    '<input id="swal_tc_track" class="swal2-input" placeholder="e.g. NATA" value="' + (existing ? existing.track_name : '') + '"' + (isEdit ? ' readonly' : '') + '></div>' +
                    '<div class="form-group text-left mb-2"><label class="small font-weight-bold">' + t('ctp.throughput.maxAcph') + '</label>' +
                    '<input id="swal_tc_acph" type="number" class="swal2-input" placeholder="e.g. 120" value="' + (existing && existing.max_acph ? existing.max_acph : '') + '"></div>' +
                    '<div class="form-group text-left mb-2"><label class="small font-weight-bold">' + t('ctp.planning.oceanEntryStart') + '</label>' +
                    '<input id="swal_tc_start" type="datetime-local" class="swal2-input" value="' + (existing && existing.ocean_entry_start ? existing.ocean_entry_start.replace('Z', '').replace('T', 'T') : '') + '"></div>' +
                    '<div class="form-group text-left mb-2"><label class="small font-weight-bold">' + t('ctp.planning.oceanEntryEnd') + '</label>' +
                    '<input id="swal_tc_end" type="datetime-local" class="swal2-input" value="' + (existing && existing.ocean_entry_end ? existing.ocean_entry_end.replace('Z', '').replace('T', 'T') : '') + '"></div>' +
                    '<div class="row"><div class="col-6 form-group text-left mb-2"><label class="small font-weight-bold">' + t('ctp.planning.flMin') + '</label>' +
                    '<input id="swal_tc_flmin" type="number" class="swal2-input" placeholder="e.g. 310" value="' + (existing && existing.fl_min ? existing.fl_min : '') + '"></div>' +
                    '<div class="col-6 form-group text-left mb-2"><label class="small font-weight-bold">' + t('ctp.planning.flMax') + '</label>' +
                    '<input id="swal_tc_flmax" type="number" class="swal2-input" placeholder="e.g. 410" value="' + (existing && existing.fl_max ? existing.fl_max : '') + '"></div></div>' +
                    '<div class="form-group text-left mb-2"><label class="small font-weight-bold">' + t('common.notes') + '</label>' +
                    '<input id="swal_tc_notes" class="swal2-input" value="' + (existing && existing.notes ? existing.notes : '') + '"></div>',
                showCancelButton: true,
                confirmButtonText: isEdit ? t('common.save') : t('common.create'),
                preConfirm: function() {
                    var track = document.getElementById('swal_tc_track').value.trim().toUpperCase();
                    if (!track) {
                        Swal.showValidationMessage(t('ctp.planning.trackRequired'));
                        return false;
                    }
                    return {
                        session_id: state.currentSession.session_id,
                        track_name: track,
                        max_acph: document.getElementById('swal_tc_acph').value || null,
                        ocean_entry_start: document.getElementById('swal_tc_start').value || null,
                        ocean_entry_end: document.getElementById('swal_tc_end').value || null,
                        fl_min: document.getElementById('swal_tc_flmin').value || null,
                        fl_max: document.getElementById('swal_tc_flmax').value || null,
                        notes: document.getElementById('swal_tc_notes').value || null
                    };
                }
            }).then(function(result) {
                if (result.isConfirmed && result.value) {
                    $.ajax({
                        url: API.planning.track_constraints,
                        method: 'POST',
                        contentType: 'application/json',
                        data: JSON.stringify(result.value),
                        success: function(resp) {
                            if (resp.status === 'ok') {
                                self.refresh();
                            } else {
                                Swal.fire({ icon: 'error', title: resp.message || 'Save failed' });
                            }
                        },
                        error: function() {
                            Swal.fire({ icon: 'error', title: 'Save failed' });
                        }
                    });
                }
            });
        },

        deleteConstraint: function(constraintId) {
            var self = this;
            Swal.fire({
                title: t('common.confirmDelete'),
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33'
            }).then(function(result) {
                if (result.isConfirmed) {
                    $.ajax({
                        url: API.planning.track_constraints,
                        method: 'DELETE',
                        contentType: 'application/json',
                        data: JSON.stringify({ constraint_id: constraintId }),
                        success: function(resp) {
                            if (resp.status === 'ok') {
                                self.refresh();
                            }
                        }
                    });
                }
            });
        }
    };

    // ========================================================================
    // Route Template Manager
    // ========================================================================

    var RouteTemplateManager = {
        templates: [],

        init: function() {
            var self = this;
            $(document).on('click', '#ctp_routes_create', function() { self.showCreateDialog(); });
            $(document).on('shown.bs.tab', 'a[href="#ctp_routes_panel"]', function() { self.refresh(); });
        },

        refresh: function() {
            if (!state.currentSession) return;
            var self = this;
            $.ajax({
                url: API.routes.templates,
                data: { session_id: state.currentSession.session_id },
                success: function(resp) {
                    if (resp.status === 'ok' && resp.data) {
                        self.templates = resp.data.templates || [];
                        self.renderTable(self.templates);
                    }
                }
            });
        },

        renderTable: function(templates) {
            var $tbody = $('#ctp_routes_table tbody');
            $tbody.empty();

            if (!templates || templates.length === 0) {
                $tbody.html('<tr><td colspan="6" class="text-center text-muted py-3">' + t('ctp.routes.noTemplates') + '</td></tr>');
                return;
            }

            var self = this;
            templates.forEach(function(tpl) {
                var $row = $('<tr>')
                    .append($('<td>').text(tpl.template_name || ''))
                    .append($('<td>').html('<span class="badge badge-' + (tpl.segment === 'OCEANIC' ? 'primary' : tpl.segment === 'NA' ? 'info' : 'success') + '">' + (tpl.segment || '') + '</span>'))
                    .append($('<td>').css({'font-family': 'Inconsolata, monospace', 'font-size': '0.68rem', 'max-width': '200px', 'overflow': 'hidden', 'text-overflow': 'ellipsis'}).text(tpl.route_string || '').attr('title', tpl.route_string || ''))
                    .append($('<td>').text(tpl.priority || 50))
                    .append($('<td>').html(tpl.for_event_flights ? '<i class="fas fa-check text-success"></i>' : ''))
                    .append($('<td>').html(
                        '<div class="btn-group btn-group-sm">' +
                            '<button class="btn btn-outline-info rt-edit" data-id="' + tpl.template_id + '" title="' + t('common.edit') + '"><i class="fas fa-pencil-alt"></i></button>' +
                            '<button class="btn btn-outline-danger rt-delete" data-id="' + tpl.template_id + '" title="' + t('common.delete') + '"><i class="fas fa-trash"></i></button>' +
                        '</div>'
                    ));
                $tbody.append($row);
            });

            $tbody.find('.rt-edit').on('click', function() {
                var id = $(this).data('id');
                var tpl = self.templates.find(function(t) { return t.template_id === id; });
                if (tpl) self.showEditDialog(tpl);
            });
            $tbody.find('.rt-delete').on('click', function() { self.deleteTemplate($(this).data('id')); });
        },

        showCreateDialog: function() {
            var self = this;
            Swal.fire({
                title: t('ctp.routes.createTemplate'),
                html:
                    '<input id="swal_rt_name" class="swal2-input" placeholder="' + t('ctp.routes.templateName') + '">' +
                    '<select id="swal_rt_segment" class="swal2-select">' +
                        '<option value="NA">NA</option>' +
                        '<option value="OCEANIC" selected>OCEANIC</option>' +
                        '<option value="EU">EU</option>' +
                    '</select>' +
                    '<input id="swal_rt_route" class="swal2-input" placeholder="' + t('ctp.routes.routeString') + '">' +
                    '<input id="swal_rt_priority" class="swal2-input" type="number" value="50" placeholder="' + t('ctp.throughput.priority') + '">' +
                    '<input id="swal_rt_altitude" class="swal2-input" placeholder="' + t('ctp.planning.altitudeRange') + ' (e.g. FL340-FL390)">',
                focusConfirm: false,
                showCancelButton: true,
                preConfirm: function() {
                    var name = $('#swal_rt_name').val();
                    var route = $('#swal_rt_route').val();
                    if (!name || !route) { Swal.showValidationMessage(t('ctp.routes.nameRouteRequired')); return false; }
                    return {
                        template_name: name,
                        segment: $('#swal_rt_segment').val(),
                        route_string: route,
                        session_id: state.currentSession.session_id,
                        priority: parseInt($('#swal_rt_priority').val()) || 50,
                        altitude_range: $('#swal_rt_altitude').val() || null
                    };
                }
            }).then(function(result) {
                if (result.isConfirmed && result.value) {
                    $.ajax({
                        url: API.routes.templates,
                        method: 'POST',
                        contentType: 'application/json',
                        data: JSON.stringify(result.value),
                        success: function(resp) {
                            if (resp.status === 'ok') {
                                Swal.fire({ icon: 'success', title: t('common.saved'), timer: 1500, showConfirmButton: false });
                                self.refresh();
                            } else {
                                Swal.fire({ icon: 'error', title: resp.message || t('common.error') });
                            }
                        }
                    });
                }
            });
        },

        showEditDialog: function(tpl) {
            var self = this;
            Swal.fire({
                title: t('ctp.routes.editTemplate'),
                html:
                    '<input id="swal_rt_name" class="swal2-input" value="' + (tpl.template_name || '').replace(/"/g, '&quot;') + '">' +
                    '<select id="swal_rt_segment" class="swal2-select">' +
                        '<option value="NA"' + (tpl.segment === 'NA' ? ' selected' : '') + '>NA</option>' +
                        '<option value="OCEANIC"' + (tpl.segment === 'OCEANIC' ? ' selected' : '') + '>OCEANIC</option>' +
                        '<option value="EU"' + (tpl.segment === 'EU' ? ' selected' : '') + '>EU</option>' +
                    '</select>' +
                    '<input id="swal_rt_route" class="swal2-input" value="' + (tpl.route_string || '').replace(/"/g, '&quot;') + '">' +
                    '<input id="swal_rt_priority" class="swal2-input" type="number" value="' + (tpl.priority || 50) + '">' +
                    '<input id="swal_rt_altitude" class="swal2-input" value="' + (tpl.altitude_range || '') + '" placeholder="' + t('ctp.planning.altitudeRange') + '">',
                focusConfirm: false,
                showCancelButton: true,
                preConfirm: function() {
                    return {
                        template_id: tpl.template_id,
                        template_name: $('#swal_rt_name').val(),
                        segment: $('#swal_rt_segment').val(),
                        route_string: $('#swal_rt_route').val(),
                        priority: parseInt($('#swal_rt_priority').val()) || 50,
                        altitude_range: $('#swal_rt_altitude').val() || null
                    };
                }
            }).then(function(result) {
                if (result.isConfirmed && result.value) {
                    $.ajax({
                        url: API.routes.templates,
                        method: 'PUT',
                        contentType: 'application/json',
                        data: JSON.stringify(result.value),
                        success: function(resp) {
                            if (resp.status === 'ok') {
                                Swal.fire({ icon: 'success', title: t('common.saved'), timer: 1500, showConfirmButton: false });
                                self.refresh();
                            } else {
                                Swal.fire({ icon: 'error', title: resp.message || t('common.error') });
                            }
                        }
                    });
                }
            });
        },

        deleteTemplate: function(templateId) {
            var self = this;
            Swal.fire({
                title: t('ctp.routes.deleteTemplate'),
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545'
            }).then(function(result) {
                if (result.isConfirmed) {
                    $.ajax({
                        url: API.routes.templates,
                        method: 'DELETE',
                        contentType: 'application/json',
                        data: JSON.stringify({ template_id: templateId }),
                        success: function(resp) {
                            if (resp.status === 'ok') {
                                self.refresh();
                            }
                        }
                    });
                }
            });
        }
    };

    // ========================================================================
    // Session Stats Panel
    // ========================================================================

    var StatsPanel = {
        init: function() {
            $(document).on('shown.bs.tab', 'a[href="#ctp_stats_panel"]', function() { StatsPanel.refresh(); });
        },

        refresh: function() {
            if (!state.currentSession) return;
            var $panel = $('#ctp_stats_content');
            $panel.html('<div class="text-center text-muted py-3"><i class="fas fa-spinner fa-spin"></i> ' + t('common.loading') + '</div>');

            $.ajax({
                url: API.stats,
                data: { session_id: state.currentSession.session_id },
                success: function(resp) {
                    if (resp.status === 'ok' && resp.data) {
                        StatsPanel.render(resp.data);
                    } else {
                        $panel.html('<div class="text-center text-muted py-3">' + t('ctp.stats.noData') + '</div>');
                    }
                },
                error: function() {
                    $panel.html('<div class="text-center text-danger py-3">' + t('ctp.stats.loadError') + '</div>');
                }
            });
        },

        render: function(d) {
            var $panel = $('#ctp_stats_content');
            var html = '<div class="row px-3 py-2" style="font-size:0.78rem;">';

            // Column 1: Flight counts
            html += '<div class="col-md-4">' +
                '<h6 class="text-uppercase text-muted" style="font-size:0.7rem;letter-spacing:0.05em;">' + t('ctp.stats.flightCounts') + '</h6>' +
                this.statRow(t('ctp.stats.total'), d.total_flights || 0) +
                this.statRow(t('ctp.stats.active'), d.active_flights || 0) +
                this.statRow(t('ctp.stats.excluded'), d.excluded_flights || 0, 'text-danger') +
                this.statRow(t('ctp.stats.event'), d.event_flights || 0) +
                this.statRow(t('ctp.stats.slotted'), d.slotted_flights || 0, 'text-success') +
                this.statRow(t('ctp.stats.modified'), d.modified_flights || 0, 'text-primary') +
                this.statRow(t('ctp.stats.validated'), d.validated_flights || 0, 'text-success') +
            '</div>';

            // Column 2: Delay & compliance
            html += '<div class="col-md-4">' +
                '<h6 class="text-uppercase text-muted" style="font-size:0.7rem;letter-spacing:0.05em;">' + t('ctp.stats.delays') + '</h6>' +
                this.statRow(t('ctp.stats.avgDelay'), d.avg_delay_min != null ? d.avg_delay_min + ' min' : '-') +
                this.statRow(t('ctp.stats.maxDelay'), d.max_delay_min != null ? d.max_delay_min + ' min' : '-') +
                this.statRow(t('ctp.stats.minDelay'), d.min_delay_min != null ? d.min_delay_min + ' min' : '-') +
                '<h6 class="text-uppercase text-muted mt-2" style="font-size:0.7rem;letter-spacing:0.05em;">' + t('ctp.stats.compliance') + '</h6>' +
                this.statRow(t('ctp.stats.onTime'), d.compliant_flights || 0, 'text-success') +
                this.statRow(t('ctp.stats.early'), d.early_flights || 0, 'text-warning') +
                this.statRow(t('ctp.stats.late'), d.late_flights || 0, 'text-danger') +
                this.statRow(t('ctp.stats.noShow'), d.no_show_flights || 0, 'text-muted') +
            '</div>';

            // Column 3: Segments & top entries
            html += '<div class="col-md-4">' +
                '<h6 class="text-uppercase text-muted" style="font-size:0.7rem;letter-spacing:0.05em;">' + t('ctp.stats.segments') + '</h6>' +
                this.statRow('NA ' + t('ctp.stats.modified'), d.na_modified || 0) +
                this.statRow('OCA ' + t('ctp.stats.modified'), d.oceanic_modified || 0) +
                this.statRow('EU ' + t('ctp.stats.modified'), d.eu_modified || 0) +
                '<h6 class="text-uppercase text-muted mt-2" style="font-size:0.7rem;letter-spacing:0.05em;">' + t('ctp.stats.topEntries') + '</h6>' +
                this.statRow(t('ctp.stats.topFir'), d.top_entry_fir || '-') +
                this.statRow(t('ctp.stats.topFix'), d.top_entry_fix || '-') +
                (d.avg_compliance_delta_min != null ? this.statRow(t('ctp.stats.avgDelta'), d.avg_compliance_delta_min + ' min') : '') +
            '</div>';

            html += '</div>';
            $panel.html(html);
        },

        statRow: function(label, value, cls) {
            return '<div class="d-flex justify-content-between py-1" style="border-bottom:1px solid #f0f0f0;">' +
                '<span class="text-muted">' + label + '</span>' +
                '<span class="font-weight-bold ' + (cls || '') + '">' + value + '</span>' +
            '</div>';
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
        TrackConstraintManager.init();
        RouteTemplateManager.init();
        StatsPanel.init();
        ResizeHandle.init();
        FlightTable.updateSortUI();
    });

})();
