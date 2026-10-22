// Payroll and statutory (H10, H11): control room, run, issues, breakdown, recalculate/validate (executed),
// approval/finalisation blocked by unverified statutory rules, auditor, set-up screens, statutory screens.
// Disposable training database (fictional people). Decks 3, 4, 7, 9, 11.
import { start, open, go, shot, finish, idle, modal, toasts, closeToasts, cancelModal, settle } from './lib.mjs';

const s = await start('60-payroll');
const step = async (name, fn) => { try { await fn(); } catch (e) { console.log(`  ✗ ${name}: ${String(e).split('\n')[0].slice(0, 200)}`); } };
const P = { deck: [3, 11], workflow: 'Payroll run and controls (payroll admin)', prereq: 'Payroll Admin role (payroll.*); salary assigned to employees' };
const ST = { deck: [3, 4], workflow: 'Statutory configuration and verification', prereq: 'Tenant HR Admin or Payroll Admin; statutory rules verified by two platform administrators' };

const pay = await open(s, 'payroll', '/admin/payroll-control-room');
await step('control room', () => shot(s, pay, 'hr-admin/60-pay-01-control-room', { ...P, deck: [3, 9, 11], screen: 'People › Pay › Control room (Payroll control room)',
    action: 'Open the payroll control room', expected: 'Readiness figures, the company card and the run register; the subheading lists statutory rules that are not verified, so runs cannot be finalised',
    callouts: [{ loc: pay.getByText(/cannot be finali[sz]ed/i), n: 1, label: 'Unverified rules warning' }, { loc: pay.getByRole('button', { name: 'Open payroll run' }), n: 2, label: 'Open payroll run' }, { loc: pay.getByRole('link', { name: 'Open run' }), n: 3, label: 'Open run' }] }));

await step('runs list', async () => {
    await go(pay, '/admin/payroll-runs');
    await shot(s, pay, 'hr-admin/60-pay-02-runs-list', { ...P, screen: 'People › Pay › Payroll runs',
        action: 'Open Payroll runs', expected: 'One run per company and month with status, employees, net pay',
        callouts: [{ loc: pay.locator('tbody tr').filter({ hasText: 'Oct 2026' }), n: 1, label: 'Oct 2026 run' }, { loc: pay.getByRole('button', { name: 'Open payroll run' }), n: 2, label: 'Open payroll run' }] });
});

await step('open run form', async () => {
    await pay.getByRole('button', { name: 'Open payroll run' }).first().click();
    await idle(pay, 1200);
    await shot(s, pay, 'hr-admin/60-pay-03-open-run-form', { ...P, screen: 'Open payroll run (dialog)',
        action: 'Open payroll run: choose company, year and month', expected: 'Opening a run calculates it for every employee with a salary (opened and cancelled here)',
        element: '.fi-modal-window:visible >> nth=-1', note: 'Dialog opened and cancelled; the existing October run is used for the rest of the workflow.' });
    await cancelModal(pay);
});

await step('run view', async () => {
    await go(pay, '/admin/payroll-runs/1');
    await shot(s, pay, 'hr-admin/60-pay-04-run-view', { ...P, deck: [3, 9, 11], screen: 'Payroll run (Oct 2026, Demo Technologies)',
        action: 'Open the run', expected: 'Status Calculated; totals (gross, deductions, net, PF, PT, TDS, employer cost); actions Recalculate, Validate, Set payment date',
        callouts: [{ loc: pay.getByText('Calculated · last changed', { exact: false }), n: 1, label: 'Run status' }, { loc: pay.getByRole('button', { name: 'Recalculate' }), n: 2, label: 'Recalculate' },
            { loc: pay.getByRole('button', { name: 'Validate' }), n: 3, label: 'Validate' }, { loc: pay.getByText('Net pay', { exact: true }), n: 4, label: 'Totals' }] });
});

