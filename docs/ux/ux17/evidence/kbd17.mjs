// UX.17.20 keyboard review: desktop focus path and Ctrl+K/Esc; on a phone viewport (as with a paired keyboard or a
// switch device): the signal-row link, "Show N more", the directory filters, the palette's Cancel, the bar's names.
import { chromium } from 'playwright';
import { writeFileSync } from 'node:fs';
import { personaContext } from './auth.mjs';
const BASE = process.env.BASE ?? 'http://127.0.0.1:8090';
const b = await chromium.launch();
const r = {};
const desc = (p) => p.evaluate(() => {
  const e = document.activeElement; if (!e || e === document.body) return { name: 'body', ring: false };
  const cs = getComputedStyle(e);
  const row = e.closest('.pos-signal-row');
  const ring = (cs.outlineStyle !== 'none' && parseFloat(cs.outlineWidth) > 0) || (cs.boxShadow && cs.boxShadow !== 'none') || (row && getComputedStyle(row).boxShadow !== 'none');
  return { name: (e.getAttribute('aria-label') || e.innerText || e.placeholder || e.tagName).trim().replace(/\s+/g, ' ').slice(0, 50), ring: !!ring };
});

// Desktop: the first 30 tab stops on the manager's Home all show focus; Ctrl+K opens the palette with focus in the input; Esc returns focus.
{
  const { page: p, ctx } = await personaContext(b, BASE, 'amit.verma@demo.local', { viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce' });
  await p.goto(BASE + '/admin', { waitUntil: 'networkidle' });
  const path = [];
  for (let i = 0; i < 30; i++) { await p.keyboard.press('Tab'); path.push(await desc(p)); }
  r.desktopTabStops = path.length; r.desktopWithoutRing = path.filter((s) => !s.ring && s.name !== 'body').map((s) => s.name);
  await p.mouse.click(700, 500); await p.waitForTimeout(300);
  await p.keyboard.press('Control+k'); await p.waitForTimeout(400);
  r.ctrlKOpens = await p.evaluate(() => document.activeElement?.classList.contains('pos-command-input'));
  r.cancelHiddenOnDesktop = !(await p.locator('.pos-command-cancel').isVisible());
  await p.keyboard.press('Escape'); await p.waitForTimeout(300);
  r.desktopEscCloses = !(await p.locator('.pos-command').isVisible());
  await ctx.close();
}

// Phone viewport with a keyboard.
{
  const { page: p, ctx } = await personaContext(b, BASE, 'kavya.menon@demo.local', { viewport: { width: 390, height: 844 }, reducedMotion: 'reduce' });
  await p.goto(BASE + '/admin', { waitUntil: 'networkidle' });
  const link = p.locator('.pos-signal-row .pos-signal-link').first();
  await link.focus();
  r.signalLinkName = await link.evaluate((e) => e.innerText.replace(/\s+/g, ' ').trim());
  r.signalRowRing = (await desc(p)).ring;
  const more = p.locator('.pos-phone-more:visible').first();
  if (await more.count()) {
    await more.focus(); r.moreBefore = await more.getAttribute('aria-expanded');
    await p.keyboard.press('Enter'); await p.waitForTimeout(200); r.moreAfter = await more.getAttribute('aria-expanded');
    r.moreKeepsFocus = await more.evaluate((e) => document.activeElement === e);
  }
  const bar = await p.locator('.pos-bottom-nav a, .pos-bottom-nav button').evaluateAll((els) => els.map((e) => ({ name: (e.getAttribute('aria-label') || e.innerText).replace(/\s+/g, ' ').trim(), current: e.getAttribute('aria-current') })));
  r.barNames = bar.map((x) => x.name + (x.current ? ' [current]' : ''));
  const trigger = p.locator('.pos-command-trigger').first();
  r.triggerName = await trigger.evaluate((e) => e.innerText.replace(/\s+/g, ' ').trim() || e.getAttribute('aria-label'));
  await trigger.focus(); await p.keyboard.press('Enter'); await p.waitForTimeout(500);
  r.paletteFocus = await p.evaluate(() => document.activeElement?.classList.contains('pos-command-input'));
  r.barHiddenUnderPalette = !(await p.locator('.pos-bottom-nav').isVisible());
  // Tab inside the input switches the search scope (UX.15 design), so Esc is the keyboard exit; Cancel is a visible,
  // named button in the sheet for touch and screen-reader users (swipe order), and it closes the sheet.
  r.cancelInSheet = await p.locator('.pos-command .pos-command-cancel').evaluate((e) => !e.closest('[aria-hidden="true"], [inert]') && e.offsetParent !== null && e.innerText.trim());
  await p.keyboard.press('Escape'); await p.waitForTimeout(300);
  r.escCloses = !(await p.locator('.pos-command').isVisible());
  r.focusBackOnTrigger = await p.evaluate(() => !!document.activeElement?.closest('.pos-command-trigger'));
  await trigger.focus(); await p.keyboard.press('Enter'); await p.waitForTimeout(500);
  await p.locator('.pos-command-cancel').click(); await p.waitForTimeout(300);
  r.cancelCloses = !(await p.locator('.pos-command').isVisible());
  r.barBackAfterClose = await p.locator('.pos-bottom-nav').isVisible();
  await ctx.close();
}
{
  const { page: p, ctx } = await personaContext(b, BASE, 'neha.kapoor@demo.local', { viewport: { width: 390, height: 844 }, reducedMotion: 'reduce' });
  await p.goto(BASE + '/admin/people?view=list', { waitUntil: 'networkidle' });
  const f = p.locator('button[aria-controls="pos-people-filters"]');
  await f.focus(); r.filtersBefore = await f.getAttribute('aria-expanded');
  await p.keyboard.press('Enter'); await p.waitForTimeout(200);
  r.filtersAfter = await f.getAttribute('aria-expanded');
  r.filtersVisible = await p.locator('#pos-people-filters select').first().isVisible();
  await ctx.close();
}
writeFileSync(process.argv[2], JSON.stringify(r, null, 1));
console.log(JSON.stringify(r, null, 1));
await b.close();
