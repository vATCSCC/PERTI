var pathname = $(location).attr('href');
var uri = pathname.split('?');
var p_id = uri[1];

const tinyMCE_b = [
    'atp_comments',
    'etp_comments',
    'aep_comments',
    'eep_comments'
];

tinyMCE_b.forEach(e => {
    tinyMCE.init({
        selector: `#${e}`,
        menubar : false,
        plugins : [
        'advlist lists charmap preview anchor',
        'searchreplace visualblocks code fullscreen',
        'insertdatetime media table paste code help',
        'link'
        ],
        toolbar : 'undo redo | ' +
        ' bold italic underline |' +
        ' bullist numlist link table removeformat ',
        force_br_newlines : true,
        force_p_newlines : false,
        forced_root_block : ''
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
    $.get(`api/data/plans/dcc_staffing?p_id=${p_id}&position_facility=DCC`).done(function(data) {
        $('#dcc_table').html(data);

        $.get(`api/data/plans/dcc_staffing?p_id=${p_id}`).done(function(data) {
            $('#dcc_staffing_table').html(data);
            tooltips();
        });
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
    });
}

function loadTermStaffing() {
    $('[data-toggle="tooltip"]').tooltip('dispose');
    $.get(`api/data/plans/term_staffing?p_id=${p_id}`).done(function(data) {
        $('#term_staffing_table').html(data); 
        tooltips();
    });
}

function loadConfigs() {
    $('[data-toggle="tooltip"]').tooltip('dispose');
    $.get(`api/data/plans/configs?p_id=${p_id}`).done(function(data) {
        $('#configs_table').html(data); 
        tooltips();
    });
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
    });
}

function loadEnrouteInits() {
    $('[data-toggle="tooltip"]').tooltip('dispose');
    $.get(`api/data/plans/enroute_inits?p_id=${p_id}`).done(function(data) {
        $('#enroute_inits').html(data); 
        tooltips();
    });
}

function loadEnrouteStaffing() {
    $('[data-toggle="tooltip"]').tooltip('dispose'); 
    $.get(`api/data/plans/enroute_staffing?p_id=${p_id}`).done(function(data) {
        $('#enroute_staffing_table').html(data); 
        tooltips();
    });
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

loadGoals();
loadDCCStaffing();
loadHistorical();
loadForecast();
loadTermInits();
loadTermStaffing();
loadConfigs();
loadTermPlanning();
loadTermConstraints();
loadEnrouteInits();
loadEnrouteStaffing();
loadEnroutePlanning();
loadEnrouteConstraints();
loadGroupFlights();
loadOutlook();

// AJAX: #addgoal POST
$("#addgoal").submit(function(e) {
    e.preventDefault();

    var url = 'api/mgt/goals/post';

    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize().replace(/'/g, "`"),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      'Successfully Added',
                text:       'You have successfully added an operational goal.',
                timer:      3000,
                showConfirmButton: false
            });

            loadGoals();
            $('#addgoalModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Added',
                text:   'There was an error in adding an operational goal.'
            });
        }
    });
});

// Edit Goal Modal
$('#editgoalModal').on('show.bs.modal', function(event) {
    var button = $(event.relatedTarget);

    var modal= $(this);

    modal.find('.modal-body #id').val(button.data('id'));
    modal.find('.modal-body #comments').html(button.data('comments'));
});

// AJAX: #editgoal POST
$("#editgoal").submit(function(e) {
    e.preventDefault();

    var url = 'api/mgt/goals/update';

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
                text:       'You have successfully edited an operational goal.',
                timer:      3000,
                showConfirmButton: false
            });

            loadGoals();
            $('#editgoalModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Edited',
                text:   'There was an error in editing an operational goal.'
            });
        }
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
                title:      'Successfully Deleted',
                text:       'You have successfully deleted the selected operational goal.',
                timer:      3000,
                showConfirmButton: false
            });

            loadGoals();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Deleted',
                text:   'There was an error in deleting this operational goal.'
            });
        }
    });
}

