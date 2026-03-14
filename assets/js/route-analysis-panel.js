/**
 * Route Analysis Panel — below-map interactive panel
 *
 * Renders facility traversal and fix analysis tables from the
 * /api/data/playbook/analysis.php endpoint. Provides click-to-zoom,
 * map highlighting, and multi-format export.
 *
 * @requires PERTII18n (i18n)
 * @requires window.MapLibreRoute (route-maplibre.js)
 */
(function () {
    'use strict';

    var t = function (key, vars) {
        if (typeof PERTII18n !== 'undefined') return PERTII18n.t(key, vars);
        return key.split('.').pop();
    };

    // ── State ────────────────────────────────────────────────────────
    var currentData = null;
    var currentRouteStr = null;
    var currentOrigin = null;
    var currentDest = null;
    var currentRouteId = null;
    var activeRowFacility = null;
    var activeRowFix = null;
    var facilityFilters = { ARTCC: true, FIR: true, TRACON: true, SECTOR_HIGH: true, SECTOR_LOW: true, SECTOR_SUPERHIGH: true };
    var timeFormat = 'hms'; // 'hms' = hh:mm:ss, 'short' = 1h 23m, 'min' = integer minutes

    // ── DOM refs (resolved on first show) ────────────────────────────
    var panel, header, toggle, body, routeLabel, chevron;
    var summaryEl, facTbody, fixTbody, segTbody, facFiltersEl;
    var speedInput, windInput, depTimeInput;
    var exportMenu;
    var pickerOrigin, pickerDest, pickerRoute, pickerGo, pickerMatches;

    function resolveDOM() {
        panel       = document.getElementById('route-analysis-panel');
        toggle      = document.getElementById('ra-toggle');
        body        = document.getElementById('ra-body');
        routeLabel  = document.getElementById('ra-route-label');
        chevron     = panel ? panel.querySelector('.ra-chevron') : null;
        summaryEl   = document.getElementById('ra-summary');
        facTbody    = document.getElementById('ra-facility-tbody');
        fixTbody    = document.getElementById('ra-fix-tbody');
        segTbody    = document.getElementById('ra-segment-tbody');
        speedInput  = document.getElementById('ra-cruise-speed');
        windInput   = document.getElementById('ra-wind');
        depTimeInput = document.getElementById('ra-dep-time');
        facFiltersEl = document.getElementById('ra-facility-filters');
        exportMenu  = document.getElementById('ra-export-menu');
        pickerOrigin  = document.getElementById('ra-picker-origin');
        pickerDest    = document.getElementById('ra-picker-dest');
        pickerRoute   = document.getElementById('ra-picker-route');
        pickerGo      = document.getElementById('ra-picker-go');
        pickerMatches = document.getElementById('ra-picker-matches');
    }

    // ── Time formatting ──────────────────────────────────────────────
    function formatTime(minutes) {
        if (minutes == null || isNaN(minutes)) return '--';
        if (timeFormat === 'min') {
            return Math.round(minutes) + 'm';
        }
        if (timeFormat === 'short') {
            minutes = Math.round(minutes);
            if (minutes < 1)  return '< 1m';
            if (minutes < 60) return minutes + 'm';
            var hrs = Math.floor(minutes / 60);
            var mins = minutes % 60;
            return mins === 0 ? hrs + 'h' : hrs + 'h ' + mins + 'm';
        }
        // Default: hh:mm:ss
        var totalSec = Math.round(minutes * 60);
        var h = Math.floor(totalSec / 3600);
        var m = Math.floor((totalSec % 3600) / 60);
        var s = totalSec % 60;
        return (h < 10 ? '0' : '') + h + ':' + (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
    }

    function formatDist(nm) {
        if (nm == null || isNaN(nm)) return '--';
        // 2 significant figures, no thousand separators
        if (nm === 0) return '0';
        var mag = Math.floor(Math.log10(Math.abs(nm))) + 1;
        var factor = Math.pow(10, 2 - mag);
        var rounded = Math.round(nm * factor) / factor;
        var decimals = Math.max(0, 2 - mag);
        return decimals > 0 ? rounded.toFixed(decimals) : String(rounded);
    }

    // ── Departure time / UTC helpers ───────────────────────────────
    function getDepartureEpoch() {
        var val = depTimeInput ? depTimeInput.value.trim() : '';
        if (!val) return Date.now();
        var parts = val.match(/^(\d{1,2}):(\d{2})$/);
        if (!parts) return Date.now();
        var now = new Date();
        var dep = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate(),
                                    parseInt(parts[1], 10), parseInt(parts[2], 10), 0));
        return dep.getTime();
    }

    function minutesToUtcStr(depEpoch, minutes) {
        if (minutes == null || isNaN(minutes)) return '--:--Z';
        var d = new Date(depEpoch + minutes * 60000);
        if (typeof PERTIDateTime !== 'undefined' && PERTIDateTime.formatTimeShortZ) {
            return PERTIDateTime.formatTimeShortZ(d);
        }
        return d.toISOString().slice(11, 16) + 'Z';
    }

    // Deduplicate fix/waypoint arrays — nav_fixes has multiple entries per fix
    function deduplicateFixes(arr) {
        if (!arr || arr.length === 0) return arr;
        var seen = {};
        var result = [];
        for (var i = 0; i < arr.length; i++) {
            var key = (arr[i].fix || '') + '|' + Math.round((arr[i].dist_from_origin_nm || arr[i].cum_dist_nm || 0) * 10);
            if (!seen[key]) {
                seen[key] = true;
                result.push(arr[i]);
            }
        }
        return result;
    }

    // ── Facility type display helpers ────────────────────────────────
    var typeLabels = {
        'ARTCC': 'ARTCC',
        'FIR': 'FIR',
        'TRACON': 'TRACON',
        'SECTOR_HIGH': 'Sector (High)',
        'SECTOR_LOW': 'Sector (Low)',
        'SECTOR_SUPERHIGH': 'Sector (Super)'
    };

    function typeBadgeClass(type) {
        return 'ra-type-' + (type || '').toLowerCase();
    }

    function typeLabel(type) {
        return typeLabels[type] || type || '';
    }

    // Pill style colors (match badge colors)
    var pillColors = {
        'ARTCC': { bg: 'rgba(35,155,205,0.2)', border: 'rgba(35,155,205,0.5)', color: '#239BCD' },
        'FIR': { bg: 'rgba(108,117,125,0.3)', border: 'rgba(108,117,125,0.6)', color: '#adb5bd' },
        'TRACON': { bg: 'rgba(255,193,7,0.2)', border: 'rgba(255,193,7,0.5)', color: '#ffc107' },
        'SECTOR_HIGH': { bg: 'rgba(40,167,69,0.2)', border: 'rgba(40,167,69,0.5)', color: '#28a745' },
        'SECTOR_LOW': { bg: 'rgba(220,53,69,0.2)', border: 'rgba(220,53,69,0.5)', color: '#dc3545' },
        'SECTOR_SUPERHIGH': { bg: 'rgba(111,66,193,0.2)', border: 'rgba(111,66,193,0.5)', color: '#6f42c1' }
    };

    // ── Facility filter pills ─────────────────────────────────────────
    function renderFacilityFilters(traversal) {
        if (!facFiltersEl) return;
        var counts = {};
        for (var i = 0; i < traversal.length; i++) {
            var tp = traversal[i].type || 'OTHER';
            counts[tp] = (counts[tp] || 0) + 1;
        }
        var types = Object.keys(counts);
        if (types.length <= 1) { facFiltersEl.innerHTML = ''; return; }

        var html = '';
        var order = ['ARTCC', 'FIR', 'SECTOR_SUPERHIGH', 'SECTOR_HIGH', 'SECTOR_LOW', 'TRACON'];
        for (var o = 0; o < order.length; o++) {
            var typ = order[o];
            if (!counts[typ]) continue;
            var pc = pillColors[typ] || pillColors['FIR'];
            var active = facilityFilters[typ] !== false;
            html += '<span class="ra-filter-pill' + (active ? ' active' : '') + '" data-type="' + typ + '"' +
                ' style="background:' + pc.bg + '; border-color:' + pc.border + '; color:' + pc.color + ';">' +
                typeLabel(typ) + ' <span class="ra-pill-count">' + counts[typ] + '</span></span>';
        }
        facFiltersEl.innerHTML = html;

        var pills = facFiltersEl.querySelectorAll('.ra-filter-pill');
        for (var p = 0; p < pills.length; p++) {
            pills[p].addEventListener('click', function (e) {
                e.stopPropagation();
                var typ2 = this.getAttribute('data-type');
                facilityFilters[typ2] = !facilityFilters[typ2];
                this.classList.toggle('active', facilityFilters[typ2]);
                refreshTimeline();
            });
        }
    }

    function filterTraversal(traversal) {
        if (!traversal) return [];
        return traversal.filter(function (f) {
            return facilityFilters[f.type] !== false;
        });
    }

    // ── Loading state ───────────────────────────────────────────────
    function showLoading(routeStr, origin, dest) {
        resolveDOM();
        if (!panel) return;

        // Set route label
        var label = '';
        if (origin) label += origin;
        if (dest)   label += ' \u2192 ' + dest;
        if (!label && routeStr) {
            label = routeStr.length > 80 ? routeStr.substring(0, 77) + '...' : routeStr;
        }
        if (routeLabel) routeLabel.textContent = label;

        // Show panel and expand
        panel.style.display = 'block';
        if (body) body.style.display = 'block';
        if (chevron) chevron.classList.remove('collapsed');

        // Show spinners in summary and tables
        if (summaryEl) summaryEl.innerHTML = '';
        if (facFiltersEl) facFiltersEl.innerHTML = '';
        if (facTbody) facTbody.innerHTML = '<tr><td colspan="8" class="ra-loading"><i class="fas fa-spinner fa-spin"></i> ' + t('routeAnalysis.loading') + '</td></tr>';
        if (fixTbody) fixTbody.innerHTML = '<tr><td colspan="9" class="ra-loading"><i class="fas fa-spinner fa-spin"></i> ' + t('routeAnalysis.loading') + '</td></tr>';
        if (segTbody) segTbody.innerHTML = '<tr><td colspan="10" class="ra-loading"><i class="fas fa-spinner fa-spin"></i> ' + t('routeAnalysis.loading') + '</td></tr>';

        panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function showError(msg) {
        resolveDOM();
        if (!panel) return;
        var errMsg = msg || t('routeAnalysis.error') || 'Analysis failed';
        if (facTbody) facTbody.innerHTML = '<tr><td colspan="8" class="ra-empty"><i class="fas fa-exclamation-triangle"></i> ' + errMsg + '</td></tr>';
        if (fixTbody) fixTbody.innerHTML = '<tr><td colspan="9" class="ra-empty"><i class="fas fa-exclamation-triangle"></i> ' + errMsg + '</td></tr>';
        if (segTbody) segTbody.innerHTML = '<tr><td colspan="10" class="ra-empty"><i class="fas fa-exclamation-triangle"></i> ' + errMsg + '</td></tr>';
    }

    // ── Rendering ────────────────────────────────────────────────────
    function show(data, routeStr, origin, dest, routeId) {
        resolveDOM();
        if (!panel) return;

        currentData = data;
        currentRouteStr = routeStr || null;
        currentOrigin = origin || null;
        currentDest = dest || null;
        currentRouteId = routeId || null;

        // Clear picker when new analysis shown (fresh selection)
        clearPicker();

        // Set route label
        var label = '';
        if (origin) label += origin;
        if (dest)   label += ' \u2192 ' + dest;
        if (!label && routeStr) {
            label = routeStr.length > 80 ? routeStr.substring(0, 77) + '...' : routeStr;
        }
        if (routeLabel) routeLabel.textContent = label;

        // Show panel and expand
        panel.style.display = 'block';
        if (body) body.style.display = 'block';
        if (chevron) chevron.classList.remove('collapsed');

        // Set speed/wind from response if available
        if (speedInput && data.speed_profile) {
            speedInput.value = data.speed_profile.cruise_kts || 460;
        }
        if (windInput && data.wind_profile) {
            windInput.value = data.wind_profile.component_kts || 0;
        }

        // Deduplicate fix_analysis (nav_fixes can have multiple entries per fix name)
        if (data.fix_analysis) {
            data.fix_analysis = deduplicateFixes(data.fix_analysis);
        }
        // Deduplicate waypoints similarly
        if (data.waypoints) {
            data.waypoints = deduplicateFixes(data.waypoints);
        }

        var depEpoch = getDepartureEpoch();
        var traversal = data.facility_traversal || [];
        renderSummary(data, depEpoch);
        renderFacilityFilters(traversal);
        renderFacilityTable(filterTraversal(traversal), depEpoch);
        renderFixTable(data.fix_analysis || [], data.total_distance_nm, data.total_time_min, depEpoch);
        renderSegmentTable(data.fix_analysis || [], depEpoch);

        // Scroll into view
        panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

        // Trigger map highlighting
        highlightOnMap(data);
    }

    function renderSummary(data, depEpoch) {
        if (!summaryEl) return;
        var facilities = data.facility_traversal || [];
        var fixes = data.fix_analysis || [];
        var etdStr = minutesToUtcStr(depEpoch, 0);
        var etaStr = minutesToUtcStr(depEpoch, data.total_time_min);
        var html =
            '<div class="ra-stat-card">' +
                '<div class="ra-stat-value">' + formatDist(data.total_distance_nm) + '</div>' +
                '<div class="ra-stat-label">' + t('routeAnalysis.stat.totalDist') + ' (nm)</div>' +
            '</div>' +
            '<div class="ra-stat-card">' +
                '<div class="ra-stat-value">' + formatTime(data.total_time_min) + '</div>' +
                '<div class="ra-stat-label">' + t('routeAnalysis.stat.totalTime') + '</div>' +
            '</div>' +
            '<div class="ra-stat-card">' +
                '<div class="ra-stat-value ra-utc">' + etdStr + '</div>' +
                '<div class="ra-stat-label">' + t('routeAnalysis.stat.etd') + '</div>' +
            '</div>' +
            '<div class="ra-stat-card">' +
                '<div class="ra-stat-value ra-utc">' + etaStr + '</div>' +
                '<div class="ra-stat-label">' + t('routeAnalysis.stat.eta') + '</div>' +
            '</div>' +
            '<div class="ra-stat-card">' +
                '<div class="ra-stat-value">' + facilities.length + '</div>' +
                '<div class="ra-stat-label">' + t('routeAnalysis.stat.facilities') + '</div>' +
            '</div>' +
            '<div class="ra-stat-card">' +
                '<div class="ra-stat-value">' + fixes.length + '</div>' +
                '<div class="ra-stat-label">' + t('routeAnalysis.stat.fixes') + '</div>' +
            '</div>';

        // Route strings (as-filed and expanded)
        if (data.route_string) {
            html += '<div class="ra-route-string-row">' +
                '<span class="ra-route-string-label">Route (filed)</span>' +
                '<div class="ra-route-string-value">' + escHtml(data.route_string) + '</div>' +
                '<button class="ra-copy-btn" title="Copy" data-copy="route_filed"><i class="fas fa-copy"></i></button>' +
                '</div>';
        }
        if (data.expanded_route_string) {
            html += '<div class="ra-route-string-row">' +
                '<span class="ra-route-string-label">Route (expanded)</span>' +
                '<div class="ra-route-string-value">' + escHtml(data.expanded_route_string) + '</div>' +
                '<button class="ra-copy-btn" title="Copy" data-copy="route_expanded"><i class="fas fa-copy"></i></button>' +
                '</div>';
        }

        summaryEl.innerHTML = html;

        // Bind copy buttons
        summaryEl.querySelectorAll('.ra-copy-btn').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                var which = this.getAttribute('data-copy');
                var text = which === 'route_filed' ? data.route_string : data.expanded_route_string;
                if (text && navigator.clipboard) {
                    navigator.clipboard.writeText(text).then(function () {
                        if (typeof PERTIDialog !== 'undefined') PERTIDialog.toast('Copied');
                    });
                }
            });
        });
    }

    function renderFacilityTable(traversal, depEpoch) {
        if (!facTbody) return;
        if (!traversal || traversal.length === 0) {
            facTbody.innerHTML = '<tr><td colspan="8" class="ra-empty">' + t('routeAnalysis.noData') + '</td></tr>';
            return;
        }
        var html = '';
        for (var i = 0; i < traversal.length; i++) {
            var f = traversal[i];
            html += '<tr data-idx="' + i + '">' +
                '<td class="ra-idx">' + (i + 1) + '</td>' +
                '<td>' + escHtml(f.name || f.id || '') + '</td>' +
                '<td><span class="ra-type-badge ' + typeBadgeClass(f.type) + '">' + typeLabel(f.type) + '</span></td>' +
                '<td class="text-right">' + formatDist(f.distance_within_nm) + '</td>' +
                '<td class="text-right">' + formatTime(f.time_within_min) + '</td>' +
                '<td class="text-right ra-utc">' + minutesToUtcStr(depEpoch, f.entry_time_min) + '</td>' +
                '<td class="text-right ra-utc">' + minutesToUtcStr(depEpoch, f.exit_time_min) + '</td>' +
                '<td class="text-right ra-seg-delta">' + (f.entry_fix || '') + ' \u2192 ' + (f.exit_fix || '') + '</td>' +
                '</tr>';
        }
        facTbody.innerHTML = html;

        // Attach click handlers
        var rows = facTbody.querySelectorAll('tr');
        for (var r = 0; r < rows.length; r++) {
            rows[r].addEventListener('click', (function (idx) {
                return function () { zoomToFacility(traversal[idx], idx); };
            })(r));
        }
    }

    // Client-side facility lookup for a fix based on its cumulative distance
    function findFacilitiesForDist(dist_nm) {
        if (!currentData || !currentData.facility_traversal) return [];
        var traversal = currentData.facility_traversal;
        var results = [];
        for (var i = 0; i < traversal.length; i++) {
            var f = traversal[i];
            if (facilityFilters[f.type] === false) continue;
            if (dist_nm >= f.entry_dist_nm && dist_nm <= f.exit_dist_nm) {
                results.push(f);
            }
        }
        return results;
    }

    function buildFacilityBadges(facilities) {
        if (!facilities || facilities.length === 0) return '';
        var html = '<span class="ra-fix-badges">';
        for (var i = 0; i < facilities.length; i++) {
            var f = facilities[i];
            var pc = pillColors[f.type] || pillColors['FIR'];
            html += '<span class="ra-fix-badge" style="background:' + pc.bg + '; color:' + pc.color + ';">' +
                escHtml(f.id) + '</span>';
        }
        html += '</span>';
        return html;
    }

    function renderFixTable(fixAnalysis, totalDist, totalTime, depEpoch) {
        if (!fixTbody) return;
        if (!fixAnalysis || fixAnalysis.length === 0) {
            fixTbody.innerHTML = '<tr><td colspan="9" class="ra-empty">' + t('routeAnalysis.noData') + '</td></tr>';
            return;
        }
        var html = '';
        for (var i = 0; i < fixAnalysis.length; i++) {
            var fx = fixAnalysis[i];
            // Compute segment distance/time from previous fix
            var segDist = '--', segTime = '--';
            if (i > 0) {
                var prev = fixAnalysis[i - 1];
                var sd = fx.dist_from_origin_nm - prev.dist_from_origin_nm;
                var st = fx.time_from_origin_min - prev.time_from_origin_min;
                segDist = formatDist(sd);
                segTime = formatTime(st);
            }
            // Dynamic facility column based on active filters
            var facBadges = buildFacilityBadges(findFacilitiesForDist(fx.dist_from_origin_nm));
            html += '<tr data-idx="' + i + '">' +
                '<td class="ra-idx">' + (i + 1) + '</td>' +
                '<td><strong>' + escHtml(fx.fix || '') + '</strong>' + facBadges + '</td>' +
                '<td class="text-right">' + formatDist(fx.dist_from_origin_nm) + '</td>' +
                '<td class="text-right">' + formatTime(fx.time_from_origin_min) + '</td>' +
                '<td class="text-right ra-utc">' + minutesToUtcStr(depEpoch, fx.time_from_origin_min) + '</td>' +
                '<td class="text-right ra-seg-delta">' + segDist + '</td>' +
                '<td class="text-right ra-seg-delta">' + segTime + '</td>' +
                '<td class="text-right">' + formatDist(fx.dist_to_dest_nm) + '</td>' +
                '<td class="text-right">' + formatTime(fx.time_to_dest_min) + '</td>' +
                '</tr>';
        }
        fixTbody.innerHTML = html;

        // Attach click handlers
        var rows = fixTbody.querySelectorAll('tr');
        for (var r = 0; r < rows.length; r++) {
            rows[r].addEventListener('click', (function (idx) {
                return function () { zoomToFix(fixAnalysis[idx], idx); };
            })(r));
        }
    }

    function renderSegmentTable(fixAnalysis, depEpoch) {
        if (!segTbody) return;
        if (!fixAnalysis || fixAnalysis.length < 2) {
            segTbody.innerHTML = '<tr><td colspan="10" class="ra-empty">' + t('routeAnalysis.noData') + '</td></tr>';
            return;
        }
        var html = '';
        for (var i = 1; i < fixAnalysis.length; i++) {
            var prev = fixAnalysis[i - 1];
            var curr = fixAnalysis[i];
            var segDist = curr.dist_from_origin_nm - prev.dist_from_origin_nm;
            var segTime = curr.time_from_origin_min - prev.time_from_origin_min;
            var gs = segTime > 0 ? Math.round(segDist / (segTime / 60)) : '--';
            html += '<tr data-seg-idx="' + i + '">' +
                '<td class="ra-idx">' + i + '</td>' +
                '<td>' + escHtml(prev.fix || '') + '</td>' +
                '<td>' + escHtml(curr.fix || '') + '</td>' +
                '<td class="text-right">' + formatDist(segDist) + '</td>' +
                '<td class="text-right">' + formatTime(segTime) + '</td>' +
                '<td class="text-right">' + formatDist(prev.dist_from_origin_nm) + '</td>' +
                '<td class="text-right ra-utc">' + minutesToUtcStr(depEpoch, prev.time_from_origin_min) + '</td>' +
                '<td class="text-right">' + formatDist(curr.dist_from_origin_nm) + '</td>' +
                '<td class="text-right ra-utc">' + minutesToUtcStr(depEpoch, curr.time_from_origin_min) + '</td>' +
                '<td class="text-right">' + gs + '</td>' +
                '</tr>';
        }
        segTbody.innerHTML = html;

        // Click to zoom to segment midpoint
        var rows = segTbody.querySelectorAll('tr');
        for (var r = 0; r < rows.length; r++) {
            rows[r].addEventListener('click', (function (idx) {
                return function () {
                    var from = fixAnalysis[idx - 1];
                    var to = fixAnalysis[idx];
                    if (!from || !to) return;
                    var map = getMap();
                    if (!map) return;
                    // Show both endpoints as highlights
                    var feats = [];
                    if (from.lat && from.lon) {
                        feats.push({ type: 'Feature', properties: { label: from.fix || '' }, geometry: { type: 'Point', coordinates: [from.lon, from.lat] } });
                    }
                    if (to.lat && to.lon) {
                        feats.push({ type: 'Feature', properties: { label: to.fix || '' }, geometry: { type: 'Point', coordinates: [to.lon, to.lat] } });
                    }
                    if (map.getSource('route-analysis-highlight')) {
                        map.getSource('route-analysis-highlight').setData({ type: 'FeatureCollection', features: feats });
                    }
                    // Fit bounds to segment
                    if (from.lat && from.lon && to.lat && to.lon) {
                        var sw = [Math.min(from.lon, to.lon), Math.min(from.lat, to.lat)];
                        var ne = [Math.max(from.lon, to.lon), Math.max(from.lat, to.lat)];
                        map.fitBounds([sw, ne], { padding: 100, duration: 800, maxZoom: 10 });
                    }
                };
            })(r + 1));
        }
    }

    // ── Click-to-zoom ────────────────────────────────────────────────
    function zoomToFacility(facility, idx) {
        // Highlight active row
        setActiveRow('facility', idx);

        var map = getMap();
        if (!map) return;

        // Show highlight points at entry and exit
        if (facility.entry_lat && facility.entry_lon) {
            var features = [];
            features.push({
                type: 'Feature',
                properties: { label: facility.id + ' Entry' },
                geometry: { type: 'Point', coordinates: [facility.entry_lon, facility.entry_lat] }
            });
            if (facility.exit_lat && facility.exit_lon) {
                features.push({
                    type: 'Feature',
                    properties: { label: facility.id + ' Exit' },
                    geometry: { type: 'Point', coordinates: [facility.exit_lon, facility.exit_lat] }
                });
            }
            if (map.getSource('route-analysis-highlight')) {
                map.getSource('route-analysis-highlight').setData({ type: 'FeatureCollection', features: features });
            }
        }

        // Try to get bounds from the map source data
        var sourceId = null;
        var filterProp = null;
        var filterVal = facility.id;

        if (facility.type === 'ARTCC' || facility.type === 'FIR') {
            sourceId = 'artcc';
            filterProp = 'ICAOCODE';
        } else if (facility.type === 'TRACON') {
            sourceId = 'tracon';
            filterProp = 'sector';
        } else if (facility.type && facility.type.indexOf('SECTOR_') === 0) {
            var sType = facility.type.replace('SECTOR_', '').toLowerCase();
            sourceId = sType + '-splits';
            filterProp = 'label';
        }

        if (sourceId) {
            var source = map.getSource(sourceId);
            if (source && source._data) {
                var features2 = (source._data.features || []).filter(function (f) {
                    return f.properties && f.properties[filterProp] === filterVal;
                });
                if (features2.length > 0) {
                    var bbox = turf.bbox(turf.featureCollection(features2));
                    map.fitBounds([[bbox[0], bbox[1]], [bbox[2], bbox[3]]], {
                        padding: 60,
                        duration: 800
                    });
                    return;
                }
            }
        }

        // Fallback: use entry/exit lat/lon to create bounds
        if (facility.entry_lat && facility.entry_lon && facility.exit_lat && facility.exit_lon) {
            var sw = [Math.min(facility.entry_lon, facility.exit_lon), Math.min(facility.entry_lat, facility.exit_lat)];
            var ne = [Math.max(facility.entry_lon, facility.exit_lon), Math.max(facility.entry_lat, facility.exit_lat)];
            map.fitBounds([sw, ne], { padding: 80, duration: 800 });
        }
    }

    function zoomToFix(fix, idx) {
        setActiveRow('fix', idx);

        var map = getMap();
        if (!map || !fix.lat || !fix.lon) return;

        // Show highlight point at fix
        showHighlightPoint(fix.lat, fix.lon, fix.fix || '');

        map.flyTo({
            center: [fix.lon, fix.lat],
            zoom: 8,
            duration: 800
        });
    }

    function setActiveRow(table, idx) {
        // Clear previous
        var prev = document.querySelectorAll('.ra-active-row');
        for (var i = 0; i < prev.length; i++) prev[i].classList.remove('ra-active-row');

        var tbody = table === 'facility' ? facTbody : fixTbody;
        if (tbody) {
            var row = tbody.querySelector('tr[data-idx="' + idx + '"]');
            if (row) row.classList.add('ra-active-row');
        }
    }

    // ── Map interaction ──────────────────────────────────────────────
    function getMap() {
        if (typeof window.MapLibreRoute !== 'undefined' && window.MapLibreRoute.getMap) {
            return window.MapLibreRoute.getMap();
        }
        return null;
    }

    function highlightOnMap(data) {
        var map = getMap();
        if (!map) return;

        // Emphasize analyzed route, dim all others
        try {
            ['routes-solid', 'routes-dashed', 'routes-fan'].forEach(function (layerId) {
                if (map.getLayer(layerId)) {
                    if (currentRouteId != null) {
                        map.setPaintProperty(layerId, 'line-opacity',
                            ['case', ['==', ['get', 'routeId'], currentRouteId], 1, 0.15]
                        );
                        var baseWidth = layerId === 'routes-fan' ? 1 : (layerId === 'routes-dashed' ? 2 : 2.5);
                        map.setPaintProperty(layerId, 'line-width',
                            ['case', ['==', ['get', 'routeId'], currentRouteId], baseWidth * 1.5, baseWidth]
                        );
                    } else {
                        map.setPaintProperty(layerId, 'line-opacity', 0.2);
                    }
                }
            });
        } catch (e) { /* layer may not exist */ }

        // Dim non-analysis fixes (route-fix points/labels)
        // Both 'fixes-circles' (original layer) and 'route-fixes-circles' (Phase 5)
        // render dots at fix positions — both must be dimmed.
        try {
            var circLayers = ['route-fixes-circles', 'fixes-circles'];
            if (currentRouteId != null) {
                var dimExpr = ['case', ['==', ['get', 'routeId'], currentRouteId], 1, 0.1];
                circLayers.forEach(function (lid) {
                    if (map.getLayer(lid)) {
                        map.setPaintProperty(lid, 'circle-opacity', dimExpr);
                        map.setPaintProperty(lid, 'circle-stroke-opacity', dimExpr);
                    }
                });
                if (map.getLayer('route-fixes-labels')) {
                    map.setPaintProperty('route-fixes-labels', 'text-opacity',
                        ['case', ['==', ['get', 'routeId'], currentRouteId], 1, 0.1]);
                }
            } else {
                // No specific route ID — dim all existing fixes
                circLayers.forEach(function (lid) {
                    if (map.getLayer(lid)) {
                        map.setPaintProperty(lid, 'circle-opacity', 0.1);
                        map.setPaintProperty(lid, 'circle-stroke-opacity', 0.1);
                    }
                });
                if (map.getLayer('route-fixes-labels')) {
                    map.setPaintProperty('route-fixes-labels', 'text-opacity', 0.1);
                }
            }
            // Also dim airports layer
            if (map.getLayer('airports-triangles')) {
                if (currentRouteId != null) {
                    map.setPaintProperty('airports-triangles', 'text-opacity',
                        ['case', ['==', ['get', 'routeId'], currentRouteId], 1, 0.15]);
                } else {
                    map.setPaintProperty('airports-triangles', 'text-opacity', 0.15);
                }
            }
        } catch (e) { /* ignore */ }

        // Add server-resolved route line overlay (complete, gap-free)
        addAnalysisRouteOverlay(data);

        updateFacilityHighlights();
    }

    // Build and set the analysis route line from API waypoints
    function addAnalysisRouteOverlay(data) {
        var map = getMap();
        if (!map || !map.getSource('route-analysis')) return;

        var waypoints = data.waypoints || [];
        if (waypoints.length < 2) {
            map.getSource('route-analysis').setData({ type: 'FeatureCollection', features: [] });
            return;
        }

        // Build coordinate pairs, using turf.greatCircle for long segments
        var coords = [];
        for (var i = 0; i < waypoints.length; i++) {
            var wp = waypoints[i];
            if (wp.lat == null || wp.lon == null) continue;

            if (coords.length > 0) {
                var prev = coords[coords.length - 1];
                var dist = turf.distance([prev[0], prev[1]], [wp.lon, wp.lat], { units: 'nauticalmiles' });
                if (dist > 100 && typeof turf.greatCircle === 'function') {
                    try {
                        var gc = turf.greatCircle([prev[0], prev[1]], [wp.lon, wp.lat], { npoints: Math.max(10, Math.round(dist / 20)) });
                        var gcCoords = gc.geometry.coordinates;
                        // Skip first point (duplicate of prev)
                        for (var j = 1; j < gcCoords.length; j++) {
                            coords.push(gcCoords[j]);
                        }
                        continue;
                    } catch (e) { /* fall through to straight segment */ }
                }
            }
            coords.push([wp.lon, wp.lat]);
        }

        if (coords.length < 2) {
            map.getSource('route-analysis').setData({ type: 'FeatureCollection', features: [] });
            return;
        }

        var feature = {
            type: 'Feature',
            properties: { kind: 'route' },
            geometry: { type: 'LineString', coordinates: coords }
        };
        map.getSource('route-analysis').setData({ type: 'FeatureCollection', features: [feature] });
    }

    // Show a highlight point on the map at a given location
    function showHighlightPoint(lat, lon, label) {
        var map = getMap();
        if (!map || !map.getSource('route-analysis-highlight')) return;

        var feature = {
            type: 'Feature',
            properties: { label: label || '' },
            geometry: { type: 'Point', coordinates: [lon, lat] }
        };
        map.getSource('route-analysis-highlight').setData({ type: 'FeatureCollection', features: [feature] });
    }

    function clearHighlightPoint() {
        var map = getMap();
        if (!map || !map.getSource('route-analysis-highlight')) return;
        map.getSource('route-analysis-highlight').setData({ type: 'FeatureCollection', features: [] });
    }

    // Update map boundary highlights based on facility filter state
    function updateFacilityHighlights() {
        var map = getMap();
        if (!map || !currentData) return;
        var traversal = currentData.facility_traversal || [];

        // ARTCCs/FIRs — only show if ARTCC or FIR filter is active
        var artccIds = (facilityFilters.ARTCC !== false || facilityFilters.FIR !== false)
            ? traversal
                .filter(function (f) {
                    return (f.type === 'ARTCC' && facilityFilters.ARTCC !== false) ||
                           (f.type === 'FIR' && facilityFilters.FIR !== false);
                })
                .map(function (f) { return f.id; })
            : [];
        if (map.getLayer('artcc-play-traversed')) {
            map.setFilter('artcc-play-traversed',
                artccIds.length > 0 ? ['in', 'ICAOCODE'].concat(artccIds) : ['in', 'ICAOCODE', '']);
        }

        // TRACONs
        var traconIds = facilityFilters.TRACON !== false
            ? traversal.filter(function (f) { return f.type === 'TRACON'; }).map(function (f) { return f.id; })
            : [];
        if (map.getLayer('tracon-search-include')) {
            map.setFilter('tracon-search-include',
                traconIds.length > 0 ? ['in', 'sector'].concat(traconIds) : ['in', 'sector', '']);
        }

        // High sectors
        var highIds = facilityFilters.SECTOR_HIGH !== false
            ? traversal.filter(function (f) { return f.type === 'SECTOR_HIGH'; }).map(function (f) { return f.id; })
            : [];
        if (map.getLayer('high-sector-search-include')) {
            map.setFilter('high-sector-search-include',
                highIds.length > 0 ? ['in', 'label'].concat(highIds) : ['in', 'label', '']);
        }

        // Low sectors
        var lowIds = facilityFilters.SECTOR_LOW !== false
            ? traversal.filter(function (f) { return f.type === 'SECTOR_LOW'; }).map(function (f) { return f.id; })
            : [];
        if (map.getLayer('low-sector-search-include')) {
            map.setFilter('low-sector-search-include',
                lowIds.length > 0 ? ['in', 'label'].concat(lowIds) : ['in', 'label', '']);
        }

        // Superhigh sectors
        var superIds = facilityFilters.SECTOR_SUPERHIGH !== false
            ? traversal.filter(function (f) { return f.type === 'SECTOR_SUPERHIGH'; }).map(function (f) { return f.id; })
            : [];
        if (map.getLayer('superhigh-sector-search-include')) {
            map.setFilter('superhigh-sector-search-include',
                superIds.length > 0 ? ['in', 'label'].concat(superIds) : ['in', 'label', '']);
        }
    }

    function clearHighlight() {
        var map = getMap();
        if (!map) return;

        // Restore route opacity and widths
        try {
            ['routes-solid', 'routes-dashed', 'routes-fan'].forEach(function (layerId) {
                if (map.getLayer(layerId)) {
                    map.setPaintProperty(layerId, 'line-opacity', 0.9);
                    var baseWidth = layerId === 'routes-fan' ? 1 : (layerId === 'routes-dashed' ? 2 : 2.5);
                    map.setPaintProperty(layerId, 'line-width', baseWidth);
                }
            });
        } catch (e) { /* ignore */ }

        // Reset ARTCC highlight
        if (map.getLayer('artcc-play-traversed')) {
            map.setFilter('artcc-play-traversed', ['in', 'ICAOCODE', '']);
        }
        // Reset TRACON highlight
        if (map.getLayer('tracon-search-include')) {
            map.setFilter('tracon-search-include', ['in', 'sector', '']);
        }
        // Reset sector highlights
        ['high-sector-search-include', 'low-sector-search-include', 'superhigh-sector-search-include'].forEach(function (layerId) {
            if (map.getLayer(layerId)) {
                map.setFilter(layerId, ['in', 'label', '']);
            }
        });

        // Restore fix/airport opacity
        try {
            ['route-fixes-circles', 'fixes-circles'].forEach(function (lid) {
                if (map.getLayer(lid)) {
                    map.setPaintProperty(lid, 'circle-opacity', 1);
                    map.setPaintProperty(lid, 'circle-stroke-opacity', 1);
                }
            });
            if (map.getLayer('route-fixes-labels')) {
                map.setPaintProperty('route-fixes-labels', 'text-opacity', 1);
            }
            if (map.getLayer('airports-triangles')) {
                map.setPaintProperty('airports-triangles', 'text-opacity', 1);
            }
        } catch (e) { /* ignore */ }

        // Clear analysis route overlay
        if (map.getSource('route-analysis')) {
            map.getSource('route-analysis').setData({ type: 'FeatureCollection', features: [] });
        }
        // Clear highlight point
        clearHighlightPoint();
    }

    // ── Export ────────────────────────────────────────────────────────
    function buildHeaderLine() {
        var label = '';
        if (currentOrigin) label += currentOrigin;
        if (currentDest) label += ' > ' + currentDest;
        if (!label && currentRouteStr) label = currentRouteStr;
        return label;
    }

    function buildFacilityRows(sep, depEpoch) {
        var lines = [];
        var traversal = (currentData && currentData.facility_traversal) || [];
        var header = ['#', 'Facility', 'Type', 'Dist (nm)', 'Time', 'Time (min)', 'Entry (Z)', 'Exit (Z)', 'Entry Fix', 'Exit Fix'];
        lines.push(header.join(sep));

        // Group by facility type in standard order
        var typeOrder = ['ARTCC', 'FIR', 'SECTOR_SUPERHIGH', 'SECTOR_HIGH', 'SECTOR_LOW', 'TRACON'];
        var grouped = {};
        for (var i = 0; i < traversal.length; i++) {
            var f = traversal[i];
            var t = f.type || 'OTHER';
            if (!grouped[t]) grouped[t] = [];
            grouped[t].push(f);
        }

        var idx = 1;
        typeOrder.forEach(function (t) {
            if (!grouped[t] || grouped[t].length === 0) return;
            // Section header row
            lines.push('');
            lines.push('--- ' + typeLabel(t) + ' ---');
            grouped[t].forEach(function (f) {
                lines.push([
                    idx++,
                    f.name || f.id || '',
                    typeLabel(f.type),
                    f.distance_within_nm != null ? Math.round(f.distance_within_nm) : '',
                    formatTime(f.time_within_min),
                    f.time_within_min != null ? Math.round(f.time_within_min * 10) / 10 : '',
                    minutesToUtcStr(depEpoch, f.entry_time_min),
                    minutesToUtcStr(depEpoch, f.exit_time_min),
                    f.entry_fix || '',
                    f.exit_fix || ''
                ].join(sep));
            });
        });

        // Any types not in typeOrder
        Object.keys(grouped).forEach(function (t) {
            if (typeOrder.indexOf(t) >= 0) return;
            lines.push('');
            lines.push('--- ' + typeLabel(t) + ' ---');
            grouped[t].forEach(function (f) {
                lines.push([
                    idx++,
                    f.name || f.id || '',
                    typeLabel(f.type),
                    f.distance_within_nm != null ? Math.round(f.distance_within_nm) : '',
                    formatTime(f.time_within_min),
                    f.time_within_min != null ? Math.round(f.time_within_min * 10) / 10 : '',
                    minutesToUtcStr(depEpoch, f.entry_time_min),
                    minutesToUtcStr(depEpoch, f.exit_time_min),
                    f.entry_fix || '',
                    f.exit_fix || ''
                ].join(sep));
            });
        });

        return lines;
    }

    function buildFixRows(sep, depEpoch) {
        var lines = [];
        var fixes = (currentData && currentData.fix_analysis) || [];
        lines.push(['#', 'Fix', 'Facility', 'Elapsed Dist (nm)', 'Elapsed Time', 'Elapsed (min)', 'ETA (Z)', 'Seg Dist (nm)', 'Seg Time', 'Seg Time (min)', 'Rem Dist (nm)', 'Rem Time', 'Rem Time (min)'].join(sep));
        for (var i = 0; i < fixes.length; i++) {
            var fx = fixes[i];
            var segDist = '', segTime = '', segTimeMin = '';
            if (i > 0) {
                var prev = fixes[i - 1];
                segDist = Math.round(fx.dist_from_origin_nm - prev.dist_from_origin_nm);
                var stMin = fx.time_from_origin_min - prev.time_from_origin_min;
                segTime = formatTime(stMin);
                segTimeMin = Math.round(stMin * 10) / 10;
            }
            lines.push([
                i + 1,
                fx.fix || '',
                fx.facility || '',
                fx.dist_from_origin_nm != null ? Math.round(fx.dist_from_origin_nm) : '',
                formatTime(fx.time_from_origin_min),
                fx.time_from_origin_min != null ? Math.round(fx.time_from_origin_min * 10) / 10 : '',
                minutesToUtcStr(depEpoch, fx.time_from_origin_min),
                segDist,
                segTime,
                segTimeMin,
                fx.dist_to_dest_nm != null ? Math.round(fx.dist_to_dest_nm) : '',
                formatTime(fx.time_to_dest_min),
                fx.time_to_dest_min != null ? Math.round(fx.time_to_dest_min * 10) / 10 : ''
            ].join(sep));
        }
        return lines;
    }

    function buildSegmentRows(sep, depEpoch) {
        var lines = [];
        var fixes = (currentData && currentData.fix_analysis) || [];
        lines.push(['#', 'From', 'To', 'Seg Dist (nm)', 'Seg Time', 'Seg Time (min)', 'Entry Dist (nm)', 'Entry (Z)', 'Exit Dist (nm)', 'Exit (Z)', 'GS (kts)'].join(sep));
        for (var i = 1; i < fixes.length; i++) {
            var prev = fixes[i - 1];
            var curr = fixes[i];
            var segDist = curr.dist_from_origin_nm - prev.dist_from_origin_nm;
            var segTime = curr.time_from_origin_min - prev.time_from_origin_min;
            var gs = segTime > 0 ? Math.round(segDist / (segTime / 60)) : '';
            lines.push([
                i,
                prev.fix || '',
                curr.fix || '',
                Math.round(segDist),
                formatTime(segTime),
                Math.round(segTime * 10) / 10,
                prev.dist_from_origin_nm != null ? Math.round(prev.dist_from_origin_nm) : '',
                minutesToUtcStr(depEpoch, prev.time_from_origin_min),
                curr.dist_from_origin_nm != null ? Math.round(curr.dist_from_origin_nm) : '',
                minutesToUtcStr(depEpoch, curr.time_from_origin_min),
                gs
            ].join(sep));
        }
        return lines;
    }

    function buildTextContent(sep) {
        var depEpoch = getDepartureEpoch();
        var lines = [];
        lines.push(buildHeaderLine());
        if (currentData) {
            lines.push('Total: ' + formatDist(currentData.total_distance_nm) + ' nm / ' + formatTime(currentData.total_time_min));
            lines.push('ETD: ' + minutesToUtcStr(depEpoch, 0) + '  ETA: ' + minutesToUtcStr(depEpoch, currentData.total_time_min));
        }
        lines.push('');
        lines.push('FACILITY TRAVERSAL');
        lines = lines.concat(buildFacilityRows(sep, depEpoch));
        lines.push('');
        lines.push('FIX ANALYSIS');
        lines = lines.concat(buildFixRows(sep, depEpoch));
        lines.push('');
        lines.push('SEGMENT ANALYSIS');
        lines = lines.concat(buildSegmentRows(sep, depEpoch));
        return lines.join('\n');
    }

    function csvEscape(val) {
        var s = String(val == null ? '' : val);
        if (s.indexOf(',') >= 0 || s.indexOf('"') >= 0 || s.indexOf('\n') >= 0) {
            return '"' + s.replace(/"/g, '""') + '"';
        }
        return s;
    }

    function buildCsvContent() {
        var depEpoch = getDepartureEpoch();
        var lines = [];
        lines.push('# ' + buildHeaderLine());
        if (currentData) {
            lines.push('# Total: ' + formatDist(currentData.total_distance_nm) + ' nm / ' + formatTime(currentData.total_time_min));
            lines.push('# ETD: ' + minutesToUtcStr(depEpoch, 0) + '  ETA: ' + minutesToUtcStr(depEpoch, currentData.total_time_min));
        }
        lines.push('');

        var traversal = (currentData && currentData.facility_traversal) || [];
        lines.push(['"#"', '"Facility"', '"Type"', '"Dist (nm)"', '"Time"', '"Time (min)"', '"Entry (Z)"', '"Exit (Z)"', '"Entry Fix"', '"Exit Fix"'].join(','));
        for (var i = 0; i < traversal.length; i++) {
            var f = traversal[i];
            lines.push([
                i + 1,
                csvEscape(f.name || f.id || ''),
                csvEscape(typeLabel(f.type)),
                f.distance_within_nm != null ? Math.round(f.distance_within_nm) : '',
                csvEscape(formatTime(f.time_within_min)),
                f.time_within_min != null ? Math.round(f.time_within_min * 10) / 10 : '',
                csvEscape(minutesToUtcStr(depEpoch, f.entry_time_min)),
                csvEscape(minutesToUtcStr(depEpoch, f.exit_time_min)),
                csvEscape(f.entry_fix || ''),
                csvEscape(f.exit_fix || '')
            ].join(','));
        }
        lines.push('');

        var fixes = (currentData && currentData.fix_analysis) || [];
        lines.push(['"#"', '"Fix"', '"Facility"', '"Elapsed Dist (nm)"', '"Elapsed Time"', '"Elapsed (min)"', '"ETA (Z)"', '"Seg Dist (nm)"', '"Seg Time"', '"Seg Time (min)"', '"Rem Dist (nm)"', '"Rem Time"', '"Rem Time (min)"'].join(','));
        for (var j = 0; j < fixes.length; j++) {
            var fx = fixes[j];
            var segDist = '', segTime = '', segTimeMin = '';
            if (j > 0) {
                var prev = fixes[j - 1];
                segDist = Math.round(fx.dist_from_origin_nm - prev.dist_from_origin_nm);
                var stMin = fx.time_from_origin_min - prev.time_from_origin_min;
                segTime = formatTime(stMin);
                segTimeMin = Math.round(stMin * 10) / 10;
            }
            lines.push([
                j + 1,
                csvEscape(fx.fix || ''),
                csvEscape(fx.facility || ''),
                fx.dist_from_origin_nm != null ? Math.round(fx.dist_from_origin_nm) : '',
                csvEscape(formatTime(fx.time_from_origin_min)),
                fx.time_from_origin_min != null ? Math.round(fx.time_from_origin_min * 10) / 10 : '',
                csvEscape(minutesToUtcStr(depEpoch, fx.time_from_origin_min)),
                segDist,
                csvEscape(segTime),
                segTimeMin,
                fx.dist_to_dest_nm != null ? Math.round(fx.dist_to_dest_nm) : '',
                csvEscape(formatTime(fx.time_to_dest_min)),
                fx.time_to_dest_min != null ? Math.round(fx.time_to_dest_min * 10) / 10 : ''
            ].join(','));
        }
        return lines.join('\n');
    }

    function downloadFile(content, filename, mimeType) {
        var blob = new Blob([content], { type: mimeType });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    function exportClipboard() {
        var text = buildTextContent('\t');
        navigator.clipboard.writeText(text).then(function () {
            if (typeof PERTIDialog !== 'undefined') {
                PERTIDialog.toast(t('routeAnalysis.export.copied'));
            }
        }).catch(function () {
            if (typeof PERTIDialog !== 'undefined') {
                PERTIDialog.toast(t('routeAnalysis.export.failed'), 'error');
            }
        });
        hideExportMenu();
    }

    function exportTXT() {
        var text = buildTextContent('\t');
        var name = 'route-analysis' + (currentOrigin && currentDest ? '-' + currentOrigin + '-' + currentDest : '') + '.txt';
        downloadFile(text, name, 'text/plain');
        hideExportMenu();
    }

    function exportCSV() {
        var csv = buildCsvContent();
        var name = 'route-analysis' + (currentOrigin && currentDest ? '-' + currentOrigin + '-' + currentDest : '') + '.csv';
        downloadFile(csv, name, 'text/csv');
        hideExportMenu();
    }

    function exportXLSX() {
        if (typeof XLSX === 'undefined') {
            // Lazy-load SheetJS
            var script = document.createElement('script');
            script.src = 'https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.mini.min.js';
            script.onload = function () { doExportXLSX(); };
            document.head.appendChild(script);
        } else {
            doExportXLSX();
        }
        hideExportMenu();
    }

    function doExportXLSX() {
        if (typeof XLSX === 'undefined') return;

        var depEpoch = getDepartureEpoch();
        var wb = XLSX.utils.book_new();

        // Facilities sheet
        var facData = [['#', 'Facility', 'Type', 'Dist (nm)', 'Time (min)', 'Entry (Z)', 'Exit (Z)', 'Entry Fix', 'Exit Fix']];
        var traversal = (currentData && currentData.facility_traversal) || [];
        for (var i = 0; i < traversal.length; i++) {
            var f = traversal[i];
            facData.push([
                i + 1,
                f.name || f.id || '',
                typeLabel(f.type),
                f.distance_within_nm != null ? Math.round(f.distance_within_nm) : null,
                f.time_within_min != null ? Math.round(f.time_within_min * 10) / 10 : null,
                minutesToUtcStr(depEpoch, f.entry_time_min),
                minutesToUtcStr(depEpoch, f.exit_time_min),
                f.entry_fix || '',
                f.exit_fix || ''
            ]);
        }
        var ws1 = XLSX.utils.aoa_to_sheet(facData);
        XLSX.utils.book_append_sheet(wb, ws1, 'Facilities');

        // Fixes sheet
        var fixData = [['#', 'Fix', 'Facility', 'Elapsed Dist (nm)', 'Elapsed (min)', 'ETA (Z)', 'Seg Dist (nm)', 'Seg Time (min)', 'Rem Dist (nm)', 'Rem Time (min)']];
        var fixes = (currentData && currentData.fix_analysis) || [];
        for (var j = 0; j < fixes.length; j++) {
            var fx = fixes[j];
            var segDist = null, segTime = null;
            if (j > 0) {
                var prev = fixes[j - 1];
                segDist = Math.round(fx.dist_from_origin_nm - prev.dist_from_origin_nm);
                segTime = Math.round((fx.time_from_origin_min - prev.time_from_origin_min) * 10) / 10;
            }
            fixData.push([
                j + 1,
                fx.fix || '',
                fx.facility || '',
                fx.dist_from_origin_nm != null ? Math.round(fx.dist_from_origin_nm) : null,
                fx.time_from_origin_min != null ? Math.round(fx.time_from_origin_min * 10) / 10 : null,
                minutesToUtcStr(depEpoch, fx.time_from_origin_min),
                segDist,
                segTime,
                fx.dist_to_dest_nm != null ? Math.round(fx.dist_to_dest_nm) : null,
                fx.time_to_dest_min != null ? Math.round(fx.time_to_dest_min * 10) / 10 : null
            ]);
        }
        var ws2 = XLSX.utils.aoa_to_sheet(fixData);
        XLSX.utils.book_append_sheet(wb, ws2, 'Fixes');

        var name = 'route-analysis' + (currentOrigin && currentDest ? '-' + currentOrigin + '-' + currentDest : '') + '.xlsx';
        XLSX.writeFile(wb, name);
    }

    function toggleExportMenu() {
        if (exportMenu) exportMenu.classList.toggle('show');
    }
    function hideExportMenu() {
        if (exportMenu) exportMenu.classList.remove('show');
    }

    // ── Recalculate ──────────────────────────────────────────────────
    function recalculate() {
        if (!currentRouteStr) return;

        var params = { route_string: currentRouteStr };
        if (currentOrigin) params.origin = currentOrigin;
        if (currentDest) params.dest = currentDest;
        if (speedInput && speedInput.value) params.cruise_kts = parseFloat(speedInput.value) || 460;
        if (windInput && windInput.value) params.wind_component_kts = parseFloat(windInput.value) || 0;
        params.facility_types = 'ARTCC,FIR,TRACON,SECTOR_HIGH,SECTOR_LOW,SECTOR_SUPERHIGH';

        // Show loading
        if (facTbody) facTbody.innerHTML = '<tr><td colspan="8" class="ra-loading"><i class="fas fa-spinner fa-spin"></i> ' + t('routeAnalysis.loading') + '</td></tr>';
        if (fixTbody) fixTbody.innerHTML = '<tr><td colspan="9" class="ra-loading"><i class="fas fa-spinner fa-spin"></i> ' + t('routeAnalysis.loading') + '</td></tr>';
        if (segTbody) segTbody.innerHTML = '<tr><td colspan="10" class="ra-loading"><i class="fas fa-spinner fa-spin"></i> ' + t('routeAnalysis.loading') + '</td></tr>';

        $.getJSON('api/data/playbook/analysis.php', params, function (resp) {
            if (!resp || resp.status !== 'success') {
                if (facTbody) facTbody.innerHTML = '<tr><td colspan="8" class="ra-empty">' + t('routeAnalysis.error') + '</td></tr>';
                return;
            }
            if (resp.fix_analysis) resp.fix_analysis = deduplicateFixes(resp.fix_analysis);
            if (resp.waypoints) resp.waypoints = deduplicateFixes(resp.waypoints);
            currentData = resp;
            var depEpoch = getDepartureEpoch();
            var traversal = resp.facility_traversal || [];
            renderSummary(resp, depEpoch);
            renderFacilityFilters(traversal);
            renderFacilityTable(filterTraversal(traversal), depEpoch);
            renderFixTable(resp.fix_analysis || [], resp.total_distance_nm, resp.total_time_min, depEpoch);
            renderSegmentTable(resp.fix_analysis || [], depEpoch);
            highlightOnMap(resp);
        }).fail(function () {
            if (facTbody) facTbody.innerHTML = '<tr><td colspan="8" class="ra-empty">' + t('routeAnalysis.error') + '</td></tr>';
        });
    }

    // ── Refresh timeline (dep time change only — no API call) ──────
    function refreshTimeline() {
        if (!currentData) return;
        var depEpoch = getDepartureEpoch();
        renderSummary(currentData, depEpoch);
        renderFacilityTable(filterTraversal(currentData.facility_traversal || []), depEpoch);
        renderFixTable(currentData.fix_analysis || [], currentData.total_distance_nm, currentData.total_time_min, depEpoch);
        renderSegmentTable(currentData.fix_analysis || [], depEpoch);
        updateFacilityHighlights();
    }

    // ── Lifecycle ────────────────────────────────────────────────────
    function clear() {
        resolveDOM();
        if (panel) panel.style.display = 'none';
        currentData = null;
        currentRouteStr = null;
        currentOrigin = null;
        currentDest = null;
        currentRouteId = null;
        clearHighlight();
        clearPicker();
    }

    function clearPicker() {
        if (pickerOrigin) pickerOrigin.value = '';
        if (pickerDest) pickerDest.value = '';
        if (pickerRoute) pickerRoute.value = '';
        if (pickerMatches) pickerMatches.innerHTML = '';
    }

    // ── Utility ──────────────────────────────────────────────────────
    function escHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    // ── Init (attach toggle/close/export handlers on DOMContentLoaded) ──
    document.addEventListener('DOMContentLoaded', function () {
        resolveDOM();
        if (!panel) return;

        // Toggle collapse
        if (toggle) {
            toggle.addEventListener('click', function (e) {
                // Don't toggle if clicking controls inside header
                if (e.target.closest('.ra-controls')) return;
                if (!body) return;
                var isHidden = body.style.display === 'none';
                body.style.display = isHidden ? 'block' : 'none';
                if (chevron) chevron.classList.toggle('collapsed', !isHidden);
            });
        }

        // Close button
        var closeBtn = document.getElementById('ra-close-btn');
        if (closeBtn) {
            closeBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                clear();
            });
        }

        // Export button
        var exportBtn = document.getElementById('ra-export-btn');
        if (exportBtn) {
            exportBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                toggleExportMenu();
            });
        }

        // Export menu items
        var expClip = document.getElementById('ra-exp-clipboard');
        var expTxt  = document.getElementById('ra-exp-txt');
        var expCsv  = document.getElementById('ra-exp-csv');
        var expXlsx = document.getElementById('ra-exp-xlsx');
        if (expClip) expClip.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); exportClipboard(); });
        if (expTxt)  expTxt.addEventListener('click',  function (e) { e.preventDefault(); e.stopPropagation(); exportTXT(); });
        if (expCsv)  expCsv.addEventListener('click',  function (e) { e.preventDefault(); e.stopPropagation(); exportCSV(); });
        if (expXlsx) expXlsx.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); exportXLSX(); });

        // Close export menu on outside click
        document.addEventListener('click', function (e) {
            if (exportMenu && exportMenu.classList.contains('show') && !e.target.closest('.ra-export-dropdown')) {
                hideExportMenu();
            }
        });

        // Time format toggle
        var timeFmtBtn = document.getElementById('ra-time-fmt-btn');
        var timeFmtLabel = document.getElementById('ra-time-fmt-label');
        if (timeFmtBtn) {
            timeFmtBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                if (timeFormat === 'hms') { timeFormat = 'short'; }
                else if (timeFormat === 'short') { timeFormat = 'min'; }
                else { timeFormat = 'hms'; }
                var labels = { hms: 'hh:mm:ss', short: 'short', min: 'minutes' };
                if (timeFmtLabel) timeFmtLabel.textContent = labels[timeFormat];
                refreshTimeline();
            });
        }

        // Recalculate button
        var recalcBtn = document.getElementById('ra-recalc-btn');
        if (recalcBtn) {
            recalcBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                recalculate();
            });
        }

        // Speed/wind inputs — recalculate on Enter
        [speedInput, windInput].forEach(function (inp) {
            if (inp) {
                inp.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') { e.preventDefault(); recalculate(); }
                });
                // Prevent header toggle when clicking inputs
                inp.addEventListener('click', function (e) { e.stopPropagation(); });
            }
        });

        // Departure time input — refresh timeline (no API call)
        if (depTimeInput) {
            depTimeInput.addEventListener('blur', function () { refreshTimeline(); });
            depTimeInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); refreshTimeline(); }
            });
            depTimeInput.addEventListener('click', function (e) { e.stopPropagation(); });
        }

        // Route picker
        if (pickerGo) {
            pickerGo.addEventListener('click', function (e) {
                e.stopPropagation();
                pickerAnalyze();
            });
        }
        // Search plotted routes on origin/dest input
        [pickerOrigin, pickerDest].forEach(function (inp) {
            if (inp) {
                inp.addEventListener('input', function () { pickerSearchPlotted(); });
                inp.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') { e.preventDefault(); pickerAnalyze(); }
                });
            }
        });
        if (pickerRoute) {
            pickerRoute.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); pickerAnalyze(); }
            });
        }

    });

    // ── Route picker ────────────────────────────────────────────────
    function pickerSearchPlotted() {
        if (!pickerMatches) return;
        var origin = (pickerOrigin ? pickerOrigin.value.trim().toUpperCase() : '');
        var dest = (pickerDest ? pickerDest.value.trim().toUpperCase() : '');
        if (!origin && !dest) { pickerMatches.innerHTML = ''; return; }

        var routeIndex = {};
        if (typeof window.MapLibreRoute !== 'undefined' && window.MapLibreRoute.getRouteIndex) {
            routeIndex = window.MapLibreRoute.getRouteIndex() || {};
        }

        var matches = [];
        var ids = Object.keys(routeIndex);
        for (var i = 0; i < ids.length; i++) {
            var id = ids[i];
            var r = routeIndex[id];
            if (!r) continue;
            var rOrigin = (r.origin || '').toUpperCase();
            var rDest = (r.dest || '').toUpperCase();
            if (origin && rOrigin && rOrigin.indexOf(origin) !== 0) continue;
            if (dest && rDest && rDest.indexOf(dest) !== 0) continue;
            // At least one must match non-empty
            if ((!origin || !rOrigin) && (!dest || !rDest)) continue;
            matches.push({ id: parseInt(id), origin: rOrigin, dest: rDest, route: r.routeString || '', color: r.color || '#adb5bd' });
        }

        if (matches.length === 0) {
            pickerMatches.innerHTML = '<span style="font-size:0.6rem;color:#6c757d;">No plotted routes match</span>';
            return;
        }

        var html = '';
        for (var m = 0; m < Math.min(matches.length, 20); m++) {
            var match = matches[m];
            var routePreview = match.route.length > 40 ? match.route.substring(0, 37) + '...' : match.route;
            html += '<span class="ra-picker-match" data-route-id="' + match.id + '" ' +
                'data-route="' + escHtml(match.route) + '" ' +
                'data-origin="' + escHtml(match.origin) + '" ' +
                'data-dest="' + escHtml(match.dest) + '">' +
                '<span class="ra-match-color" style="background:' + escHtml(match.color) + ';"></span>' +
                escHtml(match.origin) + ' &rarr; ' + escHtml(match.dest) +
                '<span style="color:#6c757d;margin-left:4px;">' + escHtml(routePreview) + '</span>' +
                '</span>';
        }
        if (matches.length > 20) {
            html += '<span style="font-size:0.6rem;color:#6c757d;">+' + (matches.length - 20) + ' more</span>';
        }
        pickerMatches.innerHTML = html;

        // Bind click handlers
        pickerMatches.querySelectorAll('.ra-picker-match').forEach(function (el) {
            el.addEventListener('click', function (e) {
                e.stopPropagation();
                var rId = parseInt(this.getAttribute('data-route-id'));
                var rStr = this.getAttribute('data-route') || '';
                var orig = this.getAttribute('data-origin') || '';
                var dst = this.getAttribute('data-dest') || '';
                if (pickerOrigin) pickerOrigin.value = orig;
                if (pickerDest) pickerDest.value = dst;
                if (pickerRoute) pickerRoute.value = rStr;
                pickerMatches.innerHTML = '';
                triggerAnalysis(rId, rStr, orig, dst);
            });
        });
    }

    function pickerAnalyze() {
        var origin = (pickerOrigin ? pickerOrigin.value.trim().toUpperCase() : '');
        var dest = (pickerDest ? pickerDest.value.trim().toUpperCase() : '');
        var route = (pickerRoute ? pickerRoute.value.trim().toUpperCase() : '');

        if (!route && !origin && !dest) return;

        // If no route string, try to find a matching plotted route
        if (!route) {
            var routeIndex = {};
            if (typeof window.MapLibreRoute !== 'undefined' && window.MapLibreRoute.getRouteIndex) {
                routeIndex = window.MapLibreRoute.getRouteIndex() || {};
            }
            var ids = Object.keys(routeIndex);
            for (var i = 0; i < ids.length; i++) {
                var r = routeIndex[ids[i]];
                if (!r) continue;
                var rOrigin = (r.origin || '').toUpperCase();
                var rDest = (r.dest || '').toUpperCase();
                if (origin && rOrigin === origin && dest && rDest === dest) {
                    triggerAnalysis(parseInt(ids[i]), r.routeString || '', origin, dest);
                    return;
                }
            }
        }

        // Direct analysis with whatever we have
        if (route) {
            triggerAnalysis(null, route, origin, dest);
        }
    }

    function triggerAnalysis(routeId, routeStr, origin, dest) {
        if (pickerMatches) pickerMatches.innerHTML = '';
        if (typeof window.showRouteAnalysis === 'function') {
            window.showRouteAnalysis(routeId, routeStr, origin, dest);
        }
    }

    // ── Public API ───────────────────────────────────────────────────
    window.RouteAnalysisPanel = {
        show: show,
        showLoading: showLoading,
        showError: showError,
        clear: clear,
        recalculate: recalculate,
        refreshTimeline: refreshTimeline,
        clearHighlight: clearHighlight,
        exportClipboard: exportClipboard,
        exportTXT: exportTXT,
        exportCSV: exportCSV,
        exportXLSX: exportXLSX,
        getCurrentData: function () { return currentData; },
        clearPicker: clearPicker
    };
})();
