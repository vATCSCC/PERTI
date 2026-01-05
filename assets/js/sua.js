/**
 * SUA/TFR Management Page JavaScript
 */

// Type display names
const TYPE_NAMES = {
    'P': 'Prohibited',
    'R': 'Restricted',
    'W': 'Warning',
    'A': 'Alert',
    'MOA': 'MOA',
    'NSA': 'NSA',
    'ATCAA': 'ATCAA',
    'IR': 'IR',
    'VR': 'VR',
    'SR': 'SR',
    'AR': 'AR',
    'TFR': 'TFR',
    'OTHER': 'Other'
};

// Tooltip helper
function tooltips() {
    $('[data-toggle="tooltip"]').tooltip('dispose');
    $(function () {
        $('[data-toggle="tooltip"]').tooltip();
    });
}

// Load activations table
function loadActivations() {
    var status = $('#statusFilter').val();
    var url = 'api/data/sua/activations.l.php';
    if (status) {
        url += '?status=' + status;
    }

    $.get(url).done(function(data) {
        $('#activations_table').html(data);
        tooltips();
    }).fail(function() {
        $('#activations_table').html('<tr><td colspan="8" class="text-center text-danger">Failed to load activations</td></tr>');
    });
}

// Load SUA browser table
function loadSuaBrowser() {
    var search = $('#suaSearch').val();
    var type = $('#suaTypeFilter').val();
    var artcc = $('#suaArtccFilter').val();

    var params = [];
    if (search) params.push('search=' + encodeURIComponent(search));
    if (type) params.push('type=' + type);
    if (artcc) params.push('artcc=' + artcc);

    var url = 'api/data/sua/sua_list.php';
    if (params.length > 0) {
        url += '?' + params.join('&');
    }

    $.getJSON(url).done(function(response) {
        if (response.status === 'ok') {
            var html = '';
            var data = response.data;
            $('#sua_count').text(response.count);

            if (data.length === 0) {
                html = '<tr><td colspan="7" class="text-center text-muted">No SUAs found</td></tr>';
            } else {
                data.forEach(function(sua) {
                    var typeName = TYPE_NAMES[sua.suaType] || sua.suaType;
                    var altDisplay = (sua.lowerLimit || '-') + ' - ' + (sua.upperLimit || '-');

                    html += '<tr>';
                    html += '<td><span class="badge badge-primary">' + typeName + '</span></td>';
                    html += '<td class="text-monospace">' + (sua.designator || '-') + '</td>';
                    html += '<td>' + (sua.name || '-') + '</td>';
                    html += '<td>' + (sua.artcc || '-') + '</td>';
                    html += '<td class="small">' + altDisplay + '</td>';
                    html += '<td class="small">' + (sua.scheduleDesc || sua.schedule || '-') + '</td>';
                    html += '<td>';
                    html += '<button class="btn btn-sm btn-success activation-btn" onclick="openScheduleModal(\'' +
                            escapeHtml(sua.designator || '') + '\', \'' +
                            escapeHtml(sua.suaType || '') + '\', \'' +
                            escapeHtml(sua.name || '') + '\', \'' +
                            escapeHtml(sua.artcc || '') + '\', \'' +
                            escapeHtml(sua.lowerLimit || '') + '\', \'' +
                            escapeHtml(sua.upperLimit || '') + '\')">';
                    html += '<i class="fas fa-plus"></i> Activate</button>';
                    html += '</td>';
                    html += '</tr>';
                });
            }

            $('#sua_browser_table').html(html);
        } else {
            $('#sua_browser_table').html('<tr><td colspan="7" class="text-center text-danger">Error loading SUAs</td></tr>');
        }
    }).fail(function() {
        $('#sua_browser_table').html('<tr><td colspan="7" class="text-center text-danger">Failed to load SUAs</td></tr>');
    });
}

// Escape HTML for safe insertion
function escapeHtml(text) {
    if (!text) return '';
    return text.replace(/[&<>"']/g, function(m) {
        return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[m];
    });
}

// Open schedule modal with SUA data
function openScheduleModal(designator, suaType, name, artcc, lowerAlt, upperAlt) {
    $('#schedule_sua_id').val(designator);
    $('#schedule_sua_type').val(suaType);
    $('#schedule_name').val(name);
    $('#schedule_artcc').val(artcc);
    $('#schedule_lower_alt').val(lowerAlt);
    $('#schedule_upper_alt').val(upperAlt);

    // Set default times (now to now+2hours)
    var now = new Date();
    var end = new Date(now.getTime() + 2 * 60 * 60 * 1000);

    $('#schedule_start').val(formatDateTimeLocal(now));
    $('#schedule_end').val(formatDateTimeLocal(end));

    $('#scheduleModal').modal('show');
}

// Format date for datetime-local input
function formatDateTimeLocal(date) {
    var year = date.getUTCFullYear();
    var month = String(date.getUTCMonth() + 1).padStart(2, '0');
    var day = String(date.getUTCDate()).padStart(2, '0');
    var hours = String(date.getUTCHours()).padStart(2, '0');
    var minutes = String(date.getUTCMinutes()).padStart(2, '0');
    return year + '-' + month + '-' + day + 'T' + hours + ':' + minutes;
}

