// Pay chapter screenshots (payroll, compliance, compensation). Read-only: modals are opened, filled with fictional
// values and cancelled; no seeded record is calculated, validated, approved, finalised or changed.
import { start, open, annotate, clearCallouts, shot, record, finish, settle } from './lib.mjs';
const only = process.argv.slice(2);
const want = (n) => only.length === 0 || only.includes(n);
const s = await start('pay');
const log = (...a) => console.log(...a);
const check = (name, missing) => { if (missing.length) log('MISSING in', name, missing); };
const modalText = async (page) => (await page.locator('.fi-modal-window:visible').last().innerText().catch(() => '')).replace(/\n+/g, ' | ').slice(0, 1500);
const closeModal = async (page) => { await page.keyboard.press('Escape'); await page.waitForTimeout(500); };

if (want('01')) {
  const page = await open(s, 'payroll', '/admin/payroll-control-room');
  const m = await annotate(page, [
    { loc: page.getByText('Statutory rules EPF', { exact: false }).first(), n: 1 },
    { loc: page.getByRole('button', { name: 'Open payroll run' }).first(), n: 2 },
    { loc: page.locator('.pos-figures').first(), n: 3 },
    { loc: page.locator('.fi-section').filter({ hasText: 'Payroll for' }).first(), n: 4 },
  ]);
  check('01', m);
  await shot(page, 'pay-01-payroll-control-room');
  record(s, { name: 'pay-01-payroll-control-room', persona: 'payroll', path: '/admin/payroll-control-room', caption: 'Payroll control room' });
  await page.close();
}

if (want('02') && only.includes('02')) { // not kept in the chapter
  const page = await open(s, 'payroll', '/admin/payroll-control-room');
  await page.getByRole('button', { name: 'Open payroll run' }).first().click();
  await page.waitForSelector('.fi-modal-window', { state: 'visible' });
  await settle(page, 800);
  const sel = page.locator('.fi-modal-window:visible select').first();
  if (await sel.count()) { await sel.selectOption({ label: 'Demo Technologies' }); } else { log('02: no native select'); }
  await settle(page, 500);
  log('02 modal:', await modalText(page));
  await shot(page, 'pay-02-open-payroll-run', { element: page.locator('.fi-modal-window:visible').last() });
  record(s, { name: 'pay-02-open-payroll-run', persona: 'payroll', path: '/admin/payroll-control-room', caption: 'Open payroll run dialog (cancelled)' });
  await closeModal(page);
  await page.close();
}

if (want('03')) {
  const page = await open(s, 'payroll', '/admin/payroll-runs/1');
  const m = await annotate(page, [
    { loc: page.getByRole('button', { name: 'Recalculate' }).first(), n: 1 },
    { loc: page.getByRole('button', { name: 'Validate' }).first(), n: 2 },
    { loc: page.getByRole('button', { name: 'Set payment date' }).first(), n: 3 },
    { loc: page.locator('.fi-section').filter({ hasText: 'Employees with exceptions' }).first(), n: 4 },
    { loc: page.locator('.fi-section').filter({ hasText: 'Employer cost' }).first(), n: 5 },
  ]);
  check('03', m);
  await shot(page, 'pay-03-payroll-run');
  record(s, { name: 'pay-03-payroll-run', persona: 'payroll', path: '/admin/payroll-runs/1', caption: 'A calculated payroll run' });
  await page.close();
}

if (want('04')) {
  const page = await open(s, 'payroll', '/admin/payroll-runs/1');
  const table = page.locator('.fi-ta').filter({ hasText: 'Paid days' }).first();
  await table.scrollIntoViewIfNeeded();
  await settle(page, 500);
  const m = await annotate(page, [
    { loc: table.locator('.fi-badge', { hasText: 'exception' }).first(), n: 1 },
    { loc: table.getByText('Attendance not processed', { exact: false }).first(), n: 2 },
    { loc: table.getByRole('button', { name: 'Breakdown' }).first(), n: 3 },
  ]);
  check('04', m);
  await page.evaluate(() => { const t = [...document.querySelectorAll('.fi-ta')].find(e => e.innerText.includes('Paid days') || e.innerText.includes('PAID DAYS')); window.scrollBy(0, t.getBoundingClientRect().top - 110); });
  await settle(page, 400);
  await annotate(page, [
    { loc: table.locator('.fi-badge', { hasText: 'exception' }).first(), n: 1 },
    { loc: table.getByText('Attendance not processed', { exact: false }).first(), n: 2 },
    { loc: table.getByRole('button', { name: 'Breakdown' }).first(), n: 3 },
  ]);
  await shot(page, 'pay-04-run-employees');
  record(s, { name: 'pay-04-run-employees', persona: 'payroll', path: '/admin/payroll-runs/1', caption: 'Employees tab of a run with blocking issues' });
  await clearCallouts(page);
  await table.getByRole('button', { name: 'Breakdown' }).nth(3).click();
  await page.waitForSelector('.fi-modal-window', { state: 'visible' });
  await settle(page, 1000);
  log('05 modal:', await modalText(page));
  await shot(page, 'pay-05-entry-breakdown');
  record(s, { name: 'pay-05-entry-breakdown', persona: 'payroll', path: '/admin/payroll-runs/1', caption: 'Breakdown of one employee result' });
  await closeModal(page);
  await page.close();
}

