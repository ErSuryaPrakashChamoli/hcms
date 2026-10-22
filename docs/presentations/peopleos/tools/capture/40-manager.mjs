// Deck 2 — Manager self-service (Amit Verma: Manager for the Platform team + Employee). Disposable training database only.
// Sections can be run on their own: node capture/40-manager.mjs home team …
import { start, context, BASE, shot, finish, settle, modal, setData, dismissWelcome, toasts, cancelModal } from './lib.mjs';


// Faster waits than lib's quick()/settle(): Livewire polling keeps the network busy, so cap the network-idle wait.
async function quick(page, ms = 900) { try { await page.waitForLoadState('networkidle', { timeout: 4000 }); } catch {} await page.waitForTimeout(ms); }
async function nav(page, path) {
    const r = await page.goto(path.startsWith('http') ? path : `${BASE}${path}`, { waitUntil: 'domcontentloaded', timeout: 120_000 });
    page.__status = r?.status();
    await quick(page, 1200);
    await page.addStyleTag({ content: '*{caret-color:transparent!important}' }).catch(() => {});
}
async function setd(page, key, value) {
    await page.evaluate(([k, v]) => {
        const c = window.Livewire.all().find((c) => Array.isArray(c.$wire.mountedActions) && c.$wire.mountedActions.length > 0);
        if (!c) throw new Error('no mounted action');
        return c.$wire.set(`mountedActions.0.data.${k}`, v);
    }, [key, value]);
    await quick(page, 900);
}
/** Close visible toasts only (lib closeToasts also tries the hidden buttons inside the bell panel, 30 s each). */
async function closeT(page) {
    for (const b of await page.locator('.fi-no-notification button.fi-icon-btn, .fi-no-notification .fi-no-notification-close-btn').filter({ visible: true }).all()) {
        await b.click({ timeout: 2000 }).catch(() => {});
    }
    await page.waitForTimeout(400);
}
async function openq(s, persona, path, opts = {}) {
    const ctx = await context(s, persona, opts);
    const page = await ctx.newPage();
    page.__persona = persona;
    page.on('pageerror', (e) => { (page.__errors ??= []).push(String(e).slice(0, 300)); });
    await nav(page, path);
    return page;
}

const s = await start('40-manager');
const want = process.argv.slice(2);
const failures = [];
async function sec(name, fn) {
    if (want.length && !want.includes(name)) return;
    try { await fn(); } catch (e) { failures.push(`${name}: ${String(e).split('\n')[0].slice(0, 200)}`); console.log(`  ✗ ${name}: ${String(e).split('\n')[0].slice(0, 200)}`); }
}
const byId = (page, id) => modal(page).locator(`[id="mountedActionSchema0.${id}"]`);
const M = (workflow, extra = {}) => ({ deck: [2], workflow, ...extra });
let mgr;
const amit = async (path) => { if (!mgr) mgr = await openq(s, 'manager', path); else await nav(mgr, path); await closeT(mgr); return mgr; };

/** Pick an option in a Filament searchable select inside the open modal. */
async function pick(page, nth, text) {
    await modal(page).locator('button', { hasText: 'Select an option' }).nth(nth).click();
    await quick(page, 600);
    await page.keyboard.type(text, { delay: 40 });
    await quick(page, 1200);
    await page.locator('[role=option], .fi-select-input-option').filter({ hasText: text }).filter({ visible: true }).first().click();
    await quick(page, 800);
}

await sec('home', async () => {
    const p = await amit('/admin');
    await dismissWelcome(p);
    await shot(s, p, 'manager/40-team-01-home', { ...M('Manager Home and team visibility', { deck: [2, 9] }), screen: 'Home (manager)',
        action: 'Sign in: Home opens on what needs the manager', expected: 'Decisions waiting, Your team, Team pulse; View as chips switch between For you and Your team',
        callouts: [{ loc: p.getByRole('button', { name: 'Your team', exact: true }), n: 1, label: 'View as' }, { loc: p.getByText(/^\s*DECISIONS\ WAITING\ FOR\ YOU\s*\d*\s*$/i), n: 2, label: 'Decisions waiting' },
            { loc: p.getByText(/^\s*YOUR\ TEAM\s*\d*\s*$/i), n: 3, label: 'Your team' }] });
    await p.getByRole('button', { name: 'Your team', exact: true }).filter({ visible: true }).first().click();
    await quick(p, 1500);
    await shot(s, p, 'manager/40-team-02-home-team-lens', { ...M('Manager Home and team visibility'), screen: 'Home › View as: Your team',
        action: 'Choose View as › Your team', expected: 'Team in today, on leave, approvals pending, need attention; team actions',
        callouts: [{ loc: p.getByText(/^\s*TEAM\ PULSE\s*\d*\s*$/i), n: 1, label: 'Team pulse' }] });
});

