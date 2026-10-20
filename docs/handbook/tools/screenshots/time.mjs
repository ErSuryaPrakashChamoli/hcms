// Time and leave chapter: genuine captures from the docs showcase (http://127.0.0.1:8097).
// Forms are opened, filled with fictional values and CANCELLED; nothing is submitted, approved or changed.
// Usage: node time.mjs [group ...]   groups: self, manager, leave, attendance, setup
import { start, open, annotate, clearCallouts, shot, record, finish, settle } from './lib.mjs';
import { modal, setData, cancelModal } from './time-helpers.mjs';

const groups = process.argv.slice(2);
const want = (g) => groups.length === 0 || groups.includes(g);
const s = await start('time');
const problems = [];
const check = (name, missing) => { if (missing.length) { problems.push(`${name}: ${missing.join(', ')}`); console.log('MISSING', name, missing); } };

if (want('self')) {
    // 01 Employee Home: Request leave, Check in, Fix my attendance.
    {
        const page = await open(s, 'employee', '/admin');
        await page.getByRole('button', { name: 'Got it' }).first().click().catch(() => {});
        await settle(page, 600);
        const missing = await annotate(page, [
            { sel: '[data-pos-action="request_leave_header"]', n: 1 },
            { loc: page.getByRole('button', { name: 'Check in', exact: true }).first(), n: 2 },
            { loc: page.getByText('Next leave:', { exact: false }).first(), n: 3 },
        ]);
        check('time-01', missing);
        await shot(page, 'time-01-employee-home');
        record(s, { name: 'time-01-employee-home', persona: 'employee', path: '/admin', caption: 'Employee Home with Request leave, Check in and the next leave' });
        await page.close();
    }
    // 02 Request leave form, filled with fictional values, then cancelled.
    {
        const page = await open(s, 'employee', '/admin');
        await page.setViewportSize({ width: 1440, height: 1300 });
        await page.locator('[data-pos-action="request_leave_header"]').click();
        await settle(page, 1500);
        const m = modal(page);
        const leaveSel = m.locator('select').filter({ has: page.locator('option', { hasText: 'Casual leave (CL)' }) }).first();
        await leaveSel.selectOption({ label: 'Casual leave (CL) · 5.0 available' });
        await settle(page, 900);
        await setData(page, 'from_date', '2026-12-24');
        await setData(page, 'to_date', '2026-12-28');
        await m.locator('textarea').first().fill('Year-end family visit (illustrative example)');
        await settle(page, 900);
        const missing = await annotate(page, [
            { loc: leaveSel, n: 1 },
            { loc: m.getByText('From date', { exact: false }).first(), n: 2 },
            { loc: m.getByText('Start', { exact: true }).first(), n: 3 },
            { loc: m.getByText('Working days', { exact: false }).first(), n: 4 },
            { loc: m.locator('textarea').first(), n: 5 },
            { loc: m.getByText('Supporting document', { exact: false }).first(), n: 6 },
            { loc: m.getByRole('button', { name: 'Submit request' }), n: 7 },
        ]);
        check('time-02', missing);
        await shot(page, 'time-02-request-leave-form', { element: '.fi-modal-window:visible' });
        record(s, { name: 'time-02-request-leave-form', persona: 'employee', path: '/admin (Request leave)', caption: 'Request leave form filled with fictional values (not submitted)' });
        await clearCallouts(page);
        await cancelModal(page);
        await page.close();
    }
    // 03 My Day.
    {
        const page = await open(s, 'employee', '/admin/my-day');
        const missing = await annotate(page, [
            { loc: page.getByRole('button', { name: 'Apply leave' }).first(), n: 1 },
            { loc: page.getByRole('button', { name: 'Regularise attendance' }).first(), n: 2 },
            { loc: page.getByRole('button', { name: 'Check in', exact: true }).first(), n: 3 },
            { loc: page.getByText('Leave balance', { exact: true }).first(), n: 4 },
        ]);
        check('time-03', missing);
        await shot(page, 'time-03-my-day');
        record(s, { name: 'time-03-my-day', persona: 'employee', path: '/admin/my-day', caption: 'My Day: Apply leave, Regularise attendance, Check in and leave balances' });
        await page.close();
    }
    // 04 The employee's own leave requests (with Cancel).
    {
        const page = await open(s, 'employee', '/admin/leave-requests');
        const missing = await annotate(page, [
            { loc: page.locator('.fi-badge').filter({ hasText: /^\s*pending\s*$/ }).first(), n: 1 },
            { loc: page.getByRole('button', { name: 'Cancel', exact: true }).first(), n: 2 },
            { loc: page.locator('a, span').filter({ hasText: /^Balances$/ }).first(), n: 3 },
        ]);
        check('time-04', missing);
        await shot(page, 'time-04-my-leave-requests');
        record(s, { name: 'time-04-my-leave-requests', persona: 'employee', path: '/admin/leave-requests', caption: "An employee's own leave request, waiting for approval, with Cancel" });
        await page.close();
    }
    // 13 Fix my attendance, filled with fictional values, then cancelled.
    {
        const page = await open(s, 'employee', '/admin');
        await page.setViewportSize({ width: 1440, height: 1100 });
        await page.getByText('Fix my attendance', { exact: true }).first().click();
        await settle(page, 1500);
        const m = modal(page);
        await setData(page, 'date', '2026-10-16');
        const typeSel = m.locator('select').filter({ has: page.locator('option', { hasText: 'Missed punch' }) }).first();
        await typeSel.selectOption({ label: 'Missed punch' });
        await setData(page, 'in', '09:10');
        await setData(page, 'out', '18:05');
        await m.locator('textarea').first().fill('Badge not read at the gate in the evening (illustrative example)');
        await settle(page, 800);
        const missing = await annotate(page, [
            { loc: m.locator('input:visible').first(), n: 1 },
            { loc: typeSel, n: 2 },
            { loc: m.getByText('Actual in', { exact: true }).first(), n: 3 },
            { loc: m.locator('textarea').first(), n: 4 },
            { loc: m.getByRole('button', { name: 'Submit', exact: true }), n: 5 },
        ]);
        check('time-13', missing);
        await shot(page, 'time-13-fix-my-attendance', { element: '.fi-modal-window:visible' });
        record(s, { name: 'time-13-fix-my-attendance', persona: 'employee', path: '/admin (Fix my attendance)', caption: 'Fix my attendance form filled with fictional values (not submitted)' });
        await clearCallouts(page);
        await cancelModal(page);
        await page.close();
    }
}

