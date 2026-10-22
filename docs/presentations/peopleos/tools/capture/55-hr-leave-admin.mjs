// HR leave administration (Deck 3): balances and adjustment, leave types, leave policy, Apply on behalf (F38) and the
// working alternative from the Employee 360, leave calendar. EXECUTED on the disposable training database.
import { start, open, go, shot, finish, idle, settle, modal, setData, toasts, cancelModal } from './lib.mjs';

/** Close visible toasts only (lib's closeToasts also waits on hidden notification-panel items). */
async function closeToasts(page) {
    for (const b of await page.locator('.fi-no-notification:visible .fi-no-notification-close-btn, .fi-no-notification:visible button.fi-icon-btn').all()) await b.click({ timeout: 2000 }).catch(() => {});
    await page.waitForTimeout(400);
}
const groups = process.argv.slice(2);
const want = (g) => groups.length === 0 || groups.includes(g);
const s = await start('55-hr-leave-admin');
const byId = (page, id) => modal(page).locator(`[id="mountedActionSchema0.${id}"]`);
const ZOYA = 12;
const D = { deck: [3], prereq: 'leave.manage (Tenant HR Admin, HR Manager, Attendance Admin …)' };

async function dropError(page) {
    await page.evaluate(() => { const f = document.querySelector('#livewire-error iframe'); f?.contentWindow?.scrollTo(0, 0); });
}
async function removeError(page) {
    await page.evaluate(() => { const d = document.getElementById('livewire-error'); if (d) { try { d.close(); } catch {} d.remove(); } });
}

