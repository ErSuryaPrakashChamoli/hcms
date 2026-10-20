import { chromium, firefox } from 'playwright';
import { personaContext } from './auth.mjs';
const BASE = 'http://127.0.0.1:8090';
const out = [];
for (const [n, e] of [['chromium', chromium], ['firefox', firefox]]) {
  const b = await e.launch();
  for (const vp of [{ width: 1440, height: 900 }, { width: 1280, height: 800 }]) {
    const { page, ctx } = await personaContext(b, BASE, 'neha.kapoor@demo.local', { viewport: vp, reducedMotion: 'reduce' });
    const errs = []; page.on('console', m => m.type() === 'error' && errs.push(m.text().slice(0, 120)));
    await page.goto(BASE + '/admin/people?view=list', { waitUntil: 'networkidle' });
    const persons = await page.locator('[data-person]').count();
    const target = page.locator('table [data-person]').first();
    await target.hover(); 
    let ok = true; try { await page.waitForSelector('#pos-peek', { state: 'visible', timeout: 6000 }); } catch { ok = false; }
    const text = ok ? (await page.locator('#pos-peek').innerText()).replace(/\s+/g, ' ').slice(0, 90) : '';
    // fresh load fonts in firefox: reload home and capture errors
    await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
    console.log(n, vp.width, 'data-person:', persons, 'peek:', ok, text, 'errors:', JSON.stringify(errs));
    await ctx.close();
  }
  await b.close();
}
