import { type Page, type Locator } from '@playwright/test';

/**
 * Page Object Model for the Polaris admin backend.
 *
 * Covers the login page + all major admin sub-pages that need brand-sweep checks.
 */
export class AdminPage {
    readonly page: Page;

    // ── Login page ─────────────────────────────────────────────────────────────
    readonly usernameInput: Locator;
    readonly passwordInput: Locator;
    readonly loginButton: Locator;

    // ── Backend nav ────────────────────────────────────────────────────────────
    readonly calendarLink: Locator;
    readonly servicesLink: Locator;
    readonly providersLink: Locator;
    readonly settingsLink: Locator;

    constructor(page: Page) {
        this.page = page;

        this.usernameInput = page.locator('#username, input[name="username"]');
        this.passwordInput = page.locator('#password, input[name="password"]');
        this.loginButton   = page.locator('button[type="submit"], #login-button');

        this.calendarLink  = page.locator('a[href*="calendar"], a[href*="backend"]').first();
        this.servicesLink  = page.locator('a[href*="services"]').first();
        this.providersLink = page.locator('a[href*="providers"]').first();
        this.settingsLink  = page.locator('a[href*="settings"]').first();
    }

    async gotoLogin(): Promise<void> {
        // EA 1.6.0 split login into its own /login page (was /backend in 1.5.2).
        await this.page.goto('/index.php/login');
        await this.page.waitForLoadState('networkidle');
    }

    async login(username: string, password: string): Promise<void> {
        await this.usernameInput.fill(username);
        await this.passwordInput.fill(password);
        await this.loginButton.click();
        await this.page.waitForLoadState('networkidle');
    }

    /**
     * Return the page HTML with acknowledged GPL license comments stripped,
     * so brand-sweep checks don't false-positive on attribution headers.
     */
    async strippedHtml(): Promise<string> {
        const html = await this.page.content();
        return html
            .replace(/Based on Easy!Appointments[^<]*/gi, '')
            .replace(/Easy!Appointments[^<]*GPL/gi, '')
            .replace(/Easy!Appointments - Online Appointment Scheduler/gi, '');
    }

    /** URLs of every major admin section to sweep for brand leaks. */
    static adminUrls(): string[] {
        return [
            '/index.php/backend',
            '/index.php/backend/services',
            '/index.php/backend/providers',
            '/index.php/backend/customers',
            '/index.php/backend/settings',
        ];
    }
}
