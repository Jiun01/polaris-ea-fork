# Multi-Tenancy Codemap

<!-- Generated: 2026-06-02 | Files scanned: 18 | Token estimate: ~1250 -->

**Last Updated:** 2026-06-02
**Entry Points:** MY_Controller::set_tenant_context(), MY_Model CRUD methods, migrations 070/071 (renumbered from 061/062 post-v1.6.0 rebase)

## Architecture

Three paths set the tenant context — all converge at `MY_Controller::set_tenant_context()`:

```
┌──────────────────┬──────────────────┬──────────────────────┐
│  /api/v1/*       │  /book/{slug}    │  /backend /calendar  │
│  (API)           │  (public)        │  /services etc.      │
│                  │                  │  (admin session)     │
├──────────────────┼──────────────────┼──────────────────────┤
│ pre_controller   │ routes.php →     │ EA_Controller::      │
│ BearerAuthHook   │ Booking::slug()  │   __construct()      │
│                  │                  │ (before ensure_user) │
│ from_bearer()    │ from_slug()      │ resolve_session_     │
│ JWT decode       │ shadow table     │   tenant()           │
└───────┬──────────┴────────┬─────────┴──────────┬───────────┘
        └───────────────────┴────────────────────┘
                            ↓
        MY_Controller::set_tenant_context(tenant_id)
               MY_Controller::$tenant_id = <UUID>
                            ↓
          MY_Model::find/get_where/insert/update/delete
              auto-injects WHERE tenant_id = ?
                            ↓
            MySQL returns ONLY this tenant's rows
```

## Key Modules

| Module | Purpose | Key Methods |
|--------|---------|-------------|
| **MY_Controller** | Static tenant context holder; base of EA_Controller | set_tenant_context(), clear_tenant_context(), from_bearer(), from_slug(), resolve_session_tenant() |
| **EA_Controller** | Base for all EA controllers; extends MY_Controller | Calls resolve_session_tenant() at constructor start (before any model access) |
| **MY_Model** | Auto-scoping CRUD base | assert_tenant(), apply_tenant_where(), inject_tenant_id(), find(), get_where(), delete() |
| **TenantContextMissingException** | Thrown when context unset | Thrown by MY_Model; prevents cross-tenant queries |
| **Polaris_tenant_model** | Slug ↔ tenant_id lookup | resolve_slug(slug) → UUID or null; resolve_tenant_slug(uuid) → slug (reverse, for admin footer + booking page self-binding); both query ea_polaris_tenants, status='active' |
| **Migration 061** | tenant_id columns on 10 tables | NOT NULL UUID, composite index (tenant_id, id), UNIQUE(tenant_id, email) on users |
| **Migration 062** | ea_polaris_tenants shadow table | {tenant_id, slug, status ENUM, synced_at}; populated by webhook |

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

### Admin Backend (Session → Tenant Context)

```
GET /index.php/calendar
Cookie: ea_session=<hash>   (set at login)

↓ EA_Controller::__construct()
  ├─ parent::__construct()  (CI_Controller bootstraps session/db)
  ├─ if empty(self::$tenant_id):
  │     MY_Controller::resolve_session_tenant()
  │       ├─ $CI->load->is_loaded('session')  → true
  │       ├─ $session->userdata('user_id')    → 6
  │       ├─ SELECT tenant_id FROM users WHERE id = 6
  │       └─ MY_Controller::set_tenant_context('aaaa...-0001')
  ├─ load->library('accounts')
  ├─ ensure_user_exists()       ← tenant context now set; no throw
  └─ ... configure language, vars, rate_limit ...

↓ Calendar::index()
  ├─ appointments_model->get_where([...])
  │   └─ MY_Model auto-scopes to tenant_id = 'aaaa...-0001'
  └─ Renders calendar with ONLY this tenant's appointments
```

Note: `Accounts::check_login()` also calls `set_tenant_context()` during the
login POST itself (from `user_settings.tenant_id`), so models used inside the
login flow (users_model->find, roles_model->find) are also properly scoped.

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

## Multi-Tenant Tables (11)

All tables include `tenant_id VARCHAR(36) NOT NULL` and `tenant_branch_id VARCHAR(36) NULL` added in migration 070 (renumbered from 061 post-v1.6.0 rebase).

