// HR administrator core workflows (Deck 3): Directory, register, Employee 360, hire with duplicate check,
// guided changes, onboarding, documents. EXECUTED on the disposable training database (fictional people).
// Run all groups or name some: node capture/50-hr-core.mjs directory 360 hire change onboarding docs
import { start, open, go, shot, finish, idle, settle, modal, setData, setForm, toasts, cancelModal } from './lib.mjs';

/** Close visible toasts only (lib's closeToasts also waits on hidden notification-panel items). */
async function closeToasts(page) {
    for (const b of await page.locator('.fi-no-notification:visible .fi-no-notification-close-btn, .fi-no-notification:visible button.fi-icon-btn').all()) await b.click({ timeout: 2000 }).catch(() => {});
    await page.waitForTimeout(400);
}
const groups = process.argv.slice(2);
const want = (g) => groups.length === 0 || groups.includes(g);
const s = await start('50-hr-core');
const byId = (page, id) => modal(page).locator(`[id="mountedActionSchema0.${id}"]`);
const PRIYA = 4;
const TANVI = Number(process.env.TANVI_ID ?? 24);   // the fictional hire created by the 'hire' group
/** Open an item of the Employee 360 Action menu. "Change manager" sits under the sticky section bar (F27), so a DOM click is used. */
async function actionMenu(page, item) {
    await page.getByRole('button', { name: 'Action', exact: true }).click();
    await page.waitForTimeout(700);
    await page.locator('.fi-dropdown-panel').getByText(item, { exact: true }).evaluate((e) => e.closest('button,a').click());
    await idle(page, 1500);
}
/** Record 5xx Livewire responses, so error states are labelled honestly; remove the debug overlay afterwards. */
function watch5xx(page) { page.__5xx = []; page.on('response', (r) => { if (r.status() >= 500) page.__5xx.push(r.status()); }); }
async function hasErrorPage(page) {
    for (const f of page.frames()) { if (await f.getByText('Internal Server Error').count().catch(() => 0)) return true; }
    return false;
}
async function dropOverlay(page) { await page.evaluate(() => { const d = document.getElementById('livewire-error'); if (d) { try { d.close(); } catch {} d.remove(); } document.body.style.overflow = ''; }).catch(() => {}); await page.waitForTimeout(400); }
/** Choose an option in a Filament searchable select (button[id=...] → search → option). */
async function pick(page, id, text, scope = page) {
    await scope.locator(`[id="${id}"]`).click();
    await page.waitForTimeout(500);
    await page.keyboard.type(text.slice(0, 12), { delay: 30 });
    await page.waitForTimeout(1200);
    await page.locator('.fi-select-input-option:visible, [role=option]:visible').filter({ hasText: text }).first().click();
    await page.waitForTimeout(700);
}
async function setWire(page, path, value) {
    await page.evaluate(([k, v]) => {
        const c = window.Livewire.all().find((c) => k.split('.')[0] in c.$wire || c.$wire.get(k.split('.')[0]) !== undefined);
        return c.$wire.set(k, v);
    }, [path, value]);
    await page.waitForTimeout(800);
}
/** After scrolling, lazily loaded sections may fail: capture the error once (support/), then close it. */
async function checkLazyError(s, page, D, tab, label) {
    await page.waitForTimeout(1800);
    if (!await page.locator('#livewire-error').count()) return false;
    await page.evaluate(() => { const f = document.querySelector('#livewire-error iframe'); f?.contentWindow?.scrollTo(0, 0); });
    console.log('    lazy-load error on', tab);
    await shot(s, page, `support/50-360-err-01-records-${label}`, { ...D, deck: [7, 3], workflow: 'Employee 360: section fails to load', screen: `Employee 360 › Records › ${tab}`,
        action: `Records › ${tab}, then scroll down`, status: 'INCOMPLETE',
        expected: 'A lower section loads lazily on scroll and fails: "Internal Server Error" — TypeError Filament\\Tables\\Table::getRelationshipQuery() must return an Eloquent Builder, Query Builder returned. Local debug mode shows the exception page.',
        note: 'Deterministic after scrolling the Employment tab. Likely OnboardingRelationManager::getRelationship() (declared Relation|Builder). Not in documentation-gaps F1–F46.' });
    await dropOverlay(page);
    return true;
}

if (want('directory')) {
    const D = { deck: [3], workflow: 'Find people: Directory and register', prereq: 'employee.view (HR roles); scope applies' };
    const p = await open(s, 'admin', '/admin/people');
    await p.getByRole('button', { name: 'Cards' }).click().catch(() => {});
    await idle(p, 1000);
    await shot(s, p, 'hr-admin/50-dir-01-directory-cards', { ...D, deck: [3, 9], screen: 'People › Directory (Cards)',
        action: 'Open People › Directory', expected: 'Everyone the HR user can see, with search, department, location, status and grouping filters',
        callouts: [{ loc: p.getByPlaceholder('Name, employee ID or work email'), n: 1, label: 'Search' }, { loc: p.locator('main select').first(), n: 2, label: 'Filters' },
            { loc: p.getByRole('button', { name: 'Cards' }), n: 3, label: 'Cards / Table' }, { loc: p.getByRole('link', { name: 'Employee register' }), n: 4, label: 'Employee register' }] });
    await p.getByPlaceholder('Name, employee ID or work email').fill('Priya');
    await idle(p, 1200);
    await p.getByText('Priya Nair', { exact: true }).first().click();
    await idle(p, 1500);
    await shot(s, p, 'hr-admin/50-dir-02-person-preview', { ...D, screen: 'People › Directory › person preview',
        action: 'Search for a name and select the person', expected: 'A preview drawer with role, manager and quick actions (Open profile, Message, Org map, Start a change)',
        callouts: [{ loc: p.getByRole('link', { name: /Open profile/ }), n: 1, label: 'Open profile' }, { loc: p.getByText(/Start a change/), n: 2, label: 'Start a change' }] });
    await go(p, '/admin/employees');
    await shot(s, p, 'hr-admin/50-dir-03-employee-register', { ...D, deck: [3, 4], screen: 'People › Lifecycle › Employees',
        action: 'Open the Employee register', expected: 'Table with ID, name, designation, department, manager, status, joining date; Hire employee; View and Edit per row',
        callouts: [{ loc: p.getByRole('link', { name: 'Hire employee' }), n: 1, label: 'Hire employee' }, { loc: p.getByPlaceholder('Search employees'), n: 2, label: 'Search' },
            { loc: p.locator('.fi-ta-filters-trigger-action-ctn, button[title="Filter"]').first(), n: 3, label: 'Filters' }, { loc: p.getByRole('link', { name: 'View' }), n: 4, label: 'View' }] });
    await p.close();
}

