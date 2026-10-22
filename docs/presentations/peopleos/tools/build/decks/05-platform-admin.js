// Deck 5 — Platform administrator (Markedge operator) training.
'use strict';
const common = require('./common');

module.exports = (lib) => {
    const { Deck, has } = lib;
    const d = new Deck({
        num: 5, file: '05-PeopleOS-Platform-Administrator-Training.pptx', short: 'Platform administrator training',
        title: 'Platform Administrator Training',
        subtitle: 'Operate PeopleOS as a SaaS platform: operator sign-in, tenant lifecycle, shadow-mode commercial pages, operations, audit, readiness and offboarding.',
        audience: 'Markedge platform operators (not customer HR administrators)',
    });
    const P = (n) => `platform-admin/${n}`;
    const shot = (name, o) => { if (has(name)) d.shot({ img: name, ...o }); else lib.WARN.push(`deck 5: missing ${name}`); };
    const wide = (name, o) => { if (has(name)) d.wide({ img: name, ...o }); else lib.WARN.push(`deck 5: missing ${name}`); };
    const cmp = (o) => { if (has(o.left.img) && has(o.right.img)) d.compare(o); else lib.WARN.push(`deck 5: missing ${o.left.img} / ${o.right.img}`); };
    const SRC = 'Executed on the training database on 21 Oct 2026 as the fictional operator platform@markedge.local (MFA enrolled through the real set-up screen), acting only on the fictional tenants Northwind Retail and Markedge Technologies (internal workforce); tools/capture/00-operator-mfa.mjs, 90-onboarding-a, 95-platform.mjs, 95b-platform-extra.mjs.';

    d.cover({ shot: P('95-pl-05-tenants'), notes: `SAY: This deck is for Markedge platform operators only. Operators look after the platform and its tenants; they do not run customers' HR.\nEVIDENCE: ${SRC}` });
    d.statusLegend();
    common.statusDetailed(d, 'Release status');
    d.numbered({ title: 'What you will be able to do', eyebrow: 'Learning objectives', items: [
        ['Sign in as an operator with MFA', 'and know why it is enforced'],
        ['Provision, suspend, reactivate and enter tenants', 'with reasons, all audited'],
        ['Read the commercial pages correctly', 'shadow mode: nothing is enforced or billed'],
        ['Watch operations and readiness', 'queues, failed jobs, scheduler, release checks'],
        ['Use offboarding safeguards', 'exports, legal holds, retention, deletion off'],
        ['Know what you must never do', 'and when a second operator is required'],
    ] });
    d.cards({ title: 'Platform operator versus tenant administrator', eyebrow: 'Roles',
        cards: [
            { head: 'Platform operator (Markedge)', body: 'An account flagged as platform administrator, with no tenant. Works on the Platform pages: tenants, readiness, operations, audit, commercial set-up, offboarding, statutory rule verification.', tone: 'info' },
            { head: 'Tenant administrator (customer)', body: 'Tenant Super Admin or Tenant HR Admin inside one tenant. Configures the organisation, people, policies and access. Cannot open Platform pages.', tone: 'info' },
            { head: 'Entering a tenant', body: 'An operator works inside a tenant only through Enter, with a reason and a ticket reference; access ends after 60 minutes and is audited.', tone: 'warn' },
            { head: 'Markedge HR is a tenant', body: 'Markedge\'s own HR runs in an ordinary tenant (Deck 6). Its HR staff cannot open the Platform pages.', tone: 'good' },
        ], notes: 'SOURCE: architecture contract §1 and §6 (platform admins act on a tenant only by explicitly entering it); captures 95-pl-35/36 and internal-operations/96-int-08.' });

    // ---- 1. Sign-in
    d.section(1, 'Operator sign-in', 'Password, then an authenticator code, every time.');
    shot(P('01-operator-sign-in'), { title: 'The same sign-in page as everyone', eyebrow: 'Sign-in', steps: ['Operator e-mail.', 'Password.', 'Sign in.'], result: 'Operators then need their authenticator app.' });
    shot(P('02-mfa-setup-required'), { title: 'No authenticator yet: set-up is forced', eyebrow: 'Sign-in', crop: { x: 640, y: 340, w: 880, h: 670 }, place: { 1: 'right' }, steps: ['Set up.'], result: 'Nothing else opens until an authenticator app is set up. This is a platform setting, on by default.',
        notes: 'SAY: The setting is PEOPLEOS_PLATFORM_MFA_REQUIRED (default true). Readiness fails in production while any active operator has no MFA (release gate 3).' });
    shot(P('03-mfa-setup-modal-redacted'), { title: 'Set up the authenticator app', eyebrow: 'Sign-in', callouts: ['2', '3'], renumber: { 2: 1, 3: 2 }, place: { 3: 'right' }, steps: [['·', 'Type the 6-digit code from the app into the boxes.'], [1, 'Current password.'], [2, 'Next.']], result: 'Recovery codes are shown once afterwards: store them in the Markedge password manager. The QR code and key were covered for this screenshot.',
        source: 'Real screen · Markedge Platform Admin (operator) · QR code and key covered before capture (secret).' });
    shot(P('95-pl-04-platform-home'), { title: 'The Platform home', eyebrow: 'Sign-in', steps: ['Manage tenants.', 'Open readiness.'], result: 'Operators work outside any tenant until they deliberately enter one.' });

    // ---- 2. Tenants
    d.section(2, 'Tenant lifecycle', 'Provision, suspend, reactivate and enter: every action needs a reason.');
    d.cards({ title: 'Provisioning is covered in Deck 4', eyebrow: 'Tenants', cards: [
        { head: 'New tenant', body: 'Platform › Tenants › New tenant: tier, region (metadata), name and slug, locale defaults, first administrator, reason. The administrator is invited; operators never set passwords.' },
        { head: 'Send invitation', body: 'On the Tenants list when a first administrator\'s link expired or the e-mail failed.' },
        { head: 'Duplicates', body: '"The slug has already been taken" and "The email has already been taken": one e-mail belongs to one organisation.' },
    ], notes: 'See Deck 4, stages 2 and 3, for the full provisioning walkthrough on Northwind Retail.' });
    shot(P('95-pl-05-tenants'), { title: 'Tenants', eyebrow: 'Tenants', place: { 1: 'below', 2: 'below' }, steps: ['Suspend.', 'Enter.'], result: 'Name, slug, status, country, users, companies; Enter, Suspend and Edit (Send invitation appears while a first administrator\'s invitation is pending).' });
    shot(P('95-pl-06-suspend-dialog'), { title: 'Suspend a tenant', eyebrow: 'Tenants', steps: ['Reason (goes to the platform audit trail).', 'Confirm.'], result: 'Every user is signed out at once, API keys stop working and scheduled work pauses.', notes: 'AVOID: suspending a live customer without the documented approval. In this training only the fictional Northwind tenant was suspended.' });
    cmp({ title: 'Suspended: the customer cannot sign in', eyebrow: 'Tenants',
        left: { label: 'Tenants list', img: P('95-pl-07-suspended'), callouts: ['2'], caption: 'Northwind shows Suspended; Reactivate replaces Suspend.' },
        right: { label: 'Customer administrator signing in', tone: 'bad', img: P('95-pl-08-suspended-sign-in'), callouts: ['1'], caption: '"These credentials do not match our records." The generic message gives no hint that the tenant is suspended.' },
        notes: 'SUPPORT: a whole customer unable to sign in, with correct passwords, may be a suspended tenant. Check the Tenants list first (Deck 7).' });
    shot(P('95-pl-09-reactivated'), { title: 'Reactivate', eyebrow: 'Tenants', renumber: { 2: 1 }, steps: [[1, 'Active again.']], result: 'Northwind is Active and its users can sign in. Both actions are in the platform audit trail with their reasons.' });
    cmp({ title: 'Entering a tenant for support', eyebrow: 'Tenants',
        left: { label: 'Enter', img: P('95-pl-35-enter-tenant-dialog'), callouts: false, caption: 'Reason and ticket reference required; access ends on its own after 60 minutes.' },
        right: { label: 'Inside the tenant', img: P('95-pl-36-operator-in-tenant'), callouts: ['1'], caption: 'The operator works with the administration lens; the tenant name shows in the date line.' },
        notes: 'OBSERVATION (not verified as a defect): nothing else on screen marks the session as support access. Be explicit with the customer when you enter their tenant.' });

    // ---- 3. Commercial
    d.section(3, 'Commercial pages (shadow mode)', 'Plans, subscriptions, entitlements, billing, tax and payments exist, but nothing is enforced, priced or billed.');
    shot(P('95-pl-10-signups'), { title: 'Signups: public registration is off', eyebrow: 'Commercial', status: 'DISABLED BY SAFETY OR COMMERCIAL GATES', steps: [], result: '"Can signup work today?" lists each prerequisite (switch, offers, terms, privacy notice, review policy) and its state. No applications.', notes: 'NEVER switch signup on to demonstrate it. It needs owner decisions D10-1…D10-11 and production authorisation.' });
    cmp({ title: 'Plans and subscriptions', eyebrow: 'Commercial',
        left: { label: 'Plans', img: P('95-pl-11-plans'), callouts: false, caption: 'Shadow mode; no plan, price or default plan is shipped (decision D-1).' },
        right: { label: 'Subscriptions', img: P('95-pl-12-subscriptions'), callouts: false, caption: 'All tenants listed; nothing is enforced or billed.' } });
    cmp({ title: 'Entitlements and billing catalogue', eyebrow: 'Commercial',
        left: { label: 'Entitlements', img: P('95-pl-13-entitlements'), callouts: false, caption: 'Shadow observations: what would have been blocked if entitlements were enforced.' },
        right: { label: 'Billing catalogue', img: P('95-pl-14-billing-catalogue'), callouts: false, caption: 'Markets, prices, currencies; changes need a second operator\'s approval.' } });
    cmp({ title: 'Tax and commercial policies', eyebrow: 'Commercial',
        left: { label: 'Tax & invoicing', img: P('95-pl-15-tax-invoicing'), callouts: false, caption: 'Jurisdiction support, selling entities, statutory dataset (load = maker, verify = second operator).' },
        right: { label: 'Commercial policies', img: P('95-pl-16-commercial-policies'), callouts: false, caption: 'Markedge policy values and statutory parameters; Propose change (maker-checker).' } });
    cmp({ title: 'Billing accounts, invoices, payments', eyebrow: 'Commercial',
        left: { label: 'Invoices', img: P('95-pl-18-invoices'), callouts: false, caption: 'None: real invoicing waits for owner inputs (B-4, B-7, B-8, B-9).' },
        right: { label: 'Payments', img: P('95-pl-19-payments'), callouts: false, caption: 'Live payments are refused in production; no provider configured.' } });
    shot(P('95-pl-20-financial-approvals'), { title: 'Financial approvals (maker-checker)', eyebrow: 'Commercial', status: 'DISABLED BY SAFETY OR COMMERCIAL GATES', steps: [], result: 'Requests that wait for a second operator. A single operator can never both propose and approve a financial change.' });

    // ---- 4. Operations
    d.section(4, 'Operations, audit and readiness', 'Watch the platform, prove what operators did, and know whether it can go live.');
    wide(P('95-pl-21-operations'), { title: 'Platform operations', eyebrow: 'Operations', caption: 'Database, cache, queue, failed jobs, scheduler heartbeat, integrations and audit chains, with per-tenant status.',
        notes: 'OBSERVE: the scheduler heartbeat is not recent because no scheduler runs in the local environment; in production a stale heartbeat is an incident.' });
    shot(P('95-pl-37-platform-audit-after'), { title: 'Platform audit trail', eyebrow: 'Audit', maxAspect: 0.62, steps: [], result: 'Operator actions across tenants, newest first, each with actor and reason (the top rows here: platform access started and sign-in; the suspend, reactivate and legal-hold events follow further down). Header: "NOT RECENTLY VERIFIED: 0 of 3 chains verified" until scheduled verification runs.' });
    wide(P('95-pl-23-readiness'), { title: 'Platform readiness', eyebrow: 'Readiness', caption: 'Every check with PASS, WARN, FAIL or BLOCKED: configuration, statutory production gate (blocked), operator MFA, demo accounts, backups, scheduler, disaster recovery.',
        notes: 'SAY: Readiness is the operator\'s go-live checklist. Several checks are WARN locally that become FAIL in production. php artisan peopleos:readiness prints the same.' });

    // ---- 5. Offboarding
    d.section(5, 'Offboarding and data protection', 'Exports, legal holds and retention; deletion is switched off.');
    shot(P('95-pl-24-offboarding'), { title: 'Offboarding: deletion is off', eyebrow: 'Offboarding', steps: ['Deletion switched off.'], result: '"Tenant deletion is switched off in this environment (PEOPLEOS_TENANT_DELETION_ENABLED)." No deletion action exists.' });
    shot(P('95-pl-25-offboarding-tenant'), { title: 'One tenant\'s offboarding record', eyebrow: 'Offboarding', steps: ['Place legal hold.', 'Request export.', 'Start offboarding.'], result: 'Retention and deletion eligibility, exports, contract retention, legal holds.' });
    cmp({ title: 'Place a legal hold', eyebrow: 'Offboarding',
        left: { label: 'Place legal hold', img: P('95-pl-26-legal-hold-dialog'), callouts: false, caption: 'Confirm placing the hold. While active, nothing it covers can be deleted.' },
        right: { label: 'Hold active', tone: 'good', img: P('95-pl-27-legal-hold-active'), callouts: ['1'], caption: '"Legal hold placed"; the tenant now shows one active hold (LH-2026-01 in the platform audit trail).' } });
    cmp({ title: 'Releasing a hold needs a second operator', eyebrow: 'Offboarding',
        left: { label: 'Request release', img: P('95-pl-33-request-release'), callouts: false, caption: '"Release requested: another operator approves it."' },
        right: { label: 'Release pending', img: P('95-pl-34-release-pending'), callouts: ['1'], caption: 'The hold stays active until a different operator approves. Only one operator exists here, so it stays pending.' } });
    cmp({ title: 'Exports and retention', eyebrow: 'Offboarding',
        left: { label: 'Request export', img: P('95-pl-32-request-export-dialog'), callouts: false, caption: 'Scope and reason; produced in the background (needs a queue worker) and protected by a passphrase. Opened, not submitted.' },
        right: { label: 'Retention policies', img: P('95-pl-28-retention-policies'), callouts: false, caption: 'None in force: nothing is deletable until Markedge decides (D-8). New versions are maker-checker.' } });

    // ---- 6. Statutory
    d.section(6, 'Statutory rule stewardship', 'Markedge verifies the rules payroll depends on: two operators and official evidence.');
    shot(P('95-pl-29-compliance-rules'), { title: 'Compliance rules (operator view)', eyebrow: 'Statutory', status: 'IMPLEMENTED BUT NOT VERIFIED END-TO-END', steps: [], result: 'Each rule version with parameters, verification history, evidence and coverage; Attach evidence and Submit for verification. 0 of 24 verified.', notes: 'SAY: Verification needs official evidence and a second operator. It was not exercised in this training (no evidence available). Release gate 5.' });
    cmp({ title: 'Notices and export layouts', eyebrow: 'Statutory',
        left: { label: 'Regulatory notices', img: P('95-pl-30-rule-notices'), callouts: false, caption: 'Open notices about statutory changes, each to be resolved.' },
        right: { label: 'Export layouts', img: P('95-pl-31-export-layouts'), callouts: false, caption: 'Return file layouts with their specification and approval actions.' } });

    d.cards({ title: 'Operators must never…', eyebrow: 'Safety rules', cards: [
        { head: 'Switch on gated features', body: 'Public signup, live payments, tenant deletion or their environment switches, to "test" them.', tone: 'bad' },
        { head: 'Share or skip MFA', body: 'Every operator has their own account and authenticator; recovery codes stay in the password manager.', tone: 'bad' },
        { head: 'Act without a reason', body: 'Suspend, enter, hold or export without the customer reference in the reason field.', tone: 'bad' },
        { head: 'Approve their own change', body: 'Financial changes, legal-hold releases and rule verification need a second operator.', tone: 'warn' },
        { head: 'Edit customer HR data', body: 'Entering a tenant is for support; changes belong to the customer\'s administrators.', tone: 'warn' },
        { head: 'Bypass a gate', body: 'Payroll finalisation, release gates and readiness FAILs are fixed at the source, never worked around.', tone: 'warn' },
    ] });
    d.exercise({ title: 'Practice (on a training environment only)', tasks: [
        'A customer says nobody can sign in, although passwords are correct. Where do you look first?',
        'You placed a legal hold by mistake. Can you release it yourself?',
        'Where do you see that an operator entered a customer\'s tenant, and why?',
        'The Readiness page shows "Statutory production gate: BLOCKED". Who resolves it?',
    ], notes: 'ANSWERS: (1) Platform › Tenants: is the tenant Suspended? (generic sign-in message hides it). (2) No: request release; a different operator approves. (3) Platform audit trail: "Tenant entered" with actor, reason and ticket. (4) Two platform administrators verifying each statutory rule with official evidence (owner decisions O-5, O-12, O-18).' });
    d.table({ title: 'Answer key', eyebrow: 'Practice', columns: ['#', 'Answer'], colW: [0.6, 11.53], rows: [
        ['1', 'Platform › Tenants: check whether the tenant is Suspended. The sign-in page deliberately shows the generic message.'],
        ['2', 'No. Request release; a different operator must approve it (two-person rule).'],
        ['3', 'Platform audit trail: "Tenant entered" with the operator, the reason and the ticket reference.'],
        ['4', 'Markedge: two platform administrators verify each rule with official evidence, after the owner decisions on scope (O-5, O-12, O-18).'],
    ] });
    d.closing({ points: ['Operators sign in with MFA and work outside tenants by default.', 'Suspend, reactivate, enter, hold and export always carry a reason and are audited.', 'Commercial pages are in shadow mode: nothing is enforced, priced or billed.', 'Readiness and operations tell you whether the platform can be trusted today.', 'Deletion is off; holds and releases follow the two-person rule.'],
        next: ['docs/saas/SaaS-Final-Release-Gate.md', 'docs/saas/SaaS-Final-Owner-Action-Register.md', 'Deck 04: provisioning a customer', 'Deck 08: security and compliance'], notes: `EVIDENCE: ${SRC}` });
    return d;
};
