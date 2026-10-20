import { start, open, annotate, clearCallouts, shot, record, finish, settle } from './lib.mjs';
const s = await start('ppl');
const only = process.argv[2] ? process.argv[2].split(',') : null;
const want = (n) => !only || only.includes(n);
async function step(name, fn) { if (!want(name)) return; try { await fn(); console.log('OK', name); } catch (e) { console.log('FAIL', name, e.message.split('\n')[0]); } }
const rec = (name, persona, path, caption) => record(s, { name, persona, path, caption });

await step('01', async () => {
  const p = await open(s, 'hr', '/admin/people');
  const m = await annotate(p, [
    { loc: p.locator('.pos-search').first(), n: 1 },
    { loc: p.locator('select[aria-label=Department]'), n: 2 },
    { loc: p.locator('select[aria-label="Group by"]'), n: 3 },
    { loc: p.locator('.pos-segmented'), n: 4 },
    { loc: p.getByRole('link', { name: 'Employee register' }), n: 5 },
    { loc: p.locator('table.pos-table thead th', { hasText: 'Status' }), n: 6 },
  ]);
  if (m.length) console.log('MISSING', m);
  await shot(p, 'ppl-01-directory-list');
  rec('ppl-01-directory-list', 'hr', '/admin/people', 'People directory, table view, as HR');
  await p.close();
});

await step('02', async () => {
  const p = await open(s, 'manager', '/admin/people');
  await p.locator('.pos-person-card').nth(2).click();
  await p.waitForTimeout(1800);
  const m = await annotate(p, [
    { loc: p.getByRole('link', { name: 'Open profile' }), n: 1 },
    { loc: p.locator('[role=dialog] a', { hasText: 'Message' }).first(), n: 2 },
    { loc: p.getByRole('link', { name: 'Org map' }).last(), n: 3 },
    { loc: p.getByRole('button', { name: 'Pin' }), n: 4 },
  ]);
  if (m.length) console.log('MISSING', m);
  await shot(p, 'ppl-02-directory-peek');
  rec('ppl-02-directory-peek', 'manager', '/admin/people', 'Directory cards with a person preview open, as a manager');
  await p.close();
});

await step('03', async () => {
  const p = await open(s, 'hr', '/admin/employees');
  const m = await annotate(p, [
    { loc: p.locator('.pos-spacebar'), n: 1 },
    { loc: p.getByRole('button', { name: 'Hire employee' }).or(p.getByRole('link', { name: 'Hire employee' })).first(), n: 2 },
    { loc: p.locator('.fi-ta-search-field input, input[type=search]').first(), n: 3 },
    { loc: p.locator('button[aria-label=Filter]').first(), n: 4 },
    { loc: p.locator('th', { hasText: 'Status' }).first(), n: 5 },
    { loc: p.locator('a', { hasText: 'View' }).first(), n: 6 },
  ]);
  if (m.length) console.log('MISSING', m);
  await shot(p, 'ppl-03-employees-register');
  rec('ppl-03-employees-register', 'hr', '/admin/employees', 'Employees register with Hire employee, search and filters');
  await p.close();
});

await step('04', async () => {
  const p = await open(s, 'hr', '/admin/employees/create');
  await p.getByLabel('First name').fill('Docs');
  await p.getByLabel('Last name').fill('Example');
  await p.getByLabel('Personal email').fill('docs.example@example.test');
  await p.waitForTimeout(800);
  const m = await annotate(p, [
    { loc: p.locator('ol:has-text("Who is joining")').first(), n: 1 },
    { loc: p.getByText('Existing person (re-use instead of creating a new one)'), n: 2 },
    { loc: p.getByLabel('First name'), n: 3 },
    { loc: p.getByText('Review before creating').first(), n: 4 },
    { loc: p.locator('.pos-form-review-head button'), n: 5 },
  ]);
  if (m.length) console.log('MISSING', m);
  await shot(p, 'ppl-04-hire-wizard');
  rec('ppl-04-hire-wizard', 'hr', '/admin/employees/create', 'Hire employee wizard, step 1 (Person), with fictional values; not saved');
  await p.close();
});

await step('05', async () => {
  const p = await open(s, 'hr', '/admin/employees/3');
  const m = await annotate(p, [
    { loc: p.locator('.pos-360-identity'), n: 1 },
    { loc: p.locator('.pos-360-actions').getByRole('button', { name: 'Summarise' }), n: 2 },
    { loc: p.locator('.pos-360-actions .fi-ac').first(), n: 3 },
    { loc: p.locator('.pos-360-nav'), n: 4 },
    { loc: p.locator('.pos-360-next'), n: 5 },
    { loc: p.locator('.pos-360-viewer'), n: 6 },
  ]);
  if (m.length) console.log('MISSING', m);
  await shot(p, 'ppl-05-employee-360-hr');
  rec('ppl-05-employee-360-hr', 'hr', '/admin/employees/3', 'Employee 360 (Rahul Sharma) as HR: header, actions, sections, Now');
  await p.close();
});

await step('06', async () => {
  const p = await open(s, 'hr', '/admin/employees/3');
  await p.locator('.pos-360-actions').getByRole('button', { name: 'Action', exact: true }).click();
  await p.waitForTimeout(700);
  const panel = p.locator('.fi-dropdown-panel:visible').first();
  const box = await panel.boundingBox();
  const m = await annotate(p, [{ loc: p.locator('.pos-360-actions').getByRole('button', { name: 'Action', exact: true }), n: 1 }]);
  if (m.length) console.log('MISSING', m);
  const clip = { x: Math.max(0, box.x - 420), y: Math.max(0, box.y - 90), width: Math.min(1440 - Math.max(0, box.x - 420), box.width + 470), height: box.height + 110 };
  await shot(p, 'ppl-06-360-action-menu', { clip });
  rec('ppl-06-360-action-menu', 'hr', '/admin/employees/3', 'The Action menu on the Employee 360 (HR Manager)');
  await p.close();
});

await step('07', async () => {
  const p = await open(s, 'hr', '/admin/employees/3#journey');
  await p.waitForTimeout(1200);
  await shot(p, 'ppl-07-360-journey', { element: '#journey' });
  rec('ppl-07-360-journey', 'hr', '/admin/employees/3#journey', 'Journey view: lifecycle stages and what changed');
  await p.close();
});

await step('08', async () => {
  const p = await open(s, 'hr', '/admin/employees/3#records');
  await p.waitForTimeout(2500);
  const tl = p.locator('.fi-tabs-item', { hasText: 'Timeline' }).last();
  await tl.click(); await p.waitForTimeout(2500);
  const tabs = p.locator('.fi-tabs').last();
  const y = await tabs.evaluate(e => e.getBoundingClientRect().top + window.scrollY);
  await p.evaluate((y) => window.scrollTo(0, y - 110), y);
  await p.waitForTimeout(800);
  const m = await annotate(p, [
    { loc: tabs, n: 1 },
    { loc: p.locator('th', { hasText: 'Kind' }).first(), n: 2 },
    { loc: p.locator('button[aria-label=Filter]').last(), n: 3 },
  ]);
  if (m.length) console.log('MISSING', m);
  await shot(p, 'ppl-08-360-records-timeline');
  rec('ppl-08-360-records-timeline', 'hr', '/admin/employees/3#records', 'Records view: tab groups and the Timeline tab');
  await p.close();
});
await finish(s);
