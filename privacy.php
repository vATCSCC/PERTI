<?php
include("sessions/handler.php");
include("load/config.php");
include("load/i18n.php");
?>
<!DOCTYPE html>
<html lang="en">
    <head>

        <!-- Import CSS -->
        <?php
            $page_title = "Privacy Policy";
            include("load/header.php");
        ?>

    </head>

    <body>

    <?php
    include('load/nav_public.php');
    ?>

    <section class="perti-hero perti-hero--full fh-section bg-position-center jarallax bg-dark text-light" data-jarallax data-speed="0.3">
        <div class="container-fluid pt-2 pb-5 py-lg-6">
            <img class="jarallax-img" src="assets/img/jumbotron/main.png" alt="" style="opacity: 50%;">

            <center>
                <h1><?= __('privacy.title') ?></h1>
                <h4 class="text-white hvr-bob pl-1">
                    <?= __('privacy.lastUpdated') ?>
                </h4>
            </center>

        </div>
    </section>

    <!-- Content -->
    <div class="container mt-4 mb-3">
        <div class="card">
            <div class="card-body">

                <h5><?= __('privacy.registrationTitle') ?></h5>
                <p><?= __('privacy.registrationText') ?></p>

                <h5><?= __('privacy.collectionTitle') ?></h5>

                <p><?= __('privacy.collectionIntro') ?></p>

                <ul>
                    <li><?= __('privacy.collectionItem1') ?></li>
                    <li><?= __('privacy.collectionItem2') ?></li>
                </ul>

                <p><?= __('privacy.collectionRemoval') ?></p>

                <h5><?= __('privacy.sharingTitle') ?></h5>
                <p><?= __('privacy.sharingIntro') ?></p>
                <ul>
                    <li>VATSIM</li>
                    <li>VATUSA</li>
                    <li>Other VATUSA ARTCCs</li>
                    <li>Other VATSIM Divisions/Regions</li>
                    <li>Google</li>
                    <li>Discord</li>
                    <li>Law Enforcement Agencies</li>
                </ul>

                <h5><?= __('privacy.cookiesTitle') ?></h5>
                <p><?= __('privacy.cookiesText') ?></p>

                <h5><?= __('privacy.thirdPartyTitle') ?></h5>
                <p><?= __('privacy.thirdPartyText') ?></p>

                <h5><?= __('privacy.securityTitle') ?></h5>
                <p><?= __('privacy.securityText') ?></p>

                <h5><?= __('privacy.changesTitle') ?></h5>
                <p><?= __('privacy.changesText') ?></p>

            </div>
        </div>
    </div>

    </body>
    <?php include('load/footer.php'); ?>

</html>
