// Deck 1 — Employee user training (plain language).
'use strict';
const common = require('./common');
const flows = require('./flows');

module.exports = (lib) => {
    const { Deck, has } = lib;
    const d = new Deck({
        num: 1, file: '01-PeopleOS-Employee-User-Training.pptx', short: 'Employee training',
        title: 'Employee User Training',
        subtitle: 'Use PeopleOS for your own work: find your way, ask for leave, fix attendance, get help from HR, and follow your goals and learning.',
        audience: 'Every employee with a PeopleOS account',
    });
    const E = (n) => `employee/${n}`;
    const shot = (name, o) => { if (has(name)) d.shot({ img: name, ...o }); else lib.WARN.push(`deck 1: missing ${name}`); };
    const cmp = (o) => { if (has(o.left.img) && has(o.right.img)) d.compare(o); else lib.WARN.push(`deck 1: missing ${o.left.img} / ${o.right.img}`); };
    const SRC = 'Captured on the training database on 21 Oct 2026 as Priya Nair and Rahul Sharma, fictional employees of the fictional Demo Group (tools/capture/30-employee.mjs, 10-leave-flow.mjs, 20-attendance-flow.mjs).';

    d.cover({ shot: E('30-self-04-home'), notes: `SAY: Welcome. In about an hour you will know how to do the everyday things in PeopleOS yourself. Everything you see is the real application with invented people.\nEVIDENCE: ${SRC}\nAVOID in a shared training tenant: submitting real leave or real personal details.` });
    d.statusLegend('SAY: Each picture is a real PeopleOS screen. The green label means we tried it and it worked. A red label means there is a known problem; the slide tells you what to do instead.');
    common.statusSimple(d);
    d.numbered({ title: 'What you will be able to do', eyebrow: 'Learning objectives', items: [
        ['Sign in and find your way', 'Home, menus, search'],
        ['Look after your own record', 'details, documents, letters'],
        ['Ask for leave and fix attendance', 'and see your manager\'s answer'],
        ['Get help from HR', 'requests, policies, announcements'],
        ['See pay and tax information', 'what is available today'],
        ['Follow your goals and learning', 'check-ins, reviews, courses'],
    ], notes: 'ASK: "What do you most often need from HR?" Point to the section that answers it.' });

    // ---- Getting started
    d.section(1, 'Getting started', 'Sign in, read your Home, and find anything in a few keystrokes.');
    cmp({ title: 'Sign in', eyebrow: 'Getting started',
        left: { label: 'Sign-in page', img: E('30-self-01-sign-in'), callouts: ['1', '2', '4'], caption: 'Your work e-mail and password, then Sign in. Forgot password? sends a reset link.' },
        right: { label: 'If it does not work', tone: 'bad', img: E('30-self-02-sign-in-failed'), callouts: ['1'], caption: '"These credentials do not match our records." Check your password or use Forgot password?. If it still fails, ask HR.' },
        notes: 'SAY: The message never says exactly what is wrong, on purpose: it protects your account.\nIF YOUR ORGANISATION REQUIRES IT: you will also enter a 6-digit code from an authenticator app.' });
    shot(E('30-self-03-first-home-welcome'), { title: 'Your first visit', eyebrow: 'Getting started', steps: ['A short welcome note.', 'Got it hides the note.', 'Search anything (Ctrl K).', 'Request leave.'], result: 'PeopleOS puts your work on Home, each item with the reason it is there.' });
    shot(E('30-self-04-home'), { title: 'Read your Home', eyebrow: 'Getting started', steps: ['For you: things about you, such as probation or an expiring passport.', 'Need attention: reminders such as missing bank details.', 'PeopleOS Intelligence: short facts with their source.', 'Start something: common requests.'], result: 'Not now hides a reminder for a while; Open takes you to the place to act.' });
    shot(E('30-self-05-home-full'), { title: 'Further down Home', eyebrow: 'Getting started', steps: ['Your day: check in and out.', 'My requests and their status.', 'Your momentum: from joining to confirmation.', 'Start something.'], result: 'Scroll for your day, your requests, what changed and your people.' });
    shot(E('30-self-06-navigation'), { title: 'Menus', eyebrow: 'Getting started', steps: ['Sidebar sections.', 'The area you are in.', 'Area bar: pages in this area.', '"All in …" lists everything in the area.'], result: 'You only see menus your role allows.' });
    cmp({ title: 'Search: Ctrl K', eyebrow: 'Getting started',
        left: { label: 'Find a person', img: E('30-self-07-search-person'), callouts: ['1', '2'], caption: 'Type a name: Preview or see them in the org map.' },
        right: { label: 'Find a page', img: E('30-self-08-search-payslip'), callouts: ['1'], caption: 'Type "payslip": pages and actions you are allowed to open.' } });
    cmp({ title: 'Start something and My day', eyebrow: 'Getting started',
        left: { label: '+ New', img: E('30-self-09-quick-launch'), callouts: ['1'], caption: 'Everything you can start: leave, attendance correction, HR request…' },
        right: { label: 'My day (avatar menu)', img: E('30-self-10-my-day'), callouts: ['1', '2', '3'], caption: 'Apply leave, Regularise attendance, Ask HR; today\'s attendance and balances.' } });
    shot(E('30-self-11-my-work'), { title: 'My work: everything waiting', eyebrow: 'Getting started', steps: ['Filters: Everything, Tasks, Follow-ups, Done…', 'Waiting on others: requests with someone else.'], result: '"Do this next" suggests the most urgent item first.' });
    cmp({ title: 'Notifications', eyebrow: 'Getting started',
        left: { label: 'Notifications page', img: E('30-self-12-notifications'), callouts: ['1', '2', '3'], caption: 'Grouped; Open, Mark read, Snooze. Preferences decide e-mail or in-app.' },
        right: { label: 'Bell', img: E('30-self-13-bell-panel'), callouts: ['1'], caption: 'Unread notifications in a side panel.' },
        notes: 'SAY: Some notices depend on rules your HR team sets up; not every event sends an e-mail.' });

    // ---- Your record
    d.section(2, 'Your record', 'See your details and documents; ask HR to change anything that is wrong.');
    shot(E('30-self-14-my-record-now'), { title: 'Your record', eyebrow: 'Your record', steps: ['Your record (open it from the avatar menu › My profile).', 'Views: Now, Journey, Work, Growth, Documents, Records.', 'Who you report to.'], result: 'Read only. Changes go through HR so they are recorded properly.' });
    cmp({ title: 'Work details and history', eyebrow: 'Your record',
        left: { label: 'Work', img: E('30-self-15-my-record-work'), callouts: ['1'], caption: 'Position, organisation and working relationships.' },
        right: { label: 'Records › Timeline', img: E('30-self-16-my-record-timeline'), maxAspect: 1.1, callouts: ['1'], caption: 'Every change to your record with its date: the same history HR sees.' } });
    shot(E('30-self-17-my-record-personal'), { title: 'Personal details', eyebrow: 'Your record', steps: ['Records › Personal.'], result: 'Addresses, family and other personal details (empty for this fictional employee). You cannot edit them yourself: raise an HR request (Ask HR) for a correction.' });
    cmp({ title: 'Documents and letters', eyebrow: 'Your record',
        left: { label: 'Documents', img: E('30-self-18-my-documents'), callouts: ['1'], caption: 'Your documents with status and expiry. HR uploads them; send renewals to HR.' },
        right: { label: 'My letters', img: E('30-self-19-my-letters'), callouts: ['1', '2'], caption: 'Letters HR issued to you; open one to read or download it.' } });

    // ---- Leave and attendance
    d.section(3, 'Leave', 'Request leave, follow it, and see your balance change.');
    flows.leaveEmployee(d);
    d.section(4, 'Attendance', 'Check in, and correct a day when a punch is missing.');
    flows.attendanceEmployee(d);

    // ---- HR services
    d.section(5, 'Help from HR', 'Ask a question, read policies, follow announcements.');
    shot(E('30-self-35-my-hr'), { title: 'My HR', eyebrow: 'Help from HR', steps: ['Ask HR.', 'Tabs: requests, tasks, documents, policies, services, surveys…'], result: 'Services › My HR is where you start anything with HR.' });
    shot(E('30-self-36-ask-hr-form'), { title: 'Ask HR', eyebrow: 'Help from HR', steps: ['Category.', 'Subject.', 'Description.', 'Priority.', 'Submit.'], result: 'Do not put bank numbers or ID numbers in the text: HR will ask through a secure route.' });
    cmp({ title: 'Your request gets a number', eyebrow: 'Help from HR',
        left: { label: 'Submitted', tone: 'good', img: E('30-self-37-ask-hr-submitted'), callouts: ['1'], caption: 'Confirmation with the request number (here TKT-2026-00002).' },
        right: { label: 'My requests', img: E('30-self-38-my-requests'), callouts: ['1', '2'], caption: 'Status Submitted and the date HR aims to answer by.' } });
    shot(E('30-self-39-request-detail'), { title: 'Follow the conversation', eyebrow: 'Help from HR', steps: ['Your request.'], result: 'Status, target date and messages with HR in one place.' });
    shot(E('30-self-40-knowledge-base'), { title: 'Knowledge base', eyebrow: 'Help from HR', steps: ['A published article.'], result: 'HR articles; those you must acknowledge are marked.' });
    cmp({ title: 'Acknowledge a policy', eyebrow: 'Help from HR',
        left: { label: 'Leave policy', img: E('30-self-41-policy-acknowledge'), callouts: ['1'], caption: '"I have read and understood" records your acknowledgement of this version.' },
        right: { label: 'Acknowledged', tone: 'good', img: E('30-self-42-policy-acknowledged'), callouts: ['1'], caption: 'Confirmation; the policy leaves "Policies to acknowledge".' } });
    cmp({ title: 'Announcements', eyebrow: 'Help from HR',
        left: { label: 'Announcements', img: E('30-self-46-announcements'), callouts: ['1', '2'], caption: 'Some ask you to acknowledge them.' },
        right: { label: 'Acknowledged', tone: 'good', img: E('30-self-47-announcement-acknowledged'), callouts: ['1'], caption: 'Confirmation; the announcement shows it is acknowledged.' } });
    cmp({ title: 'Give feedback: a known problem', eyebrow: 'Help from HR',
        left: { label: 'Give feedback', img: E('30-self-44-give-feedback-form'), callouts: ['1', '2', '3'], caption: 'Mode, category and your feedback.' },
        right: { label: 'After Submit', tone: 'bad', img: E('30-self-45-give-feedback-error'), callouts: ['1'], caption: 'An error appears, but your feedback is saved. HR is not notified automatically (F17): tell HR if it is urgent.' },
        notes: 'KNOWN DEFECT F17: on MySQL, the notification to HR fails and the employee sees "Not allowed" with a technical database message. The feedback itself is stored in the HR Feedback inbox.\nAVOID: asking employees to resubmit; it creates duplicates.' });
    shot(E('30-self-43-surveys'), { title: 'Surveys', eyebrow: 'Help from HR', steps: ['Surveys tab.'], result: 'Surveys open to you appear here (none were open in the training data).' });

    // ---- Pay
    d.section(6, 'Pay and tax', 'What you can see today, and what is not available yet.');
    shot(E('30-self-20-payslips-none'), { title: 'Payslips: not yet', eyebrow: 'Pay and tax', steps: ['Empty state.'], result: '"No payslips yet". Payslips appear only after a payroll run is finalised, and that is blocked until statutory rules are verified.' });
    cmp({ title: 'Tax declaration', eyebrow: 'Pay and tax',
        left: { label: 'New declaration', img: E('30-self-21-tax-declaration-form'), callouts: ['1', '2', '3'], caption: 'Financial year, tax regime and declared amounts; Create.' },
        right: { label: 'Saved as Draft', tone: 'bad', img: E('30-self-22-tax-declaration-saved'), callouts: ['1'], caption: 'It stays Draft: there is no Submit for employees yet (F13). Tell payroll when it is complete.' } });
    shot(E('30-self-23-my-compensation'), { title: 'My compensation', eyebrow: 'Pay and tax', steps: ['Pay in force today.', 'History of approved changes.'], result: 'Read only (figures are fictional). Every view is recorded.' });

    // ---- Growth
    d.section(7, 'Goals, reviews, career and learning', 'Keep your goals current, complete your review, grow your skills.');
    shot(E('30-self-24-goals'), { title: 'Your goals', eyebrow: 'Goals', steps: ['A goal linked to a team goal.', 'Check in.'], result: 'Weight, progress and the goal each one supports.' });
    shot(E('30-self-25-goal-check-in'), { title: 'Check in on a goal', eyebrow: 'Goals', steps: ['Key result.', 'Current value.', 'Confidence.', 'Note.'], result: 'Progress is recalculated from the key result (here 40 → 80 target; 68 = 70%).' });
    shot(E('30-self-26-goal-updated'), { title: 'Progress updated', eyebrow: 'Goals', steps: ['Confirmation.', 'New progress.'], result: '"Progress updated": 55% → 70%. Tip: check in on the key result; a check-in on the goal itself does not change progress when it has key results.' });
    shot(E('30-self-27-appraisal'), { title: 'Your appraisal', eyebrow: 'Reviews', steps: ['Cycle stage: Self review.', 'Self review.'], result: 'Complete your self review before the stage closes.' });
    shot(E('30-self-28-self-review-form'), { title: 'Self review', eyebrow: 'Reviews', maxAspect: 2.2, steps: ['Overall rating.', 'Submit.'], result: 'Then your manager reviews.' });
    shot(E('30-self-29-self-review-submitted'), { title: 'Self review submitted', eyebrow: 'Reviews', steps: ['Self: submitted, overall 4.00.'], result: 'You see your own review; your manager\'s review is not shown to you here.' });
    cmp({ title: 'Your career', eyebrow: 'Career',
        left: { label: 'Career passport', img: E('30-self-30-career-passport'), callouts: ['1', '2'], caption: 'Skills, certifications, performance history, aspirations and next step.' },
        right: { label: 'My career', img: E('30-self-31-my-career'), callouts: ['1', '2'], caption: 'Choose what to share, record aspirations and mobility interest.' } });
    shot(E('30-self-32-my-learning'), { title: 'My learning', eyebrow: 'Learning', steps: ['An assigned course.', 'Request learning.'], result: 'Assigned learning, certificates, skills and development plans.' });
    cmp({ title: 'Start a course', eyebrow: 'Learning',
        left: { label: 'Enrolment', img: E('30-self-33-enrolment'), callouts: ['1', '2'], caption: 'Status Assigned, due date and modules; Start.' },
        right: { label: 'Started', tone: 'good', img: E('30-self-34-enrolment-started'), callouts: ['1', '2'], caption: '"Started": In progress; mark modules complete as you go.' } });

    // ---- Account
    d.section(8, 'Your account', 'Security, preferences, devices and limits.');
    cmp({ title: 'Account security and preferences', eyebrow: 'Your account',
        left: { label: 'Account security', img: E('30-self-48-account-security'), callouts: ['1', '2'], caption: 'Change your password; set up an authenticator app (2FA).' },
        right: { label: 'Preferences', img: E('30-self-49-preferences'), callouts: ['1', '2'], caption: 'Density, pinned people, Home and notification preferences.' },
        notes: 'SAY: Never share your password or authenticator codes, not even with HR or support.' });
    cmp({ title: 'Phone and dark mode', eyebrow: 'Your account',
        left: { label: 'Phone', img: E('30-self-50-phone-home'), maxAspect: 2.2, callouts: ['1'], caption: 'The same Home with a bottom bar. Use your phone\'s browser (there is no app).' },
        right: { label: 'Dark theme', img: E('30-self-51-dark-home'), callouts: false, caption: 'Avatar menu › theme: light, dark or system.' } });
    shot(E('30-self-52-restricted-page'), { title: 'Pages you cannot open', eyebrow: 'Your account', steps: ['Not available.', 'Go to Home.'], result: '"This page is not available to you" means the page is for another role, not that something is broken.' });
    cmp({ title: 'If you resign', eyebrow: 'Your account',
        left: { label: 'My exit', img: E('30-self-53-my-exit'), callouts: ['1'], caption: 'You › My exit shows any exit case and Resign.' },
        right: { label: 'Resign (shown, then cancelled)', img: E('30-self-54-resign-form'), maxAspect: 1.3, callouts: ['1', '2', '3'], caption: 'Reason and requested last day, then Confirm. HR takes it from there.' },
        notes: 'AVOID: submitting this in a shared training tenant. In the capture the form was cancelled.' });

    d.cards({ title: 'Common mistakes and how to avoid them', eyebrow: 'Tips', cards: [
        { head: 'Leave type not allowed', body: 'Some types are refused during probation even if a balance shows. Read the message and pick another type.', tone: 'warn' },
        { head: 'Editing a submitted request', body: 'You cannot edit it. Cancel while it is pending and submit a new one.' },
        { head: 'Waiting for an e-mail', body: 'Check My work and Leave requests instead; e-mails depend on your organisation\'s settings.' },
        { head: 'Personal data in messages', body: 'Never type bank or ID numbers into Ask HR or feedback.', tone: 'bad' },
        { head: 'Wrong details on your record', body: 'Use Ask HR; you cannot edit your record yourself.' },
        { head: 'A page says not available', body: 'It belongs to another role. Ask your manager or HR if you think you need it.' },
    ] });
    d.exercise({ title: 'Try it yourself (practice tenant only)', tasks: [
        'Find your Casual leave balance and how many days are pending.',
        'Request one day of Casual leave next month, then cancel it while it is pending.',
        'Raise an HR request asking where to find the holiday list. Note the request number.',
        'Check in on one of your goals and see the new progress.',
    ], notes: 'ANSWER KEY: (1) People › Time & leave › Balances, Casual leave row: Pending and Available. (2) Home › Request leave; then Leave requests › Cancel on the pending row. (3) Services › My HR › Ask HR; number shown in the confirmation and in My requests. (4) People › Growth › Goals › Check in on the key result.' });
    d.table({ title: 'Answer key', eyebrow: 'Practice', columns: ['#', 'Where and how'], colW: [0.6, 11.53], rows: [
        ['1', 'People › Time & leave › Balances: the Casual leave row shows Pending and Available.'],
        ['2', 'Home › Request leave › Casual leave, one day, reason, Submit request. Then Leave requests › Cancel on that row.'],
        ['3', 'Services › My HR › Ask HR: category, subject, description, Submit. The number appears in the confirmation and in My requests.'],
        ['4', 'People › Growth › Goals › Check in: choose the key result, enter the current value and confidence. Progress updates.'],
    ] });
    d.closing({ next: ['Your HR team: My HR › Ask HR', 'Your manager, for approvals', 'Ask PeopleOS (sidebar): explains with sources, never decides', 'Your organisation\'s support contacts (shared at go-live)'], points: ['Home and My work show what needs you, with the reason.', 'Request leave and fix attendance from Home; your manager decides.', 'Ask HR from My HR; follow the request number.', 'Payslips appear once payroll is live; tax declarations stay Draft for now.', 'Keep goals current and finish your self review on time.'] });
    return d;
};
