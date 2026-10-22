// Screenshot capture helper for the PeopleOS training decks.
//
// Captures GENUINE screens of the running PeopleOS application served from a disposable database
// (hcm_training_showcase: UxShowcaseSeeder fictional people only) at TRAINING_BASE_URL (default http://127.0.0.1:8098).
// Screenshots are saved unannotated. Numbered callouts are NOT drawn into the image: the bounding box of each
// target element is recorded in the manifest and the deck builder draws editable callout shapes over the picture.
//
//   import { start, open, shot, finish } from './lib.mjs';
//   const s = await start('employee');
//   const page = await open(s, 'employee', '/admin');
//   await shot(s, page, 'employee/01-home', { deck: [1], workflow: 'Sign in and Home', screen: 'Home',
//       action: 'Employee lands on Home after signing in', expected: 'Brief of the day, quick actions',
//       callouts: [{ sel: 'text=Request leave', n: 1, label: 'Request leave' }] });
//   await finish(s);
import { chromium } from '../../../../../node_modules/playwright/index.mjs';
import { createHmac } from 'node:crypto';
import { mkdirSync, existsSync, readFileSync, writeFileSync, statSync } from 'node:fs';
import { dirname } from 'node:path';

export const BASE = process.env.TRAINING_BASE_URL ?? 'http://127.0.0.1:8098';
const HERE = dirname(new URL(import.meta.url).pathname);
export const OUT = `${HERE}/../../screenshots`;          // docs/presentations/peopleos/screenshots
const WORK = `${HERE}/../.work`;                          // signed-in sessions (gitignored)
const MANIFESTS = `${HERE}/../manifests`;                 // what each run captured (tracked: feeds the inventory)

// Fictional showcase personas. The showcase personas use the seeder's public fixture password (valid on
// *_showcase databases only). The tenant super admin and the platform operator use the per-run seed password,
// read from a private file outside the repository (TRAINING_SEED_PASSWORD_FILE) and never written anywhere else.
export const PERSONAS = {
    employee: { email: 'priya.nair@demo.local', who: 'Priya Nair', role: 'Employee' },
    employee2: { email: 'rahul.sharma@demo.local', who: 'Rahul Sharma', role: 'Employee' },
    manager: { email: 'amit.verma@demo.local', who: 'Amit Verma', role: 'Manager + Employee' },
    hr: { email: 'neha.kapoor@demo.local', who: 'Neha Kapoor', role: 'HR Manager + Employee' },
    admin: { email: 'kavya.menon@demo.local', who: 'Kavya Menon', role: 'Tenant HR Admin + Employee' },
    payroll: { email: 'arjun.bose@demo.local', who: 'Arjun Bose', role: 'Payroll Admin + Employee' },
    executive: { email: 'meera.iyer@demo.local', who: 'Meera Iyer', role: 'Executive + Employee' },
    superadmin: { email: 'admin@demo.local', who: 'Demo Admin', role: 'Tenant Super Admin', seed: true },
    operator: { email: 'platform@markedge.local', who: 'Markedge Platform Admin', role: 'Platform operator', seed: true, mfa: true },
};

export const VIEWPORT = { width: 1440, height: 900 };
export const SCALE = 1.5;

function seedPassword() {
    const file = process.env.TRAINING_SEED_PASSWORD_FILE;
    if (!file || !existsSync(file)) throw new Error('TRAINING_SEED_PASSWORD_FILE is not set (needed for the super admin / operator personas).');
    return readFileSync(file, 'utf8').trim();
}

export function passwordFor(persona) {
    const p = PERSONAS[persona];
    if (p?.password) return p.password;
    return p?.seed ? seedPassword() : 'password';
}

