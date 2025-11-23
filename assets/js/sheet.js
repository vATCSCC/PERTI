var pathname = $(location).attr('href');
var uri = pathname.split('?');
var p_id = uri[1];


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
    var button = $(event.relatedTarget);

    var modal= $(this);

    modal.find('.modal-body #id').val(button.data('id'));
    modal.find('.modal-body #personnel_name').val(button.data('personnel_name'));
    modal.find('.modal-body #personnel_ois').val(button.data('personnel_ois'));
    modal.find('.modal-body #position_name').val(button.data('position_name')).trigger('change');
    modal.find('.modal-body #position_facility').val(button.data('position_facility')).trigger('change');
});

// AJAX: #edit_dccstaffing POST
$("#edit_dccstaffing").submit(function(e) {
    e.preventDefault();

    var url = 'api/user/dcc/update';

    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize().replace(/'/g, "`"),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      'Successfully Edited',
                text:       'You have successfully edited the select TMU personnel/position.',
                timer:      3000,
                showConfirmButton: false
            });

            loadDCCStaffing();
            $('#edit_dccstaffingModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Edited',
                text:   'There was an error in editing the selected TMU personnel/position.'
            });
        }
    });
});

// editterminalinit Modal
$('#edittermstaffingModal').on('show.bs.modal', function(event) {
    var button = $(event.relatedTarget);

    var modal= $(this);

    modal.find('.modal-body #id').val(button.data('id'));
    modal.find('.modal-body #facility_name').val(button.data('facility_name'));
    modal.find('.modal-body #staffing_status').val(button.data('staffing_status')).trigger('change');
    modal.find('.modal-body #staffing_quantity').val(button.data('staffing_quantity'));
    modal.find('.modal-body #comments').val(button.data('comments'));
});

// AJAX: #edittermstaffing POST
$("#edittermstaffing").submit(function(e) {
    e.preventDefault();

    var url = 'api/user/term_staffing/update';

    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize().replace(/'/g, "`"),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      'Successfully Edited',
                text:       'You have successfully edited a terminal staffing entry for this plan.',
                timer:      3000,
                showConfirmButton: false
            });

            loadTermStaffing();
            $('#edittermstaffingModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Edited',
                text:   'There was an error in editing a terminal staffing entry for this plan.'
            });
        }
    });
});

// editconfigModal Modal
$('#editconfigModal').on('show.bs.modal', function(event) {
    var button = $(event.relatedTarget);

    var modal= $(this);

    modal.find('.modal-body #id').val(button.data('id'));
    modal.find('.modal-body #airport').val(button.data('airport'));
    modal.find('.modal-body #weather').val(button.data('weather')).trigger('change');
    modal.find('.modal-body #arrive').val(button.data('arrive'));
    modal.find('.modal-body #depart').val(button.data('depart'));
    modal.find('.modal-body #comments').val(button.data('comments'));
});

// AJAX: #editconfig POST
$("#editconfig").submit(function(e) {
    e.preventDefault();

    var url = 'api/user/configs/update';

    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize().replace(/'/g, "`"),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      'Successfully Edited',
                text:       'You have successfully edited a field config for this plan.',
                timer:      3000,
                showConfirmButton: false
            });

            loadConfigs();
            $('#editconfigModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Edited',
                text:   'There was an error in editing a field config entry for this plan.'
            });
        }
    });
});


// editenroutestaffing Modal
$('#editenroutestaffingModal').on('show.bs.modal', function(event) {
    var button = $(event.relatedTarget);

    var modal= $(this);

    modal.find('.modal-body #id').val(button.data('id'));
    modal.find('.modal-body #facility_name').val(button.data('facility_name')).trigger('change');
    modal.find('.modal-body #staffing_status').val(button.data('staffing_status')).trigger('change');
    modal.find('.modal-body #staffing_quantity').val(button.data('staffing_quantity'));
    modal.find('.modal-body #comments').val(button.data('comments'));
});

// AJAX: #editenroutestaffing POST
$("#editenroutestaffing").submit(function(e) {
    e.preventDefault();

    var url = 'api/user/enroute_staffing/update';

    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize().replace(/'/g, "`"),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      'Successfully Edited',
                text:       'You have successfully edited an enroute staffing entry for this plan.',
                timer:      3000,
                showConfirmButton: false
            });

            loadEnrouteStaffing();
            $('#editenroutestaffingModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Edited',
                text:   'There was an error in editing an enroute staffing entry for this plan.'
            });
        }
    });
});