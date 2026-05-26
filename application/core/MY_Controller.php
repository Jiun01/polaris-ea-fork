<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Polaris fork — tenant context holder + auth factories (S3-4 / S3-5).
 *
 * Static properties are populated either by BearerAuthHook (API path) or by
 * Booking::slug() (public booking path). MY_Model reads these statics on
 * every scoped query to enforce per-tenant data isolation.
 * ---------------------------------------------------------------------------- */

class MY_Controller extends CI_Controller
{
    public static ?string $tenant_id        = null;
    public static ?string $tenant_branch_id = null;

    // -------------------------------------------------------------------------
    // Context setters
    // -------------------------------------------------------------------------

    public static function set_tenant_context(string $tenant_id, ?string $branch_id = null): void
    {
        self::$tenant_id        = $tenant_id;
        self::$tenant_branch_id = $branch_id;
    }

    public static function clear_tenant_context(): void
    {
        self::$tenant_id        = null;
        self::$tenant_branch_id = null;
    }

    // -------------------------------------------------------------------------
    // Auth factories — called from BearerAuthHook or Booking::slug()
    // -------------------------------------------------------------------------

    /**
     * Decode and validate a Polaris Bearer JWT, then set the tenant context.
     *
     * Expected claims:
     *   iss = 'polaris', aud = 'ea-fork', tenant_id = <UUID>, exp = <unix ts>
     *
     * Throws RuntimeException with one of these codes (safe to forward to client):
     *   token_expired | token_invalid
     *
     * @throws RuntimeException
     */
    public static function from_bearer(string $token): void
    {
        // get_instance() is null at pre_controller time (CI superobject not yet
        // assigned to the static $_CI slot). Read env vars directly — polaris.php
        // reads these same vars, so no config-layer duplication.
        $secret   = (string) (getenv('POLARIS_EA_JWT_SECRET') ?: '');
        $issuer   = 'polaris';
        $audience = 'ea-fork';

        if ($secret === '') {
            // Misconfiguration — fail closed rather than letting through.
            throw new RuntimeException('token_invalid');
        }

        // Allow up to 10 s of clock skew between Polaris and EA hosts.
        \Firebase\JWT\JWT::$leeway = 10;

        try {
            $decoded = \Firebase\JWT\JWT::decode(
                $token,
                new \Firebase\JWT\Key($secret, 'HS256'),
            );
        } catch (\Firebase\JWT\ExpiredException) {
            throw new RuntimeException('token_expired');
        } catch (\Throwable) {
            throw new RuntimeException('token_invalid');
        }

        // Validate mandatory claims manually (firebase/php-jwt does not check iss/aud).
        if (($decoded->iss ?? '') !== $issuer) {
            throw new RuntimeException('token_invalid');
        }

        $aud = $decoded->aud ?? '';
        if (is_array($aud) ? !in_array($audience, $aud, true) : $aud !== $audience) {
            throw new RuntimeException('token_invalid');
        }

        $tenant_id = (string) ($decoded->tenant_id ?? '');

        // Basic UUID format guard — signature already proves authenticity, but
        // an empty or clearly malformed value should not reach MY_Model.
        if ($tenant_id === '' || !preg_match('/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i', $tenant_id)) {
            throw new RuntimeException('token_invalid');
        }

        $branch_id = null;
        if (isset($decoded->tenant_branch_id)) {
            $bid = (string) $decoded->tenant_branch_id;
            if (!preg_match('/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i', $bid)) {
                throw new RuntimeException('token_invalid');
            }
            $branch_id = $bid;
        }

        self::set_tenant_context($tenant_id, $branch_id);
    }

    /**
     * Resolve a tenant slug via the ea_polaris_tenants shadow table (S3-6),
     * then set the tenant context.
     *
     * @throws RuntimeException if the slug is unknown or the tenant is suspended.
     */
    public static function from_slug(string $slug): void
    {
        $CI = get_instance();
        $CI->load->model('polaris_tenant_model');

        $tenant_id = $CI->polaris_tenant_model->resolve_slug($slug);

        if ($tenant_id === null) {
            throw new RuntimeException('unknown_slug');
        }

        self::set_tenant_context($tenant_id);
    }
}