if (want('06')) {
  const page = await open(s, 'payroll', '/admin/payroll-auditor');
  const m = await annotate(page, [
    { loc: page.getByRole('button', { name: 'Choose run' }).first(), n: 1 },
    { loc: page.locator('.fi-section').first(), n: 2 },
    { loc: page.locator('.fi-section').filter({ hasText: 'Findings' }).locator('.fi-badge').first(), n: 3 },
  ]);
  check('06', m);
  await shot(page, 'pay-06-payroll-auditor');
  record(s, { name: 'pay-06-payroll-auditor', persona: 'payroll', path: '/admin/payroll-auditor', caption: 'AI Payroll Auditor findings' });
  await page.close();
}

if (want('07')) {
  const page = await open(s, 'payroll', '/admin/salary-components/create');
  await page.getByLabel('Name').first().fill('Night shift allowance (example)');
  await page.getByLabel('Code').first().fill('NIGHT_EX');
  const calc = page.locator('select').filter({ has: page.locator('option', { hasText: 'Formula' }) }).first();
  await calc.selectOption({ label: 'Formula' });
  await settle(page, 800);
  await page.getByLabel('Formula').first().fill('basic * 0.05');
  await settle(page, 400);
  log('07 text:', (await page.locator('main').innerText()).replace(/\n+/g, ' | ').slice(0, 1800));
  const m = await annotate(page, [
    { loc: page.getByLabel('Code').first(), n: 1 },
    { loc: calc, n: 2 },
    { loc: page.getByLabel('Formula').first(), n: 3 },
    { loc: page.locator('.fi-section').filter({ hasText: 'Properties' }).first(), n: 4 },
  ]);
  check('07', m);
  await page.evaluate(() => window.scrollTo(0, 0));
  await settle(page, 300);
  await annotate(page, [
    { loc: page.getByLabel('Code').first(), n: 1 },
    { loc: calc, n: 2 },
    { loc: page.getByLabel('Formula').first(), n: 3 },
    { loc: page.locator('.fi-section').filter({ hasText: 'Properties' }).first(), n: 4 },
  ]);
  await shot(page, 'pay-07-salary-component-form');
  record(s, { name: 'pay-07-salary-component-form', persona: 'payroll', path: '/admin/salary-components/create', caption: 'New salary component with a formula (not saved)' });
  await page.close();
}

if (want('08')) {
  const page = await open(s, 'admin', '/admin/salary-structures/1/edit');
  const versions = page.locator('.fi-ta').filter({ hasText: 'Components' }).first();
  await page.evaluate(() => { const t = [...document.querySelectorAll('.fi-ta')].pop(); window.scrollBy(0, t.getBoundingClientRect().top - 160); });
  await settle(page, 500);
  const m = await annotate(page, [
    { loc: page.getByRole('button', { name: 'New version' }).first(), n: 1 },
    { loc: page.locator('.fi-ta .fi-badge', { hasText: 'Active' }).first(), n: 2 },
    { loc: page.getByRole('button', { name: 'Components' }).first(), n: 3 },
  ]);
  check('08', m);
  await shot(page, 'pay-08-salary-structure-versions');
  record(s, { name: 'pay-08-salary-structure-versions', persona: 'admin', path: '/admin/salary-structures/1/edit', caption: 'Versions of the Standard salary structure' });
  await page.close();
}

