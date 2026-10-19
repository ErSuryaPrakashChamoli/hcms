import { start, open, annotate, clearCallouts, shot, record, finish, settle } from '../lib.mjs';
const s = await start('org');
const log = (n, m) => { if (m.length) console.log('MISSING', n, m); else console.log('ok', n); };
let page = await open(s, 'admin', '/admin/companies/create');
await page.fill('[id="form.name"]', 'Example Labs');
await page.fill('[id="form.code"]', 'EX-LABS');
await page.fill('[id="form.legal_name"]', 'Example Labs Private Limited');
await page.fill('[id="form.timezone"]', 'Asia/Kolkata');
await page.fill('[id="form.currency"]', 'INR');
await page.locator('[id="form.currency"]').blur();
await settle(page, 900);
let m = await annotate(page, [
  { sel: '[id="form.name"]', n: 1 },
  { sel: '[id="form.code"]', n: 2 },
  { sel: '[id="form.country_code"]', n: 3 },
  { loc: page.getByText('Review before creating', { exact: false }).first(), n: 4 },
]);
log('org-07', m);
await shot(page, 'org-07-new-company-form');
record(s, { name: 'org-07-new-company-form', persona: 'admin', path: '/admin/companies/create', caption: 'New company form (example values, not saved)' });
await page.close();
await finish(s);
