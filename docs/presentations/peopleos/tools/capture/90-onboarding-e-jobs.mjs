// Deck 4 stage 4: job architecture (job family, grade, designations) and a first position in Northwind Retail.
// EXECUTED on the disposable training database. Re-runnable.
import { start, open, go, shot, finish, idle, toasts, closeToasts, modal, setData, setForm } from './lib.mjs';
import './d-accounts.mjs';
import { chooseSearchable, fillForm } from './d-ui.mjs';

const s = await start('90-onboarding-e-jobs');
const D = { deck: [4], workflow: 'Job architecture and positions', prereq: 'Organisation set up; signed in as the tenant administrator' };
const pg = await open(s, 'nwadmin', '/admin/employment-types');
const has = async (text) => (await pg.locator('main').getByText(text, { exact: false }).count()) > 0;
const byId = (id) => modal(pg).locator(`[id="mountedActionSchema0.${id}"]`);

await shot(s, pg, 'client-onboarding/90-onb-33-seeded-employment-types', { ...D, screen: 'Admin › Setup › Employment types',
    action: 'Review the starting values every new tenant receives', expected: 'Employment types (Full-time, Part-time, Contract, Intern …), employee categories, work modes and levels L1–L6 are seeded; designations, grades and job families start empty',
    callouts: [{ loc: pg.locator('tr', { hasText: 'Full-time' }), n: 1, label: 'Seeded value' }, { loc: pg.getByRole('link', { name: 'New employment type' }), n: 2, label: 'Add your own' }] });

const create = async (path, name, values, extra) => {
    await go(pg, path.replace('/create', ''));
    if (await has(name)) return false;
    await go(pg, path);
    await pg.setViewportSize({ width: 1440, height: 1100 });
    await fillForm(pg, values);
    if (extra) await extra();
    return true;
};
const submit = async (label) => {
    await pg.locator('main button[type=submit]').first().click();
    await idle(pg, 2500);
    console.log('   ', label, (await toasts(pg)).join(' / '));
    await pg.setViewportSize({ width: 1440, height: 900 });
    await closeToasts(pg);
};

if (await create('/admin/job-families/create', 'Retail Operations family', { name: 'Retail Operations family', code: 'RETAIL', description: 'Store and supply-chain roles (training example)' })) await submit('job family');
if (await create('/admin/grades/create', 'Grade 2', { name: 'Grade 2', code: 'G2', rank: '2', description: 'Associate grade (training example)' },
    async () => { await chooseSearchable(pg, pg.locator('main'), 0, 'L2', 'L2'); await setForm(pg, 'effective_from', '2026-04-01'); })) await submit('grade');
for (const [name, code, level, shotIt] of [['Store Manager', 'STORE_MGR', 'L4', true], ['Retail Associate', 'RETAIL_ASSOC', 'L2', false], ['HR Executive', 'HR_EXEC', 'L3', false]]) {
    if (await create('/admin/designations/create', name, { name, code, description: `${name} (training example)` },
        async () => { await chooseSearchable(pg, pg.locator('main'), 0, level, level); await setForm(pg, 'effective_from', '2026-04-01'); })) {
        if (shotIt) await shot(s, pg, 'client-onboarding/90-onb-34-new-designation', { ...D, screen: 'Admin › Setup › Designations › New designation',
            action: 'Name, code, level and other job attributes, effective date', expected: 'Designations are effective-dated and carry the job attributes used on hire',
            callouts: [{ loc: pg.locator('[id="form.name"]'), n: 1, label: 'Name' }, { loc: pg.locator('[id="form.code"]'), n: 2, label: 'Code' }, { loc: pg.locator('main button', { hasText: level }), n: 3, label: 'Level' }, { loc: pg.locator('[id="form.effective_from"]'), n: 4, label: 'Effective from' }] });
        await submit(`designation ${name}`);
    }
}
await go(pg, '/admin/designations');
await shot(s, pg, 'client-onboarding/90-onb-35-designations', { ...D, screen: 'Admin › Setup › Designations', action: 'Back to the list', expected: 'Store Manager (L4), Retail Associate (L2), HR Executive (L3)',
    callouts: [{ loc: pg.locator('tr', { hasText: 'Store Manager' }), n: 1, label: 'Designation' }] });

// A budgeted position.
await go(pg, '/admin/positions');
const positionExists = await has('POS-SM-01');
{
    await pg.setViewportSize({ width: 1440, height: 2100 });
    await pg.getByRole('button', { name: 'New position' }).click();
    await idle(pg, 1500);
    await byId('code').fill(positionExists ? 'POS-SM-02' : 'POS-SM-01');
    await setData(pg, 'effective_from', '2026-04-01');
    await byId('title').fill('Store Manager, Bengaluru HQ');
    await byId('company_id').selectOption({ label: 'Northwind Retail' }).catch(() => {});
    await idle(pg, 800);
    await byId('employment_type_id').selectOption({ label: 'Full-time' }).catch(() => {});
    await byId('worker_type').selectOption({ index: 1 }).catch(() => {});
    await byId('grade_id').selectOption({ index: 1 }).catch(() => {});
    await byId('occupancy_mode').selectOption({ index: 1 }).catch(() => {});
    await byId('headcount').fill('1');
    await byId('reason').fill('Budgeted role for the head office store (training example)');
    await idle(pg, 800);
    await shot(s, pg, 'client-onboarding/90-onb-36-new-position', { ...D, screen: 'Organisation › Workforce › Positions › New position',
        action: 'Code, effective date, title, company, employment type, worker type, grade, occupancy, headcount, reason', expected: 'A draft position; it goes through propose → approve → open before it is filled',
        note: 'FTE fields left blank: the browser refuses whole numbers such as 1 because of the field step (documented defect F8).',
        clip: await modal(pg).boundingBox(), callouts: [{ loc: byId('code'), n: 1, label: 'Code' }, { loc: byId('title'), n: 2, label: 'Title' }, { loc: byId('grade_id'), n: 3, label: 'Grade' }, { loc: byId('headcount'), n: 4, label: 'Seats' }, { loc: byId('fte'), n: 5, label: 'FTE (leave blank, F8)' }] });
    if (!positionExists) {
        await modal(pg).getByRole('button', { name: 'Submit', exact: true }).last().click();
        await idle(pg, 2500);
        console.log('    position', (await toasts(pg)).join(' / '));
    } else {
        await modal(pg).getByRole('button', { name: 'Cancel', exact: true }).last().click();
        await idle(pg, 800);
    }
    await pg.setViewportSize({ width: 1440, height: 900 });
    await closeToasts(pg);
}
await go(pg, '/admin/positions');
await shot(s, pg, 'client-onboarding/90-onb-37-positions', { ...D, screen: 'Organisation › Workforce › Positions', action: 'Back to the list', expected: 'POS-SM-01 in Draft',
    callouts: [{ loc: pg.locator('tr', { hasText: 'POS-SM-01' }), n: 1, label: 'Draft position' }] });

await finish(s);
