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
$config['polaris_jwt_secret']   = getenv('POLARIS_EA_JWT_SECRET') ?: '';
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
