import { browser, signedIn, BASE } from '../capture/lib.mjs';
const b = await browser();
const who = process.argv[2] ?? 'admin@demo.local';
const ctx = await signedIn(b, who);
const p = await ctx.newPage();
for (const step of (process.argv[3] ?? 'month').split(',')) {
  await p.goto(`${BASE}/admin/run-payroll?company=1&month=2026-10&step=${step}`, { waitUntil: 'networkidle' });
  await p.waitForTimeout(6000);
  const t = (await p.locator('main').innerText()).replace(/\n+/g, ' | ');
  console.log(`\n=== ${step}: ${p.url().replace(BASE,'')}\n${t.slice(Math.max(0,t.indexOf(process.argv[4] ?? '5. REVIEW')), Math.max(0,t.indexOf(process.argv[4] ?? '5. REVIEW'))+2000)}`);
  console.log('BUTTONS:', (await p.locator('main button, main a[class*=btn]').allInnerTexts()).map(x=>x.trim()).filter(Boolean).join(' | ').slice(0, 600));
}
await b.close();
