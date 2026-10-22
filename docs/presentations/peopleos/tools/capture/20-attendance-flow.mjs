// Workflow: employee checks in and corrects a missed punch → manager approves → HR processes the day → records.
// EXECUTED on the disposable training database (fictional people). Decks 1, 2, 3, 7, 11.
import { start, open, go, shot, finish, idle, modal, setData, dismissWelcome, toasts, closeToasts } from './lib.mjs';

const s = await start('20-attendance-flow');
const byId = (page, id) => modal(page).locator(`[id="mountedActionSchema0.${id}"]`);

// 1. Employee: Check in from Home › Your day.
const D = { deck: [1, 11], workflow: 'Attendance: check in and correct a missed punch (employee)' };
const emp = await open(s, 'employee', '/admin');
await dismissWelcome(emp);
const checkIn = emp.getByRole('button', { name: 'Check in', exact: true });
await checkIn.first().scrollIntoViewIfNeeded();
await emp.evaluate(() => window.scrollBy(0, -200));
await shot(s, emp, 'employee/20-att-01-your-day-check-in', { ...D, screen: 'Home › Your day',
    action: 'Check in from Home (web punch)', expected: 'Your day shows "Not checked in yet" and a Check in button',
    callouts: [{ loc: emp.getByText('Not checked in yet'), n: 1, label: 'Today’s status' }, { loc: checkIn, n: 2, label: 'Check in' }] });
