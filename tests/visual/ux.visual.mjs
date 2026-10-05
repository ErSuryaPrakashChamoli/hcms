// UX.18 visual regression: the role experiences at phone, tablet and desktop, light and dark, plus overlays, forms,
// notifications, search and empty states. Every screen is captured as the persona who owns it, with that persona's
// own permissions. Data comes from the frozen-clock showcase rebuilt for each run (README.md), so a difference is a
// change in the product, never in the date.
import { test, expect } from '@playwright/test';
import { PERSONAS, authFile } from './personas.mjs';

const P = 'phone', T = 'tablet', D = 'desktop';
const L = ['light'], LD = ['light', 'dark'];

/** [id, persona, path, viewports, themes, action?] — ids are stable baseline names. */
export const SHOTS = [
    // Employee
    ['employee-home', 'employee', '/admin', [P, T, D], LD],
    ['employee-my-work', 'employee', '/admin/my-work', [P, D], L],
    ['employee-people', 'employee', '/admin/people', [P], L],
    ['employee-my-hr', 'employee', '/admin/my-hr', [P], L],
    ['employee-palette-leave', 'employee', '/admin', [P, D], LD, 'palette:leave'],
    ['employee-request-leave-form', 'employee', '/admin', [P, D], L, 'leave'],
    ['employee-notifications', 'employee', '/admin/notifications', [P], L],
    // Manager
    ['manager-home', 'manager', '/admin', [P, T, D], LD],
    ['manager-home-expanded', 'manager', '/admin', [P], L, 'more'],
    ['manager-my-work', 'manager', '/admin/my-work', [P, D], L],
    ['manager-approvals', 'manager', '/admin/approvals', [P, T, D], LD],
    ['manager-approval-sheet', 'manager', '/admin/approvals', [P], L, 'decide'],
    ['manager-my-team', 'manager', '/admin/my-team', [P], L],
    ['manager-360-report', 'manager', '/admin/employees/3', [P, D], LD],
    ['manager-notifications', 'manager', '/admin/notifications', [P, D], LD],
    ['manager-bell', 'manager', '/admin', [P], L, 'bell'],
    // HR
    ['hr-home', 'hr', '/admin', [P, T, D], LD],
    ['hr-my-work', 'hr', '/admin/my-work', [P], L],
    ['hr-people-list', 'hr', '/admin/people?view=list', [P, T, D], LD],
    ['hr-people-filters', 'hr', '/admin/people?view=list', [P], L, 'filters'],
    ['hr-employees-table', 'hr', '/admin/employees', [P, D], L],
    ['hr-requests', 'hr', '/admin/tickets', [P], L],
    ['hr-360', 'hr', '/admin/employees/3', [P, T, D], LD],
    ['hr-assistant', 'hr', '/admin/employees/3', [P], L, 'ai'],
    ['hr-hire-form', 'hr', '/admin/employees/create', [P, D], L],
    ['hr-menu', 'hr', '/admin', [P], L, 'menu'],
    // Executive
    ['executive-home', 'executive', '/admin', [P, T, D], LD],
    ['executive-pulse', 'executive', '/admin/workforce-command-centre', [P, D], L],
    ['executive-pulse-drill', 'executive', '/admin/workforce-command-centre', [D], L, 'drill'],
    ['executive-org-map', 'executive', '/admin/organisation-map', [P, D], L],
    // Administrator
    ['admin-home', 'admin', '/admin', [P, T, D], LD],
    ['admin-centre', 'admin', '/admin/admin-centre', [P, D], L],
    ['admin-users', 'admin', '/admin/users', [P, D], L],
    ['admin-audit', 'admin', '/admin/audit-events', [P], L],
    ['admin-360-identity', 'admin', '/admin/employees/3', [P, D], L],
    ['admin-department-form', 'admin', '/admin/departments/create', [D], L],
    // Payroll
    ['payroll-home', 'payroll', '/admin', [P, D], L],
    ['payroll-people', 'payroll', '/admin/people', [P], L],
    // States: empty, loading (the request held while the skeleton shows), error
    ['executive-approvals-empty', 'executive', '/admin/approvals', [P, D], LD],
    ['hr-person-sheet-loading', 'hr', '/admin/people?view=grid', [P], L, 'loading-sheet'],
    ['employee-page-not-found', 'employee', '/admin/does-not-exist', [P, D], L, 'status:404'],
];

