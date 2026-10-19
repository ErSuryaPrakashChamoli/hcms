import { start, open, annotate, clearCallouts, shot, record, finish, settle } from './lib.mjs';
const s = await start('ppl');
const only = process.argv[2] ? process.argv[2].split(',') : null;
const want = (n) => !only || only.includes(n);
async function step(name, fn) { if (!want(name)) return; try { await fn(); console.log('OK', name); } catch (e) { console.log('FAIL', name, e.message.split('\n')[0]); } }
const rec = (name, persona, path, caption) => record(s, { name, persona, path, caption });
async function openAction(p, group, item) {
  await p.locator('.pos-360-actions').getByRole('button', { name: group, exact: true }).click();
  await p.waitForTimeout(600);
  await p.locator('.fi-dropdown-panel:visible').getByRole('button', { name: item }).or(p.locator('.fi-dropdown-panel:visible').getByRole('menuitem', { name: item })).first().click({ force: true });
  await p.waitForTimeout(1500);
}
const modal = (p) => p.locator('.fi-modal-window:visible').last();

await step('09', async () => {
  const p = await open(s, 'hr', '/admin/employees/3');
  await openAction(p, 'Action', 'Transfer / promote');
  const m1 = modal(p);
  await shot(p, 'ppl-09a-transfer-context', { element: m1 });
  await m1.getByRole('button', { name: 'Next' }).click(); await p.waitForTimeout(1200);
  // choose Department = Design
  const dept = m1.locator('.fi-fo-field', { hasText: 'Department' }).first();
  await dept.locator('.fi-select-input-btn, button[role=combobox]').first().click(); await p.waitForTimeout(500);
  const search = p.locator('.fi-select-input-search-ctn input:visible, input[type=search]:visible').last();
  if (await search.count()) { await search.fill('Design'); await p.waitForTimeout(900); }
  await p.locator('[role=option]:visible', { hasText: 'Design' }).first().click(); await p.waitForTimeout(1200);
  await m1.getByRole('button', { name: 'Next' }).click(); await p.waitForTimeout(1500);
  await shot(p, 'ppl-09-transfer-review', { element: modal(p) });
  rec('ppl-09-transfer-review', 'hr', '/admin/employees/3', 'Transfer or promote, Review step: Before → After (not confirmed)');
  await p.close();
});

await step('10', async () => {
  const p = await open(s, 'hr', '/admin/employees/3');
  await openAction(p, 'Action', 'Change lifecycle state');
  const m = modal(p);
  await m.locator('.fi-fo-field', { hasText: 'New state' }).locator('select, .fi-select-input-btn, button[role=combobox]').first().click().catch(()=>{});
  await p.waitForTimeout(800);
  await shot(p, 'ppl-10-lifecycle-change', { element: m });
  rec('ppl-10-lifecycle-change', 'hr', '/admin/employees/3', 'Change lifecycle state: only the moves allowed from Probation are offered');
  await p.keyboard.press('Escape');
  await p.close();
});

await step('11', async () => {
  const p = await open(s, 'hr', '/admin/employees/5#records');
  await p.waitForTimeout(2500);
  await p.locator('.fi-tabs-item', { hasText: 'Operations' }).last().click(); await p.waitForTimeout(3000);
  const docs = p.locator('.fi-ta', { hasText: 'Upload' }).first();
  const y = await docs.evaluate(e => e.getBoundingClientRect().top + window.scrollY);
  await p.evaluate((y) => window.scrollTo(0, y - 170), y);
  await p.waitForTimeout(800);
  const m = await annotate(p, [
    { loc: p.getByRole('button', { name: 'Upload' }).first(), n: 1 },
    { loc: docs.locator('th', { hasText: 'Status' }).first(), n: 2 },
    { loc: docs.getByRole('button', { name: 'Verify' }).first(), n: 3 },
    { loc: docs.getByRole('button', { name: 'Reject' }).first(), n: 4 },
    { loc: docs.getByRole('link', { name: 'Download' }).first().or(docs.getByText('Download').first()), n: 5 },
  ]);
  if (m.length) console.log('MISSING', m);
  await shot(p, 'ppl-11-360-documents');
  rec('ppl-11-360-documents', 'hr', '/admin/employees/5#records', 'Operations tab of an Employee 360: Documents with Upload, Verify and Reject');
  await p.close();
});

await step('12', async () => {
  const p = await open(s, 'manager', '/admin/employees/3');
  const m = await annotate(p, [
    { loc: p.locator('.pos-360-actions'), n: 1 },
    { loc: p.locator('.pos-360-viewer'), n: 2 },
    { loc: p.locator('.pos-360-next'), n: 3 },
  ]);
  if (m.length) console.log('MISSING', m);
  await shot(p, 'ppl-12-360-manager');
  rec('ppl-12-360-manager', 'manager', '/admin/employees/3', 'Employee 360 seen by the line manager (Your team panel; no change actions)');
  await p.close();
});

await step('13', async () => {
  const p = await open(s, 'employee', '/admin/my-hr');
  const m = await annotate(p, [{ loc: p.getByRole('link', { name: 'Your record' }).or(p.getByRole('button', { name: 'Your record' })).first(), n: 1 }]);
  if (m.length) console.log('MISSING', m);
  await shot(p, 'ppl-13a-my-hr-your-record', { clip: { x: 240, y: 60, width: 1200, height: 330 } });
  await p.close();
  const q = await open(s, 'employee', '/admin/employees/4');
  const m2 = await annotate(q, [
    { loc: q.locator('.pos-360-identity'), n: 1 },
    { loc: q.locator('.pos-360-viewer'), n: 2 },
    { loc: q.locator('.pos-360-nav'), n: 3 },
  ]);
  if (m2.length) console.log('MISSING', m2);
  await shot(q, 'ppl-13-own-record');
  rec('ppl-13-own-record', 'employee', '/admin/employees/4', 'An employee viewing their own record (read-only, Your record panel)');
  await q.close();
});

await step('14', async () => {
  const p = await open(s, 'hr', '/admin/employees/3');
  await openAction(p, 'Action', 'Initiate background check');
  await shot(p, 'ppl-14-bgv-initiate', { element: modal(p) });
  rec('ppl-14-bgv-initiate', 'hr', '/admin/employees/3', 'Initiate background check form (not submitted)');
  await p.close();
});
await finish(s);
