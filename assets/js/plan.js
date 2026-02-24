const pathname = $(location).attr('href');
const uri = pathname.split('?');
const p_id = uri[1] ? uri[1].split('#')[0] : '';

const summernoteFields = [
    'atp_comments',
    'etp_comments',
    'aep_comments',
    'eep_comments',
];

summernoteFields.forEach(e => {
    $(`#${e}`).summernote({
        toolbar: [
            ['style', ['bold', 'italic', 'underline']],
            ['para', ['ul', 'ol']],
            ['insert', ['link', 'table']],
            ['misc', ['undo', 'redo', 'codeview']],
        ],
        height: 150,
        disableDragAndDrop: true,
    });
});


function loadGoals() {
    $('[data-toggle="tooltip"]').tooltip('dispose');
    $.get(`api/data/plans/goals?p_id=${p_id}`).done(function(data) {
        $('#goals_table').html(data);
        tooltips();
    });
}

function loadDCCStaffing() {
    $('[data-toggle="tooltip"]').tooltip('dispose');
    PlanTables.loadDCCStaffing().then(function() {
        tooltips();
        if (typeof opsPlanUpdateMessage === 'function') {
            opsPlanUpdateMessage();
        }
    });
}

function loadHistorical() {
    $('[data-toggle="tooltip"]').tooltip('dispose');
    $.get(`api/data/plans/historical?p_id=${p_id}`).done(function(data) {
        $('#historicaldata').html(data);
        tooltips();
    });
}

function loadForecast() {
    $('[data-toggle="tooltip"]').tooltip('dispose');
    $.get(`api/data/plans/forecast?p_id=${p_id}`).done(function(data) {
        $('#forecastdata').html(data);
        tooltips();
    });
}

function loadTermInits() {
    $('[data-toggle="tooltip"]').tooltip('dispose');
    $.get(`api/data/plans/term_inits?p_id=${p_id}`).done(function(data) {
        $('#term_inits').html(data);
        tooltips();
        if (typeof opsPlanUpdateMessage === 'function') {
            opsPlanUpdateMessage();
        }

    });
}

function loadTermStaffing() {
    $('[data-toggle="tooltip"]').tooltip('dispose');
    PlanTables.loadTermStaffing().then(function() { tooltips(); });
}

function loadConfigs() {
    $('[data-toggle="tooltip"]').tooltip('dispose');
    PlanTables.loadConfigs().then(function() { tooltips(); });
}

function loadTermPlanning() {
    $('[data-toggle="tooltip"]').tooltip('dispose');
    $.get(`api/data/plans/term_planning?p_id=${p_id}`).done(function(data) {
        $('#termplanningdata').html(data);
        tooltips();
    });
}

function loadTermConstraints() {
    $('[data-toggle="tooltip"]').tooltip('dispose');
    $.get(`api/data/plans/term_constraints?p_id=${p_id}`).done(function(data) {
        $('#term_constraints_table').html(data);
        tooltips();
        if (typeof opsPlanUpdateMessage === 'function') {
            opsPlanUpdateMessage();
        }

    });
}

function loadEnrouteInits() {
    $('[data-toggle="tooltip"]').tooltip('dispose');
    $.get(`api/data/plans/enroute_inits?p_id=${p_id}`).done(function(data) {
        $('#enroute_inits').html(data);
        tooltips();
        if (typeof opsPlanUpdateMessage === 'function') {
            opsPlanUpdateMessage();
        }

    });
}

function loadEnrouteStaffing() {
    $('[data-toggle="tooltip"]').tooltip('dispose');
    PlanTables.loadEnrouteStaffing().then(function() { tooltips(); });
}

function loadEnroutePlanning() {
    $('[data-toggle="tooltip"]').tooltip('dispose');
    $.get(`api/data/plans/enroute_planning?p_id=${p_id}`).done(function(data) {
        $('#enrouteplanningdata').html(data);
        tooltips();
    });
}

function loadEnrouteConstraints() {
    $('[data-toggle="tooltip"]').tooltip('dispose');
    $.get(`api/data/plans/enroute_constraints?p_id=${p_id}`).done(function(data) {
        $('#enroute_constraints_table').html(data);
        tooltips();
        if (typeof opsPlanUpdateMessage === 'function') {
            opsPlanUpdateMessage();
        }

    });
}

function loadGroupFlights() {
    $('[data-toggle="tooltip"]').tooltip('dispose');
    $.get(`api/data/plans/group_flights?p_id=${p_id}`).done(function(data) {
        $('#group_flights_table').html(data);
        tooltips();
    });
}

function loadOutlook() {
    $('[data-toggle="tooltip"]').tooltip('dispose');
    $.get(`api/data/plans/outlook?p_id=${p_id}`).done(function(data) {
        $('#outlook_data').html(data);
        tooltips();
    });
}

// Lazy-load splits overview on first tab visit
var _splitsTabLoaded = false;
$('a[data-toggle="tab"][href="#e_splits"]').on('shown.bs.tab', function() {
    if (!_splitsTabLoaded) {
        _splitsTabLoaded = true;
        PlanTables.loadSplitsOverview();
    }
});

// Load all plan sections in parallel for faster page load
(function loadAllSections() {
    $('[data-toggle="tooltip"]').tooltip('dispose');

    // Load sortable tables via PlanTables module (configs, staffing, DCC)
    const tablesReady = PlanTables.init({
        apiBase: 'api/data/plans',
        p_id: p_id,
        hasDccPersonnel: true,
    });

    // Load remaining HTML-rendered sections in parallel
    const sectionsReady = Promise.all([
        $.get(`api/data/plans/goals?p_id=${p_id}`),
        $.get(`api/data/plans/historical?p_id=${p_id}`),
        $.get(`api/data/plans/forecast?p_id=${p_id}`),
        $.get(`api/data/plans/term_inits?p_id=${p_id}`),
        $.get(`api/data/plans/term_planning?p_id=${p_id}`),
        $.get(`api/data/plans/term_constraints?p_id=${p_id}`),
        $.get(`api/data/plans/enroute_inits?p_id=${p_id}`),
        $.get(`api/data/plans/enroute_planning?p_id=${p_id}`),
        $.get(`api/data/plans/enroute_constraints?p_id=${p_id}`),
        $.get(`api/data/plans/group_flights?p_id=${p_id}`),
        $.get(`api/data/plans/outlook?p_id=${p_id}`)
    ]).then(function(results) {
        $('#goals_table').html(results[0]);
        $('#historicaldata').html(results[1]);
        $('#forecastdata').html(results[2]);
        $('#term_inits').html(results[3]);
        $('#termplanningdata').html(results[4]);
        $('#term_constraints_table').html(results[5]);
        $('#enroute_inits').html(results[6]);
        $('#enrouteplanningdata').html(results[7]);
        $('#enroute_constraints_table').html(results[8]);
        $('#group_flights_table').html(results[9]);
        $('#outlook_data').html(results[10]);
    });

    Promise.all([tablesReady, sectionsReady]).then(function() {
        tooltips();
        if (typeof opsPlanUpdateMessage === 'function') {
            opsPlanUpdateMessage();
        }
    });
})();

// AJAX: #addgoal POST
$('#addgoal').submit(function(e) {
    e.preventDefault();

    const url = 'api/mgt/goals/post';

    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize().replace(/'/g, '`'),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      PERTII18n.t('plan.successAdded'),
                text:       PERTII18n.t('plan.goal.added'),
                timer:      3000,
                showConfirmButton: false,
            });

            loadGoals();
            $('#addgoalModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  PERTII18n.t('plan.notAdded'),
                text:   PERTII18n.t('plan.goal.addError'),
            });
        },
    });
});

// Edit Goal Modal
$('#editgoalModal').on('show.bs.modal', function(event) {
    const button = $(event.relatedTarget);

    const modal= $(this);

    modal.find('.modal-body #id').val(button.data('id'));
    modal.find('.modal-body #comments').html(button.data('comments'));
});

// AJAX: #editgoal POST
$('#editgoal').submit(function(e) {
    e.preventDefault();

    const url = 'api/mgt/goals/update';

    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize().replace(/'/g, '`'),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      PERTII18n.t('plan.successEdited'),
                text:       PERTII18n.t('plan.goal.edited'),
                timer:      3000,
                showConfirmButton: false,
            });

            loadGoals();
            $('#editgoalModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  PERTII18n.t('plan.notEdited'),
                text:   PERTII18n.t('plan.goal.editError'),
            });
        },
    });
});

// FUNC: deleteGoal [id:]
function deleteGoal(id) {
    $.ajax({
        type:   'POST',
        url:    'api/mgt/goals/delete',
        data:   {id: id},
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      PERTII18n.t('plan.successDeleted'),
                text:       PERTII18n.t('plan.goal.deleted'),
                timer:      3000,
                showConfirmButton: false,
            });

            loadGoals();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  PERTII18n.t('plan.notDeleted'),
                text:   PERTII18n.t('plan.goal.deleteError'),
            });
        },
    });
}

