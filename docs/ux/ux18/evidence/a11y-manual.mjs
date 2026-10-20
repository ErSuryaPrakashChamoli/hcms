// UX.18 accessibility audit (scripted manual review): what a keyboard-only, zoomed-in or screen-reader user meets.
//  1. Keyboard path per role (desktop): the first 40 tab stops, each with a visible focus indicator; the skip link.
//  2. Overlays: focus moves in, Tab stays in, Escape closes, focus returns (command center, sheet, modal form, assistant).
//  3. Lists: the Approval Center's J/K keys move focus between decisions.
//  4. Reflow at 320 CSS px (WCAG 1.4.10) and 200% zoom: no sideways scroll on the main surfaces.
//  5. Text spacing (WCAG 1.4.12): no clipped text with the spacing overrides applied.
//  6. Reduced motion: animations collapse under prefers-reduced-motion.
//  7. Screen-reader view: the accessible role/name tree (Playwright aria snapshot) of the shell landmarks and overlays.
//   BASE=<url> node a11y-manual.mjs <out.json>     (needs ./auth.mjs)
import { chromium } from 'playwright';
import { writeFileSync } from 'node:fs';
import { personaContext } from './auth.mjs';

const BASE = process.env.BASE ?? 'http://127.0.0.1:8090';
const b = await chromium.launch();
const out = { browser: `Chromium ${b.version()}`, keyboard: [], overlays: [], lists: [], reflow: [], spacing: [], motion: [], tree: {} };

const focusInfo = (p) => p.evaluate(() => {
    const e = document.activeElement;
    if (!e || e === document.body) return null;
    const cs = getComputedStyle(e);
    const r = e.getBoundingClientRect();
    const row = e.closest('.pos-signal-row, .pos-stream-row');
    const ring = (cs.outlineStyle !== 'none' && parseFloat(cs.outlineWidth) > 0) || (cs.boxShadow && cs.boxShadow !== 'none')
        || (row && row !== e && getComputedStyle(row).boxShadow !== 'none') || (e.matches(':focus-visible') && cs.outlineStyle !== 'none');
    return { name: (e.getAttribute('aria-label') || e.innerText || e.placeholder || e.tagName).trim().replace(/\s+/g, ' ').slice(0, 50), tag: e.tagName.toLowerCase(), ring: !!ring, visible: r.width > 0 && r.height > 0, inDialog: !!e.closest('[role=dialog]') };
});

