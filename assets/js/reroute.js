/**
 * assets/js/reroute.js
 *
 * PERTI Reroute Management Controller
 */

(function() {
    'use strict';

    const state = {
        currentReroute: null,
        previewFlights: [],
        assignedFlights: [],
        selectedFlights: new Set(),
        map: null,
        layers: { base: null, artcc: null, protected: null, avoid: null, flights: null, tracks: null },
        airportInfo: {},
        fixDatabase: {},
        refreshInterval: null,
        refreshRateMs: 15000,
        lastRefresh: null,
        isMonitoring: false,
        isPreviewMode: true,
    };

    const STATUS_CLASSES = {
        'PENDING': 'badge-secondary', 'MONITORING': 'badge-info', 'COMPLIANT': 'badge-success',
        'PARTIAL': 'badge-warning', 'NON_COMPLIANT': 'badge-danger',
        'EXEMPT': 'badge-light border', 'UNKNOWN': 'badge-dark',
    };

    const STATUS_ICONS = {
        'PENDING': 'fa-clock', 'MONITORING': 'fa-eye', 'COMPLIANT': 'fa-check-circle',
        'PARTIAL': 'fa-exclamation-triangle', 'NON_COMPLIANT': 'fa-times-circle',
        'EXEMPT': 'fa-ban', 'UNKNOWN': 'fa-question-circle',
    };

    // ═══════════════════════════════════════════════════════════════════════
    // INITIALIZATION
    // ═══════════════════════════════════════════════════════════════════════

    async function init() {
        console.log('[Reroute] Initializing...');
        startUtcClock();
        initMap();
        bindEvents();
        await loadReferenceData();

        const rerouteId = document.getElementById('rr_id')?.value;
        if (rerouteId) {await loadReroute(rerouteId);}

        console.log('[Reroute] Ready');
    }

    function startUtcClock() {
        function tick() {
            const now = new Date();
            const el = document.getElementById('rr_utc_clock');
            if (el) {el.textContent = now.toISOString().substr(11, 8) + 'Z';}

            if (state.lastRefresh && state.isMonitoring) {
                const ago = Math.round((now - state.lastRefresh) / 1000);
                const status = document.getElementById('rr_adl_status');
                if (status) {status.textContent = PERTII18n.t('reroute.refreshAgo', { seconds: ago });}
            }
        }
        tick();
        setInterval(tick, 1000);
    }

    function initMap() {
        state.map = L.map('rr_map_container').setView([39.5, -98.35], 4);
        state.layers.base = L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
            attribution: '© CARTO', maxZoom: 12,
        }).addTo(state.map);

        ['artcc', 'protected', 'avoid', 'flights', 'tracks'].forEach(l => {
            state.layers[l] = L.layerGroup().addTo(state.map);
        });

        loadArtccBoundaries();
    }

    async function loadArtccBoundaries() {
        try {
            const r = await fetch('assets/geojson/artcc.json');
            if (r.ok) {
                const data = await r.json();
                L.geoJSON(data, {
                    style: { color: '#444', weight: 1, fillOpacity: 0.02 },
                    onEachFeature: (f, layer) => f.properties?.id && layer.bindTooltip(f.properties.id, { sticky: true }),
                }).addTo(state.layers.artcc);
            }
        } catch (e) { /* optional */ }
    }

    async function loadReferenceData() {
        try {
            const r = await fetch('assets/data/apts.csv');
            if (r.ok) {
                const text = await r.text();
                text.split('\n').slice(1).forEach(line => {
                    const p = line.split(',');
                    if (p.length >= 4) {
                        state.airportInfo[p[0].trim()] = { lat: parseFloat(p[1]), lon: parseFloat(p[2]), artcc: p[3].trim() };
                    }
                });
            }
        } catch (e) { /* optional */ }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // EVENT BINDINGS
    // ═══════════════════════════════════════════════════════════════════════

    function bindEvents() {
        document.getElementById('rr_preview_btn')?.addEventListener('click', previewFlights);
        document.getElementById('rr_save_btn')?.addEventListener('click', saveReroute);
        document.getElementById('rr_activate_btn')?.addEventListener('click', activateReroute);
        document.getElementById('rr_deactivate_btn')?.addEventListener('click', deactivateReroute);
        document.getElementById('rr_reset_btn')?.addEventListener('click', resetForm);
        document.getElementById('rr_refresh_compliance_btn')?.addEventListener('click', refreshCompliance);

        ['rr_protected_fixes', 'rr_avoid_fixes'].forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                el.addEventListener('change', updateRouteVisualization);
                el.addEventListener('blur', updateRouteVisualization);
            }
        });

        document.getElementById('rr_show_artcc')?.addEventListener('change', function() {
            this.checked ? state.map.addLayer(state.layers.artcc) : state.map.removeLayer(state.layers.artcc);
        });
        document.getElementById('rr_show_fixes')?.addEventListener('change', function() {
            [state.layers.protected, state.layers.avoid].forEach(l => this.checked ? state.map.addLayer(l) : state.map.removeLayer(l));
        });
        document.getElementById('rr_show_flights')?.addEventListener('change', function() {
            this.checked ? state.map.addLayer(state.layers.flights) : state.map.removeLayer(state.layers.flights);
        });

        document.querySelectorAll('input[name="loadFilter"]').forEach(r => r.addEventListener('change', loadReroutesList));
        document.getElementById('loadRerouteModal')?.addEventListener('show.bs.modal', loadReroutesList);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // FORM HELPERS
    // ═══════════════════════════════════════════════════════════════════════

    const getValue = id => document.getElementById(id)?.value?.trim() || '';
    const setValue = (id, v) => { const el = document.getElementById(id); if (el) {el.value = v || '';} };

    function collectFormData() {
        const fields = ['id', 'name', 'adv_number', 'start_utc', 'end_utc', 'origin_airports', 'origin_centers',
            'dest_airports', 'dest_centers', 'protected_fixes', 'avoid_fixes', 'protected_segment',
            'include_ac_cat', 'altitude_min', 'altitude_max', 'include_carriers', 'departure_fix',
            'arrival_fix', 'thru_fixes', 'time_basis', 'airborne_filter', 'exempt_airports',
            'exempt_carriers', 'exempt_flights', 'comments'];
        const data = {};
        fields.forEach(f => data[f] = getValue('rr_' + f));
        if (!data.id) {data.id = null;}
        return data;
    }

    function populateForm(reroute) {
        Object.keys(reroute).forEach(k => setValue('rr_' + k, reroute[k]));
        const badge = document.getElementById('rr_status_badge');
        if (badge && reroute.status_label) {
            badge.textContent = reroute.status_label;
            badge.className = 'badge badge-' + ['secondary','info','success','warning','dark','danger'][reroute.status] + ' ml-2';
        }
        updateRouteVisualization();
    }

    function resetForm() {
        document.getElementById('rr_form')?.reset();
        setValue('rr_id', '');
        state.currentReroute = null;
        state.previewFlights = [];
        state.assignedFlights = [];
        state.selectedFlights.clear();
        clearFlightTable();
        clearRouteVisualization();
        displayStatistics({});
        stopMonitoring();
    }

    // ═══════════════════════════════════════════════════════════════════════
    // CRUD
    // ═══════════════════════════════════════════════════════════════════════

    async function loadReroute(id) {
        try {
            const r = await fetch(`api/data/tmi/reroute.php?id=${id}&include_flights=1&include_stats=1`);
            const data = await r.json();
            if (data.status !== 'ok') {throw new Error(data.message);}

            state.currentReroute = data.reroute;
            state.isPreviewMode = data.reroute.status < 2;
            populateForm(data.reroute);

            if (data.reroute.flights?.length) {
                state.assignedFlights = data.reroute.flights;
                renderFlightTable(state.assignedFlights, 'assigned');
                updateFlightsOnMap(state.assignedFlights);
            }

            if (data.reroute.statistics) {displayStatistics(data.reroute.statistics);}
            if (data.reroute.status >= 2 && data.reroute.status <= 3) {startMonitoring();}
        } catch (e) {
            console.error('[Reroute]', e);
            showToast(PERTII18n.t('reroute.loadError', { message: e.message }), 'danger');
        }
    }

    async function saveReroute() {
        const formData = collectFormData();
        if (!formData.name) {return showToast(PERTII18n.t('reroute.nameRequired'), 'warning');}

        try {
            const body = new URLSearchParams();
            Object.entries(formData).forEach(([k, v]) => v !== null && v !== undefined && body.append(k, v));

            const r = await fetch('api/mgt/tmi/reroutes/post.php', { method: 'POST', body });
            const data = await r.json();
            if (data.status !== 'ok') {throw new Error(data.message);}

            if (data.action === 'created') {
                setValue('rr_id', data.id);
                history.pushState({}, '', `reroutes.php?id=${data.id}`);
            }
            showToast(PERTII18n.t('reroute.rerouteSaved', { action: data.action }), 'success');
        } catch (e) {
            showToast(PERTII18n.t('reroute.saveError', { message: e.message }), 'danger');
        }
    }

    async function activateReroute() {
        let id = getValue('rr_id');
        if (!id) { await saveReroute(); id = getValue('rr_id'); }
        if (!id || !confirm(PERTII18n.t('reroute.confirmActivate'))) {return;}

        try {
            await fetch('api/mgt/tmi/reroutes/activate.php', { method: 'POST', body: new URLSearchParams({ id, action: 'activate' }) });
            const ar = await fetch('api/tmi/rr_assign.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ reroute_id: id, mode: 'replace' }),
            });
            const ad = await ar.json();
            showToast(PERTII18n.t('reroute.activated', { count: ad.assigned }), 'success');
            setTimeout(() => location.reload(), 1000);
        } catch (e) {
            showToast(PERTII18n.t('reroute.genericError', { message: e.message }), 'danger');
        }
    }

    async function deactivateReroute() {
        const id = getValue('rr_id');
        if (!id || !confirm(PERTII18n.t('reroute.confirmDeactivate'))) {return;}
        try {
            await fetch('api/mgt/tmi/reroutes/activate.php', { method: 'POST', body: new URLSearchParams({ id, action: 'monitor' }) });
            showToast(PERTII18n.t('reroute.movedToMonitoring'), 'success');
            setTimeout(() => location.reload(), 1000);
        } catch (e) {
            showToast(PERTII18n.t('reroute.genericError', { message: e.message }), 'danger');
        }
    }

    async function loadReroutesList() {
        const filter = document.querySelector('input[name="loadFilter"]:checked')?.value || '';
        try {
            const r = await fetch('api/data/tmi/reroutes.php' + (filter ? `?status=${filter}` : ''));
            const data = await r.json();
            const tbody = document.getElementById('load_reroutes_body');
            if (!data.reroutes?.length) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">' + PERTII18n.t('reroute.noReroutes') + '</td></tr>';
                return;
            }
            tbody.innerHTML = data.reroutes.map(r => `
                <tr>
                    <td><span class="badge badge-${['secondary','info','success','warning','dark','danger'][r.status]}">${r.status_label}</span></td>
                    <td>${esc(r.name)}</td>
                    <td><small>${r.start_utc || '--'} - ${r.end_utc || '--'}</small></td>
                    <td><small>${r.origin_centers || r.origin_airports || '--'} → ${r.dest_centers || r.dest_airports || '--'}</small></td>
                    <td><small>${r.updated_utc || '--'}</small></td>
                    <td><button class="btn btn-sm btn-primary" onclick="location='reroutes.php?id=${r.id}'"><i class="fas fa-edit"></i></button></td>
                </tr>
            `).join('');
        } catch (e) { console.error(e); }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // PREVIEW & COMPLIANCE
    // ═══════════════════════════════════════════════════════════════════════

    async function previewFlights() {
        const criteria = collectFormData();
        if (!criteria.origin_airports && !criteria.origin_centers && !criteria.dest_airports && !criteria.dest_centers) {
            return showToast(PERTII18n.t('reroute.specifyOriginOrDest'), 'warning');
        }

        const btn = document.getElementById('rr_preview_btn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

        try {
            const r = await fetch('api/tmi/rr_preview.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(criteria),
            });
            const data = await r.json();
            if (data.status !== 'ok') {throw new Error(data.message);}

            const affected = data.flights.filter(f => !f.is_exempt);
            const exempt = data.flights.filter(f => f.is_exempt);
            state.previewFlights = data.flights;
            state.isPreviewMode = true;

            document.getElementById('rr_affected_count').textContent = affected.length;
            document.getElementById('rr_exempt_count').textContent = exempt.length;

            renderFlightTable(affected, 'preview');
            renderExemptTable(exempt);
            updateFlightsOnMap(affected);
            displayStatistics({ total: affected.length });

            document.getElementById('rr_adl_status').textContent = PERTII18n.t('reroute.adlCount', { count: data.summary.total });
            document.getElementById('rr_adl_status').className = 'badge badge-success ml-2';

            showToast(PERTII18n.t('reroute.foundFlights', { count: affected.length }), 'info');
        } catch (e) {
            showToast(PERTII18n.t('reroute.genericError', { message: e.message }), 'danger');
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-search"></i> ' + PERTII18n.t('reroute.preview');
        }
    }

    function startMonitoring() {
        if (state.isMonitoring) {return;}
        state.isMonitoring = true;
        state.isPreviewMode = false;
        refreshCompliance();
        state.refreshInterval = setInterval(refreshCompliance, state.refreshRateMs);
    }

    function stopMonitoring() {
        if (state.refreshInterval) {clearInterval(state.refreshInterval);}
        state.refreshInterval = null;
        state.isMonitoring = false;
    }

    async function refreshCompliance() {
        const id = getValue('rr_id');
        if (!id) {return;}

        // Store previous data for buffered update
        const previousFlights = state.assignedFlights ? state.assignedFlights.slice() : [];

        try {
            await fetch('api/tmi/rr_compliance_refresh.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ reroute_id: id }),
            });
            state.lastRefresh = new Date();

            const r = await fetch(`api/data/tmi/reroute.php?id=${id}&include_flights=1&include_stats=1`);
            const data = await r.json();
            if (data.status === 'ok') {
                const newFlights = data.reroute.flights || [];
                // BUFFERED: Only update if we got data, or had no prior data
                if (newFlights.length > 0 || previousFlights.length === 0) {
                    state.assignedFlights = newFlights;
                } else {
                    console.log('[Reroute] Empty response, keeping previous data (' + previousFlights.length + ' flights)');
                }
                renderFlightTable(state.assignedFlights, 'assigned');
                updateFlightsOnMap(state.assignedFlights);
                if (data.reroute.statistics) {displayStatistics(data.reroute.statistics);}
            }
        } catch (e) {
            console.error('[Reroute] Refresh error:', e);
            // BUFFERED: Keep previous data on error
            console.log('[Reroute] Keeping previous data due to error (' + previousFlights.length + ' flights)');
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // MANUAL MANAGEMENT
    // ═══════════════════════════════════════════════════════════════════════

    window.addFlightManually = async function() {
        const id = getValue('rr_id');
        if (!id) {return showToast(PERTII18n.t('reroute.saveFirst'), 'warning');}
        const key = prompt(PERTII18n.t('reroute.promptFlightKey'));
        if (!key) {return;}

        try {
            const r = await fetch('api/tmi/rr_assign_manual.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ reroute_id: id, action: 'add', flights: [key] }),
            });
            const data = await r.json();
            if (data.status !== 'ok') {throw new Error(data.message);}
            showToast(PERTII18n.t('reroute.addedFlights', { count: data.affected }), 'success');
            await loadReroute(id);
        } catch (e) { showToast(PERTII18n.t('reroute.genericError', { message: e.message }), 'danger'); }
    };

    window.removeSelectedFlights = async function() {
        const id = getValue('rr_id');
        if (!id || !state.selectedFlights.size) {return;}
        if (!confirm(PERTII18n.t('reroute.confirmRemoveFlights', { count: state.selectedFlights.size }))) {return;}

        try {
            const r = await fetch('api/tmi/rr_assign_manual.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ reroute_id: id, action: 'remove', flights: [...state.selectedFlights] }),
            });
            const data = await r.json();
            showToast(PERTII18n.t('reroute.removedFlights', { count: data.affected }), 'success');
            state.selectedFlights.clear();
            await loadReroute(id);
        } catch (e) { showToast(PERTII18n.t('reroute.genericError', { message: e.message }), 'danger'); }
    };

    window.overrideCompliance = async function(flightId, callsign) {
        const status = prompt(PERTII18n.t('reroute.promptOverrideStatus', { callsign }));
        if (!status || !['COMPLIANT','PARTIAL','NON_COMPLIANT','EXEMPT','PENDING','MONITORING'].includes(status.toUpperCase())) {
            return showToast(PERTII18n.t('reroute.invalidStatus'), 'warning');
        }
        const reason = prompt(PERTII18n.t('reroute.promptReason'));
        if (!reason) {return showToast(PERTII18n.t('reroute.reasonRequired'), 'warning');}

        try {
            const r = await fetch('api/tmi/rr_compliance_override.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ flight_id: flightId, status: status.toUpperCase(), reason }),
            });
            const data = await r.json();
            if (data.status !== 'ok') {throw new Error(data.message);}
            showToast(PERTII18n.t('reroute.updatedToStatus', { status: data.new_status }), 'success');
            await loadReroute(getValue('rr_id'));
        } catch (e) { showToast(PERTII18n.t('reroute.genericError', { message: e.message }), 'danger'); }
    };

    window.exportReroute = (format, type) => {
        const id = getValue('rr_id');
        if (!id) {return showToast(PERTII18n.t('reroute.saveFirst'), 'warning');}
        window.open(`api/tmi/rr_export.php?id=${id}&format=${format}&type=${type}&download=1`, '_blank');
    };

    // ═══════════════════════════════════════════════════════════════════════
    // RENDERING
    // ═══════════════════════════════════════════════════════════════════════

    function renderFlightTable(flights, mode) {
        const tbody = document.getElementById('rr_flight_table_body');
        if (!flights?.length) {
            tbody.innerHTML = `<tr><td colspan="10" class="text-center text-muted py-4"><i class="fas fa-search fa-2x mb-2"></i><br>${PERTII18n.t('reroute.clickPreview')}</td></tr>`;
            return;
        }

        const isAssigned = mode === 'assigned';
        tbody.innerHTML = flights.map(f => {
            const s = f.compliance_status || 'PENDING';
            const pct = f.compliance_pct != null ? f.compliance_pct + '%' : '--';
            const cls = s === 'NON_COMPLIANT' ? 'table-danger' : s === 'PARTIAL' ? 'table-warning' : '';

            return `<tr class="${cls}">
                ${isAssigned ? `<td><input type="checkbox" class="flight-select" data-flight-key="${f.flight_key}" onchange="toggleSel(this)"></td>` : ''}
                <td><strong>${esc(f.callsign)}</strong></td>
                <td>${f.fp_dept_icao || f.dep_icao || '--'}</td>
                <td>${f.fp_dest_icao || f.dest_icao || '--'}</td>
                <td>${f.aircraft_type || f.ac_type || '--'}</td>
                <td>${fmtTime(f.etd_runway_utc || f.assigned_utc)}</td>
                <td>${fmtTime(f.eta_runway_utc)}</td>
                <td><span class="badge ${STATUS_CLASSES[s]}"><i class="fas ${STATUS_ICONS[s]} me-1"></i>${s}</span></td>
                <td>${pct}</td>
                <td>
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-outline-info" onclick="showFlightDetail('${f.flight_key}')" title="Info"><i class="fas fa-info-circle"></i></button>
                        ${isAssigned ? `<button class="btn btn-outline-warning" onclick="overrideCompliance(${f.id},'${f.callsign}')" title="${PERTII18n.t('reroute.override')}"><i class="fas fa-edit"></i></button>
                        <button class="btn btn-outline-secondary" onclick="showHistory(${f.id})" title="${PERTII18n.t('reroute.history.label')}"><i class="fas fa-history"></i></button>` : ''}
                    </div>
                </td>
            </tr>`;
        }).join('');
    }

    function renderExemptTable(flights) {
        const tbody = document.getElementById('rr_exempt_table_body');
        if (!flights?.length) {
            tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-4">' + PERTII18n.t('reroute.noExemptFlights') + '</td></tr>';
            return;
        }
        tbody.innerHTML = flights.map(f => `<tr><td>${esc(f.callsign)}</td><td>${f.fp_dept_icao || '--'}</td><td>${f.fp_dest_icao || '--'}</td><td><small>${f.exempt_reason || ''}</small></td></tr>`).join('');
    }

    function clearFlightTable() {
        document.getElementById('rr_flight_table_body').innerHTML = '<tr><td colspan="10" class="text-center text-muted py-4">' + PERTII18n.t('reroute.clickPreview') + '</td></tr>';
        document.getElementById('rr_exempt_table_body').innerHTML = '<tr><td colspan="4" class="text-center text-muted">' + PERTII18n.t('reroute.noExempt') + '</td></tr>';
        document.getElementById('rr_affected_count').textContent = '0';
        document.getElementById('rr_exempt_count').textContent = '0';
    }

    function displayStatistics(s) {
        document.getElementById('rr_stats_total').textContent = s.total || s.total_flights || '--';
        document.getElementById('rr_stats_compliant').textContent = s.compliant || '0';
        document.getElementById('rr_stats_partial').textContent = s.partial || '0';
        document.getElementById('rr_stats_noncompliant').textContent = s.non_compliant || '0';
        document.getElementById('rr_stats_monitoring').textContent = s.monitoring || '0';
        const d = s.avg_route_delta_nm;
        document.getElementById('rr_stats_route_delta').textContent = d != null ? (d >= 0 ? '+' : '') + Math.round(d) + 'nm' : '--';
    }

    // ═══════════════════════════════════════════════════════════════════════
    // MAP
    // ═══════════════════════════════════════════════════════════════════════

    function updateRouteVisualization() {
        state.layers.protected.clearLayers();
        state.layers.avoid.clearLayers();

        const protectedFixes = parseFixList(getValue('rr_protected_fixes'));
        const coords = [];
        protectedFixes.forEach(fix => {
            const c = getFixCoords(fix);
            if (c) {
                coords.push(c);
                L.circleMarker(c, { radius: 8, fillColor: '#28a745', color: '#fff', weight: 2, fillOpacity: 0.9 })
                    .bindTooltip(fix, { permanent: true, direction: 'top' }).addTo(state.layers.protected);
            }
        });
        if (coords.length >= 2) {L.polyline(coords, { color: '#28a745', weight: 4, opacity: 0.8 }).addTo(state.layers.protected);}

        parseFixList(getValue('rr_avoid_fixes')).forEach(fix => {
            const c = getFixCoords(fix);
            if (c) {
                L.circleMarker(c, { radius: 10, fillColor: '#dc3545', color: '#fff', weight: 2, fillOpacity: 0.9 })
                    .bindTooltip('⛔ ' + fix).addTo(state.layers.avoid);
                coords.push(c);
            }
        });

        if (coords.length) {state.map.fitBounds(coords, { padding: [50, 50] });}
    }

    function clearRouteVisualization() {
        ['protected', 'avoid', 'flights', 'tracks'].forEach(l => state.layers[l].clearLayers());
    }

    function updateFlightsOnMap(flights) {
        state.layers.flights.clearLayers();
        flights.forEach(f => {
            const lat = f.lat || f.last_lat, lon = f.lon || f.last_lon;
            if (lat && lon) {
                const s = f.compliance_status || 'PENDING';
                const color = { PENDING:'#6c757d', MONITORING:'#17a2b8', COMPLIANT:'#28a745', PARTIAL:'#ffc107', NON_COMPLIANT:'#dc3545', EXEMPT:'#adb5bd', UNKNOWN:'#343a40' }[s] || '#6c757d';
                L.circleMarker([lat, lon], { radius: 6, fillColor: color, color: '#fff', weight: 1, fillOpacity: 0.9 })
                    .bindPopup(`<strong>${f.callsign}</strong><br>${f.fp_dept_icao || f.dep_icao} → ${f.fp_dest_icao || f.dest_icao}<br>${PERTII18n.t('reroute.flightDetail.status')}: ${s}`)
                    .addTo(state.layers.flights);
            }
        });
    }

    // ═══════════════════════════════════════════════════════════════════════
    // FLIGHT DETAIL & HISTORY
    // ═══════════════════════════════════════════════════════════════════════

    window.showFlightDetail = function(key) {
        const f = state.previewFlights.find(x => x.flight_key === key) || state.assignedFlights.find(x => x.flight_key === key);
        if (!f) {return showToast(PERTII18n.t('reroute.notFound'), 'warning');}

        document.getElementById('flightDetailTitle').textContent = f.callsign;
        document.getElementById('flightDetailBody').innerHTML = `
            <table class="table table-sm">
                <tr><th>${PERTII18n.t('reroute.flightDetail.key')}</th><td>${f.flight_key}</td></tr>
                <tr><th>${PERTII18n.t('reroute.flightDetail.route')}</th><td>${f.fp_dept_icao || f.dep_icao} → ${f.fp_dest_icao || f.dest_icao}</td></tr>
                <tr><th>${PERTII18n.t('reroute.flightDetail.aircraft')}</th><td>${f.aircraft_type || f.ac_type || '--'}</td></tr>
                <tr><th>${PERTII18n.t('reroute.flightDetail.altitude')}</th><td>FL${Math.round((f.fp_altitude_ft || f.filed_altitude || 0) / 100)}</td></tr>
                <tr><th>${PERTII18n.t('reroute.flightDetail.filed')}</th><td><small>${f.fp_route || f.route_at_assign || '--'}</small></td></tr>
                <tr><th>${PERTII18n.t('reroute.flightDetail.current')}</th><td><small>${f.current_route || '--'}</small></td></tr>
                <tr><th>${PERTII18n.t('reroute.flightDetail.status')}</th><td><span class="badge ${STATUS_CLASSES[f.compliance_status] || 'badge-secondary'}">${f.compliance_status || '--'}</span> ${f.compliance_pct != null ? f.compliance_pct + '%' : ''}</td></tr>
                ${f.protected_fixes_crossed ? `<tr><th>${PERTII18n.t('reroute.flightDetail.protectedCrossed')}</th><td class="text-success">${f.protected_fixes_crossed}</td></tr>` : ''}
                ${f.avoid_fixes_crossed ? `<tr><th>${PERTII18n.t('reroute.flightDetail.avoidCrossed')}</th><td class="text-danger">${f.avoid_fixes_crossed}</td></tr>` : ''}
            </table>`;
        $('#flightDetailModal').modal('show');
    };

    window.showHistory = async function(id) {
        try {
            const r = await fetch(`api/tmi/rr_compliance_history.php?flight_id=${id}&limit=50`);
            const data = await r.json();
            if (data.status !== 'ok') {throw new Error(data.message);}

            let html = '<table class="table table-sm"><thead><tr><th>' + PERTII18n.t('reroute.history.time') + '</th><th>' + PERTII18n.t('reroute.history.status') + '</th><th>%</th></tr></thead><tbody>';
            data.history.forEach(h => {
                html += `<tr><td><small>${h.snapshot_utc}</small></td><td><span class="badge ${STATUS_CLASSES[h.compliance_status]}">${h.compliance_status}</span></td><td>${h.compliance_pct != null ? h.compliance_pct + '%' : '--'}</td></tr>`;
            });
            html += '</tbody></table>';

            document.getElementById('flightDetailTitle').textContent = PERTII18n.t('reroute.history.title', { callsign: data.flight.callsign });
            document.getElementById('flightDetailBody').innerHTML = html;
            $('#flightDetailModal').modal('show');
        } catch (e) { showToast(PERTII18n.t('reroute.genericError', { message: e.message }), 'danger'); }
    };

    // ═══════════════════════════════════════════════════════════════════════
    // UTILITIES
    // ═══════════════════════════════════════════════════════════════════════

    const parseFixList = s => s ? s.split(/[,\s]+/).map(x => x.trim().toUpperCase()).filter(x => x) : [];

    function getFixCoords(fix) {
        if (state.airportInfo[fix]) {return [state.airportInfo[fix].lat, state.airportInfo[fix].lon];}
        const common = { MERIT:[40.85,-73.30], GREKI:[40.98,-72.33], JUDDS:[41.22,-72.88], WHITE:[41.07,-73.61], COATE:[40.72,-73.86], DIXIE:[40.47,-74.07], LANNA:[40.53,-73.23], WAVEY:[40.35,-73.78], BETTE:[40.13,-73.62], PARCH:[40.77,-73.10], RBV:[40.20,-74.50], SBJ:[40.88,-74.57], JFK:[40.64,-73.78], LGA:[40.77,-73.87], EWR:[40.69,-74.17] };
        return common[fix] || null;
    }

    const fmtTime = d => d ? (d instanceof Date ? d : new Date(d)).toISOString().substr(11, 5) + 'Z' : '--';
    const esc = s => { const d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; };

    window.toggleSel = function(cb) {
        cb.checked ? state.selectedFlights.add(cb.dataset.flightKey) : state.selectedFlights.delete(cb.dataset.flightKey);
    };

    function showToast(msg, type = 'info') {
        let c = document.getElementById('toast-container');
        if (!c) {
            c = document.createElement('div');
            c.id = 'toast-container';
            c.style.cssText = 'position:fixed;top:20px;right:20px;z-index:9999;max-width:350px;';
            document.body.appendChild(c);
        }
        const t = document.createElement('div');
        t.className = `alert alert-${type === 'danger' ? 'danger' : type === 'success' ? 'success' : type === 'warning' ? 'warning' : 'info'} alert-dismissible fade show`;
        t.innerHTML = `${msg}<button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>`;
        c.appendChild(t);
        // Auto-remove after 4 seconds
        setTimeout(() => {
            t.classList.remove('show');
            setTimeout(() => t.remove(), 150);
        }, 4000);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // INIT
    // ═══════════════════════════════════════════════════════════════════════

    document.addEventListener('DOMContentLoaded', init);
    window.rerouteController = { state, previewFlights, refreshCompliance, startMonitoring, stopMonitoring };

})();
