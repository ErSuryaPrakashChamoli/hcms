// HR services (Deck 3): letters (generate → approve by another person → issue → employee view), assets, HR queue.
// EXECUTED on the disposable training database (fictional people).
import { start, open, go, shot, finish, idle, settle, modal, setData, toasts, cancelModal, dump } from './lib.mjs';

/** Close visible toasts only (lib's closeToasts also waits on hidden notification-panel items). */
async function closeToasts(page) {
    for (const b of await page.locator('.fi-no-notification:visible .fi-no-notification-close-btn, .fi-no-notification:visible button.fi-icon-btn').all()) await b.click({ timeout: 2000 }).catch(() => {});
    await page.waitForTimeout(400);
}
const groups = process.argv.slice(2);
const want = (g) => groups.length === 0 || groups.includes(g);
const s = await start('52-hr-services');
const byId = (page, id) => modal(page).locator(`[id="mountedActionSchema0.${id}"]`);
async function pick(page, id, text, scope = page) {
    await scope.locator(`[id="${id}"]`).click();
    await page.waitForTimeout(500);
    await page.keyboard.type(text.slice(0, 12), { delay: 30 });
    await page.waitForTimeout(1200);
    await page.locator('.fi-select-input-option:visible, [role=option]:visible').filter({ hasText: text }).first().click();
    await page.waitForTimeout(700);
}
async function errorShown(page) {
    if (!await page.locator('#livewire-error').count()) return false;
    await page.evaluate(() => { const f = document.querySelector('#livewire-error iframe'); f?.contentWindow?.scrollTo(0, 0); });
    await page.waitForTimeout(400);
    return true;
}
async function removeError(page) {
    await page.evaluate(() => { const d = document.getElementById('livewire-error'); if (d) { try { d.close(); } catch {} d.remove(); } });
}
let f26Captured = false;
/** Letter pages load their History section automatically; outside production it raises F26. Capture once, then remove. */
async function clearF26(page, D) {
    await page.waitForTimeout(2000);
    if (!await errorShown(page)) return;
    if (!f26Captured) {
        f26Captured = true;
        await shot(s, page, 'support/52-let-err-01-letter-history-f26', { ...D, deck: [7], workflow: 'Letter page: History section error (F26, non-production)', screen: 'Letters › letter page',
            action: 'Open a letter', status: 'INCOMPLETE',
            expected: 'Internal Server Error over the page: LazyLoadingViolationException "Attempted to lazy load [fieldChanges] on model AuditEvent" (AuditHistoryRelationManager.php:38). Closing the dialog leaves the page usable.',
            note: 'Known finding F26: strict mode is on outside production only; production behaviour unverified.' });
    }
    await removeError(page);
    await page.waitForTimeout(500);
}
/** Click a row action of the letter for Priya; actions may sit in a row menu. */
async function rowAction(page, rowText, name) {
    const row = page.locator('tbody tr', { hasText: rowText }).first();
    const direct = row.getByRole('button', { name, exact: true }).or(row.getByRole('link', { name, exact: true }));
    if (await direct.count()) { await direct.first().click(); return true; }
    const more = row.locator('button[aria-label], .fi-dropdown-trigger button').last();
    if (await more.count()) { await more.click(); await idle(page, 600); const item = page.locator('.fi-dropdown-panel:visible').getByText(name, { exact: true }); if (await item.count()) { await item.first().click(); return true; } }
    return false;
}

