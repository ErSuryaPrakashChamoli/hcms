// Deck 8 — Security, governance and compliance.
'use strict';
const common = require('./common');

module.exports = (lib) => {
    const { Deck, has, find } = lib;
    const d = new Deck({
        num: 8, file: '08-PeopleOS-Security-Governance-and-Compliance.pptx', short: 'Security, governance and compliance',
        title: 'Security, Governance and Compliance',
        subtitle: 'The controls PeopleOS enforces, how they look on screen, what each customer must configure, what operations must provide, and which risks remain open.',
        audience: 'Security and compliance reviewers, customer IT and HR governance leads, Markedge operators',
    });
    const S = (n) => `security-compliance/${n}`;
    const shot = (name, o) => { if (has(name)) d.shot({ img: name, ...o }); else lib.WARN.push(`deck 8: missing ${name}`); };
    const cmp = (o) => { if (has(o.left.img) && has(o.right.img)) d.compare(o); else lib.WARN.push(`deck 8: missing ${o.left.img} / ${o.right.img}`); };
    const first = (...n) => n.find((x) => has(x));

    d.cover({ shot: S('80-ins-11b-audit-integrity'), notes: 'SAY: A control is only as good as its configuration and the environment that runs it. This deck separates the four: implemented in code, configured in a tenant, operational prerequisite, unresolved risk.\nSOURCES: docs/architecture/security-invariants.md, peopleos-architecture-contract.md §6 and §9, docs/saas/SaaS-Final-Security-Evidence.md, docs/reports/documentation-gaps.md, captures listed per slide.' });
    d.statusLegend();
    common.statusDetailed(d, 'Release status');
    d.numbered({ title: 'What you will be able to assess', eyebrow: 'Learning objectives', items: [
        ['Who can do what', 'roles, permissions, scope, relationship reach'],
        ['How tenants are isolated', 'fail-closed scoping; operator entry is deliberate and audited'],
        ['How sensitive data is protected', 'field permissions, masking, encryption'],
        ['How changes are proven', 'append-only audit with a verified hash chain'],
        ['Where separation of duties applies', 'documents, letters, compensation, payroll, holds, finance'],
        ['What remains open', 'findings, gates and operational prerequisites'],
    ] });
    d.table({ title: 'Control map: built, configured, operated, open', eyebrow: 'Overview', columns: ['Control', 'Implemented in code', 'Configured per tenant', 'Operational prerequisite', 'Open risk'], colW: [2.3, 2.6, 2.4, 2.4, 2.43], rows: [
        ['Tenant isolation', 'Fail-closed tenant scope; architecture tests', '—', 'Single database; monitoring', 'Independent review not engaged (gate 4)'],
        ['Roles and scope', '14 system roles; org and relationship scope', 'Role templates; HR scope', 'Quarterly access review', 'Unscoped HR users reach the whole tenant'],
        ['Field protection', 'Sensitive permissions, masking, encryption', 'Who gets *.sensitive.*', 'Key management', 'Custom-field visibility switches have no effect (F32)'],
        ['MFA', 'Authenticator app; operators forced', 'Tenant policy (Optional by default)', 'Operators enrol in production', 'MFA optional for tenant users unless set'],
        ['Audit', 'Append-only, hash chain, verifier', '—', 'Scheduled verification', 'Chains "not recently verified" without a scheduler'],
        ['Separation of duties', 'Documents, letters, compensation, payroll, holds, finance', 'Enough distinct people per role', 'Second operator', 'Change Centre: no self-approval guard (F29)'],
        ['Integrations', 'Signed webhooks, scoped keys, idempotency', 'Keys, endpoints, SSO (OIDC)', 'Secret storage, rotation', 'Key scopes not limited by issuer (F37)'],
        ['Export, retention, deletion', 'Encrypted export, holds, deletion executor', 'Retention policies (none)', 'Register disk, backup, DR', 'Deletion off; DR not verified (gate 7)'],
    ], fontSize: 11, rowsPerSlide: 8, notes: 'SOURCE: architecture contract §1, §6, §9, §10; security-invariants.md; documentation-gaps F29, F32, F37; release gate rows 3, 4, 6, 7, 11.' });

    // ---- 1. Roles
    d.section(1, 'Roles and permissions', 'Permission keys are declared in code; roles are templates a tenant can adjust.');
    shot(S('85-acc-07-roles'), { title: 'Fourteen system roles', eyebrow: 'Roles', steps: ['Permission counts.', 'User counts.'], result: 'The first 9 of 14 are visible. All 14: Account Owner, Tenant Super Admin, Tenant HR Admin, HR Manager, HR Executive, Payroll Admin, Attendance Admin, Performance Admin, Asset Admin, Manager, Employee, Executive, Alumni, Auditor.' });
    shot(S('85-acc-09-role-permissions-edit'), { title: 'What a role may do', eyebrow: 'Roles', maxAspect: 0.6, steps: [], result: 'Permission catalogue grouped by module. Changes are audited (ROLE_CHANGED with granted and removed keys). A role can be given only by someone who holds all its permissions.', notes: 'SOURCE: SaaS.10 RoleGrants delegation ceiling; SaaS.11 role permission change audit.' });
    shot(S('85-acc-08-role-edit-denied-hr-admin'), { title: 'Only a Tenant Super Admin edits roles', eyebrow: 'Roles', steps: ['Access refused.'], result: 'A Tenant HR Admin opening a role\'s edit page gets "This page is not available to you" (403). Intended or not, record it in your role design (F5).' });
    d.table({ title: 'Role-template findings to review', eyebrow: 'Roles', columns: ['Ref', 'Finding', 'Effect'], colW: [0.8, 6.2, 5.13], rows: [
        ['F3', 'Performance Admin template has only view permissions', 'Cannot administer cycles as the name suggests'],
        ['F4', 'Auditor ("read-only") includes webhook.manage', 'A read-only role can change integrations'],
        ['F5', 'Tenant HR Admin has role.view only', 'Only the Tenant Super Admin can change permissions'],
        ['F15', 'Payroll Admin cannot open statutory return screens', 'Returns need a Tenant HR Admin'],
        ['F24', 'HR Manager has no performance, learning or talent admin', 'Growth administration sits with Tenant HR Admin'],
        ['F45', 'Executive role has no analytics dataset permission', 'Executive dashboards are empty'],
    ], notes: 'SOURCE: docs/reports/documentation-gaps.md (config/peopleos.php role templates). Customers can adjust templates; fixes to the shipped templates go through the template_keys mechanism (SaaS.12).' });

    // ---- 2. Tenancy
    d.section(2, 'Tenant isolation', 'Data from one organisation never appears in another; operators cross only deliberately.');
    d.diagram({ title: 'Isolation by default', eyebrow: 'Tenant isolation', flow: [['Signed-in user', 'belongs to one tenant'], ['Tenant context', 'bound per request'], ['Every query', 'filtered; no tenant → no rows'], ['Writes', 'stamped and checked']],
        caption: 'One e-mail address belongs to one organisation. Platform operators have no tenant; they enter one explicitly with a reason and a ticket, for at most 60 minutes, and every action is recorded in the platform audit trail. Architecture tests fail the build if a model escapes tenant scoping or a service bypasses it outside the allow-list.',
        notes: 'SOURCE: architecture contract §1; ArchitectureTest (tenant scoping, bypass allow-list). Isolation has been tested on the workstation (189 isolation/authorisation tests at SaaS.11); an independent review has not been engaged (gate 4).' });
    cmp({ title: 'Boundaries in practice', eyebrow: 'Tenant isolation',
        left: { label: 'Operator enters a tenant', img: 'platform-admin/95-pl-35-enter-tenant-dialog', callouts: false, caption: 'Reason and ticket reference; ends after 60 minutes; audited.' },
        right: { label: 'Markedge HR opening Tenants', tone: 'bad', img: 'internal-operations/96-int-08-hr-cannot-open-tenants', callouts: false, caption: 'Refused: tenant users, even Markedge\'s own HR, cannot reach the platform.' } });

    // ---- 3. Scope
    d.section(3, 'Organisation and relationship scope', 'HR reach can be limited to companies or units; managers reach their reports.');
    cmp({ title: 'Scope on the user record', eyebrow: 'Scope',
        left: { label: 'HR Manager limited to one company', img: S('85-acc-06b-user-company-scope'), callouts: ['1', '2'], caption: 'Access scope › Companies = Demo Technologies: she reaches only that company\'s people and units. Dimensions combine with AND.' },
        right: { label: 'Manager', img: S('85-acc-06-user-roles-scope'), callouts: false, caption: 'Employee + Manager roles; organisation scope empty. His Platform-team scope is a team dimension the form does not display.' },
        notes: 'RISK: HR users with no scope rows reach the whole tenant by design; Home warns "people approve or edit employees across the whole tenant". Review scope at every access review.' });
    const mgrDenied = first('manager/40-team-09-outside-team');
    const empDenied = first('employee/30-self-52-restricted-page');
    if (mgrDenied && empDenied) cmp({ title: 'Restricted pages for managers and employees', eyebrow: 'Scope',
        left: { label: 'Manager: someone outside the team', tone: 'bad', img: mgrDenied, callouts: false, caption: '"We could not find that" (404): a manager cannot open, or even confirm, a record outside their reach.' },
        right: { label: 'Employee: an HR page', tone: 'bad', img: empDenied, callouts: false, caption: 'Employees see "This page is not available to you" on HR-only pages.' } });

    // ---- 4. Fields
    d.section(4, 'Sensitive data', 'Bank, identifiers, salary and confidential records need separate permissions.');
    d.table({ title: 'Data classification (from configuration)', eyebrow: 'Sensitive data', columns: ['Class', 'Examples', 'Protection'], colW: [2.4, 5.6, 4.13], rows: [
        ['Highly sensitive', 'Bank account, PAN, Aadhaar reference, UAN, PF, ESIC, secrets', 'Encrypted at rest; masked; *.sensitive.* to view, edit, export or search'],
        ['Financial', 'Salary, payroll entries, payslips, settlements', 'Domain permissions; payslip views audited'],
        ['Statutory', 'Tax declarations, statutory profiles', 'Payroll and HR permissions'],
        ['Confidential', 'Grievances, improvement plans, one-on-ones, background checks, documents', 'Relationship and domain permissions; documents via 10-minute signed links'],
    ], notes: 'SOURCE: config/peopleos.php data_classification; architecture contract §6 field-level security; ArchitectureTest "masks every highly sensitive attribute". A Tenant Administrator does not automatically hold *.sensitive.* permissions.' });
    cmp({ title: 'Two HR roles, two sets of record tabs', eyebrow: 'Sensitive data',
        left: { label: 'Tenant HR Admin', img: S('50-sec-01-360-records-tenant-hr-admin'), callouts: ['1'], caption: 'Includes Growth and Bank & personal.' },
        right: { label: 'HR Manager', img: S('50-sec-02-360-records-hr-manager'), callouts: ['1'], caption: 'No Growth; Personal instead of Bank & personal.' } });
    const mgr360 = first('manager/40-team-08-member-personal');
    if (mgr360 && has('hr-admin/50-360-09-records-bank-personal')) cmp({ title: 'Same person, different reach', eyebrow: 'Sensitive data',
        left: { label: 'HR view: Bank & personal', img: 'hr-admin/50-360-09-records-bank-personal', callouts: false, caption: 'HR with the sensitive permission sees the Bank & personal records tab.' },
        right: { label: 'Manager view', img: mgr360, callouts: false, caption: 'The manager\'s Personal tab: no bank or identity details; the Records sub-tabs omit Rewards and Operations.' } });

    // ---- 5. Audit
    d.section(5, 'Audit and change history', 'Append-only events, chained per tenant, verified on demand.');
    cmp({ title: 'An audit event and its integrity', eyebrow: 'Audit',
        left: { label: 'Event detail', img: S('80-ins-11-audit-event-detail'), callouts: false, caption: 'Who, when, action, reason and field-level before → after (masked when sensitive).' },
        right: { label: 'Integrity', img: S('80-ins-11b-audit-integrity'), maxAspect: 0.85, callouts: false, caption: 'Actor, roles, source, IP, user agent, request id; Hash, Previous hash, "Hash verified ✓".' } });
    if (has('client-onboarding/90-onb-90-audit-verified')) shot('client-onboarding/90-onb-90-audit-verified', { title: 'Verify the whole chain', eyebrow: 'Audit', steps: ['The result.'], result: '"Chain intact: 287 events verified" for the Northwind tenant after its full implementation.' });
    shot('platform-admin/95-pl-37-platform-audit-after', { title: 'Operators are audited too', eyebrow: 'Audit', maxAspect: 0.62, steps: [], result: 'Platform audit trail, newest first, each with actor and reason (top rows: platform access started and sign-in; suspend, reactivate, legal hold and release request further down). Header: "NOT RECENTLY VERIFIED: 0 of 3 chains verified" until the scheduler runs verification.' });

    // ---- 6. MFA
    d.section(6, 'Authentication and MFA', 'Authenticator apps for everyone who opts in or is required; operators always.');
    cmp({ title: 'Operators are forced; tenants choose', eyebrow: 'MFA',
        left: { label: 'Operator without MFA', img: 'platform-admin/02-mfa-setup-required', crop: { x: 640, y: 340, w: 880, h: 670 }, callouts: false, caption: 'Held on set-up until an authenticator is enrolled (platform policy, on by default).' },
        right: { label: 'Tenant security policy', img: S('85-sec-01-security-policy'), callouts: false, caption: 'MFA is optional in the demonstration tenant. Switch on "Require authenticator-app MFA for every tenant login".' },
        notes: 'FINDINGS: password expiry is stored but not enforced (F2); the security.mfa feature flag has no effect (F31); SSO users must also use a local authenticator when MFA is required. Only authenticator apps are supported (no SMS or e-mail codes).' });
    if (has('support/99-sup-01-wrong-password')) shot('support/99-sup-01-wrong-password', { title: 'Sign-in failures reveal nothing', eyebrow: 'Authentication', steps: ['Generic message.', 'Forgot password?'], result: 'The same "These credentials do not match our records." for a wrong password, an unknown e-mail, an invited or a suspended account. Password reset answers neutrally and is rate-limited.' });

    // ---- 7. Separation of duties
    d.section(7, 'Separation of duties', 'Sensitive outcomes need more than one person.');
    d.table({ title: 'Where a second person is required', eyebrow: 'Separation of duties', columns: ['Process', 'Rule', 'Seen in this training'], colW: [2.6, 5.2, 4.33], rows: [
        ['Employee documents', 'The uploader cannot verify', 'Yes (shown as a server error, new finding)'],
        ['Letters', 'The requester cannot approve', 'Yes: "The person who requested a letter cannot approve it."'],
        ['Compensation change', 'Four different people: propose, review, approve, execute', 'Yes: reviewer had no Approve'],
        ['Payroll run', 'Approver must differ from the creator', 'Not reached: validation blocked (statutory)'],
        ['Full & final settlement', 'None enforced', 'Observed: the same HR person calculated and approved'],
        ['Legal hold release', 'A different operator approves', 'Yes: release pending'],
        ['Financial and commercial changes', 'Maker-checker between operators', 'Pages seen; no change proposed'],
        ['Configuration changes', 'Change Centre approval when on', 'Off by default; no self-approval guard (F29)'],
    ], notes: 'SOURCE: captures 50-doc-03, 62-comp-07, 95-pl-34, 95-pl-20; documentation-gaps F29; PayrollRunPolicy (approver ≠ creator).' });
    cmp({ title: 'Two refusals that protect people', eyebrow: 'Separation of duties',
        left: { label: 'Reviewer cannot approve', img: S('62-comp-07-reviewer-cannot-approve'), callouts: ['1'], caption: 'After reviewing a pay change, Kavya sees Reject, Return to proposer and Cancel: no Approve.' },
        right: { label: 'Uploader cannot verify', tone: 'bad', img: S('50-doc-03-uploader-cannot-verify'), callouts: false, caption: '"The person who uploaded a document cannot verify it." Correct rule, but shown as a server error page (development mode).' } });
    cmp({ title: 'Letters and HR roles', eyebrow: 'Separation of duties',
        left: { label: 'Requester cannot approve a letter', tone: 'bad', img: S('52-let-04-requester-cannot-approve'), callouts: ['1'], caption: '"Not allowed: The person who requested a letter cannot approve it."' },
        right: { label: 'HR Manager cannot import', tone: 'bad', img: S('75-imp-08-hr-manager-not-available'), callouts: ['1'], caption: '403: employee.import is not in the HR Manager role.' } });
    if (has('platform-admin/95-pl-34-release-pending')) shot('platform-admin/95-pl-34-release-pending', { title: 'Operators: two-person release', eyebrow: 'Separation of duties', steps: ['The result.'], result: 'A legal hold stays active until a different operator approves its release.' });

    // ---- 8. Integrations
    d.section(8, 'Integration security', 'Keys, webhooks, SSO and inbound events.');
    cmp({ title: 'API keys and webhooks', eyebrow: 'Integrations',
        left: { label: 'Issue API key (cancelled)', tone: 'bad', img: S('85-sec-03-api-key-form'), callouts: false, caption: 'Name, scopes, expiry; the token is shown once. Scopes are not limited to the issuer\'s own permissions (F37).' },
        right: { label: 'New webhook', img: S('85-sec-04-webhook-form'), callouts: false, caption: 'Deliveries are HMAC-signed (timestamp.body) so the receiver can verify them; retries and audited replay.' } });
    cmp({ title: 'Single sign-on and the Integration Hub', eyebrow: 'Integrations',
        left: { label: 'New SSO connection', img: S('85-sec-02-sso-form'), callouts: false, caption: 'OpenID Connect only (no SAML). "Show SSO as the primary login" has no visible effect (F34).' },
        right: { label: 'Integration hub', img: S('85-sec-05-integration-hub-form'), callouts: false, caption: 'External systems registered with identifiers and signing; the only boundary to other products.' } });

    // ---- 9. Export, retention, deletion
    d.section(9, 'Export, retention, holds and deletion', 'Customers can leave with their data; nothing is deleted until policies exist.');
    cmp({ title: 'Data export', eyebrow: 'Export',
        left: { label: 'Tenant: request a full export', img: S('85-sec-07-data-export-request'), callouts: false, caption: 'Encrypted package, background job, passphrase shown once; credentials never included. Dialog shown; no export was generated in training.' },
        right: { label: 'Every report export is audited', img: 'analytics/80-ins-10-change-history', callouts: ['2'], caption: 'Change history: the CSV export of "Headcount by department" with user and time.' },
        notes: 'STATUS: tenant data export is implemented; generation was not exercised (it displays a one-time passphrase and needs a queue worker).' });
    cmp({ title: 'Retention and legal holds', eyebrow: 'Retention',
        left: { label: 'Retention policies', img: 'platform-admin/95-pl-28-retention-policies', callouts: false, caption: 'None shipped and none in force: nothing is deletable until Markedge decides (D-8).' },
        right: { label: 'Legal hold active', tone: 'good', img: 'platform-admin/95-pl-27-legal-hold-active', callouts: ['1'], caption: 'While a hold is active nothing it covers can be deleted.' } });
    shot('platform-admin/95-pl-24-offboarding', { title: 'Deletion is switched off', eyebrow: 'Deletion', steps: ['Deletion switched off.'], result: 'When enabled (owner decision), deletion requires a maker-checker plan, cooling-off, a write-once register on a separate disk, a signed certificate, and reconciliation after any restore.' });
    d.cards({ title: 'Recovery: what is and is not proven', eyebrow: 'Recovery', cards: [
        { head: 'Proven locally', body: 'Backup, restore and deletion reconciliation rehearsed on a development workstation with fictional data.', tone: 'good' },
        { head: 'Not proven', body: 'Backup frequency, RPO/RTO and a restore test in the target environment: release gate 7 (O-6, O-7).', tone: 'bad' },
        { head: 'After a restore', body: 'Run deletion reconciliation: a restored tenant that had been deleted is contained (suspended), never silently revived.', tone: 'info' },
        { head: 'Never purged', body: 'Audit events are never purged; other retention needs owner decisions with counsel (D8).', tone: 'info' },
    ], notes: 'SOURCE: docs/saas/SaaS-11-Backup-and-DR-Test-Report.md, SaaS-12 DeletionReconciliation, SaaS-Final-Production-Recovery-Runbook.md, release gate 7.' });
    d.table({ title: 'Open risks to carry into any review', eyebrow: 'Open risks', columns: ['Risk', 'Status', 'Owner'], colW: [7.2, 2.6, 2.33], rows: [
        ['Credential exposure remediation and repository visibility (gate 2)', 'BLOCKED', 'Owner'],
        ['Independent security review not engaged (gate 4)', 'BLOCKED', 'Owner'],
        ['API key scopes broader than the issuer (F37)', 'INCOMPLETE', 'Engineering'],
        ['Change Centre covers only some edits; no self-approval guard (F29)', 'INCOMPLETE', 'Engineering'],
        ['Separation-of-duties refusals shown as server errors (documents, new)', 'INCOMPLETE', 'Engineering'],
        ['Password expiry not enforced (F2); custom-field visibility ignored (F32)', 'INCOMPLETE', 'Engineering'],
        ['Backup, DR, hosting and on-call not in place (gates 7, 8)', 'BLOCKED', 'Owner, infrastructure'],
    ], notes: 'SOURCE: SaaS-Final-Release-Gate.md; documentation-gaps.md; findings observed live on 21 Oct 2026. Do not present any of these as resolved.' });
    d.closing({ points: ['Isolation and access controls are enforced in code and tested; an independent review is still required.', 'Customers must configure MFA, HR scope and role templates deliberately.', 'Every change is audited in a verifiable chain; operators are audited too.', 'Separation of duties protects documents, letters, pay, holds and finance.', 'Deletion is off; recovery is proven only locally; several risks remain open.'],
        next: ['Security evidence pack (Markedge, on request)', 'Release gate status (Markedge)', 'Deck 10: architecture', 'Deck 05: platform operator controls'],
        notes: 'SOURCES for reviewers with repository access: docs/saas/SaaS-Final-Security-Evidence.md, docs/architecture/security-invariants.md, docs/saas/SaaS-Final-Release-Gate.md.' });
    return d;
};
