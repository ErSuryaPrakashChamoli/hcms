// Growth chapter screenshots (performance, learning, skills, career, talent, succession).
// Read-only: forms and modals are opened, never submitted. No seeded record is changed.
import { start, open, annotate, clearCallouts, shot, record, finish, settle } from './lib.mjs';

const only = process.argv.slice(2);
const want = (n) => only.length === 0 || only.some((o) => n.startsWith(o));
const s = await start('grow');
const report = (name, missing) => { if (missing.length) console.log('MISSING', name, missing); else console.log('OK', name); };
const modal = (p) => p.locator('.fi-modal-window:visible').last();
const closeModal = async (p) => { await p.keyboard.press('Escape'); await p.waitForTimeout(500); };

async function openModal(p, buttonLocator) {
    await buttonLocator.click();
    await modal(p).waitFor({ state: 'visible', timeout: 15000 });
    await p.waitForTimeout(900);
}

// 01 — Performance cycles list (admin)
if (want('grow-01')) {
    const p = await open(s, 'admin', '/admin/performance-cycles');
    const m = await annotate(p, [
        { loc: p.getByRole('button', { name: /All in Growth/ }).first(), n: 1 },
        { loc: p.getByRole('link', { name: 'New performance cycle' }).first(), n: 2 },
        { loc: p.locator('main table tbody tr').first().locator('td').nth(5), n: 3 },
        { loc: p.locator('main table tbody tr').first().getByText('Move to Manager review'), n: 4 },
    ]);
    report('grow-01', m);
    await shot(p, 'grow-01-performance-cycles-list');
    record(s, { name: 'grow-01-performance-cycles-list', persona: 'admin', path: '/admin/performance-cycles', caption: 'Performance cycles list with the active FY 2026-27 cycle at the Self review stage' });
    await p.close();
}

// 02 — New performance cycle form (admin), filled with fictional values, never saved
if (want('grow-02')) {
    const p = await open(s, 'admin', '/admin/performance-cycles/create');
    await p.setViewportSize({ width: 1440, height: 2300 });
    await p.getByLabel('Name').first().fill('FY 2027-28 annual review (example)');
    await p.getByLabel('Code').first().fill('FY28-EXAMPLE');
    await p.locator('body').click({ position: { x: 5, y: 300 } }).catch(() => {});
    await p.waitForTimeout(800);
    const lab = (t) => p.locator('form label').filter({ hasText: t }).first();
    const m = await annotate(p, [
        { loc: lab(/^\s*Template version/), n: 1 },
        { loc: lab(/^\s*Rating scale/), n: 2 },
        { loc: lab(/^\s*Goals weight/), n: 3 },
        { loc: p.locator('form').getByText('Stages', { exact: true }).first(), n: 4 },
        { loc: p.locator('form').getByText('Allow reviewers to resubmit').first(), n: 5 },
        { loc: p.locator('form').getByText('Eligibility', { exact: true }).first(), n: 6 },
        { loc: p.locator('.pos-form-review').first(), n: 7 },
    ]);
    report('grow-02', m);
    await shot(p, 'grow-02-new-cycle-form');
    record(s, { name: 'grow-02-new-cycle-form', persona: 'admin', path: '/admin/performance-cycles/create', caption: 'New performance cycle form (fictional values, not saved)' });
    await p.close();
}

// 03 — Publish a template version (admin), modal only
if (want('grow-03')) {
    const p = await open(s, 'admin', '/admin/performance-templates');
    await openModal(p, p.locator('main table tbody tr').first().getByText('Publish version'));
    const w = modal(p);
    const m = await annotate(p, [
        { loc: w.locator('label').filter({ hasText: /^\s*Rating scale/ }).first(), n: 1 },
        { loc: w.getByText('Workflow stages (in this order)').first(), n: 2 },
        { loc: w.getByText('Required goal weight total').first(), n: 3 },
    ]);
    report('grow-03', m);
    await p.setViewportSize({ width: 1440, height: 1500 });
    await p.waitForTimeout(600);
    await annotate(p, [
        { loc: w.locator('label').filter({ hasText: /^\s*Rating scale/ }).first(), n: 1 },
        { loc: w.getByText('Workflow stages (in this order)').first(), n: 2 },
        { loc: w.getByText('Required goal weight total').first(), n: 3 },
    ]);
    await shot(p, 'grow-03-publish-template-version', { element: w });
    record(s, { name: 'grow-03-publish-template-version', persona: 'admin', path: '/admin/performance-templates', caption: 'Publish version window of a performance template (not submitted)' });
    await closeModal(p);
    await p.close();
}

