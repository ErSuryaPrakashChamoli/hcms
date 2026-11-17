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
    const state = join(WORK, `state-${new URL(BASE).port}-${email.replace(/[^a-z0-9]/gi, '_')}.json`);   // per server: cookies of 127.0.0.1 are shared across ports
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

// Masks sensitive values in the page before a screenshot (same rules everywhere).
export const MASK = () => {
    const rules = [
        [/\b[A-Z]{5}\d{4}[A-Z]\b/g, (m) => m.slice(0, 2) + 'XXXXXX' + m.slice(-2)],
        [/\b\d{4}[ -]?\d{4}[ -]?\d{4}\b/g, (m) => 'XXXX XXXX ' + m.replace(/\D/g, '').slice(-4)],
        [/\b\d{9,18}\b/g, (m) => 'X'.repeat(m.length - 4) + m.slice(-4)],
        [/(\+91[\s-]?)?\b[6-9]\d{4}[\s-]?\d{5}\b/g, () => '+91 XXXXX XXXXX'],
        [/[A-Za-z0-9._%+-]+@(?!demo\.local\b)(?!markedge)[A-Za-z0-9.-]+\.[a-z]{2,}/gi, () => 'personal.email@masked'],
    ];
    const fix = (s) => rules.reduce((t, [re, f]) => t.replace(re, f), s);
    const w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    for (let n = w.nextNode(); n; n = w.nextNode()) { const v = fix(n.nodeValue); if (v !== n.nodeValue) n.nodeValue = v; }
    for (const i of document.querySelectorAll('input:not([type=hidden]), textarea')) { if (i.value) { const v = fix(i.value); if (v !== i.value) i.value = v; } }
};

// Finds elements by their on-screen label in <main>; fields expand to their whole field wrapper.
export const FIND = ({ fields, elements }) => {
    const main = document.querySelector('main') || document.body;
    const norm = (s) => String(s ?? '').replace(/\s+/g, ' ').replace(/[*✱]\s*$/, '').trim().toLowerCase();
    // A researcher's label may carry a qualifier the screen does not show: try simpler forms of it.
    const variants = (label) => {
        const out = new Set([label]);
        const base = String(label).replace(/\s*\([^)]*\)\s*/g, ' ').replace(/^step\s*\d+\s*[·:.-]?\s*/i, '').trim();
        out.add(base);
        out.add(base.replace(/\s+(column|columns|section|tab|tabs|button|filter|filters|menu|panel|card|field|list|table|link|action|actions|toggle|icon|row|header|badge|chip|tile|step)$/i, ''));
        for (const part of base.split(/\s*(?:,|\/|›|\band\b|·)\s*/)) if (part.length > 1) out.add(part);
        return [...out].map(norm).filter(Boolean);
    };
    const vis = (el) => { const r = el.getBoundingClientRect(); const st = getComputedStyle(el); return r.width > 2 && r.height > 2 && st.visibility !== 'hidden' && st.display !== 'none' && !el.closest('[class*=dropdown-panel]:not([class*=open])'); };
    const own = (el) => norm(el.innerText || el.value || el.getAttribute('placeholder') || el.getAttribute('aria-label'));
    const used = new Set();
    const rect = (el, label, kind) => { const r = el.getBoundingClientRect(); return { label, kind, x: r.left + window.scrollX, y: r.top + window.scrollY, w: r.width, h: r.height }; };
    const pick = (list) => list.filter((el) => !used.has(el) && vis(el)).sort((a, b) => (a.innerText?.length ?? 0) - (b.innerText?.length ?? 0))[0];
    const out = [];
    for (const label of fields) {
        let el = null;
        for (const L of variants(label)) {
            const cands = [...main.querySelectorAll('[class*=field-label-content], label, legend')].filter((x) => own(x) === L || (own(x).startsWith(L + ' ') && own(x).length <= L.length + 3));
            el = pick(cands);
            if (el) break;
        }
        if (!el) continue;
        const wrap = el.closest('[class*=fo-field-wrp], [class*=field-wrp], [class*=fi-fo-field], [class*=fo-checkbox], [class*=fo-toggle], fieldset') ?? el;
        used.add(el); out.push(rect(wrap, label, 'field'));
    }
    for (const label of elements) {
        let el = null;
        for (const L of variants(label)) {
            const sets = [
                [...main.querySelectorAll('[role=tab], [class*=tabs-item]')],
                [...main.querySelectorAll('button, a, [role=button]')],
                [...main.querySelectorAll('thead th')],
                [...main.querySelectorAll('[class*=section-header-heading], h2, h3, h4, [class*=heading], [class*=step-label]')],
                [...main.querySelectorAll('label, [class*=field-label-content], input[placeholder]')],
                [...main.querySelectorAll('span, p, div, dt, td')],
            ];
            for (const set of sets) { el = pick(set.filter((x) => own(x) === L)); if (el) break; }
            if (!el) for (const set of sets.slice(0, 4)) { el = pick(set.filter((x) => own(x).startsWith(L) && own(x).length <= L.length + 8)); if (el) break; }
            if (el) break;
        }
        if (!el) continue;
        used.add(el); out.push(rect(el, label, 'element'));
    }
    return out;
};