if (want('360')) {
    const D = { deck: [3], workflow: 'Employee 360 (HR view)', prereq: 'employee.view; sensitive tabs need their own permissions' };
    {   // Employment tab on a fresh page (in the longer sequence below it intermittently raises a server error; see support/50-360-err-01).
        const e = await open(s, 'admin', `/admin/employees/${PRIYA}`);
        await e.getByRole('link', { name: 'Records', exact: true }).first().click(); await idle(e, 6000);
        const t = e.getByRole('tab', { name: 'Employment', exact: true });
        await t.click(); await idle(e, 2500);
        await t.evaluate((x) => { x.scrollIntoView({ block: 'start' }); window.scrollBy(0, -110); }); await idle(e, 600);
        await checkLazyError(s, e, D, 'Employment', 'employment');
        await t.evaluate((x) => { x.scrollIntoView({ block: 'start' }); window.scrollBy(0, -110); }); await idle(e, 600);
        await shot(s, e, 'hr-admin/50-360-05-records-employment', { ...D, screen: 'Employee 360 › Records › Employment', action: 'Records › Employment',
            expected: 'Position assignments, reporting lines and onboarding, each with effective dates', callouts: [{ loc: t, n: 1, label: 'Employment' }] });
        await e.close();
    }
    const p = await open(s, 'admin', `/admin/employees/${PRIYA}`);
    watch5xx(p);
    await shot(s, p, 'hr-admin/50-360-01-header-now', { ...D, deck: [3, 9], screen: 'Employee 360 › Now',
        action: 'Open a person from the Directory or register', expected: 'Header with status, tenure and manager; Summarise, Message, Request, Action, More; views Now … Records',
        callouts: [{ loc: p.getByText('One lifetime record'), n: 1, label: 'One lifetime record' }, { loc: p.getByRole('button', { name: 'Request', exact: true }), n: 2, label: 'Request' },
            { loc: p.getByRole('button', { name: 'Action', exact: true }), n: 3, label: 'Action' }, { loc: p.getByRole('button', { name: 'More', exact: true }), n: 4, label: 'More' },
            { loc: p.getByRole('link', { name: 'Records', exact: true }), n: 5, label: 'Records' }, { loc: p.getByText('IDENTITY AND ACCESS', { exact: false }), n: 6, label: 'Identity and access' }] });
    await p.getByRole('button', { name: 'Action', exact: true }).click();
    await idle(p, 800);
    await shot(s, p, 'hr-admin/50-360-02-action-menu', { ...D, screen: 'Employee 360 › Action menu',
        action: 'Open Action', expected: 'Transfer / promote, Change manager, Change lifecycle state, Start onboarding, Initiate background check, Assign work schedule, Start workflow',
        callouts: [{ loc: p.locator('.fi-dropdown-panel'), n: 1, label: 'Guided changes' }] });
    await p.keyboard.press('Escape');
    await idle(p, 400);
    await p.getByRole('button', { name: 'More', exact: true }).click();
    await idle(p, 800);
    await shot(s, p, 'hr-admin/50-360-03-more-menu', { ...D, screen: 'Employee 360 › More menu',
        action: 'Open More', expected: 'Edit profile, All changes, Statutory details, Edit statutory details',
        callouts: [{ loc: p.locator('.fi-dropdown-panel'), n: 1, label: 'More' }] });
    await p.keyboard.press('Escape');
    await idle(p, 400);
    await p.getByRole('link', { name: 'Journey', exact: true }).first().click();
    await idle(p, 1500);
    await shot(s, p, 'hr-admin/50-360-04-journey', { ...D, screen: 'Employee 360 › Journey',
        action: 'Open the Journey view', expected: 'Effective-dated history of the person: joining, changes, lifecycle',
        callouts: [{ loc: p.getByRole('link', { name: 'Journey', exact: true }), n: 1, label: 'Journey' }] });
    await p.getByRole('link', { name: 'Records', exact: true }).first().click();
    await idle(p, 1500);
    for (const [i, tab, label, exp] of [
        [6, 'Time', 'time', 'Attendance and leave for this person; Apply for leave on their behalf'],
        [7, 'Rewards', 'rewards', 'Compensation and payroll information (visible only with the matching permission)'],
        [8, 'Operations', 'operations', 'Documents, assets and other operational records'],
        [9, 'Bank & personal', 'bank-personal', 'Bank accounts, identifiers and personal details (sensitive; permission-controlled)'],
    ]) {
        const btn = p.getByRole('tab', { name: tab, exact: true });
        if (!await btn.isVisible().catch(() => false)) { console.log('    no tab', tab); continue; }
        await btn.click();
        await idle(p, 2500);
        await btn.evaluate((e) => { e.scrollIntoView({ block: 'start' }); window.scrollBy(0, -110); });
        await idle(p, 600);
        if (await checkLazyError(s, p, D, tab, label)) await btn.evaluate((e) => { e.scrollIntoView({ block: 'start' }); window.scrollBy(0, -110); });
        await shot(s, p, `hr-admin/50-360-${String(i).padStart(2, '0')}-records-${label}`, { ...D, deck: tab === 'Bank & personal' ? [3, 8] : [3], screen: `Employee 360 › Records › ${tab}`,
            action: `Records › ${tab}`, expected: exp, callouts: [{ loc: btn, n: 1, label: tab }] });
        await dropOverlay(p);
    }
    const hist = p.getByRole('tab', { name: 'History', exact: true });
    p.__5xx = [];
    await hist.click();
    await idle(p, 3000);
    await hist.evaluate((e) => { e.scrollIntoView({ block: 'start' }); window.scrollBy(0, -110); });
    await idle(p, 600);
    const err = await p.locator('#livewire-error').count() > 0;
    await shot(s, p, 'hr-admin/50-360-10-records-history', { ...D, deck: [3, 7, 8], screen: 'Employee 360 › Records › History',
        action: 'Records › History', expected: err ? 'Error shown instead of the history (F26, non-production strict mode)' : 'Audited changes to this record: who, when, before and after',
        status: err ? 'INCOMPLETE' : 'VERIFIED IN RUNNING APPLICATION', callouts: [{ loc: hist, n: 1, label: 'History' }] });
    console.log('    history error?', err);
    await p.close();
}