if (want('manager')) {
    // 05 Approval Center (manager) with a leave request selected: Before -> After, Approve / Reject.
    {
        const page = await open(s, 'manager', '/admin/approvals');
        await page.getByText('Priya Nair · Casual leave · 2 days').first().click();
        await settle(page, 1500);
        const missing = await annotate(page, [
            { loc: page.getByText('Priya Nair · Casual leave · 2 days').first(), n: 1 },
            { loc: page.locator('text="Casual leave balance" >> visible=true').first(), n: 2 },
            { loc: page.getByRole('button', { name: 'Approve', exact: true }).last(), n: 3 },
            { loc: page.getByRole('button', { name: 'Reject', exact: true }).last(), n: 4 },
        ]);
        check('time-05', missing);
        await shot(page, 'time-05-approval-center');
        record(s, { name: 'time-05-approval-center', persona: 'manager', path: '/admin/approvals', caption: 'Approval Center: a leave request with its balance before and after, Approve and Reject' });
        await page.close();
    }
    // 08 Leave calendar (manager).
    {
        const page = await open(s, 'manager', '/admin/leave-calendar');
        const missing = await annotate(page, [
            { loc: page.locator('.fi-ta-filters-trigger-action-ctn, button[title="Filter"], .fi-ta-header-toolbar button').first(), n: 1 },
            { loc: page.locator('th').filter({ hasText: 'Status' }).first(), n: 2 },
        ]);
        check('time-08', missing);
        await shot(page, 'time-08-leave-calendar');
        record(s, { name: 'time-08-leave-calendar', persona: 'manager', path: '/admin/leave-calendar', caption: 'Leave calendar: who is away and when, with pending and approved leave' });
        await page.close();
    }
    // 14 Regularisations (manager).
    {
        const page = await open(s, 'manager', '/admin/attendance-regularisations');
        const missing = await annotate(page, [
            { loc: page.getByText('Status: Pending', { exact: false }).first(), n: 1 },
            { loc: page.locator('th').filter({ hasText: /^\s*By\s*$/ }).first(), n: 2 },
            { loc: page.getByRole('button', { name: 'Approve', exact: true }).first(), n: 3 },
            { loc: page.getByRole('button', { name: 'Reject', exact: true }).first(), n: 4 },
            { loc: page.getByText('Decide in the Approval Center', { exact: false }).first(), n: 5 },
        ]);
        check('time-14', missing);
        await shot(page, 'time-14-regularisations');
        record(s, { name: 'time-14-regularisations', persona: 'manager', path: '/admin/attendance-regularisations', caption: 'Regularisations waiting for a decision, with Approve and Reject' });
        await page.close();
    }
}

