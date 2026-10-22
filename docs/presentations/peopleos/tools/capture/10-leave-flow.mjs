// Workflow: employee requests leave → manager decides in the Approval Center → employee sees the result.
// EXECUTED end to end on the disposable training database (fictional people). Decks 1, 2, 7, 9, 11.
import { start, open, go, shot, finish, idle, settle, modal, setData, dismissWelcome, toasts, closeToasts } from './lib.mjs';

const s = await start('10-leave-flow');
const D = { deck: [1, 11], workflow: 'Request leave (employee)' };

// 1. Employee Home: where the request starts.
const emp = await open(s, 'employee', '/admin');
await dismissWelcome(emp);
await shot(s, emp, 'employee/10-leave-01-home-request-leave', { ...D, deck: [1, 9, 11], screen: 'Home',
    action: 'Start a leave request from Home', expected: 'Request leave is the primary button on an employee Home',
    callouts: [{ sel: '[data-pos-action="request_leave_header"]', n: 1, label: 'Request leave' }, { loc: emp.getByText('Your next leave', { exact: false }), n: 2, label: 'Your next leave' }] });

// 2. Open the form and try to submit it empty: validation.
await emp.setViewportSize({ width: 1440, height: 1250 });
await emp.locator('[data-pos-action="request_leave_header"]').click();
await idle(emp, 1500);
await modal(emp).getByRole('button', { name: 'Submit request' }).click();
await idle(emp, 1200);
await shot(s, emp, 'employee/10-leave-02-validation', { ...D, deck: [1, 7, 11], screen: 'Request leave (dialog)',
    action: 'Submit the form without choosing a leave type or dates', expected: 'Required-field messages; nothing is submitted',
    element: '.fi-modal-window:visible >> nth=-1',
    callouts: [{ loc: modal(emp).locator('select').first(), n: 1, label: 'Required field prompt' }] });

