// Deck 3 — HR administrator training.
'use strict';
const common = require('./common');
const flows = require('./flows');

module.exports = (lib) => {
    const { Deck, has, find, entry } = lib;
    const d = new Deck({
        num: 3, file: '03-PeopleOS-HR-Administrator-Training.pptx', short: 'HR administrator training',
        title: 'HR Administrator Training',
        subtitle: 'Run people operations: records, hiring, changes, documents, time and leave, payroll inputs, growth, compensation, letters, requests, exits and audit.',
        audience: 'HR administrators, HR managers and payroll administrators of a customer tenant',
    });
    const H = (n) => `hr-admin/${n}`;
    const shot = (name, o) => { if (has(name)) d.shot({ img: name, ...o }); else lib.WARN.push(`deck 3: missing ${name}`); };
    const wide = (name, o) => { if (has(name)) d.wide({ img: name, ...o }); else lib.WARN.push(`deck 3: missing ${name}`); };
    const cmp = (o) => { if (has(o.left.img) && has(o.right.img)) d.compare(o); else lib.WARN.push(`deck 3: missing ${o.left.img} / ${o.right.img}`); };
    const SRC = 'Executed on the training database on 21 Oct 2026 as Kavya Menon (Tenant HR Admin), Neha Kapoor (HR Manager) and Arjun Bose (Payroll Admin), fictional people at the fictional Demo Group (tools/capture/20-, 50-, 55-, 60-, 62-, 65-, 70-, 75-).';
    const DEBUG = 'Shown here as the local debug page (development mode). In production the user sees a generic server-error dialog; the cause is the same.';

    d.cover({ shot: H('50-360-01-header-now'), notes: `SAY: This deck is for the people who run HR operations day to day. Every step was performed in a practice tenant with invented people.\nEVIDENCE: ${SRC}` });
    d.statusLegend();
    common.statusDetailed(d, 'Release status');
    d.numbered({ title: 'What you will be able to do', eyebrow: 'Learning objectives', items: [
        ['Find and read any employee record', 'Directory, register, Employee 360'],
        ['Hire without duplicates; change jobs and managers', 'guided, effective-dated, audited'],
        ['Run onboarding and verify documents', 'with separation of duties'],
        ['Administer attendance and leave', 'processing, balances, policies, known defects'],
        ['Prepare payroll and statutory inputs', 'and understand why finalising is blocked'],
        ['Handle growth, compensation, letters, requests and exits', 'and prove it in the audit trail'],
    ], notes: 'SAY: HR administrators do not automatically see every field: sensitive data and some modules need specific permissions. When a menu is missing, it is usually the role, not a fault.' });
    d.table({ title: 'Who can do what in HR (system roles)', eyebrow: 'Roles',
        columns: ['Task', 'Tenant HR Admin', 'HR Manager', 'Payroll Admin'], colW: [4.73, 2.5, 2.5, 2.4],
        rows: [
            ['Hire, change job or manager, lifecycle state', 'Yes', 'Yes', 'No'],
            ['Upload / verify documents', 'Yes / Yes (not own upload)', 'Yes / Yes (not own upload)', 'No'],
            ['Process attendance, manual punches', 'Yes', 'No', 'No'],
            ['Payroll runs, components, adjustments', 'Yes', 'View structures only', 'Yes'],
            ['Statutory returns screens (EPF, ESI, PT, LWF, TDS)', 'Yes', 'No', 'No (F15)'],
            ['Propose / review / approve / execute compensation', 'Propose, review, approve', 'Propose, review', 'Execute'],
            ['Employee imports', 'Yes', 'No (page not available)', 'No'],
            ['Edit role permissions', 'No (F5)', 'No', 'No'],
        ], fontSize: 12,
        notes: 'SOURCE: config/peopleos.php role templates as summarised in docs/reports/feature-inventory.md and implementation-status.md; F5, F15 from documentation-gaps.md. Only a Tenant Super Admin edits role permissions. Customers can change role templates.' });

    // ---- 1. People records
    d.section(1, 'People records', 'Directory, register and the Employee 360: one lifetime record per person.');
    shot(H('50-dir-01-directory-cards'), { title: 'People Directory', eyebrow: 'People records', steps: ['Search.', 'Filters.', 'Cards / Table.', 'Employee register.'], result: 'Everyone you may see, with department, location, status and grouping filters.' });
    cmp({ title: 'Preview and register', eyebrow: 'People records',
        left: { label: 'Person preview', img: H('50-dir-02-person-preview'), callouts: ['1', '2'], caption: 'Role, manager and quick actions: Open profile, Message, Org map, Start a change.' },
        right: { label: 'Employees register', img: H('50-dir-03-employee-register'), callouts: ['1', '4'], caption: 'Table with ID, designation, department, manager, status; Hire employee; View and Edit.' } });
    shot(H('50-360-01-header-now'), { title: 'Employee 360: the header and Now', eyebrow: 'Employee 360', callouts: false, steps: [], result: 'Status, tenure, manager; Summarise, Message, Request, Action and More; views Now, Journey, Work, Growth, Rewards, Documents and Records. Now shows what is next for the person.',
        notes: 'OBSERVE: Priya Nair, Probation, "One lifetime record", reports to Amit Verma. Identity and access panel shows sign-in status and organisation scope.' });
    cmp({ title: 'Action and More menus', eyebrow: 'Employee 360',
        left: { label: 'Action', img: H('50-360-02-action-menu'), callouts: false, caption: 'Transfer / promote, Change manager, Change lifecycle state, Start onboarding, Initiate background check, Assign work schedule, Start workflow.' },
        right: { label: 'More', img: H('50-360-03-more-menu'), callouts: false, caption: 'Edit profile, All changes, Statutory details, Edit statutory details. Menus depend on your permissions.' },
        notes: 'KNOWN VISUAL ISSUE F27: the dropdown can open under the sticky view bar; scroll slightly if an item is hidden.' });
    shot(H('50-360-04-journey'), { title: 'Journey: the effective-dated history', eyebrow: 'Employee 360', steps: [], result: 'Joining, onboarding, probation, assets, leave and manager assignment in order. Select a stage to see what happened in it.' });
    cmp({ title: 'Records: employment and time', eyebrow: 'Employee 360',
        left: { label: 'Employment', img: H('50-360-05-records-employment'), callouts: false, caption: 'Position assignments and reporting lines, each with from/to dates and change type.' },
        right: { label: 'Time', img: H('50-360-06-records-time'), callouts: false, caption: 'Attendance and leave for this person; Apply for leave on their behalf.' } });
    cmp({ title: 'Records: rewards and operations', eyebrow: 'Employee 360',
        left: { label: 'Rewards', img: H('50-360-07-records-rewards'), callouts: false, caption: 'Compensation, compensation changes and payslips, only with the matching permission.' },
        right: { label: 'Operations', img: H('50-360-08-records-operations'), callouts: false, caption: 'Documents (Upload, Verify, Reject), letters and requests.' } });
    cmp({ title: 'Different HR roles, different record tabs', eyebrow: 'Employee 360',
        left: { label: 'Tenant HR Admin', img: 'security-compliance/50-sec-01-360-records-tenant-hr-admin', callouts: ['1'], caption: 'Timeline, Employment, Time, Growth, Rewards, Operations, Bank & personal, History.' },
        right: { label: 'HR Manager', img: 'security-compliance/50-sec-02-360-records-hr-manager', callouts: ['1'], caption: 'No Growth; Personal instead of Bank & personal: no bank or identity details.' },
        notes: 'SAY: HR administrators do not all see the same fields. Sensitive data needs the sensitive permission.' });
    shot(H('50-360-09-records-bank-personal'), { title: 'Bank & personal: sensitive and permission-controlled', eyebrow: 'Employee 360', steps: [], result: 'Sections for addresses, family and nominees, emergency contacts, bank accounts and identifiers (empty for this fictional employee). Sensitive fields need *.sensitive.* permissions and are masked otherwise.' });
    cmp({ title: 'Two errors you may meet on the 360 (development mode)', eyebrow: 'Employee 360',
        left: { label: 'History tab', tone: 'bad', img: H('50-360-10-records-history'), callouts: false, caption: 'LazyLoadingViolationException (F26) in non-production strict mode. Use Insights › Audit › Change history instead.' },
        right: { label: 'Employment section on scroll', tone: 'bad', img: 'support/50-360-err-01-records-employment', callouts: false, caption: 'TypeError in a lazily loaded section (new finding, 21 Oct 2026).' },
        notes: `${DEBUG}\nFINDINGS: F26 (documented) and a new TypeError in the Records › Employment lower section, observed live; both recorded in the accuracy register.` });

    // ---- 2. Hire
    d.section(2, 'Hire and person matching', 'Four guided steps; the person is matched before anything is created.');
    d.purpose({ title: 'Hire an employee', eyebrow: 'Hire', goal: 'Create one lifetime record for a new joiner, placed in the organisation with a manager, without duplicating a person who already exists.',
        who: 'Tenant HR Admin, HR Manager, HR Executive (employee.create)', where: 'People › Lifecycle › Employees › Hire employee', before: 'Organisation, designations and the manager\'s record exist (Deck 4, stages 4–8).',
        flow: [['START', 'Employees'], ['PERSON', 'duplicate check'], ['EMPLOYMENT', 'dates, code'], ['POSITION', 'placement'], ['MANAGER', 'reason → create']], status: 'VERIFIED IN RUNNING APPLICATION' });
    shot(H('50-hire-01-duplicate-refused'), { title: 'The duplicate check stops a second record', eyebrow: 'Hire', callouts: ['1', '2'], steps: [], result: '"This person already exists: Priya Nair (EMP00004), same personal email." The wizard does not move on. Re-use the existing person or correct the details.', notes: 'SAY: Definite duplicates (same e-mail, phone or work e-mail) are refused; possible duplicates are warned. One person, one lifetime record.' });
    for (const [n, t] of [['50-hire-02-person-step', 'Step 1: Person'], ['50-hire-03-employment-step', 'Step 2: Employment'], ['50-hire-04-position-step', 'Step 3: Position'], ['50-hire-05-manager-step', 'Step 4: Manager']]) {
        if (has(H(n))) shot(H(n), { title: t, eyebrow: 'Hire', steps: (entry(H(n)).callouts ?? []).map((c) => c.label ?? ''), result: entry(H(n)).expected });
    }
    shot(H('50-hire-07-created-record'), { title: 'Created: the new Employee 360', eyebrow: 'Hire', steps: [], result: 'Tanvi Joshi (EMP00023), Senior Software Engineer, Probation; onboarding started automatically and the hire is audited.' });

    // ---- 3. Job and manager changes
    d.section(3, 'Job, manager and lifecycle changes', 'Guided changes with Before → After, an effective date and a reason.');
    shot(H('50-chg-01-transfer-context'), { title: 'Transfer / promote: 1 Context', eyebrow: 'Changes', steps: ['Kind of change.', 'Effective from.', 'Next.'], result: 'Three guided steps: Context, Change, Review. This first attempt used today\'s date (21 Oct) and is refused at the end; the retry with 22 Oct succeeded.' });
    shot(H('50-chg-02-transfer-change'), { title: 'Transfer / promote: 2 Change', eyebrow: 'Changes', steps: ['New team.'], result: 'Only the field that changes is edited.' });
    shot(H('50-chg-03-transfer-review'), { title: 'Transfer / promote: 3 Review', eyebrow: 'Changes', callouts: ['2', '3'], renumber: { 2: 1, 3: 2 }, steps: [['·', 'Before → After panel: today\'s team and the new team.'], [1, 'Reason (audited).'], [2, 'Confirm.']], result: 'Confirming with today\'s date is refused (next slide).' });
    cmp({ title: 'Same-day changes are refused', eyebrow: 'Changes',
        left: { label: 'Effective today (the message)', tone: 'bad', img: H('50-chg-04-transfer-refused-same-day'), crop: { x: 1500, y: 20, w: 640, h: 180 }, callouts: false, caption: '"Not allowed: The new position must start after the current one (2026-10-21)." Nothing changes.' },
        right: { label: 'Effective tomorrow (the message)', tone: 'good', img: H('50-chg-04b-transfer-done'), crop: { x: 1500, y: 20, w: 640, h: 180 }, callouts: ['1'], caption: '"Position assigned": scheduled for 22 Oct and shown on the Journey.' } });
    shot(H('50-chg-05-manager-context'), { title: 'Change manager: 1 Context', eyebrow: 'Changes', steps: (has(H('50-chg-05-manager-context')) ? entry(H('50-chg-05-manager-context')).callouts ?? [] : []).map((c) => c.label ?? ''), result: 'Choose which relationship changes: line, dotted, project, mentor…' });
    shot(H('50-chg-06-manager-change'), { title: 'Change manager: 2 Change', eyebrow: 'Changes', steps: [], result: 'New line manager Ravi Kumar and the date it takes effect. Only employed people are offered.' });
    shot(H('50-chg-07-manager-review'), { title: 'Change manager: 3 Review', eyebrow: 'Changes', steps: [], result: 'Amit Verma → Ravi Kumar, with the reason. Confirm reporting change.' });
    shot(H('50-chg-08-manager-done'), { title: 'Recorded on the record', eyebrow: 'Changes', callouts: false, steps: [], result: 'Header: Reports to Ravi Kumar. History is never overwritten: the previous line stays with its end date.' });
    shot(H('50-chg-09-lifecycle-modal'), { title: 'Change lifecycle state', eyebrow: 'Changes', steps: [], result: 'Only allowed next states; effective date and reason required. Opened and cancelled here. Exit uses the exit case, not this dialog.' });

    // ---- 4. Onboarding
    d.section(4, 'Onboarding', 'A plan starts on hire; tasks go to HR, the manager and the new joiner.');
    shot(H('50-onb-01-plans'), { title: 'Onboarding plans', eyebrow: 'Onboarding', steps: [], result: 'Plans in progress with joining date, progress, open and overdue tasks. The new hire\'s plan started automatically.' });
    shot(H('50-onb-02-template'), { title: 'Onboarding template', eyebrow: 'Onboarding', steps: [], result: 'Name, priority (lower wins), status and Applies to conditions; checklist items with phase, owner and due days.' });
    shot(H('50-onb-03-plan-tasks'), { title: 'Tasks on the plan', eyebrow: 'Onboarding', steps: [], result: 'By phase (Preboarding, Day 1, First week, 30 days), with owner, due date and status; Done or Skip per task.' });
    cmp({ title: 'Complete a task', eyebrow: 'Onboarding',
        left: { label: 'Done', img: H('50-onb-04-task-done-confirm'), callouts: false, caption: 'Confirm completion; document tasks require the document.' },
        right: { label: 'Completed', tone: 'good', img: H('50-onb-05-task-completed'), callouts: false, caption: 'Status done with who and when (the plan\'s progress figure updates after a refresh).' } });
    cmp({ title: 'Manager tasks, and a template error', eyebrow: 'Onboarding',
        left: { label: 'Manager\'s Task inbox', img: 'manager/50-onb-06-manager-onboarding-tasks', callouts: false, caption: 'Tasks assigned to the manager: meet the team, 30-day goals, check-in; Done / Skip.' },
        right: { label: 'Template › Checklist items', tone: 'bad', img: 'support/50-onb-err-01-template-items-lazy-loading', callouts: false, caption: 'LazyLoadingViolationException in development mode (new finding). Items were edited before this error appeared in the demo.' },
        notes: DEBUG });

    // ---- 5. Documents
    d.section(5, 'Employee documents', 'Upload by one person, verification by another.');
    shot(H('50-doc-01-upload-form'), { title: 'Upload a document', eyebrow: 'Documents', steps: [], result: 'PDF, JPEG, PNG or Word, up to 10 MB. Saved as Pending verification.' });
    shot(H('50-doc-02-uploaded-pending'), { title: 'Pending verification', eyebrow: 'Documents', callouts: false, steps: [], result: 'Download (10-minute link), Verify and Reject.' });
    shot('security-compliance/50-doc-03-uploader-cannot-verify', { title: 'The uploader cannot verify', eyebrow: 'Documents', status: 'INCOMPLETE', steps: [],
        result: 'The rule holds ("The person who uploaded a document cannot verify it.") but it surfaces as a server error instead of a message. Nothing is changed. Ask a second HR person to verify.', notes: `${DEBUG}\nNEW FINDING (21 Oct 2026): separation of duties works, but the refusal is an unhandled RuntimeException rather than a notification.` });
    cmp({ title: 'A second HR person verifies', eyebrow: 'Documents',
        left: { label: 'Verify', img: H('50-doc-04-verify-confirm'), callouts: false, caption: 'Neha Kapoor (HR Manager) verifies a document Kavya uploaded.' },
        right: { label: 'Verified', tone: 'good', img: H('50-doc-05-verified'), callouts: false, caption: 'Status Verified, with who verified it.' } });

    // ---- 6. Attendance
    d.section(6, 'Attendance administration', 'Punches become records when the day is processed.');
    flows.attendanceHr(d);

    // ---- 7. Leave
    d.section(7, 'Leave administration', 'Balances, ledger, types, policies, requests, and the workaround for Apply on behalf.');
    shot(H('55-lv-01-balances'), { title: 'Balances', eyebrow: 'Leave', steps: [], result: 'Per person, type and year: opening, accrued, adjusted, used, pending, encashed, lapsed, closing, available. Adjust, Encash and Run accrual now.' });
    cmp({ title: 'Adjust a balance', eyebrow: 'Leave',
        left: { label: 'Adjust', img: H('55-lv-02-adjust-form'), callouts: false, caption: 'Days (+ or −) and a note; posted to the ledger.' },
        right: { label: 'Adjusted', tone: 'good', img: H('55-lv-03-adjusted'), callouts: false, caption: 'Casual leave: adjusted 1.0; Available updated.' } });
    shot(H('55-lv-04-ledger'), { title: 'The ledger records the adjustment', eyebrow: 'Leave', steps: [], result: 'Append-only: the adjustment is its own entry with the note. Nobody edits past entries.' });
    cmp({ title: 'Leave types', eyebrow: 'Leave',
        left: { label: 'Leave types', img: H('55-lv-05-leave-types'), callouts: false, caption: 'Category, paid, half day, encashable, unit, approval, eligibility.' },
        right: { label: 'Edit Casual leave', img: H('55-lv-06-leave-type-edit'), callouts: false, caption: 'Code locked on edit; a reason is required for changes.' } });
    cmp({ title: 'Policies and the leave policy', eyebrow: 'Leave',
        left: { label: 'Policies', img: H('55-lv-07-policies'), callouts: false, caption: 'Versioned policies (exit, attendance, working hours, leave) with effective dates.' },
        right: { label: 'Standard leave', img: H('55-lv-08-leave-policy'), callouts: false, caption: 'Entitlements per type live in the version settings.' } });
    shot(H('55-lv-09-requests-hr'), { title: 'Everyone\'s leave requests', eyebrow: 'Leave', steps: [], result: 'Status tabs; Approve, Reject, Cancel per row; Apply on behalf; a link to the Approval Center.' });
    shot('support/55-lv-err-01-apply-on-behalf', { title: 'Apply on behalf fails: use the 360 instead', eyebrow: 'Leave', status: 'INCOMPLETE', steps: [], result: 'Defect F38: "Call to a member function getFormData() on array" when the dialog opens.', notes: DEBUG });
    shot(H('55-lv-10-360-time-apply'), { title: 'Workaround: Employee 360 › Records › Time', eyebrow: 'Leave', steps: [], result: 'The Leave section shows balances and Apply for leave.' });
    cmp({ title: 'Apply for leave on the employee\'s behalf', eyebrow: 'Leave',
        left: { label: 'Apply for leave', img: H('55-lv-11-apply-form'), callouts: false, caption: 'The same form and Before → After preview as the employee\'s own request.' },
        right: { label: 'Applied', tone: 'good', img: H('55-lv-12-applied'), callouts: false, caption: 'The request goes to the employee\'s approver like any other.' } });
    cmp({ title: 'Leave calendar and a search defect', eyebrow: 'Leave',
        left: { label: 'Leave calendar', img: H('55-lv-13-leave-calendar'), callouts: false, caption: 'Approved and pending leave this month to the end of next; reasons never shown.' },
        right: { label: 'Balances search', tone: 'bad', img: 'support/55-lv-err-02-balances-search', callouts: false, caption: 'Typing a name in the Balances search box fails with a database error (new finding). Use the filters instead.' },
        notes: DEBUG });

    // ---- 8. Payroll
    d.section(8, 'Payroll operations', 'Configure, calculate and check runs. Finalising is blocked until statutory rules are verified.');
    wide(H('60-pay-01-control-room'), { title: 'Payroll control room', eyebrow: 'Payroll', caption: 'Readiness figures, the company card and the run register. The subheading lists statutory rules that are not verified: runs cannot be finalised.' });
    cmp({ title: 'Runs and opening a run', eyebrow: 'Payroll',
        left: { label: 'Payroll runs', img: H('60-pay-02-runs-list'), callouts: false, caption: 'One run per company and month.' },
        right: { label: 'Open payroll run', img: H('60-pay-03-open-run-form'), callouts: false, caption: 'Company, year, month (cancelled here; the October run already exists).' } });
    shot(H('60-pay-04-run-view'), { title: 'The run', eyebrow: 'Payroll', steps: [], result: 'Totals, status and actions: Calculate / Recalculate, Validate; Approve and Finalize appear only when allowed.' });
    cmp({ title: 'Issues and the breakdown', eyebrow: 'Payroll',
        left: { label: 'Employees tab: Issues', img: H('60-pay-05-employee-issues'), callouts: false, caption: 'Issue codes appear raw, e.g. unverified_statutory_rule (F12).' },
        right: { label: 'Breakdown', img: H('60-pay-06-breakdown'), callouts: false, caption: 'Earnings and deductions; statutory lines are exceptions while rules are unverified.' } });
    cmp({ title: 'Recalculate', eyebrow: 'Payroll',
        left: { label: 'Confirm', img: H('60-pay-07-recalculate-confirm'), callouts: false, caption: 'Recalculate re-reads attendance, leave, salary and adjustments.' },
        right: { label: 'Recalculated', img: H('60-pay-08-recalculated'), callouts: false, caption: '"Calculated for 19 employee(s); 19 with exceptions." Every salaried employee of the company is included.' } });
    shot(H('60-pay-09-validate-blocked'), { title: 'Validate is refused: blocking exceptions', eyebrow: 'Payroll', steps: [], result: '"Not allowed: 19 employee(s) have blocking exceptions. Fix the inputs and recalculate." The exceptions are the unverified statutory rules (Issues column: unverified_statutory_rule). Approve, Finalize, payslips and bank files are not reachable.', notes: 'SAY: This is the payroll safeguard working, not a fault. It lifts only when platform administrators verify the statutory rules (release gate 5).' });
    shot(H('60-pay-11-payroll-auditor'), { title: 'Payroll Auditor', eyebrow: 'Payroll', steps: [], result: 'Findings with severity and evidence for the run. Rule-based (despite the "AI" label); some findings show raw technical text.' });
    cmp({ title: 'Salary components', eyebrow: 'Payroll',
        left: { label: 'Components', img: H('60-pay-12-salary-components'), callouts: false, caption: 'Formula, order and flags; statutory codes are reserved.' },
        right: { label: 'New component', img: H('60-pay-13-new-component-form'), callouts: false, caption: 'Formula validated on save (not saved here).' } });
    cmp({ title: 'Salary structures and versions', eyebrow: 'Payroll',
        left: { label: 'Structures', img: H('60-pay-14-salary-structures'), callouts: false, caption: 'Standard structure.' },
        right: { label: 'Versions', img: H('60-pay-15-structure-versions'), crop: { x: 400, y: 1250, w: 1760, h: 480 }, callouts: false, caption: 'Draft → submit → approve; versions cannot start inside a finalised period.' } });
    cmp({ title: 'Adjustments and tax declarations', eyebrow: 'Payroll',
        left: { label: 'New payroll adjustment', img: H('60-pay-16-new-adjustment'), callouts: false, caption: 'Employee, period, type, component, amount or days, taxable (cancelled).' },
        right: { label: 'Tax declarations', img: H('60-pay-17-tax-declarations'), callouts: false, caption: 'Empty here. Employees cannot submit a declaration themselves (F13).' } });
    shot(H('60-pay-10-run-history-error-f26'), { title: 'Run History tab error (development mode)', eyebrow: 'Payroll', status: 'INCOMPLETE', steps: [], result: 'LazyLoadingViolationException (F26) on the run\'s History tab outside production. Use Change history.', notes: DEBUG });

    // ---- 9. Statutory
    d.section(9, 'Statutory configuration', 'The tenant records registrations; the platform verifies rules.');
    wide(H('60-stat-01-compliance-control-room'), { title: 'Compliance control room', eyebrow: 'Statutory', caption: '0 of 24 rule versions verified; required rules, open notices and missing registrations (ESI, LWF, PT) per establishment.' });
    cmp({ title: 'Rules and verification', eyebrow: 'Statutory',
        left: { label: 'Compliance rules', img: H('60-stat-02-compliance-rules'), callouts: false, caption: 'Rule versions shipped by the platform; read only for tenants.' },
        right: { label: 'Rule verification', img: H('60-stat-03-rule-verifications'), callouts: false, caption: 'None verified. Two platform administrators verify each rule with official evidence.' } });
    cmp({ title: 'Registrations and establishment profiles', eyebrow: 'Statutory',
        left: { label: 'Statutory registrations', img: H('60-stat-05-statutory-registrations'), callouts: false, caption: 'EPF, ESIC, PT, LWF, TAN numbers with Verify / Correct / New number.' },
        right: { label: 'Statutory profiles', img: H('60-stat-06-establishment-profiles'), callouts: false, caption: 'Which statutes apply at each establishment.' } });
    cmp({ title: 'Notices and returns', eyebrow: 'Statutory',
        left: { label: 'Regulatory notices', img: H('60-stat-04-rule-notices'), callouts: false, caption: '5 open notices about statutory changes to resolve.' },
        right: { label: 'EPF returns', img: H('60-stat-07-epf-returns-empty'), callouts: false, caption: 'Empty: returns need finalised payroll.' } });

    // ---- 10. Growth and workforce
    d.section(10, 'Performance, learning and workforce', 'Cycles, appraisals, calibration, courses and positions.');
    cmp({ title: 'Performance cycles', eyebrow: 'Growth',
        left: { label: 'Cycles', img: H('65-grow-01-performance-cycles'), callouts: false, caption: 'FY 2026-27 annual review in the Self review stage.' },
        right: { label: 'New cycle', img: H('65-grow-02-new-cycle-form'), callouts: false, caption: 'Stages, eligibility rules and options (not saved). Scheduled cycles do not launch on their own (F21).' } });
    cmp({ title: 'Appraisals and calibration', eyebrow: 'Growth',
        left: { label: 'Appraisals', img: H('65-grow-03-appraisals'), callouts: false, caption: 'Each employee\'s appraisal with stage and status.' },
        right: { label: 'Calibration board', img: H('65-grow-04-calibration-board'), callouts: false, caption: 'Rating distribution and adjustments before finalising.' } });
    cmp({ title: 'Goals and learning', eyebrow: 'Growth',
        left: { label: 'Goals (HR view)', img: H('65-grow-05-goals-hr-view'), callouts: false, caption: 'Company → department → individual cascade with progress.' },
        right: { label: 'Learning assignments', img: H('65-learn-02-assignments'), callouts: false, caption: 'PoSH for everyone (annual, mandatory).' } });
    cmp({ title: 'Positions', eyebrow: 'Workforce',
        left: { label: 'New position', img: H('65-wf-02-new-position-form'), callouts: false, caption: 'Code, title, designation, company, unit, grade. FTE left blank (F8).' },
        right: { label: 'Created as a draft', tone: 'good', img: H('65-wf-03-position-created'), callouts: false, caption: '"Position POS-TRN-001 created as a draft."' } });
    cmp({ title: 'Org map and position hierarchy', eyebrow: 'Workforce',
        left: { label: 'Org map', img: H('65-wf-05-org-map'), callouts: false, caption: 'Units and people by primary reporting line.' },
        right: { label: 'Position hierarchy', img: H('65-wf-07-position-hierarchy'), callouts: false, caption: 'Positions by reporting position.' } });

    // ---- 11. Compensation
    d.section(11, 'Compensation changes', 'Four different people: propose, review, approve, execute.');
    d.purpose({ title: 'Change an employee\'s pay', eyebrow: 'Compensation', goal: 'Propose a pay change with a reason and take it through review, approval and execution by four different people.',
        who: 'Propose, review: HR Manager or Tenant HR Admin. Approve: a third person (Tenant HR or Super Admin). Execute: Payroll Admin.', where: 'Employee 360 › Records › Rewards › Propose compensation change; People › Pay › Compensation changes', before: 'The employee is employed (not alumni) and has a salary structure.',
        flow: [['PROPOSE', 'Neha (HR Manager)'], ['REVIEW', 'Kavya (Tenant HR Admin)'], ['APPROVE', 'Demo Admin'], ['EXECUTE', 'Arjun (Payroll Admin)'], ['RESULT', 'Scheduled']], status: 'VERIFIED IN RUNNING APPLICATION',
        notes: `EVIDENCE: ${SRC} Amit Verma's CTC 24,00,000 → 26,40,000 was taken through all four steps.` });
    shot(H('62-comp-00-refused-alumni'), { title: 'Refused for someone who has left', eyebrow: 'Compensation', steps: [], result: '"Cannot propose: An employee who is alumni cannot receive future compensation."' });
    cmp({ title: 'Propose', eyebrow: 'Compensation',
        left: { label: 'Rewards tab', img: H('62-comp-01-employee-compensation'), callouts: false, caption: 'Current compensation and earlier changes.' },
        right: { label: 'Propose compensation change', img: H('62-comp-02-propose-form'), callouts: false, caption: 'Change type, effective date, structure, new annual CTC, reason; Submit for review now.' } });
    cmp({ title: 'Submitted, then reviewed', eyebrow: 'Compensation',
        left: { label: 'Submitted', img: H('62-comp-04-list-submitted'), callouts: false, caption: 'Listed as submitted for review.' },
        right: { label: 'Reviewed', img: H('62-comp-06-reviewed'), callouts: false, caption: 'Mark reviewed → Confirm (Kavya).' } });
    shot('security-compliance/62-comp-07-reviewer-cannot-approve', { title: 'The reviewer cannot also approve', eyebrow: 'Compensation', steps: [], result: 'After reviewing, Kavya\'s menu offers only Reject, Return to proposer and Cancel: no Approve, although her role holds the approve permission.' });
    cmp({ title: 'Approve, then execute', eyebrow: 'Compensation',
        left: { label: 'Approved (third person)', img: H('62-comp-09-approved'), callouts: false, caption: 'Demo Admin (Tenant Super Admin) approves.' },
        right: { label: 'Executed (fourth person)', tone: 'good', img: H('62-comp-10-executed'), callouts: false, caption: 'Arjun (Payroll Admin) executes: status Scheduled for the effective date.' } });

    // ---- 12+. Sections that depend on the remaining captures
    require('./hr-part2')(d, lib, { H, shot, wide, cmp, SRC, DEBUG });
    return d;
};
