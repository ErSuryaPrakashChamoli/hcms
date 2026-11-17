import { browser, signedIn, BASE } from './lib.mjs';
const b = await browser(); const ctx = await signedIn(b, process.argv[2] ?? 'arjun.bose@demo.local'); const p = await ctx.newPage();
await p.goto(`${BASE}/admin/run-payroll?company=1&month=2026-10&step=checks`, { waitUntil: 'networkidle' }); await p.waitForTimeout(8000);
const t = (await p.locator('main').innerText()); const i = t.indexOf('3. PRE-PAYROLL'); console.log(t.slice(i, i + 2500).replace(/\n+/g, ' | '));
console.log('CHECKBOXES', await p.locator('main input[type=checkbox]').count());
console.log('BUTTONS', (await p.locator('main button').allInnerTexts()).map((x) => x.trim()).filter(Boolean).join(' | ').slice(0, 900));
await b.close();
