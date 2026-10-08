// UX.15.19: interactions and visuals on the 10,000+ synthetic tenant (port 8091).
import { chromium } from 'playwright';
import { personaContext } from './auth.mjs';
const base = 'http://127.0.0.1:8091';
const out = process.argv[2];
const browser = await chromium.launch();
const log = (...a) => console.log(...a);
const timed = async (label, fn) => { const s = Date.now(); try { await fn(); log(`${label}: ${Date.now() - s} ms`); } catch (e) { log(`${label}: FAILED ${e.message.split('\n')[0]}`); } };
const shot = async (page, name, full = false) => page.screenshot({ path: `${out}/${name}.png`, fullPage: full });
const settle = async (page) => { await page.waitForLoadState('networkidle').catch(() => {}); await page.waitForTimeout(400); };

// HR: People at 10k, load more, search; org map expand-all; 360 of a long-named person; pulse drill.
{
  const { ctx, page } = await personaContext(browser, base, 'neha.kapoor@demo.local', { viewport: { width: 1440, height: 900 } });
  const errors = []; page.on('pageerror', e => errors.push(e.message)); page.on('console', m => m.type() === 'error' && errors.push(m.text()));
  await timed('people load', async () => { await page.goto(`${base}/admin/people`); await settle(page); });
  log('people subheading:', (await page.locator('.fi-header-subheading').first().textContent().catch(() => ''))?.trim());
  await shot(page, 'scale-people-hr');
  const more = page.getByRole('button', { name: /show more|load more/i }).first();
  if (await more.count()) await timed('people load more', async () => { await more.click(); await settle(page); });
  await timed('people search', async () => { await page.locator('input[type=search]').first().fill('Venkata'); await settle(page); });
  await shot(page, 'scale-people-search-long-name');
  await timed('org map load', async () => { await page.goto(`${base}/admin/organisation-map`); await settle(page); });
  await shot(page, 'scale-orgmap-hr');
  const expand = page.getByRole('button', { name: /expand all/i }).first();
  if (await expand.count()) { await timed('org map expand all', async () => { await expand.click(); await settle(page); }); log('org nodes rendered:', await page.locator('[role=treeitem]').count()); await shot(page, 'scale-orgmap-expanded'); }
  const depts = page.getByRole('button', { name: /departments/i }).first();
  if (await depts.count()) { await timed('org map departments', async () => { await depts.click(); await settle(page); }); await shot(page, 'scale-orgmap-departments'); }
  log('neha errors:', JSON.stringify(errors.slice(0, 5)));
  await ctx.close();
}
// Manager with 120+ reports and 300 pending: Home, Approvals (paged), My Work, show more.
{
  const { ctx, page } = await personaContext(browser, base, 'amit.verma@demo.local', { viewport: { width: 1440, height: 900 } });
  const errors = []; page.on('pageerror', e => errors.push(e.message));
  await timed('manager home', async () => { await page.goto(`${base}/admin`); await settle(page); });
  await shot(page, 'scale-home-manager');
  await timed('approvals', async () => { await page.goto(`${base}/admin/approvals`); await settle(page); });
  log('approvals heading:', (await page.locator('.fi-header-heading').first().textContent())?.trim());
  log('approvals subheading:', (await page.locator('.fi-header-subheading').first().textContent().catch(() => ''))?.trim());
  log('queue rows:', await page.locator('.pos-queue-row').count(), 'detail cards:', await page.locator('.pos-approval-detail').count());
  await shot(page, 'scale-approvals-manager');
  const more = page.getByRole('button', { name: /show \d+ more/i }).first();
  if (await more.count()) await timed('approvals show more', async () => { await more.click(); await settle(page); log('queue rows after:', await page.locator('.pos-queue-row').count()); });
  const row = page.locator('.pos-queue-row').nth(3);
  await timed('select a decision', async () => { await row.click(); await page.waitForTimeout(300); });
  await timed('my work', async () => { await page.goto(`${base}/admin/my-work`); await settle(page); });
  await shot(page, 'scale-mywork-manager');
  const all = page.getByRole('button', { name: /show all \d+/i }).first();
  if (await all.count()) await timed('my work show all', async () => { await all.click(); await settle(page); });
  await timed('people (manager)', async () => { await page.goto(`${base}/admin/people`); await settle(page); });
  log('manager people subheading:', (await page.locator('.fi-header-subheading').first().textContent().catch(() => ''))?.trim());
  await shot(page, 'scale-people-manager');
  log('amit errors:', JSON.stringify(errors.slice(0, 5)));
  await ctx.close();
}
// Executive: pulse + drill drawer.
{
  const { ctx, page } = await personaContext(browser, base, 'meera.iyer@demo.local', { viewport: { width: 1440, height: 900 } });
  await timed('pulse', async () => { await page.goto(`${base}/admin/workforce-command-centre`); await settle(page); });
  log('pulse headline:', (await page.locator('.fi-header-subheading').first().textContent().catch(() => ''))?.trim());
  await shot(page, 'scale-pulse-exec');
  const fig = page.locator('.pos-figure[role=button], button.pos-figure, .pos-figure button').first();
  if (await fig.count()) { await timed('pulse drill', async () => { await fig.click(); await page.waitForSelector('.pos-drawer, [role=dialog]', { timeout: 15000 }); await settle(page); }); await shot(page, 'scale-pulse-drill'); }
  await ctx.close();
}
await browser.close();
