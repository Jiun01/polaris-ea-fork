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

/* End of file hooks.php */
/* Location: ./application/config/hooks.php */
