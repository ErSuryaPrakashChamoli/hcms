// Deck 4 stage 1: the platform operator provisions a new customer tenant (fictional "Northwind Retail Pvt Ltd").
// EXECUTED on the disposable training database. Re-runnable: if the tenant exists, creation is skipped.
import { start, open, go, shot, finish, idle, settle, toasts, closeToasts, dump } from './lib.mjs';

const s = await start('90-onboarding-a-provision');
const D = { deck: [4, 5], workflow: 'Tenant provisioning (operator)', prereq: 'Platform operator account with MFA' };

const op = await open(s, 'operator', '/admin');
await shot(s, op, 'client-onboarding/90-onb-01-platform-home', { ...D, screen: 'Platform home (operator)',
    action: 'The operator signs in (password + authenticator code) and lands on the Platform home',
    expected: 'Tenants and Platform health; operators work outside any tenant until they choose one',
    callouts: [{ loc: op.getByText('Manage tenants'), n: 1, label: 'Manage tenants' }, { loc: op.getByText('Open readiness'), n: 2, label: 'Open readiness' }] });

await go(op, '/admin/tenants');
await shot(s, op, 'client-onboarding/90-onb-02-tenants-list', { ...D, screen: 'Admin › Platform › Tenants',
    action: 'Open Admin › Platform › Tenants', expected: 'Every tenant with slug, status, country, users; New tenant; row actions Enter, Suspend, Edit',
    callouts: [{ loc: op.getByRole('link', { name: 'New tenant' }), n: 1, label: 'New tenant' }, { loc: op.getByRole('button', { name: 'Enter' }), n: 2, label: 'Enter (work in tenant)' }, { loc: op.getByRole('button', { name: 'Suspend' }), n: 3, label: 'Suspend' }] });

const exists = await op.locator('tr', { hasText: 'Northwind' }).count();
await go(op, '/admin/tenants/create');
await op.setViewportSize({ width: 1440, height: 1400 });
await op.locator('[id="form.name"]').fill('Northwind Retail Pvt Ltd');
await idle(op, 1500);
await op.locator('[id="form.admin_name"]').fill('Farah Khan');
await op.locator('[id="form.admin_email"]').fill('farah.khan@northwind.example');
await op.locator('[id="form.audit_reason"]').fill('New customer contract NW-2026-01: implementation kick-off (training example)');
await idle(op, 800);
await op.evaluate(() => window.scrollTo(0, 0));
await shot(s, op, 'client-onboarding/90-onb-03-new-tenant-form', { ...D, screen: 'Tenants › New tenant',
    action: 'Fill tier, data region, name (the slug is suggested from it), status, locale defaults, the first administrator and a reason for change',
    expected: 'The first administrator is provisioned as Tenant Super Admin and invited by e-mail; the operator never sets a password',
    callouts: [{ loc: op.locator('[id="form.tier"]'), n: 1, label: 'Tier' }, { loc: op.locator('[id="form.region"]'), n: 2, label: 'Data region' },
        { loc: op.locator('[id="form.slug"]'), n: 3, label: 'Slug' }, { loc: op.locator('[id="form.country_code"]'), n: 4, label: 'Locale defaults' },
        { loc: op.locator('[id="form.admin_email"]'), n: 5, label: 'First administrator' }, { loc: op.locator('[id="form.audit_reason"]'), n: 6, label: 'Reason for change' }] });
if (exists) {
    // The tenant already exists (created on the first run): submitting again shows the duplicate checks.
    await op.getByRole('button', { name: 'Create tenant', exact: true }).click();
    await idle(op, 2500);
    await shot(s, op, 'client-onboarding/90-onb-04-duplicate-tenant-refused', { ...D, deck: [4, 7], workflow: 'Tenant provisioning (operator): duplicate checks', screen: 'Tenants › New tenant (validation)',
        action: 'Submit the same tenant again', expected: '"The slug has already been taken." and "The email has already been taken." — nothing is created',
        callouts: [{ loc: op.getByText('The slug has already been taken.'), n: 1, label: 'Slug taken' }, { loc: op.getByText('The email has already been taken.'), n: 2, label: 'E-mail taken' }] });
} else {
    await op.getByRole('button', { name: 'Create tenant', exact: true }).click();
    await idle(op, 4000);
    console.log('    after create:', op.url(), (await toasts(op)).join(' / '));
}
await op.setViewportSize({ width: 1440, height: 900 });

await go(op, '/admin/tenants');
await shot(s, op, 'client-onboarding/90-onb-05-tenants-with-northwind', { ...D, screen: 'Admin › Platform › Tenants',
    action: 'Back to the Tenants list', expected: 'Northwind Retail is listed as Active with 1 user (the invited administrator) and a Send invitation action',
    callouts: [{ loc: op.locator('tr', { hasText: 'Northwind' }), n: 1, label: 'New tenant' }, { loc: op.locator('tr', { hasText: 'Northwind' }).getByRole('button', { name: 'Send invitation' }), n: 2, label: 'Send invitation' }] });

await op.locator('tr', { hasText: 'Northwind' }).getByRole('link', { name: 'Edit' }).click();
await idle(op, 1500);
await op.setViewportSize({ width: 1440, height: 1200 });
await shot(s, op, 'client-onboarding/90-onb-06-tenant-edit', { ...D, screen: 'Tenants › Edit tenant',
    action: 'Open the tenant record', expected: 'Operator-only settings: tier, data region, status, trial metadata, locale defaults',
    callouts: [{ loc: op.locator('[id="form.status"]'), n: 1, label: 'Status (read-only here)' }, { loc: op.getByText('Use Suspend or Reactivate'), n: 2, label: 'Lifecycle via Suspend / Reactivate' }, { loc: op.locator('[id="form.tier"]'), n: 3, label: 'Tier' }] });

await finish(s);