// RFC 6238 TOTP (SHA-1, 30 s, 6 digits) for the operator's authenticator, enrolled through the real set-up screen.
function base32(s) {
    const alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const c of s.replace(/=+$/, '').toUpperCase().replace(/\s/g, '')) bits += alpha.indexOf(c).toString(2).padStart(5, '0');
    const bytes = [];
    for (let i = 0; i + 8 <= bits.length; i += 8) bytes.push(parseInt(bits.slice(i, i + 8), 2));
    return Buffer.from(bytes);
}
export function totp(secret, at = Date.now()) {
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(at / 1000 / 30)));
    const h = createHmac('sha1', base32(secret)).update(counter).digest();
    const o = h[h.length - 1] & 0xf;
    return String(((h.readUInt32BE(o) & 0x7fffffff) % 1_000_000)).padStart(6, '0');
}
export function mfaSecret(persona) {
    const f = `${WORK}/mfa-${persona}.secret`;
    return existsSync(f) ? readFileSync(f, 'utf8').trim() : null;
}
export function saveMfaSecret(persona, secret) {
    mkdirSync(WORK, { recursive: true });
    writeFileSync(`${WORK}/mfa-${persona}.secret`, secret, { mode: 0o600 });
}

export async function start(area) {
    mkdirSync(OUT, { recursive: true });
    mkdirSync(`${WORK}/auth`, { recursive: true });
    mkdirSync(MANIFESTS, { recursive: true });
    const browser = await chromium.launch();
    return { area, browser, contexts: {}, manifest: [] };
}

/** Sign a persona in through the real sign-in page (and the MFA challenge, for the operator). */
export async function signInPage(page, persona) {
    await page.goto(`${BASE}/admin/login`, { waitUntil: 'domcontentloaded', timeout: 120_000 });
    await page.fill('input[type=email]', PERSONAS[persona].email);
    await page.fill('input[type=password]', passwordFor(persona));
    await page.click('button[type=submit]');
    await page.waitForLoadState('networkidle', { timeout: 120_000 }).catch(() => {});
    const secret = mfaSecret(persona);
    if (secret && await page.locator('input[autocomplete="one-time-code"]').first().isVisible().catch(() => false)) {
        await page.locator('input[autocomplete="one-time-code"]').first().click();
        await page.keyboard.type(totp(secret), { delay: 60 });
        await page.getByRole('button', { name: 'Confirm sign in' }).click();
        await page.waitForURL((u) => !/\/login/.test(u.pathname), { timeout: 60_000 }).catch(() => {});
        await page.waitForLoadState('networkidle', { timeout: 120_000 }).catch(() => {});
    }
}

async function storageFor(s, persona) {
    const file = `${WORK}/auth/${persona}-${new URL(BASE).port}.json`;
    if (existsSync(file) && Date.now() - statSync(file).mtimeMs < 4 * 3600_000) return file;
    const ctx = await s.browser.newContext({ viewport: VIEWPORT });
    const page = await ctx.newPage();
    await signInPage(page, persona);
    if (/\/login/.test(page.url())) throw new Error(`Sign-in failed for ${persona} (still on ${page.url()})`);
    await ctx.storageState({ path: file });
    await ctx.close();
    return file;
}

/** A browser context for the persona ('guest' = signed out). */
export async function context(s, persona, opts = {}) {
    const key = `${persona}:${opts.theme ?? 'light'}:${opts.width ?? VIEWPORT.width}:${opts.fresh ? Date.now() : ''}`;
    if (!s.contexts[key]) {
        const storageState = persona === 'guest' || opts.noAuth ? undefined : await storageFor(s, persona);
        s.contexts[key] = await s.browser.newContext({
            storageState,
            viewport: { width: opts.width ?? VIEWPORT.width, height: opts.height ?? VIEWPORT.height },
            deviceScaleFactor: SCALE,
            locale: 'en-GB',
            timezoneId: 'Asia/Kolkata',
            colorScheme: opts.theme === 'dark' ? 'dark' : 'light',
            reducedMotion: 'reduce',
            ...(opts.mobile ? { isMobile: true, hasTouch: true } : {}),
        });
    }
    return s.contexts[key];
}

export function forget(persona) {
    const file = `${WORK}/auth/${persona}-${new URL(BASE).port}.json`;
    if (existsSync(file)) writeFileSync(file, '{}');
}

/** Open a path as a persona and wait until the page has settled. */
export async function open(s, persona, path, opts = {}) {
    const ctx = await context(s, persona, opts);
    const page = await ctx.newPage();
    page.__persona = persona;
    page.on('pageerror', (e) => { (page.__errors ??= []).push(String(e).slice(0, 300)); });
    await go(page, path);
    return page;
}

