// UX.18: leaving a page while a Livewire call is still in flight is not an error. It used to raise an uncaught
// "[object Object]" in Firefox and WebKit (Livewire rejects an aborted call with a plain object): the notifications
// lazy load was in flight on every page load. Livewire requests are slowed here so the window is always open.
import { test, expect } from '@playwright/test';
import { authFile } from '../visual/personas.mjs';

test.describe('manager', () => {
    test.use({ storageState: authFile('manager') });

    test('leaving during the page load raises no page error', async ({ page }) => {
        const errors = [];
        page.on('pageerror', (e) => errors.push(String(e?.message ?? e)));
        await page.route('**/livewire*/update', async (route) => { await new Promise((r) => setTimeout(r, 1500)); await route.continue().catch(() => {}); });
        for (let i = 0; i < 4; i++) {
            await page.goto('/admin', { waitUntil: 'domcontentloaded' });
            await page.waitForTimeout(300);
            await page.goto('/admin/notifications', { waitUntil: 'domcontentloaded' });
            await page.waitForTimeout(300);
        }
        expect(errors).toEqual([]);
    });

    test('leaving while the command center is answering raises no page error', async ({ page }) => {
        const errors = [];
        await page.goto('/admin', { waitUntil: 'networkidle' });
        page.on('pageerror', (e) => errors.push(String(e?.message ?? e)));
        await page.route('**/livewire*/update', async (route) => { await new Promise((r) => setTimeout(r, 1500)); await route.continue().catch(() => {}); });
        await page.locator('.pos-command-trigger').first().click();
        await page.waitForTimeout(300);
        await page.goto('/admin/my-work', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(800);
        expect(errors).toEqual([]);
    });
});
