import { start, open, annotate, clearCallouts, shot, record, finish, settle } from '../lib.mjs';
const s = await start('org');
const log = (n, m) => { if (m.length) console.log('MISSING', n, m); else console.log('ok', n); };

// 1. Org map, executive
let page = await open(s, 'executive', '/admin/organisation-map');
let m = await annotate(page, [
  { sel: 'main .pos-segmented', n: 1 },
  { sel: 'main .pos-search', n: 2 },
  { sel: 'main button.pos-chip', n: 3 },
  { sel: 'main button:has-text("Expand all")', n: 4 },
  { sel: 'main [aria-label="Zoom"]', n: 5 },
]);
log('org-01', m);
await shot(page, 'org-01-org-map-reporting-lines');
record(s, { name: 'org-01-org-map-reporting-lines', persona: 'executive', path: '/admin/organisation-map', caption: 'Org map, reporting lines view' });
await clearCallouts(page);
await page.locator('main .pos-segmented button', { hasText: 'Departments' }).click();
await settle(page, 1200);
await shot(page, 'org-02-org-map-departments');
record(s, { name: 'org-02-org-map-departments', persona: 'executive', path: '/admin/organisation-map', caption: 'Org map, departments view' });
await page.close();

// 3. Organisation Designer, admin
page = await open(s, 'admin', '/admin/organisation-designer');
const eng = page.locator('main .pos-node', { has: page.locator('.pos-name', { hasText: /^Engineering$/ }) }).first();
await eng.hover();
await page.waitForTimeout(400);
m = await annotate(page, [
  { sel: 'main button:has-text("Add root unit")', n: 1 },
  { sel: 'main input[type=search]', n: 2 },
  { sel: 'main button:has-text("Tree")', n: 3 },
  { loc: eng.locator('.pos-actions'), n: 4 },
]);
log('org-03', m);
await shot(page, 'org-03-organisation-designer-tree');
record(s, { name: 'org-03-organisation-designer-tree', persona: 'admin', path: '/admin/organisation-designer', caption: 'Organisation Designer tree view' });
await clearCallouts(page);
// Add child modal under Engineering
await eng.hover();
await eng.locator('.pos-actions button').first().click();
await page.waitForSelector('.fi-modal-window', { state: 'visible', timeout: 15000 });
await settle(page, 800);
const html = await page.locator('.fi-modal-window:visible').first().evaluate((e) => e.innerText);
console.log('MODAL TEXT:', html);
await shot(page, 'org-04-designer-add-unit', { element: page.locator('.fi-modal-window:visible').first() });
record(s, { name: 'org-04-designer-add-unit', persona: 'admin', path: '/admin/organisation-designer', caption: 'Add unit dialog' });
await page.keyboard.press('Escape');
await page.close();
await finish(s);
