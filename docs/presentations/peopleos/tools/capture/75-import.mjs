// Employee import (Deck 3, 4, 7, 11): Upload CSV → Inspect file → Map columns → the F25 failure → Discard.
// EXECUTED on the disposable training database with a fictional 3-row CSV (tools/fixtures/).
import { start, open, go, shot, finish, idle, settle, modal, toasts } from './lib.mjs';

const s = await start('75-import');
async function closeToasts(page) {
    for (const b of await page.locator('.fi-no-notification:visible .fi-no-notification-close-btn, .fi-no-notification:visible button.fi-icon-btn').all()) await b.click({ timeout: 2000 }).catch(() => {});
    await page.waitForTimeout(400);
}
const D = { deck: [3, 4, 11], workflow: 'Employee import (bulk load)', prereq: 'employee.import (Tenant HR Admin, Account Owner, Tenant Super Admin)' };
const CSV = new URL('../fixtures/training-example-employee-import.csv', import.meta.url).pathname;

const p = await open(s, 'admin', '/admin/employee-imports');
await shot(s, p, 'hr-admin/75-imp-01-imports', { ...D, screen: 'People › Lifecycle › All in Lifecycle › Employee imports',
    action: 'Open Employee imports', expected: 'Import batches with their status; Upload CSV',
    callouts: [{ loc: p.getByRole('button', { name: 'Upload CSV' }), n: 1, label: 'Upload CSV' }] });
let existing = await p.locator('tbody tr').count();
if (!process.env.REUSE_IMPORT) {
    await p.getByRole('button', { name: 'Upload CSV' }).click(); await idle(p, 1500);
    await modal(p).locator('input[type=file]').setInputFiles(CSV); await idle(p, 3000);
    await shot(s, p, 'hr-admin/75-imp-02-upload', { ...D, screen: 'Employee imports › Upload CSV', action: 'Choose a CSV with one row per person',
        expected: 'The file is uploaded; the batch is created for inspection', element: '.fi-modal-window:visible >> nth=-1',
        callouts: [{ loc: modal(p).locator('.filepond--root'), n: 1, label: 'CSV file' }] });
    await closeToasts(p);
    await modal(p).getByRole('button', { name: /Submit|Upload|Create/ }).last().click(); await idle(p, 3000);
    console.log('    upload toast', (await toasts(p)).slice(0, 1), p.url());
}
if (!/employee-imports\/\d+/.test(p.url())) {
    await go(p, '/admin/employee-imports');
    await p.locator('tbody tr').first().getByRole('link').last().click().catch(() => {});
    await idle(p, 2000);
}
console.log('    import page', p.url(), (await p.locator('main button').allInnerTexts()).map((x) => x.trim()).filter(Boolean).join(' | '));
await shot(s, p, 'hr-admin/75-imp-03-uploaded', { ...D, screen: 'Employee import (batch)', action: 'Open the uploaded batch', expected: 'Status Uploaded; next step Inspect file',
    callouts: [{ loc: p.getByRole('button', { name: 'Inspect file' }), n: 1, label: 'Inspect file' }] });
