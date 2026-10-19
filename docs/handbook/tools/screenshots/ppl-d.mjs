import { start, open, annotate, clearCallouts, shot, record, finish, settle, BASE } from './lib.mjs';
const s = await start('ppl');
const only = process.argv[2] ? process.argv[2].split(',') : null;
const want = (n) => !only || only.includes(n);
async function step(name, fn) { if (!want(name)) return; try { await fn(); console.log('OK', name); } catch (e) { console.log('FAIL', name, e.message.split('\n')[0]); } }
const rec = (name, persona, path, caption) => record(s, { name, persona, path, caption });
const modal = (p) => p.locator('.fi-modal-window:visible').last();

await step('16', async () => {
  const p = await open(s, 'admin', '/admin/employee-imports/1');
  const mm = await annotate(p, [
    { loc: p.locator('main').getByRole('button', { name: 'Inspect file' }), n: 1 },
    { loc: p.locator('main').getByRole('button', { name: 'Map columns' }), n: 2 },
    { loc: p.locator('.fi-section', { hasText: 'To review' }).first(), n: 3 },
    { loc: p.locator('.fi-section', { hasText: 'Mapping' }).first(), n: 4 },
  ]);
  if (mm.length) console.log('MISSING', mm);
  await shot(p, 'ppl-16-import-staged', { element: 'main' });
  rec('ppl-16-import-staged', 'admin', '/admin/employee-imports/1', 'A fictional import after Inspect file: rows staged, mapping suggested, nothing written to employees');
  await p.close();
});

await step('17', async () => {
  const p = await open(s, 'hr', '/admin/onboarding-plans');
  const mm = await annotate(p, [
    { loc: p.locator('button[aria-label=Filter]').first(), n: 1 },
    { loc: p.locator('th', { hasText: 'Progress' }).first(), n: 2 },
    { loc: p.locator('th', { hasText: 'Overdue' }).first(), n: 3 },
    { loc: p.locator('main a', { hasText: 'Open' }).first(), n: 4 },
  ]);
  if (mm.length) console.log('MISSING', mm);
  await shot(p, 'ppl-17-onboarding-plans');
  rec('ppl-17-onboarding-plans', 'hr', '/admin/onboarding-plans', 'Onboarding plans in progress, with progress and overdue tasks');
  await p.close();
});

await step('18', async () => {
  const p = await open(s, 'hr', '/admin/onboarding-templates/1/edit');
  await p.waitForTimeout(2500);
  const t = p.locator('.fi-ta', { hasText: 'Checklist items' }).first();
  const y = await t.evaluate(e => e.getBoundingClientRect().top + window.scrollY).catch(() => null);
  if (y !== null) await p.evaluate((y) => window.scrollTo(0, y - 120), y);
  await p.waitForTimeout(1000);
  await shot(p, 'ppl-18-onboarding-template-items');
  rec('ppl-18-onboarding-template-items', 'hr', '/admin/onboarding-templates/1/edit', 'Onboarding template checklist items by phase, owner and due day');
  await p.close();
});

await step('19', async () => {
  const p = await open(s, 'hr', '/admin/letters');
  await p.getByRole('button', { name: 'Generate letter' }).first().click(); await p.waitForTimeout(1500);
  await shot(p, 'ppl-19-letter-generate');
  rec('ppl-19-letter-generate', 'hr', '/admin/letters', 'Generate letter form (not submitted)');
  await p.close();
});

await step('20', async () => {
  const ctxp = await (await import('./lib.mjs')).context(s, 'hr');
  const p = await ctxp.newPage();
  await p.route(/livewire-[^/]+\/update/, (r) => r.abort());
  await p.goto(BASE + '/admin/letters/1', { waitUntil: 'domcontentloaded' }); await settle(p, 1500);
  const mm = await annotate(p, [
    { loc: p.locator('main').getByRole('button', { name: 'Download' }), n: 1 },
    { loc: p.getByText('Requested by', { exact: true }), n: 2 },
    { loc: p.getByText('Approved by', { exact: true }), n: 3 },
    { loc: p.getByText('TO WHOMSOEVER IT MAY CONCERN'), n: 4 },
  ]);
  if (mm.length) console.log('MISSING', mm);
  await shot(p, 'ppl-20-letter-view', { clip: { x: 240, y: 60, width: 1200, height: 840 } });
  rec('ppl-20-letter-view', 'hr', '/admin/letters/1', 'An issued letter: requester and approver are different people');
  await p.close();
});

