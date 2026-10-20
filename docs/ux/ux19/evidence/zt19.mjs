// UX.19 final zero-training review: a first-time person looks at the first screen of Home, then taps or clicks the most
// obvious control, on a 390 × 844 phone (touch) and a 1440 × 900 desktop. The UX.17 heuristics, extended with the
// payroll task and the employee's own record (G12). Records: the first action, taps/clicks, screens, whether the answer
// is in the first screen (above the bottom bar on phones), whether the action control is visible and not covered, the
// path (with the words the person had to recognise), and the HTTP status of the page reached. A step marked `next` opens
// a menu or sheet on the way; the task ends at the first other step whose result is visible.
//   node zt19.mjs <base> <out.json> [shotsDir]   (needs ./auth.mjs from the Playwright workspace; showcase data only)
import { chromium } from 'playwright';
import { personaContext } from './auth.mjs';
import { writeFileSync, mkdirSync } from 'node:fs';

const [BASE, OUT, SHOTS] = process.argv.slice(2);
if (SHOTS) mkdirSync(SHOTS, { recursive: true });
const SIZES = {
    phone: { viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true, reducedMotion: 'reduce' },
    desktop: { viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce' },
};

// The visible fold: the viewport minus the bottom bar (phones only).
const fold = (p) => p.evaluate(() => { const n = document.querySelector('.pos-bottom-nav'); const v = n && getComputedStyle(n).display !== 'none' && n.getBoundingClientRect().height > 0; return innerHeight - (v ? n.getBoundingClientRect().height : 0); });
const inFirstScreen = async (p, loc) => { const box = await loc.boundingBox().catch(() => null); if (!box) return false; const f = await fold(p); const sy = await p.evaluate(() => scrollY); return sy === 0 && box.y >= 0 && box.y + Math.min(box.height, 40) <= f; };
const uncovered = (loc) => loc.evaluate((el) => { el.scrollIntoView({ block: 'center' }); const r = el.getBoundingClientRect(); const at = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2); return !!at && (at === el || el.contains(at)); }).catch(() => false);
const visible = async (loc) => (await loc.count()) > 0 && (await loc.first().isVisible().catch(() => false));
const tapIf = async (p, sel) => { const l = p.locator(sel).first(); return (await visible(l)) ? l : null; };
const firstScreenIf = async (p, sel) => { const l = p.locator(sel).first(); return (await visible(l)) && (await inFirstScreen(p, l)) ? l : null; };

const TASKS = [
    { role: 'Employee', email: 'priya.nair@demo.local', ask: 'I need to request leave.',
        steps: [
            async (p) => { const b = await tapIf(p, '.fi-main [data-pos-action="request_leave_header"]'); return b ? { tap: b, then: '.fi-modal-window:visible >> text=Request leave', control: '.fi-modal-window:visible .fi-modal-footer-actions button' } : null; },
            async (p) => { const b = await tapIf(p, '.pos-bottom-nav button:has-text("Actions")'); return b ? { tap: b, then: '[role=option]:has-text("Request leave")', next: true } : null; },
            async (p) => { const b = await tapIf(p, '[role=option]:has-text("Request leave")'); return b ? { tap: b, then: '.fi-modal-window:visible >> text=Request leave', control: '.fi-modal-window:visible .fi-modal-footer-actions button' } : null; },
        ] },
    { role: 'Employee', email: 'priya.nair@demo.local', ask: 'Show me my own record.',
        steps: [
            async (p) => { const b = await tapIf(p, '.fi-user-menu-trigger'); return b ? { tap: b, then: '.fi-dropdown-panel a:has-text("My profile")', next: true } : null; },
            async (p) => { const b = await tapIf(p, '.fi-dropdown-panel a:has-text("My profile")'); return b ? { tap: b, then: '.pos-360-viewer[data-viewer="self"]', firstScreenOf: '.pos-360-header' } : null; },
        ] },
    { role: 'Manager', email: 'amit.verma@demo.local', ask: 'I need to approve this request.',
        steps: [
            async (p) => { const b = await firstScreenIf(p, '.fi-main button:has-text("Review")'); return b ? { tap: b, then: '.pos-drawer .pos-btn-success', control: '.pos-drawer .pos-btn-success' } : null; },
            async (p) => { const b = await tapIf(p, '.pos-bottom-nav a:has-text("Approvals")'); return b ? { tap: b, then: '.pos-queue-row', next: true } : null; },
            async (p) => { const b = await tapIf(p, '.fi-main .pos-queue-row'); return b ? { tap: b, then: '.pos-drawer .pos-btn-success', control: '.pos-drawer .pos-btn-success' } : null; },
        ] },
    { role: 'Manager', email: 'amit.verma@demo.local', ask: 'Show me my team.',
        steps: [
            async (p) => { const b = await tapIf(p, '.pos-bottom-nav a:has-text("Team")'); return b ? { tap: b, then: '.fi-main h1:has-text("My team")', firstScreen: true } : null; },
            async (p) => { const b = await firstScreenIf(p, '.fi-main a:has-text("My team")'); return b ? { tap: b, then: '.fi-main h1:has-text("My team")', firstScreen: true } : null; },
            async (p) => { const b = await tapIf(p, '.fi-sidebar a:has-text("People")'); return b ? { tap: b, then: '.fi-main h1:has-text("My team"), .fi-main :text("Your team comes first")', firstScreen: true } : null; },
        ] },
    { role: 'HR', email: 'neha.kapoor@demo.local', ask: 'Which employee changes need attention?',
        steps: [
            async (p) => { const a = await firstScreenIf(p, '.fi-main section:has-text("People operations") .pos-stream-row'); return a ? { answer: a } : null; },
            async (p) => { const b = await tapIf(p, '.pos-bottom-nav a:has-text("Work"), .fi-sidebar a:has-text("My work")'); return b ? { tap: b, then: '.fi-main section:has-text("People operations") .pos-stream-row', firstScreen: true } : null; },
        ] },
    { role: 'Payroll', email: 'arjun.bose@demo.local', ask: 'What blocks the current payroll run?',
        steps: [
            async (p) => { const a = await firstScreenIf(p, '.fi-main section:has-text("Payroll") .pos-figures, .fi-main section:has-text("Payroll") .pos-meta'); return a ? { answer: a } : null; },
            async (p) => { const b = await tapIf(p, '.pos-bottom-nav a:has-text("Payroll")'); return b ? { tap: b, then: '.fi-main h1' } : null; },
        ] },
    { role: 'Executive', email: 'meera.iyer@demo.local', ask: 'What is happening in my workforce?',
        steps: [
            async (p) => { const a = await firstScreenIf(p, '.fi-main .pos-workforce-headline'); return a ? { answer: a } : null; },
            async (p) => { const b = await tapIf(p, '.pos-bottom-nav a:has-text("Pulse"), .fi-main a:has-text("Open Workforce pulse")'); return b ? { tap: b, then: '.fi-main .pos-workforce-headline, .fi-main :text("Workforce movement")', firstScreen: true } : null; },
        ] },
    { role: 'Administrator', email: 'kavya.menon@demo.local', ask: 'What requires system attention?',
        steps: [
            async (p) => { const a = await firstScreenIf(p, '.fi-main section:has-text("Governance") .pos-stream-row'); return a ? { answer: a } : null; },
            async (p) => { const b = await tapIf(p, '.pos-bottom-nav a:has-text("Admin")'); return b ? { tap: b, then: '.fi-main :text("Governance"), .fi-main :text("Awaiting approval")' } : null; },
        ] },
];

