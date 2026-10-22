// Deck 1 — Employee self-service (Priya Nair; Rahul Sharma for a first sign-in). Disposable training database only.
// Sections can be run on their own: node capture/30-employee.mjs signin home …
import { start, context, BASE, shot, finish, settle, modal, setData, dismissWelcome, toasts, cancelModal, PERSONAS } from './lib.mjs';


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
/** Open own record › Records › <tab> in a tall viewport (no scrolling: scrolling the Employment group triggers a server error, see support/30-self-90). */
async function recordTab(p, tab) {
    let failed = 0;
    const onResp = (r) => { if (r.status() >= 500) failed++; };
    p.on('response', onResp);
    await p.setViewportSize({ width: 1440, height: 1500 });
    await nav(p, '/admin/employees/4#records');
    await p.getByRole('tab', { name: tab, exact: true }).filter({ visible: true }).first().click();
    await quick(p, 3000);
    p.off('response', onResp);
    if (failed) console.log(`    ! ${tab}: ${failed} server error(s) while loading`);
}
async function openq(s, persona, path, opts = {}) {
    const ctx = await context(s, persona, opts);
    const page = await ctx.newPage();
    page.__persona = persona;
    page.on('pageerror', (e) => { (page.__errors ??= []).push(String(e).slice(0, 300)); });
    await nav(page, path);
    return page;
}

const s = await start('30-employee');
const want = process.argv.slice(2);
const failures = [];
async function sec(name, fn) {
    if (want.length && !want.includes(name)) return;
    try { await fn(); } catch (e) { failures.push(`${name}: ${String(e).split('\n')[0].slice(0, 200)}`); console.log(`  ✗ ${name}: ${String(e).split('\n')[0].slice(0, 200)}`); }
}
const byId = (page, id) => modal(page).locator(`[id="mountedActionSchema0.${id}"]`);
const E = (workflow, extra = {}) => ({ deck: [1], workflow, ...extra });
let emp;
const priya = async (path) => { if (!emp) emp = await openq(s, 'employee', path); else await nav(emp, path); await closeT(emp); return emp; };

await sec('signin', async () => {
    const g = await openq(s, 'guest', '/admin/login');
    await shot(s, g, 'employee/30-self-01-sign-in', { ...E('Sign in', { deck: [1, 2, 7] }), screen: 'Sign in (/admin/login)',
        action: 'Open the PeopleOS address and sign in with your work e-mail and password', expected: 'E-mail, Password, Remember me, Forgot password?, Sign in',
        callouts: [{ sel: 'input[type=email]', n: 1, label: 'Work e-mail' }, { sel: 'input[type=password]', n: 2, label: 'Password' },
            { loc: g.getByRole('link', { name: /Forgot password/ }), n: 3, label: 'Forgot password?' }, { sel: 'button[type=submit]', n: 4, label: 'Sign in' }] });
    await g.fill('input[type=email]', PERSONAS.employee.email);
    await g.fill('input[type=password]', 'not-the-right-password');
    await g.click('button[type=submit]');
    await quick(g, 1500);
    await shot(s, g, 'employee/30-self-02-sign-in-failed', { ...E('Sign in', { deck: [1, 7] }), screen: 'Sign in (failed attempt)',
        action: 'Sign in with a wrong password', expected: 'A generic message under the e-mail field; the same message is used for unknown, suspended or wrong credentials',
        callouts: [{ loc: g.locator('.fi-fo-field-wrp-error-message, [data-validation-error], .fi-fo-field-wrp p.text-danger-600').or(g.getByText(/do not match|credentials/i)), n: 1, label: 'Failure message' }] });
    await g.close();
});

