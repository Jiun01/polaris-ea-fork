/**
 * Cross-tenant API isolation — §6.5.
 *
 * Verifies that a Bearer JWT for tenant A never exposes tenant B rows on any
 * of the four data endpoints called out in the checklist:
 *   /api/v1/services  /api/v1/providers  /api/v1/customers  /api/v1/appointments
 *
 * Row-level isolation tests require real seeded data (set E2E_TENANT_A_ID and
 * E2E_TENANT_B_ID to actual UUIDs).  The auth-pass / auth-block sub-tests run
 * against any environment.
 */

import { test, expect, request as pwRequest } from '@playwright/test';
import {
    makeJwt,
    makeExpiredJwt,
    TENANT_A_ID,
    TENANT_B_ID,
    HAS_REAL_TENANTS,
} from './fixtures/index';

// Endpoints under test (§6.5 list).
const ENDPOINTS = [
    '/api/v1/services',
    '/api/v1/providers',
    '/api/v1/customers',
    '/api/v1/appointments',
] as const;

// ── Helpers ───────────────────────────────────────────────────────────────────

async function getRows(
    path: string,
    tenantId: string,
): Promise<{ status: number; rows: Array<{ id: number; tenant_id?: string }> }> {
    const ctx = await pwRequest.newContext();
    const res = await ctx.get(path, {
        headers: { Authorization: `Bearer ${makeJwt(tenantId)}` },
    });
    const status = res.status();
    let rows: Array<{ id: number; tenant_id?: string }> = [];
    try { rows = await res.json() as typeof rows; } catch { /* empty */ }
    await ctx.dispose();
    return { status, rows };
}

// ─────────────────────────────────────────────────────────────────────────────

test.describe('Cross-tenant API isolation (§6.5)', () => {

    // ── Auth enforcement on all four endpoints ────────────────────────────────

    for (const endpoint of ENDPOINTS) {
        test(`${endpoint} — no token → 401`, async () => {
            const ctx = await pwRequest.newContext();
            const res = await ctx.get(endpoint);
            expect(res.status()).toBe(401);
            await ctx.dispose();
        });

        test(`${endpoint} — expired token → 401 token_expired`, async () => {
            const ctx = await pwRequest.newContext();
            const res = await ctx.get(endpoint, {
                headers: { Authorization: `Bearer ${makeExpiredJwt(TENANT_A_ID)}` },
            });
            expect(res.status()).toBe(401);
            const body = await res.json() as { error?: string };
            expect(body.error).toBe('token_expired');
            await ctx.dispose();
        });

        test(`${endpoint} — valid token → not 401/403`, async () => {
            const { status } = await getRows(endpoint, TENANT_A_ID);
            expect(status).not.toBe(401);
            expect(status).not.toBe(403);
        });
    }

    // ── Row-level isolation (requires seeded tenants) ─────────────────────────

    for (const endpoint of ENDPOINTS) {
        test(`${endpoint} — tenant-A rows carry tenant_id = A`, async () => {
            test.skip(!HAS_REAL_TENANTS, 'Set E2E_TENANT_A_ID / E2E_TENANT_B_ID to real UUIDs');

            const { status, rows } = await getRows(endpoint, TENANT_A_ID);
            expect([200, 404]).toContain(status);

            for (const row of rows) {
                if (row.tenant_id !== undefined) {
                    expect(row.tenant_id).toBe(TENANT_A_ID);
                }
            }
        });

        test(`${endpoint} — tenant-A JWT returns zero tenant-B rows`, async () => {
            test.skip(!HAS_REAL_TENANTS, 'Set E2E_TENANT_A_ID / E2E_TENANT_B_ID to real UUIDs');

            const [{ rows: rowsA }, { rows: rowsB }] = await Promise.all([
                getRows(endpoint, TENANT_A_ID),
                getRows(endpoint, TENANT_B_ID),
            ]);

            const idsA = new Set(rowsA.map((r) => r.id));
            const idsB = new Set(rowsB.map((r) => r.id));

            // No ID should appear in both sets.
            for (const id of idsA) {
                expect(idsB.has(id)).toBe(false);
            }
        });
    }

    // ── Stale-context bleed check ─────────────────────────────────────────────
    // PHP-FPM reuses workers; BearerAuthHook must clear static context on every
    // request.  Simulate by sending A-token then B-token in sequence from the
    // same HTTP connection and verifying the response set changes.

    test('sequential A→B requests return independent row sets', async () => {
        test.skip(!HAS_REAL_TENANTS, 'Set E2E_TENANT_A_ID / E2E_TENANT_B_ID to real UUIDs');

        const ctx = await pwRequest.newContext();

        const resA = await ctx.get('/api/v1/services', {
            headers: { Authorization: `Bearer ${makeJwt(TENANT_A_ID)}` },
        });
        const resB = await ctx.get('/api/v1/services', {
            headers: { Authorization: `Bearer ${makeJwt(TENANT_B_ID)}` },
        });

        const rowsA = await resA.json() as Array<{ id: number }>;
        const rowsB = await resB.json() as Array<{ id: number }>;

        const idsA = new Set(rowsA.map((r) => r.id));
        for (const row of rowsB) {
            expect(idsA.has(row.id)).toBe(false);
        }

        await ctx.dispose();
    });

});
