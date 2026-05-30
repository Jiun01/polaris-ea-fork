/**
 * Bearer JWT authentication — exhaustive failure-mode tests.
 *
 * Every auth rejection path in BearerAuthHook + MY_Controller::from_bearer()
 * is covered here.  Tests use /api/v1/services as a stable probe endpoint
 * (any protected route would do).
 *
 * These tests do NOT require seeded database data; they only need the app
 * running and POLARIS_EA_JWT_SECRET set to E2E_JWT_SECRET.
 */

import { test, expect, request as pwRequest } from '@playwright/test';
import { makeJwt, makeExpiredJwt, JWT_SECRET, TENANT_A_ID } from './fixtures/index';

const PROBE = '/api/v1/services';

// Helper: make a request with a raw Authorization header value (or none).
async function probe(
    authHeader: string | null,
    path = PROBE,
): Promise<{ status: number; body: Record<string, unknown> }> {
    const ctx = await pwRequest.newContext();
    const headers: Record<string, string> = authHeader !== null
        ? { Authorization: authHeader }
        : {};
    const res  = await ctx.get(path, { headers });
    let body: Record<string, unknown> = {};
    try { body = await res.json() as Record<string, unknown>; } catch { /* non-JSON */ }
    await ctx.dispose();
    return { status: res.status(), body };
}

test.describe('Bearer JWT authentication', () => {

    // ── Missing / malformed header ────────────────────────────────────────────

    test('no Authorization header → 401 token_missing', async () => {
        const { status, body } = await probe(null);
        expect(status).toBe(401);
        expect(body['message']).toBe('token_missing');
    });

    test('Authorization: Basic (wrong scheme) → 401 token_missing', async () => {
        const { status, body } = await probe('Basic dXNlcjpwYXNz');
        expect(status).toBe(401);
        expect(body['message']).toBe('token_missing');
    });

    test('Authorization: Bearer (empty value) → 401 token_missing', async () => {
        const { status, body } = await probe('Bearer ');
        expect(status).toBe(401);
        expect(body['message']).toBe('token_missing');
    });

    test('Authorization: Bearer value > 8 KB → rejected (400 nginx / 401 hook)', async () => {
        // Defense in depth: nginx rejects oversized request headers with 400 before
        // PHP; if it ever reaches PHP, BearerAuthHook caps at 8192 bytes → 401.
        const oversized = 'Bearer ' + 'x'.repeat(8200);
        const { status, body } = await probe(oversized);
        expect([400, 401]).toContain(status);
        if (status === 401) {
            expect(body['message']).toBe('token_missing');
        }
    });

    // ── Signature / structure errors ─────────────────────────────────────────

    test('tampered signature → 401 token_invalid', async () => {
        const token = makeJwt(TENANT_A_ID) + 'XXXXXX';
        const { status, body } = await probe(`Bearer ${token}`);
        expect(status).toBe(401);
        expect(body['message']).toBe('token_invalid');
    });

    test('completely non-JWT string → 401 token_invalid', async () => {
        const { status, body } = await probe('Bearer notavalidjwtatall');
        expect(status).toBe(401);
        expect(body['message']).toBe('token_invalid');
    });

    // ── Claim validation errors ───────────────────────────────────────────────

    test('expired token → 401 token_expired', async () => {
        const token = makeExpiredJwt(TENANT_A_ID);
        const { status, body } = await probe(`Bearer ${token}`);
        expect(status).toBe(401);
        expect(body['message']).toBe('token_expired');
    });

    test('wrong iss ("notpolaris") → 401 token_invalid', async () => {
        const token = makeJwt(TENANT_A_ID, { iss: 'notpolaris' });
        const { status, body } = await probe(`Bearer ${token}`);
        expect(status).toBe(401);
        expect(body['message']).toBe('token_invalid');
    });

    test('wrong aud ("not-ea-fork") → 401 token_invalid', async () => {
        const token = makeJwt(TENANT_A_ID, { aud: 'not-ea-fork' });
        const { status, body } = await probe(`Bearer ${token}`);
        expect(status).toBe(401);
        expect(body['message']).toBe('token_invalid');
    });

    test('non-UUID tenant_id claim → 401 token_invalid', async () => {
        const token = makeJwt(TENANT_A_ID, { tenant_id: 'not-a-uuid' });
        const { status, body } = await probe(`Bearer ${token}`);
        expect(status).toBe(401);
        expect(body['message']).toBe('token_invalid');
    });

    test('non-UUID tenant_branch_id claim → 401 token_invalid', async () => {
        const token = makeJwt(TENANT_A_ID, { tenant_branch_id: 'bad-branch-id' });
        const { status, body } = await probe(`Bearer ${token}`);
        expect(status).toBe(401);
        expect(body['message']).toBe('token_invalid');
    });

    // ── Happy path ────────────────────────────────────────────────────────────

    test('valid token → 200 (not 401/403)', async () => {
        const token = makeJwt(TENANT_A_ID);
        const { status } = await probe(`Bearer ${token}`);
        // 200 (has rows) or 404 (tenant has no services yet) are both valid.
        // Anything in the 4xx range other than 401/403 means auth passed.
        expect(status).not.toBe(401);
        expect(status).not.toBe(403);
    });

    test('valid token accepted via lowercase authorization header', async () => {
        // BearerAuthHook uses case-insensitive apache_request_headers() lookup.
        const ctx   = await pwRequest.newContext();
        const token = makeJwt(TENANT_A_ID);
        // Playwright sends headers as-is; the server may normalise them.
        const res = await ctx.get(PROBE, { headers: { authorization: `Bearer ${token}` } });
        expect(res.status()).not.toBe(401);
        await ctx.dispose();
    });

    // ── Non-API paths must NOT require auth ───────────────────────────────────

    test('GET /book/{slug} without Bearer token → not 401', async () => {
        // Public booking path must be accessible without a JWT.
        const { status } = await probe(null, '/book/acme');
        // 200 (renders) or 404 (unknown slug) — never 401.
        expect(status).not.toBe(401);
    });

    test('GET / without Bearer token → not 401', async () => {
        const { status } = await probe(null, '/');
        expect([200, 301, 302, 307, 308]).toContain(status);
    });

});