await sec('home', async () => {
    const r = await openq(s, 'employee2', '/admin');
    await shot(s, r, 'employee/30-self-03-first-home-welcome', { ...E('Sign in and Home', { deck: [1, 9] }), screen: 'Home (first visit)', persona: 'employee2',
        action: 'First sign-in: Home explains how PeopleOS brings work to you', expected: 'Welcome note with Shortcuts and Got it; Request leave and Start something',
        callouts: [{ loc: r.getByText('Welcome to PeopleOS. Your work comes to you here.'), n: 1, label: 'Welcome note' }, { loc: r.getByRole('button', { name: 'Got it' }), n: 2, label: 'Got it' },
            { sel: '.pos-command-trigger', n: 3, label: 'Search (Ctrl K)' }, { sel: '[data-pos-action="request_leave_header"]', n: 4, label: 'Request leave' }] });
    await r.close();
    const p = await priya('/admin');
    await dismissWelcome(p);
    await shot(s, p, 'employee/30-self-04-home', { ...E('Sign in and Home', { deck: [1, 9] }), screen: 'Home',
        action: 'Read Home from top to bottom', expected: 'For you, Need attention, Your day and PeopleOS Intelligence; each item says why it is there',
        callouts: [{ loc: p.getByText(/^\s*FOR\ YOU\s*\d*\s*$/i), n: 1, label: 'For you' }, { loc: p.getByText(/^\s*NEED\ ATTENTION\s*\d*\s*$/i), n: 2, label: 'Need attention' },
            { loc: p.getByText('PeopleOS Intelligence', { exact: true }), n: 3, label: 'PeopleOS Intelligence' }, { loc: p.getByRole('button', { name: 'Start something' }), n: 4, label: 'Start something' }] });
    await p.setViewportSize({ width: 1440, height: 2000 });
    await settle(p, 600);
    await shot(s, p, 'employee/30-self-05-home-full', { ...E('Sign in and Home'), screen: 'Home (whole page)', fullPage: true,
        action: 'Scroll Home', expected: 'Your day (check in/out), My requests, What changed, Your people, Your momentum, Start something',
        callouts: [{ loc: p.getByText(/^\s*YOUR\ DAY\s*\d*\s*$/i), n: 1, label: 'Your day' }, { loc: p.getByText(/^\s*MY\ REQUESTS\s*\d*\s*$/i), n: 2, label: 'My requests' },
            { loc: p.getByText(/^\s*YOUR\ MOMENTUM\s*\d*\s*$/i), n: 3, label: 'Your momentum' }, { loc: p.getByText(/^\s*START\ SOMETHING\s*\d*\s*$/i), n: 4, label: 'Start something' }] });
    await p.setViewportSize({ width: 1440, height: 900 });
});

await sec('nav', async () => {
    const p = await priya('/admin/goals');
    await shot(s, p, 'employee/30-self-06-navigation', { ...E('Navigation'), screen: 'People › Growth › Goals (navigation shell)',
        action: 'Use the sidebar section, its sub-menu and the area bar', expected: 'Sidebar sections; sub-menu of the open section; area bar with the pages of the area and All in …',
        callouts: [{ loc: p.locator('.fi-sidebar').getByText('People', { exact: true }), n: 1, label: 'Sidebar section' }, { loc: p.locator('.fi-sidebar').getByText('Growth', { exact: true }), n: 2, label: 'Area' },
            { loc: p.getByText('People / Growth'), n: 3, label: 'Area bar' }, { loc: p.getByRole('button', { name: /All in Growth/ }), n: 4, label: 'All in Growth' }] });
});

await sec('search', async () => {
    const p = await priya('/admin');
    await p.locator('.pos-command-trigger').click();
    await quick(p, 1000);
    await p.keyboard.type('Verma', { delay: 40 });
    await quick(p, 1500);
    await shot(s, p, 'employee/30-self-07-search-person', { ...E('Search and quick launch', { deck: [1, 2] }), screen: 'Command center (Ctrl K)',
        action: 'Press Ctrl K (or click Search) and type a name', expected: 'People results with Preview and In org map; scopes Everything / Actions / People',
        callouts: [{ loc: p.getByText('Amit Verma'), n: 1, label: 'Person result' }, { loc: p.getByText('Everything', { exact: true }), n: 2, label: 'Scopes' }] });
    await p.keyboard.press('Control+a');
    await p.keyboard.type('payslip', { delay: 40 });
    await quick(p, 1500);
    await shot(s, p, 'employee/30-self-08-search-payslip', { ...E('Search and quick launch'), screen: 'Command center (Ctrl K)',
        action: 'Type what you are looking for, e.g. "payslip"', expected: 'Navigation and action results you are allowed to open',
        callouts: [{ loc: p.getByText('Payslips'), n: 1, label: 'Matching page' }] });
    await p.keyboard.press('Escape');
    await nav(p, '/admin');
    await p.locator('.pos-quick-launch').click();
    await quick(p, 1200);
    await shot(s, p, 'employee/30-self-09-quick-launch', { ...E('Search and quick launch'), screen: '+ New (Start something)',
        action: 'Click + New (or press N)', expected: 'The command center opens in Actions mode with what you can start',
        callouts: [{ loc: p.getByText('Request leave'), n: 1, label: 'Actions you can start' }] });
    await p.keyboard.press('Escape');
});

await sec('myday', async () => {
    const p = await priya('/admin/my-day');
    await shot(s, p, 'employee/30-self-10-my-day', { ...E('My day'), screen: 'My day (avatar menu › My day)',
        action: 'Open My day', expected: 'Apply leave, Regularise attendance, Ask HR, Resign; attendance today, leave balance, needs attention',
        callouts: [{ loc: p.getByRole('button', { name: 'Apply leave' }), n: 1, label: 'Apply leave' }, { loc: p.getByRole('button', { name: 'Regularise attendance' }), n: 2, label: 'Regularise attendance' },
            { loc: p.getByRole('button', { name: 'Ask HR' }), n: 3, label: 'Ask HR' }] });
});

