// UX.17.25 mobile zero-training: a first-time person on a 390 × 844 phone (touch) looks at the first screen, then taps
// the most obvious control. Same heuristics for the before and after code. Records taps, screens, whether the answer
// is in the first screen (above the bottom bar), whether the action control is visible and not covered, the path.
// node zt17.mjs <base> <out.json> [shotsDir]. Showcase data only (fictional people).
import { chromium } from 'playwright';
import { personaContext } from './auth.mjs';
import { writeFileSync, mkdirSync } from 'node:fs';
const [BASE, OUT, SHOTS] = process.argv.slice(2);
if (SHOTS) mkdirSync(SHOTS, { recursive: true });
const VP = { viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true, reducedMotion: 'reduce' };

// The visible fold: the viewport minus the bottom bar.
const fold = (p) => p.evaluate(() => { const n = document.querySelector('.pos-bottom-nav'); const v = n && getComputedStyle(n).display !== 'none' && n.getBoundingClientRect().height > 0; return innerHeight - (v ? n.getBoundingClientRect().height : 0); });
// In the first screen: the element's top is on screen and it starts above the fold, without scrolling.
const inFirstScreen = async (p, loc) => { const box = await loc.boundingBox().catch(() => null); if (!box) return false; const f = await fold(p); const sy = await p.evaluate(() => scrollY); return sy === 0 && box.y >= 0 && box.y + Math.min(box.height, 40) <= f; };
// Not covered: the element at its centre is the element itself (or inside it).
const uncovered = (loc) => loc.evaluate((el) => { el.scrollIntoView({ block: 'center' }); const r = el.getBoundingClientRect(); const at = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2); return !!at && (at === el || el.contains(at)); }).catch(() => false);
const visible = async (loc) => (await loc.count()) > 0 && (await loc.first().isVisible().catch(() => false));
const tapIf = async (p, sel) => { const l = p.locator(sel).first(); return (await visible(l)) ? l : null; };

const TASKS = [
  { role: 'Employee', email: 'priya.nair@demo.local', ask: 'I need to request leave.',
    steps: [
      async (p) => { const b = await tapIf(p, '.fi-main [data-pos-action="request_leave_header"]'); return b ? { tap: b, then: '.fi-modal-window:visible >> text=Request leave', control: '.fi-modal-window:visible .fi-modal-footer-actions button[type=submit], .fi-modal-window:visible .fi-modal-footer-actions button:has-text("Submit")' } : null; },
      async (p) => { const b = await tapIf(p, '.pos-bottom-nav button:has-text("Actions"), .pos-bottom-nav button:has-text("Start")'); return b ? { tap: b, then: '[role=option]:has-text("Request leave")' } : null; },
      async (p) => { const b = await tapIf(p, '[role=option]:has-text("Request leave")'); return b ? { tap: b, then: '.fi-modal-window:visible >> text=Request leave', control: '.fi-modal-window:visible .fi-modal-footer-actions button' } : null; },
    ] },
  { role: 'Manager', email: 'amit.verma@demo.local', ask: 'I need to approve this request.',
    steps: [
      async (p) => { const b = await tapIf(p, '.fi-main button:has-text("Review")'); return b && (await inFirstScreen(p, b)) ? { tap: b, then: '.pos-drawer .pos-btn-success', control: '.pos-drawer .pos-btn-success' } : null; },
      async (p) => { const b = await tapIf(p, '.pos-bottom-nav a:has-text("Approvals"), .pos-bottom-nav a:has-text("Decide")'); return b ? { tap: b, then: '.pos-queue-row' } : null; },
      async (p) => { const b = await tapIf(p, '.pos-bottom-nav a:has-text("Work")'); return b ? { tap: b, then: '.fi-main button:has-text("Review"), .pos-queue-row' } : null; },
      async (p) => { const b = await tapIf(p, '.fi-main .pos-queue-row, .fi-main button:has-text("Review")'); return b ? { tap: b, then: '.pos-drawer .pos-btn-success', control: '.pos-drawer .pos-btn-success' } : null; },
    ] },
  { role: 'HR', email: 'neha.kapoor@demo.local', ask: 'Which employee changes need attention?',
    steps: [
      async (p) => { const a = p.locator('.fi-main section:has-text("People operations") .pos-stream-row').first(); return (await visible(a)) && (await inFirstScreen(p, a)) ? { answer: a } : null; },
      async (p) => { const b = await tapIf(p, '.pos-bottom-nav a:has-text("Work")'); return b ? { tap: b, then: '.fi-main section:has-text("People operations") .pos-stream-row', firstScreen: true } : null; },
    ] },
  { role: 'Executive', email: 'meera.iyer@demo.local', ask: 'What is happening in my workforce?',
    steps: [
      async (p) => { const a = p.locator('.fi-main .pos-workforce-headline').first(); return (await visible(a)) && (await inFirstScreen(p, a)) ? { answer: a } : null; },
      async (p) => { const b = await tapIf(p, '.pos-bottom-nav a:has-text("Pulse"), .fi-main a:has-text("Open Workforce pulse")'); return b ? { tap: b, then: '.fi-main :text("Workforce movement"), .fi-main .pos-workforce-headline', firstScreen: true } : null; },
    ] },
  { role: 'Administrator', email: 'kavya.menon@demo.local', ask: 'What requires system attention?',
    steps: [
      async (p) => { const a = p.locator('.fi-main section:has(h2:text-is("Governance")) .pos-stream-row, .fi-main section:has-text("Governance") .pos-stream-row').first(); return (await visible(a)) && (await inFirstScreen(p, a)) ? { answer: a } : null; },
      async (p) => { const b = await tapIf(p, '.pos-bottom-nav a:has-text("Admin"), .fi-main a:has-text("Open Admin Centre")'); return b ? { tap: b, then: '.fi-main :text("Awaiting approval"), .fi-main :text("Platform health"), .fi-main :text("Governance")' } : null; },
    ] },
  { role: 'Manager', email: 'amit.verma@demo.local', ask: 'Show me my team.',
    steps: [
      async (p) => { const b = await tapIf(p, '.pos-bottom-nav a:has-text("Team"), .pos-bottom-nav a:has-text("People")'); return b ? { tap: b, then: '.fi-main :text("My team"), .fi-main :text("Your team")', firstScreen: true } : null; },
    ] },
  { role: 'HR', email: 'neha.kapoor@demo.local', ask: 'Find Rahul Sharma.',
    steps: [
      async (p) => { const b = await tapIf(p, '.pos-command-trigger'); return b ? { tap: b, then: '.pos-command-input', type: 'Rahul', after: '[role=option]:has-text("Rahul Sharma")', keyboard: true } : null; },
      async (p) => { const b = await tapIf(p, '[role=option]:has-text("Rahul Sharma")'); return b ? { tap: b, then: '.fi-main h1:has-text("Rahul Sharma"), .pos-drawer:has-text("Rahul Sharma")' } : null; },
    ] },
];

