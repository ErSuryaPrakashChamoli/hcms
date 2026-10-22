// Compensation change with four different people (H15): propose + submit (HR Manager) → mark reviewed (Tenant HR Admin)
// → approve (Tenant Super Admin) → execute/schedule (Payroll Admin). EXECUTED on the disposable training database
// for a fictional employee (Amit Verma). Re-runnable: skips steps already done.
import { start, open, go, shot, finish, idle, modal, toasts, closeToasts } from './lib.mjs';

const s = await start('62-compensation');
const step = async (name, fn) => { try { await fn(); } catch (e) { console.log(`  ✗ ${name}: ${String(e).split('\n')[0].slice(0, 200)}`); } };
const C = { deck: [3, 8, 11], workflow: 'Compensation change (four-person chain)', prereq: 'Four different people: proposer (compensation.propose), reviewer (compensation.review), approver (compensation.approve), executor (compensation.execute)' };
const REASON = 'Annual increment FY 2026-27 (training example)';
const row = (page) => page.locator('tbody tr').filter({ hasText: 'Amit Verma' }).filter({ hasNotText: 'effective' });
const openMenu = async (page) => { await row(page).first().locator('.fi-dropdown-trigger button, button.fi-icon-btn').last().click(); await idle(page, 800); };
const menuItem = (page, label) => page.locator('.fi-dropdown-panel:visible').getByText(label, { exact: true });
const confirm = async (page, note) => {
    if (note) await modal(page).locator('textarea').first().fill(note).catch(() => {});
    await modal(page).locator('.fi-modal-footer-actions button, button[type=submit]').filter({ hasNotText: 'Cancel' }).first().click();
    await idle(page, 2000);
};