if (want('letters')) {
    const D = { deck: [3, 8], workflow: 'Letters: generate → approve (different person) → issue', prereq: 'letter.issue (HR Manager, Tenant HR Admin); templates marked "needs approval" must be approved by someone other than the requester' };
    const h = await open(s, 'hr', '/admin/letters');
    // A letter whose template needs no approval (generated in an earlier run: LTR-2026-00002) is issued straight away.
    const ec = h.locator('tbody tr', { hasText: 'Employment certificate – Priya Nair' }).first();
    if (await ec.count() && /Approved/.test(await ec.innerText())) {
        await ec.getByRole('link', { name: 'Open' }).click(); await idle(h, 2000);
        await clearF26(h, D);
        await shot(s, h, 'hr-admin/52-let-02b-ready-to-issue', { ...D, workflow: 'Letters: issue a letter that needs no approval', screen: 'Letters › LTR-2026-00002 (Employment certificate)',
            action: 'Open a generated letter whose template does not need approval', expected: 'The letter preview with Issue (status Approved: "ready to issue")',
            callouts: [{ loc: h.getByRole('button', { name: 'Issue', exact: true }), n: 1, label: 'Issue' }] });
        await closeToasts(h);
        await h.getByRole('button', { name: 'Issue', exact: true }).click(); await idle(h, 1200);
        if (await h.locator('.fi-modal-window:visible').count()) await modal(h).getByRole('button', { name: /Submit|Confirm|Issue/ }).last().click();
        await idle(h, 2000);
        await clearF26(h, D);
        console.log('    issue LTR-2', (await toasts(h)).slice(0, 1));
        await go(h, '/admin/letters');
    }
    if (!process.env.REUSE_PENDING) {
        await h.getByRole('button', { name: 'Generate letter' }).click(); await idle(h, 1500);
        await pick(h, 'mountedActionSchema0.employee_id', 'Priya Nair', modal(h));
        await byId(h, 'template_id').selectOption({ label: 'No objection certificate' }); await idle(h, 1200);
        const extra = modal(h).locator('table input[type=text]');
        if (await extra.count() >= 2) { await extra.nth(0).fill('purpose'); await extra.nth(1).fill('Visa application (training example)'); await idle(h, 600); }
        await shot(s, h, 'hr-admin/52-let-02-generate-form', { ...D, screen: 'Letters › Generate letter', action: 'Choose the employee and the template (No objection certificate); add extra fields',
            expected: 'Template merge fields come from the employee record; extra key/value fields are optional', element: '.fi-modal-window:visible >> nth=-1',
            callouts: [{ loc: modal(h).locator('[id="mountedActionSchema0.employee_id"]'), n: 1, label: 'Employee' }, { loc: byId(h, 'template_id'), n: 2, label: 'Template' }, { loc: modal(h).locator('table'), n: 3, label: 'Extra fields' }, { loc: modal(h).getByRole('button', { name: 'Submit' }), n: 4, label: 'Submit' }] });
        await closeToasts(h);
        await modal(h).getByRole('button', { name: 'Submit' }).click(); await idle(h, 2500);
        console.log('    generate toast', (await toasts(h)).slice(0, 1));
    }
    await go(h, '/admin/letters');
    const row = h.locator('tbody tr', { hasText: 'No objection certificate – Priya Nair' }).filter({ hasText: 'Pending approval' }).first();
    console.log('    row', (await row.innerText()).replace(/\s+/g, ' '));
    if (!process.env.REUSE_PENDING) await shot(s, h, 'hr-admin/52-let-03-pending', { ...D, screen: 'Letters (after Generate)', action: 'Submit', expected: 'The new letter waits for approval before it can be issued',
        callouts: [{ loc: row, n: 1, label: 'New letter' }, { loc: row.locator('.fi-badge'), n: 2, label: 'Status' }] });
    await row.getByRole('link', { name: 'Open' }).click(); await idle(h, 2000);
    await clearF26(h, D);
    const btns = await h.locator('main button').allInnerTexts();
    console.log('    requester sees', btns.map((b) => b.trim()).filter(Boolean).join(' | '));
    const appr = h.getByRole('button', { name: 'Approve', exact: true });
    if (await appr.count()) {
        await closeToasts(h);
        await appr.click(); await idle(h, 1200);
        if (await h.locator('.fi-modal-window:visible').count()) { await modal(h).getByRole('button', { name: /Submit|Confirm|Approve/ }).last().click(); await idle(h, 2000); }
        const err = await errorShown(h);
        console.log('    self-approve', err ? 'error page' : (await toasts(h)).slice(0, 1));
        await shot(s, h, 'security-compliance/52-let-04-requester-cannot-approve', { ...D, deck: [3, 8, 7], workflow: 'Separation of duties: letter approval', screen: 'Letters › Approve (by the requester)',
            action: 'The HR person who generated the letter tries to approve it',
            expected: err ? 'Refused, as a server error page: "The person who requested a letter cannot approve it."' : 'Refused: "The person who requested a letter cannot approve it."',
            callouts: err ? [] : [{ sel: '.fi-no-notification', n: 1, label: 'Refused' }] });
        if (err) await removeError(h);
    } else {
        await shot(s, h, 'security-compliance/52-let-04-requester-cannot-approve', { ...D, deck: [3, 8], workflow: 'Separation of duties: letter approval', screen: 'Letters › letter (requester’s view)',
            action: 'The HR person who generated the letter opens it', expected: 'No Approve action is offered to the requester' });
    }
    const url = h.url();
    await h.close();
    const a = await open(s, 'admin', url.replace(/^https?:\/\/[^/]+/, ''));
    await clearF26(a, D);
    await shot(s, a, 'hr-admin/52-let-05-approver-view', { ...D, screen: 'Letters › letter (second HR person)', action: 'A different HR person opens the letter', expected: 'Preview with Approve / Reject',
        callouts: [{ loc: a.getByRole('button', { name: 'Approve', exact: true }), n: 1, label: 'Approve' }, { loc: a.getByRole('button', { name: 'Reject', exact: true }), n: 2, label: 'Reject' }] });
    await closeToasts(a);
    await a.getByRole('button', { name: 'Approve', exact: true }).click(); await idle(a, 1200);
    if (await a.locator('.fi-modal-window:visible').count()) { const ta = modal(a).locator('textarea'); if (await ta.count()) await ta.first().fill('Checked (training example)'); await modal(a).getByRole('button', { name: /Submit|Confirm|Approve/ }).last().click(); }
    await idle(a, 2000);
    console.log('    approve toast', (await toasts(a)).slice(0, 1));
    await clearF26(a, D);
    await closeToasts(a);
    await a.getByRole('button', { name: 'Issue', exact: true }).click(); await idle(a, 1200);
    if (await a.locator('.fi-modal-window:visible').count()) await modal(a).getByRole('button', { name: /Submit|Confirm|Issue/ }).last().click();
    await idle(a, 2000);
    console.log('    issue toast', (await toasts(a)).slice(0, 1));
    await shot(s, a, 'hr-admin/52-let-06-issued', { ...D, screen: 'Letters › letter (after Approve and Issue)', action: 'Approve, then Issue', expected: 'Issued; Download available',
        callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Issued' }] });
    await a.close();
    const e = await open(s, 'employee', '/admin/letters');
    await shot(s, e, 'employee/52-let-07-my-letters', { ...D, deck: [1, 3], workflow: 'My letters (employee)', screen: 'My letters', action: 'The employee opens My letters', expected: 'Only their own issued letters, with Open',
        callouts: [{ loc: e.locator('tbody tr', { hasText: 'No objection' }), n: 1, label: 'Issued letter' }] });
    const open1 = e.locator('tbody tr', { hasText: 'No objection' }).getByRole('link', { name: 'Open' });
    if (await open1.count()) {
        await open1.first().click(); await idle(e, 2000);
        await clearF26(e, D);
        await shot(s, e, 'employee/52-let-08-letter-view', { ...D, deck: [1, 3], workflow: 'My letters (employee)', screen: 'Letter (employee view)', action: 'Open the letter', expected: 'The issued letter on screen (HTML; no PDF or e-signature yet)' });
    }
    await e.close();
}

if (want('replay')) {
    const D = { deck: [3, 8], workflow: 'Letters: generate → approve (different person) → issue', prereq: 'letter.issue (HR Manager, Tenant HR Admin)' };
    const h = await open(s, 'hr', '/admin/letters');
    await h.getByRole('button', { name: 'Generate letter' }).click(); await idle(h, 1500);
    await pick(h, 'mountedActionSchema0.employee_id', 'Priya Nair', modal(h));
    await byId(h, 'template_id').selectOption({ label: 'No objection certificate' }); await idle(h, 1200);
    const extra = modal(h).locator('table input[type=text]');
    if (await extra.count() >= 2) { await extra.nth(0).fill('purpose'); await extra.nth(1).fill('Visa application (training example)'); await idle(h, 600); }
    await shot(s, h, 'hr-admin/52-let-02-generate-form', { ...D, screen: 'Letters › Generate letter', action: 'Choose the employee and the template (No objection certificate); add extra fields',
        expected: 'Template merge fields come from the employee record; templates marked "needs approval" go to Pending approval', element: '.fi-modal-window:visible >> nth=-1',
        callouts: [{ loc: modal(h).locator('[id="mountedActionSchema0.employee_id"]'), n: 1, label: 'Employee' }, { loc: byId(h, 'template_id'), n: 2, label: 'Template' }, { loc: modal(h).locator('table'), n: 3, label: 'Extra fields' }, { loc: modal(h).getByRole('button', { name: 'Submit' }), n: 4, label: 'Submit' }] });
    await cancelModal(h);
    await go(h, '/admin/letters');
    await shot(s, h, 'hr-admin/52-let-03-pending', { ...D, screen: 'Letters (list)', action: 'Check the list after generating',
        expected: 'Letters by status: Pending approval (NOC), Issued (employment certificate, NOC)',
        callouts: [{ loc: h.locator('tbody tr', { hasText: 'Pending approval' }), n: 1, label: 'Pending approval' }, { loc: h.getByRole('button', { name: /Waiting for approval/ }), n: 2, label: 'Status tabs' }] });
    await h.close();
}

if (want('assets')) {
    const D = { deck: [3, 6], workflow: 'Assets: issue a laptop to a new joiner', prereq: 'asset.manage (Asset Admin, Tenant HR Admin)' };
    const p = await open(s, 'admin', '/admin/assets');
    await shot(s, p, 'hr-admin/52-ast-01-register', { ...D, screen: 'Organisation › Assets › Asset register', action: 'Open the asset register',
        expected: 'Assets with tag, category, serial, status (In stock / Assigned), custodian, location, warranty; Receive asset',
        callouts: [{ loc: p.getByRole('link', { name: 'Receive asset' }), n: 1, label: 'Receive asset' }, { loc: p.locator('tbody tr', { hasText: 'LT-0004' }), n: 2, label: 'Spare laptop (In stock)' }, { loc: p.getByRole('button', { name: /In stock/ }), n: 3, label: 'Status tabs' }] });
    await p.locator('tbody tr', { hasText: 'LT-0004' }).getByRole('link', { name: 'View' }).click(); await idle(p, 2000);
    await clearF26(p, D);
    await p.getByRole('button', { name: 'Assign', exact: true }).click(); await idle(p, 1500);
    await pick(p, 'mountedActionSchema0.employee_id', 'Tanvi Joshi', modal(p));
    await byId(p, 'condition').selectOption({ index: 1 }).catch(() => {});
    await byId(p, 'note').fill('Issued on joining day with charger and bag (training example)');
    await idle(p, 600);
    await shot(s, p, 'hr-admin/52-ast-02-assign', { ...D, screen: 'Asset register › LT-0004 › Assign', action: 'Assign the spare laptop: employee, date, condition, expected return, note',
        expected: 'Custody is recorded against the employee', element: '.fi-modal-window:visible >> nth=-1',
        callouts: [{ loc: modal(p).locator('[id="mountedActionSchema0.employee_id"]'), n: 1, label: 'Employee' }, { loc: byId(p, 'condition'), n: 2, label: 'Condition' }, { loc: byId(p, 'note'), n: 3, label: 'Note' }] });
    await closeToasts(p);
    await modal(p).getByRole('button', { name: 'Submit' }).click(); await idle(p, 2500);
    await clearF26(p, D);
    console.log('    assign toast', (await toasts(p)).slice(0, 1));
    await shot(s, p, 'hr-admin/52-ast-03-assigned', { ...D, screen: 'Asset register › LT-0004 (after Assign)', action: 'Submit', expected: 'Status Assigned; custody history shows Tanvi Joshi from today',
        callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Assigned' }, { loc: p.getByText('Custody history'), n: 2, label: 'Custody history' }] });
    await p.close();
    const e = await open(s, 'employee', '/admin/assets');
    await shot(s, e, 'employee/52-ast-04-my-assets', { ...D, deck: [1, 3], workflow: 'My assets (employee)', screen: 'My assets', action: 'The employee opens My assets',
        expected: 'Only the assets in their custody; the employee acknowledges receipt', callouts: [{ loc: e.locator('tbody tr').first(), n: 1, label: 'My laptop' }] });
    await e.close();
}

async function employeeTicketView(D, tkt) {
    const e2 = await open(s, 'employee2', '/admin/tickets');
    await e2.getByRole('button', { name: 'Remove all filters' }).first().click().catch(() => {}); await idle(e2, 1500);
    await e2.getByRole('button', { name: /^Resolved/ }).first().click().catch(() => {}); await idle(e2, 1500);
    await shot(s, e2, 'employee/52-tkt-06-my-requests', { ...D, deck: [1, 3], workflow: 'Raise an HR request (employee): result', screen: 'My requests (filter cleared, Resolved tab)', action: 'The employee opens My requests, clears the default status filter and chooses Resolved',
        expected: 'The answered request is listed as Resolved',
        note: 'The list opens with a status filter that excludes resolved requests; with that filter on, the Resolved tab shows "No requests". Clear the filter (×) first.',
        callouts: [{ loc: e2.getByRole('button', { name: /^Resolved/ }), n: 1, label: 'Status tabs' }, { loc: e2.locator('tbody tr', { hasText: tkt }), n: 2, label: 'Resolved request' }] });
    await e2.locator('tbody tr', { hasText: tkt }).getByRole('link', { name: 'Open' }).click(); await idle(e2, 2000); await clearF26(e2, D);
    await shot(s, e2, 'employee/52-tkt-07-request-answer', { ...D, deck: [1, 3], workflow: 'Raise an HR request (employee): result', screen: 'My requests › TKT request', action: 'Open the request',
        expected: 'Status Resolved with HR’s resolution; the conversation shows the reply; the employee can close and rate or reopen',
        callouts: [{ loc: e2.getByText('Resolution', { exact: false }), n: 1, label: 'Resolution' }, { loc: e2.getByRole('tab', { name: 'Conversation' }), n: 2, label: 'Conversation' }] });
    await e2.close();
}
if (want('tktview')) await employeeTicketView({ deck: [1, 3], prereq: 'ticket.create' }, process.env.TKT ?? 'TKT-2026-00003');

if (want('hrqueue')) {
    const D = { deck: [3, 1], workflow: 'HR request: raise → handle → resolve', prereq: 'Employees: ticket.create (My HR); HR: ticket.manage (HR Manager, Tenant HR Admin)' };
    let tkt = process.env.TKT ?? '';
    if (!tkt) {
        const e = await open(s, 'employee2', '/admin/my-hr');
        await e.getByRole('button', { name: 'Ask HR' }).first().click(); await idle(e, 1500);
        await byId(e, 'ticket_category_id').selectOption({ label: 'Payroll & tax query' }); await idle(e, 600);
        await byId(e, 'subject').fill('Which tax regime applies to me this year? (training example)');
        await byId(e, 'description').fill('I joined last year and did not choose a regime. Please confirm the default and how to change it (training example).');
        await byId(e, 'priority').selectOption({ label: 'Normal' }).catch(() => {});
        await idle(e, 600);
        await shot(s, e, 'employee/52-tkt-01-ask-hr', { ...D, deck: [1, 3], workflow: 'Raise an HR request (employee)', screen: 'My HR › Ask HR', action: 'Choose a category, write a subject and the question, set the priority',
            expected: 'The request goes to the HR queue with an SLA from its category', element: '.fi-modal-window:visible >> nth=-1',
            callouts: [{ loc: byId(e, 'ticket_category_id'), n: 1, label: 'Category' }, { loc: byId(e, 'subject'), n: 2, label: 'Subject' }, { loc: byId(e, 'description'), n: 3, label: 'Question' }, { loc: byId(e, 'priority'), n: 4, label: 'Priority' }] });
        await closeToasts(e);
        await modal(e).getByRole('button', { name: 'Submit' }).click(); await idle(e, 2500);
        const t0 = (await toasts(e)).join(' ');
        tkt = (t0.match(/TKT-\d{4}-\d{5}/) ?? [''])[0];
        console.log('    ask toast', t0.slice(0, 80), tkt);
        await shot(s, e, 'employee/52-tkt-02-raised', { ...D, deck: [1, 3], workflow: 'Raise an HR request (employee)', screen: 'My HR (after submitting)', action: 'Submit', expected: 'Confirmation with the request number',
            callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Request number' }] });
    }
    const h = await open(s, 'hr', '/admin/tickets');
    const row = h.locator('tbody tr', { hasText: tkt }).first();
    await shot(s, h, 'hr-admin/52-tkt-03-hr-queue', { ...D, screen: 'Services › Service Desk › HR queue', action: 'HR opens the queue', expected: 'New requests with category, requester, priority, status, assignee and SLA due',
        callouts: [{ loc: row, n: 1, label: 'New request' }, { loc: h.locator('th', { hasText: 'SLA DUE' }), n: 2, label: 'SLA due' }] });
    await row.getByRole('link', { name: 'Open' }).click(); await idle(h, 2000);
    await clearF26(h, D);
    await closeToasts(h);
    await h.getByRole('button', { name: 'Take it' }).click(); await idle(h, 1500);
    if (await h.locator('.fi-modal-window:visible').count()) await modal(h).getByRole('button', { name: /Submit|Confirm|Take/ }).last().click();
    await idle(h, 1500); await clearF26(h, D);
    console.log('    take toast', (await toasts(h)).slice(0, 1));
    await closeToasts(h);
    await h.getByRole('button', { name: 'Reply' }).click(); await idle(h, 1500);
    const ta = modal(h).locator('textarea, [contenteditable=true]').first();
    await ta.fill('The new regime is the default from FY 2026-27. You can change it in your tax declaration before 31 Dec (training example).');
    await idle(h, 600);
    await shot(s, h, 'hr-admin/52-tkt-04-reply', { ...D, screen: 'HR queue › request › Reply', action: 'Take it, then Reply to the employee', expected: 'The reply is added to the conversation the employee sees',
        element: '.fi-modal-window:visible >> nth=-1', callouts: [{ loc: ta, n: 1, label: 'Reply' }] });
    await modal(h).getByRole('button', { name: /Submit|Send|Reply/ }).last().click(); await idle(h, 2000); await clearF26(h, D);
    console.log('    reply toast', (await toasts(h)).slice(0, 1));
    await closeToasts(h);
    await h.getByRole('button', { name: 'More' }).click(); await idle(h, 800);
    const items = await h.locator('.fi-dropdown-panel:visible').allInnerTexts();
    console.log('    more items', items.join(' | ').replace(/\s+/g, ' '));
    const resolve = h.locator('.fi-dropdown-panel:visible').getByText(/^Resolve/).first();
    if (await resolve.count()) {
        await resolve.click(); await idle(h, 1500);
        const rt = modal(h).locator('textarea').first(); if (await rt.count()) await rt.fill('Answered: default regime confirmed (training example)');
        await modal(h).getByRole('button', { name: /Submit|Confirm|Resolve/ }).last().click(); await idle(h, 2000); await clearF26(h, D);
        console.log('    resolve toast', (await toasts(h)).slice(0, 1));
    } else await h.keyboard.press('Escape');
    await h.reload(); await settle(h); await clearF26(h, D);
    await shot(s, h, 'hr-admin/52-tkt-05-resolved', { ...D, screen: 'HR queue › request (after Take it, Reply, Resolve)', action: 'Resolve', expected: 'Status Resolved; assignee, SLA and the conversation are kept on the request',
        callouts: [{ loc: h.getByText('ASSIGNMENT AND SLA', { exact: false }), n: 1, label: 'Assignment and SLA' }, { loc: h.getByText('Conversation', { exact: true }), n: 2, label: 'Conversation' }] });
    const url = h.url().replace(/^https?:\/\/[^/]+/, '');
    await h.close();
    await employeeTicketView(D, tkt);
}

await finish(s);
