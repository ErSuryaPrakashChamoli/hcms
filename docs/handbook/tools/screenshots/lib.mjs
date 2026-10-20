// Documentation screenshot helper for the PeopleOS customer handbook.
// Captures GENUINE screens from a disposable showcase database (a *_showcase database seeded with UxShowcaseSeeder:
// fictional people only) served at DOCS_BASE_URL (default http://127.0.0.1:8097). See ../../README.md. Numbered callouts are drawn as an overlay on real elements just before capture.
//
// Usage (from any script):
//   import { start, open, annotate, shot, finish } from './lib.mjs';
//   const s = await start('my-area');                 // one browser per script
//   const page = await open(s, 'hr', '/admin/leave-requests');
//   await annotate(page, [{ sel: 'text=New leave request', n: 1 }, { loc: page.getByRole('searchbox'), n: 2 }]);
//   await shot(page, 'leave-01-requests-list', { caption: 'Leave requests list' });
//   await finish(s);
import { chromium } from '../../../../node_modules/playwright/index.mjs';
import { mkdirSync, existsSync, readFileSync, writeFileSync, statSync } from 'node:fs';
import { dirname } from 'node:path';

export const BASE = process.env.DOCS_BASE_URL ?? 'http://127.0.0.1:8097';
const HERE = dirname(new URL(import.meta.url).pathname);
export const OUT = `${HERE}/../../../assets/screenshots`;   // docs/assets/screenshots
const AUTH = `${HERE}/../.work/auth`;                        // signed-in sessions (gitignored)
const MANIFESTS = `${HERE}/../.work/manifests`;              // what each run captured (gitignored)

// Fictional showcase personas (fixture password on a disposable database only; never shown in any screenshot).
export const PERSONAS = {
    employee: 'priya.nair@demo.local',      // Priya Nair — Employee
    manager: 'amit.verma@demo.local',       // Amit Verma — Manager (Platform team) + Employee
    hr: 'neha.kapoor@demo.local',           // Neha Kapoor — HR Manager (Demo Technologies) + Employee
    admin: 'kavya.menon@demo.local',        // Kavya Menon — Tenant HR Admin + Employee (Head of People)
    payroll: 'arjun.bose@demo.local',       // Arjun Bose — Payroll Admin (Demo Technologies) + Employee
    executive: 'meera.iyer@demo.local',     // Meera Iyer — Executive (CEO) + Employee
    employee2: 'rahul.sharma@demo.local',   // Rahul Sharma — Employee
    superadmin: 'admin@demo.local',         // Demo Admin — Tenant Super Admin (no employee record)
};

export const VIEWPORT = { width: 1440, height: 900 };

export async function start(area) {
    mkdirSync(OUT, { recursive: true });
    mkdirSync(AUTH, { recursive: true });
    mkdirSync(MANIFESTS, { recursive: true });
    const browser = await chromium.launch();
    return { area, browser, contexts: {}, manifest: [] };
}

async function signIn(s, persona) {
    const file = `${AUTH}/${s.area}-${persona}.json`;
    if (existsSync(file) && Date.now() - statSync(file).mtimeMs < 6 * 3600_000) {
        return file;
    }
    const context = await s.browser.newContext({ viewport: VIEWPORT });
    const page = await context.newPage();
    await page.goto(`${BASE}/admin/login`);
    await page.fill('input[type=email]', PERSONAS[persona]);
    await page.fill('input[type=password]', 'password');
    await Promise.all([page.waitForURL(/\/admin(\/)?$/, { timeout: 120_000 }), page.click('button[type=submit]')]);
    await context.storageState({ path: file });
    await context.close();
    return file;
}

/** A context for the persona ('employee', 'manager', 'hr', 'admin', 'payroll', 'executive', 'employee2' or 'guest'). */
export async function context(s, persona, opts = {}) {
    const key = `${persona}:${opts.theme ?? 'light'}:${opts.width ?? VIEWPORT.width}`;
    if (!s.contexts[key]) {
        const storageState = persona === 'guest' ? undefined : await signIn(s, persona);
        s.contexts[key] = await s.browser.newContext({
            storageState,
            viewport: { width: opts.width ?? VIEWPORT.width, height: opts.height ?? VIEWPORT.height },
            deviceScaleFactor: 1.5,
            locale: 'en-GB',
            timezoneId: 'Asia/Kolkata',
            colorScheme: opts.theme === 'dark' ? 'dark' : 'light',
            reducedMotion: 'reduce',
            ...(opts.mobile ? { isMobile: true, hasTouch: true } : {}),
        });
    }
    return s.contexts[key];
}

