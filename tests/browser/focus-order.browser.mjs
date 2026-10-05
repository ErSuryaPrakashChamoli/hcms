// UX.18 (WCAG 2.4.3): for a person with several views, the first Tab on Home reaches the skip link, which lands in the
// main content. UX.17 scrolled the chosen "View as" chip with scrollIntoView(), which moved the browser's focus starting
// point to the chip, so Tab skipped the skip link, the header and the navigation. The chip must still be in sight.
import { test, expect } from '@playwright/test';
import { authFile } from '../visual/personas.mjs';

for (const persona of ['admin', 'manager']) {
    test.describe(persona, () => {
        test.use({ storageState: authFile(persona) });

        test('the first Tab on Home is the skip link, and it lands in main', async ({ page }) => {
            await page.goto('/admin', { waitUntil: 'networkidle' });
            // The window must have focus before a key reaches the page (headless Firefox sometimes does not yet).
            await page.bringToFront();
            await expect.poll(() => page.evaluate(() => document.hasFocus())).toBe(true);
            await page.keyboard.press('Tab');
            await expect(page.locator(':focus')).toHaveText(/Skip to content/);
            await page.keyboard.press('Enter');
            expect(await page.evaluate(() => location.hash === '#pos-main' || !!document.activeElement?.closest('#pos-main'))).toBe(true);
        });

        test('the chosen view chip is in sight on a phone', async ({ page }, info) => {
            test.skip(!info.project.name.includes('phone'), 'the chip row only scrolls on phones');
            await page.goto('/admin', { waitUntil: 'networkidle' });
            // The row can only overflow once web fonts have loaded (the WebKit engine reaches network idle first): wait
            // for the chip to settle in sight, as a person would see it.
            await expect.poll(() => page.evaluate(() => {
                const row = document.querySelector('.pos-lens-scroll');
                // UX.19: the chips are toggle buttons (aria-pressed); they were tabs (aria-selected) before.
                const chip = row?.querySelector('[aria-pressed=true]');
                if (!row || !chip) return null;
                const a = row.getBoundingClientRect(), c = chip.getBoundingClientRect();
                return c.left >= a.left - 1 && c.right <= a.right + 1;
            })).toBe(true);
        });
    });
}
