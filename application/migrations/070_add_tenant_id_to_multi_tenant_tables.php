<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Easy!Appointments - Online Appointment Scheduler
 *
 * @package     EasyAppointments
 * @author      A.Tselegidis <alextselegidis@gmail.com>
 * @copyright   Copyright (c) Alex Tselegidis
 * @license     https://opensource.org/licenses/GPL-3.0 - GPLv3
 * @link        https://easyappointments.org
 * @since       v1.5.2
 * ---------------------------------------------------------------------------- */

/**
 * Polaris fork — multi-tenancy primitives (S3-3).
 *
 * Adds tenant_id (NOT NULL, UUID string) and tenant_branch_id (NULL, UUID string)
 * to every multi-tenant table, plus composite indexes on hot query paths and a
 * per-tenant email uniqueness constraint on users.
 *
 * PRODUCTION ASSUMPTION: this migration runs on a fresh install — all affected
 * tables are empty. The settings table may contain rows seeded by earlier
 * migrations (012, 033, etc.); those rows receive tenant_id = '' (empty string)
 * as a sentinel for system-level defaults visible before a tenant is provisioned.
 * Polaris's provisioning service creates per-tenant settings rows with real UUIDs.
 *
 * REBASE NOTE: keep this as the LAST migration index. On upstream rebase to
 * EA v1.5.3+, renumber 061/062 past upstream's new last index.
 * See docs/Polaris V2/EA_Whitelabel/FORK_MAINTENANCE.md §G2.
 */
class Migration_Add_tenant_id_to_multi_tenant_tables extends EA_Migration
{
    /**
     * Tables that receive tenant_id + tenant_branch_id columns.
     * Uses CI3 unprefixed names — the DB prefix (ea_) is applied by the driver.
     */
    private array $tenant_tables = [
        'appointments',
        'services',
        'service_categories',
        'users',
        'settings',
        'user_settings',
        'secretaries_providers',
        'blocked_periods',
        'webhooks',
        // Added on the v1.6.0 rebase: upstream moved provider working-plan
        // exceptions out of user_settings JSON into a dedicated table (migration
        // 065). It is provider-owned, therefore tenant-scoped data. The
        // table_exists() guard + this migration running at index 070 (after
        // upstream's 065) ensure the column is added to the existing table.
        'working_plan_exceptions',
    ];

    /**
     * Upgrade method.
     */
    public function up(): void
    {
        foreach ($this->tenant_tables as $table) {
            if (!$this->db->table_exists($table)) {
                continue;
            }

            // tenant_id — NOT NULL with DEFAULT '' so existing rows (e.g. seeded
            // settings) don't cause a strict-mode rejection. The application layer
            // (MY_Model) always sets a real UUID before any INSERT.
            //
            // Junction / settings tables (user_settings, secretaries_providers) have
            // no `id` column, so only pin the column AFTER `id` when it exists.
            if (!$this->db->field_exists('tenant_id', $table)) {
                $tenant_id_col = [
                    'type'       => 'VARCHAR',
                    'constraint' => '36',
                    'default'    => '',
                    'null'       => false,
                ];
                if ($this->db->field_exists('id', $table)) {
                    $tenant_id_col['after'] = 'id';
                }
                $this->dbforge->add_column($table, ['tenant_id' => $tenant_id_col]);
            }

            // tenant_branch_id — nullable; FK value set by Polaris provisioning.
            if (!$this->db->field_exists('tenant_branch_id', $table)) {
                $this->dbforge->add_column($table, [
                    'tenant_branch_id' => [
                        'type'       => 'VARCHAR',
                        'constraint' => '36',
                        'null'       => true,
                        'default'    => null,
                        'after'      => 'tenant_id',
                    ],
                ]);
            }

            $prefixed = $this->db->dbprefix($table);

            // Composite index covering the most common lookup pattern. Junction /
            // settings tables (user_settings, secretaries_providers) have no `id`
            // column — index on tenant_id alone there.
            $cols = $this->db->field_exists('id', $table) ? '`tenant_id`, `id`' : '`tenant_id`';
            $this->_add_index($prefixed, "idx_{$table}_tenant_id", $cols);
        }

        // Additional hot-path covering indexes.
        $this->_add_covering_indexes();

        // Per-tenant email uniqueness on users.
        $this->_add_user_email_uniqueness();
    }

