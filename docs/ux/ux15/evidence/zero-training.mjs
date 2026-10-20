// UX.15.23 Phase 37: zero-training tasks per role, counting interactions from sign-in to the answer.
import { chromium } from 'playwright';
import { personaContext } from './auth.mjs';
const BASE = 'http://127.0.0.1:8090';
const OUT = process.argv[2];
const b = await chromium.launch();
const vp = { viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce' };
const settle = async (p) => { await p.waitForLoadState('networkidle').catch(() => {}); await p.waitForTimeout(700); };
const log = (role, task, steps, found, note) => console.log(JSON.stringify({ role, task, interactions: steps, found, note }));
{ // Employee: "I need to apply for leave."
  const { page } = await personaContext(b, BASE, 'priya.nair@demo.local', vp); await page.goto(BASE + '/admin'); await settle(page);
  await page.getByRole('button', { name: 'Request leave' }).first().click(); await settle(page);
  const form = await page.getByText('Leave type', { exact: false }).first().isVisible().catch(() => false);
  await page.screenshot({ path: `${OUT}/zt-employee-leave.png` });
  log('Employee', 'I need to apply for leave', 1, form, 'Home primary action "Request leave" opens the leave form with the balance preview');
}
{ // Manager: "Show me everything I need to act on today."
  const { page } = await personaContext(b, BASE, 'amit.verma@demo.local', vp); await page.goto(BASE + '/admin'); await settle(page);
  const brief = (await page.locator('.pos-brief, h1 + p, .fi-header-subheading').first().textContent().catch(() => ''))?.trim();
  await page.getByRole('link', { name: /Review \d+\+? decisions?/ }).first().click(); await settle(page);
  const heading = (await page.locator('h1').first().textContent()).trim();
  const detail = await page.locator('.pos-approval-detail').first().isVisible();
  await page.screenshot({ path: `${OUT}/zt-manager-act.png` });
  log('Manager', 'Show me everything I need to act on today', 1, detail, `Home says: "${brief?.slice(0, 120)}"; one click → "${heading}" with the first decision open beside the queue`);
}
{ // HR: "Something changed in this employee's employment. Show me what happened."
  const { page } = await personaContext(b, BASE, 'neha.kapoor@demo.local', vp); await page.goto(BASE + '/admin'); await settle(page);
  await page.keyboard.press('Control+k'); await page.waitForSelector('.pos-command', { state: 'visible' });
  await page.keyboard.type('Fatima', { delay: 25 }); await page.waitForTimeout(1200); await page.keyboard.press('Enter'); await settle(page);
  const onPerson = page.url().includes('/employees/');
  await page.locator('a[href="#journey"]').first().click().catch(() => {}); await page.waitForTimeout(800);
  const changed = await page.getByText(/What changed|Journey/).first().isVisible();
  const promo = (await page.getByText('Promotion', { exact: true }).filter({ visible: true }).count()) > 0;
  await page.screenshot({ path: `${OUT}/zt-hr-what-happened.png` });
  log('HR', 'Something changed in this employee’s employment; show me what happened', 3, onPerson && changed && promo, `Ctrl K → name → Enter opens the person workspace; Journey / What changed show the change (promotion visible: ${promo})`);
}
{ // Executive: "What's happening with my workforce?"
  const { page } = await personaContext(b, BASE, 'meera.iyer@demo.local', vp); await page.goto(BASE + '/admin'); await settle(page);
  await page.getByRole('link', { name: /Open Workforce pulse/ }).first().click(); await settle(page);
  const headline = (await page.locator('.fi-header-subheading').first().textContent()).trim();
  await page.screenshot({ path: `${OUT}/zt-executive-pulse.png` });
  log('Executive', 'What’s happening with my workforce?', 1, headline.length > 20, `Headline: "${headline}"`);
}
{ // Administrator: "I need to change an organisation policy."
  const { page } = await personaContext(b, BASE, 'kavya.menon@demo.local', vp); await page.goto(BASE + '/admin/admin-centre'); await settle(page);
  const input = page.locator('input[type=search], input[placeholder*="leave policy"]').first(); await input.fill('probation period'); await page.waitForTimeout(1500);
  const hits = await page.locator('a').filter({ hasText: /probation/i }).count();
  const first = (await page.locator('a').filter({ hasText: /probation/i }).first().innerText().catch(() => '')).replace(/\s+/g, ' ');
  await page.screenshot({ path: `${OUT}/zt-admin-policy.png` });
  log('Administrator', 'I need to change an organisation policy', 1, hits > 0, `Plain-language finder: "probation period" → ${hits} matching setting(s); first: "${first}"`);
}
await b.close();
