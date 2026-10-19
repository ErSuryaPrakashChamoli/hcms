import { start, open, annotate, clearCallouts, shot, record, finish, settle } from '../lib.mjs';
const s = await start('org');
const log = (n, m) => { if (m.length) console.log('MISSING', n, m); else console.log('ok', n); };
const modal = (page) => page.locator('.fi-modal-window:visible').first();

// org-02 departments view as admin
let page = await open(s, 'admin', '/admin/organisation-map?mode=departments');
await settle(page, 1000);
await shot(page, 'org-02-org-map-departments');
record(s, { name: 'org-02-org-map-departments', persona: 'admin', path: '/admin/organisation-map?mode=departments', caption: 'Org map, departments view' });
await page.close();

// org-04 designer add unit filled
page = await open(s, 'admin', '/admin/organisation-designer');
const eng = page.locator('main .pos-node', { has: page.locator('.pos-name', { hasText: /^Engineering$/ }) }).first();
await eng.hover();
await eng.locator('.pos-actions button').first().click();
await page.waitForSelector('.fi-modal-window', { state: 'visible', timeout: 15000 });
await settle(page, 600);
const md = modal(page);
const sel = md.locator('select').first();
if (await sel.count()) { await sel.selectOption({ label: 'Team' }); } else { console.log('no native select'); }
await settle(page, 600);
await md.locator('input[type=text]').first().fill('Data Platform (example)');
await md.locator('input[type=text]').first().blur();
await settle(page, 900);
await md.locator('textarea').first().fill('New team for the data platform roadmap (example).');
let m = await annotate(page, [
  { loc: md.locator('select').first(), n: 1 },
  { loc: md.getByText('Create a new unit'), n: 2 },
  { loc: md.locator('input[type=text]').nth(0), n: 3 },
  { loc: md.locator('input[type=text]').nth(1), n: 4 },
  { loc: md.locator('textarea').first(), n: 5 },
]);
log('org-04', m);
await shot(page, 'org-04-designer-add-unit', { element: md });
record(s, { name: 'org-04-designer-add-unit', persona: 'admin', path: '/admin/organisation-designer', caption: 'Add unit dialog (filled, then cancelled)' });
await clearCallouts(page);
await md.getByRole('button', { name: 'Cancel' }).click();
await page.close();

// org-05 companies list with area bar
page = await open(s, 'admin', '/admin/companies');
m = await annotate(page, [
  { sel: 'main .pos-spacebar-scroll', n: 1 },
  { sel: 'main .pos-spacebar-more', n: 2 },
  { sel: 'main a:has-text("New company"), main button:has-text("New company")', n: 3 },
  { sel: 'main .fi-ta-search-field', n: 4 },
  { sel: 'main .fi-ta-filters-trigger-action-ctn, main button[title="Filter"]', n: 5 },
  { sel: 'main .fi-ta-row .fi-ta-actions', n: 6 },
]);
log('org-05', m);
await shot(page, 'org-05-companies-list');
record(s, { name: 'org-05-companies-list', persona: 'admin', path: '/admin/companies', caption: 'Companies list with the Organisation area bar' });
await clearCallouts(page);
// org-06 All in Organisation menu
await page.locator('main .pos-spacebar-more').click();
await settle(page, 600);
const menu = page.locator('#pos-area-all');
console.log('MENU:', await menu.innerText().catch(() => 'none'));
const bb = await menu.boundingBox();
if (bb) {
  await shot(page, 'org-06-all-in-organisation-menu', { clip: { x: Math.max(0, bb.x - 420), y: 90, width: Math.min(1440 - Math.max(0, bb.x - 420), bb.width + 440), height: Math.min(900 - 90, bb.y + bb.height - 90 + 16) } });
  record(s, { name: 'org-06-all-in-organisation-menu', persona: 'admin', path: '/admin/companies', caption: 'All in Organisation menu' });
}
await page.close();

// org-07 new company form
page = await open(s, 'admin', '/admin/companies/create');
const main = page.locator('main');
const byLabel = async (label, val) => { const f = page.getByLabel(label, { exact: true }).first(); if (await f.count()) await f.fill(val); else console.log('nolabel', label); };
await byLabel('Name', 'Example Labs');
await byLabel('Code', 'EX-LABS');
await byLabel('Legal name', 'Example Labs Private Limited');
await byLabel('Timezone', 'Asia/Kolkata');
await byLabel('Currency', 'INR');
await settle(page, 400);
console.log('FORM:', (await main.innerText()).slice(0, 1500));
await shot(page, 'org-07-new-company-form', { element: 'main' });
record(s, { name: 'org-07-new-company-form', persona: 'admin', path: '/admin/companies/create', caption: 'New company form (filled with example values, not saved)' });
await page.close();
await finish(s);
