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
 * Polaris fork — tenant slug shadow table (S3-6 Option A).
 *
 * Creates ea_polaris_tenants, a local copy of the Polaris-side tenants table
 * (slug + status only). Populated by a webhook from Polaris on tenant
 * create / update / suspend events. Zero per-request round-trip to Polaris
 * Postgres for slug → tenant_id resolution on the public booking path.
 *
 * REBASE NOTE: keep this as migration 062, always one after 061.
 * See the fork maintenance notes (operator-private docs) §G2 for the renumbering policy.
 */
class Migration_Add_ea_polaris_tenants_table extends EA_Migration
{
    /**
     * Upgrade method.
     */
    public function up(): void
    {
        if ($this->db->table_exists('polaris_tenants')) {
            return;
        }

        $this->dbforge->add_field([
            'tenant_id' => [
                'type'       => 'VARCHAR',
                'constraint' => '36',
                'null'       => false,
            ],
            'slug' => [
                'type'       => 'VARCHAR',
                'constraint' => '100',
                'null'       => false,
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['active', 'suspended'],
                'default'    => 'active',
                'null'       => false,
            ],
            'synced_at' => [
                // DATETIME (not TIMESTAMP) avoids the 2038 limit and is
                // timezone-neutral — MySQL TIMESTAMP is converted on read
                // according to the session time_zone variable.
                'type'    => 'DATETIME',
                'null'    => false,
                'default' => 'CURRENT_TIMESTAMP',
            ],
        ]);

        $this->dbforge->add_key('tenant_id', true);

        $this->dbforge->create_table('polaris_tenants', true, ['engine' => 'InnoDB']);

        // Unique index on slug for O(1) slug → tenant_id lookups. The table is
        // freshly created above, so the index cannot pre-exist; MySQL 8.0 does
        // not support `ADD INDEX IF NOT EXISTS` (MariaDB-only), so omit it.
        $prefixed = $this->db->dbprefix('polaris_tenants');
        $this->db->query(
            "ALTER TABLE `{$prefixed}`
             ADD UNIQUE INDEX `uq_polaris_tenants_slug` (`slug`)"
        );
    }

    /**
     * Downgrade method (local dev only).
     */
    public function down(): void
    {
        if ($this->db->table_exists('polaris_tenants')) {
            $this->dbforge->drop_table('polaris_tenants');
        }
    }
}
