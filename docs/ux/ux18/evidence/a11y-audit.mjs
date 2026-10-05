// UX.18 accessibility audit (automated part). For every page × viewport × theme: axe-core (WCAG 2.0/2.1/2.2 A and AA,
// best-practice rules reported separately), landmarks, the heading outline, unnamed controls, and touch targets under
// 24 px (WCAG 2.5.8, with the spacing exception approximated) split into PeopleOS-owned (pos-*) and standard Filament
// (fi-*) controls. Open states are part of the page list (palette, sheets, modals, menus, drawers, assistant).
//   BASE=<url> [VPS=phone,tablet,desktop] [THEMES=light,dark] node a11y-audit.mjs <out.json>
import { chromium } from 'playwright';
import { readFileSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { personaContext } from './auth.mjs';

const require = createRequire(import.meta.url);
const axeSrc = readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');
const axeVersion = JSON.parse(readFileSync(require.resolve('axe-core/package.json'), 'utf8')).version;
const BASE = process.env.BASE ?? 'http://127.0.0.1:8090';
const VP = {
    phone: { viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true },
    tablet: { viewport: { width: 768, height: 1024 }, hasTouch: true, isMobile: true },
    desktop: { viewport: { width: 1440, height: 1000 } },
};
const E = 'priya.nair', M = 'amit.verma', H = 'neha.kapoor', X = 'meera.iyer', A = 'kavya.menon', Y = 'arjun.bose';
// [persona, path, state?] — every role surface, shared components, module lists, forms, record pages, errors, open states.
const PAGES = [
    [E, '/admin'], [E, '/admin/my-work'], [E, '/admin/people'], [E, '/admin/my-hr'], [E, '/admin/my-hr?tab=requests'], [E, '/admin/notifications'], [E, '/admin/preferences'],
    [E, '/admin', 'palette'], [E, '/admin', 'leave'], [E, '/admin/employees/4'], [E, '/admin/does-not-exist'],
    [M, '/admin'], [M, '/admin', 'more'], [M, '/admin/my-work'], [M, '/admin/approvals'], [M, '/admin/approvals', 'decide'], [M, '/admin/my-team'], [M, '/admin/employees/3'],
    [M, '/admin/employees/3#journey'], [M, '/admin/notifications'], [M, '/admin', 'bell'], [M, '/admin', 'usermenu'],
    [H, '/admin'], [H, '/admin/my-work'], [H, '/admin/people?view=list'], [H, '/admin/people?view=list', 'filters'], [H, '/admin/people?view=grid', 'drawer'], [H, '/admin/employees'],
    [H, '/admin/employees/create'], [H, '/admin/tickets'], [H, '/admin/employees/3'], [H, '/admin/employees/3#records'], [H, '/admin/employees/3', 'ai'], [H, '/admin', 'menu'], [H, '/admin/leave-requests'],
    [X, '/admin'], [X, '/admin/my-work'], [X, '/admin/workforce-command-centre'], [X, '/admin/workforce-command-centre', 'drill'], [X, '/admin/organisation-map'], [X, '/admin/approvals'],
    [A, '/admin'], [A, '/admin/my-work'], [A, '/admin/admin-centre'], [A, '/admin/users'], [A, '/admin/audit-events'], [A, '/admin/departments/create'], [A, '/admin/security-policy-page'], [A, '/admin/roles'],
    [Y, '/admin'], [Y, '/admin/payroll-control-room'], [Y, '/admin/payroll-runs'],
];

async function act(p, state) {
    if (!state) return;
    const click = async (sel) => { await p.locator(sel).first().click({ timeout: 10000 }); await p.waitForTimeout(900); };
    if (state === 'palette') { await click('.pos-command-trigger'); await p.locator('.pos-command-input').fill('leave'); await p.waitForTimeout(1200); }
    if (state === 'leave') await click('[data-pos-action="request_leave_header"]');
    if (state === 'more') { if (await p.locator('.pos-phone-more:visible').count()) await click('.pos-phone-more:visible'); } // phones only, by design
    if (state === 'decide') await click('.pos-queue-row');
    if (state === 'bell') await click('.fi-topbar [aria-label^="Notifications"]');
    if (state === 'usermenu') await click('.fi-user-menu-trigger');
    if (state === 'filters') { const f = p.locator('button[aria-controls="pos-people-filters"]:visible'); if (await f.count()) { await f.first().click(); await p.waitForTimeout(400); } }
    if (state === 'drawer') await click('.pos-person-card');
    if (state === 'ai') { await click('button:has-text("Summarise")'); await p.waitForTimeout(2500); }
    if (state === 'menu') { const m = p.locator('.fi-topbar-open-sidebar-btn:visible'); if (await m.count()) { await m.first().click(); await p.waitForTimeout(700); } }
    if (state === 'drill') await click('.pos-figure');
}

const structure = () => {
    const vis = (el) => { const s = getComputedStyle(el); const r = el.getBoundingClientRect(); return s.visibility !== 'hidden' && s.display !== 'none' && r.width > 0 && r.height > 0; };
    const landmarks = [...document.querySelectorAll('header, nav, main, aside, footer, [role=banner], [role=navigation], [role=main], [role=complementary], [role=contentinfo], [role=search], [role=region][aria-label], section[aria-label], section[aria-labelledby]')]
        .filter(vis).map((el) => (el.getAttribute('role') || el.tagName.toLowerCase()) + (el.getAttribute('aria-label') ? `(${el.getAttribute('aria-label')})` : ''));
    const headings = [...document.querySelectorAll('h1, h2, h3, h4, h5, h6, [role=heading]')].filter(vis).map((h) => ({ level: +(h.getAttribute('aria-level') || h.tagName.slice(1)) || 2, text: h.textContent.trim().replace(/\s+/g, ' ').slice(0, 50) }));
    const skips = []; for (let i = 1; i < headings.length; i++) if (headings[i].level > headings[i - 1].level + 1) skips.push(`${headings[i - 1].level}→${headings[i].level} at "${headings[i].text}"`);
    const inter = [...document.querySelectorAll('a[href], button, [role=button], [role=tab], [role=option], [role=menuitem], input:not([type=hidden]), select, textarea, summary, [role=checkbox], [role=switch]')].filter(vis)
        .filter((el) => !(el.tagName === 'A' && getComputedStyle(el).display === 'inline' && el.parentElement && /^(P|LI|SPAN|DD|TD)$/.test(el.parentElement.tagName) && el.parentElement.textContent.trim().length > el.textContent.trim().length + 3));
    const small = inter.filter((el) => { const r = el.getBoundingClientRect(); if (Math.min(r.width, r.height) >= 24) return false;
        // Spacing exception: a 24 px circle centred on the target must not overlap another target's circle.
        const cx = r.left + r.width / 2, cy = r.top + r.height / 2;
        return inter.some((o) => { if (o === el || o.contains(el) || el.contains(o)) return false; const q = o.getBoundingClientRect(); const ox = q.left + q.width / 2, oy = q.top + q.height / 2; return Math.hypot(cx - ox, cy - oy) < 24; }); })
        .map((el) => { const cls = String(el.className); const r = el.getBoundingClientRect(); return { owner: /\bpos-/.test(cls) || el.closest('[class*="pos-"]') && !/\bfi-/.test(cls) ? 'peopleos' : 'filament', size: `${Math.round(r.width)}×${Math.round(r.height)}`, name: (el.getAttribute('aria-label') || el.textContent || el.getAttribute('placeholder') || cls).trim().replace(/\s+/g, ' ').slice(0, 40), cls: cls.split(' ').slice(0, 3).join('.') }; });
    return { title: document.title, lang: document.documentElement.lang, landmarks: [...new Set(landmarks)], h1: headings.filter((h) => h.level === 1).length, headingSkips: skips, smallTargets: small, overflow: document.documentElement.scrollWidth - innerWidth };
};

const ONLY = process.env.ONLY ? process.env.ONLY.split(',') : null; // re-check a subset by path fragment
const vps = (process.env.VPS ?? 'phone,tablet,desktop').split(',');
const themes = (process.env.THEMES ?? 'light,dark').split(',');
const b = await chromium.launch();
const out = { tool: `axe-core ${axeVersion}`, browser: `Chromium ${b.version()}`, base: BASE, rows: [] };
for (const vp of vps) {
    for (const theme of themes) {
        const ctxs = {};
        for (const [who, path, state] of PAGES.filter(([, path]) => !ONLY || ONLY.some((f) => path.includes(f)))) {
            ctxs[who] ??= (await personaContext(b, BASE, `${who}@demo.local`, { ...VP[vp], colorScheme: theme, reducedMotion: 'reduce' })).page;
            const p = ctxs[who];
            await p.emulateMedia({ colorScheme: theme, reducedMotion: 'reduce' });
            await p.evaluate((t) => { try { localStorage.setItem('theme', t); } catch {} }, theme);
            let res = null, error = null, r;
            try {
                res = await p.goto(BASE + path, { waitUntil: 'networkidle', timeout: 120000 }).catch(() => null);
                await p.waitForTimeout(500);
                try { await act(p, state); } catch (e) { error = String(e).split('\n')[0].slice(0, 140); }
                await p.evaluate(axeSrc);
                r = await p.evaluate(async () => await axe.run(document, { resultTypes: ['violations'] }));
            } catch (e) {
                // A page that cannot be audited is recorded, never silently skipped; the next page gets a fresh tab.
                out.rows.push({ vp, theme, who, path, state: state ?? '', status: 'ERR', error: String(e).split('\n')[0].slice(0, 160), wcag: [], bestPractice: [] });
                console.log('ERROR', vp, theme, who, path, state ?? '', String(e).split('\n')[0].slice(0, 120));
                ctxs[who] = (await personaContext(b, BASE, `${who}@demo.local`, { ...VP[vp], colorScheme: theme, reducedMotion: 'reduce' })).page;
                continue;
            }
            const wcag = r.violations.filter((v) => v.tags.some((t) => /^wcag\d+a+$/.test(t) || /^wcag2\d\d?a+$/.test(t) || t.startsWith('wcag2')));
            const best = r.violations.filter((v) => !wcag.includes(v));
            const fmt = (v) => ({ id: v.id, impact: v.impact, n: v.nodes.length, tags: v.tags.filter((t) => t.startsWith('wcag')), sample: v.nodes.slice(0, 3).map((n) => n.target.join(' ') + ' :: ' + (n.failureSummary || '').split('\n').slice(1, 2).join(' ').trim()) });
            const s = await p.evaluate(structure);
            const row = { vp, theme, who, path, state: state ?? '', status: res?.status() ?? 'ERR', error, wcag: wcag.map(fmt), bestPractice: best.map(fmt), ...s };
            out.rows.push(row);
            if (row.wcag.length) console.log('WCAG', vp, theme, who, path, state ?? '', row.wcag.map((v) => `${v.id}(${v.n})`).join(' '));
        }
        for (const p of Object.values(ctxs)) await p.context().close();
    }
}
writeFileSync(process.argv[2], JSON.stringify(out, null, 1));
const n = out.rows.length;
console.log(JSON.stringify({ checks: n, withWcagViolations: out.rows.filter((r) => r.wcag.length).length, wcagNodes: out.rows.reduce((a, r) => a + r.wcag.reduce((x, v) => x + v.n, 0), 0), errors: out.rows.filter((r) => r.error).length }));
await b.close();
