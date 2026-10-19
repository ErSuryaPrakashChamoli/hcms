import { start, open, annotate, clearCallouts, shot, record, finish, settle } from './lib.mjs';
const s = await start('qs');
const log = (...a) => console.log(...a);
// 1 sign in
let p = await open(s, 'guest', '/admin/login');
log('m1', await annotate(p, [{ sel: 'input[type=email]', n: 1 }, { sel: 'input[type=password]', n: 2 }, { sel: 'a:has-text("Forgot password")', n: 3 }, { sel: 'button[type=submit]', n: 4 }]));
await shot(p, 'qs-01-sign-in', { clip: { x: 320, y: 120, width: 800, height: 640 } });
record(s, { name: 'qs-01-sign-in', persona: 'guest', path: '/admin/login', caption: 'Sign-in page' });
// 2 employee home
p = await open(s, 'employee', '/admin');
log('m2', await annotate(p, [
  { sel: '.fi-sidebar-nav', n: 1 },
  { sel: '.pos-command-trigger', n: 2 },
  { sel: '.fi-topbar [aria-label^="Notifications"]', n: 3 },
  { sel: '.fi-topbar .fi-user-menu, .fi-topbar [aria-label="User menu"]', n: 4, badge: 'tr' },
  { sel: '[data-pos-action="request_leave_header"]', n: 5 },
  { sel: 'text=FOR YOU', n: 6 },
]));
await shot(p, 'qs-02-home-employee');
record(s, { name: 'qs-02-home-employee', persona: 'employee', path: '/admin', caption: 'Employee Home' });
await clearCallouts(p);
// 3 avatar menu
await p.locator('.fi-topbar .fi-user-menu button, .fi-topbar [aria-label="User menu"]').first().click();
await p.waitForTimeout(700);
await shot(p, 'qs-03-avatar-menu', { clip: { x: 1040, y: 0, width: 400, height: 560 } });
record(s, { name: 'qs-03-avatar-menu', persona: 'employee', path: '/admin', caption: 'Avatar menu with personal pages' });
await p.keyboard.press('Escape');
// 4 request leave modal
p = await open(s, 'employee', '/admin');
await p.locator('[data-pos-action="request_leave_header"]').first().click();
const modal = p.locator('.fi-modal-window:visible').first();
await modal.waitFor();
await settle(p, 800);
log('MODAL', (await modal.innerText()).slice(0, 1200));
await shot(p, 'qs-04-request-leave-form', { element: modal });
record(s, { name: 'qs-04-request-leave-form', persona: 'employee', path: '/admin (Request leave)', caption: 'Request leave form' });
await finish(s);
