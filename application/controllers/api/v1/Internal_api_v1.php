<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Polaris fork — internal webhook controller (S3-6).
 *
 * Handles internal Polaris-to-EA calls. Auth is HMAC-SHA256 (not Bearer JWT):
 * the calling service signs the raw request body with POLARIS_WEBHOOK_SECRET
 * and sends it as X-Polaris-Signature. The BearerAuthHook bypasses Bearer JWT
 * for the api/v1/internal/* prefix, so these methods set the tenant context
 * themselves (MY_Controller::set_tenant_context) before touching any model.
 *
 * Endpoints:
 *   POST   /api/v1/internal/tenants/sync                  -> tenants_sync
 *   POST   /api/v1/internal/tenants/provision             -> provision_admin
 *   DELETE /api/v1/internal/tenants/{tenant_id}/provision -> deprovision
 * ---------------------------------------------------------------------------- */

class Internal_api_v1 extends EA_Controller
{
    /**
     * Default provider working plan used when the tenant has no company plan yet.
     * Mon–Fri 09:00–18:00 with a 13:00–14:00 break; weekends off. Stored as a
     * JSON string (matches docker/seed_e2e.sql).
     */
    private const DEFAULT_WORKING_PLAN = '{"sunday":null,"monday":{"start":"09:00","end":"18:00","breaks":[{"start":"13:00","end":"14:00"}]},"tuesday":{"start":"09:00","end":"18:00","breaks":[{"start":"13:00","end":"14:00"}]},"wednesday":{"start":"09:00","end":"18:00","breaks":[{"start":"13:00","end":"14:00"}]},"thursday":{"start":"09:00","end":"18:00","breaks":[{"start":"13:00","end":"14:00"}]},"friday":{"start":"09:00","end":"18:00","breaks":[{"start":"13:00","end":"14:00"}]},"saturday":null}';

    public function __construct()
    {
        parent::__construct();

        $this->load->model('polaris_tenant_model');
        $this->load->model('admins_model');
        $this->load->model('providers_model');
        $this->load->model('services_model');
        $this->load->library('accounts');
        $this->load->library('email_messages');
        // NOTE: customer-facing links built here (e.g. the provision reset link)
        // use the public base_url, pinned for the whole /api/v1/* surface by the
        // pin_api_public_base_url post_controller_constructor hook — see
        // application/hooks/api_public_base_url.php. Do NOT rely on site_url()
        // resolving against the request host on this internal path.
    }