// =====================================================
// DCC Staffing Dynamic Dropdowns
// =====================================================
const planDcc = (function() {
    const DATA = window.PERTI_FACILITY_DATA || {};

    function buildTypeOptions(sel) {
        sel.innerHTML = '<option value="">' + PERTII18n.t('plan.dcc.selectType') + '</option>';

        // Command center org types
        const orgGroup = document.createElement('optgroup');
        orgGroup.label = PERTII18n.t('plan.dcc.commandCenter');
        (DATA.PLAN_ORG_TYPES || []).forEach(function(t) {
            const opt = document.createElement('option');
            opt.value = t;
            // Friendly labels
            const labels = { DCC: 'DCC', CANOC: 'CANOC', ECFMP: 'ECFMP', CTP: 'CTP', WF: 'WorldFlight' };
            opt.textContent = labels[t] || t;
            orgGroup.appendChild(opt);
        });
        sel.appendChild(orgGroup);

        // ATC facility types
        const facGroup = document.createElement('optgroup');
        facGroup.label = PERTII18n.t('plan.dcc.atcFacility');
        (DATA.PLAN_FACILITY_TYPES || []).forEach(function(t) {
            const opt = document.createElement('option');
            opt.value = t;
            opt.textContent = t;
            facGroup.appendChild(opt);
        });
        sel.appendChild(facGroup);
    }

    function onTypeChange(prefix) {
        const typeSel = document.getElementById(prefix + '_dcc_type');
        const serviceRow = document.getElementById(prefix + '_dcc_service_row');
        const serviceSel = document.getElementById(prefix + '_dcc_service');
        const facilityRow = document.getElementById(prefix + '_dcc_facility_row');
        const facilitySel = document.getElementById(prefix + '_dcc_facility');
        const roleSel = document.getElementById(prefix + '_dcc_role');
        const val = typeSel.value;

        // Reset downstream
        serviceRow.style.display = 'none';
        facilityRow.style.display = 'none';
        roleSel.innerHTML = '<option value="">' + PERTII18n.t('plan.dcc.selectTypeFirst') + '</option>';

        if (!val) return;

        if (val === 'DCC') {
            // Show service dropdown filtered to PLAN_DCC_SERVICES
            serviceRow.style.display = '';
            serviceSel.innerHTML = '<option value="">' + PERTII18n.t('plan.dcc.selectService') + '</option>';
            const allowed = DATA.PLAN_DCC_SERVICES || [];
            (DATA.DCC_SERVICES || []).forEach(function(s) {
                if (allowed.indexOf(s.code) !== -1) {
                    const opt = document.createElement('option');
                    opt.value = s.code;
                    opt.textContent = s.name;
                    serviceSel.appendChild(opt);
                }
            });
        } else if (val === 'CANOC') {
            // Show service dropdown filtered to PLAN_CANOC_SERVICES
            serviceRow.style.display = '';
            serviceSel.innerHTML = '<option value="">' + PERTII18n.t('plan.dcc.selectService') + '</option>';
            const allowed = DATA.PLAN_CANOC_SERVICES || [];
            (DATA.CANOC_SERVICES || []).forEach(function(s) {
                if (allowed.indexOf(s.code) !== -1) {
                    const opt = document.createElement('option');
                    opt.value = s.code;
                    opt.textContent = s.name;
                    serviceSel.appendChild(opt);
                }
            });
        } else if (DATA.SINGLE_ORG_TYPES && DATA.SINGLE_ORG_TYPES.indexOf(val) !== -1) {
            // ECFMP, CTP, WF - direct roles, no facility
            populateRoles(roleSel, DATA.ROLES[val] || []);
        } else if (DATA.PLAN_FACILITY_TYPES && DATA.PLAN_FACILITY_TYPES.indexOf(val) !== -1) {
            // ARTCC, TRACON, FIR - show facility dropdown
            facilityRow.style.display = '';
            populateFacilities(facilitySel, val);
            roleSel.innerHTML = '<option value="">' + PERTII18n.t('plan.dcc.selectFacilityFirst') + '</option>';
        }
    }

    function onServiceChange(prefix) {
        const typeSel = document.getElementById(prefix + '_dcc_type');
        const serviceSel = document.getElementById(prefix + '_dcc_service');
        const roleSel = document.getElementById(prefix + '_dcc_role');
        const typeVal = typeSel.value;
        const serviceVal = serviceSel.value;

        if (!serviceVal) {
            roleSel.innerHTML = '<option value="">' + PERTII18n.t('plan.dcc.selectServiceFirst') + '</option>';
            return;
        }

        if (typeVal === 'DCC') {
            populateRoles(roleSel, (DATA.DCC_ROLES || {})[serviceVal] || []);
        } else if (typeVal === 'CANOC') {
            populateRoles(roleSel, (DATA.CANOC_ROLES || {})[serviceVal] || []);
        }
    }

    function onFacilityChange(prefix) {
        const typeSel = document.getElementById(prefix + '_dcc_type');
        const facilitySel = document.getElementById(prefix + '_dcc_facility');
        const roleSel = document.getElementById(prefix + '_dcc_role');
        const typeVal = typeSel.value;
        const facVal = facilitySel.value;

        if (!facVal) {
            roleSel.innerHTML = '<option value="">' + PERTII18n.t('plan.dcc.selectFacilityFirst') + '</option>';
            return;
        }

        // Base FACILITY roles
        const roles = (DATA.ROLES && DATA.ROLES.FACILITY) ? DATA.ROLES.FACILITY.slice() : [];

        // Append FACILITY_EXTRAS if any
        if (DATA.FACILITY_EXTRAS && DATA.FACILITY_EXTRAS[facVal]) {
            DATA.FACILITY_EXTRAS[facVal].forEach(function(r) { roles.push(r); });
        }

        populateRoles(roleSel, roles);
    }

    function populateRoles(sel, roles) {
        sel.innerHTML = '<option value="">' + PERTII18n.t('plan.dcc.selectRole') + '</option>';
        roles.forEach(function(r) {
            const opt = document.createElement('option');
            opt.value = r.name + ' (' + r.code + ')';
            opt.textContent = r.code + ' - ' + r.name;
            sel.appendChild(opt);
        });
    }

    function populateFacilities(sel, facType) {
        sel.innerHTML = '<option value="">' + PERTII18n.t('plan.dcc.selectFacility') + '</option>';
        const list = DATA[facType] || [];

        if (facType === 'TRACON' && DATA.TRACON_CANADA && DATA.TRACON_CANADA.length > 0) {
            // Split into US and Canada optgroups
            const usGroup = document.createElement('optgroup');
            usGroup.label = 'United States';
            list.forEach(function(f) {
                const opt = document.createElement('option');
                opt.value = f.code;
                opt.textContent = f.code + ' - ' + f.name;
                usGroup.appendChild(opt);
            });
            sel.appendChild(usGroup);

            const caGroup = document.createElement('optgroup');
            caGroup.label = 'Canada';
            DATA.TRACON_CANADA.forEach(function(f) {
                const opt = document.createElement('option');
                opt.value = f.code;
                opt.textContent = f.code + ' - ' + f.name;
                caGroup.appendChild(opt);
            });
            sel.appendChild(caGroup);
        } else {
            list.forEach(function(f) {
                const opt = document.createElement('option');
                opt.value = f.code;
                opt.textContent = f.code + ' - ' + f.name;
                sel.appendChild(opt);
            });
        }
    }

    // Set hidden form fields before submit
    function setHiddenFields(prefix) {
        const typeSel = document.getElementById(prefix + '_dcc_type');
        const serviceSel = document.getElementById(prefix + '_dcc_service');
        const facilitySel = document.getElementById(prefix + '_dcc_facility');
        const roleSel = document.getElementById(prefix + '_dcc_role');
        const hiddenFacility = document.getElementById(prefix + '_position_facility');
        const hiddenName = document.getElementById(prefix + '_position_name');
        const val = typeSel.value;

        if (val === 'DCC' || val === 'CANOC') {
            hiddenFacility.value = val;
        } else if ((DATA.SINGLE_ORG_TYPES || []).indexOf(val) !== -1) {
            hiddenFacility.value = val;
        } else {
            // ATC facility types - use the facility code
            hiddenFacility.value = facilitySel.value;
        }
        hiddenName.value = roleSel.value;
    }

    // Reverse-map stored position_facility + position_name back to dropdown selections
    function restoreForEdit(posFacility, posName) {
        const prefix = 'edit';
        const typeSel = document.getElementById(prefix + '_dcc_type');
        const serviceSel = document.getElementById(prefix + '_dcc_service');
        const facilitySel = document.getElementById(prefix + '_dcc_facility');
        const roleSel = document.getElementById(prefix + '_dcc_role');

        // Determine type from position_facility
        const ccCodes = DATA.PLAN_COMMAND_CENTER_CODES || [];
        let detectedType = '';
        let detectedService = '';
        let detectedFacility = '';

        if (posFacility === 'DCC') {
            detectedType = 'DCC';
            // Try to detect service from position_name
            detectedService = detectDCCService(posName);
        } else if (posFacility === 'CANOC') {
            detectedType = 'CANOC';
            detectedService = detectCANOCService(posName);
        } else if ((DATA.SINGLE_ORG_TYPES || []).indexOf(posFacility) !== -1) {
            detectedType = posFacility;
        } else {
            // Must be an ATC facility code - detect which type
            detectedType = detectFacilityType(posFacility);
            detectedFacility = posFacility;
        }

        // Set type and cascade
        typeSel.value = detectedType;
        onTypeChange(prefix);

        if (detectedService && serviceSel) {
            serviceSel.value = detectedService;
            onServiceChange(prefix);
        }

        if (detectedFacility && facilitySel) {
            facilitySel.value = detectedFacility;
            onFacilityChange(prefix);
        }

        // Set role value
        if (posName && roleSel) {
            // Try exact match first
            for (let i = 0; i < roleSel.options.length; i++) {
                if (roleSel.options[i].value === posName) {
                    roleSel.value = posName;
                    return;
                }
            }
            // Fallback: add as custom option (legacy data)
            const opt = document.createElement('option');
            opt.value = posName;
            opt.textContent = posName;
            opt.selected = true;
            roleSel.appendChild(opt);
        }
    }

    function detectDCCService(posName) {
        if (!posName) return '';
        const dccRoles = DATA.DCC_ROLES || {};
        for (const svc in dccRoles) {
            for (let i = 0; i < dccRoles[svc].length; i++) {
                const r = dccRoles[svc][i];
                if (posName === r.name + ' (' + r.code + ')') return svc;
            }
        }
        // Default to TMU if no match (most common)
        return 'TMU';
    }

    function detectCANOCService(posName) {
        if (!posName) return '';
        const roles = DATA.CANOC_ROLES || {};
        for (const svc in roles) {
            for (let i = 0; i < roles[svc].length; i++) {
                const r = roles[svc][i];
                if (posName === r.name + ' (' + r.code + ')') return svc;
            }
        }
        return 'TMU';
    }

    function detectFacilityType(code) {
        if (!code) return '';
        // Check ARTCC
        if ((DATA.ARTCC || []).some(function(f) { return f.code === code; })) return 'ARTCC';
        // Check TRACON (US + Canada)
        if ((DATA.TRACON || []).some(function(f) { return f.code === code; })) return 'TRACON';
        if ((DATA.TRACON_CANADA || []).some(function(f) { return f.code === code; })) return 'TRACON';
        // Check FIR
        if ((DATA.FIR || []).some(function(f) { return f.code === code; })) return 'FIR';
        // Fallback to ARTCC (Z-prefix heuristic)
        if (code.charAt(0) === 'Z') return 'ARTCC';
        return 'ARTCC';
    }

    function init() {
        const addSel = document.getElementById('add_dcc_type');
        const editSel = document.getElementById('edit_dcc_type');
        if (addSel) buildTypeOptions(addSel);
        if (editSel) buildTypeOptions(editSel);

        // Bind facility change events
        var addFac = document.getElementById('add_dcc_facility');
        var editFac = document.getElementById('edit_dcc_facility');
        if (addFac) addFac.addEventListener('change', function() { onFacilityChange('add'); });
        if (editFac) editFac.addEventListener('change', function() { onFacilityChange('edit'); });
    }

    return {
        onTypeChange: onTypeChange,
        onServiceChange: onServiceChange,
        onFacilityChange: onFacilityChange,
        setHiddenFields: setHiddenFields,
        restoreForEdit: restoreForEdit,
        init: init
    };
})();

// Initialize plan DCC dropdowns
planDcc.init();

// Reset add modal when shown
$('#add_dccstaffingModal').on('show.bs.modal', function() {
    document.getElementById('add_dcc_type').value = '';
    planDcc.onTypeChange('add');
    $(this).find('input[name="personnel_name"]').val('');
    $(this).find('input[name="personnel_ois"]').val('');
});

// AJAX: #add_dccstaffing POST
$('#add_dccstaffing').submit(function(e) {
    e.preventDefault();
    planDcc.setHiddenFields('add');

    const url = 'api/mgt/dcc/post';

    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize().replace(/'/g, '`'),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      PERTII18n.t('plan.successAdded'),
                text:       PERTII18n.t('plan.dccStaffing.added'),
                timer:      3000,
                showConfirmButton: false,
            });

            loadDCCStaffing();
            $('#add_dccstaffingModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  PERTII18n.t('plan.notAdded'),
                text:   PERTII18n.t('plan.dccStaffing.addError'),
            });
        },
    });
});

// edit_dccstaffing Modal
$('#edit_dccstaffingModal').on('show.bs.modal', function(event) {
    const button = $(event.relatedTarget);
    const modal = $(this);

    modal.find('.modal-body #id').val(button.data('id'));
    modal.find('.modal-body #personnel_name').val(button.data('personnel_name'));
    modal.find('.modal-body #personnel_ois').val(button.data('personnel_ois'));

    // Reverse-map position_facility + position_name to dynamic dropdowns
    planDcc.restoreForEdit(
        button.data('position_facility'),
        button.data('position_name')
    );
});

// AJAX: #edit_dccstaffing POST
$('#edit_dccstaffing').submit(function(e) {
    e.preventDefault();
    planDcc.setHiddenFields('edit');

    const url = 'api/mgt/dcc/update';

    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize().replace(/'/g, '`'),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      PERTII18n.t('plan.successEdited'),
                text:       PERTII18n.t('plan.dccStaffing.edited'),
                timer:      3000,
                showConfirmButton: false,
            });

            loadDCCStaffing();
            $('#edit_dccstaffingModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  PERTII18n.t('plan.notEdited'),
                text:   PERTII18n.t('plan.dccStaffing.editError'),
            });
        },
    });
});

// FUNC: deleteDCCStaffing [id:]
function deleteDCCStaffing(id) {
    $.ajax({
        type:   'POST',
        url:    'api/mgt/dcc/delete',
        data:   {id: id},
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      PERTII18n.t('plan.successDeleted'),
                text:       PERTII18n.t('plan.dccStaffing.deleted'),
                timer:      3000,
                showConfirmButton: false,
            });

            loadDCCStaffing();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  PERTII18n.t('plan.notDeleted'),
                text:   PERTII18n.t('plan.dccStaffing.deleteError'),
            });
        },
    });
}

// #addhistoricalModal Show (for DTP)
$('#addhistoricalModal').on('show.bs.modal', function(event) {
    // Init: Date Time Picker
    $(this).find('.modal-body #ah_date').datetimepicker({
        format: 'Y-m-d',
        inline: false,
        timepicker: false,
    });
});

// AJAX: #addhistorical POST
$('#addhistorical').submit(function(e) {
    e.preventDefault();

    const url = 'api/mgt/historical/post';

    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize().replace(/'/g, '`'),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      PERTII18n.t('plan.successAdded'),
                text:       PERTII18n.t('plan.historical.added'),
                timer:      3000,
                showConfirmButton: false,
            });

            loadHistorical();
            $('#addhistoricalModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  PERTII18n.t('plan.notAdded'),
                text:   PERTII18n.t('plan.historical.addError'),
            });
        },
    });
});

// edithistoricalModal Modal
$('#edithistoricalModal').on('show.bs.modal', function(event) {
    const button = $(event.relatedTarget);

    const modal= $(this);

    modal.find('.modal-body #id').val(button.data('id'));
    modal.find('.modal-body #title').val(button.data('title'));
    modal.find('.modal-body #image_url').val(button.data('image_url'));
    modal.find('.modal-body #source_url').val(button.data('source_url'));
    modal.find('.modal-body #summary').html(button.data('summary'));

    // Init: Date Time Picker
    modal.find('.modal-body #eh_date').datetimepicker({
        format: 'Y-m-d',
        inline: false,
        timepicker: false,
        value: button.data('date'),
    });
});

// AJAX: #edithistorical POST
$('#edithistorical').submit(function(e) {
    e.preventDefault();

    const url = 'api/mgt/historical/update';

    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize().replace(/'/g, '`'),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      PERTII18n.t('plan.successEdited'),
                text:       PERTII18n.t('plan.historical.edited'),
                timer:      3000,
                showConfirmButton: false,
            });

            loadHistorical();
            $('#edithistoricalModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  PERTII18n.t('plan.notEdited'),
                text:   PERTII18n.t('plan.historical.editError'),
            });
        },
    });
});

// FUNC: deleteHistorical [id:]
function deleteHistorical(id) {
    $.ajax({
        type:   'POST',
        url:    'api/mgt/historical/delete',
        data:   {id: id},
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      PERTII18n.t('plan.successDeleted'),
                text:       PERTII18n.t('plan.historical.deleted'),
                timer:      3000,
                showConfirmButton: false,
            });

            loadHistorical();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  PERTII18n.t('plan.notDeleted'),
                text:   PERTII18n.t('plan.historical.deleteError'),
            });
        },
    });
}

// #addforecastModal Show (for DTP)
$('#addforecastModal').on('show.bs.modal', function(event) {
    // Init: Date Time Picker
    $(this).find('.modal-body #af_date').datetimepicker({
        format: 'Y-m-d H:i',
        inline: false,
        timepicker: true,
    });
});

// AJAX: #addforecast POST
$('#addforecast').submit(function(e) {
    e.preventDefault();

    const url = 'api/mgt/forecast/post';

    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize().replace(/'/g, '`'),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      PERTII18n.t('plan.successAdded'),
                text:       PERTII18n.t('plan.forecast.added'),
                timer:      3000,
                showConfirmButton: false,
            });

            loadForecast();
            $('#addforecastModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  PERTII18n.t('plan.notAdded'),
                text:   PERTII18n.t('plan.forecast.addError'),
            });
        },
    });
});

// editforecastModal Modal
$('#editforecastModal').on('show.bs.modal', function(event) {
    const button = $(event.relatedTarget);

    const modal= $(this);

    modal.find('.modal-body #id').val(button.data('id'));
    modal.find('.modal-body #title').val(button.data('title'));
    modal.find('.modal-body #image_url').val(button.data('image_url'));
    modal.find('.modal-body #summary').html(button.data('summary'));

    // Init: Date Time Picker
    modal.find('.modal-body #ef_date').datetimepicker({
        format: 'Y-m-d H:i',
        inline: false,
        timepicker: true,
        value: button.data('date'),
    });
});

// AJAX: #edithistorical POST
$('#editforecast').submit(function(e) {
    e.preventDefault();

    const url = 'api/mgt/forecast/update';

    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize().replace(/'/g, '`'),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      PERTII18n.t('plan.successEdited'),
                text:       PERTII18n.t('plan.forecast.edited'),
                timer:      3000,
                showConfirmButton: false,
            });

            loadForecast();
            $('#editforecastModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  PERTII18n.t('plan.notEdited'),
                text:   PERTII18n.t('plan.forecast.editError'),
            });
        },
    });
});

