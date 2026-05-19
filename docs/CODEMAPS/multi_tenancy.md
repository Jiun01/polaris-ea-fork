# Multi-Tenancy Codemap

**Last Updated:** 2026-05-20
**Entry Points:** MY_Controller::set_tenant_context(), MY_Model CRUD methods, migrations 061/062

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│  Request (API or public /book/{slug})                       │
├─────────────────────────────────────────────────────────────┤
│  ↓ (pre_controller hook)                                    │
│  BearerAuthHook::validate()  OR  Booking::slug()            │
│     (API path)                      (public booking)        │
│  ↓                              ↓                           │
│  MY_Controller::from_bearer()   Polaris_tenant_model::      │
│     (JWT decode)                   resolve_slug()           │
│  ↓                              ↓                           │
│  MY_Controller::set_tenant_context(tenant_id, branch_id)   │
│     (populate static context)                               │
│  ↓                                                          │
│  Controller action runs                                     │
│     ↓                                                        │
│     Models call find(), insert(), update(), delete()        │
│     ↓                                                        │
│     MY_Model auto-injects tenant_id WHERE clause            │
│     ↓                                                        │
│  MySQL returns ONLY this tenant's rows                      │
└─────────────────────────────────────────────────────────────┘
```

## Key Modules

| Module | Purpose | Exports | Key Methods |
|--------|---------|---------|-------------|
| **MY_Controller** | Static tenant context holder | set_tenant_context(), clear_tenant_context(), from_bearer() | — Stores tenant_id + tenant_branch_id as statics<br/>— Decodes Polaris JWT<br/>— Validates issuer/audience/tenant_id format |
| **MY_Model** | Auto-scoping CRUD base class | find(), get_where(), insert(), update(), delete() | — assert_tenant() — fails if context unset<br/>— apply_tenant_where() — prepends {tenant_id: ...} to WHERE<br/>— inject_tenant_id() — adds tenant_id to INSERT/UPDATE data |
| **TenantContextMissingException** | Thrown when tenant context is unset | RuntimeException subclass | — Thrown by MY_Model if MY_Controller::$tenant_id is null<br/>— Prevents accidental cross-tenant queries |
| **Polaris_tenant_model** | Slug → tenant_id lookup (shadow table) | resolve_slug(slug) → UUID or null | — Queries ea_polaris_tenants (populated by webhook)<br/>— Checks status = 'active' only<br/>— Used by Booking::slug() to establish context |
| **Migration 061** | Add tenant_id + tenant_branch_id columns | 10 multi-tenant tables | — Columns: NOT NULL tenant_id (UUID), NULL tenant_branch_id (UUID)<br/>— Composite index (tenant_id, id)<br/>— UNIQUE(tenant_id, email) on users<br/>— down() method for local dev rollback |
| **Migration 062** | Create ea_polaris_tenants shadow table | PK: tenant_id, UNIQUE INDEX on slug | — Schema: tenant_id, slug, status (ENUM), synced_at (DATETIME)<br/>— Populated by Polaris webhook on tenant sync events<br/>— Zero per-request round-trip to Polaris Postgres |

## Data Flow

### API Request (Bearer Token → Tenant Context)

```
GET /api/v1/appointments
Header: Authorization: Bearer eyJhbGc...

↓ (pre_controller hook)

BearerAuthHook::validate()
  ├─ extract_bearer_token() from Authorization header
  ├─ MY_Controller::from_bearer(token)
  │   ├─ JWT::decode(token, secret, 'HS256')
  │   ├─ Validate iss == 'polaris'
  │   ├─ Validate aud == 'ea-fork' (or array contains)
  │   ├─ Extract tenant_id claim
  │   ├─ UUID format check
  │   └─ MY_Controller::set_tenant_context(tenant_id)
  │       └─ MY_Controller::$tenant_id = tenant_id
  └─ Return to CI3 route handler

↓

Appointments_api_v1::index()
  ├─ $this->appointments_model->get_where([...])
  │   └─ MY_Model::get_where() auto-scopes
  │       └─ Prepends {tenant_id: MY_Controller::$tenant_id}
  │       └─ MySQL: WHERE tenant_id = ? AND <caller filters>
  └─ Returns ONLY this tenant's appointments

Response: 200 with tenant-scoped data
```

### Public Booking (Slug → Tenant Context)

```
GET /book/acme
  (no auth header)

↓ (routes.php route match)