1. **ea_appointments** — bookings for services; scoped by tenant_id
2. **ea_services** — service definitions (haircut, consultation, etc.); scoped by tenant_id
3. **ea_service_categories** — folders for services; scoped by tenant_id
4. **ea_users** — providers, admins, secretaries; scoped by tenant_id; UNIQUE(tenant_id, email)
5. **ea_settings** — system + per-tenant config; tenant_id = '' for system defaults, real UUID for per-tenant
6. **ea_user_settings** — per-provider/admin/secretary settings; scoped by tenant_id
7. **ea_secretaries_providers** — assignment of secretaries to providers; scoped by tenant_id
8. **ea_blocked_periods** — provider unavailability; scoped by tenant_id
9. **ea_webhooks** — webhook registrations for tenant events; scoped by tenant_id
10. **ea_working_plan_exceptions** — provider working-plan exceptions (new in upstream v1.6.0, migration 065); tenant-scoped via migration 070; no `id` column (uses start_date/id_users_provider as PK)
11. **ea_polaris_tenants** — shadow table (migration 071, renumbered from 062): {tenant_id, slug, status, synced_at}

## Composite Indexes (Migration 070)

All 11 multi-tenant tables get a composite index on `(tenant_id, id)` (where `id` exists) to make the common query pattern `WHERE tenant_id = ? AND id = ?` efficient. This is critical because MY_Model::find() hits this index on every appointment/service/user lookup.

**Special case — id-less tables:** `ea_user_settings`, `ea_secretaries_providers`, and `ea_working_plan_exceptions` have no `id` column. Migration 070 guards index creation via `information_schema` queries (MySQL 8.0 rejects `ADD INDEX IF NOT EXISTS` syntax) and skips the `AFTER id` clause for these tables.

Additionally, `ea_users` gets a UNIQUE constraint on `(tenant_id, email)` so no two users within the same tenant can share an email (cross-tenant email reuse is allowed).

## Security Properties

1. **Static Context Isolation** — Tenant context is stored in static properties, not request parameters. Callers cannot spoof tenant_id via query strings or form fields.
2. **Fail-Closed** — If tenant context is unset, MY_Model throws TenantContextMissingException on ANY query. No silent fallbacks; no "public" query mode.
3. **Hook-Based API Auth** — BearerAuthHook runs at pre_controller so every /api/v1/* request is authenticated before code runs. Internal routes (`/api/v1/internal/*`) bypass Bearer and use HMAC-SHA256 instead.
4. **Constructor-Time Session Auth** — EA_Controller resolves session tenant at the very start of its constructor (before libraries or models load), preventing TenantContextMissingException in ensure_user_exists() and similar early calls.
5. **Shadow Table Validation** — Polaris_tenant_model checks status = 'active' when resolving slugs; suspended tenants cannot be booked into.
6. **PHP-FPM State Reset** — BearerAuthHook::clear_tenant_context() on every request prevents stale tenant leakage when PHP-FPM reuses worker processes.
7. **Pre-login Guard** — setting_helper returns default value when no tenant is set, so Login/Installation pages load without throwing.

## Scoping Caveats (verified 2026-06-02 isolation audit)

Auto-scoping is enforced **only by MY_Model**. `EA_DB_query_builder` is empty — there is **no global DB-level tenant filter**. Two consequences to remember:

1. **Raw `$this->db` queries are NOT scoped.** Any controller/model that builds a query with `$this->db->from('<tenant table>')` instead of a MY_Model method bypasses isolation and reads every tenant's rows. Audit found exactly one such site — `Calendar::get_calendar_appointments()` "all" view (two raw appointment queries) — fixed (commit `5fd8383c`) by adding the same `if (!global_admin_mode) where('tenant_id', …)` guard. **Reviewer rule:** grep `\$this->db->from\(` in controllers; a raw query on a tenant table is a leak. (The find/update/destroy IDOR paths are safe — model `find()`/`save()`/`delete()` re-apply the tenant WHERE.)

2. **Public booking context is per-page, not just per-session.** `Booking::slug()` stores `booking_tenant_id` in a single CI session key, so two *different* tenants' booking pages open in one browser clobber each other (a submit could run against the wrong tenant → "customer/provider ID does not exist"). Fixed (commit `f1a72d4a`): the booking page exposes its own `booking_slug` (script var), posts it with `register`/`get_available_hours`/`get_unavailable_dates`, and `Booking::apply_booking_slug_context()` re-resolves the tenant per-request via `from_slug()` (session fallback when absent). Accepting the slug per-request adds no new exposure — slugs are already public and `from_slug()` only resolves to a real `active` tenant.

## Related Codemaps

- **authentication.md** — JWT decoding, from_bearer() validation
- **public_booking.md** — Booking::slug() entry point, PDPA + Turnstile
- **api_surface.md** — /api/v1/* route structure, controller inheritance