// AJAX: #add_dccstaffing POST
$("#add_dccstaffing").submit(function(e) {
    e.preventDefault();

    var url = 'api/mgt/dcc/post';

    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize().replace(/'/g, "`"),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      'Successfully Added',
                text:       'You have successfully added a TMU personnel to the roster.',
                timer:      3000,
                showConfirmButton: false
            });

            loadDCCStaffing();
            $('#add_dccstaffingModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Added',
                text:   'There was an error in adding a TMU personnel to the roster.'
            });
        }
    });
});

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

    var url = 'api/mgt/dcc/update';

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
                title:      'Successfully Deleted',
                text:       'You have successfully deleted the selected TMU personnel/position',
                timer:      3000,
                showConfirmButton: false
            });

            loadDCCStaffing();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Deleted',
                text:   'There was an error in deleting this TMU personnel/position.'
            });
        }
    });
}

// #addhistoricalModal Show (for DTP)
$('#addhistoricalModal').on('show.bs.modal', function(event) {
    // Init: Date Time Picker
    $(this).find('.modal-body #ah_date').datetimepicker({
        format: 'Y-m-d',
        inline: false,
        timepicker: false
    });
});

// AJAX: #addhistorical POST
$("#addhistorical").submit(function(e) {
    e.preventDefault();

    var url = 'api/mgt/historical/post';

    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize().replace(/'/g, "`"),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      'Successfully Added',
                text:       'You have successfully added historical data for this plan.',
                timer:      3000,
                showConfirmButton: false
            });

            loadHistorical();
            $('#addhistoricalModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Added',
                text:   'There was an error in adding historical data for this plan.'
            });
        }
    });
});

// edithistoricalModal Modal
$('#edithistoricalModal').on('show.bs.modal', function(event) {
    var button = $(event.relatedTarget);

    var modal= $(this);

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
        value: button.data('date')
    });
});

// AJAX: #edithistorical POST
$("#edithistorical").submit(function(e) {
    e.preventDefault();

    var url = 'api/mgt/historical/update';

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
                text:       'You have successfully edited an entry of historical data for this plan.',
                timer:      3000,
                showConfirmButton: false
            });

            loadHistorical();
            $('#edithistoricalModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Edited',
                text:   'There was an error in editing an entry of historical data for this plan.'
            });
        }
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
                title:      'Successfully Deleted',
                text:       'You have successfully deleted an entry of historical data for this plan',
                timer:      3000,
                showConfirmButton: false
            });

            loadHistorical();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Deleted',
                text:   'There was an error in deleting an entry of historical data for this plan.'
            });
        }
    });
}

// #addforecastModal Show (for DTP)
$('#addforecastModal').on('show.bs.modal', function(event) {
    // Init: Date Time Picker
    $(this).find('.modal-body #af_date').datetimepicker({
        format: 'Y-m-d H:i',
        inline: false,
        timepicker: true
    });
});

// AJAX: #addforecast POST
$("#addforecast").submit(function(e) {
    e.preventDefault();

    var url = 'api/mgt/forecast/post';

    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize().replace(/'/g, "`"),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      'Successfully Added',
                text:       'You have successfully added forecast data for this plan.',
                timer:      3000,
                showConfirmButton: false
            });

            loadForecast();
            $('#addforecastModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Added',
                text:   'There was an error in adding forecast data for this plan.'
            });
        }
    });
});

// editforecastModal Modal
$('#editforecastModal').on('show.bs.modal', function(event) {
    var button = $(event.relatedTarget);

    var modal= $(this);

    modal.find('.modal-body #id').val(button.data('id'));
    modal.find('.modal-body #title').val(button.data('title'));
    modal.find('.modal-body #image_url').val(button.data('image_url'));
    modal.find('.modal-body #summary').html(button.data('summary'));

    // Init: Date Time Picker
    modal.find('.modal-body #ef_date').datetimepicker({
        format: 'Y-m-d H:i',
        inline: false,
        timepicker: true,
        value: button.data('date')
    });
});

// AJAX: #edithistorical POST
$("#editforecast").submit(function(e) {
    e.preventDefault();

    var url = 'api/mgt/forecast/update';

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
                text:       'You have successfully edited an entry of forecast data for this plan.',
                timer:      3000,
                showConfirmButton: false
            });

            loadForecast();
            $('#editforecastModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Edited',
                text:   'There was an error in editing an entry of forecast data for this plan.'
            });
        }
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
                title:      'Successfully Deleted',
                text:       'You have successfully deleted an entry of forecast data for this plan.',
                timer:      3000,
                showConfirmButton: false
            });

            loadForecast();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Deleted',
                text:   'There was an error in deleting an entry of forecast data for this plan.'
            });
        }
    });
}