export async function go(page, path) {
    const resp = await page.goto(path.startsWith('http') ? path : `${BASE}${path}`, { waitUntil: 'domcontentloaded', timeout: 120_000 });
    page.__status = resp?.status();
    await settle(page);
    return resp;
}

export async function settle(page, ms = 700) {
    try { await page.waitForLoadState('networkidle', { timeout: 30_000 }); } catch {}
    await page.addStyleTag({ content: '*{caret-color:transparent!important}' }).catch(() => {});
    await page.waitForTimeout(ms);
}

/** Wait for Livewire round-trips after a click or fill. */
export async function idle(page, ms = 900) {
    try { await page.waitForLoadState('networkidle', { timeout: 30_000 }); } catch {}
    await page.waitForTimeout(ms);
}

/** Text of the visible Filament notification toasts (for verifying success / failure messages). */
export async function toasts(page) {
    return page.locator('.fi-no-notification').allInnerTexts().catch(() => []);
}

/**
 * Save screenshots/<name>.png and a manifest entry.
 * opts: deck (array of deck numbers), workflow, screen, action, expected, prereq, status, persona,
 *       callouts: [{ sel?|loc?, n, label?, pad? }], fullPage, clip, element, note
 * Callout boxes are stored in image pixels (CSS px × SCALE) relative to the saved image.
 */
export async function shot(s, page, name, opts = {}) {
    const file = `${OUT}/${name}.png`;
    mkdirSync(dirname(file), { recursive: true });
    let origin = { x: 0, y: 0 };
    let el = null;
    if (opts.element) {
        el = typeof opts.element === 'string' ? page.locator(opts.element).first() : opts.element;
        await el.waitFor({ state: 'visible', timeout: 10_000 });
        const b = await el.boundingBox();
        origin = { x: b.x, y: b.y };
    } else if (opts.clip) {
        origin = { x: opts.clip.x, y: opts.clip.y };
    }
    const scroll = opts.fullPage ? await page.evaluate(() => ({ x: window.scrollX, y: window.scrollY })) : { x: 0, y: 0 };
    const callouts = [];
    const missing = [];
    for (const c of opts.callouts ?? []) {
        const loc = (c.loc ?? page.locator(c.sel)).filter({ visible: true }).first();
        let box = null;
        try { await loc.waitFor({ state: 'visible', timeout: 4_000 }); box = await loc.boundingBox(); } catch {}
        if (!box) { missing.push(`${c.n}:${c.sel ?? c.label ?? 'locator'}`); continue; }
        const pad = c.pad ?? 4;
        callouts.push({
            n: String(c.n), label: c.label ?? null,
            x: Math.round((box.x + scroll.x - origin.x - pad) * SCALE), y: Math.round((box.y + scroll.y - origin.y - pad) * SCALE),
            w: Math.round((box.width + pad * 2) * SCALE), h: Math.round((box.height + pad * 2) * SCALE),
        });
    }
    if (el) await el.screenshot({ path: file, animations: 'disabled' });
    else await page.screenshot({ path: file, fullPage: !!opts.fullPage, clip: opts.clip, animations: 'disabled' });
    const persona = opts.persona ?? page.__persona ?? 'guest';
    const entry = {
        name, file: `screenshots/${name}.png`, captured_at: new Date().toISOString(), url: page.url().replace(BASE, ''),
        http_status: page.__status ?? null, persona, user: PERSONAS[persona]?.who ?? 'Signed out', role: PERSONAS[persona]?.role ?? 'Visitor',
        deck: opts.deck ?? [], workflow: opts.workflow ?? '', screen: opts.screen ?? '', action: opts.action ?? '', expected: opts.expected ?? '',
        prereq: opts.prereq ?? '', status: opts.status ?? 'VERIFIED IN RUNNING APPLICATION', note: opts.note ?? '',
        environment: 'Local development · disposable hcm_training_showcase (fictional data) · ' + BASE,
        callouts, missing_callouts: missing, page_errors: page.__errors ?? [],
    };
    s.manifest.push(entry);
    console.log(`  ✓ ${name}${missing.length ? '  (missing callouts: ' + missing.join(', ') + ')' : ''}`);
    return entry;
}

