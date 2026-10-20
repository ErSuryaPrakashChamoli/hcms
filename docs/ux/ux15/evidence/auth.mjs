// Shared persona sessions: sign in once, reuse the saved storage state (respects the login limiter).
import { existsSync } from 'node:fs';
const DIR = new URL('./auth/', import.meta.url).pathname;
export async function personaContext(browser, base, email, opts = {}) {
  // One session file per server (cookies are not port-specific, so two servers must not share a file).
  const port = new URL(base).port;
  const file = port && port !== '8090' ? `${DIR}${port}-${email}.json` : `${DIR}${email}.json`;
  if (existsSync(file)) {
    const ctx = await browser.newContext({ ...opts, storageState: file });
    const p = await ctx.newPage();
    const r = await p.goto(`${base}/admin`, { waitUntil: 'domcontentloaded' });
    if (!p.url().includes('/login')) return { ctx, page: p };
    await ctx.close();
  }
  const ctx = await browser.newContext(opts);
  const p = await ctx.newPage();
  await p.goto(`${base}/admin/login`);
  await p.fill('input[type=email]', email);
  await p.fill('input[type=password]', 'password');
  await Promise.all([p.waitForURL(/\/admin$/, { timeout: 120000 }), p.click('button[type=submit]')]);
  await ctx.storageState({ path: file });
  return { ctx, page: p };
}