// AJAX: #addterminalinit POST
$("#addterminalinit").submit(function(e) {
    e.preventDefault();

    var url = 'api/mgt/term_inits/post';

    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize().replace(/'/g, "`"),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      'Successfully Added',
                text:       'You have successfully added a terminal initiative for this plan.',
                timer:      3000,
                showConfirmButton: false
            });

            loadTermInits();
            $('#addterminalinitModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Added',
                text:   'There was an error in adding a terminal initiative for this plan.'
            });
        }
    });
});


// editterminalinit Modal
$('#editterminalinitModal').on('show.bs.modal', function(event) {
    var button = $(event.relatedTarget);

    var modal= $(this);

    modal.find('.modal-body #id').val(button.data('id'));
    modal.find('.modal-body #title').val(button.data('title'));
    modal.find('.modal-body #context').val(button.data('context'));
});

// AJAX: #editterminalinit POST
$("#editterminalinit").submit(function(e) {
    e.preventDefault();

    var url = 'api/mgt/term_inits/update';

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
                text:       'You have successfully edited a terminal initiative for this plan.',
                timer:      3000,
                showConfirmButton: false
            });

            loadTermInits();
            $('#editterminalinitModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Edited',
                text:   'There was an error in editing a terminal initiative for this plan.'
            });
        }
    });
});

// FUNC: deleteTerminalInit [id:]
function deleteTerminalInit(id) {
    $.ajax({
        type:   'POST',
        url:    'api/mgt/term_inits/delete',
        data:   {id: id},
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      'Successfully Deleted',
                text:       'You have successfully deleted a terminal initiative for this plan.',
                timer:      3000,
                showConfirmButton: false
            });

            loadTermInits();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Deleted',
                text:   'There was an error in deleting a terminal initiative for this plan.'
            });
        }
    });
}

// FUNC: createTermTime [id:]
function createTermTime(init_id, time) {
    $.ajax({
        type:   'POST',
        url:    'api/mgt/term_inits/times/post',
        data:   {init_id: init_id, time: time},
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      'Successfully Created',
                text:       'You have successfully created an entry of terminal initiatives for this plan.',
                timer:      3000,
                showConfirmButton: false
            });

            loadTermInits();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Created',
                text:   'There was an error in creating an entry of terminal initiatives for this plan.'
            });
        }
    });
}

// FUNC: changeTermTime [id:]
function changeTermTime(id, prob) {
    $.ajax({
        type:   'POST',
        url:    'api/mgt/term_inits/times/update',
        data:   {id: id, probability: prob},
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      'Successfully Updated',
                text:       'You have successfully updated an entry of terminal initiatives for this plan.',
                timer:      3000,
                showConfirmButton: false
            });

            loadTermInits();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Updated',
                text:   'There was an error in updating an entry of terminal initiatives for this plan.'
            });
        }
    });
}

// FUNC: deleteTermTime [id:]
function deleteTermTime(id) {
    $.ajax({
        type:   'POST',
        url:    'api/mgt/term_inits/times/delete',
        data:   {id: id},
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      'Successfully Deleted',
                text:       'You have successfully deleted an entry of terminal initiatives for this plan.',
                timer:      3000,
                showConfirmButton: false
            });

            loadTermInits();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Deleted',
                text:   'There was an error in deleting an entry of terminal initiatives for this plan.'
            });
        }
    });
}