if (want('leave')) {
    // 06 Leave requests register (HR Manager).
    {
        const page = await open(s, 'hr', '/admin/leave-requests');
        const missing = await annotate(page, [
            { loc: page.getByText('All in Time & leave', { exact: false }).first(), n: 1 },
            { loc: page.getByPlaceholder('Search leave requests'), n: 2 },
            { loc: page.locator('.fi-ta-filters-trigger-action-ctn, .fi-ta-header-toolbar button').first(), n: 3, badge: 'tr' },
            { loc: page.locator('.fi-badge').filter({ hasText: /^\s*pending\s*$/ }).first(), n: 4 },
            { loc: page.getByRole('button', { name: 'Approve', exact: true }).first(), n: 5 },
            { loc: page.getByRole('button', { name: 'Cancel', exact: true }).first(), n: 6 },
            { loc: page.getByText('Decide in the Approval Center', { exact: false }).first(), n: 7 },
        ]);
        check('time-06', missing);
        await shot(page, 'time-06-leave-requests');
        record(s, { name: 'time-06-leave-requests', persona: 'hr', path: '/admin/leave-requests', caption: 'Leave requests register for HR, with Approve, Reject and Cancel' });
        await page.close();
    }
    // 09 Leave balances.
    {
        const page = await open(s, 'hr', '/admin/leave-balances');
        await page.setViewportSize({ width: 1920, height: 1000 });
        await settle(page, 1200);
        const missing = await annotate(page, [
            { loc: page.getByRole('button', { name: 'Run accrual now' }), n: 1 },
            { loc: page.getByText('Year: 2026', { exact: false }).first(), n: 2 },
            { loc: page.locator('th').filter({ hasText: /^\s*Available\s*$/ }).first(), n: 3 },
            { loc: page.getByRole('button', { name: 'Adjust', exact: true }).first(), n: 4 },
            { loc: page.getByRole('button', { name: 'Encash', exact: true }).first(), n: 5 },
        ]);
        check('time-09', missing);
        await shot(page, 'time-09-leave-balances');
        record(s, { name: 'time-09-leave-balances', persona: 'hr', path: '/admin/leave-balances', caption: 'Leave balances per person, type and year, with Adjust, Encash and Run accrual now' });
        await page.close();
    }
    // 10 Adjust modal, filled with fictional values, then cancelled.
    {
        const page = await open(s, 'hr', '/admin/leave-balances');
        await page.getByRole('button', { name: 'Adjust', exact: true }).first().click();
        await settle(page, 1500);
        const m = modal(page);
        await m.locator('input').first().fill('1');
        await m.locator('textarea').first().fill('Illustrative example: one day credited for working on a public holiday (not saved)');
        await settle(page, 600);
        const missing = await annotate(page, [
            { loc: m.locator('input').first(), n: 1 },
            { loc: m.locator('textarea').first(), n: 2 },
            { loc: m.getByRole('button', { name: 'Submit', exact: true }), n: 3 },
        ]);
        check('time-10', missing);
        await shot(page, 'time-10-adjust-balance', { element: '.fi-modal-window:visible' });
        record(s, { name: 'time-10-adjust-balance', persona: 'hr', path: '/admin/leave-balances (Adjust)', caption: 'Adjust a leave balance: days and a required note (not saved)' });
        await clearCallouts(page);
        await cancelModal(page);
        await page.close();
    }
    // 11 Transactions (the leave ledger).
    {
        const page = await open(s, 'hr', '/admin/leave-transactions');
        const missing = await annotate(page, [
            { loc: page.locator('th').filter({ hasText: /^\s*Movement\s*$/ }).first(), n: 1 },
            { loc: page.locator('th').filter({ hasText: /^\s*Days\s*$/ }).first(), n: 2 },
            { loc: page.locator('th').filter({ hasText: /^\s*Note\s*$/ }).first(), n: 3 },
            { loc: page.locator('.fi-ta-filters-trigger-action-ctn, .fi-ta-header-toolbar button').first(), n: 4, badge: 'tr' },
        ]);
        check('time-11', missing);
        await shot(page, 'time-11-leave-transactions');
        record(s, { name: 'time-11-leave-transactions', persona: 'hr', path: '/admin/leave-transactions', caption: 'Transactions: every movement on a leave balance, read-only' });
        await page.close();
    }
    // 12 Leave types.
    {
        const page = await open(s, 'hr', '/admin/leave-types');
        const missing = await annotate(page, [
            { loc: page.getByRole('button', { name: 'New leave type' }), n: 1 },
            { loc: page.locator('th').filter({ hasText: /^\s*Category\s*$/ }).first(), n: 2 },
            { loc: page.locator('th').filter({ hasText: /^\s*Approval\s*$/ }).first(), n: 3 },
            { loc: page.getByRole('button', { name: 'Edit', exact: true }).first(), n: 4 },
        ]);
        check('time-12', missing);
        await shot(page, 'time-12-leave-types');
        record(s, { name: 'time-12-leave-types', persona: 'hr', path: '/admin/leave-types', caption: 'Leave types: the nine starting types, with New leave type and Edit' });
        await page.close();
    }
}

