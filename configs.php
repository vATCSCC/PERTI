<?php

include("sessions/handler.php");
    // Session Start (S)
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
        ob_start();
    }
    // Session Start (E)
    
    include("load/config.php");
    include("load/connect.php");

    // Check Perms
    $perm = false;
    if (!defined('DEV')) {
        if (isset($_SESSION['VATSIM_CID'])) {

            // Getting CID Value
            $cid = strip_tags($_SESSION['VATSIM_CID']);
    
            $p_check = $conn_sqli->query("SELECT * FROM users WHERE cid='$cid'");
    
            if ($p_check) {
                $perm = true;
            }
    
        }
    } else {
        $perm = true;
        $_SESSION['VATSIM_FIRST_NAME'] = $_SESSION['VATSIM_LAST_NAME'] = $_SESSION['VATSIM_CID'] = 0;
    }

?>

<!DOCTYPE html>
<html>

<head>

    <!-- Import CSS -->
    <?php
        include("load/header.php");
    ?>

</head>

<body>

<?php
include('load/nav.php');
?>

    <section class="d-flex align-items-center position-relative bg-position-center fh-section overflow-hidden pt-6 jarallax bg-dark text-light" data-jarallax data-speed="0.3" style="pointer-events: all;">
        <div class="container-fluid pt-2 pb-5 py-lg-6">
            <img class="jarallax-img" src="assets/img/jumbotron/main.png" alt="" style="opacity: 50%;">

            <center>
                <h1>Field Configuration Data</h1>
                <h4 class="text-white hvr-bob pl-1">
                    <a href="#configs" style="text-decoration: none; color: white;"><i class="fas fa-chevron-down text-danger"></i> Search for Configs</a>
                </h4>
            </center>

        </div>
    </section>


    <div class="container-fluid">

        <div class="input-group ml-5 mt-2" style="width: 10%;">
            <input type="text" class="form-control" id="search" placeholder="FAA Code" maxlength="4">
                <div class="input-group-append">
                    <button class="btn btn-info btn-sm" id="searchBtn"><i class="fas fa-search"></i></button>
                </div>
        </div>

        <center>
            <hr>

            <?php if ($perm == true) { ?>
                <button class="mt-2 mb-2 btn btn-success btn-sm" data-target="#addconfigModal" data-toggle="modal"><i class="fas fa-plus"></i> Add Config</button>
            <?php } ?>

            <table class="table table-sm table-striped table-bordered w-75" id="configs">
                <thead class="table-dark text-light">
                    <th class="text-center" style="width: 10%;">Airport</th>
                    <th class="text-center" style="width: 25%;" data-toggle="tooltip" title="Arrival Runway(s)">ARR</th>
                    <th class="text-center" style="width: 25%;" data-toggle="tooltip" title="Departure Runway(s)">DEP</th>
                    <th class="text-center" style="width: 5%;" data-toggle="tooltip" title="VMC Average Arrival Rate">VA</th>
                    <th class="text-center" style="width: 5%;" data-toggle="tooltip" title="LVMC Average Arrival Rate">LVA</th>
                    <th class="text-center" style="width: 5%;" data-toggle="tooltip" title="IMC Average Arrival Rate">IA</th>
                    <th class="text-center" style="width: 5%;" data-toggle="tooltip" title="LIMC Average Arrival Rate">LIA</th>
                    <th class="text-center" style="width: 5%;" data-toggle="tooltip" title="VMC/LVMC Average Departure Rate">VD</th>
                    <th class="text-center" style="width: 5%;" data-toggle="tooltip" title="IMC/LIMC Average Departure Rate">ID</th>

                    <?php if ($perm == true) { ?>
                        <th></th>
                    <?php } ?>
                </thead>

                <tbody id="configs_table"></tbody>
            </table>
        </center>
    </div>

    
<?php include('load/footer.php'); ?>