// 04 — Priya's appraisal (employee)
if (want('grow-04')) {
    const p = await open(s, 'employee', '/admin/appraisals/4');
    const m = await annotate(p, [
        { loc: p.getByRole('button', { name: 'Self review' }).first(), n: 1 },
        { loc: p.locator('main').getByText('In review', { exact: true }).first(), n: 2 },
        { loc: p.locator('main').getByText('Final rating', { exact: true }).first(), n: 3 },
        { loc: p.locator('main').getByText('Reviews', { exact: true }).first(), n: 4 },
    ]);
    report('grow-04', m);
    await shot(p, 'grow-04-appraisal-employee-view');
    record(s, { name: 'grow-04-appraisal-employee-view', persona: 'employee', path: '/admin/appraisals/4', caption: "Priya Nair's appraisal in the FY 2026-27 cycle, with the Self review button" });
    await p.close();
}

// 05 — Self review form (employee), not submitted
if (want('grow-05')) {
    const p = await open(s, 'employee', '/admin/appraisals/4');
    await p.setViewportSize({ width: 1440, height: 1800 });
    await openModal(p, p.getByRole('button', { name: 'Self review' }).first());
    const w = modal(p);
    const m = await annotate(p, [
        { loc: w.getByText('Goals', { exact: true }).first(), n: 1 },
        { loc: w.getByText('Competencies', { exact: true }).first(), n: 2 },
        { loc: w.getByText('Overall rating').first(), n: 3 },
    ]);
    report('grow-05', m);
    await shot(p, 'grow-05-self-review-form', { element: w });
    record(s, { name: 'grow-05-self-review-form', persona: 'employee', path: '/admin/appraisals/4', caption: 'Self review form: a rating and comment per goal and competency, then an overall rating (not submitted)' });
    await closeModal(p);
    await p.close();
}

// 06 — Calibration board (admin)
if (want('grow-06')) {
    const p = await open(s, 'admin', '/admin/calibration-board');
    const row = p.locator('main table tbody tr').first();
    const m = await annotate(p, [
        { loc: p.getByRole('button', { name: 'Choose cycle' }).first(), n: 1 },
        { loc: p.locator('main').getByText('Needs Improvement', { exact: true }).first().locator('xpath=ancestor::div[contains(@class,"grid")][1]'), n: 2 },
        { loc: row.getByText('Calibrate', { exact: true }), n: 3 },
        { loc: row.getByText('Finalize', { exact: true }), n: 4 },
    ]);
    report('grow-06', m);
    await shot(p, 'grow-06-calibration-board');
    record(s, { name: 'grow-06-calibration-board', persona: 'admin', path: '/admin/calibration-board', caption: 'Calibration board: rating distribution for the cycle and Calibrate / Finalize per appraisal' });
    await p.close();
}

// 07 — Goals list (employee)
if (want('grow-07')) {
    const p = await open(s, 'employee', '/admin/goals');
    const okr = p.locator('main table tbody tr').filter({ hasText: 'Raise test coverage' }).first();
    const m = await annotate(p, [
        { loc: p.getByRole('link', { name: 'New goal' }).first(), n: 1 },
        { loc: okr.getByText('↳ Ship platform v2 by Q3'), n: 2 },
        { loc: okr.locator('td').nth(5), n: 3 },
        { loc: okr.getByText('Check in', { exact: true }), n: 4 },
    ]);
    report('grow-07', m);
    await shot(p, 'grow-07-goals-list-employee');
    record(s, { name: 'grow-07-goals-list-employee', persona: 'employee', path: '/admin/goals', caption: 'Goals as Priya Nair sees them: her own goals plus the company and department goals they align to' });
    await p.close();
}

