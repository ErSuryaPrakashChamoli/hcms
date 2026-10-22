// Reusable workflow sequences built on the executed captures (10-leave-flow, 20-attendance-flow).
'use strict';

const LEAVE_SRC = 'Executed on the training database on 21 Oct 2026 (tools/capture/10-leave-flow.mjs): Priya Nair (Employee) requested Casual leave 14–15 Dec 2026; Amit Verma (Manager) approved it; Amit rejected Rohan Pillai\'s request with a reason.';
const ATT_SRC = 'Executed on the training database on 21 Oct 2026 (tools/capture/20-attendance-flow.mjs): Priya checked in, corrected a missed punch for 20 Oct, Amit approved it, Kavya Menon (Tenant HR Admin) recorded a manual punch and processed the day.';

/** Employee side of leave (Deck 1, 11). */
function leaveEmployee(d, { eyebrow = 'Leave', withManager = true } = {}) {
    d.purpose({
        title: 'Request leave and follow it to a decision', eyebrow,
        goal: 'Ask for time off, see your balance before and after, and follow the request until your manager decides.',
        who: 'Any employee with a PeopleOS account. Your line manager decides.',
        where: 'Home › Request leave (or + New › Request leave). Status: People › Time & leave › Leave requests.',
        before: 'Know your dates and the leave type. Some types are not allowed during probation.',
        flow: [['START', 'Home'], ['ACTION', 'Request leave form'], ['REVIEW', 'Before → After preview'], ['APPROVAL', 'Manager decides'], ['RESULT', 'Status and balance']],
        status: 'VERIFIED IN RUNNING APPLICATION',
        notes: `SAY: This is the most common thing employees do in PeopleOS. We will follow one real request from start to finish.\nEVIDENCE: ${LEAVE_SRC}\nDEMO LIVE: Only on a practice tenant. In a shared environment do not submit real leave.`,
    });
    d.shot({ title: 'Start from Home', eyebrow, img: 'employee/10-leave-01-home-request-leave',
        steps: ['Select Request leave: it is the main button on your Home.', 'PeopleOS Intelligence reminds you of your next leave and its status.'],
        result: 'The Request leave dialog opens.',
        notes: `SAY: Request leave is always the first button on an employee Home.\nOBSERVE: The panel on the right says "Your next leave: Casual leave · 02 Nov → 03 Nov (pending)".\nASK: "Where else could you start?" (Answer: + New, or Ctrl K and type "leave".)\nEVIDENCE: ${LEAVE_SRC}` });
    d.shot({ title: 'Required fields are checked before anything is sent', eyebrow, img: 'employee/10-leave-02-validation',
        steps: ['Submitting with no leave type stops at the first empty required field.'],
        result: 'Nothing is submitted until Leave type, dates and Reason are filled in.',
        notes: 'SAY: Fields with a red asterisk are required. The browser points to the first one that is missing.\nOBSERVE: "Please select an item in the list." under Leave type.' });
    d.shot({ title: 'Complete the request', eyebrow, img: 'employee/10-leave-04-form-completed', callouts: ['1', '2', '3', '5', '6'], renumber: { 5: 4, 6: 5 },
        steps: [[1, 'Leave type (shows what is available).'], [2, 'First day.'], [3, 'Last day.'], ['·', 'Before → After panel: working days and balance left.'], [4, 'Reason (your manager sees it).'], [5, 'Submit request.']],
        result: 'Weekends and holidays are not counted. Here: 2 working days; Casual leave 5 → 3.',
        notes: `SAY: Read the Before → After box before you submit. It shows how many working days the request uses and what will be left.\nOBSERVE: 14–15 Dec 2026 = 2 working days; Casual leave available 5 → 3.\nCOMMON QUESTION: Half days use Start and End (Full day, First half, Second half).\nEVIDENCE: ${LEAVE_SRC}` });
    d.compare({ title: 'Refused or accepted: read the message', eyebrow,
        left: { label: 'Refused by your leave policy (the message)', tone: 'bad', img: 'employee/10-leave-03-policy-refusal', crop: { x: 1500, y: 20, w: 640, h: 180 }, callouts: ['1'], caption: '"Cannot apply. Earned leave is not available during probation." The list still offers Earned leave; the policy refuses it when you submit. Choose another type.' },
        right: { label: 'Accepted (the message)', tone: 'good', img: 'employee/10-leave-05-submitted', crop: { x: 1500, y: 20, w: 640, h: 180 }, callouts: ['1'], caption: '"Leave requested: 2.0 day(s)." The request now waits for your manager.' },
        notes: `SAY: If PeopleOS refuses a request, the message says why. The most common reason is a type you are not eligible for yet.\nOBSERVE (real behaviour): Earned leave is listed with "17.5 available" during probation, but submitting it is refused. Tell learners this so they are not surprised.\nEVIDENCE: ${LEAVE_SRC}` });
    d.shot({ title: 'Check the status while you wait', eyebrow, img: 'employee/10-leave-06-status-pending', callouts: ['1'],
        steps: ['Your new request is listed under Leave requests.', ['·', 'Status shows pending until your manager decides; Cancel is on the right of the row while it is pending.']],
        result: 'You cannot edit a submitted request. Cancel it and submit a new one if something is wrong.',
        notes: 'SAY: Open People › Time & leave › Leave requests. Each row shows type, dates, days, reason, status and who reviewed it.\nAVOID: telling learners they will get an e-mail. E-mail and in-app notices depend on notification rules set by HR.' });
    if (withManager) {
        d.shot({ title: 'Meanwhile, your manager decides', eyebrow, img: 'manager/10-leave-08-decision-card', callouts: ['1', '4', '5'], renumber: { 4: 2, 5: 3 }, place: { 4: 'left', 5: 'right' },
            steps: [[1, 'Your manager sees your reason,'], ['·', 'whether anyone else in the team is away,'], [2, 'and can Approve…'], [3, '…or Reject (a reason is required).']],
            result: 'Deck 2 shows the manager side in full.',
            notes: 'SAY: Your manager sees the same Before → After balance you saw, plus whether others in the team are away on those dates.' });
    }
    d.compare({ title: 'See the decision and your new balance', eyebrow,
        left: { label: 'Leave requests: approved', tone: 'good', img: 'employee/10-leave-13-status-approved', callouts: ['1'], caption: 'Status approved; Reviewed by Amit Verma.' },
        right: { label: 'Balances', img: 'employee/10-leave-14-balances', callouts: ['1'], caption: 'Casual leave: Used 2.0, Pending 2.0 (another request), Available 3.0.' },
        notes: `SAY: After the decision, check Leave requests for the status and Balances for what is left. Pending days are already held back from Available.\nEVIDENCE: ${LEAVE_SRC}` });
    d.shot({ title: 'Every balance movement is recorded', eyebrow, img: 'employee/10-leave-15-ledger',
        steps: ['The newest entry: usage −2.00 for the approved request, with its note.'],
        result: 'Monthly accruals and every usage appear as separate entries. Nobody edits this ledger; corrections are new entries.',
        notes: 'SAY: If your balance looks wrong, open Transactions (heading "Leave ledger entries") before asking HR: it explains every change.' });
}