await sec('mywork', async () => {
    const p = await priya('/admin/my-work');
    await shot(s, p, 'employee/30-self-11-my-work', { ...E('My work and notifications', { deck: [1, 2] }), screen: 'My work › Inbox',
        action: 'Open My work', expected: 'Everything, Decisions, Tasks, Follow-ups, Waiting on others, Done; Do this next',
        callouts: [{ loc: p.getByRole('button', { name: 'Everything' }), n: 1, label: 'Filters' }, { loc: p.getByRole('button', { name: /Waiting on others/ }), n: 2, label: 'Waiting on others' }] });
    await nav(p, '/admin/notifications');
    await shot(s, p, 'employee/30-self-12-notifications', { ...E('My work and notifications'), screen: 'My work › Notifications',
        action: 'Open Notifications', expected: 'Grouped notifications with Open, Mark read and Snooze; Preferences link',
        callouts: [{ loc: p.getByText(/^\s*All\s*\d*\s*$/), n: 1, label: 'Groups' }, { loc: p.getByRole('button', { name: 'Mark all read' }), n: 2, label: 'Mark all read' },
            { loc: p.getByRole('button', { name: 'Snooze' }), n: 3, label: 'Snooze' }] });
    await p.locator('.fi-topbar-database-notifications-btn').click();
    await quick(p, 1200);
    await shot(s, p, 'employee/30-self-13-bell-panel', { ...E('My work and notifications'), screen: 'Notification bell (top bar)',
        action: 'Click the bell', expected: 'Unread notifications in a side panel',
        callouts: [{ sel: '.fi-topbar-database-notifications-btn', n: 1, label: 'Bell' }] });
    await p.keyboard.press('Escape');
});

await sec('profile', async () => {
    const p = await priya('/admin/employees/4');
    await shot(s, p, 'employee/30-self-14-my-record-now', { ...E('My profile (own record)', { deck: [1, 9] }), screen: 'Your record (own Employee 360) › Now',
        action: 'Avatar menu › My profile (or My HR › Your record)', expected: 'Read-only record: status, what is next, recent changes; no action menus',
        callouts: [{ loc: p.getByText(/^\s*YOUR\ RECORD\s*\d*\s*$/i), n: 1, label: 'Your record' }, { loc: p.getByRole('link', { name: 'Journey', exact: true }), n: 2, label: 'Views' },
            { loc: p.getByText('Reports to', { exact: false }), n: 3, label: 'Reports to' }] });
    await p.getByRole('link', { name: 'Work', exact: true }).filter({ visible: true }).first().click();
    await quick(p, 1500);
    await shot(s, p, 'employee/30-self-15-my-record-work', { ...E('My profile (own record)'), screen: 'Your record › Work',
        action: 'Open the Work view', expected: 'Position, organisation and working relationships',
        callouts: [{ loc: p.getByRole('link', { name: 'Work', exact: true }), n: 1, label: 'Work view' }] });
    await recordTab(p, 'Timeline');
    await shot(s, p, 'employee/30-self-16-my-record-timeline', { ...E('My profile (own record)'), screen: 'Your record › Records › Timeline',
        action: 'Records › Timeline', expected: 'Every recorded change to your record with its effective date — the same history HR sees, read only',
        note: 'Records › Employment fails with a server error once its lazy tables load (see support/30-self-90-record-tab-500).',
        callouts: [{ loc: p.getByRole('tab', { name: 'Timeline', exact: true }), n: 1, label: 'Timeline tab' }] });
    await recordTab(p, 'Personal');
    await shot(s, p, 'employee/30-self-17-my-record-personal', { ...E('My profile (own record)'), screen: 'Your record › Records › Personal',
        action: 'Records › Personal', expected: 'Personal details; changes are requested from HR (employees do not edit their own record)',
        callouts: [{ loc: p.getByRole('tab', { name: 'Personal', exact: true }), n: 1, label: 'Personal tab' }] });
    await p.setViewportSize({ width: 1440, height: 900 });
    await nav(p, '/admin/employees/4');
    await p.getByRole('link', { name: 'Documents', exact: true }).filter({ visible: true }).first().click();
    await quick(p, 1500);
    await shot(s, p, 'employee/30-self-18-my-documents', { ...E('Documents and letters'), screen: 'Your record › Documents',
        action: 'Open the Documents view', expected: 'Your documents with status and expiry (employees cannot upload their own documents; HR does)',
        callouts: [{ loc: p.getByRole('link', { name: 'Documents', exact: true }), n: 1, label: 'Documents view' }] });
    await nav(p, '/admin/letters');
    await shot(s, p, 'employee/30-self-19-my-letters', { ...E('Documents and letters'), screen: 'You › My letters',
        action: 'Open My letters', expected: 'Letters HR has issued to you, with status and issue date; open one to read or download it',
        callouts: [{ loc: p.getByRole('link', { name: 'My letters' }), n: 1, label: 'My letters' }, { loc: p.locator('tbody tr'), n: 2, label: 'Issued letter' }] });
});

