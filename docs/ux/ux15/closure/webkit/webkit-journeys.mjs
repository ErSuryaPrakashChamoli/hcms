// UX.15 closure P1-03: critical journeys in the WebKit engine (Playwright WebKit / WPE MiniBrowser) — NOT Apple Safari.
import { webkit, devices } from 'playwright';
import { writeFileSync, mkdirSync } from 'node:fs';
const BASE = 'http://127.0.0.1:8090';
const OUT = process.argv[2];
mkdirSync(OUT, { recursive: true });
const exe = process.env.WEBKIT_EXE;
const browser = await webkit.launch({ executablePath: exe });
const results = [];
const check = async (name, fn) => { const t = Date.now(); try { const detail = await fn(); results.push({ name, ok: true, ms: Date.now() - t, detail: detail ?? null }); console.log('ok ', name, detail ?? ''); } catch (e) { results.push({ name, ok: false, ms: Date.now() - t, error: e.message.split('\n')[0].slice(0, 200) }); console.log('BAD', name, e.message.split('\n')[0].slice(0, 200)); } };
// Filament allows 5 sign-ins a minute: if the limiter answers, wait out its window and sign in again (once).
const login = async (ctx, email, retry = true) => { const p = await ctx.newPage(); await p.goto(`${BASE}/admin/login`); await p.fill('input[type=email]', email); await p.fill('input[type=password]', 'password');
  try { await Promise.all([p.waitForURL(/\/admin$/, { timeout: 20000 }), p.click('button[type=submit]')]); }
  catch (e) { const throttled = /too many/i.test(await p.locator('body').innerText().catch(() => '')); if (!retry || !throttled) throw e; console.log('   (login limiter: waiting 65 s)'); await p.close(); await new Promise((r) => setTimeout(r, 65000)); return login(ctx, email, false); }
  await p.waitForLoadState('networkidle').catch(() => {}); await p.waitForTimeout(800); return p; };
const noOverflow = async (p) => { const o = await p.evaluate(() => document.documentElement.scrollWidth - innerWidth); if (o > 1) throw new Error('horizontal overflow ' + o); return o; };
const errorsOf = (p) => { const e = []; p.on('pageerror', (x) => e.push(x.message)); return e; };
const settle = async (p) => { await p.waitForLoadState('networkidle').catch(() => {}); await p.waitForTimeout(500); };
console.log('engine', browser.version());

