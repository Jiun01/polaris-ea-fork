/**
 * EA tenant provisioning — internal HMAC API end-to-end.
 *
 * Regression guard for the 2026-06-23 incident: the password-reset email built
 * on the internal provisioning path used the REQUEST host, so the link came out
 * as http://ea-nginx/recovery/reset?... (unreachable) instead of the configured
 * public host. The discriminating condition below is that the test drives EA
 * over the request host (playwright BASE_URL, e.g. localhost:8090) while the EA
 * app is configured with a DIFFERENT public host (EA_BASE_URL → PUBLIC_BASE_HOST,
 * default calendar.test). A correct build emits the public host; the buggy build
 * emits the request host — so this test fails red on the regression.
 *
 * Requires the dev stack up (php-fpm + nginx + mysql + mailpit), the EA app
 * configured with EA_BASE_URL=https://calendar.test and EA_SMTP_* → mailpit, and
 * a seeded TENANT_A (docker/seed_e2e.sql). Run:
 *   BASE_URL=http://localhost:8090 npm run e2e -- provisioning.spec.ts
 */

import { test, expect } from '@playwright/test';
import {
    TENANT_A_ID,
    TENANT_A_SLUG,
    PUBLIC_BASE_HOST,
    signPolaris,
    waitForEmail,
    fetchMailBody,
    clearMailpit,
    extractUrl,
} from './fixtures/index';

// Serial: these tests all provision the SAME seeded tenant and the derived
// admin username / provider email are globally unique, so concurrent identical
// inserts collide (duplicate-key → 500). CI already pins workers=1 for the
// shared DB; this keeps the file correct under the default parallel runner too.
test.describe.configure({ mode: 'serial' });

const PROVISION_PATH = '/index.php/api/v1/internal/tenants/provision';
const ADMIN_EMAIL = 'owner.acme@example.test';

function provisionPayload(): Record<string, string> {
    return {
        tenant_id: TENANT_A_ID,
        slug: TENANT_A_SLUG,
        email: ADMIN_EMAIL,
        first_name: 'Acme',
        last_name: 'Owner',
    };
}

/** POST a (optionally tampered) signed provision request. */
async function provision(
    request: import('@playwright/test').APIRequestContext,
    opts: { raw?: string; sig?: string } = {},
) {
    const raw = opts.raw ?? JSON.stringify(provisionPayload());
    const sig = opts.sig ?? signPolaris(raw);
    return request.post(PROVISION_PATH, {
        headers: { 'Content-Type': 'application/json', 'X-Polaris-Signature': sig },
        data: raw, // raw string — NOT an object; the HMAC covers these exact bytes
    });
}

test.describe('EA tenant provisioning (internal API)', () => {
    test.beforeEach(async () => {
        await clearMailpit();
    });

    test('provisions a tenant and emails a reset link on the PUBLIC host', async ({ request }) => {
        const res = await provision(request);
        expect(res.status()).toBe(201);

        const body = (await res.json()) as Record<string, unknown>;
        expect(typeof body['admin_user_id']).toBe('number');
        expect(typeof body['provider_id']).toBe('number');
        expect(typeof body['service_id']).toBe('number');

        // (b) the reset email is actually delivered — proves the SMTP path, not PHP mail().
        const msg = await waitForEmail(ADMIN_EMAIL);
        expect(msg, 'reset email delivered via SMTP').not.toBeNull();

        // (c) THE REGRESSION GUARD: the reset link must use the configured public
        // host, never the internal request host the provisioning call arrived on.
        const mailBody = await fetchMailBody(msg!.ID);
        const resetUrl = extractUrl(mailBody, 'recovery/reset');
        expect(new URL(resetUrl).host).toBe(PUBLIC_BASE_HOST);
        expect(resetUrl).not.toContain('ea-nginx');
        expect(resetUrl).not.toContain('localhost');
    });

    test('is idempotent — re-provisioning reuses the same entities', async ({ request }) => {
        const first = await provision(request);
        expect(first.status()).toBe(201);
        const a = (await first.json()) as Record<string, number>;

        const second = await provision(request);
        expect(second.status()).toBe(201);
        const b = (await second.json()) as Record<string, number>;

        expect(b['admin_user_id']).toBe(a['admin_user_id']);
        expect(b['provider_id']).toBe(a['provider_id']);
        expect(b['service_id']).toBe(a['service_id']);
    });

    test('rejects an invalid HMAC signature with 401', async ({ request }) => {
        const res = await provision(request, { sig: 'sha256=bad' });
        expect(res.status()).toBe(401);
    });
});
