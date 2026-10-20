// UX.17.27 Firefox recheck: the intermittent "uncaught exception: [object Object]" UX.16 traced to Filament's aborted
// database-notifications lazy load. 16 loads of the manager's Home per server and width; counts page errors.
// node ff17.mjs <out.txt>
import { firefox } from 'playwright';
import { personaContext } from './auth.mjs';
import { writeFileSync } from 'node:fs';
const lines = [];
const b = await firefox.launch();
for (const [label, base] of [['before (1220a27 code)', 'http://127.0.0.1:8091'], ['after (UX.17)', 'http://127.0.0.1:8090']]) {
  for (const [vp, viewport] of [['desktop', { width: 1440, height: 900 }], ['phone', { width: 390, height: 844 }]]) {
    const { page: p, ctx } = await personaContext(b, base, 'amit.verma@demo.local', { viewport });
    let bad = 0; const kinds = {};
    for (let i = 0; i < 16; i++) {
      const errs = [];
      const on = (e) => errs.push(String(e?.message ?? e).slice(0, 80));
      p.on('pageerror', on);
      await p.goto(base + '/admin', { waitUntil: 'networkidle', timeout: 120000 }).catch(() => {});
      await p.waitForTimeout(1500);
      p.off('pageerror', on);
      if (errs.length) { bad++; for (const e of errs) kinds[e] = (kinds[e] ?? 0) + 1; }
    }
    const line = `${label} · ${vp}: ${bad} of 16 loads with a page error ${JSON.stringify(kinds)}`;
    lines.push(line); console.log(line);
    await ctx.close();
  }
}
writeFileSync(process.argv[2], lines.join('\n') + '\n');
await b.close();