await step('issues', async () => {
    const table = pay.locator('.fi-ta').last();
    await table.scrollIntoViewIfNeeded();
    await pay.evaluate(() => window.scrollBy(0, -80));
    await idle(pay, 500);
    await shot(s, pay, 'hr-admin/60-pay-05-employee-issues', { ...P, deck: [3, 7, 11], screen: 'Payroll run › Employees tab',
        action: 'Read the Issues column for each employee', expected: 'Every employee is in "exception": unverified statutory rules, pending statutory change, missing PAN / bank account, attendance not processed',
        note: 'Statutory issue codes are shown raw (e.g. unverified_statutory_rule): finding F12.',
        callouts: [{ loc: pay.locator('tbody tr').filter({ hasText: 'Anita Rao' }).getByText('unverified_statutory_rule', { exact: false }), n: 1, label: 'Raw issue codes (F12)' },
            { loc: pay.locator('tbody tr').filter({ hasText: 'Anita Rao' }).getByText('exception', { exact: true }), n: 2, label: 'Line status' },
            { loc: pay.locator('tbody tr').filter({ hasText: 'Anita Rao' }).getByRole('button', { name: 'Breakdown' }), n: 3, label: 'Breakdown' }] });
});

await step('breakdown', async () => {
    await pay.locator('tbody tr').filter({ hasText: 'Priya Nair' }).getByRole('button', { name: 'Breakdown' }).first().click();
    await idle(pay, 1500);
    await pay.evaluate(() => window.scrollTo(0, 0));
    await shot(s, pay, 'hr-admin/60-pay-06-breakdown', { ...P, deck: [3, 10, 11], screen: 'Payroll run › Breakdown (dialog)',
        action: 'Breakdown on one employee', expected: 'Earnings, deductions and employer contributions line by line, with the basis of each statutory line and its exception',
        note: 'Viewport capture of the open dialog (the breakdown scrolls).' });
    await cancelModal(pay);
    await pay.keyboard.press('Escape').catch(() => {});
});

