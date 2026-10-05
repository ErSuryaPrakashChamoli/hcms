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
            await page.keyboard.press('Tab');
            await expect(page.locator(':focus')).toHaveText(/Skip to content/);
            await page.keyboard.press('Enter');
            expect(await page.evaluate(() => location.hash === '#pos-main' || !!document.activeElement?.closest('#pos-main'))).toBe(true);
        });

        test('the chosen view chip is in sight on a phone', async ({ page }, info) => {
            test.skip(!info.project.name.includes('phone'), 'the chip row only scrolls on phones');
            await page.goto('/admin', { waitUntil: 'networkidle' });
            const inSight = await page.evaluate(() => {
                const row = document.querySelector('.pos-lens-scroll');
                const chip = row?.querySelector('[aria-selected=true]');
                if (!row || !chip) return null;
                const a = row.getBoundingClientRect(), c = chip.getBoundingClientRect();
                return c.left >= a.left - 1 && c.right <= a.right + 1;
            });
            expect(inSight).toBe(true);
        });
    });
}
