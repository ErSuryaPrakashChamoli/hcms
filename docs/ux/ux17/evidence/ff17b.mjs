// UX.17.27 Firefox recheck, interleaved: before and after loads alternate (same load conditions), 32 each per width,
// desktop and phone; each load waits for the network to settle and 1.5 s, like the matrix. node ff17b.mjs <out.txt>
import { firefox } from 'playwright';
import { personaContext } from './auth.mjs';
import { writeFileSync } from 'node:fs';
const servers = [['before (1220a27 code)', 'http://127.0.0.1:8091'], ['after (UX.17)', 'http://127.0.0.1:8090']];
const lines = [];
const b = await firefox.launch();
for (const [vp, viewport] of [['desktop', { width: 1440, height: 900 }], ['phone', { width: 390, height: 844 }]]) {
  const pages = [];
  for (const [label, base] of servers) pages.push({ label, base, ...(await personaContext(b, base, 'amit.verma@demo.local', { viewport })) , bad: 0, kinds: {} });
  for (let i = 0; i < 32; i++) {
    for (const s of pages) {
      const errs = [];
      const on = (e) => errs.push(String(e?.message ?? e).slice(0, 60));
      s.page.on('pageerror', on);
      await s.page.goto(s.base + '/admin', { waitUntil: 'networkidle', timeout: 120000 }).catch(() => {});
      await s.page.waitForTimeout(1500);
      s.page.off('pageerror', on);
      if (errs.length) { s.bad++; for (const e of errs) s.kinds[e] = (s.kinds[e] ?? 0) + 1; }
    }
  }
  for (const s of pages) { const line = `${s.label} · ${vp}: ${s.bad} of 32 loads with a page error ${JSON.stringify(s.kinds)}`; lines.push(line); console.log(line); await s.ctx.close(); }
}
writeFileSync(process.argv[2], lines.join('\n') + '\n');
await b.close();