if (want('09')) {
  const page = await open(s, 'payroll', '/admin/payroll-adjustments');
  await page.getByRole('button', { name: 'New payroll adjustment' }).first().click();
  await page.waitForSelector('.fi-modal-window', { state: 'visible' });
  await settle(page, 800);
  const modal = page.locator('.fi-modal-window:visible').last();
  const selects = modal.locator('select');
  log('09 selects:', await selects.count());
  for (let i = 0; i < await selects.count(); i++) log('  select', i, (await selects.nth(i).innerText()).replace(/\n+/g, ' / ').slice(0, 200));
  const type = modal.locator('select').filter({ has: page.locator('option', { hasText: 'Additional earning' }) }).first();
  await type.selectOption({ label: 'Additional earning' });
  await settle(page, 800);
  await modal.getByLabel('Name').first().fill('Referral bonus (example)');
  await modal.getByLabel('Amount').first().fill('5000');
  await modal.getByLabel('Note').first().fill('Fictional example for the handbook');
  await settle(page, 400);
  log('09 modal:', await modalText(page));
  await shot(page, 'pay-09-adjustment-form', { element: modal });
  record(s, { name: 'pay-09-adjustment-form', persona: 'payroll', path: '/admin/payroll-adjustments', caption: 'New payroll adjustment dialog (cancelled)' });
  await closeModal(page);
  await page.close();
}

if (want('10')) {
  const page = await open(s, 'employee', '/admin/tax-declarations');
  await page.getByRole('button', { name: 'New employee tax declaration' }).first().click();
  await page.waitForSelector('.fi-modal-window', { state: 'visible' });
  await settle(page, 800);
  const modal = page.locator('.fi-modal-window:visible').last();
  log('10 modal before:', await modalText(page));
  const regime = modal.locator('select').filter({ has: page.locator('option', { hasText: 'Old regime' }) }).first();
  await regime.selectOption({ label: 'Old regime' });
  const keys = modal.getByPlaceholder(/./).all();
  const inputs = modal.locator('table input, .fi-fo-key-value input');
  log('10 kv inputs', await inputs.count());
  if (await inputs.count() >= 2) { await inputs.nth(0).fill('80C'); await inputs.nth(1).fill('150000'); }
  await settle(page, 400);
  log('10 modal:', await modalText(page));
  const m = await annotate(page, [
    { loc: regime, n: 1 },
    { loc: modal.getByLabel('Previous employer taxable income').first(), n: 2 },
    { loc: modal.locator('.fi-fo-key-value').first(), n: 3 },
  ]);
  check('10', m);
  await shot(page, 'pay-10-tax-declaration-form', { element: modal });
  record(s, { name: 'pay-10-tax-declaration-form', persona: 'employee', path: '/admin/tax-declarations', caption: 'Employee tax declaration dialog (cancelled)' });
  await closeModal(page);
  await page.close();
}

if (want('11')) {
  const page = await open(s, 'employee', '/admin/my-day');
  const m = await annotate(page, [
    { loc: page.getByText('Latest payslip', { exact: false }).first().locator('xpath=ancestor::*[contains(@class,"pos-") or contains(@class,"fi-section")][1]'), n: 1 },
    { loc: page.getByText('Tax declaration', { exact: true }).first().locator('xpath=ancestor::*[self::li or self::a or self::div][1]'), n: 2 },
  ]);
  check('11', m);
  await shot(page, 'pay-11-my-day-pay', { fullPage: true });
  record(s, { name: 'pay-11-my-day-pay', persona: 'employee', path: '/admin/my-day', caption: 'My Day: latest payslip and tax declaration reminder' });
  await page.close();
}

if (want('12')) {
  const page = await open(s, 'admin', '/admin/compliance-control-room');
  const m = await annotate(page, [
    { loc: page.getByText('Verified-rule enforcement is ON', { exact: false }).first(), n: 1 },
    { loc: page.locator('.pos-figures').nth(0), n: 2 },
    { loc: page.locator('.pos-figures').nth(1), n: 3 },
  ]);
  check('12', m);
  await shot(page, 'pay-12-compliance-control-room');
  record(s, { name: 'pay-12-compliance-control-room', persona: 'admin', path: '/admin/compliance-control-room', caption: 'Compliance control room' });
  await clearCallouts(page);
  const sec = page.locator('.fi-section').filter({ hasText: 'Rules this tenant needs' }).first();
  await page.evaluate(() => { const t = [...document.querySelectorAll('.fi-section')].find(e => /Rules this tenant needs/i.test(e.innerText)); window.scrollBy(0, t.getBoundingClientRect().top - 110); });
  await settle(page, 400);
  const m2 = await annotate(page, [
    { loc: sec, n: 1 },
    { loc: page.locator('.fi-section').filter({ hasText: 'Missing registrations' }).last(), n: 2 },
  ]);
  check('12b', m2);
  await shot(page, 'pay-13-rules-tenant-needs');
  record(s, { name: 'pay-13-rules-tenant-needs', persona: 'admin', path: '/admin/compliance-control-room', caption: 'Rules this tenant needs and establishment status' });
  await page.close();
}