// FUNC: deleteForecast [id:]
function deleteForecast(id) {
    $.ajax({
        type:   'POST',
        url:    'api/mgt/forecast/delete',
        data:   {id: id},
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      PERTII18n.t('plan.successDeleted'),
                text:       PERTII18n.t('plan.forecast.deleted'),
                timer:      3000,
                showConfirmButton: false,
            });

            loadForecast();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  PERTII18n.t('plan.notDeleted'),
                text:   PERTII18n.t('plan.forecast.deleteError'),
            });
        },
    });
}


// AJAX: #addterminalinit POST
$('#addterminalinit').submit(function(e) {
    e.preventDefault();

    const url = 'api/mgt/terminal_inits/post';

    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize().replace(/'/g, '`'),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      PERTII18n.t('plan.successAdded'),
                text:       PERTII18n.t('plan.termInit.added'),
                timer:      3000,
                showConfirmButton: false,
            });

            loadTermInits();
            $('#addterminalinitModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  PERTII18n.t('plan.notAdded'),
                text:   PERTII18n.t('plan.termInit.addError'),
            });
        },
    });
});


// editterminalinit Modal
$('#editterminalinitModal').on('show.bs.modal', function(event) {
    const button = $(event.relatedTarget);

    const modal= $(this);

    modal.find('.modal-body #id').val(button.data('id'));
    modal.find('.modal-body #title').val(button.data('title'));
    modal.find('.modal-body #context').val(button.data('context'));
});

// AJAX: #editterminalinit POST
$('#editterminalinit').submit(function(e) {
    e.preventDefault();

    const url = 'api/mgt/terminal_inits/update';

    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize().replace(/'/g, '`'),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      PERTII18n.t('plan.successEdited'),
                text:       PERTII18n.t('plan.termInit.edited'),
                timer:      3000,
                showConfirmButton: false,
            });

            loadTermInits();
            $('#editterminalinitModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  PERTII18n.t('plan.notEdited'),
                text:   PERTII18n.t('plan.termInit.editError'),
            });
        },
    });
});

// FUNC: deleteTerminalInit [id:]
function deleteTerminalInit(id) {
    $.ajax({
        type:   'POST',
        url:    'api/mgt/terminal_inits/delete',
        data:   {id: id},
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      PERTII18n.t('plan.successDeleted'),
                text:       PERTII18n.t('plan.termInit.deleted'),
                timer:      3000,
                showConfirmButton: false,
            });

            loadTermInits();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  PERTII18n.t('plan.notDeleted'),
                text:   PERTII18n.t('plan.termInit.deleteError'),
            });
        },
    });
}

// AJAX: #addtermstaffing POST
$('#addtermstaffing').submit(function(e) {
    e.preventDefault();

    const url = 'api/mgt/terminal_staffing/post';

    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize().replace(/'/g, '`'),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      PERTII18n.t('plan.successAdded'),
                text:       PERTII18n.t('plan.termStaffing.added'),
                timer:      3000,
                showConfirmButton: false,
            });

            loadTermStaffing();
            $('#addtermstaffingModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  PERTII18n.t('plan.notAdded'),
                text:   PERTII18n.t('plan.termStaffing.addError'),
            });
        },
    });
});


// editterminalinit Modal
$('#edittermstaffingModal').on('show.bs.modal', function(event) {
    const button = $(event.relatedTarget);

    const modal= $(this);

    modal.find('.modal-body #id').val(button.data('id'));
    modal.find('.modal-body #facility_name').val(button.data('facility_name'));
    modal.find('.modal-body #staffing_status').val(button.data('staffing_status')).trigger('change');
    modal.find('.modal-body #staffing_quantity').val(button.data('staffing_quantity'));
    modal.find('.modal-body #comments').val(button.data('comments'));
});

// AJAX: #edittermstaffing POST
$('#edittermstaffing').submit(function(e) {
    e.preventDefault();

    const url = 'api/mgt/terminal_staffing/update';

    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize().replace(/'/g, '`'),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      PERTII18n.t('plan.successEdited'),
                text:       PERTII18n.t('plan.termStaffing.edited'),
                timer:      3000,
                showConfirmButton: false,
            });

            loadTermStaffing();
            $('#edittermstaffingModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  PERTII18n.t('plan.notEdited'),
                text:   PERTII18n.t('plan.termStaffing.editError'),
            });
        },
    });
});

// FUNC: deleteTermStaffing [id:]
function deleteTermStaffing(id) {
    $.ajax({
        type:   'POST',
        url:    'api/mgt/terminal_staffing/delete',
        data:   {id: id},
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      PERTII18n.t('plan.successDeleted'),
                text:       PERTII18n.t('plan.termStaffing.deleted'),
                timer:      3000,
                showConfirmButton: false,
            });

            loadTermStaffing();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  PERTII18n.t('plan.notDeleted'),
                text:   PERTII18n.t('plan.termStaffing.deleteError'),
            });
        },
    });
}

// AJAX: #addconfig POST
$('#addconfig').submit(function(e) {
    e.preventDefault();

    const url = 'api/mgt/configs/post';

    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize().replace(/'/g, '`'),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      PERTII18n.t('plan.successAdded'),
                text:       PERTII18n.t('plan.config.added'),
                timer:      3000,
                showConfirmButton: false,
            });

            loadConfigs();
            $('#addconfigModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  PERTII18n.t('plan.notAdded'),
                text:   PERTII18n.t('plan.config.addError'),
            });
        },
    });
});


// editconfigModal Modal
$('#editconfigModal').on('show.bs.modal', function(event) {
    const button = $(event.relatedTarget);

    const modal = $(this);

    // Reset config picker state
    $('#editconfig_use_adl').prop('checked', false);
    $('#editconfig_picker').hide();
    $('#editconfig_select').empty().append('<option value="">' + PERTII18n.t('plan.config.selectConfig') + '</option>').prop('disabled', true);
    editconfigSelectedConfig = null;

    modal.find('.modal-body #editconfig_id').val(button.data('id'));
    modal.find('.modal-body #editconfig_airport').val(button.data('airport'));
    modal.find('.modal-body #editconfig_weather').val(button.data('weather')).trigger('change');
    modal.find('.modal-body #editconfig_arrive').val(button.data('arrive'));
    modal.find('.modal-body #editconfig_depart').val(button.data('depart'));
    modal.find('.modal-body #editconfig_aar').val(button.data('aar'));
    modal.find('.modal-body #editconfig_adr').val(button.data('adr'));
    modal.find('.modal-body #editconfig_comments').val(button.data('comments'));

    // Pre-fetch configs for the airport (for edit modal)
    const airport = button.data('airport');
    if (airport && airport.length >= 3) {
        fetchAirportConfigs(airport, function(configs) {
            populateConfigDropdown(configs, '#editconfig_select');
        });
    }
});

// AJAX: #editconfig POST
$('#editconfig').submit(function(e) {
    e.preventDefault();

    const url = 'api/mgt/configs/update';

    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize().replace(/'/g, '`'),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      PERTII18n.t('plan.successEdited'),
                text:       PERTII18n.t('plan.config.edited'),
                timer:      3000,
                showConfirmButton: false,
            });

            loadConfigs();
            $('#editconfigModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  PERTII18n.t('plan.notEdited'),
                text:   PERTII18n.t('plan.config.editError'),
            });
        },
    });
});

// FUNC: deleteConfig [id:]
function deleteConfig(id) {
    $.ajax({
        type:   'POST',
        url:    'api/mgt/configs/delete',
        data:   {id: id},
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      PERTII18n.t('plan.successDeleted'),
                text:       PERTII18n.t('plan.config.deleted'),
                timer:      3000,
                showConfirmButton: false,
            });

            loadConfigs();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  PERTII18n.t('plan.notDeleted'),
                text:   PERTII18n.t('plan.config.deleteError'),
            });
        },
    });
}

// FUNC: autoConfig [id:]
function autoConfig(id, aar, adr) {
    $.ajax({
        type:   'POST',
        url:    'api/mgt/configs/fill',
        data:   {id: id, aar: aar, adr: adr},
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      PERTII18n.t('plan.config.successAutofilled'),
                text:       PERTII18n.t('plan.config.autofilled'),
                timer:      3000,
                showConfirmButton: false,
            });

            loadConfigs();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  PERTII18n.t('plan.notDeleted'),
                text:   PERTII18n.t('plan.config.autofillError'),
            });
        },
    });
}

// =====================================================
// ADL Config Picker Functions
// =====================================================

// Cache for loaded configs by airport
const configCache = {};
let addconfigSelectedConfig = null;
let editconfigSelectedConfig = null;

// Fetch configs for airport from ADL
function fetchAirportConfigs(airport, callback) {
    if (!airport || airport.length < 3) {
        callback([]);
        return;
    }

    const cacheKey = airport.toUpperCase();
    if (configCache[cacheKey]) {
        callback(configCache[cacheKey]);
        return;
    }

    $.get('api/demand/config_search.php', { airport: airport })
        .done(function(data) {
            if (data.success && data.configs) {
                configCache[cacheKey] = data.configs;
                callback(data.configs);
            } else {
                callback([]);
            }
        })
        .fail(function() {
            callback([]);
        });
}

// Populate config dropdown with fetched configs
function populateConfigDropdown(configs, selectElement) {
    const $select = $(selectElement);
    $select.empty().append('<option value="">' + PERTII18n.t('plan.config.selectConfig') + '</option>');

    if (configs && configs.length > 0) {
        configs.forEach(function(cfg, idx) {
            let label = cfg.config_name;
            if (cfg.config_code) {
                label += ' (' + cfg.config_code + ')';
            }
            $select.append('<option value="' + idx + '">' + label + '</option>');
        });
        $select.prop('disabled', false);
    } else {
        $select.append('<option value="" disabled>' + PERTII18n.t('plan.config.noConfigsFound') + '</option>');
        $select.prop('disabled', true);
    }
}

// Get rate values based on weather selection
function getRatesFromConfig(config, weatherValue) {
    if (!config || !config.rates) {return { aar: '', adr: '' };}

    const weatherMap = {
        '0': 'vmc',   // Unknown -> default to VMC
        '1': 'vmc',
        '2': 'lvmc',
        '3': 'imc',
        '4': 'limc',
    };

    const key = weatherMap[weatherValue] || 'vmc';
    const rates = config.rates[key] || {};

    return {
        aar: rates.aar !== null ? rates.aar : '',
        adr: rates.adr !== null ? rates.adr : '',
    };
}

// Apply config to form fields
function applyConfigToForm(config, prefix, weatherValue) {
    if (!config) {return;}

    // Set runways (replace slashes with forward slashes for display)
    $('#' + prefix + '_arrive').val(config.arr_runways || '');
    $('#' + prefix + '_depart').val(config.dep_runways || '');

    // Set rates based on weather
    const rates = getRatesFromConfig(config, weatherValue);
    $('#' + prefix + '_aar').val(rates.aar);
    $('#' + prefix + '_adr').val(rates.adr);
}

// =====================================================
// Add Config Modal - Config Picker Events
// =====================================================

// Toggle config picker visibility
$('#addconfig_use_adl').on('change', function() {
    if ($(this).is(':checked')) {
        $('#addconfig_picker').slideDown(200);
        // Trigger config fetch if airport already entered
        const airport = $('#addconfig_airport').val();
        if (airport && airport.length >= 3) {
            fetchAirportConfigs(airport, function(configs) {
                populateConfigDropdown(configs, '#addconfig_select');
            });
        }
    } else {
        $('#addconfig_picker').slideUp(200);
        addconfigSelectedConfig = null;
    }
});

// Fetch configs when airport field changes (Add Config modal)
$('#addconfig_airport').on('blur change', function() {
    const airport = $(this).val();
    if ($('#addconfig_use_adl').is(':checked') && airport && airport.length >= 3) {
        fetchAirportConfigs(airport, function(configs) {
            populateConfigDropdown(configs, '#addconfig_select');
        });
    }
});

// Apply selected config (Add Config modal)
$('#addconfig_select').on('change', function() {
    const idx = $(this).val();
    const airport = $('#addconfig_airport').val().toUpperCase();
    const configs = configCache[airport] || [];

    if (idx !== '' && configs[idx]) {
        addconfigSelectedConfig = configs[idx];
        const weather = $('#addconfig_weather').val();
        applyConfigToForm(addconfigSelectedConfig, 'addconfig', weather);
    } else {
        addconfigSelectedConfig = null;
    }
});

// Update rates when weather changes (Add Config modal)
$('#addconfig_weather').on('change', function() {
    if (addconfigSelectedConfig) {
        const rates = getRatesFromConfig(addconfigSelectedConfig, $(this).val());
        $('#addconfig_aar').val(rates.aar);
        $('#addconfig_adr').val(rates.adr);
    }
});

// Reset Add Config modal on open
$('#addconfigModal').on('show.bs.modal', function() {
    $('#addconfig_use_adl').prop('checked', false);
    $('#addconfig_picker').hide();
    $('#addconfig_select').empty().append('<option value="">' + PERTII18n.t('plan.config.selectConfig') + '</option>').prop('disabled', true);
    addconfigSelectedConfig = null;
});

// =====================================================
// Edit Config Modal - Config Picker Events
// =====================================================

// Toggle config picker visibility (Edit modal)
$('#editconfig_use_adl').on('change', function() {
    if ($(this).is(':checked')) {
        $('#editconfig_picker').slideDown(200);
        // Trigger config fetch
        const airport = $('#editconfig_airport').val();
        if (airport && airport.length >= 3) {
            fetchAirportConfigs(airport, function(configs) {
                populateConfigDropdown(configs, '#editconfig_select');
            });
        }
    } else {
        $('#editconfig_picker').slideUp(200);
        editconfigSelectedConfig = null;
    }
});

// Fetch configs when airport field changes (Edit Config modal)
$('#editconfig_airport').on('blur change', function() {
    const airport = $(this).val();
    if ($('#editconfig_use_adl').is(':checked') && airport && airport.length >= 3) {
        fetchAirportConfigs(airport, function(configs) {
            populateConfigDropdown(configs, '#editconfig_select');
        });
    }
});

