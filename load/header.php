<?php

include_once("config.php");
$filepath = "";

// Cache-busting helper: appends file modification time as query param
// Ensures users always get fresh assets after deployments
$_rootDir = dirname(__DIR__);
function _v($path) {
    global $_rootDir;
    $fullPath = $_rootDir . '/' . $path;
    $mtime = @filemtime($fullPath);
    return $mtime ? "?v={$mtime}" : '';
}

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
<title><?= isset($page_title) ? $page_title : 'PERTI Planning'; ?></title>

<!-- Page Loading Indicator (inline CSS for instant rendering) -->
<style>
#perti-page-loader{position:fixed;top:0;left:0;width:100%;height:3px;z-index:99999;pointer-events:none}
#perti-page-loader .bar{height:100%;width:30%;background:linear-gradient(90deg,#239BCD,#17a2b8);border-radius:0 2px 2px 0;animation:perti-lp 1.5s ease-in-out infinite}
@keyframes perti-lp{0%{width:10%;margin-left:0}50%{width:40%;margin-left:30%}100%{width:10%;margin-left:90%}}
#perti-loader-overlay{position:fixed;top:0;left:0;width:100%;height:100%;z-index:99998;cursor:wait}
</style>

<!-- Load Initial Bootstrap Source --> 
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.13.14/dist/css/bootstrap-select.min.css">
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/css/bootstrap.min.css" integrity="sha384-9aIt2nRpC12Uk9gS9baDl411NQApFmC26EwAOH8WgZl5MYYxFfc+NcPb1dKGj7Sk" crossorigin="anonymous">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/summernote-bs4.min.css">
<link rel="stylesheet" href="<?= $filepath; ?>assets/css/plugins/datetimepicker.css<?= _v('assets/css/plugins/datetimepicker.css') ?>">

<!-- Load jQuery/Javascript Sources -->
<script defer src="https://cdnjs.cloudflare.com/ajax/libs/javascript.util/0.12.12/javascript.util.min.js"></script>
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
  <link rel="stylesheet" href="<?= $filepath; ?>assets/css/perti-colors.css<?= _v('assets/css/perti-colors.css') ?>">
  <link rel="stylesheet" href="<?= $filepath; ?>assets/css/theme.css<?= _v('assets/css/theme.css') ?>">
  <link rel="stylesheet" href="<?= $filepath; ?>assets/css/perti_theme.css<?= _v('assets/css/perti_theme.css') ?>">
  <link rel="stylesheet" href="<?= $filepath; ?>assets/css/tmi-compliance.css<?= _v('assets/css/tmi-compliance.css') ?>">
  <link rel="stylesheet" href="<?= $filepath; ?>assets/css/mobile.css<?= _v('assets/css/mobile.css') ?>">
<?php } ?>
    
<!-- Load Fontawesome Source (CSS only — Kit JS removed, was duplicate + sometimes returned 403) -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" integrity="sha512-1ycn6IcaQQ40/MKBW2W4Rhis/DbILU74C1vSrLJxCq57o941Ym01SwNsOMqvEBFlcgUa6xLiPY/NS5R+E6ztJQ==" crossorigin="anonymous" referrerpolicy="no-referrer" />

<!-- Load Swal Source -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<!-- Load Select2 for multi-select dropdowns -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<!-- Load PERTI Centralized Namespace -->
<script src="<?= $filepath; ?>assets/js/lib/perti.js<?= _v('assets/js/lib/perti.js') ?>"></script>
<script src="<?= $filepath; ?>assets/js/lib/colors.js<?= _v('assets/js/lib/colors.js') ?>"></script>
<script src="<?= $filepath; ?>assets/js/facility-hierarchy.js<?= _v('assets/js/facility-hierarchy.js') ?>"></script>

<!-- Organization Context (must be before locale loader so {commandCenter} resolves) -->
<?php
    require_once __DIR__ . '/org_context.php';
    $org_info_header = ['default_locale' => 'en-US'];
    if (isset($conn_sqli) && $conn_sqli) {
        $org_info_header = get_org_info($conn_sqli);
    }
?>
<script>
window.PERTI_ORG = {
    code: <?= json_encode($_SESSION['ORG_CODE'] ?? 'vatcscc') ?>,
    privileged: <?= !empty($_SESSION['ORG_PRIVILEGED']) ? 'true' : 'false' ?>,
    global: <?= !empty($_SESSION['ORG_GLOBAL']) ? 'true' : 'false' ?>,
    allOrgs: <?= json_encode($_SESSION['ORG_ALL'] ?? ['vatcscc']) ?>,
    defaultLocale: <?= json_encode($org_info_header['default_locale'] ?? 'en-US') ?>,
    orgInfo: <?= json_encode($org_info_header) ?>
};
</script>

<!-- Internationalization (i18n) -->
<script>window.PERTI_LOCALE_V = '<?= @filemtime($_rootDir . "/assets/locales/en-US.json") ?: time() ?>';</script>
<script src="<?= $filepath; ?>assets/js/lib/i18n.js<?= _v('assets/js/lib/i18n.js') ?>"></script>
<script src="<?= $filepath; ?>assets/locales/index.js<?= _v('assets/locales/index.js') ?>"></script>

<script src="<?= $filepath; ?>assets/js/lib/dialog.js<?= _v('assets/js/lib/dialog.js') ?>"></script>

<!-- Deep Link Utility -->
<script defer src="<?= $filepath; ?>assets/js/lib/deeplink.js<?= _v('assets/js/lib/deeplink.js') ?>"></script>