    /**
     * Downgrade method (local dev only — not safe on a populated production DB).
     */
    public function down(): void
    {
        foreach ($this->tenant_tables as $table) {
            if (!$this->db->table_exists($table)) {
                continue;
            }

            $prefixed = $this->db->dbprefix($table);

            $this->_drop_index($prefixed, "idx_{$table}_tenant_id");

            if ($this->db->field_exists('tenant_branch_id', $table)) {
                $this->dbforge->drop_column($table, 'tenant_branch_id');
            }

            if ($this->db->field_exists('tenant_id', $table)) {
                $this->dbforge->drop_column($table, 'tenant_id');
            }
        }

        $this->_drop_covering_indexes();
        $this->_drop_user_email_uniqueness();
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Whether an index already exists on the (already-prefixed) table.
     *
     * MySQL 8.0 does NOT support `ADD/DROP INDEX IF [NOT] EXISTS` (that is a
     * MariaDB extension), so index creation is guarded via information_schema.
     */
    private function _index_exists(string $prefixed_table, string $index_name): bool
    {
        $row = $this->db
            ->query(
                'SELECT COUNT(1) AS c FROM information_schema.STATISTICS
                 WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
                [$prefixed_table, $index_name],
            )
            ->row_array();

        return !empty($row['c']);
    }

    private function _add_index(string $prefixed_table, string $index_name, string $cols, bool $unique = false): void
    {
        if ($this->_index_exists($prefixed_table, $index_name)) {
            return;
        }

        $kind = $unique ? 'UNIQUE INDEX' : 'INDEX';
        $this->db->query("ALTER TABLE `{$prefixed_table}` ADD {$kind} `{$index_name}` ({$cols})");
    }

    private function _drop_index(string $prefixed_table, string $index_name): void
    {
        if (!$this->_index_exists($prefixed_table, $index_name)) {
            return;
        }

        $this->db->query("ALTER TABLE `{$prefixed_table}` DROP INDEX `{$index_name}`");
    }

    private function _add_covering_indexes(): void
    {
        $ap = $this->db->dbprefix('appointments');
        $sv = $this->db->dbprefix('services');

        if ($this->db->table_exists('appointments')) {
            $this->_add_index($ap, 'idx_appointments_tenant_start', '`tenant_id`, `start_datetime`');
            $this->_add_index($ap, 'idx_appointments_tenant_provider', '`tenant_id`, `id_users_provider`');
        }

        if ($this->db->table_exists('services')) {
            $this->_add_index($sv, 'idx_services_tenant_private', '`tenant_id`, `is_private`');
        }
    }

    private function _drop_covering_indexes(): void
    {
        $ap = $this->db->dbprefix('appointments');
        $sv = $this->db->dbprefix('services');

        if ($this->db->table_exists('appointments')) {
            $this->_drop_index($ap, 'idx_appointments_tenant_start');
            $this->_drop_index($ap, 'idx_appointments_tenant_provider');
        }

        if ($this->db->table_exists('services')) {
            $this->_drop_index($sv, 'idx_services_tenant_private');
        }
    }

    private function _add_user_email_uniqueness(): void
    {
        if (!$this->db->table_exists('users')) {
            return;
        }

        $u = $this->db->dbprefix('users');

        // Add composite unique so the same email can exist in different tenants.
        $this->_add_index($u, 'uq_users_tenant_email', '`tenant_id`, `email`', true);
    }

    private function _drop_user_email_uniqueness(): void
    {
        if (!$this->db->table_exists('users')) {
            return;
        }

        $u = $this->db->dbprefix('users');

        $this->_drop_index($u, 'uq_users_tenant_email');
    }
}
