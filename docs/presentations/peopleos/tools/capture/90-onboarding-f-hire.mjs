// Deck 4 stage 5: hire the first employees with the Hire employee wizard (Person → Employment → Position → Manager → Review),
// including the duplicate-person check. EXECUTED on the disposable training database (Northwind Retail). Re-runnable.
import { start, open, go, shot, finish, idle, toasts, closeToasts, setForm, dump } from './lib.mjs';
import './d-accounts.mjs';

const s = await start('90-onboarding-f-hire');
const D = { deck: [4, 3], workflow: 'Employee data: hire with the wizard', prereq: 'Organisation, designations and levels configured; employee.create permission' };
const pg = await open(s, 'nwadmin', '/admin/employees');
const field = (label) => pg.locator('main label').filter({ hasText: new RegExp(`^\\s*${label.replace(/[()&]/g, '\\$&')}\\s*\\*?\\s*$`) }).first().locator('xpath=ancestor::div[.//button][1]');
async function pick(label, text, search = text) {
    await field(label).locator('button').first().click();
    await idle(pg, 500);
    await pg.keyboard.type(search.slice(0, 10), { delay: 30 });
    await idle(pg, 1200);
    await pg.locator('[role=option]:visible, .fi-select-input-option:visible').filter({ hasText: text }).first().click();
    await idle(pg, 700);
}
const next = async () => { await pg.getByRole('button', { name: 'Next' }).click(); await idle(pg, 1500); };

