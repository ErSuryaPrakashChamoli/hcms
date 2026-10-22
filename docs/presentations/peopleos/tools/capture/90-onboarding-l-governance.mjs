// Deck 4 stage 10: workflows, notifications, security policy, settings, feature flags, Change Centre, audit trail and the
// employee import (data migration) in Northwind Retail. EXECUTED on the disposable training database. Re-runnable.
import { start, open, go, shot, finish, idle, toasts, closeToasts, modal, dump } from './lib.mjs';
import './d-accounts.mjs';
import { fillForm } from './d-ui.mjs';
import { writeFileSync, mkdirSync } from 'node:fs';

const s = await start('90-onboarding-l-governance');
const D = { deck: [4], prereq: 'Signed in as the tenant administrator' };
const pg = await open(s, 'nwadmin', '/admin/workflows');

// Workflow: a probation-ending review routed to the manager.
const W1 = { ...D, workflow: 'Workflow and approval configuration' };
if (!await pg.locator('tbody tr', { hasText: 'Probation review' }).count()) {
    await go(pg, '/admin/workflows/create');
    await pg.setViewportSize({ width: 1440, height: 1100 });
    await fillForm(pg, { name: 'Probation review', description: 'Ask the manager to confirm or extend probation (training example)' });
    await pg.locator('[id="form.trigger_event"]').selectOption({ label: 'Probation ending soon' });
    await pg.locator('[id="form.subject_type"]').selectOption({ label: 'Employee' }).catch(() => {});
    await pg.locator('[id="form.status"]').selectOption({ label: 'Active' }).catch(() => {});
    await idle(pg, 800);
    await shot(s, pg, 'client-onboarding/90-onb-82-new-workflow', { ...W1, screen: 'Workflows › Workflows › New workflow', action: 'Name, key, trigger event, subject type, conditions, status',
        expected: 'The workflow starts automatically on its trigger (here: probation ending soon); steps are added in the builder after saving',
        callouts: [{ loc: pg.locator('[id="form.name"]'), n: 1, label: 'Name' }, { loc: pg.locator('[id="form.trigger_event"]'), n: 2, label: 'Trigger event' }, { loc: pg.locator('[id="form.subject_type"]'), n: 3, label: 'Subject' }] });
    await pg.locator('main button[type=submit]').first().click();
    await idle(pg, 3000);
    console.log('    workflow', pg.url(), (await toasts(pg)).join(' / '));
    await closeToasts(pg);
    await pg.setViewportSize({ width: 1440, height: 1500 });
    await idle(pg, 1500);
    await dump(pg, 'main', 60);
    await shot(s, pg, 'client-onboarding/90-onb-83-workflow-saved', { ...W1, screen: 'Workflow (after creating)', action: 'Create workflow', expected: 'Versions, the step builder and history; a draft version is edited, then published', callouts: [] });
    await pg.setViewportSize({ width: 1440, height: 900 });
}
await go(pg, '/admin/notification-rules');
await shot(s, pg, 'client-onboarding/90-onb-84-notification-rules', { ...D, deck: [4, 7], workflow: 'Notification configuration', screen: 'Communication › Notification rules',
    action: 'Open Notification rules in a new tenant', expected: 'No rules and no "New" action: rules cannot be created or deleted from the screen (known limitation F18), so e-mail notices for leave and approvals are not sent unless a rule exists',
    status: 'INCOMPLETE', callouts: [] });

// Security policy (opened, not saved), settings and feature flags.
const W2 = { ...D, deck: [4, 8], workflow: 'Security and tenant settings' };
await go(pg, '/admin/security-policy-page');
await pg.setViewportSize({ width: 1440, height: 1500 });
await idle(pg, 800);
await shot(s, pg, 'client-onboarding/90-onb-85-security-policy', { ...W2, screen: 'Admin › Platform › Security policy', action: 'Review access, password, retention and data-warehouse settings',
    expected: 'Require MFA, session and idle timeouts, password rules, retention days; Save policy. Shown only, not changed in this walkthrough',
    note: 'Known limitations: password expiry is stored but not enforced (F2).', callouts: [{ loc: pg.getByText('ACCESS', { exact: true }), n: 1, label: 'Access (MFA, sessions)' }, { loc: pg.getByText('PASSWORDS', { exact: true }), n: 2, label: 'Passwords' }, { loc: pg.getByRole('button', { name: 'Save policy' }), n: 3, label: 'Save policy' }] });
await pg.setViewportSize({ width: 1440, height: 900 });
await go(pg, '/admin/tenant-settings');
await shot(s, pg, 'client-onboarding/90-onb-86-settings', { ...W2, deck: [4], screen: 'Admin › Setup › Settings', action: 'Review the tenant settings', expected: 'Seeded keys (attendance windows, probation months, employee code padding …); each change needs a reason',
    note: 'Several keys have no effect yet (F30), e.g. branding colour and locale.', callouts: [] });
await go(pg, '/admin/tenant-features');
await shot(s, pg, 'client-onboarding/90-onb-87-feature-flags', { ...W2, deck: [4], screen: 'Admin › Setup › Feature flags', action: 'Review feature flags', expected: 'AI assistants on, external language model (ai.llm) off, configuration approval, sensitive-access audit …',
    callouts: [{ loc: pg.locator('tr', { hasText: 'ai.llm' }), n: 1, label: 'External LLM off' }, { loc: pg.locator('tr', { hasText: 'configuration.approval' }), n: 2, label: 'Change approval' }] });

