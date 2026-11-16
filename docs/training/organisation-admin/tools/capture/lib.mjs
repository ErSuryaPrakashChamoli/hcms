// Capture helpers for the Organisation Administrator training: genuine screens of the running application,
// served from the disposable training database (tools/env.sh) and signed in as the organisation administrator.
import { chromium } from '../../../../../node_modules/playwright/index.mjs';
import { readFileSync, existsSync, mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

export const HERE = dirname(fileURLToPath(import.meta.url));
export const WORK = join(HERE, '../.work');
export const SHOTS = join(HERE, '../../screenshots');
export const BASE = process.env.TRAINING_BASE_URL ?? 'http://127.0.0.1:8091';
export const ADMIN = 'admin@demo.local';
const password = () => readFileSync(join(WORK, 'demo-password'), 'utf8').trim();

export async function browser() {
    return chromium.launch({ executablePath: '/snap/bin/chromium', headless: true });
}

/** A context signed in as `email` (default: the organisation administrator); reuses a saved session. */
export async function signedIn(b, email = ADMIN, { fresh = false } = {}) {
    const state = join(WORK, `state-${email.replace(/[^a-z0-9]/gi, '_')}.json`);
    const opts = { viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1.5, locale: 'en-GB', timezoneId: 'Asia/Kolkata' };
    if (!fresh && existsSync(state)) {
        const ctx = await b.newContext({ ...opts, storageState: state });
        const p = await ctx.newPage();
        await p.goto(BASE + '/admin', { waitUntil: 'networkidle' });
        if (!p.url().includes('/login')) { await p.close(); return ctx; }
        await ctx.close();
    }
    const ctx = await b.newContext(opts);
    const p = await ctx.newPage();
    await p.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await p.fill('input[type=email]', email);
    await p.fill('input[type=password]', password());
    await p.click('button[type=submit]');
    try { await p.waitForURL((u) => !u.pathname.endsWith('/login'), { timeout: 30000 }); }
    catch { throw new Error(`Sign-in failed for ${email}: ${(await p.locator('body').innerText()).replace(/\n+/g, ' | ').slice(0, 400)}`); }
    await p.waitForLoadState('networkidle');
    await ctx.storageState({ path: state });
    await p.close();
    return ctx;
}

/** Screenshot to screenshots/<name>.png (full page unless clip/fullPage false). */
export async function shot(page, name, { fullPage = true } = {}) {
    const file = join(SHOTS, `${name}.png`);
    mkdirSync(dirname(file), { recursive: true });
    await page.screenshot({ path: file, fullPage });
    return file;
}

export function saveJson(name, data) { const f = join(WORK, name); writeFileSync(f, JSON.stringify(data, null, 2)); return f; }