if (want('attendance')) {
    // 15 Exception centre (HR Manager).
    {
        const page = await open(s, 'hr', '/admin/attendance-exception-centre');
        const missing = await annotate(page, [
            { sel: '.pos-figures', n: 1 },
            { loc: page.locator('.fi-ta-filters-trigger-action-ctn, .fi-ta-header-toolbar button').first(), n: 2, badge: 'tr' },
            { loc: page.getByText('No attendance exceptions', { exact: true }).first(), n: 3 },
        ]);
        check('time-15', missing);
        await shot(page, 'time-15-exception-centre');
        record(s, { name: 'time-15-exception-centre', persona: 'hr', path: '/admin/attendance-exception-centre', caption: 'Attendance exception centre: 30-day counts and the days that need a decision' });
        await page.close();
    }
    // 16 Attendance records (Tenant HR Admin): Manual punch and Process day.
    {
        const page = await open(s, 'admin', '/admin/attendance-records');
        const missing = await annotate(page, [
            { loc: page.getByRole('button', { name: 'Manual punch' }), n: 1 },
            { loc: page.getByRole('button', { name: 'Process day' }), n: 2 },
            { loc: page.locator('.fi-ta-filters-trigger-action-ctn, .fi-ta-header-toolbar button').first(), n: 3, badge: 'tr' },
            { loc: page.locator('th').filter({ hasText: /^\s*Exceptions\s*$/ }).first(), n: 4 },
        ]);
        check('time-16', missing);
        await shot(page, 'time-16-attendance-records');
        record(s, { name: 'time-16-attendance-records', persona: 'admin', path: '/admin/attendance-records', caption: 'Attendance records with Manual punch and Process day (no records in the demonstration yet)' });
        await page.close();
    }
}