/** Cross-engine smoke (Firefox desktop, WebKit-engine phone): a small set, its own baselines. */
const ENGINE_SHOTS = new Set(['manager-home', 'hr-people-list', 'admin-home', 'employee-palette-leave']);

async function act(page, action) {
    if (!action) return;
    if (action.startsWith('palette:')) {
        await page.locator('.pos-command-trigger').first().click();
        // fill(), not typing: a keystroke sent before the field has focus is lost (it recorded "eave" once in Firefox).
        const input = page.locator('.pos-command-input');
        await expect(input).toBeFocused();
        await input.fill(action.slice(8));
        await input.press('End'); // opening selects the query; collapse the selection so it never races the screenshot
        // The search is debounced: wait for the last answer, not the first.
        await page.waitForLoadState('networkidle');
        await page.locator('#pos-command-list [role="option"]').first().waitFor({ state: 'visible' });
    } else if (action === 'leave') {
        await page.locator('[data-pos-action="request_leave_header"]').first().click();
        await page.locator('.fi-modal-window:visible').first().waitFor();
    } else if (action === 'more') {
        await page.locator('.pos-phone-more:visible').first().click();
    } else if (action === 'decide') {
        await page.locator('.pos-queue-row').first().click();
        await page.locator('.pos-drawer[data-open="true"] .pos-btn-success').first().waitFor({ state: 'visible' });
    } else if (action === 'bell') {
        await page.locator('.fi-topbar [aria-label^="Notifications"]').first().click();
        await page.locator('.fi-modal-window:visible').first().waitFor();
    } else if (action === 'filters') {
        await page.locator('button[aria-controls="pos-people-filters"]').first().click();
    } else if (action === 'ai') {
        await page.locator('button:has-text("Summarise")').first().click();
        await page.locator('.pos-ai-panel .pos-ai-thread').first().waitFor({ state: 'visible' });
    } else if (action === 'menu') {
        await page.locator('.fi-topbar-open-sidebar-btn').first().click();
        await page.locator('.fi-sidebar.fi-sidebar-open').waitFor();
    } else if (action === 'loading-sheet') {
        // Hold the sheet's request so its loading skeleton is what is captured (released when the page closes).
        await page.route('**/livewire*/update', () => new Promise(() => {}));
        await page.evaluate(() => window.dispatchEvent(new CustomEvent('pos-drawer-open', { detail: { type: 'person', id: 3 } })));
        await page.locator('.pos-drawer[data-open="true"] .pos-skeleton, .pos-drawer[data-open="true"] [aria-busy="true"]').first().waitFor({ state: 'visible' });
    } else if (action === 'drill') {
        await page.locator('.pos-figure').first().click();
        await page.locator('.pos-drawer[data-open="true"]').first().waitFor({ state: 'visible' });
    }
    await page.waitForTimeout(400);
}

for (const persona of Object.keys(PERSONAS)) {
    test.describe(persona, () => {
        test.use({ storageState: authFile(persona) });
        for (const [id, who, path, viewports, themes, action] of SHOTS.filter((s) => s[1] === persona)) {
            for (const theme of themes) {
                test(`${id} · ${theme}`, async ({ page }, info) => {
                    const { vp, engine } = info.project.metadata;
                    test.skip(!viewports.includes(vp), `${id} is not captured at ${vp}`);
                    test.skip(!!engine && (!ENGINE_SHOTS.has(id) || theme !== 'light'), 'outside the cross-engine smoke set');
                    await page.addInitScript((t) => { try { localStorage.setItem('theme', t); } catch {} }, theme);
                    await page.emulateMedia({ colorScheme: theme, reducedMotion: 'reduce' });
                    const response = await page.goto(path, { waitUntil: 'networkidle' });
                    // A baseline is only ever the page it claims to be: the page itself (200), or the error page a shot
                    // is about (status:NNN); never an unexpected refusal or a sign-in screen.
                    const expected = action?.startsWith('status:') ? Number(action.slice(7)) : 200;
                    expect(response?.status(), `${path} answered ${response?.status()}`).toBe(expected);
                    await expect(page).not.toHaveURL(/\/login/);
                    await page.evaluate(() => document.fonts.ready);
                    await act(page, action);
                    await expect(page).toHaveScreenshot(`${id}-${theme}.png`);
                });
            }
        }
    });
}