/** Manager side of leave (Deck 2, 9, 11). */
function leaveManager(d, { eyebrow = 'Approvals' } = {}) {
    d.purpose({
        title: 'Decide leave requests', eyebrow,
        goal: 'Approve or reject your team\'s leave with the context you need: reason, overlap with others, and the balance after.',
        who: 'Line managers (any reporting type effective today).',
        where: 'My work › Approvals (the Approval Center). Home shows how many decisions wait.',
        before: 'Nothing to configure. E-mail notices depend on notification rules set by HR.',
        flow: [['START', 'My work › Approvals'], ['REVIEW', 'Decision card'], ['DECIDE', 'Approve or Reject'], ['CONFIRM', 'Note or reason'], ['RESULT', 'Requester sees status']],
        status: 'VERIFIED IN RUNNING APPLICATION',
        notes: `EVIDENCE: ${LEAVE_SRC}\nSAY: One place for every decision: leave, cancellations, attendance corrections, letters, workflow steps.` });
    d.wide({ title: 'The Approval Center', eyebrow, img: 'manager/10-leave-07-approval-center',
        caption: 'Decisions are grouped Urgent, This week and Later, most urgent first; the new request is further down under Later. Keyboard: J/K move, A approve, R reject.',
        legend: [[1, 'How many decisions wait'], [2, 'Summarise what is pending']],
        notes: 'SAY: Urgent means overdue, starting within a day, or flagged.\nOBSERVE: 7 decisions at the time of capture.\nAVOID: using A or R without reading the card: they only open the confirm box; nothing is decided without Confirm.' });
    d.shot({ title: 'Read the context before you decide', eyebrow, img: 'manager/10-leave-08-decision-card', callouts: ['1', '4', '5'], renumber: { 4: 2, 5: 3 }, place: { 4: 'left', 5: 'right' },
        steps: [[1, 'The employee\'s reason.'], ['·', 'Team overlap, under the reason: "No one else in the team is away on these dates".'], [2, 'Approve.'], [3, 'Reject.']],
        result: 'The card also shows the dates and the balance before → after (Casual leave 7 → 5).',
        notes: 'SAY: Leave cannot be "sent back" for changes. If you need more information, ask the employee first, or reject with a reason they will see.' });
    d.shot({ title: 'Approve: add an optional note, then Confirm', eyebrow, img: 'manager/10-leave-09-approve-confirm',
        steps: ['Write an optional note.', 'Select Confirm: Approve.'], result: 'Nothing is decided until you confirm. Esc cancels.' });
    d.compare({ title: 'Rejecting needs a reason the employee will see', eyebrow,
        left: { label: 'Reason missing', tone: 'bad', img: 'manager/10-leave-11-reject-reason-required', callouts: ['2'], caption: 'Confirm: Reject without a reason is stopped: "Please fill out this field."' },
        right: { label: 'Rejected with a reason', tone: 'good', img: 'manager/10-leave-12-rejected', callouts: ['1'], caption: '"Leave rejected." The item leaves your queue; the employee sees your reason.' },
        notes: `EVIDENCE: ${LEAVE_SRC}\nSAY: Write the reason for the employee, not for HR: what should they do next?` });
    d.shot({ title: 'After the decision', eyebrow, img: 'manager/10-leave-10-approved',
        steps: ['"Leave approved" confirms the decision.', 'The count of decisions waiting drops.'],
        result: 'The employee sees Approved and "Reviewed by" your name on their Leave requests.',
        notes: 'AVOID: telling the requester "you have been notified": notification depends on rules configured by HR (finding F6).' });
}

