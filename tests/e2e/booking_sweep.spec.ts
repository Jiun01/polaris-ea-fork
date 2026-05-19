/**
 * §6 E2E sweep — Polaris EA fork acceptance tests (S3-9).
 *
 * Run against a booted local image:
 *   BASE_URL=http://localhost:8080 \
 *   E2E_JWT_SECRET=<same as POLARIS_EA_JWT_SECRET> \
 *   E2E_TENANT_A_ID=<uuid> \
 *   E2E_TENANT_A_SLUG=acme \
 *   E2E_TENANT_B_ID=<uuid> \
 *   npx playwright test
 *
 * Install Playwright browsers once: npx playwright install chromium
 */

import { test, expect, request as pwRequest } from '@playwright/test';
import * as crypto from 'crypto';
import { BookingPage } from './pages/BookingPage';

// ── JWT helper (HS256, no external dep) ──────────────────────────────────────

function b64url(buf: Buffer | string): string {
    return Buffer.from(buf).toString('base64url');
}

function makeJwt(secret: string, tenantId: string, overrides: Record<string, unknown> = {}): string {
    const now     = Math.floor(Date.now() / 1000);
    const header  = b64url(JSON.stringify({ alg: 'HS256', typ: 'JWT' }));
    const payload = b64url(JSON.stringify({
        iss: 'polaris',
        aud: 'ea-fork',
        sub: `tenant:${tenantId}`,
        tenant_id: tenantId,
        iat: now,
        exp: now + 3600,
        ...overrides,
    }));
    const sig = b64url(
        crypto.createHmac('sha256', secret).update(`${header}.${payload}`).digest(),
    );
    return `${header}.${payload}.${sig}`;
}

// ── Config from env ───────────────────────────────────────────────────────────

const JWT_SECRET    = process.env['E2E_JWT_SECRET']    ?? 'test-secret-min-32-chars-xxxxxxxxx';
const TENANT_A_ID   = process.env['E2E_TENANT_A_ID']   ?? '00000000-0000-0000-0000-000000000001';
const TENANT_A_SLUG = process.env['E2E_TENANT_A_SLUG'] ?? 'acme';
const TENANT_B_ID   = process.env['E2E_TENANT_B_ID']   ?? '00000000-0000-0000-0000-000000000002';

// ─────────────────────────────────────────────────────────────────────────────

