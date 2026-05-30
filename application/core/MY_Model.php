<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Polaris fork — tenant isolation model base (S3-4).
 *
 * Extends EA_Model with three protected helpers (assert_tenant,
 * apply_tenant_where, inject_tenant_id) that every tenant-scoped model uses
 * to restrict queries to the current tenant.
 *
 * Generic find/get_where/delete are provided as fallbacks; each EA model
 * provides its own insert/update with richer logic (JOINs, settings, etc.)
 * so those are intentionally absent here to avoid PHP 8 signature conflicts.
 * ---------------------------------------------------------------------------- */

// EA_Model is loaded by CI3's Loader via subclass_prefix='EA_', which triggers
// the require at the bottom of EA_Model.php that loads this file. When this
// file is re-entered through that path, the class_exists guards below are no-ops.
if (!class_exists('MY_Controller', false)) {
    require_once __DIR__ . '/MY_Controller.php';
}
if (!class_exists('TenantContextMissingException', false)) {
    require_once __DIR__ . '/TenantContextMissingException.php';
}

class MY_Model extends EA_Model
{
    protected string $table = '';

    // -------------------------------------------------------------------------
    // Protected helpers — used by every tenant-scoped model method
    // -------------------------------------------------------------------------

    protected function assert_tenant(): void
    {
        if (empty(MY_Controller::$tenant_id) && !MY_Controller::$global_admin_mode) {
            throw new TenantContextMissingException();
        }
    }

    protected function apply_tenant_where(array $where = []): array
    {
        $this->assert_tenant();
        if (MY_Controller::$global_admin_mode) {
            return $where; // no tenant filter — global admin sees all rows
        }
        // tenant_id first so MySQL can use the leading column of composite
        // indexes created in migration 061.
        return ['tenant_id' => MY_Controller::$tenant_id] + $where;
    }

    protected function inject_tenant_id(array $data): array
    {
        $this->assert_tenant();
        // Global admin creates rows with tenant_id = '' (global scope).
        $data['tenant_id'] = MY_Controller::$global_admin_mode ? '' : MY_Controller::$tenant_id;
        return $data;
    }

    /**
     * Conditionally add a tenant_id WHERE clause to the active query.
     * No-op when global_admin_mode is true (admin sees all tenants).
     */
    protected function add_tenant_scope(string $col = 'tenant_id'): void
    {
        if (!MY_Controller::$global_admin_mode) {
            $this->db->where($col, MY_Controller::$tenant_id);
        }
    }

    // -------------------------------------------------------------------------
    // Generic CRUD fallbacks — models with custom insert/update omit those
    // -------------------------------------------------------------------------

    public function find(int $id): ?array
    {
        $this->assert_tenant();
        $query = $this->db->where('id', $id);
        if (!MY_Controller::$global_admin_mode) {
            $query = $query->where('tenant_id', MY_Controller::$tenant_id);
        }
        $row = $query->get($this->table)->row_array();
        return $row ?: null;
    }

    public function get_where(array $where = []): array
    {
        $where = $this->apply_tenant_where($where);
        return $this->db->where($where)->get($this->table)->result_array();
    }

    public function delete(int $id): void
    {
        $this->assert_tenant();
        $query = $this->db->where('id', $id);
        if (!MY_Controller::$global_admin_mode) {
            $query = $query->where('tenant_id', MY_Controller::$tenant_id);
        }
        $query->delete($this->table);
    }
}
