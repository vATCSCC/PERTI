/**
 * TMR Report Module
 * Manages the Traffic Management Review report workflow:
 * - Load/save report data via API
 * - Auto-save with debounce
 * - Y/N/NA toggle handling
 * - Trigger card UI with i18n
 * - TMI database loading, manual entry, bulk paste, server-side parsing
 * - TMI bulk controls (select/deselect, filter, search)
 * - Per-TMI C/E/T assessment with batch assess
 * - METAR auto-import for weather section
 * - Staffing auto-import from PERTI plan
 * - Ops plan goals & initiatives import
 * - TMI compliance integration
 * - DemandChartCore integration for airport demand charts
 * - Discord export
 */
(function() {
    'use strict';

    var planId = window.planData ? window.planData.id : null;
    var reportData = {};
    var isNew = true;
    var saveTimer = null;
    var demandCharts = {};
    var tmiList = [];        // All TMIs (DB + manual)
    var tmiSelected = {};    // TMI id/index → boolean (included in report)
    var tmiFilter = { categories: {ntml:true, program:true, advisory:true, reroute:true, manual:true}, search: '' };
    var dirty = false;
    var staffingData = null;
    var goalsData = null;
    var demandSnapshots = null;  // Saved demand chart data { airport: snapshot }

    // Trigger definitions with icons
    var TRIGGERS = [
        { key: 'holding_15', icon: 'fa-plane-circle-exclamation', i18n: 'tmr.trigger.holding15', desc: 'FAA Order 7210.632' },
        { key: 'delays_30', icon: 'fa-clock', i18n: 'tmr.trigger.delays30', desc: 'Departure delay threshold' },
        { key: 'no_notice_holding', icon: 'fa-triangle-exclamation', i18n: 'tmr.trigger.noNoticeHolding', desc: 'Unexpected airborne holding' },
        { key: 'reroutes', icon: 'fa-route', i18n: 'tmr.trigger.reroutes', desc: 'Playbook or CDR activation' },
        { key: 'ground_stop', icon: 'fa-hand', i18n: 'tmr.trigger.groundStop', desc: 'Airport ground stop issued' },
        { key: 'gdp', icon: 'fa-hourglass-half', i18n: 'tmr.trigger.gdp', desc: 'Ground Delay Program issued' },
        { key: 'equipment', icon: 'fa-wrench', i18n: 'tmr.trigger.equipment', desc: 'Equipment outage or degradation' },
        { key: 'dcc_initiated', icon: 'fa-tower-broadcast', i18n: 'tmr.trigger.dccInitiated', desc: 'DCC management review request' },
        { key: 'other', icon: 'fa-circle-question', i18n: 'tmr.trigger.other', desc: 'Specify in text field below' },
    ];

    // ========================================================================
    // Initialization
    // ========================================================================

    $(document).ready(function() {
        if (!planId) return;

        buildTriggerCards();
        loadReport();
        bindFieldHandlers();
        bindTriggerHandlers();
        bindYNToggleHandlers();
        bindTMIHandlers();
        bindExportHandler();
        bindWeatherHandlers();
        bindStaffingHandlers();
        bindOpsPlanHandlers();
        checkComplianceAvailable();
    });

    // ========================================================================
    // Report Load / Save
    // ========================================================================

    function loadReport() {
        updateStatus('loading', PERTII18n.t('tmr.status.loading'));

        $.getJSON('api/data/review/tmr_report.php', { p_id: planId })
            .done(function(resp) {
                if (!resp.success) {
                    updateStatus('error', PERTII18n.t('tmr.status.loadFailed'));
                    return;
                }

                reportData = resp.report || {};
                isNew = resp.is_new;

                populateForm(reportData);
                initDemandCharts();
                autoImportCompliance();

                if (isNew) {
                    updateStatus('new', PERTII18n.t('tmr.status.newReport'));
                } else {
                    updateStatus('loaded', reportData.status === 'draft' ? PERTII18n.t('tmr.status.draft') : PERTII18n.t('tmr.status.saved'));
                }
            })
            .fail(function() {
                updateStatus('error', PERTII18n.t('tmr.status.loadFailed'));
            });
    }

    function saveReport() {
        if (!window.planData.perm) return $.Deferred().reject().promise();

        var data = gatherFormData();
        data.p_id = planId;

        showSaveIndicator('saving');

        return $.ajax({
            url: 'api/data/review/tmr_report.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(data),
        })
        .done(function(resp) {
            if (resp.success) {
                isNew = false;
                dirty = false;
                showSaveIndicator('saved');
                updateStatus('saved', PERTII18n.t('tmr.status.saved'));
            } else {
                showSaveIndicator('error');
            }
        })
        .fail(function() {
            showSaveIndicator('error');
        });
    }

    function scheduleSave() {
        dirty = true;
        if (saveTimer) clearTimeout(saveTimer);
        saveTimer = setTimeout(saveReport, 1500);
    }

    function immediateFieldSave() {
        dirty = true;
        if (saveTimer) clearTimeout(saveTimer);
        saveReport();
    }

    // ========================================================================
    // Trigger Cards
    // ========================================================================

    function buildTriggerCards() {
        var grid = $('#tmr_trigger_grid');
        grid.empty();

        TRIGGERS.forEach(function(t) {
            var label = PERTII18n.t(t.i18n);
            var card = $(
                '<label class="trigger-card" data-trigger="' + t.key + '">' +
                    '<input type="checkbox" class="tmr-trigger" value="' + t.key + '">' +
                    '<div class="trigger-icon"><i class="fas ' + t.icon + '"></i></div>' +
                    '<div class="trigger-text">' +
                        '<div class="trigger-label">' + escapeHtml(label) + '</div>' +
                        '<div class="trigger-desc">' + escapeHtml(t.desc) + '</div>' +
                    '</div>' +
                '</label>'
            );
            grid.append(card);
        });

        // Set placeholder for "other" text field
        $('#tmr_trigger_other_text').attr('placeholder', PERTII18n.t('tmr.trigger.otherPlaceholder'));

        // Card click toggles checkbox + visual state
        grid.on('click', '.trigger-card', function(e) {
            // Prevent native <label> toggle (would double-toggle with our manual flip)
            e.preventDefault();

            var cb = $(this).find('input[type="checkbox"]');
            cb.prop('checked', !cb.prop('checked')).trigger('change');
        });
    }

    // ========================================================================
    // Form Population
    // ========================================================================

    function populateForm(data) {
        // Text fields and textareas
        $('.tmr-field').each(function() {
            var field = $(this).data('field');
            if (!field || data[field] === undefined) return;

            var val = data[field];
            if (val === null) val = '';

            $(this).val(val);
        });

        // Trigger checkboxes + card visual state
        var triggers = data.tmr_triggers || [];
        if (typeof triggers === 'string') {
            try { triggers = JSON.parse(triggers); } catch(e) { triggers = []; }
        }
        $('.tmr-trigger').each(function() {
            var checked = triggers.indexOf($(this).val()) !== -1;
            $(this).prop('checked', checked);
            $(this).closest('.trigger-card').toggleClass('selected', checked);
        });
        // Show/hide "other" text field
        var hasOther = triggers.indexOf('other') !== -1;
        $('#tmr_trigger_other_wrap').toggle(hasOther);

        // Y/N/NA toggles
        $('.yn-toggle').each(function() {
            var field = $(this).data('field');
            if (!field || data[field] === undefined) return;

            var val = data[field];
            $(this).find('.btn').removeClass('active');

            if (val === true || val === 1 || val === '1') {
                $(this).find('[data-value="1"]').addClass('active');
            } else if (val === false || val === 0 || val === '0') {
                $(this).find('[data-value="0"]').addClass('active');
            } else {
                $(this).find('[data-value=""]').addClass('active');
            }
        });

        // TMI list
        if (data.tmi_list) {
            var list = data.tmi_list;
            if (typeof list === 'string') {
                try { list = JSON.parse(list); } catch(e) { list = []; }
            }
            if (Array.isArray(list) && list.length > 0) {
                tmiList = list.map(function(item, i) {
                    item._source = item._source || 'saved';
                    item._key = 'saved_' + i;
                    return item;
                });
                tmiList.forEach(function(t) { tmiSelected[t._key] = true; });
                renderTMITable();
            }
        }

        // Staffing assessment (restore if saved)
        if (data.staffing_assessment) {
            staffingData = data.staffing_assessment;
        }

        // Goals assessment (restore if saved)
        if (data.goals_assessment) {
            goalsData = data.goals_assessment;
        }

        // Demand snapshots (restore saved chart data for historical recall)
        if (data.demand_snapshots) {
            demandSnapshots = data.demand_snapshots;
        }
    }

    function gatherFormData() {
        var data = {};

        // Text fields
        $('.tmr-field').each(function() {
            var field = $(this).data('field');
            if (!field) return;
            var val = $(this).val();
            data[field] = val === '' ? null : val;
        });

        // Triggers
        var triggers = [];
        $('.tmr-trigger:checked').each(function() {
            triggers.push($(this).val());
        });
        data.tmr_triggers = triggers;

        // Y/N toggles
        $('.yn-toggle').each(function() {
            var field = $(this).data('field');
            if (!field) return;
            var active = $(this).find('.btn.active');
            var val = active.length ? active.data('value') : null;
            if (val === '' || val === undefined) {
                data[field] = null;
            } else {
                data[field] = val;
            }
        });

        // TMI list (only selected items)
        var selectedTmis = [];
        tmiList.forEach(function(t) {
            if (tmiSelected[t._key]) {
                var clean = $.extend({}, t);
                delete clean._source;
                delete clean._key;
                delete clean._hidden;
                selectedTmis.push(clean);
            }
        });
        data.tmi_list = selectedTmis;
        data.tmi_source = tmiList.some(function(t) { return t._source === 'db'; }) ? 'database' : 'manual';

        // Staffing assessment
        if (staffingData) {
            data.staffing_assessment = gatherStaffingAssessment();
        }

        // Goals assessment
        if (goalsData) {
            data.goals_assessment = gatherGoalsAssessment();
        }

        // Demand snapshots — capture current chart data for historical recall
        data.demand_snapshots = gatherDemandSnapshots();

        return data;
    }

    // ========================================================================
    // Event Handlers
    // ========================================================================

    function bindFieldHandlers() {
        $(document).on('input', '.tmr-field', function() {
            if (this.tagName === 'SELECT') return;
            scheduleSave();
        });

        $(document).on('change', '.tmr-field select, select.tmr-field', function() {
            immediateFieldSave();
        });
    }

    function bindTriggerHandlers() {
        $(document).on('change', '.tmr-trigger', function() {
            // Update card visual state
            $(this).closest('.trigger-card').toggleClass('selected', $(this).prop('checked'));

            // Show/hide "other" text field
            var otherChecked = $('.tmr-trigger[value="other"]').prop('checked');
            $('#tmr_trigger_other_wrap').toggle(otherChecked);

            immediateFieldSave();
        });
    }

    function bindYNToggleHandlers() {
        $(document).on('click', '.yn-toggle .btn', function(e) {
            e.preventDefault();
            var group = $(this).closest('.yn-toggle');
            group.find('.btn').removeClass('active');
            $(this).addClass('active');
            immediateFieldSave();
        });
    }

    // ========================================================================
    // TMI Handling
    // ========================================================================

    function bindTMIHandlers() {
        $('#tmr_load_db_tmis').on('click', loadTMIsFromDB);
        $('#tmr_bulk_paste_toggle').on('click', function() { $('#tmr_bulk_paste_form').toggle(); });
        $('#tmr_parse_bulk_ntml').on('click', parseBulkNTML);
        $('#tmr_add_manual_tmi').on('click', function() { $('#tmr_manual_tmi_form').toggle(); });
        $('#tmr_save_manual_tmi').on('click', addManualTMI);

        // Select all checkbox (in table header)
        $('#tmr_tmi_select_all').on('change', function() {
            var checked = $(this).prop('checked');
            tmiList.forEach(function(t) {
                if (!t._hidden) tmiSelected[t._key] = checked;
            });
            renderTMITable();
            immediateFieldSave();
        });

        // Individual TMI select
        $(document).on('change', '.tmi-select-row', function() {
            tmiSelected[$(this).data('key')] = $(this).prop('checked');
            immediateFieldSave();
        });

        // Toolbar: Select All / Deselect All buttons
        $('#tmr_tmi_select_all_btn').on('click', function() {
            tmiList.forEach(function(t) { if (!t._hidden) tmiSelected[t._key] = true; });
            renderTMITable();
            immediateFieldSave();
        });
        $('#tmr_tmi_deselect_all_btn').on('click', function() {
            tmiList.forEach(function(t) { if (!t._hidden) tmiSelected[t._key] = false; });
            renderTMITable();
            immediateFieldSave();
        });

        // Category filter toggles
        $(document).on('click', '.tmi-cat-btn', function() {
            $(this).toggleClass('active');
            tmiFilter.categories[$(this).data('cat')] = $(this).hasClass('active');
            applyTMIFilter();
        });

        // Search
        $('#tmr_tmi_search').on('input', function() {
            tmiFilter.search = $(this).val().toLowerCase();
            applyTMIFilter();
        });

        // Batch assess
        $('#tmr_batch_assess_btn').on('click', showBatchAssessModal);

        // Remove TMI button
        $(document).on('click', '.tmi-remove-btn', function() {
            var key = $(this).data('key');
            tmiList = tmiList.filter(function(t) { return t._key !== key; });
            delete tmiSelected[key];
            renderTMITable();
            immediateFieldSave();
        });

        // Per-TMI C/E/T pill click
        $(document).on('click', '.cet-pill', function() {
            var key = $(this).data('key');
            var field = $(this).data('field');
            var tmi = tmiList.find(function(t) { return t._key === key; });
            if (!tmi) return;

            // Cycle: Y → N → N/A → Y
            var current = tmi[field] || 'N/A';
            var next = current === 'Y' ? 'N' : (current === 'N' ? 'N/A' : 'Y');
            tmi[field] = next;
            renderTMITable();
            immediateFieldSave();
        });
    }

    function applyTMIFilter() {
        var search = tmiFilter.search;
        tmiList.forEach(function(t) {
            var catMatch = tmiFilter.categories[t.category] !== false;
            var searchMatch = !search || (
                (t.type || '').toLowerCase().indexOf(search) !== -1 ||
                (t.element || '').toLowerCase().indexOf(search) !== -1 ||
                (t.detail || '').toLowerCase().indexOf(search) !== -1 ||
                (t.facility || '').toLowerCase().indexOf(search) !== -1
            );
            t._hidden = !catMatch || !searchMatch;
        });
        renderTMITable();
    }

    function loadTMIsFromDB() {
        var btn = $('#tmr_load_db_tmis');
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> ' + PERTII18n.t('tmr.status.loading'));
        $('#tmr_tmi_status').text(PERTII18n.t('tmr.tmi.queryingDb'));

        $.getJSON('api/data/review/tmr_tmis.php', { p_id: planId })
            .done(function(resp) {
                btn.prop('disabled', false).html('<i class="fas fa-database"></i> ' + PERTII18n.t('tmr.tmi.loadFromDb'));

                if (!resp.success) {
                    $('#tmr_tmi_status').text(PERTII18n.t('tmr.tmi.errorPrefix', { message: resp.error || PERTII18n.t('common.unknown') }));
                    return;
                }

                if (resp.warning) {
                    $('#tmr_tmi_status').text(resp.warning);
                    return;
                }

                var dbTmis = (resp.tmis || []).map(function(t, i) {
                    t._source = 'db';
                    t._key = 'db_' + (t.id || i) + '_' + t.category;
                    return t;
                });

                // Merge: keep existing manual entries, replace DB entries
                var manualTmis = tmiList.filter(function(t) { return t._source === 'manual' || t._source === 'saved'; });
                tmiList = dbTmis.concat(manualTmis);

                dbTmis.forEach(function(t) { tmiSelected[t._key] = true; });

                renderTMITable();
                $('#tmr_tmi_status').text(PERTII18n.t('tmr.tmi.foundCount', { count: resp.count }));
                immediateFieldSave();
            })
            .fail(function() {
                btn.prop('disabled', false).html('<i class="fas fa-database"></i> ' + PERTII18n.t('tmr.tmi.loadFromDb'));
                $('#tmr_tmi_status').text(PERTII18n.t('tmr.tmi.loadFailed'));
            });
    }

    function addManualTMI() {
        var type = $('#manual_tmi_type').val();
        var element = $('#manual_tmi_element').val().trim();
        var detail = $('#manual_tmi_detail').val().trim();
        var start = $('#manual_tmi_start').val().trim();
        var end = $('#manual_tmi_end').val().trim();

        if (!element && !detail) {
            Swal.fire({ icon: 'warning', title: PERTII18n.t('tmr.tmi.missingInfo'), text: PERTII18n.t('tmr.tmi.missingInfoText'), timer: 2000, showConfirmButton: false });
            return;
        }

        var key = 'manual_' + Date.now();
        var tmi = {
            _source: 'manual', _key: key, category: 'manual',
            type: type, element: element, detail: detail,
            start_utc: start || null, end_utc: end || null,
            status: PERTII18n.t('tmr.tmi.source.manual'),
        };

        tmiList.push(tmi);
        tmiSelected[key] = true;
        renderTMITable();

        $('#manual_tmi_element, #manual_tmi_detail, #manual_tmi_start, #manual_tmi_end').val('');
        $('#manual_tmi_type').val('MIT');
        immediateFieldSave();
    }

    function parseBulkNTML() {
        var raw = $('#tmr_bulk_ntml_input').val().trim();
        if (!raw) {
            Swal.fire({ icon: 'warning', title: PERTII18n.t('tmr.tmi.nothingToParse'), text: PERTII18n.t('tmr.tmi.nothingToParseText'), timer: 2000, showConfirmButton: false });
            return;
        }

        // Try server-side parsing first
        $.ajax({
            url: 'api/data/review/tmr_parse_ntml.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ text: raw }),
        })
        .done(function(resp) {
            if (resp.success && resp.tmis && resp.tmis.length > 0) {
                var added = 0;
                resp.tmis.forEach(function(parsed) {
                    var key = 'parsed_' + Date.now() + '_' + added;
                    var tmi = Object.assign({}, parsed, {
                        _source: 'parsed', _key: key,
                        category: parsed.category || 'ntml',
                        status: PERTII18n.t('tmr.tmi.source.manual'),
                    });
                    tmiList.push(tmi);
                    tmiSelected[key] = true;
                    added++;
                });
                renderTMITable();
                $('#tmr_bulk_parse_status').text(PERTII18n.tp('tmr.tmi.addedEntries', added));
                $('#tmr_bulk_ntml_input').val('');
                immediateFieldSave();
            } else {
                // Fallback to client-side parsing
                parseNTMLClientSide(raw);
            }
        })
        .fail(function() {
            // Fallback to client-side parsing
            parseNTMLClientSide(raw);
        });
    }

    function parseNTMLClientSide(raw) {
        var lines = raw.split('\n').filter(function(l) { return l.trim().length > 0; });
        var added = 0;

        lines.forEach(function(line) {
            line = line.trim();
            if (!line) return;

            var parsed = parseNTMLLine(line);
            var key = 'manual_' + Date.now() + '_' + added;
            var tmi = {
                _source: 'manual', _key: key, category: 'manual',
                type: parsed.type, element: parsed.element, detail: parsed.detail,
                start_utc: parsed.start || null, end_utc: parsed.end || null,
                status: PERTII18n.t('tmr.tmi.source.manual'), raw: line,
            };
            tmiList.push(tmi);
            tmiSelected[key] = true;
            added++;
        });

        renderTMITable();
        $('#tmr_bulk_parse_status').text(PERTII18n.tp('tmr.tmi.addedEntries', added));
        $('#tmr_bulk_ntml_input').val('');
        immediateFieldSave();
    }

    /**
     * Parse a single NTML line into structured fields (client-side fallback).
     */
    function parseNTMLLine(line) {
        var result = { type: 'Other', element: '', detail: line, start: null, end: null };

        var timeMatch = line.match(/(\d{4})Z?\s*[-–]\s*(\d{4})Z?/i);
        if (timeMatch) {
            result.start = timeMatch[1] + 'z';
            result.end = timeMatch[2] + 'z';
        }

        var elMatch = line.match(/^(\d{2}\/\d{4}\s+)?([A-Z]{2,4})\b/);
        if (elMatch) result.element = elMatch[2];

        var upper = line.toUpperCase();
        if (/\bGS\b/.test(upper) || /GROUND\s*STOP/i.test(upper)) {
            result.type = 'GS';
        } else if (/\bGDP\b/.test(upper) || /GROUND\s*DELAY\s*PROG/i.test(upper)) {
            result.type = 'GDP';
        } else if (/\bAFP\b/.test(upper)) {
            result.type = 'AFP';
        } else if (/\d+\s*MIT\b/.test(upper) || /\bMINIT\b/.test(upper)) {
            result.type = /MINIT/i.test(upper) ? 'MINIT' : 'MIT';
        } else if (/\bRE?ROUTE/i.test(upper) || /\bvia\b/i.test(line)) {
            result.type = 'Reroute';
        } else if (/\bAPREQ\b/.test(upper)) {
            result.type = 'APREQ';
        } else if (/\bCONFIG\b/i.test(upper) || /\b(VMC|IMC|ARR:|DEP:)\b/.test(upper)) {
            result.type = 'Config';
        }

        var detail = line.replace(/^\d{2}\/\d{4}\s+/, '');
        if (result.element) {
            detail = detail.replace(new RegExp('^' + result.element + '\\s*', 'i'), '');
        }
        detail = detail.trim();
        if (detail) result.detail = detail;

        return result;
    }

    function renderTMITable() {
        var tbody = $('#tmr_tmi_tbody');
        tbody.empty();

        var visibleTmis = tmiList.filter(function(t) { return !t._hidden; });

        // Show/hide toolbar
        $('#tmr_tmi_toolbar').toggle(tmiList.length > 0);

        if (visibleTmis.length === 0) {
            var msg = tmiList.length > 0 ? 'No TMIs match the current filter.' : PERTII18n.t('tmr.tmi.emptyHint');
            tbody.html('<tr><td colspan="11" class="text-center text-muted py-3">' + escapeHtml(msg) + '</td></tr>');
            return;
        }

        visibleTmis.forEach(function(t) {
            var checked = tmiSelected[t._key] ? 'checked' : '';
            var cat = t.category || 'ntml';
            var badgeClass = cat === 'program' ? 'badge-danger' : (cat === 'reroute' ? 'badge-info' : 'tmi-badge-' + cat);
            var sourceKey = t._source === 'db' ? 'db' : (t._source === 'manual' || t._source === 'parsed' ? 'manual' : 'saved');
            var sourceLabel = PERTII18n.t('tmr.tmi.source.' + sourceKey);

            var formattedDetail = formatTMIDetail(t);
            var rawTooltip = t.raw || t.detail || formattedDetail;

            var cetC = buildCetPill(t._key, 'complied', t.complied);
            var cetE = buildCetPill(t._key, 'effective', t.effective);
            var cetT = buildCetPill(t._key, 'timely', t.timely);

            var row = '<tr>' +
                '<td><input type="checkbox" class="tmi-select-row" data-key="' + t._key + '" ' + checked + '></td>' +
                '<td><span class="badge ' + badgeClass + '">' + escapeHtml(t.type || t.category || '--') + '</span></td>' +
                '<td>' + escapeHtml(t.element || '--') + '</td>' +
                '<td class="text-truncate" style="max-width: 200px;" title="' + escapeHtml(rawTooltip) + '">' + escapeHtml(formattedDetail) + '</td>' +
                '<td class="text-nowrap">' + formatTmiTime(t.start_utc) + '</td>' +
                '<td class="text-nowrap">' + formatTmiTime(t.end_utc) + '</td>' +
                '<td>' + cetC + '</td>' +
                '<td>' + cetE + '</td>' +
                '<td>' + cetT + '</td>' +
                '<td><span class="badge badge-' + (t._source === 'db' ? 'info' : 'secondary') + '">' + sourceLabel + '</span></td>' +
                '<td><button class="btn btn-sm btn-link text-danger p-0 tmi-remove-btn" data-key="' + t._key + '" title="Remove"><i class="fas fa-times"></i></button></td>' +
                '</tr>';

            tbody.append(row);
        });
    }

    function buildCetPill(key, field, value) {
        var val = value || 'N/A';
        var cls = val === 'Y' ? 'cet-y' : (val === 'N' ? 'cet-n' : 'cet-na');
        return '<span class="cet-pill ' + cls + '" data-key="' + key + '" data-field="' + field + '" title="Click to toggle">' + val + '</span>';
    }

    function showBatchAssessModal() {
        var selectedCount = 0;
        tmiList.forEach(function(t) { if (tmiSelected[t._key] && !t._hidden) selectedCount++; });

        if (selectedCount === 0) {
            Swal.fire({ icon: 'info', text: 'No TMIs selected.', timer: 2000, showConfirmButton: false });
            return;
        }

        Swal.fire({
            title: PERTII18n.t('tmr.tmi.batchAssess'),
            html:
                '<div class="text-left">' +
                '<p class="small text-muted">' + selectedCount + ' TMI(s) selected</p>' +
                '<div class="form-group"><label class="small">' + PERTII18n.t('tmr.assessment.complied') + '</label>' +
                '<select class="form-control form-control-sm" id="swal_batch_c"><option value="">--</option><option value="Y">Y</option><option value="N">N</option><option value="N/A">N/A</option></select></div>' +
                '<div class="form-group"><label class="small">' + PERTII18n.t('tmr.assessment.effective') + '</label>' +
                '<select class="form-control form-control-sm" id="swal_batch_e"><option value="">--</option><option value="Y">Y</option><option value="N">N</option><option value="N/A">N/A</option></select></div>' +
                '<div class="form-group"><label class="small">' + PERTII18n.t('tmr.assessment.timely') + '</label>' +
                '<select class="form-control form-control-sm" id="swal_batch_t"><option value="">--</option><option value="Y">Y</option><option value="N">N</option><option value="N/A">N/A</option></select></div>' +
                '</div>',
            showCancelButton: true,
            confirmButtonText: PERTII18n.t('common.apply'),
            preConfirm: function() {
                return {
                    complied: $('#swal_batch_c').val(),
                    effective: $('#swal_batch_e').val(),
                    timely: $('#swal_batch_t').val(),
                };
            }
        }).then(function(result) {
            if (!result.isConfirmed) return;
            var vals = result.value;
            tmiList.forEach(function(t) {
                if (tmiSelected[t._key] && !t._hidden) {
                    if (vals.complied) t.complied = vals.complied;
                    if (vals.effective) t.effective = vals.effective;
                    if (vals.timely) t.timely = vals.timely;
                }
            });
            renderTMITable();
            immediateFieldSave();
        });
    }

    function formatTmiTime(dt) {
        if (!dt) return '--';
        if (dt.length <= 6) return escapeHtml(dt);
        // Parse "YYYY-MM-DD HH:MM" → "MM/DD HHMMz"
        var m = dt.match(/(\d{4})-(\d{2})-(\d{2})\s+(\d{2}):(\d{2})/);
        if (m) return m[2] + '/' + m[3] + ' ' + m[4] + m[5] + 'z';
        // Fallback: extract time only
        var tm = dt.match(/(\d{2}):(\d{2})/);
        if (tm) return tm[1] + tm[2] + 'z';
        return escapeHtml(dt);
    }

    /**
     * Format a TMI entry for display using its rich parsed fields.
     * Mirrors tmi_compliance.js:formatStandardizedTMI() logic.
     */
    function formatTMIDetail(tmi) {
        var type = (tmi.type || '').toUpperCase();
        var timeRange = '';
        if (tmi.start_utc || tmi.end_utc) {
            timeRange = (tmi.start_utc || '?') + '-' + (tmi.end_utc || '?');
        }

        if (type === 'MIT' || type === 'MINIT') {
            var dest = tmi.dest || tmi.element || '';
            var fix = tmi.fix || '';
            var val = tmi.value || '';
            var unit = type;
            var fac = '';
            if (tmi.requestor || tmi.provider) {
                fac = (tmi.requestor || '') + ':' + (tmi.provider || '');
            }
            var parts = [];
            if (dest) parts.push(dest);
            if (fix && fix !== 'ALL') parts.push('via ' + fix);
            if (val) parts.push(val + unit);
            if (fac) parts.push(fac);
            if (timeRange) parts.push(timeRange);
            return parts.join(' ') || tmi.raw || tmi.detail || '--';
        }

        if (type === 'APREQ' || type === 'CFR') {
            var parts = [type];
            if (tmi.dest) parts.push(tmi.dest);
            if (tmi.fix && tmi.fix !== 'ALL') parts.push('via ' + tmi.fix);
            var fac = '';
            if (tmi.requestor || tmi.provider) {
                fac = (tmi.requestor || '') + ':' + (tmi.provider || '');
            }
            if (fac) parts.push(fac);
            if (timeRange) parts.push(timeRange);
            return parts.join(' ') || tmi.raw || '--';
        }

        if (type === 'CANCEL') {
            var parts = ['CANCEL'];
            if (tmi.dest) parts.push(tmi.dest);
            if (tmi.fix) parts.push('via ' + tmi.fix);
            var fac = '';
            if (tmi.requestor || tmi.provider) {
                fac = (tmi.requestor || '') + ':' + (tmi.provider || '');
            }
            if (fac) parts.push(fac);
            return parts.join(' ') || tmi.raw || '--';
        }

        if (type === 'GS') {
            var parts = ['GS', tmi.element || tmi.airport || ''];
            if (tmi.impacting_condition) parts.push(tmi.impacting_condition);
            if (tmi.advisories && tmi.advisories.length > 0) {
                parts.push('(Advzy ' + tmi.advisories.join(',') + ')');
            }
            if (tmi.dep_facilities) parts.push('DEP: ' + tmi.dep_facilities);
            if (tmi.ended_by) parts.push('[ended ' + tmi.ended_by + ']');
            if (timeRange) parts.push(timeRange);
            return parts.join(' ') || tmi.raw || '--';
        }

        if (type === 'GS_CNX') {
            return 'GS CNX ' + (tmi.element || tmi.airport || '') + (timeRange ? ' ' + timeRange : '');
        }

        if (type === 'GDP') {
            var parts = ['GDP', tmi.element || tmi.airport || ''];
            if (tmi.program_rate) parts.push('Rate:' + tmi.program_rate);
            if (tmi.delay_limit) parts.push('MaxDelay:' + tmi.delay_limit);
            if (tmi.impacting_condition) parts.push(tmi.impacting_condition);
            if (timeRange) parts.push(timeRange);
            return parts.join(' ') || tmi.raw || '--';
        }

        if (type === 'REROUTE' || type === 'REROUTE_CNX') {
            var parts = [];
            if (tmi.name) parts.push(tmi.name);
            if (tmi.route_type || tmi.action) {
                parts.push('(' + (tmi.route_type || '') + ' ' + (tmi.action || '') + ')');
            }
            if (tmi.constrained_area) parts.push(tmi.constrained_area);
            if (tmi.ended_by) parts.push('[ended ' + tmi.ended_by + ']');
            if (timeRange) parts.push(timeRange);
            return parts.join(' ') || tmi.raw || '--';
        }

        // Default: use raw text or detail field
        return tmi.raw || tmi.detail || '--';
    }

    // ========================================================================
    // Weather / METAR
    // ========================================================================

    function bindWeatherHandlers() {
        $('#tmr_fetch_metars').on('click', fetchMetars);
    }

    function fetchMetars() {
        var btn = $('#tmr_fetch_metars');
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> ' + PERTII18n.t('tmr.weather.fetching'));
        $('#tmr_metar_status').text('');

        $.getJSON('api/data/review/tmr_weather.php', { p_id: planId })
            .done(function(resp) {
                btn.prop('disabled', false).html('<i class="fas fa-cloud-download-alt"></i> ' + PERTII18n.t('tmr.weather.fetchMetars'));

                if (!resp.success) {
                    $('#tmr_metar_status').text(resp.error || PERTII18n.t('tmr.weather.fetchFailed'));
                    return;
                }

                var airports = resp.airports || {};
                var container = $('#tmr_metar_results');
                container.empty().show();

                var keys = Object.keys(airports);
                if (keys.length === 0) {
                    container.html('<div class="text-muted small">' + PERTII18n.t('tmr.weather.noMetars') + '</div>');
                    return;
                }

                // Auto-set dominant weather category from first airport with data
                var dominantSet = false;
                keys.forEach(function(apt) {
                    var data = airports[apt];
                    var metars = data.metars || [];

                    if (data.dominant_category && !dominantSet) {
                        $('#tmr_weather_category').val(data.dominant_category);
                        dominantSet = true;
                    }

                    // Build collapsible METAR section per airport
                    var catBadge = data.dominant_category ? '<span class="badge badge-' + metarCatColor(data.dominant_category) + ' ml-2">' + data.dominant_category + '</span>' : '';
                    var section = $(
                        '<div class="card mb-2">' +
                            '<div class="card-header py-1" data-toggle="collapse" data-target="#metar_' + apt.replace(/[^a-zA-Z0-9]/g, '') + '" style="cursor: pointer; font-size: 0.85rem;">' +
                                '<strong>' + escapeHtml(apt) + '</strong> ' + catBadge + ' <span class="text-muted small">(' + metars.length + ' obs)</span>' +
                                '<i class="fas fa-chevron-down float-right"></i>' +
                            '</div>' +
                            '<div class="collapse" id="metar_' + apt.replace(/[^a-zA-Z0-9]/g, '') + '">' +
                                '<div class="card-body p-2 metar-timeline"></div>' +
                            '</div>' +
                        '</div>'
                    );

                    var timeline = section.find('.metar-timeline');
                    metars.forEach(function(m) {
                        var catClass = 'metar-' + (m.category || 'vfr').toLowerCase();
                        timeline.append('<div class="metar-entry ' + catClass + '"><span class="text-muted">' + escapeHtml(m.time_utc || '') + '</span> ' + escapeHtml(m.metar) + '</div>');
                    });

                    container.append(section);
                });

                if (dominantSet) {
                    immediateFieldSave();
                }
            })
            .fail(function() {
                btn.prop('disabled', false).html('<i class="fas fa-cloud-download-alt"></i> ' + PERTII18n.t('tmr.weather.fetchMetars'));
                $('#tmr_metar_status').text(PERTII18n.t('tmr.weather.fetchFailed'));
            });
    }

    function metarCatColor(cat) {
        switch ((cat || '').toUpperCase()) {
            case 'VFR': return 'success';
            case 'MVFR': return 'warning';
            case 'IFR': return 'danger';
            case 'LIFR': return 'dark';
            default: return 'secondary';
        }
    }

    // ========================================================================
    // Staffing (Step 7)
    // ========================================================================

    function bindStaffingHandlers() {
        $('#tmr_load_staffing').on('click', loadStaffing);
    }

    function loadStaffing() {
        var btn = $('#tmr_load_staffing');
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> ' + PERTII18n.t('tmr.staffing.loading'));
        $('#tmr_staffing_status').text('');

        $.getJSON('api/data/review/tmr_staffing.php', { p_id: planId })
            .done(function(resp) {
                btn.prop('disabled', false).html('<i class="fas fa-user-check"></i> ' + PERTII18n.t('tmr.staffing.loadPlanned'));

                if (!resp.success) {
                    $('#tmr_staffing_status').text(resp.error || PERTII18n.t('tmr.staffing.noData'));
                    return;
                }

                if (resp.total === 0) {
                    $('#tmr_staffing_status').text(PERTII18n.t('tmr.staffing.noData'));
                    return;
                }

                staffingData = resp.staffing;
                renderStaffingTable(resp.staffing);
            })
            .fail(function() {
                btn.prop('disabled', false).html('<i class="fas fa-user-check"></i> ' + PERTII18n.t('tmr.staffing.loadPlanned'));
                $('#tmr_staffing_status').text(PERTII18n.t('tmr.staffing.noData'));
            });
    }

    function renderStaffingTable(staffing) {
        var wrap = $('#tmr_staffing_table_wrap');
        wrap.empty().show();

        var statusLabels = { 0: 'N/A', 1: PERTII18n.t('tmr.staffing.adequate'), 2: PERTII18n.t('tmr.staffing.short'), 3: PERTII18n.t('tmr.staffing.overstaffed') };
        var savedAssessment = (reportData.staffing_assessment && typeof reportData.staffing_assessment === 'object') ? reportData.staffing_assessment : {};

        function renderSection(title, items, type) {
            if (!items || items.length === 0) return '';
            var html = '<h6 class="text-info mt-2">' + escapeHtml(title) + '</h6>' +
                '<table class="table table-sm table-bordered staffing-comparison">' +
                '<thead><tr><th>' + PERTII18n.t('tmr.staffing.facility') + '</th><th>' + PERTII18n.t('tmr.staffing.planned') + '</th><th>' + PERTII18n.t('tmr.staffing.actual') + '</th></tr></thead><tbody>';

            items.forEach(function(item) {
                var key = type + '_' + item.id;
                var plannedLabel = item.facility_name || '';
                if (item.position_facility) plannedLabel = item.position_facility + (item.position_name ? ' - ' + item.position_name : '');
                else if (item.position_name) plannedLabel = item.position_name;
                var plannedDetail = '';
                if (item.staffing_quantity) plannedDetail += 'Qty: ' + item.staffing_quantity;
                if (item.staffing_status) plannedDetail += (plannedDetail ? ' | ' : '') + (statusLabels[item.staffing_status] || '');
                if (item.personnel_name) plannedDetail = item.personnel_name + (item.personnel_ois ? ' (' + item.personnel_ois + ')' : '');
                if (item.comments) plannedDetail += (plannedDetail ? ' - ' : '') + item.comments;

                var savedVal = savedAssessment[key] || '';
                html += '<tr>' +
                    '<td>' + escapeHtml(plannedLabel) + '</td>' +
                    '<td class="small">' + escapeHtml(plannedDetail) + '</td>' +
                    '<td><select class="form-control form-control-sm staffing-actual-select" data-key="' + key + '">' +
                        '<option value=""' + (savedVal === '' ? ' selected' : '') + '>--</option>' +
                        '<option value="Adequate"' + (savedVal === 'Adequate' ? ' selected' : '') + '>' + PERTII18n.t('tmr.staffing.adequate') + '</option>' +
                        '<option value="Short"' + (savedVal === 'Short' ? ' selected' : '') + '>' + PERTII18n.t('tmr.staffing.short') + '</option>' +
                        '<option value="Overstaffed"' + (savedVal === 'Overstaffed' ? ' selected' : '') + '>' + PERTII18n.t('tmr.staffing.overstaffed') + '</option>' +
                    '</select></td></tr>';
            });

            html += '</tbody></table>';
            return html;
        }

        var html = '';
        html += renderSection(PERTII18n.t('tmr.staffing.terminal'), staffing.terminal, 'term');
        html += renderSection(PERTII18n.t('tmr.staffing.enroute'), staffing.enroute, 'enr');
        html += renderSection(PERTII18n.t('tmr.staffing.dcc'), staffing.dcc, 'dcc');

        wrap.html(html);

        // Bind change handler
        wrap.on('change', '.staffing-actual-select', function() {
            scheduleSave();
        });
    }

    function gatherStaffingAssessment() {
        var assessment = {};
        $('.staffing-actual-select').each(function() {
            var key = $(this).data('key');
            var val = $(this).val();
            if (val) assessment[key] = val;
        });
        return assessment;
    }

    // ========================================================================
    // Ops Plan Goals & Initiatives (Step 8)
    // ========================================================================

    function bindOpsPlanHandlers() {
        $('#tmr_load_goals').on('click', loadOpsPlan);
    }

    function loadOpsPlan() {
        var btn = $('#tmr_load_goals');
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> ' + PERTII18n.t('tmr.opsPlan.loading'));
        $('#tmr_goals_status').text('');

        $.getJSON('api/data/review/tmr_ops_plan.php', { p_id: planId })
            .done(function(resp) {
                btn.prop('disabled', false).html('<i class="fas fa-bullseye"></i> ' + PERTII18n.t('tmr.opsPlan.loadGoals'));

                if (!resp.success) {
                    $('#tmr_goals_status').text(resp.error || PERTII18n.t('tmr.opsPlan.noGoals'));
                    return;
                }

                goalsData = resp;
                renderGoals(resp.goals);
                renderInitiatives(resp.initiatives);
            })
            .fail(function() {
                btn.prop('disabled', false).html('<i class="fas fa-bullseye"></i> ' + PERTII18n.t('tmr.opsPlan.loadGoals'));
                $('#tmr_goals_status').text(PERTII18n.t('tmr.opsPlan.noGoals'));
            });
    }

    function renderGoals(goals) {
        var wrap = $('#tmr_goals_wrap');
        wrap.empty();

        if (!goals || goals.length === 0) {
            wrap.html('<div class="text-muted small">' + PERTII18n.t('tmr.opsPlan.noGoals') + '</div>').show();
            return;
        }

        var savedGoals = (reportData.goals_assessment && typeof reportData.goals_assessment === 'object') ? reportData.goals_assessment : {};

        var html = '<h6 class="text-info"><i class="fas fa-bullseye"></i> ' + PERTII18n.t('tmr.opsPlan.goal') + 's</h6>';
        goals.forEach(function(g) {
            var key = 'goal_' + g.id;
            var savedVal = savedGoals[key] || '';
            html += '<div class="goal-row">' +
                '<div class="d-flex justify-content-between align-items-start">' +
                    '<div class="goal-text flex-grow-1">' + escapeHtml(g.comments || 'Goal #' + g.id) + '</div>' +
                    '<div class="goal-assessment btn-group btn-group-sm ml-2" data-goal-key="' + key + '">' +
                        '<button class="btn btn-outline-success' + (savedVal === 'Met' ? ' active' : '') + '" data-value="Met">' + PERTII18n.t('tmr.opsPlan.met') + '</button>' +
                        '<button class="btn btn-outline-danger' + (savedVal === 'Not Met' ? ' active' : '') + '" data-value="Not Met">' + PERTII18n.t('tmr.opsPlan.notMet') + '</button>' +
                        '<button class="btn btn-outline-warning' + (savedVal === 'Partial' ? ' active' : '') + '" data-value="Partial">' + PERTII18n.t('tmr.opsPlan.partial') + '</button>' +
                    '</div>' +
                '</div>' +
            '</div>';
        });

        wrap.html(html).show();

        // Bind goal assessment click
        wrap.on('click', '.goal-assessment .btn', function(e) {
            e.preventDefault();
            var group = $(this).closest('.goal-assessment');
            group.find('.btn').removeClass('active');
            $(this).addClass('active');
            scheduleSave();
        });
    }

    function renderInitiatives(initiatives) {
        var wrap = $('#tmr_initiatives_wrap');
        wrap.empty();

        var termInit = (initiatives && initiatives.terminal) || [];
        var enrInit = (initiatives && initiatives.enroute) || [];
        var allInit = termInit.concat(enrInit);

        if (allInit.length === 0) {
            wrap.html('<div class="text-muted small">' + PERTII18n.t('tmr.opsPlan.noInitiatives') + '</div>').show();
            return;
        }

        var html = '<h6 class="text-info"><i class="fas fa-project-diagram"></i> ' + PERTII18n.t('tmr.opsPlan.initiatives') + '</h6>' +
            '<div class="table-responsive"><table class="table table-sm table-bordered" style="font-size: 0.8rem;">' +
            '<thead><tr><th>Facility</th><th>Type</th><th>Cause</th><th>Start</th><th>End</th><th>Level</th><th>Notes</th></tr></thead><tbody>';

        allInit.forEach(function(init) {
            html += '<tr>' +
                '<td>' + escapeHtml(init.facility || '') + (init.area ? ' / ' + escapeHtml(init.area) : '') + '</td>' +
                '<td>' + escapeHtml(init.tmi_type || '') + '</td>' +
                '<td>' + escapeHtml(init.cause || '') + '</td>' +
                '<td class="text-nowrap">' + escapeHtml(init.start_datetime || '') + '</td>' +
                '<td class="text-nowrap">' + escapeHtml(init.end_datetime || '') + '</td>' +
                '<td>' + escapeHtml(init.level || '') + '</td>' +
                '<td class="text-truncate" style="max-width: 200px;">' + escapeHtml(init.notes || '') + '</td>' +
                '</tr>';
        });

        html += '</tbody></table></div>';
        wrap.html(html).show();
    }

    function gatherGoalsAssessment() {
        var assessment = {};
        $('.goal-assessment').each(function() {
            var key = $(this).data('goal-key');
            var active = $(this).find('.btn.active');
            if (active.length) assessment[key] = active.data('value');
        });
        return assessment;
    }

    // ========================================================================
    // TMI Compliance Integration (Step 6)
    // ========================================================================

    function checkComplianceAvailable() {
        $.getJSON('api/analysis/tmi_compliance.php', { action: 'status', p_id: planId })
            .done(function(resp) {
                if (resp && resp.has_results) {
                    $('#tmr_import_compliance').show();
                }
            })
            .fail(function() {
                // Compliance not available, that's fine
            });

        $('#tmr_import_compliance').on('click', importCompliance);
    }

    function importCompliance() {
        var btn = $('#tmr_import_compliance');
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> ' + PERTII18n.t('tmr.status.loading'));

        $.getJSON('api/analysis/tmi_compliance.php', { action: 'results', p_id: planId })
            .done(function(resp) {
                btn.prop('disabled', false).html('<i class="fas fa-file-import"></i> ' + PERTII18n.t('tmr.tmi.importCompliance'));

                if (!resp || !resp.results) return;

                var matched = 0;
                var results = resp.results;

                // Match compliance results to TMIs by element
                tmiList.forEach(function(tmi) {
                    if (!tmi.element) return;
                    var el = tmi.element.toUpperCase();

                    // Search in MIT results
                    if (results.mit_results) {
                        results.mit_results.forEach(function(r) {
                            var mp = (r.measurement_point || r.fix || '').toUpperCase();
                            if (mp === el || mp.indexOf(el) !== -1) {
                                var pct = r.compliance_pct || r.compliance_rate || 0;
                                tmi.complied = pct >= 80 ? 'Y' : 'N';
                                tmi.compliance_pct = Math.round(pct);
                                matched++;
                            }
                        });
                    }

                    // Search in GS results
                    if (results.gs_results) {
                        results.gs_results.forEach(function(r) {
                            var airport = (r.airport || '').toUpperCase();
                            if (airport === el || 'K' + airport === el || airport === 'K' + el) {
                                var pct = r.compliance_pct || r.compliance_rate || 0;
                                tmi.complied = pct >= 80 ? 'Y' : 'N';
                                tmi.compliance_pct = Math.round(pct);
                                matched++;
                            }
                        });
                    }
                });

                renderTMITable();
                if (matched > 0) immediateFieldSave();

                Swal.fire({
                    icon: matched > 0 ? 'success' : 'info',
                    title: PERTII18n.t('tmr.tmi.importCompliance'),
                    text: matched + ' TMI(s) matched with compliance data.',
                    timer: 3000,
                    showConfirmButton: false,
                });
            })
            .fail(function() {
                btn.prop('disabled', false).html('<i class="fas fa-file-import"></i> ' + PERTII18n.t('tmr.tmi.importCompliance'));
            });
    }

    /**
     * Auto-import compliance data after report loads.
     * Silently matches compliance results to TMIs without user interaction.
     * Only runs if TMIs exist and none have compliance_pct already set.
     */
    function autoImportCompliance() {
        if (tmiList.length === 0) return;

        // Skip if any TMI already has compliance_pct (already imported)
        var alreadyImported = tmiList.some(function(t) { return t.compliance_pct !== undefined && t.compliance_pct !== null; });
        if (alreadyImported) return;

        $.getJSON('api/analysis/tmi_compliance.php', { action: 'results', p_id: planId })
            .done(function(resp) {
                if (!resp || !resp.results) return;

                var matched = 0;
                var results = resp.results;

                tmiList.forEach(function(tmi) {
                    // For MIT matching, prefer fix field (measurement point), fall back to element
                    var mitKey = (tmi.fix || tmi.element || '').toUpperCase();
                    var elKey = (tmi.element || '').toUpperCase();

                    if (results.mit_results && mitKey) {
                        results.mit_results.forEach(function(r) {
                            var mp = (r.measurement_point || r.fix || '').toUpperCase();
                            if (mp === mitKey || mp.indexOf(mitKey) !== -1) {
                                var pct = r.compliance_pct || r.compliance_rate || 0;
                                tmi.complied = pct >= 80 ? 'Y' : 'N';
                                tmi.compliance_pct = Math.round(pct);
                                matched++;
                            }
                        });
                    }

                    if (results.gs_results && elKey) {
                        results.gs_results.forEach(function(r) {
                            var airport = (r.airport || '').toUpperCase();
                            if (airport === elKey || 'K' + airport === elKey || airport === 'K' + elKey) {
                                var pct = r.compliance_pct || r.compliance_rate || 0;
                                tmi.complied = pct >= 80 ? 'Y' : 'N';
                                tmi.compliance_pct = Math.round(pct);
                                matched++;
                            }
                        });
                    }
                });

                if (matched > 0) {
                    renderTMITable();
                    immediateFieldSave();
                }
            });
    }

    // ========================================================================
    // Demand Chart Snapshots
    // ========================================================================

    /**
     * Collect current demand chart data for persistence.
     * Merges live chart data with any previously saved snapshots.
     */
    function gatherDemandSnapshots() {
        var snapshots = demandSnapshots ? Object.assign({}, demandSnapshots) : {};

        // Capture data from any live charts
        Object.keys(demandCharts).forEach(function(apt) {
            var chart = demandCharts[apt];
            if (chart && typeof chart.getSnapshot === 'function') {
                var snap = chart.getSnapshot();
                if (snap) {
                    snapshots[apt] = snap;
                }
            }
        });

        return Object.keys(snapshots).length > 0 ? snapshots : null;
    }

    // ========================================================================
    // Demand Charts
    // ========================================================================

    var demandChartsInitialized = false;
    var demandChartParams = null; // Shared time range params for chart creation
    var demandSyncTimer = null;

    function initDemandCharts() {
        if (typeof window.DemandChartCore === 'undefined') return;

        var eventDate = window.planData.event_date;
        var eventStart = window.planData.event_start || '00:00';
        var eventEndDate = window.planData.event_end_date || eventDate;
        var eventEndTime = window.planData.event_end_time || '23:59';

        if (!eventDate) return;

        var startTime = normalizeTime(eventStart);
        var endTime = normalizeTime(eventEndTime);

        var startStr = eventDate + 'T' + startTime + ':00Z';
        var endStr = eventEndDate + 'T' + endTime + ':00Z';

        var startUtc = new Date(startStr);
        var endUtc = new Date(endStr);
        var now = new Date();

        var padMs = 60 * 60 * 1000;
        var sdMonth = (startUtc.getUTCMonth() + 1).toString().padStart(2, '0');
        var sdDay = startUtc.getUTCDate().toString().padStart(2, '0');
        var sdYear = startUtc.getUTCFullYear();
        var sdTime = startUtc.getUTCHours().toString().padStart(2, '0') + startUtc.getUTCMinutes().toString().padStart(2, '0') + 'Z';
        var edTime = endUtc.getUTCHours().toString().padStart(2, '0') + endUtc.getUTCMinutes().toString().padStart(2, '0') + 'Z';

        demandChartParams = {
            startHoursFromNow: (startUtc.getTime() - padMs - now.getTime()) / (60 * 60 * 1000),
            endHoursFromNow: (endUtc.getTime() + padMs - now.getTime()) / (60 * 60 * 1000),
            dateLabel: sdMonth + '/' + sdDay + '/' + sdYear + ' ' + sdTime + ' - ' + edTime,
        };

        var configs = (window.planData && window.planData.configs) || [];
        var tabLink = $('a[data-toggle="tab"][href="#tmr_airport"]');

        function loadInitialCharts() {
            if (demandChartsInitialized) return;
            demandChartsInitialized = true;

            // Load charts for pre-existing plan configs
            var seen = {};
            configs.forEach(function(cfg) {
                if (seen[cfg.airport]) return;
                seen[cfg.airport] = true;
                addDemandChart(cfg.airport, cfg.aar, cfg.adr);
            });

            // Also sync any airports already in the textarea
            syncDemandChartsFromTextarea();

            // Load charts from saved snapshots (for airports not in plan configs or textarea)
            if (demandSnapshots) {
                Object.keys(demandSnapshots).forEach(function(apt) {
                    if (!seen[apt] && !demandCharts[apt]) {
                        addDemandChart(apt, null, null);
                    }
                });
            }
        }

        // Defer loading until Airport Conditions tab is visible (ECharts needs visible container)
        if ($('#tmr_airport').hasClass('active')) {
            loadInitialCharts();
        } else {
            tabLink.one('shown.bs.tab', loadInitialCharts);
        }

        // Resize charts when tab becomes visible
        tabLink.on('shown.bs.tab', function() {
            Object.keys(demandCharts).forEach(function(apt) {
                demandCharts[apt].resize();
            });
        });

        // Sync charts when textarea content changes (debounced)
        $('#tmr_airport_conditions').on('input', function() {
            if (demandSyncTimer) clearTimeout(demandSyncTimer);
            demandSyncTimer = setTimeout(function() {
                if (demandChartsInitialized) syncDemandChartsFromTextarea();
            }, 1500);
        });
    }

    /**
     * Add a demand chart for an airport if one doesn't already exist.
     * Creates DOM container dynamically and loads the chart.
     */
    function addDemandChart(airport, aar, adr) {
        if (!demandChartParams) return;
        if (demandCharts[airport]) return; // Already loaded

        var icao = airport;
        if (icao && icao.length === 3) icao = 'K' + icao;

        var containerId = 'demand_chart_' + airport;
        var el = document.getElementById(containerId);

        // Create container if it doesn't exist
        if (!el) {
            var wrapper = document.getElementById('tmr_demand_charts');
            if (!wrapper) return;

            // Remove the "no configs" empty state if present
            var emptyMsg = wrapper.querySelector('.text-muted.text-center');
            if (emptyMsg) emptyMsg.remove();

            var block = document.createElement('div');
            block.className = 'mb-3';
            block.id = 'demand_block_' + airport;
            var rateStr = (aar || '?') + ' / ' + (adr || '?');
            block.innerHTML = '<h6 class="text-warning">' + escapeHtml(airport) +
                ' <span class="text-muted small">AAR: ' + escapeHtml(String(aar || '?')) +
                ' | ADR: ' + escapeHtml(String(adr || '?')) + '</span></h6>' +
                '<div class="demand-chart-container" id="' + containerId + '"></div>';
            wrapper.appendChild(block);
            el = document.getElementById(containerId);
        }

        if (!el) return;

        var chart = window.DemandChartCore.createChart(containerId, {
            direction: 'both',
            granularity: 'hourly',
            showRateLines: true,
            timeRangeStart: demandChartParams.startHoursFromNow,
            timeRangeEnd: demandChartParams.endHoursFromNow,
        });

        if (chart) {
            demandCharts[airport] = chart;

            // Try restoring from saved snapshot first (for historical events)
            var savedSnap = demandSnapshots && (demandSnapshots[airport] || demandSnapshots[icao]);
            if (savedSnap && savedSnap.demandData) {
                chart.loadFromSnapshot(savedSnap);
                chart.setTitle(icao + '          Saved Snapshot          ' + demandChartParams.dateLabel);
            } else {
                // Fetch live data
                chart.load(icao).then(function(result) {
                    if (result && result.success) {
                        chart.setTitle(icao + '          Archived          ' + demandChartParams.dateLabel);
                        // Auto-save snapshot after successful live load
                        scheduleSave();
                    }
                });
            }
        }
    }

    /**
     * Parse airport codes from the conditions textarea and add charts for new airports.
     * Supports:
     *   - Pipe-delimited: "SFO | 28L/28R | 01L/01R | 35/40"
     *   - PERTI format:   "07/2023  SFO  VMC  ARR:28L/28R DEP:01L/01R  AAR(Strat):35 ADR:40"
     */
    function syncDemandChartsFromTextarea() {
        var text = $('#tmr_airport_conditions').val();
        if (!text) return;

        var exclude = /^(VMC|IMC|ARR|DEP|AAR|ADR|VFR|IFR|RWY|CAT|OVC|BKN|FEW|SCT|CLR|SKC)$/;

        text.split(/\n/).forEach(function(line) {
            line = line.trim();
            if (!line) return;

            var airport = null;
            var aar = null;
            var adr = null;

            // Format: PERTI "07/2023  SFO  VMC  ARR:...  AAR(Strat):35 ADR:40 [$ ...]"
            var perti = line.match(/^\d{2}\/\d{4}\s+([A-Z]{3,4})\b/);
            if (perti) {
                airport = perti[1];
                var aarM = line.match(/AAR[^:]*:\s*(\d+)/);
                var adrM = line.match(/ADR[^:]*:\s*(\d+)/);
                if (aarM) aar = aarM[1];
                if (adrM) adr = adrM[1];
            }

            // Format: Pipe-delimited "KJFK | 22L,22R | 31L | 40/44"
            if (!airport && line.indexOf('|') !== -1) {
                var parts = line.split('|');
                var code = parts[0].trim().toUpperCase();
                if (/^K?[A-Z]{3,4}$/.test(code)) {
                    airport = code;
                }
                if (parts.length >= 4) {
                    var rates = parts[3].trim().match(/(\d+)\s*\/\s*(\d+)/);
                    if (rates) { aar = rates[1]; adr = rates[2]; }
                }
            }

            if (airport && !exclude.test(airport)) {
                addDemandChart(airport, aar, adr);
            }
        });
    }

    // ========================================================================
    // Discord Export
    // ========================================================================

    function bindExportHandler() {
        $('#tmr_export_btn').on('click', exportToDiscord);
    }

    function exportToDiscord() {
        var btn = $('#tmr_export_btn');

        function doExport() {
            btn.html('<i class="fas fa-spinner fa-spin"></i> ' + PERTII18n.t('tmr.status.generating'));

            $.getJSON('api/data/review/tmr_export.php', { p_id: planId })
                .done(function(resp) {
                    btn.html('<i class="fas fa-share-alt fa-fw"></i> ' + PERTII18n.t('tmr.export.button'));

                    if (!resp.success) {
                        Swal.fire({ icon: 'error', title: PERTII18n.t('tmr.export.failed'), text: resp.error || PERTII18n.t('tmr.export.failedText') });
                        return;
                    }

                    copyToClipboard(resp.message);

                    Swal.fire({
                        icon: 'success',
                        title: PERTII18n.t('tmr.export.copiedToClipboard'),
                        html: '<div class="text-left" style="max-height: 400px; overflow-y: auto; font-family: monospace; font-size: 0.8rem; white-space: pre-wrap; background: #1a1a2e; color: #eee; padding: 10px; border-radius: 4px;">' +
                              escapeHtml(resp.message) + '</div>' +
                              '<div class="mt-2 text-muted small">' + PERTII18n.t('tmr.export.characters', { count: resp.char_count }) + '</div>',
                        width: 700,
                        confirmButtonText: PERTII18n.t('tmr.export.done'),
                    });
                })
                .fail(function() {
                    btn.html('<i class="fas fa-share-alt fa-fw"></i> ' + PERTII18n.t('tmr.export.button'));
                    Swal.fire({ icon: 'error', title: PERTII18n.t('tmr.export.failed'), text: PERTII18n.t('tmr.export.failedNetwork') });
                });
        }

        if (dirty) {
            saveReport().done(doExport);
        } else {
            doExport();
        }
    }

    function copyToClipboard(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).catch(function() { fallbackCopy(text); });
        } else {
            fallbackCopy(text);
        }
    }

    function fallbackCopy(text) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch(e) { /* ignore */ }
        document.body.removeChild(ta);
    }

    // ========================================================================
    // UI Helpers
    // ========================================================================

    function showSaveIndicator(state) {
        var el = $('#saveIndicator');
        el.removeClass('saving saved error');

        if (state === 'saving') {
            el.addClass('saving').text(PERTII18n.t('tmr.status.saving')).show();
        } else if (state === 'saved') {
            el.addClass('saved').text(PERTII18n.t('tmr.status.saved')).show();
            setTimeout(function() { el.fadeOut(400); }, 2000);
        } else if (state === 'error') {
            el.addClass('error').text(PERTII18n.t('tmr.status.saveFailed')).show();
            setTimeout(function() { el.fadeOut(400); }, 4000);
        }
    }

    function updateStatus(type, text) {
        var el = $('#tmr_status_label');
        var iconClass = 'fas fa-circle ';

        switch (type) {
            case 'loading':
                iconClass = 'fas fa-spinner fa-spin text-info';
                break;
            case 'new':
                iconClass += 'text-warning';
                break;
            case 'loaded':
            case 'saved':
                iconClass += 'text-success';
                break;
            case 'error':
                iconClass += 'text-danger';
                break;
            default:
                iconClass += 'text-secondary';
        }

        el.html('<i class="' + iconClass + '"></i> ' + escapeHtml(text));
    }

    function normalizeTime(t) {
        if (!t) return '00:00';
        t = t.trim();
        if (t.indexOf(':') !== -1) return t.substring(0, 5);
        if (/^\d{4}$/.test(t)) return t.substring(0, 2) + ':' + t.substring(2, 4);
        return '00:00';
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

})();
