import { chromium } from 'playwright';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { personaContext } from './auth.mjs';
const require = createRequire(import.meta.url);
const axeSrc = readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');
const b = await chromium.launch();
for (const [theme, vp] of [['light', { width: 1440, height: 1000 }], ['dark', { width: 1440, height: 1000 }], ['light', { width: 390, height: 844 }], ['dark', { width: 390, height: 844 }]]) {
  const { page: p, ctx } = await personaContext(b, 'http://127.0.0.1:8090', 'neha.kapoor@demo.local', { viewport: vp, colorScheme: theme, reducedMotion: 'reduce', hasTouch: vp.width < 500, isMobile: vp.width < 500 });
  await p.emulateMedia({ colorScheme: theme }); await p.evaluate((t) => { try { localStorage.setItem('theme', t); } catch {} }, theme);
  await p.goto('http://127.0.0.1:8090/admin/people?view=grid', { waitUntil: 'networkidle' });
  await p.locator('.pos-person-card').first().click(); await p.waitForTimeout(1500);
  await p.evaluate(axeSrc);
  const r = await p.evaluate(async () => await axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] } }));
  console.log(JSON.stringify({ theme, width: vp.width, state: 'drawer-person (cards)', violations: r.violations.map((v) => v.id + ':' + v.nodes.length) }));
  await ctx.close();
}
await b.close();