// 08 — Goal check-in (employee), not submitted
if (want('grow-08')) {
    const p = await open(s, 'employee', '/admin/goals');
    const okr = p.locator('main table tbody tr').filter({ hasText: 'Raise test coverage' }).first();
    await openModal(p, okr.getByText('Check in', { exact: true }));
    const w = modal(p);
    const m = await annotate(p, [
        { loc: w.getByText('Key result', { exact: true }).first(), n: 1 },
        { loc: w.getByText('Current value').first(), n: 2 },
        { loc: w.getByText('Confidence').first(), n: 3 },
    ]);
    report('grow-08', m);
    await shot(p, 'grow-08-goal-check-in', { element: w });
    record(s, { name: 'grow-08-goal-check-in', persona: 'employee', path: '/admin/goals', caption: 'Check in window for a goal with a key result (not submitted)' });
    await closeModal(p);
    await p.close();
}

// 09 — New goal form (employee), not saved
if (want('grow-09')) {
    const p = await open(s, 'employee', '/admin/goals/create');
    await p.setViewportSize({ width: 1440, height: 1900 });
    await p.getByLabel('Title').first().fill('Document the release checklist (example)');
    await p.locator('body').click({ position: { x: 5, y: 300 } }).catch(() => {});
    await p.waitForTimeout(800);
    const lab = (t) => p.locator('form label').filter({ hasText: t }).first();
    const m = await annotate(p, [
        { loc: lab(/^\s*Level/), n: 1 },
        { loc: lab(/^\s*Aligned to/), n: 2 },
        { loc: lab(/^\s*Weight/), n: 3 },
        { loc: lab(/^\s*Measure type/), n: 4 },
        { loc: p.locator('form').getByText('Key results', { exact: true }).first(), n: 5 },
        { loc: p.locator('.pos-form-review').first(), n: 6 },
    ]);
    report('grow-09', m);
    await shot(p, 'grow-09-new-goal-form');
    record(s, { name: 'grow-09-new-goal-form', persona: 'employee', path: '/admin/goals/create', caption: 'New goal form (fictional title, not saved)' });
    await p.close();
}

// 10 — Give feedback (employee), not submitted
if (want('grow-10')) {
    const p = await open(s, 'employee', '/admin/feedback-entries');
    await openModal(p, p.getByRole('button', { name: 'Give feedback' }).first());
    const w = modal(p);
    const m = await annotate(p, [
        { loc: w.locator('label').filter({ hasText: /^\s*Visibility/ }).first(), n: 1 },
        { loc: w.getByText('Give anonymously').first(), n: 2 },
    ]);
    report('grow-10', m);
    await shot(p, 'grow-10-give-feedback', { element: w });
    record(s, { name: 'grow-10-give-feedback', persona: 'employee', path: '/admin/feedback-entries', caption: 'Give feedback window with visibility and the anonymous option (not submitted)' });
    await closeModal(p);
    await p.close();
}

// 11 — My learning (employee)
if (want('grow-11')) {
    const p = await open(s, 'employee', '/admin/my-learning');
    const m = await annotate(p, [
        { loc: p.getByRole('button', { name: 'Request learning' }).first(), n: 1 },
        { loc: p.locator('main').getByText('POSH awareness').first(), n: 2 },
        { loc: p.locator('main').getByText('My certificates', { exact: true }).first(), n: 3 },
        { loc: p.locator('main').getByText('My skills', { exact: true }).first(), n: 4 },
    ]);
    report('grow-11', m);
    await shot(p, 'grow-11-my-learning');
    record(s, { name: 'grow-11-my-learning', persona: 'employee', path: '/admin/my-learning', caption: "Priya Nair's My learning page with the mandatory POSH awareness course" });
    await p.close();
}

