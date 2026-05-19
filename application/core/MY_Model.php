<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Polaris fork — tenant isolation model base (S3-4).
 *
 * Extends EA_Model with three protected helpers (assert_tenant,
 * apply_tenant_where, inject_tenant_id) and five generic CRUD methods that
 * automatically scope every query to the current tenant.
 *
 * Subclasses set protected string $table and override individual CRUD methods
 * when they need richer behaviour (cascades, eager loading, etc.).
 * ---------------------------------------------------------------------------- */

if (!class_exists('TenantContextMissingException')) {
    require_once __DIR__ . '/TenantContextMissingException.php';
}

class MY_Model extends EA_Model
{
    protected string $table = '';

    // -------------------------------------------------------------------------
    // Protected helpers — called by every tenant-scoped CRUD method
    // -------------------------------------------------------------------------

    protected function assert_tenant(): void
    {
        if (empty(MY_Controller::$tenant_id)) {
            throw new TenantContextMissingException();
        }
    }

    protected function apply_tenant_where(array $where = []): array
    {
        $this->assert_tenant();
        // tenant_id first so MySQL can use the leading column of composite
        // indexes created in migration 061.
        return ['tenant_id' => MY_Controller::$tenant_id] + $where;
    }

    protected function inject_tenant_id(array $data): array
    {
        $this->assert_tenant();
        $data['tenant_id'] = MY_Controller::$tenant_id;
        return $data;
    }

    // -------------------------------------------------------------------------
    // Generic CRUD — subclasses override these with model-specific logic
    // -------------------------------------------------------------------------

    public function find(int $id): ?array
    {
        $this->assert_tenant();
        $row = $this->db
            ->where('id', $id)
            ->where('tenant_id', MY_Controller::$tenant_id)
            ->get($this->table)
            ->row_array();
        return $row ?: null;
    }

    public function get_where(array $where = []): array
    {
        $where = $this->apply_tenant_where($where);
        return $this->db->where($where)->get($this->table)->result_array();
    }

    public function insert(array $data): int
    {
        $data = $this->inject_tenant_id($data);
        $this->db->insert($this->table, $data);
        return (int) $this->db->insert_id();
    }

    public function update(int $id, array $data): void
    {
        $this->assert_tenant();
        unset($data['tenant_id']); // prevent caller from spoofing the tenant
        $this->db
            ->where('id', $id)
            ->where('tenant_id', MY_Controller::$tenant_id)
            ->update($this->table, $data);
    }

    public function delete(int $id): void
    {
        $this->assert_tenant();
        $this->db
            ->where('id', $id)
            ->where('tenant_id', MY_Controller::$tenant_id)
            ->delete($this->table);
    }
}
