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

    // ── DOM refs (resolved on first show) ────────────────────────────
    var panel, header, toggle, body, routeLabel, chevron;
    var summaryEl, facTbody, fixTbody;
    var speedInput, windInput;
    var exportMenu;

    function resolveDOM() {
        panel       = document.getElementById('route-analysis-panel');
        toggle      = document.getElementById('ra-toggle');
        body        = document.getElementById('ra-body');
        routeLabel  = document.getElementById('ra-route-label');
        chevron     = panel ? panel.querySelector('.ra-chevron') : null;
        summaryEl   = document.getElementById('ra-summary');
        facTbody    = document.getElementById('ra-facility-tbody');
        fixTbody    = document.getElementById('ra-fix-tbody');
        speedInput  = document.getElementById('ra-cruise-speed');
        windInput   = document.getElementById('ra-wind');
        exportMenu  = document.getElementById('ra-export-menu');
    }

    // ── Time formatting ──────────────────────────────────────────────
    function formatTime(minutes) {
        if (minutes == null || isNaN(minutes)) return '--';
        minutes = Math.round(minutes);
        if (minutes < 1)  return '< 1m';
        if (minutes < 60) return minutes + 'm';
        var h = Math.floor(minutes / 60);
        var m = minutes % 60;
        return m === 0 ? h + 'h' : h + 'h ' + m + 'm';
    }

    function formatDist(nm) {
        if (nm == null || isNaN(nm)) return '--';
        return Math.round(nm).toLocaleString();
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

    // ── Rendering ────────────────────────────────────────────────────
    function show(data, routeStr, origin, dest, routeId) {
        resolveDOM();
        if (!panel) return;

        currentData = data;
        currentRouteStr = routeStr || null;
        currentOrigin = origin || null;
        currentDest = dest || null;
        currentRouteId = routeId || null;

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

        renderSummary(data);
        renderFacilityTable(data.facility_traversal || []);
        renderFixTable(data.fix_analysis || [], data.total_distance_nm, data.total_time_min);

        // Scroll into view
        panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

        // Trigger map highlighting
        highlightOnMap(data);
    }

    function renderSummary(data) {
        if (!summaryEl) return;
        var facilities = data.facility_traversal || [];
        var fixes = data.fix_analysis || [];
        summaryEl.innerHTML =
            '<div class="ra-stat-card">' +
                '<div class="ra-stat-value">' + formatDist(data.total_distance_nm) + '</div>' +
                '<div class="ra-stat-label">' + t('routeAnalysis.stat.totalDist') + ' (nm)</div>' +
            '</div>' +
            '<div class="ra-stat-card">' +
                '<div class="ra-stat-value">' + formatTime(data.total_time_min) + '</div>' +
                '<div class="ra-stat-label">' + t('routeAnalysis.stat.totalTime') + '</div>' +
            '</div>' +
            '<div class="ra-stat-card">' +
                '<div class="ra-stat-value">' + facilities.length + '</div>' +
                '<div class="ra-stat-label">' + t('routeAnalysis.stat.facilities') + '</div>' +
            '</div>' +
            '<div class="ra-stat-card">' +
                '<div class="ra-stat-value">' + fixes.length + '</div>' +
                '<div class="ra-stat-label">' + t('routeAnalysis.stat.fixes') + '</div>' +
            '</div>';
    }

    function renderFacilityTable(traversal) {
        if (!facTbody) return;
        if (!traversal || traversal.length === 0) {
            facTbody.innerHTML = '<tr><td colspan="6" class="ra-empty">' + t('routeAnalysis.noData') + '</td></tr>';
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

    function renderFixTable(fixAnalysis, totalDist, totalTime) {
        if (!fixTbody) return;
        if (!fixAnalysis || fixAnalysis.length === 0) {
            fixTbody.innerHTML = '<tr><td colspan="8" class="ra-empty">' + t('routeAnalysis.noData') + '</td></tr>';
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
            html += '<tr data-idx="' + i + '">' +
                '<td class="ra-idx">' + (i + 1) + '</td>' +
                '<td><strong>' + escHtml(fx.fix || '') + '</strong>' +
                    (fx.facility ? '<span class="ra-fix-facility">' + escHtml(fx.facility) + '</span>' : '') +
                '</td>' +
                '<td class="text-right">' + formatDist(fx.dist_from_origin_nm) + '</td>' +
                '<td class="text-right">' + formatTime(fx.time_from_origin_min) + '</td>' +
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

    // ── Click-to-zoom ────────────────────────────────────────────────
    function zoomToFacility(facility, idx) {
        // Highlight active row
        setActiveRow('facility', idx);

        var map = getMap();
        if (!map) return;

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
                var features = (source._data.features || []).filter(function (f) {
                    return f.properties && f.properties[filterProp] === filterVal;
                });
                if (features.length > 0) {
                    var bbox = turf.bbox(turf.featureCollection(features));
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

        var traversal = data.facility_traversal || [];

        // Dim non-analyzed routes
        try {
            ['routes-solid', 'routes-dashed', 'routes-fan'].forEach(function (layerId) {
                if (map.getLayer(layerId)) {
                    map.setPaintProperty(layerId, 'line-opacity', 0.2);
                }
            });
        } catch (e) { /* layer may not exist */ }

        // Highlight traversed ARTCCs
        var artccIds = traversal
            .filter(function (f) { return f.type === 'ARTCC' || f.type === 'FIR'; })
            .map(function (f) { return f.id; });
        if (artccIds.length > 0 && map.getLayer('artcc-play-traversed')) {
            map.setFilter('artcc-play-traversed', ['in', 'ICAOCODE'].concat(artccIds));
        }

        // Highlight traversed TRACONs (GeoJSON uses 'sector' property)
        var traconIds = traversal
            .filter(function (f) { return f.type === 'TRACON'; })
            .map(function (f) { return f.id; });
        if (traconIds.length > 0) {
            if (map.getLayer('tracon-search-include')) {
                map.setFilter('tracon-search-include', ['in', 'sector'].concat(traconIds));
            }
        }

        // Highlight traversed sectors
        var sectorHighLabels = traversal.filter(function (f) { return f.type === 'SECTOR_HIGH'; }).map(function (f) { return f.id; });
        var sectorLowLabels = traversal.filter(function (f) { return f.type === 'SECTOR_LOW'; }).map(function (f) { return f.id; });
        var sectorSuperLabels = traversal.filter(function (f) { return f.type === 'SECTOR_SUPERHIGH'; }).map(function (f) { return f.id; });

        if (sectorHighLabels.length > 0 && map.getLayer('high-sector-search-include')) {
            map.setFilter('high-sector-search-include', ['in', 'label'].concat(sectorHighLabels));
        }
        if (sectorLowLabels.length > 0 && map.getLayer('low-sector-search-include')) {
            map.setFilter('low-sector-search-include', ['in', 'label'].concat(sectorLowLabels));
        }
        if (sectorSuperLabels.length > 0 && map.getLayer('superhigh-sector-search-include')) {
            map.setFilter('superhigh-sector-search-include', ['in', 'label'].concat(sectorSuperLabels));
        }
    }

    function clearHighlight() {
        var map = getMap();
        if (!map) return;

        // Restore route opacity
        try {
            ['routes-solid', 'routes-dashed', 'routes-fan'].forEach(function (layerId) {
                if (map.getLayer(layerId)) {
                    map.setPaintProperty(layerId, 'line-opacity', 0.9);
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
    }

    // ── Export ────────────────────────────────────────────────────────
    function buildHeaderLine() {
        var label = '';
        if (currentOrigin) label += currentOrigin;
        if (currentDest) label += ' > ' + currentDest;
        if (!label && currentRouteStr) label = currentRouteStr;
        return label;
    }

    function buildFacilityRows(sep) {
        var lines = [];
        var traversal = (currentData && currentData.facility_traversal) || [];
        lines.push(['#', 'Facility', 'Type', 'Dist (nm)', 'Time', 'Entry Fix', 'Exit Fix'].join(sep));
        for (var i = 0; i < traversal.length; i++) {
            var f = traversal[i];
            lines.push([
                i + 1,
                f.name || f.id || '',
                typeLabel(f.type),
                f.distance_within_nm != null ? Math.round(f.distance_within_nm) : '',
                formatTime(f.time_within_min),
                f.entry_fix || '',
                f.exit_fix || ''
            ].join(sep));
        }
        return lines;
    }

    function buildFixRows(sep) {
        var lines = [];
        var fixes = (currentData && currentData.fix_analysis) || [];
        lines.push(['#', 'Fix', 'Facility', 'Cum Dist (nm)', 'Cum Time', 'Seg Dist (nm)', 'Seg Time', 'Rem Dist (nm)', 'Rem Time'].join(sep));
        for (var i = 0; i < fixes.length; i++) {
            var fx = fixes[i];
            var segDist = '', segTime = '';
            if (i > 0) {
                var prev = fixes[i - 1];
                segDist = Math.round(fx.dist_from_origin_nm - prev.dist_from_origin_nm);
                segTime = formatTime(fx.time_from_origin_min - prev.time_from_origin_min);
            }
            lines.push([
                i + 1,
                fx.fix || '',
                fx.facility || '',
                fx.dist_from_origin_nm != null ? Math.round(fx.dist_from_origin_nm) : '',
                formatTime(fx.time_from_origin_min),
                segDist,
                segTime,
                fx.dist_to_dest_nm != null ? Math.round(fx.dist_to_dest_nm) : '',
                formatTime(fx.time_to_dest_min)
            ].join(sep));
        }
        return lines;
    }

    function buildTextContent(sep) {
        var lines = [];
        lines.push(buildHeaderLine());
        if (currentData) {
            lines.push('Total: ' + formatDist(currentData.total_distance_nm) + ' nm / ' + formatTime(currentData.total_time_min));
        }
        lines.push('');
        lines.push('FACILITY TRAVERSAL');
        lines = lines.concat(buildFacilityRows(sep));
        lines.push('');
        lines.push('FIX ANALYSIS');
        lines = lines.concat(buildFixRows(sep));
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
        var lines = [];
        lines.push('# ' + buildHeaderLine());
        if (currentData) {
            lines.push('# Total: ' + formatDist(currentData.total_distance_nm) + ' nm / ' + formatTime(currentData.total_time_min));
        }
        lines.push('');

        var traversal = (currentData && currentData.facility_traversal) || [];
        lines.push(['"#"', '"Facility"', '"Type"', '"Dist (nm)"', '"Time"', '"Entry Fix"', '"Exit Fix"'].join(','));
        for (var i = 0; i < traversal.length; i++) {
            var f = traversal[i];
            lines.push([
                i + 1,
                csvEscape(f.name || f.id || ''),
                csvEscape(typeLabel(f.type)),
                f.distance_within_nm != null ? Math.round(f.distance_within_nm) : '',
                csvEscape(formatTime(f.time_within_min)),
                csvEscape(f.entry_fix || ''),
                csvEscape(f.exit_fix || '')
            ].join(','));
        }
        lines.push('');

        var fixes = (currentData && currentData.fix_analysis) || [];
        lines.push(['"#"', '"Fix"', '"Facility"', '"Cum Dist (nm)"', '"Cum Time"', '"Seg Dist (nm)"', '"Seg Time"', '"Rem Dist (nm)"', '"Rem Time"'].join(','));
        for (var j = 0; j < fixes.length; j++) {
            var fx = fixes[j];
            var segDist = '', segTime = '';
            if (j > 0) {
                var prev = fixes[j - 1];
                segDist = Math.round(fx.dist_from_origin_nm - prev.dist_from_origin_nm);
                segTime = formatTime(fx.time_from_origin_min - prev.time_from_origin_min);
            }
            lines.push([
                j + 1,
                csvEscape(fx.fix || ''),
                csvEscape(fx.facility || ''),
                fx.dist_from_origin_nm != null ? Math.round(fx.dist_from_origin_nm) : '',
                csvEscape(formatTime(fx.time_from_origin_min)),
                segDist,
                csvEscape(segTime),
                fx.dist_to_dest_nm != null ? Math.round(fx.dist_to_dest_nm) : '',
                csvEscape(formatTime(fx.time_to_dest_min))
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

        var wb = XLSX.utils.book_new();

        // Facilities sheet
        var facData = [['#', 'Facility', 'Type', 'Dist (nm)', 'Time (min)', 'Entry Fix', 'Exit Fix']];
        var traversal = (currentData && currentData.facility_traversal) || [];
        for (var i = 0; i < traversal.length; i++) {
            var f = traversal[i];
            facData.push([
                i + 1,
                f.name || f.id || '',
                typeLabel(f.type),
                f.distance_within_nm != null ? Math.round(f.distance_within_nm) : null,
                f.time_within_min != null ? Math.round(f.time_within_min * 10) / 10 : null,
                f.entry_fix || '',
                f.exit_fix || ''
            ]);
        }
        var ws1 = XLSX.utils.aoa_to_sheet(facData);
        XLSX.utils.book_append_sheet(wb, ws1, 'Facilities');

        // Fixes sheet
        var fixData = [['#', 'Fix', 'Facility', 'Cum Dist (nm)', 'Cum Time (min)', 'Seg Dist (nm)', 'Seg Time (min)', 'Rem Dist (nm)', 'Rem Time (min)']];
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
        params.facility_types = 'ARTCC,FIR,TRACON';

        // Show loading
        if (facTbody) facTbody.innerHTML = '<tr><td colspan="6" class="ra-loading"><i class="fas fa-spinner fa-spin"></i> ' + t('routeAnalysis.loading') + '</td></tr>';
        if (fixTbody) fixTbody.innerHTML = '<tr><td colspan="8" class="ra-loading"><i class="fas fa-spinner fa-spin"></i> ' + t('routeAnalysis.loading') + '</td></tr>';

        $.getJSON('api/data/playbook/analysis.php', params, function (resp) {
            if (!resp || resp.status !== 'success') {
                if (facTbody) facTbody.innerHTML = '<tr><td colspan="6" class="ra-empty">' + t('routeAnalysis.error') + '</td></tr>';
                return;
            }
            if (resp.fix_analysis) resp.fix_analysis = deduplicateFixes(resp.fix_analysis);
            if (resp.waypoints) resp.waypoints = deduplicateFixes(resp.waypoints);
            currentData = resp;
            renderSummary(resp);
            renderFacilityTable(resp.facility_traversal || []);
            renderFixTable(resp.fix_analysis || [], resp.total_distance_nm, resp.total_time_min);
            highlightOnMap(resp);
        }).fail(function () {
            if (facTbody) facTbody.innerHTML = '<tr><td colspan="6" class="ra-empty">' + t('routeAnalysis.error') + '</td></tr>';
        });
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
    });

    // ── Public API ───────────────────────────────────────────────────
    window.RouteAnalysisPanel = {
        show: show,
        clear: clear,
        recalculate: recalculate,
        clearHighlight: clearHighlight,
        exportClipboard: exportClipboard,
        exportTXT: exportTXT,
        exportCSV: exportCSV,
        exportXLSX: exportXLSX,
        getCurrentData: function () { return currentData; }
    };
})();
