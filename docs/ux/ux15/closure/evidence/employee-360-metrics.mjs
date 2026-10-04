// UX.15 closure P1-04: Employee 360 density metrics (panels, sections, above-fold, scroll depth) + full-page captures.
import { chromium, devices } from 'playwright';
import { personaContext } from './auth.mjs';
const BASE = process.env.BASE ?? 'http://127.0.0.1:8090';
const [path, out, label] = process.argv.slice(2);
const b = await chromium.launch();
const res = {};
for (const [vpName, opts] of [['desktop', { viewport: { width: 1440, height: 900 } }], ['mobile', { ...devices['iPhone 13'], deviceScaleFactor: 1 }]]) {
  const { page } = await personaContext(b, BASE, 'neha.kapoor@demo.local', { ...opts, reducedMotion: 'reduce' });
  await page.goto(BASE + path, { waitUntil: 'networkidle' }); await page.waitForTimeout(700);
  res[vpName] = await page.evaluate(() => {
    const main = document.querySelector('.fi-page') || document.body;
    const vis = (el) => el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden';
    const panels = [...main.querySelectorAll('.pos-panel, .pos-card, .fi-section')].filter(vis);
    const outer = panels.filter((el) => !panels.some((o) => o !== el && o.contains(el)));
    const fold = window.innerHeight;
    const above = outer.filter((el) => el.getBoundingClientRect().top < fold);
    const headings = [...main.querySelectorAll('h2, h3, .pos-sec-title')].filter(vis).map((h) => h.textContent.trim().replace(/\s+/g, ' ').slice(0, 40));
    return { panels: outer.length, panelsAboveFold: above.length, scrollHeight: document.documentElement.scrollHeight, viewport: fold, screens: +(document.documentElement.scrollHeight / fold).toFixed(1), headings: headings.length, headingList: headings.slice(0, 40) };
  });
  await page.screenshot({ path: `${out}/${label}-${vpName}-full.png`, fullPage: true });
}
console.log(JSON.stringify(res, null, 1));
await b.close();
