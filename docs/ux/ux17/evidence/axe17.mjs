// UX.17.20 targeted accessibility: axe-core (WCAG 2.0/2.1/2.2 A + AA) on the surfaces UX.17 changed, at phone (touch),
// tablet and desktop, light and dark, including open states (palette sheet, modal form, filters, expanded lists,
// assistant, menu). node axe17.mjs <out.json>. Showcase data only (fictional people).
import { chromium } from 'playwright';
import { readFileSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { personaContext } from './auth.mjs';
const require = createRequire(import.meta.url);
const axeSrc = readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');
const BASE = process.env.BASE ?? 'http://127.0.0.1:8090';
const VP = {
  phone: { viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true },
  tablet: { viewport: { width: 768, height: 1024 }, hasTouch: true, isMobile: true },
  desktop: { viewport: { width: 1440, height: 1000 } },
};
const E = 'priya.nair@demo.local', M = 'amit.verma@demo.local', H = 'neha.kapoor@demo.local', X = 'meera.iyer@demo.local', A = 'kavya.menon@demo.local', Y = 'arjun.bose@demo.local';
const CHECKS = [
  ['phone', E, '/admin'], ['phone', E, '/admin', 'leave'], ['phone', E, '/admin', 'palette'], ['phone', E, '/admin/my-work'], ['phone', E, '/admin/notifications'],
  ['phone', M, '/admin'], ['phone', M, '/admin', 'more'], ['phone', M, '/admin/approvals'], ['phone', M, '/admin/employees/3'], ['phone', M, '/admin/my-team'], ['phone', M, '/admin/notifications'],
  ['phone', H, '/admin'], ['phone', H, '/admin/people?view=list'], ['phone', H, '/admin/people?view=list', 'filters'], ['phone', H, '/admin/employees/3', 'ai'], ['phone', H, '/admin/employees'],
  ['phone', X, '/admin'], ['phone', X, '/admin/organisation-map'],
  ['phone', A, '/admin'], ['phone', A, '/admin/users'], ['phone', A, '/admin', 'menu'],
  ['phone', Y, '/admin'],
  ['tablet', M, '/admin'], ['tablet', H, '/admin/employees/3'], ['tablet', A, '/admin'],
  ['desktop', M, '/admin'], ['desktop', M, '/admin/employees/3'], ['desktop', H, '/admin/people?view=list'], ['desktop', E, '/admin', 'palette'],
];
const themes = (process.env.THEMES ?? 'light,dark').split(',');
const b = await chromium.launch();
const pages = new Map();
const out = [];
for (const theme of themes) {
  for (const [vp, email, path, state] of CHECKS) {
    const key = email + vp + theme;
    if (!pages.has(key)) { const { page } = await personaContext(b, BASE, email, { ...VP[vp], colorScheme: theme, reducedMotion: 'reduce' }); pages.set(key, page); }
    const p = pages.get(key);
    await p.emulateMedia({ colorScheme: theme, reducedMotion: 'reduce' });
    await p.evaluate((t) => { try { localStorage.setItem('theme', t); } catch {} }, theme);
    await p.goto(BASE + path, { waitUntil: 'networkidle' }); await p.waitForTimeout(600);
    try {
      if (state === 'leave') { await p.locator('[data-pos-action="request_leave_header"]').first().click(); await p.waitForTimeout(1500); }
      if (state === 'palette') { await p.locator('.pos-command-trigger').first().click(); await p.waitForTimeout(400); await p.keyboard.type('leave', { delay: 20 }); await p.waitForTimeout(1200); }
      if (state === 'more') { await p.locator('.pos-phone-more:visible').first().click(); await p.waitForTimeout(400); }
      if (state === 'filters') { await p.locator('button[aria-controls="pos-people-filters"]').first().click(); await p.waitForTimeout(400); }
      if (state === 'ai') { await p.locator('button:has-text("Summarise")').first().click(); await p.waitForTimeout(3000); }
      if (state === 'menu') { await p.locator('.fi-topbar-open-sidebar-btn').first().click(); await p.waitForTimeout(700); }
    } catch (e) { out.push({ theme, vp, email, path, state, error: String(e).slice(0, 160) }); }
    await p.evaluate(axeSrc);
    const r = await p.evaluate(async () => await axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] } }));
    const row = { theme, vp, who: email.split('@')[0], path, state: state ?? '', violations: r.violations.map((v) => ({ id: v.id, impact: v.impact, n: v.nodes.length, sample: v.nodes.slice(0, 3).map((n) => n.target.join(' ') + ' :: ' + (n.failureSummary || '').split('\n').slice(1, 2).join(' ')) })) };
    out.push(row);
    if (row.violations.length) console.log('VIOLATION', JSON.stringify(row).slice(0, 600));
  }
}
writeFileSync(process.argv[2], JSON.stringify(out, null, 1));
console.log('checks', out.filter((r) => !r.error).length, 'with violations', out.filter((r) => r.violations?.length).length, 'errors', out.filter((r) => r.error).length);
await b.close();