await step('21', async () => {
  const p = await open(s, 'hr', '/admin/letter-templates');
  await p.locator('main').getByRole('button', { name: 'Edit' }).first().click(); await p.waitForTimeout(2000);
  await shot(p, 'ppl-21-letter-template-edit', { element: modal(p) });
  rec('ppl-21-letter-template-edit', 'hr', '/admin/letter-templates', 'Editing a letter template (not saved)');
  await p.close();
});

await step('22', async () => {
  const p = await open(s, 'hr', '/admin/exit-cases');
  // remove the default open-status filter so the completed case shows
  const rm = p.getByRole('button', { name: 'Remove filter' }).first();
  if (await rm.count()) { await rm.click(); await settle(p, 1500); }
  const mm = await annotate(p, [
    { loc: p.locator('main').getByRole('button', { name: 'Initiate exit' }), n: 1 },
    { loc: p.locator('main').getByRole('button', { name: 'Resign' }), n: 2 },
    { loc: p.locator('button[aria-label=Filter]').first(), n: 3 },
    { loc: p.locator('th', { hasText: 'Clearance' }).first(), n: 4 },
    { loc: p.locator('main a', { hasText: 'Open' }).first(), n: 5 },
  ]);
  if (mm.length) console.log('MISSING', mm);
  await shot(p, 'ppl-22-exit-cases');
  rec('ppl-22-exit-cases', 'hr', '/admin/exit-cases', 'Exit cases with the status filter cleared (one completed case)');
  await p.close();
});

await step('23', async () => {
  const p = await open(s, 'hr', '/admin/exit-cases');
  await p.locator('main').getByRole('button', { name: 'Initiate exit' }).click(); await p.waitForTimeout(1800);
  await shot(p, 'ppl-23-exit-initiate', { element: modal(p) });
  rec('ppl-23-exit-initiate', 'hr', '/admin/exit-cases', 'Initiate exit form (not submitted)');
  await p.close();
});

await step('24', async () => {
  const p = await open(s, 'hr', '/admin/exit-cases/1');
  await p.waitForTimeout(1500);
  await shot(p, 'ppl-24-exit-case-clearance', { element: 'main' });
  rec('ppl-24-exit-case-clearance', 'hr', '/admin/exit-cases/1', 'A completed exit case (Vikram Singh) with its five clearance stages');
  await p.close();
});

await step('25', async () => {
  const p = await open(s, 'hr', '/admin/exit-cases/1');
  await p.waitForTimeout(1500);
  await p.locator('.fi-tabs-item', { hasText: 'Full & final' }).last().click(); await p.waitForTimeout(2500);
  const tbl = p.locator('.fi-ta', { hasText: 'Total earnings' }).first();
  const ty = await tbl.evaluate(e => e.getBoundingClientRect().top + window.scrollY);
  await p.evaluate((y) => window.scrollTo(0, y - 200), ty); await p.waitForTimeout(800);
  const mm = await annotate(p, [
    { loc: tbl.locator('th', { hasText: 'Status' }).first(), n: 1 },
    { loc: tbl.locator('th', { hasText: 'Net amount' }).first(), n: 2 },
    { loc: tbl.getByRole('button', { name: 'Breakdown' }).first(), n: 3 },
    { loc: tbl.getByRole('button', { name: 'Mark paid' }).first(), n: 4 },
  ]);
  if (mm.length) console.log('MISSING', mm);
  const b = await tbl.boundingBox(); await shot(p, 'ppl-25-full-final', { clip: { x: b.x - 24, y: b.y - 24, width: b.width + 40, height: b.height + 40 } });
  rec('ppl-25-full-final', 'hr', '/admin/exit-cases/1', 'Full & final tab of the exit case (approved, not yet paid)');
  await p.close();
});

await step('26', async () => {
  const p = await open(s, 'hr', '/admin/alumni-profiles/1');
  await p.waitForTimeout(1500);
  await shot(p, 'ppl-26-alumni-profile');
  rec('ppl-26-alumni-profile', 'hr', '/admin/alumni-profiles/1', 'Alumni profile with the Requests tab');
  await p.close();
});

await step('27', async () => {
  const p = await open(s, 'employee', '/admin/exit-cases');
  await p.locator('main').getByRole('button', { name: 'Resign' }).click(); await p.waitForTimeout(1800);
  await shot(p, 'ppl-27-resign', { element: modal(p) });
  rec('ppl-27-resign', 'employee', '/admin/exit-cases', 'Employee self-service Resign form (not submitted)');
  await p.close();
});

await step('28', async () => {
  const p = await open(s, 'hr', '/admin/exit-insights');
  await shot(p, 'ppl-28-exit-insights', { element: 'main' });
  rec('ppl-28-exit-insights', 'hr', '/admin/exit-insights', 'Exit insights');
  await p.close();
});
await finish(s);
