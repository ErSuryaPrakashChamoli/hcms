// Deck 4 stage 6: joining and reporting relationships in Northwind Retail — Mark as joined, then Change manager.
// EXECUTED on the disposable training database. Re-runnable.
import { start, open, go, shot, finish, idle, toasts, closeToasts, modal, setData, dump } from './lib.mjs';
import './d-accounts.mjs';
import { chooseSearchable } from './d-ui.mjs';

const s = await start('90-onboarding-g-reporting');
const D = { deck: [4, 3], prereq: 'Employees hired; employee.lifecycle / employee.position permissions' };
const pg = await open(s, 'nwadmin', '/admin/employees');
const ids = {};
for (const n of ['Vivek Rao', 'Ishita Sen', 'Rohit Menon']) {
    const href = await pg.locator('tr', { hasText: n }).getByRole('link').first().getAttribute('href').catch(() => null);
    ids[n] = href?.match(/employees\/(\d+)/)?.[1];
}
console.log('    ids', JSON.stringify(ids));
const action = async (label) => {
    await pg.getByRole('button', { name: 'Action', exact: true }).first().click();
    await idle(pg, 800);
    // F27: the menu opens under the sticky section bar, which intercepts pointer clicks; fire the item's click handler.
    await pg.locator('.fi-dropdown-panel:visible').getByText(label, { exact: true }).locator('xpath=ancestor-or-self::*[self::button or self::a][1]').dispatchEvent('click');
    await idle(pg, 1500);
};

for (const [name, shotIt] of [['Vivek Rao', true], ['Rohit Menon', false], ['Ishita Sen', false]]) {
    await go(pg, `/admin/employees/${ids[name]}`);
    if (await pg.getByText('Probation', { exact: true }).count()) continue;
    await action('Mark as joined');
    await setData(pg, 'joining_date', '2026-10-19');
    await modal(pg).locator('textarea').first().fill('Joined on Monday 19 October (training example)');
    await idle(pg, 600);
    if (shotIt) await shot(s, pg, 'client-onboarding/90-onb-46-mark-as-joined', { ...D, workflow: 'Mark a new hire as joined', screen: 'Employee 360 › Action › Mark as joined',
        action: 'Confirm the actual joining date with a reason', expected: 'Lifecycle moves Preboarding → Joined → Probation', element: '.fi-modal-window:visible >> nth=-1',
        callouts: [{ loc: modal(pg).locator('input').first(), n: 1, label: 'Joining date' }, { loc: modal(pg).locator('textarea'), n: 2, label: 'Reason for change' }] });
    await modal(pg).getByRole('button', { name: 'Submit', exact: true }).last().click();
    await idle(pg, 2500);
    console.log('    joined', name, (await toasts(pg)).join(' / '));
    if (shotIt) await shot(s, pg, 'client-onboarding/90-onb-47-joined-probation', { ...D, workflow: 'Mark a new hire as joined', screen: 'Employee 360 (after Mark as joined)',
        action: 'Submit', expected: '"Joined — Now in probation."; the status badge shows Probation', callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Confirmation' }] });
    await closeToasts(pg);
}

// Change manager: Ishita Sen → Vivek Rao, a guided three-step change.
const W = { ...D, workflow: 'Reporting relationships: Change manager' };
await go(pg, `/admin/employees/${ids['Ishita Sen']}`);
if (!await pg.getByText('Vivek Rao').count() || process.env.FORCE) {
    await action('Change manager');
    const m = () => modal(pg);
    console.log('    types', (await m().locator('[id="mountedActionSchema0.type"] option').allInnerTexts()).map((t) => t.trim()).join(' | '));
    await m().locator('[id="mountedActionSchema0.type"]').selectOption({ index: 1 });
    await idle(pg, 800);
    await shot(s, pg, 'client-onboarding/90-onb-48-change-manager-context', { ...W, screen: 'Employee 360 › Action › Change manager (01 Context)',
        action: 'Choose which relationship changes (line manager, dotted line, project …)', expected: 'Step 1 of 3 shows today’s reporting lines', element: '.fi-modal-window:visible >> nth=-1',
        callouts: [{ loc: m().locator('[id="mountedActionSchema0.type"]'), n: 1, label: 'Relationship' }, { loc: m().getByRole('button', { name: 'Next' }), n: 2, label: 'Next' }] });
    await m().getByRole('button', { name: 'Next' }).click();
    await idle(pg, 1200);
    await dump(pg, '.fi-modal-window:visible >> nth=-1', 40);
    await chooseSearchable(pg, m(), 0, 'Vivek Rao', 'Vivek');
    await m().locator('[id="mountedActionSchema0.effective_from"]').fill('2026-10-19');
    await idle(pg, 800);
    await shot(s, pg, 'client-onboarding/90-onb-49-change-manager-change', { ...W, screen: 'Change manager (02 Change)',
        action: 'Pick the new manager and the date the change takes effect', expected: 'Only employed people are offered as managers', element: '.fi-modal-window:visible >> nth=-1',
        callouts: [{ loc: m().locator('button', { hasText: 'Vivek Rao' }), n: 1, label: 'New manager' }, { loc: m().locator('[id="mountedActionSchema0.effective_from"]'), n: 2, label: 'Effective from' }] });
    await m().getByRole('button', { name: 'Next' }).click();
    await idle(pg, 1200);
    await m().locator('[id="mountedActionSchema0.audit_reason"]').fill('Ishita joins the Store Excellence team under the store manager (training example)');
    await idle(pg, 800);
    await shot(s, pg, 'client-onboarding/90-onb-50-change-manager-review', { ...W, screen: 'Change manager (03 Review)',
        action: 'Review Before → After and give the reason', expected: 'The old and new reporting line side by side; Confirm reporting change', element: '.fi-modal-window:visible >> nth=-1',
        callouts: [{ loc: m().locator('[id="mountedActionSchema0.audit_reason"]'), n: 1, label: 'Reason for change' }, { loc: m().getByRole('button', { name: /Confirm/ }), n: 2, label: 'Confirm reporting change' }] });
    if (!process.env.PROBE) {
        await m().getByRole('button', { name: /Confirm/ }).last().click();
        await idle(pg, 2500);
        console.log('    change manager', (await toasts(pg)).join(' / '));
        await shot(s, pg, 'client-onboarding/90-onb-51-reporting-changed', { ...W, screen: 'Employee 360 (after the change)', action: 'Confirm reporting change',
            expected: 'Ishita Sen now reports to Vivek Rao; the change is in her history', callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Confirmation' }, { loc: pg.getByText('Vivek Rao'), n: 2, label: 'Reports to' }] });
        await closeToasts(pg);
    }
}

// The structure with people in it.
await go(pg, '/admin/organisation-map');
await shot(s, pg, 'client-onboarding/90-onb-52-org-map-with-people', { ...D, workflow: 'Validate the structure', screen: 'Organisation › Org map',
    action: 'Open the Org map after hiring', expected: 'Vivek Rao with Ishita Sen reporting to him; Rohit Menon', callouts: [{ loc: pg.getByText('Vivek Rao'), n: 1, label: 'Manager' }] });
await go(pg, '/admin/people');
await shot(s, pg, 'client-onboarding/90-onb-53-directory', { ...D, workflow: 'Validate the structure', screen: 'People › Directory',
    action: 'Open the Directory', expected: 'The three Northwind employees with designation, department and location', callouts: [] });
await finish(s);
