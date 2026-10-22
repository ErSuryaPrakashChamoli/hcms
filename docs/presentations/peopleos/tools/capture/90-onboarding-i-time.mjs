// Deck 4 stage 8: attendance and leave configuration in Northwind Retail — shift, work schedule, holiday calendar,
// leave types (seeded) and a leave policy. EXECUTED on the disposable training database. Re-runnable.
import { start, open, go, shot, finish, idle, toasts, closeToasts, modal, setData, setForm, dump } from './lib.mjs';
import './d-accounts.mjs';
import { fillForm } from './d-ui.mjs';

const s = await start('90-onboarding-i-time');
const D = { deck: [4], prereq: 'Signed in as the tenant administrator (attendance.manage, leave.manage, policy.manage)' };
const pg = await open(s, 'nwadmin', '/admin/shifts');
const has = async (text) => (await pg.locator('main').getByText(text, { exact: false }).count()) > 0;
const submitPage = async (label) => {
    await pg.locator('main button[type=submit]').first().click();
    await idle(pg, 2500);
    console.log('   ', label, pg.url(), (await toasts(pg)).join(' / '));
    await closeToasts(pg);
};

// Shift.
const W1 = { ...D, workflow: 'Attendance configuration: shifts and schedules' };
if (!await has('General shift')) {
    await go(pg, '/admin/shifts/create');
    await pg.setViewportSize({ width: 1440, height: 1500 });
    await fillForm(pg, { name: 'General shift', code: 'GEN', start_time: '09:30', end_time: '18:30', full_day_minutes: '480', half_day_minutes: '240', break_minutes: '60', grace_in_minutes: '15', grace_out_minutes: '10', min_overtime_minutes: '60' });
    await setForm(pg, 'effective_from', '2026-04-01');
    await shot(s, pg, 'client-onboarding/90-onb-61-new-shift', { ...W1, screen: 'Time & leave › Shifts › New shift', action: 'Name, code, type, start and end, full/half-day minutes, breaks, grace and overtime thresholds, effective date',
        expected: 'Attendance status, late marks and overtime are computed from these values', callouts: [{ loc: pg.locator('[id="form.start_time"]'), n: 1, label: 'Start / end' }, { loc: pg.locator('[id="form.full_day_minutes"]'), n: 2, label: 'Full / half day' }, { loc: pg.locator('[id="form.grace_in_minutes"]'), n: 3, label: 'Grace' }, { loc: pg.locator('[id="form.min_overtime_minutes"]'), n: 4, label: 'Overtime' }] });
    await submitPage('shift');
    await pg.setViewportSize({ width: 1440, height: 900 });
}
// Work schedule: Mon–Fri on the general shift.
if (!await (async () => { await go(pg, '/admin/work-schedules'); return has('Bengaluru five-day week'); })()) {
    await go(pg, '/admin/work-schedules/create');
    await pg.setViewportSize({ width: 1440, height: 1200 });
    await fillForm(pg, { name: 'Bengaluru five-day week', code: 'BLR5' });
    await setForm(pg, 'effective_from', '2026-04-01');
    for (const day of ['mon', 'tue', 'wed', 'thu', 'fri']) {
        const sel = pg.locator(`select[id$=".${day}"]`).first();
        const opts = await sel.locator('option').allInnerTexts();
        const label = opts.map((o) => o.trim()).find((o) => /General/.test(o));
        if (label) await sel.selectOption({ label });
    }
    for (const day of ['sat', 'sun']) {
        const sel = pg.locator(`select[id$=".${day}"]`).first();
        const opts = (await sel.locator('option').allInnerTexts()).map((o) => o.trim());
        const off = opts.find((o) => /off|rest|weekly/i.test(o));
        if (off) await sel.selectOption({ label: off });
    }
    await idle(pg, 800);
    await shot(s, pg, 'client-onboarding/90-onb-62-new-work-schedule', { ...W1, screen: 'Time & leave › Work schedules › New work schedule', action: 'A weekly pattern: the general shift Monday to Friday, weekly off at the weekend',
        expected: 'Schedules can rotate over several weeks (Add to weeks); rules on the saved schedule decide who it applies to', callouts: [{ loc: pg.locator('[id="form.name"]'), n: 1, label: 'Name' }, { loc: pg.locator('select[id$=".mon"]'), n: 2, label: 'Monday' }, { loc: pg.locator('select[id$=".sat"]'), n: 3, label: 'Saturday' }, { loc: pg.getByRole('button', { name: 'Add to weeks' }), n: 4, label: 'Add a week (rotation)' }] });
    await submitPage('work schedule');
    await pg.setViewportSize({ width: 1440, height: 900 });
    await shot(s, pg, 'client-onboarding/90-onb-63-work-schedule-saved', { ...W1, screen: 'Work schedule (edit page after creating)', action: 'Create work schedule', expected: 'Weekly pattern, the "Applies to" rules and History',
        fullPage: false, callouts: [] });
}

