<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Polaris fork — tenant shadow-table model (S3-6).
 *
 * Queries ea_polaris_tenants (created in migration 062) to resolve a URL slug
 * to a tenant UUID. This is the only model that intentionally bypasses MY_Model
 * tenant scoping — it is the lookup that *establishes* the tenant context.
 * ---------------------------------------------------------------------------- */

class Polaris_tenant_model extends EA_Model
{
    /**
     * Resolve a booking slug to a tenant_id UUID.
     *
     * Returns null when:
     *   - no row with the given slug exists
     *   - the tenant's status is not 'active'
     *
     * @param string $slug Validated slug (only caller is Booking::slug()).
     * @return string|null UUID or null.
     */
    public function resolve_slug(string $slug): ?string
    {
        $row = $this->db
            ->select('tenant_id')
            ->from('ea_polaris_tenants')
            ->where('slug', $slug)
            ->where('status', 'active')
            ->limit(1)
            ->get()
            ->row_array();

        return $row['tenant_id'] ?? null;
    }

    /**
     * Resolve a tenant_id UUID back to its active booking slug.
     *
     * Used by the admin backend footer to build the "Go to booking page"
     * link for the logged-in tenant. Returns null when the tenant has no
     * active slug row.
     *
     * @param string|null $tenant_id Current tenant UUID (MY_Controller::$tenant_id).
     * @return string|null Slug or null.
     */
    public function resolve_tenant_slug(?string $tenant_id): ?string
    {
        if (empty($tenant_id)) {
            return null;
        }

        $row = $this->db
            ->select('slug')
            ->from('ea_polaris_tenants')
            ->where('tenant_id', $tenant_id)
            ->where('status', 'active')
            ->limit(1)
            ->get()
            ->row_array();

        return $row['slug'] ?? null;
    }
}