// 1. Propose (HR Manager, Neha).
const hr = await open(s, 'hr', '/admin/employees/2?relation=4#records');
const pending = await (async () => { await go(hr, '/admin/compensation-changes'); return row(hr).count(); })();
if (!pending) {
    await step('propose', async () => {
        await go(hr, '/admin/employees/2?relation=4#records');
        await hr.getByText('Compensation changes', { exact: true }).first().scrollIntoViewIfNeeded().catch(() => {});
        await hr.getByRole('button', { name: 'Propose compensation change' }).first().scrollIntoViewIfNeeded();
        await hr.evaluate(() => window.scrollBy(0, -250));
        await idle(hr, 600);
        await shot(s, hr, 'hr-admin/62-comp-01-employee-compensation', { ...C, deck: [3, 11], screen: 'Employee 360 › Records › Rewards › Compensation', action: 'HR Manager opens the employee’s Rewards tab',
            expected: 'Current salary assignment and change history; Propose compensation change and Propose from position',
            callouts: [{ loc: hr.getByRole('button', { name: 'Propose compensation change' }), n: 1, label: 'Propose compensation change' }, { loc: hr.getByRole('button', { name: 'Propose from position' }), n: 2, label: 'Propose from position' }] });
        await hr.setViewportSize({ width: 1440, height: 1300 });
        await hr.getByRole('button', { name: 'Propose compensation change' }).first().click();
        await idle(hr, 1500);
        const m = modal(hr);
        await m.locator('[id$="ctc_annual"]').fill('2640000');
        await m.locator('[id$=".reason"]').fill(REASON);
        await idle(hr, 800);
        await shot(s, hr, 'hr-admin/62-comp-02-propose-form', { ...C, deck: [3, 11], screen: 'Propose compensation change (dialog)', action: 'Change type, effective date, structure, new annual CTC, reason; Submit for review now',
            expected: 'Annual CTC 24,00,000 → 26,40,000 proposed with a reason; internal notes are never shown to the employee', element: '.fi-modal-window:visible >> nth=-1',
            callouts: [{ loc: m.locator('[id$="change_type"]'), n: 1, label: 'Change type' }, { loc: m.locator('[id$="ctc_annual"]'), n: 2, label: 'Annual CTC' }, { loc: m.locator('[id$=".reason"]'), n: 3, label: 'Reason' }, { loc: m.getByText('Submit for review now'), n: 4, label: 'Submit now' }] });
        await m.locator('.fi-modal-footer-actions button, button[type=submit]').filter({ hasNotText: 'Cancel' }).first().click();
        await idle(hr, 2000);
        console.log('    toast:', (await toasts(hr)).join(' / '));
        await hr.setViewportSize({ width: 1440, height: 900 });
        await hr.evaluate(() => window.scrollTo(0, 0));
        await shot(s, hr, 'hr-admin/62-comp-03-submitted', { ...C, screen: 'Employee 360 (after proposing)', action: 'Submit', expected: 'Confirmation "Compensation change submitted for review" with its reference',
            callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Confirmation' }] });
    });
}
await step('list after propose', async () => {
    await go(hr, '/admin/compensation-changes');
    await shot(s, hr, 'hr-admin/62-comp-04-list-submitted', { ...C, screen: 'People › Pay › Compensation changes', action: 'Open Compensation changes', expected: 'The new change is listed as submitted, waiting for review',
        callouts: [{ loc: row(hr), n: 1, label: 'Submitted change' }] });
});

// 2. Review (Tenant HR Admin, Kavya).
const adm = await open(s, 'admin', '/admin/compensation-changes');
await step('review', async () => {
    await openMenu(adm);
    await shot(s, adm, 'hr-admin/62-comp-05-reviewer-menu', { ...C, screen: 'Compensation changes › row actions (reviewer)', action: 'Reviewer opens the row actions',
        expected: 'Mark reviewed, Reject, Return to proposer — only the steps this person may take are shown',
        callouts: [{ loc: menuItem(adm, 'Mark reviewed'), n: 1, label: 'Mark reviewed' }, { loc: menuItem(adm, 'Return to proposer'), n: 2, label: 'Return to proposer' }] });
    await menuItem(adm, 'Mark reviewed').click();
    await idle(adm, 1200);
    await confirm(adm, 'Within the FY27 budget guideline (training example)');
    console.log('    toast:', (await toasts(adm)).join(' / '));
    await shot(s, adm, 'hr-admin/62-comp-06-reviewed', { ...C, screen: 'Compensation changes (after review)', action: 'Mark reviewed → Confirm', expected: 'Status moves to under review',
        callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Result' }, { loc: row(adm), n: 2, label: 'Under review' }] });
});
await step('sod: reviewer cannot approve', async () => {
    await closeToasts(adm);
    await go(adm, '/admin/compensation-changes');
    await openMenu(adm);
    await shot(s, adm, 'security-compliance/62-comp-07-reviewer-cannot-approve', { ...C, deck: [8, 3, 11], workflow: 'Separation of duties: compensation', screen: 'Compensation changes › row actions (after reviewing)',
        action: 'The reviewer (who holds compensation.approve) opens the actions again', expected: 'No Approve action: a person who already acted on the change cannot approve it (four different people required)',
        callouts: [{ loc: adm.locator('.fi-dropdown-panel:visible'), n: 1, label: 'No Approve for the reviewer' }] });
    await adm.keyboard.press('Escape');
});

// 3. Approve (Tenant Super Admin, Demo Admin).
const sup = await open(s, 'superadmin', '/admin/compensation-changes');
await step('approve', async () => {
    await openMenu(sup);
    await menuItem(sup, 'Approve').click();
    await idle(sup, 1200);
    await shot(s, sup, 'hr-admin/62-comp-08-approve-confirm', { ...C, screen: 'Approve (confirmation)', action: 'Approver (a third person) chooses Approve', expected: 'Confirmation with an optional note', element: '.fi-modal-window:visible >> nth=-1' });
    await confirm(sup, 'Approved (training example)');
    console.log('    toast:', (await toasts(sup)).join(' / '));
    await shot(s, sup, 'hr-admin/62-comp-09-approved', { ...C, screen: 'Compensation changes (after approval)', action: 'Approve → Confirm', expected: 'Status approved; waiting for payroll to execute',
        callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Result' }, { loc: row(sup), n: 2, label: 'Approved' }] });
});

// 4. Execute (Payroll Admin, Arjun).
const pay = await open(s, 'payroll', '/admin/compensation-changes');
await step('execute', async () => {
    await openMenu(pay);
    await menuItem(pay, 'Execute (schedule)').click();
    await idle(pay, 1200);
    await confirm(pay);
    console.log('    toast:', (await toasts(pay)).join(' / '));
    await shot(s, pay, 'hr-admin/62-comp-10-executed', { ...C, screen: 'Compensation changes (after execution)', action: 'Payroll Admin (a fourth person): Execute (schedule) → Confirm',
        expected: 'Status scheduled; the new salary takes effect on the effective date and is used by payroll from then',
        callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Result' }, { loc: pay.locator('tbody tr').filter({ hasText: 'Amit Verma' }).first(), n: 2, label: 'Scheduled change' }] });
});

// 5. Compensation analytics.
await step('analytics', async () => {
    await go(adm, '/admin/compensation-analytics');
    await shot(s, adm, 'analytics/62-comp-11-compensation-analytics', { deck: [12, 3], workflow: 'Compensation analytics', screen: 'People › Pay › Analytics (Compensation analytics)', action: 'Open Compensation analytics',
        expected: 'Pay distribution, compa-ratios and breakdowns; groups smaller than the minimum size are suppressed', prereq: 'compensation.view; analytics minimum group size', fullPage: true });
});

await finish(s);
