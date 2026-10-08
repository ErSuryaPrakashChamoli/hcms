// UX.16.25: basic regression for role surfaces: every role's Home and My Work in Chromium, Firefox and the WebKit engine
// (not Apple Safari), at desktop 1440 and phone 390 (touch): HTTP status, horizontal overflow, script errors after settling.
import { chromium, firefox, webkit } from 'playwright';
import { personaContext } from './auth.mjs';
import { writeFileSync } from 'node:fs';
const BASE = 'http://127.0.0.1:8090';
const exe = process.env.WEBKIT_EXE;
const personas = ['priya.nair', 'amit.verma', 'neha.kapoor', 'meera.iyer', 'kavya.menon', 'arjun.bose'];
const out = [];
for (const [name, engine, opts] of [['chromium', chromium, {}], ['firefox', firefox, {}], ['webkit', webkit, { executablePath: exe }]]) {
  const b = await engine.launch(opts);
  for (const [vp, viewport, touch] of [['desktop', { width: 1440, height: 900 }, false], ['phone', { width: 390, height: 844 }, name !== 'firefox']]) {
    for (const who of personas) {
      const { page: p, ctx } = await personaContext(b, BASE, who + '@demo.local', { viewport, hasTouch: touch });
      for (const path of ['/admin', '/admin/my-work']) {
        const errors = [];
        const onErr = (e) => errors.push(String(e?.message ?? e).slice(0, 140));
        p.on('pageerror', onErr);
        const res = await p.goto(BASE + path, { waitUntil: 'networkidle', timeout: 120000 }).catch(() => null);
        await p.waitForTimeout(1500);
        const overflow = await p.evaluate(() => document.documentElement.scrollWidth - innerWidth);
        p.off('pageerror', onErr);
        const row = { engine: name, viewport: vp, who, path, status: res?.status() ?? 'ERR', overflow, errors, ok: res?.status() === 200 && overflow <= 1 && errors.length === 0 };
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
