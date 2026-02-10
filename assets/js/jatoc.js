/**
 * JATOC - Joint Air Traffic Operations Command
 * AWO Incident Monitor - JavaScript Module v6
 */
(function() {
    'use strict';
    const config = window.JATOC_CONFIG || {};

    const state = {
        incidents: [],
        currentIncident: null,
        opsLevel: 1,
        countdown: 5,
        map: null,
        boundaryData: { artcc: null, tracon: null },
        layerControlExpanded: false,
        hiddenIncidents: new Set(),
        userProfile: null,
        sortColumn: 'start_utc',
        sortDirection: 'desc',
    };

    // ========== FACILITY & ROLE DATA (uses PERTI namespace when available) ==========
    const _JP = (typeof PERTI !== 'undefined') ? PERTI : null;
    const FACILITIES = (_JP && _JP.FACILITY && _JP.FACILITY.FACILITY_LISTS) ? {
        ARTCC: [..._JP.FACILITY.FACILITY_LISTS.ARTCC_ALL],
        TRACON: [..._JP.FACILITY.FACILITY_LISTS.TRACON],
        ATCT: [..._JP.FACILITY.FACILITY_LISTS.ATCT],
        FIR: [..._JP.FACILITY.FACILITY_LISTS.FIR_GLOBAL],
    } : {
        ARTCC: [
            'ZAB', 'ZAU', 'ZBW', 'ZDC', 'ZDV', 'ZFW', 'ZHU', 'ZID', 'ZJX', 'ZKC',
            'ZLA', 'ZLC', 'ZMA', 'ZME', 'ZMP', 'ZNY', 'ZOA', 'ZOB', 'ZSE', 'ZTL',
            'ZAN', 'HCF',
        ],
        TRACON: [
            'A11', 'A80', 'A90', 'C90', 'D01', 'D10', 'D21', 'F11', 'I90', 'L30',
            'M03', 'M98', 'N90', 'NCT', 'P31', 'P50', 'P80', 'PCT', 'R90',
            'S46', 'S56', 'SCT', 'T75', 'U90', 'Y90',
        ],
        ATCT: [
            'KATL', 'KBOS', 'KBWI', 'KCLT', 'KCVG', 'KDCA', 'KDEN', 'KDFW', 'KDTW',
            'KEWR', 'KFLL', 'KHOU', 'KIAD', 'KIAH', 'KJFK', 'KLAS', 'KLAX', 'KLGA',
            'KMCO', 'KMDW', 'KMEM', 'KMIA', 'KMSP', 'KOAK', 'KORD', 'KPBI', 'KPDX',
            'KPHL', 'KPHX', 'KPIT', 'KSAN', 'KSEA', 'KSFO', 'KSLC', 'KSTL', 'KTPA',
        ],
        FIR: [
            'CZEG', 'CZQM', 'CZQX', 'CZUL', 'CZVR', 'CZWG', 'CZYZ',
            'EGPX', 'EGTT', 'EISN', 'LFFF', 'LFBB', 'LFEE', 'LFMM', 'LFRR',
            'EDGG', 'EDMM', 'EDUU', 'EDWW', 'EHAA', 'EBBU', 'LSAS', 'LOVV',
            'LIBB', 'LIMM', 'LIPP', 'LIRR', 'LECM', 'LECB', 'LECS', 'LPPC',
            'EKDK', 'ENOR', 'ESAA', 'EFIN', 'BIRD', 'BICC',
            'UUUU', 'UMMV', 'UKBV', 'UKDV', 'UKLV', 'UKOV',
            'LLLL', 'OJAC', 'OSTT', 'ORBB', 'OIIX', 'OAKX', 'OPKR', 'OPLR',
            'VABF', 'VECF', 'VIDF', 'VOMF', 'VCCF', 'VRMF', 'VTBB', 'VVTS',
            'WMFC', 'WSJC', 'WAAF', 'WIIF', 'RPHI', 'VHHK', 'ZGZU', 'ZBPE',
            'ZSHA', 'ZLHW', 'ZWUQ', 'RJJJ', 'RKRR', 'RCAA',
            'YMMM', 'YBBB', 'NZZO', 'NFFF', 'AGGG',
            'FAJS', 'FACA', 'FCCC', 'FNAN', 'FQBE', 'HTDC', 'HKNA', 'HUEC', 'HRYR',
            'DGAC', 'DRRR', 'DNKK', 'GOOO', 'GMMM', 'DTTC', 'HLLL', 'HECC',
            'SCEZ', 'SCEL', 'SUEO', 'SABE', 'SBBS', 'SBCW', 'SBRE', 'SBAZ',
            'SVZM', 'SKED', 'SKEC', 'SPIM', 'SLLF', 'SEGU',
            'MHTG', 'MGGT', 'MMMX', 'MMFR', 'MMTY', 'MMZT', 'TJZS', 'MKJK', 'MDCS', 'TTZP',
        ],
    };

    const ROLES = (_JP && _JP.COORDINATION && _JP.COORDINATION.ROLES)
        ? _JP.COORDINATION.ROLES
        : {
        DCC: [
            { code: 'OP', name: 'Operations Planner' },
            { code: 'NOM', name: 'National Operations Manager' },
            { code: 'NTMO', name: 'National Traffic Management Officer' },
            { code: 'NTMS', name: 'National Traffic Management Specialist' },
            { code: 'OTHER', name: 'Other' },
        ],
        ECFMP: [
            { code: 'LEAD', name: 'Leadership' },
            { code: 'NMT', name: 'Network Management Team' },
            { code: 'SFM', name: 'Senior Flow Manager' },
            { code: 'FM', name: 'Flow Manager' },
            { code: 'EVENT', name: 'Event Staff' },
            { code: 'ATC', name: 'Air Traffic Controller' },
            { code: 'OTHER', name: 'Other' },
        ],
        CTP: [
            { code: 'LEAD', name: 'Leadership' },
            { code: 'COORD', name: 'Coordination' },
            { code: 'PLAN', name: 'Planning' },
            { code: 'RTE', name: 'Routes' },
            { code: 'FLOW', name: 'Flow' },
            { code: 'OCN', name: 'Oceanic' },
            { code: 'OTHER', name: 'Other' },
        ],
        WF: [
            { code: 'LEAD', name: 'Leadership' },
            { code: 'AFF', name: 'Affiliate' },
            { code: 'TEAM', name: 'Team Member' },
            { code: 'SM', name: 'Social Media' },
            { code: 'OTHER', name: 'Other' },
        ],
        FACILITY: [
            { code: 'STMC', name: 'Supervisory TMC' },
            { code: 'TMC', name: 'Traffic Management Coordinator' },
            { code: 'TMU', name: 'Traffic Management Unit' },
            { code: 'DEP', name: 'Departure Coordinator' },
            { code: 'ENR', name: 'En Route Coordinator' },
            { code: 'ARR', name: 'Arrival Coordinator' },
            { code: 'PIT', name: 'ZNY PIT' },
            { code: 'RR', name: 'Reroute Coordinator' },
            { code: 'MIL', name: 'Military Coordinator' },
            { code: 'LEAD', name: 'Leadership' },
            { code: 'EVENT', name: 'Events' },
            { code: 'ATC', name: 'Air Traffic Controller' },
            { code: 'OTHER', name: 'Other' },
        ],
        VATUSA: [
            { code: 'LEAD', name: 'Leadership' },
            { code: 'EVENT', name: 'Events' },
            { code: 'OTHER', name: 'Other' },
        ],
        VATSIM: [
            { code: 'LEAD', name: 'Leadership' },
            { code: 'BOG', name: 'Board of Governors' },
            { code: 'REGL', name: 'Region Leadership' },
            { code: 'DIVL', name: 'Division Leadership' },
            { code: 'OTHER', name: 'Other' },
        ],
        VA: [
            { code: 'LEAD', name: 'Leadership' },
            { code: 'AOC', name: 'Operations' },
            { code: 'OTHER', name: 'Other' },
        ],
        VSO: [
            { code: 'LEAD', name: 'Leadership' },
            { code: 'CMD', name: 'Command Staff' },
            { code: 'ATC', name: 'ATC Coordination' },
            { code: 'OTHER', name: 'Other' },
        ],
    };

    const LAYER_CONFIG = {
        'artcc': { layerIds: ['artcc-line'], labelKey: 'jatoc.layers.artccBoundaries', defaultOn: true },
        'tracon': { layerIds: ['tracon-line'], labelKey: 'jatoc.layers.traconBoundaries', defaultOn: false },
        'incidents': { layerIds: ['incident-fill', 'incident-outline', 'incident-points'], labelKey: 'jatoc.layers.activeIncidents', defaultOn: true },
        'weather': { layerIds: ['weather-radar-layer'], labelKey: 'jatoc.layers.weatherRadar', defaultOn: false },
    };

    const TRIGGER_KEYS = {
        'A': 'jatoc.triggers.A', 'B': 'jatoc.triggers.B', 'C': 'jatoc.triggers.C',
        'D': 'jatoc.triggers.D', 'E': 'jatoc.triggers.E', 'F': 'jatoc.triggers.F',
        'H': 'jatoc.triggers.H', 'J': 'jatoc.triggers.J', 'K': 'jatoc.triggers.K',
        'M': 'jatoc.triggers.M', 'Q': 'jatoc.triggers.Q', 'R': 'jatoc.triggers.R',
        'S': 'jatoc.triggers.S', 'T': 'jatoc.triggers.T', 'U': 'jatoc.triggers.U',
        'V': 'jatoc.triggers.V', 'W': 'jatoc.triggers.W',
    };

    function getTriggerLabel(code) {
        return TRIGGER_KEYS[code] ? PERTII18n.t(TRIGGER_KEYS[code]) : '';
    }

    const STATUS_COLORS = {
        'ATC_ZERO': '#dc2626', 'ATC_ALERT': '#f59e0b', 'ATC_LIMITED': '#3b82f6',
        'NON_RESPONSIVE': '#8b5cf6', 'OTHER': '#6b7280',
    };

    const OPS_LEVEL_COLORS = {
        1: { bg: 'linear-gradient(135deg, #166534 0%, #22c55e 100%)', border: '#22c55e' },
        2: { bg: 'linear-gradient(135deg, #92400e 0%, #f59e0b 100%)', border: '#f59e0b' },
        3: { bg: 'linear-gradient(135deg, #991b1b 0%, #dc2626 100%)', border: '#dc2626' },
    };

    document.addEventListener('DOMContentLoaded', () => {
        console.log('[JATOC] Init v7...');
        loadUserProfile();
        initClocks();
        loadBoundaries().then(() => initMap());
        loadOpsLevel();
        loadPotusCalendar();
        loadSpaceCalendar();
        loadVatusaEvents();
        loadPersonnel();
        loadIncidents();
        startAutoRefresh();
        initSortableTable();
        document.getElementById('opsLevelSelect').addEventListener('change', saveOpsLevel);

        // OIs auto-uppercase
        document.getElementById('profileOIs')?.addEventListener('input', (e) => {
            e.target.value = e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
        });

        // Profile preview update
        ['profileName', 'profileOIs', 'profileCID', 'profileFacility', 'profileRole', 'customFacilityCode'].forEach(id => {
            document.getElementById(id)?.addEventListener('input', updateProfilePreview);
            document.getElementById(id)?.addEventListener('change', updateProfilePreview);
        });
    });

    function initSortableTable() {
        document.querySelectorAll('#eventsTable th[data-sort]').forEach(th => {
            th.addEventListener('click', () => {
                const col = th.dataset.sort;
                if (state.sortColumn === col) {
                    state.sortDirection = state.sortDirection === 'asc' ? 'desc' : 'asc';
                } else {
                    state.sortColumn = col;
                    state.sortDirection = 'asc';
                }
                updateSortIcons();
                sortAndRenderIncidents();
            });
        });
    }

    function updateSortIcons() {
        document.querySelectorAll('#eventsTable th[data-sort]').forEach(th => {
            const icon = th.querySelector('.sort-icon');
            th.classList.remove('sort-active');
            if (icon) {
                icon.className = 'fas fa-sort sort-icon';
            }
            if (th.dataset.sort === state.sortColumn) {
                th.classList.add('sort-active');
                if (icon) {
                    icon.className = `fas fa-sort-${state.sortDirection === 'asc' ? 'up' : 'down'} sort-icon`;
                }
            }
        });
    }

    function sortAndRenderIncidents() {
        const sorted = [...state.incidents].sort((a, b) => {
            let aVal = a[state.sortColumn];
            let bVal = b[state.sortColumn];

            // Handle renamed columns with fallback
            if (state.sortColumn === 'incident_type') {
                aVal = a.incident_type || a.status;
                bVal = b.incident_type || b.status;
            } else if (state.sortColumn === 'lifecycle_status') {
                aVal = a.lifecycle_status || a.incident_status;
                bVal = b.lifecycle_status || b.incident_status;
            }

            // Handle nulls
            if (aVal == null) {aVal = '';}
            if (bVal == null) {bVal = '';}

            // Handle booleans (paged)
            if (typeof aVal === 'boolean') {aVal = aVal ? 1 : 0;}
            if (typeof bVal === 'boolean') {bVal = bVal ? 1 : 0;}

            // Handle numbers
            if (state.sortColumn === 'paged') {
                aVal = a.paged ? 1 : 0;
                bVal = b.paged ? 1 : 0;
            }

            // String comparison
            if (typeof aVal === 'string') {aVal = aVal.toLowerCase();}
            if (typeof bVal === 'string') {bVal = bVal.toLowerCase();}

            let result = 0;
            if (aVal < bVal) {result = -1;}
            if (aVal > bVal) {result = 1;}

            return state.sortDirection === 'asc' ? result : -result;
        });

        state.incidents = sorted;
        renderIncidents();
    }

    function startAutoRefresh() {
        state.countdown = 5; // Live updates - 5 second refresh
        setInterval(() => {
            state.countdown--;
            document.getElementById('refreshCountdown').textContent = state.countdown;
            if (state.countdown <= 0) { loadIncidents(); state.countdown = 5; }
        }, 1000);
    }

    // ========== USER PROFILE ==========
    function loadUserProfile() {
        const saved = localStorage.getItem('jatoc_user_profile');
        if (saved) {
            try {
                state.userProfile = JSON.parse(saved);
                updateUserDisplay();
            } catch (e) { console.log('[JATOC] Profile parse error'); }
        }
    }

    function updateUserDisplay() {
        const btn = document.getElementById('userDisplayName');
        if (state.userProfile?.name && state.userProfile?.ois) {
            btn.textContent = `${state.userProfile.ois}`;
            btn.classList.remove('user-not-set');
        } else {
            btn.textContent = PERTII18n.t('jatoc.profile.setProfile');
            btn.classList.add('user-not-set');
        }
    }

    function getUserAuthorString() {
        if (!state.userProfile?.name || !state.userProfile?.ois) {return PERTII18n.t('common.unknown');}
        const p = state.userProfile;
        // Only use customCode for custom facility types (check both facilityType and facType)
        const facType = p.facilityType || p.facType;
        const isCustomType = ['VA', 'VSO', 'MIL', 'APT_AUTH', 'OTHER'].includes(facType);
        const facCode = isCustomType ? (p.customCode || '???') : (p.facility || '???');
        const roleCode = p.roleCode || p.role || '???';
        const cid = p.cid || '???';
        return `${facCode}_${roleCode}/${p.name} (${p.ois}/${cid})`;
    }

    function hasProfile() {
        return state.userProfile?.name && state.userProfile?.ois;
    }

    function isDCC() {
        // Check both facilityType and facType for compatibility with different profile versions
        return state.userProfile?.facilityType === 'DCC' || state.userProfile?.facType === 'DCC';
    }

    function isLoggedIn() {
        return config.isLoggedIn === true;
    }

    function requireProfile(action) {
        if (!hasProfile()) {
            alert(PERTII18n.t('jatoc.profile.requiredAlert', { action }));
            return false;
        }
        return true;
    }

    function requireDCC(action) {
        if (!isLoggedIn()) {
            alert(PERTII18n.t('jatoc.auth.loginRequired', { action }));
            return false;
        }
        if (!requireProfile(action)) {return false;}
        if (!isDCC()) {
            alert(PERTII18n.t('jatoc.auth.dccOnly', { action }));
            return false;
        }
        return true;
    }

    function showUserProfile() {
        const p = state.userProfile || {};
        document.getElementById('profileName').value = p.name || config.sessionUserName || '';
        document.getElementById('profileOIs').value = p.ois || '';
        document.getElementById('profileCID').value = p.cid || config.sessionUserCid || '';
        document.getElementById('profileFacilityType').value = p.facilityType || '';

        if (p.facilityType) {
            onFacilityTypeChange();
            setTimeout(() => {
                document.getElementById('profileFacility').value = p.facility || '';
                document.getElementById('profileRole').value = p.roleCode || '';
                if (p.customName) {
                    document.getElementById('customFacilityName').value = p.customName;
                    document.getElementById('customFacilityCode').value = p.customCode || '';
                }
                updateProfilePreview();
            }, 50);
        }

        $('#userProfileModal').modal('show');
    }

    function onFacilityTypeChange() {
        const type = document.getElementById('profileFacilityType').value;
        const facSelect = document.getElementById('profileFacility');
        const roleSelect = document.getElementById('profileRole');
        const customRow = document.getElementById('customFacilityRow');
        const facSelectRow = document.getElementById('facilitySelectRow');

        facSelect.innerHTML = `<option value="">${PERTII18n.t('jatoc.profile.selectPlaceholder')}</option>`;
        roleSelect.innerHTML = `<option value="">${PERTII18n.t('jatoc.profile.selectPlaceholder')}</option>`;
        customRow.classList.remove('show');
        facSelectRow.style.display = 'block';

        // Clear custom fields when not a custom type
        const isCustomType = ['VA', 'VSO', 'MIL', 'APT_AUTH', 'OTHER'].includes(type);
        if (!isCustomType) {
            document.getElementById('customFacilityName').value = '';
            document.getElementById('customFacilityCode').value = '';
        }

        // Populate facilities
        if (FACILITIES[type]) {
            FACILITIES[type].forEach(f => {
                facSelect.innerHTML += `<option value="${f}">${f}</option>`;
            });
        } else if (isCustomType) {
            customRow.classList.add('show');
            facSelectRow.style.display = 'none';
        } else if (['DCC', 'ECFMP', 'CTP', 'WF', 'VATUSA', 'VATSIM'].includes(type)) {
            facSelect.innerHTML = `<option value="${type}">${type}</option>`;
            facSelect.value = type;
        }

        // Populate roles
        let roleKey = type;
        if (['ARTCC', 'TRACON', 'ATCT', 'FIR'].includes(type)) {roleKey = 'FACILITY';}
        if (['MIL', 'APT_AUTH', 'OTHER'].includes(type)) {roleKey = 'FACILITY';}

        const roles = ROLES[roleKey] || ROLES.FACILITY;
        roles.forEach(r => {
            roleSelect.innerHTML += `<option value="${r.code}">${r.code} - ${r.name}</option>`;
        });

        updateProfilePreview();
    }

    function updateProfilePreview() {
        const type = document.getElementById('profileFacilityType').value;
        const name = document.getElementById('profileName').value || '?';
        const ois = document.getElementById('profileOIs').value || '??';
        const cid = document.getElementById('profileCID').value || '?';
        const roleCode = document.getElementById('profileRole').value || '?';

        // Only use customCode for custom types
        const isCustomType = ['VA', 'VSO', 'MIL', 'APT_AUTH', 'OTHER'].includes(type);
        let facCode;
        if (isCustomType) {
            facCode = document.getElementById('customFacilityCode').value || '?';
        } else {
            facCode = document.getElementById('profileFacility').value || '?';
        }

        const preview = `${facCode}_${roleCode}/${name} (${ois}/${cid})`;
        document.getElementById('profilePreview').textContent = preview;
    }

    function saveProfile() {
        const name = document.getElementById('profileName').value.trim();
        const ois = document.getElementById('profileOIs').value.toUpperCase().trim();
        const cid = document.getElementById('profileCID').value.trim();
        const facilityType = document.getElementById('profileFacilityType').value;
        const facility = document.getElementById('profileFacility').value;
        const roleCode = document.getElementById('profileRole').value;

        // Only save custom fields for custom facility types
        const isCustomType = ['VA', 'VSO', 'MIL', 'APT_AUTH', 'OTHER'].includes(facilityType);
        const customName = isCustomType ? document.getElementById('customFacilityName').value.trim() : '';
        const customCode = isCustomType ? document.getElementById('customFacilityCode').value.toUpperCase().trim() : '';

        if (!name) { alert(PERTII18n.t('jatoc.profile.validation.nameRequired')); return; }
        if (!ois || !/^[A-Z0-9]{2}$/.test(ois)) { alert(PERTII18n.t('jatoc.profile.validation.oisFormat')); return; }
        if (!facilityType) { alert(PERTII18n.t('jatoc.profile.validation.facilityTypeRequired')); return; }
        if (!roleCode) { alert(PERTII18n.t('jatoc.profile.validation.roleRequired')); return; }
        if (isCustomType && !customCode) { alert(PERTII18n.t('jatoc.profile.validation.customCodeRequired')); return; }

        state.userProfile = { name, ois, cid, facilityType, facility, roleCode, customName, customCode };
        localStorage.setItem('jatoc_user_profile', JSON.stringify(state.userProfile));
        updateUserDisplay();
        $('#userProfileModal').modal('hide');
    }

    function clearProfile() {
        if (!confirm(PERTII18n.t('jatoc.profile.clearConfirm'))) {return;}
        state.userProfile = null;
        localStorage.removeItem('jatoc_user_profile');
        updateUserDisplay();
        $('#userProfileModal').modal('hide');
    }

    // ========== CLOCKS ==========
    function initClocks() {
        const update = () => {
            const now = new Date();
            document.getElementById('jatoc_utc_clock').textContent = now.toISOString().slice(11, 19);
            const fmt = (tz) => now.toLocaleTimeString('en-US', { timeZone: tz, hour: '2-digit', minute: '2-digit', hour12: false });
            document.getElementById('jatoc_clock_hi').textContent = fmt('Pacific/Honolulu');
            document.getElementById('jatoc_clock_ak').textContent = fmt('America/Anchorage');
            document.getElementById('jatoc_clock_pac').textContent = fmt('America/Los_Angeles');
            document.getElementById('jatoc_clock_mtn').textContent = fmt('America/Denver');
            document.getElementById('jatoc_clock_cent').textContent = fmt('America/Chicago');
            document.getElementById('jatoc_clock_east').textContent = fmt('America/New_York');
        };
        update();
        setInterval(update, 1000);
    }

    // ========== OPS LEVEL ==========
    async function loadOpsLevel() {
        try {
            const r = await api('oplevel.php');
            if (r.success && r.data) { state.opsLevel = r.data.ops_level; updateOpsLevel(); }
        } catch (e) { console.log('[JATOC] Ops level error:', e); }
    }

    async function saveOpsLevel() {
        if (!requireDCC('change Ops Level')) {
            // Reset to current value
            document.getElementById('opsLevelSelect').value = state.opsLevel;
            return;
        }
        const level = parseInt(document.getElementById('opsLevelSelect').value);
        try {
            await api('oplevel.php', 'PUT', { ops_level: level, set_by: getUserAuthorString() });
            state.opsLevel = level;
            updateOpsLevel();
        } catch (e) { alert(PERTII18n.t('jatoc.error.generic', { message: e.message })); }
    }

    async function changeOpsLevel() {
        if (!requireDCC('change Ops Level')) {return;}
        const level = parseInt(document.getElementById('modalOpsLevel').value);
        const reason = document.getElementById('modalOpsReason').value;
        if (!confirm(PERTII18n.t('jatoc.opsLevel.changeConfirm', { level }))) {return;}
        try {
            await api('oplevel.php', 'PUT', { ops_level: level, reason, set_by: getUserAuthorString() });
            state.opsLevel = level;
            updateOpsLevel();
            document.getElementById('modalOpsReason').value = '';
            if (state.currentIncident) {loadUpdates(state.currentIncident.id);}
            loadIncidents();
            alert(PERTII18n.t('jatoc.opsLevel.changeSuccess'));
        } catch (e) { alert(PERTII18n.t('jatoc.error.generic', { message: e.message })); }
    }

    function updateOpsLevel() {
        const sel = document.getElementById('opsLevelSelect');
        sel.value = state.opsLevel;
        sel.className = 'ops-level-badge ops-level-' + state.opsLevel;
        const modalSel = document.getElementById('modalOpsLevel');
        if (modalSel) {modalSel.value = state.opsLevel;}

        const header = document.getElementById('jatocHeaderBar');
        const titleBlock = document.getElementById('jatocTitleBlock');
        if (header) {
            const colors = OPS_LEVEL_COLORS[state.opsLevel] || OPS_LEVEL_COLORS[1];
            header.style.background = colors.bg;
            header.style.borderBottomColor = colors.border;
        }
        if (titleBlock) {titleBlock.className = 'col-md-4 ops-level-' + state.opsLevel + '-text';}
    }

    // ========== BOUNDARIES & MAP ==========
    async function loadBoundaries() {
        try {
            const [artccRes, traconRes] = await Promise.all([
                fetch('assets/geojson/artcc.json').then(r => r.ok ? r.json() : null).catch(() => null),
                fetch('assets/geojson/tracon.json').then(r => r.ok ? r.json() : null).catch(() => null),
            ]);
            state.boundaryData.artcc = artccRes;
            state.boundaryData.tracon = traconRes;
            // Debug: log available boundary IDs
            if (artccRes?.features?.length) {
                const artccIds = artccRes.features.slice(0, 5).map(f => JSON.stringify(f.properties));
                console.log('[JATOC] ARTCC sample properties:', artccIds);
            }
            if (traconRes?.features?.length) {
                const traconIds = traconRes.features.slice(0, 5).map(f => JSON.stringify(f.properties));
                console.log('[JATOC] TRACON sample properties:', traconIds);
            }
        } catch (e) { console.log('[JATOC] Boundaries error:', e); }
    }

    function initMap() {
        if (!window.maplibregl) {return;}

        state.map = new maplibregl.Map({
            container: 'jatoc-map',
            style: {
                version: 8,
                sources: { 'carto': { type: 'raster', tiles: ['https://a.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}.png'], tileSize: 256 } },
                layers: [{ id: 'carto', type: 'raster', source: 'carto' }],
            },
            center: [-98.5, 39.5],
            zoom: 3.5,
        });

        state.map.addControl(new maplibregl.NavigationControl(), 'top-right');

        state.map.on('load', () => {
            state.map.addSource('weather-radar', {
                type: 'raster',
                tiles: ['https://mesonet.agron.iastate.edu/cache/tile.py/1.0.0/nexrad-n0q/{z}/{x}/{y}.png'],
                tileSize: 256,
            });
            state.map.addLayer({ id: 'weather-radar-layer', type: 'raster', source: 'weather-radar', paint: { 'raster-opacity': 0.5 }, layout: { 'visibility': 'none' } });

            if (state.boundaryData.artcc) {
                state.map.addSource('artcc', { type: 'geojson', data: state.boundaryData.artcc });
                state.map.addLayer({ id: 'artcc-line', type: 'line', source: 'artcc', paint: { 'line-color': '#4a5568', 'line-width': 1, 'line-opacity': 0.5 } });
            }

            if (state.boundaryData.tracon) {
                state.map.addSource('tracon', { type: 'geojson', data: state.boundaryData.tracon });
                state.map.addLayer({ id: 'tracon-line', type: 'line', source: 'tracon', paint: { 'line-color': '#6b7280', 'line-width': 1, 'line-opacity': 0.4 }, layout: { visibility: 'none' } });
            }

            state.map.addSource('incident-fill', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
            state.map.addLayer({ id: 'incident-fill', type: 'fill', source: 'incident-fill', paint: { 'fill-color': ['get', 'color'], 'fill-opacity': 0.3 } });
            state.map.addLayer({ id: 'incident-outline', type: 'line', source: 'incident-fill', paint: { 'line-color': ['get', 'color'], 'line-width': 2 } });

            state.map.addSource('incident-points', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
            state.map.addLayer({ id: 'incident-points', type: 'circle', source: 'incident-points', paint: { 'circle-radius': 10, 'circle-color': ['get', 'color'], 'circle-stroke-width': 2, 'circle-stroke-color': '#fff' } });

            state.map.on('click', 'incident-fill', (e) => { if (e.features?.length) {showIncidentDetails(e.features[0].properties.incidentId);} });
            state.map.on('click', 'incident-points', (e) => { if (e.features?.length) {showIncidentDetails(e.features[0].properties.incidentId);} });
            state.map.on('mouseenter', 'incident-fill', () => state.map.getCanvas().style.cursor = 'pointer');
            state.map.on('mouseleave', 'incident-fill', () => state.map.getCanvas().style.cursor = '');
            state.map.on('mouseenter', 'incident-points', () => state.map.getCanvas().style.cursor = 'pointer');
            state.map.on('mouseleave', 'incident-points', () => state.map.getCanvas().style.cursor = '');

            setupLayerControl();
        });
    }

    function setupLayerControl() {
        const html = `
            <div class="jatoc-layer-control" id="jatoc-layer-control">
                <button id="jatoc-layer-toggle"><i class="fas fa-layer-group"></i> ${PERTII18n.t('jatoc.map.layers')}</button>
                <div id="jatoc-layer-panel">
                    ${Object.entries(LAYER_CONFIG).map(([key, cfg]) => `<label class="layer-option"><input type="checkbox" data-layer="${key}" ${cfg.defaultOn ? 'checked' : ''}><span>${PERTII18n.t(cfg.labelKey)}</span></label>`).join('')}
                </div>
            </div>
        `;
        document.getElementById('jatoc-map-container').insertAdjacentHTML('beforeend', html);

        document.getElementById('jatoc-layer-toggle').addEventListener('click', (e) => {
            e.stopPropagation();
            state.layerControlExpanded = !state.layerControlExpanded;
            document.getElementById('jatoc-layer-panel').style.display = state.layerControlExpanded ? 'block' : 'none';
        });

        document.addEventListener('click', (e) => {
            if (state.layerControlExpanded && !e.target.closest('#jatoc-layer-control')) {
                state.layerControlExpanded = false;
                document.getElementById('jatoc-layer-panel').style.display = 'none';
            }
        });

        document.querySelectorAll('#jatoc-layer-panel input[type="checkbox"]').forEach(cb => {
            cb.addEventListener('change', () => {
                const cfg = LAYER_CONFIG[cb.dataset.layer];
                if (cfg && state.map) {
                    cfg.layerIds.forEach(id => { if (state.map.getLayer(id)) {state.map.setLayoutProperty(id, 'visibility', cb.checked ? 'visible' : 'none');} });
                }
            });
        });
    }

    function updateMapIncidents(incidents) {
        if (!state.map || !state.map.loaded()) {return;}
        const fillFeatures = [], pointFeatures = [];

        incidents.filter(i => {
            const lifecycleStatus = i.lifecycle_status || i.incident_status;
            return lifecycleStatus !== 'CLOSED' && !state.hiddenIncidents.has(i.id);
        }).forEach(inc => {
            const incidentType = inc.incident_type || inc.status;
            const color = STATUS_COLORS[incidentType] || '#6b7280';
            const facType = (inc.facility_type || '').toUpperCase();
            const fac = (inc.facility || '').toUpperCase();
            let matched = false;

            if (facType === 'ARTCC' && state.boundaryData.artcc?.features) {
                // Try multiple property names for ARTCC matching
                const feature = state.boundaryData.artcc.features.find(f => {
                    const p = f.properties || {};
                    // Common ARTCC property patterns
                    const ids = [p.id, p.ICAOCODE, p.FIRname, p.icao, p.name].filter(Boolean).map(s => String(s).toUpperCase());
                    return ids.some(id => id === fac || id === 'K' + fac || id.startsWith(fac + '_') || id.endsWith('_' + fac));
                });
                if (feature) {
                    fillFeatures.push({ ...feature, properties: { ...feature.properties, color, incidentId: inc.id, facility: fac } });
                    matched = true;
                }
            } else if (facType === 'TRACON' && state.boundaryData.tracon?.features) {
                // Try multiple property names for TRACON matching
                const feature = state.boundaryData.tracon.features.find(f => {
                    const p = f.properties || {};
                    const ids = [p.id, p.sector, p.label, p.name, p.prefix].filter(Boolean).map(s => String(s).toUpperCase());
                    return ids.some(id => id === fac || id.includes(fac));
                });
                if (feature) {
                    fillFeatures.push({ ...feature, properties: { ...feature.properties, color, incidentId: inc.id, facility: fac } });
                    matched = true;
                }
            }

            // Fallback to point marker using centroid coordinates
            if (!matched) {
                const coords = getAirportCoords(fac);
                if (coords) {
                    pointFeatures.push({ type: 'Feature', geometry: { type: 'Point', coordinates: coords }, properties: { color, incidentId: inc.id, facility: fac } });
                } else {
                    console.log('[JATOC] No coords for facility:', fac, facType);
                }
            }
        });

        state.map.getSource('incident-fill')?.setData({ type: 'FeatureCollection', features: fillFeatures });
        state.map.getSource('incident-points')?.setData({ type: 'FeatureCollection', features: pointFeatures });
    }

    function getAirportCoords(fac) {
        const coords = {
            // Major US airports
            'KATL': [-84.43, 33.64], 'KJFK': [-73.78, 40.64], 'KLAX': [-118.41, 33.94], 'KORD': [-87.90, 41.98],
            'KDFW': [-97.04, 32.90], 'KDEN': [-104.67, 39.86], 'KSFO': [-122.38, 37.62], 'KSEA': [-122.31, 47.45],
            'KMIA': [-80.29, 25.80], 'KBOS': [-71.01, 42.36], 'KPHX': [-112.01, 33.43], 'KIAH': [-95.34, 29.98],
            'KEWR': [-74.17, 40.69], 'KMSP': [-93.22, 44.88], 'KDTW': [-83.35, 42.21], 'KPHL': [-75.24, 39.87],
            'KLGA': [-73.87, 40.78], 'KBWI': [-76.67, 39.18], 'KDCA': [-77.04, 38.85], 'KSAN': [-117.19, 32.73],
            'KTPA': [-82.53, 27.98], 'KPDX': [-122.60, 45.59], 'KSTL': [-90.37, 38.75], 'KCLT': [-80.94, 35.21],
            // Additional airports
            'KRDU': [-78.79, 35.88], 'KAUS': [-97.67, 30.19], 'KSAT': [-98.47, 29.53], 'KMDW': [-87.75, 41.79],
            'KBNA': [-86.68, 36.12], 'KMCO': [-81.31, 28.43], 'KFLL': [-80.15, 26.07], 'KPBI': [-80.10, 26.68],
            'KLAS': [-115.15, 36.08], 'KSLC': [-111.98, 40.79], 'KPIT': [-80.23, 40.49], 'KCLE': [-81.85, 41.41],
            'KCVG': [-84.67, 39.05], 'KIND': [-86.29, 39.72], 'KMKE': [-87.90, 42.95], 'KMCI': [-94.71, 39.30],
            'KOAK': [-122.22, 37.72], 'KSJC': [-121.93, 37.36], 'KHNL': [-157.92, 21.32], 'PANC': [-149.99, 61.17],
            'KSNA': [-117.87, 33.68], 'KONT': [-117.60, 34.06], 'KBUR': [-118.36, 34.20], 'KLGB': [-118.15, 33.82],
            'KTEB': [-74.06, 40.85], 'KHPN': [-73.71, 41.07], 'KISP': [-73.10, 40.80], 'KSWF': [-74.10, 41.50],
            // ARTCC centroids (fallback when boundary not found)
            'ZAB': [-109.0, 33.5], 'ZAU': [-89.0, 41.5], 'ZBW': [-71.5, 42.5], 'ZDC': [-77.5, 39.0],
            'ZDV': [-105.0, 40.0], 'ZFW': [-97.5, 32.5], 'ZHU': [-95.5, 30.0], 'ZID': [-86.5, 39.5],
            'ZJX': [-82.0, 30.5], 'ZKC': [-95.0, 39.0], 'ZLA': [-117.5, 34.5], 'ZLC': [-112.0, 41.0],
            'ZMA': [-80.5, 26.0], 'ZME': [-90.0, 35.5], 'ZMP': [-94.0, 45.0], 'ZNY': [-74.0, 41.0],
            'ZOA': [-122.0, 38.0], 'ZOB': [-82.0, 41.0], 'ZSE': [-122.0, 47.5], 'ZTL': [-84.5, 33.5],
            // TRACON centroids (fallback)
            'A80': [-84.43, 33.64], 'A90': [-71.01, 42.36], 'C90': [-87.90, 41.98], 'D01': [-97.04, 32.90],
            'D10': [-97.04, 32.90], 'I90': [-95.34, 29.98], 'L30': [-118.41, 33.94], 'M98': [-80.29, 25.80],
            'N90': [-73.87, 40.78], 'NCT': [-122.38, 37.62], 'P50': [-112.01, 33.43], 'PCT': [-77.04, 38.85],
            'S46': [-122.31, 47.45], 'SCT': [-117.19, 32.73], 'Y90': [-73.78, 40.64],
        };
        if (coords[fac]) return coords[fac];
        // Try normalizing to ICAO format for lookup (JFK → KJFK, YYZ → CYYZ, etc.)
        var normalized = (typeof PERTI !== 'undefined' && PERTI.normalizeIcao)
            ? PERTI.normalizeIcao(fac)
            : (fac && !fac.startsWith('K') ? 'K' + fac : fac);
        return coords[normalized] || null;
    }

    // ========== API ==========
    async function api(endpoint, method = 'GET', data = null) {
        const opts = { method, headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin' };
        let url = `api/jatoc/${endpoint}`;
        if (data && method !== 'GET') {opts.body = JSON.stringify(data);}
        if (method === 'GET' && data) {url += '?' + new URLSearchParams(data).toString();}
        const res = await fetch(url, opts);
        const result = await res.json();
        if (!res.ok) {throw new Error(result.error || PERTII18n.t('jatoc.error.apiError'));}
        return result;
    }

    // ========== CALENDARS ==========
    async function loadPotusCalendar() {
        const el = document.getElementById('potusCalendar');
        try {
            const override = await api('daily_ops.php', 'GET', { item_type: 'POTUS' });
            if (override.success && override.data?.[0]?.content) { el.innerHTML = renderCalendarFromText(override.data[0].content); return; }
            const res = await fetch('https://media-cdn.factba.se/rss/json/trump/calendar-full.json');
            if (!res.ok) {throw new Error('Fetch failed');}
            const data = await res.json();
            const now = new Date();
            // Start from yesterday to capture all of today's events including those already past
            const yesterdayStart = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate() - 1));
            const threeDaysOut = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate() + 3));
            // Include events from yesterday through 3 days out
            // Sort by date descending (most recent/upcoming first)
            const events = data.filter(e => {
                if (!e.date) {return false;}
                const d = new Date(e.date);
                return d >= yesterdayStart && d <= threeDaysOut;
            }).sort((a, b) => new Date(b.date) - new Date(a.date));
            el.innerHTML = renderPotusEvents(events);
        } catch (e) { el.innerHTML = `<div class="text-muted small p-2">${PERTII18n.t('jatoc.calendar.unableToLoad')}</div>`; }
    }

    function renderPotusEvents(events) {
        if (!events?.length) {return `<div class="text-muted small p-2">${PERTII18n.t('jatoc.calendar.noScheduledEvents')}</div>`;}
        const now = new Date();
        return events.map(e => {
            const eventDate = new Date(e.date);
            const day = String(eventDate.getUTCDate()).padStart(2, '0');
            const time = e.time || 'TBD';
            // Format: dd/hhmm (no seconds)
            const timeStr = day + '/' + (time !== 'TBD' ? time.replace(':', '').slice(0, 4) : 'TBD');
            let status = 'future';
            if (e.time && e.date) {
                const [h, m] = e.time.split(':').map(Number);
                const eventTime = new Date(eventDate); eventTime.setUTCHours(h, m, 0, 0);
                if (eventTime < now) {status = 'past';}
                else if (eventTime < new Date(now.getTime() + 3600000)) {status = 'active';}
            }
            return `<div class="ops-calendar-row ${status}"><span class="ops-calendar-time">${timeStr}</span><span class="ops-calendar-event">${esc(e.details || e.location || PERTII18n.t('jatoc.events.event'))}</span></div>`;
        }).join('');
    }

    function renderCalendarFromText(text) { return text.split('\n').filter(l => l.trim()).map(l => `<div class="ops-calendar-row"><span class="ops-calendar-event">${esc(l)}</span></div>`).join(''); }

    async function loadSpaceCalendar() {
        const el = document.getElementById('spaceCalendar');
        try {
            // Check for manual override first
            const override = await api('daily_ops.php', 'GET', { item_type: 'SPACE' });
            if (override.success && override.data?.[0]?.content) {
                el.innerHTML = renderCalendarFromText(override.data[0].content);
                return;
            }
            // Fetch from en route initiatives space operations
            const spaceRes = await api('space_ops.php');
            if (spaceRes.success && spaceRes.data?.length) {
                el.innerHTML = spaceRes.data.map(op => {
                    const statusClass = op.status === 'active' ? 'active' : (op.status === 'imminent' ? 'imminent' : '');
                    return `<div class="ops-calendar-row ${statusClass}">
                        <span class="ops-calendar-time">${op.time || 'TBD'}</span>
                        <span class="ops-calendar-event">${esc(op.name)}</span>
                    </div>`;
                }).join('');
            } else {
                el.innerHTML = `<div class="text-muted small p-2">${PERTII18n.t('jatoc.calendar.noScheduledSpaceOps')}</div>`;
            }
        } catch (e) {
            console.log('[JATOC] Space ops error:', e);
            el.innerHTML = `<div class="text-muted small p-2">${PERTII18n.t('jatoc.calendar.unableToLoadSpaceOps')}</div>`;
        }
    }

    async function loadVatusaEvents() {
        const el = document.getElementById('vatusaEvents');
        try {
            const r = await api('vatusa_events.php');
            if (!r.success || !r.data?.length) { el.innerHTML = `<div class="text-muted small">${PERTII18n.t('jatoc.events.noEvents')}</div>`; return; }
            const now = new Date();
            const todayStart = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate()));
            const todayEnd = new Date(todayStart.getTime() + 24 * 3600000);
            const t7d = new Date(todayStart.getTime() + 7 * 24 * 3600000);
            const t30d = new Date(todayStart.getTime() + 30 * 24 * 3600000);
            const today = [], next7 = [], next30 = [];
            r.data.forEach(e => {
                const d = new Date(e.start || e.start_date || e.date);
                if (isNaN(d.getTime())) {return;}
                if (d >= todayStart && d < todayEnd) {today.push(e);}
                else if (d >= todayStart && d < t7d) {next7.push(e);}
                else if (d >= todayStart && d < t30d) {next30.push(e);}
            });
            el.innerHTML = renderEventSection(PERTII18n.t('jatoc.events.today'), today, 'vat-today', true) + renderEventSection(PERTII18n.t('jatoc.events.next7d'), next7, 'vat-7d') + renderEventSection(PERTII18n.t('jatoc.events.next30d'), next30, 'vat-30d');
        } catch (e) { el.innerHTML = `<div class="text-muted small">${PERTII18n.t('jatoc.calendar.unableToLoad')}</div>`; }
    }

    function renderEventSection(title, events, id, show = false) {
        return `<div class="collapsible-header" onclick="JATOC.toggle('${id}')">${title}<span class="vatusa-count-badge">${events.length}</span></div>
        <div class="collapsible-content ${show ? 'show' : ''}" id="${id}">${events.length ? events.map(e => `<div class="vatusa-event"><div class="vatusa-event-name">${esc(e.name || e.title || PERTII18n.t('jatoc.events.event'))}</div><small class="text-muted">${formatEventTime(e.start || e.start_date || e.date)}${e.facility ? ' • ' + e.facility : ''}</small></div>`).join('') : `<div class="text-muted small py-1">${PERTII18n.t('common.none')}</div>`}</div>`;
    }

    function formatEventTime(d) { try { const dt = new Date(d); if (isNaN(dt.getTime())) {return '';} return dt.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) + ' ' + dt.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: false, timeZone: 'UTC' }) + 'Z'; } catch { return ''; } }
    function toggle(id) { document.getElementById(id)?.classList.toggle('show'); }

    // ========== INCIDENTS ==========
    async function loadPersonnel() {
        try {
            const r = await api('personnel.php');
            document.getElementById('personnelTableBody').innerHTML = (r.data || []).map(p => `<tr style="cursor:pointer" onclick="JATOC.editPersonnel('${p.element}','${p.initials||''}','${(p.name||'').replace(/'/g,"\\'")}')"><td class="text-info">${p.element}</td><td>${p.initials||''}</td><td>${p.name||''}</td></tr>`).join('');
        } catch (e) { console.log('[JATOC] Personnel error:', e); }
    }

    async function loadIncidents() {
        // Store previous data for buffered update
        const previousIncidents = state.incidents ? state.incidents.slice() : [];

        try {
            const filters = { status: document.getElementById('filterStatus').value, facilityType: document.getElementById('filterFacilityType').value, incidentType: document.getElementById('filterIncidentType').value, facility: document.getElementById('filterFacility').value };
            Object.keys(filters).forEach(k => { if (!filters[k]) {delete filters[k];} });
            const r = await api('incidents.php', 'GET', filters);
            const newIncidents = r.data || [];

            // BUFFERED: Only update if we got data, or had no prior data
            if (newIncidents.length > 0 || previousIncidents.length === 0) {
                state.incidents = newIncidents;
            } else {
                console.log('[JATOC] Empty response, keeping previous data (' + previousIncidents.length + ' incidents)');
            }

            renderIncidents();
            updateStats();
            updateMapIncidents(state.incidents);
        } catch (e) {
            console.error('[JATOC] Error loading incidents:', e);
            // BUFFERED: Don't clear state on error - keep showing old data
            if (previousIncidents.length === 0) {
                document.getElementById('eventsTableBody').innerHTML = `<tr><td colspan="7" class="text-danger text-center">${PERTII18n.t('jatoc.incidents.errorLoading')}</td></tr>`;
            } else {
                console.log('[JATOC] Keeping previous data due to error (' + previousIncidents.length + ' incidents)');
            }
        }
    }

    function renderIncidents() {
        const tbody = document.getElementById('eventsTableBody');
        if (!state.incidents.length) { tbody.innerHTML = `<tr><td colspan="7" class="text-muted text-center py-4">${PERTII18n.t('jatoc.incidents.noIncidents')}</td></tr>`; return; }
        tbody.innerHTML = state.incidents.map(i => {
            const incidentType = i.incident_type || i.status;
            const lifecycleStatus = i.lifecycle_status || i.incident_status;
            const sc = 'status-' + incidentType.toLowerCase().replace('_', '-');
            const triggerText = getTriggerLabel(i.trigger_code) || i.trigger_desc || i.trigger_code || '-';
            const isHidden = state.hiddenIncidents.has(i.id);
            return `<tr>
                <td class="incident-number">${i.incident_number || '-'}</td>
                <td><strong>${i.facility}</strong>${i.facility_type ? `<br><small class="text-muted">${i.facility_type}</small>` : ''}</td>
                <td><span class="status-badge ${sc}">${formatStatus(incidentType)}</span></td>
                <td class="trigger-col">${esc(triggerText)}</td>
                <td class="${i.paged ? 'paged-yes' : 'paged-no'}">${i.paged ? PERTII18n.t('jatoc.incidents.pagedYes') : PERTII18n.t('jatoc.incidents.pagedNo')}</td>
                <td>${formatTime(i.start_utc)}</td>
                <td class="quick-actions-cell"><div class="quick-actions">
                    <button class="btn btn-xs btn-outline-info" onclick="JATOC.showDetails(${i.id})" title="${PERTII18n.t('common.view')}"><i class="fas fa-eye"></i></button>
                    <button class="btn btn-xs btn-outline-secondary" onclick="JATOC.editIncident(${i.id})" title="${PERTII18n.t('common.edit')}"><i class="fas fa-edit"></i></button>
                    <button class="btn btn-xs btn-outline-${isHidden ? 'success' : 'warning'}" onclick="JATOC.toggleMapVisibility(${i.id})" title="${isHidden ? PERTII18n.t('jatoc.incidents.show') : PERTII18n.t('jatoc.incidents.hide')}"><i class="fas fa-${isHidden ? 'eye' : 'eye-slash'}"></i></button>
                    ${!i.paged ? `<button class="btn btn-xs btn-outline-warning" onclick="JATOC.quickPage(${i.id})" title="${PERTII18n.t('jatoc.incidents.page')}"><i class="fas fa-bell"></i></button>` : ''}
                    ${lifecycleStatus === 'ACTIVE' ? `<button class="btn btn-xs btn-outline-success" onclick="JATOC.quickClose(${i.id})" title="${PERTII18n.t('common.close')}"><i class="fas fa-check"></i></button>` : ''}
                    ${i.report_number ? `<button class="btn btn-xs btn-outline-primary" onclick="JATOC.viewReportById(${i.id})" title="${PERTII18n.t('jatoc.incidents.viewReport')}"><i class="fas fa-file-invoice"></i></button>` : ''}
                </div></td>
            </tr>`;
        }).join('');
    }

    function updateStats() {
        const active = state.incidents.filter(i => (i.lifecycle_status || i.incident_status) === 'ACTIVE');
        document.getElementById('statsAtcZero').textContent = active.filter(i => (i.incident_type || i.status) === 'ATC_ZERO').length;
        document.getElementById('statsAtcAlert').textContent = active.filter(i => (i.incident_type || i.status) === 'ATC_ALERT').length;
        document.getElementById('statsAtcLimited').textContent = active.filter(i => (i.incident_type || i.status) === 'ATC_LIMITED').length;
        document.getElementById('statsActive').textContent = active.length;
    }

    async function quickPage(id) {
        if (!requireDCC('mark incidents as paged')) {return;}
        if (!confirm(PERTII18n.t('jatoc.incidents.markPagedConfirm'))) {return;}
        try { await api('incident.php?id=' + id, 'PUT', { paged: true, updated_by: getUserAuthorString() }); await api('updates.php', 'POST', { incident_id: id, update_type: 'PAGED', remarks: PERTII18n.t('jatoc.incidents.personnelPaged'), created_by: getUserAuthorString() }); loadIncidents(); } catch (e) { alert(PERTII18n.t('jatoc.error.generic', { message: e.message })); }
    }

    async function quickClose(id) {
        if (!requireDCC('close out incidents')) {return;}
        if (!confirm(PERTII18n.t('jatoc.incidents.closeOutConfirm'))) {return;}
        try {
            const now = new Date().toISOString().slice(0, 19).replace('T', ' ');
            await api('incident.php?id=' + id, 'PUT', { incident_status: 'CLOSED', closeout_utc: now, updated_by: getUserAuthorString() });
            await api('updates.php', 'POST', { incident_id: id, update_type: 'CLOSEOUT', remarks: PERTII18n.t('jatoc.incidents.incidentClosedOut'), created_by: getUserAuthorString() });
            loadIncidents();
        } catch (e) { alert(PERTII18n.t('jatoc.error.generic', { message: e.message })); }
    }

    function toggleMapVisibility(id) { if (state.hiddenIncidents.has(id)) {state.hiddenIncidents.delete(id);} else {state.hiddenIncidents.add(id);} renderIncidents(); updateMapIncidents(state.incidents); }

    function showCreateModal() {
        if (!requireDCC('create incidents')) {return;}
        document.getElementById('incidentModalTitle').textContent = PERTII18n.t('jatoc.incidents.newIncident');
        document.getElementById('incidentForm').reset();
        document.getElementById('incidentId').value = '';
        document.getElementById('incidentStartUtc').value = new Date().toISOString().slice(0, 16);
        $('#incidentModal').modal('show');
    }

    async function editIncident(id) {
        if (!requireDCC('edit incidents')) {return;}
        try {
            const r = await api('incident.php?id=' + id);
            const i = r.data;
            const incidentType = i.incident_type || i.status;
            const lifecycleStatus = i.lifecycle_status || i.incident_status;
            document.getElementById('incidentModalTitle').textContent = PERTII18n.t('jatoc.incidents.editFacility', { facility: i.facility });
            document.getElementById('incidentId').value = i.id;
            document.getElementById('incidentFacility').value = i.facility || '';
            document.getElementById('incidentFacilityType').value = i.facility_type || '';
            document.getElementById('incidentStatus').value = incidentType || '';
            document.getElementById('incidentTrigger').value = i.trigger_code || '';
            document.getElementById('incidentPaged').value = i.paged ? '1' : '0';
            document.getElementById('incidentIncidentStatus').value = lifecycleStatus || 'ACTIVE';
            document.getElementById('incidentStartUtc').value = i.start_utc ? i.start_utc.replace(' ', 'T').slice(0, 16) : '';
            document.getElementById('incidentRemarks').value = i.remarks || '';
            $('#incidentModal').modal('show');
        } catch (e) { alert(PERTII18n.t('jatoc.error.generic', { message: e.message })); }
    }

    async function saveIncident() {
        const id = document.getElementById('incidentId').value;
        const data = { facility: document.getElementById('incidentFacility').value, facility_type: document.getElementById('incidentFacilityType').value || null, status: document.getElementById('incidentStatus').value, trigger_code: document.getElementById('incidentTrigger').value || null, paged: document.getElementById('incidentPaged').value === '1', incident_status: document.getElementById('incidentIncidentStatus').value, start_utc: document.getElementById('incidentStartUtc').value, remarks: document.getElementById('incidentRemarks').value || null, created_by: getUserAuthorString() };
        if (!data.facility || !data.status || !data.start_utc) { alert(PERTII18n.t('jatoc.incidents.fillRequired')); return; }
        try { if (id) { data.updated_by = getUserAuthorString(); await api('incident.php?id=' + id, 'PUT', data); } else {await api('incidents.php', 'POST', data);} $('#incidentModal').modal('hide'); loadIncidents(); } catch (e) { alert(PERTII18n.t('jatoc.error.generic', { message: e.message })); }
    }

    async function showIncidentDetails(id) {
        try {
            const r = await api('incident.php?id=' + id);
            const i = r.data;
            const incidentType = i.incident_type || i.status;
            const lifecycleStatus = i.lifecycle_status || i.incident_status;
            state.currentIncident = i;
            document.getElementById('detailsIncNum').textContent = i.incident_number || '-';
            document.getElementById('detailsFacility').textContent = `${i.facility} (${i.facility_type || '?'})`;
            document.getElementById('detailsStatus').innerHTML = `<span class="status-badge status-${incidentType.toLowerCase().replace('_','-')}">${formatStatus(incidentType)}</span>`;
            document.getElementById('detailsTrigger').textContent = i.trigger_code ? `${i.trigger_code} - ${getTriggerLabel(i.trigger_code)}` : '-';
            document.getElementById('detailsPaged').innerHTML = i.paged ? `<span class="text-success">${PERTII18n.t('common.yes')}</span>` : `<span class="text-muted">${PERTII18n.t('common.no')}</span>`;
            document.getElementById('detailsStartTime').textContent = formatTimeISO(i.start_utc);
            document.getElementById('detailsDuration').textContent = calcDuration(i.start_utc, i.closeout_utc);
            document.getElementById('detailsCreatedBy').textContent = i.created_by || '-';
            document.getElementById('detailsReportNum').innerHTML = i.report_number ? `<span class="report-number">${i.report_number}</span>` : '-';
            document.getElementById('detailsRemarks').textContent = i.remarks || PERTII18n.t('jatoc.incidents.noRemarks');
            document.getElementById('updateIncidentId').value = i.id;
            document.getElementById('modalOpsLevel').value = state.opsLevel;

            // Show/hide View Report button
            const viewReportBtn = document.getElementById('btnViewReport');
            if (viewReportBtn) {
                viewReportBtn.style.display = i.report_number ? 'block' : 'none';
            }

            // Show Close Out or Reopen based on lifecycle status
            const closeOutBtn = document.getElementById('btnCloseOut');
            const reopenBtn = document.getElementById('btnReopen');
            if (closeOutBtn && reopenBtn) {
                if (lifecycleStatus === 'CLOSED') {
                    closeOutBtn.style.display = 'none';
                    reopenBtn.style.display = 'block';
                } else {
                    closeOutBtn.style.display = 'block';
                    reopenBtn.style.display = 'none';
                }
            }

            loadUpdates(i.id);
            $('#incidentDetailsModal').modal('show');
        } catch (e) { alert(PERTII18n.t('jatoc.error.generic', { message: e.message })); }
    }

    async function loadUpdates(id) {
        try {
            const r = await api('updates.php', 'GET', { incident_id: id });
            const el = document.getElementById('detailsUpdates');
            if (r.data?.length) {
                el.innerHTML = r.data.map(u => {
                    const isOpsLevel = u.update_type === 'OPS_LEVEL';
                    return `<div class="update-entry ${isOpsLevel ? 'ops-level' : ''}">
                        <div class="update-header">
                            <span class="type">${u.update_type}</span>
                            <span class="author">${esc(u.created_by || '?')}</span>
                            <span class="time">${formatTimeISO(u.created_utc)}</span>
                        </div>
                        <div class="update-content">${formatPriority(esc(u.remarks || ''))}</div>
                    </div>`;
                }).join('');
            } else {el.innerHTML = `<div class="text-muted text-center py-3">${PERTII18n.t('jatoc.updates.noUpdates')}</div>`;}
        } catch (e) { console.log('[JATOC] Updates error:', e); }
    }

    function formatPriority(text) { return text.replace(/&lt;\s*([^&]+?)\s*&gt;/g, '<span class="priority-text">&lt; $1 &gt;</span>'); }

    async function addUpdate() {
        if (!requireProfile('add updates')) {return;}
        const id = document.getElementById('updateIncidentId').value;
        const remarks = document.getElementById('updateRemarks').value;
        if (!remarks.trim()) { alert(PERTII18n.t('jatoc.updates.enterNotes')); return; }
        try { await api('updates.php', 'POST', { incident_id: id, update_type: document.getElementById('updateType').value, remarks, created_by: getUserAuthorString() }); document.getElementById('updateRemarks').value = ''; loadUpdates(id); loadIncidents(); } catch (e) { alert(PERTII18n.t('jatoc.error.generic', { message: e.message })); }
    }

    async function markPaged() {
        if (!state.currentIncident) {return;}
        if (!requireDCC('mark incidents as paged')) {return;}
        try { await api('incident.php?id=' + state.currentIncident.id, 'PUT', { paged: true, updated_by: getUserAuthorString() }); await api('updates.php', 'POST', { incident_id: state.currentIncident.id, update_type: 'PAGED', remarks: PERTII18n.t('jatoc.incidents.personnelPaged'), created_by: getUserAuthorString() }); showIncidentDetails(state.currentIncident.id); loadIncidents(); } catch (e) { alert(PERTII18n.t('jatoc.error.generic', { message: e.message })); }
    }

    function editFromDetails() { if (state.currentIncident) { $('#incidentDetailsModal').modal('hide'); editIncident(state.currentIncident.id); } }

    async function generateReport() {
        if (!state.currentIncident) {return;}
        if (!requireDCC('generate report numbers')) {return;}
        if (state.currentIncident.report_number) { alert(PERTII18n.t('jatoc.report.alreadyHas', { number: state.currentIncident.report_number })); return; }
        if (!confirm(PERTII18n.t('jatoc.report.generateConfirm'))) {return;}
        try { const r = await api('report.php', 'POST', { incident_id: state.currentIncident.id, created_by: getUserAuthorString() }); alert(PERTII18n.t('jatoc.report.generated', { number: r.report_number || '?' })); showIncidentDetails(state.currentIncident.id); loadIncidents(); } catch (e) { alert(PERTII18n.t('jatoc.error.generic', { message: e.message })); }
    }

    async function closeoutIncident() {
        if (!state.currentIncident) {return;}
        if (!requireDCC('close out incidents')) {return;}
        if (!confirm(PERTII18n.t('jatoc.incidents.closeOutThisConfirm'))) {return;}
        try {
            const now = new Date().toISOString().slice(0, 19).replace('T', ' ');
            await api('incident.php?id=' + state.currentIncident.id, 'PUT', { incident_status: 'CLOSED', closeout_utc: now, updated_by: getUserAuthorString() });
            // Log the closeout
            await api('updates.php', 'POST', { incident_id: state.currentIncident.id, update_type: 'CLOSEOUT', remarks: PERTII18n.t('jatoc.incidents.incidentClosedOut'), created_by: getUserAuthorString() });
            $('#incidentDetailsModal').modal('hide');
            loadIncidents();
        } catch (e) { alert(PERTII18n.t('jatoc.error.generic', { message: e.message })); }
    }

    async function reopenIncident() {
        if (!state.currentIncident) {return;}
        if (!requireDCC('reopen incidents')) {return;}
        if (!confirm(PERTII18n.t('jatoc.incidents.reopenConfirm'))) {return;}
        try {
            await api('incident.php?id=' + state.currentIncident.id, 'PUT', { incident_status: 'ACTIVE', closeout_utc: null, updated_by: getUserAuthorString() });
            // Log the reopen
            await api('updates.php', 'POST', { incident_id: state.currentIncident.id, update_type: 'REOPEN', remarks: PERTII18n.t('jatoc.incidents.incidentReopened'), created_by: getUserAuthorString() });
            showIncidentDetails(state.currentIncident.id);
            loadIncidents();
        } catch (e) { alert(PERTII18n.t('jatoc.error.generic', { message: e.message })); }
    }

    async function deleteIncident() {
        if (!state.currentIncident || !confirm(PERTII18n.t('jatoc.incidents.deleteConfirm'))) {return;}
        try { await api('incident.php?id=' + state.currentIncident.id, 'DELETE'); $('#incidentDetailsModal').modal('hide'); loadIncidents(); } catch (e) { alert(PERTII18n.t('jatoc.error.generic', { message: e.message })); }
    }

    function viewReport() {
        if (!state.currentIncident?.report_number) { alert(PERTII18n.t('jatoc.report.notGenerated')); return; }
        window.open(`api/jatoc/report.php?id=${state.currentIncident.id}&format=text`, '_blank');
    }

    async function viewReportById(id) {
        window.open(`api/jatoc/report.php?id=${id}&format=text`, '_blank');
    }

    // ========== DAILY OPS & PERSONNEL ==========
    function editDailyOps(type) { document.getElementById('dailyOpsType').value = type; document.getElementById('dailyOpsTypeLabel').textContent = type; const el = document.getElementById(type === 'POTUS' ? 'potusCalendar' : 'spaceCalendar'); document.getElementById('dailyOpsContent').value = el?.innerText || ''; $('#dailyOpsModal').modal('show'); }
    async function saveDailyOps() {
        if (!requireDCC('edit daily ops')) {return;}
        const type = document.getElementById('dailyOpsType').value;
        try { await api('daily_ops.php', 'PUT', { item_type: type, content: document.getElementById('dailyOpsContent').value, updated_by: getUserAuthorString() }); $('#dailyOpsModal').modal('hide'); if (type === 'POTUS') {loadPotusCalendar();} else {loadSpaceCalendar();} } catch (e) { alert(PERTII18n.t('jatoc.error.generic', { message: e.message })); }
    }
    function editPersonnel(elem, init, name) { document.getElementById('personnelElement').textContent = elem; document.getElementById('personnelElementInput').value = elem; document.getElementById('personnelInitials').value = init; document.getElementById('personnelName').value = name; $('#personnelModal').modal('show'); }
    async function savePersonnel() {
        if (!requireDCC('edit personnel')) {return;}
        try { await api('personnel.php', 'PUT', { element: document.getElementById('personnelElementInput').value, initials: document.getElementById('personnelInitials').value, name: document.getElementById('personnelName').value, updated_by: getUserAuthorString() }); $('#personnelModal').modal('hide'); loadPersonnel(); } catch (e) { alert(PERTII18n.t('jatoc.error.generic', { message: e.message })); }
    }
    async function clearPersonnel() {
        if (!requireDCC('edit personnel')) {return;}
        try { await api('personnel.php', 'PUT', { element: document.getElementById('personnelElementInput').value, initials: null, name: null, updated_by: getUserAuthorString() }); $('#personnelModal').modal('hide'); loadPersonnel(); } catch (e) { alert(PERTII18n.t('jatoc.error.generic', { message: e.message })); }
    }

    // ========== UTILITIES ==========
    function formatTime(dt) { if (!dt) {return '-';} try { const d = new Date(dt.includes('Z') ? dt : dt + 'Z'); return d.toISOString().slice(5, 16).replace('T', ' ') + 'Z'; } catch { return dt; } }
    function formatTimeISO(dt) { if (!dt) {return '-';} try { const d = new Date(dt.includes('Z') ? dt : dt + 'Z'); return d.toISOString().slice(0, 19).replace('T', ' ') + 'Z'; } catch { return dt; } }
    function formatStatus(s) { return (s || '').replace('_', ' '); }
    function calcDuration(startStr, endStr) { if (!startStr) {return '-';} try { const start = new Date(startStr.includes('Z') ? startStr : startStr + 'Z'); const end = endStr ? new Date(endStr.includes('Z') ? endStr : endStr + 'Z') : new Date(); const diffMs = end.getTime() - start.getTime(); if (diffMs < 0) {return '0m';} const mins = Math.floor(diffMs / 60000); if (mins < 60) {return mins + 'm';} return Math.floor(mins / 60) + 'h ' + (mins % 60) + 'm'; } catch { return '-'; } }
    function decodeHtml(s) { const txt = document.createElement('textarea'); txt.innerHTML = s || ''; return txt.value; }
    function esc(s) { return decodeHtml(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
    function applyFilters() { loadIncidents(); }

    // ========== RETRIEVE INCIDENT ==========
    function showRetrieveModal() {
        clearRetrieveForm();
        $('#retrieveModal').modal('show');
    }

    function clearRetrieveForm() {
        document.getElementById('retrieveIncNum').value = '';
        document.getElementById('retrieveReportNum').value = '';
        document.getElementById('retrieveFacility').value = '';
        document.getElementById('retrieveStatus').value = '';
        document.getElementById('retrieveIncType').value = '';
        document.getElementById('retrieveFromDate').value = '';
        document.getElementById('retrieveToDate').value = '';
        document.getElementById('retrieveResults').innerHTML = `<div class="text-muted text-center py-3">${PERTII18n.t('jatoc.retrieve.enterCriteria')}</div>`;
    }

    async function searchIncidents() {
        const incNum = document.getElementById('retrieveIncNum').value.trim();
        const reportNum = document.getElementById('retrieveReportNum').value.trim();
        const facility = document.getElementById('retrieveFacility').value.trim();
        const status = document.getElementById('retrieveStatus').value;
        const incType = document.getElementById('retrieveIncType').value;
        const fromDate = document.getElementById('retrieveFromDate').value;
        const toDate = document.getElementById('retrieveToDate').value;

        const resultsEl = document.getElementById('retrieveResults');
        resultsEl.innerHTML = `<div class="text-muted text-center py-3"><i class="fas fa-spinner fa-spin"></i> ${PERTII18n.t('jatoc.retrieve.searching')}</div>`;

        try {
            // Build query params
            const params = {};
            if (incNum) {params.incident_number = incNum;}
            if (reportNum) {params.report_number = reportNum;}
            if (facility) {params.facility = facility;}
            if (status) {params.status = status;}
            if (incType) {params.incidentType = incType;}
            if (fromDate) {params.from_date = fromDate;}
            if (toDate) {params.to_date = toDate;}

            const r = await api('incidents.php', 'GET', params);
            const incidents = r.data || [];

            if (!incidents.length) {
                resultsEl.innerHTML = `<div class="text-muted text-center py-3">${PERTII18n.t('jatoc.incidents.noIncidents')}</div>`;
                return;
            }

            resultsEl.innerHTML = `
                <table class="table table-sm table-dark mb-0" style="font-size:0.75rem">
                    <thead><tr><th>${PERTII18n.t('jatoc.retrieve.colIncNum')}</th><th>${PERTII18n.t('jatoc.retrieve.colFacility')}</th><th>${PERTII18n.t('jatoc.retrieve.colIncidentType')}</th><th>${PERTII18n.t('jatoc.retrieve.colLifecycle')}</th><th>${PERTII18n.t('jatoc.retrieve.colStart')}</th><th>${PERTII18n.t('jatoc.retrieve.colAction')}</th></tr></thead>
                    <tbody>
                        ${incidents.map(i => {
        const incidentType = i.incident_type || i.status;
        const lifecycleStatus = i.lifecycle_status || i.incident_status;
        return `
                            <tr>
                                <td class="incident-number">${i.incident_number || '-'}</td>
                                <td>${i.facility}</td>
                                <td><span class="status-badge status-${incidentType.toLowerCase().replace('_','-')}">${formatStatus(incidentType)}</span></td>
                                <td>${lifecycleStatus}</td>
                                <td>${formatTime(i.start_utc)}</td>
                                <td>
                                    <button class="btn btn-xs btn-outline-info" onclick="JATOC.openRetrievedIncident(${i.id})" title="${PERTII18n.t('jatoc.retrieve.open')}"><i class="fas fa-folder-open"></i></button>
                                </td>
                            </tr>
                        `;}).join('')}
                    </tbody>
                </table>
            `;
        } catch (e) {
            resultsEl.innerHTML = `<div class="text-danger text-center py-3">${PERTII18n.t('jatoc.error.generic', { message: e.message })}</div>`;
        }
    }

    function openRetrievedIncident(id) {
        $('#retrieveModal').modal('hide');
        showIncidentDetails(id);
    }

    window.JATOC = { showCreateModal, editIncident, saveIncident, showDetails: showIncidentDetails, editDailyOps, saveDailyOps, editPersonnel, savePersonnel, clearPersonnel, applyFilters, addUpdate, markPaged, editFromDetails, generateReport, closeoutIncident, deleteIncident, toggle, changeOpsLevel, quickPage, quickClose, toggleMapVisibility, showUserProfile, onFacilityTypeChange, saveProfile, clearProfile, viewReport, viewReportById, reopenIncident, showRetrieveModal, clearRetrieveForm, searchIncidents, openRetrievedIncident };
})();
