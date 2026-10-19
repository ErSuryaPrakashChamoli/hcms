import { start, open, annotate, clearCallouts, shot, record, finish, settle } from '../lib.mjs';
const s = await start('org');
const log = (n, m) => { if (m.length) console.log('MISSING', n, m); else console.log('ok', n); };
let page = await open(s, 'admin', '/admin/designations');
await page.locator('main .pos-spacebar-more').click();
await settle(page, 600);
const menu = page.locator('#pos-area-all');
console.log('MENU:', await menu.innerText());
const peopleSetup = menu.getByText('People Setup', { exact: true });
if (await peopleSetup.count()) await peopleSetup.first().scrollIntoViewIfNeeded();
const bb = await menu.boundingBox();
console.log(bb);
let m = await annotate(page, [{ loc: menu.getByText('People Setup', { exact: false }).first(), n: 1 }]);
log('setup-menu', m);
await shot(page, 'org-12-all-in-setup-people-setup');
await page.close();

page = await open(s, 'admin', '/admin/designations/create');
await page.fill('[id="form.name"]', 'Data Engineer (example)');
await page.fill('[id="form.code"]', 'DE-EX');
await settle(page, 600);
console.log('DESIG FORM:', (await page.locator('main').innerText()).slice(0, 1500));
m = await annotate(page, [
  { sel: '[id="form.name"]', n: 1 },
  { sel: '[id="form.code"]', n: 2 },
  { loc: page.getByText('Level', { exact: true }).first(), n: 3 },
  { loc: page.getByText('Employment types', { exact: true }).first(), n: 4 },
]);
log('desig', m);
await shot(page, 'org-13-new-designation-form');
await page.close();
await finish(s);
