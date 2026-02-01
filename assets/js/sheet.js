const pathname = $(location).attr('href');
const uri = pathname.split('?');
const p_id = uri[1];


function loadGoals() {
    $('[data-toggle="tooltip"]').tooltip('dispose');
    $.get(`api/data/plans/goals?p_id=${p_id}`).done(function(data) {
        $('#goals_table').html(data);
        tooltips();
    });
}

function loadDCCStaffing() {
    $('[data-toggle="tooltip"]').tooltip('dispose');
    $.get(`api/data/sheet/dcc_staffing?p_id=${p_id}`).done(function(data) {
        $('#dcc_staffing_table').html(data);
        tooltips();
    });
}

function loadTermStaffing() {
    $('[data-toggle="tooltip"]').tooltip('dispose');
    $.get(`api/data/sheet/term_staffing?p_id=${p_id}`).done(function(data) {
        $('#term_staffing_table').html(data);
        tooltips();
    });
}

function loadConfigs() {
    $('[data-toggle="tooltip"]').tooltip('dispose');
    $.get(`api/data/sheet/configs?p_id=${p_id}`).done(function(data) {
        $('#configs_table').html(data);
        tooltips();
    });
}

function loadEnrouteStaffing() {
    $('[data-toggle="tooltip"]').tooltip('dispose');
    $.get(`api/data/sheet/enroute_staffing?p_id=${p_id}`).done(function(data) {
        $('#enroute_staffing_table').html(data);
        tooltips();
    });
}

loadGoals();
loadDCCStaffing();
loadTermStaffing();
loadConfigs();
loadEnrouteStaffing();


// edit_dccstaffing Modal
$('#edit_dccstaffingModal').on('show.bs.modal', function(event) {
    const button = $(event.relatedTarget);

    const modal= $(this);

    modal.find('.modal-body #id').val(button.data('id'));
    modal.find('.modal-body #personnel_name').val(button.data('personnel_name'));
    modal.find('.modal-body #personnel_ois').val(button.data('personnel_ois'));
    modal.find('.modal-body #position_name').val(button.data('position_name')).trigger('change');
    modal.find('.modal-body #position_facility').val(button.data('position_facility')).trigger('change');
});

// AJAX: #edit_dccstaffing POST
$('#edit_dccstaffing').submit(function(e) {
    e.preventDefault();

    const url = 'api/user/dcc/update';

    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize().replace(/'/g, '`'),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      'Successfully Edited',
                text:       'You have successfully edited the select TMU personnel/position.',
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
                title:  'Not Edited',
                text:   'There was an error in editing the selected TMU personnel/position.',
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

    const url = 'api/user/term_staffing/update';

    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize().replace(/'/g, '`'),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      'Successfully Edited',
                text:       'You have successfully edited a terminal staffing entry for this plan.',
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
                title:  'Not Edited',
                text:   'There was an error in editing a terminal staffing entry for this plan.',
            });
        },
    });
});

// editconfigModal Modal
$('#editconfigModal').on('show.bs.modal', function(event) {
    const button = $(event.relatedTarget);

    const modal = $(this);

    // Reset config picker state
    $('#sheet_editconfig_use_adl').prop('checked', false);
    $('#sheet_editconfig_picker').hide();
    $('#sheet_editconfig_select').empty().append('<option value="">-- Select configuration --</option>').prop('disabled', true);
    sheetEditconfigSelectedConfig = null;

    modal.find('.modal-body #sheet_editconfig_id').val(button.data('id'));
    modal.find('.modal-body #sheet_editconfig_airport').val(button.data('airport'));
    modal.find('.modal-body #sheet_editconfig_weather').val(button.data('weather')).trigger('change');
    modal.find('.modal-body #sheet_editconfig_arrive').val(button.data('arrive'));
    modal.find('.modal-body #sheet_editconfig_depart').val(button.data('depart'));
    modal.find('.modal-body #sheet_editconfig_comments').val(button.data('comments'));

    // Pre-fetch configs for the airport
    const airport = button.data('airport');
    if (airport && airport.length >= 3) {
        fetchAirportConfigs(airport, function(configs) {
            populateConfigDropdown(configs, '#sheet_editconfig_select');
        });
    }
});

// AJAX: #editconfig POST
$('#editconfig').submit(function(e) {
    e.preventDefault();

    const url = 'api/user/configs/update';

    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize().replace(/'/g, '`'),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      'Successfully Edited',
                text:       'You have successfully edited a field config for this plan.',
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
                title:  'Not Edited',
                text:   'There was an error in editing a field config entry for this plan.',
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

    const url = 'api/user/enroute_staffing/update';

    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize().replace(/'/g, '`'),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      'Successfully Edited',
                text:       'You have successfully edited an enroute staffing entry for this plan.',
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
                title:  'Not Edited',
                text:   'There was an error in editing an enroute staffing entry for this plan.',
            });
        },
    });
});

// =====================================================
// ADL Config Picker Functions (Sheet)
// =====================================================

// Cache for loaded configs by airport
const sheetConfigCache = {};
let sheetEditconfigSelectedConfig = null;

// Fetch configs for airport from ADL
function fetchAirportConfigs(airport, callback) {
    if (!airport || airport.length < 3) {
        callback([]);
        return;
    }

    const cacheKey = airport.toUpperCase();
    if (sheetConfigCache[cacheKey]) {
        callback(sheetConfigCache[cacheKey]);
        return;
    }

    $.get('api/demand/config_search.php', { airport: airport })
        .done(function(data) {
            if (data.success && data.configs) {
                sheetConfigCache[cacheKey] = data.configs;
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
    $select.empty().append('<option value="">-- Select configuration --</option>');

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
        $select.append('<option value="" disabled>No configs found for this airport</option>');
        $select.prop('disabled', true);
    }
}

// Apply config to form fields (runways only for sheet)
function applySheetConfigToForm(config) {
    if (!config) {return;}

    $('#sheet_editconfig_arrive').val(config.arr_runways || '');
    $('#sheet_editconfig_depart').val(config.dep_runways || '');
}

// Toggle config picker visibility
$('#sheet_editconfig_use_adl').on('change', function() {
    if ($(this).is(':checked')) {
        $('#sheet_editconfig_picker').slideDown(200);
        // Trigger config fetch
        const airport = $('#sheet_editconfig_airport').val();
        if (airport && airport.length >= 3) {
            fetchAirportConfigs(airport, function(configs) {
                populateConfigDropdown(configs, '#sheet_editconfig_select');
            });
        }
    } else {
        $('#sheet_editconfig_picker').slideUp(200);
        sheetEditconfigSelectedConfig = null;
    }
});

// Apply selected config
$('#sheet_editconfig_select').on('change', function() {
    const idx = $(this).val();
    const airport = $('#sheet_editconfig_airport').val().toUpperCase();
    const configs = sheetConfigCache[airport] || [];

    if (idx !== '' && configs[idx]) {
        sheetEditconfigSelectedConfig = configs[idx];
        applySheetConfigToForm(sheetEditconfigSelectedConfig);
    } else {
        sheetEditconfigSelectedConfig = null;
    }
});