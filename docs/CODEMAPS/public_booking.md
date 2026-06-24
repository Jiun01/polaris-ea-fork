# Public Booking Codemap

<!-- Generated: 2026-06-02 | Token estimate: ~1500 -->
**Last Updated:** 2026-06-02
**Entry Points:** Booking::slug($slug), GET /book/{slug}

> **Note:** the public submission handler is `Booking::register()` (not
> `save_appointment()`, which is the admin/calendar path). The diagram below
> labels it `save_appointment()` for historical reasons; treat it as `register()`.

## Architecture

```
┌──────────────────────────────────────────────────────────────────┐
│  Public Booking Flow (No Auth Required)                          │
├──────────────────────────────────────────────────────────────────┤
│  1. SLUG ROUTING                                                 │
│     GET /book/acme-corp                                          │
│     routes.php: $route['book/([a-z][a-z0-9-]{0,98})']           │
│     ↓ matches → Booking::slug('acme-corp')                       │
│                                                                  │
│  2. TENANT LOOKUP                                                │
│     Booking::slug($slug)                                         │
│     ├─ Polaris_tenant_model::resolve_slug('acme-corp')           │
│     │  ├─ Query: SELECT tenant_id FROM ea_polaris_tenants        │
│     │  │  WHERE slug = 'acme-corp' AND status = 'active'         │
│     │  └─ Returns: UUID or null                                  │
│     ├─ If null → throw 404_exception (tenant not found or        │
│     │   suspended)                                               │
│     ├─ MY_Controller::set_tenant_context(tenant_id)              │
│     │  └─ MY_Controller::$tenant_id = tenant_id (now scoped)     │
│     └─ Load booking form view                                    │
│                                                                  │
│  3. RENDER BOOKING FORM (GET /book/{slug})                       │
│     View receives:                                               │
│     ├─ Tenant's services (MY_Model auto-scoped)                  │
│     ├─ Providers (MY_Model auto-scoped)                          │
│     ├─ Turnstile sitekey (from config)                           │
│     ├─ PDPA opt-in checkbox (new in S3-7)                        │
│     └─ IP rate-limit headers (checked server-side)               │
│                                                                  │
│  4. CUSTOMER SUBMISSION (POST /book/{slug})                      │
│     Form data:                                                   │
│     ├─ Customer fields (name, email, phone, address, etc.)       │
│     ├─ Selected service + provider + start_datetime              │
│     ├─ Turnstile response token (g-recaptcha-response)           │
│     ├─ PDPA consent checkbox (required)                          │
│     └─ HTTP_X_FORWARDED_FOR (client IP for rate limit)           │
│                                                                  │
│     Booking::save_appointment()                                  │
│     ├─ Verify Turnstile token (unless disabled)                  │
│     │  ├─ POST to https://challenges.cloudflare.com/turnstile/   │
│     │  │   /api/verify with secret + token                       │
│     │  ├─ If score < 0.8, reject as bot                          │
│     │  └─ On network error, skip (log warning)                   │
│     ├─ Verify PDPA consent checkbox                              │
│     │  └─ Reject if unchecked                                    │
│     ├─ Check IP rate limit (X-Forwarded-For)                     │
│     │  ├─ Redis key: booking_ip:{ip}                             │
│     │  ├─ Limit: max 5 bookings per IP per minute                │
│     │  └─ Reject if exceeded (429 Too Many Requests)             │
│     ├─ Validate customer email (uniqueness per tenant, optional) │
│     ├─ Validate service exists and is available for provider     │
│     ├─ Check appointment availability (provider busy, PDPA etc)  │
│     ├─ Insert appointment row                                    │
│     │  └─ MY_Model::insert() auto-injects tenant_id              │
│     └─ Send confirmation email to customer                       │
│                                                                  │
│  5. RESPONSE (success or error)                                  │
│     ├─ 200 OK: appointment created, email sent                   │
│     ├─ 400 Bad Request: validation failed                        │
│     ├─ 404 Not Found: tenant not found / suspended               │
│     ├─ 429 Too Many Requests: rate limit exceeded                │
│     └─ 500 Internal Server Error: email send failed (rare)       │
│                                                                  │
└──────────────────────────────────────────────────────────────────┘
```

## Key Modules

| Module | File | Purpose |
|--------|------|---------|
| **Booking** | `application/controllers/Booking.php` | Public and private booking operations; slug($slug) entry point for public booking |
| **Polaris_tenant_model** | `application/models/Polaris_tenant_model.php` | Slug → tenant_id lookup (queries ea_polaris_tenants shadow table) |
| **Appointments_model** | `application/models/Appointments_model.php` | Appointment CRUD; extends MY_Model for tenant scoping |
| **Services_model** | `application/models/Services_model.php` | Service lookups; tenant-scoped |

## Slug Routing

### Route Definition (`application/config/routes.php`)