// AJAX: #addtermstaffing POST
$("#addtermstaffing").submit(function(e) {
    e.preventDefault();

    var url = 'api/mgt/term_staffing/post';

    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize().replace(/'/g, "`"),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      'Successfully Added',
                text:       'You have successfully added a terminal staffing entry for this plan.',
                timer:      3000,
                showConfirmButton: false
            });

            loadTermStaffing();
            $('#addtermstaffingModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Added',
                text:   'There was an error in adding a terminal staffing entry for this plan.'
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

    var url = 'api/mgt/term_staffing/update';

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

// FUNC: deleteTermStaffing [id:]
function deleteTermStaffing(id) {
    $.ajax({
        type:   'POST',
        url:    'api/mgt/term_staffing/delete',
        data:   {id: id},
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      'Successfully Deleted',
                text:       'You have successfully deleted a terminal staffing entry for this plan.',
                timer:      3000,
                showConfirmButton: false
            });

            loadTermStaffing();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Deleted',
                text:   'There was an error in deleting a terminal staffing entry for this plan.'
            });
        }
    });
}

// AJAX: #addconfig POST
$("#addconfig").submit(function(e) {
    e.preventDefault();

    var url = 'api/mgt/configs/post';

    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize().replace(/'/g, "`"),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      'Successfully Added',
                text:       'You have successfully added a field config entry for this plan.',
                timer:      3000,
                showConfirmButton: false
            });

            loadConfigs();
            $('#addconfigModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Added',
                text:   'There was an error in adding a field config entry for this plan.'
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
    modal.find('.modal-body #aar').val(button.data('aar'));
    modal.find('.modal-body #adr').val(button.data('adr'));
    modal.find('.modal-body #comments').val(button.data('comments'));
});

// AJAX: #editconfig POST
$("#editconfig").submit(function(e) {
    e.preventDefault();

    var url = 'api/mgt/configs/update';

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
                title:      'Successfully Deleted',
                text:       'You have successfully deleted a field config entry for this plan.',
                timer:      3000,
                showConfirmButton: false
            });

            loadConfigs();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Deleted',
                text:   'There was an error in deleting a field config entry for this plan.'
            });
        }
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
                title:      'Successfully Autofilled',
                text:       'You have successfully autofilled a field config entry for this plan.',
                timer:      3000,
                showConfirmButton: false
            });

            loadConfigs();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Deleted',
                text:   'There was an error in autofilling a field config entry for this plan.'
            });
        }
    });
}

// addtermplanning Modal
$('#addtermplanningModal').on('show.bs.modal', function(event) {
    tinymce.get('atp_comments').setContent('') 
});

// AJAX: #addtermplanning POST
$("#addtermplanning").submit(function(e) {
    e.preventDefault();

    var url = 'api/mgt/term_planning/post';

    tinymce.triggerSave();

    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize().replace(/'/g, "`"),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      'Successfully Added',
                text:       'You have successfully added a terminal planning entry for this plan.',
                timer:      3000,
                showConfirmButton: false
            });

            loadTermPlanning();
            $('#addtermplanningModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Added',
                text:   'There was an error in adding a terminal planning entry for this plan.'
            });
        }
    });
});


// edittermplanning Modal
$('#edittermplanningModal').on('show.bs.modal', function(event) {
    var button = $(event.relatedTarget);

    var modal= $(this);

    modal.find('.modal-body #id').val(button.data('id'));
    modal.find('.modal-body #facility_name').val(button.data('facility_name'));
    tinymce.get('etp_comments').setContent(button.data('comments')) 
});

// AJAX: #edittermplanning POST
$("#edittermplanning").submit(function(e) {
    e.preventDefault();

    var url = 'api/mgt/term_planning/update';

    tinymce.triggerSave();

    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize(),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      'Successfully Edited',
                text:       'You have successfully edited a terminal planning entry for this plan.',
                timer:      3000,
                showConfirmButton: false
            });

            loadTermPlanning();
            $('#edittermplanningModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Edited',
                text:   'There was an error in editing a terminal planning entry for this plan.'
            });
        }
    });
});

// FUNC: deleteTermPlanning [id:]
function deleteTermPlanning(id) {
    $.ajax({
        type:   'POST',
        url:    'api/mgt/term_planning/delete',
        data:   {id: id},
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      'Successfully Deleted',
                text:       'You have successfully deleted a terminal planning entry for this plan.',
                timer:      3000,
                showConfirmButton: false
            });

            loadTermPlanning();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Deleted',
                text:   'There was an error in deleting a terminal planning entry for this plan.'
            });
        }
    });
}

