/**
 * §6 E2E Acceptance Sweep — high-level scenarios (S3-9).
 *
 * One test per §6.x checklist item.  Detailed sub-cases live in:
 *   auth_bearer.spec.ts        — every JWT failure mode
 *   api_isolation.spec.ts      — all four endpoints, row-level isolation
 *   booking_hardening.spec.ts  — PDPA enforcement, Turnstile, rate limit
 *   brand_sweep.spec.ts        — admin pages + email branding
 *
 * Run against a booted image:
 *   BASE_URL=http://localhost:8080 \
 *   E2E_JWT_SECRET=<POLARIS_EA_JWT_SECRET> \
 *   E2E_TENANT_A_ID=<uuid> E2E_TENANT_A_SLUG=acme \
 *   E2E_TENANT_B_ID=<uuid> \
 *   npx playwright test
 */

import { test, expect, request as pwRequest } from '@playwright/test';
import {
    makeJwt,
    TENANT_A_ID,
    TENANT_A_SLUG,
    TENANT_B_ID,
    HAS_REAL_TENANTS,
} from './fixtures/index';
import { BookingPage } from './pages/BookingPage';

test.describe('§6 E2E Acceptance Sweep', () => {

    // §6.1 — Admin boot: Polaris chrome, no upstream brand in page title
    test('§6.1 admin backend loads with Polaris chrome', async ({ page }) => {
        await page.goto('/index.php/backend');
        await page.waitForLoadState('networkidle');

        await expect(page).toHaveURL(/backend|login/i);
        const title = await page.title();
        expect(title).not.toMatch(/Easy!Appointments/i);
    });

    // §6.2 — Public booking via slug: wizard renders + PDPA checkbox present
    test('§6.2 GET /book/{slug} renders booking wizard with PDPA checkbox', async ({ page }) => {
        const booking = new BookingPage(page);
        await booking.goto(TENANT_A_SLUG);

        await expect(booking.frame1).toBeAttached();
        expect(await booking.hasPdpaCheckbox()).toBe(true);
    });

    // §6.3 — Invalid slug → 404
    test('§6.3 GET /book/nonexistent-slug returns 404', async ({ page }) => {
        const res = await page.goto('/book/zzz-no-such-tenant-zzz');
        expect(res?.status()).toBe(404);
    });

    // §6.4 — Root URL redirect (checked at HTTP level, no browser follow)
    test('§6.4 GET / returns 302 redirect to getpolaris.my', async () => {
        const ctx = await pwRequest.newContext();
        const res = await ctx.get('/', { maxRedirects: 0 });
        expect(res.status()).toBe(302);
        expect(res.headers()['location'] ?? '').toContain('getpolaris.my');
        await ctx.dispose();
    });

    // §6.5 — Cross-tenant API isolation (summary; full coverage in api_isolation.spec.ts)
    test('§6.5 tenant-A Bearer cannot read tenant-B service rows', async () => {
        test.skip(!HAS_REAL_TENANTS, 'Set E2E_TENANT_A_ID / E2E_TENANT_B_ID to real UUIDs');

        const ctx = await pwRequest.newContext();
        const [resA, resB] = await Promise.all([
            ctx.get('/api/v1/services', { headers: { Authorization: `Bearer ${makeJwt(TENANT_A_ID)}` } }),
            ctx.get('/api/v1/services', { headers: { Authorization: `Bearer ${makeJwt(TENANT_B_ID)}` } }),
        ]);

        expect(resA.status()).toBe(200);
        expect(resB.status()).toBe(200);

        const rowsA = await resA.json() as Array<{ id: number }>;
        const rowsB = await resB.json() as Array<{ id: number }>;

        const idsA = new Set(rowsA.map((r) => r.id));
        for (const row of rowsB) {
            expect(idsA.has(row.id)).toBe(false);
        }

        await ctx.dispose();
    });

    // §6.6 — Cross-tenant public isolation: page HTML has no tenant-B UUID
    test('§6.6 /book/{slug} page contains no tenant-B UUID', async ({ page }) => {
        test.skip(!HAS_REAL_TENANTS, 'Set E2E_TENANT_B_ID to a real UUID to enable');

        const booking = new BookingPage(page);
        await booking.goto(TENANT_A_SLUG);
        await page.waitForLoadState('networkidle');

        expect(await page.content()).not.toContain(TENANT_B_ID);
    });

    // §6.7 — Booking page uses Polaris brand (detailed email checks in brand_sweep.spec.ts)
    test('§6.7 booking page carries Polaris brand string', async ({ page }) => {
        const booking = new BookingPage(page);
        await booking.goto(TENANT_A_SLUG);
        await page.waitForLoadState('networkidle');

        expect(await page.content()).toMatch(/polaris/i);
    });

    // §6.8 — Bearer JWT API: availabilities responds (not 401/403)
    test('§6.8 Bearer JWT: /api/v1/availabilities responds to valid token', async () => {
        const ctx   = await pwRequest.newContext();
        const today = new Date().toISOString().slice(0, 10);
        const res   = await ctx.get(
            `/api/v1/availabilities?serviceId=1&providerId=1&date=${today}`,
            { headers: { Authorization: `Bearer ${makeJwt(TENANT_A_ID)}` } },
        );
        expect([200, 404]).toContain(res.status());
        await ctx.dispose();
    });

    // §6.9 — No unstripped Easy!Appointments brand in booking page HTML
    test('§6.9 no unstripped Easy!Appointments string in booking page HTML', async ({ page }) => {
        const booking = new BookingPage(page);
        await booking.goto(TENANT_A_SLUG);
        await page.waitForLoadState('networkidle');

        const stripped = (await page.content())
            .replace(/Based on Easy!Appointments[^<]*/gi, '')
            .replace(/Easy!Appointments[^<]*GPL/gi, '')
            .replace(/Easy!Appointments - Online Appointment Scheduler/gi, '');

        expect(stripped).not.toMatch(/Easy!Appointments/i);
    });

});
