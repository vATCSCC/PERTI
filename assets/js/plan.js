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
            if (typeof opsPlanUpdateMessage === 'function') {
                opsPlanUpdateMessage();
            }

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
        if (typeof opsPlanUpdateMessage === 'function') {
            opsPlanUpdateMessage();
        }

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

    var url = 'api/mgt/terminal_inits/post';

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

    var url = 'api/mgt/terminal_inits/update';

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
        url:    'api/mgt/terminal_inits/delete',
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
        url:    'api/mgt/terminal_inits/times/post',
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
        url:    'api/mgt/terminal_inits/times/update',
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
        url:    'api/mgt/terminal_inits/times/delete',
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

    var url = 'api/mgt/terminal_staffing/post';

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

    var url = 'api/mgt/terminal_staffing/update';

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
        url:    'api/mgt/terminal_staffing/delete',
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

    var url = 'api/mgt/terminal_planning/post';

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

    var url = 'api/mgt/terminal_planning/update';

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
        url:    'api/mgt/terminal_planning/delete',
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

    var url = 'api/mgt/terminal_constraints/post';
    
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

    var url = 'api/mgt/terminal_constraints/update';

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
        url:    'api/mgt/terminal_constraints/delete',
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

    var url = 'api/mgt/enroute_initializations/post';

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

    var url = 'api/mgt/enroute_initializations/update';

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
        url:    'api/mgt/enroute_initializations/delete',
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
        url:    'api/mgt/enroute_initializations/times/post',
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
        url:    'api/mgt/enroute_initializations/times/update',
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
        url:    'api/mgt/enroute_initializations/times/delete',
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

