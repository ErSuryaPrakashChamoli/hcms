// Deck 2 — Manager self-service training.
'use strict';
const common = require('./common');
const flows = require('./flows');

module.exports = (lib) => {
    const { Deck, has } = lib;
    const d = new Deck({
        num: 2, file: '02-PeopleOS-Manager-Self-Service-Training.pptx', short: 'Manager training',
        title: 'Manager Self-Service Training',
        subtitle: 'See your team, decide requests with context, support goals and growth, and know exactly what you can and cannot see.',
        audience: 'Line managers and team leads',
    });
    const M = (n) => `manager/${n}`;
    const shot = (name, o) => { if (has(name)) d.shot({ img: name, ...o }); else lib.WARN.push(`deck 2: missing ${name}`); };
    const cmp = (o) => { if (has(o.left.img) && has(o.right.img)) d.compare(o); else lib.WARN.push(`deck 2: missing ${o.left.img} / ${o.right.img}`); };
    const SRC = 'Captured on the training database on 21 Oct 2026 as Amit Verma, fictional manager of the Platform team at the fictional Demo Group (tools/capture/40-manager.mjs, 10-leave-flow.mjs, 20-attendance-flow.mjs).';

    d.cover({ shot: M('40-team-01-home'), notes: `SAY: Managers make most of the day-to-day decisions in PeopleOS. This session shows where they appear, what context you get, and where your access stops.\nEVIDENCE: ${SRC}` });
    d.statusLegend();
    common.statusSimple(d);
    d.numbered({ title: 'What you will be able to do', eyebrow: 'Learning objectives', items: [
        ['See your team at a glance', 'Home lenses, My team, Directory'],
        ['Read a team member\'s record', 'and know what is not shown to managers'],
        ['Decide leave and attendance', 'with reason, overlap and balance'],
        ['Support goals and reviews', 'check-ins, one-on-ones, feedback, appraisals'],
        ['Develop your team', 'learning, career, HR requests, onboarding tasks'],
        ['Use team insights', 'within your scope'],
    ] });
    d.cards({ title: 'Your reach as a manager', eyebrow: 'Access', cards: [
        { head: 'Who you see', body: 'Yourself and everyone who reports to you today (any reporting type), plus any team scope your administrator gave you.', tone: 'info' },
        { head: 'What you see', body: 'Timeline, employment, time, growth and personal details your role allows. Pay (Rewards) and Operations are not shown to managers.', tone: 'info' },
        { head: 'What you cannot do', body: 'Edit employee records, hire, change pay or open people outside your reach. Ask HR.', tone: 'warn' },
        { head: 'Broader visibility', body: 'Only if your administrator adds organisation scope or HR permissions to your account.', tone: 'info' },
    ], notes: 'SOURCE: architecture contract §6 relationship scope; captures 40-team-05, 40-team-07, 40-team-09. Amit\'s Platform-team scope is a team dimension assigned by the administrator.' });

    d.section(1, 'Your team', 'Home, My team, the Directory and the employee register.');
    shot(M('40-team-02-home-team-lens'), { title: 'Home: the Your team lens', eyebrow: 'Your team', steps: ['Team pulse.'], result: 'View as chips switch Home between For you and Your team. Your team shows who is in today, on leave, approvals pending and who needs attention.' });
    shot(M('40-team-03-my-team'), { title: 'My team', eyebrow: 'Your team', steps: ['Team career.', 'Team learning.'], result: 'Direct reports with today\'s status and what needs attention.' });
    cmp({ title: 'Directory and register', eyebrow: 'Your team',
        left: { label: 'Directory', img: M('40-team-04-directory'), callouts: ['1', '2'], caption: 'Only the people you may see: your team and your own line.' },
        right: { label: 'Employees register', img: M('40-team-05-employee-register'), callouts: ['1'], caption: 'Seven people in scope, View only: no Edit, no Hire employee.' } });
    shot(M('40-team-06-member-360'), { title: 'A team member\'s record', eyebrow: 'Your team', steps: ['Message.', 'Views.'], result: 'Status, probation end, what is next and recent changes.' });
    cmp({ title: 'What a manager sees in Records', eyebrow: 'Your team',
        left: { label: 'Record tabs', img: M('40-team-07-member-records'), callouts: ['1'], caption: 'Timeline, Employment, Time, Growth, Personal. Rewards and Operations are not offered.' },
        right: { label: 'Personal', img: M('40-team-08-member-personal'), callouts: ['1'], caption: 'Personal details a manager may see; HR additionally sees bank and identity details.' },
        notes: 'KNOWN ISSUE: Records › Employment may show a server error when its lower tables load (new finding, 21 Oct 2026). Use Timeline for history.' });
    shot(M('40-team-09-outside-team'), { title: 'Someone outside your team', eyebrow: 'Your team', steps: ['Not found.'], result: '"We could not find that" (404): records outside your reach are not revealed, not even that they exist.' });

    d.section(2, 'Approvals', 'Leave and attendance decisions with the context you need.');
    flows.leaveManager(d);
    d.section(3, 'Team attendance and leave', 'Corrections, exceptions and who is away.');
    flows.attendanceManager(d);
    shot(M('40-team-10-leave-calendar'), { title: 'Leave calendar', eyebrow: 'Team attendance', steps: ['Team leave.'], result: 'Who is away and when, approved and pending (this month to the end of next; the rows here are pending). Reasons are never shown here.' });

    d.section(4, 'Goals and performance', 'Goals, reviews, one-on-ones and feedback.');
    shot(M('40-team-11-team-goals'), { title: 'Team goals', eyebrow: 'Performance', steps: ['A team member\'s goal.'], result: 'Progress linked to the department goal (Priya\'s goal at 70% after her check-in).' });
    shot(M('40-team-12-team-appraisals'), { title: 'Team appraisals', eyebrow: 'Performance', steps: ['Priya\'s appraisal.'], result: 'The manager review opens once the self review is in and HR opens the stage.' });
    shot(M('40-team-23-appraisal-manager-view'), { title: 'Read the self review', eyebrow: 'Performance', steps: ['Manager review.', 'Add reviewer (peer).', 'Self review (submitted).'] });
    cmp({ title: 'Manager review: wait for the stage', eyebrow: 'Performance',
        left: { label: 'Manager review form', img: M('40-team-24-manager-review-form'), callouts: ['1', '2', '3'], caption: 'Goals, overall rating, comments. The button is offered before the stage opens.' },
        right: { label: 'Refused', tone: 'bad', img: M('40-team-25-manager-review-stage-closed'), callouts: ['1'], caption: '"Not allowed: The Manager review stage has not opened yet." HR must advance the cycle; stages do not advance by date (F21).' } });
    cmp({ title: 'One-on-ones', eyebrow: 'Performance',
        left: { label: 'Schedule', img: M('40-team-13-schedule-one-on-one'), callouts: ['1', '2', '3'], caption: 'When and agenda; notes and action items are added on the meeting.' },
        right: { label: 'Scheduled', tone: 'good', img: M('40-team-14-one-on-one-scheduled'), callouts: ['1'], caption: '"Created": 28 Oct, 15:00, with Priya.' } });
    cmp({ title: 'Give feedback', eyebrow: 'Performance',
        left: { label: 'Give feedback', img: M('40-team-15-give-feedback'), callouts: ['1', '2', '3', '4'], caption: 'Type, visibility, message.' },
        right: { label: 'Shared', tone: 'good', img: M('40-team-16-feedback-given'), callouts: ['1', '2'], caption: '"Feedback shared"; Priya is notified.' } });

    d.section(5, 'Development and support', 'Learning, career, HR requests, onboarding tasks and insights.');
    cmp({ title: 'Team learning and career', eyebrow: 'Development',
        left: { label: 'Team learning', img: M('40-team-17-team-learning'), callouts: ['1', '2'], caption: 'Status, requests to decide, skill gaps; Assign learning.' },
        right: { label: 'Team career', img: M('40-team-18-team-career'), callouts: ['1'], caption: 'What each person chose to share about aspirations.' } });
    cmp({ title: 'HR requests and tasks', eyebrow: 'Development',
        left: { label: 'Team HR requests', img: M('40-team-19-team-hr-requests'), status: 'UNABLE TO VERIFY', callouts: ['1'], caption: 'Empty at capture although Priya had an open request; which requests appear here was not verified. Confidential cases are never shown.' },
        right: { label: 'Onboarding tasks', img: M('40-team-26-onboarding-tasks'), callouts: ['1', '2'], caption: 'Tasks for a new joiner in your team (Tanvi Joshi), with Done (and Skip where allowed).' } });
    cmp({ title: 'Team insights and notifications', eyebrow: 'Development',
        left: { label: 'People analytics', img: M('40-team-22-people-analytics'), callouts: ['1', '2'], caption: 'Attendance and leave for people in your scope only; small groups hidden.' },
        right: { label: 'Notifications', img: M('40-team-21-notifications'), callouts: ['1'], caption: 'Approvals and team updates, grouped.' } });
    d.cards({ title: 'Common mistakes', eyebrow: 'Tips', cards: [
        { head: 'Approving without reading', body: 'A and R only open the confirm box. Read reason, overlap and balance first.', tone: 'warn' },
        { head: 'Vague rejection reasons', body: 'The employee sees your reason. Say what to do next.' },
        { head: 'Expecting to edit records', body: 'Managers view; HR edits. Raise team requests from My work › Team HR requests.' },
        { head: 'Missing a request', body: 'If a request goes elsewhere, the reporting line may be wrong: ask HR to check it.' },
        { head: 'Manager review refused', body: 'The stage is not open yet; HR advances the cycle.' },
        { head: 'Sharing screenshots', body: 'Do not share team members\' personal details outside PeopleOS.', tone: 'bad' },
    ] });
    d.exercise({ title: 'Practice (practice tenant only)', tasks: [
        'Open the Approval Center. Which request is most urgent, and why?',
        'Reject a test request without a reason. What happens? Then reject it properly.',
        'Open a person outside your team from a link. What do you see?',
        'Schedule a one-on-one with a team member for next week.',
    ], notes: 'ANSWERS: (1) Top of Urgent; the card says why (starts within a day or overdue). (2) "Please fill out this field."; with a reason, "Leave rejected". (3) "We could not find that". (4) People › Growth › One-on-ones › Schedule.' });
    d.table({ title: 'Answer key', eyebrow: 'Practice', columns: ['#', 'Answer'], colW: [0.6, 11.53], rows: [
        ['1', 'The first item under Urgent; its card explains why (overdue, starting within a day, or flagged).'],
        ['2', 'The browser stops it ("Please fill out this field."). With a reason, "Leave rejected" and the employee sees your reason.'],
        ['3', '"We could not find that" (404): records outside your reach are not revealed.'],
        ['4', 'People › Growth › One-on-ones › Schedule: date and time, agenda, Create.'],
    ] });
    d.closing({ points: ['Home and My work bring decisions to you, most urgent first.', 'Every decision card shows reason, overlap and balance; rejections need a reason.', 'You see your reports, not their pay; outside your team returns "not found".', 'Reviews follow the cycle stages HR opens.'], next: ['Ask PeopleOS (sidebar) explains with sources', 'Your HR partner for access or reporting-line changes', 'Deck 01: what your team sees'] });
    return d;
};
