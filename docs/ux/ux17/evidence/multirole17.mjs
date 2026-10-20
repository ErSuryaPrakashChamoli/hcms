// UX.17.18 multi-role on a phone: switch the Home view through every view the person holds and read, after each
// switch (no reload), the selected chip, the bar's experience and items, and where the decision count sits; then
// reload and check the stored default. Restores the person's first view at the end. node multirole17.mjs <out.json>
import { chromium } from 'playwright';
import { personaContext } from './auth.mjs';
import { writeFileSync } from 'node:fs';
const BASE = process.env.BASE ?? 'http://127.0.0.1:8090';
const b = await chromium.launch();
const out = {};
const state = (p) => p.evaluate(() => {
  const nav = document.querySelector('.pos-bottom-nav');
  return {
    chip: document.querySelector('.pos-lens-scroll [aria-selected="true"]')?.innerText.trim(),
    bar: nav?.dataset.experience,
    items: [...(nav?.querySelectorAll('.pos-bottom-item') ?? [])].map((a) => (a.lastElementChild?.childNodes[0]?.textContent ?? a.innerText).trim() + (a.querySelector('.pos-bottom-badge') ? `(${a.querySelector('.pos-bottom-badge').innerText})` : '')),
    lead: document.querySelector('.pos-home .pos-ws-main .pos-sec-title, .pos-home .pos-ws-main h2')?.innerText.trim().replace(/\s+/g, ' '),
  };
});
for (const who of ['kavya.menon', 'amit.verma', 'neha.kapoor']) {
  const { page: p, ctx } = await personaContext(b, BASE, who + '@demo.local', { viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true, reducedMotion: 'reduce' });
  await p.goto(BASE + '/admin', { waitUntil: 'networkidle' });
  const first = await state(p);
  const chips = await p.locator('.pos-lens-scroll [role="tab"]').allInnerTexts();
  const steps = [{ view: 'start', ...first }];
  for (const chip of chips) {
    await p.locator('.pos-lens-scroll [role="tab"]', { hasText: chip }).first().tap();
    await p.waitForFunction((c) => document.querySelector('.pos-lens-scroll [aria-selected="true"]')?.innerText.trim() === c, chip.trim(), { timeout: 15000 }).catch(() => {});
    await p.waitForTimeout(1200); // the bar re-renders on the switch event
    steps.push({ view: chip.trim(), ...(await state(p)) });
  }
  // The stored default survives a reload.
  await p.reload({ waitUntil: 'networkidle' });
  steps.push({ view: 'after reload', ...(await state(p)) });
  // Restore the first view.
  if (first.chip) { await p.locator('.pos-lens-scroll [role="tab"]', { hasText: first.chip }).first().tap(); await p.waitForTimeout(1500); }
  out[who] = { chips: chips.map((c) => c.trim()), steps };
  console.log(who, JSON.stringify(steps.map((s) => `${s.view} → bar ${s.bar}: ${s.items.join(' · ')}`), null, 1));
  await ctx.close();
}
writeFileSync(process.argv[2], JSON.stringify(out, null, 1));
await b.close();
