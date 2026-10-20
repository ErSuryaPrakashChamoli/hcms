// UX.18 browser timing: what a person waits for, split into server (time to first byte), transfer, and the browser's
// own work (DOM ready, load, largest contentful paint). Localhost, so network time is near zero by construction.
// One warm-up then REPS loads per page, median and p95. Chromium, phone 390 (touch) and desktop 1440.
// With BEFORE=<url>, each page is measured on both servers alternately (before/after/after/before), so machine drift
// affects both sides alike.
//   BASE=<url> [BEFORE=<url>] REPS=5 node browser-perf.mjs <out.json>      (needs ./auth.mjs from the Playwright workspace)
import { chromium } from 'playwright';
import { personaContext } from './auth.mjs';
import { writeFileSync } from 'node:fs';

const BASE = process.env.BASE ?? 'http://127.0.0.1:8090';
const SERVERS = process.env.BEFORE ? [['before', process.env.BEFORE], ['after', BASE]] : [['after', BASE]];
const REPS = +(process.env.REPS ?? 5);
const PAGES = [
    ['neha.kapoor', 'HR', 'Home', '/admin'], ['neha.kapoor', 'HR', 'People directory', '/admin/people'], ['neha.kapoor', 'HR', 'Employee 360', '/admin/employees/3'],
    ['amit.verma', 'Manager', 'Home', '/admin'], ['amit.verma', 'Manager', 'My work', '/admin/my-work'], ['amit.verma', 'Manager', 'Approval Center', '/admin/approvals'],
    ['meera.iyer', 'Executive', 'Home', '/admin'], ['meera.iyer', 'Executive', 'My work', '/admin/my-work'],
    ['kavya.menon', 'Administrator', 'Home', '/admin'], ['kavya.menon', 'Administrator', 'My work', '/admin/my-work'],
    ['priya.nair', 'Employee', 'Home', '/admin'],
];
const VPS = { phone: { viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true }, desktop: { viewport: { width: 1440, height: 900 } } };
const pct = (a, p) => { const s = [...a].sort((x, y) => x - y); return s[Math.max(0, Math.ceil(p * s.length) - 1)]; };

const b = await chromium.launch();
const rows = [];
let rowsFlip = false;
for (const [vp, opts] of Object.entries(VPS)) {
    const contexts = {};
    for (const [who, role, name, path] of PAGES) {
      for (const [side, base] of (rowsFlip = !rowsFlip) ? SERVERS : [...SERVERS].reverse()) {
        contexts[side + who] ??= await personaContext(b, base, `${who}@demo.local`, { ...opts, reducedMotion: 'reduce' });
        const { page: p } = contexts[side + who];
        const samples = [];
        for (let i = 0; i <= REPS; i++) {
            await p.goto(base + path, { waitUntil: 'load', timeout: 120000 });
            const t = await p.evaluate(() => new Promise((resolve) => {
                let lcp = 0;
                new PerformanceObserver((l) => { for (const e of l.getEntries()) lcp = Math.max(lcp, e.startTime); }).observe({ type: 'largest-contentful-paint', buffered: true });
                setTimeout(() => {
                    const n = performance.getEntriesByType('navigation')[0];
                    resolve({ ttfb: n.responseStart - n.requestStart, transfer: n.responseEnd - n.responseStart, dom: n.domContentLoadedEventEnd, load: n.loadEventEnd, lcp, bytes: n.transferSize });
                }, 300);
            }));
            if (i > 0) samples.push(t);
        }
        const col = (k) => samples.map((s) => s[k]);
        const row = { side, viewport: vp, viewer: who, role, surface: name, path, ttfb_median: Math.round(pct(col('ttfb'), 0.5)), ttfb_p95: Math.round(pct(col('ttfb'), 0.95)), transfer_median: Math.round(pct(col('transfer'), 0.5)),
            dom_median: Math.round(pct(col('dom'), 0.5)), load_median: Math.round(pct(col('load'), 0.5)), load_p95: Math.round(pct(col('load'), 0.95)), lcp_median: Math.round(pct(col('lcp'), 0.5)), html_bytes: Math.round(pct(col('bytes'), 0.5)) };
        rows.push(row);
        console.log(side, vp, role, name, 'ttfb', row.ttfb_median, 'dom', row.dom_median, 'load', row.load_median, 'lcp', row.lcp_median);
      }
    }
    for (const c of Object.values(contexts)) await c.ctx.close();
}
writeFileSync(process.argv[2], JSON.stringify({ base: BASE, reps: REPS, rows }, null, 2));
await b.close();
