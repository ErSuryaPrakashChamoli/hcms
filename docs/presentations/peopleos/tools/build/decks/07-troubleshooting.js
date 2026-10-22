// Deck 7 — Troubleshooting and support playbook. Every symptom shown was observed in the running application.
'use strict';
const common = require('./common');

module.exports = (lib) => {
    const { Deck, has, find } = lib;
    const d = new Deck({
        num: 7, file: '07-PeopleOS-Troubleshooting-and-Support-Playbook.pptx', short: 'Troubleshooting and support',
        title: 'Troubleshooting and Support Playbook',
        subtitle: 'Recognise real PeopleOS symptoms, diagnose them safely, fix what support may fix, and escalate the rest with the right evidence.',
        audience: 'Markedge support staff and customer HR helpdesk leads',
    });
    const shot = (name, o) => { if (has(name)) d.shot({ img: name, ...o }); else lib.WARN.push(`deck 7: missing ${name}`); };
    const cmp = (o) => { if (has(o.left.img) && has(o.right.img)) d.compare(o); else lib.WARN.push(`deck 7: missing ${o.left.img} / ${o.right.img}`); };
    const first = (...n) => n.find((x) => x && has(x));
    const DEBUG = 'Development-mode debug page, as captured. In production the user sees a generic server-error dialog; the support diagnosis is the same.';

    // One issue = a card slide (symptoms, diagnosis, expected vs actual, safe fix, escalation, evidence, never) + screenshots.
    const issue = (o) => {
        d.queue.push(async () => {
            const s = d.slide('content', `${o.notes ? o.notes + '\n' : ''}SYMPTOM: ${o.symptom}\nDIAGNOSE: ${o.diagnose}\nEXPECTED VS ACTUAL: ${o.expected}\nSAFE FIX: ${o.fix}\nESCALATE: ${o.escalate}\nEVIDENCE: ${o.evidence}\nNEVER: ${o.never}\nASK: "What would you check first?"`);
            d.head(s, o.title, o.eyebrow ?? 'Issue');
            const H = lib.HEX;
            const cells = [['User symptoms', o.symptom], ['Diagnostic steps', o.diagnose], ['Expected versus actual', o.expected], ['Safe corrective action', o.fix], ['Escalate to', o.escalate], ['Evidence to collect', o.evidence]];
            const cw = (13.333 - 1.2 - 0.2) / 2, ch = 1.18;
            cells.forEach(([h, b], i) => {
                const x = 0.6 + (i % 2) * (cw + 0.2), y = 1.55 + Math.floor(i / 2) * (ch + 0.12);
                s.addShape(d.pres.shapes.ROUNDED_RECTANGLE, { x, y, w: cw, h: ch, rectRadius: 0.06, fill: { color: H.soft }, line: { color: H.soft } });
                s.addText([{ text: h.toUpperCase(), options: { bold: true, fontSize: 9.5, color: d.C.accent1, charSpacing: 1, breakLine: true } }, { text: b, options: { fontSize: b.length > 170 ? 11 : 12, color: d.C.text1 } }], { x: x + 0.15, y: y + 0.07, w: cw - 0.3, h: ch - 0.14, valign: 'top', margin: 0, isTextBox: true });
            });
            const y = 1.55 + 3 * (ch + 0.12);
            s.addShape(d.pres.shapes.ROUNDED_RECTANGLE, { x: 0.6, y, w: 13.333 - 1.2, h: 6.85 - y, rectRadius: 0.06, fill: { color: 'FBE6E4' }, line: { color: 'FBE6E4' } });
            s.addText([{ text: 'NEVER  ', options: { bold: true, fontSize: 10, color: H.red, charSpacing: 1 } }, { text: o.never, options: { fontSize: 12, color: d.C.text1 } }], { x: 0.75, y: y + 0.08, w: 13.333 - 1.5, h: 6.85 - y - 0.16, valign: 'middle', margin: 0, isTextBox: true });
        });
        for (const sh of o.shots ?? []) if (sh) shot(sh.img, { eyebrow: o.eyebrow ?? 'Issue', ...sh });
    };

    d.cover({ shot: 'support/55-lv-err-01-apply-on-behalf', notes: 'SAY: Every symptom in this deck was reproduced in the running application on a training database. Where we could not reproduce something, the slide says so.\nAVOID: inventing error messages for customers. Quote what the screen says.' });
    d.statusLegend();
    common.statusDetailed(d, 'Release status');
    d.numbered({ title: 'What you will be able to do', eyebrow: 'Learning objectives', items: [
        ['Triage by symptom', 'sign-in, access, data, time and leave, payroll, notifications, platform'],
        ['Collect the right evidence', 'without exposing personal data or secrets'],
        ['Apply safe fixes', 'resend invitations, scope checks, documented workarounds'],
        ['Escalate correctly', 'customer administrator, Markedge support, engineering, platform operator'],
        ['Recognise known defects', 'F25, F38, F44, F17, F26 and new findings'],
    ] });
    d.cards({ title: 'Ground rules for every ticket', eyebrow: 'Support', cards: [
        { head: 'Ask for the exact message', body: 'Screenshot plus time, user e-mail and the page address. Most sign-in messages are deliberately generic.' },
        { head: 'Never ask for passwords', body: 'Not for MFA codes, recovery codes or reset links either. Resend an invitation or reset instead.', tone: 'bad' },
        { head: 'Personal data stays in PeopleOS', body: 'Do not copy PAN, Aadhaar, bank or salary into tickets or chat; reference the employee code.', tone: 'warn' },
        { head: 'Check the role first', body: 'A missing menu is usually a role or scope question, not a fault.' },
        { head: 'Look for a known defect', body: 'Compare with the known-defects slide before escalating.' },
        { head: 'Operators act with reasons', body: 'Entering a tenant needs a ticket reference; it is audited and ends after 60 minutes.', tone: 'info' },
    ] });

    // ---- Sign-in
    d.section(1, 'Sign-in and account activation', 'Generic messages by design: diagnose from the account, not the screen.');
    issue({ title: 'Cannot sign in', symptom: '"These credentials do not match our records." after entering e-mail and password.',
        diagnose: 'Admin › Setup › Users: is the account Active, Invited or Suspended? Platform › Tenants (operator): is the tenant Suspended? Was the e-mail changed recently (verification)?',
        expected: 'The message is the same for a wrong password, an unknown e-mail, an invited (never activated) account and a suspended account or tenant. It never says which.',
        fix: 'Wrong password: the user selects Forgot password? on the sign-in page. Invited: resend the invitation. Suspended user: the customer administrator reactivates. Suspended tenant: operator decision only.',
        escalate: 'Customer administrator (user status); Markedge platform operator (tenant status).', evidence: 'User e-mail, time of attempt, Users list status, tenant status.',
        never: 'Never reset a password on the user\'s behalf by setting one, never read the database to "check" a password, never reactivate a tenant without the documented approval.',
        shots: [{ img: 'support/99-sup-01-wrong-password', title: 'The generic sign-in message', steps: ['Generic message.', 'Forgot password?'], result: 'Identical for every cause: protects accounts from probing.' }] });
    cmp({ title: 'Invited, never activated', eyebrow: 'Sign-in',
        left: { label: 'Users list', img: 'support/99-sup-07-user-invited', callouts: ['1'], caption: 'Status Invited, last login Never: the invitation link (72 hours) was not used.' },
        right: { label: 'Their sign-in attempt', tone: 'bad', img: 'support/99-sup-08-invited-cannot-sign-in', callouts: ['1'], caption: 'Generic message. Fix: resend the invitation (Users for staff; Tenants › Send invitation for a first administrator).' } });
    cmp({ title: 'Password reset: request', eyebrow: 'Sign-in',
        left: { label: 'Forgot password?', img: 'support/99-sup-02-forgot-password', callouts: ['1', '2'], caption: 'E-mail address and Send email.' },
        right: { label: 'Neutral answer (the toast)', img: 'support/99-sup-03-reset-requested', crop: { x: 1500, y: 20, w: 600, h: 180 }, callouts: ['1'], caption: '"If an account uses that address, we have sent it a link…" The link expires in 60 minutes; requests are rate-limited.' } });
    cmp({ title: 'Password reset: complete', eyebrow: 'Sign-in',
        left: { label: 'Reset form (from the e-mail link)', img: 'support/99-sup-04-reset-form', callouts: ['1', '2'], caption: 'New password, confirmation, Reset password.' },
        right: { label: 'Done (the toast)', tone: 'good', img: 'support/99-sup-05-reset-done', crop: { x: 1500, y: 20, w: 600, h: 180 }, callouts: ['1'], caption: '"Your password has been changed. Sign in with it now." Other sessions end.' } });
    cmp({ title: 'Used or expired links', eyebrow: 'Sign-in',
        left: { label: 'Reset link used twice', tone: 'bad', img: 'support/99-sup-06-reset-link-used', callouts: false, caption: 'One message for any bad, used or expired reset link: request a new one.' },
        right: { label: 'Invitation used twice', tone: 'bad', img: 'client-onboarding/90-onb-11-invitation-used', callouts: false, caption: '"This invitation link is invalid or has expired. Ask your administrator to send a new one."' } });
    shot('platform-admin/95-pl-08-suspended-sign-in', { title: 'A whole organisation cannot sign in', eyebrow: 'Sign-in', steps: ['The generic message.'], result: 'Every user of a suspended tenant gets the generic message. Operator: check Platform › Tenants; reactivation needs the documented approval and a reason.' });
    shot('client-onboarding/90-onb-09-password-rules', { title: 'Activation refused: password rules', eyebrow: 'Sign-in', steps: ['Validation message.'], result: '"The password field must be at least 8 characters." The account stays invited until a valid password is set.' });

    // ---- Access
    d.section(2, 'Missing menus and restricted pages', 'Role, scope and relationship decide what each person sees.');
    issue({ title: 'A menu or page is missing, or "not available"', symptom: 'A menu item a colleague has is not shown, or a page says "This page is not available to you".',
        diagnose: 'Admin › Setup › Users › the user: roles and access scope. Compare with the role table in Deck 3. For managers: is the person a direct report effective today?',
        expected: 'Pages appear only with the permission; records only within scope or reporting line. A 403 page is the control working.',
        fix: 'If the access is intended, the customer administrator changes the role or scope (Tenant Super Admin for role permissions). Otherwise explain the boundary.',
        escalate: 'Customer administrator for roles and scope; Markedge support only if the role table says the access should exist.', evidence: 'User e-mail, page address, roles and scope screenshot.',
        never: 'Never grant Tenant Super Admin to "make it work", and never ask an operator to enter the tenant to show someone data they are not allowed to see.',
        shots: [{ img: 'security-compliance/85-acc-08-role-edit-denied-hr-admin', title: 'Example: Tenant HR Admin opening role edit', steps: ['Access refused.'], result: 'HTTP 403 "This page is not available to you": only a Tenant Super Admin edits role permissions (F5).' },
            { img: 'employee/30-self-52-restricted-page', title: 'Example: employee opening an HR page', steps: ['Not available.', 'Go to Home.'], result: 'HTTP 403 "This page is not available to you" with Go to Home and Go back.' }] });
    const mgrDenied = first('manager/40-team-09-outside-team');
    if (mgrDenied) shot(mgrDenied, { title: 'Incorrect employee visibility: outside the team', eyebrow: 'Access', steps: [], result: '"We could not find that" (404), not 403: records outside a manager\'s reach are not revealed. If they should report to the manager, fix the reporting line (Change manager), not the role.' });
    shot('client-onboarding/90-onb-60-hr-executive-roles-denied', { title: 'HR Executive and the Roles page', eyebrow: 'Access', steps: [], result: 'Refused: the HR Executive role does not include role.view. Expected behaviour.' });

    // ---- Data
    d.section(3, 'Employee data problems', 'Imports, duplicates and record errors.');
    issue({ title: 'Employee import stops at "inspected"', symptom: 'After Upload and Inspect, Map columns shows "Failed — Undefined variable $svc"; the import stays inspected.',
        diagnose: 'People › Lifecycle › Employee imports › the import: status inspected; reproduce once with a 2-row file.',
        expected: 'Expected: mapping saved, then Validate, Approve, Run. Actual: known defect F25 (same code serves punch imports).',
        fix: 'Discard the import. Load people with the Hire employee wizard, or have Markedge load them through POST /api/v1/employees (documented; not exercised in the training).',
        escalate: 'Markedge engineering: defect F25 (app/Filament/Support/ImportActions.php map action).', evidence: 'Import number, screenshot of the failure, row count.',
        never: 'Never edit import data in the database or bypass validation to push rows in.',
        shots: [{ img: 'client-onboarding/90-onb-93-import-map-fails', title: 'F25 reproduced in a new tenant', steps: ['Error.'], status: 'INCOMPLETE', result: 'Observed in two tenants on 21 Oct 2026.' }] });
    issue({ title: 'Duplicate person or employee', symptom: 'Hire employee refuses to continue: "This person already exists".',
        diagnose: 'Search the Directory by name, employee code and personal e-mail. Check whether the existing person is alumni (re-employment).',
        expected: 'Definite duplicates (same personal e-mail, phone or work e-mail) are refused; possible duplicates are warned.',
        fix: 'Re-use the existing person in the wizard, or correct the typo. For a returning employee use Change lifecycle state (alumni → active); a dedicated rehire screen does not exist.',
        escalate: 'Customer HR lead; Markedge support if a real duplicate already exists (merge is not available on screen).', evidence: 'Both employee codes and the wizard message.',
        never: 'Never change someone\'s personal e-mail just to get past the duplicate check.',
        shots: [{ img: 'hr-admin/50-hire-01-duplicate-refused', title: 'The duplicate check', callouts: ['1', '2'], result: '"This person already exists: Priya Nair (EMP00004), same personal email."' }] });
    cmp({ title: 'Server errors on record screens (development mode)', eyebrow: 'Data',
        left: { label: 'History tab (F26)', tone: 'bad', img: 'hr-admin/50-360-10-records-history', callouts: false, caption: 'LazyLoadingViolationException. Seen on Employee 360, payroll run, letters and users in non-production.' },
        right: { label: 'Records › Employment (new)', tone: 'bad', img: 'support/50-360-err-01-records-employment', callouts: false, caption: 'TypeError in a lazily loaded section on scroll.' },
        notes: `${DEBUG}\nWORKAROUND: use Insights › Audit › Change history for history. Report the page address and time to engineering.` });

    // ---- Time and leave
    d.section(4, 'Attendance and leave', 'Discrepancies, failures and approvals that do not appear.');
    issue({ title: 'Leave request refused or failing', symptom: '"Cannot apply" with a reason, or a server error when HR uses Apply on behalf.',
        diagnose: 'Read the reason: policy (probation, notice, maximum days, balance). For HR: was it Leave requests › Apply on behalf?',
        expected: 'Policy refusals are intended (e.g. "Earned leave is not available during probation."). Apply on behalf failing is defect F38.',
        fix: 'Policy: choose an eligible type or dates. F38: use Employee 360 › Records › Time › Leave › Apply for leave.',
        escalate: 'Customer HR for policy questions; Markedge engineering for F38.', evidence: 'Leave type, dates, message, employee code.',
        never: 'Never adjust a balance to get around a policy refusal without HR approval; adjustments are ledger entries and audited.',
        shots: [{ img: 'employee/10-leave-03-policy-refusal', title: 'A policy refusal', steps: ['Policy message.'], result: 'Intended behaviour: the leave policy refuses Earned leave during probation.' },
            { img: 'support/55-lv-err-01-apply-on-behalf', title: 'Apply on behalf: F38', status: 'INCOMPLETE', steps: [], result: '"Call to a member function getFormData() on array" when the dialog opens.', notes: DEBUG }] });
    issue({ title: 'Approval not appearing', symptom: 'An employee submitted a request but their manager does not see it in Approvals.',
        diagnose: 'Employee 360 › Records › Employment: who is the primary line manager effective today? Is a workflow running for the request (Workflows › Runs, then Task inbox)?',
        expected: 'Leave and attendance corrections go to the primary line manager effective on the request date. Requests with a running workflow are decided in the Task inbox.',
        fix: 'Fix the reporting line with Change manager (effective date). Ask the approver to check My work › Task inbox.',
        escalate: 'Customer HR (reporting lines); Markedge support for workflow configuration.', evidence: 'Request date, employee code, manager history.',
        never: 'Never approve on someone else\'s behalf by signing in as them.',
        shots: [{ img: 'manager/10-leave-07-approval-center', title: 'Where approvals should appear', steps: [], result: 'My work › Approvals groups decisions Urgent, This week and Later.' }] });
    issue({ title: 'Attendance discrepancy', symptom: 'A day shows absent, missing punch or "worked on holiday" although the employee worked normally.',
        diagnose: 'Attendance records for the date; Punches (source and status); holiday calendar and work schedule assigned; was the day processed after a correction?',
        expected: 'Records are computed from punches, schedule and calendar when the day is processed; approved corrections reprocess the day.',
        fix: 'Employee: Fix my attendance. HR: Manual punch, then Process day. Note: holiday half-days are ignored (F39); late-mark actions are not applied (F40).',
        escalate: 'Customer attendance admin; Markedge support for device or calendar issues.', evidence: 'Date, employee code, punches list, schedule and calendar names.',
        never: 'Never delete punches; corrections are added, the history stays.',
        shots: [{ img: 'manager/20-att-09-exception-centre', title: 'Start from the Exception centre', callouts: false, steps: [['·', 'Exception counts at the top; each row shows its exceptions.']], result: 'Priya\'s 20 Oct row: Present 09:05–18:10 with the exception "Worked on holiday / weekly off": 20 Oct is Dussehra in the demonstration calendar.' }] });
    shot('support/55-lv-err-02-balances-search', { title: 'Balances search fails (new finding)', eyebrow: 'Leave', status: 'INCOMPLETE', steps: [], result: 'Typing a name in the Balances search box gives a database error. Use the Employee and Type filters instead.', notes: DEBUG });

    // ---- Payroll
    d.section(5, 'Payroll', 'Blocked by design until statutory rules are verified.');
    issue({ title: 'Payroll cannot be validated or finalised', symptom: 'Validate says "Not allowed"; Approve and Finalize never appear; no payslips.',
        diagnose: 'Payroll run › Employees tab › Issues (unverified_statutory_rule…). Compliance control room: rules verified?',
        expected: 'Intended: finalisation is blocked while statutory rules are unverified (0 of 24). Runs in jurisdictions without rules are blocked.',
        fix: 'Nothing to fix in the tenant. Calculate and check runs; pay outside PeopleOS until verification. Explain the release gate.',
        escalate: 'Markedge platform operators (rule verification) and the owner (O-5, O-12, O-18).', evidence: 'Run, Issues column, control-room screenshot.',
        never: 'Never ask engineering to switch enforcement off, and never mark a rule verified without official evidence and a second operator.',
        shots: [{ img: 'hr-admin/60-pay-09-validate-blocked', title: 'Validate refused', steps: [], result: '"Not allowed": 19 employee(s) have blocking exceptions. Fix the inputs and recalculate.' }] });

    // ---- Platform
    d.section(6, 'Platform, notifications and offboarding', 'Background work, notifications and the safeguards around leaving.');
    issue({ title: 'Notifications not received', symptom: 'Users expect an e-mail for leave or approvals and receive nothing; an SMS or WhatsApp never arrives.',
        diagnose: 'Communication › Notification rules: is there a rule for the event? Delivery log: queued, sent or failed? Is the queue worker running (Platform › Operations)?',
        expected: 'Notices go only where a rule exists. No role can create or delete rules on screen (F18). SMS, WhatsApp and push send nothing (F19).',
        fix: 'Customer: edit existing rules and templates. Markedge: seed missing rules; ensure the worker runs; Send again on failed deliveries.',
        escalate: 'Markedge support (rules, F18/F19); platform operations (queue).', evidence: 'Event, time, delivery log row.',
        never: 'Never promise e-mail for an event that has no rule.',
        shots: [{ img: 'client-onboarding/90-onb-84-notification-rules', title: 'No rules, no New action', status: 'INCOMPLETE', steps: [], result: 'An empty Notification rules list in a new tenant (F18).' }] });
    issue({ title: 'Failed or stuck background jobs', symptom: 'Exports never finish, scheduled reports do not run, deliveries stay queued, audit chains "not recently verified".',
        diagnose: 'Platform › Operations: queue depth, failed jobs by job, scheduler heartbeat. Platform readiness for WARN/FAIL lines.',
        expected: 'A worker and the scheduler run in production; every job carries its tenant. Locally none runs, so queued work waits.',
        fix: 'Operations restarts the worker or scheduler and retries failed jobs once the cause is fixed.',
        escalate: 'Platform operations / on-call (gate 8: not yet in place).', evidence: 'Operations screenshot, failed job name and time.',
        never: 'Never delete failed jobs before recording them; never run a tenant job without its tenant context.',
        shots: [{ img: 'platform-admin/95-pl-21-operations', title: 'Platform operations', steps: [], result: 'Queue, failed jobs, scheduler heartbeat and per-tenant status.' }] });
    issue({ title: 'Export or offboarding blocked', symptom: 'A customer asks to delete their tenant; an export does not complete; a legal hold cannot be released.',
        diagnose: 'Platform › Offboarding › tenant: holds, retention eligibility, export status.',
        expected: 'Deletion is switched off. Exports run in the background (worker). Releasing a hold needs a second operator. No retention policy exists yet.',
        fix: 'Explain the safeguards; provide the export when the worker is available; a second operator approves releases.',
        escalate: 'Markedge platform operator and the owner (D8 retention decisions).', evidence: 'Tenant, hold reference (e.g. LH-2026-01), export request time.',
        never: 'Never enable deletion or remove a legal hold to satisfy a request.',
        shots: [{ img: 'platform-admin/95-pl-34-release-pending', title: 'Hold release waits for a second operator', steps: ['The result.'], result: 'Release requested; the hold stays active until another operator approves.' }] });
    const f17 = first('employee/30-self-45-give-feedback-error');
    if (f17) shot(f17, { title: 'Give feedback shows an error (F17)', eyebrow: 'Known defects', status: 'INCOMPLETE', steps: [], result: 'The employee sees "Not allowed" with a raw database message (including server details) although the feedback is saved; HR is not notified. Ask HR to check the Feedback inbox. Escalate: the toast exposes technical details.' });
    d.table({ title: 'Known defects and their workarounds', eyebrow: 'Known defects', columns: ['Ref', 'Symptom', 'Workaround', 'Escalation'], colW: [0.75, 4.3, 4.6, 2.48], rows: [
        ['F25', 'Import Map columns: "Undefined variable $svc"', 'Hire wizard; API load by Markedge', 'Engineering'],
        ['F38', 'Apply on behalf: server error', 'Employee 360 › Records › Time › Apply for leave', 'Engineering'],
        ['F44', 'Report builder field lists empty', 'Starter reports; People analytics', 'Engineering'],
        ['F17', 'Give feedback: error, HR not notified', 'HR checks the Feedback inbox', 'Engineering'],
        ['F26', 'History tabs error (non-production)', 'Change history', 'Engineering'],
        ['New', 'Uploader verifying own document: server error', 'A second HR person verifies', 'Engineering'],
        ['New', 'Balances name search: database error', 'Use filters', 'Engineering'],
        ['New', 'Employee 360 Records › Employment section error; onboarding template items error', 'Upper sections and Change history', 'Engineering'],
    ], fontSize: 11.5, notes: 'SOURCE: docs/reports/documentation-gaps.md and the 21 Oct 2026 captures (support/*, security-compliance/50-doc-03).' });
    d.exercise({ title: 'Practice: triage these tickets', tasks: [
        '"All 40 people at our company get the credentials message since this morning."',
        '"I am a manager and Leave requests from my new team member never reach me."',
        '"Our import of 300 employees is stuck at inspected."',
        '"Payroll says Not allowed and we need payslips on Friday."',
    ], notes: 'ANSWERS: (1) Likely a suspended tenant: operator checks Platform › Tenants. (2) Check the reporting line effective today; Change manager. (3) F25: discard; hire wizard or Markedge API load. (4) Statutory gate: cannot be bypassed; pay outside PeopleOS; escalate to Markedge.' });
    d.table({ title: 'Answer key', eyebrow: 'Practice', columns: ['#', 'Answer'], colW: [0.6, 11.53], rows: [
        ['1', 'Likely a suspended tenant (generic message for everyone). The operator checks Platform › Tenants and follows the reactivation approval.'],
        ['2', 'The approver is the primary line manager effective on the request date: fix the reporting line with Change manager.'],
        ['3', 'Known defect F25: discard the import; load with the Hire employee wizard or a Markedge API load; escalate to engineering.'],
        ['4', 'The statutory safeguard: finalisation is blocked until rules are verified. It cannot be bypassed; pay outside PeopleOS and escalate.'],
    ] });
    d.closing({ points: ['Quote the screen; many messages are deliberately generic.', 'Check status, role, scope and reporting line before anything else.', 'Use documented workarounds for known defects; escalate with evidence.', 'Never handle passwords, codes or personal data in tickets.', 'Never weaken a safeguard to close a ticket.'], next: ['Deck 03: HR screens', 'Deck 05: operator actions', 'Deck 08: security boundaries'] });
    return d;
};
