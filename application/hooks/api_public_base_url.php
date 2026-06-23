<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Polaris fork — pin base_url to the public URL on the API surface (S3-5).
 *
 * application/config/config.php derives CI3's base_url from the request Host for
 * every HTTP request. That is correct for human web traffic (backend panel,
 * public /book pages), but the /api/v1/* surface is machine-to-machine: Polaris
 * calls it over the INTERNAL host (e.g. http://ea-nginx:80). Any customer-facing
 * absolute URL generated while serving an API request — the provisioning
 * password-reset link, and the appointment reschedule links emailed to the
 * customer/provider (Notifications.php) + written into the Google Calendar event
 * (Google_sync.php) — would otherwise bake in that internal host and be
 * unreachable (http://ea-nginx/...).
 *
 * Fires at post_controller_constructor (get_instance() is null at pre_controller,
 * so we cannot set the config item earlier) and BEFORE the controller method, so
 * every site_url()/base_url() the method calls resolves against the public host.
 *
 * Scoped to api/v1/* only — backend + public-booking requests keep the
 * request-host base_url (custom domains, correct in those contexts). Registered
 * in application/config/hooks.php under 'post_controller_constructor'.
 * ---------------------------------------------------------------------------- */

function pin_api_public_base_url(): void
{
    $uri = ltrim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '', '/');
    if (str_starts_with($uri, 'index.php/')) {
        $uri = substr($uri, strlen('index.php/'));
    }
    if (!str_starts_with($uri, 'api/v1/') && $uri !== 'api/v1') {
        return;
    }

    if (!class_exists('Config', false)) {
        require_once FCPATH . 'config.php';
    }
    $base = rtrim((string) Config::BASE_URL, '/');
    if ($base === '') {
        // No configured public URL — leave the request-derived value rather than
        // emit a broken relative link.
        return;
    }

    $CI = get_instance();
    if ($CI !== null) {
        $CI->config->set_item('base_url', $base . '/');
    }
}
