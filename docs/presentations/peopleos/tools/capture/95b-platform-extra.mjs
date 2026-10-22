// Deck 5 (continued): legal hold release request (two-person rule), the export request dialog (opened, not submitted)
// and an operator entering a customer tenant. Northwind Retail (fictional) only. Re-runnable.
import { start, open, go, shot, finish, idle, toasts, closeToasts, modal, dump } from './lib.mjs';

const s = await start('95b-platform-extra');
const D = { deck: [5, 8], prereq: 'Platform operator with MFA' };
const op = await open(s, 'operator', '/admin/platform-offboarding?tenant=2');
await op.setViewportSize({ width: 1440, height: 1500 });
await idle(op, 1200);

// Export request dialog (opened only: generating a package needs a queue worker and writes a file).
await op.getByRole('button', { name: 'Request export' }).click();
await idle(op, 1500);
await dump(op, '.fi-modal-window:visible >> nth=-1', 30);
await shot(s, op, 'platform-admin/95-pl-32-request-export-dialog', { ...D, workflow: 'Offboarding, export, legal hold and deletion', screen: 'Offboarding › Request export',
    action: 'Open Request export for a tenant', expected: 'Scope and reason; the package is produced in the background and protected', status: 'IMPLEMENTED BUT NOT VERIFIED END-TO-END',
    note: 'Opened and cancelled: generation needs the queue worker, which does not run in this local environment.', element: '.fi-modal-window:visible >> nth=-1', callouts: [] });
await modal(op).getByRole('button', { name: 'Cancel', exact: true }).last().click();
await idle(op, 800);

// Release of the hold: requested by this operator; a second operator must approve.
const rel = op.getByRole('button', { name: 'Request release' });
if (await rel.count()) {
    await rel.first().click();
    await idle(op, 1500);
    await dump(op, '.fi-modal-window:visible >> nth=-1', 30);
    for (const ta of await modal(op).locator('textarea').all()) await ta.fill('Matter LH-2026-01 closed by Northwind legal (training example)');
    await shot(s, op, 'platform-admin/95-pl-33-request-release', { ...D, workflow: 'Offboarding, export, legal hold and deletion', screen: 'Offboarding › Legal holds › Request release',
        action: 'Request the release of the hold with a reason', expected: 'The release waits for a different operator to approve it (two-person rule)', element: '.fi-modal-window:visible >> nth=-1', callouts: [] });
    await modal(op).getByRole('button', { name: /Submit|Confirm|Request/ }).last().click();
    await idle(op, 2500);
    console.log('    release', (await toasts(op)).join(' / '));
    await op.mouse.wheel(0, 1600);
    await idle(op, 800);
    await shot(s, op, 'platform-admin/95-pl-34-release-pending', { ...D, workflow: 'Offboarding, export, legal hold and deletion', screen: 'Offboarding › Legal holds (release requested)',
        action: 'Submit the release request', expected: 'The hold stays active until a second operator approves the release; only one operator exists in this environment, so it stays pending',
        status: 'AVAILABLE ONLY AFTER CONFIGURATION', callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Result' }] });
    await closeToasts(op);
}
await op.setViewportSize({ width: 1440, height: 900 });

// Entering a customer tenant.
await go(op, '/admin/tenants');
await op.locator('tr', { hasText: 'Northwind' }).getByRole('button', { name: 'Enter' }).click();
await idle(op, 1500);
await dump(op, '.fi-modal-window:visible >> nth=-1', 30);
if (await modal(op).count()) {
    for (const ta of await modal(op).locator('textarea').all()) await ta.fill('Support ticket NW-SUP-001: verify leave policy set-up with the customer (training example)');
    await modal(op).locator('[id="mountedActionSchema0.reference"]').fill('NW-SUP-001');
    await shot(s, op, 'platform-admin/95-pl-35-enter-tenant-dialog', { ...D, workflow: 'Operator access to a customer tenant', screen: 'Tenants › Enter',
        action: 'Enter a customer tenant with a reason', expected: 'Operator access is deliberate, explained and audited', element: '.fi-modal-window:visible >> nth=-1', callouts: [] });
    await modal(op).getByRole('button', { name: /Confirm|Enter|Submit/ }).last().click();
    await idle(op, 3000);
}
console.log('    entered ->', op.url());
await go(op, '/admin');
await shot(s, op, 'platform-admin/95-pl-36-operator-in-tenant', { ...D, workflow: 'Operator access to a customer tenant', screen: 'Home inside Northwind Retail (operator support access)',
    action: 'After Enter, open Home', expected: 'The operator works in the tenant with the administration lens; the tenant name shows in the date line',
    note: 'Observation: no persistent on-screen banner marks the session as operator support access. The Enter dialog states that access is recorded in Markedge’s and the tenant’s audit trails and ends after 60 minutes.',
    callouts: [{ loc: op.getByText('NORTHWIND RETAIL PVT LTD', { exact: false }), n: 1, label: 'Tenant in context' }] });
await go(op, '/admin/platform-audit');
await op.setViewportSize({ width: 1440, height: 1300 });
await idle(op, 1000);
await shot(s, op, 'platform-admin/95-pl-37-platform-audit-after', { ...D, workflow: 'Platform audit trail', screen: 'Admin › Platform › Platform audit trail',
    action: 'Review what the operator did', expected: 'Tenant suspended / reactivated, legal hold placed, release requested and tenant entered, with actor and reason', callouts: [] });
await finish(s);