<!-- Add Config Modal -->
<div class="modal fade" id="addconfigModal" tabindex="-1" role="dialog" aria-labelledby="addconfigModalLabel"
    aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addconfigModalLabel">Add Config</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="addconfig">

                <div class="modal-body">

                    Airport:
                    <input type="text" class="form-control" name="airport" maxlength="4" placeholder="DTW" required><br>

                    Arrival Runways:
                    <input type="text" class="form-control" name="arr" maxlength="32" placeholder="21L/22R" required><br>

                    Departure Runways:
                    <input type="text" class="form-control" name="dep" maxlength="32" placeholder="22L/21R" required>

                    <hr>

                    <b>VMC</b> Average Arrival Rate:
                    <input type="text" class="form-control" name="vmc_aar" maxlength="3" placeholder="76" required><br>

                    <b>LVMC</b> Average Arrival Rate:
                    <input type="text" class="form-control" name="lvmc_aar" maxlength="3" placeholder="70" required><br>

                    <b>IMC</b> Average Arrival Rate:
                    <input type="text" class="form-control" name="imc_aar" maxlength="3" placeholder="64" required><br>

                    <b>LIMC</b> Average Arrival Rate:
                    <input type="text" class="form-control" name="limc_aar" maxlength="3" placeholder="60" required>
                    
                    <hr>

                    <b>VMC</b> Average Departure Rate:
                    <input type="text" class="form-control" name="vmc_adr" maxlength="3" placeholder="60" required><br>

                    <b>IMC</b> Average Departure Rate:
                    <input type="text" class="form-control" name="imc_adr" maxlength="3" placeholder="48" required>                   

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-success" value="Add">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal">Close</button>
                </div>
        </div>

        </form>

    </div>
</div>

<!-- Update Config Modal -->
<div class="modal fade" id="updateconfigModal" tabindex="-1" role="dialog" aria-labelledby="updateconfigModalLabel"
    aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="updateconfigModalLabel">Update Config</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="post" id="updateconfig">

                <div class="modal-body">

                    <input type="hidden" name="id" id="id" required>

                    Airport:
                    <input type="text" class="form-control" name="airport" id="airport" maxlength="4" placeholder="DTW" required><br>

                    Arrival Runways:
                    <input type="text" class="form-control" name="arr" id="arr" maxlength="32" placeholder="21L/22R" required><br>

                    Departure Runways:
                    <input type="text" class="form-control" name="dep" id="dep" maxlength="32" placeholder="22L/21R" required>

                    <hr>

                    <b>VMC</b> Average Arrival Rate:
                    <input type="text" class="form-control" name="vmc_aar" id="vmc_aar" maxlength="3" placeholder="76" required><br>

                    <b>LVMC</b> Average Arrival Rate:
                    <input type="text" class="form-control" name="lvmc_aar" id="lvmc_aar" maxlength="3" placeholder="70" required><br>

                    <b>IMC</b> Average Arrival Rate:
                    <input type="text" class="form-control" name="imc_aar" id="imc_aar" maxlength="3" placeholder="64" required><br>

                    <b>LIMC</b> Average Arrival Rate:
                    <input type="text" class="form-control" name="limc_aar" id="limc_aar" maxlength="3" placeholder="60" required>
                    
                    <hr>

                    <b>VMC</b> Average Departure Rate:
                    <input type="text" class="form-control" name="vmc_adr" id="vmc_adr" maxlength="3" placeholder="60" required><br>

                    <b>IMC</b> Average Departure Rate:
                    <input type="text" class="form-control" name="imc_adr" id="imc_adr" maxlength="3" placeholder="48" required>                   

                </div>
                <div class="modal-footer">
                    <input type="submit" class="btn btn-sm btn-warning" value="Update">
                    <button type="button" class="btn btn-sm btn-danger" data-dismiss="modal">Close</button>
                </div>
        </div>

        </form>

    </div>
