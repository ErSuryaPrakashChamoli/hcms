// Deck 3, sections 12–15: letters, assets and HR requests; exit and alumni; imports; audit.
'use strict';
module.exports = (d, lib, { H, shot, wide, cmp, SRC, DEBUG }) => {
    const { has } = lib;
    const E = (n) => `employee/${n}`;

    // ---- 12. Letters, assets, HR requests
    d.section(12, 'Letters, assets and HR requests', 'Issue letters with approval where needed, track assets, answer employees.');
    shot(H('52-let-01-letters'), { title: 'Letters', eyebrow: 'Letters', steps: ['Generate letter.'], result: 'Generated letters with number, employee, type and status.' });
    shot(H('52-let-02-generate-form'), { title: 'Generate a letter', eyebrow: 'Letters', steps: ['Employee.', 'Template.', 'Extra fields.', 'Submit.'], result: 'Merge fields come from the employee record. Templates marked "needs approval" go to Pending approval; others are ready to issue.' });
    shot(H('52-let-03-pending'), { title: 'Pending approval and issued', eyebrow: 'Letters', steps: ['Pending approval (a no-objection certificate).', 'Status tabs.'], result: 'An employment certificate (no approval needed) was issued straight away.' });
    cmp({ title: 'The requester cannot approve', eyebrow: 'Letters',
        left: { label: 'Requester tries to approve', tone: 'bad', img: 'security-compliance/52-let-04-requester-cannot-approve', callouts: ['1'], caption: '"Not allowed: The person who requested a letter cannot approve it."' },
        right: { label: 'A second HR person approves', img: H('52-let-05-approver-view'), callouts: ['1'], caption: 'Preview with Approve / Reject.' } });
    cmp({ title: 'Issued, and the employee\'s copy', eyebrow: 'Letters',
        left: { label: 'Issued', tone: 'good', img: H('52-let-06-issued'), callouts: ['1'], caption: 'Issued; Download available.' },
        right: { label: 'My letters (employee)', img: E('52-let-07-my-letters'), callouts: ['1'], caption: 'The employee sees only their own issued letters. HTML only: no PDF or e-signature yet.' },
        notes: 'KNOWN ISSUE F26: opening a letter page can show a lazy-loading error in non-production (support/52-let-err-01). Use Change history for its audit trail.' });
    shot(H('52-ast-01-register'), { title: 'Asset register', eyebrow: 'Assets', steps: ['Receive asset.', 'A spare laptop in stock.', 'Status tabs.'], result: 'Tag, category, serial, status, custodian, location and warranty.' });
    cmp({ title: 'Assign an asset', eyebrow: 'Assets',
        left: { label: 'Assign', img: H('52-ast-02-assign'), callouts: ['1', '2', '3'], caption: 'Employee, condition and a note: custody is recorded.' },
        right: { label: 'Assigned', tone: 'good', img: H('52-ast-03-assigned'), callouts: ['1', '2'], caption: '"Asset assigned": LT-0004 to Tanvi Joshi; custody history from today.' } });
    shot(E('52-ast-04-my-assets'), { title: 'The employee\'s My assets', eyebrow: 'Assets', steps: ['My laptop.'], result: 'Only the assets in their custody; the employee acknowledges receipt.' });
    cmp({ title: 'An employee raises a request', eyebrow: 'HR requests',
        left: { label: 'Ask HR', img: E('52-tkt-01-ask-hr'), callouts: false, caption: 'The request goes to the HR queue with an SLA from its category.' },
        right: { label: 'Raised', tone: 'good', img: E('52-tkt-02-raised'), callouts: false, caption: '"Request TKT-2026-00003 raised"; listed as Submitted.' } });
    shot(H('52-tkt-03-hr-queue'), { title: 'The HR queue', eyebrow: 'HR requests', steps: ['New request.', 'SLA due.'], result: 'Category, requester, priority, status, assignee and SLA due date.' });
    cmp({ title: 'Take it, reply, resolve', eyebrow: 'HR requests',
        left: { label: 'Reply', img: H('52-tkt-04-reply'), callouts: ['1'], caption: 'The reply is added to the conversation the employee sees.' },
        right: { label: 'Resolved', tone: 'good', img: H('52-tkt-05-resolved'), callouts: ['1', '2'], caption: 'Assignee, SLA and conversation kept on the request.' } });
    cmp({ title: 'What the employee sees', eyebrow: 'HR requests',
        left: { label: 'My requests (Resolved tab)', img: E('52-tkt-06-my-requests'), callouts: ['1', '2'], caption: 'Tip: My requests opens with a status filter that hides Resolved; clear it to see answered requests.' },
        right: { label: 'The answer', img: E('52-tkt-07-request-answer'), callouts: ['1', '2'], caption: 'HR\'s resolution and the conversation; the employee can close and rate, or reopen.' } });

    // ---- 13. Exit
    d.section(13, 'Exit, full & final, alumni', 'From resignation to a lifetime alumni record.');
    d.purpose({ title: 'Take a leaver through exit', eyebrow: 'Exit', goal: 'Record the resignation, clear every stage, settle full & final, complete the exit and keep the person as an alumnus.',
        who: 'The employee resigns; the manager and HR clear stages; HR settles and completes.', where: 'You › My exit; People › Lifecycle › Exit cases; Alumni', before: 'Assets in the leaver\'s custody must be taken back first.',
        flow: [['RESIGN', 'employee'], ['CLEAR', 'manager, assets, HR'], ['SETTLE', 'full & final'], ['COMPLETE', 'Exited'], ['ALUMNI', 'portal']], status: 'VERIFIED IN RUNNING APPLICATION',
        notes: `EVIDENCE: ${SRC} Rahul Sharma (fictional) resigned and was taken through every step on 21 Oct 2026.` });
    cmp({ title: 'The employee resigns', eyebrow: 'Exit',
        left: { label: 'Resign', img: E('70-exit-01-resign'), maxAspect: 1.3, callouts: ['1', '2', '3'], caption: 'Reason and requested last day; blank = end of the notice period.' },
        right: { label: 'My exit', img: E('70-exit-02-my-exit'), callouts: ['1'], caption: 'The exit case with its status and last working day.' },
        notes: 'OBSERVATION: the requested last day (18 Dec) was not applied; the case used the end of notice (20 Nov). Check the last working day on the case.' });
    d.cards({ title: 'Check the last working day', eyebrow: 'Exit', cards: [{ head: 'Observed in training', body: 'Rahul asked for 18 Dec as his last day; the exit case used the end of his notice period (20 Nov) instead.', tone: 'warn' }, { head: 'What to do', body: 'Check the last working day on the exit case and correct it before clearance if it differs from the agreed date.', tone: 'info' }] });
    cmp({ title: 'HR picks up the case', eyebrow: 'Exit',
        left: { label: 'Exit cases', img: H('70-exit-03-exit-cases'), callouts: ['1', '2'], caption: 'The resignation appears as a new case; HR can also initiate an exit.' },
        right: { label: 'The case', img: H('70-exit-04-case'), callouts: false, caption: 'Status, dates, notice; start clearance, record the interview, generate letters, complete.' } });
    cmp({ title: 'Clearance', eyebrow: 'Exit',
        left: { label: 'Started', img: H('70-exit-05-clearance-started'), callouts: ['1', '2'], caption: 'Stages created from the exit checklist; owners notified.' },
        right: { label: 'Stages', img: H('70-exit-06-clearance-stages'), callouts: ['1', '2', '3'], caption: 'Manager, IT, finance, assets, HR: Clear, Block or Not applicable.' } });
    cmp({ title: 'Assets block clearance until returned', eyebrow: 'Exit',
        left: { label: 'Asset stage refused', tone: 'bad', img: H('70-exit-08a-asset-stage-blocked'), callouts: ['1', '2'], caption: '"Not allowed: Assets still in custody: LT-0001. Take them back, or record a recoverable amount."' },
        right: { label: 'Take back', img: H('70-exit-08b-take-back-asset'), callouts: false, caption: 'Custody ends; the laptop returns to stock.' } });
    cmp({ title: 'All stages cleared; exit interview', eyebrow: 'Exit',
        left: { label: 'Cleared', tone: 'good', img: H('70-exit-08-clearance-done'), callouts: ['1'], caption: 'Every stage cleared, with who and when (the manager cleared his own stage).' },
        right: { label: 'Record exit interview', img: H('70-exit-09-exit-interview'), callouts: false, caption: 'Answers feed Exit insights; averages use employee answers only.' } });
    cmp({ title: 'Full & final settlement', eyebrow: 'Exit',
        left: { label: 'Settlement', img: H('70-exit-11-ff-settlement'), callouts: ['1', '2'], caption: 'Earnings 43,400.06, deductions 5,146.00, net 38,254.06; Paid with reference.' },
        right: { label: 'Breakdown', img: H('70-exit-12-ff-breakdown'), callouts: false, caption: 'Each line with its basis (shown as raw technical values). Gratuity is not calculated.' },
        notes: 'OBSERVATIONS: the same HR person calculated and approved the settlement (no separation of duties enforced here); gratuity is not implemented (stored rule only); statutory deductions use unverified rules.' });
    cmp({ title: 'Complete the exit', eyebrow: 'Exit',
        left: { label: 'Complete exit', img: H('70-exit-14-complete-exit'), callouts: false, caption: 'The employee becomes Exited on the last working day.' },
        right: { label: 'Completed', tone: 'good', img: H('70-exit-15-completed'), callouts: ['1'], caption: '"Exit completed".' } });
    cmp({ title: 'Alumni: the record continues', eyebrow: 'Alumni',
        left: { label: 'Alumni list', img: H('70-exit-17-alumni-list'), callouts: ['1'], caption: 'The new alumni profile.' },
        right: { label: 'Employee 360 after exit', img: H('70-exit-18-employee-exited'), callouts: ['1'], caption: 'Exited / Alumni; one lifetime record keeps the whole journey.' } });
    cmp({ title: 'Alumni portal (verified live)', eyebrow: 'Alumni',
        left: { label: 'Former employee signs in', img: E('70-alumni-01-portal'), callouts: ['1', '2'], caption: 'My requests, My documents, Payslips; Request a document or reference.' },
        right: { label: 'HR receives the request', img: H('70-alumni-04-hr-requests'), callouts: ['1'], caption: 'Verify identity, Approve & generate, Mark delivered, Reject.' },
        notes: 'First live verification of the alumni portal (previous documentation could only describe it from code). OBSERVATION: the alumnus\'s Home still shows "Bank account missing" and "PAN not on file" reminders.' });

    // ---- 14. Imports
    d.section(14, 'Employee imports', 'Upload, inspect, map, validate, approve, run: blocked today at Map columns.');
    shot(H('75-imp-01-imports'), { title: 'Employee imports', eyebrow: 'Imports', status: 'INCOMPLETE', steps: ['Upload CSV.'], result: 'Import batches with their status (Tenant HR Admin and Account Owner only). The list works; imports are blocked at Map columns (F25).' });
    cmp({ title: 'Upload and inspect', eyebrow: 'Imports',
        left: { label: 'Uploaded', img: H('75-imp-03-uploaded'), callouts: ['1'], caption: 'Status Uploaded; next: Inspect file.' },
        right: { label: 'Inspected', img: H('75-imp-04-inspected'), callouts: ['1', '2'], caption: '"3 row(s) staged; 5 column(s) mapped automatically"; next: Map columns.' } });
    cmp({ title: 'Map columns fails (F25)', eyebrow: 'Imports',
        left: { label: 'Map columns', img: H('75-imp-05-map-columns'), callouts: ['1', '2', '3'], caption: 'Column → field; what to do when the person exists.' },
        right: { label: 'Submit', tone: 'bad', img: 'support/75-imp-06-map-columns-fails-f25', callouts: ['1'], caption: '"Failed: Undefined variable $svc". The batch stays Inspected; Validate, Approve and Run never become available.' },
        notes: 'WORKAROUND: Discard the batch; hire people with the wizard, or ask Markedge for an API load (POST /api/v1/employees, not exercised in this training). Defect F25.' });
    cmp({ title: 'Discard, and who may import', eyebrow: 'Imports',
        left: { label: 'Discarded', img: H('75-imp-07-discarded'), callouts: ['1'], caption: 'Nothing was imported.' },
        right: { label: 'HR Manager', tone: 'bad', img: 'security-compliance/75-imp-08-hr-manager-not-available', callouts: ['1'], caption: '403 "This page is not available to you": employee.import is not in the HR Manager role.' } });

    // ---- 15. Audit
    d.section(15, 'Audit history', 'Prove who changed what, when and why.');
    shot(H('50-aud-01-change-history'), { title: 'Change history', eyebrow: 'Audit', callouts: false, steps: [], result: 'Every audited event, newest first, with action, module, record, who and reason.' });
    shot(H('50-aud-02-event-detail'), { title: 'One event: the transfer', eyebrow: 'Audit', steps: [], result: 'Action Transferred, reason, effective date 22 Oct 2026; field change team Platform → Mobile; who and where; integrity hash.' });
    shot(H('50-aud-03-all-changes-person'), { title: 'All changes for one person', eyebrow: 'Audit', steps: [], result: 'Employee 360 › More › All changes opens Change intelligence for that person: hire, documents, transfer, reporting change, onboarding, each with Explain.' });
    d.cards({ title: 'Common mistakes in HR operations', eyebrow: 'Tips', cards: [
        { head: 'Same-day changes', body: 'A transfer effective today is refused ("must start after the current one"): use tomorrow\'s date.', tone: 'warn' },
        { head: 'One person doing two steps', body: 'Letters, documents and pay need a second person; plan who holds each role.', tone: 'info' },
        { head: 'Exits with assets out', body: 'Take assets back first; clearance refuses otherwise.', tone: 'info' },
        { head: 'Onboarding after a manager change', body: 'Open onboarding tasks stay with the previous manager: reassign them.', tone: 'warn' },
        { head: 'Imports', body: 'Blocked by F25: do not retry; hire individually or ask Markedge.', tone: 'bad' },
        { head: 'Errors in development mode', body: 'History tabs and some lower sections error outside production; use Change history.', tone: 'info' },
    ], notes: 'SOURCE: observations during the 21 Oct 2026 capture (fork B report): transfer refusal, onboarding tasks kept by the previous manager, F&F calculated and approved by the same person.' });
    d.exercise({ title: 'Practice (practice tenant only)', tasks: [
        'Hire a fictional employee; first try an existing person\'s e-mail and read the message.',
        'Change the new hire\'s manager effective tomorrow. Where does the change appear?',
        'Generate a letter that needs approval. Try to approve it yourself, then ask a colleague.',
        'Run an exit for a fictional employee with a laptop assigned. What blocks clearance?',
    ], notes: 'ANSWERS: (1) "This person already exists…". (2) Header Reports to, the Journey, Records › Employment and Change history. (3) "The person who requested a letter cannot approve it."; a second HR person approves and issues. (4) The asset stage: "Assets still in custody…"; take the laptop back first.' });
    d.table({ title: 'Answer key', eyebrow: 'Practice', columns: ['#', 'Answer'], colW: [0.6, 11.53], rows: [
        ['1', 'The wizard stops: "This person already exists… same personal email." Re-use the person or correct the details.'],
        ['2', 'Header (Reports to), Journey, Records › Employment and Insights › Audit › Change history.'],
        ['3', '"Not allowed: The person who requested a letter cannot approve it." A second HR person approves, then Issue.'],
        ['4', 'Asset clearance: "Assets still in custody: … Take them back, or record a recoverable amount."'],
    ] });
    d.closing({ points: ['The Employee 360 is the record; every change is effective-dated and audited.', 'Duplicates are stopped at hire; managers and jobs change through guided steps.', 'Documents, letters and pay changes need a second person.', 'Payroll can be calculated and checked; finalising waits for statutory verification.', 'Known defects have workarounds: Apply on behalf (F38) → use the Employee 360; imports (F25) → hire wizard; History tabs (F26) → Change history.'],
        next: ['Deck 07: troubleshooting', 'Deck 08: access and audit controls', 'Deck 12: reports and analytics', 'Your Tenant Super Admin for role changes'] });
};