// addtermconstraint Modal
$('#addtermconstraintModal').on('show.bs.modal', function(event) {
   // Init: Date Time Picker
   $(this).find('.modal-body #at_date').datetimepicker({
        format: 'Y-m-d',
        inline: false,
        timepicker: false
    });
});

// AJAX: #addtermconstraint POST
$("#addtermconstraint").submit(function(e) {
    e.preventDefault();

    var url = 'api/mgt/term_constraints/post';
    
    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize().replace(/'/g, "`"),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      'Successfully Added',
                text:       'You have successfully added a terminal constraint entry for this plan.',
                timer:      3000,
                showConfirmButton: false
            });

            loadTermConstraints();
            $('#addtermconstraintModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Added',
                text:   'There was an error in adding a terminal constraint entry for this plan.'
            });
        }
    });
});


// edittermconstraint Modal
$('#edittermconstraintModal').on('show.bs.modal', function(event) {
    var button = $(event.relatedTarget);

    var modal= $(this);

    modal.find('.modal-body #id').val(button.data('id'));
    modal.find('.modal-body #location').val(button.data('location'));
    modal.find('.modal-body #context').val(button.data('context'));
    modal.find('.modal-body #impact').val(button.data('impact'));

    // Init: Date Time Picker
    $(this).find('.modal-body #et_date').datetimepicker({
        format: 'Y-m-d',
        inline: false,
        timepicker: false,
        value: button.data('date')
    });
});

// AJAX: #edittermconstraints POST
$("#edittermconstraint").submit(function(e) {
    e.preventDefault();

    var url = 'api/mgt/term_constraints/update';

    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize(),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      'Successfully Edited',
                text:       'You have successfully edited a terminal constraint entry for this plan.',
                timer:      3000,
                showConfirmButton: false
            });

            loadTermConstraints();
            $('#edittermconstraintModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Edited',
                text:   'There was an error in editing a terminal constraint entry for this plan.'
            });
        }
    });
});

// FUNC: deleteTermPlanning [id:]
function deleteTermConstraint(id) {
    $.ajax({
        type:   'POST',
        url:    'api/mgt/term_constraints/delete',
        data:   {id: id},
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      'Successfully Deleted',
                text:       'You have successfully deleted a terminal constraint entry for this plan.',
                timer:      3000,
                showConfirmButton: false
            });

            loadTermConstraints();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Deleted',
                text:   'There was an error in deleting a terminal constraint entry for this plan.'
            });
        }
    });
}


// AJAX: #addenrouteinit POST
$("#addenrouteinit").submit(function(e) {
    e.preventDefault();

    var url = 'api/mgt/enroute_inits/post';

    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize().replace(/'/g, "`"),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      'Successfully Added',
                text:       'You have successfully added a enroute initiative for this plan.',
                timer:      3000,
                showConfirmButton: false
            });

            loadEnrouteInits();
            $('#addenrouteinitModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Added',
                text:   'There was an error in adding a enroute initiative for this plan.'
            });
        }
    });
});


// editenrouteinit Modal
$('#editenrouteinitModal').on('show.bs.modal', function(event) {
    var button = $(event.relatedTarget);

    var modal= $(this);

    modal.find('.modal-body #id').val(button.data('id'));
    modal.find('.modal-body #title').val(button.data('title'));
    modal.find('.modal-body #context').val(button.data('context'));
});

// AJAX: #editenrouteinit POST
$("#editenrouteinit").submit(function(e) {
    e.preventDefault();

    var url = 'api/mgt/enroute_inits/update';

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
                text:       'You have successfully edited an enroute initiative for this plan.',
                timer:      3000,
                showConfirmButton: false
            });

            loadEnrouteInits();
            $('#editenrouteinitModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Edited',
                text:   'There was an error in editing an enroute initiative for this plan.'
            });
        }
    });
});

// FUNC: deleteEnrouteInit [id:]
function deleteEnrouteInit(id) {
    $.ajax({
        type:   'POST',
        url:    'api/mgt/enroute_inits/delete',
        data:   {id: id},
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      'Successfully Deleted',
                text:       'You have successfully deleted an enroute initiative for this plan.',
                timer:      3000,
                showConfirmButton: false
            });

            loadEnrouteInits();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Deleted',
                text:   'There was an error in deleting an enroute initiative for this plan.'
            });
        }
    });
}

