// Deck 5: the Markedge platform operator console. Commercial, signup and deletion features are switched off and are
// shown in their real state. Lifecycle actions (suspend / reactivate, legal hold) are EXECUTED on the fictional
// Northwind Retail tenant only — never on Demo Group. Re-runnable.
import { start, open, context, go, shot, finish, idle, settle, toasts, closeToasts, modal, dump, PERSONAS, passwordFor, BASE } from './lib.mjs';
import './d-accounts.mjs';

const s = await start('95-platform');
const D = { deck: [5], prereq: 'Platform operator (is_platform_admin) signed in with MFA' };
const op = await open(s, 'operator', '/admin');
const page1 = async (path, name, extra) => {
    await go(op, path);
    if (extra?.tall) await op.setViewportSize({ width: 1440, height: extra.tall });
    await idle(op, 800);
    await shot(s, op, name, { ...D, ...extra, tall: undefined });
    if (extra?.tall) await op.setViewportSize({ width: 1440, height: 900 });
};

await shot(s, op, 'platform-admin/95-pl-04-platform-home', { ...D, workflow: 'Platform home', screen: 'Platform home (operator)', action: 'Sign in as an operator', expected: 'Tenants with status; Platform health → readiness',
    callouts: [{ loc: op.getByText('Manage tenants'), n: 1, label: 'Manage tenants' }, { loc: op.getByText('Open readiness'), n: 2, label: 'Open readiness' }] });

// Tenant lifecycle: suspend and reactivate Northwind.
const W = { ...D, workflow: 'Tenant lifecycle: suspend and reactivate' };
await go(op, '/admin/tenants');
await shot(s, op, 'platform-admin/95-pl-05-tenants', { ...W, workflow: 'Tenant list', screen: 'Admin › Platform › Tenants', action: 'Open Tenants', expected: 'Name, slug, status, country, users, companies; Enter, Suspend, Send invitation, Edit',
    callouts: [{ loc: op.locator('tr', { hasText: 'Northwind' }).getByRole('button', { name: 'Suspend' }), n: 1, label: 'Suspend' }, { loc: op.locator('tr', { hasText: 'Northwind' }).getByRole('button', { name: 'Enter' }), n: 2, label: 'Enter' }] });