// 1. Keyboard paths
for (const who of ['priya.nair', 'amit.verma', 'neha.kapoor', 'meera.iyer', 'kavya.menon']) {
    const { page: p, ctx } = await personaContext(b, BASE, `${who}@demo.local`, { viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce' });
    await p.goto(BASE + '/admin', { waitUntil: 'networkidle' });
    await p.keyboard.press('Tab');
    const first = await focusInfo(p);
    await p.keyboard.press('Enter');
    await p.waitForTimeout(200);
    const skipLands = await p.evaluate(() => location.hash === '#pos-main' || document.activeElement?.id === 'pos-main' || !!document.activeElement?.closest('#pos-main'));
    await p.goto(BASE + '/admin', { waitUntil: 'networkidle' });
    const stops = [];
    for (let i = 0; i < 40; i++) { await p.keyboard.press('Tab'); stops.push(await focusInfo(p)); }
    const real = stops.filter(Boolean);
    out.keyboard.push({ who, firstStop: first?.name, skipLinkLandsInMain: skipLands, stops: real.length, withoutVisibleFocus: real.filter((s) => !s.ring).map((s) => s.name), hiddenFocused: real.filter((s) => !s.visible).map((s) => s.name) });
    await ctx.close();
}

// 2. Overlays
const overlay = async (who, path, open, label) => {
    const { page: p, ctx } = await personaContext(b, BASE, `${who}@demo.local`, { viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce' });
    await p.goto(BASE + path, { waitUntil: 'networkidle' });
    const trigger = await open(p);
    await p.waitForTimeout(900);
    const inside = await focusInfo(p);
    const trapped = [];
    for (let i = 0; i < 12; i++) { await p.keyboard.press('Tab'); trapped.push((await focusInfo(p))?.inDialog ?? false); }
    await p.keyboard.press('Escape');
    await p.waitForTimeout(600);
    const after = await p.evaluate((sel) => ({ closed: !document.querySelector('[role=dialog][aria-modal=true]:not([hidden])') || [...document.querySelectorAll('[role=dialog][aria-modal=true]')].every((d) => d.getBoundingClientRect().height === 0 || getComputedStyle(d).display === 'none'),
        focusBack: sel ? !!document.activeElement?.closest(sel) : null }), trigger);
    out.overlays.push({ overlay: label, focusMovesIn: !!inside?.inDialog, focusName: inside?.name, tabStaysInside: trapped.every(Boolean), escapeCloses: after.closed, focusReturnsToTrigger: after.focusBack });
    await ctx.close();
};
await overlay('amit.verma', '/admin', async (p) => { await p.locator('.pos-command-trigger').first().focus(); await p.keyboard.press('Enter'); return '.pos-command-trigger'; }, 'Command center');
await overlay('neha.kapoor', '/admin/people?view=grid', async (p) => { await p.locator('.pos-person-card').first().focus(); await p.keyboard.press('Enter'); return '.pos-person-card'; }, 'Person sheet (drawer)');
await overlay('priya.nair', '/admin', async (p) => { await p.locator('[data-pos-action="request_leave_header"]').first().focus(); await p.keyboard.press('Enter'); return '[data-pos-action="request_leave_header"]'; }, 'Request leave (modal form)');
await overlay('neha.kapoor', '/admin/employees/3', async (p) => { await p.locator('button:has-text("Summarise")').first().focus(); await p.keyboard.press('Enter'); return 'button'; }, 'Assistant panel');
await overlay('amit.verma', '/admin', async (p) => { await p.locator('.fi-topbar [aria-label^="Notifications"]').first().focus(); await p.keyboard.press('Enter'); return '.fi-topbar'; }, 'Notifications sheet');

// 3. List keys
{
    const { page: p, ctx } = await personaContext(b, BASE, 'amit.verma@demo.local', { viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce' });
    await p.goto(BASE + '/admin/approvals', { waitUntil: 'networkidle' });
    await p.locator('main').click({ position: { x: 5, y: 5 } }).catch(() => {});
    const seen = [];
    for (const k of ['j', 'j', 'k']) { await p.keyboard.press(k); seen.push(await p.evaluate(() => document.activeElement?.dataset?.approvalId ?? null)); }
    out.lists.push({ list: 'Approval Center (J/K)', focusMoves: seen[0] !== null && seen[1] !== seen[0] && seen[2] === seen[0], seen });
    await ctx.close();
}

// 4. Reflow at 320 px and 200% zoom (1280 px window at 2x = 640 CSS px)
for (const [label, opts] of [['320 CSS px', { viewport: { width: 320, height: 640 }, hasTouch: true, isMobile: true }], ['200% zoom of 1280', { viewport: { width: 640, height: 400 }, deviceScaleFactor: 2 }]]) {
    for (const [who, path] of [['neha.kapoor', '/admin'], ['neha.kapoor', '/admin/people?view=list'], ['neha.kapoor', '/admin/employees/3'], ['amit.verma', '/admin/approvals'], ['kavya.menon', '/admin/users'], ['priya.nair', '/admin/my-work']]) {
        const { page: p, ctx } = await personaContext(b, BASE, `${who}@demo.local`, { ...opts, reducedMotion: 'reduce' });
        await p.goto(BASE + path, { waitUntil: 'networkidle' });
        const m = await p.evaluate(() => ({ overflow: document.documentElement.scrollWidth - innerWidth }));
        out.reflow.push({ mode: label, who, path, overflowPx: m.overflow, pass: m.overflow <= 1 });
        await ctx.close();
    }
}

// 5. Text spacing (line-height 1.5, paragraph 2em, letter 0.12em, word 0.16em)
const SPACING = '*{line-height:1.5!important;letter-spacing:.12em!important;word-spacing:.16em!important}p{margin-bottom:2em!important}';
for (const [who, path, vp] of [['neha.kapoor', '/admin', 390], ['amit.verma', '/admin/approvals', 1440], ['kavya.menon', '/admin', 390], ['neha.kapoor', '/admin/employees/3', 1440]]) {
    const { page: p, ctx } = await personaContext(b, BASE, `${who}@demo.local`, { viewport: { width: vp, height: 900 }, reducedMotion: 'reduce' });
    await p.goto(BASE + path, { waitUntil: 'networkidle' });
    await p.addStyleTag({ content: SPACING });
    await p.waitForTimeout(300);
    const m = await p.evaluate(() => {
        const clipped = [...document.querySelectorAll('main *')].filter((el) => {
            const cs = getComputedStyle(el);
            if (!el.childNodes.length || ![...el.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim())) return false;
            const hidden = (cs.overflow + cs.overflowX + cs.overflowY).includes('hidden') || cs.textOverflow === 'ellipsis';
            return hidden && (el.scrollWidth > el.clientWidth + 2 || el.scrollHeight > el.clientHeight + 2) && cs.textOverflow !== 'ellipsis' && !el.closest('.sr-only');
        }).map((el) => (el.className || el.tagName).toString().slice(0, 40) + ': ' + el.textContent.trim().slice(0, 30));
        return { overflow: document.documentElement.scrollWidth - innerWidth, clipped: clipped.slice(0, 8), clippedCount: clipped.length };
    });
    out.spacing.push({ who, path, width: vp, ...m });
    await ctx.close();
}

// 6. Reduced motion
{
    const { page: p, ctx } = await personaContext(b, BASE, 'amit.verma@demo.local', { viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce' });
    await p.goto(BASE + '/admin', { waitUntil: 'networkidle' });
    await p.locator('.pos-command-trigger').first().click();
    await p.waitForTimeout(300);
    const m = await p.evaluate(() => {
        const long = [...document.querySelectorAll('*')].filter((el) => { const cs = getComputedStyle(el); const d = parseFloat(cs.animationDuration) || 0; const t = parseFloat(cs.transitionDuration) || 0; return (cs.animationName !== 'none' && d > 0.01) || t > 0.01; }).length;
        return { elementsWithMotionOver10ms: long };
    });
    out.motion.push({ prefersReducedMotion: 'reduce', ...m });
    await ctx.close();
}

// 7. Screen-reader view (accessible role/name tree)
{
    const { page: p, ctx } = await personaContext(b, BASE, 'amit.verma@demo.local', { viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true, reducedMotion: 'reduce' });
    await p.goto(BASE + '/admin', { waitUntil: 'networkidle' });
    out.tree.phoneBar = await p.locator('nav.pos-bottom-nav').ariaSnapshot();
    out.tree.homeHeadings = (await p.locator('main').ariaSnapshot()).split('\n').filter((l) => /heading|tablist|tab "/.test(l)).slice(0, 30).join('\n');
    await p.locator('.pos-command-trigger').first().click();
    await p.locator('.pos-command-input').fill('leave');
    await p.waitForTimeout(1500);
    out.tree.commandCenter = (await p.locator('[role=dialog][aria-labelledby="pos-command-title"]').ariaSnapshot()).split('\n').slice(0, 25).join('\n');
    await p.keyboard.press('Escape');
    await p.goto(BASE + '/admin/approvals', { waitUntil: 'networkidle' });
    await p.locator('.pos-queue-row').first().click();
    await p.waitForTimeout(1500);
    out.tree.decisionSheet = (await p.locator('.pos-drawer[data-open="true"]').ariaSnapshot()).split('\n').slice(0, 30).join('\n');
    await ctx.close();
}

writeFileSync(process.argv[2], JSON.stringify(out, null, 1));
console.log(JSON.stringify({ keyboard: out.keyboard.map((k) => [k.who, k.skipLinkLandsInMain, k.withoutVisibleFocus.length]), overlays: out.overlays, lists: out.lists, reflowFails: out.reflow.filter((r) => !r.pass), spacing: out.spacing.map((s) => [s.path, s.width, s.overflow, s.clippedCount]), motion: out.motion }, null, 1));
await b.close();