// 12 — Enrolment detail (employee). Status is Assigned, so opening it changes nothing.
if (want('grow-12')) {
    const p = await open(s, 'employee', '/admin/learning-enrolments/4');
    const m = await annotate(p, [
        { loc: p.getByRole('button', { name: 'Start' }).first(), n: 1 },
        { loc: p.locator('main').getByText('Assigned', { exact: true }).first(), n: 2 },
        { loc: p.locator('main').getByText('Modules', { exact: true }).first(), n: 3 },
        { loc: p.getByRole('button', { name: 'More' }).first(), n: 4 },
    ]);
    report('grow-12', m);
    await shot(p, 'grow-12-enrolment-detail');
    record(s, { name: 'grow-12-enrolment-detail', persona: 'employee', path: '/admin/learning-enrolments/4', caption: 'An enrolment opened by the learner: status, due date, modules and the Start button' });
    await p.close();
}

// 13 — Courses (admin)
if (want('grow-13')) {
    const p = await open(s, 'admin', '/admin/courses');
    const row = p.locator('main table tbody tr').first();
    const m = await annotate(p, [
        { loc: p.getByRole('link', { name: 'New course' }).first(), n: 1 },
        { loc: p.locator('main button, main a').filter({ hasText: /^\s*Published/ }).first(), n: 2 },
        { loc: row.getByText('Submit for approval', { exact: true }), n: 3 },
        { loc: row.getByText('Versions', { exact: true }), n: 4 },
    ]);
    report('grow-13', m);
    await shot(p, 'grow-13-courses-list');
    record(s, { name: 'grow-13-courses-list', persona: 'admin', path: '/admin/courses', caption: 'Course catalogue with lifecycle actions' });
    await p.close();
}

// 14 — Assign learning (admin), modal only
if (want('grow-14')) {
    const p = await open(s, 'admin', '/admin/learning-assignments');
    await p.setViewportSize({ width: 1440, height: 1500 });
    await openModal(p, p.getByRole('button', { name: 'Assign learning' }).first());
    const w = modal(p);
    // Show the rule-based variant (a population), which reveals the conditions and the daily auto-enrol switch.
    try {
        await w.getByLabel('Target').first().selectOption('population');
    } catch {
        try { await w.locator('select').nth(0).selectOption('population'); } catch {}
    }
    await p.waitForTimeout(900);
    const m = await annotate(p, [
        { loc: w.getByText('What', { exact: true }).first(), n: 1 },
        { loc: w.getByText('Who', { exact: true }).first(), n: 2 },
        { loc: w.getByText('When and why', { exact: true }).first(), n: 3 },
        { loc: w.getByText('Repeat every').first(), n: 4 },
    ]);
    report('grow-14', m);
    await shot(p, 'grow-14-assign-learning-form', { element: w });
    record(s, { name: 'grow-14-assign-learning-form', persona: 'admin', path: '/admin/learning-assignments', caption: 'Assign learning window: what, who and when (not submitted)' });
    await closeModal(p);
    await p.close();
}

// 15 — Training session with attendees (admin)
if (want('grow-15')) {
    const p = await open(s, 'admin', '/admin/training-sessions/1/edit');
    const m = await annotate(p, [
        { loc: p.getByRole('button', { name: 'Mark session completed' }).first(), n: 1 },
        { loc: p.getByText('Capacity', { exact: true }).first(), n: 2 },
        { loc: p.getByRole('button', { name: 'Register employee' }).first(), n: 3 },
    ]);
    report('grow-15', m);
    await shot(p, 'grow-15-training-session', { element: 'main' });
    record(s, { name: 'grow-15-training-session', persona: 'admin', path: '/admin/training-sessions/1/edit', caption: 'A classroom training session with its Attendees list' });
    await p.close();
}

// 16 — Team learning (manager)
if (want('grow-16')) {
    const p = await open(s, 'manager', '/admin/team-learning');
    const m = await annotate(p, [
        { loc: p.locator('main').getByRole('link', { name: 'Team learning' }).first(), n: 1 },
        { loc: p.getByRole('button', { name: 'Assign learning' }).first(), n: 2 },
        { loc: p.locator('main').getByText('Requests awaiting your decision', { exact: false }).first(), n: 3 },
    ]);
    report('grow-16', m);
    await shot(p, 'grow-16-team-learning');
    record(s, { name: 'grow-16-team-learning', persona: 'manager', path: '/admin/team-learning', caption: "Team learning for Amit Verma's team" });
    await p.close();
}