const row = () => op.locator('tr', { hasText: 'Northwind' });
if (await row().getByRole('button', { name: 'Suspend' }).count()) {
    await row().getByRole('button', { name: 'Suspend' }).click();
    await idle(op, 1200);
    await modal(op).locator('textarea').fill('Customer requested a temporary pause during data migration (training example)');
    await shot(s, op, 'platform-admin/95-pl-06-suspend-dialog', { ...W, screen: 'Tenants › Suspend', action: 'Suspend with a reason', expected: 'Confirm; the reason goes to the platform audit trail', element: '.fi-modal-window:visible >> nth=-1',
        callouts: [{ loc: modal(op).locator('textarea'), n: 1, label: 'Reason' }, { loc: modal(op).getByRole('button', { name: 'Confirm' }), n: 2, label: 'Confirm' }] });
    await modal(op).getByRole('button', { name: 'Confirm' }).click();
    await idle(op, 2500);
    console.log('    suspend', (await toasts(op)).join(' / '));
    await shot(s, op, 'platform-admin/95-pl-07-suspended', { ...W, screen: 'Tenants (after Suspend)', action: 'Confirm', expected: 'Northwind shows Suspended; Reactivate replaces Suspend',
        callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Result' }, { loc: row(), n: 2, label: 'Suspended tenant' }] });
    await closeToasts(op);
}
// The tenant's administrator tries to sign in.
{
    const g = await (await context(s, 'guest', { fresh: true })).newPage();
    g.__persona = 'nwadmin';
    await g.goto(`${BASE}/admin/login`);
    await settle(g);
    await g.fill('input[type=email]', PERSONAS.nwadmin.email);
    await g.fill('input[type=password]', passwordFor('nwadmin'));
    await g.click('button[type=submit]');
    await idle(g, 2500);
    console.log('    suspended sign-in ->', g.url());
    await shot(s, g, 'platform-admin/95-pl-08-suspended-sign-in', { ...W, deck: [5, 7], screen: 'Sign in (tenant suspended)', action: 'The Northwind administrator signs in with the correct password while the tenant is suspended',
        expected: 'Sign-in refused with the generic credentials message (by design, no hint that the tenant is suspended)', callouts: [{ loc: g.locator('.fi-fo-field-wrp-error-message'), n: 1, label: 'Message' }] });
}
if (await row().getByRole('button', { name: 'Reactivate' }).count()) {
    await row().getByRole('button', { name: 'Reactivate' }).click();
    await idle(op, 1200);
    const ta = modal(op).locator('textarea');
    if (await ta.count()) await ta.fill('Migration pause over; access restored (training example)');
    await modal(op).getByRole('button', { name: 'Confirm' }).click();
    await idle(op, 2500);
    console.log('    reactivate', (await toasts(op)).join(' / '));
    await shot(s, op, 'platform-admin/95-pl-09-reactivated', { ...W, screen: 'Tenants (after Reactivate)', action: 'Reactivate with a reason', expected: 'Northwind Active again; its users can sign in',
        callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Result' }, { loc: row(), n: 2, label: 'Active tenant' }] });
    await closeToasts(op);
}

// Switched-off and shadow-mode commercial screens: status only.
const C = { ...D, status: 'DISABLED BY SAFETY OR COMMERCIAL GATES', prereq: 'Owner decisions (signup D10, plans and prices B-4, payments B-7/B-8/B-9) before activation' };
await page1('/admin/platform-signups', 'platform-admin/95-pl-10-signups', { ...C, workflow: 'Self-service signup (switched off)', screen: 'Admin › Platform › Signups', action: 'Open Signups', expected: '"Can signup work today?" explains why public registration is off; no applications', tall: 1300, callouts: [] });
await page1('/admin/platform-plans', 'platform-admin/95-pl-11-plans', { ...C, workflow: 'Plans, subscriptions and entitlements (shadow mode)', screen: 'Admin › Platform › Plans', action: 'Open Plans', expected: 'Commercial plans in shadow mode; no plan, price or default plan is shipped', callouts: [] });
await page1('/admin/platform-subscriptions', 'platform-admin/95-pl-12-subscriptions', { ...C, workflow: 'Plans, subscriptions and entitlements (shadow mode)', screen: 'Admin › Platform › Subscriptions', action: 'Open Subscriptions', expected: 'All tenants today; shadow mode: nothing is enforced or billed', tall: 1200, callouts: [] });
await page1('/admin/platform-entitlements', 'platform-admin/95-pl-13-entitlements', { ...C, workflow: 'Plans, subscriptions and entitlements (shadow mode)', screen: 'Admin › Platform › Entitlements', action: 'Open Entitlements', expected: 'Shadow observations: what would have been blocked if entitlements were enforced', callouts: [] });
await page1('/admin/platform-billing-catalog', 'platform-admin/95-pl-14-billing-catalogue', { ...C, workflow: 'Billing, tax and payments (switched off)', screen: 'Admin › Platform › Billing catalogue', action: 'Open Billing catalogue', expected: 'Markets, price matrix and currencies; changes need a second operator’s approval', tall: 1300, callouts: [] });
await page1('/admin/platform-tax', 'platform-admin/95-pl-15-tax-invoicing', { ...C, workflow: 'Billing, tax and payments (switched off)', screen: 'Admin › Platform › Tax & invoicing', action: 'Open Tax & invoicing', expected: 'Jurisdiction support, selling entities, statutory dataset (load = maker, verify = second operator), tax rules, invoice series', tall: 1500, callouts: [] });
await page1('/admin/platform-commercial-policies', 'platform-admin/95-pl-16-commercial-policies', { ...C, workflow: 'Billing, tax and payments (switched off)', screen: 'Admin › Platform › Commercial policies', action: 'Open Commercial policies', expected: 'Markedge policy values and statutory parameters with change history; Propose change (maker-checker)', tall: 1300, callouts: [] });
await page1('/admin/platform-billing-accounts', 'platform-admin/95-pl-17-billing-accounts', { ...C, workflow: 'Billing, tax and payments (switched off)', screen: 'Admin › Platform › Billing accounts', action: 'Open Billing accounts', expected: 'One billing account view per tenant', callouts: [] });
await page1('/admin/platform-invoices', 'platform-admin/95-pl-18-invoices', { ...C, workflow: 'Billing, tax and payments (switched off)', screen: 'Admin › Platform › Invoices', action: 'Open Invoices', expected: 'No invoices: real invoicing waits for owner inputs', callouts: [] });
await page1('/admin/platform-payments', 'platform-admin/95-pl-19-payments', { ...C, workflow: 'Billing, tax and payments (switched off)', screen: 'Admin › Platform › Payments', action: 'Open Payments', expected: 'Payments and provider events; live payments are refused in production', callouts: [] });
await page1('/admin/platform-approvals', 'platform-admin/95-pl-20-financial-approvals', { ...C, workflow: 'Billing, tax and payments (switched off)', screen: 'Admin › Platform › Approvals (financial)', action: 'Open Financial approvals', expected: 'Maker-checker requests waiting for a second operator', callouts: [] });

// Operations, audit and readiness.
const O = { ...D, status: 'VERIFIED IN RUNNING APPLICATION' };
await page1('/admin/platform-operations', 'platform-admin/95-pl-21-operations', { ...O, deck: [5, 7], workflow: 'Operations: queues, failed jobs and schedules', screen: 'Admin › Platform › Operations', action: 'Open Operations',
    expected: 'Platform health, queue depth, failed jobs by job, scheduler heartbeat, per-tenant status', note: 'No queue worker or scheduler runs in this local environment; the page reports that state.', tall: 1500, callouts: [] });
await page1('/admin/platform-audit', 'platform-admin/95-pl-22-platform-audit', { ...O, deck: [5, 8], workflow: 'Platform audit trail', screen: 'Admin › Platform › Platform audit trail', action: 'Open the platform audit trail',
    expected: 'Operator actions across tenants (tenant provisioned, suspended, reactivated, MFA changed, statutory rule events) with filters', tall: 1300, callouts: [] });
await page1('/admin/platform-readiness', 'platform-admin/95-pl-23-readiness', { ...O, deck: [5, 4, 11], workflow: 'Release readiness checks', screen: 'Admin › Platform › Platform readiness', action: 'Open Platform readiness',
    expected: 'Each check with PASS / WARN / FAIL / BLOCKED (statutory rules, MFA, demo accounts, backups, scheduler …)', note: 'Readiness reflects this local development environment, not a production target.', tall: 1700, callouts: [] });

// Offboarding, legal hold and deletion (off).
const F = { ...D, deck: [5, 8], workflow: 'Offboarding, export, legal hold and deletion' };
await page1('/admin/platform-offboarding', 'platform-admin/95-pl-24-offboarding', { ...F, status: 'DISABLED BY SAFETY OR COMMERCIAL GATES', screen: 'Admin › Platform › Offboarding', action: 'Open Offboarding',
    expected: '"Tenant deletion is switched off in this environment (PEOPLEOS_TENANT_DELETION_ENABLED)." Offboarding, exports, legal holds and retention work', callouts: [{ loc: op.getByText('switched off', { exact: false }), n: 1, label: 'Deletion switched off' }] });
await go(op, '/admin/platform-offboarding?tenant=2');
await op.setViewportSize({ width: 1440, height: 1500 });
await idle(op, 1000);
await shot(s, op, 'platform-admin/95-pl-25-offboarding-tenant', { ...F, screen: 'Offboarding › Northwind Retail', action: 'Open a tenant', expected: 'Retention and deletion eligibility, exports, contract retention, legal holds, deletions; Start offboarding, Request export, Place legal hold, Contract retention — and no deletion action',
    callouts: [{ loc: op.getByRole('button', { name: 'Place legal hold' }), n: 1, label: 'Place legal hold' }, { loc: op.getByRole('button', { name: 'Request export' }), n: 2, label: 'Request export' }, { loc: op.getByRole('button', { name: 'Start offboarding' }), n: 3, label: 'Start offboarding' }] });
if (!await op.locator('main').getByText('training example', { exact: false }).count()) {
    await op.getByRole('button', { name: 'Place legal hold' }).click();
    await idle(op, 1500);
    await dump(op, '.fi-modal-window:visible >> nth=-1', 30);
    for (const ta of await modal(op).locator('textarea').all()) await ta.fill('Litigation hold LH-2026-01 requested by Northwind legal (training example)');
    for (const inp of await modal(op).locator('input[type=text]:not([readonly])').all()) { if (!(await inp.inputValue())) await inp.fill('LH-2026-01 (training example)'); }
    await idle(op, 600);
    await shot(s, op, 'platform-admin/95-pl-26-legal-hold-dialog', { ...F, screen: 'Offboarding › Place legal hold', action: 'Place a legal hold with its reference and reason', expected: 'While a hold is active, nothing covered by it can be deleted',
        element: '.fi-modal-window:visible >> nth=-1', callouts: [] });
    await modal(op).getByRole('button', { name: /Confirm|Submit|Place/ }).last().click();
    await idle(op, 2500);
    console.log('    legal hold', (await toasts(op)).join(' / '));
    await shot(s, op, 'platform-admin/95-pl-27-legal-hold-active', { ...F, screen: 'Offboarding › Northwind Retail (hold active)', action: 'Confirm', expected: 'The hold is listed as active with who placed it and why',
        callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Result' }] });
    await closeToasts(op);
    await dump(op, 'main', 60);
}
await op.setViewportSize({ width: 1440, height: 900 });
await page1('/admin/platform-retention-policies', 'platform-admin/95-pl-28-retention-policies', { ...F, status: 'AVAILABLE ONLY AFTER CONFIGURATION', workflow: 'Retention policies', screen: 'Admin › Platform › Retention policies', action: 'Open Retention policies',
    expected: 'No policy in force (none is shipped; nothing is deletable until Markedge decides, D-8); Propose policy version (maker-checker)', tall: 1200, callouts: [] });

// Statutory rules: platform verification (status only).
const R = { ...D, deck: [5, 8, 3], workflow: 'Statutory rule verification (platform)', status: 'IMPLEMENTED BUT NOT VERIFIED END-TO-END', prereq: 'Two platform administrators and official evidence for each rule version' };
await page1('/admin/compliance-rules', 'platform-admin/95-pl-29-compliance-rules', { ...R, screen: 'People › Pay › Compliance rules (operator view)', action: 'Open Compliance rules as an operator',
    expected: 'Each rule version with Parameters, Verification history, Evidence & coverage; operator actions Attach evidence, Submit evidence, Verify, Reject, Publish corrected version', tall: 1300, callouts: [] });
await page1('/admin/rule-notices', 'platform-admin/95-pl-30-rule-notices', { ...R, screen: 'People › Pay › Regulatory notices', action: 'Open Rule notices', expected: 'Open notices about statutory changes, each to be resolved', callouts: [] });
await page1('/admin/export-layouts', 'platform-admin/95-pl-31-export-layouts', { ...R, screen: 'People › Pay › Export layouts', action: 'Open Statutory export layouts', expected: 'Return file layouts with their specification and approval actions', callouts: [] });
await finish(s);
