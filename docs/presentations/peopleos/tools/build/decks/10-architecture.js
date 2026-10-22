// Deck 10 — PeopleOS HCM system architecture and workflows (verified repository evidence + linked real screens).
'use strict';
const common = require('./common');

module.exports = (lib) => {
    const { Deck, has } = lib;
    const d = new Deck({
        num: 10, file: '10-PeopleOS-HCM-System-Architecture-and-Workflows.pptx', short: 'Architecture and workflows',
        title: 'HCM System Architecture and Workflows',
        subtitle: 'How PeopleOS is built: layers, tenancy, access control, effective dating, audit, workflows, payroll safeguards and integration boundaries.',
        audience: 'Solution architects, engineers, implementation leads and technical reviewers',
    });
    const shotIf = (name, o) => { if (has(name)) d.shot({ img: name, ...o }); };
    const wideIf = (name, o) => { if (has(name)) d.wide({ img: name, ...o }); };
    const first = (...names) => names.find((n) => has(n));

    d.cover({ shot: first('security-compliance/80-ins-11b-audit-integrity', 'hr-admin/50-360-04-journey'), notes: 'SAY: This deck explains how PeopleOS is built, using the repository as evidence and real screens where they show the architecture at work.\nAUDIENCE: architects, engineers, implementation leads.\nSOURCES: docs/architecture/peopleos-architecture-contract.md, integration-contract.md, security-invariants.md, saas-9-offboarding.md, tests/Feature/Architecture/ArchitectureTest.php.' });
    d.statusLegend();
    common.environment(d);
    d.numbered({
        title: 'What you will be able to explain', eyebrow: 'Learning objectives',
        items: [
            ['The five product layers', 'and which modules sit where'],
            ['One person, one lifetime record', 'Person, Employee, effective-dated assignments'],
            ['Tenant isolation and layered access', 'fail-closed tenancy, scopes, field protection'],
            ['Configuration and workflow versioning', 'policies, rules, pinned workflow versions'],
            ['Audit and change history', 'append-only events with a hash chain'],
            ['Safeguards and boundaries', 'payroll gates, offboarding, integrations, AI'],
        ],
        notes: 'SAY: By the end you should be able to sketch the layers, explain why tenant data cannot leak by default, and say which controls are enforced in code versus which depend on operations.\nASK: "Which of these have you had to explain to a customer before?"',
    });

    // ---- 1. Layers
    d.section(1, 'Product architecture', 'Five layers from the blueprint, realised as domain modules with thin delivery layers.');
    d.diagram({
        title: 'Five layers, one product', eyebrow: 'Product architecture',
        layers: [
            { name: 'Experience', parts: ['Employee', 'Manager', 'HR', 'Payroll', 'Admin', 'Responsive web (no native app)'] },
            { name: 'Intelligence', parts: ['Reports and datasets', 'Analytics pages', 'Ask PeopleOS (assistants)', 'Payroll Auditor (rule-based)'] },
            { name: 'Configuration platform', parts: ['Policies', 'Rules', 'Workflows and approvals', 'Forms and custom fields', 'Packs and blueprints'] },
            { name: 'People Core', parts: ['Identity', 'Organisation', 'People and employment', 'Time, leave, payroll', 'Growth, services, exit'] },
            { name: 'Integration Hub', parts: ['REST API v1', 'SCIM 2.0', 'OIDC single sign-on', 'Signed webhooks', 'Inbound events'] },
        ],
        notes: 'SOURCE: docs/peopleos-blueprint.md §3 (PEOPLE CORE, CONFIGURATION PLATFORM, EXPERIENCE, INTELLIGENCE, INTEGRATION HUB).\nSAY: The blueprint lists more integration targets (biometric, ERP, banking, SMS, WhatsApp). Only what is on this slide is built. SMS, WhatsApp and push are offered as notification channels but send nothing (finding F19). No native mobile app exists.\nAVOID: presenting blueprint targets as built.',
    });
    d.diagram({
        title: 'Code structure and call flow', eyebrow: 'Product architecture',
        flow: [['Delivery', 'app/Filament, app/Http: calls'], ['Application', 'Services and Actions: call'], ['Domain', 'Models, Policies; defines contracts'], ['Adapters', 'implement domain contracts']],
        caption: 'Arrows show calls; adapters implement contracts the domain defines (the domain never imports them). Business rules live in app/Domain/{Module}/{Models,Services,Actions,Policies,Enums}. Filament pages and HTTP controllers call services; they never hold business rules. app/Support holds cross-cutting primitives (tenancy, effective dating). Domain code does not import Filament, HTTP or vendor SDKs; adapters (AI provider, BGV, attendance devices, notification channels, SSO presets) sit behind contracts.',
        notes: 'SOURCE: architecture contract §15. About 30 domain modules (Identity, Platform, Organisation, People, Employment, Lifecycle, … Audit).\nSAY: When you look for where a rule lives, start in app/Domain, not in the screen.',
    });

    // ---- 2. People Core
    d.section(2, 'People Core', 'One person, one lifetime record; employment facts are effective-dated so any past state can be reproduced.');
    d.diagram({
        title: 'One person, one lifetime record', eyebrow: 'People Core',
        layers: [
            { name: 'Person', parts: ['Names, date of birth, contacts', 'Addresses (effective-dated)', 'Family, qualifications, skills'] },
            { name: 'Employee (one per person per tenant)', parts: ['Employee code, work e-mail', 'Lifecycle state + immutable transitions', 'Login (optional user)'] },
            { name: 'Effective-dated facts', parts: ['Positions: company … cost centre', 'Reporting relationships (typed)', 'Salary assignments'] },
            { name: 'Domain records', parts: ['Time, leave, payroll', 'Performance, learning, assets', 'Requests, exit, letters, alumni'] },
        ],
        notes: 'SOURCE: architecture contract §3 (ADR-0002). A unique (tenant_id, person_id) on employees prevents a second employee row for the same person. Re-employment reuses the same Employee (alumni → active); a dedicated rehire screen is not built.\nSAY: This is why the hire wizard checks for an existing person before creating anyone.',
    });
    d.table({
        title: 'Lifecycle states and what each transition triggers', eyebrow: 'People Core',
        columns: ['Item', 'Implementation'],
        colW: [3.2, 8.93],
        rows: [
            ['Canonical states', 'pre_employee → preboarding → onboarding → joined → probation → confirmed → active → on_leave / suspended / notice_period → exited → alumni; alumni → active'],
            ['Where enforced', 'LifecycleEngine::transition(), permission employee.lifecycle, effective date and reason required; exit needs a cleared exit case'],
            ['Each transition produces', 'Domain event → audit event → timeline entry → notification rules → workflow triggers → downstream actions'],
            ['Downstream actions', 'Onboarding start on joining; login suspended on exit; Alumni role'],
            ['Not states', 'Transfer and promotion are position events on the timeline (new effective-dated position row)'],
        ],
        notes: 'SOURCE: architecture contract §7 (ADR-0007), config peopleos.lifecycle.transitions.',
    });
    shotIf('hr-admin/50-360-04-journey', { title: 'Effective dating, as the user sees it', eyebrow: 'People Core', steps: [[1, 'The Journey view shows the history in order.'], ['·', 'Each change is a dated entry, never an overwrite.']], result: 'Past positions and managers remain reproducible.' });
    d.table({
        title: 'Organisation model: units are not interchangeable', eyebrow: 'People Core',
        columns: ['Unit', 'Nature', 'Relation'],
        colW: [3.5, 3.6, 5.03],
        rows: [
            ['Company', 'Management unit', 'Root under the tenant'],
            ['Legal entity, establishment', 'Legal and statutory', 'Legal employer; registered place of work (ADR-0001)'],
            ['Location', 'Physical site', 'Company; cannot be linked to an establishment on screen (F10)'],
            ['Business unit, division, department, team', 'Reporting and operational', 'Company; organisation tree for the designer'],
            ['Cost centre, profit centre', 'Financial', 'Referenced by positions'],
            ['Job family, designation, level, grade', 'Job architecture (tenant-wide)', 'Referenced by positions'],
        ],
        notes: 'SOURCE: architecture contract §2 and §4; documentation-gaps F10.\nSAY: Units are effective-dated and never deleted while referenced; renames and moves are audited.',
    });

    // ---- 3. Tenancy and access
    d.section(3, 'Tenant isolation and access', 'The tenant is the isolation boundary. Access is decided in layers that are never merged.');
    d.diagram({
        title: 'Tenant scoping fails closed', eyebrow: 'Tenant isolation',
        flow: [['Request', 'session or API key'], ['ResolveTenant', 'user → own tenant'], ['TenantContext', 'bound before model binding'], ['TenantScope', 'no tenant → no rows'], ['BelongsToTenant', 'stamps tenant_id, refuses cross-tenant writes']],
        caption: 'Without a bound tenant, tenant-owned queries return nothing. Platform code must call TenantContext::bypass() explicitly, and only allow-listed platform services may (architecture test "bypasses tenant scoping only in the documented platform services"). Platform operators reach a tenant only by explicitly entering it, and their actions are audited with the source.',
        notes: 'SOURCE: architecture contract §1; tests/Feature/Architecture/ArchitectureTest.php ("scopes every domain model to a tenant except the documented platform-level models", "bypasses tenant scoping only in the documented platform services").\nSAY: The user model is deliberately not tenant-scoped because sign-in happens before a tenant is known; listings use User::forCurrentTenant().',
    });
    d.diagram({
        title: 'Access is decided in layers', eyebrow: 'Access control',
        flow: [['Role', 'RBAC: what may this role do?'], ['Organisation scope', 'ABAC: which records?'], ['Relationship scope', 'whose records? (reports)'], ['Field protection', 'sensitive permissions, masking'], ['Record', 'policy allows()']],
        caption: 'Permission keys are declared in config/peopleos.php and mirrored to the database by peopleos:sync-permissions. Organisation scope (user_access_scopes) limits HR to companies or units; with no scope rows, the documented rule gives tenant-wide reach (Home warns about it). Managers reach their own record and everyone who reports to them today. Sensitive fields (bank, PAN, Aadhaar reference, UAN, salary, payslips…) need *.sensitive.* permissions and are encrypted and masked.',
        notes: 'SOURCE: architecture contract §6 (ADR-0004, ADR-0005); data_classification in config/peopleos.php; ArchitectureTest "masks every highly sensitive attribute in the data classification map".\nSAY: A Tenant Administrator is not automatically allowed to see sensitive fields.',
    });
    d.table({
        title: 'Who sees what (decided visibility matrix)', eyebrow: 'Access control',
        columns: ['Actor', 'Sees'],
        colW: [3.2, 8.93],
        rows: [
            ['Employee', 'Own person and employee data, own transactions, published policies and announcements for their audience'],
            ['Manager', 'Direct reports (any reporting type, effective today) for the abilities their permissions grant'],
            ['HR roles', 'Every employee within their organisation scope; tenant-wide when no scope rows exist'],
            ['Tenant administrator', 'Tenant-wide by permissions; sensitive fields only with *.sensitive.*; never another tenant'],
            ['Executive', 'Dashboards and aggregates; record level only via scope and permissions'],
            ['Platform operator', 'Any tenant, only after explicitly entering it; every action audited'],
        ],
        notes: 'SOURCE: architecture contract §6 visibility matrix. Deck 8 shows the real allowed and restricted screens.',
    });

    // ---- 4. Configuration platform
    d.section(4, 'Configuration platform', 'Policies, rules and workflows are versioned data, not code. Running work keeps the version it started with.');
    d.table({
        title: 'Vocabulary that matters in implementation', eyebrow: 'Configuration platform',
        columns: ['Term', 'Meaning in PeopleOS'],
        colW: [2.6, 9.53],
        rows: [
            ['Configuration', 'Tenant or company values and reference data: settings, features, organisation units, calendars, components'],
            ['Policy', 'Versioned, effective-dated bundle of settings of one type (leave, attendance, overtime, probation, payroll…)'],
            ['Rule', 'Deterministic condition over the employee context (position dimensions, lifecycle, gender, tenure)'],
            ['Workflow', 'Versioned graph of nodes executed per subject; approvals single, sequential, parallel or majority'],
            ['Change Centre', 'Governed edits with draft → approval → publish; covers only some edit forms today (F29)'],
            ['Pack / blueprint', 'Starter configuration a tenant receives and then edits freely'],
        ],
        notes: 'SOURCE: architecture contract §8 (ADR-0009, ADR-0010); documentation-gaps F29 (settings, feature flags, creates, deletes and tab edits apply immediately; no guard against approving one\'s own change).',
    });
    d.diagram({
        title: 'Workflow versioning rule (frozen)', eyebrow: 'Configuration platform',
        flow: [['Version 1 published', 'instances start on v1'], ['Version 2 published', 'new instances start on v2'], ['Running v1 instance', 'stays on v1 to the end'], ['Rollback', 'publish the previous version again']],
        caption: 'A workflow instance is pinned to its workflow_version_id. Escalation and timers run every five minutes; webhook nodes retry three times through the queue; other failed nodes are marked failed and resumed by an operator. Known limit: the "Generate document" step type is skipped at run time (F33).',
        notes: 'SOURCE: architecture contract §8 workflow versioning; documentation-gaps F33.',
    });
    shotIf('client-onboarding/85-wf-03-workflow-versions', { title: 'Versions as the administrator sees them', eyebrow: 'Configuration platform', crop: { x: 390, y: 620, w: 1770, h: 700 }, steps: [], result: 'Probation confirmation: v1 published, 6 nodes (manager recommendation, HR confirmation, record update, notification). A v2 would apply only to runs started after it is published (not exercised here).' });

    // ---- 5. Audit
    d.section(5, 'Audit and change history', 'Who changed what is recorded in append-only events chained per tenant.');
    d.cards({
        title: 'Audit events: append-only, chained, not purged', eyebrow: 'Audit',
        cards: [
            { head: 'What is recorded', body: 'Tenant, actor and roles snapshot, action, module, entity, time, IP, user agent, source, request id, reason, approval reference, effective date and field-level before/after (masked when sensitive).' },
            { head: 'Tamper evidence', body: 'Each tenant has a hash chain; a verifier and export commands check it. Rows cannot be updated or deleted through the model layer.' },
            { head: 'Change history', body: 'Audit events per record (History tab) plus effective-dated rows make any past state reproducible.' },
            { head: 'Limits to know', body: 'Deletion of audit rows exists only inside the tenant-deletion executor, which is switched off. Retention of other data needs owner decisions (D8).', tone: 'warn' },
        ],
        notes: 'SOURCE: architecture contract §9 (ADR-0008); ArchitectureTest "audits every domain model except the documented append-only or derived tables" and "deletes audit rows only in the deletion executor".',
    });
    shotIf('analytics/80-ins-10-change-history', { title: 'Change history in the application', eyebrow: 'Audit', callouts: ['1'], steps: [[1, 'When: every audited change with actor and time.'], ['·', 'View an Update event for its field-level before and after.']], result: 'Administrators and auditors read history; nobody edits it.' });
    shotIf('security-compliance/80-ins-11b-audit-integrity', { title: 'The hash chain on one event', eyebrow: 'Audit', steps: [], result: 'Who and where (actor, roles, source, IP, request id) and Integrity: Hash, Previous hash, "Hash verified ✓".' });
    shotIf('platform-admin/95-pl-22-platform-audit', { title: 'Platform audit trail', eyebrow: 'Audit', steps: [], result: 'Operator actions are audited separately from tenants. The header shows "NOT RECENTLY VERIFIED: 0 of 3 chains verified": verification (peopleos:audit:verify) is scheduled, and no scheduler runs locally.' });

    // ---- 6. Queues
    d.section(6, 'Queues, scheduler and tenant context', 'Background work always runs inside one tenant.');
    d.cards({
        title: 'Background work keeps its tenant', eyebrow: 'Queues and scheduler',
        cards: [
            { head: 'TenantAwareJob', body: 'Every queued job touching tenant data captures the tenant at dispatch and binds it with BindTenantContext middleware. The architecture test enforces it for every ShouldQueue class.', tone: 'good' },
            { head: 'Scheduler', body: 'routes/console.php; each entry withoutOverlapping()->onOneServer(); commands iterate tenants explicitly with runAs.', tone: 'good' },
            { head: 'Failures', body: 'Queued failures go to failed_jobs and the Platform › Operations page; integration failures become delivery or inbound-event states, never caller exceptions.' },
            { head: 'Operational prerequisite', body: 'A queue worker and the scheduler must run in production. None runs in the local environment; hosting and on-call are blocked (gate 8).', tone: 'warn' },
        ],
        notes: 'SOURCE: architecture contract §10 (ADR-0013); ArchitectureTest "makes every queued job tenant-aware"; release gate 8.\nSAY: A control enforced in code still needs an operated environment.',
    });
    shotIf('platform-admin/95-pl-21-operations', { title: 'Where operators see queues and failures', eyebrow: 'Queues and scheduler', steps: [], result: 'Database, cache, queue, failed jobs, integrations and the scheduler heartbeat (WARN here: no scheduler runs locally, release gate 8), with per-tenant status.' });

    // ---- 7. Payroll safeguards
    d.section(7, 'Payroll and statutory safeguards', 'Statutory rules are data, verified by people; payroll refuses to finalise on unverified rules.');
    d.cards({
        title: 'Payroll cannot finalise on unverified rules', eyebrow: 'Payroll safeguards',
        cards: [
            { head: 'Rules are data', body: 'Statutory rates live in versioned compliance rules, never in PHP (architecture test). 23 India rules plus 1 UAE rule; 0 of 24 verified.' },
            { head: 'Verification', body: 'Two platform administrators verify a rule with official evidence. Until then, enforcement blocks finalisation and payslips.', tone: 'warn' },
            { head: 'No rules, no payroll', body: 'A run in a jurisdiction without statutory rules is blocked (USA, UK and EU have no rules; owner decision O-18).', tone: 'warn' },
            { head: 'Separation of duties', body: 'The approver of a run must differ from its creator; compensation changes need four different people; payroll consumes attendance and leave outputs, never their internals.' },
        ],
        notes: 'SOURCE: ArchitectureTest "makes payroll consume attendance and leave outputs, never their internals, and keep statutory rates out of PHP"; docs/saas/SaaS-Final-Statutory-Verification-Register.md; FINAL programme commit 064c262.\nSAY: Gratuity is not calculated (stored rule only; the settlement has no gratuity line).',
    });
    shotIf('hr-admin/60-pay-09-validate-blocked', { title: 'The safeguard as payroll staff see it', eyebrow: 'Payroll safeguards', steps: [], result: 'Validate on the October run: "Not allowed: 19 employee(s) have blocking exceptions. Fix the inputs and recalculate." Approve and Finalize never appear. Nothing reaches a payslip on an unverified rate.' });
    shotIf('hr-admin/60-pay-06-breakdown', { title: 'Why: statutory lines are exceptions', eyebrow: 'Payroll safeguards', steps: [], result: 'The breakdown shows statutory lines as exceptions because their rules are unverified.' });

    // ---- 8. Offboarding
    d.section(8, 'Export, offboarding and deletion', 'A customer can leave with its data. Deletion exists in code and is switched off.');
    d.table({
        title: 'Offboarding controls and their state', eyebrow: 'Offboarding',
        columns: ['Control', 'What it does', 'State today'],
        colW: [2.5, 6.6, 3.03],
        rows: [
            ['Tenant data export', 'Encrypted, passphrase-protected package of the tenant\'s data, requested by the Account Owner or an operator (request dialog opened; not generated, because it shows a one-time passphrase)', 'IMPLEMENTED BUT NOT VERIFIED END-TO-END'],
            ['Legal holds', 'Block deletion of a tenant while a hold is active (placed on the fictional Northwind tenant in this training)', 'VERIFIED IN RUNNING APPLICATION'],
            ['Retention policies', 'Decide what may be deleted and when; none shipped (D8)', 'AVAILABLE ONLY AFTER CONFIGURATION'],
            ['Deletion', 'Maker-checker plan, cooling-off, register on a separate disk, certificate, reconciliation after restore', 'DISABLED BY SAFETY OR COMMERCIAL GATES'],
        ],
        notes: 'SOURCE: docs/architecture/saas-9-offboarding.md; SaaS.12 DeletionRegister and DeletionReconciliation; release gate 11.\nEVIDENCE: platform-admin/95-pl-24 to 95-pl-28 (offboarding page, legal hold placed on Northwind, retention policies: none); security-compliance/85-sec-07-data-export-request (dialog only).',
    });

    shotIf('platform-admin/95-pl-24-offboarding', { title: 'Offboarding page: deletion is switched off', eyebrow: 'Offboarding', callouts: false, steps: [], result: '"Tenant deletion is switched off in this environment." Captured before the Northwind legal hold was placed (Deck 5 shows the hold).' });

    // ---- 9. Integrations
    d.section(9, 'Integration boundaries', 'External systems connect through the Integration Hub; PeopleOS owns its keys and never depends on another product\'s database.');
    d.table({
        title: 'Integration channels', eyebrow: 'Integration Hub',
        columns: ['Channel', 'Security and behaviour'],
        colW: [2.8, 9.33],
        rows: [
            ['REST API v1', '/api/v1, API key → tenant, JSON errors, pagination, 120/min rate limit, Idempotency-Key on writes; OpenAPI 3.1 in docs/api/openapi.json (131 operations)'],
            ['SCIM 2.0', 'User provisioning with an API key carrying SCIM scopes'],
            ['Single sign-on', 'OIDC only (SAML not supported)'],
            ['Outbound webhooks', 'HMAC-SHA256 over timestamp.body; delivery id for de-duplication; retries, dead letter and audited replay'],
            ['Inbound events', 'Per-system secret and signature (±5 min), idempotency key, encrypted payload purged later, retry backoff, dead letter, audited reprocess'],
            ['Known limit', 'API key scopes are not limited by the issuer\'s own permissions (F37, recommend review)'],
        ],
        notes: 'SOURCE: docs/architecture/integration-contract.md; documentation-gaps F34, F37.',
    });
    d.diagram({
        title: 'Recruitment systems stay outside', eyebrow: 'Integration Hub',
        flow: [['External ATS / RMS', 'its own database'], ['Integration Hub', 'auth · validate · map · idempotency · audit'], ['PeopleOS', 'pre-employee with external references']],
        caption: 'PeopleOS is a standalone HCM product with no recruitment module. A future RecruitmentEdge (RMS) or any ATS would hand over a selected candidate through POST /api/v1/pre-employees (idempotent on the offer\'s external reference) or inbound events, mapped by external identifiers. No RMS database, table, model or primary key may enter PeopleOS: the architecture test "never acquires a hard RecruitmentEdge / RMS dependency" enforces it.',
        notes: 'SOURCE: integration-contract.md §5 (future recruitment boundary); ArchitectureTest line "never acquires a hard RecruitmentEdge / RMS dependency (contract §3)".\nAVOID: describing recruitment features as part of PeopleOS. There is no shared database.',
    });
    shotIf('security-compliance/85-sec-05-integration-hub-form', { title: 'Integration hub in the application', eyebrow: 'Integration Hub', steps: [], result: 'New integration system: identifiers and the inbound security settings (signed events, timestamp window). Inbound events are listed on their own page with their processing state. Opened, not saved.' });

    // ---- 10. AI
    d.section(10, 'Analytics and AI boundary', 'Reports run on the same scoped queries as the screens. Assistants explain; they never decide or write.');
    d.diagram({
        title: 'AI requests pass every access layer first', eyebrow: 'Analytics and AI',
        flow: [['User', 'signed in'], ['Permission', 'per assistant'], ['Scope', 'queries run as the user'], ['Field security', 'identifiers redacted'], ['Model', 'off by default']],
        caption: 'Datasets query through the same scoped models as the UI; sensitive fields need the dataset\'s sensitive permission; every export is audited. Assistants never write; answers are logged with their sources; payroll and statutory rules are deterministic. The external language model (feature ai.llm) is off by default and no provider is configured, so answers are rule-based with sources.',
        notes: 'SOURCE: architecture contract §12 (ADR-0015) and §13; implementation-status 5.8.\nSAY: The "AI Payroll Auditor" is rule-based despite its label.',
    });

    // ---- Gaps
    d.table({
        title: 'Blueprint versus what is built', eyebrow: 'Honest scope',
        columns: ['Blueprint item', 'State', 'Evidence'],
        colW: [4.3, 3.0, 4.83],
        rows: [
            ['Native mobile app', 'NOT IMPLEMENTED', 'Responsive web only'],
            ['SMS, WhatsApp, push notifications', 'INCOMPLETE', 'Offered as channels; send nothing (F19)'],
            ['Biometric, ERP, banking connectors', 'NOT IMPLEMENTED', 'Generic device push (Generic JSON, eSSL) only; bank file from payroll'],
            ['SAML single sign-on', 'NOT IMPLEMENTED', 'OIDC only (F34)'],
            ['Employee import from the screen', 'INCOMPLETE', 'Map columns fails (F25)'],
            ['Gratuity calculation', 'NOT IMPLEMENTED', 'Stored rule only; no settlement line'],
            ['Dedicated databases per tier', 'NOT IMPLEMENTED', 'Tier and region are metadata; routing deferred'],
        ],
        notes: 'SOURCE: implementation-status.md, documentation-gaps.md, architecture contract §1 (deferred connection routing).',
    });
    d.closing({
        points: ['Five layers; business rules in app/Domain, screens are delivery only.', 'Tenant scoping fails closed; access is layered: role, organisation scope, relationship scope, field protection.', 'Facts that change over time are effective-dated; audit events are append-only and chained.', 'Workflows and policies are versioned data; running work keeps its version.', 'Payroll refuses unverified statutory rules; deletion is off; integrations never share a database.'],
        next: ['Read docs/architecture/peopleos-architecture-contract.md', 'Run php artisan test --filter=Architecture', 'Deck 8 shows the controls on screen', 'Release gate: docs/saas/SaaS-Final-Release-Gate.md'],
        notes: 'SAY: Controls in code are necessary but not sufficient: hosting, review, DR and statutory verification are still open.',
    });
    return d;
};