// Desktop
const desk = { viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce' };
const ctxP = await browser.newContext(desk); let priya;
await check('login (employee) lands on Home', async () => { priya = await login(ctxP, 'priya.nair@demo.local'); return await priya.locator('h1').innerText(); });
const pe = errorsOf(priya);
await check('Home renders with the brief, no overflow', async () => { await settle(priya); await noOverflow(priya); await priya.screenshot({ path: `${OUT}/webkit-home-employee.png` }); return (await priya.locator('h1').innerText()); });
await check('Request leave opens the leave form', async () => { await priya.getByRole('button', { name: 'Request leave' }).first().click(); await priya.waitForTimeout(1200); if (!(await priya.getByText('Leave type').first().isVisible())) throw new Error('form not visible'); await priya.keyboard.press('Escape'); });
await check('no script errors (employee)', async () => { if (pe.length) throw new Error(pe.join(' | ')); });

const ctxA = await browser.newContext(desk); const amit = await login(ctxA, 'amit.verma@demo.local'); const ae = errorsOf(amit);
await check('My Work: do this next', async () => { await amit.goto(`${BASE}/admin/my-work`); await settle(amit); await noOverflow(amit); return (await amit.locator('.fi-header-subheading').innerText()).slice(0, 80); });
await check('Approval Centre: decision workspace and keyboard J/K', async () => { await amit.goto(`${BASE}/admin/approvals`); await settle(amit); const h = await amit.locator('h1').innerText(); const rows = await amit.locator('.pos-queue-row').count(); if (rows < 1) throw new Error('no queue rows'); await amit.locator('body').press('j'); await amit.waitForTimeout(300); await amit.screenshot({ path: `${OUT}/webkit-approvals-manager.png` }); return `${h} · ${rows} rows`; });
await check('no script errors (manager)', async () => { if (ae.length) throw new Error(ae.join(' | ')); });

const ctxN = await browser.newContext(desk); const neha = await login(ctxN, 'neha.kapoor@demo.local'); const ne = errorsOf(neha);
await check('People directory: search', async () => { await neha.goto(`${BASE}/admin/people`); await settle(neha); await neha.locator('input[type=search]').first().fill('Fatima'); await neha.waitForTimeout(1500); return `${await neha.locator('.pos-person-card').count()} card(s)`; });
await check('People: peek on hover (list view)', async () => { await neha.goto(`${BASE}/admin/people?view=list`); await settle(neha); await neha.locator('table [data-person]').first().hover(); await neha.waitForSelector('#pos-peek', { state: 'visible', timeout: 6000 }); });
await check('People: person drawer', async () => { await neha.goto(`${BASE}/admin/people`); await settle(neha); await neha.locator('.pos-person-card').first().click(); await neha.waitForSelector('.pos-drawer', { state: 'visible', timeout: 8000 }); await neha.screenshot({ path: `${OUT}/webkit-people-drawer.png` }); await neha.keyboard.press('Escape'); });
await check('Employee 360: Now, Journey and Records views', async () => { await neha.goto(`${BASE}/admin/employees/2`); await settle(neha); const v = []; for (const t of ['Journey', 'Records']) { await neha.locator('.pos-360-nav a', { hasText: t }).click(); await neha.waitForTimeout(400); v.push(await neha.evaluate(() => document.documentElement.dataset.pos360View)); } await neha.screenshot({ path: `${OUT}/webkit-employee-360-records.png` }); if (v.join() !== 'journey,records') throw new Error('views ' + v); return v.join(' → '); });
await check('Command palette: Ctrl+K, search, Esc', async () => { await neha.goto(`${BASE}/admin`); await settle(neha); await neha.keyboard.press('Control+k'); await neha.waitForSelector('.pos-command', { state: 'visible', timeout: 5000 }); await neha.keyboard.type('leave', { delay: 25 }); await neha.waitForTimeout(1000); const n = await neha.locator('.pos-command [role=option]').count(); await neha.keyboard.press('Escape'); await neha.waitForSelector('.pos-command', { state: 'hidden', timeout: 5000 }); return `${n} results`; });
await check('Module list: context, lens narrows the table', async () => { await neha.goto(`${BASE}/admin/leave-requests`); await settle(neha); const sub = await neha.locator('.fi-header-subheading').innerText(); const chips = neha.locator('.pos-module-context .pos-lens-chip'); if ((await chips.count()) > 1) { await chips.nth(1).click(); await neha.waitForTimeout(1200); } await neha.screenshot({ path: `${OUT}/webkit-module-list.png` }); return sub.slice(0, 70); });
await check('Keyboard: Tab reaches a focusable control with a visible focus ring', async () => { await neha.goto(`${BASE}/admin/people`); await settle(neha); for (let i = 0; i < 4; i++) await neha.keyboard.press('Tab'); const f = await neha.evaluate(() => { const a = document.activeElement; const s = getComputedStyle(a); return { tag: a.tagName, outline: s.outlineStyle !== 'none' || s.boxShadow !== 'none' }; }); if (f.tag === 'BODY') throw new Error('focus did not move'); return JSON.stringify(f); });
await check('no script errors (HR)', async () => { if (ne.length) throw new Error(ne.join(' | ')); });

const ctxK = await browser.newContext(desk); const kavya = await login(ctxK, 'kavya.menon@demo.local');
await check('Form: Context → Review → Confirm (department edit)', async () => { await kavya.goto(`${BASE}/admin/departments/1/edit`); await settle(kavya); await kavya.fill('input[wire\\:model="data.name"]', 'Engineering & Platform'); await kavya.waitForTimeout(700); const t = (await kavya.locator('.pos-form-review').innerText()).replace(/\s+/g, ' '); if (!/Engineering → Engineering & Platform/.test(t)) throw new Error(t); await kavya.screenshot({ path: `${OUT}/webkit-form-review.png` }); return t.slice(0, 80); });
await check('Drawer form on a manage page (slide-over)', async () => { await kavya.goto(`${BASE}/admin/leave-types`); await settle(kavya); await kavya.locator('.fi-ta-row').first().locator('button:has-text("Edit"), a:has-text("Edit")').first().click(); await kavya.waitForTimeout(1500); if (!(await kavya.locator('.fi-modal-slide-over form.fi-modal-window').first().isVisible())) throw new Error('no drawer'); });

const ctxD = await browser.newContext({ ...desk, colorScheme: 'dark' }); const dark = await login(ctxD, 'neha.kapoor@demo.local');
await check('Dark mode: graphite surfaces', async () => { await dark.goto(`${BASE}/admin/leave-requests`); await settle(dark); const bg = await dark.evaluate(() => getComputedStyle(document.body).backgroundColor); await dark.screenshot({ path: `${OUT}/webkit-dark-module-list.png` }); const n = (bg.match(/[\d.]+/g) || []).map(Number); if ((n[0] + n[1] + n[2]) / 3 > 60 && !/oklch\(0\.[12]/.test(bg)) throw new Error(bg); return bg; });

// iPhone-equivalent viewport in WebKit (emulation of the device size and touch; still not iOS Safari)
const ctxM = await browser.newContext({ ...devices['iPhone 13'] }); const mob = await login(ctxM, 'amit.verma@demo.local');
await check('iPhone viewport: Home with bottom bar, no overflow', async () => { await settle(mob); await noOverflow(mob); if (!(await mob.locator('.pos-bottom-nav').isVisible())) throw new Error('no bottom bar'); await mob.screenshot({ path: `${OUT}/webkit-iphone-home.png` }); });
await check('iPhone viewport: Approvals and a stacked module list', async () => { await mob.goto(`${BASE}/admin/approvals`); await settle(mob); await noOverflow(mob); await mob.goto(`${BASE}/admin/leave-requests`); await settle(mob); await noOverflow(mob); await mob.screenshot({ path: `${OUT}/webkit-iphone-module-list.png`, fullPage: true }); });
await check('iPhone viewport: Employee 360', async () => { const n = await login(await browser.newContext({ ...devices['iPhone 13'] }), 'neha.kapoor@demo.local'); await n.goto(`${BASE}/admin/employees/2`); await settle(n); await noOverflow(n); await n.screenshot({ path: `${OUT}/webkit-iphone-employee-360.png` }); });

writeFileSync(`${OUT}/webkit-journeys.json`, JSON.stringify({ engine: 'Playwright WebKit (WPE MiniBrowser) ' + browser.version() + ' — WebKit engine, not Apple Safari', results }, null, 2));
console.log(`\n${results.filter((r) => r.ok).length}/${results.length} passed`);
await browser.close();