// 17 — My career (employee)
if (want('grow-17')) {
    const p = await open(s, 'employee', '/admin/my-career');
    const m = await annotate(p, [
        { loc: p.getByRole('button', { name: 'Edit profile & sharing' }).first(), n: 1 },
        { loc: p.getByRole('button', { name: 'Record aspiration' }).first(), n: 2 },
        { loc: p.getByRole('button', { name: 'New career goal' }).first(), n: 3 },
        { loc: p.getByRole('button', { name: 'Mobility interest' }).first(), n: 4 },
        { loc: p.locator('main').getByText('Shared with my manager').first(), n: 5 },
    ]);
    report('grow-17', m);
    await shot(p, 'grow-17-my-career');
    record(s, { name: 'grow-17-my-career', persona: 'employee', path: '/admin/my-career', caption: "Priya Nair's My career page" });
    await p.close();
}

// 18 — Team career (manager)
if (want('grow-18')) {
    const p = await open(s, 'manager', '/admin/team-career');
    const m = await annotate(p, [
        { loc: p.locator('main').getByText('appear only where the employee chose to share', { exact: false }).first(), n: 1 },
        { loc: p.locator('main table tbody tr').first().getByText('Not shared').first(), n: 2 },
    ]);
    report('grow-18', m);
    await shot(p, 'grow-18-team-career');
    record(s, { name: 'grow-18-team-career', persona: 'manager', path: '/admin/team-career', caption: 'Team career as Amit Verma sees it: only what each employee chose to share' });
    await p.close();
}

// 19 — Designate critical position (admin), modal only
if (want('grow-19')) {
    const p = await open(s, 'admin', '/admin/critical-positions');
    await p.setViewportSize({ width: 1440, height: 1400 });
    await openModal(p, p.getByRole('button', { name: 'Designate critical position' }).first());
    const w = modal(p);
    const m = await annotate(p, [
        { loc: w.locator('label').filter({ hasText: /^\s*Role/ }).first(), n: 1 },
        { loc: w.locator('label').filter({ hasText: /^\s*Review every/ }).first(), n: 2 },
        { loc: w.locator('label').filter({ hasText: /^\s*Criticality/ }).first(), n: 3 },
        { loc: w.locator('label').filter({ hasText: /^\s*Reason/ }).first(), n: 4 },
    ]);
    report('grow-19', m);
    await shot(p, 'grow-19-designate-critical-position', { element: w });
    record(s, { name: 'grow-19-designate-critical-position', persona: 'admin', path: '/admin/critical-positions', caption: 'Designate critical position window (not submitted)' });
    await closeModal(p);
    await p.close();
}

// 20 — Talent dashboard (admin)
if (want('grow-20')) {
    const p = await open(s, 'admin', '/admin/talent-dashboard');
    const m = await annotate(p, [
        { loc: p.locator('main').getByRole('link', { name: 'Dashboard' }).nth(1), n: 1 },
        { loc: p.locator('main').getByText('Positions without a ready-now successor', { exact: false }).first(), n: 2 },
    ]);
    report('grow-20', m);
    await shot(p, 'grow-20-talent-dashboard');
    record(s, { name: 'grow-20-talent-dashboard', persona: 'admin', path: '/admin/talent-dashboard', caption: 'Talent & succession dashboard (no critical positions recorded yet in the demonstration data)' });
    await p.close();
}

// 21 — My Team (manager)
if (want('grow-21')) {
    const p = await open(s, 'manager', '/admin/my-team');
    const m = await annotate(p, [
        { loc: p.locator('main').getByRole('link', { name: 'My Team' }).first(), n: 1 },
        { loc: p.locator('main').getByRole('link', { name: 'Team career' }).first(), n: 2 },
        { loc: p.locator('main').getByRole('link', { name: 'Team learning' }).first(), n: 3 },
        { loc: p.locator('main').getByText('Needs attention', { exact: false }).first(), n: 4 },
    ]);
    report('grow-21', m);
    await shot(p, 'grow-21-my-team');
    record(s, { name: 'grow-21-my-team', persona: 'manager', path: '/admin/my-team', caption: "My team page for Amit Verma, with links to Team career and Team learning" });
    await p.close();
}

await finish(s);