// Holiday calendar with two holidays.
const W2 = { ...D, workflow: 'Attendance configuration: holiday calendar' };
await go(pg, '/admin/holiday-calendars');
if (!await has('Karnataka holidays 2026')) {
    await go(pg, '/admin/holiday-calendars/create');
    await fillForm(pg, { name: 'Karnataka holidays 2026', code: 'KA-2026', year: '2026' });
    await shot(s, pg, 'client-onboarding/90-onb-64-new-holiday-calendar', { ...W2, screen: 'Time & leave › Holiday calendars › New holiday calendar', action: 'Name, code, year, status',
        expected: 'Holidays and the "Applies to" rules are added on the saved calendar', callouts: [{ loc: pg.locator('[id="form.name"]'), n: 1, label: 'Name' }, { loc: pg.locator('[id="form.year"]'), n: 2, label: 'Year' }] });
    await submitPage('holiday calendar');
}
{
    await go(pg, '/admin/holiday-calendars');
    await pg.locator('tr', { hasText: 'Karnataka holidays 2026' }).getByRole('link').first().click();
    await idle(pg, 2000);
    for (const [date, name] of [['2026-11-01', 'Kannada Rajyotsava'], ['2026-12-25', 'Christmas']]) {
        if (await pg.locator('main').getByText(name, { exact: true }).count()) continue;
        const btn = pg.getByRole('button', { name: /New holiday/ }).first();
        await btn.scrollIntoViewIfNeeded();
        await btn.click();
        await idle(pg, 1200);
        await setData(pg, 'date', date).catch(async () => { await modal(pg).locator('input[type=date]').first().fill(date); });
        await modal(pg).locator('input[id$=".name"]').first().fill(name);
        await idle(pg, 600);
        if (name === 'Christmas') await shot(s, pg, 'client-onboarding/90-onb-65-new-holiday', { ...W2, screen: 'Holiday calendar › Holidays › New holiday', action: 'Date, name, type and half day',
            expected: 'The date counts as a holiday for everyone the calendar applies to', note: 'Known limitation F39: the holiday type and the Half day switch are not used by the attendance engine; every date counts as a full holiday.',
            element: '.fi-modal-window:visible >> nth=-1', callouts: [] });
        await modal(pg).getByRole('button', { name: 'Create', exact: true }).last().click();
        await idle(pg, 1800);
        await closeToasts(pg);
    }
    await go(pg, pg.url());
    await pg.setViewportSize({ width: 1440, height: 1450 });
    await idle(pg, 1500);
    await shot(s, pg, 'client-onboarding/90-onb-66-holiday-calendar-saved', { ...W2, screen: 'Holiday calendar (edit page)', action: 'Holidays added', expected: 'Holidays list (Kannada Rajyotsava, Christmas), Applies to and History tabs',
        callouts: [{ loc: pg.getByRole('button', { name: /New holiday/ }), n: 1, label: 'New holiday' }, { loc: pg.getByRole('tab', { name: 'Applies to' }), n: 2, label: 'Applies to' }] });
    await pg.setViewportSize({ width: 1440, height: 900 });
}

// Leave types (seeded) and a leave policy.
const W3 = { ...D, workflow: 'Leave configuration: leave types and leave policy' };
await go(pg, '/admin/leave-types');
await shot(s, pg, 'client-onboarding/90-onb-67-leave-types', { ...W3, screen: 'Time & leave › Leave types', action: 'Review the leave types every new tenant receives',
    expected: 'Earned, Casual, Sick, Maternity, Paternity, Bereavement, Compensatory off, Restricted holiday and Leave without pay — paid / half day / encashable flags and who they apply to',
    callouts: [{ loc: pg.locator('tr', { hasText: 'Earned leave' }), n: 1, label: 'Seeded type' }, { loc: pg.getByRole('button', { name: 'New leave type' }), n: 2, label: 'New leave type' }] });
await go(pg, '/admin/policies');
if (!await has('Northwind leave policy')) {
    await go(pg, '/admin/policies/create');
    console.log('    policy types', (await pg.locator('[id="form.type"] option').allInnerTexts()).map((t) => t.trim()).join(' | '));
    const types = (await pg.locator('[id="form.type"] option').allInnerTexts()).map((t) => t.trim());
    await pg.locator('[id="form.type"]').selectOption({ label: types.find((t) => /leave/i.test(t)) });
    await idle(pg, 800);
    await fillForm(pg, { name: 'Northwind leave policy', code: 'NW-LEAVE', description: 'Leave entitlements for Northwind Retail employees (training example)' });
    await shot(s, pg, 'client-onboarding/90-onb-68-new-leave-policy', { ...W3, screen: 'Workflows › Policies › New policy', action: 'Type Leave policy, name, code, description',
        expected: 'The rules themselves are set on a draft version of the saved policy, then published', callouts: [{ loc: pg.locator('[id="form.type"]'), n: 1, label: 'Policy type' }, { loc: pg.locator('[id="form.name"]'), n: 2, label: 'Name' }] });
    await submitPage('policy');
    await dump(pg, 'main', 50);
    await pg.setViewportSize({ width: 1440, height: 1600 }); await idle(pg, 2000);
    await shot(s, pg, 'client-onboarding/90-onb-69-leave-policy-saved', { ...W3, screen: 'Policy (after creating)', action: 'Create policy', expected: 'Draft version v1 under Versions & settings, with Settings and Edit draft; Publish draft at the top', callouts: [] });
    await pg.setViewportSize({ width: 1440, height: 900 });
}
await finish(s);
