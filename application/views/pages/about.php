<?php extend('layouts/backend_layout'); ?>

<?php section('content'); ?>

<div id="about-page" class="container backend-page py-3">
    <div id="about" class="col-lg-8 offset-lg-2">

        <div class="text-center my-5">
            <img src="<?= base_url('assets/img/logo.png') ?>" alt="Polaris Calendar Logo" class="mb-5">

            <h3>
                Polaris Calendar
            </h3>
            <h6 class="text-primary">
                Booking subsystem of the Polaris platform
            </h6>
        </div>

        <p class="mb-5">
            <?= lang('about_app_info') ?>
        </p>

        <div class="card mb-5">
            <div class="card-header">
                <h5 class="fw-light mb-0">
                    <?= lang('current_version') ?>
                </h5>
            </div>
            <div class="card-body">
                <strong>
                    <?= config('version') ?>
                </strong>
            </div>
        </div>

        <h4 class="fw-light mb-3">
            <?= lang('support') ?>
        </h4>

        <p>
            <?= lang('about_app_support') ?>
        </p>

        <div class="row mb-5">
            <div class="col-lg-6 mb-3">
                <a class="btn btn-outline-secondary d-block" href="https://getpolaris.my" target="_blank">
                    <i class="fas fa-external-link-alt me-2"></i>
                    <?= lang('official_website') ?>
                </a>
            </div>

            <div class="col-lg-6 mb-3">
                <a class="btn btn-outline-secondary d-block" href="mailto:support@getpolaris.my">
                    <i class="fas fa-envelope me-2"></i>
                    support@getpolaris.my
                </a>
            </div>
        </div>

    </div>
</div>

<?php end_section('content'); ?>