// 3. A policy refusal: Earned leave during probation.
const typeSel = modal(emp).locator('select').first();
await typeSel.selectOption({ label: 'Earned leave (EL) · 17.5 available' });
await idle(emp, 900);
await setData(emp, 'from_date', '2026-12-14');
await setData(emp, 'to_date', '2026-12-15');
await modal(emp).locator('textarea').first().fill('Family visit (training example)');
await idle(emp, 900);
await modal(emp).getByRole('button', { name: 'Submit request' }).click();
await idle(emp, 1200);
console.log('    toast:', (await toasts(emp)).join(' / '));
await emp.setViewportSize({ width: 1440, height: 900 });
await shot(s, emp, 'employee/10-leave-03-policy-refusal', { ...D, deck: [1, 7, 11], screen: 'Request leave (dialog) with policy message',
    action: 'Submit Earned leave while still on probation', expected: 'Refused by the leave policy: "Earned leave is not available during probation."',
    note: 'The leave type list still offers Earned leave with its balance during probation; the policy refuses it on submit.',
    callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Policy message' }] });

// 4. The dialog closed: start again with an eligible type.
await closeToasts(emp);
await emp.setViewportSize({ width: 1440, height: 1250 });
await emp.locator('[data-pos-action="request_leave_header"]').click();
await idle(emp, 1500);
await modal(emp).locator('select').first().selectOption({ label: 'Casual leave (CL) · 5.0 available' });
await idle(emp, 900);
await setData(emp, 'from_date', '2026-12-14');
await setData(emp, 'to_date', '2026-12-15');
await modal(emp).locator('textarea').first().fill('Family visit (training example)');
await idle(emp, 1200);
const m = modal(emp);
await shot(s, emp, 'employee/10-leave-04-form-completed', { ...D, deck: [1, 9, 11], screen: 'Request leave (dialog)',
    action: 'Choose Casual leave, 14–15 Dec 2026, add a reason', expected: 'Before → After preview shows working days and the balance after approval',
    element: '.fi-modal-window:visible >> nth=-1',
    callouts: [
        { loc: modal(emp).locator('select').first(), n: 1, label: 'Leave type with balance' },
        { loc: m.locator('[id$="from_date"]').first(), n: 2, label: 'From date' },
        { loc: m.locator('[id$="to_date"]').first(), n: 3, label: 'To date' },
        { loc: m.getByText('Working days', { exact: false }), n: 4, label: 'Before → After preview' },
        { loc: m.locator('textarea').first(), n: 5, label: 'Reason' },
        { loc: m.getByRole('button', { name: 'Submit request' }), n: 6, label: 'Submit request' },
    ] });

// 5. Submit.
await m.getByRole('button', { name: 'Submit request' }).click();
await idle(emp, 1200);
console.log('    toast:', (await toasts(emp)).join(' / '));
await emp.setViewportSize({ width: 1440, height: 900 });
await shot(s, emp, 'employee/10-leave-05-submitted', { ...D, screen: 'Home (after submitting)',
    action: 'Submit request', expected: 'Confirmation message; the request waits for the manager',
    callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Confirmation' }] });

// 6. Employee checks the status.
await go(emp, '/admin/leave-requests');
await shot(s, emp, 'employee/10-leave-06-status-pending', { ...D, screen: 'Leave requests (own)',
    action: 'Open People › Time & leave › Leave requests to check the status', expected: 'The new request is listed as pending; Cancel is available while pending',
    callouts: [{ loc: emp.locator('tr', { hasText: 'Family visit' }).first(), n: 1, label: 'New request' }, { loc: emp.locator('tr', { hasText: 'Family visit' }).locator('.fi-badge').first(), n: 2, label: 'Status' }] });

// 6. Manager: Approval Center with the new request.
const D2 = { deck: [2, 9, 11], workflow: 'Approve leave (manager)' };
const mgr = await open(s, 'manager', '/admin/approvals');
await shot(s, mgr, 'manager/10-leave-07-approval-center', { ...D2, screen: 'My work › Approvals (Approval Center)',
    action: 'Open My work › Approvals', expected: 'Decisions grouped Urgent / This week / Later, most urgent first',
    callouts: [{ loc: mgr.getByRole('heading', { name: /decisions need you/ }), n: 1, label: 'Decisions waiting' }, { loc: mgr.getByText('Summarise what is pending'), n: 2, label: 'Summarise' }, { loc: mgr.getByRole('button', { name: /Priya Nair · Casual leave · 2 days.*14 Dec/ }).first(), n: 3, label: 'New request' }] });

await mgr.getByRole('button', { name: /Priya Nair · Casual leave · 2 days.*14 Dec/ }).first().click();
await idle(mgr, 1200);
await mgr.evaluate(() => window.scrollTo(0, 0));
await shot(s, mgr, 'manager/10-leave-08-decision-card', { ...D2, screen: 'Approval Center › decision card',
    action: 'Select the request to see its context', expected: 'Reason, team overlap, dates and the balance before → after',
    callouts: [
        { loc: mgr.getByText('Family visit (training example)').first(), n: 1, label: 'Reason' },
        { loc: mgr.getByText(/away on these dates|also away/), n: 2, label: 'Team overlap' },
        { loc: mgr.getByText('Casual leave balance'), n: 3, label: 'Balance before → after' },
        { loc: mgr.getByRole('button', { name: 'Approve', exact: true }).last(), n: 4, label: 'Approve' },
        { loc: mgr.getByRole('button', { name: 'Reject', exact: true }).last(), n: 5, label: 'Reject' },
    ] });

await mgr.getByRole('button', { name: 'Approve', exact: true }).last().click();
await idle(mgr, 1000);
await mgr.locator('main textarea:visible').last().fill('Approved. Enjoy the break.');
await shot(s, mgr, 'manager/10-leave-09-approve-confirm', { ...D2, screen: 'Approval Center › Approve',
    action: 'Approve opens a confirmation with an optional note', expected: 'Nothing is decided until Confirm',
    callouts: [{ loc: mgr.locator('main textarea:visible').last(), n: 1, label: 'Optional note' }, { loc: mgr.getByRole('button', { name: /^Confirm/ }).last(), n: 2, label: 'Confirm: Approve' }] });
await mgr.getByRole('button', { name: /^Confirm/ }).last().click();
await idle(mgr, 1500);
console.log('    toast:', (await toasts(mgr)).join(' / '));
await mgr.evaluate(() => window.scrollTo(0, 0));
await shot(s, mgr, 'manager/10-leave-10-approved', { ...D2, screen: 'Approval Center (after the decision)',
    action: 'Confirm: Approve', expected: 'Confirmation; the item leaves the queue and the count drops',
    callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Decision recorded' }, { loc: mgr.getByRole('heading', { name: /decisions? need/ }), n: 2, label: 'Remaining decisions' }] });

// 7. Reject with a reason: the reason is mandatory.
await closeToasts(mgr);
const D3 = { deck: [2, 7, 11], workflow: 'Reject leave with a reason (manager)' };
await mgr.getByRole('button', { name: /Rohan Pillai · Casual leave/ }).first().click();
await idle(mgr, 1000);
await mgr.getByRole('button', { name: 'Reject', exact: true }).last().click();
await idle(mgr, 800);
await mgr.getByRole('button', { name: /^Confirm/ }).last().click();
await idle(mgr, 1000);
await shot(s, mgr, 'manager/10-leave-11-reject-reason-required', { ...D3, screen: 'Approval Center › Reject',
    action: 'Confirm a rejection without a reason', expected: 'The rejection is refused until a reason is entered (the requester sees it)',
    callouts: [{ loc: mgr.getByText(/Reason \(required/i), n: 1, label: 'Reason required' }, { loc: mgr.locator('main textarea:visible').last(), n: 2, label: 'Reason' }] });
await mgr.locator('main textarea:visible').last().fill('Release week for the Platform team: please choose another date.');
await mgr.getByRole('button', { name: /^Confirm/ }).last().click();
await idle(mgr, 1500);
console.log('    toast:', (await toasts(mgr)).join(' / '));
await shot(s, mgr, 'manager/10-leave-12-rejected', { ...D3, screen: 'Approval Center (after rejecting)',
    action: 'Confirm: Reject with a reason', expected: 'Rejection recorded; the item leaves the queue',
    callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Decision recorded' }] });

// 8. Employee sees the result and the balance.
const D4 = { deck: [1, 11], workflow: 'Request leave (employee): result' };
await go(emp, '/admin/leave-requests');
await shot(s, emp, 'employee/10-leave-13-status-approved', { ...D4, screen: 'Leave requests (own)',
    action: 'Check the status after the manager decided', expected: 'Status Approved, Reviewed by Amit Verma',
    callouts: [{ loc: emp.locator('tr', { hasText: 'Family visit' }).locator('.fi-badge').first(), n: 1, label: 'Approved' }] });
await go(emp, '/admin/leave-balances');
await shot(s, emp, 'employee/10-leave-14-balances', { ...D4, screen: 'Leave balances (own)',
    action: 'Open Balances', expected: 'Casual leave: Used and Available updated',
    callouts: [{ loc: emp.locator('tr', { hasText: 'Casual leave' }).first(), n: 1, label: 'Casual leave row' }] });
await go(emp, '/admin/leave-transactions');
await shot(s, emp, 'employee/10-leave-15-ledger', { ...D4, deck: [1, 3, 10], screen: 'Leave ledger entries (own)',
    action: 'Open Transactions', expected: 'Append-only ledger entries explain every balance movement',
    callouts: [{ loc: emp.locator('tbody tr').first(), n: 1, label: 'Latest movement' }] });

await finish(s);