await step('recalculate', async () => {
    await go(pay, '/admin/payroll-runs/1');
    await closeToasts(pay);
    await pay.getByRole('button', { name: 'Recalculate' }).first().click();
    await idle(pay, 1200);
    if (await pay.locator('.fi-modal-window:visible').count()) {
        await shot(s, pay, 'hr-admin/60-pay-07-recalculate-confirm', { ...P, screen: 'Recalculate (confirmation)', action: 'Recalculate', expected: 'Confirmation before the run is recalculated', element: '.fi-modal-window:visible >> nth=-1' });
        await modal(pay).locator('button[type=submit], .fi-modal-footer-actions button').filter({ hasNotText: 'Cancel' }).first().click();
        await idle(pay, 3000);
    }
    console.log('    toast:', (await toasts(pay)).join(' / '));
    await pay.evaluate(() => window.scrollTo(0, 0));
    await idle(pay, 400);
    await shot(s, pay, 'hr-admin/60-pay-08-recalculated', { ...P, screen: 'Payroll run (after Recalculate)', action: 'Recalculate (executed)',
        expected: 'Observed: "Calculated for 19 employee(s); 19 with exceptions" — every employee with a salary is recalculated and each still has blocking exceptions',
        callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Result' }] });
});

await step('validate', async () => {
    await closeToasts(pay);
    await pay.evaluate(() => window.scrollTo(0, 0));
    await pay.getByRole('button', { name: 'Validate' }).first().click();
    await idle(pay, 1500);
    console.log('    toast:', (await toasts(pay)).join(' / '));
    await shot(s, pay, 'hr-admin/60-pay-09-validate-blocked', { ...P, deck: [3, 7, 9, 11], screen: 'Payroll run › Validate', action: 'Validate (executed)',
        expected: 'Refused: "Not allowed — N employee(s) have blocking exceptions. Fix the inputs and recalculate." Approve and Finalize never appear while blocking exceptions remain',
        status: 'DISABLED BY SAFETY OR COMMERCIAL GATES', prereq: 'Statutory rules verified (0 of 24 today); PAN, bank account and attendance inputs complete',
        callouts: [{ sel: '.fi-no-notification', n: 1, label: 'Blocking message' }] });
});

await step('history tab', async () => {
    await closeToasts(pay);
    await pay.evaluate(() => window.scrollTo(0, 0));
    await pay.locator('button, [role=tab], a').filter({ hasText: /^\s*History\s*$/ }).first().click();
    await idle(pay, 1500);
    await pay.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    await idle(pay, 600);
    await shot(s, pay, 'hr-admin/60-pay-10-run-history', { ...P, deck: [3, 8, 10], screen: 'Payroll run › History tab', action: 'Open History',
        expected: 'Audited events for the run (calculated, recalculated) with who and when' });
});

await step('auditor', async () => {
    await go(pay, '/admin/payroll-auditor');
    await shot(s, pay, 'hr-admin/60-pay-11-payroll-auditor', { ...P, deck: [3, 9, 12], screen: 'People › Pay › Payroll Auditor', action: 'Open the Payroll Auditor for the October run',
        expected: 'Findings with severity and evidence (deterministic rules; read only)', note: 'Labelled "AI Payroll Auditor" but the checks are deterministic rules.',
        callouts: [{ loc: pay.getByRole('button', { name: 'Choose run' }), n: 1, label: 'Choose run' }, { loc: pay.getByText('FINDINGS', { exact: false }), n: 2, label: 'Findings' }] });
});

const SET = { deck: [3, 4], workflow: 'Payroll components and structures', prereq: 'Payroll Admin or Tenant HR Admin' };
await step('components', async () => {
    await go(pay, '/admin/salary-components');
    await shot(s, pay, 'hr-admin/60-pay-12-salary-components', { ...SET, screen: 'People › Pay › Salary components', action: 'Open Salary components',
        expected: 'Earnings and deductions with formula (e.g. Basic = ctc_monthly * 0.40); statutory lines are not configured here',
        callouts: [{ loc: pay.locator('tbody tr').filter({ hasText: 'BASIC' }), n: 1, label: 'Formula component' }, { loc: pay.getByRole('link', { name: 'New salary component' }), n: 2, label: 'New salary component' }] });
    await pay.getByRole('link', { name: 'New salary component' }).first().click();
    await idle(pay, 1500);
    await shot(s, pay, 'hr-admin/60-pay-13-new-component-form', { ...SET, screen: 'New salary component', action: 'Open the New salary component form (not saved)',
        expected: 'Name, code, type, calculation (fixed / formula), taxable, order; formulas are validated on save', fullPage: true });
});

await step('structures', async () => {
    const adm = await open(s, 'admin', '/admin/salary-structures');
    await shot(s, adm, 'hr-admin/60-pay-14-salary-structures', { ...SET, persona: 'admin', screen: 'People › Pay › Salary structures', action: 'Open Salary structures (Tenant HR Admin)',
        expected: 'Structures with versions; New salary structure is offered to the Tenant HR Admin only',
        callouts: [{ loc: adm.getByRole('link', { name: /New salary structure/ }), n: 1, label: 'New salary structure' }] });
    const first = adm.locator('tbody tr').first();
    if (await first.count()) {
        await first.click();
        await idle(adm, 1500);
        const v = adm.getByRole('button', { name: /Versions/ });
        if (await v.count()) { await v.first().click(); await idle(adm, 1200); }
        await shot(s, adm, 'hr-admin/60-pay-15-structure-versions', { ...SET, persona: 'admin', screen: 'Edit salary structure › Versions', action: 'Open the Standard structure (edit page) and its Versions tab',
            expected: 'Versions move Draft → Submitted → Approved; a version cannot start inside a finalised period', fullPage: true });
    }
    await adm.close();
});

await step('adjustments', async () => {
    await go(pay, '/admin/payroll-adjustments');
    await pay.getByRole('link', { name: 'New payroll adjustment' }).first().click().catch(async () => pay.getByRole('button', { name: 'New payroll adjustment' }).first().click());
    await idle(pay, 1500);
    const inModal = await pay.locator('.fi-modal-window:visible').count();
    await shot(s, pay, 'hr-admin/60-pay-16-new-adjustment', { ...P, workflow: 'Payroll adjustments', screen: 'New payroll adjustment', action: 'Open the New payroll adjustment form (cancelled)',
        expected: 'Employee, period, type, component, amount or days, taxable, note; approval only when the tenant setting requires it',
        ...(inModal ? { element: '.fi-modal-window:visible >> nth=-1' } : { fullPage: true }) });
    if (inModal) await cancelModal(pay);
});

await step('tax declarations', async () => {
    await go(pay, '/admin/tax-declarations');
    await shot(s, pay, 'hr-admin/60-pay-17-tax-declarations', { ...P, workflow: 'Tax declarations (payroll view)', screen: 'People › Pay › Tax declarations', action: 'Open Tax declarations',
        expected: 'Employee declarations per financial year; payroll verifies them. Employees cannot submit a declaration themselves (F13)', status: 'INCOMPLETE',
        callouts: [{ loc: pay.getByText('New employee tax declaration'), n: 1, label: 'New declaration' }] });
});

// Statutory (H11)
await step('compliance control room', async () => {
    await go(pay, '/admin/compliance-control-room');
    await shot(s, pay, 'hr-admin/60-stat-01-compliance-control-room', { ...ST, deck: [3, 4, 8, 9], screen: 'People › Pay › Control room (Compliance control room)', action: 'Open the compliance control room',
        expected: 'Rules this tenant needs and their verification state, establishments, returns; nothing can be exported while rules are unverified',
        status: 'DISABLED BY SAFETY OR COMMERCIAL GATES', callouts: [{ loc: pay.getByText('RULES THIS TENANT NEEDS', { exact: false }), n: 1, label: 'Rules needed' }, { loc: pay.getByText('No statutory returns yet', { exact: false }), n: 2, label: 'Returns' }], fullPage: true });
});
await step('compliance rules', async () => {
    await go(pay, '/admin/compliance-rules');
    await shot(s, pay, 'hr-admin/60-stat-02-compliance-rules', { ...ST, deck: [3, 4, 8, 10], screen: 'People › Pay › Compliance rules', action: 'Open Compliance rules (read only for tenants)',
        expected: 'Statutory rule versions (EPF, ESI, PT, LWF, TDS, gratuity…) with verification status; tenants cannot edit them',
        callouts: [{ loc: pay.locator('th').filter({ hasText: /verif/i }), n: 1, label: 'Verification status' }] });
});
await step('rule verifications', async () => {
    await go(pay, '/admin/rule-verifications');
    await shot(s, pay, 'hr-admin/60-stat-03-rule-verifications', { ...ST, deck: [3, 4, 8], screen: 'People › Pay › Rule verification', action: 'Open Rule verification',
        expected: 'Verification records: rules move draft → review → verified only with official evidence and two platform administrators; none verified yet',
        status: 'DISABLED BY SAFETY OR COMMERCIAL GATES' });
});
await step('rule notices', async () => {
    await go(pay, '/admin/rule-notices');
    await shot(s, pay, 'hr-admin/60-stat-04-rule-notices', { ...ST, screen: 'People › Pay › Regulatory notices', action: 'Open Regulatory notices',
        expected: 'Open notices of statutory changes that block finalisation until reviewed', callouts: [{ loc: pay.locator('tbody tr').first(), n: 1, label: 'Notice' }] });
});
const adm2 = await open(s, 'admin', '/admin/statutory-registrations');
await step('registrations', () => shot(s, adm2, 'hr-admin/60-stat-05-statutory-registrations', { ...ST, deck: [3, 4], persona: 'admin', screen: 'People › Pay › Statutory registrations', action: 'Open Statutory registrations (Tenant HR Admin)',
    expected: 'Registration numbers per establishment (PF, ESI, PT, LWF, TAN…) with Verify / Correct / New number',
    callouts: [{ loc: adm2.getByText('New registration'), n: 1, label: 'New registration' }, { loc: adm2.getByRole('button', { name: 'Verify' }), n: 2, label: 'Verify' }] }));
await step('establishment profiles', async () => {
    await go(adm2, '/admin/establishment-statutory-profiles');
    await shot(s, adm2, 'hr-admin/60-stat-06-establishment-profiles', { ...ST, deck: [3, 4], persona: 'admin', screen: 'People › Pay › Statutory profiles', action: 'Open establishment Statutory profiles',
        expected: 'Which statutory schemes apply to each establishment and from when', callouts: [{ loc: adm2.getByText('New profile'), n: 1, label: 'New profile' }] });
});
await step('epf returns', async () => {
    await go(adm2, '/admin/compliance/epf-returns');
    await shot(s, adm2, 'hr-admin/60-stat-07-epf-returns-empty', { ...ST, deck: [3, 4], persona: 'admin', screen: 'People › Pay › EPF returns (ECR)', action: 'Open EPF returns',
        expected: 'No returns: returns are generated from a finalised payroll, which is blocked until the rules are verified', status: 'DISABLED BY SAFETY OR COMMERCIAL GATES',
        callouts: [{ loc: adm2.getByRole('button', { name: 'Generate ECR' }), n: 1, label: 'Generate ECR' }] });
});

await finish(s);
