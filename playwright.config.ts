import { defineConfig, devices } from '@playwright/test';

const BASE_URL = process.env['BASE_URL'] ?? 'http://localhost:8080';

export default defineConfig({
    testDir: './tests/e2e',

    // Run tests in parallel — each file in its own worker.
    fullyParallel: true,

    // Fail CI run immediately if a test has `test.only`.
    forbidOnly: !!process.env['CI'],

    // Retry once on CI; no retries locally (faster feedback).
    retries: process.env['CI'] ? 2 : 0,

    // Single worker on CI to avoid port/session conflicts on the shared DB.
    workers: process.env['CI'] ? 1 : undefined,

    reporter: [
        ['list'],
        ['html', { open: 'never', outputFolder: 'playwright-report' }],
        ['json', { outputFile: 'playwright-results.json' }],
    ],

    use: {
        baseURL: BASE_URL,
        ignoreHTTPSErrors: true,

        // Per-action timeout (click, fill, etc.).
        actionTimeout: 10_000,

        // Navigation timeout (goto, waitForURL, etc.).
        navigationTimeout: 30_000,

        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
        trace: 'on-first-retry',
    },

    projects: [
        { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    ],
});