await sec('pay', async () => {
    const p = await priya('/admin/payslips');
    await shot(s, p, 'employee/30-self-20-payslips-none', { ...E('Payslips and tax'), screen: 'People › Pay › Payslips', status: 'AVAILABLE ONLY AFTER CONFIGURATION',
        action: 'Open Payslips', expected: '"No payslips yet": payslips are generated only when a payroll run is finalised, and finalisation is blocked until the statutory rules are verified',
        prereq: 'A finalised payroll run (blocked: 0 of 24 statutory rules verified)', callouts: [{ loc: p.getByText('No payslips yet'), n: 1, label: 'Empty state' }] });
    await nav(p, '/admin/tax-declarations');
    await p.getByRole('button', { name: 'New employee tax declaration' }).filter({ visible: true }).first().click();
    await quick(p, 1500);
    await byId(p, 'financial_year').selectOption({ label: '2026-27' }).catch(async () => byId(p, 'financial_year').selectOption({ index: 1 }));
    await quick(p, 500);
    await byId(p, 'regime').selectOption({ index: 1 }).catch(() => {});
    await quick(p, 500);
    await byId(p, 'previous_employer_income').fill('0');
    await byId(p, 'previous_employer_tds').fill('0');
    await quick(p, 800);
    await shot(s, p, 'employee/30-self-21-tax-declaration-form', { ...E('Payslips and tax'), screen: 'Tax declarations › New employee tax declaration',
        action: 'Choose the financial year and regime; add previous-employer figures and declared amounts', expected: 'The declaration is saved as a draft',
        note: 'F13: there is no employee "submit" step; payroll staff change the status.', element: '.fi-modal-window:visible >> nth=-1',
        callouts: [{ loc: byId(p, 'financial_year'), n: 1, label: 'Financial year' }, { loc: byId(p, 'regime'), n: 2, label: 'Regime' },
            { loc: modal(p).locator('.fi-modal-footer').getByRole('button', { name: 'Create', exact: true }), n: 3, label: 'Create' }] });
    await modal(p).getByRole('button', { name: 'Create', exact: true }).last().click();
    await quick(p, 1500);
    console.log('    toast:', (await toasts(p)).join(' / '));
    await shot(s, p, 'employee/30-self-22-tax-declaration-saved', { ...E('Payslips and tax'), screen: 'Tax declarations (own)', status: 'INCOMPLETE',
        action: 'Create', expected: 'The declaration is listed with status Draft; there is no Submit action for the employee (F13)',
        callouts: [{ loc: p.locator('tbody tr'), n: 1, label: 'Draft declaration' }] });
    await nav(p, '/admin/my-compensation');
    await shot(s, p, 'employee/30-self-23-my-compensation', { ...E('My compensation'), screen: 'You › My compensation',
        action: 'Avatar menu › My compensation', expected: 'Pay in force today and the history of approved changes (fictional figures); viewing is audited',
        callouts: [{ loc: p.getByText(/^\s*IN\ FORCE\ TODAY\s*\d*\s*$/i), n: 1, label: 'In force today' }, { loc: p.getByText(/^\s*HISTORY\ AND\ SCHEDULED\ CHANGES\s*\d*\s*$/i), n: 2, label: 'History' }] });
});

