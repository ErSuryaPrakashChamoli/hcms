// Deck 9 — Executive and client demo.
'use strict';
const common = require('./common');

module.exports = (lib) => {
    const { Deck, has, find } = lib;
    const d = new Deck({
        num: 9, file: '09-PeopleOS-Executive-and-Client-Demo.pptx', short: 'Executive and client demo',
        title: 'PeopleOS: Executive and Client Demo',
        subtitle: 'One lifetime record per person, decisions with context, governance you can prove, shown on the real product, with an honest view of what is live today.',
        audience: 'Customer executives and decision makers; Markedge leadership',
    });
    const shot = (name, o) => { if (has(name)) d.shot({ img: name, ...o }); else lib.WARN.push(`deck 9: missing ${name}`); };
    const wide = (name, o) => { if (has(name)) d.wide({ img: name, ...o }); else lib.WARN.push(`deck 9: missing ${name}`); };
    const cmp = (o) => { if (has(o.left.img) && has(o.right.img)) d.compare(o); else lib.WARN.push(`deck 9: missing ${o.left.img} / ${o.right.img}`); };
    const first = (...n) => n.find((x) => x && has(x));

    d.cover({ shot: 'analytics/80-ins-02-workforce-pulse', notes: 'SAY: Everything you will see is the real PeopleOS, running on a demonstration database with invented people. I will also tell you exactly what is not live yet.\nAVOID: promising dates, prices or service levels: none is set in the product documentation ("to be confirmed with the SaaS provider").' });
    d.cards({ title: 'PeopleOS in one slide', eyebrow: 'Overview', cards: [
        { head: 'One lifetime record', body: 'Every person has one record from hire to alumni; every change is dated, explained and audited.', tone: 'info' },
        { head: 'Work comes to people', body: 'Home and the Approval Center rank what matters, with the context to decide.', tone: 'info' },
        { head: 'Governance built in', body: 'Tenant isolation, layered access, separation of duties and a verifiable audit chain.', tone: 'info' },
        { head: 'Not yet generally available', body: 'Release gate NO-GO: hosting, independent review, DR and statutory verification are open. Payroll finalisation is blocked until statutory rules are verified.', tone: 'warn' },
    ], notes: 'SOURCE: product blueprint principles; release gate (docs/saas/SaaS-Final-Release-Gate.md). Lead with the value, then be explicit about the gate.' });
    d.numbered({ title: 'What we will show', eyebrow: 'Agenda', items: [
        ['Employee experience', 'self-service in minutes'], ['Manager experience', 'decisions with context'], ['HR operations and lifecycle', 'hire to exit, governed'],
        ['Workflow and approvals', 'versioned, multi-person'], ['Workforce analytics', 'facts with sources'], ['Security, governance and SaaS operations', 'proof, not promises'],
    ] });

    d.section(1, 'Employee experience', 'Everything an employee needs, starting from Home.');
    wide('employee/30-self-04-home', { title: 'Home: what matters today, and why', eyebrow: 'Employee', caption: 'Ranked items with their reason, a one-click Request leave, and facts with sources from PeopleOS Intelligence.' });
    shot('employee/10-leave-04-form-completed', { title: 'Leave in under a minute', eyebrow: 'Employee', callouts: ['1', '2', '3', '5', '6'], renumber: { 5: 4, 6: 5 }, steps: [[1, 'Type with balance.'], [2, 'From.'], [3, 'To.'], ['·', 'Before → After preview.'], [4, 'Reason.'], [5, 'Submit.']], result: 'Working days and the balance after the request, before it is sent.' });
    cmp({ title: 'Help from HR, on any device', eyebrow: 'Employee',
        left: { label: 'Ask HR', img: 'employee/30-self-37-ask-hr-submitted', callouts: false, caption: 'A numbered request with a target date.' },
        right: { label: 'Phone', img: 'employee/30-self-50-phone-home', maxAspect: 2.2, callouts: false, caption: 'Responsive web on phones (no native app).' } });

    d.section(2, 'Manager experience', 'Decide with the facts in front of you.');
    wide('manager/10-leave-07-approval-center', { title: 'The Approval Center', eyebrow: 'Manager', caption: 'Every decision in one place, most urgent first, with a summary that explains and never decides.' });
    shot('manager/10-leave-08-decision-card', { title: 'Context for every decision', eyebrow: 'Manager', callouts: ['1', '4', '5'], renumber: { 4: 2, 5: 3 }, place: { 4: 'left', 5: 'right' }, steps: [[1, 'Reason.'], ['·', 'Team overlap.'], [2, 'Approve.'], [3, 'Reject (with a reason).']], result: 'Balance before → after and the effective date on the same card.' });

    d.section(3, 'HR operations and lifecycle', 'One record, guided changes, multi-person controls.');
    wide('hr-admin/50-360-01-header-now', { title: 'Employee 360', eyebrow: 'HR', caption: 'Status, tenure and manager; actions for every lifecycle change; views from Now to Records.' });
    cmp({ title: 'No duplicates; history kept', eyebrow: 'HR',
        left: { label: 'Duplicate person refused', tone: 'bad', img: 'hr-admin/50-hire-01-duplicate-refused', callouts: false, caption: 'The hire wizard finds the existing person first.' },
        right: { label: 'Journey', img: 'hr-admin/50-360-04-journey', callouts: false, caption: 'Every dated change, from joining to today.' } });
    cmp({ title: 'Pay changes need four people', eyebrow: 'HR',
        left: { label: 'Reviewer cannot approve', img: 'security-compliance/62-comp-07-reviewer-cannot-approve', callouts: ['1'], caption: 'Propose, review, approve and execute by four different people.' },
        right: { label: 'Executed', tone: 'good', img: 'hr-admin/62-comp-10-executed', callouts: false, caption: 'Scheduled for its effective date.' } });
    const exitShot = first('hr-admin/70-exit-11-ff-settlement');
    if (exitShot) shot(exitShot, { title: 'To exit and alumni', eyebrow: 'HR', callouts: ['2'], renumber: { 2: 1 }, steps: [[1, 'Breakdown: each line with its basis.']], result: 'Resignation, clearance, full & final settlement (net 38,254.06 in the example) and an alumni profile in one case. Gratuity is not calculated yet.' });
    cmp({ title: 'Payroll: safe by design', eyebrow: 'HR',
        left: { label: 'Control room', img: 'hr-admin/60-pay-01-control-room', callouts: false, caption: 'Readiness and the statutory rules that block finalisation.' },
        right: { label: 'Validation refused', tone: 'bad', img: 'hr-admin/60-pay-09-validate-blocked', callouts: false, caption: 'No payslip on an unverified statutory rate: payroll finalisation is not live yet.' },
        notes: 'SAY: This is the safeguard working. Payroll goes live only after statutory rules are verified (0 of 24 today) or payroll is excluded from scope in writing.' });

    d.section(4, 'Workflow and approvals', 'Processes as versioned data.');
    cmp({ title: 'Workflows you can change safely', eyebrow: 'Workflow',
        left: { label: 'Versions and builder', img: first('client-onboarding/85-wf-03-workflow-versions', 'client-onboarding/90-onb-94-workflow-builder'), callouts: false, caption: 'Steps for approvals, tasks and notifications; running cases keep their version.' },
        right: { label: 'Onboarding plan', img: 'hr-admin/50-onb-03-plan-tasks', callouts: false, caption: 'Tasks for HR, the manager and the joiner, started automatically on hire.' } });

    d.section(5, 'Workforce analytics', 'Facts from the records each viewer may see.');
    wide('analytics/80-ins-04-hr-overview-dashboard', { title: 'HR overview', eyebrow: 'Analytics', caption: 'Headcount, attrition, absenteeism, trends and what needs attention. People cost appears once payroll is finalised.' });
    cmp({ title: 'Pulse and answers with sources', eyebrow: 'Analytics',
        left: { label: 'Workforce pulse', img: 'analytics/80-ins-02-workforce-pulse', callouts: false, caption: 'What moved this month; select a figure to see the people behind it.' },
        right: { label: 'Ask PeopleOS', img: 'analytics/80-ins-17-ask-peopleos', callouts: false, caption: '"What is our attrition?" 4.5%, with its source. Explains; never decides.' },
        notes: 'KNOWN GAP: executive dashboards need dataset permissions added to the Executive role (F45); custom report building has a defect (F44).' });

    d.section(6, 'Security, governance and SaaS operations', 'Controls you can see and verify.');
    cmp({ title: 'Audit you can verify', eyebrow: 'Governance',
        left: { label: 'Event integrity', img: 'security-compliance/80-ins-11b-audit-integrity', callouts: false, caption: 'Who, where, before → after; each event chained to the previous one.' },
        right: { label: 'Whole chain', img: 'client-onboarding/90-onb-90-audit-verified', callouts: false, caption: '"Chain intact: 287 events verified".' } });
    cmp({ title: 'Access and isolation', eyebrow: 'Governance',
        left: { label: 'Scope on an HR account', img: 'security-compliance/85-acc-06b-user-company-scope', callouts: false, caption: 'HR reach limited to one company.' },
        right: { label: 'Outside a manager\'s team', img: 'manager/40-team-09-outside-team', callouts: false, caption: 'Not found: records outside reach are not revealed.' } });
    cmp({ title: 'Operated as SaaS by Markedge', eyebrow: 'SaaS',
        left: { label: 'Tenants', img: 'platform-admin/95-pl-05-tenants', callouts: false, caption: 'Operators sign in with MFA; every tenant action carries a reason.' },
        right: { label: 'Readiness', img: 'platform-admin/95-pl-23-readiness', callouts: false, caption: 'PASS / WARN / FAIL / BLOCKED checks before any go-live.' } });

    d.table({ title: 'What is live, and what is not yet', eyebrow: 'Honest scope', columns: ['Capability', 'Status today'], colW: [8.0, 4.13], rows: [
        ['Employee and manager self-service, approvals, attendance, leave', 'VERIFIED IN RUNNING APPLICATION'],
        ['Employee lifecycle: hire, changes, onboarding, documents, letters, exit (gratuity not calculated)', 'VERIFIED IN RUNNING APPLICATION'],
        ['Performance, learning, compensation governance, analytics, audit', 'VERIFIED IN RUNNING APPLICATION'],
        ['Payroll finalisation, payslips, statutory returns', 'DISABLED BY SAFETY OR COMMERCIAL GATES'],
        ['Bulk employee import from the screen', 'INCOMPLETE'],
        ['Custom report builder (Group by, filters, sort)', 'INCOMPLETE'],
        ['Executive dashboard report widgets (dataset permissions)', 'AVAILABLE ONLY AFTER CONFIGURATION'],
        ['Self-service signup, billing, tenant deletion', 'DISABLED BY SAFETY OR COMMERCIAL GATES'],
        ['Native mobile app; SAML SSO; SMS/WhatsApp notifications', 'NOT IMPLEMENTED'],
    ], notes: 'SOURCE: this training library\'s captures and FEATURE-TO-PRESENTATION-MATRIX.md; release gate. "Verified" means executed on a demonstration database, not released to customers.' });
    d.phaseMap({ title: 'How a customer goes live', eyebrow: 'Implementation', phases: [
        { name: 'Plan', items: ['Scope and data owners', 'Payroll scope decision'] },
        { name: 'Provision', items: [['Tenant by Markedge', 'op'], 'Administrator activates'] },
        { name: 'Structure', items: ['Organisation', 'Jobs and positions'] },
        { name: 'People', items: ['Hire / load people', ['Bulk import (F25)', 'bad'], 'Roles and scope'] },
        { name: 'Policies', items: ['Time and leave', 'Pay components', ['Statutory rules', 'bad']] },
        { name: 'Validate', items: ['Reconcile', 'UAT', 'Access tests'] },
        { name: 'Go live', items: [['Release gate GO', 'bad'], 'Hypercare', 'Handover'] },
    ], caption: 'Deck 04 details all 28 stages. Red items are blocked today by a defect or a release gate.' });
    common.statusDetailed(d, 'Appendix');
    d.closing({ title: 'Next steps', points: ['See it with your own data model in a demonstration tenant.', 'Agree scope, including whether payroll is in the first go-live.', 'Plan the implementation with the 28-stage playbook.', 'Release timing follows the release gate; we will not go live before it is GO.'],
        next: ['Commercial terms, hosting region and service levels: agreed in the customer contract (not yet set)', 'Deck 04: implementation playbook', 'Deck 08: security and compliance'] });
    return d;
};
