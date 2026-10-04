// UX.15 closure: recheck every first-pass matrix failure on the final code, each screen loaded on its own and settled.
import { chromium, firefox, webkit } from 'playwright';
import { personaContext } from './auth.mjs';
const exe = process.env.WEBKIT_EXE;
const BASE = 'http://127.0.0.1:8090';
const out = [];
// 1. Firefox script errors on Approvals (1440, 1280) and Workforce pulse (1280), with the error detail.
{
  const b = await firefox.launch();
  for (const [email, path, w, h] of [['amit.verma@demo.local', '/admin/approvals', 1440, 900], ['amit.verma@demo.local', '/admin/approvals', 1280, 800], ['meera.iyer@demo.local', '/admin/workforce-command-centre', 1280, 800]]) {
    const { page, ctx } = await personaContext(b, BASE, email, { viewport: { width: w, height: h } });
    const errs = []; page.on('pageerror', (e) => errs.push(`${e?.name ?? ''} ${String(e?.message ?? e).slice(0, 120)} @ ${String(e?.stack ?? '').split('\n')[0].slice(0, 120)}`));
    await page.goto(BASE + path, { waitUntil: 'networkidle' }); await page.waitForTimeout(2500);
    out.push(['firefox', path, w, 'script errors after settle', errs.length, errs]);
    await ctx.close();
  }
  await b.close();
}
// 2. Peek on hover in list view, every engine, 1440 and 1280.
for (const [name, engine, opts] of [['chromium', chromium, {}], ['firefox', firefox, {}], ['webkit', webkit, { executablePath: exe }]]) {
  const b = await engine.launch(opts);
  for (const vp of [{ width: 1440, height: 900 }, { width: 1280, height: 800 }]) {
    const { page, ctx } = await personaContext(b, BASE, 'neha.kapoor@demo.local', { viewport: vp });
    await page.goto(BASE + '/admin/people?view=list', { waitUntil: 'networkidle' });
    await page.locator('table [data-person]').first().hover();
    let ok = true; try { await page.waitForSelector('#pos-peek', { state: 'visible', timeout: 6000 }); } catch { ok = false; }
    out.push([name, '/admin/people?view=list', vp.width, 'peek on hover', ok]);
    await ctx.close();
  }
  await b.close();
}
for (const r of out) console.log(JSON.stringify(r));
