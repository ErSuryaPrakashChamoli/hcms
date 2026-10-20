import { chromium } from 'playwright';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { personaContext } from './auth.mjs';
const require = createRequire(import.meta.url);
const axeSrc = readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');
const b = await chromium.launch();
const out = [];
for (const theme of ['light', 'dark']) {
  for (const vp of [{ width: 1440, height: 1000 }, { width: 390, height: 844 }]) {
    const { page: p, ctx } = await personaContext(b, 'http://127.0.0.1:8090', 'neha.kapoor@demo.local', { viewport: vp, colorScheme: theme, reducedMotion: 'reduce' });
    await p.emulateMedia({ colorScheme: theme }); await p.evaluate((t) => { try { localStorage.setItem('theme', t); } catch {} }, theme);
    await p.goto('http://127.0.0.1:8090/admin', { waitUntil: 'networkidle' });
    const homeChanged = await p.evaluate(() => [...document.querySelectorAll('.pos-sec-title')].find((h) => /What changed/.test(h.textContent))?.closest('section')?.innerText.slice(0, 120));
    for (const [label, url, open] of [['person drawer (cards)', '/admin/people?view=grid', '.pos-person-card'], ['change drawer (Recently changed)', '/admin/people?view=changed', 'button:has-text("What changed")']]) {
      await p.goto('http://127.0.0.1:8090' + url, { waitUntil: 'networkidle' });
      await p.locator(open).first().click(); await p.waitForTimeout(1500);
      await p.evaluate(axeSrc);
      const r = await p.evaluate(async () => await axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] } }));
      out.push({ theme, width: vp.width, state: label, violations: r.violations.map((v) => v.id + ':' + v.nodes.length) });
    }
    if (theme === 'light' && vp.width === 1440) console.log('HR Home "What changed" now:', homeChanged);
    await ctx.close();
  }
}
console.log(JSON.stringify(out));
await b.close();
