const pathname = $(location).attr('href');
const uri = pathname.split('?');
const p_id = uri[1];

const summernoteFields = [
    'a_staffing',
    'a_tactical',
    'a_other',
    'a_perti',
    'a_ntml',
    'a_tmi',
    'a_ace',
    'e_staffing',
    'e_tactical',
    'e_other',
    'e_perti',
    'e_ntml',
    'e_tmi',
    'e_ace',
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

function loadScores() {
    $('[data-toggle="tooltip"]').tooltip('dispose');
    $.get(`api/data/review/scores?p_id=${p_id}`).done(function(data) {
        $('#scores').html(data);
        tooltips();
    });
}

function loadComments() {
    $('[data-toggle="tooltip"]').tooltip('dispose');
    $.get(`api/data/review/comments?p_id=${p_id}`).done(function(data) {
        $('#comments').html(data);
        tooltips();
    });
}

function loadData() {
    $('[data-toggle="tooltip"]').tooltip('dispose');
    $.get(`api/data/review/data?p_id=${p_id}`).done(function(data) {
        $('#data').html(data);
        tooltips();
    });
}

loadScores();
loadComments();
loadData();

// AJAX: #addscore POST
$('#addscore').submit(function(e) {
    e.preventDefault();

    const url = 'api/mgt/scores/post';

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
                text:       'You have successfully added the event scores.',
                timer:      3000,
                showConfirmButton: false,
            });

            loadScores();
            $('#addscoreModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Added',
                text:   'There was an error in adding these event scores.',
            });
        },
    });
});

// Edit Score Modal
$('#editscoreModal').on('show.bs.modal', function(event) {
    const button = $(event.relatedTarget);

    const modal= $(this);

    modal.find('.modal-body #id').val(button.data('id'));
    modal.find('.modal-body #staffing').val(button.data('staffing'));
    modal.find('.modal-body #tactical').val(button.data('tactical'));
    modal.find('.modal-body #other').val(button.data('other'));
    modal.find('.modal-body #perti').val(button.data('perti'));
    modal.find('.modal-body #ntml').val(button.data('ntml'));
    modal.find('.modal-body #tmi').val(button.data('tmi'));
    modal.find('.modal-body #ace').val(button.data('ace'));
});

// AJAX: #editscore POST
$('#editscore').submit(function(e) {
    e.preventDefault();

    const url = 'api/mgt/scores/update';

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
                text:       'You have successfully edited the event scores.',
                timer:      3000,
                showConfirmButton: false,
            });

            loadScores();
            $('#editscoreModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Edited',
                text:   'There was an error in editing event scores.',
            });
        },
    });
});

// FUNC: deleteScore [id:]
function deleteScore(id) {
    $.ajax({
        type:   'POST',
        url:    'api/mgt/scores/delete',
        data:   {id: id},
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      'Successfully Deleted',
                text:       'You have successfully deleted the selected the event scores.',
                timer:      3000,
                showConfirmButton: false,
            });

            loadScores();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Deleted',
                text:   'There was an error in deleting these event scores.',
            });
        },
    });
}


// AJAX: #addcomment POST
$('#addcomment').submit(function(e) {
    e.preventDefault();

    const url = 'api/mgt/comments/post';

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
                text:       'You have successfully added score comments.',
                timer:      3000,
                showConfirmButton: false,
            });

            loadComments();
            $('#addcommentModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Added',
                text:   'There was an error in adding these score comments.',
            });
        },
    });
});

// Edit Comment Modal
$('#editcommentModal').on('show.bs.modal', function(event) {
    const button = $(event.relatedTarget);

    const modal= $(this);

    modal.find('.modal-body #id').val(button.data('id'));
    $('#e_staffing').summernote('code', button.data('staffing'));
    $('#e_tactical').summernote('code', button.data('tactical'));
    $('#e_other').summernote('code', button.data('other'));
    $('#e_perti').summernote('code', button.data('perti'));
    $('#e_ntml').summernote('code', button.data('ntml'));
    $('#e_tmi').summernote('code', button.data('tmi'));
    $('#e_ace').summernote('code', button.data('ace'));
});

// AJAX: #editcomment POST
$('#editcomment').submit(function(e) {
    e.preventDefault();

    const url = 'api/mgt/comments/update';

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
                text:       'You have successfully edited the score comments.',
                timer:      3000,
                showConfirmButton: false,
            });

            loadComments();
            $('#editcommentModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Edited',
                text:   'There was an error in editing these score comments.',
            });
        },
    });
});

// FUNC: deleteComment [id:]
function deleteComment(id) {
    $.ajax({
        type:   'POST',
        url:    'api/mgt/comments/delete',
        data:   {id: id},
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      'Successfully Deleted',
                text:       'You have successfully deleted the selected the score comments.',
                timer:      3000,
                showConfirmButton: false,
            });

            loadComments();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Deleted',
                text:   'There was an error in deleting these score comments.',
            });
        },
    });
}

// AJAX: #adddata POST
$('#adddata').submit(function(e) {
    e.preventDefault();

    const url = 'api/mgt/event_data/post';

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
                text:       'You have successfully added event data.',
                timer:      3000,
                showConfirmButton: false,
            });

            loadData();
            $('#adddataModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Added',
                text:   'There was an error in adding this event data.',
            });
        },
    });
});

// Edit Data Modal
$('#editdataModal').on('show.bs.modal', function(event) {
    const button = $(event.relatedTarget);

    const modal= $(this);

    modal.find('.modal-body #id').val(button.data('id'));
    modal.find('.modal-body #summary').html(button.data('summary'));
    modal.find('.modal-body #source_url').val(button.data('source_url'));
    modal.find('.modal-body #image_url').val(button.data('image_url'));
});

// AJAX: #editdata POST
$('#editdata').submit(function(e) {
    e.preventDefault();

    const url = 'api/mgt/event_data/update';

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
                text:       'You have successfully edited this event data.',
                timer:      3000,
                showConfirmButton: false,
            });

            loadData();
            $('#editdataModal').modal('hide');
            $('.modal-backdrop').remove();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Edited',
                text:   'There was an error in editing this event data.',
            });
        },
    });
});

// FUNC: deleteData [id:]
function deleteData(id) {
    $.ajax({
        type:   'POST',
        url:    'api/mgt/event_data/delete',
        data:   {id: id},
        success:function(data) {
            Swal.fire({
                toast:      true,
                position:   'bottom-right',
                icon:       'success',
                title:      'Successfully Deleted',
                text:       'You have successfully deleted the selected the event data.',
                timer:      3000,
                showConfirmButton: false,
            });

            loadData();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  'Not Deleted',
                text:   'There was an error in deleting this event data.',
            });
        },
    });
}