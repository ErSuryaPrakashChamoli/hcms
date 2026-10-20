// UX.19: the final decisions, checked in a real browser (Chromium phone and desktop, Firefox, the WebKit engine).
// - Tablists move between their tabs with the arrow keys, Home and End (manual activation: moving does not select).
// - An employee reaches their own Employee 360 from My HR; it is read-only (no action row).
// - "View as" is a group of toggle buttons: Enter on a chip switches the view and moves aria-pressed.
// - The command palette shows focus on its input bar, not only as a caret.
// - On a phone the Employee 360 header keeps its actions in two rows and "Reports to" next to the person.
import { test, expect } from '@playwright/test';
import { authFile } from '../visual/personas.mjs';

const ready = async (page) => {
    // The WebKit engine can reach network idle before Livewire has started.
    await page.waitForFunction(() => !!window.Livewire);
    await page.evaluate(() => document.fonts.ready);
};

test.describe('employee', () => {
    test.use({ storageState: authFile('employee') });

    test('My HR sections move with the arrow keys, Home and End, without selecting', async ({ page }) => {
        await page.goto('/admin/my-hr', { waitUntil: 'networkidle' });
        await ready(page);
        const tabs = page.locator('nav[aria-label="Everything in My HR"] [role=tab]');
        await tabs.first().focus();
        await page.keyboard.press('ArrowRight');
        await expect(tabs.nth(1)).toBeFocused();
        await page.keyboard.press('End');
        await expect(tabs.last()).toBeFocused();
        await page.keyboard.press('ArrowRight');
        await expect(tabs.first()).toBeFocused();
        await page.keyboard.press('ArrowLeft');
        await expect(tabs.last()).toBeFocused();
        await page.keyboard.press('Home');
        await expect(tabs.first()).toBeFocused();
        await expect(tabs.first()).toHaveAttribute('aria-selected', 'true');
    });

    test('opens their own Employee 360 from My HR, read-only', async ({ page }) => {
        await page.goto('/admin/my-hr', { waitUntil: 'networkidle' });
        await ready(page);
        await page.getByRole('link', { name: 'Your record' }).click();
        await expect(page).toHaveURL(/\/admin\/employees\/\d+$/);
        await expect(page.locator('.pos-360-viewer[data-viewer="self"]')).toHaveCount(1);
        await expect(page.locator('.pos-360-actions')).toHaveCount(0);
    });
});

test.describe('manager', () => {
    test.use({ storageState: authFile('manager') });

    test('"View as" is a group of toggle buttons that Enter switches', async ({ page }) => {
        await page.goto('/admin', { waitUntil: 'networkidle' });
        await ready(page);
        const group = page.getByRole('group', { name: 'View Home as' });
        await expect(group).toHaveCount(1);
        await expect(page.getByRole('tablist', { name: 'View Home as' })).toHaveCount(0);
        await expect(group.locator('[aria-pressed=true]')).toHaveCount(1);

        const choose = async (name) => {
            const chip = page.getByRole('group', { name: 'View Home as' }).getByRole('button', { name });
            await chip.focus();
            await page.keyboard.press('Enter');
            await expect(page.getByRole('group', { name: 'View Home as' }).getByRole('button', { name })).toHaveAttribute('aria-pressed', 'true');
        };
        await choose('For you');
        // Back to the manager's own view (the choice is remembered, and other tests start from it).
        await choose('Your team');
    });

    test('the command palette shows focus on its input bar', async ({ page }) => {
        await page.goto('/admin', { waitUntil: 'networkidle' });
        await ready(page);
        await page.locator('.pos-command-trigger').first().click();
        await expect(page.locator('.pos-command-input')).toBeFocused();
        expect(await page.locator('.pos-command-head').evaluate((el) => getComputedStyle(el).boxShadow)).not.toBe('none');
        await page.keyboard.press('Escape');
    });
});

test.describe('hr', () => {
    test.use({ storageState: authFile('hr') });

    test('on a phone the Employee 360 header keeps its actions in two rows and "Reports to" with the person', async ({ page }, info) => {
        test.skip(!info.project.name.includes('phone'), 'the compact header is the phone layout');
        await page.goto('/admin/employees/3', { waitUntil: 'networkidle' });
        await ready(page);
        const centres = await page.locator('.pos-360-actions').evaluate((el) => [...el.querySelectorAll('button, a')]
            .filter((b) => b.getClientRects().length > 0).map((b) => { const r = b.getBoundingClientRect(); return r.top + r.height / 2; }).sort((a, b) => a - b));
        const rows = centres.reduce((n, c, i) => (i === 0 || c - centres[i - 1] > 12 ? n + 1 : n), 0);
        expect(centres.length).toBeGreaterThan(2);
        expect(rows).toBeLessThanOrEqual(2);
        const [label, person] = await page.locator('.pos-360-reports').evaluate((el) => [el.firstElementChild, el.lastElementChild]
            .map((n) => { const r = n.getBoundingClientRect(); return r.top + r.height / 2; }));
        expect(Math.abs(label - person)).toBeLessThan(8);
    });
});