test.describe('§6 E2E Acceptance Sweep', () => {

    // §6.1 — Admin boot: Polaris chrome, no upstream brand in page title/header
    test('§6.1 admin backend loads with Polaris chrome', async ({ page }) => {
        await page.goto('/index.php/backend');

        // Should reach login or dashboard — not a raw 500 / blank screen.
        await expect(page).toHaveURL(/backend|login/i);
        await page.waitForLoadState('networkidle');

        const title = await page.title();
        expect(title).not.toMatch(/Easy!Appointments/i);
    });

    // §6.2 — Public booking via slug: renders wizard + PDPA checkbox
    test('§6.2 GET /book/{slug} renders booking page with PDPA checkbox', async ({ page }) => {
        const booking = new BookingPage(page);
        await booking.goto(TENANT_A_SLUG);

        // Wizard step 1 must be present (service picker).
        await expect(booking.frame1).toBeAttached();

        // PDPA checkbox must exist in the DOM (may be in step 4, not yet visible).
        expect(await booking.hasPdpaCheckbox()).toBe(true);
    });

    // §6.3 — Invalid slug returns 404
    test('§6.3 GET /book/nonexistent-slug returns 404', async ({ page }) => {
        const response = await page.goto('/book/zzz-no-such-tenant-zzz');
        expect(response?.status()).toBe(404);
    });

    // §6.4 — Root URL redirect to marketing site (checked at the HTTP level,
    // without following redirects, so the browser does not leave localhost).
    test('§6.4 GET / returns 302 to getpolaris.my', async ({ page }) => {
        const ctx = await pwRequest.newContext({ baseURL: page.url().replace(/\/.*/, '') || 'http://localhost:8080' });
        const res  = await ctx.get('/', { maxRedirects: 0 });
        expect(res.status()).toBe(302);
        expect(res.headers()['location'] ?? '').toContain('getpolaris.my');
        await ctx.dispose();
    });

    // §6.5 — Cross-tenant API isolation: tenant-A JWT cannot see tenant-B rows.
    test('§6.5 tenant-A Bearer cannot read tenant-B rows from /api/v1/services', async () => {
        const ctx = await pwRequest.newContext();

        // Fetch services for each tenant.
        const [resA, resB] = await Promise.all([
            ctx.get('/api/v1/services', { headers: { Authorization: `Bearer ${makeJwt(JWT_SECRET, TENANT_A_ID)}` } }),
            ctx.get('/api/v1/services', { headers: { Authorization: `Bearer ${makeJwt(JWT_SECRET, TENANT_B_ID)}` } }),
        ]);

        expect(resA.status()).toBe(200);
        expect(resB.status()).toBe(200);

        const rowsA = await resA.json() as Array<{ id: number; tenant_id?: string }>;
        const rowsB = await resB.json() as Array<{ id: number; tenant_id?: string }>;

        // Every row returned to A must belong to A.
        for (const row of rowsA) {
            if (row.tenant_id !== undefined) expect(row.tenant_id).toBe(TENANT_A_ID);
        }

        // No ID overlap between tenants.
        const idsA = new Set(rowsA.map((r) => r.id));
        for (const row of rowsB) {
            expect(idsA.has(row.id)).toBe(false);
        }

        await ctx.dispose();
    });

    // §6.6 — Cross-tenant public isolation: /book/acme page has zero tenant-B UUIDs.
    test('§6.6 /book/{slug} HTML contains no tenant-B UUID', async ({ page }) => {
        // Skip if running with default placeholder UUIDs — no real data to compare.
        test.skip(
            TENANT_B_ID === '00000000-0000-0000-0000-000000000002',
            'Set E2E_TENANT_B_ID to a real UUID to enable this test',
        );

        const booking = new BookingPage(page);
        await booking.goto(TENANT_A_SLUG);
        await page.waitForLoadState('networkidle');

        const html = await page.content();
        expect(html).not.toContain(TENANT_B_ID);
    });

    // §6.7 — Polaris brand appears on the booking page; no EA chrome outside license area.
    test('§6.7 booking page uses Polaris brand, not Easy!Appointments chrome', async ({ page }) => {
        const booking = new BookingPage(page);
        await booking.goto(TENANT_A_SLUG);
        await page.waitForLoadState('networkidle');

        const html = await page.content();
        expect(html.toLowerCase()).toMatch(/polaris/i);

        // Strip the acknowledged GPL license comment before checking for brand leaks.
        const stripped = html
            .replace(/Based on Easy!Appointments[^<]*/gi, '')
            .replace(/Easy!Appointments[^<]*GPL/gi, '');
        expect(stripped).not.toMatch(/Easy!Appointments/i);
    });

    // §6.8 — Bearer JWT API: availabilities endpoint responds (not 401/403).
    test('§6.8 Bearer JWT: GET /api/v1/availabilities responds to tenant-A token', async () => {
        const ctx   = await pwRequest.newContext();
        const today = new Date().toISOString().slice(0, 10);

        const res = await ctx.get(
            `/api/v1/availabilities?service_id=1&provider_id=any&date=${today}`,
            { headers: { Authorization: `Bearer ${makeJwt(JWT_SECRET, TENANT_A_ID)}` } },
        );

        // 200 (has slots) or 404 (no slots yet) are both valid auth successes.
        // 401 / 403 would mean the JWT was rejected.
        expect([200, 404]).toContain(res.status());

        await ctx.dispose();
    });

    // §6.9 — No raw "Easy!Appointments" strings in the public booking page chrome.
    test('§6.9 no unstripped Easy!Appointments brand in booking page HTML', async ({ page }) => {
        const booking = new BookingPage(page);
        await booking.goto(TENANT_A_SLUG);
        await page.waitForLoadState('networkidle');

        const html = await page.content();

        // Strip known-acceptable attribution lines (GPL license, About page footer).
        const stripped = html
            .replace(/Based on Easy!Appointments[^<]*/gi, '')
            .replace(/Easy!Appointments[^<]*GPL/gi, '');

        expect(stripped).not.toMatch(/Easy!Appointments/i);
    });

});