</div>


    <!-- Scripts -->
    <script async type="text/javascript">
        function tooltips() {
            $('[data-toggle="tooltip"]').tooltip('dispose');

            $(function () {
                $('[data-toggle="tooltip"]').tooltip()
            }); 
        }

        function loadData(search) {
            // Plans
            $.get(`api/data/configs?search=${search}`).done(function(data) {
                $('#configs_table').html(data);

                tooltips();           
            });
        }

        // FUNC: deleteConfig [id:]
        function deleteConfig(id) {
            $.ajax({
                type:   'POST',
                url:    'api/mgt/config_data/delete',
                data:   {id: id},
                success:function(data) {
                    Swal.fire({
                        toast:      true,
                        position:   'bottom-right',
                        icon:       'success',
                        title:      'Successfully Deleted',
                        text:       'You have successfully deleted the selected field config.',
                        timer:      3000,
                        showConfirmButton: false
                    });

                    loadData($('#search').val());
                },
                error:function(data) {
                    Swal.fire({
                        icon:   'error',
                        title:  'Not Deleted',
                        text:   'There was an error in deleting the selected field config.'
                    });
                }
            });
        }
        
        $(document).ready(function() {
            loadData($('#search').val());     

            // Init: Date Time Picker
            $('#date').datetimepicker({
                format: 'Y-m-d',
                inline: false,
                minDate: '<?= date('Y-m-d'); ?>',
                timepicker: false
            });

            // 
            $('#searchBtn').click(function() {
                loadData($('#search').val());
            })

            // AJAX: #addconfig POST
            $("#addconfig").submit(function(e) {
                e.preventDefault();

                var url = 'api/mgt/config_data/post';

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
                            text:       'You have successfully added a field config.',
                            timer:      3000,
                            showConfirmButton: false
                        });

                        loadData($('#search').val());
                        $('#addconfigModal').modal('hide');
                        $('.modal-backdrop').remove();
                    },
                    error:function(data) {
                        Swal.fire({
                            icon:   'error',
                            title:  'Not Added',
                            text:   'There was an error in adding this field config.'
                        });
                    }
                });
            });

            // Update Config Modal
            $('#updateconfigModal').on('show.bs.modal', function(event) {
                var button = $(event.relatedTarget);

                var modal= $(this);

                modal.find('.modal-body #id').val(button.data('id'));
                modal.find('.modal-body #airport').val(button.data('airport'));
                modal.find('.modal-body #arr').val(button.data('arr'));
                modal.find('.modal-body #dep').val(button.data('dep'));
                modal.find('.modal-body #vmc_aar').val(button.data('vmc_aar'));
                modal.find('.modal-body #lvmc_aar').val(button.data('lvmc_aar'));
                modal.find('.modal-body #imc_aar').val(button.data('imc_aar'));
                modal.find('.modal-body #limc_aar').val(button.data('limc_aar'));
                modal.find('.modal-body #vmc_adr').val(button.data('vmc_adr'));
                modal.find('.modal-body #imc_adr').val(button.data('imc_adr'));

            });

            // AJAX: #editplan POST
            $("#updateconfig").submit(function(e) {
                e.preventDefault();

                var url = 'api/mgt/config_data/update';

                $.ajax({
                    type:   'POST',
                    url:    url,
                    data:   $(this).serialize().replace(/'/g, "`"),
                    success:function(data) {
                        Swal.fire({
                            toast:      true,
                            position:   'bottom-right',
                            icon:       'success',
                            title:      'Successfully Updated',
                            text:       'You have successfully updated the selected field config.',
                            timer:      3000,
                            showConfirmButton: false
                        });

                        loadData($('#search').val());
                        $('#updateconfigModal').modal('hide');
                        $('.modal-backdrop').remove();
                    },
                    error:function(data) {
                        Swal.fire({
                            icon:   'error',
                            title:  'Not Updated',
                            text:   'There was an error in updating the selected field config.'
                        });
                    }
                });
            });

        });
    </script>

</html>