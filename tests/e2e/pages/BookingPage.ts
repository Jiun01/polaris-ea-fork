import { type Page, type Locator } from '@playwright/test';

/**
 * Page Object Model for the Polaris public booking page (/book/{slug}).
 *
 * Wraps locators and common interactions so individual specs stay readable.
 */
export class BookingPage {
    readonly page: Page;

    // ── Wizard frames ──────────────────────────────────────────────────────────
    readonly frame1: Locator;   // Select service & provider
    readonly frame4: Locator;   // Confirmation + consent

    // ── Consent / compliance ───────────────────────────────────────────────────
    readonly pdpaCheckbox: Locator;
    readonly termsCheckbox: Locator;
    readonly privacyCheckbox: Locator;

    // ── Turnstile ──────────────────────────────────────────────────────────────
    readonly turnstileWidget: Locator;

    // ── Navigation ─────────────────────────────────────────────────────────────
    readonly bookButton: Locator;

    constructor(page: Page) {
        this.page = page;

        this.frame1          = page.locator('#wizard-frame-1');
        this.frame4          = page.locator('#wizard-frame-4');

        this.pdpaCheckbox    = page.locator('#accept-to-pdpa-polaris');
        this.termsCheckbox   = page.locator('#accept-to-terms-and-conditions');
        this.privacyCheckbox = page.locator('#accept-to-privacy-policy');
        this.turnstileWidget = page.locator('.cf-turnstile');
        this.bookButton      = page.locator('#book-appointment-submit');
    }

    async goto(slug: string): Promise<void> {
        await this.page.goto(`/book/${slug}`);
        // Wait until the booking wizard is rendered (JS loaded + services fetched).
        await this.frame1.waitFor({ state: 'attached', timeout: 15_000 });
    }

    /** Returns true if the PDPA checkbox is present in the DOM. */
    async hasPdpaCheckbox(): Promise<boolean> {
        return await this.pdpaCheckbox.count() > 0;
    }

    /** Returns true if the Turnstile widget div is present. */
    async hasTurnstileWidget(): Promise<boolean> {
        return await this.turnstileWidget.count() > 0;
    }
}
