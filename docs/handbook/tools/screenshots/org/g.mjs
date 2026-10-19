import { start, open, annotate, clearCallouts, shot, record, finish, settle } from '../lib.mjs';
import { modal, openAction } from './wf-helpers.mjs';
const s = await start('org');
const log = (n, m) => { if (m.length) console.log('MISSING', n, m); else console.log('ok', n); };
async function modalClip(page, name) {
  const b = await modal(page).boundingBox();
  const y = Math.max(0, b.y), h = Math.min(900, b.y + b.height) - y;
  await shot(page, name, { clip: { x: b.x, y, width: b.width, height: h } });
}

// positions list
let page = await open(s, 'admin', '/admin/positions');
let m = await annotate(page, [
  { sel: 'main button:has-text("New position")', n: 1 },
  { sel: 'main th:has-text("Seats")', n: 2 },
  { sel: 'main th:has-text("Occupied")', n: 3 },
  { sel: 'main th:has-text("Status")', n: 4 },
  { sel: 'main .fi-ta-row a:has-text("View"), main .fi-ta-row button:has-text("View")', n: 5 },
]);
log('pos-list', m);
await shot(page, 'org-wf-positions-list');
await clearCallouts(page);
// new position modal top
await openAction(page, 'New position');
m = await annotate(page, [
  { sel: '[id="mountedActionSchema0.code"]', n: 1 },
  { sel: '[id="mountedActionSchema0.effective_from"]', n: 2 },
  { sel: '[id="mountedActionSchema0.designation_id"]', n: 3 },
  { sel: '[id="mountedActionSchema0.company_id"]', n: 4 },
  { sel: '[id="mountedActionSchema0.organisation_node_id"]', n: 5 },
]);
log('pos-modal', m);
await modalClip(page, 'org-wf-new-position-form');
await clearCallouts(page);
await page.keyboard.press('Escape');
await page.close();

// position view
page = await open(s, 'admin', '/admin/positions/1');
m = await annotate(page, [
  { sel: 'main button:has-text("Lifecycle")', n: 1 },
  { sel: 'main button:has-text("Edit draft")', n: 2 },
  { loc: page.getByText('Occupancy today', { exact: true }).first(), n: 3 },
  { loc: page.getByText('Establishment', { exact: true }).first(), n: 4 },
]);
log('pos-view', m);
await shot(page, 'org-wf-position-detail');
await clearCallouts(page);
await page.locator('main button:has-text("Lifecycle")').click();
await settle(page, 500);
const dd = page.locator('.fi-dropdown-panel:visible').last();
console.log('LIFECYCLE MENU', await dd.innerText());
m = await annotate(page, [{ loc: dd, n: 1 }]);
await shot(page, 'org-wf-position-lifecycle-menu', { clip: { x: 700, y: 120, width: 740, height: 360 } });
await page.keyboard.press('Escape');
// tabs at bottom
await clearCallouts(page);
const tabs = page.locator('main .fi-tabs').last();
await tabs.scrollIntoViewIfNeeded();
await settle(page, 800);
await shot(page, 'org-wf-position-history-tab');
await page.close();

// forecast
page = await open(s, 'admin', '/admin/workforce-plans');
await page.locator('main .fi-ta-row button:has-text("Forecast")').first().click();
await page.locator('.fi-modal-window').first().waitFor({ state: 'visible' });
await settle(page, 900);
m = await annotate(page, [{ loc: modal(page).getByText('Attrition', { exact: true }), n: 1 }, { loc: modal(page).getByText('2027-07', { exact: true }), n: 2 }]);
log('forecast', m);
await modalClip(page, 'org-wf-forecast');
await page.keyboard.press('Escape');
await page.close();

// dashboard as executive
page = await open(s, 'executive', '/admin/workforce-dashboard');
await shot(page, 'org-wf-dashboard');
console.log('EXEC DASH', (await page.locator('main').innerText()).slice(0, 400));
await page.close();
page = await open(s, 'admin', '/admin/workforce-snapshots');
await shot(page, 'org-wf-snapshot');
await page.close();
await finish(s);
