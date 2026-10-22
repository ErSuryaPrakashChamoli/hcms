// Growth and workforce administration (H12–H14) as the Tenant HR Admin: performance cycles, appraisals, calibration,
// goals, learning, talent, positions (a draft position is EXECUTED), headcount plans, org map, workforce dashboards.
// Disposable training database (fictional people). Decks 3, 4, 9, 12.
import { start, open, go, shot, finish, idle, modal, toasts, closeToasts, cancelModal, setData } from './lib.mjs';

const s = await start('65-growth-workforce');
const ONLY = process.env.ONLY ? process.env.ONLY.split(',') : null;
const step = async (name, fn) => { if (ONLY && !ONLY.includes(name)) return; try { await fn(); } catch (e) { console.log(`  ✗ ${name}: ${String(e).split('\n')[0].slice(0, 200)}`); } };
const G = { deck: [3], workflow: 'Performance and goals administration', prereq: 'Tenant HR Admin (performance.*); the Performance Admin role template lacks these permissions (F3)' };
const L = { deck: [3], workflow: 'Learning administration', prereq: 'Tenant HR Admin (learning.*)' };
const W = { deck: [3, 4], workflow: 'Workforce planning and positions', prereq: 'Tenant HR Admin (position.*, workforce.*)' };
const byId = (page, id) => modal(page).locator(`[id="mountedActionSchema0.${id}"]`);

const adm = await open(s, 'admin', '/admin/performance-cycles');
await step('cycles', () => shot(s, adm, 'hr-admin/65-grow-01-performance-cycles', { ...G, screen: 'People › Growth › All in Growth › Cycles', action: 'Open Performance cycles',
    expected: 'FY 2026-27 annual review, active, stage Self review; next stage action "Move to Manager review"',
    callouts: [{ loc: adm.locator('tbody tr').filter({ hasText: 'FY 2026-27' }), n: 1, label: 'Active cycle' }, { loc: adm.getByRole('button', { name: /Move to Manager review/ }), n: 2, label: 'Advance stage' }, { loc: adm.getByRole('link', { name: 'New performance cycle' }), n: 3, label: 'New performance cycle' }] }));
await step('new cycle', async () => {
    await adm.getByRole('link', { name: 'New performance cycle' }).first().click();
    await idle(adm, 1800);
    await shot(s, adm, 'hr-admin/65-grow-02-new-cycle-form', { ...G, screen: 'New performance cycle', action: 'Open the New performance cycle form (not saved)',
        expected: 'Name, code, type, period, template, rating scale, eligibility rules and stage options', fullPage: true,
        note: 'Options "Show self review to manager" and "Peer reviews anonymous by default" are stored but not applied (F20); scheduled cycles do not launch automatically (F21).' });
});
await step('appraisals', async () => {
    await go(adm, '/admin/appraisals');
    await shot(s, adm, 'hr-admin/65-grow-03-appraisals', { ...G, deck: [3, 9], screen: 'People › Growth › Appraisals', action: 'Open Appraisals',
        expected: 'One appraisal per employee in the cycle, with reviewer, stage and review progress', callouts: [{ loc: adm.locator('th').filter({ hasText: /stage|status/i }), n: 1, label: 'Stage' }] });
});
await step('calibration', async () => {
    await go(adm, '/admin/calibration-board');
    await shot(s, adm, 'hr-admin/65-grow-04-calibration-board', { ...G, deck: [3, 9], screen: 'People › Growth › Calibration', action: 'Open the Calibration board',
        expected: 'Rating distribution for the cycle; Calibrate and Finalize', callouts: [{ loc: adm.getByRole('button', { name: 'Calibrate' }), n: 1, label: 'Calibrate' }, { loc: adm.getByRole('button', { name: 'Finalize' }), n: 2, label: 'Finalize' }], fullPage: true });
});
await step('goals', async () => {
    await go(adm, '/admin/goals');
    await shot(s, adm, 'hr-admin/65-grow-05-goals-hr-view', { ...G, screen: 'People › Growth › Goals (HR view)', action: 'Open Goals',
        expected: 'Goal cascade: company → department → employee goals with weight and progress; Check in on each goal',
        callouts: [{ loc: adm.getByText('↳ Ship platform v2 by Q3', { exact: false }), n: 1, label: 'Cascaded from a department goal' }, { loc: adm.getByRole('button', { name: 'Check in' }), n: 2, label: 'Check in' }] });
});