async function hire(p, shots = {}) {
    await go(pg, '/admin/employees');
    if (!p.duplicate && await pg.locator('main').getByText(`${p.first} ${p.last}`).count()) { console.log('    exists', p.first); return; }
    await go(pg, '/admin/employees/create');
    await pg.setViewportSize({ width: 1440, height: 1250 });
    await pg.locator('[id="form.first_name"]').fill(p.first);
    await pg.locator('[id="form.last_name"]').fill(p.last);
    await setForm(pg, 'date_of_birth', p.dob);
    await pg.locator('[id="form.personal_email"]').fill(p.personal);
    await idle(pg, 1200);
    if (shots.person) await shot(s, pg, shots.person, { ...D, screen: 'Hire employee › 01 Person', action: 'Name, date of birth, personal details and personal e-mail',
        expected: 'The person is checked against existing people before you continue', callouts: [{ loc: pg.getByRole('button', { name: /Person Who is joining/ }), n: 1, label: 'Step 1 of 4' }, { loc: pg.locator('[id="form.first_name"]'), n: 2, label: 'Name' }, { loc: pg.locator('[id="form.personal_email"]'), n: 3, label: 'Personal e-mail' }] });
    await next();
    if (p.duplicate) {
        await pg.evaluate(() => window.scrollTo(0, 0));
        if (shots.dup) await shot(s, pg, shots.dup, { ...D, deck: [4, 3, 7], workflow: 'Person matching and duplicate prevention', screen: 'Hire employee › 01 Person (duplicate check)',
            action: 'Enter a person who already exists (same name, date of birth and personal e-mail) and press Next', expected: 'PeopleOS matches the existing person and stops or warns before a duplicate is created',
            callouts: [{ sel: '.fi-no-notification', n: 1, label: 'This person already exists' }, { loc: field('Existing person (re-use instead of creating a new one)'), n: 2, label: 'Re-use the existing person' }] });
        await dump(pg, 'main', 30);
        return;
    }
    await setForm(pg, 'joining_date', p.joining);
    await pg.locator('[id="form.work_email"]').fill(p.work);
    await idle(pg, 800);
    if (shots.employment) await shot(s, pg, shots.employment, { ...D, screen: 'Hire employee › 02 Employment', action: 'Joining date, probation end, employee code, work e-mail and phone',
        expected: 'Employee code is generated when left blank', callouts: [{ loc: pg.locator('[id="form.joining_date"]'), n: 1, label: 'Joining date' }, { loc: pg.locator('[id="form.employee_code"]'), n: 2, label: 'Employee code' }, { loc: pg.locator('[id="form.work_email"]'), n: 3, label: 'Work e-mail' }] });
    await next();
    await pick('Company', 'Northwind Retail', 'Northwind');
    await pick('Location', 'Bengaluru HQ', 'Bengaluru');
    await pick('Business unit', 'Retail Operations', 'Retail');
    await pick('Department', p.dept, p.dept.slice(0, 6));
    if (p.team) await pick('Team', p.team, p.team.slice(0, 6));
    await pick('Designation', p.designation, p.designation.slice(0, 6));
    await pick('Level', p.level, p.level);
    await pick('Employment type', 'Full-time', 'Full');
    await pick('Employee category', 'Permanent', 'Perm');
    await pick('Work mode', 'Office', 'Office');
    if (shots.position) await shot(s, pg, shots.position, { ...D, screen: 'Hire employee › 03 Position', action: 'Company, location, business unit, department, team, designation, level, employment type, category, work mode',
        expected: 'Only Company is required; the rest place the person in the structure built earlier', callouts: [{ loc: field('Company'), n: 1, label: 'Company' }, { loc: field('Department'), n: 2, label: 'Department' }, { loc: field('Designation'), n: 3, label: 'Designation' }, { loc: field('Employment type'), n: 4, label: 'Employment type' }] });
    await next();
    if (shots.probe) await dump(pg, 'main', 50);
    if (p.manager) await pick('Line manager', p.manager, p.manager.split(' ')[0]).catch((e) => console.log('    manager pick failed', String(e).slice(0, 120)));
    const reason = pg.locator('main textarea').first();
    if (await reason.count()) await reason.fill(`New hire, offer ${p.offer} accepted (training example)`);
    await idle(pg, 800);
    if (shots.manager) await shot(s, pg, shots.manager, { ...D, screen: 'Hire employee › 04 Manager', action: p.manager ? `Reporting line: ${p.manager}; reason for the hire` : 'Reporting line (none for the first manager) and the reason',
        expected: 'The reason is recorded in the audit trail', callouts: [{ loc: field('Line manager'), n: 1, label: 'Line manager' }, { loc: reason, n: 2, label: 'Reason for change' }, { loc: pg.getByRole('button', { name: /Create employee/ }), n: 3, label: 'Create employee' }] });
    if (process.env.PROBE) { return; }
    await pg.getByRole('button', { name: /Create employee/ }).first().click();
    await idle(pg, 2500);
    const review = pg.locator('.fi-modal-window:visible');
    if (await review.count()) {
        if (shots.review) await shot(s, pg, shots.review, { ...D, screen: 'Hire employee › Review before creating', action: 'Create employee opens a review of every value',
            expected: 'Nothing is saved until the review is confirmed', element: '.fi-modal-window:visible >> nth=-1', callouts: [] });
        await review.last().getByRole('button').filter({ hasText: /Create|Confirm|Submit/ }).last().click();
        await idle(pg, 3500);
    }
    console.log('    hired', p.first, pg.url(), (await toasts(pg)).join(' / '));
    await pg.setViewportSize({ width: 1440, height: 900 });
    if (shots.created) await shot(s, pg, shots.created, { ...D, screen: 'Employee record (after Create employee)', action: 'Create employee', expected: 'The new Employee 360 record opens: status, one lifetime record, reports to',
        callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Confirmation' }, { loc: pg.getByRole('heading').first(), n: 2, label: 'Employee' }] });
    await closeToasts(pg);
}

const VIVEK = { first: 'Vivek', last: 'Rao', dob: '1988-03-14', personal: 'vivek.rao.home@example.test', joining: '2026-11-02', work: 'vivek.rao@northwind.example', dept: 'Store Operations', designation: 'Store Manager', level: 'L4', offer: 'NW-OF-001' };
const ISHITA = { first: 'Ishita', last: 'Sen', dob: '1999-07-21', personal: 'ishita.sen.home@example.test', joining: '2026-11-09', work: 'ishita.sen@northwind.example', dept: 'Store Operations', team: 'Store Excellence', designation: 'Retail Associate', level: 'L2', manager: 'Vivek Rao', offer: 'NW-OF-002' };
const ROHIT = { first: 'Rohit', last: 'Menon', dob: '1994-01-05', personal: 'rohit.menon.home@example.test', joining: '2026-11-02', work: 'rohit.menon@northwind.example', dept: 'People & Culture', designation: 'HR Executive', level: 'L3', offer: 'NW-OF-003' };

await hire(VIVEK, { person: 'client-onboarding/90-onb-38-hire-person', employment: 'client-onboarding/90-onb-39-hire-employment', position: 'client-onboarding/90-onb-40-hire-position',
    manager: 'client-onboarding/90-onb-41-hire-manager', review: 'client-onboarding/90-onb-42-hire-review', created: 'client-onboarding/90-onb-43-hire-created', probe: !!process.env.PROBE });
if (!process.env.FIRST_ONLY) {
    await hire(ISHITA, { manager: 'client-onboarding/90-onb-44-hire-with-manager' });
    await hire(ROHIT);
    await hire({ ...ISHITA, duplicate: true }, { dup: 'client-onboarding/90-onb-45-duplicate-person' });
}
await finish(s);
