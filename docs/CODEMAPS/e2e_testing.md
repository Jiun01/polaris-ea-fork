# E2E Testing Codemap

**Last Updated:** 2026-05-20
**Entry Points:** tests/e2e/, playwright.config.ts

## Architecture

```
┌──────────────────────────────────────────────────────────────┐
│  Playwright Test Suite                                       │
├──────────────────────────────────────────────────────────────┤
│  playwright.config.ts (main configuration)                  │
│  ├─ BASE_URL: http://localhost:8080 (dev) or CI env         │
│  ├─ Chrome browser + headless mode                          │
│  ├─ Retries: 0 (local) or 2 (CI)                            │
│  ├─ Workers: unlimited (local) or 1 (CI, shared DB)         │
│  ├─ Screenshots/videos on failure                           │
│  └─ HTML + JSON reporters                                   │
│                                                              │
│  tests/e2e/                                                 │
│  ├─ fixtures/ (JWT tokens, mailpit integration)             │
│  ├─ pages/ (Page Object Models)                             │
│  └─ *.spec.ts (5 test suites, ~50 cases)                    │
│                                                              │
└──────────────────────────────────────────────────────────────┘
```

## Configuration (`playwright.config.ts`)

```typescript
export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: true,              // Tests run in parallel
    forbidOnly: !!process.env['CI'],  // Fail CI if test.only exists
    retries: process.env['CI'] ? 2 : 0,
    workers: process.env['CI'] ? 1 : undefined,

    use: {
        baseURL: process.env['BASE_URL'] ?? 'http://localhost:8080',
        actionTimeout: 10_000,         // 10s per click/fill/etc
        navigationTimeout: 30_000,     // 30s per page load
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
        trace: 'on-first-retry',
    },

    projects: [
        { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    ],
});
```

## Test Execution

### Local Development

```bash
# Install dependencies
npm install

# Start local services (Docker Compose)
docker compose up -d

# Run all tests
npm run e2e

# Run specific suite
npx playwright test tests/e2e/auth_bearer.spec.ts

# Run with UI (browser visible)
npx playwright test --ui

# View HTML report
npm run e2e:report
```

### CI Environment

```bash
# Set BASE_URL to deployed instance
BASE_URL=https://calendar.getpolaris.my npm run e2e

# Auto-retries + HTML report in CI artifacts
# See .github/workflows/e2e.yml
```

## Test Suites

### 1. auth_bearer.spec.ts — Bearer Token Authentication

