/**
 * Shared fixtures for the Polaris EA fork E2E suite.
 *
 * Import from here to keep env config and helpers in one place.
 */

import * as crypto from 'crypto';
import { type APIRequestContext } from '@playwright/test';

// ── Environment ───────────────────────────────────────────────────────────────

export const JWT_SECRET    = process.env['E2E_JWT_SECRET']    ?? 'test-secret-min-32-chars-xxxxxxxxx';
export const TENANT_A_ID   = process.env['E2E_TENANT_A_ID']   ?? '00000000-0000-0000-0000-000000000001';
export const TENANT_A_SLUG = process.env['E2E_TENANT_A_SLUG'] ?? 'acme';
export const TENANT_B_ID   = process.env['E2E_TENANT_B_ID']   ?? '00000000-0000-0000-0000-000000000002';
export const MAILPIT_URL   = process.env['E2E_MAILPIT_URL']   ?? 'http://localhost:8025';

/** True when running with real tenant UUIDs (not placeholders). */
export const HAS_REAL_TENANTS =
    TENANT_A_ID !== '00000000-0000-0000-0000-000000000001' &&
    TENANT_B_ID !== '00000000-0000-0000-0000-000000000002';

// ── JWT helper (HS256, no external dep) ──────────────────────────────────────

function b64url(buf: Buffer | string): string {
    return Buffer.from(buf).toString('base64url');
}

export interface JwtOptions {
    iss?: string;
    aud?: string | string[];
    tenant_id?: string;
    tenant_branch_id?: string;
    exp?: number;
    /** Extra claims merged into payload. */
    extra?: Record<string, unknown>;
}

/**
 * Create a signed HS256 JWT with the given options.
 * Defaults produce a valid Polaris EA token for the given tenantId.
 */
export function makeJwt(tenantId: string, opts: JwtOptions = {}): string {
    const now = Math.floor(Date.now() / 1000);
    const header = b64url(JSON.stringify({ alg: 'HS256', typ: 'JWT' }));
    const payload = b64url(JSON.stringify({
        iss: opts.iss ?? 'polaris',
        aud: opts.aud ?? 'ea-fork',
        sub: `tenant:${tenantId}`,
        tenant_id: opts.tenant_id ?? tenantId,
        iat: now,
        exp: opts.exp ?? now + 3600,
        ...(opts.tenant_branch_id ? { tenant_branch_id: opts.tenant_branch_id } : {}),
        ...(opts.extra ?? {}),
    }));
    const sig = b64url(
        crypto.createHmac('sha256', JWT_SECRET).update(`${header}.${payload}`).digest(),
    );
    return `${header}.${payload}.${sig}`;
}

/** Return an already-expired JWT. */
export function makeExpiredJwt(tenantId: string): string {
    const past = Math.floor(Date.now() / 1000) - 7200; // 2 hours ago
    return makeJwt(tenantId, { exp: past });
}

// ── API helpers ───────────────────────────────────────────────────────────────

/**
 * Make a GET request to a /api/v1/* endpoint with a Bearer token.
 * Returns { status, body }.
 */
export async function apiGet(
    ctx: APIRequestContext,
    path: string,
    tenantId: string,
    jwtOpts?: JwtOptions,
): Promise<{ status: number; body: unknown }> {
    const token = makeJwt(tenantId, jwtOpts ?? {});
    const res   = await ctx.get(path, { headers: { Authorization: `Bearer ${token}` } });
    let body: unknown = null;
    try { body = await res.json(); } catch { /* non-JSON body */ }
    return { status: res.status(), body };
}

// ── Mailpit helpers ───────────────────────────────────────────────────────────

export interface MailpitMessage {
    ID: string;
    Subject: string;
    To: Array<{ Address: string }>;
    HTML: string;
    Text: string;
}

/**
 * Poll mailpit until a message arrives addressed to `toAddress`, then return it.
 * Times out after `maxWaitMs` (default 10 s).
 */
export async function waitForEmail(
    toAddress: string,
    maxWaitMs = 10_000,
): Promise<MailpitMessage | null> {
    const deadline = Date.now() + maxWaitMs;

    while (Date.now() < deadline) {
        const res = await fetch(`${MAILPIT_URL}/api/v1/messages?limit=20`);
        if (!res.ok) break;

        const data = await res.json() as { messages: MailpitMessage[] };
        const match = data.messages?.find((m) =>
            m.To.some((t) => t.Address.toLowerCase() === toAddress.toLowerCase()),
        );
        if (match) return match;

        await new Promise((r) => setTimeout(r, 500));
    }
    return null;
}

/** Delete all messages in the mailpit inbox. */
export async function clearMailpit(): Promise<void> {
    await fetch(`${MAILPIT_URL}/api/v1/messages`, { method: 'DELETE' });
}
