// Deck 6: Markedge as its own employer. Markedge's internal HR runs as an ordinary tenant ("Markedge Technologies
// (internal workforce)"), provisioned by the platform operator like any customer and kept apart from the operator console.
// EXECUTED on the disposable training database with fictional people. Re-runnable.
import { start, open, context, go, shot, finish, idle, settle, toasts, closeToasts, modal, setData, setForm, dismissWelcome } from './lib.mjs';
import { accountPassword, activated, markActivated } from './d-accounts.mjs';
import { latestLink } from './d-mail.mjs';
import { chooseSearchable, fillForm } from './d-ui.mjs';

const s = await start('96-internal');
const D = { deck: [6], prereq: 'Platform operator provisions the internal tenant; Markedge HR administers it' };
const ADMIN = 'sana.qureshi@markedge-internal.example';

// 1. Operator provisions the internal tenant.
const op = await open(s, 'operator', '/admin/tenants');
if (!await op.locator('tr', { hasText: 'Markedge Technologies' }).count()) {
    await go(op, '/admin/tenants/create');
    await op.setViewportSize({ width: 1440, height: 1400 });
    await op.locator('[id="form.name"]').fill('Markedge Technologies (internal workforce)');
    await idle(op, 1500);
    await op.locator('[id="form.slug"]').fill('markedge-internal');
    await op.locator('[id="form.admin_name"]').fill('Sana Qureshi');
    await op.locator('[id="form.admin_email"]').fill(ADMIN);
    await op.locator('[id="form.audit_reason"]').fill('Markedge’s own employee records move to PeopleOS as an internal tenant (training example)');
    await idle(op, 800);
    await op.evaluate(() => window.scrollTo(0, 0));
    await shot(s, op, 'internal-operations/96-int-01-provision-internal-tenant', { ...D, deck: [6, 4], workflow: 'Provision Markedge’s internal tenant (operator)', screen: 'Tenants › New tenant',
        action: 'The operator provisions the internal tenant exactly like a customer tenant, with Markedge HR’s administrator as first administrator',
        expected: 'Separate tenant: Markedge employee data never sits in the operator console', callouts: [{ loc: op.locator('[id="form.name"]'), n: 1, label: 'Internal tenant name' }, { loc: op.locator('[id="form.admin_email"]'), n: 2, label: 'Markedge HR administrator' }] });
    await op.getByRole('button', { name: 'Create tenant', exact: true }).click();
    await idle(op, 4000);
    console.log('    created ->', op.url(), (await toasts(op)).join(' / '));
    await op.setViewportSize({ width: 1440, height: 900 });
    await shot(s, op, 'internal-operations/96-int-02-tenant-created', { ...D, deck: [6, 4, 5], workflow: 'Provision Markedge’s internal tenant (operator)', screen: 'Tenant (after Create tenant)',
        action: 'Create tenant', expected: 'Tenant provisioned with system roles, default features and settings; invitation e-mail sent to the first administrator', callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Result' }] });
    await closeToasts(op);
}
await go(op, '/admin/tenants');
await shot(s, op, 'internal-operations/96-int-03-tenants-side-by-side', { ...D, deck: [6, 5], workflow: 'Separation of the operator console and Markedge’s HR', screen: 'Admin › Platform › Tenants',
    action: 'Operator view of all tenants', expected: 'Demo Group, Northwind Retail and Markedge Technologies (internal workforce) are peers: the internal tenant has no special access',
    callouts: [{ loc: op.locator('tr', { hasText: 'Markedge Technologies' }), n: 1, label: 'Markedge’s own tenant' }, { loc: op.locator('tr', { hasText: 'Northwind' }), n: 2, label: 'A customer tenant' }] });

// 2. Markedge HR's administrator activates the account.
if (!activated('me-sana')) {
    const link = latestLink(ADMIN, /\/admin\/invitation\//);
    if (link) {
        const g = await (await context(s, 'guest', { fresh: true })).newPage();
        await g.goto(link);
        await settle(g);
        const pw = accountPassword('me-sana');
        await g.locator('[id="form.password"]').fill(pw);
        await g.locator('[id="form.passwordConfirmation"]').fill(pw);
        await g.getByRole('button', { name: 'Activate my account' }).click();
        await idle(g, 3000);
        if (!/invitation/.test(g.url())) markActivated('me-sana');
        console.log('    sana activation ->', g.url().replace(/invitation\/[A-Za-z0-9]+/, 'invitation/[token]'));
    }
}

// 3. Minimal internal set-up: company, departments, designations, three employees.
const pg = await open(s, 'meadmin', '/admin');
await shot(s, pg, 'internal-operations/96-int-04-internal-home', { ...D, workflow: 'Markedge internal HR: first sign-in', screen: 'Home (Markedge internal tenant)',
    action: 'Markedge HR’s administrator signs in to the internal tenant', expected: 'The same tenant Home as any customer: administration lens, governance items', callouts: [] });
await dismissWelcome(pg);
const has = async (text) => (await pg.locator('main tbody').getByText(text, { exact: false }).count()) > 0;
await go(pg, '/admin/companies');
if (!await has('Markedge Technologies')) {
    await go(pg, '/admin/companies/create');
    await fillForm(pg, { name: 'Markedge Technologies', code: 'MKT', legal_name: 'Markedge Technologies Pvt Ltd (training example)', country_code: 'IN' });
    await setForm(pg, 'effective_from', '2026-04-01');
    await pg.locator('main button[type=submit]').first().click();
    await idle(pg, 2500);
    await closeToasts(pg);
}
for (const [path, name, code] of [['/admin/departments', 'Product Engineering', 'ENG'], ['/admin/departments', 'People Operations', 'PEOPLE']]) {
    await go(pg, path);
    if (await has(name)) continue;
    await go(pg, `${path}/create`);
    await chooseSearchable(pg, pg.locator('main'), 0, 'Markedge Technologies', 'Markedge');
    await fillForm(pg, { name, code });
    await setForm(pg, 'effective_from', '2026-04-01');
    await pg.locator('main button[type=submit]').first().click();
    await idle(pg, 2500);
    await closeToasts(pg);
}
for (const [name, code, level] of [['Engineering Manager', 'ENG_MGR', 'L5'], ['Software Engineer', 'SWE', 'L3'], ['People Partner', 'PEOPLE_PARTNER', 'L4']]) {
    await go(pg, '/admin/designations');
    if (await has(name)) continue;
    await go(pg, '/admin/designations/create');
    await fillForm(pg, { name, code });
    await chooseSearchable(pg, pg.locator('main'), 0, level, level);
    await setForm(pg, 'effective_from', '2026-04-01');
    await pg.locator('main button[type=submit]').first().click();
    await idle(pg, 2500);
    await closeToasts(pg);
}

const field = (label) => pg.locator('main label').filter({ hasText: new RegExp(`^\\s*${label}\\s*\\*?\\s*$`) }).first().locator('xpath=ancestor::div[.//button][1]');
async function pick(label, text, search = text) {
    await field(label).locator('button').first().click();
    await idle(pg, 500);
    await pg.keyboard.type(search.slice(0, 10), { delay: 30 });
    await idle(pg, 1200);
    await pg.locator('[role=option]:visible, .fi-select-input-option:visible').filter({ hasText: text }).first().click();
    await idle(pg, 700);
}
async function hire(p) {
    await go(pg, '/admin/employees');
    if (await pg.locator('main').getByText(`${p.first} ${p.last}`).count()) return false;
    await go(pg, '/admin/employees/create');
    await pg.locator('[id="form.first_name"]').fill(p.first);
    await pg.locator('[id="form.last_name"]').fill(p.last);
    await setForm(pg, 'date_of_birth', p.dob);
    await pg.locator('[id="form.personal_email"]').fill(p.personal);
    await pg.getByRole('button', { name: 'Next' }).click(); await idle(pg, 1500);
    await setForm(pg, 'joining_date', '2026-10-05');
    await pg.locator('[id="form.work_email"]').fill(p.work);
    await pg.getByRole('button', { name: 'Next' }).click(); await idle(pg, 1500);
    await pick('Company', 'Markedge Technologies', 'Markedge');
    await pick('Department', p.dept, p.dept.slice(0, 6));
    await pick('Designation', p.designation, p.designation.slice(0, 6));
    await pick('Employment type', 'Full-time', 'Full');
    await pick('Work mode', p.mode, p.mode);
    await pg.getByRole('button', { name: 'Next' }).click(); await idle(pg, 1500);
    if (p.manager) await pick('Line manager', p.manager, p.manager.split(' ')[0]).catch(() => console.log('    manager not offered for', p.first));
    await pg.locator('main textarea').first().fill(`Internal hire ${p.offer} (training example)`);
    await pg.getByRole('button', { name: /Create employee/ }).first().click();
    await idle(pg, 3500);
    console.log('    hired', p.first, (await toasts(pg)).join(' / '));
    await closeToasts(pg);
    // A joining date in the past puts the new hire straight into Probation (no Mark as joined needed).
    return true;
}
await hire({ first: 'Rahul', last: 'Desai', dob: '1985-09-12', personal: 'rahul.desai.home@example.test', work: 'rahul.desai@markedge-internal.example', dept: 'Product Engineering', designation: 'Engineering Manager', mode: 'Hybrid', offer: 'MK-001' });
await hire({ first: 'Aditya', last: 'Kulkarni', dob: '1997-02-03', personal: 'aditya.kulkarni.home@example.test', work: 'aditya.kulkarni@markedge-internal.example', dept: 'Product Engineering', designation: 'Software Engineer', mode: 'Remote', manager: 'Rahul Desai', offer: 'MK-002' });
await hire({ first: 'Nisha', last: 'Varghese', dob: '1991-11-30', personal: 'nisha.varghese.home@example.test', work: 'nisha.varghese@markedge-internal.example', dept: 'People Operations', designation: 'People Partner', mode: 'Office', offer: 'MK-003' });

const W = { ...D, workflow: 'Markedge internal HR: employee records' };
await go(pg, '/admin/people');
await shot(s, pg, 'internal-operations/96-int-05-internal-directory', { ...W, screen: 'People › Directory (Markedge internal tenant)', action: 'Markedge HR opens the Directory',
    expected: 'Only Markedge’s own (fictional) employees; no customer data is visible here', callouts: [] });
await go(pg, '/admin/organisation-map');
await shot(s, pg, 'internal-operations/96-int-06-internal-org-map', { ...W, screen: 'Organisation › Org map (Markedge internal tenant)', action: 'Open the Org map', expected: 'Reporting lines: Aditya Kulkarni → Rahul Desai', callouts: [] });
await go(pg, '/admin/employees');
await pg.locator('tr', { hasText: 'Aditya Kulkarni' }).getByRole('link').first().click();
await idle(pg, 2000);
await shot(s, pg, 'internal-operations/96-int-07-internal-employee-360', { ...W, screen: 'Employee 360 (Markedge internal tenant)', action: 'Open an internal employee record', expected: 'The same Employee 360 as customers use: status, reports to, journey, records', callouts: [] });
await go(pg, '/admin/tenants');
await shot(s, pg, 'internal-operations/96-int-08-hr-cannot-open-tenants', { ...W, deck: [6, 8], workflow: 'Separation of the operator console and Markedge’s HR', screen: 'Tenants (as Markedge HR administrator)',
    action: 'Markedge HR’s administrator opens the operator’s Tenants screen directly', expected: 'Refused: being Markedge staff in HR does not grant platform-operator access', callouts: [] });
await finish(s);