// FUNC: createEnrouteTime [id:]
function createEnrouteTime(init_id, time) {
    $.ajax({
        type:   'POST',
        url:    'api/mgt/enroute_inits/times/post',
        data:   {init_id: init_id, time: time},
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      'Successfully Created',
                text:       'You have successfully created an entry of enroute initiatives for this plan.',
                timer:      3000,
                showConfirmButton: false
            });

            loadEnrouteInits();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Created',
                text:   'There was an error in creating an entry of enroute initiatives for this plan.'
            });
        }
    });
}

// FUNC: changeEnrouteTime [id:]
function changeEnrouteTime(id, prob) {
    $.ajax({
        type:   'POST',
        url:    'api/mgt/enroute_inits/times/update',
        data:   {id: id, probability: prob},
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      'Successfully Updated',
                text:       'You have successfully updated an entry of enroute initiatives for this plan.',
                timer:      3000,
                showConfirmButton: false
            });

            loadEnrouteInits();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Updated',
                text:   'There was an error in updating an entry of enroute initiatives for this plan.'
            });
        }
    });
}

// FUNC: deleteEnrouteTime [id:]
function deleteEnrouteTime(id) {
    $.ajax({
        type:   'POST',
        url:    'api/mgt/enroute_inits/times/delete',
        data:   {id: id},
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      'Successfully Deleted',
                text:       'You have successfully deleted an entry of enroute initiatives for this plan.',
                timer:      3000,
                showConfirmButton: false
            });

            loadEnrouteInits();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Deleted',
                text:   'There was an error in deleting an entry of enroute initiatives for this plan.'
            });
        }
    });
}

// AJAX: #addenroutestaffing POST
$("#addenroutestaffing").submit(function(e) {
    e.preventDefault();

    var url = 'api/mgt/enroute_staffing/post';

    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize().replace(/'/g, "`"),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      'Successfully Added',
                text:       'You have successfully added an enroute staffing entry for this plan.',
                timer:      3000,
                showConfirmButton: false
            });

            loadEnrouteStaffing();
            $('#addenroutestaffingModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Added',
                text:   'There was an error in adding an enroute staffing entry for this plan.'
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

    var url = 'api/mgt/enroute_staffing/update';

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
                title:      'Successfully Deleted',
                text:       'You have successfully deleted an enroute staffing entry for this plan.',
                timer:      3000,
                showConfirmButton: false
            });

            loadEnrouteStaffing();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Deleted',
                text:   'There was an error in deleting an enroute staffing entry for this plan.'
            });
        }
    });
}

// addenrouteplanning Modal
$('#addenrouteplanningModal').on('show.bs.modal', function(event) {
    tinymce.get('aep_comments').setContent('') 
});

// AJAX: #addenrouteplanning POST
$("#addenrouteplanning").submit(function(e) {
    e.preventDefault();

    var url = 'api/mgt/enroute_planning/post';

    tinymce.triggerSave();

    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize().replace(/'/g, "`"),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      'Successfully Added',
                text:       'You have successfully added an enroute planning entry for this plan.',
                timer:      3000,
                showConfirmButton: false
            });

            loadEnroutePlanning();
            $('#addenrouteplanningModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Added',
                text:   'There was an error in adding an enroute planning entry for this plan.'
            });
        }
    });
});


// editenrouteplanning Modal
$('#editenrouteplanningModal').on('show.bs.modal', function(event) {
    var button = $(event.relatedTarget);

    var modal= $(this);

    modal.find('.modal-body #id').val(button.data('id'));
    modal.find('.modal-body #facility_name').val(button.data('facility_name')).trigger('change');
    tinymce.get('eep_comments').setContent(button.data('comments'))
});

// AJAX: #editenrouteplanning POST
$("#editenrouteplanning").submit(function(e) {
    e.preventDefault();

    var url = 'api/mgt/enroute_planning/update';

    tinymce.triggerSave();

    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize(),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      'Successfully Edited',
                text:       'You have successfully edited an enroute planning entry for this plan.',
                timer:      3000,
                showConfirmButton: false
            });

            loadEnroutePlanning();
            $('#editenrouteplanningModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Edited',
                text:   'There was an error in editing an enroute planning entry for this plan.'
            });
        }
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
                title:      'Successfully Deleted',
                text:       'You have successfully deleted an enroute planning entry for this plan.',
                timer:      3000,
                showConfirmButton: false
            });

            loadEnroutePlanning();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Deleted',
                text:   'There was an error in deleting an enroute planning entry for this plan.'
            });
        }
    });
}