// Cancel activation
function cancelActivation(id, name) {
    Swal.fire({
        title: 'Cancel Activation?',
        text: 'Are you sure you want to cancel "' + name + '"?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, cancel it'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                type: 'POST',
                url: 'api/mgt/sua/delete.php',
                data: { id: id },
                success: function(data) {
                    Swal.fire({
                        toast: true,
                        position: 'bottom-right',
                        icon: 'success',
                        title: 'Activation cancelled',
                        timer: 3000,
                        showConfirmButton: false
                    });
                    loadActivations();
                },
                error: function() {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Failed to cancel activation'
                    });
                }
            });
        }
    });
}

// Generate circular TFR geometry
function generateCircularGeometry(lat, lon, radiusNM) {
    var radiusMeters = radiusNM * 1852; // Convert NM to meters
    var points = 36; // Number of points in circle
    var coordinates = [];

    for (var i = 0; i <= points; i++) {
        var angle = (i / points) * 2 * Math.PI;
        // Approximate offset (simplified, good enough for display)
        var dLat = (radiusMeters / 111320) * Math.cos(angle);
        var dLon = (radiusMeters / (111320 * Math.cos(lat * Math.PI / 180))) * Math.sin(angle);
        coordinates.push([lon + dLon, lat + dLat]);
    }

    return {
        type: 'Polygon',
        coordinates: [coordinates]
    };
}

// Document ready
$(document).ready(function() {
    // Initial load
    loadActivations();
    loadSuaBrowser();

    // Status filter change
    $('#statusFilter').change(function() {
        loadActivations();
    });

    // Search on enter key
    $('#suaSearch').keypress(function(e) {
        if (e.which === 13) {
            loadSuaBrowser();
        }
    });

    // Schedule form submission
    $('#scheduleForm').submit(function(e) {
        e.preventDefault();

        $.ajax({
            type: 'POST',
            url: 'api/mgt/sua/activate.php',
            data: $(this).serialize(),
            success: function(data) {
                Swal.fire({
                    toast: true,
                    position: 'bottom-right',
                    icon: 'success',
                    title: 'Activation scheduled',
                    timer: 3000,
                    showConfirmButton: false
                });
                loadActivations();
                $('#scheduleModal').modal('hide');
                $('.modal-backdrop').remove();
                $('#scheduleForm')[0].reset();
            },
            error: function(xhr) {
                var msg = 'Failed to schedule activation';
                try {
                    var resp = JSON.parse(xhr.responseText);
                    if (resp.message) msg = resp.message;
                } catch (e) {}
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: msg
                });
            }
        });
    });

    // TFR form submission
    $('#tfrForm').submit(function(e) {
        e.preventDefault();

        var formData = $(this).serialize();

        // Generate geometry if coordinates provided
        var lat = parseFloat($('#tfr_lat').val());
        var lon = parseFloat($('#tfr_lon').val());
        var radius = parseFloat($('#tfr_radius').val());

        if (!isNaN(lat) && !isNaN(lon) && !isNaN(radius) && radius > 0) {
            var geometry = generateCircularGeometry(lat, lon, radius);
            formData += '&geometry=' + encodeURIComponent(JSON.stringify(geometry));
        }

        $.ajax({
            type: 'POST',
            url: 'api/mgt/sua/tfr_create.php',
            data: formData,
            success: function(data) {
                Swal.fire({
                    toast: true,
                    position: 'bottom-right',
                    icon: 'success',
                    title: 'TFR created',
                    timer: 3000,
                    showConfirmButton: false
                });
                loadActivations();
                $('#tfrModal').modal('hide');
                $('.modal-backdrop').remove();
                $('#tfrForm')[0].reset();
            },
            error: function(xhr) {
                var msg = 'Failed to create TFR';
                try {
                    var resp = JSON.parse(xhr.responseText);
                    if (resp.message) msg = resp.message;
                } catch (e) {}
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: msg
                });
            }
        });
    });

    // Edit modal population
    $('#editModal').on('show.bs.modal', function(event) {
        var button = $(event.relatedTarget);
        var modal = $(this);

        modal.find('#edit_id').val(button.data('id'));
        modal.find('#edit_name').val(button.data('name'));
        modal.find('#edit_start').val(button.data('start'));
        modal.find('#edit_end').val(button.data('end'));
        modal.find('#edit_lower_alt').val(button.data('lower-alt'));
        modal.find('#edit_upper_alt').val(button.data('upper-alt'));
        modal.find('#edit_remarks').val(button.data('remarks'));
    });

    // Edit form submission
    $('#editForm').submit(function(e) {
        e.preventDefault();

        $.ajax({
            type: 'POST',
            url: 'api/mgt/sua/update.php',
            data: $(this).serialize(),
            success: function(data) {
                Swal.fire({
                    toast: true,
                    position: 'bottom-right',
                    icon: 'success',
                    title: 'Activation updated',
                    timer: 3000,
                    showConfirmButton: false
                });
                loadActivations();
                $('#editModal').modal('hide');
                $('.modal-backdrop').remove();
            },
            error: function(xhr) {
                var msg = 'Failed to update activation';
                try {
                    var resp = JSON.parse(xhr.responseText);
                    if (resp.message) msg = resp.message;
                } catch (e) {}
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: msg
                });
            }
        });
    });

    // Auto-refresh activations every 60 seconds
    setInterval(function() {
        loadActivations();
    }, 60000);

    // Set default TFR times
    var now = new Date();
    var end = new Date(now.getTime() + 2 * 60 * 60 * 1000);
    $('#tfr_start').val(formatDateTimeLocal(now));
    $('#tfr_end').val(formatDateTimeLocal(end));
});