const inspect = p.getByRole('button', { name: 'Inspect file' });
if (await inspect.count()) {
    await closeToasts(p);
    await inspect.click(); await idle(p, 1500);
    if (await p.locator('.fi-modal-window:visible').count()) await modal(p).getByRole('button', { name: /Submit|Confirm|Inspect/ }).last().click();
    await idle(p, 2500);
    console.log('    inspect toast', (await toasts(p)).slice(0, 1));
    await shot(s, p, 'hr-admin/75-imp-04-inspected', { ...D, screen: 'Employee import (after Inspect file)', action: 'Inspect file',
        expected: 'Columns and row count detected; status Inspected; next step Map columns',
        callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Inspected' }, { loc: p.getByRole('button', { name: 'Map columns' }), n: 2, label: 'Map columns' }] });
}
await closeToasts(p);
await p.getByRole('button', { name: 'Map columns' }).click(); await idle(p, 2000);
const m = modal(p);
const fields = { first_name: 'First name', last_name: 'Last name', personal_email: 'Personal email', work_email: 'Work email', joining_date: 'Joining date' };
for (const [i, header] of ['first_name', 'last_name', 'personal_email', 'work_email', 'joining_date'].entries()) {
    const btn = m.locator(`[id="mountedActionSchema0.mapping.${i}"]`);
    if (!await btn.count()) continue;
    await btn.click(); await p.waitForTimeout(500);
    await p.keyboard.type(fields[header].slice(0, 10), { delay: 30 }); await p.waitForTimeout(1000);
    const opt = p.locator('.fi-select-input-option:visible, [role=option]:visible').filter({ hasText: fields[header] }).first();
    if (await opt.count()) await opt.click(); else await p.keyboard.press('Escape');
    await p.waitForTimeout(600);
}
await idle(p, 800);
await shot(s, p, 'hr-admin/75-imp-05-map-columns', { ...D, screen: 'Employee import › Map columns', action: 'Choose the PeopleOS field for each CSV column; decide what happens when the person already exists',
    expected: 'Columns mapped (fields marked * are required)', element: '.fi-modal-window:visible >> nth=-1',
    callouts: [{ loc: m.locator('[id="mountedActionSchema0.mapping.0"]'), n: 1, label: 'Column → field' }, { loc: m.locator('[id="mountedActionSchema0.on_duplicate"]'), n: 2, label: 'When the person exists' }, { loc: m.getByRole('button', { name: /Submit|Save/ }), n: 3, label: 'Submit' }] });
await closeToasts(p);
await m.getByRole('button', { name: /Submit|Save/ }).last().click(); await idle(p, 2500);
const t = await toasts(p);
console.log('    map toast', t.slice(0, 1));
const err = await p.locator('#livewire-error').count();
if (err) await p.evaluate(() => { const f = document.querySelector('#livewire-error iframe'); f?.contentWindow?.scrollTo(0, 0); });
await shot(s, p, 'support/75-imp-06-map-columns-fails-f25', { ...D, deck: [3, 4, 7, 11], workflow: 'Employee import: Map columns fails (known defect F25)', screen: 'Employee import (after Map columns › Submit)',
    action: 'Submit the column mapping', status: 'INCOMPLETE',
    expected: `Mapping is not saved: ${t[0]?.replace(/\s+/g, ' ').trim() || 'error'} (F25: the Map columns action uses $svc without capturing it). The batch stays Inspected; Validate, Approve and Run are unreachable from the screen.`,
    note: 'app/Filament/Support/ImportActions.php map action; still present at 72c96a73. Punch imports share the same code.',
    callouts: err ? [] : [{ sel: '.fi-no-notification', n: 1, label: 'Failure' }] });
if (err) await p.evaluate(() => { const d = document.getElementById('livewire-error'); if (d) { try { d.close(); } catch {} d.remove(); } });
// Clean up: discard the batch.
await closeToasts(p);
await p.keyboard.press('Escape'); await idle(p, 600);
await p.reload(); await settle(p);
const more = p.getByRole('button', { name: 'More' });
if (await more.count()) {
    await more.click(); await idle(p, 700);
    const discard = p.locator('.fi-dropdown-panel:visible').getByText(/^Discard/).first();
    if (await discard.count()) {
        await discard.click(); await idle(p, 1200);
        const r = modal(p).locator('textarea'); if (await r.count()) await r.first().fill('Training example: mapping could not be saved (F25)');
        await modal(p).getByRole('button', { name: /Submit|Confirm|Discard/ }).last().click(); await idle(p, 2000);
        console.log('    discard toast', (await toasts(p)).slice(0, 1));
        await shot(s, p, 'hr-admin/75-imp-07-discarded', { ...D, screen: 'Employee import (after Discard)', action: 'More › Discard', expected: 'The batch is discarded; nothing was imported',
            callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Discarded' }] });
    }
}
await p.close();
const n = await open(s, 'hr', '/admin/employee-imports');
await shot(s, n, 'security-compliance/75-imp-08-hr-manager-not-available', { ...D, deck: [3, 7, 8], workflow: 'Restricted access: imports', screen: 'Employee imports (as HR Manager)',
    action: 'An HR Manager opens Employee imports', expected: '403 "This page is not available to you": nothing shown, nothing changed (employee.import is not in the HR Manager role)',
    callouts: [{ loc: n.getByText('This page is not available to you'), n: 1, label: 'Not available' }] });
await finish(s);
