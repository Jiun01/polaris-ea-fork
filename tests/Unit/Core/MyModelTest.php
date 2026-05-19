<?php

namespace Tests\Unit\Core;

use MY_Controller;
use TenantContextMissingException;
use PHPUnit\Framework\TestCase;

// Load core files that CI3's autoloader won't pick up in test mode.
// BASEPATH and APPPATH are defined by tests/bootstrap.php.
require_once APPPATH . 'core/TenantContextMissingException.php';
require_once APPPATH . 'core/MY_Controller.php';
require_once APPPATH . 'core/MY_Model.php';

/**
 * Fluent DB stub that captures WHERE conditions and DML arguments
 * so tests can assert on tenant isolation without a real database.
 */
class FakeDb
{
    private array $pending_where = [];

    public array $captured_where        = [];
    public array $captured_insert_data  = [];
    public array $captured_update_where = [];
    public array $captured_update_data  = [];

    public function where(string|array $key, mixed $value = null): static
    {
        if (is_array($key)) {
            foreach ($key as $k => $v) {
                $this->pending_where[$k] = $v;
            }
        } else {
            $this->pending_where[$key] = $value;
        }

        return $this;
    }

    public function get(string $table): static
    {
        $this->captured_where  = $this->pending_where;
        $this->pending_where   = [];

        return $this;
    }

    public function result_array(): array
    {
        return [];
    }

    public function row_array(): array
    {
        return [];
    }

    public function insert(string $table, array $data): bool
    {
        $this->captured_insert_data = $data;

        return true;
    }

    public function insert_id(): int
    {
        return 42;
    }

    public function update(string $table, array $data): bool
    {
        $this->captured_update_where = $this->pending_where;
        $this->captured_update_data  = $data;
        $this->pending_where         = [];

        return true;
    }

    public function delete(string $table): bool
    {
        $this->captured_where = $this->pending_where;
        $this->pending_where  = [];

        return true;
    }

    public function limit(int $value, int $offset = 0): static
    {
        return $this;
    }
}

/**
 * @covers MY_Model
 */
class MyModelTest extends TestCase
{
    private FakeDb $db;

    // Override to skip get_instance() — not needed for pure-isolation model tests.
    public static function setUpBeforeClass(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new FakeDb();
        MY_Controller::clear_tenant_context();
    }

    private function makeModel(): \MY_Model
    {
        $model = new class extends \MY_Model {
            protected string $table = 'users';
        };

        // Direct property assignment shadows CI3's __get magic, injecting the
        // fake DB without touching the live database connection.
        $model->db = $this->db;

        return $model;
    }

    public function testGetWhereFiltersToCurrentTenant(): void
    {
        MY_Controller::set_tenant_context('tenant-abc');
        $model = $this->makeModel();

        $model->get_where(['email' => 'test@example.com']);

        $this->assertArrayHasKey('tenant_id', $this->db->captured_where);
        $this->assertSame('tenant-abc', $this->db->captured_where['tenant_id']);
    }

    public function testInsertAutoInjectsTenantId(): void
    {
        MY_Controller::set_tenant_context('tenant-xyz');
        $model = $this->makeModel();

        $model->insert(['email' => 'new@example.com', 'name' => 'Alice']);

        $this->assertArrayHasKey('tenant_id', $this->db->captured_insert_data);
        $this->assertSame('tenant-xyz', $this->db->captured_insert_data['tenant_id']);
    }

    public function testUpdateScopesByTenantPreventingCrossTenantWrite(): void
    {
        MY_Controller::set_tenant_context('tenant-abc');
        $model = $this->makeModel();

        // id=99 would belong to a different tenant in a real DB; the WHERE
        // clause must include tenant_id so the update is a no-op cross-tenant.
        $model->update(99, ['email' => 'changed@example.com']);

        $this->assertArrayHasKey('tenant_id', $this->db->captured_update_where);
        $this->assertSame('tenant-abc', $this->db->captured_update_where['tenant_id']);
    }

    public function testMissingTenantContextThrows(): void
    {
        // tenant_id is null — cleared in setUp()
        $model = $this->makeModel();

        $this->expectException(TenantContextMissingException::class);

        $model->get_where([]);
    }
}