/** Employee side of attendance (Deck 1, 11). */
function attendanceEmployee(d, { eyebrow = 'Attendance' } = {}) {
    d.purpose({
        title: 'Check in and correct a missed punch', eyebrow,
        goal: 'Record your day from Home and fix it when a punch is missing or wrong.',
        who: 'Employees whose attendance is tracked. Corrections go to your manager.',
        where: 'Home › Your day › Check in. Home › Start something › Fix my attendance. People › Time & leave.',
        before: 'Your organisation decides whether web check-in is used; devices may record punches instead.',
        flow: [['START', 'Home › Your day'], ['ACTION', 'Check in / Fix'], ['REVIEW', 'Manager approves'], ['PROCESS', 'Day reprocessed'], ['RESULT', 'Attendance record']],
        status: 'VERIFIED IN RUNNING APPLICATION',
        notes: `EVIDENCE: ${ATT_SRC}` });
    d.compare({ title: 'Check in from Home', eyebrow,
        left: { label: 'Before: Your day panel', img: 'employee/20-att-01-your-day-check-in', crop: { x: 400, y: 1050, w: 1150, h: 300 }, callouts: ['1', '2'], caption: 'Your day says "Not checked in yet". Select Check in.' },
        right: { label: 'After: the message', tone: 'good', img: 'employee/20-att-02-checked-in', crop: { x: 1500, y: 20, w: 640, h: 180 }, callouts: ['1', '2'], caption: '"Checked in" with the time. The button changes to Check out.' },
        notes: 'SAY: A web check-in records a punch. Attendance records are built when the day is processed.\nAVOID: checking in on behalf of someone else.' });
    d.shot({ title: 'Fix my attendance', eyebrow, img: 'employee/20-att-03-fix-my-attendance',
        steps: ['Date of the day to correct (today or earlier).', 'Type, for example Missed punch.', 'Actual in time.', 'Actual out time.', 'Reason.', 'Submit.'],
        result: 'The correction goes to your manager. Once approved, the day is reprocessed.',
        notes: `OBSERVE: 20 Oct 2026, Missed punch, 09:05–18:10, "Badge not read at the gate in the evening".\nKNOWN LIMIT: you cannot withdraw a correction after submitting (finding F42).\nEVIDENCE: ${ATT_SRC}` });
    d.compare({ title: 'Submitted, then waiting', eyebrow,
        left: { label: 'Confirmation', tone: 'good', img: 'employee/20-att-04-correction-submitted', crop: { x: 1500, y: 20, w: 640, h: 180 }, callouts: ['1'], caption: '"Correction sent to your manager".' },
        right: { label: 'Regularisations', img: 'employee/20-att-05-regularisation-pending', callouts: ['1'], caption: 'People › Time & leave › Regularisations: the correction is pending.' } });
    d.shot({ title: 'After approval: your corrected day', eyebrow, img: 'employee/20-att-16-my-attendance',
        steps: ['The corrected day with shift, in/out, worked time and any exception.'],
        result: 'Here 20 Oct shows Present, 09:05–18:10, flagged "Worked on holiday / weekly off" because 20 Oct is Dussehra in the demonstration holiday calendar.',
        notes: `EVIDENCE: ${ATT_SRC}\nSAY: Exceptions such as late, missing punch or worked on holiday are calculated; HR sees them in the Exception centre.` });
}

