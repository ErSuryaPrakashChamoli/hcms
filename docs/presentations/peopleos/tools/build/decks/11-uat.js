// Deck 11 — Implementation, UAT and go-live checklist.
'use strict';
const common = require('./common');
const flows = require('./flows');
const { UAT } = require('../uat');

module.exports = (lib) => {
    const { Deck, has, find } = lib;
    const d = new Deck({
        num: 11, file: '11-PeopleOS-Implementation-UAT-and-Go-Live-Checklist.pptx', short: 'Implementation, UAT and go-live',
        title: 'Implementation, UAT and Go-Live Checklist',
        subtitle: 'Prepare the environment and data, test every role and process with evidence, manage defects, decide go-live, and know how to roll back.',
        audience: 'Customer UAT lead and testers, Markedge implementation team, release decision makers',
    });
    const shot = (name, o) => { if (has(name)) d.shot({ img: name, ...o }); else lib.WARN.push(`deck 11: missing ${name}`); };
    const cmp = (o) => { if (has(o.left.img) && has(o.right.img)) d.compare(o); else lib.WARN.push(`deck 11: missing ${o.left.img} / ${o.right.img}`); };
    const RUNBOOK = 'docs/saas/SaaS-Final-Deployment-and-Rollback-Runbook.md';

    d.cover({ shot: 'hr-admin/60-pay-09-validate-blocked', notes: 'SAY: UAT proves the customer\'s processes work in PeopleOS with their configuration. A test only passes when the customer executes it and sees the expected result.\nRESOURCE: supporting/PeopleOS-UAT-Test-Cases.xlsx.' });
    d.statusLegend();
    common.statusDetailed(d, 'Release status');
    common.environment(d);
    d.numbered({ title: 'What you will be able to do', eyebrow: 'Learning objectives', items: [
        ['Prepare a UAT environment', 'separate tenant, synthetic data, test accounts per role'],
        ['Run test cases with evidence', 'actual result, screenshot, pass/fail, defect reference'],
        ['Test roles, lifecycle, time, pay, approvals, audit, integrations', 'with the reference runs as a guide'],
        ['Manage defects', 'log, triage, retest; know the existing ones'],
        ['Apply go-live criteria', 'and the release gate that overrides them'],
        ['Plan rollback and post-go-live checks', 'from the deployment runbook'],
    ] });

    d.section(1, 'Prepare', 'Environment, data and configuration readiness.');
    d.table({ title: 'Environment prerequisites', eyebrow: 'Prepare', columns: ['Prerequisite', 'Why', 'Who'], colW: [4.6, 5.0, 2.53], rows: [
        ['A UAT tenant separate from the production tenant', 'Tests change data (approvals, hires, exits)', 'Markedge operator'],
        ['Mail delivery to monitored mailboxes', 'Invitations, resets and notifications', 'Markedge operations'],
        ['Queue worker and scheduler running', 'Exports, notifications, scheduled reports, accruals, audit verification', 'Markedge operations'],
        ['One test account per role (employee, manager, HR, payroll, admin, executive)', 'Access must be tested as each role', 'Customer administrator'],
        ['MFA policy decided and applied', 'Sign-in tests must match production', 'Customer administrator'],
        ['Synthetic or masked data only', 'No real identifiers in a shared environment', 'Customer data owner'],
        ['Known-defect list shared', 'Testers recognise F25, F38, F44, F17', 'Markedge support'],
    ] });
    d.cards({ title: 'Data and configuration readiness', eyebrow: 'Prepare', cards: [
        { head: 'Structure complete', body: 'Companies, legal entities, establishments, locations, units and job architecture match the signed design (Deck 4, stages 4–8).' },
        { head: 'People loaded once', body: 'Hire wizard (imports blocked by F25); managers joined before their reports; no duplicates.', tone: 'warn' },
        { head: 'Policies published', body: 'Leave policy versions, shifts, schedules, holiday calendars, salary components and structures.' },
        { head: 'Access configured', body: 'Roles, HR scope, MFA; Tenant Super Admin holders listed.' },
        { head: 'Reconciled', body: 'Headcount by company and department matches the source file; zero unexplained differences.' },
        { head: 'Payroll scope agreed', body: 'In writing: payroll statutory processing in or out while rules are unverified.', tone: 'bad' },
    ] });

    d.section(2, 'Test', 'Each case: role, preconditions, steps, expected result, actual result, evidence, pass/fail, defect, sign-off.');
    d.table({ title: 'Anatomy of a test case', eyebrow: 'Test', columns: ['Field', 'What to record'], colW: [3.0, 9.13], rows: [
        ['Test ID, module, role', 'e.g. UAT-LV-04 · Leave · Manager'],
        ['Preconditions', 'What must exist first (a pending request, a second HR user…)'],
        ['Steps', 'Numbered actions with the real menu path'],
        ['Expected result', 'What the screen should show'],
        ['Reference run', 'What Markedge observed in the training environment: guidance only, never a customer pass'],
        ['Actual result · evidence', 'What the tester saw, with a screenshot per changing step'],
        ['Pass/Fail · defect · sign-off', 'Pass only when executed and matching; otherwise a defect reference'],
    ], notes: 'SAY: Never mark a case Pass from the reference column. The workbook leaves Pass/Fail blank on purpose.' });
    const mods = [...new Set(UAT.map((t) => t.module))];
    const rowsFor = (ms) => UAT.filter((t) => ms.includes(t.module)).map((t) => [t.id, t.role, t.expected, t.ref]);
    const groups = [[['Leave', 'Attendance'], 'time and leave'], [['Provisioning', 'Organisation', 'People', 'Documents', 'Onboarding'], 'set-up and people'], [['Payroll', 'Compensation', 'Imports'], 'pay and data load'], [['Self-service', 'Pay', 'Performance', 'Learning'], 'self-service and growth'], [['Letters', 'Assets', 'Exit', 'Alumni'], 'HR services, exit and alumni'], [['Access', 'Audit', 'Reports', 'Platform', 'Sign-in'], 'access, audit, reports, platform']];
    for (const [g, label] of groups) {
        const rows = rowsFor(g);
        if (rows.length) d.table({ title: `Test cases: ${label}`, eyebrow: 'Test cases', columns: ['ID', 'Role', 'Expected result', 'Reference run (training env)'], colW: [1.4, 2.6, 5.6, 2.53], rows, rowsPerSlide: 7, fontSize: 11.5,
            notes: 'Full steps, preconditions and reference observations are in supporting/PeopleOS-UAT-Test-Cases.xlsx. "Executed: known defect" means the step was run and a documented defect appeared.' });
    }
    const left = mods.filter((m) => !groups.flatMap((x) => x[0]).includes(m));
    if (left.length) d.table({ title: `Test cases: ${left.join(', ')}`, eyebrow: 'Test cases', columns: ['ID', 'Role', 'Expected result', 'Reference run (training env)'], colW: [1.4, 2.6, 5.6, 2.53], rows: rowsFor(left), rowsPerSlide: 6, fontSize: 11.5 });

    d.section(3, 'Major scenarios on screen', 'What the expected result looks like in the reference run.');
    flows.leaveManager(d, { eyebrow: 'UAT-LV-04 / 05' });
    cmp({ title: 'UAT-AT-02: correction approved, day reprocessed', eyebrow: 'UAT-AT-02',
        left: { label: 'Manager approves', img: 'manager/20-att-07-correction-approved', callouts: ['1'], caption: '"Approved and attendance reprocessed".' },
        right: { label: 'Employee sees the corrected day', tone: 'good', img: 'employee/20-att-16-my-attendance', callouts: ['1'], caption: '20 Oct: Present 09:05–18:10. "Worked on holiday" is expected: 20 Oct is a holiday in the demo calendar.' } });
    cmp({ title: 'UAT-HR-01: hire with the duplicate check', eyebrow: 'UAT-HR-01',
        left: { label: 'Duplicate refused', tone: 'bad', img: 'hr-admin/50-hire-01-duplicate-refused', callouts: false, caption: '"This person already exists: Priya Nair (EMP00004)".' },
        right: { label: 'New hire created', tone: 'good', img: 'hr-admin/50-hire-07-created-record', callouts: false, caption: 'Tanvi Joshi, Probation; onboarding started automatically.' } });
    cmp({ title: 'UAT-CMP-01: four people, one pay change', eyebrow: 'UAT-CMP-01',
        left: { label: 'Reviewer has no Approve', img: 'security-compliance/62-comp-07-reviewer-cannot-approve', callouts: ['1'], caption: 'The person who reviewed cannot also approve.' },
        right: { label: 'Executed by the fourth person', tone: 'good', img: 'hr-admin/62-comp-10-executed', callouts: false, caption: 'Status Scheduled for the effective date.' } });
    cmp({ title: 'UAT-PAY-01: calculated, then blocked', eyebrow: 'UAT-PAY-01',
        left: { label: 'Recalculated', img: 'hr-admin/60-pay-08-recalculated', callouts: false, caption: '"Calculated for 19 employee(s); 19 with exceptions".' },
        right: { label: 'Validate refused', tone: 'bad', img: 'hr-admin/60-pay-09-validate-blocked', callouts: false, caption: 'Blocking exceptions from unverified statutory rules: UAT-PAY-02 cannot be executed today.' } });
    cmp({ title: 'UAT-ACC-01: test access as the role', eyebrow: 'UAT-ACC-01',
        left: { label: 'HR Executive Home', img: 'client-onboarding/90-onb-59-hr-executive-home', callouts: false, caption: 'A narrower menu than the administrator.' },
        right: { label: 'Roles page refused', tone: 'good', img: 'client-onboarding/90-onb-60-hr-executive-roles-denied', callouts: false, caption: 'A refusal where the role matrix says "no" is a pass.' } });
    cmp({ title: 'UAT-AUD-01 and UAT-RPT-01: prove it', eyebrow: 'UAT-AUD-01 / RPT-01',
        left: { label: 'Audit chain verified', tone: 'good', img: 'client-onboarding/90-onb-90-audit-verified', callouts: ['1'], caption: '"Chain intact: 287 events verified".' },
        right: { label: 'Report run and exported', img: 'analytics/80-ins-06-report-result', callouts: ['1', '2'], caption: 'Grouped results and Export CSV; the export is audited.' } });
    shot('client-onboarding/90-onb-93-import-map-fails', { title: 'UAT-IMP-01: a defect found by a test', eyebrow: 'UAT-IMP-01', status: 'INCOMPLETE', steps: ['Error.'], result: 'Record it as Fail with defect F25, the screenshot and the import number. Retest only after a fix is delivered.' });

    d.section(4, 'Accessibility, devices and integrations', 'What has been checked, and what the customer must still check.');
    const mobile = find(/^employee\/.*(mobile|phone)/)[0];
    const dark = find(/^employee\/.*dark/)[0];
    if (mobile) shot(mobile, { title: 'Phone layout', eyebrow: 'Responsive', maxAspect: 2.3, callouts: false, steps: [], result: 'The same Home at phone width (390 × 844) with a bottom bar. Safari and Android Chrome have not been tested (UX-19 §30).' });
    d.table({ title: 'Accessibility and device evidence', eyebrow: 'Accessibility', columns: ['Check', 'Evidence available', 'Customer UAT action'], colW: [3.2, 5.0, 3.93], rows: [
        ['Automated accessibility (axe)', 'UX.18 audit: axe-core 4.13, WCAG 2.0–2.2 A/AA on the live showcase; re-runs 0 violations (58 and 78 checks); scripted keyboard, reflow and zoom review', 'Run a screen reader on the key employee tasks with real users'],
        ['Keyboard use', 'Shortcuts (Ctrl K, ?, J/K, A/R with Confirm)', 'Complete leave and approval without a mouse'],
        ['Phone and tablet', 'Chromium at 390 × 844 (touch)', 'Test on the customer\'s actual devices; Safari and Android Chrome untested'],
        ['Dark mode', 'Theme switcher light / dark / system', 'Optional'],
        ['Integrations', 'API, SCIM, OIDC SSO, webhooks: forms verified; no round trip executed here', 'Test each integration end to end with the customer\'s systems'],
    ], notes: 'SOURCE: docs/ux/UX-18-Accessibility-Audit.md (axe runs and scripted manual review); docs/reports/implementation-status.md 5.1 (phone layout at 390 × 844, Safari and Android Chrome untested). Integration round trips were not exercised in the training environment.' });

    d.section(5, 'Defects, evidence and reconciliation', 'Log, triage, retest; keep evidence; reconcile figures.');
    d.cards({ title: 'Defect management', eyebrow: 'Defects', cards: [
        { head: 'Log', body: 'Test ID, steps, expected versus actual, screenshot, time, user, severity (Critical, High, Medium, Low).' },
        { head: 'Triage', body: 'Known defect? Configuration? Training? Markedge support decides with the customer UAT lead.' },
        { head: 'Fix and retest', body: 'Retest the same case and its neighbours; keep both screenshots.' },
        { head: 'Accept or block', body: 'Critical and High defects block go-live unless accepted in writing with a workaround.', tone: 'warn' },
    ] });
    d.cards({ title: 'Evidence and reconciliation', eyebrow: 'Evidence', cards: [
        { head: 'Screenshots', body: 'One per step that changes the screen; no personal identifiers visible.' },
        { head: 'Exports', body: 'CSV exports of headcount and balances; each export is in Change history.' },
        { head: 'Audit', body: 'Change history › Verify integrity: "Chain intact" at UAT end.' },
        { head: 'Reconciliation', body: 'Counts by company, department and status versus source; balances for a sample; zero unexplained differences.' },
    ] });

    d.section(6, 'Go live, roll back, stabilise', 'Criteria, the release gate, the runbook.');
    d.table({ title: 'Go-live criteria (all required)', eyebrow: 'Go-live', columns: ['Criterion', 'Today'], colW: [8.0, 4.13], rows: [
        ['Every critical UAT case executed and passed by the customer', 'Customer to execute'],
        ['No open Critical or High defect without written acceptance', 'F25, F38, F44 and new findings open'],
        ['Data reconciled and signed', 'Customer to sign'],
        ['Access tested for every role; MFA as decided', 'Customer to test'],
        ['Payroll scope decided in writing', 'Statutory rules 0 of 24 verified'],
        ['PeopleOS release gate GO', 'BLOCKED (NO-GO, 10 of 14 gates)'],
        ['Hosting, monitoring, on-call, backup and DR evidence', 'BLOCKED (gates 7, 8)'],
    ], notes: `SOURCE: ${RUNBOOK} §8; release gate. A customer go-live cannot be approved while the product release gate is NO-GO.` });
    d.table({ title: 'Smoke tests after deployment (non-destructive)', eyebrow: 'Go-live', columns: ['#', 'Check'], colW: [0.6, 11.53], rows: [
        ['1', 'Health endpoints live and ready (database, cache, queue, storage, scheduler)'],
        ['2', 'An operator signs in with MFA; Platform readiness has no FAIL'],
        ['3', 'A fictional test tenant is provisioned; its admin creates one employee; a second test tenant cannot see it'],
        ['4', 'Payroll on the test tenant shows statutory rules blocked; audit verify passes'],
        ['5', 'A notification e-mail reaches a monitored mailbox; a document uploads and downloads only through the authorised route'],
        ['6', 'Registration shows "not available"; deletion and payments are refused; deletion reconciliation reports nothing'],
        ['7', 'No demo or test accounts remain; every operator has MFA'],
    ], notes: `SOURCE: ${RUNBOOK} §6 (12 smoke tests, condensed).` });
    d.cards({ title: 'Rollback and interrupted migrations', eyebrow: 'Rollback', cards: [
        { head: 'Code defect, no migration', body: 'Redeploy the previous artefact; restart the queue.' },
        { head: 'Migration done, release undone', body: 'Restore the pre-deployment backup (or a reviewed down() tested on staging), then redeploy the previous artefact.' },
        { head: 'Migration interrupted', body: 'Do not re-run blindly: MySQL DDL is not transactional. Restore the backup, then deploy again.', tone: 'bad' },
        { head: 'Rollback triggers', body: 'Failed critical smoke test, broken audit chain, any cross-tenant exposure, sustained 5xx, unrecoverable migration.', tone: 'warn' },
    ], notes: `SOURCE: ${RUNBOOK} §5 and §9. On a trigger: stop rollout, follow §5, keep the logs, record the incident.` });
    d.table({ title: 'Post-go-live validation (each agreed interval)', eyebrow: 'Stabilise', columns: ['Area', 'Check'], colW: [3.0, 9.13], rows: [
        ['Sign-in', 'Users sign in; operators with MFA'],
        ['Isolation', 'A sample confirms no cross-tenant visibility'],
        ['Background work', 'Queue depth, failed jobs, scheduler heartbeat (Platform › Operations)'],
        ['Notifications', 'Delivery log shows sent items'],
        ['Audit', 'Daily verification passes'],
        ['Processes', 'Leave, attendance and approvals flow for real users; first payroll only when allowed'],
        ['Backups', 'Backup job success; restore test on schedule'],
    ], notes: `SOURCE: ${RUNBOOK} §9. The stabilisation window and its owners are owner decision O-10.` });
    d.exercise({ title: 'Practice', tasks: [
        'A tester marks UAT-PAY-02 as Pass because the reference column is filled. What do you do?',
        'UAT-IMP-01 fails with "Undefined variable $svc". How do you record it and what is the workaround?',
        'Every UAT case passed. Can the customer go live next week?',
    ], notes: 'ANSWERS: (1) Reject: the reference column is guidance; PAY-02 cannot be executed today. (2) Fail with defect F25 and evidence; workaround: hire wizard or Markedge API load. (3) Not while the PeopleOS release gate is NO-GO and hosting, DR and payroll scope are open.' });
    d.table({ title: 'Answer key', eyebrow: 'Practice', columns: ['#', 'Answer'], colW: [0.6, 11.53], rows: [
        ['1', 'Reject the Pass: the reference column is guidance only. UAT-PAY-02 cannot be executed while statutory rules are unverified: record it as Blocked.'],
        ['2', 'Fail, defect F25, with the screenshot and import number. Workaround: Hire employee wizard, or a Markedge API load. Retest after the fix.'],
        ['3', 'No. Go-live also needs the PeopleOS release gate at GO, hosting, monitoring, backup and DR evidence, and a written payroll scope decision.'],
    ] });
    d.closing({ points: ['UAT runs in a separate tenant, with synthetic data and one account per role.', 'A test passes only when the customer executes it and sees the expected result.', 'Known defects are failures with workarounds, not passes.', 'Go-live needs customer criteria and the PeopleOS release gate.', 'Roll back by the runbook; never re-run an interrupted migration blindly.'],
        next: ['supporting/PeopleOS-UAT-Test-Cases.xlsx', 'supporting/PeopleOS-Go-Live-Handover-Checklist.xlsx', RUNBOOK, 'Deck 04: implementation stages'] });
    return d;
};