```php
// Root `/` has no default booking page → Home::index() 302-redirects to /login
// (staff login; was the marketing site before 2026-06-09 / image v1.6.0-r4).
$route['default_controller'] = 'home';

// Polaris public booking path: /book/{slug} → Booking::slug($slug).
// Pattern: lowercase letter + up to 98 alphanumeric/hyphen chars = valid slug.
// MUST appear before any catch-all routes.
$route['book/([a-z][a-z0-9-]{0,98})'] = 'booking/slug/$1';
```

**Slug Format:**
- Starts with lowercase letter: `[a-z]`
- Followed by 0–98 alphanumeric or hyphen chars: `[a-z0-9-]{0,98}`
- Total length: 1–99 chars
- Examples: `a`, `acme`, `acme-corp`, `my-salon-99`

### Booking::slug() Entry Point

```php
public function slug(string $slug): void
{
    // Polaris_tenant_model resolves slug to tenant_id
    $tenant_id = $this->polaris_tenant_model->resolve_slug($slug);
    
    if ($tenant_id === null) {
        // Slug not found or tenant suspended
        throw new 404_exception();
    }
    
    // Set tenant context for all subsequent MY_Model queries
    MY_Controller::set_tenant_context($tenant_id);
    
    // Load view with tenant's data
    $this->load->view('booking/index', [
        'services' => $this->services_model->get_where(),
        'providers' => $this->providers_model->get_where(),
        'turnstile_sitekey' => $this->config->item('polaris_turnstile_sitekey'),
    ]);
}
```

## Turnstile Bot Protection (S3-7)

### Configuration

```php
// application/config/polaris.php
$config['polaris_turnstile_sitekey'] = getenv('POLARIS_TURNSTILE_SITEKEY') ?: '';
$config['polaris_turnstile_secret']  = getenv('POLARIS_TURNSTILE_SECRET') ?: '';
```

### Client-Side

Embed in booking form:

```html
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js"></script>
<form method="POST" action="/book/acme">
  ...
  <div class="cf-turnstile" data-sitekey="{{ turnstile_sitekey }}"></div>
  <button type="submit">Book Now</button>
</form>
```

When user completes the challenge, Cloudflare injects:
```html
<input type="hidden" name="cf-turnstile-response" value="0.abc123...xyz">
```

### Server-Side Verification

In Booking::save_appointment():

```php
$turnstile_secret = (string) $this->config->item('polaris_turnstile_secret');

if ($turnstile_secret === '') {
    // If not configured, skip Turnstile (log warning)
    log_message('error', 'Polaris: POLARIS_TURNSTILE_SECRET not configured');
    // Proceed without bot check — risky but fails open
} else {
    $token = $_POST['cf-turnstile-response'] ?? '';
    
    // POST to Cloudflare
    $response = $this->curl->simple_post(
        'https://challenges.cloudflare.com/turnstile/v0/api/verify',
        json_encode([
            'secret' => $turnstile_secret,
            'response' => $token,
            'remoteip' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'],
        ])
    );
    
    $result = json_decode($response, true);
    
    if (!($result['success'] ?? false)) {
        // Failed Turnstile check
        http_response_code(400);
        exit(json_encode(['error' => 'Turnstile verification failed']));
    }
}
```

**Behavior:**
- If `POLARIS_TURNSTILE_SECRET` is set: strict verification (reject if fails)
- If unset: warning logged, booking proceeds (degrades gracefully)

## PDPA Consent Checkbox (S3-7)

Malaysia's Personal Data Protection Act (PDPA) requires explicit opt-in for booking system use.

### Client-Side

```html
<form method="POST" action="/book/acme">
  ...
  <label>
    <input type="checkbox" name="pdpa_consent" value="1" required>
    I consent to the collection and processing of my personal data
    per the Privacy Policy.
  </label>
  <button type="submit">Book Now</button>
</form>
```

### Server-Side Validation

```php
if (empty($_POST['pdpa_consent'])) {
    // Checkbox unchecked
    http_response_code(400);
    exit(json_encode(['error' => 'PDPA consent required']));
}
```

**Storage:** Polaris's booking subsystem stores the consent fact in the appointment row (optional `pdpa_consented_at` timestamp in a future migration).

## IP Rate Limiting (S3-7)

Prevents spam booking floods from single IP.

### Configuration

```php
// In Booking::save_appointment()
$max_bookings_per_minute = 5;
$client_ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'];
$cache_key = "booking_ip:{$client_ip}";

// Check Redis (or file cache if Redis unavailable)
$count = $this->cache->get($cache_key) ?? 0;

if ($count >= $max_bookings_per_minute) {
    http_response_code(429);
    exit(json_encode(['error' => 'Too many booking requests. Please try again later.']));
}

// Increment counter
$this->cache->save($cache_key, $count + 1, 60); // 60 second TTL
```

