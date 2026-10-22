// Deck 6 — Markedge internal PeopleOS operations (Markedge as its own employer).
'use strict';
const common = require('./common');

module.exports = (lib) => {
    const { Deck, has } = lib;
    const d = new Deck({
        num: 6, file: '06-Markedge-Internal-PeopleOS-Operations.pptx', short: 'Markedge internal operations',
        title: 'Markedge Internal PeopleOS Operations',
        subtitle: 'How Markedge runs its own workforce on PeopleOS as an ordinary tenant, and how that work stays separate from operating the SaaS platform.',
        audience: 'Markedge internal HR, payroll and people managers; Markedge leadership; SaaS operations staff',
    });
    const I = (n) => `internal-operations/${n}`;
    const shot = (name, o) => { if (has(name)) d.shot({ img: name, ...o }); else lib.WARN.push(`deck 6: missing ${name}`); };
    const cmp = (o) => { if (has(o.left.img) && has(o.right.img)) d.compare(o); else lib.WARN.push(`deck 6: missing ${o.left.img} / ${o.right.img}`); };
    const DEMO = 'Same screen in Markedge\'s internal tenant; shown here from the demonstration tenant (fictional Demo Group), where the workflow was executed.';
    const SRC = 'Executed on the training database on 21 Oct 2026: the operator provisioned "Markedge Technologies (internal workforce)" (fictional), its administrator Sana Qureshi (fictional) activated it, created a company, departments and designations, and hired three fictional employees (tools/capture/96-internal.mjs).';

    d.cover({ shot: I('96-int-03-tenants-side-by-side'), notes: `SAY: Markedge uses PeopleOS for its own people exactly like a customer does: in its own tenant. The platform operators who run the SaaS do not get HR access by being Markedge staff, and Markedge HR gets no platform access.\nEVIDENCE: ${SRC}` });
    d.statusLegend();
    common.statusSimple(d, [{ head: 'Fictional internal tenant', body: 'The Markedge tenant shown here is a training copy with invented employees. No real Markedge staff data was used.', tone: 'info' }], 'Which of your HR tasks still run outside PeopleOS today?');
    d.numbered({ title: 'What you will be able to explain', eyebrow: 'Learning objectives', items: [
        ['Two hats at Markedge', 'employer (internal HR) and SaaS provider (platform operations)'],
        ['How the internal tenant is created', 'by an operator, like any customer'],
        ['What internal HR does in PeopleOS', 'people, time, leave, payroll inputs, growth, services, exits'],
        ['Where the boundary is enforced', 'HR cannot open the platform; operators enter tenants only with a reason'],
        ['What still runs outside PeopleOS', 'payroll finalisation, statutory verification, hosting'],
    ] });
    d.table({ title: 'Who does what at Markedge', eyebrow: 'Responsibilities', columns: ['Activity', 'Markedge internal HR (tenant users)', 'PeopleOS SaaS operations (platform operators)'], colW: [3.4, 4.4, 4.33], rows: [
        ['Employee records, hiring, exits', 'Yes, in the internal tenant', 'No'],
        ['Leave, attendance, approvals', 'Yes (managers decide)', 'No'],
        ['Payroll inputs and runs', 'Yes; finalisation blocked like every tenant', 'No'],
        ['Statutory rule verification', 'No', 'Yes: two operators with evidence'],
        ['Tenant creation, suspension, offboarding', 'No', 'Yes, with reasons, audited'],
        ['Entering another tenant for support', 'Never', 'Only via Enter: reason, ticket, 60 minutes'],
        ['Platform readiness, operations, billing set-up', 'No', 'Yes'],
    ], notes: 'SOURCE: platform-operator flag (is_platform_admin) versus tenant roles; captures 96-int-08 (HR refused on Tenants) and 95-pl-35 (operator Enter).' });

    d.section(1, 'An ordinary tenant for Markedge', 'Provisioned by an operator, administered by Markedge HR.');
    shot(I('96-int-01-provision-internal-tenant'), { title: 'The operator provisions the internal tenant', eyebrow: 'Set-up', steps: ['Internal tenant name.', 'Markedge HR administrator.', 'Reason for change.'], result: 'A separate tenant: Markedge employee data never sits in the operator console.' });
    shot(I('96-int-02-tenant-created'), { title: 'Created like any customer', eyebrow: 'Set-up', steps: [], result: 'The "Created" confirmation and the tenant record. As for any customer, provisioning also adds the system roles, default settings and the administrator\'s invitation (Deck 4, stage 2).' });
    shot(I('96-int-03-tenants-side-by-side'), { title: 'Three tenants, all peers', eyebrow: 'Set-up', steps: ['Markedge\'s own tenant.', 'A customer tenant.'], result: 'Demo Group, Northwind Retail and Markedge Technologies (internal workforce) have the same standing: the internal tenant has no special access.' });
    cmp({ title: 'Inside the internal tenant', eyebrow: 'Set-up',
        left: { label: 'Home (internal administrator)', img: I('96-int-04-internal-home'), callouts: false, caption: 'The same Home as any customer: administration lens and governance items.' },
        right: { label: 'Directory', img: I('96-int-05-internal-directory'), callouts: false, caption: 'Only Markedge\'s own (fictional) employees; no customer data is visible.' } });
    cmp({ title: 'People and reporting lines', eyebrow: 'Set-up',
        left: { label: 'Org map', img: I('96-int-06-internal-org-map'), callouts: false, caption: 'Aditya Kulkarni reports to Rahul Desai.' },
        right: { label: 'Employee 360', img: I('96-int-07-internal-employee-360'), callouts: false, caption: 'The same Employee 360 customers use: status, manager, journey, records.' } });
    shot(I('96-int-08-hr-cannot-open-tenants'), { title: 'Markedge HR cannot open the platform', eyebrow: 'Boundary', steps: [], result: 'Refused: being Markedge staff in HR does not grant platform-operator access. Operator accounts are separate accounts.' });
    if (has('platform-admin/95-pl-35-enter-tenant-dialog')) shot('platform-admin/95-pl-35-enter-tenant-dialog', { title: 'And operators enter tenants only deliberately', eyebrow: 'Boundary', steps: [], result: 'A reason and a ticket reference; access ends after 60 minutes; recorded in the platform audit trail. The same applies to Markedge\'s own tenant.' });

    d.section(2, 'Internal HR operations', 'The work Markedge HR does every month, on the same screens customers use.');
    const demo = (name, o) => shot(name, { ...o, source: `Real screen · ${DEMO}` });
    demo('hr-admin/50-hire-07-created-record', { title: 'Hire a Markedge employee', eyebrow: 'Internal HR', callouts: false, steps: [], result: 'Hire employee wizard with the duplicate check; onboarding starts automatically. Deck 3 shows every step.' });
    demo('hr-admin/50-onb-03-plan-tasks', { title: 'Onboarding new joiners', eyebrow: 'Internal HR', steps: [], result: 'Tasks for HR, IT, the manager and the joiner by phase, with Done and Skip.' });
    demo('manager/10-leave-08-decision-card', { title: 'Managers approve leave and corrections', eyebrow: 'Internal HR', callouts: ['1', '4', '5'], renumber: { 4: 2, 5: 3 }, place: { 4: 'left', 5: 'right' }, steps: [[1, 'Reason.'], [2, 'Approve.'], [3, 'Reject.']], result: 'My work › Approvals shows reason, team overlap and balance before → after.' });
    demo('hr-admin/20-att-13-day-processed', { title: 'Attendance processing', eyebrow: 'Internal HR', steps: [], result: 'Process day turns punches and approved corrections into attendance records.' });
    demo('hr-admin/60-pay-09-validate-blocked', { title: 'Payroll: calculated, not finalised', eyebrow: 'Internal HR', status: 'DISABLED BY SAFETY OR COMMERCIAL GATES', steps: [], result: '"19 employee(s) have blocking exceptions": the unverified statutory rules. Markedge\'s own payroll has the same safeguard; run it outside PeopleOS until the rules are verified.' });
    demo('hr-admin/65-grow-03-appraisals', { title: 'Performance reviews', eyebrow: 'Internal HR', steps: [], result: 'Appraisals for the current cycle with their stage. Goals, calibration and learning are covered in Deck 3.' });
    demo('hr-admin/62-comp-10-executed', { title: 'Compensation changes', eyebrow: 'Internal HR', steps: [], result: 'Four different people propose, review, approve and execute. Plan who holds each role at Markedge.' });
    if (has('hr-admin/52-tkt-05-resolved')) demo('hr-admin/52-tkt-05-resolved', { title: 'HR service requests', eyebrow: 'Internal HR', steps: [], result: 'Employees ask HR from My HR; HR handles the queue with SLAs.' });
    if (has('analytics/80-ins-04-hr-overview-dashboard')) demo('analytics/80-ins-04-hr-overview-dashboard', { title: 'Reporting for Markedge leadership', eyebrow: 'Internal HR', steps: [], result: 'The HR overview dashboard. Workforce pulse and People analytics are in Deck 12; executives need extra dataset permissions for some widgets.' });
    d.cards({ title: 'Running Markedge HR on PeopleOS today', eyebrow: 'Practicalities', cards: [
        { head: 'Use it for', body: 'Records, hiring, onboarding, leave, attendance, documents, letters, performance, learning, service requests and exits.', tone: 'good' },
        { head: 'Keep outside for now', body: 'Payroll finalisation and payslips (statutory rules unverified), bulk employee imports and custom grouped reports (both have known defects).', tone: 'warn' },
        { head: 'Separate accounts', body: 'Staff who are both Markedge employees and platform operators use two accounts: an employee account in the internal tenant and an operator account with MFA.', tone: 'info' },
        { head: 'Same rules as customers', body: 'Release gate NO-GO applies to Markedge\'s own use too: no production hosting, DR or on-call yet.', tone: 'warn' },
    ], notes: 'SAY: Markedge should be its own first customer, but with the same caution: the release gate is NO-GO.' });
    d.closing({ points: ['Markedge\'s workforce lives in an ordinary tenant, provisioned like a customer\'s.', 'Internal HR and platform operations are different accounts with different permissions.', 'Operators enter any tenant, including Markedge\'s, only with a reason, for 60 minutes, audited.', 'Internal HR uses the same screens and has the same limits as customers.'],
        next: ['Deck 03: HR operations in detail', 'Deck 05: platform operator duties', 'Deck 08: access boundaries'], notes: `EVIDENCE: ${SRC}` });
    return d;
};
