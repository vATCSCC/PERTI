<?php

include_once("config.php");
$filepath = "";

?>

<!-- Base URL for relative paths -->
<base href="/">

<!-- Tags: General -->
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">

<!-- Tags: Facility Specific -->
<meta name="keywords" content="html, css, artcc, vatusa, vatsim, vatcscc, acc, fir, dcc, atcscc, atfm, tfm, tmu">

<meta name="description" content="The site for PERTI operational planning of vATCSCC events on the VATSIM Network.">
<meta name="author" content="vATCSCC">

<meta property="og:title" content="PERTI Planning Website">
<meta property="og:site_name" content="PERTI Planning">
<meta property="og:description" content="The site for PERTI operational planning of vATCSCC events on the VATSIM Network.">
<meta property="og:type" content="website">
<meta property="og:url" content="">
<meta property="og:image" content="">

<meta name="msapplication-TileColor" content="#239BCD">
<meta name="theme-color" content="#ffffff">

<!-- Title -->
<title><?= isset($page_title) ? $page_title : 'PERTI Planning - vATCSCC'; ?></title>


<!-- Load Initial Bootstrap Source --> 
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.13.14/dist/css/bootstrap-select.min.css">
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/css/bootstrap.min.css" integrity="sha384-9aIt2nRpC12Uk9gS9baDl411NQApFmC26EwAOH8WgZl5MYYxFfc+NcPb1dKGj7Sk" crossorigin="anonymous">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/summernote-bs4.min.css">
<link rel="stylesheet" href="<?= $filepath; ?>assets/css/plugins/datetimepicker.css">

<!-- Load jQuery/Javascript Sources -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/javascript.util/0.12.12/javascript.util.min.js"></script>
<script src="https://code.jquery.com/jquery-2.2.4.js" integrity="sha256-iT6Q9iMJYuQiMWNd9lDyBUStIq/8PuOW33aOqmvFpqI=" crossorigin="anonymous"></script>
<link rel="stylesheet" href="https://ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/themes/smoothness/jquery-ui.css">
<script src="https://ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/jquery-ui.min.js"></script>

<!-- Load Popper.js & Bootstrap.js Sources -->
<script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.0/dist/umd/popper.min.js" integrity="sha384-Q6E9RHvbIyZFJoft+2mJbHaEWldlvI9IOYy5n3zV9zzTtmI3UksdQRVvoxMfooAo" crossorigin="anonymous"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/js/bootstrap.min.js" integrity="sha384-OgVRvuATP1z7JjHLkuOU7Xw704+h835Lr+6QL9UvYjZE3Ipu6Tp75j7Bh/kR0JKI" crossorigin="anonymous"></script>

<!-- Load Favicon Source -->
<link rel='icon' href='<?= $filepath; ?>assets/img/favicon.ico' type='image/x-icon'>

<!-- Load Fonts --> 
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Jost:wght@325&display=swap" rel="stylesheet">

<!-- Load CSS --> 
<?php
if (strpos($_SERVER['PHP_SELF'], "ids") == false) {
?>
  <link rel="stylesheet" href="<?= $filepath; ?>assets/css/theme.css">
  <link rel="stylesheet" href="<?= $filepath; ?>assets/css/perti_theme.css">
  <link rel="stylesheet" href="<?= $filepath; ?>assets/css/perti-colors.css">
  <link rel="stylesheet" href="<?= $filepath; ?>assets/css/tmi-compliance.css">
  <link rel="stylesheet" href="<?= $filepath; ?>assets/css/mobile.css">
<?php } ?>
    
<!-- Load Fontawesome Source -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" integrity="sha512-1ycn6IcaQQ40/MKBW2W4Rhis/DbILU74C1vSrLJxCq57o941Ym01SwNsOMqvEBFlcgUa6xLiPY/NS5R+E6ztJQ==" crossorigin="anonymous" referrerpolicy="no-referrer" />
<!-- FontAwesome Kit (backup, may fail with 403) -->
<script src="https://kit.fontawesome.com/2b05d84399.js" crossorigin="anonymous"></script>

<!-- Load Swal Source -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<!-- Load Select2 for multi-select dropdowns -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<!-- Load Facility Hierarchy Data -->
<script src="<?= $filepath; ?>assets/js/facility-hierarchy.js"></script>
