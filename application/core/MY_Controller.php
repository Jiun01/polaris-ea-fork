<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Polaris fork — tenant context holder (S3-4).
 *
 * Static properties are populated by BearerAuthHook (S3-5) after the JWT is
 * decoded and validated. All MY_Model CRUD methods read these statics to
 * scope queries to the correct tenant.
 * ---------------------------------------------------------------------------- */

class MY_Controller extends CI_Controller
{
    public static ?string $tenant_id = null;
    public static ?string $tenant_branch_id = null;

    public static function set_tenant_context(string $tenant_id, ?string $branch_id = null): void
    {
        self::$tenant_id = $tenant_id;
        self::$tenant_branch_id = $branch_id;
    }

    public static function clear_tenant_context(): void
    {
        self::$tenant_id = null;
        self::$tenant_branch_id = null;
    }
}