// addenrouteconstraint Modal
$('#addenrouteconstraintModal').on('show.bs.modal', function(event) {
   // Init: Date Time Picker
   $(this).find('.modal-body #ae_date').datetimepicker({
        format: 'Y-m-d',
        inline: false,
        timepicker: false
    });
});

// AJAX: #addenrouteconstraint POST
$("#addenrouteconstraint").submit(function(e) {
    e.preventDefault();

    var url = 'api/mgt/enroute_constraints/post';

    tinymce.triggerSave();
    
    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize().replace(/'/g, "`"),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      'Successfully Added',
                text:       'You have successfully added an enroute constraint entry for this plan.',
                timer:      3000,
                showConfirmButton: false
            });

            loadEnrouteConstraints();
            $('#addenrouteconstraintModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Added',
                text:   'There was an error in adding an enroute constraint entry for this plan.'
            });
        }
    });
});


// editenrouteconstraint Modal
$('#editenrouteconstraintModal').on('show.bs.modal', function(event) {
    var button = $(event.relatedTarget);

    var modal= $(this);

    modal.find('.modal-body #id').val(button.data('id'));
    modal.find('.modal-body #location').val(button.data('location'));
    modal.find('.modal-body #context').val(button.data('context'));
    modal.find('.modal-body #impact').val(button.data('impact'));

    // Init: Date Time Picker
    $(this).find('.modal-body #ee_date').datetimepicker({
        format: 'Y-m-d',
        inline: false,
        timepicker: false,
        value: button.data('date')
    });
});

// AJAX: #editenrouteconstraints POST
$("#editenrouteconstraint").submit(function(e) {
    e.preventDefault();

    var url = 'api/mgt/enroute_constraints/update';

    tinymce.triggerSave();

    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize(),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      'Successfully Edited',
                text:       'You have successfully edited an enroute constraint entry for this plan.',
                timer:      3000,
                showConfirmButton: false
            });

            loadEnrouteConstraints();
            $('#editenrouteconstraintModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Edited',
                text:   'There was an error in editing an enroute constraint entry for this plan.'
            });
        }
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
                title:      'Successfully Deleted',
                text:       'You have successfully deleted an enroute constraint entry for this plan.',
                timer:      3000,
                showConfirmButton: false
            });

            loadEnrouteConstraints();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Deleted',
                text:   'There was an error in deleting an enroute constraint entry for this plan.'
            });
        }
    });
}

// AJAX: #addgroupflight POST
$("#addgroupflight").submit(function(e) {
    e.preventDefault();

    var url = 'api/mgt/group_flights/post';
    
    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize().replace(/'/g, "`"),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      'Successfully Added',
                text:       'You have successfully added a group flight entry for this plan.',
                timer:      3000,
                showConfirmButton: false
            });

            loadGroupFlights();
            $('#addgroupflightModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Added',
                text:   'There was an error in adding a group flight entry for this plan.'
            });
        }
    });
});


// editgroupflight Modal
$('#editgroupflightModal').on('show.bs.modal', function(event) {
    var button = $(event.relatedTarget);

    var modal= $(this);

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
$("#editgroupflight").submit(function(e) {
    e.preventDefault();

    var url = 'api/mgt/group_flights/update';

    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize(),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      'Successfully Edited',
                text:       'You have successfully edited a group flight entry for this plan.',
                timer:      3000,
                showConfirmButton: false
            });

            loadGroupFlights();
            $('#editgroupflightModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Edited',
                text:   'There was an error in editing a group flight entry entry for this plan.'
            });
        }
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
                title:      'Successfully Deleted',
                text:       'You have successfully deleted a group flight entry for this plan.',
                timer:      3000,
                showConfirmButton: false
            });

            loadGroupFlights();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Deleted',
                text:   'There was an error in deleting a group flight entry for this plan.'
            });
        }
    });
}