await sec('team', async () => {
    const p = await amit('/admin/my-team');
    await shot(s, p, 'manager/40-team-03-my-team', { ...M('Direct reports'), screen: 'People › My team',
        action: 'Open People › My team', expected: 'Direct reports with status today and what needs attention; Team career and Team learning tabs',
        callouts: [{ loc: p.getByRole('link', { name: 'Team career' }), n: 1, label: 'Team career' }, { loc: p.getByRole('link', { name: 'Team learning' }), n: 2, label: 'Team learning' }] });
    await nav(p, '/admin/people');
    await shot(s, p, 'manager/40-team-04-directory', { ...M('Direct reports', { deck: [2, 8] }), screen: 'People › Directory (manager scope)',
        action: 'Open People › Directory', expected: 'Only the people the manager may see (relationship scope: the Platform team and own line)',
        callouts: [{ loc: p.getByRole('button', { name: 'Cards' }), n: 1, label: 'Cards / Table' }, { loc: p.getByRole('button', { name: /Priya Nair/ }), n: 2, label: 'Team member' }] });
    await nav(p, '/admin/employees');
    await shot(s, p, 'manager/40-team-05-employee-register', { ...M('Direct reports', { deck: [2, 8] }), screen: 'People › Lifecycle › Employees (manager)',
        action: 'Open the Employees register', expected: 'Only the people in the manager’s scope, each with View only (no Edit, no Hire employee)',
        callouts: [{ loc: p.getByRole('link', { name: 'View' }), n: 1, label: 'View only' }] });
});

await sec('member', async () => {
    const p = await amit('/admin/employees/4');
    await shot(s, p, 'manager/40-team-06-member-360', { ...M('Team member record (Employee 360)', { deck: [2, 9] }), screen: 'Employee 360 › Now (Priya Nair, as her manager)',
        action: 'Open a direct report from My team or the Directory', expected: 'Status, probation end, what is next, recent changes; Message',
        callouts: [{ loc: p.getByRole('link', { name: 'Message' }), n: 1, label: 'Message' }, { loc: p.getByRole('link', { name: 'Journey', exact: true }), n: 2, label: 'Views' }] });
    await p.getByRole('link', { name: 'Records', exact: true }).filter({ visible: true }).first().click();
    await quick(p, 1500);
    await shot(s, p, 'manager/40-team-07-member-records', { ...M('Team member record (Employee 360)', { deck: [2, 8] }), screen: 'Employee 360 › Records (as manager)',
        action: 'Open Records', expected: 'The manager sees Timeline, Employment, Time, Growth and Personal; Rewards (pay) and Operations are not shown to a manager',
        callouts: [{ loc: p.getByRole('tab', { name: 'Timeline', exact: true }), n: 1, label: 'Record tabs' }] });
    await p.getByRole('tab', { name: 'Personal', exact: true }).filter({ visible: true }).first().click();
    await quick(p, 1500);
    await shot(s, p, 'manager/40-team-08-member-personal', { ...M('Team member record (Employee 360)', { deck: [2, 8] }), screen: 'Employee 360 › Records › Personal (as manager)',
        action: 'Open Personal', expected: 'What a manager may see of personal details (compare with HR’s view, which also shows bank and identity details)',
        callouts: [{ loc: p.getByRole('tab', { name: 'Personal', exact: true }), n: 1, label: 'Personal tab' }] });
});

await sec('restricted', async () => {
    const p = await amit('/admin/employees/8');
    await shot(s, p, 'manager/40-team-09-outside-team', { ...M('Restricted access', { deck: [2, 7, 8] }), screen: 'Employee 360 of someone outside the team',
        action: 'Open the address of an employee outside the manager’s scope (Neha Kapoor, HR)', expected: 'HTTP 404 "We could not find that": records outside the scope are not revealed',
        callouts: [{ loc: p.getByText('We could not find that'), n: 1, label: 'Not found' }] });
});

