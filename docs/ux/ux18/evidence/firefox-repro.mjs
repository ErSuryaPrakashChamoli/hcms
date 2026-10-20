// UX.18 Firefox "[object Object]" investigation. For each engine, persona and timing: load Home, wait a fixed delay
// (so a request started by the page is still in flight), navigate away, and record page errors, the reason of any
// unhandled promise rejection, and which Livewire requests were in flight (component names from the request body).
// A second pass slows Livewire update requests (simulated slow network) so the in-flight window is wide.
//   BASE=<url> WEBKIT_EXE=<launcher> node firefox-repro.mjs <out.json>   (needs ./auth.mjs)
import { chromium, firefox, webkit } from 'playwright';
import { personaContext } from './auth.mjs';
import { writeFileSync } from 'node:fs';

const BASE = process.env.BASE ?? 'http://127.0.0.1:8090';
const TRIALS = +(process.env.TRIALS ?? 6);
const engines = (process.env.ENGINES ?? 'firefox,chromium,webkit').split(',');
const personas = (process.env.PERSONAS ?? 'amit.verma,neha.kapoor,priya.nair').split(',');
const out = [];

for (const [name, engine, opts] of [['firefox', firefox, {}], ['chromium', chromium, {}], ['webkit', webkit, { executablePath: process.env.WEBKIT_EXE }]].filter(([n]) => engines.includes(n))) {
    const b = await engine.launch(opts);
    for (const who of personas) {
        for (const slow of [0, 1500]) {
            for (const delay of [0, 150, 400, 900]) {
                const { page: p, ctx } = await personaContext(b, BASE, `${who}@demo.local`, { viewport: { width: 1440, height: 900 } });
                await p.addInitScript(() => {
                    window.__rejections = [];
                    window.addEventListener('unhandledrejection', (e) => {
                        let reason;
                        try { reason = JSON.stringify(e.reason, Object.getOwnPropertyNames(e.reason ?? {})).slice(0, 300); } catch { reason = String(e.reason); }
                        window.__rejections.push({ type: Object.prototype.toString.call(e.reason), reason });
                    });
                });
                const inflight = new Set();
                await p.route('**/livewire*/update', async (route) => {
                    const body = route.request().postData() ?? '';
                    const names = [...body.matchAll(/\\"name\\":\\"([^\\"]+)\\"/g)].map((m) => m[1]);
                    const tag = names.join(',') || 'update';
                    inflight.add(tag);
                    if (slow) await new Promise((r) => setTimeout(r, slow));
                    await route.continue().catch(() => {});
                    inflight.delete(tag);
                });
                let errors = 0, kinds = {}, rejections = [], inflightAtLeave = [];
                for (let t = 0; t < TRIALS; t++) {
                    const errs = [];
                    const on = (e) => errs.push(String(e?.message ?? e).slice(0, 80));
                    p.on('pageerror', on);
                    await p.goto(BASE + '/admin', { waitUntil: 'domcontentloaded', timeout: 120000 }).catch(() => {});
                    await p.waitForTimeout(delay);
                    inflightAtLeave.push([...inflight].join('|') || '—');
                    const r = await p.evaluate(() => window.__rejections ?? []).catch(() => []);
                    await p.goto(BASE + '/admin/notifications', { waitUntil: 'networkidle', timeout: 120000 }).catch(() => {});
                    await p.waitForTimeout(300);
                    p.off('pageerror', on);
                    rejections.push(...r, ...(await p.evaluate(() => window.__rejections ?? []).catch(() => [])));
                    if (errs.length) { errors++; for (const e of errs) kinds[e] = (kinds[e] ?? 0) + 1; }
                }
                const row = { engine: name, who, slow_ms: slow, delay_ms: delay, trials: TRIALS, loads_with_error: errors, errors: kinds, rejections: rejections.slice(0, 4), inflight_at_leave: [...new Set(inflightAtLeave)] };
                out.push(row);
                console.log(name, who, 'slow', slow, 'delay', delay, '→', errors, '/', TRIALS, JSON.stringify(kinds), row.inflight_at_leave.join(' ; ').slice(0, 120));
                await ctx.close();
            }
        }
    }
    await b.close();
}
writeFileSync(process.argv[2], JSON.stringify({ base: BASE, rows: out }, null, 1));
