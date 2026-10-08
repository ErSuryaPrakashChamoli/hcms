// UX.17.26 responsive matrix: each role's Home, My Work and its bar's own destination in Chromium, Firefox and the WebKit
// engine (Playwright WPE MiniBrowser, not Apple Safari) at phone 390, small phone 360, tablet 768 and desktop 1440.
// Records HTTP status, sideways overflow, script errors after settling, and on phones/tablets whether the role bar shows.
// WEBKIT_EXE=<run-webkit.sh> BASE=<url> node browser17.mjs <out.json>. Showcase data only (fictional people).
import { chromium, firefox, webkit } from 'playwright';
import { personaContext } from './auth.mjs';
import { writeFileSync } from 'node:fs';
const BASE = process.env.BASE ?? 'http://127.0.0.1:8090';
const exe = process.env.WEBKIT_EXE;
const personas = { 'priya.nair': '/admin/my-hr', 'amit.verma': '/admin/approvals', 'neha.kapoor': '/admin/people', 'meera.iyer': '/admin/workforce-command-centre', 'kavya.menon': '/admin/users', 'arjun.bose': '/admin/payroll-control-room' };
const VPS = { phone: { width: 390, height: 844 }, phone2: { width: 360, height: 780 }, tablet: { width: 768, height: 1024 }, desktop: { width: 1440, height: 900 } };
const engines = (process.env.ENGINES ?? 'chromium,firefox,webkit').split(',');
const out = [];
for (const [name, engine, opts] of [['chromium', chromium, {}], ['firefox', firefox, {}], ['webkit', webkit, { executablePath: exe }]].filter(([n]) => engines.includes(n))) {
  const b = await engine.launch(opts);
  for (const [vp, viewport] of Object.entries(VPS)) {
    const touch = vp !== 'desktop' && name !== 'firefox'; // Firefox has no touch/mobile emulation in Playwright
    for (const [who, own] of Object.entries(personas)) {
      const { page: p, ctx } = await personaContext(b, BASE, who + '@demo.local', { viewport, hasTouch: touch, isMobile: touch });
      for (const path of ['/admin', '/admin/my-work', own]) {
        const errors = [];
        const onErr = (e) => errors.push(String(e?.message ?? e).slice(0, 140));
        p.on('pageerror', onErr);
        const res = await p.goto(BASE + path, { waitUntil: 'networkidle', timeout: 120000 }).catch(() => null);
        await p.waitForTimeout(1500);
        const m = await p.evaluate(() => { const n = document.querySelector('.pos-bottom-nav'); return { overflow: document.documentElement.scrollWidth - innerWidth, bar: !!n && getComputedStyle(n).display !== 'none', items: n ? n.querySelectorAll('.pos-bottom-item').length : 0 }; }).catch(() => ({ overflow: null, bar: null, items: 0 }));
        p.off('pageerror', onErr);
        const barOk = vp === 'desktop' ? m.bar === false : (m.bar === true && m.items >= 4);
        const row = { engine: name, viewport: vp, who, path, status: res?.status() ?? 'ERR', overflow: m.overflow, bar: m.bar, items: m.items, errors, ok: res?.status() === 200 && m.overflow <= 1 && errors.length === 0 && barOk };
        out.push(row);
        if (!row.ok) console.log('BAD', JSON.stringify(row));
      }
      await ctx.close();
    }
  }
  await b.close();
}
writeFileSync(process.argv[2], JSON.stringify(out, null, 2));
const by = {}; for (const r of out) { by[r.engine] ??= { ok: 0, n: 0 }; by[r.engine].n++; if (r.ok) by[r.engine].ok++; }
console.log(JSON.stringify(by));
