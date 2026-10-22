// Deck 4, phases 5–7: policies and pay, validate, go live and govern.
'use strict';
module.exports = (d, lib, { st, C, shot, wide, cmp, NW }) => {
    const { has } = lib;
    const any = (...names) => names.find((n) => has(n));

    // ---- Phase 5: Policies and pay
    d.section(5, 'Policies and pay', 'Time, leave, pay, statutory, workflows, notifications and sign-in security.');
    d.stageCard(st(14));
    shot(C('90-onb-61-new-shift'), { title: 'Define shifts', eyebrow: 'Stage 14 · Attendance', steps: [], result: 'Start and end times, breaks and grace rules; a review panel shows the shift before it is created.' });
    shot(C('90-onb-62-new-work-schedule'), { title: 'Create a work schedule', eyebrow: 'Stage 14 · Attendance', callouts: false, steps: [], result: 'The weekly pattern of shifts and weekly offs.' });
    shot(C('90-onb-63-work-schedule-saved'), { title: 'Schedule saved: assign it by rule', eyebrow: 'Stage 14 · Attendance', steps: [], result: 'Applies-to rules decide who works the schedule; individuals can be assigned from the Employee 360.' });
    shot(C('90-onb-64-new-holiday-calendar'), { title: 'Create a holiday calendar', eyebrow: 'Stage 14 · Attendance', steps: [], result: 'One calendar per location or group; assigned by rule.' });
    cmp({ title: 'Add holidays and assign the calendar', eyebrow: 'Stage 14 · Attendance',
        left: { label: 'New holiday', img: C('90-onb-65-new-holiday'), callouts: false, caption: 'The date counts as a holiday for everyone the calendar applies to.' },
        right: { label: 'Calendar saved', img: C('90-onb-66-holiday-calendar-saved'), crop: { x: 384, y: 1240, w: 1776, h: 660 }, callouts: false, caption: 'Holidays Kannada Rajyotsava (1 Nov) and Christmas; Applies to and History tabs.' },
        notes: 'KNOWN LIMIT F39: holiday type and the half-day switch are stored but ignored; every calendar date counts as a full holiday. Tell the customer before they enter half days.' });
    d.stageCard(st(15));
    shot(C('90-onb-67-leave-types'), { title: 'Leave types arrive seeded', eyebrow: 'Stage 15 · Leave', steps: ['A seeded type with its flags.', 'New leave type.'], result: 'Earned, Casual, Sick, Maternity, Paternity, Bereavement, Compensatory off, Restricted holiday, Leave without pay.' });
    shot(C('90-onb-68-new-leave-policy'), { title: 'Create the leave policy', eyebrow: 'Stage 15 · Leave', steps: ['Policy type: Leave policy.', 'Name.'], result: 'The rules go on a draft version of the saved policy, which is then published.' });
    shot(C('90-onb-70-leave-entitlements'), { title: 'Set the entitlements on the draft version', eyebrow: 'Stage 15 · Leave', steps: ['Leave type.', 'Days per year.', 'Accrual.', 'Carry-forward.'], result: 'Nothing applies until the version is published.' });
    cmp({ title: 'Publish the version', eyebrow: 'Stage 15 · Leave',
        left: { label: 'Publish draft', img: C('90-onb-71-publish-policy'), callouts: false, caption: 'The version becomes active from its effective date; earlier versions are kept.' },
        right: { label: 'Published', tone: 'good', img: C('90-onb-72-policy-published'), crop: { x: 384, y: 1380, w: 1776, h: 580 }, callouts: false, caption: 'v1 Published by Farah Khan, effective 21 Oct 2026. Assignment rules decide who it applies to.' },
        notes: 'VERIFY: after publishing, check Balances for two sample employees (accruals run monthly; Run accrual now is on the Balances page).' });
    d.stageCard(st(16));
    shot(C('90-onb-73-salary-components'), { title: 'Salary components arrive seeded', eyebrow: 'Stage 16 · Pay', steps: ['A formula-based component.', 'New salary component.'], result: 'Basic (40% of monthly CTC), HRA (50% of basic), Conveyance, Special allowance (balancing), Bonus…; statutory lines are never configured here.' });
    cmp({ title: 'Edit a component; review the structure', eyebrow: 'Stage 16 · Pay',
        left: { label: 'Basic (edit)', img: C('90-onb-74-salary-component-edit'), callouts: false, caption: 'The formula is validated on save; a reason is required and approval may apply (Change Centre).' },
        right: { label: 'Salary structures', img: C('90-onb-75-salary-structures'), callouts: ['1'], caption: 'Standard structure v1 is seeded; new versions are drafted, submitted and approved.' } });
    d.stageCard(st(17));
    shot(C('90-onb-76a-registration-needs-establishment'), { title: 'Registrations belong to an establishment', eyebrow: 'Stage 17 · Statutory', steps: ['The rule message.'], result: '"A EPF establishment code registration belongs to an establishment." Nothing is saved. Create establishments first (stage 6).' });
    shot(C('90-onb-76-new-statutory-registration'), { title: 'Record a statutory registration', eyebrow: 'Stage 17 · Statutory', steps: ['Legal entity.', 'Establishment.', 'Registration type.', 'Number.'], result: 'EPF, ESIC, PT, LWF and TAN registrations are effective-dated records.' });
    cmp({ title: 'Registrations and statutory profiles', eyebrow: 'Stage 17 · Statutory',
        left: { label: 'Statutory registrations', img: C('90-onb-77-statutory-registrations'), callouts: false, caption: 'The EPF establishment code with its state and effective date.' },
        right: { label: 'New statutory profile', img: C('90-onb-78-new-establishment-statutory-profile'), callouts: ['1', '2', '3'], caption: 'Tells payroll which statutes apply where. Record the registration first: a profile saved earlier has no registration linked.' } });
    cmp({ title: 'Rule verification is Markedge\'s job, and it is not done', eyebrow: 'Stage 17 · Statutory',
        left: { label: 'Compliance control room', tone: 'bad', img: C('90-onb-80-compliance-control-room'), callouts: false, caption: 'Rules this tenant needs per establishment and whether each is verified; the production gate.' },
        right: { label: 'Compliance rules (read only)', tone: 'bad', img: C('90-onb-81-compliance-rules'), callouts: false, caption: 'Rule versions shipped by the platform with their verification status: 0 of 24 verified.' },
        notes: 'SAY: Payroll can be configured and calculated, but not finalised, until two platform administrators verify each rule against official evidence. This is release gate 5 (owner decisions O-5, O-12, O-18). Agree in writing whether payroll is in the customer\'s first go-live.' });
    d.stageCard(st(18));
    cmp({ title: 'Create a workflow', eyebrow: 'Stage 18 · Workflows',
        left: { label: 'New workflow', img: C('90-onb-82-new-workflow'), callouts: ['1', '2', '3'], caption: 'Name, trigger event (here: probation ending soon) and subject.' },
        right: { label: 'Workflow saved', img: C('90-onb-83-workflow-saved'), callouts: false, caption: 'Versions, the step builder and history.' } });
    shot(C('90-onb-94-workflow-builder'), { title: 'Add steps in the builder, then publish', eyebrow: 'Stage 18 · Workflows', steps: [], result: 'Start → End; approval, task, notification and condition steps go between them. Running instances keep the version they started on. Known limit: "Generate document" steps are skipped (F33).' });
    if (has('client-onboarding/85-wf-03-workflow-versions')) shot('client-onboarding/85-wf-03-workflow-versions', { title: 'A published workflow (Demo tenant)', eyebrow: 'Stage 18 · Workflows', crop: { x: 390, y: 620, w: 1770, h: 700 }, steps: [], result: 'Probation confirmation v1 in the Demo tenant: 6 nodes, manager then HR approval.' });
    d.stageCard(st(19));
    shot(C('90-onb-84-notification-rules'), { title: 'Notification rules: none, and none can be created', eyebrow: 'Stage 19 · Notifications', status: 'INCOMPLETE', steps: [], result: 'No rules and no New action (F18). E-mail notices for leave and approvals are not sent unless a rule exists. Markedge must seed or enable rules until this is fixed.' });
    if (has('client-onboarding/85-wf-09-delivery-log')) shot('client-onboarding/85-wf-09-delivery-log', { title: 'Delivery log (Demo tenant)', eyebrow: 'Stage 19 · Notifications', steps: [], result: 'Each delivery with channel and status. SMS, WhatsApp and push send nothing (F19). Queued items need a queue worker.' });
    d.stageCard(st(20));
    shot(C('90-onb-85-security-policy'), { title: 'Security policy', eyebrow: 'Stage 20 · Security', steps: [[3, 'Save policy']], result: 'Require MFA, session and idle timeouts, password rules, retention days. Recommend MFA required for all administrators. Password expiry is stored but not enforced (F2).' });
    cmp({ title: 'Settings and feature flags', eyebrow: 'Stage 20 · Configuration',
        left: { label: 'Settings', img: C('90-onb-86-settings'), callouts: false, caption: 'Seeded keys (attendance windows, probation months, code padding…). Several keys have no effect (F30).' },
        right: { label: 'Feature flags', img: C('90-onb-87-feature-flags'), callouts: ['1', '2'], caption: 'AI assistants on; external language model off; configuration approval off by default.' } });

    // ---- Phase 6: Validate
    d.section(6, 'Validate', 'Train pilot users, reconcile data, run UAT, fix defects and test access.');
    d.stageCard(st(21));
    d.stageCard(st(22));
    const rep = any('analytics/80-ins-06-report-result');
    if (rep) shot(rep, { title: 'Reconcile with a starter report (Demo tenant)', eyebrow: 'Stage 22 · Reconciliation', steps: ['Grouped results.', 'Export CSV.'], result: 'Compare counts by department with the customer\'s source file. A "—" group means records without a department.' });
    d.stageCard(st(23));
    d.cards({ title: 'UAT: what to test, with what', eyebrow: 'Stage 23 · UAT',
        cards: [
            { head: 'Test cases', body: 'supporting/PeopleOS-UAT-Test-Cases.xlsx: steps, expected result, and what Markedge observed in the training environment.' },
            { head: 'Test accounts', body: 'One test user per role; never test only as the super administrator.' },
            { head: 'Synthetic data', body: 'Use invented people in a UAT tenant. Never real personal identifiers in a shared environment.', tone: 'warn' },
            { head: 'Evidence', body: 'A screenshot per step that changes the screen; record actual results and defect references.' },
        ], notes: 'Deck 11 covers UAT execution and go-live criteria in detail.' });
    d.stageCard(st(24));
    d.table({ title: 'Known defects to log before UAT starts', eyebrow: 'Stage 24 · Defects',
        columns: ['Ref', 'Defect', 'Effect on implementation', 'Workaround'], colW: [0.7, 3.6, 4.2, 3.63],
        rows: [
            ['F25', 'Employee / punch import: Map columns fails', 'Bulk load impossible from the screen', 'Hire wizard per person; API (not exercised here)'],
            ['F38', 'Leave requests › Apply on behalf: server error', 'HR cannot apply leave from the list', 'Employee 360 › Records › Time › Leave › Apply for leave'],
            ['F44', 'Report builder field lists empty', 'No new grouped reports', 'Starter reports; People analytics'],
            ['F18', 'Notification rules cannot be created', 'No e-mail for leave and approvals', 'Markedge seeds rules'],
            ['F17', 'Give feedback errors on MySQL', 'Employee sees an error; HR not notified', 'None on screen'],
            ['F26', 'History tab errors outside production', 'Seen in UAT if not production mode', 'Use Change history'],
        ], fontSize: 12, notes: 'SOURCE: docs/reports/documentation-gaps.md; each re-observed or confirmed in code on 21 Oct 2026 (F25, F38, F44 live in this training).' });
    d.stageCard(st(25));
    if (has('security-compliance/85-acc-08-role-edit-denied-hr-admin')) shot('security-compliance/85-acc-08-role-edit-denied-hr-admin', { title: 'Access test: a refusal is a pass', eyebrow: 'Stage 25 · Access validation', steps: ['Access refused.'], result: '"This page is not available to you." A Tenant HR Admin cannot edit role permissions; only a Tenant Super Admin can.' });

    // ---- Phase 7: Go live
    d.section(7, 'Go live and govern', 'Decide together, monitor, hand over to support, and keep governing.');
    d.stageCard(st(26));
    const ready = any('platform-admin/95-pl-23-readiness');
    if (ready) shot(ready, { title: 'Platform readiness (operator)', eyebrow: 'Stage 26 · Go-live readiness', steps: [], result: 'Operators check readiness before any go-live: statutory status, operator MFA, demo accounts, backup and DR evidence.' });
    d.stageCard(st(27));
    const ops = any('platform-admin/95-pl-21-operations');
    if (ops) shot(ops, { title: 'Monitor queues and failed jobs', eyebrow: 'Stage 27 · Hypercare', steps: [], result: 'Platform › Operations: queues, failed jobs and scheduled commands. Requires a running queue worker and scheduler (hosting gate 8).' });
    d.stageCard(st(28));
    cmp({ title: 'Govern changes after go-live', eyebrow: 'Stage 28 · Governance',
        left: { label: 'Change Centre', img: C('90-onb-88-change-centre'), callouts: false, caption: 'Pending approval, scheduled, published, rejected. Empty here: configuration approval is off and creates apply immediately (F29).' },
        right: { label: 'Change history', img: C('90-onb-89-audit-trail'), callouts: ['1', '2'], caption: 'Every create, change, invitation, sign-in and role change of the implementation, newest first.' } });
    shot(C('90-onb-90-audit-verified'), { title: 'Verify the audit chain', eyebrow: 'Stage 28 · Governance', steps: ['The result.'], result: '"Chain intact: 287 events verified" for the whole Northwind implementation. Keep this as evidence at handover and at each quarterly review.' });
    d.cards({ title: 'What Markedge still does by hand', eyebrow: 'Manual work',
        cards: [
            { head: 'Bulk data load', body: 'Until F25 is fixed: hire wizard per person, or an API load by Markedge.', tone: 'bad' },
            { head: 'Notification rules', body: 'Seed or enable rules for the customer (F18).', tone: 'warn' },
            { head: 'Statutory verification', body: 'Two platform administrators verify each rule with official evidence (0 of 24 today).', tone: 'bad' },
            { head: 'Mail and branding', body: 'Production sender name and domain (invitations show "Laravel" in development).', tone: 'warn' },
            { head: 'Hosting and support', body: 'Environment, monitoring, on-call, backup and restore tests: release gates 7 and 8.', tone: 'bad' },
            { head: 'Commercial terms', body: 'Plans, prices and billing are switched off (shadow mode); contracts handled outside PeopleOS.', tone: 'info' },
        ], notes: 'SAY: Be explicit with customers about these items in the plan and the statement of work.' });
    d.closing({ points: ['Operators provision and invite; customer administrators configure.', 'Build structure first, then people, then policies.', 'Most screens were executed on a fictional tenant built from empty; a few come from the Demo tenant and say so.', 'Import, statutory verification and go-live are blocked today; plan around them.', 'Use the implementation and handover checklists as the record of each stage.'],
        next: ['supporting/PeopleOS-Customer-Implementation-Checklist.xlsx', 'supporting/PeopleOS-Go-Live-Handover-Checklist.xlsx', 'Deck 11: UAT and go-live', 'Deck 05: platform operator tasks'],
        notes: `EVIDENCE: ${NW}` });
};