export async function finish(s) {
    const file = `${MANIFESTS}/${s.area}.json`;
    let prior = [];
    if (existsSync(file)) { try { prior = JSON.parse(readFileSync(file, 'utf8')); } catch {} }
    const names = new Set(s.manifest.map((e) => e.name));
    writeFileSync(file, JSON.stringify([...prior.filter((e) => !names.has(e.name)), ...s.manifest], null, 2));
    await s.browser.close();
}

/** Visible text of the page's main area (for probing navigation and labels). */
export async function text(page, sel = 'body') {
    return (await page.locator(sel).first().innerText().catch(() => '')).replace(/\n{3,}/g, '\n\n');
}

// ---- Filament helpers (pattern from docs/handbook/tools/screenshots/time-helpers.mjs) ----
export const modal = (page) => page.locator('.fi-modal-window:visible').last();

/** Set a field of the currently mounted action form through Livewire (for non-native date pickers). */
export async function setData(page, key, value, prefix = 'mountedActions.0.data') {
    await page.evaluate(([k, v, p]) => {
        const c = window.Livewire.all().find((c) => Array.isArray(c.$wire.mountedActions) && c.$wire.mountedActions.length > 0);
        if (!c) throw new Error('no mounted action');
        return c.$wire.set(`${p}.${k}`, v);
    }, [key, value, prefix]);
    await settle(page, 900);
}

/** Set a field on the page's main form (create/edit pages: data.<key>). */
export async function setForm(page, key, value) {
    await page.evaluate(([k, v]) => {
        const c = window.Livewire.all().find((c) => c.$wire.data !== undefined && typeof c.$wire.data === 'object');
        if (!c) throw new Error('no form component');
        return c.$wire.set(`data.${k}`, v);
    }, [key, value]);
    await settle(page, 700);
}

export async function cancelModal(page) {
    const m = modal(page);
    const btn = m.getByRole('button', { name: 'Cancel', exact: true }).last();
    if (await btn.count()) { await btn.click(); } else { await page.keyboard.press('Escape'); }
    await page.waitForTimeout(800);
}

/** Close any visible notification toasts, so the next capture shows only the new message. */
export async function closeToasts(page) {
    // Only visible toasts: the notification bell panel holds hidden close buttons that would stall a click.
    const btns = page.locator('.fi-no-notification:visible button.fi-icon-btn:visible, .fi-no-notification:visible .fi-no-notification-close-btn:visible');
    for (const b of await btns.all()) await b.click({ timeout: 2_000 }).catch(() => {});
    await page.waitForTimeout(500);
}

export async function dismissWelcome(page) {
    const b = page.getByRole('button', { name: 'Got it' }).first();
    if (await b.isVisible().catch(() => false)) { await b.click(); await idle(page, 600); }
}

/** Print what a page offers (headings, buttons, links, inputs) to write selectors against. */
export async function dump(page, sel = 'main', max = 80) {
    const root = page.locator(sel).first();
    const out = await root.evaluate((r, max) => {
        const vis = (e) => !!(e.offsetWidth || e.offsetHeight || e.getClientRects().length);
        const t = (e) => (e.innerText || e.value || e.getAttribute('aria-label') || e.getAttribute('placeholder') || '').trim().replace(/\s+/g, ' ').slice(0, 70);
        const pick = (q) => [...r.querySelectorAll(q)].filter(vis).map(t).filter(Boolean);
        const inputs = [...r.querySelectorAll('input,select,textarea')].filter(vis).map((e) => `${e.tagName.toLowerCase()}[${e.type || ''}] id=${e.id} name=${e.name} ph=${e.placeholder || ''}`);
        return { h: pick('h1,h2,h3'), buttons: [...new Set(pick('button'))].slice(0, max), links: [...new Set(pick('a'))].slice(0, max), inputs: inputs.slice(0, max) };
    }, max);
    console.log(`--- ${page.url()}\nH: ${out.h.join(' | ')}\nBUTTONS: ${out.buttons.join(' | ')}\nLINKS: ${out.links.join(' | ')}\nINPUTS:\n  ${out.inputs.join('\n  ')}`);
    return out;
}
