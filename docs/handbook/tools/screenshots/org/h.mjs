import { start, open, annotate, clearCallouts, shot, record, finish, settle } from '../lib.mjs';
const s = await start('org');
const log = (n, m) => { if (m.length) console.log('MISSING', n, m); else console.log('ok', n); };
const modal = (page) => page.locator('.fi-modal-window:visible').last();

let page = await open(s, 'admin', '/admin/assets');
let m = await annotate(page, [
  { sel: 'main a:has-text("Receive asset"), main button:has-text("Receive asset")', n: 1 },
  { sel: 'main .fi-tabs', n: 2 },
  { sel: 'main .fi-ta-search-field', n: 3 },
  { sel: 'main button[title="Filter"]', n: 4 },
  { sel: 'main th:has-text("Custodian")', n: 5 },
  { sel: 'main .fi-ta-row .fi-ta-actions', n: 6 },
]);
log('asset-list', m);
await shot(page, 'org-as-asset-register');
await page.close();

// view LT-0002 as admin
page = await open(s, 'admin', '/admin/assets/2');
const hdr = page.locator('main .fi-header-actions-ctn, main .fi-header .fi-ac').first();
console.log('HEADER', await page.locator('main header').first().innerText().catch(() => 'none'));
m = await annotate(page, [
  { sel: 'main header button:has-text("Transfer")', n: 1 },
  { sel: 'main header button:has-text("Take back")', n: 2 },
  { sel: 'main header button:has-text("Send for repair")', n: 3 },
  { sel: 'main header button:has-text("Report lost")', n: 4 },
  { loc: page.getByText('Custodian', { exact: true }).first(), n: 5 },
]);
log('asset-view', m);
await shot(page, 'org-as-asset-detail');
await clearCallouts(page);
// custody tab
await page.locator('main .fi-tabs').last().evaluate(e => e.scrollIntoView({ block: 'center' }));
await settle(page, 1200);
await shot(page, 'org-as-custody-history');
await page.close();

// assign modal on in-stock LT-0004
page = await open(s, 'admin', '/admin/assets/4');
console.log('LT4 HEADER', await page.locator('main header').first().innerText().catch(() => 'none'));
await page.locator('main header button:has-text("Assign")').first().click();
await page.locator('.fi-modal-window').first().waitFor({ state: 'visible' });
await settle(page, 700);
console.log('ASSIGN MODAL', await modal(page).innerText());
await shot(page, 'org-as-assign-modal', { element: modal(page) });
await modal(page).getByRole('button', { name: 'Cancel' }).click();
await page.close();

// employee view
page = await open(s, 'employee', '/admin/assets/2');
await page.locator('main .fi-tabs').last().evaluate(e => e.scrollIntoView({ block: 'center' }));
await settle(page, 1200);
m = await annotate(page, [
  { sel: 'main button:has-text("Acknowledge receipt")', n: 1 },
  { sel: 'main td:has-text("Pending")', n: 2 },
]);
log('emp-ack', m);
await shot(page, 'org-as-my-assets-acknowledge');
await page.close();
await finish(s);