**Purpose:** Verify JWT auth on /api/v1/* routes

**Cases:**
- ✅ Valid token → 200 OK + tenant-scoped response
- ✅ Missing Authorization header → 401 token_missing
- ✅ Invalid Bearer format → 401 token_invalid
- ✅ Expired token → 401 token_expired
- ✅ Wrong secret (tampered signature) → 401 token_invalid
- ✅ Missing iss claim → 401 token_invalid
- ✅ Missing aud claim → 401 token_invalid
- ✅ Wrong tenant_id format (not UUID) → 401 token_invalid
- ✅ Clock skew tolerance (token exp in future ± 10s) → 200 OK

**Fixtures:** Valid JWT tokens (tenant-1, tenant-2), expired tokens, malformed tokens

### 2. api_isolation.spec.ts — Cross-Tenant API Isolation

**Purpose:** Verify /api/v1/* routes return only tenant-scoped data

**Cases:**
- ✅ Tenant-1 with Bearer(tenant-1) → sees only tenant-1 appointments
- ✅ Tenant-1 with Bearer(tenant-2) → sees only tenant-2 appointments
- ✅ GET /api/v1/appointments (tenant-1 token) + POST (tenant-2 token) → no cross-contamination
- ✅ Attempt to query another tenant's appointment ID → 404 or empty
- ✅ Settings table (system-level rows) visible but tenant rows scoped

**Fixtures:** Two tenant contexts (tenant-1, tenant-2), pre-created appointments

### 3. booking_hardening.spec.ts — Public Booking Form Hardening

**Purpose:** Verify Turnstile, PDPA, rate limiting on /book/{slug}

**Cases:**
- ✅ GET /book/valid-slug → 200, form loads
- ✅ GET /book/invalid-slug → 404
- ✅ GET /book/suspended-tenant → 404
- ✅ POST without Turnstile response → 400 or submission fails (if sitekey is set)
- ✅ POST with failed Turnstile token (mock) → rejected
- ✅ POST without PDPA checkbox → 400 (validation error)
- ✅ POST with PDPA checked + valid Turnstile → 200 (appointment created)
- ✅ 5+ bookings from same IP in 60s → 429 Too Many Requests (rate limit)

**Fixtures:** Mailpit integration (verify confirmation email), Turnstile mock API

### 4. booking_sweep.spec.ts — Public Booking Happy Path

**Purpose:** End-to-end booking flow (slug routing, data isolation, email)

**Cases:**
- ✅ Customer navigates to /book/acme-corp
- ✅ Form loads with acme's services + providers only
- ✅ Customer selects service, provider, date/time
- ✅ Completes Turnstile + PDPA
- ✅ Submits form
- ✅ Confirmation email sent to customer (via Mailpit)
- ✅ Appointment appears in database with tenant_id='acme-corp'
- ✅ Customer receives email with booking details

**Fixtures:** Pre-created tenant (acme-corp), services, providers; mailpit integration

### 5. brand_sweep.spec.ts — Brand Strings (Whitelabel Verification)

**Purpose:** Verify Easy!Appointments branding is removed (replaced with Polaris)

**Cases:**
- ✅ /book/{slug} page title does NOT contain "Easy!Appointments"
- ✅ /book/{slug} page content does NOT contain "Easy!Appointments"
- ✅ Email templates do NOT reference "Easy!Appointments"
- ✅ Form labels use polaris-specific text
- ✅ Error messages are generic (not EA-specific)
- ✅ Legal links point to Polaris (not easyappointments.org)

**Fixtures:** None (checks page content only)

## Fixtures Directory (`tests/e2e/fixtures/`)

### JWT Token Fixtures

**Purpose:** Pre-signed valid tokens for testing

```typescript
// fixtures/jwt.ts
export const TOKENS = {
  tenant1: 'eyJhbGc...', // Claims: iss='polaris', aud='ea-fork', tenant_id='tenant-1'
  tenant2: 'eyJhbGc...', // Claims: iss='polaris', aud='ea-fork', tenant_id='tenant-2'
  expired: 'eyJhbGc...', // exp claim in past
  noTenant: 'eyJhbGc...', // Missing tenant_id claim
};
```

**Generated by:** `npm run generate:fixtures` or checked in to repo

### Database Fixtures

**Purpose:** Pre-seed test data (tenants, services, providers, appointments)

```typescript
// fixtures/db.ts
export async function seedTestData(page: Page) {
  // Setup via API calls (Bearer token)
  await page.request.post('/api/v1/services', {
    headers: { Authorization: `Bearer ${TOKENS.tenant1}` },
    data: { name: 'Haircut', duration: 30 },
  });
}
```

### Mailpit Fixtures

**Purpose:** Capture emails sent during tests

```typescript
// fixtures/mailpit.ts
export async function getLastEmail(recipient: string) {
  const response = await fetch('http://mailpit:8025/api/v1/messages');
  const messages = await response.json();
  return messages.find(m => m.to.includes(recipient));
}
```

## Page Object Models (`tests/e2e/pages/`)

### BookingPage

Encapsulates interactions with /book/{slug} form.

```typescript
export class BookingPage {
  constructor(private page: Page, private slug: string) {}

  async goto() {
    await this.page.goto(`/book/${this.slug}`);
  }

  async selectService(name: string) {
    await this.page.select('select[name="id_services"]', name);
  }

  async selectProvider(name: string) {
    await this.page.select('select[name="id_users_provider"]', name);
  }

  async selectDateTime(datetime: string) {
    await this.page.fill('input[name="start_datetime"]', datetime);
  }

  async fillCustomerInfo(email: string, phone: string, name: string) {
    await this.page.fill('input[name="email"]', email);
    await this.page.fill('input[name="phone_number"]', phone);
    await this.page.fill('input[name="first_name"]', name);
  }

  async checkPDPA() {
    await this.page.check('input[name="pdpa_consent"]');
  }

  async completeTurnstile() {
    // Mock Turnstile for local testing
    await this.page.evaluate(() => {
      window.turnstileCallback?.({
        token: 'mock-token-success',
      });
    });
  }

  async submit() {
    await this.page.click('button[type="submit"]');
    await this.page.waitForNavigation({ waitUntil: 'networkidle' });
  }

  async expectError(message: string) {
    await expect(this.page.locator('.error')).toContainText(message);
  }
}
```

## CI Integration

### GitHub Actions Workflow (example)

```yaml
name: E2E Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_ROOT_PASSWORD: root
          MYSQL_DATABASE: easyappointments
      mailpit:
        image: axllent/mailpit:latest

    steps:
      - uses: actions/checkout@v3
      - uses: actions/setup-node@v3
        with:
          node-version: 18

      - name: Install dependencies
        run: npm ci

      - name: Start service
        run: docker compose up -d

      - name: Run E2E tests
        run: |
          BASE_URL=http://localhost:8080 \
          npm run e2e
        env:
          POLARIS_EA_JWT_SECRET: test-secret
          POLARIS_TURNSTILE_SITEKEY: 1x00000000000000000000AA
          POLARIS_TURNSTILE_SECRET: 2x0000000000000000000000000000000AA

      - name: Upload report
        if: always()
        uses: actions/upload-artifact@v3
        with:
          name: playwright-report
          path: playwright-report/
```

## Turnstile Mocking

**For local testing**, mock Cloudflare Turnstile to avoid real bot checks:

```typescript
// tests/e2e/fixtures/turnstile.ts
export async function mockTurnstile(page: Page) {
  // Override fetch for Turnstile verify endpoint
  await page.route('https://challenges.cloudflare.com/turnstile/v0/api/verify', route => {
    route.abort(); // Prevent real Turnstile calls
  });

  // Inject mock success response
  await page.addInitScript(() => {
    window.turnstileCallback = (response) => {
      // Auto-succeed Turnstile
      document.getElementById('cf-turnstile-response').value = 'mock-success';
    };
  });
}
```

## Test Debugging

### View Test Report

```bash
npm run e2e:report

# Opens HTML report in default browser
# Shows screenshots, videos, traces for failures
```

### Run Single Test

```bash
npx playwright test -g "should load booking form" --headed

# --headed: browser stays open (not headless)
# -g: run tests matching pattern
```

### Debug Mode

```bash
npx playwright test --debug

# Opens Playwright Inspector
# Step through test code, inspect elements, check state
```

### Trace Viewer

```bash
npx playwright show-trace trace.zip

# Opens Playwright Inspector
# Replay test action by action with network/DOM snapshots
```

## Performance Baselines

- **Page load time:** < 2s (including Turnstile frame)
- **Form submission:** < 3s (includes DB write + email queue)
- **API response:** < 500ms (appointments, services queries)

## Related Codemaps

- **public_booking.md** — Booking form structure + validation
- **authentication.md** — Bearer token validation
- **docker_build.md** — Building + running the test image