await step('courses', async () => {
    await go(adm, '/admin/courses');
    await shot(s, adm, 'hr-admin/65-learn-01-courses', { ...L, screen: 'People › Growth › All in Growth › Courses', action: 'Open Courses',
        expected: 'Courses with format, category, duration, mandatory flag and version; Submit for approval / Retire / Versions',
        callouts: [{ loc: adm.locator('tbody tr').filter({ hasText: 'POSH' }), n: 1, label: 'Mandatory course' }, { loc: adm.getByRole('link', { name: 'New course' }), n: 2, label: 'New course' }] });
});
await step('assignments', async () => {
    await go(adm, '/admin/learning-assignments');
    await shot(s, adm, 'hr-admin/65-learn-02-assignments', { ...L, screen: 'People › Growth › All in Growth › Assignments', action: 'Open Learning assignments',
        expected: '"PoSH for everyone (annual)": audience Everyone, due 30 days, recurs every 12 months, mandatory; Apply now',
        callouts: [{ loc: adm.getByRole('button', { name: 'Assign learning' }), n: 1, label: 'Assign learning' }, { loc: adm.getByRole('button', { name: 'Apply now' }), n: 2, label: 'Apply now' }] });
});
await step('learning dashboard', async () => {
    await go(adm, '/admin/learning-dashboard');
    await shot(s, adm, 'analytics/65-learn-03-learning-dashboard', { ...L, deck: [12, 3], screen: 'People › Growth › Dashboard (Learning & development)', action: 'Open the learning dashboard',
        expected: 'Certificates, development plans, mandatory training completion, popular courses, skill gaps, by department, providers and costs', fullPage: true });
});
await step('talent dashboard', async () => {
    await go(adm, '/admin/talent-dashboard');
    await shot(s, adm, 'analytics/65-talent-01-talent-dashboard', { deck: [12, 3], workflow: 'Talent and succession', screen: 'People › Growth › Dashboard (Talent & succession)', action: 'Open the talent dashboard',
        expected: 'Positions without a ready-now successor and talent pools (empty in the demonstration)', prereq: 'Tenant HR Admin; critical positions and succession plans recorded', fullPage: true });
});

