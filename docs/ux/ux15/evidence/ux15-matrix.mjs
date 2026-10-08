// UX.15.21: browser / device validation matrix on the showcase (fictional people). node ux15-matrix.mjs <out.json> [shotsDir]
import { chromium, firefox } from 'playwright';
import { personaContext } from './auth.mjs';
import { writeFileSync, mkdirSync } from 'node:fs';
const BASE = 'http://127.0.0.1:8090';
const [OUT, SHOTS] = process.argv.slice(2);
if (SHOTS) mkdirSync(SHOTS, { recursive: true });
const P = { emp: 'priya.nair@demo.local', mgr: 'amit.verma@demo.local', hr: 'neha.kapoor@demo.local', exec: 'meera.iyer@demo.local', admin: 'kavya.menon@demo.local' };
const SCREENS = [
  ['home-employee', P.emp, '/admin'], ['home-manager', P.mgr, '/admin'], ['my-work', P.mgr, '/admin/my-work'], ['approvals', P.mgr, '/admin/approvals'],
  ['people', P.hr, '/admin/people'], ['employee-360', P.hr, '/admin/employees/4'], ['org-map', P.hr, '/admin/organisation-map'],
  ['workforce-pulse', P.exec, '/admin/workforce-command-centre'], ['my-hr', P.emp, '/admin/my-hr'], ['admin-centre', P.admin, '/admin/admin-centre'],
];
const VIEWPORTS = { desktop: { width: 1440, height: 900 }, laptop: { width: 1280, height: 800 }, tablet: { width: 820, height: 1180 }, phone: { width: 390, height: 844 } };
const results = [];
for (const [engineName, engine] of [['chromium', chromium], ['firefox', firefox]]) {
  const browser = await engine.launch();
  for (const [vpName, viewport] of Object.entries(VIEWPORTS)) {
    const touch = (vpName === 'tablet' || vpName === 'phone') && engineName === 'chromium';
    const contexts = new Map();
    for (const [screen, email, path] of SCREENS) {
      if (!contexts.has(email)) contexts.set(email, await personaContext(browser, BASE, email, { viewport, hasTouch: touch, reducedMotion: 'reduce' }));
      const { page } = contexts.get(email);
      const errors = [];
      const onErr = (e) => errors.push('pageerror: ' + e.message.slice(0, 160));
      const onCon = (m) => { if (m.type() === 'error' && !/favicon|ERR_ABORTED/.test(m.text())) errors.push('console: ' + m.text().slice(0, 160)); };
      page.on('pageerror', onErr); page.on('console', onCon);
      const res = await page.goto(BASE + path, { waitUntil: 'networkidle', timeout: 120000 }).catch((e) => ({ status: () => 'ERR ' + e.message.slice(0, 80) }));
      await page.waitForTimeout(300);
      const m = await page.evaluate(() => {
        const vis = (el) => !!el && el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden';
        const offenders = [...document.querySelectorAll('body *')].filter((el) => { const r = el.getBoundingClientRect(); return r.width > 0 && r.right > window.innerWidth + 2 && getComputedStyle(el).position !== 'fixed' && !el.closest('[x-cloak],[aria-hidden=true],.pos-org-canvas,.pos-org-viewport,.overflow-x-auto,.pos-panel.overflow-x-auto,.pos-sectnav,.pos-lens,.pos-chips'); }).slice(0, 3).map((el) => el.tagName.toLowerCase() + '.' + [...el.classList].slice(0, 2).join('.'));
        return {
          overflowX: document.documentElement.scrollWidth - window.innerWidth,
          offenders,
          h1: vis(document.querySelector('h1')) ? document.querySelector('h1').textContent.trim().slice(0, 60) : null,
          bottomNav: vis(document.querySelector('.pos-bottom-nav')),
          sidebar: vis(document.querySelector('.fi-sidebar-nav')) && document.querySelector('.fi-sidebar')?.getBoundingClientRect().left >= 0,
        };
      });
      page.off('pageerror', onErr); page.off('console', onCon);
      const row = { engine: engineName, viewport: vpName, screen, status: res?.status?.(), ...m, errors };
      const phone = vpName === 'phone';
      row.ok = row.status === 200 && row.overflowX <= 1 && !!row.h1 && errors.length === 0 && (phone ? row.bottomNav : true);
      results.push(row);
      console.log(`${row.ok ? 'ok ' : 'BAD'} ${engineName.padEnd(8)} ${vpName.padEnd(7)} ${screen.padEnd(16)} ${row.status} ovf=${row.overflowX} h1=${!!row.h1} bnav=${row.bottomNav} side=${row.sidebar} ${errors.length ? JSON.stringify(errors) : ''} ${row.overflowX > 1 ? JSON.stringify(row.offenders) : ''}`);
      if (SHOTS && (engineName === 'firefox' || vpName === 'tablet')) await page.screenshot({ path: `${SHOTS}/${engineName}-${vpName}-${screen}.png` });
    }
    // Interactions on this engine / viewport.
    const { page } = contexts.get(P.hr);
    const check = async (name, fn) => { try { const v = await fn(); results.push({ engine: engineName, viewport: vpName, interaction: name, ok: !!v }); console.log(`${v ? 'ok ' : 'BAD'} ${engineName.padEnd(8)} ${vpName.padEnd(7)} [${name}]`); } catch (e) { results.push({ engine: engineName, viewport: vpName, interaction: name, ok: false, error: e.message.slice(0, 120) }); console.log(`BAD ${engineName} ${vpName} [${name}] ${e.message.slice(0, 120)}`); } };
    await page.goto(BASE + '/admin/people', { waitUntil: 'networkidle' });
    await check('command palette (Ctrl+K, Esc)', async () => { await page.keyboard.press('Control+k'); await page.waitForSelector('.pos-command', { state: 'visible', timeout: 5000 }); await page.keyboard.type('leave', { delay: 20 }); await page.waitForTimeout(800); const hits = await page.locator('.pos-command [role=option]').count(); await page.keyboard.press('Escape'); await page.waitForSelector('.pos-command', { state: 'hidden', timeout: 5000 }); return hits > 0; });
    await check('person drawer from a card', async () => { await page.locator('.pos-person-card').first().click(); await page.waitForSelector('.pos-drawer', { state: 'visible', timeout: 8000 }); await page.keyboard.press('Escape'); await page.waitForTimeout(400); return true; });
    if (vpName !== 'phone' && vpName !== 'tablet') await check('peek on hover', async () => { await page.getByRole('button', { name: /list/i }).first().click().catch(() => {}); await page.waitForTimeout(800); const p = page.locator('[data-person]').first(); await p.hover(); await page.waitForSelector('#pos-peek', { state: 'visible', timeout: 6000 }); return true; });
    if (vpName === 'phone') await check('bottom bar Actions opens actions', async () => { await page.locator('.pos-bottom-nav button').first().click(); await page.waitForSelector('.pos-command', { state: 'visible', timeout: 5000 }); await page.keyboard.press('Escape'); return true; });
    await check('dark scheme applies', async () => { await page.emulateMedia({ colorScheme: 'dark' }); await page.evaluate(() => { try { localStorage.setItem('theme', 'system'); } catch {} }); await page.reload({ waitUntil: 'networkidle' }); const bg = await page.evaluate(() => getComputedStyle(document.body).backgroundColor); await page.emulateMedia({ colorScheme: 'light' }); const n = bg.match(/\d+(\.\d+)?/g).map(Number); return (n[0] + n[1] + n[2]) / 3 < 60 || bg.includes('oklch(0.1') || bg.includes('oklch(0.2'); });
    for (const { ctx } of contexts.values()) await ctx.close();
  }
  await browser.close();
}
writeFileSync(OUT, JSON.stringify(results, null, 2));
const bad = results.filter((r) => !r.ok);
console.log(`\n${results.length} checks, ${results.length - bad.length} ok, ${bad.length} not ok`);
