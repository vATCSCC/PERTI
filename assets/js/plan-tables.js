/**
 * Plan Tables — Sortable & Grouped rendering for plan page tables
 *
 * Handles: Field Configs, Terminal Staffing, En Route Staffing, DCC Staffing
 * Works on both plan.php (authenticated) and data.php (public)
 *
 * Dependencies: FacilityHierarchy, PERTII18n, jQuery
 *
 * @package PERTI
 * @subpackage Assets/JS
 */

(function(global) {
    'use strict';

    const _PERTI = (typeof PERTI !== 'undefined') ? PERTI : null;

    // ARTCC code → display name (from PERTI namespace or fallback)
    function getArtccName(code) {
        if (_PERTI && _PERTI.FACILITY && _PERTI.FACILITY.FACILITY_NAME_MAP) {
            return _PERTI.FACILITY.FACILITY_NAME_MAP[code] || code;
        }
        return code;
    }

    // i18n helper — falls back to raw key if PERTII18n not available
    function t(key, params) {
        if (typeof PERTII18n !== 'undefined' && PERTII18n.t) {
            return PERTII18n.t(key, params);
        }
        return key;
    }

    // HTML-escape helper
    function esc(str) {
        if (str == null) return '';
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(String(str)));
        return div.innerHTML;
    }

    // Weather code → {label, bgColor}
    const WEATHER_MAP = {
        0: { label: 'Unknown', color: '' },
        1: { label: 'VMC', color: '#5CFF5C' },
        2: { label: 'LVMC', color: '#85bd02' },
        3: { label: 'IMC', color: '#02bef7' },
        4: { label: 'LIMC', color: '#6122f5' },
    };

    // Terminal staffing status code → {label, bgColor}
    const TERM_STATUS_MAP = {
        0: { label: 'plan.staffing.statusUnknown', color: '#FFFF5C' },
        1: { label: 'plan.staffing.statusTopDown', color: '#00D100' },
        2: { label: 'plan.staffing.statusYes', color: '#5CFF5C' },
        3: { label: 'plan.staffing.statusUnderstaffed', color: '#FFCD00' },
        4: { label: 'plan.staffing.statusNo', color: '', cssClass: 'bg-danger' },
    };

    // Enroute staffing status code → {label, bgColor}
    const ENROUTE_STATUS_MAP = {
        0: { label: 'plan.staffing.statusUnknown', color: '#FFFF5C' },
        1: { label: 'plan.staffing.statusYes', color: '#5CFF5C' },
        2: { label: 'plan.staffing.statusUnderstaffed', color: '#FFCD00' },
        3: { label: 'plan.staffing.statusNo', color: '', cssClass: 'bg-danger' },
    };

    // ==============================
    // Group Resolution
    // ==============================

    /**
     * Try to resolve a code to parent ARTCC via multiple strategies
     * @param {string} raw - Raw code string
     * @returns {string|null} - ARTCC code or null
     */
    function tryResolveArtcc(raw) {
        if (!raw) return null;
        // 1. Direct lookup (handles ARTCC codes, TRACON codes, ICAO airports)
        let artcc = FacilityHierarchy.getParentArtcc(raw);
        if (artcc) return artcc;
        // 2. Resolve aliases (ZEG→CZEG, ZVR→CZVR, etc.)
        const resolved = FacilityHierarchy.resolveAlias(raw);
        if (resolved !== raw) {
            artcc = FacilityHierarchy.getParentArtcc(resolved);
            if (artcc) return artcc;
        }
        // 3. Normalize as ICAO (YYZ→CYYZ, ANC→PANC, HNL→PHNL, SJU→TJSJ, ATL→KATL)
        if (raw.length === 3 || raw.length === 4) {
            const icao = FacilityHierarchy.normalizeIcao(raw);
            if (icao && icao !== raw) {
                artcc = FacilityHierarchy.getParentArtcc(icao);
                if (artcc) return artcc;
            }
        }
        // 4. FIR lookup for international airports
        const firInfo = FacilityHierarchy.getFIR(
            raw.length === 3 ? FacilityHierarchy.normalizeIcao(raw) : raw
        );
        if (firInfo && firInfo.fir) return firInfo.fir;
        return null;
    }

    /**
     * Extract facility code(s) from freetext and try to resolve each
     * Handles: "SCT - SoCal TRACON", "ZLA", "ZLA - Los Angeles ARTCC", "N90", etc.
     * @param {string} text - Freetext facility name
     * @returns {string|null} - ARTCC code or null
     */
    function extractAndResolve(text) {
        if (!text) return null;
        const upper = text.toUpperCase().trim();
        // Strategy 1: Try the prefix before " - " (most common format: "ZLA - Los Angeles ARTCC")
        const dashParts = upper.split(/\s*-\s*/);
        const prefix = dashParts[0].trim();
        // Take first word of prefix in case of multi-word prefix
        const firstWord = prefix.split(/\s+/)[0];
        let artcc = tryResolveArtcc(firstWord);
        if (artcc) return artcc;
        // Strategy 2: Try the full prefix (in case it's multi-word like "CZYZ")
        if (firstWord !== prefix) {
            artcc = tryResolveArtcc(prefix);
            if (artcc) return artcc;
        }
        // Strategy 3: Try the raw text itself (short entries like "ZLA", "SCT")
        if (upper !== firstWord) {
            artcc = tryResolveArtcc(upper);
            if (artcc) return artcc;
        }
        // Strategy 4: Scan all words for a recognizable facility code
        const words = upper.replace(/[^A-Z0-9\s]/g, '').split(/\s+/);
        for (const word of words) {
            if (word.length >= 2 && word.length <= 4) {
                artcc = tryResolveArtcc(word);
                if (artcc) return artcc;
            }
        }
        return null;
    }

    /**
     * Resolve a facility/airport code to its parent ARTCC group
     * @param {string} code - Airport code (BWI, CYYZ), TRACON code (SCT), ARTCC code (ZLA), or FIR code
     * @param {string} type - 'airport'|'terminal'|'enroute'|'dcc'
     * @returns {{ artcc: string, label: string, region: string, bgColor: string }}
     */
    function resolveGroup(code, type) {
        if (!code || !FacilityHierarchy.isLoaded) {
            return { artcc: '_OTHER', label: t('plan.tables.ungrouped'), region: null, bgColor: null };
        }

        const upper = code.toUpperCase().trim();
        let artcc = null;

        if (type === 'airport') {
            // Airport codes: 3-letter IATA (BWI, YYZ, ANC) or 4-letter ICAO (CYYZ, PANC)
            artcc = tryResolveArtcc(upper);
        } else if (type === 'terminal' || type === 'enroute') {
            // Freetext: "SCT - SoCal TRACON", "ZLA - Los Angeles ARTCC", "N90", etc.
            artcc = extractAndResolve(upper);
        } else if (type === 'dcc') {
            // Direct ARTCC/FIR codes (ZDC, CZYZ, ZEG) with alias resolution
            artcc = tryResolveArtcc(upper);
        }

        if (!artcc) {
            return { artcc: '_OTHER', label: t('plan.tables.ungrouped'), region: null, bgColor: null };
        }

        const region = FacilityHierarchy.getRegion(artcc);
        const bgColor = FacilityHierarchy.getRegionBgColor(artcc);
        const label = getArtccName(artcc);

        return { artcc, label, region, bgColor };
    }

    // ==============================
    // Sort & Group Helpers
    // ==============================

    function sortData(data, col, dir) {
        const mult = dir === 'asc' ? 1 : -1;
        return [...data].sort((a, b) => {
            let va = a[col], vb = b[col];
            if (va == null) va = '';
            if (vb == null) vb = '';
            // Numeric sort for numeric fields
            if (typeof va === 'number' && typeof vb === 'number') {
                return (va - vb) * mult;
            }
            return String(va).localeCompare(String(vb), undefined, { numeric: true, sensitivity: 'base' }) * mult;
        });
    }

    function groupByArtcc(data, codeField, type) {
        const groups = {};
        data.forEach(row => {
            const info = resolveGroup(row[codeField], type);
            row._group = info;
            if (!groups[info.artcc]) {
                groups[info.artcc] = { info, rows: [] };
            }
            groups[info.artcc].rows.push(row);
        });
        // Sort groups: _OTHER always last, then alphabetical
        const keys = Object.keys(groups).sort((a, b) => {
            if (a === '_OTHER') return 1;
            if (b === '_OTHER') return -1;
            return a.localeCompare(b);
        });
        return keys.map(k => groups[k]);
    }

    function buildGroupHeader(label, bgColor, colSpan) {
        const bg = bgColor ? ` style="background-color: ${bgColor};"` : '';
        return `<tr class="plan-group-header"${bg}><td colspan="${colSpan}" class="pl-2">${esc(label)}</td></tr>`;
    }

    function updateSortIcons(tableId, sortCol, sortDir) {
        const $table = $(`#${tableId}`).closest('table');
        $table.find('th.sortable').each(function() {
            const $th = $(this);
            const $icon = $th.find('.sort-icon');
            if ($th.data('sort') === sortCol) {
                $th.addClass('sort-active');
                $icon.removeClass('fa-sort fa-sort-up fa-sort-down')
                     .addClass(sortDir === 'asc' ? 'fa-sort-up' : 'fa-sort-down');
            } else {
                $th.removeClass('sort-active');
                $icon.removeClass('fa-sort-up fa-sort-down').addClass('fa-sort');
            }
        });
    }

    // ==============================
    // Table State
    // ==============================

    const state = {
        configs:          { data: [], perm: false, sortCol: 'airport',          sortDir: 'asc', grouped: true },
        termStaffing:     { data: [], perm: false, sortCol: 'facility_name',    sortDir: 'asc', grouped: true },
        enrouteStaffing:  { data: [], perm: false, sortCol: 'facility_name',    sortDir: 'asc', grouped: true },
        dccPersonnel:     { data: [], perm: false, sortCol: 'personnel_ois',    sortDir: 'asc', grouped: true },
        dccFacility:      { data: [], perm: false, sortCol: 'position_facility',sortDir: 'asc', grouped: true },
    };

    // Config set during init
    let config = {
        apiBase: 'api/data/plans',  // 'api/data/plans' for plan.php, 'api/data/sheet' for data.php
        p_id: '',
        hasDccPersonnel: true,      // plan.php has DCC Personnel table; data.php may not
    };

    // ==============================
    // Load Functions (Fetch JSON)
    // ==============================

    function loadConfigs() {
        return $.getJSON(`${config.apiBase}/configs?p_id=${config.p_id}`).then(function(resp) {
            state.configs.data = resp.rows || [];
            state.configs.perm = resp.perm !== undefined ? resp.perm : state.configs.perm;
            renderConfigs();
        });
    }

    function loadTermStaffing() {
        return $.getJSON(`${config.apiBase}/term_staffing?p_id=${config.p_id}`).then(function(resp) {
            state.termStaffing.data = resp.rows || [];
            state.termStaffing.perm = resp.perm !== undefined ? resp.perm : state.termStaffing.perm;
            renderTermStaffing();
        });
    }

    function loadEnrouteStaffing() {
        return $.getJSON(`${config.apiBase}/enroute_staffing?p_id=${config.p_id}`).then(function(resp) {
            state.enrouteStaffing.data = resp.rows || [];
            state.enrouteStaffing.perm = resp.perm !== undefined ? resp.perm : state.enrouteStaffing.perm;
            renderEnrouteStaffing();
        });
    }

    function loadDCCStaffing() {
        const promises = [];

        // Facility personnel (NOT command center)
        promises.push(
            $.getJSON(`${config.apiBase}/dcc_staffing?p_id=${config.p_id}`).then(function(resp) {
                state.dccFacility.data = resp.rows || [];
                state.dccFacility.perm = resp.perm !== undefined ? resp.perm : state.dccFacility.perm;
            })
        );

        // DCC/Command center personnel (only if table exists)
        if (config.hasDccPersonnel) {
            promises.push(
                $.getJSON(`${config.apiBase}/dcc_staffing?p_id=${config.p_id}&position_facility=DCC`).then(function(resp) {
                    state.dccPersonnel.data = resp.rows || [];
                    state.dccPersonnel.perm = resp.perm !== undefined ? resp.perm : state.dccPersonnel.perm;
                })
            );
        }

        return Promise.all(promises).then(function() {
            renderDCCFacility();
            if (config.hasDccPersonnel) renderDCCPersonnel();
        });
    }

    // ==============================
    // Render Functions
    // ==============================

    function renderConfigs() {
        const s = state.configs;
        const sorted = sortData(s.data, s.sortCol, s.sortDir);
        const colSpan = s.perm ? 8 : 7;
        let html = '';

        if (sorted.length === 0) {
            html = `<tr><td class="text-center" colspan="${colSpan}">${t('plan.tables.noConfigs')}</td></tr>`;
        } else if (s.grouped) {
            const groups = groupByArtcc(sorted, 'airport', 'airport');
            groups.forEach(g => {
                html += buildGroupHeader(g.info.label, g.info.bgColor, colSpan);
                const groupSorted = sortData(g.rows, s.sortCol, s.sortDir);
                groupSorted.forEach(row => { html += renderConfigRow(row, s.perm); });
            });
        } else {
            sorted.forEach(row => { html += renderConfigRow(row, s.perm); });
        }

        $('#configs_table').html(html);
        updateSortIcons('configs_table', s.sortCol, s.sortDir);
        if (typeof tooltips === 'function') tooltips();
    }

    function renderConfigRow(d, perm) {
        const w = WEATHER_MAP[d.weather] || WEATHER_MAP[0];
        const wBg = w.color ? ` background-color: ${w.color};` : '';
        let html = '<tr>';
        html += `<td class="text-center" style="width: 10%;">${esc(d.airport)}</td>`;
        html += `<td class="text-center" style="width: 10%;${wBg}">${esc(w.label)}</td>`;
        html += `<td class="text-center" style="width: 15%;">${esc(d.arrive)}</td>`;
        html += `<td class="text-center" style="width: 15%;">${esc(d.depart)}</td>`;
        html += `<td class="text-center" style="width: 10%;">${esc(d.aar)}</td>`;
        html += `<td class="text-center" style="width: 10%;">${esc(d.adr)}</td>`;
        html += `<td class="text-center">${esc(d.comments)}</td>`;
        if (perm) {
            html += '<td style="width: 15%;"><center>';
            if (d.has_autofill) {
                html += `<a href="javascript:void(0)" onclick="autoConfig(\`${d.id}\`, \`${esc(d.autofill_aar)}\`, \`${esc(d.autofill_adr)}\`)"><span class="badge badge-info"><i class="fas fa-robot"></i> Autofill</span></a> `;
            } else {
                html += '<a href="javascript:void(0)"><span class="badge badge-secondary"><i class="fas fa-robot"></i> <s>Autofill</s></span></a> ';
            }
            html += `<a href="javascript:void(0)" data-toggle="tooltip" title="${t('plan.tables.editConfig')}"><span class="badge badge-warning" data-toggle="modal" data-target="#editconfigModal" data-id="${d.id}" data-airport="${esc(d.airport)}" data-weather="${d.weather}" data-depart="${esc(d.depart)}" data-arrive="${esc(d.arrive)}" data-aar="${esc(d.aar)}" data-adr="${esc(d.adr)}" data-comments="${esc(d.comments)}"><i class="fas fa-pencil-alt"></i> ${t('common.edit')}</span></a> `;
            html += `<a href="javascript:void(0)" onclick="deleteConfig(${d.id})" data-toggle="tooltip" title="${t('plan.tables.deleteConfig')}"><span class="badge badge-danger"><i class="fas fa-times"></i> ${t('common.delete')}</span></a>`;
            html += '</center></td>';
        }
        html += '</tr>';
        return html;
    }

    function renderTermStaffing() {
        const s = state.termStaffing;
        const sorted = sortData(s.data, s.sortCol, s.sortDir);
        const colSpan = s.perm ? 5 : 4;
        let html = '';

        if (sorted.length === 0) {
            html = `<tr><td class="text-center" colspan="${colSpan}">${t('plan.tables.noStaffing')}</td></tr>`;
        } else if (s.grouped) {
            const groups = groupByArtcc(sorted, 'facility_name', 'terminal');
            groups.forEach(g => {
                html += buildGroupHeader(g.info.label, g.info.bgColor, colSpan);
                const groupSorted = sortData(g.rows, s.sortCol, s.sortDir);
                groupSorted.forEach(row => { html += renderStaffingRow(row, s.perm, TERM_STATUS_MAP, 'edittermstaffingModal', 'deleteTermStaffing'); });
            });
        } else {
            sorted.forEach(row => { html += renderStaffingRow(row, s.perm, TERM_STATUS_MAP, 'edittermstaffingModal', 'deleteTermStaffing'); });
        }

        $('#term_staffing_table').html(html);
        updateSortIcons('term_staffing_table', s.sortCol, s.sortDir);
        if (typeof tooltips === 'function') tooltips();
    }

    function renderEnrouteStaffing() {
        const s = state.enrouteStaffing;
        const sorted = sortData(s.data, s.sortCol, s.sortDir);
        const colSpan = s.perm ? 5 : 4;
        let html = '';

        if (sorted.length === 0) {
            html = `<tr><td class="text-center" colspan="${colSpan}">${t('plan.tables.noStaffing')}</td></tr>`;
        } else if (s.grouped) {
            const groups = groupByArtcc(sorted, 'facility_name', 'enroute');
            groups.forEach(g => {
                html += buildGroupHeader(g.info.label, g.info.bgColor, colSpan);
                const groupSorted = sortData(g.rows, s.sortCol, s.sortDir);
                groupSorted.forEach(row => { html += renderStaffingRow(row, s.perm, ENROUTE_STATUS_MAP, 'editenroutestaffingModal', 'deleteEnrouteStaffing'); });
            });
        } else {
            sorted.forEach(row => { html += renderStaffingRow(row, s.perm, ENROUTE_STATUS_MAP, 'editenroutestaffingModal', 'deleteEnrouteStaffing'); });
        }

        $('#enroute_staffing_table').html(html);
        updateSortIcons('enroute_staffing_table', s.sortCol, s.sortDir);
        if (typeof tooltips === 'function') tooltips();
    }

    function renderStaffingRow(d, perm, statusMap, editModal, deleteFn) {
        const st = statusMap[d.staffing_status] || statusMap[0];
        const stBg = st.color ? ` background-color: ${st.color};` : '';
        const stClass = st.cssClass || '';
        let html = '<tr>';
        html += `<td class="text-center" style="width: 40%;">${esc(d.facility_name)}</td>`;
        html += `<td class="text-center ${stClass}" style="width: 10%;${stBg}">${t(st.label)}</td>`;
        html += `<td class="text-center" style="width: 10%;">${esc(d.staffing_quantity)}</td>`;
        html += `<td class="text-center">${esc(d.comments)}</td>`;
        if (perm) {
            html += '<td style="width: 10%;"><center>';
            html += `<a href="javascript:void(0)" data-toggle="tooltip" title="${t('plan.tables.editStaffing')}"><span class="badge badge-warning" data-toggle="modal" data-target="#${editModal}" data-id="${d.id}" data-facility_name="${esc(d.facility_name)}" data-staffing_status="${d.staffing_status}" data-staffing_quantity="${esc(d.staffing_quantity)}" data-comments="${esc(d.comments)}"><i class="fas fa-pencil-alt"></i> ${t('common.edit')}</span></a> `;
            html += `<a href="javascript:void(0)" onclick="${deleteFn}(${d.id})" data-toggle="tooltip" title="${t('plan.tables.deleteStaffing')}"><span class="badge badge-danger"><i class="fas fa-times"></i> ${t('common.delete')}</span></a>`;
            html += '</center></td>';
        }
        html += '</tr>';
        return html;
    }

    function renderDCCPersonnel() {
        const s = state.dccPersonnel;
        const sorted = sortData(s.data, s.sortCol, s.sortDir);
        const colSpan = s.perm ? 4 : 3;
        let html = '';

        if (sorted.length === 0) {
            html = `<tr><td class="text-center" colspan="${colSpan}">${t('plan.tables.noPersonnel')}</td></tr>`;
        } else if (s.grouped) {
            const groups = groupByArtcc(sorted, 'position_facility', 'dcc');
            groups.forEach(g => {
                html += buildGroupHeader(g.info.label, g.info.bgColor, colSpan);
                const groupSorted = sortData(g.rows, s.sortCol, s.sortDir);
                groupSorted.forEach(row => { html += renderDCCPersonnelRow(row, s.perm); });
            });
        } else {
            sorted.forEach(row => { html += renderDCCPersonnelRow(row, s.perm); });
        }

        $('#dcc_table').html(html);
        updateSortIcons('dcc_table', s.sortCol, s.sortDir);
        if (typeof tooltips === 'function') tooltips();
    }

    function renderDCCPersonnelRow(d, perm) {
        let html = '<tr>';
        html += `<td class="text-center" style="width: 10%;">${esc(d.personnel_ois)}</td>`;
        html += `<td>${esc(d.personnel_name)}</td>`;
        html += `<td>${esc(d.position_name)}</td>`;
        if (perm) {
            html += '<td class="w-25"><center>';
            html += `<a href="javascript:void(0)" data-toggle="tooltip" title="${t('plan.dcc.editPersonnel')}"><span class="badge badge-warning" data-toggle="modal" data-target="#edit_dccstaffingModal" data-id="${d.id}" data-personnel_name="${esc(d.personnel_name)}" data-personnel_ois="${esc(d.personnel_ois)}" data-position_name="${esc(d.position_name)}" data-position_facility="${esc(d.position_facility)}"><i class="fas fa-pencil-alt"></i> ${t('common.edit')}</span></a> `;
            html += `<a href="javascript:void(0)" onclick="deleteDCCStaffing(${d.id})" data-toggle="tooltip" title="${t('plan.tables.deletePersonnel')}"><span class="badge badge-danger"><i class="fas fa-times"></i> ${t('common.delete')}</span></a>`;
            html += '</center></td>';
        }
        html += '</tr>';
        return html;
    }

    function renderDCCFacility() {
        const s = state.dccFacility;
        const sorted = sortData(s.data, s.sortCol, s.sortDir);
        const colSpan = s.perm ? 4 : 3;
        let html = '';

        if (sorted.length === 0) {
            html = `<tr><td class="text-center" colspan="${colSpan}">${t('plan.tables.noPersonnel')}</td></tr>`;
        } else if (s.grouped) {
            const groups = groupByArtcc(sorted, 'position_facility', 'dcc');
            groups.forEach(g => {
                html += buildGroupHeader(g.info.label, g.info.bgColor, colSpan);
                const groupSorted = sortData(g.rows, s.sortCol, s.sortDir);
                groupSorted.forEach(row => { html += renderDCCFacilityRow(row, s.perm); });
            });
        } else {
            sorted.forEach(row => { html += renderDCCFacilityRow(row, s.perm); });
        }

        $('#dcc_staffing_table').html(html);
        updateSortIcons('dcc_staffing_table', s.sortCol, s.sortDir);
        if (typeof tooltips === 'function') tooltips();
    }

    function renderDCCFacilityRow(d, perm) {
        let html = '<tr>';
        html += `<td class="text-center" style="width: 10%;">${esc(d.position_facility)}</td>`;
        html += `<td class="text-center" style="width: 10%;">${esc(d.personnel_ois)}</td>`;
        html += `<td>${esc(d.personnel_name)}</td>`;
        if (perm) {
            html += '<td class="w-25"><center>';
            html += `<a href="javascript:void(0)" data-toggle="tooltip" title="${t('plan.dcc.editPersonnel')}"><span class="badge badge-warning" data-toggle="modal" data-target="#edit_dccstaffingModal" data-id="${d.id}" data-personnel_name="${esc(d.personnel_name)}" data-personnel_ois="${esc(d.personnel_ois)}" data-position_name="${esc(d.position_name)}" data-position_facility="${esc(d.position_facility)}"><i class="fas fa-pencil-alt"></i> ${t('common.edit')}</span></a> `;
            html += `<a href="javascript:void(0)" onclick="deleteDCCStaffing(${d.id})" data-toggle="tooltip" title="${t('plan.tables.deletePersonnel')}"><span class="badge badge-danger"><i class="fas fa-times"></i> ${t('common.delete')}</span></a>`;
            html += '</center></td>';
        }
        html += '</tr>';
        return html;
    }

    // ==============================
    // Sort Click Handler
    // ==============================

    function bindSortHandlers() {
        $(document).on('click', 'th.sortable[data-table]', function() {
            const $th = $(this);
            const col = $th.data('sort');
            const tableKey = $th.data('table');

            const stateMap = {
                configs: state.configs,
                termStaffing: state.termStaffing,
                enrouteStaffing: state.enrouteStaffing,
                dccPersonnel: state.dccPersonnel,
                dccFacility: state.dccFacility,
            };
            const renderMap = {
                configs: renderConfigs,
                termStaffing: renderTermStaffing,
                enrouteStaffing: renderEnrouteStaffing,
                dccPersonnel: renderDCCPersonnel,
                dccFacility: renderDCCFacility,
            };

            const s = stateMap[tableKey];
            if (!s) return;

            if (s.sortCol === col) {
                s.sortDir = s.sortDir === 'asc' ? 'desc' : 'asc';
            } else {
                s.sortCol = col;
                s.sortDir = 'asc';
            }

            const render = renderMap[tableKey];
            if (render) render();
        });
    }

    // ==============================
    // Group Toggle
    // ==============================

    function bindGroupToggle() {
        $(document).on('click', '.plan-group-toggle', function() {
            const tableKey = $(this).data('table');
            const stateMap = {
                configs: state.configs,
                termStaffing: state.termStaffing,
                enrouteStaffing: state.enrouteStaffing,
                dccPersonnel: state.dccPersonnel,
                dccFacility: state.dccFacility,
            };
            const renderMap = {
                configs: renderConfigs,
                termStaffing: renderTermStaffing,
                enrouteStaffing: renderEnrouteStaffing,
                dccPersonnel: renderDCCPersonnel,
                dccFacility: renderDCCFacility,
            };

            const s = stateMap[tableKey];
            if (!s) return;

            s.grouped = !s.grouped;

            // Update button text
            const $btn = $(this);
            if (s.grouped) {
                $btn.html(`<i class="fas fa-list"></i> ${t('plan.tables.flatView')}`);
            } else {
                $btn.html(`<i class="fas fa-layer-group"></i> ${t('plan.tables.groupByArtcc')}`);
            }

            // Persist preference
            try { localStorage.setItem(`planTable_grouped_${tableKey}`, s.grouped ? '1' : '0'); } catch(e) {}

            const render = renderMap[tableKey];
            if (render) render();
        });
    }

    function restoreGroupPrefs() {
        ['configs', 'termStaffing', 'enrouteStaffing', 'dccPersonnel', 'dccFacility'].forEach(key => {
            try {
                const stored = localStorage.getItem(`planTable_grouped_${key}`);
                if (stored !== null) {
                    state[key].grouped = stored === '1';
                }
            } catch(e) {}
        });
        // Update toggle button text to match restored state
        $('.plan-group-toggle').each(function() {
            const $btn = $(this);
            const tableKey = $btn.data('table');
            const s = state[tableKey];
            if (s) {
                if (s.grouped) {
                    $btn.html(`<i class="fas fa-list"></i> ${t('plan.tables.flatView')}`);
                } else {
                    $btn.html(`<i class="fas fa-layer-group"></i> ${t('plan.tables.groupByArtcc')}`);
                }
            }
        });
    }

    // ==============================
    // Init
    // ==============================

    async function init(opts) {
        config = Object.assign(config, opts || {});

        // Ensure FacilityHierarchy is loaded
        if (typeof FacilityHierarchy !== 'undefined' && FacilityHierarchy.load) {
            await FacilityHierarchy.load();
        }

        bindSortHandlers();
        bindGroupToggle();
        restoreGroupPrefs();

        // Load all 4 table types in parallel
        return Promise.all([
            loadConfigs(),
            loadTermStaffing(),
            loadEnrouteStaffing(),
            loadDCCStaffing(),
        ]);
    }

    // ==============================
    // Export
    // ==============================

    // ==============================
    // Splits Overview (read-only summary for plan/data pages)
    // ==============================

    var _splitsLoaded = false;

    function loadSplitsOverview() {
        var container = document.getElementById('plan_splits_container');
        if (!container) return Promise.resolve();

        // Build URL with event-period overlap filter when available
        var url = 'api/splits/active.php?include_scheduled=1';
        var eventStart = (typeof PERTI_EVENT_START_ISO !== 'undefined') ? PERTI_EVENT_START_ISO : null;
        var eventEnd = (typeof PERTI_EVENT_END_ISO !== 'undefined') ? PERTI_EVENT_END_ISO : null;
        if (eventStart && eventEnd) {
            url += '&from=' + encodeURIComponent(eventStart) + '&to=' + encodeURIComponent(eventEnd);
        }

        return $.getJSON(url).done(function(data) {
            var configs = data.configs || [];
            _splitsLoaded = true;

            if (configs.length === 0) {
                container.innerHTML =
                    '<div class="text-center text-muted py-4">' +
                    '<i class="fas fa-info-circle mr-1"></i> ' +
                    t('plan.splits.noActiveConfigs') +
                    '</div>';
                return;
            }

            renderSplitsCards(container, configs);
        }).fail(function() {
            if (container) {
                container.innerHTML =
                    '<div class="text-center text-danger py-4">' +
                    '<i class="fas fa-exclamation-triangle mr-1"></i> ' +
                    t('plan.splits.loadError') +
                    '</div>';
            }
        });
    }

    function renderSplitsCards(container, configs) {
        // Group by ARTCC
        var byArtcc = {};
        configs.forEach(function(cfg) {
            var artcc = cfg.artcc || 'Unknown';
            if (!byArtcc[artcc]) byArtcc[artcc] = [];
            byArtcc[artcc].push(cfg);
        });

        var html = '<div class="row">';
        var artccs = Object.keys(byArtcc).sort();

        artccs.forEach(function(artcc) {
            var artccConfigs = byArtcc[artcc];
            var artccLabel = getArtccName(artcc);

            html += '<div class="col-md-6 col-lg-4 mb-3">';
            html += '<div class="card h-100">';
            html += '<div class="card-header py-2 d-flex justify-content-between align-items-center">';
            html += '<strong>' + artccLabel + '</strong>';
            html += '<span class="badge badge-secondary">' + artcc + '</span>';
            html += '</div>';
            html += '<div class="card-body py-2">';

            artccConfigs.forEach(function(cfg, idx) {
                if (idx > 0) html += '<hr class="my-2">';

                // Config header: name + status badge + timing
                var statusClass = cfg.status === 'active' ? 'badge-success' : 'badge-info';
                var statusLabel = cfg.status === 'active' ? t('plan.splits.active') : t('plan.splits.scheduled');

                html += '<div class="d-flex justify-content-between align-items-center mb-1">';
                html += '<span class="font-weight-bold">' + escapeHtml(cfg.config_name) + '</span>';
                html += '<span class="badge ' + statusClass + '">' + statusLabel + '</span>';
                html += '</div>';

                // Timing
                if (cfg.start_time_utc || cfg.end_time_utc) {
                    var start = cfg.start_time_utc ? formatSplitsTime(cfg.start_time_utc) : '';
                    var end = cfg.end_time_utc ? formatSplitsTime(cfg.end_time_utc) : '';
                    if (start || end) {
                        html += '<div class="text-muted small mb-1">';
                        html += '<i class="fas fa-clock mr-1"></i>';
                        html += (start || '?') + ' - ' + (end || t('plan.splits.indefinite'));
                        html += '</div>';
                    }
                }

                // Positions
                var positions = cfg.positions || [];
                if (positions.length === 0) {
                    html += '<div class="text-muted small">' + t('plan.splits.noPositions') + '</div>';
                } else {
                    html += '<div class="d-flex flex-wrap">';
                    positions.forEach(function(pos) {
                        var sectorCount = (pos.sectors || []).length;
                        var color = pos.color || '#808080';
                        html += '<span class="mr-3 mb-1 small" title="' + sectorCount + ' ' + t('plan.splits.sectors') + '">';
                        html += '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' + color + ';margin-right:4px;vertical-align:middle;"></span>';
                        html += escapeHtml(pos.position_name);
                        html += ' <span class="text-muted">(' + sectorCount + ')</span>';
                        html += '</span>';
                    });
                    html += '</div>';
                }
            });

            html += '</div></div></div>';
        });

        html += '</div>';
        container.innerHTML = html;
    }

    function formatSplitsTime(isoStr) {
        if (!isoStr) return '';
        try {
            var d = new Date(isoStr.endsWith('Z') ? isoStr : isoStr + 'Z');
            if (isNaN(d.getTime())) return isoStr;
            var hh = String(d.getUTCHours()).padStart(2, '0');
            var mm = String(d.getUTCMinutes()).padStart(2, '0');
            return hh + mm + 'Z';
        } catch (e) {
            return isoStr;
        }
    }

    function escapeHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    global.PlanTables = {
        init,
        state,

        // Individual load+render (for after add/edit/delete)
        loadConfigs,
        loadTermStaffing,
        loadEnrouteStaffing,
        loadDCCStaffing,

        // Re-render only (no fetch)
        renderConfigs,
        renderTermStaffing,
        renderEnrouteStaffing,
        renderDCCPersonnel,
        renderDCCFacility,

        // Splits overview (read-only)
        loadSplitsOverview,

        // Utility
        resolveGroup,
    };

})(window);
