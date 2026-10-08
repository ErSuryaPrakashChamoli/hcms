// Browser CPU per page load (Chromium Performance.getMetrics: ScriptDuration, LayoutDuration, RecalcStyleDuration,
// TaskDuration), old and new code alternately, 8 loads each after a warm-up; median.
import { chromium } from 'playwright';
import { personaContext } from './auth.mjs';
import { writeFileSync } from 'node:fs';
const SIDES = [['before', 'http://127.0.0.1:8093'], ['after', 'http://127.0.0.1:8094']];
const PAGES = [['neha.kapoor', '/admin'], ['neha.kapoor', '/admin/people'], ['amit.verma', '/admin'], ['amit.verma', '/admin/my-work'], ['kavya.menon', '/admin'], ['kavya.menon', '/admin/my-work']];
const VPS = { phone: { viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true }, desktop: { viewport: { width: 1440, height: 900 } } };
const med = (a) => { const s = [...a].sort((x, y) => x - y); return s[Math.floor(s.length / 2)]; };
const b = await chromium.launch();
const out = [];
for (const [vp, opts] of Object.entries(VPS)) {
  for (const [who, path] of PAGES) {
    const ctxs = {};
    for (const [side, base] of SIDES) ctxs[side] = await personaContext(b, base, `${who}@demo.local`, opts);
    const acc = { before: [], after: [] };
    for (let i = 0; i < 9; i++) {
      for (const [side, base] of i % 2 ? [...SIDES].reverse() : SIDES) {
        const p = ctxs[side].page;
        const cdp = await p.context().newCDPSession(p);
        await cdp.send('Performance.enable');
        const m0 = Object.fromEntries((await cdp.send('Performance.getMetrics')).metrics.map((m) => [m.name, m.value]));
        await p.goto(base + path, { waitUntil: 'load' });
        await p.waitForTimeout(1200);
        const m1 = Object.fromEntries((await cdp.send('Performance.getMetrics')).metrics.map((m) => [m.name, m.value]));
        await cdp.detach();
        if (i > 0) acc[side].push({ script: (m1.ScriptDuration - m0.ScriptDuration) * 1000, layout: (m1.LayoutDuration - m0.LayoutDuration) * 1000, style: (m1.RecalcStyleDuration - m0.RecalcStyleDuration) * 1000, task: (m1.TaskDuration - m0.TaskDuration) * 1000 });
      }
    }
    const row = { vp, who, path };
    for (const side of ['before', 'after']) for (const k of ['script', 'layout', 'style', 'task']) row[`${side}_${k}`] = Math.round(med(acc[side].map((x) => x[k])));
    out.push(row);
    console.log(vp, who, path, 'task', row.before_task, '->', row.after_task, 'script', row.before_script, '->', row.after_script, 'layout', row.before_layout, '->', row.after_layout, 'style', row.before_style, '->', row.after_style);
    for (const c of Object.values(ctxs)) await c.ctx.close();
  }
}
writeFileSync(process.argv[2], JSON.stringify(out, null, 1));
await b.close();