if (want('balances')) {
    const W = { ...D, workflow: 'Leave balances and adjustment (HR)' };
    const p = await open(s, 'admin', '/admin/leave-balances');
    await shot(s, p, 'hr-admin/55-lv-01-balances', { ...W, deck: [3, 12], screen: 'People › Time & leave › Balances',
        action: 'Open Balances', expected: 'Per person, type and year: opening, accrued, adjusted, used, pending, encashed, lapsed, closing, available; Run accrual now; Adjust / Encash per row',
        callouts: [{ loc: p.getByRole('button', { name: 'Run accrual now' }), n: 1, label: 'Run accrual now' }, { loc: p.getByRole('button', { name: 'Adjust' }), n: 2, label: 'Adjust' }, { loc: p.getByRole('button', { name: 'Encash' }), n: 3, label: 'Encash' }] });
    // Searching the balances by name fails (new defect): capture it, then work without the search box.
    await p.getByPlaceholder(/Search/).first().fill('Zoya');
    await idle(p, 2500);
    if (!await p.locator('#livewire-error').count()) { await p.locator('tbody tr').first().click({ trial: true }).catch(() => {}); await idle(p, 1500); }
    if (await p.locator('#livewire-error').count()) {
        await dropError(p); await idle(p, 500);
        await shot(s, p, 'support/55-lv-err-02-balances-search', { ...W, deck: [7, 3], workflow: 'Leave balances: search by name fails', screen: 'People › Time & leave › Balances (search box)',
            action: 'Type a name in the Balances search box', status: 'INCOMPLETE',
            expected: 'Internal Server Error: QueryException SQLSTATE[42S22] "Unknown column \'people\' in \'where clause\'" — the name search is built as a JSON path on the people table. Workaround: filters / paging, or the person’s Employee 360 › Records › Time.',
            note: 'Not in documentation-gaps F1–F46. Seen on MySQL.' });
        await removeError(p);
        await go(p, '/admin/leave-balances');
    }
    const row = p.locator('tbody tr', { hasText: 'Nisha Reddy' }).filter({ hasText: 'Casual leave' }).first();
    await row.getByRole('button', { name: 'Adjust' }).click(); await idle(p, 1500);
    const ids = await modal(p).locator('[id^="mountedActionSchema0."]').evaluateAll((els) => els.map((e) => e.id));
    console.log('    adjust fields', ids.join(', '));
    const num = modal(p).locator('input[type=number], input[id$=".days"]').first();
    await num.fill('1');
    await modal(p).locator('textarea').first().fill('Compensation for working on the Dussehra holiday (training example)');
    await idle(p, 600);
    await shot(s, p, 'hr-admin/55-lv-02-adjust-form', { ...W, screen: 'Balances › Adjust',
        action: 'Adjust a balance: days (+ or −) and a note', expected: 'The adjustment is posted to the leave ledger with the note',
        element: '.fi-modal-window:visible >> nth=-1', callouts: [{ loc: num, n: 1, label: 'Days' }, { loc: modal(p).locator('textarea'), n: 2, label: 'Note' }] });
    await closeToasts(p);
    await modal(p).getByRole('button', { name: /Submit|Save|Adjust/ }).last().click(); await idle(p, 2000);
    console.log('    adjust toast', (await toasts(p)).slice(0, 1));
    await p.mouse.move(5, 300); await idle(p, 600);
    await shot(s, p, 'hr-admin/55-lv-03-adjusted', { ...W, screen: 'Balances (after Adjust)',
        action: 'Submit', expected: 'Adjusted and Available updated for the row', callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Confirmation' }, { loc: p.locator('tbody tr', { hasText: 'Nisha Reddy' }).filter({ hasText: 'Casual leave' }), n: 2, label: 'Updated balance' }] });
    await go(p, '/admin/leave-transactions');
    await shot(s, p, 'hr-admin/55-lv-04-ledger', { ...W, deck: [3, 10], screen: 'People › Time & leave › Transactions (Leave ledger entries)',
        action: 'Open the leave ledger', expected: 'Append-only ledger: the adjustment appears as its own entry with the note',
        callouts: [{ loc: p.locator('tbody tr', { hasText: 'Dussehra' }), n: 1, label: 'Adjustment entry' }] });
    await p.close();
}

if (want('retake03')) {
    const p = await open(s, 'admin', '/admin/leave-balances');
    await p.mouse.move(5, 300); await idle(p, 600);
    await shot(s, p, 'hr-admin/55-lv-03-adjusted', { ...D, workflow: 'Leave balances and adjustment (HR)', screen: 'Balances (after Adjust)',
        action: 'Submit the adjustment ("Balance adjusted")', expected: 'Adjusted and Available updated for the row (Casual leave: adjusted 1.0)',
        callouts: [{ loc: p.locator('tbody tr', { hasText: 'Nisha Reddy' }).filter({ hasText: 'Casual leave' }), n: 1, label: 'Updated balance' }] });
    await p.close();
}

if (want('types')) {
    const W = { ...D, deck: [3, 4], workflow: 'Leave types and leave policy (configuration)' };
    const p = await open(s, 'admin', '/admin/leave-types');
    await shot(s, p, 'hr-admin/55-lv-05-leave-types', { ...W, screen: 'People › Time & leave › All in Time & leave › Leave types',
        action: 'Open Leave types', expected: 'Name, code, category, paid, half day, encashable, unit, approval, eligibility, status; New leave type',
        callouts: [{ loc: p.getByRole('button', { name: 'New leave type' }), n: 1, label: 'New leave type' }, { loc: p.locator('tbody tr', { hasText: 'Casual leave' }), n: 2, label: 'Leave type' }] });
    await p.locator('tbody tr', { hasText: 'Casual leave' }).getByRole('button', { name: 'Edit' }).click(); await idle(p, 1500);
    await shot(s, p, 'hr-admin/55-lv-06-leave-type-edit', { ...W, screen: 'Leave types › Edit Casual leave',
        action: 'Open a leave type (opened only, then cancelled)', expected: 'Rules of the type; the code is locked on edit; a reason is required for changes',
        element: '.fi-modal-window:visible >> nth=-1' });
    await cancelModal(p);
    await go(p, '/admin/policies');
    await shot(s, p, 'hr-admin/55-lv-07-policies', { ...W, deck: [3, 4, 10], screen: 'Workflows › Policies',
        action: 'Open Policies', expected: 'Versioned policies (exit, attendance, working hours, leave) with effective dates',
        callouts: [{ loc: p.getByRole('link', { name: 'Standard leave' }), n: 1, label: 'Leave policy' }] });
    await p.getByRole('link', { name: 'Standard leave' }).first().click(); await idle(p, 2000);
    await shot(s, p, 'hr-admin/55-lv-08-leave-policy', { ...W, deck: [3, 4, 10], screen: 'Policies › Standard leave',
        action: 'Open the leave policy', expected: 'Policy versions with effective dates; entitlements per leave type live in the version settings',
        fullPage: false });
    await p.close();
}

if (want('behalf')) {
    const W = { ...D, workflow: 'Apply leave on behalf of an employee (HR)' };
    const p = await open(s, 'admin', '/admin/leave-requests');
    await shot(s, p, 'hr-admin/55-lv-09-requests-hr', { ...W, deck: [3, 12], screen: 'People › Time & leave › Leave requests (HR view)',
        action: 'Open Leave requests', expected: 'Everyone’s requests with status tabs; Apply on behalf; Approve / Reject / Cancel per row; link to the Approval Center',
        callouts: [{ loc: p.getByRole('button', { name: 'Apply on behalf' }), n: 1, label: 'Apply on behalf' }, { loc: p.getByRole('button', { name: /Waiting for a decision/ }), n: 2, label: 'Status tabs' }, { loc: p.getByText('Decide in the Approval Center', { exact: false }), n: 3, label: 'Approval Center' }] });
    await p.getByRole('button', { name: 'Apply on behalf' }).click(); await idle(p, 2500);
    const err = await p.locator('#livewire-error').count();
    console.log('    apply-on-behalf error dialog', err);
    if (err) {
        await dropError(p); await idle(p, 500);
        await shot(s, p, 'support/55-lv-err-01-apply-on-behalf', { ...W, deck: [3, 7], workflow: 'Apply on behalf (known defect F38)', screen: 'Leave requests › Apply on behalf',
            action: 'Open Apply on behalf', status: 'INCOMPLETE',
            expected: 'Internal Server Error when the dialog opens: "Call to a member function getFormData() on array" (F38). Use the Employee 360 instead (next steps).',
            note: 'ListLeaveRequests.php:27 — still present at 72c96a73.' });
        await removeError(p);
    }
    await p.close();

    const W2 = { ...D, workflow: 'Apply leave on behalf (working route: Employee 360)' };
    const e = await open(s, 'admin', `/admin/employees/${ZOYA}`);
    await e.setViewportSize({ width: 1440, height: 1200 });
    await e.getByRole('link', { name: 'Records', exact: true }).first().click(); await idle(e, 2000);
    const t = e.getByRole('tab', { name: 'Time', exact: true });
    await t.click(); await idle(e, 2500);
    const apply = e.getByRole('button', { name: 'Apply for leave' });
    for (let i = 0; i < 8 && !(await apply.isVisible().catch(() => false)); i++) { await e.mouse.wheel(0, 400); await idle(e, 800); }
    await apply.evaluate((x) => { x.scrollIntoView({ block: 'center' }); }); await idle(e, 600);
    await shot(s, e, 'hr-admin/55-lv-10-360-time-apply', { ...W2, screen: 'Employee 360 › Records › Time',
        action: 'Open the person’s Records › Time', expected: 'Attendance and leave for the person; the Leave section shows balances and Apply for leave',
        callouts: [{ loc: apply, n: 1, label: 'Apply for leave' }, { loc: e.getByText(/Leave · balances/), n: 2, label: 'Balances' }] });
    await apply.click(); await idle(e, 1500);
    const m = modal(e);
    const typeSel = m.locator('select').filter({ has: e.locator('option', { hasText: 'Casual leave' }) }).first();
    const label = (await typeSel.locator('option', { hasText: 'Casual leave' }).first().innerText()).trim();
    await typeSel.selectOption({ label }); await idle(e, 900);
    await setData(e, 'from_date', '2026-11-23');
    await setData(e, 'to_date', '2026-11-23');
    await m.locator('textarea').first().fill('Requested by phone while travelling (training example)');
    await idle(e, 900);
    await shot(s, e, 'hr-admin/55-lv-11-apply-form', { ...W2, screen: 'Employee 360 › Records › Time › Apply for leave',
        action: 'HR enters the request for the employee', expected: 'Same form and preview as the employee’s own request',
        element: '.fi-modal-window:visible >> nth=-1', callouts: [{ loc: typeSel, n: 1, label: 'Leave type' }, { loc: m.locator('textarea'), n: 2, label: 'Reason' }] });
    await closeToasts(e);
    await m.getByRole('button', { name: /Submit/ }).last().click(); await idle(e, 2000);
    console.log('    on-behalf toast', (await toasts(e)).slice(0, 1));
    await e.setViewportSize({ width: 1440, height: 900 });
    await shot(s, e, 'hr-admin/55-lv-12-applied', { ...W2, screen: 'Employee 360 (after applying)',
        action: 'Submit', expected: 'Confirmation; the request goes to the employee’s approver like any other', callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Confirmation' }] });
    await e.close();
}

if (want('calendar')) {
    const p = await open(s, 'admin', '/admin/leave-calendar');
    await shot(s, p, 'hr-admin/55-lv-13-leave-calendar', { ...D, deck: [3, 12], workflow: 'Leave calendar (HR view)', screen: 'People › Time & leave › Leave calendar',
        action: 'Open the leave calendar', expected: 'Approved and pending leave in the window (this month to the end of next); reasons are never shown',
        callouts: [{ loc: p.locator('th', { hasText: /STATUS/i }), n: 1, label: 'Status' }] });
    await p.close();
}

await finish(s);