if (want('14')) {
  const page = await open(s, 'payroll', '/admin/compliance-rules', { width: 2400, height: 1000 });
  await page.evaluate(() => { const th = [...document.querySelectorAll('th')].find(e => /^\s*Verification\s*$/i.test(e.innerText)); const c = th.closest('.fi-ta-content') || th.closest('table').parentElement; c.scrollLeft = 0; const r = th.getBoundingClientRect(); const cr = c.getBoundingClientRect(); c.scrollLeft = Math.max(0, r.right - cr.right + 380); });
  await settle(page, 400);
  const m = await annotate(page, [
    { loc: page.locator('td .fi-badge', { hasText: 'In review' }).first(), n: 1 },
    { loc: page.getByRole('button', { name: 'Parameters' }).first(), n: 2 },
    { loc: page.getByRole('button', { name: 'Evidence & coverage' }).nth(1), n: 3 },
  ]);
  check('14', m);
  await shot(page, 'pay-14-compliance-rules');
  record(s, { name: 'pay-14-compliance-rules', persona: 'payroll', path: '/admin/compliance-rules', caption: 'Compliance rules with their verification status' });
  await clearCallouts(page);
  await page.getByRole('button', { name: 'Evidence & coverage' }).nth(1).click();
  await page.waitForSelector('.fi-modal-window', { state: 'visible' });
  await settle(page, 1000);
  log('15 modal:', (await modalText(page)).slice(0, 1200));
  await shot(page, 'pay-15-rule-evidence');
  record(s, { name: 'pay-15-rule-evidence', persona: 'payroll', path: '/admin/compliance-rules', caption: 'Evidence and coverage of one rule version' });
  await closeModal(page);
  await page.close();
}

if (want('16')) {
  const page = await open(s, 'admin', '/admin/statutory-registrations', { width: 1920, height: 1000 });
  await page.evaluate(() => { const th = [...document.querySelectorAll('th')].find(e => /^\s*Verification\s*$/i.test(e.innerText)); const c = th.closest('.fi-ta-content') || th.closest('table').parentElement; c.scrollLeft = 0; const r = th.getBoundingClientRect(); const cr = c.getBoundingClientRect(); c.scrollLeft = Math.max(0, r.right - cr.right + 380); });
  await settle(page, 400);
  const m = await annotate(page, [
    { loc: page.getByRole('button', { name: 'New registration' }).first(), n: 1 },
    { loc: page.locator('td .fi-badge', { hasText: 'unverified' }).first(), n: 2 },
    { loc: page.getByRole('button', { name: 'Verify' }).first(), n: 3 },
  ]);
  check('16', m);
  await shot(page, 'pay-16-statutory-registrations');
  record(s, { name: 'pay-16-statutory-registrations', persona: 'admin', path: '/admin/statutory-registrations', caption: 'Statutory registrations' });
  await page.close();
}

if (want('17')) {
  const page = await open(s, 'admin', '/admin/compliance/epf-returns');
  await page.getByRole('button', { name: 'Generate ECR' }).first().click();
  await page.waitForSelector('.fi-modal-window', { state: 'visible' });
  await settle(page, 800);
  const modal = page.locator('.fi-modal-window:visible').last();
  const est = modal.locator('select').first();
  const opts = await est.locator('option').allInnerTexts();
  log('17 options', opts);
  const label = opts.find(o => /Demo Technologies/.test(o));
  if (label) await est.selectOption({ label });
  await settle(page, 400);
  log('17 modal:', await modalText(page));
  await shot(page, 'pay-17-generate-ecr', { element: modal });
  record(s, { name: 'pay-17-generate-ecr', persona: 'admin', path: '/admin/compliance/epf-returns', caption: 'Generate ECR dialog (cancelled)' });
  await closeModal(page);
  await page.close();
}

