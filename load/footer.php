<?php include_once("config.php"); $filepath = ""; ?>

<!-- Load Plugins -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.13.14/dist/js/bootstrap-select.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/summernote-bs4.min.js"></script>
<script src="<?= $filepath; ?>assets/js/plugins/datetimepicker.js"> </script>
<script src="<?= $filepath; ?>assets/vendor/parallax-js/dist/parallax.min.js"></script>
<script src="<?= $filepath; ?>assets/vendor/jarallax/dist/jarallax.min.js"></script>
<script src="<?= $filepath; ?>assets/vendor/jarallax/dist/jarallax-element.min.js"></script>
<script src="<?= $filepath; ?>assets/js/theme.min.js"></script>

<!-- Mobile Navigation Handler -->
<script>
(function() {
    'use strict';

    var offcanvas = document.getElementById('primaryMenu');
    var backdrop = document.getElementById('offcanvasBackdrop');
    var closeBtn = document.querySelector('.offcanvas-close');
    var toggleBtns = document.querySelectorAll('[data-toggle="offcanvas"][data-offcanvas-id="primaryMenu"]');

    function openOffcanvas() {
        if (offcanvas) {
            offcanvas.classList.add('show');
            backdrop.classList.add('show');
            document.body.classList.add('offcanvas-open');
        }
    }

    function closeOffcanvas() {
        if (offcanvas) {
            offcanvas.classList.remove('show');
            backdrop.classList.remove('show');
            document.body.classList.remove('offcanvas-open');
        }
    }

    // Toggle button handlers
    toggleBtns.forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            if (offcanvas.classList.contains('show')) {
                closeOffcanvas();
            } else {
                openOffcanvas();
            }
        });
    });

    // Close button handler
    if (closeBtn) {
        closeBtn.addEventListener('click', closeOffcanvas);
    }

    // Backdrop click closes menu
    if (backdrop) {
        backdrop.addEventListener('click', closeOffcanvas);
    }

    // ESC key closes menu
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && offcanvas && offcanvas.classList.contains('show')) {
            closeOffcanvas();
        }
    });

    // Close on window resize to desktop
    window.addEventListener('resize', function() {
        if (window.innerWidth >= 992 && offcanvas && offcanvas.classList.contains('show')) {
            closeOffcanvas();
        }
    });
})();
</script>

<footer class="cs-footer">
    <div class="container-fluid bg-dark pt-5 pb-3">

        <p class="font-size-sm text-center"><span class="text-light opacity-50"><?= __('footer.copyright', ['year' => date('Y')]) ?></span></p>
        <p class="font-size-sm text-center"><span class="text-light opacity-50"><?= __('footer.disclaimer') ?></span></p>

        <p class="font-size-sm text-center mb-n2">
            <a href="<?php echo "https://" . SITE_DOMAIN; ?>/privacy" target="_blank" class="text-light opacity-50"><?= __('footer.privacyPolicy') ?></a>
            &nbsp; <i class="fas fa-angle-right text-light opacity-50"></i>&nbsp;
            <a href="https://vatsim.net/"  target="_blank" class="text-light opacity-50">VATSIM</a>
            &nbsp; <i class="fas fa-angle-right text-light opacity-50"></i>&nbsp;
            <a href="https://vatusa.net/"  target="_blank" class="text-light opacity-50">VATUSA</a>
        </p>

    </div>

</footer>

<!-- Dismiss page loading indicator when all resources are loaded -->
<script>
(function(){
    function dismiss(){
        var l=document.getElementById('perti-page-loader'),
            o=document.getElementById('perti-loader-overlay');
        if(l){l.style.transition='opacity .3s';l.style.opacity='0';setTimeout(function(){l.remove()},350);}
        if(o)o.remove();
    }
    window.addEventListener('load',dismiss);
    setTimeout(dismiss,15000);
})();
</script>