await checkIn.first().click();
await idle(emp, 1500);
console.log('    toast:', (await toasts(emp)).join(' / '));
await shot(s, emp, 'employee/20-att-02-checked-in', { ...D, screen: 'Home › Your day (after Check in)',
    action: 'Check in', expected: 'Confirmation; the day now shows the check-in time and offers Check out',
    callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Confirmation' }, { loc: emp.getByRole('button', { name: 'Check out', exact: true }), n: 2, label: 'Check out' }] });
await closeToasts(emp);

// 2. Employee: Fix my attendance (regularisation) for a missed punch.
await go(emp, '/admin');
await emp.setViewportSize({ width: 1440, height: 1100 });
await emp.getByText('Fix my attendance', { exact: true }).first().click();
await idle(emp, 1500);
await setData(emp, 'date', '2026-10-20');
await byId(emp, 'type').selectOption({ label: 'Missed punch' });
await idle(emp, 600);
await byId(emp, 'in').fill('09:05');
await byId(emp, 'out').fill('18:10');
await byId(emp, 'reason').fill('Badge not read at the gate in the evening (training example)');
await idle(emp, 800);
await shot(s, emp, 'employee/20-att-03-fix-my-attendance', { ...D, screen: 'Fix my attendance (dialog)',
    action: 'Home › Start something › Fix my attendance: date, type, actual in/out, reason', expected: 'All fields filled; Submit sends it to the manager',
    element: '.fi-modal-window:visible >> nth=-1',
    callouts: [{ loc: byId(emp, 'date'), n: 1, label: 'Date' }, { loc: byId(emp, 'type'), n: 2, label: 'Type' }, { loc: byId(emp, 'in'), n: 3, label: 'Actual in' },
        { loc: byId(emp, 'out'), n: 4, label: 'Actual out' }, { loc: byId(emp, 'reason'), n: 5, label: 'Reason' }, { loc: modal(emp).getByRole('button', { name: 'Submit', exact: true }), n: 6, label: 'Submit' }] });
await modal(emp).getByRole('button', { name: 'Submit', exact: true }).click();
await idle(emp, 1500);
console.log('    toast:', (await toasts(emp)).join(' / '));
await emp.setViewportSize({ width: 1440, height: 900 });
await shot(s, emp, 'employee/20-att-04-correction-submitted', { ...D, screen: 'Home (after submitting)',
    action: 'Submit', expected: 'Confirmation; the correction waits for the manager', callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Confirmation' }] });
await go(emp, '/admin/attendance-regularisations');
await shot(s, emp, 'employee/20-att-05-regularisation-pending', { ...D, screen: 'Regularisations (own)',
    action: 'People › Time & leave › Regularisations', expected: 'The correction is listed as pending',
    callouts: [{ loc: emp.locator('tr', { hasText: 'Badge not read' }).first(), n: 1, label: 'Pending correction' }] });

// 3. Manager approves it in the Approval Center.
const D2 = { deck: [2, 11], workflow: 'Approve an attendance correction (manager)' };
const mgr = await open(s, 'manager', '/admin/approvals');
await mgr.getByRole('button', { name: /Priya Nair · Missed punch/ }).first().click();
await idle(mgr, 1200);
await mgr.evaluate(() => window.scrollTo(0, 0));
await shot(s, mgr, 'manager/20-att-06-correction-card', { ...D2, screen: 'Approval Center › attendance correction',
    action: 'Select the correction', expected: 'Date, requested in/out times and reason; Approve / Reject',
    callouts: [{ loc: mgr.getByText('Badge not read at the gate'), n: 1, label: 'Reason' }, { loc: mgr.getByRole('button', { name: 'Approve', exact: true }), n: 2, label: 'Approve' }] });
await mgr.getByRole('button', { name: 'Approve', exact: true }).last().click();
await idle(mgr, 800);
await mgr.getByRole('button', { name: /^Confirm/ }).last().click();
await idle(mgr, 1500);
console.log('    toast:', (await toasts(mgr)).join(' / '));
await shot(s, mgr, 'manager/20-att-07-correction-approved', { ...D2, screen: 'Approval Center (after the decision)',
    action: 'Approve → Confirm', expected: 'Decision recorded', callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Decision recorded' }] });
await closeToasts(mgr);
// The manager's own lists.
await go(mgr, '/admin/attendance-regularisations');
await shot(s, mgr, 'manager/20-att-08-team-regularisations', { ...D2, screen: 'Regularisations (team)',
    action: 'People › Time & leave › Regularisations', expected: 'Team corrections with status and reviewer; pending ones can be decided here too',
    callouts: [{ loc: mgr.getByText('Decide in the Approval Center', { exact: false }), n: 1, label: 'Approval Center link' }] });
await go(mgr, '/admin/attendance-exception-centre');
await shot(s, mgr, 'manager/20-att-09-exception-centre', { ...D2, workflow: 'Team attendance (manager)', screen: 'Exception centre',
    action: 'People › Time & leave › Exception centre', expected: 'Missing punches, late, absent and overtime for the last 30 days',
    callouts: [{ loc: mgr.getByText('Missing punch', { exact: false }), n: 1, label: 'Exception counts' }] });

// 4. HR: process the day, manual punch, records.
const D3 = { deck: [3, 4, 11], workflow: 'Attendance administration (HR)' };
const hr = await open(s, 'admin', '/admin/attendance-records');
await shot(s, hr, 'hr-admin/20-att-10-records-before', { ...D3, screen: 'Attendance records',
    action: 'People › Time & leave › Attendance records', expected: 'Header actions Manual punch and Process day',
    callouts: [{ loc: hr.getByRole('button', { name: 'Manual punch' }), n: 1, label: 'Manual punch' }, { loc: hr.getByRole('button', { name: 'Process day' }), n: 2, label: 'Process day' }] });
await hr.getByRole('button', { name: 'Manual punch' }).first().click();
await idle(hr, 1200);
await modal(hr).locator('button', { hasText: 'Select an option' }).first().click();
await idle(hr, 600);
await hr.keyboard.type('Rahul');
await idle(hr, 1200);
await hr.locator('.fi-select-input-option, [role=option]').filter({ hasText: 'Rahul Sharma' }).first().click();
await idle(hr, 800);
await setData(hr, 'punched_at', '2026-10-20 09:12:00');
await byId(hr, 'direction').selectOption({ index: 1 });
await byId(hr, 'note').fill('Device offline at the Delhi gate (training example)');
await idle(hr, 600);
await shot(s, hr, 'hr-admin/20-att-11-manual-punch', { ...D3, screen: 'Manual punch (dialog)',
    action: 'Record a punch for an employee whose device was offline', expected: 'Employee, punched at, direction and a note',
    element: '.fi-modal-window:visible >> nth=-1',
    callouts: [{ loc: byId(hr, 'punched_at'), n: 1, label: 'Punched at' }, { loc: byId(hr, 'direction'), n: 2, label: 'Direction' }, { loc: byId(hr, 'note'), n: 3, label: 'Note' }] });
await modal(hr).getByRole('button', { name: 'Submit', exact: true }).click();
await idle(hr, 1500);
console.log('    toast:', (await toasts(hr)).join(' / '));
await closeToasts(hr);
await hr.getByRole('button', { name: 'Process day' }).first().click();
await idle(hr, 1200);
await setData(hr, 'date', '2026-10-20');
await shot(s, hr, 'hr-admin/20-att-12-process-day', { ...D3, screen: 'Process day (dialog)',
    action: 'Process a date: punches and approved corrections become attendance records', expected: 'Date chosen; Submit',
    element: '.fi-modal-window:visible >> nth=-1', callouts: [{ loc: byId(hr, 'date'), n: 1, label: 'Date' }] });
await modal(hr).getByRole('button', { name: 'Submit', exact: true }).click();
await idle(hr, 2500);
console.log('    toast:', (await toasts(hr)).join(' / '));
await shot(s, hr, 'hr-admin/20-att-13-day-processed', { ...D3, screen: 'Attendance records (after Process day)',
    action: 'Submit', expected: 'Confirmation; records for 20 Oct with status, in/out, worked hours and exceptions',
    callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Confirmation' }, { loc: hr.locator('tbody tr').first(), n: 2, label: 'Attendance record' }] });
await closeToasts(hr);
await go(hr, '/admin/attendance-exception-centre');
await shot(s, hr, 'hr-admin/20-att-14-exception-centre', { ...D3, deck: [3, 12], screen: 'Exception centre',
    action: 'People › Time & leave › Exception centre', expected: 'Exception counts and the rows that need action',
    callouts: [{ loc: hr.getByText('Missing punch', { exact: false }), n: 1, label: 'Exception counts' }] });
await go(hr, '/admin/attendance-punches');
await shot(s, hr, 'hr-admin/20-att-15-punches', { ...D3, screen: 'Punches',
    action: 'All in Time & leave › Punches', expected: 'Raw punches with source (web, manual, device) and processing status',
    callouts: [{ loc: hr.locator('tbody tr').first(), n: 1, label: 'Punch' }] });

// 5. Employee sees the processed record.
await go(emp, '/admin/attendance-records');
await shot(s, emp, 'employee/20-att-16-my-attendance', { ...D, screen: 'Attendance records (own)',
    action: 'People › Time & leave › Attendance records', expected: 'The corrected day appears with in/out and worked time',
    callouts: [{ loc: emp.locator('tbody tr').first(), n: 1, label: 'Corrected day' }] });

await finish(s);
