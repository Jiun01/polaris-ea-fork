<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Easy!Appointments - Online Appointment Scheduler
 *
 * @package     EasyAppointments
 * @author      A.Tselegidis <alextselegidis@gmail.com>
 * @copyright   Copyright (c) Alex Tselegidis
 * @license     https://opensource.org/licenses/GPL-3.0 - GPLv3
 * @link        https://easyappointments.org
 * @since       v1.3.2
 * ---------------------------------------------------------------------------- */

class Migration_Legal_contents extends EA_Migration
{
    /**
     * Upgrade method.
     */
    public function up(): void
    {
        if (!$this->db->get_where('settings', ['name' => 'display_cookie_notice'])->num_rows()) {
            $this->db->insert('settings', [
                'name' => 'display_cookie_notice',
                'value' => '0',
            ]);
        }

        if (!$this->db->get_where('settings', ['name' => 'cookie_notice_content'])->num_rows()) {
            $this->db->insert('settings', [
                'name' => 'cookie_notice_content',
                'value' => 'This site uses cookies to keep your booking session active. By continuing to use this booking page you consent to those cookies. Replace this default text via Settings > Legal in the admin panel before going live.',
            ]);
        }

        if (!$this->db->get_where('settings', ['name' => 'display_terms_and_conditions'])->num_rows()) {
            $this->db->insert('settings', [
                'name' => 'display_terms_and_conditions',
                'value' => '0',
            ]);
        }

        if (!$this->db->get_where('settings', ['name' => 'terms_and_conditions_content'])->num_rows()) {
            $this->db->insert('settings', [
                'name' => 'terms_and_conditions_content',
                'value' => 'These are the placeholder terms and conditions shipped with the Polaris Calendar booking system. Replace this text with the operator\'s actual terms via Settings > Legal in the admin panel before going live. Bookings made here are between the customer and the operator; Polaris is the platform provider only.',
            ]);
        }

        if (!$this->db->get_where('settings', ['name' => 'display_privacy_policy'])->num_rows()) {
            $this->db->insert('settings', [
                'name' => 'display_privacy_policy',
                'value' => '0',
            ]);
        }

        if (!$this->db->get_where('settings', ['name' => 'privacy_policy_content'])->num_rows()) {
            $this->db->insert('settings', [
                'name' => 'privacy_policy_content',
                'value' => 'This is placeholder privacy policy text shipped with the Polaris Calendar booking system. Replace via Settings > Legal in the admin panel with the operator\'s actual policy before going live. Personal data collected through this booking page is processed by the operator and stored on Polaris infrastructure; the operator is the data controller and Polaris is the data processor under Malaysia\'s PDPA (or the applicable jurisdiction).',
            ]);
        }

        if (!$this->db->table_exists('consents')) {
            $this->dbforge->add_field([
                'id' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'auto_increment' => true,
                ],
                'created' => [
                    'type' => 'TIMESTAMP',
                    'null' => true,
                ],
                'modified' => [
                    'type' => 'TIMESTAMP',
                    'null' => true,
                ],
                'first_name' => [
                    'type' => 'VARCHAR',
                    'constraint' => '256',
                    'null' => true,
                ],
                'last_name' => [
                    'type' => 'VARCHAR',
                    'constraint' => '256',
                    'null' => true,
                ],
                'email' => [
                    'type' => 'VARCHAR',
                    'constraint' => '512',
                    'null' => true,
                ],
                'ip' => [
                    'type' => 'VARCHAR',
                    'constraint' => '256',
                    'null' => true,
                ],
                'type' => [
                    'type' => 'VARCHAR',
                    'constraint' => '256',
                    'null' => true,
                ],
            ]);

            $this->dbforge->add_key('id', true);

            $this->dbforge->create_table('consents', true, ['engine' => 'InnoDB']);
        }
    }

    /**
     * Downgrade method.
     */
    public function down(): void
    {
        if ($this->db->get_where('settings', ['name' => 'display_cookie_notice'])->num_rows()) {
            $this->db->delete('settings', [
                'name' => 'display_cookie_notice',
            ]);
        }

        if ($this->db->get_where('settings', ['name' => 'cookie_notice_content'])->num_rows()) {
            $this->db->delete('settings', [
                'name' => 'cookie_notice_content',
            ]);
        }

        if ($this->db->get_where('settings', ['name' => 'display_terms_and_conditions'])->num_rows()) {
            $this->db->delete('settings', [
                'name' => 'display_terms_and_conditions',
            ]);
        }

        if ($this->db->get_where('settings', ['name' => 'terms_and_conditions_content'])->num_rows()) {
            $this->db->delete('settings', [
                'name' => 'terms_and_conditions_content',
            ]);
        }

        if ($this->db->get_where('settings', ['name' => 'display_privacy_policy'])->num_rows()) {
            $this->db->delete('settings', [
                'name' => 'display_privacy_policy',
            ]);
        }

        if ($this->db->get_where('settings', ['name' => 'privacy_policy_content'])->num_rows()) {
            $this->db->delete('settings', [
                'name' => 'privacy_policy_content',
            ]);
        }

        if ($this->db->table_exists('consents')) {
            $this->dbforge->drop_table('consents');
        }
    }
}
