<?php defined('BASEPATH') or exit('No direct script access allowed');

/*
| -------------------------------------------------------------------------
| Hooks
| -------------------------------------------------------------------------
| This file lets you define "hooks" to extend CI without hacking the core
| files.  Please see the user guide for info:
|
|	http://codeigniter.com/user_guide/general/hooks.html
|
*/

/**
 * Add security headers to all responses. (upstream 1.6.0)
 */
$hook['post_controller_constructor'][] = [
    'class' => '',
    'function' => 'add_security_headers',
    'filename' => 'security_headers.php',
    'filepath' => 'hooks',
    'params' => [],
];

/**
 * Pin base_url to the configured public URL on the /api/v1/* surface so
 * customer-facing links (provisioning reset email, appointment reschedule links)
 * never bake in the internal request host (e.g. ea-nginx). See the hook file.
 */
$hook['post_controller_constructor'][] = [
    'class' => '',
    'function' => 'pin_api_public_base_url',
    'filename' => 'api_public_base_url.php',
    'filepath' => 'hooks',
    'params' => [],
];

/**
 * Probabilistically clean up old storage files (runs ~1% of requests). (upstream 1.6.0)
 */
$hook['post_system'][] = [
    'class' => '',
    'function' => 'storage_cleanup',
    'filename' => 'storage_cleanup.php',
    'filepath' => 'hooks',
    'params' => [],
];

// ── Polaris Bearer JWT auth (S3-5) ───────────────────────────────────────────
// Validates the Authorization: Bearer <jwt> header on every /api/v1/* request
// and populates MY_Controller::$tenant_id before the controller runs.
$hook['pre_controller'][] = [
    'class'    => 'BearerAuthHook',
    'function' => 'validate',
    'filename' => 'BearerAuthHook.php',
    'filepath' => 'hooks',
    'params'   => [],
];

// Note: session-based tenant resolution for backend routes happens directly in
// EA_Controller::__construct() via MY_Controller::resolve_session_tenant(),
// so no separate hook is required.

/* End of file hooks.php */
/* Location: ./application/config/hooks.php */
