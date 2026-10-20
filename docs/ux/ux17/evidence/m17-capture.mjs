// UX.17 capture: node m17-capture.mjs <outDir> [filter]. First-screen screenshots (what a person sees without
// scrolling) at phone, small phone, tablet and desktop, light and dark. FULL=<dir> also writes full-page copies.
// Showcase data only (fictional people).
import { chromium } from 'playwright';
import { personaContext } from './auth.mjs';
import { mkdirSync, writeFileSync } from 'node:fs';
const BASE = process.env.BASE ?? 'http://127.0.0.1:8090';
const [OUT, FILTER = ''] = process.argv.slice(2);
const FULL = process.env.FULL;
mkdirSync(OUT, { recursive: true }); if (FULL) mkdirSync(FULL, { recursive: true });
const SIZES = {
  phone: { viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true },
  phone2: { viewport: { width: 360, height: 780 }, hasTouch: true, isMobile: true },
  tablet: { viewport: { width: 768, height: 1024 }, hasTouch: true, isMobile: true },
  desktop: { viewport: { width: 1440, height: 900 }, hasTouch: false },
};
const E = 'priya.nair@demo.local', M = 'amit.verma@demo.local', H = 'neha.kapoor@demo.local', X = 'meera.iyer@demo.local', A = 'kavya.menon@demo.local', Y = 'arjun.bose@demo.local';
const LD = ['light', 'dark'], L = ['light'];
export const LIST = [
  // Phone 390 × 844: the five roles (plus payroll), their main surfaces
  ['p1-employee-home', E, '/admin', 'phone', LD], ['p1-employee-my-work', E, '/admin/my-work', 'phone', L], ['p1-employee-my-hr', E, '/admin/my-hr', 'phone', L],
  ['p1-employee-palette-leave', E, '/admin', 'phone', LD, 'palette:leave'], ['p1-employee-request-leave', E, '/admin', 'phone', L, 'leave'],
  ['p2-manager-home', M, '/admin', 'phone', LD], ['p2-manager-my-work', M, '/admin/my-work', 'phone', L], ['p2-manager-approvals', M, '/admin/approvals', 'phone', LD],
  ['p2-manager-my-team', M, '/admin/my-team', 'phone', L], ['p2-manager-360-report', M, '/admin/employees/3', 'phone', LD], ['p2-manager-palette-person', M, '/admin', 'phone', L, 'palette:Rahul'],
  ['p3-hr-home', H, '/admin', 'phone', LD], ['p3-hr-my-work', H, '/admin/my-work', 'phone', L], ['p3-hr-people', H, '/admin/people', 'phone', LD],
  ['p3-hr-employees-table', H, '/admin/employees', 'phone', L], ['p3-hr-requests-table', H, '/admin/tickets', 'phone', L], ['p3-hr-360', H, '/admin/employees/3', 'phone', L], ['p3-hr-ai', H, '/admin/employees/3', 'phone', L, 'ai'],
  ['p4-executive-home', X, '/admin', 'phone', LD], ['p4-executive-my-work', X, '/admin/my-work', 'phone', L], ['p4-executive-pulse', X, '/admin/workforce-command-centre', 'phone', L],
  ['p5-admin-home', A, '/admin', 'phone', LD], ['p5-admin-my-work', A, '/admin/my-work', 'phone', L], ['p5-admin-users', A, '/admin/users', 'phone', L],
  ['p5-admin-audit', A, '/admin/audit-events', 'phone', L], ['p5-admin-360', A, '/admin/employees/3', 'phone', L], ['p5-admin-centre', A, '/admin/admin-centre', 'phone', L],
  ['p6-payroll-home', Y, '/admin', 'phone', L], ['p6-payroll-people', Y, '/admin/people', 'phone', L],
  ['p7-manager-notifications', M, '/admin/notifications', 'phone', LD], ['p7-hr-menu', H, '/admin', 'phone', L, 'menu'],
  // After only (UX.17 states that did not exist before): the directory filters open, a phone list expanded in place
  ...(process.env.AFTER ? [['p8-hr-people-filters-open', H, '/admin/people', 'phone', L, 'filters'], ['p8-manager-home-show-more', M, '/admin', 'phone', L, 'more'], ['p8-manager-bell', M, '/admin', 'phone', L, 'bell']] : []),
  // Small phone 360 × 780: the five Homes
  ['s1-employee-home', E, '/admin', 'phone2', L], ['s2-manager-home', M, '/admin', 'phone2', L], ['s3-hr-home', H, '/admin', 'phone2', L],
  ['s4-executive-home', X, '/admin', 'phone2', L], ['s5-admin-home', A, '/admin', 'phone2', L],
  // Tablet 768 × 1024: representative views across roles
  ['t1-employee-home', E, '/admin', 'tablet', L], ['t2-manager-home', M, '/admin', 'tablet', LD], ['t2-manager-approvals', M, '/admin/approvals', 'tablet', L],
  ['t3-hr-home', H, '/admin', 'tablet', L], ['t3-hr-people', H, '/admin/people', 'tablet', LD], ['t3-hr-360', H, '/admin/employees/3', 'tablet', L],
  ['t4-executive-home', X, '/admin', 'tablet', L], ['t5-admin-home', A, '/admin', 'tablet', LD],
  // Desktop 1440 × 900: regression for the role Homes and the major shared surfaces
  ['d1-employee-home', E, '/admin', 'desktop', L], ['d2-manager-home', M, '/admin', 'desktop', LD], ['d2-manager-my-work', M, '/admin/my-work', 'desktop', L],
  ['d2-manager-approvals', M, '/admin/approvals', 'desktop', L], ['d3-hr-home', H, '/admin', 'desktop', L], ['d3-hr-people', H, '/admin/people', 'desktop', L],
  ['d3-hr-360', H, '/admin/employees/3', 'desktop', LD], ['d4-executive-home', X, '/admin', 'desktop', L], ['d5-admin-home', A, '/admin', 'desktop', LD],
  ['d6-palette', M, '/admin', 'desktop', L, 'palette:Rahul'],
];
const b = await chromium.launch();
const ctxs = new Map();
async function page(email, vp) {
  const k = email + vp;
  if (!ctxs.has(k)) ctxs.set(k, (await personaContext(b, BASE, email, { ...SIZES[vp], deviceScaleFactor: 1, reducedMotion: 'reduce' })).page);
  return ctxs.get(k);
}
const done = [];
for (const [name, email, path, vp, themes, action] of LIST) {
  if (FILTER && !FILTER.split(',').some((f) => name.includes(f))) continue;
  for (const theme of themes) {
    const p = await page(email, vp);
    await p.emulateMedia({ colorScheme: theme, reducedMotion: 'reduce' });
    await p.evaluate((t) => { try { localStorage.setItem('theme', t); } catch {} }, theme);
    await p.goto(BASE + path, { waitUntil: 'networkidle', timeout: 120000 });
    await p.waitForTimeout(500);
    if (action?.startsWith('palette:')) {
      await p.locator('.pos-command-trigger').first().click();
      await p.waitForTimeout(400);
      await p.keyboard.type(action.slice(8), { delay: 40 });
      await p.waitForTimeout(1500);
    }
    if (action === 'leave') { await p.locator('[data-pos-action="request_leave_header"], [data-pos-action="request_leave"]').first().click(); await p.waitForTimeout(1500); }
    if (action === 'ai') { await p.locator('button:has-text("Summarise")').first().click().catch(() => {}); await p.waitForTimeout(3000); }
    if (action === 'menu') { await p.locator('.fi-topbar-open-sidebar-btn').first().click().catch(() => {}); await p.waitForTimeout(800); }
    if (action === 'filters') { await p.locator('button[aria-controls="pos-people-filters"]').first().click().catch(() => {}); await p.waitForTimeout(500); }
    if (action === 'more') { const m = p.locator('.pos-phone-more:visible').first(); await m.scrollIntoViewIfNeeded().catch(() => {}); await m.click().catch(() => {}); await p.waitForTimeout(500); await m.evaluate((e) => e.closest('.pos-phone-cap')?.scrollIntoView({ block: 'start' })).catch(() => {}); await p.waitForTimeout(300); }
    if (action === 'bell') { await p.locator('.fi-topbar [aria-label^="Notifications"]').first().click().catch(() => {}); await p.waitForTimeout(1200); }
    const file = `${OUT}/${name}-${theme}.png`;
    await p.screenshot({ path: file });
    if (FULL) await p.screenshot({ path: `${FULL}/${name}-${theme}.png`, fullPage: true });
    done.push([name, email, path, vp, theme, action ?? null]);
    console.log('ok', name, theme);
    if (action) await p.keyboard.press('Escape').catch(() => {});
  }
}
writeFileSync(`${OUT}/capture-list.json`, JSON.stringify(done, null, 1));
await b.close();
