import { start, open, annotate, clearCallouts, shot, record, finish, settle } from './lib.mjs';
const s = await start('qs');
const log = (...a) => console.log(...a);
let p = await open(s, 'manager', '/admin/approvals');
await p.locator('.pos-queue-row:has-text("Priya Nair")').first().click();
await settle(p, 1200);
log('m', await annotate(p, [
  { sel: '.pos-queue-row:has-text("Priya Nair")', n: 1 },
  { loc: p.locator('main button:has-text("Approve"):visible').first(), n: 2 },
  { loc: p.locator('main button:has-text("Reject"):visible').first(), n: 3 },
  { loc: p.locator('main a:has-text("View details"):visible, main button:has-text("View details"):visible').first(), n: 4 },
]));
await shot(p, 'qs-06-manager-approvals');
record(s, { name: 'qs-06-manager-approvals', persona: 'manager', path: '/admin/approvals', caption: 'Approval Center with a leave decision selected' });
log('PANEL', (await p.locator('main').innerText()).split('LATER')[1]?.slice(0, 900));
p = await open(s, 'employee', '/admin/profile');
log('PROFILE', (await p.locator('main').innerText()).slice(0, 1500));
await shot(p, 'qs-08-account-security');
record(s, { name: 'qs-08-account-security', persona: 'employee', path: '/admin/profile', caption: 'Account security page' });
p = await open(s, 'admin', '/admin');
await shot(p, 'ov-01-home-admin');
record(s, { name: 'ov-01-home-admin', persona: 'admin', path: '/admin', caption: 'Administrator Home' });
await finish(s);
