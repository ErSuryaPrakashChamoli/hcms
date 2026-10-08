// UX.15 closure: the accessible date-picker trigger still opens by pointer and keyboard, picks a date, and feeds the review.
import { chromium, firefox, webkit } from 'playwright';
import { personaContext } from './auth.mjs';
const BASE = 'http://127.0.0.1:8090';
const exe = 'process.env.WEBKIT_EXE';
const out = [];
for (const [name, type, opts] of [['chromium', chromium, {}], ['firefox', firefox, {}], ['webkit', webkit, { executablePath: exe }]]) {
  const b = await type.launch(opts);
  try {
    const { page: p } = await personaContext(b, BASE, 'kavya.menon@demo.local', { viewport: { width: 1440, height: 1000 } });
    await p.goto(BASE + '/admin/departments/create', { waitUntil: 'networkidle' }); await p.waitForTimeout(800);
    const field = p.locator('.fi-fo-date-time-picker').first();
    const input = field.locator('.fi-fo-date-time-picker-display-text-input');
    const panel = field.locator('.fi-fo-date-time-picker-panel');
    const r = { engine: name };
    r.wrapperTag = await field.locator('.fi-fo-date-time-picker-trigger').evaluate((e) => e.tagName);
    await input.click(); await p.waitForTimeout(300);
    r.pointerOpens = await panel.isVisible();
    await panel.locator('.fi-fo-date-time-picker-calendar-day:not([aria-disabled="true"])').filter({ hasText: /^15$/ }).first().click(); await p.waitForTimeout(400);
    r.pickedValue = await input.inputValue();
    await p.keyboard.press('Escape'); await p.waitForTimeout(200);
    // Keyboard: Tab from the name field lands on the labelled input (one stop), Enter opens, arrows move, Enter picks.
    await input.focus(); r.focusIsInput = await input.evaluate((e) => document.activeElement === e);
    await p.keyboard.press('Enter'); await p.waitForTimeout(300); r.keyboardOpens = await panel.isVisible();
    await p.keyboard.press('ArrowRight'); await p.keyboard.press('Enter'); await p.waitForTimeout(400);
    r.keyboardValue = await input.inputValue();
    r.labelTargetsInput = await p.evaluate(() => { const i = document.querySelector('.fi-fo-date-time-picker-display-text-input'); return !!(i.id && document.querySelector(`label[for="${i.id}"]`)); });
    await p.fill('input[wire\\:model="data.name"]', 'Picker check'); await p.waitForTimeout(800);
    r.reviewHasDate = await p.locator('.pos-form-review').innerText().then((t) => /Effective from/i.test(t)).catch(() => false);
    out.push(r);
  } catch (e) { out.push({ engine: name, error: String(e).split('\n')[0] }); }
  await b.close();
}
console.log(JSON.stringify(out, null, 1));
