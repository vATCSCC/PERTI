/**
 * CDM Pilot Dashboard Module
 *
 * Real-time collaborative decision making status display.
 * Fetches data from /api/data/cdm/status.php and renders:
 *   - Summary counters
 *   - Pilot readiness table
 *   - EDCT compliance table
 *   - Airport departure queue grid
 *   - CDM message delivery table
 *
 * @version 1.0.0
 */

(function () {
    'use strict';

    // =========================================================================
    // State
    // =========================================================================

    var state = {
        initialized: false,
        data: null,
        airportFilter: null,
        refreshTimer: null,
        refreshInterval: 15000, // 15 seconds
        loading: false
    };

    // =========================================================================
    // Helpers
    // =========================================================================

    /** Format a UTC ISO string to HH:MM display */
    function fmtTime(iso) {
        if (!iso) return '-';
        var d = new Date(iso);
        if (isNaN(d.getTime())) return '-';
        var h = ('0' + d.getUTCHours()).slice(-2);
        var m = ('0' + d.getUTCMinutes()).slice(-2);
        return h + ':' + m + 'z';
    }

    /** Format a UTC ISO string to HH:MM:SS display */
    function fmtTimeFull(iso) {
        if (!iso) return '-';
        var d = new Date(iso);
        if (isNaN(d.getTime())) return '-';
        var h = ('0' + d.getUTCHours()).slice(-2);
        var m = ('0' + d.getUTCMinutes()).slice(-2);
        var s = ('0' + d.getUTCSeconds()).slice(-2);
        return h + ':' + m + ':' + s + 'z';
    }

    /** Return i18n-translated text or fallback */
    function t(key, params) {
        if (typeof PERTII18n !== 'undefined' && PERTII18n.t) {
            return PERTII18n.t(key, params);
        }
        return key;
    }

    /** HTML-escape a string */
    function esc(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    /** Get CSS class for readiness state */
    function stateClass(state) {
        var map = {
            'READY': 'cdm-state-ready',
            'BOARDING': 'cdm-state-boarding',
            'PLANNING': 'cdm-state-planning',
            'TAXIING': 'cdm-state-taxiing',
            'CANCELLED': 'cdm-state-cancelled'
        };
        return map[state] || 'cdm-state-planning';
    }

    /** Get CSS class for compliance status */
    function complianceClass(status) {
        var normalized = (status || '').toLowerCase().replace(/ /g, '_');
        return 'cdm-compliance-' + normalized;
    }

    /** Get CSS class for risk level */
    function riskClass(level) {
        return 'cdm-risk-' + (level || 'low').toLowerCase();
    }

    /** Get CSS class for message delivery status */
    function msgStatusClass(status) {
        return 'cdm-msg-' + (status || 'pending').toLowerCase();
    }

    /** Format readiness state label via i18n */
    function stateLabel(state) {
        var key = 'cdm.readiness.states.' + (state || 'planning').toLowerCase();
        return t(key);
    }

    /** Format compliance status label via i18n */
    function complianceLabel(status) {
        var key = 'cdm.compliance.statuses.' + (status || 'pending').toLowerCase().replace(/ /g, '');
        // Handle NON_COMPLIANT -> nonCompliant
        var normalized = (status || 'pending').toLowerCase().replace(/_/g, '');
        key = 'cdm.compliance.statuses.' + normalized;
        return t(key);
    }

    /** Format delta minutes with sign */
    function fmtDelta(delta) {
        if (delta === null || delta === undefined) return '-';
        var sign = delta >= 0 ? '+' : '';
        return sign + delta.toFixed(1) + ' min';
    }

    // =========================================================================
    // Data Fetching
    // =========================================================================

    function fetchData() {
        if (state.loading) return;
        state.loading = true;

        var url = 'api/data/cdm/status.php';
        if (state.airportFilter) {
            url += '?airport=' + encodeURIComponent(state.airportFilter);
        }

        $.ajax({
            url: url,
            type: 'GET',
            dataType: 'json',
            timeout: 10000
        })
        .done(function (response) {
            if (response && response.success) {
                state.data = response.data;
                renderAll();
                updateTimestamp(response.timestamp);
            } else {
                var errMsg = (response && response.error) ? response.error : 'Unknown error';
                console.error('[CDM] API error:', errMsg);
            }
        })
        .fail(function (xhr, textStatus) {
            console.error('[CDM] Fetch failed:', textStatus);
            if (typeof PERTIDialog !== 'undefined') {
                PERTIDialog.toast(t('cdm.error.fetchFailed'), 'error');
            }
        })
        .always(function () {
            state.loading = false;
        });
    }

    // =========================================================================
    // Rendering
    // =========================================================================

    function renderAll() {
        if (!state.data) return;
        renderSummary(state.data.summary);
        renderReadiness(state.data.readiness);
        renderCompliance(state.data.compliance);
        renderAirportStatus(state.data.airport_status);
        renderMessages(state.data.messages);
    }

    /** Render summary counters */
    function renderSummary(summary) {
        if (!summary) return;
        $('#summary-readiness').text(summary.total_readiness || 0);
        $('#summary-compliant').text(summary.compliant_count || 0);
        $('#summary-at-risk').text(summary.at_risk_count || 0);
        $('#summary-non-compliant').text(summary.non_compliant_count || 0);
        $('#summary-messages').text(summary.pending_messages || 0);
        $('#summary-airports').text(summary.airports_controlled || 0);
    }

    /** Render pilot readiness table */
    function renderReadiness(rows) {
        var $body = $('#readiness-body');
        $body.empty();
        $('#readiness-count').text(rows ? rows.length : 0);

        if (!rows || rows.length === 0) {
            $body.append(
                '<tr><td colspan="6" class="cdm-empty">' + esc(t('cdm.empty.readiness')) + '</td></tr>'
            );
            return;
        }

        for (var i = 0; i < rows.length; i++) {
            var r = rows[i];
            var tobtDisplay = r.reported_tobt ? fmtTime(r.reported_tobt) : (r.computed_tobt ? fmtTime(r.computed_tobt) : '-');

            $body.append(
                '<tr>' +
                '<td><strong>' + esc(r.callsign) + '</strong></td>' +
                '<td>' + esc(r.dep_airport) + '</td>' +
                '<td>' + esc(r.arr_airport) + '</td>' +
                '<td><span class="cdm-state-badge ' + stateClass(r.readiness_state) + '">' +
                    esc(stateLabel(r.readiness_state)) + '</span></td>' +
                '<td>' + esc(tobtDisplay) + '</td>' +
                '<td>' + esc(r.source || '-') + '</td>' +
                '</tr>'
            );
        }
    }

    /** Render compliance table */
    function renderCompliance(rows) {
        var $body = $('#compliance-body');
        $body.empty();
        $('#compliance-count').text(rows ? rows.length : 0);

        if (!rows || rows.length === 0) {
            $body.append(
                '<tr><td colspan="6" class="cdm-empty">' + esc(t('cdm.empty.compliance')) + '</td></tr>'
            );
            return;
        }

        for (var i = 0; i < rows.length; i++) {
            var c = rows[i];

            $body.append(
                '<tr>' +
                '<td><strong>' + esc(c.callsign) + '</strong></td>' +
                '<td>' + esc(c.compliance_type) + '</td>' +
                '<td><span class="cdm-state-badge ' + complianceClass(c.compliance_status) + '">' +
                    esc(complianceLabel(c.compliance_status)) + '</span></td>' +
                '<td><span class="' + riskClass(c.risk_level) + '">' +
                    esc(c.risk_level || '-') + '</span></td>' +
                '<td>' + esc(fmtDelta(c.delta_minutes)) + '</td>' +
                '<td>' + fmtTime(c.evaluated_at) + '</td>' +
                '</tr>'
            );
        }
    }

    /** Render airport status grid */
    function renderAirportStatus(airports) {
        var $grid = $('#airport-status-grid');
        $grid.empty();
        $('#airport-count').text(airports ? airports.length : 0);

        if (!airports || airports.length === 0) {
            $grid.html('<div class="cdm-empty">' + esc(t('cdm.empty.airports')) + '</div>');
            return;
        }

        for (var i = 0; i < airports.length; i++) {
            var a = airports[i];

            var controlled = a.is_controlled
                ? ' <span class="cdm-airport-controlled">' + esc(t('cdm.airport.controlled')) + '</span>'
                : '';

            var rateInfo = '';
            if (a.aar !== null || a.adr !== null) {
                var parts = [];
                if (a.aar !== null) parts.push('AAR ' + a.aar);
                if (a.adr !== null) parts.push('ADR ' + a.adr);
                if (a.weather_category) parts.push(a.weather_category);
                rateInfo = '<div class="cdm-airport-rates">' + esc(parts.join(' | ')) + '</div>';
            }

            var taxiInfo = '';
            if (a.avg_taxi_time_sec !== null) {
                var avgMin = Math.round(a.avg_taxi_time_sec / 60);
                taxiInfo = ' | ' + t('cdm.airport.avgTaxi', { minutes: avgMin });
            }

            $grid.append(
                '<div class="cdm-airport-card">' +
                '<div class="d-flex justify-content-between align-items-center">' +
                    '<span class="cdm-airport-code">' + esc(a.airport_icao) + '</span>' +
                    controlled +
                '</div>' +
                '<div class="cdm-airport-counts">' +
                    '<span class="cdm-count-item"><span class="cdm-count-dot" style="background:#28a745"></span> ' +
                        t('cdm.airport.ready') + ': ' + a.ready_count + '</span>' +
                    '<span class="cdm-count-item"><span class="cdm-count-dot" style="background:#ffc107"></span> ' +
                        t('cdm.airport.boarding') + ': ' + a.boarding_count + '</span>' +
                    '<span class="cdm-count-item"><span class="cdm-count-dot" style="background:#17a2b8"></span> ' +
                        t('cdm.airport.taxiing') + ': ' + a.taxiing_count + '</span>' +
                    '<span class="cdm-count-item"><span class="cdm-count-dot" style="background:#dc3545"></span> ' +
                        t('cdm.airport.held') + ': ' + a.gate_held_count + '</span>' +
                    '<span class="cdm-count-item"><span class="cdm-count-dot" style="background:#6c757d"></span> ' +
                        t('cdm.airport.planning') + ': ' + a.planning_count + '</span>' +
                '</div>' +
                rateInfo +
                '</div>'
            );
        }
    }

    /** Render messages table */
    function renderMessages(rows) {
        var $body = $('#messages-body');
        $body.empty();
        $('#messages-count').text(rows ? rows.length : 0);

        if (!rows || rows.length === 0) {
            $body.append(
                '<tr><td colspan="6" class="cdm-empty">' + esc(t('cdm.empty.messages')) + '</td></tr>'
            );
            return;
        }

        for (var i = 0; i < rows.length; i++) {
            var m = rows[i];

            $body.append(
                '<tr>' +
                '<td><strong>' + esc(m.callsign) + '</strong></td>' +
                '<td>' + esc(m.message_type) + '</td>' +
                '<td>' + esc(m.delivery_channel || '-') + '</td>' +
                '<td><span class="cdm-state-badge ' + msgStatusClass(m.delivery_status) + '">' +
                    esc(m.delivery_status) + '</span></td>' +
                '<td>' + esc(m.ack_type || '-') + '</td>' +
                '<td>' + fmtTime(m.created_at) + '</td>' +
                '</tr>'
            );
        }
    }

    /** Update the "last updated" timestamp display */
    function updateTimestamp(isoStr) {
        var $el = $('#cdm-last-update');
        if (isoStr) {
            $el.text(t('cdm.lastUpdate') + ' ' + fmtTimeFull(isoStr));
        } else {
            $el.text('');
        }
    }

    // =========================================================================
    // Auto-Refresh
    // =========================================================================

    function startRefresh() {
        stopRefresh();
        state.refreshTimer = setInterval(fetchData, state.refreshInterval);
    }

    function stopRefresh() {
        if (state.refreshTimer) {
            clearInterval(state.refreshTimer);
            state.refreshTimer = null;
        }
    }

    // =========================================================================
    // Event Handlers
    // =========================================================================

    function bindEvents() {
        // Manual refresh
        $('#cdm-refresh-btn').on('click', function () {
            fetchData();
        });

        // Airport filter
        $('#cdm-filter-apply').on('click', applyFilter);
        $('#cdm-airport-filter').on('keypress', function (e) {
            if (e.which === 13) applyFilter();
        });
        $('#cdm-filter-clear').on('click', function () {
            $('#cdm-airport-filter').val('');
            state.airportFilter = null;
            fetchData();
        });
    }

    function applyFilter() {
        var val = $('#cdm-airport-filter').val().trim().toUpperCase();
        if (val && /^[A-Z]{3,4}$/.test(val)) {
            state.airportFilter = val;
        } else {
            state.airportFilter = null;
        }
        fetchData();
    }

    // =========================================================================
    // Initialization
    // =========================================================================

    function init() {
        if (state.initialized) return;
        state.initialized = true;

        bindEvents();
        fetchData();
        startRefresh();
    }

    $(document).ready(init);

})();
