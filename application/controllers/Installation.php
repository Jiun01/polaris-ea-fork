<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Easy!Appointments - Online Appointment Scheduler
 *
 * @package     EasyAppointments
 * @author      A.Tselegidis <alextselegidis@gmail.com>
 * @copyright   Copyright (c) Alex Tselegidis
 * @license     https://opensource.org/licenses/GPL-3.0 - GPLv3
 * @link        https://easyappointments.org
 * @since       v1.1.0
 * ---------------------------------------------------------------------------- */

/**
 * Installation controller.
 *
 * Handles the installation related operations.
 *
 * @package Controllers
 */
class Installation extends EA_Controller
{
    /**
     * Installation constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->load->model('admins_model');
        $this->load->model('settings_model');
        $this->load->model('services_model');
        $this->load->model('providers_model');
        $this->load->model('customers_model');

        $this->load->library('instance');
    }

    /**
     * Display the installation page.
     */
    public function index(): void
    {
        if (is_app_installed()) {
            redirect();
            return;
        }

        $this->load->view('pages/installation', [
            'base_url' => config('base_url'),
        ]);
    }

    /**
     * Installs Polaris Calendar on the server.
     *
     * Polaris install defaults baked here (B21 — Phase 3):
     *  - Timezone: Asia/Kuala_Lumpur (overrides PHP system default)
     *  - Sample-data seed REMOVED: no auto-created "Service" / "Jane Doe" /
     *    "James Doe" placeholder rows. Polaris's per-tenant provisioning
     *    code creates services / providers / customers per tenant via the
     *    REST API after install. A fresh boot lands with: 1 admin user, the
     *    company settings the operator entered, and zero domain data.
     */
    public function perform(): void
    {
        try {
            if (is_app_installed()) {
                return;
            }

            $admin = request('admin');
            $company = request('company');

            $this->instance->migrate();

            // Insert admin — hardcoded Asia/Kuala_Lumpur per Polaris install
            // default (Phase 3). Admin can change in their profile post-install.
            $admin['timezone'] = 'Asia/Kuala_Lumpur';
            $admin['settings']['username'] = $admin['username'];
            $admin['settings']['password'] = $admin['password'];
            $admin['settings']['notifications'] = true;
            $admin['settings']['calendar_view'] = CALENDAR_VIEW_DEFAULT;
            unset($admin['username'], $admin['password']);
            $admin['id'] = $this->admins_model->save($admin);

            session([
                'user_id' => $admin['id'],
                'user_email' => $admin['email'],
                'role_slug' => DB_SLUG_ADMIN,
                'language' => $admin['language'],
                'timezone' => $admin['timezone'],
                'username' => $admin['settings']['username'],
            ]);

            // Save company settings
            setting([
                'company_name' => $company['company_name'],
                'company_email' => $company['company_email'],
                'company_link' => $company['company_link'],
            ]);

            // Sample-data seed (Service / Jane Doe provider / James Doe
            // customer) intentionally removed per Polaris install discipline.
            // See PRD §Phase 3. Per-tenant provisioning happens in Polaris's
            // services/booking.py against the EA REST API.

            json_response([
                'success' => true,
            ]);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }
}
