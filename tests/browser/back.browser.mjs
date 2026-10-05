// UX.18: Back closes the topmost overlay before it leaves the page; Escape and Cancel take the overlay's history step
// back out; a link followed from an overlay leaves no stale step; nested overlays close one Back at a time.
import { test, expect } from '@playwright/test';
import { authFile } from '../visual/personas.mjs';

const back = (page) => page.evaluate(() => history.back());
const isOpen = {
    command: (page) => page.evaluate(() => document.documentElement.classList.contains('pos-command-open')),
    drawer: (page) => page.evaluate(() => document.documentElement.classList.contains('pos-drawer-is-open')),
    modal: (page) => page.evaluate(() => !!document.querySelector('.fi-modal.fi-modal-open')),
    assistant: (page) => page.evaluate(() => document.documentElement.classList.contains('pos-ai-open')),
    menu: (page) => page.evaluate(() => !!document.querySelector('.fi-sidebar.fi-sidebar-open')),
};
const openPalette = async (page) => { await page.locator('.pos-command-trigger').first().click(); await expect.poll(() => isOpen.command(page)).toBe(true); };
// Arrive on My work from Home through the app (SPA navigation), so Back has somewhere to go.
const navigate = async (page, url) => {
    // The WebKit engine can reach network idle before Livewire has started: wait for it, as a person would wait for the page.
    await page.waitForFunction(() => !!window.Livewire?.navigate);
    await page.evaluate((u) => window.Livewire.navigate(u), url);
};
const homeThenWork = async (page) => {
    await page.goto('/admin', { waitUntil: 'networkidle' });
    await navigate(page, '/admin/my-work');
    await page.waitForURL(/\/admin\/my-work$/);
    await page.waitForLoadState('networkidle');
};

test.describe('manager', () => {
    test.use({ storageState: authFile('manager') });

    test('Back closes the command center, then leaves the page', async ({ page }) => {
        await homeThenWork(page);
        await openPalette(page);
        await back(page);
        await expect.poll(() => isOpen.command(page)).toBe(false);
        await expect(page).toHaveURL(/\/admin\/my-work$/);
        await back(page);
        await expect(page).toHaveURL(/\/admin$/);
    });

    test('Escape closes the command center and takes its step out: one Back then leaves', async ({ page }) => {
        await homeThenWork(page);
        await openPalette(page);
        await page.keyboard.press('Escape');
        await expect.poll(() => isOpen.command(page)).toBe(false);
        await page.waitForTimeout(300);
        await back(page);
        await expect(page).toHaveURL(/\/admin$/);
    });

    test('Back closes a decision sheet, focus returns, and the queue stays', async ({ page }, info) => {
        test.skip(info.project.name === 'chromium-desktop', 'the desktop decides in the side pane, not a sheet');
        await page.goto('/admin/approvals', { waitUntil: 'networkidle' });
        const row = page.locator('.pos-queue-row').first();
        await row.click();
        await expect.poll(() => isOpen.drawer(page)).toBe(true);
        await back(page);
        await expect.poll(() => isOpen.drawer(page)).toBe(false);
        await expect(page).toHaveURL(/\/admin\/approvals$/);
        await expect(page.locator('.pos-queue-row').first()).toBeVisible();
    });

    test('nested overlays close one Back at a time: the command center over a sheet', async ({ page }) => {
        await homeThenWork(page);
        await page.evaluate(() => window.dispatchEvent(new CustomEvent('pos-drawer-open', { detail: { type: 'person', id: 3 } })));
        await expect.poll(() => isOpen.drawer(page)).toBe(true);
        await page.keyboard.press('Control+k');
        await expect.poll(() => isOpen.command(page)).toBe(true);
        await back(page);
        await expect.poll(() => isOpen.command(page)).toBe(false);
        expect(await isOpen.drawer(page)).toBe(true);
        await back(page);
        await expect.poll(() => isOpen.drawer(page)).toBe(false);
        await expect(page).toHaveURL(/\/admin\/my-work$/);
        await back(page);
        await expect(page).toHaveURL(/\/admin$/);
    });

    test('a link followed from the command center leaves no stale step behind', async ({ page }) => {
        await page.goto('/admin', { waitUntil: 'networkidle' });
        await openPalette(page);
        await page.locator('.pos-command-input').fill('my work');
        await page.waitForLoadState('networkidle');
        await page.locator('[role="option"]', { hasText: 'My work' }).first().click();
        await page.waitForURL(/\/admin\/my-work/);
        await page.waitForLoadState('networkidle');
        expect(await isOpen.command(page)).toBe(false);
        await back(page);
        await expect(page).toHaveURL(/\/admin$/);
        expect(await isOpen.command(page)).toBe(false);
    });
});

test.describe('employee', () => {
    test.use({ storageState: authFile('employee') });

    test('Back closes a modal form without leaving the page', async ({ page }) => {
        await page.goto('/admin', { waitUntil: 'networkidle' });
        await page.locator('[data-pos-action="request_leave_header"]').first().click();
        await expect.poll(() => isOpen.modal(page)).toBe(true);
        await back(page);
        await expect.poll(() => isOpen.modal(page)).toBe(false);
        await expect(page).toHaveURL(/\/admin$/);
        await expect(page.locator('[data-pos-action="request_leave_header"]').first()).toBeVisible();
    });
});

test.describe('hr', () => {
    test.use({ storageState: authFile('hr') });

    test('Back closes the assistant, then the page stays', async ({ page }) => {
        await page.goto('/admin/employees/3', { waitUntil: 'networkidle' });
        await page.locator('button:has-text("Summarise")').first().click();
        await expect.poll(() => isOpen.assistant(page)).toBe(true);
        await back(page);
        await expect.poll(() => isOpen.assistant(page)).toBe(false);
        await expect(page).toHaveURL(/\/admin\/employees\/3/);
    });

    test('Back closes the phone menu', async ({ page }, info) => {
        test.skip(info.project.name === 'chromium-desktop', 'the rail is not an overlay on desktop');
        await page.goto('/admin', { waitUntil: 'networkidle' });
        await page.locator('.fi-topbar-open-sidebar-btn').first().click();
        await expect.poll(() => isOpen.menu(page)).toBe(true);
        await back(page);
        await expect.poll(() => isOpen.menu(page)).toBe(false);
        await expect(page).toHaveURL(/\/admin$/);
    });

    test('the directory filters are part of the page: Back leaves it as usual', async ({ page }, info) => {
        test.skip(info.project.name === 'chromium-desktop', 'filters are always shown on desktop');
        await page.goto('/admin', { waitUntil: 'networkidle' });
        await navigate(page, '/admin/people?view=list');
        await page.waitForURL(/\/admin\/people/);
        await page.waitForLoadState('networkidle');
        const filters = page.locator('button[aria-controls="pos-people-filters"]:visible');
        if (await filters.count()) await filters.first().click();
        await back(page);
        await expect(page).toHaveURL(/\/admin$/);
    });
});