// Change Centre and audit trail.
const W3 = { ...D, deck: [4, 8], workflow: 'Validation and audit of the set-up' };
await go(pg, '/admin/configuration-changes');
await shot(s, pg, 'client-onboarding/90-onb-88-change-centre', { ...W3, screen: 'Admin › Setup › Change Centre', action: 'Open the Change Centre', expected: 'Pending approval / Scheduled / Recently published / Rejected / History. Empty here: configuration approval is off, and creates apply immediately',
    note: 'Only the main edit form of certain records waits for approval; settings, flags, creates and deletes apply immediately (F29).', callouts: [] });
await go(pg, '/admin/audit-events');
await shot(s, pg, 'client-onboarding/90-onb-89-audit-trail', { ...W3, screen: 'Insights › Audit › Change history (Audit events)', action: 'Review what was set up, by whom, when and why',
    expected: 'Every create, change, invitation, sign-in and role change of the implementation, newest first', callouts: [{ loc: pg.getByRole('button', { name: 'Verify integrity' }), n: 1, label: 'Verify integrity' }, { loc: pg.locator('tbody tr').first(), n: 2, label: 'Latest event' }] });
await pg.getByRole('button', { name: 'Verify integrity' }).click();
await idle(pg, 4000);
console.log('    verify', (await toasts(pg)).join(' / '));
await shot(s, pg, 'client-onboarding/90-onb-90-audit-verified', { ...W3, screen: 'Change history › Verify integrity', action: 'Verify integrity', expected: 'The tenant’s audit hash chain is checked and the result shown',
    callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Result' }] });
await closeToasts(pg);

// Data migration: employee import (fictional CSV).
const W4 = { ...D, deck: [4, 7], workflow: 'Data migration: employee import' };
const csvDir = new URL('../.work/import/', import.meta.url).pathname;
mkdirSync(csvDir, { recursive: true });
writeFileSync(`${csvDir}northwind-employees.csv`, 'first_name,last_name,personal_email,work_email,joining_date,designation\nKabir,Shah,kabir.shah.home@example.test,kabir.shah@northwind.example,2026-11-16,Retail Associate\nNeelam,Iyer,neelam.iyer.home@example.test,neelam.iyer@northwind.example,2026-11-16,Retail Associate\n');
await go(pg, '/admin/employee-imports');
if (!await pg.locator('tbody tr').count()) {
    await pg.getByRole('button', { name: 'Upload CSV' }).click();
    await idle(pg, 1200);
    await modal(pg).locator('input[type=file]').setInputFiles(`${csvDir}northwind-employees.csv`);
    await idle(pg, 3000);
    await shot(s, pg, 'client-onboarding/90-onb-91-import-upload', { ...W4, screen: 'People › Lifecycle › Employee imports › Upload CSV', action: 'Upload a CSV prepared from the customer’s data (fictional rows)',
        expected: 'The file is stored; the import then goes Inspect → Map columns → Validate → Approve → Run', element: '.fi-modal-window:visible >> nth=-1', callouts: [] });
    await modal(pg).getByRole('button', { name: /Upload|Submit|Create/ }).last().click();
    await idle(pg, 3000);
    console.log('    upload', pg.url(), (await toasts(pg)).join(' / '));
    await closeToasts(pg);
}
await go(pg, '/admin/employee-imports');
await pg.locator('tbody tr').first().getByRole('link').first().click().catch(() => {});
await idle(pg, 2000);
await dump(pg, 'main', 40);
for (const step of ['Inspect file', 'Map columns']) {
    const b = pg.getByRole('button', { name: step });
    if (!await b.count()) continue;
    await b.first().click();
    await idle(pg, 2000);
    if (step === 'Map columns') {
        await shot(s, pg, 'client-onboarding/90-onb-92-import-map-columns', { ...W4, screen: 'Employee import › Map columns', action: 'Choose the PeopleOS field for each column of the file',
            expected: 'Each CSV column mapped to a field; on duplicates: update or skip', element: '.fi-modal-window:visible >> nth=-1', callouts: [] });
        await modal(pg).getByRole('button', { name: /Submit|Save/ }).last().click();
        await idle(pg, 2500);
        const t = (await toasts(pg)).join(' / ');
        console.log('    map', t);
        await shot(s, pg, 'client-onboarding/90-onb-93-import-map-fails', { ...W4, screen: 'Employee import (after Map columns)', action: 'Submit the column mapping',
            expected: `Observed: "${t.trim().slice(0, 120)}" — the mapping is not saved, so the import cannot pass "inspected" from the screen (known defect F25)`, status: 'INCOMPLETE',
            callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Error' }] });
    } else {
        console.log('    inspect', (await toasts(pg)).join(' / '));
        await shot(s, pg, 'client-onboarding/90-onb-92a-import-inspected', { ...W4, screen: 'Employee import (after Inspect file)', action: 'Inspect file', expected: 'Headers and row count read from the file; status inspected', callouts: [] });
    }
    await closeToasts(pg);
}
await finish(s);