Booking::slug('acme')
  ├─ Polaris_tenant_model::resolve_slug('acme')
  │   └─ SELECT tenant_id FROM ea_polaris_tenants
  │      WHERE slug = 'acme' AND status = 'active'
  │      LIMIT 1
  │   └─ Returns: '550e8400-e29b-41d4-a716-446655440000' (or null)
  ├─ If null: throw 404_exception (no such tenant or suspended)
  ├─ MY_Controller::set_tenant_context(tenant_id)
  │   └─ MY_Controller::$tenant_id = tenant_id
  └─ Render public booking form

↓

Form submission: POST /book/acme + customer fields

↓

Booking::save_appointment()
  ├─ $this->appointments_model->insert([...])
  │   └─ MY_Model::insert() auto-scopes
  │       ├─ inject_tenant_id(data) adds {tenant_id: MY_Controller::$tenant_id}
  │       └─ MySQL INSERT with tenant_id auto-populated
  └─ Appointment row saved with tenant_id = 'acme'

Response: 200 + confirmation email
```

### Tenant Context Lifecycle

```
┌──────────────────────────────────────────────────┐
│ PHP-FPM Worker Process                           │
├──────────────────────────────────────────────────┤
│ Request 1: /api/v1/appointments?token=ABC      │
│   MY_Controller::set_tenant_context('tenant-1') │
│   MY_Controller::$tenant_id = 'tenant-1'        │
│   Handle request                                │
│   → Response sent                               │
│                                                 │
│ SAME WORKER REUSED FOR REQUEST 2                │
│ Request 2: /api/v1/appointments?token=XYZ      │
│   BearerAuthHook::validate()                    │
│     ├─ MY_Controller::clear_tenant_context()   │
│     │   MY_Controller::$tenant_id = null        │ ← CRITICAL: clear stale state
│     ├─ Extract token XYZ                        │
│     └─ MY_Controller::from_bearer(token_xyz)    │
│         MY_Controller::$tenant_id = 'tenant-2'  │
│   Handle request (now with correct tenant)     │
│   → Response sent                               │
└──────────────────────────────────────────────────┘
```

## External Dependencies

- **firebase/php-jwt** — JWT encoding/decoding, HS256 signature verification, claim extraction
- **CodeIgniter 3** — CI_Model base class (extended by EA_Model), pre_controller hook system, CI_Migration base

## Multi-Tenant Tables (10)

All tables include `tenant_id VARCHAR(36) NOT NULL` and `tenant_branch_id VARCHAR(36) NULL` added in migration 061.

1. **ea_appointments** — bookings for services; scoped by tenant_id
2. **ea_services** — service definitions (haircut, consultation, etc.); scoped by tenant_id
3. **ea_service_categories** — folders for services; scoped by tenant_id
4. **ea_users** — providers, admins, secretaries; scoped by tenant_id; UNIQUE(tenant_id, email)
5. **ea_settings** — system + per-tenant config; tenant_id = '' for system defaults, real UUID for per-tenant
6. **ea_user_settings** — per-provider/admin/secretary settings; scoped by tenant_id
7. **ea_secretaries_providers** — assignment of secretaries to providers; scoped by tenant_id
8. **ea_blocked_periods** — provider unavailability; scoped by tenant_id
9. **ea_webhooks** — webhook registrations for tenant events; scoped by tenant_id
10. **ea_polaris_tenants** — shadow table (migration 062): {tenant_id, slug, status, synced_at}

## Composite Indexes (Migration 061)

All 10 multi-tenant tables get a composite index on `(tenant_id, id)` to make the common query pattern `WHERE tenant_id = ? AND id = ?` efficient. This is critical because MY_Model::find() hits this index on every appointment/service/user lookup.

Additionally, `ea_users` gets a UNIQUE constraint on `(tenant_id, email)` so no two users within the same tenant can share an email (cross-tenant email reuse is allowed).

## Security Properties

1. **Static Context Isolation** — Tenant context is stored in static properties, not request parameters. Callers cannot spoof tenant_id via query strings or form fields.
2. **Fail-Closed** — If tenant context is unset, MY_Model throws TenantContextMissingException on ANY query. No silent fallbacks; no "public" query mode.
3. **Hook-Based Auth** — BearerAuthHook runs at pre_controller (before route handler), so every /api/v1/* request is authenticated before code runs. No per-controller auth logic needed.
4. **Shadow Table Validation** — Polaris_tenant_model checks status = 'active' when resolving slugs; suspended tenants cannot be booked into.
5. **PHP-FPM State Reset** — BearerAuthHook::clear_tenant_context() on every request to prevent stale tenant leakage when PHP-FPM reuses worker processes.

## Related Codemaps

- **authentication.md** — JWT decoding, from_bearer() validation
- **public_booking.md** — Booking::slug() entry point, PDPA + Turnstile
- **api_surface.md** — /api/v1/* route structure, controller inheritance