if (want('18')) {
  const page = await open(s, 'hr', '/admin/employees/4');
  await page.locator('nav.pos-360-nav a', { hasText: 'Records' }).first().click();
  await settle(page, 1200);
  await page.locator('[role=tab]:visible', { hasText: 'Rewards' }).first().click();
  await settle(page, 2000);
  const tbl = page.locator('.fi-ta').filter({ hasText: 'Annual CTC' }).first();
  await page.evaluate(() => { const t = [...document.querySelectorAll('.fi-ta')].find(e => /ANNUAL CTC|Annual CTC/.test(e.innerText)); window.scrollBy(0, t.getBoundingClientRect().top - 200); });
  await settle(page, 500);
  const m = await annotate(page, [
    { loc: page.locator('[role=tab]:visible', { hasText: 'Rewards' }).first(), n: 1 },
    { loc: page.getByRole('button', { name: 'Propose compensation change' }).first(), n: 2 },
    { loc: page.getByRole('button', { name: 'Propose from position' }).first(), n: 3 },
    { loc: tbl.locator('.fi-badge', { hasText: 'Current' }).first(), n: 4 },
  ]);
  check('18', m);
  await shot(page, 'pay-18-employee-compensation-tab');
  record(s, { name: 'pay-18-employee-compensation-tab', persona: 'hr', path: '/admin/employees/4 (Records › Rewards)', caption: 'Compensation tab on an employee record' });
  await page.close();
  const p2 = await open(s, 'hr', '/admin/employees/4', { width: 1441, height: 1560 });
  await p2.locator('nav.pos-360-nav a', { hasText: 'Records' }).first().click();
  await settle(p2, 1200);
  await p2.locator('[role=tab]:visible', { hasText: 'Rewards' }).first().click();
  await settle(p2, 2000);
  await reopenPropose(p2);
}
async function reopenPropose(page) {
  await page.getByRole('button', { name: 'Propose compensation change' }).first().click();
  await page.waitForSelector('.fi-modal-window', { state: 'visible' });
  await settle(page, 800);
  const modal = page.locator('.fi-modal-window:visible').last();
  log('19 modal before:', await modalText(page));
  await modal.getByLabel('Annual CTC').first().fill('990000');
  await modal.getByLabel('Reason').first().fill('Annual increment for 2027 (fictional example)');
  await settle(page, 400);
  log('19 modal:', await modalText(page));
  await shot(page, 'pay-19-propose-compensation-change');
  record(s, { name: 'pay-19-propose-compensation-change', persona: 'hr', path: '/admin/employees/4', caption: 'Propose compensation change dialog (cancelled)' });
  await closeModal(page);
  await page.close();
}

if (want('20')) {
  const page = await open(s, 'admin', '/admin/compensation-changes');
  const m = await annotate(page, [
    { loc: page.locator('th', { hasText: 'Status' }).first(), n: 1 },
    { loc: page.locator('th', { hasText: 'Proposed CTC' }).first(), n: 2 },
    { loc: page.locator('th', { hasText: 'Proposed by' }).first(), n: 3 },
  ]);
  check('20', m);
  await shot(page, 'pay-20-compensation-changes');
  record(s, { name: 'pay-20-compensation-changes', persona: 'admin', path: '/admin/compensation-changes', caption: 'Compensation changes list' });
  await page.close();
}

if (want('21')) {
  const page = await open(s, 'employee', '/admin/my-compensation');
  const m = await annotate(page, [
    { loc: page.locator('.fi-section').filter({ hasText: 'In force today' }).first(), n: 1 },
    { loc: page.locator('.fi-section').filter({ hasText: 'History and scheduled changes' }).first(), n: 2 },
  ]);
  check('21', m);
  await shot(page, 'pay-21-my-compensation');
  record(s, { name: 'pay-21-my-compensation', persona: 'employee', path: '/admin/my-compensation', caption: 'My compensation (employee view)' });
  await page.close();
}

if (want('22') && only.includes('22')) { // not kept in the chapter
  const page = await open(s, 'admin', '/admin/compensation-analytics');
  const m = await annotate(page, [
    { loc: page.locator('.fi-section').filter({ hasText: 'Filters' }).first(), n: 1 },
    { loc: page.getByText('Suppressed', { exact: false }).first(), n: 2 },
    { loc: page.getByRole('button', { name: 'Export compensation (CSV)' }).first(), n: 3 },
  ]);
  check('22', m);
  await shot(page, 'pay-22-compensation-analytics');
  record(s, { name: 'pay-22-compensation-analytics', persona: 'admin', path: '/admin/compensation-analytics', caption: 'Compensation analytics with small-group suppression' });
  await page.close();
}

await finish(s);