/** Open a path as a persona and wait until the page has settled. */
export async function open(s, persona, path, opts = {}) {
    const ctx = await context(s, persona, opts);
    const page = await ctx.newPage();
    await page.goto(`${BASE}${path}`, { waitUntil: 'domcontentloaded', timeout: 120_000 });
    await settle(page);
    return page;
}

export async function settle(page, ms = 600) {
    try { await page.waitForLoadState('networkidle', { timeout: 30_000 }); } catch {}
    // Hide carets and transient toasts so the shot shows the screen, not a moment.
    await page.addStyleTag({ content: '*{caret-color:transparent!important} .fi-no-notification{transition:none!important}' }).catch(() => {});
    await page.waitForTimeout(ms);
}

/**
 * Draw numbered callouts. items: [{ sel?: string, loc?: Locator, n: number|string, pad?: number }]
 * Each target gets an outline and a numbered badge. Missing targets are reported (returned), never invented.
 */
export async function annotate(page, items) {
    const boxes = [];
    const missing = [];
    for (const item of items) {
        const loc = item.loc ?? page.locator(item.sel).first();
        let box = null;
        try {
            await loc.waitFor({ state: 'visible', timeout: 5_000 });
            box = await loc.boundingBox();
        } catch {}
        if (!box) { missing.push(item.n + ':' + (item.sel ?? 'locator')); continue; }
        boxes.push({ ...box, n: String(item.n), pad: item.pad ?? 4, badge: item.badge ?? 'tl' });
    }
    await page.evaluate((boxes) => {
        document.querySelectorAll('.docs-callout').forEach((e) => e.remove());
        const sx = window.scrollX, sy = window.scrollY;
        for (const b of boxes) {
            const o = document.createElement('div');
            o.className = 'docs-callout';
            Object.assign(o.style, { position: 'absolute', left: `${b.x + sx - b.pad}px`, top: `${b.y + sy - b.pad}px`, width: `${b.width + b.pad * 2}px`,
                height: `${b.height + b.pad * 2}px`, border: '3px solid #e8590c', borderRadius: '8px', boxShadow: '0 0 0 3px rgba(255,255,255,.85)',
                zIndex: 2147483646, pointerEvents: 'none', boxSizing: 'border-box' });
            const n = document.createElement('div');
            n.className = 'docs-callout';
            n.textContent = b.n;
            const left = b.badge === 'tr' ? b.x + sx + b.width + b.pad - 13 : b.x + sx - b.pad - 13;
            Object.assign(n.style, { position: 'absolute', left: `${Math.max(2 + sx, left)}px`, top: `${Math.max(2 + sy, b.y + sy - b.pad - 13)}px`, width: '26px', height: '26px',
                borderRadius: '50%', background: '#e8590c', color: '#fff', font: '700 14px/26px system-ui, sans-serif', textAlign: 'center',
                border: '2px solid #fff', boxShadow: '0 1px 4px rgba(0,0,0,.35)', zIndex: 2147483647, pointerEvents: 'none' });
            document.body.appendChild(o);
            document.body.appendChild(n);
        }
    }, boxes);
    return missing;
}

export async function clearCallouts(page) {
    await page.evaluate(() => document.querySelectorAll('.docs-callout').forEach((e) => e.remove()));
}

/**
 * Save docs/assets/screenshots/<name>.png. opts: { caption, fullPage, clip: {x,y,width,height}, element: Locator|selector }
 * Records persona, path and caption in the area manifest.
 */
export async function shot(page, name, opts = {}) {
    const file = `${OUT}/${name}.png`;
    if (opts.element) {
        const el = typeof opts.element === 'string' ? page.locator(opts.element).first() : opts.element;
        await el.screenshot({ path: file, animations: 'disabled' });
    } else {
        await page.screenshot({ path: file, fullPage: !!opts.fullPage, clip: opts.clip, animations: 'disabled' });
    }
    const url = new URL(page.url());
    page.__docsShots = (page.__docsShots ?? 0) + 1;
    return file;
}

/** Record a manifest entry (call after shot). */
export function record(s, entry) {
    s.manifest.push({ captured_at: new Date().toISOString(), ...entry });
}

export async function finish(s) {
    const file = `${MANIFESTS}/${s.area}.json`;
    let prior = [];
    if (existsSync(file)) { try { prior = JSON.parse(readFileSync(file, 'utf8')); } catch {} }
    const names = new Set(s.manifest.map((e) => e.name));
    writeFileSync(file, JSON.stringify([...prior.filter((e) => !names.has(e.name)), ...s.manifest], null, 2));
    await s.browser.close();
}
