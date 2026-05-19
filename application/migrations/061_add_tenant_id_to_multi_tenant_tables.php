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
            if (!$this->db->field_exists('tenant_id', $table)) {
                $this->dbforge->add_column($table, [
                    'tenant_id' => [
                        'type'       => 'VARCHAR',
                        'constraint' => '36',
                        'default'    => '',
                        'null'       => false,
                        'after'      => 'id',
                    ],
                ]);
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

            // Composite index (tenant_id, id) — covers the most common lookup pattern.
            $this->db->query(
                "ALTER TABLE `{$prefixed}`
                 ADD INDEX IF NOT EXISTS `idx_{$table}_tenant_id` (`tenant_id`, `id`)"
            );
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

            $this->db->query(
                "ALTER TABLE `{$prefixed}`
                 DROP INDEX IF EXISTS `idx_{$table}_tenant_id`"
            );

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

    private function _add_covering_indexes(): void
    {
        $ap = $this->db->dbprefix('appointments');
        $sv = $this->db->dbprefix('services');

        if ($this->db->table_exists('appointments')) {
            $this->db->query(
                "ALTER TABLE `{$ap}`
                 ADD INDEX IF NOT EXISTS `idx_appointments_tenant_start`
                     (`tenant_id`, `start_datetime`),
                 ADD INDEX IF NOT EXISTS `idx_appointments_tenant_provider`
                     (`tenant_id`, `id_users_provider`)"
            );
        }

        if ($this->db->table_exists('services')) {
            $this->db->query(
                "ALTER TABLE `{$sv}`
                 ADD INDEX IF NOT EXISTS `idx_services_tenant_private`
                     (`tenant_id`, `is_private`)"
            );
        }
    }

    private function _drop_covering_indexes(): void
    {
        $ap = $this->db->dbprefix('appointments');
        $sv = $this->db->dbprefix('services');

        if ($this->db->table_exists('appointments')) {
            $this->db->query(
                "ALTER TABLE `{$ap}`
                 DROP INDEX IF EXISTS `idx_appointments_tenant_start`,
                 DROP INDEX IF EXISTS `idx_appointments_tenant_provider`"
            );
        }

        if ($this->db->table_exists('services')) {
            $this->db->query(
                "ALTER TABLE `{$sv}`
                 DROP INDEX IF EXISTS `idx_services_tenant_private`"
            );
        }
    }

    private function _add_user_email_uniqueness(): void
    {
        if (!$this->db->table_exists('users')) {
            return;
        }

        $u = $this->db->dbprefix('users');

        // Add composite unique so the same email can exist in different tenants.
        $this->db->query(
            "ALTER TABLE `{$u}`
             ADD UNIQUE INDEX IF NOT EXISTS `uq_users_tenant_email`
                 (`tenant_id`, `email`)"
        );
    }

    private function _drop_user_email_uniqueness(): void
    {
        if (!$this->db->table_exists('users')) {
            return;
        }

        $u = $this->db->dbprefix('users');

        $this->db->query(
            "ALTER TABLE `{$u}`
             DROP INDEX IF EXISTS `uq_users_tenant_email`"
        );
    }
}
