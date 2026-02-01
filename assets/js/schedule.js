const pathname = $(location).attr('href');
const uri = pathname.split('?');
const p_id = uri[1];

function loadData() {
    $('[data-toggle="tooltip"]').tooltip('dispose');
    $.get('api/data/schedule').done(function(data) {
        $('#unassigned').html(data);
        $.get('api/data/schedule?assigned').done(function(data) {
            $('#assigned').html(data);
            $.get('api/data/personnel').done(function(data) {
                $('#personnel').html(data);
                tooltips();
            });
        });
    });
}

loadData();

// FUNC: schedule [id:, title:, date;]
function schedule(id, title, date) {
    $.ajax({
        type:   'POST',
        url:    'api/mgt/schedule/post',
        data:   {id: id, title: title, date: date},
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      'Successfully Added',
                text:       'You have successfully added this event to the schedule.',
                timer:      3000,
                showConfirmButton: false,
            });

            loadData();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Added',
                text:   'There was an error in adding this event to the schedule.',
            });
        },
    });
}

// Edit Data Modal
$('#editassignedModal').on('show.bs.modal', function(event) {
    const button = $(event.relatedTarget);

    const modal= $(this);

    modal.find('.modal-body #id').val(button.data('id'));

    modal.find('.modal-body #p_cid').val(button.data('p_cid'));
    modal.find('.modal-body #e_cid').val(button.data('e_cid'));
    modal.find('.modal-body #r_cid').val(button.data('r_cid'));
    modal.find('.modal-body #t_cid').val(button.data('t_cid'));
    modal.find('.modal-body #i_cid').val(button.data('i_cid'));
});

// AJAX: #editassigned POST
$('#editassigned').submit(function(e) {
    e.preventDefault();

    const url = 'api/mgt/schedule/update';

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
                text:       "You have successfully edited this event's team schedule.",
                timer:      3000,
                showConfirmButton: false,
            });

            loadData();
            $('#editassignedModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Edited',
                text:   "There was an error in editing this event's team schedule.",
            });
        },
    });
});

// FUNC: deleteEvent [id:]
function deleteEvent(id) {
    $.ajax({
        type:   'POST',
        url:    'api/mgt/schedule/delete',
        data:   {id: id},
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      'Successfully Removed',
                text:       'You have successfully removed this event from the schedule.',
                timer:      3000,
                showConfirmButton: false,
            });

            loadData();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Removed',
                text:   'There was an error in removing this event from the schedule.',
            });
        },
    });
}

// AJAX: #addpersonnel POST
$('#addpersonnel').submit(function(e) {
    e.preventDefault();

    const url = 'api/mgt/personnel/post';

    $.ajax({
        type:   'POST',
        url:    url,
        data:   $(this).serialize().replace(/'/g, '`'),
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      'Successfully Added',
                text:       'You have successfully added another personnel to the system.',
                timer:      3000,
                showConfirmButton: false,
            });

            loadData();
            $('#addpersonnelModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Added',
                text:   'There was an error in adding additional personnel.',
            });
        },
    });
});

// FUNC: deletePersonnel [id:]
function deletePersonnel(id) {
    $.ajax({
        type:   'POST',
        url:    'api/mgt/personnel/delete',
        data:   {id: id},
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      'Successfully Removed',
                text:       'You have successfully removed this personnel from the system.',
                timer:      3000,
                showConfirmButton: false,
            });

            loadData();
        },
        error:function(xhr, status, error) {
            let errorMsg = 'There was an error in removing this personnel from the system.';
            try {
                const response = JSON.parse(xhr.responseText);
                if (response.error) {
                    errorMsg = response.error;
                }
            } catch (e) {
                // Use default error message
            }
            Swal.fire({
                icon:   'error',
                title:  'Not Removed',
                text:   errorMsg,
            });
        },
    });
}