const browser = await chromium.launch();
const results = [];
for (const [size, opts] of Object.entries(SIZES)) {
    for (const t of TASKS) {
        const { page: p, ctx } = await personaContext(browser, BASE, t.email, opts);
        const res = await p.goto(BASE + '/admin', { waitUntil: 'networkidle' });
        await p.evaluate(() => document.fonts.ready);
        await p.waitForTimeout(400);
        let taps = 0, screens = 0, found = null, firstScreen = null, controlOk = null, status = res.status();
        const path = [];
        for (const step of t.steps) {
            const r = await step(p);
            if (!r) continue;
            if (r.answer) { found = r.answer; firstScreen = true; path.push('in the first screen of Home'); break; }
            const before = p.url();
            const label = (await r.tap.innerText().catch(() => '?')).trim().replace(/\s+/g, ' ').slice(0, 32) || (await r.tap.getAttribute('aria-label')) || '?';
            const isLink = await r.tap.evaluate((el) => el.tagName === 'A' && !!el.getAttribute('href') && !el.getAttribute('href').startsWith('#')).catch(() => false);
            const response = isLink ? p.waitForResponse((x) => x.request().isNavigationRequest() || x.url().includes('/livewire'), { timeout: 12000 }).catch(() => null) : null;
            if (size === 'phone') await r.tap.tap({ noWaitAfter: true }).catch(async () => r.tap.click({ noWaitAfter: true }));
            else await r.tap.click({ noWaitAfter: true });
            taps++;
            if (isLink) { await p.waitForURL((u) => u.toString() !== before, { timeout: 12000 }).catch(() => {}); const rr = await response; if (rr) status = rr.status(); }
            if (p.url() !== before) { screens++; await p.waitForLoadState('networkidle').catch(() => {}); await p.waitForTimeout(300); }
            await p.locator(r.then).first().waitFor({ state: 'visible', timeout: 12000 }).catch(() => {});
            path.push(`${size === 'phone' ? 'tap' : 'click'} "${label}"`);
            const target = p.locator(r.then).first();
            // An intermediate step (a menu or a sheet opening) is not the end of the task.
            if (!r.next && (await visible(target))) {
                found = target;
                if (r.firstScreen) firstScreen = await inFirstScreen(p, target);
                if (r.firstScreenOf) firstScreen = await inFirstScreen(p, p.locator(r.firstScreenOf).first());
                if (r.control) { const c = p.locator(r.control).first(); controlOk = (await visible(c)) ? await uncovered(c) : false; }
                break;
            }
        }
        if (SHOTS) await p.screenshot({ path: `${SHOTS}/zt-${size}-${t.role.toLowerCase()}-${t.ask.split(' ').slice(0, 4).join('-').replace(/[^a-z-]/gi, '').toLowerCase()}.png` });
        const row = { size, role: t.role, ask: t.ask, completed: !!found, first_action: path[0] ?? '—', taps, screens, answer_in_first_screen: firstScreen, action_control_uncovered: controlOk, status, path: path.join(' → ') };
        results.push(row);
        console.log(JSON.stringify(row));
        await ctx.close();
    }
}
writeFileSync(OUT, JSON.stringify({ base: BASE, results }, null, 2));
await browser.close();
