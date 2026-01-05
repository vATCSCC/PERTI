<?php include("config.php"); $filepath = ""; ?>

<!-- Load Plugins -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.13.14/dist/js/bootstrap-select.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/summernote-bs4.min.js"></script>
<script src="<?= $filepath; ?>assets/js/plugins/datetimepicker.js"> </script>
<script src="<?= $filepath; ?>assets/vendor/parallax-js/dist/parallax.min.js"></script>
<script src="<?= $filepath; ?>assets/vendor/jarallax/dist/jarallax.min.js"></script>
<script src="<?= $filepath; ?>assets/vendor/jarallax/dist/jarallax-element.min.js"></script>
<script src="<?= $filepath; ?>assets/js/theme.min.js"></script>

<footer class="cs-footer">
    <div class="container-fluid bg-dark pt-5 pb-3">

        <p class="font-size-sm text-center"><span class="text-light opacity-50">Copyright © <?php echo date('Y'); ?> vATCSCC - All Rights Reserved.</span></p>
        <p class="font-size-sm text-center"><span class="text-light opacity-50">For Flight Simulation Use Only. This site is not intended for real world navigation, and not affiliated with any governing aviation body. All content contained is approved only for use on the VATSIM network.</span></p>

        <p class="font-size-sm text-center mb-n2">
            <a href="<?php echo "https://" . SITE_DOMAIN; ?>/privacy" target="_blank" class="text-light opacity-50">Privacy Policy</a>
            &nbsp; <i class="fas fa-angle-right text-light opacity-50"></i>&nbsp;
            <a href="https://vatsim.net/"  target="_blank" class="text-light opacity-50">VATSIM</a>
            &nbsp; <i class="fas fa-angle-right text-light opacity-50"></i>&nbsp;
            <a href="https://vatusa.net/"  target="_blank" class="text-light opacity-50">VATUSA</a>
        </p>

    </div>

</footer>
