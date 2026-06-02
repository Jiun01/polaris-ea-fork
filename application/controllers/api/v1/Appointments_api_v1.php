<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Easy!Appointments - Online Appointment Scheduler
 *
 * @package     EasyAppointments
 * @author      A.Tselegidis <alextselegidis@gmail.com>
 * @copyright   Copyright (c) Alex Tselegidis
 * @license     https://opensource.org/licenses/GPL-3.0 - GPLv3
 * @link        https://easyappointments.org
 * @since       v1.5.0
 * ---------------------------------------------------------------------------- */

/**
 * Appointments API v1 controller.
 *
 * @package Controllers
 */
class Appointments_api_v1 extends EA_Controller
{
    /**
     * Appointments_api_v1 constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->load->model('appointments_model');
        $this->load->model('customers_model');
        $this->load->model('providers_model');
        $this->load->model('services_model');
        $this->load->model('settings_model');

        $this->load->library('api');
        $this->load->library('webhooks_client');
        $this->load->library('synchronization');
        $this->load->library('notifications');

        $this->api->auth();

        $this->api->model('appointments_model');
    }

    /**
     * Get an appointment collection.
     */
    public function index(): void
    {
        try {
            $keyword = $this->api->request_keyword();

            $limit = $this->api->request_limit();

            $offset = $this->api->request_offset();

            $order_by = $this->api->request_order_by();

            $fields = $this->api->request_fields();

            $with = $this->api->request_with();

            $where = null;

            // Date query param.

            $date = request('date');

            if (!empty($date)) {
                $where['DATE(start_datetime)'] = (new DateTime($date))->format('Y-m-d');
            }

            // From query param.

            $from = request('from');

            if (!empty($from)) {
                $where['DATE(start_datetime) >='] = (new DateTime($from))->format('Y-m-d');
            }

            // Till query param.

            $till = request('till');

            if (!empty($till)) {
                $where['DATE(end_datetime) <='] = (new DateTime($till))->format('Y-m-d');
            }

            // Service ID query param.

            $service_id = request('serviceId');

            if (!empty($service_id)) {
                $where['id_services'] = $service_id;
            }

            // Provider ID query param.

            $provider_id = request('providerId');

            if (!empty($provider_id)) {
                $where['id_users_provider'] = $provider_id;
            }

            // Customer ID query param.

            $customer_id = request('customerId');

            if (!empty($customer_id)) {
                $where['id_users_customer'] = $customer_id;
            }

            $appointments = empty($keyword)
                ? $this->appointments_model->get($where, $limit, $offset, $order_by)
                : $this->appointments_model->search($keyword, $limit, $offset, $order_by);

            foreach ($appointments as &$appointment) {
                $this->appointments_model->api_encode($appointment);

                $this->aggregates($appointment);

                if (!empty($fields)) {
                    $this->appointments_model->only($appointment, $fields);
                }

                if (!empty($with)) {
                    $this->appointments_model->load($appointment, $with);
                }
            }

            json_response($appointments);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Load the relations of the current appointment if the "aggregates" query parameter is present.
     *
     * This is a compatibility addition to the appointment resource which was the only one to support it.
     *
     * Use the "attach" query parameter instead as this one will be removed.
     *
     * @param array $appointment Appointment data.
     *
     * @deprecated Since 1.5
     */
    private function aggregates(array &$appointment): void
    {
        $aggregates = request('aggregates') !== null;

        if ($aggregates) {
            $appointment['service'] = $this->services_model->find(
                $appointment['id_services'] ?? ($appointment['serviceId'] ?? null),
            );
            $appointment['provider'] = $this->providers_model->find(
                $appointment['id_users_provider'] ?? ($appointment['providerId'] ?? null),
            );
            $appointment['customer'] = $this->customers_model->find(
                $appointment['id_users_customer'] ?? ($appointment['customerId'] ?? null),
            );
            $this->services_model->api_encode($appointment['service']);
            $this->providers_model->api_encode($appointment['provider']);
            $this->customers_model->api_encode($appointment['customer']);
        }
    }

    /**
     * Get a single appointment.
     *
     * @param int|null $id Appointment ID.
     */
    public function show(?int $id = null): void
    {
        try {
            $occurrences = $this->appointments_model->get(['id' => $id]);

            if (empty($occurrences)) {
                response('', 404);

                return;
            }

            $fields = $this->api->request_fields();

            $with = $this->api->request_with();

            $appointment = $this->appointments_model->find($id);

            $this->appointments_model->api_encode($appointment);

            if (!empty($fields)) {
                $this->appointments_model->only($appointment, $fields);
            }

            if (!empty($with)) {
                $this->appointments_model->load($appointment, $with);
            }

            json_response($appointment);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Store a new appointment.
     */
    public function store(): void
    {
        try {
            $appointment = request();

            // Polaris booking-node contract: high-level shape with snake_case ids
            // (service_id/provider_id), a nested customer object (find-or-create),
            // and ISO-8601 datetimes. The normalizer emits EA's native DB-key
            // array directly, so api_decode (which maps the camelCase api/v1 keys
            // and would otherwise STRIP the DB keys) must be skipped for it.
            // Native api/v1 payloads still go through api_decode as before.
            if ($this->polaris_is_payload($appointment)) {
                $appointment = $this->polaris_normalize_appointment($appointment);
            } else {
                $this->appointments_model->api_decode($appointment);
            }

            if (array_key_exists('id', $appointment)) {
                unset($appointment['id']);
            }

            if (!array_key_exists('end_datetime', $appointment)) {
                $appointment['end_datetime'] = $this->appointments_model->calculate_end_datetime($appointment);
            }

            $appointment_id = $this->appointments_model->save($appointment);

            $created_appointment = $this->appointments_model->find($appointment_id);

            $this->notify_and_sync_appointment($created_appointment);

            $this->appointments_model->api_encode($created_appointment);

            json_response($created_appointment, 201);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Send the required notifications and trigger syncing after saving an appointment.
     *
     * @param array $appointment Appointment data.
     * @param string $action Performed action ("store" or "update").
     */
    private function notify_and_sync_appointment(array $appointment, string $action = 'store'): void
    {
        $manage_mode = $action === 'update';

        $service = $this->services_model->find($appointment['id_services']);

        $provider = $this->providers_model->find($appointment['id_users_provider']);

        $customer = $this->customers_model->find($appointment['id_users_customer']);

        $company_color = setting('company_color');

        $settings = [
            'company_name' => setting('company_name'),
            'company_email' => setting('company_email'),
            'company_link' => setting('company_link'),
            'company_color' =>
                !empty($company_color) && $company_color != DEFAULT_COMPANY_COLOR ? $company_color : null,
            'date_format' => setting('date_format'),
            'time_format' => setting('time_format'),
        ];

        $this->synchronization->sync_appointment_saved($appointment, $service, $provider, $customer, $settings);

        $this->notifications->notify_appointment_saved(
            $appointment,
            $service,
            $provider,
            $customer,
            $settings,
            $manage_mode,
        );

        $this->webhooks_client->trigger(WEBHOOK_APPOINTMENT_SAVE, $appointment);
    }

    /**
     * Translate the Polaris booking-node payload into EA's native appointment
     * array. Polaris sends { service_id, provider_id, customer: {...},
     * start_datetime, end_datetime, notes } with ISO-8601 datetimes; EA's save
     * path wants id_services / id_users_provider / id_users_customer +
     * 'Y-m-d H:i:s'. The customer is found-or-created here. Payloads without the
     * Polaris markers (native api/v1 clients) are returned unchanged.
     *
     * @param array $a Raw request body.
     * @return array EA-native appointment array (or the original payload).
     */
    private function polaris_is_payload(array $a): bool
    {
        return array_key_exists('service_id', $a) ||
            array_key_exists('provider_id', $a) ||
            (array_key_exists('customer', $a) && is_array($a['customer']));
    }

    private function polaris_normalize_appointment(array $a): array
    {
        $out = [];

        if (isset($a['service_id'])) {
            $out['id_services'] = (int) $a['service_id'];
        }

        if (isset($a['provider_id'])) {
            $out['id_users_provider'] = (int) $a['provider_id'];
        }

        if (!empty($a['start_datetime'])) {
            $out['start_datetime'] = (new DateTime((string) $a['start_datetime']))->format('Y-m-d H:i:s');
        }

        if (!empty($a['end_datetime'])) {
            $out['end_datetime'] = (new DateTime((string) $a['end_datetime']))->format('Y-m-d H:i:s');
        }

        if (isset($a['notes'])) {
            $out['notes'] = (string) $a['notes'];
        }

        $out['id_users_customer'] = $this->polaris_resolve_customer((array) ($a['customer'] ?? []));

        return $out;
    }

    /**
     * Find-or-create an EA customer from the Polaris customer object and return
     * its id. WhatsApp/IG customers have no email, so a stable synthetic email is
     * derived from the phone (lets EA's required-email validation pass and dedups
     * repeat bookings by the same contact).
     *
     * @param array $cust { first_name, last_name, email, phone }
     * @return int Customer id.
     */
    private function polaris_resolve_customer(array $cust): int
    {
        $phone = trim((string) ($cust['phone'] ?? ($cust['phone_number'] ?? '')));
        $email = trim((string) ($cust['email'] ?? ''));

        if ($email === '') {
            $digits = preg_replace('/[^0-9]/', '', $phone);
            $email = ($digits !== '' ? $digits : 'unknown') . '@wa.polaris.local';
        }

        $record = [
            'first_name' => (string) ($cust['first_name'] ?? 'Customer'),
            'last_name' => (string) ($cust['last_name'] ?? ''),
            'email' => $email,
            'phone_number' => $phone,
        ];

        // Reuse an existing customer when one matches (EA matches on email).
        try {
            $existing = $this->customers_model->find_record_id($record);
            if (!empty($existing)) {
                return (int) $existing;
            }
        } catch (Throwable $e) {
            // No match — fall through and create a new customer.
        }

        return (int) $this->customers_model->save($record);
    }

    /**
     * Update an appointment.
     *
     * @param int $id Appointment ID.
     */
    public function update(int $id): void
    {
        try {
            $occurrences = $this->appointments_model->get(['id' => $id]);

            if (empty($occurrences)) {
                response('', 404);

                return;
            }

            $original_appointment = $occurrences[0];

            $appointment = request();

            $this->appointments_model->api_decode($appointment, $original_appointment);

            $appointment_id = $this->appointments_model->save($appointment);

            $updated_appointment = $this->appointments_model->find($appointment_id);

            $this->notify_and_sync_appointment($updated_appointment, 'update');

            $this->appointments_model->api_encode($updated_appointment);

            json_response($updated_appointment);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Delete an appointment.
     *
     * @param int $id Appointment ID.
     */
    public function destroy(int $id): void
    {
        try {
            $occurrences = $this->appointments_model->get(['id' => $id]);

            if (empty($occurrences)) {
                response('', 404);

                return;
            }

            $deleted_appointment = $occurrences[0];

            $service = $this->services_model->find($deleted_appointment['id_services']);

            $provider = $this->providers_model->find($deleted_appointment['id_users_provider']);

            $customer = $this->customers_model->find($deleted_appointment['id_users_customer']);

            $company_color = setting('company_color');

            $settings = [
                'company_name' => setting('company_name'),
                'company_email' => setting('company_email'),
                'company_link' => setting('company_link'),
                'company_color' =>
                    !empty($company_color) && $company_color != DEFAULT_COMPANY_COLOR ? $company_color : null,
                'date_format' => setting('date_format'),
                'time_format' => setting('time_format'),
            ];

            $this->appointments_model->delete($id);

            $this->synchronization->sync_appointment_deleted($deleted_appointment, $provider);

            $this->notifications->notify_appointment_deleted(
                $deleted_appointment,
                $service,
                $provider,
                $customer,
                $settings,
            );

            $this->webhooks_client->trigger(WEBHOOK_APPOINTMENT_DELETE, $deleted_appointment);

            response('', 204);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }
}