// Apply selected config (Edit Config modal)
$('#editconfig_select').on('change', function() {
    const idx = $(this).val();
    const airport = $('#editconfig_airport').val().toUpperCase();
    const configs = configCache[airport] || [];

    if (idx !== '' && configs[idx]) {
        editconfigSelectedConfig = configs[idx];
        const weather = $('#editconfig_weather').val();
        applyConfigToForm(editconfigSelectedConfig, 'editconfig', weather);
    } else {
        editconfigSelectedConfig = null;
    }
});

// Update rates when weather changes (Edit Config modal)
$('#editconfig_weather').on('change', function() {
    if (editconfigSelectedConfig) {
        const rates = getRatesFromConfig(editconfigSelectedConfig, $(this).val());
        $('#editconfig_aar').val(rates.aar);
        $('#editconfig_adr').val(rates.adr);
    }
});

// addtermplanning Modal
$('#addtermplanningModal').on('show.bs.modal', function(event) {
    $('#atp_comments').summernote('code', '');
});

// AJAX: #addtermplanning POST
$('#addtermplanning').submit(function(e) {
    e.preventDefault();

    const url = 'api/mgt/terminal_planning/post';

    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize().replace(/'/g, '`'),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      PERTII18n.t('plan.successAdded'),
                text:       PERTII18n.t('plan.termPlanning.added'),
                timer:      3000,
                showConfirmButton: false,
            });

            loadTermPlanning();
            $('#addtermplanningModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  PERTII18n.t('plan.notAdded'),
                text:   PERTII18n.t('plan.termPlanning.addError'),
            });
        },
    });
});


// edittermplanning Modal
$('#edittermplanningModal').on('show.bs.modal', function(event) {
    const button = $(event.relatedTarget);

    const modal= $(this);

    modal.find('.modal-body #id').val(button.data('id'));
    modal.find('.modal-body #facility_name').val(button.data('facility_name'));
    $('#etp_comments').summernote('code', button.data('comments'));
});

// AJAX: #edittermplanning POST
$('#edittermplanning').submit(function(e) {
    e.preventDefault();

    const url = 'api/mgt/terminal_planning/update';

    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize(),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      PERTII18n.t('plan.successEdited'),
                text:       PERTII18n.t('plan.termPlanning.edited'),
                timer:      3000,
                showConfirmButton: false,
            });

            loadTermPlanning();
            $('#edittermplanningModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  PERTII18n.t('plan.notEdited'),
                text:   PERTII18n.t('plan.termPlanning.editError'),
            });
        },
    });
});

// FUNC: deleteTermPlanning [id:]
function deleteTermPlanning(id) {
    $.ajax({
        type:   'POST',
        url:    'api/mgt/terminal_planning/delete',
        data:   {id: id},
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      PERTII18n.t('plan.successDeleted'),
                text:       PERTII18n.t('plan.termPlanning.deleted'),
                timer:      3000,
                showConfirmButton: false,
            });

            loadTermPlanning();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  PERTII18n.t('plan.notDeleted'),
                text:   PERTII18n.t('plan.termPlanning.deleteError'),
            });
        },
    });
}

// addtermconstraint Modal
$('#addtermconstraintModal').on('show.bs.modal', function(event) {
    // Init: Date Time Picker
    $(this).find('.modal-body #at_date').datetimepicker({
        format: 'Y-m-d',
        inline: false,
        timepicker: false,
    });
});

// AJAX: #addtermconstraint POST
$('#addtermconstraint').submit(function(e) {
    e.preventDefault();

    const url = 'api/mgt/terminal_constraints/post';

    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize().replace(/'/g, '`'),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      PERTII18n.t('plan.successAdded'),
                text:       PERTII18n.t('plan.termConstraint.added'),
                timer:      3000,
                showConfirmButton: false,
            });

            loadTermConstraints();
            $('#addtermconstraintModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  PERTII18n.t('plan.notAdded'),
                text:   PERTII18n.t('plan.termConstraint.addError'),
            });
        },
    });
});


// edittermconstraint Modal
$('#edittermconstraintModal').on('show.bs.modal', function(event) {
    const button = $(event.relatedTarget);

    const modal= $(this);

    modal.find('.modal-body #id').val(button.data('id'));
    modal.find('.modal-body #location').val(button.data('location'));
    modal.find('.modal-body #context').val(button.data('context'));
    modal.find('.modal-body #impact').val(button.data('impact'));

    // Init: Date Time Picker
    $(this).find('.modal-body #et_date').datetimepicker({
        format: 'Y-m-d',
        inline: false,
        timepicker: false,
        value: button.data('date'),
    });
});

// AJAX: #edittermconstraints POST
$('#edittermconstraint').submit(function(e) {
    e.preventDefault();

    const url = 'api/mgt/terminal_constraints/update';

    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize(),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      PERTII18n.t('plan.successEdited'),
                text:       PERTII18n.t('plan.termConstraint.edited'),
                timer:      3000,
                showConfirmButton: false,
            });

            loadTermConstraints();
            $('#edittermconstraintModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  PERTII18n.t('plan.notEdited'),
                text:   PERTII18n.t('plan.termConstraint.editError'),
            });
        },
    });
});

// FUNC: deleteTermPlanning [id:]
function deleteTermConstraint(id) {
    $.ajax({
        type:   'POST',
        url:    'api/mgt/terminal_constraints/delete',
        data:   {id: id},
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      PERTII18n.t('plan.successDeleted'),
                text:       PERTII18n.t('plan.termConstraint.deleted'),
                timer:      3000,
                showConfirmButton: false,
            });

            loadTermConstraints();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  PERTII18n.t('plan.notDeleted'),
                text:   PERTII18n.t('plan.termConstraint.deleteError'),
            });
        },
    });
}


// AJAX: #addenrouteinit POST
$('#addenrouteinit').submit(function(e) {
    e.preventDefault();

    const url = 'api/mgt/enroute_initializations/post';

    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize().replace(/'/g, '`'),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      PERTII18n.t('plan.successAdded'),
                text:       PERTII18n.t('plan.enrouteInit.added'),
                timer:      3000,
                showConfirmButton: false,
            });

            loadEnrouteInits();
            $('#addenrouteinitModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  PERTII18n.t('plan.notAdded'),
                text:   PERTII18n.t('plan.enrouteInit.addError'),
            });
        },
    });
});


// editenrouteinit Modal
$('#editenrouteinitModal').on('show.bs.modal', function(event) {
    const button = $(event.relatedTarget);

    const modal= $(this);

    modal.find('.modal-body #id').val(button.data('id'));
    modal.find('.modal-body #title').val(button.data('title'));
    modal.find('.modal-body #context').val(button.data('context'));
});

// AJAX: #editenrouteinit POST
$('#editenrouteinit').submit(function(e) {
    e.preventDefault();

    const url = 'api/mgt/enroute_initializations/update';

    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize().replace(/'/g, '`'),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      PERTII18n.t('plan.successEdited'),
                text:       PERTII18n.t('plan.enrouteInit.edited'),
                timer:      3000,
                showConfirmButton: false,
            });

            loadEnrouteInits();
            $('#editenrouteinitModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  PERTII18n.t('plan.notEdited'),
                text:   PERTII18n.t('plan.enrouteInit.editError'),
            });
        },
    });
});

// FUNC: deleteEnrouteInit [id:]
function deleteEnrouteInit(id) {
    $.ajax({
        type:   'POST',
        url:    'api/mgt/enroute_initializations/delete',
        data:   {id: id},
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      PERTII18n.t('plan.successDeleted'),
                text:       PERTII18n.t('plan.enrouteInit.deleted'),
                timer:      3000,
                showConfirmButton: false,
            });

            loadEnrouteInits();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  PERTII18n.t('plan.notDeleted'),
                text:   PERTII18n.t('plan.enrouteInit.deleteError'),
            });
        },
    });
}

// AJAX: #addenroutestaffing POST
$('#addenroutestaffing').submit(function(e) {
    e.preventDefault();

    const url = 'api/mgt/enroute_staffing/post';

    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize().replace(/'/g, '`'),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      PERTII18n.t('plan.successAdded'),
                text:       PERTII18n.t('plan.enrouteStaffing.added'),
                timer:      3000,
                showConfirmButton: false,
            });

            loadEnrouteStaffing();
            $('#addenroutestaffingModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  PERTII18n.t('plan.notAdded'),
                text:   PERTII18n.t('plan.enrouteStaffing.addError'),
            });
        },
    });
});


// editenroutestaffing Modal
$('#editenroutestaffingModal').on('show.bs.modal', function(event) {
    const button = $(event.relatedTarget);

    const modal= $(this);

    modal.find('.modal-body #id').val(button.data('id'));
    modal.find('.modal-body #facility_name').val(button.data('facility_name')).trigger('change');
    modal.find('.modal-body #staffing_status').val(button.data('staffing_status')).trigger('change');
    modal.find('.modal-body #staffing_quantity').val(button.data('staffing_quantity'));
    modal.find('.modal-body #comments').val(button.data('comments'));
});

// AJAX: #editenroutestaffing POST
$('#editenroutestaffing').submit(function(e) {
    e.preventDefault();

    const url = 'api/mgt/enroute_staffing/update';

    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize().replace(/'/g, '`'),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      PERTII18n.t('plan.successEdited'),
                text:       PERTII18n.t('plan.enrouteStaffing.edited'),
                timer:      3000,
                showConfirmButton: false,
            });

            loadEnrouteStaffing();
            $('#editenroutestaffingModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  PERTII18n.t('plan.notEdited'),
                text:   PERTII18n.t('plan.enrouteStaffing.editError'),
            });
        },
    });
});

// FUNC: deleteEnrouteStaffing [id:]
function deleteEnrouteStaffing(id) {
    $.ajax({
        type:   'POST',
        url:    'api/mgt/enroute_staffing/delete',
        data:   {id: id},
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      PERTII18n.t('plan.successDeleted'),
                text:       PERTII18n.t('plan.enrouteStaffing.deleted'),
                timer:      3000,
                showConfirmButton: false,
            });

            loadEnrouteStaffing();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  PERTII18n.t('plan.notDeleted'),
                text:   PERTII18n.t('plan.enrouteStaffing.deleteError'),
            });
        },
    });
}

// addenrouteplanning Modal
$('#addenrouteplanningModal').on('show.bs.modal', function(event) {
    $('#aep_comments').summernote('code', '');
});

// AJAX: #addenrouteplanning POST
$('#addenrouteplanning').submit(function(e) {
    e.preventDefault();

    const url = 'api/mgt/enroute_planning/post';

    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize().replace(/'/g, '`'),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      PERTII18n.t('plan.successAdded'),
                text:       PERTII18n.t('plan.enroutePlanning.added'),
                timer:      3000,
                showConfirmButton: false,
            });

            loadEnroutePlanning();
            $('#addenrouteplanningModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  PERTII18n.t('plan.notAdded'),
                text:   PERTII18n.t('plan.enroutePlanning.addError'),
            });
        },
    });
});


// editenrouteplanning Modal
$('#editenrouteplanningModal').on('show.bs.modal', function(event) {
    const button = $(event.relatedTarget);

    const modal= $(this);

    modal.find('.modal-body #id').val(button.data('id'));
    modal.find('.modal-body #facility_name').val(button.data('facility_name')).trigger('change');
    $('#eep_comments').summernote('code', button.data('comments'));
});

// AJAX: #editenrouteplanning POST
$('#editenrouteplanning').submit(function(e) {
    e.preventDefault();

    const url = 'api/mgt/enroute_planning/update';

    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize(),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      PERTII18n.t('plan.successEdited'),
                text:       PERTII18n.t('plan.enroutePlanning.edited'),
                timer:      3000,
                showConfirmButton: false,
            });

            loadEnroutePlanning();
            $('#editenrouteplanningModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  PERTII18n.t('plan.notEdited'),
                text:   PERTII18n.t('plan.enroutePlanning.editError'),
            });
        },
    });
});

// FUNC: deleteEnroutePlanning [id:]
function deleteEnroutePlanning(id) {
    $.ajax({
        type:   'POST',
        url:    'api/mgt/enroute_planning/delete',
        data:   {id: id},
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      PERTII18n.t('plan.successDeleted'),
                text:       PERTII18n.t('plan.enroutePlanning.deleted'),
                timer:      3000,
                showConfirmButton: false,
            });

            loadEnroutePlanning();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  PERTII18n.t('plan.notDeleted'),
                text:   PERTII18n.t('plan.enroutePlanning.deleteError'),
            });
        },
    });
}

// addenrouteconstraint Modal
$('#addenrouteconstraintModal').on('show.bs.modal', function(event) {
    // Init: Date Time Picker
    $(this).find('.modal-body #ae_date').datetimepicker({
        format: 'Y-m-d',
        inline: false,
        timepicker: false,
    });
});

// AJAX: #addenrouteconstraint POST
$('#addenrouteconstraint').submit(function(e) {
    e.preventDefault();

    const url = 'api/mgt/enroute_constraints/post';

    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize().replace(/'/g, '`'),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      PERTII18n.t('plan.successAdded'),
                text:       PERTII18n.t('plan.enrouteConstraint.added'),
                timer:      3000,
                showConfirmButton: false,
            });

            loadEnrouteConstraints();
            $('#addenrouteconstraintModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  PERTII18n.t('plan.notAdded'),
                text:   PERTII18n.t('plan.enrouteConstraint.addError'),
            });
        },
    });
});


// editenrouteconstraint Modal
$('#editenrouteconstraintModal').on('show.bs.modal', function(event) {
    const button = $(event.relatedTarget);

    const modal= $(this);

    modal.find('.modal-body #id').val(button.data('id'));
    modal.find('.modal-body #location').val(button.data('location'));
    modal.find('.modal-body #context').val(button.data('context'));
    modal.find('.modal-body #impact').val(button.data('impact'));

    // Init: Date Time Picker
    $(this).find('.modal-body #ee_date').datetimepicker({
        format: 'Y-m-d',
        inline: false,
        timepicker: false,
        value: button.data('date'),
    });
});