    /**
     * POST /api/v1/internal/tenants/sync
     *
     * Body: { "tenant_id": "<uuid>", "slug": "<slug>", "status": "active|suspended" }
     *
     * Upserts the ea_polaris_tenants row. Idempotent — safe to replay.
     */
    public function tenants_sync(): void
    {
        $raw_body = $this->authenticate_hmac();
        if ($raw_body === null) {
            return; // 401/500 already emitted
        }

        // ── Parse body ─────────────────────────────────────────────────────────
        $payload = json_decode($raw_body, true);

        $tenant_id = $payload['tenant_id'] ?? null;
        $slug      = $payload['slug']      ?? null;
        $status    = $payload['status']    ?? 'active';

        if (empty($tenant_id) || empty($slug)) {
            http_response_code(422);
            echo json_encode(['error' => 'tenant_id and slug are required']);
            return;
        }

        if (!in_array($status, ['active', 'suspended'], true)) {
            http_response_code(422);
            echo json_encode(['error' => 'status must be active or suspended']);
            return;
        }

        // ── Upsert ea_polaris_tenants ──────────────────────────────────────────
        $this->db->query(
            'INSERT INTO ea_polaris_tenants (tenant_id, slug, status)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE slug = VALUES(slug), status = VALUES(status)',
            [$tenant_id, $slug, $status],
        );

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'tenant_id' => $tenant_id, 'slug' => $slug, 'status' => $status]);
    }

    /**
     * POST /api/v1/internal/tenants/provision
     *
     * Body: { "tenant_id": "<uuid>", "slug": "<slug>", "email": "<admin email>",
     *         "first_name": "<str>", "last_name": "<str>" }
     *
     * Provisions (idempotently) an EA admin login + a default provider + a
     * default service for the tenant, then generates a password-reset token and
     * emails the admin a reset link so they set their own password. Replaying is
     * safe — existing entities are reused; the reset email is (re)sent each call.
     */
    public function provision_admin(): void
    {
        $raw_body = $this->authenticate_hmac();
        if ($raw_body === null) {
            return; // 401/500 already emitted
        }

        try {
            $payload = json_decode($raw_body, true) ?: [];

            $tenant_id  = $payload['tenant_id'] ?? null;
            $slug       = $payload['slug'] ?? null;
            $email      = $payload['email'] ?? null;
            $first_name = trim((string) ($payload['first_name'] ?? '')) ?: 'Calendar';
            $last_name  = trim((string) ($payload['last_name'] ?? '')) ?: 'Owner';

            if (empty($tenant_id) || empty($slug) || empty($email)) {
                http_response_code(422);
                echo json_encode(['error' => 'tenant_id, slug and email are required']);
                return;
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                http_response_code(422);
                echo json_encode(['error' => 'email is not a valid address']);
                return;
            }

            // Tenant context MUST be set before any model call — the internal
            // route bypasses BearerAuthHook so it is not auto-populated. Every
            // model insert/select is then scoped to this tenant.
            MY_Controller::set_tenant_context($tenant_id);

            // ── 1. Admin (the reset-link recipient / owner login) ──────────────
            // Username is GLOBALLY unique (validate_username is not tenant-scoped).
            // Use the operator-provided username when given, else derive it from the
            // globally-unique slug. Throwaway password — the tenant sets their own
            // via the reset link below.
            $admin = $this->find_admin($tenant_id);
            if ($admin === null) {
                $req_username = trim((string) ($payload['username'] ?? ''));
                $admin_username = ($req_username !== '') ? $req_username : ('admin-' . $slug);

                if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $admin_username)) {
                    http_response_code(422);
                    echo json_encode([
                        'error' => 'invalid_username',
                        'message' => 'username must be 3-50 chars: letters, digits, . _ -',
                    ]);
                    return;
                }

                // Global uniqueness pre-check (usernames are not tenant-scoped). A
                // concurrent identical provision can still race to the save() below,
                // which the outer try/catch turns into a 500 — acceptable for an
                // operator-driven endpoint.
                $taken = $this->db
                    ->select('id_users')
                    ->from('user_settings')
                    ->where('username', $admin_username)
                    ->limit(1)
                    ->get()
                    ->row_array();
                if ($taken) {
                    http_response_code(409);
                    echo json_encode([
                        'error' => 'username_taken',
                        'message' => 'That username is already in use. Choose another.',
                    ]);
                    return;
                }

                $admin_id = $this->admins_model->save([
                    'first_name' => $first_name,
                    'last_name'  => $last_name,
                    'email'      => $email,
                    'settings'   => [
                        'username' => $admin_username,
                        'password' => bin2hex(random_bytes(16)),
                        'notifications' => true,
                        'calendar_view' => CALENDAR_VIEW_DEFAULT,
                    ],
                ]);
                $admin = ['id' => $admin_id, 'email' => $email, 'username' => $admin_username];
            }

            // ── 2. Default service ─────────────────────────────────────────────
            $service_id = $this->find_service_ids($tenant_id)[0] ?? null;
            if ($service_id === null) {
                $service_id = $this->services_model->save([
                    'name'              => 'General',
                    'duration'          => 30,
                    'price'             => 0,
                    'currency'          => '',
                    'description'       => '',
                    'location'          => '',
                    'slot_interval'     => 15,
                    'attendants_number' => 1,
                    'is_private'        => 0,
                ]);
            }

            // ── 3. Default provider (the bookable calendar) ────────────────────
            // Distinct email from the admin — (tenant_id, email) is uniquely
            // indexed across ALL users regardless of role.
            $provider_id = $this->find_user_ids($tenant_id, DB_SLUG_PROVIDER)[0] ?? null;
            if ($provider_id === null) {
                $provider_id = $this->providers_model->save([
                    'first_name' => $first_name,
                    'last_name'  => $last_name,
                    'email'      => 'provider.' . $slug . '@bookings.getpolaris.my',
                    'services'   => [$service_id],
                    'settings'   => [
                        'username' => 'owner-' . $slug,
                        'password' => bin2hex(random_bytes(16)),
                        'working_plan' => setting('company_working_plan') ?: self::DEFAULT_WORKING_PLAN,
                        'working_plan_exceptions' => '{}',
                        'calendar_view' => CALENDAR_VIEW_DEFAULT,
                    ],
                ]);
            }

            // ── 4. Password-reset email to the admin ───────────────────────────
            // Mirrors Recovery::perform — generate token, build link, send.
            $reset_data = $this->accounts->generate_reset_token($admin['username'], $admin['email']);
            $company_color = setting('company_color');
            $reset_link = site_url('recovery/reset?token=' . $reset_data['token']);
            $settings = [
                'company_name' => setting('company_name'),
                'company_link' => setting('company_link'),
                'company_email' => setting('company_email'),
                'company_color' =>
                    !empty($company_color) && $company_color != DEFAULT_COMPANY_COLOR ? $company_color : null,
            ];
            $this->email_messages->send_password_reset_link($reset_link, $reset_data['email'], $settings);

            http_response_code(201);
            header('Content-Type: application/json');
            echo json_encode([
                'ok' => true,
                'admin_user_id' => (int) $admin['id'],
                'provider_id' => (int) $provider_id,
                'service_id' => (int) $service_id,
                'username' => $admin['username'] ?? null,
            ]);
        } catch (Throwable $e) {
            log_message('error', 'EA provision_admin failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'provisioning_failed', 'message' => $e->getMessage()]);
        }
    }

    /**
     * DELETE /api/v1/internal/tenants/{tenant_id}/provision
     *
     * Removes the tenant's EA admin(s) + provider(s) + service(s). Best-effort
     * and idempotent — deleting an already-gone account is a no-op. Used only by
     * the Polaris hard-delete path.
     */
    public function deprovision(string $tenant_id = ''): void
    {
        $raw_body = $this->authenticate_hmac();
        if ($raw_body === null) {
            return;
        }

        try {
            if (empty($tenant_id)) {
                http_response_code(422);
                echo json_encode(['error' => 'tenant_id is required']);
                return;
            }

            MY_Controller::set_tenant_context($tenant_id);

            $deleted = ['admins' => 0, 'providers' => 0, 'services' => 0];

            foreach ($this->find_user_ids($tenant_id, DB_SLUG_PROVIDER) as $id) {
                try { $this->providers_model->delete((int) $id); $deleted['providers']++; }
                catch (Throwable $e) { log_message('error', 'EA deprovision provider ' . $id . ': ' . $e->getMessage()); }
            }
            foreach ($this->find_service_ids($tenant_id) as $id) {
                try { $this->services_model->delete((int) $id); $deleted['services']++; }
                catch (Throwable $e) { log_message('error', 'EA deprovision service ' . $id . ': ' . $e->getMessage()); }
            }
            foreach ($this->find_user_ids($tenant_id, DB_SLUG_ADMIN) as $id) {
                // Raw scoped delete, NOT Admins_model::delete — that guards
                // against removing a tenant's last admin (a sensible UI rule).
                // Here the tenant's whole EA footprint is being torn down, so
                // the sole admin MUST go; otherwise its row lingers and its
                // globally-unique username ('admin-<slug>') stays reserved,
                // blocking re-provisioning of the same slug. FK ON DELETE
                // CASCADE clears the user_settings row.
                try {
                    $this->db->where('id', $id)->where('tenant_id', $tenant_id)->delete('users');
                    $deleted['admins']++;
                } catch (Throwable $e) {
                    log_message('error', 'EA deprovision admin ' . $id . ': ' . $e->getMessage());
                }
            }

            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'tenant_id' => $tenant_id, 'deleted' => $deleted]);
        } catch (Throwable $e) {
            log_message('error', 'EA deprovision failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'deprovision_failed', 'message' => $e->getMessage()]);
        }
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * Verify the HMAC-SHA256 signature and return the raw request body, or emit
     * a 401/500 response and return null. Mirrors tenants_sync's inline check.
     */
    private function authenticate_hmac(): ?string
    {
        $secret = $this->config->item('polaris_webhook_secret');

        if (empty($secret)) {
            http_response_code(500);
            echo json_encode(['error' => 'Webhook secret not configured']);
            return null;
        }

        $raw_body = file_get_contents('php://input');
        $sig_header = $_SERVER['HTTP_X_POLARIS_SIGNATURE'] ?? '';
        $expected = 'sha256=' . hash_hmac('sha256', $raw_body, $secret);

        if (!hash_equals($expected, $sig_header)) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid signature']);
            return null;
        }

        return $raw_body;
    }

    /**
     * Find the first admin (id, email, username) for a tenant, or null.
     */
    private function find_admin(string $tenant_id): ?array
    {
        $row = $this->db
            ->select('users.id, users.email, user_settings.username')
            ->from('users')
            ->join('roles', 'roles.id = users.id_roles', 'inner')
            ->join('user_settings', 'user_settings.id_users = users.id', 'inner')
            ->where('roles.slug', DB_SLUG_ADMIN)
            ->where('users.tenant_id', $tenant_id)
            ->limit(1)
            ->get()
            ->row_array();

        return $row ?: null;
    }

    /**
     * Find all user ids for a tenant with the given role slug.
     *
     * @return int[]
     */
    private function find_user_ids(string $tenant_id, string $role_slug): array
    {
        $rows = $this->db
            ->select('users.id')
            ->from('users')
            ->join('roles', 'roles.id = users.id_roles', 'inner')
            ->where('roles.slug', $role_slug)
            ->where('users.tenant_id', $tenant_id)
            ->get()
            ->result_array();

        return array_map(static fn($r) => (int) $r['id'], $rows);
    }

    /**
     * Find all service ids for a tenant.
     *
     * @return int[]
     */
    private function find_service_ids(string $tenant_id): array
    {
        $rows = $this->db
            ->select('id')
            ->from('services')
            ->where('tenant_id', $tenant_id)
            ->get()
            ->result_array();

        return array_map(static fn($r) => (int) $r['id'], $rows);
    }
}