/** Manager side of attendance (Deck 2). */
function attendanceManager(d, { eyebrow = 'Team attendance' } = {}) {
    d.shot({ title: 'Approve an attendance correction', eyebrow, img: 'manager/20-att-06-correction-card', place: { 2: 'left' },
        steps: ['Read the reason; the Before → After box shows the requested in/out times.', 'Approve, then Confirm.'],
        result: '"Approved and attendance reprocessed": the day is recalculated straight away.',
        notes: `EVIDENCE: ${ATT_SRC}` });
    d.compare({ title: 'Your team\'s corrections and exceptions', eyebrow,
        left: { label: 'Regularisations (team)', img: 'manager/20-att-08-team-regularisations', callouts: ['1'], caption: 'Filter by status; pending ones can be decided here or in the Approval Center.' },
        right: { label: 'Exception centre', img: 'manager/20-att-09-exception-centre', callouts: ['1'], caption: 'Missing punch, late, absent and overtime counts for the last 30 days.' },
        notes: 'SAY: Use the Exception centre weekly to spot missing punches before payroll cut-off.' });
}

/** HR side of attendance (Deck 3, 4). */
function attendanceHr(d, { eyebrow = 'Attendance administration' } = {}) {
    d.shot({ title: 'Attendance records and the two HR actions', eyebrow, img: 'hr-admin/20-att-10-records-before',
        steps: ['Manual punch: record a punch when a device was offline.', 'Process day: turn punches and approved corrections into attendance records.'],
        result: 'Both need the attendance.manage permission (Tenant HR Admin, Attendance Admin).' });
    d.shot({ title: 'Record a manual punch', eyebrow, img: 'hr-admin/20-att-11-manual-punch',
        steps: ['When the punch happened.', 'Direction (Auto, In or Out).', 'A note explaining why.'],
        result: '"Punch recorded and day reprocessed". The punch is stored with source "manual".',
        notes: `EVIDENCE: ${ATT_SRC}\nAVOID: using manual punches to fix systematic device problems; raise those with your administrator.` });
    d.compare({ title: 'Process a day', eyebrow,
        left: { label: 'Process day dialog', img: 'hr-admin/20-att-12-process-day', callouts: ['1'], caption: 'Choose the date and Submit.' },
        right: { label: 'Result', tone: 'good', img: 'hr-admin/20-att-13-day-processed', callouts: ['1', '2'], caption: '"21 employee(s) processed for 2026-10-20". Records show shift, status, in/out, worked time and exceptions.' },
        notes: `EVIDENCE: ${ATT_SRC}` });
    d.compare({ title: 'Check exceptions and raw punches', eyebrow,
        left: { label: 'Exception centre', img: 'hr-admin/20-att-14-exception-centre', callouts: ['1'], caption: 'Counts for 30 days; rows that need action.' },
        right: { label: 'Punches', img: 'hr-admin/20-att-15-punches', callouts: ['1'], caption: 'Every punch with its source (web, manual, device) and processing status.' } });
}

module.exports = { leaveEmployee, leaveManager, attendanceEmployee, attendanceManager, attendanceHr, LEAVE_SRC, ATT_SRC };