await sec('calendar', async () => {
    const p = await amit('/admin/leave-calendar');
    await shot(s, p, 'manager/40-team-10-leave-calendar', { ...M('Team leave and attendance'), screen: 'People › Time & leave › Leave calendar',
        action: 'Open the Leave calendar', expected: 'Who in the team is away and when (approved and pending); reasons are never shown here',
        callouts: [{ loc: p.locator('tbody tr', { hasText: 'Priya Nair' }), n: 1, label: 'Team leave' }] });
});

await sec('goals', async () => {
    const p = await amit('/admin/goals');
    await shot(s, p, 'manager/40-team-11-team-goals', { ...M('Goals, check-ins, one-on-ones and feedback'), screen: 'People › Growth › Goals (manager)',
        action: 'Open Goals', expected: 'The team’s goals and their progress, linked to the department goal',
        callouts: [{ loc: p.locator('tbody tr', { hasText: 'Raise test coverage' }), n: 1, label: 'Team member goal' }] });
    await nav(p, '/admin/appraisals');
    await shot(s, p, 'manager/40-team-12-team-appraisals', { ...M('Goals, check-ins, one-on-ones and feedback'), screen: 'People › Growth › Appraisals (manager)',
        action: 'Open Appraisals', expected: 'Team appraisals with their stage; the manager review opens once the self review is in',
        callouts: [{ loc: p.locator('tbody tr', { hasText: 'Priya Nair' }), n: 1, label: 'Priya’s appraisal' }] });
});

