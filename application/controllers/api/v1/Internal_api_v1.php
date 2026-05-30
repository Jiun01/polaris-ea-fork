<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Polaris fork — internal webhook controller (S3-6).
 *
 * Handles POST /api/v1/internal/tenants/sync from the Polaris API.
 * Auth is HMAC-SHA256 (not Bearer JWT) — the calling service signs the raw
 * request body with POLARIS_WEBHOOK_SECRET and sends it as X-Polaris-Signature.
 * ---------------------------------------------------------------------------- */

class Internal_api_v1 extends EA_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('polaris_tenant_model');
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
        // ── Auth: HMAC-SHA256 signature check ──────────────────────────────────
        $secret = $this->config->item('polaris_webhook_secret');

        if (empty($secret)) {
            http_response_code(500);
            echo json_encode(['error' => 'Webhook secret not configured']);
            return;
        }

        $raw_body = file_get_contents('php://input');
        $sig_header = $_SERVER['HTTP_X_POLARIS_SIGNATURE'] ?? '';

        $expected = 'sha256=' . hash_hmac('sha256', $raw_body, $secret);

        if (!hash_equals($expected, $sig_header)) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid signature']);
            return;
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
}