function termInitStateDialog(options) {
    var mode        = options.mode; // 'create' or 'update'
    var init_id     = options.init_id || null;
    var time        = options.time || null;
    var id          = options.id || null;
    var currentProb = (typeof options.currentProb !== 'undefined') ? options.currentProb : null;

    var currentVal = '';
    if (currentProb !== null && typeof currentProb !== 'undefined') {
        var p = parseInt(currentProb, 10);
        if (!isNaN(p)) {
            currentVal = (p <= 3 ? p.toString() : '4');
        }
    }

    Swal.fire({
        title: 'Update Terminal Initiative',
        input: 'select',
        inputOptions: {
            '':  'Clear (no initiative)',
            '0': 'Critical Decision Window',
            '1': 'Possible',
            '2': 'Probable',
            '3': 'Expected',
            '4': 'Actual'
        },
        inputValue: currentVal,
        inputPlaceholder: 'Select initiative state',
        showCancelButton: true,
        confirmButtonText: 'Save'
    }).then(function(result) {
        if (!result.isConfirmed) {
            return;
        }

        var value = result.value;

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

function enrouteInitStateDialog(options) {
    var mode        = options.mode;
    var init_id     = options.init_id || null;
    var time        = options.time || null;
    var id          = options.id || null;
    var currentProb = (typeof options.currentProb !== 'undefined') ? options.currentProb : null;

    var currentVal = '';
    if (currentProb !== null && typeof currentProb !== 'undefined') {
        var p = parseInt(currentProb, 10);
        if (!isNaN(p)) {
            currentVal = (p <= 3 ? p.toString() : '4');
        }
    }

    Swal.fire({
        title: 'Update Enroute Initiative',
        input: 'select',
        inputOptions: {
            '':  'Clear (no initiative)',
            '0': 'Critical Decision Window',
            '1': 'Possible',
            '2': 'Probable',
            '3': 'Expected',
            '4': 'Actual'
        },
        inputValue: currentVal,
        inputPlaceholder: 'Select initiative state',
        showCancelButton: true,
        confirmButtonText: 'Save'
    }).then(function(result) {
        if (!result.isConfirmed) {
            return;
        }

        var value = result.value;

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

// Facilities used in the selector
const ADV_FACILITY_CODES = [
    'ZAB','ZAU','ZBW','ZDC','ZDV','ZFW','ZHU','ZID','ZJX','ZKC','ZLA','ZLC','ZMA','ZME','ZMP','ZNY','ZOA','ZOB','ZSE','ZTL',
    'CZE','CZM','CZU','CZV','CZW','CZY',
    'ZEU','ZMX','CAR'
];

const ADV_US_FACILITY_CODES = [
    'ZAB','ZAU','ZBW','ZDC','ZDV','ZFW','ZHU','ZID','ZJX','ZKC',
    'ZLA','ZLC','ZMA','ZME','ZMP','ZNY','ZOA','ZOB','ZSE','ZTL'
];

function pertiParseZuluTime(timeStr) {
    if (!timeStr) return null;
    timeStr = ('' + timeStr).trim();
    if (!timeStr) return null;

    if (timeStr.length === 3) timeStr = '0' + timeStr;
    if (timeStr.length !== 4) return null;

    var hh = parseInt(timeStr.slice(0, 2), 10);
    var mm = parseInt(timeStr.slice(2, 4), 10);
    if (isNaN(hh) || isNaN(mm)) return null;
    if (hh < 0 || hh > 23 || mm < 0 || mm > 59) return null;

    return { hh: hh, mm: mm };
}

function pertiDefaultEndDate(startDateStr, startTimeStr) {
    if (!startDateStr) return '';
    var t = pertiParseZuluTime(startTimeStr);
    if (!t) return startDateStr;

    var parts = startDateStr.split('-');
    if (parts.length !== 3) return startDateStr;
    var y = parseInt(parts[0], 10);
    var m = parseInt(parts[1], 10);
    var d = parseInt(parts[2], 10);
    if (isNaN(y) || isNaN(m) || isNaN(d)) return startDateStr;

    var dt = new Date(Date.UTC(y, m - 1, d, 0, 0, 0));

    var stVal = t.hh * 100 + t.mm;
    if (stVal >= 1800) {
        dt.setUTCDate(dt.getUTCDate() + 1);
    }

    var yy = dt.getUTCFullYear();
    var mm = (dt.getUTCMonth() + 1).toString().padStart(2, '0');
    var dd = dt.getUTCDate().toString().padStart(2, '0');
    return yy + '-' + mm + '-' + dd;
}




function opsPlanFormatMmDdYyyy(isoDate) {
    if (!isoDate) return '';
    var parts = isoDate.split('-');
    if (parts.length !== 3) return isoDate;
    return parts[1] + '/' + parts[2] + '/' + parts[0];
}

function opsPlanGetDayFromIso(isoDate) {
    if (!isoDate) return '__';
    var parts = isoDate.split('-');
    if (parts.length !== 3) return '__';
    return parts[2];
}

function opsPlanUpper(str) {
    if (str === undefined || str === null) return '';
    return String(str).toUpperCase();
}

function opsPlanLabelFromProbabilityTitle(title) {
    if (!title) return null;
    var t = String(title).toLowerCase().trim();

    // Ignore toggle-helper titles for both terminal and en route; treat them as "no probability"
    if (t.indexOf('toggle') === 0 ||
        t.indexOf('toggle terminal initiative') !== -1 ||
        t.indexOf('toggle enroute initiative') !== -1 ||
        t.indexOf('toggle en route initiative') !== -1 ||
        t.indexOf('toggle initiative') !== -1) {
        return null;
    }

    // Normalize common probability phrases
    if (t.indexOf('possible') !== -1) return 'POSSIBLE';
    if (t.indexOf('probable') !== -1) return 'PROBABLE';
    if (t.indexOf('expected') !== -1) return 'EXPECTED';

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
    var st = pertiParseZuluTime(startTimeStr);
    var et = pertiParseZuluTime(endTimeStr);
    if (!startDateStr || !st || !endDateStr || !et) return null;

    var sParts = startDateStr.split('-');
    var eParts = endDateStr.split('-');
    if (sParts.length !== 3 || eParts.length !== 3) return null;

    var sy = parseInt(sParts[0], 10);
    var sm = parseInt(sParts[1], 10);
    var sd = parseInt(sParts[2], 10);
    var ey = parseInt(eParts[0], 10);
    var em = parseInt(eParts[1], 10);
    var ed = parseInt(eParts[2], 10);
    if ([sy, sm, sd, ey, em, ed].some(isNaN)) return null;

    var startTs = Math.floor(Date.UTC(sy, sm - 1, sd, st.hh, st.mm, 0) / 1000);
    var endTs   = Math.floor(Date.UTC(ey, em - 1, ed, et.hh, et.mm, 0) / 1000);

    return { startTs: startTs, endTs: endTs };
}

function advInitFacilitiesDropdown() {
    var $grid = $('#advFacilitiesGrid');
    if (!$grid.length) return;

    // Build checkbox grid
    $grid.empty();
    ADV_FACILITY_CODES.forEach(function(code) {
        var id = 'advFacility_' + code;
        var $check = $('<input>')
            .attr('type', 'checkbox')
            .addClass('form-check-input')
            .attr('id', id)
            .attr('data-code', code)
            .val(code);

        var $label = $('<label>')
            .addClass('form-check-label')
            .attr('for', id)
            .text(code);

        var $wrapper = $('<div>').addClass('form-check');
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
            var code = ($(this).attr('data-code') || '').toString().toUpperCase();
            if (ADV_US_FACILITY_CODES.indexOf(code) !== -1) {
                $(this).prop('checked', true);
            } else {
                $(this).prop('checked', false);
            }
        });
    });

    $('#advFacilitiesApply').off('click').on('click', function(e) {
        e.stopPropagation();
        var selected = [];

        $('#advFacilitiesGrid input[type="checkbox"]:checked').each(function() {
            var code = ($(this).attr('data-code') || '').toString().toUpperCase();
            if (code) selected.push(code);
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
        var $wrap = $('.adv-facilities-wrapper');
        if (!$wrap.length) return;
        if (!$wrap.is(e.target) && $wrap.has(e.target).length === 0) {
            $('#advFacilitiesDropdown').hide();
        }
    });
}




function opsPlanSortTimeLines(lines) {
    if (!Array.isArray(lines) || lines.length <= 2) return;

    // Do not sort if section is effectively empty (header + NONE)
    if (lines.length === 2 && lines[1] === 'NONE') return;

    var header = lines[0];
    var rest = lines.slice(1);

    function getKey(line) {
        var match = line.match(/^(UNTIL|AFTER)\s+(\d{4})/);
        if (!match) {
            return { type: 2, time: 9999 }; // push non-timed lines to bottom
        }
        var type = (match[1] === 'UNTIL') ? 0 : 1; // UNTIL before AFTER
        var time = parseInt(match[2], 10);
        if (isNaN(time)) time = 9999;
        return { type: type, time: time };
    }

    rest.sort(function(a, b) {
        var ka = getKey(a);
        var kb = getKey(b);
        if (ka.type !== kb.type) return ka.type - kb.type;
        return ka.time - kb.time;
    });

    lines.length = 0;
    lines.push(header);
    Array.prototype.push.apply(lines, rest);
}


function opsPlanWrapSingleLine68(line) {
    var maxLen = 68;
    if (line == null) return [''];
    line = String(line);
    if (line.length <= maxLen) return [line];

    var result = [];

    // Detect a "list" prefix we want to preserve exactly (for hanging indent)
    var prefix = '';
    var rest = line;

    // Pattern for UNTIL/AFTER initiative lines: capture the full prefix including spaces and dash
    var m = line.match(/^(UNTIL|AFTER)\s+\d{4}\s+-\s*/);
    if (m) {
        prefix = m[0]; // exact characters, including spacing
        rest = line.substring(prefix.length);
    } else {
        // Generic "{something} - " style line
        var m2 = line.match(/^(\s*[^-]+-\s*)/);
        if (m2) {
            prefix = m2[1];
            rest = line.substring(prefix.length);
        }
    }

    var indentStr = '';
    if (prefix) {
        indentStr = ''.padStart(prefix.length, ' ');
    }

    // Split only the "rest" text into words; keep prefix as-is
    var words = rest.split(/\s+/);
    var idx = 0;
    var firstLine = true;

    while (idx < words.length) {
        var currentPrefix = firstLine ? prefix : indentStr;
        var avail = maxLen - currentPrefix.length;
        if (avail <= 0) {
            // Degenerate case: prefix itself exceeds max; hard-break prefix
            result.push(currentPrefix.substring(0, maxLen));
            currentPrefix = indentStr;
            avail = maxLen - currentPrefix.length;
        }

        var current = '';
        while (idx < words.length) {
            var w = words[idx];
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
    if (!Array.isArray(lines)) return lines;
    var out = [];
    for (var i = 0; i < lines.length; i++) {
        var pieces = opsPlanWrapSingleLine68(lines[i]);
        for (var j = 0; j < pieces.length; j++) {
            out.push(pieces[j]);
        }
    }
    lines.length = 0;
    Array.prototype.push.apply(lines, out);
    return lines;
}

function opsPlanUpdateMessage() {
    if (typeof PERTI_EVENT_NAME === 'undefined') return;

    var advNum    = ($('#opsAdvNum').val()    || '').trim();
    var advDate   = ($('#opsAdvDate').val()   || '').trim();
    var narrative = ($('#opsNarrative').val() || '').trim();

    // Event timing: prefer Ops Plan-specific inputs, then PERTI fields, then defaults
    var startDate = ($('#opsStartDate').val()  || '').trim();
    var startTime = ($('#opsStartTime').val()  || '').trim();
    var endDate   = ($('#opsEndDate').val()    || '').trim();
    var endTime   = ($('#opsEndTime').val()    || '').trim();

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

    var startDay = opsPlanGetDayFromIso(startDate);
    var endDay   = opsPlanGetDayFromIso(endDate);

    var headerAdvNum = advNum || '___';
    var headerDate   = advDate;
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
    var headerLines = [];
    headerLines.push('vATCSCC ADVZY ' + opsPlanUpper(headerAdvNum) + ' DCC ' + headerDate + ' OPERATIONS PLAN');
    headerLines.push('EVENT TIME: ' + opsPlanUpper((startDay || '__') + '/' + (startTime || '____') + ' - ' + (endDay || '__') + '/' + (endTime || '____')));
    headerLines.push('____________________________________________________________________');
    headerLines.push(opsPlanUpper(narrative || '[Add narrative here]'));
    headerLines.push('____________________________________________________________________');

    // STAFFING
    var staffingLines = [];
    staffingLines.push('STAFFING:');

    var nomNames = [];
    var ntmoNames = [];

    $('#dcc_table tr').each(function() {
        var $tds = $(this).find('td');
        if (!$tds.length) return;
        var ois  = ($tds.eq(0).text() || '').trim().toUpperCase();
        var name = ($tds.eq(1).text() || '').trim();
        var pos  = ($tds.eq(2).text() || '').trim();
        if (!ois || !name) return;

        var label = name;
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
        var $tds = $(this).find('td');
        if ($tds.length < 3) return;
        var fac  = ($tds.eq(0).text() || '').trim();
        var ois  = ($tds.eq(1).text() || '').trim();
        var name = ($tds.eq(2).text() || '').trim();
        if (!fac && !name) return;

        var line = '';
        if (fac) line += opsPlanUpper(fac) + ' - ';
        if (name) line += opsPlanUpper(name);
        if (ois) line += ' [' + opsPlanUpper(ois) + ']';
        if ($.trim(line).length) {
            staffingLines.push(line);
        }
    });

    if (staffingLines.length === 1) {
        staffingLines.push('NONE');
    }

    // TERMINAL CONSTRAINTS (from timeline)
    var termConstraintLines = [];
    termConstraintLines.push('TERMINAL CONSTRAINTS:');
    
    // Get terminal constraints from timeline
    var termTimelineData = (window.termInitTimeline && window.termInitTimeline.data) ? window.termInitTimeline.data : [];
    var termConstraints = termTimelineData.filter(function(item) {
        return item.level === 'Constraint_Terminal';
    });
    
    termConstraints.sort(function(a, b) {
        return (a.start_datetime || '').localeCompare(b.start_datetime || '');
    });
    
    termConstraints.forEach(function(item) {
        var startTime = opsPlanFormatTmiTime(item.start_datetime);
        var endTime = opsPlanFormatTmiTime(item.end_datetime);
        var loc = opsPlanUpper(item.facility || '');
        var cause = item.tmi_type || '';
        if (cause === 'Other' && item.tmi_type_other) {
            cause = item.tmi_type_other;
        }
        var impact = item.cause || '';
        
        var line = startTime + '-' + endTime + ' ' + loc;
        if (cause) line += ' ' + opsPlanUpper(cause);
        if (impact) line += ' [' + opsPlanUpper(impact) + ']';
        termConstraintLines.push(line);
    });

    if (termConstraintLines.length === 1) {
        termConstraintLines.push('NONE');
    }

    // EN ROUTE CONSTRAINTS (from timeline)
    var enrouteConstraintLines = [];
    enrouteConstraintLines.push('EN ROUTE CONSTRAINTS:');
    
    // Get enroute constraints from timeline
    var enrouteTimelineData = (window.enrouteInitTimeline && window.enrouteInitTimeline.data) ? window.enrouteInitTimeline.data : [];
    var enrouteConstraints = enrouteTimelineData.filter(function(item) {
        return item.level === 'Constraint_EnRoute';
    });
    
    enrouteConstraints.sort(function(a, b) {
        return (a.start_datetime || '').localeCompare(b.start_datetime || '');
    });
    
    enrouteConstraints.forEach(function(item) {
        var startTime = opsPlanFormatTmiTime(item.start_datetime);
        var endTime = opsPlanFormatTmiTime(item.end_datetime);
        var loc = opsPlanUpper(item.facility || '');
        var cause = item.tmi_type || '';
        if (cause === 'Other' && item.tmi_type_other) {
            cause = item.tmi_type_other;
        }
        var impact = item.cause || '';
        
        var line = startTime + '-' + endTime + ' ' + loc;
        if (cause) line += ' ' + opsPlanUpper(cause);
        if (impact) line += ' [' + opsPlanUpper(impact) + ']';
        enrouteConstraintLines.push(line);
    });

    if (enrouteConstraintLines.length === 1) {
        enrouteConstraintLines.push('NONE');
    }

    // Helper: Format datetime from MySQL/ISO format to DD/HHMM format
    function opsPlanFormatTmiTime(datetime) {
        if (!datetime) return '__/____';
        var dtStr = String(datetime).trim();
        var match = dtStr.match(/(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/);
        if (!match) return '__/____';
        var day = match[3];
        var hour = match[4];
        var minute = match[5];
        return day + '/' + hour + minute;
    }

    // Helper: Build TMI description string
    function opsPlanBuildTmiDesc(item) {
        var parts = [];
        parts.push(opsPlanUpper(item.facility || ''));
        
        // TMI type
        var tmiType = item.tmi_type || '';
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
        
        var desc = parts.join(' ');
        
        // Add cause in brackets if present
        if (item.cause) {
            desc += ' [' + opsPlanUpper(item.cause) + ']';
        }
        
        return desc;
    }

    // Helper: Build TMI lines from timeline data
    function buildTmiLinesFromTimeline(timelineData, levelFilter) {
        var result = [];
        if (!timelineData || !Array.isArray(timelineData)) return result;
        
        var filtered = timelineData.filter(function(item) {
            return levelFilter.indexOf(item.level) !== -1;
        });
        
        // Sort by start time
        filtered.sort(function(a, b) {
            return (a.start_datetime || '').localeCompare(b.start_datetime || '');
        });
        
        filtered.forEach(function(item) {
            var startTime = opsPlanFormatTmiTime(item.start_datetime);
            var endTime = opsPlanFormatTmiTime(item.end_datetime);
            var desc = opsPlanBuildTmiDesc(item);
            
            // For planned items (Possible/Probable/Expected), include probability
            var probLabel = '';
            if (item.level === 'Possible') probLabel = ' POSSIBLE';
            else if (item.level === 'Probable') probLabel = ' PROBABLE';
            else if (item.level === 'Expected') probLabel = ' EXPECTED';
            
            var line = startTime + '-' + endTime + ' -' + desc + probLabel;
            result.push(line);
        });
        
        return result;
    }

    // Helper: Build Advisory lines from timeline data
    function buildAdvisoryLinesFromTimeline(timelineData, levelFilter) {
        var result = [];
        if (!timelineData || !Array.isArray(timelineData)) return result;
        
        var filtered = timelineData.filter(function(item) {
            return levelFilter.indexOf(item.level) !== -1;
        });
        
        filtered.sort(function(a, b) {
            return (a.start_datetime || '').localeCompare(b.start_datetime || '');
        });
        
        filtered.forEach(function(item) {
            var startTime = opsPlanFormatTmiTime(item.start_datetime);
            var endTime = opsPlanFormatTmiTime(item.end_datetime);
            var facility = opsPlanUpper(item.facility || '');
            var tmiType = item.tmi_type || '';
            if (tmiType === 'Other' && item.tmi_type_other) {
                tmiType = item.tmi_type_other;
            }
            
            var advzyNum = item.advzy_number ? ' ADVZY ' + opsPlanUpper(item.advzy_number) : '';
            var desc = facility;
            if (tmiType) desc += ' ' + opsPlanUpper(tmiType);
            desc += advzyNum;
            
            if (item.cause) {
                desc += ' [' + opsPlanUpper(item.cause) + ']';
            }
            
            var line = startTime + '-' + endTime + ' -' + desc;
            result.push(line);
        });
        
        return result;
    }

    // Helper: Build VIP/Space/Special Event lines from timeline data
    function buildSpecialLinesFromTimeline(timelineData, levelFilter) {
        var result = [];
        if (!timelineData || !Array.isArray(timelineData)) return result;
        
        var filtered = timelineData.filter(function(item) {
            return levelFilter.indexOf(item.level) !== -1;
        });
        
        filtered.sort(function(a, b) {
            return (a.start_datetime || '').localeCompare(b.start_datetime || '');
        });
        
        filtered.forEach(function(item) {
            var startTime = opsPlanFormatTmiTime(item.start_datetime);
            var endTime = opsPlanFormatTmiTime(item.end_datetime);
            var facility = opsPlanUpper(item.facility || '');
            var tmiType = item.tmi_type || '';
            if (tmiType === 'Other' && item.tmi_type_other) {
                tmiType = item.tmi_type_other;
            }
            
            var desc = facility;
            if (tmiType) desc += ' ' + opsPlanUpper(tmiType);
            if (item.area) desc += ' ' + opsPlanUpper(item.area);
            
            if (item.notes) {
                desc += ' [' + opsPlanUpper(item.notes) + ']';
            } else if (item.cause) {
                desc += ' [' + opsPlanUpper(item.cause) + ']';
            }
            
            var line = startTime + '-' + endTime + ' -' + desc;
            result.push(line);
        });
        
        return result;
    }

    // Get timeline data from the timeline objects
    var termTimelineData = (window.termInitTimeline && window.termInitTimeline.data) ? window.termInitTimeline.data : [];
    var enrouteTimelineData = (window.enrouteInitTimeline && window.enrouteInitTimeline.data) ? window.enrouteInitTimeline.data : [];

    // TERMINAL ACTIVE
    var termActiveLines = [];
    termActiveLines.push('TERMINAL ACTIVE:');
    var termActiveTmis = buildTmiLinesFromTimeline(termTimelineData, ['Active']);
    if (termActiveTmis.length) {
        termActiveTmis.forEach(function(l) { termActiveLines.push(l); });
    } else {
        termActiveLines.push('NONE');
    }

    // TERMINAL PLANNED (Possible, Probable, Expected)
    var termPlannedLines = [];
    termPlannedLines.push('TERMINAL PLANNED:');
    var termPlannedTmis = buildTmiLinesFromTimeline(termTimelineData, ['Possible', 'Probable', 'Expected']);
    if (termPlannedTmis.length) {
        termPlannedTmis.forEach(function(l) { termPlannedLines.push(l); });
    }
    // Also include planning comments from old UI if present
    $('#termplanningdata .card').each(function() {
        var fac = $(this).find('.card-title').text().trim();
        var comments = $(this).find('p').text().trim();
        if (!fac && !comments) return;
        var line = '__/____-__/____ -' + opsPlanUpper(fac);
        if (comments) {
            line += ' [' + opsPlanUpper(comments) + ']';
        }
        termPlannedLines.push(line);
    });
    if (termPlannedLines.length === 1) {
        termPlannedLines.push('NONE');
    }


    // Sort TERMINAL PLANNED lines by time
    opsPlanSortTimeLines(termPlannedLines);

    // EN ROUTE ACTIVE
    var enrouteActiveLines = [];
    enrouteActiveLines.push('EN ROUTE ACTIVE:');
    var enrouteActiveTmis = buildTmiLinesFromTimeline(enrouteTimelineData, ['Active']);
    if (enrouteActiveTmis.length) {
        enrouteActiveTmis.forEach(function(l) { enrouteActiveLines.push(l); });
    } else {
        enrouteActiveLines.push('NONE');
    }

    // EN ROUTE PLANNED (Possible, Probable, Expected)
    var enroutePlannedLines = [];
    enroutePlannedLines.push('EN ROUTE PLANNED:');
    var enroutePlannedTmis = buildTmiLinesFromTimeline(enrouteTimelineData, ['Possible', 'Probable', 'Expected']);
    if (enroutePlannedTmis.length) {
        enroutePlannedTmis.forEach(function(l) { enroutePlannedLines.push(l); });
    }
    // Also include planning comments from old UI if present
    $('#enrouteplanningdata .card').each(function() {
        var fac = $(this).find('.card-title').text().trim();
        var comments = $(this).find('p').text().trim();
        if (!fac && !comments) return;
        var line = '__/____-__/____ -' + opsPlanUpper(fac);
        if (comments) {
            line += ' [' + opsPlanUpper(comments) + ']';
        }
        enroutePlannedLines.push(line);
    });
    if (enroutePlannedLines.length === 1) {
        enroutePlannedLines.push('NONE');
    }


    // Sort EN ROUTE PLANNED lines by time
    opsPlanSortTimeLines(enroutePlannedLines);

    // VIP MOVEMENTS (from both terminal and enroute timelines)
    var vipLines = [];
    vipLines.push('VIP MOVEMENTS:');
    var allTimelineData = termTimelineData.concat(enrouteTimelineData);
    var vipTmis = buildSpecialLinesFromTimeline(allTimelineData, ['VIP']);
    if (vipTmis.length) {
        vipTmis.forEach(function(l) { vipLines.push(l); });
    } else {
        vipLines.push('NONE');
    }

    // SPACE OPERATIONS (from both timelines)
    var spaceLines = [];
    spaceLines.push('SPACE OPERATIONS:');
    var spaceTmis = buildSpecialLinesFromTimeline(allTimelineData, ['Space_Op']);
    if (spaceTmis.length) {
        spaceTmis.forEach(function(l) { spaceLines.push(l); });
    } else {
        spaceLines.push('NONE');
    }

    // SPECIAL EVENTS (from both timelines)
    var specialEventLines = [];
    specialEventLines.push('SPECIAL EVENTS:');
    var specialTmis = buildSpecialLinesFromTimeline(allTimelineData, ['Special_Event']);
    if (specialTmis.length) {
        specialTmis.forEach(function(l) { specialEventLines.push(l); });
    } else {
        specialEventLines.push('NONE');
    }

    // ADVISORIES (Terminal and EnRoute)
    var advisoryLines = [];
    advisoryLines.push('ADVISORIES:');
    var advisoryTmis = buildAdvisoryLinesFromTimeline(allTimelineData, ['Advisory_Terminal', 'Advisory_EnRoute']);
    if (advisoryTmis.length) {
        advisoryTmis.forEach(function(l) { advisoryLines.push(l); });
    } else {
        advisoryLines.push('NONE');
    }

    // CDRS/SWAP/... and SIRs (still default to NONE for now)
    var cdrLines = [];
    cdrLines.push('CDRS/SWAP/CAPPING/TUNNELING/HOTLINE/DIVERSION RECOVERY:');
    cdrLines.push('NONE');

    var sirLines = [];
    sirLines.push('RUNWAY/EQUIPMENT/POSSIBLE SYSTEM IMPACT REPORTS (SIRs):');
    sirLines.push('NONE');

    // Footer time summary
    var footerLines = [];
    footerLines.push(opsPlanUpper((startDay || '__') + (startTime || '____') + '-' + (endDay || '__') + (endTime || '____')));

    var now = new Date();
    var yy  = String(now.getUTCFullYear()).slice(-2);
    var mm  = String(now.getUTCMonth() + 1).toString().padStart(2, '0');
    var dd  = String(now.getUTCDate()).toString().padStart(2, '0');
    var hh  = String(now.getUTCHours()).toString().padStart(2, '0');
    var mn  = String(now.getUTCMinutes()).toString().padStart(2, '0');
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

var sections = [
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
        { name: 'FOOTER', lines: footerLines }
    ];

    var parts = [];
    var currentLines = [];
    var currentLen = 0;
    var maxLen = 2000;

    function flushPart() {
        if (!currentLines.length) return;
        parts.push(currentLines.join('\n'));
        currentLines = [];
        currentLen = 0;
    }

    sections.forEach(function(sec, idx) {
        var secText = sec.lines.join('\n');
        var secLen = secText.length;
        var extraNewlines = currentLines.length ? 1 : 0; // one blank line between sections

        if (currentLen + extraNewlines + secLen > maxLen && currentLines.length) {
            flushPart();
        }

        if (currentLines.length) {
            currentLines.push('');
            currentLen += 1;
        }

        for (var i = 0; i < sec.lines.length; i++) {
            var line = sec.lines[i];
            currentLines.push(line);
            currentLen += line.length;
            if (i < sec.lines.length - 1) {
                currentLen += 1; // newline
            }
        }
    });

    flushPart();

    var finalText = '';
    if (parts.length <= 1) {
        finalText = parts[0] || '';
    } else {
        for (var p = 0; p < parts.length; p++) {
            var label = '(PART ' + (p + 1) + ' OF ' + parts.length + ')';
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
    var evDate    = (typeof PERTI_EVENT_DATE     !== 'undefined' ? (PERTI_EVENT_DATE     || '') : '');
    var evStart   = (typeof PERTI_EVENT_START    !== 'undefined' ? (PERTI_EVENT_START    || '') : '');
    var evEndDate = (typeof PERTI_EVENT_END_DATE !== 'undefined' ? (PERTI_EVENT_END_DATE || '') : '');
    var evEndTime = (typeof PERTI_EVENT_END_TIME !== 'undefined' ? (PERTI_EVENT_END_TIME || '') : '');

    if ($('#opsStartDate').val().trim() === '') {
        var sd = ($('#pertiStartDate').val() || '').trim() || evDate;
        if (sd) $('#opsStartDate').val(sd);
    }
    if ($('#opsStartTime').val().trim() === '') {
        var st = ($('#pertiStartTime').val() || '').trim() || evStart;
        if (st) $('#opsStartTime').val(st);
    }
    if ($('#opsEndDate').val().trim() === '') {
        var ed = ($('#pertiEndDate').val() || '').trim() || evEndDate;
        if (!ed) {
            var sd2 = ($('#opsStartDate').val() || '').trim();
            var st2 = ($('#opsStartTime').val() || '').trim();
            if (sd2 && typeof pertiDefaultEndDate === 'function') {
                ed = pertiDefaultEndDate(sd2, st2);
            }
        }
        if (ed) $('#opsEndDate').val(ed);
    }
    if ($('#opsEndTime').val().trim() === '') {
        var et = ($('#pertiEndTime').val() || '').trim() || evEndTime;
        if (et) $('#opsEndTime').val(et);
    }

    opsPlanUpdateMessage();
    $('#opsPlanModal').modal('show');
}

function opsPlanInitBindings() {
    $(document).on('input change', '#opsAdvNum, #opsAdvDate, #opsNarrative, #opsStartDate, #opsStartTime, #opsEndDate, #opsEndTime', function() {
        opsPlanUpdateMessage();
    });

    $(document).on('click', '#opsPlanCopyBtn', function() {
        var $ta = $('#opsPlanMessage');
        if (!$ta.length) return;
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
    if (typeof PERTI_EVENT_NAME === 'undefined') return;

    var eventName = PERTI_EVENT_NAME || '';
    var opLevel   = (typeof PERTI_OPLEVEL !== 'undefined' ? (PERTI_OPLEVEL || '') : '');
    var planNumber = (typeof PERTI_PLAN_ID !== 'undefined')
        ? PERTI_PLAN_ID
        : (typeof p_id !== 'undefined' ? p_id : '');

    var startDate = ($('#pertiStartDate').val() || '').trim();
    var startTime = ($('#pertiStartTime').val() || '').trim();
    var endDate   = ($('#pertiEndDate').val()   || '').trim();
    var endTime   = ($('#pertiEndTime').val()   || '').trim();

    var startZ = startTime ? (startTime + 'Z') : '____Z';
    var endZ   = endTime   ? (endTime   + 'Z') : '____Z';

    var lines = [];

    lines.push(eventName + ' | TMU OpLevel ' + opLevel + ' | PERTI Data Request');

    if (startDate && endDate && startDate === endDate) {
        lines.push(eventName + ' is on ' + startDate + ' from ' + startZ + ' to ' + endZ + '.');
    } else {
        var sdText = startDate || '____';
        var edText = endDate   || '____';
        lines.push(eventName + ' is from ' + sdText + ' ' + startZ + ' to ' + edText + ' ' + endZ + '.');
    }

    var ts = pertiComputeDiscordTimestamps(startDate, startTime, endDate, endTime);
    if (ts && ts.startTs && ts.endTs) {
        lines.push('Start: <t:' + ts.startTs + ':F> (<t:' + ts.startTs + ':R>) in your timezone');
        lines.push('End:   <t:' + ts.endTs + ':F> (<t:' + ts.endTs + ':R>) in your timezone');
    } else {
        lines.push('Start: <t:discord_timestamp_start:F> (<t:discord_timestamp_start:R>) in your timezone');
        lines.push('End:   <t:discord_timestamp_end:F> (<t:discord_timestamp_end:R>) in your timezone');
    }

    lines.push('');

    lines.push('Review and fill out the PERTI Plan:');
    lines.push('[PERTI Plan](https://perti.vatcscc.org/plan?' + planNumber + ')');
    lines.push('[Staffing Data](https://perti.vatcscc.org/data?' + planNumber + ')');
    lines.push('[PERTI NAS Operations Dashboard (NOD)](https://perti.vatcscc.org/nod)');
    lines.push('[PERTI Active Splits](https://perti.vatcscc.org/splits)');
    lines.push('[PERTI Ground Delay Tool (GDT)](https://perti.vatcscc.org/gdt)');
    lines.push('[PERTI Route Mapper](https://perti.vatcscc.org/route)');
    lines.push('[Field Configs (VATSIM-applied AAR)](https://perti.vatcscc.org/configs)');
    lines.push('[DCC Dashboard](https://docs.google.com/spreadsheets/d/1sps5ggCvSnsORlChliWsPsYD4Tl0yhUJfsTLynHq3TY/edit?usp=sharing)');
    lines.push('');

    var facilitiesText = ($('#advFacilities').val() || '').trim();
    if (facilitiesText) {
        var parts = facilitiesText.split(/[\/\s,]+/);
        var codes = [];
        parts.forEach(function(p) {
            p = (p || '').trim();
            if (!p) return;
            codes.push(p.toUpperCase());
        });
        if (codes.length) {
            codes.sort();
            var facLines = [];
            codes.forEach(function(code) {
                facLines.push(code + ':');
            });
            lines.push(facLines.join('\n'));
            lines.push('');
        }
    }

    lines.push('Attempt to coordinate as many plans (initiatives, reroutes, etc.) in a timely manner, and fill out all appropriate areas of the staffing data.');
    lines.push('---------------------------------------------------------');
    lines.push('<@&1268395359714021396> please react with your availability to NOM for this event.');
    lines.push('<@&1268395210665361478> please react with your availability to shadow this event.');
    lines.push('');
    lines.push('ðŸŸ¢ = Available');
    lines.push('ðŸŸ¡ = Partially available/unsure');
    lines.push('ðŸ”´ = Unavailable');
    lines.push('---------------------------------------------------------');

    $('#pertiMessage').val(lines.join('\n'));
}

function openPertiModal() {
    if (typeof advInitFacilitiesDropdown === 'function') {
        advInitFacilitiesDropdown();
    }

    var $startDate = $('#pertiStartDate');
    var $startTime = $('#pertiStartTime');
    var $endDate   = $('#pertiEndDate');
    var $endTime   = $('#pertiEndTime');

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
            var sd = $startDate.val().trim();
            var st = $startTime.val().trim();
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
        var $endDate = $('#pertiEndDate');
        if ($endDate.val().trim() === '') {
            var sd = ($('#pertiStartDate').val() || '').trim();
            var st = ($('#pertiStartTime').val() || '').trim();
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
        var $ta = $('#pertiMessage');
        if (!$ta.length) return;
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