await sec('goals', async () => {
    const p = await priya('/admin/goals');
    await shot(s, p, 'employee/30-self-24-goals', { ...E('Goals and performance', { deck: [1, 2] }), screen: 'People › Growth › Goals',
        action: 'Open Goals', expected: 'Your goals with weight, progress and the parent goal they support; Check in on each goal',
        callouts: [{ loc: p.locator('tr', { hasText: 'Raise test coverage' }), n: 1, label: 'Goal linked to a team goal' }, { loc: p.locator('tr', { hasText: 'Raise test coverage' }).getByRole('button', { name: 'Check in' }), n: 2, label: 'Check in' }] });
    await p.locator('tr', { hasText: 'Raise test coverage' }).getByRole('button', { name: 'Check in' }).click();
    await quick(p, 1500);
    await modal(p).locator('select').first().selectOption({ index: 1 });   // the key result, not "The goal itself"
    await quick(p, 900);
    await byId(p, 'value').fill('68');
    const conf = byId(p, 'confidence');
    const onTrack = (await conf.locator('option').allInnerTexts()).find((t) => /on track/i.test(t));
    if (onTrack) await conf.selectOption({ label: onTrack.trim() });
    await byId(p, 'note').fill('Added integration tests for the billing module (training example)');
    await quick(p, 800);
    await shot(s, p, 'employee/30-self-25-goal-check-in', { ...E('Goals and performance'), screen: 'Goals › Check in (dialog)',
        action: 'Choose the key result, enter its current value, your confidence and a note', expected: 'Submit records the check-in and recalculates progress from the key result (40 → 80; 68 = 70 %)',
        note: 'A check-in on "The goal itself" is recorded but does not change the Progress column of a goal that has key results (observed).',
        element: '.fi-modal-window:visible >> nth=-1',
        callouts: [{ loc: modal(p).locator('select').first(), n: 1, label: 'Key result' }, { loc: byId(p, 'value'), n: 2, label: 'Current value' }, { loc: byId(p, 'confidence'), n: 3, label: 'Confidence' }, { loc: byId(p, 'note'), n: 4, label: 'Note' }] });
    await modal(p).getByRole('button', { name: 'Submit', exact: true }).click();
    await quick(p, 1500);
    console.log('    toast:', (await toasts(p)).join(' / '));
    await shot(s, p, 'employee/30-self-26-goal-updated', { ...E('Goals and performance'), screen: 'People › Growth › Goals (after check-in)',
        action: 'Submit', expected: 'Confirmation; the goal shows the new progress',
        callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Confirmation' }, { loc: p.locator('tr', { hasText: 'Raise test coverage' }), n: 2, label: 'Updated progress' }] });
});

await sec('appraisal', async () => {
    const p = await priya('/admin/appraisals/4');
    await shot(s, p, 'employee/30-self-27-appraisal', { ...E('Goals and performance'), screen: 'People › Growth › Appraisals › View',
        action: 'Open your appraisal for FY 2026-27', expected: 'Cycle stage Self review; reviews pending; Self review button',
        callouts: [{ loc: p.getByText('Cycle stage'), n: 1, label: 'Cycle stage' }, { loc: p.getByRole('button', { name: 'Self review' }), n: 2, label: 'Self review' }] });
    if (!await p.getByRole('button', { name: 'Self review' }).isVisible().catch(() => false)) return;
    await p.getByRole('button', { name: 'Self review' }).filter({ visible: true }).first().click();
    await quick(p, 1500);
    const m = modal(p);
    for (const sel of await m.locator('select').all()) await sel.selectOption({ index: 4 }).catch(() => {});
    await m.locator('[id="mountedActionSchema0.strengths"]').fill('Delivered the test automation plan ahead of schedule (training example)');
    await m.locator('[id="mountedActionSchema0.improvements"]').fill('Share knowledge more often with the Mobile team (training example)');
    await quick(p, 800);
    await shot(s, p, 'employee/30-self-28-self-review-form', { ...E('Goals and performance'), screen: 'Appraisal › Self review (dialog)',
        action: 'Rate each goal and competency, choose an overall rating, add strengths and improvements', expected: 'Submit sends the self review; the manager review follows',
        element: '.fi-modal-window:visible >> nth=-1',
        callouts: [{ loc: m.getByText(/^\s*GOALS\s*\d*\s*$/i), n: 1, label: 'Goals' }, { loc: m.getByText(/^\s*COMPETENCIES\s*\d*\s*$/i), n: 2, label: 'Competencies' },
            { loc: m.locator('[id="mountedActionSchema0.overall"]'), n: 3, label: 'Overall' }, { loc: m.getByRole('button', { name: 'Submit', exact: true }), n: 4, label: 'Submit' }] });
    await m.getByRole('button', { name: 'Submit', exact: true }).click();
    await quick(p, 1500);
    console.log('    toast:', (await toasts(p)).join(' / '));
});

await sec('appraisal-after', async () => {
    const p = await priya('/admin');
    await p.setViewportSize({ width: 1440, height: 1350 });
    await nav(p, '/admin/appraisals/4');
    await shot(s, p, 'employee/30-self-29-self-review-submitted', { ...E('Goals and performance', { deck: [1, 2] }), screen: 'Appraisal (after the self review)',
        action: 'Submit the self review (confirmation "Review submitted"), then open the appraisal again', expected: 'Reviews: Self — submitted, overall 4.00, with the submission time. The employee sees only their own review row (the manager review is not listed for the employee)',
        callouts: [{ loc: p.locator('tr', { hasText: 'Self' }), n: 1, label: 'Self review submitted' }] });
    console.log('    reviews rows:', (await p.locator('tbody tr').allInnerTexts()).map((t) => t.replace(/\s+/g, ' ')).join(' | '));
    await p.setViewportSize({ width: 1440, height: 900 });
});

await sec('career', async () => {
    const p = await priya('/admin/career-passport-page');
    await shot(s, p, 'employee/30-self-30-career-passport', { ...E('Career'), screen: 'People › Growth › Career passport',
        action: 'Open Career passport', expected: 'Skills, certifications, performance history, experience, aspirations and next step',
        callouts: [{ loc: p.getByText(/^\s*SKILLS\s*\d*\s*$/i), n: 1, label: 'Skills' }, { loc: p.getByText(/^\s*CAREER\ PATH\ \&\ NEXT\ STEP\s*\d*\s*$/i), n: 2, label: 'Career path' }] });
    await nav(p, '/admin/my-career');
    await shot(s, p, 'employee/30-self-31-my-career', { ...E('Career'), screen: 'You › My career',
        action: 'Avatar menu › My career', expected: 'Career profile and sharing, aspirations, career goals, mobility interest',
        callouts: [{ loc: p.getByRole('button', { name: 'Edit profile & sharing' }), n: 1, label: 'Profile & sharing' }, { loc: p.getByRole('button', { name: 'Record aspiration' }), n: 2, label: 'Record aspiration' }] });
});

await sec('learning', async () => {
    const p = await priya('/admin/my-learning');
    await shot(s, p, 'employee/30-self-32-my-learning', { ...E('Learning'), screen: 'You › My learning',
        action: 'Avatar menu › My learning', expected: 'Assigned learning, certificates, skills, assessments and development plans; Request learning',
        callouts: [{ loc: p.getByText('POSH awareness'), n: 1, label: 'Assigned course' }, { loc: p.getByRole('button', { name: 'Request learning' }), n: 2, label: 'Request learning' }] });
    await nav(p, '/admin/learning-enrolments/4');
    await shot(s, p, 'employee/30-self-33-enrolment', { ...E('Learning'), screen: 'My learning › enrolment',
        action: 'Open the assigned course', expected: 'Status Assigned, due date, modules; Start',
        callouts: [{ loc: p.getByRole('button', { name: 'Start', exact: true }), n: 1, label: 'Start' }, { loc: p.getByText(/^\s*MODULES\s*\d*\s*$/i), n: 2, label: 'Modules' }] });
    if (await p.getByRole('button', { name: 'Start', exact: true }).isVisible().catch(() => false)) {
        await p.getByRole('button', { name: 'Start', exact: true }).click();
        await quick(p, 1200);
        if (await modal(p).isVisible().catch(() => false)) { await modal(p).getByRole('button', { name: /Confirm|Start|Submit/ }).last().click(); await quick(p, 1500); }
        console.log('    toast:', (await toasts(p)).join(' / '));
        await shot(s, p, 'employee/30-self-34-enrolment-started', { ...E('Learning'), screen: 'My learning › enrolment (started)',
            action: 'Start', expected: 'Status In progress; Mark module complete and Update progress',
            callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Confirmation' }, { loc: p.getByRole('button', { name: 'Mark module complete' }), n: 2, label: 'Mark module complete' }] });
    }
});

await sec('myhr', async () => {
    const p = await priya('/admin/my-hr');
    await shot(s, p, 'employee/30-self-35-my-hr', { ...E('HR requests and knowledge', { deck: [1, 9] }), screen: 'Services › My HR',
        action: 'Open Services (My HR)', expected: 'Ask HR, Give feedback, Your record; tabs Requests, Tasks, Documents, Policies, Services, Surveys…; Needs you',
        callouts: [{ loc: p.getByRole('button', { name: 'Ask HR' }), n: 1, label: 'Ask HR' }, { loc: p.getByRole('tab', { name: 'Overview' }), n: 2, label: 'Tabs' },
            { loc: p.getByText(/^\s*NEEDS\ YOU\s*\d*\s*$/i), n: 3, label: 'Needs you' }] });
    await p.getByRole('button', { name: 'Ask HR' }).filter({ visible: true }).first().click();
    await quick(p, 1500);
    await byId(p, 'ticket_category_id').selectOption({ label: 'Letters & certificates' });
    await byId(p, 'subject').fill('Address proof letter for my bank (training example)');
    await byId(p, 'description').fill('Please issue an address proof letter for opening a salary account (training example).');
    await byId(p, 'priority').selectOption({ label: 'Normal' });
    await quick(p, 800);
    await shot(s, p, 'employee/30-self-36-ask-hr-form', { ...E('HR requests and knowledge'), screen: 'My HR › Ask HR (dialog)',
        action: 'Choose a category, write a subject and description, set the priority', expected: 'Submit raises a numbered request with an SLA',
        element: '.fi-modal-window:visible >> nth=-1',
        callouts: [{ loc: byId(p, 'ticket_category_id'), n: 1, label: 'Category' }, { loc: byId(p, 'subject'), n: 2, label: 'Subject' }, { loc: byId(p, 'description'), n: 3, label: 'Description' },
            { loc: byId(p, 'priority'), n: 4, label: 'Priority' }, { loc: modal(p).getByRole('button', { name: 'Submit', exact: true }), n: 5, label: 'Submit' }] });
    await modal(p).getByRole('button', { name: 'Submit', exact: true }).click();
    await quick(p, 1500);
    console.log('    toast:', (await toasts(p)).join(' / '));
    await shot(s, p, 'employee/30-self-37-ask-hr-submitted', { ...E('HR requests and knowledge'), screen: 'My HR (after submitting)',
        action: 'Submit', expected: 'Confirmation with the request number', callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Confirmation' }] });
});

await sec('myrequests', async () => {
    const p = await priya('/admin/tickets');
    await shot(s, p, 'employee/30-self-38-my-requests', { ...E('HR requests and knowledge'), screen: 'You › My requests',
        action: 'Open My requests', expected: 'The request with its number, service, priority, status Submitted and SLA due date',
        callouts: [{ loc: p.locator('tbody tr', { hasText: 'TKT-' }), n: 1, label: 'Your request' }, { loc: p.getByText('Submitted', { exact: true }), n: 2, label: 'Status' }] });
    await p.locator('tbody tr', { hasText: 'TKT-' }).getByRole('link', { name: 'Open' }).or(p.locator('tbody tr', { hasText: 'TKT-' }).getByRole('button', { name: 'Open' })).first().click();
    await quick(p, 1500);
    await shot(s, p, 'employee/30-self-39-request-detail', { ...E('HR requests and knowledge'), screen: 'My requests › request',
        action: 'Open the request', expected: 'Status, SLA and the conversation with HR',
        callouts: [{ loc: p.getByText('Address proof letter for my bank (training example)'), n: 1, label: 'Request' }] });
});

await sec('kb', async () => {
    const p = await priya('/admin/articles');
    await shot(s, p, 'employee/30-self-40-knowledge-base', { ...E('HR requests and knowledge'), screen: 'You › Knowledge base',
        action: 'Open the Knowledge base', expected: 'Published HR articles; those that need acknowledgement are marked',
        callouts: [{ loc: p.locator('tbody tr', { hasText: 'Leave policy' }), n: 1, label: 'Article' }] });
    await nav(p, '/admin/articles/1');
    await shot(s, p, 'employee/30-self-41-policy-acknowledge', { ...E('Policies and announcements'), screen: 'Knowledge base › Leave policy',
        action: 'Read the policy', expected: '"I have read and understood" records your acknowledgement of this version',
        callouts: [{ loc: p.getByRole('button', { name: 'I have read and understood' }), n: 1, label: 'Acknowledge' }] });
    if (await p.getByRole('button', { name: 'I have read and understood' }).isVisible().catch(() => false)) {
        await p.getByRole('button', { name: 'I have read and understood' }).click();
        await quick(p, 1200);
        if (await modal(p).isVisible().catch(() => false)) { await modal(p).getByRole('button', { name: /Confirm|Submit|acknowledge/i }).last().click(); await quick(p, 1500); }
        console.log('    toast:', (await toasts(p)).join(' / '));
        await shot(s, p, 'employee/30-self-42-policy-acknowledged', { ...E('Policies and announcements'), screen: 'Knowledge base › Leave policy (acknowledged)',
            action: 'I have read and understood', expected: 'Confirmation; the policy leaves "Policies to acknowledge"',
            callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Confirmation' }] });
    }
});

await sec('surveys', async () => {
    const p = await priya('/admin/my-hr');
    await p.getByRole('tab', { name: 'Surveys', exact: true }).filter({ visible: true }).first().click();
    await quick(p, 1500);
    await shot(s, p, 'employee/30-self-43-surveys', { ...E('Surveys and feedback'), screen: 'My HR › Surveys',
        action: 'Open the Surveys tab', expected: 'Surveys open to you (none open in the demonstration)',
        callouts: [{ loc: p.getByRole('tab', { name: 'Surveys', exact: true }), n: 1, label: 'Surveys tab' }] });
});

await sec('feedback', async () => {
    const p = await priya('/admin/my-hr');
    await p.getByRole('button', { name: 'Give feedback' }).filter({ visible: true }).first().click();
    await quick(p, 1500);
    await modal(p).locator('[id="mountedActionSchema0.mode-confidential"]').check().catch(() => {});
    await byId(p, 'category').selectOption({ label: 'Idea / suggestion' });
    await byId(p, 'body').fill('A quiet room on the Delhi floor would help focused work (training example).');
    await quick(p, 800);
    await shot(s, p, 'employee/30-self-44-give-feedback-form', { ...E('Surveys and feedback', { deck: [1, 7] }), screen: 'My HR › Give feedback (dialog)',
        action: 'Choose identified, confidential or anonymous; pick a category; write the feedback', expected: 'Submit sends it to the HR feedback inbox',
        element: '.fi-modal-window:visible >> nth=-1',
        callouts: [{ loc: modal(p).getByText('Confidential', { exact: false }), n: 1, label: 'Mode' }, { loc: byId(p, 'category'), n: 2, label: 'Category' }, { loc: byId(p, 'body'), n: 3, label: 'Feedback' }] });
    await modal(p).getByRole('button', { name: 'Submit', exact: true }).click();
    await quick(p, 2000);
    const t = (await toasts(p)).join(' / ');
    console.log('    toast:', t.replace(/\s+/g, ' ').slice(0, 300));
    await shot(s, p, 'employee/30-self-45-give-feedback-error', { ...E('Surveys and feedback', { deck: [1, 7, 11] }), screen: 'My HR › Give feedback (after Submit)', status: 'INCOMPLETE',
        action: 'Submit', expected: `Observed: ${t.replace(/\s+/g, ' ').slice(0, 160)}`,
        note: 'Known defect F17 (MySQL): the feedback is saved, but the HR notification fails and the employee sees an error.',
        callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Message shown' }] });
    await closeT(p);
});

await sec('announce', async () => {
    const p = await priya('/admin/announcements-feed');
    await shot(s, p, 'employee/30-self-46-announcements', { ...E('Policies and announcements', { deck: [1, 9] }), screen: 'Communication › Announcements',
        action: 'Open Communication', expected: 'Announcements; some ask you to acknowledge',
        callouts: [{ loc: p.getByRole('button', { name: 'I acknowledge' }), n: 1, label: 'I acknowledge' }, { loc: p.getByRole('button', { name: 'Mark as read' }), n: 2, label: 'Mark as read' }] });
    if (await p.getByRole('button', { name: 'I acknowledge' }).first().isVisible().catch(() => false)) {
        await p.getByRole('button', { name: 'I acknowledge' }).filter({ visible: true }).first().click();
        await quick(p, 1500);
        console.log('    toast:', (await toasts(p)).join(' / '));
        await shot(s, p, 'employee/30-self-47-announcement-acknowledged', { ...E('Policies and announcements'), screen: 'Communication › Announcements (acknowledged)',
            action: 'I acknowledge', expected: 'Confirmation; the announcement shows it is acknowledged',
            callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Confirmation' }] });
    }
});

await sec('account', async () => {
    const p = await priya('/admin/profile');
    await shot(s, p, 'employee/30-self-48-account-security', { ...E('Account security and preferences', { deck: [1, 8] }), screen: 'Avatar menu › Account security',
        action: 'Open Account security', expected: 'Change password; Two-factor authentication (authenticator app) with Set up; name and e-mail are managed by HR',
        callouts: [{ sel: '[id="form.password"]', n: 1, label: 'New password' }, { loc: p.getByRole('button', { name: 'Set up' }), n: 2, label: 'Set up 2FA' }] });
    await nav(p, '/admin/preferences');
    await shot(s, p, 'employee/30-self-49-preferences', { ...E('Account security and preferences'), screen: 'Avatar menu › Preferences',
        action: 'Open Preferences', expected: 'Density, pinned people, Home and history, notification preferences',
        callouts: [{ loc: p.getByRole('button', { name: 'Compact' }), n: 1, label: 'Density' }, { loc: p.getByRole('link', { name: 'Notification preferences' }), n: 2, label: 'Notification preferences' }] });
});

await sec('mobile', async () => {
    const m = await openq(s, 'employee', '/admin', { width: 390, height: 844, mobile: true });
    await shot(s, m, 'employee/30-self-50-phone-home', { ...E('Phone layout', { deck: [1, 9] }), screen: 'Home on a phone (390 × 844)',
        action: 'Open PeopleOS in a phone browser', expected: 'The same Home, with a bottom bar: Home, Work, Actions, Services, People',
        callouts: [{ loc: m.getByText('Actions', { exact: true }), n: 1, label: 'Bottom bar' }] });
    await m.close();
    const d = await openq(s, 'employee', '/admin', { theme: 'dark' });
    await shot(s, d, 'employee/30-self-51-dark-home', { ...E('Phone layout'), screen: 'Home (dark theme)',
        action: 'Avatar menu › theme: dark', expected: 'The same screens in the dark theme', callouts: [] });
    await d.close();
});

await sec('restricted', async () => {
    const p = await priya('/admin/employees');
    await shot(s, p, 'employee/30-self-52-restricted-page', { ...E('Restricted access', { deck: [1, 7, 8] }), screen: 'People › Lifecycle › Employees (HR register) as an employee',
        action: 'Open an HR-only address directly', expected: 'HTTP 403: "This page is not available to you", with Go to Home and Go back',
        callouts: [{ loc: p.getByText('This page is not available to you'), n: 1, label: 'Not available' }, { loc: p.getByRole('link', { name: 'Go to Home' }), n: 2, label: 'Go to Home' }] });
});

await sec('resign', async () => {
    const p = await priya('/admin/exit-cases');
    await shot(s, p, 'employee/30-self-53-my-exit', { ...E('Resignation (My exit)'), screen: 'You › My exit',
        action: 'Open My exit', expected: 'Your exit case (none) and the Resign button',
        callouts: [{ loc: p.getByRole('button', { name: 'Resign' }), n: 1, label: 'Resign' }] });
    await p.getByRole('button', { name: 'Resign' }).filter({ visible: true }).first().click();
    await quick(p, 1500);
    await byId(p, 'reason').fill('Relocating to another city (training example — not submitted)');
    await quick(p, 600);
    await shot(s, p, 'employee/30-self-54-resign-form', { ...E('Resignation (My exit)'), screen: 'My exit › Resign (dialog)',
        action: 'Write the reason and, optionally, the last day you request; Confirm submits the resignation', expected: 'Form shown; in this walkthrough it is cancelled (Priya does not resign)',
        element: '.fi-modal-window:visible >> nth=-1',
        callouts: [{ loc: byId(p, 'reason'), n: 1, label: 'Reason' }, { loc: byId(p, 'requested_last_day'), n: 2, label: 'Requested last day' }, { loc: modal(p).getByRole('button', { name: 'Confirm' }), n: 3, label: 'Confirm' }] });
    await cancelModal(p);
});

await finish(s);
if (failures.length) { console.log('FAILURES:\n' + failures.join('\n')); process.exitCode = 1; }
