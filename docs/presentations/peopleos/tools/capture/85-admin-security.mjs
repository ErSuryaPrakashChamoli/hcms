// Tenant administration and security configuration (Decks 4 and 8) in the Demo Group tenant: Admin Centre, configuration
// finder, packs, users (one invitation EXECUTED), roles and scope, security policy, flags, settings, Change Centre,
// customisation, workflows, policies, notifications, SSO / API keys / webhooks / integration hub (forms opened only),
// country packs, data export dialog (not submitted). Tenant-wide settings are opened, never saved.
import { start, open, go, shot, finish, idle, modal, toasts, closeToasts, cancelModal } from './lib.mjs';

const s = await start('85-admin-security');
const ONLY = process.env.ONLY ? process.env.ONLY.split(',') : null;
const step = async (name, fn) => { if (ONLY && !ONLY.includes(name)) return; try { await fn(); } catch (e) { console.log(`  ✗ ${name}: ${String(e).split('\n')[0].slice(0, 200)}`); } };
const CFG = { deck: [4], workflow: 'Tenant configuration (Admin Centre)', prereq: 'Tenant HR Admin or Account Owner / Tenant Super Admin' };
const ACC = { deck: [4, 8], workflow: 'Users, roles and access scope', prereq: 'user.manage / role.view (role editing: Tenant Super Admin only, F5)' };
const SEC = { deck: [8, 4], workflow: 'Security policy and integrations', prereq: 'Tenant HR Admin / Account Owner (security policy); Tenant HR Admin (SSO, API keys, webhooks, integration hub)' };
const WF = { deck: [4, 3], workflow: 'Workflows, policies and notifications', prereq: 'workflow.manage / policy.manage / notification.manage' };

const adm = await open(s, 'admin', '/admin/admin-centre');
await step('admin centre', () => shot(s, adm, 'client-onboarding/85-cfg-01-admin-centre', { ...CFG, deck: [4, 3], screen: 'Admin › All modules (Admin Centre)', action: 'Open the Admin Centre',
    expected: 'Everything to manage, grouped: organisation, people data, joining/letters/exit, time and leave, pay and compliance, growth, workflows, service, access and security, integrations; Awaiting approval; Platform health',
    callouts: [{ loc: adm.getByText(/Find a setting/i), n: 1, label: 'Find a setting' }, { loc: adm.getByText(/Awaiting approval/i), n: 2, label: 'Awaiting approval' }], fullPage: true }));
await step('finder', async () => {
    await go(adm, '/admin/configuration-finder');
    const box = adm.locator('input[type=search][placeholder*="working hours"]');
    if (await box.count()) { await box.fill('leave'); await idle(adm, 1500); }
    await shot(s, adm, 'client-onboarding/85-cfg-02-configuration-finder', { ...CFG, screen: 'Admin › Setup › What do you want to configure?', action: 'Search the configuration finder for "leave"',
        expected: 'Matching modules and individual settings, each linked to the screen where it is changed', callouts: [{ loc: box, n: 1, label: 'Search' }], fullPage: true });
});
await step('packs', async () => {
    await go(adm, '/admin/configuration-packs');
    await shot(s, adm, 'client-onboarding/85-cfg-03-packs-blueprints', { ...CFG, screen: 'Admin › Setup › Packs & Blueprints', action: 'Open Packs & Blueprints (nothing applied)',
        expected: 'Available configuration packs; Apply a pack, Export blueprint, Import blueprint (blueprints copy configuration between tenants)',
        callouts: [{ loc: adm.getByRole('button', { name: 'Apply a pack' }), n: 1, label: 'Apply a pack' }, { loc: adm.getByRole('button', { name: 'Export blueprint' }), n: 2, label: 'Export blueprint' }, { loc: adm.getByRole('button', { name: 'Import blueprint' }), n: 3, label: 'Import blueprint' }], fullPage: true });
});

