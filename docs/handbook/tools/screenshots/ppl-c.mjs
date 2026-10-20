import { start, open, annotate, clearCallouts, shot, record, finish, settle, BASE } from './lib.mjs';
const s = await start('ppl');
const only = process.argv[2] ? process.argv[2].split(',') : null;
const want = (n) => !only || only.includes(n);
async function step(name, fn) { if (!want(name)) return; try { await fn(); console.log('OK', name); } catch (e) { console.log('FAIL', name, e.message.split('\n')[0]); } }
const rec = (name, persona, path, caption) => record(s, { name, persona, path, caption });
const modal = (p) => p.locator('.fi-modal-window:visible').last();
const CSV = new URL('./ppl-import-example.csv', import.meta.url).pathname;

await step('15', async () => {
  const p = await open(s, 'admin', '/admin/employee-imports');
  await p.getByRole('button', { name: 'Upload CSV' }).click(); await p.waitForTimeout(1500);
  const m = modal(p);
  await m.locator('input[type=file]').setInputFiles(CSV);
  await p.waitForTimeout(4000);
  await shot(p, 'ppl-15-import-upload');
  rec('ppl-15-import-upload', 'admin', '/admin/employee-imports', 'Employee imports: Upload CSV with a fictional file');
  const btns = await m.locator('button').allInnerTexts();
  console.log('modal buttons', btns.map(b => b.trim()).filter(Boolean));
  await m.getByRole('button', { name: /^Create$/ }).click();
  await p.waitForURL(/employee-imports\/\d+/, { timeout: 60000 }); await settle(p, 1500);
  console.log('import url', p.url());
  await p.getByRole('button', { name: 'Inspect file' }).click(); await settle(p, 2500);
  await p.getByRole('button', { name: 'Map columns' }).click(); await p.waitForTimeout(2000);
  await shot(p, 'ppl-16a-import-map', { element: modal(p) });
  await modal(p).getByRole('button', { name: /Submit|Save/ }).first().click(); await settle(p, 2500);
  await p.getByRole('button', { name: 'Validate rows' }).click(); await settle(p, 3000);
  await p.reload(); await settle(p, 2000);
  const mm = await annotate(p, [
    { loc: p.getByRole('button', { name: 'Approve import' }), n: 1 },
    { loc: p.getByRole('button', { name: 'Discard' }), n: 2 },
    { loc: p.locator('.fi-section', { hasText: 'To review' }).first(), n: 3 },
  ]);
  if (mm.length) console.log('MISSING', mm);
  await shot(p, 'ppl-16-import-validated', { fullPage: true });
  rec('ppl-16-import-validated', 'admin', p.url().replace(BASE, ''), 'A validated import: counts, mapping and rows (create, update, error); not approved');
  console.log(await p.locator('main').innerText());
  await p.close();
});
await step('16', async () => {
  const p = await open(s, 'admin', '/admin/employee-imports/1');
  await p.locator('main').getByRole('button', { name: 'Map columns' }).first().click({ force: true }); await p.waitForTimeout(2500);
  console.log('modals', await p.locator('.fi-modal-window:visible').count());
  await shot(p, 'ppl-16a-import-map', { element: modal(p) });
  console.log('map buttons', (await modal(p).locator('button').allInnerTexts()).map(b=>b.trim()).filter(Boolean));
  const sb = modal(p).getByRole('button', { name: 'Submit', exact: true }); await sb.scrollIntoViewIfNeeded(); await sb.click({ force: true }); await settle(p, 2500);
  await p.locator('main').getByRole('button', { name: 'Validate rows' }).first().click({ force: true }); await settle(p, 3000);
  await p.reload(); await settle(p, 2000);
  const mm = await annotate(p, [
    { loc: p.getByRole('button', { name: 'Approve import' }), n: 1 },
    { loc: p.locator('.fi-section', { hasText: 'To review' }).first(), n: 2 },
    { loc: p.locator('th', { hasText: 'Action' }).first(), n: 3 },
    { loc: p.locator('th', { hasText: 'Matched' }).first(), n: 4 },
  ]);
  if (mm.length) console.log('MISSING', mm);
  await shot(p, 'ppl-16-import-validated', { fullPage: true });
  rec('ppl-16-import-validated', 'admin', '/admin/employee-imports/1', 'A validated import: counts, mapping and rows (create, update, error); not approved');
  console.log(await p.locator('main').innerText());
  await p.close();
});
await finish(s);
