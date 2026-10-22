// Deck 4 stage 3b: place the units created so far into the organisation tree with the Organisation Designer.
// EXECUTED on the disposable training database (Northwind Retail). Re-runnable.
import { start, open, go, shot, finish, idle, toasts, closeToasts, modal, dump } from './lib.mjs';
import './d-accounts.mjs';
import { chooseSearchable } from './d-ui.mjs';

const s = await start('90-onboarding-d-designer');
const D = { deck: [4], workflow: 'Organisation structure: Organisation Designer', prereq: 'Company and units created; signed in as the tenant administrator' };
const byId = (page, id) => modal(page).locator(`[id="mountedActionSchema0.${id}"]`);
const pg = await open(s, 'nwadmin', '/admin/organisation-designer');

if (await pg.getByText('No organisation units placed yet', { exact: false }).count()) {
    await pg.getByRole('button', { name: 'Add root unit' }).click();
    await idle(pg, 1200);
    await byId(pg, 'type').selectOption({ label: 'Company' });
    await idle(pg, 600);
    await byId(pg, 'mode-existing').check();
    await idle(pg, 1000);
    await chooseSearchable(pg, modal(pg), 0, 'Northwind Retail', 'Northwind');
    await byId(pg, 'audit_reason').fill('Initial organisation structure for go-live (training example)');
    await idle(pg, 600);
    await shot(s, pg, 'client-onboarding/90-onb-29-designer-add-root', { ...D, screen: 'Organisation Designer › Add root unit',
        action: 'Add the company as the root: type Company, use an existing unit, reason for change', expected: 'The tree starts from the company',
        element: '.fi-modal-window:visible >> nth=-1', callouts: [{ loc: byId(pg, 'type'), n: 1, label: 'Unit type' }, { loc: byId(pg, 'mode-existing'), n: 2, label: 'Use an existing unit' }, { loc: byId(pg, 'audit_reason'), n: 3, label: 'Reason for change' }] });
    await modal(pg).getByRole('button', { name: 'Submit', exact: true }).last().click();
    await idle(pg, 2500);
    console.log('    root:', (await toasts(pg)).join(' / '));
    await closeToasts(pg);
}
const addChild = async (parent, type, existing, shotName) => {
    if (await pg.locator('main').getByText(existing, { exact: true }).count()) return;
    const row = pg.locator('main div, main li').filter({ hasText: parent }).filter({ has: pg.getByRole('button', { name: 'Add child' }) }).last();
    await row.getByRole('button', { name: 'Add child' }).first().click();
    await idle(pg, 1200);
    await byId(pg, 'type').selectOption({ label: type });
    await idle(pg, 600);
    await byId(pg, 'mode-existing').check();
    await idle(pg, 1000);
    await chooseSearchable(pg, modal(pg), 0, existing, existing.slice(0, 8));
    await byId(pg, 'audit_reason').fill('Initial organisation structure for go-live (training example)');
    await idle(pg, 600);
    if (shotName) await shot(s, pg, shotName, { ...D, screen: `Organisation Designer › Add unit under ${parent}`,
        action: `Add child under ${parent}: type ${type}, use the existing unit "${existing}", reason for change`, expected: 'The unit is placed under its parent; the change is recorded in the audit trail',
        element: '.fi-modal-window:visible >> nth=-1', callouts: [{ loc: byId(pg, 'type'), n: 1, label: 'Unit type' }, { loc: modal(pg).locator('button', { hasText: existing }), n: 2, label: 'Existing unit' }, { loc: byId(pg, 'audit_reason'), n: 3, label: 'Reason for change' }] });
    await modal(pg).getByRole('button', { name: 'Submit', exact: true }).last().click();
    await idle(pg, 2500);
    console.log('    child', existing, (await toasts(pg)).join(' / '));
    await closeToasts(pg);
};
await addChild('Northwind Retail', 'Business Unit', 'Retail Operations', 'client-onboarding/90-onb-30-designer-add-child');
await addChild('Retail Operations', 'Department', 'Store Operations');
await addChild('Northwind Retail', 'Department', 'People & Culture');
await addChild('Store Operations', 'Team', 'Store Excellence');
await addChild('Northwind Retail', 'Location', 'Bengaluru HQ');
await go(pg, '/admin/organisation-designer');
await shot(s, pg, 'client-onboarding/90-onb-31-designer-tree', { ...D, screen: 'Organisation Designer (tree)',
    action: 'Review the tree', expected: 'Company → business unit → department → team, plus a department and a location under the company; each node has Add child, Rename, Move, Deactivate, Remove from tree',
    callouts: [{ loc: pg.getByRole('button', { name: 'Add child' }), n: 1, label: 'Add child' }, { loc: pg.getByRole('button', { name: 'Move', exact: true }), n: 2, label: 'Move' }, { loc: pg.getByRole('button', { name: 'Chart' }), n: 3, label: 'Chart view' }] });
await pg.getByRole('button', { name: 'Chart' }).click();
await idle(pg, 1500);
await shot(s, pg, 'client-onboarding/90-onb-32-designer-chart', { ...D, screen: 'Organisation Designer (chart)', action: 'Switch to Chart', expected: 'The same structure as a chart', callouts: [] });
await finish(s);
