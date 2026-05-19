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
}
