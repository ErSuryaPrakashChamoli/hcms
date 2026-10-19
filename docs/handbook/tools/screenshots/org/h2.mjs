import { start, open, annotate, clearCallouts, shot, record, finish, settle } from '../lib.mjs';
const s = await start('org');
const log = (n, m) => { if (m.length) console.log('MISSING', n, m); else console.log('ok', n); };
let page = await open(s, 'admin', '/admin/assets');
let m = await annotate(page, [
  { sel: 'main a:has-text("Receive asset"), main button:has-text("Receive asset")', n: 1 },
  { loc: page.locator('main .pos-lens-chip').first().locator('xpath=..'), n: 2 },
  { sel: 'main .fi-ta-search-field', n: 3 },
  { sel: 'main button[title="Filter"]', n: 4 },
  { sel: 'main th:has-text("Custodian")', n: 5 },
  { sel: 'main .fi-ta-row .fi-ta-actions', n: 6 },
]);
log('asset-list', m);
await shot(page, 'org-as-asset-register');
await page.close();

page = await open(s, 'admin', '/admin/assets/2');
await page.locator('main header button:has-text("More")').first().click();
await settle(page, 600);
const dd = page.locator('.fi-dropdown-panel:visible').last();
console.log('MORE', await dd.innerText());
m = await annotate(page, [
  { sel: 'main header button:has-text("Transfer")', n: 1 },
  { sel: 'main header button:has-text("Take back")', n: 2 },
  { loc: dd, n: 3 },
  { loc: page.getByText('Custodian', { exact: true }).first(), n: 4 },
]);
log('asset-view', m);
await shot(page, 'org-as-asset-detail');
await page.close();
await finish(s);
