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
                bindOrgToggles();
                tooltips();
            });
        });
    });
}

loadData();

// Bind org membership checkbox toggles
function bindOrgToggles() {
    $(document).off('change', '.org-toggle').on('change', '.org-toggle', function() {
        var $cb = $(this);
        var cid = $cb.data('cid');
        var org = $cb.data('org');
        var action = $cb.is(':checked') ? 'add' : 'remove';

        $.ajax({
            type: 'POST',
            url: 'api/mgt/personnel/update_org',
            data: { cid: cid, org_code: org, action: action },
            success: function() {
                Swal.fire({
                    toast: true,
                    position: 'bottom-right',
                    icon: 'success',
                    title: action === 'add' ? PERTII18n.t('schedule.orgAdded') : PERTII18n.t('schedule.orgRemoved'),
                    timer: 2000,
                    showConfirmButton: false
                });
            },
            error: function(xhr) {
                // Revert checkbox
                $cb.prop('checked', !$cb.is(':checked'));
                var msg = PERTII18n.t('schedule.error.updateOrgFailed');
                try {
                    var resp = JSON.parse(xhr.responseText);
                    if (resp.error) msg = resp.error;
                } catch(e) {}
                Swal.fire({ icon: 'error', title: PERTII18n.t('common.error'), text: msg });
            }
        });
    });
}

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
                title:      PERTII18n.t('schedule.event.addSuccess'),
                text:       PERTII18n.t('schedule.event.addSuccessText'),
                timer:      3000,
                showConfirmButton: false,
            });

            loadData();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  PERTII18n.t('schedule.event.addFailed'),
                text:   PERTII18n.t('schedule.event.addFailedText'),
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
                title:      PERTII18n.t('schedule.assignment.editSuccess'),
                text:       PERTII18n.t('schedule.assignment.editSuccessText'),
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
                title:  PERTII18n.t('schedule.assignment.editFailed'),
                text:   PERTII18n.t('schedule.assignment.editFailedText'),
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
                title:      PERTII18n.t('schedule.event.removeSuccess'),
                text:       PERTII18n.t('schedule.event.removeSuccessText'),
                timer:      3000,
                showConfirmButton: false,
            });

            loadData();
        },
        error:function(data) {
            Swal.fire({
                icon:   'error',
                title:  PERTII18n.t('schedule.event.removeFailed'),
                text:   PERTII18n.t('schedule.event.removeFailedText'),
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
                title:      PERTII18n.t('schedule.personnel.addSuccess'),
                text:       PERTII18n.t('schedule.personnel.addSuccessText'),
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
                title:  PERTII18n.t('schedule.personnel.addFailed'),
                text:   PERTII18n.t('schedule.personnel.addFailedText'),
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
                title:      PERTII18n.t('schedule.personnel.removeSuccess'),
                text:       PERTII18n.t('schedule.personnel.removeSuccessText'),
                timer:      3000,
                showConfirmButton: false,
            });

            loadData();
        },
        error:function(xhr, status, error) {
            let errorMsg = PERTII18n.t('schedule.personnel.removeFailedText');
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
                title:  PERTII18n.t('schedule.personnel.removeFailed'),
                text:   errorMsg,
            });
        },
    });
}