if (want('setup')) {
    // 17 New shift form (Tenant HR Admin), filled with fictional values, not created.
    {
        const page = await open(s, 'admin', '/admin/shifts/create');
        await page.setViewportSize({ width: 1440, height: 2000 });
        await settle(page, 800);
        const main = page.locator('main');
        await page.locator('[id="form.name"]').fill('Early (07:00–16:00) example');
        await page.locator('[id="form.code"]').fill('EARLY_EX');
        await page.locator('[id="form.start_time"]').fill('07:00').catch((e) => problems.push('time-17 start_time: ' + e.message.split('\n')[0]));
        await page.locator('[id="form.end_time"]').fill('16:00').catch((e) => problems.push('time-17 end_time: ' + e.message.split('\n')[0]));
        await page.locator('main h1').first().click().catch(() => {});
        await settle(page, 800);
        const missing = await annotate(page, [
            { loc: main.getByText('Shift', { exact: true }).first(), n: 1 },
            { loc: main.getByText('Hours and thresholds', { exact: true }).first(), n: 2 },
            { loc: main.getByText('Breaks', { exact: true }).first(), n: 3 },
            { loc: main.getByText('Overtime', { exact: true }).first(), n: 4 },
            { loc: main.getByText('Effective dates', { exact: true }).first(), n: 5 },
            { loc: main.getByRole('button', { name: 'Create shift' }).first(), n: 6 },
            { loc: main.getByText('Review before creating', { exact: false }).first(), n: 7 },
        ]);
        check('time-17', missing);
        await shot(page, 'time-17-new-shift', { element: 'main' });
        record(s, { name: 'time-17-new-shift', persona: 'admin', path: '/admin/shifts/create', caption: 'New shift form filled with fictional values (not created)' });
        await page.close();
    }
    // 18 Work schedule: weekly pattern and Applies to.
    {
        const page = await open(s, 'admin', '/admin/work-schedules/1/edit');
        await page.setViewportSize({ width: 1920, height: 1300 });
        await settle(page, 1200);
        const main = page.locator('main');
        const missing = await annotate(page, [
            { loc: main.getByText('Weekly pattern', { exact: true }).first(), n: 1 },
            { loc: main.locator('label').filter({ hasText: /^\s*Sat\s*$/ }).first(), n: 2 },
            { loc: main.getByRole('button', { name: 'Add to weeks' }), n: 3 },
            { loc: page.locator('[role=tab]').filter({ hasText: /^\s*Applies to\s*$/ }).first(), n: 4 },
        ]);
        check('time-18', missing);
        await shot(page, 'time-18-work-schedule', { element: 'main' });
        record(s, { name: 'time-18-work-schedule', persona: 'admin', path: '/admin/work-schedules/1/edit', caption: 'Work schedule Monday to Friday: the weekly pattern and the Applies to tab' });
        await page.close();
    }
    // 19 Holiday calendar with its holidays.
    {
        const page = await open(s, 'admin', '/admin/holiday-calendars/1/edit');
        await page.setViewportSize({ width: 1440, height: 1500 });
        await settle(page, 1500);
        const main = page.locator('main');
        const missing = await annotate(page, [
            { loc: page.locator('[role=tab]').filter({ hasText: /^\s*Holidays\s*$/ }).first(), n: 1 },
            { loc: page.locator('[role=tab]').filter({ hasText: /^\s*Applies to\s*$/ }).first(), n: 2 },
            { loc: main.getByRole('button', { name: 'New holiday' }), n: 3 },
            { loc: main.getByText('Dussehra', { exact: true }).first(), n: 4 },
        ]);
        check('time-19', missing);
        await shot(page, 'time-19-holiday-calendar', { element: 'main' });
        record(s, { name: 'time-19-holiday-calendar', persona: 'admin', path: '/admin/holiday-calendars/1/edit', caption: 'Holiday calendar National holidays 2026 with its holidays' });
        await page.close();
    }
    // 20 Employee 360 > Records > Time > Leave (HR Manager), with Apply for leave.
    {
        const page = await open(s, 'hr', '/admin/employees/4');
        await page.locator('a[href="#records"]').first().click();
        await settle(page, 1500);
        const time = page.locator('[role=tab]').filter({ hasText: /^\s*Time\s*$/ }).first();
        await time.scrollIntoViewIfNeeded();
        await time.click();
        await settle(page, 3000);
        for (let i = 0; i < 6; i++) { await page.mouse.wheel(0, 900); await page.waitForTimeout(1500); }
        await settle(page, 2500);
        const leaveHeading = page.locator('.fi-ta-header-heading').filter({ hasText: 'Leave · balances' }).first();
        await leaveHeading.scrollIntoViewIfNeeded();
        await settle(page, 800);
        await page.evaluate(() => window.scrollTo(0, 0));
        await settle(page, 600);
        const missing = await annotate(page, [
            { loc: time, n: 1 },
            { loc: leaveHeading, n: 2 },
            { loc: page.getByRole('button', { name: 'Apply for leave' }).first(), n: 3 },
        ]);
        check('time-20', missing);
        const box = (l) => l.evaluate((e) => { const r = e.getBoundingClientRect(); return { x: r.x + window.scrollX, y: r.y + window.scrollY, w: r.width, h: r.height }; });
        const tb = await box(time);
        const leaveTable = page.locator('.fi-ta').filter({ has: leaveHeading }).first();
        const lb = await box(leaveTable);
        await shot(page, 'time-20-employee-360-leave', { fullPage: true, clip: { x: lb.x - 24, y: tb.y - 30, width: lb.w + 48, height: (lb.y + lb.h + 20) - (tb.y - 30) } });
        record(s, { name: 'time-20-employee-360-leave', persona: 'hr', path: '/admin/employees/4 (Records › Time › Leave)', caption: "Employee 360 for Priya Nair: the Leave section with this year's balances and Apply for leave" });
        await page.close();
    }
}

console.log('PROBLEMS', problems);
await finish(s);