// Users and roles.
await step('users list', async () => {
    await go(adm, '/admin/users');
    await shot(s, adm, 'client-onboarding/85-acc-01-users', { ...ACC, screen: 'Admin › Setup › Users', action: 'Open Users', expected: 'Every sign-in with roles, status and last sign-in; New user',
        callouts: [{ loc: adm.getByText('New user', { exact: true }), n: 1, label: 'New user' }, { loc: adm.locator('th').filter({ hasText: /Last login/i }), n: 2, label: 'Last sign-in' }] });
});
await step('invite user', async () => {
    await go(adm, '/admin/users');
    if (await adm.locator('tbody tr').filter({ hasText: 'training.auditor@demo.local' }).count()) { console.log('    (already invited)'); return; }
    await go(adm, '/admin/users/create');
    await adm.locator('[id="form.name"]').fill('Training Auditor');
    await adm.locator('[id="form.email"]').fill('training.auditor@demo.local');
    await adm.locator('button', { hasText: 'Select an option' }).first().click();
    await idle(adm, 600);
    await adm.keyboard.type('Auditor');
    await idle(adm, 1200);
    await adm.locator('[role=option]').filter({ hasText: /^\s*Auditor\s*$/ }).first().click();
    await idle(adm, 800);
    await adm.keyboard.press('Tab');
    await idle(adm, 500);
    await shot(s, adm, 'client-onboarding/85-acc-02-new-user-form', { ...ACC, screen: 'Admin › Setup › Users › New user', action: 'Name, e-mail, role Auditor; access scope left empty (tenant-wide)',
        expected: 'Roles are tenant-wide; Access scope (companies, locations, departments, business units, establishments) restricts which people and units the user reaches',
        callouts: [{ loc: adm.locator('[id="form.name"]'), n: 1, label: 'Name' }, { loc: adm.locator('[id="form.email"]'), n: 2, label: 'E-mail' }, { loc: adm.getByText('Tenant-wide role assignments', { exact: false }), n: 3, label: 'Roles' }, { loc: adm.getByText(/Access scope/i), n: 4, label: 'Access scope' }, { loc: adm.getByRole('button', { name: 'Create user' }), n: 5, label: 'Create user' }], fullPage: true });
    await closeToasts(adm);
    await adm.getByRole('button', { name: 'Create user' }).first().click();
    await idle(adm, 2500);
    if (await adm.locator('.fi-modal-window:visible').count()) {
        await shot(s, adm, 'client-onboarding/85-acc-03-new-user-review', { ...ACC, screen: 'New user › Review before creating', action: 'Create user', expected: 'A review of what will be created before it is recorded', element: '.fi-modal-window:visible >> nth=-1' });
        await modal(adm).locator('.fi-modal-footer-actions button, button[type=submit]').filter({ hasNotText: 'Cancel' }).first().click();
        await idle(adm, 2500);
    }
    console.log('    toast:', (await toasts(adm)).join(' / '));
    await shot(s, adm, 'client-onboarding/85-acc-04-user-invited', { ...ACC, screen: 'User (after Create user)', action: 'Create user (executed)', expected: 'The account is created as Invited and an invitation e-mail is sent; the person sets a password from the link',
        callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Result' }] });
});
await step('users list invited', async () => {
    await go(adm, '/admin/users');
    const sbox = adm.locator('main input[type=search]').first();
    await sbox.fill('training.auditor'); await idle(adm, 1500);
    await shot(s, adm, 'client-onboarding/85-acc-05-user-invited-status', { ...ACC, screen: 'Admin › Setup › Users (filtered)', action: 'Find the new user', expected: 'Status Invited until the person activates the account from the e-mail link',
        callouts: [{ loc: adm.locator('tbody tr').filter({ hasText: 'training.auditor' }), n: 1, label: 'Invited user' }] });
});
await step('user scope', async () => {
    await go(adm, '/admin/users');
    const sbox = adm.locator('main input[type=search]').first();
    await sbox.fill('amit.verma'); await idle(adm, 1500);
    await adm.locator('tbody tr').filter({ hasText: 'amit.verma' }).getByText('Edit', { exact: true }).first().click();
    await idle(adm, 2000);
    await shot(s, adm, 'security-compliance/85-acc-06-user-roles-scope', { ...ACC, deck: [8, 4, 2], screen: 'Admin › Setup › Users › Edit (Amit Verma)', action: 'Open a manager’s account',
        expected: 'Roles Employee + Manager; Access scope / relationship scope limit the manager to the Platform team and the reporting line; account actions (resend invitation, suspend, reset)', fullPage: true });
});
await step('roles list', async () => {
    await go(adm, '/admin/roles');
    await shot(s, adm, 'security-compliance/85-acc-07-roles', { ...ACC, deck: [8, 4], screen: 'Admin › Setup › Roles', action: 'Open Roles',
        expected: '14 system roles (and any custom roles) with permission and user counts', callouts: [{ loc: adm.locator('th').filter({ hasText: /Permissions/i }), n: 1, label: 'Permissions' }, { loc: adm.locator('th').filter({ hasText: /Users/i }), n: 2, label: 'Users' }] });
});
await step('role view hr admin', async () => {
    await go(adm, '/admin/roles');
    await adm.locator('tbody tr').filter({ hasText: 'HR Manager' }).first().click();
    await idle(adm, 2000);
    await shot(s, adm, 'security-compliance/85-acc-08-role-readonly-hr-admin', { ...ACC, deck: [8, 4], screen: 'Roles › HR Manager (as Tenant HR Admin)', action: 'Tenant HR Admin opens a role',
        expected: 'Permissions are shown read only: the Tenant HR Admin holds role.view only; editing role permissions needs the Tenant Super Admin (F5)', fullPage: true });
});
const sup = await open(s, 'superadmin', '/admin/roles');
await step('role edit superadmin', async () => {
    await sup.locator('tbody tr').filter({ hasText: 'HR Manager' }).first().click();
    await idle(sup, 2000);
    await sup.setViewportSize({ width: 1440, height: 1200 });
    await shot(s, sup, 'security-compliance/85-acc-09-role-permissions-edit', { ...ACC, deck: [8, 4], persona: 'superadmin', screen: 'Roles › HR Manager (as Tenant Super Admin)', action: 'Open the role’s permissions (not saved)',
        expected: 'Permission catalogue grouped by module with checkboxes (the page continues for every module); changes are audited (ROLE_CHANGED with granted/removed keys); a custom role can be given only by someone holding all its permissions' });
});

// Security and settings.
await step('security policy', async () => {
    await go(adm, '/admin/security-policy-page');
    await shot(s, adm, 'security-compliance/85-sec-01-security-policy', { ...SEC, screen: 'Admin › Platform › Security policy', action: 'Open the Security & retention policy (viewed, not saved)',
        expected: 'Access (require MFA, idle timeout, sessions), passwords, retention days (audit events are never purged), data warehouse feed. Multi-factor is Optional in this tenant',
        note: '"Expire after days" is stored but not enforced (F2). Not saved, so no user was forced into MFA set-up.', fullPage: true });
});
await step('feature flags', async () => {
    await go(adm, '/admin/tenant-features');
    await shot(s, adm, 'client-onboarding/85-cfg-04-feature-flags', { ...CFG, deck: [4, 8], screen: 'Admin › Setup › Feature flags', action: 'Open Feature flags (viewed only)',
        expected: 'Modules and capabilities switched on or off per tenant (e.g. ai.llm off)', note: 'Description column shows "—"; organisation.designer and security.mfa flags have no effect (F31).', fullPage: true });
});
await step('settings', async () => {
    await go(adm, '/admin/tenant-settings');
    await shot(s, adm, 'client-onboarding/85-cfg-05-settings', { ...CFG, screen: 'Admin › Setup › Settings', action: 'Open Settings (viewed only)',
        expected: 'Tenant setting keys and values (attendance windows, probation, documents reminders, compensation self-service…)', note: 'Several keys have no effect (F30).' });
});
await step('change centre', async () => {
    await go(adm, '/admin/configuration-changes');
    await shot(s, adm, 'client-onboarding/85-cfg-06-change-centre', { ...CFG, deck: [4, 8], screen: 'Admin › Setup › Change Centre', action: 'Open the Change Centre',
        expected: 'Configuration changes pending approval, scheduled, published, rejected; empty while configuration approval is off',
        note: 'Only the main edit form of certain records waits for approval; settings, flags, creates and deletes apply immediately; no self-approval guard (F29).', status: 'AVAILABLE ONLY AFTER CONFIGURATION' });
});
await step('custom fields', async () => {
    await go(adm, '/admin/custom-fields');
    await shot(s, adm, 'client-onboarding/85-cfg-07-custom-fields', { ...CFG, screen: 'Admin › Setup › Custom fields', action: 'Open Custom fields', expected: 'Extra fields per record type (e.g. Employee › Laptop asset tag)',
        note: 'The visible-to switches are saved but hide nothing (F32).' });
});
await step('forms', async () => {
    await go(adm, '/admin/forms');
    await shot(s, adm, 'client-onboarding/85-cfg-08-forms', { ...CFG, screen: 'Admin › Setup › Forms', action: 'Open Forms', expected: 'Configurable forms with versions (e.g. IT asset handover)' });
});

// Workflows, policies, notifications.
await step('workflows', async () => {
    await go(adm, '/admin/workflows');
    await shot(s, adm, 'client-onboarding/85-wf-01-workflows', { ...WF, screen: 'Workflows › Workflows', action: 'Open Workflows', expected: 'Workflow definitions with trigger, subject, version and status; New workflow',
        callouts: [{ loc: adm.locator('tbody tr').filter({ hasText: 'Probation confirmation' }), n: 1, label: 'Workflow' }, { loc: adm.getByText('New workflow', { exact: true }), n: 2, label: 'New workflow' }] });
    await adm.locator('tbody tr').filter({ hasText: 'Probation confirmation' }).getByText('Edit').first().click();
    await idle(adm, 2000);
    await shot(s, adm, 'client-onboarding/85-wf-02-workflow-edit', { ...WF, screen: 'Workflow › Edit (Probation confirmation)', action: 'Open the workflow',
        expected: 'Name, key, trigger event, subject, start conditions; New draft, Start a run; tabs Versions & builder, Runs, History', fullPage: true,
        note: 'The "Webhook signing secret" action was not opened (secret).' });
    const tab = adm.locator('button, [role=tab]').filter({ hasText: /Versions & builder/ }).first();
    await tab.click(); await idle(adm, 2000);
    await adm.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    await idle(adm, 800);
    await shot(s, adm, 'client-onboarding/85-wf-03-workflow-versions', { ...WF, deck: [4, 10], screen: 'Workflow › Versions & builder', action: 'Open Versions & builder',
        expected: 'Versioned definitions: a published version is immutable; changes are made in a new draft and published; running instances keep their version' });
});
await step('workflow runs', async () => {
    await go(adm, '/admin/workflow-instances');
    await shot(s, adm, 'client-onboarding/85-wf-04-workflow-runs', { ...WF, screen: 'Workflows › Runs', action: 'Open Workflow runs', expected: 'Running and completed workflow instances (none yet in this tenant)' });
});
await step('policies', async () => {
    await go(adm, '/admin/policies');
    await shot(s, adm, 'client-onboarding/85-wf-05-policies', { ...WF, deck: [4, 3], screen: 'Workflows › Policies', action: 'Open Policies',
        expected: 'Exit, attendance, working hours and leave policies with effective versions', callouts: [{ loc: adm.locator('tbody tr').filter({ hasText: 'Standard leave' }), n: 1, label: 'Leave policy' }] });
    await adm.locator('tbody tr').filter({ hasText: 'Standard leave' }).getByText('Edit').first().click();
    await idle(adm, 2000);
    await adm.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    await idle(adm, 2500);
    await adm.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    await idle(adm, 1500);
    await shot(s, adm, 'client-onboarding/85-wf-06-leave-policy', { ...WF, deck: [4, 3], screen: 'Policies › Standard leave', action: 'Open the leave policy (not saved)',
        expected: 'Policy header (type Leave policy, name, code, status) and its effective-dated versions; a change is made as a new draft version (New draft) with a reason', fullPage: true });
});
await step('notification rules', async () => {
    await go(adm, '/admin/notification-rules');
    await shot(s, adm, 'client-onboarding/85-wf-07-notification-rules', { ...WF, deck: [4, 7], screen: 'Communication › Notification rules', action: 'Open Notification rules',
        expected: 'One rule: "Welcome every joiner" (employee.joined → employee and line manager, in-app + e-mail). No New rule button for any tenant role (F18), so leave and approval events send no e-mail unless rules exist',
        status: 'INCOMPLETE', callouts: [{ loc: adm.locator('tbody tr').filter({ hasText: 'Welcome every joiner' }), n: 1, label: 'Only rule' }] });
});
await step('notification templates', async () => {
    await go(adm, '/admin/notification-templates');
    await shot(s, adm, 'client-onboarding/85-wf-08-notification-templates', { ...WF, screen: 'Communication › Notification templates', action: 'Open Notification templates', expected: 'Template with placeholders ({{ tenant.name }}, {{ employee.first_name }}); edit only (F18)', status: 'INCOMPLETE' });
});
await step('delivery log', async () => {
    await go(adm, '/admin/notification-deliveries');
    await shot(s, adm, 'client-onboarding/85-wf-09-delivery-log', { ...WF, deck: [4, 7], screen: 'Communication › Delivery log', action: 'Open the Delivery log',
        expected: 'Every notification delivery with channel, event, status (sent / queued / failed) and View; failed deliveries can be sent again',
        callouts: [{ loc: adm.getByRole('button', { name: /^Queued/ }), n: 1, label: 'Queued' }], note: 'E-mail deliveries stay queued unless a queue worker runs (none runs in this environment).' });
});

// Integrations (forms opened only; no key, secret or connection created).
await step('sso', async () => {
    await go(adm, '/admin/sso-connections/create');
    await shot(s, adm, 'security-compliance/85-sec-02-sso-form', { ...SEC, screen: 'Admin › Platform › Single sign-on › New', action: 'Open the New SSO connection form (not saved)',
        expected: 'OpenID Connect provider details, default role, domains; SAML is not supported (OIDC only)', note: '"Show SSO as the primary login" has no visible effect (F34). Client secret field left empty.', fullPage: true });
});
await step('api keys', async () => {
    await go(adm, '/admin/api-keys');
    await adm.getByRole('button', { name: 'Issue key' }).first().click();
    await idle(adm, 1500);
    await shot(s, adm, 'security-compliance/85-sec-03-api-key-form', { ...SEC, deck: [8, 10], screen: 'Admin › Platform › API keys › Issue key', action: 'Open the Issue key dialog (cancelled; no key issued)',
        expected: 'Name, scopes, expiry; the token is shown once after issuing', note: 'Scopes are not limited to the issuing administrator’s own permissions (F37, security finding). Dialog cancelled.',
        ...(await adm.locator('.fi-modal-window:visible').count() ? { element: '.fi-modal-window:visible >> nth=-1' } : { fullPage: true }) });
    if (await adm.locator('.fi-modal-window:visible').count()) await cancelModal(adm);
});
await step('webhooks', async () => {
    await go(adm, '/admin/webhook-endpoints/create');
    await shot(s, adm, 'security-compliance/85-sec-04-webhook-form', { ...SEC, deck: [8, 10], screen: 'Admin › Platform › Webhooks › New', action: 'Open the New webhook endpoint form (not saved)',
        expected: 'URL, events and signing; deliveries are signed so the receiver can verify them', fullPage: true });
});
await step('integration hub', async () => {
    await go(adm, '/admin/integration-systems/create');
    await shot(s, adm, 'security-compliance/85-sec-05-integration-hub-form', { ...SEC, deck: [10, 8], screen: 'Admin › Platform › Integration hub › New', action: 'Open the New integration system form (not saved)',
        expected: 'External systems are registered here with their identifiers and mappings; the Integration Hub is the only boundary to other products', fullPage: true });
});
await step('inbound events', async () => {
    await go(adm, '/admin/inbound-events');
    await shot(s, adm, 'security-compliance/85-sec-06-inbound-events', { ...SEC, deck: [10], screen: 'Admin › Platform › Inbound events', action: 'Open Inbound events', expected: 'Events received from external systems with processing status (none yet)' });
});
await step('country packs', async () => {
    await go(adm, '/admin/country-packs-page');
    await shot(s, adm, 'client-onboarding/85-cfg-09-country-packs', { ...CFG, deck: [4, 10], screen: 'Admin › Platform › Country packs', action: 'Open Country packs (read only)',
        expected: 'IN, AE, GB, US, SG, AU, CA packs and what each contains; only India (23 rules) and UAE (1) have statutory rules — payroll is blocked in jurisdictions without rules', fullPage: true });
});
await step('data export', async () => {
    await go(sup, '/admin/data-export');
    await sup.getByRole('button', { name: 'Request a full export' }).first().click();
    await idle(sup, 1500);
    await modal(sup).locator('textarea').first().fill('Annual records review (training example)');
    await shot(s, sup, 'security-compliance/85-sec-07-data-export-request', { ...SEC, deck: [8, 5], persona: 'superadmin', workflow: 'Tenant data export', screen: 'Admin › Platform › Data export › Request a full export',
        action: 'Open the request dialog with a reason (cancelled, not submitted)', prereq: 'Account Owner / Tenant Super Admin',
        expected: 'An encrypted package is prepared in the background, downloaded here and opened with a passphrase shown once; credentials and secrets are never included',
        note: 'Not submitted: generation would display a one-time passphrase (secret).', element: '.fi-modal-window:visible >> nth=-1' });
    await cancelModal(sup);
});

await finish(s);