await sec('review', async () => {
    const p = await amit('/admin');
    await p.setViewportSize({ width: 1440, height: 1600 });
    await nav(p, '/admin/appraisals/4');
    await shot(s, p, 'manager/40-team-23-appraisal-manager-view', { ...M('Goals, check-ins, one-on-ones and feedback'), screen: 'Appraisals › Priya Nair (as manager)',
        action: 'Open the team member’s appraisal after the self review is submitted', expected: 'Self review submitted; Manager review and Add reviewer (peer) are available',
        callouts: [{ loc: p.getByRole('button', { name: 'Manager review' }), n: 1, label: 'Manager review' }, { loc: p.getByRole('button', { name: 'Add reviewer' }), n: 2, label: 'Add reviewer' },
            { loc: p.locator('tr', { hasText: 'Self' }), n: 3, label: 'Self review (submitted)' }] });
    if (!await p.getByRole('button', { name: 'Manager review' }).isVisible().catch(() => false)) return;
    await p.getByRole('button', { name: 'Manager review' }).filter({ visible: true }).first().click();
    await quick(p, 1500);
    const m = modal(p);
    for (const sel of await m.locator('select').all()) await sel.selectOption({ index: 4 }).catch(() => {});
    for (const id of ['strengths', 'improvements', 'comments']) await m.locator(`[id="mountedActionSchema0.${id}"]`).fill('Consistent delivery; next step: lead a module end to end (training example)').catch(() => {});
    await quick(p, 800);
    await shot(s, p, 'manager/40-team-24-manager-review-form', { ...M('Goals, check-ins, one-on-ones and feedback'), screen: 'Appraisal › Manager review (dialog)',
        action: 'Rate each goal and competency, choose the overall rating, add comments', expected: 'The form opens while the cycle is still in the Self review stage',
        note: 'The Manager review button is offered before the stage opens; submitting is refused (next screenshot).',
        element: '.fi-modal-window:visible >> nth=-1',
        callouts: [{ loc: m.getByText(/^\s*GOALS\s*$/i), n: 1, label: 'Goals' }, { loc: m.locator('[id="mountedActionSchema0.overall"]'), n: 2, label: 'Overall' },
            { loc: m.getByRole('button', { name: 'Submit', exact: true }), n: 3, label: 'Submit' }] });
    await m.getByRole('button', { name: 'Submit', exact: true }).click();
    await quick(p, 1500);
    console.log('    toast:', (await toasts(p)).join(' / '));
    await shot(s, p, 'manager/40-team-25-manager-review-stage-closed', { ...M('Goals, check-ins, one-on-ones and feedback', { deck: [2, 7] }), screen: 'Appraisal (manager review refused)',
        status: 'AVAILABLE ONLY AFTER CONFIGURATION', action: 'Submit the manager review while the cycle is in the Self review stage',
        expected: 'Refused: "Not allowed — The Manager review stage has not opened yet." HR advances the cycle stage first (stages do not advance by date, F21)',
        prereq: 'Performance cycle advanced to the Manager review stage by HR',
        callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Stage not open' }, { loc: p.getByRole('button', { name: 'Manager review' }), n: 2, label: 'Manager review' }] });
    await p.setViewportSize({ width: 1440, height: 900 });
});

await sec('oneonone', async () => {
    const p = await amit('/admin/one-on-ones');
    await p.getByRole('button', { name: 'Schedule' }).filter({ visible: true }).first().click();
    await quick(p, 1500);
    await pick(p, 0, 'Priya');
    await setd(p, 'scheduled_at', '2026-10-28 15:00:00');
    await byId(p, 'agenda').fill('Probation review preparation and Q3 goals (training example)');
    await quick(p, 800);
    await shot(s, p, 'manager/40-team-13-schedule-one-on-one', { ...M('Goals, check-ins, one-on-ones and feedback'), screen: 'One-on-ones › Schedule (dialog)',
        action: 'Choose the team member, date and time, agenda', expected: 'Create schedules the meeting; notes and action items are added on the meeting',
        element: '.fi-modal-window:visible >> nth=-1',
        callouts: [{ loc: byId(p, 'scheduled_at'), n: 1, label: 'When' }, { loc: byId(p, 'agenda'), n: 2, label: 'Agenda' }, { loc: modal(p).locator('.fi-modal-footer').getByRole('button', { name: 'Create', exact: true }), n: 3, label: 'Create' }] });
    await modal(p).getByRole('button', { name: 'Create', exact: true }).last().click();
    await quick(p, 1500);
    console.log('    toast:', (await toasts(p)).join(' / '));
    await shot(s, p, 'manager/40-team-14-one-on-one-scheduled', { ...M('Goals, check-ins, one-on-ones and feedback'), screen: 'People › Growth › One-on-ones',
        action: 'Create', expected: 'The meeting is listed with its date and status',
        callouts: [{ loc: p.locator('tbody tr', { hasText: 'Priya' }), n: 1, label: 'Scheduled meeting' }] });
});

await sec('feedback', async () => {
    const p = await amit('/admin/feedback-entries');
    await p.getByRole('button', { name: 'Give feedback' }).filter({ visible: true }).first().click();
    await quick(p, 1500);
    await pick(p, 0, 'Priya');
    await byId(p, 'type').selectOption({ label: 'Praise' });
    await byId(p, 'visibility').selectOption({ label: 'Recipient and their manager' });
    await byId(p, 'competency_id').selectOption({ label: 'Ownership & accountability' }).catch(() => {});
    await byId(p, 'message').fill('Great ownership of the test automation plan this sprint (training example).');
    await quick(p, 800);
    await shot(s, p, 'manager/40-team-15-give-feedback', { ...M('Goals, check-ins, one-on-ones and feedback'), screen: 'Feedback › Give feedback (dialog)',
        action: 'Choose the person, type, visibility, an optional goal or competency, and write the message', expected: 'Submit records continuous feedback',
        element: '.fi-modal-window:visible >> nth=-1',
        callouts: [{ loc: byId(p, 'type'), n: 1, label: 'Type' }, { loc: byId(p, 'visibility'), n: 2, label: 'Visibility' }, { loc: byId(p, 'message'), n: 3, label: 'Message' },
            { loc: modal(p).getByRole('button', { name: 'Submit', exact: true }), n: 4, label: 'Submit' }] });
    await modal(p).getByRole('button', { name: 'Submit', exact: true }).click();
    await quick(p, 1500);
    console.log('    toast:', (await toasts(p)).join(' / '));
    await shot(s, p, 'manager/40-team-16-feedback-given', { ...M('Goals, check-ins, one-on-ones and feedback'), screen: 'People › Growth › Feedback',
        action: 'Submit', expected: 'Confirmation; the feedback is listed',
        callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Confirmation' }, { loc: p.locator('tbody tr', { hasText: 'Priya' }), n: 2, label: 'Feedback entry' }] });
});

await sec('development', async () => {
    const p = await amit('/admin/team-learning');
    await shot(s, p, 'manager/40-team-17-team-learning', { ...M('Team learning and career'), screen: 'People › My team › Team learning',
        action: 'Open Team learning', expected: 'Team learning status, requests awaiting your decision, skill gaps, development plans; Assign learning',
        callouts: [{ loc: p.getByRole('button', { name: 'Assign learning' }), n: 1, label: 'Assign learning' }, { loc: p.getByText(/^\s*TEAM\ LEARNING\ STATUS\s*\d*\s*$/i), n: 2, label: 'Status' }] });
    await nav(p, '/admin/team-career');
    await shot(s, p, 'manager/40-team-18-team-career', { ...M('Team learning and career'), screen: 'People › My team › Team career',
        action: 'Open Team career', expected: 'What each team member has chosen to share about career aspirations and development',
        callouts: [{ loc: p.getByText(/^\s*TEAM\ CAREER\ \&\ DEVELOPMENT\s*\d*\s*$/i), n: 1, label: 'Team career' }] });
});

await sec('requests', async () => {
    const p = await amit('/admin/team-requests');
    await shot(s, p, 'manager/40-team-19-team-hr-requests', { ...M('Team HR requests'), screen: 'My work › Team HR requests',
        action: 'Open Team HR requests', expected: 'Status of direct reports’ HR requests; confidential and sensitive cases are never shown',
        callouts: [{ loc: p.getByRole('heading', { name: 'Team HR requests' }), n: 1, label: 'Team HR requests' }] });
    await nav(p, '/admin/task-inbox');
    await shot(s, p, 'manager/40-team-20-task-inbox', { ...M('Notifications and follow-up'), screen: 'My work › Task inbox',
        action: 'Open Task inbox', expected: 'Workflow and onboarding tasks waiting for the manager',
        callouts: [{ loc: p.getByRole('tab', { name: 'Onboarding tasks' }).or(p.getByRole('button', { name: 'Onboarding tasks' })), n: 1, label: 'Onboarding tasks' }] });
    await p.getByRole('tab', { name: 'Onboarding tasks' }).or(p.getByRole('button', { name: 'Onboarding tasks' })).filter({ visible: true }).first().click();
    await quick(p, 1500);
    await shot(s, p, 'manager/40-team-26-onboarding-tasks', { ...M('Notifications and follow-up'), screen: 'My work › Task inbox › Onboarding tasks',
        action: 'Open the Onboarding tasks tab', expected: 'Onboarding tasks assigned to the manager for a new joiner in the team (here Tanvi Joshi, hired during this training run), with Done / Skip',
        callouts: [{ loc: p.getByRole('tab', { name: 'Onboarding tasks' }).or(p.getByRole('button', { name: 'Onboarding tasks' })), n: 1, label: 'Onboarding tasks' }, { loc: p.locator('tbody tr'), n: 2, label: 'Task' }] });
    await nav(p, '/admin/notifications');
    await shot(s, p, 'manager/40-team-21-notifications', { ...M('Notifications and follow-up'), screen: 'My work › Notifications (manager)',
        action: 'Open Notifications', expected: 'Approvals and team notifications, grouped',
        callouts: [{ loc: p.getByText(/^\s*Approvals\s*\d*\s*$/), n: 1, label: 'Approvals group' }] });
});

await sec('insights', async () => {
    const p = await amit('/admin/people-analytics');
    await shot(s, p, 'manager/40-team-22-people-analytics', { ...M('Team insights', { deck: [2, 12] }), screen: 'Insights › People analytics (manager scope)',
        action: 'Open Insights › People analytics', expected: 'Attendance and leave figures for the people in the manager’s scope only',
        callouts: [{ loc: p.getByText(/^\s*ATTENDANCE\ \(30\ DAYS\)\s*\d*\s*$/i), n: 1, label: 'Attendance' }, { loc: p.getByText(/^\s*LEAVE\s*\d*\s*$/i), n: 2, label: 'Leave' }] });
});

await finish(s);
if (failures.length) { console.log('FAILURES:\n' + failures.join('\n')); process.exitCode = 1; }