if (want('hire')) {
    const D = { deck: [3, 4, 11], workflow: 'Hire an employee (with duplicate check)', prereq: 'employee.create (Tenant HR Admin, HR Manager, HR Executive, Account Owner)' };
    const p = await open(s, 'admin', '/admin/employees/create');
    await p.setViewportSize({ width: 1440, height: 1250 });
    // Duplicate check: the same personal e-mail as an existing person is refused.
    await p.locator('[id="form.first_name"]').fill('Priya');
    await p.locator('[id="form.last_name"]').fill('Nair');
    await p.locator('[id="form.personal_email"]').fill('priya.nair@example.test');
    await p.getByRole('button', { name: 'Next' }).click(); await idle(p, 1500);
    await shot(s, p, 'hr-admin/50-hire-01-duplicate-refused', { ...D, deck: [3, 4, 7, 8, 11], workflow: 'Person matching and duplicate prevention', screen: 'Hire employee › Person',
        action: 'Enter a person whose personal e-mail already exists and press Next', expected: '"This person already exists — Priya Nair (EMP00004) — same personal email." The wizard does not move on',
        callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Duplicate refused' }, { loc: p.locator('[id="form.personal_email"]'), n: 2, label: 'Matching field' }, { loc: p.locator('[id="form.existing_person_id"], [id^="form.person"]'), n: 3, label: 'Re-use existing person' }] });
    await closeToasts(p);
    // A genuinely new person.
    await p.locator('[id="form.first_name"]').fill('Tanvi');
    await p.locator('[id="form.last_name"]').fill('Joshi');
    await p.locator('[id="form.personal_email"]').fill('tanvi.joshi@example.test');
    await p.locator('[id="form.personal_phone"]').fill('+91 90000 11223');
    await p.locator('[id="form.gender"]').selectOption({ index: 2 }).catch(() => {});
    await idle(p, 600);
    await shot(s, p, 'hr-admin/50-hire-02-person-step', { ...D, screen: 'Hire employee › 01 Person',
        action: 'Enter the new joiner’s personal details (fictional)', expected: 'Name, personal e-mail and phone; no duplicate found',
        callouts: [{ loc: p.locator('[id="form.first_name"]'), n: 1, label: 'First name (required)' }, { loc: p.locator('[id="form.personal_email"]'), n: 2, label: 'Personal e-mail (matched)' }, { loc: p.getByRole('button', { name: 'Next' }), n: 3, label: 'Next' }] });
    await p.getByRole('button', { name: 'Next' }).click(); await idle(p, 1500);
    await p.locator('[id="form.work_email"]').fill('tanvi.joshi@demo.local');
    await idle(p, 500);
    await shot(s, p, 'hr-admin/50-hire-03-employment-step', { ...D, screen: 'Hire employee › 02 Employment',
        action: 'Joining date, probation end (blank = tenant default), employee code (blank = next code), work e-mail', expected: 'Employment details completed',
        callouts: [{ loc: p.locator('[id="form.joining_date"]'), n: 1, label: 'Joining date' }, { loc: p.locator('[id="form.employee_code"]'), n: 2, label: 'Code (auto if blank)' }, { loc: p.locator('[id="form.work_email"]'), n: 3, label: 'Work e-mail' }] });
    await p.getByRole('button', { name: 'Next' }).click(); await idle(p, 1500);
    await pick(p, 'form.company_id', 'Demo Technologies');
    await pick(p, 'form.location_id', 'Delhi');
    await pick(p, 'form.department_id', 'Engineering');
    await pick(p, 'form.team_id', 'Platform');
    await pick(p, 'form.designation_id', 'Software Engineer');
    await idle(p, 600);
    await shot(s, p, 'hr-admin/50-hire-04-position-step', { ...D, screen: 'Hire employee › 03 Position',
        action: 'Place the person: company (required), location, department, team, designation …', expected: 'Organisation placement chosen from the configured structure',
        callouts: [{ loc: p.locator('[id="form.company_id"]'), n: 1, label: 'Company (required)' }, { loc: p.locator('[id="form.department_id"]'), n: 2, label: 'Department' }, { loc: p.locator('[id="form.team_id"]'), n: 3, label: 'Team' }, { loc: p.locator('[id="form.designation_id"]'), n: 4, label: 'Designation' }] });
    await p.getByRole('button', { name: 'Next' }).click(); await idle(p, 1500);
    await pick(p, 'form.manager_id', 'Amit Verma');
    await p.locator('[id="form.audit_reason"]').fill('New joiner for the Platform team (training example)');
    await idle(p, 800);
    await shot(s, p, 'hr-admin/50-hire-05-manager-step', { ...D, screen: 'Hire employee › 04 Manager',
        action: 'Choose the line manager and give a reason (recorded in the audit trail)', expected: 'Manager and reason entered; review and create',
        callouts: [{ loc: p.locator('[id="form.manager_id"]'), n: 1, label: 'Line manager' }, { loc: p.locator('[id="form.audit_reason"]'), n: 2, label: 'Reason (audited)' }, { loc: p.getByRole('button', { name: /Create|Hire/ }), n: 3, label: 'Create' }] });
    const create = p.getByRole('button', { name: /^(Create|Create employee|Hire employee|Hire)$/ });
    console.log('    create buttons', await create.allInnerTexts());
    await create.first().click();
    await idle(p, 2500);
    console.log('    after create URL', p.url(), (await toasts(p)).slice(0, 1));
    if (await p.locator('.fi-modal-window:visible').count()) {
        await shot(s, p, 'hr-admin/50-hire-06-review', { ...D, screen: 'Hire employee › Review before creating',
            action: 'Create', expected: 'A review of what will be created before it is saved', element: '.fi-modal-window:visible >> nth=-1' });
        await modal(p).getByRole('button', { name: /Create|Confirm|Hire/ }).last().click();
        await idle(p, 3000);
    }
    await p.setViewportSize({ width: 1440, height: 900 });
    await settle(p);
    await shot(s, p, 'hr-admin/50-hire-07-created-record', { ...D, deck: [3, 4, 9, 11], screen: 'Employee 360 (new hire)',
        action: 'Create', expected: 'The new Employee 360 opens: code generated, status, manager; the hire is audited',
        callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Created' }, { loc: p.getByText('Tanvi Joshi'), n: 2, label: 'New record' }, { loc: p.getByText('Reports to'), n: 3, label: 'Manager' }] });
    console.log('    new record URL', p.url());
    await p.close();
}

if (want('change') || want('transfer')) {
    const D = { deck: [3, 11], workflow: 'Transfer / promote (guided change)', prereq: 'employee.position (Tenant HR Admin, HR Manager)' };
    const p = await open(s, 'admin', `/admin/employees/${TANVI}`);
    await p.setViewportSize({ width: 1440, height: 1100 });
    await actionMenu(p, 'Transfer / promote');
    await byId(p, 'change_type').selectOption({ label: 'Transfer' });
    await idle(p, 600);
    await shot(s, p, 'hr-admin/50-chg-01-transfer-context', { ...D, screen: 'Employee 360 › Action › Transfer or promote › 01 Context',
        action: 'Choose the kind of change and the effective date', expected: 'Three guided steps: Context, Change, Review',
        element: '.fi-modal-window:visible >> nth=-1',
        callouts: [{ loc: byId(p, 'change_type'), n: 1, label: 'Kind of change' }, { loc: byId(p, 'effective_from'), n: 2, label: 'Effective from' }, { loc: modal(p).getByRole('button', { name: 'Next' }), n: 3, label: 'Next' }] });
    await modal(p).getByRole('button', { name: 'Next' }).click(); await idle(p, 1200);
    await pick(p, 'mountedActionSchema0.team_id', 'Mobile', modal(p));
    await idle(p, 600);
    await shot(s, p, 'hr-admin/50-chg-02-transfer-change', { ...D, screen: 'Transfer or promote › 02 Change',
        action: 'Change only what is different: team Platform → Mobile', expected: 'Only the changed field is edited',
        element: '.fi-modal-window:visible >> nth=-1', callouts: [{ loc: modal(p).locator('[id="mountedActionSchema0.team_id"]'), n: 1, label: 'New team' }] });
    await modal(p).getByRole('button', { name: 'Next' }).click(); await idle(p, 1200);
    await byId(p, 'audit_reason').fill('Moves to the Mobile team for the app launch (training example)');
    await idle(p, 600);
    await shot(s, p, 'hr-admin/50-chg-03-transfer-review', { ...D, deck: [3, 8, 11], screen: 'Transfer or promote › 03 Review',
        action: 'Review Before → After and give a reason', expected: 'Before → After comparison; the reason is stored in the audit trail',
        element: '.fi-modal-window:visible >> nth=-1',
        callouts: [{ loc: modal(p).getByText('Before', { exact: false }), n: 1, label: 'Before → After' }, { loc: byId(p, 'audit_reason'), n: 2, label: 'Reason (audited)' }, { loc: modal(p).getByRole('button', { name: /Confirm/ }), n: 3, label: 'Confirm' }] });
    await closeToasts(p);
    await modal(p).getByRole('button', { name: /Confirm/ }).last().click(); await idle(p, 2500);
    console.log('    transfer toast', (await toasts(p)).slice(0, 1));
    await p.setViewportSize({ width: 1440, height: 900 });
    await shot(s, p, 'hr-admin/50-chg-04-transfer-refused-same-day', { ...D, deck: [3, 7, 11], screen: 'Employee 360 (after Confirm change)',
        action: 'Confirm a transfer effective on the same day the current position started', expected: 'Refused: "Not allowed — The new position must start after the current one (2026-10-21)." Nothing is changed.',
        note: 'Effective-dated rule: a new assignment must start after the current one. Retried with 22 Oct 2026 (next shot).', callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Refused' }] });
    await p.close();
}
if (want('change') || want('transfer2')) {
    const D = { deck: [3, 11], workflow: 'Transfer / promote (guided change)', prereq: 'employee.position (Tenant HR Admin, HR Manager)' };
    const p = await open(s, 'admin', `/admin/employees/${TANVI}`);
    await p.setViewportSize({ width: 1440, height: 1100 });
    await actionMenu(p, 'Transfer / promote');
    await byId(p, 'change_type').selectOption({ label: 'Transfer' });
    await byId(p, 'effective_from').fill('2026-10-22');
    await idle(p, 600);
    await modal(p).getByRole('button', { name: 'Next' }).click(); await idle(p, 1200);
    await pick(p, 'mountedActionSchema0.team_id', 'Mobile', modal(p));
    await modal(p).getByRole('button', { name: 'Next' }).click(); await idle(p, 1200);
    await byId(p, 'audit_reason').fill('Moves to the Mobile team for the app launch (training example)');
    await closeToasts(p);
    await modal(p).getByRole('button', { name: /Confirm/ }).last().click(); await idle(p, 2500);
    console.log('    transfer2 toast', (await toasts(p)).slice(0, 1));
    await p.setViewportSize({ width: 1440, height: 900 });
    await shot(s, p, 'hr-admin/50-chg-04b-transfer-done', { ...D, screen: 'Employee 360 (after the transfer)',
        action: 'Confirm change, effective 22 Oct 2026', expected: 'Confirmation; the change is scheduled for its effective date and appears on the Journey',
        callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Recorded' }] });
    await p.close();
}
if (want('change') || want('manager')) {
    const p = await open(s, 'admin', `/admin/employees/${TANVI}`);
    const D2 = { deck: [3, 4, 11], workflow: 'Change manager (reporting relationship)', prereq: 'employee.position (Tenant HR Admin, HR Manager)' };
    await p.setViewportSize({ width: 1440, height: 1100 });
    await actionMenu(p, 'Change manager');
    await byId(p, 'type').selectOption({ label: 'Line manager' });
    await idle(p, 600);
    await shot(s, p, 'hr-admin/50-chg-05-manager-context', { ...D2, screen: 'Employee 360 › Action › Change manager › 01 Context',
        action: 'Choose which relationship changes (line, functional, dotted line, HR business partner, mentor …)', expected: 'Relationship type chosen',
        note: 'Change manager is partly hidden under the sticky section bar when the Action menu opens (F27); the menu item still works.',
        element: '.fi-modal-window:visible >> nth=-1', callouts: [{ loc: byId(p, 'type'), n: 1, label: 'Relationship' }] });
    await modal(p).getByRole('button', { name: 'Next' }).click(); await idle(p, 1200);
    const mgrField = modal(p).locator('button[id^="mountedActionSchema0."]:not([id*="::"])').filter({ visible: true }).first();
    const mgrId = await mgrField.getAttribute('id');
    console.log('    manager field', mgrId);
    await pick(p, mgrId, 'Ravi Kumar', modal(p));
    await shot(s, p, 'hr-admin/50-chg-06-manager-change', { ...D2, screen: 'Change manager › 02 Change',
        action: 'Choose the new manager and the date', expected: 'New line manager Ravi Kumar',
        element: '.fi-modal-window:visible >> nth=-1', callouts: [{ loc: modal(p).locator(`[id="${mgrId}"]`), n: 1, label: 'New manager' }] });
    await modal(p).getByRole('button', { name: 'Next' }).click(); await idle(p, 1200);
    await byId(p, 'audit_reason').fill('Reports to the Mobile team lead after the transfer (training example)');
    await shot(s, p, 'hr-admin/50-chg-07-manager-review', { ...D2, screen: 'Change manager › 03 Review',
        action: 'Review Before → After and give a reason', expected: 'Amit Verma → Ravi Kumar, with the reason',
        element: '.fi-modal-window:visible >> nth=-1', callouts: [{ loc: byId(p, 'audit_reason'), n: 1, label: 'Reason (audited)' }, { loc: modal(p).getByRole('button', { name: /Confirm/ }), n: 2, label: 'Confirm' }] });
    await modal(p).getByRole('button', { name: /Confirm/ }).last().click(); await idle(p, 2500);
    console.log('    manager toast', (await toasts(p)).slice(0, 1));
    await p.setViewportSize({ width: 1440, height: 900 });
    await p.reload(); await settle(p);
    await shot(s, p, 'hr-admin/50-chg-08-manager-done', { ...D2, screen: 'Employee 360 (after the change)',
        action: 'Confirm reporting change', expected: 'Header: Reports to Ravi Kumar; the change is on the Journey and in History',
        callouts: [{ loc: p.getByText('Reports to'), n: 1, label: 'New manager' }] });
    await p.close();
}
if (want('change') || want('lifecycle')) {
    const p = await open(s, 'admin', `/admin/employees/${TANVI}`);
    const D3 = { deck: [3], workflow: 'Change lifecycle state', prereq: 'employee.lifecycle (Tenant HR Admin, HR Manager)' };
    await p.setViewportSize({ width: 1440, height: 1000 });
    await actionMenu(p, 'Change lifecycle state');
    await byId(p, 'to_state').selectOption({ label: 'Confirmed' });
    await byId(p, 'audit_reason').fill('Probation review completed (training example)');
    await idle(p, 600);
    await shot(s, p, 'hr-admin/50-chg-09-lifecycle-modal', { ...D3, screen: 'Employee 360 › Action › Change lifecycle state',
        action: 'Choose the new state (only allowed next states are offered), the effective date and a reason', expected: 'Opened and filled; cancelled in this demonstration',
        element: '.fi-modal-window:visible >> nth=-1',
        callouts: [{ loc: byId(p, 'to_state'), n: 1, label: 'Allowed next states' }, { loc: byId(p, 'effective_date'), n: 2, label: 'Effective date' }, { loc: byId(p, 'audit_reason'), n: 3, label: 'Reason (required)' }] });
    await cancelModal(p);
    await p.close();
}

/** Records › Employment: scroll until the onboarding plan section has loaded (it loads lazily). */
async function openOnboardingSection(page) {
    await page.getByRole('link', { name: 'Records', exact: true }).first().click(); await idle(page, 2000);
    await page.getByRole('tab', { name: 'Employment', exact: true }).click(); await idle(page, 2000);
    const head = page.getByText(/Standard onboarding · \d+% complete/).first();
    for (let i = 0; i < 12 && !(await head.isVisible().catch(() => false)); i++) { await page.mouse.wheel(0, 500); await idle(page, 900); }
    await head.evaluate((x) => { x.scrollIntoView({ block: 'start' }); window.scrollBy(0, -140); });
    await idle(page, 900);
    return page.locator('.fi-ta').filter({ has: head }).first();
}

async function onboardingTemplate(p, D) {
    await go(p, '/admin/onboarding-templates');
    await p.getByRole('link', { name: 'Edit' }).first().click(); await idle(p, 2000);
    await shot(s, p, 'hr-admin/50-onb-02-template', { ...D, deck: [3, 4], workflow: 'Onboarding template', screen: 'People › Lifecycle › Onboarding templates › Edit Standard onboarding',
        action: 'Open the template', expected: 'Name, key, description, priority (lower wins), status, Applies to conditions, reason for change; Checklist items and History tabs below',
        note: 'The Checklist items list below raised a lazy-loading error in this non-production environment (see support/50-onb-err-01).',
        callouts: [{ loc: p.getByText('Priority', { exact: true }), n: 1, label: 'Priority' }, { loc: p.getByText('APPLIES TO', { exact: false }), n: 2, label: 'Applies to' }, { loc: p.getByRole('tab', { name: 'Checklist items' }), n: 3, label: 'Checklist items' }] });
    for (let i = 0; i < 6; i++) { await p.mouse.wheel(0, 400); await idle(p, 800); }
    if (await p.locator('#livewire-error').count()) {
        await p.evaluate(() => { const f = document.querySelector('#livewire-error iframe'); f?.contentWindow?.scrollTo(0, 0); });
        await p.waitForTimeout(500);
        await shot(s, p, 'support/50-onb-err-01-template-items-lazy-loading', { ...D, deck: [7], workflow: 'Onboarding template: checklist items fail to load (non-production)', screen: 'Onboarding templates › Edit › Checklist items',
            action: 'Scroll to the Checklist items list', status: 'INCOMPLETE',
            expected: 'Internal Server Error: LazyLoadingViolationException — "Attempted to lazy load [ownerRole] on model OnboardingTemplateItem but lazy loading is disabled."',
            note: 'Same class as F26: strict mode is on outside production (Model::shouldBeStrict), so production probably renders the list; unverified. Source: ItemsRelationManager.php:57.' });
        await p.evaluate(() => { const d = document.getElementById('livewire-error'); if (d) { try { d.close(); } catch {} d.remove(); } });
    }
}
if (want('onbtemplate')) { const p = await open(s, 'admin', '/admin'); await onboardingTemplate(p, { deck: [3, 4], prereq: 'onboarding.manage' }); await p.close(); }

if (want('onboarding')) {
    const D = { deck: [3, 4], workflow: 'Onboarding plan and tasks', prereq: 'onboarding.view / onboarding.manage; plans start automatically on hire (template conditions)' };
    const p = await open(s, 'admin', '/admin/onboarding-plans');
    await shot(s, p, 'hr-admin/50-onb-01-plans', { ...D, screen: 'People › Lifecycle › Onboarding',
        action: 'Open Onboarding', expected: 'Plans in progress with joining date, progress, open and overdue tasks; the new hire’s plan started automatically',
        callouts: [{ loc: p.locator('tbody tr', { hasText: 'Tanvi Joshi' }), n: 1, label: 'Auto-started plan' }, { loc: p.locator('th', { hasText: 'PROGRESS' }), n: 2, label: 'Progress' }] });
    await onboardingTemplate(p, D);
    await go(p, `/admin/employees/${TANVI}`);
    const tbl = await openOnboardingSection(p);
    await shot(s, p, 'hr-admin/50-onb-03-plan-tasks', { ...D, screen: 'Employee 360 › Records › Employment › Onboarding',
        action: 'Open the new hire’s onboarding plan', expected: 'Tasks by phase (Preboarding, Day 1, First week, 30 days), type, owner, due date, status; Done / Skip per task',
        note: 'Tasks assigned to the line manager at hire stay with that person after a manager change (Amit Verma here).',
        callouts: [{ loc: tbl.locator('tbody tr', { hasText: 'HR induction and ID card' }), n: 1, label: 'Task' }, { loc: tbl.locator('tbody tr', { hasText: 'HR induction' }).getByRole('button', { name: 'Done' }), n: 2, label: 'Done' },
            { loc: tbl.locator('tbody tr', { hasText: 'HR induction' }).getByRole('button', { name: 'Skip' }), n: 3, label: 'Skip (note required)' }] });
    await closeToasts(p);
    await tbl.locator('tbody tr', { hasText: 'HR induction and ID card' }).getByRole('button', { name: 'Done' }).click();
    await idle(p, 1500);
    if (await p.locator('.fi-modal-window:visible').count()) {
        const m = modal(p);
        const ta = m.locator('textarea');
        if (await ta.count()) await ta.first().fill('ID card issued at the Delhi office (training example)');
        await shot(s, p, 'hr-admin/50-onb-04-task-done-confirm', { ...D, screen: 'Onboarding task › Done', action: 'Done', expected: 'Confirm completion (a document is required for document tasks)', element: '.fi-modal-window:visible >> nth=-1' });
        await m.getByRole('button', { name: /Submit|Confirm|Done|Save/ }).last().click();
        await idle(p, 2000);
    }
    console.log('    onboarding done toast', (await toasts(p)).slice(0, 1));
    const tbl2 = await openOnboardingSection(p);
    await shot(s, p, 'hr-admin/50-onb-05-task-completed', { ...D, screen: 'Employee 360 › Records › Employment › Onboarding (after Done)',
        action: 'Mark the task done', expected: 'Task status done, who and when; plan progress moves up',
        callouts: [{ loc: tbl2.locator('tbody tr', { hasText: 'HR induction and ID card' }), n: 1, label: 'Completed task' }, { loc: p.getByText(/Standard onboarding · \d+% complete/), n: 2, label: 'Progress' }] });
    await p.close();
    const m = await open(s, 'manager', '/admin/task-inbox');
    await m.getByRole('button', { name: 'Onboarding tasks' }).first().click(); await idle(m, 1500);
    await shot(s, m, 'manager/50-onb-06-manager-onboarding-tasks', { ...D, deck: [2, 3], workflow: 'Onboarding tasks for the manager', screen: 'My work › Task inbox › Onboarding tasks',
        action: 'The manager opens Task inbox › Onboarding tasks', expected: 'Tasks the onboarding template assigned to the manager (meet the team, 30-day goals, check-in), with Done / Skip',
        callouts: [{ loc: m.getByRole('button', { name: 'Onboarding tasks' }), n: 1, label: 'Onboarding tasks' }, { loc: m.locator('main tbody tr', { hasText: 'Tanvi' }), n: 2, label: 'Task for the new hire' }] });
    await m.close();
}

/** Employee 360 › Records › Operations (Documents section). */
async function openOperations(page) {
    await page.getByRole('link', { name: 'Records', exact: true }).first().click(); await idle(page, 2000);
    const t = page.getByRole('tab', { name: 'Operations', exact: true });
    await t.click(); await idle(page, 2500);
    await t.evaluate((x) => { x.scrollIntoView({ block: 'start' }); window.scrollBy(0, -110); }); await idle(page, 800);
    return page.locator('.fi-ta').filter({ has: page.getByText('Documents', { exact: true }) }).first();
}

if (want('docs')) {
    const D = { deck: [3, 8], workflow: 'Employee documents: upload and verify (two people)', prereq: 'document.upload (Tenant HR Admin, HR Manager, HR Executive); document.verify (Tenant HR Admin, HR Manager); the uploader cannot verify' };
    const p = await open(s, 'admin', `/admin/employees/${TANVI}`);
    await p.setViewportSize({ width: 1440, height: 1100 });
    await openOperations(p);
    await p.getByRole('button', { name: 'Upload' }).first().click(); await idle(p, 1500);
    const m = modal(p);
    await m.locator('[id="mountedActionSchema0.document_type_id"]').click(); await idle(p, 600);
    const opts = await p.locator('.fi-select-input-option:visible, [role=option]:visible').allInnerTexts();
    console.log('    document types', opts.map((x) => x.trim()).join(' / '));
    const want_ = opts.find((o) => /degree|education|certificate/i.test(o)) ?? opts[0];
    await p.locator('.fi-select-input-option:visible, [role=option]:visible').filter({ hasText: want_.trim() }).first().click(); await idle(p, 800);
    await byId(p, 'title').fill('Degree certificate (training example)');
    await m.locator('input[type=file]').setInputFiles(new URL('../fixtures/training-example-certificate.pdf', import.meta.url).pathname);
    await idle(p, 3000);
    await byId(p, 'audit_reason').fill('Collected at joining (training example)');
    await idle(p, 800);
    await shot(s, p, 'hr-admin/50-doc-01-upload-form', { ...D, screen: 'Employee 360 › Records › Operations › Documents › Upload',
        action: 'Upload a document for the employee: type, title, file (PDF/JPEG/PNG/Word, max 10 MB), dates, reason', expected: 'File attached; Submit saves it as Pending verification',
        element: '.fi-modal-window:visible >> nth=-1',
        callouts: [{ loc: m.locator('[id="mountedActionSchema0.document_type_id"]'), n: 1, label: 'Document type' }, { loc: byId(p, 'title'), n: 2, label: 'Title' },
            { loc: m.locator('.filepond--root'), n: 3, label: 'File' }, { loc: byId(p, 'audit_reason'), n: 4, label: 'Reason' }] });
    await closeToasts(p);
    await m.getByRole('button', { name: 'Submit', exact: true }).click(); await idle(p, 2500);
    console.log('    upload toast', (await toasts(p)).slice(0, 1));
    const tbl = await openOperations(p);
    const row = tbl.locator('tbody tr', { hasText: 'Degree certificate' });
    await shot(s, p, 'hr-admin/50-doc-02-uploaded-pending', { ...D, screen: 'Employee 360 › Records › Operations › Documents',
        action: 'Submit', expected: 'The document is listed as Pending verification with Download, Verify and Reject',
        callouts: [{ loc: row, n: 1, label: 'Uploaded document' }, { loc: row.locator('.fi-badge'), n: 2, label: 'Pending verification' }, { loc: row.getByRole('button', { name: 'Verify' }), n: 3, label: 'Verify' }] });
    // The uploader tries to verify their own upload.
    await closeToasts(p);
    await row.getByRole('button', { name: 'Verify' }).click(); await idle(p, 1500);
    if (await p.locator('.fi-modal-window:visible').count()) { await modal(p).getByRole('button', { name: /Submit|Confirm|Verify/ }).last().click(); await idle(p, 2000); }
    await p.evaluate(() => { const f = document.querySelector('#livewire-error iframe'); f?.contentWindow?.scrollTo(0, 0); });
    const t1 = await toasts(p);
    console.log('    self-verify toast', t1.slice(0, 1));
    await shot(s, p, 'security-compliance/50-doc-03-uploader-cannot-verify', { ...D, deck: [3, 8, 7], workflow: 'Separation of duties: document verification', screen: 'Employee 360 › Records › Operations › Documents',
        action: 'The person who uploaded the document tries to verify it', expected: 'Refused, but as an unhandled error: Internal Server Error — RuntimeException "The person who uploaded a document cannot verify it." (local debug page; production shows a generic server-error dialog). Nothing is changed.',
        note: 'Separation of duties works (Documents::review); the refusal is not caught and shown as a message, so users see a server error. Not in documentation-gaps F1–F46.' });
    await p.evaluate(() => { const d = document.getElementById('livewire-error'); if (d) { try { d.close(); } catch {} d.remove(); } });
    await p.close();
    // A second HR person verifies it.
    const h = await open(s, 'hr', `/admin/employees/${TANVI}`);
    await h.setViewportSize({ width: 1440, height: 1100 });
    const tbl2 = await openOperations(h);
    const row2 = tbl2.locator('tbody tr', { hasText: 'Degree certificate' });
    await row2.getByRole('button', { name: 'Verify' }).click(); await idle(h, 1500);
    if (await h.locator('.fi-modal-window:visible').count()) {
        const ta = modal(h).locator('textarea'); if (await ta.count()) await ta.first().fill('Checked against the original (training example)');
        await shot(s, h, 'hr-admin/50-doc-04-verify-confirm', { ...D, screen: 'Documents › Verify (second HR person)', action: 'A different HR person verifies', expected: 'Verify dialog', element: '.fi-modal-window:visible >> nth=-1' });
        await modal(h).getByRole('button', { name: /Submit|Confirm|Verify/ }).last().click(); await idle(h, 2000);
    }
    console.log('    verify toast', (await toasts(h)).slice(0, 1));
    const tbl3 = await openOperations(h);
    await shot(s, h, 'hr-admin/50-doc-05-verified', { ...D, screen: 'Employee 360 › Records › Operations › Documents (after Verify)',
        action: 'Verify', expected: 'Status Verified, with who verified it', callouts: [{ loc: tbl3.locator('tbody tr', { hasText: 'Degree certificate' }), n: 1, label: 'Verified' }] });
    await h.close();
}

if (want('audit') || want('auditdetail')) {
    const D = { deck: [3, 8, 12], workflow: 'Change history (audit trail)', prereq: 'audit.view (Tenant HR Admin, HR Manager, Payroll Admin, Auditor, Account Owner)' };
    const p = await open(s, 'admin', '/admin/audit-events');
    await shot(s, p, 'hr-admin/50-aud-01-change-history', { ...D, screen: 'Insights › Audit › Change history (Audit events)', action: 'Open Change history',
        expected: 'Every audited event: when, action, module, record, who, reason; newest first; filters',
        callouts: [{ loc: p.locator('th', { hasText: 'WHEN' }), n: 1, label: 'When' }, { loc: p.locator('tbody tr').first(), n: 2, label: 'Event' }, { loc: p.locator('.fi-ta-filters-trigger-action-ctn, button[title="Filter"]').first(), n: 3, label: 'Filters' }] });
    // Find the reporting-line change made in the 'manager' group and open it.
    await p.getByPlaceholder('Search audit events').fill('EMP00023'); await idle(p, 2500);
    if (await p.locator('#livewire-error').count()) { console.log('    audit search error'); await p.evaluate(() => { const d = document.getElementById('livewire-error'); if (d) { try { d.close(); } catch {} d.remove(); } }); }
    for (let pg = 0; pg < 6; pg++) {
        const row = p.locator('tbody tr', { hasText: /Transferred/ }).first();
        if (await row.count()) {
            await row.getByRole('link', { name: 'View' }).click(); await idle(p, 2000);
            await shot(s, p, 'hr-admin/50-aud-02-event-detail', { ...D, screen: 'Change history › event', action: 'Open one event: the transfer of Tanvi Joshi',
                expected: 'What happened (action Transferred, module, record, reason, effective date 22 Oct 2026), Field changes (team Platform → Mobile), Who and where, Integrity (tamper-evident hash chain)' });
            break;
        }
        const next = p.getByRole('button', { name: 'Next' });
        if (!await next.count()) break;
        await next.first().click(); await idle(p, 1500);
    }
    await go(p, `/admin/employees/${TANVI}`);
    await p.getByRole('button', { name: 'More', exact: true }).click(); await idle(p, 700);
    await p.locator('.fi-dropdown-panel').getByText('All changes', { exact: true }).evaluate((e) => e.closest('button,a').click());
    await idle(p, 2500);
    await shot(s, p, 'hr-admin/50-aud-03-all-changes-person', { ...D, screen: 'Employee 360 › More › All changes (opens Change intelligence)', action: 'Open All changes for one person',
        expected: 'Change intelligence filtered to this person: hire, documents, transfer, reporting change, onboarding task …, each with who, why and Explain; sensitive values are masked unless you may see them' });
    await p.close();
}

if (want('fieldsec')) {
    const D = { deck: [8, 3], workflow: 'Field-level protection on the Employee 360', prereq: 'employee.bank / sensitive-field permissions differ by role' };
    for (const [persona, file, who] of [['admin', 'security-compliance/50-sec-01-360-records-tenant-hr-admin', 'Tenant HR Admin (Kavya)'], ['hr', 'security-compliance/50-sec-02-360-records-hr-manager', 'HR Manager (Neha)']]) {
        const p = await open(s, persona, `/admin/employees/${PRIYA}`);
        await p.getByRole('link', { name: 'Records', exact: true }).first().click(); await idle(p, 2000);
        const tl = p.getByRole('tablist').last();
        await tl.evaluate((x) => { x.scrollIntoView({ block: 'start' }); window.scrollBy(0, -110); }); await idle(p, 600);
        const tabs = (await tl.getByRole('tab').allInnerTexts()).map((x) => x.trim());
        console.log('   ', persona, tabs.join(' | '));
        await shot(s, p, file, { ...D, screen: `Employee 360 › Records (as ${who})`, action: `Open the same person’s Records as ${who}`,
            expected: `Record tabs offered: ${tabs.join(', ')}`, callouts: [{ loc: tl, n: 1, label: 'Record tabs for this role' }] });
        await p.close();
    }
}

if (want('transferview')) {
    // Re-records the Transfer dialog steps (opened, filled, cancelled) for the inventory; the executed transfer is shot 04b.
    const D = { deck: [3, 11], workflow: 'Transfer / promote (guided change)', prereq: 'employee.position (Tenant HR Admin, HR Manager)' };
    const p = await open(s, 'admin', `/admin/employees/${TANVI}`);
    await p.setViewportSize({ width: 1440, height: 1100 });
    await actionMenu(p, 'Transfer / promote');
    await byId(p, 'change_type').selectOption({ label: 'Transfer' });
    await idle(p, 600);
    await shot(s, p, 'hr-admin/50-chg-01-transfer-context', { ...D, screen: 'Employee 360 › Action › Transfer or promote › 01 Context',
        action: 'Choose the kind of change and the effective date', expected: 'Today’s position is shown; three guided steps: Context, Change, Review',
        element: '.fi-modal-window:visible >> nth=-1',
        callouts: [{ loc: byId(p, 'change_type'), n: 1, label: 'Kind of change' }, { loc: byId(p, 'effective_from'), n: 2, label: 'Effective from' }, { loc: modal(p).getByRole('button', { name: 'Next' }), n: 3, label: 'Next' }] });
    await modal(p).getByRole('button', { name: 'Next' }).click(); await idle(p, 1200);
    await pick(p, 'mountedActionSchema0.team_id', 'Mobile', modal(p));
    await shot(s, p, 'hr-admin/50-chg-02-transfer-change', { ...D, screen: 'Transfer or promote › 02 Change',
        action: 'Change only what is different: team Platform → Mobile', expected: 'Only the changed field is edited',
        element: '.fi-modal-window:visible >> nth=-1', callouts: [{ loc: modal(p).locator('[id="mountedActionSchema0.team_id"]'), n: 1, label: 'New team' }] });
    await modal(p).getByRole('button', { name: 'Next' }).click(); await idle(p, 1200);
    await byId(p, 'audit_reason').fill('Moves to the Mobile team for the app launch (training example)');
    await idle(p, 600);
    await shot(s, p, 'hr-admin/50-chg-03-transfer-review', { ...D, deck: [3, 8, 11], screen: 'Transfer or promote › 03 Review',
        action: 'Review Before → After and give a reason', expected: 'Before → After comparison and the effective date; the reason is stored in the audit trail',
        element: '.fi-modal-window:visible >> nth=-1',
        callouts: [{ loc: modal(p).getByText('Before', { exact: false }), n: 1, label: 'Before → After' }, { loc: byId(p, 'audit_reason'), n: 2, label: 'Reason (audited)' }, { loc: modal(p).getByRole('button', { name: /Confirm/ }), n: 3, label: 'Confirm' }] });
    await cancelModal(p);
    await p.close();
}

await finish(s);
