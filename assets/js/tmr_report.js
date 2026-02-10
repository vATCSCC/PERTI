/**
 * TMR Report Module
 * Manages the Traffic Management Review report workflow:
 * - Load/save report data via API
 * - Auto-save with debounce
 * - Y/N/NA toggle handling
 * - Trigger checkbox handling
 * - TMI database loading & manual entry
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
    var dirty = false;

    // ========================================================================
    // Initialization
    // ========================================================================

    $(document).ready(function() {
        if (!planId) return;

        loadReport();
        bindFieldHandlers();
        bindTriggerHandlers();
        bindYNToggleHandlers();
        bindTMIHandlers();
        bindExportHandler();
    });

    // ========================================================================
    // Report Load / Save
    // ========================================================================

    function loadReport() {
        updateStatus('loading', 'Loading...');

        $.getJSON('api/data/review/tmr_report.php', { p_id: planId })
            .done(function(resp) {
                if (!resp.success) {
                    updateStatus('error', 'Load failed');
                    return;
                }

                reportData = resp.report || {};
                isNew = resp.is_new;

                populateForm(reportData);
                initDemandCharts();

                if (isNew) {
                    updateStatus('new', 'New report');
                } else {
                    updateStatus('loaded', reportData.status === 'draft' ? 'Draft' : 'Saved');
                }
            })
            .fail(function() {
                updateStatus('error', 'Load failed');
            });
    }

    function saveReport() {
        if (!window.planData.perm) return;

        var data = gatherFormData();
        data.p_id = planId;

        showSaveIndicator('saving');

        $.ajax({
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
                updateStatus('saved', 'Saved');
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
    // Form Population
    // ========================================================================

    function populateForm(data) {
        // Text fields and textareas
        $('.tmr-field').each(function() {
            var field = $(this).data('field');
            if (!field || data[field] === undefined) return;

            var val = data[field];
            if (val === null) val = '';

            if (this.tagName === 'SELECT') {
                $(this).val(val);
            } else {
                $(this).val(val);
            }
        });

        // Trigger checkboxes
        var triggers = data.tmr_triggers || [];
        if (typeof triggers === 'string') {
            try { triggers = JSON.parse(triggers); } catch(e) { triggers = []; }
        }
        $('.tmr-trigger').each(function() {
            $(this).prop('checked', triggers.indexOf($(this).val()) !== -1);
        });

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
                // Strip internal keys before saving
                var clean = $.extend({}, t);
                delete clean._source;
                delete clean._key;
                selectedTmis.push(clean);
            }
        });
        data.tmi_list = selectedTmis;
        data.tmi_source = tmiList.some(function(t) { return t._source === 'db'; }) ? 'database' : 'manual';

        return data;
    }

    // ========================================================================
    // Event Handlers
    // ========================================================================

    function bindFieldHandlers() {
        // Debounced save for text inputs and textareas
        $(document).on('input', '.tmr-field', function() {
            if (this.tagName === 'SELECT') return; // handled separately
            scheduleSave();
        });

        // Immediate save for select dropdowns
        $(document).on('change', '.tmr-field select, select.tmr-field', function() {
            immediateFieldSave();
        });
    }

    function bindTriggerHandlers() {
        $(document).on('change', '.tmr-trigger', function() {
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
        // Load from DB
        $('#tmr_load_db_tmis').on('click', function() {
            loadTMIsFromDB();
        });

        // Toggle bulk paste form
        $('#tmr_bulk_paste_toggle').on('click', function() {
            $('#tmr_bulk_paste_form').toggle();
        });

        // Parse bulk NTML input
        $('#tmr_parse_bulk_ntml').on('click', function() {
            parseBulkNTML();
        });

        // Toggle manual entry form
        $('#tmr_add_manual_tmi').on('click', function() {
            $('#tmr_manual_tmi_form').toggle();
        });

        // Save manual TMI entry
        $('#tmr_save_manual_tmi').on('click', function() {
            addManualTMI();
        });

        // Select all checkbox
        $('#tmr_tmi_select_all').on('change', function() {
            var checked = $(this).prop('checked');
            tmiList.forEach(function(t) {
                tmiSelected[t._key] = checked;
            });
            renderTMITable();
            immediateFieldSave();
        });

        // Individual TMI select
        $(document).on('change', '.tmi-select-row', function() {
            var key = $(this).data('key');
            tmiSelected[key] = $(this).prop('checked');
            immediateFieldSave();
        });
    }

    function loadTMIsFromDB() {
        var btn = $('#tmr_load_db_tmis');
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Loading...');
        $('#tmr_tmi_status').text('Querying TMI database...');

        $.getJSON('api/data/review/tmr_tmis.php', { p_id: planId })
            .done(function(resp) {
                btn.prop('disabled', false).html('<i class="fas fa-database"></i> Load TMIs from Database');

                if (!resp.success) {
                    $('#tmr_tmi_status').text('Error: ' + (resp.error || 'Unknown'));
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

                // Select all DB TMIs by default
                dbTmis.forEach(function(t) { tmiSelected[t._key] = true; });

                renderTMITable();
                $('#tmr_tmi_status').text(resp.count + ' TMI(s) found for event window');

                // Auto-save to persist the loaded TMIs
                immediateFieldSave();
            })
            .fail(function() {
                btn.prop('disabled', false).html('<i class="fas fa-database"></i> Load TMIs from Database');
                $('#tmr_tmi_status').text('Failed to load TMIs');
            });
    }

    function addManualTMI() {
        var type = $('#manual_tmi_type').val();
        var element = $('#manual_tmi_element').val().trim();
        var detail = $('#manual_tmi_detail').val().trim();
        var start = $('#manual_tmi_start').val().trim();
        var end = $('#manual_tmi_end').val().trim();

        if (!element && !detail) {
            Swal.fire({ icon: 'warning', title: 'Missing Info', text: 'Enter at least an element or detail.', timer: 2000, showConfirmButton: false });
            return;
        }

        var key = 'manual_' + Date.now();
        var tmi = {
            _source: 'manual',
            _key: key,
            category: 'manual',
            type: type,
            element: element,
            detail: detail,
            start_utc: start || null,
            end_utc: end || null,
            status: 'Manual',
        };

        tmiList.push(tmi);
        tmiSelected[key] = true;
        renderTMITable();

        // Clear form
        $('#manual_tmi_element, #manual_tmi_detail, #manual_tmi_start, #manual_tmi_end').val('');
        $('#manual_tmi_type').val('MIT');

        immediateFieldSave();
    }

    function parseBulkNTML() {
        var raw = $('#tmr_bulk_ntml_input').val().trim();
        if (!raw) {
            Swal.fire({ icon: 'warning', title: 'Nothing to parse', text: 'Paste NTML entries first.', timer: 2000, showConfirmButton: false });
            return;
        }

        var lines = raw.split('\n').filter(function(l) { return l.trim().length > 0; });
        var added = 0;

        lines.forEach(function(line) {
            line = line.trim();
            if (!line) return;

            var parsed = parseNTMLLine(line);
            var key = 'manual_' + Date.now() + '_' + added;
            var tmi = {
                _source: 'manual',
                _key: key,
                category: 'manual',
                type: parsed.type,
                element: parsed.element,
                detail: parsed.detail,
                start_utc: parsed.start || null,
                end_utc: parsed.end || null,
                status: 'Manual',
                raw: line,
            };

            tmiList.push(tmi);
            tmiSelected[key] = true;
            added++;
        });

        renderTMITable();
        $('#tmr_bulk_parse_status').text(added + ' entr' + (added === 1 ? 'y' : 'ies') + ' added');
        $('#tmr_bulk_ntml_input').val('');
        immediateFieldSave();
    }

    /**
     * Parse a single NTML line into structured fields.
     * Handles common formats:
     *   LAS via FLCHR 20MIT ZLA:ZOA 2359Z-0400Z
     *   LAS GS (NCT) 0230Z-0315Z issued 0244Z
     *   JFK GDP AAR:30 2200Z-0200Z
     *   BOS 15MIT ZBW:ZNY 2300Z-0100Z
     *   30/2328  BOS VMC ARR:27/32 DEP:33L AAR(Strat):40 ADR:40 2328-0359
     */
    function parseNTMLLine(line) {
        var result = { type: 'Other', element: '', detail: line, start: null, end: null };

        // Extract time range: patterns like 2359Z-0400Z, 2200-0200, 2328Z - 0400Z
        var timeMatch = line.match(/(\d{4})Z?\s*[-–]\s*(\d{4})Z?/i);
        if (timeMatch) {
            result.start = timeMatch[1] + 'z';
            result.end = timeMatch[2] + 'z';
        }

        // Extract element (first word if 2-4 uppercase letters — airport/fix code)
        var elMatch = line.match(/^(\d{2}\/\d{4}\s+)?([A-Z]{2,4})\b/);
        if (elMatch) {
            result.element = elMatch[2];
        }

        // Detect TMI type from keywords
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

        // Build detail: everything except element and time
        var detail = line;
        // Strip leading date prefix like "30/2328"
        detail = detail.replace(/^\d{2}\/\d{4}\s+/, '');
        // Strip the element if it starts the line
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

        if (tmiList.length === 0) {
            tbody.html('<tr><td colspan="8" class="text-center text-muted py-3">Click "Load TMIs from Database" or add entries manually.</td></tr>');
            return;
        }

        tmiList.forEach(function(t) {
            var checked = tmiSelected[t._key] ? 'checked' : '';
            var badgeClass = 'tmi-badge-' + (t.category || 'ntml');
            var sourceLabel = t._source === 'db' ? 'DB' : (t._source === 'manual' ? 'Manual' : 'Saved');

            var row = '<tr>' +
                '<td><input type="checkbox" class="tmi-select-row" data-key="' + t._key + '" ' + checked + '></td>' +
                '<td><span class="badge ' + badgeClass + '">' + escapeHtml(t.type || t.category || '--') + '</span></td>' +
                '<td>' + escapeHtml(t.element || '--') + '</td>' +
                '<td class="text-truncate" style="max-width: 250px;" title="' + escapeHtml(t.detail || '') + '">' + escapeHtml(t.detail || '--') + '</td>' +
                '<td class="text-nowrap">' + formatTmiTime(t.start_utc) + '</td>' +
                '<td class="text-nowrap">' + formatTmiTime(t.end_utc) + '</td>' +
                '<td>' + escapeHtml(t.status || '--') + '</td>' +
                '<td><span class="badge badge-' + (t._source === 'db' ? 'info' : 'secondary') + '">' + sourceLabel + '</span></td>' +
                '</tr>';

            tbody.append(row);
        });
    }

    function formatTmiTime(dt) {
        if (!dt) return '--';
        // If already short like "2200z", return as-is
        if (dt.length <= 6) return escapeHtml(dt);
        // Extract HH:MM from datetime string
        var match = dt.match(/(\d{2}):(\d{2})/);
        if (match) return match[1] + match[2] + 'z';
        return escapeHtml(dt);
    }

    // ========================================================================
    // Demand Charts
    // ========================================================================

    function initDemandCharts() {
        if (typeof window.DemandChartCore === 'undefined') return;

        var configs = (window.planData && window.planData.configs) || [];
        if (configs.length === 0) return;

        // Calculate event window as hour offsets from now
        var eventDate = window.planData.event_date;
        var eventStart = window.planData.event_start || '00:00';
        var eventEndDate = window.planData.event_end_date || eventDate;
        var eventEndTime = window.planData.event_end_time || '23:59';

        if (!eventDate) return;

        // Normalize time strings — handle "2359", "23:59", "23:59:00" formats
        var startTime = normalizeTime(eventStart);
        var endTime = normalizeTime(eventEndTime);

        // Build UTC datetimes
        var startStr = eventDate + 'T' + startTime + ':00Z';
        var endStr = eventEndDate + 'T' + endTime + ':00Z';

        var startUtc = new Date(startStr);
        var endUtc = new Date(endStr);
        var now = new Date();

        // Pad by 1 hour each side
        var padMs = 60 * 60 * 1000;
        var startHoursFromNow = (startUtc.getTime() - padMs - now.getTime()) / (60 * 60 * 1000);
        var endHoursFromNow = (endUtc.getTime() + padMs - now.getTime()) / (60 * 60 * 1000);

        configs.forEach(function(cfg) {
            var containerId = 'demand_chart_' + cfg.airport;
            var el = document.getElementById(containerId);
            if (!el) return;

            // Convert FAA 3-letter to ICAO 4-letter (prefix K for US airports)
            var icao = cfg.airport;
            if (icao && icao.length === 3) icao = 'K' + icao;

            var chart = window.DemandChartCore.createChart(containerId, {
                direction: 'both',
                granularity: 'hourly',
                showRateLines: true,
                timeRangeStart: startHoursFromNow,
                timeRangeEnd: endHoursFromNow,
            });

            if (chart) {
                demandCharts[cfg.airport] = chart;
                chart.load(icao);
            }
        });
    }

    // ========================================================================
    // Discord Export
    // ========================================================================

    function bindExportHandler() {
        $('#tmr_export_btn').on('click', function() {
            exportToDiscord();
        });
    }

    function exportToDiscord() {
        // Save first if dirty
        if (dirty) {
            saveReport();
        }

        var btn = $('#tmr_export_btn');
        btn.html('<i class="fas fa-spinner fa-spin"></i> Generating...');

        $.getJSON('api/data/review/tmr_export.php', { p_id: planId })
            .done(function(resp) {
                btn.html('<i class="fas fa-share-alt fa-fw"></i> Export to Discord');

                if (!resp.success) {
                    Swal.fire({ icon: 'error', title: 'Export Failed', text: resp.error || 'Unknown error' });
                    return;
                }

                // Copy to clipboard
                copyToClipboard(resp.message);

                Swal.fire({
                    icon: 'success',
                    title: 'Copied to Clipboard',
                    html: '<div class="text-left" style="max-height: 400px; overflow-y: auto; font-family: monospace; font-size: 0.8rem; white-space: pre-wrap; background: #1a1a2e; color: #eee; padding: 10px; border-radius: 4px;">' +
                          escapeHtml(resp.message) + '</div>' +
                          '<div class="mt-2 text-muted small">' + resp.char_count + ' characters</div>',
                    width: 700,
                    confirmButtonText: 'Done',
                });
            })
            .fail(function() {
                btn.html('<i class="fas fa-share-alt fa-fw"></i> Export to Discord');
                Swal.fire({ icon: 'error', title: 'Export Failed', text: 'Network error' });
            });
    }

    function copyToClipboard(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).catch(function() {
                fallbackCopy(text);
            });
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
            el.addClass('saving').text('Saving...').show();
        } else if (state === 'saved') {
            el.addClass('saved').text('Saved').show();
            setTimeout(function() { el.fadeOut(400); }, 2000);
        } else if (state === 'error') {
            el.addClass('error').text('Save failed').show();
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

    /**
     * Normalize time string to HH:MM format.
     * Handles "2359", "23:59", "23:59:00", etc.
     */
    function normalizeTime(t) {
        if (!t) return '00:00';
        t = t.trim();
        // Already HH:MM or HH:MM:SS — extract HH:MM
        if (t.indexOf(':') !== -1) return t.substring(0, 5);
        // 4-digit HHMM format
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
