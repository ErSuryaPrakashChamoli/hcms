// PeopleOS visual regression references (Experience Transformation §52).
//
// Usage (against a disposable showcase database, never production data):
//   DB_DATABASE=hcm_ux_showcase php artisan migrate --force
//   DB_DATABASE=hcm_ux_showcase php artisan db:seed --class=UxShowcaseSeeder --force
//   DB_DATABASE=hcm_ux_showcase PHP_CLI_SERVER_WORKERS=4 php artisan serve --port=8090
//   npm i --no-save playwright && node docs/ux/visual-regression/capture.mjs
//
// Every person in the showcase is fictional; the seeder refuses to run on any other database.
import { chromium } from 'playwright';

const BASE = process.env.BASE ?? 'http://127.0.0.1:8090';
const OUT = new URL('.', import.meta.url).pathname;
const SHOTS = [
  // [file, persona email, path, theme, viewport, action]
  ['01-home-employee-light', 'priya.nair@demo.local', '/admin', 'light', 'desktop'],
  ['02-home-manager-light', 'amit.verma@demo.local', '/admin', 'light', 'desktop'],
  ['03-home-manager-dark', 'amit.verma@demo.local', '/admin', 'dark', 'desktop'],
  ['04-home-hr-light', 'neha.kapoor@demo.local', '/admin', 'light', 'desktop'],
  ['05-home-executive-light', 'meera.iyer@demo.local', '/admin', 'light', 'desktop'],
  ['06-approvals-manager-light', 'amit.verma@demo.local', '/admin/approvals', 'light', 'desktop'],
  ['07-my-work-manager-light', 'amit.verma@demo.local', '/admin/my-work', 'light', 'desktop'],
  ['08-command-center-light', 'neha.kapoor@demo.local', '/admin', 'light', 'desktop', 'type:leave'],
  ['09-employee-360-hr-light', 'neha.kapoor@demo.local', '/admin/employees/2', 'light', 'desktop'],
  ['10-employee-360-hr-dark', 'neha.kapoor@demo.local', '/admin/employees/2', 'dark', 'desktop'],
  ['11-people-hr-light', 'neha.kapoor@demo.local', '/admin/people', 'light', 'desktop'],
  ['12-org-map-hr-light', 'neha.kapoor@demo.local', '/admin/organisation-map', 'light', 'desktop'],
  ['13-workforce-command-center-light', 'meera.iyer@demo.local', '/admin/workforce-command-centre', 'light', 'desktop'],
  ['14-notifications-light', 'amit.verma@demo.local', '/admin/notifications', 'light', 'desktop'],
  ['15-home-employee-mobile', 'priya.nair@demo.local', '/admin', 'light', 'mobile'],
  ['16-approvals-mobile', 'amit.verma@demo.local', '/admin/approvals', 'light', 'mobile'],
  ['17-people-mobile-dark', 'neha.kapoor@demo.local', '/admin/people', 'dark', 'mobile'],
  ['18-person-drawer-light', 'neha.kapoor@demo.local', '/admin/people', 'light', 'desktop', 'drawer:3'],
  ['19-assistant-panel-light', 'neha.kapoor@demo.local', '/admin/employees/2', 'light', 'desktop', 'click:text=Summarise'],
  ['20-admin-centre-light', 'kavya.menon@demo.local', '/admin/admin-centre', 'light', 'desktop'],
];

const browser = await chromium.launch();
const sessions = new Map(); // one sign-in per persona (the login limiter is respected)
async function session(email) {
  if (sessions.has(email)) return sessions.get(email);
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1, reducedMotion: 'reduce' });
  const page = await ctx.newPage();
  await page.goto(`${BASE}/admin/login`);
  await page.fill('input[type=email]', email);
  await page.fill('input[type=password]', process.env.SHOWCASE_PASSWORD ?? 'password');
  await Promise.all([page.waitForURL(/\/admin$/, { timeout: 120000 }), page.click('button[type=submit]')]);
  sessions.set(email, page);
  return page;
}
import { existsSync } from 'node:fs';
for (const [file, email, path, theme, vp, action] of SHOTS) {
  if (process.env.SKIP_EXISTING && existsSync(`${OUT}${file}.png`)) continue;
  const page = await session(email);
  await page.setViewportSize(vp === 'mobile' ? { width: 390, height: 844 } : { width: 1440, height: 900 });
  await page.emulateMedia({ colorScheme: theme, reducedMotion: 'reduce' });
  await page.evaluate((t) => { try { localStorage.setItem('theme', t); } catch {} }, theme);
  await page.goto(BASE + path, { waitUntil: 'networkidle', timeout: 120000 });
  if (action?.startsWith('type:')) { await page.keyboard.press('Control+k'); await page.waitForTimeout(300); await page.keyboard.type(action.slice(5), { delay: 30 }); await page.waitForTimeout(1500); }
  if (action?.startsWith('drawer:')) { await page.evaluate((id) => window.dispatchEvent(new CustomEvent('pos-drawer-open', { detail: { type: 'person', id: +id } })), action.slice(7)); await page.waitForTimeout(1500); }
  if (action?.startsWith('click:')) { await page.click(action.slice(6)); await page.waitForTimeout(2500); }
  await page.waitForTimeout(700);
  await page.screenshot({ path: `${OUT}${file}.png` });
  console.log('captured', file);
  if (action) await page.keyboard.press('Escape');
}
await browser.close();
