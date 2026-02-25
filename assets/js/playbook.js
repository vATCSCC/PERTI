/**
 * PlaybookCatalog — vATCSCC Playbook feature module
 * Browse, manage, and activate pre-coordinated SWAP route plays.
 */
(function() {
    'use strict';

    var API_LIST   = 'api/data/playbook/list.php';
    var API_GET    = 'api/data/playbook/get.php';
    var API_CATS   = 'api/data/playbook/categories.php';
    var API_LOG    = 'api/data/playbook/changelog.php';
    var API_SAVE   = 'api/mgt/playbook/save.php';
    var API_DELETE  = 'api/mgt/playbook/delete.php';

    var t = typeof PERTII18n !== 'undefined' ? PERTII18n.t.bind(PERTII18n) : function(k) { return k; };
    var hasPerm = window.PERTI_PLAYBOOK_PERM === true;

    // State
    var allPlays = [];          // Full loaded set from API
    var filteredPlays = [];     // After client-side filters
    var categoryData = {};      // { category_counts, categories, legacy_count }
    var activeCategory = '';    // '' = all
    var activeSource = '';      // '' = all
    var showLegacy = false;
    var searchText = '';
    var activePlayId = null;
    var activePlayData = null;
    var selectedRouteIds = new Set();

    // =========================================================================
    // HELPERS
    // =========================================================================

    function csvSplit(s) {
        return (s || '').split(',').map(function(x) { return x.trim(); }).filter(Boolean);
    }

    function escHtml(s) {
        var d = document.createElement('div');
        d.textContent = s || '';
        return d.innerHTML;
    }

    function getAiracCycle() {
        var now = new Date();
        var yy = String(now.getUTCFullYear()).slice(-2);
        var mm = String(now.getUTCMonth() + 1).padStart(2, '0');
        return yy + mm;
    }

    function normalizePlayName(name) {
        return (name || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
    }

    function isLegacy(playName) {
        return (playName || '').indexOf('_old_') !== -1;
    }

    // =========================================================================
    // CATEGORY PILLS
    // =========================================================================

    function loadCategories() {
        $.getJSON(API_CATS, function(data) {
            if (!data || !data.success) return;
            categoryData = data;
            renderCategoryPills();
        });
    }

    function renderCategoryPills() {
        var container = $('#pb_category_pills');
        var counts = categoryData.category_counts || {};
        var cats = categoryData.categories || [];

        // Total active plays
        var total = 0;
        for (var k in counts) total += counts[k];

        var html = '<span class="pb-pill' + (activeCategory === '' ? ' active' : '') + '" data-cat="">' +
                   t('playbook.allPlays') + ' <span class="pb-pill-count">' + total + '</span></span>';

        cats.forEach(function(cat) {
            var cnt = counts[cat] || 0;
            html += '<span class="pb-pill' + (activeCategory === cat ? ' active' : '') + '" data-cat="' + escHtml(cat) + '">' +
                    escHtml(cat) + ' <span class="pb-pill-count">' + cnt + '</span></span>';
        });

        container.html(html);
    }

    // =========================================================================
    // PLAY LOADING & CLIENT-SIDE FILTERING
    // =========================================================================

    function loadPlays() {
        var params = {
            per_page: 1000,
            status: 'active',
            hide_legacy: showLegacy ? 0 : 1
        };

        var qstr = Object.keys(params).map(function(k) {
            return params[k] !== '' ? k + '=' + encodeURIComponent(params[k]) : '';
        }).filter(Boolean).join('&');

        $('#pb_play_list_container').html(
            '<div class="pb-loading"><div class="spinner-border text-primary" role="status"></div></div>'
        );

        $.getJSON(API_LIST + '?' + qstr, function(data) {
            if (!data || !data.success) {
                $('#pb_play_list_container').html(
                    '<div class="pb-empty-state"><i class="fas fa-exclamation-triangle"></i>' + t('common.error') + '</div>'
                );
                return;
            }

            allPlays = data.data || [];
            applyFilters();
        }).fail(function() {
            $('#pb_play_list_container').html(
                '<div class="pb-empty-state"><i class="fas fa-exclamation-triangle"></i>' + t('common.error') + '</div>'
            );
        });
    }

    function applyFilters() {
        var search = searchText.toUpperCase();

        filteredPlays = allPlays.filter(function(p) {
            // Category filter
            if (activeCategory && p.category !== activeCategory) return false;
            // Source filter
            if (activeSource && p.source !== activeSource) return false;
            // Search filter — match play name, display name, description, facilities
            if (search) {
                var haystack = ((p.play_name || '') + ' ' + (p.display_name || '') + ' ' +
                    (p.description || '') + ' ' + (p.facilities_involved || '') + ' ' +
                    (p.impacted_area || '') + ' ' + (p.category || '')).toUpperCase();
                if (haystack.indexOf(search) === -1) return false;
            }
            return true;
        });

        $('#pb_stats').text(t('playbook.showingPlays', { count: filteredPlays.length }));
        if (!showLegacy && categoryData.legacy_count) {
            $('#pb_stats').append(' <span style="opacity:0.6;">(' + t('playbook.legacyHidden', { count: categoryData.legacy_count }) + ')</span>');
        }

        renderPlayList();
    }

    function renderPlayList() {
        if (!filteredPlays.length) {
            $('#pb_play_list_container').html(
                '<div class="pb-empty-state"><i class="fas fa-book-open"></i>' + t('playbook.noPlaysFound') + '</div>'
            );
            return;
        }

        var html = '<ul class="pb-play-list">';
        filteredPlays.forEach(function(p) {
            var isActive = p.play_id == activePlayId;
            html += '<li class="pb-play-row' + (isActive ? ' active' : '') + '" data-play-id="' + p.play_id + '">';
            html += '<span class="pb-play-row-name">' + escHtml(p.play_name) + '</span>';
            html += '<span class="pb-play-row-meta">';
            if (p.category) html += '<span class="pb-badge pb-badge-category">' + escHtml(p.category) + '</span>';
            html += '<span class="pb-badge pb-badge-routes">' + (p.route_count || 0) + '</span>';
            html += '<span class="pb-badge pb-badge-' + (p.source || 'dcc').toLowerCase() + '">' + escHtml(p.source || 'DCC') + '</span>';
            if (p.status === 'draft') html += '<span class="pb-badge pb-badge-draft">' + t('playbook.statusDraft') + '</span>';
            html += '</span>';
            html += '</li>';
        });
        html += '</ul>';

        $('#pb_play_list_container').html(html);
    }

    // =========================================================================
    // PLAY DETAIL
    // =========================================================================

    function loadPlayDetail(playId) {
        activePlayId = playId;
        selectedRouteIds.clear();

        // Highlight active row
        $('.pb-play-row').removeClass('active');
        $('[data-play-id="' + playId + '"]').addClass('active');

        var panel = $('#pb_detail_panel');
        var content = $('#pb_detail_content');
        panel.show();
        content.html('<div class="pb-loading py-2"><div class="spinner-border spinner-border-sm text-primary"></div></div>');

        // Expand map
        $('#pb_map_section').addClass('pb-map-expanded');

        $.getJSON(API_GET + '?id=' + playId, function(data) {
            if (!data || !data.success) {
                content.html('<div class="text-danger small">' + t('common.error') + '</div>');
                return;
            }

            var play = data.play;
            var routes = data.routes || [];
            play.routes = routes;
            activePlayData = play;

            renderDetailPanel(play, routes);

            // Auto-plot routes on map
            plotOnMap();

            // Inject DCC play for PB expansion
            if (play.source === 'DCC') {
                injectDccPlay(play, routes);
            }
        });
    }

    function renderDetailPanel(play, routes) {
        var html = '';

        // Header: play name + action buttons
        html += '<div class="pb-detail-header">';
        html += '<div>';
        html += '<div class="pb-detail-play-name">' + escHtml(play.play_name) + '</div>';
        if (play.display_name && play.display_name !== play.play_name) {
            html += '<div style="font-size:0.82rem;color:#555;">' + escHtml(play.display_name) + '</div>';
        }
        html += '</div>';
        html += '<div class="pb-actions">';
        html += '<button class="btn btn-warning btn-sm" id="pb_activate_btn"><i class="fas fa-paper-plane mr-1"></i>' + t('playbook.activateReroute') + '</button>';
        if (hasPerm && play.source === 'DCC') {
            html += '<button class="btn btn-outline-secondary btn-sm" id="pb_edit_btn"><i class="fas fa-edit mr-1"></i>' + t('common.edit') + '</button>';
        }
        if (hasPerm) {
            if (play.status === 'active' || play.status === 'draft') {
                html += '<button class="btn btn-outline-danger btn-sm" id="pb_archive_btn"><i class="fas fa-archive mr-1"></i>' + t('playbook.archive') + '</button>';
            } else if (play.status === 'archived') {
                html += '<button class="btn btn-outline-success btn-sm" id="pb_restore_btn"><i class="fas fa-undo mr-1"></i>' + t('playbook.restore') + '</button>';
            }
        }
        html += '</div>';
        html += '</div>';

        // Metadata badges
        var metaParts = [];
        if (play.category) metaParts.push('<span class="pb-badge pb-badge-category">' + escHtml(play.category) + '</span>');
        if (play.scenario_type) metaParts.push('<span class="badge badge-secondary" style="font-size:0.65rem;">' + escHtml(play.scenario_type) + '</span>');
        if (play.source) metaParts.push('<span class="pb-badge pb-badge-' + (play.source || 'dcc').toLowerCase() + '">' + escHtml(play.source) + '</span>');
        if (play.airac_cycle) metaParts.push('<span style="font-size:0.65rem;color:#888;">AIRAC ' + escHtml(play.airac_cycle) + '</span>');
        if (metaParts.length) {
            html += '<div class="pb-detail-meta">' + metaParts.join('') + '</div>';
        }

        // Description
        if (play.description) {
            html += '<div class="pb-play-description">' + escHtml(play.description) + '</div>';
        }

        // Facilities
        if (play.facilities_involved || play.impacted_area) {
            html += '<div class="pb-play-facilities"><i class="fas fa-map-marker-alt mr-1"></i>' + escHtml(play.impacted_area || play.facilities_involved) + '</div>';
        }

        // Route table
        if (routes.length) {
            html += '<div class="d-flex justify-content-between align-items-center mb-1">';
            html += '<span class="pb-select-all" id="pb_select_all">' + t('playbook.selectAll') + '</span>';
            html += '<span style="font-size:0.68rem;color:#999;">' + routes.length + ' ' + t('playbook.routes').toLowerCase() + '</span>';
            html += '</div>';

            html += '<div class="pb-route-table-wrap">';
            html += '<table class="pb-route-table"><thead><tr>';
            html += '<th class="pb-route-check"><input type="checkbox" id="pb_check_all"></th>';
            html += '<th>Origin</th>';
            html += '<th>TRACON</th>';
            html += '<th>ARTCC</th>';
            html += '<th>' + t('playbook.routeString') + '</th>';
            html += '<th>Dest</th>';
            html += '<th>TRACON</th>';
            html += '<th>ARTCC</th>';
            html += '</tr></thead><tbody>';

            routes.forEach(function(r) {
                var origApt = r.origin_airports || r.origin || '-';
                var origTracon = r.origin_tracons || '-';
                var origArtcc = r.origin_artccs || '-';
                var destApt = r.dest_airports || r.dest || '-';
                var destTracon = r.dest_tracons || '-';
                var destArtcc = r.dest_artccs || '-';
                html += '<tr data-route-id="' + r.route_id + '">';
                html += '<td class="pb-route-check"><input type="checkbox" class="pb-route-cb" value="' + r.route_id + '"></td>';
                html += '<td>' + escHtml(origApt) + (r.origin_filter ? ' <small class="text-muted">' + escHtml(r.origin_filter) + '</small>' : '') + '</td>';
                html += '<td>' + escHtml(origTracon) + '</td>';
                html += '<td>' + escHtml(origArtcc) + '</td>';
                html += '<td>' + escHtml(r.route_string) + '</td>';
                html += '<td>' + escHtml(destApt) + (r.dest_filter ? ' <small class="text-muted">' + escHtml(r.dest_filter) + '</small>' : '') + '</td>';
                html += '<td>' + escHtml(destTracon) + '</td>';
                html += '<td>' + escHtml(destArtcc) + '</td>';
                html += '</tr>';
            });

            html += '</tbody></table></div>';
        } else {
            html += '<div class="pb-empty-state"><i class="fas fa-route"></i>' + t('playbook.noRoutes') + '</div>';
        }

        // Changelog toggle
        html += '<div class="pb-changelog">';
        html += '<div class="pb-changelog-header" id="pb_changelog_toggle"><i class="fas fa-chevron-right"></i> ' + t('playbook.changelog') + '</div>';
        html += '<div id="pb_changelog_content" style="display:none;"></div>';
        html += '</div>';

        $('#pb_detail_content').html(html);
    }

    function hideDetail() {
        activePlayId = null;
        activePlayData = null;
        selectedRouteIds.clear();
        $('.pb-play-row').removeClass('active');
        $('#pb_detail_panel').hide();
        $('#pb_map_section').removeClass('pb-map-expanded');

        // Clear map routes
        var textarea = document.getElementById('routeSearch');
        var plotBtn = document.getElementById('plot_r');
        if (textarea && plotBtn) {
            textarea.value = '';
            plotBtn.click();
        }
    }

    // =========================================================================
    // MAP INTEGRATION
    // =========================================================================

    function getSelectedRoutes() {
        if (!activePlayData) return [];
        var routes = activePlayData.routes || [];
        if (!selectedRouteIds.size) return routes;
        return routes.filter(function(r) { return selectedRouteIds.has(r.route_id); });
    }

    function plotOnMap() {
        var routes = getSelectedRoutes();
        if (!routes.length) return;

        var lines = routes.map(function(r) {
            var parts = [];
            if (r.origin) parts.push(r.origin);
            parts.push(r.route_string);
            if (r.dest) parts.push(r.dest);
            return parts.join(' ');
        });

        var textarea = document.getElementById('routeSearch');
        var plotBtn = document.getElementById('plot_r');
        if (textarea && plotBtn) {
            textarea.value = lines.join('\n');
            plotBtn.click();
        }
    }

    function activateAsReroute() {
        var play = activePlayData;
        if (!play) return;
        var routes = getSelectedRoutes();
        if (!routes.length) {
            if (typeof Swal !== 'undefined') {
                Swal.fire({ icon: 'info', title: t('playbook.noRoutes'), text: t('playbook.noRoutesText'), confirmButtonText: t('common.ok') });
            }
            return;
        }

        // Plot first so GeoJSON is available
        plotOnMap();

        var attempts = 0;
        var pollInterval = setInterval(function() {
            var features = (typeof MapLibreRoute !== 'undefined' && MapLibreRoute.getCurrentRouteFeatures)
                ? MapLibreRoute.getCurrentRouteFeatures() : null;
            attempts++;
            if (!features && attempts < 30) return;
            clearInterval(pollInterval);

            var rerouteData = {
                version: '1.0',
                timestamp: new Date().toISOString(),
                source: 'playbook',
                advisory: {
                    number: null,
                    facility: 'DCC',
                    name: play.display_name || play.play_name,
                    constrainedArea: play.impacted_area || '',
                    reason: play.scenario_type || 'WEATHER',
                    routeType: 'ROUTE',
                    includeTraffic: '',
                    validStart: '',
                    validEnd: '',
                    timeBasis: 'ETD',
                    probExtension: 'MEDIUM',
                    remarks: '',
                    restrictions: '',
                    modifications: ''
                },
                facilities: csvSplit(play.facilities_involved),
                routes: routes.map(function(r) {
                    return {
                        origin: r.origin || '',
                        originFilter: r.origin_filter || '',
                        originAirports: csvSplit(r.origin_airports),
                        originArtccs: csvSplit(r.origin_artccs),
                        destination: r.dest || '',
                        destFilter: r.dest_filter || '',
                        destAirports: csvSplit(r.dest_airports),
                        destArtccs: csvSplit(r.dest_artccs),
                        route: r.route_string
                    };
                }),
                rawInput: 'PB.' + play.play_name,
                procedures: ['PB: ' + play.play_name],
                geojson: features
            };

            try {
                sessionStorage.setItem('tmi_reroute_draft', JSON.stringify(rerouteData));
                sessionStorage.setItem('tmi_reroute_draft_timestamp', Date.now().toString());
                localStorage.setItem('tmi_reroute_draft', JSON.stringify(rerouteData));
                localStorage.setItem('tmi_reroute_draft_timestamp', Date.now().toString());
            } catch (e) {
                console.error('[Playbook] Storage error:', e);
            }

            window.open('tmi-publish?mode=reroute&tab=reroute#reroutePanel', '_blank');
        }, 100);
    }

    // =========================================================================
    // DCC PLAY INJECTION (PB expansion support)
    // =========================================================================

    function injectDccPlay(play, routes) {
        if (!window.playbookByPlayName) window.playbookByPlayName = {};
        if (!window.playbookRoutes) window.playbookRoutes = [];

        var norm = normalizePlayName(play.play_name);
        if (!window.playbookByPlayName[norm]) window.playbookByPlayName[norm] = [];

        routes.forEach(function(r) {
            var entry = {
                playName: play.play_name,
                playNameNorm: norm,
                fullRoute: (r.route_string || '').toUpperCase(),
                originAirportsSet: new Set(csvSplit(r.origin_airports)),
                destAirportsSet: new Set(csvSplit(r.dest_airports)),
                originArtccsSet: new Set(csvSplit(r.origin_artccs)),
                destArtccsSet: new Set(csvSplit(r.dest_artccs)),
                originField: r.origin || '',
                destField: r.dest || ''
            };
            window.playbookRoutes.push(entry);
            window.playbookByPlayName[norm].push(entry);
        });
    }

    function injectAllDccPlays() {
        $.getJSON(API_LIST + '?source=DCC&status=active&per_page=500', function(data) {
            if (!data || !data.success) return;
            (data.data || []).forEach(function(p) {
                $.getJSON(API_GET + '?id=' + p.play_id, function(d) {
                    if (d && d.success && d.routes) {
                        injectDccPlay(d.play, d.routes);
                    }
                });
            });
        });
    }

    // =========================================================================
    // AUTO-COMPUTATION
    // =========================================================================

    function autoComputeRouteFields(routeString) {
        if (typeof MapLibreRoute === 'undefined' || !MapLibreRoute.parseRoutesEnhanced) return null;
        if (typeof FacilityHierarchy === 'undefined') return null;

        var parsed = MapLibreRoute.parseRoutesEnhanced([routeString]);
        if (!parsed || !parsed.length) return null;
        var r = parsed[0];

        var origTracons = (r.origAirports || [])
            .map(function(a) { return FacilityHierarchy.AIRPORT_TO_TRACON ? FacilityHierarchy.AIRPORT_TO_TRACON[a] : null; })
            .filter(Boolean);

        var origArtccs = (r.origArtccs || []).slice();
        (r.origAirports || []).forEach(function(a) {
            var artcc = FacilityHierarchy.getParentArtcc ? FacilityHierarchy.getParentArtcc(a) : null;
            if (artcc) origArtccs.push(artcc);
        });

        var destTracons = (r.destAirports || [])
            .map(function(a) { return FacilityHierarchy.AIRPORT_TO_TRACON ? FacilityHierarchy.AIRPORT_TO_TRACON[a] : null; })
            .filter(Boolean);

        var destArtccs = (r.destArtccs || []).slice();
        (r.destAirports || []).forEach(function(a) {
            var artcc = FacilityHierarchy.getParentArtcc ? FacilityHierarchy.getParentArtcc(a) : null;
            if (artcc) destArtccs.push(artcc);
        });

        return {
            origin: r.orig || '',
            dest: r.dest || '',
            origin_airports: (r.origAirports || []).join(','),
            origin_tracons: unique(origTracons).join(','),
            origin_artccs: unique(origArtccs).join(','),
            dest_airports: (r.destAirports || []).join(','),
            dest_tracons: unique(destTracons).join(','),
            dest_artccs: unique(destArtccs).join(',')
        };
    }

    function unique(arr) {
        var seen = {};
        return arr.filter(function(v) {
            if (seen[v]) return false;
            seen[v] = true;
            return true;
        });
    }

    async function autoComputePlayFields(routes) {
        var allArtccs = new Set();
        routes.forEach(function(r) {
            csvSplit(r.origin_artccs).forEach(function(a) { allArtccs.add(a); });
            csvSplit(r.dest_artccs).forEach(function(a) { allArtccs.add(a); });
        });

        try {
            var routeStrings = routes.map(function(r) { return r.route_string; }).filter(Boolean);
            if (routeStrings.length) {
                var resp = await fetch('/api/gis/boundaries?action=expand_routes', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ routes: routeStrings })
                });
                var gis = await resp.json();
                if (gis && gis.artccs_all) {
                    gis.artccs_all.forEach(function(a) { allArtccs.add(a); });
                }
            }
        } catch (e) {
            console.warn('[Playbook] GIS facilities calculation failed:', e);
        }

        var sorted = Array.from(allArtccs).sort();
        return {
            facilities_involved: sorted.join(','),
            impacted_area: sorted.join('/')
        };
    }

    // =========================================================================
    // CRUD — CREATE / EDIT
    // =========================================================================

    function openCreateModal() {
        $('#pb_modal_title').text(t('playbook.createPlay'));
        $('#pb_edit_play_id').val(0);
        $('#pb_edit_play_name').val('');
        $('#pb_edit_display_name').val('');
        $('#pb_edit_category').val('');
        $('#pb_edit_scenario_type').val('');
        $('#pb_edit_route_format').val('standard');
        $('#pb_edit_description').val('');
        $('#pb_edit_status').val('active');
        $('#pb_route_edit_body').empty();
        $('#pb_bulk_paste_area').hide();
        addEditRouteRow();
        $('#pb_play_modal').modal('show');
    }

    function openEditModal(play, routes) {
        $('#pb_modal_title').text(t('playbook.editPlay'));
        $('#pb_edit_play_id').val(play.play_id);
        $('#pb_edit_play_name').val(play.play_name || '');
        $('#pb_edit_display_name').val(play.display_name || '');
        $('#pb_edit_category').val(play.category || '');
        $('#pb_edit_scenario_type').val(play.scenario_type || '');
        $('#pb_edit_route_format').val(play.route_format || 'standard');
        $('#pb_edit_description').val(play.description || '');
        $('#pb_edit_status').val(play.status || 'active');

        var tbody = $('#pb_route_edit_body');
        tbody.empty();
        (routes || []).forEach(function(r) {
            addEditRouteRow(r);
        });

        $('#pb_bulk_paste_area').hide();
        $('#pb_play_modal').modal('show');
    }

    function addEditRouteRow(r) {
        var route = r || {};
        var html = '<tr>';
        html += '<td><textarea class="form-control form-control-sm pb-re-route" rows="1">' + escHtml(route.route_string || '') + '</textarea></td>';
        html += '<td><input class="form-control form-control-sm pb-re-origin" value="' + escHtml(route.origin || '') + '"></td>';
        html += '<td><input class="form-control form-control-sm pb-re-origin-filter" value="' + escHtml(route.origin_filter || '') + '"></td>';
        html += '<td><input class="form-control form-control-sm pb-re-dest" value="' + escHtml(route.dest || '') + '"></td>';
        html += '<td><input class="form-control form-control-sm pb-re-dest-filter" value="' + escHtml(route.dest_filter || '') + '"></td>';
        html += '<td><button class="btn btn-sm btn-outline-danger pb-re-delete" title="' + t('playbook.deleteRoute') + '"><i class="fas fa-times"></i></button></td>';
        html += '</tr>';
        $('#pb_route_edit_body').append(html);
    }

    function applyBulkPaste() {
        var text = $('#pb_bulk_paste_text').val().trim();
        if (!text) return;

        var lines = text.split('\n').filter(function(l) { return l.trim(); });
        lines.forEach(function(line) {
            addEditRouteRow({ route_string: line.trim() });
        });

        $('#pb_bulk_paste_text').val('');
        $('#pb_bulk_paste_area').slideUp(150);
    }

    async function savePlay() {
        var playId = parseInt($('#pb_edit_play_id').val()) || 0;
        var playName = $('#pb_edit_play_name').val().trim();
        if (!playName) {
            if (typeof Swal !== 'undefined') Swal.fire({ icon: 'warning', title: t('playbook.playNameRequired'), confirmButtonText: t('common.ok') });
            return;
        }

        var routes = [];
        $('#pb_route_edit_body tr').each(function() {
            var $tr = $(this);
            var routeStr = $tr.find('.pb-re-route').val().trim();
            if (!routeStr) return;

            var origin = $tr.find('.pb-re-origin').val().trim();
            var originFilter = $tr.find('.pb-re-origin-filter').val().trim();
            var dest = $tr.find('.pb-re-dest').val().trim();
            var destFilter = $tr.find('.pb-re-dest-filter').val().trim();

            var computed = autoComputeRouteFields(
                (origin ? origin + ' ' : '') + routeStr + (dest ? ' ' + dest : '')
            );

            routes.push({
                route_string: routeStr,
                origin: origin || (computed ? computed.origin : ''),
                origin_filter: originFilter,
                dest: dest || (computed ? computed.dest : ''),
                dest_filter: destFilter,
                origin_airports: computed ? computed.origin_airports : '',
                origin_tracons: computed ? computed.origin_tracons : '',
                origin_artccs: computed ? computed.origin_artccs : '',
                dest_airports: computed ? computed.dest_airports : '',
                dest_tracons: computed ? computed.dest_tracons : '',
                dest_artccs: computed ? computed.dest_artccs : ''
            });
        });

        var playFields = await autoComputePlayFields(routes);

        var body = {
            play_id: playId,
            play_name: playName,
            display_name: $('#pb_edit_display_name').val().trim(),
            description: $('#pb_edit_description').val().trim(),
            category: $('#pb_edit_category').val().trim(),
            scenario_type: $('#pb_edit_scenario_type').val(),
            route_format: $('#pb_edit_route_format').val(),
            status: $('#pb_edit_status').val(),
            airac_cycle: getAiracCycle(),
            facilities_involved: playFields.facilities_involved,
            impacted_area: playFields.impacted_area,
            routes: routes
        };

        var $btn = $('#pb_save_play_btn');
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i>' + t('common.save'));

        $.ajax({
            url: API_SAVE,
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(body),
            dataType: 'json',
            success: function(data) {
                $btn.prop('disabled', false).html('<i class="fas fa-save mr-1"></i>' + t('common.save'));
                if (data && data.success) {
                    $('#pb_play_modal').modal('hide');
                    if (typeof Swal !== 'undefined') Swal.fire({ icon: 'success', title: t('common.success'), text: t('playbook.playSaved'), timer: 1500, showConfirmButton: false });
                    activePlayId = data.play_id;
                    loadPlays();
                    loadCategories();
                } else {
                    if (typeof Swal !== 'undefined') Swal.fire({ icon: 'error', title: t('common.error'), text: data.error || t('common.unknownError') });
                }
            },
            error: function() {
                $btn.prop('disabled', false).html('<i class="fas fa-save mr-1"></i>' + t('common.save'));
                if (typeof Swal !== 'undefined') Swal.fire({ icon: 'error', title: t('common.error'), text: t('playbook.saveFailed') });
            }
        });
    }

    // =========================================================================
    // CRUD — ARCHIVE / RESTORE
    // =========================================================================

    function archivePlay(playId) {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: 'warning',
                title: t('playbook.confirmArchive'),
                text: t('playbook.confirmArchiveText'),
                showCancelButton: true,
                confirmButtonText: t('common.confirm'),
                cancelButtonText: t('common.cancel')
            }).then(function(result) {
                if (result.isConfirmed) doAction(playId, 'archive');
            });
        } else {
            if (confirm(t('playbook.confirmArchive'))) doAction(playId, 'archive');
        }
    }

    function restorePlay(playId) {
        doAction(playId, 'restore');
    }

    function doAction(playId, action) {
        $.ajax({
            url: API_DELETE,
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ play_id: playId, action: action, airac_cycle: getAiracCycle() }),
            dataType: 'json',
            success: function(data) {
                if (data && data.success) {
                    if (typeof Swal !== 'undefined') Swal.fire({ icon: 'success', title: t('common.success'), timer: 1500, showConfirmButton: false });
                    hideDetail();
                    loadPlays();
                    loadCategories();
                } else {
                    if (typeof Swal !== 'undefined') Swal.fire({ icon: 'error', title: t('common.error'), text: data.error || t('common.unknownError') });
                }
            },
            error: function() {
                if (typeof Swal !== 'undefined') Swal.fire({ icon: 'error', title: t('common.error') });
            }
        });
    }

    // =========================================================================
    // CHANGELOG
    // =========================================================================

    function loadChangelog(playId) {
        var container = $('#pb_changelog_content');
        container.html('<div class="pb-loading py-1"><div class="spinner-border spinner-border-sm text-primary"></div></div>');

        $.getJSON(API_LOG + '?play_id=' + playId + '&per_page=20', function(data) {
            if (!data || !data.success || !data.data || !data.data.length) {
                container.html('<div class="small text-muted py-1">' + t('playbook.noChanges') + '</div>');
                return;
            }

            var html = '<ul class="pb-changelog-list">';
            data.data.forEach(function(entry) {
                html += '<li class="pb-changelog-item">';
                html += '<span class="pb-changelog-action">' + escHtml(entry.action) + '</span>';
                if (entry.field_name) {
                    html += ' <span class="pb-changelog-field">' + escHtml(entry.field_name) + '</span>';
                }
                if (entry.old_value || entry.new_value) {
                    html += ' <span class="pb-changelog-diff">';
                    if (entry.old_value) html += '<span class="pb-changelog-old">' + escHtml(entry.old_value.substring(0, 80)) + '</span>';
                    html += ' &rarr; ';
                    if (entry.new_value) html += '<span class="pb-changelog-new">' + escHtml(entry.new_value.substring(0, 80)) + '</span>';
                    html += '</span>';
                }
                html += ' <span class="text-muted" style="font-size:0.58rem;">' + escHtml(entry.changed_by || '') + ' ' + escHtml(entry.changed_at || '') + '</span>';
                html += '</li>';
            });
            html += '</ul>';
            container.html(html);
        });
    }

    // =========================================================================
    // EVENT HANDLERS
    // =========================================================================

    var searchTimer = null;

    $(document).ready(function() {
        loadCategories();
        loadPlays();
        injectAllDccPlays();

        // Search with debounce
        $('#pb_search').on('input', function() {
            searchText = ($(this).val() || '').trim();
            clearTimeout(searchTimer);
            searchTimer = setTimeout(applyFilters, 200);
        });

        // Category pills
        $(document).on('click', '.pb-pill', function() {
            activeCategory = $(this).data('cat') || '';
            $('.pb-pill').removeClass('active');
            $(this).addClass('active');
            applyFilters();
        });

        // Source toggle buttons
        $(document).on('click', '.pb-src-btn', function() {
            activeSource = $(this).data('source') || '';
            $('.pb-src-btn').removeClass('active');
            $(this).addClass('active');
            applyFilters();
        });

        // Legacy toggle
        $('#pb_legacy_toggle').on('change', function() {
            showLegacy = this.checked;
            loadPlays(); // Re-fetch from API since hide_legacy is server-side
        });

        // Play row click
        $(document).on('click', '.pb-play-row', function() {
            var playId = $(this).data('play-id');
            if (playId == activePlayId) {
                hideDetail();
            } else {
                loadPlayDetail(playId);
            }
        });

        // Route checkboxes
        $(document).on('change', '.pb-route-cb', function() {
            var rid = parseInt($(this).val());
            if (this.checked) { selectedRouteIds.add(rid); } else { selectedRouteIds.delete(rid); }
        });
        $(document).on('change', '#pb_check_all', function() {
            var checked = this.checked;
            selectedRouteIds.clear();
            $('.pb-route-cb').each(function() {
                this.checked = checked;
                if (checked) selectedRouteIds.add(parseInt($(this).val()));
            });
        });
        $(document).on('click', '#pb_select_all', function() {
            var allChecked = selectedRouteIds.size === $('.pb-route-cb').length;
            $('#pb_check_all').prop('checked', !allChecked).trigger('change');
        });

        // Action buttons (in detail panel)
        $(document).on('click', '#pb_activate_btn', activateAsReroute);

        // Edit
        $(document).on('click', '#pb_edit_btn', function() {
            if (activePlayData) {
                openEditModal(activePlayData, activePlayData.routes || []);
            }
        });

        // Archive / Restore
        $(document).on('click', '#pb_archive_btn', function() {
            if (activePlayId) archivePlay(activePlayId);
        });
        $(document).on('click', '#pb_restore_btn', function() {
            if (activePlayId) restorePlay(activePlayId);
        });

        // Create
        $('#pb_create_btn').on('click', openCreateModal);

        // Save
        $('#pb_save_play_btn').on('click', savePlay);

        // Add route row in edit modal
        $('#pb_add_route_btn').on('click', function() { addEditRouteRow(); });

        // Delete route row in edit modal
        $(document).on('click', '.pb-re-delete', function() {
            $(this).closest('tr').remove();
        });

        // Bulk paste toggle
        $('#pb_bulk_paste_btn').on('click', function() {
            $('#pb_bulk_paste_area').slideToggle(150);
        });
        $('#pb_bulk_paste_apply').on('click', applyBulkPaste);

        // Changelog toggle
        $(document).on('click', '#pb_changelog_toggle', function() {
            var $this = $(this);
            var content = $('#pb_changelog_content');
            if (content.is(':visible')) {
                content.slideUp(150);
                $this.removeClass('expanded');
            } else {
                content.slideDown(150);
                $this.addClass('expanded');
                if (activePlayId && !content.find('.pb-changelog-list').length) {
                    loadChangelog(activePlayId);
                }
            }
        });

        // Re-plot when route selection changes
        $(document).on('change', '.pb-route-cb, #pb_check_all', function() {
            if (activePlayData) plotOnMap();
        });
    });

})();
