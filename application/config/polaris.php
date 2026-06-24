<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Polaris fork — runtime configuration (S3-5).
 *
 * All secrets are injected via environment variables; no values live in
 * source control. Add entries to your deployment .env / Docker secrets and
 * mirror in config-sample.php comments so operators know what to set.
 * ---------------------------------------------------------------------------- */

// ── Bearer JWT (API path) ────────────────────────────────────────────────────
// Shared secret between Polaris (iss) and EA fork (aud). Rotate quarterly.
// Min 32 bytes of entropy. Store encrypted in Polaris tenant_secrets table.
$config['polaris_jwt_secret']   = getenv('EA_JWT_SIGNING_SECRET') ?: '';
$config['polaris_jwt_issuer']   = 'polaris';
$config['polaris_jwt_audience'] = 'ea-fork';

// ── Public booking page (S3-7) ────────────────────────────────────────────────
// Cloudflare Turnstile credentials for the /book/{slug} form.
$config['polaris_turnstile_sitekey'] = getenv('POLARIS_TURNSTILE_SITEKEY') ?: '';
$config['polaris_turnstile_secret']  = getenv('POLARIS_TURNSTILE_SECRET') ?: '';

// ── Tenant-sync webhook (S3-6) ────────────────────────────────────────────────
// HMAC-SHA256 shared secret used to verify payloads from Polaris on
// tenant create / update / suspend events.
$config['polaris_webhook_secret'] = getenv('POLARIS_WEBHOOK_SECRET') ?: '';

// ── Appointment-confirm callback (booking-confirmation loop) ──────────────────
// Base URL of the Polaris API that receives the signed appointment-confirm POST
// (its /v1/webhooks/ea endpoint) when a customer completes a booking that came
// from a bot booking link (carries ?polaris_ref=). The callback is HMAC-signed
// with polaris_webhook_secret above (Polaris verifies with the same shared key).
$config['polaris_api_base_url'] = getenv('POLARIS_API_BASE_URL') ?: 'https://api.getpolaris.my';