const b = await chromium.launch();
const results = [];
for (const t of TASKS) {
  const { page: p, ctx } = await personaContext(b, BASE, t.email, VP);
  await p.goto(BASE + '/admin', { waitUntil: 'networkidle' });
  await p.waitForTimeout(400);
  const start = Date.now();
  let taps = 0, screens = 0, found = null, path = [], controlOk = null, keyboardOk = null, firstScreen = null;
  for (const step of t.steps) {
    const r = await step(p);
    if (!r) { path.push('—'); continue; }
    if (r.answer) { found = r.answer; firstScreen = true; path.push('in the first screen'); break; }
    const before = p.url();
    const label = (await r.tap.innerText().catch(() => '?')).trim().replace(/\s+/g, ' ').slice(0, 28);
    const isLink = await r.tap.evaluate((el) => el.tagName === 'A' && !!el.getAttribute('href') && !el.getAttribute('href').startsWith('#')).catch(() => false);
    await r.tap.tap({ noWaitAfter: true }).catch(async () => r.tap.click({ noWaitAfter: true }));
    taps++;
    if (isLink) await p.waitForURL((u) => u.toString() !== before, { timeout: 12000 }).catch(() => {});
    if (p.url() !== before) { screens++; await p.waitForLoadState('networkidle').catch(() => {}); await p.waitForTimeout(300); }
    await p.locator(r.then).first().waitFor({ state: 'visible', timeout: 12000 }).catch(() => {});
    path.push(`tap "${label}"`);
    if (r.type) {
      await p.keyboard.type(r.type, { delay: 40 });
      await p.locator(r.after).first().waitFor({ state: 'visible', timeout: 8000 }).catch(() => {});
      // With the on-screen keyboard up, roughly the top 55% of a phone screen stays visible.
      const box = await p.locator(r.after).first().boundingBox().catch(() => null);
      keyboardOk = !!box && box.y + box.height <= 844 * 0.55;
      path.push(`type "${r.type}"`);
      continue;
    }
    const target = p.locator(r.then).first();
    if (await visible(target)) {
      found = target;
      if (r.firstScreen) firstScreen = await inFirstScreen(p, target);
      if (r.control) { const c = p.locator(r.control).first(); controlOk = (await visible(c)) ? await uncovered(c) : false; }
      break;
    }
  }
  const ms = Date.now() - start;
  if (SHOTS) await p.screenshot({ path: `${SHOTS}/zt-${t.role.toLowerCase()}-${t.ask.split(' ').slice(0, 3).join('-').replace(/[^a-z-]/gi, '').toLowerCase()}.png` });
  const row = { role: t.role, ask: t.ask, answered: !!found, taps, screens, in_first_screen: firstScreen, action_control_uncovered: controlOk, results_above_keyboard: keyboardOk, ms, path: path.join(' → ') };
  results.push(row);
  console.log(JSON.stringify(row));
  await ctx.close();
}
writeFileSync(OUT, JSON.stringify({ base: BASE, viewport: '390x844 touch', results }, null, 2));
await b.close();