**Note:** Requires Redis or file-based caching configured in `application/config/cache.php`.

## Tenant Shadow Table (Migration 062)

The `ea_polaris_tenants` table is populated by a webhook from Polaris when a tenant is created, updated, or suspended.

### Schema

```sql
CREATE TABLE `ea_polaris_tenants` (
  `tenant_id` VARCHAR(36) NOT NULL PRIMARY KEY,
  `slug` VARCHAR(100) NOT NULL UNIQUE,
  `status` ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
  `synced_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
```

### Webhook Payload (from Polaris)

```json
{
  "event": "tenant.created",
  "tenant_id": "550e8400-e29b-41d4-a716-446655440000",
  "slug": "acme-corp",
  "status": "active",
  "timestamp": 1716192000,
  "signature": "sha256=abc123..."  // HMAC-SHA256 of body
}
```

### Webhook Receiver (Future)

```php
// POST /webhooks/polaris/tenant-sync
public function tenant_sync(): void
{
    $body = file_get_contents('php://input');
    $signature = $_SERVER['HTTP_X_POLARIS_SIGNATURE'] ?? '';
    
    // Verify HMAC
    $secret = $this->config->item('polaris_webhook_secret');
    $expected = 'sha256=' . hash_hmac('sha256', $body, $secret);
    
    if (!hash_equals($signature, $expected)) {
        http_response_code(401);
        exit('Invalid signature');
    }
    
    $payload = json_decode($body, true);
    
    // Update shadow table
    $this->db->replace('polaris_tenants', [
        'tenant_id' => $payload['tenant_id'],
        'slug' => $payload['slug'],
        'status' => $payload['status'],
        'synced_at' => date('Y-m-d H:i:s'),
    ]);
    
    http_response_code(200);
}
```

## Cross-Tenant Isolation Verification

E2E tests confirm that:

1. **Slug isolation:** Request `/book/acme` loads only acme's services + providers
2. **Data isolation:** GET /api/v1/appointments (with acme's Bearer token) returns only acme's appointments
3. **No leakage:** Curl for another tenant's data returns 404 or empty
4. **Admin access:** Admin panel loads per-tenant data only (no cross-tenant admin views)

See **e2e_testing.md** for test specs.

## Polaris booking UX + URL behavior (2026-06-02)

**Clean URLs.** `config['index_page']=''` → routed URLs drop `/index.php/`
(e.g. `/book/{slug}`). nginx `try_files` routes them; old `/index.php/*` URLs
still resolve. `base_url`/`site_url` (and the JS `base_url` script var) are
clean.

**Per-request tenant binding (commit `f1a72d4a`).** `Booking::slug()` stores
`booking_tenant_id` in one shared session key, so two different tenants'
booking pages open in one browser clobber each other (a submit/reschedule
then runs against the wrong tenant → "customer/provider ID does not exist").
Fix: each page exposes its own `booking_slug` (script var), posts it with
`registerAppointment` / `getAvailableHours` / `getUnavailableDates`, and
`Booking::apply_booking_slug_context()` re-resolves the tenant per-request via
`from_slug()` (session fallback when absent). See multi_tenancy.md §Scoping
Caveats #2.

**"Go to booking page" link targets (commit `64174093`).**
- Admin backend footer (`components/backend_footer.php`) → the logged-in
  tenant's own `/book/{slug}` (via `Polaris_tenant_model::resolve_tenant_slug`).
- Booking confirmation/finish page (`pages/booking_confirmation.php`) → the
  tenant's `company_link` (its website), falling back to `site_url()`.

**Step wizard.** `pages/booking.js` renders a 4-step indicator. When a tenant
has exactly **one service AND one provider**, step 1 (Service & Provider) is
auto-selected, hidden, and the remaining boxes renumber to `1 2 3`. With ≥2
services/providers all four steps show (`1 2 3 4`) and the customer picks.

**Assets are a build gotcha.** `assets/**/*.min.{js,css}` are gitignored build
artifacts from `gulp scripts`/`gulp styles` (`gulp compile` in the Docker
image). The cache-buster `config/app.php → cache_busting_token` (`?TSJxx`) is
what changes the asset URL. **In prod it's auto-stamped:** every EA image build
writes its unique image tag into the token (`EA_ASSET_TOKEN` build arg, see
`polaris/infra/docker-bake.hcl` + EA `Dockerfile`), so each release busts all
asset caches automatically. The static `TSJxx` in `app.php` is only the fallback
for a plain local `docker build` — bump it by hand only there. Local dev
bind-mounts the host tree over the image's compiled assets, so run `gulp compile`
after pulling JS/CSS source changes.

## Related Codemaps

- **multi_tenancy.md** — Tenant context setup via MY_Model; scoping caveats
- **authentication.md** — Bearer token auth (API only, not used for public booking)
- **e2e_testing.md** — Booking-related test scenarios
