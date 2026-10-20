// UX.16.23 zero-training test: each role's question, answered by a first-time user who looks at the landing page
// first and then at the most obvious control. Same heuristics for the before (UX.15) and after (UX.16) code.
// node zt16.mjs <base> <out.json> [shotsDir]. Showcase data only (fictional people).
import { chromium } from 'playwright';
import { personaContext } from './auth.mjs';
import { writeFileSync, mkdirSync } from 'node:fs';
const [BASE, OUT, SHOTS] = process.argv.slice(2);
if (SHOTS) mkdirSync(SHOTS, { recursive: true });
const FOLD = 900;

// Each step either finds the answer on the current screen (returns the locator) or performs one interaction.
const TASKS = [
  { role: 'Employee', email: 'priya.nair@demo.local', ask: 'I need to take leave.',
    steps: [
      async (p) => { const b = p.locator('[data-pos-action="request_leave_header"], .pos-ws-actions button:has-text("Request leave")').first(); return (await b.count()) ? { click: b, then: '.fi-modal-window:visible >> text=Request leave' } : null; },
      async (p) => { const b = p.locator('[data-pos-action="request_leave"]').first(); return (await b.count()) ? { click: b, then: '.fi-modal-window:visible >> text=Request leave' } : null; },
    ] },
  { role: 'Manager', email: 'amit.verma@demo.local', ask: 'Show me what needs my attention.',
    // Needs both: the decisions waiting and what needs the manager about the team (probation, exceptions, reviews).
    steps: [async (p) => ({ answer: p.locator('section:has-text("Your team"):has-text("probation"), section:has-text("Team pulse"):has-text("review")').first(), also: p.locator('text=Decisions waiting for you').first() })] },
  { role: 'HR', email: 'neha.kapoor@demo.local', ask: 'Which employee changes need action?',
    steps: [async (p) => ({ answer: p.locator('section:has-text("People operations"):has-text("overdue")').first() })] },
  { role: 'Executive', email: 'meera.iyer@demo.local', ask: "What's happening in my workforce?",
    steps: [
      async (p) => { const h = p.locator('.pos-workforce-headline').first(); return (await h.count()) ? { answer: h } : null; },
      async (p) => { const b = p.locator('a:has-text("Open Workforce pulse")').first(); return (await b.count()) ? { click: b, then: 'text=/Workforce movement|No workforce movement|This month/' } : null; },
    ] },
  { role: 'Administrator', email: 'kavya.menon@demo.local', ask: 'What requires system attention?',
    steps: [
      async (p) => { const g = p.locator('section:has(h2:text-is("Governance")), section:has-text("Governance"):has-text("Admin Centre")').first(); return (await g.count()) && (await g.isVisible()) ? { answer: g } : null; },
      async (p) => { const c = p.locator('.pos-lens-chip:text-is("Platform")').first(); return (await c.count()) ? { click: c, then: 'section:has-text("Platform")' } : null; },
      async (p) => { const a = p.locator('.fi-sidebar a:has-text("Admin")').first(); return (await a.count()) ? { click: a, then: 'text=/Platform health|Awaiting approval/' } : null; },
    ] },
];

const b = await chromium.launch();
const results = [];
for (const t of TASKS) {
  const { page: p, ctx } = await personaContext(b, BASE, t.email, { viewport: { width: 1440, height: FOLD } });
  const start = Date.now();
  await p.goto(BASE + '/admin', { waitUntil: 'networkidle' }); await p.waitForTimeout(300);
  const landed = Date.now() - start;
  let clicks = 0, screens = 0, found = null, path = [], wrong = 0;
  for (const step of t.steps) {
    const r = await step(p);
    if (!r) { path.push('not here'); continue; }
    if (r.answer) { if ((await r.answer.count()) && (r.also ? await r.also.count() : true)) { found = r.answer; path.push('on landing'); break; } path.push('not here'); continue; }
    const before = p.url();
    const label = (await r.click.innerText().catch(() => "?")).trim().slice(0, 30); await r.click.click({ noWaitAfter: true }); clicks++; await p.locator(r.then).first().waitFor({ state: 'visible', timeout: 10000 }).catch(() => {});
    if (p.url() !== before) screens++;
    const target = p.locator(r.then).first();
    if (await target.count()) { found = target; path.push("clicked \"" + label + "\""); break; }
    wrong++; path.push('clicked, not found');
  }
  const ms = Date.now() - start;
  let aboveFold = null, deadEnds = null, links = 0;
  if (found) {
    await found.scrollIntoViewIfNeeded().catch(() => {});
    const box = await found.boundingBox().catch(() => null);
    aboveFold = clicks > 0 ? true : box ? (await p.evaluate(() => scrollY)) + box.y < FOLD : null;
    // Dead ends: links inside the answer that refuse this person (403/404/5xx).
    const hrefs = await found.locator('a[href]').evaluateAll((as) => as.map((a) => a.href).filter((h) => h.startsWith(location.origin) && !h.includes('#'))).catch(() => []);
    links = hrefs.length; deadEnds = 0;
    for (const h of [...new Set(hrefs)].slice(0, 12)) { const res = await p.request.get(h); if (res.status() >= 400) deadEnds++; }
    if (SHOTS) await p.screenshot({ path: `${SHOTS}/zt-${t.role.toLowerCase()}.png` });
  }
  results.push({ role: t.role, ask: t.ask, answered: !!found, clicks, extra_screens: screens, wrong_attempts: wrong, ms_to_answer: ms, ms_landing: landed, answer_in_first_screen: aboveFold, links_in_answer: links, dead_ends: deadEnds, path: path.join(' → ') });
  console.log(JSON.stringify(results.at(-1)));
  await ctx.close();
}
writeFileSync(OUT, JSON.stringify({ base: BASE, fold_px: FOLD, results }, null, 2));
await b.close();
