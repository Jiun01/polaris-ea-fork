# Polaris EA Fork — Codemaps

**Last Updated:** 2026-05-20

This directory contains architectural documentation for the Polaris-specific components in the Easy!Appointments fork. All codemaps are generated from the actual codebase and describe the structure as-is.

## Overview

The Polaris EA fork extends Easy!Appointments v1.5.2 with multi-tenant support, Bearer-token authentication, slug-routed public booking, and bot/consent protections. The fork runs as a containerized SaaS service (`ghcr.io/jiun01/polaris-ea:v1.5.2-r1`).

## Codemaps

| Codemap | Purpose |
|---------|---------|
| **multi_tenancy.md** | Tenant isolation: migrations, MY_Model/MY_Controller base classes, static tenant context |
| **authentication.md** | Bearer JWT auth: BearerAuthHook, from_bearer() factory, token validation, claim enforcement |
| **public_booking.md** | Slug-routed public booking: Booking::slug() entry, Polaris_tenant_model resolver, PDPA + Turnstile forms |
| **api_surface.md** | REST API: /api/v1/* routes, Bearer-scoped controllers, tenant isolation guarantees |
| **docker_build.md** | Multi-stage production image: Node asset compilation, PHP vendor install, FPM runtime, GHCR push |
| **e2e_testing.md** | Playwright E2E suite: test patterns, fixtures, page objects, CI/local execution, 6 test specs |

## Key Statistics

- **Multi-tenant tables:** 10 (appointments, services, users, settings, webhooks, etc.)
- **Core tenant-enforcing classes:** 2 (MY_Model, MY_Controller)
- **Migrations:** 2 (061: tenant_id columns + indexes; 062: ea_polaris_tenants shadow table)
- **Auth entry points:** 1 (BearerAuthHook pre_controller hook)
- **Public booking routes:** 1 (/book/{slug} → Booking::slug())
- **E2E test specs:** 5 (auth, api isolation, public booking hardening, slug-routed booking, brand sweep)
- **Docker stages:** 3 (Node assets, PHP vendor, production FPM)

## Integration Checklist

The integration work (polaris/v1.5.2-r1) completed the following tasks:

- **S3-3:** Migration 061/062 — tenant_id columns + ea_polaris_tenants shadow table
- **S3-4:** MY_Model — auto-scoping CRUD base class
- **S3-5:** BearerAuthHook + MY_Controller — JWT auth + static context holder
- **S3-6:** Polaris_tenant_model — slug → tenant_id resolver
- **S3-7:** Booking controller hardening — PDPA opt-in, Cloudflare Turnstile, IP rate limit
- **S3-8:** Multi-stage Dockerfile — Node + PHP vendor + FPM runtime
- **S3-9:** Playwright E2E suite — 5 comprehensive test specs
- **S3-10:** docker/build.sh — GHCR push script with .env auto-load

## Related Documentation

- **POLARIS_FORK.md** — fork overview, upstream baseline, production image, license
- **docs/INTEGRATION_CHECKLIST.md** — pre-Phase-7 task inventory + acceptance criteria
- **docs/docker.md** — local Docker Compose setup (deprecated for Polaris; multi-stage Dockerfile is the canonical build)
- **.github/CONTRIBUTING.md** — dev setup + PR process (updated with Docker/E2E steps)
- **application/config/polaris.php** — runtime configuration + environment variables
- **tests/e2e/** — full test suite source

## Quick Links

### For Developers

- Setting up a local dev environment: see **.github/CONTRIBUTING.md** → "Dev Setup"
- Running E2E tests: `npm run e2e` (or `npm run e2e:report` to view HTML)
- Building the production image: `bash docker/build.sh --push` (requires GITHUB_TOKEN)

### For Operators

- Environment variables: see **application/config/polaris.php** + the ENV reference at the end of this INDEX
- Production image: `ghcr.io/jiun01/polaris-ea:v1.5.2-r1`
- Multi-stage Dockerfile: `./Dockerfile` (asset compilation → vendor install → FPM runtime)

---

## Environment Variables Reference

All Polaris-specific configuration is injected at runtime via environment variables. No secrets live in source control.

### Bearer JWT (API authentication)

| Variable | Type | Required | Purpose |
|----------|------|----------|---------|
| `POLARIS_EA_JWT_SECRET` | string | yes | HS256 shared secret (min 32 bytes) between Polaris (iss) and EA fork (aud). Rotated quarterly. |

### Public Booking Page

| Variable | Type | Required | Purpose |
|----------|------|----------|---------|
| `POLARIS_TURNSTILE_SITEKEY` | string | yes | Cloudflare Turnstile site key for `/book/{slug}` form. Enables bot protection. |
| `POLARIS_TURNSTILE_SECRET` | string | yes | Cloudflare Turnstile secret (server-side verification). If unset, Turnstile is skipped with a warning log. |

### CORS (Cross-origin booking embeds)

| Variable | Type | Required | Purpose |
|----------|------|----------|---------|
| `POLARIS_CORS_ORIGIN` | string | no (defaults to `https://getpolaris.my`) | Allowed origin for CORS preflight + credentialed requests. Must not be a glob or wildcard. |

### Tenant Sync Webhook

| Variable | Type | Required | Purpose |
|----------|------|----------|---------|
| `POLARIS_WEBHOOK_SECRET` | string | yes | HMAC-SHA256 shared secret for verifying tenant create/update/suspend payloads from Polaris. |

### Configuration Sources

All variables are read from `application/config/polaris.php`:

```php
$config['polaris_jwt_secret']       = getenv('POLARIS_EA_JWT_SECRET') ?: '';
$config['polaris_turnstile_sitekey'] = getenv('POLARIS_TURNSTILE_SITEKEY') ?: '';
$config['polaris_turnstile_secret']  = getenv('POLARIS_TURNSTILE_SECRET') ?: '';
$config['polaris_webhook_secret']   = getenv('POLARIS_WEBHOOK_SECRET') ?: '';
```

For CORS origin, see `application/config/routes.php` line 97:

```php
$_polaris_allowed_origin = getenv('POLARIS_CORS_ORIGIN') ?: 'https://getpolaris.my';
```

### Example .env (local dev)

```bash
# Bearer JWT
POLARIS_EA_JWT_SECRET=your-test-secret-with-at-least-32-bytes-of-entropy

# Public booking page
POLARIS_TURNSTILE_SITEKEY=1x00000000000000000000AA
POLARIS_TURNSTILE_SECRET=2x0000000000000000000000000000000AA

# CORS
POLARIS_CORS_ORIGIN=http://localhost:3000

# Tenant sync webhook
POLARIS_WEBHOOK_SECRET=webhook-hmac-secret-at-least-32-bytes
```

### Example Docker run

```bash
docker run \
  -e POLARIS_EA_JWT_SECRET="$JWT_SECRET" \
  -e POLARIS_TURNSTILE_SITEKEY="$TURNSTILE_SITEKEY" \
  -e POLARIS_TURNSTILE_SECRET="$TURNSTILE_SECRET" \
  -e POLARIS_CORS_ORIGIN="https://getpolaris.my" \
  -e POLARIS_WEBHOOK_SECRET="$WEBHOOK_SECRET" \
  ghcr.io/jiun01/polaris-ea:v1.5.2-r1
```