await step('positions empty', async () => {
    await go(adm, '/admin/positions');
    await shot(s, adm, 'hr-admin/65-wf-01-positions', { ...W, screen: 'Organisation › Workforce › Positions', action: 'Open Positions', expected: 'Position register; New position',
        callouts: [{ loc: adm.getByRole('button', { name: 'New position' }), n: 1, label: 'New position' }] });
});
await step('new position', async () => {
    await go(adm, '/admin/positions');
    if (await adm.locator('tbody tr').filter({ hasText: 'POS-TRN-001' }).count()) return;
    await adm.setViewportSize({ width: 1440, height: 1400 });
    await adm.getByRole('button', { name: 'New position' }).first().click();
    await idle(adm, 1500);
    await byId(adm, 'code').fill('POS-TRN-001');
    console.log('    -> title');
    await byId(adm, 'title').fill('Senior Platform Engineer (training example)');
    console.log('    -> company_id');
    await byId(adm, 'company_id').selectOption({ label: 'Demo Technologies' });
    await idle(adm, 800);
    console.log('    -> employment_type_id');
    await byId(adm, 'employment_type_id').selectOption({ label: 'Full-time' });
    console.log('    -> worker_type');
    await byId(adm, 'worker_type').selectOption({ label: 'Employee' });
    console.log('    -> grade_id');
    await byId(adm, 'grade_id').selectOption({ label: 'G4' });
    console.log('    -> occupancy_mode');
    await byId(adm, 'occupancy_mode').selectOption({ label: 'Single occupant' });
    console.log('    -> headcount');
    await byId(adm, 'headcount').fill('1');
    console.log('    -> reason');
    await byId(adm, 'reason').fill('New role in the FY27 headcount plan (training example)');
    await idle(adm, 600);
    await shot(s, adm, 'hr-admin/65-wf-02-new-position-form', { ...W, screen: 'New position (dialog)', action: 'Code, title, company, employment type, worker type, grade, occupancy, headcount, reason',
        expected: 'A position is created as a Draft; it must be proposed and approved before it can be filled', element: '.fi-modal-window:visible >> nth=-1',
        note: 'FTE left blank: the FTE field (minimum 0.01, step 0.05) refuses whole numbers such as 1 (finding F8).',
        callouts: [{ loc: byId(adm, 'code'), n: 1, label: 'Code' }, { loc: byId(adm, 'title'), n: 2, label: 'Title' }, { loc: byId(adm, 'grade_id'), n: 3, label: 'Grade' }, { loc: byId(adm, 'fte'), n: 4, label: 'FTE (leave blank, F8)' }, { loc: byId(adm, 'reason'), n: 5, label: 'Reason' }] });
    await closeToasts(adm);
    await modal(adm).getByRole('button', { name: 'Submit', exact: true }).click();
    await idle(adm, 2000);
    console.log('    toast:', (await toasts(adm)).join(' / '));
    await adm.setViewportSize({ width: 1440, height: 900 });
    if (await adm.locator('.fi-modal-window:visible').count()) {
        await shot(s, adm, 'hr-admin/65-wf-03-new-position-validation', { ...W, screen: 'New position (dialog, after Submit)', action: 'Submit', expected: 'Validation messages for missing required fields', element: '.fi-modal-window:visible >> nth=-1' });
        await cancelModal(adm);
    } else {
        await shot(s, adm, 'hr-admin/65-wf-03-position-created', { ...W, screen: 'Positions (after Submit)', action: 'Submit', expected: 'Draft position listed; lifecycle actions Propose → Approve → Open follow',
            callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Result' }, { loc: adm.locator('tbody tr').filter({ hasText: 'POS-TRN-001' }), n: 2, label: 'Draft position' }] });
    }
});
await step('headcount plans', async () => {
    await go(adm, '/admin/workforce-plans');
    await shot(s, adm, 'hr-admin/65-wf-04-headcount-plans', { ...W, screen: 'Organisation › Workforce › Headcount plans (Plan versions)', action: 'Open Headcount plans',
        expected: 'Plan versions with lines and forecast; review and approval by a second person', callouts: [{ loc: adm.getByRole('button', { name: 'New plan' }), n: 1, label: 'New plan' }] });
});
await step('org map', async () => {
    await go(adm, '/admin/organisation-map');
    await shot(s, adm, 'hr-admin/65-wf-05-org-map', { ...W, deck: [3, 4, 9], workflow: 'Organisation map', screen: 'Organisation › Org map', action: 'Open the organisation map',
        expected: 'Reporting lines from the CEO down; switch to Departments or Other relationships; zoom and expand',
        callouts: [{ loc: adm.getByRole('button', { name: 'Reporting lines' }), n: 1, label: 'Reporting lines' }, { loc: adm.getByRole('button', { name: 'Departments' }), n: 2, label: 'Departments' }, { loc: adm.getByRole('button', { name: /Meera Iyer/ }), n: 3, label: 'Top of the hierarchy' }] });
});
await step('workforce dashboard', async () => {
    await go(adm, '/admin/workforce-dashboard');
    await shot(s, adm, 'analytics/65-wf-06-workforce-dashboard', { ...W, deck: [12, 3], screen: 'Organisation › Workforce › Dashboard (Workforce overview)', action: 'Open the workforce overview',
        expected: 'Positions, vacancies, budgets and plan figures (mostly zero in the demonstration)', fullPage: true });
});
await step('position hierarchy', async () => {
    await go(adm, '/admin/position-hierarchy');
    await shot(s, adm, 'hr-admin/65-wf-07-position-hierarchy', { ...W, screen: 'Organisation › Workforce › Position hierarchy', action: 'Open Position hierarchy',
        expected: 'Positions arranged by reporting position (only the new draft position exists)' });
});

await finish(s);