// AJAX: #editenrouteconstraints POST
$('#editenrouteconstraint').submit(function(e) {
    e.preventDefault();

    const url = 'api/mgt/enroute_constraints/update';

    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize(),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      PERTII18n.t('plan.successEdited'),
                text:       PERTII18n.t('plan.enrouteConstraint.edited'),
                timer:      3000,
                showConfirmButton: false,
            });

            loadEnrouteConstraints();
            $('#editenrouteconstraintModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  PERTII18n.t('plan.notEdited'),
                text:   PERTII18n.t('plan.enrouteConstraint.editError'),
            });
        },
    });
});

// FUNC: deleteEnroutePlanning [id:]
function deleteEnrouteConstraint(id) {
    $.ajax({
        type:   'POST',
        url:    'api/mgt/enroute_constraints/delete',
        data:   {id: id},
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      PERTII18n.t('plan.successDeleted'),
                text:       PERTII18n.t('plan.enrouteConstraint.deleted'),
                timer:      3000,
                showConfirmButton: false,
            });

            loadEnrouteConstraints();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  PERTII18n.t('plan.notDeleted'),
                text:   PERTII18n.t('plan.enrouteConstraint.deleteError'),
            });
        },
    });
}

// AJAX: #addgroupflight POST
$('#addgroupflight').submit(function(e) {
    e.preventDefault();

    const url = 'api/mgt/group_flights/post';

    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize().replace(/'/g, '`'),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      PERTII18n.t('plan.successAdded'),
                text:       PERTII18n.t('plan.groupFlight.added'),
                timer:      3000,
                showConfirmButton: false,
            });

            loadGroupFlights();
            $('#addgroupflightModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  PERTII18n.t('plan.notAdded'),
                text:   PERTII18n.t('plan.groupFlight.addError'),
            });
        },
    });
});


// editgroupflight Modal
$('#editgroupflightModal').on('show.bs.modal', function(event) {
    const button = $(event.relatedTarget);

    const modal= $(this);

    modal.find('.modal-body #id').val(button.data('id'));
    modal.find('.modal-body #entity').val(button.data('entity'));
    modal.find('.modal-body #dep').val(button.data('dep'));
    modal.find('.modal-body #arr').val(button.data('arr'));
    modal.find('.modal-body #etd').val(button.data('etd'));
    modal.find('.modal-body #eta').val(button.data('eta'));
    modal.find('.modal-body #pilot_quantity').val(button.data('pilot_quantity'));
    modal.find('.modal-body #route').val(button.data('route'));
});

// AJAX: #editgroupflight POST
$('#editgroupflight').submit(function(e) {
    e.preventDefault();

    const url = 'api/mgt/group_flights/update';

    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize(),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      PERTII18n.t('plan.successEdited'),
                text:       PERTII18n.t('plan.groupFlight.edited'),
                timer:      3000,
                showConfirmButton: false,
            });

            loadGroupFlights();
            $('#editgroupflightModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  PERTII18n.t('plan.notEdited'),
                text:   PERTII18n.t('plan.groupFlight.editError'),
            });
        },
    });
});

// FUNC: deleteGroupFlight [id:]
function deleteGroupFlight(id) {
    $.ajax({
        type:   'POST',
        url:    'api/mgt/group_flights/delete',
        data:   {id: id},
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      PERTII18n.t('plan.successDeleted'),
                text:       PERTII18n.t('plan.groupFlight.deleted'),
                timer:      3000,
                showConfirmButton: false,
            });

            loadGroupFlights();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  PERTII18n.t('plan.notDeleted'),
                text:   PERTII18n.t('plan.groupFlight.deleteError'),
            });
        },
    });
}

// === Initiative selection UI: single-click for Terminal & Enroute ===

// Terminal helpers
function termInitDeleteAjax(id) {
    $.ajax({
        type:   'POST',
        url:    'api/mgt/terminal_inits/times/delete',
        data:   {id: id},
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      PERTII18n.t('plan.successDeleted'),
                text:       PERTII18n.t('plan.termInitTime.deleted'),
                timer:      3000,
                showConfirmButton: false,
            });

            loadTermInits();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  PERTII18n.t('plan.notDeleted'),
                text:   PERTII18n.t('plan.termInitTime.deleteError'),
            });
        },
    });
}

function termInitStateDialog(options) {
    const mode        = options.mode; // 'create' or 'update'
    const init_id     = options.init_id || null;
    const time        = options.time || null;
    const id          = options.id || null;
    const currentProb = (typeof options.currentProb !== 'undefined') ? options.currentProb : null;

    let currentVal = '';
    if (currentProb !== null && typeof currentProb !== 'undefined') {
        const p = parseInt(currentProb, 10);
        if (!isNaN(p)) {
            currentVal = (p <= 3 ? p.toString() : '4');
        }
    }

    Swal.fire({
        title: PERTII18n.t('plan.initDialog.updateTerminal'),
        input: 'select',
        inputOptions: {
            '':  PERTII18n.t('plan.initDialog.clearNoInit'),
            '0': PERTII18n.t('plan.initDialog.criticalDecisionWindow'),
            '1': PERTII18n.t('plan.initDialog.possible'),
            '2': PERTII18n.t('plan.initDialog.probable'),
            '3': PERTII18n.t('plan.initDialog.expected'),
            '4': PERTII18n.t('plan.initDialog.actual'),
        },
        inputValue: currentVal,
        inputPlaceholder: PERTII18n.t('plan.initDialog.selectState'),
        showCancelButton: true,
        confirmButtonText: PERTII18n.t('common.save'),
    }).then(function(result) {
        if (!result.isConfirmed) {
            return;
        }

        const value = result.value;

        // Clear
        if (value === '' || value === null) {
            if (mode === 'update' && id) {
                termInitDeleteAjax(id);
            }
            // if create + clear, do nothing
            return;
        }

        if (mode === 'create') {
            // Create new at chosen state via existing post endpoint, extended to accept probability
            $.ajax({
                type:   'POST',
                url:    'api/mgt/terminal_inits/times/post',
                data:   {init_id: init_id, time: time, probability: value},
                success:function(data) {
                    Swal.fire({
                        toast:      true,
                        position:   'bottom-right',
                        icon:       'success',
                        title:      PERTII18n.t('plan.successCreated'),
                        text:       PERTII18n.t('plan.termInitTime.created'),
                        timer:      3000,
                        showConfirmButton: false,
                    });

                    loadTermInits();
                },
                error:function(data) {
                    Swal.fire({
                        icon:   'error',
                        title:  PERTII18n.t('plan.notCreated'),
                        text:   PERTII18n.t('plan.termInitTime.createError'),
                    });
                },
            });
        } else if (mode === 'update' && id) {
            // Update existing state via modified update endpoint (absolute probability)
            $.ajax({
                type:   'POST',
                url:    'api/mgt/terminal_inits/times/update',
                data:   {id: id, probability: value},
                success:function(data) {
                    Swal.fire({
                        toast:      true,
                        position:   'bottom-right',
                        icon:       'success',
                        title:      PERTII18n.t('plan.successUpdated'),
                        text:       PERTII18n.t('plan.termInitTime.updated'),
                        timer:      3000,
                        showConfirmButton: false,
                    });

                    loadTermInits();
                },
                error:function(data) {
                    Swal.fire({
                        icon:   'error',
                        title:  PERTII18n.t('plan.notUpdated'),
                        text:   PERTII18n.t('plan.termInitTime.updateError'),
                    });
                },
            });
        }
    });
}

// Override: any click on a Terminal box opens the picker
function createTermTime(init_id, time) {
    termInitStateDialog({mode: 'create', init_id: init_id, time: time});
}

function changeTermTime(id, prob) {
    termInitStateDialog({mode: 'update', id: id, currentProb: prob});
}

function deleteTermTime(id) {
    // Treat Actual as an update with currentProb 4 so user can change/clear
    termInitStateDialog({mode: 'update', id: id, currentProb: 4});
}

// Enroute helpers
function enrouteInitDeleteAjax(id) {
    $.ajax({
        type:   'POST',
        url:    'api/mgt/enroute_initializations/times/delete',
        data:   {id: id},
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      PERTII18n.t('plan.successDeleted'),
                text:       PERTII18n.t('plan.enrouteInitTime.deleted'),
                timer:      3000,
                showConfirmButton: false,
            });

            loadEnrouteInits();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  PERTII18n.t('plan.notDeleted'),
                text:   PERTII18n.t('plan.enrouteInitTime.deleteError'),
            });
        },
    });
}

function enrouteInitStateDialog(options) {
    const mode        = options.mode;
    const init_id     = options.init_id || null;
    const time        = options.time || null;
    const id          = options.id || null;
    const currentProb = (typeof options.currentProb !== 'undefined') ? options.currentProb : null;

    let currentVal = '';
    if (currentProb !== null && typeof currentProb !== 'undefined') {
        const p = parseInt(currentProb, 10);
        if (!isNaN(p)) {
            currentVal = (p <= 3 ? p.toString() : '4');
        }
    }

    Swal.fire({
        title: PERTII18n.t('plan.initDialog.updateEnroute'),
        input: 'select',
        inputOptions: {
            '':  PERTII18n.t('plan.initDialog.clearNoInit'),
            '0': PERTII18n.t('plan.initDialog.criticalDecisionWindow'),
            '1': PERTII18n.t('plan.initDialog.possible'),
            '2': PERTII18n.t('plan.initDialog.probable'),
            '3': PERTII18n.t('plan.initDialog.expected'),
            '4': PERTII18n.t('plan.initDialog.actual'),
        },
        inputValue: currentVal,
        inputPlaceholder: PERTII18n.t('plan.initDialog.selectState'),
        showCancelButton: true,
        confirmButtonText: PERTII18n.t('common.save'),
    }).then(function(result) {
        if (!result.isConfirmed) {
            return;
        }

        const value = result.value;

        if (value === '' || value === null) {
            if (mode === 'update' && id) {
                enrouteInitDeleteAjax(id);
            }
            return;
        }

        if (mode === 'create') {
            $.ajax({
                type:   'POST',
                url:    'api/mgt/enroute_initializations/times/post',
                data:   {init_id: init_id, time: time, probability: value},
                success:function(data) {
                    Swal.fire({
                        toast:      true,
                        position:   'bottom-right',
                        icon:       'success',
                        title:      PERTII18n.t('plan.successCreated'),
                        text:       PERTII18n.t('plan.enrouteInitTime.created'),
                        timer:      3000,
                        showConfirmButton: false,
                    });

                    loadEnrouteInits();
                },
                error:function(data) {
                    Swal.fire({
                        icon:   'error',
                        title:  PERTII18n.t('plan.notCreated'),
                        text:   PERTII18n.t('plan.enrouteInitTime.createError'),
                    });
                },
            });
        } else if (mode === 'update' && id) {
            $.ajax({
                type:   'POST',
                url:    'api/mgt/enroute_initializations/times/update',
                data:   {id: id, probability: value},
                success:function(data) {
                    Swal.fire({
                        toast:      true,
                        position:   'bottom-right',
                        icon:       'success',
                        title:      PERTII18n.t('plan.successUpdated'),
                        text:       PERTII18n.t('plan.enrouteInitTime.updated'),
                        timer:      3000,
                        showConfirmButton: false,
                    });

                    loadEnrouteInits();
                },
                error:function(data) {
                    Swal.fire({
                        icon:   'error',
                        title:  PERTII18n.t('plan.notUpdated'),
                        text:   PERTII18n.t('plan.enrouteInitTime.updateError'),
                    });
                },
            });
        }
    });
}

function createEnrouteTime(init_id, time) {
    enrouteInitStateDialog({mode: 'create', init_id: init_id, time: time});
}

function changeEnrouteTime(id, prob) {
    enrouteInitStateDialog({mode: 'update', id: id, currentProb: prob});
}

function deleteEnrouteTime(id) {
    enrouteInitStateDialog({mode: 'update', id: id, currentProb: 4});
}


// ===============================
// PERTI Discord Notification
// ===============================

// Facilities used in the selector - uses PERTI namespace > FacilityHierarchy > hardcoded fallback
const ADV_US_FACILITY_CODES = (typeof PERTI !== 'undefined' && PERTI.FACILITY && PERTI.FACILITY.FACILITY_LISTS && PERTI.FACILITY.FACILITY_LISTS.ARTCC_CONUS)
    ? PERTI.FACILITY.FACILITY_LISTS.ARTCC_CONUS
    : (typeof FacilityHierarchy !== 'undefined' && FacilityHierarchy.FACILITY_GROUPS)
        ? FacilityHierarchy.FACILITY_GROUPS.US_CONUS.artccs
        : ['ZAB','ZAN','ZAU','ZBW','ZDC','ZDV','ZFW','ZHN','ZHU','ZID','ZJX','ZKC',
           'ZLA','ZLC','ZMA','ZME','ZMP','ZNY','ZOA','ZOB','ZSE','ZTL'];

// Extended list includes Canadian FIRs (short codes) and international references
const ADV_FACILITY_CODES = [
    ...ADV_US_FACILITY_CODES,
    'CZE','CZM','CZU','CZV','CZW','CZY',  // Canadian FIR short codes
    'ZEU','ZMX','CAR',                     // International references
];

function pertiParseZuluTime(timeStr) {
    if (!timeStr) {return null;}
    timeStr = ('' + timeStr).trim();
    if (!timeStr) {return null;}

    if (timeStr.length === 3) {timeStr = '0' + timeStr;}
    if (timeStr.length !== 4) {return null;}

    const hh = parseInt(timeStr.slice(0, 2), 10);
    const mm = parseInt(timeStr.slice(2, 4), 10);
    if (isNaN(hh) || isNaN(mm)) {return null;}
    if (hh < 0 || hh > 23 || mm < 0 || mm > 59) {return null;}

    return { hh: hh, mm: mm };
}

function pertiDefaultEndDate(startDateStr, startTimeStr) {
    if (!startDateStr) {return '';}
    const t = pertiParseZuluTime(startTimeStr);
    if (!t) {return startDateStr;}

    const parts = startDateStr.split('-');
    if (parts.length !== 3) {return startDateStr;}
    const y = parseInt(parts[0], 10);
    const m = parseInt(parts[1], 10);
    const d = parseInt(parts[2], 10);
    if (isNaN(y) || isNaN(m) || isNaN(d)) {return startDateStr;}

    const dt = new Date(Date.UTC(y, m - 1, d, 0, 0, 0));

    const stVal = t.hh * 100 + t.mm;
    if (stVal >= 1800) {
        dt.setUTCDate(dt.getUTCDate() + 1);
    }

    const yy = dt.getUTCFullYear();
    const mm = (dt.getUTCMonth() + 1).toString().padStart(2, '0');
    const dd = dt.getUTCDate().toString().padStart(2, '0');
    return yy + '-' + mm + '-' + dd;
}


function opsPlanFormatMmDdYyyy(isoDate) {
    if (!isoDate) {return '';}
    const parts = isoDate.split('-');
    if (parts.length !== 3) {return isoDate;}
    return parts[1] + '/' + parts[2] + '/' + parts[0];
}

function opsPlanGetDayFromIso(isoDate) {
    if (!isoDate) {return '__';}
    const parts = isoDate.split('-');
    if (parts.length !== 3) {return '__';}
    return parts[2];
}

function opsPlanUpper(str) {
    if (str === undefined || str === null) {return '';}
    return String(str).toUpperCase();
}

function opsPlanLabelFromProbabilityTitle(title) {
    if (!title) {return null;}
    const t = String(title).toLowerCase().trim();

    // Ignore toggle-helper titles for both terminal and en route; treat them as "no probability"
    if (t.indexOf('toggle') === 0 ||
        t.indexOf('toggle terminal initiative') !== -1 ||
        t.indexOf('toggle enroute initiative') !== -1 ||
        t.indexOf('toggle en route initiative') !== -1 ||
        t.indexOf('toggle initiative') !== -1) {
        return null;
    }

    // Normalize common probability phrases
    if (t.indexOf('possible') !== -1) {return 'POSSIBLE';}
    if (t.indexOf('probable') !== -1) {return 'PROBABLE';}
    if (t.indexOf('expected') !== -1) {return 'EXPECTED';}

    // Handle both correct and mis-spelled "CRITICAL" CDW labels
    if (t.indexOf('critical decision window') !== -1 ||
        t.indexOf('criticial decision window') !== -1 ||
        t.indexOf('cdw') !== -1) {
        return 'CRITICAL DECISION WINDOW';
    }

    // Fallback: uppercase whatever came in
    return opsPlanUpper(title);
}

function pertiComputeDiscordTimestamps(startDateStr, startTimeStr, endDateStr, endTimeStr) {
    const st = pertiParseZuluTime(startTimeStr);
    const et = pertiParseZuluTime(endTimeStr);
    if (!startDateStr || !st || !endDateStr || !et) {return null;}

    const sParts = startDateStr.split('-');
    const eParts = endDateStr.split('-');
    if (sParts.length !== 3 || eParts.length !== 3) {return null;}

    const sy = parseInt(sParts[0], 10);
    const sm = parseInt(sParts[1], 10);
    const sd = parseInt(sParts[2], 10);
    const ey = parseInt(eParts[0], 10);
    const em = parseInt(eParts[1], 10);
    const ed = parseInt(eParts[2], 10);
    if ([sy, sm, sd, ey, em, ed].some(isNaN)) {return null;}

    const startTs = Math.floor(Date.UTC(sy, sm - 1, sd, st.hh, st.mm, 0) / 1000);
    const endTs   = Math.floor(Date.UTC(ey, em - 1, ed, et.hh, et.mm, 0) / 1000);

    return { startTs: startTs, endTs: endTs };
}

function advInitFacilitiesDropdown() {
    const $grid = $('#advFacilitiesGrid');
    if (!$grid.length) {return;}

    // Build checkbox grid
    $grid.empty();
    ADV_FACILITY_CODES.forEach(function(code) {
        const id = 'advFacility_' + code;
        const $check = $('<input>')
            .attr('type', 'checkbox')
            .addClass('form-check-input')
            .attr('id', id)
            .attr('data-code', code)
            .val(code);

        const $label = $('<label>')
            .addClass('form-check-label')
            .attr('for', id)
            .text(code);

        const $wrapper = $('<div>').addClass('form-check');
        $wrapper.append($check).append($label);
        $grid.append($wrapper);
    });

    $('#advFacilitiesToggle').off('click').on('click', function(e) {
        e.stopPropagation();
        $('#advFacilitiesDropdown').toggle();
    });

    $('#advFacilitiesAll').off('click').on('click', function(e) {
        e.stopPropagation();
        $('#advFacilitiesGrid input[type="checkbox"]').prop('checked', true);
    });

    $('#advFacilitiesUS').off('click').on('click', function(e) {
        e.stopPropagation();
        $('#advFacilitiesGrid input[type="checkbox"]').each(function() {
            const code = ($(this).attr('data-code') || '').toString().toUpperCase();
            if (ADV_US_FACILITY_CODES.indexOf(code) !== -1) {
                $(this).prop('checked', true);
            } else {
                $(this).prop('checked', false);
            }
        });
    });

    $('#advFacilitiesApply').off('click').on('click', function(e) {
        e.stopPropagation();
        const selected = [];

        $('#advFacilitiesGrid input[type="checkbox"]:checked').each(function() {
            const code = ($(this).attr('data-code') || '').toString().toUpperCase();
            if (code) {selected.push(code);}
        });

        selected.sort();
        $('#advFacilities').val(selected.join('/'));
        $('#advFacilitiesDropdown').hide();

        // Update PERTI notification text when facilities are applied
        if (typeof pertiUpdateMessage === 'function') {
            pertiUpdateMessage();
        }
    });

    $('#advFacilitiesClear').off('click').on('click', function(e) {
        e.stopPropagation();
        $('#advFacilitiesGrid input[type="checkbox"]').prop('checked', false);
        $('#advFacilities').val('');
        $('#advFacilitiesDropdown').hide();

        // Update PERTI notification text when facilities are cleared
        if (typeof pertiUpdateMessage === 'function') {
            pertiUpdateMessage();
        }
    });

    $(document).off('click.advFacilities').on('click.advFacilities', function(e) {
        const $wrap = $('.adv-facilities-wrapper');
        if (!$wrap.length) {return;}
        if (!$wrap.is(e.target) && $wrap.has(e.target).length === 0) {
            $('#advFacilitiesDropdown').hide();
        }
    });
}


function opsPlanSortTimeLines(lines) {
    if (!Array.isArray(lines) || lines.length <= 2) {return;}

    // Do not sort if section is effectively empty (header + NONE)
    if (lines.length === 2 && lines[1] === 'NONE') {return;}

    const header = lines[0];
    const rest = lines.slice(1);

    function getKey(line) {
        const match = line.match(/^(UNTIL|AFTER)\s+(\d{4})/);
        if (!match) {
            return { type: 2, time: 9999 }; // push non-timed lines to bottom
        }
        const type = (match[1] === 'UNTIL') ? 0 : 1; // UNTIL before AFTER
        let time = parseInt(match[2], 10);
        if (isNaN(time)) {time = 9999;}
        return { type: type, time: time };
    }

    rest.sort(function(a, b) {
        const ka = getKey(a);
        const kb = getKey(b);
        if (ka.type !== kb.type) {return ka.type - kb.type;}
        return ka.time - kb.time;
    });

    lines.length = 0;
    lines.push(header);
    Array.prototype.push.apply(lines, rest);
}


function opsPlanWrapSingleLine68(line) {
    const maxLen = 68;
    if (line == null) {return [''];}
    line = String(line);
    if (line.length <= maxLen) {return [line];}

    const result = [];

    // Detect a "list" prefix we want to preserve exactly (for hanging indent)
    let prefix = '';
    let rest = line;

    // Pattern for UNTIL/AFTER initiative lines: capture the full prefix including spaces and dash
    const m = line.match(/^(UNTIL|AFTER)\s+\d{4}\s+-\s*/);
    if (m) {
        prefix = m[0]; // exact characters, including spacing
        rest = line.substring(prefix.length);
    } else {
        // Generic "{something} - " style line
        const m2 = line.match(/^(\s*[^-]+-\s*)/);
        if (m2) {
            prefix = m2[1];
            rest = line.substring(prefix.length);
        }
    }

    let indentStr = '';
    if (prefix) {
        indentStr = ''.padStart(prefix.length, ' ');
    }

    // Split only the "rest" text into words; keep prefix as-is
    const words = rest.split(/\s+/);
    let idx = 0;
    let firstLine = true;

    while (idx < words.length) {
        let currentPrefix = firstLine ? prefix : indentStr;
        let avail = maxLen - currentPrefix.length;
        if (avail <= 0) {
            // Degenerate case: prefix itself exceeds max; hard-break prefix
            result.push(currentPrefix.substring(0, maxLen));
            currentPrefix = indentStr;
            avail = maxLen - currentPrefix.length;
        }

        let current = '';
        while (idx < words.length) {
            const w = words[idx];
            if (!w) {
                idx++;
                continue;
            }
            if (!current) {
                if (w.length > avail) {
                    // Word longer than remaining space; hard-break the word
                    result.push(currentPrefix + w.substring(0, avail));
                    words[idx] = w.substring(avail);
                    firstLine = false;
                    current = '';
                    currentPrefix = indentStr;
                    avail = maxLen - currentPrefix.length;
                    continue;
                } else {
                    current = w;
                    idx++;
                }
            } else {
                if (current.length + 1 + w.length <= avail) {
                    current += ' ' + w;
                    idx++;
                } else {
                    break;
                }
            }
        }

        if (current) {
            result.push(currentPrefix + current);
        }

        firstLine = false;
    }

    return result;
}
function opsPlanWrapLines68(lines) {
    if (!Array.isArray(lines)) {return lines;}
    const out = [];
    for (let i = 0; i < lines.length; i++) {
        const pieces = opsPlanWrapSingleLine68(lines[i]);
        for (let j = 0; j < pieces.length; j++) {
            out.push(pieces[j]);
        }
    }
    lines.length = 0;
    Array.prototype.push.apply(lines, out);
    return lines;
}

function opsPlanUpdateMessage() {
    if (typeof PERTI_EVENT_NAME === 'undefined') {return;}

    const advNum    = ($('#opsAdvNum').val()    || '').trim();
    const advDate   = ($('#opsAdvDate').val()   || '').trim();
    const narrative = ($('#opsNarrative').val() || '').trim();

    // Event timing: prefer Ops Plan-specific inputs, then PERTI fields, then defaults
    let startDate = ($('#opsStartDate').val()  || '').trim();
    let startTime = ($('#opsStartTime').val()  || '').trim();
    let endDate   = ($('#opsEndDate').val()    || '').trim();
    const endTime   = ($('#opsEndTime').val()    || '').trim();

    if (!startDate) {
        startDate = ($('#pertiStartDate').val() || '').trim();
    }
    if (!startTime) {
        startTime = ($('#pertiStartTime').val() || '').trim();
    }
    if (!endDate) {
        endDate = ($('#pertiEndDate').val() || '').trim();
    }
    if (!endDate && startDate && typeof pertiDefaultEndDate === 'function') {
        endDate = pertiDefaultEndDate(startDate, startTime);
    }
    if (!startDate && typeof PERTI_EVENT_DATE !== 'undefined' && PERTI_EVENT_DATE) {
        startDate = PERTI_EVENT_DATE;
    }
    if (!startTime && typeof PERTI_EVENT_START !== 'undefined' && PERTI_EVENT_START) {
        startTime = PERTI_EVENT_START;
    }

    const startDay = opsPlanGetDayFromIso(startDate);
    const endDay   = opsPlanGetDayFromIso(endDate);

    const headerAdvNum = advNum || '___';
    let headerDate   = advDate;
    if (!headerDate) {
        if (startDate) {
            headerDate = opsPlanFormatMmDdYyyy(startDate);
        } else if (typeof PERTI_EVENT_DATE !== 'undefined' && PERTI_EVENT_DATE) {
            headerDate = opsPlanFormatMmDdYyyy(PERTI_EVENT_DATE);
        } else {
            headerDate = 'mm/dd/yyyy';
        }
    }

    // HEADER + NARRATIVE (no blank lines around narrative)
    const headerLines = [];
    headerLines.push(AdvisoryConfig.getPrefix() + ' ADVZY ' + opsPlanUpper(headerAdvNum) + ' ' + AdvisoryConfig.getFacility() + ' ' + headerDate + ' OPERATIONS PLAN');
    headerLines.push('EVENT TIME: ' + opsPlanUpper((startDay || '__') + '/' + (startTime || '____') + ' - ' + (endDay || '__') + '/' + (endTime || '____')));
    headerLines.push('____________________________________________________________________');
    headerLines.push(opsPlanUpper(narrative || '[Add narrative here]'));
    headerLines.push('____________________________________________________________________');

    // STAFFING
    const staffingLines = [];
    staffingLines.push('STAFFING:');

    const nomNames = [];
    const ntmoNames = [];

    $('#dcc_table tr').each(function() {
        const $tds = $(this).find('td');
        if (!$tds.length) {return;}
        const ois  = ($tds.eq(0).text() || '').trim().toUpperCase();
        const name = ($tds.eq(1).text() || '').trim();
        const pos  = ($tds.eq(2).text() || '').trim();
        if (!ois || !name) {return;}

        let label = name;
        if (pos) {
            label += ' (' + pos + ')';
        }
        if (ois.indexOf('NOM') !== -1) {
            nomNames.push(label);
        }
        if (ois.indexOf('NTMO') !== -1) {
            ntmoNames.push(label);
        }
    });

    if (nomNames.length) {
        staffingLines.push('NOM           - ' + opsPlanUpper(nomNames.join(', ')));
    }
    if (ntmoNames.length) {
        staffingLines.push('NTMO          - ' + opsPlanUpper(ntmoNames.join(', ')));
    }

    $('#dcc_staffing_table tr').each(function() {
        const $tds = $(this).find('td');
        if ($tds.length < 3) {return;}
        const fac  = ($tds.eq(0).text() || '').trim();
        const ois  = ($tds.eq(1).text() || '').trim();
        const name = ($tds.eq(2).text() || '').trim();
        if (!fac && !name) {return;}

        let line = '';
        if (fac) {line += opsPlanUpper(fac) + ' - ';}
        if (name) {line += opsPlanUpper(name);}
        if (ois) {line += ' [' + opsPlanUpper(ois) + ']';}
        if ($.trim(line).length) {
            staffingLines.push(line);
        }
    });

    if (staffingLines.length === 1) {
        staffingLines.push(PERTII18n.t('plan.opsPlan.none'));
    }

    // TERMINAL CONSTRAINTS (from timeline)
    const termConstraintLines = [];
    termConstraintLines.push(PERTII18n.t('plan.opsPlan.terminalConstraints'));

    // Get terminal constraints from timeline
    const termTimelineData = (window.termInitTimeline && window.termInitTimeline.data) ? window.termInitTimeline.data : [];
    const termConstraints = termTimelineData.filter(function(item) {
        return item.level === 'Constraint_Terminal';
    });

    termConstraints.sort(function(a, b) {
        return (a.start_datetime || '').localeCompare(b.start_datetime || '');
    });

    termConstraints.forEach(function(item) {
        const startTime = opsPlanFormatTmiTime(item.start_datetime);
        const endTime = opsPlanFormatTmiTime(item.end_datetime);
        const loc = opsPlanUpper(item.facility || '');
        let cause = item.tmi_type || '';
        if (cause === 'Other' && item.tmi_type_other) {
            cause = item.tmi_type_other;
        }
        const impact = item.cause || '';

        let line = startTime + '-' + endTime + ' ' + loc;
        if (cause) {line += ' ' + opsPlanUpper(cause);}
        if (impact) {line += ' (' + opsPlanUpper(impact) + ')';}
        termConstraintLines.push(line);
    });

    if (termConstraintLines.length === 1) {
        termConstraintLines.push(PERTII18n.t('plan.opsPlan.none'));
    }

    // EN ROUTE CONSTRAINTS (from timeline)
    const enrouteConstraintLines = [];
    enrouteConstraintLines.push(PERTII18n.t('plan.opsPlan.enRouteConstraints'));

    // Get enroute constraints from timeline
    const enrouteTimelineData = (window.enrouteInitTimeline && window.enrouteInitTimeline.data) ? window.enrouteInitTimeline.data : [];
    const enrouteConstraints = enrouteTimelineData.filter(function(item) {
        return item.level === 'Constraint_EnRoute';
    });

    enrouteConstraints.sort(function(a, b) {
        return (a.start_datetime || '').localeCompare(b.start_datetime || '');
    });

    enrouteConstraints.forEach(function(item) {
        const startTime = opsPlanFormatTmiTime(item.start_datetime);
        const endTime = opsPlanFormatTmiTime(item.end_datetime);
        const loc = opsPlanUpper(item.facility || '');
        let cause = item.tmi_type || '';
        if (cause === 'Other' && item.tmi_type_other) {
            cause = item.tmi_type_other;
        }
        const impact = item.cause || '';

        let line = startTime + '-' + endTime + ' ' + loc;
        if (cause) {line += ' ' + opsPlanUpper(cause);}
        if (impact) {line += ' (' + opsPlanUpper(impact) + ')';}
        enrouteConstraintLines.push(line);
    });

    if (enrouteConstraintLines.length === 1) {
        enrouteConstraintLines.push(PERTII18n.t('plan.opsPlan.none'));
    }

    // Helper: Format datetime from MySQL/ISO format to DD/HHMM format
    function opsPlanFormatTmiTime(datetime) {
        if (!datetime) {return '__/____';}
        const dtStr = String(datetime).trim();
        const match = dtStr.match(/(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/);
        if (!match) {return '__/____';}
        const day = match[3];
        const hour = match[4];
        const minute = match[5];
        return day + '/' + hour + minute;
    }

    // Helper: Build TMI description string
    function opsPlanBuildTmiDesc(item) {
        const parts = [];
        parts.push(opsPlanUpper(item.facility || ''));

        // TMI type
        let tmiType = item.tmi_type || '';
        if (tmiType === 'Other' && item.tmi_type_other) {
            tmiType = item.tmi_type_other;
        }
        if (tmiType) {
            parts.push(opsPlanUpper(tmiType));
        }

        // Area if present
        if (item.area) {
            parts.push(opsPlanUpper(item.area));
        }

        let desc = parts.join(' ');

        return desc;
    }

    // Multi-key comparator: start time, probability (Active>Expected>Probable>Possible>CDW), cause, facility, end time
    var opsPlanProbOrder = {Active: 0, Expected: 1, Probable: 2, Possible: 3, CDW: 4};
    function opsPlanCompareTimeline(a, b) {
        var cmp = (a.start_datetime || '').localeCompare(b.start_datetime || '');
        if (cmp !== 0) return cmp;
        var pa = opsPlanProbOrder[a.level] !== undefined ? opsPlanProbOrder[a.level] : 5;
        var pb = opsPlanProbOrder[b.level] !== undefined ? opsPlanProbOrder[b.level] : 5;
        if (pa !== pb) return pa - pb;
        cmp = (a.cause || '').toUpperCase().localeCompare((b.cause || '').toUpperCase());
        if (cmp !== 0) return cmp;
        cmp = (a.facility || '').toUpperCase().localeCompare((b.facility || '').toUpperCase());
        if (cmp !== 0) return cmp;
        return (a.end_datetime || '').localeCompare(b.end_datetime || '');
    }

    // Helper: Build TMI lines from timeline data
    function buildTmiLinesFromTimeline(timelineData, levelFilter) {
        const result = [];
        if (!timelineData || !Array.isArray(timelineData)) {return result;}

        const filtered = timelineData.filter(function(item) {
            return levelFilter.indexOf(item.level) !== -1;
        });

        // Sort by: start time, probability (Active>Expected>Probable>Possible>CDW), cause, facility, end time
        filtered.sort(opsPlanCompareTimeline);

        filtered.forEach(function(item) {
            const startTime = opsPlanFormatTmiTime(item.start_datetime);
            const endTime = opsPlanFormatTmiTime(item.end_datetime);
            const desc = opsPlanBuildTmiDesc(item);

            // Probability label before cause
            let probLabel = '';
            if (item.level === 'Possible') {probLabel = PERTII18n.t('plan.opsPlan.possible');}
            else if (item.level === 'Probable') {probLabel = PERTII18n.t('plan.opsPlan.probable');}
            else if (item.level === 'Expected') {probLabel = PERTII18n.t('plan.opsPlan.expected');}
            else if (item.level === 'CDW') {probLabel = PERTII18n.t('plan.opsPlan.cdw');}

            let line = startTime + '-' + endTime + ' -' + desc + probLabel;
            if (item.cause) {line += ' (' + opsPlanUpper(item.cause) + ')';}
            result.push(line);
        });

        return result;
    }

    // Helper: Build Advisory lines from timeline data
    function buildAdvisoryLinesFromTimeline(timelineData, levelFilter) {
        const result = [];
        if (!timelineData || !Array.isArray(timelineData)) {return result;}

        const filtered = timelineData.filter(function(item) {
            return levelFilter.indexOf(item.level) !== -1;
        });

        filtered.sort(opsPlanCompareTimeline);

        filtered.forEach(function(item) {
            const startTime = opsPlanFormatTmiTime(item.start_datetime);
            const endTime = opsPlanFormatTmiTime(item.end_datetime);
            const facility = opsPlanUpper(item.facility || '');
            let tmiType = item.tmi_type || '';
            if (tmiType === 'Other' && item.tmi_type_other) {
                tmiType = item.tmi_type_other;
            }

            const advzyNum = item.advzy_number ? ' ADVZY ' + opsPlanUpper(item.advzy_number) : '';
            let desc = facility;
            if (tmiType) {desc += ' ' + opsPlanUpper(tmiType);}
            desc += advzyNum;

            if (item.cause) {
                desc += ' (' + opsPlanUpper(item.cause) + ')';
            }

            const line = startTime + '-' + endTime + ' -' + desc;
            result.push(line);
        });

        return result;
    }

    // Helper: Build VIP/Space/Special Event lines from timeline data
    function buildSpecialLinesFromTimeline(timelineData, levelFilter) {
        const result = [];
        if (!timelineData || !Array.isArray(timelineData)) {return result;}

        const filtered = timelineData.filter(function(item) {
            return levelFilter.indexOf(item.level) !== -1;
        });

        filtered.sort(opsPlanCompareTimeline);

        filtered.forEach(function(item) {
            const startTime = opsPlanFormatTmiTime(item.start_datetime);
            const endTime = opsPlanFormatTmiTime(item.end_datetime);
            const facility = opsPlanUpper(item.facility || '');
            let tmiType = item.tmi_type || '';
            if (tmiType === 'Other' && item.tmi_type_other) {
                tmiType = item.tmi_type_other;
            }

            let desc = facility;
            if (tmiType) {desc += ' ' + opsPlanUpper(tmiType);}
            if (item.area) {desc += ' ' + opsPlanUpper(item.area);}

            if (item.notes) {
                desc += ' (' + opsPlanUpper(item.notes) + ')';
            } else if (item.cause) {
                desc += ' (' + opsPlanUpper(item.cause) + ')';
            }

            const line = startTime + '-' + endTime + ' -' + desc;
            result.push(line);
        });

        return result;
    }

    // Get timeline data from the timeline objects (both termTimelineData and enrouteTimelineData declared above)

    // TERMINAL ACTIVE
    const termActiveLines = [];
    termActiveLines.push(PERTII18n.t('plan.opsPlan.terminalActive'));
    const termActiveTmis = buildTmiLinesFromTimeline(termTimelineData, ['Active']);
    if (termActiveTmis.length) {
        termActiveTmis.forEach(function(l) { termActiveLines.push(l); });
    } else {
        termActiveLines.push(PERTII18n.t('plan.opsPlan.none'));
    }

    // TERMINAL PLANNED (Possible, Probable, Expected)
    const termPlannedLines = [];
    termPlannedLines.push(PERTII18n.t('plan.opsPlan.terminalPlanned'));
    const termPlannedTmis = buildTmiLinesFromTimeline(termTimelineData, ['Possible', 'Probable', 'Expected']);
    if (termPlannedTmis.length) {
        termPlannedTmis.forEach(function(l) { termPlannedLines.push(l); });
    }
    // Also include planning comments from old UI if present
    $('#termplanningdata .card').each(function() {
        const fac = $(this).find('.card-title').text().trim();
        const comments = $(this).find('p').text().trim();
        if (!fac && !comments) {return;}
        let line = '__/____-__/____ -' + opsPlanUpper(fac);
        if (comments) {
            line += ' [' + opsPlanUpper(comments) + ']';
        }
        termPlannedLines.push(line);
    });
    if (termPlannedLines.length === 1) {
        termPlannedLines.push(PERTII18n.t('plan.opsPlan.none'));
    }


    // Sort TERMINAL PLANNED lines by time
    opsPlanSortTimeLines(termPlannedLines);

    // EN ROUTE ACTIVE
    const enrouteActiveLines = [];
    enrouteActiveLines.push(PERTII18n.t('plan.opsPlan.enRouteActive'));
    const enrouteActiveTmis = buildTmiLinesFromTimeline(enrouteTimelineData, ['Active']);
    if (enrouteActiveTmis.length) {
        enrouteActiveTmis.forEach(function(l) { enrouteActiveLines.push(l); });
    } else {
        enrouteActiveLines.push(PERTII18n.t('plan.opsPlan.none'));
    }

    // EN ROUTE PLANNED (Possible, Probable, Expected)
    const enroutePlannedLines = [];
    enroutePlannedLines.push(PERTII18n.t('plan.opsPlan.enRoutePlanned'));
    const enroutePlannedTmis = buildTmiLinesFromTimeline(enrouteTimelineData, ['Possible', 'Probable', 'Expected']);
    if (enroutePlannedTmis.length) {
        enroutePlannedTmis.forEach(function(l) { enroutePlannedLines.push(l); });
    }
    // Also include planning comments from old UI if present
    $('#enrouteplanningdata .card').each(function() {
        const fac = $(this).find('.card-title').text().trim();
        const comments = $(this).find('p').text().trim();
        if (!fac && !comments) {return;}
        let line = '__/____-__/____ -' + opsPlanUpper(fac);
        if (comments) {
            line += ' [' + opsPlanUpper(comments) + ']';
        }
        enroutePlannedLines.push(line);
    });
    if (enroutePlannedLines.length === 1) {
        enroutePlannedLines.push(PERTII18n.t('plan.opsPlan.none'));
    }


    // Sort EN ROUTE PLANNED lines by time
    opsPlanSortTimeLines(enroutePlannedLines);

    // VIP MOVEMENTS (from both terminal and enroute timelines)
    const vipLines = [];
    vipLines.push(PERTII18n.t('plan.opsPlan.vipMovements'));
    const allTimelineData = termTimelineData.concat(enrouteTimelineData);
    const vipTmis = buildSpecialLinesFromTimeline(allTimelineData, ['VIP']);
    if (vipTmis.length) {
        vipTmis.forEach(function(l) { vipLines.push(l); });
    } else {
        vipLines.push(PERTII18n.t('plan.opsPlan.none'));
    }

    // SPACE OPERATIONS (from both timelines)
    const spaceLines = [];
    spaceLines.push(PERTII18n.t('plan.opsPlan.spaceOperations'));
    const spaceTmis = buildSpecialLinesFromTimeline(allTimelineData, ['Space_Op']);
    if (spaceTmis.length) {
        spaceTmis.forEach(function(l) { spaceLines.push(l); });
    } else {
        spaceLines.push(PERTII18n.t('plan.opsPlan.none'));
    }

    // SPECIAL EVENTS (from both timelines)
    const specialEventLines = [];
    specialEventLines.push(PERTII18n.t('plan.opsPlan.specialEvents'));
    const specialTmis = buildSpecialLinesFromTimeline(allTimelineData, ['Special_Event']);
    if (specialTmis.length) {
        specialTmis.forEach(function(l) { specialEventLines.push(l); });
    } else {
        specialEventLines.push(PERTII18n.t('plan.opsPlan.none'));
    }

    // ADVISORIES (Terminal and EnRoute)
    const advisoryLines = [];
    advisoryLines.push(PERTII18n.t('plan.opsPlan.advisories'));
    const advisoryTmis = buildAdvisoryLinesFromTimeline(allTimelineData, ['Advisory_Terminal', 'Advisory_EnRoute']);
    if (advisoryTmis.length) {
        advisoryTmis.forEach(function(l) { advisoryLines.push(l); });
    } else {
        advisoryLines.push(PERTII18n.t('plan.opsPlan.none'));
    }

    // CDRS/SWAP/... and SIRs (still default to NONE for now)
    const cdrLines = [];
    cdrLines.push(PERTII18n.t('plan.opsPlan.cdrSwap'));
    cdrLines.push(PERTII18n.t('plan.opsPlan.none'));

    const sirLines = [];
    sirLines.push(PERTII18n.t('plan.opsPlan.sirReports'));
    sirLines.push(PERTII18n.t('plan.opsPlan.none'));

    // Footer time summary
    const footerLines = [];
    footerLines.push(opsPlanUpper((startDay || '__') + (startTime || '____') + '-' + (endDay || '__') + (endTime || '____')));

    const now = new Date();
    const yy  = String(now.getUTCFullYear()).slice(-2);
    const mm  = String(now.getUTCMonth() + 1).toString().padStart(2, '0');
    const dd  = String(now.getUTCDate()).toString().padStart(2, '0');
    const hh  = String(now.getUTCHours()).toString().padStart(2, '0');
    const mn  = String(now.getUTCMinutes()).toString().padStart(2, '0');
    footerLines.push(yy + '/' + mm + '/' + dd + ' ' + hh + ':' + mn);

    // Assemble sections and enforce Discord 2000-char limit per part

    // Apply 68-character wrapping with hanging indentation
    opsPlanWrapLines68(headerLines);
    opsPlanWrapLines68(staffingLines);
    opsPlanWrapLines68(termConstraintLines);
    opsPlanWrapLines68(termActiveLines);
    opsPlanWrapLines68(termPlannedLines);
    opsPlanWrapLines68(enrouteConstraintLines);
    opsPlanWrapLines68(enrouteActiveLines);
    opsPlanWrapLines68(enroutePlannedLines);
    opsPlanWrapLines68(vipLines);
    opsPlanWrapLines68(spaceLines);
    opsPlanWrapLines68(specialEventLines);
    opsPlanWrapLines68(advisoryLines);
    opsPlanWrapLines68(cdrLines);
    opsPlanWrapLines68(sirLines);
    opsPlanWrapLines68(footerLines);

    const sections = [
        { name: 'HEADER',   lines: headerLines },
        { name: 'STAFFING', lines: staffingLines },
        { name: 'TERM_CONSTRAINTS', lines: termConstraintLines },
        { name: 'TERM_ACTIVE', lines: termActiveLines },
        { name: 'TERM_PLANNED', lines: termPlannedLines },
        { name: 'ENR_CONSTRAINTS', lines: enrouteConstraintLines },
        { name: 'ENR_ACTIVE', lines: enrouteActiveLines },
        { name: 'ENR_PLANNED', lines: enroutePlannedLines },
        { name: 'VIP', lines: vipLines },
        { name: 'SPACE', lines: spaceLines },
        { name: 'SPECIAL_EVENTS', lines: specialEventLines },
        { name: 'ADVISORIES', lines: advisoryLines },
        { name: 'CDR', lines: cdrLines },
        { name: 'SIR', lines: sirLines },
        { name: 'FOOTER', lines: footerLines },
    ];

    const parts = [];
    let currentLines = [];
    let currentLen = 0;
    const maxLen = 2000;

    function flushPart() {
        if (!currentLines.length) {return;}
        parts.push(currentLines.join('\n'));
        currentLines = [];
        currentLen = 0;
    }

    sections.forEach(function(sec, idx) {
        const secText = sec.lines.join('\n');
        const secLen = secText.length;
        const extraNewlines = currentLines.length ? 1 : 0; // one blank line between sections

        if (currentLen + extraNewlines + secLen > maxLen && currentLines.length) {
            flushPart();
        }

        if (currentLines.length) {
            currentLines.push('');
            currentLen += 1;
        }

        for (let i = 0; i < sec.lines.length; i++) {
            const line = sec.lines[i];
            currentLines.push(line);
            currentLen += line.length;
            if (i < sec.lines.length - 1) {
                currentLen += 1; // newline
            }
        }
    });

    flushPart();

    let finalText = '';
    if (parts.length <= 1) {
        finalText = parts[0] || '';
    } else {
        for (let p = 0; p < parts.length; p++) {
            const label = '(PART ' + (p + 1) + ' OF ' + parts.length + ')';
            if (p > 0) {
                finalText += '\n\n';
            }
            finalText += label + '\n' + parts[p];
        }
    }

    $('#opsPlanMessage').val(finalText);
}

function openOpsPlanModal() {
    // Default advisory date from event date if empty
    if ($('#opsAdvDate').val().trim() === '' && typeof PERTI_EVENT_DATE !== 'undefined' && PERTI_EVENT_DATE) {
        $('#opsAdvDate').val(opsPlanFormatMmDdYyyy(PERTI_EVENT_DATE));
    }

    // Default Ops Plan event times from PERTI fields / event defaults if empty
    const evDate    = (typeof PERTI_EVENT_DATE     !== 'undefined' ? (PERTI_EVENT_DATE     || '') : '');
    const evStart   = (typeof PERTI_EVENT_START    !== 'undefined' ? (PERTI_EVENT_START    || '') : '');
    const evEndDate = (typeof PERTI_EVENT_END_DATE !== 'undefined' ? (PERTI_EVENT_END_DATE || '') : '');
    const evEndTime = (typeof PERTI_EVENT_END_TIME !== 'undefined' ? (PERTI_EVENT_END_TIME || '') : '');

    if ($('#opsStartDate').val().trim() === '') {
        const sd = ($('#pertiStartDate').val() || '').trim() || evDate;
        if (sd) {$('#opsStartDate').val(sd);}
    }
    if ($('#opsStartTime').val().trim() === '') {
        const st = ($('#pertiStartTime').val() || '').trim() || evStart;
        if (st) {$('#opsStartTime').val(st);}
    }
    if ($('#opsEndDate').val().trim() === '') {
        let ed = ($('#pertiEndDate').val() || '').trim() || evEndDate;
        if (!ed) {
            const sd2 = ($('#opsStartDate').val() || '').trim();
            const st2 = ($('#opsStartTime').val() || '').trim();
            if (sd2 && typeof pertiDefaultEndDate === 'function') {
                ed = pertiDefaultEndDate(sd2, st2);
            }
        }
        if (ed) {$('#opsEndDate').val(ed);}
    }
    if ($('#opsEndTime').val().trim() === '') {
        const et = ($('#pertiEndTime').val() || '').trim() || evEndTime;
        if (et) {$('#opsEndTime').val(et);}
    }

    opsPlanUpdateMessage();
    $('#opsPlanModal').modal('show');
}

function opsPlanInitBindings() {
    $(document).on('input change', '#opsAdvNum, #opsAdvDate, #opsNarrative, #opsStartDate, #opsStartTime, #opsEndDate, #opsEndTime', function() {
        opsPlanUpdateMessage();
    });

    $(document).on('click', '#opsPlanCopyBtn', function() {
        const $ta = $('#opsPlanMessage');
        if (!$ta.length) {return;}
        $ta.focus();
        $ta.select();
        try {
            document.execCommand('copy');
        } catch (e) {
            console.error('Copy to clipboard failed:', e);
        }
    });
}
function pertiUpdateMessage() {
    if (typeof PERTI_EVENT_NAME === 'undefined') {return;}

    const eventName = PERTI_EVENT_NAME || '';
    const opLevel   = (typeof PERTI_OPLEVEL !== 'undefined' ? (PERTI_OPLEVEL || '') : '');
    const planNumber = (typeof PERTI_PLAN_ID !== 'undefined')
        ? PERTI_PLAN_ID
        : (typeof p_id !== 'undefined' ? p_id : '');

    const startDate = ($('#pertiStartDate').val() || '').trim();
    const startTime = ($('#pertiStartTime').val() || '').trim();
    const endDate   = ($('#pertiEndDate').val()   || '').trim();
    const endTime   = ($('#pertiEndTime').val()   || '').trim();

    const startZ = startTime ? (startTime + 'Z') : '____Z';
    const endZ   = endTime   ? (endTime   + 'Z') : '____Z';

    const lines = [];

    lines.push(PERTII18n.t('plan.pertiNotification.titleLine', { eventName, opLevel }));

    if (startDate && endDate && startDate === endDate) {
        lines.push(PERTII18n.t('plan.pertiNotification.sameDay', { eventName, date: startDate, startZ, endZ }));
    } else {
        const sdText = startDate || '____';
        const edText = endDate   || '____';
        lines.push(PERTII18n.t('plan.pertiNotification.multiDay', { eventName, startDate: sdText, startZ, endDate: edText, endZ }));
    }

    const ts = pertiComputeDiscordTimestamps(startDate, startTime, endDate, endTime);
    if (ts && ts.startTs && ts.endTs) {
        lines.push(PERTII18n.t('plan.pertiNotification.startTimestamp', { ts: ts.startTs }));
        lines.push(PERTII18n.t('plan.pertiNotification.endTimestamp', { ts: ts.endTs }));
    } else {
        lines.push(PERTII18n.t('plan.pertiNotification.startTimestampPlaceholder'));
        lines.push(PERTII18n.t('plan.pertiNotification.endTimestampPlaceholder'));
    }

    lines.push('');

    lines.push(PERTII18n.t('plan.pertiNotification.reviewPlan'));
    lines.push(PERTII18n.t('plan.pertiNotification.linkPertiPlan', { planNumber }));
    lines.push(PERTII18n.t('plan.pertiNotification.linkStaffingData', { planNumber }));
    lines.push(PERTII18n.t('plan.pertiNotification.linkNod'));
    lines.push(PERTII18n.t('plan.pertiNotification.linkSplits'));
    lines.push(PERTII18n.t('plan.pertiNotification.linkGdt'));
    lines.push(PERTII18n.t('plan.pertiNotification.linkRouteMapper'));
    lines.push(PERTII18n.t('plan.pertiNotification.linkFieldConfigs'));
    lines.push(PERTII18n.t('plan.pertiNotification.linkDccDashboard'));
    lines.push('');

    const facilitiesText = ($('#advFacilities').val() || '').trim();
    if (facilitiesText) {
        const parts = facilitiesText.split(/[/\s,]+/);
        const codes = [];
        parts.forEach(function(p) {
            p = (p || '').trim();
            if (!p) {return;}
            codes.push(p.toUpperCase());
        });
        if (codes.length) {
            codes.sort();
            const facLines = [];
            codes.forEach(function(code) {
                facLines.push(code + ':');
            });
            lines.push(facLines.join('\n'));
            lines.push('');
        }
    }

    lines.push(PERTII18n.t('plan.pertiNotification.coordinateInstructions'));
    lines.push('---------------------------------------------------------');

    const orgType = (typeof AdvisoryConfig !== 'undefined') ? AdvisoryConfig.getOrgType() : 'DCC';
    if (orgType === 'NOC') {
        lines.push(PERTII18n.t('plan.pertiNotification.canocReactRequest'));
    } else {
        lines.push(PERTII18n.t('plan.pertiNotification.nomReactRequest'));
        lines.push(PERTII18n.t('plan.pertiNotification.traineeReactRequest'));
    }
    lines.push('');
    lines.push(PERTII18n.t('plan.pertiNotification.availableEmoji'));
    lines.push(PERTII18n.t('plan.pertiNotification.partialEmoji'));
    lines.push(PERTII18n.t('plan.pertiNotification.unavailableEmoji'));
    lines.push('---------------------------------------------------------');

    $('#pertiMessage').val(lines.join('\n'));
}

function openPertiModal() {
    if (typeof advInitFacilitiesDropdown === 'function') {
        advInitFacilitiesDropdown();
    }

    const $startDate = $('#pertiStartDate');
    const $startTime = $('#pertiStartTime');
    const $endDate   = $('#pertiEndDate');
    const $endTime   = $('#pertiEndTime');

    // Default start date/time from event
    if ($startDate.val().trim() === '' && typeof PERTI_EVENT_DATE !== 'undefined' && PERTI_EVENT_DATE) {
        $startDate.val(PERTI_EVENT_DATE);
    }
    if ($startTime.val().trim() === '' && typeof PERTI_EVENT_START !== 'undefined' && PERTI_EVENT_START) {
        $startTime.val(PERTI_EVENT_START);
    }

    // Default end date from event, or calculate from start
    if ($endDate.val().trim() === '') {
        if (typeof PERTI_EVENT_END_DATE !== 'undefined' && PERTI_EVENT_END_DATE) {
            $endDate.val(PERTI_EVENT_END_DATE);
        } else {
            const sd = $startDate.val().trim();
            const st = $startTime.val().trim();
            if (sd) {
                $endDate.val(pertiDefaultEndDate(sd, st));
            }
        }
    }

    // Default end time from event
    if ($endTime.val().trim() === '' && typeof PERTI_EVENT_END_TIME !== 'undefined' && PERTI_EVENT_END_TIME) {
        $endTime.val(PERTI_EVENT_END_TIME);
    }

    $('#pertiModal').modal('show');
    pertiUpdateMessage();
}

function pertiInitBindings() {
    $(document).on('input change', '#pertiStartDate, #pertiStartTime', function() {
        const $endDate = $('#pertiEndDate');
        if ($endDate.val().trim() === '') {
            const sd = ($('#pertiStartDate').val() || '').trim();
            const st = ($('#pertiStartTime').val() || '').trim();
            if (sd) {
                $endDate.val(pertiDefaultEndDate(sd, st));
            }
        }
        pertiUpdateMessage();
        if (typeof opsPlanUpdateMessage === 'function') {
            opsPlanUpdateMessage();
        }
    });

    $(document).on('input change', '#pertiEndDate, #pertiEndTime', function() {
        pertiUpdateMessage();
        if (typeof opsPlanUpdateMessage === 'function') {
            opsPlanUpdateMessage();
        }
    });

    $(document).on('input change', '#advFacilities', function() {
        pertiUpdateMessage();
    });

    $(document).on('click', '#pertiCopyBtn', function() {
        const $ta = $('#pertiMessage');
        if (!$ta.length) {return;}
        $ta.focus();
        $ta.select();
        try {
            document.execCommand('copy');
        } catch (e) {
            console.error('Copy to clipboard failed:', e);
        }
    });
}

$(function() {
    pertiInitBindings();
    opsPlanInitBindings();
    if (typeof advInitFacilitiesDropdown === 'function') {
        advInitFacilitiesDropdown();
    }
});

